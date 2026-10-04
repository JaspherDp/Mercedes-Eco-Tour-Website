<?php
declare(strict_types=1);

require_once __DIR__ . '/paymongo-config.php';
require_once __DIR__ . '/../php/db_connection.php';
require_once __DIR__ . '/PayMongoService.php';
require_once __DIR__ . '/PaymentReconciler.php';
require_once __DIR__ . '/PaymentReturnStatus.php';
require_once __DIR__ . '/../php/app_url_helper.php';
require_once __DIR__ . '/../php/session_security.php';
require_once __DIR__ . '/../php/tourist_auth_helper.php';

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
    $transaction = PaymentReturnStatus::resolve($pdo, $token);
} catch (Throwable $exception) {
    error_log('PayMongo return status unavailable: ' . get_class($exception));
    http_response_code(503);
    exit('Payment verification is temporarily unavailable. Please check your booking before paying again.');
}
if (($_GET['format'] ?? '') === 'json') {
    header('Content-Type: application/json; charset=utf-8');
    header('Referrer-Policy: no-referrer');
    if (!$transaction) http_response_code(404);
    echo json_encode(['success' => (bool)$transaction, 'status' => $transaction['status'] ?? 'unknown']);
    exit;
}
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
$isTouristBalancePayment = false;
$bookingCheckoutReturnPath = '';
$bookingCheckoutStatus = '';
$bookingCheckoutReference = '';
$bookingCheckoutDomain = '';
if (preg_match('/^[a-f0-9]{64}$/', $token)) {
    try {
        $metadata = json_decode((string)($transaction['metadata'] ?? ''), true);
        $isAdminPayment = is_array($metadata)
            && in_array((string)($metadata['source'] ?? ''), ['admin_booking_payment', 'hotel_checkin_payment', 'hotel_checkout_payment', 'operator_booking_payment'], true);
        $isHotelAdminPayment = $isAdminPayment && ($metadata['staff_type'] ?? '') === 'hotel_admin';
        $isOperatorPayment = $isAdminPayment && ($metadata['staff_type'] ?? '') === 'operator';
        $isTouristBalancePayment = is_array($metadata) && ($metadata['source'] ?? '') === 'tourist_profile_balance';
        $isBookingCheckout = is_array($metadata) && ($metadata['source'] ?? '') === 'booking_checkout';

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
    $params['payment_return'] = !empty($paymentReturnCancelled) ? 'cancelled' : 'token';
    $params['payment_return_token'] = $token;
}

$returnUrl = $returnBaseUrl . '/php/profile.php?'
    . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
if ($isTouristBalancePayment) {
    AppSessionStart();
    $returningTourist = TouristValidateSession($pdo);
    if (!is_array($returningTourist)
        || (int)$returningTourist['tourist_id'] !== (int)($transaction['tourist_id'] ?? 0)) {
        // Mobile hosted checkouts can return in a different browser container.
        // Show the verified ledger result before asking for a profile login.
        $paymentStatus = strtolower((string)($transaction['status'] ?? 'pending'));
        $verified = $paymentStatus === 'paid';
        $terminal = in_array($paymentStatus, ['failed', 'expired', 'cancelled'], true);
        $returnTitle = $verified ? 'Payment Verified' : ($terminal ? 'Payment Not Completed' : 'Payment Verification Pending');
        $returnMessage = $verified
            ? 'Your balance payment was verified. Sign in to view your updated booking.'
            : ($terminal
                ? 'This payment was not completed. Sign in to review your booking before trying again.'
                : 'We are still verifying this payment. Sign in to check your booking before paying again.');
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store, max-age=0');
        header('Referrer-Policy: no-referrer');
        ?>
<!doctype html>
<html lang="en">
<head>
<script src="../js/page-navigation-progress.js?v=1"></script>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= htmlspecialchars($returnTitle, ENT_QUOTES, 'UTF-8') ?> - iTour Mercedes</title>
  <style>body{min-height:100vh;display:grid;place-items:center;margin:0;padding:20px;background:#edf6f1;color:#173d32;font-family:Arial,sans-serif}.card{width:min(420px,100%);box-sizing:border-box;padding:30px;border-radius:18px;background:#fff;box-shadow:0 18px 50px #173d3220;text-align:center}h1{font-size:24px}p{line-height:1.55;color:#536b62}a{display:inline-block;margin-top:12px;padding:12px 20px;border-radius:9px;background:#176b55;color:#fff;text-decoration:none;font-weight:700}</style>
</head>
<body><main class="card"><h1><?= htmlspecialchars($returnTitle, ENT_QUOTES, 'UTF-8') ?></h1><p><?= htmlspecialchars($returnMessage, ENT_QUOTES, 'UTF-8') ?></p><a href="<?= htmlspecialchars($returnUrl, ENT_QUOTES, 'UTF-8') ?>">Sign in to view booking</a></main></body>
</html>
        <?php
        exit;
    }
}
header('Location: ' . $returnUrl, true, 303);
exit;
