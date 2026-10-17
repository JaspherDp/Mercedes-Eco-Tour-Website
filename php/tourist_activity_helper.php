<?php
declare(strict_types=1);
require_once __DIR__ . '/tourist_auth_helper.php';
require_once __DIR__ . '/session_monitor.php';

/** Validate and register public navigation before any HTML/headers are sent. */
function TouristPreparePageActivity(PDO $pdo): void
{
    if (AppRoleAccountId('tourist') < 1) return;
    if (!TouristValidateSession($pdo)) return;
    // Adopt a current legacy login without creating a new generation on each page.
    if (empty($_SESSION['auth_generation']['tourist'])) {
        $_SESSION['auth_generation']['tourist'] = bin2hex(random_bytes(16));
    }
    header('Cache-Control: private, no-store, max-age=0');
}

function TouristRenderActivityMonitor(): void
{
    if (AppRoleAccountId('tourist') > 0) AppRenderSessionMonitor('tourist');
}
