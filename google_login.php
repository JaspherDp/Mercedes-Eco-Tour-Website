<?php
require_once 'vendor/autoload.php';
require_once 'php/google_oauth.php';
require_once 'php/session_security.php';
AppSessionStart();
session_regenerate_id(true);

$client = new Google_Client();
$googleConfig = load_google_oauth_config();
$client->setClientId($googleConfig['client_id']);
$client->setClientSecret($googleConfig['client_secret']);
$client->setRedirectUri($googleConfig['redirect_uri']);
$client->addScope('email');
$client->addScope('profile');

// FORCE account selection every time
$client->setPrompt('select_account');

$oauthState = bin2hex(random_bytes(32));
$_SESSION['google_oauth_state'] = $oauthState;
$client->setState($oauthState);

// Redirect user to Google sign-in page
$authUrl = $client->createAuthUrl();
header('Location: ' . $authUrl);
exit();

?>
