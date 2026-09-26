<?php

function serviceCatalogTableExists(PDO $pdo, string $table): bool
{
    static $cache = [];
    if (array_key_exists($table, $cache)) {
        return $cache[$table];
    }
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?');
    $stmt->execute([$table]);
    return $cache[$table] = ((int)$stmt->fetchColumn() > 0);
}

function serviceCatalogColumn(PDO $pdo, string $table, array $candidates): ?string
{
    foreach ($candidates as $candidate) {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?');
        $stmt->execute([$table, $candidate]);
        if ((int)$stmt->fetchColumn() > 0) {
            return $candidate;
        }
    }
    return null;
}

function serviceCatalogSelect(?string $column, string $alias, string $fallback = "''"): string
{
    return ($column ? "s.`{$column}`" : $fallback) . " AS `{$alias}`";
}

function serviceCatalogPrices(PDO $pdo): array
{
    $prices = ['boat' => 0.0, 'guide' => 0.0];
    try {
        if (!serviceCatalogTableExists($pdo, 'service_prices')) {
            return $prices;
        }
        $stmt = $pdo->query("SELECT service_type, day_tour_price FROM service_prices WHERE is_active = 1");
        foreach (($stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : []) as $row) {
            $key = strtolower((string)($row['service_type'] ?? ''));
            if ($key === 'tourguide') $key = 'guide';
            if (array_key_exists($key, $prices)) {
                $prices[$key] = (float)($row['day_tour_price'] ?? 0);
            }
        }
    } catch (Throwable $e) {
    }
    return $prices;
}

