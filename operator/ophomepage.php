<?php
chdir(__DIR__ . '/..');
session_start();
require_once __DIR__ . '/../php/db_connection.php';

/* ================= SECURITY ================= */
if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    session_unset();
    session_destroy();
    echo "<script>alert('Logged out');location='homepage.php';</script>";
    exit();
}

if (!isset($_SESSION['operator_logged_in'])) {
    echo "<script>alert('Session expired');location='php/operator_login.php';</script>";
    exit();
}

$operator_id  = $_SESSION['operator_id'];
$operatorName = $_SESSION['operator_name'] ?? 'Operator';
$opProfilePicFile = trim((string)($_SESSION['operator_profile'] ?? ''));
$opHeaderProfilePic = null;
if ($opProfilePicFile !== '' && strtolower($opProfilePicFile) !== 'img/profileicon.png' && file_exists($opProfilePicFile)) {
    $opHeaderProfilePic = $opProfilePicFile;
}

if (isset($_POST['op_action']) && $_POST['op_action'] === 'mark_notifications_read') {
    $_SESSION['op_notifications_seen_at'] = date('Y-m-d H:i:s');
    header('Content-Type: application/json');
    echo json_encode(['ok' => true]);
    exit;
}

/* ================= DASHBOARD METRICS ================= */
$rangeFilter = strtolower(trim((string)($_GET['range'] ?? 'all')));
$validRangeFilters = ['all', 'yearly', 'monthly', 'weekly', 'daily'];
if (!in_array($rangeFilter, $validRangeFilters, true)) {
    $rangeFilter = 'all';
}

$currentYear = (int)date('Y');
$currentMonth = (int)date('n');
$selectedYear = (int)($_GET['year'] ?? $currentYear);
if ($selectedYear < 2000 || $selectedYear > ($currentYear + 2)) {
    $selectedYear = $currentYear;
}
$selectedMonth = (int)($_GET['month'] ?? $currentMonth);
if ($selectedMonth < 1 || $selectedMonth > 12) {
    $selectedMonth = $currentMonth;
}
$selectedDate = trim((string)($_GET['date'] ?? date('Y-m-d')));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $selectedDate)) {
    $selectedDate = date('Y-m-d');
}

