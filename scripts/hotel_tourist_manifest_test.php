<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
// Includes the shared writer regressions, then adds hotel fixtures to the isolated SQLite DB.
require __DIR__ . '/tourist_manifest_edit_test.php';
$pdo->exec('CREATE TABLE hotel_room_bookings (hotel_booking_id INTEGER PRIMARY KEY, tourist_id INTEGER, booking_status TEXT, checkin_date TEXT, adults INTEGER, children INTEGER, checked_out_at TEXT)');
$pdo->exec('CREATE TABLE hotel_booking_tourists AS SELECT * FROM booking_tourists WHERE 0');
$pdo->prepare("INSERT INTO hotel_room_bookings VALUES (42,8,'confirmed',?,1,1,NULL)")->execute([$future]);
$pdo->exec('ALTER TABLE hotel_room_bookings ADD COLUMN hotel_resort_id INTEGER');
$pdo->exec('UPDATE hotel_room_bookings SET hotel_resort_id=40');
verifyManifestEdit(hotelBookingTouristPropertyRecord($pdo,42,40)['allowed_pax']===2, 'Hotel staff must see lists for their own property.');
verifyManifestEdit(hotelBookingTouristPropertyRecord($pdo,42,41)===null, 'Hotel staff must not see another property booking by guessing the ID.');
verifyManifestEdit(hotelBookingTouristPropertyRecord($pdo,42,0)===null, 'Missing property scope must not grant access.');
$tourSnapshot = $saved();
verifyManifestEdit(!hotelBookingHasTourists($pdo,42), 'Tour ID 42 must not make hotel ID 42 appear submitted.');
verifyManifestEdit(hotelBookingTouristRecord($pdo,42,7) === null, 'Tour ownership must not grant hotel ownership.');
verifyManifestEdit(hotelBookingTouristRecord($pdo,42,8)['allowed_pax']===2, 'Hotel pax must include adults and children.');
expectManifestRejection(fn()=>bookingTouristSaveManifest($pdo,42,7,[$original,$original],[],false,'hotel'),'your active accepted bookings');
expectManifestRejection(fn()=>bookingTouristSaveManifest($pdo,42,8,[$original],[],false,'hotel'),'Complete all 2');
bookingTouristSaveManifest($pdo,42,8,[$original,$updated],[],false,'hotel');
verifyManifestEdit(hotelBookingHasTourists($pdo,42),'Hotel submit must create the hotel guest list.');
$hotelRows=fn()=>$pdo->query('SELECT * FROM hotel_booking_tourists WHERE booking_id=42')->fetchAll(PDO::FETCH_ASSOC);
verifyManifestEdit(count($hotelRows())===2 && $hotelRows()[0]['boat_number']===null,'Hotel guests must use one list without boat assignments.');
verifyManifestEdit($saved()===$tourSnapshot,'Hotel save must not change the colliding tour booking.');
expectManifestRejection(fn()=>bookingTouristSaveManifest($pdo,42,8,[$original,$updated],[],false,'hotel'),'already submitted');
bookingTouristSaveManifest($pdo,42,8,[$updated,$updated],[],true,'hotel');
verifyManifestEdit($hotelRows()[0]['full_name']==='Updated Guest','Hotel edits must use the same replacement logic.');
$pdo->exec("INSERT INTO booking_cancellation_requests VALUES ('tour',42,'pending')");
bookingTouristSaveManifest($pdo,42,8,[$original,$original],[],true,'hotel');
$pdo->exec("INSERT INTO booking_cancellation_requests VALUES ('hotel',42,'pending')");
expectManifestRejection(fn()=>bookingTouristSaveManifest($pdo,42,8,[$updated,$updated],[],true,'hotel'),'cancellation workflow');
$pdo->exec("DELETE FROM booking_cancellation_requests WHERE booking_domain='hotel'");
$pdo->prepare('UPDATE hotel_room_bookings SET checkin_date=?')->execute([$closedDate]);
expectManifestRejection(fn()=>bookingTouristSaveManifest($pdo,42,8,[$updated,$updated],[],true,'hotel'),'Editing is closed');
$pdo->prepare('UPDATE hotel_room_bookings SET checkin_date=?')->execute([$future]);
foreach (['pending','cancelled','completed'] as $status) {
 $pdo->prepare('UPDATE hotel_room_bookings SET booking_status=?')->execute([$status]);
 expectManifestRejection(fn()=>bookingTouristSaveManifest($pdo,42,8,[$updated,$updated],[],true,'hotel'),'your active accepted bookings');
}
$pdo->exec("UPDATE hotel_room_bookings SET booking_status='accepted', checked_out_at='2026-10-19 12:00:00'");
expectManifestRejection(fn()=>bookingTouristSaveManifest($pdo,42,8,[$updated,$updated],[],true,'hotel'),'your active accepted bookings');
$pdo->exec('UPDATE hotel_room_bookings SET checked_out_at=NULL');
$pdo->exec("CREATE TRIGGER fail_hotel_manifest BEFORE INSERT ON hotel_booking_tourists BEGIN SELECT RAISE(ABORT,'Hotel rollback fixture'); END");
$before=$hotelRows();
expectManifestRejection(fn()=>bookingTouristSaveManifest($pdo,42,8,[$updated,$updated],[],true,'hotel'),'Hotel rollback fixture');
verifyManifestEdit($hotelRows()===$before,'A failed hotel replacement must restore the original guests.');
verifyManifestEdit($saved()===$tourSnapshot,'Hotel errors must never alter the tour guest list.');
echo "PASS: hotel/tour ID isolation, owned hotel loading, accepted/confirmed states, adult+child pax, shared submit/edit/cutoff rules, cancellation domains, duplicate/completion protection, and rollback.\n";
