<?php
declare(strict_types=1);

chdir(__DIR__ . '/..');
require_once __DIR__ . '/../php/session_security.php';
AppSessionStart();
require_once __DIR__ . '/../php/db_connection.php';
require_once __DIR__ . '/../php/admin_auth_helper.php';

AdminRequireLogin();
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

function reportScalar(PDO $pdo, string $sql, array $params = []): float {
    $statement = $pdo->prepare($sql);
    $statement->execute($params);
    return (float)$statement->fetchColumn();
}
function reportRows(PDO $pdo, string $sql, array $params = []): array {
    $statement = $pdo->prepare($sql);
    $statement->execute($params);
    return $statement->fetchAll(PDO::FETCH_ASSOC);
}
function reportMoney(float $value): string { return '₱' . number_format($value, 2); }
function reportChange(float $current, float $previous): array {
    if (abs($previous) < .001) $percent = abs($current) < .001 ? 0 : 100;
    else $percent = (($current - $previous) / abs($previous)) * 100;
    return ['value' => abs($percent), 'direction' => $percent > .05 ? 'up' : ($percent < -.05 ? 'down' : 'flat')];
}
function reportTrendSparkline(array $change): string {
    $direction = (string)($change['direction'] ?? 'flat');
    $points = match ($direction) {
        'up' => '2,25 17,19 30,21 45,11 58,14 74,4',
        'down' => '2,5 17,11 30,9 45,19 58,16 74,26',
        default => '2,15 17,12 30,16 45,13 58,15 74,13',
    };
    $endY = $direction === 'up' ? 4 : ($direction === 'down' ? 26 : 13);
    $arrow = $direction === 'up' ? 'M68 4h6v6' : ($direction === 'down' ? 'M68 26h6v-6' : 'M69 10l5 3-5 3');
    return '<span class="metric-zigzag ' . htmlspecialchars($direction, ENT_QUOTES, 'UTF-8') . '" aria-hidden="true"><svg viewBox="0 0 78 30"><path class="zig-guide" d="M2 28H76"/><polyline class="zig-line" points="' . $points . '"/><circle class="zig-point" cx="74" cy="' . $endY . '" r="2.4"/><path class="zig-arrow" d="' . $arrow . '"/></svg></span>';
}
function reportCsvCell(string $value): string { return preg_match('/^\s*[=+\-@]/u', $value) ? "'" . $value : $value; }

$preset = strtolower(trim((string)($_GET['range'] ?? '30d')));
$allowedPresets = ['7d', '30d', '90d', 'this_year', 'custom'];
if (!in_array($preset, $allowedPresets, true)) $preset = '30d';
$today = new DateTimeImmutable('today');
$end = $today;
$start = match ($preset) {
    '7d' => $today->modify('-6 days'),
    '90d' => $today->modify('-89 days'),
    'this_year' => $today->setDate((int)$today->format('Y'), 1, 1),
    'custom' => DateTimeImmutable::createFromFormat('!Y-m-d', (string)($_GET['from'] ?? '')) ?: $today->modify('-29 days'),
    default => $today->modify('-29 days'),
};
if ($preset === 'custom') $end = DateTimeImmutable::createFromFormat('!Y-m-d', (string)($_GET['to'] ?? '')) ?: $today;
if ($start > $end) [$start, $end] = [$end, $start];
if ($end > $today) $end = $today;
if ($start < $today->modify('-5 years')) $start = $today->modify('-5 years');

$type = strtolower(trim((string)($_GET['type'] ?? 'all')));
if (!in_array($type, ['all', 'package', 'boat', 'tourguide', 'hotel'], true)) $type = 'all';
$hotelScope = strtolower(trim((string)($_GET['hotels'] ?? 'exclude')));
if (!in_array($hotelScope, ['include', 'exclude'], true)) $hotelScope = 'exclude';
if ($type === 'hotel') {
    $hotelScope = 'include';
} elseif ($type !== 'all') {
    $hotelScope = 'exclude';
}
$includeHotels = $hotelScope === 'include';
$startSql = $start->format('Y-m-d');
$endSql = $end->format('Y-m-d');
$days = max(1, (int)$start->diff($end)->days + 1);
$previousEnd = $start->modify('-1 day');
$previousStart = $previousEnd->modify('-' . ($days - 1) . ' days');

$bookingTypeSql = in_array($type, ['all', 'hotel'], true) ? ($type === 'hotel' ? ' AND 1=0' : '') : ' AND LOWER(b.booking_type) = :booking_type';
$bookingParams = [':date_from' => $startSql, ':date_to' => $endSql];
if (!in_array($type, ['all', 'hotel'], true)) $bookingParams[':booking_type'] = $type;
$previousParams = [':date_from' => $previousStart->format('Y-m-d'), ':date_to' => $previousEnd->format('Y-m-d')];
if (!in_array($type, ['all', 'hotel'], true)) $previousParams[':booking_type'] = $type;

