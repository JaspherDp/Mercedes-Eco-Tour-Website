<?php
$hoTitle = $hoTitle ?? 'Hotel Owner Panel';
$hoOwnerName = $hoOwnerName ?? 'Hotel Owner';
$hoPendingBadge = isset($hoPendingBadge) ? (int)$hoPendingBadge : 0;
$hoUnreadBadge = isset($hoUnreadBadge) ? (int)$hoUnreadBadge : 0;
$hoNotifItems = $hoNotifItems ?? [];
$hoShowRangeFilter = $hoShowRangeFilter ?? false;
$hoRangeFilter = $hoRangeFilter ?? 'all';
$hoRangeYear = isset($hoRangeYear) ? (int)$hoRangeYear : (int)date('Y');
$hoRangeMonth = isset($hoRangeMonth) ? (int)$hoRangeMonth : (int)date('n');
$hoRangeDate = $hoRangeDate ?? date('Y-m-d');
$hoRangeYears = $hoRangeYears ?? [(int)date('Y')];
$hoRangeHiddenFields = isset($hoRangeHiddenFields) && is_array($hoRangeHiddenFields) ? $hoRangeHiddenFields : [];
$hoTopbarViewToggle = isset($hoTopbarViewToggle) && is_array($hoTopbarViewToggle) ? $hoTopbarViewToggle : null;
$hoPaymentHeaderActions = $hoPaymentHeaderActions ?? false;
$hoPaymentExportUrl = $hoPaymentExportUrl ?? '#';
$hoPaymentCanCollect = $hoPaymentCanCollect ?? false;
$hoEarningsHeaderActions = $hoEarningsHeaderActions ?? false;
$hoEarningsExportUrl = $hoEarningsExportUrl ?? '#';
$hoProfileHeaderActions = $hoProfileHeaderActions ?? false;
$hoProfileLabel = trim((string)$hoOwnerName);
if ($hoProfileLabel === '') {
  $hoProfileLabel = 'Hotel Owner';
}
$hoProfileInitial = strtoupper(substr($hoProfileLabel, 0, 1));
$hoProfileImage = trim((string)($_SESSION['hotel_admin_profile_picture'] ?? ''));
$hoRangeOptions = [
  'all' => 'All',
  'yearly' => 'Yearly',
  'monthly' => 'Monthly',
  'weekly' => 'Weekly',
  'daily' => 'Daily'
];
?>
<header class="ho-topbar">
  <div class="ho-topbar-left<?= $hoProfileHeaderActions ? ' ho-profile-page-title' : '' ?>">
    <?php if ($hoProfileHeaderActions): ?><span class="ho-profile-title-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/></svg></span><div><?php endif; ?>
    <h1><?= htmlspecialchars($hoTitle) ?></h1>
    <p><?= $hoProfileHeaderActions ? 'Manage your identity, property details, and account security' : 'Welcome, ' . htmlspecialchars($hoOwnerName) ?></p>
    <?php if ($hoProfileHeaderActions): ?></div><?php endif; ?>
  </div>
  <div class="ho-topbar-right">
    <?php if ($hoShowRangeFilter): ?>
      <form method="get" class="ho-global-filter-form">
        <label for="hoRangeFilter" class="ho-global-filter-label">Overview Filter</label>
        <?php foreach ($hoRangeHiddenFields as $hiddenName => $hiddenValue): ?>
          <input type="hidden" name="<?= htmlspecialchars((string)$hiddenName) ?>" value="<?= htmlspecialchars((string)$hiddenValue) ?>" />
        <?php endforeach; ?>
        <select id="hoRangeFilter" name="range" class="ho-global-filter-select" onchange="hoSyncFilterFields(this.form)">
          <?php foreach ($hoRangeOptions as $value => $label): ?>
            <option value="<?= htmlspecialchars($value) ?>" <?= $hoRangeFilter === $value ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
          <?php endforeach; ?>
        </select>

        <select id="hoRangeYear" name="year" class="ho-global-filter-select">
          <?php foreach ($hoRangeYears as $year): ?>
            <option value="<?= (int)$year ?>" <?= $hoRangeYear === (int)$year ? 'selected' : '' ?>><?= (int)$year ?></option>
          <?php endforeach; ?>
        </select>

        <select id="hoRangeMonth" name="month" class="ho-global-filter-select">
          <?php for ($m = 1; $m <= 12; $m++): ?>
            <option value="<?= $m ?>" <?= $hoRangeMonth === $m ? 'selected' : '' ?>><?= date('F', mktime(0, 0, 0, $m, 1)) ?></option>
          <?php endfor; ?>
        </select>

        <input id="hoRangeDate" type="date" name="date" class="ho-global-filter-select ho-global-filter-date" value="<?= htmlspecialchars($hoRangeDate) ?>" />
        <button type="submit" class="ho-global-filter-apply">Apply</button>
      </form>
    <?php endif; ?>
    <?php if ($hoTopbarViewToggle): ?>
      <div class="ho-view-toggle ho-view-toggle-top" role="group" aria-label="Room layout view toggle">
        <a href="<?= htmlspecialchars((string)($hoTopbarViewToggle['card_url'] ?? '#')) ?>" class="<?= (($hoTopbarViewToggle['active'] ?? '') === 'card') ? 'active' : '' ?>" aria-label="Card view" title="Card view"><span aria-hidden="true">&#9638;</span></a>
        <a href="<?= htmlspecialchars((string)($hoTopbarViewToggle['list_url'] ?? '#')) ?>" class="<?= (($hoTopbarViewToggle['active'] ?? '') === 'list') ? 'active' : '' ?>" aria-label="List view" title="List view"><span aria-hidden="true">&#9776;</span></a>
      </div>
    <?php endif; ?>
    <?php if ($hoEarningsHeaderActions): ?>
      <a class="he-button secondary he-header-export" href="<?= htmlspecialchars((string)$hoEarningsExportUrl) ?>">
        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3v12m0 0 4-4m-4 4-4-4M4 18v3h16v-3"/></svg>
        Export CSV
      </a>
    <?php elseif ($hoPaymentHeaderActions): ?>
      <div class="hp-head-actions hp-header-actions" aria-label="Payment page actions">
        <a class="hp-btn secondary" href="<?= htmlspecialchars((string)$hoPaymentExportUrl) ?>">
          <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3v12m0 0 4-4m-4 4-4-4M4 18v3h16v-3"/></svg>
          Export CSV
        </a>
        <button class="hp-btn secondary hp-payment-phone-header" type="button" id="hpOpenPaymentPhone">
          <svg viewBox="0 0 24 24" aria-hidden="true"><rect x="6" y="2" width="12" height="20" rx="2"/><path d="M10 18h4"/></svg>
          <span>Payment Phone</span>
        </button>
        <button class="hp-btn primary" type="button" data-open-collect <?= $hoPaymentCanCollect ? '' : 'disabled' ?>>
          <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>
          Pay balance
        </button>
      </div>
    <?php endif; ?>
    <?php if ($hoProfileHeaderActions): ?>
      <button class="am-button" type="button" data-open-modal="hoPasswordModal"><svg viewBox="0 0 24 24"><rect x="4" y="10" width="16" height="11" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/></svg>Change password</button>
      <button class="am-save-button" type="submit" form="hoProfileForm"><svg viewBox="0 0 24 24"><path d="M5 4h12l2 2v14H5V4Z"/><path d="M8 4v6h8V4M8 20v-6h8v6"/></svg>Save profile</button>
    <?php elseif (!$hoPaymentHeaderActions && !$hoEarningsHeaderActions): ?><span class="ho-pill">Owner Console</span><?php endif; ?>
    <div class="ho-notif-wrap">
      <button type="button" class="ho-notif-btn" id="hoNotifToggle" aria-label="Booking notifications" aria-expanded="false">
        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9M10 21h4"></path></svg>
        <span class="ho-header-sr">Notifications</span>
        <?php if ($hoUnreadBadge > 0): ?>
          <strong class="ho-badge" id="hoNotifBadge"><?= $hoUnreadBadge ?></strong>
        <?php endif; ?>
      </button>
      <div class="ho-notif-panel" id="hoNotifPanel" role="dialog" aria-label="Booking notifications">
        <div class="ho-notif-head">
          <div class="ho-notif-title">
            <span class="ho-notif-title-icon"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9M10 21h4"></path></svg></span>
            <div><h3>Booking Notifications</h3><small>Recent booking activity</small></div>
          </div>
          <button type="button" id="hoNotifMarkRead">Mark all read</button>
        </div>
        <?php if (!empty($hoNotifItems)): ?>
          <ul>
            <?php foreach ($hoNotifItems as $item): ?>
              <?php $isUnread = !empty($item['is_unread']); ?>
              <?php $notificationBookingKey = (string)($item['booking_reference'] ?: $item['hotel_booking_id']); ?>
              <li class="ho-notif-item<?= $isUnread ? ' is-unread' : '' ?>">
                <a class="ho-notif-link" href="Hobookings.php?q=<?= rawurlencode($notificationBookingKey) ?>" aria-label="View booking <?= htmlspecialchars($notificationBookingKey) ?>">
                  <strong>
                    <?= htmlspecialchars($notificationBookingKey) ?> — <?= htmlspecialchars(trim($item['first_name'] . ' ' . $item['last_name'])) ?>
                    <?php if ($isUnread): ?>
                      <span class="ho-notif-unread-pill">NEW</span>
                    <?php endif; ?>
                  </strong>
                  <span><?= htmlspecialchars((string)$item['room_type']) ?> • Check-in <?= htmlspecialchars((string)$item['checkin_date']) ?></span>
                  <small><?= htmlspecialchars((string)$item['created_at']) ?></small>
                </a>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php else: ?>
          <div class="ho-notif-empty"><span>✓</span><strong>You are all caught up</strong><small>New booking activity will appear here.</small></div>
        <?php endif; ?>
      </div>
    </div>
    <div class="ho-topbar-profile" aria-label="Hotel admin profile" title="<?= htmlspecialchars($hoProfileLabel) ?>">
      <?php if ($hoProfileImage !== ''): ?><img src="<?= htmlspecialchars($hoProfileImage) ?>" alt=""><?php else: ?><?= htmlspecialchars($hoProfileInitial) ?><?php endif; ?>
    </div>
  </div>
