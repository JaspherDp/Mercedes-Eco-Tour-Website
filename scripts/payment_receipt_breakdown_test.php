<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../php/payment_receipt_breakdown.php';
require_once __DIR__ . '/../php/input_validation.php';
function receiptCheck(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('CREATE TABLE booking_checkout_drafts (booking_draft_id INTEGER PRIMARY KEY, booking_id INTEGER, booking_domain TEXT, status TEXT, total_minor INTEGER, payload TEXT, created_at TEXT)');
$pdo->exec('CREATE TABLE tour_packages (package_id INTEGER, operator_id INTEGER, package_title TEXT, price DECIMAL, created_at TEXT, updated_at TEXT)');
$pdo->exec('CREATE TABLE service_prices (service_type TEXT, day_tour_price DECIMAL, overnight_price DECIMAL, is_active INTEGER, created_at TEXT, updated_at TEXT)');
$pdo->exec('CREATE TABLE additional_fees (fee_code TEXT, amount DECIMAL, is_active INTEGER, created_at TEXT, updated_at TEXT)');
$pdo->exec("INSERT INTO tour_packages VALUES (1,1,'Explore Mercedes',1599,'2026-01-01','2026-09-12')");
$pdo->exec("INSERT INTO service_prices VALUES ('boat',2500,3500,1,'2026-01-01','2026-10-02'),('tourguide',700,1400,1,'2026-01-01','2026-10-02')");
$pdo->exec("INSERT INTO additional_fees VALUES ('environmental_local',50,1,'2026-01-01','2026-10-02'),('entrance_apuao_grande',30,1,'2026-01-01','2026-10-02'),('docking_malasugui',300,1,'2026-01-01','2026-10-02')");
$package = ['booking_id'=>25,'booking_type'=>'package','package_name'=>'Explore Mercedes','operator_id'=>1];
$payload = ['package_id'=>1,'package_name'=>'Explore Mercedes','num_adults'=>1,'num_children'=>0,'location'=>'','tour_type'=>'same-day'];
$insert=$pdo->prepare("INSERT INTO booking_checkout_drafts VALUES (?,?,?,?,?,?,?)");
$insert->execute([1,25,'package','submitted',164900,json_encode($payload),'2026-09-25']);
$result=PaymentReceiptTourBreakdown($pdo,$package,1649);
receiptCheck(count($result['rows'])===2 && (float)$result['rows'][0]['amount']===1599.0 && (float)$result['rows'][1]['amount']===50.0,'Package fare and environmental remainder must be separated.');
$boat=['booking_id'=>29,'booking_type'=>'boat','package_name'=>''];
$payload=['num_adults'=>2,'num_children'=>0,'child_ages'=>[],'location'=>'Malasugui Island,Apuao Grande Island','tour_type'=>'same-day','preferred_resource'=>'Preferred Boat: Test | Add-on: Tour Guide'];
$insert->execute([2,29,'boat','submitted',366000,json_encode($payload),'2026-10-15']);
$result=PaymentReceiptTourBreakdown($pdo,$boat,3660);
receiptCheck(count($result['rows'])===5 && abs(array_sum(array_column($result['rows'],'amount'))-3660)<0.009,'Boat, guide, environmental, entrance and docking amounts must reconcile.');
$savedRows=$result['rows'];
$adjusted=PaymentReceiptTourBreakdown($pdo,$boat,3760);
receiptCheck(end($adjusted['rows'])['amount']===100.0,'Recorded reservation adjustments must be explicit.');
$pdo->exec("UPDATE service_prices SET day_tour_price=9999, updated_at='2026-12-01'");
$result=PaymentReceiptTourBreakdown($pdo,$boat,3660);
receiptCheck(count($result['rows'])===1 && str_contains($result['note'],'not saved'),'Changed current rates must not be reported as historical rates.');
$payload['price_breakdown']=['version'=>1,'rows'=>$savedRows];
$pdo->prepare('UPDATE booking_checkout_drafts SET payload=? WHERE booking_id=29')->execute([json_encode($payload)]);
$result=PaymentReceiptTourBreakdown($pdo,$boat,3660);
receiptCheck($result['rows']==$savedRows && $result['note']==='','Saved price snapshots must survive rate changes.');
$walkin=['booking_id'=>99,'booking_type'=>'boat','preferred_resource'=>'Walk-in booking'];
$result=PaymentReceiptTourBreakdown($pdo,$walkin,2500);
receiptCheck($result['rows'][0]['description']==='Boat hire' && $result['rows'][0]['amount']===2500.0,'Walk-in fees must not be counted again in the base charge.');

// Exercise the actual checkout snapshot calculation without creating payments.
function getAdditionalFees(PDO $pdo): array { return []; }
function additionalFeesForBooking(array $fees): array { return ['environmental'=>['local'=>50], 'entrance'=>['Apuao Grande Island'=>30,'Canimog Island'=>['day'=>100,'overnight'=>250]], 'docking'=>['Malasugui Island'=>300]]; }
$source=file_get_contents(__DIR__.'/../payments/create-booking-checkout.php');
$textStart=strpos($source,'function bookingCheckoutText(');
$textEnd=strpos($source,'function bookingCheckoutDate(',$textStart);
eval(substr($source,$textStart,$textEnd-$textStart));
$start=strpos($source,'        $priceRows = [];');
$end=strpos($source,'        $rawResourceId =',$start);
$domain='boat';$addOn='tourguide';$tourType='same-day';$rates=['boat'=>2500,'tourguide'=>700];$requiredBoats=1;$guestCount=3;$adults=2;$children=1;$rateKey='day';$serviceTotal=3200;$locations=['Malasugui Island','Apuao Grande Island'];$input=['ecoCategory'=>'local','childAges'=>[5]];
eval(substr($source,$start,$end-$start));
receiptCheck($total===3660.0 && abs(array_sum(array_column($priceRows,'amount'))-$total)<0.009,'Future checkout snapshots must equal server pricing and honor child entrance exemptions.');
echo "PASS: package itemization, boat/guide/entrance/docking/environmental charges, retained snapshots, changed-rate protection, adjustments, walk-in accounting, and checkout child exemptions.\n";
