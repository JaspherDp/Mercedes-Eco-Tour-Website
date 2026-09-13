<?php
chdir(__DIR__ . '/..');
require_once __DIR__ . '/../php/session_security.php';
AppSessionStart();
require_once __DIR__ . '/../php/db_connection.php';
require_once __DIR__ . '/../php/activity_logger.php';
require_once __DIR__ . '/../php/operator_auth_helper.php';

/* ================= SECURITY ================= */
if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    logActivity(
        $pdo,
        'Tour Operator',
        (int)($_SESSION['operator_id'] ?? 0),
        (string)($_SESSION['operator_name'] ?? 'Tour Operator'),
        'Logout',
        'Signed out of the tour operator panel.',
        'Authentication'
    );
    AppDestroySession();
    AppSessionStart();
    $_SESSION['alert'] = [
        'type' => 'success',
        'title' => 'Logout Successful',
        'message' => 'You have securely signed out of the operator portal.'
    ];
    header('Location: ' . operatorLoginUrl());
    exit();
}

$operatorAccount = OperatorRequireLogin($pdo);

$operator_id  = $_SESSION['operator_id'];
$operatorName = $_SESSION['operator_name'] ?? 'Operator';
$operatorNotificationCsrf = AppCsrfToken('operator', 'notifications');
$opProfilePicFile = trim((string)($_SESSION['operator_profile'] ?? ''));
$opHeaderProfilePic = null;
if ($opProfilePicFile !== '' && strtolower($opProfilePicFile) !== 'img/profileicon.png' && file_exists($opProfilePicFile)) {
    $opHeaderProfilePic = $opProfilePicFile;
}

