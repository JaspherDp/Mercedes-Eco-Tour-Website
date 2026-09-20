<?php

function HoEnsureHotelResortContentColumns(PDO $pdo): void
{
    try {
        $stmt = $pdo->prepare("
            SELECT COLUMN_NAME
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'hotel_resorts'
        ");
        $stmt->execute();
        $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
    } catch (Throwable $e) {
        return;
    }

    if (!$columns) {
        return;
    }

    $existing = array_fill_keys(array_map('strtolower', $columns), true);
    $changes = [
        'description_text' => 'ADD COLUMN description_text TEXT NULL AFTER amenities_json',
        'rules_json' => 'ADD COLUMN rules_json TEXT NULL AFTER description_text',
        'gallery_images_json' => 'ADD COLUMN gallery_images_json TEXT NULL AFTER rules_json',
        'owner_content_json' => 'ADD COLUMN owner_content_json LONGTEXT NULL AFTER gallery_images_json',
    ];
    foreach ($changes as $column => $definition) {
        if (!isset($existing[$column])) {
            $pdo->exec("ALTER TABLE hotel_resorts {$definition}");
        }
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

function HoDecodeJsonObject(?string $json): array
{
    $decoded = json_decode((string)$json, true);
    return is_array($decoded) ? $decoded : [];
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

