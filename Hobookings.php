<?php
require_once __DIR__ . '/Ho_common.php';

$hoAdmin = HoRequireHotelAdmin($pdo);
$hoHotelResortId = (int)$hoAdmin['hotel_resort_id'];
$hoPropertyName = trim((string)($hoAdmin['property_name'] ?? ''));

$hoActive = 'bookings';
$hoTitle = 'Booking Management';
$hoOwnerName = $hoPropertyName !== '' ? $hoPropertyName . ' Admin' : (string)$hoAdmin['username'];
$hoUnreadBadge = HoGetUnreadCount($pdo, $hoHotelResortId);
$hoNotifItems = HoGetNotificationItems($pdo, 8, $hoHotelResortId);
$hoShowRangeFilter = true;

if (isset($_POST['ho_action']) && $_POST['ho_action'] === 'mark_notifications_read') {
    HoMarkNotificationsRead($pdo, $hoHotelResortId);
    header('Content-Type: application/json');
    echo json_encode(['ok' => true]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'], $_POST['booking_id'])) {
    $bookingId = (int)$_POST['booking_id'];
    $action = (string)$_POST['action'];
    $actionNotice = 'action-failed';

    if ($bookingId > 0 && in_array($action, ['confirm', 'cancel', 'no_show'], true)) {
        $newStatus = $action === 'confirm' ? 'confirmed' : ($action === 'cancel' ? 'cancelled' : 'no-show');
        $stmt = $pdo->prepare("
            UPDATE hotel_room_bookings
            SET booking_status = ?, updated_at = NOW()
            WHERE hotel_booking_id = ? AND hotel_resort_id = ?
        ");
        $stmt->execute([$newStatus, $bookingId, $hoHotelResortId]);
        if ($stmt->rowCount() > 0) {
            $actionNotice = $action === 'confirm'
                ? 'booking-confirmed'
                : ($action === 'cancel' ? 'booking-cancelled' : 'booking-noshow');
        } else {
            $actionNotice = 'booking-not-updated';
        }
    } else {
        $actionNotice = 'invalid-action';
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
        'action_notice' => $actionNotice,
    ]);
    header('Location: Hobookings.php' . ($redirectQuery ? '?' . $redirectQuery : ''));
    exit;
}

$actionNotice = strtolower(trim((string)($_GET['action_notice'] ?? '')));
$actionNoticeText = '';
$actionNoticeIcon = '';
switch ($actionNotice) {
    case 'booking-confirmed':
        $actionNoticeText = 'Booking has been confirmed.';
        $actionNoticeIcon = 'success';
        break;
    case 'booking-cancelled':
        $actionNoticeText = 'Booking has been cancelled.';
        $actionNoticeIcon = 'success';
        break;
    case 'booking-noshow':
        $actionNoticeText = 'Booking has been marked as no-show.';
        $actionNoticeIcon = 'success';
        break;
    case 'booking-not-updated':
        $actionNoticeText = 'No booking record was updated.';
        $actionNoticeIcon = 'error';
        break;
    case 'invalid-action':
        $actionNoticeText = 'Invalid booking action.';
        $actionNoticeIcon = 'error';
        break;
    case 'action-failed':
        $actionNoticeText = 'Unable to process booking action.';
        $actionNoticeIcon = 'error';
        break;
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
$validStatus = ['all', 'pending', 'confirmed', 'completed', 'cancelled', 'no-show'];
if (!in_array($statusFilter, $validStatus, true)) {
    $statusFilter = 'all';
}
$validRange = ['all', 'yearly', 'monthly', 'daily'];
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

$hoRangeFilter = $rangeFilter;
$hoRangeYear = $selectedYear;
$hoRangeMonth = $selectedMonth;
$hoRangeDate = $selectedDate;
$hoRangeYears = $availableYears;
$hoRangeOptions = [
    'all' => 'All Time',
    'yearly' => 'Yearly',
    'monthly' => 'Monthly',
    'daily' => 'Daily',
];
$hoRangeHiddenFields = [
    'status' => $statusFilter,
    'sort' => $sortBy,
    'q' => $search,
    'rows' => $rowsPerPage,
];

function HoResolveProfileImage(?string $profilePicture): string
{
    if (!$profilePicture) {
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
        if (file_exists(__DIR__ . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $p))) {
            return $p;
        }
    }
    return '';
}

function HoBuildGoogleAvatarUrl(?string $googleId): string
{
    $id = trim((string)$googleId);
    if ($id === '') {
        return '';
    }
    return 'https://profiles.google.com/' . rawurlencode($id) . '/picture?sz=256';
}

function HoGetInitial(string $name): string
{
    $name = trim($name);
    if ($name === '') {
        return 'G';
    }
    return strtoupper(substr($name, 0, 1));
}

$where = ['b.hotel_resort_id = :hotel_resort_id'];
$params = [];
$params[':hotel_resort_id'] = $hoHotelResortId;
if ($statusFilter !== 'all') {
    $where[] = 'b.booking_status = :status';
    $params[':status'] = $statusFilter;
}
if ($rangeFilter === 'yearly') {
    $where[] = 'YEAR(b.created_at) = :selected_year';
    $params[':selected_year'] = $selectedYear;
} elseif ($rangeFilter === 'monthly') {
    $where[] = 'YEAR(b.created_at) = :selected_year AND MONTH(b.created_at) = :selected_month';
    $params[':selected_year'] = $selectedYear;
    $params[':selected_month'] = $selectedMonth;
} elseif ($rangeFilter === 'daily') {
    $where[] = 'DATE(b.created_at) = :selected_date AND YEAR(b.created_at) = :selected_year';
    $params[':selected_date'] = $selectedDate;
    $params[':selected_year'] = $selectedYear;
}
if ($search !== '') {
    $where[] = "(b.first_name LIKE :search_name OR b.last_name LIKE :search_name OR b.hotel_booking_id LIKE :search_id)";
    $params[':search_name'] = '%' . $search . '%';
    $params[':search_id'] = '%' . $search . '%';
}

$statsWhere = ['b.hotel_resort_id = :stats_hotel_resort_id'];
$statsParams = [':stats_hotel_resort_id' => $hoHotelResortId];
if ($rangeFilter === 'yearly') {
    $statsWhere[] = 'YEAR(b.created_at) = :stats_selected_year';
    $statsParams[':stats_selected_year'] = $selectedYear;
} elseif ($rangeFilter === 'monthly') {
    $statsWhere[] = 'YEAR(b.created_at) = :stats_selected_year AND MONTH(b.created_at) = :stats_selected_month';
    $statsParams[':stats_selected_year'] = $selectedYear;
    $statsParams[':stats_selected_month'] = $selectedMonth;
} elseif ($rangeFilter === 'daily') {
    $statsWhere[] = 'DATE(b.created_at) = :stats_selected_date AND YEAR(b.created_at) = :stats_selected_year';
    $statsParams[':stats_selected_date'] = $selectedDate;
    $statsParams[':stats_selected_year'] = $selectedYear;
}
if ($search !== '') {
    $statsWhere[] = "(b.first_name LIKE :stats_search_name OR b.last_name LIKE :stats_search_name OR b.hotel_booking_id LIKE :stats_search_id)";
    $statsParams[':stats_search_name'] = '%' . $search . '%';
    $statsParams[':stats_search_id'] = '%' . $search . '%';
}
$statsWhereSql = $statsWhere ? ('WHERE ' . implode(' AND ', $statsWhere)) : '';
$statsSql = "
    SELECT
      COUNT(*) AS total_bookings,
      SUM(CASE WHEN b.booking_status = 'confirmed' THEN 1 ELSE 0 END) AS total_confirmed,
      SUM(CASE WHEN b.booking_status = 'pending' THEN 1 ELSE 0 END) AS total_pending,
      SUM(CASE WHEN b.booking_status = 'completed' THEN 1 ELSE 0 END) AS total_completed
    FROM hotel_room_bookings b
    $statsWhereSql
";
$statsStmt = $pdo->prepare($statsSql);
$statsStmt->execute($statsParams);
$statsRow = $statsStmt->fetch(PDO::FETCH_ASSOC) ?: [];
$summaryTotalBookings = (int)($statsRow['total_bookings'] ?? 0);
$summaryConfirmedBookings = (int)($statsRow['total_confirmed'] ?? 0);
$summaryPendingBookings = (int)($statsRow['total_pending'] ?? 0);
$summaryCompletedBookings = (int)($statsRow['total_completed'] ?? 0);
$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';
$orderBySql = $sortBy === 'name'
    ? 'b.last_name ASC, b.first_name ASC, b.created_at DESC'
    : 'b.created_at DESC';
$limitSql = " LIMIT " . (int)$rowsPerPage;

$sql = "
SELECT
  b.*,
  CONCAT(b.first_name, ' ', b.last_name) AS guest_name,
  (b.adults + b.children) AS guest_count,
  h.name AS hotel_name,
  t.profile_picture AS tourist_profile_picture,
  t.google_id AS tourist_google_id,
  t.address AS tourist_address
FROM hotel_room_bookings b
LEFT JOIN hotel_resorts h ON h.hotel_resort_id = b.hotel_resort_id
LEFT JOIN tourist t ON t.tourist_id = b.tourist_id
$whereSql
ORDER BY $orderBySql
$limitSql
";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$bookings = $stmt->fetchAll(PDO::FETCH_ASSOC);

$hoPendingBadge = HoGetPendingCount($pdo, $hoHotelResortId);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Hobookings | Hotel Owner Bookings</title>
  <link rel="icon" type="image/png" href="img/newlogo.png" />
  <link rel="stylesheet" href="styles/Ho_panel.css" />
</head>
<body class="ho-body">
  <div class="ho-layout">
    <?php include __DIR__ . '/Ho_sidebar.php'; ?>

    <main class="ho-main">
      <?php include __DIR__ . '/Ho_header.php'; ?>

      <section class="ho-content">
        <div class="ho-booking-summary-grid">
          <article class="ho-booking-summary-card">
            <span>Total Bookings</span>
            <strong><?= (int)$summaryTotalBookings ?></strong>
          </article>
          <article class="ho-booking-summary-card confirmed">
            <span>Total Confirmed</span>
            <strong><?= (int)$summaryConfirmedBookings ?></strong>
          </article>
          <article class="ho-booking-summary-card pending">
            <span>Total Pending</span>
            <strong><?= (int)$summaryPendingBookings ?></strong>
          </article>
          <article class="ho-booking-summary-card completed">
            <span>Total Completed</span>
            <strong><?= (int)$summaryCompletedBookings ?></strong>
          </article>
        </div>

        <article class="ho-card ho-table-card">
          <div class="ho-table-head">
            <h2 class="ho-section-title">All Bookings</h2>
            <div class="ho-booking-tabs" role="tablist" aria-label="Booking status quick tabs">
              <a href="Hobookings.php?<?= htmlspecialchars(http_build_query(['status' => 'all', 'q' => $search, 'range' => $rangeFilter, 'year' => $selectedYear, 'month' => $selectedMonth, 'date' => $selectedDate, 'sort' => $sortBy, 'rows' => $rowsPerPage])) ?>" class="<?= $statusFilter === 'all' ? 'active' : '' ?>">All</a>
              <a href="Hobookings.php?<?= htmlspecialchars(http_build_query(['status' => 'pending', 'q' => $search, 'range' => $rangeFilter, 'year' => $selectedYear, 'month' => $selectedMonth, 'date' => $selectedDate, 'sort' => $sortBy, 'rows' => $rowsPerPage])) ?>" class="<?= $statusFilter === 'pending' ? 'active' : '' ?>">Pending</a>
              <a href="Hobookings.php?<?= htmlspecialchars(http_build_query(['status' => 'confirmed', 'q' => $search, 'range' => $rangeFilter, 'year' => $selectedYear, 'month' => $selectedMonth, 'date' => $selectedDate, 'sort' => $sortBy, 'rows' => $rowsPerPage])) ?>" class="<?= $statusFilter === 'confirmed' ? 'active' : '' ?>">Confirmed</a>
              <a href="Hobookings.php?<?= htmlspecialchars(http_build_query(['status' => 'completed', 'q' => $search, 'range' => $rangeFilter, 'year' => $selectedYear, 'month' => $selectedMonth, 'date' => $selectedDate, 'sort' => $sortBy, 'rows' => $rowsPerPage])) ?>" class="<?= $statusFilter === 'completed' ? 'active' : '' ?>">Completed</a>
              <a href="Hobookings.php?<?= htmlspecialchars(http_build_query(['status' => 'cancelled', 'q' => $search, 'range' => $rangeFilter, 'year' => $selectedYear, 'month' => $selectedMonth, 'date' => $selectedDate, 'sort' => $sortBy, 'rows' => $rowsPerPage])) ?>" class="<?= $statusFilter === 'cancelled' ? 'active' : '' ?>">Cancelled</a>
              <a href="Hobookings.php?<?= htmlspecialchars(http_build_query(['status' => 'no-show', 'q' => $search, 'range' => $rangeFilter, 'year' => $selectedYear, 'month' => $selectedMonth, 'date' => $selectedDate, 'sort' => $sortBy, 'rows' => $rowsPerPage])) ?>" class="<?= $statusFilter === 'no-show' ? 'active' : '' ?>">No-show</a>
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
              <option value="confirmed" <?= $statusFilter === 'confirmed' ? 'selected' : '' ?>>Confirmed</option>
              <option value="completed" <?= $statusFilter === 'completed' ? 'selected' : '' ?>>Completed</option>
              <option value="cancelled" <?= $statusFilter === 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
              <option value="no-show" <?= $statusFilter === 'no-show' ? 'selected' : '' ?>>No-show</option>
            </select>
            <select name="sort">
              <option value="time" <?= $sortBy === 'time' ? 'selected' : '' ?>>Sort: Latest (Default)</option>
              <option value="name" <?= $sortBy === 'name' ? 'selected' : '' ?>>Sort: Name (A-Z)</option>
            </select>
            <button type="submit" class="ho-btn">Apply Filter</button>
            <input type="text" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="Search by guest name or booking ID" />
          </form>

          <?php if ($bookings): ?>
            <div class="ho-table-wrap">
              <table class="ho-table">
                <thead>
                  <tr>
                    <th>Booking ID</th>
                    <th>Booker</th>
                    <th>Room Type</th>
                    <th>Check-in / Check-out</th>
                    <th>No. of Guests</th>
                    <th>Status</th>
                    <th>Payment</th>
                    <th>Actions</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($bookings as $row): ?>
                    <?php $status = strtolower((string)$row['booking_status']); ?>
                    <?php
                      $profilePath = HoResolveProfileImage($row['tourist_profile_picture'] ?? null);
                      if ($profilePath === '') {
                          $profilePath = HoBuildGoogleAvatarUrl($row['tourist_google_id'] ?? null);
                      }
                      $hasAvatar = $profilePath !== '';
                      $guestName = htmlspecialchars((string)$row['guest_name']);
                      $guestInitial = htmlspecialchars(HoGetInitial((string)$row['guest_name']));
                      $guestEmail = htmlspecialchars((string)$row['email']);
                      $guestPhone = htmlspecialchars((string)($row['phone_number'] ?: '-'));
                      $guestAddress = htmlspecialchars((string)($row['tourist_address'] ?: '-'));
                      $emailLink = 'mailto:' . rawurlencode((string)$row['email']) . '?subject=' . rawurlencode('Regarding your hotel booking #' . (int)$row['hotel_booking_id']);
                    ?>
                    <tr>
                      <td class="ho-cell-center">#<?= (int)$row['hotel_booking_id'] ?></td>
                      <td>
                        <div class="ho-booker-cell">
                          <div class="ho-booker-avatar-wrap">
                            <?php if ($hasAvatar): ?>
                              <img src="<?= htmlspecialchars($profilePath) ?>" alt="Booker profile" class="ho-booker-avatar" onerror="this.onerror=null;this.src='img/profileicon2.png';" />
                            <?php else: ?>
                              <div class="ho-booker-avatar ho-booker-avatar-initial" aria-label="Booker initial"><?= $guestInitial ?></div>
                            <?php endif; ?>
                            <div class="ho-booker-hover-card">
                              <div class="ho-booker-hover-head">
                                <?php if ($hasAvatar): ?>
                                  <img src="<?= htmlspecialchars($profilePath) ?>" alt="Booker profile" class="ho-booker-avatar large" onerror="this.onerror=null;this.src='img/profileicon2.png';" />
                                <?php else: ?>
                                  <div class="ho-booker-avatar ho-booker-avatar-initial large" aria-label="Booker initial"><?= $guestInitial ?></div>
                                <?php endif; ?>
                                <div>
                                  <strong><?= $guestName ?></strong>
                                  <span>Email: <?= $guestEmail ?></span>
                                  <span>Phone: <?= $guestPhone ?></span>
                                  <span>Address: <?= $guestAddress ?></span>
                                </div>
                              </div>
                              <a href="<?= htmlspecialchars($emailLink) ?>" class="ho-booker-email-btn">Send Email</a>
                            </div>
                          </div>
                          <div>
                            <?= $guestName ?><br />
                            <small><?= $guestEmail ?></small>
                          </div>
                        </div>
                      </td>
                      <td class="ho-cell-center"><?= htmlspecialchars((string)$row['room_type']) ?></td>
                      <td class="ho-cell-center"><?= htmlspecialchars((string)$row['checkin_date']) ?> to <?= htmlspecialchars((string)$row['checkout_date']) ?></td>
                      <td class="ho-cell-center"><?= (int)$row['guest_count'] ?> (<?= (int)$row['adults'] ?>A / <?= (int)$row['children'] ?>C)</td>
                      <td class="ho-cell-center"><span class="ho-status <?= htmlspecialchars($status) ?>"><?= ucfirst($status) ?></span></td>
                      <td class="ho-cell-center"><span class="ho-status payment-<?= htmlspecialchars((string)$row['payment_status']) ?>"><?= htmlspecialchars(ucfirst((string)$row['payment_status'])) ?></span></td>
                      <td>
                        <div class="ho-row-actions" data-row-actions>
                          <button type="button" class="ho-btn ho-row-actions-trigger" data-row-actions-trigger aria-expanded="false">Action buttons</button>
                          <div class="ho-actions ho-actions-center ho-row-actions-menu" data-row-actions-menu>
                            <button
                              type="button"
                              class="ho-btn"
                              data-view
                              data-booking='<?= htmlspecialchars(json_encode([
                                  'id' => (int)$row['hotel_booking_id'],
                                  'guest' => $row['guest_name'],
                                  'hotel' => $row['hotel_name'] ?: '-',
                                  'room_type' => $row['room_type'],
                                  'checkin' => $row['checkin_date'],
                                  'checkout' => $row['checkout_date'],
                                  'rooms' => (int)$row['rooms_booked'],
                                  'adults' => (int)$row['adults'],
                                  'children' => (int)$row['children'],
                                  'phone' => $row['phone_number'],
                                  'email' => $row['email'],
                                  'special_request' => $row['special_request'] ?: '-',
                                  'total' => number_format((float)$row['total_amount'], 2),
                                  'amount_paid' => number_format((float)($row['amount_paid'] ?? 0), 2),
                                  'remaining_balance' => number_format((float)($row['remaining_balance'] ?? 0), 2),
                                  'payment_type' => $row['payment_type'] ?? 'full',
                                  'payment_status' => $row['payment_status'] ?? 'unpaid',
                                  'booking_status' => $row['booking_status'] ?? 'pending',
                                  'created_at' => $row['created_at'],
                              ]), ENT_QUOTES) ?>'
                            >View Details</button>

                            <?php if (!in_array($status, ['confirmed', 'completed', 'cancelled', 'no-show'], true)): ?>
                              <form method="post" data-confirm-form>
                                <input type="hidden" name="action" value="confirm" />
                                <input type="hidden" name="booking_id" value="<?= (int)$row['hotel_booking_id'] ?>" />
                                <button type="submit" class="ho-btn confirm">Confirm</button>
                              </form>
                            <?php endif; ?>

                            <?php if (!in_array($status, ['completed', 'cancelled', 'no-show'], true)): ?>
                              <form method="post" data-cancel-form>
                                <input type="hidden" name="action" value="cancel" />
                                <input type="hidden" name="booking_id" value="<?= (int)$row['hotel_booking_id'] ?>" />
                                <button type="submit" class="ho-btn cancel">Cancel</button>
                              </form>
                            <?php endif; ?>

                            <?php if ($status === 'confirmed'): ?>
                              <form method="post" data-noshow-form>
                                <input type="hidden" name="action" value="no_show" />
                                <input type="hidden" name="booking_id" value="<?= (int)$row['hotel_booking_id'] ?>" />
                                <button type="submit" class="ho-btn cancel">No-show</button>
                              </form>
                            <?php endif; ?>
                          </div>
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

      <?php include __DIR__ . '/Ho_footer.php'; ?>
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

  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
  <script>
    (function () {
      const actionNoticeText = <?= json_encode($actionNoticeText, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
      const actionNoticeIcon = <?= json_encode($actionNoticeIcon, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
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
        try {
          const response = await fetch('Hobookings.php', {
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

        document.addEventListener('click', (e) => {
          if (!panel.contains(e.target) && !toggle.contains(e.target)) {
            closePanelAndMarkRead();
          }
        });
      }

      if (markBtn) markBtn.addEventListener('click', markNotificationsRead);

      const modal = document.getElementById('hoDetailsModal');
      const closeBtn = document.getElementById('hoCloseModal');
      const grid = document.getElementById('hoDetailGrid');

      const labels = [
        ['Booking ID', 'id'],
        ['Guest', 'guest'],
        ['Hotel', 'hotel'],
        ['Room Type', 'room_type'],
        ['Check-in', 'checkin'],
        ['Check-out', 'checkout'],
        ['Rooms', 'rooms'],
        ['Adults', 'adults'],
        ['Children', 'children'],
        ['Phone', 'phone'],
        ['Email', 'email'],
        ['Booking Status', 'booking_status'],
        ['Payment Type', 'payment_type'],
        ['Payment Status', 'payment_status'],
        ['Amount Paid', 'amount_paid'],
        ['Remaining Balance', 'remaining_balance'],
        ['Special Request', 'special_request'],
        ['Total Amount', 'total'],
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

      const rowActions = document.querySelectorAll('[data-row-actions]');
      const positionRowActionsMenu = (wrapper) => {
        const trigger = wrapper.querySelector('[data-row-actions-trigger]');
        const menu = wrapper.querySelector('[data-row-actions-menu]');
        if (!trigger || !menu) return;

        const spacing = 6;
        const viewportPadding = 8;
        const triggerRect = trigger.getBoundingClientRect();
        const menuRect = menu.getBoundingClientRect();

        let top = triggerRect.top - menuRect.height - spacing;
        if (top < viewportPadding) {
          top = triggerRect.bottom + spacing;
        }

        let left = triggerRect.right - menuRect.width;
        if (left < viewportPadding) {
          left = viewportPadding;
        }
        const maxLeft = window.innerWidth - menuRect.width - viewportPadding;
        if (left > maxLeft) {
          left = Math.max(viewportPadding, maxLeft);
        }

        menu.style.top = `${Math.round(top)}px`;
        menu.style.left = `${Math.round(left)}px`;
      };

      const closeRowActions = (except) => {
        rowActions.forEach(wrapper => {
          if (except && wrapper === except) return;
          wrapper.classList.remove('open');
          const trigger = wrapper.querySelector('[data-row-actions-trigger]');
          if (trigger) trigger.setAttribute('aria-expanded', 'false');
          const menu = wrapper.querySelector('[data-row-actions-menu]');
          if (menu) {
            menu.style.top = '-9999px';
            menu.style.left = '-9999px';
          }
        });
      };

      document.querySelectorAll('[data-row-actions-trigger]').forEach(trigger => {
        trigger.addEventListener('click', (e) => {
          e.preventDefault();
          e.stopPropagation();
          const wrapper = trigger.closest('[data-row-actions]');
          if (!wrapper) return;
          const willOpen = !wrapper.classList.contains('open');
          closeRowActions(wrapper);
          wrapper.classList.toggle('open', willOpen);
          trigger.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
          if (willOpen) {
            requestAnimationFrame(() => positionRowActionsMenu(wrapper));
          }
        });
      });

      document.addEventListener('click', (e) => {
        if (!(e.target instanceof Element)) return;
        if (e.target.closest('[data-row-actions]')) return;
        closeRowActions(null);
      });
      window.addEventListener('resize', () => closeRowActions(null));
      document.addEventListener('scroll', () => closeRowActions(null), true);

      closeBtn.addEventListener('click', () => modal.classList.remove('open'));
      modal.addEventListener('click', (e) => {
        if (e.target === modal) modal.classList.remove('open');
      });

      document.querySelectorAll('[data-cancel-form]').forEach(form => {
        form.addEventListener('submit', async (e) => {
          if (form.dataset.confirmed === '1') {
            return;
          }
          e.preventDefault();
          if (!window.Swal) {
            const ok = window.confirm('Are you sure you want to cancel this booking?');
            if (ok) {
              form.dataset.confirmed = '1';
              form.submit();
            }
            return;
          }
          const result = await Swal.fire({
            icon: 'warning',
            title: 'Cancel booking?',
            text: 'Are you sure you want to cancel this booking?',
            showCancelButton: true,
            confirmButtonText: 'Yes, cancel booking',
            cancelButtonText: 'Keep booking',
            confirmButtonColor: '#2b7a66',
            cancelButtonColor: '#6c757d'
          });
          if (result.isConfirmed) {
            form.dataset.confirmed = '1';
            form.submit();
          }
        });
      });

      document.querySelectorAll('[data-noshow-form]').forEach(form => {
        form.addEventListener('submit', async (e) => {
          if (form.dataset.confirmed === '1') {
            return;
          }
          e.preventDefault();
          if (!window.Swal) {
            const ok = window.confirm('Mark this booking as no-show?');
            if (ok) {
              form.dataset.confirmed = '1';
              form.submit();
            }
            return;
          }
          const result = await Swal.fire({
            icon: 'question',
            title: 'Mark as no-show?',
            text: 'This booking will be tagged as no-show.',
            showCancelButton: true,
            confirmButtonText: 'Yes, mark no-show',
            cancelButtonText: 'Cancel',
            confirmButtonColor: '#2b7a66',
            cancelButtonColor: '#6c757d'
          });
          if (result.isConfirmed) {
            form.dataset.confirmed = '1';
            form.submit();
          }
        });
      });

      document.querySelectorAll('[data-confirm-form]').forEach(form => {
        form.addEventListener('submit', async (e) => {
          if (form.dataset.confirmed === '1') {
            return;
          }
          e.preventDefault();
          if (!window.Swal) {
            const ok = window.confirm('Confirm this booking?');
            if (ok) {
              form.dataset.confirmed = '1';
              form.submit();
            }
            return;
          }
          const result = await Swal.fire({
            icon: 'question',
            title: 'Confirm booking?',
            text: 'This booking will be marked as confirmed.',
            showCancelButton: true,
            confirmButtonText: 'Yes, confirm',
            cancelButtonText: 'Cancel',
            confirmButtonColor: '#2b7a66',
            cancelButtonColor: '#6c757d'
          });
          if (result.isConfirmed) {
            form.dataset.confirmed = '1';
            form.submit();
          }
        });
      });

      if (actionNoticeText) {
        if (window.Swal) {
          Swal.fire({
            icon: actionNoticeIcon || 'info',
            title: actionNoticeIcon === 'error' ? 'Action failed' : 'Success',
            text: actionNoticeText,
            confirmButtonColor: '#2b7a66'
          }).then(() => {
            if (!window.history?.replaceState) return;
            const url = new URL(window.location.href);
            if (url.searchParams.has('action_notice')) {
              url.searchParams.delete('action_notice');
              window.history.replaceState({}, '', `${url.pathname}${url.search}${url.hash}`);
            }
          });
        } else {
          alert(actionNoticeText);
        }
      }

    })();
  </script>
</body>
</html>

