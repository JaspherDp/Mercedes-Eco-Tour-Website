<?php
declare(strict_types=1);

require_once __DIR__ . '/paymongo-config.php';
require_once __DIR__ . '/../php/db_connection.php';
require_once __DIR__ . '/PayMongoService.php';
require_once __DIR__ . '/PaymentReconciler.php';
require_once __DIR__ . '/../php/app_url_helper.php';

header('Cache-Control: no-store');

function bookingPaymentSafeReturnPath(mixed $value): string
{
    $value = trim((string)$value);
    $parts = @parse_url($value);
    if ($value === '' || !$parts || isset($parts['scheme']) || isset($parts['host'])
        || str_contains($value, "\r") || str_contains($value, "\n")) {
        return '';
    }
    $path = ltrim((string)($parts['path'] ?? ''), '/\\');
    if ($path === '' || !preg_match('/^[A-Za-z0-9_.\/-]+\.php$/', $path)) {
        return '';
    }
    $query = isset($parts['query']) && $parts['query'] !== '' ? ('?' . $parts['query']) : '';
    return $path . $query;
}

function bookingPaymentAppendQuery(string $path, array $params): string
{
    $parts = parse_url($path);
    parse_str((string)($parts['query'] ?? ''), $existing);
    $query = http_build_query(array_merge($existing, $params), '', '&', PHP_QUERY_RFC3986);
    return (string)$parts['path'] . ($query !== '' ? '?' . $query : '');
}

$token = strtolower(trim((string)($_GET['token'] ?? '')));
try {
    $returnBaseUrl = ItourPaymentReturnBaseUrl();
} catch (Throwable $exception) {
    error_log('PayMongo success return URL configuration error: ' . $exception->getMessage());
    http_response_code(503);
    exit('Payment return is temporarily unavailable.');
}

$isAdminPayment = false;
$isHotelAdminPayment = false;
$isOperatorPayment = false;
$bookingCheckoutReturnPath = '';
$bookingCheckoutStatus = '';
$bookingCheckoutReference = '';
$bookingCheckoutDomain = '';
if (preg_match('/^[a-f0-9]{64}$/', $token)) {
    try {
        $stmt = $pdo->prepare('SELECT metadata, status, provider_checkout_session_id FROM payment_transactions WHERE return_token = ? LIMIT 1');
        $stmt->execute([$token]);
        $transaction = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $metadata = json_decode((string)($transaction['metadata'] ?? ''), true);
        $isAdminPayment = is_array($metadata)
            && in_array((string)($metadata['source'] ?? ''), ['admin_booking_payment', 'hotel_checkin_payment', 'hotel_checkout_payment', 'operator_booking_payment'], true);
        $isHotelAdminPayment = $isAdminPayment && ($metadata['staff_type'] ?? '') === 'hotel_admin';
        $isOperatorPayment = $isAdminPayment && ($metadata['staff_type'] ?? '') === 'operator';
        $isBookingCheckout = is_array($metadata) && ($metadata['source'] ?? '') === 'booking_checkout';

        // Webhooks remain authoritative. This retrieval is a safe fallback for
        // local/ngrok test setups where the browser returns before the webhook.
        if (($transaction['status'] ?? '') === 'pending'
            && str_starts_with((string)($transaction['provider_checkout_session_id'] ?? ''), 'cs_')) {
            $checkout = PayMongoService::fromEnvironment()->retrieveCheckoutSession((string)$transaction['provider_checkout_session_id']);
            $resource = is_array($checkout['data'] ?? null) ? $checkout['data'] : [];
            $attributes = is_array($resource['attributes'] ?? null) ? $resource['attributes'] : [];
            $status = strtolower((string)($attributes['payment_status'] ?? $attributes['status'] ?? ''));
            if (in_array($status, ['paid', 'completed'], true) || !empty($attributes['payments'])) {
                PaymentReconciler::reconcilePaidCheckout($pdo, $resource);
            }
        }
        if ($isBookingCheckout) {
            $refetch = $pdo->prepare('SELECT status, booking_reference, booking_domain, booking_id FROM payment_transactions WHERE return_token = ? LIMIT 1');
            $refetch->execute([$token]);
            $latest = $refetch->fetch(PDO::FETCH_ASSOC) ?: [];
            $bookingCheckoutStatus = strtolower((string)($latest['status'] ?? ''));
            $bookingCheckoutReference = preg_replace('/[^A-Z0-9-]/i', '', (string)($latest['booking_reference'] ?? ''));
            $bookingCheckoutDomain = strtolower((string)($latest['booking_domain'] ?? ''));
            $bookingCheckoutReturnPath = bookingPaymentSafeReturnPath($metadata['return_path'] ?? '');
            if ($bookingCheckoutDomain === 'hotel'
                && (int)($latest['booking_id'] ?? 0) > 0) {
                $hotelReturn = $pdo->prepare('SELECT hotel_resort_id FROM hotel_room_bookings WHERE hotel_booking_id = ? LIMIT 1');
                $hotelReturn->execute([(int)$latest['booking_id']]);
                $hotelId = (int)$hotelReturn->fetchColumn();
                if ($hotelId > 0) {
                    $bookingCheckoutReturnPath = 'hotel_details.php?id=' . $hotelId;
                }
            }
        }
    } catch (Throwable $exception) {
        error_log('PayMongo return routing failed: ' . $exception->getMessage());
    }
}

if ($isAdminPayment) {
    $returnPage = $isHotelAdminPayment ? '/Hobookings.php?' : ($isOperatorPayment ? '/opbookings.php?' : '/adbookings.php?');
    $returnUrl = $returnBaseUrl . $returnPage . http_build_query([
        'payment_return' => 'paymongo',
        'payment_return_token' => $token,
    ], '', '&', PHP_QUERY_RFC3986);
    header('Location: ' . $returnUrl, true, 303);
    exit;
}

if ($bookingCheckoutStatus === 'paid'
    && in_array($bookingCheckoutDomain, ['hotel', 'package', 'boat', 'tourguide'], true)) {
    header(
        'Location: ' . $returnBaseUrl . '/booking_success.php?token=' . rawurlencode($token),
        true,
        303
    );
    exit;
}

if ($bookingCheckoutReturnPath !== '' && $bookingCheckoutStatus === 'paid') {
    $returnPath = bookingPaymentAppendQuery($bookingCheckoutReturnPath, [
        'booking_success' => '1',
        'booking_ref' => $bookingCheckoutReference,
    ]);
    header('Location: ' . $returnBaseUrl . '/' . ltrim($returnPath, '/'), true, 303);
    exit;
}

$params = ['section' => 'bookings', 'payment_return' => 'latest'];
if (preg_match('/^[a-f0-9]{64}$/', $token)) {
    $params['payment_return'] = 'token';
    $params['payment_return_token'] = $token;
}

$returnUrl = $returnBaseUrl . '/php/profile.php?'
    . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
header('Location: ' . $returnUrl, true, 303);
exit;
