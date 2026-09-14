<?php
require_once __DIR__ . '/session_security.php';
AppSessionStart();
require 'db_connection.php';
require_once __DIR__ . '/activity_logger.php';
require_once __DIR__ . '/admin_auth_helper.php';
require_once __DIR__ . '/login_throttle.php';
require_once __DIR__ . '/turnstile.php';
require 'alert.php';

$requestedAdminReturn = trim((string)($_POST['return_to'] ?? $_GET['return_to'] ?? ''));
$adminReturnPage = AdminNormalizeReturnTo($requestedAdminReturn);
$isPhoneSetupReturn = $adminReturnPage === 'admin-phone-setup.php';
$isAjaxLogin = strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $username = trim((string)($_POST['username'] ?? ''));
    $password = trim((string)($_POST['password'] ?? ''));

    if ($username === '' || $password === '' || !ItourTurnstileRequestPassed()) {
        $message = $username === '' || $password === '' ? 'Username and password are required.' : ITOUR_TURNSTILE_ERROR;
        if ($isAjaxLogin) {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(403);
            echo json_encode(['success' => false, 'locked' => false, 'message' => $message, 'retry_after' => 0]);
            exit();
        }
        $_SESSION['alert'] = ['type' => 'error', 'title' => 'Login Failed', 'message' => $message];
        $securityFailureLocation = 'admin_login.php';
        if ($adminReturnPage !== 'adhomepage.php') {
            $securityFailureLocation .= '?return_to=' . rawurlencode($adminReturnPage);
        }
        header('Location: ' . $securityFailureLocation);
        exit();
    }

    $throttle = loginThrottleStatus($pdo, 'administrator', $username);
    if ($throttle['locked']) {
        $message = loginThrottleMessage($throttle['retry_after']);
        if ($isAjaxLogin) {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(429);
            echo json_encode(['success' => false, 'locked' => true, 'message' => $message, 'retry_after' => $throttle['retry_after']]);
            exit();
        }
        $_SESSION['alert'] = ['type' => 'error', 'title' => 'Login Temporarily Locked', 'message' => $message];
        header('Location: admin_login.php');
        exit();
    }

    $stmt = $pdo->prepare("SELECT * FROM admin_users WHERE username = ?");
    $stmt->execute([$username]);
    $admin = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($admin && password_verify($password, $admin['password'])) {
        loginThrottleClear($pdo, 'administrator', $username);
        session_regenerate_id(true);
        $_SESSION['admin_logged_in'] = true;
        $_SESSION['admin_id'] = (int)$admin['admin_id'];
        $_SESSION['username'] = $admin['username'];
        $_SESSION['admin_name'] = $admin['full_name'];
        $_SESSION['admin_session_started'] = time();
        AppMarkRoleAuthenticated('admin');
        if ($isPhoneSetupReturn) {
            // This one-use grant lets the phone setup page open only immediately
            // after the Administrator has entered their credentials.
            $_SESSION['admin_phone_setup_grant'] = bin2hex(random_bytes(32));
        } else {
            unset($_SESSION['admin_phone_setup_grant']);
        }
        logActivity(
            $pdo,
            'Admin',
            (int)$admin['admin_id'],
            (string)($admin['full_name'] ?: $admin['username']),
            'Login',
            'Signed in to the admin panel.',
            'Authentication'
        );
        setcookie('admin_seen', '1', [
            'expires' => time() + (60 * 60 * 24 * 30),
            'path' => '/',
            'secure' => AppSessionIsHttps(),
            'httponly' => true,
            'samesite' => 'Lax'
        ]);

        $_SESSION['alert'] = [
            'type' => 'success',
            'title' => 'Login Successful',
            'message' => 'Welcome back. Your administrator dashboard is ready.'
        ];
        if ($isAjaxLogin) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => true, 'redirect' => '../' . $adminReturnPage]);
            exit();
        }
        header('Location: ../' . $adminReturnPage);
        exit();
    } else {
        $throttle = loginThrottleRecordFailure($pdo, 'administrator', $username, 300);
        $message = $throttle['locked']
            ? loginThrottleMessage($throttle['retry_after'])
            : 'Incorrect username or password. ' . $throttle['attempts_remaining'] . ' attempts remaining.';
        if ($isAjaxLogin) {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code($throttle['locked'] ? 429 : 401);
            echo json_encode(['success' => false, 'locked' => $throttle['locked'], 'message' => $message, 'retry_after' => $throttle['retry_after'], 'attempts_remaining' => $throttle['attempts_remaining']]);
            exit();
        }
        $_SESSION['alert'] = [
            'type' => 'error',
            'title' => $throttle['locked'] ? 'Login Temporarily Locked' : 'Login Failed',
            'message' => $message
        ];
        $failureLocation = 'admin_login.php';
        if ($adminReturnPage !== 'adhomepage.php') {
            $failureLocation .= '?return_to=' . rawurlencode($adminReturnPage);
        }
        header('Location: ' . $failureLocation);
        exit();
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>iTour Mercedes - Admin Login</title>
<link rel="icon" type="image/png" href="../img/newlogo.png">
<link rel="stylesheet" href="../styles/auth-portal.css?v=10">
</head>
<body class="auth-page">

<div class="adlog-modal">
    <div class="adlog-brand">
        <img class="adlog-brand-logo" src="../img/newlogo.png" alt="">
        <img class="adlog-brand-wordmark" src="../img/textlogo2.png" alt="iTour Mercedes">
    </div>

    <header class="adlog-heading">
        <h1 class="adlog-title">Administrator Login</h1>
    </header>

    <form class="adlog-form" action="" method="POST" data-login-scope="administrator">
        <?php if ($adminReturnPage !== 'adhomepage.php'): ?>
            <input type="hidden" name="return_to" value="<?= htmlspecialchars($adminReturnPage, ENT_QUOTES, 'UTF-8') ?>">
        <?php endif; ?>
        <div class="adlog-input-group">
            <svg class="adlog-input-icon" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="8" r="4"/><path d="M5 21a7 7 0 0 1 14 0"/></svg>
            <input type="text" id="adminUsername" name="username" required placeholder=" " autocomplete="username">
            <label for="adminUsername">Username</label>
        </div>

        <div class="adlog-input-group">
            <svg class="adlog-input-icon" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="4" y="10" width="16" height="11" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/></svg>
            <input type="password" id="adminPassword" name="password" required placeholder=" " autocomplete="current-password">
            <label for="adminPassword">Password</label>
            <img class="adlog-eye-icon" id="toggleAdminPassword" src="../img/passwordhide.png" data-hidden-icon="../img/passwordhide.png" data-visible-icon="../img/passwordsee.png" data-password-input="adminPassword" alt="Show password" role="button" tabindex="0">
        </div>

        <div class="itour-turnstile" data-itour-turnstile="administrator-login"></div>
        <button type="submit" class="adlog-btn">
            <span>Login</span>
            <svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
        </button>
    </form>
</div>

<script src="../js/turnstile.js?v=1"></script>
<script src="../js/auth-portal.js?v=10"></script>
</body>
</html>

