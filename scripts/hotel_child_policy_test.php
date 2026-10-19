<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../php/hotel_child_policy.php';

function auditRoom(int $adults = 2, int $children = 2, ?int $maximum = null, float $price = 1500): array {
    return ['id'=>1, 'hotel_resort_id'=>1, 'room_name'=>'Fixture room', 'status'=>'active', 'price'=>$price,
        'capacity_adults'=>$adults, 'capacity_children'=>0,
        'child_policy_approval'=>['hotel_room_id'=>1, 'adult_capacity'=>$adults, 'max_children'=>$children,
            'max_occupancy'=>$maximum ?? $adults+$children, 'approved_at'=>'2026-10-20 00:00:00']];
}
if (($argv[1] ?? '') === '--parity') {
    $cases=[];
    foreach (range(2,6) as $adults) foreach (range(0,3) as $children) foreach ([1,2,3] as $nights) {
        if ($adults===2 && $children>2) continue;
        $room=auditRoom($adults, $adults===2?2:3, null, 1500.55);
        $ages=array_slice([5,6,17],0,$children);
        $cases[]=['policy'=>HoChildRoomPolicy($room),'adults'=>$adults,'children'=>$children,'ages'=>$ages,'nights'=>$nights,'rate'=>$room['price'],'quote'=>HoChildQuote($room,$adults,$children,$ages,$nights)];
    }
    echo json_encode($cases,JSON_THROW_ON_ERROR); exit;
}
$childChecks=0;
function childCheck(bool $result,string $message): void { global $childChecks; if(!$result)throw new RuntimeException($message);$childChecks++; }
function childReject(callable $action,string $message): void { try{$action();}catch(InvalidArgumentException|DomainException $error){childCheck(true,$message);return;}throw new RuntimeException($message); }
$room=auditRoom();
foreach ([0,1,5,6,10,17] as $age) childCheck(HoChildQuote($room,2,1,[$age],2)['child_fee_total']===($age<=5?0:500),'Age boundary '.$age);
foreach ([18,-1,'wrong','',null,6.5,true] as $age) childReject(fn()=>HoChildQuote($room,2,1,[$age],2),'Reject invalid age');
childReject(fn()=>HoChildQuote($room,2,1,[],2),'Missing age');
childReject(fn()=>HoChildQuote($room,2,1,[5,6],2),'Too many ages');
childReject(fn()=>HoChildQuote($room,2,1,['age'=>5,'extra'=>6],2),'Invalid count');
foreach(range(2,6) as $adults){
    $allowed=$adults===2?2:3;$current=auditRoom($adults,$allowed);
    foreach(range(0,4) as $count){$ages=array_fill(0,$count,6);if($count<=$allowed)childCheck(HoChildQuote($current,$adults,$count,$ages,2)['children']===$count,'Approved combination');else childReject(fn()=>HoChildQuote($current,$adults,$count,$ages,2),'Reject child overflow');}
    childReject(fn()=>HoChildQuote($current,$adults+1,0,[],1),'Separate adult limit');
}
childReject(fn()=>HoChildQuote(auditRoom(4,3,5),4,2,[1,2],1),'Total includes all free children');
childReject(fn()=>HoChildQuote($room+['unused'=>1],2,0,[],0),'Invalid nights');
$inactive=$room;$inactive['status']='archived';childReject(fn()=>HoChildQuote($inactive,2,0,[],1),'Inactive room');
foreach([1,7] as $adults){$legacy=['capacity_adults'=>$adults,'capacity_children'=>1,'price'=>1500];childCheck(HoChildRoomPolicy($legacy)['child_allowance']===1,'Preserve nonstandard configured allowance');}
childReject(fn()=>HoChildQuote(['capacity_adults'=>2,'capacity_children'=>5,'price'=>1500],2,3,[1,2,3],1),'Standard child limit does not expand for legacy combined capacities');
$legacy=['capacity_adults'=>2,'capacity_children'=>0,'price'=>1500];childReject(fn()=>HoChildQuote($legacy,2,1,[3],1),'Unapproved occupancy cannot expand');
$stale=$room;$stale['capacity_adults']=3;childCheck(!HoChildRoomPolicy($stale)['explicitly_approved'],'Changed adult capacity invalidates stale approval');
$q1=HoChildQuote($room,2,1,[4],2);$q2=HoChildQuote($room,2,2,[4,9],2);$q3=HoChildQuote(auditRoom(4,3,null,2500),4,3,[6,10,5],3);
childCheck($q1['total_before_addons']===3000.0,'Example 1');childCheck($q2['total_before_addons']===3500.0,'Example 2');childCheck($q3['total_before_addons']===9000.0,'Example 3');
childCheck(round($q2['total_before_addons']*.2,2)===700.0 && $q2['total_before_addons']-700===2800.0,'20 percent deposit');
childCheck($q2['total_before_addons']+400===3900.0,'Add-on once per reservation');childCheck(!$q2['bedding_included'],'No automatic bedding');
childReject(fn()=>HoChildCheckClientTotal(['child_fee_total'=>1],$q2),'Forged child fee');
childReject(fn()=>HoChildCheckSubmittedTotal(['total'=>1],3500),'Forged total');