function serviceCatalogFetch(PDO $pdo, string $type): array
{
    $type = $type === 'guide' ? 'guide' : 'boat';
    $prices = serviceCatalogPrices($pdo);
    $table = $type === 'guide'
        ? 'tour_guides'
        : (serviceCatalogTableExists($pdo, 'boats') ? 'boats' : 'tour_boats');
    if (!serviceCatalogTableExists($pdo, $table)) {
        return [];
    }

    $id = serviceCatalogColumn($pdo, $table, $type === 'guide' ? ['guide_id', 'tour_guide_id', 'id'] : ['tour_boat_id', 'boat_id', 'id']);
    $name = serviceCatalogColumn($pdo, $table, $type === 'guide' ? ['fullname', 'guide_name', 'name'] : ['boat_name', 'name']);
    if (!$id || !$name) {
        return [];
    }

    $status = serviceCatalogColumn($pdo, $table, ['status']);
    $columns = [
        "s.`{$id}` AS id",
        "s.`{$name}` AS name",
        serviceCatalogSelect(serviceCatalogColumn($pdo, $table, ['popular']), 'popular', '0')
    ];

    if ($type === 'boat') {
        $columns[] = serviceCatalogSelect(serviceCatalogColumn($pdo, $table, ['capacity', 'total_pax']), 'capacity', '0');
        $columns[] = serviceCatalogSelect(serviceCatalogColumn($pdo, $table, ['size', 'boat_size']), 'size');
        $columns[] = serviceCatalogSelect(serviceCatalogColumn($pdo, $table, ['boat_number', 'registration_number']), 'boat_number');
        $columns[] = serviceCatalogSelect(serviceCatalogColumn($pdo, $table, ['short_description', 'description']), 'short_description');
        $columns[] = serviceCatalogSelect(serviceCatalogColumn($pdo, $table, ['long_description', 'details']), 'long_description');
        $primaryImage = serviceCatalogColumn($pdo, $table, ['boat_image_path', 'image1', 'image_path']);
        $columns[] = serviceCatalogSelect($primaryImage, 'img');
        for ($index = 1; $index <= 5; $index++) {
            $columns[] = serviceCatalogSelect(serviceCatalogColumn($pdo, $table, ["image{$index}", "boat_image{$index}"]), "image{$index}");
        }
    } else {
        $columns[] = serviceCatalogSelect(serviceCatalogColumn($pdo, $table, ['specialization', 'short_description']), 'specialization', "'Mercedes tours'");
        $columns[] = serviceCatalogSelect(serviceCatalogColumn($pdo, $table, ['short_description', 'description']), 'description');
        $columns[] = serviceCatalogSelect(serviceCatalogColumn($pdo, $table, ['age']), 'age', '0');
        $columns[] = serviceCatalogSelect(serviceCatalogColumn($pdo, $table, ['experience', 'years_experience']), 'experience', '0');
        $columns[] = serviceCatalogSelect(serviceCatalogColumn($pdo, $table, ['profile_picture', 'guide_image_path', 'image_path']), 'img');
    }

    $ratingSelect = '0 AS rating, 0 AS total_reviews';
    $ratingJoin = '';
    if (serviceCatalogTableExists($pdo, 'feedback')) {
        $feedbackId = serviceCatalogColumn($pdo, 'feedback', [$type === 'boat' ? 'boat_id' : 'tourguide_id']);
        $feedbackRating = serviceCatalogColumn($pdo, 'feedback', ['rating']);
        if ($feedbackId && $feedbackRating) {
            $feedbackVisibility = serviceCatalogColumn($pdo, 'feedback', ['moderation_status']);
            $publishedOnly = $feedbackVisibility ? " AND `{$feedbackVisibility}` = 'published'" : '';
            $ratingSelect = 'COALESCE(fr.rating, 0) AS rating, COALESCE(fr.total_reviews, 0) AS total_reviews';
            $ratingJoin = "LEFT JOIN (SELECT `{$feedbackId}` AS service_id, ROUND(AVG(`{$feedbackRating}`), 1) AS rating, COUNT(*) AS total_reviews FROM feedback WHERE `{$feedbackId}` IS NOT NULL{$publishedOnly} GROUP BY `{$feedbackId}`) fr ON fr.service_id = s.`{$id}`";
        }
    }

    $where = $status ? "WHERE LOWER(TRIM(s.`{$status}`)) = 'active'" : '';
    $sql = 'SELECT ' . implode(', ', $columns) . ", {$ratingSelect} FROM `{$table}` s {$ratingJoin} {$where} ORDER BY s.`{$id}` DESC LIMIT 50";
    try {
        $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        if (!$where) return [];
        try {
            $rows = $pdo->query(str_replace($where, '', $sql))->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $ignored) {
            return [];
        }
    }

    foreach ($rows as &$row) {
        $row['id'] = (int)($row['id'] ?? 0);
        $row['price'] = $prices[$type];
        $row['rating'] = (float)($row['rating'] ?? 0);
        $row['total_reviews'] = (int)($row['total_reviews'] ?? 0);
        if ($type === 'boat') {
            $row['images'] = array_values(array_unique(array_filter(array_map('trim', [
                (string)($row['image1'] ?? ''), (string)($row['image2'] ?? ''),
                (string)($row['image3'] ?? ''), (string)($row['image4'] ?? ''),
                (string)($row['image5'] ?? ''), (string)($row['img'] ?? '')
            ]))));
        } else {
            $row['images'] = array_values(array_filter([(string)($row['img'] ?? '')]));
        }
    }
    unset($row);
    return $rows;
}

function serviceCatalogImage(string $path, string $type): string
{
    $path = ltrim(str_replace('\\', '/', trim($path)), '/');
    if ($path === '') return $type === 'guide' ? 'img/default-guide.png' : 'img/default-boat.png';
    if (preg_match('#^(https?:|data:)#i', $path)) return $path;
    if (strpos($path, 'upload/') === 0) return 'php/' . $path;
    foreach (['uploads/', 'php/upload/', 'img/', 'imagess/'] as $prefix) {
        if (strpos($path, $prefix) === 0) {
            if ($type === 'boat' && preg_match('/\.(png|jpe?g)$/i', $path)) {
                $optimized = preg_replace('/\.(png|jpe?g)$/i', '.optimized.webp', $path);
                if ($optimized && is_file(dirname(__DIR__) . '/' . $optimized)) return $optimized;
            }
            return $path;
        }
    }
    $resolved = 'uploads/' . $path;
    if ($type === 'boat' && preg_match('/\.(png|jpe?g)$/i', $resolved)) {
        $optimized = preg_replace('/\.(png|jpe?g)$/i', '.optimized.webp', $resolved);
        if ($optimized && is_file(dirname(__DIR__) . '/' . $optimized)) return $optimized;
    }
    return $resolved;
}
