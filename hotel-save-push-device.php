<?php
declare(strict_types=1);

require_once __DIR__ . '/Ho_common.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');

function hotelPushResponse(int $status, array $payload): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    hotelPushResponse(405, ['success' => false, 'message' => 'Only POST requests are accepted.']);
}
$hotelAdmin = HoRequireHotelAdmin($pdo, true);

$maximumBodyBytes = 16 * 1024;
$contentLength = filter_var($_SERVER['CONTENT_LENGTH'] ?? null, FILTER_VALIDATE_INT);
if ($contentLength !== false && $contentLength !== null && $contentLength > $maximumBodyBytes) {
    hotelPushResponse(413, ['success' => false, 'message' => 'The request body is too large.']);
}
$rawBody = file_get_contents('php://input', false, null, 0, $maximumBodyBytes + 1);
if ($rawBody === false || strlen($rawBody) > $maximumBodyBytes) {
    hotelPushResponse(413, ['success' => false, 'message' => 'The request body is too large.']);
}

try {
    $payload = json_decode($rawBody, true, 16, JSON_THROW_ON_ERROR);
} catch (JsonException $exception) {
    hotelPushResponse(400, ['success' => false, 'message' => 'The request body must contain valid JSON.']);
}
$expectedCsrf = (string)($_SESSION['hotel_push_csrf'] ?? '');
$submittedCsrf = is_array($payload) ? (string)($payload['csrf_token'] ?? '') : '';
if ($expectedCsrf === '' || !hash_equals($expectedCsrf, $submittedCsrf)) {
    hotelPushResponse(403, ['success' => false, 'message' => 'The notification setup session expired. Refresh and try again.']);
}
$token = trim((string)($payload['token'] ?? ''));
if (strlen($token) < 20 || strlen($token) > 4096 || preg_match('/[^A-Za-z0-9_:\-.]/', $token)) {
    hotelPushResponse(422, ['success' => false, 'message' => 'Firebase returned an invalid device token.']);
}
$deviceName = preg_replace('/\s+/u', ' ', trim((string)($payload['device_name'] ?? ''))) ?: '';
$deviceName = mb_substr($deviceName, 0, 100);
$hotelAdminId = (int)$hotelAdmin['hotel_admin_id'];

try {
    $pdo->beginTransaction();
    $save = $pdo->prepare(
        'INSERT INTO hotel_admin_push_devices (hotel_admin_id, fcm_token, device_name, last_used_at, revoked_at)
         VALUES (?, ?, ?, NOW(), NULL)
         ON DUPLICATE KEY UPDATE hotel_admin_id = VALUES(hotel_admin_id), device_name = VALUES(device_name),
             last_used_at = NOW(), revoked_at = NULL'
    );
    $save->execute([$hotelAdminId, $token, $deviceName !== '' ? $deviceName : null]);
    $revoke = $pdo->prepare(
        'UPDATE hotel_admin_push_devices SET revoked_at = NOW()
         WHERE hotel_admin_id = ? AND fcm_token <> ? AND revoked_at IS NULL'
    );
    $revoke->execute([$hotelAdminId, $token]);
    $pdo->commit();
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('Hotel administrator device registration failed: ' . $exception->getMessage());
    hotelPushResponse(503, ['success' => false, 'message' => 'The phone could not be registered right now.']);
}

hotelPushResponse(200, ['success' => true, 'message' => 'Payment notifications are enabled on this hotel administrator phone.']);
