<?php
require_once __DIR__ . '/notification_helper.php';
require_once __DIR__ . '/session_security.php';

function NotificationWebBase(): string
{
    $root = str_replace('\\', '/', (string)realpath(dirname(__DIR__)));
    $documentRoot = rtrim(str_replace('\\', '/', (string)realpath((string)($_SERVER['DOCUMENT_ROOT'] ?? ''))), '/');
    if ($documentRoot !== '' && str_starts_with(strtolower($root . '/'), strtolower($documentRoot . '/'))) {
        return implode('/', array_map('rawurlencode', explode('/', substr($root, strlen($documentRoot))))) . '/';
    }
    $script = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
    return rtrim((string)preg_replace('~/(?:php|admin|operator|public|includes)(?:/.*)?$~', '', dirname($script)), '/') . '/';
}

function NotificationConfig(PDO $pdo, string $role): ?array
{
    $id = (int)($_SESSION[match ($role) { 'tourist' => 'tourist_id', 'admin' => 'admin_id', 'operator' => 'operator_id', 'hotel_admin' => 'hotel_admin_id', default => '__invalid' }] ?? 0);
    if ($id < 1 || !NotificationAvailable($pdo)) return null;
    return ['role' => $role, 'base' => NotificationWebBase(), 'csrf' => AppCsrfToken($role, 'notification_inbox'), 'pending' => (int)($_SESSION['notification_pending'][$role] ?? 0)];
}

function NotificationRender(PDO $pdo, string $role): void
{
    $config = NotificationConfig($pdo, $role);
    if (!$config) return;
    unset($_SESSION['notification_pending'][$role]);
    $base = htmlspecialchars($config['base'], ENT_QUOTES, 'UTF-8');
    echo '<link rel="stylesheet" data-inbox-style="1" href="' . $base . 'styles/notification-inbox.css?v=5">';
    echo '<script>window.ItourNotificationConfig=' . json_encode($config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . ';</script>';
    echo '<script defer src="' . $base . 'js/notification-inbox.js?v=5"></script>';
}
