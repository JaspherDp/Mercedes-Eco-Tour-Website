<?php
require_once 'vendor/autoload.php';
require_once 'php/google_oauth.php';
session_start();

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

// FORCE account selection every time
$client->setPrompt('select_account');

// Redirect user to Google sign-in page
$authUrl = $client->createAuthUrl();
header('Location: ' . $authUrl);
exit();

?>
