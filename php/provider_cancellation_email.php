<?php

declare(strict_types=1);

require_once __DIR__ . '/PHPMailer/src/Exception.php';
require_once __DIR__ . '/PHPMailer/src/PHPMailer.php';
require_once __DIR__ . '/PHPMailer/src/SMTP.php';
require_once __DIR__ . '/booking_confirmation_email.php';
require_once __DIR__ . '/../payments/PaymentHelper.php';
require_once __DIR__ . '/app_url_helper.php';

use PHPMailer\PHPMailer\PHPMailer;

function bookingWorkflowBaseUrl(): string
{
    return ItourTryCanonicalAppUrl('booking workflow email link');
}

function bookingWorkflowProfileUrl(int $requestId): string
{
    $base = bookingWorkflowBaseUrl();
    return $base === '' ? '' : $base . '/php/profile.php?section=bookings&cancellation_request=' . $requestId . '#cancellation-' . $requestId;
}

function bookingWorkflowEmailRows(array $rows): string
{
    $html = '';
    foreach ($rows as $label => $value) {
        $html .= '<tr><td style="padding:9px 12px;color:#718078;font-size:12px;border-bottom:1px solid #e5eeea;width:38%">' . htmlspecialchars((string)$label) . '</td>'
            . '<td style="padding:9px 12px;color:#173f33;font-size:12px;font-weight:700;border-bottom:1px solid #e5eeea">' . htmlspecialchars((string)$value) . '</td></tr>';
    }
    return $html;
}

function bookingWorkflowEmailShell(string $eyebrow, string $title, string $intro, array $rows, string $notice, string $buttonLabel = '', string $buttonUrl = ''): string
{
    $button = $buttonUrl !== '' ? '<div style="padding:20px 0 5px;text-align:center"><a href="' . htmlspecialchars($buttonUrl) . '" style="display:inline-block;padding:13px 22px;border-radius:9px;background:#176b58;color:#fff;text-decoration:none;font-weight:800;font-size:13px">' . htmlspecialchars($buttonLabel) . '</a></div>' : '';
    return '<!doctype html><html><body style="margin:0;background:#eef5f2;font-family:Arial,sans-serif;color:#294b40"><div style="display:none;max-height:0;overflow:hidden">' . htmlspecialchars($title) . '</div>'
        . '<table role="presentation" width="100%" cellspacing="0" cellpadding="20"><tr><td align="center"><table role="presentation" width="620" style="max-width:620px;width:100%;background:#fff;border-radius:14px;overflow:hidden;box-shadow:0 8px 30px rgba(14,68,51,.12)">'
        . '<tr><td style="padding:28px 32px;text-align:center;background:#0d4d3b;color:#fff"><div style="font-size:11px;font-weight:800;letter-spacing:1px;color:#bde4d7">' . htmlspecialchars($eyebrow) . '</div><h1 style="margin:10px 0 5px;font-size:25px">' . htmlspecialchars($title) . '</h1><div style="font-size:13px;color:#d9eee7">iTour Mercedes</div></td></tr>'
        . '<tr><td style="padding:28px 32px"><p style="margin:0 0 18px;font-size:14px;line-height:1.7">' . $intro . '</p><table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border:1px solid #dce9e4;border-radius:10px;overflow:hidden">' . bookingWorkflowEmailRows($rows) . '</table>'
        . '<div style="margin-top:18px;padding:15px 17px;border-left:4px solid #d59a2c;background:#fff8e8;color:#684e1c;font-size:13px;line-height:1.65">' . $notice . '</div>' . $button
        . '<p style="margin:22px 0 0;font-size:12px;line-height:1.6;color:#75857f">If you need assistance, reply to this email and our tourism team will help you.</p></td></tr></table></td></tr></table></body></html>';
}

function sendBookingWorkflowEmail(string $email, string $name, string $subject, string $html, string $plain): bool
{
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return false;
    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host = getenv('SMTP_HOST') ?: 'smtp.gmail.com';
        $mail->SMTPAuth = true;
        $mail->Username = getenv('SMTP_USERNAME') ?: 'itourmercedes@gmail.com';
        $mail->Password = PaymentHelper::env('SMTP_PASSWORD');
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = (int)(getenv('SMTP_PORT') ?: 587);
        $mail->Timeout = 45;
        $trustedCaBundle = realpath(__DIR__ . '/../certs/firebase-ca-bundle.pem');
        $mail->SMTPOptions = [
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
                'allow_self_signed' => false,
                'cafile' => $trustedCaBundle !== false ? $trustedCaBundle : (string)ini_get('openssl.cafile'),
            ],
        ];
        $mail->CharSet = 'UTF-8';
        $mail->setFrom($mail->Username, 'iTour Mercedes');
        $mail->addReplyTo($mail->Username, 'iTour Mercedes');
        $mail->addAddress($email, $name);
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body = $html;
        $mail->AltBody = $plain;
        return BookingConfirmationEmailSend($mail);
    } catch (Throwable $error) {
        error_log('Booking workflow email failed: ' . $error->getMessage());
        return false;
    }
}

