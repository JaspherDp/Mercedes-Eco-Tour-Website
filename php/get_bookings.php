<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    require_once __DIR__ . '/session_security.php';
    AppSessionStart();
}

require_once __DIR__ . '/db_connection.php';
require_once __DIR__ . '/admin_auth_helper.php';
require_once __DIR__ . '/operator_auth_helper.php';
require_once __DIR__ . '/tourist_auth_helper.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$where = '';
$params = [];
$adminAuthorized = ($_SESSION['admin_logged_in'] ?? false) === true && AdminValidateSession($pdo);
$operatorAuthorized = !$adminAuthorized && ($_SESSION['operator_logged_in'] ?? false) === true && OperatorValidateSession($pdo);
$touristAuthorized = !$adminAuthorized && !$operatorAuthorized && (int)($_SESSION['tourist_id'] ?? 0) > 0 && TouristValidateSession($pdo);
if ($adminAuthorized) {
    // Administrators may retrieve all booking summaries.
} elseif ($operatorAuthorized) {
    $where = ' WHERE operator_id = ?';
    $params[] = (int)$_SESSION['operator_id'];
} elseif ($touristAuthorized) {
    $where = ' WHERE tourist_id = ?';
    $params[] = (int)$_SESSION['tourist_id'];
} else {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Authentication required.']);
    exit;
}

try {
    $stmt = $pdo->prepare(
        'SELECT booking_id, booking_reference, tourist_id, operator_id, booking_date,
                booking_type, package_name, location, status, is_complete
         FROM bookings' . $where . '
         ORDER BY booking_id DESC'
    );
    $stmt->execute($params);
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC), JSON_INVALID_UTF8_SUBSTITUTE);
} catch (Throwable $error) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Bookings could not be loaded.']);
}
