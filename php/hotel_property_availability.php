<?php
declare(strict_types=1);
require_once __DIR__ . '/db_connection.php';
require_once __DIR__ . '/hotel_rooms_helper.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
try {
    $party = HoChildParty($_GET['adults'] ?? 1, $_GET['children'] ?? 0, $_GET['child_ages'] ?? []);
    $checkin = ItourValidationDate($_GET['checkin'] ?? '', 'Check-in date');
    $checkout = ItourValidationDate($_GET['checkout'] ?? '', 'Check-out date');
    if ($checkout <= $checkin || $checkin < (new DateTimeImmutable('today', new DateTimeZone('Asia/Manila')))->format('Y-m-d')) throw new InvalidArgumentException('Select valid future stay dates.');
    $query = $pdo->query("SELECT hotel_resort_id FROM hotel_resorts WHERE status='active' ORDER BY hotel_resort_id");
    $ids = [];
    foreach ($query->fetchAll(PDO::FETCH_COLUMN) as $id) {
        $rooms = HoGetAvailableHotelRooms($pdo, (int)$id, $checkin, $checkout, $party['adults'] + $party['children'], $party);
        if (array_filter($rooms, static fn(array $room): bool => $room['is_available'])) $ids[] = (int)$id;
    }
    echo json_encode(['success' => true, 'hotel_ids' => $ids], JSON_THROW_ON_ERROR);
} catch (InvalidArgumentException|DomainException $exception) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => $exception->getMessage()]);
} catch (Throwable $exception) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Hotel availability could not be loaded. Please try again.']);
}
