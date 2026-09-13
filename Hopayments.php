<?php
declare(strict_types=1);

require_once __DIR__ . '/Ho_common.php';
require_once __DIR__ . '/php/activity_logger.php';
require_once __DIR__ . '/php/firebase_config.php';
require_once __DIR__ . '/php/input_validation.php';

$hoAdmin = HoRequireHotelAdmin($pdo);
$hotelId = (int)$hoAdmin['hotel_resort_id'];
$propertyName = trim((string)($hoAdmin['property_name'] ?? '')) ?: 'Assigned Property';
$ownerName = $propertyName . ' Admin';
$hoActive = 'payments';
$hoTitle = 'Payments & Transactions';
$hoOwnerName = $ownerName;
$hoPendingBadge = HoGetPendingCount($pdo, $hotelId);
$hoUnreadBadge = HoGetUnreadCount($pdo, $hotelId);
$hoNotifItems = HoGetNotificationItems($pdo, 8, $hotelId);
$hasLedger = HoTableExists($pdo, 'payment_transactions');
$hoFirebaseConfiguration = firebase_public_configuration();

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
if (empty($_SESSION['ho_payment_csrf'])) $_SESSION['ho_payment_csrf'] = bin2hex(random_bytes(32));
$csrfToken = (string)$_SESSION['ho_payment_csrf'];
if (empty($_SESSION['paymongo_hotel_admin_csrf'])) $_SESSION['paymongo_hotel_admin_csrf'] = bin2hex(random_bytes(32));
$payMongoCsrf = (string)$_SESSION['paymongo_hotel_admin_csrf'];
$hotelNotificationCsrf = AppCsrfToken('hotel_admin', 'notifications');

function hoPayMoney(float $amount): string { return '₱' . number_format($amount, 2); }
function hoPayLabel(?string $value): string {
    $value = trim(str_replace(['_', '-'], ' ', strtolower((string)$value)));
    return $value === '' ? 'Not specified' : ucwords($value);
}
function hoPayComparison(float $current, float $previous, bool $lowerIsBetter = false): array {
    $difference = $current - $previous;
    if (abs($difference) < 0.005) return ['text'=>'No change vs last month','tone'=>'neutral','direction'=>''];
    $direction = $difference > 0 ? '↑' : '↓';
    $tone = (($difference > 0) xor $lowerIsBetter) ? 'positive' : 'attention';
    $change = $previous > 0 ? number_format(abs($difference) / $previous * 100, 1).'%' : 'New';
    return ['text'=>$direction.' '.$change.' vs last month','tone'=>$tone,'direction'=>$direction];
}
function hoPayTrendVisual(array $comparison): string {
    $direction = (string)($comparison['direction'] ?? '');
    $tone = (string)($comparison['tone'] ?? 'neutral');
    $points = match ($direction) {
        '↑' => '2,23 15,18 28,20 43,10 56,13 70,3',
        '↓' => '2,5 15,10 28,8 43,18 56,15 70,24',
        default => '2,14 15,12 28,15 43,13 56,14 70,13',
    };
    $tip = $direction === '↑' ? 'M64 3h6v6' : ($direction === '↓' ? 'M64 24h6v-6' : 'M65 10l5 3-5 3');
    $endpointY = $direction === '↑' ? 3 : ($direction === '↓' ? 24 : 13);
    return '<span class="hp-stat-trend '.htmlspecialchars($tone,ENT_QUOTES).'" aria-hidden="true"><svg viewBox="0 0 72 28"><path class="guide" d="M2 25H70"/><polyline class="line" points="'.$points.'"/><circle cx="70" cy="'.$endpointY.'" r="2.5"/><path class="arrow" d="'.$tip.'"/></svg></span>';
}
function hoPaymentProfileImage(?string $profilePicture, ?string $googleId = null): string {
    $profilePicture = trim((string)$profilePicture);
    if ($profilePicture !== '' && preg_match('#^https?://#i', $profilePicture)) return $profilePicture;
    if ($profilePicture !== '') {
        $clean = ltrim($profilePicture, '/');
        foreach (['uploads/profile_pictures/'.basename($clean), 'uploads/profile_picture/'.basename($clean), $clean] as $path) {
            if (file_exists(__DIR__.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $path))) return $path;
        }
    }
    $googleId = trim((string)$googleId);
    return $googleId !== '' ? 'https://profiles.google.com/'.rawurlencode($googleId).'/picture?sz=96' : '';
}
function hoPayRedirect(string $type, string $message): never {
    $_SESSION['ho_payment_notice'] = compact('type', 'message');
    header('Location: Hopayments.php'); exit;
}
function hoCsvSafe(string $value): string { return preg_match('/^\s*[=+\-@]/u', $value) ? "'" . $value : $value; }

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['ho_action'] ?? '') === 'mark_notifications_read') {
    if (!AppVerifyCsrf('hotel_admin', 'notifications', $_POST['csrf_token'] ?? null)) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['ok' => false]);
        exit;
    }
    HoMarkNotificationsRead($pdo, $hotelId);
    header('Content-Type: application/json'); echo json_encode(['ok' => true]); exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'record_payment') {
    if (!hash_equals($csrfToken, (string)($_POST['csrf_token'] ?? ''))) hoPayRedirect('error', 'Your session expired. Refresh the page and try again.');
    try {
        $bookingId = ItourValidationInt($_POST['booking_id'] ?? null, 'Booking ID', 1, PHP_INT_MAX);
        $amount = ItourValidationMoney($_POST['amount'] ?? null, 'Payment amount', 10000000.00, false);
        $note = ItourValidationText($_POST['note'] ?? '', 'Payment note', 500);
    } catch (InvalidArgumentException $exception) {
        hoPayRedirect('error', $exception->getMessage());
    }
    $method = strtolower(trim((string)($_POST['payment_method'] ?? 'cash')));
    $allowedMethods = ['cash', 'gcash', 'bank_transfer', 'card_terminal', 'other'];
    if ($bookingId < 1 || $amount <= 0 || !in_array($method, $allowedMethods, true)) hoPayRedirect('error', 'Choose a valid booking, amount, and payment method.');
    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare("SELECT hotel_booking_id, tourist_id, booking_reference, first_name, last_name, total_amount, amount_paid, remaining_balance, booking_status, checked_out_at FROM hotel_room_bookings WHERE hotel_booking_id=? AND hotel_resort_id=? LIMIT 1 FOR UPDATE");
        $stmt->execute([$bookingId, $hotelId]);
        $booking = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$booking) throw new RuntimeException('The selected booking does not belong to this property.');
        if (in_array(strtolower((string)$booking['booking_status']), ['cancelled', 'no-show'], true)) throw new RuntimeException('Payments cannot be recorded for a cancelled or no-show booking.');
        $balance = round(max(0, (float)$booking['remaining_balance']), 2);
        if ($balance <= 0) throw new RuntimeException('This booking is already fully paid.');
        if ($amount > $balance + .009) throw new RuntimeException('The payment cannot exceed ' . hoPayMoney($balance) . '.');
        $newPaid = round((float)$booking['amount_paid'] + $amount, 2);
        $newBalance = round(max(0, $balance - $amount), 2);
        $newStatus = $newBalance <= 0 ? 'paid' : 'partial';
        $update = $pdo->prepare("UPDATE hotel_room_bookings SET amount_paid=?, remaining_balance=?, payment_status=?, balance_payment_method=?, updated_at=NOW() WHERE hotel_booking_id=? AND hotel_resort_id=?");
        $update->execute([$newPaid, $newBalance, $newStatus, $method, $bookingId, $hotelId]);

        if ($hasLedger) {
            $unique = bin2hex(random_bytes(16));
            $merchantReference = 'HOTEL-OFF-' . date('YmdHis') . '-' . strtoupper(substr($unique, 0, 6));
            $metadata = json_encode(['source'=>'hotel_payment_page','hotel_resort_id'=>$hotelId,'recorded_by_hotel_admin_id'=>(int)$hoAdmin['hotel_admin_id'],'note'=>$note], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            $insert = $pdo->prepare("INSERT INTO payment_transactions (tourist_id, booking_domain, booking_id, booking_reference, provider, merchant_reference, idempotency_key, return_token, amount_minor, currency, status, payment_method_type, metadata, paid_at) VALUES (?, 'hotel', ?, ?, 'offline', ?, ?, ?, ?, 'PHP', 'paid', ?, ?, NOW())");
            $insert->execute([(int)$booking['tourist_id'], $bookingId, (string)$booking['booking_reference'], $merchantReference, 'hotel-offline:' . $unique, hash('sha256', $unique . random_bytes(8)), (int)round($amount * 100), $method, $metadata]);
        }
        $pdo->commit();
        logActivity($pdo, 'Hotel Owner', (int)$hoAdmin['hotel_admin_id'], $ownerName, 'Hotel Payment Recorded', 'Recorded ' . hoPayMoney($amount) . ' for ' . ((string)$booking['booking_reference'] ?: 'hotel booking #' . $bookingId) . '.', 'Hotel Payments', $bookingId);
        hoPayRedirect('success', 'Payment recorded successfully. The booking balance and transaction ledger were updated.');
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        hoPayRedirect('error', $error->getMessage());
    }
}

