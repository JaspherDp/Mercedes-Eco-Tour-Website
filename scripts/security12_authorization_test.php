<?php
declare(strict_types=1);

function security12Assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function security12Source(string $relativePath): string
{
    $source = file_get_contents(__DIR__ . '/../' . $relativePath);
    if (!is_string($source)) {
        throw new RuntimeException('Could not read ' . $relativePath);
    }
    return $source;
}

$publicReturnFiles = [
    'payments/payment-cancel.php',
    'payments/payment-phone-return.php',
];
foreach ($publicReturnFiles as $file) {
    $source = security12Source($file);
    security12Assert(
        preg_match('/\b(?:UPDATE|INSERT\s+INTO|DELETE\s+FROM)\s+(?:payment_transactions|booking_checkout_drafts|bookings|hotel_room_bookings)\b/i', $source) !== 1,
        $file . ' still contains a financial-state write.'
    );
    security12Assert(!str_contains($source, 'reconcilePaidCheckout'), $file . ' still reconciles payment state from GET.');
    security12Assert(!str_contains($source, 'expireCheckoutSession'), $file . ' still expires a provider session from GET.');
}

$cancelAction = security12Source('payments/create-balance-checkout.php');
foreach ([
    "REQUEST_METHOD'] ?? '') !== 'POST'",
    'AdminValidateSession($pdo)',
    'hash_equals($adminCsrf, $submittedCsrf)',
    "action'] ?? '') === 'cancel_pending'",
    "POST['return_token']",
    'INNER JOIN hotel_room_bookings',
    'INNER JOIN bookings',
    'expireCheckoutSession($checkoutSessionId)',
    'LIMIT 1 FOR UPDATE',
    "AND return_token = ? AND status = 'pending'",
    'Pending QR Payment Cancelled',
] as $requiredFragment) {
    security12Assert(str_contains($cancelAction, $requiredFragment), 'Protected cancellation is missing: ' . $requiredFragment);
}

foreach ([
    'php/request_booking_cancellation.php',
    'php/respond_provider_cancellation.php',
    'php/refund_institutions.php',
] as $file) {
    $source = security12Source($file);
    security12Assert(str_contains($source, "TouristRequireLogin(\$pdo, 'json')"), $file . ' lacks canonical tourist revalidation.');
    security12Assert(str_contains($source, "\$authenticatedTourist['tourist_id']"), $file . ' does not derive tourist identity from the revalidated record.');
    security12Assert(
        preg_match('/(?:booking_id|cancellation_request_id)\s*=\s*\?[^;]{0,400}tourist_id\s*=\s*\?/is', $source) === 1,
        $file . ' lacks an ownership-bound booking/refund query.'
    );
}

$adminUi = security12Source('admin/adbookings.php');
$hotelUi = security12Source('Hobookings.php');
security12Assert(str_contains($adminUi, "form.append('return_token', String(returnToken))"), 'Admin Cancel/Retry does not submit the exact QR attempt token.');
security12Assert(str_contains($hotelUi, "form.append('return_token', String(returnToken))"), 'Hotel Cancel/Retry does not submit the exact QR attempt token.');
foreach ([
    'admin/adbookings.php',
    'Hobookings.php',
    'Horooms.php',
    'operator/opbookings.php',
    'js/adpaymenttransactions.js',
    'js/Ho_payments.js',
] as $file) {
    $source = security12Source($file);
    preg_match_all('/(?:append|set)\([\'\"]action[\'\"]\s*,\s*[\'\"]cancel_pending[\'\"]\)(.{0,700})/s', $source, $matches);
    foreach ($matches[1] ?? [] as $cancelRequest) {
        security12Assert(str_contains($cancelRequest, 'return_token'), $file . ' has a Cancel/Retry request without the exact QR attempt token.');
    }
}
security12Assert(str_contains($cancelAction, 'sendAdminPaymentQrNotification'), 'Admin paired-phone QR delivery was removed.');
security12Assert(str_contains($cancelAction, 'sendHotelAdminPaymentQrNotification'), 'Hotel paired-phone QR delivery was removed.');
security12Assert(str_contains($cancelAction, 'sendOperatorPaymentQrNotification'), 'Operator paired-phone QR delivery was removed.');
security12Assert(str_contains($cancelAction, "bin2hex(random_bytes(32))"), 'Fresh QR return-token creation was removed.');
security12Assert(str_contains($cancelAction, "status = 'pending'"), 'Pending-only cancellation guard was removed.');

echo "Security #12 authorization static tests passed.\n";
