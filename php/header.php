<?php
if (session_status() === PHP_SESSION_NONE) {
    require_once __DIR__ . '/session_security.php';
    AppSessionStart();
}

require_once 'db_connection.php';

$headerRequestFile = realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? ''));
$headerIsStandaloneRequest = $headerRequestFile !== false && $headerRequestFile === realpath(__FILE__);
$user = null;

if (isset($_SESSION['tourist_id'])) {
    $stmt = $pdo->prepare("SELECT tourist_id, full_name, email, phone, profile_picture, google_id FROM tourist WHERE tourist_id = ? LIMIT 1");
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

$refererUrl = (string)($_SERVER['HTTP_REFERER'] ?? '');
$requestUrl = (string)($_SERVER['REQUEST_URI'] ?? '');
$currentUrl = $headerIsStandaloneRequest && $refererUrl !== '' ? $refererUrl : $requestUrl;
$currentPath = rawurldecode((string)(parse_url($currentUrl, PHP_URL_PATH) ?? ''));
$currentPage = basename(rtrim($currentPath, '/'));
$headerScriptPath = rawurldecode(str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? '/php/header.php')));
$applicationRootPath = rtrim(str_replace('\\', '/', dirname(dirname($headerScriptPath))), '/');
if ($applicationRootPath === '.' || $applicationRootPath === '/') {
    $applicationRootPath = '';
}
$isHomepage = rtrim($currentPath, '/') === $applicationRootPath
    || in_array(strtolower($currentPage), ['index.php', 'homepage.php'], true);
$refererQuery = [];
parse_str((string)parse_url($refererUrl, PHP_URL_QUERY), $refererQuery);
$currentTabRaw = strtolower(trim((string)($refererQuery['tab'] ?? ($_GET['tab'] ?? ''))));
$tabAliases = [
    'hotel' => 'hotels',
    'hotel-resorts' => 'hotels',
    'hotel_resorts' => 'hotels',
    'tour-packages' => 'tours',
    'packages' => 'tours',
    'tour-guides' => 'guides',
    'our-boats' => 'boats',
    'guide-boat' => 'bundle',
    'guide_boat' => 'bundle'
];
$currentTab = $tabAliases[$currentTabRaw] ?? $currentTabRaw;
$isUnifiedSearchPage = in_array(
    $currentPage,
    ['hotel_resorts.php', 'hotelResorts.php', 'homepage_new.php', 'service_details.php'],
    true
);

$isHotelsTab = in_array($currentTab, ['hotels'], true);

$isHotelsActive = (
    in_array($currentPage, ['hotel_resorts.php', 'hotelResorts.php', 'service_details.php'], true)
    || ($isUnifiedSearchPage && $isHotelsTab)
);
$bookingSuccessNotif = isset($_GET['booking_success']) && $_GET['booking_success'] === '1';
$bookingRefNotif = isset($_GET['booking_ref'])
  ? preg_replace('/[^A-Z0-9-]/i', '', trim((string)$_GET['booking_ref']))
  : '';
$notifCount = $bookingSuccessNotif ? 1 : 0;
$notifKey = $bookingSuccessNotif ? ($bookingRefNotif !== '' ? ('booking-success-' . $bookingRefNotif) : 'booking-success') : '';
$bookingNotifMessage = $bookingSuccessNotif
  ? ('Booking request submitted successfully' . ($bookingRefNotif !== '' ? ' (' . $bookingRefNotif . ')' : '') . '. Please wait for confirmation on your inputted email.')
  : '';

function isDefaultProfileImage($value)
{
    $name = strtolower(basename((string) $value));
    return in_array($name, ['profileicon.png', 'profileicon2.png'], true);
}

function normalizeProfileImage($value)
{
    $candidate = html_entity_decode(trim((string) $value), ENT_QUOTES, 'UTF-8');
    $candidate = str_replace('\\/', '/', $candidate);
    if ($candidate === '') {
        return '';
    }
    if (strlen($candidate) >= 2) {
        $first = $candidate[0];
        $last = $candidate[strlen($candidate) - 1];
        if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
            $candidate = substr($candidate, 1, -1);
        }
    }
    if (strpos($candidate, '//') === 0) {
        $candidate = 'https:' . $candidate;
    }
    if (preg_match('#^https?://#i', $candidate)) {
        if (stripos($candidate, 'profiles.google.com') !== false && preg_match('#profiles\\.google\\.com/(?:s2/photos/profile/)?([^/?#]+)(?:/picture)?#i', $candidate, $m)) {
            return 'https://profiles.google.com/' . rawurlencode($m[1]) . '/picture?sz=96';
        }
        if (stripos($candidate, 'google.com/s2/photos/profile') !== false) {
            $candidate = preg_replace('/([?&])sz=\\d+/i', '$1sz=96', $candidate);
            if (!preg_match('/[?&]sz=/i', $candidate)) {
                $candidate .= (strpos($candidate, '?') !== false ? '&' : '?') . 'sz=96';
            }
            return $candidate;
        }
        if (stripos($candidate, 'googleusercontent.com') !== false) {
            $candidate = preg_replace('/([?&])sz=\\d+/i', '$1sz=96', $candidate);
            $candidate = preg_replace('/=s\\d+-c(?=$|[?&#])/i', '=s96-c', $candidate);
            $candidate = preg_replace('/=s\\d+(?=$|[?&#])/i', '=s96', $candidate);
        }
        return $candidate;
    }
    return ltrim($candidate, '/');
}

