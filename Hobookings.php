<?php
require_once __DIR__ . '/Ho_common.php';
require_once __DIR__ . '/php/activity_logger.php';
require_once __DIR__ . '/php/booking_reference_helper.php';
require_once __DIR__ . '/php/firebase_config.php';
require_once __DIR__ . '/php/input_validation.php';
require_once __DIR__ . '/payments/PayMongoService.php';
require_once __DIR__ . '/payments/PaymentReconciler.php';

require_once __DIR__ . '/php/PHPMailer/src/Exception.php';
require_once __DIR__ . '/php/PHPMailer/src/PHPMailer.php';
require_once __DIR__ . '/php/PHPMailer/src/SMTP.php';
require_once __DIR__ . '/php/booking_confirmation_email.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

$hoAdmin = HoRequireHotelAdmin($pdo);
$hoHotelResortId = (int)$hoAdmin['hotel_resort_id'];
$hoPropertyName = trim((string)($hoAdmin['property_name'] ?? ''));

$hoActive = 'bookings';
$hoTitle = 'Booking Management';
$hoOwnerName = $hoPropertyName !== '' ? $hoPropertyName . ' Admin' : (string)$hoAdmin['username'];
$hoUnreadBadge = HoGetUnreadCount($pdo, $hoHotelResortId);
$hoNotifItems = HoGetNotificationItems($pdo, 8, $hoHotelResortId);
$hoShowRangeFilter = true;
$hoWalkinCsrf = (string)($_SESSION['ho_walkin_booking_csrf'] ?? '');
if ($hoWalkinCsrf === '') {
    $hoWalkinCsrf = bin2hex(random_bytes(24));
    $_SESSION['ho_walkin_booking_csrf'] = $hoWalkinCsrf;
}
$hoPayMongoCsrf = (string)($_SESSION['paymongo_hotel_admin_csrf'] ?? '');
if ($hoPayMongoCsrf === '') {
    $hoPayMongoCsrf = bin2hex(random_bytes(32));
    $_SESSION['paymongo_hotel_admin_csrf'] = $hoPayMongoCsrf;
}
$hoPushCsrf = (string)($_SESSION['hotel_push_csrf'] ?? '');
if ($hoPushCsrf === '') {
    $hoPushCsrf = bin2hex(random_bytes(32));
    $_SESSION['hotel_push_csrf'] = $hoPushCsrf;
}
$hoFirebaseConfiguration = firebase_public_configuration();
$hotelBookingCsrf = AppCsrfToken('hotel_admin', 'booking_management');
$hotelNotificationCsrf = AppCsrfToken('hotel_admin', 'notifications');

if (isset($_GET['ho_action']) && $_GET['ho_action'] === 'paymongo_payment_status') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    $token = strtolower(trim((string)($_GET['token'] ?? '')));
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid payment return token.']);
        exit;
    }
    try {
        $stmt = $pdo->prepare(
            "SELECT payment_transaction_id, booking_id, booking_reference, amount_minor, status,
                    provider_checkout_session_id, metadata
             FROM payment_transactions WHERE return_token = ? AND booking_domain = 'hotel' LIMIT 1"
        );
        $stmt->execute([$token]);
        $transaction = $stmt->fetch(PDO::FETCH_ASSOC);
        $metadata = $transaction ? json_decode((string)($transaction['metadata'] ?? ''), true) : null;
        if (!$transaction || !is_array($metadata)
            || !in_array((string)($metadata['source'] ?? ''), ['admin_booking_payment', 'hotel_checkin_payment', 'hotel_checkout_payment'], true)
            || (int)($metadata['hotel_admin_id'] ?? 0) !== (int)$hoAdmin['hotel_admin_id']
            || (int)($metadata['hotel_resort_id'] ?? 0) !== $hoHotelResortId) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Hotel payment transaction not found.']);
            exit;
        }
        if (strtolower((string)$transaction['status']) === 'pending'
            && preg_match('/^cs_[A-Za-z0-9]+$/', (string)$transaction['provider_checkout_session_id'])) {
            try {
                $checkout = PayMongoService::fromEnvironment()->retrieveCheckoutSession((string)$transaction['provider_checkout_session_id']);
                PaymentReconciler::reconcilePaidCheckout($pdo, is_array($checkout['data'] ?? null) ? $checkout['data'] : []);
                $refresh = $pdo->prepare('SELECT status FROM payment_transactions WHERE payment_transaction_id = ?');
                $refresh->execute([(int)$transaction['payment_transaction_id']]);
                $transaction['status'] = (string)$refresh->fetchColumn();
            } catch (UnexpectedValueException|PayMongoException $ignored) {
            }
        }
        echo json_encode([
            'success' => true,
            'status' => strtolower((string)$transaction['status']),
            'booking_id' => (int)$transaction['booking_id'],
            'booking_reference' => (string)$transaction['booking_reference'],
            'amount' => ((int)$transaction['amount_minor']) / 100,
        ]);
    } catch (Throwable $exception) {
        http_response_code(503);
        echo json_encode(['success' => false, 'message' => 'Payment status is temporarily unavailable.']);
    }
    exit;
}

if (isset($_POST['ho_action']) && $_POST['ho_action'] === 'mark_notifications_read') {
    if (!AppVerifyCsrf('hotel_admin', 'notifications', $_POST['csrf_token'] ?? null)) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['ok' => false]);
        exit;
    }
    HoMarkNotificationsRead($pdo, $hoHotelResortId);
    header('Content-Type: application/json');
    echo json_encode(['ok' => true]);
    exit;
}

if (isset($_GET['ho_action']) && $_GET['ho_action'] === 'search_tourists') {
    $touristSearch = trim((string)($_GET['q'] ?? ''));
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    if (mb_strlen($touristSearch) < 2) {
        echo json_encode(['ok' => true, 'tourists' => []]);
        exit;
    }
    $searchTerm = '%' . $touristSearch . '%';
    $touristStmt = $pdo->prepare("
        SELECT tourist_id, full_name, email, phone_number, profile_picture, google_id
        FROM tourist
        WHERE status = 'active'
          AND (full_name LIKE ? OR email LIKE ? OR phone_number LIKE ?)
        ORDER BY full_name ASC
        LIMIT 15
    ");
    $touristStmt->execute([$searchTerm, $searchTerm, $searchTerm]);
    $touristResults = $touristStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($touristResults as &$touristResult) {
        $profileImage = HoResolveProfileImage((string)($touristResult['profile_picture'] ?? ''));
        if ($profileImage === '' && !empty($touristResult['google_id'])) {
            $profileImage = HoBuildGoogleAvatarUrl((string)$touristResult['google_id']);
        }
        $touristResult['profile_image'] = $profileImage;
        unset($touristResult['profile_picture'], $touristResult['google_id']);
    }
    unset($touristResult);
    echo json_encode(['ok' => true, 'tourists' => $touristResults]);
    exit;
}

if (isset($_GET['ho_action']) && $_GET['ho_action'] === 'walkin_available_rooms') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    try {
        $checkin = trim((string)($_GET['checkin'] ?? ''));
        $checkout = trim((string)($_GET['checkout'] ?? ''));
        $capacityGuests = ItourValidationInt($_GET['capacity_guests'] ?? 1, 'Guest capacity', 1, 100);
        $checkin = ItourValidationDate($checkin, 'Check-in date');
        $checkout = ItourValidationDate($checkout, 'Check-out date');
        if ($checkout <= $checkin) {
            throw new RuntimeException('Select valid stay dates before choosing a room.');
        }

        $rooms = array_values(array_filter(
            HoGetAvailableHotelRooms($pdo, $hoHotelResortId, $checkin, $checkout, $capacityGuests),
            static fn(array $room): bool => !empty($room['is_available'])
        ));
        echo json_encode([
            'success' => true,
            'rooms' => array_map(static fn(array $room): array => [
                'id' => (int)$room['id'],
                'name' => (string)$room['room_name'],
                'price' => (float)$room['price'],
                'capacity' => HoRoomCapacityTotal($room),
                'adultCapacity' => (int)$room['capacity_adults'],
                'childCapacity' => (int)$room['capacity_children'],
            ], $rooms),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    } catch (Throwable $e) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_INVALID_UTF8_SUBSTITUTE);
    }
    exit;
}

