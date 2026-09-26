<?php
declare(strict_types=1);

require_once __DIR__ . '/PaymentHelper.php';
require_once __DIR__ . '/../php/secure_dns_resolver.php';

final class PayMongoException extends RuntimeException
{
    /** @param array<string, mixed> $response */
    public function __construct(
        string $message,
        private int $httpStatus = 0,
        private array $response = [],
        ?Throwable $previous = null
    ) {
        parent::__construct($message, $httpStatus, $previous);
    }

    public function getHttpStatus(): int
    {
        return $this->httpStatus;
    }

    /** @return array<string, mixed> */
    public function getResponse(): array
    {
        return $this->response;
    }
}

final class PayMongoService
{
    private const API_BASE_URL = 'https://api.paymongo.com';
    private int $lastHttpStatus = 0;

    public function __construct(
        private string $secretKey,
        private string $publicKey,
        private string $publicBaseUrl = '',
        private string $webhookSecret = '',
        private int $timeoutSeconds = 30,
        private string $caBundlePath = '',
        private string $resolveIp = '',
        private string $dnsServers = '',
        private string $caDirectoryPath = ''
    ) {
        if ($secretKey !== '' && !str_starts_with($secretKey, 'sk_test_')) {
            throw new InvalidArgumentException('Only a PayMongo sk_test_ secret key is permitted.');
        }
        if ($publicKey !== '' && !str_starts_with($publicKey, 'pk_test_')) {
            throw new InvalidArgumentException('Only a PayMongo pk_test_ public key is permitted.');
        }
        if ($webhookSecret !== '' && !str_starts_with($webhookSecret, 'whsk_')) {
            throw new InvalidArgumentException('PAYMONGO_WEBHOOK_SECRET must be the secret issued by PayMongo.');
        }
        if ($timeoutSeconds < 1 || $timeoutSeconds > 120) {
            throw new InvalidArgumentException('PayMongo request timeout must be between 1 and 120 seconds.');
        }
        if ($caBundlePath !== '' && (!is_file($caBundlePath) || !is_readable($caBundlePath))) {
            throw new InvalidArgumentException('PAYMONGO_CA_BUNDLE must point to a readable CA certificate file.');
        }
        if ($caDirectoryPath !== '' && (!is_dir($caDirectoryPath) || !is_readable($caDirectoryPath))) {
            throw new InvalidArgumentException('PAYMONGO_CA_PATH must point to a readable hashed CA directory.');
        }
        if ($resolveIp !== '' && filter_var($resolveIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            throw new InvalidArgumentException('PAYMONGO_API_RESOLVE must be a valid IPv4 address.');
        }
        if ($dnsServers !== '') {
            $servers = array_filter(array_map('trim', explode(',', $dnsServers)));
            if ($servers === [] || count($servers) > 4) {
                throw new InvalidArgumentException('PAYMONGO_DNS_SERVERS must contain one to four DNS server addresses.');
            }
            foreach ($servers as $server) {
                if (filter_var($server, FILTER_VALIDATE_IP) === false) {
                    throw new InvalidArgumentException('PAYMONGO_DNS_SERVERS contains an invalid address.');
                }
            }
            $this->dnsServers = implode(',', $servers);
        }
    }

    public static function fromEnvironment(?string $envPath = null): self
    {
        return new self(
            PaymentHelper::env('PAYMONGO_SECRET_KEY', '', $envPath),
            PaymentHelper::env('PAYMONGO_PUBLIC_KEY', '', $envPath),
            PaymentHelper::env('PAYMONGO_PUBLIC_BASE_URL', '', $envPath),
            PaymentHelper::env('PAYMONGO_WEBHOOK_SECRET', '', $envPath),
            (int)PaymentHelper::env('PAYMONGO_TIMEOUT_SECONDS', '30', $envPath),
            PaymentHelper::env('PAYMONGO_CA_BUNDLE', '', $envPath),
            PaymentHelper::env('PAYMONGO_API_RESOLVE', '', $envPath),
            PaymentHelper::env('PAYMONGO_DNS_SERVERS', '', $envPath),
            PaymentHelper::env('PAYMONGO_CA_PATH', '', $envPath)
        );
    }

    public function isConfigured(): bool
    {
        return str_starts_with($this->secretKey, 'sk_test_')
            && str_starts_with($this->publicKey, 'pk_test_');
    }

    public function getPublicKey(): string
    {
        if (!str_starts_with($this->publicKey, 'pk_test_')) {
            throw new LogicException('PAYMONGO_PUBLIC_KEY is not configured with a test key.');
        }
        return $this->publicKey;
    }

    public function getLastHttpStatus(): int
    {
        return $this->lastHttpStatus;
    }

    public function publicUrl(string $path = ''): string
    {
        return PaymentHelper::publicHttpsUrl($this->publicBaseUrl, $path);
    }

    /**
     * Creates a PayMongo Hosted Checkout Session through the currently
     * recommended V2 endpoint. This method is not connected to booking pages.
     *
     * @param array<string, mixed> $attributes
     * @return array<string, mixed>
     */
    public function createCheckoutSession(array $attributes, ?string $idempotencyKey = null): array
    {
        foreach (['line_items', 'payment_method_types', 'success_url', 'cancel_url', 'reference_number'] as $required) {
            if (!array_key_exists($required, $attributes) || $attributes[$required] === '' || $attributes[$required] === []) {
                throw new InvalidArgumentException("Checkout Session attribute '{$required}' is required.");
            }
        }

        PaymentHelper::publicHttpsUrl((string)$attributes['success_url']);
        PaymentHelper::publicHttpsUrl((string)$attributes['cancel_url']);

        return $this->request('POST', '/v2/checkout_sessions', [
            'data' => ['attributes' => $attributes],
        ], $idempotencyKey);
    }

    /** @return array<string, mixed> */
    public function retrieveCheckoutSession(string $checkoutSessionId): array
    {
        $checkoutSessionId = trim($checkoutSessionId);
        if (!preg_match('/^cs_[A-Za-z0-9]+$/', $checkoutSessionId)) {
            throw new InvalidArgumentException('PayMongo Checkout Session ID is invalid.');
        }

        return $this->request('GET', '/v1/checkout_sessions/' . rawurlencode($checkoutSessionId));
    }

    /** @return array<string, mixed> */
    public function expireCheckoutSession(string $checkoutSessionId): array
    {
        $checkoutSessionId = trim($checkoutSessionId);
        if (!preg_match('/^cs_[A-Za-z0-9]+$/', $checkoutSessionId)) {
            throw new InvalidArgumentException('PayMongo Checkout Session ID is invalid.');
        }

        return $this->request(
            'POST',
            '/v1/checkout_sessions/' . rawurlencode($checkoutSessionId) . '/expire'
        );
    }

    /**
     * Return money to the original payment method used for a paid PayMongo payment.
     * Amounts are expressed in the currency's minor unit (centavos for PHP).
     *
     * @return array<string, mixed>
     */
    public function createRefund(
        string $paymentId,
        int $amountMinor,
        string $reason = 'others',
        string $notes = '',
        ?string $idempotencyKey = null
    ): array {
        $paymentId = trim($paymentId);
        if (!preg_match('/^pay_[A-Za-z0-9]+$/', $paymentId)) {
            throw new InvalidArgumentException('PayMongo Payment ID is invalid.');
        }
        if ($amountMinor < 100) {
            throw new InvalidArgumentException('A PayMongo refund must be at least PHP 1.00.');
        }
        $allowedReasons = ['duplicate', 'fraudulent', 'others'];
        if (!in_array($reason, $allowedReasons, true)) {
            throw new InvalidArgumentException('The PayMongo refund reason is invalid.');
        }

        $attributes = [
            'amount' => $amountMinor,
            'payment_id' => $paymentId,
            'reason' => $reason,
        ];
        $notes = trim($notes);
        if ($notes !== '') $attributes['notes'] = mb_substr($notes, 0, 255);

        return $this->request('POST', '/v1/refunds', [
            'data' => ['attributes' => $attributes],
        ], $idempotencyKey);
    }

    /** @return array<string, mixed> */
    public function createQrPhRefund(
        string $paymentId,
        int $amountMinor,
        string $reason = 'others',
        string $notes = '',
        ?string $idempotencyKey = null
    ): array {
        $paymentId = trim($paymentId);
        if (!preg_match('/^pay_[A-Za-z0-9]+$/', $paymentId)) throw new InvalidArgumentException('PayMongo Payment ID is invalid.');
        if ($amountMinor < 100) throw new InvalidArgumentException('A PayMongo refund must be at least PHP 1.00.');
        if (!in_array($reason, ['duplicate', 'fraudulent', 'others', 'requested_by_customer'], true)) {
            throw new InvalidArgumentException('The PayMongo QR Ph refund reason is invalid.');
        }
        $attributes = ['payment_id' => $paymentId, 'amount' => $amountMinor, 'reason' => $reason];
        if (trim($notes) !== '') $attributes['notes'] = mb_substr(trim($notes), 0, 255);
        return $this->request('POST', '/v1/refunds', ['data' => ['attributes' => $attributes]], $idempotencyKey);
    }

    /** @return array<string, mixed> */
    public function retrieveRefund(string $refundId): array
    {
        $refundId = trim($refundId);
        if (!preg_match('/^ref_[A-Za-z0-9]+$/', $refundId)) {
            throw new InvalidArgumentException('PayMongo Refund ID is invalid.');
        }
        return $this->request('GET', '/v1/refunds/' . rawurlencode($refundId));
    }

    /** @return array<string, mixed> */
    public function retrieveQrPhRefund(string $refundId, string $paymentId): array
    {
        $refundId = trim($refundId);
        if (!preg_match('/^ref_[A-Za-z0-9]+$/', $refundId)) throw new InvalidArgumentException('PayMongo Refund ID is invalid.');
        if (!preg_match('/^pay_[A-Za-z0-9]+$/', trim($paymentId))) throw new InvalidArgumentException('PayMongo Payment ID is invalid.');
        return $this->retrieveRefund($refundId);
    }

    /** @return array<string, mixed> */
    public function retrievePayment(string $paymentId): array
    {
        $paymentId = trim($paymentId);
        if (!preg_match('/^pay_[A-Za-z0-9]+$/', $paymentId)) {
            throw new InvalidArgumentException('PayMongo Payment ID is invalid.');
        }
        return $this->request('GET', '/v1/payments/' . rawurlencode($paymentId));
    }

    /** @return array<string, mixed> */
    public function listReceivingInstitutions(string $provider = 'instapay'): array
    {
        $provider = strtolower(trim($provider));
        if (!in_array($provider, ['instapay', 'pesonet'], true)) {
            throw new InvalidArgumentException('The transfer rail is invalid.');
        }
        return $this->request('GET', '/v1/wallets/receiving_institutions?provider=' . rawurlencode($provider));
    }

    /**
     * Registers one webhook endpoint. Call this once for an environment, not
     * per transaction. PayMongo returns the signing secret after creation.
     *
     * @param list<string> $events
     * @return array<string, mixed>
     */
    public function createWebhook(string $url, array $events): array
    {
        PaymentHelper::publicHttpsUrl($url);
        $events = array_values(array_unique(array_filter(array_map('trim', $events))));
        if ($events === []) {
            throw new InvalidArgumentException('At least one webhook event is required.');
        }

        return $this->request('POST', '/v1/webhooks', [
            'data' => ['attributes' => ['url' => $url, 'events' => $events]],
        ]);
    }

    /**
     * Updates an existing webhook endpoint. At least one attribute is required.
     * This method is intentionally not called during normal application requests.
     *
     * @param list<string>|null $events
     * @return array<string, mixed>
     */
    public function updateWebhook(string $webhookId, ?string $url = null, ?array $events = null): array
    {
        $webhookId = trim($webhookId);
        if (!preg_match('/^hook_[A-Za-z0-9]+$/', $webhookId)) {
            throw new InvalidArgumentException('PayMongo Webhook ID is invalid.');
        }
        $attributes = [];
        if ($url !== null) {
            $attributes['url'] = PaymentHelper::publicHttpsUrl($url);
        }
        if ($events !== null) {
            $events = array_values(array_unique(array_filter(array_map('trim', $events))));
            if ($events === []) throw new InvalidArgumentException('At least one webhook event is required.');
            $attributes['events'] = $events;
        }
        if ($attributes === []) throw new InvalidArgumentException('A webhook URL or event list is required.');

        return $this->request('PUT', '/v1/webhooks/' . rawurlencode($webhookId), [
            'data' => ['attributes' => $attributes],
        ]);
    }

    public function verifyTestWebhook(string $rawBody, string $signatureHeader, int $toleranceSeconds = 300): bool
    {
        return PaymentHelper::verifyPayMongoTestSignature(
            $rawBody,
            $signatureHeader,
            $this->webhookSecret,
            $toleranceSeconds
        );
    }

    /** @return list<string> */
    private function resolveApiAddresses(string $apiHost = 'api.paymongo.com'): array
    {
        $localCaBundle = dirname(__DIR__) . '/certs/firebase-ca-bundle.pem';
        $dohCaBundle = is_file($localCaBundle) && is_readable($localCaBundle)
            ? $localCaBundle
            : $this->caBundlePath;
        $sharedAddresses = itourSecureDnsResolve($apiHost, $dohCaBundle);
        if ($sharedAddresses !== []) return $sharedAddresses;

        $cachePath = rtrim(sys_get_temp_dir(), '\\/') . DIRECTORY_SEPARATOR
            . 'itour_paymongo_dns_' . hash('sha256', $apiHost) . '.json';
        $sharedCachePath = rtrim(sys_get_temp_dir(), '\\/') . DIRECTORY_SEPARATOR
            . 'itour_firebase_dns_' . hash('sha256', 'api.paymongo.com') . '.json';
        $cacheCandidates = $apiHost === 'api.paymongo.com' ? [$cachePath, $sharedCachePath] : [$cachePath];
        foreach ($cacheCandidates as $candidateCachePath) {
            if (!is_file($candidateCachePath) || !is_readable($candidateCachePath)) continue;
            $cached = json_decode((string)@file_get_contents($candidateCachePath), true);
            if (is_array($cached) && (int)($cached['expires_at'] ?? 0) > time()) {
                $addresses = array_values(array_filter(
                    is_array($cached['addresses'] ?? null) ? $cached['addresses'] : [],
                    static fn($address): bool => is_string($address)
                        && filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false
                ));
                if ($addresses !== []) return $addresses;
            }
        }

        foreach (['8.8.8.8', '8.8.4.4'] as $googleDnsIp) {
            $curl = curl_init('https://dns.google/resolve?name=' . rawurlencode($apiHost) . '&type=A');
            if ($curl === false) continue;
            $options = [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_RESOLVE => ['dns.google:443:' . $googleDnsIp],
                CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
                CURLOPT_CONNECTTIMEOUT => 8,
                CURLOPT_TIMEOUT => 15,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_HTTPHEADER => ['Accept: application/dns-json'],
            ];
            if ($dohCaBundle !== '') $options[CURLOPT_CAINFO] = $dohCaBundle;
            curl_setopt_array($curl, $options);
            $body = curl_exec($curl);
            $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            $error = curl_errno($curl);
            curl_close($curl);
            if (!is_string($body) || $error !== CURLE_OK || $status !== 200) continue;
            $response = json_decode($body, true);
            if (!is_array($response) || (int)($response['Status'] ?? -1) !== 0) continue;
            $addresses = [];
            foreach (($response['Answer'] ?? []) as $answer) {
                $address = is_array($answer) ? trim((string)($answer['data'] ?? '')) : '';
                if ((int)($answer['type'] ?? 0) === 1
                    && filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                    $addresses[] = $address;
                }
            }
            $addresses = array_values(array_unique($addresses));
            if ($addresses === []) continue;
            $cache = json_encode(['addresses' => $addresses, 'expires_at' => time() + 300]);
            if (is_string($cache) && @file_put_contents($cachePath, $cache, LOCK_EX) !== false) {
                @chmod($cachePath, 0600);
            }
            return $addresses;
        }
        return [];
    }

    /**
     * @param array<string, mixed>|null $payload
     * @return array<string, mixed>
     */
    private function request(
        string $method,
        string $path,
        ?array $payload = null,
        ?string $idempotencyKey = null,
        string $apiBaseUrl = self::API_BASE_URL
    ): array
    {
        $this->lastHttpStatus = 0;
        if (!$this->isConfigured()) {
            throw new LogicException('PayMongo test keys are not configured in the private server environment.');
        }
        if (!function_exists('curl_init')) {
            throw new LogicException('The PHP cURL extension is required for PayMongo API requests.');
        }
        if ($idempotencyKey !== null) {
            $idempotencyKey = trim($idempotencyKey);
            if ($idempotencyKey === '' || strlen($idempotencyKey) > 255 || preg_match('/[^A-Za-z0-9._:-]/', $idempotencyKey)) {
                throw new InvalidArgumentException('PayMongo idempotency key is invalid.');
            }
        }

        $headers = ['Accept: application/json', 'Content-Type: application/json'];
        if ($idempotencyKey !== null) {
            $headers[] = 'Idempotency-Key: ' . $idempotencyKey;
        }
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
            CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
            CURLOPT_USERPWD => $this->secretKey . ':',
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ];
        if ($payload !== null) {
            $options[CURLOPT_POSTFIELDS] = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        }
        if ($this->caBundlePath !== '') {
            $options[CURLOPT_CAINFO] = $this->caBundlePath;
        }
        if ($this->caDirectoryPath !== '') {
            $options[CURLOPT_CAPATH] = $this->caDirectoryPath;
        }
        if ($this->dnsServers !== '') {
            $options[CURLOPT_DNS_SERVERS] = $this->dnsServers;
        }
        if ($apiBaseUrl !== self::API_BASE_URL) {
            throw new InvalidArgumentException('The PayMongo API host is not allowed.');
        }
        $apiHost = (string)parse_url($apiBaseUrl, PHP_URL_HOST);
        $resolvedAddresses = $this->resolveIp !== '' && $apiHost === 'api.paymongo.com'
            ? [$this->resolveIp]
            : $this->resolveApiAddresses($apiHost);
        $method = strtoupper($method);
        $safeToRetry = $method === 'GET' || $idempotencyKey !== null;
        $maxAttempts = $safeToRetry ? 3 : 1;
        $retryableCurlErrors = [CURLE_COULDNT_RESOLVE_HOST, CURLE_COULDNT_CONNECT, CURLE_OPERATION_TIMEDOUT];
        $responseBody = false;
        $httpStatus = 0;
        $curlError = '';

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $curl = curl_init($apiBaseUrl . $path);
            if ($curl === false) {
                throw new PayMongoException('Unable to initialize the PayMongo request.');
            }
            $attemptOptions = $options;
            if ($resolvedAddresses !== []) {
                $address = $resolvedAddresses[($attempt - 1) % count($resolvedAddresses)];
                $attemptOptions[CURLOPT_RESOLVE] = [$apiHost . ':443:' . $address];
            }
            foreach ($attemptOptions as $curlOption => $curlValue) {
                if (curl_setopt($curl, $curlOption, $curlValue)) continue;
                if (defined('CURLOPT_DNS_SERVERS') && $curlOption === CURLOPT_DNS_SERVERS) continue;
                curl_close($curl);
                throw new PayMongoException('PayMongo networking could not be configured.');
            }
            $responseBody = curl_exec($curl);
            $httpStatus = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            $this->lastHttpStatus = $httpStatus;
            $curlErrorNumber = curl_errno($curl);
            $curlError = curl_error($curl);
            curl_close($curl);

            if (is_string($responseBody)
                || $attempt === $maxAttempts
                || !in_array($curlErrorNumber, $retryableCurlErrors, true)) {
                break;
            }
        }

