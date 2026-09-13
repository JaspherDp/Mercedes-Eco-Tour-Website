<?php

function ensureAdminManagementTables(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS admin_system_settings (
            settings_id TINYINT UNSIGNED NOT NULL PRIMARY KEY DEFAULT 1,
            settings_json LONGTEXT NOT NULL,
            updated_by INT NULL,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS admin_profile_details (
            admin_id INT NOT NULL PRIMARY KEY,
            phone VARCHAR(40) NULL,
            job_title VARCHAR(100) NULL,
            bio VARCHAR(500) NULL,
            profile_picture VARCHAR(255) NULL,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_admin_profile_updated (updated_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}

function adminSettingsDefaults(): array
{
    return [
        'site_name' => 'iTour Mercedes',
        'office_email' => '',
        'office_phone' => '',
        'office_address' => 'Mercedes, Camarines Norte',
        'timezone' => 'Asia/Manila',
        'currency' => 'PHP',
        'date_format' => 'M d, Y',
        'booking_notice_hours' => 24,
        'cancellation_window_hours' => 48,
        'capacity_warning_percent' => 80,
        'default_booking_status' => 'pending',
        'notify_new_booking' => true,
        'notify_payment' => true,
        'notify_inquiry' => true,
        'notify_review' => true,
        'email_digest' => false,
        'digest_time' => '08:00',
        'audit_retention_days' => 365,
        'session_timeout_minutes' => 60,
        'support_message' => 'For assistance, contact the Municipal Tourism Office.',
    ];
}

function loadAdminSystemSettings(PDO $pdo): array
{
    $defaults = adminSettingsDefaults();
    try {
        ensureAdminManagementTables($pdo);
        $row = $pdo->query('SELECT settings_json, updated_by, updated_at FROM admin_system_settings WHERE settings_id = 1')->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return [$defaults, null];
        }
        $saved = json_decode((string)$row['settings_json'], true);
        return [array_replace($defaults, is_array($saved) ? $saved : []), $row];
    } catch (Throwable $e) {
        error_log('Admin settings could not be loaded: ' . $e->getMessage());
        return [$defaults, null];
    }
}

function saveAdminSystemSettings(PDO $pdo, array $settings, int $adminId): void
{
    ensureAdminManagementTables($pdo);
    $json = json_encode($settings, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    $stmt = $pdo->prepare(
        'INSERT INTO admin_system_settings (settings_id, settings_json, updated_by)
         VALUES (1, ?, ?)
         ON DUPLICATE KEY UPDATE settings_json = VALUES(settings_json), updated_by = VALUES(updated_by)'
    );
    $stmt->execute([$json, $adminId ?: null]);
}

function adminManagementCsrfToken(): string
{
    if (empty($_SESSION['admin_management_csrf'])) {
        $_SESSION['admin_management_csrf'] = bin2hex(random_bytes(32));
    }
    return (string)$_SESSION['admin_management_csrf'];
}

function verifyAdminManagementCsrf(): bool
{
    $submitted = (string)($_POST['csrf_token'] ?? '');
    return $submitted !== '' && hash_equals((string)($_SESSION['admin_management_csrf'] ?? ''), $submitted);
}

function adminManagementEscape($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function adminManagementRedirect(string $page, string $status, string $message): void
{
    $_SESSION['admin_management_flash'] = ['status' => $status, 'message' => $message];
    header('Location: ' . $page);
    exit;
}

function adminManagementFlash(): ?array
{
    $flash = $_SESSION['admin_management_flash'] ?? null;
    unset($_SESSION['admin_management_flash']);
    return is_array($flash) ? $flash : null;
}

function adminManagementRequireLogin(): void
{
    require_once __DIR__ . '/admin_auth_helper.php';
    AdminRequireLogin();
}

function adminProfileImageUrl(string $path): string
{
    $path = trim($path);
    if ($path === '') return '';
    if (preg_match('#^https?://#i', $path)) return $path;
    $scriptDirectory = strtolower(str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? ''))));
    return (substr($scriptDirectory, -6) === '/admin' ? '../' : '') . ltrim($path, '/');
}
