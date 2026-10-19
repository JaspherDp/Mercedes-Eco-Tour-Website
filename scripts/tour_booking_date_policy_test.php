<?php
declare(strict_types=1);
require_once __DIR__ . '/../php/tour_booking_date_policy.php';
$checks = 0;
foreach ([['2026-10-19T00:00:00+08:00','2026-10-21'],['2026-10-19T23:59:59+08:00','2026-10-21'],['2026-10-19T16:00:00Z','2026-10-22'],['2026-12-31T12:00:00+08:00','2027-01-02'],['2028-02-28T12:00:00+08:00','2028-03-01']] as [$instant,$minimum]) {
    $now = new DateTimeImmutable($instant);
    if (tourBookingEarliestDate($now) !== $minimum) throw new RuntimeException('Incorrect Manila minimum');
    ++$checks;
    foreach ([-3,-2,-1,0,1] as $offset) {
        $date = (new DateTimeImmutable($minimum))->modify("$offset days")->format('Y-m-d');
        $accepted = true;
        try { tourBookingValidateAdvanceDate($date,$now); } catch (DomainException) { $accepted = false; }
        if ($accepted !== ($offset >= 0)) throw new RuntimeException('Incorrect date boundary');
        ++$checks;
    }
}
foreach (['','2026-02-30','2026-13-01','not-a-date'] as $invalid) {
    try { tourBookingValidateAdvanceDate($invalid); } catch (DomainException) { ++$checks; continue; }
    throw new RuntimeException('Invalid date accepted');
}
echo "PASS: $checks server date-policy assertions; no database or payment calls.\n";
