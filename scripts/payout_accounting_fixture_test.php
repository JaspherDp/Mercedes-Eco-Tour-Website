<?php
declare(strict_types=1);
// Offline only. No application DB bootstrap, private keys, payments or refunds.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../php/payout_accounting.php';
require_once __DIR__ . '/../php/payout_fee_sync.php';
$checks = 0;
function check(bool $ok, string $message): void {
    global $checks;
    if (!$ok) throw new RuntimeException($message);
    $checks++;
}
function payment(int $gross, ?int $fee, string $id = 'pay_fixture'): array {
    $t = ['payment_transaction_id'=>1,'provider'=>'paymongo','provider_payment_id'=>$id,'amount_minor'=>$gross,'currency'=>'PHP','status'=>'paid','metadata'=>'{}'];
    if ($fee !== null) $t['metadata'] = json_encode(['source'=>'booking_checkout','paymongo_accounting'=>PayoutAccounting::extract($t, resource($gross, $fee, $id), false)]);
    return $t;
}
function resource(int $gross, int $fee, string $id = 'pay_fixture'): array {
    return ['id'=>$id,'type'=>'payment','attributes'=>['status'=>'paid','amount'=>$gross,'fee'=>$fee,'net_amount'=>$gross-$fee,'currency'=>'PHP','livemode'=>false]];
}
$full = payment(500000,15000);
$a = PayoutAccounting::calculate([$full],[],500000);
check($a['gross_minor']===500000 && $a['fee_minor']===15000 && $a['net_minor']===485000,'Full payment');
$deposit = payment(200000,6000,'pay_deposit'); $balance = payment(800000,24000,'pay_balance');
$a = PayoutAccounting::calculate([$deposit],[],200000);
check($a['net_minor']===194000,'Downpayment only');
$a = PayoutAccounting::calculate([$deposit,$balance],[],1000000);
check($a['gross_minor']===1000000 && $a['fee_minor']===30000 && $a['net_minor']===970000,'Multiple payments aggregate individual fees');
foreach (['failed','cancelled','pending','expired','abandoned'] as $status) {
    $t = $full; $t['status'] = $status;
    $a = PayoutAccounting::calculate([$t,$deposit],[],200000);
    check($a['gross_minor']===200000 && $a['fee_minor']===6000 && $a['net_minor']===194000,"Exclude $status");
}
$refund = ['status'=>'succeeded','amount_minor'=>500000,'currency'=>'PHP'];
$a = PayoutAccounting::calculate([$full],[$refund],500000);
check($a['gross_minor']===500000 && $a['refund_minor']===500000 && $a['net_minor']===0,'Fully refunded money not payable; gross preserved');
$refund['amount_minor']=100000;
$a = PayoutAccounting::calculate([$full],[$refund],500000);
check($a['net_minor']===385000,'Partial refund reduces net exactly once');
$manual=$refund; $manual['provider']='manual'; $manual['payment_transaction_id']=null;
$a=PayoutAccounting::calculate([$full],[$manual],500000);
check($a['net_minor']===385000,'Unlinked manual / QR Ph fallback refund counted by booking');
$refund['status']='pending';
$a=PayoutAccounting::calculate([$full],[$refund],500000);
check($a['active_refund'] && $a['blocked_reason']!=='' && $a['refund_minor']===0,'Pending refund holds settlement');
$refund['status']='failed';
$a=PayoutAccounting::calculate([$full],[$refund],500000);
check($a['net_minor']===485000 && !$a['active_refund'],'Failed refund not deducted');
$refund['status']='succeeded'; $refund['amount_minor']=400000;
$a=PayoutAccounting::calculate([$full],[$refund],500000,100000);
check($a['net_minor']===85000,'Cancellation retained amount deducts gateway fee once');
$unknown=payment(500000,null);
$a=PayoutAccounting::calculate([$unknown],[],500000);
check($a['fee_minor']===null && $a['net_minor']===null && $a['blocked_reason']!=='','Historical missing fee stays unknown');
$a=PayoutAccounting::calculate([$full],[],600000);
check($a['gross_minor']===600000 && $a['net_minor']===null,'Unlinked historical receipts held for review');
$cash=$full; $cash['provider']='cash'; $cash['metadata']='{}';
check(PayoutAccounting::calculate([$cash],[],500000)['net_minor']===500000,'Cash has no PayMongo fee');
$mixed=PayoutAccounting::calculate([$cash,$full],[],1000000);
check($mixed['fee_minor']===15000 && $mixed['net_minor']===985000,'Cash and online payments');
check(PayoutAccounting::minor('0.01')===1 && PayoutAccounting::minor('1000.10')===100010 && PayoutAccounting::decimal(100010)==='1000.10','Exact centavo conversion');
check(PayoutAccounting::calculate([payment(10001,301)],[],10001)['net_minor']===9700,'Odd centavos');
check(PayoutAccounting::calculate([payment(100,0)],[],100)['fee_minor']===0,'Authoritative zero fee is known');
foreach (['fee','net_amount','amount','currency','livemode','status'] as $field) {
    $bad=resource(500000,15000);$bad['attributes'][$field]=null;
    try {PayoutAccounting::extract($full,$bad,false);throw new RuntimeException('Accepted invalid '.$field);}
    catch(UnexpectedValueException $expected){check(true,'Reject invalid resource '.$field);}
}
$bad=resource(500000,15000,'pay_other');
try {PayoutAccounting::extract($full,$bad,false);throw new RuntimeException('Accepted mismatched payment');}
catch(UnexpectedValueException $expected){check(true,'Reject mismatched payment');}

