<?php
declare(strict_types=1);

function ensureReviewModerationColumns(PDO $pdo): void
{
    $columns = $pdo->query("SELECT COLUMN_NAME FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'feedback'")
        ->fetchAll(PDO::FETCH_COLUMN);
    $existing = array_fill_keys(array_map('strtolower', $columns), true);
    $changes = [
        'moderation_status' => "ADD COLUMN moderation_status ENUM('published','hidden','flagged') NOT NULL DEFAULT 'published' AFTER comment",
        'admin_note' => "ADD COLUMN admin_note TEXT NULL AFTER moderation_status",
        'admin_reply' => "ADD COLUMN admin_reply TEXT NULL AFTER admin_note",
        'moderated_by' => "ADD COLUMN moderated_by INT NULL AFTER admin_reply",
        'moderated_at' => "ADD COLUMN moderated_at DATETIME NULL AFTER moderated_by",
        'updated_at' => "ADD COLUMN updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at",
    ];
    foreach ($changes as $column => $definition) {
        if (!isset($existing[$column])) $pdo->exec("ALTER TABLE feedback {$definition}");
    }
}

function reviewTypeSql(string $alias = 'f'): string
{
    return "CASE
        WHEN {$alias}.package_id IS NOT NULL OR LOWER(TRIM(COALESCE({$alias}.booking_type,''))) = 'package' OR LOWER(TRIM(COALESCE({$alias}.service_type,''))) IN ('package','tourpackage','tour_package') THEN 'package'
        WHEN {$alias}.tourguide_id IS NOT NULL OR LOWER(TRIM(COALESCE({$alias}.booking_type,''))) = 'tourguide' OR LOWER(TRIM(COALESCE({$alias}.service_type,''))) IN ('tourguide','guide','tour_guide') THEN 'tourguide'
        WHEN {$alias}.boat_id IS NOT NULL OR LOWER(TRIM(COALESCE({$alias}.booking_type,''))) = 'boat' OR LOWER(TRIM(COALESCE({$alias}.service_type,''))) = 'boat' THEN 'boat'
        WHEN LOWER(TRIM(COALESCE({$alias}.service_type,''))) IN ('service','services') THEN 'service'
        ELSE NULL END";
}

function reviewTypeLabel(string $type): string
{
    return ['package'=>'Tour Package','tourguide'=>'Tour Guide','boat'=>'Boat','service'=>'Service'][$type] ?? 'Service';
}
