<?php
chdir(__DIR__ . '/..');
require_once __DIR__ . '/../php/session_security.php';
AppSessionStart();
require_once __DIR__ . '/../php/db_connection.php';
require_once __DIR__ . '/../php/activity_logger.php';
require_once __DIR__ . '/../php/admin_auth_helper.php';
require_once __DIR__ . '/../php/booking_cancellations_helper.php';

// ---------------------
// LOGOUT HANDLER
// ---------------------
if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    logActivity(
        $pdo,
        'Admin',
        (int)($_SESSION['admin_id'] ?? 0),
        (string)($_SESSION['admin_name'] ?? 'Administrator'),
        'Logout',
        'Signed out of the admin panel.',
        'Authentication'
    );
    AppDestroySession();
    AppSessionStart();
    $_SESSION['alert'] = [
        'type' => 'success',
        'title' => 'Logout Successful',
        'message' => 'You have securely signed out of the administrator portal.'
    ];
    header('Location: ' . AdminLoginUrl('adhomepage.php'));
    exit();
}

// ---------------------
// SESSION CHECK
// ---------------------
AdminRequireLogin();
ensureBookingCancellationRequestsTable($pdo);

// Prevent caching
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

function resolveDashboardTouristProfileImage(?string $profilePicture): string
{
    $profilePicture = trim((string)$profilePicture);
    if ($profilePicture === '') return '';
    if (preg_match('#^https?://#i', $profilePicture)) return $profilePicture;

    $candidates = [
        'uploads/profile_pictures/' . basename($profilePicture),
        'uploads/profile_picture/' . basename($profilePicture),
        ltrim($profilePicture, '/'),
    ];
    foreach ($candidates as $candidate) {
        if (is_file(getcwd() . '/' . $candidate)) return $candidate;
    }
    return '';
}

function adminDashboardTrendVisual(int $current, int $previous, string $comparisonLabel = 'vs last month', bool $lowerIsBetter = false): string
{
    $direction = $current > $previous ? 'up' : ($current < $previous ? 'down' : 'flat');
    $favorable = $direction === 'flat' || ($lowerIsBetter ? $direction === 'down' : $direction === 'up');
    $tone = $direction === 'flat' ? 'neutral' : ($favorable ? 'positive' : 'attention');
    $points = match ($direction) {
        'up' => '2,23 15,18 28,20 43,10 56,13 70,3',
        'down' => '2,5 15,10 28,8 43,18 56,15 70,24',
        default => '2,14 15,12 28,15 43,13 56,14 70,13',
    };
    $tip = $direction === 'up' ? 'M64 3h6v6' : ($direction === 'down' ? 'M64 24h6v-6' : 'M65 10l5 3-5 3');
    $endpointY = $direction === 'up' ? 3 : ($direction === 'down' ? 24 : 13);
    if ($direction === 'flat') {
        $changeText = '— No change';
    } elseif ($previous === 0) {
        $changeText = ($direction === 'up' ? '↑' : '↓') . ' New';
    } else {
        $percentage = abs((($current - $previous) / $previous) * 100);
        $formattedPercentage = number_format($percentage, $percentage == floor($percentage) ? 0 : 1);
        $changeText = ($direction === 'up' ? '↑ ' : '↓ ') . $formattedPercentage . '%';
    }
    return '<div class="admin-dashboard-insight '.$tone.'"><span class="admin-dashboard-comparison"><strong>'.$changeText.'</strong><small>'.htmlspecialchars($comparisonLabel, ENT_QUOTES, 'UTF-8').'</small></span><span class="admin-dashboard-trend" aria-hidden="true"><svg viewBox="0 0 72 28"><path class="guide" d="M2 25H70"/><polyline class="line" points="'.$points.'"/><circle cx="70" cy="'.$endpointY.'" r="2.5"/><path class="arrow" d="'.$tip.'"/></svg></span></div>';
}

// ---------------------
// DATABASE CONNECTION
// ---------------------
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
    WHERE b.status='accepted' AND (b.is_complete IS NULL OR b.is_complete = 'uncomplete')
      AND NOT EXISTS (
          SELECT 1 FROM booking_cancellation_requests cr
          WHERE cr.booking_domain='tour' AND cr.booking_id=b.booking_id
            AND cr.request_status IN ('approved','decision_required')
      ) {$bookingDateWhere}
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
            'booking_id' => (int)$row['booking_id'],
            'full_name' => $row['full_name'],
            'email' => $row['email'],
            'phone' => $row['phone_number'],
            'profile_picture' => resolveDashboardTouristProfileImage($row['profile_picture'] ?? ''),
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
        b.booking_type, t.full_name, t.email, t.profile_picture,
        b.jump_off_port, b.tour_type, b.tour_range,
        CASE WHEN LOWER(COALESCE(b.booking_type, '')) IN ('boat','tourguide') THEN b.preferred_resource ELSE NULL END AS preferred_resource
    FROM bookings b
    INNER JOIN tourist t ON b.tourist_id = t.tourist_id
    WHERE b.status='accepted' AND (b.is_complete IS NULL OR b.is_complete = 'uncomplete')
      AND NOT EXISTS (
          SELECT 1 FROM booking_cancellation_requests cr
          WHERE cr.booking_domain='tour' AND cr.booking_id=b.booking_id
            AND cr.request_status IN ('approved','decision_required')
      ) {$bookingDateWhere}
    ORDER BY b.booking_date ASC, b.updated_at ASC
    LIMIT 10
");
$stmt->execute($bookingDateParams);
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $row['profile_picture'] = resolveDashboardTouristProfileImage($row['profile_picture'] ?? '');
    $upcoming_accepted[] = $row;
}

