<?php
declare(strict_types=1);

require_once __DIR__ . '/php/session_security.php';
AppSessionStart();
require_once __DIR__ . '/php/db_connection.php';
require_once __DIR__ . '/php/operator_auth_helper.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');

function operatorDeviceStatusResponse(int $status, array $payload): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    header('Allow: GET');
    operatorDeviceStatusResponse(405, ['success' => false, 'message' => 'Only GET requests are accepted.']);
}
$operatorAccount = OperatorRequireLogin($pdo, 'json');
$operatorId = (int)$_SESSION['operator_id'];
try {
    $operatorCheck = $pdo->prepare("SELECT operator_id FROM operators WHERE operator_id = ? AND status = 'active' LIMIT 1");
    $operatorCheck->execute([$operatorId]);
    if ((int)$operatorCheck->fetchColumn() !== $operatorId) {
        operatorDeviceStatusResponse(401, ['success' => false, 'message' => 'The tour operator account could not be verified.']);
    }
    $deviceQuery = $pdo->prepare(
        'SELECT device_id, device_name, created_at, last_used_at
         FROM operator_push_devices
         WHERE operator_id = ? AND revoked_at IS NULL
         ORDER BY COALESCE(last_used_at, created_at) DESC, device_id DESC
         LIMIT 1'
    );
    $deviceQuery->execute([$operatorId]);
    $device = $deviceQuery->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $exception) {
    error_log('Unable to read tour operator phone registration: ' . $exception->getMessage());
    operatorDeviceStatusResponse(503, ['success' => false, 'message' => 'The registered phone could not be checked right now.']);
}

operatorDeviceStatusResponse(200, [
    'success' => true,
    'registered' => (bool)$device,
    'device' => $device ? [
        'device_id' => (int)$device['device_id'],
        'device_name' => (string)($device['device_name'] ?: 'Operator phone'),
        'created_at' => (string)$device['created_at'],
        'last_used_at' => (string)($device['last_used_at'] ?: $device['created_at']),
    ] : null,
]);
