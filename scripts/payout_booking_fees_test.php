<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../php/payout_accounting.php';
$checks=0;
function feeCheck(bool $ok,string $message):void { global $checks;if(!$ok)throw new RuntimeException($message);$checks++; }
$fees=['categories'=>['environmental'=>10000,'entrance'=>6000,'docking'=>30000,'other'=>4000],'total_minor'=>50000,'booking_total_minor'=>200000,'verified'=>true];
function baseAccounting(int $gross,int $gateway,int $refund=0,?int $cap=null):array {
 return PayoutAccounting::calculate([['provider'=>'cash','currency'=>'PHP','status'=>'paid','amount_minor'=>$gross]],$refund?[['status'=>'succeeded','currency'=>'PHP','amount_minor'=>$refund]]:[],$gross,$cap)+[];
}
$a=baseAccounting(200000,0);$a['fee_minor']=3000;$a['net_minor']-=3000;
$a=PayoutBookingFees::apply($a,$fees,null);
feeCheck($a['net_minor']===147000 && $a['booking_fee_total_minor']===50000,'Full payment excludes third-party charges and processing fee once');
$a=PayoutBookingFees::apply(baseAccounting(40000,0),$fees,null);
feeCheck($a['booking_fee_total_minor']===10000 && $a['net_minor']===30000,'Deposit allocates only its share of fees');
$a=PayoutBookingFees::apply(baseAccounting(200000,0,100000),$fees,null);
feeCheck($a['booking_fee_total_minor']===25000 && $a['net_minor']===75000,'Partial refund releases proportional fees');
$a=PayoutBookingFees::apply(baseAccounting(200000,0,200000),$fees,null);
feeCheck($a['net_minor']===0 && $a['booking_fee_total_minor']===0,'Full refund has no fee reserve');
$a=PayoutBookingFees::apply(baseAccounting(200000,0,160000,40000),$fees,40000);
feeCheck($a['net_minor']===30000 && $a['booking_fee_total_minor']===10000,'Retention applies fee allocation to retained amount');
$unverified=$fees;$unverified['verified']=false;
$a=PayoutBookingFees::apply(baseAccounting(200000,0),$unverified,null);
feeCheck($a['net_minor']===150000 && $a['blocked_reason']==='' && $a['booking_fee_total_minor']===50000,'Incomplete itemization still deducts recorded fees without holding settlement');
$missing=$unverified;$missing['categories']=array_fill_keys(array_keys($fees['categories']),0);$missing['total_minor']=0;
$a=PayoutBookingFees::apply(baseAccounting(200000,0),$missing,null);
feeCheck($a['net_minor']===200000 && $a['blocked_reason']==='','Missing fee history is not estimated or used as a settlement hold');
$invalid=$fees;$invalid['valid']=false;
$a=PayoutBookingFees::apply(baseAccounting(200000,0),$invalid,null);
feeCheck($a['net_minor']===null && $a['blocked_reason']!=='','Inconsistent amounts remain protected');
$a=PayoutBookingFees::apply(baseAccounting(200000,0,0,0),$unverified,0);
feeCheck($a['net_minor']===0,'Excluded bookings remain nonpayable');
foreach(['Environmental Fee'=>'environmental','Entrance fee — Island'=>'entrance','Docking / Landing Fee'=>'docking','Other fees'=>'other','Food'=>null,'Additional Boat'=>null,'others'=>null] as $label=>$category)feeCheck(PayoutBookingFees::category($label)===$category,'Classify '.$label);
$odd=['categories'=>['environmental'=>1,'entrance'=>1,'docking'=>1,'other'=>1],'total_minor'=>4,'booking_total_minor'=>7,'verified'=>true];
$a=PayoutBookingFees::apply(baseAccounting(4,0),$odd,null);
feeCheck(array_sum($a['booking_fees_minor'])===2 && $a['net_minor']===2,'Fractional allocations reconcile to the centavo');
feeCheck(PayoutAccounting::recordedSettlement(['net_amount'=>'123.45'])===12345,'Recorded transfers stay immutable');
final class BookingFeesFixturePDO extends PDO {
 public function __construct(){parent::__construct('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);}
 public function prepare(string $query,array $options=[]):PDOStatement|false {
  if(str_contains($query,'information_schema.tables'))$query="SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name=?";
  return parent::prepare(str_replace(' FOR UPDATE','',$query),$options);
 }
}
$pdo=new BookingFeesFixturePDO();
$pdo->exec('CREATE TABLE bookings (booking_id INTEGER,booking_type TEXT,grand_total DECIMAL,preferred_resource TEXT)');
$pdo->exec('CREATE TABLE booking_expenses (id INTEGER,booking_id INTEGER,expense_type TEXT,expense_name TEXT,amount DECIMAL)');
$pdo->exec('CREATE TABLE booking_walkin_details (booking_id INTEGER,details TEXT)');
$pdo->exec('CREATE TABLE booking_checkout_drafts (booking_draft_id INTEGER,booking_id INTEGER,booking_domain TEXT,status TEXT,payload TEXT,total_minor INTEGER,created_at TEXT)');
$pdo->exec("INSERT INTO bookings VALUES (1,'boat',2000,''),(2,'boat',2000,'Walk-in booking')");
$rows=[PaymentReceiptLine('Boat hire',1500),PaymentReceiptLine('Environmental fee',100),PaymentReceiptLine('Entrance fee — Island',100),PaymentReceiptLine('Docking / landing fee — Island',300)];
$insert=$pdo->prepare('INSERT INTO booking_checkout_drafts VALUES (1,1,?,?,?,200000,?)');
$insert->execute(['boat','submitted',json_encode(['price_breakdown'=>['rows'=>$rows]]),'2026-10-17']);
$loaded=PayoutBookingFees::load($pdo,'boat',1,true);
feeCheck($loaded['verified'] && $loaded['total_minor']===50000,'Online checkout uses recorded itemization');
feeCheck($loaded['locations']['entrance']['Island']===10000 && $loaded['locations']['docking']['Island']===30000,'Saved checkout location names preserved');
$allocation=PayoutBookingFees::allocateLocations(['Apuao Grande Island'=>10001,'Caringo Island'=>20002],10001);
feeCheck(array_sum($allocation)===10001,'Partial collection location amounts reconcile exactly');
feeCheck(PayoutBookingFees::allocateLocations([],100)===['Location not recorded'=>100],'Unlabeled fees remain visible without guessing a location');
$pdo->exec("INSERT INTO booking_expenses VALUES (1,2,'Environmental Fee','Environmental fee',100),(2,2,'Entrance Fee','Entrance fee — Island',100),(3,2,'Docking / Landing Fee','Docking / landing fee — Island',300),(4,2,'food','Food',100)");
$insert=$pdo->prepare('INSERT INTO booking_walkin_details VALUES (2,?)');
$insert->execute([json_encode(['service_rows'=>[PaymentReceiptLine('Boat hire',1400)]])]);
$loaded=PayoutBookingFees::load($pdo,'boat',2,true);
feeCheck($loaded['verified'] && $loaded['total_minor']===50000,'Walk-in expenses counted once and service extras retained');
$pdo->exec("INSERT INTO bookings VALUES (3,'package',1000,'')");
$loaded=PayoutBookingFees::load($pdo,'package',3,false);
feeCheck(!$loaded['verified'],'Missing historical itemization is not guessed');
echo "Passed $checks booking fee allocation checks.\n";
