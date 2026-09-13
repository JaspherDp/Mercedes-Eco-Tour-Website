<?php
    require_once __DIR__ . '/session_security.php';
    AppSessionStart();
require_once 'db_connection.php';

header('Content-Type: application/json');

try {

    if (!isset($_SESSION['tourist_id'])) {
        echo json_encode(['success' => false, 'message' => 'Not logged in']);
        exit;
    }

    $tourist_id = (int) $_SESSION['tourist_id'];
    $booking_id = (int)($_GET['booking_id'] ?? 0);
    $type = strtolower(trim($_GET['type'] ?? ''));

    if (!$booking_id || !$type) {
        echo json_encode(['success' => false, 'message' => 'Missing params']);
        exit;
    }

    // map type → column
    $sql = "SELECT * FROM feedback 
            WHERE tourist_id = ? 
            AND LOWER(TRIM(booking_type)) = ?";

    if ($type === 'package') {
        $sql .= " AND package_id = ?";
    } elseif ($type === 'boat') {
        $sql .= " AND boat_id = ?";
    } elseif ($type === 'tourguide') {
        $sql .= " AND tourguide_id = ?";
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$tourist_id, $type, $booking_id]);

    $review = $stmt->fetch(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'data' => $review
    ]);

} catch (Throwable $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
