<?php
declare(strict_types=1);

require_once __DIR__ . '/session_security.php';
require_once __DIR__ . '/operator_auth_helper.php';

function AppRenderSessionMonitor(string $role): void
{
    static $rendered = false;
    if ($rendered || AppRoleAccountId($role) < 1) return;
    $rendered = true;
    if (empty($_SESSION['auth_generation'][$role])) {
        $_SESSION['auth_generation'][$role] = bin2hex(random_bytes(16));
    }
    $base = operatorPortalBaseUrl();
    $config = [
        'role' => $role,
        'endpoint' => $base . '/php/session_status.php',
        'generation' => $_SESSION['auth_generation'][$role],
        'csrf' => AppCsrfToken($role, 'session_activity'),
    ];
    echo '<script>window.itourSessionMonitor=' . json_encode($config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . ';</script>';
    echo '<script src="' . htmlspecialchars($base . '/js/session-monitor.js?v=1', ENT_QUOTES, 'UTF-8') . '" defer></script>';
}
