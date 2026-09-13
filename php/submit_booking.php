<?php
// The production booking flow uses payments/create-booking-checkout.php,
// which derives prices and payment amounts server-side. Keep this legacy file
// for rollback/history, but do not expose its client-trusted flow over HTTP.
if (PHP_SAPI !== 'cli') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    http_response_code(410);
    echo json_encode([
        'success' => false,
        'message' => 'This legacy booking route is disabled. Please use the current secure checkout.',
    ]);
    exit;
}

    require_once __DIR__ . '/session_security.php';
    AppSessionStart();
require 'db_connection.php';
require_once __DIR__ . '/activity_logger.php';
require_once __DIR__ . '/booking_reference_helper.php';
require_once __DIR__ . '/tour_resource_availability_helper.php';
require_once __DIR__ . '/request_rate_limiter.php';

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
$booking_date       = tourResourceDate((string)($data['bookingDate'] ?? ''));
$contact_number     = $data['contactNumber'] ?? '';
$num_adults         = intval($data['numAdults'] ?? 0);
$num_children       = intval($data['numChildren'] ?? 0);
$operator_id_raw    = isset($data['operatorId']) ? intval($data['operatorId']) : 0;
$operator_id        = $operator_id_raw > 0 ? $operator_id_raw : null;
$tour_type          = $data['tourType'] ?? '';
$tour_range         = trim((string)($data['tourDuration'] ?? '')); // map tourDuration to tour_range
$jump_off_port      = $data['jumpOffPort'] ?? '';
$preferred_resource = trim((string)($data['preferredSelection'] ?? '')); // <- new field
$boat_id = isset($data['boat_id']) && $data['boat_id'] !== ''
    ? intval($data['boat_id'])
    : null;

$guide_id = isset($data['guide_id']) && $data['guide_id'] !== ''
    ? intval($data['guide_id'])
    : null;
$booking_end_date = tourResourceDate((string)($data['bookingEndDate'] ?? ''));

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
if ($booking_date === '') {
    echo json_encode(['success' => false, 'message' => 'A valid booking date is required']);
    exit;
}
if ($booking_date < date('Y-m-d')) {
    echo json_encode(['success' => false, 'message' => 'The booking date cannot be in the past']);
    exit;
}
if (strtolower($tour_type) === 'overnight' && ($booking_end_date === '' || $booking_end_date <= $booking_date)) {
    echo json_encode(['success' => false, 'message' => 'Please select an end date after the booking start date']);
    exit;
}
if (!$contact_number) {
    echo json_encode(['success' => false, 'message' => 'Contact number is required']);
    exit;
}

$bookingLimit = requestRateLimitConsume($pdo, 'booking_submission', 'tourist:' . (int)$tourist_id, 5, 3600);
if (!$bookingLimit['allowed']) {
    requestRateLimitReject($bookingLimit);
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
    $resourceId = $booking_type === 'boat' ? (int)$boat_id : ($booking_type === 'tourguide' ? (int)$guide_id : 0);
    $resourceLock = '';
    $resourceEndDate = strtolower($tour_type) === 'overnight' && $booking_end_date !== '' ? $booking_end_date : $booking_date;
    if ($booking_type === 'package') {
        $resourceLock = tourPackageLock($pdo, $package_name);
        $requestedGuests = max(1, $num_adults + $num_children);
        if (!tourPackageIsAvailable($pdo, $package_name, $booking_date, $resourceEndDate, $requestedGuests, $operator_id)) {
            throw new RuntimeException('This tour package does not have enough open guest slots for the selected date.');
        }
        if (strtolower($tour_type) === 'overnight') {
            $tour_range = $booking_date . ' to ' . $resourceEndDate;
        }
    } elseif ($resourceId > 0) {
        $resourceLock = tourResourceLock($pdo, $booking_type, $resourceId);
        if (!tourResourceIsAvailable($pdo, $booking_type, $resourceId, $booking_date, $resourceEndDate)) {
            throw new RuntimeException('This boat or tour guide is unavailable for the selected date.');
        }
        if (strtolower($tour_type) === 'overnight') {
            $tour_range = $booking_date . ' to ' . $resourceEndDate;
        }
    }
    BookingReferenceEnsureSchema($pdo);
    $bookingReference = BookingReferenceGenerate($pdo, $booking_type);
    $grand_total = isset($data['grandTotal'])
    ? floatval($data['grandTotal'])
    : 0;

    $payment_amount = isset($data['paymentAmount'])
        ? floatval($data['paymentAmount'])
        : 0;

    $remaining_balance = $grand_total - $payment_amount;
    $stmt = $pdo->prepare("
        INSERT INTO bookings 
(
booking_reference,
tourist_id,
booking_date,
location,
package_name,
phone_number,
booking_type,
operator_id,
tour_type,
tour_range,
jump_off_port,
preferred_resource,

boat_id,
guide_id,

grand_total,
remaining_balance,
payment_amount,
created_at,
updated_at,
status,
is_complete,
is_notif_viewed,
num_adults,
num_children
)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");

$stmt->execute([
    $bookingReference,
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
    $preferred_resource,

    $boat_id,
    $guide_id,

    $grand_total,
    $remaining_balance,
    $payment_amount,

    $created_at,
    $updated_at,
    $status,
    $is_complete,
    $is_notif_viewed,
    $num_adults,
    $num_children
]);
    tourResourceUnlock($pdo, $resourceLock ?? '');
    $bookingId = (int)$pdo->lastInsertId();
    logActivity(
        $pdo,
        'Tourist',
        (int)$tourist_id,
        (string)($_SESSION['full_name'] ?? $_SESSION['tourist_email'] ?? 'Tourist'),
        'Booking Submitted',
        'Submitted ' . $bookingReference . ', a ' . ($booking_type ?: 'tour') . ' booking for ' . ($package_name_col ?: $location) . '.',
        'Bookings',
        $bookingId
    );

    echo json_encode([
        'success' => true,
        'message' => 'Booking successful!',
        'booking_reference' => $bookingReference
    ]);
} catch(Throwable $e) {
    tourResourceUnlock($pdo, $resourceLock ?? '');
    echo json_encode(['success' => false, 'message' => 'Booking failed: ' . $e->getMessage()]);
}
?>
