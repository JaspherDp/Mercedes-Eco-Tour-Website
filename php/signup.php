<?php
require_once __DIR__ . '/session_security.php';
AppSessionStart();
require 'db_connection.php'; // Connects $pdo to db_itourmercedes
require_once __DIR__ . '/activity_logger.php';
require_once __DIR__ . '/request_rate_limiter.php';
require_once __DIR__ . '/input_validation.php';
require_once __DIR__ . '/turnstile.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require 'PHPMailer/src/PHPMailer.php';
require 'PHPMailer/src/SMTP.php';
require 'PHPMailer/src/Exception.php';
require_once __DIR__ . '/auth_email_template.php';
require_once __DIR__ . '/../payments/PaymentHelper.php';

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
    $legalConsent = ($_POST['legal_consent'] ?? '') === '1';
    $privacyAcknowledged = ($_POST['privacy_acknowledged'] ?? '') === '1';
    $termsAccepted = ($_POST['terms_accepted'] ?? '') === '1';
    $granularLegalConsentProvided = array_key_exists('privacy_acknowledged', $_POST) || array_key_exists('terms_accepted', $_POST);
    $code     = $_POST['code'] ?? '';
    $phone    = trim($_POST['phone'] ?? '');
    $address  = trim($_POST['address'] ?? '');
    $action   = $_POST['action'] ?? '';

    $fname = ItourValidationText($fname, 'First name', 100);
    $lname = ItourValidationText($lname, 'Last name', 100);
    $email = ItourValidationText($email, 'Email', 190, true);
    $phone = ItourValidationText($phone, 'Phone number', 30);
    $address = ItourValidationText($address, 'Address', 500);
    if (!is_string($password) || !is_string($confirm) || strlen($password) > 128 || strlen($confirm) > 128) {
        throw new InvalidArgumentException('Password must not exceed 128 characters.');
    }

    if (!$email) {
        echo json_encode(['status' => 'error', 'title' => 'Missing Email', 'message' => 'Email is required']);
        exit;
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['status' => 'error', 'title' => 'Invalid Email', 'message' => 'Enter a valid email address.']);
        exit;
    }

    if (in_array($action, ['send_code', 'complete_signup'], true) && !ItourTurnstileRequestPassed()) {
        http_response_code(403);
        echo json_encode(['status' => 'error', 'title' => 'Security Check', 'message' => ITOUR_TURNSTILE_ERROR]);
        exit;
    }

     // SEND VERIFICATION CODE