function OpDashboardResolveTouristProfile(?string $profilePicture, ?string $googleId = null): string
{
    $profilePicture = trim((string)$profilePicture);
    if (preg_match('~(?:^|/)(?:profileicon|profileicon2)\.png(?:$|[?#])~i', str_replace('\\', '/', $profilePicture))) {
        $profilePicture = '';
    }
    if ($profilePicture !== '') {
        if (preg_match('#^https?://#i', $profilePicture)) {
            if (stripos($profilePicture, 'profiles.google.com') !== false && preg_match('~profiles\\.google\\.com/(?:s2/photos/profile/)?([^/?#]+)(?:/picture)?~i', $profilePicture, $match)) {
                return 'https://profiles.google.com/' . rawurlencode($match[1]) . '/picture?sz=256';
            }
            return $profilePicture;
        }

        $clean = ltrim($profilePicture, '/');
        foreach (['uploads/profile_pictures/' . basename($clean), 'uploads/profile_picture/' . basename($clean), $clean] as $candidate) {
            $localPath = __DIR__ . '/../' . str_replace('/', DIRECTORY_SEPARATOR, $candidate);
            if (file_exists($localPath)) return $candidate;
        }
    }

    return $googleId ? 'https://profiles.google.com/' . rawurlencode($googleId) . '/picture?sz=256' : '';
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

if (($_GET['op_action'] ?? '') === 'booking_details') {
    header('Content-Type: application/json; charset=utf-8');
    $bookingId = (int)($_GET['id'] ?? 0);
    if ($bookingId <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid booking.']);
        exit;
    }

    $detailsStmt = $pdo->prepare("
        SELECT b.booking_id, b.booking_reference, b.package_name, b.booking_date, b.status,
               b.phone_number, b.location, b.pax, b.num_adults, b.num_children,
               b.booking_type, b.jump_off_port, b.is_complete, b.grand_total,
               b.payment_amount, b.remaining_balance, b.payment_method, b.created_at,
               COALESCE(NULLIF(TRIM(t.full_name), ''), CONCAT('Tourist #', b.tourist_id)) AS guest_name,
               COALESCE(NULLIF(TRIM(t.email), ''), '-') AS guest_email,
               t.profile_picture AS tourist_profile_picture, t.google_id AS tourist_google_id
        FROM bookings b
        LEFT JOIN tourist t ON t.tourist_id = b.tourist_id
        WHERE b.booking_id = ? AND b.operator_id = ?
        LIMIT 1
    ");
    $detailsStmt->execute([$bookingId, $operator_id]);
    $booking = $detailsStmt->fetch(PDO::FETCH_ASSOC);
    if (!$booking) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Booking not found or access was denied.']);
        exit;
    }

    $booking['pax'] = (int)$booking['pax'] > 0
        ? (int)$booking['pax']
        : (int)$booking['num_adults'] + (int)$booking['num_children'];
    $booking['profile_image'] = OpDashboardResolveTouristProfile(
        $booking['tourist_profile_picture'] ?? null,
        $booking['tourist_google_id'] ?? null
    );
    unset($booking['tourist_profile_picture'], $booking['tourist_google_id']);
    echo json_encode(['success' => true, 'booking' => $booking], JSON_UNESCAPED_SLASHES);
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

/* ================= OPERATIONAL ANALYTICS ================= */
$stmt = $pdo->prepare("SELECT $chartGroupSql AS grp,
        COALESCE(SUM(b.grand_total), 0) AS booked_value,
        COALESCE(SUM(b.payment_amount), 0) AS collected_value
    FROM bookings b
    WHERE $rangeWhere AND LOWER(COALESCE(b.status, '')) NOT IN ('cancelled', 'declined')
    GROUP BY grp ORDER BY grp ASC");
$stmt->execute($rangeParams);
$revenueRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
$revenueLabels = array_map(function ($row) use ($rangeFilter) {
    $label = (string)$row['grp'];
    $ts = strtotime($label);
    if ($ts === false) return $label;
    if ($rangeFilter === 'daily') return date('H:i', $ts);
    if ($rangeFilter === 'weekly' || $rangeFilter === 'monthly') return date('M d', $ts);
    if ($rangeFilter === 'yearly') return date('M', $ts);
    return date('Y-m', $ts);
}, $revenueRows);
$revenueBooked = array_map(static fn($row) => round((float)$row['booked_value'], 2), $revenueRows);
$revenueCollected = array_map(static fn($row) => round((float)$row['collected_value'], 2), $revenueRows);

$stmt = $pdo->prepare("SELECT COALESCE(NULLIF(TRIM(b.package_name), ''), 'Other service') AS service_name,
        COUNT(*) AS booking_count,
        COALESCE(SUM(CASE WHEN COALESCE(b.pax, 0) > 0 THEN b.pax ELSE COALESCE(b.num_adults, 0) + COALESCE(b.num_children, 0) END), 0) AS guests
    FROM bookings b
    WHERE $rangeWhere AND LOWER(COALESCE(b.status, '')) NOT IN ('cancelled', 'declined')
    GROUP BY service_name
    ORDER BY booking_count DESC, guests DESC
    LIMIT 6");
$stmt->execute($rangeParams);
$packageDemandRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$stmt = $pdo->prepare("SELECT b.booking_id, b.booking_reference, b.package_name, b.booking_date,
        b.status, b.grand_total, b.payment_amount, b.remaining_balance, b.created_at,
        CASE WHEN COALESCE(b.pax, 0) > 0 THEN b.pax ELSE COALESCE(b.num_adults, 0) + COALESCE(b.num_children, 0) END AS guests,
        COALESCE(NULLIF(TRIM(t.full_name), ''), CONCAT('Tourist #', b.tourist_id)) AS tourist_name
    FROM bookings b
    LEFT JOIN tourist t ON t.tourist_id = b.tourist_id
    WHERE $rangeWhere
    ORDER BY b.created_at DESC
    LIMIT 8");
$stmt->execute($rangeParams);
$recentBookings = $stmt->fetchAll(PDO::FETCH_ASSOC);

$totalBookedValue = array_sum($revenueBooked);
$totalCollectedValue = array_sum($revenueCollected);

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

/* ================= UPCOMING BOOKINGS ================= */
// Prepare bookings grouped by date
$stmt = $pdo->prepare("
    SELECT b.booking_date, b.booking_id, b.booking_reference, b.package_name, b.phone_number, b.location, b.pax,
           b.num_adults, b.num_children, b.tour_type, b.tour_range,
           b.booking_type, b.jump_off_port, b.status, b.is_complete, b.created_at,
           t.full_name AS tourist_name, t.profile_picture AS tourist_profile_picture, t.google_id AS tourist_google_id
    FROM bookings b
    JOIN tourist t ON b.tourist_id = t.tourist_id
    WHERE $calendarWhere
    ORDER BY b.booking_date ASC, b.created_at ASC
");
$stmt->execute($calendarParams);
$allBookings = $stmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($allBookings as &$dashboardBooking) {
    $dashboardBooking['profile_image'] = OpDashboardResolveTouristProfile(
        $dashboardBooking['tourist_profile_picture'] ?? null,
        $dashboardBooking['tourist_google_id'] ?? null
    );
    unset($dashboardBooking['tourist_profile_picture'], $dashboardBooking['tourist_google_id']);
}
unset($dashboardBooking);
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

main.op-main {
    margin-left: 250px;
    padding: 84px 18px 24px;
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
    border: 1px solid #236552;
    background: linear-gradient(135deg, #2b7a66 0%, #236552 100%);
    border-radius: 11px;
    padding: 8px 13px;
    font: inherit;
    font-size: 13px;
    font-weight: 700;
    color: #fff;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    box-shadow: 0 7px 15px rgba(29, 93, 74, 0.17);
}

.op-global-filter-apply::before {
    content: "";
    width: 16px;
    height: 16px;
    flex: 0 0 16px;
    background-color: currentColor;
    -webkit-mask: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24'%3E%3Cpath fill='black' d='M3.5 5.2A1.2 1.2 0 0 1 4.6 4.5h14.8a1.2 1.2 0 0 1 .9 2L14.5 13v5.2a1.2 1.2 0 0 1-.7 1.1l-3 1.4A1.2 1.2 0 0 1 9 19.6V13L3.7 6.5a1.2 1.2 0 0 1-.2-1.3Z'/%3E%3C/svg%3E") center / contain no-repeat;
    mask: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24'%3E%3Cpath fill='black' d='M3.5 5.2A1.2 1.2 0 0 1 4.6 4.5h14.8a1.2 1.2 0 0 1 .9 2L14.5 13v5.2a1.2 1.2 0 0 1-.7 1.1l-3 1.4A1.2 1.2 0 0 1 9 19.6V13L3.7 6.5a1.2 1.2 0 0 1-.2-1.3Z'/%3E%3C/svg%3E") center / contain no-repeat;
}

.op-global-filter-apply:hover {
    background: linear-gradient(135deg, #236d59 0%, #194f40 100%);
    box-shadow: 0 9px 18px rgba(29, 93, 74, 0.22);
    transform: translateY(-1px);
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
    margin-top: 4px;
}

.stats-grid {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 12px;
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
    font-size: 14px;
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
    min-height: 80px;
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
    font-size: 1.55rem;
    line-height: 1;
    font-weight: 800;
    color: #ffffff;
}
.stat-card .label {
    margin-top: 4px;
    font-size: 12px;
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
    background: rgba(5, 24, 18, 0.62);
    backdrop-filter: blur(4px);
    justify-content: center;
    align-items: center;
}
.modal-content {
    background: #f5f9f7;
    border-radius: 17px;
    border: 1px solid rgba(255,255,255,.72);
    width: min(760px, 92vw);
    max-height: 86vh;
    overflow: auto;
    padding: 0;
    position: relative;
    box-shadow: 0 28px 70px rgba(4,39,29,.28);
}
.booking-modal-head {
    position: relative;
    display: flex;
    align-items: center;
    gap: 11px;
    padding: 16px 62px 16px 18px;
    border-bottom: 1px solid #dce8e3;
    background: #fff;
}
.booking-modal-head .panel-heading-main { flex: 1; }
.booking-modal-head .modal-close { position: absolute; top: 16px; right: 18px; }
.booking-modal-head h3 {
    margin: 0;
    color: #1d4338;
    font-size: 15px;
}
.modal-close {
    width: 36px;
    height: 36px;
    display: grid;
    place-items: center;
    padding: 0;
    border: 1px solid #d7e5e0;
    border-radius: 10px;
    background: #f8fbfa;
    font-size: 20px;
    color: #4a665d;
    cursor: pointer;
    transition: background .18s ease, border-color .18s ease, transform .18s ease;
}
.modal-close:hover { background: #e8f4ef; border-color: #a9ccbe; transform: translateY(-1px); }

#modalBody {
    display: grid;
    gap: 10px;
    padding: 14px 16px 17px;
    overflow-y: auto;
}
.booking-modal-item {
    border: 1px solid #dce9e4;
    border-left: 4px solid var(--op-primary);
    background: #fff;
    padding: 13px;
    border-radius: 11px;
    box-shadow: 0 4px 12px rgba(23,73,58,.05);
}
.booking-modal-item p {
    margin: 0 0 6px;
    font-size: 13px;
    color: #31464f;
}
.booking-modal-item p:last-child { margin-bottom: 0; }

.overview-charts {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 12px;
}
.insight-card { min-width: 0; min-height: 0; display: flex; flex-direction: column; }
.overview-charts .insight-card { height: 270px; }
.insight-card-head,
.schedule-panel-head,
.upcoming-panel-head,
.table-card-head {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 12px;
    margin-bottom: 12px;
}
.panel-heading-main { display: flex; align-items: center; gap: 10px; min-width: 0; }
.panel-heading-icon {
    width: 38px;
    height: 38px;
    flex: 0 0 38px;
    display: grid;
    place-items: center;
    border-radius: 10px;
    background: #eaf5f0;
    color: #1f6a55;
}
.panel-heading-icon i {
    width: 100%;
    height: 100%;
    display: grid;
    place-items: center;
    font-size: 16px;
    line-height: 1;
    text-align: center;
}
.panel-eyebrow { display: block; margin-bottom: 3px; color: #739087; font-size: 8px; font-weight: 800; letter-spacing: .1em; text-transform: uppercase; }
.insight-card-head h4,
.schedule-panel-head h4,
.upcoming-panel-head h4,
.table-card-head h4 { margin: 0; color: #1d4338; font-size: 14px; }
.panel-note { padding: 5px 8px; border-radius: 999px; background: #edf6f2; color: #28705a; font-size: 8px; font-weight: 800; white-space: nowrap; }
.chart-stage { position: relative; flex: 0 0 190px; height: 190px; min-height: 0; overflow: hidden; }
.chart-stage canvas { width: 100% !important; height: 100% !important; max-height: none !important; }

.schedule-workspace { display: grid; grid-template-columns: minmax(0, 1.55fr) minmax(300px, .65fr); gap: 12px; align-items: stretch; }
.schedule-side { min-width: 0; display: grid; gap: 12px; align-content: start; }
.schedule-calendar-card { min-width: 0; padding: 16px; }
.schedule-summary {
    display: flex;
    align-items: center;
    gap: 9px;
    padding: 8px 10px;
    border: 1px solid #d9e9e3;
    border-radius: 10px;
    background: #f5faf8;
}
.schedule-summary i { color: var(--op-primary); }
.schedule-summary strong { display: block; color: #285044; font-size: 10px; }
.schedule-summary small { display: block; margin-top: 2px; color: #74857f; font-size: 8px; }
.schedule-legend { display: flex; gap: 12px; margin: 10px 0 0; color: #6d8179; font-size: 9px; }
.schedule-legend span { display: inline-flex; align-items: center; gap: 5px; }
.schedule-legend i { width: 7px; height: 7px; border-radius: 50%; background: var(--op-primary); }
.schedule-legend i.today { background: #b9dfd1; border: 1px solid #4b937b; }

.upcoming-panel { min-width: 0; display: flex; flex-direction: column; max-height: 380px; }
.upcoming-count { min-width: 28px; height: 28px; padding: 0 8px; border-radius: 999px; display: inline-flex; align-items: center; justify-content: center; background: var(--op-primary); color: #fff; font-size: 11px; font-weight: 800; }
.upcoming-cards { display: grid; gap: 9px; overflow-y: auto; padding-right: 3px; scrollbar-width: thin; scrollbar-color: #62a58d #edf4f1; }
.upcoming-card { border: 1px solid #dfeae6; border-radius: 11px; padding: 11px; background: #fbfdfc; }
.upcoming-card-top { display: flex; align-items: center; justify-content: space-between; gap: 8px; margin-bottom: 9px; }
.booking-status { display: inline-flex; padding: 4px 7px; border-radius: 999px; background: #e7f4ef; color: #216850; font-size: 8px; font-weight: 800; text-transform: capitalize; }
.upcoming-card time { color: #60746c; font-size: 9px; font-weight: 700; }
.upcoming-card time i { width: 12px; height: 12px; display: inline-grid; place-items: center; margin-right: 2px; line-height: 1; vertical-align: -2px; }
.upcoming-guest { display: flex; align-items: center; gap: 9px; margin-bottom: 9px; }
.upcoming-avatar {
    width: 32px;
    height: 32px;
    flex: 0 0 32px;
    display: grid;
    place-items: center;
    margin: 0;
    border-radius: 9px;
    background: linear-gradient(135deg,#2b7a66,#1d5d4a);
    color: #fff;
    font-size: 10px;
    font-weight: 800;
    line-height: 1;
    text-align: center;
}
.upcoming-guest strong { display: block; color: #27483e; font-size: 11px; }
.upcoming-guest > div > span { display: block; margin-top: 2px; color: #788982; font-size: 8px; }
.upcoming-service { padding: 8px 9px; border-radius: 8px; background: #f0f6f3; }
.upcoming-service small { display: block; color: #7b8e87; font-size: 7px; font-weight: 800; letter-spacing: .05em; text-transform: uppercase; }
.upcoming-service strong { display: block; margin-top: 3px; overflow: hidden; color: #35554b; font-size: 9px; text-overflow: ellipsis; white-space: nowrap; }
.upcoming-action {
    width: fit-content;
    min-height: 32px;
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 9px 0 0 auto;
    padding: 0 11px;
    border: 1px solid var(--op-primary);
    border-radius: 8px;
    background: var(--op-primary);
    color: #fff;
    font-family: 'Inter', sans-serif;
    font-size: 9px;
    font-weight: 700;
    line-height: 1;
    text-decoration: none;
    box-shadow: 0 4px 9px rgba(43,122,102,.16);
    transition: background .18s ease, border-color .18s ease, transform .18s ease, box-shadow .18s ease;
}
.upcoming-action:hover { background: var(--op-primary-dark); color: #fff; transform: translateY(-1px); }
.upcoming-empty { padding: 45px 15px; text-align: center; color: #768981; font-size: 10px; }

.recent-table-wrap { overflow-x: auto; border: 1px solid #e0eae6; border-radius: 11px; }
.recent-table { width: 100%; border-collapse: collapse; min-width: 790px; }
.recent-table th { padding: 10px 11px; background: #f1f7f4; color: #62766e; font-size: 8px; font-weight: 800; letter-spacing: .05em; text-align: left; text-transform: uppercase; }
.recent-table td { padding: 11px; border-top: 1px solid #e6eeeb; color: #3d564e; font-size: 9px; vertical-align: middle; }
.recent-table tbody tr:hover { background: #f8fbfa; }
.recent-table td strong { color: #24483d; font-size: 9.5px; }
.table-status { display: inline-flex; padding: 4px 7px; border-radius: 999px; font-size: 8px; font-weight: 800; text-transform: capitalize; }
.table-status.pending { background: #fff4d8; color: #916514; }
.table-status.accepted { background: #e7f4ef; color: #216850; }
.table-status.cancelled, .table-status.declined { background: #fae8eb; color: #a43240; }
.table-status.completed { background: #e7f0fa; color: #315f8d; }
.payment-progress { width: 72px; height: 5px; margin-top: 5px; overflow: hidden; border-radius: 999px; background: #e7efec; }
.payment-progress span { display: block; height: 100%; border-radius: inherit; background: var(--op-primary); }
.table-link { min-height: 30px; display: inline-flex; align-items: center; justify-content: center; padding: 0 10px; border: 1px solid #cfe0da; border-radius: 7px; color: #27634f; font-family: 'Inter', sans-serif; font-size: 9px; font-weight: 700; line-height: 1; text-decoration: none; }
.table-link:hover { background: #eaf5f0; border-color: #a8cbbb; }
.table-card-head > .table-link { min-height: 34px; padding: 0 12px; border-color: var(--op-primary); background: var(--op-primary); color: #fff; box-shadow: 0 4px 9px rgba(43,122,102,.16); }
.table-card-head > .table-link:hover { border-color: var(--op-primary-dark); background: var(--op-primary-dark); color: #fff; transform: translateY(-1px); }
.booking-modal-item .upcoming-action { min-height: 32px; font-size: 9px; }

/* FullCalendar look aligned to hotel admin palette */
.fc .fc-toolbar-title {
    color: #214952;
    font-weight: 700;
    font-size: 16px;
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
    padding: 8px 4px;
    font-size: 10px;
    text-decoration: none;
}
.fc .fc-daygrid-day-number {
    color: #21363f;
    text-decoration: none;
    min-width: 27px;
    height: 27px;
    display: inline-grid;
    place-items: center;
    margin: 5px;
    padding: 0 !important;
    border-radius: 8px;
    font-size: 12px;
    font-weight: 600;
}
.fc .fc-day-today .fc-daygrid-day-number {
    color: #fff !important;
    background: #246e5a;
    box-shadow: 0 4px 10px rgba(36,110,90,.2);
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
.fc .fc-daygrid-day-frame { min-height: 78px; }
.fc .fc-daygrid-day:hover { background: #f4faf7; }
.fc .fc-event-main { overflow: hidden; }
.op-calendar-event { display: flex; align-items: center; gap: 5px; min-width: 0; padding: 1px 0; }
.op-calendar-event i { font-size: 9px; }
.op-calendar-event span { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-size: 9px; font-weight: 750; }

/* Calendar date booking modal, aligned with the admin dashboard. */
.calendar-bookings-modal { padding: 22px; background: rgba(5,28,22,.62); backdrop-filter: blur(5px); }
.calendar-bookings-modal .calendar-bookings-modal-content { width: min(720px,100%); max-width: none; max-height: min(780px,calc(100vh - 44px)); overflow: hidden; display: flex; flex-direction: column; padding: 0; border: 1px solid rgba(255,255,255,.7); border-radius: 18px; background: #f5f9f7; box-shadow: 0 30px 80px rgba(3,36,27,.3); }
.calendar-modal-header { display: flex; align-items: center; gap: 12px; padding: 18px 20px; border-bottom: 1px solid #dce8e3; background: #fff; }
.calendar-modal-header-icon { width: 42px; height: 42px; flex: 0 0 42px; display: grid; place-items: center; color: #fff; border-radius: 11px; background: linear-gradient(135deg,#2b7a66,#1b5c49); box-shadow: 0 7px 16px rgba(31,105,86,.2); }
.calendar-modal-heading { min-width: 0; flex: 1; }
.calendar-modal-heading span { color: #6d827b; font-size: 10px; font-weight: 800; letter-spacing: .11em; text-transform: uppercase; }
.calendar-modal-heading h3 { margin: 3px 0 0; color: #173b31; font-size: 18px; }
.calendar-bookings-modal .modal-close { position: static; width: 40px; height: 40px; flex: 0 0 40px; border-radius: 11px; }
.calendar-bookings-modal .modal-close svg { width: 18px; height: 18px; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; }
.calendar-bookings-modal #modalBody { min-height: 0; overflow-y: auto; display: flex; flex-direction: column; gap: 11px; padding: 17px; scrollbar-width: thin; scrollbar-color: #9dbfb3 transparent; }
.calendar-modal-booking { overflow: hidden; border: 1px solid #d9e6e1; border-radius: 14px; background: #fff; box-shadow: 0 5px 16px rgba(20,67,53,.06); transition: border-color .18s ease, box-shadow .18s ease, transform .18s ease; }
.calendar-modal-booking:hover { transform: translateY(-1px); border-color: #bcd7cd; box-shadow: 0 9px 21px rgba(20,67,53,.1); }
.calendar-modal-booking-top { display: flex; align-items: center; justify-content: space-between; gap: 10px; padding: 10px 13px; border-bottom: 1px solid #e8efec; background: #f9fcfb; }
.calendar-booking-type { display: inline-flex; align-items: center; gap: 6px; padding: 5px 8px; color: #17604c; border-radius: 7px; background: #e7f3ef; font-size: 10px; font-weight: 800; letter-spacing: .04em; text-transform: uppercase; }
.calendar-modal-booking-ref { color: #70847d; font-size: 10px; font-weight: 700; }
.calendar-modal-booking-body { display: grid; grid-template-columns: minmax(180px,.9fr) minmax(0,1.5fr) auto; align-items: center; gap: 14px; padding: 13px; }
.calendar-modal-guest { min-width: 0; display: flex; align-items: center; gap: 10px; }
.calendar-modal-avatar { position: relative; width: 38px; height: 38px; flex: 0 0 38px; display: grid; place-items: center; overflow: hidden; border-radius: 10px; color: #17604c; background: #e3f1ec; font-size: 12px; font-weight: 900; }
.calendar-modal-avatar img { position: absolute; inset: 0; width: 100%; height: 100%; display: block; object-fit: cover; }
.calendar-modal-guest strong { display: block; overflow: hidden; color: #243f37; font-size: 12px; text-overflow: ellipsis; white-space: nowrap; }
.calendar-modal-guest span { display: block; margin-top: 2px; color: #778982; font-size: 10px; }
.calendar-modal-trip { min-width: 0; }
.calendar-modal-trip small { display: block; margin-bottom: 3px; color: #7b8d86; font-size: 9px; font-weight: 800; text-transform: uppercase; }
.calendar-modal-trip strong { display: block; overflow: hidden; color: #355249; font-size: 11px; text-overflow: ellipsis; white-space: nowrap; }
.calendar-view-details { min-height: 36px; display: inline-flex; align-items: center; gap: 7px; padding: 7px 11px; color: #fff; border: 0; border-radius: 9px; background: linear-gradient(135deg,#2b7a66,#1f6652); font: inherit; font-size: 10px; font-weight: 800; white-space: nowrap; cursor: pointer; box-shadow: 0 5px 12px rgba(31,102,82,.16); }
.calendar-view-details:hover { filter: brightness(1.04); transform: translateY(-1px); }

/* Keep operational information readable at normal dashboard zoom. */
.panel-eyebrow, .panel-note, .booking-status { font-size: 10px; }
.schedule-summary strong, .upcoming-service small { font-size: 11px; }
.schedule-summary small, .schedule-legend, .upcoming-card time, .upcoming-guest > div > span { font-size: 11px; }
.upcoming-guest strong { font-size: 13px; }
.upcoming-service strong { font-size: 12px; }
.upcoming-action, .booking-modal-item .upcoming-action { font-size: 11px; }
.recent-table th { font-size: 10px; }
.recent-table td, .recent-table td strong { font-size: 11px; }
.table-status, .table-link { font-size: 10px; }
.op-calendar-event span { font-size: 11px; }
.upcoming-avatar { position: relative; overflow: hidden; }
.upcoming-avatar img { position: absolute; inset: 0; width: 100%; height: 100%; display: block; object-fit: cover; }

@media (max-width: 1280px) {
    .stats-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    .overview-charts { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    .overview-charts .insight-card:last-child { grid-column: 1 / -1; }
    .schedule-workspace { grid-template-columns: 1fr; }
    .upcoming-panel { max-height: none; }
    .upcoming-cards { grid-template-columns: repeat(2, minmax(0, 1fr)); overflow: visible; }
}

@media (max-width: 900px) {
    .operator-header {
        position: static;
        width: auto;
        left: auto;
        border-radius: 14px;
    }
    main.op-main {
        margin-left: 250px;
        padding-top: 18px;
    }
    .stats-grid,
    .overview-charts,
    .upcoming-cards {
        grid-template-columns: 1fr;
    }
    .overview-charts .insight-card:last-child { grid-column: auto; }
}
@media (max-width: 620px) {
    .calendar-bookings-modal { padding: 0; align-items: stretch; }
    .calendar-bookings-modal .calendar-bookings-modal-content { max-height: 100vh; border-radius: 0; }
    .calendar-modal-booking-body { grid-template-columns: 1fr; }
    .calendar-view-details { width: 100%; justify-content: center; }
}
</style>
<link rel="stylesheet" href="styles/operator_header.css?v=5">
<link rel="stylesheet" href="styles/operator_booking_drawer.css?v=2">
</head>
<body>

<div class="op-layout">
    <!-- Sidebar -->
    <?php include 'operator_sidebar.php'; ?>

    <!-- Main Content -->
    <main class="op-main">
        <header class="operator-header">
            <div class="operator-header-left">
                <span class="operator-header-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="m3 11 9-8 9 8"/><path d="M5 10v10h14V10M9 20v-6h6v6"/></svg></span>
                <div class="operator-header-copy"><h2>Operator Dashboard</h2><p>Welcome, <?= htmlspecialchars($operatorName) ?></p></div>
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
                <div class="op-topbar-profile" title="<?= htmlspecialchars($operatorName) ?>" data-operator-header-profile role="button" tabindex="0" aria-label="Open operator profile">
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

            <div class="overview-charts">
                <article class="card insight-card">
                    <div class="insight-card-head"><div class="panel-heading-main"><span class="panel-heading-icon"><i class="fa-solid fa-chart-line"></i></span><div><span class="panel-eyebrow">Booking activity</span><h4>Bookings Overview</h4></div></div><span class="panel-note"><?= number_format($totalBookings) ?> total</span></div>
                    <div class="chart-stage"><canvas id="bookingChart"></canvas></div>
                </article>
                <article class="card insight-card">
                    <div class="insight-card-head"><div class="panel-heading-main"><span class="panel-heading-icon"><i class="fa-solid fa-coins"></i></span><div><span class="panel-eyebrow">Financial performance</span><h4>Booked vs. Collected</h4></div></div><span class="panel-note">₱<?= number_format($totalCollectedValue, 0) ?> received</span></div>
                    <div class="chart-stage"><canvas id="revenueChart"></canvas></div>
                </article>
                <article class="card insight-card">
                    <div class="insight-card-head"><div class="panel-heading-main"><span class="panel-heading-icon"><i class="fa-solid fa-chart-pie"></i></span><div><span class="panel-eyebrow">Booking mix</span><h4>Status Overview</h4></div></div><span class="panel-note"><?= number_format($pendingBookings) ?> pending</span></div>
                    <div class="chart-stage"><canvas id="statusPieChart"></canvas></div>
                </article>
            </div>

            <div class="schedule-workspace">
                <div class="card calendar-card schedule-calendar-card">
                    <div class="calendar-card-head">
                        <div class="panel-heading-main"><span class="panel-heading-icon"><i class="fa-regular fa-calendar-days"></i></span><div><span class="panel-eyebrow">Schedule overview</span><h4>Booking Calendar</h4></div></div>
                        <?php if ($upcomingRows): ?><div class="schedule-summary"><i class="fa-regular fa-bell"></i><div><strong><?= count($upcomingRows) ?> upcoming accepted booking<?= count($upcomingRows) === 1 ? '' : 's' ?></strong><small>Next tour: <?= htmlspecialchars(date('D, M j, Y', strtotime((string)$upcomingRows[0]['booking_date']))) ?></small></div></div><?php else: ?><div class="schedule-summary"><i class="fa-regular fa-calendar-check"></i><div><strong>Schedule is clear</strong><small>No accepted tours in this view</small></div></div><?php endif; ?>
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
                    <div class="schedule-legend"><span><i></i>Accepted bookings</span><span><i class="today"></i>Today</span></div>
                </div>
                <div class="schedule-side">
                    <aside class="card upcoming-panel" aria-labelledby="upcomingToursTitle">
                        <div class="upcoming-panel-head"><div class="panel-heading-main"><span class="panel-heading-icon"><i class="fa-solid fa-route"></i></span><div><span class="panel-eyebrow">Requires attention</span><h4 id="upcomingToursTitle">Upcoming Tours</h4></div></div><span class="upcoming-count"><?= count($upcomingRows) ?></span></div>
                        <div class="upcoming-cards">
                            <?php foreach ($upcomingRows as $u):
                                $guestCount = (int)$u['pax'] > 0 ? (int)$u['pax'] : ((int)$u['num_adults'] + (int)$u['num_children']);
                                $guestName = trim((string)$u['tourist_name']) ?: 'Guest';
                                $guestInitial = strtoupper(function_exists('mb_substr') ? mb_substr($guestName, 0, 1) : substr($guestName, 0, 1));
                                $serviceName = trim((string)($u['package_name'] ?: $u['location'])) ?: 'Tour service';
                            ?>
                                <article class="upcoming-card">
                                    <div class="upcoming-card-top"><span class="booking-status"><?= htmlspecialchars((string)$u['status']) ?></span><time datetime="<?= htmlspecialchars((string)$u['booking_date']) ?>"><i class="fa-regular fa-calendar"></i> <?= htmlspecialchars(date('M j, Y', strtotime((string)$u['booking_date']))) ?></time></div>
                                    <div class="upcoming-guest"><span class="upcoming-avatar"><?= htmlspecialchars($guestInitial) ?><?php if (!empty($u['profile_image'])): ?><img src="<?= htmlspecialchars((string)$u['profile_image']) ?>" alt="<?= htmlspecialchars($guestName) ?> profile picture" loading="lazy" decoding="async" referrerpolicy="no-referrer" onerror="this.remove()"><?php endif; ?></span><div><strong><?= htmlspecialchars($guestName) ?></strong><span><?= $guestCount ?> guest<?= $guestCount === 1 ? '' : 's' ?> · <?= htmlspecialchars((string)($u['booking_reference'] ?: '#' . $u['booking_id'])) ?></span></div></div>
                                    <div class="upcoming-service"><small>Package / service</small><strong><?= htmlspecialchars($serviceName) ?></strong></div>
                                    <button class="upcoming-action" type="button" data-dashboard-booking="<?= (int)$u['booking_id'] ?>">View booking details&nbsp; →</button>
                                </article>
                            <?php endforeach; ?>
                            <?php if (!$upcomingRows): ?><div class="upcoming-empty"><i class="fa-regular fa-calendar-check" style="font-size:22px;color:#69a28e;"></i><p>No accepted tours are scheduled for this view.</p></div><?php endif; ?>
                        </div>
                    </aside>
                    <article class="card insight-card" style="min-height:270px;">
                        <div class="insight-card-head"><div class="panel-heading-main"><span class="panel-heading-icon"><i class="fa-solid fa-map-location-dot"></i></span><div><span class="panel-eyebrow">Package demand</span><h4>Most Booked Packages</h4></div></div><span class="panel-note">Top 6</span></div>
                        <div class="chart-stage"><canvas id="packageDemandChart"></canvas></div>
                    </article>
                </div>
            </div>

            <section class="card recent-table-card" aria-labelledby="recentBookingsTitle">
                <div class="table-card-head"><div class="panel-heading-main"><span class="panel-heading-icon"><i class="fa-solid fa-list-check"></i></span><div><span class="panel-eyebrow">Latest activity</span><h4 id="recentBookingsTitle">Recent Bookings</h4></div></div><a class="table-link" href="opbookings.php">View all bookings</a></div>
                <div class="recent-table-wrap">
                    <table class="recent-table">
                        <thead><tr><th>Reference</th><th>Guest</th><th>Package</th><th>Tour date</th><th>Guests</th><th>Payment</th><th>Status</th><th></th></tr></thead>
                        <tbody>
                            <?php foreach ($recentBookings as $row):
                                $grandTotal = max(0, (float)$row['grand_total']);
                                $paidAmount = max(0, (float)$row['payment_amount']);
                                $paidPercent = $grandTotal > 0 ? min(100, (int)round(($paidAmount / $grandTotal) * 100)) : 0;
                                $statusKey = strtolower((string)$row['status']);
                                if ((float)$row['remaining_balance'] <= 0 && $grandTotal > 0) $paymentLabel = 'Paid';
                                elseif ($paidAmount > 0) $paymentLabel = 'Partial';
                                else $paymentLabel = 'Unpaid';
                            ?>
                                <tr>
                                    <td><strong><?= htmlspecialchars((string)($row['booking_reference'] ?: '#' . $row['booking_id'])) ?></strong><br><small><?= htmlspecialchars(date('M j, Y', strtotime((string)$row['created_at']))) ?></small></td>
                                    <td><?= htmlspecialchars((string)$row['tourist_name']) ?></td>
                                    <td><?= htmlspecialchars((string)($row['package_name'] ?: 'Tour service')) ?></td>
                                    <td><?= htmlspecialchars(date('M j, Y', strtotime((string)$row['booking_date']))) ?></td>
                                    <td><?= (int)$row['guests'] ?></td>
                                    <td><strong><?= $paymentLabel ?></strong><div class="payment-progress" title="<?= $paidPercent ?>% collected"><span style="width:<?= $paidPercent ?>%"></span></div></td>
                                    <td><span class="table-status <?= htmlspecialchars($statusKey) ?>"><?= htmlspecialchars((string)$row['status']) ?></span></td>
                                    <td><button class="table-link" type="button" data-dashboard-booking="<?= (int)$row['booking_id'] ?>">Open</button></td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (!$recentBookings): ?><tr><td colspan="8" style="text-align:center;padding:28px;color:#778982;">No bookings found for the selected overview filter.</td></tr><?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>

        </section>
    </main>
</div>

<div class="ho-booking-details-overlay" id="opDashboardBookingDetails" aria-hidden="true">
    <aside class="ho-booking-details-drawer" role="dialog" aria-modal="true" aria-labelledby="opDashboardBookingDetailsTitle">
        <header class="ho-booking-details-header">
            <div class="ho-booking-drawer-brand">
                <img src="img/newlogo.png" alt="">
                <div><span>ITOUR MERCEDES</span><h3 id="opDashboardBookingDetailsTitle">Booking Details</h3></div>
            </div>
            <button type="button" class="ho-booking-details-close" data-close-dashboard-details aria-label="Close booking details">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m6 6 12 12M18 6 6 18"/></svg>
            </button>
        </header>
        <div class="ho-booking-details-body" id="opDashboardBookingDetailsBody"></div>
        <footer class="ho-booking-details-footer">
            <button type="button" class="ho-booking-details-close-btn" data-close-dashboard-details>Close Details</button>
        </footer>
    </aside>
</div>

<!-- BOOKING MODAL -->
<div class="modal calendar-bookings-modal" id="bookingModal" role="dialog" aria-modal="true" aria-labelledby="bookingModalTitle" aria-hidden="true">
    <div class="modal-content calendar-bookings-modal-content">
        <div class="calendar-modal-header">
            <span class="calendar-modal-header-icon" aria-hidden="true"><i class="fa-regular fa-calendar-check"></i></span>
            <div class="calendar-modal-heading"><span>Accepted schedule</span><h3 id="bookingModalTitle">Bookings for <time id="modalDate"></time></h3></div>
            <button type="button" class="modal-close" onclick="closeModal()" aria-label="Close scheduled bookings"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m6 6 12 12M18 6 6 18"/></svg></button>
        </div>
        <div id="modalBody"></div>
    </div>
</div>

<script>
const escapeOperatorHtml = value => String(value ?? '').replace(/[&<>'"]/g, character => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#039;', '"': '&quot;' }[character]));
const formatOperatorCalendarDate = value => {
    const parsed = new Date(`${value}T00:00:00`);
    return Number.isNaN(parsed.getTime()) ? String(value) : parsed.toLocaleDateString('en-PH', { month: 'long', day: 'numeric', year: 'numeric' });
};
Chart.defaults.font.family = 'Inter, sans-serif';
Chart.defaults.font.size = 9;
Chart.defaults.color = '#60746c';
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
            pointRadius: 4,
            pointHoverRadius: 6,
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
                ticks: { color: '#4f636c', font: { size: 11, weight: '600' }, precision: 0 },
                grid: { color: 'rgba(18,44,32,0.08)', drawBorder: false }
            },
            x: {
                ticks: { color: '#4f636c', font: { size: 11, weight: '600' }, maxRotation: 0 },
                grid: { color: 'rgba(18,44,32,0.08)', drawBorder: false }
            }
        }
    }
});

const revenueCtx = document.getElementById('revenueChart').getContext('2d');
new Chart(revenueCtx, {
    type: 'bar',
    data: {
        labels: <?= json_encode($revenueLabels, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
        datasets: [
            { label: 'Booked value', data: <?= json_encode($revenueBooked) ?>, backgroundColor: 'rgba(43,122,102,.22)', borderColor: '#2b7a66', borderWidth: 1, borderRadius: 5 },
            { label: 'Collected', data: <?= json_encode($revenueCollected) ?>, backgroundColor: '#2b7a66', borderRadius: 5 }
        ]
    },
    options: {
        responsive: true, maintainAspectRatio: false,
        plugins: { legend: { position: 'bottom', labels: { usePointStyle: true, boxWidth: 8, color: '#52675f', font: { size: 11, weight: '600' } } }, tooltip: { callbacks: { label: context => `${context.dataset.label}: ₱${Number(context.raw || 0).toLocaleString()}` } } },
        scales: { x: { grid: { display: false }, ticks: { color: '#657870', font: { size: 10 } } }, y: { beginAtZero: true, grid: { color: 'rgba(18,44,32,.07)' }, ticks: { color: '#657870', font: { size: 10 }, callback: value => `₱${Number(value).toLocaleString()}` } } }
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
                    font: { size: 11, weight: '600' }
                }
            }
        }
    }
});

const demandCtx = document.getElementById('packageDemandChart').getContext('2d');
new Chart(demandCtx, {
    type: 'bar',
    data: {
        labels: <?= json_encode(array_map(static fn($row) => (string)$row['service_name'], $packageDemandRows), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
        datasets: [{ label: 'Bookings', data: <?= json_encode(array_map(static fn($row) => (int)$row['booking_count'], $packageDemandRows)) ?>, backgroundColor: ['#2b7a66','#438c76','#5b9d87','#74ae99','#8bbfac','#a3d0bf'], borderRadius: 6, maxBarThickness: 24 }]
    },
    options: {
        indexAxis: 'y', responsive: true, maintainAspectRatio: false,
        plugins: { legend: { display: false } },
        scales: { x: { beginAtZero: true, ticks: { precision: 0, color: '#657870', font: { size: 10 } }, grid: { color: 'rgba(18,44,32,.07)' } }, y: { grid: { display: false }, ticks: { color: '#52675f', font: { size: 10 }, callback: function(value) { const label = this.getLabelForValue(value); return label.length > 22 ? label.slice(0, 22) + '…' : label; } } } }
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
        body.set('csrf_token', <?= json_encode($operatorNotificationCsrf) ?>);
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
            notifToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
            if (open) markNotificationsRead();
        });
        document.addEventListener('click', (event) => {
            if (!notifPanel.contains(event.target) && !notifToggle.contains(event.target)) {
                notifPanel.classList.remove('open');
                notifToggle.setAttribute('aria-expanded', 'false');
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
        height: 'auto',
        contentHeight: 560,
        expandRows: true,
        events: <?= json_encode($calendarEvents, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
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
        dayHeaderFormat: { weekday: 'short' },
        dayMaxEvents: 2,
        navLinks: true,
        nowIndicator: true,
        stickyHeaderDates: true,
        eventDisplay: 'block',
        eventContent: function(info) {
            const bookings = Array.isArray(info.event.extendedProps.bookings) ? info.event.extendedProps.bookings : [];
            const count = bookings.length;
            return { html: `<div class="op-calendar-event"><i class="fa-regular fa-calendar-check" aria-hidden="true"></i><span>${count} ${count === 1 ? 'booking' : 'bookings'}</span></div>` };
        },
        eventDidMount: function(info) {
            info.el.setAttribute('title', `${info.event.title} — select to view details`);
            info.el.setAttribute('aria-label', `${info.event.title} on ${info.event.startStr}. Select to view details.`);
        },
        eventClick: function(info) {
            info.jsEvent.preventDefault();
            const bookings = info.event.extendedProps.bookings;
            if (!bookings || bookings.length === 0) return;

            const modalDate = document.getElementById('modalDate');
            modalDate.dateTime = info.event.startStr;
            modalDate.textContent = formatOperatorCalendarDate(info.event.startStr);

            let html = '';
            bookings.forEach((b, index) => {
                const guests = Number(b.pax || 0) > 0 ? Number(b.pax) : Number(b.num_adults || 0) + Number(b.num_children || 0);
                const guestName = String(b.tourist_name || 'Guest');
                const initials = guestName.split(/\s+/).filter(Boolean).slice(0, 2).map(part => part[0]).join('').toUpperCase() || 'G';
                const profileImage = String(b.profile_image || '').trim();
                const avatar = `${escapeOperatorHtml(initials)}${profileImage ? `<img src="${escapeOperatorHtml(profileImage)}" alt="" loading="lazy" decoding="async" referrerpolicy="no-referrer" onerror="this.remove()">` : ''}`;
                const bookingType = String(b.booking_type || 'Package');
                html += `
                <article class="calendar-modal-booking">
                    <div class="calendar-modal-booking-top">
                        <span class="calendar-booking-type"><i class="fa-solid fa-tag" aria-hidden="true"></i>${escapeOperatorHtml(bookingType)}</span>
                        <span class="calendar-modal-booking-ref">Booking ${index + 1} of ${bookings.length}</span>
                    </div>
                    <div class="calendar-modal-booking-body">
                        <div class="calendar-modal-guest"><span class="calendar-modal-avatar" aria-hidden="true">${avatar}</span><div><strong>${escapeOperatorHtml(guestName)}</strong><span>${guests} guest(s)</span></div></div>
                        <div class="calendar-modal-trip"><small>Package / location</small><strong>${escapeOperatorHtml(b.package_name || b.location || 'Tour service')}</strong></div>
                        <button class="calendar-view-details" type="button" data-dashboard-booking="${Number(b.booking_id || 0)}">View details <i class="fa-solid fa-arrow-right" aria-hidden="true"></i></button>
                    </div>
                </article>
                `;
            });

            document.getElementById('modalBody').innerHTML = html;
            document.getElementById('bookingModal').style.display = 'flex';
            document.getElementById('bookingModal').setAttribute('aria-hidden', 'false');
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
    document.getElementById('bookingModal').setAttribute('aria-hidden', 'true');
}

document.getElementById('bookingModal')?.addEventListener('click', event => {
    if (event.target.id === 'bookingModal') closeModal();
});
document.addEventListener('keydown', event => {
    if (event.key === 'Escape' && document.getElementById('bookingModal')?.style.display === 'flex') closeModal();
});

const dashboardDetails = (() => {
    const overlay = document.getElementById('opDashboardBookingDetails');
    const body = document.getElementById('opDashboardBookingDetailsBody');
    const closeButton = overlay?.querySelector('.ho-booking-details-close');
    let lastTrigger = null;

    const icons = {
        guest: '<svg viewBox="0 0 24 24"><path d="M20 21a8 8 0 0 0-16 0"/><circle cx="12" cy="7" r="4"/></svg>',
        users: '<svg viewBox="0 0 24 24"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/></svg>',
        calendar: '<svg viewBox="0 0 24 24"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 10h18"/></svg>',
        service: '<svg viewBox="0 0 24 24"><path d="M4 19h16M6 16l2-9h8l2 9M9 11h6"/><path d="M12 3v4"/></svg>',
        map: '<svg viewBox="0 0 24 24"><path d="m3 6 6-3 6 3 6-3v15l-6 3-6-3-6 3Z"/><path d="M9 3v15M15 6v15"/></svg>',
        port: '<svg viewBox="0 0 24 24"><path d="M3 19h18M5 19l2-8h10l2 8M9 11V6h6v5M12 6V3"/></svg>',
        payment: '<svg viewBox="0 0 24 24"><rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20M6 15h2"/></svg>'
    };
    const money = value => `₱${Number(value || 0).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
    const date = (value, includeTime = false) => {
        if (!value) return '-';
        const parsed = new Date(includeTime ? String(value).replace(' ', 'T') : `${value}T00:00:00`);
        if (Number.isNaN(parsed.getTime())) return String(value);
        return parsed.toLocaleDateString('en-PH', { year: 'numeric', month: 'long', day: 'numeric', ...(includeTime ? { hour: 'numeric', minute: '2-digit' } : {}) });
    };
    const row = (icon, label, value) => `<div class="ho-bd-detail-row"><span class="ho-bd-row-icon">${icons[icon]}</span><div><small>${escapeOperatorHtml(label)}</small><strong>${escapeOperatorHtml(value || '-')}</strong></div></div>`;
    const close = () => {
        overlay?.classList.remove('show');
        overlay?.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('ho-booking-details-open');
        lastTrigger?.focus();
    };
    const render = data => {
        const completed = String(data.is_complete || '').toLowerCase() === 'completed';
        const status = completed ? 'Completed' : String(data.status || 'Pending');
        const guest = String(data.guest_name || 'Guest');
        const initials = guest.split(/\s+/).filter(Boolean).slice(0, 2).map(part => part[0]).join('').toUpperCase() || 'G';
        const balance = Number(data.remaining_balance || 0);
        const paid = Number(data.payment_amount || 0);
        const paymentStatus = balance <= 0 ? 'Paid' : paid > 0 ? 'Partial' : 'Unpaid';
        const profileImage = String(data.profile_image || '').trim();
        const avatar = `${escapeOperatorHtml(initials)}${profileImage ? `<img src="${escapeOperatorHtml(profileImage)}" alt="${escapeOperatorHtml(guest)} profile picture" loading="lazy" decoding="async" referrerpolicy="no-referrer" onerror="this.remove()">` : ''}`;
        body.innerHTML = `
            <div class="ho-bd-drawer-hero"><div class="ho-bd-reference"><span>BOOKING REFERENCE</span><strong>${escapeOperatorHtml(data.booking_reference || data.booking_id)}</strong></div><span class="ho-bd-status-pill">${escapeOperatorHtml(status)}</span></div>
            <section class="ho-bd-drawer-section ho-bd-guest-card"><div class="ho-bd-guest-avatar">${avatar}</div><div class="ho-bd-guest-primary"><small>PRIMARY GUEST</small><h4>${escapeOperatorHtml(guest)}</h4><p>${escapeOperatorHtml(data.guest_email || '-')}</p></div></section>
            <section class="ho-bd-drawer-section"><div class="ho-bd-section-title"><span>${icons.guest}</span><div><h4>Guest information</h4><p>Contact details used for this reservation</p></div></div><div class="ho-bd-detail-grid">${row('guest', 'Contact number', data.phone_number)}${row('users', 'Guest count', `${Number(data.pax || 0)} passenger(s)`)}</div></section>
            <section class="ho-bd-drawer-section"><div class="ho-bd-section-title"><span>${icons.calendar}</span><div><h4>Trip information</h4><p>Package, schedule, and meeting details</p></div></div><div class="ho-bd-detail-grid">${row('service', 'Selected package', data.package_name)}${row('service', 'Booking type', data.booking_type)}${row('map', 'Destination', data.location)}${row('calendar', 'Tour date', date(data.booking_date))}${row('port', 'Jump-off port', data.jump_off_port)}${row('calendar', 'Booked on', date(data.created_at, true))}</div></section>
            <section class="ho-bd-drawer-section ho-bd-payment-card"><div class="ho-bd-section-title"><span>${icons.payment}</span><div><h4>Payment summary</h4><p>Current financial status of this booking</p></div></div><div class="ho-bd-payment-lines"><div><span>Grand total</span><strong>${money(data.grand_total)}</strong></div><div><span>Amount received</span><strong>${money(paid)}</strong></div><div class="ho-bd-grand-total"><span>Remaining balance</span><strong>${money(balance)}</strong></div></div><div class="ho-bd-payment-stats"><div><small>PAYMENT METHOD</small><strong>${escapeOperatorHtml(data.payment_method ? String(data.payment_method).replace(/_/g, ' ') : 'Not selected')}</strong></div><div><small>PAYMENT STATUS</small><strong class="ho-bd-payment-${paymentStatus.toLowerCase()}">${paymentStatus}</strong></div></div></section>
            <div class="ho-bd-confidence-note"><span>${icons.service}</span><p><strong>Booking record verified</strong>Details shown here are loaded directly from the operator's current booking record.</p></div>`;
    };
    const open = async trigger => {
        const id = Number(trigger.dataset.dashboardBooking || 0);
        if (!id || !overlay || !body) return;
        lastTrigger = trigger;
        if (document.getElementById('bookingModal')?.style.display === 'flex') closeModal();
        body.innerHTML = '<div class="ho-bd-loading"><span></span><strong>Loading booking details…</strong></div>';
        overlay.classList.add('show');
        overlay.setAttribute('aria-hidden', 'false');
        document.body.classList.add('ho-booking-details-open');
        closeButton?.focus();
        try {
            const response = await fetch(`ophomepage.php?op_action=booking_details&id=${encodeURIComponent(id)}`, { headers: { Accept: 'application/json' }, cache: 'no-store' });
            const responseText = await response.text();
            let payload;
            try {
                payload = JSON.parse(responseText);
            } catch (_parseError) {
                throw new Error('The server returned an invalid booking response. Please refresh and try again.');
            }
            if (!response.ok || !payload.success || !payload.booking) throw new Error(payload.message || 'Booking details could not be loaded.');
            render(payload.booking);
        } catch (error) {
            body.innerHTML = `<div class="ho-bd-error"><strong>Unable to load booking details</strong><p>${escapeOperatorHtml(error.message)}</p></div>`;
        }
    };
    document.addEventListener('click', event => {
        const trigger = event.target.closest('[data-dashboard-booking]');
        if (trigger) { event.preventDefault(); open(trigger); }
    });
    overlay?.querySelectorAll('[data-close-dashboard-details]').forEach(button => button.addEventListener('click', close));
    overlay?.addEventListener('mousedown', event => { if (event.target === overlay) close(); });
    document.addEventListener('keydown', event => { if (event.key === 'Escape' && overlay?.classList.contains('show')) close(); });
    return { close };
})();

</script>
<script src="js/operator_header.js?v=4"></script>
</body>
</html>

