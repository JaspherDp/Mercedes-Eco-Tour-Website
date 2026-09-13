<?php
declare(strict_types=1);

require_once __DIR__ . '/../php/activity_logger.php';
require_once __DIR__ . '/BookingCheckoutService.php';

final class PaymentReconciler
{
    /**
     * @param array<string, mixed> $checkoutSession PayMongo checkout_session resource
     * @return array{transaction_id:int, booking_id:int, status:string, idempotent:bool}
     */
    public static function reconcilePaidCheckout(
        PDO $pdo,
        array $checkoutSession,
        ?string $eventId = null
    ): array {
        $sessionId = trim((string)($checkoutSession['id'] ?? ''));
        $attributes = is_array($checkoutSession['attributes'] ?? null)
            ? $checkoutSession['attributes']
            : [];
        if (!str_starts_with($sessionId, 'cs_') || ($checkoutSession['type'] ?? '') !== 'checkout_session') {
            throw new UnexpectedValueException('The event does not contain a Checkout Session resource.');
        }
        if (($attributes['livemode'] ?? null) !== false) {
            throw new UnexpectedValueException('Only test-mode Checkout Sessions can be reconciled.');
        }

        $reference = trim((string)($attributes['reference_number'] ?? ''));
        $payments = is_array($attributes['payments'] ?? null) ? $attributes['payments'] : [];
        if ($payments === [] && is_array($attributes['payment_intent']['attributes']['payments'] ?? null)) {
            $payments = $attributes['payment_intent']['attributes']['payments'];
        }

        $paidPayment = null;
        foreach (array_reverse($payments) as $payment) {
            $paymentAttributes = is_array($payment['attributes'] ?? null) ? $payment['attributes'] : [];
            if (strtolower((string)($paymentAttributes['status'] ?? '')) === 'paid') {
                $paidPayment = $payment;
                break;
            }
        }
        if (!is_array($paidPayment)) {
            throw new UnexpectedValueException('The Checkout Session does not contain a paid Payment resource.');
        }

        $paymentId = trim((string)($paidPayment['id'] ?? ''));
        $paymentAttributes = is_array($paidPayment['attributes'] ?? null) ? $paidPayment['attributes'] : [];
        $amountMinor = (int)($paymentAttributes['amount'] ?? 0);
        $currency = strtoupper(trim((string)($paymentAttributes['currency'] ?? '')));
        $paymentLivemode = $paymentAttributes['livemode'] ?? false;
        if (!str_starts_with($paymentId, 'pay_') || $amountMinor < 1 || $currency !== 'PHP' || $paymentLivemode === true) {
            throw new UnexpectedValueException('The paid Payment resource failed validation.');
        }

        $paymentIntentId = trim((string)($paymentAttributes['payment_intent_id'] ?? $attributes['payment_intent']['id'] ?? ''));
        $source = is_array($paymentAttributes['source'] ?? null) ? $paymentAttributes['source'] : [];
        $method = strtolower(trim((string)($source['type'] ?? 'paymongo')));
        $method = preg_replace('/[^a-z0-9_-]/', '', $method) ?: 'paymongo';
        $eventId = trim((string)$eventId);
        if ($eventId !== '' && !str_starts_with($eventId, 'evt_')) {
            $eventId = '';
        }

        $pdo->beginTransaction();
        try {
            $transactionStmt = $pdo->prepare(
                "SELECT * FROM payment_transactions
                 WHERE provider_checkout_session_id = ? OR merchant_reference = ?
                 ORDER BY provider_checkout_session_id = ? DESC
                 LIMIT 1 FOR UPDATE"
            );
            $transactionStmt->execute([$sessionId, $reference, $sessionId]);
            $transaction = $transactionStmt->fetch(PDO::FETCH_ASSOC);
            if (!$transaction) {
                throw new UnexpectedValueException('No local payment transaction matches this Checkout Session.');
            }
            if ((string)$transaction['merchant_reference'] !== $reference) {
                throw new UnexpectedValueException('PayMongo reference does not match the local transaction.');
            }
            if ((string)$transaction['provider_checkout_session_id'] !== ''
                && (string)$transaction['provider_checkout_session_id'] !== $sessionId) {
                throw new UnexpectedValueException('Checkout Session ID does not match the local transaction.');
            }
            if ((int)$transaction['amount_minor'] !== $amountMinor || strtoupper((string)$transaction['currency']) !== $currency) {
                throw new UnexpectedValueException('Paid amount or currency does not match the local transaction.');
            }
            if ((string)$transaction['status'] === 'paid') {
                $pdo->commit();
                return [
                    'transaction_id' => (int)$transaction['payment_transaction_id'],
                    'booking_id' => (int)$transaction['booking_id'],
                    'status' => 'paid',
                    'idempotent' => true,
                ];
            }

            $bookingId = (int)$transaction['booking_id'];
            $touristId = (int)$transaction['tourist_id'];
            $domain = strtolower((string)$transaction['booking_domain']);
            $amount = $amountMinor / 100;
            $metadata = json_decode((string)($transaction['metadata'] ?? ''), true);
            $metadata = is_array($metadata) ? $metadata : [];
            $isAdminPayment = in_array(
                (string)($metadata['source'] ?? ''),
                ['admin_booking_payment', 'hotel_checkin_payment', 'hotel_checkout_payment', 'operator_booking_payment'],
                true
            );
            $isHotelCheckoutPayment = ($metadata['source'] ?? '') === 'hotel_checkout_payment';
            $isBookingCheckout = ($metadata['source'] ?? '') === 'booking_checkout';

            if ($isBookingCheckout) {
                $draftId = (int)($metadata['booking_draft_id'] ?? 0);
                if ($draftId < 1) {
                    throw new UnexpectedValueException('The payment is missing its booking draft.');
                }
                $submitted = BookingCheckoutService::submitPaidDraft(
                    $pdo,
                    $draftId,
                    $touristId,
                    $amount,
                    'paymongo_' . $method
                );
                $bookingId = $submitted['booking_id'];
                $domain = $submitted['domain'];
                $transaction['booking_reference'] = $submitted['booking_reference'];
            }

            if ($isBookingCheckout) {
                // The newly-created booking already contains this verified
                // payment. Do not apply it a second time as a balance payment.
            } elseif ($domain === 'hotel') {
                $bookingStmt = $pdo->prepare(
                    "SELECT remaining_balance, amount_paid, total_amount, checked_in_at, checked_out_at
                     FROM hotel_room_bookings
                     WHERE hotel_booking_id = ? AND tourist_id = ?
                     LIMIT 1 FOR UPDATE"
                );
                $bookingStmt->execute([$bookingId, $touristId]);
                $booking = $bookingStmt->fetch(PDO::FETCH_ASSOC);
                if (!$booking) {
                    throw new UnexpectedValueException('The hotel booking no longer belongs to the paying tourist.');
                }
                $balance = round((float)$booking['remaining_balance'], 2);
                if ($isHotelCheckoutPayment) {
                    if (empty($booking['checked_in_at']) || !empty($booking['checked_out_at'])) {
                        throw new UnexpectedValueException('The guest is no longer eligible for checkout payment.');
                    }
                    $baseRemaining = round((float)($metadata['checkout_base_remaining'] ?? -1), 2);
                    $additionalCharges = round(max(0, (float)($metadata['checkout_additional_charges'] ?? 0)), 2);
                    $expectedDue = round($balance + $additionalCharges, 2);
                    if ($baseRemaining < 0 || abs($baseRemaining - $balance) > 0.009
                        || abs($amount - $expectedDue) > 0.009) {
                        throw new UnexpectedValueException('Verified checkout payment no longer matches the amount due.');
                    }
                    $newBalance = round(max(0, $expectedDue - $amount), 2);
                    $newPaid = round((float)$booking['amount_paid'] + $amount, 2);
                    $newTotal = round((float)$booking['total_amount'] + $additionalCharges, 2);
                    $updateBooking = $pdo->prepare(
                        "UPDATE hotel_room_bookings
                         SET total_amount = ?, amount_paid = ?, remaining_balance = ?, payment_status = ?,
                             balance_payment_method = ?, checkout_additional_charges = ?,
                             checkout_final_payment_amount = ?, checkout_payment_method = 'qr_code',
                             updated_at = NOW()
                         WHERE hotel_booking_id = ? AND tourist_id = ?"
                    );
                    $updateBooking->execute([
                        $newTotal,
                        $newPaid,
                        $newBalance,
                        $newBalance <= 0 ? 'paid' : 'partial',
                        'paymongo_' . $method,
                        $additionalCharges,
                        $amount,
                        $bookingId,
                        $touristId,
                    ]);
                } else {
                    if ($amount > $balance + 0.009) {
                        throw new UnexpectedValueException('Verified payment exceeds the current hotel balance.');
                    }
                    $newBalance = round(max(0, $balance - $amount), 2);
                    $newPaid = round((float)$booking['amount_paid'] + $amount, 2);
                    $updateBooking = $pdo->prepare(
                        "UPDATE hotel_room_bookings
                         SET amount_paid = ?, remaining_balance = ?, payment_status = ?,
                             balance_payment_method = ?, updated_at = NOW()
                         WHERE hotel_booking_id = ? AND tourist_id = ?"
                    );
                    $updateBooking->execute([
                        $newPaid,
                        $newBalance,
                        $newBalance <= 0 ? 'paid' : 'partial',
                        'paymongo_' . $method,
                        $bookingId,
                        $touristId,
                    ]);
                }
            } elseif (in_array($domain, ['package', 'boat', 'tourguide'], true)) {
                $bookingStmt = $pdo->prepare(
                    "SELECT remaining_balance, payment_amount, booking_type
                     FROM bookings
                     WHERE booking_id = ? AND tourist_id = ?
                     LIMIT 1 FOR UPDATE"
                );
                $bookingStmt->execute([$bookingId, $touristId]);
                $booking = $bookingStmt->fetch(PDO::FETCH_ASSOC);
                if (!$booking || strtolower((string)$booking['booking_type']) !== $domain) {
                    throw new UnexpectedValueException('The tour booking no longer matches the local transaction.');
                }
                $balance = round((float)$booking['remaining_balance'], 2);
                if ($amount > $balance + 0.009) {
                    throw new UnexpectedValueException('Verified payment exceeds the current tour balance.');
                }
                $newBalance = round(max(0, $balance - $amount), 2);
                $newPaid = round((float)$booking['payment_amount'] + $amount, 2);
                $updateBooking = $pdo->prepare(
                    "UPDATE bookings
                     SET payment_amount = ?, remaining_balance = ?, is_paid = ?,
                         payment_method = ?, updated_at = NOW()
                     WHERE booking_id = ? AND tourist_id = ?"
                );
                $updateBooking->execute([
                    $newPaid,
                    $newBalance,
                    $newBalance <= 0 ? 1 : 0,
                    'paymongo_' . $method,
                    $bookingId,
                    $touristId,
                ]);
                if ($isAdminPayment
                    && !empty($metadata['complete_after_payment'])
                    && $newBalance <= 0) {
                    $completeBooking = $pdo->prepare(
                        "UPDATE bookings
                         SET is_complete = 'completed', updated_at = NOW()
                         WHERE booking_id = ? AND tourist_id = ? AND status = 'accepted'"
                    );
                    $completeBooking->execute([$bookingId, $touristId]);
                }
            } else {
                throw new UnexpectedValueException('Unsupported payment booking domain.');
            }

            $paidAtValue = $paymentAttributes['paid_at'] ?? null;
            $manilaTimezone = new DateTimeZone('Asia/Manila');
            $paidAt = is_numeric($paidAtValue) && (int)$paidAtValue > 0
                ? (new DateTimeImmutable('@' . (int)$paidAtValue))->setTimezone($manilaTimezone)->format('Y-m-d H:i:s')
                : (new DateTimeImmutable('now', $manilaTimezone))->format('Y-m-d H:i:s');
            $updateTransaction = $pdo->prepare(
                "UPDATE payment_transactions
                 SET status = 'paid', provider_checkout_session_id = ?,
                     booking_domain = ?, booking_id = ?, booking_reference = ?,
                     provider_payment_intent_id = ?, provider_payment_id = ?,
                     provider_event_id = COALESCE(?, provider_event_id),
                     payment_method_type = ?, paid_at = ?, failed_at = NULL,
                     failure_code = NULL, failure_message = NULL
                 WHERE payment_transaction_id = ?"
            );
            $updateTransaction->execute([
                $sessionId,
                $domain,
                $bookingId,
                (string)$transaction['booking_reference'],
                $paymentIntentId !== '' ? $paymentIntentId : null,
                $paymentId,
                $eventId !== '' ? $eventId : null,
                $method,
                $paidAt,
                (int)$transaction['payment_transaction_id'],
            ]);

            $activityActorId = $isAdminPayment
                ? (int)($metadata['hotel_admin_id'] ?? $metadata['operator_id'] ?? $metadata['admin_id'] ?? 0)
                : $touristId;
            $activityActorName = $isAdminPayment
                ? trim((string)($metadata['admin_name'] ?? 'Administrator'))
                : 'Tourist #' . $touristId;
            logActivity(
                $pdo,
                $isAdminPayment ? (($metadata['staff_type'] ?? '') === 'hotel_admin' ? 'Hotel Owner' : (($metadata['staff_type'] ?? '') === 'operator' ? 'Tour Operator' : 'Admin')) : 'Tourist',
                $activityActorId,
                $activityActorName !== '' ? $activityActorName : 'Administrator',
                $isBookingCheckout ? 'Booking Payment Verified' : 'Online Balance Payment Verified',
                'Verified PayMongo payment ' . $paymentId . ' for booking ' . (string)$transaction['booking_reference'] . '.',
                'Payments',
                $bookingId
            );

            $pdo->commit();
            return [
                'transaction_id' => (int)$transaction['payment_transaction_id'],
                'booking_id' => $bookingId,
                'status' => 'paid',
                'idempotent' => false,
            ];
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }
}
