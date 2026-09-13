<?php

function BookingReferenceColumnExists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
          AND COLUMN_NAME = ?
    ");
    $stmt->execute([$table, $column]);
    return (int)$stmt->fetchColumn() > 0;
}

function BookingReferenceTableExists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
    ");
    $stmt->execute([$table]);
    return (int)$stmt->fetchColumn() > 0;
}

function BookingReferenceEnsureSchema(PDO $pdo): void
{
    if (!BookingReferenceTableExists($pdo, 'booking_reference_sequences')) {
        $pdo->exec("
            CREATE TABLE booking_reference_sequences (
                prefix VARCHAR(2) NOT NULL,
                reference_year SMALLINT UNSIGNED NOT NULL,
                next_number INT UNSIGNED NOT NULL DEFAULT 1,
                PRIMARY KEY (prefix, reference_year)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    }

    if (!BookingReferenceColumnExists($pdo, 'bookings', 'booking_reference')) {
        $pdo->exec("ALTER TABLE bookings ADD COLUMN booking_reference VARCHAR(20) NULL AFTER booking_id");
        $pdo->exec("ALTER TABLE bookings ADD UNIQUE KEY uq_bookings_booking_reference (booking_reference)");
    }
    if (!BookingReferenceColumnExists($pdo, 'hotel_room_bookings', 'booking_reference')) {
        $pdo->exec("ALTER TABLE hotel_room_bookings ADD COLUMN booking_reference VARCHAR(20) NULL AFTER hotel_booking_id");
        $pdo->exec("ALTER TABLE hotel_room_bookings ADD UNIQUE KEY uq_hotel_booking_reference (booking_reference)");
    }
}

function BookingReferencePrefix(string $bookingType): string
{
    return match (strtolower(trim($bookingType))) {
        'package', 'tourpackage', 'tour package' => 'TP',
        'boat', 'tourboat', 'tour boat' => 'TB',
        'tourguide', 'guide', 'tour guide' => 'TG',
        'hotel', 'resort', 'hotel_resort', 'hotel/resort' => 'HR',
        default => throw new InvalidArgumentException('Unsupported booking type for reference generation.'),
    };
}

function BookingReferenceGenerate(PDO $pdo, string $bookingType, ?int $year = null): string
{
    BookingReferenceEnsureSchema($pdo);
    $prefix = BookingReferencePrefix($bookingType);
    $fullYear = $year ?: (int)date('Y');
    $shortYear = str_pad((string)($fullYear % 100), 2, '0', STR_PAD_LEFT);
    $startedTransaction = !$pdo->inTransaction();

    if ($startedTransaction) {
        $pdo->beginTransaction();
    }

    try {
        $insert = $pdo->prepare("
            INSERT IGNORE INTO booking_reference_sequences (prefix, reference_year, next_number)
            VALUES (?, ?, 1)
        ");
        $insert->execute([$prefix, $fullYear]);

        $select = $pdo->prepare("
            SELECT next_number
            FROM booking_reference_sequences
            WHERE prefix = ? AND reference_year = ?
            FOR UPDATE
        ");
        $select->execute([$prefix, $fullYear]);
        $number = max(1, (int)$select->fetchColumn());

        $update = $pdo->prepare("
            UPDATE booking_reference_sequences
            SET next_number = ?
            WHERE prefix = ? AND reference_year = ?
        ");
        $update->execute([$number + 1, $prefix, $fullYear]);

        if ($startedTransaction) {
            $pdo->commit();
        }

        return $prefix . $shortYear . '-' . str_pad((string)$number, 4, '0', STR_PAD_LEFT);
    } catch (Throwable $e) {
        if ($startedTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function BookingReferenceDisplay(array $booking, string $idKey = 'booking_id'): string
{
    $reference = trim((string)($booking['booking_reference'] ?? ''));
    return $reference !== '' ? $reference : (string)($booking[$idKey] ?? '');
}