final class AccountingFixturePDO extends PDO {
    public function __construct(){parent::__construct('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);}
    public function prepare(string $query,array $options=[]): PDOStatement|false {
        if(str_contains($query,'information_schema.tables'))$query="SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name=?";
        $query=str_replace(' FOR UPDATE','',$query);
        return parent::prepare($query,$options);
    }
}
$pdo=new AccountingFixturePDO();
$pdo->exec('CREATE TABLE payment_transactions (payment_transaction_id INTEGER PRIMARY KEY,booking_domain TEXT,booking_id INTEGER,provider TEXT,provider_payment_id TEXT,amount_minor INTEGER,currency TEXT,status TEXT,metadata TEXT)');
$pdo->exec('CREATE TABLE booking_refunds (booking_domain TEXT,booking_id INTEGER,amount_minor INTEGER,currency TEXT,status TEXT)');
$insert=$pdo->prepare("INSERT INTO payment_transactions VALUES (?,?,?,?,?,?,?,?,?)");
$insert->execute([1,'package',1,'paymongo','pay_fixture',500000,'PHP','paid','{"source":"booking_checkout","unrelated":"preserve"}']);
PayoutAccounting::capture($pdo,$full,resource(500000,15000),false);
$stored=$pdo->query('SELECT * FROM payment_transactions')->fetch();
check((int)$stored['amount_minor']===500000 && PayoutAccounting::metadata($stored)['unrelated']==='preserve' && PayoutAccounting::fee($stored)===15000,'Fee enrichment preserves gross and unrelated metadata');
PayoutAccounting::capture($pdo,$full,resource(500000,15000),false);
check((int)$pdo->query('SELECT COUNT(*) FROM payment_transactions')->fetchColumn()===1 && PayoutAccounting::fee($pdo->query('SELECT * FROM payment_transactions')->fetch())===15000,'Duplicate fee capture does not duplicate charges');
$insert->execute([2,'hotel',1,'cash',null,100000,'PHP','paid','{}']);
$pdo->exec("INSERT INTO booking_refunds VALUES ('tour',1,100000,'PHP','succeeded')");
$ledger=PayoutAccounting::load($pdo,[['booking_domain'=>'package','booking_id'=>1],['booking_domain'=>'hotel','booking_id'=>1]],true);
$tour=PayoutAccounting::forBooking($ledger,'package',1,'5000.00');$hotel=PayoutAccounting::forBooking($ledger,'hotel',1,'1000.00');
check($tour['net_minor']===385000 && $hotel['net_minor']===100000,'Hotel and tour IDs stay isolated and tour refunds map correctly');
check($tour['net_minor']+$hotel['net_minor']===485000,'Multiple bookings sum correctly');
$settlement=PayoutAccounting::settlementAmounts(['status'=>'pending'],PayoutAccounting::calculate([$full],[],500000));
check($settlement===['5000.00','150.00','0.00','4850.00'],'Settlement snapshots gross, fee, refunds and actual net separately');
check(PayoutAccounting::recordedSettlement(['gross_amount'=>'5000.00','net_amount'=>'4850.00'])===485000,'New settled payout uses recorded net without double deduction');
check(PayoutAccounting::recordedSettlement(['gross_amount'=>'5000.00','net_amount'=>null])===500000,'Legacy settlement amount preserved');
foreach ([['status'=>'settled'],['status'=>'void']] as $state) {
    try {PayoutAccounting::settlementAmounts($state,$tour);throw new LogicException('Duplicate settlement accepted');}
    catch(RuntimeException $expected){check(true,'Settled/void payout cannot be settled again');}
}
try {PayoutAccounting::settlementAmounts(['status'=>'pending'],PayoutAccounting::calculate([$unknown],[],500000));throw new LogicException('Unknown fee allowed');}
catch(RuntimeException $expected){check(true,'Unknown fee cannot settle');}
// A failed optional enrichment must not escape into the payment confirmation path.
PayoutAccounting::captureOptional($pdo,$full,resource(500000,15000,'pay_wrong'),false);
check(true,'Optional enrichment failure does not fail confirmation');
$summary=PayoutAccounting::summary([['accounting'=>$tour],['accounting'=>PayoutAccounting::calculate([$unknown],[],500000)]]);
check(str_contains($summary,'Known net payout subtotal') && str_contains($summary,'unavailable'),'Unknown amounts clearly excluded from summaries');
check(PayoutAccounting::totalLabel(0,1)==='Pending review','Unknown total never displayed as zero');
check(PayoutAccounting::totalLabel(4850,1)==='Pending review','Incomplete totals use a plain review label');
$foreign=resource(100000,3000);$foreign['attributes']['foreign_fee']=1000;$foreign['attributes']['net_amount']=96000;
$captured=PayoutAccounting::extract(payment(100000,null),$foreign,false);
check($captured['fee_minor']===4000 && $captured['processing_fee_minor']===3000 && $captured['foreign_fee_minor']===1000,'Actual foreign card fee reconciles provider net');
check(PayoutAccounting::calculate([$unknown],[],500000,0)['net_minor']===0,'Excluded booking has no payable income even while fee unavailable');
check(PayoutFeeSync::modeMatches(['metadata'=>'{}'],true),'Untagged historical live payment may be retrieved');
check(!PayoutFeeSync::modeMatches(['metadata'=>'{"paymongo_mode":"test"}'],true),'Explicit other-mode payment not fetched');
$pdo->sqliteCreateFunction('JSON_UNQUOTE',static fn($v)=>$v);
$pdo->exec('CREATE TABLE bookings (booking_id INTEGER,operator_id INTEGER)');
$pdo->exec('CREATE TABLE hotel_room_bookings (hotel_booking_id INTEGER,hotel_resort_id INTEGER)');
$pdo->exec('INSERT INTO bookings VALUES (101,10),(102,20)');
$pdo->exec('INSERT INTO hotel_room_bookings VALUES (103,30),(104,40)');
foreach([101=>'package',102=>'package',103=>'hotel',104=>'hotel'] as $id=>$domain)$insert->execute([$id,$domain,$id,'paymongo','pay_sync'.$id,100000,'PHP','paid','{}']);
$remote=new class {
    public int $calls=0;
    public bool $fail=false;
    public function isLiveMode():bool{return false;}
    public function retrievePayment(string $id):array{$this->calls++;if($this->fail)throw new RuntimeException('Fixture outage');return ['data'=>resource(100000,3000,$id)];}
};
check(PayoutFeeSync::batch($pdo,'operator',10,$remote)['updated']===1,'Operator automatically retrieves owned historical fee');
check(PayoutFeeSync::batch($pdo,'operator',10,$remote)['more']===false && $remote->calls===1,'Known fees cached and other operators excluded');
check(PayoutFeeSync::batch($pdo,'hotel',30,$remote)['updated']===1,'Hotel automatically retrieves owned fee');
check(PayoutFeeSync::batch($pdo,'hotel',30,$remote)['more']===false,'Other hotel excluded');
$remote->fail=true;
check(PayoutFeeSync::batch($pdo,'operator',20,$remote)['updated']===0,'Provider failure remains unresolved');
$calls=$remote->calls;
check(PayoutFeeSync::batch($pdo,'operator',20,$remote)['more']===false && $remote->calls===$calls,'Failed fee retrieval throttled across requests');
echo "Passed $checks accounting checks (offline SQLite and monetary fixtures).\n";
