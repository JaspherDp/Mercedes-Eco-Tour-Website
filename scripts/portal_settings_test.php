<?php
declare(strict_types=1);

require_once __DIR__ . '/../php/session_security.php';
require_once __DIR__ . '/../php/db_connection.php';

// Use an isolated session and roll back every account-preference fixture.
$sessionDirectory = sys_get_temp_dir() . '/itour-portal-settings-test-' . bin2hex(random_bytes(8));
mkdir($sessionDirectory, 0700);
ini_set('session.save_path', $sessionDirectory);
AppSessionStart();
$checks = [];
function portalSettingsAssert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

try {
    portalSettingsAssert(PortalSettingsTableExists($pdo), 'Apply the portal account settings migration before running this test.');
    portalSettingsAssert((int)ini_get('session.gc_maxlifetime') >= 480 * 60, 'PHP cleanup would remove long sessions prematurely.');
    foreach (array_keys(PortalSessionTimeoutOptions()) as $minutes) {
        portalSettingsAssert(PortalValidateSessionTimeout((string)$minutes) === $minutes, 'Valid preset rejected.');
    }
    foreach ([null, [], '', '1.5', '15oops', 0, 14, 45, 481, 9999] as $invalid) {
        $rejected = false;
        try { PortalValidateSessionTimeout($invalid); } catch (InvalidArgumentException $error) { $rejected = true; }
        portalSettingsAssert($rejected, 'Invalid timeout accepted.');
    }
    portalSettingsAssert(PortalValidateSessionTimeout('45', 45) === 45, 'An existing custom admin timeout was lost.');
    $checks[] = 'Preset validation and existing admin timeout preservation';

    $pdo->beginTransaction();
    $fixtureId = 2147483000;
    $otherId = 2147483001;
    $exists = $pdo->prepare('SELECT COUNT(*) FROM portal_account_settings WHERE account_id IN (?,?)');
    $exists->execute([$fixtureId, $otherId]);
    portalSettingsAssert((int)$exists->fetchColumn() === 0, 'Fixture account IDs are already used.');
    PortalSaveSettings($pdo, 'operator', $fixtureId, ['session_timeout_minutes' => 120, 'landing_page' => 'opbookings.php']);
    PortalSaveSettings($pdo, 'hotel_admin', $fixtureId, ['session_timeout_minutes' => 15, 'landing_page' => 'Horooms.php']);
    portalSettingsAssert(PortalLoadSettings($pdo, 'operator', $fixtureId)['session_timeout_minutes'] === 120, 'Operator timeout not persisted.');
    portalSettingsAssert(PortalLoadSettings($pdo, 'hotel_admin', $fixtureId)['session_timeout_minutes'] === 15, 'Hotel timeout leaked across roles.');
    portalSettingsAssert(PortalLoadSettings($pdo, 'operator', $otherId) === PortalSettingsDefaults('operator'), 'Settings leaked to another account.');
    portalSettingsAssert(PortalLoginLandingPage($pdo, 'operator', $fixtureId) === 'opbookings.php', 'Operator login ignores saved start page.');
    portalSettingsAssert(PortalLoginLandingPage($pdo, 'hotel_admin', $fixtureId) === 'Horooms.php', 'Hotel login ignores saved start page.');
    $checks[] = 'Persistent preferences, role isolation, account isolation and login start pages';

    $rejected = false;
    try { PortalSaveSettings($pdo, 'operator', $fixtureId, ['session_timeout_minutes' => 60, 'landing_page' => 'adsystemsettings.php']); }
    catch (InvalidArgumentException $error) { $rejected = true; }
    portalSettingsAssert($rejected, 'Cross-role start page accepted.');
    $rejected = false;
    try { PortalSaveSettings($pdo, 'hotel_admin', $fixtureId, ['session_timeout_minutes' => 60, 'landing_page' => 'https://example.com']); }
    catch (InvalidArgumentException $error) { $rejected = true; }
    portalSettingsAssert($rejected, 'External start page accepted.');
    $checks[] = 'Start-page allowlists reject external and cross-role destinations';

    $_SESSION['operator_id'] = $fixtureId;
    $_SESSION['operator_logged_in'] = true;
    $_SESSION['hotel_admin_id'] = $fixtureId;
    $_SESSION['hotel_admin_logged_in'] = true;
    $_SESSION['auth_last_activity'] = ['operator' => time() - 31 * 60, 'hotel_admin' => time() - 31 * 60];
    portalSettingsAssert(AppRoleSessionIsActive('operator', $pdo), 'Operator session expired before its saved timeout.');
    portalSettingsAssert(!AppRoleSessionIsActive('hotel_admin', $pdo), 'Hotel session did not expire at its saved timeout.');
    portalSettingsAssert(!isset($_SESSION['hotel_admin_logged_in']), 'Expired hotel authentication was retained.');
    portalSettingsAssert(($_SESSION['operator_logged_in'] ?? false) === true, 'Hotel expiry cleared operator authentication.');
    $_SESSION['auth_last_activity']['operator'] = time() - 121 * 60;
    portalSettingsAssert(!AppRoleSessionIsActive('operator', $pdo), 'Operator session did not expire at its saved timeout.');
    $adminTimeout = AppSessionTimeoutMinutes($pdo, 'admin');
    $_SESSION['admin_id'] = $fixtureId;
    $_SESSION['admin_logged_in'] = true;
    $_SESSION['auth_last_activity']['admin'] = time() - ($adminTimeout + 1) * 60;
    portalSettingsAssert(!AppRoleSessionIsActive('admin', $pdo), 'Existing admin session policy was not enforced.');
    $checks[] = 'Real inactivity enforcement for all three panels and role-specific logout';

    AppCsrfToken('operator', 'system_settings');
    AppCsrfToken('hotel_admin', 'system_settings');
    portalSettingsAssert(!AppVerifyCsrf('operator', 'system_settings', $_SESSION['csrf_hotel_admin_system_settings']), 'Cross-role CSRF token accepted.');
    portalSettingsAssert(!AppVerifyCsrf('operator', 'system_settings', null), 'Missing CSRF token accepted.');
    $checks[] = 'Settings CSRF tokens are required and scoped by role';

    PortalSaveSettings($pdo, 'operator', $fixtureId, PortalSettingsDefaults('operator'));
    portalSettingsAssert(PortalLoadSettings($pdo, 'operator', $fixtureId) === PortalSettingsDefaults('operator'), 'Restore defaults failed.');
    portalSettingsAssert(PortalLoadSettings($pdo, 'hotel_admin', $fixtureId)['session_timeout_minutes'] === 15, 'Reset affected another role.');
    $checks[] = 'Restore defaults affects only the selected account';
} finally {
    if ($pdo->inTransaction()) $pdo->rollBack();
    AppDestroySession();
    foreach (glob($sessionDirectory . '/sess_*') ?: [] as $sessionFile) unlink($sessionFile);
    rmdir($sessionDirectory);
}
foreach ($checks as $check) echo 'PASS: ', $check, PHP_EOL;
