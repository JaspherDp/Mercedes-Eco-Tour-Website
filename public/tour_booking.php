<?php
chdir(__DIR__ . '/..');
require_once 'php/session_security.php';
AppSessionStart();
require_once 'php/db_connection.php';
require_once 'php/tourist_auth_helper.php';
require_once 'php/additional_fees_helper.php';

$tourAvailabilityBase = rtrim(str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? ''))), '/');
if (str_ends_with(strtolower($tourAvailabilityBase), '/public')) {
    $tourAvailabilityBase = substr($tourAvailabilityBase, 0, -7);
}
$tourAvailabilityEndpoint = $tourAvailabilityBase . '/php/tour_resource_availability.php';

$target = (string)($_SERVER['REQUEST_URI'] ?? 'tour_booking.php');
$user = TouristRequireLogin($pdo, 'redirect', './?open_login=1', $target);
$bookingCheckoutCsrf = (string)($_SESSION['paymongo_booking_csrf'] ?? '');
if ($bookingCheckoutCsrf === '') {
    $bookingCheckoutCsrf = bin2hex(random_bytes(32));
    $_SESSION['paymongo_booking_csrf'] = $bookingCheckoutCsrf;
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
    return '';
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
    if ($url === '') return 'hotel_resorts.php?tab=tours';

    $parts = @parse_url($url);
    if (!$parts) return 'hotel_resorts.php?tab=tours';
    if (isset($parts['scheme']) || isset($parts['host'])) return 'hotel_resorts.php?tab=tours';

    $clean = ltrim($url, '/\\');
    if ($clean === '') return 'hotel_resorts.php?tab=tours';
    if (preg_match('/[\r\n]/', $clean)) return 'hotel_resorts.php?tab=tours';
    if (!preg_match('/^[A-Za-z0-9_\-\/\.?=&%]+$/', $clean)) return 'hotel_resorts.php?tab=tours';

    return $clean;
}

$touristId = (int)$_SESSION['tourist_id'];
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
$additionalFees = getAdditionalFees($pdo);
$bookingAdditionalFees = additionalFeesForBooking($additionalFees);

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

$prefillDestinations = [];
$rawDestinations = trim((string)($_GET['destinations'] ?? ''));
if ($rawDestinations !== '') {
    foreach (explode('|', $rawDestinations) as $token) {
        $value = trim((string)$token);
        if ($value !== '' && !in_array($value, $prefillDestinations, true)) {
            $prefillDestinations[] = $value;
        }
    }
}
foreach (['destination', 'destination2'] as $destinationKey) {
    $value = trim((string)($_GET[$destinationKey] ?? ''));
    if ($value !== '' && !in_array($value, $prefillDestinations, true)) {
        $prefillDestinations[] = $value;
    }
}
$prefillDestinations = array_slice($prefillDestinations, 0, 2);

$prefillTourType = strtolower(trim((string)($_GET['tour_type'] ?? $_GET['tour_date_mode'] ?? '')));
if (in_array($prefillTourType, ['sameday', 'same day', 'day', 'day tour'], true)) {
    $prefillTourType = 'same-day';
} elseif (in_array($prefillTourType, ['overnight', 'night', 'multi-day', 'multiday'], true)) {
    $prefillTourType = 'overnight';
} else {
    $prefillTourType = '';
}

$prefillTourDuration = trim((string)($_GET['tour_duration'] ?? ''));
if ($prefillTourDuration !== '') {
    $prefillTourDuration = substr($prefillTourDuration, 0, 120);
}

$prefillCheckin = trim((string)($_GET['checkin'] ?? $_GET['date'] ?? ''));
$prefillCheckout = trim((string)($_GET['checkout'] ?? ''));
$isValidDate = static function (string $value): bool {
    return $value === '' || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1;
};
if (!$isValidDate($prefillCheckin)) {
    $prefillCheckin = '';
}
if (!$isValidDate($prefillCheckout)) {
    $prefillCheckout = '';
}

$prefillAdults = max(0, (int)($_GET['adults'] ?? 0));
$prefillChildren = max(0, (int)($_GET['children'] ?? 0));
$prefillChildAges = array_values(array_slice(array_filter(
    array_map('trim', explode(',', (string)($_GET['child_ages'] ?? ''))),
    static fn($age) => preg_match('/^\d{1,2}$/', $age) === 1 && (int)$age >= 0 && (int)$age <= 17
), 0, $prefillChildren));

$backLink = sanitizeReturnUrl($_GET['return'] ?? 'hotel_resorts.php?tab=tours');
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
  <link rel="stylesheet" href="public/styles/tour_booking.css?v=<?= (int)@filemtime(__DIR__ . '/styles/tour_booking.css') ?>" />
  <link rel="stylesheet" href="styles/required-fields.css" />
  <script src="js/required-fields.js" defer></script>
  <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
  <script src="js/request-limit.js?v=<?= (int)@filemtime(__DIR__ . '/../js/request-limit.js') ?>"></script>
  <script src="https://cdn.jsdelivr.net/npm/zod@3.23.8/lib/index.umd.min.js"></script>
  <script src="js/booking-validation-schemas.js"></script>
<style>
  .tour-range-calendar .flatpickr-day.flatpickr-disabled,
  .tour-range-calendar .flatpickr-day.flatpickr-disabled:hover,
  .flatpickr-calendar .flatpickr-day.flatpickr-disabled,
  .flatpickr-calendar .flatpickr-day.flatpickr-disabled:hover {
    border-color: transparent !important;
    background: #e5e8e7 !important;
    color: #9aa39f !important;
    box-shadow: none !important;
    cursor: not-allowed !important;
    text-decoration: line-through;
    opacity: 1 !important;
  }
  #durationHint.needs-tour-type { color: #9a5b10; font-weight: 650; }
  #tourDuration.needs-tour-type { border-color: #d49a50 !important; box-shadow: 0 0 0 3px rgba(212,154,80,.14) !important; }
