<?php
chdir(__DIR__ . '/..');
require_once 'php/session_security.php';
AppSessionStart();
require_once 'php/db_connection.php';
require_once 'php/tourist_auth_helper.php';
require_once 'php/hotel_rooms_helper.php';
require_once 'php/input_validation.php';
HoEnsureHotelBookingsTable($pdo);

$target = (string)($_SERVER['REQUEST_URI'] ?? 'hotel_booking.php');
$user = TouristRequireLogin($pdo, 'redirect', './?open_login=1', $target);
$bookingCheckoutCsrf = (string)($_SESSION['paymongo_booking_csrf'] ?? '');
if ($bookingCheckoutCsrf === '') {
    $bookingCheckoutCsrf = bin2hex(random_bytes(32));
    $_SESSION['paymongo_booking_csrf'] = $bookingCheckoutCsrf;
}

function normalizeDate(mixed $date): ?string {
    if (!is_string($date) || trim($date) === '') return null;
    try {
        return ItourValidationDate($date, 'Date');
    } catch (InvalidArgumentException) {
        return null;
    }
}

function isDefaultProfileImage(?string $value): bool {
    $name = strtolower(basename(trim((string)$value)));
    return in_array($name, ['profileicon.png', 'profileicon2.png'], true);
}

function normalizeProfileImage(?string $value): string {
    $candidate = trim((string)$value);
    if ($candidate === '') return '';
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
    return $googleImage !== '' ? $googleImage : '';
}

$touristId = (int)$_SESSION['tourist_id'];
$fullName = trim((string)($user['full_name'] ?? ''));
$firstNameDefault = trim((string)($user['first_name'] ?? ''));
$lastNameDefault = trim((string)($user['last_name'] ?? ''));
if (($firstNameDefault === '' || $lastNameDefault === '') && $fullName !== '') {
    $parts = preg_split('/\s+/', $fullName);
    if ($firstNameDefault === '') $firstNameDefault = (string)($parts[0] ?? '');
    if ($lastNameDefault === '') $lastNameDefault = count($parts) > 1 ? implode(' ', array_slice($parts, 1)) : '';
}

$userEmail = trim((string)($user['email'] ?? ''));
$profileImage = resolveProfileImage($user['profile_picture'] ?? '', (string)($user['google_id'] ?? ''));
if ($profileImage === '' && !empty($_SESSION['tourist_profile_pic'])) {
    $profileImage = resolveProfileImage((string)$_SESSION['tourist_profile_pic'], (string)($user['google_id'] ?? ''));
}
$profileInitial = strtoupper(substr($firstNameDefault !== '' ? $firstNameDefault : ($fullName ?: 'U'), 0, 1));

$hotelId = (int)($_REQUEST['hotel_id'] ?? 0);
$roomId = (int)($_REQUEST['room_id'] ?? 0);
$roomType = trim((string)($_REQUEST['room_type'] ?? ''));
$checkin = normalizeDate($_REQUEST['checkin'] ?? null);
$checkout = normalizeDate($_REQUEST['checkout'] ?? null);
$adults = max(1, (int)($_REQUEST['adults'] ?? 1));
$children = max(0, (int)($_REQUEST['children'] ?? 0));
$childAgesInput = $_POST['child_ages'] ?? ($_REQUEST['child_ages'] ?? '');
$childAgesValues = is_array($childAgesInput)
    ? $childAgesInput
    : explode(',', (string)$childAgesInput);
$childAges = array_values(array_slice(array_filter(
    array_map(static fn($age) => trim((string)$age), $childAgesValues),
    static fn($age) => preg_match('/^\d{1,2}$/', $age) === 1 && (int)$age >= 0 && (int)$age <= 17
), 0, $children));
$selectedPaymentType = strtolower(trim((string)($_POST['payment_type'] ?? 'full')));
if (!in_array($selectedPaymentType, ['full', 'partial'], true)) {
    $selectedPaymentType = 'full';
}
$phoneInput = isset($_POST['phone_number']) ? trim((string)$_POST['phone_number']) : '';
$specialRequestInput = isset($_POST['special_request']) ? trim((string)$_POST['special_request']) : '';

$today = new DateTime('today');
if (!$checkin) {
    $tmp = clone $today;
    $tmp->modify('+1 day');
    $checkin = $tmp->format('Y-m-d');
}
if (!$checkout) {
    $tmp = new DateTime($checkin);
    $tmp->modify('+1 day');
    $checkout = $tmp->format('Y-m-d');
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $checkin < $today->format('Y-m-d')) {
    $tmp = clone $today;
    $tmp->modify('+1 day');
    $checkin = $tmp->format('Y-m-d');
    $checkout = (clone $tmp)->modify('+1 day')->format('Y-m-d');
}
if ($checkout <= $checkin) {
    $tmp = new DateTime($checkin);
    $tmp->modify('+1 day');
    $checkout = $tmp->format('Y-m-d');
}

$checkinDt = new DateTime($checkin);
$checkoutDt = new DateTime($checkout);
$nights = (int)$checkinDt->diff($checkoutDt)->days;
if ($nights < 1) $nights = 1;

$unitPrice = 0.0;
$computedTotal = 0.0;
$cancelDeadline = (clone $checkinDt)->modify('-3 days');

