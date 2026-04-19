<?php
// Show errors for debugging
ini_set('display_errors', 1);
error_reporting(E_ALL);

header('Content-Type: application/json');

require 'db_connection.php'; // Make sure this path is correct

try {
    $stmt = $pdo->prepare("SELECT * FROM bookings ORDER BY booking_id DESC");
    $stmt->execute();
    $bookings = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode($bookings);
} catch (PDOException $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
