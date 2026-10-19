<?php
declare(strict_types=1);

/** New tours require two calendar days' notice, using the destination's timezone. */
function tourBookingEarliestDate(?DateTimeImmutable $now = null): string
{
    $zone = new DateTimeZone('Asia/Manila');
    $today = ($now ?? new DateTimeImmutable('now', $zone))->setTimezone($zone)->setTime(0, 0);
    return $today->modify('+2 days')->format('Y-m-d');
}

function tourBookingValidateAdvanceDate(string $date, ?DateTimeImmutable $now = null): void
{
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date, new DateTimeZone('Asia/Manila'));
    if (!$parsed || $parsed->format('Y-m-d') !== $date) {
        throw new DomainException('Please select a valid booking date.');
    }
    $minimum = tourBookingEarliestDate($now);
    if ($date < $minimum) {
        throw new DomainException('Boat, tour guide, and tour package bookings must be made at least two calendar days in advance. The earliest available date is ' . $minimum . ' (Manila time).');
    }
}
