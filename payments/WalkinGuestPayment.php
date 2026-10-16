<?php
declare(strict_types=1);

/** Narrow binding for staff payments on bookings explicitly without an account. */
final class WalkinGuestPayment
{
    public static function isGuestBooking(array $booking): bool
    {
        return array_key_exists('tourist_id', $booking) && $booking['tourist_id'] === null
            && trim((string)($booking['walkin_guest_name'] ?? '')) !== ''
            && stripos((string)($booking['preferred_resource'] ?? ''), 'Walk-in booking') !== false
            && in_array(strtolower((string)($booking['booking_type'] ?? '')), ['boat','tourguide','package'], true);
    }

    public static function assertBinding(array $transaction, array $metadata): void
    {
        $source = (string)($metadata['source'] ?? '');
        $staffId = $source === 'admin_booking_payment' ? (int)($metadata['admin_id'] ?? 0)
            : ($source === 'operator_booking_payment' ? (int)($metadata['operator_id'] ?? 0) : 0);
        if ((int)($transaction['tourist_id'] ?? -1) !== 0 || $staffId < 1
            || (int)($transaction['booking_id'] ?? 0) < 1
            || (int)($metadata['walkin_guest_booking_id'] ?? 0) !== (int)$transaction['booking_id']
            || (string)($metadata['booking_domain'] ?? '') !== (string)($transaction['booking_domain'] ?? '')
            || !in_array((string)($transaction['booking_domain'] ?? ''), ['boat','tourguide','package'], true)) {
            throw new UnexpectedValueException('The no-account payment does not match its staff-created walk-in booking.');
        }
    }
}
