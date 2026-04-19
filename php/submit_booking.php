<?php
session_start();
require 'db_connection.php';

// Ensure JSON output
header('Content-Type: application/json');
error_reporting(E_ERROR | E_PARSE);

// Check login
if (!isset($_SESSION['tourist_id'])) {
    echo json_encode(['success' => false, 'message' => 'User not logged in']);
    exit;
}

// Get JSON data
$data = json_decode(file_get_contents('php://input'), true);

$tourist_id         = $_SESSION['tourist_id'];
$booking_type       = strtolower(trim((string)($data['bookingType'] ?? '')));
$package_name       = $data['packageName'] ?? '';
$selectedLocations  = $data['selectedLocations'] ?? [];
$booking_date       = $data['bookingDate'] ?? '';
$contact_number     = $data['contactNumber'] ?? '';
$num_adults         = intval($data['numAdults'] ?? 0);
$num_children       = intval($data['numChildren'] ?? 0);
$operator_id_raw    = isset($data['operatorId']) ? intval($data['operatorId']) : 0;
$operator_id        = $operator_id_raw > 0 ? $operator_id_raw : null;
$tour_type          = $data['tourType'] ?? '';
$tour_range         = trim((string)($data['tourDuration'] ?? '')); // map tourDuration to tour_range
$jump_off_port      = $data['jumpOffPort'] ?? '';
$preferred_resource = trim((string)($data['preferredSelection'] ?? '')); // <- new field

// Prevent SQL truncation on shorter VARCHAR columns
$tour_range = function_exists('mb_substr') ? mb_substr($tour_range, 0, 100) : substr($tour_range, 0, 100);
$preferred_resource = function_exists('mb_substr') ? mb_substr($preferred_resource, 0, 255) : substr($preferred_resource, 0, 255);

// Validation
if (!$booking_type) {
    echo json_encode(['success' => false, 'message' => 'Booking type is required']);
    exit;
}
if (!in_array($booking_type, ['package', 'boat', 'tourguide'], true)) {
    echo json_encode(['success' => false, 'message' => 'Invalid booking type']);
    exit;
}
if ($booking_type === 'package' && !$package_name) {
    echo json_encode(['success' => false, 'message' => 'Package name is required']);
    exit;
}
if (($booking_type === 'boat' || $booking_type === 'tourguide') && empty($selectedLocations)) {
    echo json_encode(['success' => false, 'message' => 'At least one location must be selected']);
    exit;
}
if (!$booking_date) {
    echo json_encode(['success' => false, 'message' => 'Booking date is required']);
    exit;
}
if (!$contact_number) {
    echo json_encode(['success' => false, 'message' => 'Contact number is required']);
    exit;
}

// Ensure operator is attached for package bookings so operator panels receive notifications
if ($booking_type === 'package' && (!$operator_id || $operator_id <= 0)) {
    try {
        $opStmt = $pdo->prepare("
            SELECT operator_id
            FROM tour_packages
            WHERE package_title = ?
            ORDER BY package_id DESC
            LIMIT 1
        ");
        $opStmt->execute([$package_name]);
        $resolvedOperatorId = (int)$opStmt->fetchColumn();
        if ($resolvedOperatorId > 0) {
            $operator_id = $resolvedOperatorId;
        }
    } catch (Exception $e) {
        // Keep existing value when lookup fails
    }
}
if ($booking_type !== 'package') {
    $operator_id = null;
}

// Prepare fields
$location = ($booking_type === 'boat' || $booking_type === 'tourguide') ? implode(',', $selectedLocations) : '';
$package_name_col = ($booking_type === 'package') ? $package_name : null;
$status = 'pending';
$is_complete = 'uncomplete';
$is_notif_viewed = 0;
$created_at = date('Y-m-d H:i:s');
$updated_at = $created_at;

try {
    $stmt = $pdo->prepare("
        INSERT INTO bookings 
        (tourist_id, booking_date, location, package_name, phone_number, booking_type, operator_id, tour_type, tour_range, jump_off_port, preferred_resource, created_at, updated_at, status, is_complete, is_notif_viewed, num_adults, num_children)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");

    $stmt->execute([
        $tourist_id,
        $booking_date,
        $location,
        $package_name_col,
        $contact_number,
        $booking_type,
        $operator_id,
        $tour_type,
        $tour_range,
        $jump_off_port,
        $preferred_resource, // <- saved here
        $created_at,
        $updated_at,
        $status,
        $is_complete,
        $is_notif_viewed,
        $num_adults,
        $num_children
    ]);

    echo json_encode(['success' => true, 'message' => 'Booking successful!']);
} catch(PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Booking failed: ' . $e->getMessage()]);
}
?>