$bookingBase = " FROM bookings b WHERE DATE(b.created_at) BETWEEN :date_from AND :date_to{$bookingTypeSql}";
$totalBookings = (int)reportScalar($pdo, 'SELECT COUNT(*)' . $bookingBase, $bookingParams);
$previousBookings = (int)reportScalar($pdo, 'SELECT COUNT(*)' . $bookingBase, $previousParams);
$completedBookings = (int)reportScalar($pdo, "SELECT COUNT(*){$bookingBase} AND LOWER(COALESCE(b.is_complete,''))='completed'", $bookingParams);
$previousCompleted = (int)reportScalar($pdo, "SELECT COUNT(*){$bookingBase} AND LOWER(COALESCE(b.is_complete,''))='completed'", $previousParams);
$hotelParams = [':date_from' => $startSql, ':date_to' => $endSql];
$previousHotelParams = [':date_from' => $previousStart->format('Y-m-d'), ':date_to' => $previousEnd->format('Y-m-d')];
if ($includeHotels) {
    $hotelBase = " FROM hotel_room_bookings hb WHERE DATE(hb.created_at) BETWEEN :date_from AND :date_to";
    $totalBookings += (int)reportScalar($pdo, 'SELECT COUNT(*)' . $hotelBase, $hotelParams);
    $previousBookings += (int)reportScalar($pdo, 'SELECT COUNT(*)' . $hotelBase, $previousHotelParams);
    $completedBookings += (int)reportScalar($pdo, "SELECT COUNT(*){$hotelBase} AND LOWER(COALESCE(hb.booking_status,''))='completed'", $hotelParams);
    $previousCompleted += (int)reportScalar($pdo, "SELECT COUNT(*){$hotelBase} AND LOWER(COALESCE(hb.booking_status,''))='completed'", $previousHotelParams);
}
$visitorIds = array_column(reportRows($pdo, 'SELECT DISTINCT b.tourist_id' . $bookingBase, $bookingParams), 'tourist_id');
$previousVisitorIds = array_column(reportRows($pdo, 'SELECT DISTINCT b.tourist_id' . $bookingBase, $previousParams), 'tourist_id');
if ($includeHotels) {
    $visitorIds = array_merge($visitorIds, array_column(reportRows($pdo, 'SELECT DISTINCT hb.tourist_id' . $hotelBase, $hotelParams), 'tourist_id'));
    $previousVisitorIds = array_merge($previousVisitorIds, array_column(reportRows($pdo, 'SELECT DISTINCT hb.tourist_id' . $hotelBase, $previousHotelParams), 'tourist_id'));
}
$uniqueVisitors = count(array_unique(array_filter(array_map('intval', $visitorIds))));
$previousVisitors = count(array_unique(array_filter(array_map('intval', $previousVisitorIds))));
$completionRate = $totalBookings ? ($completedBookings / $totalBookings) * 100 : 0;
$previousCompletionRate = $previousBookings ? ($previousCompleted / $previousBookings) * 100 : 0;

$paymentDomains = $type === 'all' ? ['package','boat','tourguide'] : ($type === 'hotel' ? [] : [$type]);
if ($includeHotels) $paymentDomains[] = 'hotel';
$paymentPlaceholders = implode(',', array_fill(0, count($paymentDomains), '?'));
$paymentTypeSql = " AND LOWER(pt.booking_domain) IN ({$paymentPlaceholders})";
$paymentParams = array_merge([$startSql, $endSql], $paymentDomains);
$previousPaymentParams = array_merge([$previousStart->format('Y-m-d'), $previousEnd->format('Y-m-d')], $paymentDomains);
$revenueSql = " FROM payment_transactions pt WHERE DATE(COALESCE(pt.paid_at,pt.created_at)) BETWEEN ? AND ? AND LOWER(pt.status) IN ('paid','succeeded','completed'){$paymentTypeSql}";
$revenue = reportScalar($pdo, 'SELECT COALESCE(SUM(pt.amount_minor),0)/100' . $revenueSql, $paymentParams);
$previousRevenue = reportScalar($pdo, 'SELECT COALESCE(SUM(pt.amount_minor),0)/100' . $revenueSql, $previousPaymentParams);

$bookingChange = reportChange($totalBookings, $previousBookings);
$revenueChange = reportChange($revenue, $previousRevenue);
$visitorChange = reportChange($uniqueVisitors, $previousVisitors);
$completionChange = reportChange($completionRate, $previousCompletionRate);

