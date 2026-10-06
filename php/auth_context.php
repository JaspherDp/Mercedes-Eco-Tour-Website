<?php
require_once __DIR__ . '/session_security.php';
AppSessionStart();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'POST'
    || stripos((string)($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json') !== 0
    || ($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '') === 'cross-site') {
    http_response_code(400);
    echo json_encode(['success' => false]);
    exit;
}
$data = json_decode(file_get_contents('php://input'), true);
$target = is_array($data) && is_string($data['returnTo'] ?? null) ? $data['returnTo'] : '';
// Only booking pages within this app can be supplied by a modal trigger.
if ($target !== '' && (!preg_match('/^(hotel_booking|tour_booking)\.php(?:\?[^\r\n\\\\#]*)?$/D', $target)
    || preg_match('/[\x00-\x1F\x7F]/', $target))) {
    http_response_code(400);
    echo json_encode(['success' => false]);
    exit;
}
unset($_SESSION['post_login_redirect']);
if ($target !== '') $_SESSION['post_login_redirect'] = $target;
echo json_encode(['success' => true]);
