<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
// Offline only: real pricing, availability SQL and finalization on in-memory SQLite.
require __DIR__ . '/paymongo_mode_fixture_test.php';
require_once __DIR__ . '/../php/walkin_booking_helper.php';
require_once __DIR__ . '/../payments/BookingCheckoutService.php';

final class CapacityFixturePDO extends PDO
{
    public function __construct() {
        parent::__construct('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
        $this->sqliteCreateFunction('NOW', static fn() => '2026-10-17 00:00:00');
        parent::exec("CREATE TABLE booking_cancellation_requests (booking_domain TEXT, booking_id INT, request_status TEXT)");
    }
    public function exec(string $statement): int|false {
        // Schema bootstrap is outside this test; only the actual reservation SQL is exercised.
        if (str_contains($statement, 'booking_cancellation_requests') && preg_match('/^\s*(CREATE TABLE IF NOT EXISTS|ALTER TABLE)/i', $statement)) return 0;
        return parent::exec($statement);
    }
    public function query(string $query, ?int $fetchMode=null, mixed ...$args): PDOStatement|false {
        if (str_starts_with($query, 'SHOW COLUMNS FROM booking_cancellation_requests')) {
            preg_match_all("/'([a-z_]+)'\s*=>/", file_get_contents(__DIR__ . '/../php/booking_cancellations_helper.php'), $matches);
            $query = implode(' UNION ALL ', array_map(static fn($name) => "SELECT '" . $name . "' AS Field", array_unique($matches[1])));
        } elseif (str_starts_with($query, 'SHOW INDEX')) $query = "SELECT 'idx_cancellation_deadline' AS Key_name";
        elseif (str_starts_with($query, "SHOW TABLES LIKE 'additional_fees'")) $query = "SELECT name FROM sqlite_master WHERE name='additional_fees'";
        return parent::query($query);
    }
}
function capacityReject(callable $work, string $message): void {
    try { $work(); } catch (DomainException|InvalidArgumentException $error) { modeAssert(str_contains($error->getMessage(), $message), $error->getMessage()); return; }
    throw new RuntimeException('Expected rejection: ' . $message);
}
$pdo = new CapacityFixturePDO();
$pdo->exec("CREATE TABLE boats (boat_id INT, name TEXT, total_pax INT);
CREATE TABLE tour_guides (guide_id INT, fullname TEXT);
CREATE TABLE service_prices (service_type TEXT, day_tour_price NUMERIC, overnight_price NUMERIC, is_active INT);
CREATE TABLE additional_fees (fee_code TEXT, category TEXT, fee_label TEXT, amount NUMERIC, unit TEXT, is_active INT);
CREATE TABLE bookings (booking_id INT PRIMARY KEY, boat_id INT, guide_id INT, booking_date TEXT, tour_type TEXT, tour_range TEXT, status TEXT, is_complete TEXT);
CREATE TABLE booking_checkout_drafts (payload TEXT, booking_domain TEXT, status TEXT, expires_at TEXT);
CREATE TABLE booking_resource_boats (booking_id INT, boat_id INT, PRIMARY KEY(booking_id,boat_id));
CREATE TABLE booking_resource_guides (booking_id INT, guide_id INT, PRIMARY KEY(booking_id,guide_id));
INSERT INTO boats VALUES (1,'A',8),(2,'B',8),(3,'Small',4),(4,'Large',12),(5,'C',8);
INSERT INTO tour_guides VALUES (1,'G1'),(2,'G2'),(3,'G3');
INSERT INTO service_prices VALUES ('boat',1000,1700,1),('tourguide',250,400,1);
INSERT INTO additional_fees VALUES ('environmental_local','Environmental','Local',11,'per person',1),('entrance_apuao_grande','Entrance','Apuao Grande Island',20,'per head',1);");
$source = file_get_contents(__DIR__ . '/../payments/create-booking-checkout.php');
$helpersStart = strpos($source, 'function bookingCheckoutText');
$helpersEnd = strpos($source, "if ((" . '$' . "_SERVER", $helpersStart);
eval(substr($source, $helpersStart, $helpersEnd-$helpersStart));
$calculationStart = strpos($source, "        " . '$' . "bookingDate = bookingCheckoutDate");
$calculationEnd = strpos($source, "
    }

    " . '$' . "selectedAddons", $calculationStart);
if ($calculationStart === false || $calculationEnd === false) throw new RuntimeException('Checkout calculation fixture boundaries changed.');
$calculation = substr($source, $calculationStart, $calculationEnd-$calculationStart);
function capacityCheckout(PDO $pdo, array $input, string $domain): array {
    global $calculation;
    $paymentType = 'partial';
    eval($calculation);
    return ['total'=>$total, 'payload'=>$payload];
}
$input = ['bookingDate'=>'2099-01-03','bookingEndDate'=>'','tourType'=>'same-day','contactNumber'=>'09123456789',
    'numAdults'=>'1','numChildren'=>'0','childAges'=>[], 'selectedLocations'=>['Apuao Grande Island'], 'ecoCategory'=>'local',
    'boat_id'=>'1','guide_id'=>'1','additionalBoatIds'=>[],'additionalGuideIds'=>[]];
foreach ([[1,0,1],[5,3,1],[6,3,2],[8,8,2],[10,7,3]] as [$adults,$children,$required]) {
    $data = array_replace($input,['numAdults'=>(string)$adults,'numChildren'=>(string)$children,'childAges'=>array_fill(0,$children,'5'),
        'additionalBoatIds'=>array_slice([2,5],0,$required-1),'additionalGuideIds'=>array_slice([2,3],0,$required-1),'addOnService'=>'tourguide']);
    $quote = capacityCheckout($pdo,$data,'boat');
    $rows=$quote['payload']['price_breakdown']['rows'];
    $guideRows=array_values(array_filter($rows, static fn($row)=>$row['description']==='Tour guide fee'));
    modeAssert(count($guideRows)===1 && $guideRows[0]['quantity']===$required && $guideRows[0]['amount']===(float)(250*$required), 'Boat add-on guide count/rate must follow total pax and be charged exactly once.');
    modeAssert($quote['total'] === (float)(1250*$required+11*$adults+20*($adults+$children)), 'Adult-only environmental fees, all-passenger entrance fees and resources must reconcile.');
    modeAssert(array_sum(array_column($rows,'amount'))===$quote['total'], 'Saved breakdown does not sum to total.');
    $countOnly = $data;
    unset($countOnly['childAges']);
    $countQuote = capacityCheckout($pdo, $countOnly, 'boat');
    modeAssert($countQuote['total'] === $quote['total'], 'A booking without any child-age fields must use the same adult/child counts.');
    modeAssert(!array_key_exists('child_ages', $countQuote['payload']) && !array_key_exists('young_children', $countQuote['payload']) && $countQuote['payload']['entrance_guests'] === $adults+$children, 'A new checkout must record all passengers without an age split.');
    $oldAgeSplit = capacityCheckout($pdo, array_replace($countOnly, ['numYoungChildren'=>9999, 'childAges'=>['0']]), 'boat');
    modeAssert($oldAgeSplit === $countQuote, 'Stale child-age fields changed new checkout pricing.');
    $forged = capacityCheckout($pdo,array_replace($data,['grandTotal'=>1,'paymentAmount'=>1,'environmentalFee'=>0]),'boat');
    modeAssert($forged===$quote, 'Client totals changed authoritative pricing.');
}
foreach ([8=>1,9=>2,17=>3] as $pax=>$required) {
    $guideOnly=capacityCheckout($pdo,array_replace($input,[
        'numAdults'=>'1','numChildren'=>(string)($pax-1),'addOnService'=>'',
        'additionalGuideIds'=>array_slice([2,3],0,$required-1)
    ]),'tourguide');
    modeAssert($guideOnly['total']===(float)(250*$required+11+20*$pax), 'Guide-only booking must require and charge one guide per eight total pax.');
    if ($required>1) capacityReject(fn()=>capacityCheckout($pdo,array_replace($input,[
        'numAdults'=>'1','numChildren'=>(string)($pax-1),'addOnService'=>'','additionalGuideIds'=>[]
    ]),'tourguide'), $required . ' tour guide');
}
$data=array_replace($input,['numAdults'=>'6','numChildren'=>'3','childAges'=>['5','8','12'],'additionalBoatIds'=>[2],'additionalGuideIds'=>[2],'addOnService'=>'boat']);
$guide=capacityCheckout($pdo,$data,'tourguide');
modeAssert($guide['total']===2746.0, 'All children remain in pax and entrance counts while excluded from environmental fees.');
$overnight=capacityCheckout($pdo,array_replace($data,['tourType'=>'overnight','bookingEndDate'=>'2099-01-06']),'tourguide');
modeAssert($overnight['total']===4446.0,'Each guide and boat must use the overnight rate exactly once, not once per night.');
capacityReject(fn()=>capacityCheckout($pdo,array_replace($data,['additionalBoatIds'=>[]]),'tourguide'),'more seats');
capacityReject(fn()=>capacityCheckout($pdo,array_replace($data,['additionalGuideIds'=>[]]),'tourguide'),'2 tour guide');
capacityReject(fn()=>BookingResourceIds(1,[1],'boat'),'only once');
capacityReject(fn()=>BookingResourceIds(1,[2,2],'tour guide'),'only once');
capacityReject(fn()=>BookingValidateBoatCapacity(9,[12]),'more seats');
capacityReject(fn()=>BookingValidateBoatCapacity(13,[8,4]),'more seats');
BookingValidateBoatCapacity(13,[8,4,4]);
capacityReject(fn()=>BookingValidateBoatCapacity(8,[8,8]),'only the additional boats');
capacityReject(fn()=>capacityCheckout($pdo,array_replace($input,['numAdults'=>'1.5']),'boat'),'whole number');

$walkin=['booking_type'=>'tourguide','tour_type'=>'same-day','booking_date'=>'2099-01-03','num_adults'=>'6','num_children'=>'3','child_ages'=>['5','8','12'],
    'guide_id'=>'1','additional_guide_ids'=>[2],'boat_id'=>'1','additional_boat_ids'=>[2],'include_boats'=>'1','locations'=>['Apuao Grande Island'],'eco_category'=>'local'];
modeAssert(WalkinBookingQuote($pdo,$walkin)['total']===$guide['total'],'Admin and tourist totals differ.');
modeAssert(WalkinBookingQuote($pdo,array_replace($walkin,['child_ages'=>[], 'num_young_children'=>'1']))['total'] === $guide['total'], 'Stale admin age-split fields must not affect new quotes.');
capacityReject(fn()=>WalkinBookingQuote($pdo,array_replace($walkin,['additional_guide_ids'=>[]])),'2 tour guide');

$draft=['booking_date'=>'2099-01-03','booking_end_date'=>'2099-01-05','boat_id'=>1,'guide_id'=>1,'additional_boat_ids'=>[2],'additional_guide_ids'=>[2],'resource_allocation_version'=>2];
$pdo->prepare("INSERT INTO booking_checkout_drafts VALUES (?,'boat','pending','2099-01-01')")->execute([json_encode($draft)]);
foreach (['boat','tourguide'] as $type) {
    modeAssert(!tourResourceIsAvailable($pdo,$type,2,'2099-01-05'),'Additional draft allocation did not hold inclusive end date.');
    modeAssert(tourResourceIsAvailable($pdo,$type,2,'2099-01-06'),'Draft allocation blocks dates after the end.');
}
$pdo->exec("UPDATE booking_checkout_drafts SET status='cancelled'");
modeAssert(tourResourceIsAvailable($pdo,'boat',2,'2099-01-03'),'Cancelled draft holds boat.');
$pdo->exec("INSERT INTO bookings VALUES (9,1,1,'2099-01-03','overnight','2099-01-03 to 2099-01-05','accepted','uncomplete'); INSERT INTO booking_resource_boats VALUES (9,2); INSERT INTO booking_resource_guides VALUES (9,2)");
foreach (['boat','tourguide'] as $type) {
    modeAssert(!tourResourceIsAvailable($pdo,$type,2,'2099-01-04'),'Confirmed extra resource was not reserved.');
    modeAssert(tourResourceIsAvailable($pdo,$type,2,'2099-01-04','',9),'Rescheduling must exclude its own booking.');
}
modeAssert(tourBookingAssignedResources($pdo,['booking_id'=>9,'boat_id'=>1,'guide_id'=>1])===['boat'=>[1,2],'tourguide'=>[1,2]],'Rescheduling omitted allocated resources.');
$pdo->exec("UPDATE bookings SET status='cancelled' WHERE booking_id=9");
modeAssert(tourResourceIsAvailable($pdo,'tourguide',2,'2099-01-03'),'Cancelled booking holds extra guide.');

// The operator endpoint ignores a forged environmental amount.
$operatorSource=file_get_contents(__DIR__ . '/../operator/opbookings.php');
$operatorStart=strpos($operatorSource, '        $expenseAmounts = [');
$operatorEnd=strpos($operatorSource, '        $serviceAmount =', $operatorStart);
$operatorCalculation=substr($operatorSource,$operatorStart,$operatorEnd-$operatorStart);
$adults=6; $opEnvironmentalRates=['local'=>11]; $savedPost=$_POST;
$_POST=['eco_category'=>'local','expense_environmental'=>'9999','expense_entrance'=>'0','expense_docking'=>'0','expense_other'=>'0'];
eval($operatorCalculation);
modeAssert($expenseAmounts['Environmental Fee']===66.0,'Operator trusted a client environmental amount.');
$_POST=$savedPost;

// Execute real paid-draft finalization twice to verify atomic, idempotent allocations.
$paidPdo=modeDatabase();
$paidPdo->exec('CREATE TABLE booking_resource_boats (booking_id INT, boat_id INT, PRIMARY KEY(booking_id,boat_id)); CREATE TABLE booking_resource_guides (booking_id INT, guide_id INT, PRIMARY KEY(booking_id,guide_id))');
$payload=$guide['payload']; $paid=round($guide['total']*0.20,2);
$paidPdo->prepare("INSERT INTO booking_checkout_drafts (booking_draft_id,tourist_id,booking_domain,payload,total_minor,amount_minor,payment_type,status) VALUES (1,7,'tourguide',?,?,?,'partial','pending')")->execute([json_encode($payload),(int)round($guide['total']*100),(int)round($paid*100)]);
$paidPdo->beginTransaction();
$first=BookingCheckoutService::submitPaidDraft($paidPdo,1,7,$paid,'qrph');
$second=BookingCheckoutService::submitPaidDraft($paidPdo,1,7,$paid,'qrph');
$paidPdo->commit();
modeAssert(!$first['idempotent'] && $second['idempotent'],'Payment replay not idempotent.');
modeAssert((int)$paidPdo->query('SELECT COUNT(*) FROM booking_resource_boats')->fetchColumn()===2,'Finalization lost or repeated boat assignments.');
modeAssert((int)$paidPdo->query('SELECT COUNT(*) FROM booking_resource_guides')->fetchColumn()===2,'Finalization lost or repeated guide assignments.');
$booking=$paidPdo->query('SELECT * FROM bookings')->fetch(PDO::FETCH_ASSOC);
modeAssert(abs((float)$booking['remaining_balance']-($guide['total']-$paid))<0.009,'20% deposit/balance changed.');
echo "PASS: capacity/pricing, adult-only configured fees, admin/tourist parity, overnight rates, tampered totals, draft/confirmed conflicts, cancellation, reschedule allocation lookup, and paid-draft replay.
";
