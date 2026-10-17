<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/payment_receipt_breakdown_test.php';
require_once __DIR__ . '/../php/booking_success_receipt.php';
$pdo->exec('CREATE TABLE tourist (tourist_id INTEGER, full_name TEXT)');
$pdo->exec('CREATE TABLE bookings (booking_id INTEGER, booking_reference TEXT, tourist_id INTEGER, booking_type TEXT, package_name TEXT, location TEXT, preferred_resource TEXT, operator_id INTEGER, tour_type TEXT, num_adults INTEGER, num_children INTEGER, grand_total REAL, payment_amount REAL, remaining_balance REAL, status TEXT, is_complete TEXT)');
$pdo->exec('CREATE TABLE booking_expenses (id INTEGER, booking_id INTEGER, expense_type TEXT, expense_name TEXT, amount REAL)');
$pdo->exec("INSERT INTO tourist VALUES (1,'Receipt Guest')");
$pdo->exec("INSERT INTO bookings VALUES (29,'TEST-BOAT',1,'boat','','','',1,'same-day',2,0,3760,752,3008,'pending','uncomplete')");
$pdo->exec("INSERT INTO booking_expenses VALUES (1,29,'food','Food add-on',100)");
$scope = ['booking_domain'=>'boat','booking_reference'=>'TEST-BOAT','tourist_id'=>1];
$pdo->exec('PRAGMA query_only = ON');
$receipt = BookingSuccessReceipt($pdo, $scope);
receiptCheck($receipt['amountPaid'] === 752.0 && $receipt['balance'] === 3008.0, 'Receipt must show the recorded downpayment and current balance.');
receiptCheck($receipt['billingTotal'] === 3760.0 && $receipt['serviceAmount'] === 3660.0, 'Add-ons must not be charged twice.');
receiptCheck(count($receipt['expenses']) === 1 && abs(array_sum(array_column($receipt['priceBreakdown']['rows'], 'amount')) - 3660) < .009, 'Saved fees and add-ons must reconcile to total.');
foreach ([['tourist_id'=>2], ['booking_domain'=>'package'], ['booking_reference'=>'OTHER']] as $change) {
    $denied = false;
    try { BookingSuccessReceipt($pdo, array_merge($scope, $change)); } catch (RuntimeException $e) { $denied = true; }
    receiptCheck($denied, 'A receipt must stay within the authorized reservation.');
}
$pdo->exec('PRAGMA query_only = OFF');
$pdo->exec('CREATE TABLE hotel_room_bookings (hotel_booking_id INTEGER, booking_reference TEXT, tourist_id INTEGER, room_type TEXT, first_name TEXT, last_name TEXT, rooms_booked INTEGER, nights INTEGER, unit_price REAL, checkin_date TEXT, checkout_date TEXT, checkout_total_nights INTEGER, total_amount REAL, amount_paid REAL, remaining_balance REAL, checkout_additional_charges REAL, booking_status TEXT, hotel_resort_id INTEGER)');
$pdo->exec('CREATE TABLE hotel_booking_expenses (hotel_booking_id INTEGER, hotel_resort_id INTEGER, expense_type TEXT, expense_name TEXT, quantity INTEGER, unit_price REAL, total_amount REAL, notes TEXT, created_at TEXT)');
$pdo->exec("INSERT INTO hotel_room_bookings VALUES (1,'TEST-HOTEL',1,'Deluxe','Receipt','Guest',1,2,1000,'2026-10-20','2026-10-22',2,2500,2500,0,200,'confirmed',3)");
$pdo->exec("INSERT INTO hotel_booking_expenses VALUES (1,3,'food','Breakfast',2,150,300,'','2026-10-20')");
$pdo->exec('PRAGMA query_only = ON');
$hotel = BookingSuccessReceipt($pdo, ['booking_domain'=>'hotel','booking_reference'=>'TEST-HOTEL','tourist_id'=>1]);
receiptCheck($hotel['serviceAmount'] === 2000.0 && $hotel['checkoutCharges'] === 200.0 && $hotel['billingTotal'] === 2500.0, 'Hotel room charges, expenses, and checkout charges must reconcile.');
receiptCheck($hotel['amountPaid'] === 2500.0 && $hotel['balance'] === 0.0 && $hotel['paymentStatus'] === 'Paid', 'Fully paid receipts must show no balance.');
echo "PASS: success receipts use read-only queries, authorized reservation scope, itemized charges, downpayments, add-ons, hotel charges, and fully paid balances.\n";