function sendRescheduleOfferEmail(array $request, array $tourist): bool
{
    $name = trim((string)($tourist['full_name'] ?? '')) ?: 'Guest';
    $deadline = date('F j, Y \a\t g:i A', strtotime((string)$request['decision_deadline']));
    $paid = '₱' . number_format((float)$request['amount_paid'], 2);
    $reference = (string)($request['booking_reference'] ?: ('Booking #' . $request['booking_id']));
    $url = bookingWorkflowProfileUrl((int)$request['cancellation_request_id']);
    $html = bookingWorkflowEmailShell('SCHEDULE UPDATE', 'Your response is required', 'Dear <strong>' . htmlspecialchars($name) . '</strong>,<br>The service provider has advised that your reservation cannot proceed on its original date. We are offering a <strong>free reschedule</strong>; your existing payment will remain attached to the same booking.', [
        'Booking reference' => $reference,
        'Service' => $request['service_name'],
        'Original date' => date('F j, Y', strtotime((string)$request['service_date'])),
        'Booking type' => ucfirst((string)$request['booking_type']),
        'Amount paid' => $paid,
        'Provider reason' => $request['cancellation_reason'],
        'Decision deadline' => $deadline,
    ], 'Choose a new date at no extra rescheduling fee, or cancel and receive a full refund of the amount actually paid. If no response is received by <strong>' . htmlspecialchars($deadline) . '</strong>, the booking will automatically be cancelled and submitted for full-refund processing.', 'Manage Booking', $url);
    return sendBookingWorkflowEmail((string)($tourist['email'] ?? ''), $name, 'iTour Mercedes | Response Required (' . $reference . ')', $html, 'Your booking cannot proceed on its original date. Choose a free reschedule or full refund by ' . $deadline . '. Manage your booking: ' . $url);
}

function sendProviderCancellationEmail(array $request, array $tourist): bool
{
    $name = trim((string)($tourist['full_name'] ?? '')) ?: 'Guest';
    $reference = (string)($request['booking_reference'] ?: ('Booking #' . $request['booking_id']));
    $timeframe = trim((string)getenv('REFUND_PROCESSING_TIMEFRAME'));
    $timeframeText = $timeframe !== '' ? $timeframe : 'The tourism office will provide the processing timeline when the refund is reviewed.';
    $html = bookingWorkflowEmailShell('BOOKING CANCELLED', 'Full refund submitted', 'Dear <strong>' . htmlspecialchars($name) . '</strong>,<br>The service provider has cancelled this booking. No action is required from you, and the provider-cancellation policy entitles you to a full refund of the amount successfully paid.', [
        'Booking reference' => $reference,
        'Service' => $request['service_name'],
        'Scheduled date' => date('F j, Y', strtotime((string)$request['service_date'])),
        'Provider reason' => $request['cancellation_reason'],
        'Amount paid' => '₱' . number_format((float)$request['amount_paid'], 2),
        'Refund amount' => '₱' . number_format((float)$request['refundable_amount'], 2),
        'Refund status' => (float)$request['refundable_amount'] > 0 ? 'Submitted for processing' : 'No payment to refund',
    ], '<strong>Expected processing:</strong> ' . htmlspecialchars($timeframeText));
    return sendBookingWorkflowEmail((string)($tourist['email'] ?? ''), $name, 'iTour Mercedes | Booking Cancelled (' . $reference . ')', $html, 'The provider cancelled ' . $reference . '. Refund amount: ₱' . number_format((float)$request['refundable_amount'], 2) . '. ' . $timeframeText);
}

function sendRescheduleConfirmationEmail(array $request, array $tourist): bool
{
    $name = trim((string)($tourist['full_name'] ?? '')) ?: 'Guest';
    $reference = (string)($request['booking_reference'] ?: ('Booking #' . $request['booking_id']));
    $html = bookingWorkflowEmailShell('RESCHEDULE CONFIRMED', 'Your new date is confirmed', 'Dear <strong>' . htmlspecialchars($name) . '</strong>,<br>Your booking has been rescheduled successfully. The booking reference and all service, guest, and payment details remain unchanged.', [
        'Booking reference' => $reference,
        'Service' => $request['service_name'],
        'Original date' => date('F j, Y', strtotime((string)$request['original_service_date'])),
        'New date' => date('F j, Y', strtotime((string)$request['rescheduled_service_date'])),
        'Amount paid remains applied' => '₱' . number_format((float)$request['amount_paid'], 2),
        'Remaining balance' => 'Unchanged',
    ], 'Please use the new date shown above. Your original schedule will no longer proceed.', 'View My Bookings', bookingWorkflowProfileUrl((int)$request['cancellation_request_id']));
    return sendBookingWorkflowEmail((string)($tourist['email'] ?? ''), $name, 'iTour Mercedes | Reschedule Confirmed (' . $reference . ')', $html, 'Your booking was moved from ' . $request['original_service_date'] . ' to ' . $request['rescheduled_service_date'] . '. Your existing payment remains applied.');
}