</header>
<script>
  (function () {
    const toggle = document.getElementById('hoNotifToggle');
    const panel = document.getElementById('hoNotifPanel');
    let readRequestSent = false;

    const persistNotificationsRead = function () {
      if (readRequestSent) return;
      readRequestSent = true;
      document.getElementById('hoNotifBadge')?.remove();
      panel?.querySelectorAll('.is-unread').forEach(item => item.classList.remove('is-unread'));
      panel?.querySelectorAll('.ho-notif-unread-pill').forEach(pill => pill.remove());

      fetch(window.location.pathname, {
        method: 'POST',
        credentials: 'same-origin',
        keepalive: true,
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: new URLSearchParams({ho_action: 'mark_notifications_read', csrf_token: <?= json_encode(AppCsrfToken('hotel_admin', 'notifications')) ?>})
      }).catch(function () { readRequestSent = false; });
    };

    toggle?.addEventListener('click', function () {
      if (!panel?.classList.contains('open')) persistNotificationsRead();
    });
    panel?.addEventListener('click', function (event) {
      if (event.target.closest('.ho-notif-link')) persistNotificationsRead();
    });
  })();
</script>
<script>
  (function () {
    const form = document.querySelector('.ho-global-filter-form');
    if (!form) return;

    const range = form.querySelector('#hoRangeFilter');
    const year = form.querySelector('#hoRangeYear');
    const month = form.querySelector('#hoRangeMonth');
    const date = form.querySelector('#hoRangeDate');

    window.hoSyncFilterFields = function (targetForm) {
      const r = targetForm.querySelector('#hoRangeFilter')?.value || 'all';
      const y = targetForm.querySelector('#hoRangeYear');
      const m = targetForm.querySelector('#hoRangeMonth');
      const d = targetForm.querySelector('#hoRangeDate');

      if (y) y.style.display = (r === 'yearly' || r === 'monthly' || r === 'daily') ? '' : 'none';
      if (m) m.style.display = (r === 'monthly') ? '' : 'none';
      if (d) d.style.display = (r === 'daily') ? '' : 'none';

      targetForm.submit();
    };

    const updateVisibility = () => {
      const r = range ? range.value : 'all';
      if (year) year.style.display = (r === 'yearly' || r === 'monthly' || r === 'daily') ? '' : 'none';
      if (month) month.style.display = (r === 'monthly') ? '' : 'none';
      if (date) date.style.display = (r === 'daily') ? '' : 'none';
    };

    if (range) range.addEventListener('change', updateVisibility);
    updateVisibility();
  })();
</script>
