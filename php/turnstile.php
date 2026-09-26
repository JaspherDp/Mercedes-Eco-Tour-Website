<?php
declare(strict_types=1);

require_once __DIR__ . '/../payments/PaymentHelper.php';
require_once __DIR__ . '/app_url_helper.php';

const ITOUR_TURNSTILE_VERIFY_URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';
const ITOUR_TURNSTILE_ERROR = 'Security verification failed. Please try again.';
const ITOUR_TURNSTILE_TEST_SITE_KEY_ALWAYS_PASS = '1x00000000000000000000AA';
const ITOUR_TURNSTILE_TEST_SECRET_KEY_ALWAYS_PASS = '1x0000000000000000000000000000000AA';

function ItourTurnstileIsLocalRequest(): bool
{
    if (PHP_SAPI === 'cli') return true;
    $authority = trim((string)($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? ''));
    $host = strtolower((string)(parse_url('http://' . $authority, PHP_URL_HOST) ?? ''));
    return in_array($host, ['localhost', '127.0.0.1', '::1'], true);
}

/** @return array{site_key:string,configured:bool,development_bypass:bool,local_test_verification:bool} */
function ItourTurnstileConfiguration(): array
{
    static $configuration = null;
    if (is_array($configuration)) return $configuration;

    $siteKey = PaymentHelper::env('CLOUDFLARE_TURNSTILE_SITE_KEY');
    $secretKey = PaymentHelper::env('CLOUDFLARE_TURNSTILE_SECRET_KEY');
    $configured = $siteKey !== '' && $secretKey !== '';
    $bothEmpty = $siteKey === '' && $secretKey === '';
    $alwaysPassTestPair = hash_equals(ITOUR_TURNSTILE_TEST_SITE_KEY_ALWAYS_PASS, $siteKey)
        && hash_equals(ITOUR_TURNSTILE_TEST_SECRET_KEY_ALWAYS_PASS, $secretKey);

    try {
        $isProduction = ItourAppIsProduction();
    } catch (Throwable $exception) {
        error_log('Turnstile treated an invalid application environment as production.');
        $isProduction = true;
    }

    return $configuration = [
        'site_key' => $siteKey,
        'configured' => $configured,
        'development_bypass' => $bothEmpty && !$isProduction && ItourTurnstileIsLocalRequest(),
        // Keep Cloudflare's visible test widget on localhost, but do not make
        // local development depend on outbound access to Siteverify. This mode
        // is impossible on a production environment or public hostname.
        'local_test_verification' => $alwaysPassTestPair && !$isProduction && ItourTurnstileIsLocalRequest(),
    ];
}

function ItourTurnstileSiteKey(): string
{
    return ItourTurnstileConfiguration()['site_key'];
}

function ItourTurnstileRemoteIp(): ?string
{
    $ip = trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));
    return filter_var($ip, FILTER_VALIDATE_IP) !== false ? $ip : null;
}

/**
 * Verify one single-use Turnstile token. Failures deliberately collapse to
 * false so Cloudflare diagnostics, tokens, and credentials never reach users.
 */
function verifyTurnstile(string $token, ?string $remoteIp = null): bool
{
    $configuration = ItourTurnstileConfiguration();
    if ($configuration['development_bypass']) return true;
    if (!$configuration['configured']) {
        error_log('Turnstile verification unavailable because server configuration is incomplete.');
        return false;
    }

    $token = trim($token);
    if ($token === '' || strlen($token) > 2048) return false;
    if ($configuration['local_test_verification']) return true;

    $fields = [
        'secret' => PaymentHelper::env('CLOUDFLARE_TURNSTILE_SECRET_KEY'),
        'response' => $token,
    ];
    if ($remoteIp !== null && filter_var($remoteIp, FILTER_VALIDATE_IP) !== false) {
        $fields['remoteip'] = $remoteIp;
    }

    try {
        $rawResponse = ItourTurnstileSiteverifyRequest($fields);
        if ($rawResponse === null || $rawResponse === '') return false;
        $result = json_decode($rawResponse, true, 16, JSON_THROW_ON_ERROR);
        return is_array($result) && ($result['success'] ?? false) === true;
    } catch (Throwable $exception) {
        error_log('Turnstile Siteverify request failed.');
        return false;
    }
}

/** @param array<string,string> $fields */
function ItourTurnstileSiteverifyRequest(array $fields): ?string
{
    $body = http_build_query($fields, '', '&', PHP_QUERY_RFC3986);
    if (function_exists('curl_init')) {
        $curl = curl_init(ITOUR_TURNSTILE_VERIFY_URL);
        if ($curl === false) return null;
        $curlOptions = [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 6,
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
        ];
        $caBundle = PaymentHelper::env('PAYMONGO_CA_BUNDLE');
        if ($caBundle !== '' && is_file($caBundle) && is_readable($caBundle)) {
            $curlOptions[CURLOPT_CAINFO] = $caBundle;
        }
        curl_setopt_array($curl, $curlOptions);
        $response = curl_exec($curl);
        $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);
        return is_string($response) && $status >= 200 && $status < 300 ? $response : null;
    }

    $streamOptions = ['http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/x-www-form-urlencoded\r\nConnection: close\r\n",
            'content' => $body,
            'timeout' => 6,
            'ignore_errors' => true,
        ],
        'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
    ];
    $caBundle = PaymentHelper::env('PAYMONGO_CA_BUNDLE');
    if ($caBundle !== '' && is_file($caBundle) && is_readable($caBundle)) {
        $streamOptions['ssl']['cafile'] = $caBundle;
    }
    $context = stream_context_create($streamOptions);
    $response = @file_get_contents(ITOUR_TURNSTILE_VERIFY_URL, false, $context);
    return is_string($response) ? $response : null;
}

function ItourTurnstileRequestPassed(): bool
{
    $token = $_POST['cf-turnstile-response'] ?? '';
    return is_string($token) && verifyTurnstile($token, ItourTurnstileRemoteIp());
}
