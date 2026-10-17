<?php

declare(strict_types=1);

require_once __DIR__ . '/package_slot_helper.php';
require_once __DIR__ . '/booking_cancellations_helper.php';

function tourResourceMeta(string $type): ?array
{
    return match (strtolower(trim($type))) {
        'boat' => ['domain' => 'boat', 'column' => 'boat_id'],
        'tourguide', 'guide' => ['domain' => 'tourguide', 'column' => 'guide_id'],
        default => null,
    };
}

function tourResourceDate(string $value): string
{
    $value = trim($value);
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return $date && $date->format('Y-m-d') === $value ? $value : '';
}

function tourResourceEndDate(array $row): string
{
    $start = tourResourceDate((string)($row['booking_date'] ?? ''));
    if ($start === '') return '';

    $range = trim((string)($row['tour_range'] ?? ''));
    if (preg_match('/^\d{4}-\d{2}-\d{2}\s+to\s+(\d{4}-\d{2}-\d{2})$/i', $range, $match)) {
        $end = tourResourceDate($match[1]);
        if ($end !== '' && $end >= $start) return $end;
    }

    if (preg_match('/(\d+)\s*days?\s+(\d+)\s*nights?/i', $range, $match)) {
        $occupiedDaysAfterStart = max(0, (int)$match[2]);
        return (new DateTimeImmutable($start))->modify('+' . $occupiedDaysAfterStart . ' days')->format('Y-m-d');
    }

    if (strtolower((string)($row['tour_type'] ?? '')) === 'overnight') {
        return (new DateTimeImmutable($start))->modify('+1 day')->format('Y-m-d');
    }
    return $start;
}