$stmtHotel = $pdo->prepare("
SELECT hotel_resort_id AS id, name, island, type, image_path
FROM hotel_resorts
WHERE hotel_resort_id = ? AND status = 'active'
LIMIT 1
");
$stmtHotel->execute([$hotelId]);
$hotel = $stmtHotel->fetch(PDO::FETCH_ASSOC);
if (!$hotel) {
    http_response_code(404);
    echo "Hotel not found.";
    exit;
}

$hotelName = (string)$hotel['name'];
$hotelIsland = (string)$hotel['island'];
$hotelType = strtoupper((string)$hotel['type']);

$roomList = HoGetHotelRooms($pdo, (int)$hotel['id'], true);
if (empty($roomList)) {
    http_response_code(404);
    echo "No rooms available.";
    exit;
}

$selectedRoom = null;
if ($roomId > 0) {
    $selectedRoom = HoGetHotelRoomById($pdo, (int)$hotel['id'], $roomId, true);
}
if (!$selectedRoom && $roomType !== '') {
    $selectedRoom = HoGetHotelRoomByName($pdo, (int)$hotel['id'], $roomType, true);
}
if (!$selectedRoom) {
    $selectedRoom = $roomList[0];
    $roomType = (string)$selectedRoom['room_name'];
}
$roomId = (int)$selectedRoom['id'];
$roomType = (string)$selectedRoom['room_name'];
$requestedGuests = max(1, $adults + $children);
$roomInitiallyUnavailable = false;
if (!HoIsHotelRoomAvailable($pdo, (int)$hotel['id'], $roomId, $roomType, $checkin, $checkout)) {
    $fallbackRooms = HoGetAvailableHotelRooms($pdo, (int)$hotel['id'], $checkin, $checkout, $requestedGuests);
    if (!empty($fallbackRooms)) {
        $selectedRoom = $fallbackRooms[0];
        $roomId = (int)$selectedRoom['id'];
        $roomType = (string)$selectedRoom['room_name'];
    } else {
        $roomInitiallyUnavailable = true;
    }
}

$roomData = [
    'price' => (float)$selectedRoom['price'],
    'description' => (string)$selectedRoom['description'],
    'inclusions' => (array)$selectedRoom['inclusions'],
];
$roomGalleryImages = (array)$selectedRoom['gallery_images'];
if (empty($roomGalleryImages)) {
    $roomGalleryImages = [(string)$selectedRoom['main_image_path']];
}
if (count($roomGalleryImages) < 3) {
    $roomGalleryImages = array_pad($roomGalleryImages, 3, $roomGalleryImages[0]);
}
$unitPrice = (float)$roomData['price'];
$computedTotal = $unitPrice * $nights;
$amountDueNow = $selectedPaymentType === 'partial' ? round($computedTotal * 0.20, 2) : $computedTotal;
$remainingBalance = max($computedTotal - $amountDueNow, 0);

$errors = [];
$fieldErrors = [];
$pushFieldError = static function (string $field, string $message) use (&$fieldErrors, &$errors): void {
    if (!isset($fieldErrors[$field])) {
        $fieldErrors[$field] = $message;
    }
    $errors[] = $message;
};
$successMessage = '';
if ($roomInitiallyUnavailable) {
    $pushFieldError('checkin', 'No room is currently available for the selected dates and guest count.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_hotel_booking'])) {
    $firstName = trim((string)($_POST['first_name'] ?? ''));
    $lastName = trim((string)($_POST['last_name'] ?? ''));
    $email = trim((string)($_POST['email'] ?? ''));
    $phoneNumber = trim((string)($_POST['phone_number'] ?? ''));
    $specialRequest = trim((string)($_POST['special_request'] ?? ''));

    $submittedCheckin = normalizeDate(isset($_POST['checkin']) && is_string($_POST['checkin']) ? $_POST['checkin'] : null);
    $submittedCheckout = normalizeDate(isset($_POST['checkout']) && is_string($_POST['checkout']) ? $_POST['checkout'] : null);
    if ($submittedCheckin === null) {
        $pushFieldError('checkin', 'Please enter a real check-in date in YYYY-MM-DD format.');
    } else {
        $checkin = $submittedCheckin;
    }
    if ($submittedCheckout === null) {
        $pushFieldError('checkout', 'Please enter a real check-out date in YYYY-MM-DD format.');
    } else {
        $checkout = $submittedCheckout;
    }
    try {
        $adults = ItourValidationInt($_POST['adults'] ?? null, 'Adults', 1, 100);
        $children = ItourValidationInt($_POST['children'] ?? 0, 'Children', 0, 100);
        $roomId = ItourValidationInt($_POST['room_id'] ?? null, 'Room ID', 1, PHP_INT_MAX);
    } catch (InvalidArgumentException $exception) {
        $pushFieldError('adults', $exception->getMessage());
    }
    $roomType = trim((string)($_POST['room_type'] ?? $roomType));
    $selectedPaymentType = strtolower(trim((string)($_POST['payment_type'] ?? $selectedPaymentType)));
    if (!in_array($selectedPaymentType, ['full', 'partial'], true)) {
        $selectedPaymentType = 'full';
    }

    $selectedRoom = null;
    if ($roomId > 0) {
        $selectedRoom = HoGetHotelRoomById($pdo, (int)$hotel['id'], $roomId, true);
    }
    if (!$selectedRoom && $roomType !== '') {
        $selectedRoom = HoGetHotelRoomByName($pdo, (int)$hotel['id'], $roomType, true);
    }
    if (!$selectedRoom) {
        $selectedRoom = $roomList[0];
    }
    $roomId = (int)$selectedRoom['id'];
    $roomType = (string)$selectedRoom['room_name'];

    $roomData = [
        'price' => (float)$selectedRoom['price'],
        'description' => (string)$selectedRoom['description'],
        'inclusions' => (array)$selectedRoom['inclusions'],
    ];
    $roomGalleryImages = (array)$selectedRoom['gallery_images'];
    if (empty($roomGalleryImages)) {
        $roomGalleryImages = [(string)$selectedRoom['main_image_path']];
    }
    if (count($roomGalleryImages) < 3) {
        $roomGalleryImages = array_pad($roomGalleryImages, 3, $roomGalleryImages[0]);
    }
    $unitPrice = (float)$roomData['price'];

    if ($checkin < $today->format('Y-m-d')) {
        $pushFieldError('checkin', 'Please select a check-in date that is today or later.');
    }
    if ($checkout <= $checkin) {
        $pushFieldError('checkout', 'Please choose a Stay Duration ending after the check-in date.');
    }
    if ($firstName === '') $pushFieldError('first_name', 'This field is required. Please enter your first name.');
    if ($lastName === '') $pushFieldError('last_name', 'This field is required. Please enter your last name.');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $pushFieldError('email', 'Please enter a valid email address (example: name@email.com).');
    if ($phoneNumber === '') $pushFieldError('phone_number', 'This field is required. Please enter your phone number.');
    if ((int)$adults + (int)$children > HoRoomCapacityTotal($selectedRoom)) {
        $pushFieldError('adults', 'Please reduce the number of guests. This room cannot accommodate the current total.');
        $pushFieldError('children', 'Please reduce the number of guests. This room cannot accommodate the current total.');
    }
    if (count($childAges) !== (int)$children) {
        $pushFieldError('child_ages', 'Please select an age for every child.');
    }
    if (!HoIsHotelRoomAvailable($pdo, (int)$hotel['id'], $roomId, $roomType, $checkin, $checkout)) {
        $pushFieldError('checkin', 'This room is no longer available on the selected dates. Please choose different dates.');
        $pushFieldError('checkout', 'This room is no longer available on the selected dates. Please choose different dates.');
    }

    $checkinDt = new DateTime($checkin);
    $checkoutDt = new DateTime($checkout);
    $nights = (int)$checkinDt->diff($checkoutDt)->days;
    if ($nights < 1) $nights = 1;
    $computedTotal = $unitPrice * $nights;
    $cancelDeadline = (clone $checkinDt)->modify('-3 days');
    $amountDueNow = $selectedPaymentType === 'partial' ? round($computedTotal * 0.20, 2) : $computedTotal;
    $remainingBalance = max($computedTotal - $amountDueNow, 0);
    $paymentStatus = $remainingBalance > 0 ? 'partial' : 'paid';

    if (!$errors) {
        $pushFieldError('payment_type', 'Online payment must be completed before this booking can be submitted. Please use the Proceed to Pay button.');
    }

    $phoneInput = $phoneNumber;
    $specialRequestInput = $specialRequest;
    $firstNameDefault = $firstName;
    $lastNameDefault = $lastName;
    $userEmail = $email;
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <script src="js/request-limit.js?v=<?= (int)@filemtime(__DIR__ . '/../js/request-limit.js') ?>"></script>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Hotel Booking | iTour Mercedes</title>
  <link rel="icon" type="image/png" href="img/newlogo.png" />
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css" />
  <link rel="stylesheet" href="public/styles/hotel_booking.css?v=<?= (int)@filemtime(__DIR__ . '/styles/hotel_booking.css') ?>" />
  <link rel="stylesheet" href="styles/required-fields.css" />
  <script src="js/required-fields.js" defer></script>
  <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
  <script src="https://cdn.jsdelivr.net/npm/zod@3.23.8/lib/index.umd.min.js"></script>
  <script src="js/booking-validation-schemas.js?v=<?= (int)@filemtime(__DIR__ . '/../js/booking-validation-schemas.js') ?>"></script>
</head>
<body>
  <header class="booking-header">
    <div class="booking-header-left">
      <a class="back-btn" href="hotel_details.php?id=<?= (int)$hotelId ?>" aria-label="Go back">&#8249; Back</a>
      <div class="brand-wrap">
        <img src="img/newlogo.png" alt="iTour Mercedes logo" class="brand-round-logo" />
        <img src="img/textlogo2.png" alt="iTour Mercedes" class="brand-text-logo" />
      </div>
    </div>
    <div class="profile-chip" aria-label="Your profile">
      <?php if ($profileImage !== ''): ?>
        <img
          src="<?= htmlspecialchars($profileImage) ?>"
          alt="Profile"
          onerror="this.onerror=null;this.style.display='none';this.parentElement.querySelector('.profile-initial-fallback').style.display='inline-flex';"
        />
        <span class="profile-initial-fallback" style="display:none;"><?= htmlspecialchars($profileInitial) ?></span>
      <?php else: ?>
        <span class="profile-initial-fallback"><?= htmlspecialchars($profileInitial) ?></span>
      <?php endif; ?>
    </div>
  </header>

  <main class="booking-page">
    <div class="booking-grid">
      <article class="mobile-stay-selection" aria-label="Selected hotel room">
        <img src="<?= htmlspecialchars($roomGalleryImages[0]) ?>" alt="<?= htmlspecialchars($roomType) ?>" />
        <div class="mobile-stay-selection__content">
          <span class="mobile-stay-selection__eyebrow"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m7.5 12.5 3 3 6-7"></path><circle cx="12" cy="12" r="9"></circle></svg>Your selected stay</span>
          <h2><?= htmlspecialchars($hotelName) ?></h2>
          <p class="mobile-stay-selection__room"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 19v-8h18v8M5 11V7h6a3 3 0 0 1 3 3v1M3 16h18M6 19v2M18 19v2"></path></svg><?= htmlspecialchars($roomType) ?></p>
          <div class="mobile-stay-selection__meta">
            <span><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 6h16v14H4zM8 3v6M16 3v6M4 10h16"></path></svg><b id="mobileStayDates"><?= htmlspecialchars($checkin) ?> to <?= htmlspecialchars($checkout) ?></b></span>
            <span><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M16 11a3 3 0 1 0 0-6 3 3 0 0 0 0 6ZM8 12a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7ZM2.5 20a5.5 5.5 0 0 1 11 0M13 14.5A5 5 0 0 1 21.5 18"></path></svg><b id="mobileStayGuests"><?= (int)$adults ?> adult(s), <?= (int)$children ?> child(ren)</b></span>
          </div>
          <p class="mobile-stay-selection__price"><span>Estimated stay</span><strong id="mobileStayEstimate">₱<?= number_format($computedTotal, 2) ?></strong></p>
        </div>
      </article>

      <section class="booking-form-card">
        <h1>Complete Your Booking</h1>
        <p class="subtitle">Choose full payment or a 20% deposit to reserve this room.</p>

        <div class="booking-stepper" aria-label="Hotel booking progress">
          <div class="booking-step-indicator is-active" data-step-indicator="1" aria-current="step">
            <span>1</span><strong>Stay details</strong>
          </div>
          <div class="booking-step-line" aria-hidden="true"></div>
          <div class="booking-step-indicator" data-step-indicator="2">
            <span>2</span><strong>Guest details</strong>
          </div>
          <div class="booking-step-line" aria-hidden="true"></div>
          <div class="booking-step-indicator" data-step-indicator="3">
            <span>3</span><strong>Review & pay</strong>
          </div>
        </div>

        <form method="post" class="booking-form" id="hotelBookingForm" data-required-fields novalidate>
          <input type="hidden" name="submit_hotel_booking" value="1" />
          <input type="hidden" name="hotel_id" value="<?= (int)$hotelId ?>" />
          <input type="hidden" name="room_id" value="<?= (int)$roomId ?>" />
          <input type="hidden" name="room_type" value="<?= htmlspecialchars($roomType) ?>" />

          <section class="booking-step-panel is-active" data-booking-step="1">
            <div class="step-section-heading">
              <b>1</b>
              <div><h2>Your stay</h2><p>Confirm the dates and number of guests for this room.</p></div>
              <p class="room-availability-note" id="roomAvailabilityNote" aria-live="polite">Reserved dates are disabled in the calendar.</p>
            </div>
          <div class="field-row two">
            <label>
              Check-in Date
              <input type="text" name="checkin" id="checkinDate" value="<?= htmlspecialchars($checkin) ?>" readonly aria-readonly="true" required />
            </label>
            <label>
              Stay Duration
              <input type="text" id="stayDuration" placeholder="Choose check-in and check-out dates" readonly required />
            </label>
          </div>
          <input type="hidden" name="checkout" id="checkoutDate" value="<?= htmlspecialchars($checkout) ?>" />

          <div class="field-row two">
            <label>
              Adults
              <input type="number" min="1" name="adults" id="adultsCount" value="<?= (int)$adults ?>" required />
            </label>
            <label>
              Children
              <input type="number" min="0" name="children" id="childrenCount" value="<?= (int)$children ?>" required />
            </label>
          </div>
          <div id="hotelChildAgeRows" class="hotel-child-age-list" tabindex="-1" aria-live="polite"></div>
          <p class="step-inline-note">Room capacity: up to <?= (int)HoRoomCapacityTotal($selectedRoom) ?> guests. Hotel pricing is per room and night.</p>
          </section>

          <section class="booking-step-panel" data-booking-step="2" hidden>
            <div class="step-section-heading">
              <b>2</b>
              <div><h2>Guest information</h2><p>Tell the property who will be checking in.</p></div>
            </div>
          <div class="field-row two">
            <label>
              First Name
              <input type="text" name="first_name" value="<?= htmlspecialchars($firstNameDefault) ?>" required />
            </label>
            <label>
              Last Name
              <input type="text" name="last_name" value="<?= htmlspecialchars($lastNameDefault) ?>" required />
            </label>
          </div>

          <div class="field-row two">
            <label>
              Email Address
              <input type="email" name="email" value="<?= htmlspecialchars($userEmail) ?>" required />
            </label>
            <label>
              Phone Number
              <input type="tel" name="phone_number" value="<?= htmlspecialchars($phoneInput) ?>" placeholder="Enter your active phone number" required />
            </label>
          </div>

          <div class="field-row">
            <label>
              Special Request <span>(Optional)</span>
              <textarea name="special_request" rows="4" placeholder="Any special instructions for your stay."><?= htmlspecialchars($specialRequestInput) ?></textarea>
            </label>
          </div>
          </section>

          <section class="booking-step-panel" data-booking-step="3" hidden>
            <div class="step-section-heading">
              <b>3</b>
              <div><h2>Review and payment</h2><p>Choose how much to pay now and review the total.</p></div>
            </div>
          <div class="payment-option-grid">
            <p class="payment-option-title" data-required-label>Payment Option</p>
            <label class="payment-option-item">
              <input type="radio" name="payment_type" value="full" <?= $selectedPaymentType === 'full' ? 'checked' : '' ?> />
              <span>
                <strong>Full Payment (100%)</strong>
                <small>Pay the full booking amount now.</small>
              </span>
            </label>
            <label class="payment-option-item">
              <input type="radio" name="payment_type" value="partial" <?= $selectedPaymentType === 'partial' ? 'checked' : '' ?> />
              <span>
                <strong>Partial Payment (20% deposit)</strong>
                <small>Pay the remaining balance during check-in.</small>
              </span>
            </label>
          </div>

          <div class="totals-panel">
            <h3>Payment summary</h3>
            <p><span>Rate per night</span><strong id="unitPriceText">₱<?= number_format($unitPrice, 2) ?></strong></p>
            <p><span>Number of nights</span><strong id="nightsText"><?= (int)$nights ?></strong></p>
            <p class="grand-total"><span>Total amount</span><strong id="totalText">₱<?= number_format($computedTotal, 2) ?></strong></p>
            <p><span>Amount due now</span><strong id="dueNowText">₱<?= number_format($amountDueNow, 2) ?></strong></p>
            <p><span>Remaining balance</span><strong id="remainingBalanceText">₱<?= number_format($remainingBalance, 2) ?></strong></p>
          </div>

          <button type="submit" class="submit-btn">Proceed to Payment</button>
          </section>

          <div class="booking-step-actions">
            <p id="hotelStepStatus" class="required-step-note">
              <span data-step-copy>Step 1 of 3</span>
              <span>·</span>
              <span class="required-mark" aria-hidden="true">*</span>
              <span>indicates a required field.</span>
            </p>
            <div>
              <button type="button" class="step-btn secondary" id="hotelPreviousStep" hidden>Back</button>
              <button type="button" class="step-btn primary" id="hotelNextStep">Continue</button>
            </div>
          </div>
        </form>
      </section>

      <aside class="booking-room-card">
        <div class="selection-kicker">
          <span class="summary-icon" aria-hidden="true">
            <svg viewBox="0 0 24 24"><path d="m9.2 16.2-4-4 1.4-1.4 2.6 2.6 8.2-8.2 1.4 1.4-9.6 9.6Z"></path></svg>
          </span>
          Your selected stay
        </div>
        <div class="room-gallery-block">
        <img src="<?= htmlspecialchars($roomGalleryImages[0]) ?>" class="room-main-image" alt="<?= htmlspecialchars($roomType) ?>" />
        <div class="room-thumb-row">
          <img src="<?= htmlspecialchars($roomGalleryImages[1]) ?>" alt="Room photo 2" />
          <img src="<?= htmlspecialchars($roomGalleryImages[2]) ?>" alt="Room photo 3" />
        </div>
        </div>

        <div class="room-info-block">
          <h2><?= htmlspecialchars($hotelName) ?></h2>
          <p class="room-meta-line"><?= htmlspecialchars($hotelIsland) ?> • <?= htmlspecialchars($hotelType) ?></p>
          <span class="room-type-label">Selected room</span>
          <h3><?= htmlspecialchars($roomType) ?></h3>
          <p class="room-description"><?= htmlspecialchars((string)$roomData['description']) ?></p>
        </div>

        <div class="inclusions-block">
          <h4>Room Inclusions</h4>
          <ul>
            <?php foreach ($roomData['inclusions'] as $item): ?>
              <li><?= htmlspecialchars((string)$item) ?></li>
            <?php endforeach; ?>
          </ul>
        </div>

        <div class="summary-block">
          <h4>Booking Summary</h4>
          <p><span>Stay Dates</span><strong id="summaryDates"><?= htmlspecialchars($checkin) ?> to <?= htmlspecialchars($checkout) ?></strong></p>
          <p><span>Nights</span><strong id="summaryNights"><?= (int)$nights ?></strong></p>
          <p><span>Guests</span><strong id="summaryGuests"><?= (int)$adults ?> adult(s), <?= (int)$children ?> child(ren)</strong></p>
          <p><span>Payment Option</span><strong id="summaryPaymentType"><?= $selectedPaymentType === 'partial' ? 'Partial (20%)' : 'Full (100%)' ?></strong></p>
          <p><span>Amount Due Now</span><strong id="summaryDueNow">₱<?= number_format($amountDueNow, 2) ?></strong></p>
          <p><span>Price per night</span><strong>₱<?= number_format($unitPrice, 2) ?></strong></p>
          <p class="overall-total"><span>Booking Total</span><strong id="summaryTotal">₱<?= number_format($computedTotal, 2) ?></strong></p>
        </div>

        <div class="policy-block" id="cancelPolicyBlock" data-checkin="<?= htmlspecialchars($checkin) ?>">
          <h4>Cancellation Policy</h4>
          <p id="cancelPolicyText">
            Free cancellation is allowed up to <?= htmlspecialchars($cancelDeadline->format('F d, Y')) ?> (3 days before check-in date) with a full refund. If the booking is cancelled less than 3 days before check-in, the guest is eligible for a 50% refund of the total booking amount.
          </p>
        </div>
      </aside>
    </div>
  </main>

  <script>
    (function () {
      const bookingForm = document.getElementById("hotelBookingForm");
      const checkin = document.getElementById("checkinDate");
      const checkout = document.getElementById("checkoutDate");
      const stayDuration = document.getElementById("stayDuration");
      const roomAvailabilityNotes = [...document.querySelectorAll(".room-availability-note")];
      const adults = document.getElementById("adultsCount");
      const children = document.getElementById("childrenCount");
      const childAgeRows = document.getElementById("hotelChildAgeRows");
      const firstName = bookingForm.querySelector('input[name="first_name"]');
      const lastName = bookingForm.querySelector('input[name="last_name"]');
      const email = bookingForm.querySelector('input[name="email"]');
      const phoneNumber = bookingForm.querySelector('input[name="phone_number"]');
      const submitButton = bookingForm.querySelector(".submit-btn");
      const paymentTypeInputs = document.querySelectorAll('input[name="payment_type"]');
      const unitPrice = <?= json_encode($unitPrice) ?>;
      const maxRoomCapacity = <?= json_encode(HoRoomCapacityTotal($selectedRoom)) ?>;
      const initialChildAges = <?= json_encode(array_map('intval', $childAges), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
      const serverFieldErrors = <?= json_encode($fieldErrors, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
      const depositRate = 0.20;
      const roomAvailabilityEndpoint = "php/hotel_room_unavailable_dates.php";
      const hotelId = <?= json_encode((int)$hotelId) ?>;
      const hotelRoomId = <?= json_encode((int)$roomId) ?>;
      const hotelRoomType = <?= json_encode($roomType, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
      let unavailableRoomDates = new Set();
      let stayDurationPicker = null;
      const touchedFields = new Set();
      const errorIconSvg = '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="7.25" x2="12" y2="13.25"></line><circle cx="12" cy="16.5" r="1"></circle></svg>';
      const fieldOrder = ["checkin", "checkout", "adults", "children", "child_ages", "payment_type", "first_name", "last_name", "email", "phone_number"];
      const fieldTargets = {
        checkin,
        checkout: stayDuration,
        adults,
        children,
        child_ages: childAgeRows,
        payment_type: bookingForm.querySelector(".payment-option-grid"),
        first_name: firstName,
        last_name: lastName,
        email,
        phone_number: phoneNumber
      };
      const formErrorMap = new Map();
      let isSubmitting = false;
      let hasSubmitted = false;
      let initialSnapshot = "";
      let hasUnsavedChanges = false;

      const nightsText = document.getElementById("nightsText");
      const totalText = document.getElementById("totalText");
      const dueNowText = document.getElementById("dueNowText");
      const remainingBalanceText = document.getElementById("remainingBalanceText");
      const summaryDates = document.getElementById("summaryDates");
      const summaryNights = document.getElementById("summaryNights");
      const summaryGuests = document.getElementById("summaryGuests");
      const summaryPaymentType = document.getElementById("summaryPaymentType");
      const summaryDueNow = document.getElementById("summaryDueNow");
      const summaryTotal = document.getElementById("summaryTotal");
      const cancelPolicyText = document.getElementById("cancelPolicyText");
      const mobileStayDates = document.getElementById("mobileStayDates");
      const mobileStayGuests = document.getElementById("mobileStayGuests");
      const mobileStayEstimate = document.getElementById("mobileStayEstimate");

      const toPhpDate = (d) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, "0")}-${String(d.getDate()).padStart(2, "0")}`;
      const formatMoney = (num) => `₱${num.toLocaleString("en-PH", { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
      const formatLongDate = (dateObj) => dateObj.toLocaleDateString("en-US", { month: "long", day: "2-digit", year: "numeric" });

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
        if (fieldKey === "payment_type") return target;
        if (fieldKey === "child_ages") return childAgeRows;
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
        if (fieldKey === "payment_type") {
          target?.classList.remove("field-error-outline");
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
        node.querySelector("span").textContent = message;
        node.classList.add("is-visible");
        if (fieldKey === "payment_type") {
          target?.classList.add("field-error-outline");
        } else if (target instanceof HTMLElement) {
          target.classList.add("input-error");
          target.setAttribute("aria-invalid", "true");
        }
        formErrorMap.set(fieldKey, message);
      };

      const clearAllErrors = () => {
        fieldOrder.forEach((fieldKey) => clearFieldError(fieldKey));
      };

      const getPaymentType = () => [...paymentTypeInputs].find(el => el.checked)?.value || "full";

      const getChildAges = () => [...childAgeRows.querySelectorAll(".hotel-child-age-select")]
        .map(select => select.value)
        .filter(value => value !== "")
        .map(Number);

      const renderChildAgeRows = (savedAges = null) => {
        const count = Math.max(0, Number(children.value || 0));
        const previous = savedAges === null
          ? [...childAgeRows.querySelectorAll(".hotel-child-age-select")].map(select => select.value)
          : (Array.isArray(savedAges) ? savedAges : []).map(String);

        childAgeRows.innerHTML = Array.from({ length: count }, (_, index) => {
          const selectedAge = previous[index] ?? "";
          const options = Array.from({ length: 18 }, (__, age) =>
            `<option value="${age}"${selectedAge === String(age) ? " selected" : ""}>${age} year${age === 1 ? "" : "s"} old${age <= 7 ? " — Free" : ""}</option>`
          ).join("");
          return `<label class="hotel-child-age-field">
            <span>Child ${index + 1} age</span>
            <select class="hotel-child-age-select" name="child_ages[]" aria-label="Age of child ${index + 1}" required>
              <option value="">Select age</option>${options}
            </select>
          </label>`;
        }).join("");

        childAgeRows.hidden = count === 0;
        if (count > 0) {
          childAgeRows.insertAdjacentHTML("beforeend", '<p class="hotel-child-age-note">Children age 7 and below are free from child hotel and entrance charges.</p>');
        }
        childAgeRows.querySelectorAll(".hotel-child-age-select").forEach(select => {
          select.addEventListener("change", () => {
            touchedFields.add("child_ages");
            revalidateTouchedField("child_ages");
            recalc();
            updateDirtyState();
          });
        });
      };

      const collectPayload = () => ({
        checkin: checkin.value,
        checkout: checkout.value,
        adults: Number(adults.value || 0),
        children: Number(children.value || 0),
        child_ages: getChildAges(),
        payment_type: getPaymentType(),
        first_name: firstName.value.trim(),
        last_name: lastName.value.trim(),
        email: email.value.trim(),
        phone_number: phoneNumber.value.trim()
      });

      const validateForm = () => {
        const payload = collectPayload();
        const validator = window.BookingValidationSchemas?.validateHotelBooking;
        const schemaResult = typeof validator === "function" ? validator(payload) : { success: true, errors: {} };
        const errors = schemaResult.success ? {} : { ...(schemaResult.errors || {}) };

        if (Number(payload.adults) + Number(payload.children) > Number(maxRoomCapacity || 0)) {
          errors.adults = "Please reduce guests. This room cannot accommodate the current total.";
          errors.children = "Please reduce guests. This room cannot accommodate the current total.";
        }
        if (payload.children > 0 && payload.child_ages.length !== payload.children) {
          errors.child_ages = "Please select an age for every child.";
        }
        return errors;
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

      const stepPanels = [...bookingForm.querySelectorAll("[data-booking-step]")];
      const stepIndicators = [...document.querySelectorAll("[data-step-indicator]")];
      const stepLines = [...document.querySelectorAll(".booking-step-line")];
      const previousStepButton = document.getElementById("hotelPreviousStep");
      const nextStepButton = document.getElementById("hotelNextStep");
      const stepStatus = document.getElementById("hotelStepStatus");
      const bookingGrid = document.querySelector(".booking-grid");
      const bookingRoomCard = document.querySelector(".booking-room-card");
      const reviewTotalsPanel = bookingForm.querySelector(".totals-panel");
      const mobileBookingQuery = window.matchMedia("(max-width: 1100px)");
      const stepFieldKeys = {
        1: ["checkin", "checkout", "adults", "children", "child_ages"],
        2: ["first_name", "last_name", "email", "phone_number"],
        3: ["payment_type"]
      };
      let currentStep = 1;

      const syncMobileBookingLayout = () => {
        if (!bookingGrid || !bookingRoomCard || !reviewTotalsPanel) return;
        if (mobileBookingQuery.matches) {
          bookingRoomCard.classList.add("is-mobile-review-summary");
          reviewTotalsPanel.parentNode.insertBefore(bookingRoomCard, reviewTotalsPanel);
        } else {
          bookingRoomCard.classList.remove("is-mobile-review-summary");
          bookingGrid.appendChild(bookingRoomCard);
        }
      };

      mobileBookingQuery.addEventListener?.("change", syncMobileBookingLayout);
      window.addEventListener("resize", syncMobileBookingLayout, { passive: true });
      syncMobileBookingLayout();

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

      const setSubmittingState = (submitting) => {
        isSubmitting = submitting;
        submitButton.disabled = submitting;
        submitButton.classList.toggle("is-loading", submitting);
        if (submitting) {
          submitButton.setAttribute("aria-busy", "true");
          submitButton.dataset.label = submitButton.textContent || "Proceed to Payment";
          submitButton.innerHTML = '<span class="btn-spinner" aria-hidden="true"></span><span>Opening secure payment...</span>';
        } else {
          submitButton.removeAttribute("aria-busy");
          submitButton.textContent = submitButton.dataset.label || "Proceed to Payment";
        }
      };

      const buildSnapshot = () => {
        const params = new URLSearchParams(new FormData(bookingForm));
        params.set("payment_type", getPaymentType());
        return params.toString();
      };

      const updateDirtyState = () => {
        if (!initialSnapshot) return;
        hasUnsavedChanges = buildSnapshot() !== initialSnapshot;
      };

      const enforceDateRules = () => {
        const today = new Date();
        today.setHours(0, 0, 0, 0);
        const todayValue = toPhpDate(today);
        if (checkin.value && checkin.value < todayValue) {
          checkin.value = "";
          checkout.value = "";
          stayDurationPicker?.clear(false);
        }
        if (!checkin.value || (checkout.value && checkout.value <= checkin.value)) {
          checkout.value = "";
        }
      };

      const rangeHasUnavailableDate = (startValue, endValue) => {
        if (!startValue || !endValue) return false;
        const cursor = new Date(`${startValue}T00:00:00`);
        const end = new Date(`${endValue}T00:00:00`);
        for (; cursor < end; cursor.setDate(cursor.getDate() + 1)) {
          if (unavailableRoomDates.has(toPhpDate(cursor))) return true;
        }
        return false;
      };

      const syncHotelCalendars = () => {
        if (typeof flatpickr !== "function") return;
        const disabled = [(date) => unavailableRoomDates.has(toPhpDate(date))];
        stayDurationPicker?.set("disable", disabled);
      };

      const initHotelCalendars = () => {
        if (typeof flatpickr !== "function") return;
        stayDurationPicker = flatpickr(stayDuration, {
          mode: "range",
          showMonths: window.innerWidth >= 760 ? 2 : 1,
          dateFormat: "M j, Y",
          minDate: "today",
          disableMobile: true,
          monthSelectorType: "static",
          nextArrow: "&#8250;",
          prevArrow: "&#8249;",
          disable: [(date) => unavailableRoomDates.has(toPhpDate(date))],
          defaultDate: checkin.value && checkout.value
            ? [new Date(`${checkin.value}T00:00:00`), new Date(`${checkout.value}T00:00:00`)]
            : null,
          onReady: (_, __, instance) => instance.calendarContainer.classList.add("booking-date-calendar", "booking-range-calendar"),
          onChange: (selectedDates, _, instance) => {
            if (selectedDates.length === 0) {
              checkin.value = "";
              checkout.value = "";
            } else if (selectedDates.length === 1) {
              checkin.value = toPhpDate(selectedDates[0]);
              checkout.value = "";
            } else {
              const selectedCheckin = toPhpDate(selectedDates[0]);
              const selectedCheckout = toPhpDate(selectedDates[1]);
              if (rangeHasUnavailableDate(selectedCheckin, selectedCheckout)) {
                checkin.value = "";
                checkout.value = "";
                instance.clear(false);
                showToast("That stay includes a reserved night. Please choose different dates.", "error");
                recalc();
                return;
              }
              checkin.value = selectedCheckin;
              checkout.value = selectedCheckout;
            }
            touchedFields.add("checkin");
            touchedFields.add("checkout");
            revalidateTouchedField("checkin");
            revalidateTouchedField("checkout");
            updateDirtyState();
            recalc();
          },
          onClose: (selectedDates, _, instance) => {
            if (selectedDates.length === 1) {
              checkin.value = "";
              checkout.value = "";
              instance.clear(false);
              showToast("Please select both dates in Stay Duration.", "error");
              recalc();
            }
          }
        });
        if (checkin.value && checkout.value) {
          stayDurationPicker.setDate([
            new Date(`${checkin.value}T00:00:00`),
            new Date(`${checkout.value}T00:00:00`)
          ], false);
        }
        syncHotelCalendars();
      };

      const resizeHotelCalendar = () => {
        if (!stayDurationPicker) return;
        const months = window.innerWidth >= 760 ? 2 : 1;
        if (stayDurationPicker.config.showMonths !== months) {
          stayDurationPicker.set("showMonths", months);
        }
      };

      window.addEventListener("resize", resizeHotelCalendar);

      const loadUnavailableRoomDates = async () => {
        const today = new Date();
        const limit = new Date(today.getFullYear() + 2, today.getMonth(), today.getDate());
        const params = new URLSearchParams({
          hotel_id: String(hotelId),
          room_id: String(hotelRoomId),
          room_type: hotelRoomType,
          from: toPhpDate(today),
          to: toPhpDate(limit)
        });
        try {
          const response = await fetch(`${roomAvailabilityEndpoint}?${params}`, { headers: { "Accept": "application/json" } });
          const result = await response.json();
          if (!response.ok || !result.success) throw new Error(result.message || "Availability could not be loaded.");
          unavailableRoomDates = new Set(result.unavailable_dates || []);
          syncHotelCalendars();
          roomAvailabilityNotes.forEach(note => { note.textContent = "Reserved dates are disabled in the calendar."; });
          if (rangeHasUnavailableDate(checkin.value, checkout.value)) {
            checkin.value = "";
            checkout.value = "";
            stayDurationPicker?.clear(false);
            showToast("The previous stay dates are no longer available. Please choose new dates.", "error");
            recalc();
          }
        } catch (error) {
          roomAvailabilityNotes.forEach(note => { note.textContent = "Reserved dates could not be loaded. Please try again before booking."; });
        }
      };

      const recalc = () => {
        enforceDateRules();
        const checkinDate = new Date(checkin.value);
        const checkoutDate = new Date(checkout.value);
        const adultQty = Math.max(1, Number(adults.value || 1));
        const childQty = Math.max(0, Number(children.value || 0));
        const selectedPaymentType = getPaymentType();

        let nights = Math.round((checkoutDate - checkinDate) / 86400000);
        const hasValidStay = Boolean(checkin.value && checkout.value && Number.isFinite(nights) && nights >= 1);
        if (!hasValidStay) nights = 0;

        const total = unitPrice * nights;
        const dueNow = selectedPaymentType === "partial" ? total * depositRate : total;
        const remaining = Math.max(total - dueNow, 0);

        nightsText.textContent = String(nights);
        totalText.textContent = formatMoney(total);
        dueNowText.textContent = formatMoney(dueNow);
        remainingBalanceText.textContent = formatMoney(remaining);

        summaryDates.textContent = hasValidStay ? `${checkin.value} to ${checkout.value}` : "Choose your stay duration";
        summaryNights.textContent = hasValidStay ? String(nights) : "-";
        const selectedChildAges = getChildAges();
        const childAgeSummary = childQty > 0 && selectedChildAges.length
          ? ` · ages ${selectedChildAges.join(", ")}`
          : "";
        summaryGuests.textContent = `${adultQty} adult(s), ${childQty} child(ren)${childAgeSummary}`;
        summaryPaymentType.textContent = selectedPaymentType === "partial" ? "Partial (20%)" : "Full (100%)";
        summaryDueNow.textContent = formatMoney(dueNow);
        summaryTotal.textContent = formatMoney(total);
        mobileStayDates.textContent = hasValidStay ? `${checkin.value} to ${checkout.value}` : "Choose stay dates";
        mobileStayGuests.textContent = `${adultQty} adult(s), ${childQty} child(ren)`;
        mobileStayEstimate.textContent = formatMoney(total);
        if (!isSubmitting) {
          submitButton.textContent = `Proceed to Pay ${formatMoney(dueNow)}`;
          submitButton.setAttribute("aria-label", `Proceed to payment. Amount due now: ${formatMoney(dueNow)}`);
        }

        if (hasValidStay) {
          const deadline = new Date(checkin.value);
          deadline.setDate(deadline.getDate() - 3);
          cancelPolicyText.textContent = `Free cancellation is allowed up to ${formatLongDate(deadline)} (3 days before check-in date) with a full refund. If the booking is cancelled less than 3 days before check-in, the guest is eligible for a 50% refund of the total booking amount.`;
        } else {
          cancelPolicyText.textContent = "Choose your stay duration to view the cancellation deadline.";
        }
      };

      [checkin, checkout, adults, children].forEach(el => {
        el.addEventListener("change", recalc);
        el.addEventListener("input", recalc);
      });
      children.addEventListener("input", () => renderChildAgeRows());
      children.addEventListener("change", () => renderChildAgeRows());
      paymentTypeInputs.forEach(el => el.addEventListener("change", recalc));

      const revalidateTouchedField = (fieldKey) => {
        if (!touchedFields.has(fieldKey)) return;
        const errors = validateForm();
        if (errors[fieldKey]) {
          setFieldError(fieldKey, errors[fieldKey]);
        } else {
          clearFieldError(fieldKey);
        }
      };

      const setTouchedAndValidate = (fieldKey) => {
        touchedFields.add(fieldKey);
        revalidateTouchedField(fieldKey);
        updateDirtyState();
      };

      [
        [checkin, "checkin"],
        [stayDuration, "checkout"],
        [adults, "adults"],
        [children, "children"],
        [firstName, "first_name"],
        [lastName, "last_name"],
        [email, "email"],
        [phoneNumber, "phone_number"]
      ].forEach(([element, key]) => {
        element.addEventListener("input", () => setTouchedAndValidate(key));
        element.addEventListener("change", () => setTouchedAndValidate(key));
      });
      paymentTypeInputs.forEach((radio) => {
        radio.addEventListener("change", () => setTouchedAndValidate("payment_type"));
      });

      bookingForm.addEventListener("submit", async (event) => {
        event.preventDefault();
        if (isSubmitting) {
          return;
        }
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
        setSubmittingState(true);
        hasSubmitted = true;
        hasUnsavedChanges = false;
        try {
          const formData = new FormData(bookingForm);
          const payload = Object.fromEntries(formData.entries());
          payload.booking_domain = "hotel";
          payload.bookingType = "hotel";
          payload.payment_type = getPaymentType();
          payload.returnUrl = `hotel_details.php?id=${encodeURIComponent(payload.hotel_id)}`;
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
            hasSubmitted = false;
            setSubmittingState(false);
            window.RequestLimitModal?.handle(response, result, {
              button: submitButton,
              defaultText: submitButton.dataset.label || "Proceed to Payment"
            });
            return;
          }
          if (!response.ok || !result?.success || !result?.checkout_url) {
            throw new Error(result?.message || "The payment page could not be opened.");
          }
          window.location.assign(result.checkout_url);
        } catch (error) {
          hasSubmitted = false;
          setSubmittingState(false);
          showToast(error?.message || "The payment page could not be opened. No booking was submitted.", "error");
        }
      });

      window.addEventListener("beforeunload", (event) => {
        if (!hasUnsavedChanges || hasSubmitted || isSubmitting) return;
        event.preventDefault();
        event.returnValue = "";
      });

      renderChildAgeRows(initialChildAges);
      recalc();
      initHotelCalendars();
      loadUnavailableRoomDates();
      showBookingStep(1, { scroll: false });
      initialSnapshot = buildSnapshot();
      if (serverFieldErrors && typeof serverFieldErrors === "object" && Object.keys(serverFieldErrors).length > 0) {
        const firstServerErrorKey = fieldOrder.find((key) => serverFieldErrors[key]);
        const serverErrorStep = Number(Object.keys(stepFieldKeys).find((step) => stepFieldKeys[step].includes(firstServerErrorKey))) || 1;
        showBookingStep(serverErrorStep, { scroll: false });
        renderValidationErrors(serverFieldErrors);
      }
    })();
  </script>
<script src="js/mobile-scroll.js"></script>
</body>
</html>
