<?php
declare(strict_types=1);

chdir(__DIR__ . '/..');
require_once __DIR__ . '/../php/session_security.php';
AppSessionStart();
require_once __DIR__ . '/../php/db_connection.php';
require_once __DIR__ . '/../php/operator_auth_helper.php';

$operatorAccount = OperatorRequireLogin($pdo);
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$operatorId = (int)($_SESSION['operator_id'] ?? 0);
$operatorName = trim((string)($_SESSION['operator_name'] ?? 'Tour Operator')) ?: 'Tour Operator';
$directRoute = str_contains(str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? '')), '/operator/');
$assetBase = $directRoute ? '../' : '';
$operatorNotificationCsrf = AppCsrfToken('operator', 'notifications');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['op_action'] ?? '') === 'mark_notifications_read') {
    if (!AppVerifyCsrf('operator', 'notifications', $_POST['csrf_token'] ?? null)) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false]);
        exit;
    }
    $_SESSION['op_notifications_seen_at'] = date('Y-m-d H:i:s');
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => true]);
    exit;
}

function oeMoney(float $amount): string { return '₱' . number_format($amount, 2); }
function oeLabel(?string $value): string {
    $value = trim(str_replace(['_', '-'], ' ', strtolower((string)$value)));
    return $value === '' ? '—' : ucwords($value);
}
function oeCsvSafe(string $value): string { return preg_match('/^\s*[=+\-@]/u', $value) ? "'" . $value : $value; }
function oeTableExists(PDO $pdo, string $table): bool {
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?');
    $stmt->execute([$table]);
    return (int)$stmt->fetchColumn() > 0;
}
function oeTrend(array $periods, bool $lowerIsBetter = false): array {
    [$current, $previous] = $periods;
    $difference = $current - $previous;
    if (abs($difference) < .005) return ['label'=>'0.0%','tone'=>'neutral','direction'=>'flat'];
    $direction = $difference > 0 ? 'up' : 'down';
    $tone = (($difference > 0) xor $lowerIsBetter) ? 'positive' : 'attention';
    $label = $previous > .005 ? ($difference > 0 ? '+' : '-') . number_format(abs($difference) / $previous * 100, 1) . '%' : 'New';
    return compact('label','tone','direction');
}
function oeTrendMarkup(array $trend, string $basis): string {
    $points = match ($trend['direction']) {
        'up' => '2,23 15,18 28,20 43,10 56,13 70,3',
        'down' => '2,5 15,10 28,8 43,18 56,15 70,24',
        default => '2,14 70,14',
    };
    $tip = match ($trend['direction']) { 'up'=>'M64 3h6v6','down'=>'M64 24h6v-6',default=>'M65 11l5 3-5 3' };
    return '<div class="he-metric-footer '.htmlspecialchars($trend['tone'],ENT_QUOTES).'"><div><span class="he-trend-badge">'.htmlspecialchars($trend['label']).'</span><span class="he-trend-caption">vs last month</span></div><svg class="he-stat-trend" viewBox="0 0 74 28" aria-hidden="true"><path class="guide" d="M2 26H70"/><polyline points="'.$points.'"/><path d="'.$tip.'"/></svg><span class="he-trend-basis">'.htmlspecialchars($basis).'</span></div>';
}

// Keep this operator's package bookings connected to the administrator payout ledger.
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
  SELECT 'package',b.booking_id,COALESCE(NULLIF(b.booking_reference,''),CONCAT('TP-',b.booking_id)),
    'operator',b.operator_id,CONCAT('operator:',b.operator_id),COALESCE(NULLIF(TRIM(o.fullname),''),'Tour Operator'),
    GREATEST(COALESCE(b.payment_amount,0),0),'pending'
  FROM bookings b LEFT JOIN operators o ON o.operator_id=b.operator_id
  WHERE b.operator_id=? AND LOWER(b.booking_type)='package' AND COALESCE(b.payment_amount,0)>0
  ON DUPLICATE KEY UPDATE booking_reference=VALUES(booking_reference),provider_name=VALUES(provider_name),
    gross_amount=IF(provider_payouts.status='pending',VALUES(gross_amount),provider_payouts.gross_amount),updated_at=NOW()");
