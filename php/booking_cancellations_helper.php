<?php

declare(strict_types=1);

function ensureBookingCancellationRequestsTable(PDO $pdo): void
{
    static $ensured = false;
    if ($ensured) return;
    $ensured = true;

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS booking_cancellation_requests (
            cancellation_request_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            tourist_id INT NOT NULL,
            booking_domain VARCHAR(20) NOT NULL,
            booking_id INT NOT NULL,
            booking_reference VARCHAR(100) NULL,
            booking_type VARCHAR(40) NOT NULL,
            service_name VARCHAR(255) NOT NULL,
            service_date DATE NOT NULL,
            total_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
            amount_paid DECIMAL(12,2) NOT NULL DEFAULT 0,
            days_before_service INT NOT NULL DEFAULT 0,
            refund_policy VARCHAR(30) NOT NULL,
            refundable_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
            non_refundable_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
            request_status VARCHAR(30) NOT NULL DEFAULT 'pending',
            refund_status VARCHAR(30) NOT NULL DEFAULT 'not_started',
            cancellation_reason TEXT NULL,
            admin_note TEXT NULL,
            provider_email_sent_count INT UNSIGNED NOT NULL DEFAULT 0,
            provider_email_last_sent_at DATETIME NULL,
            reviewed_by_admin_id INT NULL,
            requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            reviewed_at DATETIME NULL,
            refund_updated_at DATETIME NULL,
            completed_at DATETIME NULL,
            completed_by_admin_id INT NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_cancellation_booking (booking_domain, booking_id),
            INDEX idx_cancellation_tourist (tourist_id, requested_at),
            INDEX idx_cancellation_status (request_status, refund_status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $columns = [];
    $columnStatement = $pdo->query('SHOW COLUMNS FROM booking_cancellation_requests');
    foreach ($columnStatement->fetchAll(PDO::FETCH_ASSOC) as $column) {
        $columns[(string)$column['Field']] = true;
    }
    $columnMigrations = [
        'reviewed_by_admin_id' => 'ALTER TABLE booking_cancellation_requests ADD COLUMN reviewed_by_admin_id INT NULL AFTER admin_note',
        'completed_at' => 'ALTER TABLE booking_cancellation_requests ADD COLUMN completed_at DATETIME NULL AFTER refund_updated_at',
        'completed_by_admin_id' => 'ALTER TABLE booking_cancellation_requests ADD COLUMN completed_by_admin_id INT NULL AFTER completed_at',
        'refund_destination_institution' => 'ALTER TABLE booking_cancellation_requests ADD COLUMN refund_destination_institution VARCHAR(150) NULL AFTER non_refundable_amount',
        'refund_destination_bic' => 'ALTER TABLE booking_cancellation_requests ADD COLUMN refund_destination_bic VARCHAR(20) NULL AFTER refund_destination_institution',
        'refund_destination_account_name' => 'ALTER TABLE booking_cancellation_requests ADD COLUMN refund_destination_account_name VARCHAR(150) NULL AFTER refund_destination_bic',
        'refund_destination_account_cipher' => 'ALTER TABLE booking_cancellation_requests ADD COLUMN refund_destination_account_cipher TEXT NULL AFTER refund_destination_account_name',
        'refund_destination_last4' => 'ALTER TABLE booking_cancellation_requests ADD COLUMN refund_destination_last4 VARCHAR(4) NULL AFTER refund_destination_account_cipher',
        'refund_destination_verified_at' => 'ALTER TABLE booking_cancellation_requests ADD COLUMN refund_destination_verified_at DATETIME NULL AFTER refund_destination_last4',
        'initiated_by' => "ALTER TABLE booking_cancellation_requests ADD COLUMN initiated_by VARCHAR(30) NOT NULL DEFAULT 'tourist' AFTER booking_id",
        'reschedule_offered' => 'ALTER TABLE booking_cancellation_requests ADD COLUMN reschedule_offered TINYINT(1) NOT NULL DEFAULT 0 AFTER cancellation_reason',
        'reschedule_offered_at' => 'ALTER TABLE booking_cancellation_requests ADD COLUMN reschedule_offered_at DATETIME NULL AFTER reschedule_offered',
        'decision_deadline' => 'ALTER TABLE booking_cancellation_requests ADD COLUMN decision_deadline DATETIME NULL AFTER reschedule_offered_at',
        'tourist_decision' => 'ALTER TABLE booking_cancellation_requests ADD COLUMN tourist_decision VARCHAR(30) NULL AFTER decision_deadline',
        'tourist_responded_at' => 'ALTER TABLE booking_cancellation_requests ADD COLUMN tourist_responded_at DATETIME NULL AFTER tourist_decision',
        'decision_expired_at' => 'ALTER TABLE booking_cancellation_requests ADD COLUMN decision_expired_at DATETIME NULL AFTER tourist_responded_at',
        'original_service_date' => 'ALTER TABLE booking_cancellation_requests ADD COLUMN original_service_date DATE NULL AFTER service_date',
        'rescheduled_service_date' => 'ALTER TABLE booking_cancellation_requests ADD COLUMN rescheduled_service_date DATE NULL AFTER original_service_date',
        'refund_request_created_at' => 'ALTER TABLE booking_cancellation_requests ADD COLUMN refund_request_created_at DATETIME NULL AFTER refund_status',
        'provider_email_sent_count' => 'ALTER TABLE booking_cancellation_requests ADD COLUMN provider_email_sent_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER admin_note',
        'provider_email_last_sent_at' => 'ALTER TABLE booking_cancellation_requests ADD COLUMN provider_email_last_sent_at DATETIME NULL AFTER provider_email_sent_count',
    ];
    foreach ($columnMigrations as $column => $migration) {
        if (!isset($columns[$column])) $pdo->exec($migration);
    }
    $indexes = [];
    foreach ($pdo->query('SHOW INDEX FROM booking_cancellation_requests')->fetchAll(PDO::FETCH_ASSOC) as $index) {
        $indexes[(string)$index['Key_name']] = true;
    }
    if (!isset($indexes['idx_cancellation_deadline'])) {
        $pdo->exec('ALTER TABLE booking_cancellation_requests ADD INDEX idx_cancellation_deadline (request_status, decision_deadline)');
    }
}

function bookingProviderCancellationReasons(): array
{
    return [
        'Bad Weather / Unfavorable Weather Conditions',
        'Safety Concerns',
        'Tour/Activity Unavailable',
        'Boat Unavailable',
        'Tour Guide Unavailable',
        'Operator Unavailable',
        'Unexpected Operational Issue',
        'Government/LGU Advisory',
        'Natural Disaster / Emergency',
    ];
}

function bookingProviderCancellationReason(string $selected, string $other = ''): string
{
    $selected = trim($selected);
    if ($selected === 'Other') {
        $selected = trim($other);
    } elseif (!in_array($selected, bookingProviderCancellationReasons(), true)) {
        $selected = '';
    }
    if ($selected === '') throw new InvalidArgumentException('Select or enter a cancellation reason.');
    if (mb_strlen($selected) > 1500) throw new InvalidArgumentException('The cancellation reason must be 1,500 characters or fewer.');
    return $selected;
}

function bookingProviderFullRefund(float $amountPaid): array
{
    $amountPaid = max(0, round($amountPaid, 2));
    return [
        'refund_policy' => $amountPaid > 0.009 ? 'provider_full_refund' : 'no_payment',
        'refundable_amount' => $amountPaid,
        'non_refundable_amount' => 0.0,
        'refund_status' => $amountPaid > 0.009 ? 'pending' : 'not_applicable',
    ];
}

function bookingCancellationIsDecisionRequired(array $request): bool
{
    return strtolower((string)($request['request_status'] ?? '')) === 'decision_required'
        && !empty($request['reschedule_offered'])
        && empty($request['tourist_decision']);
}

function bookingCancellationFinalizeProviderRefund(PDO $pdo, int $requestId, string $decision, bool $expired = false): array
{
    $statement = $pdo->prepare('SELECT * FROM booking_cancellation_requests WHERE cancellation_request_id=? LIMIT 1 FOR UPDATE');
    $statement->execute([$requestId]);
    $request = $statement->fetch(PDO::FETCH_ASSOC);
    if (!$request) throw new RuntimeException('Cancellation record not found.');

    $status = strtolower((string)$request['request_status']);
    if ($status === 'approved' && !empty($request['refund_request_created_at'])) {
        throw new RuntimeException('The full-refund request has already been created.');
    }
    if ($status !== 'decision_required' && $decision !== 'admin_full_refund') {
        throw new RuntimeException('This cancellation decision has already been completed.');
    }

    $refund = bookingProviderFullRefund((float)$request['amount_paid']);
    $noTouristResponse = $expired || $decision === 'admin_full_refund';
    $update = $pdo->prepare("UPDATE booking_cancellation_requests SET
        initiated_by='admin_provider', request_status='approved', refund_policy=?,
        refundable_amount=?, non_refundable_amount=0, refund_status=?,
        tourist_decision=?, tourist_responded_at=CASE WHEN ?=1 THEN tourist_responded_at ELSE NOW() END,
        decision_expired_at=CASE WHEN ?=1 THEN NOW() ELSE decision_expired_at END,
        refund_request_created_at=COALESCE(refund_request_created_at, NOW()), reviewed_at=COALESCE(reviewed_at, NOW()),
        refund_updated_at=NOW() WHERE cancellation_request_id=?");
    $update->execute([
        $refund['refund_policy'], $refund['refundable_amount'], $refund['refund_status'], $decision,
        $noTouristResponse ? 1 : 0, $expired ? 1 : 0, $requestId,
    ]);

    if (strtolower((string)$request['booking_domain']) === 'hotel') {
        $source = $pdo->prepare("UPDATE hotel_room_bookings SET booking_status='cancelled', updated_at=NOW() WHERE hotel_booking_id=? AND tourist_id=? AND booking_status NOT IN ('completed','cancelled')");
    } else {
        $source = $pdo->prepare("UPDATE bookings SET status='accepted', is_complete='cancelled', dec_can_note=?, updated_at=NOW() WHERE booking_id=? AND tourist_id=? AND is_complete='uncomplete'");
        $source->bindValue(1, 'Cancelled by administrator/provider - ' . (string)$request['cancellation_reason']);
        $source->bindValue(2, (int)$request['booking_id'], PDO::PARAM_INT);
        $source->bindValue(3, (int)$request['tourist_id'], PDO::PARAM_INT);
        $source->execute();
        return array_merge($request, $refund, ['request_status' => 'approved', 'tourist_decision' => $decision]);
    }
    $source->execute([(int)$request['booking_id'], (int)$request['tourist_id']]);
    return array_merge($request, $refund, ['request_status' => 'approved', 'tourist_decision' => $decision]);
}

function bookingCancellationRefundPolicy(
    string $serviceDate,
    float $totalAmount,
    float $amountPaid,
    ?DateTimeImmutable $requestedAt = null
): array {
    $timezone = new DateTimeZone('Asia/Manila');
    $requestedAt = ($requestedAt ?? new DateTimeImmutable('now', $timezone))->setTimezone($timezone);
    $requestDate = $requestedAt->setTime(0, 0);
    $service = DateTimeImmutable::createFromFormat('!Y-m-d', $serviceDate, $timezone);
    if (!$service) throw new InvalidArgumentException('The booking has an invalid service date.');

    $daysBeforeService = (int)$requestDate->diff($service)->format('%r%a');
    $totalAmount = max(0, round($totalAmount, 2));
    $amountPaid = max(0, round($amountPaid, 2));

    if ($amountPaid <= 0.009) {
        return [
            'days_before_service' => $daysBeforeService,
            'refund_policy' => 'no_payment',
            'refundable_amount' => 0.0,
            'non_refundable_amount' => 0.0,
        ];
    }

    if ($daysBeforeService >= 3) {
        return [
            'days_before_service' => $daysBeforeService,
            'refund_policy' => 'full_refund',
            'refundable_amount' => $amountPaid,
            'non_refundable_amount' => 0.0,
        ];
    }

    $nonRefundableDeposit = round($totalAmount * 0.20, 2);
    $refundableAmount = max(0, round($amountPaid - $nonRefundableDeposit, 2));

    return [
        'days_before_service' => $daysBeforeService,
        'refund_policy' => $refundableAmount > 0 ? 'partial_refund' : 'deposit_non_refundable',
        'refundable_amount' => $refundableAmount,
        'non_refundable_amount' => min($amountPaid, $nonRefundableDeposit),
    ];
}

function bookingCancellationPolicyLabel(string $policy): string
{
    return match ($policy) {
        'provider_full_refund' => 'Full refund — provider cancellation',
        'no_refund_rescheduled' => 'No refund — booking rescheduled',
        'full_refund' => 'Eligible for full refund',
        'partial_refund' => 'Eligible for partial refund',
        'deposit_non_refundable' => '20% deposit is non-refundable',
        'no_payment' => 'No payment to refund',
        default => 'Under review',
    };
}

function bookingCancellationRequestStatusLabel(string $status): string
{
    return match (strtolower($status)) {
        'decision_required' => 'Tourist Decision Required',
        'rescheduled' => 'Rescheduled',
        'approved' => 'Approved',
        'rejected' => 'Not approved',
        'cancelled' => 'Cancelled',
        'completed' => 'Cancellation completed',
        default => 'Awaiting approval',
    };
}

function bookingCancellationRefundStatusLabel(string $status, float $refundableAmount): string
{
    if ($refundableAmount <= 0.009) return 'No refund due';
    return match (strtolower($status)) {
        'pending' => 'Refund pending',
        'processing' => 'Refund processing',
        'partial' => 'Partially refunded — requires attention',
        'failed' => 'Refund failed — requires attention',
        'completed', 'refunded' => 'Refund completed',
        'rejected', 'declined' => 'Refund declined',
        default => 'Awaiting approval',
    };
}
