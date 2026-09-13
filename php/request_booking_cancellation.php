<?php

declare(strict_types=1);

    require_once __DIR__ . '/session_security.php';
    AppSessionStart();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require_once __DIR__ . '/db_connection.php';
require_once __DIR__ . '/tourist_auth_helper.php';
require_once __DIR__ . '/activity_logger.php';
require_once __DIR__ . '/hotel_rooms_helper.php';
require_once __DIR__ . '/booking_cancellations_helper.php';
require_once __DIR__ . '/booking_refunds_helper.php';

function cancellationResponse(int $status, array $payload): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    cancellationResponse(405, ['success' => false, 'message' => 'Method not allowed.']);
}

$authenticatedTourist = TouristRequireLogin($pdo, 'json');
$touristId = (int)$authenticatedTourist['tourist_id'];

$csrf = (string)($_POST['csrf_token'] ?? '');
$sessionCsrf = (string)($_SESSION['booking_cancellation_csrf'] ?? '');
if ($sessionCsrf === '' || !hash_equals($sessionCsrf, $csrf)) {
    cancellationResponse(403, ['success' => false, 'message' => 'Your session expired. Refresh the page and try again.']);
}

$domain = strtolower(trim((string)($_POST['booking_domain'] ?? '')));
$bookingId = (int)($_POST['booking_id'] ?? 0);
$reason = trim((string)($_POST['reason'] ?? ''));
$refundInstitution = trim((string)($_POST['refund_institution'] ?? ''));
$refundBic = strtoupper(trim((string)($_POST['refund_bic'] ?? '')));
$refundAccountName = trim((string)($_POST['refund_account_name'] ?? ''));
$refundAccountNumber = preg_replace('/\s+/', '', trim((string)($_POST['refund_account_number'] ?? ''))) ?: '';
if (!in_array($domain, ['tour', 'hotel'], true) || $bookingId <= 0) {
    cancellationResponse(422, ['success' => false, 'message' => 'Invalid booking request.']);
}
if ($reason === '') {
    cancellationResponse(422, ['success' => false, 'message' => 'Please tell us why you need to cancel this booking.']);
}
if (mb_strlen($reason) > 1500) {
    cancellationResponse(422, ['success' => false, 'message' => 'The cancellation reason must be 1,500 characters or fewer.']);
}

