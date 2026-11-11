<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../php/passenger_manifest_helper.php';
define('K_PATH_CACHE', str_replace('\\', '/', sys_get_temp_dir()) . '/');
require_once __DIR__ . '/../tcpdf/tcpdf.php';
function manifestCheck(bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
}
$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$pdo->exec('CREATE TABLE boats (boat_id INTEGER PRIMARY KEY, name TEXT)');
$pdo->exec('CREATE TABLE booking_resource_boats (booking_id INTEGER, boat_id INTEGER)');
$pdo->exec("INSERT INTO boats VALUES (9,'Primary Vessel'), (2,'Second Vessel'), (6,'Third Vessel')");
$pdo->exec('INSERT INTO booking_resource_boats VALUES (42,6), (42,9), (42,2), (99,6)');
$bookingId = 42;
$booking = ['booking_id'=>42, 'boat_id'=>9, 'booking_type'=>'boat', 'location'=>'Malasugui Island, Apuao Grande Island'];
$manifestBoatNames = passengerManifestBoatNames($pdo, $booking);
manifestCheck($manifestBoatNames === [1=>'Primary Vessel', 2=>'Second Vessel', 3=>'Third Vessel'], 'Primary boat must stay first; allocations must not duplicate it or leak from other bookings.');
$tourists = [];
for ($i=1; $i<=17; $i++) {
    $tourists[] = ['full_name'=>'Fixture Guest ' . $i, 'gender'=>'male', 'age'=>25,
        'address'=>'Purok 5, Barangay 5, Daet, Camarines Norte, Region V (Bicol Region), 4600, Philippines',
        'phone_number'=>'09123456789'];
}
foreach ([1,8,9,16,17] as $count) {
    $pages = passengerManifestPages(array_slice($tourists,0,$count), $booking, $manifestBoatNames);
    manifestCheck(count($pages) === (int)ceil($count/8), 'Incorrect page count at ' . $count . ' guests.');
    manifestCheck(array_sum(array_map(fn($p)=>count($p['passengers']), $pages)) === $count, 'Guests were lost or duplicated.');
    foreach ($pages as $page) manifestCheck(count($page['passengers'])<=8, 'More than eight tourists on a page.');
}
$assigned = array_slice($tourists,0,9);
foreach ($assigned as $i=>&$guest) $guest['boat_number'] = $i===0 ? 2 : 1;
unset($guest);
$pages = passengerManifestPages($assigned, $booking, $manifestBoatNames);
manifestCheck($pages[1]['passengers'][0]['full_name']==='Fixture Guest 1' && count($pages[0]['passengers'])===8,
    'Explicit boat assignments must override original passenger order.');
foreach ($assigned as &$guest) $guest['boat_number']=1;
unset($guest);
manifestCheck(count(passengerManifestPages($assigned,$booking,$manifestBoatNames))===2, 'Legacy oversized groups must still split.');
$pdo->exec('DROP TABLE booking_resource_boats');
manifestCheck(passengerManifestBoatNames($pdo,$booking)===[1=>'Primary Vessel'], 'Legacy primary-only bookings must still render.');
$legacyPages = passengerManifestPages($tourists, $booking, [1=>'Primary Vessel']);
manifestCheck($legacyPages[1]['boat_name']==='', 'Missing allocations must never repeat the primary boat on overflow pages.');

// Render the real endpoint template with synthetic passengers; no auth bypass
// is added to the endpoint and no application records are read or changed.
$source = file_get_contents(__DIR__ . '/../php/tourists_pdf.php');
$start = strpos($source, 'function manifestText(');
$end = strpos($source, '$filename =');
eval(substr($source,$start,$end-$start));
manifestCheck($pdf->getNumPages()===3, 'The actual renderer must output three pages for 17 tourists.');
$output = $pdf->Output('fixture.pdf','S');
ob_end_clean();
manifestCheck(str_starts_with($output,'%PDF-'), 'Invalid PDF output.');
if (!is_dir(__DIR__ . '/../tmp/pdfs')) mkdir(__DIR__ . '/../tmp/pdfs', 0777, true);
file_put_contents(__DIR__ . '/../tmp/pdfs/passenger-manifest-fixture.pdf',$output);
echo "PASS: 1/8/9/16/17 tourists, explicit assignments, unique boat names, legacy data, no lost passengers, and actual three-page PDF rendering.\n";
