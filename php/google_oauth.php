<?php

require_once __DIR__ . '/../payments/PaymentHelper.php';
require_once __DIR__ . '/app_url_helper.php';

function load_google_oauth_config(): array
{
    static $config = null;

    if ($config !== null) {
        return $config;
    }

    $clientId = PaymentHelper::env('GOOGLE_CLIENT_ID');
    $clientSecret = PaymentHelper::env('GOOGLE_CLIENT_SECRET');

    if ($clientId === '' || $clientSecret === '') {
        throw new RuntimeException('Google sign-in is not configured in the private server environment.');
    }

    try {
        $redirectBaseUrl = ItourAppIsProduction() ? ItourCanonicalAppUrl() : ItourPaymentReturnBaseUrl();
        $redirectUri = ItourAppUrl('google_callback.php', $redirectBaseUrl);
    } catch (Throwable $exception) {
        error_log('Google OAuth redirect configuration error: ' . $exception->getMessage());
        throw new RuntimeException('Google sign-in is temporarily unavailable.');
    }

    $config = [
        'client_id' => $clientId,
        'client_secret' => $clientSecret,
        'redirect_uri' => $redirectUri,
    ];

    return $config;
}
