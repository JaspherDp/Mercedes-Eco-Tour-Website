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
    return itourEmailPublicBaseUrl();
}

function bookingWorkflowProfileUrl(int $requestId): string
{
    return itourEmailProfileUrl(['section' => 'bookings', 'cancellation_request' => $requestId], 'cancellation-' . $requestId);
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
    $greeting = '';
    if (preg_match('/^(Dear\s[\s\S]*?),<br\s*\/?>([\s\S]*)$/i', $intro, $parts)) {
        $greeting = '<p style="margin:0 0 10px;font-size:14px;line-height:1.7;text-align:left;text-indent:0;">' . $parts[1] . ',</p>';
        $intro = $parts[2];
    }
    $button = $buttonUrl !== '' ? '<div style="padding:20px 0 5px;text-align:center"><a href="' . htmlspecialchars($buttonUrl) . '" style="display:inline-block;padding:13px 22px;border-radius:9px;background:#176b58;color:#fff;text-decoration:none;font-weight:800;font-size:13px">' . htmlspecialchars($buttonLabel) . '</a></div>' : '';
    return '<!doctype html><html><body style="margin:0;background:#eef5f2;font-family:Arial,sans-serif;color:#294b40"><div style="display:none;max-height:0;overflow:hidden">' . htmlspecialchars($title) . '</div>'
        . '<table role="presentation" width="100%" cellspacing="0" cellpadding="20"><tr><td align="center"><table role="presentation" width="620" style="max-width:620px;width:100%;background:#fff;border-radius:14px;overflow:hidden;box-shadow:0 8px 30px rgba(14,68,51,.12)">'
        . '<tr><td style="padding:28px 32px;text-align:center;background:#0d4d3b;color:#fff"><div style="font-size:11px;font-weight:800;letter-spacing:1px;color:#bde4d7">' . htmlspecialchars($eyebrow) . '</div><h1 style="margin:10px 0 5px;font-size:25px">' . htmlspecialchars($title) . '</h1><div style="font-size:13px;color:#d9eee7">iTour Mercedes</div></td></tr>'
        . '<tr><td style="padding:28px 32px">' . $greeting . '<p style="margin:0 0 18px;font-size:14px;line-height:1.7;text-align:justify;text-indent:28px;">' . $intro . '</p><table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border:1px solid #dce9e4;border-radius:10px;overflow:hidden">' . bookingWorkflowEmailRows($rows) . '</table>'
        . '<div style="margin-top:18px;padding:15px 17px;border-left:4px solid #d59a2c;background:#fff8e8;color:#684e1c;font-size:13px;line-height:1.65;text-align:justify;text-indent:28px;">' . $notice . '</div>' . $button
        . '<p style="margin:22px 0 0;font-size:12px;line-height:1.6;color:#75857f;text-align:left;text-indent:0;">If you need assistance, reply to this email and our tourism team will help you.</p>' . itourEmailQuickLinks() . '</td></tr></table></td></tr></table></body></html>';
}

function bookingWorkflowConfigureSmtp(PHPMailer $mail): void
{
    $mail->isSMTP();
    $mail->Host = PaymentHelper::env('SMTP_HOST', 'smtp.gmail.com');
    $mail->SMTPAuth = true;
    $mail->Username = PaymentHelper::env('SMTP_USERNAME', 'itourmercedes@gmail.com');
    $mail->Password = PaymentHelper::env('SMTP_PASSWORD');
    $mail->Port = (int)PaymentHelper::env('SMTP_PORT', '587');
    $mail->SMTPSecure = $mail->Port === 465
        ? PHPMailer::ENCRYPTION_SMTPS
        : PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Timeout = 45;
    $ssl = ['verify_peer' => true, 'verify_peer_name' => true, 'allow_self_signed' => false];
    $configuredCaBundle = PaymentHelper::env('SMTP_CA_FILE');
    if ($configuredCaBundle !== '') {
        if (!is_file($configuredCaBundle) || !is_readable($configuredCaBundle)) {
            throw new RuntimeException('The configured SMTP_CA_FILE is missing or unreadable.');
        }
        $ssl['cafile'] = $configuredCaBundle;
    } else {
        // These bundles are optional deployment files. Without one, PHP uses
        // its configured/system CA store; never force a nonexistent cafile.
        $candidates = PHP_OS_FAMILY === 'Windows'
            ? [__DIR__ . '/../certs/smtp-ca-bundle.local.pem', __DIR__ . '/../certs/firebase-ca-bundle.pem']
            : [__DIR__ . '/../certs/firebase-ca-bundle.pem'];
        foreach ($candidates as $candidate) {
            if (is_file($candidate) && is_readable($candidate)) {
                $ssl['cafile'] = $candidate;
                break;
            }
        }
    }
    $mail->SMTPOptions = ['ssl' => $ssl];
}

function bookingWorkflowEmailFailureMessage(?string $message = null): string
{
    static $lastMessage = 'The email server did not accept the message. Check the server PHP error log for the SMTP error, then try again.';
    if ($message !== null) $lastMessage = $message;
    return $lastMessage;
}

