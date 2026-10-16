<?php
declare(strict_types=1);

// Offline regression: in-memory database and a fake delivery function. No emails are sent.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../php/tourist_submission_alert_email.php';
require_once __DIR__ . '/../php/session_security.php';
require_once __DIR__ . '/../php/booking_tourists_helper.php';

function verifySubmissionAlert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$source = file_get_contents(__DIR__ . '/../admin/adbookings.php');
$start = strpos($source, 'function getBookingConfirmationRecord(');
$end = strpos($source, 'function sendBookingConfirmedEmail(', $start);
eval(substr($source, $start, $end - $start));
$start = strpos($source, '// Each deliberate reminder is allowed');
$end = strpos($source, '// Retry a confirmation email', $start);
$handler = substr($source, $start, $end - $start);
$handler = str_replace('sendTouristSubmissionAlertEmail($booking)', 'fakeSubmissionAlertSend($booking)', $handler);
$handler = str_replace('logActivity(', 'fakeSubmissionAlertLog(', $handler);
$handler = preg_replace('/header\(\x27Location:[^\n]+\n/', '', $handler);
$handler = str_replace('exit;', 'return;', $handler);

$deliveries = 0;
$deliveryWorks = true;
function fakeSubmissionAlertSend(array $booking): bool
{
    global $deliveries, $deliveryWorks;
    $deliveries++;
    return $deliveryWorks;
}
function fakeSubmissionAlertLog(...$arguments): void {}

$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('CREATE TABLE bookings (booking_id INTEGER, tourist_id INTEGER, boat_id INTEGER, guide_id INTEGER, status TEXT, is_complete TEXT, booking_type TEXT, booking_reference TEXT, booking_date TEXT, tour_type TEXT, tour_range TEXT, num_adults INTEGER, num_children INTEGER, location TEXT, jump_off_port TEXT)');
$pdo->exec('CREATE TABLE tourist (tourist_id INTEGER, full_name TEXT, email TEXT)');
$pdo->exec('CREATE TABLE boats (boat_id INTEGER, name TEXT)');
$pdo->exec('CREATE TABLE tour_guides (guide_id INTEGER, fullname TEXT)');
$pdo->exec('CREATE TABLE booking_tourists (booking_id INTEGER)');
$pdo->exec('CREATE TABLE booking_cancellation_requests (booking_domain TEXT, booking_id INTEGER, request_status TEXT)');
$pdo->exec("INSERT INTO tourist VALUES (7, 'Guest <Test>', 'guest@example.com')");
$pdo->exec("INSERT INTO boats VALUES (3, 'Boat & Crew')");
$pdo->exec("INSERT INTO bookings VALUES (42,7,3,NULL,'accepted','uncomplete','boat','TB26-0042','2026-10-29','overnight','',2,1,'Mercedes','Mercedes Port')");
foreach (['walkin_guest_name','walkin_guest_email'] as $column) $pdo->exec("ALTER TABLE bookings ADD COLUMN $column TEXT");
$_SESSION = [];
$token = AppCsrfToken('admin', 'booking_management');
$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST = ['action' => 'send_tourist_submission_alert', 'id' => 42, 'csrf_token' => $token, 'return_tab' => 'accepted'];
$run = static function () use ($pdo, $handler): void { eval($handler); };
$run();
$run();
verifySubmissionAlert($deliveries === 2 && $_SESSION['alert']['type'] === 'success', 'Repeat reminders must be allowed.');
$pdo->exec('INSERT INTO booking_tourists VALUES (42)');
$run();
verifySubmissionAlert($deliveries === 2 && str_contains($_SESSION['alert']['message'], 'already has a tourist submission'), 'A stale action must stop after submission.');
$pdo->exec('DELETE FROM booking_tourists');
$_POST['csrf_token'] = 'invalid';
$run();
verifySubmissionAlert($deliveries === 2 && $_SESSION['alert']['type'] === 'warning', 'Invalid CSRF must not send.');
$_POST['csrf_token'] = $token;
foreach (['pending', 'declined'] as $status) {
    $pdo->exec("UPDATE bookings SET status = '$status'");
    $run();
    verifySubmissionAlert($deliveries === 2, 'Non-accepted booking must not send.');
}
$pdo->exec("UPDATE bookings SET status = 'accepted', is_complete = 'completed'");
$run();
verifySubmissionAlert($deliveries === 2, 'Completed booking must not send.');
$pdo->exec("UPDATE bookings SET is_complete = 'uncomplete'");
$pdo->exec("INSERT INTO booking_cancellation_requests VALUES ('tour',42,'pending')");
$run();
verifySubmissionAlert($deliveries === 2, 'Cancellation workflow must not send.');
$pdo->exec('DELETE FROM booking_cancellation_requests');
$pdo->exec("UPDATE tourist SET email = 'invalid'");
$run();
verifySubmissionAlert($deliveries === 2, 'Invalid recipient must not send.');
$pdo->exec("UPDATE tourist SET email = 'guest@example.com'");
$deliveryWorks = false;
$run();
verifySubmissionAlert($_SESSION['alert']['type'] === 'warning', 'SMTP failure must not report success.');
$deliveryWorks = true;
$run();
verifySubmissionAlert($_SESSION['alert']['type'] === 'success', 'A failed attempt must remain retryable.');

