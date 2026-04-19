<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/php/db_connection.php';
require_once __DIR__ . '/php/hotel_rooms_helper.php';
require_once __DIR__ . '/php/hotel_content_helper.php';

function HoTableExists(PDO $pdo, string $tableName): bool
{
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
    ");
    $stmt->execute([$tableName]);
    return (int)$stmt->fetchColumn() > 0;
}

if (function_exists('HoEnsureHotelBookingsTable')) {
    HoEnsureHotelBookingsTable($pdo);
}

function HoEnsureHotelAdminTables(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS hotel_admin_accounts (
          hotel_admin_id INT AUTO_INCREMENT PRIMARY KEY,
          hotel_resort_id INT NOT NULL,
          username VARCHAR(190) NOT NULL,
          password VARCHAR(255) NOT NULL,
          full_name VARCHAR(190) DEFAULT NULL,
          status VARCHAR(20) NOT NULL DEFAULT 'active',
          created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          UNIQUE KEY uq_hotel_admin_hotel (hotel_resort_id),
          UNIQUE KEY uq_hotel_admin_username (username),
          INDEX idx_hotel_admin_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    if (!HoTableExists($pdo, 'hotel_resorts')) {
        return;
    }

    $hotels = $pdo->query("
        SELECT hotel_resort_id, name
        FROM hotel_resorts
        WHERE status = 'active'
        ORDER BY hotel_resort_id ASC
    ")->fetchAll(PDO::FETCH_ASSOC);

    if (!$hotels) {
        return;
    }

    $existsStmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM hotel_admin_accounts
        WHERE hotel_resort_id = ?
    ");
    $insertStmt = $pdo->prepare("
        INSERT INTO hotel_admin_accounts (hotel_resort_id, username, password, full_name, status)
        VALUES (?, ?, ?, ?, 'active')
    ");

    foreach ($hotels as $hotel) {
        $hotelId = (int)$hotel['hotel_resort_id'];
        $hotelName = trim((string)$hotel['name']);
        if ($hotelId < 1 || $hotelName === '') {
            continue;
        }

        $existsStmt->execute([$hotelId]);
        $alreadyAssigned = (int)$existsStmt->fetchColumn() > 0;
        if ($alreadyAssigned) {
            continue;
        }

        $insertStmt->execute([
            $hotelId,
            $hotelName,
            password_hash('123456', PASSWORD_DEFAULT),
            $hotelName . ' Admin',
        ]);
    }
}

HoEnsureHotelAdminTables($pdo);
HoEnsureHotelResortContentColumns($pdo);

function HoSetHotelAdminSession(array $admin): void
{
    $_SESSION['hotel_admin_logged_in'] = true;
    $_SESSION['hotel_admin_id'] = (int)$admin['hotel_admin_id'];
    $_SESSION['hotel_admin_username'] = (string)$admin['username'];
    $_SESSION['hotel_admin_hotel_resort_id'] = (int)$admin['hotel_resort_id'];
    $_SESSION['hotel_admin_property_name'] = (string)($admin['property_name'] ?? '');
    $_SESSION['hotel_admin_name'] = (string)($admin['full_name'] ?? $admin['username']);
}

function HoClearHotelAdminSession(): void
{
    unset(
        $_SESSION['hotel_admin_logged_in'],
        $_SESSION['hotel_admin_id'],
        $_SESSION['hotel_admin_username'],
        $_SESSION['hotel_admin_hotel_resort_id'],
        $_SESSION['hotel_admin_property_name'],
        $_SESSION['hotel_admin_name']
    );
}

function HoRequireHotelAdmin(PDO $pdo): array
{
    HoEnsureHotelAdminTables($pdo);

    $isLoggedIn = isset($_SESSION['hotel_admin_logged_in']) && $_SESSION['hotel_admin_logged_in'] === true;
    $hotelAdminId = (int)($_SESSION['hotel_admin_id'] ?? 0);
    if (!$isLoggedIn || $hotelAdminId < 1) {
        header('Location: php/hotel_admin_login.php');
        exit;
    }

    $stmt = $pdo->prepare("
        SELECT
          ha.hotel_admin_id,
          ha.hotel_resort_id,
          ha.username,
          ha.full_name,
          ha.status,
          hr.name AS property_name
        FROM hotel_admin_accounts ha
        LEFT JOIN hotel_resorts hr ON hr.hotel_resort_id = ha.hotel_resort_id
        WHERE ha.hotel_admin_id = ?
        LIMIT 1
    ");
    $stmt->execute([$hotelAdminId]);
    $admin = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$admin || strtolower((string)$admin['status']) !== 'active') {
        HoClearHotelAdminSession();
        header('Location: php/hotel_admin_login.php');
        exit;
    }

    HoSetHotelAdminSession($admin);
    return $admin;
}

function HoGetPendingCount(PDO $pdo, ?int $hotelResortId = null): int
{
    if ($hotelResortId && $hotelResortId > 0) {
        $stmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM hotel_room_bookings
            WHERE booking_status = 'pending' AND hotel_resort_id = ?
        ");
        $stmt->execute([(int)$hotelResortId]);
        return (int)$stmt->fetchColumn();
    }

    return (int)$pdo->query("SELECT COUNT(*) FROM hotel_room_bookings WHERE booking_status = 'pending'")->fetchColumn();
}

function HoGetLatestBookingTimestamp(PDO $pdo, ?int $hotelResortId = null): ?string
{
    if ($hotelResortId && $hotelResortId > 0) {
        $stmt = $pdo->prepare("SELECT MAX(created_at) FROM hotel_room_bookings WHERE hotel_resort_id = ?");
        $stmt->execute([(int)$hotelResortId]);
        $value = $stmt->fetchColumn();
        return $value ? (string)$value : null;
    }

    $value = $pdo->query("SELECT MAX(created_at) FROM hotel_room_bookings")->fetchColumn();
    return $value ? (string)$value : null;
}

function HoGetNotifSeenAt(?int $hotelResortId = null): ?string
{
    $suffix = ($hotelResortId && $hotelResortId > 0) ? '_' . (int)$hotelResortId : '';
    $key = 'ho_last_notif_seen_at' . $suffix;
    return isset($_SESSION[$key]) ? (string)$_SESSION[$key] : null;
}

function HoMarkNotificationsRead(PDO $pdo, ?int $hotelResortId = null): void
{
    $latest = HoGetLatestBookingTimestamp($pdo, $hotelResortId);
    $suffix = ($hotelResortId && $hotelResortId > 0) ? '_' . (int)$hotelResortId : '';
    $key = 'ho_last_notif_seen_at' . $suffix;
    $_SESSION[$key] = $latest ?? date('Y-m-d H:i:s');
}

function HoGetUnreadCount(PDO $pdo, ?int $hotelResortId = null): int
{
    $seenAt = HoGetNotifSeenAt($hotelResortId);
    $hotelFilter = ($hotelResortId && $hotelResortId > 0) ? (int)$hotelResortId : 0;

    if (!$seenAt) {
        if ($hotelFilter > 0) {
            $stmt = $pdo->prepare("
                SELECT COUNT(*)
                FROM hotel_room_bookings
                WHERE booking_status = 'pending' AND hotel_resort_id = ?
            ");
            $stmt->execute([$hotelFilter]);
            return (int)$stmt->fetchColumn();
        }

        return (int)$pdo->query("SELECT COUNT(*) FROM hotel_room_bookings WHERE booking_status = 'pending'")->fetchColumn();
    }

    if ($hotelFilter > 0) {
        $stmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM hotel_room_bookings
            WHERE booking_status = 'pending' AND created_at > ? AND hotel_resort_id = ?
        ");
        $stmt->execute([$seenAt, $hotelFilter]);
    } else {
        $stmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM hotel_room_bookings
            WHERE booking_status = 'pending' AND created_at > ?
        ");
        $stmt->execute([$seenAt]);
    }

    return (int)$stmt->fetchColumn();
}

function HoGetNotificationItems(PDO $pdo, int $limit = 8, ?int $hotelResortId = null): array
{
    $hotelFilter = ($hotelResortId && $hotelResortId > 0) ? (int)$hotelResortId : 0;
    $sql = "
        SELECT
          hotel_booking_id,
          first_name,
          last_name,
          room_type,
          checkin_date,
          booking_status,
          created_at
        FROM hotel_room_bookings
    ";
    if ($hotelFilter > 0) {
        $sql .= " WHERE hotel_resort_id = :hotel_resort_id ";
    }
    $sql .= " ORDER BY created_at DESC LIMIT :limit_rows ";

    $stmt = $pdo->prepare($sql);
    if ($hotelFilter > 0) {
        $stmt->bindValue(':hotel_resort_id', $hotelFilter, PDO::PARAM_INT);
    }
    $stmt->bindValue(':limit_rows', $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}


