<?php
    require_once __DIR__ . '/session_security.php';
    AppSessionStart();
require_once __DIR__ . '/db_connection.php';
require_once __DIR__ . '/admin_auth_helper.php';
header('Content-Type: application/json');

AdminRequireLogin();

try {
    $stmt = $pdo->query("
        SELECT 
            b.booking_id,
            b.booking_reference,
            b.booking_date,
            b.booking_type,
            COALESCE(b.is_notif_viewed, 0) AS is_notif_viewed,
            b.created_at,
            cr.cancellation_request_id,
            cr.tourist_decision,
            cr.rescheduled_service_date,
            cr.tourist_responded_at,
            COALESCE(cr.tourist_responded_at, b.created_at) AS notification_at,
            COALESCE(NULLIF(TRIM(t.full_name), ''), CONCAT('Tourist #', b.tourist_id)) AS full_name,
            t.profile_picture
        FROM bookings b
        LEFT JOIN tourist t ON t.tourist_id = b.tourist_id
        LEFT JOIN booking_cancellation_requests cr
          ON cr.booking_domain='tour'
         AND cr.booking_id=b.booking_id
         AND cr.tourist_responded_at IS NOT NULL
         AND cr.cancellation_request_id=(
             SELECT MAX(cr2.cancellation_request_id)
             FROM booking_cancellation_requests cr2
             WHERE cr2.booking_domain='tour' AND cr2.booking_id=b.booking_id AND cr2.tourist_responded_at IS NOT NULL
         )
        ORDER BY b.is_notif_viewed ASC, notification_at DESC, b.booking_id DESC
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
