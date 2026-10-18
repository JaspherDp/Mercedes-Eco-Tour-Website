<?php
declare(strict_types=1);

/** Normalize titles and catalog locations without merging the two Apuao islands. */
function destinationStayLocationKey(string $value): string
{
    $value = strtr(mb_strtolower(trim($value)), ['ñ'=>'n', 'á'=>'a', 'é'=>'e', 'í'=>'i', 'ó'=>'o', 'ú'=>'u']);
    $value = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $value);
    $value = preg_replace('/\b(?:islands?|beaches|beach|hotels?|resorts?)\b/u', '', $value);
    $value = trim(preg_replace('/\s+/u', ' ', $value));
    return $value;
}

function destinationLoadStays(PDO $pdo): array
{
    $rows = $pdo->query("SELECT h.hotel_resort_id, h.name, h.island, h.type, h.image_path, h.amenities_json,
            COALESCE(rooms.min_price, h.price) AS nightly_price,
            COALESCE(reviews.rating, 0) AS rating, COALESCE(reviews.total_reviews, 0) AS total_reviews
        FROM hotel_resorts h
        LEFT JOIN (SELECT hotel_resort_id, MIN(price) AS min_price FROM hotel_rooms
            WHERE status='active' GROUP BY hotel_resort_id) rooms ON rooms.hotel_resort_id=h.hotel_resort_id
        LEFT JOIN (SELECT hotel_resort_id, ROUND(AVG(rating), 1) AS rating, COUNT(*) AS total_reviews
            FROM hotel_resort_reviews WHERE moderation_status='published' GROUP BY hotel_resort_id) reviews
            ON reviews.hotel_resort_id=h.hotel_resort_id
        WHERE h.status='active' ORDER BY h.name, h.hotel_resort_id")->fetchAll(PDO::FETCH_ASSOC);
    return array_map(static fn(array $row): array => [
        'id'=>(int)$row['hotel_resort_id'], 'name'=>(string)$row['name'],
        'island'=>(string)$row['island'], 'type'=>strtolower(trim((string)$row['type'])),
        'image'=>trim((string)$row['image_path']) ?: 'img/sampleimage.png',
        'price'=>(float)$row['nightly_price'], 'rating'=>(float)$row['rating'], 'review_count'=>(int)$row['total_reviews'],
        'amenities'=>array_values(array_filter((array)json_decode((string)($row['amenities_json'] ?? '[]'), true), 'is_string')),
    ], $rows);
}

function destinationStaysForDestination(array $stays, array $destination): array
{
    $keys = array_filter(array_map('destinationStayLocationKey', [(string)$destination['slug'], (string)$destination['title']]));
    $matches = array_filter($stays, static function(array $stay) use ($keys): bool {
        $location = destinationStayLocationKey((string)$stay['island']);
        $title = destinationStayLocationKey((string)$stay['name']);
        // The catalog may say only "Apuao". Use the resort title to identify its island.
        if (preg_match('/\bapuao (grande|pequena)\b/u', $title, $match)) {
            $location = 'apuao ' . $match[1];
        }
        if (in_array('apuao', $keys, true) && preg_match('/^apuao\b/u', $location)) return true;
        return in_array($location, $keys, true);
    });
    return array_values(array_map(static fn(array $stay): array => $stay + ['location_label'=>(string)$destination['title']], $matches));
}
