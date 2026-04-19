<?php
chdir(__DIR__ . '/..');
session_start();

// ---------------------
// LOGOUT HANDLER
// ---------------------
if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    session_unset();
    session_destroy();
    echo "<script>alert('You have been logged out');window.location.href='homepage.php';</script>";
    exit();
}

// Prevent caching
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

// ---------------------
// DATABASE CONNECTION
// ---------------------
require_once __DIR__ . '/../php/db_connection.php';

// ---------------------
// FILTER SETUP (Hotel-style)
// ---------------------
$filter = strtolower(trim((string)($_GET['filter'] ?? 'all')));
$validFilters = ['all', 'yearly', 'monthly', 'daily'];
if (!in_array($filter, $validFilters, true)) {
    $filter = 'all';
}

$currentYear = (int)date('Y');
$currentMonth = (int)date('n');
$selectedYear = (int)($_GET['year'] ?? $currentYear);
$selectedMonth = (int)($_GET['month'] ?? $currentMonth);
$selectedDate = trim((string)($_GET['date'] ?? date('Y-m-d')));

if ($selectedYear < 2000 || $selectedYear > ($currentYear + 2)) {
    $selectedYear = $currentYear;
}
if ($selectedMonth < 1 || $selectedMonth > 12) {
    $selectedMonth = $currentMonth;
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $selectedDate)) {
    $selectedDate = date('Y-m-d');
}

