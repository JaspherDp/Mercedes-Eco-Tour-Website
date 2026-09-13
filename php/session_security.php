<?php
declare(strict_types=1);

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
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_trans_sid', '0');
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
        session_start();
    }
}

function AppSessionTimeoutMinutes(PDO $pdo): int
{
    static $minutes = null;
    if (is_int($minutes)) {
        return $minutes;
    }

    $minutes = 60;
    try {
        $exists = $pdo->query(
            "SELECT COUNT(*) FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'admin_system_settings'"
        );
        if ((int)$exists->fetchColumn() > 0) {
            $raw = $pdo->query('SELECT settings_json FROM admin_system_settings WHERE settings_id = 1 LIMIT 1')->fetchColumn();
            $settings = is_string($raw) ? json_decode($raw, true) : null;
            if (is_array($settings) && isset($settings['session_timeout_minutes'])) {
                $minutes = max(15, min(480, (int)$settings['session_timeout_minutes']));
            }
        }
    } catch (Throwable $exception) {
        error_log('Session timeout setting could not be read: ' . $exception->getMessage());
    }
    return $minutes;
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
            'admin_session_started', 'admin_phone_setup_grant', 'paymongo_admin_csrf',
        ],
        'operator' => [
            'operator_logged_in', 'operator_id', 'operator_name',
            'paymongo_operator_csrf',
        ],
        'hotel_admin' => [
            'hotel_admin_logged_in', 'hotel_admin_id', 'hotel_admin_username',
            'hotel_admin_hotel_resort_id', 'hotel_admin_property_name',
            'hotel_admin_name', 'hotel_admin_profile_picture', 'paymongo_hotel_admin_csrf',
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
    unset($_SESSION['auth_last_activity'][$role]);
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

function AppMarkRoleAuthenticated(string $role): void
{
    $now = time();
    $_SESSION['auth_last_activity'][$role] = $now;
    $_SESSION['last_activity'] = $now;
}

function AppRoleSessionIsActive(string $role, PDO $pdo): bool
{
    $now = time();
    $lastActivity = (int)($_SESSION['auth_last_activity'][$role] ?? $_SESSION['last_activity'] ?? 0);
    if ($lastActivity > 0 && ($now - $lastActivity) > (AppSessionTimeoutMinutes($pdo) * 60)) {
        AppClearRoleAuthentication($role);
        session_regenerate_id(true);
        return false;
    }

    $_SESSION['auth_last_activity'][$role] = $now;
    $_SESSION['last_activity'] = $now;
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
