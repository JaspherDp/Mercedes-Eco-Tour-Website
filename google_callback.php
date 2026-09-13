<?php
require_once 'vendor/autoload.php';
require_once 'php/db_connection.php'; // your PDO connection
require_once 'php/google_oauth.php';
require_once 'php/activity_logger.php';
require_once 'php/request_rate_limiter.php';
require_once 'php/session_security.php';
AppSessionStart();

// --- Google Client Configuration ---
$client = new Google_Client();
$googleConfig = load_google_oauth_config();
$client->setClientId($googleConfig['client_id']);
$client->setClientSecret($googleConfig['client_secret']);
$client->setRedirectUri($googleConfig['redirect_uri']);

$client->addScope('email');
$client->addScope('profile');

$expectedState = (string)($_SESSION['google_oauth_state'] ?? '');
unset($_SESSION['google_oauth_state']);
$submittedState = (string)($_GET['state'] ?? '');
if ($expectedState === '' || $submittedState === '' || !hash_equals($expectedState, $submittedState)) {
    http_response_code(400);
    exit('Google login request expired or is invalid. Please try again.');
}

// --- Check for authorization code ---
if (!isset($_GET['code'])) {
    header('Location: homepage.php');
    exit();
}

// --- Get Access Token ---
$token = $client->fetchAccessTokenWithAuthCode($_GET['code']);
if (isset($token['error'])) {
    die('Google login failed: ' . htmlspecialchars($token['error']));
}
$client->setAccessToken($token);

// --- Get Google User Info ---
$oauth = new Google_Service_Oauth2($client);
$googleUser = $oauth->userinfo->get();

$email = $googleUser->email;
$fullname = $googleUser->name;
$google_id = $googleUser->id;
$profile_pic = $googleUser->picture;
if ($profile_pic) {
    $profile_pic = preg_replace('/([?&])sz=\\d+/i', '$1sz=256', $profile_pic);
    $profile_pic = preg_replace('/=s\\d+-c(?=$|[?&#])/i', '=s256-c', $profile_pic);
    $profile_pic = preg_replace('/=s\\d+(?=$|[?&#])/i', '=s256', $profile_pic);
}
if (empty($profile_pic)) {
    $profile_pic = '';
}

// --- Check if user exists ---
$stmt = $pdo->prepare("SELECT * FROM tourist WHERE email = ?");
$stmt->execute([$email]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if ($user) {
    if (strtolower((string)($user['status'] ?? 'active')) === 'banned') {
        http_response_code(403);
        exit('This account cannot sign in. Please contact the Tourism Office.');
    }
    if ($user['google_id'] && $user['google_id'] !== $google_id) {
        // Email exists but with a different Google account
        die('This email is already registered with another Google account.');
    }

    if (!$user['google_id']) {
        // User exists via normal signup → prevent Google signup
        die('This email is already registered. Please login using your email and password.');
    }

    // ✅ Existing Google user → login
    session_regenerate_id(true);
    $_SESSION['tourist_logged_in'] = true;
    $_SESSION['tourist_id'] = $user['tourist_id'];
    $_SESSION['tourist_email'] = $user['email'];
    $_SESSION['tourist_name'] = $user['full_name'];
    $_SESSION['full_name'] = $user['full_name'];
    AppMarkRoleAuthenticated('tourist');

    // 🔹 Update profile picture if Google photo is new
    if ($user['profile_picture'] !== $profile_pic) {
        $stmt = $pdo->prepare("UPDATE tourist SET profile_picture = ?, updated_at = NOW() WHERE tourist_id = ?");
        $stmt->execute([$profile_pic, $user['tourist_id']]);
    }

    $_SESSION['tourist_profile_pic'] = $profile_pic;
    logActivity(
        $pdo, 'Tourist', (int)$user['tourist_id'], (string)$user['full_name'],
        'Login', 'Signed in to the tourist account with Google.', 'Authentication'
    );
} else {
    // ✅ New Google user → insert into DB
    $registrationLimit = requestRateLimitConsume($pdo, 'tourist_registration', requestRateLimitClientIp(), 3, 3600);
    if (!$registrationLimit['allowed']) {
        $_SESSION['request_rate_limit_notice'] = [
            'title' => 'Request Limit Reached',
            'message' => 'The registration limit has been reached. Please try again after the timer ends.',
            'retry_after' => max(1, (int)$registrationLimit['retry_after']),
            'rate_limited' => true,
        ];
        header('Location: signup.php?request_limited=1');
        exit;
    }

    $randomPassword = bin2hex(random_bytes(8));
    $passwordHash = password_hash($randomPassword, PASSWORD_DEFAULT);

    $insert = $pdo->prepare("
        INSERT INTO tourist 
        (full_name, email, phone_number, address, password_hash, email_verified, verification_code, created_at, updated_at, profile_picture, google_id)
        VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW(), ?, ?)
    ");

    $insert->execute([
        $fullname,
        $email,
        '',            // phone_number
        '',            // address
        $passwordHash, // password_hash
        1,             // email_verified
        NULL,          // verification_code
        $profile_pic,
        $google_id
    ]);

    $newUserId = $pdo->lastInsertId();

    session_regenerate_id(true);
    $_SESSION['tourist_logged_in'] = true;
    $_SESSION['tourist_id'] = $newUserId;
    $_SESSION['tourist_email'] = $email;
    $_SESSION['tourist_name'] = $fullname;
    $_SESSION['full_name'] = $fullname;
    $_SESSION['tourist_profile_pic'] = $profile_pic;
    AppMarkRoleAuthenticated('tourist');
    logActivity(
        $pdo, 'Tourist', (int)$newUserId, (string)$fullname,
        'Account Created', 'Created a tourist account with Google.', 'Accounts', (int)$newUserId
    );
}

// --- Redirect back to requested page if available ---
$redirectUrl = $_SESSION['post_login_redirect'] ?? 'homepage.php';
unset($_SESSION['post_login_redirect']);
header('Location: ' . $redirectUrl);
exit();