$availableYears = $pdo->query("
    SELECT DISTINCT YEAR(created_at) AS yr
    FROM bookings
    WHERE created_at IS NOT NULL
    ORDER BY yr DESC
")->fetchAll(PDO::FETCH_COLUMN);
$availableYears = array_values(array_filter(array_map('intval', $availableYears)));
if (empty($availableYears)) {
    $availableYears = [$currentYear];
}
if (!in_array($selectedYear, $availableYears, true)) {
    $selectedYear = $availableYears[0];
}

$bookingCreatedWhere = '';
$bookingCreatedParams = [];
$bookingDateWhere = '';
$bookingDateParams = [];
$touristWhere = '';
$touristParams = [];

switch ($filter) {
    case 'daily':
        $bookingCreatedWhere = " AND DATE(b.created_at) = :selected_date";
        $bookingCreatedParams[':selected_date'] = $selectedDate;

        $bookingDateWhere = " AND DATE(b.booking_date) = :selected_date";
        $bookingDateParams[':selected_date'] = $selectedDate;

        $touristWhere = " AND DATE(created_at) = :selected_date";
        $touristParams[':selected_date'] = $selectedDate;
        break;
    case 'monthly':
        $bookingCreatedWhere = " AND YEAR(b.created_at) = :selected_year AND MONTH(b.created_at) = :selected_month";
        $bookingCreatedParams[':selected_year'] = $selectedYear;
        $bookingCreatedParams[':selected_month'] = $selectedMonth;

        $bookingDateWhere = " AND YEAR(b.booking_date) = :selected_year AND MONTH(b.booking_date) = :selected_month";
        $bookingDateParams[':selected_year'] = $selectedYear;
        $bookingDateParams[':selected_month'] = $selectedMonth;

        $touristWhere = " AND YEAR(created_at) = :selected_year AND MONTH(created_at) = :selected_month";
        $touristParams[':selected_year'] = $selectedYear;
        $touristParams[':selected_month'] = $selectedMonth;
        break;
    case 'yearly':
        $bookingCreatedWhere = " AND YEAR(b.created_at) = :selected_year";
        $bookingCreatedParams[':selected_year'] = $selectedYear;

        $bookingDateWhere = " AND YEAR(b.booking_date) = :selected_year";
        $bookingDateParams[':selected_year'] = $selectedYear;

        $touristWhere = " AND YEAR(created_at) = :selected_year";
        $touristParams[':selected_year'] = $selectedYear;
        break;
    case 'all':
    default:
        break;
}

$calendarInitialDate = date('Y-m-d');
if ($filter === 'monthly') {
    $calendarInitialDate = sprintf('%04d-%02d-01', $selectedYear, $selectedMonth);
} elseif ($filter === 'daily') {
    $calendarInitialDate = $selectedDate;
} elseif ($filter === 'yearly') {
    $calendarInitialDate = sprintf('%04d-01-01', $selectedYear);
}

function fetchCount(PDO $pdo, string $sql, array $params = []): int {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return (int)$stmt->fetchColumn();
}

// ---------------------
// DASHBOARD COUNTS
// ---------------------
$total_bookings = fetchCount(
    $pdo,
    "SELECT COUNT(*) FROM bookings b WHERE 1=1 {$bookingCreatedWhere}",
    $bookingCreatedParams
);
$accepted_bookings = fetchCount(
    $pdo,
    "SELECT COUNT(*) FROM bookings b WHERE b.status='accepted' AND (b.is_complete IS NULL OR b.is_complete='uncomplete') {$bookingCreatedWhere}",
    $bookingCreatedParams
);
$completed_bookings = fetchCount(
    $pdo,
    "SELECT COUNT(*) FROM bookings b WHERE b.is_complete='completed' {$bookingCreatedWhere}",
    $bookingCreatedParams
);
$total_tourists = fetchCount(
    $pdo,
    "SELECT COUNT(*) FROM tourist WHERE 1=1 {$touristWhere}",
    $touristParams
);

// ---------------------
// CALENDAR EVENTS
// ---------------------
$calendar_events = [];
$stmt = $pdo->prepare("
    SELECT 
        b.booking_id, b.booking_date, b.pax, b.location, b.package_name, b.phone_number,
        b.booking_type, b.updated_at,
        t.full_name, t.email, t.profile_picture,
        b.jump_off_port, b.tour_type, b.tour_range,
        CASE WHEN LOWER(COALESCE(b.booking_type, '')) IN ('boat','tourguide') THEN b.preferred_resource ELSE NULL END AS preferred_resource
    FROM bookings b
    INNER JOIN tourist t ON b.tourist_id = t.tourist_id
    WHERE b.status='accepted' AND (b.is_complete IS NULL OR b.is_complete = 'uncomplete') {$bookingDateWhere}
    ORDER BY b.booking_date ASC, COALESCE(b.updated_at, b.booking_date) DESC
");
$stmt->execute($bookingDateParams);
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $calendar_events[] = [
        'type' => 'booking',
        'status_type' => 'unfinished',
        'title' => $row['full_name'] . ' (' . $row['pax'] . ' pax)',
        'start' => $row['booking_date'],
        'color' => '#2b7a66',
        'details' => [
            'full_name' => $row['full_name'],
            'email' => $row['email'],
            'phone' => $row['phone_number'],
            'profile_picture' => $row['profile_picture'],
            'pax' => $row['pax'],
            'date' => $row['booking_date'],
            'location' => $row['location'],
            'package_name' => $row['package_name'],
            'booking_type' => $row['booking_type'],
            'jump_off_port' => $row['jump_off_port'],
            'tour_type' => $row['tour_type'],
            'tour_range' => $row['tour_range'],
            'preferred_resource' => $row['preferred_resource'],
            'status_type' => 'unfinished',
            'updated_at' => $row['updated_at'] ?? $row['booking_date']
        ]
    ];
}

// ---------------------
// UPCOMING ACCEPTED BOOKINGS
// ---------------------
$upcoming_accepted = [];
$stmt = $pdo->prepare("
    SELECT 
        b.booking_id, b.booking_date, b.pax, b.location, b.package_name, b.phone_number,
        b.booking_type, t.full_name, t.email,
        b.jump_off_port, b.tour_type, b.tour_range,
        CASE WHEN LOWER(COALESCE(b.booking_type, '')) IN ('boat','tourguide') THEN b.preferred_resource ELSE NULL END AS preferred_resource
    FROM bookings b
    INNER JOIN tourist t ON b.tourist_id = t.tourist_id
    WHERE b.status='accepted' AND (b.is_complete IS NULL OR b.is_complete = 'uncomplete') {$bookingDateWhere}
    ORDER BY b.booking_date ASC, b.updated_at ASC
    LIMIT 10
");
$stmt->execute($bookingDateParams);
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $upcoming_accepted[] = $row;
}

// ---------------------
// LINE CHART DATA
// ---------------------
function getLineChartData(PDO $pdo, string $filter, array $params): array {
    $labels = $data = [];
    $groupExpr = "DATE_FORMAT(b.created_at, '%Y-%m')";
    if ($filter === 'daily') {
        $groupExpr = "DATE_FORMAT(b.created_at, '%H:00')";
    } elseif ($filter === 'monthly') {
        $groupExpr = "DATE_FORMAT(b.created_at, '%Y-%m-%d')";
    } elseif ($filter === 'yearly') {
        $groupExpr = "DATE_FORMAT(b.created_at, '%Y-%m')";
    }
    $sql = "SELECT {$groupExpr} AS grp, COUNT(*) AS cnt
            FROM bookings b
            WHERE 1=1 {$GLOBALS['bookingCreatedWhere']}
            GROUP BY grp
            ORDER BY grp ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $labels[] = $r['grp'];
        $data[] = (int)$r['cnt'];
    }
    return [$labels, $data];
}
list($line_labels, $line_data) = getLineChartData($pdo, $filter, $bookingCreatedParams);

// ---------------------
// PIE CHART DATA
// ---------------------
$pie_labels = ['Accepted','Completed','Cancelled','Declined'];
$pie_data = [
    fetchCount($pdo, "SELECT COUNT(*) FROM bookings b WHERE b.status='accepted' AND (b.is_complete IS NULL OR b.is_complete='uncomplete') {$bookingCreatedWhere}", $bookingCreatedParams),
    fetchCount($pdo, "SELECT COUNT(*) FROM bookings b WHERE b.is_complete='completed' {$bookingCreatedWhere}", $bookingCreatedParams),
    fetchCount($pdo, "SELECT COUNT(*) FROM bookings b WHERE b.is_complete='cancelled' {$bookingCreatedWhere}", $bookingCreatedParams),
    fetchCount($pdo, "SELECT COUNT(*) FROM bookings b WHERE b.is_complete='declined' {$bookingCreatedWhere}", $bookingCreatedParams)
];

