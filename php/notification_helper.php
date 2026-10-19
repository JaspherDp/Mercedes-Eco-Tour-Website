<?php
declare(strict_types=1);
require_once __DIR__ . '/hotel_booking_status.php';

/** Extend successful existing events; never create schema or send email here. */
function NotificationAvailable(PDO $pdo): bool
{
    static $connections;
    $connections ??= new WeakMap();
    if (isset($connections[$pdo])) return $connections[$pdo];
    try {
        $pdo->query('SELECT notification_id, recipient_role, recipient_id, event_key, event_type, booking_domain, booking_id, title, message, created_at, read_at FROM app_notifications LIMIT 0');
        return $connections[$pdo] = true;
    } catch (Throwable $error) { return $connections[$pdo] = false; }
}

function NotificationBooking(PDO $pdo, string $domain, int $id): ?array
{
    if ($id < 1 || !in_array($domain, ['tour', 'hotel'], true)) return null;
    $table = $domain === 'hotel' ? 'hotel_room_bookings' : 'bookings';
    $key = $domain === 'hotel' ? 'hotel_booking_id' : 'booking_id';
    $query = $pdo->prepare("SELECT * FROM $table WHERE $key = ? LIMIT 1");
    $query->execute([$id]);
    return $query->fetch(PDO::FETCH_ASSOC) ?: null;
}

function NotificationCanAccess(array $booking, string $domain, string $role, int $id, int $propertyId = 0): bool
{
    if ($id < 1) return false;
    return match ($role) {
        'tourist' => (int)($booking['tourist_id'] ?? 0) === $id,
        'operator' => $domain === 'tour' && (int)($booking['operator_id'] ?? 0) === $id,
        'hotel_admin' => $domain === 'hotel' && $propertyId > 0 && (int)($booking['hotel_resort_id'] ?? 0) === $propertyId,
        'admin' => true, // The existing main administrator role centrally manages tours/cancellations.
        default => false,
    };
}

/** All text is static; previews contain no guest names, contact or payment details. */
function NotificationEvents(): array
{
    return [
        'Booking Submitted' => ['booking_submitted', 'Booking Submitted', 'A booking has been submitted and is awaiting review.'],
        'Hotel Booking Submitted' => ['booking_submitted', 'Booking Submitted', 'A hotel booking has been submitted and is awaiting review.'],
        'Walk-in Booking Added' => ['booking_submitted', 'Booking Submitted', 'An assisted booking is ready for management.'],
        'Walk-in Hotel Booking Created' => ['booking_submitted', 'Booking Submitted', 'An assisted hotel booking is ready for management.'],
        'Booking Accepted' => ['booking_confirmed', 'Booking Confirmed', 'The booking has been confirmed.'],
        'Hotel Booking Accepted' => ['booking_confirmed', 'Booking Confirmed', 'The hotel booking has been confirmed.'],
        'Booking Rejected' => ['booking_rejected', 'Booking Rejected', 'The booking was not approved. Please review its details.'],
        'Hotel Booking Rejected' => ['booking_rejected', 'Booking Rejected', 'The hotel booking was not approved. Please review its details.'],
        'Booking Cancelled' => ['booking_cancelled', 'Booking Cancelled', 'The booking has been cancelled.'],
        'Provider Cancellation' => ['booking_cancelled', 'Booking Cancelled', 'The booking has been cancelled. Review the cancellation details.'],
        'Provider Cancellation Accepted' => ['booking_cancelled', 'Booking Cancelled', 'The tourist selected cancellation and a full refund.'],
        'Cancellation Requested' => ['cancellation_requested', 'Cancellation Requested', 'A booking cancellation request is ready for review.'],
        'Cancellation Approved' => ['cancellation_approved', 'Cancellation Approved', 'The cancellation request has been approved.'],
        'Cancellation Rejected' => ['cancellation_rejected', 'Cancellation Not Approved', 'The cancellation request was not approved. Review the decision.'],
        'Cancellation Completed' => ['cancellation_completed', 'Cancellation Completed', 'The cancellation process has been completed.'],
        'Booking Completed' => ['booking_completed', 'Booking Completed', 'The booking has been marked as completed.'],
        'Hotel Booking No-show' => ['booking_no_show', 'Booking Status Updated', 'The hotel booking has been marked as no-show.'],
        'Guest Checked In' => ['booking_checked_in', 'Check-in Recorded', 'The hotel check-in has been recorded.'],
        'Guest Checked Out' => ['booking_completed', 'Booking Completed', 'The hotel check-out has been recorded.'],
        'Tourist Submission Requested' => ['tourist_information_required', 'Tourist Information Required', 'Please submit the guest details for your booking.'],
        'Tourist Data Submitted' => ['tourist_details_submitted', 'Tourist Details Submitted', 'Guest information has been submitted and is ready for review.'],
        'Tourist Data Updated' => ['tourist_details_updated', 'Tourist Details Updated', 'Updated guest information is ready for review.'],
        'Reschedule Offered' => ['reschedule_required', 'Rescheduling Decision Required', 'Please review the rescheduling options for your booking.'],
        'Booking Rescheduled' => ['booking_rescheduled', 'Booking Schedule Updated', 'The booking schedule has been updated successfully.'],
        'Reschedule Decision Expired' => ['booking_cancelled', 'Rescheduling Decision Expired', 'The decision period ended. Review the cancellation and refund status.'],
        'Booking Payment Verified' => ['payment_confirmed', 'Payment Confirmed', 'Payment has been verified and recorded for the booking.'],
        'Online Balance Payment Verified' => ['payment_confirmed', 'Payment Confirmed', 'Payment has been verified and recorded for the booking.'],
        'Booking Payment Updated' => ['payment_confirmed', 'Payment Recorded', 'A payment has been recorded for the booking.'],
        'Hotel Booking Payment Recorded' => ['payment_confirmed', 'Payment Recorded', 'A payment has been recorded for the hotel booking.'],
        'Online Balance Payment Initiated' => ['payment_pending', 'Payment Pending', 'A payment is awaiting verification. Review booking billing.'],
        'Booking Expense Added' => ['booking_updated', 'Booking Charges Updated', 'Booking charges have been updated. Review the billing details.'],
        'Hotel Booking Expense Added' => ['booking_updated', 'Booking Charges Updated', 'Hotel booking charges have been updated. Review the billing details.'],
        'Refund Status Updated' => ['refund_updated', 'Refund Status Updated', 'The refund status has changed. Review the cancellation details.'],
    ];
}