try {
    HoEnsureHotelBookingsTable($pdo);
    ensureBookingCancellationRequestsTable($pdo);
    $pdo->beginTransaction();

    if ($domain === 'hotel') {
        $statement = $pdo->prepare("
            SELECT b.*, h.name AS service_name
            FROM hotel_room_bookings b
            LEFT JOIN hotel_resorts h ON h.hotel_resort_id = b.hotel_resort_id
            WHERE b.hotel_booking_id = ? AND b.tourist_id = ?
            LIMIT 1 FOR UPDATE
        ");
        $statement->execute([$bookingId, $touristId]);
        $booking = $statement->fetch(PDO::FETCH_ASSOC);
        if (!$booking) throw new RuntimeException('Hotel booking not found.');

        $status = strtolower((string)($booking['booking_status'] ?? 'pending'));
        if (in_array($status, ['cancelled', 'completed', 'declined', 'no-show'], true) || !empty($booking['checked_in_at']) || !empty($booking['checked_out_at'])) {
            throw new RuntimeException('This hotel booking can no longer be cancelled online.');
        }

        $bookingType = 'hotel';
        $reference = (string)($booking['booking_reference'] ?? ('HOTEL-' . $bookingId));
        $serviceName = trim((string)($booking['service_name'] ?? '')) ?: (string)($booking['room_type'] ?? 'Hotel stay');
        $serviceDate = (string)$booking['checkin_date'];
        $totalAmount = max(0, (float)($booking['total_amount'] ?? 0));
        $amountPaid = max(0, (float)($booking['amount_paid'] ?? 0));
        $remainingBalance = max(0, (float)($booking['remaining_balance'] ?? 0));
    } else {
        $statement = $pdo->prepare('SELECT * FROM bookings WHERE booking_id = ? AND tourist_id = ? LIMIT 1 FOR UPDATE');
        $statement->execute([$bookingId, $touristId]);
        $booking = $statement->fetch(PDO::FETCH_ASSOC);
        if (!$booking) throw new RuntimeException('Booking not found.');

        $status = strtolower((string)($booking['status'] ?? 'pending'));
        $completion = strtolower((string)($booking['is_complete'] ?? 'uncomplete'));
        if (!in_array($status, ['pending', 'accepted'], true) || $completion !== 'uncomplete') {
            throw new RuntimeException('This booking can no longer be cancelled online.');
        }

        $bookingType = strtolower((string)($booking['booking_type'] ?? 'tour')) ?: 'tour';
        $reference = (string)($booking['booking_reference'] ?? ('BOOKING-' . $bookingId));
        $serviceName = in_array($bookingType, ['boat', 'tourguide', 'guide'], true)
            ? (trim((string)($booking['location'] ?? '')) ?: ucfirst($bookingType) . ' booking')
            : (trim((string)($booking['package_name'] ?? '')) ?: 'Tour package');
        $serviceDate = (string)$booking['booking_date'];
        $totalAmount = max(0, (float)($booking['grand_total'] ?? 0));
        $amountPaid = max(0, (float)($booking['payment_amount'] ?? 0));
        $remainingBalance = max(0, (float)($booking['remaining_balance'] ?? 0));
    }

    $timezone = new DateTimeZone('Asia/Manila');
    $service = DateTimeImmutable::createFromFormat('!Y-m-d', $serviceDate, $timezone);
    if (!$service || $service < new DateTimeImmutable('today', $timezone)) {
        throw new RuntimeException('Past bookings can no longer be cancelled online.');
    }

    $totalAmount = max($totalAmount, $amountPaid + $remainingBalance);
    $policy = bookingCancellationRefundPolicy($serviceDate, $totalAmount, $amountPaid);

    $destinationCipher = null;
    $destinationLast4 = null;
    $destinationVerifiedAt = null;
    if ($policy['refund_policy'] === 'partial_refund') {
        if ($refundInstitution === '' || $refundBic === '' || $refundAccountName === '' || $refundAccountNumber === '') {
            throw new RuntimeException('Add the bank or e-wallet account that will receive your partial refund.');
        }
        if (!preg_match('/^[A-Z0-9]{8,20}$/', $refundBic)) throw new RuntimeException('Select a valid refund institution.');
        if (mb_strlen($refundAccountName) > 150 || !preg_match('/^[0-9A-Za-z+._-]{5,40}$/', $refundAccountNumber)) {
            throw new RuntimeException('Check the refund account name and account number.');
        }
        $destinationCipher = bookingRefundEncryptAccountNumber($refundAccountNumber);
        $destinationLast4 = substr($refundAccountNumber, -4);
        $destinationVerifiedAt = date('Y-m-d H:i:s');
    }

    $existingStatement = $pdo->prepare('SELECT request_status FROM booking_cancellation_requests WHERE booking_domain=? AND booking_id=? LIMIT 1');
    $existingStatement->execute([$domain, $bookingId]);
    $existingStatus = strtolower((string)($existingStatement->fetchColumn() ?: ''));
    if (in_array($existingStatus, ['pending', 'approved'], true)) {
        throw new RuntimeException('A cancellation request already exists for this booking.');
    }

    $insert = $pdo->prepare("
        INSERT INTO booking_cancellation_requests
            (tourist_id, booking_domain, booking_id, initiated_by, booking_reference, booking_type, service_name,
             service_date, total_amount, amount_paid, days_before_service, refund_policy,
            refundable_amount, non_refundable_amount, request_status, refund_status,
             refund_destination_institution, refund_destination_bic, refund_destination_account_name,
             refund_destination_account_cipher, refund_destination_last4, refund_destination_verified_at,
             cancellation_reason, admin_note, requested_at, reviewed_at, refund_updated_at)
        VALUES (?, ?, ?, 'tourist', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', 'not_started', ?, ?, ?, ?, ?, ?, ?, NULL, NOW(), NULL, NULL)
        ON DUPLICATE KEY UPDATE
            tourist_id=VALUES(tourist_id), initiated_by='tourist', booking_reference=VALUES(booking_reference),
            booking_type=VALUES(booking_type), service_name=VALUES(service_name), service_date=VALUES(service_date),
            total_amount=VALUES(total_amount), amount_paid=VALUES(amount_paid),
            days_before_service=VALUES(days_before_service), refund_policy=VALUES(refund_policy),
            refundable_amount=VALUES(refundable_amount), non_refundable_amount=VALUES(non_refundable_amount),
            refund_destination_institution=VALUES(refund_destination_institution), refund_destination_bic=VALUES(refund_destination_bic),
            refund_destination_account_name=VALUES(refund_destination_account_name), refund_destination_account_cipher=VALUES(refund_destination_account_cipher),
            refund_destination_last4=VALUES(refund_destination_last4), refund_destination_verified_at=VALUES(refund_destination_verified_at),
            request_status='pending', refund_status='not_started', cancellation_reason=VALUES(cancellation_reason),
            original_service_date=NULL, rescheduled_service_date=NULL, reschedule_offered=0,
            reschedule_offered_at=NULL, decision_deadline=NULL, tourist_decision=NULL,
            tourist_responded_at=NULL, decision_expired_at=NULL, refund_request_created_at=NULL,
            admin_note=NULL, reviewed_by_admin_id=NULL, requested_at=NOW(), reviewed_at=NULL,
            refund_updated_at=NULL, completed_at=NULL, completed_by_admin_id=NULL
    ");
    $insert->execute([
        $touristId, $domain, $bookingId, $reference, $bookingType, $serviceName,
        $serviceDate, $totalAmount, $amountPaid, $policy['days_before_service'], $policy['refund_policy'],
        $policy['refundable_amount'], $policy['non_refundable_amount'],
        $refundInstitution ?: null, $refundBic ?: null, $refundAccountName ?: null, $destinationCipher, $destinationLast4, $destinationVerifiedAt,
        mb_substr($reason, 0, 1500),
    ]);

    $pdo->commit();
    try {
        logActivity(
            $pdo,
            'Tourist',
            $touristId,
            (string)($_SESSION['full_name'] ?? $_SESSION['tourist_email'] ?? 'Tourist'),
            'Cancellation Requested',
            'Requested cancellation of ' . $reference . '.',
            'Bookings',
            $bookingId
        );
    } catch (Throwable $loggingError) {
        error_log('Cancellation request activity logging failed: ' . $loggingError->getMessage());
    }

    cancellationResponse(200, [
        'success' => true,
        'message' => 'Your cancellation request was submitted for approval.',
        'refund_policy' => bookingCancellationPolicyLabel($policy['refund_policy']),
        'refundable_amount' => $policy['refundable_amount'],
    ]);
} catch (Throwable $error) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    cancellationResponse(400, ['success' => false, 'message' => $error->getMessage() ?: 'The cancellation request could not be submitted.']);
}
