<?php
declare(strict_types=1);

require_once __DIR__ . '/portal_settings_helper.php';

/**
 * Shared session hardening for browser-facing entry points.
 * Call AppSessionStart() before reading or writing $_SESSION.
 */
function AppSessionIsHttps(): bool
{
    $https = strtolower((string)($_SERVER['HTTPS'] ?? ''));
    if ($https !== '' && $https !== 'off' && $https !== '0') {
        return true;
    }
    if ((int)($_SERVER['SERVER_PORT'] ?? 0) === 443) {
        return true;
    }
    return strtolower(trim(explode(',', (string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''))[0])) === 'https';
}

function AppSessionConfigure(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_cookies', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_trans_sid', '0');
    // Keep PHP's session cleanup window at least as long as the longest
    // supported inactivity setting; role-specific expiry is enforced below.
    ini_set('session.gc_maxlifetime', (string)(480 * 60));
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => AppSessionIsHttps(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

function AppSessionStart(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        AppSessionConfigure();
        $page = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
        $portalRequest = preg_match('/^(?:ad|op)[a-z]|^Ho[A-Z]/', $page) === 1
            || in_array($page, ['session_status.php', 'fetch_notifications.php', 'mark_notif_badge.php', 'admin_sidebar.php', 'operator_sidebar.php'], true);
        if (in_array($page, ['admin_login.php', 'operator_login.php', 'hotel_admin_login.php'], true)) $portalRequest = false;
        if ($portalRequest && !AppRequestCountsAsActivity()) {
            // A queued poll from an old, deleted session must never overwrite
            // the cookie issued by a successful login in another request.
            $cookieId = (string)($_COOKIE[session_name()] ?? '');
            if ($cookieId !== '' && preg_match('/^[a-zA-Z0-9,-]{16,256}$/D', $cookieId)) session_id($cookieId);
            session_start(['use_cookies' => false]);
        } else {
            session_start();
        }
    }
}

function AppRoleAccountId(string $role): int
{
    return (int)($_SESSION[match ($role) {
        'admin' => 'admin_id', 'operator' => 'operator_id',
        'hotel_admin' => 'hotel_admin_id', default => 'tourist_id',
    }] ?? 0);
}

function AppSessionTimeoutMinutes(PDO $pdo, string $role = 'admin'): int
{
    // Tourist policy is unchanged; administrative preferences are account scoped.
    if ($role === 'tourist') {
        try {
            $raw = $pdo->query('SELECT settings_json FROM admin_system_settings WHERE settings_id=1')->fetchColumn();
            $saved = is_string($raw) ? json_decode($raw, true) : null;
            return max(15, min(480, (int)($saved['session_timeout_minutes'] ?? 60)));
        } catch (Throwable $error) { return 60; }
    }
    $accountId = AppRoleAccountId($role);
    if ($accountId < 1) return 60;
    try {
        return (int)PortalLoadSettings($pdo, $role, $accountId)['session_timeout_minutes'];
    } catch (Throwable $error) {
        error_log('Account session timeout could not be read: ' . $error->getMessage());
        return 60;
    }
}

function AppRequestCountsAsActivity(): bool
{
    // Notification polling (including automatic badge updates) is passive.
    $page = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
    if (in_array($page, ['session_status.php', 'fetch_notifications.php', 'mark_notif_badge.php'], true)) return false;
    foreach (['get_pending_count', 'get_unviewed'] as $key) {
        if (isset($_GET[$key])) return false;
    }
    if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET') return true;
    $destination = strtolower((string)($_SERVER['HTTP_SEC_FETCH_DEST'] ?? ''));
    if (isset($_SERVER['HTTP_SEC_FETCH_DEST']) && !in_array($destination, ['document', 'iframe'], true)) return false;
    return strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) !== 'xmlhttprequest'
        && !str_contains(strtolower((string)($_SERVER['HTTP_ACCEPT'] ?? '')), 'application/json');
}

function AppRoleSessionKeys(string $role): array
{
    return match ($role) {
        'tourist' => [
            'tourist_logged_in', 'tourist_id', 'tourist_email', 'tourist_name',
            'tourist_profile_pic', 'full_name', 'first_name', 'last_name',
            'paymongo_booking_csrf', 'paymongo_balance_csrf',
        ],
        'admin' => [
            'admin_logged_in', 'admin_id', 'username', 'admin_name',
            'admin_session_started', 'admin_phone_setup_grant', 'paymongo_admin_csrf', 'admin_push_csrf', 'admin_management_csrf', 'admin_management_flash',
        ],
        'operator' => [
            'operator_logged_in', 'operator_id', 'operator_name',
            'paymongo_operator_csrf', 'operator_profile', 'operator_push_csrf',
        ],
        'hotel_admin' => [
            'hotel_admin_logged_in', 'hotel_admin_id', 'hotel_admin_username',
            'hotel_admin_hotel_resort_id', 'hotel_admin_property_name',
            'hotel_admin_name', 'hotel_admin_profile_picture', 'paymongo_hotel_admin_csrf', 'hotel_push_csrf',
        ],
        default => [],
    };
}

