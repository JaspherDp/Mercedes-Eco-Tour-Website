<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once 'db_connection.php';

$user = null;

if (isset($_SESSION['tourist_id'])) {
    $stmt = $pdo->prepare("SELECT * FROM tourist WHERE tourist_id = ?");
    $stmt->execute([$_SESSION['tourist_id']]);
    $tourist = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($tourist) {
        $nameParts = explode(" ", $tourist['full_name'], 2);
        $firstName = $nameParts[0] ?? '';
        $lastName  = $nameParts[1] ?? '';

        $user = [
            "id"              => $tourist['tourist_id'],
            "first_name"      => $firstName,
            "last_name"       => $lastName,
            "email"           => $tourist['email'],
            "phone"           => $tourist['phone'] ?? "",
            "profile_picture" => $tourist['profile_picture'] ?? null,
            "google_id"       => $tourist['google_id'] ?? null
        ];
    }
}

$currentPage = basename(parse_url($_SERVER['HTTP_REFERER'] ?? '', PHP_URL_PATH));
$bookingSuccessNotif = isset($_GET['booking_success']) && $_GET['booking_success'] === '1';
$bookingRefNotif = isset($_GET['booking_ref']) ? (int)$_GET['booking_ref'] : 0;
$notifCount = $bookingSuccessNotif ? 1 : 0;
$notifKey = $bookingSuccessNotif ? ($bookingRefNotif > 0 ? ('booking-success-' . $bookingRefNotif) : 'booking-success') : '';
$bookingNotifMessage = $bookingSuccessNotif
  ? ('Booking request submitted successfully' . ($bookingRefNotif > 0 ? ' (#' . $bookingRefNotif . ')' : '') . '. Please wait for confirmation on your inputted email.')
  : '';

function isDefaultProfileImage($value)
{
    $name = strtolower(basename((string) $value));
    return in_array($name, ['profileicon.png', 'profileicon2.png'], true);
}

function normalizeProfileImage($value)
{
    $candidate = trim((string) $value);
    if ($candidate === '') {
        return '';
    }
    if (preg_match('#^https?://#i', $candidate)) {
        if (stripos($candidate, 'profiles.google.com') !== false && preg_match('#profiles\\.google\\.com/(?:s2/photos/profile/)?([^/?#]+)(?:/picture)?#i', $candidate, $m)) {
            return 'https://profiles.google.com/' . rawurlencode($m[1]) . '/picture?sz=256';
        }
        if (stripos($candidate, 'googleusercontent.com') !== false) {
            $candidate = preg_replace('/([?&])sz=\\d+/i', '$1sz=256', $candidate);
            $candidate = preg_replace('/=s\\d+-c(?=$|[?&#])/i', '=s256-c', $candidate);
            $candidate = preg_replace('/=s\\d+(?=$|[?&#])/i', '=s256', $candidate);
        }
        return $candidate;
    }
    return ltrim($candidate, '/');
}

function buildGoogleProfileImageById($googleId)
{
    $id = trim((string)$googleId);
    if ($id === '') {
        return '';
    }
    return 'https://profiles.google.com/' . rawurlencode($id) . '/picture?sz=256';
}

$profileImage = '';
if (!empty($user['profile_picture']) && !isDefaultProfileImage($user['profile_picture'])) {
    $candidate = normalizeProfileImage($user['profile_picture']);
    if ($candidate !== '') {
        $profileImage = $candidate;
    }
}

if ($profileImage === '' && !empty($_SESSION['tourist_profile_pic']) && !isDefaultProfileImage($_SESSION['tourist_profile_pic'])) {
    $candidate = normalizeProfileImage($_SESSION['tourist_profile_pic']);
    if ($candidate !== '') {
        $profileImage = $candidate;
    }
}

if ($profileImage === '') {
    $googleProfileImage = buildGoogleProfileImageById($user['google_id'] ?? '');
    if ($googleProfileImage !== '') {
        $profileImage = $googleProfileImage;
    }
}

$profileName = trim((string)(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? '')));
if ($profileName === '') {
    $profileName = trim((string)($user['full_name'] ?? $_SESSION['tourist_name'] ?? ''));
}
$profileInitial = strtoupper(function_exists('mb_substr') ? mb_substr(($profileName !== '' ? $profileName : 'U'), 0, 1) : substr(($profileName !== '' ? $profileName : 'U'), 0, 1));

