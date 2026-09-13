<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    require_once __DIR__ . '/session_security.php';
    AppSessionStart();
}
require_once __DIR__ . '/db_connection.php';
require_once __DIR__ . '/admin_auth_helper.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    echo json_encode(['success' => false, 'msg' => 'Method not allowed.']);
    exit;
}
if (!AdminValidateSession($pdo)) {
    http_response_code(401);
    echo json_encode(['success' => false, 'msg' => 'Administrator authentication required.']);
    exit;
}

$data = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($data)) {
    $data = $_POST;
}
$csrf = (string)($data['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
$sessionCsrf = (string)($_SESSION['cancellation_admin_csrf'] ?? '');
if ($csrf === '' || $sessionCsrf === '' || !hash_equals($sessionCsrf, $csrf)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'msg' => 'Invalid security token.']);
    exit;
}
if (!isset($data['booking_id'], $data['tourist_locked'])
    || filter_var($data['booking_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
    http_response_code(422);
    echo json_encode(['success' => false, 'msg' => 'Invalid request.']);
    exit;
}

$bookingId = (int)$data['booking_id'];
$parsedLocked = filter_var($data['tourist_locked'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
if ($parsedLocked === null) {
    http_response_code(422);
    echo json_encode(['success' => false, 'msg' => 'tourist_locked must be a boolean.']);
    exit;
}
$locked = $parsedLocked ? 1 : 0;
$stmt = $pdo->prepare('UPDATE bookings SET tourist_locked = ? WHERE booking_id = ?');
$success = $stmt->execute([$locked, $bookingId]);

echo json_encode(['success' => $success]);
