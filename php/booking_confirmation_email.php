<?php
declare(strict_types=1);

require_once __DIR__ . '/../payments/PaymentHelper.php';
require_once __DIR__ . '/email_branding_helper.php';

function BookingConfirmationEmailEscape(mixed $value): string
{
    return htmlspecialchars(trim((string)$value), ENT_QUOTES, 'UTF-8');
}

function BookingConfirmationEmailDate(mixed $value): string
{
    $value = trim((string)$value);
    if ($value === '') return 'Not specified';
    $timestamp = strtotime($value);
    return $timestamp ? date('F j, Y', $timestamp) : $value;
}

function BookingConfirmationEmailMoney(mixed $value): string
{
    return '₱' . number_format(max(0, (float)$value), 2);
}

function BookingConfirmationEmailBranding(object $mail): array
{
    return [
        'logo_url' => itourEmailAssetUrl('img/newlogo.png', 'BOOKING_EMAIL_LOGO_URL'),
        'wordmark_url' => itourEmailAssetUrl('img/textlogo2-white.png', 'BOOKING_EMAIL_WORDMARK_URL'),
    ];
}

function BookingConfirmationEnsureTourEmailTracking(PDO $pdo): void
{
    $columns = $pdo->query(
        "SELECT COLUMN_NAME FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bookings'
           AND COLUMN_NAME IN (
             'confirmation_email_status', 'confirmation_email_sent_at',
             'confirmation_email_last_attempt_at', 'confirmation_email_error'
           )"
    )->fetchAll(PDO::FETCH_COLUMN) ?: [];
    $existing = array_fill_keys(array_map('strval', $columns), true);
    $isNewTrackingInstallation = !isset($existing['confirmation_email_status']);
    $definitions = [
        'confirmation_email_status' => "ADD COLUMN confirmation_email_status VARCHAR(20) NOT NULL DEFAULT 'not_sent' AFTER status",
        'confirmation_email_sent_at' => 'ADD COLUMN confirmation_email_sent_at DATETIME NULL AFTER confirmation_email_status',
        'confirmation_email_last_attempt_at' => 'ADD COLUMN confirmation_email_last_attempt_at DATETIME NULL AFTER confirmation_email_sent_at',
        'confirmation_email_error' => 'ADD COLUMN confirmation_email_error VARCHAR(500) NULL AFTER confirmation_email_last_attempt_at',
    ];

    foreach ($definitions as $column => $definition) {
        if (!isset($existing[$column])) {
            $pdo->exec('ALTER TABLE bookings ' . $definition);
        }
    }

    // Accepted bookings that predate delivery tracking are historical/unknown,
    // not confirmed failures. Preserve the old successful state and only expose
    // retry for failures recorded by an actual send attempt.
    if ($isNewTrackingInstallation) {
        $pdo->exec("
            UPDATE bookings
            SET confirmation_email_status = 'sent',
                confirmation_email_sent_at = COALESCE(confirmation_email_sent_at, updated_at)
            WHERE status = 'accepted'
              AND confirmation_email_status = 'not_sent'
              AND confirmation_email_last_attempt_at IS NULL
        ");
    }
}

function BookingConfirmationEmailRows(array $rows): string
{
    $html = '';
    foreach ($rows as $label => $value) {
        $value = trim((string)$value);
        if ($value === '') continue;
        $html .= '<tr>'
            . '<td style="width:39%;padding:11px 14px;border-bottom:1px solid #e4ece8;color:#6b7e77;font-size:13px;vertical-align:top;">'
            . BookingConfirmationEmailEscape($label) . '</td>'
            . '<td style="padding:11px 14px;border-bottom:1px solid #e4ece8;color:#183b31;font-size:13px;font-weight:700;line-height:1.45;vertical-align:top;">'
            . BookingConfirmationEmailEscape($value) . '</td>'
            . '</tr>';
    }
    return $html;
}

function BookingConfirmationEmailTemplate(array $data): string
{
    $guestName = BookingConfirmationEmailEscape($data['guest_name'] ?? 'Guest');
    $serviceLabel = BookingConfirmationEmailEscape($data['service_label'] ?? 'Booking');
    $reference = BookingConfirmationEmailEscape($data['booking_reference'] ?? '');
    $intro = BookingConfirmationEmailEscape(
        $data['intro'] ?? 'Your reservation has been reviewed and confirmed.'
    );
    $importantNote = BookingConfirmationEmailEscape(
        $data['important_note'] ?? 'Please keep this email available and arrive before your scheduled time.'
    );
    $detailRows = BookingConfirmationEmailRows((array)($data['details'] ?? []));
    $paymentRows = BookingConfirmationEmailRows((array)($data['payment_details'] ?? []));
    $defaultLogoUrl = itourEmailAssetUrl('img/newlogo.png', 'BOOKING_EMAIL_LOGO_URL');
    $defaultWordmarkUrl = itourEmailAssetUrl('img/textlogo2-white.png', 'BOOKING_EMAIL_WORDMARK_URL');
    $logoUrl = BookingConfirmationEmailEscape(
        $data['logo_url'] ?? PaymentHelper::env('BOOKING_EMAIL_LOGO_URL', $defaultLogoUrl)
    );
    $wordmarkUrl = BookingConfirmationEmailEscape(
        $data['wordmark_url'] ?? PaymentHelper::env('BOOKING_EMAIL_WORDMARK_URL', $defaultWordmarkUrl)
    );
    $year = date('Y');

    return '<!doctype html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>iTour Mercedes Booking Confirmation</title>
</head>
<body style="margin:0;padding:0;background:#eef4f1;font-family:Arial,Helvetica,sans-serif;color:#183b31;">
  <div style="display:none;max-height:0;overflow:hidden;opacity:0;color:transparent;">Your iTour Mercedes ' . $serviceLabel . ' is confirmed.</div>
  <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="width:100%;background:#eef4f1;">
    <tr><td align="center" style="padding:28px 12px;">
      <table role="presentation" width="640" cellspacing="0" cellpadding="0" border="0" style="width:100%;max-width:640px;background:#ffffff;border:1px solid #d8e6e0;border-radius:16px;overflow:hidden;box-shadow:0 10px 30px rgba(18,72,55,.08);">
        <tr>
          <td style="padding:22px 28px;background:#0d4938;">
            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="width:100%;">
              <tr>
                <td align="left" valign="middle" style="vertical-align:middle;">
                  <table role="presentation" align="left" cellspacing="0" cellpadding="0" border="0">
                    <tr>
                      <td width="62" align="left" valign="middle" style="width:62px;vertical-align:middle;"><img src="' . $logoUrl . '" width="52" height="52" alt="iTour Mercedes seal" style="display:block;width:52px;height:52px;object-fit:contain;border:0;"></td>
                      <td align="left" valign="middle" style="padding-left:14px;vertical-align:middle;"><img src="' . $wordmarkUrl . '" width="205" alt="iTour Mercedes" style="display:block;width:205px;max-width:100%;height:auto;border:0;background:transparent;"></td>
                    </tr>
                  </table>
                </td>
                <td align="right" valign="middle" style="padding-left:20px;color:#cce4db;font-size:10px;font-weight:700;line-height:1.4;letter-spacing:1.1px;text-align:right;text-transform:uppercase;vertical-align:middle;white-space:nowrap;">Official booking confirmation</td>
              </tr>
            </table>
          </td>
        </tr>
        <tr>
          <td style="padding:30px 30px 22px;text-align:center;background:#f5faf8;border-bottom:1px solid #deebe6;">
            <div style="display:inline-block;padding:7px 13px;border-radius:999px;background:#dff3ea;color:#176148;font-size:11px;font-weight:800;letter-spacing:.8px;">CONFIRMED</div>
            <h1 style="margin:15px 0 7px;color:#123f31;font-size:26px;line-height:1.2;">' . $serviceLabel . ' Confirmed</h1>
            <p style="margin:0;color:#63766f;font-size:14px;line-height:1.6;">' . $intro . '</p>
            ' . ($reference !== '' ? '<div style="margin-top:17px;color:#6a7e76;font-size:12px;">Booking reference<br><strong style="display:inline-block;margin-top:4px;color:#174e3d;font-size:16px;letter-spacing:.8px;">' . $reference . '</strong></div>' : '') . '
          </td>
        </tr>
        <tr>
          <td style="padding:26px 30px 8px;">
            <p style="margin:0 0 8px;color:#314f45;font-size:14px;line-height:1.65;">Dear <strong>' . $guestName . '</strong>,</p>
            <p style="margin:0 0 17px;color:#314f45;font-size:14px;line-height:1.65;text-indent:28px;">We are pleased to confirm your reservation. Please review the information below before your scheduled booking.</p>
            <h2 style="margin:0;padding:0 0 10px;color:#174e3d;font-size:14px;text-transform:uppercase;letter-spacing:.8px;">Reservation details</h2>
            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="width:100%;border:1px solid #dce8e3;border-radius:10px;border-collapse:separate;border-spacing:0;overflow:hidden;background:#fbfdfc;">' . $detailRows . '</table>
          </td>
        </tr>
        ' . ($paymentRows !== '' ? '<tr><td style="padding:18px 30px 8px;"><h2 style="margin:0;padding:0 0 10px;color:#174e3d;font-size:14px;text-transform:uppercase;letter-spacing:.8px;">Payment summary</h2><table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="width:100%;border:1px solid #dce8e3;border-radius:10px;border-collapse:separate;border-spacing:0;overflow:hidden;background:#fbfdfc;">' . $paymentRows . '</table></td></tr>' : '') . '
        <tr>
          <td style="padding:20px 30px 28px;">
            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="width:100%;border-left:4px solid #d99a25;border-radius:8px;background:#fff8e8;">
              <tr><td style="padding:14px 16px;color:#654b19;font-size:13px;line-height:1.55;"><strong style="display:block;margin-bottom:3px;color:#5b4214;">Important reminder</strong>' . $importantNote . '</td></tr>
            </table>
            <p style="margin:21px 0 0;color:#61746d;font-size:13px;line-height:1.6;">Need help with your reservation? Reply to this email and the iTour Mercedes team will assist you.</p>
          </td>
        </tr>
        <tr>
          <td style="padding:18px 26px;background:#f0f5f3;border-top:1px solid #dce7e3;text-align:center;color:#778780;font-size:11px;line-height:1.6;">Thank you for choosing <strong style="color:#31594d;">iTour Mercedes</strong>.<br>&copy; ' . $year . ' iTour Mercedes. All rights reserved.</td>
        </tr>
      </table>
    </td></tr>
  </table>
</body>
</html>';
}

function BookingConfirmationEmailSend(object $mail): bool
{
    for ($attempt = 1; $attempt <= 2; $attempt++) {
        try {
            if ($mail->send()) return true;
        } catch (Throwable $error) {
            $message = $error->getMessage() . ' ' . (string)($mail->ErrorInfo ?? '');
            $connectionFailure = stripos($message, 'connect') !== false
                || stripos($message, 'timed out') !== false;
            if (!$connectionFailure || $attempt === 2) {
                error_log('Booking confirmation email failed: ' . $message);
                return false;
            }
        }

        if ($attempt < 2) {
            if (method_exists($mail, 'smtpClose')) $mail->smtpClose();
            usleep(400000);
        }
    }

    error_log('Booking confirmation email failed: ' . (string)($mail->ErrorInfo ?? 'Unknown SMTP error'));
    return false;
}
