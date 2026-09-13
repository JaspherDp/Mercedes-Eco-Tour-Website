<?php
require_once __DIR__ . '/session_security.php';
AppSessionStart();
require_once __DIR__ . '/../Ho_common.php';
require_once __DIR__ . '/activity_logger.php';

logActivity(
    $pdo,
    'Hotel Owner',
    (int)($_SESSION['hotel_admin_id'] ?? 0),
    (string)($_SESSION['hotel_admin_name'] ?? $_SESSION['hotel_admin_username'] ?? 'Hotel Owner'),
    'Logout',
    'Signed out of the hotel owner panel.',
    'Authentication'
);
HoClearHotelAdminSession();
session_regenerate_id(true);
$_SESSION['alert'] = [
    'type' => 'success',
    'title' => 'Logout Successful',
    'message' => 'You have securely signed out of the property portal.'
];
header('Location: hotel_admin_login.php');
exit;
