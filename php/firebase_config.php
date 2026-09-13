<?php
declare(strict_types=1);

require_once __DIR__ . '/../payments/PaymentHelper.php';

/**
 * Returns only Firebase values that are safe to expose in browser JavaScript.
 * The service-account path and all private credentials are intentionally absent.
 *
 * @return array{configured: bool, app_url: string, firebase: array<string, string>, vapid_public_key: string}
 */
function firebase_public_configuration(): array
{
    $appUrl = PaymentHelper::env('APP_URL');
    if ($appUrl === '') {
        $appUrl = PaymentHelper::env('PUBLIC_APP_URL');
    }
    $appUrl = rtrim($appUrl, '/');

    $firebase = [
        'apiKey' => PaymentHelper::env('FIREBASE_API_KEY'),
        'authDomain' => PaymentHelper::env('FIREBASE_AUTH_DOMAIN'),
        'projectId' => PaymentHelper::env('FIREBASE_PROJECT_ID'),
        'storageBucket' => PaymentHelper::env('FIREBASE_STORAGE_BUCKET'),
        'messagingSenderId' => PaymentHelper::env('FIREBASE_MESSAGING_SENDER_ID'),
        'appId' => PaymentHelper::env('FIREBASE_APP_ID'),
    ];
    $vapidPublicKey = PaymentHelper::env('FIREBASE_VAPID_PUBLIC_KEY');

    $appParts = parse_url($appUrl);
    $validAppUrl = filter_var($appUrl, FILTER_VALIDATE_URL) !== false
        && strtolower((string)($appParts['scheme'] ?? '')) === 'https'
        && (string)($appParts['host'] ?? '') !== '';

    return [
        'configured' => $validAppUrl
            && $vapidPublicKey !== ''
            && !in_array('', array_values($firebase), true),
        'app_url' => $validAppUrl ? $appUrl : '',
        'firebase' => $firebase,
        'vapid_public_key' => $vapidPublicKey,
    ];
}

