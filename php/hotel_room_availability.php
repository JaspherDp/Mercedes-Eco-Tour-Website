<?php
declare(strict_types=1);

header('Content-Type: application/json');

require_once __DIR__ . '/db_connection.php';
require_once __DIR__ . '/hotel_rooms_helper.php';

function hoNormalizeDateInput(?string $date): ?string
{
    $date = trim((string)$date);
    if ($date === '') {
        return null;
    }
    $dt = DateTime::createFromFormat('Y-m-d', $date);
    if (!$dt) {
        return null;
    }
    return $dt->format('Y-m-d');
}

$hotelId = (int)($_GET['hotel_id'] ?? 0);
$checkin = hoNormalizeDateInput($_GET['checkin'] ?? null);
$checkout = hoNormalizeDateInput($_GET['checkout'] ?? null);
$guests = max(1, (int)($_GET['guests'] ?? 1));

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
if (strtotime($checkout) <= strtotime($checkin)) {
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