$scheduleReminder = [
    'state' => 'empty',
    'title' => 'No upcoming schedules',
    'message' => 'Accepted bookings will be highlighted here when a schedule is available.',
];
$today = date('Y-m-d');
$nextSchedule = null;
foreach ($upcoming_accepted as $booking) {
    if ((string)($booking['booking_date'] ?? '') >= $today) {
        $nextSchedule = $booking;
        break;
    }
}
if ($nextSchedule) {
    $nextDate = (string)$nextSchedule['booking_date'];
    $nextCount = count(array_filter($upcoming_accepted, static fn(array $booking): bool => (string)($booking['booking_date'] ?? '') === $nextDate));
    $nextTimestamp = strtotime($nextDate);
    $nextLabel = $nextTimestamp ? date('l, F j, Y', $nextTimestamp) : $nextDate;
    $scheduleReminder = [
        'state' => 'upcoming',
        'title' => $nextCount . ' booking' . ($nextCount === 1 ? '' : 's') . ' coming up',
        'message' => 'Next schedule: ' . $nextLabel . '. Review guest and resource arrangements.',
    ];
} elseif (!empty($upcoming_accepted)) {
    $latestSchedule = $upcoming_accepted[array_key_last($upcoming_accepted)];
    $latestDate = (string)($latestSchedule['booking_date'] ?? '');
    $latestTimestamp = strtotime($latestDate);
    $latestLabel = $latestTimestamp ? date('l, F j, Y', $latestTimestamp) : ($latestDate ?: 'the latest schedule');
    $scheduleReminder = [
        'state' => 'overdue',
        'title' => 'Schedule follow-up required',
        'message' => 'The latest unfinished booking was ' . $latestLabel . '. Review its completion status.',
    ];
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
$bookingTrendGroupExpr = "DATE_FORMAT(b.created_at, '%Y-%m')";
if ($filter === 'daily') $bookingTrendGroupExpr = "DATE_FORMAT(b.created_at, '%H:00')";
elseif ($filter === 'monthly') $bookingTrendGroupExpr = "DATE_FORMAT(b.created_at, '%Y-%m-%d')";
$statusTrendStmt = $pdo->prepare("SELECT {$bookingTrendGroupExpr} grp, LOWER(COALESCE(b.status,'')) status, LOWER(COALESCE(b.is_complete,'')) completion, COUNT(*) cnt FROM bookings b WHERE 1=1 {$bookingCreatedWhere} GROUP BY grp,status,completion ORDER BY grp ASC");
$statusTrendStmt->execute($bookingCreatedParams);
$adminStatusTrendMap = [];
foreach ($statusTrendStmt->fetchAll(PDO::FETCH_ASSOC) as $trendRow) {
    $group = (string)$trendRow['grp'];
    $count = (int)$trendRow['cnt'];
    if ((string)$trendRow['status'] === 'accepted' && in_array((string)$trendRow['completion'], ['', 'uncomplete'], true)) $adminStatusTrendMap[$group]['accepted'] = ($adminStatusTrendMap[$group]['accepted'] ?? 0) + $count;
    if ((string)$trendRow['completion'] === 'completed') $adminStatusTrendMap[$group]['completed'] = ($adminStatusTrendMap[$group]['completed'] ?? 0) + $count;
}
$bookingCurrentIndex = max(0,count($line_labels)-1); $bookingPreviousIndex = max(0,$bookingCurrentIndex-1);
$bookingCurrentGroup = (string)($line_labels[$bookingCurrentIndex]??''); $bookingPreviousGroup = count($line_labels)>1?(string)($line_labels[$bookingPreviousIndex]??''):'';
$touristTrendGroupExpr = "DATE_FORMAT(created_at, '%Y-%m')";
if ($filter === 'daily') $touristTrendGroupExpr = "DATE_FORMAT(created_at, '%H:00')";
elseif ($filter === 'monthly') $touristTrendGroupExpr = "DATE_FORMAT(created_at, '%Y-%m-%d')";
$touristTrendStmt = $pdo->prepare("SELECT {$touristTrendGroupExpr} grp, COUNT(*) cnt FROM tourist WHERE 1=1 {$touristWhere} GROUP BY grp ORDER BY grp ASC");
$touristTrendStmt->execute($touristParams);
$touristTrendRows = $touristTrendStmt->fetchAll(PDO::FETCH_ASSOC);
$touristCurrentIndex=max(0,count($touristTrendRows)-1);$touristPreviousIndex=max(0,$touristCurrentIndex-1);
$adminTrendComparisonLabel = $filter === 'daily' ? 'vs previous hour' : ($filter === 'monthly' ? 'vs previous day' : 'vs last month');
$adminDashboardTrends = [
    'accepted'=>[(int)($adminStatusTrendMap[$bookingCurrentGroup]['accepted']??0),(int)($adminStatusTrendMap[$bookingPreviousGroup]['accepted']??0),$adminTrendComparisonLabel],
    'total'=>[(int)($line_data[$bookingCurrentIndex]??0),count($line_data)>1?(int)($line_data[$bookingPreviousIndex]??0):0,$adminTrendComparisonLabel],
    'completed'=>[(int)($adminStatusTrendMap[$bookingCurrentGroup]['completed']??0),(int)($adminStatusTrendMap[$bookingPreviousGroup]['completed']??0),$adminTrendComparisonLabel],
    'tourists'=>[(int)($touristTrendRows[$touristCurrentIndex]['cnt']??0),count($touristTrendRows)>1?(int)($touristTrendRows[$touristPreviousIndex]['cnt']??0):0,$adminTrendComparisonLabel],
];

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

/* Professional schedule workspace */
.dashboard-row.calendar-row {
  display: grid;
  grid-template-columns: minmax(0, 1.75fr) minmax(320px, 0.9fr);
  align-items: stretch;
  gap: 18px;
  margin-bottom: 0;
}

.dashboard-calendar-panel,
.dashboard-side-panel {
  min-width: 0;
}

.dashboard-calendar-panel {
  overflow: hidden;
  height: 100%;
  display: flex;
  flex-direction: column;
  background: #fff;
  border: 1px solid #dce8e3;
  border-radius: 18px;
  box-shadow: 0 12px 30px rgba(17, 66, 53, 0.08);
}

.schedule-panel-heading {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 18px;
  padding: 20px 22px 16px;
  border-bottom: 1px solid #e5eee9;
  background: linear-gradient(135deg, #fbfefd 0%, #f1f8f5 100%);
}

.schedule-panel-heading-main {
  display: flex;
  align-items: flex-start;
  gap: 12px;
  min-width: 0;
}

.schedule-panel-icon,
.upcoming-panel-icon {
  width: 40px;
  height: 40px;
  flex: 0 0 40px;
  display: grid;
  place-items: center;
  color: #17634f;
  border: 1px solid #cce1d9;
  border-radius: 11px;
  background: #e8f4ef;
}

.schedule-panel-icon i,
.upcoming-panel-icon i {
  width: 17px;
  height: 17px;
  display: grid;
  place-items: center;
  font-size: 16px;
  line-height: 1;
}

.schedule-eyebrow {
  display: block;
  margin-bottom: 3px;
  color: #6b817a;
  font-size: 10px;
  font-weight: 700;
  letter-spacing: .1em;
  text-transform: uppercase;
}

.schedule-panel-heading h3,
.upcoming-panel-heading h3 {
  margin: 0;
  color: #173f34;
  font-size: 16px;
  font-weight: 700;
  line-height: 1.35;
}

.schedule-panel-heading p,
.upcoming-panel-heading p {
  margin: 4px 0 0;
  color: #71837d;
  font-size: 11px;
  line-height: 1.45;
}

.schedule-panel-alert {
  min-width: 275px;
  max-width: 355px;
  display: grid;
  grid-template-columns: 34px minmax(0, 1fr);
  align-items: center;
  gap: 10px;
  flex: 0 1 355px;
  padding: 9px 11px;
  color: #5f4813;
  border: 1px solid #ead8a8;
  border-radius: 11px;
  background: linear-gradient(135deg, #fffaf0, #fff5d7);
}

.schedule-panel-alert.upcoming { color: #24594a; border-color: #c9e0d7; background: linear-gradient(135deg, #f2faf7, #e7f4ef); }
.schedule-panel-alert.empty { color: #5f746d; border-color: #dce7e3; background: #f7faf9; }

.schedule-panel-alert .schedule-alert-icon {
  width: 34px;
  height: 34px;
  display: grid;
  place-items: center;
  color: #8a6717;
  border-radius: 9px;
  background: rgba(225, 184, 71, .2);
}

.schedule-panel-alert.upcoming .schedule-alert-icon { color: #17634f; background: #dcefe8; }
.schedule-panel-alert.empty .schedule-alert-icon { color: #647a73; background: #e8efec; }
.schedule-alert-icon svg {
  width: 15px;
  height: 15px;
  display: block;
  fill: none;
  stroke: currentColor;
  stroke-width: 1.8;
  stroke-linecap: round;
  stroke-linejoin: round;
}
.schedule-panel-alert strong { display: block; margin-bottom: 2px; font-size: 10px; line-height: 1.3; }
.schedule-panel-alert > div > span { display: block; font-size: 8.5px; line-height: 1.4; }

#calendar {
  width: 100%;
  min-height: 0;
  flex: 1 1 auto;
  padding: 17px 18px 20px;
  border: 0 !important;
  border-radius: 0 !important;
  box-shadow: none !important;
}

.fc .fc-toolbar.fc-header-toolbar {
  margin-bottom: 15px;
}

.fc .fc-toolbar-title {
  color: #173f34;
  font-size: 18px;
  letter-spacing: -.015em;
}

.fc .fc-button {
  min-height: 34px;
  transition: color .18s ease, background .18s ease, border-color .18s ease, transform .18s ease;
}

.fc .fc-button:hover:not(:disabled) {
  color: #155a47 !important;
  background: #e4f2ed !important;
  transform: translateY(-1px);
}

.fc .fc-button:focus-visible {
  outline: 3px solid rgba(43, 122, 102, .2) !important;
  outline-offset: 2px;
}

.fc-theme-standard .fc-scrollgrid {
  border-color: #dce8e3 !important;
  border-radius: 13px;
}

.fc .fc-col-header-cell-cushion {
  padding: 11px 4px;
  color: #36584f !important;
  font-size: 11px;
  letter-spacing: .025em;
}

.fc .fc-daygrid-day-frame {
  min-height: 84px;
  transition: background-color .18s ease, box-shadow .18s ease;
}

.fc .fc-daygrid-day:hover .fc-daygrid-day-frame {
  background: #f7fbf9;
  box-shadow: inset 0 0 0 1px rgba(43, 122, 102, .08);
}

.fc .fc-daygrid-day-number {
  min-width: 27px;
  height: 27px;
  display: inline-grid;
  place-items: center;
  margin: 5px;
  padding: 0 !important;
  border-radius: 8px;
}

.fc .fc-day-today .fc-daygrid-day-number {
  color: #fff !important;
  background: #246e5a;
  box-shadow: 0 4px 10px rgba(36, 110, 90, .2);
}

.fc .fc-daygrid-day.fc-day-today {
  background: #edf7f3 !important;
}

.fc .fc-daygrid-event {
  margin: 3px 5px !important;
  padding: 0;
  border-radius: 7px;
  background: transparent !important;
  overflow: visible;
  box-shadow: none;
  cursor: pointer;
  transition: transform .18s ease, box-shadow .18s ease, filter .18s ease;
}

.fc .fc-daygrid-event:hover,
.fc .fc-daygrid-event:focus {
  z-index: 3;
  transform: translateY(-1px);
  filter: brightness(1.04);
  box-shadow: 0 7px 14px rgba(31, 101, 81, .22);
}

.fc .fc-event-title {
  overflow: hidden;
  font-size: 10px;
  font-weight: 700;
  text-overflow: ellipsis;
}

.fc .fc-event-main { overflow: visible; }

.fc .fc-dayGridMonth-view .fc-scroller {
  height: auto !important;
  overflow: visible !important;
}

.calendar-booking-indicator {
  min-width: 0;
  display: flex;
  align-items: center;
  gap: 6px;
  min-height: 27px;
  padding: 5px 7px;
  color: #fff;
  border: 1px solid #17604c;
  border-radius: 8px;
  background: linear-gradient(135deg, #287a63, #185845);
  box-shadow: 0 4px 9px rgba(25, 91, 70, .18);
}

.calendar-booking-icon {
  width: 14px;
  height: 14px;
  flex: 0 0 14px;
  display: inline-grid;
  place-items: center;
  font-size: 9px;
  line-height: 1;
  opacity: .92;
}

.calendar-booking-label {
  overflow: hidden;
  font-size: 9px;
  font-weight: 800;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.dashboard-side-panel {
  height: 100%;
  display: flex;
  flex-direction: column;
  gap: 18px;
}

.upcoming-bookings-panel {
  height: 480px;
  min-height: 480px;
  max-height: 480px;
  flex: 0 0 480px;
  overflow: hidden;
  display: flex;
  flex-direction: column;
  padding: 0;
}

.upcoming-panel-heading {
  display: flex;
  align-items: center;
  gap: 11px;
  padding: 15px 16px 12px;
}

.upcoming-panel-heading-copy { min-width: 0; flex: 1; }

.upcoming-count {
  min-width: 30px;
  height: 25px;
  display: inline-grid;
  place-items: center;
  padding: 0 8px;
  color: #17634f;
  font-size: 10px;
  font-weight: 800;
  border: 1px solid #c7dfd6;
  border-radius: 999px;
  background: #e7f3ef;
}

.upcoming-bookings-list {
  min-height: 0;
  flex: 1;
  overflow-y: auto;
  padding: 0 13px 14px;
  scrollbar-width: thin;
  scrollbar-color: #8bb6a8 #edf4f1;
}

.upcoming-bookings-list::-webkit-scrollbar { width: 6px; }
.upcoming-bookings-list::-webkit-scrollbar-track { background: #edf4f1; border-radius: 999px; }
.upcoming-bookings-list::-webkit-scrollbar-thumb { background: #8bb6a8; border-radius: 999px; }

.upcoming-booking-card {
  position: relative;
  margin-bottom: 10px;
  overflow: hidden;
  border: 1px solid #d9e6e1 !important;
  border-radius: 13px !important;
  background: #fff !important;
  box-shadow: 0 6px 16px rgba(22, 67, 54, .07);
}

.upcoming-booking-card::before {
  position: absolute;
  inset: 0 auto 0 0;
  width: 3px;
  content: "";
  background: #287861;
}

.upcoming-booking-card:hover {
  transform: translateY(-2px);
  border-color: #b9d5cb !important;
  box-shadow: 0 10px 22px rgba(22, 67, 54, .12);
}

.upcoming-card-top {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
  padding: 12px 13px 9px 15px;
  border-bottom: 1px solid #edf2f0;
}

.booking-type-badge {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 5px 8px;
  color: #17604c;
  font-size: 9px;
  font-weight: 800;
  letter-spacing: .04em;
  text-transform: uppercase;
  border-radius: 7px;
  background: #e7f3ef;
}

.booking-type-badge.boat { color: #285a88; background: #eaf2fa; }
.booking-type-badge.tourguide { color: #806019; background: #fff5d9; }

.upcoming-booking-date {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  color: #506a62;
  font-size: 9px;
  font-weight: 700;
  white-space: nowrap;
}

.upcoming-card-body { padding: 11px 13px 12px 15px; }

.upcoming-customer {
  display: flex;
  align-items: center;
  gap: 9px;
  margin-bottom: 11px;
}

.upcoming-customer-avatar {
  width: 34px;
  height: 34px;
  flex: 0 0 34px;
  display: grid;
  place-items: center;
  color: #fff;
  font-size: 11px;
  font-weight: 800;
  border-radius: 10px;
  background: linear-gradient(135deg, #2b7a66, #1c5b49);
  overflow: hidden;
  position: relative;
}

.upcoming-customer-avatar img,
.calendar-modal-avatar img,
#bookingDetailsModal .bd-guest-avatar img {
  position: absolute;
  inset: 0;
  width: 100%;
  height: 100%;
  display: block;
  object-fit: cover;
}

.upcoming-customer strong {
  display: block;
  overflow: hidden;
  color: #243f37;
  font-size: 11px;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.upcoming-customer span {
  display: block;
  margin-top: 2px;
  color: #758780;
  font-size: 9px;
}

.upcoming-detail-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 8px;
}

.upcoming-detail-item {
  min-width: 0;
  padding: 8px 9px;
  border-radius: 8px;
  background: #f5f9f7;
}

.upcoming-detail-item.wide { grid-column: 1 / -1; }

.upcoming-detail-item span {
  display: block;
  margin-bottom: 2px;
  color: #7a8c86;
  font-size: 8px;
  font-weight: 700;
  letter-spacing: .04em;
  text-transform: uppercase;
}

.upcoming-detail-item strong,
.upcoming-detail-item a {
  display: block;
  overflow-wrap: anywhere;
  color: #355249;
  font-size: 9.5px;
  font-weight: 650;
  line-height: 1.35;
  text-decoration: none;
}

.upcoming-detail-item a:hover { color: #17634f; text-decoration: underline; }

.upcoming-card-action {
  display: flex;
  width: fit-content;
  align-items: center;
  justify-content: center;
  gap: 6px;
  margin: 11px 0 0 auto;
  padding: 8px 12px;
  border: 1px solid #17634f;
  border-radius: 8px;
  background: #17634f;
  color: #fff;
  font-size: 9.5px;
  font-weight: 750;
  font-family: inherit;
  text-decoration: none;
  box-shadow: 0 4px 10px rgba(23, 99, 79, .18);
  cursor: pointer;
  transition: background-color .18s ease, border-color .18s ease, box-shadow .18s ease, transform .18s ease;
}

.upcoming-card-action:hover {
  border-color: #0f493a;
  background: #0f493a;
  color: #fff;
  box-shadow: 0 6px 14px rgba(15, 73, 58, .24);
  transform: translateY(-1px);
}

.upcoming-card-action:focus-visible {
  outline: 3px solid rgba(40, 120, 97, .28);
  outline-offset: 2px;
}
.booking-type-badge i,
.upcoming-booking-date i,
.upcoming-card-action i,
.calendar-view-details i {
  width: 12px;
  height: 12px;
  flex: 0 0 12px;
  display: inline-grid;
  place-items: center;
  line-height: 1;
}
.upcoming-card-action i { transition: transform .18s ease; }
.upcoming-card-action:hover i { transform: translateX(2px); }

.upcoming-empty {
  display: grid;
  justify-items: center;
  gap: 7px;
  padding: 35px 18px;
  color: #74867f;
  text-align: center;
}

.upcoming-empty i { color: #73a794; font-size: 22px; }
.upcoming-empty strong { color: #355249; font-size: 11px; }
.upcoming-empty span { font-size: 9px; }

.accepted-status-card {
  flex: 0 0 273px;
  height: 273px;
  min-height: 273px;
  margin-top: auto;
  padding: 14px;
}

/* Calendar day booking modal */
.booking-modal {
  padding: 22px;
  background: rgba(5, 28, 22, .62);
  backdrop-filter: blur(5px);
}

.booking-modal-content {
  width: min(720px, 100%);
  max-width: none;
  max-height: min(780px, calc(100vh - 44px));
  overflow: hidden;
  display: flex;
  flex-direction: column;
  padding: 0;
  border: 1px solid rgba(255,255,255,.7);
  border-radius: 18px;
  background: #f5f9f7;
  box-shadow: 0 30px 80px rgba(3, 36, 27, .3);
}

.calendar-modal-header {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 18px 20px;
  border-bottom: 1px solid #dce8e3;
  background: #fff;
}

.calendar-modal-header-icon {
  width: 42px;
  height: 42px;
  flex: 0 0 42px;
  display: grid;
  place-items: center;
  color: #fff;
  border-radius: 11px;
  background: linear-gradient(135deg, #2b7a66, #1b5c49);
  box-shadow: 0 7px 16px rgba(31, 105, 86, .2);
}

.calendar-modal-heading { min-width: 0; flex: 1; }
.calendar-modal-heading span { color: #6d827b; font-size: 9px; font-weight: 800; letter-spacing: .11em; text-transform: uppercase; }
.calendar-modal-heading h3 { margin: 3px 0 0; color: #173b31; font-size: 17px; }

.booking-modal-close {
  position: static;
  width: 38px;
  height: 38px;
  flex: 0 0 38px;
  display: grid;
  place-items: center;
  padding: 0;
  color: #4d675f;
  border: 1px solid #dce8e4;
  border-radius: 11px;
  background: #f1f6f4;
}

.booking-modal-close:hover { color: #175b48; background: #e4f0ec; }
.booking-modal-close svg { width: 18px; height: 18px; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; }

.booking-modal-body {
  min-height: 0;
  overflow-y: auto;
  padding: 17px;
  gap: 11px;
  scrollbar-width: thin;
  scrollbar-color: #9dbfb3 transparent;
}

.calendar-modal-booking {
  overflow: hidden;
  border: 1px solid #d9e6e1;
  border-radius: 14px;
  background: #fff;
  box-shadow: 0 5px 16px rgba(20, 67, 53, .06);
  transition: border-color .18s ease, box-shadow .18s ease, transform .18s ease;
}

.calendar-modal-booking:hover { transform: translateY(-1px); border-color: #bcd7cd; box-shadow: 0 9px 21px rgba(20, 67, 53, .1); }

.calendar-modal-booking-top {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
  padding: 10px 13px;
  border-bottom: 1px solid #e8efec;
  background: #f9fcfb;
}

.calendar-modal-booking-ref { color: #70847d; font-size: 9px; font-weight: 700; }

.calendar-modal-booking-body {
  display: grid;
  grid-template-columns: minmax(150px, .8fr) minmax(0, 1.5fr) auto;
  align-items: center;
  gap: 14px;
  padding: 13px;
}

.calendar-modal-guest { min-width: 0; display: flex; align-items: center; gap: 9px; }
.calendar-modal-avatar { width: 36px; height: 36px; flex: 0 0 36px; display: grid; place-items: center; border-radius: 10px; color: #17604c; background: #e3f1ec; font-size: 11px; font-weight: 900; }
.calendar-modal-avatar { position: relative; overflow: hidden; }
.calendar-modal-guest strong { display: block; overflow: hidden; color: #243f37; font-size: 11px; text-overflow: ellipsis; white-space: nowrap; }
.calendar-modal-guest span { display: block; margin-top: 2px; color: #778982; font-size: 9px; }
.calendar-modal-trip { min-width: 0; }
.calendar-modal-trip small { display: block; margin-bottom: 3px; color: #7b8d86; font-size: 8px; font-weight: 750; text-transform: uppercase; }
.calendar-modal-trip strong { display: block; overflow: hidden; color: #355249; font-size: 10px; text-overflow: ellipsis; white-space: nowrap; }

.calendar-view-details {
  min-height: 34px;
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 7px 10px;
  color: #fff;
  border: 0;
  border-radius: 9px;
  background: linear-gradient(135deg, #2b7a66, #1f6652);
  font: inherit;
  font-size: 9px;
  font-weight: 750;
  white-space: nowrap;
  cursor: pointer;
  box-shadow: 0 5px 12px rgba(31, 102, 82, .16);
}

.calendar-view-details:hover { filter: brightness(1.04); transform: translateY(-1px); }

@media (max-width: 620px) {
  .booking-modal { padding: 0; align-items: stretch; }
  .booking-modal-content { max-height: 100vh; border-radius: 0; }
  .calendar-modal-booking-body { grid-template-columns: 1fr; }
  .calendar-view-details { width: 100%; justify-content: center; }
}

@media (max-width: 1180px) {
  .dashboard-row.calendar-row { grid-template-columns: 1fr; }
  .dashboard-calendar-panel { min-height: 690px; }
  .dashboard-side-panel { display: grid; grid-template-columns: minmax(0, 1fr) minmax(280px, .7fr); }
  .upcoming-bookings-panel {
    height: 400px;
    min-height: 400px;
    max-height: 400px;
    flex-basis: 400px;
  }
  .accepted-status-card { height: 400px; }
}

@media (max-width: 760px) {
  .schedule-panel-heading { flex-direction: column; padding: 16px; }
  .schedule-panel-alert { width: 100%; min-width: 0; max-width: none; }
  .dashboard-calendar-panel { min-height: 650px; }
  .dashboard-side-panel { display: flex; }
  .upcoming-bookings-panel {
    height: 430px;
    min-height: 430px;
    max-height: 430px;
    flex-basis: 430px;
  }
  .accepted-status-card { height: 273px; }
  #calendar { padding: 12px; }
  .fc .fc-toolbar { align-items: stretch; flex-direction: column; gap: 9px; }
  .fc .fc-toolbar-chunk { display: flex; justify-content: center; }
  .fc .fc-daygrid-day-frame { min-height: 68px; }
  .upcoming-detail-grid { grid-template-columns: 1fr; }
  .upcoming-detail-item.wide { grid-column: auto; }
}

</style>
<link rel="stylesheet" href="styles/admin_panel_theme.css" />
<link rel="stylesheet" href="styles/booking-details-drawer.css?v=3" />
<style>
/* Dashboard-only header density and typography scale. */
.main-content .admin-header.admin-page-header.dashboard-page-header {
  min-height: 62px !important;
  padding-top: 6px !important;
  padding-bottom: 6px !important;
}

.dashboard-content > .dashboard-row {
  margin-bottom: 14px;
}

.dashboard-content > .dashboard-row.calendar-row {
  margin-bottom: 0;
}

.dashboard-content .schedule-eyebrow {
  font-size: 11px;
  line-height: 1.3;
}

.dashboard-content .schedule-panel-heading h3,
.dashboard-content .upcoming-panel-heading h3 {
  font-size: 17px;
  line-height: 1.3;
}

.dashboard-content .schedule-panel-alert strong {
  margin-bottom: 3px;
  font-size: 12px;
  line-height: 1.35;
}

.dashboard-content .schedule-panel-alert > div > span {
  font-size: 10.5px;
  line-height: 1.45;
}

.dashboard-content .calendar-booking-label {
  font-size: 11px;
  line-height: 1.25;
}

.dashboard-content .calendar-booking-icon {
  font-size: 10px;
}

.dashboard-content .upcoming-count {
  font-size: 11px;
}

.dashboard-content .booking-type-badge,
.dashboard-content .upcoming-booking-date {
  font-size: 10px;
  line-height: 1.3;
}

.dashboard-content .upcoming-customer strong {
  font-size: 12px;
  line-height: 1.35;
}

.dashboard-content .upcoming-customer span {
  font-size: 10px;
  line-height: 1.4;
}

.dashboard-content .upcoming-detail-item span {
  font-size: 9.5px;
  line-height: 1.35;
}

.dashboard-content .upcoming-detail-item strong,
.dashboard-content .upcoming-detail-item a {
  font-size: 11px;
  line-height: 1.4;
}

.dashboard-content .upcoming-card-action {
  font-size: 11px;
  line-height: 1.3;
}

.dashboard-content .upcoming-empty strong { font-size: 12px; }
.dashboard-content .upcoming-empty span { font-size: 10px; }

.dashboard-content .fc .fc-col-header-cell-cushion,
.dashboard-content .fc .fc-event-title {
  font-size: 11px;
}

/* Keep the schedule workspace compact and bottom-aligned on desktop. */
.dashboard-content .fc .fc-daygrid-body .fc-scrollgrid-sync-table {
  height: auto !important;
}

.dashboard-content .fc .fc-daygrid-body .fc-scrollgrid-sync-table tbody tr {
  height: 64px !important;
}

.dashboard-content .fc .fc-daygrid-day-frame {
  height: 64px !important;
  min-height: 64px !important;
}

.dashboard-content .fc .fc-daygrid-day-top {
  min-height: 25px;
}

.dashboard-content .fc .fc-daygrid-day-number {
  min-width: 22px;
  height: 22px;
  margin: 2px 4px;
  font-size: 11px;
}

.dashboard-content .fc .fc-daygrid-day-events {
  min-height: 27px;
  margin-top: 0 !important;
}

.dashboard-content .fc .fc-daygrid-event {
  margin: 1px 5px !important;
}

.dashboard-content .calendar-booking-indicator {
  min-height: 24px;
  padding: 3px 6px;
}

.dashboard-content .calendar-booking-icon {
  width: 13px;
  height: 13px;
  flex-basis: 13px;
}

.dashboard-content .schedule-panel-alert strong,
.dashboard-content .upcoming-panel-heading .schedule-eyebrow {
  color: #b42332;
}

.dashboard-content .schedule-panel-alert .schedule-alert-icon {
  color: #b42332;
  background: #fde8ea;
}

@media (min-width: 1181px) {
  .dashboard-content .dashboard-row.calendar-row {
    height: 629px;
  }

  .dashboard-content .dashboard-calendar-panel,
  .dashboard-content .dashboard-side-panel {
    height: 629px;
    box-sizing: border-box;
  }

  .dashboard-content .upcoming-bookings-panel {
    height: 338px;
    min-height: 338px;
    max-height: 338px;
    flex-basis: 338px;
  }
}

/* Darker administrator KPI cards with real filtered-period trends. */
.dashboard-content>.dashboard-row:first-child{gap:12px}
.dashboard-content>.dashboard-row:first-child .dashboard-box{box-sizing:border-box;height:118px;min-height:118px;align-items:flex-start;padding:15px 18px 42px;background:linear-gradient(145deg,#10513f 0%,#092f27 100%);border:1px solid rgba(5,42,33,.48);box-shadow:0 9px 22px rgba(6,43,33,.2)}
.dashboard-content>.dashboard-row:first-child .dashboard-box:hover{background:linear-gradient(145deg,#0e4939 0%,#072a22 100%);box-shadow:0 13px 27px rgba(5,36,28,.26)}
.dashboard-content>.dashboard-row:first-child .dashboard-icon{margin-top:3px}
.admin-dashboard-insight{position:absolute;left:18px;right:18px;bottom:9px;height:30px;padding-top:6px;border-top:1px solid rgba(255,255,255,.16);display:flex;align-items:flex-end;justify-content:space-between;gap:10px;color:#82edc5}
.admin-dashboard-comparison{display:flex;align-items:baseline;gap:5px;white-space:nowrap}.admin-dashboard-comparison strong{font-size:.72rem;line-height:1;font-weight:800}.admin-dashboard-comparison small{font-size:.63rem;line-height:1;color:rgba(255,255,255,.7)}
.admin-dashboard-trend{display:block;width:67px;height:25px;flex:0 0 67px}.admin-dashboard-trend svg{width:100%;height:100%;overflow:visible;fill:none;stroke:currentColor;stroke-linecap:round;stroke-linejoin:round}.admin-dashboard-trend .guide{opacity:.14;stroke-width:1;stroke-dasharray:3 4}.admin-dashboard-trend .line{stroke-width:2.1;filter:drop-shadow(0 2px 2px rgba(0,0,0,.16))}.admin-dashboard-trend .arrow{stroke-width:1.8}.admin-dashboard-trend circle{fill:currentColor;stroke:#092f27;stroke-width:1.2}.admin-dashboard-insight.attention{color:#ffc568}.admin-dashboard-insight.neutral{color:#c3ded4}
@media(max-width:720px){.dashboard-content>.dashboard-row:first-child .dashboard-box{height:116px;min-height:116px}.admin-dashboard-comparison small{font-size:.6rem}}
</style>

</head>
<body>
<div class="admin-container">
<?php include 'admin_sidebar.php'; ?>

<main class="main-content">
<header class="admin-header admin-page-header dashboard-page-header">
    <div class="admin-header-left admin-page-title">
      <span class="admin-page-title-icon" aria-hidden="true">
        <svg viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/></svg>
      </span>
      <div class="admin-page-title-copy">
        <h2>Administrator Dashboard</h2>
        <p class="admin-header-subtitle">Overview of bookings, users, and platform activity</p>
      </div>
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
    <?= adminDashboardTrendVisual(...$adminDashboardTrends['accepted']) ?>
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
    <?= adminDashboardTrendVisual(...$adminDashboardTrends['total']) ?>
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
    <?= adminDashboardTrendVisual(...$adminDashboardTrends['completed']) ?>
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
    <?= adminDashboardTrendVisual(...$adminDashboardTrends['tourists']) ?>
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


<!-- Calendar + schedule workspace -->
<div class="dashboard-row calendar-row">
  <section class="dashboard-calendar-panel" aria-labelledby="scheduleCalendarTitle">
    <div class="schedule-panel-heading">
      <div class="schedule-panel-heading-main">
        <span class="schedule-panel-icon" aria-hidden="true"><i class="fa-regular fa-calendar-days"></i></span>
        <div>
          <span class="schedule-eyebrow">Schedule overview</span>
          <h3 id="scheduleCalendarTitle">Booking Calendar</h3>
        </div>
      </div>
      <div class="schedule-panel-alert <?= htmlspecialchars($scheduleReminder['state'], ENT_QUOTES, 'UTF-8') ?>" role="status">
        <span class="schedule-alert-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9"/><path d="M10 21h4"/></svg></span>
        <div>
          <strong><?= htmlspecialchars($scheduleReminder['title'], ENT_QUOTES, 'UTF-8') ?></strong>
          <span><?= htmlspecialchars($scheduleReminder['message'], ENT_QUOTES, 'UTF-8') ?></span>
        </div>
      </div>
    </div>
    <div id="calendar"></div>
  </section>

  <aside class="dashboard-side-panel" aria-label="Upcoming booking information">
    <section class="chart-card upcoming-bookings-panel" aria-labelledby="upcomingBookingsTitle">
      <div class="upcoming-panel-heading">
        <span class="upcoming-panel-icon" aria-hidden="true"><i class="fa-solid fa-calendar-check"></i></span>
        <div class="upcoming-panel-heading-copy">
          <span class="schedule-eyebrow">Requires attention</span>
          <h3 id="upcomingBookingsTitle">Upcoming Accepted Bookings</h3>
        </div>
        <span class="upcoming-count" aria-label="<?= count($upcoming_accepted) ?> upcoming bookings"><?= count($upcoming_accepted) ?></span>
      </div>

      <div class="upcoming-bookings-list">
        <?php if (empty($upcoming_accepted)): ?>
          <div class="upcoming-empty">
            <i class="fa-regular fa-calendar-check" aria-hidden="true"></i>
            <strong>No accepted bookings scheduled</strong>
            <span>New accepted reservations will appear here.</span>
          </div>
        <?php else: ?>
          <?php foreach($upcoming_accepted as $b): ?>
            <?php
              $bookingTypeKey = strtolower(trim((string)($b['booking_type'] ?? 'package')));
              if (!in_array($bookingTypeKey, ['package', 'boat', 'tourguide'], true)) $bookingTypeKey = 'package';
              $bookingTypeLabel = trim((string)($b['booking_type'] ?? 'Package')) ?: 'Package';
              $customerName = trim((string)($b['full_name'] ?? 'Guest')) ?: 'Guest';
              $customerInitial = function_exists('mb_substr') ? mb_substr($customerName, 0, 1) : substr($customerName, 0, 1);
              $bookingDateValue = (string)($b['booking_date'] ?? '');
              $bookingDateTimestamp = strtotime($bookingDateValue);
              $bookingDateLabel = $bookingDateTimestamp ? date('M j, Y', $bookingDateTimestamp) : ($bookingDateValue ?: '-');
              $packageOrLocation = trim((string)($b['package_name'] ?: $b['location'])) ?: '-';
              $phoneNumber = trim((string)($b['phone_number'] ?? ''));
              $phoneHref = preg_replace('/[^0-9+]/', '', $phoneNumber);
              $profileImage = trim((string)($b['profile_picture'] ?? ''));
            ?>
            <article class="booking-card upcoming-booking-card">
              <div class="upcoming-card-top">
                <span class="booking-type-badge <?= $bookingTypeKey ?>"><i class="fa-solid fa-tag" aria-hidden="true"></i><?= htmlspecialchars($bookingTypeLabel, ENT_QUOTES, 'UTF-8') ?></span>
                <time class="upcoming-booking-date" datetime="<?= htmlspecialchars($bookingDateValue, ENT_QUOTES, 'UTF-8') ?>"><i class="fa-regular fa-calendar" aria-hidden="true"></i> <?= htmlspecialchars($bookingDateLabel, ENT_QUOTES, 'UTF-8') ?></time>
              </div>
              <div class="upcoming-card-body">
                <div class="upcoming-customer">
                  <span class="upcoming-customer-avatar" aria-hidden="true"><?= htmlspecialchars(strtoupper($customerInitial), ENT_QUOTES, 'UTF-8') ?><?php if ($profileImage !== ''): ?><img src="<?= htmlspecialchars($profileImage, ENT_QUOTES, 'UTF-8') ?>" alt="" onerror="this.remove()"><?php endif; ?></span>
                  <div>
                    <strong><?= htmlspecialchars($customerName, ENT_QUOTES, 'UTF-8') ?></strong>
                    <span><?= (int)($b['pax'] ?? 0) ?> <?= (int)($b['pax'] ?? 0) === 1 ? 'guest' : 'guests' ?></span>
                  </div>
                </div>
                <div class="upcoming-detail-grid">
                  <div class="upcoming-detail-item wide"><span>Package / Location</span><strong><?= htmlspecialchars($packageOrLocation, ENT_QUOTES, 'UTF-8') ?></strong></div>
                  <div class="upcoming-detail-item"><span>Jump-off port</span><strong><?= htmlspecialchars((string)($b['jump_off_port'] ?: '-'), ENT_QUOTES, 'UTF-8') ?></strong></div>
                  <div class="upcoming-detail-item"><span>Tour type</span><strong><?= htmlspecialchars((string)($b['tour_type'] ?: '-'), ENT_QUOTES, 'UTF-8') ?></strong></div>
                  <div class="upcoming-detail-item"><span>Tour range</span><strong><?= htmlspecialchars((string)($b['tour_range'] ?: '-'), ENT_QUOTES, 'UTF-8') ?></strong></div>
                  <?php if(in_array($bookingTypeKey, ['boat', 'tourguide'], true)): ?>
                    <div class="upcoming-detail-item"><span>Preferred resource</span><strong><?= htmlspecialchars((string)($b['preferred_resource'] ?: '-'), ENT_QUOTES, 'UTF-8') ?></strong></div>
                  <?php endif; ?>
                  <div class="upcoming-detail-item"><span>Contact number</span>
                    <?php if ($phoneHref !== ''): ?><a href="tel:<?= htmlspecialchars($phoneHref, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($phoneNumber, ENT_QUOTES, 'UTF-8') ?></a><?php else: ?><strong>-</strong><?php endif; ?>
                  </div>
                </div>
                <button class="upcoming-card-action js-view-booking-details" type="button" data-booking-id="<?= (int)$b['booking_id'] ?>">View booking details <i class="fa-solid fa-arrow-right" aria-hidden="true"></i></button>
              </div>
            </article>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </section>

    <section class="chart-card accepted-status-card">
      <div class="chart-header"><h4>Accepted Bookings Status</h4></div>
      <canvas id="acceptedPieChart"></canvas>
    </section>
  </aside>
</div>


</section>
</main>
</div>

<div id="bookingModal" class="booking-modal" aria-hidden="true">
  <div class="booking-modal-content" role="dialog" aria-modal="true" aria-labelledby="bookingModalTitle">
    <div class="calendar-modal-header">
      <span class="calendar-modal-header-icon" aria-hidden="true"><i class="fa-regular fa-calendar-check"></i></span>
      <div class="calendar-modal-heading">
        <span>Accepted schedule</span>
        <h3 id="bookingModalTitle">Bookings for <time id="modalDate"></time></h3>
      </div>
      <button id="closeModal" class="booking-modal-close" type="button" aria-label="Close scheduled bookings">
        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m6 6 12 12M18 6 6 18"/></svg>
      </button>
    </div>
    <div id="modalContent" class="booking-modal-body"></div>
  </div>
</div>

<div id="bookingDetailsModal" class="booking-details-modal-overlay" aria-hidden="true">
  <aside class="booking-details-modal" role="dialog" aria-modal="true" aria-labelledby="bookingDetailsTitle">
    <div class="booking-details-modal-header">
      <div class="booking-drawer-brand">
        <img src="img/newlogo.png" alt="">
        <div><span>ITOUR MERCEDES</span><h3 id="bookingDetailsTitle">Booking Details</h3></div>
      </div>
      <button type="button" class="booking-details-modal-close" aria-label="Close booking details" onclick="closeBookingDetailsDrawer()">
        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m6 6 12 12M18 6 6 18"/></svg>
      </button>
    </div>
    <div class="booking-details-modal-body" id="bookingDetailsContent">
      <div class="booking-drawer-loading"><span></span><p>Loading booking details…</p></div>
    </div>
    <div class="booking-details-modal-footer">
      <button type="button" class="booking-details-modal-btn-close" onclick="closeBookingDetailsDrawer()">Close Details</button>
    </div>
  </aside>
</div>


<script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.8/index.global.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
const dashboardBookingIcons = {
  guest: '<svg viewBox="0 0 24 24"><path d="M20 21a8 8 0 0 0-16 0"/><circle cx="12" cy="7" r="4"/></svg>',
  calendar: '<svg viewBox="0 0 24 24"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 10h18"/></svg>',
  map: '<svg viewBox="0 0 24 24"><path d="m9 18-6 3V6l6-3 6 3 6-3v15l-6 3-6-3Z"/><path d="M9 3v15M15 6v15"/></svg>',
  users: '<svg viewBox="0 0 24 24"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/></svg>',
  service: '<svg viewBox="0 0 24 24"><path d="M4 19.5V4.8A1.8 1.8 0 0 1 5.8 3h12.4A1.8 1.8 0 0 1 20 4.8v14.7"/><path d="M2 21h20M8 7h8M8 11h8M8 15h5"/></svg>',
  payment: '<svg viewBox="0 0 24 24"><rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20M6 15h2"/></svg>',
  port: '<svg viewBox="0 0 24 24"><circle cx="12" cy="5" r="3"/><path d="M12 22V8M5 12H2a10 10 0 0 0 20 0h-3M8 19h8"/></svg>'
};

function escapeDashboardBooking(value) {
  return String(value ?? '-').replace(/[&<>"']/g, character => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'
  })[character]);
}

function parseDashboardBookingMeta(preferredResource) {
  const result = { preferred: '-', paymentOption: '-', paymentStatus: 'Unpaid' };
  const parts = String(preferredResource || '').split('|').map(part => part.trim()).filter(Boolean);
  parts.forEach(part => {
    const lower = part.toLowerCase();
    const value = part.substring(part.indexOf(':') + 1).trim() || '-';
    if (lower.startsWith('preferred:')) result.preferred = value;
    if (lower.startsWith('payment:') || lower.startsWith('payment option:')) result.paymentOption = value;
    if (lower.startsWith('payment status:')) result.paymentStatus = value;
  });
  return result;
}

function formatDashboardBookingDate(value) {
  if (!value) return '-';
  const date = new Date(`${value}T00:00:00`);
  return Number.isNaN(date.getTime())
    ? String(value)
    : date.toLocaleDateString('en-PH', { year: 'numeric', month: 'long', day: 'numeric' });
}

function dashboardBookingDateDifference(startValue, endValue) {
  const startParts = String(startValue || '').split('-').map(Number);
  const endParts = String(endValue || '').split('-').map(Number);
  if (startParts.length !== 3 || endParts.length !== 3 || startParts.some(Number.isNaN) || endParts.some(Number.isNaN)) return 0;
  return Math.max(0, Math.round((Date.UTC(endParts[0], endParts[1] - 1, endParts[2]) - Date.UTC(startParts[0], startParts[1] - 1, startParts[2])) / 86400000));
}

function dashboardBookingScheduleDetails(booking) {
  const start = booking.tour_start_date || booking.booking_date || '';
  const end = booking.tour_end_date || start;
  const nights = dashboardBookingDateDifference(start, end);
  return {
    schedule: nights > 0 ? `${formatDashboardBookingDate(start)} – ${formatDashboardBookingDate(end)}` : formatDashboardBookingDate(start),
    duration: nights > 0 ? `${nights + 1} days · ${nights} night${nights === 1 ? '' : 's'}` : '1 day',
    arrangement: String(booking.tour_type || '').toLowerCase() === 'overnight' || nights > 0 ? 'Overnight / multi-day tour' : 'Day tour'
  };
}

function formatDashboardBookingMoney(value) {
  return `₱${Number(value || 0).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
}

function dashboardBookingDetailRow(icon, label, value) {
  return `<div class="bd-detail-row"><span class="bd-row-icon">${dashboardBookingIcons[icon] || ''}</span><div><small>${escapeDashboardBooking(label)}</small><strong>${escapeDashboardBooking(value || '-')}</strong></div></div>`;
}

async function openBookingDetailsDrawer(bookingId) {
  const modal = document.getElementById('bookingDetailsModal');
  const content = document.getElementById('bookingDetailsContent');
  const numericBookingId = Number(bookingId || 0);
  if (!numericBookingId || !modal || !content) return;

  modal.classList.add('show');
  modal.setAttribute('aria-hidden', 'false');
  document.body.classList.add('booking-details-open');
  content.innerHTML = '<div class="booking-drawer-loading"><span></span><p>Loading booking details…</p></div>';

  try {
    const response = await fetch(`adbookings.php?action=fetchBookingDetails&id=${encodeURIComponent(numericBookingId)}`, {
      headers: { Accept: 'application/json' }
    });
    const payload = await response.json();
    if (!response.ok || !payload.success || !payload.booking) {
      throw new Error(payload.message || 'Booking details could not be loaded.');
    }

    const booking = payload.booking;
    const expenses = Array.isArray(payload.expenses) ? payload.expenses : [];
    const meta = parseDashboardBookingMeta(booking.preferred_resource);
    const bookingType = String(booking.booking_type || '').toLowerCase();
    const resourceName = bookingType === 'package'
      ? booking.package_name
      : bookingType === 'boat'
        ? (booking.boat_name || meta.preferred)
        : (booking.guide_name || meta.preferred);
    const status = String(booking.is_complete || '').toLowerCase() === 'completed'
      ? 'Completed'
      : String(booking.status || 'Pending');
    const grandTotal = Number(booking.grand_total || 0);
    const paymentAmount = Number(booking.payment_amount || 0);
    const remainingBalance = Number(booking.remaining_balance || 0);
    const expensesTotal = expenses.reduce((sum, expense) => sum + Number(expense.amount || 0), 0);
    const serviceAmount = Math.max(0, grandTotal - expensesTotal);
    const paymentStatus = Number(booking.is_paid || 0) === 1 || (grandTotal > 0 && remainingBalance <= 0)
      ? 'Paid'
      : paymentAmount > 0 ? 'Partial' : 'Unpaid';
    const totalGuests = Number(booking.num_adults || 0) + Number(booking.num_children || 0) || Number(booking.pax || 0);
    const fullName = booking.t_full_name || 'Guest';
    const initials = fullName.split(/\s+/).filter(Boolean).slice(0, 2).map(part => part[0]).join('').toUpperCase() || 'G';
    const scheduleDetails = dashboardBookingScheduleDetails(booking);
    const profileImageUrl = `adbookings.php?action=fetchTouristProfileImage&id=${encodeURIComponent(Number(booking.booking_id || numericBookingId))}`;
    const expenseRows = expenses.length
      ? expenses.map(expense => `<div><span>${escapeDashboardBooking(expense.expense_type)}</span><strong>${formatDashboardBookingMoney(expense.amount)}</strong></div>`).join('')
      : '<div class="bd-no-expense"><span>No additional expenses recorded</span><strong>₱0.00</strong></div>';

    content.innerHTML = `
      <div class="bd-drawer-hero">
        <div class="bd-reference"><span>BOOKING REFERENCE</span><strong>${escapeDashboardBooking(booking.booking_reference || booking.booking_id)}</strong></div>
        <span class="bd-status-pill">${escapeDashboardBooking(status)}</span>
      </div>
      <section class="bd-drawer-section bd-guest-card">
        <div class="bd-guest-avatar"><span>${escapeDashboardBooking(initials)}</span>${booking.t_has_profile_picture ? `<img src="${profileImageUrl}" alt="${escapeDashboardBooking(fullName)} profile picture" onerror="this.remove()">` : ''}</div>
        <div class="bd-guest-primary"><small>PRIMARY GUEST</small><h4>${escapeDashboardBooking(fullName)}</h4><p>${escapeDashboardBooking(booking.t_email || '-')}</p></div>
      </section>
      <section class="bd-drawer-section">
        <div class="bd-section-title"><span>${dashboardBookingIcons.guest}</span><div><h4>Guest information</h4><p>Contact details used for this reservation</p></div></div>
        <div class="bd-detail-grid">
          ${dashboardBookingDetailRow('guest', 'Contact number', booking.booking_phone || booking.t_phone)}
          ${dashboardBookingDetailRow('map', 'Home address', booking.t_address)}
        </div>
      </section>
      <section class="bd-drawer-section">
        <div class="bd-section-title"><span>${dashboardBookingIcons.calendar}</span><div><h4>Trip information</h4><p>Service, schedule, and meeting details</p></div></div>
        <div class="bd-detail-grid">
          ${dashboardBookingDetailRow('service', 'Booking type', booking.booking_type)}
          ${dashboardBookingDetailRow('service', 'Selected service', resourceName)}
          ${dashboardBookingDetailRow('map', 'Destination', booking.location || booking.package_name)}
          ${dashboardBookingDetailRow('calendar', 'Tour schedule', scheduleDetails.schedule)}
          ${dashboardBookingDetailRow('calendar', 'Trip duration', scheduleDetails.duration)}
          ${dashboardBookingDetailRow('service', 'Tour arrangement', scheduleDetails.arrangement)}
          ${dashboardBookingDetailRow('port', 'Jump-off port', booking.jump_off_port)}
          ${dashboardBookingDetailRow('users', 'Guest count', `${totalGuests} total · ${booking.num_adults || 0} adult(s), ${booking.num_children || 0} child(ren)`)}
        </div>
      </section>
      <section class="bd-drawer-section bd-payment-card">
        <div class="bd-section-title">
          <span>${dashboardBookingIcons.payment}</span>
          <div><h4>Payment and expenses</h4><p>Complete financial summary for this booking</p></div>
          <button type="button" class="bd-go-billing" data-dashboard-billing data-booking-id="${Number(booking.booking_id || numericBookingId)}">Go to Billing</button>
        </div>
        <div class="bd-payment-lines">
          <div><span>Service amount</span><strong>${formatDashboardBookingMoney(serviceAmount)}</strong></div>
          ${expenseRows}
          <div class="bd-expense-subtotal"><span>Expenses total</span><strong>${formatDashboardBookingMoney(expensesTotal)}</strong></div>
          <div class="bd-grand-total"><span>Grand total</span><strong>${formatDashboardBookingMoney(grandTotal)}</strong></div>
        </div>
        <div class="bd-payment-stats">
          <div><small>PAYMENT OPTION</small><strong>${escapeDashboardBooking(meta.paymentOption)}</strong></div>
          <div><small>AMOUNT RECEIVED</small><strong>${formatDashboardBookingMoney(paymentAmount)}</strong></div>
          <div><small>REMAINING BALANCE</small><strong>${formatDashboardBookingMoney(remainingBalance)}</strong></div>
          <div><small>PAYMENT STATUS</small><strong class="bd-payment-${paymentStatus.toLowerCase()}">${paymentStatus}</strong></div>
        </div>
      </section>
      <div class="bd-confidence-note">
        <span>${dashboardBookingIcons.service}</span>
        <p><strong>Booking record verified</strong>Details shown here are loaded directly from the current booking and expense records.</p>
      </div>`;
    content.querySelector('[data-dashboard-billing]')?.addEventListener('click', event => {
      const selectedBookingId = Number(event.currentTarget.dataset.bookingId || 0);
      if (selectedBookingId <= 0) return;
      window.location.href = `adbookings.php?tab=all&open_billing=${encodeURIComponent(selectedBookingId)}`;
    });
  } catch (error) {
    content.innerHTML = `<div class="booking-drawer-error"><span>!</span><h4>Unable to load booking details</h4><p>${escapeDashboardBooking(error instanceof Error ? error.message : 'Please try again.')}</p><button type="button" data-retry-booking-id="${numericBookingId}">Try Again</button></div>`;
  }
}

function closeBookingDetailsDrawer() {
  const modal = document.getElementById('bookingDetailsModal');
  if (modal) {
    modal.classList.remove('show');
    modal.setAttribute('aria-hidden', 'true');
  }
  document.body.classList.remove('booking-details-open');
}

document.addEventListener('DOMContentLoaded', function() {
  // -------------------------
  // DATA
  // -------------------------
  const lineSeries = {
    labels: <?= json_encode($line_labels, JSON_UNESCAPED_UNICODE|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?>,
    data: <?= json_encode($line_data, JSON_UNESCAPED_UNICODE|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?>
  };

  const pieLabels = <?= json_encode($pie_labels, JSON_UNESCAPED_UNICODE|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;
  const pieData = <?= json_encode($pie_data, JSON_UNESCAPED_UNICODE|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;

  const barLabels = ['Total Bookings','Completed Bookings'];
  const barData = [<?= $total_bookings ?>, <?= $completed_bookings ?>];

  const palette = {
    green: '#2b7a66',
    blue: '#3368A1',
    yellow: '#FFB74D',
    red: '#F28B82',
    gray: '#E0E0E0'
  };
  const dashboardChartDuration = window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 0 : 1500;

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
      animation: {
        duration: dashboardChartDuration,
        easing: 'easeOutQuart'
      },
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
      animation: {
        duration: dashboardChartDuration,
        easing: 'easeOutQuart',
        animateRotate: true,
        animateScale: true
      },
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
      animation: {
        duration: dashboardChartDuration,
        easing: 'easeOutQuart',
        delay: context => context.type === 'data' ? context.dataIndex * 120 : 0
      },
      plugins: { legend: { display: false }, tooltip: { mode: 'nearest' } },
      scales: { y: { beginAtZero: true } }
    }
  });

 // -------------------------
  // FULLCALENDAR
  // -------------------------
  const calendarEl = document.getElementById('calendar');
  const eventsData = <?= json_encode($calendar_events, JSON_UNESCAPED_UNICODE|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;

  const bookingCountPerDay = {};
  eventsData.forEach(ev => {
    if(!bookingCountPerDay[ev.start]) bookingCountPerDay[ev.start] = [];
    bookingCountPerDay[ev.start].push(ev);
  });

  const dayEvents = Object.keys(bookingCountPerDay).map(date => ({
    title: bookingCountPerDay[date].length + (bookingCountPerDay[date].length === 1 ? ' accepted booking' : ' accepted bookings'),
    start: date,
    allDay: true,
    backgroundColor: '#2b7a66',
    extendedProps: { bookings: bookingCountPerDay[date] }
  }));

  const calendar = new FullCalendar.Calendar(calendarEl, {
    initialView: 'dayGridMonth',
    initialDate: '<?= htmlspecialchars($calendarInitialDate) ?>',
    height: 'auto',
    contentHeight: 'auto',
    expandRows: false,
    buttonText: { today: 'Today', month: 'Month', week: 'Week', day: 'Day' },
    headerToolbar: { left:'prev,next today', center:'title', right:'dayGridMonth,timeGridWeek,timeGridDay' },
    dayHeaderFormat: { weekday: 'short' },
    dayMaxEvents: 2,
    navLinks: true,
    nowIndicator: true,
    stickyHeaderDates: true,
    eventDisplay: 'block',
    events: dayEvents,
    eventContent: function(info) {
      const count = Array.isArray(info.event.extendedProps.bookings) ? info.event.extendedProps.bookings.length : 0;
      return {
        html: `<div class="calendar-booking-indicator"><i class="fa-regular fa-calendar-check calendar-booking-icon" aria-hidden="true"></i><span class="calendar-booking-label">${count} ${count === 1 ? 'booking' : 'bookings'}</span></div>`
      };
    },
    eventDidMount: function(info) {
      info.el.setAttribute('title', info.event.title + ' — select to view details');
      info.el.setAttribute('aria-label', info.event.title + ' on ' + info.event.startStr + '. Select to view details.');
    },
    eventClick: function(info) {
      const modal = document.getElementById('bookingModal');
      const content = document.getElementById('modalContent');
      const modalDate = document.getElementById('modalDate');
      modalDate.dateTime = info.event.startStr;
      modalDate.textContent = formatDashboardBookingDate(info.event.startStr);

      const sortedBookings = [...info.event.extendedProps.bookings].sort((a, b) => {
        const dateA = new Date((a.details.updated_at || a.details.date).replace(' ', 'T'));
        const dateB = new Date((b.details.updated_at || b.details.date).replace(' ', 'T'));
        return dateA - dateB;
      });

      content.innerHTML = sortedBookings.map((booking, index) => {
        const details = booking.details;
        const bookingType = String(details.booking_type || 'Package');
        const typeKey = ['package', 'boat', 'tourguide'].includes(bookingType.toLowerCase()) ? bookingType.toLowerCase() : 'package';
        const name = String(details.full_name || 'Guest');
        const initials = name.split(/\s+/).filter(Boolean).slice(0, 2).map(part => part[0]).join('').toUpperCase() || 'G';
        const service = details.package_name || details.location || '-';
        const profileImage = String(details.profile_picture || '');
        return `
          <article class="calendar-modal-booking">
            <div class="calendar-modal-booking-top">
              <span class="booking-type-badge ${typeKey}"><i class="fa-solid fa-tag" aria-hidden="true"></i>${escapeDashboardBooking(bookingType)}</span>
              <span class="calendar-modal-booking-ref">Booking ${index + 1} of ${sortedBookings.length}</span>
            </div>
            <div class="calendar-modal-booking-body">
              <div class="calendar-modal-guest">
                <span class="calendar-modal-avatar" aria-hidden="true">${escapeDashboardBooking(initials)}${profileImage ? `<img src="${escapeDashboardBooking(profileImage)}" alt="" onerror="this.remove()">` : ''}</span>
                <div><strong>${escapeDashboardBooking(name)}</strong><span>${escapeDashboardBooking(details.pax || 0)} guest(s)</span></div>
              </div>
              <div class="calendar-modal-trip"><small>Package / location</small><strong>${escapeDashboardBooking(service)}</strong></div>
              <button class="calendar-view-details js-view-booking-details" type="button" data-booking-id="${Number(details.booking_id || 0)}">View details <i class="fa-solid fa-arrow-right" aria-hidden="true"></i></button>
            </div>
          </article>`;
      }).join('');

      modal.style.display = 'flex';
      modal.setAttribute('aria-hidden', 'false');
  }

  });
  calendar.render();

  // Close modal
  document.getElementById('closeModal').addEventListener('click', ()=>{ 
    document.getElementById('bookingModal').style.display='none';
    document.getElementById('bookingModal').setAttribute('aria-hidden', 'true');
  });

  document.addEventListener('click', event => {
    const detailsButton = event.target.closest('.js-view-booking-details');
    if (detailsButton) {
      document.getElementById('bookingModal').style.display = 'none';
      document.getElementById('bookingModal').setAttribute('aria-hidden', 'true');
      openBookingDetailsDrawer(detailsButton.dataset.bookingId);
      return;
    }
    const retryButton = event.target.closest('[data-retry-booking-id]');
    if (retryButton) openBookingDetailsDrawer(retryButton.dataset.retryBookingId);
  });

  document.getElementById('bookingModal').addEventListener('mousedown', event => {
    if (event.target.id === 'bookingModal') {
      event.currentTarget.style.display = 'none';
      event.currentTarget.setAttribute('aria-hidden', 'true');
    }
  });

  document.getElementById('bookingDetailsModal').addEventListener('mousedown', event => {
    if (event.target.id === 'bookingDetailsModal') closeBookingDetailsDrawer();
  });

  document.addEventListener('keydown', event => {
    if (event.key !== 'Escape') return;
    if (document.getElementById('bookingDetailsModal').classList.contains('show')) {
      closeBookingDetailsDrawer();
    } else {
      document.getElementById('bookingModal').style.display = 'none';
      document.getElementById('bookingModal').setAttribute('aria-hidden', 'true');
    }
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
    animation: {
      duration: dashboardChartDuration,
      easing: 'easeOutQuart',
      animateRotate: true,
      animateScale: true
    },
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

