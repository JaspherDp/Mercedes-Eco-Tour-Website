<?php
require_once __DIR__ . '/booking_tourist_manifest_validation.php';
require_once __DIR__ . '/hotel_booking_tourists_helper.php';

/** Editing closes at 00:00 Manila time, three calendar days before the tour. */
function bookingTouristEditDeadline(array $booking): ?DateTimeImmutable
{
    $zone = new DateTimeZone('Asia/Manila');
    $value = (string)($booking['booking_date'] ?? '');
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, $zone);
    return $date && $date->format('Y-m-d') === $value ? $date->modify('-3 days') : null;
}

function bookingTouristCanEdit(array $booking, ?DateTimeImmutable $now = null): bool
{
    $deadline = bookingTouristEditDeadline($booking);
    return strtolower((string)($booking['status'] ?? '')) === 'accepted'
        && strtolower((string)($booking['is_complete'] ?? '')) === 'uncomplete'
        && $deadline !== null
        && ($now ?? new DateTimeImmutable('now', new DateTimeZone('Asia/Manila'))) < $deadline;
}

/** Save the complete validated manifest atomically, checking ownership and cutoff under a booking lock. */
function bookingTouristSaveManifest(PDO $pdo, int $bookingId, int $touristId, array $rows, array $boatNumbers, bool $editing, string $domain = 'tour'): void
{
    if (!in_array($domain, ['tour', 'hotel'], true)) throw new InvalidArgumentException('Invalid booking domain.');
    bookingTouristWriteManifest($pdo, $bookingId, $touristId, $rows, $boatNumbers, $editing, false, $domain);
}

/** Only the authenticated Admin endpoint uses this no-account walk-in scope. */
function bookingTouristSaveAdminWalkinManifest(PDO $pdo, int $bookingId, int $adminId, array $rows, array $boatNumbers, bool $editing): void
{
    if ($adminId < 1) throw new RuntimeException('An authenticated administrator is required.');
    bookingTouristWriteManifest($pdo, $bookingId, 0, $rows, $boatNumbers, $editing, true);
}

function bookingTouristAdminWalkinCanManage(array $booking): bool
{
    return array_key_exists('tourist_id', $booking) && $booking['tourist_id'] === null
        && trim((string)($booking['walkin_guest_name'] ?? '')) !== ''
        && stripos((string)($booking['preferred_resource'] ?? ''), 'Walk-in booking') !== false
        && strtolower((string)($booking['status'] ?? '')) === 'accepted'
        && strtolower((string)($booking['is_complete'] ?? '')) === 'uncomplete'
        && (int)($booking['is_paid'] ?? 0) === 1 && (float)($booking['grand_total'] ?? 0) > 0
        && (float)($booking['remaining_balance'] ?? 1) <= 0
        && (float)($booking['payment_amount'] ?? 0) >= (float)$booking['grand_total'];
}

