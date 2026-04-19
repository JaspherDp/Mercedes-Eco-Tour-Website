<?php
session_start();
require 'db_connection.php'; // $pdo is available

// ✅ Check if user is logged in
if (!isset($_SESSION['tourist_id'])) {
    echo "You must log in first to submit an inquiry.";
    exit;
}

$tourist_id = $_SESSION['tourist_id'];

// ✅ Get form data
$inquiry_date = $_POST['inqDate'] ?? null;
$phone_number = $_POST['inqPhone'] ?? null;
$package_id   = $_POST['package_id'] ?? null;   // FIXED
$operator_id  = $_POST['operator_id'] ?? null;  // operator_id (NEW)
$num_adults   = isset($_POST['num_adults']) ? (int)$_POST['num_adults'] : 0;
$num_children = isset($_POST['num_children']) ? (int)$_POST['num_children'] : 0;

$pax = $num_adults + $num_children; // total pax
$status = 'pending';

// ✅ Basic validation
if (!$inquiry_date || !$phone_number || !$package_id || !$operator_id) {
    echo "Please fill in all required fields.";
    exit;
}

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

    echo "✅ Inquiry submitted successfully!";
} catch (PDOException $e) {
    echo "❌ Failed to submit inquiry: " . $e->getMessage();
}
?>