$sync->execute([$operatorId]);

$hasCancellations = oeTableExists($pdo, 'booking_cancellation_requests');
$cancelSelect = $hasCancellations
    ? ',cr.request_status cancellation_status,cr.refund_status,cr.refund_policy,cr.refundable_amount,cr.non_refundable_amount'
    : ',NULL cancellation_status,NULL refund_status,NULL refund_policy,0 refundable_amount,0 non_refundable_amount';
$cancelJoin = $hasCancellations
    ? " LEFT JOIN booking_cancellation_requests cr ON cr.cancellation_request_id=(SELECT cr2.cancellation_request_id FROM booking_cancellation_requests cr2 WHERE cr2.booking_domain='tour' AND cr2.booking_id=b.booking_id ORDER BY cr2.cancellation_request_id DESC LIMIT 1)"
    : '';
$stmt = $pdo->prepare("SELECT b.booking_id,b.booking_reference,b.package_name,b.booking_date,b.tour_range,b.location,b.pax,b.num_adults,b.num_children,
  b.grand_total,b.payment_amount,b.remaining_balance,b.payment_method,b.status booking_status,b.is_complete,b.created_at,
  COALESCE(NULLIF(TRIM(t.full_name),''),CONCAT('Tourist #',b.tourist_id)) guest_name,COALESCE(t.email,'') guest_email,COALESCE(t.phone_number,b.phone_number,'') phone_number,
  p.payout_id,p.status ledger_status,p.gross_amount,p.approved_at,p.settlement_method,p.settlement_reference,p.settlement_note,p.settled_at
  {$cancelSelect}
  FROM bookings b LEFT JOIN tourist t ON t.tourist_id=b.tourist_id
  LEFT JOIN provider_payouts p ON p.booking_domain='package' AND p.booking_id=b.booking_id AND p.provider_key=CONCAT('operator:',b.operator_id)
  {$cancelJoin}
  WHERE b.operator_id=? AND LOWER(b.booking_type)='package' ORDER BY b.created_at DESC");
$stmt->execute([$operatorId]);
$allRows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$totals=['pending'=>0.0,'ready'=>0.0,'settled'=>0.0,'not_payable'=>0.0];
$counts=['pending'=>0,'ready'=>0,'settled'=>0,'not_payable'=>0];
$grossPayments=0.0;
foreach ($allRows as &$row) {
    $bookingStatus=strtolower(trim((string)$row['booking_status']));
    $completion=strtolower(trim((string)$row['is_complete']));
    $ledgerStatus=strtolower(trim((string)($row['ledger_status']??'')));
    $paid=max(0.0,(float)$row['payment_amount']);
    $grossPayments += $paid;
    $retained=min($paid,max(0.0,(float)($row['non_refundable_amount']??0)));
    $refundStatus=strtolower((string)($row['refund_status']??''));
    $cancelStatus=strtolower((string)($row['cancellation_status']??''));
    $policy=strtolower((string)($row['refund_policy']??''));
    $cancelled=in_array($completion,['cancelled','declined'],true)||$bookingStatus==='declined';
    $retentionApplies=$cancelled&&in_array($policy,['partial_refund','deposit_non_refundable'],true)&&$retained>.009;
    $refundComplete=(float)($row['refundable_amount']??0)<=.009||in_array($refundStatus,['completed','refunded','succeeded','not_applicable'],true);
    $retentionReady=$retentionApplies&&$cancelStatus==='completed'&&$refundComplete;
    if ($ledgerStatus==='settled') {$state='settled';$amount=max(0.0,(float)$row['gross_amount']);}
    elseif ($cancelled) {$state=$retentionReady?'ready':($retentionApplies?'pending':'not_payable');$amount=$retentionApplies?$retained:0.0;}
    elseif ($paid<=.009) {$state='not_payable';$amount=0.0;}
    elseif ($completion==='completed') {$state='ready';$amount=$paid;}
    else {$state='pending';$amount=$paid;}
    $row['workflow_status']=$state;$row['payout_amount']=$amount;$row['retention_applies']=$retentionApplies;
    $row['display_booking_status']=$cancelled?($completion==='cancelled'?'cancelled':'declined'):($completion==='completed'?'completed':($bookingStatus==='accepted'?'accepted':'pending'));
    $totals[$state]+=$amount;$counts[$state]++;
}
unset($row);

$search=trim((string)($_GET['q']??''));
$statusFilter=strtolower(trim((string)($_GET['status']??'all')));
$dateFrom=trim((string)($_GET['from']??''));$dateTo=trim((string)($_GET['to']??''));
if(!in_array($statusFilter,['all','pending','ready','settled','not_payable'],true))$statusFilter='all';
if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$dateFrom))$dateFrom='';
if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$dateTo))$dateTo='';
$filteredRows=array_values(array_filter($allRows,static function(array $row)use($search,$statusFilter,$dateFrom,$dateTo):bool{
    if($statusFilter!=='all'&&$row['workflow_status']!==$statusFilter)return false;
    $created=substr((string)$row['created_at'],0,10);
    if($dateFrom!==''&&$created<$dateFrom)return false;if($dateTo!==''&&$created>$dateTo)return false;
    return $search===''||str_contains(strtolower(implode(' ',[$row['booking_reference'],$row['guest_name'],$row['package_name']])),strtolower($search));
}));

