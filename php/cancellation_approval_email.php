<?php
declare(strict_types=1);

require_once __DIR__ . '/booking_confirmation_email.php';

function CancellationApprovalEmailBranding(object $mail): array
{
    return BookingConfirmationEmailBranding($mail);
}

function CancellationApprovalEmailTemplate(array $data): string
{
    $escape = static fn(mixed $value): string => BookingConfirmationEmailEscape($value);
    $guest = $escape($data['guest_name'] ?? 'Guest');
    $reference = $escape($data['booking_reference'] ?? '');
    $service = $escape($data['service_name'] ?? 'Booking service');
    $serviceType = $escape($data['booking_type'] ?? 'Booking');
    $serviceDate = $escape(BookingConfirmationEmailDate($data['service_date'] ?? ''));
    $requestedAt = $escape(BookingConfirmationEmailDate($data['requested_at'] ?? ''));
    $amountPaid = $escape(BookingConfirmationEmailMoney($data['amount_paid'] ?? 0));
    $refundAmount = max(0, (float)($data['refundable_amount'] ?? 0));
    $nonRefundable = $escape(BookingConfirmationEmailMoney($data['non_refundable_amount'] ?? 0));
    $refundDisplay = $escape(BookingConfirmationEmailMoney($refundAmount));
    $policy = $escape($data['policy_label'] ?? 'Reviewed under the cancellation policy');
    $eligible = $refundAmount > 0.009;
    $trackingUrl = $escape($data['tracking_url'] ?? '');
    $defaultLogoUrl = itourEmailAssetUrl('img/newlogo.png', 'BOOKING_EMAIL_LOGO_URL');
    $defaultWordmarkUrl = itourEmailAssetUrl('img/textlogo2-white.png', 'BOOKING_EMAIL_WORDMARK_URL');
    $logoUrl = $escape($data['logo_url'] ?? PaymentHelper::env('BOOKING_EMAIL_LOGO_URL', $defaultLogoUrl));
    $wordmarkUrl = $escape($data['wordmark_url'] ?? PaymentHelper::env('BOOKING_EMAIL_WORDMARK_URL', $defaultWordmarkUrl));
    $year = date('Y');

    $refundMessage = $eligible
        ? 'You are eligible for a refund of <strong style="color:#126447;">' . $refundDisplay . '</strong>. Your refund is now queued for processing. You can track its progress from your tourist account.'
        : 'This cancellation is <strong style="color:#a43642;">not eligible for a monetary refund</strong> under the applicable policy. No refund transaction is required.';
    $refundBadge = $eligible ? 'REFUND PROCESSING' : 'NO REFUND DUE';
    $refundBadgeStyle = $eligible
        ? 'background:#dff3ea;color:#176148;'
        : 'background:#fde9eb;color:#9f3340;';

    return '<!doctype html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Cancellation Approved</title></head>
<body style="margin:0;padding:0;background:#eef4f1;font-family:Arial,Helvetica,sans-serif;color:#183b31;">
<div style="display:none;max-height:0;overflow:hidden;opacity:0;color:transparent;">Your booking cancellation has been approved.</div>
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="width:100%;background:#eef4f1;"><tr><td align="center" style="padding:28px 12px;">
<table role="presentation" width="640" cellspacing="0" cellpadding="0" border="0" style="width:100%;max-width:640px;background:#fff;border:1px solid #d8e6e0;border-radius:16px;overflow:hidden;box-shadow:0 10px 30px rgba(18,72,55,.08);">
<tr><td style="padding:22px 28px;background:#0d4938;"><table role="presentation" width="100%"><tr><td><table role="presentation"><tr><td width="62"><img src="' . $logoUrl . '" width="52" height="52" alt="iTour Mercedes seal" style="display:block;width:52px;height:52px;object-fit:contain;border:0;"></td><td style="padding-left:14px;"><img src="' . $wordmarkUrl . '" width="205" alt="iTour Mercedes" style="display:block;width:205px;max-width:100%;height:auto;border:0;"></td></tr></table></td><td align="right" style="color:#cce4db;font-size:10px;font-weight:700;letter-spacing:1.1px;text-transform:uppercase;white-space:nowrap;">Cancellation update</td></tr></table></td></tr>
<tr><td style="padding:30px 30px 22px;text-align:center;background:#f5faf8;border-bottom:1px solid #deebe6;"><div style="display:inline-block;padding:7px 13px;border-radius:999px;background:#dff3ea;color:#176148;font-size:11px;font-weight:800;letter-spacing:.8px;">CANCELLATION APPROVED</div><h1 style="margin:15px 0 7px;color:#123f31;font-size:26px;line-height:1.2;">Your booking is cancelled</h1><p style="margin:0;color:#63766f;font-size:14px;line-height:1.6;">We have approved your cancellation request and updated your booking.</p><div style="margin-top:17px;color:#6a7e76;font-size:12px;">Booking reference<br><strong style="display:inline-block;margin-top:4px;color:#174e3d;font-size:16px;letter-spacing:.8px;">' . $reference . '</strong></div></td></tr>
<tr><td style="padding:26px 30px 8px;"><p style="margin:0 0 10px;color:#314f45;font-size:14px;line-height:1.65;">Dear <strong>' . $guest . '</strong>,</p><p style="margin:0 0 18px;color:#314f45;font-size:14px;line-height:1.65;text-align:justify;text-indent:28px;">Your cancellation request has been approved. The reservation is no longer active and the cancelled service will not proceed.</p><h2 style="margin:0;padding:0 0 10px;color:#174e3d;font-size:14px;text-transform:uppercase;letter-spacing:.8px;">Cancellation details</h2><table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="width:100%;border:1px solid #dce8e3;border-radius:10px;border-collapse:separate;border-spacing:0;overflow:hidden;background:#fbfdfc;">' . BookingConfirmationEmailRows([
        'Booking type' => $serviceType,
        'Service' => $service,
        'Service date' => $serviceDate,
        'Cancellation requested' => $requestedAt,
        'Request status' => 'Approved',
    ]) . '</table></td></tr>
