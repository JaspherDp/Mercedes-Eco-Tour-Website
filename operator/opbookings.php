<?php
chdir(__DIR__ . '/..');
session_start();
require 'php/db_connection.php';

if (!isset($_SESSION['operator_id'])) {
    echo "<script>alert('Session expired. Please login.'); window.location.href='php/operator_login.php';</script>";
    exit();
}

$operator_id = (int)$_SESSION['operator_id'];
$operatorName = $_SESSION['operator_name'] ?? 'Operator';
$opProfilePicFile = trim((string)($_SESSION['operator_profile'] ?? ''));
$opHeaderProfilePic = null;
if ($opProfilePicFile !== '' && strtolower($opProfilePicFile) !== 'img/profileicon.png' && file_exists($opProfilePicFile)) {
    $opHeaderProfilePic = $opProfilePicFile;
}
$opProfileInitial = strtoupper(substr(trim((string)$operatorName) !== '' ? trim((string)$operatorName) : 'O', 0, 1));

if (isset($_POST['op_action']) && $_POST['op_action'] === 'mark_notifications_read') {
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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'], $_POST['booking_id'])) {
    $bookingId = (int)$_POST['booking_id'];
    $action = (string)$_POST['action'];

    if ($bookingId > 0 && in_array($action, ['confirm', 'cancel'], true)) {
        $newStatus = $action === 'confirm' ? 'accepted' : 'cancelled';
        $stmt = $pdo->prepare("
            UPDATE bookings
            SET status = ?, updated_at = NOW()
            WHERE booking_id = ? AND operator_id = ?
        ");
        $stmt->execute([$newStatus, $bookingId, $operator_id]);
    }

    $redirectQuery = http_build_query([
        'status' => $_GET['status'] ?? 'all',
        'q' => $_GET['q'] ?? '',
        'range' => $_GET['range'] ?? 'all',
        'year' => $_GET['year'] ?? date('Y'),
        'month' => $_GET['month'] ?? date('n'),
        'date' => $_GET['date'] ?? date('Y-m-d'),
        'sort' => $_GET['sort'] ?? 'time',
        'rows' => $_GET['rows'] ?? '25',
    ]);
    header('Location: opbookings.php' . ($redirectQuery ? '?' . $redirectQuery : ''));
    exit;
}

$statusFilter = strtolower(trim((string)($_GET['status'] ?? 'all')));
$search = trim((string)($_GET['q'] ?? ''));
$rangeFilter = strtolower(trim((string)($_GET['range'] ?? 'all')));
$sortBy = strtolower(trim((string)($_GET['sort'] ?? 'time')));
$rowsRaw = trim((string)($_GET['rows'] ?? '25'));
$rowsPerPage = (int)$rowsRaw;
if ($rowsPerPage < 1) {
    $rowsPerPage = 25;
}
if ($rowsPerPage > 300) {
    $rowsPerPage = 300;
}

$validStatus = ['all', 'pending', 'accepted', 'cancelled'];
if (!in_array($statusFilter, $validStatus, true)) {
    $statusFilter = 'all';
}
$validRange = ['all', 'yearly', 'monthly', 'weekly', 'daily'];
if (!in_array($rangeFilter, $validRange, true)) {
    $rangeFilter = 'all';
}
$validSort = ['time', 'name'];
if (!in_array($sortBy, $validSort, true)) {
    $sortBy = 'time';
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

$availableYears = $pdo->prepare("
    SELECT DISTINCT YEAR(created_at) AS yr
    FROM bookings
    WHERE created_at IS NOT NULL AND operator_id = ?
    ORDER BY yr DESC
");
$availableYears->execute([$operator_id]);
$availableYears = array_values(array_filter(array_map('intval', $availableYears->fetchAll(PDO::FETCH_COLUMN))));
if (empty($availableYears)) {
    $availableYears = [$currentYear];
}
if (!in_array($selectedYear, $availableYears, true)) {
    $selectedYear = $availableYears[0];
}

function OpResolveBookerProfileImage(?string $profilePicture): string
{
    $profilePicture = trim((string)$profilePicture);
    if ($profilePicture === '') {
        return '';
    }

    if (preg_match('#^https?://#i', $profilePicture)) {
        if (stripos($profilePicture, 'profiles.google.com') !== false && preg_match('#profiles\\.google\\.com/(?:s2/photos/profile/)?([^/?#]+)(?:/picture)?#i', $profilePicture, $m)) {
            return 'https://profiles.google.com/' . rawurlencode($m[1]) . '/picture?sz=256';
        }
        if (stripos($profilePicture, 'googleusercontent.com') !== false) {
            $profilePicture = preg_replace('/([?&])sz=\\d+/i', '$1sz=256', $profilePicture);
            $profilePicture = preg_replace('/=s\\d+-c(?=$|[?&#])/i', '=s256-c', $profilePicture);
            $profilePicture = preg_replace('/=s\\d+(?=$|[?&#])/i', '=s256', $profilePicture);
        }
        return $profilePicture;
    }

    $clean = ltrim($profilePicture, '/');
    $paths = [
        'uploads/profile_pictures/' . basename($clean),
        'uploads/profile_picture/' . basename($clean),
        $clean,
    ];

    foreach ($paths as $p) {
        $local = __DIR__ . '/../' . str_replace('/', DIRECTORY_SEPARATOR, $p);
        if (file_exists($local)) {
            return $p;
        }
    }

    return '';
}

function OpBuildGoogleAvatarUrl(?string $googleId): string
{
    $googleId = trim((string)$googleId);
    if ($googleId === '') {
        return '';
    }
    return 'https://profiles.google.com/' . rawurlencode($googleId) . '/picture?sz=256';
}

$where = ['b.operator_id = :operator_id'];
$params = [':operator_id' => $operator_id];

if ($statusFilter !== 'all') {
    $where[] = 'LOWER(b.status) = :status';
    $params[':status'] = $statusFilter;
}

if ($search !== '') {
    $where[] = "(COALESCE(t.full_name, '') LIKE :search OR CAST(b.booking_id AS CHAR) LIKE :search OR COALESCE(b.package_name, '') LIKE :search)";
    $params[':search'] = '%' . $search . '%';
}
if ($rangeFilter === 'yearly') {
    $where[] = 'YEAR(b.created_at) = :selected_year';
    $params[':selected_year'] = $selectedYear;
} elseif ($rangeFilter === 'monthly') {
    $where[] = 'YEAR(b.created_at) = :selected_year AND MONTH(b.created_at) = :selected_month';
    $params[':selected_year'] = $selectedYear;
    $params[':selected_month'] = $selectedMonth;
} elseif ($rangeFilter === 'daily') {
    $where[] = 'DATE(b.created_at) = :selected_date';
    $params[':selected_date'] = $selectedDate;
} elseif ($rangeFilter === 'weekly') {
    $weekDate = DateTime::createFromFormat('Y-m-d', $selectedDate) ?: new DateTime();
    $weekDate->setTime(0, 0, 0);
    $weekStart = (clone $weekDate)->modify('monday this week');
    $weekEnd = (clone $weekStart)->modify('+6 days');
    $where[] = 'DATE(b.created_at) BETWEEN :week_start AND :week_end';
    $params[':week_start'] = $weekStart->format('Y-m-d');
    $params[':week_end'] = $weekEnd->format('Y-m-d');
}

$whereSql = 'WHERE ' . implode(' AND ', $where);
$orderBySql = $sortBy === 'name'
    ? 'COALESCE(t.full_name, \'\') ASC, b.created_at DESC'
    : 'b.created_at DESC';

$sql = "
SELECT
  b.booking_id,
  b.package_name,
  b.booking_date,
  b.status,
  b.phone_number,
  b.location,
  b.pax,
  b.booking_type,
  b.jump_off_port,
  b.is_complete,
  b.created_at,
  COALESCE(NULLIF(TRIM(t.full_name), ''), CONCAT('Tourist #', b.tourist_id)) AS guest_name,
  COALESCE(NULLIF(TRIM(t.email), ''), '-') AS guest_email,
  t.profile_picture AS tourist_profile_picture,
  t.google_id AS tourist_google_id
FROM bookings b
LEFT JOIN tourist t ON t.tourist_id = b.tourist_id
$whereSql
ORDER BY $orderBySql
LIMIT " . (int)$rowsPerPage;

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$bookings = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>iTour Mercedes - Operator Bookings</title>
  <link rel="icon" type="image/png" href="img/newlogo.png" />
  <link rel="stylesheet" href="styles/Ho_panel.css" />
  <style>
    .op-layout {
      display: flex;
      min-height: 100vh;
    }
    .op-main {
      margin-left: 250px;
      padding: 86px 18px 24px;
      flex: 1;
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
    }
    .operator-header-left h2 {
      margin: 0;
      font-size: 23px;
      font-weight: 700;
      color: #1d5d4a;
      letter-spacing: 0.01em;
    }
    .operator-header-left p {
      margin: 3px 0 0;
      color: #60707a;
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
      border: 1px solid #d8e6e0;
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
      border: 1px solid #d8e6e0;
      background: #fff;
      border-radius: 10px;
      padding: 8px 12px;
      font: inherit;
      font-size: 13px;
      font-weight: 600;
      color: #24434d;
      cursor: pointer;
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
      border: 1px solid #d8e6e0;
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
      border: 1px solid #d8e6e0;
      border-radius: 12px;
      box-shadow: 0 12px 30px rgba(17, 67, 53, 0.08);
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
    .op-notif-empty { margin: 0; color: #60707a; font-size: 13px; padding: 8px 4px; }
    .ho-content {
      margin-top: 10px;
    }
    .ho-status.accepted {
      background: #e6f8ef;
      color: #1f6e4a;
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
      .ho-toolbar {
        flex-wrap: wrap;
      }
      .ho-toolbar input[name="q"] {
        min-width: 100%;
      }
    }
  </style>
</head>
<body class="ho-body">
  <div class="op-layout">
    <?php include 'operator_sidebar.php'; ?>

    <main class="op-main">
      <header class="operator-header">
        <div class="operator-header-left">
          <h2>Booking Management</h2>
          <p>Welcome, <?= htmlspecialchars((string)$operatorName) ?></p>
        </div>
        <div class="operator-header-right">
          <form method="get" class="op-global-filter-form">
            <input type="hidden" name="status" value="<?= htmlspecialchars($statusFilter) ?>" />
            <input type="hidden" name="sort" value="<?= htmlspecialchars($sortBy) ?>" />
            <input type="hidden" name="q" value="<?= htmlspecialchars($search) ?>" />
            <input type="hidden" name="rows" value="<?= (int)$rowsPerPage ?>" />
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
          <div class="op-topbar-profile" title="<?= htmlspecialchars((string)$operatorName) ?>">
            <?php if ($opHeaderProfilePic): ?>
              <img src="<?= htmlspecialchars($opHeaderProfilePic) ?>" alt="<?= htmlspecialchars((string)$operatorName) ?>">
            <?php else: ?>
              <?= htmlspecialchars($opProfileInitial) ?>
            <?php endif; ?>
          </div>
        </div>
      </header>

      <section class="ho-content">
        <article class="ho-card ho-table-card">
          <div class="ho-table-head">
            <h2 class="ho-section-title">All Bookings</h2>
            <div class="ho-booking-tabs" role="tablist" aria-label="Booking status quick tabs">
              <a href="opbookings.php?<?= htmlspecialchars(http_build_query(['status' => 'all', 'q' => $search, 'range' => $rangeFilter, 'year' => $selectedYear, 'month' => $selectedMonth, 'date' => $selectedDate, 'sort' => $sortBy, 'rows' => $rowsPerPage])) ?>" class="<?= $statusFilter === 'all' ? 'active' : '' ?>">All</a>
              <a href="opbookings.php?<?= htmlspecialchars(http_build_query(['status' => 'pending', 'q' => $search, 'range' => $rangeFilter, 'year' => $selectedYear, 'month' => $selectedMonth, 'date' => $selectedDate, 'sort' => $sortBy, 'rows' => $rowsPerPage])) ?>" class="<?= $statusFilter === 'pending' ? 'active' : '' ?>">Pending</a>
              <a href="opbookings.php?<?= htmlspecialchars(http_build_query(['status' => 'accepted', 'q' => $search, 'range' => $rangeFilter, 'year' => $selectedYear, 'month' => $selectedMonth, 'date' => $selectedDate, 'sort' => $sortBy, 'rows' => $rowsPerPage])) ?>" class="<?= $statusFilter === 'accepted' ? 'active' : '' ?>">Accepted</a>
              <a href="opbookings.php?<?= htmlspecialchars(http_build_query(['status' => 'cancelled', 'q' => $search, 'range' => $rangeFilter, 'year' => $selectedYear, 'month' => $selectedMonth, 'date' => $selectedDate, 'sort' => $sortBy, 'rows' => $rowsPerPage])) ?>" class="<?= $statusFilter === 'cancelled' ? 'active' : '' ?>">Cancelled</a>
            </div>
          </div>

          <form method="get" class="ho-toolbar">
            <input type="hidden" name="range" value="<?= htmlspecialchars($rangeFilter) ?>" />
            <input type="hidden" name="year" value="<?= (int)$selectedYear ?>" />
            <input type="hidden" name="month" value="<?= (int)$selectedMonth ?>" />
            <input type="hidden" name="date" value="<?= htmlspecialchars($selectedDate) ?>" />
            <span class="ho-rows-label">Rows</span>
            <input type="number" name="rows" min="1" max="300" step="1" value="<?= (int)$rowsPerPage ?>" list="hoRowsOptions" placeholder="25" title="Rows count" />
            <datalist id="hoRowsOptions">
              <option value="25"></option>
              <option value="50"></option>
              <option value="100"></option>
              <option value="150"></option>
              <option value="200"></option>
              <option value="250"></option>
              <option value="300"></option>
            </datalist>
            <select name="status">
              <option value="all" <?= $statusFilter === 'all' ? 'selected' : '' ?>>All Statuses</option>
              <option value="pending" <?= $statusFilter === 'pending' ? 'selected' : '' ?>>Pending</option>
              <option value="accepted" <?= $statusFilter === 'accepted' ? 'selected' : '' ?>>Accepted</option>
              <option value="cancelled" <?= $statusFilter === 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
            </select>
            <select name="sort">
              <option value="time" <?= $sortBy === 'time' ? 'selected' : '' ?>>Sort: Latest (Default)</option>
              <option value="name" <?= $sortBy === 'name' ? 'selected' : '' ?>>Sort: Name (A-Z)</option>
            </select>
            <button type="submit" class="ho-btn">Apply Filter</button>
            <input type="text" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="Search by guest name, package, or booking ID" />
          </form>

          <?php if ($bookings): ?>
            <div class="ho-table-wrap">
              <table class="ho-table">
                <thead>
                  <tr>
                    <th>Booking ID</th>
                    <th>Booker</th>
                    <th>Package</th>
                    <th>Date</th>
                    <th>Pax</th>
                    <th>Status</th>
                    <th>Actions</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($bookings as $row): ?>
                    <?php $status = strtolower((string)$row['status']); ?>
                    <?php
                      $profilePath = OpResolveBookerProfileImage($row['tourist_profile_picture'] ?? null);
                      if ($profilePath === '') {
                          $profilePath = OpBuildGoogleAvatarUrl($row['tourist_google_id'] ?? null);
                      }
                      $hasAvatar = $profilePath !== '';
                      $guestName = (string)$row['guest_name'];
                      $guestInitial = strtoupper(substr(trim($guestName) !== '' ? trim($guestName) : 'G', 0, 1));
                    ?>
                    <tr>
                      <td class="ho-cell-center">#<?= (int)$row['booking_id'] ?></td>
                      <td>
                        <div class="ho-booker-cell">
                          <?php if ($hasAvatar): ?>
                            <img src="<?= htmlspecialchars($profilePath) ?>" alt="Booker profile" class="ho-booker-avatar" onerror="this.onerror=null;this.src='../img/profileicon.png';" />
                          <?php else: ?>
                            <div class="ho-booker-avatar ho-booker-avatar-initial" aria-label="Booker initial"><?= htmlspecialchars($guestInitial) ?></div>
                          <?php endif; ?>
                          <div>
                            <?= htmlspecialchars($guestName) ?><br />
                            <small><?= htmlspecialchars((string)$row['guest_email']) ?></small>
                          </div>
                        </div>
                      </td>
                      <td class="ho-cell-center"><?= htmlspecialchars((string)$row['package_name']) ?></td>
                      <td class="ho-cell-center"><?= htmlspecialchars((string)$row['booking_date']) ?></td>
                      <td class="ho-cell-center"><?= (int)$row['pax'] ?></td>
                      <td class="ho-cell-center"><span class="ho-status <?= htmlspecialchars($status) ?>"><?= htmlspecialchars(ucfirst($status)) ?></span></td>
                      <td>
                        <div class="ho-actions ho-actions-center">
                          <button
                            type="button"
                            class="ho-btn"
                            data-view
                            data-booking='<?= htmlspecialchars(json_encode([
                              'id' => (int)$row['booking_id'],
                              'guest' => (string)$row['guest_name'],
                              'email' => (string)$row['guest_email'],
                              'package' => (string)$row['package_name'],
                              'date' => (string)$row['booking_date'],
                              'phone' => (string)$row['phone_number'],
                              'location' => (string)$row['location'],
                              'pax' => (int)$row['pax'],
                              'booking_type' => (string)$row['booking_type'],
                              'jump_off_port' => (string)$row['jump_off_port'],
                              'status' => (string)$row['status'],
                              'completion' => (string)$row['is_complete'],
                              'created_at' => (string)$row['created_at'],
                            ]), ENT_QUOTES) ?>'
                          >View Details</button>

                          <?php if ($status !== 'accepted'): ?>
                            <form method="post">
                              <input type="hidden" name="action" value="confirm" />
                              <input type="hidden" name="booking_id" value="<?= (int)$row['booking_id'] ?>" />
                              <button type="submit" class="ho-btn confirm">Accept</button>
                            </form>
                          <?php endif; ?>

                          <?php if ($status !== 'cancelled'): ?>
                            <form method="post" data-cancel-form>
                              <input type="hidden" name="action" value="cancel" />
                              <input type="hidden" name="booking_id" value="<?= (int)$row['booking_id'] ?>" />
                              <button type="submit" class="ho-btn cancel">Cancel</button>
                            </form>
                          <?php endif; ?>
                        </div>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php else: ?>
            <div class="ho-empty">No bookings found for the selected filter/search.</div>
          <?php endif; ?>

          <p class="ho-footnote">Default sort is latest added; switch to Name (A-Z) to sort alphabetically.</p>
        </article>
      </section>
    </main>
  </div>

  <div class="ho-modal" id="hoDetailsModal" aria-hidden="true">
    <div class="ho-modal-card">
      <div class="ho-modal-head">
        <h3>Booking Details</h3>
        <button type="button" class="ho-close" id="hoCloseModal">&times;</button>
      </div>
      <div class="ho-detail-grid" id="hoDetailGrid"></div>
    </div>
  </div>

  <script>
    (function () {
      const notifToggle = document.getElementById('opNotifToggle');
      const notifPanel = document.getElementById('opNotifPanel');
      const notifBadge = document.querySelector('.op-notif-badge');
      const range = document.getElementById('opRangeFilter');
      const year = document.getElementById('opRangeYear');
      const month = document.getElementById('opRangeMonth');
      const date = document.getElementById('opRangeDate');
      let notifMarked = false;
      const updateRangeVisibility = () => {
        const r = range ? range.value : 'all';
        if (year) year.style.display = (r === 'yearly' || r === 'monthly' || r === 'daily') ? '' : 'none';
        if (month) month.style.display = (r === 'monthly') ? '' : 'none';
        if (date) date.style.display = (r === 'daily') ? '' : 'none';
      };
      if (range) range.addEventListener('change', updateRangeVisibility);
      updateRangeVisibility();

      const markNotificationsRead = async () => {
        if (notifMarked) return;
        notifMarked = true;
        if (notifBadge) notifBadge.remove();
        const body = new URLSearchParams();
        body.set('op_action', 'mark_notifications_read');
        try {
          await fetch('opbookings.php', {
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

      const modal = document.getElementById('hoDetailsModal');
      const closeBtn = document.getElementById('hoCloseModal');
      const grid = document.getElementById('hoDetailGrid');
      if (!modal || !closeBtn || !grid) return;

      const labels = [
        ['Booking ID', 'id'],
        ['Guest', 'guest'],
        ['Email', 'email'],
        ['Package', 'package'],
        ['Booking Date', 'date'],
        ['Phone', 'phone'],
        ['Location', 'location'],
        ['Pax', 'pax'],
        ['Booking Type', 'booking_type'],
        ['Jump Off Port', 'jump_off_port'],
        ['Status', 'status'],
        ['Completion', 'completion'],
        ['Created At', 'created_at'],
      ];

      document.querySelectorAll('[data-view]').forEach(btn => {
        btn.addEventListener('click', () => {
          const raw = btn.getAttribute('data-booking');
          if (!raw) return;
          const data = JSON.parse(raw);
          grid.innerHTML = labels.map(([label, key]) => `
            <div class="ho-detail-item">
              <strong>${label}</strong>
              <span>${String(data[key] ?? '-')}</span>
            </div>
          `).join('');
          modal.classList.add('open');
        });
      });

      closeBtn.addEventListener('click', () => modal.classList.remove('open'));
      modal.addEventListener('click', (e) => {
        if (e.target === modal) modal.classList.remove('open');
      });

      document.querySelectorAll('[data-cancel-form]').forEach(form => {
        form.addEventListener('submit', (e) => {
          const ok = window.confirm('Are you sure you want to cancel this booking?');
          if (!ok) e.preventDefault();
        });
      });
    })();
  </script>
</body>
</html>