$search = trim((string)($_GET['q'] ?? ''));
$statusFilter = strtolower(trim((string)($_GET['status'] ?? 'all')));
$methodFilter = strtolower(trim((string)($_GET['method'] ?? 'all')));
$dateFrom = trim((string)($_GET['from'] ?? ''));
$dateTo = trim((string)($_GET['to'] ?? ''));
if (!in_array($statusFilter, ['all','paid','pending','failed','cancelled','expired'], true)) $statusFilter = 'all';
if (!in_array($methodFilter, ['all','paymongo','offline','cash','gcash','bank_transfer','card_terminal'], true)) $methodFilter = 'all';
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) $dateFrom = '';
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) $dateTo = '';

$bookingStatsStmt = $pdo->prepare("SELECT COALESCE(SUM(total_amount),0) total_value, COALESCE(SUM(amount_paid),0) collected, COALESCE(SUM(remaining_balance),0) outstanding, SUM(CASE WHEN remaining_balance<=0 AND total_amount>0 THEN 1 ELSE 0 END) paid_count, SUM(CASE WHEN amount_paid>0 AND remaining_balance>0 THEN 1 ELSE 0 END) partial_count, SUM(CASE WHEN amount_paid<=0 AND remaining_balance>0 THEN 1 ELSE 0 END) unpaid_count, COUNT(*) booking_count FROM hotel_room_bookings WHERE hotel_resort_id=? AND booking_status NOT IN ('cancelled','no-show')");
$bookingStatsStmt->execute([$hotelId]);
$stats = $bookingStatsStmt->fetch(PDO::FETCH_ASSOC) ?: [];
$totalValue = (float)($stats['total_value'] ?? 0); $collected = (float)($stats['collected'] ?? 0); $outstanding = (float)($stats['outstanding'] ?? 0);
$collectionRate = $totalValue > 0 ? min(100, ($collected / $totalValue) * 100) : 0;

