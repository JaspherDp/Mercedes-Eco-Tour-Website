<?php
session_start();
require 'db_connection.php'; // Connects $pdo to db_itourmercedes

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require 'PHPMailer/src/PHPMailer.php';
require 'PHPMailer/src/SMTP.php';
require 'PHPMailer/src/Exception.php';

header('Content-Type: application/json');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Invalid request method');
    }

    $fname    = trim($_POST['fname'] ?? '');
    $lname    = trim($_POST['lname'] ?? '');
    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm  = $_POST['confirm'] ?? '';
    $code     = $_POST['code'] ?? '';
    $action   = $_POST['action'] ?? '';

    if (!$email) {
        echo json_encode(['status' => 'error', 'title' => 'Missing Email', 'message' => 'Email is required']);
        exit;
    }

    // SEND VERIFICATION CODE
    if ($action === 'send_code') {
        $stmt = $pdo->prepare("SELECT 1 FROM tourist WHERE email = ?");
        $stmt->execute([$email]);
        if ($stmt->rowCount() > 0) {
            echo json_encode(['status' => 'error', 'title' => 'Email Exists', 'message' => 'Email already registered']);
            exit;
        }

        $verification_code = rand(100000, 999999);

        $_SESSION['verification_code'] = $verification_code;
        $_SESSION['signup_email']      = $email;
        $_SESSION['signup_fname']      = $fname;
        $_SESSION['signup_lname']      = $lname;

        $mail = new PHPMailer(true);
        try {
            $mail->isSMTP();
            $mail->Host       = 'smtp.gmail.com';
            $mail->SMTPAuth   = true;
            $mail->Username   = 'johnjaspherdelapacion29@gmail.com';
            $mail->Password   = 'jbvx nqwv hlgm vlls';
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port       = 587;

            $mail->SMTPOptions = [
                'ssl' => [
                    'verify_peer'       => false,
                    'verify_peer_name'  => false,
                    'allow_self_signed' => true,
                ],
            ];

            $mail->setFrom('johnjaspherdelapacion29@gmail.com', 'iTour Mercedes');
            $mail->addAddress($email);
            $mail->Subject = 'iTour Mercedes - Verification Code';
            $mail->Body    = "Your verification code is: $verification_code";
            $mail->send();

            echo json_encode([
                'status'  => 'success',
                'title'   => 'Verification Sent',
                'message' => 'Check your email for the 6-digit verification code.'
            ]);
            exit;
        } catch (Exception $e) {
            echo json_encode([
                'status'  => 'error',
                'title'   => 'Email Failed',
                'message' => 'Failed to send verification email: ' . $mail->ErrorInfo
            ]);
            exit;
        }
    }

    // VERIFY CODE AND SIGN UP
    if ($action === 'verify_code') {
        if (!$fname || !$lname || !$password || !$confirm || !$code) {
            echo json_encode(['status' => 'error', 'title' => 'Incomplete Fields', 'message' => 'All fields are required for signup']);
            exit;
        }

        if ($password !== $confirm) {
            echo json_encode(['status' => 'error', 'title' => 'Password Mismatch', 'message' => 'Passwords do not match']);
            exit;
        }

        if (
            !isset($_SESSION['verification_code']) ||
            $code != $_SESSION['verification_code'] ||
            $email != $_SESSION['signup_email']
        ) {
            echo json_encode(['status' => 'error', 'title' => 'Invalid Code', 'message' => 'Invalid verification code']);
            exit;
        }

        $full_name = trim($fname . ' ' . $lname);

        $stmt = $pdo->prepare("SELECT 1 FROM tourist WHERE email = ?");
        $stmt->execute([$email]);
        if ($stmt->rowCount() > 0) {
            echo json_encode(['status' => 'error', 'title' => 'Email Exists', 'message' => 'Email already registered']);
            exit;
        }

        $password_hash = password_hash($password, PASSWORD_BCRYPT);

        $stmt = $pdo->prepare("INSERT INTO tourist (full_name, email, password_hash, email_verified) VALUES (?, ?, ?, 1)");
        $stmt->execute([$full_name, $email, $password_hash]);

        $userId = $pdo->lastInsertId();

        $stmt = $pdo->prepare("SELECT * FROM tourist WHERE tourist_id = ?");
        $stmt->execute([$userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        $_SESSION['tourist_id']    = $user['tourist_id'];
        $_SESSION['tourist_email'] = $user['email'];
        $_SESSION['full_name']     = $user['full_name'];

        $nameParts = explode(" ", $user['full_name'], 2);
        $_SESSION['first_name'] = $nameParts[0];
        $_SESSION['last_name']  = isset($nameParts[1]) ? $nameParts[1] : "";

        unset($_SESSION['verification_code'], $_SESSION['signup_email'], $_SESSION['signup_fname'], $_SESSION['signup_lname']);

        echo json_encode([
            'status'  => 'success',
            'title'   => 'Signup Successful',
            'message' => 'Welcome! You have successfully signed up.',
            'user'    => [
                'id'         => $_SESSION['tourist_id'],
                'email'      => $_SESSION['tourist_email'],
                'full_name'  => $_SESSION['full_name'],
                'first_name' => $_SESSION['first_name'],
                'last_name'  => $_SESSION['last_name']
            ]
        ]);
        exit;
    }

    throw new Exception('Invalid action');

} catch (Exception $e) {
    echo json_encode([
        'status'  => 'error',
        'title'   => 'Server Error',
        'message' => 'Server error: ' . $e->getMessage()
    ]);
}
