<?php
declare(strict_types=1);

require_once __DIR__ . '/../php/db_connection.php';
require_once __DIR__ . '/paymongo-config.php';
require_once __DIR__ . '/../php/app_url_helper.php';

header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');

$token = strtolower(trim((string)($_GET['token'] ?? '')));
$isAdminPayment = false;
$isHotelAdminPayment = false;
$isOperatorPayment = false;
try {
    $returnBaseUrl = ItourPaymentReturnBaseUrl();
} catch (Throwable $exception) {
    error_log('PayMongo cancel return URL configuration error: ' . $exception->getMessage());
    http_response_code(503);
    exit('Payment return is temporarily unavailable.');
}
if (preg_match('/^[a-f0-9]{64}$/', $token)) {
    try {
        $lookup = $pdo->prepare('SELECT metadata FROM payment_transactions WHERE return_token = ? LIMIT 1');
        $lookup->execute([$token]);
        $metadata = json_decode((string)$lookup->fetchColumn(), true);
        $isAdminPayment = is_array($metadata)
            && in_array((string)($metadata['source'] ?? ''), ['admin_booking_payment', 'hotel_checkin_payment', 'hotel_checkout_payment', 'operator_booking_payment'], true);
        $isHotelAdminPayment = $isAdminPayment && ($metadata['staff_type'] ?? '') === 'hotel_admin';
        $isOperatorPayment = $isAdminPayment && ($metadata['staff_type'] ?? '') === 'operator';
    } catch (Throwable $exception) {
        error_log('PayMongo cancel return could not read local routing metadata: ' . $exception->getMessage());
    }
}

if ($isAdminPayment) {
    $returnPage = $isHotelAdminPayment ? '/Hobookings.php?' : ($isOperatorPayment ? '/opbookings.php?' : '/adbookings.php?');
    header('Location: ' . $returnBaseUrl . $returnPage . http_build_query([
        'payment_return' => 'cancelled',
        'payment_return_token' => $token,
    ], '', '&', PHP_QUERY_RFC3986), true, 303);
    exit;
}

header('Location: ' . $returnBaseUrl . '/php/profile.php?' . http_build_query([
    'section' => 'bookings',
    'payment_return' => 'cancelled',
    'payment_return_token' => $token,
], '', '&', PHP_QUERY_RFC3986), true, 303);
exit;
