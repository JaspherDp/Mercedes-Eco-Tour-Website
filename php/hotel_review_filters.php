<?php
declare(strict_types=1);

function hotelReviewFilterOptions(array $query): array
{
    $text = static fn(string $key): string => is_string($query[$key] ?? null) ? trim($query[$key]) : '';
    $rating = $text('review_rating');
    $sort = $text('review_sort');
    $page = $text('review_page');
    return [
        'rating' => in_array($rating, ['1', '2', '3', '4', '5'], true) ? $rating : '',
        'sort' => in_array($sort, ['newest', 'oldest', 'highest', 'lowest'], true) ? $sort : 'newest',
        'search' => mb_substr($text('review_search'), 0, 120),
        'page' => ctype_digit($page) ? max(1, min(1000000, (int)$page)) : 1,
    ];
}

/** Published reviews for this property only; no account or schema changes. */
function hotelReviewSearch(PDO $pdo, int $hotelId, array $filters): array
{
    $where = ["r.hotel_resort_id = ?", "r.moderation_status = 'published'"];
    $params = [$hotelId];
    if ($filters['rating'] !== '') {
        $where[] = 'r.rating >= ? AND r.rating < ?';
        $params[] = (int)$filters['rating'];
        $params[] = (int)$filters['rating'] + 1;
    }
    if ($filters['search'] !== '') {
        $where[] = "LOWER(r.review_message) LIKE LOWER(?) ESCAPE '!'";
        $params[] = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $filters['search']) . '%';
    }
    $condition = implode(' AND ', $where);
    $count = $pdo->prepare('SELECT COUNT(*) FROM hotel_resort_reviews r WHERE ' . $condition);
    $count->execute($params);
    $total = (int)$count->fetchColumn();
    $perPage = 10;
    $pages = max(1, (int)ceil($total / $perPage));
    $page = min($filters['page'], $pages);
    $offset = ($page - 1) * $perPage;
    $order = [
        'newest' => 'r.created_at DESC, r.review_id DESC',
        'oldest' => 'r.created_at ASC, r.review_id ASC',
        'highest' => 'r.rating DESC, r.created_at DESC, r.review_id DESC',
        'lowest' => 'r.rating ASC, r.created_at DESC, r.review_id DESC',
    ][$filters['sort']];
    $statement = $pdo->prepare("SELECT r.review_id, r.reviewer_name, r.rating, r.review_message,
        r.owner_reply, r.created_at, t.profile_picture, t.google_id
        FROM hotel_resort_reviews r LEFT JOIN tourist t ON t.tourist_id = r.tourist_id
        WHERE {$condition} ORDER BY {$order} LIMIT {$perPage} OFFSET {$offset}");
    $statement->execute($params);
    return ['reviews' => $statement->fetchAll(PDO::FETCH_ASSOC), 'total' => $total,
        'page' => $page, 'pages' => $pages, 'offset' => $offset];
}

function hotelReviewFilterUrl(array $query, array $changes): string
{
    $params = array_filter($query, static fn($value) => is_scalar($value));
    foreach ($changes as $key => $value) {
        if ($value === null) unset($params[$key]);
        else $params[$key] = $value;
    }
    return 'hotel_details.php?' . http_build_query($params) . '#reviews';
}
