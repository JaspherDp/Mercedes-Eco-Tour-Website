<?php
declare(strict_types=1);

function PortalSessionTimeoutOptions(): array
{
    return [15 => '15 minutes', 30 => '30 minutes', 60 => '1 hour', 120 => '2 hours', 240 => '4 hours', 480 => '8 hours'];
}

function PortalValidateSessionTimeout(mixed $value, ?int $current = null): int
{
    if (!is_scalar($value) || !preg_match('/^\d+$/', (string)$value)) {
        throw new InvalidArgumentException('Choose an available session timeout.');
    }
    $minutes = (int)$value;
    // Preserve an existing custom admin value until a preset is selected.
    if (!isset(PortalSessionTimeoutOptions()[$minutes])
        && !($current === $minutes && $minutes >= 15 && $minutes <= 480)) {
        throw new InvalidArgumentException('Choose an available session timeout.');
    }
    return $minutes;
}

function PortalLandingPageOptions(string $role): array
{
    return match ($role) {
        'admin' => ['adhomepage.php' => 'Dashboard'],
        'operator' => ['ophomepage.php' => 'Dashboard', 'opbookings.php' => 'Bookings', 'oppayments.php' => 'Payments & Transactions', 'opearnings.php' => 'Earnings & Payouts', 'optourpackages.php' => 'Tour Packages'],
        'hotel_admin' => ['Hohome.php' => 'Dashboard', 'Hobookings.php' => 'Bookings', 'Horooms.php' => 'Rooms', 'Hopayments.php' => 'Payments & Transactions', 'Hoearnings.php' => 'Earnings & Payouts'],
        default => throw new InvalidArgumentException('Unsupported settings role.'),
    };
}

function PortalSettingsDefaults(string $role): array
{
    return ['session_timeout_minutes' => 60, 'landing_page' => array_key_first(PortalLandingPageOptions($role))];
}

function PortalSettingsTableExists(PDO $pdo): bool
{
    return (int)$pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='portal_account_settings'")->fetchColumn() > 0;
}

function PortalEnsureSettingsTable(PDO $pdo): void
{
    $sql = file_get_contents(__DIR__ . '/../portal_account_settings_migration.sql');
    if (!is_string($sql)) throw new RuntimeException('Settings storage could not be initialized.');
    $pdo->exec($sql);
}

function PortalLoadSettings(PDO $pdo, string $role, int $accountId): array
{
    $defaults = PortalSettingsDefaults($role);
    if ($accountId < 1) throw new InvalidArgumentException('An authenticated account is required.');
    if (!PortalSettingsTableExists($pdo)) return $defaults;
    $stmt = $pdo->prepare('SELECT settings_json FROM portal_account_settings WHERE role=? AND account_id=?');
    $stmt->execute([$role, $accountId]);
    $raw = $stmt->fetchColumn();
    $saved = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($saved)) return $defaults;
    $minutes = filter_var($saved['session_timeout_minutes'] ?? null, FILTER_VALIDATE_INT);
    if ($minutes !== false && $minutes >= 15 && $minutes <= 480) $defaults['session_timeout_minutes'] = $minutes;
    $landingPage = $saved['landing_page'] ?? null;
    if (is_string($landingPage) && isset(PortalLandingPageOptions($role)[$landingPage])) $defaults['landing_page'] = $landingPage;
    return $defaults;
}

function PortalSaveSettings(PDO $pdo, string $role, int $accountId, array $input): void
{
    $pages = PortalLandingPageOptions($role);
    if ($accountId < 1) throw new InvalidArgumentException('An authenticated account is required.');
    $existing = $pdo->prepare('SELECT settings_json FROM portal_account_settings WHERE role=? AND account_id=?');
    $existing->execute([$role, $accountId]);
    $raw = $existing->fetchColumn();
    $saved = is_string($raw) ? json_decode($raw, true) : [];
    $saved = is_array($saved) ? $saved : [];
    $settings = ['session_timeout_minutes' => PortalValidateSessionTimeout($input['session_timeout_minutes'] ?? null, (int)($saved['session_timeout_minutes'] ?? 60))];
    $page = $input['landing_page'] ?? null;
    if (!is_string($page) || !isset($pages[$page])) throw new InvalidArgumentException('Choose an available start page.');
    $settings['landing_page'] = $page;
    $settings = array_replace($saved, $settings);
    $stmt = $pdo->prepare('INSERT INTO portal_account_settings (role,account_id,settings_json) VALUES (?,?,?) ON DUPLICATE KEY UPDATE settings_json=VALUES(settings_json)');
    $stmt->execute([$role, $accountId, json_encode($settings, JSON_THROW_ON_ERROR)]);
}

function PortalLoginLandingPage(PDO $pdo, string $role, int $accountId): string
{
    try {
        return PortalLoadSettings($pdo, $role, $accountId)['landing_page'];
    } catch (Throwable $error) {
        error_log('Portal start page could not be read: ' . $error->getMessage());
        return PortalSettingsDefaults($role)['landing_page'];
    }
}