$useMonthly = $days > 45;
$groupBooking = $useMonthly ? "DATE_FORMAT(b.created_at,'%Y-%m')" : 'DATE(b.created_at)';
$groupPayment = $useMonthly ? "DATE_FORMAT(COALESCE(pt.paid_at,pt.created_at),'%Y-%m')" : 'DATE(COALESCE(pt.paid_at,pt.created_at))';
$bookingTrendRows = reportRows($pdo, "SELECT {$groupBooking} period, COUNT(*) total FROM bookings b WHERE DATE(b.created_at) BETWEEN :date_from AND :date_to{$bookingTypeSql} GROUP BY period ORDER BY period", $bookingParams);
$revenueTrendRows = reportRows($pdo, "SELECT {$groupPayment} period, COALESCE(SUM(pt.amount_minor),0)/100 total FROM payment_transactions pt WHERE DATE(COALESCE(pt.paid_at,pt.created_at)) BETWEEN ? AND ? AND LOWER(pt.status) IN ('paid','succeeded','completed'){$paymentTypeSql} GROUP BY period ORDER BY period", $paymentParams);
$bookingMap = []; foreach ($bookingTrendRows as $row) $bookingMap[(string)$row['period']] = (int)$row['total'];
$groupHotel = $useMonthly ? "DATE_FORMAT(hb.created_at,'%Y-%m')" : 'DATE(hb.created_at)';
if ($includeHotels) {
    $hotelTrendRows = reportRows($pdo, "SELECT {$groupHotel} period, COUNT(*) total FROM hotel_room_bookings hb WHERE DATE(hb.created_at) BETWEEN :date_from AND :date_to GROUP BY period ORDER BY period", $hotelParams);
    foreach ($hotelTrendRows as $row) $bookingMap[(string)$row['period']] = ($bookingMap[(string)$row['period']] ?? 0) + (int)$row['total'];
}
$revenueMap = []; foreach ($revenueTrendRows as $row) $revenueMap[(string)$row['period']] = (float)$row['total'];
$periodKeys = [];
if ($useMonthly) {
    $cursor = $start->modify('first day of this month'); $last = $end->modify('first day of this month');
    while ($cursor <= $last) { $periodKeys[] = $cursor->format('Y-m'); $cursor = $cursor->modify('+1 month'); }
} else {
    $cursor = $start; while ($cursor <= $end) { $periodKeys[] = $cursor->format('Y-m-d'); $cursor = $cursor->modify('+1 day'); }
}
$trendLabels = array_map(fn($key) => $useMonthly ? date('M Y', strtotime($key . '-01')) : date('M j', strtotime($key)), $periodKeys);
$trendBookings = array_map(fn($key) => $bookingMap[$key] ?? 0, $periodKeys);
$trendRevenue = array_map(fn($key) => $revenueMap[$key] ?? 0, $periodKeys);

$typeRows = reportRows($pdo, "SELECT LOWER(COALESCE(b.booking_type,'other')) label, COUNT(*) total{$bookingBase} GROUP BY label", $bookingParams);
$typeData = ['package' => 0, 'boat' => 0, 'tourguide' => 0]; foreach ($typeRows as $row) if (isset($typeData[$row['label']])) $typeData[$row['label']] = (int)$row['total'];
if ($includeHotels) $typeData['hotel'] = (int)reportScalar($pdo, 'SELECT COUNT(*) FROM hotel_room_bookings hb WHERE DATE(hb.created_at) BETWEEN :date_from AND :date_to', $hotelParams);
$statusRows = reportRows($pdo, "SELECT CASE WHEN LOWER(COALESCE(b.is_complete,''))='completed' THEN 'Completed' WHEN LOWER(COALESCE(b.is_complete,'')) IN ('cancelled','declined') OR LOWER(COALESCE(b.status,''))='declined' THEN 'Cancelled / declined' WHEN LOWER(COALESCE(b.status,''))='accepted' THEN 'Confirmed' ELSE 'Pending' END label, COUNT(*) total{$bookingBase} GROUP BY label ORDER BY total DESC", $bookingParams);
$destinationRows = reportRows($pdo, "SELECT COALESCE(NULLIF(TRIM(b.location),''),'Not specified') label, COUNT(*) bookings{$bookingBase} GROUP BY label ORDER BY bookings DESC LIMIT 6", $bookingParams);
$serviceRows = reportRows($pdo, "SELECT COALESCE(NULLIF(TRIM(b.package_name),''),NULLIF(TRIM(b.preferred_resource),''),NULLIF(TRIM(b.location),''),'Unspecified service') service, LOWER(COALESCE(b.booking_type,'other')) type, COUNT(*) bookings, COALESCE(SUM(b.grand_total),0) booking_value, SUM(CASE WHEN LOWER(COALESCE(b.is_complete,''))='completed' THEN 1 ELSE 0 END) completed{$bookingBase} GROUP BY service,type ORDER BY bookings DESC,booking_value DESC LIMIT 7", $bookingParams);
if ($includeHotels) {
    $hotelStatuses = reportRows($pdo, "SELECT CASE WHEN LOWER(COALESCE(hb.booking_status,''))='completed' THEN 'Completed' WHEN LOWER(COALESCE(hb.booking_status,'')) IN ('cancelled','no-show','declined') THEN 'Cancelled / declined' WHEN LOWER(COALESCE(hb.booking_status,'')) IN ('confirmed','checked-in') THEN 'Confirmed' ELSE 'Pending' END label, COUNT(*) total FROM hotel_room_bookings hb WHERE DATE(hb.created_at) BETWEEN :date_from AND :date_to GROUP BY label", $hotelParams);
    $statusMap=[]; foreach(array_merge($statusRows,$hotelStatuses) as $row)$statusMap[(string)$row['label']]=($statusMap[(string)$row['label']]??0)+(int)$row['total'];
    $statusRows=[]; foreach($statusMap as $label=>$total)$statusRows[]=['label'=>$label,'total'=>$total]; usort($statusRows,fn($a,$b)=>$b['total']<=>$a['total']);
    $hotelDestinations = reportRows($pdo, "SELECT COALESCE(NULLIF(TRIM(hr.island),''),NULLIF(TRIM(hr.name),''),'Not specified') label, COUNT(*) bookings FROM hotel_room_bookings hb LEFT JOIN hotel_resorts hr ON hr.hotel_resort_id=hb.hotel_resort_id WHERE DATE(hb.created_at) BETWEEN :date_from AND :date_to GROUP BY label", $hotelParams);
    $destinationMap=[]; foreach(array_merge($destinationRows,$hotelDestinations) as $row)$destinationMap[(string)$row['label']]=($destinationMap[(string)$row['label']]??0)+(int)$row['bookings']; arsort($destinationMap);
    $destinationRows=[]; foreach(array_slice($destinationMap,0,6,true) as $label=>$bookings)$destinationRows[]=['label'=>$label,'bookings'=>$bookings];
    $hotelServices = reportRows($pdo, "SELECT COALESCE(NULLIF(TRIM(hr.name),''),'Hotel / resort stay') service, 'hotel' type, COUNT(*) bookings, COALESCE(SUM(hb.total_amount),0) booking_value, SUM(CASE WHEN LOWER(COALESCE(hb.booking_status,''))='completed' THEN 1 ELSE 0 END) completed FROM hotel_room_bookings hb LEFT JOIN hotel_resorts hr ON hr.hotel_resort_id=hb.hotel_resort_id WHERE DATE(hb.created_at) BETWEEN :date_from AND :date_to GROUP BY service", $hotelParams);
    $serviceRows=array_merge($serviceRows,$hotelServices); usort($serviceRows,fn($a,$b)=>(int)$b['bookings']<=>(int)$a['bookings'] ?: (float)$b['booking_value']<=>(float)$a['booking_value']); $serviceRows=array_slice($serviceRows,0,7);
}
$paymentMethods = reportRows($pdo, "SELECT COALESCE(NULLIF(LOWER(pt.payment_method_type),''),'other') label, COALESCE(SUM(pt.amount_minor),0)/100 total{$revenueSql} GROUP BY label ORDER BY total DESC", $paymentParams);

