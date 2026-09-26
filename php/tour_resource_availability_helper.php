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

function tourResourceActiveRanges(PDO $pdo, string $type, ?int $resourceId = null): array
{
    ensureBookingCancellationRequestsTable($pdo);
    $meta = tourResourceMeta($type);
    if (!$meta) return [];

    $whereId = $resourceId !== null ? " AND {$meta['column']} = :resource_id" : " AND {$meta['column']} IS NOT NULL";
    $stmt = $pdo->prepare("SELECT {$meta['column']} AS resource_id, booking_date, tour_type, tour_range
        FROM bookings
        WHERE LOWER(COALESCE(status, '')) IN ('pending', 'accepted')
          AND LOWER(COALESCE(is_complete, 'uncomplete')) = 'uncomplete'
          AND NOT EXISTS (
              SELECT 1 FROM booking_cancellation_requests cr
              WHERE cr.booking_domain='tour' AND cr.booking_id=bookings.booking_id
                AND cr.request_status='decision_required'
          )
          {$whereId}");
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
        $draftSql = "SELECT JSON_UNQUOTE(JSON_EXTRACT(payload, '$.{$meta['column']}')) AS resource_id,
                            JSON_UNQUOTE(JSON_EXTRACT(payload, '$.booking_date')) AS booking_date,
                            JSON_UNQUOTE(JSON_EXTRACT(payload, '$.booking_end_date')) AS booking_end_date,
                            JSON_UNQUOTE(JSON_EXTRACT(payload, '$.tour_type')) AS tour_type,
                            JSON_UNQUOTE(JSON_EXTRACT(payload, '$.tour_range')) AS tour_range
                     FROM booking_checkout_drafts
                     WHERE booking_domain = :domain AND status = 'pending' AND expires_at > NOW()";
        if ($resourceId !== null) {
            $draftSql .= " AND JSON_UNQUOTE(JSON_EXTRACT(payload, '$.{$meta['column']}')) = :draft_resource_id";
        }
        $draftStmt = $pdo->prepare($draftSql);
        $draftParams = ['domain' => $meta['domain']];
        if ($resourceId !== null) $draftParams['draft_resource_id'] = (string)$resourceId;
        $draftStmt->execute($draftParams);
        foreach ($draftStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $id = (int)($row['resource_id'] ?? 0);
            $start = tourResourceDate((string)($row['booking_date'] ?? ''));
            $end = tourResourceDate((string)($row['booking_end_date'] ?? ''));
            if ($end === '') $end = tourResourceEndDate($row);
            if ($id > 0 && $start !== '' && $end !== '') {
                $ranges[] = ['resource_id' => $id, 'start' => $start, 'end' => $end];
            }
        }
    } catch (Throwable $ignored) {
        // Checkout drafts may not exist on older installations; confirmed bookings still apply.
    }

    return $ranges;
}

function tourResourceRangesOverlap(string $start, string $end, string $otherStart, string $otherEnd): bool
{
    return $start <= $otherEnd && $end >= $otherStart;
}

function tourResourceIsAvailable(PDO $pdo, string $type, int $resourceId, string $start, string $end = ''): bool
{
    $start = tourResourceDate($start);
    $end = tourResourceDate($end !== '' ? $end : $start);
    if (!tourResourceMeta($type) || $resourceId < 1 || $start === '' || $end === '' || $end < $start) return false;

    foreach (tourResourceActiveRanges($pdo, $type, $resourceId) as $range) {
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
