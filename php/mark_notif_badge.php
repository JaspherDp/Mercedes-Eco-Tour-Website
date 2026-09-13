<?php
if (session_status() === PHP_SESSION_NONE) {
    require_once __DIR__ . '/session_security.php';
    AppSessionStart();
}
require_once 'db_connection.php';
require_once __DIR__ . '/admin_auth_helper.php';
AdminRequireLogin();
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit;
}
if (!AppVerifyCsrf('admin', 'notifications', $_POST['csrf_token'] ?? null)) {
    http_response_code(403);
    exit;
}
$pdo->query("UPDATE bookings SET is_notif_viewed = 1 WHERE is_notif_viewed = 0 OR is_notif_viewed IS NULL");
header('Content-Type: application/json');
echo json_encode(['success' => true]);
