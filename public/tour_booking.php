<?php
chdir(__DIR__ . '/..');
session_start();
require_once 'php/db_connection.php';

if (!isset($_SESSION['tourist_id'])) {
    $target = $_SERVER['REQUEST_URI'] ?? 'tour_booking.php';
    $_SESSION['post_login_redirect'] = $target;
    header('Location: homepage.php?open_login=1');
    exit;
}

function isDefaultProfileImage(?string $value): bool {
    $name = strtolower(basename(trim((string)$value)));
    return in_array($name, ['profileicon.png', 'profileicon2.png'], true);
}

function normalizeProfileImage(?string $value): string {
    $candidate = trim((string)$value);
    if ($candidate === '') {
        return '';
    }
    if (preg_match('~^https?://~i', $candidate)) {
        if (stripos($candidate, 'profiles.google.com') !== false
            && preg_match('#profiles\\.google\\.com/(?:s2/photos/profile/)?([^/?#]+)(?:/picture)?#i', $candidate, $m)) {
            return 'https://profiles.google.com/' . rawurlencode($m[1]) . '/picture?sz=256';
        }
        if (stripos($candidate, 'googleusercontent.com') !== false) {
            $candidate = preg_replace('/([?&])sz=\\d+/i', '$1sz=256', $candidate);
            $candidate = preg_replace('/=s\\d+-c(?=$|[?&#])/i', '=s256-c', $candidate);
            $candidate = preg_replace('/=s\\d+(?=$|[?&#])/i', '=s256', $candidate);
        }
        return $candidate;
    }
    return ltrim($candidate, '/\\');
}

function buildGoogleProfileImageById(?string $googleId): string {
    $id = trim((string)$googleId);
    if ($id === '') {
        return '';
    }
    return 'https://profiles.google.com/' . rawurlencode($id) . '/picture?sz=256';
}

function resolveProfileImage(?string $path, ?string $googleId = null): string {
    $normalized = normalizeProfileImage($path);
    if ($normalized !== '' && !isDefaultProfileImage($normalized)) {
        if (preg_match('~^https?://~i', $normalized)) {
            return $normalized;
        }
        $candidates = [
            $normalized,
            'php/upload/' . basename($normalized),
            'uploads/profile/' . basename($normalized),
            'uploads/profile_pictures/' . basename($normalized),
        ];
        foreach ($candidates as $candidate) {
            if (file_exists(__DIR__ . '/../' . $candidate)) {
                return $candidate;
            }
        }
    }

    $googleImage = buildGoogleProfileImageById($googleId);
    if ($googleImage !== '') {
        return $googleImage;
    }
    return 'img/profileicon.png';
}

function resolveAssetImage(?string $path, string $fallback = 'img/sampleimage.png'): string {
    $path = trim((string)$path);
    if ($path === '') return $fallback;
    if (preg_match('~^https?://~i', $path)) return $path;

    $file = basename($path);
    $candidates = [
        $path,
        'php/upload/' . $file,
        'upload/' . $file,
        'uploads/' . $file,
        'uploads/packages/' . $file,
        'img/' . $file,
    ];

    foreach ($candidates as $candidate) {
        if (file_exists(__DIR__ . '/../' . $candidate)) {
            return $candidate;
        }
    }
    return $fallback;
}

function sanitizeBookingType(string $type): string {
    $type = strtolower(trim($type));
    return in_array($type, ['boat', 'tourguide', 'package'], true) ? $type : '';
}

function sanitizeReturnUrl(?string $url): string {
    $url = trim((string)$url);
    if ($url === '') return 'tourss.php';

    $parts = @parse_url($url);
    if (!$parts) return 'tourss.php';
    if (isset($parts['scheme']) || isset($parts['host'])) return 'tourss.php';

    $clean = ltrim($url, '/\\');
    if ($clean === '') return 'tourss.php';
    if (preg_match('/[\r\n]/', $clean)) return 'tourss.php';
    if (!preg_match('/^[A-Za-z0-9_\-\/\.?=&%]+$/', $clean)) return 'tourss.php';

    return $clean;
}

$touristId = (int)$_SESSION['tourist_id'];
$stmtUser = $pdo->prepare("SELECT * FROM tourist WHERE tourist_id = ?");
$stmtUser->execute([$touristId]);
$user = $stmtUser->fetch(PDO::FETCH_ASSOC);
if (!$user) {
    header('Location: homepage.php');
    exit;
}

$fullName = trim((string)($user['full_name'] ?? ''));
$firstName = trim((string)($user['first_name'] ?? ''));
$lastName = trim((string)($user['last_name'] ?? ''));
if (($firstName === '' || $lastName === '') && $fullName !== '') {
    $parts = preg_split('/\s+/', $fullName);
    if ($firstName === '') $firstName = (string)($parts[0] ?? '');
    if ($lastName === '') $lastName = count($parts) > 1 ? implode(' ', array_slice($parts, 1)) : '';
}
$profileImage = resolveProfileImage($user['profile_picture'] ?? '', (string)($user['google_id'] ?? ''));
if ($profileImage === 'img/profileicon.png' && !empty($_SESSION['tourist_profile_pic'])) {
    $profileImage = resolveProfileImage((string)$_SESSION['tourist_profile_pic'], (string)($user['google_id'] ?? ''));
}
$profileInitial = strtoupper(substr($firstName !== '' ? $firstName : ($fullName ?: 'U'), 0, 1));
$phoneDefault = trim((string)($user['phone'] ?? ''));
$bookingAccountName = trim($fullName);
if ($bookingAccountName === '') {
    $bookingAccountName = trim($firstName . ' ' . $lastName);
}
if ($bookingAccountName === '') {
    $bookingAccountName = 'Your account';
}