?>

<header class="head-nav-main-header">
  <div class="head-nav-container">

    <!-- LEFT: Logos ONLY -->
    <div class="head-nav-left">
      <img src="img/newlogo.png" class="head-nav-logo">
      <img src="img/textlogo2.png" class="head-navtext-logo">
    </div>

    <!-- RIGHT GROUP -->
    <div class="head-nav-right">

      <!-- NAV LINKS -->
<nav class="head-nav-center">
  <a href="homepage.php" class="<?= ($currentPage === 'homepage.php') ? 'active' : '' ?>">HOME</a>
  <a href="destination.php" class="<?= ($currentPage == 'destination.php') ? 'active' : '' ?>">DESTINATIONS</a>
  <a href="tourss.php" class="<?= ($currentPage == 'tourss.php') ? 'active' : '' ?>">TOURS</a>
  <a href="hotel_resorts.php" class="<?= ($currentPage == 'hotel_resorts.php') ? 'active' : '' ?>">HOTEL & RESORTS</a>
  <a href="about.php" class="<?= ($currentPage == 'about.php') ? 'active' : '' ?>">ABOUT</a>
</nav>
      <!-- NOTIFICATION ICON -->
      <div class="head-nav-notif-wrap"
           data-notif-key="<?= htmlspecialchars($notifKey) ?>"
           data-notif-active="<?= $bookingSuccessNotif ? '1' : '0' ?>"
           data-notif-title="Booking Submitted"
           data-notif-message="<?= htmlspecialchars($bookingNotifMessage) ?>">
        <button class="head-nav-notif-btn" id="headNavNotifBtn" type="button" aria-expanded="false" aria-label="Notifications">
          <img src="img/notificon.png" alt="Notifications">
          <?php if ($notifCount > 0): ?>
            <span class="head-nav-notif-badge" id="headNavNotifBadge"><?= $notifCount ?></span>
          <?php endif; ?>
        </button>

        <div class="head-nav-notif-panel" id="headNavNotifPanel" aria-label="Notification panel">
          <div class="head-nav-notif-panel-head">Notifications</div>
          <div class="head-nav-notif-section-title" id="headNavNotifNewTitle" style="display:none;">New Notifications</div>
          <div class="head-nav-notif-list" id="headNavNotifList"></div>
          <div class="head-nav-notif-section-title" id="headNavNotifOldTitle" style="display:none;">Old Notifications</div>
          <div class="head-nav-notif-list" id="headNavNotifOldList"></div>
          <div class="head-nav-notif-empty" id="headNavNotifEmpty">No new notifications.</div>
        </div>
      </div>

      <!-- PROFILE / LOGIN -->
      <?php if ($user): ?>
        <div class="head-nav-profile-container">
          <div class="head-nav-profile-wrapper">
            <div class="head-nav-profile-icon-wrapper" id="profileBtn">
              <?php if ($profileImage !== ''): ?>
                <img
                  src="<?= htmlspecialchars($profileImage, ENT_QUOTES, 'UTF-8') ?>"
                  class="head-nav-profile-icon"
                  onerror="this.onerror=null;this.style.display='none';var fallback=this.parentElement.querySelector('.head-nav-profile-fallback');if(fallback){fallback.style.display='inline-flex';}">
              <?php endif; ?>
              <span class="head-nav-profile-fallback"<?= $profileImage !== '' ? ' style="display:none;"' : '' ?>><?= htmlspecialchars($profileInitial, ENT_QUOTES, 'UTF-8') ?></span>
              <img src="img/dropdownicon3.png" class="head-nav-dropdown-arrow" alt="" aria-hidden="true">
            </div>

            <div class="head-nav-profile-dropdown" id="profileDropdown">
              <a href="php/profile.php" class="head-nav-dropdown-item">
                <svg viewBox="0 0 24 24" aria-hidden="true">
                  <path d="M12 12a5 5 0 1 0-5-5 5 5 0 0 0 5 5Zm0 2c-4.33 0-8 2.17-8 5v1h16v-1c0-2.83-3.67-5-8-5Z"></path>
                </svg>
                <span>Profile</span>
              </a>
              <a href="#" id="logoutBtn" class="head-nav-dropdown-item">
                <svg viewBox="0 0 24 24" aria-hidden="true">
                  <path d="M15.75 3a1 1 0 0 1 1 1v3.25a1 1 0 1 1-2 0V5H8v14h6.75v-2.25a1 1 0 1 1 2 0V20a1 1 0 0 1-1 1H7a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1ZM19.71 11.29l-2.5-2.5a1 1 0 0 0-1.42 1.42L16.59 11H11a1 1 0 1 0 0 2h5.59l-.8.79a1 1 0 0 0 1.42 1.42l2.5-2.5a1 1 0 0 0 0-1.42Z"></path>
                </svg>
                <span>Logout</span>
              </a>
            </div>
          </div>
        </div>
      <?php else: ?>
        <button class="head-nav-btn-login" id="openModalBtn">Login</button>
      <?php endif; ?>

    </div>

  </div>
