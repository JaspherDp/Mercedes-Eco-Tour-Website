<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../php/app_url_helper.php';
require_once __DIR__ . '/../php/session_security.php';

function security17Assert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

function security17SetEnvironment(array $values): void
{
    foreach (['APP_ENV', 'APP_URL', 'PUBLIC_APP_URL', 'LOCAL_APP_URL'] as $name) putenv($name);
    foreach ($values as $name => $value) putenv($name . '=' . $value);
}

try {
    security17SetEnvironment([
        'APP_ENV' => 'production',
        'APP_URL' => 'http://example.test/app',
    ]);
    $rejected = false;
    try { ItourCanonicalAppUrl(); } catch (RuntimeException) { $rejected = true; }
    security17Assert($rejected, 'Production accepted an HTTP APP_URL.');

    security17SetEnvironment([
        'APP_ENV' => 'production',
        'APP_URL' => 'https://localhost/app',
    ]);
    $rejected = false;
    try { ItourCanonicalAppUrl(); } catch (RuntimeException) { $rejected = true; }
    security17Assert($rejected, 'Production accepted a localhost APP_URL.');

    security17SetEnvironment([
        'APP_ENV' => 'production',
        'APP_URL' => 'https://travel.example/app',
    ]);
    security17Assert(ItourCanonicalAppUrl() === 'https://travel.example/app', 'Production rejected a valid HTTPS APP_URL.');
    security17Assert(ItourPaymentReturnBaseUrl() === 'https://travel.example/app', 'Production payment return is not canonical HTTPS.');
    security17Assert(ItourAppUrl('google_callback.php') === 'https://travel.example/app/google_callback.php', 'Production OAuth callback is incorrect.');
    security17Assert(ItourAppUrl('admin-phone-setup.php') === 'https://travel.example/app/admin-phone-setup.php', 'Production phone setup URL is incorrect.');
    security17Assert(ItourAppUrl('admin-payment-handoff.php?token=' . str_repeat('a', 64)) === 'https://travel.example/app/admin-payment-handoff.php?token=' . str_repeat('a', 64), 'Production payment handoff URL is incorrect.');
    $_SERVER['HTTP_HOST'] = 'attacker.invalid';
    security17Assert(ItourCanonicalAppUrl() === 'https://travel.example/app', 'HTTP_HOST influenced the production URL.');
    $successSource = (string)file_get_contents(__DIR__ . '/../payments/payment-success.php');
    $cancelSource = (string)file_get_contents(__DIR__ . '/../payments/payment-cancel.php');
    security17Assert(!str_contains($successSource, 'LOCAL_APP_URL') && !str_contains($successSource, '$localBaseUrl'), 'Success return still contains legacy localhost routing.');
    security17Assert(!str_contains($cancelSource, 'LOCAL_APP_URL') && !str_contains($cancelSource, '$localBaseUrl'), 'Cancel return still contains legacy localhost routing.');
    foreach ([
        __DIR__ . '/../php/email_branding_helper.php',
        __DIR__ . '/../php/provider_cancellation_email.php',
        __DIR__ . '/../admin/adbookings.php',
        __DIR__ . '/../admin/adpaymenttransactions.php',
    ] as $emailSourcePath) {
        security17Assert(!str_contains((string)file_get_contents($emailSourcePath), 'HTTP_HOST'), 'An email URL builder still trusts HTTP_HOST.');
    }

    security17SetEnvironment([
        'APP_ENV' => 'development',
        'APP_URL' => 'https://tunnel.example/app',
        'LOCAL_APP_URL' => 'http://localhost/Mercedes%20Eco%20Tour%20Website',
    ]);
    security17Assert(ItourPaymentReturnBaseUrl() === 'http://localhost/Mercedes%20Eco%20Tour%20Website', 'Development payment return lost localhost compatibility.');
    $developmentCallback = ItourAppUrl('google_callback.php', ItourPaymentReturnBaseUrl());
    security17Assert($developmentCallback === 'http://localhost/Mercedes%20Eco%20Tour%20Website/google_callback.php', 'Development OAuth callback is incorrect.');

    unset($_SERVER['HTTPS'], $_SERVER['HTTP_X_FORWARDED_PROTO']);
    $_SERVER['SERVER_PORT'] = '80';
    AppSessionConfigure();
    $cookie = session_get_cookie_params();
    security17Assert(($cookie['secure'] ?? true) === false && ($cookie['httponly'] ?? false) === true
        && ($cookie['samesite'] ?? '') === 'Lax', 'Local HTTP session cookie settings changed.');
    $_SERVER['HTTPS'] = 'on';
    $_SERVER['SERVER_PORT'] = '443';
    AppSessionConfigure();
    $cookie = session_get_cookie_params();
    security17Assert(($cookie['secure'] ?? false) === true && ($cookie['httponly'] ?? false) === true
        && ($cookie['samesite'] ?? '') === 'Lax', 'Native HTTPS session cookie settings are incorrect.');

    echo "Security #17 HTTPS regression: PASS\n";
} finally {
    security17SetEnvironment([]);
}