$booking = getBookingConfirmationRecord($pdo, 42);
$html = BookingConfirmationEmailTemplate(touristSubmissionAlertEmailData($booking));
foreach (['ACTION REQUIRED', 'Please Submit Your Tourist List', 'Guest &lt;Test&gt;', 'Boat &amp; Crew', 'TB26-0042', 'October 30, 2026', 'Profile &gt; Bookings', 'Add Tourist', 'Submit Tourist Details', 'section=bookings&amp;submit_tourists=42', 'newlogo.png', 'textlogo2-white.png'] as $text) {
    verifySubmissionAlert(str_contains($html, $text), 'Missing email content: ' . $text);
}
verifySubmissionAlert(!str_contains($html, 'We are pleased to confirm'), 'Reminder must not read as a new confirmation.');
$default = BookingConfirmationEmailTemplate(['service_label' => 'Boat Booking']);
verifySubmissionAlert(str_contains($default, 'Boat Booking Confirmed') && str_contains($default, 'Official booking confirmation'), 'Existing confirmation email must retain its content.');

// Exercise the actual row renderer across tabs and after a submission arrives.
function getProfileImg($value): string { return 'img/profileicon.png'; }
$start = strpos($source, 'function renderBookings(');
$end = strpos($source, '?>', $start);
eval(substr($source, $start, $end - $start));
$_SESSION['cancellation_admin_csrf'] = 'test';
$render = static function (array $booking, string $tab): string {
    ob_start();
    renderBookings([$booking], $tab);
    return ob_get_clean();
};
foreach (['accepted', 'all'] as $tab) {
    $row = $render($booking, $tab);
    verifySubmissionAlert(str_contains($row, 'Send Tourist Submission Alert'), 'Missing action on ' . $tab);
    verifySubmissionAlert(str_contains($row, 'Mark Completed') && str_contains($row, 'Cancel Booking'), 'Existing actions must remain.');
}
$pdo->exec('INSERT INTO booking_tourists VALUES (42)');
foreach (['accepted', 'all'] as $tab) verifySubmissionAlert(!str_contains($render($booking, $tab), 'Send Tourist Submission Alert'), 'Action must disappear after submission on ' . $tab);
$pdo->exec('DELETE FROM booking_tourists');
$booking['status'] = 'pending';
verifySubmissionAlert(!str_contains($render($booking, 'pending'), 'Send Tourist Submission Alert'), 'Pending bookings must not offer the action.');
$booking['status'] = 'accepted';
$booking['is_complete'] = 'completed';
verifySubmissionAlert(!str_contains($render($booking, 'completed'), 'Send Tourist Submission Alert'), 'Completed bookings must not offer the action.');
$before = $deliveries;
$pdo->exec("UPDATE bookings SET tourist_id=NULL,status='accepted',is_complete='uncomplete',walkin_guest_name='Walk-in Guest',walkin_guest_email='guest@example.com' WHERE booking_id=42");
$run();
verifySubmissionAlert($deliveries === $before && $_SESSION['alert']['type'] === 'warning', 'A forged reminder POST for a no-account walk-in must never send an email.');
echo "PASS: repeat reminders, stale submission protection, CSRF, booking eligibility, cancellation, recipient, delivery failure/retry, branded email, exact booking link, existing confirmation content, and action visibility across tabs.\n";
