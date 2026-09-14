<?php
require_once __DIR__ . '/session_security.php';
AppSessionStart();
require 'db_connection.php';
require_once __DIR__ . '/activity_logger.php';
require_once __DIR__ . '/login_throttle.php';
require_once __DIR__ . '/turnstile.php';

header('Content-Type: application/json');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        echo json_encode(["status" => "error", "message" => "Invalid request"]);
        exit;
    }

    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($email) || empty($password)) {
        echo json_encode(["status" => "error", "message" => "Email and password are required"]);
        exit;
    }

    if (!ItourTurnstileRequestPassed()) {
        http_response_code(403);
        echo json_encode(["status" => "error", "message" => ITOUR_TURNSTILE_ERROR]);
        exit;
    }

    $throttle = loginThrottleStatus($pdo, 'tourist', $email);
    if ($throttle['locked']) {
        http_response_code(429);
        echo json_encode([
            'status' => 'locked',
            'locked' => true,
            'message' => loginThrottleMessage($throttle['retry_after']),
            'retry_after' => $throttle['retry_after'],
        ]);
        exit;
    }

    // Query the tourist table
    $stmt = $pdo->prepare("SELECT * FROM tourist WHERE email = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    // Check if user exists and password is correct
    if (!$user || !password_verify($password, $user['password_hash'])) {
        $throttle = loginThrottleRecordFailure($pdo, 'tourist', $email, 120);
        http_response_code($throttle['locked'] ? 429 : 401);
        echo json_encode([
            'status' => $throttle['locked'] ? 'locked' : 'error',
            'locked' => $throttle['locked'],
            'message' => $throttle['locked']
                ? loginThrottleMessage($throttle['retry_after'])
                : 'Email or password is wrong. ' . $throttle['attempts_remaining'] . ' attempts remaining.',
            'retry_after' => $throttle['retry_after'],
            'attempts_remaining' => $throttle['attempts_remaining'],
        ]);
        exit;
    }

    loginThrottleClear($pdo, 'tourist', $email);

    // Check if email is verified
    if (!$user['email_verified']) {
        echo json_encode(["status" => "error", "message" => "Please verify your email before logging in"]);
        exit;
    }

    // Check if account is banned
    if ($user['status'] === 'banned') {
        $ban_note = $user['ban_note'] ?: "No reason provided.";
        echo json_encode([
            "status" => "banned",
            "message" => "Your account has been banned. Reason: " . $ban_note
        ]);
        exit;
    }

    // --- Save session ---
    session_regenerate_id(true);
    $_SESSION['tourist_id']    = $user['tourist_id'];
    $_SESSION['tourist_logged_in'] = true;
    $_SESSION['tourist_email'] = $user['email'];
    $_SESSION['full_name']     = $user['full_name'];

    // Split full_name into first/last name for forms
    $nameParts = explode(" ", $user['full_name'], 2);
    $_SESSION['first_name'] = $nameParts[0];
    $_SESSION['last_name']  = isset($nameParts[1]) ? $nameParts[1] : "";
    AppMarkRoleAuthenticated('tourist');
    logActivity(
        $pdo,
        'Tourist',
        (int)$user['tourist_id'],
        (string)$user['full_name'],
        'Login',
        'Signed in to the tourist account.',
        'Authentication'
    );

    $redirectUrl = $_SESSION['post_login_redirect'] ?? null;
    if ($redirectUrl) {
        unset($_SESSION['post_login_redirect']);
    }

    echo json_encode([
        "status"  => "success",
        "message" => "Login successful!",
        "redirect_url" => $redirectUrl,
        "user"    => [
            "id"         => $_SESSION['tourist_id'],
            "email"      => $_SESSION['tourist_email'],
            "full_name"  => $_SESSION['full_name'],
            "first_name" => $_SESSION['first_name'],
            "last_name"  => $_SESSION['last_name']
        ]
    ]);

} catch (PDOException $e) {
    error_log("Login error: " . $e->getMessage());
    echo json_encode(["status" => "error", "message" => "Email or password is wrong"]);
}
