<?php
declare(strict_types=1);

require_once __DIR__ . '/../payments/PaymentHelper.php';
require_once __DIR__ . '/app_url_helper.php';

/** Email navigation always targets the permanent public website. */
function itourEmailPublicBaseUrl(): string
{
    return 'https://itourmercedes.com';
}

/** Build a profile link on the public website, preserving its booking action. */
function itourEmailProfileUrl(array $query = [], string $fragment = ''): string
{
    $base = itourEmailPublicBaseUrl();
    if ($base === '') return '';
    return $base . '/php/profile.php' . ($query ? '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986) : '')
        . ($fragment !== '' ? '#' . rawurlencode($fragment) : '');
}

function itourEmailQuickLinks(): string
{
    $base = itourEmailPublicBaseUrl();
    if ($base === '') return '';
    $escape = static fn(string $url): string => htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
    return '<div style="margin-top:22px;text-align:center;font-size:12px;line-height:1.8;"><strong style="color:#31594d;">Quick links</strong><br>'
        . '<a style="color:#176b58;" href="' . $escape($base . '/') . '">Homepage</a> &nbsp;|&nbsp; '
        . '<a style="color:#176b58;" href="' . $escape(itourEmailProfileUrl(['section' => 'bookings'])) . '">My Bookings</a> &nbsp;|&nbsp; '
        . '<a style="color:#176b58;" href="' . $escape(itourEmailProfileUrl(['section' => 'cancel-bookings'])) . '">Cancellations &amp; Refunds</a></div>';
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
