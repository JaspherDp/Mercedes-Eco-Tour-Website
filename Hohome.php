<?php
require_once __DIR__ . '/Ho_common.php';

function hoDashboardTrendVisual(int $current, int $previous, bool $lowerIsBetter = false): string
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
    return '<span class="ho-dashboard-trend '.$tone.'" aria-hidden="true"><svg viewBox="0 0 72 28"><path class="guide" d="M2 25H70"/><polyline class="line" points="'.$points.'"/><circle cx="70" cy="'.$endpointY.'" r="2.5"/><path class="arrow" d="'.$tip.'"/></svg></span>';
}

$hoAdmin = HoRequireHotelAdmin($pdo);
$hoHotelResortId = (int)$hoAdmin['hotel_resort_id'];
$hoPropertyName = trim((string)($hoAdmin['property_name'] ?? ''));

$hoActive = 'dashboard';
$hoTitle = 'Dashboard';
$hoOwnerName = $hoPropertyName !== '' ? $hoPropertyName . ' Admin' : (string)$hoAdmin['username'];
$hoPendingBadge = HoGetPendingCount($pdo, $hoHotelResortId);
$hoUnreadBadge = HoGetUnreadCount($pdo, $hoHotelResortId);
$hoNotifItems = HoGetNotificationItems($pdo, 8, $hoHotelResortId);
$hotelNotificationCsrf = AppCsrfToken('hotel_admin', 'notifications');
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
$yearFromDate = (int)substr($selectedDate, 0, 4);
if ($rangeFilter === 'daily' && $yearFromDate !== $selectedYear) {
    $selectedDate = sprintf('%04d-%02d-%02d', $selectedYear, (int)date('m'), (int)date('d'));
}
$availableYears = $pdo->query("
    SELECT DISTINCT YEAR(created_at) AS yr
    FROM hotel_room_bookings
    WHERE created_at IS NOT NULL AND hotel_resort_id = " . (int)$hoHotelResortId . "
    ORDER BY yr DESC
")->fetchAll(PDO::FETCH_COLUMN);
$availableYears = array_values(array_filter(array_map('intval', $availableYears)));
if (empty($availableYears)) {
    $availableYears = [$currentYear];
}
if (!in_array($selectedYear, $availableYears, true)) {
    $selectedYear = $availableYears[0];
}
$hoShowRangeFilter = true;
$hoRangeFilter = $rangeFilter;
$hoRangeYear = $selectedYear;
$hoRangeMonth = $selectedMonth;
$hoRangeDate = $selectedDate;
$hoRangeYears = $availableYears;
$calendarDisplayYear = $currentYear;
$calendarDisplayMonth = $currentMonth;
if ($rangeFilter === 'monthly') {
    $calendarDisplayYear = $selectedYear;
    $calendarDisplayMonth = $selectedMonth;
} elseif ($rangeFilter === 'daily') {
    $calendarDisplayYear = (int)substr($selectedDate, 0, 4);
    $calendarDisplayMonth = (int)substr($selectedDate, 5, 2);
} elseif ($rangeFilter === 'yearly') {
    $calendarDisplayYear = $selectedYear;
    $calendarDisplayMonth = $currentMonth;
}
$requestedCalendarYear = (int)($_GET['calendar_year'] ?? 0);
$requestedCalendarMonth = (int)($_GET['calendar_month'] ?? 0);
if ($requestedCalendarYear >= 2000 && $requestedCalendarYear <= ($currentYear + 5)
    && $requestedCalendarMonth >= 1 && $requestedCalendarMonth <= 12) {
    $calendarDisplayYear = $requestedCalendarYear;
    $calendarDisplayMonth = $requestedCalendarMonth;
}

if (isset($_POST['ho_action']) && $_POST['ho_action'] === 'mark_notifications_read') {
    if (!AppVerifyCsrf('hotel_admin', 'notifications', $_POST['csrf_token'] ?? null)) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['ok' => false]);
        exit;
    }
    HoMarkNotificationsRead($pdo, $hoHotelResortId);
    header('Content-Type: application/json');
    echo json_encode(['ok' => true]);
    exit;
}

