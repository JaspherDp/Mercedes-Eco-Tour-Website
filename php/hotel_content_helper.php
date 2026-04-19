<?php

function HoEnsureHotelResortContentColumns(PDO $pdo): void
{
    $tableExists = false;
    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'hotel_resorts'
        ");
        $stmt->execute();
        $tableExists = (int)$stmt->fetchColumn() > 0;
    } catch (Throwable $e) {
        $tableExists = false;
    }

    if (!$tableExists) {
        return;
    }

    try {
        $pdo->exec("ALTER TABLE hotel_resorts ADD COLUMN description_text TEXT NULL AFTER amenities_json");
    } catch (Throwable $e) {
    }

    try {
        $pdo->exec("ALTER TABLE hotel_resorts ADD COLUMN rules_json TEXT NULL AFTER description_text");
    } catch (Throwable $e) {
    }

    try {
        $pdo->exec("ALTER TABLE hotel_resorts ADD COLUMN gallery_images_json TEXT NULL AFTER rules_json");
    } catch (Throwable $e) {
    }
}

function HoDecodeJsonList(?string $json): array
{
    $decoded = json_decode((string)$json, true);
    if (!is_array($decoded)) {
        return [];
    }

    $out = [];
    foreach ($decoded as $value) {
        $value = trim((string)$value);
        if ($value !== '') {
            $out[] = $value;
        }
    }
    return array_values(array_unique($out));
}

function HoHotelResortsHasColumn(PDO $pdo, string $columnName): bool
{
    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'hotel_resorts' AND COLUMN_NAME = ?
        ");
        $stmt->execute([$columnName]);
        return (int)$stmt->fetchColumn() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

