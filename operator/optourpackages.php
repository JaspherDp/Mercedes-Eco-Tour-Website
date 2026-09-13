<?php
chdir(__DIR__ . '/..');
require_once __DIR__ . '/../php/session_security.php';
AppSessionStart();
require 'php/db_connection.php';
require_once __DIR__ . '/../php/package_slot_helper.php';
require_once __DIR__ . '/../php/input_validation.php';
require_once __DIR__ . '/../php/activity_logger.php';
require_once __DIR__ . '/../php/operator_auth_helper.php';

// ✅ Operator Authentication
$operatorAccount = OperatorRequireLogin($pdo);
$operator_id = $_SESSION['operator_id'];
$operatorName = $_SESSION['operator_name'] ?? 'Operator';
$opProfilePicFile = trim((string)($_SESSION['operator_profile'] ?? ''));
$opHeaderProfilePic = null;
if ($opProfilePicFile !== '' && strtolower($opProfilePicFile) !== 'img/profileicon.png' && file_exists($opProfilePicFile)) {
    $opHeaderProfilePic = $opProfilePicFile;
}
$opProfileInitial = strtoupper(substr(trim((string)$operatorName) !== '' ? trim((string)$operatorName) : 'O', 0, 1));

packageSlotEnsureSchema($pdo);
$packageSlotCsrf = (string)($_SESSION['package_slot_csrf'] ?? '');
if ($packageSlotCsrf === '') {
    $packageSlotCsrf = bin2hex(random_bytes(24));
    $_SESSION['package_slot_csrf'] = $packageSlotCsrf;
}
$operatorPackageCsrf = AppCsrfToken('operator', 'package_management');
$operatorNotificationCsrf = AppCsrfToken('operator', 'notifications');

if (($_GET['op_action'] ?? '') === 'package_slot_month') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    $packageId = max(0, (int)($_GET['package_id'] ?? 0));
    $month = trim((string)($_GET['month'] ?? ''));
    $ownsStmt = $pdo->prepare('SELECT package_title FROM tour_packages WHERE package_id = ? AND operator_id = ? LIMIT 1');
    $ownsStmt->execute([$packageId, $operator_id]);
    $packageTitle = (string)$ownsStmt->fetchColumn();
    if ($packageTitle === '' || !preg_match('/^\d{4}-\d{2}$/', $month)) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'Select a valid package and month.']);
        exit;
    }
    echo json_encode([
        'success' => true,
        'package_title' => $packageTitle,
        'month' => $month,
        'slots' => packageSlotMonth($pdo, (int)$operator_id, $packageId, $month),
    ], JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array((string)($_POST['op_action'] ?? ''), ['save_package_slot', 'clear_package_slot'], true)) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    try {
        if (!hash_equals($packageSlotCsrf, (string)($_POST['csrf_token'] ?? ''))) {
            throw new RuntimeException('Your session expired. Refresh the page and try again.');
        }
        $packageId = ItourValidationInt($_POST['package_id'] ?? null, 'Package', 1, PHP_INT_MAX);
        $slotDate = ItourValidationDate($_POST['slot_date'] ?? null, 'Availability date');
        if ($slotDate < date('Y-m-d')) {
            throw new RuntimeException('Choose today or a future date.');
        }
        $packageStmt = $pdo->prepare('SELECT package_title FROM tour_packages WHERE package_id = ? AND operator_id = ? LIMIT 1');
        $packageStmt->execute([$packageId, $operator_id]);
        $packageTitle = (string)$packageStmt->fetchColumn();
        if ($packageTitle === '') throw new RuntimeException('The selected package was not found.');

        if (($_POST['op_action'] ?? '') === 'clear_package_slot') {
            $deleteStmt = $pdo->prepare('DELETE FROM package_daily_slots WHERE package_id = ? AND operator_id = ? AND slot_date = ?');
            $deleteStmt->execute([$packageId, $operator_id, $slotDate]);
            $message = 'The custom capacity was removed.';
        } else {
            $capacity = ItourValidationInt($_POST['capacity'] ?? null, 'Capacity', 1, 500);
            $isOpenRaw = $_POST['is_open'] ?? null;
            if (!is_string($isOpenRaw) || !in_array($isOpenRaw, ['0', '1'], true)) {
                throw new InvalidArgumentException('Availability status must be open or closed.');
            }
            $isOpen = $isOpenRaw === '1' ? 1 : 0;
            $saveStmt = $pdo->prepare("INSERT INTO package_daily_slots (operator_id, package_id, slot_date, capacity, is_open)
                VALUES (?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE operator_id = VALUES(operator_id), capacity = VALUES(capacity), is_open = VALUES(is_open), updated_at = NOW()");
            $saveStmt->execute([$operator_id, $packageId, $slotDate, $capacity, $isOpen]);
            $message = $isOpen ? 'Daily guest capacity saved.' : 'This package is closed for the selected date.';
        }
        logActivity($pdo, 'Tour Operator', (int)$operator_id, (string)$operatorName, 'Package Availability Updated', $packageTitle . ' availability updated for ' . $slotDate . '.', 'Tour Packages', $packageId);
        echo json_encode(['success' => true, 'message' => $message]);
    } catch (Throwable $error) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => $error->getMessage()]);
    }
    exit;
}

if (isset($_POST['op_action']) && $_POST['op_action'] === 'mark_notifications_read') {
    if (!AppVerifyCsrf('operator', 'notifications', $_POST['csrf_token'] ?? null)) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['ok' => false]);
        exit;
    }
    $_SESSION['op_notifications_seen_at'] = date('Y-m-d H:i:s');
    header('Content-Type: application/json');
    echo json_encode(['ok' => true]);
    exit;
}

$seenAt = trim((string)($_SESSION['op_notifications_seen_at'] ?? ''));
if ($seenAt !== '' && preg_match('/^\d{4}-\d{2}-\d{2}\s\d{2}:\d{2}:\d{2}$/', $seenAt)) {
    $notifStmt = $pdo->prepare("SELECT COUNT(*) FROM bookings WHERE operator_id=? AND status='pending' AND created_at > ?");
    $notifStmt->execute([$operator_id, $seenAt]);
} else {
    $notifStmt = $pdo->prepare("SELECT COUNT(*) FROM bookings WHERE operator_id=? AND status='pending'");
    $notifStmt->execute([$operator_id]);
}
$notificationCount = (int)$notifStmt->fetchColumn();

