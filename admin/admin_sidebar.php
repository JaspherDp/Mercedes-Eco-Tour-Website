<?php
chdir(__DIR__ . '/..');
if (session_status() === PHP_SESSION_NONE) {
require_once __DIR__ . '/../php/session_security.php';
AppSessionStart();
}
require_once __DIR__ . '/../php/db_connection.php';
require_once __DIR__ . '/../php/admin_auth_helper.php';

AdminRequireLogin();
$adminNotificationCsrf = AppCsrfToken('admin', 'notifications');

// === AJAX endpoint for unviewed notifications ===
if (isset($_GET['get_unviewed'])) {
    header('Content-Type: application/json'); // make sure it's JSON
    $booking_count = $pdo->query("SELECT COUNT(*) FROM bookings WHERE COALESCE(is_notif_viewed, 0)=0")->fetchColumn();
    $inquiry_count = $pdo->query("SELECT COUNT(*) FROM inquiries WHERE is_notif_viewed=0")->fetchColumn();
    echo json_encode(['count' => (int)$booking_count + (int)$inquiry_count]);
    exit; // stop the rest of the page from rendering
}

// === Admin info ===
$admin_username = 'Administrator';
$admin_email = 'admin@example.com';
$admin_profile_picture = '';

function resolveAdminProfileImage(?string $profilePicture): string {
    if (!$profilePicture) return '';
    $profilePicture = trim($profilePicture);
    if ($profilePicture === '') return '';
    if (preg_match('#^https?://#i', $profilePicture) || strpos($profilePicture, '//') === 0) {
        return normalizeGoogleProfileImage($profilePicture, 96);
    }

    $candidates = [
        ltrim($profilePicture, '/'),
        'uploads/profile_pictures/' . basename($profilePicture),
        'uploads/profile_picture/' . basename($profilePicture),
        'uploads/profile/' . basename($profilePicture),
        'img/' . basename($profilePicture)
    ];
    foreach ($candidates as $candidate) {
        $full = getcwd() . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $candidate);
        if (is_file($full)) return $candidate;
    }
    return '';
}

if (!function_exists('normalizeGoogleProfileImage')) {
    function normalizeGoogleProfileImage(string $profilePicture, int $size = 96): string
    {
        $candidate = trim($profilePicture);
        if ($candidate === '') {
            return '';
        }

        if (strpos($candidate, '//') === 0) {
            $candidate = 'https:' . $candidate;
        }

        if (!preg_match('#^https?://#i', $candidate)) {
            return $candidate;
        }

        if (
            stripos($candidate, 'profiles.google.com') !== false &&
            preg_match('#profiles\\.google\\.com/(?:s2/photos/profile/)?([^/?#]+)(?:/picture)?#i', $candidate, $m)
        ) {
            return 'https://profiles.google.com/' . rawurlencode($m[1]) . '/picture?sz=' . $size;
        }

        if (stripos($candidate, 'google.com/s2/photos/profile') !== false) {
            $candidate = preg_replace('/([?&])sz=\d+/i', '$1sz=' . $size, $candidate);

            if (!preg_match('/[?&]sz=/i', $candidate)) {
                $candidate .= (strpos($candidate, '?') !== false ? '&' : '?') . 'sz=' . $size;
            }

            return $candidate;
        }

        if (stripos($candidate, 'googleusercontent.com') !== false) {
            $candidate = preg_replace('/([?&])sz=\d+/i', '$1sz=' . $size, $candidate);
            $candidate = preg_replace('/=s\d+-c(?=$|[?&#])/i', '=s' . $size . '-c', $candidate);
            $candidate = preg_replace('/=s\d+(?=$|[?&#])/i', '=s' . $size, $candidate);
        }

        return $candidate;
    }
}

try {
    if (!empty($_SESSION['admin_id'])) {
        $stmt = $pdo->prepare('SELECT * FROM admin_users WHERE admin_id = ? LIMIT 1');
        $stmt->execute([$_SESSION['admin_id']]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $admin_username = htmlspecialchars($row['username'], ENT_QUOTES, 'UTF-8');
            $admin_email = htmlspecialchars($row['email'] ?? '', ENT_QUOTES, 'UTF-8');
            $admin_profile_picture = resolveAdminProfileImage((string)($row['profile_picture'] ?? $row['profile_pic'] ?? $row['avatar'] ?? ''));
        }
    } elseif (!empty($_SESSION['username'])) {
        $stmt = $pdo->prepare('SELECT * FROM admin_users WHERE username = ? LIMIT 1');
        $stmt->execute([$_SESSION['username']]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $admin_username = htmlspecialchars($row['username'], ENT_QUOTES, 'UTF-8');
            $admin_email = htmlspecialchars($row['email'] ?? '', ENT_QUOTES, 'UTF-8');
            $admin_profile_picture = resolveAdminProfileImage((string)($row['profile_picture'] ?? $row['profile_pic'] ?? $row['avatar'] ?? ''));
        }
    } else {
        $stmt = $pdo->query('SELECT * FROM admin_users ORDER BY admin_id ASC LIMIT 1');
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $admin_username = htmlspecialchars($row['username'], ENT_QUOTES, 'UTF-8');
            $admin_email = htmlspecialchars($row['email'] ?? '', ENT_QUOTES, 'UTF-8');
            $admin_profile_picture = resolveAdminProfileImage((string)($row['profile_picture'] ?? $row['profile_pic'] ?? $row['avatar'] ?? ''));
        }
    }
} catch (Exception $e) {}

// Profile metadata is stored separately so the core login table stays stable.
if ($admin_profile_picture === '' && !empty($_SESSION['admin_id'])) {
    try {
        $stmt = $pdo->prepare('SELECT profile_picture FROM admin_profile_details WHERE admin_id = ? LIMIT 1');
        $stmt->execute([(int)$_SESSION['admin_id']]);
        $admin_profile_picture = resolveAdminProfileImage((string)($stmt->fetchColumn() ?: ''));
    } catch (Throwable $e) {
        // The profile-details table is created when management pages are first used.
    }
}

