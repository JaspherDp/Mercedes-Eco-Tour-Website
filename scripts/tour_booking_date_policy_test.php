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
// Execute the actual search URL guard without loading the database-backed page.
$source = file_get_contents(__DIR__ . '/../public/search_results.php');
$start = strpos($source, '$searchDateError = ');
$end = strpos($source, "\nif (\$resultType !== 'hotels' &&", $start);
if ($start === false || $end === false) throw new RuntimeException('Search guard missing.');
$guard = substr($source, $start, $end - $start);
$minimum = tourBookingEarliestDate();
$tooSoon = (new DateTimeImmutable($minimum))->modify('-1 day')->format('Y-m-d');
foreach (['tours', 'guides', 'boats', 'bundle', 'hotels'] as $resultType) {
    foreach ([['checkin', $tooSoon], ['date', $tooSoon], ['checkout', $tooSoon],
              ['date', 'not-a-date'], ['date', $minimum]] as [$field, $value]) {
        $checkin = $date = $checkout = '';
        $$field = $value;
        http_response_code(200);
        eval($guard);
        $shouldReject = $resultType !== 'hotels' && $value !== $minimum;
        if (($searchDateError !== '') !== $shouldReject || http_response_code() !== ($shouldReject ? 422 : 200)) {
            throw new RuntimeException('Search URL date guard failed.');
        }
        ++$checks;
    }
}
echo "PASS: $checks server date-policy assertions; no database or payment calls.\n";