/** Called only by successful business-event producers, never by the inbox GET. */
function NotificationEmit(PDO $pdo, string $action, string $domain, int $bookingId, string $sourceKey = ''): void
{
    if (!isset(NotificationEvents()[$action]) || !NotificationAvailable($pdo)) return;
    try {
        $booking = NotificationBooking($pdo, $domain, $bookingId);
        if (!$booking) return;
        [$type, $title, $message] = NotificationEvents()[$action];
        $recipients = [];
        $touristId = (int)($booking['tourist_id'] ?? 0);
        if ($touristId > 0) $recipients[] = ['tourist', $touristId];
        $touristOnly = $type === 'tourist_information_required';
        if (!$touristOnly) {
            if ($domain === 'tour' && (int)($booking['operator_id'] ?? 0) > 0) $recipients[] = ['operator', (int)$booking['operator_id']];
            if ($domain === 'hotel') {
                $query = $pdo->prepare("SELECT hotel_admin_id FROM hotel_admin_accounts WHERE hotel_resort_id = ? AND LOWER(status) = 'active'");
                $query->execute([(int)$booking['hotel_resort_id']]);
                foreach ($query->fetchAll(PDO::FETCH_COLUMN) as $id) $recipients[] = ['hotel_admin', (int)$id];
            }
            // Main admins manage tours and both domains' cancellation requests.
            if ($domain === 'tour' || str_contains($type, 'cancellation') || $type === 'refund_updated') {
                foreach ($pdo->query('SELECT admin_id FROM admin_users')->fetchAll(PDO::FETCH_COLUMN) as $id) $recipients[] = ['admin', (int)$id];
            }
        }
        $state = [$booking['status'] ?? $booking['booking_status'] ?? '', $booking['is_complete'] ?? '', $booking['booking_date'] ?? $booking['checkin_date'] ?? '', $booking['payment_amount'] ?? $booking['amount_paid'] ?? '', $booking['remaining_balance'] ?? ''];
        if ($sourceKey === '') $sourceKey = json_encode($state, JSON_THROW_ON_ERROR);
        if ($type === 'booking_submitted') $sourceKey = 'created';
        $eventKey = hash('sha256', "$type|$domain|$bookingId|$sourceKey");
        $mysql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
        $insert = $pdo->prepare('INSERT INTO app_notifications (recipient_role, recipient_id, event_key, event_type, booking_domain, booking_id, title, message) VALUES (?,?,?,?,?,?,?,?)' . ($mysql ? ' ON DUPLICATE KEY UPDATE notification_id=notification_id' : ' ON CONFLICT(recipient_role,recipient_id,event_key) DO NOTHING'));
        foreach ($recipients as [$role, $id]) $insert->execute([$role, $id, $eventKey, $type, $domain, $bookingId, $title, $message]);
    } catch (Throwable $error) {
        error_log('In-app notification could not be recorded (' . $action . ').');
    }
}

