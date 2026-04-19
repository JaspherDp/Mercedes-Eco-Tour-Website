<?php
require_once 'vendor/autoload.php';
require_once 'php/db_connection.php'; // your PDO connection
require_once 'php/google_oauth.php';
session_start();

// --- Google Client Configuration ---
$client = new Google_Client();
try {
    $googleConfig = load_google_oauth_config();
} catch (RuntimeException $e) {
    http_response_code(500);
    exit(htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'));
}
$client->setClientId($googleConfig['client_id']);
$client->setClientSecret($googleConfig['client_secret']);
$client->setRedirectUri($googleConfig['redirect_uri']);

$client->addScope('email');
$client->addScope('profile');

// --- Check for authorization code ---
if (!isset($_GET['code'])) {
    header('Location: homepage.php');
    exit();
}

// --- Get Access Token ---
$token = $client->fetchAccessTokenWithAuthCode($_GET['code']);
if (isset($token['error'])) {
    if (($token['error'] ?? '') === 'invalid_client') {
        http_response_code(500);
        exit('Google Sign-In failed: invalid OAuth client credentials. Update GOOGLE_CLIENT_ID and GOOGLE_CLIENT_SECRET in .env and Google Cloud Console.');
    }
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
if (empty($profile_pic) || strlen((string)$profile_pic) > 180) {
    $profile_pic = 'https://profiles.google.com/' . rawurlencode((string)$google_id) . '/picture?sz=256';
}

// --- Check if user exists ---
$stmt = $pdo->prepare("SELECT * FROM tourist WHERE email = ?");
$stmt->execute([$email]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if ($user) {
    if ($user['google_id'] && $user['google_id'] !== $google_id) {
        // Email exists but with a different Google account
        die('This email is already registered with another Google account.');
    }

    if (!$user['google_id']) {
        // User exists via normal signup → prevent Google signup
        die('This email is already registered. Please login using your email and password.');
    }

    // ✅ Existing Google user → login
    $_SESSION['tourist_logged_in'] = true;
    $_SESSION['tourist_id'] = $user['tourist_id'];
    $_SESSION['tourist_email'] = $user['email'];
    $_SESSION['tourist_name'] = $user['full_name'];

    // 🔹 Update profile picture if Google photo is new
    if ($user['profile_picture'] !== $profile_pic) {
        $stmt = $pdo->prepare("UPDATE tourist SET profile_picture = ?, updated_at = NOW() WHERE tourist_id = ?");
        $stmt->execute([$profile_pic, $user['tourist_id']]);
    }

    $_SESSION['tourist_profile_pic'] = $profile_pic;
} else {
    // ✅ New Google user → insert into DB
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

    $_SESSION['tourist_logged_in'] = true;
    $_SESSION['tourist_id'] = $newUserId;
    $_SESSION['tourist_email'] = $email;
    $_SESSION['tourist_name'] = $fullname;
    $_SESSION['tourist_profile_pic'] = $profile_pic;
}

// --- Redirect back to requested page if available ---
$redirectUrl = $_SESSION['post_login_redirect'] ?? 'homepage.php';
unset($_SESSION['post_login_redirect']);
header('Location: ' . $redirectUrl);
exit();
