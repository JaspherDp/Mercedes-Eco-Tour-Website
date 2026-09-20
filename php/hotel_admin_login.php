<?php
require_once __DIR__ . '/session_security.php';
AppSessionStart();
require_once __DIR__ . '/../Ho_common.php';
require_once __DIR__ . '/activity_logger.php';
require_once __DIR__ . '/login_throttle.php';
require_once __DIR__ . '/turnstile.php';

$errorMessage = '';
$isAjaxLogin = strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';
$requestedReturnTo = trim((string)($_POST['return_to'] ?? $_GET['return_to'] ?? ''));
$safeReturnTo = HoNormalizeHotelAdminReturnTo($requestedReturnTo);
$returnTo = '../' . $safeReturnTo;

if (isset($_SESSION['hotel_admin_logged_in']) && $_SESSION['hotel_admin_logged_in'] === true) {
    HoRequireHotelAdmin($pdo);
    header('Location: ' . $returnTo);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim((string)($_POST['username'] ?? ''));
    $password = trim((string)($_POST['password'] ?? ''));

    if ($username === '' || $password === '') {
        $errorMessage = 'Username and password are required.';
    } elseif (!ItourTurnstileRequestPassed()) {
        $errorMessage = ITOUR_TURNSTILE_ERROR;
    } else {
        $throttle = loginThrottleStatus($pdo, 'hotel-administrator', $username);
        if ($throttle['locked']) {
            $errorMessage = loginThrottleMessage($throttle['retry_after']);
            if ($isAjaxLogin) {
                header('Content-Type: application/json; charset=utf-8');
                http_response_code(429);
                echo json_encode(['success' => false, 'locked' => true, 'message' => $errorMessage, 'retry_after' => $throttle['retry_after']]);
                exit;
            }
        }

        if ($errorMessage !== '') {
            // Keep the form available for a normal POST fallback while the
            // JavaScript client handles the live countdown for AJAX requests.
        } else {
        $stmt = $pdo->prepare("
            SELECT
              ha.hotel_admin_id,
              ha.hotel_resort_id,
              ha.username,
              ha.password,
              ha.full_name,
              ha.status,
              hr.name AS property_name
            FROM hotel_admin_accounts ha
            LEFT JOIN hotel_resorts hr ON hr.hotel_resort_id = ha.hotel_resort_id
            WHERE ha.username = ?
            LIMIT 1
        ");
        $stmt->execute([$username]);
        $admin = $stmt->fetch(PDO::FETCH_ASSOC);

        if (
            $admin &&
            strtolower((string)$admin['status']) === 'active' &&
            password_verify($password, (string)$admin['password'])
        ) {
            loginThrottleClear($pdo, 'hotel-administrator', $username);
            session_regenerate_id(true);
            HoSetHotelAdminSession($admin);
            AppMarkRoleAuthenticated('hotel_admin');
            logActivity(
                $pdo,
                'Hotel Owner',
                (int)$admin['hotel_admin_id'],
                (string)($admin['full_name'] ?: $admin['username']),
                'Login',
                'Signed in to the hotel owner panel.',
                'Authentication'
            );
            setcookie('hotel_admin_seen', '1', [
                'expires' => time() + (60 * 60 * 24 * 30),
                'path' => '/',
                'secure' => AppSessionIsHttps(),
                'httponly' => true,
                'samesite' => 'Lax'
            ]);
            $_SESSION['alert'] = [
                'type' => 'success',
                'title' => 'Login Successful',
                'message' => 'Welcome back. Your property dashboard is ready.'
            ];
            if ($isAjaxLogin) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['success' => true, 'redirect' => $returnTo]);
                exit;
            }
            header('Location: ' . $returnTo);
            exit;
        }

        $throttle = loginThrottleRecordFailure($pdo, 'hotel-administrator', $username, 300);
        $errorMessage = $throttle['locked']
            ? loginThrottleMessage($throttle['retry_after'])
            : 'Incorrect username or password. ' . $throttle['attempts_remaining'] . ' attempts remaining.';

        if ($isAjaxLogin && $errorMessage !== '') {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code($throttle['locked'] ? 429 : 401);
            echo json_encode(['success' => false, 'locked' => $throttle['locked'], 'message' => $errorMessage, 'retry_after' => $throttle['retry_after'], 'attempts_remaining' => $throttle['attempts_remaining']]);
            exit;
        }
        }
    }

    if ($isAjaxLogin && $errorMessage !== '') {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(400);
        echo json_encode(['success' => false, 'locked' => false, 'message' => $errorMessage, 'retry_after' => 0]);
        exit;
    }
}
?>
<?php require_once __DIR__ . '/alert.php'; ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>iTour Mercedes - Hotel and Resort Owner Login</title>
<link rel="icon" type="image/png" href="../img/newlogo.png" />
<link rel="stylesheet" href="../styles/auth-portal.css?v=14">
</head>
<body class="auth-page">

<div class="adlog-modal">
    <div class="adlog-brand">
        <img class="adlog-brand-logo" src="../img/newlogo.png" alt="">
        <img class="adlog-brand-wordmark" src="../img/textlogo2-transparent.png" alt="iTour Mercedes">
    </div>

    <header class="adlog-heading">
        <h1 class="adlog-title">Hotel / Resort Owner Login</h1>
    </header>

    <?php if ($errorMessage !== ''): ?>
      <div class="adlog-alert" role="alert">
        <svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9"/><path d="M12 7v6M12 17h.01"/></svg>
        <span><?= htmlspecialchars($errorMessage) ?></span>
      </div>
    <?php endif; ?>

    <form class="adlog-form" action="" method="POST" data-login-scope="hotel-administrator">
        <input type="hidden" name="return_to" value="<?= htmlspecialchars($safeReturnTo, ENT_QUOTES, 'UTF-8') ?>">
        <div class="adlog-input-group">
            <svg class="adlog-input-icon" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="8" r="4"/><path d="M5 21a7 7 0 0 1 14 0"/></svg>
            <input type="text" id="ownerUsername" name="username" required placeholder=" " autocomplete="username">
            <label for="ownerUsername">Username</label>
        </div>

        <div class="adlog-input-group">
            <svg class="adlog-input-icon" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="4" y="10" width="16" height="11" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/></svg>
            <input type="password" id="ownerPassword" name="password" required placeholder=" " autocomplete="current-password">
            <label for="ownerPassword">Password</label>
            <img class="adlog-eye-icon" id="toggleOwnerPassword" src="../img/passwordhide.png" data-hidden-icon="../img/passwordhide.png" data-visible-icon="../img/passwordsee.png" data-password-input="ownerPassword" alt="Show password" role="button" tabindex="0">
        </div>

        <div class="itour-turnstile" data-itour-turnstile="hotel-owner-login"></div>
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
