<?php
declare(strict_types=1);

chdir(__DIR__ . '/..');
require_once __DIR__ . '/../php/session_security.php';
AppSessionStart();
require_once __DIR__ . '/../php/admin_auth_helper.php';
require_once __DIR__ . '/../php/db_connection.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');
header('X-Content-Type-Options: nosniff');

function adminDeviceStatusResponse(int $status, array $payload): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET');
    adminDeviceStatusResponse(405, ['success' => false, 'message' => 'Only GET requests are accepted.']);
}

if (!AdminValidateSession($pdo)) {
    adminDeviceStatusResponse(401, [
        'success' => false,
        'code' => 'SESSION_EXPIRED',
        'message' => 'Your administrator session expired. Please log in again.',
        'login_url' => AdminLoginUrl('admin-phone-setup.php'),
    ]);
}

$adminId = (int)$_SESSION['admin_id'];

try {
    $adminCheck = $pdo->prepare('SELECT admin_id FROM admin_users WHERE admin_id = ? LIMIT 1');
    $adminCheck->execute([$adminId]);
    if ((int)$adminCheck->fetchColumn() !== $adminId) {
        adminDeviceStatusResponse(401, ['success' => false, 'message' => 'The Administrator account could not be verified.']);
    }

    $deviceQuery = $pdo->prepare(
        'SELECT device_id, device_name, created_at, last_used_at
         FROM admin_push_devices
         WHERE admin_id = ? AND revoked_at IS NULL
         ORDER BY COALESCE(last_used_at, created_at) DESC, device_id DESC
         LIMIT 1'
    );
    $deviceQuery->execute([$adminId]);
    $device = $deviceQuery->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $exception) {
    error_log('Unable to read the main Administrator Firebase device registration.');
    adminDeviceStatusResponse(503, ['success' => false, 'message' => 'The registered phone could not be checked right now.']);
}

adminDeviceStatusResponse(200, [
    'success' => true,
    'registered' => (bool)$device,
    'device' => $device ? [
        'device_id' => (int)$device['device_id'],
        'device_name' => (string)($device['device_name'] ?: 'Administrator phone'),
        'created_at' => (string)$device['created_at'],
        'last_used_at' => (string)($device['last_used_at'] ?: $device['created_at']),
    ] : null,
]);
