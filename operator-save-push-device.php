<?php
declare(strict_types=1);

require_once __DIR__ . '/php/session_security.php';
AppSessionStart();
require_once __DIR__ . '/php/db_connection.php';
require_once __DIR__ . '/php/operator_auth_helper.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');

function operatorPushResponse(int $status, array $payload): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    operatorPushResponse(405, ['success' => false, 'message' => 'Only POST requests are accepted.']);
}
$operatorAccount = OperatorRequireLogin($pdo, 'json');
if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 16384) {
    operatorPushResponse(413, ['success' => false, 'message' => 'The device registration request is too large.']);
}
try {
    $payload = json_decode((string)file_get_contents('php://input'), true, 16, JSON_THROW_ON_ERROR);
} catch (JsonException $exception) {
    operatorPushResponse(400, ['success' => false, 'message' => 'The request body must contain valid JSON.']);
}
if (!is_array($payload)) operatorPushResponse(400, ['success' => false, 'message' => 'The request body must be a JSON object.']);

$expectedCsrf = (string)($_SESSION['operator_push_csrf'] ?? '');
$submittedCsrf = (string)($payload['csrf_token'] ?? '');
if ($expectedCsrf === '' || $submittedCsrf === '' || !hash_equals($expectedCsrf, $submittedCsrf)) {
    operatorPushResponse(403, ['success' => false, 'message' => 'The notification setup session expired. Refresh and try again.']);
}
$token = trim((string)($payload['token'] ?? ''));
if (strlen($token) < 20 || strlen($token) > 4096 || preg_match('/[^A-Za-z0-9_:\-.]/', $token)) {
    operatorPushResponse(422, ['success' => false, 'message' => 'Firebase returned an invalid device token.']);
}
$deviceName = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', (string)($payload['device_name'] ?? '')) ?? '';
$deviceName = preg_replace('/\s+/u', ' ', trim($deviceName)) ?: '';
$deviceName = function_exists('mb_substr') ? mb_substr($deviceName, 0, 100, 'UTF-8') : substr($deviceName, 0, 100);

$operatorId = (int)$_SESSION['operator_id'];
try {
    $operatorCheck = $pdo->prepare("SELECT operator_id FROM operators WHERE operator_id = ? AND status = 'active' LIMIT 1");
    $operatorCheck->execute([$operatorId]);
    if ((int)$operatorCheck->fetchColumn() !== $operatorId) {
        operatorPushResponse(401, ['success' => false, 'message' => 'The tour operator account could not be verified.']);
    }
    $pdo->beginTransaction();
    $save = $pdo->prepare(
        'INSERT INTO operator_push_devices (operator_id, fcm_token, device_name, last_used_at, revoked_at)
         VALUES (?, ?, ?, NOW(), NULL)
         ON DUPLICATE KEY UPDATE operator_id = VALUES(operator_id), device_name = VALUES(device_name),
             last_used_at = NOW(), revoked_at = NULL'
    );
    $save->execute([$operatorId, $token, $deviceName !== '' ? $deviceName : null]);
    $revoke = $pdo->prepare(
        'UPDATE operator_push_devices SET revoked_at = NOW()
         WHERE operator_id = ? AND fcm_token <> ? AND revoked_at IS NULL'
    );
    $revoke->execute([$operatorId, $token]);
    $pdo->commit();
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('Tour operator device registration failed: ' . $exception->getMessage());
    operatorPushResponse(503, ['success' => false, 'message' => 'The phone could not be registered right now.']);
}

operatorPushResponse(200, ['success' => true, 'message' => 'Payment notifications are enabled on this operator phone.']);
