<?php

function HoDefaultRoomTemplates(): array
{
    return [
        [
            'room_name' => 'Deluxe King Room',
            'description' => 'Elegant king room with cozy furnishings, ideal for couples and small families.',
            'price' => 3200,
            'capacity_adults' => 2,
            'capacity_children' => 1,
            'available_units' => 1,
            'breakfast_for' => 2,
            'meta' => ['bed' => '1 Queen bed', 'view' => 'Garden View', 'size' => '28 sqm'],
            'inclusions' => ['1 Queen bed', 'Air conditioning', 'Private CR', 'Smart TV', 'Free WiFi', 'Free breakfast for 2'],
        ],
        [
            'room_name' => 'Family Suite',
            'description' => 'Spacious suite perfect for families, with extra sleeping space and dining nook.',
            'price' => 5600,
            'capacity_adults' => 4,
            'capacity_children' => 2,
            'available_units' => 1,
            'breakfast_for' => 4,
            'meta' => ['bed' => '2 Queen beds', 'view' => 'Pool View', 'size' => '42 sqm'],
            'inclusions' => ['2 Queen beds', 'Air conditioning', 'Private CR', 'Mini fridge', 'Dining nook', 'Free breakfast for 4'],
        ],
        [
            'room_name' => 'Ocean View Twin',
            'description' => 'Twin room with relaxing coastal ambiance and balcony view.',
            'price' => 4100,
            'capacity_adults' => 2,
            'capacity_children' => 2,
            'available_units' => 1,
            'breakfast_for' => 2,
            'meta' => ['bed' => '2 Twin beds', 'view' => 'Ocean View', 'size' => '32 sqm'],
            'inclusions' => ['2 Twin beds', 'Air conditioning', 'Private CR', 'Balcony view', 'Coffee set', 'Free breakfast for 2'],
        ],
        [
            'room_name' => 'Barkada Loft',
            'description' => 'Group-friendly loft setup with lounge area, made for bigger travel squads.',
            'price' => 7200,
            'capacity_adults' => 6,
            'capacity_children' => 2,
            'available_units' => 1,
            'breakfast_for' => 6,
            'meta' => ['bed' => 'Loft bunk setup', 'view' => 'Island View', 'size' => '55 sqm'],
            'inclusions' => ['Loft bunk setup', 'Air conditioning', 'Private CR', 'Lounge area', 'Smart TV', 'Free breakfast for 6'],
        ],
        [
            'room_name' => 'Budget Standard',
            'description' => 'Practical and comfortable room for budget-conscious travelers.',
            'price' => 2500,
            'capacity_adults' => 2,
            'capacity_children' => 0,
            'available_units' => 1,
            'breakfast_for' => 2,
            'meta' => ['bed' => '1 Double bed', 'view' => 'Courtyard View', 'size' => '22 sqm'],
            'inclusions' => ['1 Double bed', 'Air conditioning', 'Private CR', 'Hot shower', 'Daily cleaning', 'Free breakfast for 2'],
        ],
        [
            'room_name' => 'Premium Villa',
            'description' => 'Luxury multi-room villa with private lounge and kitchenette for premium stays.',
            'price' => 10800,
            'capacity_adults' => 8,
            'capacity_children' => 4,
            'available_units' => 1,
            'breakfast_for' => 8,
            'meta' => ['bed' => '3 Bedrooms', 'view' => 'Beachfront', 'size' => '95 sqm'],
            'inclusions' => ['3 Bedrooms', 'Air conditioning', '2 Private CR', 'Private lounge', 'Kitchenette', 'Free breakfast for 8'],
        ],
    ];
}

function HoRoomsTableExists(PDO $pdo, string $tableName): bool
{
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
    ");
    $stmt->execute([$tableName]);
    return (int)$stmt->fetchColumn() > 0;
}

function HoRoomsHasColumn(PDO $pdo, string $tableName, string $columnName): bool
{
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
          AND COLUMN_NAME = ?
    ");
    $stmt->execute([$tableName, $columnName]);
    return (int)$stmt->fetchColumn() > 0;
}

