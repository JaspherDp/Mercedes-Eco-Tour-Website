<?php

declare(strict_types=1);

function favoriteTypes(): array
{
    return [
        'hotel' => ['table' => 'hotel_resorts', 'id' => 'hotel_resort_id'],
        'package' => ['table' => 'tour_packages', 'id' => 'package_id'],
        'guide' => ['table' => 'tour_guides', 'id' => 'guide_id'],
        'boat' => ['table' => 'boats', 'id' => 'boat_id'],
    ];
}

function ensureFavoritesTable(PDO $pdo): void
{
    static $ready = false;
    if ($ready) {
        return;
    }

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS tourist_favorites (
            favorite_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            tourist_id INT NOT NULL,
            entity_type ENUM('hotel', 'package', 'guide', 'boat') NOT NULL,
            entity_id INT NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (favorite_id),
            UNIQUE KEY uq_tourist_favorite (tourist_id, entity_type, entity_id),
            KEY idx_favorites_tourist_created (tourist_id, created_at),
            KEY idx_favorites_entity (entity_type, entity_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $ready = true;
}

function favoriteCsrfToken(): string
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
    require_once __DIR__ . '/session_security.php';
    AppSessionStart();
    }
    if (empty($_SESSION['favorites_csrf'])) {
        $_SESSION['favorites_csrf'] = bin2hex(random_bytes(24));
    }
    return (string)$_SESSION['favorites_csrf'];
}

function isValidFavoriteEntity(PDO $pdo, string $type, int $entityId): bool
{
    $types = favoriteTypes();
    if (!isset($types[$type]) || $entityId <= 0) {
        return false;
    }

    $definition = $types[$type];
    $statusFilter = $type === 'hotel' ? " AND status = 'active'" : '';
    $stmt = $pdo->prepare(
        "SELECT 1 FROM {$definition['table']} WHERE {$definition['id']} = ?{$statusFilter} LIMIT 1"
    );
    $stmt->execute([$entityId]);
    return (bool)$stmt->fetchColumn();
}

function isFavorite(PDO $pdo, int $touristId, string $type, int $entityId): bool
{
    if ($touristId <= 0 || !isset(favoriteTypes()[$type]) || $entityId <= 0) {
        return false;
    }

    ensureFavoritesTable($pdo);
    $stmt = $pdo->prepare("
        SELECT 1
        FROM tourist_favorites
        WHERE tourist_id = ? AND entity_type = ? AND entity_id = ?
        LIMIT 1
    ");
    $stmt->execute([$touristId, $type, $entityId]);
    return (bool)$stmt->fetchColumn();
}

function favoriteIdsByType(PDO $pdo, int $touristId): array
{
    $result = ['hotel' => [], 'package' => [], 'guide' => [], 'boat' => []];
    if ($touristId <= 0) {
        return $result;
    }

    ensureFavoritesTable($pdo);
    $stmt = $pdo->prepare("
        SELECT entity_type, entity_id
        FROM tourist_favorites
        WHERE tourist_id = ?
    ");
    $stmt->execute([$touristId]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $type = (string)$row['entity_type'];
        if (isset($result[$type])) {
            $result[$type][(int)$row['entity_id']] = true;
        }
    }
    return $result;
}

function getTouristFavorites(PDO $pdo, int $touristId): array
{
    ensureFavoritesTable($pdo);
    $queries = [
        'hotel' => "
            SELECT f.favorite_id, f.entity_type, f.entity_id, f.created_at,
                   h.name AS title,
                   CONCAT(COALESCE(h.island, 'Mercedes'), ' · ', UPPER(COALESCE(h.type, 'Stay'))) AS subtitle,
                   h.image_path AS image, h.price AS price
            FROM tourist_favorites f
            INNER JOIN hotel_resorts h ON h.hotel_resort_id = f.entity_id
            WHERE f.tourist_id = ? AND f.entity_type = 'hotel'
        ",
        'package' => "
            SELECT f.favorite_id, f.entity_type, f.entity_id, f.created_at,
                   p.package_title AS title,
                   CONCAT('Tour Package · ', UPPER(REPLACE(COALESCE(p.package_type, 'tour'), '-', ' '))) AS subtitle,
                   p.package_image AS image, p.price AS price
            FROM tourist_favorites f
            INNER JOIN tour_packages p ON p.package_id = f.entity_id
            WHERE f.tourist_id = ? AND f.entity_type = 'package'
        ",
        'guide' => "
            SELECT f.favorite_id, f.entity_type, f.entity_id, f.created_at,
                   g.fullname AS title,
                   CONCAT('Tour Guide · ', COALESCE(g.experience, 0), ' yrs experience') AS subtitle,
                   g.profile_picture AS image, NULL AS price
            FROM tourist_favorites f
            INNER JOIN tour_guides g ON g.guide_id = f.entity_id
            WHERE f.tourist_id = ? AND f.entity_type = 'guide'
        ",
        'boat' => "
            SELECT f.favorite_id, f.entity_type, f.entity_id, f.created_at,
                   b.name AS title,
                   CONCAT('Tour Boat · Up to ', COALESCE(b.total_pax, 0), ' guests') AS subtitle,
                   b.image1 AS image, NULL AS price
            FROM tourist_favorites f
            INNER JOIN boats b ON b.boat_id = f.entity_id
            WHERE f.tourist_id = ? AND f.entity_type = 'boat'
        ",
    ];
    $links = [
        'hotel' => '/hotel_details.php?id=',
        'package' => '/package_details.php?package_id=',
        'guide' => '/tourss.php?tab=tour-guides&favorite_id=',
        'boat' => '/tourss.php?tab=our-boats&favorite_id=',
    ];

    $items = [];
    foreach ($queries as $type => $sql) {
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$touristId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $item) {
            $item['link_prefix'] = $links[$type];
            $items[] = $item;
        }
    }

    usort($items, static function (array $left, array $right): int {
        $dateCompare = strcmp((string)$right['created_at'], (string)$left['created_at']);
        return $dateCompare !== 0
            ? $dateCompare
            : ((int)$right['favorite_id'] <=> (int)$left['favorite_id']);
    });
    return $items;
}
