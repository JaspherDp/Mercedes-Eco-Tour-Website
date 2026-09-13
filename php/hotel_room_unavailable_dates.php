<?php

declare(strict_types=1);

require __DIR__ . '/db_connection.php';
require_once __DIR__ . '/hotel_rooms_helper.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$hotelId = filter_var($_GET['hotel_id'] ?? null, FILTER_VALIDATE_INT);
$roomId = filter_var($_GET['room_id'] ?? null, FILTER_VALIDATE_INT);
$roomType = trim((string)($_GET['room_type'] ?? ''));
$from = trim((string)($_GET['from'] ?? date('Y-m-d')));
$to = trim((string)($_GET['to'] ?? date('Y-m-d', strtotime('+2 years'))));
$validDate = static function (string $value): bool {
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return $date && $date->format('Y-m-d') === $value;
};

if (!$hotelId || !$roomId || $roomType === '' || !$validDate($from) || !$validDate($to) || $to < $from) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Invalid room availability request.']);
    exit;
}

try {
    echo json_encode([
        'success' => true,
        'unavailable_dates' => HoGetRoomUnavailableDates(
            $pdo,
            (int)$hotelId,
            (int)$roomId,
            $roomType,
            $from,
            $to
        ),
    ], JSON_UNESCAPED_SLASHES);
} catch (Throwable $error) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Room availability could not be loaded.']);
}
