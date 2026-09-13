<?php
declare(strict_types=1);

require_once __DIR__ . '/Ho_common.php';

$hoAdmin = HoRequireHotelAdmin($pdo);
$hotelId = (int)$hoAdmin['hotel_resort_id'];
$propertyName = trim((string)($hoAdmin['property_name'] ?? '')) ?: 'Assigned Property';
$ownerName = $propertyName . ' Admin';
$hoActive = 'earnings';
$hoTitle = 'Earnings & Payouts';
$hoOwnerName = $ownerName;
$hoPendingBadge = HoGetPendingCount($pdo, $hotelId);
$hoUnreadBadge = HoGetUnreadCount($pdo, $hotelId);
$hoNotifItems = HoGetNotificationItems($pdo, 8, $hotelId);
$hotelNotificationCsrf = AppCsrfToken('hotel_admin', 'notifications');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

function heMoney(float $amount): string { return '₱' . number_format($amount, 2); }
function heLabel(?string $value): string {
    $value = trim(str_replace(['_', '-'], ' ', strtolower((string)$value)));
    return $value === '' ? '—' : ucwords($value);
}
function heCsvSafe(string $value): string { return preg_match('/^\s*[=+\-@]/u', $value) ? "'" . $value : $value; }

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['ho_action'] ?? '') === 'mark_notifications_read') {
    if (!AppVerifyCsrf('hotel_admin', 'notifications', $_POST['csrf_token'] ?? null)) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['ok' => false]);
        exit;
    }
    HoMarkNotificationsRead($pdo, $hotelId);
    header('Content-Type: application/json');
    echo json_encode(['ok' => true]);
    exit;
}