if(($_GET['export']??'')==='csv'){
    header('Content-Type: text/csv; charset=UTF-8');header('Content-Disposition: attachment; filename="operator-earnings-'.date('Y-m-d').'.csv"');
    $out=fopen('php://output','wb');fwrite($out,"\xEF\xBB\xBF");
    fputcsv($out,['Booking','Guest','Package','Tour date','Booking total (PHP)','Paid (PHP)','Payout amount (PHP)','Payout status','Settlement date','Settlement method','Settlement reference']);
    foreach($filteredRows as $row)fputcsv($out,[oeCsvSafe((string)$row['booking_reference']),oeCsvSafe((string)$row['guest_name']),oeCsvSafe((string)$row['package_name']),(string)$row['booking_date'],number_format((float)$row['grand_total'],2,'.',''),number_format((float)$row['payment_amount'],2,'.',''),number_format((float)$row['payout_amount'],2,'.',''),oeLabel((string)$row['workflow_status']),(string)$row['settled_at'],oeLabel((string)$row['settlement_method']),oeCsvSafe((string)$row['settlement_reference'])]);
    fclose($out);exit;
}

$page=max(1,(int)($_GET['page']??1));$perPage=10;$rowCount=count($filteredRows);$totalPages=max(1,(int)ceil($rowCount/$perPage));$page=min($page,$totalPages);$pageRows=array_slice($filteredRows,($page-1)*$perPage,$perPage);
$monthKeys=[];$monthLabels=[];$earnedMap=[];$settledMap=[];
for($i=5;$i>=0;$i--){$ts=strtotime("first day of -{$i} month");$key=date('Y-m',$ts);$monthKeys[]=$key;$monthLabels[]=date('M',$ts);}
foreach($allRows as $row){$key=substr((string)$row['created_at'],0,7);if(in_array($key,$monthKeys,true))$earnedMap[$key]=($earnedMap[$key]??0)+(float)$row['payout_amount'];if(!empty($row['settled_at'])){$key=substr((string)$row['settled_at'],0,7);if(in_array($key,$monthKeys,true))$settledMap[$key]=($settledMap[$key]??0)+(float)$row['payout_amount'];}}
$earnedSeries=array_map(fn($key)=>(float)($earnedMap[$key]??0),$monthKeys);$settledSeries=array_map(fn($key)=>(float)($settledMap[$key]??0),$monthKeys);$maxChart=max(1.0,...$earnedSeries,...$settledSeries);
$settleBase=$totals['ready']+$totals['settled'];$settlementRate=$settleBase>0?($totals['settled']/$settleBase)*100:0;
$periodMonths=[date('Y-m'),date('Y-m',strtotime('first day of last month'))];$metricPeriods=array_fill_keys(['earned','pending','ready','settled'],[0.0,0.0]);
foreach($allRows as $row){$index=array_search(substr((string)$row['created_at'],0,7),$periodMonths,true);if($index!==false){$metricPeriods['earned'][$index]+=(float)$row['payment_amount'];if(in_array($row['workflow_status'],['pending','ready'],true))$metricPeriods[$row['workflow_status']][$index]+=(float)$row['payout_amount'];}$index=array_search(substr((string)($row['settled_at']??''),0,7),$periodMonths,true);if($row['workflow_status']==='settled'&&$index!==false)$metricPeriods['settled'][$index]+=(float)$row['payout_amount'];}
$exportQuery=$_GET;unset($exportQuery['page']);$exportQuery['export']='csv';$paginationQuery=$_GET;unset($paginationQuery['page'],$paginationQuery['export']);