$currentMonthStart = date('Y-m-01 00:00:00');
$nextMonthStart = date('Y-m-01 00:00:00', strtotime('+1 month'));
$previousMonthStart = date('Y-m-01 00:00:00', strtotime('-1 month'));
$monthlyBookingStmt = $pdo->prepare("SELECT
  COALESCE(SUM(CASE WHEN created_at>=? AND created_at<? THEN remaining_balance ELSE 0 END),0) current_receivable,
  COALESCE(SUM(CASE WHEN created_at>=? AND created_at<? THEN remaining_balance ELSE 0 END),0) previous_receivable,
  COALESCE(SUM(CASE WHEN remaining_balance<=0 AND total_amount>0 AND updated_at>=? AND updated_at<? THEN 1 ELSE 0 END),0) current_paid,
  COALESCE(SUM(CASE WHEN remaining_balance<=0 AND total_amount>0 AND updated_at>=? AND updated_at<? THEN 1 ELSE 0 END),0) previous_paid,
  COALESCE(SUM(CASE WHEN checkin_date>=DATE(?) AND checkin_date<LEAST(CURDATE(),DATE(?)) AND remaining_balance>0 THEN remaining_balance ELSE 0 END),0) current_overdue,
  COALESCE(SUM(CASE WHEN checkin_date>=DATE(?) AND checkin_date<DATE(?) AND remaining_balance>0 THEN remaining_balance ELSE 0 END),0) previous_overdue
  FROM hotel_room_bookings WHERE hotel_resort_id=? AND booking_status NOT IN ('cancelled','no-show')");
$monthlyBookingStmt->execute([$currentMonthStart,$nextMonthStart,$previousMonthStart,$currentMonthStart,$currentMonthStart,$nextMonthStart,$previousMonthStart,$currentMonthStart,$currentMonthStart,$nextMonthStart,$previousMonthStart,$currentMonthStart,$hotelId]);
$monthly = $monthlyBookingStmt->fetch(PDO::FETCH_ASSOC) ?: [];
$currentCollected = 0.0; $previousCollected = 0.0;
if ($hasLedger) {
    $monthlyCollectedStmt = $pdo->prepare("SELECT
      COALESCE(SUM(CASE WHEN COALESCE(pt.paid_at,pt.created_at)>=? AND COALESCE(pt.paid_at,pt.created_at)<? THEN pt.amount_minor ELSE 0 END),0)/100 current_collected,
      COALESCE(SUM(CASE WHEN COALESCE(pt.paid_at,pt.created_at)>=? AND COALESCE(pt.paid_at,pt.created_at)<? THEN pt.amount_minor ELSE 0 END),0)/100 previous_collected
      FROM payment_transactions pt INNER JOIN hotel_room_bookings b ON b.hotel_booking_id=pt.booking_id AND LOWER(pt.booking_domain)='hotel'
      WHERE b.hotel_resort_id=? AND LOWER(pt.status) IN ('paid','succeeded','completed')");
    $monthlyCollectedStmt->execute([$currentMonthStart,$nextMonthStart,$previousMonthStart,$currentMonthStart,$hotelId]);
    $monthlyCollected = $monthlyCollectedStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $currentCollected = (float)($monthlyCollected['current_collected'] ?? 0);
    $previousCollected = (float)($monthlyCollected['previous_collected'] ?? 0);
}
$collectedCompare = hoPayComparison($currentCollected,$previousCollected);
$receivableCompare = hoPayComparison((float)($monthly['current_receivable']??0),(float)($monthly['previous_receivable']??0),true);
$paidCompare = hoPayComparison((float)($monthly['current_paid']??0),(float)($monthly['previous_paid']??0));
$overdueCompare = hoPayComparison((float)($monthly['current_overdue']??0),(float)($monthly['previous_overdue']??0),true);

$balancesStmt = $pdo->prepare("SELECT b.*, CONCAT(b.first_name,' ',b.last_name) guest_name, r.room_name, h.name hotel_name, t.profile_picture tourist_profile_picture, t.google_id tourist_google_id FROM hotel_room_bookings b LEFT JOIN hotel_rooms r ON r.hotel_room_id=b.hotel_room_id AND r.hotel_resort_id=b.hotel_resort_id LEFT JOIN hotel_resorts h ON h.hotel_resort_id=b.hotel_resort_id LEFT JOIN tourist t ON t.tourist_id=b.tourist_id WHERE b.hotel_resort_id=? AND b.remaining_balance>0 AND b.booking_status NOT IN ('cancelled','no-show') ORDER BY b.checkin_date ASC, b.created_at ASC");
$balancesStmt->execute([$hotelId]);
$balances = $balancesStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
$overdueTotal=0.0; $dueSoonTotal=0.0; $futureTotal=0.0; $overdueCount=0;
$today = date('Y-m-d'); $soon = date('Y-m-d', strtotime('+7 days'));
foreach ($balances as $row) {
    $amount=(float)$row['remaining_balance']; $due=(string)$row['checkin_date'];
    if ($due < $today) { $overdueTotal += $amount; $overdueCount++; }
    elseif ($due <= $soon) $dueSoonTotal += $amount; else $futureTotal += $amount;
}

$transactions=[]; $transactionCount=0; $page=max(1,(int)($_GET['page']??1)); $perPage=10; $totalPages=1; $offset=0;
$where=["LOWER(pt.booking_domain)='hotel'",'b.hotel_resort_id=:hotel_id']; $params=['hotel_id'=>$hotelId];
if ($search!=='') { $where[]='(pt.merchant_reference LIKE :search OR pt.booking_reference LIKE :search OR CONCAT(b.first_name,\' \',b.last_name) LIKE :search OR b.email LIKE :search)'; $params['search']='%'.$search.'%'; }
if ($statusFilter!=='all') { $where[]='LOWER(pt.status)=:status'; $params['status']=$statusFilter; }
if ($methodFilter!=='all') {
    if ($methodFilter==='paymongo') $where[]="LOWER(pt.provider)='paymongo'";
    elseif ($methodFilter==='offline') $where[]="LOWER(pt.provider)='offline'";
    else { $where[]='LOWER(pt.payment_method_type)=:method'; $params['method']=$methodFilter; }
}
if ($dateFrom!=='') { $where[]='DATE(pt.created_at)>=:date_from'; $params['date_from']=$dateFrom; }
if ($dateTo!=='') { $where[]='DATE(pt.created_at)<=:date_to'; $params['date_to']=$dateTo; }
$whereSql=' WHERE '.implode(' AND ',$where);
$selectSql="SELECT pt.*, CONCAT(b.first_name,' ',b.last_name) guest_name, b.email, b.room_type, b.total_amount booking_total, b.amount_paid booking_paid, b.remaining_balance booking_balance, b.checkin_date FROM payment_transactions pt INNER JOIN hotel_room_bookings b ON b.hotel_booking_id=pt.booking_id AND LOWER(pt.booking_domain)='hotel'";
if ($hasLedger) {
    if (($_GET['export']??'')==='csv') {
        $exportStmt=$pdo->prepare($selectSql.$whereSql.' ORDER BY pt.created_at DESC'); $exportStmt->execute($params); $rows=$exportStmt->fetchAll(PDO::FETCH_ASSOC)?:[];
        header('Content-Type: text/csv; charset=UTF-8'); header('Content-Disposition: attachment; filename="hotel-payment-transactions-'.date('Y-m-d').'.csv"'); echo "\xEF\xBB\xBF"; $out=fopen('php://output','wb');
        fputcsv($out,['iTour Mercedes — Hotel Payment Transaction Report']); fputcsv($out,['Property',$propertyName]); fputcsv($out,['Generated',date('F j, Y g:i A')]); fputcsv($out,[]); fputcsv($out,['Transaction Reference','Booking Reference','Guest','Email','Room','Payment Date','Channel','Method','Amount (PHP)','Status']);
        foreach ($rows as $row) {
            $isCashRow = strtolower((string)$row['payment_method_type']) === 'cash';
            fputcsv($out, [
                hoCsvSafe($isCashRow ? 'Cash' : (string)$row['merchant_reference']),
                hoCsvSafe((string)$row['booking_reference']),
                hoCsvSafe((string)$row['guest_name']),
                hoCsvSafe((string)$row['email']),
                hoCsvSafe((string)$row['room_type']),
                date('Y-m-d H:i:s', strtotime((string)($row['paid_at'] ?: $row['created_at']))),
                $isCashRow ? 'Cash' : hoPayLabel((string)$row['provider']),
                hoPayLabel((string)$row['payment_method_type']),
                number_format(((int)$row['amount_minor']) / 100, 2, '.', ''),
                hoPayLabel((string)$row['status']),
            ]);
        }
        fclose($out); exit;
    }
    $countStmt=$pdo->prepare('SELECT COUNT(*) FROM payment_transactions pt INNER JOIN hotel_room_bookings b ON b.hotel_booking_id=pt.booking_id'.$whereSql); $countStmt->execute($params); $transactionCount=(int)$countStmt->fetchColumn();
    $totalPages=max(1,(int)ceil($transactionCount/$perPage)); $page=min($page,$totalPages); $offset=($page-1)*$perPage;
    $txStmt=$pdo->prepare($selectSql.$whereSql.' ORDER BY COALESCE(pt.paid_at,pt.created_at) DESC, pt.payment_transaction_id DESC LIMIT '.$perPage.' OFFSET '.$offset); $txStmt->execute($params); $transactions=$txStmt->fetchAll(PDO::FETCH_ASSOC)?:[];
}

$trendLabels=[]; $trendValues=[]; $trendMap=[];
if ($hasLedger) {
    $trendStmt=$pdo->prepare("
        SELECT collections.ym, SUM(collections.amount) AS amount
        FROM (
            SELECT
                DATE_FORMAT(COALESCE(pt.paid_at, pt.created_at), '%Y-%m') AS ym,
                SUM(pt.amount_minor) / 100 AS amount
            FROM payment_transactions pt
            INNER JOIN hotel_room_bookings b
                ON b.hotel_booking_id = pt.booking_id
               AND LOWER(pt.booking_domain) = 'hotel'
            WHERE b.hotel_resort_id = ?
              AND b.booking_status NOT IN ('cancelled', 'no-show')
              AND LOWER(pt.status) IN ('paid', 'succeeded', 'completed')
              AND COALESCE(pt.paid_at, pt.created_at) >= DATE_FORMAT(CURRENT_DATE - INTERVAL 5 MONTH, '%Y-%m-01')
            GROUP BY DATE_FORMAT(COALESCE(pt.paid_at, pt.created_at), '%Y-%m')

            UNION ALL

            SELECT
                DATE_FORMAT(b.created_at, '%Y-%m') AS ym,
                SUM(GREATEST(b.amount_paid - COALESCE(ledger.recorded_amount, 0), 0)) AS amount
            FROM hotel_room_bookings b
            LEFT JOIN (
                SELECT booking_id, SUM(amount_minor) / 100 AS recorded_amount
                FROM payment_transactions
                WHERE LOWER(booking_domain) = 'hotel'
                  AND LOWER(status) IN ('paid', 'succeeded', 'completed')
                GROUP BY booking_id
            ) ledger ON ledger.booking_id = b.hotel_booking_id
            WHERE b.hotel_resort_id = ?
              AND b.booking_status NOT IN ('cancelled', 'no-show')
              AND b.amount_paid > COALESCE(ledger.recorded_amount, 0)
              AND b.created_at >= DATE_FORMAT(CURRENT_DATE - INTERVAL 5 MONTH, '%Y-%m-01')
            GROUP BY DATE_FORMAT(b.created_at, '%Y-%m')
        ) collections
        GROUP BY collections.ym
        ORDER BY collections.ym
    ");
    $trendStmt->execute([$hotelId, $hotelId]);
    foreach($trendStmt->fetchAll(PDO::FETCH_ASSOC) as $r) $trendMap[$r['ym']] = (float)$r['amount'];
}
for($i=5;$i>=0;$i--){$ts=strtotime("first day of -$i month");$key=date('Y-m',$ts);$trendLabels[]=date('M',$ts);$trendValues[]=$trendMap[$key]??0;}
$maxTrend=max(1.0,...$trendValues);

$notice=$_SESSION['ho_payment_notice']??null; unset($_SESSION['ho_payment_notice']);
$linkQuery=$_GET; unset($linkQuery['page'],$linkQuery['export']);
$exportQuery=$_GET; unset($exportQuery['page']); $exportQuery['export']='csv';
$hoPaymentHeaderActions = true;
$hoPaymentExportUrl = '?' . http_build_query($exportQuery);
$hoPaymentCanCollect = !empty($balances);
?>
<!DOCTYPE html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Payments & Transactions | Hotel Admin</title><link rel="icon" href="img/newlogo.png"><link rel="stylesheet" href="styles/Ho_panel.css?v=notifications-4"><link rel="stylesheet" href="styles/Ho_payments.css?v=10"><link rel="stylesheet" href="styles/admin_receipt.css?v=2"></head>
<body class="ho-body"><div class="ho-layout">
<?php include __DIR__.'/Ho_sidebar.php'; ?>
<main class="ho-main"><div class="ho-payment-topbar"><?php include __DIR__.'/Ho_header.php'; ?></div>
<section class="hp-workspace">
  <?php if(is_array($notice)): ?><div class="hp-notice <?= ($notice['type']??'')==='success'?'success':'error' ?>" role="alert"><strong><?= ($notice['type']??'')==='success'?'Payment saved':'Unable to save payment' ?></strong><span><?= htmlspecialchars((string)$notice['message']) ?></span><button type="button" aria-label="Dismiss">×</button></div><?php endif; ?>
  <section class="hp-kpi-grid">
    <article class="hp-kpi collected" tabindex="0" data-kpi-target="transactions" title="Open the transaction ledger"><div class="hp-kpi-head"><span>Lifetime collected</span><i><svg viewBox="0 0 24 24"><path d="M4 7h16v12H4zM7 4h10M8 13h8"/></svg></i></div><strong><?= hoPayMoney($collected) ?></strong><?= hoPayTrendVisual($collectedCompare) ?><small><?= number_format($collectionRate,1) ?>% of booking value collected</small><div class="hp-kpi-month"><span>This month <b><?= hoPayMoney($currentCollected) ?></b></span><em class="<?= $collectedCompare['tone'] ?>"><?= htmlspecialchars($collectedCompare['text']) ?></em></div><div class="hp-progress"><span style="width:<?= $collectionRate ?>%"></span></div><button type="button" class="hp-kpi-link">View transactions <span>→</span></button></article>
    <article class="hp-kpi outstanding" tabindex="0" data-kpi-target="receivables" title="Open outstanding room-booking balances"><div class="hp-kpi-head"><span>Accounts receivable</span><i><svg viewBox="0 0 24 24"><path d="M12 7v5l3 2"/><circle cx="12" cy="12" r="9"/></svg></i></div><strong><?= hoPayMoney($outstanding) ?></strong><?= hoPayTrendVisual($receivableCompare) ?><small><?= count($balances) ?> open balance<?= count($balances)===1?'':'s' ?></small><div class="hp-kpi-month"><span>New this month <b><?= hoPayMoney((float)($monthly['current_receivable']??0)) ?></b></span><em class="<?= $receivableCompare['tone'] ?>"><?= htmlspecialchars($receivableCompare['text']) ?></em></div><div class="hp-progress"><span style="width:<?= $totalValue>0?min(100,$outstanding/$totalValue*100):0 ?>%"></span></div><button type="button" class="hp-kpi-link">Review balances <span>→</span></button></article>
    <article class="hp-kpi paid" tabindex="0" data-kpi-target="transactions" title="Open successful payment transactions"><div class="hp-kpi-head"><span>Fully paid bookings</span><i><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="m8 12 2.5 2.5L16 9"/></svg></i></div><strong><?= number_format((int)($stats['paid_count']??0)) ?></strong><?= hoPayTrendVisual($paidCompare) ?><small><?= number_format((int)($stats['partial_count']??0)) ?> partial · <?= number_format((int)($stats['unpaid_count']??0)) ?> unpaid</small><div class="hp-kpi-month"><span>Paid this month <b><?= number_format((int)($monthly['current_paid']??0)) ?></b></span><em class="<?= $paidCompare['tone'] ?>"><?= htmlspecialchars($paidCompare['text']) ?></em></div><div class="hp-progress"><span style="width:<?= (int)($stats['booking_count']??0)>0?min(100,(int)($stats['paid_count']??0)/(int)$stats['booking_count']*100):0 ?>%"></span></div><button type="button" class="hp-kpi-link">View successful payments <span>→</span></button></article>
    <article class="hp-kpi overdue" tabindex="0" data-kpi-target="receivables" title="Open overdue and upcoming receivables"><div class="hp-kpi-head"><span>Overdue receivables</span><i><svg viewBox="0 0 24 24"><path d="M12 3 2.8 20h18.4L12 3Z"/><path d="M12 9v5M12 17h.01"/></svg></i></div><strong><?= hoPayMoney($overdueTotal) ?></strong><?= hoPayTrendVisual($overdueCompare) ?><small><?= $overdueCount ?> booking<?= $overdueCount===1?'':'s' ?> past check-in</small><div class="hp-kpi-month"><span>This month <b><?= hoPayMoney((float)($monthly['current_overdue']??0)) ?></b></span><em class="<?= $overdueCompare['tone'] ?>"><?= htmlspecialchars($overdueCompare['text']) ?></em></div><div class="hp-progress"><span style="width:<?= $outstanding>0?min(100,$overdueTotal/$outstanding*100):0 ?>%"></span></div><button type="button" class="hp-kpi-link">Review overdue balances <span>→</span></button></article>
  </section>

  <section class="hp-insights-grid">
    <article class="hp-card hp-trend"><header><div><span class="hp-kicker">COLLECTION PERFORMANCE</span><h3>Payments received</h3></div><span class="hp-period">Last 6 months</span></header><div class="hp-chart" aria-label="Six month payment collection chart"><?php foreach($trendValues as $i=>$value): ?><div class="hp-bar-col" title="<?= htmlspecialchars($trendLabels[$i].': '.hoPayMoney($value)) ?>"><strong><?= $value>0?hoPayMoney($value):'' ?></strong><div><span style="height:<?= $value>0?max(8,$value/$maxTrend*100):2 ?>%"></span></div><small><?= htmlspecialchars($trendLabels[$i]) ?></small></div><?php endforeach; ?></div></article>
    <article class="hp-card hp-receivable"><header><div><span class="hp-kicker">ACCOUNTS RECEIVABLE</span><h3>Balance aging</h3></div><span class="hp-count"><?= count($balances) ?> OPEN</span></header><div class="hp-ar-total"><span>Total outstanding</span><strong><?= hoPayMoney($outstanding) ?></strong></div><div class="hp-aging-bar"><span class="overdue" style="width:<?= $outstanding>0?$overdueTotal/$outstanding*100:0 ?>%"></span><span class="soon" style="width:<?= $outstanding>0?$dueSoonTotal/$outstanding*100:0 ?>%"></span><span class="future" style="width:<?= $outstanding>0?$futureTotal/$outstanding*100:0 ?>%"></span></div><div class="hp-aging-list"><div><i class="overdue"></i><span>Overdue</span><strong><?= hoPayMoney($overdueTotal) ?></strong></div><div><i class="soon"></i><span>Due within 7 days</span><strong><?= hoPayMoney($dueSoonTotal) ?></strong></div><div><i class="future"></i><span>Future stays</span><strong><?= hoPayMoney($futureTotal) ?></strong></div></div><button type="button" class="hp-text-btn" data-jump-receivables>Review outstanding bookings →</button></article>
  </section>

  <section class="hp-card hp-ledger" id="transactions"><header class="hp-ledger-head"><div><span class="hp-kicker">PAYMENT LEDGER</span><h3>Transactions</h3></div><div class="hp-tabs"><button class="active" type="button" data-finance-tab="transactions">Transactions <b><?= $transactionCount ?></b></button><button type="button" data-finance-tab="receivables">Receivables <b><?= count($balances) ?></b></button></div></header>
    <div data-finance-panel="transactions">
      <form class="hp-filters" method="get"><label class="hp-search"><svg viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><path d="m20 20-4-4"/></svg><input name="q" value="<?= htmlspecialchars($search) ?>" placeholder="Search guest or reference"></label><select name="status"><option value="all">All statuses</option><?php foreach(['paid','pending','failed','cancelled','expired'] as $v): ?><option value="<?= $v ?>" <?= $statusFilter===$v?'selected':'' ?>><?= hoPayLabel($v) ?></option><?php endforeach; ?></select><select name="method"><option value="all">All channels</option><option value="paymongo" <?= $methodFilter==='paymongo'?'selected':'' ?>>PayMongo</option><option value="offline" <?= $methodFilter==='offline'?'selected':'' ?>>Recorded at property</option><option value="cash" <?= $methodFilter==='cash'?'selected':'' ?>>Cash</option><option value="gcash" <?= $methodFilter==='gcash'?'selected':'' ?>>GCash</option><option value="bank_transfer" <?= $methodFilter==='bank_transfer'?'selected':'' ?>>Bank transfer</option></select><label class="hp-date"><span>From</span><input type="date" name="from" value="<?= htmlspecialchars($dateFrom) ?>"></label><label class="hp-date"><span>To</span><input type="date" name="to" value="<?= htmlspecialchars($dateTo) ?>"></label><button class="hp-btn primary compact">Apply</button><a class="hp-clear" href="Hopayments.php">Clear</a></form>
      <div class="hp-table-wrap"><table class="hp-table"><thead><tr><th>Transaction</th><th>Guest & booking</th><th>Room</th><th>Channel</th><th>Date</th><th class="right">Amount</th><th>Status</th><th></th></tr></thead><tbody>
      <?php if(!$transactions): ?><tr><td colspan="8"><div class="hp-empty"><svg viewBox="0 0 24 24"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 10h18M7 15h3"/></svg><strong>No transactions found</strong><span><?= $hasLedger?'Try changing the filters or record a property payment.':'The transaction ledger is not installed.' ?></span></div></td></tr><?php else: foreach($transactions as $tx): $rawStatus=strtolower((string)$tx['status']); $metadata=json_decode((string)($tx['metadata']??''),true); $source=is_array($metadata)?strtolower((string)($metadata['source']??'')):''; $collectionSource=match($source){'hotel_payment_page'=>'Recorded in hotel payment center','tourist_profile_balance'=>'Collected from tourist profile','booking_checkout'=>'Collected during room booking','hotel_booking_payment'=>'Collected in hotel booking panel',default=>strtolower((string)$tx['provider'])==='offline'?'Recorded by property staff':'Collected through online checkout'}; $txData=['reference'=>(string)$tx['merchant_reference'],'booking_reference'=>(string)$tx['booking_reference'],'guest'=>(string)$tx['guest_name'],'email'=>(string)$tx['email'],'room'=>(string)$tx['room_type'],'service'=>(string)$tx['room_type'].' at '.$propertyName,'property'=>$propertyName,'amount'=>hoPayMoney(((int)$tx['amount_minor'])/100),'status'=>hoPayLabel($rawStatus),'status_raw'=>$rawStatus,'provider'=>hoPayLabel((string)$tx['provider']),'method'=>hoPayLabel((string)$tx['payment_method_type']),'collection_source'=>$collectionSource,'created'=>date('F j, Y · g:i A',strtotime((string)$tx['created_at'])),'paid_at'=>$tx['paid_at']?date('F j, Y · g:i A',strtotime((string)$tx['paid_at'])):'—','date'=>date('F j, Y · g:i A',strtotime((string)($tx['paid_at']?:$tx['created_at']))),'booking_total'=>hoPayMoney((float)$tx['booking_total']),'booking_paid'=>hoPayMoney((float)$tx['booking_paid']),'booking_balance'=>hoPayMoney((float)$tx['booking_balance'])]; ?><tr><td><div class="hp-ref"><strong><?= htmlspecialchars((string)$tx['merchant_reference']) ?></strong><span>#<?= (int)$tx['payment_transaction_id'] ?></span></div></td><td><div class="hp-person"><strong><?= htmlspecialchars((string)$tx['guest_name']) ?></strong><span><?= htmlspecialchars((string)$tx['booking_reference']) ?> · <?= htmlspecialchars((string)$tx['email']) ?></span></div></td><td><strong><?= htmlspecialchars((string)$tx['room_type']) ?></strong><span class="hp-cell-sub">Check-in <?= date('M j, Y',strtotime((string)$tx['checkin_date'])) ?></span></td><td><strong><?= htmlspecialchars(hoPayLabel((string)$tx['provider'])) ?></strong><span class="hp-cell-sub"><?= htmlspecialchars(hoPayLabel((string)$tx['payment_method_type'])) ?></span></td><td><strong><?= date('M j, Y',strtotime((string)($tx['paid_at']?:$tx['created_at']))) ?></strong><span class="hp-cell-sub"><?= date('g:i A',strtotime((string)($tx['paid_at']?:$tx['created_at']))) ?></span></td><td class="right"><strong><?= hoPayMoney(((int)$tx['amount_minor'])/100) ?></strong><span class="hp-cell-sub"><?= htmlspecialchars((string)$tx['currency']) ?></span></td><td><span class="hp-status <?= htmlspecialchars($rawStatus) ?>"><i></i><?= htmlspecialchars(hoPayLabel($rawStatus)) ?></span></td><td><button class="hp-row-btn" type="button" data-transaction='<?= htmlspecialchars(json_encode($txData,JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT),ENT_QUOTES) ?>' aria-label="View transaction"><svg viewBox="0 0 24 24"><circle cx="5" cy="12" r="1"/><circle cx="12" cy="12" r="1"/><circle cx="19" cy="12" r="1"/></svg></button></td></tr><?php endforeach; endif; ?></tbody></table></div>
      <?php if($transactionCount>0): ?><footer class="hp-table-footer"><span>Showing <?= $offset+1 ?>–<?= min($offset+$perPage,$transactionCount) ?> of <?= $transactionCount ?></span><nav><a class="<?= $page<=1?'disabled':'' ?>" href="?<?= htmlspecialchars(http_build_query(array_merge($linkQuery,['page'=>max(1,$page-1)]))) ?>">‹</a><span>Page <?= $page ?> of <?= $totalPages ?></span><a class="<?= $page>=$totalPages?'disabled':'' ?>" href="?<?= htmlspecialchars(http_build_query(array_merge($linkQuery,['page'=>min($totalPages,$page+1)]))) ?>">›</a></nav></footer><?php endif; ?>
    </div>
    <div data-finance-panel="receivables" hidden><div class="hp-table-wrap"><table class="hp-table"><thead><tr><th>Booking</th><th>Guest</th><th>Room & stay</th><th class="right">Booking value</th><th class="right">Paid</th><th class="right">Balance</th><th>Aging</th><th></th></tr></thead><tbody><?php if(!$balances): ?><tr><td colspan="8"><div class="hp-empty"><strong>All balances are settled</strong><span>There are no outstanding room-booking receivables.</span></div></td></tr><?php else: foreach($balances as $b): $due=(string)$b['checkin_date'];$aging=$due<$today?'Overdue':($due<=$soon?'Due soon':'Future stay');$tone=$due<$today?'danger':($due<=$soon?'warning':'neutral'); $profileImage=hoPaymentProfileImage($b['tourist_profile_picture']??null,$b['tourist_google_id']??null); $bookingData=['id'=>(string)($b['booking_reference']?:'#'.$b['hotel_booking_id']),'booking_id'=>(int)$b['hotel_booking_id'],'guest'=>(string)$b['guest_name'],'profile_image'=>$profileImage,'hotel'=>(string)($b['hotel_name']?:$propertyName),'room_type'=>(string)$b['room_type'],'checkin'=>(string)$b['checkin_date'],'checkout'=>(string)$b['checkout_date'],'rooms'=>(int)($b['rooms_booked']??1),'adults'=>(int)($b['adults']??0),'children'=>(int)($b['children']??0),'phone'=>(string)($b['phone_number']??''),'email'=>(string)$b['email'],'special_request'=>(string)($b['special_request']??''),'total'=>(float)$b['total_amount'],'amount_paid'=>(float)$b['amount_paid'],'remaining_balance'=>(float)$b['remaining_balance'],'payment_type'=>(string)($b['payment_type']??'full'),'payment_status'=>(string)($b['payment_status']??'unpaid'),'booking_status'=>(string)($b['booking_status']??'pending'),'created_at'=>(string)($b['created_at']??'')]; ?><tr><td><div class="hp-ref"><strong><?= htmlspecialchars((string)($b['booking_reference']?:'#'.$b['hotel_booking_id'])) ?></strong><span><?= htmlspecialchars(hoPayLabel((string)$b['payment_status'])) ?></span></div></td><td><div class="hp-person"><strong><?= htmlspecialchars((string)$b['guest_name']) ?></strong><span><?= htmlspecialchars((string)$b['email']) ?></span></div></td><td><strong><?= htmlspecialchars((string)$b['room_type']) ?></strong><span class="hp-cell-sub"><?= date('M j',strtotime((string)$b['checkin_date'])) ?>–<?= date('M j, Y',strtotime((string)$b['checkout_date'])) ?></span></td><td class="right"><strong><?= hoPayMoney((float)$b['total_amount']) ?></strong></td><td class="right"><strong><?= hoPayMoney((float)$b['amount_paid']) ?></strong></td><td class="right"><strong class="hp-balance"><?= hoPayMoney((float)$b['remaining_balance']) ?></strong></td><td><span class="hp-aging <?= $tone ?>"><?= $aging ?></span></td><td><div class="hp-receivable-actions"><button class="hp-view-booking" type="button" data-view-booking='<?= htmlspecialchars(json_encode($bookingData,JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT),ENT_QUOTES) ?>'>View Details</button><button class="hp-collect-link" type="button" data-collect-booking="<?= (int)$b['hotel_booking_id'] ?>" data-reference="<?= htmlspecialchars((string)$b['booking_reference']) ?>" data-guest="<?= htmlspecialchars((string)$b['guest_name']) ?>" data-balance="<?= number_format((float)$b['remaining_balance'],2,'.','') ?>">Collect</button></div></td></tr><?php endforeach; endif; ?></tbody></table></div></div>
  </section>
</section><?php include __DIR__.'/Ho_footer.php'; ?></main></div>

<div class="ho-booking-details-overlay" id="hpBookingDetails" aria-hidden="true" inert><aside class="ho-booking-details-drawer" role="dialog" aria-modal="true" aria-labelledby="hpBookingDetailsTitle"><header class="ho-booking-details-header"><div class="ho-booking-drawer-brand"><img src="img/newlogo.png" alt=""><div><span>ITOUR MERCEDES</span><h3 id="hpBookingDetailsTitle">Booking Details</h3></div></div><button type="button" class="ho-booking-details-close" data-close-booking-details aria-label="Close booking details"><svg viewBox="0 0 24 24"><path d="m6 6 12 12M18 6 6 18"/></svg></button></header><div class="ho-booking-details-body" id="hpBookingDetailsBody"></div><footer class="ho-booking-details-footer"><button type="button" class="ho-booking-details-close-btn" data-close-booking-details>Close Details</button></footer></aside></div>

<div class="hp-modal" id="hpCollectModal" aria-hidden="true"><div class="hp-modal-backdrop" data-close-collect></div><section class="hp-modal-card" role="dialog" aria-modal="true" aria-labelledby="hpCollectTitle"><header><div><span class="hp-modal-icon">₱</span><div><span class="hp-kicker">PAYMENT COLLECTION</span><h3 id="hpCollectTitle">Pay Balance</h3><p>Collect a cash or PayMongo payment for an outstanding room booking.</p></div></div><button type="button" data-close-collect>×</button></header><form method="post" id="hpCollectForm"><input type="hidden" name="action" value="record_payment"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>"><div class="hp-selected" id="hpSelectedBooking"><span>Select an outstanding booking below</span><strong>No booking selected</strong></div><label><span>Outstanding booking</span><select name="booking_id" id="hpBookingSelect" required><option value="">Choose a booking…</option><?php foreach($balances as $b): ?><option value="<?= (int)$b['hotel_booking_id'] ?>" data-balance="<?= number_format((float)$b['remaining_balance'],2,'.','') ?>" data-guest="<?= htmlspecialchars((string)$b['guest_name']) ?>" data-reference="<?= htmlspecialchars((string)$b['booking_reference']) ?>"><?= htmlspecialchars((string)$b['booking_reference'].' — '.$b['guest_name'].' — '.hoPayMoney((float)$b['remaining_balance'])) ?></option><?php endforeach; ?></select></label><p class="hp-payment-context" id="hpPaymentContext">Record the amount received. Partial payments are allowed.</p><div class="hp-form-grid"><label><span>Payment method</span><select name="payment_method" id="hpPaymentMethod" required><option value="cash">Cash</option><option value="qr_code">QR Code (PayMongo)</option></select></label><label><span>Amount paid</span><div class="hp-money-input"><i>₱</i><input type="number" name="amount" id="hpPaymentAmount" min="0.01" step="0.01" placeholder="0.00" required></div><small>Cannot exceed the outstanding balance.</small></label></div><section class="hp-phone-status" id="hpPhoneStatus" hidden><span class="hp-phone-icon" aria-hidden="true">▮</span><div><small>REGISTERED HOTEL ADMIN PHONE</small><strong id="hpPhoneName">Checking registered phone…</strong><span id="hpPhoneMeta">Please wait.</span></div><button type="button" id="hpManagePhone">Change</button></section><div class="hp-security"><svg viewBox="0 0 24 24"><path d="M12 3 5 6v5c0 4.6 2.8 8 7 10 4.2-2 7-5.4 7-10V6l-7-3Z"/><path d="m9 12 2 2 4-4"/></svg><p>Cash payments are recorded immediately. PayMongo payments update the booking only after secure provider verification.</p></div><footer><button class="hp-btn secondary" type="button" data-close-collect>Cancel</button><button class="hp-btn primary" type="submit" id="hpConfirmPayment">Confirm payment</button></footer></form></section></div>
<div class="hp-drawer" id="hpTransactionDrawer" aria-hidden="true"><div class="hp-modal-backdrop" data-close-drawer></div><aside role="dialog" aria-modal="true"><header><div><span class="hp-kicker">TRANSACTION DETAILS</span><h3>Payment record</h3></div><button type="button" data-close-drawer>×</button></header><div id="hpDrawerBody" class="hp-drawer-body"></div><footer><button class="hp-btn secondary" type="button" id="hpViewReceipt" hidden>View receipt</button><button class="hp-btn primary" type="button" data-close-drawer>Done</button></footer></aside></div>
<div id="hpReceiptModal" class="admin-receipt-overlay" aria-hidden="true" inert><section class="admin-receipt-shell" role="dialog" aria-modal="true" aria-labelledby="hpReceiptTitle"><header class="admin-receipt-head"><div><span>OFFICIAL PAYMENT RECORD</span><h3 id="hpReceiptTitle">Payment Receipt</h3></div><button type="button" class="admin-receipt-x" data-close-receipt aria-label="Close receipt">×</button></header><div class="admin-receipt-stage"><article class="admin-receipt-paper" id="hpReceiptPaper"></article></div><footer class="admin-receipt-footer"><button type="button" class="admin-receipt-btn secondary" data-close-receipt>Close</button><button type="button" class="admin-receipt-btn print" id="hpPrintReceipt">Print</button><button type="button" class="admin-receipt-btn primary" id="hpDownloadReceipt">Download PDF</button></footer></section></div>
<script>window.hoPaymentConfig=<?= json_encode(['csrf'=>$payMongoCsrf,'notificationCsrf'=>$hotelNotificationCsrf,'checkoutEndpoint'=>'payments/create-balance-checkout.php','statusEndpoint'=>'Hobookings.php?ho_action=paymongo_payment_status','phoneStatusEndpoint'=>'hotel-push-device-status.php','phoneSetupPage'=>'hotel-admin-phone-setup.php','publicAppUrl'=>(string)($hoFirebaseConfiguration['app_url']??'')],JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;</script><script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script><script src="https://html2canvas.hertzen.com/dist/html2canvas.min.js"></script><script src="js/Ho_payments.js?v=8"></script></body></html>