function HoEnsureHotelRoomsTable(PDO $pdo): void
{
    static $ensured = false;
    if ($ensured) {
        return;
    }
    $ensured = true;

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS hotel_rooms (
          hotel_room_id INT AUTO_INCREMENT PRIMARY KEY,
          hotel_resort_id INT NOT NULL,
          room_name VARCHAR(150) NOT NULL,
          description TEXT NULL,
          price DECIMAL(10,2) NOT NULL DEFAULT 0,
          capacity_adults INT NOT NULL DEFAULT 1,
          capacity_children INT NOT NULL DEFAULT 0,
          available_units INT NOT NULL DEFAULT 1,
          breakfast_for INT NOT NULL DEFAULT 0,
          room_meta_json TEXT NULL,
          inclusions_json TEXT NULL,
          main_image_path VARCHAR(255) DEFAULT NULL,
          gallery_images_json TEXT NULL,
          status VARCHAR(20) NOT NULL DEFAULT 'active',
          created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          INDEX idx_hotel_rooms_hotel (hotel_resort_id),
          INDEX idx_hotel_rooms_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    // Best-effort guard against duplicate room names per property.
    try {
        $check = $pdo->query("SHOW INDEX FROM hotel_rooms WHERE Key_name = 'uq_hotel_room_name_per_hotel'");
        if (!$check || !$check->fetch(PDO::FETCH_ASSOC)) {
            $pdo->exec("ALTER TABLE hotel_rooms ADD UNIQUE KEY uq_hotel_room_name_per_hotel (hotel_resort_id, room_name)");
        }
    } catch (Throwable $e) {
    }
}

function HoEnsureHotelBookingsTable(PDO $pdo): void
{
    static $ensured = false;
    if ($ensured) {
        return;
    }
    $ensured = true;

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS hotel_room_bookings (
          hotel_booking_id INT AUTO_INCREMENT PRIMARY KEY,
          tourist_id INT NOT NULL,
          hotel_resort_id INT NOT NULL,
          hotel_room_id INT NULL,
          room_type VARCHAR(120) NOT NULL,
          checkin_date DATE NOT NULL,
          checkout_date DATE NOT NULL,
          nights INT NOT NULL,
          rooms_booked INT NOT NULL DEFAULT 1,
          adults INT NOT NULL DEFAULT 1,
          children INT NOT NULL DEFAULT 0,
          first_name VARCHAR(120) NOT NULL,
          last_name VARCHAR(120) NOT NULL,
          email VARCHAR(190) NOT NULL,
          phone_number VARCHAR(40) NOT NULL,
          special_request TEXT NULL,
          unit_price DECIMAL(10,2) NOT NULL,
          total_amount DECIMAL(12,2) NOT NULL,
          amount_paid DECIMAL(12,2) NOT NULL DEFAULT 0,
          remaining_balance DECIMAL(12,2) NOT NULL DEFAULT 0,
          payment_type VARCHAR(40) NOT NULL DEFAULT 'full',
          booking_status VARCHAR(40) NOT NULL DEFAULT 'pending',
          payment_status VARCHAR(40) NOT NULL DEFAULT 'unpaid',
          checked_in_at DATETIME NULL,
          checked_out_at DATETIME NULL,
          checkin_guest_name VARCHAR(190) NULL,
          checkin_representative_name VARCHAR(190) NULL,
          checkin_guest_count INT NULL,
          checkin_id_reference VARCHAR(255) NULL,
          checkin_payment_amount DECIMAL(12,2) NULL,
          checkin_payment_recorded_at DATETIME NULL,
          checkout_guest_name VARCHAR(190) NULL,
          checkout_representative_name VARCHAR(190) NULL,
          checkout_total_nights INT NULL,
          checkout_additional_charges DECIMAL(12,2) NULL,
          checkout_final_payment_amount DECIMAL(12,2) NULL,
          created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          INDEX idx_hrb_tourist (tourist_id),
          INDEX idx_hrb_hotel (hotel_resort_id),
          INDEX idx_hrb_hotel_room (hotel_room_id),
          INDEX idx_hrb_status (booking_status),
          INDEX idx_hrb_checkin (checkin_date),
          INDEX idx_hrb_checkout (checkout_date),
          INDEX idx_hrb_checkedin (checked_in_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $columnAddSql = [
        'hotel_room_id' => "ALTER TABLE hotel_room_bookings ADD COLUMN hotel_room_id INT NULL AFTER hotel_resort_id",
        'amount_paid' => "ALTER TABLE hotel_room_bookings ADD COLUMN amount_paid DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER total_amount",
        'remaining_balance' => "ALTER TABLE hotel_room_bookings ADD COLUMN remaining_balance DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER amount_paid",
        'checked_in_at' => "ALTER TABLE hotel_room_bookings ADD COLUMN checked_in_at DATETIME NULL AFTER payment_status",
        'checked_out_at' => "ALTER TABLE hotel_room_bookings ADD COLUMN checked_out_at DATETIME NULL AFTER checked_in_at",
        'checkin_guest_name' => "ALTER TABLE hotel_room_bookings ADD COLUMN checkin_guest_name VARCHAR(190) NULL AFTER checked_out_at",
        'checkin_representative_name' => "ALTER TABLE hotel_room_bookings ADD COLUMN checkin_representative_name VARCHAR(190) NULL AFTER checkin_guest_name",
        'checkin_guest_count' => "ALTER TABLE hotel_room_bookings ADD COLUMN checkin_guest_count INT NULL AFTER checkin_representative_name",
        'checkin_id_reference' => "ALTER TABLE hotel_room_bookings ADD COLUMN checkin_id_reference VARCHAR(255) NULL AFTER checkin_guest_count",
        'checkin_payment_amount' => "ALTER TABLE hotel_room_bookings ADD COLUMN checkin_payment_amount DECIMAL(12,2) NULL AFTER checkin_id_reference",
        'checkin_payment_recorded_at' => "ALTER TABLE hotel_room_bookings ADD COLUMN checkin_payment_recorded_at DATETIME NULL AFTER checkin_payment_amount",
        'checkout_guest_name' => "ALTER TABLE hotel_room_bookings ADD COLUMN checkout_guest_name VARCHAR(190) NULL AFTER checkin_payment_recorded_at",
        'checkout_representative_name' => "ALTER TABLE hotel_room_bookings ADD COLUMN checkout_representative_name VARCHAR(190) NULL AFTER checkout_guest_name",
        'checkout_total_nights' => "ALTER TABLE hotel_room_bookings ADD COLUMN checkout_total_nights INT NULL AFTER checkout_representative_name",
        'checkout_additional_charges' => "ALTER TABLE hotel_room_bookings ADD COLUMN checkout_additional_charges DECIMAL(12,2) NULL AFTER checkout_total_nights",
        'checkout_final_payment_amount' => "ALTER TABLE hotel_room_bookings ADD COLUMN checkout_final_payment_amount DECIMAL(12,2) NULL AFTER checkout_additional_charges",
    ];
    foreach ($columnAddSql as $column => $sql) {
        try {
            if (!HoRoomsHasColumn($pdo, 'hotel_room_bookings', $column)) {
                $pdo->exec($sql);
            }
        } catch (Throwable $e) {
        }
    }

    try {
        $pdo->exec("UPDATE hotel_room_bookings SET rooms_booked = 1 WHERE rooms_booked < 1");
        $pdo->exec("UPDATE hotel_room_bookings SET payment_type = LOWER(payment_type), payment_status = LOWER(payment_status), booking_status = LOWER(booking_status)");
        $pdo->exec("
            UPDATE hotel_room_bookings
            SET amount_paid = CASE
                WHEN payment_status = 'paid' THEN total_amount
                WHEN payment_status = 'partial' THEN ROUND(total_amount * 0.20, 2)
                ELSE 0
            END
            WHERE amount_paid <= 0
        ");
        $pdo->exec("
            UPDATE hotel_room_bookings
            SET remaining_balance = GREATEST(total_amount - amount_paid, 0)
            WHERE remaining_balance < 0 OR remaining_balance = 0
        ");
    } catch (Throwable $e) {
    }

    if (!HoRoomsTableExists($pdo, 'hotel_rooms')) {
        return;
    }

    try {
        $pdo->exec("
            UPDATE hotel_room_bookings b
            INNER JOIN hotel_rooms r
                ON r.hotel_resort_id = b.hotel_resort_id
               AND r.room_name = b.room_type
            SET b.hotel_room_id = r.hotel_room_id
            WHERE b.hotel_room_id IS NULL
        ");
    } catch (Throwable $e) {
    }
}

function HoHydrateHotelRoom(array $row): array
{
    $meta = json_decode((string)($row['room_meta_json'] ?? '[]'), true);
    $inclusions = json_decode((string)($row['inclusions_json'] ?? '[]'), true);
    $gallery = json_decode((string)($row['gallery_images_json'] ?? '[]'), true);

    $mainImage = trim((string)($row['main_image_path'] ?? ''));
    if ($mainImage === '') {
        $mainImage = 'img/sampleimage.png';
    }

    $galleryImages = [];
    if (is_array($gallery)) {
        foreach ($gallery as $img) {
            $img = trim((string)$img);
            if ($img !== '') {
                $galleryImages[] = $img;
            }
        }
    }
    $galleryImages = array_values(array_unique($galleryImages));

    return [
        'id' => (int)$row['hotel_room_id'],
        'hotel_resort_id' => (int)$row['hotel_resort_id'],
        'room_name' => (string)$row['room_name'],
        'description' => (string)($row['description'] ?? ''),
        'price' => (float)$row['price'],
        'capacity_adults' => (int)$row['capacity_adults'],
        'capacity_children' => (int)$row['capacity_children'],
        'available_units' => 1,
        'breakfast_for' => (int)$row['breakfast_for'],
        'meta' => is_array($meta) ? $meta : [],
        'inclusions' => is_array($inclusions) ? $inclusions : [],
        'main_image_path' => $mainImage,
        'gallery_images' => $galleryImages,
        'status' => (string)$row['status'],
    ];
}

function HoEnsureHotelDefaultRooms(PDO $pdo, int $hotelResortId): void
{
    if ($hotelResortId < 1) {
        return;
    }

    HoEnsureHotelRoomsTable($pdo);
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM hotel_rooms WHERE hotel_resort_id = ?");
    $countStmt->execute([$hotelResortId]);
    if ((int)$countStmt->fetchColumn() > 0) {
        return;
    }

    $insert = $pdo->prepare("
        INSERT INTO hotel_rooms
        (hotel_resort_id, room_name, description, price, capacity_adults, capacity_children, available_units, breakfast_for, room_meta_json, inclusions_json, main_image_path, gallery_images_json, status)
        VALUES
        (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active')
    ");

    foreach (HoDefaultRoomTemplates() as $tpl) {
        $insert->execute([
            $hotelResortId,
            $tpl['room_name'],
            $tpl['description'],
            $tpl['price'],
            $tpl['capacity_adults'],
            $tpl['capacity_children'],
            $tpl['available_units'],
            $tpl['breakfast_for'],
            json_encode($tpl['meta'], JSON_UNESCAPED_UNICODE),
            json_encode($tpl['inclusions'], JSON_UNESCAPED_UNICODE),
            'img/sampleimage.png',
            json_encode(['img/sampleimage.png'], JSON_UNESCAPED_UNICODE),
        ]);
    }
}

function HoGetHotelRooms(PDO $pdo, int $hotelResortId, bool $onlyActive = true): array
{
    HoEnsureHotelDefaultRooms($pdo, $hotelResortId);
    $sql = "
        SELECT *
        FROM hotel_rooms
        WHERE hotel_resort_id = :hotel_resort_id
    ";
    if ($onlyActive) {
        $sql .= " AND status = 'active' ";
    }
    $sql .= " ORDER BY room_name ASC, updated_at DESC, hotel_room_id DESC ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([':hotel_resort_id' => $hotelResortId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $unique = [];
    $rooms = [];
    foreach ($rows as $row) {
        $key = mb_strtolower(trim((string)($row['room_name'] ?? '')));
        if ($key === '') {
            $key = 'room-' . (string)($row['hotel_room_id'] ?? '');
        }
        if (isset($unique[$key])) {
            continue;
        }
        $unique[$key] = true;
        $rooms[] = HoHydrateHotelRoom($row);
    }

    usort($rooms, static function (array $a, array $b): int {
        return strcmp((string)$a['room_name'], (string)$b['room_name']);
    });
    return $rooms;
}

function HoGetHotelRoomById(PDO $pdo, int $hotelResortId, int $hotelRoomId, bool $onlyActive = true): ?array
{
    HoEnsureHotelDefaultRooms($pdo, $hotelResortId);
    $sql = "
        SELECT *
        FROM hotel_rooms
        WHERE hotel_room_id = :hotel_room_id AND hotel_resort_id = :hotel_resort_id
    ";
    if ($onlyActive) {
        $sql .= " AND status = 'active' ";
    }
    $sql .= " ORDER BY updated_at DESC, hotel_room_id DESC LIMIT 1 ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':hotel_room_id' => $hotelRoomId,
        ':hotel_resort_id' => $hotelResortId,
    ]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ? HoHydrateHotelRoom($row) : null;
}

function HoGetHotelRoomByName(PDO $pdo, int $hotelResortId, string $roomName, bool $onlyActive = true): ?array
{
    HoEnsureHotelDefaultRooms($pdo, $hotelResortId);
    $sql = "
        SELECT *
        FROM hotel_rooms
        WHERE room_name = :room_name AND hotel_resort_id = :hotel_resort_id
    ";
    if ($onlyActive) {
        $sql .= " AND status = 'active' ";
    }
    $sql .= " ORDER BY updated_at DESC, hotel_room_id DESC LIMIT 1 ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':room_name' => $roomName,
        ':hotel_resort_id' => $hotelResortId,
    ]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ? HoHydrateHotelRoom($row) : null;
}

function HoRoomCapacityTotal(array $room): int
{
    return max(1, (int)($room['capacity_adults'] ?? 0) + (int)($room['capacity_children'] ?? 0));
}

function HoGetUnavailableRoomLookup(PDO $pdo, int $hotelResortId, string $checkin, string $checkout): array
{
    HoEnsureHotelBookingsTable($pdo);
    $stmt = $pdo->prepare("
        SELECT DISTINCT
          b.hotel_room_id,
          b.room_type
        FROM hotel_room_bookings b
        WHERE b.hotel_resort_id = :hotel_resort_id
          AND b.booking_status IN ('pending', 'confirmed')
          AND (
            (:checkin < b.checkout_date AND :checkout > b.checkin_date)
            OR (b.checked_in_at IS NOT NULL AND b.checked_out_at IS NULL)
          )
    ");
    $stmt->execute([
        ':hotel_resort_id' => $hotelResortId,
        ':checkin' => $checkin,
        ':checkout' => $checkout,
    ]);

    $ids = [];
    $names = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $roomId = (int)($row['hotel_room_id'] ?? 0);
        if ($roomId > 0) {
            $ids[$roomId] = true;
        }
        $roomType = mb_strtolower(trim((string)($row['room_type'] ?? '')));
        if ($roomType !== '') {
            $names[$roomType] = true;
        }
    }

    return ['ids' => $ids, 'names' => $names];
}

function HoGetAvailableHotelRooms(PDO $pdo, int $hotelResortId, string $checkin, string $checkout, int $guests): array
{
    $guests = max(1, $guests);
    $rooms = HoGetHotelRooms($pdo, $hotelResortId, true);
    $unavailable = HoGetUnavailableRoomLookup($pdo, $hotelResortId, $checkin, $checkout);

    $result = [];
    foreach ($rooms as $room) {
        $roomId = (int)$room['id'];
        $roomNameKey = mb_strtolower(trim((string)$room['room_name']));
        if (isset($unavailable['ids'][$roomId]) || isset($unavailable['names'][$roomNameKey])) {
            continue;
        }
        if (HoRoomCapacityTotal($room) < $guests) {
            continue;
        }
        $result[] = $room;
    }

    usort($result, static function (array $a, array $b): int {
        $capA = HoRoomCapacityTotal($a);
        $capB = HoRoomCapacityTotal($b);
        if ($capA !== $capB) {
            return $capA <=> $capB;
        }
        $priceA = (float)($a['price'] ?? 0);
        $priceB = (float)($b['price'] ?? 0);
        if ($priceA !== $priceB) {
            return $priceA <=> $priceB;
        }
        return strcmp((string)$a['room_name'], (string)$b['room_name']);
    });

    return $result;
}

function HoIsHotelRoomAvailable(PDO $pdo, int $hotelResortId, int $hotelRoomId, string $roomName, string $checkin, string $checkout): bool
{
    HoEnsureHotelBookingsTable($pdo);
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM hotel_room_bookings
        WHERE hotel_resort_id = :hotel_resort_id
          AND booking_status IN ('pending', 'confirmed')
          AND (
            hotel_room_id = :hotel_room_id
            OR (hotel_room_id IS NULL AND room_type = :room_type)
          )
          AND (
            (:checkin < checkout_date AND :checkout > checkin_date)
            OR (checked_in_at IS NOT NULL AND checked_out_at IS NULL)
          )
    ");
    $stmt->execute([
        ':hotel_resort_id' => $hotelResortId,
        ':hotel_room_id' => $hotelRoomId,
        ':room_type' => $roomName,
        ':checkin' => $checkin,
        ':checkout' => $checkout,
    ]);
    return (int)$stmt->fetchColumn() === 0;
}

function HoGetHotelRoomStatusSnapshot(PDO $pdo, int $hotelResortId): array
{
    HoEnsureHotelBookingsTable($pdo);

    $stmt = $pdo->prepare("
        SELECT
          hotel_booking_id,
          hotel_room_id,
          room_type,
          booking_status,
          checkin_date,
          checkout_date,
          checked_in_at,
          checked_out_at,
          checkin_guest_name,
          checkin_representative_name,
          checkin_guest_count,
          checkin_id_reference,
          checkout_guest_name,
          checkout_representative_name,
          checkout_total_nights,
          checkout_additional_charges,
          checkout_final_payment_amount,
          adults,
          children,
          first_name,
          last_name,
          amount_paid,
          remaining_balance
        FROM hotel_room_bookings
        WHERE hotel_resort_id = :hotel_resort_id
          AND booking_status IN ('pending', 'confirmed')
        ORDER BY checkin_date ASC, created_at ASC, hotel_booking_id ASC
    ");
    $stmt->execute([':hotel_resort_id' => $hotelResortId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $today = date('Y-m-d');
    $byRoomId = [];
    $byRoomName = [];

    foreach ($rows as $row) {
        $bookingStatus = strtolower((string)($row['booking_status'] ?? 'pending'));
        $hasCheckin = !empty($row['checked_in_at']);
        $hasCheckout = !empty($row['checked_out_at']);
        $checkinDate = (string)($row['checkin_date'] ?? '');
        $checkoutDate = (string)($row['checkout_date'] ?? '');

        $status = null;
        $priority = 99;
        $canCheckin = false;

        if ($bookingStatus === 'confirmed' && $hasCheckin && !$hasCheckout) {
            $status = 'occupied';
            $priority = 1;
        } elseif (in_array($bookingStatus, ['pending', 'confirmed'], true) && !$hasCheckin && $checkoutDate > $today) {
            $status = 'booked';
            $priority = $bookingStatus === 'confirmed' ? 2 : 3;
            $canCheckin = true;
        }

        if ($status === null) {
            continue;
        }

        $baseGuestName = trim((string)($row['first_name'] ?? '') . ' ' . (string)($row['last_name'] ?? ''));
        $checkinGuestName = trim((string)($row['checkin_guest_name'] ?? ''));
        $booking = [
            'hotel_booking_id' => (int)$row['hotel_booking_id'],
            'booking_status' => $bookingStatus,
            'checkin_date' => $checkinDate,
            'checkout_date' => $checkoutDate,
            'checked_in_at' => (string)($row['checked_in_at'] ?? ''),
            'checked_out_at' => (string)($row['checked_out_at'] ?? ''),
            'guest_name' => $checkinGuestName !== '' ? $checkinGuestName : $baseGuestName,
            'guest_count' => max(1, (int)($row['adults'] ?? 0) + (int)($row['children'] ?? 0)),
            'checkin_guest_name' => $checkinGuestName,
            'checkin_representative_name' => trim((string)($row['checkin_representative_name'] ?? '')),
            'checkin_guest_count' => max(0, (int)($row['checkin_guest_count'] ?? 0)),
            'checkin_id_reference' => trim((string)($row['checkin_id_reference'] ?? '')),
            'checkout_guest_name' => trim((string)($row['checkout_guest_name'] ?? '')),
            'checkout_representative_name' => trim((string)($row['checkout_representative_name'] ?? '')),
            'checkout_total_nights' => max(0, (int)($row['checkout_total_nights'] ?? 0)),
            'checkout_additional_charges' => (float)($row['checkout_additional_charges'] ?? 0),
            'checkout_final_payment_amount' => (float)($row['checkout_final_payment_amount'] ?? 0),
            'remaining_balance' => (float)($row['remaining_balance'] ?? 0),
            'amount_paid' => (float)($row['amount_paid'] ?? 0),
            'can_checkin' => $canCheckin,
            '_priority' => $priority,
            '_status' => $status,
        ];

        $roomId = (int)($row['hotel_room_id'] ?? 0);
        if ($roomId > 0) {
            $existing = $byRoomId[$roomId] ?? null;
            if (!$existing || (int)$booking['_priority'] < (int)$existing['_priority']) {
                $byRoomId[$roomId] = $booking;
            }
        }

        $roomName = mb_strtolower(trim((string)($row['room_type'] ?? '')));
        if ($roomName !== '') {
            $existing = $byRoomName[$roomName] ?? null;
            if (!$existing || (int)$booking['_priority'] < (int)$existing['_priority']) {
                $byRoomName[$roomName] = $booking;
            }
        }
    }

    return [
        'by_room_id' => $byRoomId,
        'by_room_name' => $byRoomName,
    ];
}

function HoResolveRoomStatus(array $snapshot, int $roomId, string $roomName): array
{
    $booking = null;
    if ($roomId > 0 && isset($snapshot['by_room_id'][$roomId])) {
        $booking = $snapshot['by_room_id'][$roomId];
    }

    if (!$booking) {
        $nameKey = mb_strtolower(trim($roomName));
        if ($nameKey !== '' && isset($snapshot['by_room_name'][$nameKey])) {
            $booking = $snapshot['by_room_name'][$nameKey];
        }
    }

    if (!$booking) {
        return [
            'status' => 'available',
            'label' => 'Available',
            'badge_class' => 'available',
            'can_checkin' => false,
            'booking' => null,
        ];
    }

    $status = (string)($booking['_status'] ?? 'available');
    if ($status === 'occupied') {
        return [
            'status' => 'occupied',
            'label' => 'Occupied',
            'badge_class' => 'occupied',
            'can_checkin' => false,
            'booking' => $booking,
        ];
    }

    return [
        'status' => 'booked',
        'label' => 'Booked',
        'badge_class' => 'booked',
        'can_checkin' => (bool)($booking['can_checkin'] ?? false),
        'booking' => $booking,
    ];
}
