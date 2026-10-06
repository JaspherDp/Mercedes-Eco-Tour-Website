<?php

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