/* =========================================================
   HOTEL BILLING DETAILS / EARLY BALANCE PAYMENT
========================================================= */
if (isset($_GET['ho_action']) && $_GET['ho_action'] === 'billing_details') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    try {
        $bookingId = (int)($_GET['booking_id'] ?? 0);
        if ($bookingId <= 0) throw new RuntimeException('Invalid booking reference.');

        $billingStmt = $pdo->prepare("
            SELECT b.*, h.name AS hotel_name,
                   TRIM(CONCAT(COALESCE(b.first_name, ''), ' ', COALESCE(b.last_name, ''))) AS guest_name
            FROM hotel_room_bookings b
            LEFT JOIN hotel_resorts h ON h.hotel_resort_id = b.hotel_resort_id
            WHERE b.hotel_booking_id = ? AND b.hotel_resort_id = ?
            LIMIT 1
        ");
        $billingStmt->execute([$bookingId, $hoHotelResortId]);
        $booking = $billingStmt->fetch(PDO::FETCH_ASSOC);
        if (!$booking) throw new RuntimeException('Billing details could not be found.');

        $expenseStmt = $pdo->prepare("
            SELECT expense_type, expense_name, quantity, unit_price, total_amount, notes, created_at
            FROM hotel_booking_expenses
            WHERE hotel_booking_id = ? AND hotel_resort_id = ?
            ORDER BY created_at ASC
        ");
        $expenseStmt->execute([$bookingId, $hoHotelResortId]);

        echo json_encode([
            'success' => true,
            'booking' => $booking,
            'expenses' => $expenseStmt->fetchAll(PDO::FETCH_ASSOC) ?: [],
        ], JSON_INVALID_UTF8_SUBSTITUTE);
    } catch (Throwable $e) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_INVALID_UTF8_SUBSTITUTE);
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['ho_action'] ?? '') === 'pay_balance') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        if (!hash_equals($hoPayMongoCsrf, (string)($_POST['csrf_token'] ?? ''))) {
            throw new RuntimeException('Your payment session expired. Refresh the page and try again.');
        }
        $bookingId = ItourValidationInt($_POST['booking_id'] ?? null, 'Booking ID', 1, PHP_INT_MAX);
        $amount = ItourValidationMoney($_POST['amount'] ?? null, 'Payment amount', 10000000.00, false);
        $paymentMethod = strtolower(trim((string)($_POST['payment_method'] ?? '')));
        if ($bookingId <= 0 || $amount <= 0) throw new RuntimeException('Enter a valid payment amount.');
        if ($paymentMethod !== 'cash') throw new RuntimeException('QR Code payments must be verified through PayMongo.');

        $pdo->beginTransaction();
        $paymentStmt = $pdo->prepare("
            SELECT hotel_booking_id, tourist_id, booking_reference, remaining_balance, amount_paid, booking_status, checked_out_at
            FROM hotel_room_bookings
            WHERE hotel_booking_id = ? AND hotel_resort_id = ?
            FOR UPDATE
        ");
        $paymentStmt->execute([$bookingId, $hoHotelResortId]);
        $booking = $paymentStmt->fetch(PDO::FETCH_ASSOC);
        if (!$booking) throw new RuntimeException('Booking not found.');

        $status = strtolower((string)($booking['booking_status'] ?? ''));
        if (in_array($status, ['completed', 'cancelled', 'no-show'], true) || !empty($booking['checked_out_at'])) {
            throw new RuntimeException('Payments can no longer be added to this booking.');
        }
        $balance = round(max(0, (float)$booking['remaining_balance']), 2);
        if ($balance <= 0) throw new RuntimeException('This booking is already fully paid.');
        if ($amount > $balance + 0.009) throw new RuntimeException('Payment cannot exceed the current balance.');

        $newBalance = round(max(0, $balance - $amount), 2);
        $newPaid = round((float)$booking['amount_paid'] + $amount, 2);
        $paymentStatus = $newBalance <= 0 ? 'paid' : 'partial';
        $updatePayment = $pdo->prepare("
            UPDATE hotel_room_bookings
            SET amount_paid = ?, remaining_balance = ?, payment_status = ?, balance_payment_method = ?, updated_at = NOW()
            WHERE hotel_booking_id = ? AND hotel_resort_id = ?
        ");
        $updatePayment->execute([$newPaid, $newBalance, $paymentStatus, $paymentMethod, $bookingId, $hoHotelResortId]);

        $cashUnique = bin2hex(random_bytes(16));
        $cashReference = 'CASH-' . date('YmdHis') . '-' . strtoupper(substr($cashUnique, 0, 8));
        $cashMetadata = json_encode([
            'source' => 'hotel_booking_payment',
            'hotel_resort_id' => $hoHotelResortId,
            'recorded_by_hotel_admin_id' => (int)$hoAdmin['hotel_admin_id'],
            'display_reference' => 'Cash',
        ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        $cashLedger = $pdo->prepare("
            INSERT INTO payment_transactions
              (tourist_id, booking_domain, booking_id, booking_reference, provider,
               merchant_reference, idempotency_key, return_token, amount_minor,
               currency, status, payment_method_type, metadata, paid_at)
            VALUES (?, 'hotel', ?, ?, 'cash', ?, ?, ?, ?, 'PHP', 'paid', 'cash', ?, NOW())
        ");
        $cashLedger->execute([
            (int)$booking['tourist_id'],
            $bookingId,
            (string)$booking['booking_reference'],
            $cashReference,
            'hotel-cash:' . $cashUnique,
            hash('sha256', $cashUnique . random_bytes(8)),
            (int)round($amount * 100),
            $cashMetadata,
        ]);
        $pdo->commit();

        logActivity(
            $pdo, 'Hotel Owner', (int)$hoAdmin['hotel_admin_id'], (string)$hoOwnerName,
            'Hotel Booking Payment Recorded',
            'Recorded an early balance payment of PHP ' . number_format($amount, 2) . ' for hotel booking #' . $bookingId . '.',
            'Hotel Bookings', $bookingId
        );
        echo json_encode([
            'success' => true,
            'amount_paid' => $newPaid,
            'remaining_balance' => $newBalance,
            'payment_status' => $paymentStatus,
        ]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_INVALID_UTF8_SUBSTITUTE);
    }
    exit;
}

/* =========================================================
   STAFF / WALK-IN BOOKING
========================================================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_walkin_booking'])) {
    $walkinAjax = ($_POST['walkin_ajax'] ?? '') === '1';
    $guestMode = strtolower(trim((string)($_POST['walkin_guest_mode'] ?? 'existing')));
    $touristId = 0;
    $firstName = trim((string)($_POST['walkin_first_name'] ?? ''));
    $lastName = trim((string)($_POST['walkin_last_name'] ?? ''));
    $email = trim((string)($_POST['walkin_email'] ?? ''));
    $phone = trim((string)($_POST['walkin_phone'] ?? ''));
    $roomId = 0;
    $checkin = trim((string)($_POST['walkin_checkin'] ?? ''));
    $checkout = trim((string)($_POST['walkin_checkout'] ?? ''));
    $adults = 1;
    $children = 0;
    $childAges = [];
    $paymentOption = strtolower(trim((string)($_POST['walkin_payment_option'] ?? 'full')));
    $paymentMethod = strtolower(trim((string)($_POST['walkin_payment_method'] ?? '')));
    $partialAmount = 0.0;
    $specialRequest = trim((string)($_POST['walkin_special_request'] ?? ''));
    $submittedCsrf = (string)($_POST['walkin_csrf'] ?? '');
    $walkinError = hash_equals($hoWalkinCsrf, $submittedCsrf)
        ? ''
        : 'Your booking form session expired. Please reopen the form and try again.';

    if ($walkinError === '') {
        try {
            if ($guestMode === 'existing') $touristId = ItourValidationInt($_POST['walkin_tourist_id'] ?? null, 'Tourist ID', 1, PHP_INT_MAX);
            $roomId = ItourValidationInt($_POST['walkin_room_id'] ?? null, 'Room ID', 1, PHP_INT_MAX);
            $checkin = ItourValidationDate($_POST['walkin_checkin'] ?? null, 'Check-in date');
            $checkout = ItourValidationDate($_POST['walkin_checkout'] ?? null, 'Check-out date');
            $adults = ItourValidationInt($_POST['walkin_adults'] ?? null, 'Adults', 1, 100);
            $children = ItourValidationInt($_POST['walkin_children'] ?? 0, 'Children', 0, 100);
            if ($adults + $children > 100) throw new InvalidArgumentException('A maximum of 100 guests is allowed.');
            if (!is_array($_POST['walkin_child_ages'] ?? [])) throw new InvalidArgumentException('Child ages must be submitted as a list.');
            $childAgesRaw = array_values($_POST['walkin_child_ages'] ?? []);
            if (count($childAgesRaw) !== $children) throw new InvalidArgumentException('Provide one age for every child.');
            foreach ($childAgesRaw as $index => $age) $childAges[] = ItourValidationInt($age, 'Child age ' . ($index + 1), 0, 17);
            $partialAmount = ItourValidationMoney($_POST['walkin_partial_amount'] ?? 0, 'Payment amount');
        } catch (InvalidArgumentException $exception) {
            $walkinError = $exception->getMessage();
        }
    }

    $isValidDate = static function (string $value): bool {
        try { ItourValidationDate($value, 'Date'); return true; } catch (InvalidArgumentException) { return false; }
    };

    if (!in_array($guestMode, ['existing', 'new'], true)) {
        $walkinError = 'Select an existing tourist or a new walk-in guest.';
    }

    if ($walkinError === '' && $guestMode === 'existing') {
        $touristStmt = $pdo->prepare("
            SELECT tourist_id, full_name, email, phone_number, profile_picture, google_id
            FROM tourist
            WHERE tourist_id = ? AND status = 'active'
            LIMIT 1
        ");
        $touristStmt->execute([$touristId]);
        $selectedTourist = $touristStmt->fetch(PDO::FETCH_ASSOC);
        if (!$selectedTourist) {
            $walkinError = 'Search for and select an active tourist account.';
        } else {
            $fullName = trim((string)$selectedTourist['full_name']);
            $nameParts = preg_split('/\s+/', $fullName, 2) ?: [];
            $firstName = trim((string)($nameParts[0] ?? ''));
            $lastName = trim((string)($nameParts[1] ?? ''));
            if ($lastName === '') {
                $lastName = '-';
            }
            $email = trim((string)$selectedTourist['email']);
            $phone = trim((string)($_POST['walkin_existing_phone'] ?? ''));
            if ($phone === '') {
                $phone = trim((string)($selectedTourist['phone_number'] ?? ''));
            }
        }
    }

    if ($walkinError === '' && $guestMode === 'new' && ($firstName === '' || $lastName === '')) {
        $walkinError = 'Enter the walk-in guest’s first and last name.';
    } elseif ($walkinError === '' && $guestMode === 'new' && $email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $walkinError = 'Enter a valid guest email address or leave it blank.';
    } elseif ($walkinError === '' && $phone === '') {
        $walkinError = 'Enter the guest’s contact number.';
    } elseif ($walkinError === '' && (!$isValidDate($checkin) || !$isValidDate($checkout) || strtotime($checkout) <= strtotime($checkin))) {
        $walkinError = 'Check-out must be after the selected check-in date.';
    } elseif ($walkinError === '' && $checkin < date('Y-m-d')) {
        $walkinError = 'Check-in cannot be earlier than today.';
    } elseif ($walkinError === '' && count($childAges) !== $children) {
        $walkinError = 'Enter the age of every child.';
    } elseif ($walkinError === '' && array_filter($childAgesRaw, static fn($age): bool => filter_var(
        $age,
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 0, 'max_range' => 17]]
    ) === false)) {
        $walkinError = 'Each child age must be between 0 and 17.';
    } elseif ($walkinError === '' && !in_array($paymentOption, ['full', 'partial', 'unpaid'], true)) {
        $walkinError = 'Select a valid payment option.';
    } elseif ($walkinError === '' && $paymentOption !== 'unpaid' && !in_array($paymentMethod, ['cash', 'qr_code'], true)) {
        $walkinError = 'Select Cash or QR Code (PayMongo) for the payment received.';
    }

    $selectedRoom = $walkinError === '' && $roomId > 0
        ? HoGetHotelRoomById($pdo, $hoHotelResortId, $roomId, true)
        : null;
    if ($walkinError === '' && !$selectedRoom) {
        $walkinError = 'Select an active room for this booking.';
    }

    if ($walkinError === '' && $selectedRoom) {
        $chargeableChildren = count(array_filter($childAges, static fn(int $age): bool => $age >= 8));
        $capacityGuests = $adults + $chargeableChildren;
        if ($capacityGuests > HoRoomCapacityTotal($selectedRoom)) {
            $walkinError = 'The selected room cannot accommodate this number of guests.';
        } elseif (!HoIsHotelRoomAvailable(
            $pdo,
            $hoHotelResortId,
            $roomId,
            (string)$selectedRoom['room_name'],
            $checkin,
            $checkout
        )) {
            $walkinError = 'The selected room is no longer available for these dates.';
        }
    }

    if ($walkinError === '' && $selectedRoom) {
        $checkinDate = new DateTime($checkin);
        $checkoutDate = new DateTime($checkout);
        $nights = max(1, (int)$checkinDate->diff($checkoutDate)->days);
        $unitPrice = max(0, (float)$selectedRoom['price']);
        $totalAmount = round($unitPrice * $nights, 2);
        $amountPaid = match ($paymentOption) {
            'full' => $totalAmount,
            'partial' => round($partialAmount, 2),
            default => 0.0,
        };

        if ($paymentOption === 'partial' && ($amountPaid <= 0 || $amountPaid >= $totalAmount)) {
            $walkinError = 'Enter a partial payment greater than zero and lower than the booking total.';
        }
    }

    if ($walkinError !== '') {
        if ($walkinAjax) {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => $walkinError], JSON_INVALID_UTF8_SUBSTITUTE);
            exit;
        }
        $_SESSION['ho_walkin_error'] = $walkinError;
        $_SESSION['ho_walkin_form'] = $_POST;
        header('Location: Hobookings.php?add_booking=1');
        exit;
    }

    try {
        $pdo->beginTransaction();
        $checkoutAmount = $paymentMethod === 'qr_code' ? $amountPaid : 0.0;
        $recordedAmountPaid = $paymentMethod === 'qr_code' ? 0.0 : $amountPaid;
        $remainingBalance = max(0, round($totalAmount - $recordedAmountPaid, 2));
        $paymentStatus = $remainingBalance <= 0 ? 'paid' : ($recordedAmountPaid > 0 ? 'partial' : 'unpaid');
        $paymentType = $paymentOption === 'full' ? 'full' : 'partial';
        $bookingReference = BookingReferenceGenerate($pdo, 'hotel');
        $insert = $pdo->prepare("
            INSERT INTO hotel_room_bookings
            (booking_reference, tourist_id, hotel_resort_id, hotel_room_id, room_type, checkin_date, checkout_date, nights, rooms_booked, adults, children, first_name, last_name, email, phone_number, special_request, unit_price, total_amount, amount_paid, remaining_balance, payment_type, booking_status, payment_status, balance_payment_method)
            VALUES
            (?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'confirmed', ?, ?)
        ");
        $insert->execute([
            $bookingReference,
            $guestMode === 'existing' ? $touristId : 0,
            $hoHotelResortId,
            $roomId,
            (string)$selectedRoom['room_name'],
            $checkin,
            $checkout,
            $nights,
            $adults,
            $children,
            $firstName,
            $lastName,
            $email,
            $phone,
            $specialRequest !== '' ? $specialRequest : null,
            $unitPrice,
            $totalAmount,
            $recordedAmountPaid,
            $remainingBalance,
            $paymentType,
            $paymentStatus,
            $recordedAmountPaid > 0 ? $paymentMethod : null,
        ]);
        $newBookingId = (int)$pdo->lastInsertId();
        if ($paymentMethod === 'cash' && $recordedAmountPaid > 0) {
            $cashUnique = bin2hex(random_bytes(16));
            $cashMetadata = json_encode([
                'source' => 'hotel_booking_payment',
                'hotel_resort_id' => $hoHotelResortId,
                'recorded_by_hotel_admin_id' => (int)$hoAdmin['hotel_admin_id'],
                'display_reference' => 'Cash',
            ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            $cashLedger = $pdo->prepare("
                INSERT INTO payment_transactions
                  (tourist_id, booking_domain, booking_id, booking_reference, provider,
                   merchant_reference, idempotency_key, return_token, amount_minor,
                   currency, status, payment_method_type, metadata, paid_at)
                VALUES (?, 'hotel', ?, ?, 'cash', ?, ?, ?, ?, 'PHP', 'paid', 'cash', ?, NOW())
            ");
            $cashLedger->execute([
                $guestMode === 'existing' ? $touristId : 0,
                $newBookingId,
                $bookingReference,
                'CASH-' . date('YmdHis') . '-' . strtoupper(substr($cashUnique, 0, 8)),
                'hotel-walkin-cash:' . $cashUnique,
                hash('sha256', $cashUnique . random_bytes(8)),
                (int)round($recordedAmountPaid * 100),
                $cashMetadata,
            ]);
        }
        $pdo->commit();
        logActivity(
            $pdo,
            'Hotel Owner',
            (int)$hoAdmin['hotel_admin_id'],
            (string)$hoOwnerName,
            'Walk-in Hotel Booking Created',
            'Created ' . $bookingReference . ' for ' . trim($firstName . ' ' . $lastName) . ' in ' . (string)$selectedRoom['room_name'] . '.',
            'Hotel Bookings',
            $newBookingId
        );
        unset($_SESSION['ho_walkin_error'], $_SESSION['ho_walkin_form'], $_SESSION['ho_walkin_booking_csrf']);
        if ($paymentMethod === 'qr_code' && $checkoutAmount > 0 && $walkinAjax) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'success' => true,
                'booking_id' => $newBookingId,
                'booking_reference' => $bookingReference,
                'checkout_amount' => $checkoutAmount,
            ]);
            exit;
        }
        header('Location: Hobookings.php?action_notice=walkin-booking-created&booking_ref=' . rawurlencode($bookingReference));
        exit;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ($walkinAjax) {
            error_log('Walk-in hotel booking creation failed: ' . $e->getMessage());
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'The booking could not be created. Please review the details and try again.']);
            exit;
        }
        $_SESSION['ho_walkin_error'] = 'The booking could not be created. Please review the details and try again.';
        $_SESSION['ho_walkin_form'] = $_POST;
        header('Location: Hobookings.php?add_booking=1');
        exit;
    }
}

/* =========================================================
   BOOKING ACTION HANDLER (WITH SMTP EMAIL CONFIRMATION)
========================================================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'], $_POST['booking_id'])) {
    if (!AppVerifyCsrf('hotel_admin', 'booking_management', $_POST['csrf_token'] ?? null)) {
        http_response_code(403);
        exit('Invalid security token. Refresh the page and try again.');
    }

    try {
        $bookingId = ItourValidationInt($_POST['booking_id'], 'Booking ID', 1, PHP_INT_MAX);
    } catch (InvalidArgumentException) {
        $bookingId = 0;
    }
    $action = (string)$_POST['action'];
    $isAjaxBookingAction = (string)($_POST['ajax_booking_action'] ?? '') === '1'
        || strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';
    $ajaxResponseBufferLevel = ob_get_level();
    if ($isAjaxBookingAction) {
        ob_start();
    }

    $actionNotice = 'action-failed';
    $emailStatus = 'not_sent';

    if ($bookingId > 0 && in_array($action, ['confirm', 'cancel', 'no_show'], true)) {

        $newStatus = $action === 'confirm'
            ? 'confirmed'
            : ($action === 'cancel' ? 'cancelled' : 'no-show');

        $stateStmt = $pdo->prepare('SELECT booking_status FROM hotel_room_bookings WHERE hotel_booking_id = ? AND hotel_resort_id = ? LIMIT 1');
        $stateStmt->execute([$bookingId, $hoHotelResortId]);
        $currentStatus = strtolower((string)$stateStmt->fetchColumn());
        $allowedCurrentStatuses = [
            'confirm' => ['pending'],
            'cancel' => ['pending', 'confirmed'],
            'no_show' => ['confirmed'],
        ];
        if (!in_array($currentStatus, $allowedCurrentStatuses[$action] ?? [], true)) {
            $actionNotice = 'booking-not-updated';
        } else {
        $stmt = $pdo->prepare("
            UPDATE hotel_room_bookings
            SET booking_status = ?, updated_at = NOW()
            WHERE hotel_booking_id = ? AND hotel_resort_id = ? AND LOWER(booking_status) = ?
        ");
        $stmt->execute([$newStatus, $bookingId, $hoHotelResortId, $currentStatus]);

        if ($stmt->rowCount() > 0) {
            $hotelActionLabels = [
                'confirm' => 'Hotel Booking Accepted',
                'cancel' => 'Hotel Booking Rejected',
                'no_show' => 'Hotel Booking No-show'
            ];
            logActivity(
                $pdo, 'Hotel Owner', (int)$hoAdmin['hotel_admin_id'], (string)$hoOwnerName,
                $hotelActionLabels[$action],
                $hotelActionLabels[$action] . ' for booking #' . $bookingId . '.',
                'Hotel Bookings', $bookingId
            );

            $email = trim((string)($_POST['email'] ?? ''));

            /* =========================
               EMAIL ONLY ON CONFIRM
            ========================= */
            if ($action === 'confirm') {

    if ($email === '') {
        error_log("EMAIL ERROR: Empty email for booking $bookingId");
        $emailStatus = 'no_email';

    } else {

        /* =========================
           GET BOOKING DETAILS (SAFE WAY)
        ========================= */
        $stmt = $pdo->prepare("
            SELECT b.*, h.name AS hotel_name
            FROM hotel_room_bookings b
            LEFT JOIN hotel_resorts h ON h.hotel_resort_id = b.hotel_resort_id
            WHERE b.hotel_booking_id = ? AND b.hotel_resort_id = ?
        ");
        $stmt->execute([$bookingId, $hoHotelResortId]);
        $b = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$b) {
            error_log("EMAIL ERROR: Booking not found $bookingId");
            $emailStatus = 'failed';

        } else {
            $bookingReference = trim((string)($b['booking_reference'] ?? '')) ?: (string)$bookingId;

            $mail = new PHPMailer(true);

            try {

                // =========================
                // SMTP DEBUG
                // =========================
                $mail->SMTPDebug = 0;
                $mail->Debugoutput = 'error_log';

                // =========================
                // SMTP CONFIG
                // =========================
                $mail->isSMTP();
                $mail->Host       = 'smtp.gmail.com';
                $mail->SMTPAuth   = true;
                $mail->Username   = 'itourmercedes@gmail.com';
                $mail->Password   = PaymentHelper::env('SMTP_PASSWORD');
                if ($mail->Password === '') {
                    throw new RuntimeException('SMTP_PASSWORD is not configured.');
                }
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                $mail->Port       = 587;
                $mail->Timeout    = 45;
                $mail->Timelimit  = 60;

                $mail->SMTPOptions = [
                    'ssl' => [
                        'verify_peer' => false,
                        'verify_peer_name' => false,
                        'allow_self_signed' => true,
                    ],
                ];

                // =========================
                // SENDER
                // =========================
                $mail->setFrom(
                    'itourmercedes@gmail.com',
                    'iTour Mercedes'
                );

                $guestName = trim((string)($b['first_name'] ?? '') . ' ' . (string)($b['last_name'] ?? ''));
                $mail->CharSet = 'UTF-8';
                $mail->addReplyTo('itourmercedes@gmail.com', 'iTour Mercedes');
                $mail->addAddress($email, $guestName);

                // =========================
                // EMAIL SUBJECT
                // =========================
                $mail->isHTML(true);
                $mail->Subject = "iTour Mercedes | Hotel / Resort Reservation Confirmed ($bookingReference)";

                // =========================
                // EMAIL BODY (PROFESSIONAL DESIGN)
                // =========================
                $mail->Body = "
                <!DOCTYPE html>
                <html>
                <head>
                <meta charset='UTF-8'>
                <style>
                    body { font-family: Arial; background:#f4f6f8; margin:0; padding:0; }
                    .container { max-width:600px; margin:30px auto; background:#fff; border-radius:10px; overflow:hidden; }
                    .header { background:#2b7a66; color:#fff; text-align:center; padding:20px; }
                    .header h1 { margin:0; font-size:20px; }
                    .content { padding:25px; }
                    .status { background:#d1fae5; color:#065f46; padding:6px 12px; display:inline-block; border-radius:20px; font-weight:bold; }
                    table { width:100%; margin-top:15px; border-collapse:collapse; }
                    td { padding:10px; border-bottom:1px solid #eee; font-size:14px; }
                    td:first-child { font-weight:bold; width:40%; }
                    .footer { text-align:center; padding:15px; font-size:12px; color:#777; background:#f0f0f0; }
                </style>
                </head>

                <body>
                <div class='container'>

                    <div class='header'>
                        <h1>iTour Mercedes Booking Confirmation</h1>
                    </div>

                    <div class='content'>

                        <div class='status'>CONFIRMED</div>

                        <p>Dear {$b['first_name']} {$b['last_name']},</p>

                        <p>Your booking has been successfully confirmed. Below are your details:</p>

                        <table>
                            <tr><td>Booking Reference</td><td>{$bookingReference}</td></tr>
                            <tr><td>Room Type</td><td>{$b['room_type']}</td></tr>
                            <tr><td>Check-in</td><td>{$b['checkin_date']}</td></tr>
                            <tr><td>Check-out</td><td>{$b['checkout_date']}</td></tr>
                            <tr><td>Guests</td><td>{$b['adults']} Adults / {$b['children']} Children</td></tr>
                            <tr><td>Total Amount</td><td>₱" . number_format((float)$b['total_amount'], 2) . "</td></tr>
                            <tr><td>Payment Status</td><td>{$b['payment_status']}</td></tr>
                        </table>

                        <p style='margin-top:20px;'>
                            Thank you for choosing <b>iTour Mercedes</b>. We look forward to serving you.
                        </p>

                    </div>

                    <div class='footer'>
                        © " . date('Y') . " iTour Mercedes. All rights reserved.
                    </div>

                </div>
                </body>
                </html>
                ";

                $guestSummary = (int)($b['adults'] ?? 0) . ' adult'
                    . ((int)($b['adults'] ?? 0) === 1 ? '' : 's')
                    . ' / ' . (int)($b['children'] ?? 0) . ' child'
                    . ((int)($b['children'] ?? 0) === 1 ? '' : 'ren');
                $paymentTypeLabel = strtolower((string)($b['payment_type'] ?? '')) === 'partial'
                    ? 'Partial payment / deposit'
                    : 'Full payment';
                $paymentStatusLabel = ucwords(str_replace(['_', '-'], ' ', (string)($b['payment_status'] ?? '')));
                $paymentChannel = ucwords(str_replace(['_', '-'], ' ', (string)($b['balance_payment_method'] ?? 'Online payment')));
                $mail->Body = BookingConfirmationEmailTemplate(array_merge([
                    'guest_name' => $guestName,
                    'service_label' => 'Hotel / Resort Reservation',
                    'booking_reference' => $bookingReference,
                    'intro' => 'Your accommodation reservation has been reviewed and confirmed by the property through iTour Mercedes.',
                    'details' => [
                        'Property' => (string)($b['hotel_name'] ?? $hoPropertyName),
                        'Room type' => (string)($b['room_type'] ?? ''),
                        'Check-in date' => BookingConfirmationEmailDate($b['checkin_date'] ?? ''),
                        'Check-out date' => BookingConfirmationEmailDate($b['checkout_date'] ?? ''),
                        'Number of nights' => (string)max(1, (int)($b['nights'] ?? 1)),
                        'Rooms reserved' => (string)max(1, (int)($b['rooms_booked'] ?? 1)),
                        'Guests' => $guestSummary,
                        'Guest contact' => (string)($b['phone_number'] ?? ''),
                        'Special request' => (string)($b['special_request'] ?? ''),
                    ],
                    'payment_details' => [
                        'Total reservation amount' => BookingConfirmationEmailMoney($b['total_amount'] ?? 0),
                        'Amount paid' => BookingConfirmationEmailMoney($b['amount_paid'] ?? 0),
                        'Remaining balance' => BookingConfirmationEmailMoney($b['remaining_balance'] ?? 0),
                        'Payment option' => $paymentTypeLabel,
                        'Payment status' => $paymentStatusLabel,
                        'Payment channel' => $paymentChannel,
                    ],
                    'important_note' => 'Please bring a valid ID and this confirmation when checking in. Contact the property ahead of arrival for special requests, exact check-in instructions, or schedule changes.',
                ], BookingConfirmationEmailBranding($mail)));
                $mail->AltBody = "Your iTour Mercedes hotel/resort reservation $bookingReference has been confirmed. Check-in: "
                    . BookingConfirmationEmailDate($b['checkin_date'] ?? '') . '; Check-out: '
                    . BookingConfirmationEmailDate($b['checkout_date'] ?? '') . '.';

                // =========================
                // SEND EMAIL
                // =========================
                if (BookingConfirmationEmailSend($mail)) {
                    $emailStatus = 'sent';
                } else {
                    $emailStatus = 'failed';
                    error_log("EMAIL FAILED booking $bookingId");
                }

            } catch (Throwable $e) {
                $emailStatus = 'failed';
                error_log("BOOKING EMAIL ERROR (booking $bookingId): " . $e->getMessage());
                if (trim((string)($mail->ErrorInfo ?? '')) !== '') {
                    error_log("PHPMailer detail: " . $mail->ErrorInfo);
                }
            }
        }
    }

            }

            $actionNotice = $action === 'confirm'
                ? 'booking-confirmed'
                : ($action === 'cancel' ? 'booking-cancelled' : 'booking-noshow');

        } else {
            $actionNotice = 'booking-not-updated';
        }
        }

    } else {
        $actionNotice = 'invalid-action';
    }

    if ($isAjaxBookingAction) {
        $bookingConfirmed = $actionNotice === 'booking-confirmed';
        $responseMessages = [
            'sent' => 'The booking was confirmed and the confirmation email was sent successfully.',
            'failed' => 'The booking was confirmed, but the confirmation email could not be sent.',
            'no_email' => 'The booking was confirmed, but no guest email address was provided.',
        ];

        while (ob_get_level() > $ajaxResponseBufferLevel) {
            ob_end_clean();
        }
        header('Content-Type: application/json; charset=utf-8');
        http_response_code($bookingConfirmed ? 200 : 422);
        echo json_encode([
            'success' => $bookingConfirmed,
            'booking_confirmed' => $bookingConfirmed,
            'email_status' => $emailStatus,
            'message' => $bookingConfirmed
                ? ($responseMessages[$emailStatus] ?? 'The booking was confirmed.')
                : ($actionNotice === 'booking-not-updated'
                    ? 'No booking record was updated. It may already be confirmed.'
                    : 'The booking could not be confirmed.'),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    /* =========================
       REDIRECT WITH ALERT DATA
    ========================= */
    $redirectQuery = http_build_query([
        'status' => $_GET['status'] ?? 'all',
        'q' => $_GET['q'] ?? '',
        'range' => $_GET['range'] ?? 'all',
        'year' => $_GET['year'] ?? date('Y'),
        'month' => $_GET['month'] ?? date('n'),
        'date' => $_GET['date'] ?? date('Y-m-d'),
        'sort' => $_GET['sort'] ?? 'time',
        'rows' => $_GET['rows'] ?? '25',
        'page' => $_GET['page'] ?? '1',
        'action_notice' => $actionNotice,
        'email_status' => $emailStatus
    ]);

    header('Location: Hobookings.php' . ($redirectQuery ? '?' . $redirectQuery : ''));
    exit;
}
/* =========================================================
   ADD EXTRA EXPENSES (FIXED MULTI-ROW VERSION)
========================================================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_expense'])) {

    if (!AppVerifyCsrf('hotel_admin', 'booking_management', $_POST['csrf_token'] ?? null)) {
        http_response_code(403);
        exit('Invalid security token. Refresh the page and try again.');
    }

    try {
        $bookingId = ItourValidationInt($_POST['booking_id'] ?? null, 'Booking ID', 1, PHP_INT_MAX);
    } catch (InvalidArgumentException) {
        $bookingId = 0;
    }

    // ✅ arrays (multiple rows support)
    $expenseTypes = $_POST['expense_type'] ?? [];
    $expenseNames = $_POST['expense_name'] ?? [];
    $quantities   = $_POST['quantity'] ?? [];
    $unitPrices   = $_POST['unit_price'] ?? [];
    $notesArr     = $_POST['notes'] ?? [];

    $validatedExpenses = [];
    try {
        $lists = [$expenseTypes, $expenseNames, $quantities, $unitPrices, $notesArr];
        foreach ($lists as $list) {
            if (!is_array($list)) throw new InvalidArgumentException('Expense fields must be submitted as lists.');
        }
        $rowCount = count($expenseNames);
        if ($rowCount < 1 || $rowCount > 25) throw new InvalidArgumentException('Add between 1 and 25 expense rows.');
        foreach ($lists as $list) {
            if (count($list) !== $rowCount) throw new InvalidArgumentException('Expense fields must have matching row counts.');
        }
        $allowedExpenseTypes = ['Room Upgrade', 'Additional Room', 'Activity', 'Cottage', 'Food', 'Others'];
        foreach ($expenseNames as $index => $_unused) {
            $type = ItourValidationText($expenseTypes[$index], 'Expense type', 40, true);
            if (!in_array($type, $allowedExpenseTypes, true)) throw new InvalidArgumentException('Invalid expense type.');
            $name = ItourValidationText($expenseNames[$index], 'Expense description', 160, true);
            $qty = ItourValidationInt($quantities[$index], 'Expense quantity', 1, 1000);
            $price = ItourValidationMoney($unitPrices[$index], 'Expense unit price', 10000000.00, false);
            $note = ItourValidationText($notesArr[$index], 'Expense note', 500);
            $validatedExpenses[] = compact('type', 'name', 'qty', 'price', 'note');
        }
    } catch (InvalidArgumentException) {
        $bookingId = 0;
    }

    $totalExpense = 0;

    if ($bookingId > 0 && !empty($expenseNames)) {

        $pdo->beginTransaction();

        try {

            $insertExpense = $pdo->prepare("
                INSERT INTO hotel_booking_expenses (
                    hotel_booking_id,
                    hotel_resort_id,
                    expense_type,
                    expense_name,
                    quantity,
                    unit_price,
                    total_amount,
                    notes
                )
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");

            foreach ($validatedExpenses as $expense) {
                $type = $expense['type'];
                $name = $expense['name'];
                $qty = $expense['qty'];
                $price = $expense['price'];
                $note = $expense['note'];

                $rowTotal = $qty * $price;
                $totalExpense += $rowTotal;

                $insertExpense->execute([
                    $bookingId,
                    $hoHotelResortId,
                    $type,
                    $name,
                    $qty,
                    $price,
                    $rowTotal,
                    $note
                ]);
            }

            $stmt = $pdo->prepare("
                SELECT total_amount, amount_paid, booking_status
                FROM hotel_room_bookings
                WHERE hotel_booking_id = ? AND hotel_resort_id = ?
                FOR UPDATE
            ");
            $stmt->execute([$bookingId, $hoHotelResortId]);
            $booking = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$booking) {
                throw new RuntimeException('Booking not found.');
            }
            $expenseBookingStatus = strtolower((string)($booking['booking_status'] ?? ''));
            if (in_array($expenseBookingStatus, ['completed', 'cancelled', 'no-show'], true)) {
                throw new RuntimeException('Expenses can no longer be added to this booking.');
            }
            if ($totalExpense <= 0) {
                throw new RuntimeException('Add at least one valid expense.');
            }

            $currentTotal = (float)$booking['total_amount'];
            $amountPaid   = (float)$booking['amount_paid'];

            $newTotal = $currentTotal + $totalExpense;
            $newRemaining = max(0, $newTotal - $amountPaid);

            // update booking totals
            $updateBooking = $pdo->prepare("
                UPDATE hotel_room_bookings
                SET
                    total_amount = ?,
                    remaining_balance = ?,
                    payment_status = ?,
                    updated_at = NOW()
                WHERE hotel_booking_id = ?
                AND hotel_resort_id = ?
            ");

            $updateBooking->execute([
                $newTotal,
                $newRemaining,
                $newRemaining <= 0 ? 'paid' : ($amountPaid > 0 ? 'partial' : 'unpaid'),
                $bookingId,
                $hoHotelResortId
            ]);

            $pdo->commit();
            $actionNotice = 'expense-added';
            logActivity(
                $pdo, 'Hotel Owner', (int)$hoAdmin['hotel_admin_id'], (string)$hoOwnerName,
                'Hotel Booking Expense Added',
                'Added expenses to hotel booking #' . $bookingId . '.',
                'Hotel Bookings', $bookingId
            );

        } catch (Exception $e) {
            $pdo->rollBack();
            $actionNotice = 'expense-failed';
        }

    } else {
        $actionNotice = 'expense-invalid';
    }

    $redirectQuery = http_build_query([
        'status' => $_GET['status'] ?? 'all',
        'q' => $_GET['q'] ?? '',
        'range' => $_GET['range'] ?? 'all',
        'year' => $_GET['year'] ?? date('Y'),
        'month' => $_GET['month'] ?? date('n'),
        'date' => $_GET['date'] ?? date('Y-m-d'),
        'sort' => $_GET['sort'] ?? 'time',
        'rows' => $_GET['rows'] ?? '25',
        'page' => $_GET['page'] ?? '1',
        'action_notice' => $actionNotice,
    ]);

    header('Location: Hobookings.php?' . $redirectQuery);
    exit;
}

$actionNotice = strtolower(trim((string)($_GET['action_notice'] ?? '')));
$actionNoticeText = '';
$actionNoticeIcon = '';
switch ($actionNotice) {
    case 'walkin-booking-created':
        $createdReference = trim((string)($_GET['booking_ref'] ?? ''));
        $actionNoticeText = $createdReference !== ''
            ? 'Walk-in booking ' . $createdReference . ' was created and confirmed.'
            : 'Walk-in booking was created and confirmed.';
        $actionNoticeIcon = 'success';
        break;
    case 'booking-confirmed':
        $redirectEmailStatus = strtolower(trim((string)($_GET['email_status'] ?? '')));
        if ($redirectEmailStatus === 'sent') {
            $actionNoticeText = 'The booking was confirmed and the confirmation email was sent successfully.';
            $actionNoticeIcon = 'success';
        } elseif ($redirectEmailStatus === 'no_email') {
            $actionNoticeText = 'The booking was confirmed, but no guest email address was provided.';
            $actionNoticeIcon = 'warning';
        } elseif ($redirectEmailStatus === 'failed') {
            $actionNoticeText = 'The booking was confirmed, but the confirmation email could not be sent.';
            $actionNoticeIcon = 'warning';
        } else {
            $actionNoticeText = 'Booking has been confirmed.';
            $actionNoticeIcon = 'success';
        }
        break;
    case 'booking-cancelled':
        $actionNoticeText = 'Booking has been cancelled.';
        $actionNoticeIcon = 'success';
        break;
    case 'booking-noshow':
        $actionNoticeText = 'Booking has been marked as no-show.';
        $actionNoticeIcon = 'success';
        break;
    case 'booking-not-updated':
        $actionNoticeText = 'No booking record was updated.';
        $actionNoticeIcon = 'error';
        break;
    case 'invalid-action':
        $actionNoticeText = 'Invalid booking action.';
        $actionNoticeIcon = 'error';
        break;
    case 'action-failed':
        $actionNoticeText = 'Unable to process booking action.';
        $actionNoticeIcon = 'error';
        break;
    case 'expense-added':
        $actionNoticeText = 'Additional expense added successfully.';
        $actionNoticeIcon = 'success';
        break;

    case 'expense-failed':
        $actionNoticeText = 'Failed to add expense.';
        $actionNoticeIcon = 'error';
        break;

    case 'expense-invalid':
        $actionNoticeText = 'Please complete all expense fields.';
        $actionNoticeIcon = 'warning';
        break;
}

$walkinRooms = HoGetHotelRooms($pdo, $hoHotelResortId, true);
$walkinForm = is_array($_SESSION['ho_walkin_form'] ?? null) ? $_SESSION['ho_walkin_form'] : [];
$walkinError = trim((string)($_SESSION['ho_walkin_error'] ?? ''));
$openWalkinModal = isset($_GET['add_booking']) || $walkinError !== '';
unset($_SESSION['ho_walkin_form'], $_SESSION['ho_walkin_error']);
$walkinGuestMode = in_array((string)($walkinForm['walkin_guest_mode'] ?? 'existing'), ['existing', 'new'], true)
    ? (string)($walkinForm['walkin_guest_mode'] ?? 'existing')
    : 'existing';
$walkinSelectedTourist = null;
$savedWalkinTouristId = max(0, (int)($walkinForm['walkin_tourist_id'] ?? 0));
if ($savedWalkinTouristId > 0) {
    $savedTouristStmt = $pdo->prepare("
        SELECT tourist_id, full_name, email, phone_number, profile_picture, google_id
        FROM tourist
        WHERE tourist_id = ? AND status = 'active'
        LIMIT 1
    ");
    $savedTouristStmt->execute([$savedWalkinTouristId]);
    $walkinSelectedTourist = $savedTouristStmt->fetch(PDO::FETCH_ASSOC) ?: null;
}
$walkinSelectedProfileImage = '';
if ($walkinSelectedTourist) {
    $walkinSelectedProfileImage = HoResolveProfileImage((string)($walkinSelectedTourist['profile_picture'] ?? ''));
    if ($walkinSelectedProfileImage === '' && !empty($walkinSelectedTourist['google_id'])) {
        $walkinSelectedProfileImage = HoBuildGoogleAvatarUrl((string)$walkinSelectedTourist['google_id']);
    }
}
$walkinDefaultCheckin = (string)($walkinForm['walkin_checkin'] ?? date('Y-m-d'));
$walkinDefaultCheckout = (string)($walkinForm['walkin_checkout'] ?? date('Y-m-d', strtotime('+1 day')));
$walkinSavedChildAges = is_array($walkinForm['walkin_child_ages'] ?? null)
    ? array_map('intval', array_values($walkinForm['walkin_child_ages']))
    : [];

$statusFilter = strtolower(trim((string)($_GET['status'] ?? 'all')));
$search = trim((string)($_GET['q'] ?? ''));
$rangeFilter = strtolower(trim((string)($_GET['range'] ?? 'all')));
$sortBy = strtolower(trim((string)($_GET['sort'] ?? 'time')));
$rowsRaw = trim((string)($_GET['rows'] ?? '25'));
$rowsPerPage = (int)$rowsRaw;
$currentPage = max(1, (int)($_GET['page'] ?? 1));
if ($rowsPerPage < 1) {
    $rowsPerPage = 25;
}
if ($rowsPerPage > 300) {
    $rowsPerPage = 300;
}
$validStatus = ['all', 'pending', 'confirmed', 'completed', 'cancelled', 'no-show'];
if (!in_array($statusFilter, $validStatus, true)) {
    $statusFilter = 'all';
}
$validRange = ['all', 'yearly', 'monthly', 'daily'];
if (!in_array($rangeFilter, $validRange, true)) {
    $rangeFilter = 'all';
}
$validSort = ['time', 'name'];
if (!in_array($sortBy, $validSort, true)) {
    $sortBy = 'time';
}

$currentYear = (int)date('Y');
$currentMonth = (int)date('n');
$selectedYear = (int)($_GET['year'] ?? $currentYear);
if ($selectedYear < 2000 || $selectedYear > ($currentYear + 2)) {
    $selectedYear = $currentYear;
}
$selectedMonth = (int)($_GET['month'] ?? $currentMonth);
if ($selectedMonth < 1 || $selectedMonth > 12) {
    $selectedMonth = $currentMonth;
}
$selectedDate = trim((string)($_GET['date'] ?? date('Y-m-d')));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $selectedDate)) {
    $selectedDate = date('Y-m-d');
}

$availableYears = $pdo->query("
    SELECT DISTINCT YEAR(created_at) AS yr
    FROM hotel_room_bookings
    WHERE created_at IS NOT NULL AND hotel_resort_id = " . (int)$hoHotelResortId . "
    ORDER BY yr DESC
")->fetchAll(PDO::FETCH_COLUMN);
$availableYears = array_values(array_filter(array_map('intval', $availableYears)));
if (empty($availableYears)) {
    $availableYears = [$currentYear];
}
if (!in_array($selectedYear, $availableYears, true)) {
    $selectedYear = $availableYears[0];
}

$hoRangeFilter = $rangeFilter;
$hoRangeYear = $selectedYear;
$hoRangeMonth = $selectedMonth;
$hoRangeDate = $selectedDate;
$hoRangeYears = $availableYears;
$hoRangeOptions = [
    'all' => 'All Time',
    'yearly' => 'Yearly',
    'monthly' => 'Monthly',
    'daily' => 'Daily',
];
$hoRangeHiddenFields = [
    'status' => $statusFilter,
    'sort' => $sortBy,
    'q' => $search,
    'rows' => $rowsPerPage,
];

function HoResolveProfileImage(?string $profilePicture): string
{
    if (!$profilePicture) {
        return '';
    }
    if (preg_match('#^https?://#i', $profilePicture)) {
        if (stripos($profilePicture, 'profiles.google.com') !== false && preg_match('#profiles\\.google\\.com/(?:s2/photos/profile/)?([^/?#]+)(?:/picture)?#i', $profilePicture, $m)) {
            return 'https://profiles.google.com/' . rawurlencode($m[1]) . '/picture?sz=256';
        }
        if (stripos($profilePicture, 'googleusercontent.com') !== false) {
            $profilePicture = preg_replace('/([?&])sz=\\d+/i', '$1sz=256', $profilePicture);
            $profilePicture = preg_replace('/=s\\d+-c(?=$|[?&#])/i', '=s256-c', $profilePicture);
            $profilePicture = preg_replace('/=s\\d+(?=$|[?&#])/i', '=s256', $profilePicture);
        }
        return $profilePicture;
    }
    $clean = ltrim($profilePicture, '/');
    $paths = [
        'uploads/profile_pictures/' . basename($clean),
        'uploads/profile_picture/' . basename($clean),
        $clean,
    ];
    foreach ($paths as $p) {
        if (file_exists(__DIR__ . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $p))) {
            return $p;
        }
    }
    return '';
}

function HoBuildGoogleAvatarUrl(?string $googleId): string
{
    if (!$googleId) {
        return '';
    }
    return 'https://profiles.google.com/' . urlencode($googleId) . '/picture?sz=96';
}

function HoGetInitial(string $name): string
{
    $name = trim($name);
    if ($name === '') {
        return 'G';
    }
    return strtoupper(substr($name, 0, 1));
}

$where = ['b.hotel_resort_id = :hotel_resort_id'];
$params = [];
$params[':hotel_resort_id'] = $hoHotelResortId;
if ($statusFilter !== 'all') {
    $where[] = 'b.booking_status = :status';
    $params[':status'] = $statusFilter;
}
if ($rangeFilter === 'yearly') {
    $where[] = 'YEAR(b.created_at) = :selected_year';
    $params[':selected_year'] = $selectedYear;
} elseif ($rangeFilter === 'monthly') {
    $where[] = 'YEAR(b.created_at) = :selected_year AND MONTH(b.created_at) = :selected_month';
    $params[':selected_year'] = $selectedYear;
    $params[':selected_month'] = $selectedMonth;
} elseif ($rangeFilter === 'daily') {
    $where[] = 'DATE(b.created_at) = :selected_date AND YEAR(b.created_at) = :selected_year';
    $params[':selected_date'] = $selectedDate;
    $params[':selected_year'] = $selectedYear;
}
if ($search !== '') {
    $where[] = "(b.first_name LIKE :search_name OR b.last_name LIKE :search_name OR b.booking_reference LIKE :search_id OR b.hotel_booking_id LIKE :search_id)";
    $params[':search_name'] = '%' . $search . '%';
    $params[':search_id'] = '%' . $search . '%';
}

$statsWhere = ['b.hotel_resort_id = :stats_hotel_resort_id'];
$statsParams = [':stats_hotel_resort_id' => $hoHotelResortId];
if ($rangeFilter === 'yearly') {
    $statsWhere[] = 'YEAR(b.created_at) = :stats_selected_year';
    $statsParams[':stats_selected_year'] = $selectedYear;
} elseif ($rangeFilter === 'monthly') {
    $statsWhere[] = 'YEAR(b.created_at) = :stats_selected_year AND MONTH(b.created_at) = :stats_selected_month';
    $statsParams[':stats_selected_year'] = $selectedYear;
    $statsParams[':stats_selected_month'] = $selectedMonth;
} elseif ($rangeFilter === 'daily') {
    $statsWhere[] = 'DATE(b.created_at) = :stats_selected_date AND YEAR(b.created_at) = :stats_selected_year';
    $statsParams[':stats_selected_date'] = $selectedDate;
    $statsParams[':stats_selected_year'] = $selectedYear;
}
if ($search !== '') {
    $statsWhere[] = "(b.first_name LIKE :stats_search_name OR b.last_name LIKE :stats_search_name OR b.booking_reference LIKE :stats_search_id OR b.hotel_booking_id LIKE :stats_search_id)";
    $statsParams[':stats_search_name'] = '%' . $search . '%';
    $statsParams[':stats_search_id'] = '%' . $search . '%';
}
$statsWhereSql = $statsWhere ? ('WHERE ' . implode(' AND ', $statsWhere)) : '';
$statsSql = "
    SELECT
      COUNT(*) AS total_bookings,
      SUM(CASE WHEN b.booking_status = 'confirmed' THEN 1 ELSE 0 END) AS total_confirmed,
      SUM(CASE WHEN b.booking_status = 'pending' THEN 1 ELSE 0 END) AS total_pending,
      SUM(CASE WHEN b.booking_status = 'completed' THEN 1 ELSE 0 END) AS total_completed
    FROM hotel_room_bookings b
    $statsWhereSql
";
$statsStmt = $pdo->prepare($statsSql);
$statsStmt->execute($statsParams);
$statsRow = $statsStmt->fetch(PDO::FETCH_ASSOC) ?: [];
$summaryTotalBookings = (int)($statsRow['total_bookings'] ?? 0);
$summaryConfirmedBookings = (int)($statsRow['total_confirmed'] ?? 0);
$summaryPendingBookings = (int)($statsRow['total_pending'] ?? 0);
$summaryCompletedBookings = (int)($statsRow['total_completed'] ?? 0);
$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';
$orderBySql = $sortBy === 'name'
    ? 'b.last_name ASC, b.first_name ASC, b.created_at DESC'
    : 'b.created_at DESC';

$countSql = "
SELECT COUNT(*)
FROM hotel_room_bookings b
LEFT JOIN hotel_resorts h ON h.hotel_resort_id = b.hotel_resort_id
LEFT JOIN tourist t ON t.tourist_id = b.tourist_id
$whereSql
";
$countStmt = $pdo->prepare($countSql);
$countStmt->execute($params);
$totalFilteredBookings = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalFilteredBookings / $rowsPerPage));
$currentPage = min($currentPage, $totalPages);
$offset = ($currentPage - 1) * $rowsPerPage;
$limitSql = " LIMIT " . (int)$rowsPerPage . " OFFSET " . (int)$offset;
$showingFrom = $totalFilteredBookings > 0 ? $offset + 1 : 0;
$showingTo = min($offset + $rowsPerPage, $totalFilteredBookings);
$paginationParams = [
    'status' => $statusFilter,
    'q' => $search,
    'range' => $rangeFilter,
    'year' => $selectedYear,
    'month' => $selectedMonth,
    'date' => $selectedDate,
    'sort' => $sortBy,
    'rows' => $rowsPerPage,
];

$sql = "
SELECT
  b.*,
  CONCAT(b.first_name, ' ', b.last_name) AS guest_name,
  (b.adults + b.children) AS guest_count,
  h.name AS hotel_name,
  t.profile_picture AS tourist_profile_picture,
  t.google_id AS tourist_google_id,
  t.address AS tourist_address,
  t.email_verified AS tourist_email_verified,
  t.status AS tourist_account_status,
  t.created_at AS tourist_created_at,
  t.updated_at AS tourist_updated_at,
  (SELECT COUNT(*) FROM hotel_room_bookings tb WHERE tb.tourist_id = t.tourist_id) AS tourist_total_bookings,
  (SELECT COUNT(*) FROM hotel_room_bookings cb WHERE cb.tourist_id = t.tourist_id AND cb.booking_status = 'completed') AS tourist_completed_bookings
FROM hotel_room_bookings b
LEFT JOIN hotel_resorts h ON h.hotel_resort_id = b.hotel_resort_id
LEFT JOIN tourist t ON t.tourist_id = b.tourist_id
$whereSql
ORDER BY $orderBySql
$limitSql
";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$bookings = $stmt->fetchAll(PDO::FETCH_ASSOC);

$hoPendingBadge = HoGetPendingCount($pdo, $hoHotelResortId);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Hobookings | Hotel Owner Bookings</title>
  <link rel="icon" type="image/png" href="img/newlogo.png" />
  <link rel="stylesheet" href="styles/Ho_panel.css?v=notifications-4" />
  <link rel="stylesheet" href="styles/required-fields.css" />
  <script src="js/required-fields.js" defer></script>
</head>
<style>
  .ho-walkin-partial-field select{width:100%;min-height:46px;margin-top:8px;padding:10px 12px;border:1px solid #cfded8;border-radius:9px;background:#fff;color:#294b41;font-size:14px}
  .ho-balance-payment-modal{width:min(480px,100%)}
  .ho-balance-payment-modal.phone-setup{width:min(520px,100%)}
  .ho-balance-payment-body{padding:24px 26px}
  .ho-balance-payment-body>#hoBalancePaymentContext{margin:15px 0 18px;color:#617871;font-size:12.5px;line-height:1.55}
  .ho-balance-payment-field>span{margin-bottom:8px;font-size:12.5px}
  .ho-balance-payment-field>select{min-height:47px;padding:11px 13px;font-size:15px}
  .ho-balance-payment-field>div{min-height:47px}
  .ho-balance-payment-field input{font-size:15px}
  #hoQrDeviceSection[hidden]+.ho-balance-payment-field{display:block;margin-top:18px}
  .ho-qr-device{display:grid;grid-template-columns:46px minmax(0,1fr) auto;align-items:center;gap:13px;margin:14px 0 18px;padding:13px 14px;border:1px solid #cde3da;border-radius:12px;background:#f6fbf9}
  .ho-qr-device[hidden]{display:none}
  .ho-qr-device-icon{width:42px;height:42px;display:grid;place-items:center;border-radius:10px;color:#fff;background:#21765e}
  .ho-qr-device-icon svg{width:22px;height:22px;fill:none;stroke:currentColor;stroke-width:1.8}
  .ho-qr-device div{min-width:0}
  .ho-qr-device small,.ho-qr-device strong,.ho-qr-device div>span{display:block}
  .ho-qr-device small{color:#4d776b;font-size:10.5px;font-weight:900;letter-spacing:.09em}
  .ho-qr-device strong{margin:3px 0;color:#214c40;font-size:13.5px;line-height:1.25}
  .ho-qr-device div>span{overflow:hidden;color:#6c827b;font-size:11px;line-height:1.35;text-overflow:ellipsis;white-space:nowrap}
  .ho-qr-device button{min-height:38px;padding:9px 13px;border:1px solid #c7dbd4;border-radius:8px;color:#245f4f;background:#fff;font-size:11.5px;font-weight:800;cursor:pointer}
  .ho-phone-steps{display:grid;gap:16px;margin:0 0 24px;padding:0;list-style:none;counter-reset:phone-step}
  .ho-phone-steps li{display:grid;grid-template-columns:30px 1fr;column-gap:12px;row-gap:3px;counter-increment:phone-step}
  .ho-phone-steps li:before{content:counter(phone-step);grid-row:1/3;width:28px;height:28px;display:grid;place-items:center;border-radius:50%;color:#fff;background:#287a63;font-size:12px;font-weight:900}
  .ho-phone-steps b{font-size:13px;line-height:1.35}
  .ho-phone-steps span{color:#687e77;font-size:11.5px;line-height:1.45}
  .ho-phone-setup-link>span{display:block;margin-bottom:8px;color:#31574c;font-size:11.5px;font-weight:900;letter-spacing:.03em}
  .ho-phone-setup-link div{display:flex;min-height:44px}
  .ho-phone-setup-link input{min-width:0;flex:1;padding:11px 12px;border:1px solid #cbdcd6;border-radius:9px 0 0 9px;background:#f6f8f7;font-size:11.5px}
  .ho-phone-setup-link button{padding:0 16px;border:0;border-radius:0 9px 9px 0;color:#fff;background:#256d58;font-size:12px;font-weight:800}
  .ho-phone-registration-status{display:flex;align-items:center;gap:13px;margin-top:20px;padding:14px 15px;border-radius:10px;background:#f2f6f5}
  .ho-phone-registration-status i{width:10px;height:10px;flex:0 0 auto;border-radius:50%;background:#d19a2d}
  .ho-phone-registration-status strong,.ho-phone-registration-status small{display:block}
  .ho-phone-registration-status strong{font-size:13px;line-height:1.35}
  .ho-phone-registration-status small{margin-top:2px;color:#6c817a;font-size:11px;line-height:1.4}
  .ho-phone-registration-status.is-success i{background:#21865f}
  .ho-phone-registration-status.is-error i{background:#b74a55}
  .ho-payment-phone-btn svg{width:17px;height:17px;fill:none;stroke:currentColor;stroke-width:1.9;stroke-linecap:round;stroke-linejoin:round}
  .ho-phone-overview-modal{width:min(520px,100%)}
  .ho-phone-overview-card{display:grid;grid-template-columns:46px minmax(0,1fr) auto;align-items:center;gap:14px;padding:15px;border:1px solid #dfd4ad;border-radius:12px;background:#fffaf0}
  .ho-phone-overview-card.is-registered{border-color:#b9ddcf;background:linear-gradient(135deg,#edf8f3,#fff)}
  .ho-phone-overview-icon{width:46px;height:46px;display:grid;place-items:center;border-radius:11px;color:#fff;background:#9a7a29}
  .ho-phone-overview-card.is-registered .ho-phone-overview-icon{background:#21765e}
  .ho-phone-overview-icon svg{width:22px;height:22px;fill:none;stroke:currentColor;stroke-width:1.8;stroke-linecap:round;stroke-linejoin:round}
  .ho-phone-overview-copy{min-width:0}
  .ho-phone-overview-copy small,.ho-phone-overview-copy strong,.ho-phone-overview-copy span{display:block}
  .ho-phone-overview-copy small{margin-bottom:4px;color:#617b73;font-size:10px;font-weight:900;letter-spacing:.08em}
  .ho-phone-overview-copy strong{overflow:hidden;color:#214c40;font-size:14px;text-overflow:ellipsis;white-space:nowrap}
  .ho-phone-overview-copy span{margin-top:4px;color:#6c817a;font-size:11px;line-height:1.4}
  .ho-phone-overview-change{min-height:38px;padding:9px 14px;border:1px solid #bdd7ce;border-radius:9px;color:#205f4d;background:#fff;font-size:11.5px;font-weight:850;cursor:pointer;white-space:nowrap;box-shadow:0 3px 9px rgba(31,101,79,.08)}
  .ho-phone-overview-change:hover{border-color:#79ad9a;background:#f1f8f5;transform:translateY(-1px)}
  .ho-balance-payment-footer{gap:11px;padding:17px 26px}
  .ho-balance-payment-footer .ho-billing-btn{min-height:42px;padding:10px 16px;font-size:12px}
  .swal2-container{z-index:100600!important}
  @media(max-width:560px){.ho-balance-payment-body{padding:20px}.ho-qr-device{grid-template-columns:42px 1fr}.ho-qr-device button{grid-column:1/-1}.ho-balance-payment-footer{flex-wrap:wrap;padding:15px 20px}.ho-balance-payment-footer .ho-billing-btn{flex:1 1 auto}.ho-phone-steps{gap:14px}.ho-phone-setup-link input{font-size:10px}.ho-phone-overview-card{grid-template-columns:46px 1fr}.ho-phone-overview-change{grid-column:1/-1;width:100%}}
  .ho-status-stack{
  display:flex;
  flex-direction:column;
  align-items:center;
  gap:6px;
}

.ho-checkin-pill{
  background:#d1fae5;
  color:#065f46;
  padding:4px 12px;
  border-radius:999px;
  font-size:11px;
  font-weight:700;
  white-space:nowrap;
}

.ho-expense-form{
  display:flex;
  flex-direction:column;
  gap:18px;
}

.ho-form-grid{
  display:grid;
  grid-template-columns:repeat(2,1fr);
  gap:16px;
}

.ho-form-group{
  display:flex;
  flex-direction:column;
  gap:6px;
}

.ho-form-group input,
.ho-form-group select,
.ho-form-group textarea{
  width:100%;
  border:1px solid #d1d5db;
  border-radius:10px;
  padding:12px;
  font-size:14px;
}

.ho-full{
  grid-column:1 / -1;
}

.ho-modal-actions{
  display:flex;
  justify-content:flex-end;
}

.ho-expense-row{
  display:flex;
  align-items:flex-end;
  gap:12px;
  margin-bottom:14px;
  flex-wrap:wrap;
  border:1px solid #e5e7eb;
  padding:14px;
  border-radius:14px;
  background:#fafafa;
}

.ho-row-field{
  display:flex;
  flex-direction:column;
  gap:6px;
  flex:1;
  min-width:140px;
}

.ho-row-field.small{
  max-width:100px;
}

.ho-row-field.notes{
  flex:1.4;
}

.ho-row-field input,
.ho-row-field select{
  border:1px solid #d1d5db;
  border-radius:10px;
  padding:10px;
  font-size:14px;
}

.ho-remove-expense{
  width:42px;
  height:42px;
  border:none;
  border-radius:10px;
  background:#ef4444;
  color:#fff;
  font-size:22px;
  cursor:pointer;
  flex-shrink:0;
}

.ho-remove-expense:hover{
  opacity:.9;
}

.ho-expense-buttons{
  display:flex;
  justify-content:space-between;
  margin-top:20px;
  gap:12px;
}
</style>
<body class="ho-body">
  <div class="ho-layout">
    <?php include __DIR__ . '/Ho_sidebar.php'; ?>

    <main class="ho-main">
      <?php include __DIR__ . '/Ho_header.php'; ?>

      <section class="ho-content">
        <div class="ho-booking-summary-grid">
          <article class="ho-booking-summary-card">
            <div class="ho-booking-summary-copy">
              <span>Total Bookings</span>
              <strong><?= (int)$summaryTotalBookings ?></strong>
            </div>
            <div class="ho-booking-summary-icon" aria-hidden="true">
              <svg viewBox="0 0 24 24"><path d="M7 3v2m10-2v2M4 9h16M5 5h14a1 1 0 0 1 1 1v13a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V6a1 1 0 0 1 1-1Zm3 8h3v3H8v-3Z"/></svg>
            </div>
          </article>
          <article class="ho-booking-summary-card confirmed">
            <div class="ho-booking-summary-copy">
              <span>Total Confirmed</span>
              <strong><?= (int)$summaryConfirmedBookings ?></strong>
            </div>
            <div class="ho-booking-summary-icon" aria-hidden="true">
              <svg viewBox="0 0 24 24"><path d="m7 12 3 3 7-7"/><circle cx="12" cy="12" r="9"/></svg>
            </div>
          </article>
          <article class="ho-booking-summary-card pending">
            <div class="ho-booking-summary-copy">
              <span>Total Pending</span>
              <strong><?= (int)$summaryPendingBookings ?></strong>
            </div>
            <div class="ho-booking-summary-icon" aria-hidden="true">
              <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>
            </div>
          </article>
          <article class="ho-booking-summary-card completed">
            <div class="ho-booking-summary-copy">
              <span>Total Completed</span>
              <strong><?= (int)$summaryCompletedBookings ?></strong>
            </div>
            <div class="ho-booking-summary-icon" aria-hidden="true">
              <svg viewBox="0 0 24 24"><path d="M8 4h8M9 3h6a1 1 0 0 1 1 1v2H8V4a1 1 0 0 1 1-1Z"/><path d="M6 5h12a1 1 0 0 1 1 1v14H5V6a1 1 0 0 1 1-1Zm2 9 2.5 2.5L16 11"/></svg>
            </div>
          </article>
        </div>

        <article class="ho-card ho-table-card">
          <div class="ho-table-head">
            <h2 class="ho-section-title">All Bookings</h2>
            <div class="ho-booking-head-actions">
              <div class="ho-booking-tabs" role="tablist" aria-label="Booking status quick tabs">
                <a href="Hobookings.php?<?= htmlspecialchars(http_build_query(['status' => 'all', 'q' => $search, 'range' => $rangeFilter, 'year' => $selectedYear, 'month' => $selectedMonth, 'date' => $selectedDate, 'sort' => $sortBy, 'rows' => $rowsPerPage])) ?>" class="<?= $statusFilter === 'all' ? 'active' : '' ?>">All</a>
                <a href="Hobookings.php?<?= htmlspecialchars(http_build_query(['status' => 'pending', 'q' => $search, 'range' => $rangeFilter, 'year' => $selectedYear, 'month' => $selectedMonth, 'date' => $selectedDate, 'sort' => $sortBy, 'rows' => $rowsPerPage])) ?>" class="<?= $statusFilter === 'pending' ? 'active' : '' ?>">Pending</a>
                <a href="Hobookings.php?<?= htmlspecialchars(http_build_query(['status' => 'confirmed', 'q' => $search, 'range' => $rangeFilter, 'year' => $selectedYear, 'month' => $selectedMonth, 'date' => $selectedDate, 'sort' => $sortBy, 'rows' => $rowsPerPage])) ?>" class="<?= $statusFilter === 'confirmed' ? 'active' : '' ?>">Confirmed</a>
                <a href="Hobookings.php?<?= htmlspecialchars(http_build_query(['status' => 'completed', 'q' => $search, 'range' => $rangeFilter, 'year' => $selectedYear, 'month' => $selectedMonth, 'date' => $selectedDate, 'sort' => $sortBy, 'rows' => $rowsPerPage])) ?>" class="<?= $statusFilter === 'completed' ? 'active' : '' ?>">Completed</a>
                <a href="Hobookings.php?<?= htmlspecialchars(http_build_query(['status' => 'cancelled', 'q' => $search, 'range' => $rangeFilter, 'year' => $selectedYear, 'month' => $selectedMonth, 'date' => $selectedDate, 'sort' => $sortBy, 'rows' => $rowsPerPage])) ?>" class="<?= $statusFilter === 'cancelled' ? 'active' : '' ?>">Cancelled</a>
                <a href="Hobookings.php?<?= htmlspecialchars(http_build_query(['status' => 'no-show', 'q' => $search, 'range' => $rangeFilter, 'year' => $selectedYear, 'month' => $selectedMonth, 'date' => $selectedDate, 'sort' => $sortBy, 'rows' => $rowsPerPage])) ?>" class="<?= $statusFilter === 'no-show' ? 'active' : '' ?>">No-show</a>
              </div>
              <button type="button" class="ho-btn confirm ho-add-booking-btn ho-payment-phone-btn" id="hoOpenPhoneSetup" title="View the phone registered for PayMongo QR notifications">
                <svg aria-hidden="true" viewBox="0 0 24 24"><rect x="6" y="2" width="12" height="20" rx="2"></rect><path d="M10 18h4"></path></svg>
                <span>Payment Phone</span>
              </button>
              <button type="button" class="ho-btn confirm ho-add-booking-btn" id="hoOpenWalkinBooking">
                <svg aria-hidden="true" viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"></path></svg>
                <span>Add Booking</span>
              </button>
            </div>
          </div>

          <form method="get" class="ho-toolbar">
            <input type="hidden" name="range" value="<?= htmlspecialchars($rangeFilter) ?>" />
            <input type="hidden" name="year" value="<?= (int)$selectedYear ?>" />
            <input type="hidden" name="month" value="<?= (int)$selectedMonth ?>" />
            <input type="hidden" name="date" value="<?= htmlspecialchars($selectedDate) ?>" />
            <span class="ho-rows-label">Rows</span>
            <input type="number" name="rows" min="1" max="300" step="1" value="<?= (int)$rowsPerPage ?>" list="hoRowsOptions" placeholder="25" title="Rows count" />
            <datalist id="hoRowsOptions">
              <option value="25"></option>
              <option value="50"></option>
              <option value="100"></option>
              <option value="150"></option>
              <option value="200"></option>
              <option value="250"></option>
              <option value="300"></option>
            </datalist>
            <select name="status">
              <option value="all" <?= $statusFilter === 'all' ? 'selected' : '' ?>>All Statuses</option>
              <option value="pending" <?= $statusFilter === 'pending' ? 'selected' : '' ?>>Pending</option>
              <option value="confirmed" <?= $statusFilter === 'confirmed' ? 'selected' : '' ?>>Confirmed</option>
              <option value="completed" <?= $statusFilter === 'completed' ? 'selected' : '' ?>>Completed</option>
              <option value="cancelled" <?= $statusFilter === 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
              <option value="no-show" <?= $statusFilter === 'no-show' ? 'selected' : '' ?>>No-show</option>
            </select>
            <select name="sort">
              <option value="time" <?= $sortBy === 'time' ? 'selected' : '' ?>>Sort: Latest (Default)</option>
              <option value="name" <?= $sortBy === 'name' ? 'selected' : '' ?>>Sort: Name (A-Z)</option>
            </select>
            <button type="submit" class="ho-btn ho-filter-apply">Apply Filter</button>
            <input type="text" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="Search by guest name or booking ID" />
          </form>

          <?php if ($bookings): ?>
            <div class="ho-table-wrap">
              <table class="ho-table ho-bookings-table">
                <thead>
                  <tr>
                    <th>Booking ID</th>
                    <th>Booker</th>
                    <th>Room Type</th>
                    <th>Check-in / Check-out</th>
                    <th>No. of Guests</th>
                    <th>Status</th>
                    <th>Payment</th>
                    <th>Actions</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($bookings as $row): ?>
                    <?php
                      $status = strtolower((string)$row['booking_status']);
                      $bookingCanCheckInToday = $status === 'confirmed'
                          && empty($row['checked_in_at'])
                          && (string)($row['checkin_date'] ?? '') <= date('Y-m-d')
                          && (string)($row['checkout_date'] ?? '') > date('Y-m-d');
                    ?>
                    <?php
                      $profilePath = HoResolveProfileImage($row['tourist_profile_picture'] ?? null);
                      if ($profilePath === '') {
                        $profilePath = HoBuildGoogleAvatarUrl($row['tourist_google_id'] ?? null);
                      }
                      $hasAvatar = $profilePath !== '';
                      $guestName = htmlspecialchars((string)$row['guest_name']);
                      $guestInitial = htmlspecialchars(HoGetInitial((string)$row['guest_name']));
                      $guestEmail = htmlspecialchars((string)$row['email']);
                      $guestPhone = htmlspecialchars((string)($row['phone_number'] ?: '-'));
                      $guestAddress = htmlspecialchars((string)($row['tourist_address'] ?: '-'));
                      $publicBookingReference = trim((string)($row['booking_reference'] ?? '')) ?: (string)$row['hotel_booking_id'];
                      $guestCount = (int)$row['guest_count'];
                      $adultCount = (int)$row['adults'];
                      $childCount = (int)$row['children'];
                      $guestLabel = $guestCount === 1 ? 'guest' : 'guests';
                      $adultLabel = $adultCount === 1 ? 'adult' : 'adults';
                      $childLabel = $childCount === 1 ? 'child' : 'children';
                    ?>
                    <tr class="ho-booking-row">
                      <td class="ho-cell-center"><strong class="ho-booking-id"><?= htmlspecialchars($publicBookingReference) ?></strong></td>
                      <td>
                        <div class="ho-booker-cell">
                          <div class="ho-booker-avatar-wrap">
                            <?php if ($hasAvatar): ?>
                              <img
                                src="<?= htmlspecialchars($profilePath) ?>"
                                alt="<?= $guestName ?> profile"
                                class="ho-booker-avatar ho-profile-trigger"
                                loading="lazy"
                                decoding="async"
                                referrerpolicy="no-referrer"
                                onerror="this.onerror=null;this.src='img/profileicon2.png';"
                                role="button"
                                tabindex="0"
                                aria-label="View full profile for <?= $guestName ?>"
                                data-profile-src="<?= htmlspecialchars($profilePath) ?>"
                                data-tourist-id="<?= htmlspecialchars((string)($row['tourist_id'] ?? '')) ?>"
                                data-fullname="<?= $guestName ?>"
                                data-email="<?= $guestEmail ?>"
                                data-phone="<?= $guestPhone ?>"
                                data-address="<?= $guestAddress ?>"
                                data-total-bookings="<?= (int)($row['tourist_total_bookings'] ?? 0) ?>"
                                data-completed-bookings="<?= (int)($row['tourist_completed_bookings'] ?? 0) ?>"
                                data-account-status="<?= htmlspecialchars((string)($row['tourist_account_status'] ?? 'Guest')) ?>"
                                data-email-verified="<?= (int)($row['tourist_email_verified'] ?? 0) ?>"
                                data-google-connected="<?= !empty($row['tourist_google_id']) ? '1' : '0' ?>"
                                data-created-at="<?= htmlspecialchars((string)($row['tourist_created_at'] ?? '')) ?>"
                                data-updated-at="<?= htmlspecialchars((string)($row['tourist_updated_at'] ?? '')) ?>"
                              />
                            <?php else: ?>
                              <div
                                class="ho-booker-avatar ho-booker-avatar-initial ho-profile-trigger"
                                role="button"
                                tabindex="0"
                                aria-label="View full profile for <?= $guestName ?>"
                                data-profile-src="img/profileicon2.png"
                                data-tourist-id="<?= htmlspecialchars((string)($row['tourist_id'] ?? '')) ?>"
                                data-fullname="<?= $guestName ?>"
                                data-email="<?= $guestEmail ?>"
                                data-phone="<?= $guestPhone ?>"
                                data-address="<?= $guestAddress ?>"
                                data-total-bookings="<?= (int)($row['tourist_total_bookings'] ?? 0) ?>"
                                data-completed-bookings="<?= (int)($row['tourist_completed_bookings'] ?? 0) ?>"
                                data-account-status="<?= htmlspecialchars((string)($row['tourist_account_status'] ?? 'Guest')) ?>"
                                data-email-verified="<?= (int)($row['tourist_email_verified'] ?? 0) ?>"
                                data-google-connected="<?= !empty($row['tourist_google_id']) ? '1' : '0' ?>"
                                data-created-at="<?= htmlspecialchars((string)($row['tourist_created_at'] ?? '')) ?>"
                                data-updated-at="<?= htmlspecialchars((string)($row['tourist_updated_at'] ?? '')) ?>"
                              ><?= $guestInitial ?></div>
                            <?php endif; ?>
                          </div>
                          <div>
                            <?= $guestName ?><br />
                            <small><?= $guestEmail ?></small>
                          </div>
                        </div>
                      </td>
                      <td class="ho-cell-center"><?= htmlspecialchars((string)$row['room_type']) ?></td>
                      <td class="ho-cell-center"><?= htmlspecialchars((string)$row['checkin_date']) ?> to <?= htmlspecialchars((string)$row['checkout_date']) ?></td>
                      <td class="ho-cell-center ho-pax-cell">
                        <div class="ho-pax-summary" aria-label="<?= $guestCount ?> <?= $guestLabel ?>: <?= $adultCount ?> <?= $adultLabel ?> and <?= $childCount ?> <?= $childLabel ?>">
                          <span class="ho-pax-icon" aria-hidden="true">
                            <svg viewBox="0 0 24 24"><path d="M16 20v-1.5a4.5 4.5 0 0 0-4.5-4.5h-3A4.5 4.5 0 0 0 4 18.5V20"/><circle cx="10" cy="7" r="3.5"/><path d="M16 4.4a3.5 3.5 0 0 1 0 6.7M18 14.2a4.5 4.5 0 0 1 2 3.8v2"/></svg>
                          </span>
                          <span class="ho-pax-copy">
                            <span class="ho-pax-total"><strong><?= $guestCount ?></strong> <?= $guestLabel ?></span>
                            <span class="ho-pax-breakdown"><b><?= $adultCount ?></b> <?= $adultLabel ?><i></i><b><?= $childCount ?></b> <?= $childLabel ?></span>
                          </span>
                        </div>
                      </td>
                      <td class="ho-cell-center">
                        <div class="ho-status-stack">

                          <span class="ho-status <?= htmlspecialchars($status) ?>">
                            <?= ucfirst($status) ?>
                          </span>

                          <?php if (!empty($row['checked_in_at']) && empty($row['checked_out_at'])): ?>
                            <span class="ho-checkin-pill">
                              Currently Checked-in
                            </span>
                          <?php endif; ?>

                        </div>
                      </td>
                     <?php
                        $totalAmount = (float)($row['total_amount'] ?? 0);
                        $amountPaid  = (float)($row['amount_paid'] ?? 0);

                        $computedRemaining = max(0, $totalAmount - $amountPaid);

                        $isFullyPaid = ($totalAmount > 0 && $amountPaid >= $totalAmount);
                        $isPartial   = ($amountPaid > 0 && $amountPaid < $totalAmount);
                        $isUnpaid    = ($amountPaid <= 0);
                        ?>

                        <td class="ho-cell-center">

                        <?php if ($isFullyPaid): ?>
                            <span class="ho-status payment-paid">Paid</span>

                        <?php elseif ($isPartial): ?>
                            <span class="ho-status payment-partial">Partial</span>
                            <small style="display:block;color:#b91c1c;font-weight:600; margin-top: 5px;">
                                ₱<?= number_format($computedRemaining, 2) ?> remaining
                            </small>

                        <?php else: ?>
                            <span class="ho-status payment-unpaid">Unpaid</span>
                        <?php endif; ?>

                        </td>
                        <td>
                        <div class="ho-row-actions" data-row-actions>
                          <button type="button" class="ho-btn ho-row-actions-trigger" data-row-actions-trigger aria-haspopup="menu" aria-expanded="false">Actions</button>
                          <div class="ho-actions ho-actions-center ho-row-actions-menu" data-row-actions-menu>
                            <button
                              type="button"
                              class="ho-btn"
                              data-view
                              data-booking='<?= htmlspecialchars(json_encode([
                                  'id' => $publicBookingReference,
                                  'booking_id' => (int)$row['hotel_booking_id'],
                                  'guest' => $row['guest_name'],
                                  'profile_image' => $profilePath,
                                  'hotel' => $row['hotel_name'] ?: '-',
                                  'room_type' => $row['room_type'],
                                  'checkin' => $row['checkin_date'],
                                  'checkout' => $row['checkout_date'],
                                  'rooms' => (int)$row['rooms_booked'],
                                  'adults' => (int)$row['adults'],
                                  'children' => (int)$row['children'],
                                  'phone' => $row['phone_number'],
                                  'email' => $row['email'],
                                  'special_request' => $row['special_request'] ?: '-',
                                  'total' => (float)$row['total_amount'],
                                  'amount_paid' => (float)($row['amount_paid'] ?? 0),
                                  'remaining_balance' => (float)($row['remaining_balance'] ?? 0),
                                  'payment_type' => $row['payment_type'] ?? 'full',
                                  'payment_status' => $row['payment_status'] ?? 'unpaid',
                                  'booking_status' => $row['booking_status'] ?? 'pending',
                                  'created_at' => $row['created_at'],
                              ]), ENT_QUOTES) ?>'
                            >View Details</button>

                            <?php if (!in_array($status, ['confirmed', 'completed', 'cancelled', 'no-show'], true)): ?>
                              <form method="post" action="Hobookings.php" data-confirm-form>
                                  <input type="hidden" name="action" value="confirm" />
                                  <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($hotelBookingCsrf, ENT_QUOTES, 'UTF-8') ?>" />
                                  <input type="hidden" name="booking_id" value="<?= (int)$row['hotel_booking_id'] ?>" />
                                  <input type="hidden" name="email" value="<?= htmlspecialchars($row['email']) ?>" />
                                  <button type="submit" class="ho-btn confirm">Confirm</button>
                              </form>
                            <?php endif; ?>

                            <?php if (!in_array($status, ['completed', 'cancelled', 'no-show'], true)): ?>
                              <form method="post" data-cancel-form>
                                <input type="hidden" name="action" value="cancel" />
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($hotelBookingCsrf, ENT_QUOTES, 'UTF-8') ?>" />
                                <input type="hidden" name="booking_id" value="<?= (int)$row['hotel_booking_id'] ?>" />
                                <button type="submit" class="ho-btn cancel">Cancel</button>
                              </form>
                            <?php endif; ?>

                            <button
                              type="button"
                              class="ho-btn ho-billing-action"
                              data-open-billing
                              data-booking-id="<?= (int)$row['hotel_booking_id'] ?>"
                              data-guest="<?= htmlspecialchars($row['guest_name']) ?>"
                            >
                              Billing
                            </button>
                            <?php if ($bookingCanCheckInToday): ?>
                              <?php if ((int)($row['hotel_room_id'] ?? 0) > 0): ?>
                                <a
                                  class="ho-btn confirm"
                                  href="Horooms.php?<?= htmlspecialchars(http_build_query([
                                    'selected_room' => (int)$row['hotel_room_id'],
                                    'view' => 'list',
                                    'checkin_booking' => (int)$row['hotel_booking_id'],
                                  ])) ?>"
                                >Check-in Guest</a>
                              <?php endif; ?>
                              <form method="post" data-noshow-form>
                                <input type="hidden" name="action" value="no_show" />
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($hotelBookingCsrf, ENT_QUOTES, 'UTF-8') ?>" />
                                <input type="hidden" name="booking_id" value="<?= (int)$row['hotel_booking_id'] ?>" />
                                <button type="submit" class="ho-btn cancel">No-show</button>
                              </form>
                            <?php endif; ?>
                          </div>
                        </div>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php else: ?>
            <div class="ho-empty">No bookings found for the selected filter/search.</div>
          <?php endif; ?>

          <div class="ho-table-footer">
            <p class="ho-footnote">
              Showing <?= number_format($showingFrom) ?>&ndash;<?= number_format($showingTo) ?> of <?= number_format($totalFilteredBookings) ?>
            </p>

            <?php if ($totalPages > 1): ?>
              <nav class="ho-pagination" aria-label="Bookings pagination">
                <?php
                  $previousParams = $paginationParams;
                  $previousParams['page'] = max(1, $currentPage - 1);
                  $nextParams = $paginationParams;
                  $nextParams['page'] = min($totalPages, $currentPage + 1);

                  $visiblePages = [1, $totalPages];
                  for ($pageNumber = max(1, $currentPage - 1); $pageNumber <= min($totalPages, $currentPage + 1); $pageNumber++) {
                      $visiblePages[] = $pageNumber;
                  }
                  $visiblePages = array_values(array_unique($visiblePages));
                  sort($visiblePages);
                ?>
                <a
                  class="ho-page-link ho-page-arrow<?= $currentPage === 1 ? ' disabled' : '' ?>"
                  href="<?= $currentPage === 1 ? '#' : 'Hobookings.php?' . htmlspecialchars(http_build_query($previousParams)) ?>"
                  aria-label="Previous page"
                  <?= $currentPage === 1 ? 'aria-disabled="true" tabindex="-1"' : '' ?>
                >&lsaquo;</a>

                <?php $lastRenderedPage = 0; ?>
                <?php foreach ($visiblePages as $pageNumber): ?>
                  <?php if ($lastRenderedPage > 0 && $pageNumber > $lastRenderedPage + 1): ?>
                    <span class="ho-page-ellipsis" aria-hidden="true">&hellip;</span>
                  <?php endif; ?>
                  <?php $pageParams = $paginationParams; $pageParams['page'] = $pageNumber; ?>
                  <a
                    class="ho-page-link<?= $pageNumber === $currentPage ? ' active' : '' ?>"
                    href="Hobookings.php?<?= htmlspecialchars(http_build_query($pageParams)) ?>"
                    <?= $pageNumber === $currentPage ? 'aria-current="page"' : '' ?>
                  ><?= $pageNumber ?></a>
                  <?php $lastRenderedPage = $pageNumber; ?>
                <?php endforeach; ?>

                <a
                  class="ho-page-link ho-page-arrow<?= $currentPage === $totalPages ? ' disabled' : '' ?>"
                  href="<?= $currentPage === $totalPages ? '#' : 'Hobookings.php?' . htmlspecialchars(http_build_query($nextParams)) ?>"
                  aria-label="Next page"
                  <?= $currentPage === $totalPages ? 'aria-disabled="true" tabindex="-1"' : '' ?>
                >&rsaquo;</a>
              </nav>
            <?php endif; ?>
          </div>
        </article>
      </section>

      <?php include __DIR__ . '/Ho_footer.php'; ?>
    </main>
  </div>

  <div class="ho-modal ho-checkin-flow-modal" id="hoWalkinBookingModal" aria-hidden="true">
    <div class="ho-modal-card ho-checkin-flow-card ho-walkin-booking-card" role="dialog" aria-modal="true" aria-labelledby="hoWalkinBookingTitle">
      <header class="ho-checkin-flow-header">
        <span class="ho-walkin-title-icon" aria-hidden="true">
          <svg viewBox="0 0 24 24"><path d="M8 2v3M16 2v3M3.5 9h17M5 4h14a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2Z"></path><path d="M12 12v6M9 15h6"></path></svg>
        </span>
        <div>
          <span class="ho-checkin-eyebrow">Front desk booking</span>
          <h3 id="hoWalkinBookingTitle">Add a walk-in booking</h3>
          <p>Create a confirmed reservation for a guest booking through staff.</p>
        </div>
        <button type="button" class="ho-checkin-close" data-close-walkin aria-label="Close add booking">&times;</button>
      </header>

      <div class="ho-checkin-stepper" aria-label="New booking progress">
        <div class="ho-checkin-step active" data-walkin-step-indicator="1" data-checkin-step-indicator="1">
          <span class="ho-checkin-step-circle">1</span>
          <div><strong>Guest</strong><small>Contact information</small></div>
        </div>
        <div class="ho-checkin-step" data-walkin-step-indicator="2" data-checkin-step-indicator="2">
          <span class="ho-checkin-step-circle">2</span>
          <div><strong>Stay details</strong><small>Room and schedule</small></div>
        </div>
        <div class="ho-checkin-step" data-walkin-step-indicator="3" data-checkin-step-indicator="3">
          <span class="ho-checkin-step-circle">3</span>
          <div><strong>Payment</strong><small>Review and create</small></div>
        </div>
      </div>

      <form method="post" class="ho-checkin-flow-form" id="hoWalkinBookingForm" data-step="1" data-required-fields>
        <input type="hidden" name="create_walkin_booking" value="1" />
        <input type="hidden" name="walkin_csrf" value="<?= htmlspecialchars($hoWalkinCsrf) ?>" />

        <section class="ho-checkin-panel active" data-walkin-panel="1">
          <div class="ho-checkin-section-head">
            <div>
              <span class="ho-checkin-section-kicker">Step 1 of 3</span>
              <h4>Guest information</h4>
              <p>Enter the primary guest’s contact details for the reservation.</p>
            </div>
            <span class="ho-checkin-secure">Staff assisted</span>
          </div>

          <?php if ($walkinError !== ''): ?>
            <div class="ho-walkin-error" role="alert">
              <span>!</span>
              <p><strong>Booking not created</strong><?= htmlspecialchars($walkinError) ?></p>
            </div>
          <?php endif; ?>

          <p class="required-choice-title" data-required-label>Guest account type</p>
          <div class="ho-walkin-guest-mode" role="radiogroup" aria-label="Guest account type">
            <label>
              <input type="radio" name="walkin_guest_mode" value="existing" <?= $walkinGuestMode === 'existing' ? 'checked' : '' ?> />
              <span><strong>Existing tourist</strong><small>Attach booking to their account</small></span>
            </label>
            <label>
              <input type="radio" name="walkin_guest_mode" value="new" <?= $walkinGuestMode === 'new' ? 'checked' : '' ?> />
              <span><strong>New walk-in guest</strong><small>Book without an account</small></span>
            </label>
          </div>

          <div class="ho-walkin-existing-guest" id="hoWalkinExistingGuest">
            <label class="ho-walkin-tourist-search-label" for="hoWalkinTouristSearch" data-required-label>Search tourist account</label>
            <div class="ho-walkin-tourist-search">
              <svg aria-hidden="true" viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"></circle><path d="m16 16 4 4"></path></svg>
              <input type="search" id="hoWalkinTouristSearch" placeholder="Search by name, email, or phone number" autocomplete="off" />
              <span class="ho-walkin-search-spinner" id="hoWalkinSearchSpinner" hidden></span>
            </div>
            <input type="hidden" name="walkin_tourist_id" id="hoWalkinTouristId" value="<?= $walkinSelectedTourist ? (int)$walkinSelectedTourist['tourist_id'] : '' ?>" />
            <div class="ho-walkin-tourist-results" id="hoWalkinTouristResults" hidden></div>
            <div
              class="ho-walkin-selected-tourist"
              id="hoWalkinSelectedTourist"
              data-name="<?= htmlspecialchars((string)($walkinSelectedTourist['full_name'] ?? '')) ?>"
              data-email="<?= htmlspecialchars((string)($walkinSelectedTourist['email'] ?? '')) ?>"
              <?= $walkinSelectedTourist ? '' : 'hidden' ?>
            >
              <span class="ho-walkin-tourist-avatar" id="hoWalkinTouristAvatar">
                <b><?= htmlspecialchars(strtoupper(substr((string)($walkinSelectedTourist['full_name'] ?? 'T'), 0, 1))) ?></b>
                <?php if ($walkinSelectedProfileImage !== ''): ?>
                  <img src="<?= htmlspecialchars($walkinSelectedProfileImage) ?>" alt="" referrerpolicy="no-referrer" onerror="this.remove()" />
                <?php endif; ?>
              </span>
              <div>
                <small>Selected tourist account</small>
                <strong id="hoWalkinTouristName"><?= htmlspecialchars((string)($walkinSelectedTourist['full_name'] ?? '')) ?></strong>
                <span id="hoWalkinTouristEmail"><?= htmlspecialchars((string)($walkinSelectedTourist['email'] ?? '')) ?></span>
              </div>
              <button type="button" id="hoWalkinChangeTourist">Change</button>
            </div>
            <div class="ho-checkin-fields one ho-walkin-existing-phone" id="hoWalkinExistingPhoneWrap" <?= $walkinSelectedTourist ? '' : 'hidden' ?>>
              <label>Booking contact number
                <input type="tel" name="walkin_existing_phone" id="hoWalkinExistingPhone" value="<?= htmlspecialchars((string)($walkinForm['walkin_existing_phone'] ?? ($walkinSelectedTourist['phone_number'] ?? ''))) ?>" placeholder="09XX XXX XXXX" />
              </label>
            </div>
            <p class="ho-walkin-search-help" id="hoWalkinSearchHelp"><?= $walkinSelectedTourist ? 'This reservation will be saved in the selected tourist’s account.' : 'Type at least two characters to find a registered tourist.' ?></p>
          </div>

          <div class="ho-walkin-new-guest" id="hoWalkinNewGuest" <?= $walkinGuestMode === 'new' ? '' : 'hidden' ?>>
            <div class="ho-checkin-fields two">
              <label>First name
                <input type="text" name="walkin_first_name" value="<?= htmlspecialchars((string)($walkinForm['walkin_first_name'] ?? '')) ?>" autocomplete="given-name" />
              </label>
              <label>Last name
                <input type="text" name="walkin_last_name" value="<?= htmlspecialchars((string)($walkinForm['walkin_last_name'] ?? '')) ?>" autocomplete="family-name" />
              </label>
            </div>
            <div class="ho-checkin-fields two">
              <label>Email address <span>(optional)</span>
                <input type="email" name="walkin_email" value="<?= htmlspecialchars((string)($walkinForm['walkin_email'] ?? '')) ?>" autocomplete="email" placeholder="guest@email.com" />
              </label>
              <label>Contact number
                <input type="tel" name="walkin_phone" value="<?= htmlspecialchars((string)($walkinForm['walkin_phone'] ?? '')) ?>" autocomplete="tel" placeholder="09XX XXX XXXX" />
              </label>
            </div>
          </div>

          <div class="ho-walkin-info-note">
            <span aria-hidden="true">i</span>
            <p id="hoWalkinGuestModeNote">Existing tourists will see this reservation in their account after staff creates it.</p>
          </div>

          <footer class="ho-checkin-footer">
            <p class="required-step-note"><span class="required-mark" aria-hidden="true">*</span><span>indicates a required field.</span></p>
            <div class="required-footer-actions">
              <button type="button" class="ho-btn cancel" data-close-walkin>Cancel</button>
              <button type="button" class="ho-btn confirm" data-walkin-next>Continue to stay details</button>
            </div>
          </footer>
        </section>

        <section class="ho-checkin-panel" data-walkin-panel="2" hidden>
          <div class="ho-checkin-section-head">
            <div>
              <span class="ho-checkin-section-kicker">Step 2 of 3</span>
              <h4>Stay dates and guests</h4>
              <p>Enter the stay and party details first. Available rooms will then be filtered automatically.</p>
            </div>
          </div>

          <?php if ($walkinRooms): ?>
            <div class="ho-checkin-fields two">
              <label>Check-in date
                <input type="date" name="walkin_checkin" id="hoWalkinCheckin" min="<?= htmlspecialchars(date('Y-m-d')) ?>" value="<?= htmlspecialchars($walkinDefaultCheckin) ?>" required />
              </label>
              <label>Check-out date
                <input type="date" name="walkin_checkout" id="hoWalkinCheckout" min="<?= htmlspecialchars(date('Y-m-d', strtotime('+1 day'))) ?>" value="<?= htmlspecialchars($walkinDefaultCheckout) ?>" required />
              </label>
            </div>
            <div class="ho-checkin-fields two">
              <label>Adults
                <input type="number" name="walkin_adults" id="hoWalkinAdults" min="1" value="<?= max(1, (int)($walkinForm['walkin_adults'] ?? 1)) ?>" required />
              </label>
              <label>Children
                <input type="number" name="walkin_children" id="hoWalkinChildren" min="0" value="<?= max(0, (int)($walkinForm['walkin_children'] ?? 0)) ?>" required />
              </label>
            </div>
            <div class="ho-walkin-child-ages" id="hoWalkinChildAges" data-saved-ages="<?= htmlspecialchars(json_encode($walkinSavedChildAges), ENT_QUOTES) ?>"></div>
            <p class="ho-walkin-child-policy"><strong>Child policy:</strong> Children 7 years old and below stay free and do not count toward room capacity. Children aged 8 and above count as guests.</p>

            <div class="ho-walkin-room-results-head">
              <div><strong>Available rooms</strong><small id="hoWalkinRoomStatus">Complete the stay dates and guest details to see rooms.</small></div>
              <span class="ho-walkin-room-spinner" id="hoWalkinRoomSpinner" hidden></span>
            </div>
            <div class="ho-walkin-room-field">
              <label>Room
                <select name="walkin_room_id" id="hoWalkinRoom" data-saved-room-id="<?= (int)($walkinForm['walkin_room_id'] ?? 0) ?>" required disabled>
                  <option value="">Checking available rooms...</option>
                </select>
              </label>
              <div class="ho-walkin-room-price"><small>Nightly rate</small><strong id="hoWalkinNightlyRate">₱0.00</strong></div>
            </div>
            <label class="ho-walkin-request">Special request <span>(optional)</span>
              <textarea name="walkin_special_request" rows="3" placeholder="Accessibility needs, arrival notes, or guest preferences"><?= htmlspecialchars((string)($walkinForm['walkin_special_request'] ?? '')) ?></textarea>
            </label>
            <p class="ho-walkin-capacity-note" id="hoWalkinCapacityNote">Only children aged 8 and above count toward room capacity.</p>
          <?php else: ?>
            <div class="ho-walkin-empty">No active rooms are available to book. Add or activate a room first.</div>
          <?php endif; ?>

          <footer class="ho-checkin-footer">
            <p class="required-step-note"><span class="required-mark" aria-hidden="true">*</span><span>indicates a required field.</span></p>
            <div class="required-footer-actions">
              <button type="button" class="ho-btn" data-walkin-prev>Back</button>
              <button type="button" class="ho-btn confirm" data-walkin-next <?= $walkinRooms ? '' : 'disabled' ?>>Review payment</button>
            </div>
          </footer>
        </section>

        <section class="ho-checkin-panel" data-walkin-panel="3" hidden>
          <div class="ho-checkin-section-head">
            <div>
              <span class="ho-checkin-section-kicker">Step 3 of 3</span>
              <h4>Payment and confirmation</h4>
              <p>Review the stay total and record what the guest pays now.</p>
            </div>
            <span class="ho-checkin-secure">Confirmed booking</span>
          </div>

          <div class="ho-checkin-finance-grid">
            <div><small>Nightly rate</small><strong id="hoWalkinReviewRate">₱0.00</strong></div>
            <div><small>Length of stay</small><strong id="hoWalkinReviewNights">0 nights</strong></div>
            <div class="balance"><small>Booking total</small><strong id="hoWalkinReviewTotal">₱0.00</strong></div>
          </div>

          <div class="ho-walkin-payment-layout">
            <div>
              <h5 data-required-label>Payment received</h5>
              <div class="ho-walkin-payment-options">
                <?php $savedPaymentOption = (string)($walkinForm['walkin_payment_option'] ?? 'full'); ?>
                <label><input type="radio" name="walkin_payment_option" value="full" <?= $savedPaymentOption === 'full' ? 'checked' : '' ?> /><span><strong>Paid in full</strong><small>Collect the complete booking total</small></span></label>
                <label><input type="radio" name="walkin_payment_option" value="partial" <?= $savedPaymentOption === 'partial' ? 'checked' : '' ?> /><span><strong>Partial payment</strong><small>Record a deposit collected now</small></span></label>
                <label><input type="radio" name="walkin_payment_option" value="unpaid" <?= $savedPaymentOption === 'unpaid' ? 'checked' : '' ?> /><span><strong>No payment yet</strong><small>Keep the full total as balance</small></span></label>
              </div>
              <label class="ho-walkin-partial-field" id="hoWalkinPaymentMethodField">Payment method
                <select name="walkin_payment_method" id="hoWalkinPaymentMethod">
                  <option value="" disabled <?= empty($walkinForm['walkin_payment_method']) ? 'selected' : '' ?>>Select payment method</option>
                  <option value="cash" <?= ($walkinForm['walkin_payment_method'] ?? '') === 'cash' ? 'selected' : '' ?>>Cash</option>
                  <option value="qr_code" <?= ($walkinForm['walkin_payment_method'] ?? '') === 'qr_code' ? 'selected' : '' ?>>QR Code (PayMongo)</option>
                </select>
              </label>
              <section class="ho-qr-device" id="hoWalkinQrDeviceSection" hidden aria-live="polite">
                <span class="ho-qr-device-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><rect x="6" y="2" width="12" height="20" rx="2"/><path d="M10 18h4"/></svg></span>
                <div><small>REGISTERED HOTEL PHONE</small><strong id="hoWalkinQrDeviceName">Checking registered phone...</strong><span id="hoWalkinQrDeviceMeta">Please wait.</span></div>
                <button type="button" id="hoWalkinManagePaymentPhone">Register a Phone</button>
              </section>
              <label class="ho-walkin-partial-field" id="hoWalkinPartialField">Amount received
                <div class="ho-checkin-money-input"><span>₱</span><input type="number" name="walkin_partial_amount" id="hoWalkinPartialAmount" min="0.01" step="0.01" value="<?= htmlspecialchars((string)($walkinForm['walkin_partial_amount'] ?? '')) ?>" /></div>
              </label>
            </div>
            <div class="ho-checkin-summary-box ho-walkin-summary">
              <h5>Booking summary</h5>
              <dl>
                <div><dt>Guest</dt><dd id="hoWalkinSummaryGuest">—</dd></div>
                <div><dt>Room</dt><dd id="hoWalkinSummaryRoom">—</dd></div>
                <div><dt>Stay</dt><dd id="hoWalkinSummaryDates">—</dd></div>
                <div><dt>Guests</dt><dd id="hoWalkinSummaryGuests">—</dd></div>
                <div><dt>Paid now</dt><dd id="hoWalkinSummaryPaid">₱0.00</dd></div>
                <div><dt>Balance</dt><dd id="hoWalkinSummaryBalance">₱0.00</dd></div>
              </dl>
            </div>
          </div>

          <div class="ho-checkin-confirm-note">
            <span aria-hidden="true">i</span>
            <p><strong>Ready to create</strong> This reservation will be saved as confirmed and will appear immediately in the room’s check-in list.</p>
          </div>

          <footer class="ho-checkin-footer">
            <p class="required-step-note"><span class="required-mark" aria-hidden="true">*</span><span>indicates a required field.</span></p>
            <div class="required-footer-actions">
              <button type="button" class="ho-btn" data-walkin-prev>Back</button>
              <button type="submit" class="ho-btn confirm">Create confirmed booking</button>
            </div>
          </footer>
        </section>
      </form>
    </div>
  </div>

  <div class="ho-booking-details-overlay" id="hoDetailsModal" aria-hidden="true">
    <aside class="ho-booking-details-drawer" role="dialog" aria-modal="true" aria-labelledby="hoBookingDetailsTitle">
      <header class="ho-booking-details-header">
        <div class="ho-booking-drawer-brand">
          <img src="img/newlogo.png" alt="" />
          <div>
            <span>ITOUR MERCEDES</span>
            <h3 id="hoBookingDetailsTitle">Booking Details</h3>
          </div>
        </div>
        <button type="button" class="ho-booking-details-close" id="hoCloseModal" aria-label="Close booking details">
          <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m6 6 12 12M18 6 6 18"/></svg>
        </button>
      </header>
      <div class="ho-booking-details-body" id="hoDetailGrid"></div>
      <footer class="ho-booking-details-footer">
        <button type="button" class="ho-booking-details-close-btn" id="hoCloseDetailsFooter">Close Details</button>
      </footer>
    </aside>
  </div>

  <div class="ho-billing-overlay" id="hoBillingModal" aria-hidden="true">
    <section class="ho-billing-modal" role="dialog" aria-modal="true" aria-labelledby="hoBillingTitle">
      <header class="ho-billing-header">
        <div class="ho-billing-header-icon" aria-hidden="true">
          <svg viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="16" rx="2"/><path d="M7 9h10M7 13h6M16 13h1M7 17h4"/></svg>
        </div>
        <div><span>GUEST ACCOUNT</span><h3 id="hoBillingTitle">Billing Details</h3><p id="hoBillingSubtitle">Loading current room charges and payment information…</p></div>
        <button type="button" class="ho-billing-icon-close" id="hoBillingIconClose" aria-label="Close billing details">&times;</button>
      </header>
      <div class="ho-billing-body" id="hoBillingBody">
        <div class="ho-billing-loading"><span></span><p>Preparing billing statement…</p></div>
      </div>
      <footer class="ho-billing-footer">
        <button type="button" class="ho-billing-btn close" id="hoCloseBilling">Close</button>
        <button type="button" class="ho-billing-btn expense" id="hoBillingAddExpense">Add Expense</button>
        <div class="ho-billing-pay-wrap">
          <button type="button" class="ho-billing-btn pay" id="hoBillingPayBalance">Pay Balance</button>
          <small id="hoBillingPaidNote" hidden>Already paid</small>
        </div>
      </footer>
    </section>
  </div>

  <div class="ho-billing-overlay" id="hoBalancePaymentModal" aria-hidden="true">
    <section class="ho-balance-payment-modal" role="dialog" aria-modal="true" aria-labelledby="hoBalancePaymentTitle">
      <header class="ho-balance-payment-header">
        <div><span>PAYMENT COLLECTION</span><h3 id="hoBalancePaymentTitle">Pay Balance</h3></div>
        <button type="button" class="ho-billing-icon-close" id="hoCloseBalancePayment" aria-label="Close payment modal">&times;</button>
      </header>
      <div class="ho-balance-payment-body">
        <div class="ho-balance-due"><small>CURRENT BALANCE</small><strong id="hoBalancePaymentDue">₱0.00</strong></div>
        <p id="hoBalancePaymentContext">Record the cash amount received. Partial payments are allowed.</p>
        <label class="ho-balance-payment-field"><span>Payment Method</span><select id="hoBalancePaymentMethod" required><option value="" selected disabled>Select payment method</option><option value="cash">Cash</option><option value="qr_code">QR Code (PayMongo)</option></select></label>
        <section class="ho-qr-device" id="hoQrDeviceSection" hidden aria-live="polite">
          <span class="ho-qr-device-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><rect x="6" y="2" width="12" height="20" rx="2"/><path d="M10 18h4"/></svg></span>
          <div><small>REGISTERED HOTEL PHONE</small><strong id="hoQrDeviceName">Checking registered phone...</strong><span id="hoQrDeviceMeta">Please wait.</span></div>
          <button type="button" id="hoManagePaymentPhone">Register a Phone</button>
        </section>
        <label class="ho-balance-payment-field"><span>Amount Received</span><div><i>₱</i><input type="number" id="hoBalancePaymentAmount" min="0.01" step="0.01" required /></div></label>
      </div>
      <footer class="ho-balance-payment-footer">
        <button type="button" class="ho-billing-btn close" id="hoCancelBalancePayment">Cancel</button>
        <button type="button" class="ho-billing-btn pay" id="hoConfirmBalancePayment">Confirm Payment</button>
      </footer>
    </section>
  </div>

  <div class="ho-billing-overlay" id="hoPhoneOverviewModal" aria-hidden="true">
    <section class="ho-balance-payment-modal ho-phone-overview-modal" role="dialog" aria-modal="true" aria-labelledby="hoPhoneOverviewTitle">
      <header class="ho-balance-payment-header">
        <div><span>PAYMENT NOTIFICATIONS</span><h3 id="hoPhoneOverviewTitle">Payment Phone</h3></div>
        <button type="button" class="ho-billing-icon-close" id="hoClosePhoneOverview" aria-label="Close payment phone">&times;</button>
      </header>
      <div class="ho-balance-payment-body">
        <section class="ho-phone-overview-card" id="hoPhoneOverviewCard" aria-live="polite">
          <span class="ho-phone-overview-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><rect x="6" y="2" width="12" height="20" rx="2"/><path d="M10 18h4"/></svg></span>
          <div class="ho-phone-overview-copy"><small>REGISTERED PAYMENT PHONE</small><strong id="hoPhoneOverviewName">Checking registered phone...</strong><span id="hoPhoneOverviewMeta">Please wait.</span></div>
          <button type="button" class="ho-phone-overview-change" id="hoPhoneOverviewAction">Change</button>
        </section>
      </div>
      <footer class="ho-balance-payment-footer"><button type="button" class="ho-billing-btn close" id="hoClosePhoneOverviewFooter">Close</button></footer>
    </section>
  </div>

  <div class="ho-billing-overlay" id="hoPhoneSetupModal" aria-hidden="true">
    <section class="ho-balance-payment-modal phone-setup" role="dialog" aria-modal="true" aria-labelledby="hoPhoneSetupTitle">
      <header class="ho-balance-payment-header">
        <div><span>PAYMONGO NOTIFICATIONS</span><h3 id="hoPhoneSetupTitle">Register Payment Phone</h3></div>
        <button type="button" class="ho-billing-icon-close" id="hoClosePhoneSetup" aria-label="Close phone setup">&times;</button>
      </header>
      <div class="ho-balance-payment-body">
        <ol class="ho-phone-steps"><li><b>Open the setup address on the hotel phone.</b><span>Log in with the same hotel administrator account when asked.</span></li><li><b>Name and register the phone.</b><span>Allow browser notifications on the setup page.</span></li><li><b>Return to this computer.</b><span>This window detects the registered phone automatically.</span></li></ol>
        <label class="ho-phone-setup-link"><span>PHONE SETUP ADDRESS</span><div><input type="text" id="hoPhoneSetupUrl" readonly><button type="button" id="hoCopyPhoneSetupUrl">Copy Link</button></div></label>
        <div class="ho-phone-registration-status" id="hoPhoneRegistrationStatus"><i></i><div><strong>Waiting for phone registration</strong><small>Keep this window open while registering the phone.</small></div></div>
      </div>
      <footer class="ho-balance-payment-footer"><button type="button" class="ho-billing-btn close" id="hoClosePhoneSetupFooter">Close</button><button type="button" class="ho-billing-btn close" id="hoCheckPhoneRegistration">Check Again</button><button type="button" class="ho-billing-btn pay" id="hoOpenPhoneSetupPage">Open Setup Page</button></footer>
    </section>
  </div>

  <div class="ho-modal ho-expense-modal" id="hoExpenseModal" aria-hidden="true">
    <div class="ho-modal-card ho-expense-modal-card" role="dialog" aria-modal="true" aria-labelledby="hoExpenseTitle">

      <div class="ho-expense-modal-head">
        <div class="ho-expense-head-icon" aria-hidden="true">+</div>
        <div><span>GUEST FOLIO</span><h3 id="hoExpenseTitle">Add Additional Expense</h3><p>Record itemized charges accurately against this booking.</p></div>
        <button type="button" class="ho-close" id="hoCloseExpenseModal" aria-label="Close expense modal">
          &times;
        </button>
      </div>

      <form method="post" class="ho-expense-form">

  <input type="hidden" name="add_expense" value="1">
  <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($hotelBookingCsrf, ENT_QUOTES, 'UTF-8') ?>">
  <input type="hidden" name="booking_id" id="hoExpenseBookingId">

  <div class="ho-expense-guest-card">
    <div><small>CHARGE TO</small><strong id="hoExpenseGuestDisplay">Guest</strong></div>
    <span>Active booking</span>
    <input type="hidden" id="hoExpenseGuest">
  </div>

  <div id="hoExpenseRows">

    <div class="ho-expense-row" data-expense-row>
      <div class="ho-expense-row-head"><span>EXPENSE ITEM 01</span><strong data-expense-row-total>₱0.00</strong></div>

      <div class="ho-row-field">
        <label>Type</label>
        <select name="expense_type[]" required>
          <option value="">Select</option>
          <option value="Room Upgrade">Room Upgrade</option>
          <option value="Additional Room">Additional Room</option>
          <option value="Activity">Activity</option>
          <option value="Cottage">Cottage</option>
          <option value="Food">Food</option>
          <option value="Others">Others</option>
        </select>
      </div>

      <div class="ho-row-field">
        <label>Description</label>
        <input type="text" name="expense_name[]" placeholder="Enter item or service" required>
      </div>

      <div class="ho-row-field small">
        <label>Qty</label>
        <input type="number" name="quantity[]" min="1" value="1" required>
      </div>

      <div class="ho-row-field small">
        <label>Unit Price</label>
        <div class="ho-expense-money"><span>₱</span><input type="number" name="unit_price[]" min="0.01" step="0.01" placeholder="0.00" required></div>
      </div>

      <div class="ho-row-field notes">
        <label>Notes</label>
        <input type="text" name="notes[]" placeholder="Optional explanation or reference">
      </div>

      <button type="button" class="ho-remove-expense">
        &times;
      </button>

    </div>

  </div>

  <div class="ho-expense-total-strip"><span>New charges total</span><strong id="hoExpenseGrandTotal">₱0.00</strong></div>

  <div class="ho-expense-buttons">

    <button type="button" class="ho-btn" id="hoAddExpenseRow">
      + Add Another Item
    </button>

    <button type="submit" class="ho-btn confirm">
      Save Charges
    </button>

  </div>

</form>
    </div>
  </div>

  <div id="hoTouristProfileDrawer" class="ho-tourist-profile-overlay" aria-hidden="true">
    <aside class="ho-tourist-profile-drawer" role="dialog" aria-modal="true" aria-labelledby="hoTouristProfileTitle">
      <header class="ho-tourist-profile-header">
        <div class="ho-tourist-profile-brand">
          <img src="img/newlogo.png" alt="" />
          <div>
            <span>ITOUR MERCEDES</span>
            <h3 id="hoTouristProfileTitle">Guest Profile</h3>
          </div>
        </div>
        <button type="button" class="ho-tourist-profile-close" aria-label="Close guest profile">
          <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m6 6 12 12M18 6 6 18"/></svg>
        </button>
      </header>
      <div class="ho-tourist-profile-body" id="hoTouristProfileContent"></div>
      <footer class="ho-tourist-profile-footer">
        <button type="button" class="ho-tourist-profile-close-btn">Close Profile</button>
      </footer>
    </aside>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
  <script>
    (function () {
      const actionNoticeText = <?= json_encode($actionNoticeText, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
      const actionNoticeIcon = <?= json_encode($actionNoticeIcon, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
      const toggle = document.getElementById('hoNotifToggle');
      const panel = document.getElementById('hoNotifPanel');
      const markBtn = document.getElementById('hoNotifMarkRead');
      const badge = document.getElementById('hoNotifBadge');
      const unreadSelector = '.ho-notif-item.is-unread';
      let notifMarked = false;
      const hideBadge = () => {
        if (badge) badge.style.display = 'none';
      };
      const hasUnreadItems = () => panel ? panel.querySelector(unreadSelector) !== null : false;
      const clearUnreadState = () => {
        if (!panel) return;
        panel.querySelectorAll(unreadSelector).forEach((item) => item.classList.remove('is-unread'));
        panel.querySelectorAll('.ho-notif-unread-pill').forEach((pill) => pill.remove());
      };

      const markNotificationsRead = async () => {
        if (notifMarked || !hasUnreadItems()) return;
        notifMarked = true;
        const body = new URLSearchParams();
        body.set('ho_action', 'mark_notifications_read');
        body.set('csrf_token', <?= json_encode($hotelNotificationCsrf) ?>);
        try {
          const response = await fetch('Hobookings.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body.toString()
          });
          if (!response.ok) {
            throw new Error(`Failed to mark notifications as read (${response.status})`);
          }
          hideBadge();
          clearUnreadState();
        } catch (error) {
          notifMarked = false;
          console.error(error);
        }
      };

      const closePanelAndMarkRead = () => {
        if (!panel || !toggle) return;
        const wasOpen = panel.classList.contains('open');
        panel.classList.remove('open');
        toggle.setAttribute('aria-expanded', 'false');
        if (wasOpen) markNotificationsRead();
      };

      if (toggle && panel) {
        toggle.addEventListener('click', () => {
          const willOpen = !panel.classList.contains('open');
          if (!willOpen) {
            closePanelAndMarkRead();
            return;
          }
          panel.classList.add('open');
          toggle.setAttribute('aria-expanded', 'true');
          hideBadge();
        });

        document.addEventListener('click', (e) => {
          if (!panel.contains(e.target) && !toggle.contains(e.target)) {
            closePanelAndMarkRead();
          }
        });
      }

      if (markBtn) markBtn.addEventListener('click', markNotificationsRead);

      const hotelPayMongoCsrf = <?= json_encode($hoPayMongoCsrf, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
      const hotelPayMongoEndpoint = new URL('payments/create-balance-checkout.php', window.location.href).href;
      const hotelPayMongoPendingKey = 'itour_hotel_admin_paymongo_pending';
      const hotelPhoneStatusEndpoint = 'hotel-push-device-status.php';
      const hotelPhoneSetupPage = 'hotel-admin-phone-setup.php';
      const hotelPublicAppUrl = <?= json_encode((string)($hoFirebaseConfiguration['app_url'] ?? ''), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
      const hotelPhoneState = { device: null, baseline: null, paymentTimer: null, registrationTimer: null };

      const readHotelPaymentJson = async (response) => {
        const contentType = response.headers.get('content-type') || '';
        if (!contentType.includes('application/json')) throw new Error('The payment service returned an unexpected response.');
        return response.json();
      };

      const cancelHotelPayMongoPayment = async (bookingId, returnToken) => {
        const form = new FormData();
        form.append('action', 'cancel_pending');
        form.append('type', 'hotel');
        form.append('id', String(bookingId));
        form.append('return_token', String(returnToken));
        form.append('csrf_token', hotelPayMongoCsrf);
        const response = await fetch(hotelPayMongoEndpoint, { method: 'POST', body: form, headers: { Accept: 'application/json' } });
        const result = await readHotelPaymentJson(response);
        if (!response.ok || !result.success) throw new Error(result.message || 'The pending QR payment could not be cancelled.');
        return Boolean(result.cancelled);
      };

      const pollHotelPayMongo = async (token, bookingId = 0) => {
        if (!/^[a-f0-9]{64}$/.test(String(token || ''))) return false;
        let trackedBookingId = Number(bookingId || 0);
        let cancelRequested = false;
        Swal.fire({
          title: 'QR Sent to Hotel Phone',
          text: 'Waiting for the tourist payment. This booking will update automatically after PayMongo verifies it.',
          allowOutsideClick: false,
          allowEscapeKey: false,
          showCancelButton: true,
          showConfirmButton: false,
          cancelButtonText: 'Cancel Payment',
          cancelButtonColor: '#b5444f',
          didOpen: () => Swal.showLoading()
        }).then(result => {
          if (result.dismiss === Swal.DismissReason.cancel) cancelRequested = true;
        });
        for (let attempt = 0; attempt < 120; attempt += 1) {
          if (cancelRequested) {
            sessionStorage.removeItem(hotelPayMongoPendingKey);
            try {
              Swal.fire({
                title: 'Cancelling Payment',
                text: 'Closing the pending PayMongo checkout…',
                allowOutsideClick: false,
                allowEscapeKey: false,
                showConfirmButton: false,
                didOpen: () => Swal.showLoading()
              });
              const cancelled = trackedBookingId > 0 && await cancelHotelPayMongoPayment(trackedBookingId, token);
              await Swal.fire({
                icon: cancelled ? 'info' : 'warning',
                title: cancelled ? 'Payment Cancelled' : 'Nothing to Cancel',
                text: cancelled ? 'The pending PayMongo payment was cancelled and logged in transactions. No amount was applied to the booking.' : 'No active pending PayMongo payment was found. Refresh the page to confirm its latest status.',
                confirmButtonColor: '#2b7a66'
              });
              if (cancelled) window.location.reload();
            } catch (error) {
              await Swal.fire('Cancellation Not Confirmed', error.message || 'Wait for PayMongo payment verification before trying again.', 'warning');
            }
            return false;
          }
          try {
            const response = await fetch(`Hobookings.php?ho_action=paymongo_payment_status&token=${encodeURIComponent(token)}`, {
              headers: { Accept: 'application/json' }, cache: 'no-store'
            });
            const data = await readHotelPaymentJson(response);
            trackedBookingId = Number(data.booking_id || trackedBookingId);
            if (response.ok && data.success && data.status === 'paid') {
              sessionStorage.removeItem(hotelPayMongoPendingKey);
              const paidBookingId = String(data.booking_reference || `#${data.booking_id || ''}`).trim();
              await Swal.fire({
                icon: 'success',
                title: 'QR Payment Verified',
                text: `Booking ID: ${paidBookingId}. PayMongo verified the payment and the hotel booking account has been updated.`,
                confirmButtonColor: '#2b7a66'
              });
              window.location.href = 'Hobookings.php';
              return true;
            }
            if (response.ok && data.success && data.status === 'cancelled') {
              sessionStorage.removeItem(hotelPayMongoPendingKey);
              await Swal.fire('Payment Cancelled', 'The pending PayMongo payment was cancelled and logged in transactions. No amount was applied to the booking.', 'info');
              window.location.reload();
              return false;
            }
            if (response.ok && data.success && ['failed', 'expired'].includes(data.status)) {
              sessionStorage.removeItem(hotelPayMongoPendingKey);
              await Swal.fire('Payment Not Completed', 'PayMongo did not verify a payment. No amount was applied to the booking.', 'warning');
              return false;
            }
          } catch (_) {
          }
          await new Promise(resolve => window.setTimeout(resolve, 2500));
        }
        sessionStorage.removeItem(hotelPayMongoPendingKey);
        await Swal.fire('Confirmation Pending', 'The QR remains active, but confirmation is taking longer than expected. Refresh the bookings page to check again.', 'info');
        return false;
      };

      const startHotelPayMongoCheckout = async (bookingId, amount, bookingReference = '') => {
        const formData = new FormData();
        formData.append('type', 'hotel');
        formData.append('id', String(bookingId));
        formData.append('amount', Number(amount).toFixed(2));
        formData.append('csrf_token', hotelPayMongoCsrf);
        const response = await fetch(hotelPayMongoEndpoint, { method: 'POST', body: formData, headers: { Accept: 'application/json' } });
        const data = await readHotelPaymentJson(response);
        if (!response.ok || !data.success) throw new Error(data.message || 'The PayMongo QR could not be opened.');
        closeHotelBalancePayment();
        const checkoutUrl = new URL(String(data.checkout_url || ''));
        if (checkoutUrl.protocol !== 'https:' || (checkoutUrl.hostname !== 'checkout.paymongo.com' && !checkoutUrl.hostname.endsWith('.paymongo.com'))) {
          throw new Error('PayMongo returned an invalid checkout URL.');
        }
        const token = String(data.return_token || '');
        sessionStorage.setItem(hotelPayMongoPendingKey, JSON.stringify({ token, bookingId, bookingReference, checkoutUrl: checkoutUrl.href }));

        if (data.phone_notification?.sent && /^[a-f0-9]{64}$/.test(token)) {
          return pollHotelPayMongo(token, bookingId);
        }

        const choice = await Swal.fire({
          icon: data.phone_notification?.registered ? 'warning' : 'info',
          title: data.phone_notification?.registered ? 'Phone Delivery Failed' : 'No Payment Phone Registered',
          text: data.phone_notification?.message || 'Register a hotel payment phone, or open the secure QR on this computer.',
          showCancelButton: true,
          confirmButtonText: 'Open QR on This Computer',
          cancelButtonText: 'Keep Booking Unpaid',
          confirmButtonColor: '#2b7a66'
        });
        if (choice.isConfirmed) window.location.assign(checkoutUrl.href);
        return false;
      };

      const returnParams = new URLSearchParams(window.location.search);
      const paymentReturn = returnParams.get('payment_return');
      const paymentReturnToken = returnParams.get('payment_return_token') || '';
      if (['paymongo', 'cancelled'].includes(paymentReturn) && /^[a-f0-9]{64}$/.test(paymentReturnToken)) {
        sessionStorage.removeItem(hotelPayMongoPendingKey);
        const cleanReturnUrl = new URL(window.location.href);
        cleanReturnUrl.searchParams.delete('payment_return');
        cleanReturnUrl.searchParams.delete('payment_return_token');
        window.history.replaceState({}, document.title, cleanReturnUrl.href);
        if (paymentReturn === 'paymongo') pollHotelPayMongo(paymentReturnToken);
        else Swal.fire('Payment Not Completed', 'The PayMongo QR payment was cancelled. No amount was applied to the booking.', 'warning');
      } else {
        // A regular refresh must not reopen the waiting dialog. PayMongo still
        // tracks the transaction server-side and reconciliation remains active.
        sessionStorage.removeItem(hotelPayMongoPendingKey);
      }

      const phoneSetupModal = document.getElementById('hoPhoneSetupModal');
      const phoneOverviewModal = document.getElementById('hoPhoneOverviewModal');
      const fetchHotelPhone = async () => {
        const response = await fetch(hotelPhoneStatusEndpoint, { credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'application/json' } });
        const payload = await readHotelPaymentJson(response);
        if (!response.ok || !payload.success) throw new Error(payload.message || 'The registered hotel phone could not be checked.');
        return payload;
      };
      const renderHotelPhone = payload => {
        const targets = [
          ['hoQrDeviceName', 'hoQrDeviceMeta', 'hoManagePaymentPhone'],
          ['hoWalkinQrDeviceName', 'hoWalkinQrDeviceMeta', 'hoWalkinManagePaymentPhone']
        ].map(ids => ids.map(id => document.getElementById(id))).filter(items => items.every(Boolean));
        if (payload?.registered && payload.device) {
          hotelPhoneState.device = payload.device;
          const registered = new Date(String(payload.device.last_used_at || payload.device.created_at || '').replace(' ', 'T'));
          targets.forEach(([name, meta, action]) => {
            action.disabled = false;
            name.textContent = payload.device.device_name || 'Hotel administrator phone';
            meta.textContent = Number.isNaN(registered.getTime()) ? 'Notifications active on this phone.' : `Notifications active · Registered ${registered.toLocaleString()}`;
            action.textContent = 'Change';
          });
          const overviewName = document.getElementById('hoPhoneOverviewName');
          const overviewMeta = document.getElementById('hoPhoneOverviewMeta');
          const overviewAction = document.getElementById('hoPhoneOverviewAction');
          if (overviewName) overviewName.textContent = payload.device.device_name || 'Hotel administrator phone';
          if (overviewMeta) overviewMeta.textContent = Number.isNaN(registered.getTime()) ? 'Ready to receive PayMongo payment notifications.' : `Notifications active · Registered ${registered.toLocaleString()}`;
          if (overviewAction) overviewAction.textContent = 'Change';
          document.getElementById('hoPhoneOverviewCard')?.classList.add('is-registered');
        } else {
          hotelPhoneState.device = null;
          targets.forEach(([name, meta, action]) => {
            action.disabled = false;
            name.textContent = 'No hotel phone registered';
            meta.textContent = 'Register a phone to receive PayMongo QR notifications.';
            action.textContent = 'Register a Phone';
          });
          const overviewName = document.getElementById('hoPhoneOverviewName');
          const overviewMeta = document.getElementById('hoPhoneOverviewMeta');
          const overviewAction = document.getElementById('hoPhoneOverviewAction');
          if (overviewName) overviewName.textContent = 'No payment phone registered';
          if (overviewMeta) overviewMeta.textContent = 'Register a phone before sending PayMongo QR notifications.';
          if (overviewAction) overviewAction.textContent = 'Register Phone';
          document.getElementById('hoPhoneOverviewCard')?.classList.remove('is-registered');
        }
      };
      const refreshHotelPhone = async (silent = false) => {
        if (!silent) {
          ['hoQrDeviceName', 'hoWalkinQrDeviceName'].forEach(id => { const item = document.getElementById(id); if (item) item.textContent = 'Checking registered phone...'; });
          ['hoQrDeviceMeta', 'hoWalkinQrDeviceMeta'].forEach(id => { const item = document.getElementById(id); if (item) item.textContent = 'Please wait.'; });
        }
        try {
          const payload = await fetchHotelPhone();
          renderHotelPhone(payload);
          return payload;
        } catch (error) {
          if (!silent) {
            ['hoQrDeviceName', 'hoWalkinQrDeviceName'].forEach(id => { const item = document.getElementById(id); if (item) item.textContent = 'Unable to check registered phone'; });
            ['hoQrDeviceMeta', 'hoWalkinQrDeviceMeta'].forEach(id => { const item = document.getElementById(id); if (item) item.textContent = error.message; });
          }
          return null;
        }
      };
      const stopHotelPhonePolling = () => {
        if (hotelPhoneState.paymentTimer) window.clearInterval(hotelPhoneState.paymentTimer);
        hotelPhoneState.paymentTimer = null;
      };
      const startHotelPhonePolling = () => {
        stopHotelPhonePolling();
        refreshHotelPhone();
        hotelPhoneState.paymentTimer = window.setInterval(() => {
          if (document.getElementById('hoBalancePaymentMethod')?.value !== 'qr_code' || !balancePaymentModal?.classList.contains('open')) return stopHotelPhonePolling();
          refreshHotelPhone(true);
        }, 2500);
      };
      const buildHotelPhoneSetupUrl = () => {
        if (hotelPublicAppUrl) {
          const base = new URL(hotelPublicAppUrl);
          base.pathname = base.pathname.endsWith('/') ? base.pathname : `${base.pathname}/`;
          base.search = '';
          base.hash = '';
          return new URL(hotelPhoneSetupPage, base).href;
        }
        return new URL(hotelPhoneSetupPage, window.location.href).href;
      };
      const updateHotelRegistrationStatus = (type, title, detail) => {
        const status = document.getElementById('hoPhoneRegistrationStatus');
        status?.classList.toggle('is-success', type === 'success');
        status?.classList.toggle('is-error', type === 'error');
        if (status) { status.querySelector('strong').textContent = title; status.querySelector('small').textContent = detail; }
      };
      const checkHotelPhoneRegistration = async (manual = false) => {
        try {
          const payload = await fetchHotelPhone();
          const device = payload.registered ? payload.device : null;
          const baseline = hotelPhoneState.baseline;
          const changed = device && (!baseline || Number(device.device_id) !== Number(baseline.device_id) || String(device.last_used_at) !== String(baseline.last_used_at));
          renderHotelPhone(payload);
          if (changed) {
            if (hotelPhoneState.registrationTimer) window.clearInterval(hotelPhoneState.registrationTimer);
            hotelPhoneState.registrationTimer = null;
            updateHotelRegistrationStatus('success', 'Hotel phone registered', `${device.device_name || 'Hotel phone'} is ready for payment notifications.`);
          } else if (manual) updateHotelRegistrationStatus('', 'No new phone detected', `Checked ${new Date().toLocaleTimeString()}. Complete registration on the phone, then check again.`);
        } catch (error) {
          updateHotelRegistrationStatus('error', 'Could not check registration', error.message);
        }
      };
      const openPhoneSetup = () => {
        if (!phoneSetupModal) return;
        hotelPhoneState.baseline = hotelPhoneState.device ? { ...hotelPhoneState.device } : null;
        document.getElementById('hoPhoneSetupUrl').value = buildHotelPhoneSetupUrl();
        document.getElementById('hoPhoneSetupTitle').textContent = hotelPhoneState.baseline ? 'Change Registered Payment Phone' : 'Register Payment Phone';
        updateHotelRegistrationStatus('', 'Waiting for phone registration', 'Keep this window open while registering the phone.');
        phoneSetupModal.classList.add('open');
        phoneSetupModal.setAttribute('aria-hidden', 'false');
        if (hotelPhoneState.registrationTimer) window.clearInterval(hotelPhoneState.registrationTimer);
        hotelPhoneState.registrationTimer = window.setInterval(checkHotelPhoneRegistration, 2500);
      };
      const openPhoneOverview = async () => {
        const toolbarButton = document.getElementById('hoOpenPhoneSetup');
        if (toolbarButton) toolbarButton.disabled = true;
        try {
          const payload = await refreshHotelPhone();
          if (!payload) throw new Error('The registered payment phone could not be checked.');
          phoneOverviewModal?.classList.add('open');
          phoneOverviewModal?.setAttribute('aria-hidden', 'false');
        } catch (error) {
          await Swal.fire('Phone Check Failed', error.message || 'The registered payment phone could not be checked.', 'error');
        } finally {
          if (toolbarButton) toolbarButton.disabled = false;
        }
      };
      const closePhoneOverview = () => {
        phoneOverviewModal?.classList.remove('open');
        phoneOverviewModal?.setAttribute('aria-hidden', 'true');
      };
      const requireRegisteredHotelPaymentPhone = async () => {
        try {
          const payload = await fetchHotelPhone();
          renderHotelPhone(payload);
          if (payload.registered && payload.device) return true;
          const choice = await Swal.fire({
            icon: 'error',
            title: 'Payment Phone Required',
            text: 'Register a Hotel Administrator phone first before using QR Code (PayMongo).',
            showCancelButton: true,
            confirmButtonText: 'Register a Phone',
            cancelButtonText: 'Cancel',
            confirmButtonColor: '#2b7a66'
          });
          if (choice.isConfirmed) openPhoneSetup();
          return false;
        } catch (error) {
          await Swal.fire('Phone Check Failed', error.message || 'The registered payment phone could not be checked.', 'error');
          return false;
        }
      };
      const closePhoneSetup = () => {
        if (hotelPhoneState.registrationTimer) window.clearInterval(hotelPhoneState.registrationTimer);
        hotelPhoneState.registrationTimer = null;
        phoneSetupModal?.classList.remove('open');
        phoneSetupModal?.setAttribute('aria-hidden', 'true');
        if (document.getElementById('hoBalancePaymentMethod')?.value === 'qr_code') refreshHotelPhone(true);
      };
      document.getElementById('hoOpenPhoneSetup')?.addEventListener('click', openPhoneOverview);
      document.getElementById('hoPhoneOverviewAction')?.addEventListener('click', () => { closePhoneOverview(); openPhoneSetup(); });
      document.getElementById('hoClosePhoneOverview')?.addEventListener('click', closePhoneOverview);
      document.getElementById('hoClosePhoneOverviewFooter')?.addEventListener('click', closePhoneOverview);
      document.getElementById('hoManagePaymentPhone')?.addEventListener('click', openPhoneSetup);
      document.getElementById('hoWalkinManagePaymentPhone')?.addEventListener('click', openPhoneSetup);
      document.getElementById('hoClosePhoneSetup')?.addEventListener('click', closePhoneSetup);
      document.getElementById('hoClosePhoneSetupFooter')?.addEventListener('click', closePhoneSetup);
      document.getElementById('hoCheckPhoneRegistration')?.addEventListener('click', () => checkHotelPhoneRegistration(true));
      document.getElementById('hoOpenPhoneSetupPage')?.addEventListener('click', () => window.open(document.getElementById('hoPhoneSetupUrl').value, '_blank', 'noopener'));
      document.getElementById('hoCopyPhoneSetupUrl')?.addEventListener('click', async () => {
        const input = document.getElementById('hoPhoneSetupUrl');
        try { await navigator.clipboard.writeText(input.value); Swal.fire({ toast: true, position: 'top-end', icon: 'success', title: 'Phone setup link copied.', showConfirmButton: false, timer: 1800 }); }
        catch (_) { input.select(); document.execCommand('copy'); }
      });
      phoneSetupModal?.addEventListener('mousedown', event => { if (event.target === phoneSetupModal) closePhoneSetup(); });
      phoneOverviewModal?.addEventListener('mousedown', event => { if (event.target === phoneOverviewModal) closePhoneOverview(); });

      const walkinModal = document.getElementById('hoWalkinBookingModal');
      const walkinForm = document.getElementById('hoWalkinBookingForm');
      const openWalkinButton = document.getElementById('hoOpenWalkinBooking');
      const shouldOpenWalkin = <?= json_encode($openWalkinModal) ?>;
      const walkinTouristSearch = document.getElementById('hoWalkinTouristSearch');
      const walkinTouristResults = document.getElementById('hoWalkinTouristResults');
      const walkinTouristId = document.getElementById('hoWalkinTouristId');
      const walkinSelectedTourist = document.getElementById('hoWalkinSelectedTourist');
      const walkinExistingPhone = document.getElementById('hoWalkinExistingPhone');
      let walkinSearchTimer = null;
      let walkinSearchRequest = 0;
      let walkinRoomRequest = 0;
      let walkinRoomTimer = null;
      const formatWalkinMoney = value => `₱${Number(value || 0).toLocaleString('en-PH', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
      })}`;
      const renderWalkinAvatar = (container, tourist) => {
        if (!container) return;
        const initial = String(tourist.full_name || 'T').trim().charAt(0).toUpperCase() || 'T';
        const fallback = document.createElement('b');
        fallback.textContent = initial;
        container.replaceChildren(fallback);
        if (tourist.profile_image) {
          const image = document.createElement('img');
          image.src = tourist.profile_image;
          image.alt = '';
          image.referrerPolicy = 'no-referrer';
          image.addEventListener('error', () => image.remove());
          container.appendChild(image);
        }
      };
      const selectWalkinTourist = tourist => {
        if (!walkinTouristId || !walkinSelectedTourist) return;
        walkinTouristId.value = String(tourist.tourist_id || '');
        walkinSelectedTourist.dataset.name = tourist.full_name || '';
        walkinSelectedTourist.dataset.email = tourist.email || '';
        const avatar = document.getElementById('hoWalkinTouristAvatar');
        const name = document.getElementById('hoWalkinTouristName');
        const email = document.getElementById('hoWalkinTouristEmail');
        renderWalkinAvatar(avatar, tourist);
        if (name) name.textContent = tourist.full_name || 'Tourist';
        if (email) email.textContent = tourist.email || 'No email';
        if (walkinExistingPhone) walkinExistingPhone.value = tourist.phone_number || '';
        if (walkinTouristSearch) walkinTouristSearch.setCustomValidity('');
        walkinSelectedTourist.hidden = false;
        document.getElementById('hoWalkinExistingPhoneWrap').hidden = false;
        const searchShell = walkinTouristSearch?.closest('.ho-walkin-tourist-search');
        if (searchShell) searchShell.hidden = true;
        if (walkinTouristResults) walkinTouristResults.hidden = true;
        const help = document.getElementById('hoWalkinSearchHelp');
        if (help) help.textContent = 'This reservation will be saved in the selected tourist’s account.';
        updateWalkinGuestMode();
      };
      const clearWalkinTourist = () => {
        if (walkinTouristId) walkinTouristId.value = '';
        if (walkinSelectedTourist) {
          walkinSelectedTourist.hidden = true;
          walkinSelectedTourist.dataset.name = '';
          walkinSelectedTourist.dataset.email = '';
        }
        document.getElementById('hoWalkinExistingPhoneWrap').hidden = true;
        const searchShell = walkinTouristSearch?.closest('.ho-walkin-tourist-search');
        if (searchShell) searchShell.hidden = false;
        if (walkinTouristSearch) {
          walkinTouristSearch.value = '';
          walkinTouristSearch.setCustomValidity('');
          window.setTimeout(() => walkinTouristSearch.focus(), 30);
        }
        const help = document.getElementById('hoWalkinSearchHelp');
        if (help) help.textContent = 'Type at least two characters to find a registered tourist.';
        updateWalkinGuestMode();
      };
      const renderWalkinTourists = tourists => {
        if (!walkinTouristResults) return;
        walkinTouristResults.replaceChildren();
        if (!tourists.length) {
          const empty = document.createElement('p');
          empty.className = 'ho-walkin-tourist-empty';
          empty.textContent = 'No matching active tourist accounts found.';
          walkinTouristResults.appendChild(empty);
        } else {
          tourists.forEach(tourist => {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'ho-walkin-tourist-result';
            const avatar = document.createElement('span');
            avatar.className = 'ho-walkin-tourist-avatar';
            renderWalkinAvatar(avatar, tourist);
            const copy = document.createElement('span');
            const name = document.createElement('strong');
            const details = document.createElement('small');
            name.textContent = tourist.full_name || 'Tourist';
            details.textContent = [tourist.email, tourist.phone_number].filter(Boolean).join(' · ') || 'No contact details';
            copy.append(name, details);
            const action = document.createElement('b');
            action.textContent = 'Select';
            button.append(avatar, copy, action);
            button.addEventListener('click', () => selectWalkinTourist(tourist));
            walkinTouristResults.appendChild(button);
          });
        }
        walkinTouristResults.hidden = false;
      };
      const searchWalkinTourists = async query => {
        const requestId = ++walkinSearchRequest;
        const spinner = document.getElementById('hoWalkinSearchSpinner');
        if (spinner) spinner.hidden = false;
        try {
          const response = await fetch(`Hobookings.php?ho_action=search_tourists&q=${encodeURIComponent(query)}`, {
            headers: { Accept: 'application/json' },
            cache: 'no-store'
          });
          if (!response.ok) throw new Error('Tourist search failed');
          const result = await response.json();
          if (requestId === walkinSearchRequest) renderWalkinTourists(Array.isArray(result.tourists) ? result.tourists : []);
        } catch (error) {
          if (requestId === walkinSearchRequest) renderWalkinTourists([]);
          console.error(error);
        } finally {
          if (requestId === walkinSearchRequest && spinner) spinner.hidden = true;
        }
      };
      const updateWalkinGuestMode = () => {
        if (!walkinForm) return;
        const mode = walkinForm.querySelector('input[name="walkin_guest_mode"]:checked')?.value || 'existing';
        const existingPanel = document.getElementById('hoWalkinExistingGuest');
        const newPanel = document.getElementById('hoWalkinNewGuest');
        const isExisting = mode === 'existing';
        if (existingPanel) existingPanel.hidden = !isExisting;
        if (newPanel) newPanel.hidden = isExisting;
        if (!isExisting) walkinTouristSearch?.setCustomValidity('');
        ['walkin_first_name', 'walkin_last_name', 'walkin_phone'].forEach(name => {
          const field = walkinForm.elements[name];
          if (field) field.required = !isExisting;
        });
        if (walkinExistingPhone) walkinExistingPhone.required = isExisting && Boolean(walkinTouristId?.value);
        const note = document.getElementById('hoWalkinGuestModeNote');
        if (note) {
          note.textContent = isExisting
            ? 'Existing tourists will see this reservation in their account after staff creates it.'
            : 'A new walk-in booking is recorded without creating a tourist login account.';
        }
      };
      const getWalkinChildAgeInputs = () => [...(document.querySelectorAll('#hoWalkinChildAges input[name="walkin_child_ages[]"]') || [])];
      const getWalkinChildAges = () => getWalkinChildAgeInputs().map(input => input.value === '' ? null : Number(input.value));
      const getWalkinCapacityGuests = () => {
        const adults = Math.max(0, Number(document.getElementById('hoWalkinAdults')?.value || 0));
        const chargeableChildren = getWalkinChildAges().filter(age => age !== null && age >= 8).length;
        return { adults, chargeableChildren, total: adults + chargeableChildren };
      };
      const updateWalkinChildAgeLabels = () => {
        getWalkinChildAgeInputs().forEach(input => {
          const note = input.closest('label')?.querySelector('small');
          if (!note) return;
          const age = input.value === '' ? null : Number(input.value);
          note.textContent = age === null ? 'Enter age' : (age <= 7 ? 'Free · does not count as a guest' : 'Counts toward room capacity');
          note.classList.toggle('free', age !== null && age <= 7);
        });
      };
      const scheduleWalkinRoomSearch = () => {
        window.clearTimeout(walkinRoomTimer);
        walkinRoomTimer = window.setTimeout(loadWalkinAvailableRooms, 180);
      };
      const renderWalkinChildAges = () => {
        const container = document.getElementById('hoWalkinChildAges');
        if (!container) return;
        const existingAges = getWalkinChildAges();
        let savedAges = [];
        try { savedAges = JSON.parse(container.dataset.savedAges || '[]'); } catch (error) { savedAges = []; }
        const count = Math.max(0, Number(document.getElementById('hoWalkinChildren')?.value || 0));
        container.replaceChildren();
        for (let index = 0; index < count; index += 1) {
          const label = document.createElement('label');
          const title = document.createElement('span');
          const input = document.createElement('input');
          const note = document.createElement('small');
          title.textContent = `Child ${index + 1} age`;
          input.type = 'number';
          input.name = 'walkin_child_ages[]';
          input.min = '0';
          input.max = '17';
          input.required = true;
          input.inputMode = 'numeric';
          input.placeholder = 'Age';
          const restoredAge = existingAges[index] ?? savedAges[index];
          input.value = restoredAge === null || restoredAge === undefined ? '' : String(restoredAge);
          input.addEventListener('input', event => {
            event.target.setCustomValidity('');
            updateWalkinChildAgeLabels();
            updateWalkinStay();
            scheduleWalkinRoomSearch();
          });
          input.addEventListener('change', scheduleWalkinRoomSearch);
          label.append(title, input, note);
          container.appendChild(label);
        }
        container.dataset.savedAges = '[]';
        updateWalkinChildAgeLabels();
      };
      const getWalkinDates = () => {
        const checkinValue = document.getElementById('hoWalkinCheckin')?.value || '';
        const checkoutValue = document.getElementById('hoWalkinCheckout')?.value || '';
        const checkinDate = checkinValue ? new Date(`${checkinValue}T00:00:00`) : null;
        const checkoutDate = checkoutValue ? new Date(`${checkoutValue}T00:00:00`) : null;
        const valid = checkinDate && checkoutDate
          && !Number.isNaN(checkinDate.getTime())
          && !Number.isNaN(checkoutDate.getTime())
          && checkoutDate > checkinDate;
        const nights = valid ? Math.max(1, Math.round((checkoutDate - checkinDate) / 86400000)) : 0;
        return { checkinValue, checkoutValue, checkinDate, checkoutDate, valid, nights };
      };
      async function loadWalkinAvailableRooms() {
        const roomSelect = document.getElementById('hoWalkinRoom');
        const status = document.getElementById('hoWalkinRoomStatus');
        const spinner = document.getElementById('hoWalkinRoomSpinner');
        if (!roomSelect) return;
        const requestId = ++walkinRoomRequest;
        const dates = getWalkinDates();
        const children = Math.max(0, Number(document.getElementById('hoWalkinChildren')?.value || 0));
        const childAges = getWalkinChildAges();
        const capacity = getWalkinCapacityGuests();
        const partyComplete = capacity.adults >= 1 && childAges.length === children
          && childAges.every(age => age !== null && Number.isInteger(age) && age >= 0 && age <= 17);
        const currentRoomId = roomSelect.value || roomSelect.dataset.savedRoomId || '';
        if (!dates.valid || !partyComplete) {
          roomSelect.replaceChildren(new Option('Complete dates and guest details first', ''));
          roomSelect.disabled = true;
          if (status) status.textContent = 'Complete the stay dates and every child age to see rooms.';
          if (spinner) spinner.hidden = true;
          updateWalkinStay();
          return;
        }

        roomSelect.replaceChildren(new Option('Checking available rooms...', ''));
        roomSelect.disabled = true;
        if (status) status.textContent = 'Checking availability for the selected stay...';
        if (spinner) spinner.hidden = false;
        try {
          const params = new URLSearchParams({
            ho_action: 'walkin_available_rooms',
            checkin: dates.checkinValue,
            checkout: dates.checkoutValue,
            capacity_guests: String(capacity.total)
          });
          const response = await fetch(`Hobookings.php?${params.toString()}`, { headers: { Accept: 'application/json' }, cache: 'no-store' });
          const result = await response.json();
          if (!response.ok || !result.success) throw new Error(result.message || 'Unable to check room availability.');
          if (requestId !== walkinRoomRequest) return;
          const rooms = Array.isArray(result.rooms) ? result.rooms : [];
          roomSelect.replaceChildren(new Option(rooms.length ? 'Select an available room' : 'No matching rooms available', ''));
          rooms.forEach(room => {
            const option = new Option(`${room.name} — ${formatWalkinMoney(room.price)}/night · ${room.capacity} guests`, String(room.id));
            option.dataset.name = room.name;
            option.dataset.price = String(room.price);
            option.dataset.totalCapacity = String(room.capacity);
            option.dataset.adultCapacity = String(room.adultCapacity);
            option.dataset.childCapacity = String(room.childCapacity);
            roomSelect.appendChild(option);
          });
          roomSelect.disabled = rooms.length === 0;
          if (rooms.some(room => String(room.id) === String(currentRoomId))) roomSelect.value = String(currentRoomId);
          roomSelect.dataset.savedRoomId = '';
          if (status) {
            const freeChildren = children - capacity.chargeableChildren;
            status.textContent = `${rooms.length} room${rooms.length === 1 ? '' : 's'} available for ${capacity.total} capacity guest${capacity.total === 1 ? '' : 's'}${freeChildren > 0 ? ` · ${freeChildren} young child${freeChildren === 1 ? '' : 'ren'} stay free` : ''}.`;
          }
        } catch (error) {
          if (requestId !== walkinRoomRequest) return;
          roomSelect.replaceChildren(new Option('Unable to load available rooms', ''));
          roomSelect.disabled = true;
          if (status) status.textContent = error.message || 'Unable to check availability right now.';
        } finally {
          if (requestId === walkinRoomRequest && spinner) spinner.hidden = true;
          updateWalkinStay();
        }
      }
      const getWalkinPricing = () => {
        const roomSelect = document.getElementById('hoWalkinRoom');
        const selectedRoom = roomSelect?.selectedOptions?.[0] || null;
        const rate = Number(selectedRoom?.dataset.price || 0);
        const dates = getWalkinDates();
        return { roomSelect, selectedRoom, rate, dates, total: rate * dates.nights };
      };
      const setWalkinStep = step => {
        if (!walkinForm || !walkinModal) return;
        const nextStep = Math.min(3, Math.max(1, Number(step) || 1));
        walkinForm.dataset.step = String(nextStep);
        walkinForm.querySelectorAll('[data-walkin-panel]').forEach(panelItem => {
          const active = Number(panelItem.getAttribute('data-walkin-panel')) === nextStep;
          panelItem.classList.toggle('active', active);
          panelItem.hidden = !active;
        });
        walkinModal.querySelectorAll('[data-walkin-step-indicator]').forEach(indicator => {
          const indicatorStep = Number(indicator.getAttribute('data-walkin-step-indicator'));
          indicator.classList.toggle('active', indicatorStep === nextStep);
          indicator.classList.toggle('complete', indicatorStep < nextStep);
        });
        if (nextStep === 3) updateWalkinSummary();
        walkinModal.querySelector('.ho-checkin-flow-card')?.scrollTo({ top: 0, behavior: 'smooth' });
      };
      const validateWalkinStep = step => {
        if (!walkinForm) return false;
        if (step === 1) {
          updateWalkinGuestMode();
          const mode = walkinForm.querySelector('input[name="walkin_guest_mode"]:checked')?.value || 'existing';
          if (mode === 'existing' && !walkinTouristId?.value) {
            walkinTouristSearch?.setCustomValidity('Search for and select a tourist account.');
            walkinTouristSearch?.reportValidity();
            return false;
          }
        }
        const panelItem = walkinForm.querySelector(`[data-walkin-panel="${step}"]`);
        const requiredFields = [...(panelItem?.querySelectorAll('input[required], select[required], textarea[required]') || [])];
        const invalid = requiredFields.find(field => !field.checkValidity());
        if (invalid) {
          invalid.reportValidity();
          return false;
        }
        if (step === 2) {
          const { selectedRoom, dates } = getWalkinPricing();
          if (!dates.valid) {
            document.getElementById('hoWalkinCheckout')?.setCustomValidity('Check-out must be after check-in.');
            document.getElementById('hoWalkinCheckout')?.reportValidity();
            return false;
          }
          if (!selectedRoom?.value) {
            const status = document.getElementById('hoWalkinRoomStatus');
            const roomSelect = document.getElementById('hoWalkinRoom');
            if (status) status.textContent = roomSelect?.disabled
              ? 'No available room matches these dates and capacity requirements.'
              : 'Select one of the available rooms before continuing.';
            if (!roomSelect?.disabled) roomSelect?.focus();
            return false;
          }
          const guests = getWalkinCapacityGuests().total;
          const capacity = Number(selectedRoom?.dataset.totalCapacity || 0);
          if (guests > capacity) {
            const adultsInput = document.getElementById('hoWalkinAdults');
            adultsInput?.setCustomValidity(`This room can accommodate up to ${capacity} guest${capacity === 1 ? '' : 's'}.`);
            adultsInput?.reportValidity();
            return false;
          }
        }
        return true;
      };
      const updateWalkinStay = () => {
        const { selectedRoom, rate, dates, total } = getWalkinPricing();
        const roomName = selectedRoom?.dataset.name || '—';
        const capacity = Number(selectedRoom?.dataset.totalCapacity || 0);
        const adults = Number(document.getElementById('hoWalkinAdults')?.value || 0);
        const children = Number(document.getElementById('hoWalkinChildren')?.value || 0);
        const capacityGuests = getWalkinCapacityGuests();
        const freeChildren = Math.max(0, children - capacityGuests.chargeableChildren);
        const rateLabel = document.getElementById('hoWalkinNightlyRate');
        const capacityNote = document.getElementById('hoWalkinCapacityNote');
        if (rateLabel) rateLabel.textContent = formatWalkinMoney(rate);
        if (capacityNote) {
          capacityNote.textContent = selectedRoom?.value
            ? `${roomName} accommodates ${capacity} capacity guests. This party uses ${capacityGuests.total}${freeChildren ? `; ${freeChildren} child${freeChildren === 1 ? '' : 'ren'} age 7 or below stay free` : ''}.`
            : 'Only children aged 8 and above count toward room capacity.';
          capacityNote.classList.toggle('warning', Boolean(selectedRoom?.value) && capacityGuests.total > capacity);
        }
        const reviewRate = document.getElementById('hoWalkinReviewRate');
        const reviewNights = document.getElementById('hoWalkinReviewNights');
        const reviewTotal = document.getElementById('hoWalkinReviewTotal');
        if (reviewRate) reviewRate.textContent = formatWalkinMoney(rate);
        if (reviewNights) reviewNights.textContent = `${dates.nights} night${dates.nights === 1 ? '' : 's'}`;
        if (reviewTotal) reviewTotal.textContent = formatWalkinMoney(total);
      };
      function updateWalkinSummary() {
        if (!walkinForm) return;
        updateWalkinStay();
        const { selectedRoom, dates, total } = getWalkinPricing();
        const guestMode = walkinForm.querySelector('input[name="walkin_guest_mode"]:checked')?.value || 'existing';
        const firstName = walkinForm.elements.walkin_first_name?.value.trim() || '';
        const lastName = walkinForm.elements.walkin_last_name?.value.trim() || '';
        const guestName = guestMode === 'existing'
          ? (walkinSelectedTourist?.dataset.name || '—')
          : (`${firstName} ${lastName}`.trim() || '—');
        const adults = Number(walkinForm.elements.walkin_adults?.value || 0);
        const children = Number(walkinForm.elements.walkin_children?.value || 0);
        const capacityGuests = getWalkinCapacityGuests();
        const paymentOption = walkinForm.querySelector('input[name="walkin_payment_option"]:checked')?.value || 'full';
        const partialField = document.getElementById('hoWalkinPartialField');
        const partialInput = document.getElementById('hoWalkinPartialAmount');
        const methodField = document.getElementById('hoWalkinPaymentMethodField');
        const methodInput = document.getElementById('hoWalkinPaymentMethod');
        const walkinQrDevice = document.getElementById('hoWalkinQrDeviceSection');
        const isPartial = paymentOption === 'partial';
        const hasPayment = paymentOption !== 'unpaid';
        if (partialField) partialField.hidden = !isPartial;
        if (methodField) methodField.hidden = !hasPayment;
        if (methodInput) methodInput.required = hasPayment;
        if (walkinQrDevice) walkinQrDevice.hidden = !hasPayment || methodInput?.value !== 'qr_code';
        if (partialInput) {
          partialInput.required = isPartial;
          partialInput.max = Math.max(0, total - 0.01).toFixed(2);
        }
        const paid = paymentOption === 'full'
          ? total
          : (isPartial ? Math.max(0, Number(partialInput?.value || 0)) : 0);
        const balance = Math.max(0, total - paid);
        const values = {
          hoWalkinSummaryGuest: guestName,
          hoWalkinSummaryRoom: selectedRoom?.dataset.name || '—',
          hoWalkinSummaryDates: dates.valid ? `${dates.checkinValue} to ${dates.checkoutValue}` : '—',
          hoWalkinSummaryGuests: `${adults + children} total (${adults}A / ${children}C) · ${capacityGuests.total} count toward capacity`,
          hoWalkinSummaryPaid: formatWalkinMoney(paid),
          hoWalkinSummaryBalance: formatWalkinMoney(balance)
        };
        Object.entries(values).forEach(([id, value]) => {
          const target = document.getElementById(id);
          if (target) target.textContent = value;
        });
      }
      const openWalkinModal = () => {
        if (!walkinModal || !walkinForm) return;
        walkinModal.classList.add('open');
        walkinModal.setAttribute('aria-hidden', 'false');
        renderWalkinChildAges();
        scheduleWalkinRoomSearch();
        setWalkinStep(1);
        updateWalkinStay();
        const existingMode = walkinForm.querySelector('input[name="walkin_guest_mode"]:checked')?.value === 'existing';
        const firstField = existingMode
          ? (walkinTouristId?.value ? walkinExistingPhone : walkinTouristSearch)
          : walkinForm.querySelector('[name="walkin_first_name"]');
        window.setTimeout(() => firstField?.focus(), 80);
      };
      const closeWalkinModal = () => {
        if (!walkinModal) return;
        walkinModal.classList.remove('open');
        walkinModal.setAttribute('aria-hidden', 'true');
      };

      openWalkinButton?.addEventListener('click', openWalkinModal);
      walkinModal?.querySelectorAll('[data-close-walkin]').forEach(button => button.addEventListener('click', closeWalkinModal));
      walkinModal?.addEventListener('click', event => {
        if (event.target === walkinModal) closeWalkinModal();
      });
      walkinForm?.querySelectorAll('input[name="walkin_guest_mode"]').forEach(input => {
        input.addEventListener('change', () => {
          updateWalkinGuestMode();
          updateWalkinSummary();
          const target = input.value === 'existing'
            ? (walkinTouristId?.value ? walkinExistingPhone : walkinTouristSearch)
            : walkinForm.elements.walkin_first_name;
          window.setTimeout(() => target?.focus(), 30);
        });
      });
      walkinTouristSearch?.addEventListener('input', () => {
        walkinTouristSearch.setCustomValidity('');
        window.clearTimeout(walkinSearchTimer);
        const query = walkinTouristSearch.value.trim();
        if (query.length < 2) {
          if (walkinTouristResults) walkinTouristResults.hidden = true;
          return;
        }
        walkinSearchTimer = window.setTimeout(() => searchWalkinTourists(query), 280);
      });
      document.getElementById('hoWalkinChangeTourist')?.addEventListener('click', clearWalkinTourist);
      walkinForm?.querySelectorAll('[data-walkin-next]').forEach(button => {
        button.addEventListener('click', () => {
          const step = Number(walkinForm.dataset.step || 1);
          if (validateWalkinStep(step)) setWalkinStep(step + 1);
        });
      });
      walkinForm?.querySelectorAll('[data-walkin-prev]').forEach(button => {
        button.addEventListener('click', () => setWalkinStep(Number(walkinForm.dataset.step || 1) - 1));
      });
      ['hoWalkinRoom'].forEach(id => {
        document.getElementById(id)?.addEventListener('input', event => {
          event.target.setCustomValidity('');
          updateWalkinStay();
        });
        document.getElementById(id)?.addEventListener('change', event => {
          event.target.setCustomValidity('');
          updateWalkinStay();
        });
      });
      ['hoWalkinCheckin', 'hoWalkinCheckout', 'hoWalkinAdults'].forEach(id => {
        document.getElementById(id)?.addEventListener('input', event => {
          event.target.setCustomValidity('');
          updateWalkinStay();
          scheduleWalkinRoomSearch();
        });
        document.getElementById(id)?.addEventListener('change', scheduleWalkinRoomSearch);
      });
      document.getElementById('hoWalkinChildren')?.addEventListener('input', event => {
        event.target.setCustomValidity('');
        renderWalkinChildAges();
        updateWalkinStay();
        scheduleWalkinRoomSearch();
      });
      document.getElementById('hoWalkinChildren')?.addEventListener('change', scheduleWalkinRoomSearch);
      document.getElementById('hoWalkinCheckin')?.addEventListener('change', event => {
        const checkoutInput = document.getElementById('hoWalkinCheckout');
        if (!checkoutInput || !event.target.value) return;
        const nextDate = new Date(`${event.target.value}T00:00:00`);
        nextDate.setDate(nextDate.getDate() + 1);
        const minimumCheckout = [
          nextDate.getFullYear(),
          String(nextDate.getMonth() + 1).padStart(2, '0'),
          String(nextDate.getDate()).padStart(2, '0')
        ].join('-');
        checkoutInput.min = minimumCheckout;
        if (!checkoutInput.value || checkoutInput.value < minimumCheckout) checkoutInput.value = minimumCheckout;
        updateWalkinStay();
        scheduleWalkinRoomSearch();
      });
      walkinForm?.querySelectorAll('input[name="walkin_payment_option"]').forEach(input => {
        input.addEventListener('change', updateWalkinSummary);
      });
      document.getElementById('hoWalkinPaymentMethod')?.addEventListener('change', event => {
        updateWalkinSummary();
        if (event.target.value === 'qr_code') refreshHotelPhone();
      });
      document.getElementById('hoWalkinPartialAmount')?.addEventListener('input', event => {
        event.target.setCustomValidity('');
        updateWalkinSummary();
      });
      walkinForm?.addEventListener('submit', async event => {
        if (walkinForm.dataset.confirmed === '1') return;
        event.preventDefault();
        if (Number(walkinForm.dataset.step || 1) < 3) {
          walkinForm.querySelector(`[data-walkin-panel="${walkinForm.dataset.step || 1}"] [data-walkin-next]`)?.click();
          return;
        }
        updateWalkinSummary();
        const partialInput = document.getElementById('hoWalkinPartialAmount');
        const paymentOption = walkinForm.querySelector('input[name="walkin_payment_option"]:checked')?.value;
        const total = getWalkinPricing().total;
        if (paymentOption === 'partial' && (Number(partialInput?.value || 0) <= 0 || Number(partialInput?.value || 0) >= total)) {
          partialInput?.setCustomValidity('Enter an amount greater than zero and lower than the booking total.');
          partialInput?.reportValidity();
          return;
        }
        const paymentMethod = document.getElementById('hoWalkinPaymentMethod')?.value || '';
        if (paymentOption !== 'unpaid' && !paymentMethod) {
          document.getElementById('hoWalkinPaymentMethod')?.reportValidity();
          return;
        }
        const guestName = document.getElementById('hoWalkinSummaryGuest')?.textContent || 'this guest';
        const confirmed = window.Swal
          ? await Swal.fire({
              icon: 'question',
              title: 'Create confirmed booking?',
              text: `${guestName} will be added to the selected room’s check-in list.`,
              showCancelButton: true,
              confirmButtonText: 'Create booking',
              cancelButtonText: 'Review again',
              confirmButtonColor: '#2b7a66',
              cancelButtonColor: '#6c757d'
            }).then(result => result.isConfirmed)
          : window.confirm(`Create a confirmed booking for ${guestName}?`);
        if (confirmed && paymentMethod === 'qr_code' && paymentOption !== 'unpaid') {
          if (!(await requireRegisteredHotelPaymentPhone())) return;
          const submitButton = walkinForm.querySelector('button[type="submit"]');
          if (submitButton) submitButton.disabled = true;
          try {
            const checkoutAmount = paymentOption === 'full' ? total : Number(partialInput?.value || 0);
            const payload = new FormData(walkinForm);
            payload.append('walkin_ajax', '1');
            const response = await fetch('Hobookings.php', { method: 'POST', body: payload, headers: { Accept: 'application/json' } });
            const data = await readHotelPaymentJson(response);
            if (!response.ok || !data.success) throw new Error(data.message || 'The walk-in booking could not be created.');
            closeWalkinModal();
            await startHotelPayMongoCheckout(data.booking_id, data.checkout_amount || checkoutAmount, data.booking_reference || '');
          } catch (error) {
            Swal.fire('QR Payment Failed', error.message, 'error');
          } finally {
            if (submitButton) submitButton.disabled = false;
          }
        } else if (confirmed) {
          walkinForm.dataset.confirmed = '1';
          walkinForm.submit();
        }
      });
      document.addEventListener('keydown', event => {
        if (event.key === 'Escape' && walkinModal?.classList.contains('open')) closeWalkinModal();
      });
      if (walkinTouristId?.value) {
        const searchShell = walkinTouristSearch?.closest('.ho-walkin-tourist-search');
        if (searchShell) searchShell.hidden = true;
      }
      updateWalkinGuestMode();
      updateWalkinStay();
      updateWalkinSummary();
      if (shouldOpenWalkin) openWalkinModal();

      const profileTooltip = document.createElement('div');
      profileTooltip.className = 'ho-profile-tooltip';
      profileTooltip.setAttribute('role', 'dialog');
      document.body.appendChild(profileTooltip);

      const profileDrawer = document.getElementById('hoTouristProfileDrawer');
      const profileDrawerContent = document.getElementById('hoTouristProfileContent');
      let profileHideTimer = null;
      let activeProfileTrigger = null;

      const escapeProfileText = (value) => String(value ?? '').replace(/[&<>"']/g, character => ({
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#039;'
      }[character]));

      const formatProfileDate = (value) => {
        if (!value) return 'Not available';
        const parsed = new Date(String(value).replace(' ', 'T'));
        if (Number.isNaN(parsed.getTime())) return String(value);
        return parsed.toLocaleDateString('en-PH', {
          year: 'numeric',
          month: 'long',
          day: 'numeric'
        });
      };

      const positionProfileTooltip = (trigger) => {
        const triggerRect = trigger.getBoundingClientRect();
        const tooltipRect = profileTooltip.getBoundingClientRect();
        const spacing = 9;
        const pageTop = window.scrollY;
        const pageLeft = window.scrollX;
        let top = triggerRect.bottom + pageTop + spacing;
        let left = triggerRect.left + pageLeft;

        if (left + tooltipRect.width > pageLeft + window.innerWidth - 12) {
          left = pageLeft + window.innerWidth - tooltipRect.width - 12;
        }
        left = Math.max(pageLeft + 12, left);

        if (top + tooltipRect.height > pageTop + window.innerHeight - 12) {
          top = triggerRect.top + pageTop - tooltipRect.height - spacing;
        }
        top = Math.max(pageTop + 12, top);

        profileTooltip.style.top = `${Math.round(top)}px`;
        profileTooltip.style.left = `${Math.round(left)}px`;
      };

      const hideProfileTooltip = (immediate = false) => {
        window.clearTimeout(profileHideTimer);
        const hide = () => {
          profileTooltip.classList.remove('show');
          profileTooltip.style.display = 'none';
        };
        if (immediate) hide();
        else profileHideTimer = window.setTimeout(hide, 150);
      };

      const closeProfileDrawer = () => {
        if (!profileDrawer) return;
        profileDrawer.classList.remove('show');
        profileDrawer.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('ho-tourist-profile-open');
        activeProfileTrigger?.focus();
      };

      const openProfileDrawer = (trigger) => {
        if (!profileDrawer || !profileDrawerContent || !trigger) return;
        hideProfileTooltip(true);
        activeProfileTrigger = trigger;

        const data = trigger.dataset;
        const name = escapeProfileText(data.fullname || 'Guest');
        const email = escapeProfileText(data.email || '-');
        const phone = escapeProfileText(data.phone || '-');
        const address = escapeProfileText(data.address || '-');
        const profileSrc = escapeProfileText(data.profileSrc || 'img/profileicon2.png');
        const touristId = escapeProfileText(data.touristId || 'Walk-in');
        const totalBookings = Number(data.totalBookings || 0);
        const completedBookings = Number(data.completedBookings || 0);
        const rawStatus = data.accountStatus || 'Guest';
        const accountStatus = escapeProfileText(rawStatus.charAt(0).toUpperCase() + rawStatus.slice(1));
        const verified = data.emailVerified === '1';
        const googleConnected = data.googleConnected === '1';
        const joinedDate = escapeProfileText(formatProfileDate(data.createdAt));
        const updatedDate = escapeProfileText(formatProfileDate(data.updatedAt));
        const emailHref = data.email && data.email !== '-' ? `mailto:${encodeURIComponent(data.email)}` : '#';
        const icon = {
          mail: '<svg viewBox="0 0 24 24"><rect x="3" y="5" width="18" height="14" rx="2"></rect><path d="m4 7 8 6 8-6"></path></svg>',
          phone: '<svg viewBox="0 0 24 24"><path d="M7 3H4a1 1 0 0 0-1 1c0 9.4 7.6 17 17 17a1 1 0 0 0 1-1v-3l-4-2-2 3a15 15 0 0 1-9-9l3-2-2-4Z"></path></svg>',
          map: '<svg viewBox="0 0 24 24"><path d="M20 10c0 5-8 12-8 12S4 15 4 10a8 8 0 1 1 16 0Z"></path><circle cx="12" cy="10" r="2.5"></circle></svg>',
          shield: '<svg viewBox="0 0 24 24"><path d="M12 3 4.5 6v5.5c0 4.8 3.2 8 7.5 9.5 4.3-1.5 7.5-4.7 7.5-9.5V6L12 3Z"></path><path d="m9 12 2 2 4-4"></path></svg>'
        };

        profileDrawerContent.innerHTML = `
          <section class="ho-tp-hero">
            <img src="${profileSrc}" alt="">
            <div class="ho-tp-hero-copy">
              <span class="ho-tp-eyebrow">HOTEL GUEST PROFILE</span>
              <h2>${name}</h2>
              <p>Tourist ID: ${touristId === 'Walk-in' ? touristId : `#${touristId}`}</p>
              <div class="ho-tp-hero-pills">
                <span>${accountStatus}</span>
                <span class="${verified ? 'is-positive' : 'is-warning'}">${verified ? 'Email verified' : 'Email unverified'}</span>
              </div>
            </div>
          </section>
          <section class="ho-tp-stats" aria-label="Guest booking summary">
            <div><strong>${totalBookings}</strong><span>Hotel bookings</span></div>
            <div><strong>${completedBookings}</strong><span>Completed stays</span></div>
            <div><strong>${joinedDate}</strong><span>Member since</span></div>
          </section>
          <section class="ho-tp-section">
            <div class="ho-tp-section-heading"><span>${icon.mail}</span><div><h3>Contact information</h3><p>Primary contact details for this guest</p></div></div>
            <div class="ho-tp-info-list">
              <div class="ho-tp-info-row"><span>${icon.mail}</span><div><small>Email address</small><strong>${email}</strong></div></div>
              <div class="ho-tp-info-row"><span>${icon.phone}</span><div><small>Phone number</small><strong>${phone}</strong></div></div>
              <div class="ho-tp-info-row"><span>${icon.map}</span><div><small>Home address</small><strong>${address}</strong></div></div>
            </div>
            <a class="ho-tp-email-action${emailHref === '#' ? ' is-disabled' : ''}" href="${emailHref}" ${emailHref === '#' ? 'aria-disabled="true"' : ''}>
              ${icon.mail}<span>Send email to guest</span><b aria-hidden="true">&rarr;</b>
            </a>
          </section>
          <section class="ho-tp-section">
            <div class="ho-tp-section-heading"><span>${icon.shield}</span><div><h3>Account information</h3><p>Registration and access details</p></div></div>
            <div class="ho-tp-account-grid">
              <div><small>Account status</small><strong>${accountStatus}</strong></div>
              <div><small>Email verification</small><strong>${verified ? 'Verified' : 'Not verified'}</strong></div>
              <div><small>Sign-in connection</small><strong>${googleConnected ? 'Google connected' : 'Email and password'}</strong></div>
              <div><small>Last profile update</small><strong>${updatedDate}</strong></div>
            </div>
          </section>
        `;

        profileDrawer.classList.add('show');
        profileDrawer.setAttribute('aria-hidden', 'false');
        document.body.classList.add('ho-tourist-profile-open');
        profileDrawer.querySelector('.ho-tourist-profile-close')?.focus();
      };

      const showProfileTooltip = (trigger) => {
        window.clearTimeout(profileHideTimer);
        activeProfileTrigger = trigger;
        const data = trigger.dataset;
        const name = escapeProfileText(data.fullname || 'Guest');
        const email = escapeProfileText(data.email || '-');
        const phone = escapeProfileText(data.phone || '-');
        const address = escapeProfileText(data.address || '-');
        const profileSrc = escapeProfileText(data.profileSrc || 'img/profileicon2.png');
        const completed = Number(data.completedBookings || 0);

        profileTooltip.innerHTML = `
          <div class="ho-tooltip-accent" aria-hidden="true"></div>
          <div class="ho-tooltip-header">
            <img class="ho-tooltip-avatar" src="${profileSrc}" alt="">
            <div class="ho-tooltip-identity">
              <span class="ho-tooltip-eyebrow">Guest profile</span>
              <div class="ho-tooltip-name">${name}</div>
              <span class="ho-tooltip-booking-count">${completed} completed stay${completed === 1 ? '' : 's'}</span>
            </div>
          </div>
          <div class="ho-tooltip-details">
            <div class="ho-tooltip-detail"><span class="ho-tooltip-detail-icon" aria-hidden="true">&#9993;</span><span><small>Email address</small><strong>${email}</strong></span></div>
            <div class="ho-tooltip-detail"><span class="ho-tooltip-detail-icon" aria-hidden="true">&#9742;</span><span><small>Phone number</small><strong>${phone}</strong></span></div>
            <div class="ho-tooltip-detail"><span class="ho-tooltip-detail-icon" aria-hidden="true">&#9673;</span><span><small>Home address</small><strong>${address}</strong></span></div>
          </div>
          <button type="button" class="ho-tooltip-profile-btn"><span>View full profile</span><span aria-hidden="true">&rarr;</span></button>
        `;
        profileTooltip.style.display = 'block';
        profileTooltip.style.visibility = 'hidden';
        requestAnimationFrame(() => {
          positionProfileTooltip(trigger);
          profileTooltip.style.visibility = 'visible';
          profileTooltip.classList.add('show');
        });
      };

      document.querySelectorAll('.ho-profile-trigger').forEach(trigger => {
        trigger.addEventListener('mouseenter', () => showProfileTooltip(trigger));
        trigger.addEventListener('mouseleave', () => hideProfileTooltip());
        trigger.addEventListener('click', () => openProfileDrawer(trigger));
        trigger.addEventListener('keydown', event => {
          if (event.key === 'Enter' || event.key === ' ') {
            event.preventDefault();
            openProfileDrawer(trigger);
          }
        });
      });

      profileTooltip.addEventListener('mouseenter', () => window.clearTimeout(profileHideTimer));
      profileTooltip.addEventListener('mouseleave', () => hideProfileTooltip());
      profileTooltip.addEventListener('click', event => {
        if (event.target.closest('.ho-tooltip-profile-btn') && activeProfileTrigger) {
          openProfileDrawer(activeProfileTrigger);
        }
      });
      profileDrawer?.querySelectorAll('.ho-tourist-profile-close, .ho-tourist-profile-close-btn').forEach(button => {
        button.addEventListener('click', closeProfileDrawer);
      });
      profileDrawer?.addEventListener('mousedown', event => {
        if (event.target === profileDrawer) closeProfileDrawer();
      });
      document.addEventListener('click', event => {
        if (!profileTooltip.contains(event.target) && !event.target.closest('.ho-profile-trigger')) {
          hideProfileTooltip(true);
        }
      });
      document.addEventListener('keydown', event => {
        if (event.key === 'Escape' && profileDrawer?.classList.contains('show')) {
          closeProfileDrawer();
        }
      });

      const modal = document.getElementById('hoDetailsModal');
      const closeBtn = document.getElementById('hoCloseModal');
      const closeDetailsFooterBtn = document.getElementById('hoCloseDetailsFooter');
      const grid = document.getElementById('hoDetailGrid');
      let lastDetailsTrigger = null;
      const bookingDetailIcons = {
        guest: '<svg viewBox="0 0 24 24"><path d="M20 21a8 8 0 0 0-16 0"/><circle cx="12" cy="7" r="4"/></svg>',
        calendar: '<svg viewBox="0 0 24 24"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 10h18"/></svg>',
        hotel: '<svg viewBox="0 0 24 24"><path d="M4 21V5a2 2 0 0 1 2-2h12a2 2 0 0 1 2 2v16M2 21h20M8 7h2M14 7h2M8 11h2M14 11h2M9 21v-5h6v5"/></svg>',
        users: '<svg viewBox="0 0 24 24"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/></svg>',
        payment: '<svg viewBox="0 0 24 24"><rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20M6 15h2"/></svg>',
        note: '<svg viewBox="0 0 24 24"><path d="M5 3h14a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2H9l-5 4V5a2 2 0 0 1 1-2Z"/><path d="M8 8h8M8 12h5"/></svg>'
      };

      const formatBookingMoney = value => `₱${Number(value || 0).toLocaleString('en-PH', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
      })}`;

      const formatBookingDate = value => {
        if (!value) return '-';
        const parsed = new Date(`${value}T00:00:00`);
        return Number.isNaN(parsed.getTime()) ? value : parsed.toLocaleDateString('en-PH', {
          year: 'numeric',
          month: 'long',
          day: 'numeric'
        });
      };

      const bookingDetailRow = (icon, label, value) => `
        <div class="ho-bd-detail-row">
          <span class="ho-bd-row-icon">${bookingDetailIcons[icon] || ''}</span>
          <div><small>${escapeProfileText(label)}</small><strong>${escapeProfileText(value || '-')}</strong></div>
        </div>
      `;

      const closeBookingDetails = () => {
        modal?.classList.remove('show');
        modal?.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('ho-booking-details-open');
        lastDetailsTrigger?.focus();
      };

      document.querySelectorAll('[data-view]').forEach(btn => {
        btn.addEventListener('click', () => {
          const raw = btn.getAttribute('data-booking');
          if (!raw) return;
          const data = JSON.parse(raw);
          const status = String(data.booking_status || 'pending');
          const statusClass = status.toLowerCase().replace(/[^a-z0-9-]/g, '');
          const paymentStatus = String(data.payment_status || 'unpaid');
          const paymentClass = paymentStatus.toLowerCase().replace(/[^a-z0-9-]/g, '');
          const guestName = String(data.guest || 'Guest');
          const initials = guestName.split(/\s+/).filter(Boolean).slice(0, 2).map(part => part[0]).join('').toUpperCase() || 'G';
          const profileImage = String(data.profile_image || '').trim();
          const guestAvatar = profileImage
            ? `<img src="${escapeProfileText(profileImage)}" alt="${escapeProfileText(guestName)} profile picture" loading="lazy" decoding="async" referrerpolicy="no-referrer" onerror="this.remove();this.parentElement.textContent='${escapeProfileText(initials)}';">`
            : escapeProfileText(initials);
          const guestTotal = Number(data.adults || 0) + Number(data.children || 0);

          grid.innerHTML = `
            <div class="ho-bd-drawer-hero">
              <div class="ho-bd-reference"><span>BOOKING ID</span><strong>${escapeProfileText(data.id)}</strong></div>
              <span class="ho-bd-status-pill ho-bd-status-${statusClass}">${escapeProfileText(status)}</span>
            </div>

            <section class="ho-bd-drawer-section ho-bd-guest-card">
              <div class="ho-bd-guest-avatar">${guestAvatar}</div>
              <div class="ho-bd-guest-primary">
                <small>PRIMARY GUEST</small>
                <h4>${escapeProfileText(guestName)}</h4>
                <p>${escapeProfileText(data.email || '-')}</p>
              </div>
            </section>

            <section class="ho-bd-drawer-section">
              <div class="ho-bd-section-title"><span>${bookingDetailIcons.guest}</span><div><h4>Guest information</h4><p>Contact details used for this reservation</p></div></div>
              <div class="ho-bd-detail-grid">
                ${bookingDetailRow('guest', 'Contact number', data.phone)}
                ${bookingDetailRow('users', 'Guest count', `${guestTotal} total · ${data.adults || 0} adult(s), ${data.children || 0} child(ren)`)}
              </div>
            </section>

            <section class="ho-bd-drawer-section">
              <div class="ho-bd-section-title"><span>${bookingDetailIcons.hotel}</span><div><h4>Stay information</h4><p>Property, room, and reservation schedule</p></div></div>
              <div class="ho-bd-detail-grid">
                ${bookingDetailRow('hotel', 'Hotel', data.hotel)}
                ${bookingDetailRow('hotel', 'Room type', data.room_type)}
                ${bookingDetailRow('calendar', 'Check-in', formatBookingDate(data.checkin))}
                ${bookingDetailRow('calendar', 'Check-out', formatBookingDate(data.checkout))}
                ${bookingDetailRow('hotel', 'Rooms booked', data.rooms)}
                ${bookingDetailRow('calendar', 'Booked on', formatProfileDate(data.created_at))}
              </div>
            </section>

            <section class="ho-bd-drawer-section ho-bd-payment-card">
              <div class="ho-bd-section-title">
                <span>${bookingDetailIcons.payment}</span>
                <div><h4>Payment summary</h4><p>Current financial status of this booking</p></div>
                <button type="button" class="ho-bd-go-billing" data-details-billing data-booking-id="${Number(data.booking_id || 0)}">Go to Billing</button>
              </div>
              <div class="ho-bd-payment-lines">
                <div><span>Total booking amount</span><strong>${formatBookingMoney(data.total)}</strong></div>
                <div><span>Amount received</span><strong>${formatBookingMoney(data.amount_paid)}</strong></div>
                <div class="ho-bd-grand-total"><span>Remaining balance</span><strong>${formatBookingMoney(data.remaining_balance)}</strong></div>
              </div>
              <div class="ho-bd-payment-stats">
                <div><small>PAYMENT OPTION</small><strong>${escapeProfileText(data.payment_type || '-')}</strong></div>
                <div><small>PAYMENT STATUS</small><strong class="ho-bd-payment-${paymentClass}">${escapeProfileText(paymentStatus)}</strong></div>
              </div>
            </section>

            <section class="ho-bd-drawer-section">
              <div class="ho-bd-section-title"><span>${bookingDetailIcons.note}</span><div><h4>Special request</h4><p>Additional notes submitted with the reservation</p></div></div>
              <div class="ho-bd-request">${escapeProfileText(data.special_request || 'No special request provided.')}</div>
            </section>

            <div class="ho-bd-confidence-note">
              <span>${bookingDetailIcons.hotel}</span>
              <p><strong>Booking record verified</strong>Details shown here are loaded from the current hotel booking record.</p>
            </div>
          `;
          grid.querySelector('[data-details-billing]')?.addEventListener('click', event => {
            const bookingId = Number(event.currentTarget.dataset.bookingId || 0);
            if (bookingId <= 0) return;
            closeBookingDetails();
            renderHotelBilling(bookingId, guestName);
          });
          lastDetailsTrigger = btn;
          closeRowActions(null);
          modal.classList.add('show');
          modal.setAttribute('aria-hidden', 'false');
          document.body.classList.add('ho-booking-details-open');
          closeBtn?.focus();
        });
      });

      const rowActions = document.querySelectorAll('[data-row-actions]');
      const positionRowActionsMenu = (wrapper) => {
        const trigger = wrapper.querySelector('[data-row-actions-trigger]');
        const menu = wrapper.querySelector('[data-row-actions-menu]');
        if (!trigger || !menu) return;

        const spacing = 7;
        const viewportPadding = 12;
        const triggerRect = trigger.getBoundingClientRect();
        const menuRect = menu.getBoundingClientRect();

        const availableBelow = window.innerHeight - triggerRect.bottom - viewportPadding;
        const shouldOpenUp = availableBelow < menuRect.height + spacing
          && triggerRect.top > menuRect.height + spacing + viewportPadding;

        const top = shouldOpenUp
          ? Math.max(viewportPadding, triggerRect.top - menuRect.height - spacing)
          : Math.min(triggerRect.bottom + spacing, window.innerHeight - menuRect.height - viewportPadding);

        let left = triggerRect.right - menuRect.width;
        if (left < viewportPadding) {
          left = viewportPadding;
        }
        const maxLeft = window.innerWidth - menuRect.width - viewportPadding;
        if (left > maxLeft) {
          left = Math.max(viewportPadding, maxLeft);
        }

        menu.style.top = `${Math.round(top)}px`;
        menu.style.left = `${Math.round(left)}px`;
        wrapper.classList.toggle('drop-up', shouldOpenUp);
      };

      const closeRowActions = (except) => {
        rowActions.forEach(wrapper => {
          if (except && wrapper === except) return;
          wrapper.classList.remove('open', 'drop-up');
          const trigger = wrapper.querySelector('[data-row-actions-trigger]');
          if (trigger) trigger.setAttribute('aria-expanded', 'false');
          const menu = wrapper.querySelector('[data-row-actions-menu]');
          if (menu) {
            menu.style.top = '-9999px';
            menu.style.left = '-9999px';
          }
        });
      };

      /* =========================================================
   EXPENSE MODAL
========================================================= */

const billingModal = document.getElementById('hoBillingModal');
const billingBody = document.getElementById('hoBillingBody');
const balancePaymentModal = document.getElementById('hoBalancePaymentModal');
const hotelBillingState = { bookingId: 0, reference: '', guest: '', balance: 0, paid: false, canEdit: false };

const closeHotelBilling = () => {
  billingModal?.classList.remove('open');
  billingModal?.setAttribute('aria-hidden', 'true');
  document.body.classList.remove('ho-billing-open');
};

const closeHotelBalancePayment = () => {
  stopHotelPhonePolling();
  balancePaymentModal?.classList.remove('open');
  balancePaymentModal?.setAttribute('aria-hidden', 'true');
};

const renderHotelBilling = async (bookingId, guestName) => {
  if (!billingModal || !billingBody || !bookingId) return;
  hotelBillingState.bookingId = Number(bookingId);
  hotelBillingState.guest = guestName || 'Guest';
  billingModal.classList.add('open');
  billingModal.setAttribute('aria-hidden', 'false');
  document.body.classList.add('ho-billing-open');
  document.getElementById('hoBillingSubtitle').textContent = 'Loading current room charges and payment information…';
  billingBody.innerHTML = '<div class="ho-billing-loading"><span></span><p>Preparing billing statement…</p></div>';
  const payButton = document.getElementById('hoBillingPayBalance');
  const expenseButton = document.getElementById('hoBillingAddExpense');
  const paidNote = document.getElementById('hoBillingPaidNote');
  payButton.disabled = true;
  expenseButton.disabled = true;
  paidNote.hidden = true;

  try {
    const response = await fetch(`Hobookings.php?ho_action=billing_details&booking_id=${encodeURIComponent(bookingId)}`, { headers: { Accept: 'application/json' } });
    const payload = await response.json();
    if (!response.ok || !payload.success || !payload.booking) throw new Error(payload.message || 'Billing details could not be loaded.');

    const booking = payload.booking;
    const expenses = Array.isArray(payload.expenses) ? payload.expenses : [];
    const total = Number(booking.total_amount || 0);
    const amountPaid = Number(booking.amount_paid || 0);
    const balance = Math.max(0, Number(booking.remaining_balance || 0));
    const expenseTotal = expenses.reduce((sum, item) => sum + Number(item.total_amount || 0), 0);
    const checkoutCharges = Number(booking.checkout_additional_charges || 0);
    const roomCharge = Math.max(0, total - expenseTotal - checkoutCharges);
    const checkinPayment = Number(booking.checkin_payment_amount || 0);
    const checkoutPayment = Number(booking.checkout_final_payment_amount || 0);
    const advancePayment = Math.max(0, amountPaid - checkinPayment - checkoutPayment);
    const paymentStatus = balance <= 0 ? 'Paid' : amountPaid > 0 ? 'Partial' : 'Unpaid';
    const bookingStatus = String(booking.booking_status || '').toLowerCase();
    const terminal = ['completed', 'cancelled', 'no-show'].includes(bookingStatus) || Boolean(booking.checked_out_at);
    const guest = String(booking.guest_name || guestName || 'Guest').trim();
    const expenseRows = expenses.length
      ? expenses.map(item => `<div class="ho-billing-line"><span>${escapeProfileText(item.expense_name || item.expense_type || 'Additional charge')}<small>${escapeProfileText(`${item.expense_type || 'Expense'} · ${Number(item.quantity || 1)} × ${formatBookingMoney(item.unit_price)}`)}${item.notes ? ` · ${escapeProfileText(item.notes)}` : ''}</small></span><strong>${formatBookingMoney(item.total_amount)}</strong></div>`).join('')
      : '<div class="ho-billing-line"><span>No additional expenses recorded</span><strong>₱0.00</strong></div>';
    const paymentRows = [
      advancePayment > 0 ? `<div><span>Booking / early payments${booking.balance_payment_method ? ` · ${escapeProfileText(String(booking.balance_payment_method).replace('_', ' ').toUpperCase())}` : ''}</span><strong>${formatBookingMoney(advancePayment)}</strong></div>` : '',
      checkinPayment > 0 ? `<div><span>Paid during check-in · ${escapeProfileText(String(booking.checkin_payment_method || 'Not recorded').replace('_', ' ').toUpperCase())}</span><strong>${formatBookingMoney(checkinPayment)}</strong></div>` : '',
      checkoutPayment > 0 ? `<div><span>Paid during check-out · ${escapeProfileText(String(booking.checkout_payment_method || 'Not recorded').replace('_', ' ').toUpperCase())}</span><strong>${formatBookingMoney(checkoutPayment)}</strong></div>` : ''
    ].filter(Boolean).join('') || '<div><span>No payments recorded</span><strong>₱0.00</strong></div>';

    hotelBillingState.guest = guest;
    hotelBillingState.reference = String(booking.booking_reference || booking.hotel_booking_id || '');
    hotelBillingState.balance = balance;
    hotelBillingState.paid = balance <= 0;
    hotelBillingState.canEdit = !terminal;
    document.getElementById('hoBillingSubtitle').textContent = `${guest} · ${booking.room_type || 'Hotel room'}`;
    billingBody.innerHTML = `
      <div class="ho-billing-summary-top">
        <div class="ho-billing-reference"><small>BOOKING REFERENCE</small><strong>${escapeProfileText(booking.booking_reference || booking.hotel_booking_id)}</strong></div>
        <span class="ho-billing-status ${paymentStatus.toLowerCase()}">${paymentStatus}</span>
      </div>
      <div class="ho-billing-party">
        <div><small>BILLED TO</small><strong>${escapeProfileText(guest)}</strong></div>
        <div><small>ROOM / STAY</small><strong>${escapeProfileText(`${booking.room_type || '-'} · ${booking.nights || 1} night(s)`)}</strong></div>
      </div>
      <h4 class="ho-billing-section-title">Detailed Charges</h4>
      <div class="ho-billing-lines">
        <div class="ho-billing-line"><span>Room accommodation<small>${escapeProfileText(`${booking.checkin_date || '-'} to ${booking.checkout_date || '-'}`)}</small></span><strong>${formatBookingMoney(roomCharge)}</strong></div>
        ${expenseRows}
        ${checkoutCharges > 0 ? `<div class="ho-billing-line"><span>Check-out additional charges</span><strong>${formatBookingMoney(checkoutCharges)}</strong></div>` : ''}
        <div class="ho-billing-line subtotal"><span>Additional charges total</span><strong>${formatBookingMoney(expenseTotal + checkoutCharges)}</strong></div>
        <div class="ho-billing-line total"><span>Total amount due</span><strong>${formatBookingMoney(total)}</strong></div>
      </div>
      <h4 class="ho-billing-section-title">Payment Summary</h4>
      <div class="ho-billing-stats">
        <div class="ho-billing-stat"><small>AMOUNT PAID</small><strong>${formatBookingMoney(amountPaid)}</strong></div>
        <div class="ho-billing-stat balance ${balance <= 0 ? 'zero' : ''}"><small>CURRENT BALANCE</small><strong>${formatBookingMoney(balance)}</strong></div>
        <div class="ho-billing-stat"><small>PAYMENT STATUS</small><strong>${paymentStatus}</strong></div>
        <div class="ho-billing-stat"><small>BOOKING STATUS</small><strong>${escapeProfileText(booking.booking_status || '-')}</strong></div>
      </div>
      <h4 class="ho-billing-section-title">Payment Activity</h4>
      <div class="ho-payment-history">${paymentRows}</div>`;

    payButton.disabled = balance <= 0 || terminal;
    expenseButton.disabled = terminal;
    paidNote.textContent = balance <= 0 ? 'Already paid' : (terminal ? 'Booking closed' : '');
    paidNote.hidden = !(balance <= 0 || terminal);
  } catch (error) {
    document.getElementById('hoBillingSubtitle').textContent = 'Billing statement unavailable';
    billingBody.innerHTML = `<div class="ho-billing-error"><strong>Unable to load billing details</strong><span>${escapeProfileText(error.message)}</span></div>`;
  }
};

document.querySelectorAll('[data-open-billing]').forEach(button => {
  button.addEventListener('click', () => {
    closeRowActions(null);
    renderHotelBilling(button.dataset.bookingId, button.dataset.guest);
  });
});

document.getElementById('hoCloseBilling')?.addEventListener('click', closeHotelBilling);
document.getElementById('hoBillingIconClose')?.addEventListener('click', closeHotelBilling);
billingModal?.addEventListener('mousedown', event => { if (event.target === billingModal) closeHotelBilling(); });

document.getElementById('hoBillingPayBalance')?.addEventListener('click', () => {
  if (hotelBillingState.paid || hotelBillingState.balance <= 0) return;
  closeHotelBilling();
  document.getElementById('hoBalancePaymentDue').textContent = formatBookingMoney(hotelBillingState.balance);
  const amountInput = document.getElementById('hoBalancePaymentAmount');
  document.getElementById('hoBalancePaymentMethod').value = '';
  document.getElementById('hoQrDeviceSection').hidden = true;
  document.getElementById('hoBalancePaymentContext').textContent = 'Record the cash amount received. Partial payments are allowed.';
  document.getElementById('hoConfirmBalancePayment').textContent = 'Confirm Payment';
  amountInput.max = hotelBillingState.balance.toFixed(2);
  amountInput.value = hotelBillingState.balance.toFixed(2);
  balancePaymentModal.classList.add('open');
  balancePaymentModal.setAttribute('aria-hidden', 'false');
  amountInput.focus();
});

document.getElementById('hoBalancePaymentMethod')?.addEventListener('change', event => {
  const isQr = event.target.value === 'qr_code';
  document.getElementById('hoQrDeviceSection').hidden = !isQr;
  document.getElementById('hoBalancePaymentContext').textContent = isQr
    ? 'PayMongo will display a secure QR for the tourist to scan. The balance updates only after payment is verified.'
    : 'Record the cash amount received. Partial payments are allowed.';
  document.getElementById('hoConfirmBalancePayment').textContent = isQr ? 'Open PayMongo QR' : 'Confirm Payment';
  if (isQr) startHotelPhonePolling();
  else stopHotelPhonePolling();
});

document.getElementById('hoConfirmBalancePayment')?.addEventListener('click', async () => {
  const amount = Number(document.getElementById('hoBalancePaymentAmount').value || 0);
  const paymentMethod = document.getElementById('hoBalancePaymentMethod').value;
  if (!paymentMethod) {
    Swal.fire('Payment Method Required', 'Select Cash or QR Code before recording the payment.', 'warning');
    return;
  }
  if (amount <= 0 || amount > hotelBillingState.balance + 0.009) {
    Swal.fire('Invalid Payment', 'Enter an amount greater than zero and not more than the current balance.', 'warning');
    return;
  }
  const button = document.getElementById('hoConfirmBalancePayment');
  button.disabled = true;
  if (paymentMethod === 'qr_code') {
    const originalText = button.textContent;
    button.textContent = 'Opening PayMongo…';
    try {
      if (!(await requireRegisteredHotelPaymentPhone())) return;
      await startHotelPayMongoCheckout(hotelBillingState.bookingId, amount, hotelBillingState.reference || '');
    } catch (error) {
      Swal.fire('QR Payment Failed', error.message, 'error');
    } finally {
      button.disabled = false;
      button.textContent = originalText;
    }
    return;
  }
  const formData = new FormData();
  formData.append('ho_action', 'pay_balance');
  formData.append('booking_id', hotelBillingState.bookingId);
  formData.append('amount', amount.toFixed(2));
  formData.append('payment_method', paymentMethod);
  formData.append('csrf_token', hotelPayMongoCsrf);
  try {
    const response = await fetch('Hobookings.php', { method: 'POST', body: formData });
    const payload = await response.json();
    if (!response.ok || !payload.success) throw new Error(payload.message || 'Payment could not be recorded.');
    closeHotelBalancePayment();
    await Swal.fire({ icon: 'success', title: 'Payment Recorded', text: payload.remaining_balance <= 0 ? 'The booking is now fully paid.' : 'The partial payment was added to the guest account.', confirmButtonColor: '#2b7a66' });
    location.reload();
  } catch (error) {
    Swal.fire('Payment Failed', error.message, 'error');
  } finally {
    button.disabled = false;
  }
});

document.getElementById('hoCloseBalancePayment')?.addEventListener('click', closeHotelBalancePayment);
document.getElementById('hoCancelBalancePayment')?.addEventListener('click', closeHotelBalancePayment);
balancePaymentModal?.addEventListener('mousedown', event => { if (event.target === balancePaymentModal) closeHotelBalancePayment(); });

const expenseModal = document.getElementById('hoExpenseModal');
const closeExpenseModal = document.getElementById('hoCloseExpenseModal');

const expenseBookingId = document.getElementById('hoExpenseBookingId');
const expenseGuest = document.getElementById('hoExpenseGuest');

const openHotelExpenseModal = (bookingId, guest) => {
  expenseModal.querySelector('form')?.reset();
  expenseRows.querySelectorAll('.ho-expense-row').forEach((row, index) => { if (index > 0) row.remove(); });
  expenseBookingId.value = bookingId || '';
  expenseGuest.value = guest || '';
  document.getElementById('hoExpenseGuestDisplay').textContent = guest || 'Guest';
  closeHotelBilling();
  expenseModal.classList.add('open');
  expenseModal.setAttribute('aria-hidden', 'false');
  updateHotelExpenseTotals();
};

document.getElementById('hoBillingAddExpense')?.addEventListener('click', () => {
  if (hotelBillingState.canEdit) openHotelExpenseModal(hotelBillingState.bookingId, hotelBillingState.guest);
});

closeExpenseModal.addEventListener('click', () => {
  expenseModal.classList.remove('open');
  expenseModal.setAttribute('aria-hidden', 'true');
});

expenseModal.addEventListener('click', (e) => {
  if (e.target === expenseModal) {
    expenseModal.classList.remove('open');
    expenseModal.setAttribute('aria-hidden', 'true');
  }
});

/* =========================================================
   ADD / REMOVE EXPENSE ROWS
========================================================= */

const expenseRows = document.getElementById('hoExpenseRows');
const addExpenseRowBtn = document.getElementById('hoAddExpenseRow');

function createExpenseRow() {

  const row = document.createElement('div');
  row.className = 'ho-expense-row';
  row.dataset.expenseRow = '';

  row.innerHTML = `
    <div class="ho-expense-row-head"><span>EXPENSE ITEM</span><strong data-expense-row-total>₱0.00</strong></div>
    <div class="ho-row-field">
      <label>Type</label>
      <select name="expense_type[]" required>
        <option value="">Select</option>
        <option value="Room Upgrade">Room Upgrade</option>
        <option value="Additional Room">Additional Room</option>
        <option value="Activity">Activity</option>
        <option value="Cottage">Cottage</option>
        <option value="Food">Food</option>
        <option value="Others">Others</option>
      </select>
    </div>

    <div class="ho-row-field">
      <label>Description</label>
      <input type="text" name="expense_name[]" placeholder="Enter item or service" required>
    </div>

    <div class="ho-row-field small">
      <label>Qty</label>
      <input type="number" name="quantity[]" min="1" value="1" required>
    </div>

    <div class="ho-row-field small">
      <label>Unit Price</label>
      <div class="ho-expense-money"><span>₱</span><input type="number" name="unit_price[]" min="0.01" step="0.01" placeholder="0.00" required></div>
    </div>

    <div class="ho-row-field notes">
      <label>Notes</label>
      <input type="text" name="notes[]" placeholder="Optional explanation or reference">
    </div>

    <button type="button" class="ho-remove-expense">
      &times;
    </button>
  `;

  expenseRows.appendChild(row);
  updateHotelExpenseTotals();
}

addExpenseRowBtn.addEventListener('click', createExpenseRow);

document.addEventListener('click', (e) => {

  if (!e.target.classList.contains('ho-remove-expense')) {
    return;
  }

  const rows = document.querySelectorAll('.ho-expense-row');

  if (rows.length <= 1) {
    return;
  }

  e.target.closest('.ho-expense-row').remove();
  updateHotelExpenseTotals();

});

function updateHotelExpenseTotals() {
  let grandTotal = 0;
  expenseRows.querySelectorAll('.ho-expense-row').forEach((row, index) => {
    const quantity = Math.max(0, Number(row.querySelector('[name="quantity[]"]')?.value || 0));
    const price = Math.max(0, Number(row.querySelector('[name="unit_price[]"]')?.value || 0));
    const rowTotal = quantity * price;
    grandTotal += rowTotal;
    const label = row.querySelector('.ho-expense-row-head span');
    const total = row.querySelector('[data-expense-row-total]');
    if (label) label.textContent = `EXPENSE ITEM ${String(index + 1).padStart(2, '0')}`;
    if (total) total.textContent = formatBookingMoney(rowTotal);
  });
  const grandTotalElement = document.getElementById('hoExpenseGrandTotal');
  if (grandTotalElement) grandTotalElement.textContent = formatBookingMoney(grandTotal);
}

expenseRows.addEventListener('input', updateHotelExpenseTotals);
expenseRows.addEventListener('change', updateHotelExpenseTotals);
updateHotelExpenseTotals();

      document.querySelectorAll('[data-row-actions-trigger]').forEach(trigger => {
        trigger.addEventListener('click', (e) => {
          e.preventDefault();
          e.stopPropagation();
          const wrapper = trigger.closest('[data-row-actions]');
          if (!wrapper) return;
          const willOpen = !wrapper.classList.contains('open');
          closeRowActions(wrapper);
          wrapper.classList.toggle('open', willOpen);
          trigger.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
          if (willOpen) {
            requestAnimationFrame(() => positionRowActionsMenu(wrapper));
          }
        });
      });

      document.addEventListener('click', (e) => {
        if (!(e.target instanceof Element)) return;
        if (e.target.closest('[data-row-actions]')) return;
        closeRowActions(null);
      });
      window.addEventListener('resize', () => closeRowActions(null));
      document.addEventListener('scroll', () => closeRowActions(null), true);

      closeBtn.addEventListener('click', closeBookingDetails);
      closeDetailsFooterBtn?.addEventListener('click', closeBookingDetails);
      modal.addEventListener('click', (e) => {
        if (e.target === modal) closeBookingDetails();
      });
      document.addEventListener('keydown', e => {
        if (e.key === 'Escape' && modal.classList.contains('show')) closeBookingDetails();
      });

      document.querySelectorAll('[data-cancel-form]').forEach(form => {
        form.addEventListener('submit', async (e) => {
          if (form.dataset.confirmed === '1') {
            return;
          }
          e.preventDefault();
          if (!window.Swal) {
            const ok = window.confirm('Are you sure you want to cancel this booking?');
            if (ok) {
              form.dataset.confirmed = '1';
              form.submit();
            }
            return;
          }
          const result = await Swal.fire({
            icon: 'warning',
            title: 'Cancel booking?',
            text: 'Are you sure you want to cancel this booking?',
            showCancelButton: true,
            confirmButtonText: 'Yes, cancel booking',
            cancelButtonText: 'Keep booking',
            confirmButtonColor: '#2b7a66',
            cancelButtonColor: '#6c757d'
          });
          if (result.isConfirmed) {
            form.dataset.confirmed = '1';
            form.submit();
          }
        });
      });

      document.querySelectorAll('[data-noshow-form]').forEach(form => {
        form.addEventListener('submit', async (e) => {
          if (form.dataset.confirmed === '1') {
            return;
          }
          e.preventDefault();
          if (!window.Swal) {
            const ok = window.confirm('Mark this booking as no-show?');
            if (ok) {
              form.dataset.confirmed = '1';
              form.submit();
            }
            return;
          }
          const result = await Swal.fire({
            icon: 'question',
            title: 'Mark as no-show?',
            text: 'This booking will be tagged as no-show.',
            showCancelButton: true,
            confirmButtonText: 'Yes, mark no-show',
            cancelButtonText: 'Cancel',
            confirmButtonColor: '#2b7a66',
            cancelButtonColor: '#6c757d'
          });
          if (result.isConfirmed) {
            form.dataset.confirmed = '1';
            form.submit();
          }
        });
      });

      document.querySelectorAll('[data-confirm-form]').forEach(form => {
        form.addEventListener('submit', async (e) => {
          e.preventDefault();
          if (!window.Swal) {
            const ok = window.confirm('Confirm this booking?');
            if (ok) {
              form.submit();
            }
            return;
          }
          const result = await Swal.fire({
            icon: 'question',
            title: 'Confirm booking?',
            text: 'This booking will be marked as confirmed.',
            showCancelButton: true,
            confirmButtonText: 'Yes, confirm',
            cancelButtonText: 'Cancel',
            confirmButtonColor: '#2b7a66',
            cancelButtonColor: '#6c757d'
          });
          if (!result.isConfirmed) return;

          const progressModal = Swal.fire({
            title: 'Confirming booking',
            text: 'Please wait while the confirmation email is being sent…',
            allowOutsideClick: false,
            allowEscapeKey: false,
            showConfirmButton: false,
            didOpen: () => Swal.showLoading()
          });

          try {
            const formData = new FormData(form);
            formData.set('ajax_booking_action', '1');
            const actionAttribute = form.getAttribute('action');
            const confirmationUrl = actionAttribute
              ? new URL(actionAttribute, window.location.href).href
              : window.location.href;
            const response = await fetch(confirmationUrl, {
              method: 'POST',
              headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
              },
              body: formData
            });
            const responseText = (await response.text()).replace(/^\uFEFF/, '').trim();
            let payload;
            try {
              payload = JSON.parse(responseText);
            } catch (_parseError) {
              throw new Error(response.ok
                ? 'The confirmation response could not be read. Please refresh and check the booking status.'
                : `The server could not complete the confirmation (HTTP ${response.status}).`);
            }
            if (response.status === 401 && payload.code === 'SESSION_EXPIRED' && payload.login_url) {
              Swal.hideLoading();
              Swal.update({
                icon: 'warning',
                title: 'Session expired',
                text: payload.message || 'Please log in again to continue.',
                showConfirmButton: true,
                confirmButtonText: 'Go to Hotel Owner Login',
                confirmButtonColor: '#2b7a66',
                allowOutsideClick: true,
                allowEscapeKey: true
              });
              await progressModal;
              window.location.assign(payload.login_url);
              return;
            }
            const bookingConfirmed = payload.booking_confirmed === true;
            const emailSent = payload.email_status === 'sent';

            Swal.hideLoading();
            Swal.update({
              icon: bookingConfirmed ? (emailSent ? 'success' : 'warning') : 'error',
              title: bookingConfirmed
                ? (emailSent ? 'Booking confirmed' : 'Booking confirmed — email not sent')
                : 'Confirmation failed',
              text: payload.message || (bookingConfirmed
                ? 'The booking was confirmed.'
                : 'The booking could not be confirmed.'),
              showConfirmButton: true,
              confirmButtonText: 'OK',
              confirmButtonColor: '#2b7a66',
              allowOutsideClick: true,
              allowEscapeKey: true
            });

            await progressModal;
            if (bookingConfirmed) {
              window.location.reload();
            }
          } catch (error) {
            Swal.hideLoading();
            Swal.update({
              icon: 'error',
              title: 'Confirmation failed',
              text: error.message || 'The booking could not be confirmed. Please try again.',
              showConfirmButton: true,
              confirmButtonText: 'Close',
              confirmButtonColor: '#b42336',
              allowOutsideClick: true,
              allowEscapeKey: true
            });
            await progressModal;
          }
        });
      });

      if (actionNoticeText) {
        if (window.Swal) {
          Swal.fire({
            icon: actionNoticeIcon || 'info',
            title: actionNoticeIcon === 'error' ? 'Action failed' : 'Success',
            text: actionNoticeText,
            confirmButtonColor: '#2b7a66'
          }).then(() => {
            if (!window.history?.replaceState) return;
            const url = new URL(window.location.href);
            if (url.searchParams.has('action_notice')) {
              url.searchParams.delete('action_notice');
              url.searchParams.delete('email_status');
              window.history.replaceState({}, '', `${url.pathname}${url.search}${url.hash}`);
            }
          });
        } else {
          alert(actionNoticeText);
        }
      }

    })();
  </script>
</body>
</html>

