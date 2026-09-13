<?php
require_once __DIR__ . '/session_security.php';
AppSessionStart();
require 'db_connection.php'; // $pdo is available
require_once __DIR__ . '/tourist_auth_helper.php';
require_once __DIR__ . '/activity_logger.php';
require_once __DIR__ . '/input_validation.php';

// ✅ Check if user is logged in
$tourist = TouristRequireLogin($pdo, 'text');
$tourist_id = (int)$tourist['tourist_id'];
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method not allowed.');
}
if (!AppVerifyCsrf('tourist', 'engagement', $_POST['csrf_token'] ?? null)) {
    http_response_code(403);
    exit('Invalid security token. Refresh the page and try again.');
}

// ✅ Get form data
try {
    $inquiry_date = ItourValidationDate($_POST['inqDate'] ?? null, 'Inquiry date');
    if ($inquiry_date < date('Y-m-d')) {
        throw new InvalidArgumentException('Inquiry date cannot be in the past.');
    }
    $phone_number = ItourValidationText($_POST['inqPhone'] ?? null, 'Phone number', 30, true);
    if (!preg_match('/^[0-9+().\-\s]{7,30}$/D', $phone_number)) {
        throw new InvalidArgumentException('Enter a valid phone number.');
    }
    $package_id = ItourValidationInt($_POST['package_id'] ?? null, 'Package', 1, PHP_INT_MAX);
    $num_adults = ItourValidationInt($_POST['num_adults'] ?? null, 'Adults', 1, 100);
    $num_children = ItourValidationInt($_POST['num_children'] ?? 0, 'Children', 0, 100);
    if ($num_adults + $num_children > 100) {
        throw new InvalidArgumentException('A maximum of 100 guests is allowed.');
    }
} catch (InvalidArgumentException $exception) {
    http_response_code(422);
    exit($exception->getMessage());
}

$packageStmt = $pdo->prepare('SELECT operator_id FROM tour_packages WHERE package_id = ? LIMIT 1');
$packageStmt->execute([$package_id]);
$operator_id = $packageStmt->fetchColumn();
if ($operator_id === false || (int)$operator_id < 1) {
    http_response_code(422);
    exit('The selected package is not available.');
}
$operator_id = (int)$operator_id;

$pax = $num_adults + $num_children; // total pax
$status = 'pending';

// ✅ Basic validation
// ✅ Insert inquiry
$sql = "INSERT INTO inquiries 
        (tourist_id, inquiry_date, phone_number, package, operator_id, num_adults, num_children, pax, status)
        VALUES 
        (:tourist_id, :inquiry_date, :phone_number, :package, :operator_id, :num_adults, :num_children, :pax, :status)";

$stmt = $pdo->prepare($sql);

try {
    $stmt->execute([
        ':tourist_id'   => $tourist_id,
        ':inquiry_date' => $inquiry_date,
        ':phone_number' => $phone_number,
        ':package'      => $package_id,
        ':operator_id'  => $operator_id,
        ':num_adults'   => $num_adults,
        ':num_children' => $num_children,
        ':pax'          => $pax,
        ':status'       => $status
    ]);
    logActivity(
        $pdo,
        'Tourist',
        (int)$tourist_id,
        (string)($_SESSION['full_name'] ?? $_SESSION['tourist_email'] ?? 'Tourist'),
        'Inquiry Submitted',
        'Submitted an inquiry for tour package #' . (int)$package_id . '.',
        'Inquiries',
        (int)$pdo->lastInsertId()
    );

    echo "✅ Inquiry submitted successfully!";
} catch (PDOException $e) {
    echo "❌ Failed to submit inquiry: " . $e->getMessage();
}
?>