function sendBookingWorkflowEmail(string $email, string $name, string $subject, string $html, string $plain): bool
{
    bookingWorkflowEmailFailureMessage('The email server did not accept the message. Check the server PHP error log for the SMTP error, then try again.');
    $email = trim($email);
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        bookingWorkflowEmailFailureMessage('The tourist email address is missing or invalid. Correct it before resending the notice.');
        error_log('Booking workflow email failed: missing or invalid recipient address.');
        return false;
    }
    $mail = new PHPMailer(true);
    try {
        bookingWorkflowConfigureSmtp($mail);
        if ($mail->Password === '') {
            throw new RuntimeException('SMTP_PASSWORD is missing from the private environment configuration.');
        }
        $mail->CharSet = 'UTF-8';
        $mail->setFrom($mail->Username, 'iTour Mercedes');
        $mail->addReplyTo($mail->Username, 'iTour Mercedes');
        $mail->addAddress($email, $name);
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body = $html;
        $mail->AltBody = $plain;
        if (BookingConfirmationEmailSend($mail)) return true;
        throw new RuntimeException($mail->ErrorInfo ?: 'The SMTP server did not accept the message.');
    } catch (Throwable $error) {
        error_log('Booking workflow email failed: ' . $error->getMessage());
        $detail = strtolower($error->getMessage());
        if (str_contains($detail, 'certificate') || str_contains($detail, 'smtp_ca_file')) {
            bookingWorkflowEmailFailureMessage('The email connection failed certificate verification. Check the server trusted CA certificates and SMTP_CA_FILE setting, then try again.');
        } elseif (str_contains($detail, 'authenticat') || str_contains($detail, 'smtp_password')) {
            bookingWorkflowEmailFailureMessage('The email server authentication failed. Check SMTP_USERNAME and SMTP_PASSWORD in the private server configuration, then try again.');
        } elseif (str_contains($detail, 'connect') || str_contains($detail, 'timed out')) {
            bookingWorkflowEmailFailureMessage('The website could not connect to the email server. Check SMTP_HOST, SMTP_PORT and hosting outbound SMTP access, then try again.');
        }
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
    if (in_array($request['tourist_decision'] ?? '', ['booking_rejected','hotel_booking_cancelled'], true)) return sendBookingRejectedEmail($request, $tourist);
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


function BookingRejectionEmailContent(array $request, array $tourist): array
{
    $name = trim((string)($tourist['full_name'] ?? '')) ?: 'Guest';
    $reference = (string)($request['booking_reference'] ?? 'Booking');
    $reason = (string)($request['cancellation_reason'] ?? '');
    $paid = max(0, (float)($request['amount_paid'] ?? 0));
    $refund = max(0, (float)($request['refundable_amount'] ?? 0));
    $notice = $paid > 0 ? 'Your cancellation request is already approved. The full amount successfully paid is eligible for refund and has been submitted for processing. This notice does not mean that the refund transfer is complete.' : 'Your cancellation request is already approved. No payment was received, so there is no amount to refund.';
    $cancelledByHotel = ($request['booking_domain'] ?? '') === 'hotel' && ($request['tourist_decision'] ?? '') === 'hotel_booking_cancelled';
    $html = bookingWorkflowEmailShell($cancelledByHotel ? 'BOOKING CANCELLED' : 'BOOKING REJECTED', $cancelledByHotel ? 'Hotel booking cancellation notice' : 'Booking rejection notice',
        'Dear <strong>'.htmlspecialchars($name, ENT_QUOTES, 'UTF-8').'</strong>,<br>'.($cancelledByHotel ? 'The property has cancelled your previously accepted hotel booking.' : 'The service provider could not accept your pending booking. The booking has been rejected.').' The reason and refund details are shown below.',
        ['Booking reference'=>$reference, 'Service'=>$request['service_name'] ?? 'Booking service',
         'Scheduled date'=>$request['service_date'] ?? '', ($cancelledByHotel ? 'Cancellation reason' : 'Rejection reason')=>$reason,
         'Cancellation status'=>'Approved', 'Amount paid'=>'PHP '.number_format($paid,2),
         'Eligible refund'=>'PHP '.number_format($refund,2), 'Refund status'=>$refund>0?'Pending processing':'No payment to refund'],
        $notice);
    $plain = 'Booking '.$reference.($cancelledByHotel ? ' was cancelled by the property after acceptance.' : ' was rejected before acceptance.').' Reason: '.$reason.'. Cancellation: approved. Amount paid: PHP '.number_format($paid,2).'. Eligible refund: PHP '.number_format($refund,2).'. '.$notice;
    return ['subject'=>'iTour Mercedes | Booking '.($cancelledByHotel ? 'Cancelled' : 'Rejected').' ('.$reference.')','html'=>$html,'plain'=>$plain,'name'=>$name];
}

function sendBookingRejectedEmail(array $request, array $tourist): bool
{
    $content = BookingRejectionEmailContent($request, $tourist);
    return sendBookingWorkflowEmail((string)($tourist['email'] ?? ''), $content['name'], $content['subject'], $content['html'], $content['plain']);
}
