<?php
declare(strict_types=1);

// Run on localhost or the hosting server: php scripts/cancellation_email_diagnostic.php
// Connects and authenticates only. No recipients, email, or booking updates.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../php/provider_cancellation_email.php';

$mail = new PHPMailer\PHPMailer\PHPMailer(true);
try {
    bookingWorkflowConfigureSmtp($mail);
    $mail->Timeout = 15;
    echo 'Private environment: ', PaymentHelper::environmentPath() !== null ? 'found' : 'not found (process variables only)', PHP_EOL;
    echo 'SMTP host: ', $mail->Host, ':', $mail->Port, PHP_EOL;
    echo 'SMTP username: ', $mail->Username !== '' ? 'present' : 'missing', PHP_EOL;
    echo 'SMTP password: ', $mail->Password !== '' ? 'present' : 'missing', PHP_EOL;
    echo 'CA trust: ', $mail->SMTPOptions['ssl']['cafile'] ?? 'PHP/system certificate store', PHP_EOL;
    if ($mail->Password === '') throw new RuntimeException('SMTP_PASSWORD is missing.');
    if (!$mail->smtpConnect()) throw new RuntimeException($mail->ErrorInfo ?: 'SMTP connection failed.');
    echo 'PASS: TLS verification and SMTP authentication succeeded. No email sent.', PHP_EOL;
    $mail->smtpClose();
} catch (Throwable $error) {
    $mail->smtpClose();
    fwrite(STDERR, 'FAIL: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
