<?php
/** Canonical hotel lifecycle names; payment and check-in state remain separate. */
function HotelBookingStatus(array|string $booking): string
{
    $value = is_array($booking) ? (string)($booking['booking_status'] ?? 'pending') : $booking;
    $status = strtolower(trim(str_replace('_', '-', $value)));
    $status = match ($status) {
        '', 'pending' => 'pending',
        'accepted', 'approved', 'confirmed', 'checked-in' => 'confirmed',
        'complete', 'completed', 'checked-out' => 'completed',
        'canceled', 'cancelled', 'declined' => 'cancelled',
        'no show', 'noshow', 'no-show' => 'no-show',
        default => $status,
    };
    if (is_array($booking) && !empty($booking['checked_out_at']) && in_array($status, ['pending','confirmed'], true)) return 'completed';
    return $status;
}

function HotelBookingApplyStatus(array $booking): array
{
    $booking['booking_status'] = HotelBookingStatus($booking);
    return $booking;
}

function HotelBookingStatusLabel(array|string $booking): string
{
    $status = HotelBookingStatus($booking);
    return strtoupper($status === 'confirmed' ? 'accepted' : $status);
}
