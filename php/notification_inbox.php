<?php
declare(strict_types=1);
require_once __DIR__ . '/session_security.php';
AppSessionStart();
require_once __DIR__ . '/db_connection.php';
require_once __DIR__ . '/notification_component.php';
header('Cache-Control: private, no-store, max-age=0');
header('Vary: Cookie');
$role = (string)($_GET['role'] ?? '');
$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$open = $method === 'GET' ? filter_var($_GET['open'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : false;

function notificationResponse(int $status, array $body): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($body, JSON_UNESCAPED_SLASHES);
    exit;
}
if (!in_array($role, ['tourist','admin','operator','hotel_admin'], true)) notificationResponse(400, ['success'=>false, 'message'=>'Invalid notification panel.']);
if (!in_array($method, ['GET','POST'], true)) { header('Allow: GET, POST'); notificationResponse(405, ['success'=>false]); }

// Use existing auth validators for tourists/operators/admins; hotel validation is
// equivalent to HoRequireHotelAdmin without including its runtime schema DDL.
$propertyId = 0;
$id = (int)($_SESSION[match ($role) { 'tourist'=>'tourist_id', 'admin'=>'admin_id', 'operator'=>'operator_id', 'hotel_admin'=>'hotel_admin_id' }] ?? 0);
$authenticated = false;
try {
    if ($role === 'admin') { require_once __DIR__ . '/admin_auth_helper.php'; $authenticated = AdminValidateSession($pdo); }
    elseif ($role === 'tourist') { require_once __DIR__ . '/tourist_auth_helper.php'; $authenticated = TouristValidateSession($pdo) !== false; }
    elseif ($role === 'operator') { require_once __DIR__ . '/operator_auth_helper.php'; $authenticated = OperatorValidateSession($pdo) !== false; }
    elseif (($_SESSION['hotel_admin_logged_in'] ?? false) === true && $id > 0 && AppRoleSessionIsActive($role, $pdo, false)) {
        $query = $pdo->prepare("SELECT hotel_resort_id FROM hotel_admin_accounts WHERE hotel_admin_id=? AND LOWER(status)='active'");
        $query->execute([$id]);
        $propertyId = (int)$query->fetchColumn();
        $authenticated = $propertyId > 0;
    }
} catch (Throwable $error) { notificationResponse(503, ['success'=>false, 'message'=>'Notifications are temporarily unavailable.']); }
if (!$authenticated) {
    if ($open) {
        $_SESSION['notification_pending'][$role] = (int)$open;
        $login = match ($role) { 'tourist'=>'?open_login=1', 'admin'=>'php/admin_login.php', 'operator'=>'php/operator_login.php', 'hotel_admin'=>'php/hotel_admin_login.php' };
        header('Location: ' . NotificationWebBase() . $login);
        exit;
    }
    notificationResponse(401, ['success'=>false, 'message'=>'Please sign in again to view notifications.']);
}
if (!NotificationAvailable($pdo)) notificationResponse(503, ['success'=>false, 'message'=>'Notifications are temporarily unavailable.']);
try {
    if ($open) {
        [$where, $params] = NotificationScope($role, $id, $propertyId);
        $query = $pdo->prepare("SELECT * FROM app_notifications WHERE $where AND notification_id=? LIMIT 1");
        $query->execute([...$params, (int)$open]);
        $item = $query->fetch(PDO::FETCH_ASSOC);
        $booking = $item ? NotificationBooking($pdo, $item['booking_domain'], (int)$item['booking_id']) : null;
        if (!$booking || !NotificationCanAccess($booking, $item['booking_domain'], $role, $id, $propertyId)) {
            http_response_code(404);
            header('Content-Type: text/html; charset=utf-8');
            $home = match ($role) { 'tourist'=>'php/profile.php?section=bookings', 'admin'=>'adbookings.php', 'operator'=>'opbookings.php', 'hotel_admin'=>'Hobookings.php' };
            echo '<!doctype html><meta name="viewport" content="width=device-width"><title>Notification unavailable</title><p>This booking is unavailable or you no longer have access.</p><a href="' . htmlspecialchars(NotificationWebBase() . $home, ENT_QUOTES, 'UTF-8') . '">Return to bookings</a>';
            exit;
        }
        header('Location: ' . NotificationWebBase() . NotificationDestination($pdo, $item, $booking, $role));
        exit;
    }
    if ($method === 'POST') {
        if (!AppVerifyCsrf($role, 'notification_inbox', $_POST['csrf_token'] ?? null)) notificationResponse(403, ['success'=>false, 'message'=>'Refresh the page and try again.']);
        $only = filter_var($_POST['notification_id'] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
        $through = filter_var($_POST['through'] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
        if (!$only && !$through) notificationResponse(422, ['success'=>false, 'message'=>'Invalid notification selection.']);
        NotificationMarkRead($pdo, $role, $id, $propertyId, (int)$through, $only ? (int)$only : null);
        notificationResponse(200, ['success'=>true]);
    }
    notificationResponse(200, ['success'=>true] + NotificationInbox($pdo, $role, $id, $propertyId));
} catch (Throwable $error) { notificationResponse(503, ['success'=>false, 'message'=>'Notifications are temporarily unavailable.']); }
