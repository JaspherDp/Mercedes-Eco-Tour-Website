<?php
declare(strict_types=1);
require_once __DIR__ . '/session_security.php';
AppSessionStart();
require_once __DIR__ . '/db_connection.php';
require_once __DIR__ . '/booking_addon_catalog.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST'); http_response_code(405); echo json_encode(['success' => false, 'message' => 'Method not allowed.']); exit;
}
try {
    $role = (string)($_POST['role'] ?? '');
    $hotelId = 0;
    if ($role === 'admin') {
        require_once __DIR__ . '/admin_management_helper.php';
        adminManagementRequireLogin();
        $ownerId = (int)($_SESSION['admin_id'] ?? 0);
    } elseif ($role === 'operator') {
        require_once __DIR__ . '/operator_auth_helper.php';
        $account = OperatorRequireLogin($pdo);
        $ownerId = (int)$account['operator_id'];
    } elseif ($role === 'hotel_admin') {
        require_once __DIR__ . '/../Ho_common.php';
        $account = HoRequireHotelAdmin($pdo);
        $ownerId = (int)$account['hotel_admin_id'];
        $hotelId = (int)$account['hotel_resort_id'];
    } else { throw new DomainException('Sign in to manage add-ons.'); }
    if (!AppVerifyCsrf($role, 'addon_catalog', $_POST['csrf_token'] ?? null)) {
        http_response_code(403); echo json_encode(['success' => false, 'message' => 'Your form session expired. Refresh the page and try again.']); exit;
    }
    AddonCatalogEnsureSchema($pdo);
    AddonCatalogSave($pdo, $role, $ownerId, $hotelId, $_POST);
    echo json_encode(['success' => true, 'message' => 'Add-on saved.', 'items' => AddonCatalogOwned($pdo, $role, $ownerId, $hotelId)], JSON_INVALID_UTF8_SUBSTITUTE);
} catch (InvalidArgumentException|DomainException $error) {
    http_response_code(422); echo json_encode(['success' => false, 'message' => $error->getMessage()]);
} catch (Throwable $error) {
    error_log('Add-on catalog save failed: ' . $error->getMessage());
    http_response_code(503); echo json_encode(['success' => false, 'message' => 'Add-ons could not be saved. Please try again.']);
}
