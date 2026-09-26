<?php
declare(strict_types=1);

require_once __DIR__ . '/../payments/PaymentHelper.php';

function ensureBookingRefundsTable(PDO $pdo): void
{
    static $ensured = false;
    if ($ensured) return;
    $ensured = true;

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS booking_refunds (
            booking_refund_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            cancellation_request_id BIGINT UNSIGNED NOT NULL,
            payment_transaction_id BIGINT UNSIGNED NULL,
            tourist_id INT NOT NULL,
            booking_domain VARCHAR(20) NOT NULL,
            booking_id INT NOT NULL,
            booking_reference VARCHAR(100) NULL,
            provider VARCHAR(30) NOT NULL DEFAULT 'paymongo',
            provider_payment_id VARCHAR(100) NULL,
            provider_refund_id VARCHAR(100) NULL,
            idempotency_key VARCHAR(255) NOT NULL,
            amount_minor INT UNSIGNED NOT NULL,
            currency CHAR(3) NOT NULL DEFAULT 'PHP',
            payment_method_type VARCHAR(50) NULL,
            payment_destination VARCHAR(150) NULL,
            status VARCHAR(30) NOT NULL DEFAULT 'initiating',
            reason VARCHAR(50) NOT NULL DEFAULT 'requested_by_customer',
            admin_note VARCHAR(500) NULL,
            provider_response JSON NULL,
            failure_code VARCHAR(100) NULL,
            failure_message VARCHAR(500) NULL,
            initiated_by_admin_id INT NULL,
            initiated_at DATETIME NULL,
            completed_at DATETIME NULL,
            email_status VARCHAR(20) NOT NULL DEFAULT 'not_sent',
            email_sent_at DATETIME NULL,
            email_error VARCHAR(500) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_refund_provider_id (provider_refund_id),
            UNIQUE KEY uq_refund_idempotency (idempotency_key),
            INDEX idx_refund_cancellation (cancellation_request_id, status),
            INDEX idx_refund_payment (payment_transaction_id),
            INDEX idx_refund_status (status, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $columns = [];
    foreach ($pdo->query('SHOW COLUMNS FROM booking_refunds')->fetchAll(PDO::FETCH_ASSOC) as $column) {
        $columns[(string)$column['Field']] = true;
    }
    $migrations = [
        'provider_operation' => "ALTER TABLE booking_refunds ADD COLUMN provider_operation VARCHAR(30) NOT NULL DEFAULT 'refund' AFTER provider",
        'provider_transfer_id' => 'ALTER TABLE booking_refunds ADD COLUMN provider_transfer_id VARCHAR(100) NULL AFTER provider_refund_id',
        'provider_reference_number' => 'ALTER TABLE booking_refunds ADD COLUMN provider_reference_number VARCHAR(150) NULL AFTER provider_transfer_id',
        'destination_institution' => 'ALTER TABLE booking_refunds ADD COLUMN destination_institution VARCHAR(150) NULL AFTER payment_destination',
        'destination_last4' => 'ALTER TABLE booking_refunds ADD COLUMN destination_last4 VARCHAR(4) NULL AFTER destination_institution',
        'manual_refund_channel' => 'ALTER TABLE booking_refunds ADD COLUMN manual_refund_channel VARCHAR(80) NULL AFTER destination_last4',
        'manual_sender_account' => 'ALTER TABLE booking_refunds ADD COLUMN manual_sender_account VARCHAR(150) NULL AFTER manual_refund_channel',
        'provider_http_status' => 'ALTER TABLE booking_refunds ADD COLUMN provider_http_status SMALLINT UNSIGNED NULL AFTER provider_response',
        'provider_diagnostic' => 'ALTER TABLE booking_refunds ADD COLUMN provider_diagnostic JSON NULL AFTER provider_http_status',
    ];
    foreach ($migrations as $column => $sql) {
        if (!isset($columns[$column])) $pdo->exec($sql);
    }
    $indexes = [];
    foreach ($pdo->query('SHOW INDEX FROM booking_refunds')->fetchAll(PDO::FETCH_ASSOC) as $index) {
        $indexes[(string)$index['Key_name']] = true;
    }
    if (!isset($indexes['uq_refund_provider_transfer'])) {
        $pdo->exec('ALTER TABLE booking_refunds ADD UNIQUE KEY uq_refund_provider_transfer (provider_transfer_id)');
    }

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS paymongo_webhook_events (
            event_id VARCHAR(100) PRIMARY KEY,
            event_type VARCHAR(100) NOT NULL,
            payload_hash CHAR(64) NOT NULL,
            processed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_paymongo_webhook_processed (processed_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

function bookingRefundDestinationKey(): string
{
    $material = PaymentHelper::env('REFUND_DESTINATION_ENCRYPTION_KEY');
    if ($material === '') throw new RuntimeException('Refund destination encryption is not configured.');
    return hash_hkdf('sha256', $material, 32, 'itour-mercedes-refund-destination-v1');
}

/**
 * Legacy ciphertext may have been derived from the PayMongo secret before
 * Security #20 introduced a dedicated key. This key is decrypt-only so new
 * records can never become coupled to PayMongo credential rotation again.
 */
function bookingRefundLegacyDestinationKey(): ?string
{
    $material = PaymentHelper::env('PAYMONGO_SECRET_KEY');
    if ($material === '') return null;
    return hash_hkdf('sha256', $material, 32, 'itour-mercedes-refund-destination-v1');
}

function bookingRefundEncryptAccountNumber(string $accountNumber): string
{
    $accountNumber = trim($accountNumber);
    if ($accountNumber === '') throw new InvalidArgumentException('The refund account number is required.');
    $iv = random_bytes(12);
    $tag = '';
    $cipher = openssl_encrypt($accountNumber, 'aes-256-gcm', bookingRefundDestinationKey(), OPENSSL_RAW_DATA, $iv, $tag);
    if ($cipher === false) throw new RuntimeException('The refund destination could not be protected.');
    return base64_encode($iv . $tag . $cipher);
}

function bookingRefundDecryptAccountNumber(string $encoded): string
{
    $payload = base64_decode($encoded, true);
    if ($payload === false || strlen($payload) < 29) throw new RuntimeException('The saved refund destination is invalid.');

    $keys = [];
    $dedicatedMaterial = PaymentHelper::env('REFUND_DESTINATION_ENCRYPTION_KEY');
    if ($dedicatedMaterial !== '') {
        $keys[] = hash_hkdf('sha256', $dedicatedMaterial, 32, 'itour-mercedes-refund-destination-v1');
    }
    $legacyKey = bookingRefundLegacyDestinationKey();
    if ($legacyKey !== null && !in_array($legacyKey, $keys, true)) {
        $keys[] = $legacyKey;
    }
    if ($keys === []) throw new RuntimeException('Refund destination encryption is not configured.');

    foreach ($keys as $key) {
        $plain = openssl_decrypt(
            substr($payload, 28),
            'aes-256-gcm',
            $key,
            OPENSSL_RAW_DATA,
            substr($payload, 0, 12),
            substr($payload, 12, 16)
        );
        if ($plain !== false && trim($plain) !== '') return trim($plain);
    }

    throw new RuntimeException('The saved refund destination could not be opened.');
}

function bookingRefundRecognizedProviderStatus(string $status): ?string
{
    return match (strtolower(trim($status))) {
        'succeeded', 'success', 'completed', 'refunded' => 'succeeded',
        'processing', 'refunding' => 'processing',
        'pending' => 'pending',
        'initiating' => 'initiating',
        'failed', 'cancelled', 'canceled' => 'failed',
        default => null,
    };
}

function bookingRefundNormalizeProviderStatus(string $status): string
{
    $localStatus = strtolower(trim($status));
    if (in_array($localStatus, ['partial', 'ready'], true)) return $localStatus;
    return bookingRefundRecognizedProviderStatus($localStatus) ?? 'unknown';
}

function bookingRefundProviderResponseDisposition(string $status): string
{
    return match (bookingRefundRecognizedProviderStatus($status)) {
        'pending', 'processing', 'succeeded' => 'submitted',
        'failed' => 'failed',
        default => 'unexpected',
    };
}

function bookingRefundMonotonicProviderStatus(string $localStatus, string $providerStatus): string
{
    $local = bookingRefundRecognizedProviderStatus($localStatus) ?? strtolower(trim($localStatus));
    $provider = bookingRefundRecognizedProviderStatus($providerStatus);
    if ($provider === null) throw new UnexpectedValueException('The provider refund status is invalid.');
    if ($local === 'succeeded') return 'succeeded';
    if ($local === 'failed' && $provider !== 'succeeded') return 'failed';
    $rank = ['initiating' => 0, 'pending' => 1, 'processing' => 2, 'failed' => 3, 'succeeded' => 4];
    return ($rank[$provider] ?? 0) < ($rank[$local] ?? 0) ? $local : $provider;
}

function bookingRefundStatusLabel(string $status): string
{
    return match (bookingRefundNormalizeProviderStatus($status)) {
        'succeeded' => 'Refunded',
        'processing' => 'Processing',
        'pending' => 'Pending',
        'initiating' => 'Submitting',
        'ready' => 'Ready to refund',
        'partial' => 'Partially refunded / requires attention',
        'failed' => 'Failed / requires attention',
        'unknown' => 'Requires attention',
        default => 'Ready to refund',
    };
}

function bookingRefundMethodLabel(string $method): string
{
    return match (strtolower(trim($method))) {
        'gcash' => 'GCash',
        'maya', 'paymaya' => 'Maya',
        'grab_pay', 'grabpay' => 'GrabPay',
        'shopeepay' => 'ShopeePay',
        'qrph', 'qr_code' => 'QR Ph',
        'card', 'card_terminal' => 'Debit / credit card',
        'billease' => 'BillEase',
        'bpi' => 'BPI Online',
        'brankas_bdo' => 'BDO via Brankas',
        'brankas_metrobank' => 'Metrobank via Brankas',
        'brankas_landbank' => 'Landbank via Brankas',
        'cash' => 'Cash',
        'bank_transfer' => 'Bank transfer',
        '' => 'Payment channel not recorded',
        default => ucwords(str_replace(['_', '-'], ' ', $method)),
    };
}

function bookingRefundSupportsAutomaticPayMongoRefund(string $method): bool
{
    return in_array(strtolower(trim($method)), [
        'card',
        'gcash',
        'grab_pay',
        'grabpay',
        'maya',
        'paymaya',
        'qrph',
        'qr_code',
    ], true);
}

function bookingRefundSupportsPartialPayMongoRefund(string $method): bool
{
    $method = strtolower(trim($method));
    return in_array($method, ['qrph', 'qr_code'], true);
}

function bookingRefundAutomaticAllocation(string $method, int $remainingMinor, int $availableMinor): int
{
    if (!bookingRefundSupportsAutomaticPayMongoRefund($method)) return 0;
    if ($remainingMinor < 100 || $availableMinor < 100) return 0;
    if ($remainingMinor < $availableMinor && !bookingRefundSupportsPartialPayMongoRefund($method)) return 0;
    return min($remainingMinor, $availableMinor);
}

function bookingRefundWindowDays(string $method): ?int
{
    return match (strtolower(trim($method))) {
        'card', 'card_terminal', 'billease' => 60,
        'gcash' => 180,
        'grab_pay', 'grabpay' => 90,
        'maya', 'paymaya', 'shopeepay' => 365,
        'bpi', 'brankas_bdo', 'brankas_metrobank', 'brankas_landbank', 'qrph', 'qr_code' => 30,
        default => null,
    };
}

/** @return array{short:string,detail:string} */
function bookingRefundTimeline(string $method, int $amountMinor = 0): array
{
    return match (strtolower(trim($method))) {
        'gcash', 'maya', 'paymaya', 'grab_pay', 'grabpay', 'shopeepay', 'billease' => [
            'short' => 'Within 24 hours',
            'detail' => 'The provider normally posts the refund to the original e-wallet within 24 hours.',
        ],
        'qrph', 'qr_code' => $amountMinor < 5000000 ? [
            'short' => 'Usually real time',
            'detail' => 'A QR Ph refund below PHP 50,000 is normally posted in real time, subject to the receiving bank.',
        ] : [
            'short' => 'Next banking day',
            'detail' => 'A QR Ph refund of PHP 50,000 or more normally posts on the next banking day.',
        ],
        'bpi' => [
            'short' => 'At least 3 banking days',
            'detail' => 'BPI Online refunds normally require at least three banking days.',
        ],
        'brankas_bdo', 'brankas_metrobank', 'brankas_landbank', 'bank_transfer' => [
            'short' => 'Up to 5 banking days',
            'detail' => 'Bank refunds can take up to five banking days after provider acceptance.',
        ],
        'card', 'card_terminal' => [
            'short' => 'Up to 30 days',
            'detail' => 'Card posting time depends on the issuing bank and may take up to 30 calendar days.',
        ],
        'cash' => [
            'short' => 'Manual release required',
            'detail' => 'Cash payments cannot be returned automatically. Record the handover only after the guest receives the funds.',
        ],
        default => [
            'short' => 'Depends on provider',
            'detail' => 'Posting time depends on the original payment channel and its refund policy.',
        ],
    };
}

/** @return array{id:string,status:string,amount_minor:int,payment_id:string,currency:string,livemode:bool,transfer_link:string} */
function bookingRefundParsePayMongoResponse(array $response): array
{
    $data = is_array($response['data'] ?? null) ? $response['data'] : [];
    $attributes = is_array($data['attributes'] ?? null) ? $data['attributes'] : [];
    return [
        'id' => trim((string)($data['id'] ?? '')),
        'status' => bookingRefundNormalizeProviderStatus((string)($attributes['status'] ?? '')),
        'amount_minor' => max(0, (int)($attributes['amount'] ?? 0)),
        'payment_id' => trim((string)($attributes['payment_id'] ?? '')),
        'currency' => strtoupper(trim((string)($attributes['currency'] ?? ''))),
        'livemode' => ($attributes['livemode'] ?? false) === true,
        'transfer_link' => filter_var((string)($attributes['transfer_link'] ?? ''), FILTER_VALIDATE_URL) ? trim((string)$attributes['transfer_link']) : '',
    ];
}


/** @return list<array{refund_id:string,amount_minor:int,claim_url:string}> */
function bookingRefundClaimActions(array $submitted): array
{
    $actions = [];
    foreach ($submitted as $refund) {
        if (!is_array($refund)) continue;
        $url = trim((string)($refund['claim_url'] ?? ''));
        if (!filter_var($url, FILTER_VALIDATE_URL)) continue;
        $actions[] = [
            'refund_id' => trim((string)($refund['provider_refund_id'] ?? '')),
            'amount_minor' => max(0, (int)($refund['amount_minor'] ?? 0)),
            'claim_url' => $url,
        ];
    }
    return $actions;
}

/** @return array{state:string,requires_attention:bool} */
function bookingRefundOperationOutcome(array $submitted, array $failures): array
{
    if ($submitted !== [] && $failures !== []) return ['state' => 'partial', 'requires_attention' => true];
    if ($submitted !== []) return ['state' => 'submitted', 'requires_attention' => false];
    return ['state' => 'failed', 'requires_attention' => true];
}

/** @return list<array{id:string,payment_id:string,amount_minor:int,currency:string,status:string}> */
function bookingRefundRemoteRefundResources(array $paymentResource): array
{
    $paymentId = trim((string)($paymentResource['id'] ?? ''));
    $attributes = is_array($paymentResource['attributes'] ?? null) ? $paymentResource['attributes'] : [];
    $paymentCurrency = strtoupper(trim((string)($attributes['currency'] ?? '')));
    if (!preg_match('/^pay_[A-Za-z0-9]+$/', $paymentId) || $paymentCurrency !== 'PHP') return [];
    $resources = [];
    foreach ((array)($attributes['refunds'] ?? []) as $refundResource) {
        if (!is_array($refundResource)) continue;
        if (is_array($refundResource['data'] ?? null)) $refundResource = $refundResource['data'];
        $refundAttributes = is_array($refundResource['attributes'] ?? null) ? $refundResource['attributes'] : $refundResource;
        $refundId = trim((string)($refundResource['id'] ?? $refundAttributes['id'] ?? ''));
        $refundPaymentId = trim((string)($refundAttributes['payment_id'] ?? $paymentId));
        $amountMinor = is_numeric($refundAttributes['amount'] ?? null) ? (int)$refundAttributes['amount'] : 0;
        $currency = strtoupper(trim((string)($refundAttributes['currency'] ?? $paymentCurrency)));
        $status = bookingRefundRecognizedProviderStatus((string)($refundAttributes['status'] ?? ''));
        if (!preg_match('/^ref_[A-Za-z0-9]+$/', $refundId)
            || !hash_equals($paymentId, $refundPaymentId)
            || $amountMinor < 1 || $currency !== 'PHP' || $status === null) {
            continue;
        }
        $resources[$refundId] = [
            'id' => $refundId,
            'payment_id' => $refundPaymentId,
            'amount_minor' => $amountMinor,
            'currency' => $currency,
            'status' => $status,
        ];
    }
    return array_values($resources);
}

function bookingRefundWebhookEventProcessed(PDO $pdo, string $eventId): bool
{
    if (!preg_match('/^evt_[A-Za-z0-9]+$/', $eventId)) return false;
    $statement = $pdo->prepare('SELECT 1 FROM paymongo_webhook_events WHERE event_id=? LIMIT 1');
    $statement->execute([$eventId]);
    return (bool)$statement->fetchColumn();
}

function bookingRefundRecordWebhookEvent(PDO $pdo, string $eventId, string $eventType, string $rawBody): bool
{
    if (!preg_match('/^evt_[A-Za-z0-9]+$/', $eventId)) return false;
    try {
        $statement = $pdo->prepare('INSERT INTO paymongo_webhook_events (event_id,event_type,payload_hash,processed_at) VALUES (?,?,?,NOW())');
        $statement->execute([$eventId, mb_substr(trim($eventType), 0, 100), hash('sha256', $rawBody)]);
        return true;
    } catch (PDOException $exception) {
        if ((string)$exception->getCode() === '23000') return false;
        throw $exception;
    }
}

function bookingRefundAggregateStatus(int $targetMinor, int $succeededMinor, int $activeMinor, int $failedCount): string
{
    if ($targetMinor <= 0) return 'not_applicable';
    if ($succeededMinor >= $targetMinor) return 'completed';
    if ($activeMinor > 0) return 'processing';
    if ($succeededMinor > 0) return 'partial';
    return $failedCount > 0 ? 'failed' : 'pending';
}

function bookingRefundSyncCancellationStatus(PDO $pdo, int $cancellationRequestId): string
{
    $requestStatement = $pdo->prepare('SELECT refundable_amount FROM booking_cancellation_requests WHERE cancellation_request_id=? LIMIT 1');
    $requestStatement->execute([$cancellationRequestId]);
    $targetMinor = (int)round((float)$requestStatement->fetchColumn() * 100);

    $totals = $pdo->prepare("
        SELECT
          COALESCE(SUM(CASE WHEN status='succeeded' THEN amount_minor ELSE 0 END),0) succeeded_minor,
          COALESCE(SUM(CASE WHEN status IN ('initiating','pending','processing') THEN amount_minor ELSE 0 END),0) active_minor,
          COALESCE(SUM(CASE WHEN status='failed' THEN 1 ELSE 0 END),0) failed_count
        FROM booking_refunds WHERE cancellation_request_id=?
    ");
    $totals->execute([$cancellationRequestId]);
    $row = $totals->fetch(PDO::FETCH_ASSOC) ?: [];
    $succeeded = (int)($row['succeeded_minor'] ?? 0);
    $active = (int)($row['active_minor'] ?? 0);
    $failed = (int)($row['failed_count'] ?? 0);
    $next = bookingRefundAggregateStatus($targetMinor, $succeeded, $active, $failed);
    $update = $pdo->prepare('UPDATE booking_cancellation_requests SET refund_status=?, refund_updated_at=NOW() WHERE cancellation_request_id=?');
    $update->execute([$next, $cancellationRequestId]);
    return $next;
}