if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="itour-analytics-' . $startSql . '-to-' . $endSql . '.csv"');
    $out = fopen('php://output', 'wb');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['iTour Mercedes Reports & Analytics']);
    fputcsv($out, ['Period', $startSql . ' to ' . $endSql]);
    fputcsv($out, ['Booking type', $type === 'all' ? 'All booking types' : ucfirst($type)]);
    fputcsv($out, ['Hotel / resort data', $includeHotels ? 'Included' : 'Excluded']);
    fputcsv($out, []);
    fputcsv($out, ['Metric', 'Value']);
    fputcsv($out, ['Total bookings', $totalBookings]); fputcsv($out, ['Revenue collected', number_format($revenue, 2, '.', '')]);
    fputcsv($out, ['Unique tourists', $uniqueVisitors]); fputcsv($out, ['Completion rate', number_format($completionRate, 1) . '%']);
    fputcsv($out, []); fputcsv($out, ['Top service', 'Type', 'Bookings', 'Booking value', 'Completed']);
    foreach ($serviceRows as $row) fputcsv($out, [reportCsvCell((string)$row['service']), ucfirst((string)$row['type']), $row['bookings'], $row['booking_value'], $row['completed']]);
    fclose($out); exit;
}

$dateLabel = $start->format('M j, Y') . ' – ' . $end->format('M j, Y');
$activeFilterCount = ($type !== 'all' ? 1 : 0) + ($preset === 'custom' ? 1 : 0) + ($includeHotels ? 1 : 0);
$bestDestination = $destinationRows[0]['label'] ?? 'No data yet';
$bestDestinationCount = (int)($destinationRows[0]['bookings'] ?? 0);
$tourBookingValue = reportScalar($pdo, 'SELECT COALESCE(SUM(b.grand_total),0)' . $bookingBase, $bookingParams);
$hotelBookingValue = $includeHotels ? reportScalar($pdo, 'SELECT COALESCE(SUM(hb.total_amount),0)' . $hotelBase, $hotelParams) : 0;
$averageBookingValue = $totalBookings ? ($tourBookingValue + $hotelBookingValue) / $totalBookings : 0;
$cancellationCount = 0; foreach ($statusRows as $row) if ($row['label'] === 'Cancelled / declined') $cancellationCount = (int)$row['total'];
$cancellationRate = $totalBookings ? ($cancellationCount / $totalBookings) * 100 : 0;
$isDirectAdminRoute = str_contains(str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? '')), '/admin/');
$tourismReportEndpoint = $isDirectAdminRoute ? 'tourism_report.php' : 'admin/tourism_report.php';
$tourismReportBaseQuery = ['from' => $startSql, 'to' => $endSql];
$tourismReportPreviewUrl = $tourismReportEndpoint . '?' . http_build_query($tourismReportBaseQuery + ['format' => 'pdf', 'disposition' => 'inline']);
$tourismReportPdfUrl = $tourismReportEndpoint . '?' . http_build_query($tourismReportBaseQuery + ['format' => 'pdf', 'disposition' => 'download']);
$tourismReportExcelUrl = $tourismReportEndpoint . '?' . http_build_query($tourismReportBaseQuery + ['format' => 'xlsx']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Reports & Analytics | iTour Mercedes Admin</title>
  <link rel="icon" type="image/png" href="img/newlogo.png">
  <link rel="stylesheet" href="styles/admin_panel_theme.css">
<link rel="stylesheet" href="styles/adreportsanalytics.css?v=12">
  <link rel="stylesheet" href="styles/adreportsanalytics-tourism-fixes.css?v=3">
</head>
<body>
<div class="admin-container">
  <?php include __DIR__ . '/admin_sidebar.php'; ?>
  <main class="main-content reports-main">
    <header class="admin-header admin-page-header">
      <div class="admin-header-left admin-page-title">
        <span class="admin-page-title-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M4 20V10M10 20V4M16 20v-7M22 20H2"/></svg></span>
        <div class="admin-page-title-copy"><h2>Reports & Analytics</h2><p class="admin-header-subtitle">Tourism performance, revenue, and operational insights</p></div>
      </div>
      <div class="admin-header-right reports-header-actions">
        <button class="tourism-report-button" type="button" id="tourismReportButton"><svg viewBox="0 0 24 24"><path d="M5 20V7h14v13M8 7V4h8v3M8 11h3M8 15h3M14 11h2M14 15h2"/></svg><span>Tourism report</span></button>
        <a class="report-export-button" href="?<?= htmlspecialchars(http_build_query(array_merge($_GET, ['export' => 'csv']))) ?>"><svg viewBox="0 0 24 24"><path d="M12 3v12m0 0 4-4m-4 4-4-4M4 17v3h16v-3"/></svg> Export CSV</a>
      </div>
    </header>

    <section class="reports-workspace">
      <form class="report-filters" method="get" id="reportFilters">
        <div class="filter-toolbar-top">
          <div class="filter-section-title"><span>REPORT PERIOD</span><strong>Choose a reporting window</strong></div>
          <div class="quick-ranges" aria-label="Date range presets">
            <?php foreach (['7d'=>'7 days','30d'=>'30 days','90d'=>'90 days','this_year'=>'This year'] as $key=>$label): ?><button type="button" data-range="<?= $key ?>" class="<?= $preset === $key ? 'active' : '' ?>"><?= $label ?></button><?php endforeach; ?>
          </div>
          <button class="report-details-button toolbar-details-button" type="button" id="openDetailedReport"><svg viewBox="0 0 24 24"><path d="M4 5h16v14H4zM8 9h8M8 13h5"/></svg><span>Detailed report</span></button>
          <div class="scope-summary"><span class="live-indicator"><i></i> LIVE DATA</span><b><?= $includeHotels ? 'Tours + Accommodations' : 'Tour Operations' ?></b><small>Updated <?= date('g:i A') ?></small></div>
        </div>
        <div class="filter-toolbar-fields">
          <input type="hidden" name="range" id="rangeInput" value="<?= htmlspecialchars($preset) ?>">
          <div class="date-field-group">
            <label class="filter-field"><span>From date</span><input type="date" name="from" value="<?= $startSql ?>" max="<?= $today->format('Y-m-d') ?>"></label>
            <span class="date-separator">—</span>
            <label class="filter-field"><span>To date</span><input type="date" name="to" value="<?= $endSql ?>" max="<?= $today->format('Y-m-d') ?>"></label>
          </div>
          <label class="filter-field type-filter"><span>Booking category</span><select name="type" id="bookingTypeFilter"><option value="all">All booking types</option><option value="package" <?= $type==='package'?'selected':'' ?>>Tour packages</option><option value="boat" <?= $type==='boat'?'selected':'' ?>>Boats</option><option value="tourguide" <?= $type==='tourguide'?'selected':'' ?>>Tour guides</option><option value="hotel" <?= $type==='hotel'?'selected':'' ?>>Hotels & resorts</option></select></label>
          <label class="hotel-scope"><input type="checkbox" name="hotels" id="hotelScopeToggle" value="include" <?= $includeHotels?'checked':'' ?>><span><i></i></span><b>Include hotels & resorts<small>Combine accommodation performance</small></b></label>
          <div class="filter-actions"><button class="apply-filter" type="submit"><svg viewBox="0 0 24 24"><path d="M4 5h16M7 12h10M10 19h4"/></svg>Apply filters</button><?php if ($activeFilterCount): ?><a class="clear-filter" href="adreportsanalytics.php">Reset</a><?php endif; ?></div>
        </div>
      </form>

      <section class="metric-grid" aria-label="Key performance indicators">
        <?php
        $metrics = [
          ['Total Bookings', number_format($totalBookings), $bookingChange, 'calendar', 'Reservations created in this period'],
          ['Revenue Collected', reportMoney($revenue), $revenueChange, 'revenue', 'Successful payment transactions'],
          ['Unique Tourists', number_format($uniqueVisitors), $visitorChange, 'visitors', 'Distinct guests who booked'],
          ['Completion Rate', number_format($completionRate, 1).'%', $completionChange, 'completion', number_format($completedBookings).' completed bookings'],
        ];
        $metricIcons = [
          'calendar'=>'<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M8 3v4M16 3v4M3 10h18M8 14h3M8 18h6"/>',
          'revenue'=>'<path d="M12 2v20M17 6.5C17 5.1 14.8 4 12 4S7 5.1 7 6.5 9.2 9 12 9s5 1.1 5 2.5S14.8 14 12 14s-5 1.1-5 2.5S9.2 19 12 19s5-1.1 5-2.5"/>',
          'visitors'=>'<circle cx="9" cy="8" r="3"/><path d="M3 21v-2a6 6 0 0 1 12 0v2M16 5a3 3 0 0 1 0 6M17 15a5 5 0 0 1 4 5"/>',
          'completion'=>'<circle cx="12" cy="12" r="9"/><path d="m8 12 2.6 2.6L16.5 9"/>',
        ];
        foreach ($metrics as [$label,$value,$change,$icon,$note]): ?>
          <article class="metric-card <?= $icon ?>">
            <div class="metric-top"><div class="metric-heading"><span class="metric-icon"><svg viewBox="0 0 24 24"><?= $metricIcons[$icon] ?></svg></span><span class="metric-label"><?= $label ?></span></div><span class="change-pill <?= $change['direction'] ?>"><?= $change['direction']==='up'?'↑':($change['direction']==='down'?'↓':'→') ?> <?= number_format($change['value'],1) ?>%</span></div>
            <div class="metric-value-row"><strong><?= $value ?></strong><?= reportTrendSparkline($change) ?></div><small><?= $note ?> · vs previous <?= $days ?> days</small>
          </article>
        <?php endforeach; ?>
      </section>

      <section class="analytics-grid primary-grid">
        <article class="report-card performance-card">
          <header class="card-heading"><div><span class="eyebrow">PERFORMANCE TREND</span><h2>Bookings & revenue</h2><p>Demand and collected revenue across the selected period</p></div><div class="card-heading-actions"><div class="chart-legend"><span><i class="booking-dot"></i>Bookings</span><span><i class="revenue-dot"></i>Revenue</span></div><div class="chart-tools"><button type="button" data-chart-download="performanceChart" data-filename="bookings-revenue" title="Download chart" aria-label="Download bookings and revenue chart"><svg viewBox="0 0 24 24"><path d="M12 3v12m0 0 4-4m-4 4-4-4M5 20h14"/></svg></button><button type="button" data-chart-expand title="Expand chart" aria-label="Expand bookings and revenue chart"><svg viewBox="0 0 24 24"><path d="M8 3H3v5M16 3h5v5M8 21H3v-5M16 21h5v-5"/></svg></button></div></div></header>
          <div class="chart-wrap tall"><canvas id="performanceChart"></canvas><div class="chart-empty" hidden>No booking or payment activity for this period.</div></div>
        </article>
        <article class="report-card mix-card">
          <header class="card-heading"><div><span class="eyebrow">SERVICE MIX</span><h2>Bookings by type</h2><p>How guests are booking services</p></div><div class="chart-tools"><button type="button" data-chart-download="typeChart" data-filename="booking-service-mix" title="Download chart" aria-label="Download service mix chart"><svg viewBox="0 0 24 24"><path d="M12 3v12m0 0 4-4m-4 4-4-4M5 20h14"/></svg></button><button type="button" data-chart-expand title="Expand chart" aria-label="Expand service mix chart"><svg viewBox="0 0 24 24"><path d="M8 3H3v5M16 3h5v5M8 21H3v-5M16 21h5v-5"/></svg></button></div></header>
          <div class="donut-wrap"><canvas id="typeChart"></canvas><div class="donut-center"><strong><?= number_format(array_sum($typeData)) ?></strong><span>bookings</span></div></div>
          <div class="mix-list"><?php foreach (['package'=>'Tour packages','boat'=>'Boat rentals','tourguide'=>'Tour guides','hotel'=>'Hotel stays'] as $key=>$label): if(!array_key_exists($key,$typeData))continue; $pct=array_sum($typeData)?($typeData[$key]/array_sum($typeData))*100:0; ?><div><span><i class="<?= $key ?>"></i><?= $label ?></span><strong><?= $typeData[$key] ?> <small><?= number_format($pct,0) ?>%</small></strong></div><?php endforeach; ?></div>
        </article>
      </section>

      <section class="analytics-grid secondary-grid">
        <article class="report-card destination-card">
          <header class="card-heading"><div><span class="eyebrow">DESTINATION DEMAND</span><h2>Most booked locations</h2><p>Top destinations by reservation volume</p></div><div class="chart-tools"><button type="button" data-chart-download="destinationChart" data-filename="destination-demand" title="Download chart" aria-label="Download destination demand chart"><svg viewBox="0 0 24 24"><path d="M12 3v12m0 0 4-4m-4 4-4-4M5 20h14"/></svg></button><button type="button" data-chart-expand title="Expand chart" aria-label="Expand destination demand chart"><svg viewBox="0 0 24 24"><path d="M8 3H3v5M16 3h5v5M8 21H3v-5M16 21h5v-5"/></svg></button></div></header>
          <div class="chart-wrap"><canvas id="destinationChart"></canvas></div>
        </article>
        <article class="report-card status-card">
          <header class="card-heading"><div><span class="eyebrow">BOOKING HEALTH</span><h2>Status overview</h2><p>Current outcome of selected bookings</p></div><div class="chart-tools"><button type="button" data-chart-download="statusChart" data-filename="booking-status" title="Download chart" aria-label="Download booking status chart"><svg viewBox="0 0 24 24"><path d="M12 3v12m0 0 4-4m-4 4-4-4M5 20h14"/></svg></button><button type="button" data-chart-expand title="Expand chart" aria-label="Expand booking status chart"><svg viewBox="0 0 24 24"><path d="M8 3H3v5M16 3h5v5M8 21H3v-5M16 21h5v-5"/></svg></button></div></header>
          <div class="status-content"><div class="status-chart"><canvas id="statusChart"></canvas></div><div class="status-list" id="statusLegend"></div></div>
        </article>
        <article class="report-card insight-card">
          <header class="card-heading"><div><span class="eyebrow">AUTOMATED SUMMARY</span><h2>Operational insights</h2></div><button type="button" class="insight-details-button" data-open-details>View detailed report <span>→</span></button></header>
          <div class="insight-list">
            <div class="insight-row positive"><span>01</span><div><strong>Top destination</strong><p><?= htmlspecialchars((string)$bestDestination) ?> leads with <?= $bestDestinationCount ?> booking<?= $bestDestinationCount===1?'':'s' ?>.</p></div></div>
            <div class="insight-row"><span>02</span><div><strong>Average booking value</strong><p><?= reportMoney($averageBookingValue) ?> average value across recorded bookings.</p></div></div>
            <div class="insight-row <?= $cancellationRate > 15 ? 'warning' : 'positive' ?>"><span>03</span><div><strong>Cancellation health</strong><p><?= number_format($cancellationRate,1) ?>% cancellation or decline rate for this period.</p></div></div>
          </div>
        </article>
      </section>

      <section class="report-card services-card">
        <header class="card-heading table-heading"><div><span class="eyebrow">SERVICE PERFORMANCE</span><h2>Top performing services</h2><p>Ranked by booking volume in the selected period</p></div><span class="table-count"><?= count($serviceRows) ?> services shown</span></header>
        <div class="report-table-wrap"><table><thead><tr><th>Rank</th><th>Service</th><th>Type</th><th>Bookings</th><th>Completion</th><th>Booking value</th></tr></thead><tbody>
        <?php if (!$serviceRows): ?><tr><td colspan="6"><div class="table-empty">No service activity found for this period.</div></td></tr><?php else: foreach ($serviceRows as $index=>$row): $rate=(int)$row['bookings']?((int)$row['completed']/(int)$row['bookings'])*100:0; ?><tr><td><span class="rank <?= $index<3?'top':'' ?>"><?= $index+1 ?></span></td><td><strong class="service-name"><?= htmlspecialchars((string)$row['service']) ?></strong></td><td><span class="type-badge <?= htmlspecialchars((string)$row['type']) ?>"><?= htmlspecialchars(ucfirst((string)$row['type'])) ?></span></td><td><b><?= number_format((int)$row['bookings']) ?></b></td><td><div class="completion-cell"><span><i style="width:<?= number_format($rate,1,'.','') ?>%"></i></span><b><?= number_format($rate,0) ?>%</b></div></td><td><strong><?= reportMoney((float)$row['booking_value']) ?></strong></td></tr><?php endforeach; endif; ?>
        </tbody></table></div>
      </section>
      <footer class="reports-footer"><span>Data covers bookings created and payments collected from <?= htmlspecialchars($dateLabel) ?>.</span><button type="button" id="printReport"><svg viewBox="0 0 24 24"><path d="M6 9V3h12v6M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2M6 14h12v7H6z"/></svg>Print report</button></footer>
    </section>
  </main>
</div>
<dialog class="report-detail-dialog" id="reportDetailDialog" aria-labelledby="reportDetailTitle">
  <div class="detail-dialog-shell">
    <header><div><span class="detail-icon"><svg viewBox="0 0 24 24"><path d="M4 19V9M10 19V4M16 19v-7M21 19H3"/></svg></span><div><small>ANALYTICS DETAIL</small><h2 id="reportDetailTitle">Performance report</h2><p><?= htmlspecialchars($dateLabel) ?> · <?= $includeHotels ? 'Tours and accommodations' : 'Tour operations' ?></p></div></div><button type="button" data-close-details aria-label="Close detailed report">×</button></header>
    <div class="detail-dialog-body">
      <section class="detail-summary-grid">
        <article><span>Total bookings</span><strong><?= number_format($totalBookings) ?></strong><small><?= number_format($completedBookings) ?> completed</small></article>
        <article><span>Revenue collected</span><strong><?= reportMoney($revenue) ?></strong><small><?= reportMoney($averageBookingValue) ?> average booking value</small></article>
        <article><span>Unique tourists</span><strong><?= number_format($uniqueVisitors) ?></strong><small>Distinct guests in this period</small></article>
        <article><span>Booking health</span><strong><?= number_format($completionRate,1) ?>%</strong><small><?= number_format($cancellationRate,1) ?>% cancelled or declined</small></article>
      </section>
      <div class="detail-columns">
        <section class="detail-panel"><div class="detail-panel-title"><span>COLLECTION BREAKDOWN</span><h3>Payment channels</h3></div><div class="detail-channel-list">
          <?php if (!$paymentMethods): ?><p class="detail-empty">No successful collections in this period.</p><?php else: $paymentMaximum=max(array_map('floatval',array_column($paymentMethods,'total'))); foreach($paymentMethods as $method): $methodShare=$revenue>0?((float)$method['total']/$revenue)*100:0; ?><div><span><b><?= htmlspecialchars(ucwords(str_replace(['_','-'],' ',(string)$method['label']))) ?></b><small><?= number_format($methodShare,1) ?>% of collections</small></span><i><em style="width:<?= $paymentMaximum>0?number_format(((float)$method['total']/$paymentMaximum)*100,1,'.',''):0 ?>%"></em></i><strong><?= reportMoney((float)$method['total']) ?></strong></div><?php endforeach; endif; ?>
        </div></section>
        <section class="detail-panel"><div class="detail-panel-title"><span>TOP SERVICES</span><h3>Booking performance</h3></div><div class="detail-service-list">
          <?php if (!$serviceRows): ?><p class="detail-empty">No service activity in this period.</p><?php else: foreach(array_slice($serviceRows,0,5) as $index=>$service): ?><div><span><?= $index+1 ?></span><p><b><?= htmlspecialchars((string)$service['service']) ?></b><small><?= htmlspecialchars(ucfirst((string)$service['type'])) ?> · <?= number_format((int)$service['bookings']) ?> bookings</small></p><strong><?= reportMoney((float)$service['booking_value']) ?></strong></div><?php endforeach; endif; ?>
        </div></section>
      </div>
    </div>
    <footer><span>Generated from live booking and payment records.</span><div><button type="button" class="detail-print" id="printDetailedReport">Print report</button><button type="button" class="detail-close" data-close-details>Close</button></div></footer>
  </div>
</dialog>
<dialog class="tourism-preview-dialog" id="tourismPreviewDialog" aria-labelledby="tourismPreviewTitle">
  <div class="tourism-preview-shell">
    <header>
      <div class="tourism-preview-title"><span><svg viewBox="0 0 24 24"><path d="M5 20V7h14v13M8 7V4h8v3M8 11h3M8 15h3M14 11h2M14 15h2"/></svg></span><div><small>DEPARTMENT OF TOURISM FORMAT</small><h2 id="tourismPreviewTitle">Tourist Arrivals Report</h2><p id="tourismReportPeriodLabel"><?= htmlspecialchars($dateLabel) ?> · Completed visits by service date</p></div></div>
      <form class="tourism-preview-filter" id="tourismReportFilter" data-endpoint="<?= htmlspecialchars($tourismReportEndpoint) ?>">
        <label><span>From</span><input type="date" id="tourismReportFrom" value="<?= htmlspecialchars($startSql) ?>" max="<?= htmlspecialchars(date('Y-m-d')) ?>" required></label>
        <label><span>To</span><input type="date" id="tourismReportTo" value="<?= htmlspecialchars($endSql) ?>" max="<?= htmlspecialchars(date('Y-m-d')) ?>" required></label>
        <button type="submit"><svg viewBox="0 0 24 24"><path d="M4 6h16M7 12h10M10 18h4"/></svg>Apply</button>
      </form>
      <div class="tourism-preview-actions"><a id="tourismExcelDownload" href="<?= htmlspecialchars($tourismReportExcelUrl) ?>"><svg viewBox="0 0 24 24"><path d="M6 3h9l3 3v15H6zM14 3v4h4M9 11l4 5M13 11l-4 5"/></svg>Download Excel</a><a class="primary" id="tourismPdfDownload" href="<?= htmlspecialchars($tourismReportPdfUrl) ?>"><svg viewBox="0 0 24 24"><path d="M6 3h9l3 3v15H6zM14 3v4h4M9 12h6M9 16h4"/></svg>Download PDF</a><button type="button" data-close-tourism-preview aria-label="Close tourism report preview">×</button></div>
    </header>
    <div class="tourism-preview-loading" id="tourismPreviewLoading"><span></span><strong>Preparing official tourism report…</strong><small>Counting completed bookings by tour or check-in date</small></div>
    <iframe title="Tourist Arrivals PDF preview" data-src="<?= htmlspecialchars($tourismReportPreviewUrl) ?>"></iframe>
  </div>
</dialog>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.7/dist/chart.umd.min.js"></script>
<script>
window.reportAnalyticsData = <?= json_encode([
  'labels'=>$trendLabels,'bookings'=>$trendBookings,'revenue'=>$trendRevenue,
  'types'=>array_values($typeData),'typeLabels'=>array_values(array_intersect_key(['package'=>'Tour packages','boat'=>'Boat rentals','tourguide'=>'Tour guides','hotel'=>'Hotel stays'],$typeData)),
  'destinations'=>array_column($destinationRows,'label'),'destinationValues'=>array_map('intval',array_column($destinationRows,'bookings')),
  'statusLabels'=>array_column($statusRows,'label'),'statusValues'=>array_map('intval',array_column($statusRows,'total')),
], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) ?>;
</script>
<script src="js/adreportsanalytics.js?v=9"></script>
</body>
</html>