$notifItemsStmt = $pdo->prepare("
    SELECT booking_id, package_name, booking_date, status, created_at
    FROM bookings
    WHERE operator_id=?
    ORDER BY created_at DESC
    LIMIT 8
");
$notifItemsStmt->execute([$operator_id]);
$notificationItems = $notifItemsStmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch packages for this operator only
$stmt = $pdo->prepare("SELECT * FROM tour_packages WHERE operator_id=? ORDER BY package_id DESC");
$stmt->execute([$operator_id]);
$packages = $stmt->fetchAll(PDO::FETCH_ASSOC);

$activeBookingFilter = "LOWER(COALESCE(status, '')) IN ('pending', 'accepted') AND LOWER(COALESCE(is_complete, 'uncomplete')) = 'uncomplete'";
$summaryStmt = $pdo->prepare("SELECT
        COUNT(*) AS upcoming_bookings,
        COALESCE(SUM(CASE WHEN COALESCE(pax, 0) > 0 THEN pax ELSE COALESCE(num_adults, 0) + COALESCE(num_children, 0) END), 0) AS upcoming_guests,
        COALESCE(SUM(CASE WHEN booking_date BETWEEN DATE_FORMAT(CURDATE(), '%Y-%m-01') AND LAST_DAY(CURDATE()) THEN 1 ELSE 0 END), 0) AS bookings_this_month,
        COALESCE(SUM(CASE WHEN booking_date BETWEEN DATE_FORMAT(CURDATE(), '%Y-%m-01') AND LAST_DAY(CURDATE()) THEN grand_total ELSE 0 END), 0) AS value_this_month
    FROM bookings
    WHERE operator_id = ? AND booking_type = 'package' AND booking_date >= CURDATE() AND {$activeBookingFilter}");
$summaryStmt->execute([$operator_id]);
$packageSummary = $summaryStmt->fetch(PDO::FETCH_ASSOC) ?: [];

$packageStatsStmt = $pdo->prepare("SELECT package_name,
        COUNT(*) AS booking_count,
        COALESCE(SUM(CASE WHEN COALESCE(pax, 0) > 0 THEN pax ELSE COALESCE(num_adults, 0) + COALESCE(num_children, 0) END), 0) AS guest_count,
        MIN(booking_date) AS next_date
    FROM bookings
    WHERE operator_id = ? AND booking_type = 'package' AND booking_date >= CURDATE() AND {$activeBookingFilter}
    GROUP BY package_name");
$packageStatsStmt->execute([$operator_id]);
$packageStats = [];
foreach ($packageStatsStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $packageStats[(string)$row['package_name']] = $row;
}

$scheduleStmt = $pdo->prepare("SELECT booking_date, COUNT(*) AS booking_count,
        COALESCE(SUM(CASE WHEN COALESCE(pax, 0) > 0 THEN pax ELSE COALESCE(num_adults, 0) + COALESCE(num_children, 0) END), 0) AS guests
    FROM bookings
    WHERE operator_id = ? AND booking_type = 'package'
      AND booking_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 13 DAY)
      AND {$activeBookingFilter}
    GROUP BY booking_date ORDER BY booking_date");
$scheduleStmt->execute([$operator_id]);
$scheduleMap = [];
foreach ($scheduleStmt->fetchAll(PDO::FETCH_ASSOC) as $row) $scheduleMap[(string)$row['booking_date']] = $row;
$bookingDateChart = [];
for ($offset = 0; $offset < 14; $offset++) {
    $date = date('Y-m-d', strtotime('+' . $offset . ' day'));
    $bookingDateChart[] = [
        'date' => $date,
        'label' => $offset === 0 ? 'Today' : date('M j', strtotime($date)),
        'bookings' => (int)($scheduleMap[$date]['booking_count'] ?? 0),
        'guests' => (int)($scheduleMap[$date]['guests'] ?? 0),
    ];
}

$trendStart = date('Y-m-01', strtotime('-5 months'));
$trendStmt = $pdo->prepare("SELECT DATE_FORMAT(created_at, '%Y-%m') AS month_key, COUNT(*) AS booking_count
    FROM bookings WHERE operator_id = ? AND booking_type = 'package' AND created_at >= ?
      AND LOWER(COALESCE(status, '')) NOT IN ('cancelled', 'declined')
    GROUP BY month_key ORDER BY month_key");
$trendStmt->execute([$operator_id, $trendStart]);
$trendMap = [];
foreach ($trendStmt->fetchAll(PDO::FETCH_ASSOC) as $row) $trendMap[(string)$row['month_key']] = (int)$row['booking_count'];
$monthlyTrend = [];
for ($offset = 5; $offset >= 0; $offset--) {
    $key = date('Y-m', strtotime('-' . $offset . ' months'));
    $monthlyTrend[] = ['label' => date('M', strtotime($key . '-01')), 'count' => (int)($trendMap[$key] ?? 0)];
}

$openSlotStmt = $pdo->prepare('SELECT COUNT(*) FROM package_daily_slots WHERE operator_id = ? AND slot_date >= CURDATE() AND is_open = 1 AND capacity > 0');
$openSlotStmt->execute([$operator_id]);
$openSlotDays = (int)$openSlotStmt->fetchColumn();

// Stored package images are shared with the admin page. The page itself is
// loaded through /optourpackages.php, so keep its browser-facing paths intact.
function resolveImagePath($imgField){
    $imgField = trim((string)$imgField);
    if ($imgField === '') return 'img/placeholder.png';

    $imgField = str_replace('\\', '/', $imgField);
    if (preg_match('#^(https?:)?//#i', $imgField) || str_starts_with($imgField, 'data:')) {
        return $imgField;
    }

    // Operator uploads are stored as upload/..., while public URLs use
    // php/upload/... (the same normalization used by adtourpackages).
    if (str_starts_with($imgField, 'upload/')) {
        return 'php/' . $imgField;
    }

    if (str_starts_with($imgField, 'php/upload/') || str_starts_with($imgField, 'img/')) {
        return $imgField;
    }

    // Support legacy rows that contain only the uploaded filename.
    $rootUpload = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'php' . DIRECTORY_SEPARATOR . 'upload' . DIRECTORY_SEPARATOR . basename($imgField);
    if (is_file($rootUpload)) {
        return 'php/upload/' . basename($imgField);
    }

    return 'img/placeholder.png';
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>iTour Mercedes - My Tour Packages</title>
<link rel="icon" type="image/png" href="img/newlogo.png">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/cropperjs@1.5.13/dist/cropper.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">

<style>
:root {
    --op-primary: #2b7a66;
    --op-primary-dark: #1d5d4a;
    --op-bg: #f4f8f6;
    --op-card: #ffffff;
    --op-border: #d8e6e0;
    --op-text: #132028;
    --op-muted: #60707a;
    --op-shadow: 0 12px 28px rgba(17, 67, 53, 0.08);
}

body { margin: 0; font-family: 'Inter', sans-serif; background: var(--op-bg); color: var(--op-text); }
.admin-container { display: flex; min-height: 100vh; }
main.op-main {
    margin-left: 250px;
    flex: 1;
    padding: 86px 18px 24px;
    box-sizing: border-box;
    min-width: 0;
    overflow-x: hidden;
    transition: margin-left 0.3s ease;
    min-height: 100vh;
}
.operator-header {
    background: #fff;
    border-radius: 0 0 14px 14px;
    padding: 14px 18px;
    position: fixed;
    top: 0;
    left: 250px;
    width: calc(100vw - 250px);
    min-height: 78px;
    z-index: 90;
    border-bottom: 1px solid rgba(188, 220, 206, 0.6);
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 16px;
    box-sizing: border-box;
}
.operator-header-left h2 {
    color: var(--op-primary-dark);
    font-size: 23px;
    margin: 0;
    font-weight: 700;
    letter-spacing: 0.01em;
}
.operator-header-left p {
    margin: 3px 0 0;
    color: var(--op-muted);
    font-size: 13px;
}
.operator-header-right {
    display: inline-flex;
    align-items: center;
    gap: 10px;
    margin-right: 8px;
    flex-wrap: wrap;
    justify-content: flex-end;
}
.op-topbar-profile {
    width: 38px;
    height: 38px;
    border-radius: 50%;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    background: linear-gradient(135deg, #2b7a66 0%, #1f614e 100%);
    color: #fff;
    font-size: 14px;
    font-weight: 800;
    text-transform: uppercase;
    border: 1px solid rgba(43, 122, 102, 0.18);
    box-shadow: 0 4px 10px rgba(28, 74, 62, 0.14);
    overflow: hidden;
    flex-shrink: 0;
}
.op-topbar-profile img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: block;
}
.op-notif-wrap { position: relative; }
.op-notif-btn {
    border: 1px solid var(--op-border);
    background: #fff;
    border-radius: 10px;
    padding: 8px 11px;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    color: #26404a;
    font-weight: 600;
    cursor: pointer;
    font-size: 13px;
}
.op-notif-badge {
    min-width: 20px;
    height: 20px;
    border-radius: 50%;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    background: #bf3545;
    color: #fff;
    font-size: 12px;
    font-weight: 700;
}
.op-notif-panel {
    position: absolute;
    right: 0;
    top: calc(100% + 8px);
    width: min(420px, 88vw);
    background: #fff;
    border: 1px solid var(--op-border);
    border-radius: 12px;
    box-shadow: var(--op-shadow);
    padding: 10px;
    display: none;
    z-index: 120;
}
.op-notif-panel.open { display: block; }
.op-notif-panel h4 { margin: 0 0 8px; font-size: 14px; }
.op-notif-list {
    margin: 0;
    padding: 0;
    list-style: none;
    display: grid;
    gap: 8px;
    max-height: 320px;
    overflow: auto;
}
.op-notif-list li {
    border: 1px solid #e8f0ed;
    background: #fbfefd;
    border-radius: 10px;
    padding: 8px;
    display: grid;
    gap: 2px;
}
.op-notif-list li strong { font-size: 12px; color: #1d343e; }
.op-notif-list li span,
.op-notif-list li small { font-size: 12px; color: #63747d; }
.op-notif-empty { margin: 0; color: var(--op-muted); font-size: 13px; padding: 8px 4px; }

.dashboard-content {
    margin-top: 4px;
}
.packages-section {
    position: relative;
    background: var(--op-card);
    border: 1px solid var(--op-border);
    border-radius: 16px;
    box-shadow: 0 10px 24px rgba(17, 67, 53, 0.07);
    padding: 18px;
}
.packages-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    margin-bottom: 14px;
    flex-wrap: wrap;
}
.packages-head h3 {
    margin: 0;
    font-size: 18px;
    color: #1f3f49;
}
.add-new-p-btn {
    border: 1px solid #2b7a66;
    background: var(--op-primary);
    color: #fff;
    border-radius: 10px;
    padding: 9px 13px;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    box-shadow: 0 2px 8px rgba(28, 74, 62, 0.16);
}
.add-new-p-btn:hover { background: var(--op-primary-dark); }

.cards-container {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 270px));
    justify-content: start;
    gap: 16px;
    margin-top: 2px;
    align-items: stretch;
}

.package-card {
    background: #fff;
    border: 1px solid #dfeae6;
    border-radius: 12px;
    box-shadow: 0 8px 18px rgba(17, 67, 53, 0.06);
    display: flex;
    flex-direction: column;
    overflow: hidden;
    min-width: 0;
}
.package-card img { width: 100%; height: 150px; object-fit: cover; }
.package-card-content {
    padding: 14px;
    display: flex;
    flex-direction: column;
    flex: 1;
    gap: 8px;
    min-width: 0;
}
.package-card-content h4 {
    margin: 0;
    color: #214952;
    font-size: 17px;
    line-height: 1.3;
    word-break: break-word;
}
.package-card-content p {
    margin: 0;
    font-weight: 800;
    color: #102028;
    font-size: 1.2rem;
}
.package-card-content button {
    margin-top: auto;
    padding: 9px 11px;
    border: 1px solid var(--op-border);
    border-radius: 10px;
    background: #fff;
    color: #24434d;
    font-weight: 600;
    font-size: 13px;
    cursor: pointer;
}
.package-card-content button:hover {
    background: #f4faf7;
    border-color: #c9dfd6;
}

.modal {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(7, 24, 19, 0.66);
    backdrop-filter: blur(3px);
    z-index: 1000;
    justify-content: center;
    align-items: center;
    padding: 24px;
    box-sizing: border-box;
}
.modal-content {
    background: #fff;
    border-radius: 18px;
    border: 1px solid rgba(203, 222, 214, 0.9);
    width: min(1040px, 100%);
    max-width: 1040px;
    max-height: calc(100vh - 48px);
    overflow-y: auto;
    padding: 0;
    position: relative;
    box-shadow: 0 28px 70px rgba(7, 32, 24, 0.24);
}
#editModal .modal-content {
    scrollbar-width: thin;
    scrollbar-color: var(--op-primary) #edf4f1;
}
#editModal .modal-content::-webkit-scrollbar {
    width: 7px;
}
#editModal .modal-content::-webkit-scrollbar-track {
    background: #edf4f1;
    border-radius: 0 18px 18px 0;
}
#editModal .modal-content::-webkit-scrollbar-thumb {
    background: var(--op-primary);
    border-radius: 999px;
    border: 1px solid #edf4f1;
}
#editModal .modal-content::-webkit-scrollbar-thumb:hover {
    background: var(--op-primary-dark);
}
#editModal .modal-content::-webkit-scrollbar-button {
    display: none;
}
.close {
    width: 38px;
    height: 38px;
    border: 1px solid #dce9e4;
    border-radius: 10px;
    background: #f8fbfa;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 23px;
    line-height: 1;
    font-weight: 500;
    color: #5a6f79;
    cursor: pointer;
    flex: 0 0 auto;
    transition: background-color .18s ease, border-color .18s ease, color .18s ease, transform .18s ease;
}
.close:hover { color: #173e33; background: #e4f3ed; border-color: #9fc9b9; transform: translateY(-1px); }
.close:focus-visible { outline: 3px solid rgba(43,122,102,.2); outline-offset: 2px; }

.modal-section-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
    position: sticky;
    top: 0;
    z-index: 5;
    padding: 20px 24px;
    border-bottom: 1px solid #e7efec;
    background: rgba(255, 255, 255, 0.97);
}
.modal-section-head strong {
    display: block;
    font-size: 1.18rem;
    color: #173b32;
    margin-bottom: 3px;
}
.modal-section-head p {
    margin: 0;
    color: #687b74;
    font-size: 0.82rem;
    line-height: 1.45;
}
.image-modal-title {
    color: #244650;
    text-align: center;
    margin: 0 0 8px;
}

.custum-file-upload {
    width: 100%;
    max-width: 460px;
    height: 220px;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 15px;
    cursor: pointer;
    border: 2px dashed var(--op-primary);
    background-color: #f8fcfa;
    padding: 1rem;
    border-radius: 12px;
    margin: 0 auto;
    box-sizing: border-box;
}
.custum-file-upload .icon svg {
    height: 72px;
    fill: #60707a;
}
.custum-file-upload .text span {
    font-weight: 500;
    color: #4f636c;
}
.custum-file-upload input { display: none; }
.custum-file-upload.dragover {
    background-color: #edf8f3;
    border-color: var(--op-primary-dark);
}

.itinerary-step {
    display: flex;
    gap: 12px;
    align-items: flex-start;
    margin-bottom: 12px;
    border-radius: 12px;
    border: 1px solid #dfeae6;
    background: #fbfdfc;
    padding: 12px 14px;
}
.drag-handle {
    cursor: grab;
    font-size: 1.3rem;
    color: var(--op-primary);
    user-select: none;
}
.drag-handle:active { cursor: grabbing; }

.step-content { flex: 1; display: flex; flex-direction: column; gap: 10px; }
.step-content .top-row {
    width: 100%;
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 12px;
}
.step-content textarea,
.step-content input {
    margin-top: 8px;
    width: 100%;
    padding: 9px 10px;
    border-radius: 10px;
    border: 1px solid #cfe0da;
    font-size: 0.95rem;
    outline: none;
    transition: border-color 0.2s ease, box-shadow 0.2s ease;
}
.step-content textarea {
    resize: vertical;
    min-height: 96px;
}
.step-content textarea:focus,
.step-content input:focus,
.form-input:focus,
.form-group select:focus {
    border-color: var(--op-primary);
    box-shadow: 0 0 0 3px rgba(43, 122, 102, 0.12);
}