function AppClearRoleAuthentication(string $role): void
{
    foreach (AppRoleSessionKeys($role) as $key) {
        unset($_SESSION[$key]);
    }
    $csrfPrefix = 'csrf_' . $role . '_';
    foreach (array_keys($_SESSION) as $key) {
        if (is_string($key) && str_starts_with($key, $csrfPrefix)) {
            unset($_SESSION[$key]);
        }
    }
    unset($_SESSION['auth_last_activity'][$role], $_SESSION['auth_identity'][$role], $_SESSION['auth_generation'][$role], $_SESSION['auth_login_time'][$role], $_SESSION['auth_timeout_minutes'][$role]);
    if ($role === 'admin') unset($_SESSION['admin_management_csrf']);
}

function AppCsrfToken(string $role, string $scope): string
{
    $role = strtolower(trim($role));
    $scope = strtolower(trim($scope));
    if (!preg_match('/^[a-z][a-z0-9_]*$/', $role) || !preg_match('/^[a-z][a-z0-9_]*$/', $scope)) {
        throw new InvalidArgumentException('Invalid CSRF token scope.');
    }
    $key = 'csrf_' . $role . '_' . $scope;
    if (!isset($_SESSION[$key]) || !is_string($_SESSION[$key]) || $_SESSION[$key] === '') {
        $_SESSION[$key] = bin2hex(random_bytes(32));
    }
    return $_SESSION[$key];
}

function AppVerifyCsrf(string $role, string $scope, mixed $submitted): bool
{
    if (!is_string($submitted) || $submitted === '') {
        return false;
    }
    $key = 'csrf_' . strtolower(trim($role)) . '_' . strtolower(trim($scope));
    $expected = $_SESSION[$key] ?? null;
    return is_string($expected) && $expected !== '' && hash_equals($expected, $submitted);
}

function AppMarkRoleAuthenticated(string $role, ?PDO $pdo = null): void
{
    $now = time();
    if ($role === 'tourist') {
        $_SESSION['auth_last_activity'][$role] = $now;
        $_SESSION['last_activity'] = $now;
        return;
    }
    $_SESSION['auth_last_activity'][$role] = $now;
    $_SESSION['auth_login_time'][$role] = $now;
    $_SESSION['auth_identity'][$role] = AppRoleAccountId($role);
    $_SESSION['auth_generation'][$role] = bin2hex(random_bytes(16));
    if ($pdo) $_SESSION['auth_timeout_minutes'][$role] = AppSessionTimeoutMinutes($pdo, $role);
    $_SESSION['last_activity'] = $now;
}

function AppRoleSessionIsActive(string $role, PDO $pdo, ?bool $touch = null): bool
{
    $now = time();
    // Preserve the existing public tourist policy independently of portal fixes.
    if ($role === 'tourist') {
        $lastActivity = (int)($_SESSION['auth_last_activity'][$role] ?? $_SESSION['last_activity'] ?? 0);
        if ($lastActivity > 0 && ($now - $lastActivity) > AppSessionTimeoutMinutes($pdo, $role) * 60) {
            AppClearRoleAuthentication($role);
            session_regenerate_id(true);
            return false;
        }
        $_SESSION['auth_last_activity'][$role] = $now;
        $_SESSION['last_activity'] = $now;
        return true;
    }
    $accountId = AppRoleAccountId($role);
    if ($accountId < 1) {
        AppClearRoleAuthentication($role);
        return false;
    }
    if (isset($_SESSION['auth_identity'][$role]) && (int)$_SESSION['auth_identity'][$role] !== $accountId) {
        AppClearRoleAuthentication($role);
        return false;
    }
    // Legacy sessions use only their own role timestamp, never another role's.
    $lastActivity = (int)($_SESSION['auth_last_activity'][$role] ?? 0);
    if ($lastActivity <= 0 || ($now - $lastActivity) >= AppSessionTimeoutMinutes($pdo, $role) * 60) {
        AppClearRoleAuthentication($role);
        // Keep the anonymous session ID stable: a late polling response must
        // not replace the fresh cookie produced by a concurrent successful login.
        return false;
    }
    $_SESSION['auth_identity'][$role] = $accountId;
    if ($touch ?? AppRequestCountsAsActivity()) {
        $_SESSION['auth_last_activity'][$role] = $now;
    }
    return true;
}

function AppExpireSessionCookie(): void
{
    if (!ini_get('session.use_cookies')) {
        return;
    }
    $params = session_get_cookie_params();
    setcookie(session_name(), '', [
        'expires' => time() - 42000,
        'path' => (string)($params['path'] ?? '/'),
        'domain' => (string)($params['domain'] ?? ''),
        'secure' => (bool)($params['secure'] ?? false),
        'httponly' => (bool)($params['httponly'] ?? true),
        'samesite' => (string)($params['samesite'] ?? 'Lax'),
    ]);
}

function AppDestroySession(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return;
    }
    $_SESSION = [];
    AppExpireSessionCookie();
    session_destroy();
}