<tr><td style="padding:18px 30px 8px;"><div style="margin-bottom:11px;"><span style="display:inline-block;padding:7px 12px;border-radius:999px;' . $refundBadgeStyle . 'font-size:10px;font-weight:800;letter-spacing:.6px;">' . $refundBadge . '</span></div><p style="margin:0 0 14px;color:#314f45;font-size:14px;line-height:1.65;text-align:justify;text-indent:28px;">' . $refundMessage . '</p><table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="width:100%;border:1px solid #dce8e3;border-radius:10px;border-collapse:separate;border-spacing:0;overflow:hidden;background:#fbfdfc;">' . BookingConfirmationEmailRows([
        'Amount paid' => $amountPaid,
        'Eligible refund' => $refundDisplay,
        'Non-refundable amount' => $nonRefundable,
        'Eligibility decision' => $policy,
        'Current refund status' => $eligible ? 'Processing' : 'No refund due',
    ]) . '</table></td></tr>
<tr><td style="padding:22px 30px 28px;"><table role="presentation" width="100%" style="border-left:4px solid #d99a25;border-radius:8px;background:#fff8e8;"><tr><td style="padding:14px 16px;color:#654b19;font-size:13px;line-height:1.55;"><strong style="display:block;margin-bottom:3px;color:#5b4214;">Track your cancellation</strong>Visit your tourist profile to follow the approval and refund status. Refund posting times may depend on the payment provider.</td></tr></table>' . ($trackingUrl !== '' ? '<div style="padding-top:20px;text-align:center;"><a href="' . $trackingUrl . '" style="display:inline-block;padding:12px 20px;border-radius:9px;background:#216d57;color:#fff;font-size:13px;font-weight:700;text-decoration:none;">Track Cancellation Progress</a><p style="margin:10px 0 0;color:#788981;font-size:10px;line-height:1.5;word-break:break-all;">' . $trackingUrl . '</p></div>' : '') . '<p style="margin:21px 0 0;color:#61746d;font-size:13px;line-height:1.6;">Need help? Reply to this email and the iTour Mercedes team will assist you.</p></td></tr>
<tr><td style="padding:18px 26px;background:#f0f5f3;border-top:1px solid #dce7e3;text-align:center;color:#778780;font-size:11px;line-height:1.6;">Thank you for choosing <strong style="color:#31594d;">iTour Mercedes</strong>.<br>&copy; ' . $year . ' iTour Mercedes. All rights reserved.</td></tr>
</table></td></tr></table></body></html>';
}
