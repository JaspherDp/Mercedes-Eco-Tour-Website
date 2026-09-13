<?php
$hoActive = $hoActive ?? 'dashboard';
$hoPendingBadge = isset($hoPendingBadge) ? (int)$hoPendingBadge : 0;
$hoSidebarAccountName = trim((string)($_SESSION['hotel_admin_name'] ?? 'Hotel Admin'));
$hoSidebarPropertyName = trim((string)($_SESSION['hotel_admin_property_name'] ?? ''));
$hoSidebarInitial = strtoupper(substr($hoSidebarAccountName !== '' ? $hoSidebarAccountName : 'H', 0, 1));
$hoSidebarProfileImage = trim((string)($_SESSION['hotel_admin_profile_picture'] ?? ''));
require_once __DIR__ . '/php/alert.php';

$hoNavGroups = [
  'Operations' => [
    ['bookings', 'Bookings', 'Hobookings.php', 'calendar'],
    ['rooms', 'Rooms', 'Horooms.php', 'rooms'],
    ['payments', 'Payments & Transactions', 'Hopayments.php', 'card'],
    ['earnings', 'Earnings & Payouts', 'Hoearnings.php', 'wallet'],
  ],
  'Content Management' => [
    ['contents', 'Property Content', 'Hocontents.php', 'content'],
  ],
  'Guest Experience' => [
    ['reviews', 'Reviews', 'Horeviews.php', 'star'],
  ],
  'Account' => [
    ['profile', 'Profile', 'Hoprofile.php', 'profile'],
  ],
];
$hoNavIcons = [
  'calendar' => '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 10h18M8 14h.01M12 14h.01M16 14h.01M8 18h.01M12 18h.01"/>',
  'rooms' => '<path d="M3 19v-8a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v8M3 16h18M6 19v2M18 19v2"/><path d="M6 9V6h5a2 2 0 0 1 2 2v1M13 9V7h5v2"/>',
  'card' => '<rect x="2.5" y="5" width="19" height="14" rx="2"/><path d="M2.5 10h19M7 15h3"/>',
  'wallet' => '<path d="M4 6h14a2 2 0 0 1 2 2v11H4a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h12"/><path d="M20 11h-5a2 2 0 0 0 0 4h5M15 13h.01"/>',
  'content' => '<path d="M5 3h10l4 4v14H5z"/><path d="M14 3v5h5M8 12h8M8 16h8"/>',
  'star' => '<path d="m12 3 2.8 5.7 6.2.9-4.5 4.4 1.1 6.2-5.6-3-5.6 3 1.1-6.2L3 9.6l6.2-.9L12 3Z"/>',
  'profile' => '<circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/>',
];
?>
<aside class="ho-sidebar" aria-label="Hotel admin sidebar">
  <a class="ho-brand" href="Hohome.php">
    <img src="img/newlogo.png" alt="iTour Mercedes" />
    <img src="img/textlogo3.png" alt="iTour Mercedes text logo" class="ho-brand-text" />
  </a>
  <nav class="ho-nav">
    <a href="Hohome.php" class="ho-dashboard-link <?= $hoActive === 'dashboard' ? 'active' : '' ?>" <?= $hoActive === 'dashboard' ? 'aria-current="page"' : '' ?>>
      <svg class="ho-nav-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M3 10.8 12 3l9 7.8"/><path d="M5.5 9.5V21h13V9.5M9 21v-7h6v7"/></svg>
      <span>Dashboard</span>
    </a>
    <?php foreach ($hoNavGroups as $groupLabel => $items):
      $groupId = 'ho-nav-' . strtolower(trim(preg_replace('/[^a-z0-9]+/i', '-', $groupLabel), '-'));
    ?>
      <section class="ho-nav-group" aria-labelledby="<?= htmlspecialchars($groupId) ?>-heading">
        <h2 class="ho-nav-heading" id="<?= htmlspecialchars($groupId) ?>-heading">
          <button type="button" class="ho-nav-toggle" aria-expanded="true" aria-controls="<?= htmlspecialchars($groupId) ?>">
            <span><?= htmlspecialchars($groupLabel) ?></span>
            <svg class="ho-nav-chevron" viewBox="0 0 24 24" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>
          </button>
        </h2>
        <div class="ho-nav-items" id="<?= htmlspecialchars($groupId) ?>">
          <?php foreach ($items as [$key, $label, $href, $icon]): $isActive = $hoActive === $key; ?>
            <a href="<?= htmlspecialchars($href) ?>" class="<?= $isActive ? 'active' : '' ?>" <?= $isActive ? 'aria-current="page"' : '' ?>>
              <svg class="ho-nav-icon" viewBox="0 0 24 24" aria-hidden="true"><?= $hoNavIcons[$icon] ?></svg>
              <span><?= htmlspecialchars($label) ?></span>
              <?php if ($key === 'bookings' && $hoPendingBadge > 0): ?><strong class="ho-nav-count"><?= (int)$hoPendingBadge ?></strong><?php endif; ?>
            </a>
          <?php endforeach; ?>
        </div>
      </section>
    <?php endforeach; ?>
  </nav>
  <div class="ho-sidebar-bottom">
    <a class="ho-account-card" href="Hoprofile.php" aria-label="Open hotel administrator profile">
      <span class="ho-account-avatar" aria-hidden="true"><?php if ($hoSidebarProfileImage !== ''): ?><img src="<?= htmlspecialchars($hoSidebarProfileImage) ?>" alt=""><?php else: ?><?= htmlspecialchars($hoSidebarInitial) ?><?php endif; ?></span>
      <div class="ho-account-meta">
        <strong title="<?= htmlspecialchars($hoSidebarAccountName) ?>"><?= htmlspecialchars($hoSidebarAccountName) ?></strong>
        <small title="<?= htmlspecialchars($hoSidebarPropertyName !== '' ? $hoSidebarPropertyName : 'Assigned Property') ?>"><?= htmlspecialchars($hoSidebarPropertyName !== '' ? $hoSidebarPropertyName : 'Assigned Property') ?></small>
      </div>
    </a>
    <hr class="ho-logout-divider" />
    <a href="php/hotel_admin_logout.php" class="ho-logout-link" id="hoLogoutLink">
      <svg class="ho-nav-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M10 4H5a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h5M14 8l4 4-4 4M18 12H9"/></svg><span>Logout</span>
    </a>
  </div>
</aside>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
document.querySelectorAll('.ho-nav-toggle').forEach((toggle) => {
  const items = document.getElementById(toggle.getAttribute('aria-controls'));
  if (!items) return;
  toggle.addEventListener('click', () => {
    const open = toggle.getAttribute('aria-expanded') !== 'true';
    toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    items.hidden = !open;
  });
});
document.getElementById('hoLogoutLink')?.addEventListener('click', function (event) {
  event.preventDefault(); const logoutUrl = this.href;
  Swal.fire({icon:'question',title:'Sign out?',text:'Are you sure you want to leave the property portal?',showCancelButton:true,confirmButtonColor:'#176b55',cancelButtonColor:'#687b75',confirmButtonText:'Yes, sign out',cancelButtonText:'Stay signed in',reverseButtons:true,focusCancel:true})
    .then((result) => { if (result.isConfirmed) window.location.href = logoutUrl; });
});
</script>
