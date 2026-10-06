<?php
declare(strict_types=1);
require_once __DIR__ . '/session_security.php';
AppSessionStart();
require_once __DIR__ . '/db_connection.php';
require_once __DIR__ . '/admin_auth_helper.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if (!AdminValidateSession($pdo)) {
    http_response_code(401);
    echo json_encode(['error' => 'Please sign in to your administrator account.']);
    exit;
}
$touristId = filter_var($_GET['tourist_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$offset = filter_var($_GET['offset'] ?? 0, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
if ($touristId === false || $offset === false) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid booking history request.']);
    exit;
}
try {
    $tourist = $pdo->prepare('SELECT full_name FROM tourist WHERE tourist_id = ?');
    $tourist->execute([$touristId]);
    $name = $tourist->fetchColumn();
    if ($name === false) {
        http_response_code(404);
        echo json_encode(['error' => 'Tourist not found.']);
        exit;
    }
    $count = $pdo->prepare('SELECT COUNT(*) FROM bookings WHERE tourist_id = ?');
    $count->execute([$touristId]);
    $total = (int)$count->fetchColumn();
    $history = $pdo->prepare("SELECT b.booking_id, b.booking_reference, b.booking_type,
        b.package_name, b.location, b.booking_date, b.created_at, b.status, b.is_complete,
        bt.name AS boat_name, tg.fullname AS guide_name
        FROM bookings b
        LEFT JOIN boats bt ON bt.boat_id = b.boat_id
        LEFT JOIN tour_guides tg ON tg.guide_id = b.guide_id
        WHERE b.tourist_id = :tourist_id
        ORDER BY b.created_at DESC, b.booking_id DESC LIMIT 25 OFFSET :offset");
    $history->bindValue(':tourist_id', $touristId, PDO::PARAM_INT);
    $history->bindValue(':offset', $offset, PDO::PARAM_INT);
    $history->execute();
    $bookings = $history->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['name' => $name, 'total' => $total, 'bookings' => $bookings,
        'next_offset' => $offset + count($bookings), 'has_more' => $offset + count($bookings) < $total]);
} catch (Throwable $error) {
    error_log('Tourist booking history: ' . $error->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Unable to load booking history. Please try again.']);
}
