<?php
session_start();
require_once __DIR__ . '/db_connection.php';
header('Content-Type: application/json');

try {
    $stmt = $pdo->query("
        SELECT 
            b.booking_id,
            b.booking_date,
            b.booking_type,
            COALESCE(b.is_notif_viewed, 0) AS is_notif_viewed,
            b.created_at,
            COALESCE(NULLIF(TRIM(t.full_name), ''), CONCAT('Tourist #', b.tourist_id)) AS full_name,
            t.profile_picture
        FROM bookings b
        LEFT JOIN tourist t ON t.tourist_id = b.tourist_id
        ORDER BY b.is_notif_viewed ASC, b.created_at DESC, b.booking_id DESC
        LIMIT 50
    ");

    $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $unread = $pdo->query("SELECT COUNT(*) FROM bookings WHERE COALESCE(is_notif_viewed, 0) = 0")->fetchColumn();

    echo json_encode([
        "unread" => (int)$unread,
        "data"   => $notifications
    ]);
} catch (Throwable $e) {
    echo json_encode([
        "unread" => 0,
        "data" => [],
        "error" => "Failed to load notifications"
    ]);
}
?>