if (isset($_GET['ho_action']) && $_GET['ho_action'] === 'booking_details') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    $bookingId = (int)($_GET['booking_id'] ?? 0);
    if ($bookingId <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'A valid booking is required.']);
        exit;
    }
    $detailStmt = $pdo->prepare("
        SELECT b.*, (b.adults + b.children) AS guest_count
        FROM hotel_room_bookings b
        WHERE b.hotel_booking_id = ? AND b.hotel_resort_id = ?
        LIMIT 1
    ");
    $detailStmt->execute([$bookingId, $hoHotelResortId]);
    $booking = $detailStmt->fetch(PDO::FETCH_ASSOC);
    if (!$booking) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'This booking was not found for your property.']);
        exit;
    }
    echo json_encode(['success' => true, 'booking' => $booking], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

$rangeWhere = "hotel_resort_id = :hotel_resort_id";
$params = [];
$params[':hotel_resort_id'] = $hoHotelResortId;
switch ($rangeFilter) {
    case 'daily':
        $rangeWhere .= " AND DATE(created_at) = :selected_date AND YEAR(created_at) = :selected_year";
        $params[':selected_date'] = $selectedDate;
        $params[':selected_year'] = $selectedYear;
        $chartGroupSql = "DATE_FORMAT(created_at, '%Y-%m-%d %H:00')";
        break;
    case 'weekly':
        $rangeWhere .= " AND YEARWEEK(created_at, 1) = YEARWEEK(CURDATE(), 1)";
        $chartGroupSql = "DATE_FORMAT(created_at, '%Y-%m-%d')";
        break;
    case 'monthly':
        $rangeWhere .= " AND YEAR(created_at) = :selected_year AND MONTH(created_at) = :selected_month";
        $params[':selected_year'] = $selectedYear;
        $params[':selected_month'] = $selectedMonth;
        $chartGroupSql = "DATE_FORMAT(created_at, '%Y-%m-%d')";
        break;
    case 'yearly':
        $rangeWhere .= " AND YEAR(created_at) = :selected_year";
        $params[':selected_year'] = $selectedYear;
        $chartGroupSql = "DATE_FORMAT(created_at, '%Y-%m')";
        break;
    case 'all':
    default:
        $chartGroupSql = "DATE_FORMAT(created_at, '%Y-%m')";
        break;
}

$totalStmt = $pdo->prepare("SELECT COUNT(*) FROM hotel_room_bookings WHERE $rangeWhere");
$totalStmt->execute($params);
$totalBookings = (int)$totalStmt->fetchColumn();
$pendingStmt = $pdo->prepare("SELECT COUNT(*) FROM hotel_room_bookings WHERE booking_status='pending' AND $rangeWhere");
$pendingStmt->execute($params);
$pendingBookings = (int)$pendingStmt->fetchColumn();
$confirmedStmt = $pdo->prepare("SELECT COUNT(*) FROM hotel_room_bookings WHERE booking_status='confirmed' AND $rangeWhere");
$confirmedStmt->execute($params);
$confirmedBookings = (int)$confirmedStmt->fetchColumn();
$cancelledStmt = $pdo->prepare("SELECT COUNT(*) FROM hotel_room_bookings WHERE booking_status='cancelled' AND $rangeWhere");
$cancelledStmt->execute($params);
$cancelledBookings = (int)$cancelledStmt->fetchColumn();

$trendStmt = $pdo->prepare("
    SELECT $chartGroupSql AS grp, COUNT(*) AS cnt
    FROM hotel_room_bookings
    WHERE $rangeWhere
    GROUP BY grp
    ORDER BY grp ASC
");
$trendStmt->execute($params);
$trendRows = $trendStmt->fetchAll(PDO::FETCH_ASSOC);
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
$statusTrendStmt = $pdo->prepare("SELECT $chartGroupSql AS grp, LOWER(booking_status) booking_status, COUNT(*) cnt FROM hotel_room_bookings WHERE $rangeWhere GROUP BY grp, LOWER(booking_status) ORDER BY grp ASC");
$statusTrendStmt->execute($params);
$statusTrendMap = [];
foreach ($statusTrendStmt->fetchAll(PDO::FETCH_ASSOC) as $statusTrendRow) {
    $statusTrendMap[(string)$statusTrendRow['grp']][(string)$statusTrendRow['booking_status']] = (int)$statusTrendRow['cnt'];
}
$trendCurrentIndex = max(0, count($lineLabelsRaw) - 1);
$trendPreviousIndex = max(0, $trendCurrentIndex - 1);
$trendCurrentGroup = (string)($lineLabelsRaw[$trendCurrentIndex] ?? '');
$trendPreviousGroup = count($lineLabelsRaw) > 1 ? (string)($lineLabelsRaw[$trendPreviousIndex] ?? '') : '';
$dashboardTrendValues = [
    'total' => [(int)($lineData[$trendCurrentIndex] ?? 0), count($lineData) > 1 ? (int)($lineData[$trendPreviousIndex] ?? 0) : 0],
    'pending' => [(int)($statusTrendMap[$trendCurrentGroup]['pending'] ?? 0), (int)($statusTrendMap[$trendPreviousGroup]['pending'] ?? 0)],
    'confirmed' => [(int)($statusTrendMap[$trendCurrentGroup]['confirmed'] ?? 0), (int)($statusTrendMap[$trendPreviousGroup]['confirmed'] ?? 0)],
    'cancelled' => [(int)($statusTrendMap[$trendCurrentGroup]['cancelled'] ?? 0), (int)($statusTrendMap[$trendPreviousGroup]['cancelled'] ?? 0)],
];
$financialStmt = $pdo->prepare("
    SELECT $chartGroupSql AS grp,
           COALESCE(SUM(total_amount), 0) AS booked_revenue,
           COALESCE(SUM(amount_paid), 0) AS collected_revenue
    FROM hotel_room_bookings
    WHERE $rangeWhere AND booking_status <> 'cancelled'
    GROUP BY grp
    ORDER BY grp ASC
");
$financialStmt->execute($params);
$financialRows = $financialStmt->fetchAll(PDO::FETCH_ASSOC);
$financialMap = [];
foreach ($financialRows as $row) {
    $financialMap[(string)$row['grp']] = $row;
}
$revenueData = array_map(fn($key) => (float)($financialMap[$key]['booked_revenue'] ?? 0), $lineLabelsRaw);
$collectedData = array_map(fn($key) => (float)($financialMap[$key]['collected_revenue'] ?? 0), $lineLabelsRaw);

$roomDemandStmt = $pdo->prepare("
    SELECT COALESCE(NULLIF(room_type, ''), 'Unassigned') AS room_label, COUNT(*) AS booking_count
    FROM hotel_room_bookings
    WHERE $rangeWhere AND booking_status <> 'cancelled'
    GROUP BY room_label
    ORDER BY booking_count DESC, room_label ASC
    LIMIT 6
");
$roomDemandStmt->execute($params);
$roomDemandRows = $roomDemandStmt->fetchAll(PDO::FETCH_ASSOC);
$roomDemandLabels = array_map(fn($r) => (string)$r['room_label'], $roomDemandRows);
$roomDemandData = array_map(fn($r) => (int)$r['booking_count'], $roomDemandRows);

$paymentStmt = $pdo->prepare("
    SELECT
      SUM(CASE WHEN LOWER(payment_status) = 'paid' THEN 1 ELSE 0 END) AS paid_count,
      SUM(CASE WHEN LOWER(payment_status) IN ('partial', 'partially_paid') THEN 1 ELSE 0 END) AS partial_count,
      SUM(CASE WHEN LOWER(payment_status) NOT IN ('paid', 'partial', 'partially_paid') OR payment_status IS NULL THEN 1 ELSE 0 END) AS unpaid_count
    FROM hotel_room_bookings
    WHERE $rangeWhere AND booking_status <> 'cancelled'
");
$paymentStmt->execute($params);
$paymentCounts = $paymentStmt->fetch(PDO::FETCH_ASSOC) ?: [];
$confirmationRate = $totalBookings > 0 ? (int)round(($confirmedBookings / $totalBookings) * 100) : 0;
$upcomingStmt = $pdo->prepare("
    SELECT hotel_booking_id, booking_reference, first_name, last_name, room_type, checkin_date, booking_status, created_at
    FROM hotel_room_bookings
    WHERE (booking_status = 'confirmed' OR booking_status = 'pending') AND $rangeWhere
    ORDER BY checkin_date ASC, created_at DESC
    LIMIT 8
");
$upcomingStmt->execute($params);
$upcomingRows = $upcomingStmt->fetchAll(PDO::FETCH_ASSOC);

$recentStmt = $pdo->prepare("
    SELECT
      hotel_booking_id,
      booking_reference,
      first_name,
      last_name,
      room_type,
      checkin_date,
      checkout_date,
      (adults + children) AS guest_count,
      booking_status,
      created_at
    FROM hotel_room_bookings
    WHERE $rangeWhere
    ORDER BY created_at DESC
    LIMIT 10
");
$recentStmt->execute($params);
$recentBookings = $recentStmt->fetchAll(PDO::FETCH_ASSOC);
$acceptedStmt = $pdo->prepare("
    SELECT
      hotel_booking_id,
      booking_reference,
      first_name,
      last_name,
      room_type,
      checkin_date,
      checkout_date,
      (adults + children) AS guest_count,
      booking_status
    FROM hotel_room_bookings
    WHERE booking_status IN ('confirmed', 'pending') AND hotel_resort_id = :calendar_hotel_resort_id
    ORDER BY checkin_date ASC, created_at DESC
");
$acceptedStmt->execute([':calendar_hotel_resort_id' => $hoHotelResortId]);
$acceptedCheckins = $acceptedStmt->fetchAll(PDO::FETCH_ASSOC);

$acceptedByDate = [];
foreach ($acceptedCheckins as $item) {
    $dateKey = (string)$item['checkin_date'];
    if (!isset($acceptedByDate[$dateKey])) {
        $acceptedByDate[$dateKey] = [];
    }
    $acceptedByDate[$dateKey][] = [
        'id' => (int)$item['hotel_booking_id'],
        'reference' => (string)($item['booking_reference'] ?: $item['hotel_booking_id']),
        'guest' => trim((string)$item['first_name'] . ' ' . (string)$item['last_name']),
        'room_type' => (string)$item['room_type'],
        'checkin' => (string)$item['checkin_date'],
        'checkout' => (string)$item['checkout_date'],
        'guest_count' => (int)$item['guest_count'],
        'status' => (string)$item['booking_status'],
    ];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Hohome | Hotel Owner Dashboard</title>
  <link rel="icon" type="image/png" href="img/newlogo.png" />
  <link rel="stylesheet" href="styles/Ho_panel.css?v=notifications-4" />
  <link rel="stylesheet" href="styles/booking-details-drawer.css" />
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body class="ho-body">
  <div class="ho-layout">
    <?php include __DIR__ . '/Ho_sidebar.php'; ?>

    <main class="ho-main">
      <?php include __DIR__ . '/Ho_header.php'; ?>

      <section class="ho-content">
        <div class="ho-summary-grid">
          <article class="ho-card ho-summary-card">
            <p>Total Bookings</p>
            <div class="ho-summary-value">
              <div class="ho-summary-icon" aria-hidden="true">
                <svg viewBox="0 0 24 24"><path d="M7 2h10v2h3a2 2 0 0 1 2 2v13a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h3Zm-1 8v2h12v-2H6Zm0 4v2h8v-2H6ZM8 6v2h8V6H8Z"></path></svg>
              </div>
              <h3><?= $totalBookings ?></h3>
            </div>
            <?= hoDashboardTrendVisual(...$dashboardTrendValues['total']) ?>
            <small>Reservations in the selected period</small>
          </article>
          <article class="ho-card ho-summary-card">
            <p>Pending Bookings</p>
            <div class="ho-summary-value">
              <div class="ho-summary-icon" aria-hidden="true">
                <svg viewBox="0 0 24 24"><path d="M12 2a10 10 0 1 0 10 10A10 10 0 0 0 12 2Zm1 10.6V7h-2v6h5v-2.4Z"></path></svg>
              </div>
              <h3><?= $pendingBookings ?></h3>
            </div>
            <?= hoDashboardTrendVisual($dashboardTrendValues['pending'][0], $dashboardTrendValues['pending'][1], true) ?>
            <small>Require review and confirmation</small>
          </article>
          <article class="ho-card ho-summary-card">
            <p>Confirmed Bookings</p>
            <div class="ho-summary-value">
              <div class="ho-summary-icon" aria-hidden="true">
                <svg viewBox="0 0 24 24"><path d="M12 2a10 10 0 1 0 10 10A10 10 0 0 0 12 2Zm4.7 7.7-5.4 5.7a1 1 0 0 1-1.4 0L7.3 13a1 1 0 0 1 1.4-1.4l1.9 1.9 4.7-4.9a1 1 0 1 1 1.4 1.1Z"></path></svg>
              </div>
              <h3><?= $confirmedBookings ?></h3>
            </div>
            <?= hoDashboardTrendVisual(...$dashboardTrendValues['confirmed']) ?>
            <small><?= $confirmationRate ?>% confirmation rate</small>
          </article>
          <article class="ho-card ho-summary-card">
            <p>Cancelled Bookings</p>
            <div class="ho-summary-value">
              <div class="ho-summary-icon" aria-hidden="true">
                <svg viewBox="0 0 24 24"><path d="M12 2a10 10 0 1 0 10 10A10 10 0 0 0 12 2Zm4.2 12.8-1.4 1.4L12 13.4 9.2 16.2l-1.4-1.4L10.6 12 7.8 9.2l1.4-1.4L12 10.6l2.8-2.8 1.4 1.4L13.4 12Z"></path></svg>
              </div>
              <h3><?= $cancelledBookings ?></h3>
            </div>
            <?= hoDashboardTrendVisual($dashboardTrendValues['cancelled'][0], $dashboardTrendValues['cancelled'][1], true) ?>
            <small>Cancelled reservations recorded</small>
          </article>
        </div>

        <div class="ho-insights-grid">
          <article class="ho-card ho-insight-card">
            <div class="ho-card-head">
              <div><p class="ho-eyebrow">FINANCIAL PERFORMANCE</p><h3>Revenue & Collections</h3></div>
              <span class="ho-chart-note">Booked vs. received</span>
            </div>
            <div class="ho-chart-stage"><canvas id="hoRevenueChart"></canvas></div>
          </article>
          <article class="ho-card ho-insight-card">
            <div class="ho-card-head">
              <div><p class="ho-eyebrow">INVENTORY DEMAND</p><h3>Most Booked Room Types</h3></div>
              <span class="ho-chart-note">Top 6</span>
            </div>
            <div class="ho-chart-stage"><canvas id="hoRoomDemandChart"></canvas></div>
          </article>
          <article class="ho-card ho-insight-card ho-payment-card">
            <div class="ho-card-head">
              <div><p class="ho-eyebrow">COLLECTION HEALTH</p><h3>Payment Status</h3></div>
            </div>
            <div class="ho-chart-stage"><canvas id="hoPaymentChart"></canvas></div>
          </article>
        </div>

        <div class="ho-dashboard-analytics">
          <article class="ho-card ho-calendar-card">
            <div class="ho-calendar-feature-head">
              <div class="ho-calendar-heading">
                <span class="ho-calendar-heading-icon" aria-hidden="true">
                  <svg viewBox="0 0 24 24"><path d="M7 2v3m10-3v3M4 8h16M5 4h14a2 2 0 0 1 2 2v15H3V6a2 2 0 0 1 2-2Zm2 8h3v3H7zm7 0h3v3h-3z"/></svg>
                </span>
                <div><p class="ho-eyebrow">SCHEDULE OVERVIEW</p><h3>Booking Calendar</h3></div>
              </div>
              <?php if ($upcomingRows): $nextBooking = $upcomingRows[0]; ?>
              <div class="ho-next-arrival">
                <span aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9ZM10 21h4"/></svg></span>
                <div><strong><?= count($upcomingRows) ?> booking<?= count($upcomingRows) === 1 ? '' : 's' ?> coming up</strong><small>Next check-in: <?= htmlspecialchars(date('l, F j, Y', strtotime((string)$nextBooking['checkin_date']))) ?></small></div>
              </div>
              <?php endif; ?>
            </div>

            <div id="hoCalendarPane">
              <div class="ho-calendar-toolbar">
                <div class="ho-calendar-nav">
                  <button type="button" id="hoCalendarPrevious" aria-label="Previous month">‹</button>
                  <button type="button" id="hoCalendarNext" aria-label="Next month">›</button>
                  <button type="button" id="hoCalendarToday">Today</button>
                </div>
                <div class="ho-calendar-month-label" id="hoCalendarMonthLabel"></div>
                <div class="ho-calendar-legend" aria-label="Calendar legend">
                  <span><i class="confirmed"></i>Confirmed</span>
                  <span><i class="pending"></i>Pending</span>
                </div>
              </div>
              <table class="ho-mini-calendar" id="hoMiniCalendar"></table>
            </div>
          </article>

          <div class="ho-analytics-right">
            <article class="ho-card ho-chart-card">
              <div class="ho-card-head">
                <div><p class="ho-eyebrow">DEMAND</p><h3>Booking Trend</h3></div>
              </div>
              <canvas id="hoLineChart" height="130"></canvas>
            </article>

            <article class="ho-card ho-pie-card">
              <div class="ho-card-head">
                <div><p class="ho-eyebrow">BOOKING MIX</p><h3>Status Overview</h3></div>
              </div>
              <canvas id="hoStatusPieChart" height="130"></canvas>
            </article>
          </div>
        </div>

        <article class="ho-card ho-table-card">
          <h2 class="ho-section-title">Recent Bookings</h2>

          <?php if ($recentBookings): ?>
            <div class="ho-table-wrap">
              <table class="ho-table">
                <thead>
                  <tr>
                    <th>Booking Reference</th>
                    <th>Guest</th>
                    <th>Room Type</th>
                    <th>Check-in / Check-out</th>
                    <th>Guests</th>
                    <th>Status</th>
                    <th>Created</th>
                    <th aria-label="Actions"></th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($recentBookings as $row): ?>
                    <?php $status = strtolower((string)$row['booking_status']); ?>
                    <tr>
                      <td><?= htmlspecialchars((string)($row['booking_reference'] ?: $row['hotel_booking_id'])) ?></td>
                      <td><?= htmlspecialchars(trim($row['first_name'] . ' ' . $row['last_name'])) ?></td>
                      <td><?= htmlspecialchars($row['room_type']) ?></td>
                      <td><?= htmlspecialchars($row['checkin_date']) ?> to <?= htmlspecialchars($row['checkout_date']) ?></td>
                      <td><?= (int)$row['guest_count'] ?></td>
                      <td><span class="ho-status <?= htmlspecialchars($status) ?>"><?= ucfirst($status) ?></span></td>
                      <td><?= htmlspecialchars($row['created_at']) ?></td>
                      <td><button type="button" class="ho-table-action" data-booking-details="<?= (int)$row['hotel_booking_id'] ?>">View</button></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php else: ?>
            <div class="ho-empty">No bookings available yet.</div>
          <?php endif; ?>
        </article>
      </section>

      <?php include __DIR__ . '/Ho_footer.php'; ?>
    </main>
  </div>
  <div class="ho-modal" id="hoCalendarBookingModal" aria-hidden="true">
    <div class="ho-modal-card ho-calendar-modal-card" role="dialog" aria-modal="true" aria-labelledby="hoCalendarBookingTitle">
      <div class="ho-modal-head">
        <div class="ho-calendar-modal-title">
          <span aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M7 3v3m10-3v3M4 9h16M5 5h14a2 2 0 0 1 2 2v14H3V7a2 2 0 0 1 2-2Zm3 9 2 2 5-5"/></svg></span>
          <div><p class="ho-eyebrow">ACCEPTED SCHEDULE</p><h3 id="hoCalendarBookingTitle">Scheduled Check-ins</h3></div>
        </div>
        <button type="button" class="ho-close" id="hoCalendarBookingClose" aria-label="Close">&times;</button>
      </div>
      <div id="hoCalendarBookingBody"></div>
    </div>
  </div>
  <div id="bookingDetailsModal" class="booking-details-modal-overlay" aria-hidden="true">
    <aside class="booking-details-modal" role="dialog" aria-modal="true" aria-labelledby="bookingDetailsTitle">
      <div class="booking-details-modal-header">
        <div class="booking-drawer-brand">
          <img src="img/newlogo.png" alt="" />
          <div><span>ITOUR MERCEDES</span><h3 id="bookingDetailsTitle">Hotel Booking Details</h3></div>
        </div>
        <button type="button" class="booking-details-modal-close" id="bookingDetailsClose" aria-label="Close booking details">
          <svg viewBox="0 0 24 24"><path d="m6 6 12 12M18 6 6 18" /></svg>
        </button>
      </div>
      <div class="booking-details-modal-body" id="bookingDetailsContent">
        <div class="booking-drawer-loading"><span></span><p>Loading booking details…</p></div>
      </div>
      <div class="booking-details-modal-footer">
        <a href="Hobookings.php" class="ho-drawer-manage-link">Open Booking Management</a>
        <button type="button" class="booking-details-modal-btn-close" id="bookingDetailsFooterClose">Close Details</button>
      </div>
    </aside>
  </div>
  <script>
    (function () {
      const toggle = document.getElementById('hoNotifToggle');
      const panel = document.getElementById('hoNotifPanel');
      const markBtn = document.getElementById('hoNotifMarkRead');
      const badge = document.getElementById('hoNotifBadge');
      const unreadSelector = '.ho-notif-item.is-unread';
      let notifMarked = false;
      const hideBadge = () => {
        if (badge) badge.style.display = 'none';
      };
      const hasUnreadItems = () => panel ? panel.querySelector(unreadSelector) !== null : false;
      const clearUnreadState = () => {
        if (!panel) return;
        panel.querySelectorAll(unreadSelector).forEach((item) => item.classList.remove('is-unread'));
        panel.querySelectorAll('.ho-notif-unread-pill').forEach((pill) => pill.remove());
      };

      const markNotificationsRead = async () => {
        if (notifMarked || !hasUnreadItems()) return;
        notifMarked = true;
        const body = new URLSearchParams();
        body.set('ho_action', 'mark_notifications_read');
        body.set('csrf_token', <?= json_encode($hotelNotificationCsrf) ?>);
        try {
          const response = await fetch('Hohome.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body.toString()
          });
          if (!response.ok) {
            throw new Error(`Failed to mark notifications as read (${response.status})`);
          }
          hideBadge();
          clearUnreadState();
        } catch (error) {
          notifMarked = false;
          console.error(error);
        }
      };

      const closePanelAndMarkRead = () => {
        if (!panel || !toggle) return;
        const wasOpen = panel.classList.contains('open');
        panel.classList.remove('open');
        toggle.setAttribute('aria-expanded', 'false');
        if (wasOpen) markNotificationsRead();
      };

      if (toggle && panel) {
        toggle.addEventListener('click', () => {
          const willOpen = !panel.classList.contains('open');
          if (!willOpen) {
            closePanelAndMarkRead();
            return;
          }
          panel.classList.add('open');
          toggle.setAttribute('aria-expanded', 'true');
          hideBadge();
        });
      }

      document.addEventListener('click', (e) => {
        if (panel && toggle && !panel.contains(e.target) && !toggle.contains(e.target)) {
          closePanelAndMarkRead();
        }
      });

      if (markBtn) markBtn.addEventListener('click', markNotificationsRead);

      const lineLabels = <?= json_encode($lineLabels, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
      const lineData = <?= json_encode($lineData, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
      const revenueData = <?= json_encode($revenueData, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
      const collectedData = <?= json_encode($collectedData, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
      const roomDemandLabels = <?= json_encode($roomDemandLabels, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
      const roomDemandData = <?= json_encode($roomDemandData, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
      const acceptedByDate = <?= json_encode($acceptedByDate, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
      const statusCounts = {
        pending: <?= (int)$pendingBookings ?>,
        confirmed: <?= (int)$confirmedBookings ?>,
        cancelled: <?= (int)$cancelledBookings ?>
      };
      const paymentCounts = {
        paid: <?= (int)($paymentCounts['paid_count'] ?? 0) ?>,
        partial: <?= (int)($paymentCounts['partial_count'] ?? 0) ?>,
        unpaid: <?= (int)($paymentCounts['unpaid_count'] ?? 0) ?>
      };
      Chart.defaults.font.family = "Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif";
      Chart.defaults.color = '#65776f';
      const ctx = document.getElementById('hoLineChart');
      if (ctx) {
        new Chart(ctx, {
          type: 'line',
          data: {
            labels: lineLabels,
            datasets: [{
              label: 'Bookings',
              data: lineData,
              borderColor: '#2b7a66',
              backgroundColor: 'rgba(43,122,102,0.12)',
              tension: 0.35,
              fill: true,
              pointRadius: 3
            }]
          },
          options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { intersect: false, mode: 'index' },
            plugins: { legend: { display: false } },
            scales: {
              x: { grid: { display: false }, border: { display: false } },
              y: { beginAtZero: true, ticks: { precision: 0 }, border: { display: false }, grid: { color: '#edf2f0' } }
            }
          }
        });
      }

      const pieCtx = document.getElementById('hoStatusPieChart');
      if (pieCtx) {
        new Chart(pieCtx, {
          type: 'doughnut',
          data: {
            labels: ['Pending', 'Confirmed', 'Cancelled'],
            datasets: [{
              data: [statusCounts.pending, statusCounts.confirmed, statusCounts.cancelled],
              backgroundColor: ['#e2a93a', '#2f8a5f', '#bf4c5a'],
              borderColor: '#ffffff',
              borderWidth: 2
            }]
          },
          options: {
            responsive: true,
            maintainAspectRatio: false,
            cutout: '68%',
            plugins: {
              legend: {
                position: 'bottom',
                labels: { boxWidth: 12, usePointStyle: true, pointStyle: 'circle' }
              }
            }
          }
        });
      }

      const currencyTick = value => '₱' + Number(value || 0).toLocaleString('en-PH', { notation: 'compact', maximumFractionDigits: 1 });
      const revenueCtx = document.getElementById('hoRevenueChart');
      if (revenueCtx) {
        new Chart(revenueCtx, {
          type: 'bar',
          data: { labels: lineLabels, datasets: [
            { label: 'Booked revenue', data: revenueData, backgroundColor: '#b9ded1', borderRadius: 6, maxBarThickness: 30 },
            { label: 'Collected', data: collectedData, backgroundColor: '#216b56', borderRadius: 6, maxBarThickness: 30 }
          ]},
          options: {
            responsive: true, maintainAspectRatio: false, interaction: { intersect: false, mode: 'index' },
            plugins: { legend: { position: 'bottom', align: 'start', labels: { usePointStyle: true, pointStyle: 'circle', boxWidth: 8 } }, tooltip: { callbacks: { label: item => `${item.dataset.label}: ₱${Number(item.raw || 0).toLocaleString('en-PH', { minimumFractionDigits: 2 })}` } } },
            scales: { x: { grid: { display: false }, border: { display: false } }, y: { beginAtZero: true, ticks: { callback: currencyTick }, border: { display: false }, grid: { color: '#edf2f0' } } }
          }
        });
      }

      const demandCtx = document.getElementById('hoRoomDemandChart');
      if (demandCtx) {
        new Chart(demandCtx, {
          type: 'bar',
          data: { labels: roomDemandLabels, datasets: [{ label: 'Bookings', data: roomDemandData, backgroundColor: ['#1f6b55','#398570','#62a18e','#87b9aa','#a9d0c3','#cde5dc'], borderRadius: 7, maxBarThickness: 23 }] },
          options: { indexAxis: 'y', responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { x: { beginAtZero: true, ticks: { precision: 0 }, border: { display: false }, grid: { color: '#edf2f0' } }, y: { grid: { display: false }, border: { display: false } } } }
        });
      }

      const paymentCtx = document.getElementById('hoPaymentChart');
      if (paymentCtx) {
        new Chart(paymentCtx, {
          type: 'doughnut',
          data: { labels: ['Paid', 'Partial', 'Unpaid'], datasets: [{ data: [paymentCounts.paid, paymentCounts.partial, paymentCounts.unpaid], backgroundColor: ['#2b8462','#e4a934','#c96363'], borderColor: '#fff', borderWidth: 3 }] },
          options: { responsive: true, maintainAspectRatio: false, cutout: '70%', plugins: { legend: { position: 'bottom', labels: { usePointStyle: true, pointStyle: 'circle', boxWidth: 8 } } } }
        });
      }

      const escapeHtml = value => String(value ?? '').replace(/[&<>'"]/g, char => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#039;', '"': '&quot;' }[char]));
      const formatDate = value => {
        const date = new Date(`${value}T00:00:00`);
        return Number.isNaN(date.getTime()) ? String(value || '-') : date.toLocaleDateString('en-PH', { month: 'short', day: 'numeric', year: 'numeric' });
      };
      const formatMoney = value => '₱' + Number(value || 0).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
      const closeCalendarModal = () => {
        const modal = document.getElementById('hoCalendarBookingModal');
        modal?.classList.remove('open');
        modal?.setAttribute('aria-hidden', 'true');
      };

      const bookingIcons = {
        guest: '<svg viewBox="0 0 24 24"><path d="M20 21a8 8 0 0 0-16 0M12 13a5 5 0 1 0 0-10 5 5 0 0 0 0 10Z"/></svg>',
        calendar: '<svg viewBox="0 0 24 24"><path d="M6 2v4m12-4v4M3 9h18M5 4h14a2 2 0 0 1 2 2v15H3V6a2 2 0 0 1 2-2Z"/></svg>',
        room: '<svg viewBox="0 0 24 24"><path d="M3 20V5h18v15M3 15h18M7 10h4v5H7zM3 20h18"/></svg>',
        payment: '<svg viewBox="0 0 24 24"><path d="M3 6h18v12H3zM3 10h18M7 15h3"/></svg>',
        note: '<svg viewBox="0 0 24 24"><path d="M5 3h14v18H5zM8 8h8M8 12h8M8 16h5"/></svg>'
      };
      const detailRow = (icon, label, value) => `<div class="bd-detail-row"><span class="bd-row-icon">${bookingIcons[icon] || ''}</span><div><small>${escapeHtml(label)}</small><strong>${escapeHtml(value || '-')}</strong></div></div>`;

      async function openBookingDetails(bookingId) {
        const modal = document.getElementById('bookingDetailsModal');
        const content = document.getElementById('bookingDetailsContent');
        if (!modal || !content || !bookingId) return;
        closeCalendarModal();
        modal.classList.add('show');
        modal.setAttribute('aria-hidden', 'false');
        document.body.classList.add('booking-details-open');
        content.innerHTML = '<div class="booking-drawer-loading"><span></span><p>Loading booking details…</p></div>';
        try {
          const response = await fetch(`Hohome.php?ho_action=booking_details&booking_id=${encodeURIComponent(bookingId)}`, { headers: { Accept: 'application/json' }, cache: 'no-store' });
          const payload = await response.json();
          if (!response.ok || !payload.success || !payload.booking) throw new Error(payload.message || 'Booking details could not be loaded.');
          const booking = payload.booking;
          const guestName = `${booking.first_name || ''} ${booking.last_name || ''}`.trim() || 'Guest';
          const initials = guestName.split(/\s+/).slice(0, 2).map(part => part.charAt(0)).join('').toUpperCase();
          const totalGuests = Number(booking.guest_count || 0);
          const status = String(booking.booking_status || 'pending');
          const paymentStatus = String(booking.payment_status || 'unpaid').replace(/_/g, ' ');
          content.innerHTML = `
            <div class="bd-drawer-hero"><div class="bd-reference"><span>BOOKING REFERENCE</span><strong>${escapeHtml(booking.booking_reference || booking.hotel_booking_id)}</strong></div><span class="bd-status-pill">${escapeHtml(status)}</span></div>
            <section class="bd-drawer-section bd-guest-card"><div class="bd-guest-avatar">${escapeHtml(initials || 'G')}</div><div class="bd-guest-primary"><small>PRIMARY GUEST</small><h4>${escapeHtml(guestName)}</h4><p>${escapeHtml(booking.email || '-')}</p></div></section>
            <section class="bd-drawer-section"><div class="bd-section-title"><span>${bookingIcons.guest}</span><div><h4>Guest information</h4><p>Contact and party details for this reservation</p></div></div><div class="bd-detail-grid">
              ${detailRow('guest', 'Contact number', booking.phone_number)}
              ${detailRow('guest', 'Guest party', `${totalGuests} total · ${Number(booking.adults || 0)} adult(s), ${Number(booking.children || 0)} child(ren)`)}
            </div></section>
            <section class="bd-drawer-section"><div class="bd-section-title"><span>${bookingIcons.calendar}</span><div><h4>Stay information</h4><p>Room assignment and reservation schedule</p></div></div><div class="bd-detail-grid">
              ${detailRow('room', 'Room type', booking.room_type)}
              ${detailRow('room', 'Rooms booked', `${Number(booking.rooms_booked || 1)} room(s)`)}
              ${detailRow('calendar', 'Check-in', formatDate(booking.checkin_date))}
              ${detailRow('calendar', 'Check-out', formatDate(booking.checkout_date))}
              ${detailRow('calendar', 'Length of stay', `${Number(booking.nights || 0)} night(s)`)}
              ${detailRow('note', 'Special request', booking.special_request || 'No special request')}
            </div></section>
            <section class="bd-drawer-section bd-payment-card"><div class="bd-section-title"><span>${bookingIcons.payment}</span><div><h4>Payment summary</h4><p>Current collection position for this booking</p></div></div><div class="bd-payment-lines">
              <div><span>Total amount</span><strong>${formatMoney(booking.total_amount)}</strong></div>
              <div><span>Amount received</span><strong>${formatMoney(booking.amount_paid)}</strong></div>
              <div class="bd-grand-total"><span>Remaining balance</span><strong>${formatMoney(booking.remaining_balance)}</strong></div>
            </div><div class="bd-payment-stats"><div><small>PAYMENT TYPE</small><strong>${escapeHtml(booking.payment_type || '-')}</strong></div><div><small>PAYMENT STATUS</small><strong class="bd-payment-${escapeHtml(paymentStatus.toLowerCase())}">${escapeHtml(paymentStatus)}</strong></div></div></section>
            <div class="bd-confidence-note"><span>${bookingIcons.room}</span><p><strong>Property booking record</strong>These details are loaded securely from the current hotel's booking records.</p></div>`;
        } catch (error) {
          content.innerHTML = `<div class="booking-drawer-error"><span>!</span><h4>Unable to load booking details</h4><p>${escapeHtml(error.message)}</p><button type="button" data-retry-booking="${Number(bookingId)}">Try Again</button></div>`;
        }
      }
      const closeBookingDetails = () => {
        const modal = document.getElementById('bookingDetailsModal');
        modal?.classList.remove('show');
        modal?.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('booking-details-open');
      };
      document.addEventListener('click', event => {
        const trigger = event.target.closest('[data-booking-details]');
        if (trigger) openBookingDetails(Number(trigger.dataset.bookingDetails || 0));
        const retry = event.target.closest('[data-retry-booking]');
        if (retry) openBookingDetails(Number(retry.dataset.retryBooking || 0));
      });
      document.getElementById('bookingDetailsClose')?.addEventListener('click', closeBookingDetails);
      document.getElementById('bookingDetailsFooterClose')?.addEventListener('click', closeBookingDetails);
      document.getElementById('bookingDetailsModal')?.addEventListener('mousedown', event => { if (event.target.id === 'bookingDetailsModal') closeBookingDetails(); });

      const calBookingModal = document.getElementById('hoCalendarBookingModal');
      const calBookingTitle = document.getElementById('hoCalendarBookingTitle');
      const calBookingBody = document.getElementById('hoCalendarBookingBody');
      const calBookingClose = document.getElementById('hoCalendarBookingClose');
      const miniCal = document.getElementById('hoMiniCalendar');
      const monthLabel = document.getElementById('hoCalendarMonthLabel');
      if (miniCal) {
        const now = new Date();
        const year = <?= (int)$calendarDisplayYear ?>;
        const month = <?= (int)$calendarDisplayMonth ?> - 1;
        const goToCalendarMonth = offset => {
          const target = new Date(year, month + offset, 1);
          const url = new URL(window.location.href);
          url.searchParams.set('calendar_year', String(target.getFullYear()));
          url.searchParams.set('calendar_month', String(target.getMonth() + 1));
          window.location.assign(url.toString());
        };
        document.getElementById('hoCalendarPrevious')?.addEventListener('click', () => goToCalendarMonth(-1));
        document.getElementById('hoCalendarNext')?.addEventListener('click', () => goToCalendarMonth(1));
        document.getElementById('hoCalendarToday')?.addEventListener('click', () => {
          const url = new URL(window.location.href);
          url.searchParams.set('calendar_year', String(now.getFullYear()));
          url.searchParams.set('calendar_month', String(now.getMonth() + 1));
          window.location.assign(url.toString());
        });
        const firstDay = new Date(year, month, 1);
        const lastDay = new Date(year, month + 1, 0);
        const startWeekDay = firstDay.getDay();
        const totalDays = lastDay.getDate();
        const labels = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
        const makeDateKey = (dayNum) => `${year}-${String(month + 1).padStart(2, '0')}-${String(dayNum).padStart(2, '0')}`;
        if (monthLabel) {
          monthLabel.textContent = new Intl.DateTimeFormat('en-US', { month: 'long', year: 'numeric' }).format(new Date(year, month, 1));
        }

        let html = '<thead><tr>' + labels.map(d => `<th>${d}</th>`).join('') + '</tr></thead><tbody><tr>';
        for (let i = 0; i < startWeekDay; i++) html += '<td></td>';
        for (let day = 1; day <= totalDays; day++) {
          const weekday = (startWeekDay + day - 1) % 7;
          const isToday = day === now.getDate() && month === now.getMonth() && year === now.getFullYear();
          const dateKey = makeDateKey(day);
          const items = acceptedByDate[dateKey] || [];
          const hasBookings = items.length > 0;
          const classes = `${isToday ? 'today ' : ''}${hasBookings ? 'has-bookings' : ''}`.trim();
          const confirmed = items.filter(item => String(item.status).toLowerCase() === 'confirmed').length;
          const pending = items.length - confirmed;
          html += hasBookings
            ? `<td class="${classes}"><button type="button" class="ho-cal-day-btn" data-date="${dateKey}" aria-label="${day}, ${items.length} scheduled booking${items.length === 1 ? '' : 's'}"><span class="ho-cal-day-number">${day}</span><span class="ho-cal-booking-pill"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7 3v3m10-3v3M4 9h16M5 5h14a2 2 0 0 1 2 2v14H3V7a2 2 0 0 1 2-2Zm5 8h4"/></svg><strong>${items.length}</strong> booking${items.length === 1 ? '' : 's'}</span><span class="ho-cal-status-dots">${confirmed ? '<i class="confirmed"></i>' : ''}${pending ? '<i class="pending"></i>' : ''}</span></button></td>`
            : `<td class="${classes}"><span class="ho-cal-empty-day">${day}</span></td>`;
          if (weekday === 6 && day !== totalDays) html += '</tr><tr>';
        }
        const endWeekday = (startWeekDay + totalDays - 1) % 7;
        for (let i = endWeekday + 1; i <= 6; i++) html += '<td></td>';
        html += '</tr></tbody>';
        miniCal.innerHTML = html;

        miniCal.querySelectorAll('.ho-cal-day-btn').forEach((btn) => {
          btn.addEventListener('click', () => {
            const selectedDate = btn.getAttribute('data-date');
            const items = selectedDate ? (acceptedByDate[selectedDate] || []) : [];
            if (!selectedDate || !items.length || !calBookingModal || !calBookingTitle || !calBookingBody) return;

            const prettyDate = new Date(`${selectedDate}T00:00:00`).toLocaleDateString('en-US', {
              year: 'numeric',
              month: 'long',
              day: 'numeric'
            });

            calBookingModal.classList.add('open');
            calBookingTitle.textContent = `Scheduled Check-ins — ${prettyDate}`;
            calBookingBody.innerHTML = `<ul class="ho-calendar-booking-list">${items.map((item, index) => {
              const initials = String(item.guest || 'Guest').split(/\s+/).filter(Boolean).slice(0, 2).map(part => part[0]).join('').toUpperCase();
              return `
              <li>
                <div class="ho-calendar-booking-top"><span class="ho-calendar-type"><i></i> Hotel room</span><small>Booking ${index + 1} of ${items.length}</small></div>
                <div class="ho-calendar-booking-main">
                  <div class="ho-calendar-guest"><span class="ho-calendar-guest-avatar">${escapeHtml(initials || 'G')}</span><div><strong>${escapeHtml(item.guest)}</strong><small>${Number(item.guest_count)} guest${Number(item.guest_count) === 1 ? '' : 's'} · ${formatDate(item.checkin)} – ${formatDate(item.checkout)}</small></div></div>
                  <div class="ho-calendar-room"><small>ROOM / ACCOMMODATION</small><strong>${escapeHtml(item.room_type)}</strong><span class="ho-calendar-status ${escapeHtml(String(item.status).toLowerCase())}">${escapeHtml(item.status)}</span></div>
                  <button type="button" class="ho-view-booking-btn" data-booking-details="${Number(item.id)}">View details <span>→</span></button>
                </div>
              </li>
            `}).join('')}</ul>`;
            calBookingModal.setAttribute('aria-hidden', 'false');
            calBookingClose?.focus();
          });
        });
      }

      if (calBookingClose && calBookingModal) {
        calBookingClose.addEventListener('click', closeCalendarModal);
        calBookingModal.addEventListener('click', (e) => {
          if (e.target === calBookingModal) closeCalendarModal();
        });
      }
      document.addEventListener('keydown', event => {
        if (event.key !== 'Escape') return;
        if (document.getElementById('bookingDetailsModal')?.classList.contains('show')) closeBookingDetails();
        else closeCalendarModal();
      });
    })();
  </script>
</body>
</html>

