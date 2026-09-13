<?php

declare(strict_types=1);

function packageSlotEnsureSchema(PDO $pdo): void
{
    static $ensured = false;
    if ($ensured) return;

    $pdo->exec("CREATE TABLE IF NOT EXISTS package_daily_slots (
        slot_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        operator_id INT NOT NULL,
        package_id INT NOT NULL,
        slot_date DATE NOT NULL,
        capacity INT UNSIGNED NOT NULL DEFAULT 0,
        is_open TINYINT(1) NOT NULL DEFAULT 1,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (slot_id),
        UNIQUE KEY uq_package_daily_slot (package_id, slot_date),
        KEY idx_operator_slot_date (operator_id, slot_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $ensured = true;
}

function packageSlotResolvePackage(PDO $pdo, string $packageName, ?int $operatorId = null): ?array
{
    $sql = 'SELECT package_id, operator_id, package_title FROM tour_packages WHERE package_title = ?';
    $params = [$packageName];
    if ($operatorId !== null && $operatorId > 0) {
        $sql .= ' AND operator_id = ?';
        $params[] = $operatorId;
    }
    $sql .= ' ORDER BY package_id DESC LIMIT 1';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function packageSlotBookedGuests(PDO $pdo, int $operatorId, string $packageName, string $date): int
{
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(
            CASE
                WHEN COALESCE(pax, 0) > 0 THEN pax
                WHEN COALESCE(num_adults, 0) + COALESCE(num_children, 0) > 0
                    THEN COALESCE(num_adults, 0) + COALESCE(num_children, 0)
                ELSE 1
            END
        ), 0)
        FROM bookings
        WHERE operator_id = ?
          AND booking_type = 'package'
          AND package_name = ?
          AND booking_date = ?
          AND LOWER(COALESCE(status, '')) IN ('pending', 'accepted')
          AND LOWER(COALESCE(is_complete, 'uncomplete')) = 'uncomplete'");
    $stmt->execute([$operatorId, $packageName, $date]);
    $booked = max(0, (int)$stmt->fetchColumn());
    try {
        $draftStmt = $pdo->prepare("SELECT COALESCE(SUM(GREATEST(1,
                CAST(COALESCE(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.num_adults')), ''), '0') AS UNSIGNED) +
                CAST(COALESCE(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.num_children')), ''), '0') AS UNSIGNED)
            )), 0)
            FROM booking_checkout_drafts
            WHERE booking_domain = 'package' AND status = 'pending' AND expires_at > NOW()
              AND JSON_UNQUOTE(JSON_EXTRACT(payload, '$.operator_id')) = ?
              AND JSON_UNQUOTE(JSON_EXTRACT(payload, '$.package_name')) = ?
              AND JSON_UNQUOTE(JSON_EXTRACT(payload, '$.booking_date')) = ?");
        $draftStmt->execute([(string)$operatorId, $packageName, $date]);
        $booked += max(0, (int)$draftStmt->fetchColumn());
    } catch (Throwable $ignored) {
        // Older installations may not have checkout drafts; confirmed bookings still count.
    }
    return $booked;
}

function packageSlotStatus(PDO $pdo, int $packageId, string $date): ?array
{
    packageSlotEnsureSchema($pdo);
    $stmt = $pdo->prepare("SELECT s.slot_id, s.operator_id, s.package_id, s.slot_date, s.capacity, s.is_open,
                                 p.package_title
                          FROM package_daily_slots s
                          INNER JOIN tour_packages p ON p.package_id = s.package_id
                          WHERE s.package_id = ? AND s.slot_date = ?
                          LIMIT 1");
    $stmt->execute([$packageId, $date]);
    $slot = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$slot) return null;

    $booked = packageSlotBookedGuests($pdo, (int)$slot['operator_id'], (string)$slot['package_title'], $date);
    $capacity = max(0, (int)$slot['capacity']);
    $slot['booked'] = $booked;
    $slot['remaining'] = max(0, $capacity - $booked);
    return $slot;
}

function packageSlotCanAccept(PDO $pdo, int $packageId, string $date, int $requestedGuests): ?bool
{
    $slot = packageSlotStatus($pdo, $packageId, $date);
    if ($slot === null) return null;
    if (!(bool)$slot['is_open']) return false;
    return max(1, $requestedGuests) <= (int)$slot['remaining'];
}

function packageSlotMonth(PDO $pdo, int $operatorId, int $packageId, string $month): array
{
    packageSlotEnsureSchema($pdo);
    $start = DateTimeImmutable::createFromFormat('!Y-m', $month);
    if (!$start || $start->format('Y-m') !== $month) return [];
    $end = $start->modify('last day of this month');

    $packageStmt = $pdo->prepare('SELECT package_title FROM tour_packages WHERE package_id = ? AND operator_id = ? LIMIT 1');
    $packageStmt->execute([$packageId, $operatorId]);
    $packageName = (string)$packageStmt->fetchColumn();
    if ($packageName === '') return [];

    $stmt = $pdo->prepare('SELECT slot_date, capacity, is_open FROM package_daily_slots WHERE package_id = ? AND operator_id = ? AND slot_date BETWEEN ? AND ?');
    $stmt->execute([$packageId, $operatorId, $start->format('Y-m-d'), $end->format('Y-m-d')]);
    $slots = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $slots[(string)$row['slot_date']] = [
            'capacity' => (int)$row['capacity'],
            'is_open' => (bool)$row['is_open'],
            'booked' => 0,
        ];
    }

    $bookedStmt = $pdo->prepare("SELECT booking_date, COALESCE(SUM(
            CASE WHEN COALESCE(pax, 0) > 0 THEN pax
                 WHEN COALESCE(num_adults, 0) + COALESCE(num_children, 0) > 0 THEN COALESCE(num_adults, 0) + COALESCE(num_children, 0)
                 ELSE 1 END), 0) AS booked
        FROM bookings
        WHERE operator_id = ? AND booking_type = 'package' AND package_name = ?
          AND booking_date BETWEEN ? AND ?
          AND LOWER(COALESCE(status, '')) IN ('pending', 'accepted')
          AND LOWER(COALESCE(is_complete, 'uncomplete')) = 'uncomplete'
        GROUP BY booking_date");
    $bookedStmt->execute([$operatorId, $packageName, $start->format('Y-m-d'), $end->format('Y-m-d')]);
    foreach ($bookedStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $date = (string)$row['booking_date'];
        if (!isset($slots[$date])) $slots[$date] = ['capacity' => null, 'is_open' => null, 'booked' => 0];
        $slots[$date]['booked'] = (int)$row['booked'];
    }

    foreach ($slots as &$slot) {
        $slot['remaining'] = $slot['capacity'] === null ? null : max(0, (int)$slot['capacity'] - (int)$slot['booked']);
    }
    unset($slot);
    return $slots;
}
