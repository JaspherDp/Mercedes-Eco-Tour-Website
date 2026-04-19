<?php
// ---------------------------------------
// Forgot Password: Send & Verify Code - JSON API
// ---------------------------------------
session_start();
header('Content-Type: application/json');

// --- Show errors for debugging ---
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once 'db_connection.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require 'PHPMailer/src/PHPMailer.php';
require 'PHPMailer/src/SMTP.php';
require 'PHPMailer/src/Exception.php';

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
        $stmt = $pdo->prepare("SELECT tourist_id FROM tourist WHERE email = ?");
        $stmt->execute([$email]);
        if ($stmt->rowCount() === 0) {
            echo json_encode(['status'=>'error','message'=>'Email not found']);
            exit;
        }

        $verification_code = rand(100000, 999999);

        // Store code in session
        $_SESSION['forgot_email'] = $email;
        $_SESSION['forgot_code'] = $verification_code;
        $_SESSION['forgot_code_time'] = time(); // 10 minutes

        // Optional: save to DB
        $stmt = $pdo->prepare("UPDATE tourist SET verification_code=? WHERE email=?");
        $stmt->execute([$verification_code, $email]);

        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = 'johnjaspherdelapacion29@gmail.com';
        $mail->Password   = 'jbvx nqwv hlgm vlls';
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;

        $mail->SMTPOptions = [
            'ssl'=> [
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true
            ]
        ];

        $mail->setFrom('johnjaspherdelapacion29@gmail.com','iTour Mercedes');
        $mail->addAddress($email);
        $mail->Subject = 'iTour Mercedes - Forgot Password Verification Code';
        $mail->Body = "Your verification code is: $verification_code";

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
            echo json_encode(['status'=>'success','valid'=>true]);
        } else {
            echo json_encode(['status'=>'error','valid'=>false,'message'=>'Invalid or expired code']);
        }
        exit;
    }

    echo json_encode(['status'=>'error','message'=>'Invalid action']);
    exit;

} catch (Exception $e) {
    echo json_encode(['status'=>'error','message'=>'Server error: '.$e->getMessage()]);
    exit;
}