if ($action === 'send_code') {

    if ($fname === '' || $lname === '') {
        echo json_encode(['status' => 'error', 'title' => 'Missing Details', 'message' => 'Complete your first and last name before verifying your email.']);
        exit;
    }

    $sendCodeLimit = requestRateLimitConsume($pdo, 'signup_verification_code', requestRateLimitClientIp(), 4, 600);
    if (!$sendCodeLimit['allowed']) {
        requestRateLimitReject($sendCodeLimit);
    }

    $stmt = $pdo->prepare("SELECT 1 FROM tourist WHERE email = ?");
    $stmt->execute([$email]);

    if ($stmt->rowCount() > 0) {

        echo json_encode([
            'status'  => 'error',
            'title'   => 'Email Exists',
            'message' => 'Email already registered'
        ]);
        exit;
    }

    // =========================
    // GENERATE 6-DIGIT CODE
    // =========================
    $verification_code = random_int(100000, 999999);

    $_SESSION['verification_code'] = $verification_code;
    $_SESSION['signup_email']      = $email;
    $_SESSION['signup_fname']      = $fname;
    $_SESSION['signup_lname']      = $lname;
    $_SESSION['verification_expiry'] = time() + 600; // 10 mins
    unset($_SESSION['signup_verified_email']);

    $mail = new PHPMailer(true);

    try {

        // =========================
        // SMTP CONFIG
        // =========================
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = 'itourmercedes@gmail.com';
        $mail->Password   = PaymentHelper::env('SMTP_PASSWORD');
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;

        // =========================
        // XAMPP / LOCALHOST FIX
        // =========================
        $mail->SMTPOptions = [
            'ssl' => [
                'verify_peer'       => false,
                'verify_peer_name'  => false,
                'allow_self_signed' => true,
            ],
        ];

        // =========================
        // DEBUG LOGS
        // =========================
        $mail->SMTPDebug  = 0;
        $mail->Debugoutput = 'error_log';

        // =========================
        // EMAIL SETUP
        // =========================
        $mail->CharSet = 'UTF-8';
        $mail->Encoding = 'base64';
        $mail->setFrom($mail->Username, 'iTour Mercedes');
        $mail->addReplyTo($mail->Username, 'iTour Mercedes Support');

        $mail->addAddress($email);

        $mail->isHTML(true);

        $mail->Subject = 'iTour Mercedes - Email Verification Code';

        // =========================
        // PROFESSIONAL EMAIL DESIGN
        // =========================
        $signupDisplayName = htmlspecialchars(trim($fname . ' ' . $lname), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $mail->Body = "
        <!DOCTYPE html>
        <html>
        <head>
        <meta charset='UTF-8'>
        <style>

            body{
                margin:0;
                padding:0;
                background:#f4f6f8;
                font-family:Arial,sans-serif;
            }

            .container{
                max-width:520px;
                margin:40px auto;
                background:#ffffff;
                border-radius:14px;
                overflow:hidden;
                box-shadow:0 6px 20px rgba(0,0,0,0.08);
            }

            .header{
                background:linear-gradient(135deg,#2b7a66,#1e5f50);
                color:#fff;
                text-align:center;
                padding:24px;
            }

            .header h1{
                margin:0;
                font-size:22px;
            }

            .header p{
                margin-top:6px;
                font-size:13px;
                opacity:.9;
            }

            .content{
                padding:30px;
                text-align:center;
            }

            .hello{
                font-size:15px;
                color:#333;
                margin-bottom:18px;
            }

            .otp-box{
                display:inline-block;
                background:#f0fdfa;
                border:2px dashed #2b7a66;
                color:#065f46;
                padding:18px 28px;
                border-radius:12px;
                font-size:34px;
                font-weight:bold;
                letter-spacing:8px;
                margin:20px 0;
            }

            .note{
                font-size:14px;
                color:#555;
                line-height:1.6;
                margin-top:10px;
            }

            .warning{
                margin-top:25px;
                font-size:12px;
                color:#b91c1c;
            }

            .footer{
                background:#f3f4f6;
                text-align:center;
                padding:15px;
                font-size:11px;
                color:#777;
            }

        </style>
        </head>

        <body>

            <div class='container'>

                <div class='header'>
                    <h1>iTour Mercedes</h1>
                    <p>Email Verification</p>
                </div>

                <div class='content'>

                    <div class='hello'>
                        Hello <b>{$signupDisplayName}</b>,
                    </div>

                    <p>
                        Use the verification code below to complete your registration:
                    </p>

                    <div class='otp-box'>
                        {$verification_code}
                    </div>

                    <div class='note'>
                        This verification code will expire in
                        <b>10 minutes</b>.
                    </div>

                    <div class='warning'>
                        Never share this code with anyone.
                    </div>

                </div>

                <div class='footer'>
                    © " . date('Y') . " iTour Mercedes. All rights reserved.
                </div>

            </div>

        </body>
        </html>
        ";

        $verificationEmail = itourBuildVerificationEmail(
            'registration',
            (string)$verification_code,
            trim($fname . ' ' . $lname)
        );
        $mail->Subject = $verificationEmail['subject'];
        $mail->Body = $verificationEmail['html'];
        $mail->AltBody = $verificationEmail['text'];

        // =========================
        // SEND EMAIL
        // =========================
        if ($mail->send()) {

            echo json_encode([
                'status'  => 'success',
                'title'   => 'Verification Sent',
                'message' => 'Check your email for the 6-digit verification code.'
            ]);

        } else {

            error_log("Verification email failed: UNKNOWN ERROR");

            echo json_encode([
                'status'  => 'error',
                'title'   => 'Email Failed',
                'message' => 'Unable to send verification email.'
            ]);
        }

        exit;

    } catch (Exception $e) {

        error_log("PHPMailer ERROR: " . $mail->ErrorInfo);
        error_log("EXCEPTION: " . $e->getMessage());

        echo json_encode([
            'status'  => 'error',
            'title'   => 'Email Failed',
            'message' => 'Failed to send verification email.'
        ]);

        exit;
    }

    }

    // VERIFY EMAIL BEFORE PASSWORD SETUP
    if ($action === 'verify_code') {
        if (!preg_match('/^\d{6}$/', (string)$code)) {
            echo json_encode(['status' => 'error', 'title' => 'Incomplete Code', 'message' => 'Enter the complete 6-digit verification code.']);
            exit;
        }
        if (!isset($_SESSION['verification_expiry']) || time() > (int)$_SESSION['verification_expiry']) {
            unset($_SESSION['verification_code'], $_SESSION['signup_verified_email']);
            echo json_encode(['status' => 'error', 'title' => 'Code Expired', 'message' => 'The verification code expired. Please send a new one.']);
            exit;
        }
        if (
            !isset($_SESSION['verification_code']) ||
            !hash_equals((string)$_SESSION['verification_code'], (string)$code) ||
            !isset($_SESSION['signup_email']) ||
            !hash_equals((string)$_SESSION['signup_email'], $email)
        ) {
            echo json_encode(['status' => 'error', 'title' => 'Invalid Code', 'message' => 'Invalid verification code']);
            exit;
        }

        $_SESSION['signup_verified_email'] = $email;
        echo json_encode(['status' => 'success', 'title' => 'Email Verified', 'message' => 'Your email is verified. Create your password to finish.']);
        exit;
    }

    // CREATE ACCOUNT AFTER THE VERIFIED EMAIL STEP
    if ($action === 'complete_signup') {
        if (!$legalConsent || ($granularLegalConsentProvided && (!$privacyAcknowledged || !$termsAccepted))) {
            echo json_encode(['status' => 'error', 'title' => 'Agreement Required', 'message' => 'Please review and accept the Terms & Conditions and acknowledge the Privacy Policy before creating your account.']);
            exit;
        }
        if (!$fname || !$lname || !$phone || !$address || !$password || !$confirm) {
            echo json_encode(['status' => 'error', 'title' => 'Incomplete Fields', 'message' => 'Your personal details, address, and password are required.']);
            exit;
        }
        if (!isset($_SESSION['signup_verified_email']) || !hash_equals((string)$_SESSION['signup_verified_email'], $email)) {
            echo json_encode(['status' => 'error', 'title' => 'Email Not Verified', 'message' => 'Verify this email before creating your account.']);
            exit;
        }
        if (!preg_match('/^[0-9+()\-\s]{7,30}$/', $phone) || strlen(preg_replace('/\D+/', '', $phone)) < 7) {
            echo json_encode(['status' => 'error', 'title' => 'Invalid Contact Number', 'message' => 'Enter a valid contact number using at least 7 digits.']);
            exit;
        }
        if (strlen($address) > 500) {
            echo json_encode(['status' => 'error', 'title' => 'Invalid Address', 'message' => 'The address is too long.']);
            exit;
        }
        if ($password !== $confirm) {
            echo json_encode(['status' => 'error', 'title' => 'Password Mismatch', 'message' => 'Passwords do not match.']);
            exit;
        }
        if (strlen($password) < 6 || !preg_match('/[A-Za-z]/', $password) || !preg_match('/\d/', $password)) {
            echo json_encode(['status' => 'error', 'title' => 'Weak Password', 'message' => 'Use at least 6 characters with at least one letter and one number.']);
            exit;
        }

        $full_name = trim($fname . ' ' . $lname);

        $stmt = $pdo->prepare("SELECT 1 FROM tourist WHERE email = ?");
        $stmt->execute([$email]);
        if ($stmt->rowCount() > 0) {
            echo json_encode(['status' => 'error', 'title' => 'Email Exists', 'message' => 'Email already registered']);
            exit;
        }

        $registrationLimit = requestRateLimitConsume($pdo, 'tourist_registration', requestRateLimitClientIp(), 3, 3600);
        if (!$registrationLimit['allowed']) {
            requestRateLimitReject($registrationLimit);
        }

        $password_hash = password_hash($password, PASSWORD_BCRYPT);

        $stmt = $pdo->prepare("INSERT INTO tourist (full_name, email, phone_number, address, password_hash, email_verified) VALUES (?, ?, ?, ?, ?, 1)");
        $stmt->execute([$full_name, $email, $phone, $address, $password_hash]);

        $userId = $pdo->lastInsertId();

        $stmt = $pdo->prepare("SELECT * FROM tourist WHERE tourist_id = ?");
        $stmt->execute([$userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        session_regenerate_id(true);
        $_SESSION['tourist_id']    = $user['tourist_id'];
        $_SESSION['tourist_logged_in'] = true;
        $_SESSION['tourist_email'] = $user['email'];
        $_SESSION['full_name']     = $user['full_name'];

        $nameParts = explode(" ", $user['full_name'], 2);
        $_SESSION['first_name'] = $nameParts[0];
        $_SESSION['last_name']  = isset($nameParts[1]) ? $nameParts[1] : "";
        AppMarkRoleAuthenticated('tourist');
        logActivity(
            $pdo,
            'Tourist',
            (int)$user['tourist_id'],
            (string)$user['full_name'],
            'Account Created',
            'Created and verified a tourist account.',
            'Accounts',
            (int)$user['tourist_id']
        );

        unset($_SESSION['verification_code'], $_SESSION['signup_email'], $_SESSION['signup_fname'], $_SESSION['signup_lname'], $_SESSION['signup_verified_email'], $_SESSION['verification_expiry']);

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