        if (!is_string($responseBody)) {
            throw new PayMongoException('PayMongo could not be reached' . ($curlError !== '' ? ': ' . $curlError : '.'));
        }

        try {
            $response = json_decode($responseBody, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new PayMongoException("PayMongo returned invalid JSON (HTTP {$httpStatus}).", $httpStatus, [], $exception);
        }

        if (!is_array($response)) {
            throw new PayMongoException("PayMongo returned an unexpected response (HTTP {$httpStatus}).", $httpStatus);
        }
        if ($httpStatus < 200 || $httpStatus >= 300) {
            $attributes = is_array($response['data']['attributes'] ?? null) ? $response['data']['attributes'] : [];
            $detail = trim((string)($response['errors'][0]['detail'] ?? $attributes['failure_message'] ?? $attributes['error_message'] ?? ''));
            if ($detail === '' && strtolower((string)($attributes['status'] ?? '')) === 'failed') {
                $detail = 'PayMongo created the refund with a failed status but did not provide a reason.';
            }
            if ($detail === '') $detail = 'The PayMongo API rejected the request.';
            throw new PayMongoException($detail, $httpStatus, $response);
        }

        $livemode = $response['data']['attributes']['livemode'] ?? null;
        if ($livemode === true) {
            throw new PayMongoException('A live-mode PayMongo resource was rejected.', $httpStatus, $response);
        }

        return $response;
    }
}
