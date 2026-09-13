<?php
declare(strict_types=1);

header('Content-Type: application/json');

require_once __DIR__ . '/db_connection.php';
require_once __DIR__ . '/hotel_rooms_helper.php';
require_once __DIR__ . '/input_validation.php';

function hoNormalizeDateInput(mixed $date): ?string
{
    try { return ItourValidationDate($date, 'Date'); } catch (InvalidArgumentException) { return null; }
}

try {
    $hotelId = ItourValidationInt($_GET['hotel_id'] ?? null, 'Hotel ID', 1, PHP_INT_MAX);
    $guests = ItourValidationInt($_GET['guests'] ?? 1, 'Guests', 1, 100);
} catch (InvalidArgumentException $exception) {
    http_response_code(422); echo json_encode(['success' => false, 'message' => $exception->getMessage()]); exit;
}
$checkin = hoNormalizeDateInput($_GET['checkin'] ?? null);
$checkout = hoNormalizeDateInput($_GET['checkout'] ?? null);

if ($hotelId < 1) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Invalid hotel ID.']);
    exit;
}
if (!$checkin || !$checkout) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Check-in and check-out dates are required.']);
    exit;
}
if ($checkout <= $checkin) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Checkout date must be after check-in date.']);
    exit;
}

try {
    $rooms = HoGetAvailableHotelRooms($pdo, $hotelId, $checkin, $checkout, $guests);
    $formatted = array_map(static function (array $room): array {
        return [
            'id' => (int)$room['id'],
            'roomType' => (string)$room['room_name'],
            'description' => (string)$room['description'],
            'capacityAdults' => (int)$room['capacity_adults'],
            'capacityChildren' => (int)$room['capacity_children'],
            'capacityTotal' => HoRoomCapacityTotal($room),
            'price' => (float)$room['price'],
            'breakfastFor' => (int)$room['breakfast_for'],
            'inclusions' => (array)$room['inclusions'],
            'galleryImages' => (array)$room['gallery_images'],
            'mainImage' => (string)$room['main_image_path'],
            'meta' => (array)$room['meta'],
            'isAvailable' => (bool)($room['is_available'] ?? false),
            'isBookedForDates' => (bool)($room['is_booked_for_dates'] ?? false),
            'insufficientCapacity' => (bool)($room['insufficient_capacity'] ?? false),
        ];
    }, $rooms);

    echo json_encode([
        'success' => true,
        'rooms' => $formatted,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Unable to load room availability at the moment.',
    ]);
}
