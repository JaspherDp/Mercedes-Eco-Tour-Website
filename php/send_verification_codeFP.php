<?php
// ---------------------------------------
// Forgot Password: Send & Verify Code - JSON API
// ---------------------------------------
    require_once __DIR__ . '/session_security.php';
    AppSessionStart();
header('Content-Type: application/json');

// Keep diagnostics in the private server log, never in API responses.
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

require_once 'db_connection.php';
require_once __DIR__ . '/request_rate_limiter.php';
require_once __DIR__ . '/turnstile.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require 'PHPMailer/src/PHPMailer.php';
require 'PHPMailer/src/SMTP.php';
require 'PHPMailer/src/Exception.php';
require_once __DIR__ . '/auth_email_template.php';
require_once __DIR__ . '/../payments/PaymentHelper.php';

try {
    $action = $_POST['action'] ?? '';
    $email  = trim($_POST['email'] ?? '');
    $code   = trim($_POST['code'] ?? '');

    if (!$email) {
        echo json_encode(['status'=>'error','message'=>'Email is required']);
        exit;
    }

    // --- Send code ---
    if ($action === 'send_code') {
        if (!ItourTurnstileRequestPassed()) {
            http_response_code(403);
            echo json_encode(['status' => 'error', 'message' => ITOUR_TURNSTILE_ERROR]);
            exit;
        }

        $forgotLimit = requestRateLimitConsume($pdo, 'forgot_password_request', requestRateLimitClientIp(), 3, 900);
        if (!$forgotLimit['allowed']) {
            requestRateLimitReject($forgotLimit);
        }

        $stmt = $pdo->prepare("SELECT tourist_id, full_name FROM tourist WHERE email = ? LIMIT 1");
        $stmt->execute([$email]);
        $touristAccount = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$touristAccount) {
            echo json_encode(['status'=>'error','message'=>'Email not found']);
            exit;
        }

        $verification_code = rand(100000, 999999);

        // Store code in session
        $_SESSION['forgot_email'] = $email;
        $_SESSION['forgot_code'] = $verification_code;
        $_SESSION['forgot_code_time'] = time(); // 10 minutes
        unset($_SESSION['forgot_verified_at']);

        // Optional: save to DB
        $stmt = $pdo->prepare("UPDATE tourist SET verification_code=? WHERE email=?");
        $stmt->execute([$verification_code, $email]);

        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = 'itourmercedes@gmail.com';
        $mail->Password   = PaymentHelper::env('SMTP_PASSWORD');
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;

        $mail->SMTPOptions = [
            'ssl'=> [
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true
            ]
        ];

        $mail->CharSet = 'UTF-8';
        $mail->Encoding = 'base64';
        $mail->setFrom($mail->Username, 'iTour Mercedes');
        $mail->addReplyTo($mail->Username, 'iTour Mercedes Support');
        $mail->addAddress($email);
        $mail->isHTML(true);
        $resetEmail = itourBuildVerificationEmail(
            'password_reset',
            (string)$verification_code,
            trim((string)($touristAccount['full_name'] ?? ''))
        );
        $mail->Subject = $resetEmail['subject'];
        $mail->Body = $resetEmail['html'];
        $mail->AltBody = $resetEmail['text'];

        $mail->send();

        echo json_encode(['status'=>'success','message'=>'Verification code sent! Check your email.']);
        exit;
    }

    // --- Verify code ---
    if ($action === 'verify_code') {
        if (
            isset($_SESSION['forgot_email'], $_SESSION['forgot_code'], $_SESSION['forgot_code_time']) &&
            $_SESSION['forgot_email'] === $email &&
            $_SESSION['forgot_code'] == $code &&
            (time() - $_SESSION['forgot_code_time'] <= 600)
        ) {
            $_SESSION['forgot_verified_at'] = time();
            echo json_encode(['status'=>'success','valid'=>true]);
        } else {
            echo json_encode(['status'=>'error','valid'=>false,'message'=>'Invalid or expired code']);
        }
        exit;
    }

    echo json_encode(['status'=>'error','message'=>'Invalid action']);
    exit;

} catch (Exception $e) {
    error_log('Forgot-password verification failed with ' . get_class($e) . '.');
    echo json_encode(['status'=>'error','message'=>'Server error. Please try again.']);
    exit;
}
