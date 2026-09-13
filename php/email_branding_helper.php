<?php
declare(strict_types=1);

require_once __DIR__ . '/../payments/PaymentHelper.php';
require_once __DIR__ . '/app_url_helper.php';

/** Resolve the public website URL used by email links and remotely hosted images. */
function itourEmailPublicBaseUrl(): string
{
    return ItourTryCanonicalAppUrl('email public base URL');
}

/** Return a non-attached, cache-busted public URL for an email brand asset. */
function itourEmailAssetUrl(string $relativePath, string $overrideEnvironment = ''): string
{
    if ($overrideEnvironment !== '') {
        $override = trim(PaymentHelper::env($overrideEnvironment));
        if (preg_match('#^https://#i', $override)) return $override;
    }

    $relativePath = ltrim(str_replace('\\', '/', $relativePath), '/');
    $localPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
    $version = is_file($localPath) ? substr((string)hash_file('sha256', $localPath), 0, 16) : (string)time();
    // Gmail can proxy raw GitHub assets reliably, unlike temporary ngrok URLs.
    return 'https://raw.githubusercontent.com/JaspherDp/Mercedes-Eco-Tour-Website/main/' . $relativePath
        . '?v=' . rawurlencode($version);
}
