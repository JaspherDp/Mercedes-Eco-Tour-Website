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

function adminPushJsonResponse(int $status, array $payload): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    adminPushJsonResponse(405, [
        'success' => false,
        'message' => 'Only POST requests are accepted.',
    ]);
}

if (!AdminValidateSession($pdo)) {
    adminPushJsonResponse(401, [
        'success' => false,
        'code' => 'SESSION_EXPIRED',
        'message' => 'Your administrator session expired. Please log in again.',
        'login_url' => AdminLoginUrl('admin-phone-setup.php'),
    ]);
}

$contentLength = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
if ($contentLength > 16384) {
    adminPushJsonResponse(413, [
        'success' => false,
        'message' => 'The device registration request is too large.',
    ]);
}

$rawBody = file_get_contents('php://input');
if (strlen((string)$rawBody) > 16384) {
    adminPushJsonResponse(413, [
        'success' => false,
        'message' => 'The device registration request is too large.',
    ]);
}
try {
    $payload = json_decode((string)$rawBody, true, 16, JSON_THROW_ON_ERROR);
} catch (JsonException $exception) {
    adminPushJsonResponse(400, [
        'success' => false,
        'message' => 'The request body must contain valid JSON.',
    ]);
}

if (!is_array($payload)) {
    adminPushJsonResponse(400, [
        'success' => false,
        'message' => 'The request body must be a JSON object.',
    ]);
}

$expectedCsrf = (string)($_SESSION['admin_push_csrf'] ?? '');
$submittedCsrf = is_string($payload['csrf_token'] ?? null) ? (string)$payload['csrf_token'] : '';
if ($expectedCsrf === '' || $submittedCsrf === '' || !hash_equals($expectedCsrf, $submittedCsrf)) {
    adminPushJsonResponse(403, [
        'success' => false,
        'message' => 'The notification setup session expired. Refresh the page and try again.',
    ]);
}

$token = is_string($payload['token'] ?? null) ? trim((string)$payload['token']) : '';
if (
    strlen($token) < 20
    || strlen($token) > 4096
    || preg_match('/[^A-Za-z0-9_:\-.]/', $token)
) {
    adminPushJsonResponse(422, [
        'success' => false,
        'message' => 'Firebase returned an invalid device token.',
    ]);
}

$deviceName = is_string($payload['device_name'] ?? null) ? trim((string)$payload['device_name']) : '';
$deviceName = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $deviceName) ?? '';
$deviceName = preg_replace('/\s+/u', ' ', trim($deviceName)) ?? '';
if (function_exists('mb_substr')) {
    $deviceName = mb_substr($deviceName, 0, 100, 'UTF-8');
} else {
    $deviceName = substr($deviceName, 0, 100);
}
$deviceName = $deviceName !== '' ? $deviceName : null;

$adminId = (int)$_SESSION['admin_id'];

try {
    $adminCheck = $pdo->prepare('SELECT admin_id FROM admin_users WHERE admin_id = ? LIMIT 1');
    $adminCheck->execute([$adminId]);
    if ((int)$adminCheck->fetchColumn() !== $adminId) {
        adminPushJsonResponse(401, [
            'success' => false,
            'message' => 'The Administrator account could not be verified.',
        ]);
    }

    $pdo->beginTransaction();
    $saveDevice = $pdo->prepare(
        'INSERT INTO admin_push_devices (admin_id, fcm_token, device_name, last_used_at, revoked_at)
         VALUES (?, ?, ?, NOW(), NULL)
         ON DUPLICATE KEY UPDATE
             admin_id = VALUES(admin_id),
             device_name = VALUES(device_name),
             last_used_at = NOW(),
             revoked_at = NULL'
    );
    $saveDevice->execute([$adminId, $token, $deviceName]);

    // The payment interface intentionally presents one registered phone. A
    // successful re-registration replaces older active devices atomically.
    $revokeOlderDevices = $pdo->prepare(
        'UPDATE admin_push_devices
         SET revoked_at = NOW()
         WHERE admin_id = ? AND fcm_token <> ? AND revoked_at IS NULL'
    );
    $revokeOlderDevices->execute([$adminId, $token]);
    $pdo->commit();
} catch (PDOException $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Unable to save the main Administrator Firebase device registration.');
    adminPushJsonResponse(503, [
        'success' => false,
        'message' => 'The device could not be registered right now. Please try again later.',
    ]);
}

adminPushJsonResponse(200, [
    'success' => true,
    'message' => 'Payment notifications are enabled on this Administrator device.',
]);