</header>

<?php if ($currentPage === 'homepage.php'): ?>

<!-- SECOND NAVBAR -->
<div class="head-subnav">
  <a href="tourss.php#popular-destinations">
    Popular Destinations
    <img src="img/dropdownicon2.png" class="head-subnav-dropdown-icon">
  </a>

  <a href="tourss.php#popular-packages">
    Popular Packages
    <img src="img/dropdownicon2.png" class="head-subnav-dropdown-icon">
  </a>

  <a href="hotel_resorts.php#resorts">
    Hotel & Resorts
    <img src="img/dropdownicon2.png" class="head-subnav-dropdown-icon">
  </a>

  <span class="head-subnav-separator">|</span>

  <a href="tourss.php#book-tour" class="head-subnav-btn">
    <img src="img/bookingicon.png" class="head-subnav-icon">
    Book a Tour
  </a>
</div>

<style>
body {
  padding-top: calc(var(--main-nav) + var(--sub-nav)) !important;
}
</style>

<?php endif; ?>

<?php if ($currentPage !== 'homepage.php'): ?>
<style>
.head-nav-main-header {
  border-bottom: 3px solid #2b7a66 !important;
}

body {
  padding-top: var(--main-nav) !important;
}
</style>
<?php endif; ?>

<style>
:root {
  --main-nav: 70px;
  --sub-nav: 55px;
}
* {
  box-sizing: border-box;
}

/* ===== MAIN HEADER ===== */
.head-nav-main-header {
  position: fixed;
  top: 0;
  left: 0;
  width: 100%;
  z-index: 3000;
  height: var(--main-nav);
  background: white;
  border-bottom: 3px solid #2b7a66
}

/* ===== CONTAINER ===== */
.head-nav-container {
  display: flex;
  align-items: center;
  padding: 12px 40px;
}

/* ===== LEFT LOGOS ===== */
.head-nav-left {
  margin-top: 10px;
  margin-left: 30px;
  display: flex;
  align-items: center;
  gap: 15px;
}

.head-nav-logo {
  margin-top: -10px;
  height: 45px;
  object-fit: contain;
}

.head-navtext-logo {
  margin-top: -6px;
  height: 35px;
  object-fit: contain;
}

/* ===== RIGHT GROUP ===== */
.head-nav-right {
  margin-left: auto;
  display: flex;
  align-items: center;
  gap: 18px;
}

/* ===== NAV LINKS ===== */
.head-nav-center {
  display: flex;
  gap: 40px;
}

.head-nav-center a::after {
  z-index: 9999;
}

.head-nav-center a {
  text-decoration: none;
  font-weight: 600;
  font-size: 14px;
  color: black;
}

.head-nav-center a {
  position: relative;
  display: inline-block;
  margin-top: 3px;
}

.head-nav-center a.active::after {
  content: "";
  position: absolute;
  left: 0;
  bottom: -5px;
  width: 100%;
  height: 3px;
  background: #2b7a66; /* make it obvious */
}

.head-nav-center a {
  position: relative;
}

.head-nav-center a::after {
  content: "";
  position: absolute;
  left: 0;
  bottom: -5px;
  width: 0%;
  height: 3px;
  background: #2b7a66;
  transition: 0.3s;
}

.head-nav-center a:hover::after,
.head-nav-center a.active::after {
  width: 100%;
}