/** Existing log call sites already represent successful, authorized actions. */
function NotificationFromActivity(PDO $pdo, string $action, string $module, int $id, string $description, ?string $domain = null): void
{
    if ($id < 1 || !isset(NotificationEvents()[$action]) || !NotificationAvailable($pdo)) return;
    try {
        $source = '';
        if ($module === 'Payments') {
            // Both domains can share numeric IDs. Resolve the exact reference used by
            // the existing payment producer, never guess from the numeric ID alone.
            if (!preg_match('/ for booking ([A-Za-z0-9-]+)\.$/', $description, $match)) return;
            $paymentWhere = '';
            $paymentParams = [$id, $match[1]];
            if (in_array($action, ['Booking Payment Verified', 'Online Balance Payment Verified'], true)) {
                if (!preg_match('/Verified PayMongo payment (pay_[A-Za-z0-9]+) /', $description, $paymentMatch)) return;
                $paymentWhere = " AND provider_payment_id=? AND status='paid'";
                $paymentParams[] = $paymentMatch[1];
            }
            $query = $pdo->prepare('SELECT booking_domain, provider_payment_id, payment_transaction_id FROM payment_transactions WHERE booking_id=? AND booking_reference=?' . $paymentWhere . ' ORDER BY payment_transaction_id DESC LIMIT 1');
            $query->execute($paymentParams);
            $payment = $query->fetch(PDO::FETCH_ASSOC);
            if (!$payment) return;
            $domain = $payment['booking_domain'] === 'hotel' ? 'hotel' : 'tour';
            $source = (string)$payment['payment_transaction_id'];
        }
        $domain ??= in_array($module, ['Hotel Bookings', 'Hotel Rooms'], true) ? 'hotel' : 'tour';
        if (in_array($action, ['Tourist Data Submitted', 'Tourist Data Updated'], true)) {
            $table = $domain === 'hotel' ? 'hotel_booking_tourists' : 'booking_tourists';
            $query = $pdo->prepare("SELECT MAX(id) FROM $table WHERE booking_id=?");
            $query->execute([$id]);
            $source = (string)$query->fetchColumn();
            if ($source === '') return;
        }
        if (str_contains($action, 'Cancellation') || in_array($action, ['Reschedule Offered', 'Booking Rescheduled', 'Reschedule Decision Expired', 'Refund Status Updated'], true)) {
            $query = $pdo->prepare('SELECT * FROM booking_cancellation_requests WHERE booking_domain=? AND booking_id=? LIMIT 1');
            $query->execute([$domain, $id]);
            $request = $query->fetch(PDO::FETCH_ASSOC);
            if (!$request) return;
            $source = json_encode([$request['cancellation_request_id'], $request['request_status'], $request['refund_status'], $request['tourist_decision'] ?? '', $request['rescheduled_service_date'] ?? ''], JSON_THROW_ON_ERROR);
        }
        NotificationEmit($pdo, $action, $domain, $id, $source);
        if ($action === 'Booking Payment Updated' && $domain === 'tour') {
            $paidBooking = NotificationBooking($pdo, 'tour', $id);
            if (($paidBooking['is_complete'] ?? '') === 'completed') NotificationEmit($pdo, 'Booking Completed', 'tour', $id);
        }
    } catch (Throwable $error) { error_log('In-app activity notification unavailable.'); }
}

/** Recipient ownership is rechecked on every read and every click. */
function NotificationScope(string $role, int $id, int $propertyId = 0): array
{
    $tour = match ($role) {
        'tourist' => 'b.tourist_id = ?', 'operator' => 'b.operator_id = ?', 'admin' => '1=1', default => '1=0',
    };
    $hotel = match ($role) {
        'tourist' => 'h.tourist_id = ?', 'hotel_admin' => 'h.hotel_resort_id = ?', 'admin' => '1=1', default => '1=0',
    };
    $params = [$role, $id];
    if (in_array($role, ['tourist', 'operator'], true)) $params[] = $id;
    if ($role === 'tourist') $params[] = $id;
    if ($role === 'hotel_admin') $params[] = $propertyId > 0 ? $propertyId : 0;
    return ["recipient_role=? AND recipient_id=? AND ((booking_domain='tour' AND EXISTS (SELECT 1 FROM bookings b WHERE b.booking_id=app_notifications.booking_id AND $tour)) OR (booking_domain='hotel' AND EXISTS (SELECT 1 FROM hotel_room_bookings h WHERE h.hotel_booking_id=app_notifications.booking_id AND $hotel)))", $params];
}

