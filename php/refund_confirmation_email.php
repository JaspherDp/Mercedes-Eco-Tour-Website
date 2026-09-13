<?php
declare(strict_types=1);

require_once __DIR__ . '/booking_confirmation_email.php';

function RefundConfirmationEmailBranding(object $mail): array
{
    return BookingConfirmationEmailBranding($mail);
}

function RefundConfirmationEmailTemplate(array $data): string
{
    $e = static fn(mixed $value): string => BookingConfirmationEmailEscape($value);
    $guest = $e($data['guest_name'] ?? 'Guest');
    $reference = $e($data['booking_reference'] ?? '');
    $amount = $e(BookingConfirmationEmailMoney($data['amount'] ?? 0));
    $method = $e($data['method'] ?? 'Original payment method');
    $destination = $e($data['destination'] ?? $method);
    $providerReference = $e($data['provider_refund_id'] ?? 'Pending assignment');
    $timeline = $e($data['timeline'] ?? 'Depends on the payment provider');
    $timelineDetail = $e($data['timeline_detail'] ?? 'Posting time depends on the original payment channel.');
    $trackingUrl = $e($data['tracking_url'] ?? '');
    $claimUrlRaw = trim((string)($data['claim_url'] ?? ''));
    $claimUrl = filter_var($claimUrlRaw, FILTER_VALIDATE_URL) ? $e($claimUrlRaw) : '';
    $manualCompleted = !empty($data['manual_completed']);
    $logoUrl = $e($data['logo_url'] ?? itourEmailAssetUrl('img/newlogo.png', 'BOOKING_EMAIL_LOGO_URL'));
    $wordmarkUrl = $e($data['wordmark_url'] ?? itourEmailAssetUrl('img/textlogo2-white.png', 'BOOKING_EMAIL_WORDMARK_URL'));
    $year = date('Y');

    return '<!doctype html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Refund Processing</title></head>
<body style="margin:0;padding:0;background:#eef4f1;font-family:Arial,Helvetica,sans-serif;color:#183b31;">
<div style="display:none;max-height:0;overflow:hidden;opacity:0;color:transparent;">' . ($manualCompleted ? 'Your refund transfer has been recorded as completed.' : 'Your approved refund has been submitted for processing.') . '</div>
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="width:100%;background:#eef4f1;"><tr><td align="center" style="padding:28px 12px;">
<table role="presentation" width="640" cellspacing="0" cellpadding="0" border="0" style="width:100%;max-width:640px;background:#fff;border:1px solid #d8e6e0;border-radius:16px;overflow:hidden;box-shadow:0 10px 30px rgba(18,72,55,.08);">
<tr><td style="padding:22px 28px;background:#0d4938;"><table role="presentation" width="100%"><tr><td><table role="presentation"><tr><td width="62"><img src="' . $logoUrl . '" width="52" height="52" alt="iTour Mercedes seal" style="display:block;width:52px;height:52px;object-fit:contain;border:0;"></td><td style="padding-left:14px;"><img src="' . $wordmarkUrl . '" width="205" alt="iTour Mercedes" style="display:block;width:205px;max-width:100%;height:auto;border:0;"></td></tr></table></td><td align="right" style="color:#cce4db;font-size:10px;font-weight:700;letter-spacing:1.1px;text-transform:uppercase;white-space:nowrap;">Refund update</td></tr></table></td></tr>
<tr><td style="padding:30px;text-align:center;background:#f5faf8;border-bottom:1px solid #deebe6;"><div style="display:inline-block;padding:7px 13px;border-radius:999px;background:#dff3ea;color:#176148;font-size:11px;font-weight:800;letter-spacing:.8px;">' . ($manualCompleted ? 'REFUND SENT' : 'REFUND APPROVED &amp; PROCESSING') . '</div><h1 style="margin:15px 0 7px;color:#123f31;font-size:26px;line-height:1.2;">' . ($manualCompleted ? 'Your refund was sent' : 'Your refund was submitted') . '</h1><p style="margin:0;color:#63766f;font-size:14px;line-height:1.6;">' . ($manualCompleted ? 'The administrator recorded the transfer to your verified refund account.' : ($claimUrl !== '' ? 'Use the secure PayMongo link below to claim your QR Ph refund.' : 'The funds are being returned through your original payment channel.')) . '</p><div style="margin-top:17px;color:#6a7e76;font-size:12px;">Booking reference<br><strong style="display:inline-block;margin-top:4px;color:#174e3d;font-size:16px;letter-spacing:.8px;">' . $reference . '</strong></div></td></tr>
<tr><td style="padding:26px 30px 8px;"><p style="margin:0 0 10px;color:#314f45;font-size:14px;line-height:1.65;">Dear <strong>' . $guest . '</strong>,</p><p style="margin:0 0 18px;color:#314f45;font-size:14px;line-height:1.65;text-align:justify;text-indent:28px;">' . ($manualCompleted ? 'We recorded your approved refund of <strong style="color:#126447;">' . $amount . '</strong> as sent to your verified refund account.' : 'We approved and submitted your refund of <strong style="color:#126447;">' . $amount . '</strong>.' . ($claimUrl !== '' ? ' PayMongo requires you to choose the bank or e-wallet that will receive this QR Ph refund.' : ' PayMongo will return it to the same account or payment method used for the original transaction.')) . '</p><h2 style="margin:0;padding:0 0 10px;color:#174e3d;font-size:14px;text-transform:uppercase;letter-spacing:.8px;">Refund details</h2><table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="width:100%;border:1px solid #dce8e3;border-radius:10px;border-collapse:separate;border-spacing:0;overflow:hidden;background:#fbfdfc;">' . BookingConfirmationEmailRows([
        'Refund amount' => $amount,
        $manualCompleted ? 'Refund sent through' : 'Original payment channel' => $method,
        'Refund destination' => $destination,
        'Provider refund reference' => $providerReference,
        'Estimated posting time' => $timeline,
        'Current status' => $manualCompleted ? 'Sent' : ($claimUrl !== '' ? 'Waiting for your claim' : 'Processing'),
    ]) . '</table></td></tr>