function buildGoogleProfileImageById($googleId)
{
    return '';
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

// The standalone AJAX endpoint only reads session data. Release its file lock
// before running navigation queries so it cannot hold up another page request.
if ($headerIsStandaloneRequest && session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

function headSubnavTableExists(PDO $pdo, string $tableName): bool
{
    static $tables = null;
    if ($tables === null) {
        $names = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
        $tables = array_fill_keys(array_map('strtolower', $names ?: []), true);
    }
    return isset($tables[strtolower($tableName)]);
}

function headSubnavResolveImage(?string $rawPath, string $fallback = 'img/sampleimage.png'): string
{
    $candidate = trim((string)$rawPath);
    if ($candidate === '') {
        return $fallback;
    }

    if (preg_match('#^https?://#i', $candidate)) {
        return $candidate;
    }

    $cleanCandidate = ltrim(str_replace('\\', '/', $candidate), '/');
    $candidateFile = __DIR__ . '/../' . $cleanCandidate;
    if (is_file($candidateFile)) {
        return $cleanCandidate;
    }

    $basename = basename($candidate);
    $relativeCandidates = [
        'php/upload/' . $basename,
        'upload/' . $basename,
        'uploads/' . $basename,
        'img/' . $basename,
        'imagess/' . $basename
    ];

    foreach ($relativeCandidates as $relativePath) {
        if (is_file(__DIR__ . '/../' . $relativePath)) {
            return $relativePath;
        }
    }

    return $fallback;
}

function headSubnavDestinationKey(string $value): string
{
    $value = html_entity_decode(trim($value), ENT_QUOTES, 'UTF-8');
    $value = str_replace(['Ã±', 'ñ', 'Ñ'], 'n', $value);
    $value = function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
    $value = preg_replace('/\bisland\b/u', '', $value) ?? $value;
    return preg_replace('/[^a-z0-9]+/', '', $value) ?? '';
}

function headSubnavAddBookingShares(array $rows): array
{
    $totalBookings = array_sum(array_map(
        static fn(array $row): int => (int)($row['booking_count'] ?? 0),
        $rows
    ));

    foreach ($rows as &$row) {
        $row['share_percent'] = $totalBookings > 0
            ? round(((int)($row['booking_count'] ?? 0) / $totalBookings) * 100, 1)
            : 0;
    }
    unset($row);

    return $rows;
}

function headSubnavFormatPercent(float $value): string
{
    return rtrim(rtrim(number_format($value, 1, '.', ''), '0'), '.');
}

$popularDestinations = [];
$popularPackages = [];
$popularHotels = [];

if (
    $isHomepage
    && headSubnavTableExists($pdo, 'destinations')
    && headSubnavTableExists($pdo, 'bookings')
) {
    try {
        $stmt = $pdo->query("
            SELECT
              destination_id,
              slug,
              title,
              card_image,
              sort_order
            FROM destinations
            WHERE status = 'published'
            ORDER BY sort_order ASC, destination_id ASC
        ");
        $destinationRows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $destinationBookingCounts = [];

        $locationStmt = $pdo->query("
            SELECT location
            FROM bookings
            WHERE TRIM(COALESCE(location, '')) <> ''
              AND LOWER(COALESCE(status, '')) NOT IN ('cancelled', 'declined', 'rejected')
              AND LOWER(COALESCE(is_complete, '')) NOT IN ('cancelled', 'declined', 'rejected')
        ");
        foreach ($locationStmt->fetchAll(PDO::FETCH_COLUMN) ?: [] as $locationList) {
            $bookingDestinations = [];
            foreach (explode(',', (string)$locationList) as $location) {
                $key = headSubnavDestinationKey($location);
                if ($key !== '') {
                    $bookingDestinations[$key] = true;
                }
            }
            foreach (array_keys($bookingDestinations) as $key) {
                $destinationBookingCounts[$key] = ($destinationBookingCounts[$key] ?? 0) + 1;
            }
        }

        foreach ($destinationRows as $destination) {
            $key = headSubnavDestinationKey((string)($destination['title'] ?? ''));
            $bookingCount = (int)($destinationBookingCounts[$key] ?? 0);
            if ($bookingCount > 0) {
                $popularDestinations[] = [
                    'label' => 'Things to do in',
                    'title' => (string)($destination['title'] ?? 'Mercedes'),
                    'url' => 'destination.php',
                    'image' => (string)($destination['card_image'] ?? ''),
                    'booking_count' => $bookingCount,
                    'sort_order' => (int)($destination['sort_order'] ?? 0),
                    'destination_id' => (int)($destination['destination_id'] ?? 0)
                ];
            }
        }

        usort($popularDestinations, static fn(array $left, array $right): int =>
            $right['booking_count'] <=> $left['booking_count']
            ?: $left['sort_order'] <=> $right['sort_order']
            ?: $left['destination_id'] <=> $right['destination_id']
        );
        $popularDestinations = headSubnavAddBookingShares($popularDestinations);
        $popularDestinations = array_slice($popularDestinations, 0, 4);
    } catch (Throwable $error) {
        $popularDestinations = [];
    }
}

if (
    $isHomepage
    && headSubnavTableExists($pdo, 'tour_packages')
    && headSubnavTableExists($pdo, 'bookings')
) {
    try {
        if (headSubnavTableExists($pdo, 'operators')) {
            $stmt = $pdo->query("
                SELECT
                  p.package_id,
                  p.package_title,
                  p.package_image,
                  COUNT(b.booking_id) AS booking_count
                FROM tour_packages p
                INNER JOIN operators o ON o.operator_id = p.operator_id AND o.status = 'active'
                INNER JOIN bookings b
                  ON b.operator_id = p.operator_id
                 AND LOWER(TRIM(b.package_name)) = LOWER(TRIM(p.package_title))
                 AND LOWER(COALESCE(b.booking_type, '')) = 'package'
                 AND LOWER(COALESCE(b.status, '')) NOT IN ('cancelled', 'declined', 'rejected')
                 AND LOWER(COALESCE(b.is_complete, '')) NOT IN ('cancelled', 'declined', 'rejected')
                GROUP BY p.package_id, p.package_title, p.package_image
                ORDER BY booking_count DESC, MAX(b.created_at) DESC, p.package_id DESC
            ");
        } else {
            $stmt = $pdo->query("
                SELECT
                  p.package_id,
                  p.package_title,
                  p.package_image,
                  COUNT(b.booking_id) AS booking_count
                FROM tour_packages p
                INNER JOIN bookings b
                  ON b.operator_id = p.operator_id
                 AND LOWER(TRIM(b.package_name)) = LOWER(TRIM(p.package_title))
                 AND LOWER(COALESCE(b.booking_type, '')) = 'package'
                 AND LOWER(COALESCE(b.status, '')) NOT IN ('cancelled', 'declined', 'rejected')
                 AND LOWER(COALESCE(b.is_complete, '')) NOT IN ('cancelled', 'declined', 'rejected')
                GROUP BY p.package_id, p.package_title, p.package_image
                ORDER BY booking_count DESC, MAX(b.created_at) DESC, p.package_id DESC
            ");
        }
        $popularPackages = headSubnavAddBookingShares($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
        $popularPackages = array_slice($popularPackages, 0, 4);
    } catch (Throwable $error) {
        $popularPackages = [];
    }
}

if (
    $isHomepage
    && headSubnavTableExists($pdo, 'hotel_resorts')
    && headSubnavTableExists($pdo, 'hotel_room_bookings')
) {
    try {
        $stmt = $pdo->query("
            SELECT
              h.hotel_resort_id,
              h.name,
              h.image_path,
              COUNT(b.hotel_booking_id) AS booking_count
            FROM hotel_resorts h
            INNER JOIN hotel_room_bookings b
              ON b.hotel_resort_id = h.hotel_resort_id
             AND LOWER(COALESCE(b.booking_status, '')) NOT IN ('cancelled', 'declined', 'rejected', 'no-show')
            WHERE h.status = 'active'
            GROUP BY h.hotel_resort_id, h.name, h.image_path
            ORDER BY booking_count DESC, MAX(b.created_at) DESC, h.hotel_resort_id DESC
        ");
        $popularHotels = headSubnavAddBookingShares($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
        $popularHotels = array_slice($popularHotels, 0, 4);
    } catch (Throwable $error) {
        $popularHotels = [];
    }
}

?>

<header class="head-nav-main-header">
  <div class="head-nav-container">

    <!-- Mobile navigation and brand -->
    <div class="head-nav-left">
      <button type="button" class="head-nav-mobile-toggle" id="headNavMobileToggle" aria-label="Open navigation menu" aria-expanded="false" aria-controls="headNavPrimary"><span class="hamburger" aria-hidden="true"><span class="bar"></span><span class="bar"></span><span class="bar"></span></span></button>
      <img src="img/newlogo.png" class="head-nav-logo" alt="Mercedes tourism logo">
      <img src="img/textlogo2.png" class="head-navtext-logo" alt="iTour Mercedes">
    </div>

    <!-- RIGHT GROUP -->
    <div class="head-nav-right">

      <!-- NAV LINKS -->
<div class="head-nav-menu-backdrop" id="headNavMenuBackdrop" aria-hidden="true"></div>
<nav class="head-nav-center" id="headNavPrimary" aria-label="Main navigation">
  <?php if ($user): ?>
    <a class="head-nav-drawer-account" href="php/profile.php" aria-label="Open profile for <?= htmlspecialchars($profileName, ENT_QUOTES, 'UTF-8') ?>">
      <span class="head-nav-drawer-avatar">
        <?php if ($profileImage !== ''): ?>
          <img src="<?= htmlspecialchars($profileImage, ENT_QUOTES, 'UTF-8') ?>" alt="" referrerpolicy="no-referrer" loading="lazy" decoding="async" onerror="this.hidden=true;this.nextElementSibling.hidden=false;">
        <?php endif; ?>
        <span<?= $profileImage !== '' ? ' hidden' : '' ?>><?= htmlspecialchars($profileInitial, ENT_QUOTES, 'UTF-8') ?></span>
      </span>
      <span class="head-nav-drawer-account-copy">
        <strong><?= htmlspecialchars($profileName !== '' ? $profileName : 'My Profile', ENT_QUOTES, 'UTF-8') ?></strong>
        <small><?= htmlspecialchars((string)($user['email'] ?? ''), ENT_QUOTES, 'UTF-8') ?></small>
      </span>
      <svg class="head-nav-drawer-account-chevron" viewBox="0 0 24 24" aria-hidden="true"><path d="m9 5 7 7-7 7"/></svg>
    </a>
  <?php else: ?>
    <div class="head-nav-drawer-guest">
      <p>Sign in to save favorites and manage your trips.</p>
      <button type="button" data-head-nav-auth-open>Sign in/Create account</button>
    </div>
  <?php endif; ?>
  <div class="head-nav-drawer-section-title">Navigation Links</div>
  <a href="./" class="<?= $isHomepage ? 'active' : '' ?>"><svg class="head-nav-page-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="m3 10 9-7 9 7M5 9v12h14V9M9 21v-8h6v8"/></svg><span>HOME</span><svg class="head-nav-page-arrow" viewBox="0 0 24 24" aria-hidden="true"><path d="m9 5 7 7-7 7"/></svg></a>
  <a href="destination.php" class="<?= in_array($currentPage, ['destination.php', 'destination_results.php'], true) ? 'active' : '' ?>"><svg class="head-nav-page-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M19 10c0 5-7 11-7 11S5 15 5 10a7 7 0 1 1 14 0Z"/><circle cx="12" cy="10" r="2"/></svg><span>DESTINATIONS</span><svg class="head-nav-page-arrow" viewBox="0 0 24 24" aria-hidden="true"><path d="m9 5 7 7-7 7"/></svg></a>
  <a href="hotel_resorts.php?tab=tours&amp;reset_search=1" class="<?= $isHotelsActive ? 'active' : '' ?>"><svg class="head-nav-page-icon" viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="7" width="18" height="14" rx="3"/><path d="M8 7V3h8v4M8 7v14M16 7v14"/></svg><span>TOURS</span><svg class="head-nav-page-arrow" viewBox="0 0 24 24" aria-hidden="true"><path d="m9 5 7 7-7 7"/></svg></a>
  <a href="about.php" class="<?= ($currentPage == 'about.php') ? 'active' : '' ?>"><svg class="head-nav-page-icon" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 11v6M12 7v1"/></svg><span>ABOUT</span><svg class="head-nav-page-arrow" viewBox="0 0 24 24" aria-hidden="true"><path d="m9 5 7 7-7 7"/></svg></a>
  <div class="head-nav-drawer-section-title head-nav-drawer-section-title--secondary">Trip Essentials</div>
  <a href="hotel_resorts.php?tab=tours" class="head-nav-drawer-utility"><svg class="head-nav-page-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7.5A2.5 2.5 0 0 0 6.5 10 2.5 2.5 0 0 0 4 12.5V17a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-4.5a2.5 2.5 0 0 0 0-5V7a2 2 0 0 0-2-2H6a2 2 0 0 0-2 2v.5Z"/><path d="M14 9h3M14 13h3"/></svg><span>BOOK A TOUR</span><svg class="head-nav-page-arrow" viewBox="0 0 24 24" aria-hidden="true"><path d="m9 5 7 7-7 7"/></svg></a>
  <a href="about.php#visitHeading" class="head-nav-drawer-utility"><svg class="head-nav-page-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M4 5h16v12H7l-3 3V5Z"/><path d="M8 9h8M8 13h5"/></svg><span>TOURISM OFFICE</span><svg class="head-nav-page-arrow" viewBox="0 0 24 24" aria-hidden="true"><path d="m9 5 7 7-7 7"/></svg></a>
  <div class="head-nav-drawer-section-title head-nav-drawer-section-title--secondary">Information</div>
  <a href="#legalPolicyModal" class="head-nav-drawer-utility" data-legal-policy="terms"><svg class="head-nav-page-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M6 3h9l4 4v14H6V3Z"/><path d="M14 3v5h5M9 12h7M9 16h7"/></svg><span>TERMS &amp; CONDITIONS</span><svg class="head-nav-page-arrow" viewBox="0 0 24 24" aria-hidden="true"><path d="m9 5 7 7-7 7"/></svg></a>
  <a href="#legalPolicyModal" class="head-nav-drawer-utility" data-legal-policy="privacy"><svg class="head-nav-page-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3 5 6v5c0 4.8 2.8 8.4 7 10 4.2-1.6 7-5.2 7-10V6l-7-3Z"/><path d="m9 12 2 2 4-5"/></svg><span>PRIVACY POLICY</span><svg class="head-nav-page-arrow" viewBox="0 0 24 24" aria-hidden="true"><path d="m9 5 7 7-7 7"/></svg></a>
  <?php if ($user): ?>
    <button type="button" class="head-nav-drawer-logout" data-head-nav-logout>
      <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M14 5V3H5v18h9v-2M10 12h11M18 8l4 4-4 4"/></svg>
      <span>Logout</span>
    </button>
  <?php endif; ?>
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
          <div class="head-nav-notif-panel-body">
            <div class="head-nav-notif-section-title" id="headNavNotifNewTitle" style="display:none;">New Notifications</div>
            <div class="head-nav-notif-list" id="headNavNotifList"></div>
            <div class="head-nav-notif-section-title" id="headNavNotifOldTitle" style="display:none;">Old Notifications</div>
            <div class="head-nav-notif-list" id="headNavNotifOldList"></div>
            <div class="head-nav-notif-empty" id="headNavNotifEmpty">No new notifications.</div>
          </div>
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
                  referrerpolicy="no-referrer"
                  loading="lazy"
                  decoding="async"
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
        <button type="button" class="head-nav-btn-login" id="openModalBtn" aria-label="Log in"><span class="head-nav-login-label">Login</span><svg class="head-nav-login-icon" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="8" r="3.5"/><path d="M5 21v-2a7 7 0 0 1 14 0v2"/></svg></button>
      <?php endif; ?>

    </div>

  </div>
</header>

<?php if ($isHomepage): ?>

<!-- SECOND NAVBAR -->
<div class="head-subnav">
  <nav class="head-subnav-mobile-links" aria-label="Sticky mobile navigation">
    <a href="./" aria-current="page"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m3 10 9-7 9 7M5 9v12h14V9M9 21v-8h6v8"/></svg><span>Home</span></a>
    <a href="destination.php"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M19 10c0 5-7 11-7 11S5 15 5 10a7 7 0 1 1 14 0Z"/><circle cx="12" cy="10" r="2"/></svg><span>Destinations</span></a>
    <a href="hotel_resorts.php?tab=tours&amp;reset_search=1"><svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="7" width="18" height="14" rx="3"/><path d="M8 7V3h8v4M8 7v14M16 7v14"/></svg><span>Tours</span></a>
    <a href="about.php"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 11v6M12 7v1"/></svg><span>About</span></a>
  </nav>
  <div class="head-subnav-item head-subnav-item--destinations">
    <a href="destination.php" class="head-subnav-link" aria-haspopup="true">
      Popular Destinations
      <svg class="head-subnav-dropdown-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="m7 9 5 5 5-5"/></svg>
    </a>

    <div class="head-subnav-popup" aria-label="Popular destinations">
      <h4 class="head-subnav-popup-title">Popular Destinations</h4>
      <?php if (!empty($popularDestinations)): ?>
        <?php foreach ($popularDestinations as $destination): ?>
          <a href="<?= htmlspecialchars($destination['url']) ?>" class="head-subnav-popup-item">
            <img src="<?= htmlspecialchars(headSubnavResolveImage($destination['image'], 'img/sampleimage.png')) ?>" alt="<?= htmlspecialchars($destination['title']) ?>">
            <div>
              <span><?= htmlspecialchars($destination['label']) ?></span>
              <strong><?= htmlspecialchars($destination['title']) ?></strong>
            </div>
            <span class="head-subnav-trend" title="<?= number_format((int)$destination['booking_count']) ?> recorded booking<?= (int)$destination['booking_count'] === 1 ? '' : 's' ?>" aria-label="<?= number_format((int)$destination['booking_count']) ?> booking<?= (int)$destination['booking_count'] === 1 ? '' : 's' ?>, <?= headSubnavFormatPercent((float)$destination['share_percent']) ?> percent of destination bookings"><svg viewBox="0 0 42 24" aria-hidden="true"><polyline points="3,20 18,8 27,15 40,4"></polyline><path d="m34 4 6 0 0 6"></path></svg><b><?= headSubnavFormatPercent((float)$destination['share_percent']) ?>%</b></span>
          </a>
        <?php endforeach; ?>
      <?php else: ?>
        <p class="head-subnav-popup-empty">No destination booking data yet.</p>
      <?php endif; ?>
    </div>
  </div>

  <div class="head-subnav-item head-subnav-item--packages">
    <a href="hotel_resorts.php?tab=tours" class="head-subnav-link" aria-haspopup="true">
      Popular Packages
      <svg class="head-subnav-dropdown-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="m7 9 5 5 5-5"/></svg>
    </a>

    <div class="head-subnav-popup" aria-label="Popular packages">
      <h4 class="head-subnav-popup-title">Popular Packages</h4>
      <?php if (!empty($popularPackages)): ?>
        <?php foreach ($popularPackages as $package): ?>
          <a href="package_details.php?package_id=<?= (int)$package['package_id'] ?>" class="head-subnav-popup-item">
            <img src="<?= htmlspecialchars(headSubnavResolveImage($package['package_image'] ?? '', 'img/packageshome.png')) ?>" alt="<?= htmlspecialchars((string)($package['package_title'] ?? 'Package')) ?>">
            <div>
              <span>Top package</span>
              <strong><?= htmlspecialchars((string)($package['package_title'] ?? 'Package')) ?></strong>
            </div>
            <span class="head-subnav-trend" title="<?= number_format((int)$package['booking_count']) ?> recorded booking<?= (int)$package['booking_count'] === 1 ? '' : 's' ?>" aria-label="<?= number_format((int)$package['booking_count']) ?> booking<?= (int)$package['booking_count'] === 1 ? '' : 's' ?>, <?= headSubnavFormatPercent((float)$package['share_percent']) ?> percent of package bookings"><svg viewBox="0 0 42 24" aria-hidden="true"><polyline points="3,20 18,8 27,15 40,4"></polyline><path d="m34 4 6 0 0 6"></path></svg><b><?= headSubnavFormatPercent((float)$package['share_percent']) ?>%</b></span>
          </a>
        <?php endforeach; ?>
      <?php else: ?>
        <p class="head-subnav-popup-empty">No package booking data yet.</p>
      <?php endif; ?>
    </div>
  </div>

  <div class="head-subnav-item head-subnav-item--hotels">
    <a href="hotel_resorts.php" class="head-subnav-link" aria-haspopup="true">
      Hotel & Resorts
      <svg class="head-subnav-dropdown-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="m7 9 5 5 5-5"/></svg>
    </a>

    <div class="head-subnav-popup" aria-label="Popular hotels and resorts">
      <h4 class="head-subnav-popup-title">Hotel & Resorts</h4>
      <?php if (!empty($popularHotels)): ?>
        <?php foreach ($popularHotels as $hotel): ?>
          <a href="hotel_details.php?id=<?= (int)$hotel['hotel_resort_id'] ?>&amp;source=popular" class="head-subnav-popup-item">
            <img src="<?= htmlspecialchars(headSubnavResolveImage($hotel['image_path'] ?? '', 'img/hotelshome.png')) ?>" alt="<?= htmlspecialchars((string)($hotel['name'] ?? 'Hotel')) ?>">
            <div>
              <span>Top stays</span>
              <strong><?= htmlspecialchars((string)($hotel['name'] ?? 'Hotel & Resort')) ?></strong>
            </div>
            <span class="head-subnav-trend" title="<?= number_format((int)$hotel['booking_count']) ?> recorded booking<?= (int)$hotel['booking_count'] === 1 ? '' : 's' ?>" aria-label="<?= number_format((int)$hotel['booking_count']) ?> booking<?= (int)$hotel['booking_count'] === 1 ? '' : 's' ?>, <?= headSubnavFormatPercent((float)$hotel['share_percent']) ?> percent of hotel and resort bookings"><svg viewBox="0 0 42 24" aria-hidden="true"><polyline points="3,20 18,8 27,15 40,4"></polyline><path d="m34 4 6 0 0 6"></path></svg><b><?= headSubnavFormatPercent((float)$hotel['share_percent']) ?>%</b></span>
          </a>
        <?php endforeach; ?>
      <?php else: ?>
        <p class="head-subnav-popup-empty">No hotel or resort booking data yet.</p>
      <?php endif; ?>
    </div>
  </div>

  <span class="head-subnav-separator">|</span>

  <a href="hotel_resorts.php?tab=tours" class="head-subnav-btn">
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

<?php if (!$isHomepage): ?>
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
  border-bottom: 3px solid #2b7a66;
  font-family: Arial, Helvetica, sans-serif;
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

.head-nav-center a::after {
  content: "";
  position: absolute;
  left: 0;
  bottom: -5px;
  width: 0%;
  height: 3px;
  background: #2b7a66;
  transition: width .3s ease;
}

.head-nav-center a:hover::after,
.head-nav-center a.active::after {
  width: 100%;
}

@media (min-width: 981px) {
  .head-nav-center > .head-nav-drawer-account,
  .head-nav-center > .head-nav-drawer-guest,
  .head-nav-center > .head-nav-drawer-section-title,
  .head-nav-center > a.head-nav-drawer-utility {
    display: none !important;
  }
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
  height: 520px;
  flex-direction: column;
  overflow: hidden;
}

.head-nav-notif-panel.show {
  display: flex;
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
  overflow: visible;
}

.head-nav-notif-panel-body {
  flex: 1;
  min-height: 0;
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
  font-family: "Inter", system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif !important;
  font-style: normal !important;
  font-weight: 800 !important;
  line-height: 1 !important;
  letter-spacing: 0 !important;
  cursor: pointer;
}
.head-nav-btn-login .head-nav-login-label {
  font-family: inherit !important;
  font-size: inherit !important;
  font-style: normal !important;
  font-weight: 800 !important;
  line-height: inherit !important;
  letter-spacing: 0 !important;
}
@media (min-width: 481px) {
  .head-nav-main-header .head-nav-btn-login {
    box-sizing: border-box !important;
    width: 76px !important;
    min-width: 76px !important;
    height: 32px !important;
    min-height: 32px !important;
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    padding: 0 16px !important;
    border-radius: 999px !important;
    font-family: "Inter", system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif !important;
    font-size: 14px !important;
    font-style: normal !important;
    font-weight: 800 !important;
    line-height: 1 !important;
    letter-spacing: 0 !important;
  }
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

.head-nav-dropdown-item#logoutBtn {
  color: #bd313b;
}

.head-nav-dropdown-item#logoutBtn svg {
  fill: #bd313b;
}

.head-nav-dropdown-item#logoutBtn:hover,
.head-nav-dropdown-item#logoutBtn:focus-visible {
  color: #a6252f;
  background: #fff0f1;
  outline: 0;
}

.head-nav-dropdown-item#logoutBtn:hover svg,
.head-nav-dropdown-item#logoutBtn:focus-visible svg {
  fill: #a6252f;
}

/* ===== SECOND NAVBAR ===== */
.head-subnav {
  width: 100%;
  background: white;
  display: flex;
  align-items: center;
  gap: 22px;
  padding: 10px 0 10px 75px;
  box-shadow: 0 4px 10px rgba(0, 0, 0, 0.08);
  position: fixed;
  top: 69px; /* adjust if your main navbar height is different */
  left: 0;
  width: 100%;
  z-index: 999;
  height: var(--sub-nav);
  overflow: visible;
}

.head-subnav-mobile-links { display: none; }

.head-subnav-item {
  position: relative;
  height: 100%;
  display: inline-flex;
  align-items: center;
}

.head-subnav-link {
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
  flex: 0 0 auto;
  margin-top: 2px;
  fill: none;
  stroke: #3a9a7c;
  stroke-width: 2;
  stroke-linecap: round;
  stroke-linejoin: round;

  /* optional: subtle look */
  opacity: 0.7;
  transition: 0.3s;
}

.head-subnav-item:hover .head-subnav-dropdown-icon,
.head-subnav-item:focus-within .head-subnav-dropdown-icon {
  transform: rotate(180deg);
  opacity: 1;
}

.head-subnav-popup {
  position: absolute;
  left: 0;
  top: calc(100% + 1px);
  min-width: 360px;
  max-width: 420px;
  display: none;
  background: #ffffff;
  border: 1px solid #e4ece8;
  border-radius: 8px;
  box-shadow: 0 10px 24px rgba(15, 50, 40, 0.12);
  padding: 12px 14px 14px;
  z-index: 1200;
}

.head-subnav-item:hover .head-subnav-popup,
.head-subnav-item:focus-within .head-subnav-popup {
  display: block;
}

.head-subnav-item--packages .head-subnav-popup {
  left: -28px;
}

.head-subnav-item--hotels .head-subnav-popup {
  left: -52px;
}

.head-subnav-popup-title {
  margin: 0 0 8px;
  font-size: 15 px;
  color: #10313b;
  letter-spacing: 0.01em;
}

.head-subnav-popup-item {
  text-decoration: none;
  color: inherit;
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 7px 4px;
  border-radius: 8px;
  transition: background 0.2s ease;
}

.head-subnav-popup-item:hover {
  background: #f5f8f7;
}

.head-subnav-popup-item img {
  width: 46px;
  height: 46px;
  border-radius: 50%;
  object-fit: cover;
  flex-shrink: 0;
}

.head-subnav-popup-item span {
  display: block;
  font-size: 12px;
  font-weight: 500;
  color: #6a7f85;
}

.head-subnav-popup-item strong {
  display: block;
  margin-top: 1px;
  font-size: 15px;
  color: #20363c;
  font-weight: 600;
  line-height: 1.2;
}

.head-subnav-popup-empty {
  margin: 4px 0 0;
  padding: 13px 8px;
  color: #6a7f85;
  font-size: 12px;
  line-height: 1.45;
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
  text-decoration: none !important;
  padding: 6px 14px;
  border-radius: 20px;
  font-weight: 600;
  transition: 0.3s;
}

/* hover effect */
.head-subnav-btn:hover {
  background: #256b59;
  text-decoration: none !important;
}

.head-subnav-btn:focus,
.head-subnav-btn:active,
.head-subnav-btn:visited {
  text-decoration: none !important;
}

/* button layout */
.head-subnav-btn {
  display: flex;
  align-items: center;
  gap: 8px; /* space between icon and text */

  background: #2b7a66;
  color: white !important;
  text-decoration: none !important;
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
  display: none !important;
  content: none !important;
}

@media (max-width: 1180px) {
  .head-subnav {
    gap: 12px;
    padding-left: 22px;
  }

  .head-subnav-item {
    height: auto;
  }

  .head-subnav-link {
    font-size: 13px;
  }

  .head-subnav-popup {
    min-width: 320px;
    max-width: min(360px, calc(100vw - 22px));
  }

  .head-subnav-item--packages .head-subnav-popup {
    left: -12px;
  }

  .head-subnav-item--hotels .head-subnav-popup {
    left: auto;
    right: 0;
  }

  .head-subnav-popup-title,
  .head-subnav-popup-item span,
  .head-subnav-popup-item strong {
    font-size: 13px;
  }
}

@media (max-width: 860px) {
  .head-subnav-popup {
    display: none !important;
  }
}

html {
  scroll-behavior: smooth;
}

.head-nav-mobile-toggle, .head-nav-login-icon, .head-nav-menu-backdrop, .head-nav-drawer-account, .head-nav-drawer-guest, .head-nav-drawer-section-title, .head-nav-drawer-utility, .head-nav-drawer-logout, .head-nav-page-icon, .head-nav-page-arrow { display: none; }
@media (max-width: 980px) {
  .head-nav-main-header .head-nav-container { height: 100%; padding: 0 10px !important; gap: 8px; }
  .head-nav-main-header .head-nav-left { min-width: 0; flex: 1; margin: 0 !important; gap: 7px !important; }
  .head-nav-main-header .head-nav-logo { flex: 0 0 36px; width: 36px !important; height: 36px !important; margin: 0; }
  .head-nav-main-header .head-navtext-logo { display: block !important; min-width: 0; width: clamp(90px, 28vw, 155px); height: 30px !important; margin: 0; object-fit: contain; }
  .head-nav-main-header .head-nav-right { flex: 0 0 auto; gap: 4px !important; margin-left: auto; }
  .head-nav-main-header .head-nav-mobile-toggle { display: flex; flex: 0 0 40px; width: 40px; height: 44px; flex-direction: column; align-items: center; justify-content: center; gap: 5px; padding: 0; border: 0; border-radius: 9px; background: transparent; color: #155a49; cursor: pointer; }
  .head-nav-mobile-toggle .hamburger { display: flex; flex-direction: column; justify-content: space-between; width: 24px; height: 18px; }
  .head-nav-mobile-toggle .bar { display: block; position: relative; width: 100%; height: 3px; border-radius: 10px; background: currentColor; transition: transform .3s ease, opacity .3s ease, translate .3s ease; }
  .head-nav-mobile-toggle[aria-expanded="false"] .bar { transition-delay: 0s, .3s, .3s; }
  .head-nav-mobile-toggle[aria-expanded="true"] .bar:nth-child(2) { transform: translateY(8px); opacity: 0; transition-delay: 0s; }
  .head-nav-mobile-toggle[aria-expanded="true"] .bar:nth-child(1) { translate: 0 7.5px; transform: rotate(-45deg) scale(.85); transition-delay: .3s, 0s, 0s; }
  .head-nav-mobile-toggle[aria-expanded="true"] .bar:nth-child(3) { translate: 0 -7.5px; transform: rotate(45deg) scale(.85); transition-delay: .3s, 0s, 0s; }
  body.mobile-nav-open { overflow: hidden; }
  .head-nav-main-header .head-nav-mobile-toggle { height: 40px; border: 0; background: transparent; box-shadow: none; }
  .head-nav-menu-backdrop { display: block; position: fixed; inset: var(--main-nav) 0 0; background: #092b2366; opacity: 0; visibility: hidden; transition: opacity .28s ease, visibility .28s; z-index: 1; }
  .mobile-nav-open .head-nav-menu-backdrop { opacity: 1; visibility: visible; }
  .head-nav-main-header .head-nav-center { display: flex !important; flex-direction: column; position: fixed !important; top: var(--main-nav); bottom: auto; left: 0; right: auto; width: min(310px, 86vw); height: calc(100vh - var(--main-nav)); height: calc(100dvh - var(--main-nav)); max-height: calc(100vh - var(--main-nav)); max-height: calc(100dvh - var(--main-nav)); overflow-x: hidden; overflow-y: auto; overscroll-behavior: contain; -webkit-overflow-scrolling: touch; touch-action: pan-y; scroll-padding-bottom: calc(58px + env(safe-area-inset-bottom)); padding: 15px 14px max(10px, env(safe-area-inset-bottom)); gap: 3px !important; background: linear-gradient(180deg, #fff 0%, #f9fcfa 100%); border: 0; border-right: 1px solid #dbe9e3; border-radius: 0 18px 18px 0; box-shadow: 14px 0 36px rgba(10, 54, 42, .16); transform: translateX(-105%); visibility: hidden; transition: transform .28s cubic-bezier(.2,.7,.2,1), visibility .28s; z-index: 2; }
  .head-nav-main-header .head-nav-center.is-open { display: flex !important; transform: translateX(0); visibility: visible; }
  .head-nav-drawer-guest { display: block; margin: 0 0 10px; padding: 2px 1px 13px; border-bottom: 1px solid #e0ebe6; }
  .head-nav-drawer-guest p { margin: 0 0 7px; color: #657970; font-size: 9.5px; line-height: 1.4; }
  .head-nav-drawer-guest button { width: 100%; min-height: 38px; padding: 8px 14px; border: 0; border-radius: 999px; color: #fff; background: linear-gradient(135deg, #25846a, #14614e); box-shadow: 0 6px 14px rgba(20, 97, 78, .18); font: inherit; font-size: 10.5px; font-weight: 800; cursor: pointer; transition: transform .2s ease, box-shadow .2s ease, background .2s ease; }
  .head-nav-drawer-guest button:hover { background: linear-gradient(135deg, #2b9174, #125542); box-shadow: 0 9px 20px rgba(20, 97, 78, .26); transform: translateY(-1px); }
  .head-nav-main-header .head-nav-center a.head-nav-drawer-account { min-height: 68px; display: grid; grid-template-columns: 43px minmax(0, 1fr) 18px; align-items: center; gap: 10px; margin: 0 0 10px !important; padding: 9px; border: 1px solid #d5e8df; border-radius: 13px; color: #173f34; background: linear-gradient(135deg, #f0faf6, #fff); box-shadow: 0 7px 17px rgba(18, 83, 64, .07); text-decoration: none; }
  .head-nav-main-header .head-nav-center a.head-nav-drawer-account:hover { border-color: #bcdccd; background: #f4fbf8; transform: translateY(-1px); }
  .head-nav-drawer-avatar { width: 43px; height: 43px; display: grid; place-items: center; overflow: hidden; border: 2px solid #fff; border-radius: 50%; color: #fff; background: #237a62; box-shadow: 0 0 0 1px #8fc8b5; font-size: 14px; font-weight: 800; }
  .head-nav-drawer-avatar img, .head-nav-drawer-avatar > span { grid-area: 1 / 1; width: 100%; height: 100%; }
  .head-nav-drawer-avatar img { display: block; object-fit: cover; }
  .head-nav-drawer-avatar > span { display: grid; place-items: center; }
  .head-nav-drawer-avatar > span[hidden] { display: none; }
  .head-nav-drawer-account-copy { min-width: 0; display: block; }
  .head-nav-drawer-account-copy strong, .head-nav-drawer-account-copy small { display: block; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
  .head-nav-drawer-account-copy strong { margin-bottom: 3px; color: #174b3c; font-size: 12.5px; line-height: 1.25; }
  .head-nav-drawer-account-copy small { color: #6c8078; font-size: 10px; font-weight: 500; line-height: 1.3; }
  .head-nav-drawer-account-chevron { width: 17px; height: 17px; fill: none; stroke: #4f8e79; stroke-width: 1.8; stroke-linecap: round; stroke-linejoin: round; }
  .head-nav-drawer-section-title { display: flex; align-items: center; gap: 8px; margin: 3px 5px 4px; color: #678078; font-size: 8px; font-weight: 850; letter-spacing: .13em; line-height: 1.2; text-transform: uppercase; }
  .head-nav-drawer-section-title::after { content: ""; height: 1px; flex: 1; background: #e1ebe7; }
  .head-nav-drawer-section-title--secondary { margin-top: 10px; }
  .head-nav-main-header .head-nav-center a { display: flex; align-items: center; gap: 11px; min-height: 46px; flex: 0 0 auto; margin: 0 !important; padding: 10px 10px; font-size: 10.5px !important; font-weight: 750; border: 1px solid transparent; border-radius: 10px; text-decoration: none; color: #284c40; transition: color .2s ease, background .2s ease, border-color .2s ease, transform .2s ease; }
  .head-nav-main-header .head-nav-center a:hover { color: #155a49; background: #f0f7f4; transform: translateX(2px); }
  .head-nav-main-header .head-nav-center a.head-nav-drawer-utility { display: flex; color: #49645b; }
  .head-nav-main-header .head-nav-drawer-utility .head-nav-page-icon { color: #337a65; }
  .head-nav-drawer-logout { position: sticky; bottom: env(safe-area-inset-bottom); align-self: stretch; width: calc(100% + 28px); min-height: 48px; flex: 0 0 auto; display: inline-flex; align-items: center; justify-content: flex-end; gap: 7px; margin: auto -14px 0; padding: 12px 18px; border: 0; border-top: 1px solid #edd8da; border-radius: 0 0 18px 0; color: #bd3542; background: rgba(255, 249, 249, .97); box-shadow: 0 -8px 18px rgba(70, 35, 38, .04); font-family: Arial, Helvetica, sans-serif !important; font-size: 10.5px !important; font-style: normal !important; font-weight: 750 !important; line-height: 1.2 !important; letter-spacing: 0 !important; text-transform: none !important; cursor: pointer; backdrop-filter: blur(8px); transition: color .2s ease, background .2s ease; }
  .head-nav-drawer-logout:hover { color: #a6202e; background: #fff0f1; }
  .head-nav-drawer-logout svg { width: 15px; height: 15px; fill: none; stroke: currentColor; stroke-width: 1.8; stroke-linecap: round; stroke-linejoin: round; }
  .head-nav-main-header .head-nav-page-icon { display: block; flex: 0 0 18px; width: 18px; height: 18px; fill: none; stroke: currentColor; stroke-width: 1.7; stroke-linejoin: round; stroke-linecap: round; }
  .head-nav-main-header .head-nav-page-arrow { display: block; flex: 0 0 17px; width: 17px; height: 17px; margin-left: auto; fill: none; stroke: #4f8e79; stroke-width: 1.8; stroke-linecap: round; stroke-linejoin: round; transition: transform .2s ease, stroke .2s ease; }
  .head-nav-main-header .head-nav-center a:hover .head-nav-page-arrow { stroke: #246f59; transform: translateX(2px); }
  .head-nav-main-header .head-nav-center a.active { border-color: #d3e8dc; }
  .head-nav-main-header .head-nav-center a.active { background: #eef7f2; color: #155a49; }
  .head-nav-main-header .head-nav-center a::after { display: none; }
  .head-nav-main-header .head-nav-btn-login { width: 40px; min-width: 40px; height: 40px; display: inline-flex; align-items: center; justify-content: center; padding: 0; border-radius: 50%; border: 1px solid #d6e7df; background: #eef7f2; color: #155a49; }
  .head-nav-main-header .head-nav-login-label { display: none; }
  .head-nav-main-header .head-nav-login-icon { display: block; flex: none; width: 22px; height: 22px; margin: 0; transform: translateY(-1px); fill: none; stroke: currentColor; stroke-width: 1.8; stroke-linecap: round; }
  .head-nav-main-header .head-nav-notif-btn { width: 40px; height: 44px; padding: 8px; }
  .head-nav-main-header .head-nav-notif-btn img { width: 22px !important; height: 22px !important; object-fit: contain; }
  .head-nav-main-header .head-nav-profile-icon-wrapper, .head-nav-main-header .head-nav-profile-icon, .head-nav-main-header .head-nav-profile-fallback { width: 38px !important; height: 38px !important; }
  .head-nav-main-header .head-nav-notif-panel { position: fixed; top: calc(var(--main-nav) + 8px); right: 10px; width: min(420px, calc(100vw - 20px)); height: min(520px, calc(100dvh - var(--main-nav) - 24px)); }
  .head-nav-main-header .head-nav-profile-dropdown { right: 0; }
  .head-nav-main-header button:focus-visible { outline: 2px solid #288b70; outline-offset: 2px; }
  body .head-subnav { top: var(--main-nav); height: var(--sub-nav); padding: 6px 10px !important; gap: 4px !important; justify-content: flex-start; overflow: hidden !important; white-space: nowrap; }
  body .head-subnav-item, body .head-subnav-btn { flex: 0 0 auto; }
  body .head-subnav-item--packages, body .head-subnav-item--hotels { display: none !important; }
  body .head-subnav-link { min-height: 40px; padding: 0 9px; font-size: 12px !important; border-radius: 9px; }
  body .head-subnav-btn { min-height: 34px; gap: 5px; padding: 5px 10px; font-size: 12px; line-height: 1; }
  body .head-subnav-btn .head-subnav-icon { width: 14px; height: 14px; }
  body .head-subnav-link:active { background: #eef7f2; }
  body .head-subnav-dropdown-icon { width: 14px; height: 14px; margin: 0; }
  body .head-subnav-popup, body .head-subnav-separator { display: none !important; }
}

@media (max-width: 760px) and (max-height: 680px) {
  .head-nav-main-header .head-nav-center { padding-top: 9px; gap: 1px !important; }
  .head-nav-main-header .head-nav-center a.head-nav-drawer-account { min-height: 56px; grid-template-columns: 36px minmax(0, 1fr) 17px; gap: 8px; margin-bottom: 5px !important; padding: 7px 8px; }
  .head-nav-drawer-avatar { width: 36px; height: 36px; }
  .head-nav-drawer-section-title { margin-top: 2px; margin-bottom: 2px; }
  .head-nav-drawer-section-title--secondary { margin-top: 5px; }
  .head-nav-main-header .head-nav-center a { min-height: 39px; padding-block: 7px; }
  .head-nav-drawer-logout { min-height: 44px; padding-block: 10px; }
}

/* Tablet/iPad header: retain the compact navigation shell without replacing
   desktop actions and discovery menus with their phone-only versions. */
@media (min-width: 761px) and (max-width: 980px) {
  .head-nav-main-header .head-nav-center {
    width: min(340px, 86vw);
  }

  .head-nav-drawer-guest p {
    font-size: 12px;
  }

  .head-nav-drawer-guest button {
    font-size: 13.5px;
  }

  .head-nav-drawer-section-title {
    font-size: 10.5px;
  }

  .head-nav-main-header .head-nav-center a {
    min-height: 50px;
    font-size: 14px !important;
  }

  .head-nav-main-header .head-nav-page-icon {
    width: 20px;
    height: 20px;
    flex-basis: 20px;
  }

  .head-nav-main-header .head-nav-page-arrow {
    width: 18px;
    height: 18px;
    flex-basis: 18px;
  }

  .head-nav-drawer-account-copy strong {
    font-size: 14px;
  }

  .head-nav-drawer-account-copy small {
    font-size: 11px;
  }

  .head-nav-drawer-logout {
    font-size: 13.5px !important;
  }

  .head-nav-main-header .head-nav-btn-login {
    width: auto;
    min-width: 88px;
    height: 38px;
    gap: 7px;
    padding: 0 17px;
    border: 0;
    border-radius: 999px;
    color: #fff;
    background: linear-gradient(135deg, #25846a, #14614e);
    box-shadow: 0 6px 15px rgba(20, 97, 78, .2);
    font-size: 13px;
    font-weight: 800;
  }

  .head-nav-main-header .head-nav-login-label {
    display: inline;
  }

  .head-nav-main-header .head-nav-login-icon {
    display: none;
  }

  body .head-subnav {
    padding-inline: 16px !important;
    gap: 9px !important;
    justify-content: flex-start;
    overflow: visible !important;
  }

  body .head-subnav-item--packages,
  body .head-subnav-item--hotels {
    display: block !important;
  }

  body .head-subnav-link {
    padding-inline: 11px;
    font-size: 13px !important;
    font-weight: 650;
  }

  body .head-subnav-dropdown-icon {
    width: 16px;
    height: 16px;
  }

  body .head-subnav-separator {
    display: inline-flex !important;
  }

  body .head-subnav-popup {
    display: none !important;
    min-width: 290px;
    max-width: min(330px, calc(100vw - 24px));
  }

  body .head-subnav-item:hover > .head-subnav-popup,
  body .head-subnav-item:focus-within > .head-subnav-popup {
    display: block !important;
  }

  body .head-subnav-btn {
    margin-left: 0;
    padding-inline: 14px;
    font-size: 13px;
  }
}
@media (max-width: 980px) {
  body .head-subnav-item > .head-subnav-popup,
  body .head-subnav-item:hover > .head-subnav-popup,
  body .head-subnav-item:focus-within > .head-subnav-popup {
    display: none !important;
  }

  body .head-subnav-item.is-touch-open > .head-subnav-popup {
    display: block !important;
  }

  body .head-subnav-item:hover > .head-subnav-link .head-subnav-dropdown-icon,
  body .head-subnav-item:focus-within > .head-subnav-link .head-subnav-dropdown-icon {
    transform: none;
  }

  body .head-subnav-item.is-touch-open > .head-subnav-link .head-subnav-dropdown-icon {
    transform: rotate(180deg);
    opacity: 1;
  }

  body .head-subnav.has-touch-popup-open {
    overflow: visible !important;
  }
}
@media (prefers-reduced-motion: reduce) {
  .head-nav-main-header .head-nav-center, .head-nav-menu-backdrop, .head-nav-mobile-toggle span { transition: none; }
}
</style>

