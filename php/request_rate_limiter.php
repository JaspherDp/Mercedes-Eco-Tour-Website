<?php

declare(strict_types=1);

/**
 * Database-backed fixed-window limiter for security-sensitive requests.
 * Identifiers are hashed so raw IP addresses, emails, and account IDs are not stored.
 */
function requestRateLimitEnsureTable(PDO $pdo): void
{
    static $ready = false;
    if ($ready) {
        return;
    }

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS request_rate_limits (
            request_scope VARCHAR(60) NOT NULL,
            identifier_hash CHAR(64) NOT NULL,
            request_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            window_started_at DATETIME NOT NULL,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (request_scope, identifier_hash),
            INDEX idx_request_rate_limits_updated (updated_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $ready = true;
}

function requestRateLimitClientIp(): string
{
    // REMOTE_ADDR is supplied by the web server and cannot be spoofed with a
    // client-controlled forwarding header unless the server explicitly rewrites it.
    return trim((string)($_SERVER['REMOTE_ADDR'] ?? 'unknown')) ?: 'unknown';
}

function requestRateLimitIdentifier(string $identifier): string
{
    return hash('sha256', strtolower(trim($identifier)));
}

/** @return array{allowed:bool,rate_limited:bool,retry_after:int,limit:int,remaining:int} */
function requestRateLimitConsume(PDO $pdo, string $scope, string $identifier, int $limit, int $windowSeconds): array
{
    requestRateLimitEnsureTable($pdo);
    $limit = max(1, $limit);
    $windowSeconds = max(1, $windowSeconds);
    $hash = requestRateLimitIdentifier($identifier);

    // A single upsert makes the increment atomic, including when several first
    // requests for the same identifier arrive concurrently.
    $consume = $pdo->prepare("
        INSERT INTO request_rate_limits (request_scope, identifier_hash, request_count, window_started_at)
        VALUES (?, ?, 1, NOW())
        ON DUPLICATE KEY UPDATE
            request_count = IF(
                window_started_at <= DATE_SUB(NOW(), INTERVAL ? SECOND),
                1,
                LEAST(request_count + 1, ?)
            ),
            window_started_at = IF(
                window_started_at <= DATE_SUB(NOW(), INTERVAL ? SECOND),
                NOW(),
                window_started_at
            )
    ");
    $consume->execute([$scope, $hash, $windowSeconds, $limit + 1, $windowSeconds]);

    $select = $pdo->prepare("
        SELECT request_count,
               GREATEST(0, ? - (UNIX_TIMESTAMP() - UNIX_TIMESTAMP(window_started_at))) AS retry_after
        FROM request_rate_limits
        WHERE request_scope = ? AND identifier_hash = ?
        LIMIT 1
    ");
    $select->execute([$windowSeconds, $scope, $hash]);
    $row = $select->fetch(PDO::FETCH_ASSOC) ?: [];
    $count = (int)($row['request_count'] ?? 1);
    $retryAfter = max(1, (int)($row['retry_after'] ?? $windowSeconds));
    $allowed = $count <= $limit;

    return [
        'allowed' => $allowed,
        'rate_limited' => !$allowed,
        'retry_after' => $allowed ? 0 : $retryAfter,
        'limit' => $limit,
        'remaining' => $allowed ? max(0, $limit - $count) : 0,
    ];
}

function requestRateLimitReject(array $limit, string $message = 'Request limit reached. Please try again after the timer ends.'): never
{
    $retryAfter = max(1, (int)($limit['retry_after'] ?? 1));
    http_response_code(429);
    header('Retry-After: ' . $retryAfter);
    echo json_encode([
        'success' => false,
        'status' => 'rate_limited',
        'rate_limited' => true,
        'title' => 'Request Limit Reached',
        'message' => $message,
        'retry_after' => $retryAfter,
    ], JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}