function NotificationInbox(PDO $pdo, string $role, int $id, int $propertyId = 0): array
{
    [$where, $params] = NotificationScope($role, $id, $propertyId);
    $query = $pdo->prepare("SELECT notification_id, event_type, booking_domain, booking_id, title, message, created_at, read_at FROM app_notifications WHERE $where ORDER BY created_at DESC, notification_id DESC LIMIT 50");
    $query->execute($params);
    $items = $query->fetchAll(PDO::FETCH_ASSOC);
    $query = $pdo->prepare("SELECT COUNT(*) FROM app_notifications WHERE $where AND read_at IS NULL");
    $query->execute($params);
    return ['data' => $items, 'unread' => (int)$query->fetchColumn()];
}

function NotificationMarkRead(PDO $pdo, string $role, int $id, int $propertyId, int $through, ?int $only = null): void
{
    [$where, $params] = NotificationScope($role, $id, $propertyId);
    $where .= $only !== null ? ' AND notification_id=?' : ' AND notification_id<=?';
    $params[] = $only ?? $through;
    $query = $pdo->prepare("UPDATE app_notifications SET read_at=CURRENT_TIMESTAMP WHERE $where AND read_at IS NULL");
    $query->execute($params);
}

function NotificationDestination(PDO $pdo, array $item, array $booking, string $role): string
{
    $domain = (string)$item['booking_domain'];
    $id = (int)$item['booking_id'];
    $type = (string)$item['event_type'];
    $query = $pdo->prepare('SELECT cancellation_request_id, request_status FROM booking_cancellation_requests WHERE booking_domain=? AND booking_id=? LIMIT 1');
    $query->execute([$domain, $id]);
    $request = $query->fetch(PDO::FETCH_ASSOC);
    $cancellation = str_contains($type, 'cancellation') || str_contains($type, 'cancelled') || str_contains($type, 'reschedul') || $type === 'refund_updated';
    if ($role === 'admin') {
        if ($cancellation && $request) return 'adbookings.php?tab=cancellations&focus_request=' . (int)$request['cancellation_request_id'];
        return 'adbookings.php?tab=all&focus_booking=' . $id . '&notification_booking=' . $id . ($type === 'tourist_details_submitted' || $type === 'tourist_details_updated' ? '&notification_section=tourists' : '');
    }
    if ($role === 'tourist') {
        if ($cancellation && $request && $request['request_status'] === 'decision_required') {
            return 'php/profile.php?section=bookings&cancellation_request=' . (int)$request['cancellation_request_id'] . '&notification_booking=' . $id . '&notification_domain=' . $domain . '&notification_action=decision';
        }
        if ($cancellation && $request && $request['request_status'] !== 'rescheduled') return 'php/profile.php?section=cancel-bookings&cancellation_request=' . (int)$request['cancellation_request_id'] . '&notification_booking=' . $id . '&notification_domain=' . $domain;
        if ($type === 'tourist_information_required') {
            $table = $domain === 'hotel' ? 'hotel_booking_tourists' : 'booking_tourists';
            $query = $pdo->prepare("SELECT COUNT(*) FROM $table WHERE booking_id=?");
            $query->execute([$id]);
            $active = $domain === 'hotel' ? HotelBookingStatus($booking) === 'confirmed' : strtolower((string)$booking['status']) === 'accepted' && strtolower((string)$booking['is_complete']) === 'uncomplete';
            if ((int)$query->fetchColumn() === 0 && $active && (!$request || !in_array($request['request_status'], ['pending','approved','decision_required','completed'], true))) {
                return 'php/profile.php?section=bookings&submit_tourists=' . $id . '&submit_domain=' . $domain;
            }
        }
        $completed = $domain === 'hotel' ? in_array(HotelBookingStatus($booking), ['completed', 'cancelled'], true) : in_array(strtolower((string)$booking['is_complete']), ['completed','cancelled','declined'], true) || strtolower((string)$booking['status']) === 'declined';
        return 'php/profile.php?section=' . ($completed ? 'history' : 'bookings') . '&notification_booking=' . $id . '&notification_domain=' . $domain . (str_starts_with($type, 'payment_') ? '&notification_action=billing' : '');
    }
    if ($role === 'hotel_admin' && $domain === 'hotel' && $cancellation && $request) return 'Hobookings.php?tab=cancellations&focus_request=' . (int)$request['cancellation_request_id'];
    $page = $role === 'hotel_admin' ? 'Hobookings.php' : 'opbookings.php';
    return $page . '?notification_booking=' . $id;
}