// ---------------------
// BAR CHART DATA
// ---------------------
$total_bookings = fetchCount($pdo, "SELECT COUNT(*) FROM bookings b WHERE 1=1 {$bookingCreatedWhere}", $bookingCreatedParams);
$completed_bookings = fetchCount($pdo, "SELECT COUNT(*) FROM bookings b WHERE b.is_complete='completed' {$bookingCreatedWhere}", $bookingCreatedParams);

// ---------------------
// ACCEPTED VS COMPLETED PIE
// ---------------------
$accepted_unfinished_count = fetchCount($pdo, "SELECT COUNT(*) FROM bookings b WHERE b.status='accepted' AND (b.is_complete IS NULL OR b.is_complete = 'uncomplete') {$bookingCreatedWhere}", $bookingCreatedParams);
$accepted_completed_count = fetchCount($pdo, "SELECT COUNT(*) FROM bookings b WHERE b.status='accepted' AND b.is_complete='completed' {$bookingCreatedWhere}", $bookingCreatedParams);

// ---------------------
// NOTIFICATIONS
// ---------------------
$notifications = $pdo->query("
    SELECT b.booking_id, b.booking_date, b.booking_type, t.full_name, t.profile_picture, b.is_notif_viewed
    FROM bookings b
    INNER JOIN tourist t ON b.tourist_id = t.tourist_id
    ORDER BY b.booking_date DESC, b.updated_at DESC
")->fetchAll(PDO::FETCH_ASSOC);

$unread_count = array_reduce($notifications, fn($carry, $n) => $carry + ($n['is_notif_viewed'] == 0 ? 1 : 0), 0);

?>


<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="icon" type="image/png" href="img/newlogo.png">
<title>iTour Mercedes - Admin Dashboard</title>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600&display=swap" rel="stylesheet" />
<link href="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.8/index.global.min.css" rel="stylesheet">
<!-- Font Awesome CDN -->
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css" crossorigin="anonymous">

<style>
body {
  margin: 0;
  font-family: 'Poppins', sans-serif;
  background: #f0f2f5;
  overflow-x: hidden;
}

.admin-container {
  display: flex;
  min-height: 100vh;
}

.main-content {
  flex: 1;
  transition: transform 0.3s;
  margin-left: 240px;
  width: calc(100vw - 240px);
}

.admin-sidebar.collapsed ~ .main-content {
  margin-left: 80px;
}

.admin-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    background: #fff;
    padding: 1rem 2rem;
    border-bottom: 1px solid #ddd;
    box-shadow: 0 2px 5px rgba(0,0,0,0.05);
}

.admin-header h2 {
    color: #2b7a66;
    margin: 0;
    font-size: 1.6rem;
}

.admin-header-right {
    display: flex;
    align-items: center;
    gap: 12px;
    flex-wrap: wrap;
    justify-content: flex-end;
}

.admin-header-filter {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
    justify-content: flex-end;
}
.admin-header-filter label {
    font-size: 13px;
    font-weight: 600;
    color: #3f5560;
}
.admin-header-filter select,
.admin-header-filter input {
    padding: 8px 11px;
    border-radius: 10px;
    border: 1px solid #d8e6e0;
    font: inherit;
    font-size: 13px;
    background: #fff;
    min-width: 96px;
    color: #24434d;
}
.admin-header-filter button {
    padding: 8px 12px;
    border-radius: 10px;
    border: 1px solid #d8e6e0;
    background: #fff;
    color: #24434d;
    font-weight: 600;
    cursor: pointer;
}
.admin-header-filter #applyFilter {
    background: #fff !important;
    color: #24434d !important;
    border: 1px solid #d8e6e0 !important;
    box-shadow: none !important;
}
.admin-header-filter #applyFilter:hover {
    background: #f1f7f4 !important;
    transform: none !important;
}
#dashboardFilterMonth { min-width: 130px; }
#dashboardFilterDate { min-width: 148px; }
#filterInputContainer { display: none; }

/* Notification icon */
.header-notification {
    width: 32px;
    height: 32px;
    cursor: pointer;
    border-radius: 10px;
    border: 1px solid #d8e6e0;
    padding: 6px;
    background: #fff;
}

.admin-header-profile {
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
    border: 1px solid rgba(43, 122, 102, 0.2);
    box-shadow: 0 4px 10px rgba(28, 74, 62, 0.14);
    text-transform: uppercase;
}


.dashboard-content {
  padding: 1.5rem;
}

.dashboard-row {
  display: flex;
  gap: 1rem;
  flex-wrap: wrap;
  margin-bottom: 1.5rem;
  justify-content: center;
}

.dashboard-box {
  flex: 1 1 140px;
  background: #2b7a66;
  color: #fff;
  display: flex;             
  flex-direction: row;       /* Row instead of column */
  align-items: center;       /* Vertically center icon + text */
  padding: 1rem 1.5rem;      
  border-radius: 12px;
  box-shadow: 0 4px 10px rgba(0,0,0,0.1);
  transition: transform 0.2s, background-color 0.2s;
  min-width: 150px;
  max-width: 100%;
  cursor: pointer;
  position: relative;
}