</style>
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
        <p class="subtitle">Fill in your details. Your booking summary updates in real time.</p>
        <p class="account-note">Booking name will be taken from your logged-in account: <strong><?= htmlspecialchars($bookingAccountName) ?></strong>.</p>

        <div class="booking-stepper" aria-label="Tour booking progress">
          <div class="booking-step-indicator is-active" data-step-indicator="1" aria-current="step">
            <span>1</span><strong>Trip details</strong>
          </div>
          <div class="booking-step-line" aria-hidden="true"></div>
          <div class="booking-step-indicator" data-step-indicator="2">
            <span>2</span><strong>Guests & payment</strong>
          </div>
          <div class="booking-step-line" aria-hidden="true"></div>
          <div class="booking-step-indicator" data-step-indicator="3">
            <span>3</span><strong>Review & book</strong>
          </div>
        </div>

        <form id="tourBookingForm" class="booking-form" data-required-fields novalidate>
          <section class="booking-step-panel is-active" data-booking-step="1">
            <div class="step-section-heading">
              <b>1</b>
              <div><h2>Build your trip</h2><p>Select the service, destinations, schedule, and meeting point.</p></div>
            </div>
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
            <label data-required-label>
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

          <input type="hidden" id="selectedBoatId" name="boat_id">
          <input type="hidden" id="selectedGuideId" name="guide_id">

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
              <input type="text" id="tourDuration" name="tour_duration" placeholder="e.g. 2 Days 1 Night" readonly inputmode="none" virtualkeyboardpolicy="manual" required aria-label="Select booking duration dates" />
            </label>
          </div>
          <p class="helper-text" id="durationHint">For overnight booking, tap the Duration field to pick a date range.</p>

          <fieldset id="locationsWrapper" class="locations-box">
            <legend data-required-label>Locations to Visit (max 2)</legend>
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
              <input type="date" id="bookingDate" name="booking_date" readonly inputmode="none" virtualkeyboardpolicy="manual" required />
            </label>
          </div>

          <div class="field-row" id="bookingEndDateWrapper">
            <label data-required-label>
              End Date (Overnight)
              <input type="date" id="bookingEndDate" name="booking_end_date" />
            </label>
          </div>
          <p class="helper-text" id="resourceAvailabilityNote" aria-live="polite"></p>
          </section>

          <section class="booking-step-panel" data-booking-step="2" hidden>
            <div class="step-section-heading">
              <b>2</b>
              <div><h2>Guests and payment</h2><p>Confirm your party size, contact details, and payment preference.</p></div>
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
          </section>

          <section class="booking-step-panel" data-booking-step="3" hidden>
            <div class="step-section-heading">
              <b>3</b>
              <div><h2>Review and confirm</h2><p>Review your payment summary and acknowledge the booking policies.</p></div>
            </div>

          <div class="preview-block totals step-payment-summary">
            <h3><span class="preview-heading-icon" aria-hidden="true">₱</span> Payment summary</h3>
            <details class="fee-breakdown">
              <summary>View fee breakdown <span aria-hidden="true"></span></summary>
              <div class="fee-breakdown-content">
                <p><span>Environmental Fee</span><strong id="previewEcoFee">-</strong></p>
                <p><span>Total Discount</span><strong id="previewDiscountTotal">-</strong></p>
                <p><span>Entrance Fee</span><strong id="previewEntranceFee">-</strong></p>
                <p><span>Docking/Landing Fee</span><strong id="previewDockingFee">-</strong></p>
                <p><span>Total Boat Bill</span><strong id="previewBoatBill">-</strong></p>
                <small id="previewFeesNote">Other fees vary by selected locations and tour type.</small>
              </div>
            </details>
            <p><span>Service Subtotal</span><strong id="previewServiceTotal">-</strong></p>
            <p><span>Other Fees Subtotal</span><strong id="previewOtherFeesTotal">-</strong></p>
            <p class="grand-total-row"><span>Estimated total</span><strong id="previewEstimatedTotal">-</strong></p>
            <p><span>Payment Option</span><strong id="previewPaymentOption">20% Partial</strong></p>
            <p class="pay-now-row"><span>Amount to pay now</span><strong id="previewAmountPayable">-</strong></p>
            <small id="previewPricingNote">Select your booking type and details to view your estimate.</small>
          </div>

          <button type="button" class="other-fees-button" id="openOtherFeesModal" aria-haspopup="dialog" aria-controls="otherFeesModal" aria-expanded="false">
            <span aria-hidden="true">i</span>
            View other fees reference
          </button>

          <div class="other-fees-modal" id="otherFeesModal" role="dialog" aria-modal="true" aria-labelledby="otherFeesModalTitle" hidden>
            <button type="button" class="other-fees-modal-backdrop" data-close-other-fees aria-label="Close other fees reference"></button>
            <section class="other-fees-modal-panel" role="document">
              <header>
                <div><span class="other-fees-modal-icon" aria-hidden="true">₱</span><div><small>Fee guide</small><h2 id="otherFeesModalTitle">Other Fees Reference</h2></div></div>
                <button type="button" class="other-fees-modal-close" data-close-other-fees aria-label="Close">&times;</button>
              </header>
          <div class="other-fees-reference">
            <ul>
              <li><span>Environmental Fee (Foreigner / Local)</span><strong>₱<?= number_format($additionalFees['environmental_foreigner']['amount'], 0) ?> / ₱<?= number_format($additionalFees['environmental_local']['amount'], 0) ?> per person</strong></li>
              <li><span>Entrance Fee (Apuao Grande / Caringo)</span><strong>₱<?= number_format($additionalFees['entrance_apuao_grande']['amount'], 0) ?> / ₱<?= number_format($additionalFees['entrance_caringo']['amount'], 0) ?> per head</strong></li>
              <li><span>Entrance Fee (Canimog Day / Overnight)</span><strong>₱<?= number_format($additionalFees['entrance_canimog_day']['amount'], 0) ?> / ₱<?= number_format($additionalFees['entrance_canimog_overnight']['amount'], 0) ?> per head</strong></li>
              <li><span>Docking Fee (Apuao Pequeña / Malasugui / Canimog)</span><strong>₱<?= number_format($additionalFees['docking_apuao_pequena']['amount'], 0) ?> / ₱<?= number_format($additionalFees['docking_malasugui']['amount'], 0) ?> / ₱<?= number_format($additionalFees['docking_canimog']['amount'], 0) ?> per boat</strong></li>
            </ul>
          </div>
              <footer><button type="button" class="step-btn primary" data-close-other-fees>Done</button></footer>
            </section>
          </div>

          <div class="checkbox-box">
            <label data-required-label>
              <input type="checkbox" id="agreePrivacy" />
              I agree to the <a href="privacy-policy.php" target="_blank" rel="noopener">Privacy Policy</a>.
            </label>
            <label data-required-label>
              <input type="checkbox" id="agreeOtherFees" />
              I acknowledge that additional fees may apply based on selected destinations and activities.
            </label>
          </div>

          <button type="submit" id="payBookingBtn" class="submit-btn">Pay</button>
          </section>

          <div class="booking-step-actions">
            <p id="tourStepStatus" class="required-step-note">
              <span data-step-copy>Step 1 of 3</span>
              <span>·</span>
              <span class="required-mark" aria-hidden="true">*</span>
              <span>indicates a required field.</span>
            </p>
            <div>
              <button type="button" class="step-btn secondary" id="tourPreviousStep" hidden>Back</button>
              <button type="button" class="step-btn primary" id="tourNextStep">Continue</button>
            </div>
          </div>
        </form>
      </section>

      <div class="booking-sidebar">
      <section class="mobile-booking-selection" id="mobileBookingSelection" aria-label="Selected booking">
        <img id="mobileSelectionImage" class="is-placeholder" src="img/newlogo.png" alt="Select a tour service" />
        <div class="mobile-selection-copy">
          <span class="mobile-selection-eyebrow">Your selected service</span>
          <h2 id="mobileSelectionTitle">Choose what you want to book</h2>
          <p id="mobileSelectionMeta">Select a tour boat, guide, or package below.</p>
          <div class="mobile-selection-capsules" aria-label="Selected service details">
            <span><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7h16v10H4zM8 7V4h8v3M8 12h8"/></svg><b id="mobileSelectionType">Tour service</b></span>
            <span id="mobileSelectionScheduleCapsule" hidden><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7 3v3M17 3v3M4 9h16M5 5h14v15H5z"/></svg><b id="mobileSelectionSchedule"></b></span>
            <span id="mobileSelectionGuestsCapsule"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="9" cy="8" r="3"/><path d="M3 19c.5-4 2.5-6 6-6s5.5 2 6 6M16 6a3 3 0 0 1 0 6M17 13c2.4.5 3.7 2.5 4 6"/></svg><b id="mobileSelectionGuests">1 guest</b></span>
            <span id="mobileSelectionLocationsCapsule" hidden><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 21s7-5 7-12a7 7 0 1 0-14 0c0 7 7 12 7 12z"/><circle cx="12" cy="9" r="2"/></svg><b id="mobileSelectionLocations"></b></span>
          </div>
          <div class="mobile-selection-estimate">
            <span><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M9 8h4a2 2 0 0 1 0 4H9m0-7v14m0-7h5"/></svg>Estimated service</span>
            <strong id="mobileSelectionPrice">Not calculated yet</strong>
          </div>
        </div>
      </section>

      <aside class="booking-preview-card">
        <div class="preview-card-header">
          <div>
            <span class="preview-eyebrow">Live summary</span>
            <h2>Your booking</h2>
          </div>
          <span class="preview-live-badge"><i aria-hidden="true"></i> Updates automatically</span>
        </div>

        <div class="preview-block preview-main">
          <h3><span class="preview-heading-icon" aria-hidden="true">1</span> Trip overview</h3>
          <p><span>Booking Type</span><strong id="previewType">Not selected</strong></p>
          <p class="preview-optional-row"><span>Package</span><strong id="previewPackage">-</strong></p>
          <p class="preview-optional-row"><span>Preferred</span><strong id="previewPreferred">-</strong></p>
          <p class="preview-optional-row"><span>Add-on Service</span><strong id="previewAddon">-</strong></p>
          <p><span>Tour Type</span><strong id="previewTourType">-</strong></p>
          <p><span>Duration</span><strong id="previewDuration">-</strong></p>
          <p class="preview-optional-row"><span>Locations</span><strong id="previewLocations">-</strong></p>
          <p><span>Jump-Off Port</span><strong id="previewPort">-</strong></p>
          <p><span>Date</span><strong id="previewDate">-</strong></p>
          <p class="preview-optional-row"><span>Contact</span><strong id="previewContact">-</strong></p>
          <p><span>Guests</span><strong id="previewGuests">0</strong></p>
          <p class="preview-optional-row"><span>Required Boats</span><strong id="previewRequiredBoats">-</strong></p>
        </div>

      </aside>
      </div>
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
      'prefillDestinations' => $prefillDestinations,
      'prefillTourType' => $prefillTourType,
      'prefillTourDuration' => $prefillTourDuration,
      'prefillCheckin' => $prefillCheckin,
      'prefillCheckout' => $prefillCheckout,
      'prefillAdults' => $prefillAdults,
      'prefillChildren' => $prefillChildren,
      'prefillChildAges' => array_map('intval', $prefillChildAges),
      'returnUrl' => $backLink,
    ], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

    (function () {
      const MAX_BOAT_PAX = 8;
      const OTHER_FEES = <?= json_encode($bookingAdditionalFees, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

      const bookingForm = document.getElementById("tourBookingForm");
      const availabilityEndpoint = <?= json_encode($tourAvailabilityEndpoint, JSON_UNESCAPED_SLASHES) ?>;
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
      const resourceAvailabilityNote = document.getElementById("resourceAvailabilityNote");
      const bookingEndDateWrapper = document.getElementById("bookingEndDateWrapper");
      const contactNumber = document.getElementById("contactNumber");
      const numAdults = document.getElementById("numAdults");
      const numChildren = document.getElementById("numChildren");
      const ecoCategory = document.getElementById("ecoCategory");
      const paymentOption = document.getElementById("paymentOption");
      const agreePrivacy = document.getElementById("agreePrivacy");
      const agreeOtherFees = document.getElementById("agreeOtherFees");
      const payBookingBtn = document.getElementById("payBookingBtn");
      const openOtherFeesButton = document.getElementById("openOtherFeesModal");
      const otherFeesModal = document.getElementById("otherFeesModal");
      let otherFeesReturnFocus = null;
      let isOpeningPayment = false;

      const closeOtherFeesModal = () => {
        if (!otherFeesModal || otherFeesModal.hidden) return;
        otherFeesModal.hidden = true;
        openOtherFeesButton?.setAttribute("aria-expanded", "false");
        document.body.classList.remove("other-fees-modal-open");
        otherFeesReturnFocus?.focus();
      };

      const showOtherFeesModal = () => {
        if (!otherFeesModal) return;
        otherFeesReturnFocus = document.activeElement;
        otherFeesModal.hidden = false;
        openOtherFeesButton?.setAttribute("aria-expanded", "true");
        document.body.classList.add("other-fees-modal-open");
        otherFeesModal.querySelector(".other-fees-modal-close")?.focus();
      };

      openOtherFeesButton?.addEventListener("click", showOtherFeesModal);
      otherFeesModal?.querySelectorAll("[data-close-other-fees]").forEach((button) => {
        button.addEventListener("click", closeOtherFeesModal);
      });
      document.addEventListener("keydown", (event) => {
        if (!otherFeesModal || otherFeesModal.hidden) return;
        if (event.key === "Escape") {
          event.preventDefault();
          closeOtherFeesModal();
          return;
        }
        if (event.key !== "Tab") return;
        const focusable = [...otherFeesModal.querySelectorAll("button:not([disabled])")];
        if (!focusable.length) return;
        const first = focusable[0];
        const last = focusable[focusable.length - 1];
        if (event.shiftKey && document.activeElement === first) {
          event.preventDefault();
          last.focus();
        } else if (!event.shiftKey && document.activeElement === last) {
          event.preventDefault();
          first.focus();
        }
      });

      const setOpeningPaymentState = (opening) => {
        isOpeningPayment = opening;
        payBookingBtn.disabled = opening;
        payBookingBtn.classList.toggle("is-loading", opening);
        if (opening) {
          payBookingBtn.setAttribute("aria-busy", "true");
          payBookingBtn.innerHTML = '<span class="btn-spinner" aria-hidden="true"></span><span>Opening secure payment...</span>';
        } else {
          payBookingBtn.removeAttribute("aria-busy");
          updatePreview();
        }
      };

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
      const mobileBookingSelection = document.getElementById("mobileBookingSelection");
      const mobileSelectionImage = document.getElementById("mobileSelectionImage");
      const mobileSelectionTitle = document.getElementById("mobileSelectionTitle");
      const mobileSelectionMeta = document.getElementById("mobileSelectionMeta");
      const mobileSelectionPrice = document.getElementById("mobileSelectionPrice");
      const mobileSelectionType = document.getElementById("mobileSelectionType");
      const mobileSelectionSchedule = document.getElementById("mobileSelectionSchedule");
      const mobileSelectionScheduleCapsule = document.getElementById("mobileSelectionScheduleCapsule");
      const mobileSelectionGuests = document.getElementById("mobileSelectionGuests");
      const mobileSelectionLocations = document.getElementById("mobileSelectionLocations");
      const mobileSelectionLocationsCapsule = document.getElementById("mobileSelectionLocationsCapsule");

      const boats = Array.isArray(BOOKING_DATA.boats) ? BOOKING_DATA.boats : [];
      const guides = Array.isArray(BOOKING_DATA.guides) ? BOOKING_DATA.guides : [];
      const servicePrices = BOOKING_DATA.servicePrices || {};
      const prefillPreferred = String(BOOKING_DATA.prefillPreferred || "");
      const prefillLocations = Array.isArray(BOOKING_DATA.prefillDestinations) ? BOOKING_DATA.prefillDestinations : [];
      const prefillTourType = String(BOOKING_DATA.prefillTourType || "");
      const prefillTourDuration = String(BOOKING_DATA.prefillTourDuration || "");
      const prefillCheckin = String(BOOKING_DATA.prefillCheckin || "");
      const prefillCheckout = String(BOOKING_DATA.prefillCheckout || "");
      const prefillAdults = Math.max(0, Number(BOOKING_DATA.prefillAdults || 0));
      const prefillChildren = Math.max(0, Number(BOOKING_DATA.prefillChildren || 0));
      const prefillChildAges = Array.isArray(BOOKING_DATA.prefillChildAges) ? BOOKING_DATA.prefillChildAges : [];
      let prefillPreferredApplied = false;
      let overnightRangePicker = null;
      let sameDayPicker = null;
      let rangePickerOpenTimer = 0;
      let startDatePickerOpenTimer = 0;
      let unavailableResourceDates = new Set();
      let availabilityRequestController = null;
      let guestAvailabilityTimer = null;
      const errorIconSvg = '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="7.25" x2="12" y2="13.25"></line><circle cx="12" cy="16.5" r="1"></circle></svg>';
      const fieldOrder = [
        "booking_type",
        "package_name",
        "selected_locations",
        "tour_type",
        "jump_off_port",
        "booking_date",
        "booking_end_date",
        "tour_duration",
        "contact_number",
        "num_adults",
        "num_children",
        "eco_category",
        "payment_option",
        "agree_privacy",
        "agree_other_fees"
      ];
      const fieldTargets = {
        booking_type: bookingType,
        package_name: packageSelect,
        selected_locations: locationsWrapper,
        tour_type: tourType,
        jump_off_port: jumpOffPort,
        booking_date: bookingDate,
        booking_end_date: bookingEndDate,
        tour_duration: tourDuration,
        contact_number: contactNumber,
        num_adults: numAdults,
        num_children: numChildren,
        eco_category: ecoCategory,
        payment_option: paymentOption,
        agree_privacy: agreePrivacy,
        agree_other_fees: agreeOtherFees
      };
      const formErrorMap = new Map();

      const formatMoney = (value) =>
        `₱${Number(value || 0).toLocaleString("en-PH", { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;

      const showToast = (message, variant = "success") => {
        const existing = document.querySelector(".booking-toast");
        if (existing) existing.remove();
        const toast = document.createElement("div");
        toast.className = `booking-toast booking-toast-${variant}`;
        toast.textContent = message;
        document.body.appendChild(toast);
        requestAnimationFrame(() => toast.classList.add("show"));
        setTimeout(() => {
          toast.classList.remove("show");
          setTimeout(() => toast.remove(), 220);
        }, 2600);
      };

      const getErrorHost = (fieldKey, target) => {
        if (fieldKey === "selected_locations") return locationsWrapper;
        if (fieldKey === "booking_end_date") return bookingEndDateWrapper;
        if (fieldKey === "agree_privacy" || fieldKey === "agree_other_fees") {
          return target?.closest("label") || target?.closest(".checkbox-box") || bookingForm;
        }
        return target?.closest("label") || target?.closest(".field-row") || bookingForm;
      };

      const ensureErrorNode = (fieldKey, target) => {
        const host = getErrorHost(fieldKey, target);
        if (!host) return null;
        let node = host.querySelector(`.field-error[data-error-key="${fieldKey}"]`);
        if (!node) {
          node = document.createElement("p");
          node.className = "field-error";
          node.dataset.errorKey = fieldKey;
          node.setAttribute("aria-live", "polite");
          node.innerHTML = `${errorIconSvg}<span></span>`;
          host.appendChild(node);
        }
        return node;
      };

      const clearFieldError = (fieldKey) => {
        const target = fieldTargets[fieldKey];
        const host = getErrorHost(fieldKey, target);
        const node = host?.querySelector(`.field-error[data-error-key="${fieldKey}"]`);
        if (node) {
          node.classList.remove("is-visible");
          node.querySelector("span").textContent = "";
        }

        if (fieldKey === "selected_locations" || fieldKey === "booking_end_date") {
          target?.classList.remove("field-error-outline");
        } else if (fieldKey === "agree_privacy" || fieldKey === "agree_other_fees") {
          if (target instanceof HTMLElement) {
            target.classList.remove("input-error");
            target.removeAttribute("aria-invalid");
          }
        } else if (target instanceof HTMLElement) {
          target.classList.remove("input-error");
          target.removeAttribute("aria-invalid");
        }

        formErrorMap.delete(fieldKey);
      };

      const setFieldError = (fieldKey, message) => {
        const target = fieldTargets[fieldKey];
        const node = ensureErrorNode(fieldKey, target);
        if (!node) return;

        const normalizedMessage = typeof message === "string" && message.trim() !== ""
          ? message
          : "Please review this field.";
        node.querySelector("span").textContent = normalizedMessage;
        node.classList.add("is-visible");

        if (fieldKey === "selected_locations" || fieldKey === "booking_end_date") {
          target?.classList.add("field-error-outline");
        } else if (fieldKey === "agree_privacy" || fieldKey === "agree_other_fees") {
          if (target instanceof HTMLElement) {
            target.classList.add("input-error");
            target.setAttribute("aria-invalid", "true");
          }
        } else if (target instanceof HTMLElement) {
          target.classList.add("input-error");
          target.setAttribute("aria-invalid", "true");
        }

        formErrorMap.set(fieldKey, normalizedMessage);
      };

      const clearAllErrors = () => {
        fieldOrder.forEach((fieldKey) => clearFieldError(fieldKey));
      };

      const revalidateFieldErrors = (fieldKeys = []) => {
        const keysToCheck = fieldKeys.filter((fieldKey) => formErrorMap.has(fieldKey));
        if (!keysToCheck.length) return;
        const errors = validateForm();
        keysToCheck.forEach((fieldKey) => {
          if (errors[fieldKey]) {
            setFieldError(fieldKey, errors[fieldKey]);
          } else {
            clearFieldError(fieldKey);
          }
        });
      };

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

      const normalizeLocationToken = (value) =>
        String(value || "")
          .toLowerCase()
          .replace(/island/g, "")
          .replace(/[^a-z0-9]/g, "");

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

      const parseDurationDateRange = (value) => {
        const match = String(value || "").trim().match(/^(\d{4}-\d{2}-\d{2})\s+to\s+(\d{4}-\d{2}-\d{2})$/i);
        if (!match) return null;
        const start = toDateOnly(match[1]);
        const end = toDateOnly(match[2]);
        if (!start || !end || end <= start) return null;
        return { start: match[1], end: match[2] };
      };

      const placeRangeCalendarBelow = () => {
        const calendar = overnightRangePicker?.calendarContainer;
        if (!calendar || !calendar.classList.contains("open")) return;

        const viewportWidth = window.innerWidth || document.documentElement.clientWidth || 0;
        const viewportLeft = window.scrollX;
        const edgePadding = 10;
        const mobileViewport = window.matchMedia("(max-width: 768px)").matches;
        const maxAllowedWidth = Math.max(240, viewportWidth - edgePadding * 2);

        calendar.style.maxWidth = `${maxAllowedWidth}px`;

        const inputRect = tourDuration.getBoundingClientRect();
        const renderedCalendarWidth = calendar.getBoundingClientRect().width || 0;
        const calendarWidth = mobileViewport ? renderedCalendarWidth : Math.max(
          renderedCalendarWidth,
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

      const setBookingStartDateValue = (value) => {
        const normalizedValue = String(value || "");
        if (sameDayPicker) {
          if (normalizedValue) sameDayPicker.setDate(normalizedValue, false);
          else sameDayPicker.clear(false);
        }
        bookingDate.value = normalizedValue;
      };

      const showTourTypeDateRequirement = () => {
        durationHint.style.display = "block";
        durationHint.textContent = "Select a tour type first to open the date calendar.";
        durationHint.classList.add("needs-tour-type");
        tourDuration.classList.add("needs-tour-type");
        tourType.focus();
      };

      const applyOvernightRangeSelection = (selectedDates, instance = overnightRangePicker) => {
        if (tourType.value !== "overnight" || !instance) return;

        if (!Array.isArray(selectedDates) || selectedDates.length === 0) {
          setBookingStartDateValue("");
          bookingEndDate.value = "";
          tourDuration.value = "";
          updateOvernightDateRangeState();
          updatePreview();
          return;
        }

        const selectedStart = instance.formatDate(selectedDates[0], "Y-m-d");
        setBookingStartDateValue(selectedStart);
        bookingEndDate.value = "";

        if (selectedDates.length < 2) {
          // Keep Flatpickr's first selection intact so the next click can
          // complete the range instead of starting over.
          updateOvernightDateRangeState();
          updatePreview();
          revalidateFieldErrors(["booking_date", "booking_end_date", "tour_duration"]);
          return;
        }

        const selectedEnd = instance.formatDate(selectedDates[1], "Y-m-d");
        if (selectedEnd <= selectedStart) {
          setBookingStartDateValue("");
          bookingEndDate.value = "";
          tourDuration.value = "";
          instance.clear(false);
          showToast("End date must be after the start date for an overnight booking.", "error");
          updatePreview();
          return;
        }

        if (rangeHasUnavailableDate(selectedStart, selectedEnd)) {
          setBookingStartDateValue("");
          bookingEndDate.value = "";
          tourDuration.value = "";
          instance.clear(false);
          showToast("That date range includes an unavailable date.", "error");
          updatePreview();
          return;
        }

        bookingEndDate.value = selectedEnd;
        tourDuration.value = `${selectedStart} to ${selectedEnd}`;
        updateOvernightDateRangeState();
        syncPackageDerivedFields();
        updatePreview();
        revalidateFieldErrors(["booking_date", "booking_end_date", "tour_duration"]);
      };

      const dismissMobilePickerKeyboard = (pickerInput = tourDuration) => {
        if (!window.matchMedia("(max-width: 768px)").matches) return;
        pickerInput.readOnly = true;
        pickerInput.setAttribute("readonly", "readonly");
        pickerInput.setAttribute("inputmode", "none");
        pickerInput.setAttribute("virtualkeyboardpolicy", "manual");

        [0, 60, 180, 360].forEach((delay) => {
          window.setTimeout(() => {
            const activeElement = document.activeElement;
            if (activeElement instanceof HTMLElement && activeElement !== document.body) {
              activeElement.blur();
            }
            if (navigator.virtualKeyboard && typeof navigator.virtualKeyboard.hide === "function") {
              navigator.virtualKeyboard.hide();
            }
          }, delay);
        });
      };

      const initOvernightRangePicker = () => {
        if (typeof flatpickr !== "function" || overnightRangePicker) return;
        overnightRangePicker = flatpickr(tourDuration, {
          mode: "range",
          showMonths: window.matchMedia("(max-width: 768px)").matches ? 1 : 2,
          clickOpens: false,
          allowInput: false,
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
          disable: [(date) => unavailableResourceDates.has(flatpickr.formatDate(date, "Y-m-d"))],
          onReady: (_, __, instance) => {
            instance.calendarContainer.classList.add("tour-range-calendar");
            tourDuration.readOnly = true;
            tourDuration.setAttribute("inputmode", "none");
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
          onChange: (selectedDates, _, instance) => {
            applyOvernightRangeSelection(selectedDates, instance);
            if (selectedDates.length === 2) dismissMobilePickerKeyboard();
          },
          onValueUpdate: (selectedDates, _, instance) => {
            if (selectedDates.length === 2) {
              applyOvernightRangeSelection(selectedDates, instance);
            }
          },
          onClose: (selectedDates, _, instance) => {
            if (selectedDates.length === 2) {
              applyOvernightRangeSelection(selectedDates, instance);
            }
            tourDuration.readOnly = true;
            tourDuration.setAttribute("inputmode", "none");
            dismissMobilePickerKeyboard();
          }
        });

        window.addEventListener("resize", () => {
          const months = window.matchMedia("(max-width: 768px)").matches ? 1 : 2;
          if (overnightRangePicker && overnightRangePicker.config.showMonths !== months) {
            overnightRangePicker.set("showMonths", months);
          }
          placeRangeCalendarBelow();
        });
        window.addEventListener("scroll", placeRangeCalendarBelow, true);
      };

      const openOvernightRangePicker = () => {
        if (!tourType.value) {
          overnightRangePicker?.close();
          showTourTypeDateRequirement();
          return;
        }
        if (!overnightRangePicker) {
          if (tourType.value === "overnight") {
            showToast("Use the Start Date and End Date fields to choose the overnight range.", "error");
            bookingDate.focus();
          }
          return;
        }
        if (tourType.value !== "overnight") {
          overnightRangePicker.close();
          durationHint.style.display = "block";
          durationHint.textContent = "Same Day uses the Start Date calendar below.";
          return;
        }
        durationHint.classList.remove("needs-tour-type");
        tourDuration.classList.remove("needs-tour-type");
        tourDuration.readOnly = true;
        tourDuration.setAttribute("inputmode", "none");
        tourDuration.blur();

        window.clearTimeout(rangePickerOpenTimer);
        if (window.matchMedia("(max-width: 768px)").matches) {
          const fieldTop = tourDuration.getBoundingClientRect().top;
          const targetTop = 112;
          window.scrollBy({ top: fieldTop - targetTop, behavior: "smooth" });
          rangePickerOpenTimer = window.setTimeout(() => {
            overnightRangePicker.open();
            requestAnimationFrame(placeRangeCalendarBelow);
          }, 240);
          return;
        }

        overnightRangePicker.open();
      };

      const openBookingStartDatePicker = () => {
        if (!tourType.value) {
          showTourTypeDateRequirement();
          return;
        }
        if (tourType.value === "overnight") {
          openOvernightRangePicker();
          return;
        }
        if (!sameDayPicker) return;

        bookingDate.readOnly = true;
        bookingDate.setAttribute("readonly", "readonly");
        bookingDate.setAttribute("inputmode", "none");
        bookingDate.setAttribute("virtualkeyboardpolicy", "manual");
        bookingDate.blur();

        window.clearTimeout(startDatePickerOpenTimer);
        if (window.matchMedia("(max-width: 768px)").matches) {
          const fieldTop = bookingDate.getBoundingClientRect().top;
          window.scrollBy({ top: fieldTop - 112, behavior: "smooth" });
          startDatePickerOpenTimer = window.setTimeout(() => sameDayPicker.open(), 240);
          return;
        }

        sameDayPicker.open();
      };

      const getRateKey = () => (tourType.value === "overnight" ? "overnight" : "day");
      const getSelectedLocations = () => locationInputs.filter((item) => item.checked).map((item) => item.value);
      const getAdultCount = () => Math.max(0, Number(numAdults.value || 0));
      const getChildCount = () => Math.max(0, Number(numChildren.value || 0));
      const getFreeYoungChildCount = () => prefillChildAges
        .slice(0, getChildCount())
        .filter(age => Number(age) >= 0 && Number(age) <= 7).length;
      const getTotalGuests = () => getAdultCount() + getChildCount();

      const getAddOnType = () => {
        if (!includeAddonService.checked) return "";
        if (bookingType.value === "boat") return "tourguide";
        if (bookingType.value === "tourguide") return "boat";
        return "";
      };

      const getServiceRate = (type, rateKey) => Number(servicePrices?.[type]?.[rateKey] || 0);

      const populatePreferredOptions = (type) => {

    preferredSelect.innerHTML =
      '<option value="">No specific preference</option>';

    const list =
      type === "boat"
        ? boats
        : type === "tourguide"
        ? guides
        : [];

    list.forEach((item) => {

        const option = document.createElement("option");

        option.value = item.name;
        option.textContent = item.name;

        // IMPORTANT
        option.dataset.id = item.id;
        option.dataset.type = type;

        preferredSelect.appendChild(option);
    });

    if (!prefillPreferredApplied && prefillPreferred !== "") {

        const matched = [...preferredSelect.options]
            .find((item) => item.value === prefillPreferred);

        if (matched) {

            preferredSelect.value = matched.value;

            // ALSO APPLY IDS
            if (matched.dataset.type === "boat") {
                selectedBoatId.value = matched.dataset.id || "";
                selectedGuideId.value = "";
            }

            if (matched.dataset.type === "tourguide") {
                selectedGuideId.value = matched.dataset.id || "";
                selectedBoatId.value = "";
            }

            prefillPreferredApplied = true;
        }
    }
};

const selectedBoatId = document.getElementById("selectedBoatId");
const selectedGuideId = document.getElementById("selectedGuideId");

preferredSelect.addEventListener("change", function () {

    selectedBoatId.value = "";
    selectedGuideId.value = "";

    const selectedOption =
        this.options[this.selectedIndex];

    if (!selectedOption) return;

    const selectedId =
        selectedOption.dataset.id || "";

    const selectedType =
        selectedOption.dataset.type || "";

    if (selectedType === "boat") {
        selectedBoatId.value = selectedId;
    }

    if (selectedType === "tourguide") {
        selectedGuideId.value = selectedId;
    }
    refreshResourceAvailability();
});

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
            tourDuration.value = `${bookingDate.value} to ${bookingEndDate.value}`;
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
        const hasTourType = tourType.value === "same-day" || needsRange;
        const hasRangePicker = Boolean(overnightRangePicker);
        bookingEndDateWrapper.style.display = needsRange && !hasRangePicker ? "grid" : "none";
        bookingEndDate.required = needsRange;
        bookingDate.readOnly = true;
        bookingDate.setAttribute("readonly", "readonly");
        bookingDate.setAttribute("inputmode", "none");
        bookingDate.setAttribute("virtualkeyboardpolicy", "manual");
        bookingDate.setAttribute("aria-disabled", hasTourType ? "false" : "true");
        bookingDate.classList.toggle("date-locked", !hasTourType);
        bookingDate.classList.toggle("derived-start-date", needsRange && hasRangePicker);
        if (sameDayPicker && sameDayPicker.config.clickOpens !== false) {
          sameDayPicker.set("clickOpens", false);
        }
        if (overnightRangePicker && overnightRangePicker.config.clickOpens !== false) {
          overnightRangePicker.set("clickOpens", false);
        }
        if (durationHint) {
          durationHint.style.display = needsRange ? "block" : "none";
          durationHint.textContent = hasRangePicker
            ? "Choose the booking range in Duration. Start Date fills automatically from the first selected date."
            : "Choose a Start Date and End Date to set the overnight range.";
          durationHint.classList.remove("needs-tour-type");
        }
        tourDuration.classList.remove("needs-tour-type");
        tourDuration.classList.toggle("range-ready", needsRange);
        if (!needsRange && overnightRangePicker) {
          overnightRangePicker.close();
        }

        if (bookingDate.value) {
          const startDate = toDateOnly(bookingDate.value);
          if (startDate) {
            if (needsRange) {
              startDate.setDate(startDate.getDate() + 1);
              bookingEndDate.min = `${startDate.getFullYear()}-${String(startDate.getMonth() + 1).padStart(2, "0")}-${String(startDate.getDate()).padStart(2, "0")}`;
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
          ? `${bookingDate.value} to ${bookingEndDate.value}`
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
          tourDuration.readOnly = true;
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
        const freeYoungChildren = getFreeYoungChildCount();
        const entrancePayingGuests = Math.max(0, totalGuests - freeYoungChildren);
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
          entranceFee += getEntranceFeePerHead(location, rateKey) * entrancePayingGuests;
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
          freeYoungChildren,
          entrancePayingGuests,
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
          tourDuration.readOnly = true;
          locationInputs.forEach((item) => { item.checked = false; });
        }

        updateOvernightDateRangeState();
        applyTourTypeDurationRules();
        syncRangePickerFromFields();
      };

      const updateMobileSelectionCard = (pricing, preferred) => {
        if (!mobileBookingSelection) return;
        const placeholderImages = {
          boat: "img/boathome.png",
          tourguide: "img/tourguidehome.png",
          package: "img/packageshome.png",
          default: "img/newlogo.png"
        };
        const serviceLabel = getTypeLabel(pricing.type);
        const selectedPackageOption = packageSelect.selectedOptions[0];
        const selectedService = pricing.type === "boat"
          ? boats.find(item => item.name === preferred)
          : pricing.type === "tourguide"
            ? guides.find(item => item.name === preferred)
            : null;
        const hasSpecificSelection = pricing.type === "package"
          ? Boolean(pricing.packageTitle)
          : Boolean(selectedService);
        const title = pricing.packageTitle
          || selectedService?.name
          || (serviceLabel !== "Not selected" ? serviceLabel : "Choose what you want to book");
        const placeholderImage = placeholderImages[pricing.type] || placeholderImages.default;
        const selectedImage = pricing.type === "package"
          ? selectedPackageOption?.dataset.image
          : selectedService?.image;
        const image = hasSpecificSelection && selectedImage
          ? String(selectedImage)
          : placeholderImage;
        const detailParts = [
          serviceLabel !== "Not selected" ? serviceLabel : "",
          tourType.value === "same-day" ? "Same Day" : tourType.value === "overnight" ? "Overnight" : "",
          tourDuration.value.trim()
        ].filter(Boolean);

        mobileBookingSelection.classList.toggle("is-empty", !hasSpecificSelection);
        mobileSelectionTitle.textContent = title || "Choose what you want to book";
        mobileSelectionMeta.textContent = detailParts.length
          ? detailParts.join(" · ")
          : "Select a tour boat, guide, or package below.";
        mobileSelectionType.textContent = serviceLabel !== "Not selected" ? serviceLabel : "Tour service";
        const scheduleText = [
          tourType.value === "same-day" ? "Same Day" : tourType.value === "overnight" ? "Overnight" : "",
          tourDuration.value.trim()
        ].filter(Boolean).join(" · ");
        mobileSelectionSchedule.textContent = scheduleText;
        mobileSelectionScheduleCapsule.hidden = scheduleText === "";
        const guestCount = Math.max(0, Number(pricing.totalGuests || 0));
        mobileSelectionGuests.textContent = `${guestCount} ${guestCount === 1 ? "guest" : "guests"}`;
        const locationCount = Array.isArray(pricing.selectedLocations) ? pricing.selectedLocations.length : 0;
        mobileSelectionLocations.textContent = `${locationCount} ${locationCount === 1 ? "destination" : "destinations"}`;
        mobileSelectionLocationsCapsule.hidden = locationCount === 0;
        mobileSelectionPrice.textContent = pricing.serviceTotal > 0
          ? formatMoney(pricing.serviceTotal)
          : "Not calculated yet";
        mobileSelectionImage.classList.toggle("is-placeholder", !hasSpecificSelection);
        mobileSelectionImage.src = image;
        mobileSelectionImage.alt = hasSpecificSelection ? `${title} booking selection` : "Tour booking selection";
        mobileSelectionImage.onerror = () => {
          mobileSelectionImage.onerror = null;
          mobileSelectionImage.classList.add("is-placeholder");
          mobileSelectionImage.src = placeholderImage;
        };
      };

      const updatePreview = () => {
        updateAddOnUI();
        const pricing = calculatePricing();
        const preferred = preferredSelect.value || "-";

        updateMobileSelectionCard(pricing, preferred);

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
        document.querySelectorAll(".preview-main .preview-optional-row").forEach((row) => {
          const value = row.querySelector("strong")?.textContent.trim() || "";
          row.hidden = value === "" || value === "-" || value === "Not required";
        });

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
        if (payBookingBtn && !isOpeningPayment) {
          payBookingBtn.textContent = pricing.payableNow > 0 ? `Pay ${formatMoney(pricing.payableNow)}` : "Pay";
        }

        if (pricing.usesBoat && pricing.totalGuests > MAX_BOAT_PAX) {
          previewFeesNote.textContent = `Passenger count exceeds ${MAX_BOAT_PAX}. Estimated docking and boat rates use ${pricing.requiredBoats} boats.`;
          previewPricingNote.textContent = `Boat capacity is ${MAX_BOAT_PAX} passengers per boat. This booking needs ${pricing.requiredBoats} boats for ${pricing.totalGuests} passengers.`;
        } else if (pricing.freeYoungChildren > 0) {
          previewFeesNote.textContent = `${pricing.freeYoungChildren} child${pricing.freeYoungChildren === 1 ? "" : "ren"} age 7 or below exempted from entrance fees.`;
          previewPricingNote.textContent = "Hotel stays remain priced per room/night; boat capacity and other service fees still use the full guest count.";
        } else if (pricing.discountTotal > 0) {
          previewFeesNote.textContent = `Discount applied: -${formatMoney(pricing.discountTotal)} on environmental fee.`;
          previewPricingNote.textContent = "Environmental, entrance, and docking fees are estimates based on your selections.";
        } else {
          previewFeesNote.textContent = "Other fees vary by selected locations and tour type.";
          previewPricingNote.textContent = "Environmental, entrance, and docking fees are estimates based on your selections.";
        }
      };

      const applySearchPrefill = () => {
        const type = bookingType.value;
        const isServiceType = type === "boat" || type === "tourguide";
        const durationRange = parseDurationDateRange(prefillTourDuration);
        const prefillStartDate = prefillCheckin || durationRange?.start || "";
        const prefillEndDate = prefillCheckout || durationRange?.end || "";

        if (isServiceType && prefillLocations.length) {
          const wantedTokens = prefillLocations
            .map((item) => normalizeLocationToken(item))
            .filter(Boolean)
            .slice(0, 2);

          locationInputs.forEach((input) => { input.checked = false; });
          wantedTokens.forEach((token) => {
            const matchedInput = locationInputs.find((input) => {
              if (input.checked) return false;
              const candidateToken = normalizeLocationToken(input.value);
              return candidateToken === token || candidateToken.includes(token) || token.includes(candidateToken);
            });
            if (matchedInput) {
              matchedInput.checked = true;
            }
          });
        }

        if (prefillTourType) {
          tourType.value = prefillTourType;
        } else if (durationRange) {
          tourType.value = "overnight";
        }
        if (prefillAdults > 0) {
          numAdults.value = String(prefillAdults);
        }
        if (prefillChildren >= 0) {
          numChildren.value = String(prefillChildren);
        }
        if (prefillStartDate) {
          bookingDate.value = prefillStartDate;
        }
        if ((tourType.value === "overnight" || prefillTourType === "overnight") && prefillEndDate) {
          bookingEndDate.value = prefillEndDate;
        } else if (tourType.value === "same-day" && prefillStartDate) {
          bookingEndDate.value = prefillStartDate;
        }

        updateOvernightDateRangeState();
        applyTourTypeDurationRules();
        if (prefillTourDuration && !durationRange && (!tourDuration.value || tourDuration.value.trim() === "")) {
          tourDuration.value = prefillTourDuration;
        }
        syncRangePickerFromFields();
      };

      const validateForm = () => {
  const pricing = calculatePricing();

  const payload = {
    booking_type: bookingType.value,
    package_name: packageSelect.value,
    selected_locations: getSelectedLocations(),
    tour_type: tourType.value,
    jump_off_port: jumpOffPort.value,
    booking_date: bookingDate.value,
    booking_end_date: bookingEndDate.value,
    tour_duration: tourDuration.value.trim(),
    contact_number: contactNumber.value.trim(),
    num_adults: getAdultCount(),
    num_children: getChildCount(),
    eco_category: ecoCategory.value,
    payment_option: paymentOption.value,
    agree_privacy: agreePrivacy.checked,
    agree_other_fees: agreeOtherFees.checked,

    grandTotal: pricing.grandTotal,
    remainingBalance: pricing.grandTotal - pricing.payableNow,
    paymentAmount: pricing.payableNow,
  };

  const validator = window.BookingValidationSchemas?.validateTourBooking;

  const result = typeof validator === "function"
    ? validator(payload)
    : { success: true, errors: {} };

  return result.success ? {} : (result.errors || {});
};
      const renderValidationErrors = (errors) => {
        clearAllErrors();
        fieldOrder.forEach((fieldKey) => {
          if (errors[fieldKey]) {
            setFieldError(fieldKey, errors[fieldKey]);
          }
        });
      };

      const focusFirstInvalidField = (errors) => {
        const targetKey = fieldOrder.find((fieldKey) => !!errors[fieldKey]);
        if (!targetKey) return;
        const target = fieldTargets[targetKey];
        if (target instanceof HTMLElement) {
          target.focus({ preventScroll: false });
        }
      };

      const selectedResource = () => {
        if (bookingType.value === "package") {
          const option = packageSelect.selectedOptions[0];
          const packageName = String(option?.value || "").trim();
          const operatorId = Number(option?.dataset.operatorId || 0);
          const packageId = Number(option?.dataset.packageId || 0);
          return packageName && packageId > 0 ? { type: "package", packageName, packageId, operatorId } : null;
        }
        const option = preferredSelect.selectedOptions[0];
        const type = String(option?.dataset.type || "");
        const id = Number(option?.dataset.id || 0);
        return id > 0 && (type === "boat" || type === "tourguide") ? { type, id } : null;
      };

      const dateIsUnavailable = (value) => Boolean(value && unavailableResourceDates.has(value));

      const rangeHasUnavailableDate = (startValue, endValue) => {
        const start = toDateOnly(startValue);
        const end = toDateOnly(endValue || startValue);
        if (!start || !end) return false;
        for (const date = new Date(start); date <= end; date.setDate(date.getDate() + 1)) {
          const key = `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, "0")}-${String(date.getDate()).padStart(2, "0")}`;
          if (unavailableResourceDates.has(key)) return true;
        }
        return false;
      };

      const syncAvailabilityCalendars = () => {
        if (typeof flatpickr !== "function") return;
        const disabled = [(date) => unavailableResourceDates.has(flatpickr.formatDate(date, "Y-m-d"))];
        sameDayPicker?.set("disable", disabled);
        overnightRangePicker?.set("disable", disabled);
      };

      const refreshResourceAvailability = async () => {
        const previousStartDate = bookingDate.value;
        const previousEndDate = bookingEndDate.value;
        availabilityRequestController?.abort();
        availabilityRequestController = new AbortController();
        unavailableResourceDates = new Set();
        resourceAvailabilityNote.textContent = "";
        syncAvailabilityCalendars();
        const resource = selectedResource();
        if (!resource) return;

        resourceAvailabilityNote.textContent = "Checking booked dates...";
        const today = new Date();
        const limit = new Date(today.getFullYear() + 2, today.getMonth(), today.getDate());
        const formatLocalDate = (date) => `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, "0")}-${String(date.getDate()).padStart(2, "0")}`;
        try {
          const params = new URLSearchParams({ type: resource.type, from: formatLocalDate(today), to: formatLocalDate(limit) });
          if (resource.type === "package") {
            params.set("package_id", String(resource.packageId));
            params.set("package_name", resource.packageName);
            if (resource.operatorId > 0) params.set("operator_id", String(resource.operatorId));
            params.set("guests", String(Math.max(1, getAdultCount() + getChildCount())));
          }
          else params.set("resource_id", String(resource.id));
          const response = await fetch(`${availabilityEndpoint}?${params}`, { headers: { "Accept": "application/json" }, signal: availabilityRequestController.signal });
          const result = await response.json();
          if (!response.ok || !result.success) throw new Error(result.message || "Availability could not be loaded.");
          unavailableResourceDates = new Set(result.unavailable_dates || []);
          syncAvailabilityCalendars();
          resourceAvailabilityNote.textContent = unavailableResourceDates.size ? "Booked dates are disabled in the calendar." : "No booked dates for this selection.";
          if (rangeHasUnavailableDate(bookingDate.value, tourType.value === "overnight" ? bookingEndDate.value : bookingDate.value)) {
            bookingDate.value = "";
            bookingEndDate.value = "";
            tourDuration.value = "";
            sameDayPicker?.clear(false);
            overnightRangePicker?.clear(false);
            showToast("This selection is unavailable for the chosen date.", "error");
            updatePreview();
          } else if (previousStartDate) {
            bookingDate.value = previousStartDate;
            bookingEndDate.value = tourType.value === "overnight" ? previousEndDate : "";
            applyTourTypeDurationRules();
            syncRangePickerFromFields();
            updatePreview();
          }
        } catch (error) {
          if (error.name === "AbortError") return;
          resourceAvailabilityNote.textContent = "Availability could not be loaded. Please try again.";
        }
      };

      const initSameDayPicker = () => {
        if (typeof flatpickr !== "function" || sameDayPicker) return;
        sameDayPicker = flatpickr(bookingDate, {
          clickOpens: false,
          allowInput: false,
          minDate: bookingDate.min || "today", dateFormat: "Y-m-d", disableMobile: true,
          monthSelectorType: "static", nextArrow: "&#8250;", prevArrow: "&#8249;",
          disable: [(date) => unavailableResourceDates.has(flatpickr.formatDate(date, "Y-m-d"))],
          onReady: (_, __, instance) => {
            instance.calendarContainer.classList.add("tour-single-calendar");
            bookingDate.readOnly = true;
            bookingDate.setAttribute("inputmode", "none");
            bookingDate.setAttribute("virtualkeyboardpolicy", "manual");
          },
          onChange: (selectedDates) => {
            if (selectedDates.length > 0) dismissMobilePickerKeyboard(bookingDate);
          },
          onClose: () => dismissMobilePickerKeyboard(bookingDate)
        });
      };

      const stepPanels = [...bookingForm.querySelectorAll("[data-booking-step]")];
      const stepIndicators = [...document.querySelectorAll("[data-step-indicator]")];
      const stepLines = [...document.querySelectorAll(".booking-step-line")];
      const bookingGrid = document.querySelector(".booking-grid");
      const bookingSidebar = document.querySelector(".booking-sidebar");
      const bookingPreviewCard = document.querySelector(".booking-preview-card");
      const reviewStepPanel = bookingForm.querySelector('[data-booking-step="3"]');
      const paymentSummaryBlock = reviewStepPanel?.querySelector(".step-payment-summary");
      const previousStepButton = document.getElementById("tourPreviousStep");
      const nextStepButton = document.getElementById("tourNextStep");
      const stepStatus = document.getElementById("tourStepStatus");
      const stepFieldKeys = {
        1: ["booking_type", "package_name", "selected_locations", "tour_type", "jump_off_port", "booking_date", "booking_end_date", "tour_duration"],
        2: ["contact_number", "num_adults", "num_children", "eco_category", "payment_option"],
        3: ["agree_privacy", "agree_other_fees"]
      };
      let currentStep = 1;

      const syncResponsiveSummaryPlacement = () => {
        if (!bookingGrid || !bookingPreviewCard || !reviewStepPanel) return;
        const mobileLayout = window.matchMedia(
          "(max-width: 768px), (min-width: 769px) and (max-width: 1024px) and (min-height: 600px)"
        ).matches;
        const summaryHeading = bookingPreviewCard.querySelector(".preview-card-header h2");
        if (mobileLayout) {
          if (bookingPreviewCard.parentElement !== reviewStepPanel) {
            reviewStepPanel.insertBefore(bookingPreviewCard, paymentSummaryBlock || reviewStepPanel.firstChild);
          }
          bookingPreviewCard.classList.add("is-mobile-review-summary");
          if (summaryHeading) summaryHeading.textContent = "Booking summary";
        } else {
          if (bookingSidebar && bookingPreviewCard.parentElement !== bookingSidebar) bookingSidebar.appendChild(bookingPreviewCard);
          bookingPreviewCard.classList.remove("is-mobile-review-summary");
          if (summaryHeading) summaryHeading.textContent = "Your booking";
        }
      };

      syncResponsiveSummaryPlacement();
      window.addEventListener("resize", syncResponsiveSummaryPlacement, { passive: true });

      const showBookingStep = (step, options = {}) => {
        currentStep = Math.min(3, Math.max(1, Number(step) || 1));
        stepPanels.forEach((panel) => {
          const active = Number(panel.dataset.bookingStep) === currentStep;
          panel.hidden = !active;
          panel.classList.toggle("is-active", active);
        });
        stepIndicators.forEach((indicator) => {
          const number = Number(indicator.dataset.stepIndicator);
          indicator.classList.toggle("is-active", number === currentStep);
          indicator.classList.toggle("is-complete", number < currentStep);
          if (number === currentStep) indicator.setAttribute("aria-current", "step");
          else indicator.removeAttribute("aria-current");
        });
        stepLines.forEach((line, index) => line.classList.toggle("is-complete", index + 1 < currentStep));
        previousStepButton.hidden = currentStep === 1;
        nextStepButton.hidden = currentStep === 3;
        stepStatus.querySelector("[data-step-copy]").textContent = `Step ${currentStep} of 3`;
        if (options.scroll !== false) {
          document.querySelector(".booking-form-card")?.scrollIntoView({ behavior: "smooth", block: "start" });
        }
      };

      const validateCurrentStep = () => {
        const allErrors = validateForm();
        const allowed = new Set(stepFieldKeys[currentStep] || []);
        const stepErrors = Object.fromEntries(Object.entries(allErrors).filter(([key]) => allowed.has(key)));
        if (Object.keys(stepErrors).length === 0) return true;
        renderValidationErrors(stepErrors);
        focusFirstInvalidField(stepErrors);
        showToast("Please complete the highlighted fields before continuing.", "error");
        return false;
      };

      nextStepButton.addEventListener("click", () => {
        if (!validateCurrentStep()) return;
        clearAllErrors();
        showBookingStep(currentStep + 1);
      });
      previousStepButton.addEventListener("click", () => showBookingStep(currentStep - 1));

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
          package_id: bookingType.value === "package"
              ? Number(selectedPackageOption?.dataset.packageId || 0)
              : null,
          packageName: bookingType.value === "package" ? packageSelect.value : "",
          selectedLocations: (bookingType.value === "boat" || bookingType.value === "tourguide")
              ? getSelectedLocations()
              : [],

          bookingDate: bookingDate.value,
          bookingEndDate: bookingEndDate.value,
          contactNumber: contactNumber.value.trim(),

          numAdults: pricing.adults,
          numChildren: pricing.children,

          operatorId: bookingType.value === "package"
              ? Number(selectedPackageOption?.dataset.operatorId || 0)
              : null,

          tourType: tourType.value,
          tourDuration: tourDuration.value.trim(),
          jumpOffPort: jumpOffPort.value,

          preferredSelection: bookingMetaSummary,

          // ADD THESE
          boat_id: selectedBoatId.value || null,
          guide_id: selectedGuideId.value || null,

          addOnService: pricing.addOnType,
          ecoCategory: ecoCategory.value,
          childAges: BOOKING_DATA.prefillChildAges || [],
          paymentOption: pricing.selectedPaymentOption,

          paymentAmount: pricing.payableNow,
          grandTotal: pricing.grandTotal,
          remainingBalance: pricing.grandTotal - pricing.payableNow,
          returnUrl: BOOKING_DATA.returnUrl || "hotel_resorts.php?tab=tours"
        };

        const response = await fetch("payments/create-booking-checkout.php", {
          method: "POST",
          headers: {
            "Content-Type": "application/json",
            "Accept": "application/json",
            "X-Booking-CSRF": <?= json_encode($bookingCheckoutCsrf) ?>
          },
          body: JSON.stringify(payload)
        });
        const result = await response.json();
        if (response.status === 429 || result?.rate_limited === true || result?.status === "rate_limited") {
          setOpeningPaymentState(false);
          window.RequestLimitModal?.handle(response, result, {
            button: payBookingBtn,
            defaultText: pricing.payableNow > 0 ? `Pay ${formatMoney(pricing.payableNow)}` : "Pay"
          });
          const rateLimitError = new Error(result?.message || "Request limit reached.");
          rateLimitError.rateLimited = true;
          throw rateLimitError;
        }
        if (!response.ok || !result?.success) {
          throw new Error(result?.message || "The payment page could not be opened.");
        }
        return result;
      };

      const setDateMin = () => {
        const today = new Date();
        const yyyy = today.getFullYear();
        const mm = String(today.getMonth() + 1).padStart(2, "0");
        const dd = String(today.getDate()).padStart(2, "0");
        bookingDate.min = `${yyyy}-${mm}-${dd}`;
        bookingEndDate.min = `${yyyy}-${mm}-${dd}`;
        if (bookingDate.value && bookingDate.value < bookingDate.min) {
          bookingDate.value = "";
          bookingEndDate.value = "";
          tourDuration.value = "";
        }
        if (overnightRangePicker) {
          overnightRangePicker.set("minDate", bookingDate.min);
        }
        if (sameDayPicker) sameDayPicker.set("minDate", bookingDate.min);
      };

      bookingType.addEventListener("change", () => {
        updateSectionsByType();
        refreshResourceAvailability();
        updatePreview();
        revalidateFieldErrors(["booking_type", "package_name", "selected_locations", "tour_type", "tour_duration"]);
      });

      packageSelect.addEventListener("change", () => {
        syncPackageDerivedFields();
        updateOvernightDateRangeState();
        applyTourTypeDurationRules();
        syncRangePickerFromFields();
        refreshResourceAvailability();
        updatePreview();
        revalidateFieldErrors(["package_name", "tour_type", "tour_duration"]);
      });

      preferredSelect.addEventListener("change", updatePreview);
      includeAddonService.addEventListener("change", updatePreview);
      ecoCategory.addEventListener("change", () => {
        updatePreview();
        revalidateFieldErrors(["eco_category"]);
      });
      paymentOption.addEventListener("change", () => {
        updatePreview();
        revalidateFieldErrors(["payment_option"]);
      });

      tourType.addEventListener("change", () => {
        durationHint.classList.remove("needs-tour-type");
        tourDuration.classList.remove("needs-tour-type");
        updateOvernightDateRangeState();
        applyTourTypeDurationRules();
        syncRangePickerFromFields();
        updatePreview();
        revalidateFieldErrors(["tour_type", "booking_end_date", "tour_duration"]);
      });

      tourDuration.addEventListener("input", () => {
        updatePreview();
        revalidateFieldErrors(["tour_duration"]);
      });
      tourDuration.addEventListener("pointerdown", (event) => {
        if (!window.matchMedia("(max-width: 768px)").matches) return;
        event.preventDefault();
        openOvernightRangePicker();
      });
      tourDuration.addEventListener("focus", () => {
        if (!window.matchMedia("(max-width: 768px)").matches) return;
        tourDuration.readOnly = true;
        tourDuration.setAttribute("inputmode", "none");
        requestAnimationFrame(() => tourDuration.blur());
      });
      tourDuration.addEventListener("click", (event) => {
        if (window.matchMedia("(max-width: 768px)").matches) {
          event.preventDefault();
          return;
        }
        openOvernightRangePicker();
      });
      tourDuration.addEventListener("keydown", (event) => {
        if (tourType.value === "overnight" && (event.key === "Enter" || event.key === " ")) {
          event.preventDefault();
          openOvernightRangePicker();
        }
      });
      jumpOffPort.addEventListener("change", () => {
        updatePreview();
        revalidateFieldErrors(["jump_off_port"]);
      });
      bookingDate.addEventListener("pointerdown", (event) => {
        if (!window.matchMedia("(max-width: 768px)").matches) return;
        event.preventDefault();
        openBookingStartDatePicker();
      });
      bookingDate.addEventListener("focus", () => {
        if (!window.matchMedia("(max-width: 768px)").matches) return;
        bookingDate.readOnly = true;
        bookingDate.setAttribute("inputmode", "none");
        requestAnimationFrame(() => bookingDate.blur());
      });
      bookingDate.addEventListener("click", (event) => {
        event.preventDefault();
        if (window.matchMedia("(max-width: 768px)").matches) {
          return;
        }
        openBookingStartDatePicker();
      });
      bookingDate.addEventListener("keydown", (event) => {
        if (event.key === "Enter" || event.key === " ") {
          event.preventDefault();
          openBookingStartDatePicker();
        }
      });
      bookingDate.addEventListener("change", () => {
        if (dateIsUnavailable(bookingDate.value)) {
          bookingDate.value = "";
          sameDayPicker?.clear(false);
          showToast("This selection is unavailable for the chosen date.", "error");
        }
        updateOvernightDateRangeState();
        applyTourTypeDurationRules();
        syncRangePickerFromFields();
        updatePreview();
        revalidateFieldErrors(["booking_date", "booking_end_date", "tour_duration"]);
      });
      bookingEndDate.addEventListener("change", () => {
        if (rangeHasUnavailableDate(bookingDate.value, bookingEndDate.value)) {
          bookingEndDate.value = "";
          showToast("The selected range includes an unavailable date.", "error");
        }
        applyTourTypeDurationRules();
        syncRangePickerFromFields();
        updatePreview();
        revalidateFieldErrors(["booking_end_date", "tour_duration"]);
      });
      contactNumber.addEventListener("input", () => {
        updatePreview();
        revalidateFieldErrors(["contact_number"]);
      });
      numAdults.addEventListener("input", () => {
        updatePreview();
        revalidateFieldErrors(["num_adults"]);
        clearTimeout(guestAvailabilityTimer);
        guestAvailabilityTimer = setTimeout(refreshResourceAvailability, 250);
      });
      numChildren.addEventListener("input", () => {
        updatePreview();
        revalidateFieldErrors(["num_adults", "num_children"]);
        clearTimeout(guestAvailabilityTimer);
        guestAvailabilityTimer = setTimeout(refreshResourceAvailability, 250);
      });
      agreePrivacy.addEventListener("change", () => revalidateFieldErrors(["agree_privacy"]));
      agreeOtherFees.addEventListener("change", () => revalidateFieldErrors(["agree_other_fees"]));

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
          revalidateFieldErrors(["selected_locations"]);
        });
      });

      bookingForm.addEventListener("submit", async (event) => {
        event.preventDefault();
        if (isOpeningPayment) return;
        const errors = validateForm();
        if (Object.keys(errors).length > 0) {
          const firstErrorKey = fieldOrder.find((key) => errors[key]);
          const errorStep = Number(Object.keys(stepFieldKeys).find((step) => stepFieldKeys[step].includes(firstErrorKey))) || 1;
          showBookingStep(errorStep);
          renderValidationErrors(errors);
          focusFirstInvalidField(errors);
          showToast("Please review the highlighted fields and try again.", "error");
          return;
        }

        clearAllErrors();
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
          setOpeningPaymentState(true);
          const result = await submitBooking();
          if (result?.success && result?.checkout_url) {
            window.location.assign(result.checkout_url);
            return;
          }
          throw new Error(result?.message || "The payment page could not be opened.");
        } catch (err) {
          if (!err?.rateLimited) setOpeningPaymentState(false);
          if (err?.rateLimited) return;
          Swal.fire({
            icon: "error",
            title: "Payment page unavailable",
            text: err?.message || "No booking was submitted. Please try again.",
            confirmButtonColor: "#2b7a66"
          });
        }
      });

      showBookingStep(1, { scroll: false });

      initOvernightRangePicker();
      setDateMin();
      initSameDayPicker();
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
      applySearchPrefill();
      refreshResourceAvailability();
      updatePreview();
    })();
  </script>
<script src="js/mobile-scroll.js"></script>
</body>
</html>
