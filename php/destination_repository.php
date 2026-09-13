<?php

function destinationEnsureSchema(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS destination_settings (
        setting_key VARCHAR(80) PRIMARY KEY,
        setting_value VARCHAR(255) NOT NULL DEFAULT '',
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS destinations (
        destination_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        slug VARCHAR(160) NOT NULL UNIQUE,
        title VARCHAR(180) NOT NULL,
        tagline VARCHAR(255) NOT NULL DEFAULT '',
        description TEXT NOT NULL,
        destination_type VARCHAR(80) NOT NULL DEFAULT 'Island escape',
        location VARCHAR(180) NOT NULL DEFAULT 'Mercedes, Camarines Norte',
        latitude DECIMAL(10,7) NULL,
        longitude DECIMAL(10,7) NULL,
        activities TEXT NULL,
        card_image VARCHAR(500) NOT NULL,
        hero_image VARCHAR(500) NOT NULL,
        status ENUM('published','archived') NOT NULL DEFAULT 'published',
        is_featured TINYINT(1) NOT NULL DEFAULT 0,
        sort_order INT NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_destination_status_order (status, sort_order, destination_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $statusColumn = $pdo->query("SHOW COLUMNS FROM destinations LIKE 'status'")->fetch(PDO::FETCH_ASSOC);
    if ($statusColumn && !str_contains(strtolower((string)$statusColumn['Type']), 'archived')) {
        $pdo->exec("UPDATE destinations SET status='draft' WHERE status='draft'");
        $pdo->exec("ALTER TABLE destinations MODIFY status ENUM('published','archived','draft') NOT NULL DEFAULT 'published'");
        $pdo->exec("UPDATE destinations SET status='archived' WHERE status='draft'");
        $pdo->exec("ALTER TABLE destinations MODIFY status ENUM('published','archived') NOT NULL DEFAULT 'published'");
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS destination_gallery (
        gallery_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        destination_id INT UNSIGNED NOT NULL,
        image_path VARCHAR(500) NOT NULL,
        alt_text VARCHAR(255) NOT NULL DEFAULT '',
        sort_order INT NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        CONSTRAINT fk_destination_gallery_destination
          FOREIGN KEY (destination_id) REFERENCES destinations(destination_id) ON DELETE CASCADE,
        INDEX idx_destination_gallery_order (destination_id, sort_order, gallery_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function destinationSlug(string $value): string
{
    $value = strtolower(trim($value));
    $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';
    return trim($value, '-') ?: 'destination';
}

function destinationSeedDefaults(PDO $pdo): void
{
    $seeded = $pdo->query("SELECT COUNT(*) FROM destination_settings WHERE setting_key='defaults_seeded'")->fetchColumn();
    if ((int)$seeded > 0) return;
    if ((int)$pdo->query('SELECT COUNT(*) FROM destinations')->fetchColumn() > 0) {
        $pdo->exec("INSERT IGNORE INTO destination_settings (setting_key,setting_value) VALUES ('defaults_seeded','1')");
        return;
    }

    $defaults = [
        ['Apuao Pequena Island', 'Sun, Sand, Serenity', 'Apuao Pequena offers a peaceful white-sand escape shaded by agoho trees. Its clear water, sandbar, camping areas and wilder ocean-facing shore make it ideal for families, nature lovers and adventurous visitors.', 14.0835458, 123.1035423, ['Scuba Diving','Photography','Surfing','Camping','Swimming','Kayaking','Fishing'], 'imagess/Apuao Pequena.jpg', 'imagess/Apuao Pequena_header-img.png', 'Apuao Pequena_Gallery'],
        ['Apuao Grande Island', 'Feel the Breeze, Embrace the Sea', 'Apuao Grande is a relaxed island-hopping stop with pale shores and turquoise water. Its open beach and easy-going atmosphere make it a rewarding weekend escape from city life.', 14.0851907, 123.0908549, ['Scuba Diving','Photography','Surfing','Camping','Swimming','Kayaking','Hiking','Fishing'], 'imagess/Apuao Grande.jpg', 'imagess/Apuao Grande_header-img.png', 'Apuao Grande_Gallery'],
        ['Quinapaguian Island', 'Unwind, Dive, Explore', 'Quinapaguian Island combines a quiet shoreline with clear water suited to swimming, paddling and underwater exploration. It is a scenic stop on a Mercedes island-hopping route.', 14.0705785, 123.0745640, ['Scuba Diving','Photography','Surfing','Camping','Swimming','Kayaking','Fishing'], 'imagess/Quinapaguian.jpg', 'imagess/Quinapaguian_header-img.png', 'Quinapaguian_Gallery'],
        ['Canimog Island', 'Let the Tides Take You Away', 'Canimog is the largest of the seven islands of Mercedes. Known as Crocodile Island for its shape, it is home to wildlife, a bat sanctuary and a historic lighthouse established in 1927.', 14.1224869, 123.0648670, ['Scuba Diving','Photography','Surfing','Hiking','Camping','Swimming','Kayaking','Fishing'], 'imagess/Canimog.jpg', 'imagess/Canimog_header-img.png', 'Canimog_Gallery'],
        ['Caringo Island', 'Your Ultimate Seaside Escape', 'Caringo Island has a white sandy beach scattered with ornamental shells. Falaconete Point offers views of the sleeping-giant mountain formation and nearby San Miguel Bay.', 14.0395120, 123.1034480, ['Scuba Diving','Photography','Surfing','Camping','Swimming','Kayaking','Fishing'], 'imagess/Caringo.jpg', 'imagess/Caringo_header-img.png', 'Caringo_Gallery'],
        ['Malasugui Island', 'Experience the Magic of the Ocean', 'Malasugui may be the smallest of the seven islands, but its white sand, rock formations and turquoise water create an idyllic place for camping, swimming and quiet time in nature.', 14.0553813, 123.0883866, ['Scuba Diving','Photography','Surfing','Camping','Swimming','Kayaking','Fishing'], 'imagess/Malasugui.jpg', 'imagess/Malasugui_header-img.png', 'Malasugui_Gallery'],
        ['Canton Island', 'Raw Shores and Rock Formations', 'Canton, also known as Canron Island, has a rugged character distinct from the area’s white-sand beaches. Visitors can swim, explore rock formations, climb and visit mangrove forests.', 14.0823874, 123.1072221, ['Photography','Camping','Swimming','Kayaking','Fishing'], 'imagess/Canton.jpg', 'imagess/Canton_header-img.png', 'Canton'],
        ['St. Anthony of Padua Church', 'A Home of Faith by the Sea', 'St. Anthony of Padua Parish has long been a community anchor in Mercedes. Built through local effort and formally established in 1954, it remains a place of worship, heritage and shared memory.', 14.1090795, 123.0111237, ['Sightseeing','Photography','Cultural Tour'], 'imagess/Church.jpg', 'imagess/Church_header-img.png', 'Church'],
    ];

    $insert = $pdo->prepare('INSERT INTO destinations (slug,title,tagline,description,destination_type,latitude,longitude,activities,card_image,hero_image,status,is_featured,sort_order) VALUES (?,?,?,?,?,?,?,?,?,?,\'published\',1,?)');
    $galleryInsert = $pdo->prepare('INSERT INTO destination_gallery (destination_id,image_path,alt_text,sort_order) VALUES (?,?,?,?)');
    foreach ($defaults as $index => $item) {
        [$title,$tagline,$description,$lat,$lng,$activities,$card,$hero,$galleryStem] = $item;
        $type = str_contains($title, 'Church') ? 'Heritage landmark' : 'Island escape';
        $insert->execute([destinationSlug($title),$title,$tagline,$description,$type,$lat,$lng,json_encode($activities),$card,$hero,$index + 1]);
        $id = (int)$pdo->lastInsertId();
        for ($photo = 1; $photo <= 3; $photo++) {
            $candidate = __DIR__ . '/../imagess/' . $galleryStem . ' (' . $photo . ').JPG';
            if (!is_file($candidate)) $candidate = __DIR__ . '/../imagess/' . $galleryStem . '_' . $photo . '.jpg';
            if (!is_file($candidate)) continue;
            $relative = 'imagess/' . basename($candidate);
            $galleryInsert->execute([$id,$relative,$title . ' photo ' . $photo,$photo]);
        }
    }
    $pdo->exec("INSERT IGNORE INTO destination_settings (setting_key,setting_value) VALUES ('defaults_seeded','1')");
}

function destinationRows(PDO $pdo, bool $publishedOnly = false): array
{
    $sql = 'SELECT d.*, (SELECT COUNT(*) FROM destination_gallery g WHERE g.destination_id=d.destination_id) gallery_count FROM destinations d';
    if ($publishedOnly) $sql .= " WHERE d.status='published'";
    $sql .= ' ORDER BY d.sort_order ASC, d.destination_id ASC';
    return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
}

function destinationGallery(PDO $pdo, int $destinationId): array
{
    $stmt = $pdo->prepare('SELECT * FROM destination_gallery WHERE destination_id=? ORDER BY sort_order, gallery_id');
    $stmt->execute([$destinationId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
