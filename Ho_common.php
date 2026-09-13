<?php
date_default_timezone_set('Asia/Manila');

require_once __DIR__ . '/php/session_security.php';
AppSessionStart();

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

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS hotel_admin_push_devices (
          device_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
          hotel_admin_id INT NOT NULL,
          fcm_token TEXT NOT NULL,
          device_name VARCHAR(100) NULL,
          created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          last_used_at DATETIME NULL,
          revoked_at DATETIME NULL,
          UNIQUE KEY uq_hotel_admin_fcm_token (fcm_token(191)),
          INDEX idx_hotel_push_admin (hotel_admin_id),
          INDEX idx_hotel_push_active (hotel_admin_id, revoked_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS hotel_admin_profile_details (
          hotel_admin_id INT NOT NULL PRIMARY KEY,
          phone VARCHAR(40) DEFAULT NULL,
          job_title VARCHAR(100) DEFAULT NULL,
          bio VARCHAR(500) DEFAULT NULL,
          profile_picture VARCHAR(255) DEFAULT NULL,
          updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          INDEX idx_hotel_admin_profile_updated (updated_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

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
    $_SESSION['hotel_admin_profile_picture'] = (string)($admin['profile_picture'] ?? '');
}

function HoClearHotelAdminSession(): void
{
    AppClearRoleAuthentication('hotel_admin');
}

function HoNormalizeHotelAdminReturnTo(?string $value): string
{
    $candidate = trim((string)$value);
    if ($candidate === '') {
        return 'Hohome.php';
    }

    $parts = parse_url($candidate);
    if (!is_array($parts) || isset($parts['scheme'], $parts['host'])) {
        return 'Hohome.php';
    }

    $page = basename(str_replace('\\', '/', (string)($parts['path'] ?? '')));
    $allowedPages = [
        'Hohome.php',
        'Hobookings.php',
        'Horooms.php',
        'Hopayments.php',
        'Hoearnings.php',
        'Hocontents.php',
        'Horeviews.php',
        'Hoprofile.php',
        'hotel-admin-phone-setup.php',
    ];
    if (!in_array($page, $allowedPages, true)) {
        return 'Hohome.php';
    }

    $query = trim((string)($parts['query'] ?? ''));
    return $page . ($query !== '' ? '?' . $query : '');
}

function HoHotelAdminLoginUrl(): string
{
    $requestUri = (string)($_SERVER['REQUEST_URI'] ?? '');
    $returnTo = HoNormalizeHotelAdminReturnTo($requestUri);
    return 'php/hotel_admin_login.php?return_to=' . rawurlencode($returnTo);
}

function HoRedirectToHotelAdminLogin(bool $forceJson = false): never
{
    $loginUrl = HoHotelAdminLoginUrl();
    $isAjax = $forceJson
        || strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest'
        || str_contains(strtolower((string)($_SERVER['HTTP_ACCEPT'] ?? '')), 'application/json');

    if ($isAjax) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(401);
        echo json_encode([
            'success' => false,
            'code' => 'SESSION_EXPIRED',
            'message' => 'Your session expired. Please log in again.',
            'login_url' => $loginUrl,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    header('Location: ' . $loginUrl);
    exit;
}

function HoRequireHotelAdmin(PDO $pdo, bool $forceJson = false): array
{
    HoEnsureHotelAdminTables($pdo);

    $isLoggedIn = isset($_SESSION['hotel_admin_logged_in']) && $_SESSION['hotel_admin_logged_in'] === true;
    $hotelAdminId = (int)($_SESSION['hotel_admin_id'] ?? 0);
    if (!$isLoggedIn || $hotelAdminId < 1 || !AppRoleSessionIsActive('hotel_admin', $pdo)) {
        $hasHotelCookie = !empty($_COOKIE['hotel_admin_seen']);
        $_SESSION['alert'] = [
            'type' => 'error',
            'title' => $hasHotelCookie ? 'Session Expired' : 'Access Denied',
            'message' => $hasHotelCookie
                ? 'Your session expired. Please log in again.'
                : 'Error accessing the hotel admin. Please log in first.'
        ];
        HoRedirectToHotelAdminLogin($forceJson);
    }

    $stmt = $pdo->prepare("
        SELECT
          ha.hotel_admin_id,
          ha.hotel_resort_id,
          ha.username,
          ha.full_name,
          ha.status,
          ha.created_at AS account_created_at,
          ha.updated_at AS account_updated_at,
          hr.name AS property_name,
          hr.island AS property_island,
          hr.type AS property_type,
          hr.image_path AS property_image,
          hr.status AS property_status,
          hp.phone,
          hp.job_title,
          hp.bio,
          hp.profile_picture,
          hp.updated_at AS profile_updated_at
        FROM hotel_admin_accounts ha
        LEFT JOIN hotel_resorts hr ON hr.hotel_resort_id = ha.hotel_resort_id
        LEFT JOIN hotel_admin_profile_details hp ON hp.hotel_admin_id = ha.hotel_admin_id
        WHERE ha.hotel_admin_id = ?
        LIMIT 1
    ");
    $stmt->execute([$hotelAdminId]);
    $admin = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$admin || strtolower((string)$admin['status']) !== 'active') {
        HoClearHotelAdminSession();
        session_regenerate_id(true);
        $hasHotelCookie = !empty($_COOKIE['hotel_admin_seen']);
        $_SESSION['alert'] = [
            'type' => 'error',
            'title' => $hasHotelCookie ? 'Session Expired' : 'Access Denied',
            'message' => $hasHotelCookie
                ? 'Your session expired. Please log in again.'
                : 'Error accessing the hotel admin. Please log in first.'
        ];
        HoRedirectToHotelAdminLogin($forceJson);
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
    $seenAt = HoGetNotifSeenAt($hotelResortId);
    $seenAtTs = $seenAt ? strtotime($seenAt) : false;
    $hotelFilter = ($hotelResortId && $hotelResortId > 0) ? (int)$hotelResortId : 0;
    $sql = "
        SELECT
          hotel_booking_id,
          booking_reference,
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
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($items as &$item) {
        $status = strtolower((string)($item['booking_status'] ?? ''));
        $createdAt = (string)($item['created_at'] ?? '');
        $createdAtTs = $createdAt !== '' ? strtotime($createdAt) : false;
        $isUnread = $status === 'pending' && ($seenAtTs === false || ($createdAtTs !== false && $createdAtTs > $seenAtTs));
        $item['is_unread'] = $isUnread ? 1 : 0;
    }
    unset($item);
    return $items;
}


