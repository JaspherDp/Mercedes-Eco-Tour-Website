<?php
require_once 'db_connection.php';
$pdo->query("UPDATE bookings SET is_notif_viewed = 1 WHERE is_notif_viewed = 0 OR is_notif_viewed IS NULL");
header('Content-Type: application/json');
echo json_encode(['success' => true]);
