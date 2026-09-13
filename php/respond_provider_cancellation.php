<?php

declare(strict_types=1);

    require_once __DIR__ . '/session_security.php';
    AppSessionStart();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require_once __DIR__ . '/db_connection.php';
require_once __DIR__ . '/tourist_auth_helper.php';
require_once __DIR__ . '/activity_logger.php';
require_once __DIR__ . '/booking_cancellations_helper.php';
require_once __DIR__ . '/tour_resource_availability_helper.php';
require_once __DIR__ . '/provider_cancellation_email.php';

function providerDecisionResponse(int $status, array $payload): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function providerDecisionResponseThen(int $status, array $payload, callable $afterResponse): never
{
    $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    if (!is_string($body)) $body = '{"success":true}';
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    ignore_user_abort(true);
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Length: ' . strlen($body));
    header('Connection: close');
    echo $body;
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    } else {
        while (ob_get_level() > 0) @ob_end_flush();
        flush();
    }
    try {
        $afterResponse();
    } catch (Throwable $error) {
        error_log('Post-decision task failed: ' . $error->getMessage());
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') providerDecisionResponse(405, ['success' => false, 'message' => 'Method not allowed.']);
$authenticatedTourist = TouristRequireLogin($pdo, 'json');
$touristId = (int)$authenticatedTourist['tourist_id'];
$expectedCsrf = $_SESSION['booking_cancellation_csrf'] ?? null;
$submittedCsrf = $_POST['csrf_token'] ?? null;
if (!is_string($expectedCsrf) || $expectedCsrf === '' || !is_string($submittedCsrf) || $submittedCsrf === '' || !hash_equals($expectedCsrf, $submittedCsrf)) {
    providerDecisionResponse(403, ['success' => false, 'message' => 'Your session expired. Refresh the page and try again.']);
}

$requestId = (int)($_POST['cancellation_request_id'] ?? 0);
$decision = strtolower(trim((string)($_POST['decision'] ?? '')));
$newDate = trim((string)($_POST['new_date'] ?? ''));
if ($requestId < 1 || !in_array($decision, ['reschedule', 'full_refund'], true)) {
    providerDecisionResponse(422, ['success' => false, 'message' => 'Invalid cancellation response.']);
}

$lockName = '';
try {
    ensureBookingCancellationRequestsTable($pdo);
    $pdo->beginTransaction();
    $statement = $pdo->prepare("SELECT cr.*, b.booking_date, b.booking_type AS source_booking_type, b.package_name,
        b.location, b.num_adults, b.num_children, b.pax, b.guide_id, b.boat_id, b.tour_type, b.tour_range,
        b.operator_id, b.remaining_balance, b.payment_amount, b.grand_total, b.status, b.is_complete,
        t.full_name, t.email
        FROM booking_cancellation_requests cr
        JOIN bookings b ON cr.booking_domain='tour' AND b.booking_id=cr.booking_id
        JOIN tourist t ON t.tourist_id=cr.tourist_id
        WHERE cr.cancellation_request_id=? AND cr.tourist_id=? LIMIT 1 FOR UPDATE");
    $statement->execute([$requestId, $touristId]);
    $request = $statement->fetch(PDO::FETCH_ASSOC);
    if (!$request || !bookingCancellationIsDecisionRequired($request)) throw new RuntimeException('This cancellation decision is no longer available.');
    if (strtotime((string)$request['decision_deadline']) <= time()) throw new RuntimeException('The two-day response deadline has passed. The booking is being moved to refund processing.');

    if ($decision === 'full_refund') {
        $refundRecord = bookingCancellationFinalizeProviderRefund($pdo, $requestId, 'tourist_full_refund');
        $notificationUpdate = $pdo->prepare('UPDATE bookings SET is_notif_viewed=0 WHERE booking_id=? AND tourist_id=?');
        $notificationUpdate->execute([(int)$request['booking_id'], $touristId]);
        $pdo->commit();
        logActivity($pdo, 'Tourist', $touristId, (string)$request['full_name'], 'Provider Cancellation Accepted', 'Selected a full refund for ' . $request['booking_reference'] . '.', 'Bookings', (int)$request['booking_id']);
        providerDecisionResponseThen(200, ['success' => true, 'decision' => 'full_refund', 'message' => 'The booking has been cancelled and your full refund was submitted for processing.'], static function () use ($refundRecord, $request): void {
            sendProviderCancellationEmail($refundRecord, $request);
        });
    }

    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $newDate, new DateTimeZone('Asia/Manila'));
    if (!$date || $date->format('Y-m-d') !== $newDate || $newDate <= date('Y-m-d')) throw new RuntimeException('Choose a valid future booking date.');
    $originalDate = (string)($request['original_service_date'] ?: $request['service_date']);
    if ($newDate === $originalDate) throw new RuntimeException('Choose a date different from the original booking date.');

    $type = strtolower((string)$request['source_booking_type']);
    $durationDays = 0;
    $oldEnd = tourResourceEndDate(['booking_date' => $originalDate, 'tour_type' => $request['tour_type'], 'tour_range' => $request['tour_range']]);
    if ($oldEnd !== '') $durationDays = max(0, (int)(new DateTimeImmutable($originalDate))->diff(new DateTimeImmutable($oldEnd))->format('%r%a'));
    $newEnd = $date->modify('+' . $durationDays . ' days')->format('Y-m-d');

    if (in_array($type, ['boat','tourguide'], true)) {
        $resourceId = $type === 'boat' ? (int)$request['boat_id'] : (int)$request['guide_id'];
        $lockName = tourResourceLock($pdo, $type, $resourceId);
        $column = $type === 'boat' ? 'boat_id' : 'guide_id';
        $conflicts = $pdo->prepare("SELECT b.booking_date,b.tour_type,b.tour_range FROM bookings b
            WHERE b.{$column}=? AND b.booking_id<>? AND b.status IN ('pending','accepted') AND b.is_complete='uncomplete'
              AND NOT EXISTS (SELECT 1 FROM booking_cancellation_requests crx WHERE crx.booking_domain='tour' AND crx.booking_id=b.booking_id AND crx.request_status='decision_required')");
        $conflicts->execute([$resourceId, (int)$request['booking_id']]);
        foreach ($conflicts->fetchAll(PDO::FETCH_ASSOC) as $conflict) {
            if (tourResourceRangesOverlap($newDate, $newEnd, (string)$conflict['booking_date'], tourResourceEndDate($conflict))) throw new RuntimeException('The selected boat or tour guide is unavailable on that date.');
        }
    } elseif ($type === 'package') {
        $lockName = tourPackageLock($pdo, (string)$request['package_name']);
        if (!tourPackageIsAvailable($pdo, (string)$request['package_name'], $newDate, $newEnd, max(1, (int)$request['pax']), (int)$request['operator_id'])) {
            throw new RuntimeException('The selected package is unavailable or has insufficient slots on that date.');
        }
    }

    $newRange = (string)$request['tour_range'];
    if (preg_match('/^\d{4}-\d{2}-\d{2}\s+to\s+\d{4}-\d{2}-\d{2}$/i', $newRange)) $newRange = $newDate . ' to ' . $newEnd;
    $bookingUpdate = $pdo->prepare("UPDATE bookings SET booking_date=?, tour_range=?, status='accepted', is_complete='uncomplete', is_notif_viewed=0, dec_can_note=?, updated_at=NOW() WHERE booking_id=? AND tourist_id=?");
    $bookingUpdate->execute([$newDate, $newRange, 'Rescheduled after provider cancellation: ' . (string)$request['cancellation_reason'], (int)$request['booking_id'], $touristId]);
    $requestUpdate = $pdo->prepare("UPDATE booking_cancellation_requests SET request_status='rescheduled', refund_status='not_applicable',
        refund_policy='no_refund_rescheduled', refundable_amount=0, non_refundable_amount=0,
        tourist_decision='reschedule', tourist_responded_at=NOW(), rescheduled_service_date=?, updated_at=NOW()
        WHERE cancellation_request_id=? AND request_status='decision_required'");
    $requestUpdate->execute([$newDate, $requestId]);
    $pdo->commit();
    tourResourceUnlock($pdo, $lockName);
    $lockName = '';

    $request['original_service_date'] = $originalDate;
    $request['rescheduled_service_date'] = $newDate;
    logActivity($pdo, 'Tourist', $touristId, (string)$request['full_name'], 'Booking Rescheduled', 'Rescheduled ' . $request['booking_reference'] . ' from ' . $originalDate . ' to ' . $newDate . '.', 'Bookings', (int)$request['booking_id']);
    providerDecisionResponseThen(200, ['success' => true, 'decision' => 'reschedule', 'message' => 'Your booking was rescheduled successfully.', 'old_date' => $originalDate, 'new_date' => $newDate], static function () use ($request): void {
        sendRescheduleConfirmationEmail($request, $request);
    });
} catch (Throwable $error) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    if ($lockName !== '') tourResourceUnlock($pdo, $lockName);
    providerDecisionResponse(400, ['success' => false, 'message' => $error->getMessage() ?: 'Your response could not be saved.']);
}
