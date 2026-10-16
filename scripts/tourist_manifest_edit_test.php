<?php
declare(strict_types=1);

// Offline regression: no real bookings, accounts, emails, or database writes.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../php/booking_tourists_helper.php';

function verifyManifestEdit(bool $condition, string $label): void
{
    if (!$condition) throw new RuntimeException($label);
}
function expectManifestRejection(callable $operation, string $message): void
{
    try { $operation(); } catch (Throwable $error) {
        verifyManifestEdit(str_contains($error->getMessage(), $message), 'Unexpected rejection: ' . $error->getMessage());
        return;
    }
    throw new RuntimeException('Expected rejection: ' . $message);
}

$booking = ['status' => 'accepted', 'is_complete' => 'uncomplete', 'booking_date' => '2026-10-29'];
verifyManifestEdit(bookingTouristCanEdit($booking, new DateTimeImmutable('2026-10-25 23:59:59', new DateTimeZone('Asia/Manila'))), 'Editing must work before the cutoff.');
verifyManifestEdit(!bookingTouristCanEdit($booking, new DateTimeImmutable('2026-10-26 00:00:00', new DateTimeZone('Asia/Manila'))), 'Editing must stop exactly at the cutoff.');
verifyManifestEdit(!bookingTouristCanEdit($booking, new DateTimeImmutable('2026-10-25 16:00:00', new DateTimeZone('UTC'))), 'Timezone comparisons must use the same instant.');
verifyManifestEdit(!bookingTouristCanEdit(array_merge($booking, ['booking_date' => '2026-02-30'])), 'Invalid dates must not permit editing.');
verifyManifestEdit(!bookingTouristCanEdit(array_merge($booking, ['is_complete' => 'completed'])), 'Completed bookings must not permit editing.');

$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('CREATE TABLE bookings (booking_id INTEGER PRIMARY KEY, tourist_id INTEGER, status TEXT, is_complete TEXT, booking_date TEXT, booking_type TEXT, pax INTEGER, num_adults INTEGER, num_children INTEGER)');
$pdo->exec('CREATE TABLE booking_cancellation_requests (booking_domain TEXT, booking_id INTEGER, request_status TEXT)');
$pdo->exec('CREATE TABLE booking_tourists (id INTEGER PRIMARY KEY AUTOINCREMENT, booking_id INTEGER, full_name TEXT NOT NULL, gender TEXT, age INTEGER, address TEXT, country TEXT, region TEXT, province TEXT, city TEXT, barangay TEXT, postal_code TEXT, street TEXT, residence TEXT, phone_number TEXT, boat_number INTEGER)');
$future = (new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))->modify('+10 days')->format('Y-m-d');
$pdo->prepare("INSERT INTO bookings VALUES (42,7,'accepted','uncomplete',?,'boat',1,1,0)")->execute([$future]);
$original = ['full_name' => 'Original Guest', 'gender' => 'female', 'age' => 29, 'address' => 'Full address', 'country' => 'Philippines', 'region' => 'Bicol', 'province' => 'Camarines Norte', 'city' => 'Mercedes', 'barangay' => 'San Roque', 'postal_code' => '4601', 'street' => 'Main Street', 'residence' => 'philippines', 'phone_number' => '09123456789'];
bookingTouristSaveManifest($pdo, 42, 7, [$original], [1], false);
$saved = static fn(): array => $pdo->query('SELECT * FROM booking_tourists WHERE booking_id = 42 ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
verifyManifestEdit($saved()[0]['full_name'] === 'Original Guest', 'First submission must still work.');
expectManifestRejection(static fn() => bookingTouristSaveManifest($pdo, 42, 7, [$original], [1], false), 'already submitted');
$updated = array_merge($original, ['full_name' => 'Updated Guest', 'age' => 30, 'street' => 'Updated Street', 'phone_number' => '09999999999']);
bookingTouristSaveManifest($pdo, 42, 7, [$updated], [1], true);
verifyManifestEdit(count($saved()) === 1 && $saved()[0]['full_name'] === 'Updated Guest' && $saved()[0]['street'] === 'Updated Street' && $saved()[0]['phone_number'] === '09999999999', 'Editing must replace the full list without duplicates.');
expectManifestRejection(static fn() => bookingTouristSaveManifest($pdo, 42, 8, [$original], [1], true), 'your active accepted bookings');
expectManifestRejection(static fn() => bookingTouristSaveManifest($pdo, 42, 7, [$original, $original], [1,1], true), 'Complete all 1');
expectManifestRejection(static fn() => bookingTouristSaveManifest($pdo, 42, 7, [$original], [2], true), 'Invalid tourist boat assignment');
$pdo->exec("INSERT INTO booking_cancellation_requests VALUES ('tour',42,'decision_required')");
expectManifestRejection(static fn() => bookingTouristSaveManifest($pdo, 42, 7, [$original], [1], true), 'cancellation workflow');
$pdo->exec('DELETE FROM booking_cancellation_requests');
$closedDate = (new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))->modify('+3 days')->format('Y-m-d');
$pdo->prepare('UPDATE bookings SET booking_date = ? WHERE booking_id = 42')->execute([$closedDate]);
expectManifestRejection(static fn() => bookingTouristSaveManifest($pdo, 42, 7, [$original], [1], true), 'Editing is closed');
verifyManifestEdit($saved()[0]['full_name'] === 'Updated Guest', 'Rejected edits must retain the existing list.');
$pdo->prepare('UPDATE bookings SET booking_date = ? WHERE booking_id = 42')->execute([$future]);
$pdo->exec("CREATE TRIGGER fail_manifest_insert BEFORE INSERT ON booking_tourists BEGIN SELECT RAISE(ABORT, 'Simulated save failure'); END");
expectManifestRejection(static fn() => bookingTouristSaveManifest($pdo, 42, 7, [$original], [1], true), 'Simulated save failure');
verifyManifestEdit($saved()[0]['full_name'] === 'Updated Guest', 'Failed replacements must roll back to the saved list.');
$pdo->exec('DROP TRIGGER fail_manifest_insert');
$pdo->exec("UPDATE bookings SET booking_type = 'boat', pax = 9, num_adults = 9");
bookingTouristSaveManifest($pdo, 42, 7, array_fill(0,9,$original), [1,1,1,1,1,1,1,1,2], true);
verifyManifestEdit(count($saved()) === 9 && (int)$saved()[8]['boat_number'] === 2, 'Multiple boat assignments must be preserved.');
echo "PASS: exact cutoff, Manila timezone, valid dates, initial submission, full-list edits, ownership, passenger count, boat validation, cancellation protection, stale saves, rollback, and multiple boats.\n";
