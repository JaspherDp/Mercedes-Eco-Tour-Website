<?php
chdir(__DIR__ . '/..');
session_start();
require_once 'php/db_connection.php';
require_once 'php/hotel_rooms_helper.php';
HoEnsureHotelBookingsTable($pdo);

if (!isset($_SESSION['tourist_id'])) {
    $target = $_SERVER['REQUEST_URI'] ?? 'hotel_booking.php';
    $_SESSION['post_login_redirect'] = $target;
    header('Location: homepage.php');
    exit;
}

function normalizeDate(?string $date): ?string {
    $date = trim((string)$date);
    if ($date === '') return null;
    $dt = DateTime::createFromFormat('Y-m-d', $date);
    if (!$dt) return null;
    return $dt->format('Y-m-d');
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
    $id = trim((string)$googleId);
    if ($id === '') return '';
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
    return $googleImage !== '' ? $googleImage : '';
}

$touristId = (int)$_SESSION['tourist_id'];
$stmtUser = $pdo->prepare("SELECT * FROM tourist WHERE tourist_id = ?");
$stmtUser->execute([$touristId]);
$user = $stmtUser->fetch(PDO::FETCH_ASSOC);
if (!$user) {
    header('Location: hotel_resorts.php');
    exit;
}

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
if (strtotime($checkout) <= strtotime($checkin)) {
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
$successMessage = '';
if ($roomInitiallyUnavailable) {
    $errors[] = 'No room is currently available for the selected dates and guest count.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_hotel_booking'])) {
    $firstName = trim((string)($_POST['first_name'] ?? ''));
    $lastName = trim((string)($_POST['last_name'] ?? ''));
    $email = trim((string)($_POST['email'] ?? ''));
    $phoneNumber = trim((string)($_POST['phone_number'] ?? ''));
    $specialRequest = trim((string)($_POST['special_request'] ?? ''));

    $checkin = normalizeDate($_POST['checkin'] ?? null) ?: $checkin;
    $checkout = normalizeDate($_POST['checkout'] ?? null) ?: $checkout;
    $adults = max(1, (int)($_POST['adults'] ?? $adults));
    $children = max(0, (int)($_POST['children'] ?? $children));
    $roomId = (int)($_POST['room_id'] ?? $roomId);
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

    if (strtotime($checkout) <= strtotime($checkin)) {
        $errors[] = 'Checkout date must be after check-in date.';
    }
    if ($firstName === '') $errors[] = 'First name is required.';
    if ($lastName === '') $errors[] = 'Last name is required.';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Please enter a valid email address.';
    if ($phoneNumber === '') $errors[] = 'Phone number is required.';
    if ((int)$adults + (int)$children > HoRoomCapacityTotal($selectedRoom)) {
        $errors[] = 'Selected room cannot accommodate the total number of guests.';
    }
    if (!HoIsHotelRoomAvailable($pdo, (int)$hotel['id'], $roomId, $roomType, $checkin, $checkout)) {
        $errors[] = 'Selected room is no longer available for the chosen dates. Please choose another room or dates.';
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
        $insert = $pdo->prepare("
            INSERT INTO hotel_room_bookings
            (tourist_id, hotel_resort_id, hotel_room_id, room_type, checkin_date, checkout_date, nights, rooms_booked, adults, children, first_name, last_name, email, phone_number, special_request, unit_price, total_amount, amount_paid, remaining_balance, payment_type, booking_status, payment_status)
            VALUES
            (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?)
        ");
        $insert->execute([
            $touristId,
            $hotelId,
            $roomId,
            $roomType,
            $checkin,
            $checkout,
            $nights,
            1,
            $adults,
            $children,
            $firstName,
            $lastName,
            $email,
            $phoneNumber,
            $specialRequest !== '' ? $specialRequest : null,
            $unitPrice,
            $computedTotal,
            $amountDueNow,
            $remainingBalance,
            $selectedPaymentType,
            $paymentStatus,
        ]);

        $newBookingId = (int)$pdo->lastInsertId();
        $params = http_build_query([
            'id' => (string)$hotelId,
            'booking_success' => '1',
            'booking_ref' => (string)$newBookingId
        ]);
        header('Location: hotel_details.php?' . $params);
        exit;
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
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Hotel Booking | iTour Mercedes</title>
  <link rel="icon" type="image/png" href="img/newlogo.png" />
  <link rel="stylesheet" href="public/styles/hotel_booking.css" />
</head>
<body>
  <header class="booking-header">
    <div class="booking-header-left">
      <a class="back-btn" href="hotel_details.php?id=<?= (int)$hotelId ?>" aria-label="Go back">&#8249; Back</a>
      <div class="brand-wrap">
        <img src="img/newlogo.png" alt="iTour Mercedes logo" class="brand-round-logo" />
        <img src="img/textlogo.png" alt="iTour Mercedes" class="brand-text-logo" />
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
    <?php if (!empty($errors)): ?>
      <div class="booking-alert error"><?= htmlspecialchars(implode(' ', $errors)) ?></div>
    <?php endif; ?>

    <div class="booking-grid">
      <section class="booking-form-card">
        <h1>Complete Your Booking</h1>
        <p class="subtitle">Choose full payment or a 20% deposit to reserve this room.</p>

        <form method="post" class="booking-form" id="hotelBookingForm" novalidate>
          <input type="hidden" name="submit_hotel_booking" value="1" />
          <input type="hidden" name="hotel_id" value="<?= (int)$hotelId ?>" />
          <input type="hidden" name="room_id" value="<?= (int)$roomId ?>" />
          <input type="hidden" name="room_type" value="<?= htmlspecialchars($roomType) ?>" />

          <div class="field-row two">
            <label>
              Check-in Date
              <input type="date" name="checkin" id="checkinDate" value="<?= htmlspecialchars($checkin) ?>" required />
            </label>
            <label>
              Check-out Date
              <input type="date" name="checkout" id="checkoutDate" value="<?= htmlspecialchars($checkout) ?>" required />
            </label>
          </div>

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

          <div class="payment-option-grid">
            <p class="payment-option-title">Payment Option</p>
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

          <div class="totals-panel">
            <p><span>Rate per night</span><strong id="unitPriceText">₱<?= number_format($unitPrice, 2) ?></strong></p>
            <p><span>Number of nights</span><strong id="nightsText"><?= (int)$nights ?></strong></p>
            <p class="grand-total"><span>Total amount</span><strong id="totalText">₱<?= number_format($computedTotal, 2) ?></strong></p>
            <p><span>Amount due now</span><strong id="dueNowText">₱<?= number_format($amountDueNow, 2) ?></strong></p>
            <p><span>Remaining balance</span><strong id="remainingBalanceText">₱<?= number_format($remainingBalance, 2) ?></strong></p>
          </div>

          <button type="submit" class="submit-btn">Proceed to Payment</button>
        </form>
      </section>

      <aside class="booking-room-card">
        <img src="<?= htmlspecialchars($roomGalleryImages[0]) ?>" class="room-main-image" alt="<?= htmlspecialchars($roomType) ?>" />
        <div class="room-thumb-row">
          <img src="<?= htmlspecialchars($roomGalleryImages[1]) ?>" alt="Room photo 2" />
          <img src="<?= htmlspecialchars($roomGalleryImages[2]) ?>" alt="Room photo 3" />
        </div>

        <div class="room-info-block">
          <h2><?= htmlspecialchars($hotelName) ?></h2>
          <p class="room-meta-line"><?= htmlspecialchars($hotelIsland) ?> • <?= htmlspecialchars($hotelType) ?></p>
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
      const checkin = document.getElementById("checkinDate");
      const checkout = document.getElementById("checkoutDate");
      const adults = document.getElementById("adultsCount");
      const children = document.getElementById("childrenCount");
      const paymentTypeInputs = document.querySelectorAll('input[name="payment_type"]');
      const unitPrice = <?= json_encode($unitPrice) ?>;
      const depositRate = 0.20;

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

      const toPhpDate = (d) => d.toISOString().split("T")[0];
      const formatMoney = (num) => `₱${num.toLocaleString("en-PH", { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
      const formatLongDate = (dateObj) => dateObj.toLocaleDateString("en-US", { month: "long", day: "2-digit", year: "numeric" });

      const enforceDateRules = () => {
        const today = new Date();
        today.setHours(0, 0, 0, 0);
        checkin.min = toPhpDate(today);

        if (checkin.value) {
          const minCheckout = new Date(checkin.value);
          minCheckout.setDate(minCheckout.getDate() + 1);
          checkout.min = toPhpDate(minCheckout);
          if (!checkout.value || new Date(checkout.value) <= new Date(checkin.value)) {
            checkout.value = toPhpDate(minCheckout);
          }
        }
      };

      const recalc = () => {
        enforceDateRules();
        const checkinDate = new Date(checkin.value);
        const checkoutDate = new Date(checkout.value);
        const adultQty = Math.max(1, Number(adults.value || 1));
        const childQty = Math.max(0, Number(children.value || 0));
        const selectedPaymentType = [...paymentTypeInputs].find(el => el.checked)?.value || "full";

        let nights = Math.round((checkoutDate - checkinDate) / 86400000);
        if (!Number.isFinite(nights) || nights < 1) nights = 1;

        const total = unitPrice * nights;
        const dueNow = selectedPaymentType === "partial" ? total * depositRate : total;
        const remaining = Math.max(total - dueNow, 0);

        nightsText.textContent = String(nights);
        totalText.textContent = formatMoney(total);
        dueNowText.textContent = formatMoney(dueNow);
        remainingBalanceText.textContent = formatMoney(remaining);

        summaryDates.textContent = `${checkin.value} to ${checkout.value}`;
        summaryNights.textContent = String(nights);
        summaryGuests.textContent = `${adultQty} adult(s), ${childQty} child(ren)`;
        summaryPaymentType.textContent = selectedPaymentType === "partial" ? "Partial (20%)" : "Full (100%)";
        summaryDueNow.textContent = formatMoney(dueNow);
        summaryTotal.textContent = formatMoney(total);

        const deadline = new Date(checkin.value);
        deadline.setDate(deadline.getDate() - 3);
        cancelPolicyText.textContent = `Free cancellation is allowed up to ${formatLongDate(deadline)} (3 days before check-in date) with a full refund. If the booking is cancelled less than 3 days before check-in, the guest is eligible for a 50% refund of the total booking amount.`;
      };

      [checkin, checkout, adults, children].forEach(el => {
        el.addEventListener("change", recalc);
        el.addEventListener("input", recalc);
      });
      paymentTypeInputs.forEach(el => el.addEventListener("change", recalc));

      recalc();
    })();
  </script>
</body>
</html>
