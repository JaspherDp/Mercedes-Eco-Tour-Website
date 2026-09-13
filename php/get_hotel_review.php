<?php
    require_once __DIR__ . '/session_security.php';
    AppSessionStart();
require_once 'db_connection.php';

header('Content-Type: application/json');

try {

    $hotel_id = (int) ($_GET['hotel_resort_id'] ?? 0);
    $booking_id = (int) ($_GET['hotel_booking_id'] ?? 0);
    $tourist_id = (int) ($_SESSION['tourist_id'] ?? 0);

    if ($hotel_id <= 0 || $booking_id <= 0 || $tourist_id <= 0) {
        echo json_encode([
            'success' => false,
            'message' => 'Invalid request'
        ]);
        exit;
    }

    $stmt = $pdo->prepare("
        SELECT 
            rating,
            value_rating,
            service_rating,
            cleanliness_rating,
            facilities_rating,
            room_comfort_rating,
            review_message
        FROM hotel_resort_reviews
        WHERE hotel_resort_id = ?
          AND hotel_booking_id = ?
          AND tourist_id = ?
        LIMIT 1
    ");

    $stmt->execute([$hotel_id, $booking_id, $tourist_id]);

    $review = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$review) {
        echo json_encode([
            'success' => false,
            'message' => 'No review found'
        ]);
        exit;
    }

    echo json_encode([
        'success' => true,
        'data' => $review
    ]);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