/* Keep this property's read-only view connected to the administrator payout ledger. */
$pdo->exec("CREATE TABLE IF NOT EXISTS provider_payouts (
  payout_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, booking_domain VARCHAR(20) NOT NULL,
  booking_id INT NOT NULL, booking_reference VARCHAR(20) NOT NULL, provider_type VARCHAR(30) NOT NULL,
  provider_id INT NULL, provider_key VARCHAR(80) NOT NULL, provider_name VARCHAR(190) NOT NULL,
  gross_amount DECIMAL(12,2) NOT NULL DEFAULT 0, status VARCHAR(20) NOT NULL DEFAULT 'pending',
  approved_by_admin_id INT NULL, approved_at DATETIME NULL, settlement_method VARCHAR(40) NULL,
  settlement_reference VARCHAR(120) NULL, settlement_note VARCHAR(500) NULL,
  settled_by_admin_id INT NULL, settled_at DATETIME NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (payout_id), UNIQUE KEY uq_provider_booking (booking_domain,booking_id,provider_key),
  KEY idx_payout_status (status), KEY idx_payout_provider (provider_type,provider_id), KEY idx_payout_settled (settled_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$sync = $pdo->prepare("INSERT INTO provider_payouts
  (booking_domain,booking_id,booking_reference,provider_type,provider_id,provider_key,provider_name,gross_amount,status)
  SELECT 'hotel',b.hotel_booking_id,COALESCE(NULLIF(b.booking_reference,''),CONCAT('HR-',b.hotel_booking_id)),
    'hotel',b.hotel_resort_id,CONCAT('hotel:',b.hotel_resort_id),COALESCE(NULLIF(TRIM(h.name),''),'Hotel / Resort'),
    GREATEST(COALESCE(b.amount_paid,0),0),'pending'
  FROM hotel_room_bookings b LEFT JOIN hotel_resorts h ON h.hotel_resort_id=b.hotel_resort_id
  WHERE b.hotel_resort_id=? AND COALESCE(b.amount_paid,0)>0
  ON DUPLICATE KEY UPDATE
    booking_reference=IF(provider_payouts.status='pending',VALUES(booking_reference),provider_payouts.booking_reference),
    provider_name=IF(provider_payouts.status='pending',VALUES(provider_name),provider_payouts.provider_name),
    gross_amount=IF(provider_payouts.status='pending',VALUES(gross_amount),provider_payouts.gross_amount),
    updated_at=IF(provider_payouts.status='pending',NOW(),provider_payouts.updated_at)");
$sync->execute([$hotelId]);

$hasCancellations = HoTableExists($pdo, 'booking_cancellation_requests');
$cancellationSelect = $hasCancellations
    ? ",cr.request_status cancellation_status,cr.refund_status,cr.refund_policy,cr.refundable_amount,cr.non_refundable_amount"
    : ",NULL cancellation_status,NULL refund_status,NULL refund_policy,0 refundable_amount,0 non_refundable_amount";
$cancellationJoin = $hasCancellations
    ? " LEFT JOIN booking_cancellation_requests cr ON cr.cancellation_request_id=(SELECT cr2.cancellation_request_id FROM booking_cancellation_requests cr2 WHERE cr2.booking_domain='hotel' AND cr2.booking_id=b.hotel_booking_id ORDER BY cr2.cancellation_request_id DESC LIMIT 1)"
    : '';
$stmt = $pdo->prepare("SELECT b.hotel_booking_id,b.booking_reference,b.first_name,b.last_name,b.email,b.phone_number,
  b.room_type,b.checkin_date,b.checkout_date,b.nights,b.rooms_booked,b.adults,b.children,b.total_amount,b.amount_paid,
  b.remaining_balance,b.payment_status,b.payment_type,b.balance_payment_method,b.booking_status,b.created_at,
  p.payout_id,p.status ledger_status,p.gross_amount,p.approved_at,p.settlement_method,p.settlement_reference,p.settlement_note,p.settled_at
  {$cancellationSelect}
  FROM hotel_room_bookings b
  LEFT JOIN provider_payouts p ON p.booking_domain='hotel' AND p.booking_id=b.hotel_booking_id AND p.provider_id=b.hotel_resort_id
  {$cancellationJoin}
  WHERE b.hotel_resort_id=? ORDER BY b.created_at DESC");
$stmt->execute([$hotelId]);
$allRows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$totals = ['pending'=>0.0,'ready'=>0.0,'settled'=>0.0,'not_payable'=>0.0];
$counts = ['pending'=>0,'ready'=>0,'settled'=>0,'not_payable'=>0];
$grossPayments = 0.0;
foreach ($allRows as &$row) {
    $bookingStatus = strtolower(trim((string)$row['booking_status']));
    $ledgerStatus = strtolower(trim((string)($row['ledger_status'] ?? '')));
    $paid = max(0.0, (float)$row['amount_paid']);
    $grossPayments += $paid;
    $retained = min($paid, max(0.0, (float)($row['non_refundable_amount'] ?? 0)));
    $refundPolicy = strtolower((string)($row['refund_policy'] ?? ''));
    $cancelWorkflow = strtolower((string)($row['cancellation_status'] ?? ''));
    $refundStatus = strtolower((string)($row['refund_status'] ?? ''));
    $refundComplete = (float)($row['refundable_amount'] ?? 0) <= .009 || in_array($refundStatus, ['completed','refunded','succeeded','not_applicable'], true);
    $retentionApplies = $bookingStatus === 'cancelled' && in_array($refundPolicy, ['partial_refund','deposit_non_refundable'], true) && $retained > .009;
    $retentionReady = $retentionApplies && $cancelWorkflow === 'completed' && $refundComplete;

    if ($ledgerStatus === 'settled') {
        $state = 'settled';
        $amount = max(0.0, (float)$row['gross_amount']);
    } elseif (in_array($bookingStatus, ['cancelled','declined','no-show'], true)) {
        $state = $retentionReady ? 'ready' : ($retentionApplies ? 'pending' : 'not_payable');
        $amount = $retentionApplies ? $retained : 0.0;
    } elseif ($paid <= .009) {
        $state = 'not_payable';
        $amount = 0.0;
    } elseif ($bookingStatus === 'completed') {
        $state = 'ready';
        $amount = $paid;
    } else {
        $state = 'pending';
        $amount = $paid;
    }
    $row['workflow_status'] = $state;
    $row['payout_amount'] = $amount;
    $row['retention_applies'] = $retentionApplies;
    $totals[$state] += $amount;
    $counts[$state]++;
}
unset($row);

// Compare booking-month cohorts; settlement activity uses its actual settlement month.
$metricMonths = [date('Y-m'), date('Y-m', strtotime('first day of last month'))];
$metricPeriods = array_fill_keys(['earned', 'pending', 'ready', 'settled'], [0.0, 0.0]);
foreach ($allRows as $row) {
    $period = array_search(substr((string)$row['created_at'], 0, 7), $metricMonths, true);
    if ($period !== false) {
        $metricPeriods['earned'][$period] += max(0.0, (float)$row['amount_paid']);
        if (in_array($row['workflow_status'], ['pending', 'ready'], true)) {
            $metricPeriods[$row['workflow_status']][$period] += (float)$row['payout_amount'];
        }
    }
    $settledPeriod = array_search(substr((string)($row['settled_at'] ?? ''), 0, 7), $metricMonths, true);
    if ($row['workflow_status'] === 'settled' && $settledPeriod !== false) {
        $metricPeriods['settled'][$settledPeriod] += (float)$row['payout_amount'];
    }
}
function heMetricTrend(array $periods, string $metric): string {
    [$current, $previous] = $periods;
    $difference = $current - $previous;
    $direction = abs($difference) < .005 ? 'flat' : ($difference > 0 ? 'up' : 'down');
    $tone = $direction === 'flat' ? 'neutral' : ((($direction === 'up') !== ($metric === 'pending')) ? 'positive' : 'attention');
    $label = $direction === 'flat' ? '0.0%' : ($previous > .005 ? ($difference > 0 ? '+' : '-') . number_format(abs($difference) / $previous * 100, 1) . '%' : 'New');
    $points = match ($direction) {
        'up' => '2,23 15,18 28,20 43,10 56,13 70,3',
        'down' => '2,5 15,10 28,8 43,18 56,15 70,24',
        default => '2,14 70,14',
    };
    $tip = match ($direction) { 'up' => 'M64 3h6v6', 'down' => 'M64 24h6v-6', default => 'M65 11l5 3-5 3' };
    $basis = $metric === 'settled' ? 'Settled this month vs last month' : 'Current amounts for bookings created this month vs last month';
    return '<div class="he-metric-footer '.$tone.'" title="'.$basis.'"><div><span class="he-trend-badge">'.$label.'</span><span class="he-trend-caption">vs last month</span></div><svg class="he-stat-trend" viewBox="0 0 74 28" aria-hidden="true"><path class="guide" d="M2 26H70"/><polyline points="'.$points.'"/><path d="'.$tip.'"/></svg><span class="he-trend-basis">'.($metric === 'settled' ? 'By settlement date' : 'By booking month').'</span></div>';
}

$search = trim((string)($_GET['q'] ?? ''));
$statusFilter = strtolower(trim((string)($_GET['status'] ?? 'all')));
$dateFrom = trim((string)($_GET['from'] ?? ''));
$dateTo = trim((string)($_GET['to'] ?? ''));
if (!in_array($statusFilter, ['all','pending','ready','settled','not_payable'], true)) $statusFilter = 'all';
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) $dateFrom = '';
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) $dateTo = '';
$filteredRows = array_values(array_filter($allRows, static function(array $row) use ($search,$statusFilter,$dateFrom,$dateTo): bool {
    if ($statusFilter !== 'all' && $row['workflow_status'] !== $statusFilter) return false;
    $created = substr((string)$row['created_at'], 0, 10);
    if ($dateFrom !== '' && $created < $dateFrom) return false;
    if ($dateTo !== '' && $created > $dateTo) return false;
    if ($search === '') return true;
    return str_contains(strtolower(implode(' ', [$row['booking_reference'], $row['first_name'], $row['last_name'], $row['email'], $row['room_type']])), strtolower($search));
}));

if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="hotel-payout-ledger-' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'wb'); fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Booking','Guest','Stay','Booking total (PHP)','Paid (PHP)','Payout amount (PHP)','Payout status','Settlement date','Settlement method','Settlement reference']);
    foreach ($filteredRows as $row) fputcsv($out, [heCsvSafe((string)$row['booking_reference']),heCsvSafe(trim($row['first_name'].' '.$row['last_name'])),(string)$row['checkin_date'].' to '.$row['checkout_date'],number_format((float)$row['total_amount'],2,'.',''),number_format((float)$row['amount_paid'],2,'.',''),number_format((float)$row['payout_amount'],2,'.',''),heLabel((string)$row['workflow_status']),(string)$row['settled_at'],heLabel((string)$row['settlement_method']),heCsvSafe((string)$row['settlement_reference'])]);
    fclose($out); exit;
}

$page = max(1, (int)($_GET['page'] ?? 1)); $perPage = 10;
$rowCount = count($filteredRows); $totalPages = max(1, (int)ceil($rowCount / $perPage)); $page = min($page, $totalPages);
$pageRows = array_slice($filteredRows, ($page - 1) * $perPage, $perPage);
$monthKeys=[]; $monthLabels=[]; $earnedMap=[]; $settledMap=[];
for ($i=5; $i>=0; $i--) { $ts=strtotime("first day of -{$i} month"); $key=date('Y-m',$ts); $monthKeys[]=$key; $monthLabels[]=date('M',$ts); }
foreach ($allRows as $row) {
    $earnedKey=substr((string)$row['created_at'],0,7); if(in_array($earnedKey,$monthKeys,true)) $earnedMap[$earnedKey]=($earnedMap[$earnedKey]??0)+(float)$row['payout_amount'];
    if(!empty($row['settled_at'])) { $settledKey=substr((string)$row['settled_at'],0,7); if(in_array($settledKey,$monthKeys,true)) $settledMap[$settledKey]=($settledMap[$settledKey]??0)+(float)$row['payout_amount']; }
}
$earnedSeries=array_map(fn($key)=>(float)($earnedMap[$key]??0),$monthKeys);
$settledSeries=array_map(fn($key)=>(float)($settledMap[$key]??0),$monthKeys);
$maxChart=max(1.0,...$earnedSeries,...$settledSeries);
$settleBase=$totals['ready']+$totals['settled']; $settlementRate=$settleBase>0?($totals['settled']/$settleBase)*100:0;
$exportQuery=$_GET; unset($exportQuery['page']); $exportQuery['export']='csv';
$paginationQuery=$_GET; unset($paginationQuery['page'],$paginationQuery['export']);
$hoEarningsHeaderActions = true;
$hoEarningsExportUrl = '?' . http_build_query($exportQuery);
?>
<!DOCTYPE html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Earnings & Payouts | Hotel Admin</title><link rel="icon" href="img/newlogo.png"><link rel="stylesheet" href="styles/Ho_panel.css?v=notifications-4"><link rel="stylesheet" href="styles/Ho_earnings.css?v=3"></head>
<body class="ho-body"><div class="ho-layout">
<?php include __DIR__.'/Ho_sidebar.php'; ?>
<main class="ho-main"><?php include __DIR__.'/Ho_header.php'; ?>
<section class="he-workspace">
  <section class="he-metrics" aria-label="Payout summary">
    <article class="he-metric earned"><span class="he-metric-icon"><svg viewBox="0 0 24 24"><path d="M4 19V9m5 10V5m5 14v-7m5 7V3"/></svg></span><div><small>Gross booking payments</small><strong><?= heMoney($grossPayments) ?></strong><p><?= count($allRows) ?> total booking<?= count($allRows)===1?'':'s' ?> in your ledger</p></div><?= heMetricTrend($metricPeriods['earned'], 'earned') ?></article>
    <article class="he-metric pending"><span class="he-metric-icon"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg></span><div><small>Pending eligibility</small><strong><?= heMoney($totals['pending']) ?></strong><p><?= $counts['pending'] ?> awaiting stay or refund completion</p></div><?= heMetricTrend($metricPeriods['pending'], 'pending') ?></article>
    <article class="he-metric ready"><span class="he-metric-icon"><svg viewBox="0 0 24 24"><path d="m7 12 3 3 7-7"/><circle cx="12" cy="12" r="9"/></svg></span><div><small>Available for payout</small><strong><?= heMoney($totals['ready']) ?></strong><p><?= $counts['ready'] ?> completed booking<?= $counts['ready']===1?'':'s' ?> awaiting admin settlement</p></div><?= heMetricTrend($metricPeriods['ready'], 'ready') ?></article>
    <article class="he-metric settled"><span class="he-metric-icon"><svg viewBox="0 0 24 24"><path d="M4 7h16v12H4zM7 11h10M7 15h6"/></svg></span><div><small>Total settled</small><strong><?= heMoney($totals['settled']) ?></strong><p><?= $counts['settled'] ?> payout<?= $counts['settled']===1?'':'s' ?> recorded by administrators</p></div><?= heMetricTrend($metricPeriods['settled'], 'settled') ?></article>
  </section>

  <section class="he-insights">
    <article class="he-card he-chart-card"><header><div><span class="he-kicker">6-MONTH ACTIVITY</span><h3>Earnings and settlements</h3></div><div class="he-legend"><span><i class="earned"></i>Payable earnings</span><span><i class="settled"></i>Settled</span></div></header><div class="he-chart" aria-label="Six month earnings and settlement chart"><?php foreach($monthLabels as $i=>$label): ?><div class="he-chart-col"><div class="he-chart-values"><span class="earned" style="height:<?= max(2,$earnedSeries[$i]/$maxChart*100) ?>%" title="<?= htmlspecialchars($label.' payable: '.heMoney($earnedSeries[$i])) ?>"></span><span class="settled" style="height:<?= max(2,$settledSeries[$i]/$maxChart*100) ?>%" title="<?= htmlspecialchars($label.' settled: '.heMoney($settledSeries[$i])) ?>"></span></div><small><?= htmlspecialchars($label) ?></small></div><?php endforeach; ?></div></article>
    <article class="he-card he-health"><header><div><span class="he-kicker">PAYOUT PROGRESS</span><h3>Eligible funds settled</h3></div></header><div class="he-health-body"><div class="he-donut" style="--progress:<?= number_format($settlementRate,1,'.','') ?>"><div><strong><?= number_format($settlementRate,0) ?>%</strong><span>settled</span></div></div><div class="he-health-list"><div><span><i class="settled"></i>Settled funds</span><strong><?= heMoney($totals['settled']) ?></strong></div><div><span><i class="ready"></i>Awaiting admin payout</span><strong><?= heMoney($totals['ready']) ?></strong></div><div><span><i class="pending"></i>Not yet eligible</span><strong><?= heMoney($totals['pending']) ?></strong></div></div></div><p class="he-health-note"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M12 11v5M12 8h.01"/></svg>Payouts become available after a stay is completed. Only platform administrators can record a settlement.</p></article>
  </section>

  <section class="he-card he-ledger"><header class="he-ledger-head"><div><span class="he-kicker">BOOKING PAYOUT LEDGER</span><h3>All bookings and payout status</h3></div><nav class="he-tabs" aria-label="Payout status filters"><?php foreach(['all'=>'All','pending'=>'Pending','ready'=>'Available','settled'=>'Paid out'] as $key=>$label): ?><a class="<?= $statusFilter===$key?'active':'' ?>" href="?<?= htmlspecialchars(http_build_query(array_filter(['q'=>$search,'status'=>$key==='all'?null:$key,'from'=>$dateFrom?:null,'to'=>$dateTo?:null]))) ?>"><?= $label ?><b><?= $key==='all'?count($allRows):$counts[$key] ?></b></a><?php endforeach; ?></nav></header>
    <form class="he-filters" method="get"><label class="he-search"><svg viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><path d="m20 20-4-4"/></svg><input type="search" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="Search booking, guest, or room"></label><select name="status"><option value="all">All payout statuses</option><?php foreach(['pending'=>'Pending eligibility','ready'=>'Available for settlement','settled'=>'Paid out','not_payable'=>'Not payable'] as $key=>$label): ?><option value="<?= $key ?>" <?= $statusFilter===$key?'selected':'' ?>><?= $label ?></option><?php endforeach; ?></select><label class="he-date"><span>From</span><input type="date" name="from" value="<?= htmlspecialchars($dateFrom) ?>"></label><label class="he-date"><span>To</span><input type="date" name="to" value="<?= htmlspecialchars($dateTo) ?>"></label><button class="he-button primary" type="submit">Apply</button><a class="he-clear" href="Hoearnings.php">Clear</a></form>
    <div class="he-table-wrap"><table class="he-table"><thead><tr><th>Booking & guest</th><th>Stay</th><th>Booking status</th><th>Booking payment</th><th class="right">Payout amount</th><th>Payout status</th><th>Settlement</th><th></th></tr></thead><tbody><?php if(!$pageRows): ?><tr><td colspan="8"><div class="he-empty"><svg viewBox="0 0 24 24"><path d="M5 4h14v16H5zM8 8h8M8 12h8M8 16h5"/></svg><strong>No payout records found</strong><span>Try changing your search or filters.</span></div></td></tr><?php else: foreach($pageRows as $row):
      $state=(string)$row['workflow_status']; $guest=trim((string)$row['first_name'].' '.(string)$row['last_name']);
      $stateLabel=match($state){'pending'=>'Pending eligibility','ready'=>'Available for settlement','settled'=>'Paid out',default=>'Not payable'};
      $stateNote=match($state){'pending'=>!empty($row['retention_applies'])?'Cancellation workflow in progress':'Stay must be completed','ready'=>'Waiting for admin settlement','settled'=>!empty($row['settled_at'])?date('M j, Y',strtotime((string)$row['settled_at'])):'Recorded by administrator',default=>(float)$row['amount_paid']<=.009?'No payment collected':heLabel((string)$row['booking_status'])};
      $detail=['booking'=>(string)$row['booking_reference'],'guest'=>$guest,'email'=>(string)$row['email'],'phone'=>(string)$row['phone_number'],'room'=>(string)$row['room_type'],'stay'=>date('M j, Y',strtotime((string)$row['checkin_date'])).' – '.date('M j, Y',strtotime((string)$row['checkout_date'])),'nights'=>(int)$row['nights'],'rooms'=>(int)$row['rooms_booked'],'guests'=>(int)$row['adults']+(int)$row['children'],'booking_status'=>heLabel((string)$row['booking_status']),'payment_status'=>heLabel((string)$row['payment_status']),'booking_total'=>heMoney((float)$row['total_amount']),'amount_paid'=>heMoney((float)$row['amount_paid']),'balance'=>heMoney((float)$row['remaining_balance']),'payout_amount'=>heMoney((float)$row['payout_amount']),'payout_status'=>$stateLabel,'payout_note'=>$stateNote,'settled_at'=>!empty($row['settled_at'])?date('F j, Y · g:i A',strtotime((string)$row['settled_at'])):'Not settled','method'=>heLabel((string)$row['settlement_method']),'reference'=>(string)($row['settlement_reference']??''),'note'=>(string)($row['settlement_note']??'')];
    ?><tr><td><div class="he-booking"><strong><?= htmlspecialchars((string)($row['booking_reference']?:'#'.$row['hotel_booking_id'])) ?></strong><span><?= htmlspecialchars($guest) ?></span></div></td><td><strong><?= htmlspecialchars((string)$row['room_type']) ?></strong><span class="he-sub"><?= date('M j',strtotime((string)$row['checkin_date'])) ?>–<?= date('M j, Y',strtotime((string)$row['checkout_date'])) ?> · <?= (int)$row['nights'] ?> night<?= (int)$row['nights']===1?'':'s' ?></span></td><td><span class="he-booking-status <?= htmlspecialchars(strtolower((string)$row['booking_status'])) ?>"><?= htmlspecialchars(heLabel((string)$row['booking_status'])) ?></span></td><td><strong><?= heMoney((float)$row['amount_paid']) ?> <small>of <?= heMoney((float)$row['total_amount']) ?></small></strong><span class="he-payment-status <?= htmlspecialchars(strtolower((string)$row['payment_status'])) ?>"><?= htmlspecialchars(heLabel((string)$row['payment_status'])) ?></span></td><td class="right"><strong class="he-payout-amount"><?= heMoney((float)$row['payout_amount']) ?></strong></td><td><span class="he-status <?= htmlspecialchars($state) ?>"><i></i><?= htmlspecialchars($stateLabel) ?></span><span class="he-sub"><?= htmlspecialchars($stateNote) ?></span></td><td><?php if($state==='settled'): ?><strong>Paid out</strong><span class="he-sub"><?= htmlspecialchars(heLabel((string)$row['settlement_method'])) ?> · <?= htmlspecialchars((string)$row['settlement_reference']) ?></span><?php else: ?><span class="he-muted">—</span><?php endif; ?></td><td><button type="button" class="he-row-action" data-payout='<?= htmlspecialchars(json_encode($detail,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT),ENT_QUOTES) ?>' aria-label="View payout details"><svg viewBox="0 0 24 24"><circle cx="5" cy="12" r="1"/><circle cx="12" cy="12" r="1"/><circle cx="19" cy="12" r="1"/></svg></button></td></tr><?php endforeach; endif; ?></tbody></table></div>
    <footer class="he-table-footer"><span>Showing <?= $rowCount?($page-1)*$perPage+1:0 ?>–<?= min($page*$perPage,$rowCount) ?> of <?= $rowCount ?> bookings</span><?php if($totalPages>1): ?><nav><a class="<?= $page<=1?'disabled':'' ?>" href="?<?= htmlspecialchars(http_build_query(array_merge($paginationQuery,['page'=>max(1,$page-1)]))) ?>">‹</a><span>Page <?= $page ?> of <?= $totalPages ?></span><a class="<?= $page>=$totalPages?'disabled':'' ?>" href="?<?= htmlspecialchars(http_build_query(array_merge($paginationQuery,['page'=>min($totalPages,$page+1)]))) ?>">›</a></nav><?php endif; ?><span>Filtered payout total <strong><?= heMoney(array_sum(array_map(fn($r)=>(float)$r['payout_amount'],$filteredRows))) ?></strong></span></footer>
  </section>
</section><?php include __DIR__.'/Ho_footer.php'; ?></main></div>
<div class="he-drawer" id="hePayoutDrawer" aria-hidden="true" inert><div class="he-drawer-backdrop" data-close-payout></div><aside role="dialog" aria-modal="true" aria-labelledby="heDrawerTitle"><header><div><span class="he-drawer-icon"><svg viewBox="0 0 24 24"><path d="M4 7h16v12H4zM7 11h10M7 15h6"/></svg></span><div><small>PAYOUT RECORD</small><h3 id="heDrawerTitle">Booking payout details</h3><p>Payment and administrator settlement information</p></div></div><button type="button" data-close-payout aria-label="Close">×</button></header><div class="he-drawer-body" id="heDrawerBody"></div><footer><button type="button" data-close-payout>Close details</button></footer></aside></div>
<script src="js/Ho_earnings.js?v=2"></script></body></html>