/* ===== NOTIF BUTTON ===== */
.head-nav-notif-btn {
  background: none;
  border: none;
  cursor: pointer;
  padding: 0;
  margin-top: 0;
  margin-right: 0;
  position: relative;
  display: inline-flex;
  align-items: center;
  justify-content: center;
}

.head-nav-notif-btn img {
  width: 25px;
  height: 25px;
  filter: invert(1);
}

.head-nav-notif-wrap {
  position: relative;
  display: inline-flex;
  align-items: center;
  isolation: isolate;
  margin-right: 0;
}

.head-nav-notif-badge {
  position: absolute;
  top: -6px;
  right: -8px;
  min-width: 18px;
  height: 18px;
  border-radius: 999px;
  background: #c93a3a;
  color: #fff;
  font-size: 11px;
  font-weight: 700;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  padding: 0 5px;
  border: 2px solid #fff;
  line-height: 1;
}

.head-nav-notif-quick {
  position: absolute;
  top: calc(100% + 10px);
  right: -30px;
  width: min(410px, calc(100vw - 24px));
  background: #fff;
  border: 1px solid #dbe7e3;
  border-radius: 12px;
  box-shadow: 0 12px 28px rgba(16, 59, 46, 0.14);
  padding: 12px 36px 12px 12px;
  z-index: 1400;
  pointer-events: auto;
}

.head-nav-notif-quick::before,
.head-nav-notif-panel::before {
  content: "";
  position: absolute;
  top: -9px;
  right: 14px;
  width: 16px;
  height: 16px;
  background: #fff;
  border-top: 1px solid #dbe7e3;
  border-left: 1px solid #dbe7e3;
  transform: rotate(45deg);
}

.head-nav-notif-quick-title {
  font-size: 13px;
  font-weight: 700;
  color: #1f5f4f;
  margin-bottom: 4px;
  letter-spacing: 0.01em;
}

.head-nav-notif-quick-text {
  font-size: 13px;
  color: #29444e;
  line-height: 1.45;
}

.head-nav-notif-quick-close {
  position: absolute;
  top: 8px;
  right: 8px;
  border: 1px solid #d5e4de;
  background: #f6fbf8;
  color: #2b7a66;
  width: 22px;
  height: 22px;
  border-radius: 999px;
  font-size: 15px;
  line-height: 1;
  cursor: pointer;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  pointer-events: auto;
}

.head-nav-notif-quick-close:hover {
  background: #edf6f2;
}

.head-nav-notif-panel {
  position: absolute;
  top: calc(100% + 10px);
  right: -30px;
  width: min(420px, calc(100vw - 24px));
  background: #fff;
  border: 1px solid #dbe7e3;
  border-radius: 12px;
  box-shadow: 0 14px 30px rgba(16, 59, 46, 0.16);
  z-index: 1410;
  display: none;
  overflow: hidden;
}

.head-nav-notif-panel.show {
  display: block;
}

.head-nav-notif-panel-head {
  padding: 11px 13px;
  border-bottom: 1px solid #ebf2ef;
  font-size: 13px;
  font-weight: 700;
  color: #214f43;
  background: #f8fcfa;
}

.head-nav-notif-list {
  max-height: 190px;
  overflow-y: auto;
}

.head-nav-notif-section-title {
  padding: 8px 13px 7px;
  font-size: 11px;
  font-weight: 700;
  letter-spacing: 0.08em;
  text-transform: uppercase;
  color: #5e757d;
  background: #fbfdfc;
  border-top: 1px solid #edf3f1;
  border-bottom: 1px solid #edf3f1;
}

.head-nav-notif-item {
  padding: 12px 13px;
  display: grid;
  gap: 4px;
  border-bottom: 1px solid #eef4f1;
}

.head-nav-notif-item.new {
  background: #f2faf6;
}

.head-nav-notif-item:last-child {
  border-bottom: 0;
}

.head-nav-notif-item strong {
  font-size: 13px;
  color: #205a4b;
}

.head-nav-notif-item span {
  font-size: 13px;
  color: #2c4953;
  line-height: 1.45;
}

.head-nav-notif-empty {
  padding: 14px 13px;
  font-size: 13px;
  color: #5a6f78;
}

/* ===== LOGIN BUTTON ===== */
.head-nav-btn-login {
  padding: 6px 16px;
  border-radius: 20px;
  border: none;
  background-color: #2b7a66;
  color: white;
  font-weight: bold;
  cursor: pointer;
}

