<?php
chdir(__DIR__ . '/..');
require_once __DIR__ . '/../php/session_security.php';
AppSessionStart();
require 'php/db_connection.php';
require_once __DIR__ . '/../php/activity_logger.php';
require_once __DIR__ . '/../php/booking_reference_helper.php';
require_once __DIR__ . '/../php/firebase_config.php';
require_once __DIR__ . '/../php/admin_auth_helper.php';
AdminRequireLogin();
require_once __DIR__ . '/../php/booking_cancellations_helper.php';
require_once __DIR__ . '/../php/tour_resource_availability_helper.php';
require_once __DIR__ . '/../php/hotel_rooms_helper.php';
require_once __DIR__ . '/../php/input_validation.php';
require_once __DIR__ . '/../payments/PayMongoService.php';
require_once __DIR__ . '/../payments/PaymentReconciler.php';
require_once __DIR__ . '/../php/app_url_helper.php';
BookingReferenceEnsureSchema($pdo);
$adminFirebasePublicConfiguration = firebase_public_configuration();

// ✅ Include SweetAlert2 alert system
include 'php/alert.php';

require_once __DIR__ . '/../php/PHPMailer/src/Exception.php';
require_once __DIR__ . '/../php/PHPMailer/src/PHPMailer.php';
require_once __DIR__ . '/../php/PHPMailer/src/SMTP.php';
require_once __DIR__ . '/../php/booking_confirmation_email.php';
require_once __DIR__ . '/../php/cancellation_approval_email.php';
require_once __DIR__ . '/../php/provider_cancellation_email.php';
BookingConfirmationEnsureTourEmailTracking($pdo);

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

function getBookingConfirmationRecord(PDO $pdo, int $bookingId): ?array {
    $stmt = $pdo->prepare("
        SELECT
            b.*,
            t.full_name,
            t.email,
            bt.name AS boat_name,
            tg.fullname AS guide_name
        FROM bookings b
        LEFT JOIN tourist t ON b.tourist_id = t.tourist_id
        LEFT JOIN boats bt ON bt.boat_id = b.boat_id
        LEFT JOIN tour_guides tg ON tg.guide_id = b.guide_id
        WHERE b.booking_id = ?
        LIMIT 1
    ");
    $stmt->execute([$bookingId]);
    $booking = $stmt->fetch(PDO::FETCH_ASSOC);
    return $booking ?: null;
}

function sendBookingConfirmedEmail($email, $name, $booking) {
    $mail = new PHPMailer(true);

    try {

        // =========================
        // SMTP DEBUG (LIKE HOTEL SYSTEM)
        // =========================
        $mail->SMTPDebug = 0;
        $mail->Debugoutput = 'error_log';

        // =========================
        // SMTP CONFIG
        // =========================
        $mail->isSMTP();
        $mail->Host = 'smtp.gmail.com';
        $mail->SMTPAuth = true;
        $mail->Username   = 'itourmercedes@gmail.com';
        $mail->Password   = PaymentHelper::env('SMTP_PASSWORD');
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = 587;
        $mail->Timeout = 45;
        $mail->Timelimit = 60;

        $mail->SMTPOptions = [
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true,
            ],
        ];

        // =========================
        // SENDER
        // =========================
        $mail->CharSet = 'UTF-8';
        $mail->setFrom('itourmercedes@gmail.com', 'iTour Mercedes');
        $mail->addReplyTo('itourmercedes@gmail.com', 'iTour Mercedes');
        $mail->addAddress($email, $name);

        // =========================
        // EMAIL CONTENT
        // =========================
        $mail->isHTML(true);
        $mail->Subject = 'iTour Mercedes | ' . getBookingEmailServiceLabel($booking)
            . ' Confirmed (' . BookingReferenceDisplay($booking) . ')';
        $mail->Body = getProfessionalBookingEmailTemplate(
            $name,
            $booking,
            BookingConfirmationEmailBranding($mail)
        );
        $mail->AltBody = 'Your iTour Mercedes booking ' . BookingReferenceDisplay($booking)
            . ' has been confirmed. Please review the booking details in this email.';

        // =========================
        // SEND
        // =========================
        if (BookingConfirmationEmailSend($mail)) {
            return true;
        }

        error_log("EMAIL FAILED TOUR BOOKING: " . $mail->ErrorInfo);
        return false;

    } catch (Exception $e) {
        error_log("PHPMailer ERROR: " . $e->getMessage());
        return false;
    }
}

function getBookingEmailServiceLabel(array $booking): string {
    return match (strtolower((string)($booking['booking_type'] ?? ''))) {
        'package' => 'Tour Package',
        'boat' => 'Boat Booking',
        'tourguide' => 'Tour Guide Booking',
        default => 'Tour Booking',
    };
}

function cancellationTrackingUrl(): string
{
    $baseUrl = ItourTryCanonicalAppUrl('cancellation tracking email link');
    return $baseUrl === '' ? '' : $baseUrl . '/php/profile.php?section=cancel-bookings';
}

function sendCancellationApprovedEmail(string $email, string $name, array $cancellation): bool
{
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return false;
    $mail = new PHPMailer(true);
    try {
        $mail->SMTPDebug = 0;
        $mail->Debugoutput = 'error_log';
        $mail->isSMTP();
        $mail->Host = 'smtp.gmail.com';
        $mail->SMTPAuth = true;
        $mail->Username = 'itourmercedes@gmail.com';
        $mail->Password = PaymentHelper::env('SMTP_PASSWORD');
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = 587;
        $mail->Timeout = 45;
        $mail->Timelimit = 60;
        $mail->SMTPOptions = ['ssl' => ['verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true]];
        $mail->CharSet = 'UTF-8';
        $mail->setFrom('itourmercedes@gmail.com', 'iTour Mercedes');
        $mail->addReplyTo('itourmercedes@gmail.com', 'iTour Mercedes');
        $mail->addAddress($email, $name);
        $mail->isHTML(true);
        $mail->Subject = 'iTour Mercedes | Cancellation Approved (' . (string)$cancellation['booking_reference'] . ')';
        $trackingUrl = cancellationTrackingUrl();
        $emailBranding = CancellationApprovalEmailBranding($mail);
        $mail->Body = CancellationApprovalEmailTemplate(array_merge(
            $cancellation,
            [
                'guest_name' => $name,
                'policy_label' => bookingCancellationPolicyLabel((string)$cancellation['refund_policy']),
                'tracking_url' => $trackingUrl,
            ],
            $emailBranding
        ));
        $refundAmount = BookingConfirmationEmailMoney($cancellation['refundable_amount'] ?? 0);
        $mail->AltBody = 'Your booking cancellation for ' . (string)$cancellation['booking_reference']
            . ' has been approved. ' . ((float)$cancellation['refundable_amount'] > 0
                ? 'Your eligible refund is ' . $refundAmount . ' and is now being processed.'
                : 'This cancellation is not eligible for a monetary refund.')
            . ' Track progress at ' . $trackingUrl;
        return BookingConfirmationEmailSend($mail);
    } catch (Throwable $error) {
        error_log('Cancellation approval email failed: ' . $error->getMessage());
        return false;
    }
}

function updateBookingConfirmationEmailStatus(PDO $pdo, int $bookingId, bool $sent, string $error = ''): void {
    $stmt = $pdo->prepare("
        UPDATE bookings
        SET confirmation_email_status = ?,
            confirmation_email_sent_at = CASE WHEN ? = 1 THEN NOW() ELSE confirmation_email_sent_at END,
            confirmation_email_last_attempt_at = NOW(),
            confirmation_email_error = ?
        WHERE booking_id = ?
    ");
    $stmt->execute([
        $sent ? 'sent' : 'failed',
        $sent ? 1 : 0,
        $sent ? null : mb_substr($error !== '' ? $error : 'SMTP delivery failed.', 0, 500),
        $bookingId,
    ]);
}

function getProfessionalBookingEmailTemplate($name, array $b, array $branding = []): string {
    $type = strtolower((string)($b['booking_type'] ?? ''));
    $serviceLabel = getBookingEmailServiceLabel($b);
    $serviceName = match ($type) {
        'package' => (string)($b['package_name'] ?? ''),
        'boat' => (string)($b['boat_name'] ?? $b['preferred_resource'] ?? ''),
        'tourguide' => (string)($b['guide_name'] ?? $b['preferred_resource'] ?? ''),
        default => (string)($b['package_name'] ?? $b['preferred_resource'] ?? ''),
    };
    $adults = max(0, (int)($b['num_adults'] ?? 0));
    $children = max(0, (int)($b['num_children'] ?? 0));
    $guestSummary = $adults . ' adult' . ($adults === 1 ? '' : 's')
        . ' / ' . $children . ' child' . ($children === 1 ? '' : 'ren');
    $remaining = max(0, (float)($b['remaining_balance'] ?? 0));
    $tourType = trim((string)($b['tour_type'] ?? ''));
    $importantNotes = [
        'package' => 'Please arrive at the designated jump-off point at least 15 minutes early and bring this confirmation. Package activities remain subject to weather and local safety advisories.',
        'boat' => 'Please arrive at the jump-off port at least 15 minutes early. Follow the boat crew’s safety briefing and bring appropriate sun and water protection.',
        'tourguide' => 'Please arrive at the meeting point at least 15 minutes early and keep your contact phone available for coordination with the assigned guide.',
    ];

    return BookingConfirmationEmailTemplate(array_merge([
        'guest_name' => $name,
        'service_label' => $serviceLabel,
        'booking_reference' => BookingReferenceDisplay($b),
        'intro' => 'Your ' . strtolower($serviceLabel) . ' has been reviewed and confirmed by the iTour Mercedes team.',
        'details' => [
            'Booking type' => $serviceLabel,
            'Service / assignment' => $serviceName,
            'Destination(s)' => (string)($b['location'] ?? ''),
            'Tour date' => BookingConfirmationEmailDate($b['booking_date'] ?? ''),
            'Tour arrangement' => $tourType !== '' ? ucwords(str_replace(['-', '_'], ' ', $tourType)) : '',
            'Tour range / duration' => (string)($b['tour_range'] ?? ''),
            'Jump-off point' => (string)($b['jump_off_port'] ?? ''),
            'Guests' => $guestSummary,
            'Contact number' => (string)($b['phone_number'] ?? ''),
        ],
        'payment_details' => [
            'Booking total' => BookingConfirmationEmailMoney($b['grand_total'] ?? 0),
            'Amount paid' => BookingConfirmationEmailMoney($b['payment_amount'] ?? 0),
            'Remaining balance' => BookingConfirmationEmailMoney($remaining),
            'Payment status' => $remaining <= 0 ? 'Fully paid' : 'Partial payment',
            'Payment method' => ucwords(str_replace(['_', '-'], ' ', (string)($b['payment_method'] ?? 'Online payment'))),
        ],
        'important_note' => $importantNotes[$type]
            ?? 'Please arrive at least 15 minutes before your scheduled tour and keep this confirmation available.',
    ], $branding));
}

function getBookingEmailTemplate($name, $b) {

    $bookingId = htmlspecialchars(BookingReferenceDisplay($b));
    $type = htmlspecialchars($b['booking_type']);
    $location = htmlspecialchars($b['location'] ?? $b['package_name']);
    $date = htmlspecialchars($b['booking_date']);
    $adults = (int)$b['num_adults'];
    $children = (int)$b['num_children'];

    return "
    <!DOCTYPE html>
    <html>
    <head>
    <meta charset='UTF-8'>
    <style>
        body {
            font-family: Arial;
            background:#f4f6f8;
            margin:0;
            padding:0;
        }

        .container {
            max-width:600px;
            margin:30px auto;
            background:#fff;
            border-radius:10px;
            overflow:hidden;
            box-shadow:0 2px 10px rgba(0,0,0,0.08);
        }

        .header {
            background:#1e3a8a;
            color:#fff;
            text-align:center;
            padding:22px;
        }

        .header h1 {
            margin:0;
            font-size:20px;
        }

        .content {
            padding:25px;
        }

        .status {
            background:#d1fae5;
            color:#065f46;
            padding:6px 12px;
            display:inline-block;
            border-radius:20px;
            font-weight:bold;
            font-size:13px;
            margin-bottom:15px;
        }

        table {
            width:100%;
            margin-top:15px;
            border-collapse:collapse;
        }

        td {
            padding:10px;
            border-bottom:1px solid #eee;
            font-size:14px;
        }

        td:first-child {
            font-weight:bold;
            width:40%;
            color:#333;
        }

        .footer {
            text-align:center;
            padding:15px;
            font-size:12px;
            color:#777;
            background:#f0f0f0;
        }

        .note {
            margin-top:20px;
            padding:15px;
            background:#e8f5e9;
            border-left:5px solid #2e7d32;
            font-size:13px;
        }

    </style>
    </head>

    <body>

    <div class='container'>

        <div class='header'>
            <h1>iTour Mercedes - Booking Confirmation</h1>
        </div>

        <div class='content'>

            <div class='status'>CONFIRMED</div>

            <p>Dear <b>{$name}</b>,</p>

            <p>Your tour booking has been successfully confirmed. Below are your details:</p>

            <table>
                <tr><td>Booking Reference</td><td>{$bookingId}</td></tr>
                <tr><td>Booking Type</td><td>{$type}</td></tr>
                <tr><td>Location / Package</td><td>{$location}</td></tr>
                <tr><td>Tour Date</td><td>{$date}</td></tr>
                <tr><td>Adults</td><td>{$adults}</td></tr>
                <tr><td>Children</td><td>{$children}</td></tr>
            </table>

            <div class='note'>
                <b>Important:</b> Please arrive at least 15 minutes before your scheduled tour time.
            </div>

            <p style='margin-top:20px;'>
                Thank you for choosing <b>Mercedes Eco Tour</b>. We look forward to serving you.
            </p>

        </div>

        <div class='footer'>
            © " . date('Y') . " Mercedes Eco Tour. All rights reserved.
        </div>

    </div>

    </body>
    </html>
    ";
}
// ✅ Prevent cached pages
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

if (empty($_SESSION['walkin_booking_csrf'])) {
    $_SESSION['walkin_booking_csrf'] = bin2hex(random_bytes(32));
}
if (empty($_SESSION['paymongo_admin_csrf'])) {
    $_SESSION['paymongo_admin_csrf'] = bin2hex(random_bytes(32));
}
if (empty($_SESSION['cancellation_admin_csrf'])) {
    $_SESSION['cancellation_admin_csrf'] = bin2hex(random_bytes(32));
}
ensureBookingCancellationRequestsTable($pdo);
HoEnsureHotelBookingsTable($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'admin_provider_cancel') {
    $returnTab = in_array((string)($_POST['return_tab'] ?? ''), ['all', 'accepted'], true) ? (string)$_POST['return_tab'] : 'accepted';
    try {
        if (!hash_equals((string)$_SESSION['cancellation_admin_csrf'], (string)($_POST['csrf_token'] ?? ''))) {
            throw new RuntimeException('Your session expired. Refresh the page and try again.');
        }
        $bookingId = (int)($_POST['id'] ?? 0);
        $workflow = strtolower(trim((string)($_POST['provider_cancellation_workflow'] ?? '')));
        if ($bookingId < 1 || !in_array($workflow, ['offer_reschedule', 'full_refund'], true)) {
            throw new RuntimeException('Choose a valid cancellation option.');
        }
        $reason = bookingProviderCancellationReason((string)($_POST['reason_category'] ?? ''), (string)($_POST['reason_other'] ?? ''));
        $adminId = (int)($_SESSION['admin_id'] ?? 0);

        $pdo->beginTransaction();
        $bookingStatement = $pdo->prepare("SELECT b.*, t.full_name, t.email FROM bookings b LEFT JOIN tourist t ON t.tourist_id=b.tourist_id WHERE b.booking_id=? LIMIT 1 FOR UPDATE");
        $bookingStatement->execute([$bookingId]);
        $booking = $bookingStatement->fetch(PDO::FETCH_ASSOC);
        if (!$booking || strtolower((string)$booking['status']) !== 'accepted' || strtolower((string)$booking['is_complete']) !== 'uncomplete') {
            throw new RuntimeException('Only an active accepted booking can use this workflow.');
        }

        $existing = $pdo->prepare("SELECT cancellation_request_id, request_status FROM booking_cancellation_requests WHERE booking_domain='tour' AND booking_id=? LIMIT 1 FOR UPDATE");
        $existing->execute([$bookingId]);
        $existingRow = $existing->fetch(PDO::FETCH_ASSOC);
        if ($existingRow && in_array(strtolower((string)$existingRow['request_status']), ['pending','approved','decision_required'], true)) {
            throw new RuntimeException('A cancellation or rescheduling workflow already exists for this booking.');
        }

        $bookingType = strtolower((string)($booking['booking_type'] ?? 'tour')) ?: 'tour';
        $serviceName = in_array($bookingType, ['boat','tourguide','guide'], true)
            ? (trim((string)($booking['location'] ?? '')) ?: ucfirst($bookingType) . ' booking')
            : (trim((string)($booking['package_name'] ?? '')) ?: 'Tour package');
        $reference = (string)($booking['booking_reference'] ?: ('BOOKING-' . $bookingId));
        $amountPaid = max(0, round((float)($booking['payment_amount'] ?? 0), 2));
        $totalAmount = max((float)($booking['grand_total'] ?? 0), $amountPaid + (float)($booking['remaining_balance'] ?? 0));
        $serviceDate = (string)$booking['booking_date'];
        $days = bookingCancellationRefundPolicy($serviceDate, $totalAmount, $amountPaid)['days_before_service'];
        $refund = bookingProviderFullRefund($amountPaid);
        $deadline = (new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))->modify('+2 days')->format('Y-m-d H:i:s');

        $save = $pdo->prepare("INSERT INTO booking_cancellation_requests
            (tourist_id, booking_domain, booking_id, initiated_by, booking_reference, booking_type, service_name,
             service_date, original_service_date, total_amount, amount_paid, days_before_service, refund_policy,
             refundable_amount, non_refundable_amount, request_status, refund_status, cancellation_reason,
             reschedule_offered, reschedule_offered_at, decision_deadline, requested_at, reviewed_by_admin_id, reviewed_at)
            VALUES (?, 'tour', ?, 'admin_provider', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 'decision_required', 'not_started', ?, ?, NOW(), ?, NOW(), ?, NULL)
            ON DUPLICATE KEY UPDATE tourist_id=VALUES(tourist_id), initiated_by='admin_provider', booking_reference=VALUES(booking_reference),
             booking_type=VALUES(booking_type), service_name=VALUES(service_name), service_date=VALUES(service_date),
             original_service_date=VALUES(original_service_date), rescheduled_service_date=NULL, total_amount=VALUES(total_amount),
             amount_paid=VALUES(amount_paid), days_before_service=VALUES(days_before_service), refund_policy=VALUES(refund_policy),
             refundable_amount=VALUES(refundable_amount), non_refundable_amount=0, request_status='decision_required', refund_status='not_started',
             cancellation_reason=VALUES(cancellation_reason), admin_note=NULL, reschedule_offered=VALUES(reschedule_offered),
             reschedule_offered_at=NOW(), decision_deadline=VALUES(decision_deadline), tourist_decision=NULL,
             tourist_responded_at=NULL, decision_expired_at=NULL, refund_request_created_at=NULL, requested_at=NOW(),
             provider_email_sent_count=0, provider_email_last_sent_at=NULL,
             reviewed_by_admin_id=VALUES(reviewed_by_admin_id), reviewed_at=NULL, completed_at=NULL, completed_by_admin_id=NULL");
        $save->execute([
            (int)$booking['tourist_id'], $bookingId, $reference, $bookingType, $serviceName, $serviceDate, $serviceDate,
            $totalAmount, $amountPaid, $days, $refund['refund_policy'], $refund['refundable_amount'], $reason,
            $workflow === 'offer_reschedule' ? 1 : 0, $deadline, $adminId ?: null,
        ]);
        $requestId = (int)$pdo->lastInsertId();
        if ($requestId < 1 && $existingRow) $requestId = (int)$existingRow['cancellation_request_id'];

        if ($workflow === 'full_refund') {
            bookingCancellationFinalizeProviderRefund($pdo, $requestId, 'admin_full_refund');
        }
        $pdo->commit();

        $requestStatement = $pdo->prepare('SELECT * FROM booking_cancellation_requests WHERE cancellation_request_id=?');
        $requestStatement->execute([$requestId]);
        $request = $requestStatement->fetch(PDO::FETCH_ASSOC) ?: [];
        $tourist = ['full_name' => $booking['full_name'], 'email' => $booking['email']];
        $emailSent = $workflow === 'offer_reschedule' ? sendRescheduleOfferEmail($request, $tourist) : sendProviderCancellationEmail($request, $tourist);
        if ($emailSent) {
            $emailCountUpdate = $pdo->prepare('UPDATE booking_cancellation_requests SET provider_email_sent_count=provider_email_sent_count+1, provider_email_last_sent_at=NOW() WHERE cancellation_request_id=?');
            $emailCountUpdate->execute([$requestId]);
        }
        logActivity($pdo, 'Admin', $adminId, (string)($_SESSION['admin_name'] ?? 'Administrator'),
            $workflow === 'offer_reschedule' ? 'Reschedule Offered' : 'Provider Cancellation',
            ($workflow === 'offer_reschedule' ? 'Offered free rescheduling for ' : 'Cancelled with full refund for ') . $reference . '. Reason: ' . $reason,
            'Bookings', $bookingId);
        $_SESSION['alert'] = [
            'type' => $emailSent ? 'success' : 'warning',
            'title' => $emailSent
                ? ($workflow === 'offer_reschedule' ? 'Tourist Notified Successfully' : 'Booking Cancelled')
                : 'Notice Email Not Sent',
            'message' => $workflow === 'offer_reschedule'
                ? ($emailSent
                    ? 'The cancellation notice and reschedule offer were delivered successfully. The booking is awaiting the tourist’s decision until ' . htmlspecialchars(date('M j, Y g:i A', strtotime($deadline))) . '.'
                    : 'The reschedule workflow was saved, but the cancellation notice email could not be delivered. Use “Resend Tourist Email” from the cancellation request actions.')
                : 'The booking was cancelled and a full-refund record for the amount paid was created.' . ($emailSent ? ' The tourist was notified successfully.' : ' The email could not be delivered; use “Resend Tourist Email” from the cancellation request actions.'),
        ];
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $_SESSION['alert'] = ['type' => 'error', 'title' => 'Cancellation Failed', 'message' => htmlspecialchars($error->getMessage())];
    }
    header('Location: adbookings.php?tab=' . rawurlencode($returnTab));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'retry_provider_cancellation_email') {
    try {
        if (!hash_equals((string)$_SESSION['cancellation_admin_csrf'], (string)($_POST['csrf_token'] ?? ''))) {
            throw new RuntimeException('Your session expired. Refresh the page and try again.');
        }
        $requestId = (int)($_POST['cancellation_request_id'] ?? 0);
        $statement = $pdo->prepare("SELECT cr.*, t.full_name, t.email FROM booking_cancellation_requests cr
            JOIN tourist t ON t.tourist_id=cr.tourist_id
            WHERE cr.cancellation_request_id=? AND cr.initiated_by='admin_provider' LIMIT 1");
        $statement->execute([$requestId]);
        $request = $statement->fetch(PDO::FETCH_ASSOC);
        if (!$request) throw new RuntimeException('Provider cancellation record not found.');
        $status = strtolower((string)$request['request_status']);
        if (!in_array($status, ['decision_required', 'approved', 'rescheduled'], true)) {
            throw new RuntimeException('This notification is not available for the current cancellation status.');
        }
        if ($status === 'decision_required') {
            $sent = sendRescheduleOfferEmail($request, $request);
            $emailLabel = 'rescheduling decision notice';
        } elseif ($status === 'rescheduled') {
            $sent = sendRescheduleConfirmationEmail($request, $request);
            $emailLabel = 'rescheduling confirmation';
        } else {
            $sent = sendProviderCancellationEmail($request, $request);
            $emailLabel = 'cancellation and refund notice';
        }
        if (!$sent) throw new RuntimeException('Gmail did not confirm delivery. Check the recipient address and SMTP connection, then try again.');
        $emailCountUpdate = $pdo->prepare('UPDATE booking_cancellation_requests SET provider_email_sent_count=provider_email_sent_count+1, provider_email_last_sent_at=NOW() WHERE cancellation_request_id=?');
        $emailCountUpdate->execute([$requestId]);
        logActivity($pdo, 'Admin', (int)($_SESSION['admin_id'] ?? 0), (string)($_SESSION['admin_name'] ?? 'Administrator'),
            'Cancellation Email Resent', 'Resent the ' . $emailLabel . ' for ' . ($request['booking_reference'] ?: ('booking #' . $request['booking_id'])) . ' to ' . $request['email'] . '.', 'Bookings', (int)$request['booking_id']);
        $_SESSION['alert'] = ['type'=>'success','title'=>'Tourist Email Sent','message'=>'The ' . htmlspecialchars($emailLabel) . ' was delivered to <strong>' . htmlspecialchars((string)$request['email']) . '</strong>.'];
    } catch (Throwable $error) {
        $_SESSION['alert'] = ['type'=>'error','title'=>'Email Not Sent','message'=>htmlspecialchars($error->getMessage())];
    }
    header('Location: adbookings.php?tab=cancellations');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cancellation_action'], $_POST['cancellation_request_id'])) {
    $returnTab = 'cancellations';
    try {
        if (!hash_equals((string)$_SESSION['cancellation_admin_csrf'], (string)($_POST['csrf_token'] ?? ''))) {
            throw new RuntimeException('Your session expired. Refresh the page and try again.');
        }

        $requestId = (int)$_POST['cancellation_request_id'];
        $action = strtolower(trim((string)$_POST['cancellation_action']));
        $adminId = (int)($_SESSION['admin_id'] ?? 0);
        $adminNote = trim((string)($_POST['admin_note'] ?? ''));
        if ($requestId <= 0 || !in_array($action, ['approve', 'reject', 'complete'], true)) {
            throw new RuntimeException('Invalid cancellation action.');
        }
        if ($action === 'reject' && $adminNote === '') {
            throw new RuntimeException('Add a reason before rejecting this cancellation request.');
        }

        $pdo->beginTransaction();
        $requestStatement = $pdo->prepare('SELECT * FROM booking_cancellation_requests WHERE cancellation_request_id = ? LIMIT 1 FOR UPDATE');
        $requestStatement->execute([$requestId]);
        $cancellation = $requestStatement->fetch(PDO::FETCH_ASSOC);
        if (!$cancellation) throw new RuntimeException('Cancellation request not found.');

        $requestStatus = strtolower((string)$cancellation['request_status']);
        $refundableAmount = (float)$cancellation['refundable_amount'];
        $refundStatus = strtolower((string)$cancellation['refund_status']);
        $reference = (string)($cancellation['booking_reference'] ?: ('Booking #' . $cancellation['booking_id']));
        $cancellationTourist = [];
        if ($action === 'approve') {
            $touristStatement = $pdo->prepare('SELECT full_name, email FROM tourist WHERE tourist_id = ? LIMIT 1');
            $touristStatement->execute([(int)$cancellation['tourist_id']]);
            $cancellationTourist = $touristStatement->fetch(PDO::FETCH_ASSOC) ?: [];
        }

        if ($action === 'approve') {
            if ($requestStatus !== 'pending') throw new RuntimeException('Only awaiting requests can be approved.');
            $nextRefundStatus = $refundableAmount > 0.009 ? 'pending' : 'not_applicable';
            $update = $pdo->prepare("UPDATE booking_cancellation_requests
                SET request_status='approved', refund_status=?, admin_note=NULL,
                    reviewed_at=NOW(), reviewed_by_admin_id=?, refund_updated_at=NOW()
                WHERE cancellation_request_id=?");
            $update->execute([$nextRefundStatus, $adminId ?: null, $requestId]);

            if (strtolower((string)$cancellation['booking_domain']) === 'hotel') {
                $sourceUpdate = $pdo->prepare("UPDATE hotel_room_bookings SET booking_status='cancelled', updated_at=NOW() WHERE hotel_booking_id=? AND tourist_id=?");
            } else {
                $sourceUpdate = $pdo->prepare("UPDATE bookings SET status='accepted', is_complete='cancelled', dec_can_note='Cancelled by tourist - approved by admin', updated_at=NOW() WHERE booking_id=? AND tourist_id=?");
            }
            $sourceUpdate->execute([(int)$cancellation['booking_id'], (int)$cancellation['tourist_id']]);
            $alert = ['type' => 'success', 'title' => 'Cancellation Approved', 'message' => htmlspecialchars($reference) . ' is now cancelled. The refund eligibility is ready for processing.'];
            $activityTitle = 'Cancellation Approved';
        } elseif ($action === 'reject') {
            if ($requestStatus !== 'pending') throw new RuntimeException('Only awaiting requests can be rejected.');
            $update = $pdo->prepare("UPDATE booking_cancellation_requests
                SET request_status='rejected', refund_status='not_started', admin_note=?,
                    reviewed_at=NOW(), reviewed_by_admin_id=?
                WHERE cancellation_request_id=?");
            $update->execute([mb_substr($adminNote, 0, 1500), $adminId ?: null, $requestId]);
            $alert = ['type' => 'info', 'title' => 'Request Not Approved', 'message' => htmlspecialchars($reference) . ' remains active. The tourist can view the review note.'];
            $activityTitle = 'Cancellation Rejected';
        } else {
            if ($requestStatus !== 'approved') throw new RuntimeException('Approve the cancellation before completing it.');
            if ($refundableAmount > 0.009 && !in_array($refundStatus, ['completed', 'refunded'], true)) {
                throw new RuntimeException('Complete the required refund before marking this cancellation completed.');
            }
            $update = $pdo->prepare("UPDATE booking_cancellation_requests
                SET request_status='completed', completed_at=NOW(), completed_by_admin_id=?
                WHERE cancellation_request_id=?");
            $update->execute([$adminId ?: null, $requestId]);
            $alert = ['type' => 'success', 'title' => 'Cancellation Completed', 'message' => htmlspecialchars($reference) . ' has been marked completed.'];
            $activityTitle = 'Cancellation Completed';
        }

        $pdo->commit();
        if ($action === 'approve') {
            $touristName = trim((string)($cancellationTourist['full_name'] ?? '')) ?: 'Guest';
            $touristEmail = trim((string)($cancellationTourist['email'] ?? ''));
            $emailSent = false;
            try {
                $emailSent = sendCancellationApprovedEmail($touristEmail, $touristName, $cancellation);
            } catch (Throwable $emailError) {
                error_log('Cancellation approval email failed after commit: ' . $emailError->getMessage());
            }

            if ($emailSent) {
                $alert = [
                    'type' => 'success',
                    'title' => 'Cancellation Approved & Email Sent',
                    'message' => htmlspecialchars($reference) . ' is now cancelled. The approval and refund details were emailed to ' . htmlspecialchars($touristEmail) . '.',
                ];
            } else {
                $alert = [
                    'type' => 'warning',
                    'title' => 'Cancellation Approved',
                    'message' => htmlspecialchars($reference) . ' is now cancelled, but the confirmation email could not be delivered. Please verify the tourist email address and SMTP connection.',
                ];
            }
        }
        try {
            logActivity($pdo, 'Admin', $adminId, (string)($_SESSION['admin_name'] ?? 'Administrator'),
                $activityTitle, $activityTitle . ' for ' . $reference . '.', 'Bookings', (int)$cancellation['booking_id']);
        } catch (Throwable $loggingError) {
            error_log('Cancellation admin activity logging failed: ' . $loggingError->getMessage());
        }
        $_SESSION['alert'] = $alert;
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $_SESSION['alert'] = [
            'type' => 'error',
            'title' => 'Cancellation Action Failed',
            'message' => htmlspecialchars($error->getMessage() ?: 'The cancellation request could not be updated.'),
        ];
    }
    header('Location: adbookings.php?tab=' . $returnTab);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'paymongoPaymentStatus') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    $token = strtolower(trim((string)($_GET['token'] ?? '')));
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid payment return token.']);
        exit;
    }
    try {
        $stmt = $pdo->prepare(
            "SELECT payment_transaction_id, booking_id, booking_reference, amount_minor, status,
                    provider_checkout_session_id, metadata
             FROM payment_transactions WHERE return_token = ? LIMIT 1"
        );
        $stmt->execute([$token]);
        $transaction = $stmt->fetch(PDO::FETCH_ASSOC);
        $metadata = $transaction ? json_decode((string)($transaction['metadata'] ?? ''), true) : null;
        if (!$transaction
            || !is_array($metadata)
            || ($metadata['source'] ?? '') !== 'admin_booking_payment'
            || (int)($metadata['admin_id'] ?? 0) !== (int)($_SESSION['admin_id'] ?? 0)) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Staff payment transaction not found.']);
            exit;
        }
        if (strtolower((string)$transaction['status']) === 'pending'
            && preg_match('/^cs_[A-Za-z0-9]+$/', (string)$transaction['provider_checkout_session_id'])) {
            try {
                $payMongo = PayMongoService::fromEnvironment();
                $checkoutResponse = $payMongo->retrieveCheckoutSession((string)$transaction['provider_checkout_session_id']);
                $checkoutResource = is_array($checkoutResponse['data'] ?? null) ? $checkoutResponse['data'] : [];
                PaymentReconciler::reconcilePaidCheckout($pdo, $checkoutResource);
                $statusRefresh = $pdo->prepare('SELECT status FROM payment_transactions WHERE payment_transaction_id = ?');
                $statusRefresh->execute([(int)$transaction['payment_transaction_id']]);
                $transaction['status'] = (string)$statusRefresh->fetchColumn();
            } catch (UnexpectedValueException|PayMongoException $ignored) {
                // A pending Checkout Session has no paid Payment resource yet.
            }
        }
        echo json_encode([
            'success' => true,
            'status' => strtolower((string)$transaction['status']),
            'booking_id' => (int)$transaction['booking_id'],
            'booking_reference' => (string)$transaction['booking_reference'],
            'amount' => ((int)$transaction['amount_minor']) / 100,
        ]);
    } catch (Throwable $exception) {
        http_response_code(503);
        echo json_encode(['success' => false, 'message' => 'Payment status is temporarily unavailable.']);
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'searchWalkinTourists') {
    header('Content-Type: application/json; charset=utf-8');
    $query = trim((string)($_GET['q'] ?? ''));
    if (mb_strlen($query) < 2) {
        echo json_encode(['success' => true, 'tourists' => []]);
        exit;
    }

    $term = '%' . $query . '%';
    $searchStmt = $pdo->prepare("
        SELECT tourist_id, full_name, email, phone_number, profile_picture, google_id
        FROM tourist
        WHERE status = 'active'
          AND (full_name LIKE ? OR email LIKE ? OR phone_number LIKE ?)
        ORDER BY full_name ASC
        LIMIT 15
    ");
    $searchStmt->execute([$term, $term, $term]);
    $tourists = $searchStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($tourists as &$tourist) {
        $profileImage = getProfileImg((string)($tourist['profile_picture'] ?? ''));
        if (
            ($profileImage === '' || $profileImage === 'img/profileicon.png')
            && !empty($tourist['google_id'])
        ) {
            $profileImage = 'https://profiles.google.com/' . rawurlencode((string)$tourist['google_id']) . '/picture?sz=96';
        } elseif ($profileImage === 'img/profileicon.png') {
            $profileImage = '';
        } elseif ($profileImage !== '' && !preg_match('#^https?://#i', $profileImage)) {
            $isAdminRoute = str_contains(str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? '')), '/admin/');
            $profileImage = ($isAdminRoute ? '../' : '') . ltrim($profileImage, '/');
        }
        $tourist['profile_image'] = $profileImage;
        unset($tourist['profile_picture'], $tourist['google_id']);
    }
    unset($tourist);

    echo json_encode(['success' => true, 'tourists' => $tourists]);
    exit;
}

// =============================
// STAFF: CREATE WALK-IN BOOKING
// =============================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_walkin_booking') {
    $redirectTab = 'pending';

    try {
        if (!hash_equals((string)$_SESSION['walkin_booking_csrf'], (string)($_POST['csrf_token'] ?? ''))) {
            throw new RuntimeException('The form session expired. Please reopen the booking form.');
        }

        $guestMode = ($_POST['guest_mode'] ?? 'existing') === 'new' ? 'new' : 'existing';
        $bookingType = strtolower(trim((string)($_POST['booking_type'] ?? '')));
        if (!in_array($bookingType, ['package', 'boat', 'tourguide'], true)) {
            throw new RuntimeException('Please select a valid booking type.');
        }

        $bookingDate = trim((string)($_POST['booking_date'] ?? ''));
        $bookingDate = ItourValidationDate($bookingDate, 'Booking date');
        if ($bookingDate < date('Y-m-d')) {
            throw new RuntimeException('The booking date cannot be in the past.');
        }
        $bookingEndDate = trim((string)($_POST['booking_end_date'] ?? ''));

        $adults = ItourValidationInt($_POST['num_adults'] ?? null, 'Adults', 1, 100);
        $children = ItourValidationInt($_POST['num_children'] ?? 0, 'Children', 0, 100);
        $pax = $adults + $children;
        if ($pax > 100) {
            throw new RuntimeException('A maximum of 100 guests is allowed.');
        }

        $jumpOffPort = trim((string)($_POST['jump_off_port'] ?? ''));
        $allowedJumpOffPorts = ['Mercedes Port', 'Cayucyucan'];
        $tourType = trim((string)($_POST['tour_type'] ?? 'same-day'));
        $tourRange = trim((string)($_POST['tour_range'] ?? ''));
        if (!in_array($jumpOffPort, $allowedJumpOffPorts, true)) {
            throw new RuntimeException('Please select a valid jump-off port.');
        }
        if (!in_array($tourType, ['same-day', 'overnight'], true)) {
            $tourType = 'same-day';
        }
        if ($tourType === 'overnight') {
            $bookingEndDate = ItourValidationDate($bookingEndDate, 'Booking end date');
            if ($bookingEndDate <= $bookingDate) {
                throw new RuntimeException('Please select a valid overnight start and end date.');
            }
            $tourRange = $bookingDate . ' to ' . $bookingEndDate;
        } else {
            $bookingEndDate = '';
            $tourRange = $bookingDate;
        }

        $serviceAmount = ItourValidationMoney($_POST['service_amount'] ?? null, 'Service amount');
        $expenseAmounts = [
            'Environmental Fee' => ItourValidationMoney($_POST['expense_environmental'] ?? 0, 'Environmental fee'),
            'Entrance Fee' => ItourValidationMoney($_POST['expense_entrance'] ?? 0, 'Entrance fee'),
            'Docking / Landing Fee' => ItourValidationMoney($_POST['expense_docking'] ?? 0, 'Docking fee'),
            'Other Fee' => ItourValidationMoney($_POST['expense_other'] ?? 0, 'Other fee'),
        ];
        $expensesTotal = round(array_sum($expenseAmounts), 2);
        $grandTotal = round($serviceAmount + $expensesTotal, 2);
        $paymentAmount = ItourValidationMoney($_POST['payment_amount'] ?? 0, 'Payment amount');
        if ($paymentAmount > $grandTotal) {
            throw new RuntimeException('Payment received cannot exceed the total booking amount.');
        }
        $remainingBalance = round($grandTotal - $paymentAmount, 2);
        $paymentMethod = trim((string)($_POST['payment_method'] ?? ''));
        $paymentOption = ($_POST['payment_option'] ?? 'partial') === 'full' ? 'full' : 'partial';
        if ($paymentAmount > 0 && $paymentMethod === '') {
            throw new RuntimeException('Select a payment method for the amount received.');
        }

        $bookingStatus = ($_POST['booking_status'] ?? 'pending') === 'accepted' ? 'accepted' : 'pending';
        $redirectTab = $bookingStatus;
        $phoneNumber = trim((string)($_POST['phone_number'] ?? ''));
        $location = trim((string)($_POST['location'] ?? ''));
        $packageName = null;
        $operatorId = null;
        $boatId = null;
        $guideId = null;
        $resourceName = '';

        $pdo->beginTransaction();

        if ($guestMode === 'existing') {
            $touristId = (int)($_POST['tourist_id'] ?? 0);
            $touristStmt = $pdo->prepare("SELECT tourist_id, full_name, email, phone_number, status FROM tourist WHERE tourist_id = ? LIMIT 1");
            $touristStmt->execute([$touristId]);
            $tourist = $touristStmt->fetch(PDO::FETCH_ASSOC);
            if (!$tourist || strtolower((string)$tourist['status']) !== 'active') {
                throw new RuntimeException('Please select an active tourist account.');
            }
            if ($phoneNumber === '') {
                $phoneNumber = trim((string)($tourist['phone_number'] ?? ''));
            }
        } else {
            $guestName = trim((string)($_POST['guest_name'] ?? ''));
            $guestEmail = strtolower(trim((string)($_POST['guest_email'] ?? '')));
            $guestAddress = trim((string)($_POST['guest_address'] ?? ''));
            if ($guestName === '' || !filter_var($guestEmail, FILTER_VALIDATE_EMAIL) || $phoneNumber === '') {
                throw new RuntimeException('Walk-in guest name, valid email, and phone number are required.');
            }

            $existingStmt = $pdo->prepare("SELECT tourist_id, status FROM tourist WHERE LOWER(email) = ? LIMIT 1");
            $existingStmt->execute([$guestEmail]);
            $existingTourist = $existingStmt->fetch(PDO::FETCH_ASSOC);
            if ($existingTourist) {
                if (strtolower((string)$existingTourist['status']) !== 'active') {
                    throw new RuntimeException('This email belongs to an inactive tourist account.');
                }
                $touristId = (int)$existingTourist['tourist_id'];
            } else {
                $temporaryPassword = password_hash(bin2hex(random_bytes(18)), PASSWORD_DEFAULT);
                $insertTourist = $pdo->prepare("
                    INSERT INTO tourist (full_name, email, phone_number, address, password_hash, email_verified, status)
                    VALUES (?, ?, ?, ?, ?, 1, 'active')
                ");
                $insertTourist->execute([$guestName, $guestEmail, $phoneNumber, $guestAddress, $temporaryPassword]);
                $touristId = (int)$pdo->lastInsertId();
            }
        }

        if ($phoneNumber === '') {
            throw new RuntimeException('A contact number is required.');
        }

        if ($bookingType === 'package') {
            $packageId = (int)($_POST['package_id'] ?? 0);
            $resourceStmt = $pdo->prepare("SELECT package_title, operator_id, package_type, package_range, price FROM tour_packages WHERE package_id = ? LIMIT 1");
            $resourceStmt->execute([$packageId]);
            $resource = $resourceStmt->fetch(PDO::FETCH_ASSOC);
            if (!$resource) throw new RuntimeException('Please select a valid tour package.');
            $packageName = (string)$resource['package_title'];
            $resourceName = $packageName;
            $operatorId = (int)$resource['operator_id'];
        } elseif ($bookingType === 'boat') {
            $boatId = (int)($_POST['boat_id'] ?? 0);
            $resourceStmt = $pdo->prepare("SELECT name, total_pax FROM boats WHERE boat_id = ? LIMIT 1");
            $resourceStmt->execute([$boatId]);
            $resource = $resourceStmt->fetch(PDO::FETCH_ASSOC);
            if (!$resource) throw new RuntimeException('Please select a valid tour boat.');
            if ($pax > (int)$resource['total_pax']) {
                throw new RuntimeException('Guest count exceeds the selected boat capacity.');
            }
            $resourceName = (string)$resource['name'];
        } else {
            $guideId = (int)($_POST['guide_id'] ?? 0);
            $resourceStmt = $pdo->prepare("SELECT fullname FROM tour_guides WHERE guide_id = ? LIMIT 1");
            $resourceStmt->execute([$guideId]);
            $resource = $resourceStmt->fetch(PDO::FETCH_ASSOC);
            if (!$resource) throw new RuntimeException('Please select a valid tour guide.');
            $resourceName = (string)$resource['fullname'];
        }

        if ($bookingType !== 'package' && $location === '') {
            throw new RuntimeException('Destination is required for boat and tour guide bookings.');
        }

        if ($boatId || $guideId) {
            require_once __DIR__ . '/../php/tour_resource_availability_helper.php';
            $resourceType = $boatId ? 'boat' : 'tourguide';
            $resourceId = $boatId ?: $guideId;
            $resourceEndDate = $tourType === 'overnight' ? $bookingEndDate : $bookingDate;
            if (!tourResourceIsAvailable($pdo, $resourceType, $resourceId, $bookingDate, $resourceEndDate)) {
                throw new RuntimeException('The selected boat or tour guide is unavailable for the selected date.');
            }
        }

        $isPaid = ($grandTotal > 0 && $remainingBalance <= 0) ? 1 : 0;
        $paymentLabel = $isPaid ? 'Paid' : ($paymentAmount > 0 ? 'Partial' : 'Unpaid');
        $preferredResource = $resourceName
            . ' | Walk-in booking'
            . ' | Payment Option: ' . ($paymentOption === 'full' ? 'Full Payment' : '20% Partial Payment')
            . ' | Payment Status: ' . $paymentLabel;
        $bookingReference = BookingReferenceGenerate($pdo, $bookingType);

        $insertBooking = $pdo->prepare("
            INSERT INTO bookings (
                booking_reference, tourist_id, operator_id, booking_date, location, package_name,
                phone_number, booking_type, jump_off_port, tour_type, tour_range,
                status, is_notif_viewed, num_adults, num_children, is_complete,
                preferred_resource, grand_total, remaining_balance, payment_amount,
                is_paid, payment_method, guide_id, boat_id, created_at, updated_at
            ) VALUES (
                ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
                ?, 0, ?, ?, 'uncomplete',
                ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW()
            )
        ");
        $insertBooking->execute([
            $bookingReference, $touristId, $operatorId, $bookingDate, $location, $packageName,
            $phoneNumber, $bookingType, $jumpOffPort, $tourType, substr($tourRange, 0, 50),
            $bookingStatus, $adults, $children,
            substr($preferredResource, 0, 255), $grandTotal, $remainingBalance, $paymentAmount,
            $isPaid, ($paymentMethod !== '' ? $paymentMethod : null), $guideId, $boatId
        ]);
        $bookingId = (int)$pdo->lastInsertId();

        $insertExpense = $pdo->prepare("
            INSERT INTO booking_expenses (booking_id, expense_type, amount, note)
            VALUES (?, ?, ?, ?)
        ");
        foreach ($expenseAmounts as $expenseType => $expenseAmount) {
            if ($expenseAmount <= 0) continue;
            $insertExpense->execute([
                (string)$bookingId,
                $expenseType,
                $expenseAmount,
                'Recorded during staff walk-in booking creation'
            ]);
        }

        if ($paymentMethod === 'cash' && $paymentAmount > 0) {
            $cashUnique = bin2hex(random_bytes(16));
            $cashMetadata = json_encode([
                'source' => 'admin_booking_payment',
                'recorded_by_admin_id' => (int)($_SESSION['admin_id'] ?? 0),
                'display_reference' => 'Cash',
            ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            $cashLedger = $pdo->prepare("
                INSERT INTO payment_transactions
                  (tourist_id, booking_domain, booking_id, booking_reference, provider,
                   merchant_reference, idempotency_key, return_token, amount_minor,
                   currency, status, payment_method_type, metadata, paid_at)
                VALUES (?, ?, ?, ?, 'cash', ?, ?, ?, ?, 'PHP', 'paid', 'cash', ?, NOW())
            ");
            $cashLedger->execute([
                $touristId,
                $bookingType,
                $bookingId,
                $bookingReference,
                'CASH-' . date('YmdHis') . '-' . strtoupper(substr($cashUnique, 0, 8)),
                'admin-walkin-cash:' . $cashUnique,
                hash('sha256', $cashUnique . random_bytes(8)),
                (int)round($paymentAmount * 100),
                $cashMetadata,
            ]);
        }

        logActivity(
            $pdo, 'Admin', (int)($_SESSION['admin_id'] ?? 0),
            (string)($_SESSION['admin_name'] ?? 'Administrator'),
            'Walk-in Booking Added',
            'Created walk-in ' . $bookingType . ' booking ' . $bookingReference . ' for ' . $resourceName . '.',
            'Bookings', $bookingId
        );

        $pdo->commit();
        $_SESSION['walkin_booking_csrf'] = bin2hex(random_bytes(32));
        $_SESSION['alert'] = [
            'type' => 'success',
            'title' => 'Booking Added',
            'message' => 'Walk-in booking ' . $bookingReference . ' was created successfully.'
        ];
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $_SESSION['alert'] = [
            'type' => 'error',
            'title' => 'Unable to Add Booking',
            'message' => $error->getMessage()
        ];
    }

    header('Location: adbookings.php?tab=' . urlencode($redirectTab));
    exit;
}

if (isset($_POST['action']) && $_POST['action'] === 'add_expense') {

    header('Content-Type: application/json');

    try {
        if (!AppVerifyCsrf('admin', 'booking_management', $_POST['csrf_token'] ?? null)) {
            http_response_code(403);
            throw new RuntimeException('Your session expired. Refresh the page and try again.');
        }

        $booking_id = ItourValidationInt($_POST['booking_id'] ?? null, 'Booking ID', 1, PHP_INT_MAX);
        $type = ItourValidationText($_POST['expense_type'] ?? null, 'Expense type', 40, true);
        $amount = ItourValidationMoney($_POST['amount'] ?? null, 'Expense amount', 10000000.00, false);
        $note = ItourValidationText($_POST['note'] ?? '', 'Expense note', 500);
        $allowedTypes = ['additional_boat', 'additional_tourguide', 'food', 'others'];

        if ($booking_id <= 0 || $amount <= 0 || !in_array($type, $allowedTypes, true)) {
            throw new Exception('Please provide valid expense details.');
        }

        $pdo->beginTransaction();

        $bookingStateStmt = $pdo->prepare('SELECT status, is_complete FROM bookings WHERE booking_id = ? FOR UPDATE');
        $bookingStateStmt->execute([$booking_id]);
        $expenseBooking = $bookingStateStmt->fetch(PDO::FETCH_ASSOC);
        if (!$expenseBooking || !in_array(strtolower((string)$expenseBooking['status']), ['pending', 'accepted'], true)
            || strtolower((string)$expenseBooking['is_complete']) !== 'uncomplete') {
            throw new RuntimeException('Expenses can only be added to pending or accepted bookings.');
        }

        // 1. Insert expense
        $stmt = $pdo->prepare("
            INSERT INTO booking_expenses (booking_id, expense_type, amount, note)
            VALUES (:booking_id, :type, :amount, :note)
        ");

        $stmt->execute([
            ':booking_id' => $booking_id,
            ':type' => $type,
            ':amount' => $amount,
            ':note' => $note
        ]);

        // 2. Keep the booking accounting equation in sync. An expense raises
        // both the total amount due and the unpaid balance by the same value.
        $stmt2 = $pdo->prepare("
            UPDATE bookings 
            SET grand_total = grand_total + :total_amount,
                remaining_balance = remaining_balance + :balance_amount,
                is_paid = 0,
                updated_at = NOW()
            WHERE booking_id = :booking_id
        ");

        $stmt2->execute([
            ':total_amount' => $amount,
            ':balance_amount' => $amount,
            ':booking_id' => $booking_id
        ]);

        // 3. Return both updated figures so any active billing UI can refresh
        // from one authoritative response.
        $stmt3 = $pdo->prepare("
            SELECT grand_total, payment_amount, remaining_balance
            FROM bookings 
            WHERE booking_id = :booking_id
        ");

        $stmt3->execute([
            ':booking_id' => $booking_id
        ]);

        $updatedBookingTotals = $stmt3->fetch(PDO::FETCH_ASSOC);
        if (!$updatedBookingTotals) {
            throw new RuntimeException('The updated booking totals could not be loaded.');
        }

        $pdo->commit();

        // 4. Return response
        echo json_encode([
            'success' => true,
            'message' => 'Expense added successfully!',
            'new_total' => (float)$updatedBookingTotals['grand_total'],
            'amount_paid' => (float)$updatedBookingTotals['payment_amount'],
            'new_balance' => (float)$updatedBookingTotals['remaining_balance'],
            'booking_id' => $booking_id
        ]);

    } catch (Throwable $e) {

        if ($pdo->inTransaction()) $pdo->rollBack();

        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
    }

    exit;
}
// =============================
// AJAX: CONFIRM PAYMENT (MUST BE FIRST)
// =============================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'confirm_payment') {

    header('Content-Type: application/json; charset=utf-8');

    try {

        $id = ItourValidationInt($_POST['id'] ?? null, 'Booking ID', 1, PHP_INT_MAX);
        $paymentMethod = trim((string)($_POST['payment_method'] ?? ''));
        $amount = ItourValidationMoney($_POST['amount'] ?? null, 'Payment amount', 10000000.00, false);
        $completeAfterPayment = !empty($_POST['complete_after_payment']);

        if (!hash_equals((string)$_SESSION['paymongo_admin_csrf'], (string)($_POST['csrf_token'] ?? ''))) {
            throw new Exception('Your payment session expired. Refresh the page and try again.');
        }
        if ($paymentMethod !== 'cash') {
            throw new Exception('QR Code payments must be verified through PayMongo.');
        }

        $pdo->beginTransaction();

        $stmt = $pdo->prepare("SELECT booking_id, tourist_id, booking_reference, booking_type, payment_amount, remaining_balance, status, is_complete FROM bookings WHERE booking_id = ? FOR UPDATE");
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            throw new Exception('Booking not found.');
        }
        if (strtolower((string)$row['is_complete']) !== 'uncomplete') {
            throw new Exception('Payments can no longer be added to this booking.');
        }

        if ($amount <= 0) {
            throw new Exception('Enter a payment amount greater than zero.');
        }

        $currentBalance = max((float)$row['remaining_balance'], 0);
        if ($completeAfterPayment && (strtolower((string)$row['status']) !== 'accepted'
            || strtolower((string)$row['is_complete']) !== 'uncomplete')) {
            throw new Exception('Only accepted bookings can be completed.');
        }
        if ($amount > $currentBalance) {
            throw new Exception('Payment amount cannot exceed the remaining balance.');
        }
        if ($completeAfterPayment && abs($amount - $currentBalance) > 0.009) {
            throw new Exception('The full remaining balance is required before completing this booking.');
        }

        $newPayment = (float)$row['payment_amount'] + $amount;
        // Expenses increase remaining_balance, so payments must reduce the
        // current balance instead of recalculating from the original total.
        $remaining = $currentBalance - $amount;
        $isPaid = ($remaining <= 0) ? 1 : 0;

        $stmt = $pdo->prepare("
            UPDATE bookings 
            SET payment_amount = ?,
                remaining_balance = ?,
                payment_method = ?,
                is_paid = ?,
                updated_at = NOW()
            WHERE booking_id = ?
        ");

        $stmt->execute([$newPayment, $remaining, $paymentMethod, $isPaid, $id]);

        $cashUnique = bin2hex(random_bytes(16));
        $cashReference = 'CASH-' . date('YmdHis') . '-' . strtoupper(substr($cashUnique, 0, 8));
        $cashMetadata = json_encode([
            'source' => 'admin_booking_payment',
            'recorded_by_admin_id' => (int)($_SESSION['admin_id'] ?? 0),
            'display_reference' => 'Cash',
        ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        $cashLedger = $pdo->prepare("
            INSERT INTO payment_transactions
              (tourist_id, booking_domain, booking_id, booking_reference, provider,
               merchant_reference, idempotency_key, return_token, amount_minor,
               currency, status, payment_method_type, metadata, paid_at)
            VALUES (?, ?, ?, ?, 'cash', ?, ?, ?, ?, 'PHP', 'paid', 'cash', ?, NOW())
        ");
        $cashLedger->execute([
            (int)$row['tourist_id'],
            strtolower((string)$row['booking_type']),
            $id,
            (string)$row['booking_reference'],
            $cashReference,
            'admin-cash:' . $cashUnique,
            hash('sha256', $cashUnique . random_bytes(8)),
            (int)round($amount * 100),
            $cashMetadata,
        ]);

        $completed = false;
        if ($completeAfterPayment && $isPaid && strtolower((string)$row['status']) === 'accepted'
            && strtolower((string)$row['is_complete']) === 'uncomplete') {
            $completeStmt = $pdo->prepare("UPDATE bookings SET is_complete = 'completed', updated_at = NOW()
                WHERE booking_id = ? AND LOWER(status) = 'accepted' AND LOWER(is_complete) = 'uncomplete'");
            $completeStmt->execute([$id]);
            if ($completeStmt->rowCount() !== 1) throw new RuntimeException('The booking changed before completion. Refresh and try again.');
            $completed = true;
        }

        $pdo->commit();

        echo json_encode([
            'success' => true,
            'is_paid' => $isPaid,
            'remaining' => $remaining,
            'completed' => $completed
        ]);

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
    }

    exit;
}

// Retry a confirmation email without changing an already accepted booking.
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && ($_POST['action'] ?? '') === 'retry_confirmation_email'
    && isset($_POST['id'])) {
    $id = max(0, (int)$_POST['id']);
    $return_tab = in_array((string)($_POST['return_tab'] ?? ''), ['all', 'accepted'], true)
        ? (string)$_POST['return_tab']
        : 'accepted';

    try {
        if (!AppVerifyCsrf('admin', 'booking_management', $_POST['csrf_token'] ?? null)) {
            throw new RuntimeException('Your session expired. Refresh the page and try again.');
        }
        $booking = getBookingConfirmationRecord($pdo, $id);
        if (!$booking || strtolower((string)($booking['status'] ?? '')) !== 'accepted') {
            throw new RuntimeException('Only accepted bookings can resend a confirmation email.');
        }

        $confirmationEmail = trim((string)($booking['email'] ?? ''));
        if ($confirmationEmail === '') {
            updateBookingConfirmationEmailStatus($pdo, $id, false, 'No tourist email address is available.');
            throw new RuntimeException('This tourist does not have an email address available for confirmation.');
        }

        $emailSent = sendBookingConfirmedEmail(
            $confirmationEmail,
            (string)($booking['full_name'] ?? 'Guest'),
            $booking
        );
        updateBookingConfirmationEmailStatus(
            $pdo,
            $id,
            $emailSent,
            $emailSent ? '' : 'The SMTP server did not confirm delivery.'
        );

        if ($emailSent) {
            logActivity(
                $pdo, 'Admin', (int)($_SESSION['admin_id'] ?? 0),
                (string)($_SESSION['admin_name'] ?? 'Administrator'),
                'Confirmation Email Resent',
                'Resent the confirmation email for booking #' . $id . ' to ' . $confirmationEmail . '.',
                'Bookings', $id
            );
            $_SESSION['alert'] = [
                'type' => 'success',
                'title' => 'Confirmation email sent',
                'message' => 'The booking confirmation email was sent to <strong>'
                    . htmlspecialchars($confirmationEmail, ENT_QUOTES, 'UTF-8') . '</strong>.',
            ];
        } else {
            $_SESSION['alert'] = [
                'type' => 'warning',
                'title' => 'Email not sent',
                'message' => 'The confirmation email still could not be sent to <strong>'
                    . htmlspecialchars($confirmationEmail, ENT_QUOTES, 'UTF-8')
                    . '</strong>. Check the connection and try again.',
                'retry_email_booking_id' => $id,
                'retry_email_return_tab' => $return_tab,
            ];
        }
    } catch (Throwable $error) {
        $_SESSION['alert'] = [
            'type' => 'warning',
            'title' => 'Email not sent',
            'message' => htmlspecialchars($error->getMessage(), ENT_QUOTES, 'UTF-8'),
        ];
    }

    header('Location: adbookings.php?tab=' . rawurlencode($return_tab));
    exit;
}

// --- Handle POST actions with decision note ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'], $_POST['id'])) {
    try {
        if (!AppVerifyCsrf('admin', 'booking_management', $_POST['csrf_token'] ?? null)) {
            throw new RuntimeException('Your session expired. Refresh the page and try again.');
        }
        $id = ItourValidationInt($_POST['id'], 'Booking ID', 1, PHP_INT_MAX);
        $action = ItourValidationText($_POST['action'], 'Booking action', 20, true);
        $return_tab = $_POST['return_tab'] ?? 'pending';

        $pdo->beginTransaction();
        $stateStmt = $pdo->prepare('SELECT status, is_complete FROM bookings WHERE booking_id = ? FOR UPDATE');
        $stateStmt->execute([$id]);
        $currentState = $stateStmt->fetch(PDO::FETCH_ASSOC);
        if (!$currentState) throw new RuntimeException('Booking not found.');
        $currentStatus = strtolower((string)$currentState['status']);
        $currentCompletion = strtolower((string)$currentState['is_complete']);
        $allowedCurrentStates = [
            'accept' => ['pending', 'uncomplete'],
            'decline' => ['pending', 'uncomplete'],
            'finish' => ['accepted', 'uncomplete'],
        ];
        if (!isset($allowedCurrentStates[$action])
            || $allowedCurrentStates[$action] !== [$currentStatus, $currentCompletion]) {
            throw new RuntimeException('This booking can no longer be changed using that action.');
        }

        $status = null;
        $is_complete = null;
        $decCanNote = null;

        switch ($action) {
            case 'accept':
              $bookingStmt = $pdo->prepare("SELECT booking_type, boat_id, guide_id, booking_date, tour_type, tour_range FROM bookings WHERE booking_id = ? LIMIT 1");
              $bookingStmt->execute([$id]);
              $bookingToAccept = $bookingStmt->fetch(PDO::FETCH_ASSOC);
              if ($bookingToAccept) {
                  $resourceType = strtolower((string)$bookingToAccept['booking_type']);
                  $resourceId = $resourceType === 'boat' ? (int)$bookingToAccept['boat_id'] : ($resourceType === 'tourguide' ? (int)$bookingToAccept['guide_id'] : 0);
                  if ($resourceId > 0) {
                      require_once __DIR__ . '/../php/tour_resource_availability_helper.php';
                      $resourceEnd = tourResourceEndDate($bookingToAccept);
                      $conflictStmt = $pdo->prepare("SELECT booking_id, booking_date, tour_type, tour_range FROM bookings WHERE " . ($resourceType === 'boat' ? 'boat_id' : 'guide_id') . " = ? AND booking_id <> ? AND status = 'accepted' AND is_complete = 'uncomplete'");
                      $conflictStmt->execute([$resourceId, $id]);
                      foreach ($conflictStmt->fetchAll(PDO::FETCH_ASSOC) as $conflict) {
                          if (tourResourceRangesOverlap((string)$bookingToAccept['booking_date'], $resourceEnd, (string)$conflict['booking_date'], tourResourceEndDate($conflict))) {
                              throw new RuntimeException('This boat or tour guide is already accepted for the selected date. Decline or reschedule one booking first.');
                          }
                      }
                  }
              }
              $status = 'accepted';
              $is_complete = 'uncomplete';
              break;
            case 'decline':
                $status = 'declined';
                $is_complete = 'declined';
                // Get category and note from POST
                $category = $_POST['decision_category'] ?? null;
                $note = trim($_POST['decision_note'] ?? '');
                $decCanNote = $category ? $category . ($note ? " - $note" : "") : null;
                break;

            case 'finish':

                $stmt = $pdo->prepare("SELECT remaining_balance FROM bookings WHERE booking_id = ?");
                $stmt->execute([$id]);
                $balance = (float)$stmt->fetchColumn();

                if ($balance > 0) {
                    throw new Exception("Please confirm payment before completing booking.");
                }
                $status = 'accepted';
                $is_complete = 'completed';
                break;

            case 'cancel':
                throw new RuntimeException('Use the provider cancellation workflow to cancel an accepted booking.');
            default:
                throw new Exception("Invalid action."); 
        }

        $stmt = $pdo->prepare("UPDATE bookings SET status=:status, is_complete=:is_complete, dec_can_note=:dec_can_note, updated_at=NOW()
            WHERE booking_id=:id AND LOWER(status)=:old_status AND LOWER(is_complete)=:old_complete");
        $stmt->execute([
            ':status' => $status,
            ':is_complete' => $is_complete,
            ':dec_can_note' => $decCanNote,
            ':id' => $id,
            ':old_status' => $currentStatus,
            ':old_complete' => $currentCompletion,
        ]);
        if ($stmt->rowCount() !== 1) throw new RuntimeException('The booking changed before this action completed. Refresh and try again.');
        $pdo->commit();
        $actionLabels = [
            'accept' => 'Booking Accepted',
            'decline' => 'Booking Rejected',
            'finish' => 'Booking Completed',
            'cancel' => 'Booking Cancelled'
        ];
        logActivity(
            $pdo, 'Admin', (int)($_SESSION['admin_id'] ?? 0),
            (string)($_SESSION['admin_name'] ?? 'Administrator'),
            $actionLabels[$action] ?? 'Booking Updated',
            ($actionLabels[$action] ?? 'Updated booking') . ' #' . $id . '.',
            'Bookings', $id
        );

        $emailSent = false;
        $confirmationEmail = '';

        if ($action === 'accept') {
          $booking = getBookingConfirmationRecord($pdo, $id);

          if ($booking && !empty($booking['email'])) {
              $confirmationEmail = trim((string)$booking['email']);
              $emailSent = sendBookingConfirmedEmail(
                  $confirmationEmail,
                  $booking['full_name'],
                  $booking
              );
          }
          updateBookingConfirmationEmailStatus(
              $pdo,
              $id,
              $emailSent,
              $confirmationEmail === ''
                  ? 'No tourist email address is available.'
                  : ($emailSent ? '' : 'The SMTP server did not confirm delivery.')
          );
      }

        $messages = [
            'accept' => $emailSent
                ? ['success', 'Booking accepted', 'The booking confirmation email was sent to <strong>' . htmlspecialchars($confirmationEmail, ENT_QUOTES, 'UTF-8') . '</strong>.']
                : ['warning', 'Booking accepted', $confirmationEmail !== ''
                    ? 'The booking was accepted, but the confirmation email could not be sent to <strong>' . htmlspecialchars($confirmationEmail, ENT_QUOTES, 'UTF-8') . '</strong>. Please try again.'
                    : 'The booking was accepted, but no tourist email address was available for confirmation.'],
            'decline' => ['error', 'Booking Declined!', 'Booking marked as declined.'],
            'finish' => ['success', 'Booking Completed!', 'Booking moved to Completed tab.'],
            'cancel' => ['info', 'Booking Cancelled!', 'Booking moved to Completed tab as cancelled.']
        ];

        [$type, $title, $message] = $messages[$action] ?? ['info','Updated','Booking updated.'];

        $_SESSION['alert'] = ['type'=>$type,'title'=>$title,'message'=>$message];
        if ($action === 'accept' && !$emailSent && $confirmationEmail !== '') {
            $_SESSION['alert']['retry_email_booking_id'] = $id;
            $_SESSION['alert']['retry_email_return_tab'] = $return_tab === 'all' ? 'all' : 'accepted';
        }

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $_SESSION['alert'] = [
            'type' => 'error',
            'title' => 'Action Failed!',
            'message' => 'Error: ' . htmlspecialchars($e->getMessage()),
            'redirect' => null
        ];
    }

    header("Location: adbookings.php?tab=" . rawurlencode($return_tab));
    exit();
}


// ------------------------
// Filters & Search (GET)
// ------------------------
$activeTab = $_GET['tab'] ?? 'all';
$validTabs = ['all','pending','accepted','completed','cancellations'];
if (!in_array($activeTab, $validTabs)) {
    $activeTab = 'all';
}

$rangeFilter  = strtolower(trim((string)($_GET['range'] ?? ($_GET['filter_mode'] ?? 'all'))));
$validRange   = ['all', 'yearly', 'monthly', 'daily'];
if (!in_array($rangeFilter, $validRange, true)) {
    $rangeFilter = 'all';
}
$selectedYear  = !empty($_GET['year'])  ? intval($_GET['year'])  : (int)date('Y');
$selectedMonth = !empty($_GET['month']) ? intval($_GET['month']) : (int)date('n');
$selectedDate  = !empty($_GET['date'])  ? trim((string)$_GET['date']) : date('Y-m-d');
$search_q     = trim($_GET['search'] ?? '');
$statusFilter = $_GET['status'] ?? ''; // for completed tab
$cancellationStatusFilter = strtolower(trim((string)($_GET['cancel_status'] ?? '')));
$rowsRaw      = trim((string)($_GET['rows'] ?? '25'));
$rowsPerPage  = (int)$rowsRaw;
if ($rowsPerPage < 1) $rowsPerPage = 25;
if ($rowsPerPage > 300) $rowsPerPage = 300;

$isReport = isset($_GET['report_mode']) && $_GET['report_mode'] == '1';

$where = [];
$params = [];

// ----------------------
// TAB FILTER (skip in report mode)
// ----------------------
if (!$isReport) {
    switch ($activeTab) {
        case 'pending':
            $where[] = "b.status = 'pending'";
            break;
        case 'accepted':
            $where[] = "b.status = 'accepted'";
            $where[] = "b.is_complete = 'uncomplete'";
            break;
        case 'completed':
            $where[] = "(b.is_complete IN ('completed','declined') OR (b.is_complete='cancelled' AND (cr.cancellation_request_id IS NULL OR cr.request_status='completed')))";
            break;
    }
}

// ----------------------
// STATUS FILTER (for completed tab)
// ----------------------
if (in_array($activeTab, ['all', 'completed'], true)) {
    if (in_array($statusFilter, ['completed','declined','cancelled'], true)) {
        $where[] = "b.is_complete = :status";
        $params[':status'] = $statusFilter;
        if ($statusFilter === 'cancelled') {
            $where[] = "(cr.cancellation_request_id IS NULL OR cr.request_status='completed')";
        }
    } elseif ($activeTab === 'all' && $statusFilter === 'pending') {
        $where[] = "b.status = 'pending'";
        $where[] = "b.is_complete = 'uncomplete'";
    } elseif ($activeTab === 'all' && $statusFilter === 'accepted') {
        $where[] = "b.status = 'accepted'";
        $where[] = "b.is_complete = 'uncomplete'";
    }
}

// ----------------------
// DATE FILTER
// ----------------------

if ($rangeFilter === 'yearly') {
    $where[] = "YEAR(b.created_at) = :selected_year";
    $params[':selected_year'] = $selectedYear;
} elseif ($rangeFilter === 'monthly') {
    $where[] = "YEAR(b.created_at) = :selected_year";
    $where[] = "MONTH(b.created_at) = :selected_month";
    $params[':selected_year'] = $selectedYear;
    $params[':selected_month'] = $selectedMonth;
} elseif ($rangeFilter === 'daily') {
    $where[] = "DATE(b.created_at) = :selected_date";
    $params[':selected_date'] = $selectedDate;
}

// Combine where conditions
$whereSql = '';
if (!empty($where)) {
    $whereSql = 'WHERE ' . implode(' AND ', $where);
}


// ----------------------
// SEARCH FILTER
// ----------------------
if (!empty($search_q)) {
    $where[] = "(
        CAST(b.booking_id AS CHAR) LIKE :search_booking_id
        OR b.booking_reference LIKE :search_reference
        OR t.full_name LIKE :search_name
        OR b.location LIKE :search_location
        OR b.booking_date LIKE :search_date
        OR b.booking_type LIKE :search_type
        OR b.package_name LIKE :search_package
    )";
    $searchLike = "%$search_q%";
    $params[':search_booking_id'] = $searchLike;
    $params[':search_reference'] = $searchLike;
    $params[':search_name'] = $searchLike;
    $params[':search_location'] = $searchLike;
    $params[':search_date'] = $searchLike;
    $params[':search_type'] = $searchLike;
    $params[':search_package'] = $searchLike;
}

// ----------------------
// SORT ORDER
// ----------------------
$sortBy = $_GET['sort_by'] ?? 'time';
$where = $where ?? []; // ensure $where exists

if ($activeTab === 'completed') {
    switch ($sortBy) {
        case 'completed':
        case 'declined':
        case 'cancelled':
            // Add WHERE filter for the selected status
            $where[] = "b.is_complete = :status";
            $params[':status'] = $sortBy;
            if ($sortBy === 'cancelled') {
                $where[] = "(cr.cancellation_request_id IS NULL OR cr.request_status='completed')";
            }
            // Set order
            $orderClause = "FIELD(b.is_complete, 'completed','cancelled','declined'), b.booking_id DESC";
            break;
        case 'name':
            $orderClause = 't.full_name ASC';
            break;
        case 'time':
        default:
            $orderClause = "FIELD(b.is_complete, 'completed','cancelled','declined'), b.booking_id DESC";
            break;
    }
} else {
    // Other tabs
    switch ($sortBy) {
        case 'name':
            $orderClause = 't.full_name ASC';
            break;
        case 'time':
        default:
            $orderClause = 'b.created_at DESC, b.booking_id DESC';
            break;
    }
}

$countSql = "
    SELECT COUNT(*)
    FROM bookings b
    LEFT JOIN tourist t ON b.tourist_id = t.tourist_id
    LEFT JOIN booking_cancellation_requests cr
      ON cr.booking_domain = 'tour'
     AND cr.booking_id = b.booking_id
     AND cr.request_status IN ('pending','approved','decision_required','completed')
";
if (!empty($where)) {
    $countSql .= " WHERE " . implode(" AND ", $where);
}
$countStmt = $pdo->prepare($countSql);
foreach ($params as $key => $val) {
    $countStmt->bindValue($key, $val);
}
$countStmt->execute();
$totalFilteredBookings = (int)$countStmt->fetchColumn();

// ----------------------
// COMPOSE SQL
// ----------------------
$sql = "SELECT 
            b.*,
            b.phone_number AS booking_phone,
            b.location AS booking_location,
            b.booking_type AS booking_type,
            b.package_name AS package_name,
            b.num_adults,
            b.num_children,
            t.tourist_id AS t_id, 
            t.full_name AS t_full_name, 
            t.email AS t_email, 
            t.profile_picture AS t_profile_picture, 
            t.google_id AS t_google_id,
            t.phone_number AS t_phone, 
            t.address AS t_address,
            t.email_verified AS t_email_verified,
            t.status AS t_status,
            t.ban_note AS t_ban_note,
            t.created_at AS t_created_at,
            t.updated_at AS t_updated_at,
            cr.cancellation_request_id AS active_cancellation_request_id,
            cr.request_status AS active_cancellation_status,
            cr.refund_status AS active_cancellation_refund_status,
            cr.refundable_amount AS active_cancellation_refundable_amount,
            (SELECT COUNT(*) FROM bookings tb WHERE tb.tourist_id = t.tourist_id) AS t_total_bookings,
            (SELECT COUNT(*) FROM bookings cb WHERE cb.tourist_id = t.tourist_id AND cb.is_complete = 'completed') AS t_completed_bookings,
            COALESCE(MONTHNAME(b.created_at), '') AS created_month,
            COALESCE(YEAR(b.created_at), '') AS created_year
        FROM bookings b
        LEFT JOIN tourist t ON b.tourist_id = t.tourist_id
        LEFT JOIN booking_cancellation_requests cr
          ON cr.booking_domain = 'tour'
         AND cr.booking_id = b.booking_id
         AND cr.request_status IN ('pending','approved','decision_required','completed')";

if (!empty($where)) {
    $sql .= " WHERE " . implode(" AND ", $where);
}

$sql .= " ORDER BY $orderClause";
$sql .= " LIMIT " . (int)$rowsPerPage;

$stmt = $pdo->prepare($sql);
foreach ($params as $key => $val) {
    $stmt->bindValue($key, $val);
}
$stmt->execute();
$bookings = $stmt->fetchAll(PDO::FETCH_ASSOC);

$cancellationSummary = ['total' => 0, 'pending' => 0, 'approved' => 0, 'refund_pending' => 0, 'completed' => 0];
$cancellationSummaryStatement = $pdo->query("
    SELECT COUNT(*) AS total,
           SUM(request_status='pending') AS pending,
           SUM(request_status='approved') AS approved,
           SUM(request_status='approved' AND refundable_amount > 0 AND refund_status NOT IN ('completed','refunded')) AS refund_pending,
           SUM(request_status='completed') AS completed
    FROM booking_cancellation_requests
");
$cancellationSummaryRow = $cancellationSummaryStatement->fetch(PDO::FETCH_ASSOC) ?: [];
foreach ($cancellationSummary as $summaryKey => $summaryValue) {
    $cancellationSummary[$summaryKey] = (int)($cancellationSummaryRow[$summaryKey] ?? 0);
}

$cancellationWhere = [];
$cancellationParams = [];
$focusedCancellationRequestId = max(0, (int)($_GET['focus_request'] ?? 0));
if (in_array($cancellationStatusFilter, ['pending', 'approved', 'completed', 'rejected'], true)) {
    $cancellationWhere[] = 'cr.request_status = :cancellation_status';
    $cancellationParams[':cancellation_status'] = $cancellationStatusFilter;
} elseif ($cancellationStatusFilter === 'refund_pending') {
    $cancellationWhere[] = "cr.request_status = 'approved' AND cr.refundable_amount > 0 AND cr.refund_status NOT IN ('completed','refunded')";
} elseif ($cancellationStatusFilter === 'refunded') {
    $cancellationWhere[] = "cr.refund_status IN ('completed','refunded')";
}
if ($search_q !== '') {
    $cancellationWhere[] = '(cr.booking_reference LIKE :cancellation_reference OR cr.service_name LIKE :cancellation_service OR t.full_name LIKE :cancellation_name OR t.email LIKE :cancellation_email)';
    $cancellationSearchLike = '%' . $search_q . '%';
    $cancellationParams[':cancellation_reference'] = $cancellationSearchLike;
    $cancellationParams[':cancellation_service'] = $cancellationSearchLike;
    $cancellationParams[':cancellation_name'] = $cancellationSearchLike;
    $cancellationParams[':cancellation_email'] = $cancellationSearchLike;
}
$cancellationSql = "
    SELECT cr.*, t.full_name AS tourist_name, t.email AS tourist_email,
           t.phone_number AS tourist_phone, t.profile_picture AS tourist_profile_picture,
           b.tour_type AS source_tour_type, b.tour_range AS source_tour_range
    FROM booking_cancellation_requests cr
    LEFT JOIN tourist t ON t.tourist_id = cr.tourist_id
    LEFT JOIN bookings b ON cr.booking_domain = 'tour' AND b.booking_id = cr.booking_id
";
if ($cancellationWhere) $cancellationSql .= ' WHERE ' . implode(' AND ', $cancellationWhere);
$cancellationSql .= ' ORDER BY (cr.cancellation_request_id = ' . $focusedCancellationRequestId . ') DESC, cr.requested_at DESC, cr.cancellation_request_id DESC LIMIT ' . (int)$rowsPerPage;
$cancellationStatement = $pdo->prepare($cancellationSql);
foreach ($cancellationParams as $key => $value) $cancellationStatement->bindValue($key, $value);
$cancellationStatement->execute();
$adminCancellationRequests = $cancellationStatement->fetchAll(PDO::FETCH_ASSOC) ?: [];



// ------------------------
// Get distinct years
// ------------------------
$years = [];
try {
    $ystmt = $pdo->query("SELECT DISTINCT YEAR(created_at) AS y FROM bookings WHERE created_at IS NOT NULL ORDER BY y DESC");
    $years = $ystmt->fetchAll(PDO::FETCH_COLUMN, 0);
} catch(Exception $e){ /*ignore*/ }
if (empty($years)) {
    $years = [(int)date('Y')];
}
if (!in_array((int)$selectedYear, array_map('intval', $years), true)) {
    array_unshift($years, (int)$selectedYear);
}

// ------------------------
// Function to get finished bookings
// ------------------------
function getFinishedCounts(PDO $pdo, $touristId){
    if(!$touristId) return ['bookings'=>0];

    // Bookings count
    $stmt1 = $pdo->prepare("SELECT COUNT(*) FROM bookings WHERE tourist_id = :touristId AND is_complete='completed'");
    $stmt1->execute(['touristId'=>$touristId]);
    $bookings = (int)$stmt1->fetchColumn();

    return ['bookings'=>$bookings];
}

// ------------------------
// Helper: get profile image
// ------------------------
function getProfileImg($profile_picture) {
    $default = 'img/profileicon.png';
    if (empty($profile_picture)) return $default;
    if (preg_match('#^https?://#i', $profile_picture)) {
        if (stripos($profile_picture, 'profiles.google.com') !== false && preg_match('#profiles\\.google\\.com/(?:s2/photos/profile/)?([^/?#]+)(?:/picture)?#i', $profile_picture, $m)) {
            return 'https://profiles.google.com/' . rawurlencode($m[1]) . '/picture?sz=256';
        }
        if (stripos($profile_picture, 'googleusercontent.com') !== false) {
            $profile_picture = preg_replace('/([?&])sz=\\d+/i', '$1sz=256', $profile_picture);
            $profile_picture = preg_replace('/=s\\d+-c(?=$|[?&#])/i', '=s256-c', $profile_picture);
            $profile_picture = preg_replace('/=s\\d+(?=$|[?&#])/i', '=s256', $profile_picture);
        }
        return $profile_picture;
    }

    $paths = [
        'uploads/profile_pictures/' . basename($profile_picture),
        'uploads/profile_picture/' . basename($profile_picture),
        ltrim($profile_picture,'/'),
    ];

    foreach($paths as $p){
        if(file_exists(getcwd().'/'.$p)) return $p;
    }

    return $default;
}

function getGoogleAvatarById($googleId) {
    return '';
}

function outputBookingTouristProfileImage(PDO $pdo, int $bookingId): never {
    $statement = $pdo->prepare("
        SELECT t.profile_picture
        FROM bookings b
        INNER JOIN tourist t ON t.tourist_id = b.tourist_id
        WHERE b.booking_id = ?
        LIMIT 1
    ");
    $statement->execute([$bookingId]);
    $rawProfilePicture = trim((string)$statement->fetchColumn());
    $profilePicture = getProfileImg($rawProfilePicture);

    $fail = static function (): never {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        header('Cache-Control: no-store');
        exit('Profile image not found.');
    };

    if ($rawProfilePicture === '' || $profilePicture === 'img/profileicon.png') {
        $fail();
    }

    if (!preg_match('#^https?://#i', $profilePicture)) {
        $root = realpath(getcwd());
        $file = realpath(getcwd() . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, ltrim($profilePicture, '/')));
        if ($root === false || $file === false || !is_file($file) || !str_starts_with($file, $root . DIRECTORY_SEPARATOR)) {
            $fail();
        }
        $mime = (string)(mime_content_type($file) ?: '');
        if (!str_starts_with($mime, 'image/')) {
            $fail();
        }
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . (string)filesize($file));
        header('Cache-Control: private, max-age=3600');
        readfile($file);
        exit;
    }

    $host = strtolower((string)parse_url($profilePicture, PHP_URL_HOST));
    $allowedGoogleHost = $host === 'googleusercontent.com'
        || str_ends_with($host, '.googleusercontent.com')
        || $host === 'ggpht.com'
        || str_ends_with($host, '.ggpht.com')
        || $host === 'profiles.google.com';
    if (!$allowedGoogleHost || !function_exists('curl_init')) {
        $fail();
    }

    $curl = curl_init($profilePicture);
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        CURLOPT_MAXREDIRS => 3,
        CURLOPT_USERAGENT => 'iTour Mercedes profile image/1.0',
    ]);
    $image = curl_exec($curl);
    $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $mime = strtolower(trim((string)curl_getinfo($curl, CURLINFO_CONTENT_TYPE)));
    $curlError = curl_errno($curl);
    curl_close($curl);

    if (!is_string($image) || $image === '' || strlen($image) > 3 * 1024 * 1024
        || $curlError !== CURLE_OK || $status < 200 || $status >= 300 || !str_starts_with($mime, 'image/')) {
        $fail();
    }

    header('Content-Type: ' . $mime);
    header('Content-Length: ' . (string)strlen($image));
    header('Cache-Control: private, max-age=3600');
    echo $image;
    exit;
}

function getPaymentStatusFromMeta($preferredResource) {
    $raw = strtolower((string)$preferredResource);
    if ($raw === '') {
        return ['label' => 'Unpaid', 'class' => 'unpaid'];
    }

    if (preg_match('/payment status\s*:\s*([^|]+)/i', (string)$preferredResource, $m)) {
        $explicit = strtolower(trim((string)$m[1]));
        if (strpos($explicit, 'partial') !== false) {
            return ['label' => 'Partial', 'class' => 'partial'];
        }
        if (strpos($explicit, 'paid') !== false || strpos($explicit, 'full') !== false) {
            return ['label' => 'Paid', 'class' => 'paid'];
        }
    }

    if (preg_match('/payment option\s*:\s*([^|]+)/i', (string)$preferredResource, $m) || preg_match('/payment\s*:\s*([^|]+)/i', (string)$preferredResource, $m)) {
        $option = strtolower(trim((string)$m[1]));
        if (strpos($option, 'partial') !== false || strpos($option, '20%') !== false) {
            return ['label' => 'Partial', 'class' => 'partial'];
        }
        if (strpos($option, 'full') !== false) {
            return ['label' => 'Paid', 'class' => 'paid'];
        }
    }

    return ['label' => 'Unpaid', 'class' => 'unpaid'];
}

// Data used by the walk-in booking form. Keep the form tied to real,
// currently available records so staff cannot submit an invalid resource ID.
$walkInPackages = [];
$walkInBoats = [];
$walkInGuides = [];
$walkInServicePrices = [];
try {
    $walkInPackages = $pdo->query("
        SELECT package_id, package_title, package_type, package_range, price
        FROM tour_packages
        ORDER BY package_title ASC
    ")->fetchAll(PDO::FETCH_ASSOC);
    $walkInBoats = $pdo->query("
        SELECT boat_id, name, total_pax
        FROM boats
        ORDER BY name ASC
    ")->fetchAll(PDO::FETCH_ASSOC);
    $walkInGuides = $pdo->query("
        SELECT guide_id, fullname
        FROM tour_guides
        ORDER BY fullname ASC
    ")->fetchAll(PDO::FETCH_ASSOC);
    $walkInServicePrices = $pdo->query("
        SELECT service_type, day_tour_price, overnight_price
        FROM service_prices
        WHERE is_active = 1
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $error) {
    // The rest of Booking Management remains usable if an optional catalog
    // table is temporarily unavailable.
}

if (isset($_GET['action']) && $_GET['action'] === 'fetchTouristProfileImage') {
    $bookingId = (int)($_GET['id'] ?? 0);
    if ($bookingId <= 0) {
        http_response_code(404);
        exit;
    }
    outputBookingTouristProfileImage($pdo, $bookingId);
}

if (isset($_GET['action']) && $_GET['action'] === 'fetchBookingDetails') {
    header('Content-Type: application/json');
    try {
        $bookingId = (int)($_GET['id'] ?? 0);
        if ($bookingId <= 0) {
            throw new RuntimeException('Invalid booking reference.');
        }

        $detailStmt = $pdo->prepare("
            SELECT
                b.*,
                t.full_name AS t_full_name,
                t.email AS t_email,
                t.phone_number AS t_phone,
                t.address AS t_address,
                t.profile_picture AS t_profile_picture,
                COALESCE(b.phone_number, t.phone_number) AS booking_phone,
                bt.name AS boat_name,
                tg.fullname AS guide_name
            FROM bookings b
            LEFT JOIN tourist t ON t.tourist_id = b.tourist_id
            LEFT JOIN boats bt ON bt.boat_id = b.boat_id
            LEFT JOIN tour_guides tg ON tg.guide_id = b.guide_id
            WHERE b.booking_id = ?
            LIMIT 1
        ");
        $detailStmt->execute([$bookingId]);
        $booking = $detailStmt->fetch(PDO::FETCH_ASSOC);
        if (!$booking) {
            throw new RuntimeException('Booking details could not be found.');
        }
        $rawTouristProfilePicture = trim((string)($booking['t_profile_picture'] ?? ''));
        $booking['t_profile_picture'] = getProfileImg($rawTouristProfilePicture);
        $booking['t_has_profile_picture'] = $rawTouristProfilePicture !== ''
            && basename(str_replace('\\', '/', $rawTouristProfilePicture)) !== 'profileicon.png'
            && $booking['t_profile_picture'] !== 'img/profileicon.png';
        $booking['tour_start_date'] = tourResourceDate((string)($booking['booking_date'] ?? ''));
        $booking['tour_end_date'] = tourResourceEndDate($booking);

        $expenseStmt = $pdo->prepare("
            SELECT expense_type, amount, note, created_at
            FROM booking_expenses
            WHERE booking_id = ?
            ORDER BY id ASC
        ");
        $expenseStmt->execute([(string)$bookingId]);

        echo json_encode([
            'success' => true,
            'booking' => $booking,
            'expenses' => $expenseStmt->fetchAll(PDO::FETCH_ASSOC)
        ], JSON_INVALID_UTF8_SUBSTITUTE);
    } catch (Throwable $error) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => $error->getMessage()
        ], JSON_INVALID_UTF8_SUBSTITUTE);
    }
    exit;
}

// --- Handle AJAX fetch bookings ---
if(isset($_GET['action']) && $_GET['action'] === 'fetchBookings') {
    header('Content-Type: application/json');

    try {

        $stmt = $pdo->query("
            SELECT 
                b.booking_id,
                b.booking_reference,
                t.full_name AS name,
                t.email,
                COALESCE(b.phone_number, t.phone_number) AS phone,
                b.package_name,
                b.location,
                b.booking_type,
                b.status,
                b.is_complete,
                b.tour_type,
                b.num_adults,
                b.num_children,
                b.pax,
                b.grand_total,
                b.payment_amount,
                b.remaining_balance,
                b.payment_method,
                b.booking_date AS `date`,
                b.created_at,   -- ADD THIS LINE
                b.booking_date,
                CASE 
                    WHEN b.booking_type = 'package' THEN b.package_name
                    WHEN b.booking_type IN ('boat', 'tourguide') THEN b.location
                    ELSE ''
                END AS display_name
            FROM bookings b
            LEFT JOIN tourist t ON b.tourist_id = t.tourist_id
            ORDER BY b.booking_id DESC

        ");

        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode($results);

    } catch(Exception $e){
        echo json_encode(['error' => $e->getMessage()]);
    }

    exit();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>iTour Mercedes - Admin: Bookings</title>
<link rel="icon" type="image/png" href="img/newlogo.png">
<link href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;500;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
<link rel="stylesheet" href="styles/admin_panel_theme.css" />
<link rel="stylesheet" href="styles/adbookings.css?v=31" />
<link rel="stylesheet" href="styles/admin_receipt.css?v=2" />
</head>
<style>
/* Overlay */
.payment-modal-overlay {
  position: fixed;
  inset: 0;
  background: rgba(15, 23, 42, 0.55);
  display: none;
  justify-content: center;
  align-items: center;
  z-index: 9999;
}

.swal2-container {
  z-index: 1000000 !important;
}

/* Modal Box */
.payment-modal {
  width: 420px;
  max-width: 92%;
  background: #ffffff;
  border-radius: 14px;
  padding: 22px 24px;
  box-shadow: 0 20px 60px rgba(0,0,0,0.25);
  font-family: Arial, sans-serif;
}

/* Title */
.payment-title {
  font-size: 18px;
  font-weight: 700;
  color: #111827;
  margin-bottom: 12px;
}

/* Info box */
.payment-info {
  background: #f3f4f6;
  padding: 10px 12px;
  border-radius: 8px;
  font-size: 13px;
  color: #374151;
  margin-bottom: 12px;
}

/* Label */
.payment-label {
  font-size: 13px;
  font-weight: 600;
  color: #374151;
  display: block;
  margin-bottom: 6px;
}

.payment-label.payment-amount-label {
  margin-top: 14px;
}

/* Dropdown */
.payment-select {
  width: 100%;
  padding: 10px 12px;
  border-radius: 8px;
  border: 1px solid #d1d5db;
  font-size: 14px;
  background: #fff;
  outline: none;
  transition: 0.2s ease;
  margin-bottom: 18px;
}

.payment-select:focus {
  border-color: #2563eb;
  box-shadow: 0 0 0 3px rgba(37,99,235,0.15);
}

/* Buttons container */
.payment-actions {
  display: flex;
  justify-content: flex-end;
  gap: 10px;
}

/* Confirm button */
.btn-confirm {
  background: #2b7a66;
  color: #fff;
  border: none;
  padding: 10px 14px;
  border-radius: 8px;
  font-size: 13px;
  font-weight: 600;
  cursor: pointer;
  transition: 0.2s ease;
}

.btn-confirm:hover {
  background: #0a3b2f;
}

/* Cancel button */
.btn-cancel {
  background: #f3f4f6;
  color: #111827;
  border: 1px solid #d1d5db;
  padding: 10px 14px;
  border-radius: 8px;
  font-size: 13px;
  font-weight: 600;
  cursor: pointer;
  transition: 0.2s ease;
}

.btn-cancel:hover {
  background: #e5e7eb;
}

/* =========================
   MODAL OVERLAY
========================= */
.expense-overlay {
    position: fixed;
    inset: 0;
    display: none;
    align-items: center;
    justify-content: center;
    padding: 24px;
    background: rgba(8, 31, 26, 0.68);
    backdrop-filter: blur(6px);
    z-index: 99999;
    animation: fadeIn 0.18s ease-out;
}

/* =========================
   MODAL BOX
========================= */
.expense-modal-box {
    width: 100%;
    max-width: 540px;
    max-height: calc(100vh - 48px);
    background: #ffffff;
    border: 1px solid rgba(43, 122, 102, 0.18);
    border-radius: 18px;
    padding: 0;
    overflow: auto;
    box-shadow: 0 28px 80px rgba(5, 30, 24, 0.3);
    animation: slideUp 0.22s ease-out;
}

.expense-modal-header {
    display: grid;
    grid-template-columns: 46px minmax(0, 1fr) 38px;
    align-items: center;
    gap: 13px;
    padding: 21px 24px;
    background: linear-gradient(135deg, #f4faf7 0%, #ffffff 78%);
    border-bottom: 1px solid #dce9e4;
}

.expense-heading-icon {
    width: 46px;
    height: 46px;
    display: grid;
    place-items: center;
    color: #216b58;
    background: #e3f2ec;
    border: 1px solid #c7e4d9;
    border-radius: 13px;
}

.expense-heading-icon svg {
    width: 23px;
    height: 23px;
    fill: none;
    stroke: currentColor;
    stroke-width: 1.8;
    stroke-linecap: round;
    stroke-linejoin: round;
}

.expense-heading-copy span {
    display: block;
    margin-bottom: 3px;
    color: #638078;
    font-size: 10px;
    font-weight: 800;
    letter-spacing: .12em;
}

.expense-title {
    margin: 0;
    color: #173b32;
    font-size: 21px;
    font-weight: 700;
    line-height: 1.2;
}

.expense-heading-copy p {
    margin: 4px 0 0;
    color: #657c75;
    font-size: 12px;
    line-height: 1.45;
}

.expense-icon-close {
    width: 36px;
    height: 36px;
    display: grid;
    place-items: center;
    padding: 0;
    color: #526b64;
    background: #ffffff;
    border: 1px solid #d6e3de;
    border-radius: 10px;
    font-size: 24px;
    line-height: 1;
    cursor: pointer;
    transition: background .18s ease, border-color .18s ease, color .18s ease;
}

.expense-icon-close:hover {
    color: #173b32;
    background: #edf6f2;
    border-color: #b9d5ca;
}

/* =========================
   FORM
========================= */
.expense-form {
    padding: 22px 24px 0;
}

.expense-form .form-group {
    display: flex;
    flex-direction: column;
    gap: 7px;
    margin-bottom: 17px;
}

.expense-form .form-group label {
    color: #294c42;
    font-size: 12px;
    font-weight: 700;
    letter-spacing: .015em;
}

.expense-required {
    color: #b53c49;
}

.expense-optional {
    color: #7b908a;
    font-weight: 500;
}

.expense-field-help {
    margin: -1px 0 0;
    color: #7b908a;
    font-size: 11px;
    line-height: 1.45;
}

/* =========================
   INPUTS
========================= */
.expense-input {
    width: 100%;
    min-height: 46px;
    padding: 11px 13px;
    color: #18352e;
    background: #ffffff;
    border: 1px solid #cfded8;
    border-radius: 10px;
    box-sizing: border-box;
    font: inherit;
    font-size: 13px;
    outline: none;
    transition: border-color .18s ease, box-shadow .18s ease, background .18s ease;
}

.expense-input:hover {
    border-color: #a9c5bb;
}

.expense-input:focus {
    border-color: #2b7a66;
    box-shadow: 0 0 0 3px rgba(43, 122, 102, 0.13);
}

.expense-amount-wrap {
    position: relative;
}

.expense-currency {
    position: absolute;
    left: 13px;
    top: 50%;
    transform: translateY(-50%);
    color: #216b58;
    font-size: 15px;
    font-weight: 800;
    pointer-events: none;
}

.expense-amount-wrap .expense-input {
    padding-left: 35px;
}

/* TEXTAREA */
.expense-input.textarea {
    min-height: 104px;
    resize: vertical;
    line-height: 1.55;
}

/* =========================
   ACTION BUTTONS
========================= */
.expense-actions {
    display: flex;
    justify-content: flex-end;
    gap: 10px;
    margin: 22px -24px 0;
    padding: 16px 24px;
    background: #f7faf9;
    border-top: 1px solid #e0ebe7;
}

.expense-actions .expense-action-btn {
    min-width: 128px;
    min-height: 42px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    padding: 10px 16px;
    border-radius: 9px;
    font-size: 12px;
    font-weight: 700;
    cursor: pointer;
    transition: transform .18s ease, background .18s ease, border-color .18s ease, box-shadow .18s ease;
}

.expense-action-btn svg {
    width: 16px;
    height: 16px;
    fill: none;
    stroke: currentColor;
    stroke-width: 2;
    stroke-linecap: round;
    stroke-linejoin: round;
}

/* SAVE BUTTON */
.expense-action-btn.accept {
    background: #2b7a66;
    color: #fff;
    border: 1px solid #2b7a66;
    box-shadow: 0 6px 14px rgba(43, 122, 102, .18);
}

.expense-action-btn.accept:hover {
    background: #1f6654;
    border-color: #1f6654;
    transform: translateY(-1px);
}

/* CANCEL BUTTON */
.expense-action-btn.cancel {
    color: #36544c;
    background: #ffffff;
    border: 1px solid #cbdad5;
}

.expense-action-btn.cancel:hover {
    background: #edf5f2;
    border-color: #aec8be;
}

/* =========================
   ANIMATIONS
========================= */
@keyframes fadeIn {
    from { opacity: 0; }
    to { opacity: 1; }
}

@keyframes slideUp {
    from {
        opacity: 0;
        transform: translateY(20px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

.expense-overlay.active {
    display: flex;
}

@media (max-width: 600px) {
    .expense-overlay { padding: 12px; align-items: flex-end; }
    .expense-modal-box { max-height: calc(100vh - 24px); border-radius: 16px; }
    .expense-modal-header { grid-template-columns: 42px minmax(0, 1fr) 36px; padding: 18px; }
    .expense-heading-icon { width: 42px; height: 42px; }
    .expense-form { padding: 19px 18px 0; }
    .expense-actions { margin-left: -18px; margin-right: -18px; padding: 14px 18px; }
    .expense-actions .expense-action-btn { flex: 1; min-width: 0; }
}

.payment-label {
    display: block;
    font-size: 13px;
    font-weight: 600;
    color: #374151;
    margin-bottom: 6px;
    letter-spacing: 0.2px;
}

.payment-input {
    width: 100%;
    padding: 11px 12px;

    font-size: 14px;
    color: #111827;

    border: 1px solid #d1d5db;
    border-radius: 10px;

    background: #ffffff;

    outline: none;

    transition: all 0.2s ease;
    box-shadow: 0 1px 2px rgba(0,0,0,0.04);
}

/* Focus state */
.payment-input:focus {
    border-color: #2563eb;
    box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.15);
}

/* Hover effect */
.payment-input:hover {
    border-color: #9ca3af;
}

/* Remove number arrows (clean UI) */
.payment-input::-webkit-outer-spin-button,
.payment-input::-webkit-inner-spin-button {
    -webkit-appearance: none;
    margin: 0;
}

.payment-input {
    width: 100%;
    max-width: 100%;
    box-sizing: border-box;
    margin-bottom: 20px
}

/* Booking acceptance email progress dialog */
.booking-email-swal-popup {
    width: min(510px, calc(100vw - 32px));
    padding: 26px 28px 24px;
    border-radius: 6px;
}

.booking-email-swal-title {
    padding: 0;
    color: #3f4543;
    font-size: clamp(25px, 3vw, 31px);
    font-weight: 700;
    line-height: 1.2;
}

.booking-email-swal-content {
    margin: 14px 0 4px;
    color: #535b58;
    font-size: 17px;
    line-height: 1.45;
}

.booking-email-swal-popup .swal2-actions {
    min-height: 58px;
    margin: 18px 0 0;
}

.booking-email-swal-loader {
    width: 48px;
    height: 48px;
    margin: 0;
    border-width: 4px;
    border-color: #176b58 transparent #176b58 transparent;
}
</style>
<body>
<div class="admin-container">
<?php include 'admin_sidebar.php'; ?>
<main class="main-content">
<header class="admin-header admin-page-header">
  <div class="admin-header-left admin-page-title">
    <span class="admin-page-title-icon" aria-hidden="true">
      <svg viewBox="0 0 24 24"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M7 3v4M17 3v4M3 10h18"/><path d="m8 15 2.2 2.2L16 12"/></svg>
    </span>
    <div class="admin-page-title-copy">
      <h2>Booking Management</h2>
      <p class="admin-header-subtitle">Review reservations, payments, and booking activity</p>
    </div>
  </div>
  <div class="admin-header-right">
    <form id="overviewFilterForm" method="GET" class="ad-global-filter-form">
      <input type="hidden" name="tab" value="<?= htmlspecialchars($activeTab) ?>">
      <input type="hidden" name="sort_by" value="<?= htmlspecialchars($sortBy) ?>">
      <input type="hidden" name="rows" value="<?= (int)$rowsPerPage ?>">
      <input type="hidden" name="status" value="<?= htmlspecialchars($statusFilter) ?>">
      <input type="hidden" name="search" value="<?= htmlspecialchars($search_q) ?>">
      <label for="adRangeFilter" class="ad-global-filter-label">Overview Filter</label>
      <select id="adRangeFilter" name="range" class="ad-global-filter-select">
        <option value="all" <?= $rangeFilter === 'all' ? 'selected' : '' ?>>All</option>
        <option value="yearly" <?= $rangeFilter === 'yearly' ? 'selected' : '' ?>>Yearly</option>
        <option value="monthly" <?= $rangeFilter === 'monthly' ? 'selected' : '' ?>>Monthly</option>
        <option value="daily" <?= $rangeFilter === 'daily' ? 'selected' : '' ?>>Daily</option>
      </select>
      <select id="adRangeYear" name="year" class="ad-global-filter-select">
        <?php foreach($years as $y): ?>
          <option value="<?= (int)$y ?>" <?= (int)$selectedYear === (int)$y ? 'selected' : '' ?>><?= (int)$y ?></option>
        <?php endforeach; ?>
      </select>
      <select id="adRangeMonth" name="month" class="ad-global-filter-select">
        <?php for ($m = 1; $m <= 12; $m++): ?>
          <option value="<?= $m ?>" <?= (int)$selectedMonth === $m ? 'selected' : '' ?>><?= date('F', mktime(0,0,0,$m,1)) ?></option>
        <?php endfor; ?>
      </select>
      <input id="adRangeDate" type="date" name="date" class="ad-global-filter-select ad-global-filter-date" value="<?= htmlspecialchars($selectedDate) ?>">
      <button type="submit" class="ad-global-filter-apply">Apply</button>
    </form>
    <button class="btn-generate-report" id="openReportModal">
      <img src="https://img.icons8.com/ios-filled/24/ffffff/document.png" alt="icon">
      Generate Report
    </button>
  </div>
</header>

<!-- Report Modal -->
<div class="af-modal-overlay" id="reportModal" report-mode="1">
  <div class="af-modal" style= "padding:24px; border-radius:12px;">

    <!-- Header -->
    <div style="display:flex; justify-content:center; position:relative; padding-bottom:12px; border-bottom:1px solid #eee;">
      <strong style="font-size:1.2rem; color:#2b7a66;">Booking Report Preview</strong>
      <button id="closeReportModal" style="position:absolute; top:8px; right:8px; border:none; background:none; font-size:1.2rem; cursor:pointer; color:#666;">×</button>
    </div>

    <!-- Filter Panel -->
    <div class="report-filter-panel" style="display:flex; flex-wrap:wrap; gap:12px; margin-top:16px; align-items:center;">
      <!-- Hidden input for report mode -->
      <input type="hidden" id="reportMode" value="1">

      <select id="reportFilterMode" style="padding:8px 10px; border-radius:8px; border:1px solid #ccc; font-family:'Poppins', sans-serif;">
        <option value="all">All</option>
        <option value="yearly">Yearly</option>
        <option value="monthly">Monthly</option>
      </select>

      <select id="reportFilterYear" style="padding:8px 10px; border-radius:8px; border:1px solid #ccc; font-family:'Poppins', sans-serif;">
        <option value="">Select Year</option>
        <?php foreach($years as $y): ?>
        <option value="<?= $y ?>"><?= $y ?></option>
        <?php endforeach; ?>
      </select>

      <select id="reportFilterMonth" style="padding:8px 10px; border-radius:8px; border:1px solid #ccc; font-family:'Poppins', sans-serif;">
        <option value="">Select Month</option>
        <?php foreach(range(1,12) as $m): ?>
        <option value="<?= $m ?>"><?= date("F", mktime(0,0,0,$m,1)) ?></option>
        <?php endforeach; ?>
      </select>

      <select id="reportPaperSize" style="padding:8px 10px; border-radius:8px; border:1px solid #ccc; font-family:'Poppins', sans-serif;">
        <option value="letter">Letter</option>
        <option value="A4" selected>A4</option>
        <option value="long">Legal</option>
      </select>

        <select id="reportOrientation" style="padding:8px 10px; border-radius:8px; border:1px solid #ccc; font-family:'Poppins', sans-serif;">
            <option value="portrait" selected>Portrait</option>
            <option value="landscape">Landscape</option>
        </select>


      <button class="af-btn" id="applyReportFilter" style="margin-left:auto;">Apply Filter</button>
    </div>

    <!-- Report Preview -->
    <div class="report-preview" id="reportPreview" style="margin-top:20px; background:#f9fafb; padding:16px; border-radius:10px; box-shadow:0 4px 12px rgba(0,0,0,0.05);">
      <div id="reportContent"><div class="report-empty-state"><strong>Booking report preview</strong><span>Select the coverage and click “Apply Filter” to generate the formal report.</span></div></div>
    </div>

    <!-- Footer -->
    <div class="af-actions" style="margin-top:20px; display:flex; justify-content:flex-end; gap:10px; align-items:center;">
      <button class="af-btn report-download-csv" id="downloadReportCsv" disabled>
        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3v12m0 0 4-4m-4 4-4-4"/><path d="M5 17v3h14v-3"/></svg>
        Download CSV
      </button>
      <button class="af-btn" id="downloadReport">Download PDF</button>
      <button class="af-btn" id="printReport">Print Preview</button>
      <button class="af-btn secondary" id="closeReportModalFooter">Close</button>
    </div>

  </div>
</div>


<div class="card bookings-card">
<div class="bookings-head">
  <h3><?= $activeTab === 'cancellations' ? 'Cancellation Requests' : 'All Bookings' ?></h3>
  <div class="bookings-head-actions">
    <div class="tabs">
      <button class="tab-btn <?= $activeTab==='all'?'active':'' ?>" data-tab="all">All</button>
      <button class="tab-btn <?= $activeTab==='pending'?'active':'' ?>" data-tab="pending">Pending</button>
      <button class="tab-btn <?= $activeTab==='accepted'?'active':'' ?>" data-tab="accepted">Accepted</button>
      <button class="tab-btn <?= $activeTab==='completed'?'active':'' ?>" data-tab="completed">Completed</button>
      <button class="tab-btn <?= $activeTab==='cancellations'?'active':'' ?>" data-tab="cancellations">
        Cancellation Requests
        <?php if ($cancellationSummary['pending'] > 0): ?><span class="tab-count"><?= $cancellationSummary['pending'] ?></span><?php endif; ?>
      </button>
    </div>
    <button type="button" class="add-booking-btn payment-phone-btn" id="openAdminPaymentPhoneModal">
      <svg aria-hidden="true" viewBox="0 0 24 24">
        <rect x="6" y="2" width="12" height="20" rx="2"></rect>
        <path d="M10 18h4"></path>
      </svg>
      <span>Payment Phone</span>
    </button>
    <button type="button" class="add-booking-btn" id="openWalkinBookingModal">
      <svg aria-hidden="true" viewBox="0 0 24 24">
        <path d="M12 5v14M5 12h14"></path>
      </svg>
      <span>Add Booking</span>
    </button>
  </div>
</div>

<?php if ($activeTab === 'cancellations'): ?>
<form id="searchForm" class="booking-toolbar cancellation-toolbar" method="GET">
  <input type="hidden" name="tab" value="cancellations">
  <span class="rows-label">Rows</span>
  <input type="number" name="rows" class="input rows-input" min="1" max="300" step="1" value="<?= (int)$rowsPerPage ?>">
  <select name="cancel_status" class="select status-select">
    <option value="" <?= $cancellationStatusFilter === '' ? 'selected' : '' ?>>All Requests</option>
    <option value="pending" <?= $cancellationStatusFilter === 'pending' ? 'selected' : '' ?>>Awaiting Approval</option>
    <option value="approved" <?= $cancellationStatusFilter === 'approved' ? 'selected' : '' ?>>Approved</option>
    <option value="refund_pending" <?= $cancellationStatusFilter === 'refund_pending' ? 'selected' : '' ?>>Refund Required</option>
    <option value="refunded" <?= $cancellationStatusFilter === 'refunded' ? 'selected' : '' ?>>Refund Completed</option>
    <option value="completed" <?= $cancellationStatusFilter === 'completed' ? 'selected' : '' ?>>Workflow Completed</option>
    <option value="rejected" <?= $cancellationStatusFilter === 'rejected' ? 'selected' : '' ?>>Not Approved</option>
  </select>
  <button type="submit" class="btn-primary toolbar-apply-btn">Apply Filter</button>
  <input type="text" name="search" class="search-input" placeholder="Search tourist, reference, or service" value="<?= htmlspecialchars($search_q) ?>">
</form>
<?php else: ?>
<form id="searchForm" class="booking-toolbar" method="GET">
  <input type="hidden" name="tab" value="<?= htmlspecialchars($activeTab) ?>">
  <input type="hidden" name="range" value="<?= htmlspecialchars($rangeFilter) ?>">
  <input type="hidden" name="year" value="<?= (int)$selectedYear ?>">
  <input type="hidden" name="month" value="<?= (int)$selectedMonth ?>">
  <input type="hidden" name="date" value="<?= htmlspecialchars($selectedDate) ?>">
  <span class="rows-label">Rows</span>
  <input type="number" id="rows" name="rows" class="input rows-input" min="1" max="300" step="1" value="<?= (int)$rowsPerPage ?>">
  <select name="status" class="select status-select">
    <option value="" <?= $statusFilter===''?'selected':'' ?>>All Statuses</option>
    <?php if ($activeTab === 'all'): ?>
      <option value="pending" <?= $statusFilter==='pending'?'selected':'' ?>>Pending</option>
      <option value="accepted" <?= $statusFilter==='accepted'?'selected':'' ?>>Accepted</option>
    <?php endif; ?>
    <option value="completed" <?= $statusFilter==='completed'?'selected':'' ?>>Completed</option>
    <option value="declined" <?= $statusFilter==='declined'?'selected':'' ?>>Declined</option>
    <option value="cancelled" <?= $statusFilter==='cancelled'?'selected':'' ?>>Cancelled</option>
  </select>
  <select id="sort_by" name="sort_by" class="select sort-select">
    <?php
      $sortBy = $_GET['sort_by'] ?? 'time';
      if ($activeTab === 'completed') {
          $options = [
              'time' => 'Sort: Latest (Default)',
              'name' => 'Sort: Name (A-Z)',
              'completed' => 'Sort: Completed',
              'declined' => 'Sort: Declined',
              'cancelled' => 'Sort: Cancelled'
          ];
      } else {
          $options = [
              'time' => 'Sort: Latest (Default)',
              'name' => 'Sort: Name (A-Z)'
          ];
      }
      foreach ($options as $value => $label) {
          $selected = ($sortBy === $value) ? 'selected' : '';
          echo "<option value=\"$value\" $selected>$label</option>";
      }
    ?>
  </select>
  <button type="submit" class="btn-primary toolbar-apply-btn">Apply Filter</button>
  <input type="text" name="search" class="search-input" placeholder="Search by guest name or booking ID" value="<?= htmlspecialchars($search_q) ?>">
</form>
<?php endif; ?>

<?php
// ------------------------
// Render Bookings
// ------------------------
function renderBookings($bookings, $filter_status){
    // Filter bookings first
    $filteredBookings = array_filter($bookings, function($b) use ($filter_status) {
        $status = $b['status'] ?? 'pending';
        $is_complete = $b['is_complete'] ?? 'uncomplete';

        if($filter_status==='pending' && $status!=='pending') return false;
        if($filter_status==='accepted' && !($status==='accepted' && $is_complete==='uncomplete')) return false;
        if($filter_status==='completed' && !in_array($is_complete,['completed','cancelled','declined'])) return false;
        if($filter_status==='completed' && $is_complete==='cancelled'
            && !empty($b['active_cancellation_request_id'])
            && strtolower((string)($b['active_cancellation_status'] ?? '')) !== 'completed') return false;
        return true;
    });

    if(count($filteredBookings) === 0){
        echo "<tr><td colspan='8' style='text-align:center; color:#777;'>No bookings found.</td></tr>";
        return;
    }

    // Render rows
    foreach($filteredBookings as $b){
        $status = $b['status'] ?? 'pending';
        $is_complete = $b['is_complete'] ?? 'uncomplete';
        $rowCategory = in_array($is_complete, ['completed', 'cancelled', 'declined'], true)
            ? 'completed'
            : ($status === 'accepted' ? 'accepted' : 'pending');
        $actionContext = $filter_status === 'all' ? $rowCategory : $filter_status;
        $t_name = $b['t_full_name'] ?? 'Guest';
        $t_email = $b['t_email'] ?? '-';
        $t_phone = !empty($b['booking_phone']) ? $b['booking_phone'] : (!empty($b['t_phone']) ? $b['t_phone'] : '-');
        $t_address = $b['t_address'] ?? '-';
        $tNameEsc = htmlspecialchars($t_name);
        $tEmailEsc = htmlspecialchars($t_email);
        $tPhoneEsc = htmlspecialchars($t_phone);
        $finishedCounts = ['bookings' => (int)($b['t_completed_bookings'] ?? 0)];

        $resolvedPic = getProfileImg($b['t_profile_picture'] ?? '');
        $picEsc = htmlspecialchars($resolvedPic);
        $adults = (int)($b['num_adults'] ?? 0);
        $children = (int)($b['num_children'] ?? 0);
        $pax = (int)($b['pax'] ?? ($adults + $children));

        $created_month = !empty($b['created_month']) ? htmlspecialchars($b['created_month']) : '';
        $created_year = !empty($b['created_year']) ? htmlspecialchars($b['created_year']) : '';

        $bookingTypeRaw = strtolower(trim((string)($b['booking_type'] ?? '')));
        $booking_type = htmlspecialchars(ucfirst($bookingTypeRaw !== '' ? $bookingTypeRaw : 'n/a'));
        if (in_array($bookingTypeRaw, ['boat', 'tourguide'], true)) {
            $location = htmlspecialchars($b['location'] ?? '-');
        } elseif ($bookingTypeRaw === 'package') {
            $location = htmlspecialchars($b['package_name'] ?? '-');
        } else {
            $location = '-';
        }

        // Status pill logic
        $pill_classes = [
            'completed' => 'finished',
            'cancelled' => 'cancel',
            'declined'  => 'declined',
            'uncomplete'=> 'pending'
        ];

        $pill_labels = [
            'completed' => 'Completed',
            'cancelled' => 'Cancelled',
            'declined'  => 'Declined',
            'uncomplete'=> 'Pending'
        ];

        if ($rowCategory === 'completed') {
            $pill_class = $pill_classes[$is_complete] ?? 'pending';
            $pill_label = $pill_labels[$is_complete] ?? ucfirst($is_complete);
        } else {
            $pill_class = $rowCategory;
            $pill_label = ucfirst($rowCategory);
        }

$state_pill = "<span class='pill {$pill_class}'>".$pill_label."</span>";
$grandTotal = (float)($b['grand_total'] ?? 0);
$paymentAmount = (float)($b['payment_amount'] ?? 0);
$remainingBalance = (float)($b['remaining_balance'] ?? 0);

// -------------------------
// FIXED PAYMENT STATUS LOGIC
// -------------------------
if ((int)($b['is_paid'] ?? 0) === 1 || $remainingBalance <= 0) {
    $paymentStatus = [
        'label' => 'Paid',
        'class' => 'paid'
    ];
} elseif ($paymentAmount > 0 && $remainingBalance > 0) {
    $paymentStatus = [
        'label' => 'Partial',
        'class' => 'partial'
    ];
} else {
    $paymentStatus = [
        'label' => 'Unpaid',
        'class' => 'unpaid'
    ];
}

// -------------------------
// PAYMENT PILL UI
// -------------------------
$paymentPill = "
<div class='booking-payment {$paymentStatus['class']}'>
    <span class='booking-payment-capsule {$paymentStatus['class']}'>" . htmlspecialchars($paymentStatus['label']) . "</span>
";

if ($paymentStatus['label'] === 'Paid') {
    $paymentPill .= "<span class='booking-payment-balance'>Fully settled</span>";
} elseif ($remainingBalance > 0) {
    $paymentPill .= "
        <span class='booking-payment-balance'>
            Remaining Balance: ₱" . number_format($remainingBalance, 2) . "
        </span>
    ";
}

$paymentPill .= "</div>";     
          $viewDetailsBtn = "<button type='button' class='btn-details action-btn view' data-booking-id='" . (int)$b['booking_id'] . "'>View Details</button>";
          $activeCancellationRequestId = (int)($b['active_cancellation_request_id'] ?? 0);
          $activeCancellationStatus = strtolower((string)($b['active_cancellation_status'] ?? ''));
          $activeCancellationRefundStatus = strtolower((string)($b['active_cancellation_refund_status'] ?? ''));
          $activeCancellationRefundableAmount = max(0, (float)($b['active_cancellation_refundable_amount'] ?? 0));
          $showCancellationRequestState = $activeCancellationRequestId > 0;
          $cancellationWorkflowLabel = '';
          $cancellationWorkflowClass = 'pending';
          if ($activeCancellationStatus === 'decision_required') {
              $cancellationWorkflowLabel = 'Tourist Decision Required';
              $cancellationWorkflowClass = 'decision';
          } elseif ($activeCancellationStatus === 'pending') {
              $cancellationWorkflowLabel = 'Cancellation Request Pending';
          } elseif ($activeCancellationStatus === 'approved' && $activeCancellationRefundableAmount > 0.009) {
              if (in_array($activeCancellationRefundStatus, ['completed', 'refunded'], true)) {
                  $cancellationWorkflowLabel = 'Refund Completed';
                  $cancellationWorkflowClass = 'complete';
              } elseif ($activeCancellationRefundStatus === 'processing') {
                  $cancellationWorkflowLabel = 'Refund Processing';
                  $cancellationWorkflowClass = 'processing';
              } else {
                  $cancellationWorkflowLabel = 'Refund Requested';
                  $cancellationWorkflowClass = 'refund';
              }
          } elseif ($activeCancellationStatus === 'approved') {
              $cancellationWorkflowLabel = 'Cancellation Approved';
              $cancellationWorkflowClass = 'complete';
          }
          $cancellationRequestNotice = $showCancellationRequestState && $cancellationWorkflowLabel !== ''
              ? "<div class='booking-cancellation-notice {$cancellationWorkflowClass}'><span aria-hidden='true'></span>" . htmlspecialchars($cancellationWorkflowLabel) . "</div>"
              : '';
          
          
        // Only show Tourist Submission button on Accepted tab
        $touristSubmissionTd = '';
        if($filter_status === 'accepted'){
            $bookingId = $b['booking_id'];
            $stmtTourist = $GLOBALS['pdo']->prepare("SELECT COUNT(*) FROM booking_tourists WHERE booking_id = :bookingId");
            $stmtTourist->execute(['bookingId' => $bookingId]);
            $touristCount = (int)$stmtTourist->fetchColumn();

            $touristBtn = $touristCount > 0 
                ? "<button class='btn-tourists btn-primary' data-booking='$bookingId'>View Tourists</button>" 
                : "<button class='btn-tourists-nosub btn-disabled' disabled>No Submission Yet</button>";

            $touristSubmissionTd = "<td style='text-align:center;'>{$touristBtn}</td>";
        }


        echo "<tr class='booking-row'>
    <td class='booker-cell' style='width:32%;'>
        <div class='booker-cell-wrap'>
            <img src='{$picEsc}' class='profile-img hover-profile' alt='profile'
                onerror=\"this.onerror=null;this.src='../img/profileicon.png';\"
                role='button'
                tabindex='0'
                aria-label='Open full profile for ".htmlspecialchars($t_name)."'
                data-tourist-id='".htmlspecialchars($b['t_id'] ?? '')."'
                data-fullname='".htmlspecialchars($t_name)."' 
                data-email='".htmlspecialchars($t_email)."' 
                data-phone='".htmlspecialchars($t_phone)."' 
                data-address='".htmlspecialchars($t_address)."' 
                data-finished-bookings='".htmlspecialchars($finishedCounts['bookings'])."'
                data-total-bookings='".htmlspecialchars($b['t_total_bookings'] ?? 0)."'
                data-account-status='".htmlspecialchars($b['t_status'] ?? 'active')."'
                data-email-verified='".htmlspecialchars((int)($b['t_email_verified'] ?? 0))."'
                data-google-connected='".htmlspecialchars(!empty($b['t_google_id']) ? '1' : '0')."'
                data-created-at='".htmlspecialchars($b['t_created_at'] ?? '')."'
                data-updated-at='".htmlspecialchars($b['t_updated_at'] ?? '')."'
                data-ban-note='".htmlspecialchars($b['t_ban_note'] ?? '')."'
            >
            <div class='booker-details'>
                <div class='booker-name-row'>
                    <div class='profile-name'>{$tNameEsc}</div>
                </div>
                <div class='booker-meta-row'>
                    ".(!empty($created_month) ? "<div class='pill-month'>{$created_month} {$created_year}</div>" : "")."
                    <div class='profile-email'>{$tEmailEsc}</div>
                </div>
                {$cancellationRequestNotice}
            </div>
        </div>
    </td>
    <td><span class='booking-reference'>".htmlspecialchars(BookingReferenceDisplay($b))."</span></td>
    <td><span class='booking-type-chip'>{$booking_type}</span></td>";

// Only show Pax for tabs other than 'accepted'
if ($filter_status !== 'accepted') {
    $guestLabel = $pax === 1 ? 'guest' : 'guests';
    $adultLabel = $adults === 1 ? 'adult' : 'adults';
    $childLabel = $children === 1 ? 'child' : 'children';
    echo "<td class='pax-cell'>
        <div class='pax-summary' aria-label='{$pax} {$guestLabel}: {$adults} {$adultLabel} and {$children} {$childLabel}'>
            <span class='pax-icon' aria-hidden='true'>
                <svg viewBox='0 0 24 24'><path d='M16 20v-1.5a4.5 4.5 0 0 0-4.5-4.5h-3A4.5 4.5 0 0 0 4 18.5V20'/><circle cx='10' cy='7' r='3.5'/><path d='M16 4.4a3.5 3.5 0 0 1 0 6.7M18 14.2a4.5 4.5 0 0 1 2 3.8v2'/></svg>
            </span>
            <span class='pax-copy'>
                <span class='pax-total'><strong>{$pax}</strong> {$guestLabel}</span>
                <span class='pax-breakdown'><b>{$adults}</b> {$adultLabel}<i></i><b>{$children}</b> {$childLabel}</span>
            </span>
        </div>
    </td>";
}

echo "<td><span class='booking-date'>".htmlspecialchars($b['booking_date'])."</span></td>
    <td>{$paymentPill}</td>
    <td>{$state_pill}</td>
    {$touristSubmissionTd}
    <td style='width:auto;'>";

if ($showCancellationRequestState) {
    echo "<a class='view-cancellation-link' href='adbookings.php?tab=cancellations&amp;focus_request={$activeCancellationRequestId}'>View</a>";
} else {
    echo "
      <div class='row-actions'>
        <button type='button' class='action-toggle-btn' aria-haspopup='menu' aria-expanded='false'>Actions</button>
        <div class='action-menu'>
          {$viewDetailsBtn}";

// ------------------------
// Action buttons
// ------------------------
if ($actionContext === 'pending') {

    $pendingReturnTab = $filter_status === 'all' ? 'all' : 'pending';
    $completedReturnTab = $filter_status === 'all' ? 'all' : 'completed';

    // ACCEPT stays the same
    echo "
    <form method='POST' class='inline-form' 
        onsubmit='return confirmAction(event, this, \"accept\");'>
        <input type='hidden' name='id' value='".htmlspecialchars($b['booking_id'])."'>
        <input type='hidden' name='action' value='accept'>
        <input type='hidden' name='csrf_token' value='" . htmlspecialchars(AppCsrfToken('admin', 'booking_management'), ENT_QUOTES, 'UTF-8') . "'>
        <input type='hidden' name='return_tab' value='{$pendingReturnTab}'>
        <button class='action-btn accept' type='submit'>Accept</button>
    </form>

    <!-- DECLINE now opens decision reason modal -->
    <form method='POST' class='inline-form'
        onsubmit='return openDecisionModal(event, this, \"decline\", \"{$completedReturnTab}\");'>
        <input type='hidden' name='id' value='".htmlspecialchars($b['booking_id'])."'>
        <input type='hidden' name='action' value='decline'>
        <input type='hidden' name='csrf_token' value='" . htmlspecialchars(AppCsrfToken('admin', 'booking_management'), ENT_QUOTES, 'UTF-8') . "'>
        <input type='hidden' name='return_tab' value='{$completedReturnTab}'>
        <button class='action-btn decline' type='submit'>Decline</button>
    </form>
    ";

} elseif ($actionContext === 'accepted') {

    $bookingId = htmlspecialchars($b['booking_id']);
    $remainingBalance = (float)($b['remaining_balance'] ?? 0);
    $completedReturnTab = $filter_status === 'all' ? 'all' : 'completed';
    $acceptedReturnTab = $filter_status === 'all' ? 'all' : 'accepted';
    $emailDeliveryStatus = strtolower((string)($b['confirmation_email_status'] ?? 'not_sent'));
    $retryEmailAction = '';
    if ($emailDeliveryStatus === 'failed' && filter_var((string)($b['t_email'] ?? ''), FILTER_VALIDATE_EMAIL)) {
        $retryEmailAction = "
          <form method='POST' class='inline-form' onsubmit='return confirmAction(event, this, \"retry_email\");'>
            <input type='hidden' name='id' value='{$bookingId}'>
            <input type='hidden' name='action' value='retry_confirmation_email'>
            <input type='hidden' name='csrf_token' value='" . htmlspecialchars(AppCsrfToken('admin', 'booking_management'), ENT_QUOTES, 'UTF-8') . "'>
            <input type='hidden' name='return_tab' value='{$acceptedReturnTab}'>
            <button class='action-btn accept retry-email-btn' type='submit'>Retry Confirmation Email</button>
          </form>";
    }
    $adminCancelReference = htmlspecialchars((string)($b['booking_reference'] ?? ('BOOKING-' . $bookingId)), ENT_QUOTES, 'UTF-8');
    $adminCancelTourist = htmlspecialchars((string)($b['t_full_name'] ?? 'Tourist'), ENT_QUOTES, 'UTF-8');
    $adminCancelDate = htmlspecialchars((string)($b['booking_date'] ?? ''), ENT_QUOTES, 'UTF-8');
    $adminCancelEndDate = htmlspecialchars(tourResourceEndDate($b), ENT_QUOTES, 'UTF-8');
    $adminCancelPaid = htmlspecialchars(number_format(max(0, (float)($b['payment_amount'] ?? 0)), 2, '.', ''), ENT_QUOTES, 'UTF-8');

    echo "
<div class='inline-form'>

    <input type='hidden' name='id' value='{$bookingId}'>
    <input type='hidden' name='action' value='finish'>
    <input type='hidden' name='return_tab' value='{$completedReturnTab}'>

    <button
        type='button'
        class='action-btn finish mark-complete-btn'
        data-id='{$bookingId}'
        style='margin-bottom:5px;'
        data-balance='{$remainingBalance}'>
        Mark Completed
    </button>

    <form method='POST' onsubmit='return openAdminCancellationWorkflow(event, this);' style='display:inline;'
          data-booking-reference='{$adminCancelReference}' data-tourist-name='{$adminCancelTourist}'
          data-booking-date='{$adminCancelDate}' data-booking-end-date='{$adminCancelEndDate}' data-amount-paid='{$adminCancelPaid}'>
        <input type='hidden' name='id' value='{$bookingId}'>
        <input type='hidden' name='action' value='admin_provider_cancel'>
        <input type='hidden' name='return_tab' value='{$acceptedReturnTab}'>
        <input type='hidden' name='csrf_token' value='" . htmlspecialchars((string)$_SESSION['cancellation_admin_csrf'], ENT_QUOTES, 'UTF-8') . "'>
        <button class='action-btn cancel' type='submit'>Cancel Booking</button>
    </form>

    <button
        type='button'
        class='action-btn billing billing-btn'
        data-id='{$bookingId}'>
        Billing
    </button>

    {$retryEmailAction}

</div>
";
}

echo "    </div>
      </div>";
}

echo "</td></tr>";

    }
}
?>


<?php 
function renderAdminCancellationRequests(array $requests): void
{
    if (!$requests) {
        echo "<tr><td colspan='8' class='cancellation-empty'>No cancellation requests found.</td></tr>";
        return;
    }

    foreach ($requests as $request) {
        $requestId = (int)$request['cancellation_request_id'];
        $touristName = trim((string)($request['tourist_name'] ?? '')) ?: 'Guest';
        $touristEmail = trim((string)($request['tourist_email'] ?? '')) ?: '-';
        $profileImage = getProfileImg((string)($request['tourist_profile_picture'] ?? ''));
        $domain = strtolower((string)$request['booking_domain']);
        $bookingType = $domain === 'hotel' ? 'Hotel' : ucfirst((string)$request['booking_type']);
        $serviceEndDate = $domain === 'tour'
            ? tourResourceEndDate([
                'booking_date' => (string)$request['service_date'],
                'tour_type' => (string)($request['source_tour_type'] ?? ''),
                'tour_range' => (string)($request['source_tour_range'] ?? ''),
            ])
            : (string)$request['service_date'];
        $requestStatus = strtolower((string)$request['request_status']);
        $refundStatus = strtolower((string)$request['refund_status']);
        $refundableAmount = (float)$request['refundable_amount'];
        $refundFinished = in_array($refundStatus, ['completed', 'refunded'], true);
        $canComplete = $requestStatus === 'approved' && ($refundableAmount <= 0.009 || $refundFinished);
        $requestLabel = bookingCancellationRequestStatusLabel($requestStatus);
        $refundLabel = bookingCancellationRefundStatusLabel($refundStatus, $refundableAmount);
        $policyLabel = bookingCancellationPolicyLabel((string)$request['refund_policy']);
        $requestClass = in_array($requestStatus, ['approved', 'completed', 'rescheduled'], true) ? 'success' : ($requestStatus === 'rejected' ? 'danger' : 'pending');
        $refundClass = $refundFinished ? 'success' : ($refundableAmount <= 0.009 ? 'neutral' : 'pending');
        $details = htmlspecialchars(json_encode([
            'tourist' => $touristName,
            'email' => $touristEmail,
            'phone' => (string)($request['tourist_phone'] ?? ''),
            'profile_image' => $profileImage,
            'profile_endpoint' => $domain === 'tour' ? 'adbookings.php?action=fetchTouristProfileImage&id=' . (int)$request['booking_id'] : '',
            'reference' => (string)$request['booking_reference'],
            'type' => $bookingType,
            'service' => (string)$request['service_name'],
            'service_date' => (string)$request['service_date'],
            'service_end_date' => $serviceEndDate,
            'tour_type' => (string)($request['source_tour_type'] ?? ''),
            'requested_at' => (string)$request['requested_at'],
            'days_before' => (int)$request['days_before_service'],
            'reason' => (string)$request['cancellation_reason'],
            'admin_note' => (string)($request['admin_note'] ?? ''),
            'total' => (float)$request['total_amount'],
            'paid' => (float)$request['amount_paid'],
            'refundable' => $refundableAmount,
            'non_refundable' => (float)$request['non_refundable_amount'],
            'policy' => $policyLabel,
            'request_status' => $requestLabel,
            'refund_status' => $refundLabel,
            'refund_destination' => !empty($request['refund_destination_institution'])
                ? (string)$request['refund_destination_institution'] . (!empty($request['refund_destination_last4']) ? ' ending in ' . (string)$request['refund_destination_last4'] : '')
                : 'Not provided',
            'refund_destination_verified' => !empty($request['refund_destination_verified_at']) ? 'Verified by tourist' : 'Pending tourist details',
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8');

        echo "<tr class='cancellation-request-row' id='cancel-request-{$requestId}' data-cancellation-request-id='{$requestId}'>
          <td><div class='cancel-tourist'><img src='" . htmlspecialchars($profileImage) . "' alt='' onerror=\"this.onerror=null;this.src='img/profileicon.png'\"><div><strong>" . htmlspecialchars($touristName) . "</strong><span>" . htmlspecialchars($touristEmail) . "</span></div></div></td>
          <td><strong class='cancel-reference'>" . htmlspecialchars((string)$request['booking_reference']) . "</strong><span class='cancel-subtext'>" . htmlspecialchars($bookingType) . "</span></td>
          <td><strong>" . htmlspecialchars((string)$request['service_name']) . "</strong><span class='cancel-subtext'>Service: " . htmlspecialchars(date('M j, Y', strtotime((string)$request['service_date']))) . "</span></td>
          <td><strong>" . htmlspecialchars(date('M j, Y', strtotime((string)$request['requested_at']))) . "</strong><span class='cancel-subtext'>" . (int)$request['days_before_service'] . " days before</span></td>
          <td><span class='cancel-policy'>" . htmlspecialchars($policyLabel) . "</span><strong class='cancel-money'>₱" . number_format($refundableAmount, 2) . "</strong><span class='cancel-subtext'>of ₱" . number_format((float)$request['amount_paid'], 2) . " paid</span></td>
          <td><span class='cancel-status {$requestClass}'>" . htmlspecialchars($requestLabel) . "</span></td>
          <td><span class='cancel-status {$refundClass}'>" . htmlspecialchars($refundLabel) . "</span></td>
          <td><div class='row-actions cancellation-row-actions'>
            <button type='button' class='action-toggle-btn' aria-haspopup='menu' aria-expanded='false'>Actions</button>
            <div class='action-menu cancellation-action-menu'>
            <button type='button' class='action-btn view view-cancellation-request' data-request='{$details}'>View Details</button>";

        if (strtolower((string)($request['initiated_by'] ?? '')) === 'admin_provider'
            && in_array($requestStatus, ['decision_required', 'approved', 'rescheduled'], true)) {
            $successfulEmailCount = max(0, (int)($request['provider_email_sent_count'] ?? 0));
            $emailCountLabel = $successfulEmailCount === 1 ? '1 email sent' : $successfulEmailCount . ' emails sent';
            echo "<form method='post' class='inline-form provider-email-retry-form'>
                <input type='hidden' name='csrf_token' value='" . htmlspecialchars((string)$_SESSION['cancellation_admin_csrf']) . "'>
                <input type='hidden' name='cancellation_request_id' value='{$requestId}'>
                <input type='hidden' name='action' value='retry_provider_cancellation_email'>
                <button type='submit' class='action-btn accept provider-email-retry-btn'><span>Resend Tourist Email</span><small class='provider-email-count'>" . htmlspecialchars($emailCountLabel) . "</small></button>
              </form>";
        }

        if ($requestStatus === 'pending') {
            echo "<form method='post' class='cancellation-action-form' data-action='approve'>
                <input type='hidden' name='csrf_token' value='" . htmlspecialchars((string)$_SESSION['cancellation_admin_csrf']) . "'>
                <input type='hidden' name='cancellation_request_id' value='{$requestId}'>
                <input type='hidden' name='cancellation_action' value='approve'>
                <button type='submit' class='action-btn accept'>Approve</button>
              </form>
              <form method='post' class='cancellation-action-form' data-action='reject'>
                <input type='hidden' name='csrf_token' value='" . htmlspecialchars((string)$_SESSION['cancellation_admin_csrf']) . "'>
                <input type='hidden' name='cancellation_request_id' value='{$requestId}'>
                <input type='hidden' name='cancellation_action' value='reject'>
                <input type='hidden' name='admin_note' value=''>
                <button type='submit' class='action-btn decline'>Reject</button>
              </form>";
        } elseif ($requestStatus === 'approved') {
            if ($refundableAmount > 0.009 && !$refundFinished) {
                echo "<a class='action-btn finish process-refund-action' href='adpaymenttransactions.php?view=refunds&amp;cancellation_request_id={$requestId}'>Process Refund</a>";
            }
            if ($canComplete) {
                echo "<form method='post' class='cancellation-action-form' data-action='complete'>
                    <input type='hidden' name='csrf_token' value='" . htmlspecialchars((string)$_SESSION['cancellation_admin_csrf']) . "'>
                    <input type='hidden' name='cancellation_request_id' value='{$requestId}'>
                    <input type='hidden' name='cancellation_action' value='complete'>
                    <button type='submit' class='action-btn finish'>Mark Completed</button>
                  </form>";
            } else {
                echo "<button type='button' class='action-btn finish cancellation-complete-disabled' disabled aria-disabled='true' title='Complete the refund before closing this request.'>Mark Completed</button>";
            }
        }
        echo "</div></div></td></tr>";
    }
}

// Base columns
$columnsBase = "<th>Tourist</th><th>ID</th><th>Type</th><th>Pax</th><th>Date</th><th>Payment Status</th><th>Status</th>";

// Pending tab: no Tourist Submission
$columnsPending = $columnsBase . "<th>Actions</th>";

// Accepted tab: remove Pax and add Tourist Submission before Actions
$columnsAccepted = "<th>Tourist</th><th>ID</th><th>Type</th><th>Date</th><th>Payment Status</th><th>Status</th><th>Tourist Submission</th><th>Actions</th>";

// Completed tab: include Actions for View Details
$columnsCompleted = $columnsBase . "<th>Actions</th>";
?>

<div id="all" class="tab-page <?= $activeTab==='all'?'active':'' ?>">
    <table>
        <thead>
            <tr><?= $columnsBase ?><th>Actions</th></tr>
        </thead>
        <tbody>
            <?php renderBookings($bookings, 'all'); ?>
        </tbody>
    </table>
</div>

<div id="pending" class="tab-page <?= $activeTab==='pending'?'active':'' ?>">
    <table>
        <thead>
            <tr><?= $columnsPending ?></tr>
        </thead>
        <tbody>
            <?php renderBookings($bookings,'pending'); ?>
        </tbody>
    </table>
</div>

<div id="accepted" class="tab-page <?= $activeTab==='accepted'?'active':'' ?>">
    <table>
        <thead>
            <tr><?= $columnsAccepted ?></tr>
        </thead>
        <tbody>
            <?php renderBookings($bookings,'accepted'); ?>
        </tbody>
    </table>
</div>

<div id="completed" class="tab-page <?= $activeTab==='completed'?'active':'' ?>">
    <table>
        <thead>
            <tr><?= $columnsCompleted ?></tr>
        </thead>
        <tbody>
            <?php renderBookings($bookings, 'completed'); ?>
        </tbody>
    </table>
</div>

<div id="cancellations" class="tab-page cancellation-page <?= $activeTab==='cancellations'?'active':'' ?>">
  <div class="cancellation-summary-grid">
    <article><span>All Requests</span><strong><?= $cancellationSummary['total'] ?></strong></article>
    <article><span>Awaiting Approval</span><strong><?= $cancellationSummary['pending'] ?></strong></article>
    <article><span>Approved</span><strong><?= $cancellationSummary['approved'] ?></strong></article>
    <article><span>Refund Required</span><strong><?= $cancellationSummary['refund_pending'] ?></strong></article>
    <article><span>Completed</span><strong><?= $cancellationSummary['completed'] ?></strong></article>
  </div>
  <div class="cancellation-policy-note">
    <strong>Refund workflow</strong>
    <span>Approve the request first. Refund-eligible cancellations must be successfully refunded before “Mark Completed” becomes available.</span>
  </div>
  <div class="cancellation-table-wrap">
    <table class="cancellation-admin-table">
      <thead><tr><th>Tourist</th><th>Booking</th><th>Service</th><th>Requested</th><th>Refund Eligibility</th><th>Request Status</th><th>Refund Status</th><th>Actions</th></tr></thead>
      <tbody><?php renderAdminCancellationRequests($adminCancellationRequests); ?></tbody>
    </table>
  </div>
</div>

<p class="booking-count-note">
  <?php if ($activeTab === 'cancellations'): ?>
    Showing <?= number_format(count($adminCancellationRequests)) ?> cancellation request<?= count($adminCancellationRequests) === 1 ? '' : 's' ?>.
  <?php else: ?>
    Showing <?= number_format(count($bookings)) ?> of <?= number_format($totalFilteredBookings) ?> bookings.
  <?php endif; ?>
</p>

<!-- STAFF WALK-IN BOOKING MODAL -->
<div id="walkinBookingModal" class="walkin-modal-overlay" aria-hidden="true">
  <div class="walkin-modal" role="dialog" aria-modal="true" aria-labelledby="walkinModalTitle">
    <form id="walkinBookingForm" method="POST" action="adbookings.php">
      <input type="hidden" name="action" value="create_walkin_booking">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['walkin_booking_csrf']) ?>">
      <div class="walkin-modal-header">
        <span class="walkin-title-icon" aria-hidden="true">
          <svg viewBox="0 0 24 24"><path d="M8 2v3M16 2v3M3.5 9h17M5 4h14a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2Z"/><path d="M12 12v6M9 15h6"/></svg>
        </span>
        <div>
          <small>STAFF-ASSISTED RESERVATION</small>
          <h2 id="walkinModalTitle">Add Walk-in Booking</h2>
          <p>Complete the guest, trip, and payment details.</p>
        </div>
        <button type="button" class="walkin-close" id="closeWalkinBookingModal" aria-label="Close">
          <svg viewBox="0 0 24 24"><path d="m6 6 12 12M18 6 6 18"/></svg>
        </button>
      </div>

      <div class="walkin-stepper" aria-label="Booking progress">
        <div class="walkin-step-indicator is-active" data-step-indicator="1" aria-current="step">
          <span>1</span>
          <strong>Guest details</strong>
        </div>
        <div class="walkin-step-line" aria-hidden="true"></div>
        <div class="walkin-step-indicator" data-step-indicator="2">
          <span>2</span>
          <strong>Booking details</strong>
        </div>
        <div class="walkin-step-line" aria-hidden="true"></div>
        <div class="walkin-step-indicator" data-step-indicator="3">
          <span>3</span>
          <strong>Confirm booking</strong>
        </div>
      </div>

      <div class="walkin-modal-body">
        <section class="walkin-section walkin-step-panel is-active" data-walkin-step="1">
          <div class="walkin-section-heading"><b>1</b><div><h3>Guest information</h3><p>Select an account or register a walk-in guest.</p></div></div>
          <div class="walkin-segmented">
            <label><input type="radio" name="guest_mode" value="existing" checked><span>Existing tourist</span></label>
            <label><input type="radio" name="guest_mode" value="new"><span>New walk-in guest</span></label>
          </div>
          <div id="walkinExistingGuest" class="walkin-account-picker">
            <label class="walkin-field"><span>Search tourist account <b>*</b></span>
              <div class="walkin-tourist-search">
                <svg aria-hidden="true" viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"></circle><path d="m16 16 4 4"></path></svg>
                <input type="search" id="walkinTouristSearch" placeholder="Search by name, email, or phone number" autocomplete="off">
                <i id="walkinTouristSearchSpinner" class="walkin-tourist-spinner" hidden></i>
              </div>
            </label>
            <input type="hidden" name="tourist_id" id="walkinTouristId">
            <div id="walkinTouristResults" class="walkin-tourist-results" hidden></div>
            <div id="walkinSelectedTourist" class="walkin-selected-tourist" hidden>
              <span class="walkin-tourist-avatar" id="walkinSelectedTouristAvatar"><b>T</b></span>
              <div>
                <small>Selected tourist account</small>
                <strong id="walkinSelectedTouristName">Tourist</strong>
                <span id="walkinSelectedTouristEmail"></span>
              </div>
              <button type="button" id="walkinChangeTourist">Change</button>
            </div>
            <p class="walkin-tourist-help" id="walkinTouristHelp">Type at least two characters to find a registered tourist.</p>
          </div>
          <div id="walkinNewGuest" class="walkin-grid" hidden>
            <label class="walkin-field"><span>Full name <b>*</b></span><input type="text" name="guest_name" maxlength="150" placeholder="Guest's complete name"></label>
            <label class="walkin-field"><span>Email address <b>*</b></span><input type="email" name="guest_email" maxlength="190" placeholder="guest@example.com"></label>
            <label class="walkin-field walkin-span-2"><span>Home address</span><input type="text" name="guest_address" maxlength="255" placeholder="Street, barangay, municipality"></label>
          </div>
          <div class="walkin-grid">
            <label class="walkin-field walkin-span-2"><span>Contact number <b>*</b></span><input type="tel" name="phone_number" id="walkinPhone" maxlength="20" required placeholder="e.g. 0912 345 6789"></label>
          </div>
        </section>

        <section class="walkin-section walkin-step-panel" data-walkin-step="2" hidden>
          <div class="walkin-section-heading"><b>2</b><div><h3>Booking requirements</h3><p>Choose a service and complete the trip details.</p></div></div>
          <div class="walkin-grid">
            <label class="walkin-field"><span>Booking type <b>*</b></span>
              <select name="booking_type" id="walkinBookingType" required><option value="">Select a service</option><option value="package">Tour package</option><option value="boat">Tour boat</option><option value="tourguide">Tour guide</option></select>
            </label>
            <label class="walkin-field"><span>Tour date <b>*</b></span>
              <input type="text" id="walkinDateDisplay" placeholder="Select tour date" readonly required>
              <input type="hidden" name="booking_date" id="walkinBookingDate">
              <input type="hidden" name="booking_end_date" id="walkinBookingEndDate">
            </label>

            <label class="walkin-field walkin-span-2 walkin-resource-field" id="walkinPackageField" hidden><span>Tour package <b>*</b></span>
              <select name="package_id" id="walkinPackageId" disabled><option value="">Select a tour package</option>
                <?php foreach ($walkInPackages as $package): ?>
                  <option value="<?= (int)$package['package_id'] ?>" data-price="<?= htmlspecialchars((string)$package['price']) ?>" data-tour-type="<?= htmlspecialchars((string)$package['package_type']) ?>" data-range="<?= htmlspecialchars((string)$package['package_range']) ?>"><?= htmlspecialchars($package['package_title']) ?> — ₱<?= number_format((float)$package['price'], 2) ?></option>
                <?php endforeach; ?>
              </select>
            </label>
            <label class="walkin-field walkin-span-2 walkin-resource-field" id="walkinBoatField" hidden><span>Tour boat <b>*</b></span>
              <select name="boat_id" id="walkinBoatId" disabled><option value="">Select a tour boat</option>
                <?php foreach ($walkInBoats as $boat): ?>
                  <option value="<?= (int)$boat['boat_id'] ?>" data-capacity="<?= (int)$boat['total_pax'] ?>"><?= htmlspecialchars($boat['name']) ?> — up to <?= (int)$boat['total_pax'] ?> guests</option>
                <?php endforeach; ?>
              </select>
            </label>
            <label class="walkin-field walkin-span-2 walkin-resource-field" id="walkinGuideField" hidden><span>Tour guide <b>*</b></span>
              <select name="guide_id" id="walkinGuideId" disabled><option value="">Select a tour guide</option>
                <?php foreach ($walkInGuides as $guide): ?><option value="<?= (int)$guide['guide_id'] ?>"><?= htmlspecialchars($guide['fullname']) ?></option><?php endforeach; ?>
              </select>
            </label>

            <label class="walkin-field walkin-span-2" id="walkinLocationField" hidden><span>Destination <b>*</b></span>
              <select name="location" id="walkinLocation" disabled><option value="">Select destination</option><option>Apuao</option><option>Apuao Grande</option><option>Cayucyucan</option><option>Canimog</option><option>Caringo</option><option>Malasugui</option><option>Quinapaguian</option></select>
            </label>
            <label class="walkin-field"><span>Trip duration <b>*</b></span><select name="tour_type" id="walkinTourType" required><option value="same-day">Day tour</option><option value="overnight">Overnight</option></select></label>
            <label class="walkin-field"><span>Jump-off port <b>*</b></span>
              <select name="jump_off_port" id="walkinJumpOffPort" required>
                <option value="">Select jump-off port</option>
                <option value="Mercedes Port">Mercedes Port</option>
                <option value="Cayucyucan">Cayucyucan</option>
              </select>
            </label>
            <label class="walkin-field"><span>Adults <b>*</b></span>
              <div class="walkin-pax-control">
                <button type="button" data-pax-action="decrease" data-pax-target="walkinAdults" aria-label="Remove one adult">−</button>
                <input type="number" name="num_adults" id="walkinAdults" min="1" max="100" value="1" required readonly aria-live="polite">
                <button type="button" data-pax-action="increase" data-pax-target="walkinAdults" aria-label="Add one adult">+</button>
              </div>
            </label>
            <label class="walkin-field"><span>Children</span>
              <div class="walkin-pax-control">
                <button type="button" data-pax-action="decrease" data-pax-target="walkinChildren" aria-label="Remove one child">−</button>
                <input type="number" name="num_children" id="walkinChildren" min="0" max="100" value="0" readonly aria-live="polite">
                <button type="button" data-pax-action="increase" data-pax-target="walkinChildren" aria-label="Add one child">+</button>
              </div>
            </label>
            <input type="hidden" name="tour_range" id="walkinTourRange">
          </div>
          <p id="walkinCapacityNote" class="walkin-inline-note" hidden></p>
        </section>

        <section class="walkin-section walkin-step-panel" data-walkin-step="3" hidden>
          <div class="walkin-section-heading"><b>3</b><div><h3>Payment and confirmation</h3><p>Record the amount collected at the desk.</p></div></div>
          <div class="walkin-review" id="walkinReviewSummary" aria-live="polite"></div>
          <div class="walkin-expense-tally">
            <div class="walkin-tally-heading"><div><strong>Price and expense tally</strong><span>Review or adjust charges before confirming.</span></div><b id="walkinExpensesTotalLabel">Expenses: ₱0.00</b></div>
            <div class="walkin-grid">
              <label class="walkin-field"><span>Service price <b>*</b></span><div class="walkin-money"><i>₱</i><input type="number" name="service_amount" id="walkinServiceAmount" min="0" step="0.01" value="0.00" required></div></label>
              <label class="walkin-field"><span>Environmental fee</span><div class="walkin-money"><i>₱</i><input type="number" name="expense_environmental" class="walkin-expense-input" min="0" step="0.01" value="0.00"></div></label>
              <label class="walkin-field"><span>Entrance fee</span><div class="walkin-money"><i>₱</i><input type="number" name="expense_entrance" class="walkin-expense-input" min="0" step="0.01" value="0.00"></div></label>
              <label class="walkin-field"><span>Docking / landing fee</span><div class="walkin-money"><i>₱</i><input type="number" name="expense_docking" class="walkin-expense-input" min="0" step="0.01" value="0.00"></div></label>
              <label class="walkin-field walkin-span-2"><span>Other fees</span><div class="walkin-money"><i>₱</i><input type="number" name="expense_other" class="walkin-expense-input" min="0" step="0.01" value="0.00"></div></label>
            </div>
            <div class="walkin-tally-total"><span>Grand total</span><strong id="walkinGrandTotalLabel">₱0.00</strong><input type="hidden" name="grand_total" id="walkinGrandTotal" value="0.00"></div>
          </div>
          <div class="walkin-grid walkin-payment-fields">
            <label class="walkin-field"><span>Payment option <b>*</b></span><select name="payment_option" id="walkinPaymentOption" required><option value="partial" selected>20% down payment</option><option value="full">Full payment (100%)</option></select></label>
            <label class="walkin-field"><span>Payment received</span><div class="walkin-money"><i>₱</i><input type="number" name="payment_amount" id="walkinPaymentAmount" min="0" step="0.01" value="0.00"></div></label>
            <label class="walkin-field"><span>Remaining balance</span><div class="walkin-money is-readonly"><i>₱</i><input type="text" id="walkinRemainingBalance" value="0.00" readonly></div></label>
            <label class="walkin-field"><span>Payment method</span><select name="payment_method" id="walkinPaymentMethod"><option value="">No payment yet</option><option value="Cash">Cash</option><option value="GCash">GCash</option><option value="Bank Transfer">Bank transfer</option></select></label>
            <label class="walkin-field walkin-span-2"><span>Initial booking status <b>*</b></span><select name="booking_status" required><option value="accepted" selected>Accepted — confirmed by staff</option><option value="pending">Pending — needs confirmation</option></select></label>
          </div>
        </section>
      </div>
      <div class="walkin-modal-footer">
        <p id="walkinStepStatus">Step 1 of 3 · Required fields are marked with an asterisk.</p>
        <div>
          <button type="button" class="walkin-btn secondary" id="cancelWalkinBooking">Cancel</button>
          <button type="button" class="walkin-btn secondary walkin-back-btn" id="walkinPreviousStep" hidden>
            <svg viewBox="0 0 24 24"><path d="m15 18-6-6 6-6"/></svg>Back
          </button>
          <button type="button" class="walkin-btn primary" id="walkinNextStep">
            Continue to Booking Details<svg viewBox="0 0 24 24"><path d="m9 18 6-6-6-6"/></svg>
          </button>
          <button type="submit" class="walkin-btn primary" id="submitWalkinBooking" hidden>
            <svg viewBox="0 0 24 24"><path d="M20 6 9 17l-5-5"/></svg>Confirm &amp; Create Booking
          </button>
        </div>
      </div>
    </form>
  </div>
</div>

<!-- DECISION REASON MODAL -->
<div id="decisionModal" class="decision-modal-overlay">
  <div class="decision-modal">

    <!-- Header -->
    <!-- Example modal header -->
    <div class="decision-modal-header">
      <h3 id="decisionModalTitle">Reason Required</h3>
      <button id="decisionModalClose" class="decision-modal-close">×</button>
    </div>


    <!-- Body -->
    <div class="decision-modal-body">

      <label class="decision-label">--Select Category--</label>
      <select id="decisionCategory" class="decision-input">
        <option value="" disabled selected>Select a reason</option>
        <option value="Incorrect Information">Incorrect Information</option>
        <option value="Invalid Booking Details">Invalid Booking Details</option>
        <option value="Duplicate Booking">Duplicate Booking</option>
        <option value="Fraudulent activity">Fraudulent activity</option>
        <option value="Unavailability">Unavailability</option>
        <option value="Tourist-related issues">Tourist-related issues</option>
        <option value="Platform policy violations">Platform policy violations</option>
        <option value="Weather issues">Weather issues</option>
        <option value="Others">Others</option>
      </select>

      <label class="decision-label">Additional Note (optional)</label>
      <textarea id="decisionNote" class="decision-textarea" placeholder="Add note here..."></textarea>

    </div>

    <!-- Footer -->
    <div class="decision-modal-footer">
      <button class="decision-btn cancel" id="decisionCancelBtn">Cancel</button>
      <button class="decision-btn ok" id="decisionOkBtn">OK</button>
    </div>

  </div>
</div>

<!-- PROVIDER CANCELLATION WORKFLOW MODAL -->
<div id="providerCancellationModal" class="provider-cancel-overlay" aria-hidden="true">
  <section class="provider-cancel-modal" role="dialog" aria-modal="true" aria-labelledby="providerCancelTitle">
    <header class="provider-cancel-header">
      <div class="provider-cancel-heading-icon" aria-hidden="true">
        <svg viewBox="0 0 24 24"><path d="M12 3 4 6v5c0 4.8 3.3 8 8 10 4.7-2 8-5.2 8-10V6l-8-3Z"></path><path d="M9 9l6 6M15 9l-6 6"></path></svg>
      </div>
      <div class="provider-cancel-heading-copy">
        <span>BOOKING MANAGEMENT</span>
        <h3 id="providerCancelTitle">Administrator Cancellation</h3>
        <p id="providerCancelSubtitle">Choose the appropriate assistance for this tourist.</p>
      </div>
      <button type="button" class="provider-cancel-close" id="providerCancelClose" aria-label="Close cancellation dialog">
        <svg viewBox="0 0 24 24"><path d="m6 6 12 12M18 6 6 18"></path></svg>
      </button>
    </header>

    <div class="provider-cancel-progress" aria-label="Cancellation workflow progress">
      <div class="provider-cancel-progress-item active" data-progress="1"><span>1</span><div><strong>Resolution</strong><small>Select an option</small></div></div>
      <i></i>
      <div class="provider-cancel-progress-item" data-progress="2"><span>2</span><div><strong>Reason</strong><small>Document cause</small></div></div>
      <i></i>
      <div class="provider-cancel-progress-item" data-progress="3"><span>3</span><div><strong>Review</strong><small>Confirm action</small></div></div>
    </div>

    <div class="provider-cancel-body">
      <div class="provider-cancel-booking-strip">
        <div><small>BOOKING REFERENCE</small><strong id="providerCancelReference">—</strong></div>
        <div><small>TOURIST</small><strong id="providerCancelTourist">—</strong></div>
        <div><small id="providerCancelDateLabel">SCHEDULED DATE</small><strong id="providerCancelDate">—</strong></div>
      </div>

      <div class="provider-cancel-step active" data-step="1">
        <div class="provider-cancel-section-title"><span>01</span><div><h4>How should this booking be handled?</h4><p>The selected resolution determines whether the tourist is asked to respond.</p></div></div>
        <div class="provider-cancel-options">
          <label class="provider-cancel-option">
            <input type="radio" name="providerCancellationChoice" value="offer_reschedule">
            <span class="provider-cancel-option-icon reschedule"><svg viewBox="0 0 24 24"><path d="M20 11a8 8 0 1 0-2.3 5.7"></path><path d="M20 4v7h-7"></path><path d="M12 8v4l2.5 1.5"></path></svg></span>
            <span class="provider-cancel-option-copy"><strong>Offer Free Reschedule</strong><small>The tourist receives 2 days to choose a new date or take a full refund.</small><em>Recommended when the service can operate on another date</em></span>
            <span class="provider-cancel-radio"></span>
          </label>
          <label class="provider-cancel-option danger">
            <input type="radio" name="providerCancellationChoice" value="full_refund">
            <span class="provider-cancel-option-icon refund"><svg viewBox="0 0 24 24"><path d="M4 7h16v10H4z"></path><path d="M8 11h4M16 10v2"></path><path d="m7 4-3 3 3 3"></path></svg></span>
            <span class="provider-cancel-option-copy"><strong>Cancel &amp; Full Refund</strong><small>Cancel immediately and submit 100% of the amount actually paid for refund processing.</small><em>No response is required from the tourist</em></span>
            <span class="provider-cancel-radio"></span>
          </label>
        </div>
        <p class="provider-cancel-error" id="providerCancelChoiceError" role="alert"></p>
      </div>

      <div class="provider-cancel-step" data-step="2">
        <div class="provider-cancel-section-title"><span>02</span><div><h4 id="providerCancelReasonHeading">Why can the booking not proceed?</h4><p>This reason is recorded in the audit trail and included in the tourist email.</p></div></div>
        <label class="provider-cancel-field">
          <span>Cancellation or rescheduling reason <b>*</b></span>
          <select id="providerCancelReason">
            <option value="">Select the most appropriate reason</option>
            <?php foreach (array_merge(bookingProviderCancellationReasons(), ['Other']) as $providerReason): ?>
              <option value="<?= htmlspecialchars($providerReason, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($providerReason) ?></option>
            <?php endforeach; ?>
          </select>
          <small>Choose the reason that best explains the provider-initiated change.</small>
        </label>
        <label class="provider-cancel-field" id="providerCancelOtherField" hidden>
          <span>Custom reason <b>*</b></span>
          <textarea id="providerCancelOther" maxlength="1500" rows="4" placeholder="Provide a clear explanation for the tourist..."></textarea>
          <small><span id="providerCancelReasonCount">0</span>/1,500 characters</small>
        </label>
        <p class="provider-cancel-error" id="providerCancelReasonError" role="alert"></p>
      </div>

      <div class="provider-cancel-step" data-step="3">
        <div class="provider-cancel-section-title"><span>03</span><div><h4>Review before confirming</h4><p>Verify the resolution and reason. This action is recorded in system activity logs.</p></div></div>
        <div class="provider-cancel-review">
          <div><small>RESOLUTION</small><strong id="providerCancelReviewResolution">—</strong></div>
          <div><small>AMOUNT PAID</small><strong id="providerCancelReviewPaid">—</strong></div>
          <div class="wide"><small>TOUR SCHEDULE</small><strong id="providerCancelReviewSchedule">—</strong></div>
          <div class="wide"><small>RECORDED REASON</small><strong id="providerCancelReviewReason">—</strong></div>
        </div>
        <div class="provider-cancel-notice" id="providerCancelReviewNotice"></div>
        <label class="provider-cancel-confirm-check"><input type="checkbox" id="providerCancelAcknowledge"><span>I have reviewed the booking and confirm this administrator/provider-initiated action.</span></label>
        <p class="provider-cancel-error" id="providerCancelReviewError" role="alert"></p>
      </div>
    </div>

    <footer class="provider-cancel-footer">
      <button type="button" class="provider-cancel-btn secondary" id="providerCancelBack" hidden><svg viewBox="0 0 24 24"><path d="m15 18-6-6 6-6"></path></svg>Back</button>
      <span class="provider-cancel-footer-spacer"></span>
      <button type="button" class="provider-cancel-btn ghost" id="providerCancelDismiss">Keep Booking</button>
      <button type="button" class="provider-cancel-btn primary" id="providerCancelNext">Continue<svg viewBox="0 0 24 24"><path d="m9 18 6-6-6-6"></path></svg></button>
      <button type="button" class="provider-cancel-btn danger" id="providerCancelSubmit" hidden>Confirm Cancellation</button>
    </footer>
    <div class="provider-cancel-processing" id="providerCancelProcessing" hidden><span></span><strong>Processing cancellation…</strong><small>Please keep this page open.</small></div>
  </section>
</div>

<div id="bookingDetailsModal" class="booking-details-modal-overlay" aria-hidden="true">
  <aside class="booking-details-modal" role="dialog" aria-modal="true" aria-labelledby="bookingDetailsTitle">
    <div class="booking-details-modal-header">
      <div class="booking-drawer-brand">
        <img src="img/newlogo.png" alt="">
        <div><span>ITOUR MERCEDES</span><h3 id="bookingDetailsTitle">Booking Details</h3></div>
      </div>
      <button type="button" onclick="closeBookingDetailsModal()" class="booking-details-modal-close" aria-label="Close booking details">
        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m6 6 12 12M18 6 6 18"/></svg>
      </button>
    </div>
    <div class="booking-details-modal-body" id="bookingDetailsContent">
      <div class="booking-drawer-loading"><span></span><p>Loading booking details…</p></div>
    </div>
    <div class="booking-details-modal-footer">
      <button type="button" onclick="closeBookingDetailsModal()" class="booking-details-modal-btn-close">Close Details</button>
    </div>
  </aside>
</div>

<div id="cancellationDetailsDrawer" class="cancellation-details-overlay" aria-hidden="true">
  <aside class="cancellation-details-drawer" role="dialog" aria-modal="true" aria-labelledby="cancellationDetailsTitle">
    <header class="cancellation-details-header">
      <div class="booking-drawer-brand">
        <img src="img/newlogo.png" alt="">
        <div><span>ITOUR MERCEDES</span><h3 id="cancellationDetailsTitle">Cancellation Details</h3></div>
      </div>
      <button type="button" class="cancellation-details-close" aria-label="Close cancellation details" onclick="closeCancellationDetailsDrawer()">
        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m6 6 12 12M18 6 6 18"/></svg>
      </button>
    </header>
    <div class="cancellation-details-body" id="cancellationDetailsContent"></div>
    <footer class="cancellation-details-footer">
      <button type="button" onclick="closeCancellationDetailsDrawer()">Close Details</button>
    </footer>
  </aside>
</div>

<div id="touristProfileDrawer" class="tourist-profile-overlay" aria-hidden="true">
  <aside class="tourist-profile-drawer" role="dialog" aria-modal="true" aria-labelledby="touristProfileTitle">
    <header class="tourist-profile-header">
      <div class="tourist-profile-brand">
        <img src="img/newlogo.png" alt="">
        <div>
          <span>ITOUR MERCEDES</span>
          <h3 id="touristProfileTitle">Tourist Profile</h3>
        </div>
      </div>
      <button type="button" class="tourist-profile-close" aria-label="Close tourist profile" onclick="closeTouristProfileDrawer()">
        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m6 6 12 12M18 6 6 18"/></svg>
      </button>
    </header>
    <div class="tourist-profile-body" id="touristProfileContent"></div>
    <footer class="tourist-profile-footer">
      <button type="button" class="tourist-profile-close-btn" onclick="closeTouristProfileDrawer()">Close Profile</button>
    </footer>
  </aside>
</div>

<!-- Tourist Modal -->
<div id="touristModal" class="tourist-modal" aria-hidden="true">
  <div class="tourist-modal-wrapper" role="dialog" aria-modal="true" aria-labelledby="touristDocumentsTitle">
    <div class="tourist-modal-content">
      <div class="tourist-modal-header">
        <div class="tourist-document-heading">
          <span class="tourist-document-icon" aria-hidden="true">
            <svg viewBox="0 0 24 24"><path d="M6 3h9l4 4v14H6z"/><path d="M14 3v5h5M9 13h6M9 17h6"/></svg>
          </span>
          <div>
            <small>BOOKING DOCUMENTS</small>
            <h2 id="touristDocumentsTitle">Tourist Documents</h2>
            <p>Preview the submitted travel documents for this booking.</p>
          </div>
        </div>
        <div class="tourist-document-switcher" role="tablist" aria-label="Tourist documents">
          <button type="button" class="tourist-document-tab active" data-tourist-document="manifest" role="tab" aria-selected="true">
            <span>Passenger Manifest</span><small>Coast Guard passenger list</small>
          </button>
          <button type="button" class="tourist-document-tab" data-tourist-document="registration" role="tab" aria-selected="false">
            <span>Travel Registration Form</span><small>Tourist registration document</small>
          </button>
        </div>
        <button type="button" class="tourist-modal-close" onclick="closeTouristModal()" aria-label="Close tourist documents">&times;</button>
      </div>
      <div class="tourist-modal-body">
        <iframe id="touristPdfFrame" src="" title="Passenger manifest PDF"></iframe>
      </div>
    </div>
  </div>
</div>

<!-- BILLING SUMMARY MODAL -->
<div id="billingModal" class="billing-modal-overlay" aria-hidden="true">
  <section class="billing-modal" role="dialog" aria-modal="true" aria-labelledby="billingModalTitle">
    <header class="billing-modal-header">
      <div class="billing-heading-icon" aria-hidden="true">
        <svg viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="16" rx="2"/><path d="M7 9h10M7 13h6M16 13h1M7 17h4"/></svg>
      </div>
      <div><span>BOOKING ACCOUNT</span><h3 id="billingModalTitle">Billing Details</h3><p id="billingModalSubtitle">Loading current charges and payment information…</p></div>
      <button type="button" class="billing-icon-close" id="billingIconClose" aria-label="Close billing details">&times;</button>
    </header>
    <div class="billing-modal-body" id="billingModalBody">
      <div class="billing-loading"><span></span><p>Preparing billing statement…</p></div>
    </div>
    <footer class="billing-modal-footer">
      <button type="button" class="billing-footer-btn billing-close-btn" id="closeBillingModal">Close</button>
      <button type="button" class="billing-footer-btn billing-receipt-btn" id="openBookingReceipt" disabled>Booking Receipt</button>
      <button type="button" class="billing-footer-btn billing-expense-btn add-expense-btn" id="billingAddExpense">Add Expense</button>
      <div class="billing-pay-wrap">
        <button type="button" class="billing-footer-btn billing-pay-btn" id="billingPayBalance">Pay Balance</button>
        <small id="billingPaidNote" hidden>Already paid</small>
      </div>
    </footer>
  </section>
</div>

<!-- BOOKING RECEIPT PREVIEW MODAL -->
<div id="adminReceiptModal" class="admin-receipt-overlay" aria-hidden="true" inert>
  <section class="admin-receipt-shell" role="dialog" aria-modal="true" aria-labelledby="adminReceiptTitle">
    <header class="admin-receipt-head">
      <div><span>OFFICIAL PAYMENT RECORD</span><h3 id="adminReceiptTitle">Booking Receipt</h3></div>
      <button type="button" class="admin-receipt-x" data-close-admin-receipt aria-label="Close booking receipt">&times;</button>
    </header>
    <div class="admin-receipt-stage">
      <article class="admin-receipt-paper" id="adminReceiptPaper"></article>
    </div>
    <footer class="admin-receipt-footer">
      <button type="button" class="admin-receipt-btn secondary" data-close-admin-receipt>Close</button>
      <button type="button" class="admin-receipt-btn print" id="printAdminReceipt">Print</button>
      <button type="button" class="admin-receipt-btn primary" id="downloadAdminReceipt">Download</button>
    </footer>
  </section>
</div>

<div id="paymentModal" class="payment-modal-overlay" style="display:none;" aria-hidden="true">
  <div class="payment-modal" role="dialog" aria-modal="true" aria-labelledby="paymentModalTitle">

    <div class="payment-modal-heading">
      <div><span>PAYMENT COLLECTION</span><h3 class="payment-title" id="paymentModalTitle">Complete Payment</h3></div>
      <button id="paymentIconClose" type="button" class="payment-icon-close" aria-label="Close payment modal">&times;</button>
    </div>

    <div id="paymentInfo" class="payment-info"></div>
    <p id="paymentContextNote" class="payment-context-note"></p>

    <!-- REQUIRED: booking ID -->
    <input type="hidden" id="paymentBookingId" name="id">

    <!-- REQUIRED: action for backend -->
    <input type="hidden" id="paymentAction" value="confirm_payment">

    <!-- PAYMENT METHOD -->
    <label class="payment-label">Payment Method</label>
    <select id="paymentMethod" name="payment_method" class="payment-select">
      <option value="" selected disabled>Select payment method</option>
      <option value="cash">Cash</option>
      <option value="qr_code">QR Code (PayMongo)</option>
    </select>

    <section class="qr-admin-device" id="qrAdminDeviceSection" hidden aria-live="polite">
      <div class="qr-admin-device-icon" aria-hidden="true">
        <svg viewBox="0 0 24 24"><rect x="6" y="2" width="12" height="20" rx="2"/><path d="M10 18h4"/></svg>
      </div>
      <div class="qr-admin-device-copy">
        <small>REGISTERED ADMIN PHONE</small>
        <strong id="qrAdminDeviceName">Checking registered phone...</strong>
        <span id="qrAdminDeviceMeta">Please wait.</span>
      </div>
      <button type="button" class="qr-admin-device-action" id="manageAdminPhoneBtn">Register a Phone</button>
    </section>

    <!-- OPTIONAL BUT IMPORTANT: amount paid -->
    <label class="payment-label payment-amount-label">Amount Paid</label>
    <input 
      type="number" 
      id="paymentAmount" 
      name="amount" 
      class="payment-input"
      placeholder="Enter amount paid"
      min="0"
      step="0.01"
    >

    <div class="payment-actions">
      <button id="confirmPaymentBtn" type="button" class="btn-confirm">
        Confirm Payment
      </button>

      <button id="closePaymentModal" type="button" class="btn-cancel">
        Cancel
      </button>
    </div>

  </div>
</div>

<div id="adminPaymentPhoneOverviewModal" class="admin-phone-registration-overlay admin-phone-overview-overlay" aria-hidden="true" inert>
  <section class="admin-phone-registration-modal admin-phone-overview-modal" role="dialog" aria-modal="true" aria-labelledby="adminPaymentPhoneOverviewTitle">
    <header class="admin-phone-registration-head">
      <div class="admin-phone-registration-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><rect x="6" y="2" width="12" height="20" rx="2"/><path d="M10 18h4"/></svg></div>
      <div><span>PAYMENT NOTIFICATIONS</span><h3 id="adminPaymentPhoneOverviewTitle">Payment Phone</h3><p>The registered phone receives secure PayMongo QR notifications.</p></div>
      <button type="button" class="admin-phone-registration-x" data-close-phone-overview aria-label="Close payment phone">&times;</button>
    </header>
    <div class="admin-phone-registration-body admin-phone-overview-body">
      <section class="admin-phone-current-device" id="adminPhoneOverviewDevice" aria-live="polite">
        <div class="admin-phone-current-device-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><rect x="6" y="2" width="12" height="20" rx="2"/><path d="M10 18h4"/></svg></div>
        <div class="admin-phone-current-device-copy">
          <small>REGISTERED PAYMENT PHONE</small>
          <strong id="adminPhoneOverviewName">Checking registered phone...</strong>
          <span id="adminPhoneOverviewMeta">Please wait.</span>
        </div>
        <button type="button" class="admin-phone-overview-change" id="adminPhoneOverviewAction">Change</button>
      </section>
    </div>
    <footer class="admin-phone-registration-footer">
      <button type="button" class="admin-phone-registration-btn secondary" data-close-phone-overview>Close</button>
    </footer>
  </section>
</div>

<div id="adminPhoneRegistrationModal" class="admin-phone-registration-overlay" aria-hidden="true" inert>
  <section class="admin-phone-registration-modal" role="dialog" aria-modal="true" aria-labelledby="adminPhoneRegistrationTitle">
    <header class="admin-phone-registration-head">
      <div class="admin-phone-registration-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><rect x="6" y="2" width="12" height="20" rx="2"/><path d="M10 18h4"/></svg></div>
      <div><span>ADMINISTRATOR DEVICE</span><h3 id="adminPhoneRegistrationTitle">Register an Admin Phone</h3><p>Complete these steps using the phone that should display payment QR notifications.</p></div>
      <button type="button" class="admin-phone-registration-x" data-close-phone-registration aria-label="Close phone registration">&times;</button>
    </header>
    <div class="admin-phone-registration-body">
      <section class="admin-phone-current-device" id="adminPhoneCurrentDevice" aria-live="polite">
        <div class="admin-phone-current-device-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><rect x="6" y="2" width="12" height="20" rx="2"/><path d="M10 18h4"/></svg></div>
        <div class="admin-phone-current-device-copy">
          <small>CURRENT PAYMENT PHONE</small>
          <strong id="adminPhoneModalDeviceName">Checking registered phone...</strong>
          <span id="adminPhoneModalDeviceMeta">Please wait.</span>
        </div>
        <span class="admin-phone-current-device-state" id="adminPhoneModalDeviceState">Checking</span>
      </section>
      <ol class="admin-phone-registration-steps">
        <li><b>Open the setup address on the admin phone.</b><span>Log in with the same main Administrator account when asked.</span></li>
        <li><b>Name and register the phone.</b><span>Tap Register This Phone and allow browser notifications.</span></li>
        <li><b>Return to this computer.</b><span>This window detects the newly registered phone automatically.</span></li>
      </ol>
      <label class="admin-phone-setup-link"><span>PHONE SETUP ADDRESS</span><div><input type="text" id="adminPhoneSetupUrl" readonly><button type="button" id="copyAdminPhoneSetupUrl">Copy Link</button></div></label>
      <div class="admin-phone-registration-status" id="adminPhoneRegistrationStatus"><span></span><div><strong>Waiting for phone registration</strong><small>Keep this window open while registering the phone.</small></div></div>
    </div>
    <footer class="admin-phone-registration-footer">
      <button type="button" class="admin-phone-registration-btn secondary" data-close-phone-registration>Close</button>
      <button type="button" class="admin-phone-registration-btn secondary" id="checkAdminPhoneRegistration">Check Again</button>
      <button type="button" class="admin-phone-registration-btn primary" id="openAdminPhoneSetupPage">Open Setup Page</button>
    </footer>
  </section>
</div>

<!-- ADD EXPENSE MODAL -->
<div id="expenseModal" class="expense-overlay" aria-hidden="true">

  <section class="modal-content expense-modal-box" role="dialog" aria-modal="true" aria-labelledby="expenseModalTitle" aria-describedby="expenseModalDescription">

    <header class="expense-modal-header">
      <div class="expense-heading-icon" aria-hidden="true">
        <svg viewBox="0 0 24 24"><path d="M12 3v18M17 7.5H9.5a3 3 0 0 0 0 6h5a3 3 0 0 1 0 6H7"/></svg>
      </div>
      <div class="expense-heading-copy">
        <span>BOOKING ACCOUNT</span>
        <h3 class="expense-title" id="expenseModalTitle">Add Booking Expense</h3>
        <p id="expenseModalDescription">Record an additional charge for booking <strong id="expenseBookingLabel">—</strong>.</p>
      </div>
      <button type="button" class="expense-icon-close" id="expenseIconClose" aria-label="Close add expense dialog">&times;</button>
    </header>

    <form id="expenseForm" method="POST" class="expense-form">

        <input type="hidden" name="action" value="add_expense">
        <input type="hidden" name="booking_id" id="expenseBookingId">

        <div class="form-group">
            <label for="expenseType">Expense Type <span class="expense-required">*</span></label>
            <select id="expenseType" name="expense_type" required class="expense-input">
                <option value="additional_boat">Additional Boat</option>
                <option value="additional_tourguide">Additional Tour Guide</option>
                <option value="food">Food</option>
                <option value="others">Others</option>
            </select>
            <p class="expense-field-help">Choose the service or charge being added to this booking.</p>
        </div>

        <div class="form-group">
            <label for="expenseAmount">Amount <span class="expense-required">*</span></label>
            <div class="expense-amount-wrap">
              <span class="expense-currency" aria-hidden="true">&#8369;</span>
              <input id="expenseAmount" type="number" name="amount" min="0.01" step="0.01" inputmode="decimal" placeholder="0.00" required class="expense-input">
            </div>
            <p class="expense-field-help">This amount will be added to the booking’s remaining balance.</p>
        </div>

        <div class="form-group">
            <label for="expenseNote">Internal Note <span class="expense-optional">(optional)</span></label>
            <textarea id="expenseNote" name="note" maxlength="500" placeholder="Add a short explanation for this charge..." class="expense-input textarea"></textarea>
            <p class="expense-field-help">Use a clear description that staff can recognize later.</p>
        </div>

        <div class="expense-actions">
            <button type="button" class="expense-action-btn cancel" id="closeExpenseModal">Cancel</button>
            <button type="submit" class="expense-action-btn accept">
              <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>
              <span>Save Expense</span>
            </button>
        </div>

    </form>

  </section>

</div>



</main>
</div>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
<script src="https://html2canvas.hertzen.com/dist/html2canvas.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
<script>
// ---------------------------------------------------------------------
// TABS — preserves filters + search
// ---------------------------------------------------------------------
document.querySelectorAll('.tab-btn').forEach(btn => {
    btn.addEventListener('click', () => {
        const url = new URL(window.location);
        url.searchParams.set('tab', btn.dataset.tab);
        url.searchParams.delete('focus_request');
        if (btn.dataset.tab === 'all') url.searchParams.delete('status');
        window.location = url.toString();
    });
});


// ===========================================================
// ----------------- GLOBAL VARIABLES -----------------------
// ===========================================================
const reportModal = document.getElementById('reportModal');
const openReportBtn = document.getElementById('openReportModal');
const closeReportBtns = [
  document.getElementById('closeReportModal'),
  document.getElementById('closeReportModalFooter')
];

// ===========================================================
// ----------------- MODAL HANDLING -------------------------
// ===========================================================
function openModal(modal) {
  if (modal) modal.classList.add('show');
}
function closeModal(modal) {
  if (modal) modal.classList.remove('show');
}
if (openReportBtn) openReportBtn.addEventListener('click', () => openModal(reportModal));
closeReportBtns.forEach(btn => { if (btn) btn.addEventListener('click', () => closeModal(reportModal)); });
if (reportModal) reportModal.addEventListener('click', (e) => { if (e.target === reportModal) closeModal(reportModal); });

// ===========================================================
// ----------------- FETCH BOOKINGS -------------------------
// ===========================================================
async function fetchBookings(status = 'all', reportMode = '0') {
  try {
    const res = await fetch(`adbookings.php?action=fetchBookings&report_mode=${reportMode}&status=${status}`);
    const data = await res.json();
    if (data.error) {
      console.error("Error fetching bookings:", data.error);
      return [];
    }
    return data;
  } catch (err) {
    console.error("Fetch failed:", err);
    return [];
  }
}
// Get buttons
const downloadButton = document.getElementById('downloadReport');
const downloadCsvButton = document.getElementById('downloadReportCsv');
const printButton = document.getElementById('printReport');
let currentReportBookings = [];
let currentReportFilterText = 'All Bookings';
let currentReportFileStem = 'iTour-Mercedes-Booking-Report-All-Years';
let currentReportSummary = { bookings: 0, guests: 0, value: 0, collected: 0, outstanding: 0 };
const reportPreparedBy = <?= json_encode((string)($_SESSION['admin_name'] ?? 'Website Administrator'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;

// Disable buttons initially
downloadButton.disabled = true;
downloadCsvButton.disabled = true;
printButton.disabled = true;
downloadButton.style.backgroundColor = '#ccc';
downloadCsvButton.style.backgroundColor = '#ccc';
printButton.style.backgroundColor = '#ccc';

// ===========================================================
// ----------------- GENERATE REPORT PREVIEW ----------------
// ===========================================================
function reportEscape(value) {
  return String(value == null ? '' : value).replace(/[&<>"']/g, character => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'
  })[character]);
}

function reportMoney(value) {
  return `₱${Number(value || 0).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
}

function reportDate(value, includeTime = false) {
  if (!value) return 'Not set';
  const date = new Date(includeTime ? String(value).replace(' ', 'T') : `${String(value).slice(0, 10)}T00:00:00`);
  if (Number.isNaN(date.getTime())) return String(value);
  return date.toLocaleDateString('en-PH', includeTime
    ? { year: 'numeric', month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' }
    : { year: 'numeric', month: 'short', day: 'numeric' });
}

function reportLabel(value) {
  return String(value || 'Not specified').replace(/[_-]+/g, ' ').replace(/\b\w/g, letter => letter.toUpperCase());
}

async function generateReportPreview() {
  const mode = document.getElementById('reportFilterMode').value;
  const year = document.getElementById('reportFilterYear').value;
  const month = document.getElementById('reportFilterMonth').value;
  const reportMode = document.getElementById('reportMode').value;
  const orientation = document.getElementById('reportOrientation').value || 'portrait';

  const reportContent = document.getElementById('reportContent');
  reportContent.innerHTML = '<p>Loading...</p>';
  currentReportBookings = [];
  downloadButton.disabled = true;
  downloadCsvButton.disabled = true;
  printButton.disabled = true;
  downloadButton.style.backgroundColor = '#ccc';
  downloadCsvButton.style.backgroundColor = '#ccc';
  printButton.style.backgroundColor = '#ccc';

  if ((mode === 'yearly' || mode === 'monthly') && !year) {
    reportContent.innerHTML = '<div class="report-empty-state"><strong>Select a report year</strong><span>A year is required for this report coverage.</span></div>';
    return;
  }
  if (mode === 'monthly' && !month) {
    reportContent.innerHTML = '<div class="report-empty-state"><strong>Select a report month</strong><span>A month is required for monthly reports.</span></div>';
    return;
  }

  let allBookings = await fetchBookings('all', reportMode);

  allBookings = allBookings.map(b => ({
    ...b,
    display_name: b.booking_type === 'package' ? (b.package_name || b.location) : (b.location || b.package_name),
    guest_count: Number(b.pax || 0) || (Number(b.num_adults || 0) + Number(b.num_children || 0)),
    booking_status: String(b.is_complete || '').toLowerCase() === 'completed' ? 'Completed' : reportLabel(b.status || 'Pending'),
    payment_status: Number(b.remaining_balance || 0) <= 0 ? 'Paid' : Number(b.payment_amount || 0) > 0 ? 'Partial' : 'Unpaid'
  }));

  let filtered = allBookings;
  if (mode === 'yearly' && year) filtered = filtered.filter(b => new Date(String(b.created_at).replace(' ', 'T')).getFullYear() == year);
  if (mode === 'monthly' && year && month) filtered = filtered.filter(b => {
    const created = new Date(String(b.created_at).replace(' ', 'T'));
    return created.getFullYear() == year && created.getMonth() + 1 == month;
  });

  if (filtered.length === 0) {
    reportContent.innerHTML = '<div class="report-empty-state"><strong>No bookings found</strong><span>Try another reporting period and apply the filter again.</span></div>';

    // Disable buttons if no data
    downloadButton.disabled = true;
    downloadCsvButton.disabled = true;
    printButton.disabled = true;
    downloadButton.style.backgroundColor = '#ccc';
    downloadCsvButton.style.backgroundColor = '#ccc';
    printButton.style.backgroundColor = '#ccc';
    return;
  }

  // Enable buttons since data exists
  currentReportBookings = filtered;
  if (mode === 'yearly' && year) {
    currentReportFilterText = `Year: ${year}`;
    currentReportFileStem = `iTour-Mercedes-Booking-Report-Year-${year}`;
  } else if (mode === 'monthly' && year && month) {
    const monthName = new Date(0, Number(month) - 1).toLocaleString('en-PH', { month: 'long' });
    currentReportFilterText = `Month: ${monthName} ${year}`;
    currentReportFileStem = `iTour-Mercedes-Booking-Report-${monthName}-${year}`;
  } else {
    currentReportFilterText = 'All Bookings';
    currentReportFileStem = 'iTour-Mercedes-Booking-Report-All-Years';
  }
  currentReportSummary = filtered.reduce((summary, booking) => {
    summary.bookings += 1;
    summary.guests += Number(booking.guest_count || 0);
    summary.value += Number(booking.grand_total || 0);
    summary.collected += Number(booking.payment_amount || 0);
    summary.outstanding += Math.max(0, Number(booking.remaining_balance || 0));
    return summary;
  }, { bookings: 0, guests: 0, value: 0, collected: 0, outstanding: 0 });
  downloadButton.disabled = false;
  downloadCsvButton.disabled = false;
  printButton.disabled = false;
  downloadButton.style.backgroundColor = ''; // restore original
  downloadCsvButton.style.backgroundColor = '';
  printButton.style.backgroundColor = '';

  reportContent.innerHTML = `
    <article class="booking-report-document ${orientation === 'landscape' ? 'is-landscape' : ''}">
      <header class="booking-report-brand">
        <div class="booking-report-branding"><img src="img/newlogo.png" alt="iTour Mercedes seal"><div><img src="img/textlogo2.png" alt="iTour Mercedes"><span>OFFICIAL BOOKING OPERATIONS REPORT</span></div></div>
        <div class="booking-report-meta"><span>REPORT COVERAGE</span><strong>${reportEscape(currentReportFilterText)}</strong><small>Generated ${reportEscape(reportDate(new Date().toISOString(), true))}</small></div>
      </header>
      <div class="booking-report-rule"></div>
      <section class="booking-report-intro"><div><span>BOOKING MANAGEMENT</span><h3>Booking Activity Report</h3><p>Formal operational summary of reservations, guest volume, collection status, and outstanding balances.</p></div><b>${filtered.length} RECORD${filtered.length === 1 ? '' : 'S'}</b></section>
      <section class="booking-report-summary">
        <div><span>Total bookings</span><strong>${currentReportSummary.bookings.toLocaleString()}</strong><small>Reservations recorded</small></div>
        <div><span>Total guests</span><strong>${currentReportSummary.guests.toLocaleString()}</strong><small>Adults and children</small></div>
        <div><span>Booking value</span><strong>${reportMoney(currentReportSummary.value)}</strong><small>Gross booking amount</small></div>
        <div><span>Amount collected</span><strong>${reportMoney(currentReportSummary.collected)}</strong><small>Payments received</small></div>
        <div class="outstanding"><span>Outstanding</span><strong>${reportMoney(currentReportSummary.outstanding)}</strong><small>Balance to collect</small></div>
      </section>
      <div class="booking-report-table-wrap">
        <table class="booking-report-table">
          <thead><tr><th>No.</th><th>Reference & tourist</th><th>Booking details</th><th>Guests</th><th>Tour date</th><th>Financial summary</th><th>Status</th></tr></thead>
          <tbody>${filtered.map((booking, index) => `
            <tr>
              <td>${index + 1}</td>
              <td><strong>${reportEscape(booking.booking_reference || `#${booking.booking_id}`)}</strong><span>${reportEscape(booking.name || 'Guest')}</span><small>${reportEscape(booking.email || 'No email')} · ${reportEscape(booking.phone || 'No phone')}</small></td>
              <td><strong>${reportEscape(reportLabel(booking.booking_type))}</strong><span>${reportEscape(booking.display_name || 'Tour service')}</span><small>Created ${reportEscape(reportDate(booking.created_at, true))}</small></td>
              <td><strong>${Number(booking.guest_count || 0)}</strong><small>${Number(booking.num_adults || 0)} adult · ${Number(booking.num_children || 0)} child</small></td>
              <td><strong>${reportEscape(reportDate(booking.booking_date))}</strong><small>${reportEscape(reportLabel(booking.tour_type || 'Day tour'))}</small></td>
              <td><strong>${reportMoney(booking.grand_total)}</strong><span>Paid ${reportMoney(booking.payment_amount)}</span><small>Balance ${reportMoney(booking.remaining_balance)} · ${reportEscape(reportLabel(booking.payment_method))}</small></td>
              <td><span class="report-status-pill ${String(booking.payment_status).toLowerCase()}">${reportEscape(booking.payment_status)}</span><small>${reportEscape(booking.booking_status)}</small></td>
            </tr>`).join('')}</tbody>
        </table>
      </div>
      <footer class="booking-report-preview-footer"><span>iTour Mercedes · Mercedes, Camarines Norte</span><strong>Prepared by ${reportEscape(reportPreparedBy)} · Internal administrative report</strong></footer>
    </article>`;
}

// Apply filter
document.getElementById('applyReportFilter').addEventListener('click', generateReportPreview);

document.addEventListener('DOMContentLoaded', () => {
    const actionRows = Array.from(document.querySelectorAll('.row-actions'));
    const closeAllActionMenus = () => {
        actionRows.forEach(row => {
            row.classList.remove('open', 'drop-up');
            const button = row.querySelector('.action-toggle-btn');
            const menu = row.querySelector('.action-menu');
            if (button) button.setAttribute('aria-expanded', 'false');
            if (menu) {
                menu.style.removeProperty('top');
                menu.style.removeProperty('left');
            }
        });
    };

    const positionActionMenu = row => {
        const button = row.querySelector('.action-toggle-btn');
        const menu = row.querySelector('.action-menu');
        if (!button || !menu) return;

        const buttonRect = button.getBoundingClientRect();
        const menuRect = menu.getBoundingClientRect();
        const edge = 12;
        const gap = 7;
        const availableBelow = window.innerHeight - buttonRect.bottom - edge;
        const shouldOpenUp = availableBelow < menuRect.height + gap
            && buttonRect.top > menuRect.height + gap + edge;

        const left = Math.max(
            edge,
            Math.min(buttonRect.right - menuRect.width, window.innerWidth - menuRect.width - edge)
        );
        const top = shouldOpenUp
            ? Math.max(edge, buttonRect.top - menuRect.height - gap)
            : Math.min(buttonRect.bottom + gap, window.innerHeight - menuRect.height - edge);

        row.classList.toggle('drop-up', shouldOpenUp);
        menu.style.left = `${left}px`;
        menu.style.top = `${top}px`;
    };

    document.querySelectorAll('.action-toggle-btn').forEach(btn => {
        btn.addEventListener('click', (e) => {
            e.preventDefault();
            e.stopPropagation();
            const row = btn.closest('.row-actions');
            if (!row) return;
            const willOpen = !row.classList.contains('open');
            closeAllActionMenus();
            if (willOpen) {
                row.classList.add('open');
                btn.setAttribute('aria-expanded', 'true');
                positionActionMenu(row);
            }
        });
    });

    document.addEventListener('click', (e) => {
        if (!e.target.closest('.row-actions')) {
            closeAllActionMenus();
        }
    });

    window.addEventListener('resize', closeAllActionMenus);
    window.addEventListener('scroll', closeAllActionMenus, true);
    
    // Booking details modal
    document.querySelectorAll('.btn-details').forEach(btn => {
        btn.addEventListener('click', async () => {
            closeAllActionMenus();
            const bookingId = Number(btn.dataset.bookingId || 0);
            await openBookingDetailsModal(bookingId);
        });
    });

    // Tourist modal
    document.querySelectorAll('.btn-tourists').forEach(btn => {
        btn.addEventListener('click', function(e){
            e.preventDefault();
            const bookingId = this.dataset.booking;
            if(!bookingId) return;

            const modal = document.getElementById('touristModal');
            if(modal) {
                modal.dataset.bookingId = bookingId;
                modal.style.display = 'flex';
                modal.setAttribute('aria-hidden', 'false');
                showTouristAdminDocument('manifest');
            }
        });
    });

    document.querySelectorAll('[data-tourist-document]').forEach(button => {
        button.addEventListener('click', () => showTouristAdminDocument(button.dataset.touristDocument));
    });

    // Close tourist modal
    const touristModalCloseBtn = document.getElementById('touristModalClose');
    if (touristModalCloseBtn) {
        touristModalCloseBtn.addEventListener('click', closeTouristModal);
    }
    const touristModalEl = document.getElementById('touristModal');
    if (touristModalEl) {
        touristModalEl.addEventListener('click', (e) => {
            if(e.target.id === 'touristModal') closeTouristModal();
        });
    }

});

const modal = document.getElementById('expenseModal');
const closeBtn = document.getElementById('closeExpenseModal');
const iconCloseBtn = document.getElementById('expenseIconClose');
const form = document.getElementById('expenseForm');
const expenseBookingLabel = document.getElementById('expenseBookingLabel');
let previousExpenseFocus = null;

function openExpenseModal(bookingId, trigger) {
    previousExpenseFocus = trigger || document.activeElement;
    document.getElementById('expenseBookingId').value = bookingId;
    expenseBookingLabel.textContent = bookingId ? `#${bookingId}` : '—';
    modal.classList.add('active');
    modal.setAttribute('aria-hidden', 'false');
    document.body.style.overflow = 'hidden';
    window.setTimeout(() => document.getElementById('expenseType')?.focus(), 40);
}

function closeExpenseEntryModal() {
    modal.classList.remove('active');
    modal.setAttribute('aria-hidden', 'true');
    document.body.style.overflow = '';
    if (previousExpenseFocus && document.contains(previousExpenseFocus)) previousExpenseFocus.focus();
}

// OPEN MODAL
document.querySelectorAll('.add-expense-btn').forEach(btn => {
    btn.addEventListener('click', function () {
        const bookingId = this.getAttribute('data-id') || '';
        if (typeof closeBillingModal === 'function') closeBillingModal();
        openExpenseModal(bookingId, this);
    });
});

// CLOSE MODAL
closeBtn.addEventListener('click', closeExpenseEntryModal);
iconCloseBtn.addEventListener('click', closeExpenseEntryModal);

window.addEventListener('click', (e) => {
    if (e.target === modal) closeExpenseEntryModal();
});

document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && modal.classList.contains('active')) closeExpenseEntryModal();
});

// SUBMIT VIA AJAX (NO PAGE RELOAD)
form.addEventListener('submit', function (e) {
    e.preventDefault();

    const formData = new FormData(form);
    formData.append('action', 'add_expense');
    formData.append('csrf_token', adminBookingCsrf);

    fetch(window.location.href, {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {

    if (data.success) {

        closeExpenseEntryModal();
        form.reset();

        Swal.fire({
            icon: 'success',
            title: 'Success!',
            text: data.message,
            confirmButtonColor: '#2b7a66'
        }).then(() => {
            // 🔥 REFRESH AFTER USER CLICKS OK
            location.reload();
        });

    } else {

        Swal.fire({
            icon: 'error',
            title: 'Failed!',
            text: data.message
        });
    }

})
    .catch(() => {
        Swal.fire({
            icon: 'error',
            title: 'Error!',
            text: 'Something went wrong.'
        });
    });
});


document.addEventListener("DOMContentLoaded", function () {

  const modal = document.getElementById("paymentModal");
  const closeBtn = document.getElementById("closePaymentModal");

  if (!modal) {
    console.error("Payment modal not found");
    return;
  }

  // ========================
  // OPEN MODAL (MARK COMPLETE)
  // ========================
  document.addEventListener("click", function (e) {

    const btn = e.target.closest(".legacy-mark-complete-btn");
    if (!btn) return;

    e.preventDefault();

    const bookingId = btn.getAttribute("data-id");
    const balance = parseFloat(btn.getAttribute("data-balance") || 0);
    const amount = document.getElementById("paymentAmount")?.value || 0;

    const bookingInput = document.getElementById("paymentBookingId");
    const infoBox = document.getElementById("paymentInfo");

    if (!bookingId) return;

    console.log("Clicked booking:", bookingId, balance);

    // ========================
// NO BALANCE → DIRECT FINISH
// ========================
if (balance <= 0) {

  Swal.fire({
    title: "Mark as Completed?",
    text: "This booking has no remaining balance.",
    icon: "question",
    showCancelButton: true,
    confirmButtonColor: "#2b7a66",
    cancelButtonColor: "#d33",
    confirmButtonText: "Yes, complete it"
  }).then((result) => {

    if (!result.isConfirmed) return;

    const formData = new FormData();
    formData.append("action", "finish");
    formData.append("id", bookingId);
    formData.append("csrf_token", adminBookingCsrf);

    Swal.fire({
      title: "Processing...",
      text: "Completing booking...",
      allowOutsideClick: false,
      didOpen: () => Swal.showLoading()
    });

    fetch(window.location.href, {
      method: "POST",
      body: formData
    })
    .then(async (res) => {
      const text = await res.text();
      console.log("RAW RESPONSE:", text);
      return location.reload();
    })
    .catch(err => {
      console.error("Finish error:", err);

      Swal.fire({
        icon: "error",
        title: "Failed",
        text: "Could not complete booking."
      });
    });
  });

  return;
}

    // ========================
    // OPEN PAYMENT MODAL
    // ========================
    modal.style.display = "flex";

    if (bookingInput) {
      bookingInput.value = bookingId;
    } else {
      console.error("paymentBookingId not found");
    }

    if (infoBox) {
      infoBox.innerHTML = "Remaining Balance: ₱" + balance.toFixed(2);
    } else {
      console.error("paymentInfo not found");
    }
  });

  // ========================
  // CONFIRM PAYMENT
  // ========================
  document.addEventListener("click", function (e) {

    const btn = e.target.closest("#legacyConfirmPaymentBtn");
    if (!btn) return;

    e.preventDefault();

    const bookingId = document.getElementById("paymentBookingId")?.value;
    const paymentMethod = document.getElementById("paymentMethod")?.value;
const amount = document.getElementById("paymentAmount")?.value || 0;

    if (!bookingId) {
      Swal.fire("Error", "No booking selected", "error");
      return;
    }

    const formData = new FormData();
    formData.append("action", "confirm_payment");
    formData.append("id", bookingId);
    formData.append("payment_method", paymentMethod);
    formData.append("amount", amount);

    fetch(window.location.href, {
  method: "POST",
  body: formData
})
.then(async (res) => {
  const text = await res.text();

  console.log("RAW RESPONSE:", text); // 🔥 DEBUG

  try {
    return JSON.parse(text);
  } catch (e) {
    throw new Error("Server did not return JSON. Check PHP errors.");
  }
})
.then(data => {

  if (!data.success) {
    throw new Error(data.message || "Update failed");
  }

  Swal.fire({
    icon: "success",
    title: "Payment Complete",
    text: data.is_paid ? "Fully Paid!" : "Partial Payment Recorded",
    timer: 1500,
    showConfirmButton: false
  });

  modal.style.display = "none";

  setTimeout(() => location.reload(), 800);
})
.catch(err => {
  console.error("PAYMENT ERROR:", err);
  Swal.fire("Error", err.message, "error");
});
  });

  // ========================
  // CLOSE MODAL
  // ========================
  if (closeBtn) {
    closeBtn.addEventListener("click", function () {
      modal.style.display = "none";
    });
  }

  modal.addEventListener("click", function (e) {
    if (e.target === modal) {
      modal.style.display = "none";
    }
  });

  document.addEventListener("keydown", function (e) {
    if (e.key === "Escape") {
      modal.style.display = "none";
    }
  });

});

const paymentFlow = { balance: 0, completeAfterPayment: false };
const adminPayMongoCsrf = <?= json_encode($_SESSION['paymongo_admin_csrf'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
const adminBookingCsrf = <?= json_encode(AppCsrfToken('admin', 'booking_management'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
const adminPayMongoPendingKey = 'itour_admin_paymongo_pending';
const adminPayMongoCheckoutEndpoint = <?= json_encode(
  (str_contains(str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? '')), '/admin/') ? '../' : '')
  . 'payments/create-balance-checkout.php',
  JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
) ?>;
const adminPushDeviceStatusEndpoint = <?= json_encode(
  (str_contains(str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? '')), '/admin/') ? '' : 'admin/')
  . 'push-device-status.php',
  JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
) ?>;
const adminPhoneSetupPage = <?= json_encode(
  (str_contains(str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? '')), '/admin/') ? '../' : '')
  . 'admin-phone-setup.php',
  JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
) ?>;
const adminPublicAppUrl = <?= json_encode(
  (string)($adminFirebasePublicConfiguration['app_url'] ?? ''),
  JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
) ?>;
const adminPhoneRegistrationState = { device: null, baseline: null, pollTimer: null, paymentPollTimer: null };

async function readAdminPaymentJson(response, fallbackMessage) {
  const responseText = await response.text();
  try {
    return JSON.parse(responseText);
  } catch (error) {
    if (response.status === 404) {
      throw new Error('The PayMongo payment service could not be found. Refresh the page and try again.');
    }
    throw new Error(fallbackMessage);
  }
}

function closePaymentModal() {
  const paymentModal = document.getElementById('paymentModal');
  if (!paymentModal) return;
  stopPaymentDevicePolling();
  paymentModal.style.display = 'none';
  paymentModal.setAttribute('aria-hidden', 'true');
}

function openPaymentModal(bookingId, balance, completeAfterPayment = false) {
  const paymentModal = document.getElementById('paymentModal');
  const amountInput = document.getElementById('paymentAmount');
  if (!paymentModal || !bookingId || balance <= 0) return;

  closeBillingModal();
  paymentFlow.balance = Number(balance);
  paymentFlow.completeAfterPayment = Boolean(completeAfterPayment);
  document.getElementById('paymentBookingId').value = bookingId;
  document.getElementById('paymentMethod').value = '';
  document.getElementById('paymentMethod').classList.remove('has-device-panel');
  document.getElementById('qrAdminDeviceSection').hidden = true;
  document.getElementById('paymentInfo').textContent = `Remaining Balance: ${formatBookingMoney(balance)}`;
  document.getElementById('paymentModalTitle').textContent = completeAfterPayment ? 'Complete Payment & Booking' : 'Pay Balance';
  document.getElementById('paymentContextNote').textContent = completeAfterPayment
    ? 'The full balance must be collected to mark this booking as completed.'
    : 'Record the amount received. Partial payments are allowed.';
  document.getElementById('confirmPaymentBtn').textContent = completeAfterPayment ? 'Pay & Mark Completed' : 'Confirm Payment';
  amountInput.max = Number(balance).toFixed(2);
  amountInput.value = Number(balance).toFixed(2);
  amountInput.readOnly = Boolean(completeAfterPayment);
  paymentModal.style.display = 'flex';
  paymentModal.setAttribute('aria-hidden', 'false');
  setTimeout(() => completeAfterPayment ? document.getElementById('paymentMethod').focus() : amountInput.focus(), 50);
}

function clampPayBalanceAmount() {
  const amountInput = document.getElementById('paymentAmount');
  if (!amountInput) return;
  const maximum = Number(amountInput.max || paymentFlow.balance || 0);
  const entered = Number(amountInput.value);
  if (maximum > 0 && Number.isFinite(entered) && entered > maximum) {
    amountInput.value = maximum.toFixed(2);
  }
}

document.getElementById('paymentAmount')?.addEventListener('blur', clampPayBalanceAmount);
document.getElementById('paymentAmount')?.addEventListener('change', clampPayBalanceAmount);

async function fetchRegisteredAdminPhone() {
  const response = await fetch(adminPushDeviceStatusEndpoint, {
    credentials: 'same-origin',
    cache: 'no-store',
    headers: { Accept: 'application/json' }
  });
  const payload = await readAdminPaymentJson(response, 'The registered Administrator phone could not be checked.');
  if (!response.ok || !payload.success) throw new Error(payload.message || 'The registered Administrator phone could not be checked.');
  return payload;
}

function renderRegisteredAdminPhone(payload) {
  const name = document.getElementById('qrAdminDeviceName');
  const meta = document.getElementById('qrAdminDeviceMeta');
  const action = document.getElementById('manageAdminPhoneBtn');
  const modalName = document.getElementById('adminPhoneModalDeviceName');
  const modalMeta = document.getElementById('adminPhoneModalDeviceMeta');
  const modalState = document.getElementById('adminPhoneModalDeviceState');
  const modalCard = document.getElementById('adminPhoneCurrentDevice');
  const overviewName = document.getElementById('adminPhoneOverviewName');
  const overviewMeta = document.getElementById('adminPhoneOverviewMeta');
  const overviewAction = document.getElementById('adminPhoneOverviewAction');
  const overviewCard = document.getElementById('adminPhoneOverviewDevice');
  if (!name || !meta || !action) return;
  action.disabled = false;

  if (payload?.registered && payload.device) {
    adminPhoneRegistrationState.device = payload.device;
    name.textContent = payload.device.device_name || 'Administrator phone';
    const lastUsed = payload.device.last_used_at ? new Date(String(payload.device.last_used_at).replace(' ', 'T')) : null;
    meta.textContent = lastUsed && !Number.isNaN(lastUsed.getTime())
      ? `Notifications active · Registered ${lastUsed.toLocaleString()}`
      : 'Notifications active on this phone.';
    action.textContent = 'Change';
    action.dataset.mode = 'change';
    if (modalName) modalName.textContent = payload.device.device_name || 'Administrator phone';
    if (modalMeta) modalMeta.textContent = lastUsed && !Number.isNaN(lastUsed.getTime())
      ? `Notifications active · Registered ${lastUsed.toLocaleString()}`
      : 'Ready to receive PayMongo payment notifications.';
    if (modalState) modalState.textContent = 'Registered';
    modalCard?.classList.add('is-registered');
    if (overviewName) overviewName.textContent = payload.device.device_name || 'Administrator phone';
    if (overviewMeta) overviewMeta.textContent = lastUsed && !Number.isNaN(lastUsed.getTime())
      ? `Notifications active · Registered ${lastUsed.toLocaleString()}`
      : 'Ready to receive PayMongo payment notifications.';
    if (overviewAction) overviewAction.textContent = 'Change';
    overviewCard?.classList.add('is-registered');
  } else {
    adminPhoneRegistrationState.device = null;
    name.textContent = 'No admin phone registered';
    meta.textContent = 'Register a phone before using mobile payment notifications.';
    action.textContent = 'Register a Phone';
    action.dataset.mode = 'register';
    if (modalName) modalName.textContent = 'No payment phone registered';
    if (modalMeta) modalMeta.textContent = 'Use the setup instructions below to register a phone.';
    if (modalState) modalState.textContent = 'Not registered';
    modalCard?.classList.remove('is-registered');
    if (overviewName) overviewName.textContent = 'No payment phone registered';
    if (overviewMeta) overviewMeta.textContent = 'Register a phone before sending PayMongo QR notifications.';
    if (overviewAction) overviewAction.textContent = 'Register Phone';
    overviewCard?.classList.remove('is-registered');
  }
}

async function refreshRegisteredAdminPhone(silent = false) {
  const name = document.getElementById('qrAdminDeviceName');
  const meta = document.getElementById('qrAdminDeviceMeta');
  const action = document.getElementById('manageAdminPhoneBtn');
  if (!silent) {
    if (name) name.textContent = 'Checking registered phone...';
    if (meta) meta.textContent = 'Please wait.';
    if (action) action.disabled = true;
  }
  try {
    const payload = await fetchRegisteredAdminPhone();
    renderRegisteredAdminPhone(payload);
    return payload;
  } catch (error) {
    if (!silent) {
      if (action) action.disabled = false;
      if (name) name.textContent = 'Unable to check registered phone';
      if (meta) meta.textContent = error.message;
    }
    return null;
  }
}

function stopPaymentDevicePolling() {
  if (adminPhoneRegistrationState.paymentPollTimer) {
    window.clearInterval(adminPhoneRegistrationState.paymentPollTimer);
  }
  adminPhoneRegistrationState.paymentPollTimer = null;
}

function startPaymentDevicePolling() {
  stopPaymentDevicePolling();
  refreshRegisteredAdminPhone();
  adminPhoneRegistrationState.paymentPollTimer = window.setInterval(() => {
    const paymentModal = document.getElementById('paymentModal');
    const isQrOpen = paymentModal?.style.display !== 'none'
      && document.getElementById('paymentMethod')?.value === 'qr_code';
    if (!isQrOpen) {
      stopPaymentDevicePolling();
      return;
    }
    refreshRegisteredAdminPhone(true);
  }, 2500);
}

function updatePhoneRegistrationStatus(type, title, detail) {
  const status = document.getElementById('adminPhoneRegistrationStatus');
  if (!status) return;
  status.classList.toggle('is-success', type === 'success');
  status.classList.toggle('is-error', type === 'error');
  status.querySelector('strong').textContent = title;
  status.querySelector('small').textContent = detail;
}

function showAdminPhoneToast(icon, title) {
  Swal.fire({
    toast: true,
    position: 'top-end',
    icon,
    title,
    showConfirmButton: false,
    timer: 2200,
    timerProgressBar: true
  });
}

function stopAdminPhoneRegistrationPolling() {
  if (adminPhoneRegistrationState.pollTimer) window.clearInterval(adminPhoneRegistrationState.pollTimer);
  adminPhoneRegistrationState.pollTimer = null;
}

async function checkPhoneRegistrationProgress(manualCheck = false) {
  if (manualCheck) updatePhoneRegistrationStatus('', 'Checking registration...', 'Looking for a newly registered Administrator phone.');
  try {
    const payload = await fetchRegisteredAdminPhone();
    const baseline = adminPhoneRegistrationState.baseline;
    const device = payload.registered ? payload.device : null;
    const changed = device && (!baseline
      || Number(device.device_id) !== Number(baseline.device_id)
      || String(device.last_used_at) !== String(baseline.last_used_at));

    if (changed) {
      renderRegisteredAdminPhone(payload);
      stopAdminPhoneRegistrationPolling();
      updatePhoneRegistrationStatus('success', 'Admin phone registered', `${device.device_name || 'Administrator phone'} is ready for payment notifications.`);
      localStorage.setItem('itour_admin_payment_phone_changed', String(Date.now()));
      showAdminPhoneToast('success', 'Admin phone registration detected.');
      return;
    }
    if (manualCheck) {
      updatePhoneRegistrationStatus('', 'No new phone detected', `Checked ${new Date().toLocaleTimeString()}. Complete registration on the phone, then try again.`);
      showAdminPhoneToast('info', 'No new phone registration found yet.');
    } else {
      updatePhoneRegistrationStatus('', 'Waiting for phone registration', 'Keep this window open while registering the phone.');
    }
  } catch (error) {
    updatePhoneRegistrationStatus('error', 'Could not check registration', error.message);
    if (manualCheck) showAdminPhoneToast('error', 'Could not check the phone registration.');
  }
}

function buildAdminPhoneSetupUrl() {
  if (adminPublicAppUrl) {
    const publicBase = new URL(adminPublicAppUrl);
    publicBase.pathname = publicBase.pathname.endsWith('/') ? publicBase.pathname : `${publicBase.pathname}/`;
    publicBase.search = '';
    publicBase.hash = '';
    return new URL('admin-phone-setup.php', publicBase).href;
  }
  return new URL(adminPhoneSetupPage, window.location.href).href;
}

function openAdminPhoneRegistrationModal() {
  const modal = document.getElementById('adminPhoneRegistrationModal');
  if (!modal || document.getElementById('manageAdminPhoneBtn')?.disabled) return;
  adminPhoneRegistrationState.baseline = adminPhoneRegistrationState.device
    ? { ...adminPhoneRegistrationState.device }
    : null;
  const setupUrl = buildAdminPhoneSetupUrl();
  document.getElementById('adminPhoneSetupUrl').value = setupUrl;
  document.getElementById('adminPhoneRegistrationTitle').textContent = adminPhoneRegistrationState.baseline
    ? 'Change Registered Admin Phone'
    : 'Register an Admin Phone';
  updatePhoneRegistrationStatus('', 'Waiting for phone registration', 'Keep this window open while registering the phone.');
  modal.inert = false;
  modal.setAttribute('aria-hidden', 'false');
  modal.classList.add('show');
  stopAdminPhoneRegistrationPolling();
  adminPhoneRegistrationState.pollTimer = window.setInterval(checkPhoneRegistrationProgress, 2500);
}

function openAdminPaymentPhoneOverviewModal() {
  const modal = document.getElementById('adminPaymentPhoneOverviewModal');
  if (!modal) return;
  modal.inert = false;
  modal.setAttribute('aria-hidden', 'false');
  modal.classList.add('show');
}

function closeAdminPaymentPhoneOverviewModal() {
  const modal = document.getElementById('adminPaymentPhoneOverviewModal');
  if (!modal) return;
  const focused = document.activeElement;
  if (focused instanceof HTMLElement && modal.contains(focused)) focused.blur();
  modal.classList.remove('show');
  modal.setAttribute('aria-hidden', 'true');
  modal.inert = true;
}

async function requireRegisteredAdminPaymentPhone() {
  try {
    const payload = await fetchRegisteredAdminPhone();
    renderRegisteredAdminPhone(payload);
    if (payload.registered && payload.device) return true;
    const choice = await Swal.fire({
      icon: 'error',
      title: 'Payment Phone Required',
      text: 'Register an Administrator phone first before using QR Code (PayMongo).',
      showCancelButton: true,
      confirmButtonText: 'Register a Phone',
      cancelButtonText: 'Cancel',
      confirmButtonColor: '#2b7a66'
    });
    if (choice.isConfirmed) openAdminPhoneRegistrationModal();
    return false;
  } catch (error) {
    await Swal.fire('Phone Check Failed', error.message || 'The registered payment phone could not be checked.', 'error');
    return false;
  }
}

function closeAdminPhoneRegistrationModal() {
  const modal = document.getElementById('adminPhoneRegistrationModal');
  if (!modal) return;
  stopAdminPhoneRegistrationPolling();
  const focused = document.activeElement;
  if (focused instanceof HTMLElement && modal.contains(focused)) focused.blur();
  modal.classList.remove('show');
  modal.setAttribute('aria-hidden', 'true');
  modal.inert = true;
  if (document.getElementById('paymentMethod')?.value === 'qr_code') {
    refreshRegisteredAdminPhone(true);
  }
}

document.getElementById('paymentMethod')?.addEventListener('change', event => {
  const isQr = event.target.value === 'qr_code';
  const note = document.getElementById('paymentContextNote');
  const button = document.getElementById('confirmPaymentBtn');
  const deviceSection = document.getElementById('qrAdminDeviceSection');
  event.target.classList.toggle('has-device-panel', isQr);
  deviceSection.hidden = !isQr;
  if (isQr) {
    startPaymentDevicePolling();
    note.textContent = paymentFlow.completeAfterPayment
      ? 'PayMongo will display a secure QR for the tourist to scan. The booking completes only after the full payment is verified.'
      : 'PayMongo will display a secure QR for the tourist to scan. The balance updates only after payment is verified.';
    button.textContent = 'Open PayMongo QR';
  } else {
    stopPaymentDevicePolling();
    note.textContent = paymentFlow.completeAfterPayment
      ? 'The full balance must be collected to mark this booking as completed.'
      : 'Record the cash amount received. Partial payments are allowed.';
    button.textContent = paymentFlow.completeAfterPayment ? 'Pay & Mark Completed' : 'Confirm Payment';
  }
});

document.getElementById('manageAdminPhoneBtn')?.addEventListener('click', openAdminPhoneRegistrationModal);
document.getElementById('openAdminPaymentPhoneModal')?.addEventListener('click', async event => {
  const button = event.currentTarget;
  const originalContent = button.innerHTML;
  button.disabled = true;
  button.classList.add('is-loading');
  button.innerHTML = '<span class="admin-toolbar-phone-spinner" aria-hidden="true"></span><span>Checking Phone...</span>';
  try {
    const payload = await refreshRegisteredAdminPhone();
    if (!payload) throw new Error('The registered payment phone could not be checked.');
    openAdminPaymentPhoneOverviewModal();
  } catch (error) {
    await Swal.fire('Phone Check Failed', error.message, 'error');
  } finally {
    button.disabled = false;
    button.classList.remove('is-loading');
    button.innerHTML = originalContent;
  }
});
document.getElementById('adminPhoneOverviewAction')?.addEventListener('click', () => {
  closeAdminPaymentPhoneOverviewModal();
  openAdminPhoneRegistrationModal();
});
document.querySelectorAll('[data-close-phone-overview]').forEach(button => button.addEventListener('click', closeAdminPaymentPhoneOverviewModal));
document.getElementById('adminPaymentPhoneOverviewModal')?.addEventListener('mousedown', event => {
  if (event.target.id === 'adminPaymentPhoneOverviewModal') closeAdminPaymentPhoneOverviewModal();
});
document.querySelectorAll('[data-close-phone-registration]').forEach(button => button.addEventListener('click', closeAdminPhoneRegistrationModal));
document.getElementById('checkAdminPhoneRegistration')?.addEventListener('click', async event => {
  const button = event.currentTarget;
  const originalText = button.textContent;
  button.disabled = true;
  button.textContent = 'Checking...';
  try {
    await checkPhoneRegistrationProgress(true);
  } finally {
    button.disabled = false;
    button.textContent = originalText;
  }
});
document.getElementById('openAdminPhoneSetupPage')?.addEventListener('click', () => {
  const url = document.getElementById('adminPhoneSetupUrl').value;
  if (url) window.open(url, '_blank', 'noopener');
});
document.getElementById('copyAdminPhoneSetupUrl')?.addEventListener('click', async event => {
  const input = document.getElementById('adminPhoneSetupUrl');
  let copied = false;
  try {
    if (navigator.clipboard?.writeText && window.isSecureContext) {
      await navigator.clipboard.writeText(input.value);
      copied = true;
    } else {
      const helper = document.createElement('textarea');
      helper.value = input.value;
      helper.setAttribute('readonly', '');
      helper.style.position = 'fixed';
      helper.style.left = '-9999px';
      helper.style.opacity = '0';
      document.body.appendChild(helper);
      helper.select();
      copied = document.execCommand('copy');
      helper.remove();
    }
  } catch (error) {
    copied = false;
  }
  input.blur();
  window.getSelection()?.removeAllRanges();
  if (copied) showAdminPhoneToast('success', 'Phone setup link copied.');
  else showAdminPhoneToast('error', 'The link could not be copied.');
});
window.addEventListener('storage', event => {
  if (event.key === 'itour_admin_payment_phone_changed') refreshRegisteredAdminPhone(true);
});
document.getElementById('adminPhoneRegistrationModal')?.addEventListener('mousedown', event => {
  if (event.target.id === 'adminPhoneRegistrationModal') closeAdminPhoneRegistrationModal();
});

function completeBookingWithoutBalance(bookingId) {
  Swal.fire({
    title: 'Mark as Completed?',
    text: 'This booking is fully paid and ready to be completed.',
    icon: 'question',
    showCancelButton: true,
    confirmButtonColor: '#2b7a66',
    cancelButtonColor: '#6b7e78',
    confirmButtonText: 'Yes, complete it'
  }).then(result => {
    if (!result.isConfirmed) return;
    const formData = new FormData();
    formData.append('action', 'finish');
    formData.append('id', bookingId);
    formData.append('csrf_token', adminBookingCsrf);
    fetch(window.location.href, { method: 'POST', body: formData })
      .then(response => {
        if (!response.ok) throw new Error('Could not complete booking.');
        location.reload();
      })
      .catch(error => Swal.fire('Unable to Complete', error.message, 'error'));
  });
}

async function cancelAdminPayMongoPayment(bookingId, returnToken) {
  const form = new FormData();
  form.append('action', 'cancel_pending');
  form.append('type', 'tour');
  form.append('id', String(bookingId));
  form.append('return_token', String(returnToken));
  form.append('csrf_token', adminPayMongoCsrf);
  const response = await fetch(adminPayMongoCheckoutEndpoint, {
    method: 'POST',
    body: form,
    headers: { Accept: 'application/json' }
  });
  const result = await readAdminPaymentJson(response, 'The pending QR payment could not be cancelled.');
  if (!response.ok || !result.success) throw new Error(result.message || 'The pending QR payment could not be cancelled.');
  return Boolean(result.cancelled);
}

async function monitorAdminPhoneQrPayment(token, bookingId = 0) {
  if (!/^[a-f0-9]{64}$/.test(token)) return false;
  let trackedBookingId = Number(bookingId || 0);
  let cancelRequested = false;
  Swal.fire({
    title: 'QR Sent to Admin Phone',
    text: 'Waiting for the tourist payment. This booking will update automatically after PayMongo verifies it.',
    allowOutsideClick: false,
    allowEscapeKey: false,
    showCancelButton: true,
    showConfirmButton: false,
    cancelButtonText: 'Cancel Payment',
    cancelButtonColor: '#b5444f',
    didOpen: () => Swal.showLoading()
  }).then(result => {
    if (result.dismiss === Swal.DismissReason.cancel) cancelRequested = true;
  });

  for (let attempt = 0; attempt < 120; attempt += 1) {
    if (cancelRequested) {
      sessionStorage.removeItem(adminPayMongoPendingKey);
      try {
        Swal.fire({
          title: 'Cancelling Payment',
          text: 'Closing the pending PayMongo checkout…',
          allowOutsideClick: false,
          allowEscapeKey: false,
          showConfirmButton: false,
          didOpen: () => Swal.showLoading()
        });
        const cancelled = trackedBookingId > 0 && await cancelAdminPayMongoPayment(trackedBookingId, token);
        await Swal.fire({
          icon: cancelled ? 'info' : 'warning',
          title: cancelled ? 'Payment Cancelled' : 'Nothing to Cancel',
          text: cancelled
            ? 'The pending PayMongo payment was cancelled and logged in transactions. No amount was applied to the booking.'
            : 'No active pending payment was found. Refresh the page to confirm the latest payment status.',
          confirmButtonColor: '#2b7a66'
        });
        if (cancelled) window.location.reload();
      } catch (error) {
        await Swal.fire('Cancellation Not Confirmed', error.message || 'Wait for PayMongo payment verification before trying again.', 'warning');
      }
      return false;
    }
    try {
      const response = await fetch(`adbookings.php?action=paymongoPaymentStatus&token=${encodeURIComponent(token)}`, {
        headers: { Accept: 'application/json' },
        cache: 'no-store'
      });
      const result = await readAdminPaymentJson(response, 'The payment status service returned an invalid response.');
      if (!response.ok || !result.success) throw new Error(result.message || 'Payment status could not be checked.');
      trackedBookingId = Number(result.booking_id || trackedBookingId);
      if (result.status === 'paid') {
        sessionStorage.removeItem(adminPayMongoPendingKey);
        await Swal.fire({
          icon: 'success',
          title: 'QR Payment Verified',
          text: `${formatBookingMoney(result.amount)} was applied to Booking ${result.booking_reference || result.booking_id}.`,
          confirmButtonColor: '#2b7a66'
        });
        window.location.reload();
        return true;
      }
      if (result.status === 'cancelled') {
        sessionStorage.removeItem(adminPayMongoPendingKey);
        await Swal.fire('Payment Cancelled', 'The pending PayMongo payment was cancelled and logged in transactions. No amount was applied to the booking.', 'info');
        window.location.reload();
        return false;
      }
      if (result.status === 'failed') {
        sessionStorage.removeItem(adminPayMongoPendingKey);
        Swal.fire('Payment Not Completed', 'The phone QR payment was not completed.', 'warning');
        return false;
      }
    } catch (_) {
      // Keep polling through brief ngrok, PayMongo, or network interruptions.
    }
    await new Promise(resolve => setTimeout(resolve, 2500));
  }

  Swal.fire('Confirmation Pending', 'The QR remains active, but confirmation is taking longer than expected. Refresh the page to check again.', 'info');
  return false;
}

document.addEventListener('click', event => {
  const completeButton = event.target.closest('.mark-complete-btn');
  if (!completeButton) return;
  event.preventDefault();
  const bookingId = completeButton.dataset.id;
  const balance = Number(completeButton.dataset.balance || 0);
  if (balance > 0) openPaymentModal(bookingId, balance, true);
  else completeBookingWithoutBalance(bookingId);
});

function setPayMongoButtonLoading(button, loading, idleLabel = 'Open PayMongo QR') {
  if (!button) return;
  button.disabled = loading;
  button.classList.toggle('is-paymongo-loading', loading);
  if (loading) {
    button.setAttribute('aria-busy', 'true');
    button.innerHTML = '<span class="paymongo-button-spinner" aria-hidden="true"></span><span>Opening PayMongo…</span>';
  } else {
    button.removeAttribute('aria-busy');
    button.textContent = idleLabel;
  }
}

document.getElementById('confirmPaymentBtn')?.addEventListener('click', async () => {
  clampPayBalanceAmount();
  const bookingId = document.getElementById('paymentBookingId').value;
  const paymentMethod = document.getElementById('paymentMethod').value;
  const amount = Number(document.getElementById('paymentAmount').value || 0);
  if (!paymentMethod) {
    Swal.fire('Payment Method Required', 'Select Cash or QR Code before recording the payment.', 'warning');
    return;
  }
  if (!bookingId || amount <= 0) {
    Swal.fire('Payment Required', 'Enter an amount greater than zero.', 'warning');
    return;
  }
  if (amount > paymentFlow.balance + 0.009) {
    Swal.fire('Invalid Amount', 'Payment cannot exceed the current balance.', 'warning');
    return;
  }
  if (paymentFlow.completeAfterPayment && Math.abs(amount - paymentFlow.balance) > 0.009) {
    Swal.fire('Full Payment Required', 'Collect the entire balance before completing this booking.', 'warning');
    return;
  }

  const confirmButton = document.getElementById('confirmPaymentBtn');
  confirmButton.disabled = true;
  const originalButtonText = confirmButton.textContent;

  if (paymentMethod === 'qr_code') {
    if (!(await requireRegisteredAdminPaymentPhone())) {
      confirmButton.disabled = false;
      return;
    }
    setPayMongoButtonLoading(confirmButton, true, originalButtonText);
    const checkoutData = new FormData();
    checkoutData.append('type', 'tour');
    checkoutData.append('id', bookingId);
    checkoutData.append('amount', amount.toFixed(2));
    checkoutData.append('csrf_token', adminPayMongoCsrf);
    if (paymentFlow.completeAfterPayment) checkoutData.append('complete_after_payment', '1');
    try {
      while (true) {
        const response = await fetch(adminPayMongoCheckoutEndpoint, {
          method: 'POST',
          body: checkoutData,
          headers: { Accept: 'application/json' }
        });
        const data = await readAdminPaymentJson(response, 'The PayMongo payment service returned an invalid response. Please try again.');
        if (!response.ok || !data.success) throw new Error(data.message || 'The PayMongo QR payment page could not be opened.');
        const checkoutUrl = new URL(data.checkout_url);
        if (checkoutUrl.protocol !== 'https:' || (checkoutUrl.hostname !== 'checkout.paymongo.com' && !checkoutUrl.hostname.endsWith('.paymongo.com'))) {
          throw new Error('PayMongo returned an invalid checkout URL.');
        }
        sessionStorage.setItem(adminPayMongoPendingKey, JSON.stringify({
          type: 'tour',
          id: Number(bookingId),
          token: String(data.return_token || ''),
          phoneHandoff: Boolean(data.phone_notification?.sent),
          startedAt: Date.now()
        }));
        if (data.phone_notification?.sent && /^[a-f0-9]{64}$/.test(String(data.return_token || ''))) {
          setPayMongoButtonLoading(confirmButton, false, originalButtonText);
          closePaymentModal();
          await monitorAdminPhoneQrPayment(String(data.return_token), Number(bookingId));
          return;
        }
        if (data.phone_notification && !data.phone_notification.sent) {
          setPayMongoButtonLoading(confirmButton, false, originalButtonText);
          const fallbackChoice = await Swal.fire({
            icon: 'warning',
            iconColor: '#c78524',
            title: 'Phone Notification Not Sent',
            text: data.phone_notification.message || 'The QR was created, but it could not be delivered to the registered phone.',
            showDenyButton: true,
            showCancelButton: true,
            confirmButtonText: 'Open QR on This Computer',
            denyButtonText: 'Retry Notification',
            cancelButtonText: 'Close',
            confirmButtonColor: '#176b55',
            denyButtonColor: '#2f7a64',
            cancelButtonColor: '#687b75',
            reverseButtons: true,
            focusConfirm: true,
            customClass: { actions: 'paymongo-phone-alert-actions' }
          });
          if (fallbackChoice.isConfirmed) {
            window.location.assign(checkoutUrl.href);
            return;
          }
          if (fallbackChoice.isDenied) {
            setPayMongoButtonLoading(confirmButton, true, originalButtonText);
            continue;
          }
          setPayMongoButtonLoading(confirmButton, false, originalButtonText);
          return;
        }
        window.location.assign(checkoutUrl.href);
        return;
      }
    } catch (error) {
      Swal.fire('QR Payment Failed', error.message, 'error');
      setPayMongoButtonLoading(confirmButton, false, originalButtonText);
      return;
    }
  }

  const formData = new FormData();
  formData.append('action', 'confirm_payment');
  formData.append('id', bookingId);
  formData.append('payment_method', paymentMethod);
  formData.append('amount', amount.toFixed(2));
  formData.append('csrf_token', adminPayMongoCsrf);
  if (paymentFlow.completeAfterPayment) formData.append('complete_after_payment', '1');

  try {
    const response = await fetch(window.location.href, { method: 'POST', body: formData });
    const data = await response.json();
    if (!response.ok || !data.success) throw new Error(data.message || 'Payment could not be recorded.');
    closePaymentModal();
    await Swal.fire({
      icon: 'success',
      title: data.completed ? 'Booking Completed' : 'Payment Recorded',
      text: data.completed ? 'The balance was paid and the booking was marked completed.' : (data.is_paid ? 'The booking is now fully paid.' : 'The partial payment was recorded.'),
      confirmButtonColor: '#2b7a66'
    });
    location.reload();
  } catch (error) {
    Swal.fire('Payment Failed', error.message, 'error');
  } finally {
    confirmButton.disabled = false;
  }
});

document.getElementById('closePaymentModal')?.addEventListener('click', closePaymentModal);
document.getElementById('paymentIconClose')?.addEventListener('click', closePaymentModal);
document.getElementById('paymentModal')?.addEventListener('mousedown', event => {
  if (event.target.id === 'paymentModal') closePaymentModal();
});

async function handleAdminPayMongoReturn() {
  const params = new URLSearchParams(window.location.search);
  const returnType = params.get('payment_return');
  const token = params.get('payment_return_token') || '';
  if (!['paymongo', 'cancelled'].includes(returnType) || !/^[a-f0-9]{64}$/.test(token)) return;
  sessionStorage.removeItem(adminPayMongoPendingKey);

  const cleanReturnUrl = () => {
    const url = new URL(window.location.href);
    url.searchParams.delete('payment_return');
    url.searchParams.delete('payment_return_token');
    history.replaceState({}, '', url.href);
  };
  if (returnType === 'cancelled') {
    cleanReturnUrl();
    Swal.fire('QR Payment Cancelled', 'No payment was recorded and the booking balance was not changed.', 'info');
    return;
  }

  Swal.fire({
    title: 'Verifying QR Payment',
    text: 'Waiting for PayMongo confirmation. Do not record this payment manually.',
    allowOutsideClick: false,
    allowEscapeKey: false,
    didOpen: () => Swal.showLoading()
  });

  for (let attempt = 0; attempt < 15; attempt += 1) {
    try {
      const response = await fetch(`adbookings.php?action=paymongoPaymentStatus&token=${encodeURIComponent(token)}`, {
        headers: { Accept: 'application/json' },
        cache: 'no-store'
      });
      const result = await readAdminPaymentJson(response, 'The payment status service returned an invalid response.');
      if (!response.ok || !result.success) throw new Error(result.message || 'Payment status could not be checked.');
      if (result.status === 'paid') {
        cleanReturnUrl();
        await Swal.fire({
          icon: 'success',
          title: 'QR Payment Verified',
          text: `${formatBookingMoney(result.amount)} was applied to Booking ${result.booking_reference || result.booking_id}.`,
          confirmButtonColor: '#2b7a66'
        });
        window.location.reload();
        return;
      }
      if (['failed', 'cancelled'].includes(result.status)) {
        cleanReturnUrl();
        Swal.fire('Payment Not Completed', 'PayMongo did not verify a payment. The booking balance was not changed.', 'warning');
        return;
      }
    } catch (error) {
      if (attempt === 14) {
        cleanReturnUrl();
        Swal.fire('Confirmation Pending', 'PayMongo confirmation has not arrived yet. Refresh the bookings page shortly; no payment will be applied until it is verified.', 'info');
        return;
      }
    }
    await new Promise(resolve => setTimeout(resolve, 2000));
  }
  cleanReturnUrl();
  Swal.fire('Confirmation Pending', 'PayMongo confirmation is still pending. Refresh shortly; the booking will update only after verification.', 'info');
}

handleAdminPayMongoReturn();

window.addEventListener('pageshow', async event => {
  const navigation = performance.getEntriesByType('navigation')[0];
  const returnedByHistory = event.persisted || navigation?.type === 'back_forward';
  const returnType = new URLSearchParams(window.location.search).get('payment_return');
  const pendingRaw = sessionStorage.getItem(adminPayMongoPendingKey);
  if (!returnedByHistory || returnType || !pendingRaw) return;

  sessionStorage.removeItem(adminPayMongoPendingKey);
  closePaymentModal();
  try {
    const pending = JSON.parse(pendingRaw);
    if (!pending?.id) return;
    const form = new FormData();
    form.append('action', 'cancel_pending');
    form.append('type', 'tour');
    form.append('id', String(pending.id));
    form.append('return_token', String(pending.token || ''));
    form.append('csrf_token', adminPayMongoCsrf);
    const response = await fetch(adminPayMongoCheckoutEndpoint, {
      method: 'POST',
      body: form,
      headers: {Accept: 'application/json'}
    });
    const result = await readAdminPaymentJson(response, 'The pending QR payment could not be closed.');
    if (!response.ok || !result.success) throw new Error(result.message || 'The pending QR payment could not be closed.');
    Swal.fire('QR Payment Cancelled', 'No payment was recorded. A new QR payment can be started when needed.', 'info');
  } catch (error) {
    Swal.fire('Payment Status Notice', 'The payment was not recorded. Refresh before starting another QR payment.', 'info');
  }
});

const billingState = { bookingId: null, balance: 0, paid: false, receipt: null };
const billingExpenseLabels = {
  additional_boat: 'Additional Boat',
  additional_tourguide: 'Additional Tour Guide',
  food: 'Food',
  others: 'Other Expense'
};

function closeBillingModal() {
  const billingModal = document.getElementById('billingModal');
  if (!billingModal) return;
  billingModal.classList.remove('show');
  billingModal.setAttribute('aria-hidden', 'true');
  document.body.classList.remove('billing-modal-open');
}

async function openBillingModal(bookingId) {
  const billingModal = document.getElementById('billingModal');
  const body = document.getElementById('billingModalBody');
  const subtitle = document.getElementById('billingModalSubtitle');
  if (!billingModal || !body || !bookingId) return;

  billingState.bookingId = bookingId;
  billingState.balance = 0;
  billingState.paid = false;
  billingState.receipt = null;
  billingModal.classList.add('show');
  billingModal.setAttribute('aria-hidden', 'false');
  document.body.classList.add('billing-modal-open');
  subtitle.textContent = 'Loading current charges and payment information…';
  body.innerHTML = '<div class="billing-loading"><span></span><p>Preparing billing statement…</p></div>';
  const payButton = document.getElementById('billingPayBalance');
  const paidNote = document.getElementById('billingPaidNote');
  const receiptButton = document.getElementById('openBookingReceipt');
  payButton.disabled = true;
  receiptButton.disabled = true;
  paidNote.hidden = true;

  try {
    const response = await fetch(`adbookings.php?action=fetchBookingDetails&id=${encodeURIComponent(bookingId)}`, { headers: { Accept: 'application/json' } });
    const payload = await response.json();
    if (!response.ok || !payload.success || !payload.booking) throw new Error(payload.message || 'Billing details could not be loaded.');

    const booking = payload.booking;
    const expenses = Array.isArray(payload.expenses) ? payload.expenses : [];
    const expenseTotal = expenses.reduce((sum, item) => sum + Number(item.amount || 0), 0);
    const amountPaid = Number(booking.payment_amount || 0);
    const balance = Math.max(0, Number(booking.remaining_balance || 0));
    const storedTotal = Number(booking.grand_total || 0);
    const billingTotal = Math.max(storedTotal, amountPaid + balance);
    const serviceAmount = Math.max(0, billingTotal - expenseTotal);
    const isPaid = Number(booking.is_paid || 0) === 1 || balance <= 0;
    const paymentStatus = isPaid ? 'Paid' : amountPaid > 0 ? 'Partial' : 'Unpaid';
    const meta = parseBookingMeta(booking.preferred_resource);
    const serviceName = booking.package_name || booking.boat_name || booking.guide_name || booking.location || meta.preferred || '-';
    const expenseRows = expenses.length
      ? expenses.map(item => {
          const rawType = String(item.expense_type || 'Expense');
          const label = billingExpenseLabels[rawType] || rawType.replace(/_/g, ' ').replace(/\b\w/g, letter => letter.toUpperCase());
          const note = String(item.note || '').trim();
          return `<div class="billing-line"><span>${escapeBookingDetail(label)}${note ? `<small class="billing-line-note">${escapeBookingDetail(note)}</small>` : ''}</span><strong>${formatBookingMoney(item.amount)}</strong></div>`;
        }).join('')
      : '<div class="billing-line"><span>No additional expenses</span><strong>₱0.00</strong></div>';

    billingState.balance = balance;
    billingState.paid = isPaid;
    billingState.receipt = {
      booking,
      expenses,
      serviceName,
      serviceAmount,
      amountPaid,
      balance,
      billingTotal,
      paymentStatus,
      completed: String(booking.is_complete || '').toLowerCase() === 'completed'
    };
    subtitle.textContent = `${booking.t_full_name || 'Guest'} · ${serviceName}`;
    body.innerHTML = `
      <div class="billing-summary-top">
        <div class="billing-reference"><small>BOOKING REFERENCE</small><strong>${escapeBookingDetail(booking.booking_reference || booking.booking_id)}</strong></div>
        <span class="billing-status ${paymentStatus.toLowerCase()}">${paymentStatus}</span>
      </div>
      <div class="billing-party-card">
        <div><small>BILLED TO</small><strong>${escapeBookingDetail(booking.t_full_name || 'Guest')}</strong></div>
        <div><small>TOUR / SERVICE</small><strong>${escapeBookingDetail(serviceName)}</strong></div>
      </div>
      <h4 class="billing-section-title">Detailed Charges</h4>
      <div class="billing-lines">
        <div class="billing-line"><span>Tour service amount</span><strong>${formatBookingMoney(serviceAmount)}</strong></div>
        ${expenseRows}
        <div class="billing-line subtotal"><span>Additional expenses</span><strong>${formatBookingMoney(expenseTotal)}</strong></div>
        <div class="billing-line total"><span>Total amount due</span><strong>${formatBookingMoney(billingTotal)}</strong></div>
      </div>
      <h4 class="billing-section-title">Payment Summary</h4>
      <div class="billing-stats">
        <div class="billing-stat"><small>AMOUNT PAID</small><strong>${formatBookingMoney(amountPaid)}</strong></div>
        <div class="billing-stat balance ${isPaid ? 'is-zero' : ''}"><small>CURRENT BALANCE</small><strong>${formatBookingMoney(balance)}</strong></div>
        <div class="billing-stat"><small>PAYMENT STATUS</small><strong>${paymentStatus}</strong></div>
        <div class="billing-stat"><small>LAST PAYMENT METHOD</small><strong>${escapeBookingDetail(booking.payment_method ? String(booking.payment_method).replace(/_/g, ' ').toUpperCase() : 'Not recorded')}</strong></div>
      </div>`;

    document.getElementById('billingAddExpense').dataset.id = bookingId;
    receiptButton.disabled = false;
    payButton.disabled = isPaid;
    paidNote.hidden = !isPaid;
  } catch (error) {
    receiptButton.disabled = true;
    subtitle.textContent = 'Billing statement unavailable';
    body.innerHTML = `<div class="billing-error"><strong>Unable to load billing details</strong><span>${escapeBookingDetail(error.message)}</span></div>`;
  }
}

function setAdminReceiptModal(open) {
  const modal = document.getElementById('adminReceiptModal');
  if (!modal) return;
  if (open) {
    modal.inert = false;
    modal.setAttribute('aria-hidden', 'false');
    modal.classList.add('show');
    return;
  }
  const focused = document.activeElement;
  if (focused instanceof HTMLElement && modal.contains(focused)) focused.blur();
  modal.classList.remove('show');
  modal.setAttribute('aria-hidden', 'true');
  modal.inert = true;
}

function openAdminBookingReceipt() {
  const receipt = billingState.receipt;
  const paper = document.getElementById('adminReceiptPaper');
  if (!receipt || !paper) return;

  const booking = receipt.booking || {};
  const reference = booking.booking_reference || booking.booking_id || '-';
  const guest = booking.t_full_name || 'Guest';
  const bookingType = String(booking.booking_type || 'Tour').replace(/_/g, ' ');
  const bookingTypeLabel = `${bookingType.charAt(0).toUpperCase()}${bookingType.slice(1)} reservation`;
  const receiptLogo = new URL('img/newlogo.png', window.location.href).href;
  const receiptWordmark = new URL('img/textlogo2.png', window.location.href).href;
  const expenseRows = receipt.expenses.length
    ? receipt.expenses.map(item => {
        const rawType = String(item.expense_type || 'Expense');
        const label = billingExpenseLabels[rawType] || rawType.replace(/_/g, ' ').replace(/\b\w/g, letter => letter.toUpperCase());
        return `<tr><td>${escapeBookingDetail(label)}</td><td>${formatBookingMoney(item.amount)}</td></tr>`;
      }).join('')
    : `<tr><td>No additional charges</td><td>${formatBookingMoney(0)}</td></tr>`;

  paper.innerHTML = `
    <div class="receipt-brand">
      <div class="receipt-brand-identity"><img class="receipt-brand-logo" src="${receiptLogo}" alt="iTour Mercedes seal"><div><img class="receipt-brand-wordmark" src="${receiptWordmark}" alt="iTour Mercedes"><p>OFFICIAL BOOKING RECEIPT</p></div></div>
      <div class="receipt-number"><small>RECEIPT REFERENCE</small><strong>${escapeBookingDetail(reference)}</strong>${receipt.completed ? '<span class="receipt-paid-stamp">COMPLETED</span>' : ''}</div>
    </div>
    <div class="receipt-meta">
      <div><small>BILLED TO</small><strong>${escapeBookingDetail(guest)}</strong></div>
      <div><small>SERVICE</small><strong>${escapeBookingDetail(receipt.serviceName)}</strong></div>
      <div><small>BOOKING TYPE</small><strong>${escapeBookingDetail(bookingTypeLabel)}</strong></div>
      <div><small>RECEIPT STATUS</small><strong>${receipt.completed ? 'Final receipt' : 'Preview only'}</strong></div>
    </div>
    <table class="receipt-table">
      <thead><tr><th>DESCRIPTION</th><th>AMOUNT</th></tr></thead>
      <tbody>
        <tr><td>Tour service</td><td>${formatBookingMoney(receipt.serviceAmount)}</td></tr>
        ${expenseRows}
        <tr class="receipt-total"><td>TOTAL</td><td>${formatBookingMoney(receipt.billingTotal)}</td></tr>
      </tbody>
    </table>
    <div class="receipt-summary">
      <div><small>AMOUNT PAID</small><strong>${formatBookingMoney(receipt.amountPaid)}</strong></div>
      <div><small>BALANCE</small><strong>${formatBookingMoney(receipt.balance)}</strong></div>
      <div><small>PAYMENT STATUS</small><strong>${escapeBookingDetail(receipt.paymentStatus)}</strong></div>
    </div>
    <div class="receipt-foot">This receipt reflects the booking account at the time it was generated. Please retain it as your payment and reservation record.</div>`;

  setAdminReceiptModal(true);
}

function printAdminBookingReceipt() {
  const paper = document.getElementById('adminReceiptPaper');
  if (!paper?.innerHTML.trim()) return;
  const printWindow = window.open('', '_blank', 'width=900,height=720');
  if (!printWindow) {
    Swal.fire('Print Blocked', 'Allow pop-ups for this site, then try printing the receipt again.', 'warning');
    return;
  }
  const stylesheet = Array.from(document.styleSheets).find(sheet => String(sheet.href || '').includes('styles/admin_receipt.css'))?.href
    || new URL('styles/admin_receipt.css', window.location.href).href;
  printWindow.document.write(`<!doctype html><html><head><title>Booking Receipt</title><link rel="stylesheet" href="${stylesheet}"><style>body{margin:0;padding:20px;background:#fff}.admin-receipt-paper{width:560px;min-height:0;margin:auto;box-shadow:none}@page{size:A4 portrait;margin:12mm}@media print{body{padding:0}.admin-receipt-paper{width:100%;max-width:560px}}</style></head><body><article class="admin-receipt-paper">${paper.innerHTML}</article></body></html>`);
  printWindow.document.close();
  printWindow.addEventListener('load', () => setTimeout(() => {
    printWindow.focus();
    printWindow.print();
  }, 150));
}

async function downloadAdminBookingReceipt() {
  const paper = document.getElementById('adminReceiptPaper');
  const button = document.getElementById('downloadAdminReceipt');
  if (!paper?.innerHTML.trim() || !window.html2canvas || !window.jspdf?.jsPDF) {
    Swal.fire('Download Unavailable', 'The PDF tools did not load. Refresh the page and try again.', 'error');
    return;
  }

  button.disabled = true;
  const originalText = button.textContent;
  button.textContent = 'Preparing...';
  try {
    const canvas = await html2canvas(paper, { scale: 2, backgroundColor: '#ffffff', useCORS: true });
    const { jsPDF } = window.jspdf;
    const pdf = new jsPDF({ orientation: 'portrait', unit: 'mm', format: 'a4' });
    const maxWidth = 190;
    const maxHeight = 277;
    const scale = Math.min(maxWidth / canvas.width, maxHeight / canvas.height);
    const width = canvas.width * scale;
    const height = canvas.height * scale;
    pdf.addImage(canvas.toDataURL('image/png'), 'PNG', (210 - width) / 2, 10, width, height, undefined, 'FAST');
    const reference = String(billingState.receipt?.booking?.booking_reference || billingState.bookingId || 'booking').replace(/[^a-z0-9_-]+/gi, '-');
    pdf.save(`booking-receipt-${reference}.pdf`);
  } catch (error) {
    Swal.fire('Download Failed', 'The booking receipt could not be prepared. Please try again.', 'error');
  } finally {
    button.disabled = false;
    button.textContent = originalText;
  }
}

document.addEventListener('click', event => {
  const billingButton = event.target.closest('.billing-btn');
  if (billingButton) {
    event.preventDefault();
    openBillingModal(billingButton.dataset.id);
  }
});
document.getElementById('billingPayBalance')?.addEventListener('click', () => {
  if (!billingState.paid && billingState.balance > 0) openPaymentModal(billingState.bookingId, billingState.balance, false);
});
document.getElementById('openBookingReceipt')?.addEventListener('click', openAdminBookingReceipt);
document.getElementById('printAdminReceipt')?.addEventListener('click', printAdminBookingReceipt);
document.getElementById('downloadAdminReceipt')?.addEventListener('click', downloadAdminBookingReceipt);
document.querySelectorAll('[data-close-admin-receipt]').forEach(button => button.addEventListener('click', () => setAdminReceiptModal(false)));
document.getElementById('adminReceiptModal')?.addEventListener('mousedown', event => {
  if (event.target.id === 'adminReceiptModal') setAdminReceiptModal(false);
});
document.getElementById('closeBillingModal')?.addEventListener('click', closeBillingModal);
document.getElementById('billingIconClose')?.addEventListener('click', closeBillingModal);
document.getElementById('billingModal')?.addEventListener('mousedown', event => {
  if (event.target.id === 'billingModal') closeBillingModal();
});

const requestedBillingBookingId = Number(new URLSearchParams(window.location.search).get('open_billing') || 0);
if (requestedBillingBookingId > 0) {
  const cleanBillingUrl = new URL(window.location.href);
  cleanBillingUrl.searchParams.delete('open_billing');
  history.replaceState({}, '', cleanBillingUrl.href);
  openBillingModal(requestedBillingBookingId);
}
document.addEventListener('keydown', event => {
  if (event.key === 'Escape') {
    closeAdminPhoneRegistrationModal();
    setAdminReceiptModal(false);
    closeBillingModal();
    closePaymentModal();
  }
});

function parseBookingMeta(preferredResource) {
  const result = {
    preferred: '-',
    addOn: '-',
    boatBill: '-',
    paymentOption: '-',
    payNow: '-',
    paymentStatus: 'Unpaid'
  };
  const raw = String(preferredResource || '').trim();
  if (!raw) return result;

  const parts = raw.split('|').map(p => p.trim()).filter(Boolean);
  for (const part of parts) {
    const lower = part.toLowerCase();
    if (lower.startsWith('preferred:')) {
      result.preferred = part.substring(part.indexOf(':') + 1).trim() || '-';
    } else if (lower.startsWith('add-on:')) {
      result.addOn = part.substring(part.indexOf(':') + 1).trim() || '-';
    } else if (lower.startsWith('boat bill:') || lower.startsWith('boat bill total:')) {
      result.boatBill = part.substring(part.indexOf(':') + 1).trim() || '-';
    } else if (lower.startsWith('payment:') || lower.startsWith('payment option:')) {
      result.paymentOption = part.substring(part.indexOf(':') + 1).trim() || '-';
    } else if (lower.startsWith('payment status:')) {
      result.paymentStatus = part.substring(part.indexOf(':') + 1).trim() || 'Unpaid';
    } else if (lower.startsWith('pay now:')) {
      result.payNow = part.substring(part.indexOf(':') + 1).trim() || '-';
    }
  }

  if (result.paymentStatus === 'Unpaid' && result.paymentOption !== '-') {
    const paymentOption = String(result.paymentOption).toLowerCase();
    if (paymentOption.includes('partial') || paymentOption.includes('20%')) {
      result.paymentStatus = 'Partial';
    } else if (paymentOption.includes('full')) {
      result.paymentStatus = 'Paid';
    }
  }

  return result;
}

const bookingDetailIcons = {
  guest: '<svg viewBox="0 0 24 24"><path d="M20 21a8 8 0 0 0-16 0"/><circle cx="12" cy="7" r="4"/></svg>',
  calendar: '<svg viewBox="0 0 24 24"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 10h18"/></svg>',
  map: '<svg viewBox="0 0 24 24"><path d="m9 18-6 3V6l6-3 6 3 6-3v15l-6 3-6-3Z"/><path d="M9 3v15M15 6v15"/></svg>',
  users: '<svg viewBox="0 0 24 24"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/></svg>',
  service: '<svg viewBox="0 0 24 24"><path d="M4 19.5V4.8A1.8 1.8 0 0 1 5.8 3h12.4A1.8 1.8 0 0 1 20 4.8v14.7"/><path d="M2 21h20M8 7h8M8 11h8M8 15h5"/></svg>',
  payment: '<svg viewBox="0 0 24 24"><rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20M6 15h2"/></svg>',
  port: '<svg viewBox="0 0 24 24"><circle cx="12" cy="5" r="3"/><path d="M12 22V8M5 12H2a10 10 0 0 0 20 0h-3M8 19h8"/></svg>'
};

function escapeBookingDetail(value) {
  return String(value ?? '-').replace(/[&<>"']/g, character => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'
  })[character]);
}

function formatBookingMoney(value) {
  return `₱${Number(value || 0).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
}

function formatBookingDate(value) {
  if (!value) return '-';
  const date = new Date(`${value}T00:00:00`);
  return Number.isNaN(date.getTime())
    ? value
    : date.toLocaleDateString('en-PH', { year: 'numeric', month: 'long', day: 'numeric' });
}

function bookingDateDifference(startValue, endValue) {
  const startParts = String(startValue || '').split('-').map(Number);
  const endParts = String(endValue || '').split('-').map(Number);
  if (startParts.length !== 3 || endParts.length !== 3 || startParts.some(Number.isNaN) || endParts.some(Number.isNaN)) return 0;
  return Math.max(0, Math.round((Date.UTC(endParts[0], endParts[1] - 1, endParts[2]) - Date.UTC(startParts[0], startParts[1] - 1, startParts[2])) / 86400000));
}

function bookingScheduleDetails(booking) {
  const start = booking.tour_start_date || booking.booking_date || '';
  const end = booking.tour_end_date || start;
  const nights = bookingDateDifference(start, end);
  return {
    schedule: nights > 0 ? `${formatBookingDate(start)} – ${formatBookingDate(end)}` : formatBookingDate(start),
    duration: nights > 0 ? `${nights + 1} days · ${nights} night${nights === 1 ? '' : 's'}` : '1 day',
    arrangement: String(booking.tour_type || '').toLowerCase() === 'overnight' || nights > 0 ? 'Overnight / multi-day tour' : 'Day tour'
  };
}

function bookingDetailRow(icon, label, value) {
  return `<div class="bd-detail-row"><span class="bd-row-icon">${bookingDetailIcons[icon] || ''}</span><div><small>${escapeBookingDetail(label)}</small><strong>${escapeBookingDetail(value || '-')}</strong></div></div>`;
}

async function openBookingDetailsModal(bookingId) {
  const modal = document.getElementById('bookingDetailsModal');
  const content = document.getElementById('bookingDetailsContent');
  if (!bookingId || !content || !modal) return;

  modal.classList.add('show');
  modal.setAttribute('aria-hidden', 'false');
  document.body.classList.add('booking-details-open');
  content.innerHTML = '<div class="booking-drawer-loading"><span></span><p>Loading booking details…</p></div>';

  try {
    const response = await fetch(`adbookings.php?action=fetchBookingDetails&id=${encodeURIComponent(bookingId)}`, {
      headers: { 'Accept': 'application/json' }
    });
    const payload = await response.json();
    if (!response.ok || !payload.success || !payload.booking) {
      throw new Error(payload.message || 'Booking details could not be loaded.');
    }

    const booking = payload.booking;
    const expenses = Array.isArray(payload.expenses) ? payload.expenses : [];
    const meta = parseBookingMeta(booking.preferred_resource);
    const bookingType = String(booking.booking_type || '').toLowerCase();
    const resourceName = bookingType === 'package'
      ? booking.package_name
      : bookingType === 'boat'
        ? (booking.boat_name || meta.preferred)
        : (booking.guide_name || meta.preferred);
    const status = String(booking.is_complete || '').toLowerCase() === 'completed'
      ? 'Completed'
      : String(booking.status || 'Pending');
    const grandTotal = Number(booking.grand_total || 0);
    const paymentAmount = Number(booking.payment_amount || 0);
    const remainingBalance = Number(booking.remaining_balance || 0);
    const expensesTotal = expenses.reduce((sum, expense) => sum + Number(expense.amount || 0), 0);
    const serviceAmount = Math.max(0, grandTotal - expensesTotal);
    const paymentStatus = Number(booking.is_paid || 0) === 1 || remainingBalance <= 0
      ? 'Paid'
      : paymentAmount > 0 ? 'Partial' : 'Unpaid';
    const totalGuests = Number(booking.num_adults || 0) + Number(booking.num_children || 0);
    const fullName = booking.t_full_name || 'Guest';
    const initials = fullName.split(/\s+/).filter(Boolean).slice(0, 2).map(part => part[0]).join('').toUpperCase() || 'G';
    const scheduleDetails = bookingScheduleDetails(booking);
    const profileImageUrl = `adbookings.php?action=fetchTouristProfileImage&id=${encodeURIComponent(Number(booking.booking_id || bookingId))}`;
    const expenseRows = expenses.length
      ? expenses.map(expense => `<div><span>${escapeBookingDetail(expense.expense_type)}</span><strong>${formatBookingMoney(expense.amount)}</strong></div>`).join('')
      : '<div class="bd-no-expense"><span>No additional expenses recorded</span><strong>₱0.00</strong></div>';

    content.innerHTML = `
      <div class="bd-drawer-hero">
        <div class="bd-reference"><span>BOOKING REFERENCE</span><strong>${escapeBookingDetail(booking.booking_reference || booking.booking_id)}</strong></div>
        <span class="bd-status-pill bd-status-${escapeBookingDetail(status.toLowerCase())}">${escapeBookingDetail(status)}</span>
      </div>

      <section class="bd-drawer-section bd-guest-card">
        <div class="bd-guest-avatar"><span>${escapeBookingDetail(initials)}</span>${booking.t_has_profile_picture ? `<img src="${profileImageUrl}" alt="${escapeBookingDetail(fullName)} profile picture" onerror="this.remove()">` : ''}</div>
        <div class="bd-guest-primary"><small>PRIMARY GUEST</small><h4>${escapeBookingDetail(fullName)}</h4><p>${escapeBookingDetail(booking.t_email || '-')}</p></div>
      </section>

      <section class="bd-drawer-section">
        <div class="bd-section-title"><span>${bookingDetailIcons.guest}</span><div><h4>Guest information</h4><p>Contact details used for this reservation</p></div></div>
        <div class="bd-detail-grid">
          ${bookingDetailRow('guest', 'Contact number', booking.booking_phone || booking.t_phone)}
          ${bookingDetailRow('map', 'Home address', booking.t_address)}
        </div>
      </section>

      <section class="bd-drawer-section">
        <div class="bd-section-title"><span>${bookingDetailIcons.calendar}</span><div><h4>Trip information</h4><p>Service, schedule, and meeting details</p></div></div>
        <div class="bd-detail-grid">
          ${bookingDetailRow('service', 'Booking type', booking.booking_type)}
          ${bookingDetailRow('service', 'Selected service', resourceName)}
          ${bookingDetailRow('map', 'Destination', booking.location || booking.package_name)}
          ${bookingDetailRow('calendar', 'Tour schedule', scheduleDetails.schedule)}
          ${bookingDetailRow('calendar', 'Trip duration', scheduleDetails.duration)}
          ${bookingDetailRow('service', 'Tour arrangement', scheduleDetails.arrangement)}
          ${bookingDetailRow('port', 'Jump-off port', booking.jump_off_port)}
          ${bookingDetailRow('users', 'Guest count', `${totalGuests} total · ${booking.num_adults || 0} adult(s), ${booking.num_children || 0} child(ren)`)}
        </div>
      </section>

      <section class="bd-drawer-section bd-payment-card">
        <div class="bd-section-title">
          <span>${bookingDetailIcons.payment}</span>
          <div><h4>Payment and expenses</h4><p>Complete financial summary for this booking</p></div>
          <button type="button" class="bd-go-billing" data-details-billing data-booking-id="${Number(booking.booking_id || bookingId)}">Go to Billing</button>
        </div>
        <div class="bd-payment-lines">
          <div><span>Service amount</span><strong>${formatBookingMoney(serviceAmount)}</strong></div>
          ${expenseRows}
          <div class="bd-expense-subtotal"><span>Expenses total</span><strong>${formatBookingMoney(expensesTotal)}</strong></div>
          <div class="bd-grand-total"><span>Grand total</span><strong>${formatBookingMoney(grandTotal)}</strong></div>
        </div>
        <div class="bd-payment-stats">
          <div><small>PAYMENT OPTION</small><strong>${escapeBookingDetail(meta.paymentOption)}</strong></div>
          <div><small>AMOUNT RECEIVED</small><strong>${formatBookingMoney(paymentAmount)}</strong></div>
          <div><small>REMAINING BALANCE</small><strong>${formatBookingMoney(remainingBalance)}</strong></div>
          <div><small>PAYMENT STATUS</small><strong class="bd-payment-${paymentStatus.toLowerCase()}">${paymentStatus}</strong></div>
        </div>
      </section>

      <div class="bd-confidence-note">
        <span>${bookingDetailIcons.service}</span>
        <p><strong>Booking record verified</strong>Details shown here are loaded directly from the current booking and expense records.</p>
      </div>
    `;
    content.querySelector('[data-details-billing]')?.addEventListener('click', event => {
      const selectedBookingId = Number(event.currentTarget.dataset.bookingId || 0);
      if (selectedBookingId <= 0) return;
      closeBookingDetailsModal();
      openBillingModal(selectedBookingId);
    });
  } catch (error) {
    content.innerHTML = `<div class="booking-drawer-error"><span>!</span><h4>Unable to load booking details</h4><p>${escapeBookingDetail(error.message)}</p><button type="button" onclick="openBookingDetailsModal(${Number(bookingId)})">Try Again</button></div>`;
  }
}

function closeBookingDetailsModal() {
  const modal = document.getElementById('bookingDetailsModal');
  if (modal) {
    modal.classList.remove('show');
    modal.setAttribute('aria-hidden', 'true');
  }
  document.body.classList.remove('booking-details-open');
}

document.getElementById('bookingDetailsModal')?.addEventListener('mousedown', event => {
  if (event.target.id === 'bookingDetailsModal') closeBookingDetailsModal();
});
document.addEventListener('keydown', event => {
  if (event.key === 'Escape' && document.getElementById('bookingDetailsModal')?.classList.contains('show')) {
    closeBookingDetailsModal();
  }
  if (event.key === 'Escape' && document.getElementById('touristModal')?.style.display === 'flex') {
    closeTouristModal();
  }
});

function closeTouristModal() {
    const modal = document.getElementById('touristModal');
    if(modal) {
      modal.style.display = 'none';
      modal.setAttribute('aria-hidden', 'true');
      delete modal.dataset.bookingId;
    }
    const iframe = document.getElementById('touristPdfFrame');
    if(iframe) iframe.src = 'about:blank';
}

function showTouristAdminDocument(documentType) {
    const modal = document.getElementById('touristModal');
    const iframe = document.getElementById('touristPdfFrame');
    const bookingId = modal?.dataset.bookingId || '';
    const isRegistration = documentType === 'registration';
    const activeDocument = isRegistration ? 'registration' : 'manifest';

    document.querySelectorAll('[data-tourist-document]').forEach(button => {
      const active = button.dataset.touristDocument === activeDocument;
      button.classList.toggle('active', active);
      button.setAttribute('aria-selected', active ? 'true' : 'false');
    });

    if (iframe && bookingId) {
      const documentPath = isRegistration ? 'travel_registration_pdf.php' : 'tourists_pdf.php';
      const documentUrl = `php/${documentPath}?booking_id=${encodeURIComponent(bookingId)}`;
      iframe.title = isRegistration ? 'Travel registration form PDF' : 'Passenger manifest PDF';
      if (!iframe.src.endsWith(documentUrl)) iframe.src = documentUrl;
    }
}

// ===========================================================
// ----------------- PRINT PREVIEW & PDF DESIGN -------------
// ===========================================================

function getFilteredText() {
  const mode = document.getElementById('reportFilterMode').value;
  const year = document.getElementById('reportFilterYear').value;
  const month = document.getElementById('reportFilterMonth').value;

  if (mode === 'yearly' && year) return `Year: ${year}`;
  if (mode === 'monthly' && year && month) {
    const monthName = new Date(0, month - 1).toLocaleString('default', { month: 'long' });
    return `Month: ${monthName} ${year}`;
  }
  return 'All Bookings';
}

function generateReportHTML() {
  const content = document.getElementById('reportContent').innerHTML;
  if (!content || !currentReportBookings.length) return null;

  const orientation = document.getElementById('reportOrientation').value || 'portrait';
  const paperSize = document.getElementById('reportPaperSize').value;
  const printSize = paperSize === 'letter' ? 'letter' : paperSize === 'long' ? 'legal' : 'A4';
  const stylesheet = new URL('styles/adbookings.css?v=31', window.location.href).href;

  return `
    <!doctype html><html>
      <head>
        <meta charset="utf-8"><title>iTour Mercedes Booking Report</title>
        <link rel="stylesheet" href="${stylesheet}">
        <style>
          @page { size: ${printSize} ${orientation}; margin: 10mm; }
          html,body{margin:0!important;padding:0!important;background:#fff!important}
          body{font-family:Arial,sans-serif!important;-webkit-print-color-adjust:exact;print-color-adjust:exact}
          .booking-report-document{width:100%!important;max-width:none!important;margin:0!important;padding:0!important;border:0!important;border-radius:0!important;box-shadow:none!important}
          .booking-report-table{font-size:7px!important}
          .booking-report-table th{position:static!important}
          .booking-report-preview-footer{margin-top:12px}
        </style>
      </head>
      <body>${content}</body>
    </html>
  `;
}

// ---------------- PRINT PREVIEW ----------------
document.getElementById('printReport').addEventListener('click', () => {
  const html = generateReportHTML();
  if (!html) return alert('No bookings to print');

  const iframe = document.createElement('iframe');
  iframe.style.position = 'absolute';
  iframe.style.width = '0';
  iframe.style.height = '0';
  iframe.style.border = '0';
  document.body.appendChild(iframe);

  const doc = iframe.contentWindow.document;
  iframe.onload = () => setTimeout(() => {
    iframe.contentWindow.focus();
    iframe.contentWindow.print();
    setTimeout(() => iframe.remove(), 1200);
  }, 350);
  doc.open();
  doc.write(html);
  doc.close();
});

// ---------------- CSV EXPORT ----------------
function reportCsvCell(value) {
  let text = String(value == null ? '' : value).replace(/\r?\n/g, ' ').trim();
  // Prevent spreadsheet applications from evaluating values as formulas.
  if (/^[=+\-@]/.test(text)) text = `'${text}`;
  return `"${text.replace(/"/g, '""')}"`;
}

function downloadReportCSV() {
  if (!currentReportBookings.length) {
    alert('Apply a report filter before downloading the CSV file.');
    return;
  }

  const button = document.getElementById('downloadReportCsv');
  const originalContent = button.innerHTML;
  button.disabled = true;
  button.textContent = 'Preparing CSV...';

  try {
    const generatedAt = new Intl.DateTimeFormat('en-PH', {
      year: 'numeric', month: 'long', day: 'numeric', hour: 'numeric', minute: '2-digit'
    }).format(new Date());
    const rows = [
      ['iTour Mercedes', 'Official Booking Report'],
      ['Report coverage', currentReportFilterText],
      ['Generated', generatedAt],
      ['Total bookings', currentReportBookings.length],
      [],
      ['No.', 'Booking ID', 'Booking Reference', 'Tourist Name', 'Email Address', 'Contact Number', 'Booking Type', 'Package / Location', 'Adults', 'Children', 'Total Guests', 'Tour Date', 'Tour Duration', 'Booking Created', 'Booking Total', 'Amount Collected', 'Balance Due', 'Payment Status', 'Payment Method', 'Booking Status'],
      ...currentReportBookings.map((booking, index) => [
        index + 1,
        booking.booking_id || '',
        booking.booking_reference || '',
        booking.name || 'Guest',
        booking.email || '',
        booking.phone || '',
        reportLabel(booking.booking_type),
        booking.display_name || booking.package_name || booking.location || '',
        Number(booking.num_adults || 0),
        Number(booking.num_children || 0),
        Number(booking.guest_count || 0),
        booking.booking_date || booking.date || '',
        reportLabel(booking.tour_type || 'Day tour'),
        booking.created_at || '',
        Number(booking.grand_total || 0).toFixed(2),
        Number(booking.payment_amount || 0).toFixed(2),
        Number(booking.remaining_balance || 0).toFixed(2),
        booking.payment_status || '',
        reportLabel(booking.payment_method),
        booking.booking_status || reportLabel(booking.status)
      ])
    ];
    const csv = rows.map(row => row.map(reportCsvCell).join(',')).join('\r\n');
    const blob = new Blob([`\uFEFF${csv}`], { type: 'text/csv;charset=utf-8' });
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.download = `${currentReportFileStem}.csv`;
    document.body.appendChild(link);
    link.click();
    link.remove();
    setTimeout(() => URL.revokeObjectURL(url), 1000);
  } catch (error) {
    console.error('Error generating CSV:', error);
    alert('An error occurred while generating the CSV file.');
  } finally {
    button.disabled = false;
    button.innerHTML = originalContent;
  }
}

document.getElementById('downloadReportCsv').addEventListener('click', downloadReportCSV);

// ---------------- PDF EXPORT ----------------
function loadReportImage(source) {
  return new Promise((resolve, reject) => {
    const image = new Image();
    image.onload = () => {
      const canvas = document.createElement('canvas');
      canvas.width = image.naturalWidth;
      canvas.height = image.naturalHeight;
      const context = canvas.getContext('2d');
      context.drawImage(image, 0, 0);
      resolve({ data: canvas.toDataURL('image/png'), ratio: image.naturalWidth / image.naturalHeight });
    };
    image.onerror = () => reject(new Error(`Could not load report image: ${source}`));
    image.src = new URL(source, window.location.href).href;
  });
}

async function downloadReportPDF() {
  const button = document.getElementById('downloadReport');
  if (!currentReportBookings.length) {
    alert('Apply a report filter before downloading the PDF file.');
    return;
  }

  button.disabled = true;
  const originalText = button.innerText;
  const originalBackground = button.style.backgroundColor;
  button.innerText = 'Preparing PDF...';
  button.style.backgroundColor = '#ccc';

  try {
    const { jsPDF } = window.jspdf;
    const orientation = document.getElementById('reportOrientation').value || 'portrait';
    const paperSize = document.getElementById('reportPaperSize').value;
    const pdfFormat = paperSize === 'letter' ? 'letter' : paperSize === 'long' ? 'legal' : 'a4';
    const doc = new jsPDF({ orientation, unit: 'pt', format: pdfFormat, compress: true });
    const [seal, wordmark] = await Promise.all([loadReportImage('img/newlogo.png'), loadReportImage('img/textlogo2.png')]);
    const pageWidth = doc.internal.pageSize.getWidth();
    const pageHeight = doc.internal.pageSize.getHeight();
    const margin = 34;
    const usableWidth = pageWidth - margin * 2;
    const generatedAt = new Intl.DateTimeFormat('en-PH', {
      year: 'numeric', month: 'long', day: 'numeric', hour: 'numeric', minute: '2-digit'
    }).format(new Date());
    const green = [28, 104, 81];
    const darkGreen = [18, 66, 52];
    const ink = [39, 57, 50];
    const muted = [103, 123, 115];
    const pale = [237, 247, 243];
    const line = [214, 229, 223];
    const pdfMoney = value => `PHP ${Number(value || 0).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;

    const drawBrandHeader = (compact = false) => {
      const top = compact ? 22 : 28;
      const sealSize = compact ? 28 : 38;
      doc.addImage(seal.data, 'PNG', margin, top, sealSize, sealSize, undefined, 'FAST');
      const wordmarkWidth = compact ? 105 : 132;
      const wordmarkHeight = wordmarkWidth / wordmark.ratio;
      doc.addImage(wordmark.data, 'PNG', margin + sealSize + 9, top + (compact ? 2 : 3), wordmarkWidth, wordmarkHeight, undefined, 'FAST');
      doc.setFont('helvetica', 'bold');
      doc.setFontSize(compact ? 5.8 : 6.8);
      doc.setTextColor(...muted);
      doc.text('OFFICIAL BOOKING OPERATIONS REPORT', margin + sealSize + 10, top + wordmarkHeight + (compact ? 7 : 10));
      doc.setFont('helvetica', 'bold');
      doc.setFontSize(compact ? 7 : 8);
      doc.setTextColor(...green);
      doc.text(currentReportFilterText.toUpperCase(), pageWidth - margin, top + 10, { align: 'right' });
      doc.setFont('helvetica', 'normal');
      doc.setFontSize(6.2);
      doc.setTextColor(...muted);
      doc.text(`Generated ${generatedAt}`, pageWidth - margin, top + 22, { align: 'right' });
      const ruleY = top + sealSize + (compact ? 8 : 12);
      doc.setDrawColor(...green);
      doc.setLineWidth(1.1);
      doc.line(margin, ruleY, pageWidth - margin, ruleY);
      return ruleY;
    };

    const drawSummary = startY => {
      doc.setFont('helvetica', 'bold');
      doc.setFontSize(14);
      doc.setTextColor(...darkGreen);
      doc.text('Booking Activity Report', margin, startY + 18);
      doc.setFont('helvetica', 'normal');
      doc.setFontSize(7.2);
      doc.setTextColor(...muted);
      doc.text(`Prepared by ${reportPreparedBy}  •  Source: Booking Management  •  ${currentReportBookings.length} verified record${currentReportBookings.length === 1 ? '' : 's'}`, margin, startY + 31);

      const cards = [
        ['TOTAL BOOKINGS', currentReportSummary.bookings.toLocaleString(), 'Reservations'],
        ['TOTAL GUESTS', currentReportSummary.guests.toLocaleString(), 'Adults & children'],
        ['BOOKING VALUE', pdfMoney(currentReportSummary.value), 'Gross amount'],
        ['COLLECTED', pdfMoney(currentReportSummary.collected), 'Payments received'],
        ['OUTSTANDING', pdfMoney(currentReportSummary.outstanding), 'Balance to collect']
      ];
      const gap = 6;
      const cardWidth = (usableWidth - gap * (cards.length - 1)) / cards.length;
      const cardY = startY + 44;
      cards.forEach((card, index) => {
        const x = margin + index * (cardWidth + gap);
        doc.setFillColor(...(index === cards.length - 1 ? [255, 246, 234] : pale));
        doc.setDrawColor(...(index === cards.length - 1 ? [237, 210, 174] : line));
        doc.roundedRect(x, cardY, cardWidth, 48, 4, 4, 'FD');
        doc.setFont('helvetica', 'bold');
        doc.setFontSize(5.7);
        doc.setTextColor(...(index === cards.length - 1 ? [159, 90, 33] : muted));
        doc.text(card[0], x + 8, cardY + 11);
        doc.setFontSize(card[1].length > 14 ? 8 : 10.5);
        doc.setTextColor(...(index === cards.length - 1 ? [154, 77, 25] : darkGreen));
        doc.text(card[1], x + 8, cardY + 27, { maxWidth: cardWidth - 16 });
        doc.setFont('helvetica', 'normal');
        doc.setFontSize(5.8);
        doc.setTextColor(...muted);
        doc.text(card[2], x + 8, cardY + 39);
      });
      return cardY + 62;
    };

    const ratios = [.045, .225, .18, .075, .12, .205, .15];
    const headers = ['NO.', 'REFERENCE & TOURIST', 'BOOKING DETAILS', 'GUESTS', 'TOUR DATE', 'FINANCIAL SUMMARY', 'STATUS'];
    const columns = [];
    let cursorX = margin;
    ratios.forEach(ratio => {
      const width = usableWidth * ratio;
      columns.push({ x: cursorX, width });
      cursorX += width;
    });

    const drawTableHeader = y => {
      doc.setFillColor(...darkGreen);
      doc.rect(margin, y, usableWidth, 23, 'F');
      doc.setFont('helvetica', 'bold');
      doc.setFontSize(5.7);
      doc.setTextColor(255, 255, 255);
      headers.forEach((header, index) => doc.text(header, columns[index].x + 5, y + 14, { maxWidth: columns[index].width - 9 }));
      return y + 23;
    };

    const cellLines = (values, width) => values.flatMap(value => doc.splitTextToSize(String(value || '—'), Math.max(12, width - 10)));
    const reportRows = currentReportBookings.map((booking, index) => [
      [String(index + 1)],
      [booking.booking_reference || `#${booking.booking_id}`, booking.name || 'Guest', booking.email || 'No email', booking.phone || 'No phone'],
      [reportLabel(booking.booking_type), booking.display_name || 'Tour service', `Created ${reportDate(booking.created_at, true)}`],
      [String(booking.guest_count || 0), `${Number(booking.num_adults || 0)} adult / ${Number(booking.num_children || 0)} child`],
      [reportDate(booking.booking_date), reportLabel(booking.tour_type || 'Day tour')],
      [`Total ${pdfMoney(booking.grand_total)}`, `Paid ${pdfMoney(booking.payment_amount)}`, `Balance ${pdfMoney(booking.remaining_balance)}`, `Method ${reportLabel(booking.payment_method)}`],
      [booking.payment_status, booking.booking_status]
    ]);

    let y = drawBrandHeader(false);
    y = drawSummary(y + 8);
    y = drawTableHeader(y);
    reportRows.forEach((row, rowIndex) => {
      doc.setFontSize(6.2);
      const wrapped = row.map((cell, index) => cellLines(cell, columns[index].width));
      const rowHeight = Math.max(27, ...wrapped.map(lines => lines.length * 7 + 10));
      if (y + rowHeight > pageHeight - 34) {
        doc.addPage();
        y = drawBrandHeader(true) + 9;
        y = drawTableHeader(y);
      }
      if (rowIndex % 2 === 0) {
        doc.setFillColor(248, 251, 250);
        doc.rect(margin, y, usableWidth, rowHeight, 'F');
      }
      doc.setDrawColor(...line);
      doc.setLineWidth(.35);
      doc.line(margin, y + rowHeight, pageWidth - margin, y + rowHeight);
      wrapped.forEach((lines, columnIndex) => {
        let textY = y + 10;
        lines.forEach((text, lineIndex) => {
          doc.setFont('helvetica', lineIndex === 0 ? 'bold' : 'normal');
          doc.setFontSize(lineIndex === 0 ? 6.4 : 5.8);
          const isOutstanding = columnIndex === 5 && String(text).startsWith('Balance') && Number(currentReportBookings[rowIndex].remaining_balance || 0) > 0;
          doc.setTextColor(...(isOutstanding ? [166, 88, 28] : lineIndex === 0 ? ink : muted));
          doc.text(text, columns[columnIndex].x + 5, textY);
          textY += 7;
        });
      });
      y += rowHeight;
    });

    const totalPages = doc.getNumberOfPages();
    for (let page = 1; page <= totalPages; page++) {
      doc.setPage(page);
      doc.setDrawColor(...line);
      doc.line(margin, pageHeight - 24, pageWidth - margin, pageHeight - 24);
      doc.setFont('helvetica', 'normal');
      doc.setFontSize(5.8);
      doc.setTextColor(...muted);
      doc.text('iTour Mercedes · Mercedes, Camarines Norte · Confidential administrative record', margin, pageHeight - 13);
      doc.setFont('helvetica', 'bold');
      doc.text(`Page ${page} of ${totalPages}`, pageWidth - margin, pageHeight - 13, { align: 'right' });
    }

    doc.setProperties({
      title: `iTour Mercedes Booking Report - ${currentReportFilterText}`,
      subject: 'Booking operations and payment summary',
      author: reportPreparedBy,
      creator: 'iTour Mercedes Booking Management'
    });
    doc.save(`${currentReportFileStem}.pdf`);

  } catch (error) {
    console.error('Error generating PDF:', error);
    alert('An error occurred while generating the PDF.');
  } finally {
    // Re-enable button and restore original text and color
    button.disabled = false;
    button.innerText = originalText;
    button.style.backgroundColor = originalBackground;
  }
}

document.getElementById('downloadReport').addEventListener('click', downloadReportPDF);
const providerCancelModal = document.getElementById('providerCancellationModal');
const providerCancelState = {step:1, form:null};
const providerCancelNext = document.getElementById('providerCancelNext');
const providerCancelBack = document.getElementById('providerCancelBack');
const providerCancelSubmit = document.getElementById('providerCancelSubmit');
const providerCancelReason = document.getElementById('providerCancelReason');
const providerCancelOther = document.getElementById('providerCancelOther');

function providerCancelMoney(value) {
    return `₱${Number(value || 0).toLocaleString('en-PH', {minimumFractionDigits:2, maximumFractionDigits:2})}`;
}

function providerCancelSetText(id, value) {
    const element = document.getElementById(id);
    if (element) element.textContent = value ?? '—';
}

function providerCancelSelectedChoice() {
    return providerCancelModal?.querySelector('input[name="providerCancellationChoice"]:checked')?.value || '';
}

function providerCancelSchedule(sourceForm) {
    const start = sourceForm?.dataset.bookingDate || '';
    const end = sourceForm?.dataset.bookingEndDate || start;
    const hasRange = Boolean(start && end && bookingDateDifference(start, end) > 0);
    return {
        hasRange,
        text: hasRange ? `${formatBookingDate(start)} – ${formatBookingDate(end)}` : formatBookingDate(start)
    };
}

function providerCancelShowStep(step) {
    providerCancelState.step = Math.max(1, Math.min(3, step));
    providerCancelModal.querySelectorAll('.provider-cancel-step').forEach(panel => panel.classList.toggle('active', Number(panel.dataset.step) === providerCancelState.step));
    providerCancelModal.querySelectorAll('.provider-cancel-progress-item').forEach(item => {
        const number = Number(item.dataset.progress);
        item.classList.toggle('active', number === providerCancelState.step);
        item.classList.toggle('complete', number < providerCancelState.step);
        item.querySelector(':scope > span').textContent = number < providerCancelState.step ? '✓' : String(number);
    });
    providerCancelBack.hidden = providerCancelState.step === 1;
    providerCancelNext.hidden = providerCancelState.step === 3;
    providerCancelSubmit.hidden = providerCancelState.step !== 3;
    document.getElementById('providerCancelDismiss').textContent = providerCancelState.step === 1 ? 'Keep Booking' : 'Close';

    const choice = providerCancelSelectedChoice();
    if (providerCancelState.step === 2) {
        providerCancelSetText('providerCancelTitle', choice === 'offer_reschedule' ? 'Offer Free Reschedule' : 'Cancel & Full Refund');
        providerCancelSetText('providerCancelSubtitle', 'Provide the official reason that will be shared with the tourist.');
        providerCancelSetText('providerCancelReasonHeading', choice === 'offer_reschedule' ? 'Why must the schedule change?' : 'Why is the booking being cancelled?');
    } else if (providerCancelState.step === 3) {
        providerCancelPrepareReview();
        providerCancelSetText('providerCancelTitle', 'Review Cancellation Action');
        providerCancelSetText('providerCancelSubtitle', 'Confirm the details before updating this booking.');
    } else {
        providerCancelSetText('providerCancelTitle', 'Administrator Cancellation');
        providerCancelSetText('providerCancelSubtitle', 'Choose the appropriate assistance for this tourist.');
    }
}

function providerCancelPrepareReview() {
    const choice = providerCancelSelectedChoice();
    const reason = providerCancelReason.value === 'Other' ? providerCancelOther.value.trim() : providerCancelReason.value;
    const paid = providerCancelState.form?.dataset.amountPaid || 0;
    const schedule = providerCancelSchedule(providerCancelState.form);
    providerCancelSetText('providerCancelReviewResolution', choice === 'offer_reschedule' ? 'Offer free rescheduling' : 'Cancel immediately with full refund');
    providerCancelSetText('providerCancelReviewPaid', providerCancelMoney(paid));
    providerCancelSetText('providerCancelReviewSchedule', schedule.text);
    providerCancelSetText('providerCancelReviewReason', reason);
    const notice = document.getElementById('providerCancelReviewNotice');
    const submit = providerCancelSubmit;
    if (choice === 'offer_reschedule') {
        notice.className = 'provider-cancel-notice';
        notice.innerHTML = '<strong>What happens next:</strong> The original schedule is stopped and the tourist has exactly 2 days to choose a free new date or a full refund. No refund is created unless the tourist selects it or the deadline expires.';
        submit.textContent = 'Send Reschedule Offer';
        submit.classList.add('reschedule');
    } else {
        notice.className = 'provider-cancel-notice danger';
        notice.innerHTML = `<strong>Immediate action:</strong> This booking will be cancelled now and ${providerCancelMoney(paid)}—the amount actually paid—will be submitted for refund processing. The tourist does not need to respond.`;
        submit.textContent = 'Cancel & Submit Refund';
        submit.classList.remove('reschedule');
    }
}

function providerCancelClose() {
    if (!providerCancelModal || !document.getElementById('providerCancelProcessing').hidden) return;
    providerCancelModal.classList.remove('open');
    providerCancelModal.setAttribute('aria-hidden', 'true');
    document.body.style.overflow = '';
    providerCancelState.form = null;
}

function openAdminCancellationWorkflow(event, sourceForm) {
    event.preventDefault();
    providerCancelState.form = sourceForm;
    providerCancelModal.querySelectorAll('input[name="providerCancellationChoice"]').forEach(input => input.checked = false);
    providerCancelReason.value = '';
    providerCancelOther.value = '';
    document.getElementById('providerCancelOtherField').hidden = true;
    document.getElementById('providerCancelAcknowledge').checked = false;
    document.getElementById('providerCancelProcessing').hidden = true;
    providerCancelSubmit.disabled = false;
    ['providerCancelChoiceError','providerCancelReasonError','providerCancelReviewError'].forEach(id => providerCancelSetText(id, ''));
    providerCancelSetText('providerCancelReference', sourceForm.dataset.bookingReference);
    providerCancelSetText('providerCancelTourist', sourceForm.dataset.touristName);
    const schedule = providerCancelSchedule(sourceForm);
    providerCancelSetText('providerCancelDateLabel', schedule.hasRange ? 'TOUR DATE RANGE' : 'SCHEDULED DATE');
    providerCancelSetText('providerCancelDate', schedule.text);
    providerCancelShowStep(1);
    providerCancelModal.classList.add('open');
    providerCancelModal.setAttribute('aria-hidden', 'false');
    document.body.style.overflow = 'hidden';
    setTimeout(() => providerCancelModal.querySelector('input[name="providerCancellationChoice"]')?.focus(), 50);
    return false;
}

providerCancelNext?.addEventListener('click', () => {
    if (providerCancelState.step === 1) {
        if (!providerCancelSelectedChoice()) {
            providerCancelSetText('providerCancelChoiceError', 'Select how this booking should be handled before continuing.');
            return;
        }
        providerCancelSetText('providerCancelChoiceError', '');
        providerCancelShowStep(2);
        providerCancelReason.focus();
        return;
    }
    if (providerCancelState.step === 2) {
        const customReason = providerCancelOther.value.trim();
        if (!providerCancelReason.value) {
            providerCancelSetText('providerCancelReasonError', 'Select a cancellation or rescheduling reason.');
            providerCancelReason.focus();
            return;
        }
        if (providerCancelReason.value === 'Other' && !customReason) {
            providerCancelSetText('providerCancelReasonError', 'Enter the custom reason that will be shared with the tourist.');
            providerCancelOther.focus();
            return;
        }
        providerCancelSetText('providerCancelReasonError', '');
        providerCancelShowStep(3);
    }
});

providerCancelBack?.addEventListener('click', () => providerCancelShowStep(providerCancelState.step - 1));
['providerCancelClose','providerCancelDismiss'].forEach(id => document.getElementById(id)?.addEventListener('click', providerCancelClose));
providerCancelReason?.addEventListener('change', () => {
    const isOther = providerCancelReason.value === 'Other';
    document.getElementById('providerCancelOtherField').hidden = !isOther;
    if (!isOther) providerCancelOther.value = '';
    providerCancelSetText('providerCancelReasonError', '');
});
providerCancelOther?.addEventListener('input', () => providerCancelSetText('providerCancelReasonCount', String(providerCancelOther.value.length)));
document.getElementById('providerCancelAcknowledge')?.addEventListener('change', () => providerCancelSetText('providerCancelReviewError', ''));
providerCancelModal?.querySelectorAll('input[name="providerCancellationChoice"]').forEach(input => input.addEventListener('change', () => providerCancelSetText('providerCancelChoiceError', '')));

providerCancelSubmit?.addEventListener('click', () => {
    const acknowledgement = document.getElementById('providerCancelAcknowledge');
    if (!acknowledgement.checked) {
        providerCancelSetText('providerCancelReviewError', 'Confirm that you reviewed this administrator/provider action.');
        acknowledgement.focus();
        return;
    }
    const sourceForm = providerCancelState.form;
    if (!sourceForm) return;
    const values = {
        provider_cancellation_workflow: providerCancelSelectedChoice(),
        reason_category: providerCancelReason.value,
        reason_other: providerCancelOther.value.trim()
    };
    Object.entries(values).forEach(([name, value]) => {
        let input = sourceForm.querySelector(`input[name="${name}"]`);
        if (!input) { input = document.createElement('input'); input.type = 'hidden'; input.name = name; sourceForm.appendChild(input); }
        input.value = value;
    });
    providerCancelSubmit.disabled = true;
    sourceForm.querySelector('button[type="submit"]')?.setAttribute('disabled', 'disabled');
    const isRescheduleOffer = values.provider_cancellation_workflow === 'offer_reschedule';
    Swal.fire({
        title: isRescheduleOffer ? 'Sending Cancellation Notice' : 'Cancelling Booking',
        html: isRescheduleOffer
            ? 'Please wait while the reschedule offer and cancellation notice are emailed to the tourist.'
            : 'Please wait while the booking is cancelled and the refund notice is emailed to the tourist.',
        color: '#173f34',
        background: '#ffffff',
        allowOutsideClick: false,
        allowEscapeKey: false,
        allowEnterKey: false,
        showConfirmButton: false,
        showCancelButton: false,
        showDenyButton: false,
        didOpen: () => Swal.showLoading(),
        customClass: {
            popup: 'booking-email-swal-popup',
            title: 'booking-email-swal-title',
            htmlContainer: 'booking-email-swal-content',
            loader: 'booking-email-swal-loader'
        }
    });
    requestAnimationFrame(() => requestAnimationFrame(() => sourceForm.submit()));
});

providerCancelModal?.addEventListener('click', event => { if (event.target === providerCancelModal) providerCancelClose(); });
document.addEventListener('keydown', event => { if (event.key === 'Escape' && providerCancelModal?.classList.contains('open')) providerCancelClose(); });
const decisionModal = document.getElementById("decisionModal");
const decisionCloseBtns = [
    document.getElementById("decisionModalClose"),
    document.getElementById("decisionCancelBtn")
];

// Close modal on close buttons click
decisionCloseBtns.forEach(btn => {
    if (btn) {
        btn.onclick = () => {
            decisionModal.style.display = "none";
        };
    }
});

// Function to open modal
function openDecisionModal(event, form, action, returnTab) {
    event.preventDefault();

    const bookingId = form.querySelector("input[name='id']").value;

    // Helper to create or update hidden input
    function setHiddenInput(id, value) {
        let input = document.getElementById(id);
        if (!input) {
            input = document.createElement("input");
            input.type = "hidden";
            input.id = id;
            decisionModal.appendChild(input);
        }
        input.value = value;
    }

    setHiddenInput("decisionBookingId", bookingId);
    setHiddenInput("decisionAction", action);
    setHiddenInput("decisionReturnTab", returnTab);

    // -----------------------------
    // DYNAMIC MODAL TITLE
    // -----------------------------
    const modalTitle = decisionModal.querySelector(".decision-modal-header h3");
    if (modalTitle) {
        if (action.toLowerCase() === "cancel") {
            modalTitle.textContent = "Cancel Reason";
        } else if (action.toLowerCase() === "decline") {
            modalTitle.textContent = "Decline Reason";
        } else {
            modalTitle.textContent = "Reason Required";
        }
    }

    // Show modal
    decisionModal.style.display = "flex";

    // Get category field and submit button
    const categoryField = decisionModal.querySelector("select#decisionCategory");
    const submitBtn = decisionModal.querySelector("#decisionOkBtn");

    if (!submitBtn) return;

    function updateSubmitButton() {
        if (!categoryField || !categoryField.value || categoryField.value.trim() === "") {
            submitBtn.disabled = true;
            submitBtn.style.backgroundColor = "#ccc";
            submitBtn.style.cursor = "not-allowed";
        } else {
            submitBtn.disabled = false;
            submitBtn.style.backgroundColor = "";
            submitBtn.style.cursor = "";
        }
    }

    updateSubmitButton();

    if (categoryField) {
        categoryField.addEventListener("input", updateSubmitButton);
        categoryField.addEventListener("change", updateSubmitButton);
    }

    submitBtn.onclick = () => {
        const bookingId = document.getElementById("decisionBookingId").value;
        const action = document.getElementById("decisionAction").value;
        const returnTab = document.getElementById("decisionReturnTab").value;
        const category = categoryField ? categoryField.value : "";
        const note = decisionModal.querySelector("#decisionNote")?.value.trim() || "";

        const form = document.createElement("form");
        form.method = "POST";
        form.style.display = "none";
        form.innerHTML = `
            <input name="id" value="${bookingId}">
            <input name="action" value="${action}">
            <input name="return_tab" value="${returnTab}">
            <input name="csrf_token" value="${adminBookingCsrf}">
            <input name="decision_category" value="${category}">
            <input name="decision_note" value="${note}">
        `;
        document.body.appendChild(form);
        form.submit();
    };

    return false;
}



// ===========================================================
// ----------------- SWEETALERT CONFIRMATION ----------------
// ===========================================================
function confirmAction(event, form, action) {
  event.preventDefault();

  if (form.dataset.submitting === 'true') return false;

  const texts = {
    accept: "Accept this booking?",
    retry_email: "Retry sending the confirmation email?",
    decline: "Decline this booking?",
    finish: "Mark this booking as Completed?",
    cancel: "Cancel this booking?"
  };

  const icons = {
    accept: 'question',
    retry_email: 'question',
    decline: 'warning',
    finish: 'info',
    cancel: 'warning'
  };

  Swal.fire({
    icon: icons[action] || 'question',
    iconColor: action === 'accept' ? '#176b55' : undefined,
    title: 'Confirm Action',
    html: `<p style="font-size:16px;margin-top:8px;">${texts[action] || "Proceed?"}</p>`,
    showCancelButton: true,
    confirmButtonColor: "#176b55",
    cancelButtonColor: "#55766d",
    confirmButtonText: "Yes, proceed",
    cancelButtonText: "Cancel",
    reverseButtons: true,
    focusCancel: true
  }).then(r => {
    if (!r.isConfirmed) return;

    form.dataset.submitting = 'true';
    if (action === 'accept' || action === 'retry_email') {
      Swal.fire({
        title: 'Sending Confirmation Email',
        html: action === 'accept'
          ? 'Please wait while the booking is accepted and the confirmation email is sent.'
          : 'Please wait while the booking confirmation email is sent again.',
        color: '#173f34',
        background: '#ffffff',
        allowOutsideClick: false,
        allowEscapeKey: false,
        allowEnterKey: false,
        showConfirmButton: false,
        showCancelButton: false,
        showDenyButton: false,
        didOpen: () => Swal.showLoading(),
        customClass: {
          popup: 'booking-email-swal-popup',
          title: 'booking-email-swal-title',
          htmlContainer: 'booking-email-swal-content',
          loader: 'booking-email-swal-loader'
        }
      });
      window.setTimeout(() => form.submit(), 80);
      return;
    }

    form.submit();
  });

  return false;
}

// ===========================================================
// ----------------- SMART TOOLTIP ---------------------------
// ===========================================================

// ----------------- TOOLTIP SETUP ----------------
const tooltip = document.createElement('div');
tooltip.className = 'profile-tooltip'; // Add your CSS class for styling
tooltip.style.position = 'absolute';
tooltip.style.display = 'none';
tooltip.style.zIndex = '9999';
document.body.appendChild(tooltip);

let hideTimeout;

function positionTooltip(img) {
  const rect = img.getBoundingClientRect();
  const tRect = tooltip.getBoundingClientRect();
  const spacing = 8;
  const pageTop = window.scrollY;
  const pageLeft = window.scrollX;
  const viewportTop = pageTop + 10;
  const viewportBottom = pageTop + window.innerHeight - 10;
  const viewportRight = pageLeft + window.innerWidth - 10;
  let top = rect.bottom + pageTop + spacing;
  let left = rect.left + pageLeft;

  // Prevent overflow right
  if (left + tRect.width > viewportRight) {
    left = viewportRight - tRect.width;
  }

  // Prevent overflow left
  if (left < pageLeft + 10) {
    left = pageLeft + 10;
  }

  // Move above if not enough bottom space
  if (top + tRect.height > viewportBottom) {
    top = rect.top + pageTop - tRect.height - spacing;
  }
  if (top < viewportTop) {
    top = viewportTop;
  }

  tooltip.style.top = `${top}px`;
  tooltip.style.left = `${left}px`;
}

function showTooltip(img) {
  clearTimeout(hideTimeout);

  const fullName = escapeBookingDetail(img.dataset.fullname || 'Guest');
  const email = escapeBookingDetail(img.dataset.email || '-');
  const phone = escapeBookingDetail(img.dataset.phone || '-');
  const address = escapeBookingDetail(img.dataset.address || '-');
  const completed = escapeBookingDetail(img.dataset.finishedBookings || 0);
  const profileSrc = escapeBookingDetail(img.src);
  const emailHref = img.dataset.email && img.dataset.email !== '-'
    ? `mailto:${encodeURIComponent(img.dataset.email)}`
    : '#';

  tooltip.innerHTML = `
    <div class="tooltip-accent" aria-hidden="true"></div>
    <div class="tooltip-header">
      <img class="tooltip-avatar" src="${profileSrc}" alt="">
      <div class="tooltip-identity">
        <span class="tooltip-eyebrow">Guest profile</span>
        <div class="tooltip-name">${fullName}</div>
        <span class="tooltip-booking-count">${completed} completed booking${Number(img.dataset.finishedBookings || 0) === 1 ? '' : 's'}</span>
      </div>
    </div>
    <div class="tooltip-details">
      <div class="tooltip-detail">
        <span class="tooltip-detail-icon" aria-hidden="true">&#9993;</span>
        <span><small>Email address</small><strong>${email}</strong></span>
      </div>
      <div class="tooltip-detail">
        <span class="tooltip-detail-icon" aria-hidden="true">&#9742;</span>
        <span><small>Phone number</small><strong>${phone}</strong></span>
      </div>
      <div class="tooltip-detail tooltip-address">
        <span class="tooltip-detail-icon" aria-hidden="true">&#9673;</span>
        <span><small>Home address</small><strong>${address}</strong></span>
      </div>
    </div>
    <a href="${emailHref}" class="tooltip-button${emailHref === '#' ? ' is-disabled' : ''}">
      <span class="tooltip-button-label">
        <svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2"></rect><path d="m4 7 8 6 8-6"></path></svg>
        Send email
      </span>
      <span aria-hidden="true">&rarr;</span>
    </a>
  `;

  tooltip.style.display = 'block';
  tooltip.style.visibility = 'hidden';

  requestAnimationFrame(() => {
    positionTooltip(img);
    tooltip.style.visibility = 'visible';
    tooltip.classList.add('show');
  });
}

function hideTooltip() {
  hideTimeout = setTimeout(() => {
    tooltip.classList.remove('show');
    tooltip.style.display = 'none';
  }, 150);
}

let lastTouristProfileTrigger = null;

function formatTouristProfileDate(value) {
  if (!value) return 'Not available';
  const parsed = new Date(String(value).replace(' ', 'T'));
  if (Number.isNaN(parsed.getTime())) return value;
  return parsed.toLocaleDateString('en-PH', {
    year: 'numeric',
    month: 'long',
    day: 'numeric'
  });
}

function openTouristProfileDrawer(img) {
  const drawer = document.getElementById('touristProfileDrawer');
  const content = document.getElementById('touristProfileContent');
  if (!drawer || !content || !img) return;

  clearTimeout(hideTimeout);
  tooltip.classList.remove('show');
  tooltip.style.display = 'none';
  lastTouristProfileTrigger = img;

  const fullName = escapeBookingDetail(img.dataset.fullname || 'Guest');
  const email = escapeBookingDetail(img.dataset.email || '-');
  const phone = escapeBookingDetail(img.dataset.phone || '-');
  const address = escapeBookingDetail(img.dataset.address || '-');
  const touristId = escapeBookingDetail(img.dataset.touristId || '-');
  const accountStatusRaw = img.dataset.accountStatus || 'active';
  const accountStatus = escapeBookingDetail(
    accountStatusRaw.charAt(0).toUpperCase() + accountStatusRaw.slice(1)
  );
  const isVerified = img.dataset.emailVerified === '1';
  const isGoogleConnected = img.dataset.googleConnected === '1';
  const totalBookings = escapeBookingDetail(img.dataset.totalBookings || 0);
  const completedBookings = escapeBookingDetail(img.dataset.finishedBookings || 0);
  const joinedDate = escapeBookingDetail(formatTouristProfileDate(img.dataset.createdAt));
  const updatedDate = escapeBookingDetail(formatTouristProfileDate(img.dataset.updatedAt));
  const banNote = escapeBookingDetail(img.dataset.banNote || '');
  const profileSrc = escapeBookingDetail(img.src);
  const emailHref = img.dataset.email && img.dataset.email !== '-'
    ? `mailto:${encodeURIComponent(img.dataset.email)}`
    : '#';

  const icon = {
    mail: '<svg viewBox="0 0 24 24"><rect x="3" y="5" width="18" height="14" rx="2"></rect><path d="m4 7 8 6 8-6"></path></svg>',
    phone: '<svg viewBox="0 0 24 24"><path d="M7 3H4a1 1 0 0 0-1 1c0 9.4 7.6 17 17 17a1 1 0 0 0 1-1v-3l-4-2-2 3a15 15 0 0 1-9-9l3-2-2-4Z"></path></svg>',
    map: '<svg viewBox="0 0 24 24"><path d="M20 10c0 5-8 12-8 12S4 15 4 10a8 8 0 1 1 16 0Z"></path><circle cx="12" cy="10" r="2.5"></circle></svg>',
    shield: '<svg viewBox="0 0 24 24"><path d="M12 3 4.5 6v5.5c0 4.8 3.2 8 7.5 9.5 4.3-1.5 7.5-4.7 7.5-9.5V6L12 3Z"></path><path d="m9 12 2 2 4-4"></path></svg>',
    calendar: '<svg viewBox="0 0 24 24"><rect x="3" y="5" width="18" height="16" rx="2"></rect><path d="M16 3v4M8 3v4M3 10h18"></path></svg>',
    link: '<svg viewBox="0 0 24 24"><path d="M10 13a5 5 0 0 0 7.5.5l2-2a5 5 0 0 0-7-7l-1.2 1.2"></path><path d="M14 11a5 5 0 0 0-7.5-.5l-2 2a5 5 0 0 0 7 7l1.2-1.2"></path></svg>'
  };

  content.innerHTML = `
    <section class="tp-hero">
      <img src="${profileSrc}" alt="">
      <div class="tp-hero-copy">
        <span class="tp-eyebrow">REGISTERED TOURIST</span>
        <h2>${fullName}</h2>
        <p>Tourist ID: #${touristId}</p>
        <div class="tp-hero-pills">
          <span class="tp-status">${accountStatus}</span>
          <span class="${isVerified ? 'is-positive' : 'is-warning'}">${isVerified ? 'Email verified' : 'Email unverified'}</span>
        </div>
      </div>
    </section>

    <section class="tp-stats" aria-label="Booking summary">
      <div><strong>${totalBookings}</strong><span>Total bookings</span></div>
      <div><strong>${completedBookings}</strong><span>Completed tours</span></div>
      <div><strong>${joinedDate}</strong><span>Member since</span></div>
    </section>

    <section class="tp-section">
      <div class="tp-section-heading"><span>${icon.mail}</span><div><h3>Contact information</h3><p>Primary contact details for this tourist</p></div></div>
      <div class="tp-info-list">
        <div class="tp-info-row"><span>${icon.mail}</span><div><small>Email address</small><strong>${email}</strong></div></div>
        <div class="tp-info-row"><span>${icon.phone}</span><div><small>Phone number</small><strong>${phone}</strong></div></div>
        <div class="tp-info-row"><span>${icon.map}</span><div><small>Home address</small><strong>${address}</strong></div></div>
      </div>
      <a class="tp-email-action${emailHref === '#' ? ' is-disabled' : ''}" href="${emailHref}">
        ${icon.mail}<span>Send email to tourist</span><b aria-hidden="true">&rarr;</b>
      </a>
    </section>

    <section class="tp-section">
      <div class="tp-section-heading"><span>${icon.shield}</span><div><h3>Account information</h3><p>Registration, verification, and access status</p></div></div>
      <div class="tp-account-grid">
        <div><small>Account status</small><strong>${accountStatus}</strong></div>
        <div><small>Email verification</small><strong>${isVerified ? 'Verified' : 'Not verified'}</strong></div>
        <div><small>Sign-in connection</small><strong>${isGoogleConnected ? 'Google connected' : 'Email and password'}</strong></div>
        <div><small>Last profile update</small><strong>${updatedDate}</strong></div>
      </div>
    </section>

    <section class="tp-section tp-history">
      <div class="tp-section-heading"><span>${icon.calendar}</span><div><h3>Profile timeline</h3><p>Account activity dates recorded by the system</p></div></div>
      <div class="tp-timeline">
        <div><i></i><span><small>Account created</small><strong>${joinedDate}</strong></span></div>
        <div><i></i><span><small>Information last updated</small><strong>${updatedDate}</strong></span></div>
      </div>
    </section>

    ${banNote ? `<section class="tp-ban-note"><strong>Account restriction note</strong><p>${banNote}</p></section>` : ''}
  `;

  drawer.classList.add('show');
  drawer.setAttribute('aria-hidden', 'false');
  document.body.classList.add('tourist-profile-open');
  drawer.querySelector('.tourist-profile-close')?.focus();
}

function closeTouristProfileDrawer() {
  const drawer = document.getElementById('touristProfileDrawer');
  if (!drawer) return;
  drawer.classList.remove('show');
  drawer.setAttribute('aria-hidden', 'true');
  document.body.classList.remove('tourist-profile-open');
  if (lastTouristProfileTrigger) lastTouristProfileTrigger.focus();
}

document.querySelectorAll('.hover-profile').forEach(img => {
  img.addEventListener('mouseenter', () => showTooltip(img));
  img.addEventListener('mouseleave', hideTooltip);
  img.addEventListener('click', () => openTouristProfileDrawer(img));
  img.addEventListener('keydown', event => {
    if (event.key === 'Enter' || event.key === ' ') {
      event.preventDefault();
      openTouristProfileDrawer(img);
    }
  });
});

tooltip.addEventListener('mouseenter', () => clearTimeout(hideTimeout));
tooltip.addEventListener('mouseleave', hideTooltip);

document.addEventListener('click', (e) => {
  if (!tooltip.contains(e.target) && !e.target.classList.contains('hover-profile')) {
    tooltip.classList.remove('show');
    tooltip.style.display = 'none';
  }
});

document.getElementById('touristProfileDrawer')?.addEventListener('mousedown', event => {
  if (event.target.id === 'touristProfileDrawer') closeTouristProfileDrawer();
});

document.addEventListener('keydown', event => {
  if (event.key === 'Escape' && document.getElementById('touristProfileDrawer')?.classList.contains('show')) {
    closeTouristProfileDrawer();
  }
});

const cancellationHtml = value => String(value ?? '').replace(/[&<>"']/g, character => ({
  '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'
})[character]);
const cancellationMoney = value => `₱${Number(value || 0).toLocaleString('en-PH', {minimumFractionDigits:2, maximumFractionDigits:2})}`;

function openCancellationDetailsDrawer(request) {
  const drawer = document.getElementById('cancellationDetailsDrawer');
  const content = document.getElementById('cancellationDetailsContent');
  if (!drawer || !content) return;
  const touristName = String(request.tourist || 'Guest');
  const initials = touristName.split(/\s+/).filter(Boolean).slice(0, 2).map(part => part[0]).join('').toUpperCase() || 'G';
  const profileImage = String(request.profile_endpoint || request.profile_image || '').trim();
  const isTourRequest = String(request.type || '').toLowerCase() !== 'hotel';
  const requestSchedule = bookingScheduleDetails({
    tour_start_date: request.service_date,
    tour_end_date: request.service_end_date || request.service_date,
    tour_type: request.tour_type
  });
  const avatar = profileImage
    ? `<img src="${cancellationHtml(profileImage)}" alt="${cancellationHtml(touristName)} profile picture">`
    : cancellationHtml(initials);
  const item = (label, value, extraClass = '') => `<div class="crd-info-item ${extraClass}"><span>${cancellationHtml(label)}</span><strong>${cancellationHtml(value || '-')}</strong></div>`;

  content.innerHTML = `
    <div class="crd-hero"><div><span>BOOKING REFERENCE</span><strong>${cancellationHtml(request.reference || '-')}</strong><small>${cancellationHtml(request.type || 'Booking')} cancellation request</small></div><b>${cancellationHtml(request.request_status || 'Awaiting approval')}</b></div>
    <section class="crd-guest-card"><div class="crd-avatar">${avatar}</div><div><span>PRIMARY GUEST</span><h4>${cancellationHtml(touristName)}</h4><p>${cancellationHtml(request.email || '-')}</p></div></section>
    <section class="crd-section">
      <div class="crd-section-title"><i>01</i><div><h4>Request overview</h4><p>Booking service and cancellation timing</p></div></div>
      <div class="crd-info-grid">${item('Selected service', request.service, 'wide')}${item(isTourRequest ? 'Tour schedule' : 'Service date', isTourRequest ? requestSchedule.schedule : formatBookingDate(request.service_date), isTourRequest ? 'wide' : '')}${isTourRequest ? item('Trip duration', requestSchedule.duration) : ''}${item('Requested on', request.requested_at)}${item('Notice period', `${Number(request.days_before || 0)} days before service`)}${item('Contact number', request.phone || 'Not provided')}</div>
    </section>
    <section class="crd-section">
      <div class="crd-section-title"><i>02</i><div><h4>Refund assessment</h4><p>Calculated from the cancellation policy</p></div></div>
      <div class="crd-money-grid"><div><span>AMOUNT PAID</span><strong>${cancellationMoney(request.paid)}</strong></div><div class="eligible"><span>ELIGIBLE REFUND</span><strong>${cancellationMoney(request.refundable)}</strong></div><div><span>NON-REFUNDABLE</span><strong>${cancellationMoney(request.non_refundable)}</strong></div></div>
      <div class="crd-policy"><span>ELIGIBILITY RULE</span><strong>${cancellationHtml(request.policy || '-')}</strong></div>
      ${String(request.policy || '').toLowerCase().includes('partial') ? `<div class="crd-info-grid" style="margin-top:12px">${item('Automatic refund destination', request.refund_destination || 'Not provided', 'wide')}${item('Destination status', request.refund_destination_verified || 'Pending tourist details', 'wide')}</div>` : ''}
      <div class="crd-status-row"><div><span>REQUEST STATUS</span><strong>${cancellationHtml(request.request_status || '-')}</strong></div><div><span>REFUND STATUS</span><strong>${cancellationHtml(request.refund_status || '-')}</strong></div></div>
    </section>
    <section class="crd-section crd-reason-section"><div class="crd-section-title"><i>03</i><div><h4>Cancellation reason</h4><p>Statement submitted by the tourist</p></div></div><blockquote>${cancellationHtml(request.reason || 'No reason provided.')}</blockquote>${request.admin_note ? `<div class="crd-admin-note"><span>ADMIN REVIEW NOTE</span><p>${cancellationHtml(request.admin_note)}</p></div>` : ''}</section>
    <div class="crd-verification-note"><strong>Request record verified</strong><span>Details are loaded from the current cancellation and booking records.</span></div>`;

  content.querySelector('.crd-avatar img')?.addEventListener('error', event => {
    event.currentTarget.parentElement.textContent = initials;
  }, {once:true});

  drawer.classList.add('show');
  drawer.setAttribute('aria-hidden', 'false');
  document.body.classList.add('cancellation-details-open');
  drawer.querySelector('.cancellation-details-close')?.focus();
}

function closeCancellationDetailsDrawer() {
  const drawer = document.getElementById('cancellationDetailsDrawer');
  drawer?.classList.remove('show');
  drawer?.setAttribute('aria-hidden', 'true');
  document.body.classList.remove('cancellation-details-open');
}

document.querySelectorAll('.view-cancellation-request').forEach(button => {
  button.addEventListener('click', () => {
    button.closest('.row-actions')?.classList.remove('open', 'drop-up');
    let request;
    try { request = JSON.parse(button.dataset.request || '{}'); } catch (error) { return; }
    openCancellationDetailsDrawer(request);
  });
});

document.getElementById('cancellationDetailsDrawer')?.addEventListener('mousedown', event => {
  if (event.target.id === 'cancellationDetailsDrawer') closeCancellationDetailsDrawer();
});
document.addEventListener('keydown', event => {
  if (event.key === 'Escape' && document.getElementById('cancellationDetailsDrawer')?.classList.contains('show')) {
    closeCancellationDetailsDrawer();
  }
});

document.querySelectorAll('.provider-email-retry-form').forEach(form => {
  form.addEventListener('submit', event => {
    event.preventDefault();
    if (form.dataset.submitting === 'true') return;
    form.dataset.submitting = 'true';
    const button = form.querySelector('button[type="submit"]');
    if (button) button.disabled = true;
    form.closest('.row-actions')?.classList.remove('open', 'drop-up');
    Swal.fire({
      title: 'Resending Tourist Email',
      html: 'Please wait while the latest booking notice is sent to the tourist.',
      color: '#173f34',
      background: '#ffffff',
      allowOutsideClick: false,
      allowEscapeKey: false,
      allowEnterKey: false,
      showConfirmButton: false,
      showCancelButton: false,
      showDenyButton: false,
      didOpen: () => Swal.showLoading(),
      customClass: {
        popup: 'booking-email-swal-popup',
        title: 'booking-email-swal-title',
        htmlContainer: 'booking-email-swal-content',
        loader: 'booking-email-swal-loader'
      }
    });
    requestAnimationFrame(() => requestAnimationFrame(() => form.submit()));
  });
});

document.querySelectorAll('.cancellation-action-form').forEach(form => {
  form.addEventListener('submit', async event => {
    event.preventDefault();
    const action = form.dataset.action;
    if (action === 'reject') {
      const result = await Swal.fire({
        icon: 'warning',
        title: 'Reject Cancellation Request?',
        text: 'The booking will remain active. Tell the tourist why the request was not approved.',
        input: 'textarea',
        inputLabel: 'Admin review note',
        inputPlaceholder: 'Enter the reason for rejection...',
        inputAttributes: {maxlength: '1500'},
        showCancelButton: true,
        confirmButtonText: 'Reject Request',
        confirmButtonColor: '#b43845',
        preConfirm: value => {
          if (!String(value || '').trim()) {
            Swal.showValidationMessage('An admin review note is required.');
            return false;
          }
          return String(value).trim();
        }
      });
      if (!result.isConfirmed) return;
      form.querySelector('[name="admin_note"]').value = result.value;
      form.submit();
      return;
    }

    const approving = action === 'approve';
    const result = await Swal.fire({
      icon: approving ? 'question' : 'warning',
      title: approving ? 'Approve Cancellation?' : 'Mark Cancellation Completed?',
      text: approving
        ? 'The booking will be cancelled and its refund eligibility will move to the next step.'
        : 'This closes the cancellation workflow. This action is available only when no refund is due or the refund is complete.',
      showCancelButton: true,
      confirmButtonText: approving ? 'Approve Cancellation' : 'Mark Completed',
      confirmButtonColor: '#26745f'
    });
    if (result.isConfirmed) {
      if (approving) {
        Swal.fire({
          title: 'Approving Cancellation',
          text: 'Cancelling the booking and sending the confirmation email...',
          allowOutsideClick: false,
          allowEscapeKey: false,
          showConfirmButton: false,
          didOpen: () => Swal.showLoading()
        });
        requestAnimationFrame(() => requestAnimationFrame(() => form.submit()));
        return;
      }
      form.submit();
    }
  });
});

const focusedCancellationId = new URLSearchParams(window.location.search).get('focus_request');
if (focusedCancellationId && /^\d+$/.test(focusedCancellationId)) {
  const focusedRow = document.getElementById(`cancel-request-${focusedCancellationId}`);
  const cleanUrl = new URL(window.location.href);
  cleanUrl.searchParams.delete('focus_request');
  window.history.replaceState({}, '', `${cleanUrl.pathname}${cleanUrl.search}${cleanUrl.hash}`);
  if (focusedRow) {
    requestAnimationFrame(() => {
      focusedRow.classList.add('is-focused');
      focusedRow.scrollIntoView({behavior: 'smooth', block: 'center', inline: 'nearest'});
      window.setTimeout(() => focusedRow.classList.remove('is-focused'), 1200);
    });
  }
}


// ===========================================================
// ----------------- OVERVIEW FILTER -------------------------
// ===========================================================
const overviewForm = document.getElementById('overviewFilterForm');
if (overviewForm) {
  const range = overviewForm.querySelector('#adRangeFilter');
  const year = overviewForm.querySelector('#adRangeYear');
  const month = overviewForm.querySelector('#adRangeMonth');
  const date = overviewForm.querySelector('#adRangeDate');

  const syncOverviewFields = () => {
    const mode = range ? range.value : 'all';
    if (year) year.style.display = (mode === 'yearly' || mode === 'monthly' || mode === 'daily') ? '' : 'none';
    if (month) month.style.display = mode === 'monthly' ? '' : 'none';
    if (date) date.style.display = mode === 'daily' ? '' : 'none';
  };

  if (range) range.addEventListener('change', syncOverviewFields);
  syncOverviewFields();
}

// ===========================================================
// ----------------- LIVE SEARCH ----------------------------
// ===========================================================
const searchInput = document.querySelector('#searchForm input[name="search"]');
const tabPages = document.querySelectorAll('.tab-page');

if (searchInput) searchInput.addEventListener('input', () => {
  const query = searchInput.value.toLowerCase().trim();

  tabPages.forEach(tab => {
    const tbody = tab.querySelector('tbody');
    const rows = Array.from(tbody.querySelectorAll('tr'));

    // Remove any existing "no results" row
    const oldNoResults = tbody.querySelector('.no-results');
    if (oldNoResults) oldNoResults.remove();

    let anyVisible = false;

    rows.forEach(row => {
      row.style.display = row.classList.contains('no-results') ? 'none' :
        row.innerText.toLowerCase().includes(query) ? '' : 'none';
      if (row.style.display === '') anyVisible = true;
    });

    // Add "No results found" if nothing matches
    if (!anyVisible) {
      const tr = document.createElement('tr');
      tr.className = 'no-results';
      tr.innerHTML = `<td colspan="100%" style="text-align:center;color:#777;">No results found</td>`;
      tbody.appendChild(tr);
    }
  });
});

// ===========================================================
// ----------------- WALK-IN BOOKING -------------------------
// ===========================================================
(() => {
  const modal = document.getElementById('walkinBookingModal');
  const form = document.getElementById('walkinBookingForm');
  const openButton = document.getElementById('openWalkinBookingModal');
  if (!modal || !form || !openButton) return;

  const cancelButton = document.getElementById('cancelWalkinBooking');
  const closeButtons = [
    document.getElementById('closeWalkinBookingModal'),
    cancelButton
  ].filter(Boolean);
  const touristInput = document.getElementById('walkinTouristId');
  const touristSearch = document.getElementById('walkinTouristSearch');
  const touristResults = document.getElementById('walkinTouristResults');
  const selectedTouristCard = document.getElementById('walkinSelectedTourist');
  const selectedTouristName = document.getElementById('walkinSelectedTouristName');
  const selectedTouristEmail = document.getElementById('walkinSelectedTouristEmail');
  const selectedTouristAvatar = document.getElementById('walkinSelectedTouristAvatar');
  const touristSearchSpinner = document.getElementById('walkinTouristSearchSpinner');
  const touristSearchHelp = document.getElementById('walkinTouristHelp');
  const phoneInput = document.getElementById('walkinPhone');
  const existingPanel = document.getElementById('walkinExistingGuest');
  const newPanel = document.getElementById('walkinNewGuest');
  const typeSelect = document.getElementById('walkinBookingType');
  const tourType = document.getElementById('walkinTourType');
  const dateDisplay = document.getElementById('walkinDateDisplay');
  const bookingDateInput = document.getElementById('walkinBookingDate');
  const bookingEndDateInput = document.getElementById('walkinBookingEndDate');
  const packageSelect = document.getElementById('walkinPackageId');
  const boatSelect = document.getElementById('walkinBoatId');
  const guideSelect = document.getElementById('walkinGuideId');
  const locationSelect = document.getElementById('walkinLocation');
  const serviceAmountInput = document.getElementById('walkinServiceAmount');
  const expenseInputs = Array.from(form.querySelectorAll('.walkin-expense-input'));
  const totalInput = document.getElementById('walkinGrandTotal');
  const grandTotalLabel = document.getElementById('walkinGrandTotalLabel');
  const expensesTotalLabel = document.getElementById('walkinExpensesTotalLabel');
  const paymentOption = document.getElementById('walkinPaymentOption');
  const paymentInput = document.getElementById('walkinPaymentAmount');
  const remainingInput = document.getElementById('walkinRemainingBalance');
  const paymentMethod = document.getElementById('walkinPaymentMethod');
  const adultsInput = document.getElementById('walkinAdults');
  const childrenInput = document.getElementById('walkinChildren');
  const paxButtons = Array.from(form.querySelectorAll('[data-pax-action]'));
  const capacityNote = document.getElementById('walkinCapacityNote');
  const submitButton = document.getElementById('submitWalkinBooking');
  const nextButton = document.getElementById('walkinNextStep');
  const previousButton = document.getElementById('walkinPreviousStep');
  const stepStatus = document.getElementById('walkinStepStatus');
  const reviewSummary = document.getElementById('walkinReviewSummary');
  const modalBody = modal.querySelector('.walkin-modal-body');
  const stepPanels = Array.from(form.querySelectorAll('[data-walkin-step]'));
  const stepIndicators = Array.from(form.querySelectorAll('[data-step-indicator]'));
  const stepLines = Array.from(form.querySelectorAll('.walkin-step-line'));
  let currentStep = 1;
  let walkinDatePicker = null;
  let paymentManuallyEdited = false;
  let touristSearchTimer = null;
  let touristSearchRequest = 0;
  const servicePriceRows = <?= json_encode($walkInServicePrices, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
  const servicePrices = {};
  servicePriceRows.forEach(row => {
    servicePrices[String(row.service_type).toLowerCase()] = {
      day: Number(row.day_tour_price || 0),
      overnight: Number(row.overnight_price || 0)
    };
  });

  const formatIsoDate = date => {
    const year = date.getFullYear();
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');
    return `${year}-${month}-${day}`;
  };

  const normalizeTourType = value => {
    const normalized = String(value || '').toLowerCase().trim();
    return ['overnight', 'night', 'night tour', 'multi-day'].includes(normalized)
      ? 'overnight'
      : 'same-day';
  };

  const configureDatePicker = (clearSelection = true) => {
    if (!walkinDatePicker) return;
    const overnight = tourType.value === 'overnight';
    walkinDatePicker.set('mode', overnight ? 'range' : 'single');
    walkinDatePicker.set('showMonths', window.innerWidth >= 760 ? 2 : 1);
    dateDisplay.placeholder = overnight ? 'Select start and end dates' : 'Select tour date';
    if (clearSelection) {
      walkinDatePicker.clear(false);
      bookingDateInput.value = '';
      bookingEndDateInput.value = '';
      document.getElementById('walkinTourRange').value = '';
    }
  };

  if (typeof flatpickr === 'function') {
    walkinDatePicker = flatpickr(dateDisplay, {
      mode: 'single',
      showMonths: window.innerWidth >= 760 ? 2 : 1,
      minDate: 'today',
      dateFormat: 'F j, Y',
      conjunction: ' to ',
      disableMobile: true,
      monthSelectorType: 'static',
      nextArrow: '&#8250;',
      prevArrow: '&#8249;',
      onReady: (_, __, instance) => instance.calendarContainer.classList.add('walkin-date-calendar'),
      onChange: selectedDates => {
        const overnight = tourType.value === 'overnight';
        bookingDateInput.value = selectedDates[0] ? formatIsoDate(selectedDates[0]) : '';
        bookingEndDateInput.value = overnight && selectedDates[1] ? formatIsoDate(selectedDates[1]) : '';
        document.getElementById('walkinTourRange').value = overnight && bookingEndDateInput.value
          ? `${bookingDateInput.value} to ${bookingEndDateInput.value}`
          : bookingDateInput.value;
      }
    });
  }

  const setRequired = (container, required) => {
    container.querySelectorAll('input, select').forEach(field => {
      if (field.name === 'guest_address') return;
      field.required = required;
      field.disabled = !required;
    });
  };

  const renderTouristAvatar = (container, tourist) => {
    if (!container) return;
    const initial = String(tourist.full_name || 'T').trim().charAt(0).toUpperCase() || 'T';
    const fallback = document.createElement('b');
    fallback.textContent = initial;
    container.replaceChildren(fallback);
    if (tourist.profile_image) {
      const image = document.createElement('img');
      image.src = tourist.profile_image;
      image.alt = '';
      image.addEventListener('error', () => image.remove());
      container.appendChild(image);
    }
  };

  const selectTourist = tourist => {
    touristInput.value = String(tourist.tourist_id || '');
    touristSearch.setCustomValidity('');
    selectedTouristCard.dataset.name = tourist.full_name || '';
    selectedTouristCard.dataset.phone = tourist.phone_number || '';
    selectedTouristName.textContent = tourist.full_name || 'Tourist';
    selectedTouristEmail.textContent = [tourist.email, tourist.phone_number].filter(Boolean).join(' · ') || 'No contact details';
    renderTouristAvatar(selectedTouristAvatar, tourist);
    phoneInput.value = tourist.phone_number || '';
    selectedTouristCard.hidden = false;
    touristSearch.closest('.walkin-field').hidden = true;
    touristResults.hidden = true;
    touristSearchHelp.textContent = 'This booking will be recorded in the selected tourist’s account.';
  };

  const clearSelectedTourist = () => {
    touristInput.value = '';
    selectedTouristCard.dataset.name = '';
    selectedTouristCard.dataset.phone = '';
    selectedTouristCard.hidden = true;
    touristSearch.closest('.walkin-field').hidden = false;
    touristSearch.value = '';
    touristSearch.setCustomValidity('');
    touristResults.hidden = true;
    phoneInput.value = '';
    touristSearchHelp.textContent = 'Type at least two characters to find a registered tourist.';
    setTimeout(() => touristSearch.focus(), 30);
  };

  const renderTouristResults = tourists => {
    touristResults.replaceChildren();
    if (!tourists.length) {
      const empty = document.createElement('p');
      empty.className = 'walkin-tourist-empty';
      empty.textContent = 'No matching active tourist accounts found.';
      touristResults.appendChild(empty);
    } else {
      tourists.forEach(tourist => {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'walkin-tourist-result';
        const avatar = document.createElement('span');
        avatar.className = 'walkin-tourist-avatar';
        renderTouristAvatar(avatar, tourist);
        const copy = document.createElement('span');
        const name = document.createElement('strong');
        const details = document.createElement('small');
        name.textContent = tourist.full_name || 'Tourist';
        details.textContent = [tourist.email, tourist.phone_number].filter(Boolean).join(' · ') || 'No contact details';
        copy.append(name, details);
        const action = document.createElement('b');
        action.textContent = 'Select';
        button.append(avatar, copy, action);
        button.addEventListener('click', () => selectTourist(tourist));
        touristResults.appendChild(button);
      });
    }
    touristResults.hidden = false;
  };

  const searchTourists = async query => {
    const requestId = ++touristSearchRequest;
    touristSearchSpinner.hidden = false;
    try {
      const response = await fetch(`adbookings.php?action=searchWalkinTourists&q=${encodeURIComponent(query)}`, {
        headers: { Accept: 'application/json' }
      });
      if (!response.ok) throw new Error('Tourist search failed');
      const result = await response.json();
      if (requestId === touristSearchRequest) {
        renderTouristResults(Array.isArray(result.tourists) ? result.tourists : []);
      }
    } catch (error) {
      if (requestId === touristSearchRequest) renderTouristResults([]);
      console.error(error);
    } finally {
      if (requestId === touristSearchRequest) touristSearchSpinner.hidden = true;
    }
  };

  const syncGuestMode = () => {
    const isNew = form.elements.guest_mode.value === 'new';
    existingPanel.hidden = isNew;
    newPanel.hidden = !isNew;
    setRequired(existingPanel, !isNew);
    setRequired(newPanel, isNew);
    if (isNew) {
      newPanel.querySelector('[name="guest_address"]').disabled = false;
      phoneInput.value = '';
    } else {
      phoneInput.value = selectedTouristCard.dataset.phone || '';
    }
  };

  const syncResources = () => {
    const type = typeSelect.value;
    const resources = [
      ['package', document.getElementById('walkinPackageField'), packageSelect],
      ['boat', document.getElementById('walkinBoatField'), boatSelect],
      ['tourguide', document.getElementById('walkinGuideField'), guideSelect]
    ];
    resources.forEach(([name, field, select]) => {
      const active = type === name;
      field.hidden = !active;
      select.disabled = !active;
      select.required = active;
      if (!active) select.value = '';
    });
    const needsLocation = type === 'boat' || type === 'tourguide';
    document.getElementById('walkinLocationField').hidden = !needsLocation;
    locationSelect.disabled = !needsLocation;
    locationSelect.required = needsLocation;
    if (!needsLocation) locationSelect.value = '';
    syncSuggestedPrice();
    syncCapacity();
  };

  const syncSuggestedPrice = () => {
    const type = typeSelect.value;
    let price = 0;
    if (type === 'package') {
      const option = packageSelect.options[packageSelect.selectedIndex];
      const guests = Math.max(1, Number(adultsInput.value || 0) + Number(childrenInput.value || 0));
      price = Number(option?.dataset.price || 0) * guests;
      if (option?.dataset.tourType) {
        const packageTourType = normalizeTourType(option.dataset.tourType);
        if (tourType.value !== packageTourType) {
          tourType.value = packageTourType;
          configureDatePicker(true);
        }
      }
    } else if (servicePrices[type]) {
      price = tourType.value === 'overnight'
        ? servicePrices[type].overnight
        : servicePrices[type].day;
    }
    serviceAmountInput.value = price.toFixed(2);
    syncBalance();
  };

  const syncBalance = (applySuggestedPayment = false) => {
    const serviceAmount = Math.max(0, Number(serviceAmountInput.value || 0));
    const expensesTotal = expenseInputs.reduce((sum, input) => sum + Math.max(0, Number(input.value || 0)), 0);
    const total = serviceAmount + expensesTotal;
    totalInput.value = total.toFixed(2);
    grandTotalLabel.textContent = `₱${total.toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
    expensesTotalLabel.textContent = `Expenses: ₱${expensesTotal.toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
    if (applySuggestedPayment || !paymentManuallyEdited) {
      const percentage = paymentOption.value === 'full' ? 1 : 0.2;
      paymentInput.value = (total * percentage).toFixed(2);
    }
    const payment = Math.max(0, Number(paymentInput.value || 0));
    remainingInput.value = Math.max(0, total - payment).toFixed(2);
    paymentInput.max = total.toFixed(2);
    paymentMethod.required = payment > 0;
    if (payment <= 0) paymentMethod.value = '';
  };

  const syncPaxButtons = () => {
    const boatOption = boatSelect.options[boatSelect.selectedIndex];
    const boatCapacity = typeSelect.value === 'boat'
      ? Number(boatOption?.dataset.capacity || 0)
      : 0;
    const totalGuests = Number(adultsInput.value || 0) + Number(childrenInput.value || 0);

    paxButtons.forEach(button => {
      const input = document.getElementById(button.dataset.paxTarget);
      if (!input) return;
      const value = Number(input.value || 0);
      const minimum = Number(input.min || 0);
      const maximum = Number(input.max || 100);
      const decreasing = button.dataset.paxAction === 'decrease';
      button.disabled = decreasing
        ? value <= minimum
        : value >= maximum || (boatCapacity > 0 && totalGuests >= boatCapacity);
    });
  };

  const syncCapacity = () => {
    const option = boatSelect.options[boatSelect.selectedIndex];
    const capacity = Number(option?.dataset.capacity || 0);
    if (typeSelect.value !== 'boat' || !capacity) {
      capacityNote.hidden = true;
      capacityNote.classList.remove('is-error');
      boatSelect.setCustomValidity('');
      syncPaxButtons();
      return;
    }
    const guests = Math.max(0, Number(adultsInput.value || 0)) + Math.max(0, Number(childrenInput.value || 0));
    const exceeds = guests > capacity;
    capacityNote.hidden = false;
    capacityNote.classList.toggle('is-error', exceeds);
    capacityNote.textContent = exceeds
      ? `Guest count exceeds this boat's ${capacity}-person capacity.`
      : `${guests} of ${capacity} boat seats will be used.`;
    boatSelect.setCustomValidity(exceeds ? 'Select a larger boat or reduce the guest count.' : '');
    syncPaxButtons();
  };

  const selectedText = select => {
    const option = select?.options[select.selectedIndex];
    return option && option.value ? option.textContent.trim() : 'Not selected';
  };

  const escapeHtml = value => String(value).replace(/[&<>"']/g, character => ({
    '&': '&amp;',
    '<': '&lt;',
    '>': '&gt;',
    '"': '&quot;',
    "'": '&#039;'
  })[character]);

  const renderReview = () => {
    const isNewGuest = form.elements.guest_mode.value === 'new';
    const guest = isNewGuest
      ? (form.elements.guest_name.value.trim() || 'Walk-in guest')
      : (selectedTouristCard.dataset.name || 'Tourist account not selected');
    const typeLabels = {
      package: 'Tour package',
      boat: 'Tour boat',
      tourguide: 'Tour guide'
    };
    const resources = {
      package: packageSelect,
      boat: boatSelect,
      tourguide: guideSelect
    };
    const bookingType = typeSelect.value;
    const formattedDate = dateDisplay.value || 'Not selected';
    const totalGuests = Number(adultsInput.value || 0) + Number(childrenInput.value || 0);
    const destination = bookingType === 'package' ? 'Included in package' : selectedText(locationSelect);

    const items = [
      ['Guest', guest],
      ['Service', `${typeLabels[bookingType] || 'Service'} · ${selectedText(resources[bookingType])}`],
      ['Schedule', `${formattedDate} · ${tourType.value === 'overnight' ? 'Overnight' : 'Day tour'}`],
      ['Trip details', `${destination} · ${totalGuests} guest${totalGuests === 1 ? '' : 's'}`]
    ];
    reviewSummary.innerHTML = items.map(([label, value]) => `
      <div class="walkin-review-item">
        <span>${escapeHtml(label)}</span>
        <strong title="${escapeHtml(value)}">${escapeHtml(value)}</strong>
      </div>
    `).join('');
  };

  const validateStep = step => {
    if (step === 1 && form.elements.guest_mode.value === 'existing' && !touristInput.value) {
      touristSearch.setCustomValidity('Search for and select an active tourist account.');
      touristSearch.reportValidity();
      touristSearch.focus({ preventScroll: true });
      touristSearch.scrollIntoView({ behavior: 'smooth', block: 'center' });
      return false;
    }
    if (step === 2) syncCapacity();
    if (step === 2 && (!bookingDateInput.value || (tourType.value === 'overnight' && !bookingEndDateInput.value))) {
      Swal.fire({
        icon: 'warning',
        title: tourType.value === 'overnight' ? 'Select an overnight date range' : 'Select a tour date',
        text: tourType.value === 'overnight'
          ? 'Choose both the start and end dates from the calendar.'
          : 'Choose one date from the calendar.',
        confirmButtonColor: '#2b7a66'
      }).then(() => walkinDatePicker?.open());
      return false;
    }
    const panel = stepPanels.find(item => Number(item.dataset.walkinStep) === step);
    if (!panel) return true;
    const fields = Array.from(panel.querySelectorAll('input, select, textarea'))
      .filter(field => !field.disabled);
    const invalidField = fields.find(field => !field.checkValidity());
    if (invalidField) {
      invalidField.reportValidity();
      invalidField.focus({ preventScroll: true });
      invalidField.scrollIntoView({ behavior: 'smooth', block: 'center' });
      return false;
    }
    return true;
  };

  const showStep = step => {
    currentStep = Math.min(3, Math.max(1, step));
    stepPanels.forEach(panel => {
      const isActive = Number(panel.dataset.walkinStep) === currentStep;
      panel.hidden = !isActive;
      panel.classList.toggle('is-active', isActive);
    });
    stepIndicators.forEach(indicator => {
      const indicatorStep = Number(indicator.dataset.stepIndicator);
      indicator.classList.toggle('is-active', indicatorStep === currentStep);
      indicator.classList.toggle('is-complete', indicatorStep < currentStep);
      if (indicatorStep === currentStep) indicator.setAttribute('aria-current', 'step');
      else indicator.removeAttribute('aria-current');
    });
    stepLines.forEach((line, index) => {
      line.classList.toggle('is-complete', currentStep > index + 1);
    });
    cancelButton.hidden = currentStep !== 1;
    previousButton.hidden = currentStep === 1;
    nextButton.hidden = currentStep === 3;
    submitButton.hidden = currentStep !== 3;
    nextButton.innerHTML = currentStep === 1
      ? 'Continue to Booking Details<svg viewBox="0 0 24 24"><path d="m9 18 6-6-6-6"/></svg>'
      : 'Review Booking<svg viewBox="0 0 24 24"><path d="m9 18 6-6-6-6"/></svg>';
    stepStatus.textContent = `Step ${currentStep} of 3 · Required fields are marked with an asterisk.`;
    if (currentStep === 3) renderReview();
    modalBody.scrollTo({ top: 0, behavior: 'smooth' });
    const firstField = stepPanels[currentStep - 1]?.querySelector('input:not([disabled]), select:not([disabled])');
    setTimeout(() => firstField?.focus({ preventScroll: true }), 80);
  };

  const openModal = () => {
    showStep(1);
    modal.classList.add('is-open');
    modal.setAttribute('aria-hidden', 'false');
    document.body.classList.add('walkin-modal-open');
    const firstGuestField = form.elements.guest_mode.value === 'existing'
      ? (touristInput.value ? phoneInput : touristSearch)
      : form.elements.guest_name;
    setTimeout(() => firstGuestField?.focus(), 40);
  };

  const closeModal = () => {
    modal.classList.remove('is-open');
    modal.setAttribute('aria-hidden', 'true');
    document.body.classList.remove('walkin-modal-open');
    openButton.focus();
  };

  openButton.addEventListener('click', openModal);
  closeButtons.forEach(button => button.addEventListener('click', closeModal));
  modal.addEventListener('mousedown', event => {
    if (event.target === modal) closeModal();
  });
  document.addEventListener('keydown', event => {
    if (event.key === 'Escape' && modal.classList.contains('is-open')) closeModal();
  });
  form.querySelectorAll('[name="guest_mode"]').forEach(input => input.addEventListener('change', syncGuestMode));
  touristSearch.addEventListener('input', () => {
    touristSearch.setCustomValidity('');
    clearTimeout(touristSearchTimer);
    const query = touristSearch.value.trim();
    if (query.length < 2) {
      touristResults.hidden = true;
      return;
    }
    touristSearchTimer = setTimeout(() => searchTourists(query), 280);
  });
  document.getElementById('walkinChangeTourist').addEventListener('click', clearSelectedTourist);
  typeSelect.addEventListener('change', () => {
    configureDatePicker(true);
    syncResources();
  });
  packageSelect.addEventListener('change', syncSuggestedPrice);
  boatSelect.addEventListener('change', syncCapacity);
  tourType.addEventListener('change', () => {
    configureDatePicker(true);
    syncSuggestedPrice();
  });
  [serviceAmountInput, ...expenseInputs].forEach(input => input.addEventListener('input', () => syncBalance(false)));
  paymentOption.addEventListener('change', () => {
    paymentManuallyEdited = false;
    syncBalance(true);
  });
  paymentInput.addEventListener('input', () => {
    paymentManuallyEdited = true;
    syncBalance(false);
  });
  [adultsInput, childrenInput].forEach(input => input.addEventListener('input', () => {
    syncCapacity();
    if (typeSelect.value === 'package') syncSuggestedPrice();
  }));
  paxButtons.forEach(button => {
    button.addEventListener('click', () => {
      const input = document.getElementById(button.dataset.paxTarget);
      if (!input) return;
      const minimum = Number(input.min || 0);
      const maximum = Number(input.max || 100);
      const adjustment = button.dataset.paxAction === 'increase' ? 1 : -1;
      input.value = String(Math.min(maximum, Math.max(minimum, Number(input.value || 0) + adjustment)));
      input.dispatchEvent(new Event('input', { bubbles: true }));
    });
  });
  nextButton.addEventListener('click', () => {
    if (!validateStep(currentStep)) return;
    showStep(currentStep + 1);
  });
  previousButton.addEventListener('click', () => showStep(currentStep - 1));
  form.addEventListener('submit', event => {
    syncBalance();
    syncCapacity();
    if (currentStep < 3) {
      event.preventDefault();
      if (validateStep(currentStep)) showStep(currentStep + 1);
      return;
    }
    if (!form.checkValidity()) {
      event.preventDefault();
      form.reportValidity();
      return;
    }
    submitButton.disabled = true;
    submitButton.lastChild.textContent = ' Creating…';
  });

  syncGuestMode();
  syncResources();
  configureDatePicker(false);
  syncBalance(true);
  syncPaxButtons();
  showStep(1);
})();
</script>
</body>
</html>