$current_page = basename($_SERVER['PHP_SELF']);
$current_admin_view = $current_page === 'admin-placeholder.php'
    ? preg_replace('/[^a-z0-9-]/', '', strtolower((string)($_GET['page'] ?? '')))
    : '';
$admin_initial = strtoupper(substr(trim(strip_tags($admin_username)) !== '' ? trim(strip_tags($admin_username)) : 'A', 0, 1));
$scriptDir = strtolower(str_replace('\\', '/', dirname($_SERVER['PHP_SELF'] ?? '')));
$phpApiBase = (substr($scriptDir, -6) === '/admin') ? '../php' : 'php';

if (isset($_GET['get_pending_count'])) {
    header('Content-Type: application/json');
    $pending_count = 0;
    $cancellation_count = 0;
    try {
        $pending_count = $pdo->query("
            SELECT COUNT(*)
            FROM bookings b
            WHERE b.status='pending' AND b.is_complete='uncomplete'
              AND NOT EXISTS (
                SELECT 1
                FROM booking_cancellation_requests cr
                WHERE cr.booking_domain='tour'
                  AND cr.booking_id=b.booking_id
                  AND cr.request_status IN ('pending','approved')
              )
        ")->fetchColumn();
        $cancellation_count = $pdo->query("
            SELECT COUNT(*) FROM booking_cancellation_requests
            WHERE request_status IN ('pending','approved')
        ")->fetchColumn();
    } catch (Throwable $error) {
        $pending_count = $pdo->query("SELECT COUNT(*) FROM bookings WHERE status='pending' AND is_complete='uncomplete'")->fetchColumn();
        $cancellation_count = 0;
    }
    echo json_encode(['count' => (int)$pending_count + (int)$cancellation_count]);
    exit;
}


?>

<style>
.admin-sidebar,
.admin-sidebar *,
.admin-sidebar *::before,
.admin-sidebar *::after {
  box-sizing: content-box;
}

.admin-sidebar {
  width: 250px;
  background: linear-gradient(180deg, #143a2d 0%, #102b22 100%);
  color: #daf7ea;
  display: flex;
  flex-direction: column;
  position: fixed;
  top: 0;
  bottom: 0;
  left: 0;
  border-right: 1px solid rgba(196, 243, 220, 0.14);
  box-shadow: 2px 0 16px rgba(0, 0, 0, 0.2);
  overflow: hidden;
  z-index: 100;
}

.admin-brand {
  display: flex;
  align-items: center;
  gap: 9px;
  height: 70px;
  min-height: 70px;
  padding: 0 14px;
  border-bottom: 1px solid rgba(208, 250, 231, 0.18);
  text-decoration: none;
}
.admin-brand img {
  width: 40px;
  height: 40px;
  object-fit: contain;
  flex-shrink: 0;
}
.admin-brand .admin-brand-text {
  max-width: 128px;
  width: 100%;
  height: auto;
  filter: drop-shadow(0 0 1px rgba(208, 248, 229, 0.38));
}

.admin-navlinks {
  flex: 1;
  overflow-y: auto;
  overflow-x: hidden;
  display: flex;
  flex-direction: column;
  gap: 0;
  padding: 11px 10px 12px;
  align-content: start;
  scrollbar-width: none;
  -ms-overflow-style: none;
}
.admin-navlinks::-webkit-scrollbar { display: none; }
.admin-navlinks a {
  text-decoration: none;
  color: #daf7ea;
  display: flex;
  align-items: center;
  gap: 10px;
  min-height: 30px;
  padding: 4px 10px;
  border-radius: 8px;
  border: 1px solid transparent;
  font-size: 0.75rem;
  font-weight: 600;
  transition: all 0.25s ease;
  white-space: nowrap;
  position: relative;
}
.admin-navlinks a:hover,
.admin-navlinks a.active {
  background: rgba(195, 245, 222, 0.16);
  color: #e8fff5;
  border-color: rgba(208, 250, 231, 0.2);
}
.admin-navlinks a.active::before {
  content: "";
  position: absolute;
  left: -10px;
  top: 8px;
  bottom: 8px;
  width: 3px;
  border-radius: 0 4px 4px 0;
  background: #8ed9bb;
}
.admin-nav-group {
  display: grid;
  gap: 0;
  padding: 0 0 5px;
  margin-top: 7px;
}
.admin-nav-group + .admin-nav-group {
  margin-top: 5px;
}
.admin-nav-heading {
  margin: 0;
  padding: 0;
}
.admin-nav-toggle {
  width: 100%;
  box-sizing: border-box;
  display: flex;
  align-items: center;
  justify-content: flex-start;
  gap: 8px;
  padding: 6px 10px 5px;
  border: 0;
  background: transparent;
  color: #9bc8b7;
  cursor: pointer;
  font-size: 0.59rem;
  font-weight: 800;
  line-height: 1.2;
  letter-spacing: 0.105em;
  text-align: left;
  text-transform: uppercase;
}
.admin-nav-toggle-label {
  min-width: 0;
  display: inline-flex;
  align-items: center;
  gap: 8px;
}
.admin-nav-group-icon {
  width: 14px;
  height: 14px;
  flex: 0 0 14px;
  fill: none;
  stroke: currentColor;
  stroke-width: 1.8;
  stroke-linecap: round;
  stroke-linejoin: round;
  opacity: .9;
}
.admin-nav-toggle .admin-nav-chevron {
  margin-left: auto;
}

.admin-nav-toggle:hover,
.admin-nav-toggle:focus-visible {
  color: #d7f3e7;
  outline: none;
}
.admin-nav-chevron {
  width: 14px;
  height: 14px;
  flex: 0 0 14px;
  fill: none;
  stroke: currentColor;
  stroke-width: 2;
  stroke-linecap: round;
  stroke-linejoin: round;
  transition: transform .2s ease;
}
.admin-nav-toggle[aria-expanded="true"] .admin-nav-chevron {
  transform: rotate(180deg);
}
.admin-nav-items {
  display: grid;
  gap: 0;
}
.admin-nav-items[hidden] {
  display: none;
}
.admin-nav-items a {
  width: auto;
  margin-left: 14px;
  padding-left: 12px;
}
.admin-nav-icon {
  width: 16px;
  height: 16px;
  margin-right: 0;
  flex: 0 0 16px;
  fill: none;
  stroke: currentColor;
  stroke-width: 1.8;
  stroke-linecap: round;
  stroke-linejoin: round;
  opacity: .88;
}

.admin-badge {
  margin-left: auto;
  background: #d8efe4;
  color: #1f614e;
  border-radius: 999px;
  font-size: 9px;
  padding: 0 6px;
  min-width: 18px;
  height: 18px;
  line-height: 1;
  text-align: center;
  font-weight: 700;
  display: none;
  align-items: center;
  justify-content: center;
}

.admin-navlinks a:hover .admin-badge,
.admin-navlinks a.active .admin-badge {
  background: rgba(232, 255, 245, 0.92);
  color: #1c5a48;
}

.admin-sidebar-bottom {
  padding: 5px 10px 7px;
  margin-top: auto;
}
.admin-account-card {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 8px 10px;
  border-radius: 14px;
  background: rgba(195, 245, 222, 0.1);
  border: 1px solid rgba(208, 250, 231, 0.14);
  color: inherit;
  text-decoration: none;
  transition: background-color .18s ease, border-color .18s ease, transform .18s ease;
}
.admin-account-card:hover,
.admin-account-card:focus-visible {
  background: rgba(195, 245, 222, 0.17);
  border-color: rgba(208, 250, 231, 0.32);
  outline: none;
  transform: translateY(-1px);
}
.admin-account-avatar {
  width: 34px;
  height: 34px;
  border-radius: 50%;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  color: #fff;
  font-size: 14px;
  font-weight: 800;
  background: linear-gradient(135deg, #2b7a66 0%, #1f614e 100%);
  border: 1px solid rgba(208, 250, 231, 0.35);
  flex-shrink: 0;
}
.admin-account-meta {
  min-width: 0;
}
.admin-account-meta strong {
  display: block;
  font-size: 11px;
  color: #e8fff5;
  font-weight: 700;
  line-height: 1.2;
}
.admin-account-meta small {
  display: block;
  margin-top: 2px;
  font-size: 10px;
  color: #b7d7ca;
  word-break: break-word;
  line-height: 1.2;
}

.admin-logout-divider {
  width: 100%;
  margin: 6px 0 3px;
  border: 0;
  border-top: 1px solid rgba(208, 250, 231, 0.24);
}
.admin-logout-btn {
  display: flex;
  align-items: center;
  justify-content: flex-start;
  gap: 8px;
  min-height: 24px;
  padding: 5px 10px;
  color: #daf7ea;
  text-decoration: none;
  border-radius: 8px;
  border: 1px solid transparent;
  font-size: 0.7rem;
  font-weight: 600;
  transition: all 0.25s ease;
}
.admin-logout-btn:hover {
  background: rgba(195, 245, 222, 0.16);
  color: #e8fff5;
  border-color: rgba(208, 250, 231, 0.2);
}
.admin-logout-btn .admin-nav-icon { color: currentColor; }

.ap-header-right {
  display: inline-flex;
  align-items: center;
  gap: 10px;
  margin-left: auto;
}

.main-content .admin-header,
.featured-main .featured-header {
  display: flex;
  align-items: center;
  gap: 12px;
}

.ap-header-notif-wrap {
  position: relative;
  display: inline-flex;
  align-items: center;
}

.ap-header-notif-btn {
  position: relative;
  width: 40px;
  min-width: 40px;
  height: 40px;
  border: 1px solid #d8e6e0;
  background: #fff;
  border-radius: 11px;
  padding: 0;
  display: inline-grid;
  place-items: center;
  cursor: pointer;
  color: #24434d;
  box-sizing: border-box;
  transition: background-color .2s ease, border-color .2s ease, color .2s ease, box-shadow .2s ease;
}

.ap-header-notif-btn:hover,
.ap-header-notif-btn[aria-expanded="true"] {
  color: #17624d;
  background: #edf7f3;
  border-color: #9fc8b9;
  box-shadow: 0 4px 12px rgba(23, 98, 77, .1);
}

.main-content .admin-header-profile,
.featured-main .admin-header-profile {
  width: 36px !important;
  height: 36px !important;
  border-radius: 50% !important;
  display: inline-flex !important;
  align-items: center !important;
  justify-content: center !important;
  overflow: hidden !important;
  background: linear-gradient(135deg, #2b7a66 0%, #1f614e 100%) !important;
  color: #fff !important;
  font-size: 13px !important;
  font-weight: 800 !important;
  border: 1px solid rgba(43, 122, 102, 0.22) !important;
  box-shadow: 0 4px 10px rgba(28, 74, 62, 0.14) !important;
  text-transform: uppercase !important;
  flex-shrink: 0 !important;
}

.main-content .admin-header-profile img,
.featured-main .admin-header-profile img {
  width: 100%;
  height: 100%;
  object-fit: cover;
  display: block;
}

.ap-header-notif-btn svg {
  width: 18px;
  height: 18px;
  fill: none;
  stroke: currentColor;
  stroke-width: 1.8;
  stroke-linecap: round;
  stroke-linejoin: round;
}

.ap-header-sr {
  position: absolute;
  width: 1px;
  height: 1px;
  padding: 0;
  margin: -1px;
  overflow: hidden;
  clip: rect(0, 0, 0, 0);
  white-space: nowrap;
  border: 0;
}

.ap-header-notif-badge {
  position: absolute;
  top: -6px;
  right: -6px;
  background: #b42335;
  color: #fff;
  border: 2px solid #fff;
  border-radius: 999px;
  min-width: 18px;
  height: 18px;
  padding: 0 5px;
  display: none;
  align-items: center;
  justify-content: center;
  font-size: 9px;
  font-weight: 800;
  line-height: 1;
}

.ap-header-notif-panel {
  position: absolute;
  right: 0;
  top: calc(100% + 8px);
  width: min(390px, calc(100vw - 28px));
  max-height: min(460px, calc(100vh - 86px));
  overflow: hidden;
  border-radius: 14px;
  border: 1px solid #b9d8cc;
  background: #fff;
  box-shadow: 0 20px 48px rgba(14, 54, 43, .2);
  z-index: 160;
  display: none;
  flex-direction: column;
  box-sizing: border-box;
}

.ap-header-notif-panel.open {
  display: flex;
}

.ap-header-notif-head {
  flex: 0 0 auto;
  box-sizing: border-box;
  display: flex;
  align-items: center;
  justify-content: space-between;
  min-height: 72px;
  padding: 13px 15px;
  border-bottom: 1px solid #0e4b3a;
  background: linear-gradient(135deg, #0d4938 0%, #17684f 60%, #238066 100%);
}

.ap-header-notif-head h4 {
  margin: 0;
  font-size: 14px;
  color: #fff;
}

.ap-header-notif-title { display:flex; align-items:center; gap:10px; min-width:0; }
.ap-header-notif-title > div { display:grid; gap:2px; }
.ap-header-notif-title small { color:rgba(255,255,255,.7); font-size:10px; font-weight:500; }
.ap-header-notif-title-icon { width:36px; height:36px; flex:0 0 36px; display:grid; place-items:center; color:#fff; border:1px solid rgba(255,255,255,.25); border-radius:10px; background:rgba(255,255,255,.13); box-shadow:inset 0 1px 0 rgba(255,255,255,.12); }
.ap-header-notif-title-icon svg { width:17px; height:17px; fill:none; stroke:currentColor; stroke-width:1.8; stroke-linecap:round; stroke-linejoin:round; }

.ap-header-notif-head button {
  border: 1px solid rgba(255,255,255,.25);
  border-radius: 8px;
  padding: 6px 8px;
  background: rgba(255,255,255,.12);
  color: #fff;
  font-size: 10px;
  font-weight: 700;
  cursor: pointer;
}
.ap-header-notif-head button:hover { background:rgba(255,255,255,.22); }

.ap-header-notif-list {
  min-height: 0;
  flex: 1 1 auto;
  padding: 11px;
  overflow-y: auto;
  overscroll-behavior: contain;
  scrollbar-gutter: stable;
  background: linear-gradient(180deg, #edf7f2 0%, #f8fbf9 100%);
}

.ap-header-notif-item {
  position: relative;
  padding: 9px 10px;
  border-radius: 10px;
  background: linear-gradient(100deg, #fff 0%, #f8fcfa 100%);
  border: 1px solid #d7e8e1;
  border-left: 3px solid #75b49f;
  margin-bottom: 8px;
  display: block;
  color: inherit;
  text-decoration: none;
  transition: background-color .2s ease, border-color .2s ease, box-shadow .2s ease;
}

.ap-header-notif-item:hover,
.ap-header-notif-item:focus-visible {
  background: #eef8f4;
  border-color: #84bca9;
  border-left-color: #17684f;
  box-shadow: 0 7px 18px rgba(20, 78, 61, .12);
  outline: none;
}

.ap-header-notif-item::after {
  content: "\2192";
  position: absolute;
  top: 50%;
  right: 11px;
  color: #4d7a6d;
  font-size: 14px;
  transform: translateY(-50%);
}

.ap-header-notif-item.is-unread {
  background: linear-gradient(90deg, #e9fff3 0%, #f7fffb 100%);
  border-color: #8fd1b3;
  box-shadow: 0 0 0 1px rgba(43, 122, 102, 0.12);
}

.ap-header-notif-item.is-unread::before {
  content: "";
  position: absolute;
  left: 0;
  top: 6px;
  bottom: 6px;
  width: 4px;
  border-radius: 999px;
  background: #1f8a63;
}

.ap-header-notif-item strong {
  display: block;
  padding-right: 24px;
  font-size: 12.5px;
  color: #143e32;
  margin-bottom: 3px;
}

.ap-header-notif-item small {
  width: fit-content;
  display: inline-flex;
  padding-right: 24px;
  padding: 3px 8px;
  border-radius: 999px;
  background: #e6f3ee;
  color: #517167;
  font-size: 11px;
}

.ap-notif-unread-pill {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  margin-left: 6px;
  padding: 1px 7px;
  border-radius: 999px;
  background: #1f8a63;
  color: #fff;
  font-size: 10px;
  font-weight: 700;
  letter-spacing: .2px;
}

.ap-notif-empty { min-height:160px; display:grid; place-items:center; align-content:center; gap:5px; color:#60786f; text-align:center; }
.ap-notif-empty span { width:38px; height:38px; display:grid; place-items:center; margin-bottom:3px; color:#fff; border-radius:12px; background:linear-gradient(135deg,#2b8b6d,#17634d); box-shadow:0 7px 16px rgba(23,99,77,.2); font-size:17px; font-weight:800; }
.ap-notif-empty strong { color:#214d40; font-size:13px; }
.ap-notif-empty small { font-size:10.5px; }
</style>

<?php require_once __DIR__ . '/../php/alert.php'; ?>
<aside class="admin-sidebar" id="adminSidebar" aria-label="Admin sidebar">
  <a class="admin-brand" href="adhomepage.php">
    <img src="img/newlogo.png" alt="iTour Mercedes logo">
    <img src="img/textlogo3.png" alt="iTour Mercedes" class="admin-brand-text">
  </a>
  <nav class="admin-navlinks">
    <a href="adhomepage.php" class="<?= $current_page === 'adhomepage.php' ? 'active' : '' ?>" <?= $current_page === 'adhomepage.php' ? 'aria-current="page"' : '' ?>>
      <svg class="admin-nav-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M3 10.8 12 3l9 7.8"/><path d="M5.5 9.5V21h13V9.5M9 21v-7h6v7"/></svg>
      <span>Dashboard</span>
    </a>
    <?php
    $adminNavGroups = [
      'Operations' => [
        ['Bookings', 'adbookings.php', 'calendar', 'adbookings.php', 'bookingBadge'],
        ['Payments & Transactions', 'adpaymenttransactions.php', 'card', 'adpaymenttransactions.php'],
        ['Earnings & Disbursements', 'adearningsdisbursements.php', 'wallet', 'adearningsdisbursements.php'],
      ],
      'Tourism Management' => [
        ['Destinations', 'addestination.php', 'map', 'addestination.php'],
        ['Featured Content', 'adfeatured.php', 'star', 'adfeatured.php'],
        ['Tour Packages', 'adtourpackages.php', 'package', 'adtourpackages.php'],
        ['Boats', 'adboats.php', 'boat', 'adboats.php'],
        ['Tour Guides', 'adtourguides.php', 'guide', 'adtourguides.php'],
      ],
      'Tourism Stakeholders' => [
        ['Tourists', 'adtourists.php', 'person', 'adtourists.php'],
        ['Tour Operators', 'adoperator.php', 'operator', 'adoperator.php'],
        ['Hotels & Resorts', 'adhotelresorts.php', 'hotel', 'adhotelresorts.php'],
      ],
      'Service Quality' => [
        ['Reviews & Feedback', 'adreviewsfeedback.php', 'star', 'adreviewsfeedback.php'],
        ['Complaints & Incidents', 'adcomplaintsincidents.php', 'alert', 'adcomplaintsincidents.php'],
      ],
      'Insights' => [
        ['Reports & Analytics', 'adreportsanalytics.php', 'chart', 'adreportsanalytics.php'],
      ],
      'System' => [
        ['Activity Logs', 'adactivitylog.php', 'history', 'adactivitylog.php'],
        ['System Settings', 'adsystemsettings.php', 'settings', 'adsystemsettings.php'],
        ['Administrator Profile', 'adadministratorprofile.php', 'profile', 'adadministratorprofile.php'],
      ],
    ];
    $adminIcons = [
      'home' => '<path d="M3 10.8 12 3l9 7.8"/><path d="M5.5 9.5V21h13V9.5M9 21v-7h6v7"/>',
      'calendar' => '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 10h18M8 14h.01M12 14h.01M16 14h.01M8 18h.01M12 18h.01"/>',
      'card' => '<rect x="2.5" y="5" width="19" height="14" rx="2"/><path d="M2.5 10h19M7 15h3"/>',
      'wallet' => '<path d="M4 6.5h14a2 2 0 0 1 2 2V19H4a2 2 0 0 1-2-2V6.5a2 2 0 0 1 2-2h12"/><path d="M20 11h-5a2 2 0 0 0 0 4h5M15 13h.01"/>',
      'bell' => '<path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9M10 21h4"/>',
      'map' => '<path d="m3 6 6-3 6 3 6-3v15l-6 3-6-3-6 3V6Z"/><path d="M9 3v15M15 6v15"/>',
      'package' => '<path d="m12 3 9 4.5-9 4.5-9-4.5L12 3Z"/><path d="m3 7.5 9 4.5 9-4.5M3 12l9 4.5 9-4.5M3 16.5l9 4.5 9-4.5"/>',
      'boat' => '<path d="M3 18c2 2 4 2 6 0 2 2 4 2 6 0 2 2 4 2 6 0M5 14h14l-2.5 4H7.5L5 14ZM8 14V7h8v7M12 7V3l4 2-4 2Z"/>',
      'guide' => '<circle cx="12" cy="7" r="3"/><path d="M5 21v-2a7 7 0 0 1 14 0v2M4 10h4M16 10h4"/>',
      'operator' => '<circle cx="9" cy="8" r="3"/><path d="M3 21v-2a6 6 0 0 1 12 0v2M16 7h5M18.5 4.5v5M16.5 14H21M16.5 18H21"/>',
      'hotel' => '<path d="M4 21V5h10v16M14 10h6v11M8 9h2M8 13h2M8 17h2M17 14h1M17 17h1M2 21h20"/>',
      'approval' => '<path d="M12 3 4 6v6c0 5 3.4 8 8 9 4.6-1 8-4 8-9V6l-8-3Z"/><path d="m8.5 12 2.3 2.3 4.7-4.8"/>',
      'users' => '<circle cx="9" cy="8" r="3"/><path d="M3 21v-2a6 6 0 0 1 12 0v2M16 4.5a3 3 0 0 1 0 7M17 15a5 5 0 0 1 4 4.9V21"/>',
      'person' => '<circle cx="12" cy="8" r="3.5"/><path d="M5 21v-2a7 7 0 0 1 14 0v2"/>',
      'star' => '<path d="m12 3 2.8 5.7 6.2.9-4.5 4.4 1.1 6.2-5.6-3-5.6 3 1.1-6.2L3 9.6l6.2-.9L12 3Z"/>',
      'alert' => '<path d="M12 3 2.8 20h18.4L12 3Z"/><path d="M12 9v5M12 17.5h.01"/>',
      'megaphone' => '<path d="m3 11 15-6v14L3 13v-2ZM18 9a3 3 0 0 1 0 6M6 14l1.5 6h4L10 15.5"/>',
      'help' => '<circle cx="12" cy="12" r="9"/><path d="M9.8 9a2.4 2.4 0 1 1 3.2 2.3c-.7.3-1 1-1 1.7M12 17h.01"/>',
      'chart' => '<path d="M4 20V10M10 20V4M16 20v-7M22 20H2"/>',
      'history' => '<path d="M3 12a9 9 0 1 0 3-6.7L3 8"/><path d="M3 3v5h5M12 7v5l3 2"/>',
      'settings' => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.9l.1.1-2.8 2.8-.1-.1a1.7 1.7 0 0 0-1.9-.3 1.7 1.7 0 0 0-1 1.6v.2h-4V21a1.7 1.7 0 0 0-1-1.6 1.7 1.7 0 0 0-1.9.3l-.1.1L4.2 17l.1-.1a1.7 1.7 0 0 0 .3-1.9A1.7 1.7 0 0 0 3 14H2.8v-4H3a1.7 1.7 0 0 0 1.6-1 1.7 1.7 0 0 0-.3-1.9L4.2 7 7 4.2l.1.1A1.7 1.7 0 0 0 9 4.6 1.7 1.7 0 0 0 10 3v-.2h4V3a1.7 1.7 0 0 0 1 1.6 1.7 1.7 0 0 0 1.9-.3l.1-.1L19.8 7l-.1.1a1.7 1.7 0 0 0-.3 1.9 1.7 1.7 0 0 0 1.6 1h.2v4H21a1.7 1.7 0 0 0-1.6 1Z"/>',
      'profile' => '<circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/>',
    ];
    $adminGroupIcons = [
      'Operations' => '<rect x="3" y="6" width="18" height="14" rx="2"/><path d="M8 6V4h8v2M3 11h18M10 11v2h4v-2"/>',
      'Tourism Management' => '<circle cx="12" cy="12" r="9"/><path d="m15.5 8.5-2.1 4.9-4.9 2.1 2.1-4.9 4.9-2.1Z"/>',
      'Tourism Stakeholders' => $adminIcons['users'],
      'Service Quality' => $adminIcons['approval'],
      'Insights' => '<path d="M4 5h16v11H8l-4 4V5Z"/><path d="M8 9h8M8 12h5"/>',
      'System' => '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/>',
    ];
    foreach ($adminNavGroups as $group => $items):
      $groupSlug = strtolower(trim(preg_replace('/[^a-z0-9]+/i', '-', $group), '-'));
      $groupHasActivePage = false;
      foreach ($items as $groupItem) {
        $groupMatch = $groupItem[3];
        $groupItemActive = str_ends_with($groupMatch, '.php')
          ? $current_page === $groupMatch
          : $current_admin_view === $groupMatch;
        if ($groupItemActive) {
          $groupHasActivePage = true;
          break;
        }
      }
      $groupOpen = in_array($group, ['Operations', 'Tourism Management'], true) || $groupHasActivePage;
    ?>
      <section class="admin-nav-group" aria-labelledby="admin-nav-<?= htmlspecialchars($groupSlug) ?>">
        <h2 class="admin-nav-heading" id="admin-nav-<?= htmlspecialchars($groupSlug) ?>">
          <button type="button" class="admin-nav-toggle" aria-expanded="<?= $groupOpen ? 'true' : 'false' ?>" aria-controls="admin-nav-items-<?= htmlspecialchars($groupSlug) ?>">
            <span class="admin-nav-toggle-label">
              <svg class="admin-nav-group-icon" viewBox="0 0 24 24" aria-hidden="true"><?= $adminGroupIcons[$group] ?? $adminIcons['package'] ?></svg>
              <span><?= htmlspecialchars($group) ?></span>
            </span>
            <svg class="admin-nav-chevron" viewBox="0 0 24 24" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>
          </button>
        </h2>
        <div class="admin-nav-items" id="admin-nav-items-<?= htmlspecialchars($groupSlug) ?>" <?= $groupOpen ? '' : 'hidden' ?>>
        <?php foreach ($items as $item):
          [$label, $href, $icon, $match] = $item;
          $active = str_ends_with($match, '.php') ? $current_page === $match : $current_admin_view === $match;
          $badgeId = $item[4] ?? '';
        ?>
          <a href="<?= htmlspecialchars($href) ?>" class="<?= $active ? 'active' : '' ?>" <?= $active ? 'aria-current="page"' : '' ?>>
            <svg class="admin-nav-icon" viewBox="0 0 24 24" aria-hidden="true"><?= $adminIcons[$icon] ?></svg>
            <span><?= htmlspecialchars($label) ?></span>
            <?php if ($badgeId): ?><span class="admin-badge" id="<?= htmlspecialchars($badgeId) ?>">0</span><?php endif; ?>
          </a>
        <?php endforeach; ?>
        </div>
      </section>
    <?php endforeach; ?>
  </nav>

  <div class="admin-sidebar-bottom">
    <a class="admin-account-card" href="adadministratorprofile.php" aria-label="Open administrator profile">
      <span class="admin-account-avatar" aria-hidden="true"><?= htmlspecialchars($admin_initial) ?></span>
      <div class="admin-account-meta">
        <strong title="<?= $admin_username; ?>"><?= $admin_username; ?></strong>
        <small title="<?= $admin_email; ?>"><?= $admin_email !== '' ? $admin_email : 'Administrator account'; ?></small>
      </div>
    </a>
    <hr class="admin-logout-divider">
    <a href="#" class="admin-logout-btn" onclick="confirmAdminLogout(event)"><svg class="admin-nav-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M10 4H5a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h5M14 8l4 4-4 4M18 12H9"/></svg><span>Logout</span></a>
  </div>
</aside>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
(function installAdminSessionFetchGuard() {
    if (window.__adminSessionFetchGuardInstalled) return;
    window.__adminSessionFetchGuardInstalled = true;
    const originalFetch = window.fetch.bind(window);

    window.fetch = async function adminSessionFetch(input, init = {}) {
        const requestUrl = new URL(input instanceof Request ? input.url : String(input), window.location.href);
        const options = { ...init };
        if (requestUrl.origin === window.location.origin) {
            const headers = new Headers(input instanceof Request ? input.headers : undefined);
            new Headers(init.headers || {}).forEach((value, key) => headers.set(key, value));
            headers.set('X-Requested-With', 'XMLHttpRequest');
            headers.set('X-Admin-Return-To', window.location.pathname + window.location.search);
            options.headers = headers;
        }

        const response = await originalFetch(input, options);
        if (response.status === 401) {
            try {
                const payload = await response.clone().json();
                if (payload?.code === 'SESSION_EXPIRED' && payload?.login_url) {
                    window.location.assign(payload.login_url);
                }
            } catch (_error) {
                // Let the page's existing request handler process non-session errors.
            }
        }
        return response;
    };
})();

function confirmAdminLogout(event) {
    event.preventDefault();
    Swal.fire({
        icon: "question",
        title: "Sign out?",
        text: "Are you sure you want to leave the administrator portal?",
        showCancelButton: true,
        confirmButtonColor: "#176b55",
        cancelButtonColor: "#687b75",
        confirmButtonText: "Yes, sign out",
        cancelButtonText: "Stay signed in",
        reverseButtons: true,
        focusCancel: true
    }).then((result) => {
        if (result.isConfirmed) {
            window.location.href = "adhomepage.php?action=logout";
        }
    });
}
async function fetchPendingBookings() {
    try {
        const response = await fetch('<?= basename(__FILE__); ?>?get_pending_count=1');
        const data = await response.json();
        const badge = document.getElementById('bookingBadge');
        if (data.count > 0) {
            badge.style.display = 'inline-flex';
            badge.textContent = data.count;
        } else {
            badge.style.display = 'none';
        }
    } catch (err) {
        console.error('Failed to fetch pending bookings:', err);
    }
}


// Initial fetch + repeat every 5 seconds
fetchPendingBookings();
setInterval(fetchPendingBookings, 2000);

document.querySelectorAll('.admin-nav-toggle').forEach(toggle => {
    const groupId = toggle.getAttribute('aria-controls');
    const items = document.getElementById(groupId);
    if (!items) return;

    toggle.addEventListener('click', () => {
        const willOpen = toggle.getAttribute('aria-expanded') !== 'true';
        toggle.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
        items.hidden = !willOpen;
    });
});

const adminNavScroller = document.querySelector('.admin-navlinks');
const adminNavScrollKey = 'itour-admin-sidebar-scroll';
if (adminNavScroller) {
    let savedAdminNavScroll = 0;
    try {
        savedAdminNavScroll = Number(sessionStorage.getItem(adminNavScrollKey) || 0);
    } catch (error) {
        savedAdminNavScroll = 0;
    }

    requestAnimationFrame(() => {
        adminNavScroller.scrollTop = savedAdminNavScroll;
    });

    const saveAdminNavScroll = () => {
        try {
            sessionStorage.setItem(adminNavScrollKey, String(adminNavScroller.scrollTop));
        } catch (error) {
            // Scrolling remains available when browser storage is unavailable.
        }
    };

    adminNavScroller.addEventListener('scroll', saveAdminNavScroll, { passive: true });
    adminNavScroller.querySelectorAll('a').forEach(link => {
        link.addEventListener('click', saveAdminNavScroll);
    });
}

document.addEventListener('DOMContentLoaded', () => {
    const headers = document.querySelectorAll('.main-content .admin-header, .featured-main .featured-header');
    const notifApiBase = <?= json_encode($phpApiBase) ?>;
    const adminInitial = <?= json_encode($admin_initial) ?>;
    const adminName = <?= json_encode(trim(strip_tags($admin_username)) !== '' ? trim(strip_tags($admin_username)) : 'Website Admin') ?>;
    const adminProfileImage = <?= json_encode($admin_profile_picture) ?>;
    let notifCache = null;
    let markRequested = false;

    const escapeHtml = value => String(value ?? '').replace(/[&<>'"]/g, character => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#039;', '"': '&quot;'
    })[character]);

    const renderNotifItems = (container, items) => {
        if (!container) return;
        if (!Array.isArray(items) || items.length === 0) {
            container.innerHTML = '<div class="ap-notif-empty"><span>✓</span><strong>You are all caught up</strong><small>New booking activity will appear here.</small></div>';
            return;
        }
        container.innerHTML = items.map(item => {
            const isUnread = String(item?.is_notif_viewed ?? 0) === '0';
            const unreadClass = isUnread ? ' is-unread' : '';
            const unreadPill = isUnread ? '<span class="ap-notif-unread-pill">NEW</span>' : '';
            const bookingId = item.booking_id ?? '';
            const bookingReference = item.booking_reference || bookingId || 'Booking';
            const fullName = escapeHtml(item.full_name ?? 'Tourist');
            const bookingType = escapeHtml(item.booking_type ?? 'Booking');
            const createdAt = escapeHtml(item.notification_at || item.created_at || '');
            const bookingLabel = escapeHtml(bookingReference);
            const decision = String(item.tourist_decision || '').toLowerCase();
            const isRescheduled = decision === 'reschedule';
            const isFullRefund = decision === 'full_refund' || decision === 'tourist_full_refund';
            const detail = isRescheduled
                ? `Tourist rescheduled to ${escapeHtml(item.rescheduled_service_date || item.booking_date || '-')}`
                : (isFullRefund ? 'Tourist chose cancellation and a full refund' : `${bookingType} &bull; ${createdAt}`);
            const href = isFullRefund && item.cancellation_request_id
                ? `adbookings.php?tab=cancellations&focus_request=${encodeURIComponent(item.cancellation_request_id)}`
                : `adbookings.php?search=${encodeURIComponent(bookingId)}`;
            return `
                <a class="ap-header-notif-item${unreadClass}" href="${href}" aria-label="View booking ${bookingLabel}">
                    <strong>${bookingLabel} &mdash; ${fullName}${unreadPill}</strong>
                    <small>${detail}${(isRescheduled || isFullRefund) && createdAt ? ` &bull; ${createdAt}` : ''}</small>
                </a>
            `;
        }).join('');
    };

    const fetchNotifications = () => {
        return fetch(`${notifApiBase}/fetch_notifications.php`, { cache: 'no-store' })
            .then(r => r.json())
            .then(payload => {
                notifCache = payload;
                return payload;
            })
            .catch(() => ({ unread: 0, data: [] }));
    };

    const renderNotificationState = (badgeEl, listEl, payload, forceHideBadge = false) => {
        const safePayload = payload || {};
        const unread = parseInt(safePayload.unread || 0, 10);
        renderNotifItems(listEl, safePayload.data || []);
        if (forceHideBadge) {
            badgeEl.style.display = 'none';
        } else if (unread > 0) {
            badgeEl.style.display = 'inline-flex';
            badgeEl.textContent = unread;
        } else {
            badgeEl.style.display = 'none';
        }
    };

    const markNotificationsRead = () => {
        if (markRequested) return Promise.resolve();
        markRequested = true;
        return fetch(`${notifApiBase}/mark_notif_badge.php`, {
            method: 'POST', cache: 'no-store', keepalive: true,
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: new URLSearchParams({csrf_token: <?= json_encode($adminNotificationCsrf, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>})
        })
            .then(() => {
                if (notifCache && Array.isArray(notifCache.data)) {
                    notifCache.data = notifCache.data.map(n => {
                        n.is_notif_viewed = 1;
                        return n;
                    });
                }
            })
            .catch(() => {})
            .finally(() => {
                markRequested = false;
            });
    };

    headers.forEach(header => {
        let left = header.querySelector('.admin-header-left');
        if (!left) {
            const title = header.querySelector('h1, h2, h3');
            if (title) {
                left = document.createElement('div');
                left.className = 'admin-header-left';
                title.parentNode.insertBefore(left, title);
                left.appendChild(title);
            }
        }
        if (left && !left.querySelector('.admin-header-subtitle')) {
            const subtitle = document.createElement('p');
            subtitle.className = 'admin-header-subtitle';
            subtitle.textContent = `Welcome, ${adminName}`;
            left.appendChild(subtitle);
        }

        const existingProfile = header.querySelector('.admin-header-profile');
        if (existingProfile && adminProfileImage && !existingProfile.querySelector('img')) {
            existingProfile.innerHTML = `<img src="${adminProfileImage}" alt="Admin profile" onerror="this.onerror=null;this.parentElement.textContent='${adminInitial || 'A'}';">`;
        }

        if (header.querySelector('.header-notification, #notificationIcon') && header.querySelector('.admin-header-profile')) {
            return;
        }

        let right = header.querySelector('.admin-header-right');
        if (!right) {
            right = document.createElement('div');
            right.className = 'admin-header-right';
            header.appendChild(right);
        }
        right.classList.add('ap-header-right');

        const headerNav = header.querySelector(':scope > nav, :scope > .nav-links');
        if (headerNav) {
            headerNav.classList.add('admin-header-nav');
            if (headerNav.parentElement !== right) {
                right.prepend(headerNav);
            }
        }

        let notifWrap = right.querySelector('.ap-header-notif-wrap');
        if (!notifWrap) {
            notifWrap = document.createElement('div');
            notifWrap.className = 'ap-header-notif-wrap';
            notifWrap.innerHTML = `
                <button type="button" class="ap-header-notif-btn" aria-expanded="false">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9M10 21h4"></path></svg>
                    <span class="ap-header-sr">Notifications</span>
                    <strong class="ap-header-notif-badge">0</strong>
                </button>
                <div class="ap-header-notif-panel" role="dialog" aria-label="Notifications">
                    <div class="ap-header-notif-head">
                        <div class="ap-header-notif-title">
                            <span class="ap-header-notif-title-icon"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9M10 21h4"></path></svg></span>
                            <div><h4>Booking Notifications</h4><small>Recent booking activity</small></div>
                        </div>
                        <button type="button" class="ap-header-mark-read">Mark all read</button>
                    </div>
                    <div class="ap-header-notif-list"></div>
                </div>
            `;
            right.appendChild(notifWrap);
        }

        if (!right.querySelector('.admin-header-profile')) {
            const profile = document.createElement('span');
            profile.className = 'admin-header-profile';
            profile.setAttribute('aria-label', 'Admin profile');
            if (adminProfileImage) {
                profile.innerHTML = `<img src="${adminProfileImage}" alt="Admin profile" onerror="this.onerror=null;this.parentElement.textContent='${adminInitial || 'A'}';">`;
            } else {
                profile.textContent = adminInitial || 'A';
            }
            right.appendChild(profile);
        }

        const btn = notifWrap.querySelector('.ap-header-notif-btn');
        const panel = notifWrap.querySelector('.ap-header-notif-panel');
        const badge = notifWrap.querySelector('.ap-header-notif-badge');
        const list = notifWrap.querySelector('.ap-header-notif-list');
        const markReadBtn = notifWrap.querySelector('.ap-header-mark-read');
        if (!btn || !panel || !badge || !list || !markReadBtn) return;

        const refreshNotifications = () => {
            return fetchNotifications().then(payload => {
                renderNotificationState(badge, list, payload, panel.classList.contains('open'));
                return payload;
            });
        };

        const closePanel = () => {
            panel.classList.remove('open');
            btn.setAttribute('aria-expanded', 'false');
        };

        refreshNotifications();
        setInterval(refreshNotifications, 4000);

        btn.addEventListener('click', () => {
            const isOpen = !panel.classList.contains('open');
            if (!isOpen) {
                closePanel();
                return;
            }
            panel.classList.add('open');
            btn.setAttribute('aria-expanded', 'true');
            refreshNotifications().then(() => {
                badge.style.display = 'none';
                return markNotificationsRead();
            }).then(() => {
                if (notifCache) renderNotifItems(list, notifCache.data || []);
            });
        });

        markReadBtn.addEventListener('click', () => {
            markNotificationsRead().then(() => {
                badge.style.display = 'none';
                if (notifCache && Array.isArray(notifCache.data)) {
                    renderNotifItems(list, notifCache.data);
                }
            });
        });

        document.addEventListener('click', (e) => {
            if (!notifWrap.contains(e.target)) {
                closePanel();
            }
        });
    });
});

</script>
