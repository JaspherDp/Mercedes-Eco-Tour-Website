<?php
declare(strict_types=1);

chdir(__DIR__ . '/..');
require_once __DIR__ . '/../php/session_security.php';
AppSessionStart();
require_once 'php/db_connection.php';
require_once __DIR__ . '/../php/tourist_activity_helper.php';
TouristPreparePageActivity($pdo);

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Robots-Tag: noindex, nofollow', true);

function bookingSuccessSafeReturnPath(mixed $value): string
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

$token = strtolower(trim((string)($_GET['token'] ?? '')));
if ($token !== '') {
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
        header('Location: php/profile.php?section=bookings', true, 303);
        exit;
    }

    $statement = $pdo->prepare(
        "SELECT pt.tourist_id, pt.status, pt.booking_domain, pt.booking_reference,
                pt.metadata, t.email, hrb.hotel_resort_id
         FROM payment_transactions pt
         INNER JOIN tourist t ON t.tourist_id = pt.tourist_id
         LEFT JOIN hotel_room_bookings hrb
           ON pt.booking_domain = 'hotel' AND hrb.hotel_booking_id = pt.booking_id
         WHERE pt.return_token = ?
         LIMIT 1"
    );
    $statement->execute([$token]);
    $payment = $statement->fetch(PDO::FETCH_ASSOC) ?: [];
    $metadata = json_decode((string)($payment['metadata'] ?? ''), true);
    $sessionTouristId = (int)($_SESSION['tourist_id'] ?? 0);
    $paymentTouristId = (int)($payment['tourist_id'] ?? 0);
    $bookingDomain = strtolower((string)($payment['booking_domain'] ?? ''));

    $isValidPayment = $paymentTouristId > 0
        && strtolower((string)($payment['status'] ?? '')) === 'paid'
        && in_array($bookingDomain, ['hotel', 'package', 'boat', 'tourguide'], true)
        && is_array($metadata)
        && ($metadata['source'] ?? '') === 'booking_checkout'
        && ($sessionTouristId === 0 || $sessionTouristId === $paymentTouristId);

    if (!$isValidPayment) {
        header('Location: php/profile.php?section=bookings', true, 303);
        exit;
    }

    $backUrl = bookingSuccessSafeReturnPath($metadata['return_path'] ?? '');
    if ($bookingDomain === 'hotel' && (int)($payment['hotel_resort_id'] ?? 0) > 0) {
        $backUrl = 'hotel_details.php?id=' . (int)$payment['hotel_resort_id'];
    }
    if ($backUrl === '') {
        $backUrl = $bookingDomain === 'hotel' ? 'hotel_resorts.php' : 'hotel_resorts.php?tab=tours';
    }

    $_SESSION['hotel_booking_success'] = [
        'tourist_id' => $paymentTouristId,
        'email' => trim((string)($payment['email'] ?? '')),
        'booking_domain' => $bookingDomain,
        'back_url' => $backUrl,
        'booking_reference' => preg_replace('/[^A-Z0-9-]/i', '', (string)($payment['booking_reference'] ?? '')),
        'created_at' => time(),
    ];

    header('Location: booking_success.php', true, 303);
    exit;
}

$success = $_SESSION['hotel_booking_success'] ?? null;
if (!is_array($success)
    || (int)($success['created_at'] ?? 0) < time() - 1800
    || !in_array((string)($success['booking_domain'] ?? ''), ['hotel', 'package', 'boat', 'tourguide'], true)
    || bookingSuccessSafeReturnPath($success['back_url'] ?? '') === '') {
    unset($_SESSION['hotel_booking_success']);
    header('Location: php/profile.php?section=bookings', true, 303);
    exit;
}

if (($_GET['format'] ?? '') === 'receipt') {
    header('Content-Type: application/json; charset=utf-8');
    header('Referrer-Policy: no-referrer');
    $currentTouristId = (int)($_SESSION['tourist_id'] ?? 0);
    if ($currentTouristId > 0 && $currentTouristId !== (int)$success['tourist_id']) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'This receipt is not available in this session.']);
        exit;
    }
    try {
        require_once __DIR__ . '/../php/booking_success_receipt.php';
        echo json_encode(['success' => true, 'receipt' => BookingSuccessReceipt($pdo, $success)], JSON_INVALID_UTF8_SUBSTITUTE);
    } catch (Throwable $error) {
        error_log('Success receipt unavailable: ' . $error->getMessage());
        http_response_code(503);
        echo json_encode(['success' => false, 'message' => 'Your receipt could not be loaded. Please try again or view your booking.']);
    }
    exit;
}

