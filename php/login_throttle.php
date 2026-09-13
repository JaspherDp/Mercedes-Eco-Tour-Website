<?php

declare(strict_types=1);

const LOGIN_THROTTLE_MAX_ATTEMPTS = 5;

function loginThrottleAttemptWindow(string $scope): int
{
    return strtolower(trim($scope)) === 'tourist' ? 120 : 300;
}

function loginThrottleEnsureTable(PDO $pdo): void
{
    static $ready = false;
    if ($ready) {
        return;
    }

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS login_rate_limits (
            login_scope VARCHAR(40) NOT NULL,
            identifier_hash CHAR(64) NOT NULL,
            failed_attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
            locked_until DATETIME NULL,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (login_scope, identifier_hash),
            INDEX idx_login_rate_limits_updated (updated_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $ready = true;
}

function loginThrottleIdentifier(string $identifier): string
{
    return hash('sha256', strtolower(trim($identifier)));
}

function loginThrottleStatus(PDO $pdo, string $scope, string $identifier): array
{
    loginThrottleEnsureTable($pdo);
    $hash = loginThrottleIdentifier($identifier);
    $stmt = $pdo->prepare("
        SELECT failed_attempts,
               GREATEST(0, UNIX_TIMESTAMP(locked_until) - UNIX_TIMESTAMP()) AS retry_after,
               GREATEST(0, UNIX_TIMESTAMP() - UNIX_TIMESTAMP(updated_at)) AS inactive_for
        FROM login_rate_limits
        WHERE login_scope = ? AND identifier_hash = ?
        LIMIT 1
    ");
    $stmt->execute([$scope, $hash]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $retryAfter = max(0, (int)($row['retry_after'] ?? 0));
    $attempts = (int)($row['failed_attempts'] ?? 0);

    if ($retryAfter <= 0
        && $attempts > 0
        && (int)($row['inactive_for'] ?? 0) >= loginThrottleAttemptWindow($scope)) {
        $clear = $pdo->prepare('DELETE FROM login_rate_limits WHERE login_scope = ? AND identifier_hash = ? AND updated_at <= DATE_SUB(NOW(), INTERVAL ? SECOND) AND (locked_until IS NULL OR locked_until <= NOW())');
        $clear->execute([$scope, $hash, loginThrottleAttemptWindow($scope)]);
        $attempts = 0;
    }

    return [
        'locked' => $retryAfter > 0,
        'retry_after' => $retryAfter,
        'attempts_remaining' => max(0, LOGIN_THROTTLE_MAX_ATTEMPTS - $attempts),
    ];
}

function loginThrottleRecordFailure(PDO $pdo, string $scope, string $identifier, int $lockSeconds): array
{
    loginThrottleEnsureTable($pdo);
    $hash = loginThrottleIdentifier($identifier);
    $pdo->beginTransaction();

    try {
        $stmt = $pdo->prepare("
            SELECT failed_attempts,
                   GREATEST(0, UNIX_TIMESTAMP(locked_until) - UNIX_TIMESTAMP()) AS retry_after,
                   GREATEST(0, UNIX_TIMESTAMP() - UNIX_TIMESTAMP(updated_at)) AS inactive_for
            FROM login_rate_limits
            WHERE login_scope = ? AND identifier_hash = ?
            FOR UPDATE
        ");
        $stmt->execute([$scope, $hash]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $activeRetry = max(0, (int)($row['retry_after'] ?? 0));

        if ($activeRetry > 0) {
            $pdo->commit();
            return ['locked' => true, 'retry_after' => $activeRetry, 'attempts_remaining' => 0];
        }

        $attempts = (int)($row['failed_attempts'] ?? 0);
        if ($row && (int)($row['retry_after'] ?? 0) <= 0
            && ($attempts >= LOGIN_THROTTLE_MAX_ATTEMPTS
                || (int)($row['inactive_for'] ?? 0) >= loginThrottleAttemptWindow($scope))) {
            $attempts = 0;
        }
        $attempts++;
        $locked = $attempts >= LOGIN_THROTTLE_MAX_ATTEMPTS;
        $lockedUntil = $locked ? time() + $lockSeconds : null;

        if ($row) {
            $update = $pdo->prepare("
                UPDATE login_rate_limits
                SET failed_attempts = ?, locked_until = FROM_UNIXTIME(?)
                WHERE login_scope = ? AND identifier_hash = ?
            ");
            $update->execute([$attempts, $lockedUntil, $scope, $hash]);
        } else {
            $insert = $pdo->prepare("
                INSERT INTO login_rate_limits (login_scope, identifier_hash, failed_attempts, locked_until)
                VALUES (?, ?, ?, FROM_UNIXTIME(?))
            ");
            $insert->execute([$scope, $hash, $attempts, $lockedUntil]);
        }

        $pdo->commit();
        return [
            'locked' => $locked,
            'retry_after' => $locked ? $lockSeconds : 0,
            'attempts_remaining' => max(0, LOGIN_THROTTLE_MAX_ATTEMPTS - $attempts),
        ];
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}

function loginThrottleClear(PDO $pdo, string $scope, string $identifier): void
{
    loginThrottleEnsureTable($pdo);
    $stmt = $pdo->prepare('DELETE FROM login_rate_limits WHERE login_scope = ? AND identifier_hash = ?');
    $stmt->execute([$scope, loginThrottleIdentifier($identifier)]);
}

function loginThrottleMessage(int $retryAfter): string
{
    $minutes = intdiv(max(0, $retryAfter), 60);
    $seconds = max(0, $retryAfter) % 60;
    $time = $minutes > 0 ? sprintf('%d:%02d', $minutes, $seconds) : sprintf('%d seconds', $seconds);
    return 'Too many failed login attempts. Please try again in ' . $time . '.';
}
