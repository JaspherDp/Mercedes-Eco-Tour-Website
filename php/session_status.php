<?php
declare(strict_types=1);

require_once __DIR__ . '/session_security.php';
AppSessionStart();
require_once __DIR__ . '/db_connection.php';
require_once __DIR__ . '/operator_auth_helper.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');

$role = (string)($_GET['role'] ?? '');
$accounts = [
    'admin' => ['admin_users', 'admin_id', 'admin_login.php'],
    'operator' => ['operators', 'operator_id', 'operator_login.php'],
    'hotel_admin' => ['hotel_admin_accounts', 'hotel_admin_id', 'hotel_admin_login.php'],
];
if (!isset($accounts[$role])) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid panel.']);
    exit;
}
[$table, $idColumn, $loginPage] = $accounts[$role];
$loginUrl = operatorPortalBaseUrl() . '/php/' . $loginPage;
$accountId = AppRoleAccountId($role);
$active = ($_SESSION[$role . '_logged_in'] ?? false) === true && $accountId > 0;
// An old page cannot renew or invalidate a different/newly authenticated session.
$generation = (string)($_GET['generation'] ?? '');
if ($active && ($generation === '' || !hash_equals((string)($_SESSION['auth_generation'][$role] ?? ''), $generation))) {
    session_write_close();
    echo json_encode(['active' => false, 'code' => 'SESSION_REPLACED', 'login_url' => $loginUrl]);
    exit;
}
if ($active) {
    $stmt = $pdo->prepare("SELECT $idColumn" . ($role === 'admin' ? '' : ', status') . " FROM $table WHERE $idColumn=?");
    $stmt->execute([$accountId]);
    $account = $stmt->fetch(PDO::FETCH_ASSOC);
    $active = $account && ($role === 'admin' || strtolower((string)$account['status']) === 'active');
}
if ($active) $active = AppRoleSessionIsActive($role, $pdo, false);
if (!$active) {
    AppClearRoleAuthentication($role);
    session_write_close();
    http_response_code(401);
    echo json_encode(['active' => false, 'code' => 'SESSION_EXPIRED', 'login_url' => $loginUrl]);
    exit;
}
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!AppVerifyCsrf($role, 'session_activity', $_POST['csrf_token'] ?? null)) {
        session_write_close();
        http_response_code(403);
        echo json_encode(['error' => 'Invalid activity token.']);
        exit;
    }
    // Expiry was checked BEFORE activity: an expired session cannot be revived.
    AppRoleSessionIsActive($role, $pdo, true);
}
$minutes = AppSessionTimeoutMinutes($pdo, $role);
$remaining = max(0, (int)$_SESSION['auth_last_activity'][$role] + $minutes * 60 - time());
session_write_close();
echo json_encode(['active' => true, 'timeout_minutes' => $minutes, 'remaining_seconds' => $remaining]);
