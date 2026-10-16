<?php
declare(strict_types=1);

// Offline SQL regression: no application sessions, accounts or database writes.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$homepage = file_get_contents(__DIR__ . '/../public/homepage.php');
$start = strpos($homepage, 'function getLatestHomepageFields(');
$end = strpos($homepage, 'function resolveHomepageImage(', $start);
if ($start === false || $end === false) throw new RuntimeException('Homepage lookup not found.');
eval(substr($homepage, $start, $end - $start));

$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$columns = ['description1', 'description2', 'footer_text', 'video_path', 'slider_image1', 'slider_image2', 'slider_image3', 'slider_image4', 'small_image1', 'small_image2'];
$pdo->exec('CREATE TABLE featured_section (id INTEGER PRIMARY KEY, ' . implode(', ', array_map(static fn($column) => $column . ' TEXT', $columns)) . ')');
$verify = static function (string $label) use ($pdo, $columns): void {
    $expected = [];
    foreach ($columns as $column) {
        $stmt = $pdo->prepare("SELECT {$column} FROM featured_section WHERE {$column} IS NOT NULL AND {$column} != '' ORDER BY id DESC LIMIT 1");
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $expected[$column] = trim((string)($row[$column] ?? ''));
    }
    if (getLatestHomepageFields($pdo) !== $expected) throw new RuntimeException('Changed featured values: ' . $label);
};
$verify('empty table');
$insert = $pdo->prepare('INSERT INTO featured_section VALUES (' . implode(', ', array_fill(0, 11, '?')) . ')');
$insert->execute([1, ' old description ', 'other field', 'footer', 'video.mp4', 'one.png', 'two.png', 'three.png', 'four.png', 'small-one.png', 'small-two.png']);
$verify('single complete row');
$insert->execute([2, null, '', ' new footer ', null, '', 'new-two.png', null, '', null, '0']);
$insert->execute([3, 'new description', null, null, '', null, null, '   ', null, 'imagess/Apuao Pequeña.png', null]);
$verify('sparse updates, whitespace, Unicode and zero string');

// Exercise the actual dashboard chart block with filtered fixture bookings.
$pdo->exec('CREATE TABLE bookings (status TEXT, is_complete TEXT, created_at TEXT)');
$insertBooking = $pdo->prepare('INSERT INTO bookings VALUES (?, ?, ?)');
foreach ([['accepted', null], ['accepted', 'uncomplete'], ['accepted', 'completed'], ['pending', 'completed'], ['cancelled', 'cancelled'], ['declined', 'declined'], ['pending', null]] as $row) {
    $insertBooking->execute([$row[0], $row[1], '2026-10-08']);
    $insertBooking->execute([$row[0], $row[1], '2025-01-01']);
}
function fetchCount(PDO $pdo, string $sql, array $params = []): int {
    $stmt = $pdo->prepare($sql); $stmt->execute($params); return (int)$stmt->fetchColumn();
}
$dashboard = file_get_contents(__DIR__ . '/../admin/adhomepage.php');
$start = strpos($dashboard, "$" . "pie_labels =");
$end = strpos($dashboard, '// NOTIFICATIONS', $start);
if ($start === false || $end === false) throw new RuntimeException('Dashboard charts not found.');
foreach (['', ' AND b.created_at >= ?'] as $bookingCreatedWhere) {
    $bookingCreatedParams = $bookingCreatedWhere === '' ? [] : ['2026-01-01'];
    $total_bookings = fetchCount($pdo, "SELECT COUNT(*) FROM bookings b WHERE 1=1 {$bookingCreatedWhere}", $bookingCreatedParams);
    $accepted_bookings = fetchCount($pdo, "SELECT COUNT(*) FROM bookings b WHERE b.status='accepted' AND (b.is_complete IS NULL OR b.is_complete='uncomplete') {$bookingCreatedWhere}", $bookingCreatedParams);
    $completed_bookings = fetchCount($pdo, "SELECT COUNT(*) FROM bookings b WHERE b.is_complete='completed' {$bookingCreatedWhere}", $bookingCreatedParams);
    $factor = $bookingCreatedWhere === '' ? 2 : 1;
    eval(substr($dashboard, $start, $end - $start));
    if ($pie_data !== [2 * $factor, 2 * $factor, $factor, $factor] || $total_bookings !== 7 * $factor || $completed_bookings !== 2 * $factor || $accepted_unfinished_count !== 2 * $factor || $accepted_completed_count !== $factor) {
        throw new RuntimeException('Changed dashboard chart counts or filters.');
    }
}
echo "PASS: featured lookup equivalence and dashboard counts with/without date filters.\n";