$pdo=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
childReject(fn()=>HoChildRequireStorage($pdo),'Missing migration fails before payment');
childCheck((int)$pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table'")->fetchColumn()===0,'No runtime migration');
$pdo->exec('CREATE TABLE hotel_room_child_policies (hotel_room_id INTEGER PRIMARY KEY,adult_capacity INT,max_children INT,max_occupancy INT,approved_by INT,approved_at TEXT DEFAULT CURRENT_TIMESTAMP);
CREATE TABLE hotel_booking_child_policies (hotel_booking_id INTEGER PRIMARY KEY,policy_version TEXT,snapshot_json TEXT,created_at TEXT DEFAULT CURRENT_TIMESTAMP);
CREATE TABLE legacy_financial_records (id INT,amount NUMERIC); INSERT INTO legacy_financial_records VALUES(1,1234.56)');
$before=$pdo->query('SELECT * FROM legacy_financial_records')->fetchAll();
childCheck(HoChildApprovalInput($pdo,2,0,[])===null,'No checkbox means no approval');
$approval=HoChildApprovalInput($pdo,2,0,['approve_child_occupancy'=>'1','policy_max_children'=>'2','policy_max_occupancy'=>'4']);
HoChildSaveApproval($pdo,1,7,$approval);$attached=HoChildAttachRooms($pdo,[$legacy+['id'=>1]])[0];childCheck(HoChildRoomPolicy($attached)['max_occupancy']===4,'Explicit approval activates occupancy');
childReject(fn()=>HoChildApprovalInput($pdo,2,0,['approve_child_occupancy'=>'1','policy_max_children'=>3,'policy_max_occupancy'=>5]),'Couple child allowance capped at two');
HoChildSaveSnapshot($pdo,1,$q2);$saved=HoChildSnapshot($pdo,1);childCheck($saved['child_ages']===[4,9] && $saved['child_fee_total']===500,'Ages and price round trip');
HoChildSaveApproval($pdo,1,7,['adult_capacity'=>2,'max_children'=>0,'max_occupancy'=>2]);childCheck(HoChildSnapshot($pdo,1)===$saved,'Later room edits do not change snapshots');
childCheck(HoChildSnapshotValid(['adults'=>2,'children'=>2],$saved),'Saved terms work at check-in');childCheck(!HoChildSnapshotValid(['adults'=>3,'children'=>2],$saved),'Edited guest counts rejected');
childCheck(HoChildSnapshot($pdo,999)===null,'Legacy booking has no inferred new charge');childCheck($before===$pdo->query('SELECT * FROM legacy_financial_records')->fetchAll(),'Historical amounts untouched');

// Existing offline payment fixtures use real SQL on SQLite; never application DB/HTTP.
require __DIR__ . '/paymongo_mode_fixture_test.php';
$paymentDb=modeDatabase();
$paymentDb->exec('CREATE TABLE hotel_room_child_policies (hotel_room_id INTEGER PRIMARY KEY,adult_capacity INT,max_children INT,max_occupancy INT,approved_by INT,approved_at TEXT);
CREATE TABLE hotel_booking_child_policies (hotel_booking_id INTEGER PRIMARY KEY,policy_version TEXT,snapshot_json TEXT)');
$payload=['hotel_resort_id'=>1,'hotel_room_id'=>1,'room_type'=>'Couple','checkin_date'=>'2099-01-01','checkout_date'=>'2099-01-03','nights'=>2,'adults'=>2,'children'=>2,'first_name'=>'Fixture','last_name'=>'Guest','email'=>'fixture@example.invalid','phone_number'=>'09123456789','special_request'=>'','unit_price'=>1500,'hotel_child_policy'=>$q2,'child_ages'=>[4,9]];
$insert=$paymentDb->prepare("INSERT INTO booking_checkout_drafts (booking_draft_id,tourist_id,booking_domain,payload,total_minor,amount_minor,payment_type,status) VALUES(100,7,'hotel',?,350000,70000,'partial','pending')");$insert->execute([json_encode($payload)]);
$paymentDb->beginTransaction();$submitted=BookingCheckoutService::submitPaidDraft($paymentDb,100,7,700,'fixture');$paymentDb->commit();
$booking=$paymentDb->query('SELECT * FROM hotel_room_bookings WHERE hotel_booking_id='.(int)$submitted['booking_id'])->fetch(PDO::FETCH_ASSOC);
childCheck((float)$booking['total_amount']===3500.0 && (float)$booking['remaining_balance']===2800.0,'Verified draft total/deposit/balance');
childCheck(HoChildSnapshot($paymentDb,(int)$submitted['booking_id'])['child_fee_total']===500,'Verified conversion saves child snapshot');
childCheck(BookingCheckoutService::submitPaidDraft($paymentDb,100,7,700,'fixture')['idempotent'],'Repeated verified event is idempotent');
childCheck((int)$paymentDb->query('SELECT COUNT(*) FROM hotel_booking_child_policies')->fetchColumn()===1,'No duplicate child charge/snapshot');
// A late verified callback keeps its original stored quote; it is never repriced
// from today's room policy. This does not simulate a competing paid reservation.
$payload['hotel_child_policy']=$q1;$payload['children']=1;$payload['child_ages']=[4];
$insert=$paymentDb->prepare("INSERT INTO booking_checkout_drafts (booking_draft_id,tourist_id,booking_domain,payload,total_minor,amount_minor,payment_type,status) VALUES(101,7,'hotel',?,300000,300000,'full','cancelled')");
$insert->execute([json_encode($payload)]);
$paymentDb->beginTransaction();$late=BookingCheckoutService::submitPaidDraft($paymentDb,101,7,3000,'fixture');$paymentDb->commit();
childCheck(HoChildSnapshot($paymentDb,(int)$late['booking_id'])['child_fee_total']===0,'Late verified callback preserves saved child quote');
require_once __DIR__.'/../php/booking_cancellations_helper.php';
$requested=new DateTimeImmutable('2099-01-01',new DateTimeZone('Asia/Manila'));
childCheck(abs(bookingCancellationRefundPolicy('2099-01-02',3500,3500,$requested)['refundable_amount']-2800)<.009,'Late refund uses stored total with child accommodation');
childCheck(abs(bookingCancellationRefundPolicy('2099-01-02',3500,700,$requested)['refundable_amount'])<.009,'Late deposit remains nonrefundable');
childCheck(abs(bookingCancellationRefundPolicy('2099-01-04',3500,700,$requested)['refundable_amount']-700)<.009,'Early cancellation refunds original amount paid');
echo "PASS: {$childChecks} hotel child-policy functional checks; isolated SQLite only.\n";
