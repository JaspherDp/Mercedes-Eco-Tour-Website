<?php
chdir(__DIR__ . '/..');
require_once __DIR__ . '/../php/session_security.php';
AppSessionStart();
require_once "php/db_connection.php";
require_once __DIR__ . '/../php/activity_logger.php';
require_once __DIR__ . '/../php/admin_auth_helper.php';
AdminRequireLogin();
$adminHotelCsrf = AppCsrfToken('admin', 'hotel_management');
include 'php/alert.php';

if (isset($_GET['ajax']) && $_GET['ajax'] === 'fetch') {

    $search = trim($_GET['search'] ?? '');
    $status = $_GET['status'] ?? 'all';
    $type = $_GET['type'] ?? 'all';
    $sort = $_GET['sort'] ?? 'id_desc';
    $rows = (int)($_GET['rows'] ?? 25);

    $where = [];
    $params = [];

    if ($search !== '') {
        $where[] = "(hr.name LIKE ? OR hr.island LIKE ? OR ha.username LIKE ? OR ha.full_name LIKE ?)";
        array_push($params, "%$search%", "%$search%", "%$search%", "%$search%");
    }

    if ($status !== 'all') {
        $where[] = "hr.status = ?";
        $params[] = $status;
    }

    if ($type !== 'all') {
        $where[] = "hr.type = ?";
        $params[] = $type;
    }

    $whereSql = $where ? "WHERE " . implode(" AND ", $where) : "";

    $order = "hr.hotel_resort_id DESC";
    if ($sort === 'name_asc') $order = "hr.name ASC";
    if ($sort === 'name_desc') $order = "hr.name DESC";
    if ($sort === 'id_asc') $order = "hr.hotel_resort_id ASC";

    $sql = "SELECT hr.hotel_resort_id, hr.name, hr.island, hr.type, hr.status,
                   hr.price, hr.popular, hr.image_path, hr.description_text, hr.created_at, hr.updated_at,
                   ha.username, ha.full_name AS administrator_name, ha.status AS account_status,
                   COALESCE(r.room_count, 0) AS room_count,
                   COALESCE(r.active_room_count, 0) AS active_room_count,
                   COALESCE(r.available_units, 0) AS available_units,
                   COALESCE(b.booking_count, 0) AS booking_count
            FROM hotel_resorts hr
            LEFT JOIN hotel_admin_accounts ha ON ha.hotel_resort_id = hr.hotel_resort_id
            LEFT JOIN (
              SELECT hotel_resort_id, COUNT(*) AS room_count,
                     SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) AS active_room_count,
                     SUM(CASE WHEN status = 'active' THEN available_units ELSE 0 END) AS available_units
              FROM hotel_rooms GROUP BY hotel_resort_id
            ) r ON r.hotel_resort_id = hr.hotel_resort_id
            LEFT JOIN (
              SELECT hotel_resort_id, COUNT(*) AS booking_count
              FROM hotel_room_bookings GROUP BY hotel_resort_id
            ) b ON b.hotel_resort_id = hr.hotel_resort_id
            $whereSql
            ORDER BY $order
            LIMIT $rows";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

    header('Content-Type: application/json');
    echo json_encode($data);
    exit();
}

if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    AppDestroySession();
    echo "<script>alert('Logged out.');window.location.href='homepage.php';</script>";
    exit();
}

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

