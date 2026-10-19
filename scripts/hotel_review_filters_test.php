<?php
declare(strict_types=1);
require_once __DIR__ . '/../php/hotel_review_filters.php';
$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('CREATE TABLE tourist (tourist_id INTEGER PRIMARY KEY, profile_picture TEXT, google_id TEXT)');
$pdo->exec('CREATE TABLE hotel_resort_reviews (review_id INTEGER PRIMARY KEY, hotel_resort_id INTEGER,
    tourist_id INTEGER, reviewer_name TEXT, rating REAL, review_message TEXT, owner_reply TEXT,
    created_at TEXT, moderation_status TEXT)');
$insert = $pdo->prepare('INSERT INTO hotel_resort_reviews VALUES (?, ?, 1, ?, ?, ?, ?, ?, ?)');
for ($id = 1; $id <= 12; $id++) {
    $insert->execute([$id, 1, 'Fixture guest', (($id - 1) % 5) + 1,
        $id === 1 ? 'Peaceful beach, 100% clean' : ($id === 2 ? 'Helpful staff' : 'Nice room'),
        '', sprintf('2026-10-%02d 10:00:00', $id), 'published']);
}
$insert->execute([13, 1, 'Hidden guest', 5, 'Hidden beach review', '', '2026-10-19 10:00:00', 'hidden']);
$insert->execute([14, 2, 'Other property', 5, 'Other beach review', '', '2026-10-19 10:00:00', 'published']);
$checks = 0;
function reviewFilterCheck(bool $condition, string $message): void {
    global $checks;
    if (!$condition) throw new RuntimeException($message);
    ++$checks;
}
$run = static fn(array $query) => hotelReviewSearch($pdo, 1, hotelReviewFilterOptions($query));
$all = $run([]);
reviewFilterCheck($all['total'] === 12 && count($all['reviews']) === 10 && $all['pages'] === 2, 'Scope or pagination failed.');
reviewFilterCheck((int)$all['reviews'][0]['review_id'] === 12, 'Newest sort failed.');
$second = $run(['review_page' => '2']);
reviewFilterCheck(array_map('intval', array_column($second['reviews'], 'review_id')) === [2, 1], 'Older reviews cannot be reached.');
reviewFilterCheck($run(['review_page' => '99999'])['page'] === 2, 'Page not clamped.');
reviewFilterCheck((int)$run(['review_sort' => 'oldest'])['reviews'][0]['review_id'] === 1, 'Oldest sort failed.');
$high = $run(['review_sort' => 'highest']);
reviewFilterCheck(array_slice(array_map('intval', array_column($high['reviews'], 'review_id')), 0, 2) === [10, 5], 'Highest sort or tie-break failed.');
reviewFilterCheck((float)$run(['review_sort' => 'lowest'])['reviews'][0]['rating'] === 1.0, 'Lowest sort failed.');
foreach ([1, 2, 3, 4, 5] as $rating) {
    $filtered = $run(['review_rating' => (string)$rating]);
    reviewFilterCheck($filtered['total'] > 0, 'Rating should have matches.');
    foreach ($filtered['reviews'] as $review) reviewFilterCheck((int)$review['rating'] === $rating, 'Wrong stars returned.');
}
$beach = $run(['review_search' => 'BEACH']);
reviewFilterCheck($beach['total'] === 1 && (int)$beach['reviews'][0]['review_id'] === 1, 'Search missed an older review or exposed a hidden or other-property review.');
reviewFilterCheck($run(['review_search' => '%'])['total'] === 1, 'Wildcard must be literal.');
reviewFilterCheck($run(['review_search' => "' OR 1=1 --"])['total'] === 0, 'Search escaped its binding.');
reviewFilterCheck($run(['review_search' => 'beach', 'review_rating' => '5'])['total'] === 0, 'Combined empty filter failed.');
reviewFilterCheck($run(['review_search' => 'staff', 'review_rating' => '2'])['total'] === 1, 'Combined matching filter failed.');
$invalid = hotelReviewFilterOptions(['review_sort' => 'DROP TABLE', 'review_rating' => ['5'], 'review_search' => [], 'review_page' => '-1']);
reviewFilterCheck($invalid === ['rating' => '', 'sort' => 'newest', 'search' => '', 'page' => 1], 'Invalid filters not normalized.');
reviewFilterCheck(mb_strlen(hotelReviewFilterOptions(['review_search' => str_repeat('x', 150)])['search']) === 120, 'Search length not limited.');
$url = hotelReviewFilterUrl(['id' => '1', 'checkin' => '2026-10-25', 'review_rating' => '5', 'review_page' => '2'], ['review_rating' => null, 'review_page' => null]);
reviewFilterCheck($url === 'hotel_details.php?id=1&checkin=2026-10-25#reviews', 'Clearing lost booking context.');