$availableYearsStmt = $pdo->prepare("
    SELECT DISTINCT YEAR(created_at) AS yr
    FROM bookings
    WHERE created_at IS NOT NULL AND operator_id = ?
    ORDER BY yr DESC
");
$availableYearsStmt->execute([$operator_id]);
$availableYears = array_values(array_filter(array_map('intval', $availableYearsStmt->fetchAll(PDO::FETCH_COLUMN))));
if (empty($availableYears)) {
    $availableYears = [$currentYear];
}
if (!in_array($selectedYear, $availableYears, true)) {
    $selectedYear = $availableYears[0];
}

$rangeWhere = "b.operator_id = :operator_id";
$rangeParams = [':operator_id' => $operator_id];
$chartGroupSql = "DATE_FORMAT(b.created_at, '%Y-%m')";
switch ($rangeFilter) {
    case 'daily':
        $rangeWhere .= " AND DATE(b.created_at) = :selected_date AND YEAR(b.created_at) = :selected_year";
        $rangeParams[':selected_date'] = $selectedDate;
        $rangeParams[':selected_year'] = $selectedYear;
        $chartGroupSql = "DATE_FORMAT(b.created_at, '%Y-%m-%d %H:00')";
        break;
    case 'weekly':
        $rangeWhere .= " AND YEARWEEK(b.created_at, 1) = YEARWEEK(CURDATE(), 1)";
        $chartGroupSql = "DATE_FORMAT(b.created_at, '%Y-%m-%d')";
        break;
    case 'monthly':
        $rangeWhere .= " AND YEAR(b.created_at) = :selected_year AND MONTH(b.created_at) = :selected_month";
        $rangeParams[':selected_year'] = $selectedYear;
        $rangeParams[':selected_month'] = $selectedMonth;
        $chartGroupSql = "DATE_FORMAT(b.created_at, '%Y-%m-%d')";
        break;
    case 'yearly':
        $rangeWhere .= " AND YEAR(b.created_at) = :selected_year";
        $rangeParams[':selected_year'] = $selectedYear;
        $chartGroupSql = "DATE_FORMAT(b.created_at, '%Y-%m')";
        break;
    default:
        break;
}

$stmt = $pdo->prepare("SELECT COUNT(*) FROM bookings b WHERE $rangeWhere");
$stmt->execute($rangeParams);
$totalBookings = (int)$stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT COUNT(*) FROM bookings b WHERE b.status='accepted' AND b.is_complete='uncomplete' AND $rangeWhere");
$stmt->execute($rangeParams);
$acceptedIncomplete = (int)$stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT COUNT(*) FROM bookings b WHERE b.is_complete='completed' AND $rangeWhere");
$stmt->execute($rangeParams);
$completedBookings = (int)$stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT ROUND(AVG(f.rating),1) FROM feedback f JOIN tour_packages p ON f.package_id=p.package_id WHERE p.operator_id=?");
$stmt->execute([$operator_id]);
$avgRating = (float)($stmt->fetchColumn() ?: 0);
$opProfileInitial = strtoupper(substr(trim((string)$operatorName) !== '' ? trim((string)$operatorName) : 'O', 0, 1));

/* ================= HEADER NOTIFICATIONS ================= */
$seenAt = trim((string)($_SESSION['op_notifications_seen_at'] ?? ''));
if ($seenAt !== '' && preg_match('/^\d{4}-\d{2}-\d{2}\s\d{2}:\d{2}:\d{2}$/', $seenAt)) {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM bookings WHERE operator_id=? AND status='pending' AND created_at > ?");
    $stmt->execute([$operator_id, $seenAt]);
} else {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM bookings WHERE operator_id=? AND status='pending'");
    $stmt->execute([$operator_id]);
}
$notificationCount = (int)$stmt->fetchColumn();

$stmt = $pdo->prepare("
    SELECT booking_id, package_name, booking_date, status, created_at
    FROM bookings
    WHERE operator_id=?
    ORDER BY created_at DESC
    LIMIT 8
");
$stmt->execute([$operator_id]);
$notificationItems = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* ================= PIE CHART DATA ================= */
$stmt = $pdo->prepare("SELECT COUNT(*) FROM bookings b WHERE b.status='pending' AND $rangeWhere");
$stmt->execute($rangeParams);
$pendingBookings = (int)$stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT COUNT(*) FROM bookings b WHERE b.status='cancelled' AND $rangeWhere");
$stmt->execute($rangeParams);
$cancelledBookings = (int)$stmt->fetchColumn();

/* ================= LINE CHART ================= */
$stmt = $pdo->prepare("
    SELECT $chartGroupSql AS grp, COUNT(*) AS cnt
    FROM bookings b
    WHERE $rangeWhere
    GROUP BY grp
    ORDER BY grp ASC
");
$stmt->execute($rangeParams);
$trendRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
$lineLabelsRaw = array_map(fn($r) => (string)$r['grp'], $trendRows);
$lineLabels = array_map(function ($label) use ($rangeFilter) {
    $ts = strtotime($label);
    if ($ts === false) return $label;
    if ($rangeFilter === 'daily') return date('H:i', $ts);
    if ($rangeFilter === 'weekly' || $rangeFilter === 'monthly') return date('M d', $ts);
    if ($rangeFilter === 'yearly') return date('M', $ts);
    return date('Y-m', $ts);
}, $lineLabelsRaw);
$lineData = array_map(fn($r) => (int)$r['cnt'], $trendRows);

$calendarInitialDate = date('Y-m-d');
if ($rangeFilter === 'monthly') {
    $calendarInitialDate = sprintf('%04d-%02d-01', $selectedYear, $selectedMonth);
} elseif ($rangeFilter === 'daily') {
    $calendarInitialDate = $selectedDate;
} elseif ($rangeFilter === 'yearly') {
    $calendarInitialDate = sprintf('%04d-01-01', $selectedYear);
}

$calendarWhere = "b.operator_id = :operator_id AND b.status='accepted' AND b.is_complete='uncomplete'";
$calendarParams = [':operator_id' => $operator_id];
switch ($rangeFilter) {
    case 'daily':
        $calendarWhere .= " AND DATE(b.booking_date) = :cal_date";
        $calendarParams[':cal_date'] = $selectedDate;
        break;
    case 'weekly':
        $calendarWhere .= " AND YEARWEEK(b.booking_date, 1) = YEARWEEK(CURDATE(), 1)";
        break;
    case 'monthly':
        $calendarWhere .= " AND YEAR(b.booking_date) = :cal_year AND MONTH(b.booking_date) = :cal_month";
        $calendarParams[':cal_year'] = $selectedYear;
        $calendarParams[':cal_month'] = $selectedMonth;
        break;
    case 'yearly':
        $calendarWhere .= " AND YEAR(b.booking_date) = :cal_year";
        $calendarParams[':cal_year'] = $selectedYear;
        break;
    default:
        break;
}

/* ================= FEEDBACK ================= */
$stmt = $pdo->prepare("SELECT t.full_name, f.rating, f.comment FROM feedback f JOIN tour_packages p ON f.package_id=p.package_id JOIN tourist t ON f.tourist_id=t.tourist_id WHERE p.operator_id=? ORDER BY f.created_at DESC");
$stmt->execute([$operator_id]);
$ratings = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* ================= UPCOMING BOOKINGS ================= */
// Prepare bookings grouped by date
$stmt = $pdo->prepare("
    SELECT b.booking_date, b.booking_id, b.package_name, b.phone_number, b.location, b.pax,
           b.booking_type, b.jump_off_port, b.status, b.is_complete, b.created_at,
           t.full_name AS tourist_name
    FROM bookings b
    JOIN tourist t ON b.tourist_id = t.tourist_id
    WHERE $calendarWhere
    ORDER BY b.booking_date ASC, b.created_at ASC
");
$stmt->execute($calendarParams);
$allBookings = $stmt->fetchAll(PDO::FETCH_ASSOC);
$upcomingRows = array_slice($allBookings, 0, 8);

// Group bookings by date
$bookingsByDate = [];
foreach ($allBookings as $b) {
    $date = $b['booking_date'];
    if (!isset($bookingsByDate[$date])) $bookingsByDate[$date] = [];
    $bookingsByDate[$date][] = $b;
}

/* Prepare events for FullCalendar */
/* Prepare events for FullCalendar (one per date, showing count) */
$calendarEvents = [];
foreach ($bookingsByDate as $date => $bookings) {
    $calendarEvents[] = [
        'title' => count($bookings) . ' Booking' . (count($bookings) > 1 ? 's' : ''),
        'start' => $date,
        'backgroundColor' => '#2b7a66',
        'borderColor' => '#2b7a66',
        'textColor' => '#fff',
        'extendedProps' => [
            'bookings' => $bookings
        ]
    ];
}


?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>iTour Mercedes - Operator Dashboard</title>
<link rel="icon" type="image/png" href="img/newlogo.png">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<!-- Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<!-- FullCalendar -->
<link href='https://cdn.jsdelivr.net/npm/fullcalendar@6.1.8/index.global.min.css' rel='stylesheet' />
<script src='https://cdn.jsdelivr.net/npm/fullcalendar@6.1.8/index.global.min.js'></script>
<!-- Font Awesome -->
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" integrity="sha512-..." crossorigin="anonymous" referrerpolicy="no-referrer" />


<style>
@import url("https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap");

:root {
    --op-primary: #2b7a66;
    --op-primary-dark: #1d5d4a;
    --op-bg: #f4f8f6;
    --op-card: #ffffff;
    --op-border: #d8e6e0;
    --op-text: #132028;
    --op-muted: #60707a;
    --op-shadow: 0 12px 30px rgba(17, 67, 53, 0.08);
}

* { box-sizing: border-box; }

body {
    margin: 0;
    font-family: "Inter", sans-serif;
    background: var(--op-bg);
    color: var(--op-text);
}

.op-layout {
    display: flex;
    min-height: 100vh;
}

.op-main {
    margin-left: 250px;
    padding: 86px 18px 24px;
    flex: 1;
    min-height: 100vh;
    min-width: 0;
    overflow-x: hidden;
    transition: margin-left 0.3s ease;
}

.operator-header {
    background: #fff;
    border-radius: 0 0 14px 14px;
    padding: 14px 18px;
    position: fixed;
    top: 0;
    left: 250px;
    right: 0;
    width: auto;
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
    margin: 0;
    font-size: 23px;
    font-weight: 700;
    color: var(--op-primary-dark);
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

.op-global-filter-form {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
    justify-content: flex-end;
}

.op-global-filter-label {
    font-size: 13px;
    color: #4f636c;
    font-weight: 600;
}

.op-global-filter-select {
    border: 1px solid var(--op-border);
    background: #fff;
    border-radius: 10px;
    padding: 8px 11px;
    font: inherit;
    font-size: 13px;
    color: #24434d;
    min-width: 96px;
}

.op-global-filter-date {
    min-width: 148px;
}

.op-global-filter-apply {
    border: 1px solid var(--op-border);
    background: #fff;
    border-radius: 10px;
    padding: 8px 12px;
    font: inherit;
    font-size: 13px;
    font-weight: 600;
    color: #24434d;
    cursor: pointer;
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
.op-notif-panel h4 {
    margin: 0 0 8px;
    font-size: 14px;
}
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
.op-notif-list li strong {
    font-size: 12px;
    color: #1d343e;
}
.op-notif-list li span,
.op-notif-list li small {
    font-size: 12px;
    color: #63747d;
}
.op-notif-empty {
    margin: 0;
    color: var(--op-muted);
    font-size: 13px;
    padding: 8px 4px;
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

.dashboard-content {
    display: grid;
    gap: 12px;
    margin-top: 10px;
}

.stats-grid {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 10px;
}

.card {
    background: var(--op-card);
    border: 1px solid var(--op-border);
    border-radius: 16px;
    padding: 14px;
    box-shadow: var(--op-shadow);
}
.card h4 {
    margin: 0 0 10px;
    font-size: 16px;
    font-weight: 700;
    color: #1f3f49;
}

.stat-card {
    display: flex;
    align-items: center;
    gap: 12px;
    position: relative;
    overflow: hidden;
    background: linear-gradient(135deg, #2b7a66 0%, #236552 100%);
    border: 1px solid rgba(22, 90, 72, 0.18);
}
.stat-card::after {
    content: "";
    position: absolute;
    top: -10px;
    right: -10px;
    width: 46px;
    height: 46px;
    border-radius: 50%;
    background: rgba(255, 255, 255, 0.14);
}
.stat-card .icon {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    border: 1px solid rgba(255, 255, 255, 0.28);
    background: rgba(255, 255, 255, 0.18);
    color: #ffffff;
    font-size: 18px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}
.stat-card .stat {
    font-size: 1.75rem;
    line-height: 1;
    font-weight: 800;
    color: #ffffff;
}
.stat-card .label {
    margin-top: 4px;
    font-size: 14px;
    color: rgba(233, 251, 244, 0.9);
    font-weight: 600;
}

.dashboard-grid {
    display: grid;
    grid-template-columns: minmax(0, 1.45fr) minmax(0, 0.85fr);
    gap: 10px;
}
.analytics-stack {
    display: grid;
    gap: 10px;
    grid-template-rows: 1fr 1fr;
}

#bookingChart,
#statusPieChart {
    max-height: 220px;
}

.calendar-card {
    min-height: 560px;
}
.calendar-card-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    margin-bottom: 10px;
}
.calendar-card-head h4 {
    margin: 0;
}
.op-tabs {
    display: inline-flex;
    background: #f1f7f4;
    border: 1px solid var(--op-border);
    border-radius: 10px;
    padding: 3px;
    gap: 3px;
}
.op-tabs button {
    border: 0;
    background: transparent;
    border-radius: 8px;
    padding: 6px 9px;
    font-size: 12px;
    font-weight: 600;
    color: #50616b;
    cursor: pointer;
}
.op-tabs button.active {
    background: #fff;
    color: #214952;
    box-shadow: 0 1px 4px rgba(20, 55, 44, 0.12);
}
.op-upcoming-pane {
    display: none;
}
.op-calendar-list {
    margin: 0;
    padding: 0;
    list-style: none;
    display: grid;
    gap: 8px;
    max-height: 430px;
    overflow: auto;
}
.op-calendar-list li {
    border: 1px solid #e8f0ed;
    background: #fbfefd;
    border-radius: 10px;
    padding: 8px;
    display: grid;
    gap: 2px;
}
.op-calendar-list li strong {
    font-size: 12px;
    color: #1d343e;
}
.op-calendar-list li span,
.op-calendar-list li small {
    font-size: 12px;
    color: #63747d;
}
#calendar { max-width: 100%; }

.feedback-carousel {
    position: relative;
    overflow: hidden;
    height: 220px;
}
.feedback-track {
    display: flex;
    transition: transform 0.5s ease-in-out;
}
.feedback-item {
    min-width: 100%;
    box-sizing: border-box;
    border: 1px solid #dfeae6;
    background: #fbfdfc;
    border-radius: 12px;
    padding: 14px;
    display: flex;
    flex-direction: column;
    justify-content: space-between;
    height: 200px;
}
.feedback-item strong {
    font-size: 13px;
    color: #0f5a41;
}
.feedback-stars {
    color: #f5b301;
    margin: 8px 0 10px;
    font-size: 16px;
}
.feedback-item p {
    margin: 0;
    font-size: 0.93rem;
    line-height: 1.5;
    color: #355664;
    overflow: hidden;
    text-overflow: ellipsis;
    display: -webkit-box;
    -webkit-line-clamp: 4;
    -webkit-box-orient: vertical;
}
.feedback-controls {
    display: flex;
    justify-content: flex-end;
    gap: 8px;
    margin-top: 10px;
}
.feedback-controls button {
    background: var(--op-primary);
    color: #fff;
    border: 0;
    width: 36px;
    height: 32px;
    border-radius: 8px;
    cursor: pointer;
}

.modal {
    display: none;
    position: fixed;
    inset: 0;
    z-index: 1000;
    background: rgba(5, 18, 14, 0.52);
    justify-content: center;
    align-items: center;
}
.modal-content {
    background: #fff;
    border-radius: 14px;
    border: 1px solid var(--op-border);
    width: min(760px, 92vw);
    max-height: 86vh;
    overflow: auto;
    padding: 16px;
    position: relative;
    box-shadow: var(--op-shadow);
}
.modal-content h3 {
    margin: 0 0 12px;
    color: #214952;
}
.modal-close {
    position: absolute;
    top: 8px;
    right: 12px;
    font-size: 22px;
    color: #4a6069;
    cursor: pointer;
}

#modalBody {
    display: grid;
    gap: 10px;
}
.booking-modal-item {
    border: 1px solid #dce9e4;
    border-left: 4px solid var(--op-primary);
    background: #f8fcfa;
    padding: 12px;
    border-radius: 10px;
}
.booking-modal-item p {
    margin: 0 0 6px;
    font-size: 13px;
    color: #31464f;
}
.booking-modal-item p:last-child { margin-bottom: 0; }

/* FullCalendar look aligned to hotel admin palette */
.fc .fc-toolbar-title {
    color: #214952;
    font-weight: 700;
    font-size: 20px;
}
.fc .fc-button {
    background-color: transparent !important;
    border: 0 !important;
    color: #50616b !important;
    border-radius: 8px !important;
    font-weight: 600 !important;
    font-size: 12px !important;
    padding: 6px 9px !important;
    box-shadow: none !important;
    text-transform: none;
}
.fc .fc-button:hover {
    background-color: rgba(255, 255, 255, 0.65) !important;
    color: #214952 !important;
}
.fc .fc-button-primary:not(:disabled).fc-button-active,
.fc .fc-button-primary:not(:disabled):active {
    background: #fff !important;
    color: #214952 !important;
    border: 1px solid #d8e6e0 !important;
    box-shadow: 0 1px 4px rgba(20, 55, 44, 0.12) !important;
}
.fc .fc-button:disabled {
    opacity: 0.55;
    color: #87959c !important;
}
.fc .fc-toolbar.fc-header-toolbar {
    margin-bottom: 12px;
}
.fc .fc-toolbar-chunk .fc-button-group,
.fc .fc-toolbar-chunk > .fc-button {
    background: #f1f7f4;
    border: 1px solid var(--op-border);
    border-radius: 10px;
    padding: 3px;
}
.fc .fc-toolbar-chunk .fc-button-group .fc-button {
    margin: 0 !important;
}
.fc .fc-toolbar-chunk > .fc-button {
    margin-left: 6px !important;
}
.fc .fc-prev-button,
.fc .fc-next-button,
.fc .fc-today-button {
    background: #f1f7f4 !important;
    border: 1px solid var(--op-border) !important;
    border-radius: 10px !important;
}
.fc-theme-standard .fc-scrollgrid {
    border: 1px solid #dfe9e5;
    border-radius: 12px;
    overflow: hidden;
}
.fc-theme-standard .fc-scrollgrid-section-header > * {
    background: #f4faf7;
}
.fc .fc-col-header-cell-cushion {
    color: #4f636d;
    font-weight: 700;
    padding: 10px 4px;
    text-decoration: none;
}
.fc .fc-daygrid-day-number {
    color: #21363f;
    text-decoration: none;
    font-size: 12px;
    font-weight: 600;
}
.fc .fc-daygrid-event {
    border-radius: 8px;
    border: 0;
    padding: 2px 6px;
    background: linear-gradient(135deg, #2b7a66 0%, #226451 100%) !important;
}
.fc-theme-standard td,
.fc-theme-standard th {
    border-color: #dfe9e5;
}
.fc .fc-daygrid-day.fc-day-today {
    background: #e5f5ef !important;
}

@media (max-width: 1280px) {
    .stats-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    .dashboard-grid { grid-template-columns: 1fr; }
    .analytics-stack { grid-template-rows: none; grid-template-columns: 1fr 1fr; }
}

@media (max-width: 900px) {
    .operator-header {
        position: static;
        width: auto;
        left: auto;
        border-radius: 14px;
    }
    .op-main {
        margin-left: 250px;
        padding-top: 18px;
    }
    .stats-grid,
    .analytics-stack {
        grid-template-columns: 1fr;
    }
}
</style>
</head>
<body>

<div class="op-layout">
    <!-- Sidebar -->
    <?php include 'operator_sidebar.php'; ?>

    <!-- Main Content -->
    <main class="op-main">
        <header class="operator-header">
            <div class="operator-header-left">
                <h2>Operator Dashboard</h2>
                <p>Welcome, <?= htmlspecialchars($operatorName) ?></p>
            </div>
            <div class="operator-header-right">
                <form method="get" class="op-global-filter-form">
                    <label for="opRangeFilter" class="op-global-filter-label">Overview Filter</label>
                    <select id="opRangeFilter" name="range" class="op-global-filter-select">
                        <option value="all" <?= $rangeFilter === 'all' ? 'selected' : '' ?>>All</option>
                        <option value="yearly" <?= $rangeFilter === 'yearly' ? 'selected' : '' ?>>Yearly</option>
                        <option value="monthly" <?= $rangeFilter === 'monthly' ? 'selected' : '' ?>>Monthly</option>
                        <option value="weekly" <?= $rangeFilter === 'weekly' ? 'selected' : '' ?>>Weekly</option>
                        <option value="daily" <?= $rangeFilter === 'daily' ? 'selected' : '' ?>>Daily</option>
                    </select>

                    <select id="opRangeYear" name="year" class="op-global-filter-select">
                        <?php foreach ($availableYears as $year): ?>
                            <option value="<?= (int)$year ?>" <?= $selectedYear === (int)$year ? 'selected' : '' ?>><?= (int)$year ?></option>
                        <?php endforeach; ?>
                    </select>

                    <select id="opRangeMonth" name="month" class="op-global-filter-select">
                        <?php for ($m = 1; $m <= 12; $m++): ?>
                            <option value="<?= $m ?>" <?= $selectedMonth === $m ? 'selected' : '' ?>><?= date('F', mktime(0, 0, 0, $m, 1)) ?></option>
                        <?php endfor; ?>
                    </select>

                    <input id="opRangeDate" type="date" name="date" class="op-global-filter-select op-global-filter-date" value="<?= htmlspecialchars($selectedDate) ?>" />
                    <button type="submit" class="op-global-filter-apply">Apply</button>
                </form>
                <div class="op-notif-wrap">
                    <button type="button" class="op-notif-btn" id="opNotifToggle" aria-label="Notifications">
                        <i class="fas fa-bell"></i>
                        Notifications
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
                <div class="op-topbar-profile" title="<?= htmlspecialchars($operatorName) ?>">
                    <?php if ($opHeaderProfilePic): ?>
                        <img src="<?= htmlspecialchars($opHeaderProfilePic) ?>" alt="<?= htmlspecialchars($operatorName) ?>">
                    <?php else: ?>
                        <?= htmlspecialchars($opProfileInitial) ?>
                    <?php endif; ?>
                </div>
            </div>
        </header>

        <section class="dashboard-content">
            <div class="stats-grid">
                <div class="card stat-card">
                    <div class="icon"><i class="fas fa-calendar-check"></i></div>
                    <div>
                        <div class="stat"><?= $totalBookings ?></div>
                        <div class="label">Total Bookings</div>
                    </div>
                </div>
                <div class="card stat-card">
                    <div class="icon"><i class="fas fa-star"></i></div>
                    <div>
                        <div class="stat"><?= $avgRating ?></div>
                        <div class="label">Average Rating</div>
                    </div>
                </div>
                <div class="card stat-card">
                    <div class="icon"><i class="fas fa-plane-departure"></i></div>
                    <div>
                        <div class="stat"><?= $acceptedIncomplete ?></div>
                        <div class="label">Upcoming Tours</div>
                    </div>
                </div>
                <div class="card stat-card">
                    <div class="icon"><i class="fas fa-check-circle"></i></div>
                    <div>
                        <div class="stat"><?= $completedBookings ?></div>
                        <div class="label">Completed Tours</div>
                    </div>
                </div>
            </div>

            <div class="dashboard-grid">
                <div class="card calendar-card">
                    <div class="calendar-card-head">
                        <h4>Calendar & Upcoming</h4>
                        <div class="op-tabs">
                            <button type="button" class="active" data-op-tab="calendar">Calendar</button>
                            <button type="button" data-op-tab="upcoming">Upcoming</button>
                        </div>
                    </div>
                    <div id="opCalendarPane">
                        <div id='calendar'></div>
                    </div>
                    <div id="opUpcomingPane" class="op-upcoming-pane">
                        <ul class="op-calendar-list">
                            <?php foreach ($upcomingRows as $u): ?>
                                <li>
                                    <strong>#<?= (int)$u['booking_id'] ?> — <?= htmlspecialchars((string)$u['tourist_name']) ?></strong>
                                    <span><?= htmlspecialchars((string)$u['package_name']) ?> • Date <?= htmlspecialchars((string)$u['booking_date']) ?></span>
                                    <small>Status: <?= htmlspecialchars(ucfirst((string)$u['status'])) ?></small>
                                </li>
                            <?php endforeach; ?>
                            <?php if (!$upcomingRows): ?>
                                <li><span>No upcoming bookings yet.</span></li>
                            <?php endif; ?>
                        </ul>
                    </div>
                </div>
                <div class="analytics-stack">
                    <div class="card">
                        <h4>Bookings Overview</h4>
                        <canvas id="bookingChart"></canvas>
                    </div>
                    <div class="card">
                        <h4>Booking Status Pie Chart</h4>
                        <canvas id="statusPieChart"></canvas>
                    </div>
                </div>
            </div>

            <div class="card">
                <h4>Tourist Feedbacks</h4>
                <?php if(empty($ratings)): ?>
                    <p>No feedback yet.</p>
                <?php else: ?>
                    <div class="feedback-carousel">
                        <div class="feedback-track">
                            <?php foreach($ratings as $r): ?>
                                <div class="feedback-item">
                                    <div>
                                        <strong><?= htmlspecialchars($r['full_name']) ?></strong>
                                        <div class="feedback-stars"><?= str_repeat('★',(int)$r['rating']) ?></div>
                                    </div>
                                    <p><?= htmlspecialchars($r['comment']) ?></p>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php if(count($ratings) > 1): ?>
                        <div class="feedback-controls">
                            <button onclick="prevFeedback()"><i class="fas fa-chevron-left"></i></button>
                            <button onclick="nextFeedback()"><i class="fas fa-chevron-right"></i></button>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </section>
    </main>
</div>

<!-- BOOKING MODAL -->
<div class="modal" id="bookingModal">
    <div class="modal-content">
        <span class="modal-close" onclick="closeModal()">×</span>
        <h3>Booking Details</h3>
        <div id="modalBody"></div>
    </div>
</div>

<script>
const ctx = document.getElementById('bookingChart').getContext('2d');
const gradient = ctx.createLinearGradient(0, 0, 0, 360);
gradient.addColorStop(0, 'rgba(73,164,122,0.28)');
gradient.addColorStop(1, 'rgba(73,164,122,0)');

new Chart(ctx, {
    type: 'line',
    data: {
        labels: <?= json_encode($lineLabels) ?>,
        datasets: [{
            label: 'Bookings',
            data: <?= json_encode($lineData) ?>,
            borderColor: '#2b7a66',
            backgroundColor: gradient,
            borderWidth: 3,
            tension: 0.4,
            pointBackgroundColor: '#fff',
            pointBorderColor: '#2b7a66',
            pointRadius: 6,
            pointHoverRadius: 8,
            fill: true
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: { display: false },
            tooltip: {
                backgroundColor: '#2b7a66',
                titleColor: '#fff',
                bodyColor: '#fff',
                padding: 10,
                cornerRadius: 8
            }
        },
        scales: {
            y: {
                beginAtZero: true,
                ticks: { color: '#4f636c', font: { weight: '600' } },
                grid: { color: 'rgba(18,44,32,0.08)', drawBorder: false }
            },
            x: {
                ticks: { color: '#4f636c', font: { weight: '600' } },
                grid: { color: 'rgba(18,44,32,0.08)', drawBorder: false }
            }
        }
    }
});

const pieCtx = document.getElementById('statusPieChart').getContext('2d');
new Chart(pieCtx, {
    type: 'pie',
    data: {
        labels: ['Pending', 'Upcoming', 'Completed', 'Cancelled'],
        datasets: [{
            data: [
                <?= $pendingBookings ?>,
                <?= $acceptedIncomplete ?>,
                <?= $completedBookings ?>,
                <?= $cancelledBookings ?>
            ],
            backgroundColor: ['#f5b301', '#2b7a66', '#1f7c53', '#bf3545'],
            borderColor: '#ffffff',
            borderWidth: 3
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: {
                position: 'bottom',
                labels: {
                    usePointStyle: true,
                    boxWidth: 10,
                    color: '#435761',
                    font: { size: 12, weight: '600' }
                }
            }
        }
    }
});

document.addEventListener('DOMContentLoaded', function() {
    const range = document.getElementById('opRangeFilter');
    const year = document.getElementById('opRangeYear');
    const month = document.getElementById('opRangeMonth');
    const date = document.getElementById('opRangeDate');

    const updateRangeVisibility = () => {
        const r = range ? range.value : 'all';
        if (year) year.style.display = (r === 'yearly' || r === 'monthly' || r === 'daily') ? '' : 'none';
        if (month) month.style.display = (r === 'monthly') ? '' : 'none';
        if (date) date.style.display = (r === 'daily') ? '' : 'none';
    };
    if (range) range.addEventListener('change', updateRangeVisibility);
    updateRangeVisibility();

    const notifToggle = document.getElementById('opNotifToggle');
    const notifPanel = document.getElementById('opNotifPanel');
    const notifBadge = document.querySelector('.op-notif-badge');
    let notifMarked = false;

    const markNotificationsRead = async () => {
        if (notifMarked) return;
        notifMarked = true;
        if (notifBadge) notifBadge.remove();

        const body = new URLSearchParams();
        body.set('op_action', 'mark_notifications_read');
        try {
            await fetch('ophomepage.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: body.toString()
            });
        } catch (e) {
            console.error('Failed to mark notifications as read', e);
        }
    };

    if (notifToggle && notifPanel) {
        notifToggle.addEventListener('click', () => {
            const open = notifPanel.classList.toggle('open');
            if (open) markNotificationsRead();
        });
        document.addEventListener('click', (event) => {
            if (!notifPanel.contains(event.target) && !notifToggle.contains(event.target)) {
                notifPanel.classList.remove('open');
            }
        });
    }

    const calendarEl = document.getElementById('calendar');
    const opTabs = document.querySelectorAll('[data-op-tab]');
    const opCalendarPane = document.getElementById('opCalendarPane');
    const opUpcomingPane = document.getElementById('opUpcomingPane');
    const calendar = new FullCalendar.Calendar(calendarEl, {
        initialView: 'dayGridMonth',
        initialDate: '<?= htmlspecialchars($calendarInitialDate) ?>',
        height: 520,
        events: <?= json_encode($calendarEvents) ?>,
        buttonText: {
            today: 'Today',
            month: 'Month',
            week: 'Week',
            day: 'Day'
        },
        headerToolbar: {
            left: 'prev,next today',
            center: 'title',
            right: 'dayGridMonth,timeGridWeek,timeGridDay'
        },
        eventClick: function(info) {
            info.jsEvent.preventDefault();
            const bookings = info.event.extendedProps.bookings;
            if (!bookings || bookings.length === 0) return;

            let html = '';
            bookings.forEach(b => {
                html += `
                <div class="booking-modal-item">
                    <p><strong>Tourist:</strong> ${b.tourist_name}</p>
                    <p><strong>Package:</strong> ${b.package_name}</p>
                    <p><strong>Phone:</strong> ${b.phone_number}</p>
                    <p><strong>Location:</strong> ${b.location}</p>
                    <p><strong>Pax:</strong> ${b.pax}</p>
                    <p><strong>Booking Type:</strong> ${b.booking_type}</p>
                    <p><strong>Jump Off Port:</strong> ${b.jump_off_port}</p>
                    <p><strong>Status:</strong> ${b.status}</p>
                    <p><strong>Complete:</strong> ${b.is_complete}</p>
                </div>
                `;
            });

            document.getElementById('modalBody').innerHTML = html;
            document.getElementById('bookingModal').style.display = 'flex';
        }
    });
    calendar.render();

    opTabs.forEach((btn) => {
        btn.addEventListener('click', () => {
            opTabs.forEach((x) => x.classList.remove('active'));
            btn.classList.add('active');
            const tab = btn.getAttribute('data-op-tab');
            if (opCalendarPane) opCalendarPane.style.display = tab === 'calendar' ? 'block' : 'none';
            if (opUpcomingPane) opUpcomingPane.style.display = tab === 'upcoming' ? 'block' : 'none';
            if (tab === 'calendar') {
                calendar.updateSize();
            }
        });
    });
});

function closeModal() {
    document.getElementById('bookingModal').style.display = 'none';
}

let feedbackIndex = 0;
const track = document.querySelector('.feedback-track');
const feedbackCount = document.querySelectorAll('.feedback-item').length;

function updateFeedback() {
    if (!track || feedbackCount === 0) return;
    const offset = -feedbackIndex * 100;
    track.style.transform = `translateX(${offset}%)`;
}

function nextFeedback() {
    if (feedbackCount === 0) return;
    feedbackIndex = (feedbackIndex + 1) % feedbackCount;
    updateFeedback();
}

function prevFeedback() {
    if (feedbackCount === 0) return;
    feedbackIndex = (feedbackIndex - 1 + feedbackCount) % feedbackCount;
    updateFeedback();
}

</script>

</body>
</html>