$pdo->exec("
    CREATE TABLE IF NOT EXISTS hotel_resorts (
      hotel_resort_id INT AUTO_INCREMENT PRIMARY KEY,
      name VARCHAR(150) NOT NULL,
      island VARCHAR(100) NOT NULL,
      type VARCHAR(20) NOT NULL,
      price DECIMAL(10,2) NOT NULL DEFAULT 0,
      popular TINYINT(1) NOT NULL DEFAULT 0,
      image_path VARCHAR(255) DEFAULT NULL,
      amenities_json TEXT DEFAULT NULL,
      status VARCHAR(20) NOT NULL DEFAULT 'active',
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      UNIQUE KEY uq_hotel_resort_name (name)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

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

if (empty($_SESSION['hotel_account_password_csrf'])) {
    $_SESSION['hotel_account_password_csrf'] = bin2hex(random_bytes(32));
}
$hotelAccountPasswordCsrf = (string)$_SESSION['hotel_account_password_csrf'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reset_hotel_password'])) {
    header('Content-Type: application/json; charset=utf-8');

    try {
        $submittedCsrf = (string)($_POST['csrf_token'] ?? '');
        if ($submittedCsrf === '' || !hash_equals($hotelAccountPasswordCsrf, $submittedCsrf)) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Your security token expired. Refresh the page and try again.']);
            exit;
        }

        $hotelId = (int)($_POST['hotel_id'] ?? 0);
        $newPassword = (string)($_POST['new_password'] ?? '');
        $confirmPassword = (string)($_POST['confirm_password'] ?? '');

        if ($hotelId < 1) {
            throw new InvalidArgumentException('Select a valid hotel or resort account.');
        }
        if (strlen($newPassword) < 8) {
            throw new InvalidArgumentException('The new password must be at least 8 characters.');
        }
        if ($newPassword !== $confirmPassword) {
            throw new InvalidArgumentException('Password confirmation does not match.');
        }

        $accountStmt = $pdo->prepare(
            'SELECT hotel_admin_id, full_name, username
             FROM hotel_admin_accounts
             WHERE hotel_resort_id = ?
             LIMIT 1'
        );
        $accountStmt->execute([$hotelId]);
        $hotelAccount = $accountStmt->fetch(PDO::FETCH_ASSOC);
        if (!$hotelAccount) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'This property does not have a Hotel Owner account.']);
            exit;
        }

        $passwordStmt = $pdo->prepare(
            'UPDATE hotel_admin_accounts SET password = ?, updated_at = NOW() WHERE hotel_admin_id = ?'
        );
        $passwordStmt->execute([
            password_hash($newPassword, PASSWORD_DEFAULT),
            (int)$hotelAccount['hotel_admin_id'],
        ]);

        logActivity(
            $pdo,
            'Admin',
            (int)($_SESSION['admin_id'] ?? 0),
            (string)($_SESSION['admin_name'] ?? 'Administrator'),
            'Hotel Owner Password Reset',
            'Reset the Hotel Owner password for property #' . $hotelId . '.',
            'Hotels and Resorts',
            $hotelId
        );

        echo json_encode(['success' => true, 'message' => 'The Hotel Owner password was reset successfully.']);
        exit;
    } catch (InvalidArgumentException $e) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit;
    } catch (Throwable $e) {
        error_log('HOTEL OWNER PASSWORD RESET ERROR: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'The password could not be reset right now.']);
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_status'])) {
    header('Content-Type: application/json');
    if (!AppVerifyCsrf('admin', 'hotel_management', $_POST['csrf_token'] ?? null)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Invalid or expired request token.']);
        exit;
    }

    try {
        $id = (int)($_POST['hotel_id'] ?? 0);
        $status = ($_POST['new_status'] ?? '') === 'active' ? 'active' : 'inactive';

        if ($id <= 0) {
            echo json_encode([
                'success' => false,
                'message' => 'Invalid hotel ID'
            ]);
            exit();
        }

        $stmt = $pdo->prepare("
            UPDATE hotel_resorts 
            SET status = ?, updated_at = NOW()
            WHERE hotel_resort_id = ?
        ");

        $ok = $stmt->execute([$status, $id]);
        if ($ok && $stmt->rowCount() > 0) {
            logActivity(
                $pdo, 'Admin', (int)($_SESSION['admin_id'] ?? 0),
                (string)($_SESSION['admin_name'] ?? 'Administrator'),
                'Hotel Account Updated',
                'Changed hotel/resort #' . $id . ' status to ' . $status . '.',
                'Hotels and Resorts', $id
            );
        }

        echo json_encode([
            'success' => $ok,
            'status' => $status
        ]);
        exit();

    } catch (Throwable $e) {

      if ($pdo->inTransaction()) {
          $pdo->rollBack();
      }

      die("ERROR: " . $e->getMessage());
  }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_hotel_account'])) {

    if (!AppVerifyCsrf('admin', 'hotel_management', $_POST['csrf_token'] ?? null)) {
        http_response_code(403);
        exit('Invalid or expired request token.');
    }

    try {
        $propertyName = trim((string)($_POST['property_name'] ?? ''));
        $propertyType = strtolower(trim((string)($_POST['property_type'] ?? '')));
        $propertyLocation = trim((string)($_POST['property_location'] ?? ''));
        $email = strtolower(trim((string)($_POST['email'] ?? '')));
        $password = (string)($_POST['password'] ?? '');
        $confirmPassword = (string)($_POST['confirm_password'] ?? '');
        $administratorName = trim((string)($_POST['administrator_name'] ?? ''));
        $description = trim((string)($_POST['description'] ?? ''));

        // VALIDATION
        if (
            $propertyName === '' ||
            !in_array($propertyType, ['hotel', 'resort'], true) ||
            $propertyLocation === '' ||
            !filter_var($email, FILTER_VALIDATE_EMAIL) ||
            $password === '' ||
            $administratorName === ''
        ) {
            throw new Exception("Please complete all fields correctly.");
        }

        if (strlen($password) < 8) {
            throw new Exception("Password must be at least 8 characters.");
        }

        if ($password !== $confirmPassword) {
            throw new Exception("Password confirmation does not match.");
        }

        // normalize
        $propertyNameClean = mb_strtolower(trim($propertyName));

        // CHECK DUPLICATES (safe)
        $checkName = $pdo->prepare("
            SELECT COUNT(*) 
            FROM hotel_resorts 
            WHERE LOWER(TRIM(name)) = ?
        ");
        $checkName->execute([$propertyNameClean]);

        if ($checkName->fetchColumn() > 0) {
            throw new Exception("Hotel/Resort name already exists.");
        }

        $checkUsername = $pdo->prepare("
            SELECT COUNT(*) 
            FROM hotel_admin_accounts 
            WHERE LOWER(username) = ?
        ");
        $checkUsername->execute([$email]);

        if ($checkUsername->fetchColumn() > 0) {
            throw new Exception("Email already used as hotel admin username.");
        }

        // TRANSACTION START
        $pdo->beginTransaction();

        // 1. Insert hotel
        $insertHotel = $pdo->prepare("
            INSERT INTO hotel_resorts 
            (name, island, type, price, popular, image_path, amenities_json, description_text, status) 
            VALUES 
            (:name, :island, :type, :price, :popular, :image, :amenities, :description, :status)
        ");

        $insertHotel->execute([
            ':name' => $propertyName,
            ':island' => $propertyLocation,
            ':type' => $propertyType,
            ':price' => 0,
            ':popular' => 0,
            ':image' => 'img/sampleimage.png',
            ':amenities' => json_encode(['WiFi']),
            ':description' => $description !== '' ? $description : null,
            ':status' => 'active'
        ]);

        $hotelResortId = $pdo->lastInsertId();

        if (!$hotelResortId) {
            throw new Exception("Failed to create hotel record.");
        }

        // 2. Insert admin account
        $insertAccount = $pdo->prepare("
            INSERT INTO hotel_admin_accounts 
            (hotel_resort_id, username, password, full_name, status)
            VALUES (?, ?, ?, ?, 'active')
        ");

        $insertAccount->execute([
            $hotelResortId,
            $email,
            password_hash($password, PASSWORD_DEFAULT),
            $administratorName
        ]);

        $pdo->commit();
        logActivity(
            $pdo, 'Admin', (int)($_SESSION['admin_id'] ?? 0),
            (string)($_SESSION['admin_name'] ?? 'Administrator'),
            'Hotel Account Created',
            'Created the ' . $propertyType . ' account for ' . $propertyName . '.',
            'Hotels and Resorts', (int)$hotelResortId
        );

        $_SESSION['alert'] = [
            'type' => 'success',
            'title' => 'Created',
            'message' => 'Hotel/Resort account created successfully.'
        ];

        header("Location: adhotelresorts.php");
        exit();

    } catch (Throwable $e) {

        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        error_log("ADD HOTEL ERROR: " . $e->getMessage());

        $_SESSION['alert'] = [
            'type' => 'error',
            'title' => 'Error',
            'message' => $e->getMessage()
        ];

        header("Location: adhotelresorts.php");
        exit();
    }
}

$search = trim((string)($_GET['search'] ?? ''));
$statusFilter = strtolower(trim((string)($_GET['status'] ?? 'all')));
$typeFilter = strtolower(trim((string)($_GET['type'] ?? 'all')));
$sortBy = $_GET['sort'] ?? 'id_desc';
$rowsPerPage = (int)($_GET['rows'] ?? 25);
if ($rowsPerPage < 1) $rowsPerPage = 25;
if ($rowsPerPage > 300) $rowsPerPage = 300;

$where = [];
$params = [];

if (!empty($search)) {
    $where[] = "(hr.name LIKE ? OR hr.island LIKE ? OR ha.username LIKE ? OR ha.full_name LIKE ?)";
    array_push($params, "%$search%", "%$search%", "%$search%", "%$search%");
}

if ($statusFilter !== 'all') {
    $where[] = "hr.status = ?";
    $params[] = $statusFilter;
}

if ($typeFilter !== 'all') {
    $where[] = "hr.type = ?";
    $params[] = $typeFilter;
}

$whereSql = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

$orderClause = 'hr.hotel_resort_id DESC';
switch ($sortBy) {
    case 'name_asc':
        $orderClause = 'hr.name ASC';
        break;
    case 'name_desc':
        $orderClause = 'hr.name DESC';
        break;
    case 'id_asc':
        $orderClause = 'hr.hotel_resort_id ASC';
        break;
    case 'id_desc':
    default:
        $orderClause = 'hr.hotel_resort_id DESC';
}

$sql = "SELECT hr.hotel_resort_id, hr.name, hr.type, hr.island, hr.status,
               hr.price, hr.popular, hr.image_path, hr.description_text, hr.created_at, hr.updated_at,
               ha.username, ha.full_name AS administrator_name, ha.status AS account_status,
               COALESCE(r.room_count, 0) AS room_count,
               COALESCE(r.active_room_count, 0) AS active_room_count,
               COALESCE(r.available_units, 0) AS available_units,
               COALESCE(b.booking_count, 0) AS booking_count
        FROM hotel_resorts hr 
        LEFT JOIN hotel_admin_accounts ha ON ha.hotel_resort_id = hr.hotel_resort_id
        LEFT JOIN (
          SELECT hotel_resort_id, COUNT(*) AS room_count,
                 SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) AS active_room_count,
                 SUM(CASE WHEN status = 'active' THEN available_units ELSE 0 END) AS available_units
          FROM hotel_rooms GROUP BY hotel_resort_id
        ) r ON r.hotel_resort_id = hr.hotel_resort_id
        LEFT JOIN (
          SELECT hotel_resort_id, COUNT(*) AS booking_count
          FROM hotel_room_bookings GROUP BY hotel_resort_id
        ) b ON b.hotel_resort_id = hr.hotel_resort_id
        $whereSql 
        ORDER BY $orderClause 
        LIMIT $rowsPerPage";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$hotels = $stmt->fetchAll(PDO::FETCH_ASSOC);
$propertySummary = $pdo->query("
    SELECT COUNT(*) AS total,
           SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) AS active,
           SUM(CASE WHEN type = 'hotel' THEN 1 ELSE 0 END) AS hotels,
           SUM(CASE WHEN type = 'resort' THEN 1 ELSE 0 END) AS resorts
    FROM hotel_resorts
")->fetch(PDO::FETCH_ASSOC) ?: ['total' => 0, 'active' => 0, 'hotels' => 0, 'resorts' => 0];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title>iTour Mercedes - Hotel/Resorts</title>
<link rel="icon" type="image/png" href="img/newlogo.png" />
<link href="https://fonts.googleapis.com/css2?family=Roboto&display=swap" rel="stylesheet" />
<style>
:root {
  --green: #2b7a66;
  --green-dark: #1f5d4c;
  --light: #f5f7fa;
  --shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
}

body {
  margin: 0;
  font-family: 'Segoe UI', sans-serif;
  background: var(--light);
}

.admin-container {
  display: flex;
  min-height: 100vh;
}

.main-content {
  flex: 1;
  margin-left: 240px;
  display: flex;
  flex-direction: column;
  transition: margin-left 0.3s ease;
}

.admin-sidebar.collapsed ~ .main-content {
  margin-left: 80px;
}

.admin-header {
  background: #fff;
  border-bottom: 2px solid #eee;
  padding: 1rem 2rem;
  box-shadow: 0 2px 5px rgba(0, 0, 0, 0.05);
}

.admin-header h2 {
  margin: 0;
  color: var(--green);
  font-size: 1.5rem;
}

.dashboard-content {
  padding: 2rem;
  display: flex;
  flex-direction: column;
  gap: 1rem;
}

.page-actions {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 12px;
}

.page-actions p {
  margin: 0;
  color: #4f5f66;
  font-size: 0.95rem;
}

.add-btn {
  border: none;
  background: var(--green);
  color: #fff;
  padding: 10px 16px;
  border-radius: 8px;
  font-weight: 600;
  cursor: pointer;
  box-shadow: var(--shadow);
}

.add-btn:hover {
  background: var(--green-dark);
}

.hotels-table-wrap {
  background: #fff;
  border-radius: 12px;
  box-shadow: var(--shadow);
  overflow: hidden;
}

.hotels-table {
  width: 100%;
  border-collapse: collapse;
}

.hotels-table thead th {
  background: var(--green);
  color: #fff;
  padding: 12px;
  text-align: left;
  font-size: 0.9rem;
}

.hotels-table tbody td {
  padding: 12px;
  border-bottom: 1px solid #eef1f3;
  font-size: 0.94rem;
  color: #1f2f35;
}

.hotels-table tbody tr:last-child td {
  border-bottom: none;
}

.filter-header-row {
  display: flex;
  align-items: center;
  gap: 12px;
  width: 100%;
  flex-wrap: wrap;
}

/* LEFT: filters group */
.filter-controls-left {
  display: flex;
  gap: 10px;
  align-items: flex-end;
  flex: 0 0 auto;
}

/* each filter */
.filter-group {
  display: flex;
  flex-direction: column;
  gap: 4px;
}

.filter-group label {
  font-size: 0.8rem;
  font-weight: 600;
  white-space: nowrap;
}

.filter-group input,
.filter-group select {
  border: 1px solid #cfd8dc;
  border-radius: 6px;
  padding: 8px 10px;
  font-size: 0.9rem;
  min-width: 110px;
}

/* APPLY BUTTON */
.apply-filter-btn {
  border: none;
  background: var(--green);
  color: #fff;
  padding: 9px 14px;
  border-radius: 6px;
  font-weight: 600;
  cursor: pointer;
  white-space: nowrap;
  flex: 0 0 auto;
}

.apply-filter-btn:hover {
  background: var(--green-dark);
}

/* CENTER SEARCH (THIS FIXES WIDTH ISSUE) */
.search-row {
  flex: 1;              /* THIS is the key */
  display: flex;
  min-width: 200px;
}

.search-input {
  width: 100%;
  border: 1px solid #cfd8dc;
  border-radius: 6px;
  padding: 9px 12px;
  font-size: 0.9rem;
}

/* RIGHT BUTTON */
.add-hotel-btn-inline {
  flex: 0 0 auto;
  border: none;
  background: var(--green);
  color: #fff;
  padding: 10px 14px;
  border-radius: 6px;
  font-weight: 600;
  cursor: pointer;
  white-space: nowrap;
  height: 38px; /* 🔥 fixes vertical misalignment */
  display: flex;
  align-items: center;
}

.add-hotel-btn-inline:hover {
  background: var(--green-dark);
}

.no-login {
  color: #9aa7af;
  font-style: italic;
}

.toggle-status-btn {
  background: var(--green);
  color: #fff;
  border: none;
  padding: 6px 12px;
  border-radius: 6px;
  font-size: 0.9rem;
  cursor: pointer;
  transition: background 0.2s;
}

.toggle-status-btn:hover {
  background: var(--green-dark);
}

.toggle-status-btn:disabled {
  background: #bbb;
  cursor: not-allowed;
}

.capsule {
  display: inline-flex;
  align-items: center;
  border-radius: 999px;
  padding: 5px 12px;
  font-size: 0.8rem;
  font-weight: 700;
  white-space: nowrap;
}

.capsule.hotel {
  background: #e8f7f3;
  color: #1f7d63;
}

.capsule.resort {
  background: #fff3dc;
  color: #b06e00;
}

.capsule.active {
  background: #e8f7f3;
  color: #1f7d63;
}

.capsule.inactive {
  background: #eceff2;
  color: #5d6a72;
}

.modal {
  display: none;
  position: fixed;
  inset: 0;
  background: rgba(0, 0, 0, 0.45);
  align-items: center;
  justify-content: center;
  z-index: 1200;
  padding: 16px;
}

.modal.open {
  display: flex;
}

.modal-content {
  width: 100%;
  max-width: 460px;
  background: #fff;
  border-radius: 12px;
  box-shadow: 0 14px 32px rgba(0, 0, 0, 0.2);
  padding: 1.35rem 1.2rem 1.2rem;
  position: relative;
}

.modal-content h3 {
  margin: 0 0 1rem;
  color: var(--green);
}

.close-btn {
  position: absolute;
  right: 14px;
  top: 10px;
  font-size: 1.5rem;
  line-height: 1;
  color: #63737b;
  cursor: pointer;
}

.form-group {
  margin-bottom: 0.9rem;
}

.form-group label {
  display: block;
  margin-bottom: 5px;
  font-size: 0.9rem;
  color: #2f4248;
  font-weight: 600;
}

.form-group input,
.form-group select {
  width: 100%;
  box-sizing: border-box;
  border: 1px solid #cfd8dc;
  border-radius: 8px;
  padding: 10px 12px;
  font-size: 0.95rem;
}

.field-hint {
  margin: 3px 0 0;
  font-size: 0.78rem;
  color: #6d7d84;
}

.save-btn {
  margin-top: 8px;
  width: 100%;
  border: none;
  border-radius: 8px;
  padding: 11px;
  background: var(--green);
  color: #fff;
  font-weight: 700;
  cursor: pointer;
}

.save-btn:hover {
  background: var(--green-dark);
}

@media (max-width: 900px) {
  .main-content {
    margin-left: 0;
  }

  .dashboard-content {
    padding: 1rem;
  }

  .filter-header-row {
    flex-wrap: wrap;
  }

  .filter-controls-left {
    width: 100%;
    flex-wrap: wrap;
  }

  .apply-filter-btn,
  .add-hotel-btn-inline {
    align-self: flex-start;
  }

  .hotels-table-wrap {
    overflow-x: auto;
  }

  .hotels-table {
    min-width: 760px;
  }
}
</style>
<link rel="stylesheet" href="styles/admin_panel_theme.css" />
<style>
/* Hotel/resort account management */
.hotel-admin-main,
.hotel-admin-main *,
.hotel-admin-main *::before,
.hotel-admin-main *::after {
  box-sizing: border-box;
}

.main-content.hotel-admin-main {
  flex: 0 0 calc(100% - 250px) !important;
  width: calc(100% - 250px) !important;
  max-width: calc(100% - 250px);
  min-width: 0;
  box-sizing: border-box;
  overflow-x: hidden;
}

.admin-sidebar.collapsed ~ .main-content.hotel-admin-main {
  flex-basis: calc(100% - 90px) !important;
  width: calc(100% - 90px) !important;
  max-width: calc(100% - 90px);
}

.admin-container {
  width: 100%;
  max-width: 100%;
  overflow-x: hidden;
}

.hotel-admin-main .admin-header {
  min-width: 0;
}

.hotel-page-header .admin-header-left {
  display: flex !important;
  flex-direction: row !important;
  align-items: center;
  gap: 11px;
}

.hotel-page-title-icon {
  width: 39px;
  height: 39px;
  flex: 0 0 39px;
  display: grid;
  place-items: center;
  border-radius: 12px;
  color: #fff;
  background: linear-gradient(135deg, #2b7a66, #1d5d4a);
  box-shadow: 0 7px 15px rgba(29, 93, 74, .18);
}

.hotel-page-title-icon svg {
  width: 20px;
  height: 20px;
  fill: none;
  stroke: currentColor;
  stroke-width: 1.8;
  stroke-linecap: round;
  stroke-linejoin: round;
}

.hotel-page-title-copy {
  display: grid;
  gap: 2px;
}

.hotel-admin-main .dashboard-content {
  width: 100%;
  min-width: 0;
  padding: 18px 20px 24px !important;
  gap: 14px;
}

.hotel-admin-main .filter-controls {
  width: 100%;
  min-width: 0;
  padding: 16px;
  border: 1px solid #d8e6e0;
  border-radius: 16px;
  background: #fff;
  box-shadow: 0 10px 24px rgba(17, 67, 53, .07);
}

.hotel-admin-main .filter-controls.is-loading {
  cursor: progress;
}

.hotel-admin-main .filter-controls.is-loading .search-input,
.hotel-admin-main .filter-controls.is-loading select,
.hotel-admin-main .filter-controls.is-loading input[type="number"] {
  opacity: .72;
}

.hotel-filter-heading {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 16px;
  margin-bottom: 14px;
}

.hotel-filter-heading-main {
  display: flex;
  align-items: center;
  gap: 11px;
  min-width: 0;
}

.hotel-filter-heading-icon {
  width: 38px;
  height: 38px;
  flex: 0 0 38px;
  display: grid;
  place-items: center;
  border-radius: 11px;
  color: #fff;
  background: linear-gradient(135deg, #2b7a66, #1d5d4a);
  box-shadow: 0 7px 15px rgba(29, 93, 74, .18);
}

.hotel-filter-heading-icon svg {
  width: 19px;
  height: 19px;
  fill: none;
  stroke: currentColor;
  stroke-width: 1.8;
  stroke-linecap: round;
  stroke-linejoin: round;
}

.hotel-filter-heading h3 {
  margin: 0;
  color: #1c353e;
  font-size: 15px;
}

.hotel-filter-heading p {
  margin: 3px 0 0;
  color: #718087;
  font-size: 12px;
}

.hotel-result-count {
  flex: 0 0 auto;
  padding: 6px 10px;
  border: 1px solid #d6e8e1;
  border-radius: 999px;
  color: #286650;
  background: #f1f9f5;
  font-size: 11px;
  font-weight: 800;
}

.hotel-admin-main #filterForm {
  width: 100%;
}

.hotel-admin-main .filter-header-row {
  display: grid;
  gap: 12px;
  width: 100%;
}

.hotel-admin-main .filter-controls-left {
  display: grid;
  grid-template-columns: minmax(90px, .65fr) minmax(130px, 1fr) minmax(120px, 1fr) minmax(145px, 1.05fr) auto;
  align-items: end;
  gap: 10px;
  width: 100%;
  min-width: 0;
}

.hotel-admin-main .filter-group {
  min-width: 0;
  gap: 6px;
}

.hotel-admin-main .filter-group label {
  color: #52656e;
  font-size: 10.5px;
  font-weight: 800;
  letter-spacing: .055em;
  text-transform: uppercase;
}

.hotel-admin-main .filter-group input,
.hotel-admin-main .filter-group select {
  width: 100%;
  min-width: 0;
  height: 42px;
  padding: 0 12px;
  border: 1px solid #d8e6e0;
  border-radius: 12px;
  color: #29434c;
  background-color: #fff;
  font: 600 12.5px Inter, sans-serif;
}

.hotel-admin-main .filter-search-actions {
  display: grid;
  grid-template-columns: minmax(240px, 1fr) auto;
  align-items: center;
  gap: 10px;
  min-width: 0;
}

.hotel-admin-main .search-row {
  width: 100%;
  min-width: 0;
}

.hotel-admin-main .search-input {
  width: 100% !important;
  min-width: 0 !important;
  height: 44px !important;
  padding: 0 14px 0 42px !important;
  border: 1px solid #d8e6e0 !important;
  border-radius: 12px !important;
  background-position: 14px center !important;
  box-shadow: 0 1px 3px rgba(17, 67, 53, .04);
}

.hotel-admin-main .apply-filter-btn {
  min-width: 108px;
  height: 42px;
}

.hotel-admin-main .apply-filter-btn:disabled {
  opacity: .65;
  cursor: wait;
  transform: none !important;
}

.hotel-admin-main .add-hotel-btn-inline {
  height: 44px;
  min-width: 154px;
  padding: 0 16px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
  border: 1px solid #236552;
  border-radius: 12px;
  background: linear-gradient(135deg, #2b7a66, #236552);
  color: #fff;
  font-size: 12.5px;
  font-weight: 800;
  box-shadow: 0 7px 15px rgba(29, 93, 74, .17);
}

.hotel-admin-main .add-hotel-btn-inline svg {
  width: 17px;
  height: 17px;
  fill: none;
  stroke: currentColor;
  stroke-width: 2;
  stroke-linecap: round;
}

.hotel-admin-main .add-hotel-btn-inline:hover {
  background: linear-gradient(135deg, #236d59, #194f40);
  transform: translateY(-1px);
  box-shadow: 0 9px 18px rgba(29, 93, 74, .22);
}

.hotel-table-card {
  width: 100%;
  min-width: 0;
  overflow: hidden;
  border: 1px solid #d8e6e0;
  border-radius: 16px;
  background: #fff;
  box-shadow: 0 10px 24px rgba(17, 67, 53, .07);
}

.hotel-table-card-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 14px;
  padding: 15px 17px 13px;
  border-bottom: 1px solid #e5eeea;
}

.hotel-table-card-head h3 {
  margin: 0;
  color: #1c353e;
  font-size: 15px;
}

.hotel-table-card-head p {
  margin: 3px 0 0;
  color: #728188;
  font-size: 11.5px;
}

.hotel-table-legend {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  color: #62747c;
  font-size: 11px;
  white-space: nowrap;
}

.hotel-table-legend::before {
  content: "";
  width: 7px;
  height: 7px;
  border-radius: 50%;
  background: #2b7a66;
  box-shadow: 0 0 0 4px rgba(43, 122, 102, .1);
}

.hotel-admin-main .hotels-table-wrap {
  width: 100%;
  max-width: 100%;
  min-width: 0;
  overflow-x: auto;
  border: 0;
  border-radius: 0;
  box-shadow: none;
  scrollbar-width: thin;
  scrollbar-color: #b8d3c8 #f3f8f6;
}

.hotel-admin-main .hotels-table {
  width: 100%;
  min-width: 1080px;
  table-layout: fixed;
  border: 0 !important;
  border-radius: 0 !important;
}

.hotel-admin-main .hotels-table th:nth-child(1) { width: 23%; }
.hotel-admin-main .hotels-table th:nth-child(2) { width: 7%; }
.hotel-admin-main .hotels-table th:nth-child(3) { width: 13%; }
.hotel-admin-main .hotels-table th:nth-child(4) { width: 10%; }
.hotel-admin-main .hotels-table th:nth-child(5) { width: 8%; }
.hotel-admin-main .hotels-table th:nth-child(6) { width: 24%; }
.hotel-admin-main .hotels-table th:nth-child(7) { width: 15%; }

.hotel-admin-main .hotels-table thead th {
  padding: 12px 14px !important;
  background: #f3f8f6 !important;
  color: #536970 !important;
  border-bottom: 1px solid #dce9e4 !important;
  font-size: 10.5px;
  font-weight: 800;
  letter-spacing: .06em;
  text-transform: uppercase;
}

.hotel-admin-main .hotels-table tbody td {
  padding: 12px 14px !important;
  color: #30464e;
  border-bottom: 1px solid #e9f0ed !important;
  font-size: 12.5px;
  vertical-align: middle;
}

.hotel-admin-main .hotels-table tbody tr {
  transition: background-color .16s ease;
}

.hotel-admin-main .hotels-table tbody tr:hover {
  background: #f9fcfb !important;
}

.property-cell {
  display: flex;
  align-items: center;
  gap: 10px;
  min-width: 0;
}

.property-avatar {
  width: 38px;
  height: 38px;
  flex: 0 0 38px;
  display: grid;
  place-items: center;
  border-radius: 11px;
  color: #246b55;
  background: #eaf6f1;
  border: 1px solid #d1e8de;
}

.property-avatar.resort {
  color: #9b6100;
  background: #fff5e3;
  border-color: #f2dfb9;
}

.property-avatar svg {
  width: 19px;
  height: 19px;
  fill: none;
  stroke: currentColor;
  stroke-width: 1.8;
  stroke-linecap: round;
  stroke-linejoin: round;
}

.property-meta {
  min-width: 0;
}

.property-meta strong {
  display: block;
  overflow: hidden;
  color: #1d343d;
  font-size: 12.8px;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.property-meta small {
  display: block;
  margin-top: 3px;
  overflow: hidden;
  color: #829097;
  font-size: 10.5px;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.hotel-admin-main .capsule {
  gap: 6px;
  padding: 5px 9px;
  border: 1px solid transparent;
  font-size: 10.5px;
  letter-spacing: .01em;
}

.hotel-admin-main .capsule.active::before,
.hotel-admin-main .capsule.inactive::before {
  content: "";
  width: 6px;
  height: 6px;
  border-radius: 50%;
  background: currentColor;
}

.hotel-admin-main .capsule.hotel {
  border-color: #cde9df;
}

.hotel-admin-main .capsule.resort {
  border-color: #f3dfb8;
}

.hotel-admin-main .capsule.active {
  border-color: #cde9df;
}

.hotel-admin-main .capsule.inactive {
  border-color: #dce2e6;
}

.hotel-login-cell {
  overflow: hidden;
  color: #40565f;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.hotel-login-cell strong,
.hotel-login-cell span,
.property-inventory strong,
.property-inventory span,
.property-bookings strong,
.property-bookings span { display: block; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.hotel-login-cell strong, .property-inventory strong, .property-bookings strong { color: #29483e; font-size: 10.5px; }
.hotel-login-cell span, .property-inventory span, .property-bookings span { margin-top: 4px; color: #7f908a; font-size: 9px; }
.hotel-actions-cell { white-space: nowrap; }
.property-row-actions { display: inline-block; }
.property-actions-toggle { min-width: 92px; min-height: 35px; display: inline-flex; align-items: center; justify-content: center; gap: 7px; padding: 0 12px; border: 0; border-radius: 10px; background: linear-gradient(135deg,#24725c,#165440); color: #fff; font: 800 10.5px Inter,sans-serif; cursor: pointer; box-shadow: 0 6px 13px rgba(22,84,64,.18); }
.property-actions-toggle:hover { background: linear-gradient(135deg,#2b8068,#195e49); }
.property-actions-toggle svg { width: 13px; height: 13px; fill: none; stroke: currentColor; stroke-width: 2; transition: transform .18s ease; }
.property-row-actions.open .property-actions-toggle svg { transform: rotate(180deg); }
.property-actions-menu { position: fixed; z-index: 100300; display: none; width: 190px; padding: 8px; border: 1px solid #d7e5e0; border-radius: 12px; background: #fff; box-shadow: 0 18px 42px rgba(14,54,42,.2),0 3px 10px rgba(14,54,42,.08); }
.property-row-actions.open .property-actions-menu { display: grid; gap: 3px; }
.property-actions-menu::before { content: "PROPERTY ACTIONS"; display: block; padding: 4px 7px 7px; border-bottom: 1px solid #e8efec; color: #82918c; font-size: 8px; font-weight: 800; letter-spacing: .11em; }
.property-actions-menu .view-property-btn,
.property-actions-menu .reset-owner-password-btn,
.property-actions-menu .toggle-status-btn { width: 100%; min-height: 36px; display: flex; align-items: center; justify-content: flex-start; gap: 8px; margin: 0; padding: 7px 9px; border: 0; border-radius: 8px; background: transparent; color: #275c4b; font: 750 10.5px Inter,sans-serif; text-align: left; box-shadow: none; }
.property-actions-menu .view-property-btn:hover,
.property-actions-menu .reset-owner-password-btn:hover,
.property-actions-menu .toggle-status-btn:hover { background: #edf6f2; }
.property-actions-menu .toggle-status-btn.is-deactivate { color: #ac3948; }
.property-actions-menu .toggle-status-btn.is-deactivate:hover { background: #fff0f2; }
.property-actions-menu .toggle-status-btn.is-activate { color: #237057; }
.property-actions-menu svg { width: 15px; height: 15px; flex: 0 0 15px; fill: none; stroke: currentColor; stroke-width: 1.8; stroke-linecap: round; stroke-linejoin: round; }
.view-property-btn { min-height: 34px; display: inline-flex; align-items: center; gap: 6px; margin-right: 6px; padding: 0 10px; border: 1px solid #cde0d9; border-radius: 9px; background: #fff; color: #286650; font: 750 9.5px Inter, sans-serif; cursor: pointer; text-decoration: none; }
.view-property-btn:hover { background: #edf6f2; border-color: #a9cdbf; }
.view-property-btn svg { width: 15px; height: 15px; fill: none; stroke: currentColor; stroke-width: 1.8; }
.reset-owner-password-btn { min-height: 34px; display: inline-flex; align-items: center; gap: 6px; margin-right: 6px; padding: 0 10px; border: 1px solid #cde0d9; border-radius: 9px; background: #f4faf7; color: #286650; font: 750 9.5px Inter, sans-serif; cursor: pointer; }
.reset-owner-password-btn:hover { background: #e8f4ef; border-color: #a9cdbf; }
.reset-owner-password-btn svg { width: 14px; height: 14px; fill: none; stroke: currentColor; stroke-width: 1.9; stroke-linecap: round; stroke-linejoin: round; }

.hotel-admin-main .toggle-status-btn {
  min-width: 100px;
  min-height: 34px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  border-radius: 9px;
  font-size: 11.5px;
  font-weight: 800;
}

.hotel-admin-main .toggle-status-btn.is-activate {
  border: 1px solid #236552;
  background: #2b7a66;
  color: #fff;
}

.hotel-admin-main .toggle-status-btn.is-deactivate {
  border: 1px solid #efc7cd;
  background: #fff3f4;
  color: #a43c49;
}

.hotel-admin-main .toggle-status-btn.is-deactivate:hover {
  border-color: #dfaab2;
  background: #fce8eb;
}

.hotel-admin-main .property-actions-menu .toggle-status-btn,
.hotel-admin-main .property-actions-menu .toggle-status-btn.is-activate,
.hotel-admin-main .property-actions-menu .toggle-status-btn.is-deactivate {
  width: 100%;
  min-width: 0;
  min-height: 36px;
  justify-content: flex-start;
  padding: 7px 9px;
  border: 0;
  border-radius: 8px;
  background: transparent;
  box-shadow: none;
  font-size: 10.5px;
}
.hotel-admin-main .property-actions-menu .toggle-status-btn.is-activate:hover { background: #edf6f2; }
.hotel-admin-main .property-actions-menu .toggle-status-btn.is-deactivate:hover { border: 0; background: #fff0f2; }

.hotel-empty-state td {
  padding: 54px 20px !important;
  text-align: center;
}

.hotel-empty-content {
  display: grid;
  justify-items: center;
  gap: 7px;
  color: #77888f;
}

.hotel-empty-icon {
  width: 48px;
  height: 48px;
  display: grid;
  place-items: center;
  border-radius: 14px;
  color: #2b7a66;
  background: #edf7f3;
}

.hotel-empty-icon svg {
  width: 23px;
  height: 23px;
  fill: none;
  stroke: currentColor;
  stroke-width: 1.8;
}

.hotel-empty-content strong {
  color: #344d56;
  font-size: 13px;
}

.hotel-empty-content span {
  font-size: 11.5px;
}

#hotelModal .modal-content {
  max-width: 480px;
  border: 1px solid #d8e6e0;
  border-radius: 16px;
  box-shadow: 0 22px 50px rgba(8, 35, 27, .24);
}

#hotelModal .form-group input,
#hotelModal .form-group select {
  min-height: 42px;
  border-radius: 11px !important;
}

#hotelModal .save-btn {
  min-height: 44px;
  border-radius: 11px;
  background: linear-gradient(135deg, #2b7a66, #236552);
  box-shadow: 0 7px 15px rgba(29, 93, 74, .17);
}

#hotelModal .close-btn {
  width: 32px;
  height: 32px;
  display: grid;
  place-items: center;
  border-radius: 9px;
  background: #f1f6f4;
  transition: background-color .16s ease, color .16s ease;
}

#hotelModal .close-btn:hover {
  color: #1d5d4a;
  background: #e5f1ec;
}

/* Consolidated property directory toolbar */
.hotel-page-header .hotel-header-add {
  flex: 0 0 auto;
  margin-left: auto !important;
}

.hotel-page-header .hotel-header-add + .ap-header-right {
  margin-left: 0;
}

.hotel-table-card-head {
  display: grid;
  grid-template-columns: minmax(260px, 320px) minmax(0, 1fr);
  align-items: end;
  padding: 14px 17px;
  gap: 24px;
}

.hotel-directory-heading {
  min-width: 0;
}

.hotel-admin-main #filterForm.filter-controls {
  width: 100%;
  min-width: 0;
  display: grid;
  grid-template-columns: minmax(230px, 1fr) 62px 150px 130px 120px 102px 72px;
  align-items: flex-end;
  gap: 12px;
  padding: 0;
  border: 0;
  border-radius: 0;
  background: transparent;
  box-shadow: none;
}

.hotel-toolbar-search {
  position: relative;
  width: 100%;
  min-width: 0;
  max-width: none;
}

.hotel-toolbar-search svg {
  position: absolute;
  z-index: 1;
  top: 50%;
  left: 12px;
  width: 17px;
  height: 17px;
  transform: translateY(-50%);
  fill: none;
  stroke: #5e756d;
  stroke-width: 1.8;
  stroke-linecap: round;
  stroke-linejoin: round;
  pointer-events: none;
}

.hotel-admin-main #filterForm .search-input {
  height: 40px !important;
  padding-left: 38px !important;
  border-radius: 10px !important;
  background-image: none !important;
  font-size: 11px !important;
}

.hotel-admin-main #filterForm .filter-group {
  min-width: 0;
  display: flex;
  align-items: center;
  flex-direction: column;
  align-items: stretch;
  gap: 7px;
}

.hotel-admin-main #filterForm .filter-group label {
  align-self: flex-start;
  margin: 0;
  color: #657871;
  font-size: 8px;
  letter-spacing: .045em;
  white-space: nowrap;
}

.hotel-admin-main #filterForm .filter-group { padding-top: 0; }

.hotel-admin-main #filterForm .filter-group input,
.hotel-admin-main #filterForm .filter-group select {
  width: 100%;
  height: 40px;
  padding: 0 28px 0 9px;
  border-radius: 10px;
  font-size: 10px;
}

.hotel-admin-main #filterForm .rows-filter input {
  width: 100%;
  padding: 0 8px;
}

.hotel-admin-main #filterForm #statusSelect,
.hotel-admin-main #filterForm #typeSelect,
.hotel-admin-main #filterForm #sortSelect { width: 100%; }

.hotel-admin-main #filterForm .apply-filter-btn {
  width: 100%;
  min-width: 0;
  height: 40px;
  flex: 0 0 auto;
  border-radius: 10px !important;
  font-size: 10.5px !important;
}

.hotel-admin-main #filterForm .hotel-result-count {
  min-height: 40px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  padding: 6px 9px;
  font-size: 9px;
  white-space: nowrap;
  margin: 0;
}

.hotel-toolbar-search,
.hotel-admin-main #filterForm .apply-filter-btn { margin-bottom: 0; }

@media (max-width: 1450px) {
  .hotel-table-card-head {
    grid-template-columns: 1fr;
    align-items: stretch;
    gap: 12px;
  }

  .hotel-directory-heading {
    min-width: 0;
    flex: none;
  }

  .hotel-admin-main #filterForm.filter-controls {
    grid-template-columns: minmax(240px, 1fr) 62px 150px 130px 120px 102px 72px;
  }
}

@media (max-width: 1250px) {
  .hotel-admin-main #filterForm.filter-controls {
    grid-template-columns: minmax(240px, 1fr) repeat(3, minmax(120px, 1fr));
  }
}

@media (max-width: 1050px) {
  .hotel-admin-main #filterForm.filter-controls {
    grid-template-columns: minmax(240px, 1fr) repeat(3, minmax(120px, 1fr));
  }

  .hotel-admin-main .filter-controls-left {
    grid-template-columns: repeat(2, minmax(140px, 1fr));
  }

  .hotel-admin-main .apply-filter-btn {
    width: 100%;
  }
}

@media (max-width: 760px) {
  .main-content.hotel-admin-main,
  .admin-sidebar.collapsed ~ .main-content.hotel-admin-main {
    flex-basis: 100% !important;
    width: 100% !important;
    max-width: 100%;
    margin-left: 0 !important;
  }

  .hotel-admin-main .dashboard-content {
    padding: 12px !important;
  }

  .hotel-table-card-head {
    align-items: flex-start;
  }

  .hotel-page-header .hotel-header-add {
    width: auto;
    margin-left: 0 !important;
  }

  .hotel-admin-main #filterForm.filter-controls {
    width: 100%;
    grid-template-columns: 1fr;
    align-items: stretch;
  }

  .hotel-toolbar-search,
  .hotel-admin-main #filterForm .filter-group,
  .hotel-admin-main #filterForm .filter-group input,
  .hotel-admin-main #filterForm .filter-group select,
  .hotel-admin-main #filterForm .apply-filter-btn {
    width: 100%;
    max-width: none;
  }

  .hotel-admin-main #filterForm .filter-group label {
    width: 54px;
    flex: 0 0 54px;
  }

  .hotel-admin-main #filterForm .hotel-result-count {
    align-self: flex-start;
  }
}

/* Interactive property statistics */
.property-summary { display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:12px; }
.property-stat-card { position:relative; min-width:0; min-height:104px; display:flex; align-items:center; gap:13px; overflow:hidden; padding:17px; border:1px solid rgba(183,224,209,.3); border-radius:16px; background:radial-gradient(circle at 92% 8%,rgba(144,216,190,.2),transparent 35%),linear-gradient(135deg,#174f40,#236d58); color:#fff; text-align:left; font-family:Inter,sans-serif; cursor:pointer; box-shadow:0 10px 24px rgba(16,73,56,.14); transition:transform .18s,box-shadow .18s,border-color .18s; }
.property-stat-card::after { content:""; position:absolute; right:-24px; bottom:-48px; width:115px; height:115px; border:1px solid rgba(255,255,255,.1); border-radius:50%; }
.property-stat-card:hover { transform:translateY(-3px); border-color:rgba(205,243,229,.65); box-shadow:0 15px 30px rgba(16,73,56,.22); }
.property-stat-card:focus-visible { outline:3px solid rgba(43,122,102,.28); outline-offset:3px; }
.property-stat-card.is-selected { background:radial-gradient(circle at 92% 8%,rgba(175,235,213,.28),transparent 36%),linear-gradient(135deg,#0d3f32,#19715a); border-color:rgba(220,250,239,.82); }
.property-stat-card > span { width:44px; height:44px; flex:0 0 44px; display:grid; place-items:center; border:1px solid rgba(230,255,246,.18); border-radius:13px; background:rgba(235,255,248,.13); }
.property-stat-card svg { width:21px; height:21px; fill:none; stroke:currentColor; stroke-width:1.8; stroke-linecap:round; stroke-linejoin:round; }
.property-stat-card > div { position:relative; z-index:1; min-width:0; }
.property-stat-card small { display:block; margin-bottom:6px; color:#aad7c8; font-size:7.5px; font-weight:800; letter-spacing:.1em; text-transform:uppercase; }
.property-stat-card strong { display:block; color:#fff; font-size:23px; line-height:1; }
.property-stat-card em { display:block; margin-top:6px; color:#d1e8df; font-size:10.5px; font-style:normal; font-weight:650; }
.property-stat-card > i { position:absolute; top:12px; right:13px; padding:4px 7px; border:1px solid rgba(255,255,255,.13); border-radius:999px; background:rgba(255,255,255,.07); color:#cce7dd; font-size:7.5px; font-style:normal; font-weight:750; text-transform:uppercase; }

/* Branded Add Property modal */
body.property-dialog-open { overflow: hidden; }
.property-modal-overlay { z-index: 100400; padding: 18px; background: rgba(3,25,19,.58); backdrop-filter: blur(4px); }
.property-modal, .property-modal * { box-sizing:border-box; }
.property-modal { width:min(900px,calc(100vw - 36px)); max-height:calc(100vh - 30px); overflow:hidden; border:1px solid rgba(255,255,255,.72); border-radius:20px; background:#fff; box-shadow:0 25px 70px rgba(3,35,26,.3); }
.property-modal-header { display:flex; justify-content:space-between; gap:18px; padding:14px 20px; background:radial-gradient(circle at 90% 5%,rgba(112,189,164,.2),transparent 31%),linear-gradient(135deg,#092f26,#10483a); color:#fff; }
.property-modal-header span { color: #a9d8c8; font-size: 8px; font-weight: 800; letter-spacing: .15em; }
.property-modal-header h3 { margin: 5px 0 0; color: #fff; font-size: 19px; }
.property-modal-header p { margin: 5px 0 0; color: #c4dfd6; font-size: 10px; }
.property-modal-close, .property-profile-close { width: 39px; height: 39px; flex: 0 0 39px; display: grid; place-items: center; padding: 0; border: 1px solid rgba(255,255,255,.17); border-radius: 11px; background: rgba(255,255,255,.09); color: #dff3ec; cursor: pointer; }
.property-modal-close svg, .property-profile-close svg { width: 18px; height: 18px; fill: none; stroke: currentColor; stroke-width: 1.8; stroke-linecap: round; }
.property-modal form { padding:14px 20px 0; overflow:hidden; }
.property-form-grid { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:9px 14px; }
.property-form-grid label { min-width: 0; }
.property-form-grid .wide { grid-column: 1/-1; }
.property-form-grid label > span { display: block; margin-bottom: 7px; color: #405b52; font-size: 10px; font-weight: 750; }
.property-form-grid label > span b { color: #b24c4c; }
.property-form-grid input, .property-form-grid select, .property-form-grid textarea { width: 100%; border: 1px solid #d3e1dc; border-radius: 10px; outline: 0; color: #263f37; background: #fff; font: 500 11px Inter,sans-serif; }
.property-form-grid input, .property-form-grid select { height:38px; padding:0 11px; }
.property-form-grid textarea { height:52px; padding:9px 11px; resize:none; }
.property-form-grid input:focus, .property-form-grid select:focus, .property-form-grid textarea:focus { border-color: #6dac96; box-shadow: 0 0 0 3px rgba(72,148,120,.12); }
.property-form-grid small { display: block; margin-top: 5px; color: #8b9994; font-size: 8px; }
.property-password, .property-price-input { position: relative; }
.property-password input { padding-right: 42px; }
.property-password-toggle { position: absolute; top: 4px; right: 4px; width: 34px; height: 34px; display: grid; place-items: center; border: 0; border-radius: 8px; background: transparent; color: #668078; cursor: pointer; }
.property-password-toggle:hover { background: #edf5f2; }
.property-password-toggle svg { width: 17px; height: 17px; fill: none; stroke: currentColor; stroke-width: 1.8; }
.property-password-toggle .eye-off { display: none; }
.property-password-toggle[aria-pressed="true"] .eye-open { display: none; }
.property-password-toggle[aria-pressed="true"] .eye-off { display: block; }
.property-price-input i { position: absolute; top: 50%; left: 12px; transform: translateY(-50%); color: #527168; font-size: 12px; font-style: normal; font-weight: 750; }
.property-price-input input { padding-left: 28px; }
.property-modal form > footer { display:flex; justify-content:flex-end; gap:9px; margin:12px -20px 0; padding:10px 20px; border-top:1px solid #dce8e4; background:#f9fbfa; }
.property-modal form > footer button { min-height: 40px; padding: 0 15px; border-radius: 10px; font: 750 10.5px Inter,sans-serif; cursor: pointer; }
.property-modal-cancel { border: 1px solid #cfded9; background: #fff; color: #556b64; }
.property-modal-submit { display: inline-flex; align-items: center; gap: 7px; border: 0; background: linear-gradient(135deg,#2b7a66,#1c5f4c); color: #fff; }
.property-modal-submit svg { width: 16px; height: 16px; fill: none; stroke: currentColor; stroke-width: 2; }

/* Administrator password reset modal */
.password-reset-modal { width:min(460px,calc(100vw - 36px)); }
.password-reset-modal form { padding:18px 20px 0; }
.password-reset-account { margin:0 0 15px; padding:11px 12px; border:1px solid #dbe8e3; border-radius:11px; background:#f5faf8; }
.password-reset-account small { display:block; margin-bottom:4px; color:#789087; font-size:8px; font-weight:800; letter-spacing:.08em; text-transform:uppercase; }
.password-reset-account strong { display:block; color:#234b3d; font-size:12px; }
.password-reset-fields { display:grid; gap:12px; }
.password-reset-fields label > span { display:block; margin-bottom:7px; color:#405b52; font-size:10px; font-weight:750; }
.password-reset-fields label > span b { color:#b24c4c; }
.password-reset-fields input { width:100%; height:42px; padding:0 42px 0 11px; border:1px solid #d3e1dc; border-radius:10px; outline:0; color:#263f37; font:500 11px Inter,sans-serif; }
.password-reset-fields input:focus { border-color:#6dac96; box-shadow:0 0 0 3px rgba(72,148,120,.12); }
.password-reset-fields small { display:block; margin-top:5px; color:#82928c; font-size:8.5px; }
.password-reset-error { min-height:15px; margin-top:9px; color:#b13e4d; font-size:9px; font-weight:700; }
.password-reset-modal .property-modal-submit:disabled { cursor:wait; opacity:.7; }

/* Property details drawer */
.property-profile-overlay { position: fixed; inset: 0; z-index: 100400; display: flex; align-items: stretch; justify-content: flex-end; visibility: hidden; opacity: 0; pointer-events: none; background: rgba(3,25,19,.58); backdrop-filter: blur(4px); transition: opacity .22s,visibility .22s; }
.property-profile-overlay.show { visibility: visible; opacity: 1; pointer-events: auto; }
.property-profile-drawer { width: min(540px,94vw); height: 100vh; display: flex; flex-direction: column; overflow: hidden; border-radius: 22px 0 0 22px; background: #f2f7f5; box-shadow: -24px 0 65px rgba(3,35,26,.3); transform: translateX(102%); transition: transform .28s cubic-bezier(.22,.8,.3,1); }
.property-profile-overlay.show .property-profile-drawer { transform: none; }
.property-profile-header { min-height: 78px; display: flex; align-items: center; justify-content: space-between; padding: 14px 18px; background: radial-gradient(circle at 88% 10%,rgba(112,189,164,.18),transparent 29%),linear-gradient(135deg,#092f26,#10483a); color:#fff; }
.property-profile-brand { display:flex; align-items:center; gap:11px; }
.property-profile-brand img { width:45px; height:45px; padding:3px; object-fit:contain; border:1px solid rgba(255,255,255,.18); border-radius:13px; background:rgba(255,255,255,.1); }
.property-profile-brand span { display:block; margin-bottom:3px; color:#a9d8c8; font-size:8px; font-weight:800; letter-spacing:.15em; }
.property-profile-brand h3 { margin:0; color:#fff; font-size:17px; }
.property-profile-body { flex:1; min-height:0; overflow-y:auto; padding:17px; }
.property-profile-body svg { width:18px; height:18px; fill:none; stroke:currentColor; stroke-width:1.8; stroke-linecap:round; stroke-linejoin:round; }
.pp-hero { display:flex; align-items:center; gap:15px; margin-bottom:12px; padding:17px; border-radius:16px; background:linear-gradient(135deg,#12513f,#2b7a66); color:#fff; box-shadow:0 10px 23px rgba(17,78,60,.19); }
.pp-hero-icon { width:70px; height:70px; flex:0 0 70px; display:grid; place-items:center; border:2px solid rgba(255,255,255,.55); border-radius:20px; background:rgba(255,255,255,.12); }
.pp-hero-icon svg { width:32px; height:32px; }
.pp-hero small { color:#bce1d4; font-size:8px; font-weight:850; letter-spacing:.13em; }
.pp-hero h2 { margin:4px 0; color:#fff; font-size:19px; }
.pp-hero p { margin:0 0 8px; color:#c7e4da; font-size:10px; }
.pp-hero b { padding:5px 8px; border-radius:999px; background:rgba(255,255,255,.13); font-size:8px; }
.pp-stats { display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:7px; margin-bottom:12px; }
.pp-stats div, .pp-section { border:1px solid #d9e7e2; background:#fff; box-shadow:0 4px 14px rgba(20,68,53,.045); }
.pp-stats div { padding:11px; border-radius:12px; }
.pp-stats strong { display:block; color:#185744; font-size:15px; }
.pp-stats span { display:block; margin-top:4px; color:#7b8e87; font-size:8px; font-weight:700; text-transform:uppercase; }
.pp-section { margin-bottom:12px; padding:15px; border-radius:15px; }
.pp-section h3 { margin:0 0 4px; color:#1c4036; font-size:13px; }
.pp-section > p { margin:0 0 12px; color:#809089; font-size:9px; }
.pp-info { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:8px; }
.pp-info div { min-width:0; padding:10px; border:1px solid #e4ece9; border-radius:10px; background:#f7faf9; }
.pp-info small { display:block; margin-bottom:3px; color:#7e8f89; font-size:8px; font-weight:800; text-transform:uppercase; }
.pp-info strong { display:block; overflow-wrap:anywhere; color:#2b4a40; font-size:10.5px; }
.pp-description { color:#435e55!important; font-size:10.5px!important; line-height:1.55; }
.pp-email { min-height:39px; display:flex; align-items:center; gap:8px; margin-top:10px; padding:0 12px; border-radius:10px; background:linear-gradient(135deg,#2b7a66,#1c5f4c); color:#fff; font-size:10.5px; font-weight:800; text-decoration:none; }
.property-profile-footer { padding:13px 18px; border-top:1px solid #d7e5e0; background:#fff; }
.property-profile-close-btn { width:100%; height:40px; border:0; border-radius:10px; background:linear-gradient(135deg,#2b7a66,#1e6551); color:#fff; font:800 11px Inter,sans-serif; cursor:pointer; }
@media(max-width:1100px){.property-summary{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media(max-width:620px){.property-summary{grid-template-columns:1fr}.property-modal-overlay{padding:6px}.property-modal{width:calc(100vw - 12px);max-height:calc(100vh - 12px);border-radius:14px}.property-form-grid{grid-template-columns:repeat(2,minmax(0,1fr));gap:7px}.property-form-grid label > span{font-size:8px}.property-modal-header p,.property-form-grid small{display:none}.property-profile-drawer{width:100vw;border-radius:0}.pp-info,.pp-stats{grid-template-columns:1fr}}
</style>
</head>
<body>
<div class="admin-container">
  <?php include 'admin_sidebar.php'; ?>

  <main class="main-content hotel-admin-main">

    <header class="admin-header admin-page-header hotel-page-header">
      <div class="admin-header-left admin-page-title">
        <span class="admin-page-title-icon hotel-page-title-icon" aria-hidden="true">
          <svg viewBox="0 0 24 24"><path d="M4 20V8l8-4 8 4v12"/><path d="M8 20v-7h8v7M3 20h18"/></svg>
        </span>
        <div class="admin-page-title-copy hotel-page-title-copy">
          <h2>Hotels &amp; Resorts</h2>
          <p class="admin-header-subtitle">Manage property accounts, status, and owner access</p>
        </div>
      </div>
      <button type="button" class="add-hotel-btn-inline hotel-header-add" id="openHotelModal">
        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>
        Add Property
      </button>
    </header>

    <section class="dashboard-content">
      <section class="property-summary" aria-label="Property account summary">
        <button type="button" class="property-stat-card is-selected" data-property-filter="all" aria-pressed="true"><span><svg viewBox="0 0 24 24"><path d="M4 20V8l8-4 8 4v12M8 20v-7h8v7M3 20h18"/></svg></span><div><small>Property network</small><strong><?= number_format((int)$propertySummary['total']) ?></strong><em>All properties</em></div><i>View all</i></button>
        <button type="button" class="property-stat-card" data-property-filter="active" aria-pressed="false"><span><svg viewBox="0 0 24 24"><path d="m5 12 4 4L19 6"/></svg></span><div><small>Live availability</small><strong><?= number_format((int)$propertySummary['active']) ?></strong><em>Active listings</em></div><i>Filter</i></button>
        <button type="button" class="property-stat-card" data-property-filter="hotel" aria-pressed="false"><span><svg viewBox="0 0 24 24"><path d="M5 21V4h14v17M8 8h2M14 8h2M8 12h2M14 12h2M10 21v-5h4v5"/></svg></span><div><small>Accommodation type</small><strong><?= number_format((int)$propertySummary['hotels']) ?></strong><em>Hotels</em></div><i>Filter</i></button>
        <button type="button" class="property-stat-card" data-property-filter="resort" aria-pressed="false"><span><svg viewBox="0 0 24 24"><path d="M3 20h18M5 20V9l7-5 7 5v11M9 20v-6h6v6"/></svg></span><div><small>Accommodation type</small><strong><?= number_format((int)$propertySummary['resorts']) ?></strong><em>Resorts</em></div><i>Filter</i></button>
      </section>
      <div class="hotel-table-card">
        <div class="hotel-table-card-head">
          <div class="hotel-directory-heading">
            <h3>Hotel and resort directory</h3>
            <p>Property details and owner login access</p>
          </div>
          <form method="GET" id="filterForm" class="filter-controls">
            <label class="hotel-toolbar-search">
              <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-4-4"/></svg>
              <input type="search" name="search" placeholder="Search properties..." value="<?= htmlspecialchars($search) ?>" class="search-input" autocomplete="off" />
            </label>
            <div class="filter-group rows-filter"><label for="rowsSelect">Rows</label><input type="number" id="rowsSelect" name="rows" min="1" max="300" value="<?= htmlspecialchars((string)$rowsPerPage) ?>" class="rows-input" /></div>
            <div class="filter-group"><label for="statusSelect">Status</label><select id="statusSelect" name="status"><option value="all" <?= $statusFilter === 'all' ? 'selected' : '' ?>>All statuses</option><option value="active" <?= $statusFilter === 'active' ? 'selected' : '' ?>>Active</option><option value="inactive" <?= $statusFilter === 'inactive' ? 'selected' : '' ?>>Inactive</option></select></div>
            <div class="filter-group"><label for="typeSelect">Type</label><select id="typeSelect" name="type"><option value="all" <?= $typeFilter === 'all' ? 'selected' : '' ?>>All types</option><option value="hotel" <?= $typeFilter === 'hotel' ? 'selected' : '' ?>>Hotels</option><option value="resort" <?= $typeFilter === 'resort' ? 'selected' : '' ?>>Resorts</option></select></div>
            <div class="filter-group"><label for="sortSelect">Sort</label><select id="sortSelect" name="sort"><option value="id_desc" <?= $sortBy === 'id_desc' ? 'selected' : '' ?>>Latest</option><option value="id_asc" <?= $sortBy === 'id_asc' ? 'selected' : '' ?>>Oldest</option><option value="name_asc" <?= $sortBy === 'name_asc' ? 'selected' : '' ?>>A–Z</option><option value="name_desc" <?= $sortBy === 'name_desc' ? 'selected' : '' ?>>Z–A</option></select></div>
            <button type="button" class="apply-filter-btn" id="applyFilterBtn">Apply</button>
            <span class="hotel-result-count"><?= number_format(count($hotels)) ?> shown</span>
          </form>
        </div>
        <div class="hotels-table-wrap">

        <table class="hotels-table">

          <thead>
            <tr>
              <th>HOTEL/RESORT NAME</th>
              <th>TYPE</th>
              <th>INVENTORY</th>
              <th>BOOKINGS</th>
              <th>STATUS</th>
              <th>OWNER ACCESS</th>
              <th>ACTIONS</th>
            </tr>
          </thead>

          <tbody id="hotelTableBody">

            <?php if (!$hotels): ?>

              <tr class="hotel-empty-state">
                <td colspan="7">
                  <div class="hotel-empty-content">
                    <span class="hotel-empty-icon"><svg viewBox="0 0 24 24"><path d="M4 20V8l8-4 8 4v12M8 20v-7h8v7"/></svg></span>
                    <strong>No properties found</strong>
                    <span>Try changing your search or filters.</span>
                  </div>
                </td>
              </tr>

            <?php else: ?>

              <?php foreach ($hotels as $hotel): ?>

                <tr>

                  <td>
                    <div class="property-cell">
                      <span class="property-avatar <?= strtolower((string)$hotel['type']) ?>" aria-hidden="true">
                        <svg viewBox="0 0 24 24"><path d="M4 20V8l8-4 8 4v12M8 20v-7h8v7"/></svg>
                      </span>
                      <span class="property-meta">
                        <strong><?= htmlspecialchars((string)$hotel['name']) ?></strong>
                        <small><?= htmlspecialchars((string)($hotel['island'] ?? 'Location not set')) ?></small>
                      </span>
                    </div>
                  </td>

                  <td>
                    <span class="capsule <?= strtolower((string)$hotel['type']) ?>">
                      <?= htmlspecialchars(ucfirst((string)$hotel['type'])) ?>
                    </span>
                  </td>

                  <td>
                    <div class="property-inventory">
                      <strong><?= number_format((int)$hotel['active_room_count']) ?> active room<?= (int)$hotel['active_room_count'] === 1 ? '' : 's' ?></strong>
                      <span><?= number_format((int)$hotel['available_units']) ?> unit<?= (int)$hotel['available_units'] === 1 ? '' : 's' ?> available</span>
                    </div>
                  </td>

                  <td>
                    <div class="property-bookings">
                      <strong><?= number_format((int)$hotel['booking_count']) ?></strong>
                      <span>total booking<?= (int)$hotel['booking_count'] === 1 ? '' : 's' ?></span>
                    </div>
                  </td>

                  <td>
                    <span class="capsule <?= strtolower((string)$hotel['status']) ?>">
                      <?= htmlspecialchars(ucfirst((string)$hotel['status'])) ?>
                    </span>
                  </td>

                  <td class="hotel-login-cell" title="<?= htmlspecialchars((string)($hotel['username'] ?? 'N/A')) ?>">
                    <strong><?= htmlspecialchars((string)($hotel['administrator_name'] ?: 'Property administrator')) ?></strong>
                    <span><?= htmlspecialchars((string)($hotel['username'] ?? 'N/A')) ?></span>
                  </td>

                  <td class="hotel-actions-cell">
                    <div class="property-row-actions">
                      <button
                        type="button"
                        class="property-actions-toggle"
                        aria-haspopup="menu"
                        aria-expanded="false"
                      >
                        Actions <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m7 10 5 5 5-5"/></svg>
                      </button>
                      <div class="property-actions-menu" role="menu">
                        <a class="view-property-btn" role="menuitem" href="hotel_details.php?id=<?= (int)$hotel['hotel_resort_id'] ?>&amp;source=result" target="_blank" rel="noopener noreferrer" aria-label="Open <?= htmlspecialchars((string)$hotel['name']) ?> live property page in a new tab">
                          <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M2.5 12s3.5-6 9.5-6 9.5 6 9.5 6-3.5 6-9.5 6-9.5-6-9.5-6Z"/><circle cx="12" cy="12" r="2.5"/></svg>View property
                        </a>
                        <?php if (trim((string)($hotel['username'] ?? '')) !== ''): ?>
                          <button type="button" role="menuitem" class="reset-owner-password-btn" data-hotel-id="<?= (int)$hotel['hotel_resort_id'] ?>" data-property-name="<?= htmlspecialchars((string)$hotel['name'], ENT_QUOTES, 'UTF-8') ?>">
                            <svg viewBox="0 0 24 24" aria-hidden="true"><rect x="5" y="10" width="14" height="10" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3M12 14v2"/></svg>Reset password
                          </button>
                        <?php endif; ?>
                        <button type="button" role="menuitem" class="toggle-status-btn <?= $hotel['status'] === 'active' ? 'is-deactivate' : 'is-activate' ?>" data-hotel-id="<?= (int)$hotel['hotel_resort_id'] ?>" data-current-status="<?= htmlspecialchars((string)$hotel['status']) ?>">
                          <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 7v5M12 16h.01"/></svg><?= $hotel['status'] === 'active' ? 'Deactivate' : 'Activate' ?>
                        </button>
                      </div>
                    </div>
                  </td>

                </tr>

              <?php endforeach; ?>

            <?php endif; ?>

          </tbody>

        </table>

        </div>
      </div>

    </section>

  </main>

</div>

<!-- ADD PROPERTY MODAL -->
<div class="modal property-modal-overlay" id="hotelModal" aria-hidden="true">
  <section class="property-modal" role="dialog" aria-modal="true" aria-labelledby="hotelModalTitle">
    <header class="property-modal-header">
      <div><span>NEW ACCOMMODATION PROVIDER</span><h3 id="hotelModalTitle">Add Hotel or Resort</h3><p>Create the property record and secure owner-portal access.</p></div>
      <button type="button" class="property-modal-close" id="closeHotelModal" aria-label="Close add property form"><svg viewBox="0 0 24 24"><path d="m6 6 12 12M18 6 6 18"/></svg></button>
    </header>
    <form method="POST" action="adhotelresorts.php" autocomplete="off" id="addPropertyForm">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($adminHotelCsrf, ENT_QUOTES, 'UTF-8') ?>">
      <div class="property-form-grid">
        <label class="wide"><span>Hotel or resort name <b>*</b></span><input type="text" id="property_name" name="property_name" maxlength="150" required placeholder="e.g. Mercedes Bay Resort"></label>
        <label><span>Property type <b>*</b></span><select id="property_type" name="property_type" required><option value="">Select type</option><option value="hotel">Hotel</option><option value="resort">Resort</option></select></label>
        <label><span>Island or location <b>*</b></span><select id="property_location" name="property_location" required><option value="">Select location</option><option value="Apuao">Apuao</option><option value="Apuao Grande">Apuao Grande</option><option value="Cayucyucan">Cayucyucan</option><option value="Canimog">Canimog</option><option value="Caringo">Caringo</option><option value="Malasugui">Malasugui</option><option value="Quinapaguian">Quinapaguian</option></select></label>
        <label><span>Administrator name <b>*</b></span><input type="text" name="administrator_name" maxlength="190" required placeholder="Full name of account owner"></label>
        <label><span>Login email <b>*</b></span><input type="email" id="email" name="email" maxlength="190" required placeholder="owner@example.com"><small>Used as the username for the property portal.</small></label>
        <label class="wide"><span>Short property description</span><textarea name="description" maxlength="600" rows="3" placeholder="Briefly describe the property, setting, or guest experience."></textarea></label>
        <label><span>Temporary password <b>*</b></span><div class="property-password"><input type="password" name="password" minlength="8" required placeholder="At least 8 characters"><button type="button" class="property-password-toggle" aria-label="Show password" aria-pressed="false"><svg class="eye-open" viewBox="0 0 24 24"><path d="M2.5 12s3.5-6 9.5-6 9.5 6 9.5 6-3.5 6-9.5 6-9.5-6-9.5-6Z"/><circle cx="12" cy="12" r="2.5"/></svg><svg class="eye-off" viewBox="0 0 24 24"><path d="M3 3l18 18"/><path d="M10.6 6.2A10.7 10.7 0 0 1 12 6c6 0 9.5 6 9.5 6s-.8 1.4-2.3 2.9M6.1 6.1C3.8 7.8 2.5 12 2.5 12s3.5 6 9.5 6a10.7 10.7 0 0 0 3.4-.5"/><path d="M9.9 9.9a3 3 0 0 0 4.2 4.2"/></svg></button></div></label>
        <label><span>Confirm password <b>*</b></span><div class="property-password"><input type="password" name="confirm_password" minlength="8" required placeholder="Repeat temporary password"><button type="button" class="property-password-toggle" aria-label="Show password" aria-pressed="false"><svg class="eye-open" viewBox="0 0 24 24"><path d="M2.5 12s3.5-6 9.5-6 9.5 6 9.5 6-3.5 6-9.5 6-9.5-6-9.5-6Z"/><circle cx="12" cy="12" r="2.5"/></svg><svg class="eye-off" viewBox="0 0 24 24"><path d="M3 3l18 18"/><path d="M10.6 6.2A10.7 10.7 0 0 1 12 6c6 0 9.5 6 9.5 6s-.8 1.4-2.3 2.9M6.1 6.1C3.8 7.8 2.5 12 2.5 12s3.5 6 9.5 6a10.7 10.7 0 0 0 3.4-.5"/><path d="M9.9 9.9a3 3 0 0 0 4.2 4.2"/></svg></button></div></label>
      </div>
      <footer><button type="button" class="property-modal-cancel" id="cancelHotelModal">Cancel</button><button type="submit" name="add_hotel_account" value="1" class="property-modal-submit"><svg viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg>Create Property</button></footer>
    </form>
  </section>
</div>

<!-- RESET HOTEL OWNER PASSWORD MODAL -->
<div class="modal property-modal-overlay" id="resetHotelPasswordModal" aria-hidden="true">
  <section class="property-modal password-reset-modal" role="dialog" aria-modal="true" aria-labelledby="resetHotelPasswordTitle">
    <header class="property-modal-header">
      <div><span>OWNER PORTAL SECURITY</span><h3 id="resetHotelPasswordTitle">Reset Hotel Owner Password</h3><p>Set a new login password for this property's owner account.</p></div>
      <button type="button" class="property-modal-close" id="closeResetPasswordModal" aria-label="Close password reset form"><svg viewBox="0 0 24 24"><path d="m6 6 12 12M18 6 6 18"/></svg></button>
    </header>
    <form method="POST" action="adhotelresorts.php" autocomplete="off" id="resetHotelPasswordForm">
      <input type="hidden" name="reset_hotel_password" value="1">
      <input type="hidden" name="hotel_id" id="resetHotelId" value="">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($hotelAccountPasswordCsrf, ENT_QUOTES, 'UTF-8') ?>">
      <div class="password-reset-account"><small>Selected property</small><strong id="resetHotelPropertyName">Hotel or resort</strong></div>
      <div class="password-reset-fields">
        <label><span>New password <b>*</b></span><div class="property-password"><input type="password" name="new_password" minlength="8" required autocomplete="new-password" placeholder="At least 8 characters"><button type="button" class="property-password-toggle" aria-label="Show password" aria-pressed="false"><svg class="eye-open" viewBox="0 0 24 24"><path d="M2.5 12s3.5-6 9.5-6 9.5 6 9.5 6-3.5 6-9.5 6-9.5-6-9.5-6Z"/><circle cx="12" cy="12" r="2.5"/></svg><svg class="eye-off" viewBox="0 0 24 24"><path d="M3 3l18 18"/><path d="M10.6 6.2A10.7 10.7 0 0 1 12 6c6 0 9.5 6 9.5 6s-.8 1.4-2.3 2.9M6.1 6.1C3.8 7.8 2.5 12 2.5 12s3.5 6 9.5 6a10.7 10.7 0 0 0 3.4-.5"/><path d="M9.9 9.9a3 3 0 0 0 4.2 4.2"/></svg></button></div><small>Use at least 8 characters.</small></label>
        <label><span>Confirm new password <b>*</b></span><div class="property-password"><input type="password" name="confirm_password" minlength="8" required autocomplete="new-password" placeholder="Repeat the new password"><button type="button" class="property-password-toggle" aria-label="Show password" aria-pressed="false"><svg class="eye-open" viewBox="0 0 24 24"><path d="M2.5 12s3.5-6 9.5-6 9.5 6 9.5 6-3.5 6-9.5 6-9.5-6-9.5-6Z"/><circle cx="12" cy="12" r="2.5"/></svg><svg class="eye-off" viewBox="0 0 24 24"><path d="M3 3l18 18"/><path d="M10.6 6.2A10.7 10.7 0 0 1 12 6c6 0 9.5 6 9.5 6s-.8 1.4-2.3 2.9M6.1 6.1C3.8 7.8 2.5 12 2.5 12s3.5 6 9.5 6a10.7 10.7 0 0 0 3.4-.5"/><path d="M9.9 9.9a3 3 0 0 0 4.2 4.2"/></svg></button></div></label>
      </div>
      <div class="password-reset-error" id="resetHotelPasswordError" role="alert"></div>
      <footer><button type="button" class="property-modal-cancel" id="cancelResetPasswordModal">Cancel</button><button type="submit" class="property-modal-submit"><svg viewBox="0 0 24 24"><rect x="5" y="10" width="14" height="10" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/></svg>Reset Password</button></footer>
    </form>
  </section>
</div>

<!-- PROPERTY DETAILS DRAWER -->
<div class="property-profile-overlay" id="propertyProfileDrawer" aria-hidden="true">
  <aside class="property-profile-drawer" role="dialog" aria-modal="true" aria-labelledby="propertyProfileTitle">
    <header class="property-profile-header"><div class="property-profile-brand"><img src="img/newlogo.png" alt=""><div><span>ITOUR MERCEDES</span><h3 id="propertyProfileTitle">Property Details</h3></div></div><button type="button" class="property-profile-close" aria-label="Close property details"><svg viewBox="0 0 24 24"><path d="m6 6 12 12M18 6 6 18"/></svg></button></header>
    <div class="property-profile-body" id="propertyProfileContent"></div>
    <footer class="property-profile-footer"><button type="button" class="property-profile-close-btn">Close Details</button></footer>
  </aside>
</div>

<script>
document.addEventListener("DOMContentLoaded", () => {

const hotelModal = document.getElementById('hotelModal');
const openHotelModalBtn = document.getElementById('openHotelModal');
const closeHotelModalBtn = document.getElementById('closeHotelModal');
const cancelHotelModalBtn = document.getElementById('cancelHotelModal');
const form = document.querySelector('#hotelModal form');
const resetPasswordModal = document.getElementById('resetHotelPasswordModal');
const resetPasswordForm = document.getElementById('resetHotelPasswordForm');
const resetHotelId = document.getElementById('resetHotelId');
const resetHotelPropertyName = document.getElementById('resetHotelPropertyName');
const resetPasswordError = document.getElementById('resetHotelPasswordError');
const propertyDrawer = document.getElementById('propertyProfileDrawer');
let lastPropertyTrigger = null;
let lastResetPasswordTrigger = null;

const tbody = document.getElementById('hotelTableBody');
const searchInput = document.querySelector('.search-input');
const statusSelect = document.getElementById('statusSelect');
const typeSelect = document.getElementById('typeSelect');
const sortSelect = document.getElementById('sortSelect');
const rowsInput = document.getElementById('rowsSelect');
const resultCount = document.querySelector('.hotel-result-count');
const filterPanel = document.querySelector('.filter-controls');

let timer = null;

const escapeHtml = (value) => String(value ?? '').replace(/[&<>"']/g, character => ({
  '&': '&amp;',
  '<': '&lt;',
  '>': '&gt;',
  '"': '&quot;',
  "'": '&#039;'
})[character]);

const propertyIcon = `
  <svg viewBox="0 0 24 24" aria-hidden="true">
    <path d="M4 20V8l8-4 8 4v12M8 20v-7h8v7"></path>
  </svg>
`;

const closePropertyActionMenus = () => {
  document.querySelectorAll('.property-row-actions.open').forEach(actions => {
    actions.classList.remove('open');
    actions.querySelector('.property-actions-toggle')?.setAttribute('aria-expanded', 'false');
    const menu = actions.querySelector('.property-actions-menu');
    if (menu) {
      menu.style.removeProperty('top');
      menu.style.removeProperty('left');
    }
  });
};

const openPropertyActionMenu = actions => {
  const toggle = actions.querySelector('.property-actions-toggle');
  const menu = actions.querySelector('.property-actions-menu');
  if (!toggle || !menu) return;
  actions.classList.add('open');
  toggle.setAttribute('aria-expanded', 'true');
  const buttonRect = toggle.getBoundingClientRect();
  const menuRect = menu.getBoundingClientRect();
  const edge = 10;
  const left = Math.max(edge, Math.min(buttonRect.right - menuRect.width, window.innerWidth - menuRect.width - edge));
  const roomBelow = window.innerHeight - buttonRect.bottom;
  const top = roomBelow >= menuRect.height + 8
    ? buttonRect.bottom + 6
    : Math.max(edge, buttonRect.top - menuRect.height - 6);
  menu.style.left = `${left}px`;
  menu.style.top = `${top}px`;
};

document.addEventListener('click', event => {
  const toggle = event.target.closest('.property-actions-toggle');
  if (toggle) {
    const actions = toggle.closest('.property-row-actions');
    const wasOpen = actions?.classList.contains('open');
    closePropertyActionMenus();
    if (actions && !wasOpen) openPropertyActionMenu(actions);
    return;
  }
  if (!event.target.closest('.property-actions-menu')) closePropertyActionMenus();
  else if (event.target.closest('a, button')) closePropertyActionMenus();
});

window.addEventListener('resize', closePropertyActionMenus);
window.addEventListener('scroll', closePropertyActionMenus, true);

const closePropertyModal = () => {
  if (!hotelModal) return;
  hotelModal.classList.remove('open');
  hotelModal.setAttribute('aria-hidden', 'true');
  document.body.classList.remove('property-dialog-open');
  hotelModal.querySelectorAll('.property-password-toggle').forEach(button => {
    button.previousElementSibling.type = 'password';
    button.setAttribute('aria-label', 'Show password');
    button.setAttribute('aria-pressed', 'false');
  });
  openHotelModalBtn?.focus();
};

const closeResetPasswordModal = () => {
  if (!resetPasswordModal) return;
  resetPasswordModal.classList.remove('open');
  resetPasswordModal.setAttribute('aria-hidden', 'true');
  document.body.classList.remove('property-dialog-open');
  resetPasswordForm?.reset();
  if (resetPasswordError) resetPasswordError.textContent = '';
  resetPasswordModal.querySelectorAll('.property-password-toggle').forEach(button => {
    button.previousElementSibling.type = 'password';
    button.setAttribute('aria-label', 'Show password');
    button.setAttribute('aria-pressed', 'false');
  });
  lastResetPasswordTrigger?.focus();
};

const openResetPasswordModal = (button) => {
  if (!resetPasswordModal || !resetPasswordForm) return;
  lastResetPasswordTrigger = button;
  resetPasswordForm.reset();
  resetHotelId.value = button.dataset.hotelId || '';
  resetHotelPropertyName.textContent = button.dataset.propertyName || 'Hotel or resort';
  if (resetPasswordError) resetPasswordError.textContent = '';
  resetPasswordModal.classList.add('open');
  resetPasswordModal.setAttribute('aria-hidden', 'false');
  document.body.classList.add('property-dialog-open');
  setTimeout(() => resetPasswordForm.elements.new_password?.focus(), 50);
};

// prevent null crash
if (openHotelModalBtn && hotelModal) {
  openHotelModalBtn.addEventListener('click', () => {
    hotelModal.classList.add('open');
    hotelModal.setAttribute('aria-hidden', 'false');
    document.body.classList.add('property-dialog-open');
    setTimeout(() => document.getElementById('property_name')?.focus(), 50);
  });
}

if (closeHotelModalBtn && hotelModal) closeHotelModalBtn.addEventListener('click', closePropertyModal);
if (cancelHotelModalBtn && hotelModal) cancelHotelModalBtn.addEventListener('click', closePropertyModal);

if (hotelModal) {
  hotelModal.addEventListener('click', (event) => {
    if (event.target === hotelModal) {
      closePropertyModal();
    }
  });
}

document.getElementById('closeResetPasswordModal')?.addEventListener('click', closeResetPasswordModal);
document.getElementById('cancelResetPasswordModal')?.addEventListener('click', closeResetPasswordModal);
resetPasswordModal?.addEventListener('click', event => {
  if (event.target === resetPasswordModal) closeResetPasswordModal();
});

function loadHotels() {
  closePropertyActionMenus();
  const params = new URLSearchParams({
    ajax: 'fetch',
    search: searchInput?.value || '',
    status: statusSelect?.value || 'all',
    type: typeSelect?.value || 'all',
    sort: sortSelect?.value || 'id_desc',
    rows: rowsInput?.value || 25
  });

  if (filterPanel) filterPanel.classList.add('is-loading');
  if (applyBtn) applyBtn.disabled = true;

  fetch('adhotelresorts.php?' + params)
    .then(async r => {
      const text = await r.text();
      try {
        return JSON.parse(text);
      } catch (err) {
        console.error("JSON PARSE ERROR:", err);
        console.error("Response was:", text);
        throw err;
      }
    })
    .then(data => {
      if (!tbody) return;

      tbody.innerHTML = '';
      if (resultCount) {
        resultCount.textContent = `${data.length.toLocaleString()} shown`;
      }

      if (!data.length) {
            tbody.innerHTML = `
              <tr class="hotel-empty-state">
                <td colspan="7">
              <div class="hotel-empty-content">
                <span class="hotel-empty-icon">${propertyIcon}</span>
                <strong>No properties found</strong>
                <span>Try changing your search or filters.</span>
              </div>
            </td>
          </tr>`;
        return;
      }

      data.forEach(h => {
        const type = String(h.type || 'hotel').toLowerCase();
        const typeClass = type === 'resort' ? 'resort' : 'hotel';
        const status = String(h.status || 'inactive').toLowerCase();
        const isActive = status === 'active';
        const name = escapeHtml(h.name || 'Unnamed property');
        const island = escapeHtml(h.island || 'Location not set');
        const username = escapeHtml(h.username || 'N/A');

        tbody.innerHTML += `
          <tr>
            <td>
              <div class="property-cell">
                <span class="property-avatar ${typeClass}">${propertyIcon}</span>
                <span class="property-meta">
                  <strong>${name}</strong>
                  <small>${island}</small>
                </span>
              </div>
            </td>
            <td><span class="capsule ${typeClass}">${typeClass.charAt(0).toUpperCase() + typeClass.slice(1)}</span></td>
            <td><div class="property-inventory"><strong>${Number(h.active_room_count || 0)} active room${Number(h.active_room_count || 0) === 1 ? '' : 's'}</strong><span>${Number(h.available_units || 0)} unit${Number(h.available_units || 0) === 1 ? '' : 's'} available</span></div></td>
            <td><div class="property-bookings"><strong>${Number(h.booking_count || 0).toLocaleString()}</strong><span>total booking${Number(h.booking_count || 0) === 1 ? '' : 's'}</span></div></td>
            <td><span class="capsule ${isActive ? 'active' : 'inactive'}">${isActive ? 'Active' : 'Inactive'}</span></td>
            <td class="hotel-login-cell" title="${username}"><strong>${escapeHtml(h.administrator_name || 'Property administrator')}</strong><span>${username}</span></td>
            <td class="hotel-actions-cell">
              <div class="property-row-actions">
                <button type="button" class="property-actions-toggle" aria-haspopup="menu" aria-expanded="false">Actions <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m7 10 5 5 5-5"></path></svg></button>
                <div class="property-actions-menu" role="menu">
                  <a class="view-property-btn" role="menuitem" href="hotel_details.php?id=${Number(h.hotel_resort_id) || 0}&source=result" target="_blank" rel="noopener noreferrer" aria-label="Open ${name} live property page in a new tab">${propertyIcon}<span>View property</span></a>
                  ${h.username ? `<button type="button" role="menuitem" class="reset-owner-password-btn" data-hotel-id="${Number(h.hotel_resort_id) || 0}" data-property-name="${name}"><svg viewBox="0 0 24 24" aria-hidden="true"><rect x="5" y="10" width="14" height="10" rx="2"></rect><path d="M8 10V7a4 4 0 0 1 8 0v3M12 14v2"></path></svg>Reset password</button>` : ''}
                  <button type="button" role="menuitem" class="toggle-status-btn ${isActive ? 'is-deactivate' : 'is-activate'}" data-hotel-id="${Number(h.hotel_resort_id) || 0}" data-current-status="${isActive ? 'active' : 'inactive'}"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"></circle><path d="M12 7v5M12 16h.01"></path></svg>${isActive ? 'Deactivate' : 'Activate'}</button>
                </div>
              </div>
            </td>
          </tr>
        `;
      });
    })
    .catch(err => {
      console.error("FETCH FAILED:", err);
      if (tbody) {
        tbody.innerHTML = `
          <tr class="hotel-empty-state">
            <td colspan="6">
              <div class="hotel-empty-content">
                <strong>Unable to load properties</strong>
                <span>Please refresh the page and try again.</span>
              </div>
            </td>
          </tr>`;
      }
    })
    .finally(() => {
      if (filterPanel) filterPanel.classList.remove('is-loading');
      if (applyBtn) applyBtn.disabled = false;
    });
}

// search
if (searchInput) {
  searchInput.addEventListener('input', () => {
    clearTimeout(timer);
    timer = setTimeout(loadHotels, 300);
  });
}

// apply filter
const applyBtn = document.getElementById('applyFilterBtn');
if (applyBtn) {
  applyBtn.addEventListener('click', loadHotels);
}

document.querySelectorAll('.property-stat-card').forEach(card => card.addEventListener('click', () => {
  const filter = card.dataset.propertyFilter || 'all';
  if (filter === 'active') {
    statusSelect.value = 'active';
    typeSelect.value = 'all';
  } else if (filter === 'hotel' || filter === 'resort') {
    statusSelect.value = 'all';
    typeSelect.value = filter;
  } else {
    statusSelect.value = 'all';
    typeSelect.value = 'all';
  }
  document.querySelectorAll('.property-stat-card').forEach(item => {
    const selected = item === card;
    item.classList.toggle('is-selected', selected);
    item.setAttribute('aria-pressed', selected ? 'true' : 'false');
  });
  loadHotels();
  document.querySelector('.hotel-table-card')?.scrollIntoView({behavior:'smooth',block:'start'});
}));

document.querySelectorAll('.property-password-toggle').forEach(button => button.addEventListener('click', () => {
  const input = button.previousElementSibling;
  const show = input.type === 'password';
  input.type = show ? 'text' : 'password';
  button.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
  button.setAttribute('aria-pressed', show ? 'true' : 'false');
}));

if (form) form.addEventListener('submit', event => {
  const password = form.elements.password;
  const confirmation = form.elements.confirm_password;
  confirmation.setCustomValidity(password.value === confirmation.value ? '' : 'Passwords do not match.');
  if (!form.checkValidity()) {
    event.preventDefault();
    form.reportValidity();
  }
});

const propertyDate = value => {
  if (!value) return 'Not recorded';
  const date = new Date(String(value).replace(' ', 'T'));
  return Number.isNaN(date.getTime()) ? String(value) : date.toLocaleDateString('en-PH', {year:'numeric',month:'long',day:'numeric'});
};

function openPropertyDrawer(data, trigger) {
  if (!propertyDrawer) return;
  lastPropertyTrigger = trigger;
  const content = document.getElementById('propertyProfileContent');
  const status = String(data.status || 'inactive').toLowerCase();
  const type = String(data.type || 'property').replace(/^./, character => character.toUpperCase());
  const email = String(data.username || '');
  content.innerHTML = `
    <section class="pp-hero"><span class="pp-hero-icon">${propertyIcon}</span><div><small>REGISTERED ACCOMMODATION PROVIDER</small><h2>${escapeHtml(data.name || 'Property')}</h2><p>${escapeHtml(data.island || 'Location not set')} · Property ID #${Number(data.hotel_resort_id || 0)}</p><b>${escapeHtml(type)} · ${status === 'active' ? 'Active' : 'Inactive'}</b></div></section>
    <section class="pp-stats"><div><strong>${Number(data.room_count || 0)}</strong><span>Room types</span></div><div><strong>${Number(data.available_units || 0)}</strong><span>Available units</span></div><div><strong>${Number(data.booking_count || 0)}</strong><span>Total bookings</span></div></section>
    <section class="pp-section"><h3>Property information</h3><p>Classification, location, pricing, and listing status</p><div class="pp-info"><div><small>Property type</small><strong>${escapeHtml(type)}</strong></div><div><small>Location</small><strong>${escapeHtml(data.island || 'Not provided')}</strong></div><div><small>Starting rate</small><strong>₱${Number(data.price || 0).toLocaleString('en-PH',{minimumFractionDigits:2})}</strong></div><div><small>Listing status</small><strong>${status === 'active' ? 'Active' : 'Inactive'}</strong></div></div>${data.description_text ? `<p class="pp-description">${escapeHtml(data.description_text)}</p>` : ''}</section>
    <section class="pp-section"><h3>Owner portal access</h3><p>Administrator identity and account login</p><div class="pp-info"><div><small>Administrator</small><strong>${escapeHtml(data.administrator_name || 'Property administrator')}</strong></div><div><small>Account status</small><strong>${escapeHtml(data.account_status || status)}</strong></div><div><small>Login email</small><strong>${escapeHtml(email || 'Not provided')}</strong></div><div><small>Last property update</small><strong>${escapeHtml(propertyDate(data.updated_at))}</strong></div></div>${email ? `<a class="pp-email" href="mailto:${encodeURIComponent(email)}">Email property administrator</a>` : ''}</section>
    <section class="pp-section"><h3>Inventory overview</h3><p>Room records currently maintained by this property</p><div class="pp-info"><div><small>All room types</small><strong>${Number(data.room_count || 0)}</strong></div><div><small>Active room types</small><strong>${Number(data.active_room_count || 0)}</strong></div><div><small>Available units</small><strong>${Number(data.available_units || 0)}</strong></div><div><small>Property created</small><strong>${escapeHtml(propertyDate(data.created_at))}</strong></div></div></section>`;
  propertyDrawer.classList.add('show');
  propertyDrawer.setAttribute('aria-hidden','false');
  document.body.classList.add('property-dialog-open');
  propertyDrawer.querySelector('.property-profile-close')?.focus();
}

function closePropertyDrawer() {
  if (!propertyDrawer) return;
  propertyDrawer.classList.remove('show');
  propertyDrawer.setAttribute('aria-hidden','true');
  document.body.classList.remove('property-dialog-open');
  lastPropertyTrigger?.focus();
}

propertyDrawer?.querySelector('.property-profile-close')?.addEventListener('click', closePropertyDrawer);
propertyDrawer?.querySelector('.property-profile-close-btn')?.addEventListener('click', closePropertyDrawer);
propertyDrawer?.addEventListener('mousedown', event => { if (event.target === propertyDrawer) closePropertyDrawer(); });

document.addEventListener('keydown', event => {
  if (event.key !== 'Escape') return;
  if (document.querySelector('.property-row-actions.open')) {
    closePropertyActionMenus();
    return;
  }
  if (propertyDrawer?.classList.contains('show')) closePropertyDrawer();
  else if (resetPasswordModal?.classList.contains('open')) closeResetPasswordModal();
  else if (hotelModal?.classList.contains('open')) closePropertyModal();
});

resetPasswordForm?.addEventListener('submit', async event => {
  event.preventDefault();
  const newPassword = resetPasswordForm.elements.new_password;
  const confirmation = resetPasswordForm.elements.confirm_password;
  confirmation.setCustomValidity(newPassword.value === confirmation.value ? '' : 'Passwords do not match.');
  if (!resetPasswordForm.checkValidity()) {
    resetPasswordForm.reportValidity();
    return;
  }

  const submitButton = resetPasswordForm.querySelector('button[type="submit"]');
  submitButton.disabled = true;
  if (resetPasswordError) resetPasswordError.textContent = '';
  try {
    const response = await fetch('adhotelresorts.php', {
      method: 'POST',
      credentials: 'same-origin',
      body: new FormData(resetPasswordForm)
    });
    const result = await response.json();
    if (!response.ok || !result.success) throw new Error(result.message || 'The password could not be reset.');
    closeResetPasswordModal();
    if (window.Swal) {
      await Swal.fire({icon: 'success', title: 'Password reset', text: result.message, confirmButtonColor: '#2b7a66'});
    } else {
      alert(result.message);
    }
  } catch (error) {
    if (resetPasswordError) resetPasswordError.textContent = error.message || 'The password could not be reset.';
  } finally {
    submitButton.disabled = false;
  }
});

// toggle status (event delegation)
if (tbody) {
  tbody.addEventListener('click', async (e) => {
    const resetButton = e.target.closest('.reset-owner-password-btn');
    if (resetButton) {
      openResetPasswordModal(resetButton);
      return;
    }
    const btn = e.target.closest('.toggle-status-btn');
    if (!btn) return;

    const id = btn.dataset.hotelId;
    const current = btn.dataset.currentStatus;
    const newStatus = current === 'active' ? 'inactive' : 'active';

    const res = await fetch('adhotelresorts.php', {
      method: 'POST',
      headers: {'Content-Type':'application/x-www-form-urlencoded'},
      body: new URLSearchParams({toggle_status:'1', hotel_id:String(id), new_status:newStatus, csrf_token:<?= json_encode($adminHotelCsrf, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>})
    });

    const data = await res.json();

    if (data.success) loadHotels();
  });
}

loadHotels();

});

</script>
</body>
</html>
