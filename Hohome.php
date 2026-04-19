<?php
require_once __DIR__ . '/Ho_common.php';

$hoAdmin = HoRequireHotelAdmin($pdo);
$hoHotelResortId = (int)$hoAdmin['hotel_resort_id'];
$hoPropertyName = trim((string)($hoAdmin['property_name'] ?? ''));

$hoActive = 'dashboard';
$hoTitle = 'Dashboard';
$hoOwnerName = $hoPropertyName !== '' ? $hoPropertyName . ' Admin' : (string)$hoAdmin['username'];
$hoPendingBadge = HoGetPendingCount($pdo, $hoHotelResortId);
$hoUnreadBadge = HoGetUnreadCount($pdo, $hoHotelResortId);
$hoNotifItems = HoGetNotificationItems($pdo, 8, $hoHotelResortId);
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

if (isset($_POST['ho_action']) && $_POST['ho_action'] === 'mark_notifications_read') {
    HoMarkNotificationsRead($pdo, $hoHotelResortId);
    header('Content-Type: application/json');
    echo json_encode(['ok' => true]);
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
$upcomingStmt = $pdo->prepare("
    SELECT hotel_booking_id, first_name, last_name, room_type, checkin_date, booking_status, created_at
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
      first_name,
      last_name,
      room_type,
      checkin_date,
      checkout_date,
      (adults + children) AS guest_count
    FROM hotel_room_bookings
    WHERE booking_status = 'confirmed' AND $rangeWhere
    ORDER BY checkin_date ASC, created_at DESC
");
$acceptedStmt->execute($params);
$acceptedCheckins = $acceptedStmt->fetchAll(PDO::FETCH_ASSOC);

$acceptedByDate = [];
foreach ($acceptedCheckins as $item) {
    $dateKey = (string)$item['checkin_date'];
    if (!isset($acceptedByDate[$dateKey])) {
        $acceptedByDate[$dateKey] = [];
    }
    $acceptedByDate[$dateKey][] = [
        'id' => (int)$item['hotel_booking_id'],
        'guest' => trim((string)$item['first_name'] . ' ' . (string)$item['last_name']),
        'room_type' => (string)$item['room_type'],
        'checkin' => (string)$item['checkin_date'],
        'checkout' => (string)$item['checkout_date'],
        'guest_count' => (int)$item['guest_count'],
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
  <link rel="stylesheet" href="styles/Ho_panel.css" />
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
          </article>
          <article class="ho-card ho-summary-card">
            <p>Pending Bookings</p>
            <div class="ho-summary-value">
              <div class="ho-summary-icon" aria-hidden="true">
                <svg viewBox="0 0 24 24"><path d="M12 2a10 10 0 1 0 10 10A10 10 0 0 0 12 2Zm1 10.6V7h-2v6h5v-2.4Z"></path></svg>
              </div>
              <h3><?= $pendingBookings ?></h3>
            </div>
          </article>
          <article class="ho-card ho-summary-card">
            <p>Confirmed Bookings</p>
            <div class="ho-summary-value">
              <div class="ho-summary-icon" aria-hidden="true">
                <svg viewBox="0 0 24 24"><path d="M12 2a10 10 0 1 0 10 10A10 10 0 0 0 12 2Zm4.7 7.7-5.4 5.7a1 1 0 0 1-1.4 0L7.3 13a1 1 0 0 1 1.4-1.4l1.9 1.9 4.7-4.9a1 1 0 1 1 1.4 1.1Z"></path></svg>
              </div>
              <h3><?= $confirmedBookings ?></h3>
            </div>
          </article>
          <article class="ho-card ho-summary-card">
            <p>Cancelled Bookings</p>
            <div class="ho-summary-value">
              <div class="ho-summary-icon" aria-hidden="true">
                <svg viewBox="0 0 24 24"><path d="M12 2a10 10 0 1 0 10 10A10 10 0 0 0 12 2Zm4.2 12.8-1.4 1.4L12 13.4 9.2 16.2l-1.4-1.4L10.6 12 7.8 9.2l1.4-1.4L12 10.6l2.8-2.8 1.4 1.4L13.4 12Z"></path></svg>
              </div>
              <h3><?= $cancelledBookings ?></h3>
            </div>
          </article>
        </div>

        <div class="ho-dashboard-analytics">
          <article class="ho-card ho-calendar-card">
            <div class="ho-card-head">
              <h3>Calendar & Upcoming</h3>
              <div class="ho-tabs">
                <button type="button" class="active" data-ho-tab="calendar">Calendar</button>
                <button type="button" data-ho-tab="upcoming">Upcoming</button>
              </div>
            </div>

            <div id="hoCalendarPane">
              <div class="ho-calendar-month-label" id="hoCalendarMonthLabel"></div>
              <table class="ho-mini-calendar" id="hoMiniCalendar"></table>
            </div>
            <div id="hoUpcomingPane" style="display:none;">
              <ul class="ho-calendar-list">
                <?php foreach ($upcomingRows as $u): ?>
                  <li>
                    <strong>#<?= (int)$u['hotel_booking_id'] ?> — <?= htmlspecialchars(trim($u['first_name'] . ' ' . $u['last_name'])) ?></strong>
                    <span><?= htmlspecialchars((string)$u['room_type']) ?> • Check-in <?= htmlspecialchars((string)$u['checkin_date']) ?></span>
                    <small>Status: <?= htmlspecialchars(ucfirst((string)$u['booking_status'])) ?></small>
                  </li>
                <?php endforeach; ?>
                <?php if (!$upcomingRows): ?>
                  <li><span>No upcoming bookings yet.</span></li>
                <?php endif; ?>
              </ul>
            </div>
          </article>

          <div class="ho-analytics-right">
            <article class="ho-card ho-chart-card">
              <div class="ho-card-head">
                <h3>Booking Trends</h3>
              </div>
              <canvas id="hoLineChart" height="130"></canvas>
            </article>

            <article class="ho-card ho-pie-card">
              <div class="ho-card-head">
                <h3>Booking Status Pie Chart</h3>
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
                    <th>Booking ID</th>
                    <th>Guest</th>
                    <th>Room Type</th>
                    <th>Check-in / Check-out</th>
                    <th>Guests</th>
                    <th>Status</th>
                    <th>Created</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($recentBookings as $row): ?>
                    <?php $status = strtolower((string)$row['booking_status']); ?>
                    <tr>
                      <td>#<?= (int)$row['hotel_booking_id'] ?></td>
                      <td><?= htmlspecialchars(trim($row['first_name'] . ' ' . $row['last_name'])) ?></td>
                      <td><?= htmlspecialchars($row['room_type']) ?></td>
                      <td><?= htmlspecialchars($row['checkin_date']) ?> to <?= htmlspecialchars($row['checkout_date']) ?></td>
                      <td><?= (int)$row['guest_count'] ?></td>
                      <td><span class="ho-status <?= htmlspecialchars($status) ?>"><?= ucfirst($status) ?></span></td>
                      <td><?= htmlspecialchars($row['created_at']) ?></td>
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
    <div class="ho-modal-card">
      <div class="ho-modal-head">
        <h3 id="hoCalendarBookingTitle">Accepted Check-ins</h3>
        <button type="button" class="ho-close" id="hoCalendarBookingClose">&times;</button>
      </div>
      <div id="hoCalendarBookingBody"></div>
    </div>
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

      const lineLabels = <?= json_encode($lineLabels, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
      const lineData = <?= json_encode($lineData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
      const acceptedByDate = <?= json_encode($acceptedByDate, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
      const statusCounts = {
        pending: <?= (int)$pendingBookings ?>,
        confirmed: <?= (int)$confirmedBookings ?>,
        cancelled: <?= (int)$cancelledBookings ?>
      };
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
            plugins: { legend: { display: false } },
            scales: { y: { beginAtZero: true, ticks: { precision: 0 } } }
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
            plugins: {
              legend: {
                position: 'bottom',
                labels: { boxWidth: 12, usePointStyle: true, pointStyle: 'circle' }
              }
            }
          }
        });
      }

      const tabs = document.querySelectorAll('[data-ho-tab]');
      const calPane = document.getElementById('hoCalendarPane');
      const upPane = document.getElementById('hoUpcomingPane');
      const calBookingModal = document.getElementById('hoCalendarBookingModal');
      const calBookingTitle = document.getElementById('hoCalendarBookingTitle');
      const calBookingBody = document.getElementById('hoCalendarBookingBody');
      const calBookingClose = document.getElementById('hoCalendarBookingClose');
      tabs.forEach(btn => {
        btn.addEventListener('click', () => {
          tabs.forEach(x => x.classList.remove('active'));
          btn.classList.add('active');
          const tab = btn.getAttribute('data-ho-tab');
          calPane.style.display = tab === 'calendar' ? 'block' : 'none';
          upPane.style.display = tab === 'upcoming' ? 'block' : 'none';
        });
      });

      const miniCal = document.getElementById('hoMiniCalendar');
      const monthLabel = document.getElementById('hoCalendarMonthLabel');
      if (miniCal) {
        const now = new Date();
        const year = <?= (int)$calendarDisplayYear ?>;
        const month = <?= (int)$calendarDisplayMonth ?> - 1;
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
          const isToday = day === now.getDate();
          const dateKey = makeDateKey(day);
          const items = acceptedByDate[dateKey] || [];
          const hasBookings = items.length > 0;
          const classes = `${isToday ? 'today ' : ''}${hasBookings ? 'has-bookings' : ''}`.trim();
          html += hasBookings
            ? `<td class="${classes}"><button type="button" class="ho-cal-day-btn" data-date="${dateKey}"><span>${day}</span><em>${items.length}</em></button></td>`
            : `<td class="${classes}">${day}</td>`;
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

            calBookingTitle.textContent = `Accepted Check-ins — ${prettyDate}`;
            calBookingBody.innerHTML = `<ul class="ho-calendar-booking-list">${items.map((item) => `
              <li>
                <strong>#${item.id} — ${item.guest}</strong>
                <span>${item.room_type}</span>
                <small>${item.checkin} to ${item.checkout} • ${item.guest_count} guest(s)</small>
              </li>
            `).join('')}</ul>`;
            calBookingModal.classList.add('open');
          });
        });
      }

      if (calBookingClose && calBookingModal) {
        calBookingClose.addEventListener('click', () => calBookingModal.classList.remove('open'));
        calBookingModal.addEventListener('click', (e) => {
          if (e.target === calBookingModal) calBookingModal.classList.remove('open');
        });
      }
    })();
  </script>
</body>
</html>

