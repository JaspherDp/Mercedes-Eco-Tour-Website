<?php
declare(strict_types=1);

require_once __DIR__ . '/../payments/PaymentHelper.php';

/**
 * Return the configured runtime environment. Existing XAMPP installations
 * without APP_ENV remain development-only; every other unconfigured host is
 * treated as production and therefore fails closed for absolute URLs.
 */
function ItourAppEnvironment(): string
{
    $configured = strtolower(trim(PaymentHelper::env('APP_ENV')));
    if (in_array($configured, ['production', 'prod'], true)) return 'production';
    if (in_array($configured, ['development', 'dev', 'local', 'testing', 'test'], true)) return 'development';
    if ($configured !== '') {
        throw new RuntimeException('The application environment is invalid.');
    }

    $projectPath = strtolower(str_replace('\\', '/', dirname(__DIR__)));
    return str_contains($projectPath, '/xampp/htdocs/') ? 'development' : 'production';
}

function ItourAppIsProduction(): bool
{
    return ItourAppEnvironment() === 'production';
}

/** Validate and normalize a configured application base URL. */
function ItourValidateAppBaseUrl(string $value, bool $production): string
{
    $value = rtrim(trim($value), '/');
    $parts = $value !== '' ? parse_url($value) : false;
    if (!is_array($parts) || filter_var($value, FILTER_VALIDATE_URL) === false
        || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
        throw new RuntimeException('The canonical application URL is invalid.');
    }

    $scheme = strtolower((string)($parts['scheme'] ?? ''));
    $host = strtolower((string)($parts['host'] ?? ''));
    $localHost = in_array($host, ['localhost', '127.0.0.1', '::1'], true);
    if ($host === '' || !in_array($scheme, ['http', 'https'], true)) {
        throw new RuntimeException('The canonical application URL is invalid.');
    }
    if ($production && ($scheme !== 'https' || $localHost)) {
        throw new RuntimeException('Production requires a public HTTPS application URL.');
    }
    if (!$production && $scheme === 'http' && !$localHost) {
        throw new RuntimeException('Development HTTP application URLs must use localhost.');
    }
    return $value;
}

/**
 * Resolve the trusted canonical URL. No Host or forwarded header is used.
 * Production fails closed when configuration is absent or unsafe.
 */
function ItourCanonicalAppUrl(): string
{
    $production = ItourAppIsProduction();
    foreach (['APP_URL', 'PUBLIC_APP_URL'] as $name) {
        $configured = PaymentHelper::env($name);
        if ($configured !== '') return ItourValidateAppBaseUrl($configured, $production);
    }

    if (!$production) {
        $local = PaymentHelper::env('LOCAL_APP_URL', 'http://localhost/Mercedes%20Eco%20Tour%20Website');
        return ItourValidateAppBaseUrl($local, false);
    }
    throw new RuntimeException('The production application URL is not configured.');
}

/** Return the explicit browser destination used after a PayMongo return. */
function ItourPaymentReturnBaseUrl(): string
{
    if (ItourAppIsProduction()) return ItourCanonicalAppUrl();
    $local = PaymentHelper::env('LOCAL_APP_URL', 'http://localhost/Mercedes%20Eco%20Tour%20Website');
    $local = ItourValidateAppBaseUrl($local, false);
    $host = strtolower((string)(parse_url($local, PHP_URL_HOST) ?? ''));
    if (!in_array($host, ['localhost', '127.0.0.1', '::1'], true)) {
        throw new RuntimeException('The local payment return URL must use localhost.');
    }
    return $local;
}

function ItourAppUrl(string $path, ?string $baseUrl = null): string
{
    $baseUrl ??= ItourCanonicalAppUrl();
    if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $path) || str_contains($path, "\r") || str_contains($path, "\n")) {
        throw new InvalidArgumentException('Only application-relative URL paths are allowed.');
    }
    return rtrim($baseUrl, '/') . '/' . ltrim($path, '/');
}

/** Fail closed without leaking deployment details into an email or response. */
function ItourTryCanonicalAppUrl(string $context = 'absolute application URL'): string
{
    try {
        return ItourCanonicalAppUrl();
    } catch (Throwable $exception) {
        error_log('iTour configuration error while creating ' . $context . ': ' . $exception->getMessage());
        return '';
    }
}

