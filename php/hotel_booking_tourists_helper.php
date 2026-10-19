<?php
require_once __DIR__ . '/hotel_booking_status.php';
/** Hotel guest lists use the shared form and writer, with a separate hotel foreign key. */
function hotelBookingTouristsEnsureSchema(PDO $pdo): void
{
    try { $pdo->query('SELECT id FROM hotel_booking_tourists LIMIT 0'); }
    catch (PDOException $error) {
        if (($error->errorInfo[1] ?? 0) !== 1146 && !str_contains($error->getMessage(), 'no such table')) throw $error;
        $pdo->exec((string)file_get_contents(__DIR__ . '/../hotel_booking_tourists_migration.sql'));
    }
}

function hotelBookingTouristNormalize(array $booking): array
{
    $status = HotelBookingStatus($booking);
    return array_merge($booking, [
        'booking_id' => (int)$booking['hotel_booking_id'],
        'booking_status' => $status,
        'status' => $status === 'confirmed' ? 'accepted' : $status,
        'is_complete' => !empty($booking['checked_out_at']) || $status === 'completed' ? 'completed' : 'uncomplete',
        'booking_date' => $booking['checkin_date'],
        'booking_type' => 'hotel',
        'allowed_pax' => (int)$booking['adults'] + (int)$booking['children'],
        'pax' => (int)$booking['adults'] + (int)$booking['children'],
        'num_adults' => (int)$booking['adults'], 'num_children' => (int)$booking['children'],
    ]);
}

function hotelBookingTouristRecord(PDO $pdo, int $bookingId, int $touristId, bool $lock = false): ?array
{
    $suffix = $lock && $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
    $query = $pdo->prepare('SELECT * FROM hotel_room_bookings WHERE hotel_booking_id = ? AND tourist_id = ? LIMIT 1' . $suffix);
    $query->execute([$bookingId, $touristId]);
    $booking = $query->fetch(PDO::FETCH_ASSOC);
    return $booking ? hotelBookingTouristNormalize($booking) : null;
}

function hotelBookingHasTourists(PDO $pdo, int $bookingId): bool
{
    $query = $pdo->prepare('SELECT COUNT(*) FROM hotel_booking_tourists WHERE booking_id = ?');
    $query->execute([$bookingId]);
    return (int)$query->fetchColumn() > 0;
}

/** Hotel staff access is scoped to the property resolved from their authenticated account. */
function hotelBookingTouristPropertyRecord(PDO $pdo, int $bookingId, int $propertyId): ?array
{
    if ($propertyId < 1) return null;
    $query = $pdo->prepare('SELECT * FROM hotel_room_bookings WHERE hotel_booking_id = ? AND hotel_resort_id = ? LIMIT 1');
    $query->execute([$bookingId, $propertyId]);
    $booking = $query->fetch(PDO::FETCH_ASSOC);
    return $booking ? hotelBookingTouristNormalize($booking) : null;
}
