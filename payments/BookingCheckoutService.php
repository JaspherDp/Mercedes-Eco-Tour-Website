<?php
declare(strict_types=1);

require_once __DIR__ . '/../php/booking_reference_helper.php';
require_once __DIR__ . '/../php/activity_logger.php';

final class BookingCheckoutService
{
    public static function tableExists(PDO $pdo): bool
    {
        $stmt = $pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'booking_checkout_drafts'");
        return (int)$stmt->fetchColumn() > 0;
    }

    /** @return array{booking_id:int,booking_reference:string,domain:string,idempotent:bool} */
    public static function submitPaidDraft(
        PDO $pdo,
        int $draftId,
        int $touristId,
        float $paidAmount,
        string $paymentMethod
    ): array {
        $stmt = $pdo->prepare('SELECT * FROM booking_checkout_drafts WHERE booking_draft_id = ? AND tourist_id = ? LIMIT 1 FOR UPDATE');
        $stmt->execute([$draftId, $touristId]);
        $draft = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$draft) {
            throw new UnexpectedValueException('The paid booking draft could not be found.');
        }
        if ((string)$draft['status'] === 'submitted' && (int)$draft['booking_id'] > 0) {
            return [
                'booking_id' => (int)$draft['booking_id'],
                'booking_reference' => (string)$draft['booking_reference'],
                'domain' => (string)$draft['booking_domain'],
                'idempotent' => true,
            ];
        }
        // A verified paid event wins a race with a browser cancel/failure
        // return. This method is reachable only through signed/retrieved
        // PayMongo reconciliation, so a real payment must always produce the
        // corresponding booking.
        if (!in_array((string)$draft['status'], ['pending', 'paid', 'cancelled', 'failed'], true)) {
            throw new UnexpectedValueException('This booking draft is no longer active.');
        }

        $expectedPaid = ((int)$draft['amount_minor']) / 100;
        if (abs($expectedPaid - $paidAmount) > 0.009) {
            throw new UnexpectedValueException('The verified amount does not match the booking draft.');
        }
        $payload = json_decode((string)$draft['payload'], true);
        if (!is_array($payload)) {
            throw new UnexpectedValueException('The booking draft details are invalid.');
        }

        $domain = strtolower((string)$draft['booking_domain']);
        $total = ((int)$draft['total_minor']) / 100;
        $balance = round(max(0, $total - $paidAmount), 2);
        $reference = BookingReferenceGenerate($pdo, $domain);

        if ($domain === 'hotel') {
            $insert = $pdo->prepare(
                "INSERT INTO hotel_room_bookings
                 (booking_reference, tourist_id, hotel_resort_id, hotel_room_id, room_type,
                  checkin_date, checkout_date, nights, rooms_booked, adults, children,
                  first_name, last_name, email, phone_number, special_request, unit_price,
                  total_amount, amount_paid, remaining_balance, payment_type, booking_status,
                  payment_status, balance_payment_method)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?, ?)"
            );
            $insert->execute([
                $reference, $touristId, (int)$payload['hotel_resort_id'], (int)$payload['hotel_room_id'],
                (string)$payload['room_type'], (string)$payload['checkin_date'], (string)$payload['checkout_date'],
                (int)$payload['nights'], (int)$payload['adults'], (int)$payload['children'],
                (string)$payload['first_name'], (string)$payload['last_name'], (string)$payload['email'],
                (string)$payload['phone_number'], $payload['special_request'] ?: null,
                (float)$payload['unit_price'], $total, $paidAmount, $balance,
                (string)$draft['payment_type'], $balance <= 0 ? 'paid' : 'partial', $paymentMethod,
            ]);
            $bookingId = (int)$pdo->lastInsertId();
            $activity = 'Hotel Booking Submitted';
            $description = 'Submitted ' . $reference . ' after verified PayMongo payment for ' . (string)$payload['room_type'] . '.';
            $module = 'Hotel Bookings';
        } elseif (in_array($domain, ['package', 'boat', 'tourguide'], true)) {
            $insert = $pdo->prepare(
                "INSERT INTO bookings
                 (booking_reference, tourist_id, booking_date, location, package_name, phone_number,
                  booking_type, operator_id, tour_type, tour_range, jump_off_port, preferred_resource,
                  boat_id, guide_id, grand_total, remaining_balance, payment_amount, created_at,
                  updated_at, status, is_complete, is_notif_viewed, num_adults, num_children,
                  is_paid, payment_method)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW(),
                         'pending', 'uncomplete', 0, ?, ?, ?, ?)"
            );
            $insert->execute([
                $reference, $touristId, (string)$payload['booking_date'], (string)$payload['location'],
                $payload['package_name'] ?: null, (string)$payload['phone_number'], $domain,
                $payload['operator_id'] ?: null, (string)$payload['tour_type'], (string)$payload['tour_range'],
                (string)$payload['jump_off_port'], (string)$payload['preferred_resource'],
                $payload['boat_id'] ?: null, $payload['guide_id'] ?: null,
                $total, $balance, $paidAmount, (int)$payload['num_adults'], (int)$payload['num_children'],
                $balance <= 0 ? 1 : 0, $paymentMethod,
            ]);
            $bookingId = (int)$pdo->lastInsertId();
            $activity = 'Booking Submitted';
            $description = 'Submitted ' . $reference . ' after verified PayMongo payment.';
            $module = 'Bookings';
        } else {
            throw new UnexpectedValueException('Unsupported booking draft type.');
        }

        $update = $pdo->prepare(
            "UPDATE booking_checkout_drafts
             SET status = 'submitted', booking_id = ?, booking_reference = ?, submitted_at = NOW()
             WHERE booking_draft_id = ?"
        );
        $update->execute([$bookingId, $reference, $draftId]);

        logActivity($pdo, 'Tourist', $touristId, 'Tourist #' . $touristId, $activity, $description, $module, $bookingId);
        return ['booking_id' => $bookingId, 'booking_reference' => $reference, 'domain' => $domain, 'idempotent' => false];
    }
}
