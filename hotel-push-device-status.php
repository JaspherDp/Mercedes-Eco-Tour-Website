<?php
declare(strict_types=1);

require_once __DIR__ . '/Ho_common.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');

function hotelDeviceStatusResponse(int $status, array $payload): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    hotelDeviceStatusResponse(405, ['success' => false, 'message' => 'Only GET requests are accepted.']);
}
$hotelAdmin = HoRequireHotelAdmin($pdo, true);
$hotelAdminId = (int)$hotelAdmin['hotel_admin_id'];
try {
    $deviceQuery = $pdo->prepare(
        'SELECT device_id, device_name, created_at, last_used_at
         FROM hotel_admin_push_devices
         WHERE hotel_admin_id = ? AND revoked_at IS NULL
         ORDER BY COALESCE(last_used_at, created_at) DESC, device_id DESC
         LIMIT 1'
    );
    $deviceQuery->execute([$hotelAdminId]);
    $device = $deviceQuery->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $exception) {
    error_log('Unable to read hotel administrator phone registration: ' . $exception->getMessage());
    hotelDeviceStatusResponse(503, ['success' => false, 'message' => 'The registered phone could not be checked right now.']);
}

hotelDeviceStatusResponse(200, [
    'success' => true,
    'registered' => (bool)$device,
    'device' => $device ? [
        'device_id' => (int)$device['device_id'],
        'device_name' => (string)($device['device_name'] ?: 'Hotel administrator phone'),
        'created_at' => (string)$device['created_at'],
        'last_used_at' => (string)($device['last_used_at'] ?: $device['created_at']),
    ] : null,
]);