/** Internal writer: tourist ownership/cutoff remains separate from staff scope. */
function bookingTouristWriteManifest(PDO $pdo, int $bookingId, int $touristId, array $rows, array $boatNumbers, bool $editing, bool $adminWalkin = false, string $domain = 'tour'): void
{
    if (!in_array($domain, ['tour','hotel'], true) || ($adminWalkin && $domain !== 'tour')) throw new InvalidArgumentException('Invalid booking domain.');
    $table = $domain === 'hotel' ? 'hotel_booking_tourists' : 'booking_tourists';
    if ($domain === 'hotel') hotelBookingTouristsEnsureSchema($pdo);
    $pdo->beginTransaction();
    try {
        $lock = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
        if ($domain === 'hotel') {
            $booking = hotelBookingTouristRecord($pdo, $bookingId, $touristId, true);
        } else {
            $ownership = $adminWalkin ? 'tourist_id IS NULL' : 'tourist_id = ?';
            $check = $pdo->prepare('SELECT *, COALESCE(NULLIF(pax, 0), num_adults + num_children) AS allowed_pax FROM bookings WHERE booking_id = ? AND ' . $ownership . ' LIMIT 1' . $lock);
            $check->execute($adminWalkin ? [$bookingId] : [$bookingId, $touristId]);
            $booking = $check->fetch(PDO::FETCH_ASSOC);
        }
        if (!$booking || strtolower((string)$booking['status']) !== 'accepted' || strtolower((string)$booking['is_complete']) !== 'uncomplete') {
            throw new RuntimeException('Tourist lists can only be saved for your active accepted bookings.');
        }
        if ($adminWalkin && !bookingTouristAdminWalkinCanManage($booking)) throw new RuntimeException('Only paid, active no-account walk-in bookings can be managed by this form.');
        $check = $pdo->prepare("SELECT COUNT(*) FROM booking_cancellation_requests WHERE booking_domain = ? AND booking_id = ? AND request_status IN ('pending','approved','decision_required','completed')");
        $check->execute([$domain, $bookingId]);
        if ((int)$check->fetchColumn() > 0) throw new RuntimeException('The tourist list cannot be changed while this booking is in a cancellation workflow.');

        $check = $pdo->prepare("SELECT COUNT(*) FROM {$table} WHERE booking_id = ?");
        $check->execute([$bookingId]);
        $exists = (int)$check->fetchColumn() > 0;
        if ($editing && !$exists) throw new RuntimeException('No submitted tourist list was found. Refresh the page and submit the list first.');
        if (!$editing && $exists) throw new RuntimeException('Tourists already submitted for this booking. Refresh the page and use Edit Tourist if editing is still available.');
        if ($editing && !$adminWalkin && !bookingTouristCanEdit($booking)) throw new RuntimeException('Editing is closed. Tourist lists can only be edited until three days before the booking date (Manila time).');

        $pax = (int)$booking['allowed_pax'];
        if ($pax <= 0 || count($rows) !== $pax) throw new RuntimeException("Complete all {$pax} tourist entries before saving.");
        $rows = bookingTouristAssignBoats($rows, $boatNumbers, (string)$booking['booking_type'], $pax);
        if ($editing) {
            $delete = $pdo->prepare("DELETE FROM {$table} WHERE booking_id = ?");
            $delete->execute([$bookingId]);
        }
        $columns = ['full_name', 'gender', 'age', 'address', 'country', 'region', 'province', 'city', 'barangay', 'postal_code', 'street', 'residence', 'phone_number', 'boat_number'];
        $insert = $pdo->prepare("INSERT INTO {$table} (booking_id, " . implode(', ', $columns) . ') VALUES (' . implode(', ', array_fill(0, 15, '?')) . ')');
        foreach ($rows as $row) {
            $values = [$bookingId];
            foreach ($columns as $column) $values[] = $row[$column];
            $insert->execute($values);
        }
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
}

function ensureBookingTouristManifestColumns(PDO $pdo): void
{
    static $ready = false;
    if ($ready) {
        return;
    }

    $columns = $pdo->query(
        "SELECT COLUMN_NAME
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'booking_tourists'"
    )->fetchAll(PDO::FETCH_COLUMN) ?: [];
    $known = array_fill_keys(array_map('strtolower', $columns), true);

    $definitions = [
        'boat_number' => "SMALLINT UNSIGNED NULL",
        'age' => "TINYINT UNSIGNED NULL AFTER gender",
        'address' => "VARCHAR(500) NULL AFTER age",
        'country' => "VARCHAR(100) NULL AFTER address",
        'region' => "VARCHAR(120) NULL AFTER country",
        'province' => "VARCHAR(120) NULL AFTER region",
        'city' => "VARCHAR(120) NULL AFTER province",
        'barangay' => "VARCHAR(120) NULL AFTER city",
        'postal_code' => "VARCHAR(20) NULL AFTER barangay",
        'street' => "VARCHAR(180) NULL AFTER postal_code",
    ];

    foreach ($definitions as $column => $definition) {
        if (!isset($known[$column])) {
            $pdo->exec("ALTER TABLE booking_tourists ADD COLUMN `{$column}` {$definition}");
        }
    }

    $ready = true;
}

/** Validate explicit boat assignments; other booking types keep a single list. */
function bookingTouristAssignBoats(array $rows, array $numbers, string $type, int $pax): array
{
    if (strtolower($type) !== 'boat') {
        foreach ($rows as &$row) $row['boat_number'] = null;
        return $rows;
    }
    $boatCount = (int)ceil($pax / 8);
    if (count($numbers) !== count($rows)) throw new InvalidArgumentException('Choose a boat for every tourist.');
    $counts = array_fill(1, $boatCount, 0);
    foreach ($rows as $index => &$row) {
        $number = filter_var($numbers[$index] ?? null, FILTER_VALIDATE_INT);
        if ($number === false || $number < 1 || $number > $boatCount) throw new InvalidArgumentException('Invalid tourist boat assignment.');
        if (++$counts[$number] > 8) throw new InvalidArgumentException("Boat {$number} can carry a maximum of 8 tourists.");
        $row['boat_number'] = $number;
    }
    unset($row);
    if (in_array(0, $counts, true)) throw new InvalidArgumentException('Add at least one tourist to each required boat.');
    return $rows;
}

/** Legacy manifests without assignments are split in their original passenger order. */
function bookingTouristBoatManifests(array $passengers): array
{
    $groups = [];
    foreach ($passengers as $index => $passenger) {
        $boat = (int)($passenger['boat_number'] ?? 0);
        if ($boat < 1) $boat = (int)floor($index / 8) + 1;
        $groups[$boat][] = $passenger;
    }
    ksort($groups, SORT_NUMERIC);
    return $groups;
}
