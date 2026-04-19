<?php
require_once 'vendor/autoload.php';
session_start();

// ✅ Your Google Cloud credentials
$client = new Google_Client();
$clientID = getenv("GOOGLE_CLIENT_ID");
$clientSecret = getenv("GOOGLE_CLIENT_SECRET");
$client->setClientId($clientID);
$client->setClientSecret($clientSecret);
$client->setRedirectUri('http://localhost/Mercedes%20Eco%20Tour%20Website/google_callback.php');
$client->addScope('email');
$client->addScope('profile');

// FORCE account selection every time
$client->setPrompt('select_account');

// Redirect user to Google sign-in page
$authUrl = $client->createAuthUrl();
header('Location: ' . $authUrl);
exit();

?>
