<?php
declare(strict_types=1);

final class PaymentHelper
{
    /** @var array<string, array<string, string>> */
    private static array $environments = [];

    public static function environmentPath(): ?string
    {
        $projectRoot = dirname(__DIR__);
        $documentRoot = trim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''));
        if ($documentRoot === '') {
            $parent = dirname($projectRoot);
            if (in_array(strtolower(basename($parent)), ['htdocs', 'public_html', 'www', 'html'], true)) {
                $documentRoot = $parent;
            }
        }

        $outsideDocumentRoot = static function (string $path) use ($documentRoot): bool {
            $normalize = static fn(string $value): string => strtolower(rtrim(str_replace('\\', '/', $value), '/'));
            $candidate = $normalize(realpath($path) ?: $path);
            $publicRoot = $normalize(($documentRoot !== '' ? realpath($documentRoot) : false) ?: $documentRoot);
            return $candidate !== '' && ($publicRoot === ''
                || ($candidate !== $publicRoot && !str_starts_with($candidate . '/', $publicRoot . '/')));
        };

        $configuredPath = trim((string)getenv('ITOUR_PRIVATE_ENV_PATH'));
        if ($configuredPath !== '' && $outsideDocumentRoot($configuredPath)
            && is_file($configuredPath) && is_readable($configuredPath)) {
            return $configuredPath;
        }

        $privateCandidates = [
            dirname($projectRoot) . DIRECTORY_SEPARATOR . 'private' . DIRECTORY_SEPARATOR . 'itour-mercedes.env',
            dirname($projectRoot, 2) . DIRECTORY_SEPARATOR . 'private' . DIRECTORY_SEPARATOR . 'itour-mercedes.env',
            dirname($projectRoot, 3) . DIRECTORY_SEPARATOR . 'private' . DIRECTORY_SEPARATOR . 'itour-mercedes.env',
        ];
        foreach (array_unique($privateCandidates) as $candidate) {
            if ($outsideDocumentRoot($candidate) && is_file($candidate) && is_readable($candidate)) {
                return $candidate;
            }
        }

        // XAMPP is the only supported web-root fallback. Apache protection in
        // the project .htaccess prevents this local file from being served.
        $normalizedProjectRoot = strtolower(str_replace('\\', '/', $projectRoot));
        $localFallback = $projectRoot . DIRECTORY_SEPARATOR . '.env';
        if ((PHP_SAPI === 'cli' || str_contains($normalizedProjectRoot, '/xampp/htdocs/'))
            && is_file($localFallback) && is_readable($localFallback)) {
            return $localFallback;
        }

        return null;
    }

    /**
     * Loads the selected private KEY=VALUE environment file without
     * overwriting real process environment variables.
     *
     * @return array<string, string>
     */
    public static function loadEnvironment(?string $path = null): array
    {
        $path ??= self::environmentPath();
        if ($path === null || $path === '') return [];
        $cacheKey = str_replace('\\', '/', $path);
        if (isset(self::$environments[$cacheKey])) return self::$environments[$cacheKey];
        $values = [];

        if (is_file($path) && is_readable($path)) {
            foreach (file($path, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
                $line = trim($line);
                if ($line === '' || str_starts_with($line, '#')) {
                    continue;
                }

                if (str_starts_with($line, 'export ')) {
                    $line = trim(substr($line, 7));
                }
                if (!str_contains($line, '=')) {
                    continue;
                }

                [$name, $value] = array_map('trim', explode('=', $line, 2));
                if (!preg_match('/^[A-Z_][A-Z0-9_]*$/i', $name)) {
                    continue;
                }

                if (strlen($value) >= 2) {
                    $quote = $value[0];
                    if (($quote === '"' || $quote === "'") && str_ends_with($value, $quote)) {
                        $value = substr($value, 1, -1);
                        if ($quote === '"') {
                            $value = str_replace(['\\n', '\\r', '\\"', '\\\\'], ["\n", "\r", '"', '\\'], $value);
                        }
                    }
                }

                $values[$name] = $value;
            }
        }

        return self::$environments[$cacheKey] = $values;
    }

    public static function env(string $name, string $default = '', ?string $path = null): string
    {
        $processValue = getenv($name);
        if (is_string($processValue) && $processValue !== '') {
            return trim($processValue);
        }

        $values = self::loadEnvironment($path);
        return trim((string)($values[$name] ?? $default));
    }

    public static function amountToCentavos(int|float|string $amount): int
    {
        if (!is_numeric($amount)) {
            throw new InvalidArgumentException('Payment amount must be numeric.');
        }

        $centavos = (int)round((float)$amount * 100, 0, PHP_ROUND_HALF_UP);
        if ($centavos < 1) {
            throw new InvalidArgumentException('Payment amount must be at least PHP 0.01.');
        }

        return $centavos;
    }

    public static function normalizePayMongoMode(string $mode): string
    {
        $mode = strtolower(trim($mode));
        if (!in_array($mode, ['test', 'live'], true)) {
            throw new InvalidArgumentException('PAYMONGO_MODE must be test or live.');
        }
        return $mode;
    }

    public static function payMongoMode(?string $envPath = null): string
    {
        // Existing installations remain in test mode until explicitly switched.
        return self::normalizePayMongoMode(self::env('PAYMONGO_MODE', 'test', $envPath));
    }

    public static function payMongoIsLiveMode(?string $envPath = null): bool
    {
        return self::payMongoMode($envPath) === 'live';
    }

    public static function assertPayMongoLivemode(mixed $livemode, ?bool $expected = null): void
    {
        if (!is_bool($livemode) || $livemode !== ($expected ?? self::payMongoIsLiveMode())) {
            throw new UnexpectedValueException('PayMongo environment mismatch or missing boolean livemode.');
        }
    }

    /** Validate every supplied flag, including nested payments/refunds. */
    public static function assertPayMongoResourceMode(array $resource, ?bool $expected = null): void
    {
        $expected ??= self::payMongoIsLiveMode();
        foreach ($resource as $key => $value) {
            if ($key === 'livemode') self::assertPayMongoLivemode($value, $expected);
            elseif (is_array($value)) self::assertPayMongoResourceMode($value, $expected);
        }
    }

    /** Metadata without a marker belongs to the original test-only integration. */
    public static function assertPayMongoTransactionMode(mixed $metadata, ?bool $expected = null): void
    {
        if (is_string($metadata)) {
            $metadata = json_decode($metadata, true, 512, JSON_THROW_ON_ERROR);
        }
        if ($metadata !== null && !is_array($metadata)) {
            throw new UnexpectedValueException('Invalid PayMongo transaction metadata.');
        }
        $mode = is_array($metadata) && array_key_exists('paymongo_mode', $metadata)
            ? $metadata['paymongo_mode'] : 'test';
        if (!is_string($mode) || !in_array($mode, ['test', 'live'], true)) {
            throw new UnexpectedValueException('Invalid stored PayMongo mode.');
        }
        self::assertPayMongoLivemode($mode === 'live', $expected);
    }

    /** A booking funded in one mode cannot receive payments in the other. */
    public static function assertPayMongoBookingMode(PDO $pdo, string $domain, int $bookingId): void
    {
        if ($bookingId < 1) return;
        $statement = $pdo->prepare(
            "SELECT metadata FROM payment_transactions
             WHERE provider='paymongo' AND booking_domain=? AND booking_id=?
               AND status IN ('paid','succeeded','completed')"
        );
        $statement->execute([$domain, $bookingId]);
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            self::assertPayMongoTransactionMode($row['metadata'] ?? null);
        }
    }

    /** A webhook may credit the transaction before checkout creation returns. */
    public static function linkPayMongoCheckout(PDO $pdo, int $transactionId, int $touristId, string $sessionId, string $checkoutUrl): void
    {
        $save = $pdo->prepare(
            'UPDATE payment_transactions SET provider_checkout_session_id=?, checkout_url=?
             WHERE payment_transaction_id=? AND tourist_id=?
               AND (provider_checkout_session_id IS NULL OR provider_checkout_session_id=?)'
        );
        $save->execute([$sessionId, $checkoutUrl, $transactionId, $touristId, $sessionId]);
        if ($save->rowCount() === 1) return;
        $verify = $pdo->prepare('SELECT provider_checkout_session_id,checkout_url FROM payment_transactions WHERE payment_transaction_id=? AND tourist_id=?');
        $verify->execute([$transactionId, $touristId]);
        $saved = $verify->fetch(PDO::FETCH_ASSOC);
        if (!$saved || !hash_equals($sessionId, (string)$saved['provider_checkout_session_id'])
            || !hash_equals($checkoutUrl, (string)$saved['checkout_url'])) {
            throw new UnexpectedValueException('The Checkout Session could not be linked to its local transaction.');
        }
    }

    /** Only fixed diagnostic fields are allowed; never log a provider body. */
    public static function logPayMongo(string $action, array $context = []): void
    {
        $safe = ['action' => preg_replace('/[^a-z0-9_]/', '', $action)];
        foreach (['mode', 'event_id', 'session_id', 'payment_id', 'transaction_id', 'booking_id', 'status', 'http_status', 'idempotent'] as $key) {
            if (!array_key_exists($key, $context)) continue;
            $value = $context[$key];
            if (is_bool($value) || is_int($value)
                || (is_string($value) && preg_match('/^[A-Za-z0-9_-]{1,100}$/', $value))) {
                $safe[$key] = $value;
            }
        }
        error_log('PayMongo ' . json_encode($safe, JSON_UNESCAPED_SLASHES));
    }

    public static function sanitizePayMongoDiagnostic(mixed $value, array $secrets = []): mixed
    {
        if (is_string($value)) {
            $secrets = array_values(array_filter($secrets, static fn($secret) => is_string($secret) && $secret !== ''));
            if ($secrets !== []) $value = str_replace($secrets, '[REDACTED]', $value);
            return preg_replace('/\b(?:(?:sk|pk)_(?:test|live)_|whsk_)[A-Za-z0-9_]+/', '[REDACTED]', $value);
        }
        if (!is_array($value)) return $value;
        foreach ($value as $key => $item) {
            $blocked = '/(^|_)(authorization|api_?key|secret|secret_?key|client_?secret|access_?token|refresh_?token|password|signature|webhook_?secret|private_?key|card_?number|account_?number|cvv|cvc)($|_)/i';
            $value[$key] = preg_match($blocked, (string)$key)
                ? '[REDACTED]' : self::sanitizePayMongoDiagnostic($item, $secrets);
        }
        return $value;
    }

    public static function centavosToAmount(int $centavos): string
    {
        if ($centavos < 0) {
            throw new InvalidArgumentException('Centavo amount cannot be negative.');
        }

        return number_format($centavos / 100, 2, '.', '');
    }

    public static function publicHttpsUrl(string $baseUrl, string $path = ''): string
    {
        $baseUrl = rtrim(trim($baseUrl), '/');
        $parts = parse_url($baseUrl);
        $host = strtolower((string)($parts['host'] ?? ''));

        if (!filter_var($baseUrl, FILTER_VALIDATE_URL)
            || strtolower((string)($parts['scheme'] ?? '')) !== 'https'
            || $host === ''
            || in_array($host, ['localhost', '127.0.0.1', '::1'], true)) {
            throw new InvalidArgumentException('The configured public application URL must be a public HTTPS URL.');
        }

        return $path === '' ? $baseUrl : $baseUrl . '/' . ltrim($path, '/');
    }

    /** @return array<string, string> */
    public static function signatureParts(string $header): array
    {
        $parts = [];
        foreach (explode(',', $header) as $part) {
            [$name, $value] = array_pad(explode('=', trim($part), 2), 2, '');
            if ($name !== '') {
                if (array_key_exists($name, $parts)) return [];
                $parts[$name] = trim($value);
            }
        }
        return $parts;
    }

    public static function verifyPayMongoTestSignature(
        string $rawBody,
        string $signatureHeader,
        string $webhookSecret,
        int $toleranceSeconds = 300,
        ?int $currentTimestamp = null
    ): bool {
        return self::verifyPayMongoSignature($rawBody, $signatureHeader, $webhookSecret, false, $toleranceSeconds, $currentTimestamp);
    }

    public static function verifyPayMongoSignature(
        string $rawBody,
        string $signatureHeader,
        string $webhookSecret,
        bool $livemode,
        int $toleranceSeconds = 300,
        ?int $currentTimestamp = null
    ): bool {
        if ($rawBody === '' || $signatureHeader === '' || !str_starts_with($webhookSecret, 'whsk_')) {
            return false;
        }

        $parts = self::signatureParts($signatureHeader);
        $timestamp = $parts['t'] ?? '';
        $suppliedSignature = strtolower($parts[$livemode ? 'li' : 'te'] ?? '');
        if (!ctype_digit($timestamp) || !preg_match('/^[a-f0-9]{64}$/', $suppliedSignature)) {
            return false;
        }

        $now = $currentTimestamp ?? time();
        if ($toleranceSeconds > 0 && abs($now - (int)$timestamp) > $toleranceSeconds) {
            return false;
        }

        $expected = hash_hmac('sha256', $timestamp . '.' . $rawBody, $webhookSecret);
        return hash_equals($expected, $suppliedSignature);
    }
}