.itinerary-step .btn-red {
    margin-top: 10px;
    align-self: flex-start;
    background: #fff3f4;
    color: #a52d3b;
    border: 1px solid #f0cbd0;
}
.itinerary-step .btn-red:hover { background: #fce7e9; }
.add-step-btn,
.btn-green,
.btn-cancel,
.btn-red {
    padding: 9px 12px;
    border: 0;
    border-radius: 10px;
    cursor: pointer;
    font-weight: 700;
}
.add-step-btn,
.btn-green { background: var(--op-primary); color: #fff; }
.add-step-btn:hover,
.btn-green:hover { background: var(--op-primary-dark); }
.btn-green:disabled { opacity: .65; cursor: wait; }
.btn-red,
.btn-cancel { background: #bf3545; color: #fff; }
.btn-red:hover,
.btn-cancel:hover { background: #9d2a38; }

#cropContainer { display: flex; justify-content: center; align-items: center; padding: 12px 0; }

.form-group {
    display: flex;
    flex-direction: column;
    margin-bottom: 12px;
    gap: 6px;
}
.form-input,
.form-group input,
.form-group select,
.form-group textarea {
    padding: 10px 12px;
    border-radius: 10px;
    border: 1px solid #cfe0da;
    font-size: 0.95rem;
    width: 100%;
    box-sizing: border-box;
    font-family: 'Inter', sans-serif;
}
.form-group label {
    font-weight: 700;
    color: #314d45;
    font-size: 0.9rem;
}

body.modal-open { overflow: hidden; }
.package-form-body { padding: 22px 24px 0; }
.package-form-section {
    border: 1px solid #deebe6;
    border-radius: 14px;
    background: #fff;
    padding: 18px;
    margin-bottom: 18px;
}
.package-form-section-head {
    display: flex;
    align-items: flex-start;
    gap: 11px;
    margin-bottom: 16px;
}
.package-form-section-icon {
    width: 34px;
    height: 34px;
    border-radius: 9px;
    background: #eaf5f0;
    color: var(--op-primary-dark);
    display: inline-flex;
    align-items: center;
    justify-content: center;
    flex: 0 0 auto;
    font-size: 15px;
    font-weight: 800;
}
.package-form-section-head h4 { margin: 0 0 3px; color: #173b32; font-size: 0.98rem; }
.package-form-section-head p { margin: 0; color: #708079; font-size: 0.79rem; line-height: 1.45; }
.package-form-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 14px 18px; }
.package-form-grid .form-group { margin: 0; }
.field-required { color: #b73343; margin-left: 2px; }
.package-form-section .form-input {
    min-height: 44px;
    background: #fcfefd;
}
.package-form-section .form-input::placeholder { color: #9aa9a3; }
.image-card-grid { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 14px; }
.image-card-grid.general { grid-template-columns: repeat(2, minmax(0, 220px)); }
.package-image-card {
    border: 1px solid #dce9e4;
    border-radius: 12px;
    padding: 9px;
    background: #fbfdfc;
    min-width: 0;
}
.package-image-card img {
    width: 100%;
    aspect-ratio: 4 / 3;
    height: auto;
    display: block;
    object-fit: cover;
    border-radius: 8px;
    background: #edf2f0;
}
.package-image-card-label { display: block; margin: 10px 2px 8px; font-size: 0.78rem; font-weight: 700; color: #526760; }
.package-image-card .btn-green { width: 100%; padding: 8px 10px; font-size: 0.78rem; }
.package-form-actions {
    position: sticky;
    bottom: 0;
    z-index: 4;
    display: flex;
    justify-content: flex-end;
    gap: 10px;
    padding: 15px 24px;
    margin: 4px -24px 0;
    border-top: 1px solid #e2ece8;
    background: rgba(255,255,255,.97);
}
.package-form-actions .btn-cancel { background: #fff; color: #455b54; border: 1px solid #ccdcd6; }
.package-form-actions .btn-cancel:hover { background: #f4f8f6; }
.package-form-actions button { min-width: 112px; min-height: 42px; }
.itinerary-toolbar { display: flex; justify-content: flex-end; margin-bottom: 12px; }
.itinerary-step:last-child { margin-bottom: 0; }
.empty-packages { padding: 42px 20px; text-align: center; color: var(--op-muted); grid-column: 1 / -1; }

.op-header-action {
    min-height: 40px;
    padding: 8px 13px;
    border: 1px solid var(--op-border);
    border-radius: 10px;
    background: #fff;
    color: #24483e;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    font: 700 0.78rem 'Inter', sans-serif;
    cursor: pointer;
}
.op-header-action svg { width: 17px; height: 17px; fill: none; stroke: currentColor; stroke-width: 1.8; }
.op-header-action:hover { border-color: #a9cdc0; background: #f2f8f5; }
.op-header-action.primary { color: #fff; background: var(--op-primary); border-color: var(--op-primary); }
.op-header-action.primary:hover { background: var(--op-primary-dark); }

.package-summary-grid {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 14px;
    margin-bottom: 14px;
}
.package-summary-card {
    background: linear-gradient(135deg, #26725e 0%, #195845 100%);
    border: 1px solid #246b58;
    border-radius: 14px;
    padding: 15px;
    display: flex;
    align-items: center;
    gap: 12px;
    min-width: 0;
    min-height: 76px;
    box-sizing: border-box;
    box-shadow: 0 8px 18px rgba(17, 67, 53, 0.14);
    transition: transform .18s ease, box-shadow .18s ease;
}
.package-summary-card:hover { transform: translateY(-2px); box-shadow: 0 12px 23px rgba(17,67,53,.2); }
.summary-icon {
    width: 40px;
    height: 40px;
    border-radius: 11px;
    background: #eaf5f0;
    color: var(--op-primary-dark);
    display: grid;
    place-items: center;
    flex: 0 0 auto;
}
.package-summary-card .summary-icon { background: rgba(255,255,255,.15); color: #fff; }
.summary-icon svg { width: 20px; height: 20px; fill: none; stroke: currentColor; stroke-width: 1.8; }
.package-summary-card small { display: block; color: rgba(255,255,255,.76); font-size: .72rem; margin-bottom: 3px; }
.package-summary-card strong { display: block; color: #fff; font-size: 1.22rem; line-height: 1.15; }

.package-workspace { display: grid; grid-template-columns: minmax(0, 1.75fr) minmax(310px, .75fr); gap: 16px; align-items: start; }
.package-workspace .packages-section { min-width: 0; }
.package-workspace .cards-container { grid-template-columns: repeat(2, minmax(0, 1fr)); justify-content: stretch; }
.package-workspace .package-card > img {
    width: 100%;
    height: auto;
    aspect-ratio: 4 / 3;
    padding: 0;
    box-sizing: border-box;
    object-fit: cover;
    object-position: center;
    background: #edf4f1;
    border-bottom: 1px solid #dfeae6;
}
.package-toolbar { display: flex; align-items: center; gap: 9px; flex-wrap: wrap; }
.package-search { position: relative; min-width: 210px; }
.package-search svg { position: absolute; left: 10px; top: 50%; width: 15px; height: 15px; transform: translateY(-50%); fill: none; stroke: #6e827a; stroke-width: 2; }
.package-search input,
.package-toolbar select {
    height: 38px;
    border: 1px solid #d5e4de;
    border-radius: 9px;
    background: #fbfdfc;
    color: #314c44;
    font: 500 .76rem 'Inter', sans-serif;
    outline: none;
}
.package-search input { width: 100%; box-sizing: border-box; padding: 8px 10px 8px 32px; }
.package-toolbar select { padding: 0 28px 0 10px; }
.package-search input:focus, .package-toolbar select:focus { border-color: var(--op-primary); box-shadow: 0 0 0 3px rgba(43,122,102,.1); }
.package-card { position: relative; }
.package-card-topline { display: flex; justify-content: space-between; align-items: center; gap: 8px; }
.package-type-badge { padding: 4px 7px; border-radius: 999px; background: #edf6f2; color: #286752; font-size: .66rem; font-weight: 800; text-transform: capitalize; }
.package-card-stats { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 7px; margin: 2px 0 3px; }
.package-card-stat { padding: 8px; border-radius: 8px; background: #f6faf8; }
.package-card-stat span { display: block; color: #7a8984; font-size: .64rem; margin-bottom: 2px; }
.package-card-stat strong { font-size: .74rem; color: #284b41; }
.package-card-actions { display: grid; grid-template-columns: 1fr auto; gap: 7px; margin-top: auto; }
.package-card-actions button { margin-top: 0; }
.package-card-actions .p-edit-p-btn {
    color: #fff;
    background: var(--op-primary);
    border-color: var(--op-primary);
    transition: background-color .18s ease, border-color .18s ease, transform .18s ease, box-shadow .18s ease;
}
.package-card-actions .p-edit-p-btn:hover {
    color: #fff;
    background: var(--op-primary-dark);
    border-color: var(--op-primary-dark);
    transform: translateY(-1px);
    box-shadow: 0 5px 12px rgba(43,122,102,.22);
}
.package-card-actions .availability-btn {
    width: 39px;
    padding: 8px;
    color: #fff;
    background: var(--op-primary);
    border-color: var(--op-primary);
    transition: background-color .18s ease, border-color .18s ease, transform .18s ease, box-shadow .18s ease;
}
.package-card-actions .availability-btn:hover {
    color: #fff;
    background: var(--op-primary-dark);
    border-color: var(--op-primary-dark);
    transform: translateY(-1px);
    box-shadow: 0 5px 12px rgba(43,122,102,.22);
}
.package-card-actions svg { width: 16px; height: 16px; fill: none; stroke: currentColor; stroke-width: 1.9; vertical-align: middle; }
.package-no-results { display: none; padding: 46px 20px; text-align: center; grid-column: 1 / -1; color: #6c7d77; }

.package-insights { display: grid; gap: 14px; min-width: 0; }
.insight-card { background: #fff; border: 1px solid var(--op-border); border-radius: 14px; padding: 16px; box-shadow: 0 6px 16px rgba(17,67,53,.045); }
.insight-head { display: flex; justify-content: space-between; align-items: flex-start; gap: 10px; margin-bottom: 14px; }
.insight-head h3 { margin: 0 0 3px; color: #1c4439; font-size: .92rem; }
.insight-head p { margin: 0; color: #788882; font-size: .7rem; }
.insight-kicker { padding: 4px 7px; background: #edf6f2; color: #2b705c; border-radius: 999px; font-size: .62rem; font-weight: 800; white-space: nowrap; }
.chart-wrap { height: 190px; position: relative; }
.date-load-list { display: grid; gap: 9px; }
.date-load-row { display: grid; grid-template-columns: 60px 1fr auto; align-items: center; gap: 9px; }
.date-load-row time { color: #50655e; font-size: .7rem; font-weight: 700; }
.date-load-track { height: 7px; background: #edf3f0; border-radius: 99px; overflow: hidden; }
.date-load-fill { height: 100%; min-width: 3px; background: linear-gradient(90deg, #2b7a66, #67a98f); border-radius: inherit; }
.date-load-row strong { color: #24483e; font-size: .68rem; min-width: 52px; text-align: right; }
.insight-empty { margin: 0; color: #7b8a85; font-size: .75rem; padding: 8px 0; }
.availability-brief { display: flex; align-items: center; gap: 12px; }
.availability-brief strong { display: block; font-size: 1.35rem; color: #1f5847; }
.availability-brief p { margin: 2px 0 0; color: #73837d; font-size: .71rem; line-height: 1.45; }
.availability-brief button { margin-left: auto; }
.availability-brief .calendar-nav-btn { background: var(--op-primary); border-color: var(--op-primary); color: #fff; }
.availability-brief .calendar-nav-btn:hover { background: var(--op-primary-dark); border-color: var(--op-primary-dark); }

#availabilityModal .modal-content { width: min(900px, 100%); max-width: 900px; }
.availability-body { padding: 20px 22px 22px; }
.availability-controls { display: grid; grid-template-columns: minmax(220px, 1fr) auto; gap: 12px; align-items: end; margin-bottom: 16px; }
.availability-controls .form-group { margin: 0; }
.calendar-month-nav { display: flex; align-items: center; gap: 7px; min-height: 44px; }
.calendar-month-nav strong { min-width: 125px; text-align: center; color: #25483e; font-size: .84rem; }
.calendar-nav-btn { width: 40px; height: 40px; border: 1px solid #d6e5df; background: #fff; border-radius: 9px; color: #31564b; cursor: pointer; font-size: 1rem; transition: background-color .18s ease, border-color .18s ease, color .18s ease, transform .18s ease, box-shadow .18s ease; }
.calendar-nav-btn:hover { background: var(--op-primary); border-color: var(--op-primary); color: #fff; transform: translateY(-1px); box-shadow: 0 5px 12px rgba(43,122,102,.2); }
.calendar-nav-btn:active { transform: translateY(0); box-shadow: none; }
.calendar-nav-btn:focus-visible { outline: 3px solid rgba(43,122,102,.18); outline-offset: 2px; }
.availability-layout { display: grid; grid-template-columns: minmax(0, 1fr) 260px; gap: 16px; }
.availability-layout > div, .slot-editor { min-width: 0; box-sizing: border-box; }
.availability-controls .form-input { min-height: 44px; }
.slot-calendar { border: 1px solid #dce9e4; border-radius: 13px; overflow: hidden; width: 100%; box-sizing: border-box; }
.calendar-weekdays, .calendar-days { display: grid; grid-template-columns: repeat(7, minmax(0, 1fr)); }
.calendar-days { grid-auto-rows: 68px; }
.calendar-weekdays span { padding: 9px 4px; background: #f3f8f6; color: #667a73; text-align: center; font-size: .65rem; font-weight: 800; text-transform: uppercase; }
.calendar-day { min-height: 0; height: 100%; border: 0; border-right: 1px solid #edf2f0; border-top: 1px solid #edf2f0; background: #fff; padding: 7px; text-align: left; cursor: pointer; color: #304d44; position: relative; box-sizing: border-box; transition: background-color .16s ease, color .16s ease, box-shadow .16s ease; }
.calendar-day:nth-child(7n) { border-right: 0; }
.calendar-day:hover:not(:disabled) { background: #e9f6f1; color: #155a45; box-shadow: inset 0 0 0 1px #9bcab9; }
.calendar-day:focus-visible { z-index: 2; outline: 3px solid rgba(43,122,102,.22); outline-offset: -3px; }
.calendar-day.is-other { color: #a7b2ae; background: #fafcfb; }
.calendar-day.is-past { color: #b0b9b6; cursor: not-allowed; background: #f7f9f8; }
.calendar-day.is-selected { box-shadow: inset 0 0 0 2px var(--op-primary); background: #eef8f4; }
.calendar-day.is-open::after, .calendar-day.is-full::after, .calendar-day.is-closed::after { content: ''; position: absolute; top: 9px; right: 8px; width: 7px; height: 7px; border-radius: 50%; }
.calendar-day.is-open::after { background: #32a276; }
.calendar-day.is-full::after { background: #d69b32; }
.calendar-day.is-closed::after { background: #bd4352; }
.calendar-day b { display: block; font-size: .72rem; }
.calendar-day small { display: block; margin-top: 13px; font-size: .58rem; color: #6e827a; white-space: nowrap; }
.slot-editor { border: 1px solid #dce9e4; border-radius: 13px; padding: 15px; background: #fbfdfc; align-self: stretch; display: flex; flex-direction: column; }
.slot-editor h4 { margin: 0 0 4px; color: #1c4439; font-size: .9rem; }
.slot-editor > p { margin: 0 0 14px; color: #778781; font-size: .7rem; line-height: 1.4; }
.slot-metric { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; margin-bottom: 13px; }
.slot-metric div { background: #fff; border: 1px solid #e0ebe7; border-radius: 9px; padding: 9px; }
.slot-metric span { display: block; font-size: .62rem; color: #7b8a85; }
.slot-metric strong { color: #234d40; font-size: .88rem; }
.slot-toggle { display: flex; align-items: center; gap: 8px; color: #36554b; font-size: .73rem; font-weight: 700; margin: 11px 0 14px; }
.slot-toggle input { accent-color: var(--op-primary); }
.slot-editor-actions { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; margin-top: auto; }
.slot-editor-actions button { min-height: 38px; padding: 9px; border-radius: 9px; font-weight: 700; cursor: pointer; font-size: .7rem; transition: background-color .18s ease, border-color .18s ease, color .18s ease, transform .18s ease, box-shadow .18s ease; }
.slot-clear { background: #fff; border: 1px solid #d5e3de; color: #576a64; }
.slot-save { background: var(--op-primary); border: 1px solid var(--op-primary); color: #fff; }
.slot-clear:hover:not(:disabled) { background: #edf5f2; border-color: #a8cbbd; color: #245b49; transform: translateY(-1px); }
.slot-save:hover:not(:disabled) { background: var(--op-primary-dark); border-color: var(--op-primary-dark); transform: translateY(-1px); box-shadow: 0 6px 13px rgba(43,122,102,.22); }
.slot-editor-actions button:active:not(:disabled) { transform: translateY(0); box-shadow: none; }
.slot-editor-actions button:disabled { opacity: .5; cursor: not-allowed; }
.slot-editor-actions button:focus-visible { outline: 3px solid rgba(43,122,102,.18); outline-offset: 2px; }
.calendar-legend { display: flex; gap: 14px; flex-wrap: wrap; margin-top: 11px; color: #6d7f78; font-size: .65rem; }
.calendar-legend span { display: inline-flex; align-items: center; gap: 5px; }
.calendar-legend i { width: 7px; height: 7px; border-radius: 50%; display: inline-block; }

@media (max-width: 1180px) {
    .package-summary-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    .package-workspace { grid-template-columns: 1fr; }
    .package-insights { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    .package-insights .insight-card:first-child { grid-column: 1 / -1; }
}

#imageModal .modal-content {
    width: 520px !important;
    max-width: 520px;
    max-height: 420px;
    overflow: hidden;
    display: flex;
    flex-direction: column;
    align-items: center;
    padding: 24px;
    box-sizing: border-box;
}
#imageModal .close { position: absolute; top: 14px; right: 14px; }

#cropContainer > div {
    width: 100% !important;
    height: 220px !important;
}

.swal2-confirm-green {
    background-color: var(--op-primary) !important;
    color: #fff !important;
}
.swal2-confirm-green:hover {
    background-color: var(--op-primary-dark) !important;
}
@media (max-width: 1280px) {
    .cards-container {
        grid-template-columns: repeat(3, minmax(0, 260px));
    }
}
@media (max-width: 900px) {
    .operator-header {
        position: static;
        left: auto;
        width: auto;
        border-radius: 14px;
    }
    main.op-main {
        margin-left: 250px;
        padding-top: 18px;
    }
    .cards-container {
        grid-template-columns: repeat(2, minmax(0, 1fr));
        justify-content: stretch;
    }
    .op-header-action span { display: none; }
    .op-header-action { padding: 9px; }
}
@media (max-width: 640px) {
    .cards-container {
        grid-template-columns: 1fr;
    }
    .package-summary-grid, .package-workspace .cards-container, .package-insights { grid-template-columns: 1fr; }
    .package-insights .insight-card:first-child { grid-column: auto; }
    .package-toolbar, .package-search { width: 100%; }
    .package-toolbar select { flex: 1; }
    .availability-controls, .availability-layout { grid-template-columns: 1fr; }
    .calendar-month-nav { justify-content: space-between; }
    .calendar-days { grid-auto-rows: 58px; }
    .calendar-day { padding: 5px; }
    .calendar-day small { font-size: .52rem; margin-top: 10px; overflow: hidden; text-overflow: ellipsis; }
    .modal { padding: 0; align-items: stretch; }
    .modal-content { width: 100%; max-height: 100vh; border-radius: 0; }
    .modal-section-head, .package-form-body { padding-left: 16px; padding-right: 16px; }
    .package-form-grid { grid-template-columns: 1fr; }
    .image-card-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    .image-card-grid.general { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    .package-form-actions { margin-left: -16px; margin-right: -16px; padding: 12px 16px; }
    .step-content .top-row { grid-template-columns: 1fr; }
    .itinerary-step { padding: 11px; }
    #imageModal .modal-content { width: 100% !important; max-width: 100%; min-height: 100vh; justify-content: center; }
}

/* Match the administrator destination/package editor language. */
#editModal { font-family: Poppins, Inter, sans-serif; backdrop-filter: blur(4px); }
#editModal .modal-content { width: min(900px,calc(100vw - 28px)); height: min(640px,calc(100vh - 28px)); max-width: 900px; max-height: 92vh; display: grid; grid-template-rows: auto minmax(0,1fr); overflow: hidden; border-radius: 17px; }
#editModal .package-editor-header { position: static; display: grid; grid-template-columns: 42px minmax(0,1fr) 32px; align-items: center; gap: 13px; min-height: 84px; padding: 16px 20px; background: #fff; }
.package-editor-icon { width: 42px; height: 42px; display: grid; place-items: center; border-radius: 11px; background: #e9f6f1; color: #17705b; }
.package-editor-icon svg { width: 21px; height: 21px; fill: none; stroke: currentColor; stroke-width: 1.7; stroke-linecap: round; stroke-linejoin: round; }
#editModal .package-editor-header small { display: block; color: #34806c; font-size: 8px; font-weight: 700; letter-spacing: .12em; }
#editModal .package-editor-header strong { margin: 2px 0; color: #17251f; font-size: 17px; line-height: 1.2; }
#editModal .package-editor-header p { color: #7a8882; font-size: 10px; }
#editModal .package-editor-header .close { width: 32px; height: 32px; border: 0; border-radius: 8px; background: #eff4f2; font-size: 21px; }
#editPackageForm { min-height: 0; height: 100%; display: grid; grid-template-rows: minmax(0,1fr) auto; overflow: hidden; }
.package-editor-layout { min-height: 0; display: grid; overflow: hidden; }
.package-editor-nav button { border: 0; color: #60756d; font: 600 11px Poppins,sans-serif; cursor: pointer; }
.package-editor-nav button svg { width: 18px; height: 18px; flex: 0 0 18px; fill: none; stroke: currentColor; stroke-width: 1.7; stroke-linecap: round; stroke-linejoin: round; }
.package-editor-pane { display: none; }
.package-editor-pane.active { display: grid; align-content: start; gap: 17px; }
.package-form-body { min-height: 0; padding: 22px; overflow-y: auto; overscroll-behavior: contain; scrollbar-gutter: stable; scrollbar-width: thin; scrollbar-color: #278068 #e6f0ec; }
.package-form-body::-webkit-scrollbar { width: 6px; height: 6px; }
.package-form-body::-webkit-scrollbar-track { background: #e6f0ec; border-radius: 999px; }
.package-form-body::-webkit-scrollbar-thumb { background: #278068; border-radius: 999px; }
.package-form-body::-webkit-scrollbar-thumb:hover { background: #145d4b; }

#editModal[data-mode="edit"] .package-editor-layout { grid-template-columns: 190px minmax(0,1fr); }
#editModal[data-mode="edit"] .package-editor-nav { padding: 17px 12px; border-right: 1px solid #e2ebe7; background: #f5f8f7; }
#editModal[data-mode="edit"] .package-editor-nav button { width: 100%; display: flex; align-items: center; gap: 9px; padding: 11px 12px; border-radius: 8px; background: transparent; text-align: left; }
#editModal[data-mode="edit"] .package-editor-nav button.active { background: #dff1ea; color: #176b58; }
#editModal[data-mode="add"] .package-editor-layout { grid-template-rows: auto minmax(0,1fr); }
#editModal[data-mode="add"] .package-editor-nav { counter-reset: package-step; display: grid; grid-template-columns: repeat(3,minmax(0,1fr)); padding: 14px 80px 13px; border-bottom: 1px solid #e2ebe7; background: #f8fbfa; }
#editModal[data-mode="add"] .package-editor-nav button { counter-increment: package-step; position: relative; display: flex; flex-direction: column; align-items: center; gap: 6px; padding: 0; border: 0; background: transparent; overflow: visible; font-size: 9.5px; }
#editModal[data-mode="add"] .package-editor-nav button svg { display: none; }
#editModal[data-mode="add"] .package-editor-nav button:before { content: counter(package-step); position: relative; z-index: 2; width: 30px; height: 30px; display: grid; place-items: center; border: 2px solid #c8dcd5; border-radius: 50%; background: #fff; color: #6d827a; font-size: 10px; }
#editModal[data-mode="add"] .package-editor-nav button:not(:last-child):after { content: ''; position: absolute; z-index: 1; top: 14px; left: calc(50% + 15px); width: calc(100% - 30px); height: 2px; background: #dce8e4; }
#editModal[data-mode="add"] .package-editor-nav button.active { color: #145f4d; }
#editModal[data-mode="add"] .package-editor-nav button.active:before { border-color: #176b58; background: #176b58; color: #fff; box-shadow: 0 0 0 4px #dcefe9; }
#editModal[data-mode="add"] .package-editor-nav button.completed { color: #347764; }
#editModal[data-mode="add"] .package-editor-nav button.completed:before { content: '✓'; border-color: #3a8d75; background: #e2f3ed; color: #176b58; }
#editModal[data-mode="add"] .package-editor-nav button.completed:after { background: #68aa97; }

#editModal .package-editor-pane[data-package-pane="information"] .package-form-grid { grid-template-columns: repeat(2,minmax(0,1fr)); gap: 17px; }
#editModal .package-editor-pane[data-package-pane="information"] .form-group:last-child { grid-column: 1 / -1; }
#editModal .form-group { min-width: 0; margin: 0; gap: 0; }
#editModal .form-group label { margin: 0 0 7px; color: #354a42; font: 700 10px Poppins,sans-serif; }
#editModal .form-input,
#editModal .form-group input:not([type="hidden"]),
#editModal .form-group select,
#editModal .form-group textarea { width: 100%; min-width: 0; max-width: 100%; min-height: 38px; margin: 0; padding: 9px 11px; box-sizing: border-box; border: 1px solid #d6e2dd; border-radius: 8px; background: #fff; color: #253c33; font: 11px Poppins,sans-serif; box-shadow: none; }
#editModal .form-group textarea { min-height: 72px; line-height: 1.5; resize: vertical; }
#editModal .form-input:focus,
#editModal .form-group input:focus,
#editModal .form-group select:focus,
#editModal .form-group textarea:focus { border-color: #55a08c; box-shadow: 0 0 0 3px rgba(81,162,139,.12); }
.field-required { display: none; color: #dc3545; font-weight: 800; }
#editModal[data-mode="add"] .field-required { display: inline; }
.package-required-note { display: none; margin-right: auto; color: #7b8c85; font-size: 9px; }
.package-required-note em { color: #dc3545; font-style: normal; font-weight: 800; }
#editModal[data-mode="add"] .package-required-note { display: inline; }

#editModal .package-form-section-head { margin: 0; }
#editModal .package-form-section-head h4 { margin: 0 0 3px; color: #31594c; font: 700 10px Poppins,sans-serif; letter-spacing: .08em; text-transform: uppercase; }
#editModal .package-form-section-head p { font-size: 9px; }
#editModal .package-general-heading { margin-top: 2px; padding-top: 16px; border-top: 1px solid #e3ebe8; }
#editModal .image-card-grid { grid-template-columns: repeat(4,minmax(0,1fr)); gap: 12px; }
#editModal .image-card-grid.general { grid-template-columns: repeat(2,minmax(0,1fr)); }
#editModal .package-image-card { padding: 10px; border-radius: 10px; background: #f8fbfa; }
#editModal .package-image-card-label { margin: 8px 1px 6px; font: 600 9px Poppins,sans-serif; }
#editModal .package-image-card .btn-green { min-height: 34px; padding: 7px 9px; border-radius: 7px; font: 600 9px Poppins,sans-serif; }
#editModal .itinerary-step { display: grid; grid-template-columns: 28px minmax(0,1fr); align-items: start; gap: 10px; margin: 0 0 12px; padding: 13px; border-radius: 10px; background: #fff; box-sizing: border-box; }
#editModal .drag-handle { width: 28px; min-height: 38px; display: grid; place-items: center; font-size: 18px; }
#editModal .step-content { min-width: 0; gap: 12px; }
#editModal .step-content .top-row { grid-template-columns: minmax(0,1.15fr) repeat(2,minmax(0,.85fr)); gap: 12px; }
#editModal .step-content .top-row .form-group { min-width: 0; }
#editModal .step-content input[type="time"] { width: 100%; min-width: 0; max-width: 100%; }
#editModal .itinerary-step .btn-red { width: max-content; margin: 0; padding: 7px 11px; border-radius: 7px; font: 600 9px Poppins,sans-serif; }
#editModal .package-add-step-bottom { width: max-content; margin: 0; padding: 8px 11px; border-radius: 7px; font: 600 9px Poppins,sans-serif; }
#editModal .package-form-actions { position: static; min-height: 68px; margin: 0; padding: 14px 20px; align-items: center; gap: 9px; border-top: 1px solid #e1ebe7; background: #fbfcfc; }
#editModal .package-form-actions button { min-width: 0; min-height: 42px; padding: 10px 17px; border-radius: 10px; font: 600 12px Poppins,sans-serif; }
#editModal .package-form-actions .btn-cancel { border: 0; background: #eef4f1; color: #345248; }
.package-wizard-action[hidden], #packageSubmitButton[hidden] { display: none !important; }

@media (max-width: 640px) {
    #editModal .modal-content { width: 100%; height: 100vh; max-height: 100vh; border-radius: 0; }
    #editModal .package-editor-header { padding: 14px; }
    #editModal[data-mode="edit"] .package-editor-layout { grid-template-columns: 1fr; grid-template-rows: auto minmax(0,1fr); }
    #editModal[data-mode="edit"] .package-editor-nav { display: flex; overflow-x: auto; padding: 10px; border-right: 0; border-bottom: 1px solid #e2ebe7; }
    #editModal[data-mode="edit"] .package-editor-nav button { width: auto; flex: 0 0 auto; }
    #editModal[data-mode="add"] .package-editor-nav { padding: 13px 12px 11px; }
    #editModal .package-form-body { padding: 14px; }
    #editModal .package-editor-pane[data-package-pane="information"] .package-form-grid { grid-template-columns: 1fr; }
    #editModal .package-editor-pane[data-package-pane="information"] .form-group:last-child { grid-column: auto; }
    #editModal .image-card-grid { grid-template-columns: repeat(2,minmax(0,1fr)); }
    #editModal .step-content .top-row { grid-template-columns: 1fr; }
    #editModal .package-form-actions { margin: 0; padding: 12px 14px; }
    #editModal .package-required-note { display: none !important; }
}
</style>
<link rel="stylesheet" href="styles/operator_header.css?v=5">
</head>

<body>
<div class="admin-container">
<?php include 'operator_sidebar.php'; ?>

<main class="op-main">
<header class="operator-header">
    <div class="operator-header-left">
        <span class="operator-header-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M4 7.5 12 3l8 4.5-8 4.5-8-4.5Z"/><path d="m4 12 8 4.5 8-4.5M4 16.5 12 21l8-4.5"/></svg></span>
        <div class="operator-header-copy"><h2>My Tour Packages</h2><p>Welcome, <?= htmlspecialchars((string)$operatorName) ?></p></div>
    </div>
    <div class="operator-header-right">
        <button type="button" class="op-header-action primary" onclick="openAvailabilityModal()" title="Manage package availability">
            <svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M8 3v4m8-4v4M3 10h18M8 14h2m4 0h2m-8 4h2"/></svg>
            <span>Availability</span>
        </button>
        <button type="button" class="op-header-action primary" onclick="openAddModal()">
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>
            <span>New package</span>
        </button>
        <div class="op-notif-wrap">
            <button type="button" class="op-notif-btn" id="opNotifToggle" aria-label="Notifications" aria-expanded="false">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9M10 21h4"/></svg><span class="op-header-sr">Notifications</span>
                <?php if ($notificationCount > 0): ?>
                    <span class="op-notif-badge"><?= $notificationCount ?></span>
                <?php endif; ?>
            </button>
            <div class="op-notif-panel" id="opNotifPanel">
                <h4>Recent Bookings</h4>
                <?php if (!empty($notificationItems)): ?>
                    <ul class="op-notif-list">
                        <?php foreach ($notificationItems as $item): ?>
                            <li>
                                <strong>#<?= (int)$item['booking_id'] ?> - <?= htmlspecialchars((string)$item['package_name']) ?></strong>
                                <span><?= htmlspecialchars((string)$item['status']) ?> • <?= htmlspecialchars((string)$item['booking_date']) ?></span>
                                <small><?= htmlspecialchars((string)$item['created_at']) ?></small>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php else: ?>
                    <p class="op-notif-empty">No notifications yet.</p>
                <?php endif; ?>
            </div>
        </div>
        <div class="op-topbar-profile" title="<?= htmlspecialchars((string)$operatorName) ?>" data-operator-header-profile role="button" tabindex="0" aria-label="Open operator profile">
            <?php if ($opHeaderProfilePic): ?>
                <img src="<?= htmlspecialchars($opHeaderProfilePic) ?>" alt="<?= htmlspecialchars((string)$operatorName) ?>">
            <?php else: ?>
                <?= htmlspecialchars($opProfileInitial) ?>
            <?php endif; ?>
        </div>
    </div>
</header>

<div class="dashboard-content">
<section class="package-summary-grid" aria-label="Package summary">
    <article class="package-summary-card"><span class="summary-icon"><svg viewBox="0 0 24 24"><path d="m4 7 8-4 8 4-8 4-8-4Z"/><path d="m4 12 8 4 8-4M4 17l8 4 8-4"/></svg></span><div><small>Current packages</small><strong><?= count($packages) ?></strong></div></article>
    <article class="package-summary-card"><span class="summary-icon"><svg viewBox="0 0 24 24"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M8 3v4m8-4v4M3 10h18"/></svg></span><div><small>Upcoming bookings</small><strong><?= (int)($packageSummary['upcoming_bookings'] ?? 0) ?></strong></div></article>
    <article class="package-summary-card"><span class="summary-icon"><svg viewBox="0 0 24 24"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/></svg></span><div><small>Reserved guests</small><strong><?= (int)($packageSummary['upcoming_guests'] ?? 0) ?></strong></div></article>
    <article class="package-summary-card"><span class="summary-icon"><svg viewBox="0 0 24 24"><path d="M12 2v20M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg></span><div><small>Booked value this month</small><strong>₱<?= number_format((float)($packageSummary['value_this_month'] ?? 0), 0) ?></strong></div></article>
</section>

<div class="package-workspace">
    <section id="tour-packages" class="packages-section">
        <div class="packages-head">
            <div><h3>Current Packages</h3><p style="margin:4px 0 0;color:#74847e;font-size:.72rem;">Manage pricing, details, itineraries, and booking capacity.</p></div>
            <div class="package-toolbar">
                <label class="package-search" aria-label="Search packages"><svg viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><path d="m20 20-4-4"/></svg><input type="search" id="packageSearch" placeholder="Search packages"></label>
                <select id="packageTypeFilter" aria-label="Filter package type"><option value="all">All types</option><option value="same-day">Same-day</option><option value="overnight">Overnight</option></select>
            </div>
        </div>
        <div class="cards-container" id="packageCardGrid">
            <?php foreach($packages as $package):
                $cardStats = $packageStats[(string)$package['package_title']] ?? [];
                $packageType = strtolower(trim((string)$package['package_type']));
            ?>
            <article class="package-card" data-package-card data-title="<?= htmlspecialchars(strtolower((string)$package['package_title'])) ?>" data-type="<?= htmlspecialchars($packageType) ?>">
                <img src="<?= resolveImagePath($package['package_image']); ?>" alt="<?= htmlspecialchars((string)$package['package_title']) ?> cover image">
                <div class="package-card-content">
                    <div class="package-card-topline"><span class="package-type-badge"><?= htmlspecialchars(str_replace('-', ' ', $packageType ?: 'Package')) ?></span><span style="font-size:.67rem;color:#7b8b85;"><?= htmlspecialchars((string)($package['package_range'] ?: 'Flexible duration')) ?></span></div>
                    <h4><?= htmlspecialchars($package['package_title']); ?></h4>
                    <p>₱<?= number_format((float)$package['price'],2); ?> <span style="font-size:.68rem;font-weight:600;color:#6f817a;">/ guest</span></p>
                    <div class="package-card-stats">
                        <div class="package-card-stat"><span>Upcoming</span><strong><?= (int)($cardStats['booking_count'] ?? 0) ?> bookings</strong></div>
                        <div class="package-card-stat"><span>Next tour</span><strong><?= !empty($cardStats['next_date']) ? date('M j, Y', strtotime((string)$cardStats['next_date'])) : 'No schedule' ?></strong></div>
                    </div>
                    <div class="package-card-actions">
                        <button class="p-edit-p-btn" onclick="openModal(<?= (int)$package['package_id']; ?>)">Edit package</button>
                        <button class="availability-btn" onclick="openAvailabilityModal(<?= (int)$package['package_id']; ?>)" title="Manage availability" aria-label="Manage package availability"><svg viewBox="0 0 24 24"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M8 3v4m8-4v4M3 10h18"/></svg></button>
                    </div>
                </div>
            </article>
            <?php endforeach; ?>
            <?php if (!$packages): ?><div class="empty-packages">No tour packages yet. Use <strong>New package</strong> to create your first offer.</div><?php endif; ?>
            <div class="package-no-results" id="packageNoResults">No packages match your search or filter.</div>
        </div>
    </section>

    <aside class="package-insights" aria-label="Booking insights">
        <section class="insight-card"><div class="insight-head"><div><h3>Booked dates</h3><p>Reserved guests over the next 14 days</p></div><span class="insight-kicker">Live schedule</span></div><div class="chart-wrap"><canvas id="bookedDatesChart" aria-label="Reserved guests by booked date"></canvas></div></section>
        <section class="insight-card">
            <div class="insight-head"><div><h3>Upcoming date load</h3><p>Quick view of the next seven days</p></div></div>
            <?php $maxDateGuests = max(1, ...array_map(static fn($item) => (int)$item['guests'], array_slice($bookingDateChart, 0, 7))); ?>
            <div class="date-load-list">
                <?php foreach (array_slice($bookingDateChart, 0, 7) as $dateItem): ?>
                    <div class="date-load-row"><time datetime="<?= htmlspecialchars($dateItem['date']) ?>"><?= htmlspecialchars($dateItem['label']) ?></time><div class="date-load-track"><div class="date-load-fill" style="width:<?= max(3, round(((int)$dateItem['guests'] / $maxDateGuests) * 100)) ?>%"></div></div><strong><?= (int)$dateItem['guests'] ?> guests</strong></div>
                <?php endforeach; ?>
            </div>
        </section>
        <section class="insight-card"><div class="insight-head"><div><h3>Booking trend</h3><p>Bookings created during the last six months</p></div><span class="insight-kicker"><?= (int)($packageSummary['bookings_this_month'] ?? 0) ?> this month</span></div><div class="chart-wrap" style="height:150px;"><canvas id="bookingTrendChart" aria-label="Six month booking trend"></canvas></div></section>
        <section class="insight-card availability-brief"><span class="summary-icon"><svg viewBox="0 0 24 24"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M8 3v4m8-4v4M3 10h18M8 15l2 2 5-5"/></svg></span><div><strong><?= $openSlotDays ?></strong><p>future open package dates configured</p></div><button type="button" class="calendar-nav-btn" onclick="openAvailabilityModal()" title="Manage availability">→</button></section>
    </aside>
</div>
</div>
</main>
</div>

<!-- Add / Edit Package Modal -->
<div id="editModal" class="modal" role="dialog" aria-modal="true" aria-labelledby="af-media-modal-title">
    <div class="modal-content">
        <div class="modal-section-head package-editor-header">
            <span class="package-editor-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="m4 6 5-2 6 2 5-2v14l-5 2-6-2-5 2V6Z"/><path d="M9 4v14M15 6v14"/></svg></span>
            <div><small id="packageEditorEyebrow">PACKAGE EDITOR</small><strong id="af-media-modal-title">Edit package</strong><p id="packageModalSubtitle">Update the package details, photos, and itinerary.</p></div>
            <button type="button" class="close" onclick="closeModal()" aria-label="Close package form">&times;</button>
        </div>
        <form id="editPackageForm" method="post" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($operatorPackageCsrf, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="package_id" id="package_id">
            <div class="package-editor-layout">
                <nav class="package-editor-nav" aria-label="Package editor sections">
                    <button type="button" class="active" data-package-tab="information"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 4h16v16H4z"/><path d="M8 9h8M8 13h8M8 17h5"/></svg><span>Information</span></button>
                    <button type="button" data-package-tab="media"><svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2"/><circle cx="9" cy="10" r="2"/><path d="m4 17 5-4 3 2 3-4 5 6"/></svg><span>Media</span></button>
                    <button type="button" data-package-tab="itinerary"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 4h14v16H5z"/><path d="M9 8h6M9 12h6M9 16h4"/></svg><span>Itinerary</span></button>
                </nav>
                <div class="package-form-body">
                <section class="package-editor-pane active" data-package-pane="information">
                    <div class="package-form-grid">
                        <div class="form-group">
                            <label for="package_title">Package title <span class="field-required">*</span></label>
                            <input type="text" name="package_title" id="package_title" class="form-input" placeholder="e.g. Explore Mercedes – Overnight" required>
                        </div>
                        <div class="form-group">
                            <label for="price">Price per guest (PHP) <span class="field-required">*</span></label>
                            <input type="number" name="price" id="price" class="form-input" placeholder="0.00" min="0" step="0.01" required>
                        </div>
                        <div class="form-group">
                            <label for="package_type">Package type <span class="field-required">*</span></label>
                            <select name="package_type" id="package_type" class="form-input" required>
                                <option value="">Select a package type</option>
                                <option value="same-day">Same-day</option>
                                <option value="overnight">Overnight</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="package_range">Duration</label>
                            <input type="text" name="package_range" id="package_range" class="form-input" placeholder="e.g. 2 days and 1 night">
                        </div>
                    </div>
                </section>

                <section class="package-editor-pane" data-package-pane="media">
                    <div class="package-form-section-head">
                        <div><h4>Package gallery</h4><p>Add up to four clear photos. The first photo is used as the package cover.</p></div>
                    </div>
                    <div id="packageImagesContainer" class="image-card-grid"></div>
                    <div class="package-form-section-head package-general-heading">
                        <div><h4>Location and route</h4><p>These reference images are displayed with the package itinerary.</p></div>
                    </div>
                    <div id="generalImagesContainer" class="image-card-grid general">
                        <div class="package-image-card">
                            <img id="location_image_preview" src="img/placeholder.png" alt="Location preview">
                            <span class="package-image-card-label">Location image</span>
                            <button type="button" class="btn-green" onclick="openGeneralImageModal('location_image')">Choose image</button>
                        </div>
                        <div class="package-image-card">
                            <img id="route_image_preview" src="img/placeholder.png" alt="Route preview">
                            <span class="package-image-card-label">Route image</span>
                            <button type="button" class="btn-green" onclick="openGeneralImageModal('route_image')">Choose image</button>
                        </div>
                    </div>
                </section>

                <section class="package-editor-pane" data-package-pane="itinerary">
                    <div class="package-form-section-head">
                        <div><h4>Itinerary</h4><p>Arrange activities in order. Drag a step by its handle to reorder it.</p></div>
                    </div>
                    <div id="itineraryContainer"></div>
                    <button type="button" class="add-step-btn package-add-step-bottom" onclick="addItineraryStep()">+ Add itinerary step</button>
                </section>
                </div>
            </div>
            <div class="package-form-actions">
                <span class="package-required-note">Fields marked <em>*</em> are required.</span>
                <button type="button" class="btn-cancel" onclick="closeModal()">Cancel</button>
                <button type="button" class="btn-cancel package-wizard-action" id="packagePrevious" hidden>Previous</button>
                <button type="button" class="btn-green package-wizard-action" id="packageNext" hidden>Next step</button>
                <button type="submit" class="btn-green" id="packageSubmitButton">Save changes</button>
            </div>
        </form>
    </div>
</div>

<!-- Package Availability Modal -->
<div id="availabilityModal" class="modal" role="dialog" aria-modal="true" aria-labelledby="availabilityModalTitle">
    <div class="modal-content">
        <div class="modal-section-head">
            <div><strong id="availabilityModalTitle">Package Availability</strong><p>Open dates and set daily guest limits. Dates without a custom limit use the existing default availability.</p></div>
            <button type="button" class="close" onclick="closeAvailabilityModal()" aria-label="Close availability calendar">&times;</button>
        </div>
        <div class="availability-body">
            <div class="availability-controls">
                <div class="form-group">
                    <label for="availabilityPackage">Tour package</label>
                    <select id="availabilityPackage" class="form-input">
                        <?php foreach ($packages as $package): ?><option value="<?= (int)$package['package_id'] ?>"><?= htmlspecialchars((string)$package['package_title']) ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="calendar-month-nav">
                    <button type="button" class="calendar-nav-btn" id="calendarPrevMonth" aria-label="Previous month">‹</button>
                    <strong id="calendarMonthLabel"></strong>
                    <button type="button" class="calendar-nav-btn" id="calendarNextMonth" aria-label="Next month">›</button>
                </div>
            </div>
            <div class="availability-layout">
                <div>
                    <div class="slot-calendar">
                        <div class="calendar-weekdays"><span>Sun</span><span>Mon</span><span>Tue</span><span>Wed</span><span>Thu</span><span>Fri</span><span>Sat</span></div>
                        <div class="calendar-days" id="availabilityCalendarDays"></div>
                    </div>
                    <div class="calendar-legend"><span><i style="background:#32a276"></i>Open</span><span><i style="background:#d69b32"></i>Full</span><span><i style="background:#bd4352"></i>Closed</span><span><i style="background:#b8c5c0"></i>Default availability</span></div>
                </div>
                <aside class="slot-editor" id="slotEditor">
                    <h4 id="slotEditorDate">Select a date</h4>
                    <p>Choose a future date from the calendar to configure its guest limit.</p>
                    <div class="slot-metric"><div><span>Guests booked</span><strong id="slotBookedGuests">—</strong></div><div><span>Slots remaining</span><strong id="slotRemainingGuests">—</strong></div></div>
                    <div class="form-group"><label for="slotCapacity">Maximum guests</label><input class="form-input" type="number" id="slotCapacity" min="1" max="500" value="20" disabled></div>
                    <label class="slot-toggle"><input type="checkbox" id="slotIsOpen" checked disabled> Accept bookings on this date</label>
                    <div class="slot-editor-actions"><button type="button" class="slot-clear" id="slotClearButton" disabled>Use default</button><button type="button" class="slot-save" id="slotSaveButton" disabled>Save date</button></div>
                </aside>
            </div>
        </div>
    </div>
</div>

<!-- Image Upload & Crop Modal -->
<div id="imageModal" class="modal">
    <div class="modal-content">
        <span class="close" onclick="closeImageModal()">&times;</span>
        <h3 class="image-modal-title">Upload & Crop Image</h3>

        <!-- Custom Drag Area -->
        <div class="custum-file-upload" id="dragArea">
            <div class="icon">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24">
                    <path d="M10 1L3 7v13c0 1.7 1.3 3 3 3h1"/>
                </svg>
            </div>
            <div class="text">
                <span>Drag & drop image here or click</span>
            </div>
            <input type="file" id="imageInput" accept="image/*" hidden>
        </div>

        <!-- Cropper Container -->
        <div id="cropContainer" style="display:none; margin-top:20px; text-align:center;">
            <div style="width:100%; max-width:500px; height:350px; margin:0 auto; border:1px solid #ddd; border-radius:5px; overflow:hidden;">
                <img id="cropImage" style="width:100%; height:100%; object-fit:contain;">
            </div>
        </div>

        <!-- Controls -->
        <div style="margin-top:10px; display:flex; gap:10px; justify-content:center;">
            <button id="doneBtn" class="btn btn-green" style="display:none;">Done</button>
            <button id="cancelBtn" class="btn btn-red" style="display:none;">Cancel</button>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/cropperjs@1.5.13/dist/cropper.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.0/Sortable.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.7/dist/chart.umd.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>

<script>
// ===============================
// Packages Data (JS)
let packagesData = <?php
$jsPackages = [];
foreach($packages as $p){
    $packageId = $p['package_id'];

    // Get first itinerary step for general images
    $stmtIt = $pdo->prepare("SELECT location_image, route_image FROM package_itinerary WHERE package_id=? ORDER BY display_order ASC LIMIT 1");
    $stmtIt->execute([$packageId]);
    $firstStep = $stmtIt->fetch(PDO::FETCH_ASSOC) ?: [];

    // Get all itinerary steps
    $stmtSteps = $pdo->prepare("SELECT * FROM package_itinerary WHERE package_id=? ORDER BY display_order ASC");
    $stmtSteps->execute([$packageId]);
    $steps = $stmtSteps->fetchAll(PDO::FETCH_ASSOC);

    $jsPackages[$packageId] = [
        'package_title'=>$p['package_title'],
        'package_image'=>resolveImagePath($p['package_image']),
        'price' => $p['price'], // ← Add this line
        'package_image2'=>resolveImagePath($p['package_image2']),
        'package_image3'=>resolveImagePath($p['package_image3']),
        'package_image4'=>resolveImagePath($p['package_image4']),
        'location_image'=>resolveImagePath($firstStep['location_image'] ?? ''),
        'route_image'=>resolveImagePath($firstStep['route_image'] ?? ''),
        'package_type'=>$p['package_type'],
        'package_range'=>$p['package_range'],
        'itinerary'=>$steps
    ];
}
echo json_encode($jsPackages, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
?>;

/* =====================================================
   GLOBAL STATE
===================================================== */
let currentPackageId = null;
let cropper = null;
let currentImageField = null;

/* =====================================================
   DOM ELEMENTS (DECLARE ONCE)
===================================================== */
const editModal = document.getElementById('editModal');
const imageModal = document.getElementById('imageModal');
const dragArea = document.getElementById('dragArea');
const imageInput = document.getElementById('imageInput');
const cropContainer = document.getElementById('cropContainer');
const cropImage = document.getElementById('cropImage');
const doneBtn = document.getElementById('doneBtn');
const cancelBtn = document.getElementById('cancelBtn');
const itineraryContainer = document.getElementById('itineraryContainer');
const availabilityModal = document.getElementById('availabilityModal');
const packageEditorSteps = ['information', 'media', 'itinerary'];

function switchPackageEditorPane(name) {
    const index = Math.max(0, packageEditorSteps.indexOf(name));
    const isAdd = editModal.dataset.mode === 'add';
    document.querySelectorAll('[data-package-tab]').forEach((button, buttonIndex) => {
        const active = button.dataset.packageTab === name;
        button.classList.toggle('active', active);
        button.classList.toggle('completed', isAdd && buttonIndex < index);
        if (active) button.setAttribute('aria-current', 'step');
        else button.removeAttribute('aria-current');
    });
    document.querySelectorAll('[data-package-pane]').forEach((pane) => pane.classList.toggle('active', pane.dataset.packagePane === name));
    const previous = document.getElementById('packagePrevious');
    const next = document.getElementById('packageNext');
    const submit = document.getElementById('packageSubmitButton');
    previous.hidden = !isAdd || index === 0;
    next.hidden = !isAdd || index === packageEditorSteps.length - 1;
    submit.hidden = isAdd && index !== packageEditorSteps.length - 1;
    submit.innerText = isAdd ? 'Create package' : 'Save changes';
    const body = editModal.querySelector('.package-form-body');
    if (body) body.scrollTop = 0;
}

document.querySelectorAll('[data-package-tab]').forEach((button) => button.addEventListener('click', () => {
    const target = packageEditorSteps.indexOf(button.dataset.packageTab);
    const current = packageEditorSteps.indexOf(document.querySelector('[data-package-tab].active')?.dataset.packageTab || 'information');
    if (editModal.dataset.mode === 'add' && target > current) {
        document.getElementById('packageNext').click();
        return;
    }
    switchPackageEditorPane(button.dataset.packageTab);
}));
document.getElementById('packagePrevious').addEventListener('click', () => {
    const current = packageEditorSteps.indexOf(document.querySelector('[data-package-tab].active')?.dataset.packageTab || 'information');
    switchPackageEditorPane(packageEditorSteps[Math.max(0, current - 1)]);
});
document.getElementById('packageNext').addEventListener('click', () => {
    const currentName = document.querySelector('[data-package-tab].active')?.dataset.packageTab || 'information';
    const pane = document.querySelector(`[data-package-pane="${currentName}"]`);
    const invalid = Array.from(pane?.querySelectorAll('[required]') || []).find((field) => !field.checkValidity());
    if (invalid) { invalid.reportValidity(); return; }
    const current = packageEditorSteps.indexOf(currentName);
    switchPackageEditorPane(packageEditorSteps[Math.min(packageEditorSteps.length - 1, current + 1)]);
});

/* =====================================================
   MODAL OPEN / CLOSE
===================================================== */

// ======= OPEN EDIT MODAL =======
function openModal(packageId){
    currentPackageId = packageId;
    const pkg = packagesData[packageId];
    if(!pkg) return;

    editModal.dataset.mode = 'edit';
    document.getElementById('packageEditorEyebrow').innerText = 'PACKAGE EDITOR';
    document.getElementById('af-media-modal-title').innerText = 'Edit package';
    document.getElementById('packageModalSubtitle').innerText = 'Update the package details, photos, and itinerary.';
    document.getElementById('packageSubmitButton').innerText = 'Save changes';
    editModal.style.display = 'flex';
    editModal.querySelector('.modal-content').scrollTop = 0;
    document.body.classList.add('modal-open');

    document.getElementById('package_id').value = packageId;
    document.getElementById('package_title').value = pkg.package_title || '';
    document.getElementById('price').value = pkg.price || '';
    document.getElementById('package_type').value = pkg.package_type || '';
    document.getElementById('package_range').value = pkg.package_range || '';

    renderPackageImages(pkg);
    renderGeneralImages(pkg);
    renderItinerary(pkg);
    switchPackageEditorPane('information');
}

function closeModal(){
    editModal.style.display = 'none';
    itineraryContainer.innerHTML = '';
    if (imageModal.style.display !== 'flex') document.body.classList.remove('modal-open');
}

/* =====================================================
   ADD PACKAGE
===================================================== */
// ======= OPEN ADD PACKAGE =======
function openAddModal(){
    currentPackageId = 0;
    editModal.dataset.mode = 'add';
    editModal.style.display = 'flex';
    document.getElementById('packageEditorEyebrow').innerText = 'NEW TOUR PACKAGE';
    document.getElementById('af-media-modal-title').innerText = 'Add tour package';
    document.getElementById('packageModalSubtitle').innerText = 'Create a complete package with photos and a clear itinerary.';
    document.getElementById('packageSubmitButton').innerText = 'Create package';
    document.getElementById('editPackageForm').reset();
    document.getElementById('package_id').value = 0;

    renderEmptyPackageImages();
    document.getElementById('location_image_preview').src = 'img/placeholder.png';
    document.getElementById('route_image_preview').src = 'img/placeholder.png';

    itineraryContainer.innerHTML = '';
    addItineraryStep(); // Always start with 1 empty step
    if(window.itinerarySortable) window.itinerarySortable.destroy();
    window.itinerarySortable = new Sortable(itineraryContainer, {
        handle: '.drag-handle',
        animation: 150,
        onEnd: updateDisplayOrders
    });
    updateDisplayOrders();
    switchPackageEditorPane('information');
    document.body.classList.add('modal-open');
}



/* =====================================================
   PACKAGE IMAGES
===================================================== */
function renderPackageImages(pkg){
    const container = document.getElementById('packageImagesContainer');
    container.innerHTML = '';

    ['package_image','package_image2','package_image3','package_image4'].forEach((field, index)=>{
        const imgSrc = pkg[field] || 'img/placeholder.png';
        const wrap = document.createElement('div');
        wrap.className = 'package-image-card';
        wrap.innerHTML = `
            <img src="${imgSrc}" data-field="${field}" alt="Package photo ${index + 1}">
            <span class="package-image-card-label">${index === 0 ? 'Cover photo' : `Gallery photo ${index + 1}`}</span>
            <button type="button" class="btn-green" onclick="openGeneralImageModal('${field}')">Choose image</button>
        `;
        container.appendChild(wrap);
    });
}

function renderGeneralImages(pkg){
    document.getElementById('location_image_preview').src = pkg.location_image || 'img/placeholder.png';
    document.getElementById('route_image_preview').src = pkg.route_image || 'img/placeholder.png';
}

/* =====================================================
   ITINERARY
===================================================== */

// ======= RENDER ITINERARY =======
function renderItinerary(pkg){
    itineraryContainer.innerHTML = '';

    if(pkg.itinerary && pkg.itinerary.length){
        pkg.itinerary.forEach(step => addItineraryStep(step));
    } else {
        addItineraryStep();
    }

    // Destroy previous Sortable instance if exists
    if(window.itinerarySortable) window.itinerarySortable.destroy();

    // Create new Sortable instance
    window.itinerarySortable = new Sortable(itineraryContainer, {
        handle: '.drag-handle',
        animation: 150,
        onEnd: updateDisplayOrders // ✅ Update display_order after dragging
    });

    // Initialize display_order values
    updateDisplayOrders();
}

// ======= ADD ITINERARY STEP =======
function addItineraryStep(step = {}) {
    const div = document.createElement('div');
    div.className = 'itinerary-step';
    div.innerHTML = `
        <input type="hidden" name="itinerary_id[]" value="${step.itinerary_id || ''}">
        <input type="hidden" name="display_order[]" value="${step.display_order || ''}">
        <div class="drag-handle" title="Drag to reorder" aria-label="Drag to reorder">&#8942;&#8942;</div>
        <div class="step-content">
            <div class="top-row">
                <div class="form-group">
                    <label>Step Title <span class="field-required">*</span></label>
                    <input type="text" name="step_title[]" value="${step.step_title || ''}" ${editModal.dataset.mode === 'add' ? 'required' : ''}>
                </div>
                <div class="form-group">
                    <label>Start Time</label>
                    <input type="time" name="start_time[]" value="${step.start_time || ''}">
                </div>
                <div class="form-group">
                    <label>End Time</label>
                    <input type="time" name="end_time[]" value="${step.end_time || ''}">
                </div>
            </div>
            <div class="form-group">
                <label>Description</label>
                <textarea name="description[]">${step.description || ''}</textarea>
            </div>
            <button type="button" class="btn-red" onclick="this.closest('.itinerary-step').remove()">Remove Step</button>
        </div>
    `;
    itineraryContainer.appendChild(div);
}

function updateDisplayOrders() {
    document.querySelectorAll('.itinerary-step').forEach((step, index) => {
        const input = step.querySelector('input[name="display_order[]"]');
        if (input) input.value = index + 1; // top step = 1, next = 2...
    });
}



/* =====================================================
   IMAGE MODAL
===================================================== */
// ======= IMAGE MODAL =======
function openGeneralImageModal(field){
    currentImageField = field;
    imageInput.value = '';
    dragArea.style.display = 'flex';
    cropContainer.style.display = 'none';
    doneBtn.style.display = 'none';
    cancelBtn.style.display = 'none';
    if(cropper){ cropper.destroy(); cropper = null; }
    imageModal.style.display = 'flex';
    document.body.classList.add('modal-open');
}

function closeImageModal(){
    imageModal.style.display = 'none';
    imageInput.value = '';

    if(cropper){ cropper.destroy(); cropper = null; }

    dragArea.style.display = 'flex';
    cropContainer.style.display = 'none';
    doneBtn.style.display = 'none';
    cancelBtn.style.display = 'none';
    if (editModal.style.display !== 'flex') document.body.classList.remove('modal-open');
}

/* =====================================================
   DRAG & DROP
===================================================== */
dragArea.addEventListener('click',()=>imageInput.click());

dragArea.addEventListener('dragover',e=>{
    e.preventDefault();
    dragArea.classList.add('dragover');
});

dragArea.addEventListener('dragleave',()=>dragArea.classList.remove('dragover'));

dragArea.addEventListener('drop',e=>{
    e.preventDefault();
    dragArea.classList.remove('dragover');
    if(!e.dataTransfer.files.length) return;
    imageInput.files = e.dataTransfer.files;
    imageInput.dispatchEvent(new Event('change',{bubbles:true}));
});

/* =====================================================
   IMAGE INPUT
===================================================== */
imageInput.addEventListener('change',()=>{
    const file = imageInput.files[0];
    if(!file) return;

    cropImage.src = URL.createObjectURL(file);
    dragArea.style.display = 'none';
    cropContainer.style.display = 'block';
    doneBtn.style.display = 'inline-block';
    cancelBtn.style.display = 'inline-block';

    if(cropper) cropper.destroy();
    cropper = new Cropper(cropImage,{aspectRatio:4/3,viewMode:1});
});

/* =====================================================
   CROP CONFIRM
===================================================== */
doneBtn.addEventListener('click',()=>{
    if(!cropper || !currentImageField) return;
    const canvas = cropper.getCroppedCanvas({width:600,height:400});
    const dataUrl = canvas.toDataURL('image/jpeg');

    if(currentImageField.startsWith('package_image')){
        const img = document.querySelector(`img[data-field="${currentImageField}"]`);
        if(img) img.src = dataUrl;
    } else if(currentImageField === 'location_image'){
        document.getElementById('location_image_preview').src = dataUrl;
    } else if(currentImageField === 'route_image'){
        document.getElementById('route_image_preview').src = dataUrl;
    }
    closeImageModal();
});

cancelBtn.addEventListener('click',closeImageModal);

/* =====================================================
   SUBMIT
===================================================== */
// ======= FORM SUBMIT =======
document.getElementById('editPackageForm').addEventListener('submit', e => {
    e.preventDefault();
    const activePane = document.querySelector('[data-package-tab].active')?.dataset.packageTab || 'information';
    if (editModal.dataset.mode === 'add' && activePane !== 'itinerary') {
        document.getElementById('packageNext').click();
        return;
    }
    updateDisplayOrders();
    const formData = new FormData(e.target);
    const submitButton = document.getElementById('packageSubmitButton');
    const originalButtonText = submitButton.innerText;
    submitButton.disabled = true;
    submitButton.innerText = 'Saving...';

    document.querySelectorAll('#packageImagesContainer img').forEach(img => {
        formData.append(img.dataset.field + '_data', img.src);
    });
    formData.append('location_image_data', document.getElementById('location_image_preview').src);
    formData.append('route_image_data', document.getElementById('route_image_preview').src);

    fetch('php/update_operator_package.php', {
        method: 'POST',
        body: formData
    }).then(r => r.json())
    .then(d => {
        if (d.success) {
            Swal.fire({
                title: 'Success',
                text: 'Package saved',
                icon: 'success',
                confirmButtonText: 'OK',
                customClass: {
                    confirmButton: 'swal2-confirm-green'
                }
            }).then(() => location.reload());
        } else {
            Swal.fire({
                title: 'Error',
                text: d.message,
                icon: 'error',
                confirmButtonText: 'OK',
                customClass: {
                    confirmButton: 'swal2-confirm-green'
                }
            });
        }
    }).catch(() => {
        Swal.fire({
            title: 'Unable to save package',
            text: 'Please check your connection and try again.',
            icon: 'error',
            confirmButtonText: 'OK',
            customClass: { confirmButton: 'swal2-confirm-green' }
        });
    }).finally(() => {
        submitButton.disabled = false;
        submitButton.innerText = originalButtonText;
    });
});

function renderEmptyPackageImages(){
    const container = document.getElementById('packageImagesContainer');
    container.innerHTML = '';

    ['package_image','package_image2','package_image3','package_image4'].forEach((field, index)=>{
        const wrap = document.createElement('div');
        wrap.className = 'package-image-card';
        wrap.innerHTML = `
            <img src="img/placeholder.png"
                 data-field="${field}"
                 alt="Package photo ${index + 1}">
            <span class="package-image-card-label">${index === 0 ? 'Cover photo' : `Gallery photo ${index + 1}`}</span>
            <button type="button"
                    class="btn-green"
                    onclick="openGeneralImageModal('${field}')">
                Choose image
            </button>
        `;
        container.appendChild(wrap);
    });
}

/* =====================================================
   PACKAGE DASHBOARD TOOLS
===================================================== */
const packageSearch = document.getElementById('packageSearch');
const packageTypeFilter = document.getElementById('packageTypeFilter');
const packageCards = [...document.querySelectorAll('[data-package-card]')];
const packageNoResults = document.getElementById('packageNoResults');

function filterPackageCards() {
    const query = (packageSearch?.value || '').trim().toLowerCase();
    const type = packageTypeFilter?.value || 'all';
    let visible = 0;
    packageCards.forEach(card => {
        const matches = (!query || card.dataset.title.includes(query)) && (type === 'all' || card.dataset.type === type);
        card.style.display = matches ? '' : 'none';
        if (matches) visible++;
    });
    if (packageNoResults) packageNoResults.style.display = packageCards.length && visible === 0 ? 'block' : 'none';
}
packageSearch?.addEventListener('input', filterPackageCards);
packageTypeFilter?.addEventListener('change', filterPackageCards);

if (typeof Chart === 'function') {
    const chartGrid = 'rgba(41, 89, 74, .08)';
    const chartTicks = '#71827c';
    const bookedDates = <?= json_encode($bookingDateChart, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const monthlyBookings = <?= json_encode($monthlyTrend, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const bookedCanvas = document.getElementById('bookedDatesChart');
    const trendCanvas = document.getElementById('bookingTrendChart');
    if (bookedCanvas) new Chart(bookedCanvas, {
        type: 'bar',
        data: { labels: bookedDates.map(item => item.label), datasets: [{ label: 'Guests', data: bookedDates.map(item => item.guests), backgroundColor: '#3b8b73', borderRadius: 5, maxBarThickness: 18 }] },
        options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false }, tooltip: { callbacks: { afterLabel: context => `${bookedDates[context.dataIndex].bookings} booking(s)` } } }, scales: { x: { grid: { display: false }, ticks: { color: chartTicks, maxRotation: 0, autoSkip: true, maxTicksLimit: 7, font: { size: 9 } } }, y: { beginAtZero: true, ticks: { color: chartTicks, precision: 0, font: { size: 9 } }, grid: { color: chartGrid } } } }
    });
    if (trendCanvas) new Chart(trendCanvas, {
        type: 'line',
        data: { labels: monthlyBookings.map(item => item.label), datasets: [{ data: monthlyBookings.map(item => item.count), borderColor: '#2b7a66', backgroundColor: 'rgba(43,122,102,.12)', fill: true, tension: .35, pointRadius: 3, pointBackgroundColor: '#2b7a66' }] },
        options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { x: { grid: { display: false }, ticks: { color: chartTicks, font: { size: 9 } } }, y: { beginAtZero: true, ticks: { color: chartTicks, precision: 0, font: { size: 9 } }, grid: { color: chartGrid } } } }
    });
}

/* =====================================================
   DAILY PACKAGE AVAILABILITY
===================================================== */
const availabilityPackage = document.getElementById('availabilityPackage');
const calendarDays = document.getElementById('availabilityCalendarDays');
const calendarMonthLabel = document.getElementById('calendarMonthLabel');
const slotCapacity = document.getElementById('slotCapacity');
const slotIsOpen = document.getElementById('slotIsOpen');
const slotSaveButton = document.getElementById('slotSaveButton');
const slotClearButton = document.getElementById('slotClearButton');
const slotEditorDate = document.getElementById('slotEditorDate');
const slotBookedGuests = document.getElementById('slotBookedGuests');
const slotRemainingGuests = document.getElementById('slotRemainingGuests');
const packageSlotCsrf = <?= json_encode($packageSlotCsrf, JSON_HEX_TAG) ?>;
let availabilityMonth = new Date(new Date().getFullYear(), new Date().getMonth(), 1);
let availabilitySlots = {};
let selectedSlotDate = '';

const localDateKey = date => `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
const currentDateKey = localDateKey(new Date());
const availabilityMonthKey = () => `${availabilityMonth.getFullYear()}-${String(availabilityMonth.getMonth() + 1).padStart(2, '0')}`;

function openAvailabilityModal(packageId = 0) {
    if (!availabilityPackage || !availabilityPackage.options.length) {
        Swal.fire({ title: 'Create a package first', text: 'Availability can be configured after you add a tour package.', icon: 'info', confirmButtonText: 'OK', customClass: { confirmButton: 'swal2-confirm-green' } });
        return;
    }
    if (packageId) availabilityPackage.value = String(packageId);
    availabilityMonth = new Date(new Date().getFullYear(), new Date().getMonth(), 1);
    selectedSlotDate = '';
    availabilityModal.style.display = 'flex';
    availabilityModal.querySelector('.modal-content').scrollTop = 0;
    document.body.classList.add('modal-open');
    resetSlotEditor();
    loadAvailabilityMonth();
}

function closeAvailabilityModal() {
    availabilityModal.style.display = 'none';
    document.body.classList.remove('modal-open');
}

function resetSlotEditor() {
    selectedSlotDate = '';
    slotEditorDate.textContent = 'Select a date';
    slotBookedGuests.textContent = '—';
    slotRemainingGuests.textContent = '—';
    slotCapacity.value = '20';
    slotIsOpen.checked = true;
    [slotCapacity, slotIsOpen, slotSaveButton, slotClearButton].forEach(control => control.disabled = true);
}

async function loadAvailabilityMonth() {
    calendarMonthLabel.textContent = availabilityMonth.toLocaleDateString('en-PH', { month: 'long', year: 'numeric' });
    calendarDays.innerHTML = '<div style="grid-column:1/-1;padding:38px;text-align:center;color:#71827c;font-size:.75rem;">Loading availability…</div>';
    try {
        const params = new URLSearchParams({ op_action: 'package_slot_month', package_id: availabilityPackage.value, month: availabilityMonthKey() });
        const response = await fetch(`optourpackages.php?${params}`, { headers: { Accept: 'application/json' } });
        const result = await response.json();
        if (!response.ok || !result.success) throw new Error(result.message || 'Availability could not be loaded.');
        availabilitySlots = result.slots || {};
        renderAvailabilityCalendar();
    } catch (error) {
        calendarDays.innerHTML = '';
        const message = document.createElement('div');
        message.style.cssText = 'grid-column:1/-1;padding:38px;text-align:center;color:#a52d3b;font-size:.75rem;';
        message.textContent = error.message;
        calendarDays.appendChild(message);
    }
}

function renderAvailabilityCalendar() {
    calendarDays.innerHTML = '';
    const first = new Date(availabilityMonth.getFullYear(), availabilityMonth.getMonth(), 1);
    const gridStart = new Date(first);
    gridStart.setDate(1 - first.getDay());
    for (let index = 0; index < 42; index++) {
        const date = new Date(gridStart);
        date.setDate(gridStart.getDate() + index);
        const key = localDateKey(date);
        const slot = availabilitySlots[key];
        const isOther = date.getMonth() !== availabilityMonth.getMonth();
        const isPast = key < currentDateKey;
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'calendar-day';
        if (isOther) button.classList.add('is-other');
        if (isPast) button.classList.add('is-past');
        if (key === selectedSlotDate) button.classList.add('is-selected');
        let detail = 'Default';
        if (slot) {
            const booked = Number(slot.booked || 0);
            if (slot.capacity === null) {
                if (booked > 0) button.classList.add('is-full');
                detail = booked > 0 ? `${booked} booked` : 'Default';
            } else if (!slot.is_open) {
                button.classList.add('is-closed'); detail = 'Closed';
            } else if (Number(slot.remaining) <= 0) {
                button.classList.add('is-full'); detail = 'Full';
            } else {
                button.classList.add('is-open'); detail = `${slot.remaining} open`;
            }
        }
        button.innerHTML = `<b>${date.getDate()}</b><small>${detail}</small>`;
        button.disabled = isPast || isOther;
        if (!button.disabled) button.addEventListener('click', () => selectAvailabilityDate(key));
        calendarDays.appendChild(button);
    }
}

function selectAvailabilityDate(key) {
    selectedSlotDate = key;
    const slot = availabilitySlots[key] || { capacity: null, is_open: true, booked: 0, remaining: null };
    const parsed = new Date(`${key}T00:00:00`);
    slotEditorDate.textContent = parsed.toLocaleDateString('en-PH', { weekday: 'long', month: 'long', day: 'numeric', year: 'numeric' });
    slotBookedGuests.textContent = String(slot.booked || 0);
    slotRemainingGuests.textContent = slot.remaining === null ? 'Default' : String(slot.remaining);
    slotCapacity.value = String(slot.capacity === null ? Math.max(20, Number(slot.booked || 0)) : slot.capacity);
    slotIsOpen.checked = slot.is_open !== false;
    [slotCapacity, slotIsOpen, slotSaveButton].forEach(control => control.disabled = false);
    slotClearButton.disabled = slot.capacity === null;
    renderAvailabilityCalendar();
}

async function submitSlotAction(action) {
    if (!selectedSlotDate) return;
    const body = new URLSearchParams({ op_action: action, csrf_token: packageSlotCsrf, package_id: availabilityPackage.value, slot_date: selectedSlotDate, capacity: slotCapacity.value, is_open: slotIsOpen.checked ? '1' : '0' });
    slotSaveButton.disabled = true;
    slotClearButton.disabled = true;
    try {
        const response = await fetch('optourpackages.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded', Accept: 'application/json' }, body: body.toString() });
        const result = await response.json();
        if (!response.ok || !result.success) throw new Error(result.message || 'The date could not be saved.');
        await loadAvailabilityMonth();
        selectAvailabilityDate(selectedSlotDate);
        Swal.fire({ toast: true, position: 'top-end', title: result.message, icon: 'success', showConfirmButton: false, timer: 2200 });
    } catch (error) {
        Swal.fire({ title: 'Availability not saved', text: error.message, icon: 'error', confirmButtonText: 'OK', customClass: { confirmButton: 'swal2-confirm-green' } });
        slotSaveButton.disabled = false;
    }
}

availabilityPackage?.addEventListener('change', () => { resetSlotEditor(); loadAvailabilityMonth(); });
document.getElementById('calendarPrevMonth')?.addEventListener('click', () => { availabilityMonth.setMonth(availabilityMonth.getMonth() - 1); resetSlotEditor(); loadAvailabilityMonth(); });
document.getElementById('calendarNextMonth')?.addEventListener('click', () => { availabilityMonth.setMonth(availabilityMonth.getMonth() + 1); resetSlotEditor(); loadAvailabilityMonth(); });
slotSaveButton?.addEventListener('click', () => submitSlotAction('save_package_slot'));
slotClearButton?.addEventListener('click', () => submitSlotAction('clear_package_slot'));

editModal.addEventListener('click', event => {
    if (event.target === editModal) closeModal();
});

imageModal.addEventListener('click', event => {
    if (event.target === imageModal) closeImageModal();
});

availabilityModal.addEventListener('click', event => {
    if (event.target === availabilityModal) closeAvailabilityModal();
});

document.addEventListener('keydown', event => {
    if (event.key !== 'Escape') return;
    if (imageModal.style.display === 'flex') closeImageModal();
    else if (availabilityModal.style.display === 'flex') closeAvailabilityModal();
    else if (editModal.style.display === 'flex') closeModal();
});

const opNotifToggle = document.getElementById('opNotifToggle');
const opNotifPanel = document.getElementById('opNotifPanel');
const opNotifBadge = document.querySelector('.op-notif-badge');
let opNotifMarked = false;

async function markOpNotificationsRead() {
    if (opNotifMarked) return;
    opNotifMarked = true;
    if (opNotifBadge) opNotifBadge.remove();
    const body = new URLSearchParams();
    body.set('op_action', 'mark_notifications_read');
    body.set('csrf_token', <?= json_encode($operatorNotificationCsrf) ?>);
    try {
        await fetch('optourpackages.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body.toString()
        });
    } catch (e) {
        console.error('Failed to mark notifications as read', e);
    }
}

if (opNotifToggle && opNotifPanel) {
    opNotifToggle.addEventListener('click', () => {
        const open = opNotifPanel.classList.toggle('open');
        opNotifToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        if (open) markOpNotificationsRead();
    });
    document.addEventListener('click', (event) => {
        if (!opNotifPanel.contains(event.target) && !opNotifToggle.contains(event.target)) {
            opNotifPanel.classList.remove('open');
            opNotifToggle.setAttribute('aria-expanded', 'false');
        }
    });
}

</script>
<script src="js/operator_header.js?v=4"></script>
</body>
</html>