$stmtPackages = $pdo->prepare("
    SELECT
        p.package_id,
        p.package_title,
        p.package_type,
        p.package_range,
        p.price,
        p.operator_id,
        p.package_image,
        o.fullname AS operator_name
    FROM tour_packages p
    JOIN operators o ON o.operator_id = p.operator_id
    WHERE o.status = 'active'
    ORDER BY p.package_title ASC
");
$stmtPackages->execute();
$packagesRaw = $stmtPackages->fetchAll(PDO::FETCH_ASSOC);
$packages = [];
foreach ($packagesRaw as $row) {
    $packages[] = [
        'id' => (int)$row['package_id'],
        'title' => (string)$row['package_title'],
        'type' => (string)$row['package_type'],
        'range' => (string)$row['package_range'],
        'price' => (float)$row['price'],
        'operator_id' => (int)$row['operator_id'],
        'operator_name' => (string)$row['operator_name'],
        'image' => resolveAssetImage($row['package_image'] ?? ''),
    ];
}

$stmtBoats = $pdo->prepare("
    SELECT boat_id, name, total_pax, size, boat_number, image1
    FROM boats
    ORDER BY name ASC
");
$stmtBoats->execute();
$boatsRaw = $stmtBoats->fetchAll(PDO::FETCH_ASSOC);
$boats = [];
foreach ($boatsRaw as $row) {
    $boats[] = [
        'id' => (int)$row['boat_id'],
        'name' => (string)$row['name'],
        'capacity' => (string)$row['total_pax'],
        'size' => (string)$row['size'],
        'boat_number' => (string)$row['boat_number'],
        'image' => resolveAssetImage($row['image1'] ?? ''),
    ];
}

$stmtGuides = $pdo->prepare("
    SELECT guide_id, fullname, profile_picture
    FROM tour_guides
    ORDER BY fullname ASC
");
$stmtGuides->execute();
$guidesRaw = $stmtGuides->fetchAll(PDO::FETCH_ASSOC);
$guides = [];
foreach ($guidesRaw as $row) {
    $guides[] = [
        'id' => (int)$row['guide_id'],
        'name' => (string)$row['fullname'],
        'image' => resolveAssetImage($row['profile_picture'] ?? '', 'img/profileicon.png'),
    ];
}

$stmtServicePrices = $pdo->prepare("
    SELECT service_type, day_tour_price, overnight_price
    FROM service_prices
    WHERE is_active = 1
");
$stmtServicePrices->execute();
$servicePricesRaw = $stmtServicePrices->fetchAll(PDO::FETCH_ASSOC);
$servicePrices = [
    'boat' => ['day' => 0, 'overnight' => 0],
    'tourguide' => ['day' => 0, 'overnight' => 0],
];
foreach ($servicePricesRaw as $row) {
    $key = strtolower((string)$row['service_type']);
    if (!isset($servicePrices[$key])) continue;
    $servicePrices[$key]['day'] = (float)($row['day_tour_price'] ?? 0);
    $servicePrices[$key]['overnight'] = (float)($row['overnight_price'] ?? 0);
}

$prefillType = sanitizeBookingType((string)($_GET['booking_type'] ?? ''));
$prefillPackageId = max(0, (int)($_GET['package_id'] ?? 0));
$prefillPreferred = trim((string)($_GET['preferred'] ?? ''));
if ($prefillPreferred !== '') {
    $prefillPreferred = substr($prefillPreferred, 0, 120);
}
if ($prefillPackageId > 0) {
    $hasPackage = false;
    foreach ($packages as $package) {
        if ((int)$package['id'] === $prefillPackageId) {
            $hasPackage = true;
            break;
        }
    }
    if ($hasPackage) {
        $prefillType = 'package';
    } else {
        $prefillPackageId = 0;
    }
}

$backLink = sanitizeReturnUrl($_GET['return'] ?? 'tourss.php');
$locations = [
    'Malasugui Island',
    'Caringo Island',
    'Apuao Grande Island',
    'Apuao Pequeña Island',
    'Canimog Island',
    'Quinapaguian Island',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Tour Booking | iTour Mercedes</title>
  <link rel="icon" type="image/png" href="img/newlogo.png" />
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css" />
  <link rel="stylesheet" href="public/styles/tour_booking.css" />
  <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
</head>
<body>
  <header class="booking-header">
    <div class="booking-header-left">
      <a class="back-btn" href="<?= htmlspecialchars($backLink) ?>" aria-label="Go back">&#8249; Back</a>
      <div class="brand-wrap">
        <img src="img/newlogo.png" alt="iTour Mercedes logo" class="brand-round-logo" />
        <img src="img/textlogo2.png" alt="iTour Mercedes" class="brand-text-logo" />
      </div>
    </div>
    <div class="profile-chip" aria-label="Your profile">
      <?php if ($profileImage !== 'img/profileicon.png'): ?>
        <img
          src="<?= htmlspecialchars($profileImage) ?>"
          alt="Profile"
          onerror="this.onerror=null;this.style.display='none';this.parentElement.querySelector('.profile-initial-fallback').style.display='inline-flex';"
        />
        <span class="profile-initial-fallback" style="display:none;"><?= htmlspecialchars($profileInitial) ?></span>
      <?php else: ?>
        <span><?= htmlspecialchars($profileInitial) ?></span>
      <?php endif; ?>
    </div>
  </header>

  <main class="booking-page">
    <div class="booking-grid">
      <section class="booking-form-card">
        <h1>Book Your Island Experience</h1>
        <p class="subtitle">Fill in your details. Your booking preview updates in real-time on the right.</p>
        <p class="account-note">Booking name will be taken from your logged-in account: <strong><?= htmlspecialchars($bookingAccountName) ?></strong>.</p>

        <form id="tourBookingForm" class="booking-form" novalidate>
          <div class="field-row">
            <label>
              Booking Type
              <select id="bookingType" name="booking_type" required>
                <option value="">Select booking type</option>
                <option value="boat" <?= $prefillType === 'boat' ? 'selected' : '' ?>>Tour Boat</option>
                <option value="tourguide" <?= $prefillType === 'tourguide' ? 'selected' : '' ?>>Tour Guide</option>
                <option value="package" <?= $prefillType === 'package' ? 'selected' : '' ?>>Tour Package</option>
              </select>
            </label>
          </div>

          <div class="field-row" id="packageWrapper">
            <label>
              Tour Package
              <select id="packageName" name="package_name">
                <option value="">Select package</option>
                <?php foreach ($packages as $package): ?>
                  <option
                    value="<?= htmlspecialchars($package['title']) ?>"
                    data-package-id="<?= (int)$package['id'] ?>"
                    data-package-type="<?= htmlspecialchars($package['type']) ?>"
                    data-package-range="<?= htmlspecialchars($package['range']) ?>"
                    data-package-price="<?= htmlspecialchars((string)$package['price']) ?>"
                    data-operator-id="<?= (int)$package['operator_id'] ?>"
                    data-image="<?= htmlspecialchars($package['image']) ?>"
                    <?= $prefillPackageId === (int)$package['id'] ? 'selected' : '' ?>
                  >
                    <?= htmlspecialchars($package['title']) ?> - ₱<?= number_format((float)$package['price'], 2) ?>/pax
                  </option>
                <?php endforeach; ?>
              </select>
            </label>
          </div>

          <div class="field-row" id="preferredWrapper">
            <label>
              Preferred Tour Guide / Boat
              <select id="preferredSelection" name="preferred_selection">
                <option value="">No specific preference</option>
              </select>
            </label>
            <p class="helper-text">Your preferred selection is subject to availability and may be changed by the office.</p>
          </div>

          <div class="field-row two">
            <label>
              Tour Type
              <select id="tourType" name="tour_type" required>
                <option value="">Select tour type</option>
                <option value="same-day">Same Day</option>
                <option value="overnight">Overnight</option>
              </select>
            </label>
            <label>
              Duration
              <input type="text" id="tourDuration" name="tour_duration" placeholder="e.g. 2 Days 1 Night" readonly required />
            </label>
          </div>
          <p class="helper-text" id="durationHint">For overnight booking, tap the Duration field to pick a date range.</p>

          <fieldset id="locationsWrapper" class="locations-box">
            <legend>Locations to Visit (max 2)</legend>
            <p class="helper-text">A maximum of two locations is included in the base service price. Extra locations may require additional fees.</p>
            <div class="locations-grid">
              <?php foreach ($locations as $location): ?>
                <label class="location-chip">
                  <input type="checkbox" name="locations[]" value="<?= htmlspecialchars($location) ?>" />
                  <span><?= htmlspecialchars($location) ?></span>
                </label>
              <?php endforeach; ?>
            </div>
          </fieldset>

          <div class="field-row two">
            <label>
              Jump-Off Port
              <select id="jumpOffPort" name="jump_off_port" required>
                <option value="">Select jump-off port</option>
                <option value="Mercedes Port">Mercedes Port</option>
                <option value="Cayucyucan">Cayucyucan</option>
              </select>
            </label>
            <label>
              Start Date
              <input type="date" id="bookingDate" name="booking_date" required />
            </label>
          </div>

          <div class="field-row" id="bookingEndDateWrapper">
            <label>
              End Date (Overnight)
              <input type="date" id="bookingEndDate" name="booking_end_date" />
            </label>
          </div>

          <div class="field-row two">
            <label>
              Contact Number
              <input type="tel" id="contactNumber" name="contact_number" value="<?= htmlspecialchars($phoneDefault) ?>" placeholder="Enter active mobile number" required />
            </label>
            <label>
              Adults
              <input type="number" id="numAdults" name="num_adults" min="0" value="1" required />
            </label>
          </div>

          <div class="field-row">
            <label>
              Children
              <input type="number" id="numChildren" name="num_children" min="0" value="0" required />
            </label>
          </div>

          <div class="field-row">
            <label>
              Environmental Fee Category
              <select id="ecoCategory" name="eco_category" required>
                <option value="local">Local</option>
                <option value="foreigner">Foreigner</option>
                <option value="mercedeno">Mercedeño (50% discount)</option>
                <option value="senior">Senior Citizen (20% discount)</option>
              </select>
            </label>
            <p class="helper-text">Children 12 years old and below are free for environmental fee.</p>
          </div>

          <div class="field-row">
            <label>
              Payment Option
              <select id="paymentOption" name="payment_option" required>
                <option value="partial" selected>20% Partial Payment</option>
                <option value="full">Full Payment</option>
              </select>
            </label>
            <p class="helper-text">Choose how much to pay now. Booking confirmation is still manual for now.</p>
          </div>

          <div id="addonWrapper" class="checkbox-box addon-box">
            <label>
              <input type="checkbox" id="includeAddonService" />
              <span id="addonServiceLabel">Include additional service</span>
            </label>
            <p class="helper-text" id="addonServiceHint"></p>
          </div>

          <div class="other-fees-reference">
            <h3>Other Fees Reference</h3>
            <ul>
              <li><span>Environmental Fee (Foreigner / Local)</span><strong>₱100 / ₱50 per person</strong></li>
              <li><span>Entrance Fee (Apuao Grande / Caringo)</span><strong>₱30 / ₱20 per head</strong></li>
              <li><span>Entrance Fee (Canimog Day / Overnight)</span><strong>₱100 / ₱250 per head</strong></li>
              <li><span>Docking Fee (Apuao Pequeña / Malasugui / Canimog)</span><strong>₱500 / ₱300 / ₱500 per boat</strong></li>
            </ul>
          </div>

          <div class="checkbox-box">
            <label>
              <input type="checkbox" id="agreePrivacy" />
              I agree to the <a href="privacy-policy.php" target="_blank" rel="noopener">Privacy Policy</a>.
            </label>
            <label>
              <input type="checkbox" id="agreeOtherFees" />
              I acknowledge that additional fees may apply based on selected destinations and activities.
            </label>
          </div>

          <button type="submit" id="payBookingBtn" class="submit-btn">Pay</button>
        </form>
      </section>

      <aside class="booking-preview-card">
        <div class="preview-block preview-main">
          <h3>Booking Preview</h3>
          <p><span>Booking Type</span><strong id="previewType">Not selected</strong></p>
          <p><span>Package</span><strong id="previewPackage">-</strong></p>
          <p><span>Preferred</span><strong id="previewPreferred">-</strong></p>
          <p><span>Add-on Service</span><strong id="previewAddon">-</strong></p>
          <p><span>Tour Type</span><strong id="previewTourType">-</strong></p>
          <p><span>Duration</span><strong id="previewDuration">-</strong></p>
          <p><span>Locations</span><strong id="previewLocations">-</strong></p>
          <p><span>Jump-Off Port</span><strong id="previewPort">-</strong></p>
          <p><span>Date</span><strong id="previewDate">-</strong></p>
          <p><span>Contact</span><strong id="previewContact">-</strong></p>
          <p><span>Guests</span><strong id="previewGuests">0</strong></p>
          <p><span>Required Boats</span><strong id="previewRequiredBoats">-</strong></p>
        </div>

        <div class="preview-block totals">
          <h3>Fees & Summary</h3>
          <p><span>Environmental Fee</span><strong id="previewEcoFee">-</strong></p>
          <p><span>Total Discount</span><strong id="previewDiscountTotal">-</strong></p>
          <p><span>Entrance Fee</span><strong id="previewEntranceFee">-</strong></p>
          <p><span>Docking/Landing Fee</span><strong id="previewDockingFee">-</strong></p>
          <p><span>Total Boat Bill</span><strong id="previewBoatBill">-</strong></p>
          <small id="previewFeesNote">Other fees vary by selected locations and tour type.</small>
          <p><span>Service Subtotal</span><strong id="previewServiceTotal">-</strong></p>
          <p><span>Other Fees Subtotal</span><strong id="previewOtherFeesTotal">-</strong></p>
          <p><span>Estimated Grand Total</span><strong id="previewEstimatedTotal">-</strong></p>
          <p><span>Payment Option</span><strong id="previewPaymentOption">20% Partial</strong></p>
          <p><span>Amount to Pay Now</span><strong id="previewAmountPayable">-</strong></p>
          <small id="previewPricingNote">Select your booking type and details to view your estimate.</small>
        </div>
      </aside>
    </div>
  </main>

  <script>
    const BOOKING_DATA = <?= json_encode([
      'servicePrices' => $servicePrices,
      'packages' => $packages,
      'boats' => $boats,
      'guides' => $guides,
      'prefillType' => $prefillType,
      'prefillPackageId' => $prefillPackageId,
      'prefillPreferred' => $prefillPreferred,
      'returnUrl' => $backLink,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

    (function () {
      const MAX_BOAT_PAX = 8;
      const OTHER_FEES = {
        environmental: {
          foreigner: 100,
          local: 50,
          mercedeno: 25,
          senior: 40
        },
        entrance: {
          "Apuao Grande Island": 30,
          "Caringo Island": 20,
          "Canimog Island": { day: 100, overnight: 250 }
        },
        docking: {
          "Apuao Pequeña Island": 500,
          "Malasugui Island": 300,
          "Canimog Island": 500
        }
      };

      const bookingForm = document.getElementById("tourBookingForm");
      const bookingType = document.getElementById("bookingType");
      const packageWrapper = document.getElementById("packageWrapper");
      const packageSelect = document.getElementById("packageName");
      const preferredWrapper = document.getElementById("preferredWrapper");
      const preferredSelect = document.getElementById("preferredSelection");
      const addonWrapper = document.getElementById("addonWrapper");
      const includeAddonService = document.getElementById("includeAddonService");
      const addonServiceLabel = document.getElementById("addonServiceLabel");
      const addonServiceHint = document.getElementById("addonServiceHint");
      const tourType = document.getElementById("tourType");
      const tourDuration = document.getElementById("tourDuration");
      const durationHint = document.getElementById("durationHint");
      const locationsWrapper = document.getElementById("locationsWrapper");
      const locationInputs = [...document.querySelectorAll('input[name="locations[]"]')];
      const jumpOffPort = document.getElementById("jumpOffPort");
      const bookingDate = document.getElementById("bookingDate");
      const bookingEndDate = document.getElementById("bookingEndDate");
      const bookingEndDateWrapper = document.getElementById("bookingEndDateWrapper");
      const contactNumber = document.getElementById("contactNumber");
      const numAdults = document.getElementById("numAdults");
      const numChildren = document.getElementById("numChildren");
      const ecoCategory = document.getElementById("ecoCategory");
      const paymentOption = document.getElementById("paymentOption");
      const agreePrivacy = document.getElementById("agreePrivacy");
      const agreeOtherFees = document.getElementById("agreeOtherFees");
      const payBookingBtn = document.getElementById("payBookingBtn");

      const previewType = document.getElementById("previewType");
      const previewPackage = document.getElementById("previewPackage");
      const previewPreferred = document.getElementById("previewPreferred");
      const previewAddon = document.getElementById("previewAddon");
      const previewTourType = document.getElementById("previewTourType");
      const previewDuration = document.getElementById("previewDuration");
      const previewLocations = document.getElementById("previewLocations");
      const previewPort = document.getElementById("previewPort");
      const previewDate = document.getElementById("previewDate");
      const previewContact = document.getElementById("previewContact");
      const previewGuests = document.getElementById("previewGuests");
      const previewRequiredBoats = document.getElementById("previewRequiredBoats");
      const previewEcoFee = document.getElementById("previewEcoFee");
      const previewDiscountTotal = document.getElementById("previewDiscountTotal");
      const previewEntranceFee = document.getElementById("previewEntranceFee");
      const previewDockingFee = document.getElementById("previewDockingFee");
      const previewBoatBill = document.getElementById("previewBoatBill");
      const previewFeesNote = document.getElementById("previewFeesNote");
      const previewServiceTotal = document.getElementById("previewServiceTotal");
      const previewOtherFeesTotal = document.getElementById("previewOtherFeesTotal");
      const previewEstimatedTotal = document.getElementById("previewEstimatedTotal");
      const previewPaymentOption = document.getElementById("previewPaymentOption");
      const previewAmountPayable = document.getElementById("previewAmountPayable");
      const previewPricingNote = document.getElementById("previewPricingNote");

      const boats = Array.isArray(BOOKING_DATA.boats) ? BOOKING_DATA.boats : [];
      const guides = Array.isArray(BOOKING_DATA.guides) ? BOOKING_DATA.guides : [];
      const servicePrices = BOOKING_DATA.servicePrices || {};
      const prefillPreferred = String(BOOKING_DATA.prefillPreferred || "");
      let prefillPreferredApplied = false;
      let overnightRangePicker = null;

      const formatMoney = (value) =>
        `₱${Number(value || 0).toLocaleString("en-PH", { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;

      const formatDate = (value) => {
        if (!value) return "-";
        const dt = new Date(value);
        if (Number.isNaN(dt.getTime())) return value;
        return dt.toLocaleDateString("en-US", { year: "numeric", month: "long", day: "numeric" });
      };

      const getTypeLabel = (type) => {
        if (type === "boat") return "Tour Boat";
        if (type === "tourguide") return "Tour Guide";
        if (type === "package") return "Tour Package";
        return "Not selected";
      };

      const normalizeTourType = (value) => {
        const raw = String(value || "").toLowerCase().trim();
        if (["same-day", "same day", "day", "day tour", "day-tour"].includes(raw)) return "same-day";
        if (["overnight", "multi-day", "multiday", "night", "night tour"].includes(raw)) return "overnight";
        return "";
      };

      const toDateOnly = (value) => {
        if (!value) return null;
        const date = new Date(value);
        if (Number.isNaN(date.getTime())) return null;
        date.setHours(0, 0, 0, 0);
        return date;
      };

      const calcNights = (startValue, endValue) => {
        const startDate = toDateOnly(startValue);
        const endDate = toDateOnly(endValue);
        if (!startDate || !endDate) return 0;
        const diff = Math.round((endDate - startDate) / 86400000);
        return diff > 0 ? diff : 0;
      };

      const placeRangeCalendarBelow = () => {
        const calendar = overnightRangePicker?.calendarContainer;
        if (!calendar || !calendar.classList.contains("open")) return;

        const viewportWidth = window.innerWidth || document.documentElement.clientWidth || 0;
        const viewportLeft = window.scrollX;
        const edgePadding = 10;
        const maxAllowedWidth = Math.max(320, viewportWidth - edgePadding * 2);

        calendar.style.maxWidth = `${maxAllowedWidth}px`;

        const inputRect = tourDuration.getBoundingClientRect();
        const calendarWidth = Math.max(
          calendar.getBoundingClientRect().width || 0,
          calendar.offsetWidth || 0,
          calendar.scrollWidth || 0,
          300
        );

        let left = inputRect.left + viewportLeft;
        const maxLeft = viewportLeft + viewportWidth - calendarWidth - edgePadding;
        if (left > maxLeft) left = maxLeft;
        if (left < viewportLeft + edgePadding) left = viewportLeft + edgePadding;

        const top = inputRect.bottom + window.scrollY + 8;
        calendar.style.right = "auto";
        calendar.style.left = `${Math.round(left)}px`;
        calendar.style.top = `${Math.round(top)}px`;
      };

      const syncRangePickerFromFields = () => {
        if (!overnightRangePicker) return;
        if (tourType.value !== "overnight") {
          overnightRangePicker.close();
          return;
        }
        if (bookingDate.value && bookingEndDate.value) {
          overnightRangePicker.setDate([bookingDate.value, bookingEndDate.value], false);
          return;
        }
        if (bookingDate.value) {
          overnightRangePicker.setDate([bookingDate.value], false);
          return;
        }
        overnightRangePicker.clear(false);
      };

      const initOvernightRangePicker = () => {
        if (typeof flatpickr !== "function" || overnightRangePicker) return;
        overnightRangePicker = flatpickr(tourDuration, {
          mode: "range",
          showMonths: 2,
          clickOpens: false,
          static: false,
          appendTo: document.body,
          positionElement: tourDuration,
          position: "below left",
          monthSelectorType: "static",
          nextArrow: "&#8250;",
          prevArrow: "&#8249;",
          minDate: bookingDate.min || "today",
          dateFormat: "Y-m-d",
          disableMobile: true,
          onReady: (_, __, instance) => {
            instance.calendarContainer.classList.add("tour-range-calendar");
          },
          onOpen: () => {
            if (tourType.value !== "overnight") {
              overnightRangePicker.close();
              return;
            }
            requestAnimationFrame(() => {
              placeRangeCalendarBelow();
              requestAnimationFrame(placeRangeCalendarBelow);
            });
            setTimeout(placeRangeCalendarBelow, 30);
          },
          onMonthChange: () => {
            requestAnimationFrame(placeRangeCalendarBelow);
          },
          onChange: (selectedDates) => {
            if (tourType.value !== "overnight") return;

            if (selectedDates.length === 2) {
              const startDate = new Date(selectedDates[0]);
              const endDate = new Date(selectedDates[1]);
              startDate.setHours(0, 0, 0, 0);
              endDate.setHours(0, 0, 0, 0);

              if (endDate <= startDate) {
                Swal.fire({
                  icon: "warning",
                  title: "Invalid date range",
                  text: "End date must be after start date for overnight booking.",
                  confirmButtonColor: "#2b7a66"
                });
                bookingDate.value = "";
                bookingEndDate.value = "";
                tourDuration.value = "";
                updatePreview();
                return;
              }

              bookingDate.value = flatpickr.formatDate(startDate, "Y-m-d");
              bookingEndDate.value = flatpickr.formatDate(endDate, "Y-m-d");
              updateOvernightDateRangeState();
              syncPackageDerivedFields();
              applyTourTypeDurationRules();
              updatePreview();
              return;
            }

            if (selectedDates.length === 1) {
              bookingDate.value = flatpickr.formatDate(selectedDates[0], "Y-m-d");
              bookingEndDate.value = "";
              updateOvernightDateRangeState();
              applyTourTypeDurationRules();
              updatePreview();
              return;
            }

            bookingDate.value = "";
            bookingEndDate.value = "";
            tourDuration.value = "";
            updateOvernightDateRangeState();
            updatePreview();
          }
        });

        window.addEventListener("resize", placeRangeCalendarBelow);
        window.addEventListener("scroll", placeRangeCalendarBelow, true);
      };

      const openOvernightRangePicker = () => {
        if (!overnightRangePicker) return;
        if (tourType.value !== "overnight") {
          overnightRangePicker.close();
          return;
        }
        overnightRangePicker.open();
      };

      const getRateKey = () => (tourType.value === "overnight" ? "overnight" : "day");
      const getSelectedLocations = () => locationInputs.filter((item) => item.checked).map((item) => item.value);
      const getAdultCount = () => Math.max(0, Number(numAdults.value || 0));
      const getChildCount = () => Math.max(0, Number(numChildren.value || 0));
      const getTotalGuests = () => getAdultCount() + getChildCount();

      const getAddOnType = () => {
        if (!includeAddonService.checked) return "";
        if (bookingType.value === "boat") return "tourguide";
        if (bookingType.value === "tourguide") return "boat";
        return "";
      };

      const getServiceRate = (type, rateKey) => Number(servicePrices?.[type]?.[rateKey] || 0);

      const populatePreferredOptions = (type) => {
        preferredSelect.innerHTML = '<option value="">No specific preference</option>';
        const list = type === "boat" ? boats : type === "tourguide" ? guides : [];

        list.forEach((item) => {
          const option = document.createElement("option");
          option.value = item.name;
          option.textContent = item.name;
          preferredSelect.appendChild(option);
        });

        if (!prefillPreferredApplied && prefillPreferred !== "") {
          const matched = [...preferredSelect.options].find((item) => item.value === prefillPreferred);
          if (matched) {
            preferredSelect.value = matched.value;
            prefillPreferredApplied = true;
          }
        }
      };

      const applyTourTypeDurationRules = () => {
        const type = bookingType.value;
        if (!(type === "boat" || type === "tourguide" || type === "package")) return;

        if (tourType.value === "same-day") {
          tourDuration.value = "1 Day";
          tourDuration.placeholder = "1 Day";
          tourDuration.readOnly = true;
          bookingEndDate.value = "";
        } else if (tourType.value === "overnight") {
          const nights = calcNights(bookingDate.value, bookingEndDate.value);
          if (nights > 0) {
            const days = nights + 1;
            tourDuration.value = `${days} Day${days > 1 ? "s" : ""} ${nights} Night${nights > 1 ? "s" : ""}`;
          } else {
            tourDuration.value = "";
            tourDuration.placeholder = "Tap to select overnight date range";
          }
          tourDuration.readOnly = true;
        } else {
          tourDuration.value = "";
          tourDuration.readOnly = true;
          bookingEndDate.value = "";
          tourDuration.placeholder = "e.g. 2 Days 1 Night";
        }
      };

      const updateOvernightDateRangeState = () => {
        const needsRange = tourType.value === "overnight";
        bookingEndDateWrapper.style.display = "none";
        bookingEndDate.required = needsRange;
        if (durationHint) {
          durationHint.style.display = needsRange ? "block" : "none";
        }
        tourDuration.classList.toggle("range-ready", needsRange);
        if (!needsRange && overnightRangePicker) {
          overnightRangePicker.close();
        }

        if (bookingDate.value) {
          const startDate = toDateOnly(bookingDate.value);
          if (startDate) {
            if (needsRange) {
              startDate.setDate(startDate.getDate() + 1);
              bookingEndDate.min = startDate.toISOString().split("T")[0];
            } else {
              bookingEndDate.min = bookingDate.value;
            }
          }
        } else {
          bookingEndDate.min = "";
        }

        if (!needsRange) {
          bookingEndDate.value = "";
        } else if (bookingEndDate.value && calcNights(bookingDate.value, bookingEndDate.value) < 1) {
          bookingEndDate.value = "";
        }
      };

      const syncPackageDerivedFields = () => {
        const selected = packageSelect.selectedOptions[0];
        if (bookingType.value !== "package" || !selected || !selected.value) {
          if (bookingType.value === "package") {
            tourType.value = "";
            tourDuration.value = "";
          }
          return;
        }

        const packageType = normalizeTourType(selected.dataset.packageType || "");
        const packageRange = selected.dataset.packageRange || "";
        const packageNights = calcNights(bookingDate.value, bookingEndDate.value);
        const computedPackageRange = packageNights > 0
          ? `${packageNights + 1} Day${packageNights + 1 > 1 ? "s" : ""} ${packageNights} Night${packageNights > 1 ? "s" : ""}`
          : "";
        tourType.value = packageType;
        tourDuration.value = packageType === "overnight"
          ? computedPackageRange
          : (packageRange || (packageType === "same-day" ? "1 Day" : ""));

        if (packageType) {
          tourType.disabled = true;
          tourDuration.readOnly = true;
        } else {
          tourType.disabled = false;
          tourDuration.readOnly = false;
        }
      };

      const updateAddOnUI = () => {
        const type = bookingType.value;
        const rateKey = getRateKey();

        if (type === "boat") {
          addonWrapper.style.display = "grid";
          addonServiceLabel.textContent = "Include Tour Guide with this boat booking";
          const addOnRate = getServiceRate("tourguide", rateKey);
          addonServiceHint.textContent = `Adds Tour Guide ${rateKey === "overnight" ? "Overnight" : "Day Tour"} rate: ${formatMoney(addOnRate)}.`;
          return;
        }

        if (type === "tourguide") {
          addonWrapper.style.display = "grid";
          addonServiceLabel.textContent = "Include Boat with this tour guide booking";
          const addOnRate = getServiceRate("boat", rateKey);
          addonServiceHint.textContent = `Adds Boat ${rateKey === "overnight" ? "Overnight" : "Day Tour"} rate: ${formatMoney(addOnRate)}. 1 boat accommodates up to ${MAX_BOAT_PAX} passengers.`;
          return;
        }

        includeAddonService.checked = false;
        addonWrapper.style.display = "none";
        addonServiceHint.textContent = "";
      };

      const getEntranceFeePerHead = (location, rateKey) => {
        if (location === "Canimog Island") {
          const canimog = OTHER_FEES.entrance["Canimog Island"] || {};
          return Number(canimog[rateKey] || 0);
        }
        return Number(OTHER_FEES.entrance[location] || 0);
      };

      const calculatePricing = () => {
        const type = bookingType.value;
        const selectedPackageOption = packageSelect.selectedOptions[0];
        const packageTitle = selectedPackageOption?.value || "";
        const packagePrice = Number(selectedPackageOption?.dataset.packagePrice || 0);
        const selectedLocations = getSelectedLocations();
        const adults = getAdultCount();
        const children = getChildCount();
        const totalGuests = adults + children;
        const rateKey = getRateKey();
        const addOnType = getAddOnType();
        const usesBoat = type === "boat" || addOnType === "boat";
        const requiredBoats = usesBoat ? Math.max(1, Math.ceil(Math.max(totalGuests, 1) / MAX_BOAT_PAX)) : 0;

        let primarySubtotal = 0;
        if (type === "package" && packageTitle !== "") {
          primarySubtotal = packagePrice * Math.max(1, totalGuests);
        } else if (type === "boat") {
          primarySubtotal = getServiceRate("boat", rateKey) * requiredBoats;
        } else if (type === "tourguide") {
          primarySubtotal = getServiceRate("tourguide", rateKey);
        }

        let addOnSubtotal = 0;
        let addOnLabel = "-";
        if (addOnType === "boat") {
          addOnSubtotal = getServiceRate("boat", rateKey) * requiredBoats;
          addOnLabel = `Boat (${requiredBoats} required)`;
        } else if (addOnType === "tourguide") {
          addOnSubtotal = getServiceRate("tourguide", rateKey);
          addOnLabel = "Tour Guide";
        }
        const boatBillTotal = (type === "boat" ? primarySubtotal : 0) + (addOnType === "boat" ? addOnSubtotal : 0);

        const ecoKey = String(ecoCategory.value || "local");
        const ecoRate = Number(OTHER_FEES.environmental[ecoKey] || 0);
        const ecoBaseRateForDiscount = Number(OTHER_FEES.environmental.local || 0);
        const perHeadDiscount = Math.max(ecoBaseRateForDiscount - ecoRate, 0);
        const discountTotal = perHeadDiscount * adults;
        const environmentalFee = ecoRate * adults;

        let entranceFee = 0;
        selectedLocations.forEach((location) => {
          entranceFee += getEntranceFeePerHead(location, rateKey) * Math.max(totalGuests, 0);
        });

        let dockingFee = 0;
        if (usesBoat) {
          selectedLocations.forEach((location) => {
            const dockingPerBoat = Number(OTHER_FEES.docking[location] || 0);
            dockingFee += dockingPerBoat * requiredBoats;
          });
        }

        const serviceTotal = primarySubtotal + addOnSubtotal;
        const otherFeesTotal = environmentalFee + entranceFee + dockingFee;
        const grandTotal = serviceTotal + otherFeesTotal;
        const selectedPaymentOption = paymentOption.value === "full" ? "full" : "partial";
        const paymentPercent = selectedPaymentOption === "full" ? 1 : 0.2;
        const payableNow = grandTotal * paymentPercent;

        return {
          type,
          packageTitle,
          selectedLocations,
          adults,
          children,
          totalGuests,
          rateKey,
          addOnType,
          addOnLabel,
          usesBoat,
          requiredBoats,
          boatBillTotal,
          discountTotal,
          environmentalFee,
          entranceFee,
          dockingFee,
          serviceTotal,
          otherFeesTotal,
          grandTotal,
          selectedPaymentOption,
          paymentPercent,
          payableNow
        };
      };

      const updateSectionsByType = () => {
        const type = bookingType.value;
        const isPackage = type === "package";
        const isService = type === "boat" || type === "tourguide";

        packageWrapper.style.display = isPackage ? "grid" : "none";
        preferredWrapper.style.display = isService ? "grid" : "none";
        locationsWrapper.style.display = isService ? "block" : "none";

        if (isPackage) {
          includeAddonService.checked = false;
          addonWrapper.style.display = "none";
          tourType.disabled = true;
          tourDuration.readOnly = true;
          preferredSelect.innerHTML = '<option value="">No specific preference</option>';
          preferredSelect.value = "";
          syncPackageDerivedFields();
        } else if (isService) {
          packageSelect.value = "";
          tourType.disabled = false;
          populatePreferredOptions(type);
          applyTourTypeDurationRules();
          updateAddOnUI();
        } else {
          includeAddonService.checked = false;
          addonWrapper.style.display = "none";
          packageSelect.value = "";
          preferredSelect.innerHTML = '<option value="">No specific preference</option>';
          preferredSelect.value = "";
          tourType.value = "";
          tourDuration.value = "";
          tourDuration.readOnly = false;
          locationInputs.forEach((item) => { item.checked = false; });
        }

        updateOvernightDateRangeState();
        applyTourTypeDurationRules();
        syncRangePickerFromFields();
      };

      const updatePreview = () => {
        updateAddOnUI();
        const pricing = calculatePricing();
        const preferred = preferredSelect.value || "-";

        previewType.textContent = getTypeLabel(pricing.type);
        previewPackage.textContent = pricing.packageTitle || "-";
        previewPreferred.textContent = preferred;
        previewAddon.textContent = pricing.addOnType ? pricing.addOnLabel : "-";
        previewTourType.textContent = tourType.value ? (tourType.value === "same-day" ? "Same Day" : "Overnight") : "-";
        previewDuration.textContent = tourDuration.value.trim() || "-";
        previewLocations.textContent = pricing.selectedLocations.length ? pricing.selectedLocations.join(", ") : "-";
        previewPort.textContent = jumpOffPort.value || "-";
        previewDate.textContent = tourType.value === "overnight" && bookingEndDate.value
          ? `${formatDate(bookingDate.value)} to ${formatDate(bookingEndDate.value)}`
          : formatDate(bookingDate.value);
        previewContact.textContent = contactNumber.value.trim() || "-";
        previewGuests.textContent = `${pricing.adults} adult(s), ${pricing.children} child(ren)`;
        previewRequiredBoats.textContent = pricing.usesBoat ? `${pricing.requiredBoats} boat(s)` : "Not required";

        previewEcoFee.textContent = pricing.environmentalFee > 0 ? formatMoney(pricing.environmentalFee) : "₱0.00";
        previewDiscountTotal.textContent = pricing.discountTotal > 0 ? `- ${formatMoney(pricing.discountTotal)}` : "₱0.00";
        previewEntranceFee.textContent = pricing.entranceFee > 0 ? formatMoney(pricing.entranceFee) : "₱0.00";
        previewDockingFee.textContent = pricing.dockingFee > 0 ? formatMoney(pricing.dockingFee) : "₱0.00";
        previewBoatBill.textContent = pricing.boatBillTotal > 0 ? formatMoney(pricing.boatBillTotal) : "₱0.00";
        previewServiceTotal.textContent = pricing.serviceTotal > 0 ? formatMoney(pricing.serviceTotal) : "₱0.00";
        previewOtherFeesTotal.textContent = pricing.otherFeesTotal > 0 ? formatMoney(pricing.otherFeesTotal) : "₱0.00";
        previewEstimatedTotal.textContent = pricing.grandTotal > 0 ? formatMoney(pricing.grandTotal) : "₱0.00";
        previewPaymentOption.textContent = pricing.selectedPaymentOption === "full" ? "Full Payment (100%)" : "20% Partial Payment";
        previewAmountPayable.textContent = pricing.payableNow > 0 ? formatMoney(pricing.payableNow) : "₱0.00";
        if (payBookingBtn) {
          payBookingBtn.textContent = pricing.payableNow > 0 ? `Pay ${formatMoney(pricing.payableNow)}` : "Pay";
        }

        if (pricing.usesBoat && pricing.totalGuests > MAX_BOAT_PAX) {
          previewFeesNote.textContent = `Passenger count exceeds ${MAX_BOAT_PAX}. Estimated docking and boat rates use ${pricing.requiredBoats} boats.`;
          previewPricingNote.textContent = `Boat capacity is ${MAX_BOAT_PAX} passengers per boat. This booking needs ${pricing.requiredBoats} boats for ${pricing.totalGuests} passengers.`;
        } else if (pricing.discountTotal > 0) {
          previewFeesNote.textContent = `Discount applied: -${formatMoney(pricing.discountTotal)} on environmental fee.`;
          previewPricingNote.textContent = "Environmental, entrance, and docking fees are estimates based on your selections.";
        } else {
          previewFeesNote.textContent = "Other fees vary by selected locations and tour type.";
          previewPricingNote.textContent = "Environmental, entrance, and docking fees are estimates based on your selections.";
        }
      };

      const validateForm = () => {
        const type = bookingType.value;
        const dateValue = bookingDate.value;
        const today = new Date();
        today.setHours(0, 0, 0, 0);
        const selectedDate = dateValue ? new Date(dateValue) : null;
        const endDateValue = bookingEndDate.value;
        const endDate = endDateValue ? new Date(endDateValue) : null;
        const locations = getSelectedLocations();
        const adults = getAdultCount();
        const children = getChildCount();

        if (!type) return "Please select a booking type.";
        if (type === "package" && !packageSelect.value) return "Please select a tour package.";
        if ((type === "boat" || type === "tourguide") && locations.length === 0) return "Please select at least one location.";
        if ((type === "boat" || type === "tourguide") && locations.length > 2) return "You can only select up to two locations.";
        if (!tourType.value) return "Please select a tour type.";
        if (!jumpOffPort.value) return "Please select a jump-off port.";
        if (!dateValue) return "Please select a booking date.";
        if (!selectedDate || Number.isNaN(selectedDate.getTime()) || selectedDate < today) return "Please select a valid booking date.";
        if (tourType.value === "overnight") {
          if (!endDateValue) return "Please select an end date for overnight booking.";
          if (!endDate || Number.isNaN(endDate.getTime()) || calcNights(dateValue, endDateValue) < 1) {
            return "End date must be after start date for overnight booking.";
          }
        }
        if (!tourDuration.value.trim()) return "Please complete a valid date range to compute tour duration.";
        if (!contactNumber.value.trim()) return "Please enter your contact number.";
        if (adults + children <= 0) return "Please enter at least one guest.";
        if (!ecoCategory.value) return "Please select environmental fee category.";
        if (!paymentOption.value) return "Please select a payment option.";
        if (!agreePrivacy.checked) return "You must agree to the Privacy Policy.";
        if (!agreeOtherFees.checked) return "You must acknowledge the Other Fees.";
        return "";
      };

      const submitBooking = async () => {
        const selectedPackageOption = packageSelect.selectedOptions[0];
        const pricing = calculatePricing();
        const preferredSelectionText = preferredSelect.value.trim();
        const bookingMetaSummary = [
          preferredSelectionText ? `Preferred: ${preferredSelectionText}` : "",
          pricing.addOnType ? `Add-on: ${pricing.addOnLabel}` : "",
          pricing.usesBoat ? `Boat bill: ${formatMoney(pricing.boatBillTotal)}` : "",
          `Payment: ${pricing.selectedPaymentOption === "full" ? "Full" : "20% Partial"}`,
          `Pay now: ${formatMoney(pricing.payableNow)}`
        ].filter(Boolean).join(" | ");

        const payload = {
          bookingType: bookingType.value,
          packageName: bookingType.value === "package" ? packageSelect.value : "",
          selectedLocations: (bookingType.value === "boat" || bookingType.value === "tourguide") ? getSelectedLocations() : [],
          bookingDate: bookingDate.value,
          contactNumber: contactNumber.value.trim(),
          numAdults: pricing.adults,
          numChildren: pricing.children,
          operatorId: bookingType.value === "package" ? Number(selectedPackageOption?.dataset.operatorId || 0) : null,
          tourType: tourType.value,
          tourDuration: tourDuration.value.trim(),
          jumpOffPort: jumpOffPort.value,
          preferredSelection: bookingMetaSummary,
          addOnService: pricing.addOnType,
          paymentOption: pricing.selectedPaymentOption,
          paymentAmount: pricing.payableNow
        };

        const response = await fetch("php/submit_booking.php", {
          method: "POST",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify(payload)
        });
        return response.json();
      };

      const setDateMin = () => {
        const today = new Date();
        const yyyy = today.getFullYear();
        const mm = String(today.getMonth() + 1).padStart(2, "0");
        const dd = String(today.getDate()).padStart(2, "0");
        bookingDate.min = `${yyyy}-${mm}-${dd}`;
        bookingEndDate.min = `${yyyy}-${mm}-${dd}`;
        if (overnightRangePicker) {
          overnightRangePicker.set("minDate", bookingDate.min);
        }
      };

      bookingType.addEventListener("change", () => {
        updateSectionsByType();
        updatePreview();
      });

      packageSelect.addEventListener("change", () => {
        syncPackageDerivedFields();
        updateOvernightDateRangeState();
        applyTourTypeDurationRules();
        syncRangePickerFromFields();
        updatePreview();
      });

      preferredSelect.addEventListener("change", updatePreview);
      includeAddonService.addEventListener("change", updatePreview);
      ecoCategory.addEventListener("change", updatePreview);
      paymentOption.addEventListener("change", updatePreview);

      tourType.addEventListener("change", () => {
        updateOvernightDateRangeState();
        applyTourTypeDurationRules();
        syncRangePickerFromFields();
        updatePreview();
      });

      tourDuration.addEventListener("input", updatePreview);
      tourDuration.addEventListener("click", openOvernightRangePicker);
      tourDuration.addEventListener("keydown", (event) => {
        if (tourType.value === "overnight" && (event.key === "Enter" || event.key === " ")) {
          event.preventDefault();
          openOvernightRangePicker();
        }
      });
      jumpOffPort.addEventListener("change", updatePreview);
      bookingDate.addEventListener("change", () => {
        updateOvernightDateRangeState();
        applyTourTypeDurationRules();
        syncRangePickerFromFields();
        updatePreview();
      });
      bookingEndDate.addEventListener("change", () => {
        applyTourTypeDurationRules();
        syncRangePickerFromFields();
        updatePreview();
      });
      contactNumber.addEventListener("input", updatePreview);
      numAdults.addEventListener("input", updatePreview);
      numChildren.addEventListener("input", updatePreview);

      locationInputs.forEach((input) => {
        input.addEventListener("change", () => {
          const checked = getSelectedLocations();
          if (checked.length > 2) {
            input.checked = false;
            Swal.fire({
              icon: "warning",
              title: "Maximum of 2 locations",
              text: "Please select up to two locations only.",
              confirmButtonColor: "#2b7a66"
            });
          }
          updatePreview();
        });
      });

      bookingForm.addEventListener("submit", async (event) => {
        event.preventDefault();
        const error = validateForm();
        if (error) {
          Swal.fire({
            icon: "warning",
            title: "Incomplete booking details",
            text: error,
            confirmButtonColor: "#2b7a66"
          });
          return;
        }

        const pricing = calculatePricing();
        if (pricing.usesBoat && pricing.totalGuests > MAX_BOAT_PAX) {
          const proceed = await Swal.fire({
            icon: "info",
            title: "Multiple boats required",
            text: `1 boat can only carry ${MAX_BOAT_PAX} passengers. Your booking requires ${pricing.requiredBoats} boats for ${pricing.totalGuests} passengers.`,
            showCancelButton: true,
            confirmButtonText: "Continue Booking",
            cancelButtonText: "Review Booking",
            confirmButtonColor: "#2b7a66"
          });
          if (!proceed.isConfirmed) return;
        }

        try {
          const result = await submitBooking();
          if (result?.success) {
            await Swal.fire({
              icon: "success",
              title: "Booking submitted!",
              text: "Your booking has been sent successfully.",
              confirmButtonColor: "#2b7a66"
            });
            window.location.href = BOOKING_DATA.returnUrl || "tourss.php";
            return;
          }

          Swal.fire({
            icon: "error",
            title: "Booking failed",
            text: result?.message || "Please try again.",
            confirmButtonColor: "#2b7a66"
          });
        } catch (err) {
          Swal.fire({
            icon: "error",
            title: "Booking failed",
            text: err?.message || "An unexpected error occurred.",
            confirmButtonColor: "#2b7a66"
          });
        }
      });

      initOvernightRangePicker();
      setDateMin();
      if (BOOKING_DATA.prefillType) {
        bookingType.value = BOOKING_DATA.prefillType;
      }
      if (BOOKING_DATA.prefillPackageId) {
        const targetOption = [...packageSelect.options].find(
          (opt) => Number(opt.dataset.packageId || 0) === Number(BOOKING_DATA.prefillPackageId)
        );
        if (targetOption) {
          packageSelect.value = targetOption.value;
        }
      }

      updateSectionsByType();
      updatePreview();
    })();
  </script>
</body>
</html>
