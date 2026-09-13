<?php
require_once __DIR__ . '/session_security.php';
AppSessionStart();
require_once __DIR__ . '/db_connection.php';
require_once __DIR__ . '/activity_logger.php';
if (!empty($_SESSION['tourist_id'])) {
    logActivity(
        $pdo,
        'Tourist',
        (int)$_SESSION['tourist_id'],
        (string)($_SESSION['full_name'] ?? $_SESSION['tourist_email'] ?? 'Tourist'),
        'Logout',
        'Signed out of the tourist account.',
        'Authentication'
    );
}
AppDestroySession();
http_response_code(200); // optional
?>
