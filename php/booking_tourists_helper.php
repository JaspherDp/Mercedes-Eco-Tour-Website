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