$profileInitial=strtoupper(substr($operatorName,0,1));$profileImage=null;$profileStmt=$pdo->prepare('SELECT profile_pic FROM operators WHERE operator_id=? LIMIT 1');$profileStmt->execute([$operatorId]);$profileFile=trim((string)$profileStmt->fetchColumn());if($profileFile!==''&&file_exists('uploads/profile/'.basename($profileFile)))$profileImage=$assetBase.'uploads/profile/'.basename($profileFile);
$seenAt=trim((string)($_SESSION['op_notifications_seen_at']??''));$notifSql="SELECT COUNT(*) FROM bookings WHERE operator_id=? AND LOWER(status)='pending'";$notifParams=[$operatorId];if($seenAt!==''&&preg_match('/^\d{4}-\d{2}-\d{2}\s\d{2}:\d{2}:\d{2}$/',$seenAt)){$notifSql.=' AND created_at>?';$notifParams[]=$seenAt;}$notificationStmt=$pdo->prepare($notifSql);$notificationStmt->execute($notifParams);$notificationCount=(int)$notificationStmt->fetchColumn();
$notificationItemsStmt=$pdo->prepare('SELECT booking_id,booking_reference,package_name,booking_date,status,created_at FROM bookings WHERE operator_id=? ORDER BY created_at DESC LIMIT 8');$notificationItemsStmt->execute([$operatorId]);$notificationItems=$notificationItemsStmt->fetchAll(PDO::FETCH_ASSOC)?:[];
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Earnings &amp; Payouts | Tour Operator</title><link rel="icon" href="<?= $assetBase ?>img/newlogo.png"><link rel="stylesheet" href="<?= $assetBase ?>styles/Ho_panel.css?v=notifications-4"><link rel="stylesheet" href="<?= $assetBase ?>styles/Ho_earnings.css?v=3"><link rel="stylesheet" href="<?= $assetBase ?>styles/operator_header.css?v=5"><link rel="stylesheet" href="<?= $assetBase ?>styles/op_earnings.css?v=1"></head>
<body class="ho-body op-earnings-body"><div class="op-earnings-layout"><?php include __DIR__.'/operator_sidebar.php'; ?>
<main class="op-earnings-main"><header class="operator-header"><div class="operator-header-left"><span class="operator-header-icon"><svg viewBox="0 0 24 24"><path d="M4 6h14a2 2 0 0 1 2 2v11H4a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h12"/><path d="M20 11h-5a2 2 0 0 0 0 4h5M15 13h.01"/></svg></span><div class="operator-header-copy"><h2>Earnings &amp; Payouts</h2><p>Track package earnings and administrator settlements</p></div></div><div class="operator-header-right"><a class="op-earnings-export" href="?<?= htmlspecialchars(http_build_query($exportQuery)) ?>"><svg viewBox="0 0 24 24"><path d="M12 3v12m0 0 4-4m-4 4-4-4M5 20h14"/></svg>Export CSV</a><div class="op-notif-wrap"><button type="button" class="op-notif-btn" id="opNotifToggle" aria-label="Notifications" aria-expanded="false"><svg viewBox="0 0 24 24"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9M10 21h4"/></svg><?php if($notificationCount>0):?><span class="op-notif-badge"><?= $notificationCount ?></span><?php endif;?></button><div class="op-notif-panel" id="opNotifPanel"><h4>Recent Bookings</h4><?php if($notificationItems):?><ul class="op-notif-list"><?php foreach($notificationItems as $item):?><li><strong><?= htmlspecialchars((string)($item['booking_reference']?:$item['booking_id'])) ?> - <?= htmlspecialchars((string)$item['package_name']) ?></strong><span><?= htmlspecialchars(oeLabel((string)$item['status'])) ?> · <?= htmlspecialchars((string)$item['booking_date']) ?></span><small><?= htmlspecialchars((string)$item['created_at']) ?></small></li><?php endforeach;?></ul><?php else:?><p class="op-notif-empty">No notifications yet.</p><?php endif;?></div></div><div class="op-topbar-profile" data-operator-header-profile role="button" tabindex="0" aria-label="Open operator profile"><?php if($profileImage):?><img src="<?= htmlspecialchars($profileImage) ?>" alt="<?= htmlspecialchars($operatorName) ?>"><?php else:?><?= htmlspecialchars($profileInitial) ?><?php endif;?></div></div></header>
<section class="he-workspace">
<section class="he-metrics" aria-label="Payout summary">
<?php $cards=[['earned','Gross package payments',$grossPayments,count($allRows).' package booking'.(count($allRows)===1?'':'s').' in your ledger','M4 19V9m5 10V5m5 14v-7m5 7V3',false,'By booking month'],['pending','Pending eligibility',$totals['pending'],$counts['pending'].' awaiting tour or refund completion','M12 7v5l3 2M21 12a9 9 0 1 1-9-9',true,'By booking month'],['ready','Available for payout',$totals['ready'],$counts['ready'].' completed booking'.($counts['ready']===1?'':'s').' awaiting settlement','m7 12 3 3 7-7M12 3a9 9 0 1 0 9 9',false,'By booking month'],['settled','Total settled',$totals['settled'],$counts['settled'].' payout'.($counts['settled']===1?'':'s').' recorded by administrators','M4 7h16v12H4zM7 11h10M7 15h6',false,'By settlement date']];foreach($cards as [$key,$label,$value,$note,$icon,$lower,$basis]):?><article class="he-metric <?= $key ?>"><span class="he-metric-icon"><svg viewBox="0 0 24 24"><path d="<?= $icon ?>"/></svg></span><div><small><?= htmlspecialchars($label) ?></small><strong><?= oeMoney((float)$value) ?></strong><p><?= htmlspecialchars($note) ?></p></div><?= oeTrendMarkup(oeTrend($metricPeriods[$key],$lower),$basis) ?></article><?php endforeach;?>
</section>
<section class="he-insights"><article class="he-card he-chart-card"><header><div><span class="he-kicker">6-MONTH ACTIVITY</span><h3>Package earnings and settlements</h3></div><div class="he-legend"><span><i class="earned"></i>Payable earnings</span><span><i class="settled"></i>Settled</span></div></header><div class="he-chart" aria-label="Six month operator earnings chart"><?php foreach($monthLabels as $i=>$label):?><div class="he-chart-col"><div class="he-chart-values"><span class="earned" style="height:<?= max(2,$earnedSeries[$i]/$maxChart*100) ?>%" title="<?= htmlspecialchars($label.' payable: '.oeMoney($earnedSeries[$i])) ?>"></span><span class="settled" style="height:<?= max(2,$settledSeries[$i]/$maxChart*100) ?>%" title="<?= htmlspecialchars($label.' settled: '.oeMoney($settledSeries[$i])) ?>"></span></div><small><?= htmlspecialchars($label) ?></small></div><?php endforeach;?></div></article>
<article class="he-card he-health"><header><div><span class="he-kicker">PAYOUT PROGRESS</span><h3>Eligible funds settled</h3></div></header><div class="he-health-body"><div class="he-donut" style="--progress:<?= number_format($settlementRate,1,'.','') ?>"><div><strong><?= number_format($settlementRate,0) ?>%</strong><span>settled</span></div></div><div class="he-health-list"><div><span><i class="settled"></i>Settled funds</span><strong><?= oeMoney($totals['settled']) ?></strong></div><div><span><i class="ready"></i>Awaiting admin payout</span><strong><?= oeMoney($totals['ready']) ?></strong></div><div><span><i class="pending"></i>Not yet eligible</span><strong><?= oeMoney($totals['pending']) ?></strong></div></div></div><p class="he-health-note"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M12 11v5M12 8h.01"/></svg>Package earnings become available after the tour is completed. Only platform administrators can record a settlement.</p></article></section>
<section class="he-card he-ledger"><header class="he-ledger-head"><div><span class="he-kicker">PACKAGE PAYOUT LEDGER</span><h3>All bookings and payout status</h3></div><nav class="he-tabs" aria-label="Payout status filters"><?php foreach(['all'=>'All','pending'=>'Pending','ready'=>'Available','settled'=>'Paid out'] as $key=>$label):?><a class="<?= $statusFilter===$key?'active':'' ?>" href="?<?= htmlspecialchars(http_build_query(array_filter(['q'=>$search,'status'=>$key==='all'?null:$key,'from'=>$dateFrom?:null,'to'=>$dateTo?:null]))) ?>"><?= $label ?><b><?= $key==='all'?count($allRows):$counts[$key] ?></b></a><?php endforeach;?></nav></header>
<form class="he-filters" method="get"><label class="he-search"><svg viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><path d="m20 20-4-4"/></svg><input type="search" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="Search booking, guest, or package"></label><select name="status"><option value="all">All payout statuses</option><?php foreach(['pending'=>'Pending eligibility','ready'=>'Available for settlement','settled'=>'Paid out','not_payable'=>'Not payable'] as $key=>$label):?><option value="<?= $key ?>" <?= $statusFilter===$key?'selected':'' ?>><?= $label ?></option><?php endforeach;?></select><label class="he-date"><span>From</span><input type="date" name="from" value="<?= htmlspecialchars($dateFrom) ?>"></label><label class="he-date"><span>To</span><input type="date" name="to" value="<?= htmlspecialchars($dateTo) ?>"></label><button class="he-button primary" type="submit">Apply</button><a class="he-clear" href="opearnings.php">Clear</a></form>
<div class="he-table-wrap"><table class="he-table"><thead><tr><th>Booking &amp; guest</th><th>Package &amp; tour</th><th>Booking status</th><th>Booking payment</th><th class="right">Payout amount</th><th>Payout status</th><th>Settlement</th><th></th></tr></thead><tbody><?php if(!$pageRows):?><tr><td colspan="8"><div class="he-empty"><strong>No payout records found</strong><span>Try changing your search or filters.</span></div></td></tr><?php else:foreach($pageRows as $row):$state=(string)$row['workflow_status'];$stateLabel=match($state){'pending'=>'Pending eligibility','ready'=>'Available for settlement','settled'=>'Paid out',default=>'Not payable'};$stateNote=match($state){'pending'=>!empty($row['retention_applies'])?'Cancellation workflow in progress':'Tour must be completed','ready'=>'Waiting for admin settlement','settled'=>!empty($row['settled_at'])?date('M j, Y',strtotime((string)$row['settled_at'])):'Recorded by administrator',default=>(float)$row['payment_amount']<=.009?'No payment collected':oeLabel((string)$row['display_booking_status'])};$detail=['booking'=>(string)$row['booking_reference'],'guest'=>(string)$row['guest_name'],'email'=>(string)$row['guest_email'],'phone'=>(string)$row['phone_number'],'room'=>(string)$row['package_name'],'stay'=>!empty($row['booking_date'])?date('F j, Y',strtotime((string)$row['booking_date'])):'Date unavailable','nights'=>(trim((string)$row['tour_range'])!==''?(string)$row['tour_range']:'Single-day tour'),'rooms'=>'—','guests'=>(int)$row['pax'],'booking_status'=>oeLabel((string)$row['display_booking_status']),'payment_status'=>(float)$row['remaining_balance']<=.009?'Paid':((float)$row['payment_amount']>.009?'Partial':'Unpaid'),'booking_total'=>oeMoney((float)$row['grand_total']),'amount_paid'=>oeMoney((float)$row['payment_amount']),'balance'=>oeMoney((float)$row['remaining_balance']),'payout_amount'=>oeMoney((float)$row['payout_amount']),'payout_status'=>$stateLabel,'payout_note'=>$stateNote,'settled_at'=>!empty($row['settled_at'])?date('F j, Y · g:i A',strtotime((string)$row['settled_at'])):'Not settled','method'=>oeLabel((string)$row['settlement_method']),'reference'=>(string)($row['settlement_reference']??''),'note'=>(string)($row['settlement_note']??'')];?><tr><td><div class="he-booking"><strong><?= htmlspecialchars((string)($row['booking_reference']?:'#'.$row['booking_id'])) ?></strong><span><?= htmlspecialchars((string)$row['guest_name']) ?></span></div></td><td><strong><?= htmlspecialchars((string)$row['package_name']) ?></strong><span class="he-sub"><?= !empty($row['booking_date'])?date('M j, Y',strtotime((string)$row['booking_date'])):'Date unavailable' ?> · <?= (int)$row['pax'] ?> guest<?= (int)$row['pax']===1?'':'s' ?></span></td><td><span class="he-booking-status <?= htmlspecialchars((string)$row['display_booking_status']) ?>"><?= htmlspecialchars(strtoupper(oeLabel((string)$row['display_booking_status']))) ?></span></td><td><strong><?= oeMoney((float)$row['payment_amount']) ?> <small>of <?= oeMoney((float)$row['grand_total']) ?></small></strong><span class="he-sub"><?= (float)$row['remaining_balance']<=.009?'PAID':((float)$row['payment_amount']>.009?'PARTIAL':'UNPAID') ?></span></td><td class="right"><strong class="he-payout-amount"><?= oeMoney((float)$row['payout_amount']) ?></strong></td><td><span class="he-status <?= $state ?>"><i></i><?= htmlspecialchars($stateLabel) ?></span><span class="he-sub"><?= htmlspecialchars($stateNote) ?></span></td><td><?= $state==='settled'?'<strong>'.htmlspecialchars(oeLabel((string)$row['settlement_method'])).'</strong><span class="he-sub">'.htmlspecialchars((string)$row['settlement_reference']).'</span>':'<span class="he-muted">—</span>' ?></td><td><button class="he-row-action" type="button" data-payout="<?= htmlspecialchars(json_encode($detail,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),ENT_QUOTES) ?>"></button></td></tr><?php endforeach;endif;?></tbody></table></div>
<footer class="he-table-footer"><span>Showing <?= $rowCount?($page-1)*$perPage+1:0 ?>–<?= min($page*$perPage,$rowCount) ?> of <?= $rowCount ?> bookings</span><nav><a class="<?= $page<=1?'disabled':'' ?>" href="?<?= htmlspecialchars(http_build_query(array_merge($paginationQuery,['page'=>max(1,$page-1)]))) ?>">‹</a><strong>Page <?= $page ?> of <?= $totalPages ?></strong><a class="<?= $page>=$totalPages?'disabled':'' ?>" href="?<?= htmlspecialchars(http_build_query(array_merge($paginationQuery,['page'=>min($totalPages,$page+1)]))) ?>">›</a></nav></footer></section>
</section></main></div>
<div class="he-drawer" id="hePayoutDrawer" aria-hidden="true" inert><div class="he-drawer-backdrop" data-close-payout></div><aside><header><div><span class="he-drawer-icon"><svg viewBox="0 0 24 24"><path d="M4 6h14a2 2 0 0 1 2 2v11H4a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h12"/><path d="M20 11h-5a2 2 0 0 0 0 4h5"/></svg></span><div><small>PACKAGE PAYOUT</small><h3>Payout details</h3><p>Booking earnings and settlement record</p></div></div><button type="button" data-close-payout aria-label="Close">×</button></header><div class="he-drawer-body" id="heDrawerBody"></div><footer><button type="button" data-close-payout>Done</button></footer></aside></div>
<script>window.operatorNotificationCsrf=<?= json_encode($operatorNotificationCsrf) ?>;</script><script src="<?= $assetBase ?>js/operator_header.js?v=4"></script><script src="<?= $assetBase ?>js/Ho_earnings.js?v=2"></script></body></html>