' . ($claimUrl !== '' ? '<tr><td style="padding:18px 30px 4px;text-align:center;"><table role="presentation" width="100%" style="border:1px solid #b9dfd1;border-radius:10px;background:#edf9f4;"><tr><td style="padding:18px;color:#315b4e;font-size:13px;line-height:1.55;"><strong style="display:block;margin-bottom:6px;color:#164f3e;">Claim your QR Ph refund within 3 days</strong>Select the bank or e-wallet where PayMongo should send the refund.<div style="padding-top:14px;"><a href="' . $claimUrl . '" style="display:inline-block;padding:12px 20px;border-radius:9px;background:#176b58;color:#fff;font-weight:700;text-decoration:none;">Claim Refund Securely</a></div></td></tr></table></td></tr>' : '') . '
<tr><td style="padding:20px 30px 8px;"><table role="presentation" width="100%" style="border-left:4px solid #d99a25;border-radius:8px;background:#fff8e8;"><tr><td style="padding:14px 16px;color:#654b19;font-size:13px;line-height:1.55;"><strong style="display:block;margin-bottom:3px;color:#5b4214;">When will the money appear?</strong>' . $timelineDetail . ' The receiving bank or wallet may take additional time to display the credit. Please keep the provider reference above.</td></tr></table></td></tr>
<tr><td style="padding:20px 30px 28px;"><p style="margin:0;color:#61746d;font-size:13px;line-height:1.6;text-align:justify;text-indent:28px;">' . ($claimUrl !== '' ? 'Complete the secure PayMongo claim form before the link expires. After you claim it, PayMongo will update the refund status automatically.' : 'If the refund has not appeared after the stated period, contact your bank or e-wallet first, then reply to this email so our team can assist you.') . '</p>' . ($trackingUrl !== '' ? '<div style="padding-top:20px;text-align:center;"><a href="' . $trackingUrl . '" style="display:inline-block;padding:12px 20px;border-radius:9px;background:#216d57;color:#fff;font-size:13px;font-weight:700;text-decoration:none;">Track Refund Progress</a><p style="margin:10px 0 0;color:#788981;font-size:10px;line-height:1.5;word-break:break-all;">' . $trackingUrl . '</p></div>' : '') . '</td></tr>
<tr><td style="padding:18px 26px;background:#f0f5f3;border-top:1px solid #dce7e3;text-align:center;color:#778780;font-size:11px;line-height:1.6;">Thank you for choosing <strong style="color:#31594d;">iTour Mercedes</strong>.<br>&copy; ' . $year . ' iTour Mercedes. All rights reserved.</td></tr>
</table></td></tr></table></body></html>';
}
