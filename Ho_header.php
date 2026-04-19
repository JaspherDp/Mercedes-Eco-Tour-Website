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
$hoProfileLabel = trim((string)$hoOwnerName);
if ($hoProfileLabel === '') {
  $hoProfileLabel = 'Hotel Owner';
}
$hoProfileInitial = strtoupper(substr($hoProfileLabel, 0, 1));
$hoRangeOptions = [
  'all' => 'All',
  'yearly' => 'Yearly',
  'monthly' => 'Monthly',
  'weekly' => 'Weekly',
  'daily' => 'Daily'
];
?>
<header class="ho-topbar">
  <div class="ho-topbar-left">
    <h1><?= htmlspecialchars($hoTitle) ?></h1>
    <p>Welcome, <?= htmlspecialchars($hoOwnerName) ?></p>
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
    <span class="ho-pill">Owner Console</span>
    <div class="ho-notif-wrap">
      <button type="button" class="ho-notif-btn" id="hoNotifToggle" aria-label="Booking notifications" aria-expanded="false">
        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3a6 6 0 0 0-6 6v3.6l-1.6 2.8A1 1 0 0 0 5.2 17h13.6a1 1 0 0 0 .8-1.6L18 12.6V9a6 6 0 0 0-6-6Zm0 19a3 3 0 0 0 2.8-2H9.2A3 3 0 0 0 12 22Z"></path></svg>
        Notifications
        <?php if ($hoUnreadBadge > 0): ?>
          <strong class="ho-badge" id="hoNotifBadge"><?= $hoUnreadBadge ?></strong>
        <?php endif; ?>
      </button>
      <div class="ho-notif-panel" id="hoNotifPanel">
        <div class="ho-notif-head">
          <h3>New Bookings</h3>
          <button type="button" id="hoNotifMarkRead">Mark as read</button>
        </div>
        <?php if (!empty($hoNotifItems)): ?>
          <ul>
            <?php foreach ($hoNotifItems as $item): ?>
              <li>
                <strong>#<?= (int)$item['hotel_booking_id'] ?> — <?= htmlspecialchars(trim($item['first_name'] . ' ' . $item['last_name'])) ?></strong>
                <span><?= htmlspecialchars((string)$item['room_type']) ?> • Check-in <?= htmlspecialchars((string)$item['checkin_date']) ?></span>
                <small><?= htmlspecialchars((string)$item['created_at']) ?></small>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php else: ?>
          <p class="ho-notif-empty">No notifications yet.</p>
        <?php endif; ?>
      </div>
    </div>
    <div class="ho-topbar-profile" aria-label="Hotel admin profile" title="<?= htmlspecialchars($hoProfileLabel) ?>">
      <?= htmlspecialchars($hoProfileInitial) ?>
    </div>
  </div>
</header>
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