/* White circle container */
.dashboard-corner-icon {
  position: absolute;
  top: 0px;
  right: 0px;
  width: 50px;
  height: 50px;
  border-radius: 50%;
  display: flex;
  justify-content: center;
  align-items: center;
  z-index: 10;
  transition: 0.2s ease;
}

/* Arrow icon inside */
.dashboard-corner-icon i { /* green */
  font-size: 14px;
  width: 15px;
  height: 15px;
}

/* Hover effect */
.dashboard-box:hover {
  transform: translateY(-2px);
  box-shadow: 0 3px 10px rgba(0,0,0,0.25);
}

.dashboard-box:hover {
  transform: translateY(-5px);
}

.dashboard-icon {
  font-size: 2rem;
  margin-right: 15px;       /* space between icon and text */
  flex-shrink: 0;            /* don’t shrink icon */
}

.dashboard-text-container {
  display: flex;
  flex-direction: column;
  justify-content: center;
}

.dashboard-box h3 {
  margin: 0.25rem 0 0;
  font-size: 1.5rem;
  font-weight: 700;
}

.dashboard-box .dashboard-text {
  font-size: 1rem;
  font-weight: 500;
}


/* Make the two summary boxes wider */
.dashboard-row.summary-row {
  justify-content: space-between;
}
.dashboard-row.summary-row .dashboard-box {
  flex: 1 1 45%;
  max-width: none;
}

/* Layout for chart + calendar */
.dashboard-row.calendar-row {
  align-items: flex-start;
  justify-content: space-between;
}

#calendar {
  flex: 1 1 auto;
  background: #fff;
  border-radius: 12px;
  padding: 1rem;
  box-shadow: 0 4px 10px rgba(0,0,0,0.05);
  min-height: 650px;
  width: 60vw;
  transition: width 0.3s;
}

#chartContainer {
  flex: 0 1 28vw;
  background: #fff;
  border-radius: 12px;
  padding: 1rem;
  box-shadow: 0 4px 10px rgba(0,0,0,0.05);
  display: flex;
  align-items: center;
  justify-content: center;
  min-height: 300px;
  transition: width 0.3s;
}

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
  border: 1px solid #d8e6e0;
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
  border: 1px solid #d8e6e0 !important;
  border-radius: 10px !important;
}
.fc-theme-standard .fc-scrollgrid {
  border: 1px solid #dfe9e5;
  border-radius: 12px;
  overflow: hidden;
}
.fc-theme-standard .fc-scrollgrid-section-header > * {
  background: #f4faf7 !important;
}
.fc .fc-col-header-cell {
  background: #f4faf7 !important;
}
.fc .fc-col-header-cell-cushion {
  color: #2f4b56 !important;
  font-weight: 700 !important;
  padding: 10px 4px;
  text-decoration: none !important;
}
.fc .fc-daygrid-day-number {
  color: #21363f !important;
  text-decoration: none !important;
  font-size: 12px !important;
  font-weight: 600 !important;
}
.fc .fc-daygrid-event {
  border-radius: 8px;
  border: 0;
  padding: 2px 6px;
  background: linear-gradient(135deg, #2b7a66 0%, #226451 100%) !important;
}
.fc .fc-event-title,
.fc .fc-event-time {
  color: #fff !important;
}
.fc-theme-standard td,
.fc-theme-standard th {
  border-color: #dfe9e5 !important;
}
.fc .fc-daygrid-day.fc-day-today {
  background: #e5f5ef !important;
}

/* Responsive */
@media (max-width: 1100px) {
  #calendar, #chartContainer {
    width: 100% !important;
  }
  .dashboard-row.calendar-row {
    flex-direction: column;
  }
}

@media (max-width: 700px) {
  .dashboard-row.summary-row .dashboard-box {
    flex: 1 1 100%;
  }
}

/* Modal overlay */
.booking-modal {
  display: none;
  position: fixed;
  z-index: 9999;
  top: 0;
  left: 0;
  width: 100%;
  height: 100%;
  background: rgba(0,0,0,0.5);
  justify-content: center;
  align-items: center;
  overflow-y: auto;
  padding: 20px;
}

/* Modal container */
.booking-modal-content {
  background: #fff;
  border-radius: 16px;
  max-width: 650px;
  width: 95%;
  max-height: 85%;
  overflow-y: auto;
  padding: 25px 30px;
  position: relative;
  box-shadow: 0 6px 18px rgba(0,0,0,0.15);
  animation: fadeIn 0.25s ease-in-out;
  font-family: 'Poppins', sans-serif;
}

/* Modal close button */
.booking-modal-close {
  position: absolute;
  top: 15px;
  right: 20px;
  font-size: 24px;
  background: none;
  border: none;
  cursor: pointer;
  color: #333;
  transition: color 0.2s;
}
.booking-modal-close:hover {
  color: #2b7a66;
}

/* Modal title */
.booking-modal-title {
  font-size: 1.5rem;
  font-weight: 700;
  color: #2b7a66;
  margin-bottom: 20px;
  text-align: center;
}

