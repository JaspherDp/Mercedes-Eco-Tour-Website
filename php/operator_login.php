<?php
require_once __DIR__ . '/session_security.php';
AppSessionStart();
require 'db_connection.php';
require_once __DIR__ . '/activity_logger.php';
require_once __DIR__ . '/login_throttle.php';
require_once __DIR__ . '/turnstile.php';
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
        header('Location: operator_login.php');
        exit();
    }

    $throttle = loginThrottleStatus($pdo, 'operator', $username);
    if ($throttle['locked']) {
        $message = loginThrottleMessage($throttle['retry_after']);
        if ($isAjaxLogin) {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(429);
            echo json_encode(['success' => false, 'locked' => true, 'message' => $message, 'retry_after' => $throttle['retry_after']]);
            exit();
        }
        $_SESSION['alert'] = ['type' => 'error', 'title' => 'Login Temporarily Locked', 'message' => $message];
        header('Location: operator_login.php');
        exit();
    }

    $stmt = $pdo->prepare("SELECT * FROM operators WHERE username = ?");
    $stmt->execute([$username]);
    $operator = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($operator && password_verify($password, $operator['password'])) {
        loginThrottleClear($pdo, 'operator', $username);
        
        if ($operator['status'] !== 'active') {
            if ($isAjaxLogin) {
                header('Content-Type: application/json; charset=utf-8');
                http_response_code(403);
                echo json_encode(['success' => false, 'message' => 'Your account is inactive. Contact the administrator.']);
                exit();
            }
            $_SESSION['alert'] = [
                'type' => 'error',
                'title' => 'Account Inactive',
                'message' => 'Your account is inactive. Contact the admin.'
            ];
            header('Location: operator_login.php');
            exit();
        } else {
            session_regenerate_id(true);
            $_SESSION['operator_logged_in'] = true;
            $_SESSION['operator_id'] = $operator['operator_id'];
            $_SESSION['operator_name'] = $operator['fullname'];
            AppMarkRoleAuthenticated('operator');
            logActivity(
                $pdo,
                'Tour Operator',
                (int)$operator['operator_id'],
                (string)$operator['fullname'],
                'Login',
                'Signed in to the tour operator panel.',
                'Authentication'
            );
            setcookie('operator_seen', '1', [
                'expires' => time() + (60 * 60 * 24 * 30),
                'path' => '/',
                'secure' => AppSessionIsHttps(),
                'httponly' => true,
                'samesite' => 'Lax'
            ]);

            $_SESSION['alert'] = [
                'type' => 'success',
                'title' => 'Login Successful',
                'message' => 'Welcome back. Your operator dashboard is ready.'
            ];
            $operatorReturnTo = (string)($_SESSION['operator_login_return_to'] ?? '');
            unset($_SESSION['operator_login_return_to']);
            $operatorRedirect = $operatorReturnTo === '../operator-phone-setup.php'
                ? $operatorReturnTo
                : '../ophomepage.php';
            if ($isAjaxLogin) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['success' => true, 'redirect' => $operatorRedirect]);
                exit();
            }
            header('Location: ' . $operatorRedirect);
            exit();
        }
    } else {
        $throttle = loginThrottleRecordFailure($pdo, 'operator', $username, 300);
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
        header('Location: operator_login.php');
        exit();
    }
}
?>
<?php require 'alert.php'; ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>iTour Mercedes - Operator Login</title>
<link rel="icon" type="image/png" href="../img/newlogo.png">
<link rel="stylesheet" href="../styles/auth-portal.css?v=14">
</head>
<body class="auth-page">

<div class="adlog-modal">
    <div class="adlog-brand">
        <img class="adlog-brand-logo" src="../img/newlogo.png" alt="">
        <img class="adlog-brand-wordmark" src="../img/textlogo2-transparent.png" alt="iTour Mercedes">
    </div>

    <header class="adlog-heading">
        <h1 class="adlog-title">Operator Login</h1>
    </header>

    <form class="adlog-form" action="" method="POST" data-login-scope="operator">
        <div class="adlog-input-group">
            <svg class="adlog-input-icon" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="8" r="4"/><path d="M5 21a7 7 0 0 1 14 0"/></svg>
            <input type="text" id="operatorUsername" name="username" required placeholder=" " autocomplete="username">
            <label for="operatorUsername">Username</label>
        </div>

        <div class="adlog-input-group">
            <svg class="adlog-input-icon" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="4" y="10" width="16" height="11" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/></svg>
            <input type="password" id="operatorPassword" name="password" required placeholder=" " autocomplete="current-password">
            <label for="operatorPassword">Password</label>
            <img class="adlog-eye-icon" id="toggleOperatorPassword" src="../img/passwordhide.png" data-hidden-icon="../img/passwordhide.png" data-visible-icon="../img/passwordsee.png" data-password-input="operatorPassword" alt="Show password" role="button" tabindex="0">
        </div>

        <div class="itour-turnstile" data-itour-turnstile="operator-login"></div>
        <button type="submit" class="adlog-btn">
            <span>Login</span>
            <svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
        </button>
    </form>
</div>

<script src="../js/turnstile.js?v=7"></script>
<script src="../js/auth-portal.js?v=10"></script>
</body>
</html>

