<?php
$hoActive = $hoActive ?? 'dashboard';
$hoPendingBadge = isset($hoPendingBadge) ? (int)$hoPendingBadge : 0;
$hoSidebarAccountName = trim((string)($_SESSION['hotel_admin_name'] ?? 'Hotel Admin'));
$hoSidebarPropertyName = trim((string)($_SESSION['hotel_admin_property_name'] ?? ''));
$hoSidebarInitial = strtoupper(substr($hoSidebarAccountName !== '' ? $hoSidebarAccountName : 'H', 0, 1));
?>
<aside class="ho-sidebar">
  <a class="ho-brand" href="Hohome.php">
    <img src="img/newlogo.png" alt="iTour Mercedes" />
    <img src="img/textlogo3.png" alt="iTour Mercedes text logo" class="ho-brand-text" />
  </a>

  <nav class="ho-nav">
    <a href="Hohome.php" class="<?= $hoActive === 'dashboard' ? 'active' : '' ?>">
      <span class="ho-nav-icon" aria-hidden="true">
        <svg viewBox="0 0 24 24"><path d="M4 11.2 12 4l8 7.2V20a1 1 0 0 1-1 1h-5v-6h-4v6H5a1 1 0 0 1-1-1Z"></path></svg>
      </span><span>Dashboard</span>
    </a>
    <a href="Hobookings.php" class="<?= $hoActive === 'bookings' ? 'active' : '' ?>">
      <span class="ho-nav-icon" aria-hidden="true">
        <svg viewBox="0 0 24 24"><path d="M8 3h8v2h3a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V7a2 2 0 0 1 2-2h3Zm-1 8v2h10v-2H7Zm0 4v2h7v-2H7Zm1-8v2h8V7H8Z"></path></svg>
      </span><span>Bookings</span>
      <?php if ($hoPendingBadge > 0): ?>
        <strong class="ho-nav-count"><?= (int)$hoPendingBadge ?></strong>
      <?php endif; ?>
    </a>
    <a href="Horooms.php" class="<?= $hoActive === 'rooms' ? 'active' : '' ?>">
      <span class="ho-nav-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M3 8a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v8h-2v3h-2v-3H7v3H5v-3H3Zm4 3a2 2 0 1 0 0 4 2 2 0 0 0 0-4Zm10 0a2 2 0 1 0 0 4 2 2 0 0 0 0-4Z"></path></svg></span><span>Rooms</span>
    </a>
    <a href="Hocontents.php" class="<?= $hoActive === 'contents' ? 'active' : '' ?>">
      <span class="ho-nav-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M5 4h14a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2Zm2 4v2h10V8H7Zm0 4v2h7v-2H7Z"></path></svg></span><span>Contents</span>
    </a>
    <a href="#" class="disabled"><span class="ho-nav-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M16 11a3 3 0 1 0-3-3 3 3 0 0 0 3 3Zm-8 0a3 3 0 1 0-3-3 3 3 0 0 0 3 3Zm0 2c-2.8 0-5 1.8-5 4v1h10v-1c0-2.2-2.2-4-5-4Zm8 0c-.3 0-.6 0-.9.1A6 6 0 0 1 17 17v1h4v-1c0-2.2-2.2-4-5-4Z"></path></svg></span><span>Guests</span></a>
    <a href="#" class="disabled"><span class="ho-nav-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M4 19h16v2H4Zm2-3h2v2H6Zm5-5h2v7h-2Zm5-4h2v11h-2Z"></path></svg></span><span>Reports</span></a>
    <a href="#" class="disabled"><span class="ho-nav-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="m19.4 13 .9 1.6-1.8 3.1-1.9-.3a7.4 7.4 0 0 1-1.4.8l-.6 1.8H9.4l-.6-1.8a7.4 7.4 0 0 1-1.4-.8l-1.9.3-1.8-3.1.9-1.6a8 8 0 0 1 0-2l-.9-1.6 1.8-3.1 1.9.3a7.4 7.4 0 0 1 1.4-.8l.6-1.8h4.2l.6 1.8a7.4 7.4 0 0 1 1.4.8l1.9-.3 1.8 3.1-.9 1.6a8 8 0 0 1 0 2ZM12 15.5a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7Z"></path></svg></span><span>Settings</span></a>
  </nav>

  <div class="ho-sidebar-bottom">
    <div class="ho-account-card">
      <span class="ho-account-avatar" aria-hidden="true"><?= htmlspecialchars($hoSidebarInitial) ?></span>
      <div class="ho-account-meta">
        <strong><?= htmlspecialchars($hoSidebarAccountName) ?></strong>
        <small><?= htmlspecialchars($hoSidebarPropertyName !== '' ? $hoSidebarPropertyName : 'Assigned Property') ?></small>
      </div>
    </div>
    <a href="php/hotel_admin_logout.php" class="ho-logout-link">
      <span class="ho-nav-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M10 17v-3H3v-4h7V7l5 5-5 5Zm6 2h-4v2h4a2 2 0 0 0 2-2V5a2 2 0 0 0-2-2h-4v2h4Z"></path></svg></span>
      <span>Logout</span>
    </a>
  </div>
</aside>