/* Scrollable modal body */
.booking-modal-body {
  display: flex;
  flex-direction: column;
  gap: 16px;
}

/* Booking card container */
.booking-card {
  background: #f9f9f9;
  border-radius: 14px;
  box-shadow: 0 4px 14px rgba(0,0,0,0.08);
  transition: transform 0.2s, box-shadow 0.2s;
  overflow: hidden;
}
.booking-card:hover {
  transform: translateY(-2px);
  box-shadow: 0 6px 18px rgba(0,0,0,0.12);
}

/* Card header */
.booking-header {
  font-weight: 700;
  font-size: 16px;
  padding: 10px 15px;
  color: #fff;
  border-radius: 8px 8px 0 0;
  margin: 0;
  display: inline-block;
}

/* Header colors by type */
.booking-header.package { background-color: #2b7a66; }
.booking-header.boat { background-color: #3368A1; }
.booking-header.tourguide { background-color: #FFA500; }

/* Card details */
.booking-details {
  font-size: 15px;
  color: #333;
  line-height: 1.6;
  padding: 10px 15px 15px 15px;
}
.booking-details strong {
  color: #2b7a66
}

/* Fade-in animation */
@keyframes fadeIn {
  from { opacity: 0; transform: translateY(-15px); }
  to { opacity: 1; transform: translateY(0); }
}

.chart-card {
  flex: 1 1 28%;
  min-width: 220px;
  max-width: 100%;
  height: 280px;           /* container height */
  background: #fff;
  border-radius: 12px;
  padding: 12px;
  box-shadow: 0 4px 12px rgba(0,0,0,0.08);
  display: flex;
  flex-direction: column;
  box-sizing: border-box;
}

.chart-card canvas {
  display: block;
  width: 100% !important;
  height: 220px !important; /* explicit height for chart */
  max-height: 220px;        /* optional safety */
}

.chart-header {
  display: flex;              /* make children in a row */
  justify-content: space-between; /* h4 left, controls right */
  align-items: center;        /* vertical alignment */
  margin-bottom: 6px;
}

.chart-header h4 {
  margin: 0;
  font-size: 1rem;
  color: #333;
  font-weight: 600;
}

.chart-controls {
  display: flex;
  gap: 8px;
  align-items: center;
}

.chart-select {
  padding:6px 10px;
  border-radius:8px;
  border:1px solid #e6e6e6;
  background:#fafafa;
  font-size:0.9rem;
}
/* small legend row for the pie */
.pie-legend { display:flex; gap:10px; flex-wrap:wrap; margin-top:8px; font-size:0.9rem; }
.pie-legend .item { display:flex; align-items:center; gap:6px; }
.swatch { width:12px; height:12px; border-radius:3px; display:inline-block; }

#pieChart {
  height: 160px !important;   /* set height for pie chart only */
  width: 210px !important;
  align-self: center !important;
}

#notifBadge {
    position: absolute;
    top: -5px;
    right: -5px;
    min-width: 18px;
    height: 18px;
    background: red;
    color: white;
    font-size: 12px;
    font-weight: 600;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 0 5px;
    z-index: 1000;
    display:none;
}

</style>
<link rel="stylesheet" href="styles/admin_panel_theme.css" />

</head>
<body>
<div class="admin-container">
<?php include 'admin_sidebar.php'; ?>

<main class="main-content">
<header class="admin-header">
    <div class="admin-header-left">
      <h2>Admin Dashboard</h2>
      <p class="admin-header-subtitle">Welcome, <?= $admin_username ?? 'Website Admin' ?></p>
    </div>
    <div class="admin-header-right">
      <div class="admin-header-filter">
        <label for="dashboardFilter">Filter by:</label>
        <select id="dashboardFilter" name="filter">
            <option value="all" <?= $filter==='all'?'selected':'' ?>>All</option>
            <option value="yearly" <?= $filter==='yearly'?'selected':'' ?>>Yearly</option>
            <option value="monthly" <?= $filter==='monthly'?'selected':'' ?>>Monthly</option>
            <option value="daily" <?= $filter==='daily'?'selected':'' ?>>Daily</option>
        </select>
        <select id="dashboardFilterYear" name="year">
          <?php foreach ($availableYears as $yr): ?>
            <option value="<?= (int)$yr ?>" <?= $selectedYear === (int)$yr ? 'selected' : '' ?>><?= (int)$yr ?></option>
          <?php endforeach; ?>
        </select>
        <select id="dashboardFilterMonth" name="month">
          <?php for ($m = 1; $m <= 12; $m++): ?>
            <option value="<?= $m ?>" <?= $selectedMonth === $m ? 'selected' : '' ?>><?= date('F', mktime(0, 0, 0, $m, 1)) ?></option>
          <?php endfor; ?>
        </select>
        <input id="dashboardFilterDate" type="date" name="date" value="<?= htmlspecialchars($selectedDate) ?>" />
        <button id="applyFilter" type="button">Apply</button>
      </div>
    </div>
</header>

<section class="dashboard-content">

<div class="dashboard-row">

  <div class="dashboard-box" onclick="window.location.href='adbookings.php?filter=accepted';">
    <div class="dashboard-corner-icon">
      <img src="img/arrowrightup.png" style="height: 30px; width: 30px;" alt="arrow">
    </div>

    <i class="fa-solid fa-calendar-check dashboard-icon"></i>
    <div class="dashboard-text-container">
      <div class="dashboard-text">Accepted Bookings</div>
      <h3><?= $accepted_bookings ?></h3>
    </div>
  </div>

  <div class="dashboard-box" onclick="window.location.href='adbookings.php';">
    <div class="dashboard-corner-icon">
      <img src="img/arrowrightup.png" style="height: 30px; width: 30px;" alt="arrow">
    </div>

    <i class="fa-solid fa-calendar-days dashboard-icon"></i>
    <div class="dashboard-text-container">
      <div class="dashboard-text">Total Inquiry</div>
      <h3><?= $total_bookings ?></h3>
    </div>
  </div>

  <div class="dashboard-box" onclick="window.location.href='adbookings.php?filter=completed';">
    <div class="dashboard-corner-icon">
      <img src="img/arrowrightup.png" style="height: 30px; width: 30px;" alt="arrow">
    </div>

    <i class="fa-solid fa-check-circle dashboard-icon"></i>
    <div class="dashboard-text-container">
      <div class="dashboard-text">Completed Bookings</div>
      <h3><?= $completed_bookings ?></h3>
    </div>
  </div>

  <div class="dashboard-box" onclick="window.location.href='adtourists.php';">
    <div class="dashboard-corner-icon">
      <img src="img/arrowrightup.png" style="height: 30px; width: 30px;" alt="arrow">
    </div>

    <i class="fa-solid fa-users dashboard-icon"></i>
    <div class="dashboard-text-container">
      <div class="dashboard-text">Tourist Accounts</div>
      <h3><?= $total_tourists ?></h3>
    </div>
  </div>

</div>

<div class="dashboard-row">
  <!-- LINE CHART - 50% width -->
  <div class="chart-card" style="flex: 0 1 49.3%;">
    <div class="chart-header">
      <h4>Bookings Created</h4>
    </div>
    <canvas id="lineChart"></canvas>
  </div>

  <!-- PIE CHART - 25% width -->
  <div class="chart-card" style="flex: 0 1 24%;">
    <div class="chart-header">
      <h4>Bookings Status</h4>
    </div>
    <canvas id="pieChart"></canvas>
    <div class="pie-legend" id="pieLegend"></div>
  </div>

  <!-- BAR CHART - 25% width -->
  <div class="chart-card" style="flex: 0 1 24%;">
    <div class="chart-header">
      <h4>Total & Completed Bookings</h4>
    </div>
    <canvas id="barChart"></canvas>
  </div>
</div>


<!-- Calendar + Right Boxes Row -->
<div class="dashboard-row calendar-row" style="align-items:flex-start; gap:1rem;">

  <!-- Calendar (left side) -->
  <div id="calendar" style="flex: 1 1 65%;"></div>

  <!-- Right side (two stacked boxes) -->
  <div style="flex: 0 1 32%; display:flex; flex-direction:column; gap:1rem;">

<!-- Upcoming Accepted Bookings -->
<div class="chart-card" style="flex: none; height: 360px;">
  <div class="chart-header"><h4>Upcoming Accepted Bookings</h4></div>
  <div style="overflow-y:auto; padding: 15px">
    <?php foreach($upcoming_accepted as $b): ?>
      <div class="booking-card" style="margin-bottom:8px;">
        <div class="booking-header <?= strtolower($b['booking_type']) ?>"><?= $b['booking_type'] ?></div>
        <div class="booking-details">
          <strong>Name:</strong> <?= htmlspecialchars($b['full_name']) ?><br>
          <strong>Date:</strong> <?= $b['booking_date'] ?><br>
          <strong>Pax:</strong> <?= $b['pax'] ?><br>
          <strong>Package/Location:</strong> <?= $b['package_name'] ?: $b['location'] ?><br>
          <strong>Jump Off Port:</strong> <?= $b['jump_off_port'] ?: '-' ?><br>
          <strong>Tour Type:</strong> <?= $b['tour_type'] ?: '-' ?><br>
          <strong>Tour Range:</strong> <?= $b['tour_range'] ?: '-' ?><br>
          <?php if(in_array(strtolower((string)$b['booking_type']), ['boat', 'tourguide'], true)): ?>
              <strong>Preferred Resource:</strong> <?= $b['preferred_resource'] ?: '-' ?><br>
          <?php endif; ?>
          <strong>Phone:</strong> <?= $b['phone_number'] ?>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
</div>

    <!-- Accepted Completed vs Unfinished Pie -->
    <div class="chart-card" style="flex: none; height: 273px;">
      <div class="chart-header"><h4>Accepted Bookings Status</h4></div>
      <canvas id="acceptedPieChart"></canvas>
    </div>

  </div>

</div>


</section>
</main>
</div>

<div id="bookingModal" class="booking-modal">
  <div class="booking-modal-content">
    <button id="closeModal" class="booking-modal-close">&times;</button>
    <h3 class="booking-modal-title">Bookings for <span id="modalDate"></span></h3>
    <div id="modalContent" class="booking-modal-body"></div>
  </div>
</div>


<script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.8/index.global.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
  // -------------------------
  // DATA
  // -------------------------
  const lineSeries = {
    labels: <?= json_encode($line_labels, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) ?>,
    data: <?= json_encode($line_data, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) ?>
  };

  const pieLabels = <?= json_encode($pie_labels, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) ?>;
  const pieData = <?= json_encode($pie_data, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) ?>;

  const barLabels = ['Total Bookings','Completed Bookings'];
  const barData = [<?= $total_bookings ?>, <?= $completed_bookings ?>];

  const palette = {
    green: '#2b7a66',
    blue: '#3368A1',
    yellow: '#FFB74D',
    red: '#F28B82',
    gray: '#E0E0E0'
  };

  // -------------------------
  // LINE CHART
  // -------------------------
  const lineCtx = document.getElementById('lineChart').getContext('2d');
  const lineChart = new Chart(lineCtx, {
    type: 'line',
    data: {
      labels: lineSeries.labels,
      datasets: [{
        label: 'Bookings',
        data: lineSeries.data,
        tension: 0.25,
        borderWidth: 2,
        fill: true,
        backgroundColor: 'rgba(73,164,122,0.12)',
        borderColor: palette.green,
        pointRadius: 3,
        pointBackgroundColor: palette.green
      }]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,  // fill the card
      plugins: { legend: { display: false }, tooltip: { mode: 'index', intersect:false } },
      scales: {
        x: { ticks: { maxRotation: 0, sampleSize: 12 } },
        y: { beginAtZero: true }
      }
    }
  });

  // -------------------------
  // PIE CHART
  // -------------------------
  const pieCtx = document.getElementById('pieChart').getContext('2d');
  const pieColors = [palette.green, palette.blue, palette.red, palette.yellow];
  const pieChart = new Chart(pieCtx, {
    type: 'doughnut',
    data: { labels: pieLabels, datasets:[{ data: pieData, backgroundColor: pieColors, borderWidth:0 }] },
    options: {
      responsive: true,
      maintainAspectRatio: false,  // fill the card
      plugins: {
        legend: { display: false },
        tooltip: { callbacks: { label: ctx => `${ctx.label}: ${ctx.parsed}` } }
      }
    }
  });

  // Pie chart legend
  const legendWrap = document.getElementById('pieLegend');
  legendWrap.innerHTML = '';
  pieLabels.forEach((lab, idx) => {
    const div = document.createElement('div');
    div.className = 'item';
    const sw = document.createElement('span');
    sw.className = 'swatch';
    sw.style.background = pieColors[idx];
    const txt = document.createElement('span');
    txt.textContent = `${lab} (${pieData[idx]||0})`;
    div.appendChild(sw);
    div.appendChild(txt);
    legendWrap.appendChild(div);
  });

  // -------------------------
  // BAR CHART (vertical & small)
  // -------------------------
  const barCtx = document.getElementById('barChart').getContext('2d');
  const barChart = new Chart(barCtx, {
    type: 'bar',          // vertical
    data: {
      labels: barLabels,
      datasets: [{
        label: 'Count',
        data: barData,
        backgroundColor: [palette.gray, palette.green],
        borderWidth: 0
      }]
    },
    options: {
      indexAxis: 'x',      // vertical bars
      responsive: true,
      maintainAspectRatio: false,  // fill the card
      plugins: { legend: { display: false }, tooltip: { mode: 'nearest' } },
      scales: { y: { beginAtZero: true } }
    }
  });

 // -------------------------
  // FULLCALENDAR
  // -------------------------
  const calendarEl = document.getElementById('calendar');
  const eventsData = <?= json_encode($calendar_events, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) ?>;

  const bookingCountPerDay = {};
  eventsData.forEach(ev => {
    if(!bookingCountPerDay[ev.start]) bookingCountPerDay[ev.start] = [];
    bookingCountPerDay[ev.start].push(ev);
  });

  const dayEvents = Object.keys(bookingCountPerDay).map(date => ({
    title: bookingCountPerDay[date].length + ' Booking(s)',
    start: date,
    allDay: true,
    backgroundColor: '#2b7a66',
    extendedProps: { bookings: bookingCountPerDay[date] }
  }));

  const calendar = new FullCalendar.Calendar(calendarEl, {
    initialView: 'dayGridMonth',
    initialDate: '<?= htmlspecialchars($calendarInitialDate) ?>',
    height: 520,
    buttonText: { today: 'Today', month: 'Month', week: 'Week', day: 'Day' },
    headerToolbar: { left:'prev,next today', center:'title', right:'dayGridMonth,timeGridWeek,timeGridDay' },
    events: dayEvents,
    eventClick: function(info) {
    const modal = document.getElementById('bookingModal');
    const content = document.getElementById('modalContent');
    const modalDate = document.getElementById('modalDate');

    modalDate.textContent = info.event.startStr;
    content.innerHTML = '';

    // Sort bookings by updated_at (ascending: earliest first)
    const sortedBookings = info.event.extendedProps.bookings.sort((a, b) => {
      const dateA = new Date((a.details.updated_at || a.details.date).replace(' ', 'T'));
      const dateB = new Date((b.details.updated_at || b.details.date).replace(' ', 'T'));
      return dateA - dateB; // <-- smallest/earliest first
    });

    sortedBookings.forEach(b => {
      const d = b.details;
      const bookingType = d.booking_type || 'Package';
      const box = document.createElement('div'); 
      box.className='booking-card';

      const header = document.createElement('div'); 
      header.className='booking-header'; 
      header.textContent = bookingType;
      switch(bookingType.toLowerCase()){
        case 'package': header.style.backgroundColor='#2b7a66'; break;
        case 'boat': header.style.backgroundColor='#3368A1'; break;
        case 'tourguide': header.style.backgroundColor='#FFA500'; break;
        default: header.style.backgroundColor='#2b7a66';
      }
      box.appendChild(header);

     const details = document.createElement('div'); 
        details.className = 'booking-details';
        details.innerHTML = `
          <strong>Name:</strong> ${d.full_name || '-'}<br>
          <strong>Email:</strong> ${d.email || '-'}<br>
          <strong>Phone:</strong> ${d.phone || '-'}<br>
          ${bookingType.toLowerCase() === 'package' ? `<strong>Package:</strong> ${d.package_name || '-'}<br>` : ''}
          ${bookingType.toLowerCase() === 'boat' || bookingType.toLowerCase() === 'tourguide' ? `
              <strong>Location:</strong> ${d.location || '-'}<br>
              <strong>Jump Off Port:</strong> ${d.jump_off_port || '-'}<br>
              <strong>Tour Type:</strong> ${d.tour_type || '-'}<br>
              <strong>Tour Range:</strong> ${d.tour_range || '-'}<br>
              <strong>Preferred Resource:</strong> ${d.preferred_resource || '-'}<br>
          ` : `
              <strong>Jump Off Port:</strong> ${d.jump_off_port || '-'}<br>
              <strong>Tour Type:</strong> ${d.tour_type || '-'}<br>
              <strong>Tour Range:</strong> ${d.tour_range || '-'}<br>
          `}
          <strong>Pax:</strong> ${d.pax || '-'}<br>
          <strong>Date:</strong> ${d.date || '-'}
        `;
        box.appendChild(details);
        content.appendChild(box);

    });

    modal.style.display = 'flex';
  }

  });
  calendar.render();

  // Close modal
  document.getElementById('closeModal').addEventListener('click', ()=>{ 
    document.getElementById('bookingModal').style.display='none'; 
  });

const acceptedPieCtx = document.getElementById('acceptedPieChart').getContext('2d');
const acceptedPieChart = new Chart(acceptedPieCtx, {
  type: 'doughnut',
  data: {
    labels: ['Accepted Completed','Accepted Uncomplete'],
    datasets: [{
      data: [<?= $accepted_completed_count ?>, <?= $accepted_unfinished_count ?>],
      backgroundColor: [palette.green, palette.yellow],
      borderWidth: 0
    }]
  },
  options: {
    responsive: true,
    maintainAspectRatio: false,
    plugins: {
      legend: { display: true, position: 'bottom' },
      tooltip: { callbacks: { label: ctx => `${ctx.label}: ${ctx.parsed}` } }
    }
  }
});

});