.head-nav-btn-login:hover{
  background-color: #144d1c;
}

/* ===== PROFILE ===== */
.head-nav-profile-wrapper {
  position: relative;
  z-index: 3200;
}

.head-nav-profile-icon-wrapper {
  position: relative;
  width: 38px;
  height: 38px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  cursor: pointer;
  border-radius: 50%;
  overflow: visible;
}

.head-nav-profile-icon {
  width: 38px;
  height: 38px;
  border-radius: 50%;
  object-fit: cover;
}

.head-nav-profile-fallback {
  width: 38px;
  height: 38px;
  border-radius: 50%;
  background: #e1ece8;
  color: #1f5f4e;
  font-weight: 700;
  display: inline-flex;
  align-items: center;
  justify-content: center;
}

.head-nav-dropdown-arrow {
  position: absolute;
  right: -2px;
  bottom: -2px;
  width: 14px;
  height: 14px;
  border-radius: 50%;
  background: #ffffff;
  border: 1px solid #d8e5df;
  padding: 2px;
  object-fit: contain;
  box-shadow: 0 2px 6px rgba(16, 40, 32, 0.18);
  pointer-events: none;
  z-index: 2;
}

/* ===== DROPDOWN ===== */
.head-nav-profile-dropdown {
  position: absolute;
  top: calc(100% + 12px);
  right: -8px;
  min-width: 170px;
  background: #ffffff;
  border: 1px solid #d8e5df;
  border-radius: 12px;
  box-shadow: 0 12px 28px rgba(16, 40, 32, 0.18);
  display: none;
  overflow: hidden;
  padding: 6px;
  z-index: 3500;
}

.head-nav-profile-dropdown.show {
  display: block;
}

.head-nav-dropdown-item {
  padding: 10px 12px;
  display: flex;
  align-items: center;
  gap: 10px;
  text-decoration: none;
  color: #203841;
  border-radius: 9px;
  font-size: 15px;
  font-weight: 600;
}

.head-nav-dropdown-item svg {
  width: 17px;
  height: 17px;
  fill: #2b7a66;
  flex-shrink: 0;
}

.head-nav-dropdown-item:hover {
  background: #eef7f3;
  color: #1f5f4d;
}

/* ===== SECOND NAVBAR ===== */
.head-subnav {
  width: 100%;
  background: white;
  display: flex;
  align-items: center;
  gap: 25px;
  padding: 10px 0 10px 75px;
  box-shadow: 0 4px 10px rgba(0, 0, 0, 0.08);
  position: fixed;
  top: 69px; /* adjust if your main navbar height is different */
  left: 0;
  width: 100%;
  z-index: 999;
  height: var(--sub-nav);
}

.head-subnav a {
  color: black;
  text-decoration: none;
  font-weight: 550;
  font-size: 15px;
  position: relative;
  display: flex;
  align-items: center;
  gap: 6px;
}

/* dropdown icon */
.head-subnav-dropdown-icon {
  width: 23px;
  height: 20px;
  object-fit: contain;
  margin-top: 2px;

  /* optional: subtle look */
  opacity: 0.7;
  transition: 0.3s;
}

/* hover effect (nice UX) */
.head-subnav a:hover .head-subnav-dropdown-icon {
  transform: rotate(180deg);
  opacity: 1;
}

/* separator */
.head-subnav-separator {
  color: #999;
  font-weight: 300;
}

/* ===== BOOK A TOUR BUTTON ===== */
.head-subnav-btn {
  background: #2b7a66;
  color: white !important;
  padding: 6px 14px;
  border-radius: 20px;
  font-weight: 600;
  transition: 0.3s;
}

/* hover effect */
.head-subnav-btn:hover {
  background: #256b59;
}

/* button layout */
.head-subnav-btn {
  display: flex;
  align-items: center;
  gap: 8px; /* space between icon and text */

  background: #2b7a66;
  color: white !important;
  padding: 6px 14px;
  border-radius: 20px;
  font-weight: 600;
  transition: 0.3s;
}

/* icon style */
.head-subnav-icon {
  width: 16px;
  height: 16px;
  object-fit: contain;
}
/* remove underline effect for button */
.head-subnav-btn::after {
  display: none;
}

html {
  scroll-behavior: smooth;
}
</style>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

