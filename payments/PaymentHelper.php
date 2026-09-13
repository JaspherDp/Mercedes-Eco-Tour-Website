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
        if ($rawBody === '' || $signatureHeader === '' || !str_starts_with($webhookSecret, 'whsk_')) {
            return false;
        }

        $parts = self::signatureParts($signatureHeader);
        $timestamp = $parts['t'] ?? '';
        $testSignature = strtolower($parts['te'] ?? '');
        if (!ctype_digit($timestamp) || !preg_match('/^[a-f0-9]{64}$/', $testSignature)) {
            return false;
        }

        $now = $currentTimestamp ?? time();
        if ($toleranceSeconds > 0 && abs($now - (int)$timestamp) > $toleranceSeconds) {
            return false;
        }

        $expected = hash_hmac('sha256', $timestamp . '.' . $rawBody, $webhookSecret);
        return hash_equals($expected, $testSignature);
    }
}