const filterSelect = document.getElementById('dashboardFilter');
const filterYear = document.getElementById('dashboardFilterYear');
const filterMonth = document.getElementById('dashboardFilterMonth');
const filterDate = document.getElementById('dashboardFilterDate');
const filterInputContainer = document.getElementById('filterInputContainer');

function updateFilterVisibility() {
    const mode = filterSelect.value;
    if (filterInputContainer) filterInputContainer.style.display = 'none';
    if (filterYear) filterYear.style.display = (mode === 'yearly' || mode === 'monthly' || mode === 'daily') ? '' : 'none';
    if (filterMonth) filterMonth.style.display = (mode === 'monthly') ? '' : 'none';
    if (filterDate) filterDate.style.display = (mode === 'daily') ? '' : 'none';
}

filterSelect.addEventListener('change', updateFilterVisibility);
updateFilterVisibility();

document.getElementById('applyFilter').addEventListener('click', () => {
    const mode = filterSelect.value;
    const url = new URL(window.location.href);

    url.searchParams.set('filter', mode);
    url.searchParams.delete('year');
    url.searchParams.delete('month');
    url.searchParams.delete('date');

    if ((mode === 'yearly' || mode === 'monthly' || mode === 'daily') && filterYear.value) {
        url.searchParams.set('year', filterYear.value);
    }
    if (mode === 'monthly' && filterMonth.value) {
        url.searchParams.set('month', filterMonth.value);
    }
    if (mode === 'daily' && filterDate.value) {
        url.searchParams.set('date', filterDate.value);
    }

    window.location.href = url.toString();
});

</script>



</body>
</html>

