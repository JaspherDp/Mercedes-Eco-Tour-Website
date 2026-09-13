<?php
declare(strict_types=1);

require_once __DIR__ . '/../php/session_security.php';
AppSessionStart();

require_once __DIR__ . '/../php/db_connection.php';
require_once __DIR__ . '/../php/admin_auth_helper.php';
require_once __DIR__ . '/../php/operator_auth_helper.php';
require_once __DIR__ . '/../php/tourist_auth_helper.php';
require_once __DIR__ . '/../php/activity_logger.php';
require_once __DIR__ . '/../php/firebase_admin_messaging.php';
require_once __DIR__ . '/../php/input_validation.php';
require_once __DIR__ . '/PayMongoService.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function balanceCheckoutResponse(int $status, array $body): never
{
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function balanceCheckoutTableExists(PDO $pdo): bool
{
    $stmt = $pdo->query(
        "SELECT COUNT(*) FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payment_transactions'"
    );
    return (int)$stmt->fetchColumn() > 0;
}

function balanceCheckoutMarkCreationFailure(PDO $pdo, int $transactionId, string $message): void
{
    try {
        $stmt = $pdo->prepare(
            "UPDATE payment_transactions
             SET status = 'failed', failed_at = NOW(), failure_message = ?
             WHERE payment_transaction_id = ? AND provider_checkout_session_id IS NULL"
        );
        $stmt->execute([substr($message, 0, 1000), $transactionId]);
    } catch (Throwable $ignored) {
    }
}

function balanceCheckoutDateLabel(?string $date): string
{
    $date = trim((string)$date);
    if ($date === '') {
        return '';
    }
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    return $parsed instanceof DateTimeImmutable ? $parsed->format('M j, Y') : '';
}

function balanceCheckoutDetail(string $value, int $limit = 80): string
{
    $value = preg_replace('/\s+/u', ' ', str_replace(['|', "\r", "\n"], ' ', trim($value))) ?: '';
    return mb_substr($value, 0, $limit);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    balanceCheckoutResponse(405, ['success' => false, 'message' => 'Method not allowed.']);
}

$hasAdminSession = ($_SESSION['admin_logged_in'] ?? false) === true;
$hasHotelAdminSession = ($_SESSION['hotel_admin_logged_in'] ?? false) === true;
$hasOperatorSession = ($_SESSION['operator_logged_in'] ?? false) === true;
$touristId = (int)($_SESSION['tourist_id'] ?? 0);
$submittedCsrf = (string)($_POST['csrf_token'] ?? '');
$adminCsrf = (string)($_SESSION['paymongo_admin_csrf'] ?? '');
$touristCsrf = (string)($_SESSION['paymongo_balance_csrf'] ?? '');
$hotelAdminCsrf = (string)($_SESSION['paymongo_hotel_admin_csrf'] ?? '');
$operatorCsrf = (string)($_SESSION['paymongo_operator_csrf'] ?? '');

// Admin and tourist authentication can coexist in the same PHP session when
// both panels are opened in one browser. Select the payment flow using the
// CSRF token issued by the page that initiated this request instead of always
// giving the admin session precedence.
$isAdminStaff = $hasAdminSession
    && $submittedCsrf !== ''
    && $adminCsrf !== ''
    && hash_equals($adminCsrf, $submittedCsrf);
$isTouristPayment = $touristId > 0
    && $submittedCsrf !== ''
    && $touristCsrf !== ''
    && hash_equals($touristCsrf, $submittedCsrf);
$isHotelAdminStaff = $hasHotelAdminSession
    && $submittedCsrf !== ''
    && $hotelAdminCsrf !== ''
    && hash_equals($hotelAdminCsrf, $submittedCsrf);
$isOperatorStaff = $hasOperatorSession
    && $submittedCsrf !== ''
    && $operatorCsrf !== ''
    && hash_equals($operatorCsrf, $submittedCsrf);
$isStaffPayment = $isAdminStaff || $isHotelAdminStaff || $isOperatorStaff;
$requestAdminId = $isAdminStaff ? (int)($_SESSION['admin_id'] ?? 0) : 0;
$requestHotelAdminId = $isHotelAdminStaff ? (int)($_SESSION['hotel_admin_id'] ?? 0) : 0;
$requestHotelResortId = $isHotelAdminStaff ? (int)($_SESSION['hotel_admin_hotel_resort_id'] ?? 0) : 0;
$requestOperatorId = $isOperatorStaff ? (int)($_SESSION['operator_id'] ?? 0) : 0;

if (!$hasAdminSession && !$hasHotelAdminSession && !$hasOperatorSession && $touristId < 1) {
    balanceCheckoutResponse(401, ['success' => false, 'message' => 'Please log in to continue.']);
}
if (!$isStaffPayment && !$isTouristPayment) {
    balanceCheckoutResponse(403, ['success' => false, 'message' => 'Your session token is invalid or expired. Refresh the page and try again.']);
}

if ($isAdminStaff && !AdminValidateSession($pdo)) {
    balanceCheckoutResponse(401, ['success' => false, 'message' => 'Your administrator session is no longer valid. Please log in again.']);
}
if ($isOperatorStaff && !OperatorValidateSession($pdo)) {
    balanceCheckoutResponse(401, ['success' => false, 'message' => 'Your operator session is no longer valid. Please log in again.']);
}
if ($isTouristPayment && !TouristValidateSession($pdo)) {
    balanceCheckoutResponse(401, ['success' => false, 'message' => 'Your tourist session is no longer valid. Please log in again.']);
}
if ($isHotelAdminStaff) {
    if ($requestHotelAdminId < 1 || $requestHotelResortId < 1 || !AppRoleSessionIsActive('hotel_admin', $pdo)) {
        AppClearRoleAuthentication('hotel_admin');
        balanceCheckoutResponse(401, ['success' => false, 'message' => 'Your hotel owner session is no longer valid. Please log in again.']);
    }
    $hotelAdminCheck = $pdo->prepare(
        "SELECT hotel_admin_id FROM hotel_admin_accounts
         WHERE hotel_admin_id = ? AND hotel_resort_id = ? AND LOWER(status) = 'active' LIMIT 1"
    );
    $hotelAdminCheck->execute([$requestHotelAdminId, $requestHotelResortId]);
    if (!$hotelAdminCheck->fetchColumn()) {
        AppClearRoleAuthentication('hotel_admin');
        session_regenerate_id(true);
        balanceCheckoutResponse(401, ['success' => false, 'message' => 'Your hotel owner session is no longer valid. Please log in again.']);
    }
}

if (!balanceCheckoutTableExists($pdo)) {
    balanceCheckoutResponse(503, ['success' => false, 'message' => 'Online payments are not ready because the payment migration has not been applied.']);
}

$requestedType = strtolower(trim((string)($_POST['type'] ?? '')));
try {
    $bookingId = ItourValidationInt($_POST['id'] ?? null, 'Booking ID', 1, PHP_INT_MAX);
} catch (InvalidArgumentException $exception) {
    balanceCheckoutResponse(422, ['success' => false, 'message' => $exception->getMessage()]);
}
if (!in_array($requestedType, ['hotel', 'tour'], true)) {
    balanceCheckoutResponse(400, ['success' => false, 'message' => 'Invalid booking request.']);
}
if ($isHotelAdminStaff && $requestedType !== 'hotel') {
    balanceCheckoutResponse(403, ['success' => false, 'message' => 'Hotel administrators can only collect payments for their property bookings.']);
}
if ($isOperatorStaff && $requestedType !== 'tour') {
    balanceCheckoutResponse(403, ['success' => false, 'message' => 'Tour operators can only collect payments for their tour bookings.']);
}
$requestedContext = strtolower(trim((string)($_POST['context'] ?? '')));
$isHotelCheckinPayment = $isHotelAdminStaff
    && $requestedType === 'hotel'
    && $requestedContext === 'hotel_checkin';
$isHotelCheckoutPayment = $isHotelAdminStaff
    && $requestedType === 'hotel'
    && $requestedContext === 'hotel_checkout';
$staffPaymentSource = $isHotelCheckinPayment
    ? 'hotel_checkin_payment'
    : ($isHotelCheckoutPayment ? 'hotel_checkout_payment' : ($isOperatorStaff ? 'operator_booking_payment' : 'admin_booking_payment'));
try {
    $checkoutAdditionalCharges = $isHotelCheckoutPayment
        ? ItourValidationMoney($_POST['additional_charges'] ?? 0, 'Additional charges')
        : 0.0;
} catch (InvalidArgumentException $exception) {
    balanceCheckoutResponse(422, ['success' => false, 'message' => $exception->getMessage()]);
}

// Staff QR collection is phone-first. Avoid creating a Checkout Session when
// its secure QR cannot be delivered to a registered Administrator device.
if ($isStaffPayment && ($_POST['action'] ?? '') !== 'cancel_pending') {
    $phoneCheck = $pdo->prepare(
        $isHotelAdminStaff
            ? 'SELECT 1 FROM hotel_admin_push_devices WHERE hotel_admin_id = ? AND revoked_at IS NULL LIMIT 1'
            : ($isOperatorStaff
                ? 'SELECT 1 FROM operator_push_devices WHERE operator_id = ? AND revoked_at IS NULL LIMIT 1'
                : 'SELECT 1 FROM admin_push_devices WHERE admin_id = ? AND revoked_at IS NULL LIMIT 1')
    );
    $phoneCheck->execute([$isHotelAdminStaff ? $requestHotelAdminId : ($isOperatorStaff ? $requestOperatorId : $requestAdminId)]);
    if (!$phoneCheck->fetchColumn()) {
        balanceCheckoutResponse(409, [
            'success' => false,
            'code' => 'payment_phone_required',
            'message' => 'Register a payment phone first before using QR Code (PayMongo).',
        ]);
    }
}

if (($_POST['action'] ?? '') === 'cancel_pending') {
    $returnToken = strtolower(trim((string)($_POST['return_token'] ?? '')));
    if (!preg_match('/^[a-f0-9]{64}$/', $returnToken)) {
        balanceCheckoutResponse(422, ['success' => false, 'message' => 'The pending payment reference is invalid or expired.']);
    }

    if ($requestedType === 'hotel') {
        $contextOwnershipSql = $isHotelAdminStaff
            ? ' AND b.hotel_resort_id = ?'
            : ($isAdminStaff ? '' : ' AND pt.tourist_id = ?');
        $lookup = $pdo->prepare(
            "SELECT pt.payment_transaction_id, pt.provider_checkout_session_id, pt.metadata,
                    COALESCE(NULLIF(b.booking_reference, ''), CONCAT('HR-', b.hotel_booking_id)) AS booking_reference
             FROM payment_transactions pt
             INNER JOIN hotel_room_bookings b ON b.hotel_booking_id = pt.booking_id
             WHERE pt.return_token = ? AND pt.booking_id = ? AND pt.booking_domain = 'hotel'
               AND pt.status = 'pending'{$contextOwnershipSql}
             LIMIT 1"
        );
        $lookupParams = [$returnToken, $bookingId];
        if (!$isAdminStaff) {
            $lookupParams[] = $isHotelAdminStaff ? $requestHotelResortId : $touristId;
        }
    } else {
        $contextOwnershipSql = $isOperatorStaff
            ? ' AND b.operator_id = ?'
            : ($isAdminStaff ? '' : ' AND pt.tourist_id = ?');
        $lookup = $pdo->prepare(
            "SELECT pt.payment_transaction_id, pt.provider_checkout_session_id, pt.metadata,
                    COALESCE(NULLIF(b.booking_reference, ''), CONCAT('TOUR-', b.booking_id)) AS booking_reference
             FROM payment_transactions pt
             INNER JOIN bookings b ON b.booking_id = pt.booking_id
                AND LOWER(b.booking_type) = pt.booking_domain
             WHERE pt.return_token = ? AND pt.booking_id = ?
               AND pt.booking_domain IN ('package', 'boat', 'tourguide')
               AND pt.status = 'pending'{$contextOwnershipSql}
             LIMIT 1"
        );
        $lookupParams = [$returnToken, $bookingId];
        if (!$isAdminStaff) {
            $lookupParams[] = $isOperatorStaff ? $requestOperatorId : $touristId;
        }
    }
    $lookup->execute($lookupParams);
    $pendingTransaction = $lookup->fetch(PDO::FETCH_ASSOC);
    if (!$pendingTransaction) {
        balanceCheckoutResponse(200, ['success' => true, 'cancelled' => false]);
    }

    $pendingMetadata = json_decode((string)($pendingTransaction['metadata'] ?? ''), true);
    $allowedSources = $isHotelAdminStaff
        ? ['admin_booking_payment', 'hotel_checkin_payment', 'hotel_checkout_payment']
        : [$isOperatorStaff ? 'operator_booking_payment' : ($isAdminStaff ? 'admin_booking_payment' : 'tourist_profile_balance')];
    if (!is_array($pendingMetadata) || !in_array((string)($pendingMetadata['source'] ?? ''), $allowedSources, true)) {
        balanceCheckoutResponse(200, ['success' => true, 'cancelled' => false]);
    }
    if ($isHotelAdminStaff && (
        ($pendingMetadata['staff_type'] ?? '') !== 'hotel_admin'
        || (int)($pendingMetadata['hotel_admin_id'] ?? 0) !== $requestHotelAdminId
        || (int)($pendingMetadata['hotel_resort_id'] ?? 0) !== $requestHotelResortId
    )) {
        balanceCheckoutResponse(200, ['success' => true, 'cancelled' => false]);
    }
    if ($isOperatorStaff && (
        ($pendingMetadata['staff_type'] ?? '') !== 'operator'
        || (int)($pendingMetadata['operator_id'] ?? 0) !== $requestOperatorId
    )) {
        balanceCheckoutResponse(200, ['success' => true, 'cancelled' => false]);
    }

    $transactionId = (int)$pendingTransaction['payment_transaction_id'];
    $checkoutSessionId = trim((string)($pendingTransaction['provider_checkout_session_id'] ?? ''));
    if ($checkoutSessionId !== '') {
        try {
            PayMongoService::fromEnvironment()->expireCheckoutSession($checkoutSessionId);
        } catch (Throwable $exception) {
            error_log('PayMongo pending checkout cancellation failed: ' . $exception->getMessage());
            balanceCheckoutResponse(409, [
                'success' => false,
                'message' => 'The payment could not be cancelled because PayMongo may already be processing it. Wait for payment verification.',
            ]);
        }
    }

    try {
        $pdo->beginTransaction();
        $lock = $pdo->prepare(
            "SELECT status FROM payment_transactions
             WHERE payment_transaction_id = ? AND return_token = ?
             LIMIT 1 FOR UPDATE"
        );
        $lock->execute([$transactionId, $returnToken]);
        if (strtolower((string)$lock->fetchColumn()) !== 'pending') {
            $pdo->rollBack();
            balanceCheckoutResponse(200, ['success' => true, 'cancelled' => false]);
        }
        $cancel = $pdo->prepare(
            "UPDATE payment_transactions
             SET status = 'cancelled', failed_at = NOW(),
                 failure_code = 'payment_cancelled',
                 failure_message = 'The pending PayMongo payment was cancelled from the payment waiting screen.'
             WHERE payment_transaction_id = ? AND return_token = ? AND status = 'pending'"
        );
        $cancel->execute([$transactionId, $returnToken]);
        $cancelled = $cancel->rowCount() === 1;
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('PayMongo protected pending checkout cancellation failed: ' . $exception->getMessage());
        balanceCheckoutResponse(500, ['success' => false, 'message' => 'The pending payment could not be cancelled safely.']);
    }

    if ($cancelled) {
        $actorRole = $isHotelAdminStaff ? 'Hotel Owner' : ($isOperatorStaff ? 'Tour Operator' : ($isAdminStaff ? 'Admin' : 'Tourist'));
        $actorId = $isHotelAdminStaff ? $requestHotelAdminId : ($isOperatorStaff ? $requestOperatorId : ($isAdminStaff ? $requestAdminId : $touristId));
        $actorName = $isHotelAdminStaff
            ? (string)($_SESSION['hotel_admin_name'] ?? 'Hotel Administrator')
            : ($isOperatorStaff
                ? (string)($_SESSION['operator_name'] ?? 'Tour Operator')
                : ($isAdminStaff ? (string)($_SESSION['admin_name'] ?? 'Administrator') : 'Tourist'));
        logActivity(
            $pdo,
            $actorRole,
            $actorId,
            $actorName,
            'Pending QR Payment Cancelled',
            'Cancelled pending PayMongo transaction #' . $transactionId . ' for booking ' . (string)$pendingTransaction['booking_reference'] . '.',
            'Payments',
            $bookingId
        );
    }
    balanceCheckoutResponse(200, ['success' => true, 'cancelled' => $cancelled]);
}

try {
    $requestedAmount = ItourValidationMoney($_POST['amount'] ?? null, 'Payment amount', 10000000.00, false);
} catch (InvalidArgumentException $exception) {
    balanceCheckoutResponse(422, ['success' => false, 'message' => $exception->getMessage()]);
}
$completeAfterPayment = ($isAdminStaff || $isOperatorStaff) && !empty($_POST['complete_after_payment']);
if ($isStaffPayment && $requestedAmount <= 0) {
    balanceCheckoutResponse(400, ['success' => false, 'message' => 'Enter a valid QR payment amount.']);
}

$transactionId = 0;
$bookingDomain = '';
$bookingReference = '';
$serviceName = '';
$balanceMinor = 0;
$merchantReference = '';
$idempotencyKey = '';
$returnToken = '';
$checkoutUrl = '';
$touristName = '';
$touristEmail = '';
$touristPhone = '';
$reusedTransaction = false;
$checkoutDescription = '';
$lineItemDescription = '';

try {
    $pdo->beginTransaction();

    if ($requestedType === 'hotel') {
        $hotelOwnershipSql = $isHotelAdminStaff
            ? ' AND b.hotel_resort_id = ?'
            : ($isAdminStaff ? '' : ' AND b.tourist_id = ?');
        $stmt = $pdo->prepare(
            "SELECT b.hotel_booking_id, b.tourist_id, b.booking_reference, b.remaining_balance,
                    b.payment_status, b.booking_status, b.checked_in_at, b.checked_out_at,
                    b.room_type, b.checkin_date, b.checkout_date, b.nights,
                    b.rooms_booked, b.adults, b.children, b.amount_paid,
                    b.total_amount, h.name AS hotel_name,
                     COALESCE(t.full_name, TRIM(CONCAT(COALESCE(b.first_name, ''), ' ', COALESCE(b.last_name, '')))) AS full_name,
                     COALESCE(t.email, b.email) AS email,
                     COALESCE(t.phone_number, b.phone_number) AS phone_number
             FROM hotel_room_bookings b
             LEFT JOIN hotel_resorts h ON h.hotel_resort_id = b.hotel_resort_id
             LEFT JOIN tourist t ON t.tourist_id = b.tourist_id
             WHERE b.hotel_booking_id = ?{$hotelOwnershipSql}
             LIMIT 1 FOR UPDATE"
        );
        $stmt->execute($isAdminStaff ? [$bookingId] : [$bookingId, $isHotelAdminStaff ? $requestHotelResortId : $touristId]);
        $booking = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$booking) {
            throw new DomainException('Hotel booking not found or does not belong to this account or property.');
        }
        $touristId = max(0, (int)($booking['tourist_id'] ?? $touristId));

        $status = strtolower(trim((string)$booking['booking_status']));
        if (!in_array($status, ['pending', 'confirmed'], true)
            || in_array($status, ['cancelled', 'declined', 'completed', 'no-show'], true)
            || !empty($booking['checked_out_at'])) {
            throw new DomainException('This hotel booking can no longer accept online payments.');
        }
        if ($isHotelCheckoutPayment
            && ($status !== 'confirmed' || empty($booking['checked_in_at']))) {
            throw new DomainException('Only a currently checked-in guest can make a checkout payment.');
        }
        if (!$isHotelCheckoutPayment && strtolower((string)$booking['payment_status']) === 'paid') {
            throw new DomainException('This hotel booking is already fully paid.');
        }

        $bookingDomain = 'hotel';
        $bookingReference = trim((string)$booking['booking_reference']) ?: 'HR-' . $bookingId;
        $serviceName = trim((string)$booking['hotel_name']) ?: trim((string)$booking['room_type']) ?: 'Hotel booking';
        $guestCount = max(0, (int)$booking['adults'] + (int)$booking['children']);
        $dateRange = balanceCheckoutDateLabel((string)$booking['checkin_date']);
        $checkoutDate = balanceCheckoutDateLabel((string)$booking['checkout_date']);
        if ($dateRange !== '' && $checkoutDate !== '') {
            $dateRange .= ' to ' . $checkoutDate;
        }
        $lineItemDescription = implode(' • ', array_filter([
            'Booking ' . $bookingReference,
            balanceCheckoutDetail((string)$booking['room_type']),
            $dateRange,
            (int)$booking['nights'] . ((int)$booking['nights'] === 1 ? ' night' : ' nights'),
            (int)$booking['rooms_booked'] . ((int)$booking['rooms_booked'] === 1 ? ' room' : ' rooms'),
            $guestCount . ($guestCount === 1 ? ' guest' : ' guests'),
            'Paid PHP ' . number_format((float)$booking['amount_paid'], 2),
        ]));
    } else {
        $touristOwnershipSql = ($isAdminStaff || $isOperatorStaff)
            ? ($isOperatorStaff ? ' AND b.operator_id = ?' : '')
            : ' AND b.tourist_id = ?';
        $stmt = $pdo->prepare(
            "SELECT b.booking_id, b.tourist_id, b.booking_reference, b.booking_type,
                    b.remaining_balance, b.is_paid, b.status, b.is_complete,
                    b.package_name, b.location, b.preferred_resource,
                    b.booking_date, b.pax, b.num_adults, b.num_children,
                    b.tour_type, b.tour_range, b.payment_amount, b.grand_total,
                    t.full_name, t.email, t.phone_number
             FROM bookings b
             INNER JOIN tourist t ON t.tourist_id = b.tourist_id
             WHERE b.booking_id = ?{$touristOwnershipSql}
             LIMIT 1 FOR UPDATE"
        );
        $stmt->execute($isAdminStaff ? [$bookingId] : [$bookingId, $isOperatorStaff ? $requestOperatorId : $touristId]);
        $booking = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$booking) {
            throw new DomainException('Tour booking not found or does not belong to this account.');
        }

        $bookingDomain = strtolower(trim((string)$booking['booking_type']));
        if (!in_array($bookingDomain, ['package', 'boat', 'tourguide'], true)) {
            throw new DomainException('This booking type does not support online balance payment.');
        }
        $status = strtolower(trim((string)$booking['status']));
        $completion = strtolower(trim((string)$booking['is_complete']));
        if (!in_array($status, ['pending', 'accepted'], true)
            || $completion !== 'uncomplete'
            || in_array($status, ['cancelled', 'declined', 'completed'], true)) {
            throw new DomainException('This tour booking can no longer accept online payments.');
        }
        if ((int)$booking['is_paid'] === 1) {
            throw new DomainException('This tour booking is already fully paid.');
        }
        if ($isAdminStaff || $isOperatorStaff) {
            $touristId = (int)$booking['tourist_id'];
            if ($touristId < 1) {
                throw new DomainException('This booking has no valid tourist account for payment reconciliation.');
            }
            if ($completeAfterPayment && $status !== 'accepted') {
                throw new DomainException('Only accepted bookings can be completed after payment.');
            }
        }

        $bookingReference = trim((string)$booking['booking_reference']) ?: strtoupper(substr($bookingDomain, 0, 2)) . '-' . $bookingId;
        $serviceName = trim((string)($booking['package_name'] ?: $booking['preferred_resource'] ?: $booking['location'])) ?: ucfirst($bookingDomain) . ' booking';
        $guestCount = max(0, (int)($booking['pax'] ?: ((int)$booking['num_adults'] + (int)$booking['num_children'])));
        $lineItemDescription = implode(' • ', array_filter([
            'Booking ' . $bookingReference,
            ucfirst($bookingDomain),
            balanceCheckoutDateLabel((string)$booking['booking_date']),
            balanceCheckoutDetail((string)$booking['location']),
            $guestCount . ($guestCount === 1 ? ' guest' : ' guests'),
            balanceCheckoutDetail(str_replace('-', ' ', (string)$booking['tour_type'])),
            'Paid PHP ' . number_format((float)$booking['payment_amount'], 2),
        ]));
    }

    $remainingBalance = round((float)$booking['remaining_balance'], 2);
    if ($isHotelCheckoutPayment) {
        $checkoutAmountDue = round($remainingBalance + $checkoutAdditionalCharges, 2);
        if ($checkoutAmountDue <= 0) {
            throw new DomainException('There is no checkout balance to collect.');
        }
        if (abs($requestedAmount - $checkoutAmountDue) > 0.009) {
            throw new DomainException('The PayMongo amount must match the complete checkout balance.');
        }
        $paymentAmount = $checkoutAmountDue;
    } else {
        if ($remainingBalance <= 0) {
            throw new DomainException('This booking is already fully paid.');
        }
        $paymentAmount = $isStaffPayment ? $requestedAmount : $remainingBalance;
        if ($paymentAmount > $remainingBalance + 0.009) {
            throw new DomainException('Payment amount cannot exceed the current booking balance.');
        }
        if ($completeAfterPayment && abs($paymentAmount - $remainingBalance) > 0.009) {
            throw new DomainException('The full remaining balance is required to complete this booking.');
        }
    }
    $balanceMinor = PaymentHelper::amountToCentavos($paymentAmount);
    $touristName = trim((string)$booking['full_name']);
    $touristEmail = trim((string)$booking['email']);
    $touristPhone = trim((string)$booking['phone_number']);
    $checkoutDescription = $isHotelCheckoutPayment
        ? 'Official checkout settlement for Booking ' . $bookingReference . '.'
        : 'Official remaining balance payment for Booking ' . $bookingReference . '.';

    $existingStmt = $pdo->prepare(
        "SELECT payment_transaction_id, merchant_reference, idempotency_key,
                return_token, amount_minor, status, checkout_url,
                provider_checkout_session_id, metadata
         FROM payment_transactions
         WHERE booking_domain = ? AND booking_id = ?
           AND status = 'pending'
         ORDER BY payment_transaction_id DESC
         LIMIT 1 FOR UPDATE"
    );
    $existingStmt->execute([$bookingDomain, $bookingId]);
    $existing = $existingStmt->fetch(PDO::FETCH_ASSOC);

    if ($existing) {
        $existingMetadata = json_decode((string)($existing['metadata'] ?? ''), true);
        $existingSource = is_array($existingMetadata) ? (string)($existingMetadata['source'] ?? '') : '';
        $expectedSource = $isStaffPayment ? $staffPaymentSource : 'tourist_profile_balance';
        if ($existingSource !== $expectedSource) {
            throw new DomainException('Another online payment is already active for this booking. Cancel or finish it before starting a new one.');
        }
        if ($isHotelAdminStaff && (
            ($existingMetadata['staff_type'] ?? '') !== 'hotel_admin'
            || (int)($existingMetadata['hotel_admin_id'] ?? 0) !== $requestHotelAdminId
            || (int)($existingMetadata['hotel_resort_id'] ?? 0) !== $requestHotelResortId
        )) {
            throw new DomainException('Another staff payment is already active for this booking. Cancel or finish it before starting a new one.');
        }
        if ($isAdminStaff && ($existingMetadata['staff_type'] ?? '') === 'hotel_admin') {
            throw new DomainException('A hotel administrator payment is already active for this booking.');
        }
        if ($isOperatorStaff && (
            ($existingMetadata['staff_type'] ?? '') !== 'operator'
            || (int)($existingMetadata['operator_id'] ?? 0) !== $requestOperatorId
        )) {
            throw new DomainException('Another staff payment is already active for this booking.');
        }
        if (($isAdminStaff || $isOperatorStaff) && $completeAfterPayment && empty($existingMetadata['complete_after_payment'])) {
            $existingMetadata['complete_after_payment'] = true;
            $updateIntent = $pdo->prepare('UPDATE payment_transactions SET metadata = ? WHERE payment_transaction_id = ?');
            $updateIntent->execute([
                json_encode($existingMetadata, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                (int)$existing['payment_transaction_id'],
            ]);
            $existing['metadata'] = json_encode($existingMetadata, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        }
    }

    if ($existing && (int)$existing['amount_minor'] !== $balanceMinor) {
        $hasProviderSession = trim((string)$existing['provider_checkout_session_id']) !== ''
            || trim((string)$existing['checkout_url']) !== '';
        if ($hasProviderSession) {
            throw new DomainException('The booking balance changed while an online Checkout Session is active. Please contact support before paying.');
        }

        // The previous API attempt failed before a Checkout Session was
        // returned. Retire its old amount and create a fresh idempotent request
        // using the current database balance.
        $retire = $pdo->prepare(
            "UPDATE payment_transactions
             SET status = 'failed', failed_at = NOW(),
                 failure_code = 'booking_balance_changed',
                 failure_message = 'The database balance changed before PayMongo returned a Checkout Session.'
             WHERE payment_transaction_id = ?
               AND provider_checkout_session_id IS NULL"
        );
        $retire->execute([(int)$existing['payment_transaction_id']]);
        $existing = false;
    }

    if ($existing) {
        $transactionId = (int)$existing['payment_transaction_id'];
        $merchantReference = (string)$existing['merchant_reference'];
        $idempotencyKey = (string)$existing['idempotency_key'];
        $returnToken = (string)$existing['return_token'];
        $checkoutUrl = trim((string)$existing['checkout_url']);
        $reusedTransaction = true;

        if ($checkoutUrl !== '') {
            $reactivate = $pdo->prepare(
                "UPDATE payment_transactions
                 SET status = 'pending', failed_at = NULL, failure_code = NULL, failure_message = NULL
                 WHERE payment_transaction_id = ?"
            );
            $reactivate->execute([$transactionId]);
            $pdo->commit();
            $phoneNotification = null;
            if ($isStaffPayment) {
                session_write_close();
                $phoneNotification = $isHotelAdminStaff
                    ? sendHotelAdminPaymentQrNotification($pdo, $requestHotelAdminId, $transactionId, $returnToken, $bookingReference, $balanceMinor)
                    : ($isOperatorStaff
                        ? sendOperatorPaymentQrNotification($pdo, $requestOperatorId, $transactionId, $returnToken, $bookingReference, $balanceMinor)
                        : sendAdminPaymentQrNotification($pdo, $requestAdminId, $transactionId, $returnToken, $bookingReference, $balanceMinor));
            }
            balanceCheckoutResponse(200, [
                'success' => true,
                'checkout_url' => $checkoutUrl,
                'status' => 'pending',
                'reused' => true,
                'phone_notification' => $phoneNotification,
                'return_token' => $isStaffPayment ? $returnToken : null,
            ]);
        }
    } else {
        $merchantReference = 'PM-' . strtoupper(bin2hex(random_bytes(12)));
        $idempotencyKey = 'checkout_' . bin2hex(random_bytes(24));
        $returnToken = bin2hex(random_bytes(32));
        $metadataPayload = [
            'source' => $isStaffPayment ? $staffPaymentSource : 'tourist_profile_balance',
            'booking_domain' => $bookingDomain,
            'booking_id' => (string)$bookingId,
        ];
        if ($isAdminStaff) {
            $metadataPayload['admin_id'] = (string)((int)($_SESSION['admin_id'] ?? 0));
            $metadataPayload['admin_name'] = trim((string)($_SESSION['admin_name'] ?? 'Administrator'));
            $metadataPayload['complete_after_payment'] = $completeAfterPayment;
        }
        if ($isHotelAdminStaff) {
            $metadataPayload['hotel_admin_id'] = (string)$requestHotelAdminId;
            $metadataPayload['hotel_resort_id'] = (string)$requestHotelResortId;
            $metadataPayload['admin_name'] = trim((string)($_SESSION['hotel_admin_name'] ?? 'Hotel Administrator'));
            $metadataPayload['staff_type'] = 'hotel_admin';
        }
        if ($isOperatorStaff) {
            $metadataPayload['operator_id'] = (string)$requestOperatorId;
            $metadataPayload['operator_name'] = trim((string)($_SESSION['operator_name'] ?? 'Tour Operator'));
            $metadataPayload['admin_name'] = trim((string)($_SESSION['operator_name'] ?? 'Tour Operator'));
            $metadataPayload['staff_type'] = 'operator';
            $metadataPayload['complete_after_payment'] = $completeAfterPayment;
        }
        if ($isHotelCheckoutPayment) {
            $metadataPayload['checkout_base_remaining'] = number_format($remainingBalance, 2, '.', '');
            $metadataPayload['checkout_additional_charges'] = number_format($checkoutAdditionalCharges, 2, '.', '');
            $metadataPayload['checkout_amount_due'] = number_format($paymentAmount, 2, '.', '');
        }
        $metadata = json_encode($metadataPayload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        $insert = $pdo->prepare(
            "INSERT INTO payment_transactions
             (tourist_id, booking_domain, booking_id, booking_reference, provider,
              merchant_reference, idempotency_key, return_token, amount_minor,
              currency, status, metadata)
             VALUES (?, ?, ?, ?, 'paymongo', ?, ?, ?, ?, 'PHP', 'pending', ?)"
        );
        $insert->execute([
            $touristId,
            $bookingDomain,
            $bookingId,
            $bookingReference,
            $merchantReference,
            $idempotencyKey,
            $returnToken,
            $balanceMinor,
            $metadata,
        ]);
        $transactionId = (int)$pdo->lastInsertId();

        logActivity(
            $pdo,
            $isStaffPayment ? ($isHotelAdminStaff ? 'Hotel Owner' : ($isOperatorStaff ? 'Tour Operator' : 'Admin')) : 'Tourist',
            $isHotelAdminStaff ? $requestHotelAdminId : ($isOperatorStaff ? $requestOperatorId : ($isAdminStaff ? (int)($_SESSION['admin_id'] ?? 0) : $touristId)),
            $isHotelAdminStaff ? (string)($_SESSION['hotel_admin_name'] ?? 'Hotel Administrator') : ($isOperatorStaff ? (string)($_SESSION['operator_name'] ?? 'Tour Operator') : ($isAdminStaff ? (string)($_SESSION['admin_name'] ?? 'Administrator') : ($touristName !== '' ? $touristName : 'Tourist'))),
            'Online Balance Payment Initiated',
            'Initiated PayMongo test payment ' . $merchantReference . ' for booking ' . $bookingReference . '.',
            'Payments',
            $bookingId
        );
    }

    $pdo->commit();
} catch (DomainException|InvalidArgumentException $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    balanceCheckoutResponse(400, ['success' => false, 'message' => $exception->getMessage()]);
} catch (PDOException $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Balance checkout database error: ' . $exception->getMessage());
    balanceCheckoutResponse(409, ['success' => false, 'message' => 'An online payment is already active or the payment record could not be created.']);
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Balance checkout preparation error: ' . $exception->getMessage());
    balanceCheckoutResponse(500, ['success' => false, 'message' => 'The online payment could not be prepared.']);
}

session_write_close();

try {
    $useAdminPhoneHandoff = false;
    if ($isStaffPayment) {
        $activePhone = $pdo->prepare(
            $isHotelAdminStaff
                ? 'SELECT 1 FROM hotel_admin_push_devices WHERE hotel_admin_id = ? AND revoked_at IS NULL LIMIT 1'
                : ($isOperatorStaff
                    ? 'SELECT 1 FROM operator_push_devices WHERE operator_id = ? AND revoked_at IS NULL LIMIT 1'
                    : 'SELECT 1 FROM admin_push_devices WHERE admin_id = ? AND revoked_at IS NULL LIMIT 1')
        );
        $activePhone->execute([$isHotelAdminStaff ? $requestHotelAdminId : ($isOperatorStaff ? $requestOperatorId : $requestAdminId)]);
        $useAdminPhoneHandoff = (bool)$activePhone->fetchColumn();
    }
    $publicAppUrl = PaymentHelper::env('PUBLIC_APP_URL');
    $successPath = $useAdminPhoneHandoff
        ? '/payments/payment-phone-return.php?result=success&token=' . rawurlencode($returnToken)
        : '/payments/payment-success.php?token=' . rawurlencode($returnToken);
    $cancelPath = $useAdminPhoneHandoff
        ? '/payments/payment-phone-return.php?result=cancelled&token=' . rawurlencode($returnToken)
        : '/payments/payment-cancel.php?token=' . rawurlencode($returnToken);
    $successUrl = PaymentHelper::publicHttpsUrl($publicAppUrl, $successPath);
    $cancelUrl = PaymentHelper::publicHttpsUrl($publicAppUrl, $cancelPath);
    $billing = array_filter([
        'name' => $touristName,
        'email' => $touristEmail,
        'phone' => $touristPhone,
    ], static fn($value) => trim((string)$value) !== '');

    $attributes = [
        'line_items' => [[
            'name' => mb_substr($serviceName, 0, 120),
            'description' => mb_substr($lineItemDescription, 0, 255),
            'amount' => $balanceMinor,
            'currency' => 'PHP',
            'quantity' => 1,
        ]],
        'payment_method_types' => $isStaffPayment ? ['qrph'] : ['card', 'gcash', 'qrph'],
        'merchant' => 'iTour Mercedes',
        'description' => $checkoutDescription,
        'success_url' => $successUrl,
        'cancel_url' => $cancelUrl,
        'reference_number' => $merchantReference,
        'send_email_receipt' => false,
        'show_description' => true,
        'show_line_items' => true,
        'metadata' => [
            'payment_transaction_id' => (string)$transactionId,
            'booking_domain' => $bookingDomain,
            'booking_id' => (string)$bookingId,
            'booking_reference' => $bookingReference,
            'tourist_id' => (string)$touristId,
        ],
    ];
    if ($billing !== []) {
        $attributes['billing'] = $billing;
    }

    $service = PayMongoService::fromEnvironment();
    $response = $service->createCheckoutSession($attributes, $idempotencyKey);
    $resource = is_array($response['data'] ?? null) ? $response['data'] : [];
    $resourceAttributes = is_array($resource['attributes'] ?? null) ? $resource['attributes'] : [];
    $checkoutSessionId = trim((string)($resource['id'] ?? ''));
    $checkoutUrl = trim((string)($resourceAttributes['checkout_url'] ?? ''));

    $checkoutParts = parse_url($checkoutUrl);
    $checkoutHost = strtolower((string)($checkoutParts['host'] ?? ''));
    if (!str_starts_with($checkoutSessionId, 'cs_')
        || strtolower((string)($checkoutParts['scheme'] ?? '')) !== 'https'
        || ($checkoutHost !== 'checkout.paymongo.com' && !str_ends_with($checkoutHost, '.paymongo.com'))) {
        throw new RuntimeException('PayMongo returned an invalid Checkout Session.');
    }

    $save = $pdo->prepare(
        "UPDATE payment_transactions
         SET provider_checkout_session_id = ?, checkout_url = ?, status = 'pending',
             failed_at = NULL, failure_code = NULL, failure_message = NULL
         WHERE payment_transaction_id = ? AND tourist_id = ?"
    );
    $save->execute([$checkoutSessionId, $checkoutUrl, $transactionId, $touristId]);
    if ($save->rowCount() !== 1) {
        $verifySave = $pdo->prepare(
            "SELECT provider_checkout_session_id, checkout_url
             FROM payment_transactions
             WHERE payment_transaction_id = ? AND tourist_id = ?"
        );
        $verifySave->execute([$transactionId, $touristId]);
        $saved = $verifySave->fetch(PDO::FETCH_ASSOC);
        if (!$saved
            || !hash_equals($checkoutSessionId, (string)$saved['provider_checkout_session_id'])
            || !hash_equals($checkoutUrl, (string)$saved['checkout_url'])) {
            throw new RuntimeException('The Checkout Session could not be linked to its local transaction.');
        }
    }

    $phoneNotification = $isStaffPayment
        ? ($isHotelAdminStaff
            ? sendHotelAdminPaymentQrNotification($pdo, $requestHotelAdminId, $transactionId, $returnToken, $bookingReference, $balanceMinor)
            : ($isOperatorStaff
                ? sendOperatorPaymentQrNotification($pdo, $requestOperatorId, $transactionId, $returnToken, $bookingReference, $balanceMinor)
                : sendAdminPaymentQrNotification($pdo, $requestAdminId, $transactionId, $returnToken, $bookingReference, $balanceMinor)))
        : null;

    balanceCheckoutResponse(200, [
        'success' => true,
        'checkout_url' => $checkoutUrl,
        'status' => 'pending',
        'reused' => $reusedTransaction,
        'phone_notification' => $phoneNotification,
        'return_token' => $isStaffPayment ? $returnToken : null,
    ]);
} catch (PayMongoException $exception) {
    $status = $exception->getHttpStatus();
    if ($status >= 400 && $status < 500 && $status !== 409) {
        balanceCheckoutMarkCreationFailure($pdo, $transactionId, $exception->getMessage());
    }
    error_log('PayMongo balance checkout error: ' . $exception->getMessage());
    balanceCheckoutResponse(502, [
        'success' => false,
        'message' => $status === 409
            ? 'PayMongo is already preparing this Checkout Session. Please try again shortly.'
            : 'PayMongo could not prepare the payment page. No booking payment was recorded.',
    ]);
} catch (LogicException $exception) {
    balanceCheckoutMarkCreationFailure($pdo, $transactionId, $exception->getMessage());
    error_log('PayMongo balance checkout configuration error: ' . $exception->getMessage());
    balanceCheckoutResponse(503, ['success' => false, 'message' => 'Online payment configuration is incomplete.']);
} catch (Throwable $exception) {
    error_log('PayMongo balance checkout unexpected error: ' . $exception->getMessage());
    balanceCheckoutResponse(500, ['success' => false, 'message' => 'The payment page could not be opened. Please retry using the same booking.']);
}