function tourResourceActiveRanges(PDO $pdo, string $type, ?int $resourceId = null, ?int $excludeBookingId = null): array
{
    ensureBookingCancellationRequestsTable($pdo);
    $meta = tourResourceMeta($type);
    if (!$meta) return [];

    $whereId = $resourceId !== null ? " AND {$meta['column']} = :resource_id" : " AND {$meta['column']} IS NOT NULL";
    $excludeSql = $excludeBookingId !== null ? " AND booking_id <> " . (int)$excludeBookingId : "";
    $stmt = $pdo->prepare("SELECT {$meta['column']} AS resource_id, booking_date, tour_type, tour_range
        FROM bookings
        WHERE LOWER(COALESCE(status, '')) IN ('pending', 'accepted')
          AND LOWER(COALESCE(is_complete, 'uncomplete')) = 'uncomplete'
          AND NOT EXISTS (
              SELECT 1 FROM booking_cancellation_requests cr
              WHERE cr.booking_domain='tour' AND cr.booking_id=bookings.booking_id
                AND cr.request_status='decision_required'
          )
          {$whereId}{$excludeSql}");
    $params = $resourceId !== null ? ['resource_id' => $resourceId] : [];
    $stmt->execute($params);
    $ranges = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $start = tourResourceDate((string)$row['booking_date']);
        $end = tourResourceEndDate($row);
        if ($start !== '' && $end !== '') {
            $ranges[] = ['resource_id' => (int)$row['resource_id'], 'start' => $start, 'end' => $end];
        }
    }

    try {
        $draftStmt = $pdo->query("SELECT payload FROM booking_checkout_drafts
            WHERE booking_domain IN ('boat','tourguide') AND status='pending' AND expires_at>NOW()");
        foreach ($draftStmt->fetchAll(PDO::FETCH_ASSOC) as $draft) {
            $row = json_decode((string)$draft['payload'], true);
            if (!is_array($row)) continue;
            $ids = [(int)($row[$meta['column']] ?? 0)];
            // Historical extra preferences did not reserve resources.
            if ((int)($row['resource_allocation_version'] ?? 0) >= 2) {
                $key = $meta['domain'] === 'boat' ? 'additional_boat_ids' : 'additional_guide_ids';
                $ids = array_merge($ids, $row[$key] ?? []);
            }
            $start = tourResourceDate((string)($row['booking_date'] ?? ''));
            $end = tourResourceDate((string)($row['booking_end_date'] ?? '')) ?: tourResourceEndDate($row);
            foreach (array_unique(array_map('intval', $ids)) as $id) {
                if ($id > 0 && ($resourceId === null || $id === $resourceId) && $start !== '' && $end !== '') {
                    $ranges[] = ['resource_id' => $id, 'start' => $start, 'end' => $end];
                }
            }
        }
    } catch (PDOException $error) {
        $missingTable = (string)$error->getCode() === '42S02' || str_contains($error->getMessage(), 'no such table');
        if (!$missingTable) throw $error;
    }

    // Additional boats reserve the same inclusive dates as the primary boat.
    // Older installations have no allocations table until the new form is used.
    if (in_array($meta['domain'], ['boat','tourguide'], true)) {
        $allocationTable = $meta['domain'] === 'boat' ? 'booking_resource_boats' : 'booking_resource_guides';
        $allocationColumn = $meta['column'];
        try {
            $extra = $pdo->prepare("SELECT rb.{$allocationColumn} AS resource_id, b.booking_date, b.tour_type, b.tour_range
                FROM {$allocationTable} rb JOIN bookings b ON b.booking_id=rb.booking_id
                WHERE LOWER(COALESCE(b.status,'')) IN ('pending','accepted')
                  AND LOWER(COALESCE(b.is_complete,'uncomplete'))='uncomplete'
                  AND NOT EXISTS (SELECT 1 FROM booking_cancellation_requests cr
                    WHERE cr.booking_domain='tour' AND cr.booking_id=b.booking_id AND cr.request_status='decision_required')"
                . ($resourceId !== null ? ' AND rb.' . $allocationColumn . '=?' : '')
                . ($excludeBookingId !== null ? ' AND b.booking_id<>' . (int)$excludeBookingId : ''));
            $extra->execute($resourceId !== null ? [$resourceId] : []);
            foreach ($extra->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $start = tourResourceDate((string)$row['booking_date']);
                $end = tourResourceEndDate($row);
                if ($start !== '' && $end !== '') $ranges[] = ['resource_id' => (int)$row['resource_id'], 'start' => $start, 'end' => $end];
            }
        } catch (PDOException $error) {
            $missingTable = (string)$error->getCode() === '42S02' || str_contains($error->getMessage(), 'no such table');
            if (!$missingTable) throw $error;
        }
    }
    return $ranges;
}

function tourResourceRangesOverlap(string $start, string $end, string $otherStart, string $otherEnd): bool
{
    return $start <= $otherEnd && $end >= $otherStart;
}

function tourResourceIsAvailable(PDO $pdo, string $type, int $resourceId, string $start, string $end = '', ?int $excludeBookingId = null): bool
{
    $start = tourResourceDate($start);
    $end = tourResourceDate($end !== '' ? $end : $start);
    if (!tourResourceMeta($type) || $resourceId < 1 || $start === '' || $end === '' || $end < $start) return false;

    foreach (tourResourceActiveRanges($pdo, $type, $resourceId, $excludeBookingId) as $range) {
        if (tourResourceRangesOverlap($start, $end, $range['start'], $range['end'])) return false;
    }
    return true;
}

function tourResourceUnavailableDates(PDO $pdo, string $type, int $resourceId, string $from, string $to): array
{
    $from = tourResourceDate($from);
    $to = tourResourceDate($to);
    if ($from === '' || $to === '' || $to < $from) return [];
    $dates = [];
    foreach (tourResourceActiveRanges($pdo, $type, $resourceId) as $range) {
        $start = max($from, $range['start']);
        $end = min($to, $range['end']);
        if ($end < $start) continue;
        for ($date = new DateTimeImmutable($start), $last = new DateTimeImmutable($end); $date <= $last; $date = $date->modify('+1 day')) {
            $dates[$date->format('Y-m-d')] = true;
        }
    }
    return array_keys($dates);
}

function tourPackageActiveRanges(PDO $pdo, string $packageName, ?int $operatorId = null, ?int $packageId = null): array
{
    ensureBookingCancellationRequestsTable($pdo);
    $packageName = trim($packageName);
    if ($packageName === '') return [];

    $bookingOperatorSql = $operatorId !== null && $operatorId > 0 ? ' AND operator_id = :operator_id' : '';
    $stmt = $pdo->prepare("SELECT booking_date, tour_type, tour_range
        FROM bookings
        WHERE booking_type = 'package'
          AND package_name = :package_name
          {$bookingOperatorSql}
          AND LOWER(COALESCE(status, '')) IN ('pending', 'accepted')
          AND LOWER(COALESCE(is_complete, 'uncomplete')) = 'uncomplete'
          AND NOT EXISTS (
              SELECT 1 FROM booking_cancellation_requests cr
              WHERE cr.booking_domain='tour' AND cr.booking_id=bookings.booking_id
                AND cr.request_status='decision_required'
          )");
    $bookingParams = ['package_name' => $packageName];
    if ($bookingOperatorSql !== '') $bookingParams['operator_id'] = $operatorId;
    $stmt->execute($bookingParams);
    $ranges = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $start = tourResourceDate((string)($row['booking_date'] ?? ''));
        $end = tourResourceEndDate($row);
        if ($start !== '' && $end !== '') $ranges[] = ['start' => $start, 'end' => $end];
    }

    try {
        $draftOperatorSql = $operatorId !== null && $operatorId > 0
            ? " AND JSON_UNQUOTE(JSON_EXTRACT(payload, '$.operator_id')) = :operator_id"
            : '';
        $draftPackageSql = $packageId !== null && $packageId > 0
            ? " AND JSON_UNQUOTE(JSON_EXTRACT(payload, '$.package_id')) = :package_id"
            : '';
        $draftStmt = $pdo->prepare("SELECT
                JSON_UNQUOTE(JSON_EXTRACT(payload, '$.booking_date')) AS booking_date,
                JSON_UNQUOTE(JSON_EXTRACT(payload, '$.booking_end_date')) AS booking_end_date,
                JSON_UNQUOTE(JSON_EXTRACT(payload, '$.tour_type')) AS tour_type,
                JSON_UNQUOTE(JSON_EXTRACT(payload, '$.tour_range')) AS tour_range
            FROM booking_checkout_drafts
            WHERE booking_domain = 'package' AND status = 'pending' AND expires_at > NOW()
              AND JSON_UNQUOTE(JSON_EXTRACT(payload, '$.package_name')) = :package_name
              {$draftOperatorSql}{$draftPackageSql}");
        $draftParams = ['package_name' => $packageName];
        if ($draftOperatorSql !== '') $draftParams['operator_id'] = (string)$operatorId;
        if ($draftPackageSql !== '') $draftParams['package_id'] = (string)$packageId;
        $draftStmt->execute($draftParams);
        foreach ($draftStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $start = tourResourceDate((string)($row['booking_date'] ?? ''));
            $end = tourResourceDate((string)($row['booking_end_date'] ?? ''));
            if ($end === '') $end = tourResourceEndDate($row);
            if ($start !== '' && $end !== '') $ranges[] = ['start' => $start, 'end' => $end];
        }
    } catch (Throwable $ignored) {
    }

    return $ranges;
}

function tourPackageIsAvailable(PDO $pdo, string $packageName, string $start, string $end = '', int $requestedGuests = 1, ?int $operatorId = null, ?int $packageId = null): bool
{
    $start = tourResourceDate($start);
    $end = tourResourceDate($end !== '' ? $end : $start);
    if (trim($packageName) === '' || $start === '' || $end === '' || $end < $start) return false;

    $package = null;
    if ($packageId !== null && $packageId > 0) {
        $packageSql = 'SELECT package_id, operator_id, package_title FROM tour_packages WHERE package_id = ? AND package_title = ?';
        $packageParams = [$packageId, $packageName];
        if ($operatorId !== null && $operatorId > 0) {
            $packageSql .= ' AND operator_id = ?';
            $packageParams[] = $operatorId;
        }
        $packageSql .= ' LIMIT 1';
        $packageStmt = $pdo->prepare($packageSql);
        $packageStmt->execute($packageParams);
        $package = $packageStmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$package) return false;
    } else {
        $package = packageSlotResolvePackage($pdo, $packageName, $operatorId);
    }
    if ($package) {
        $slotAvailability = packageSlotCanAccept($pdo, (int)$package['package_id'], $start, max(1, $requestedGuests));
        if ($slotAvailability !== null) return $slotAvailability;
    }

    foreach (tourPackageActiveRanges($pdo, $packageName, $operatorId, $packageId) as $range) {
        if (tourResourceRangesOverlap($start, $end, $range['start'], $range['end'])) return false;
    }
    return true;
}

function tourPackageUnavailableDates(PDO $pdo, string $packageName, string $from, string $to, ?int $operatorId = null, int $requestedGuests = 1, ?int $packageId = null): array
{
    $from = tourResourceDate($from);
    $to = tourResourceDate($to);
    if ($from === '' || $to === '' || $to < $from) return [];
    $dates = [];
    foreach (tourPackageActiveRanges($pdo, $packageName, $operatorId, $packageId) as $range) {
        $start = max($from, $range['start']);
        $end = min($to, $range['end']);
        if ($end < $start) continue;
        for ($date = new DateTimeImmutable($start), $last = new DateTimeImmutable($end); $date <= $last; $date = $date->modify('+1 day')) {
            $dates[$date->format('Y-m-d')] = true;
        }
    }
    $package = null;
    if ($packageId !== null && $packageId > 0) {
        $packageSql = 'SELECT package_id, operator_id, package_title FROM tour_packages WHERE package_id = ? AND package_title = ?';
        $packageParams = [$packageId, $packageName];
        if ($operatorId !== null && $operatorId > 0) {
            $packageSql .= ' AND operator_id = ?';
            $packageParams[] = $operatorId;
        }
        $packageSql .= ' LIMIT 1';
        $packageStmt = $pdo->prepare($packageSql);
        $packageStmt->execute($packageParams);
        $package = $packageStmt->fetch(PDO::FETCH_ASSOC) ?: null;
    } else {
        $package = packageSlotResolvePackage($pdo, $packageName, $operatorId);
    }
    if ($package) {
        packageSlotEnsureSchema($pdo);
        $configuredStmt = $pdo->prepare('SELECT slot_date FROM package_daily_slots WHERE package_id = ? AND slot_date BETWEEN ? AND ?');
        $configuredStmt->execute([(int)$package['package_id'], $from, $to]);
        foreach ($configuredStmt->fetchAll(PDO::FETCH_COLUMN) as $configuredDate) {
            $key = (string)$configuredDate;
            $slot = packageSlotStatus($pdo, (int)$package['package_id'], $key);
            if ($slot === null) continue;
            if ((bool)$slot['is_open'] && (int)$slot['remaining'] >= max(1, $requestedGuests)) unset($dates[$key]);
            else $dates[$key] = true;
        }
    }
    return array_keys($dates);
}

function tourPackageLockName(string $packageName): string
{
    $packageName = trim($packageName);
    if ($packageName === '') return '';
    $prefix = 'tour-package-';
    return $prefix . substr(hash('sha256', strtolower($packageName)), 0, 64 - strlen($prefix));
}

function tourPackageLock(PDO $pdo, string $packageName): string
{
    $name = tourPackageLockName($packageName);
    if ($name === '') return '';
    $stmt = $pdo->prepare('SELECT GET_LOCK(?, 5)');
    $stmt->execute([$name]);
    if ((int)$stmt->fetchColumn() !== 1) throw new RuntimeException('Availability is being updated. Please try again.');
    return $name;
}

function tourResourceLock(PDO $pdo, string $type, int $resourceId): string
{
    $meta = tourResourceMeta($type);
    if (!$meta || $resourceId < 1) return '';
    $name = 'tour-resource-' . $meta['domain'] . '-' . $resourceId;
    $stmt = $pdo->prepare('SELECT GET_LOCK(?, 5)');
    $stmt->execute([$name]);
    if ((int)$stmt->fetchColumn() !== 1) throw new RuntimeException('Availability is being updated. Please try again.');
    return $name;
}

function tourResourceUnlock(PDO $pdo, string $name): void
{
    if ($name === '') return;
    try {
        $stmt = $pdo->prepare('SELECT RELEASE_LOCK(?)');
        $stmt->execute([$name]);
    } catch (Throwable $ignored) {
    }
}

/** Load allocations without changing legacy primary-only bookings. */
function tourBookingAssignedResources(PDO $pdo, array $booking): array
{
    $resources = ['boat' => [], 'tourguide' => []];
    foreach (['boat' => 'boat_id', 'tourguide' => 'guide_id'] as $type => $column) {
        if ((int)($booking[$column] ?? 0) > 0) $resources[$type][] = (int)$booking[$column];
        $table = $type === 'boat' ? 'booking_resource_boats' : 'booking_resource_guides';
        try {
            $stmt = $pdo->prepare("SELECT {$column} FROM {$table} WHERE booking_id=?");
            $stmt->execute([(int)$booking['booking_id']]);
            $resources[$type] = array_values(array_unique(array_merge($resources[$type], array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)))));
        } catch (PDOException $error) {
            if ((string)$error->getCode() !== '42S02' && !str_contains($error->getMessage(), 'no such table')) throw $error;
        }
        sort($resources[$type], SORT_NUMERIC);
    }
    return $resources;
}
