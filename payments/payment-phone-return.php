<?php
declare(strict_types=1);

require_once __DIR__ . '/../php/db_connection.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Referrer-Policy: no-referrer');

$token = strtolower(trim((string)($_GET['token'] ?? '')));
$result = strtolower(trim((string)($_GET['result'] ?? '')));
if (!preg_match('/^[a-f0-9]{64}$/', $token) || !in_array($result, ['success', 'cancelled'], true)) {
    http_response_code(400);
    exit('Invalid payment return link.');
}

$lookup = $pdo->prepare(
    'SELECT metadata, status, provider_checkout_session_id
     FROM payment_transactions WHERE return_token = ? LIMIT 1'
);
$lookup->execute([$token]);
$paymentTransaction = $lookup->fetch(PDO::FETCH_ASSOC);
$metadata = $paymentTransaction
    ? json_decode((string)($paymentTransaction['metadata'] ?? ''), true)
    : null;
if (!is_array($metadata)
    || !in_array((string)($metadata['source'] ?? ''), ['admin_booking_payment', 'hotel_checkin_payment', 'hotel_checkout_payment', 'operator_booking_payment'], true)) {
    http_response_code(404);
    exit('Payment transaction not found.');
}
$isOperatorPayment = ($metadata['staff_type'] ?? '') === 'operator';
$bookingsScreenLabel = $isOperatorPayment ? 'Operator Bookings screen' : 'Admin Bookings screen';

$statusQuery = $pdo->prepare(
    'SELECT status, booking_reference, amount_minor FROM payment_transactions WHERE return_token = ? LIMIT 1'
);
$statusQuery->execute([$token]);
$transaction = $statusQuery->fetch(PDO::FETCH_ASSOC);
if (!$transaction) {
    http_response_code(404);
    exit('Payment transaction not found.');
}

if (($_GET['format'] ?? '') === 'json') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => true,
        'status' => strtolower((string)$transaction['status']),
    ], JSON_UNESCAPED_SLASHES);
    exit;
}

$paymentStatus = strtolower((string)$transaction['status']);
$isPaid = $paymentStatus === 'paid';
$isCancelled = !$isPaid && ($paymentStatus === 'cancelled' || $result === 'cancelled');
$title = $isPaid ? 'Payment Verified' : ($isCancelled ? 'Payment Cancelled' : 'Payment Submitted');
$message = $isPaid
    ? 'The booking payment was verified and the ' . $bookingsScreenLabel . ' has been updated.'
    : ($isCancelled
        ? 'The QR payment was cancelled. No payment was applied to the booking.'
        : 'PayMongo accepted the payment. Verification is in progress and the ' . $bookingsScreenLabel . ' will update automatically.');
$reference = trim((string)$transaction['booking_reference']);
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?> - iTour Mercedes</title>
  <link rel="icon" type="image/png" href="../img/newlogo.png">
  <style>
    *{box-sizing:border-box}body{min-height:100vh;margin:0;display:grid;place-items:center;padding:20px;color:#23463c;background:linear-gradient(150deg,#e6f3ee,#f8fbfa);font-family:Arial,sans-serif}.result-card{width:min(430px,100%);padding:30px 25px;border:1px solid #cfe1da;border-radius:20px;background:#fff;box-shadow:0 22px 60px rgba(21,78,61,.18);text-align:center}.result-icon{width:64px;height:64px;display:grid;place-items:center;margin:0 auto 17px;border-radius:50%;color:#fff;background:#24755e;font-size:31px;font-weight:800}.result-card h1{margin:0 0 10px;color:#164d3d;font-size:24px}.result-card p{margin:0;color:#61776f;font-size:13px;line-height:1.6}.result-ref{margin:18px 0!important;padding:11px;border-radius:10px;color:#31594d!important;background:#edf6f2;font-weight:700}.result-card button{min-height:44px;padding:10px 22px;border:0;border-radius:10px;color:#fff;background:#24755e;font-weight:800;cursor:pointer}.result-note{display:block;margin-top:12px;color:#82938d;font-size:10px}
  </style>
</head>
<body>
  <main class="result-card">
    <div class="result-icon" id="paymentResultIcon"><?= $isCancelled ? '&times;' : '&#10003;' ?></div>
    <h1 id="paymentResultTitle"><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></h1>
    <p id="paymentResultMessage"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></p>
    <?php if ($reference !== ''): ?><p class="result-ref">Booking <?= htmlspecialchars($reference, ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
    <button type="button" id="closePaymentResult">Done</button>
    <small class="result-note">You can safely close this payment tab.</small>
  </main>
  <?php if (!$isPaid && !$isCancelled): ?>
  <script>
    const statusUrl = new URL(window.location.href);
    statusUrl.searchParams.set('format', 'json');
    const timer = window.setInterval(async () => {
      try {
        const response = await fetch(statusUrl.href, { cache: 'no-store', headers: { Accept: 'application/json' } });
        const data = await response.json();
        if (data.status === 'paid') {
          window.clearInterval(timer);
          document.getElementById('paymentResultTitle').textContent = 'Payment Verified';
          document.getElementById('paymentResultMessage').textContent = <?= json_encode('The booking payment was verified and the ' . $bookingsScreenLabel . ' has been updated.', JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        }
      } catch (_) {}
    }, 2000);
  </script>
  <?php endif; ?>
  <script>
    document.getElementById('closePaymentResult').addEventListener('click', () => {
      window.close();
      window.setTimeout(() => {
        const button = document.getElementById('closePaymentResult');
        button.textContent = 'You can close this tab';
        button.disabled = true;
      }, 150);
    });
  </script>
</body>
</html>
