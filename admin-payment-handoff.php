<?php
declare(strict_types=1);

$token = strtolower(trim((string)($_GET['token'] ?? '')));
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Referrer-Policy: no-referrer');
if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
    http_response_code(400);
    exit('Invalid payment handoff link.');
}

require_once __DIR__ . '/php/db_connection.php';

$lookup = $pdo->prepare(
    "SELECT checkout_url, status, metadata
     FROM payment_transactions
     WHERE return_token = ? AND provider = 'paymongo'
     LIMIT 1"
);
$lookup->execute([$token]);
$transaction = $lookup->fetch(PDO::FETCH_ASSOC);
$metadata = $transaction ? json_decode((string)($transaction['metadata'] ?? ''), true) : null;
$checkoutUrl = trim((string)($transaction['checkout_url'] ?? ''));
$urlParts = parse_url($checkoutUrl);
$checkoutHost = strtolower((string)($urlParts['host'] ?? ''));
$isAdminPayment = is_array($metadata)
    && in_array(
        (string)($metadata['source'] ?? ''),
        ['admin_booking_payment', 'hotel_checkin_payment', 'hotel_checkout_payment', 'operator_booking_payment'],
        true
    );
$belongsToMainAdmin = $isAdminPayment
    && ($metadata['staff_type'] ?? '') !== 'hotel_admin'
    && (int)($metadata['admin_id'] ?? 0) > 0;
$belongsToHotelAdmin = $isAdminPayment
    && ($metadata['staff_type'] ?? '') === 'hotel_admin'
    && (int)($metadata['hotel_admin_id'] ?? 0) > 0
    && (int)($metadata['hotel_resort_id'] ?? 0) > 0;
$belongsToOperator = $isAdminPayment
    && ($metadata['staff_type'] ?? '') === 'operator'
    && (int)($metadata['operator_id'] ?? 0) > 0;
$belongsToStaff = $belongsToMainAdmin || $belongsToHotelAdmin || $belongsToOperator;
$isSafeCheckout = strtolower((string)($urlParts['scheme'] ?? '')) === 'https'
    && ($checkoutHost === 'checkout.paymongo.com' || str_ends_with($checkoutHost, '.paymongo.com'));

if (!$transaction || !$belongsToStaff || !$isSafeCheckout || (string)$transaction['status'] !== 'pending') {
    http_response_code(404);
    exit('This payment QR is unavailable or no longer active.');
}

header('Location: ' . $checkoutUrl, true, 302);
exit;