$email = trim((string)($success['email'] ?? ''));
$bookingDomain = (string)$success['booking_domain'];
$bookingReference = trim((string)($success['booking_reference'] ?? ''));
$backUrl = bookingSuccessSafeReturnPath($success['back_url']);
$bookingsUrl = 'php/profile.php?section=bookings';
$paymentLabels = [
    'hotel' => 'Accommodation payment confirmed',
    'package' => 'Tour package payment confirmed',
    'boat' => 'Boat booking payment confirmed',
    'tourguide' => 'Tour guide payment confirmed',
];
$paymentLabel = $paymentLabels[$bookingDomain] ?? 'Reservation payment confirmed';
?>
<!doctype html>
<html lang="en">
<head>
<script src="js/page-navigation-progress.js?v=3"></script>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="referrer" content="no-referrer">
  <title>Payment Successful | iTour Mercedes</title>
  <link rel="stylesheet" href="styles/admin_receipt.css?v=4">
  <link rel="icon" type="image/png" href="img/newlogo.png">
  <link rel="stylesheet" href="styles/hotel_booking_success.css?v=<?= (int)@filemtime(__DIR__ . '/../styles/hotel_booking_success.css') ?>">
  <?php TouristRenderActivityMonitor(); ?>
</head>
<body>
  <header class="success-header">
    <a href="./" class="success-brand" aria-label="iTour Mercedes home">
      <img src="img/newlogo.png" alt="">
      <img src="img/textlogo2.png" alt="iTour Mercedes">
    </a>
    <span class="secure-payment-label">
      <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7 10V8a5 5 0 0 1 10 0v2M6 10h12v10H6z"/></svg>
      Secure payment confirmed
    </span>
  </header>

  <main class="success-page">
    <section class="success-card" aria-labelledby="successTitle">
      <div class="success-hero">
        <div class="success-mark" aria-hidden="true">
          <svg viewBox="0 0 64 64"><circle cx="32" cy="32" r="27"/><path d="m20 32 8 8 17-18"/></svg>
        </div>
        <p class="success-eyebrow"><?= htmlspecialchars($paymentLabel, ENT_QUOTES, 'UTF-8') ?></p>
        <h1 id="successTitle">Payment Successful</h1>
        <p>Your booking has been submitted successfully. Please wait for the confirmation email sent to the address below.</p>
      </div>

      <div class="success-details">
        <div class="email-icon" aria-hidden="true">
          <svg viewBox="0 0 24 24"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="m4 7 8 6 8-6"/></svg>
        </div>
        <div>
          <span>Confirmation email will be sent to</span>
          <strong><?= htmlspecialchars($email !== '' ? $email : 'your registered email', ENT_QUOTES, 'UTF-8') ?></strong>
        </div>
      </div>

      <?php if ($bookingReference !== ''): ?>
        <p class="booking-reference">Booking reference <strong><?= htmlspecialchars($bookingReference, ENT_QUOTES, 'UTF-8') ?></strong></p>
      <?php endif; ?>

      <div class="success-actions">
        <a class="success-button success-button-secondary" href="<?= htmlspecialchars($backUrl, ENT_QUOTES, 'UTF-8') ?>">
          <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m15 18-6-6 6-6"/></svg>
          <span>Go Back</span>
        </a>
        <button type="button" class="success-button success-button-secondary" id="successReceipt">
          <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 3h12v18l-3-2-3 2-3-2-3 2V3Z M9 7h6M9 11h6M9 15h4"/></svg>
          <span>Receipt</span>
        </button>
        <a class="success-button success-button-primary" href="<?= htmlspecialchars($bookingsUrl, ENT_QUOTES, 'UTF-8') ?>">
          <span>View Bookings</span>
          <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m9 18 6-6-6-6"/></svg>
        </a>
      </div>

      <p id="successReceiptError" class="success-note" role="alert" hidden></p>
      <p class="success-note">Please allow a short time for the service provider to review your reservation.</p>
    </section>
  </main>
<script src="js/booking-payment-summary.js?v=2"></script>
<script src="js/booking-success-receipt.js?v=1"></script>
<script src="js/mobile-scroll.js"></script>
</body>
</html>