// Render only the real form, without the page's database or AI services.
$source = file_get_contents(__DIR__ . '/../public/hotel_details.php');
$start = strpos($source, '<form class="review-filters"');
$end = strpos($source, '<div id="hotelReviewResults"', $start);
reviewFilterCheck($start !== false && $end !== false, 'Review form missing.');
$hotelId = 1;
$_GET = ['id' => '1', 'checkin' => '2026-10-25', 'review_rating' => '4', 'review_search' => '<script>'];
$reviewFilters = hotelReviewFilterOptions($_GET);
$reviewResults = ['total' => 0];
ob_start(); eval('?>' . substr($source, $start, $end - $start)); $html = ob_get_clean();
reviewFilterCheck(str_contains($html, '&lt;script&gt;') && !str_contains($html, 'value="<script>"'), 'Search is not escaped.');
reviewFilterCheck(str_contains($html, 'name="checkin" value="2026-10-25"'), 'Booking dates lost.');
reviewFilterCheck(str_contains($html, 'value="4" selected'), 'Rating selection lost.');
foreach (['Guest Type', 'Room Type', 'All languages', 'Show reviews that mention'] as $placeholder) {
    reviewFilterCheck(!str_contains($html, $placeholder), 'Unsupported placeholder remains.');
}
reviewFilterCheck(!str_contains($html, 'Apply filters') && !str_contains($html, 'Clear filters'), 'Manual filter actions remain.');
foreach ([0, 12] as $totalReviews) {
    $hotel = ['total_reviews' => $totalReviews];
    $reviews = [];
    $reviewResults = ['total' => 0, 'pages' => 1];
    ob_start(); include __DIR__ . '/../php/hotel_review_results.php'; $empty = ob_get_clean();
    reviewFilterCheck(substr_count($empty, 'class="dummy-text"') === 1, 'Empty results should have one message.');
    reviewFilterCheck(!str_contains($empty, 'review-results-count'), 'Empty result count duplicates the list message.');
    reviewFilterCheck(str_contains($empty, $totalReviews ? 'No reviews match your filters.' : 'No reviews yet.'), 'Wrong empty state.');
}
// The shared AJAX template uses the page's existing profile/star renderers.
$renderStart = strpos($source, 'function reviewStarClass(');
$renderEnd = strpos($source, '$stmtRatings = ', $renderStart);
eval(substr($source, $renderStart, $renderEnd - $renderStart));
$hotel = ['total_reviews' => 12];
$reviewResults = $run([]);
$reviews = $reviewResults['reviews'];
$reviews[0]['review_message'] = '<script>fixture</script>';
ob_start(); include __DIR__ . '/../php/hotel_review_results.php'; $populated = ob_get_clean();
reviewFilterCheck(substr_count($populated, 'class="review-card individual"') === 10, 'Shared results lost reviews.');
reviewFilterCheck(str_contains($populated, 'review-results-count'), 'Positive count missing.');
reviewFilterCheck(str_contains($populated, '&lt;script&gt;fixture&lt;/script&gt;'), 'Fragment exposed review HTML.');
reviewFilterCheck(str_contains($populated, 'review_page=2') && str_contains($populated, 'rel="next"'), 'Fragment lost pagination.');
echo "PASS: {$checks} review filter checks; isolated SQLite, no application database or network calls.\n";
