<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/../php/third_party_fee_ledger.php';
$checks=0;function feeLedgerCheck(bool $ok,string $label):void{global $checks;if(!$ok)throw new RuntimeException($label);$checks++;}
$catalog=[];foreach(additionalFeeDefinitions() as $code=>$definition)$catalog[$code]=['category'=>$definition[0],'label'=>$definition[1],'amount'=>$definition[2],'unit'=>$definition[3]];
function feeBooking(string $domain,int $id,int $entrance,int $docking,int $environmental,string $location='Canimog Island'):array{
 return ['booking_domain'=>$domain,'booking_id'=>$id,'accounting'=>['active_refund'=>false,'blocked_reason'=>'','booking_fee_locations_minor'=>['entrance'=>$entrance?[$location=>$entrance]:[],'docking'=>$docking?[$location=>$docking]:[]],'booking_fees_minor'=>['environmental'=>$environmental]]];
}
$bookings=[feeBooking('boat',1,10000,50000,5000),feeBooking('boat',2,20000,30000,10000)];
$pools=ThirdPartyFeeLedger::build(array_merge($bookings,[$bookings[0]]),$catalog,[]);
$entrance=array_values(array_filter($pools,fn($p)=>$p['category']==='entrance'));
feeLedgerCheck(count($entrance)===3,'All configured entrance locations, including zero records');
$canimog=array_values(array_filter($entrance,fn($p)=>$p['location']==='Canimog Island'))[0];
feeLedgerCheck(count($canimog['rates'])===2,'Day and overnight prices grouped under one location');
feeLedgerCheck(ThirdPartyFeeLedger::balance($canimog,['boat:1','boat:2'])===['collected'=>30000,'settled'=>0,'available'=>30000],'Full collection balance');
$allocation=ThirdPartyFeeLedger::allocate($canimog,['boat:1','boat:2'],15000);
feeLedgerCheck($allocation===['boat:1'=>10000,'boat:2'=>5000],'Partial payment allocated exactly to collection sources');
$payment=['payout_id'=>1,'fee_category'=>'entrance','location_name'=>'Canimog Island','allocations'=>json_encode($allocation),'amount_minor'=>15000];
$pools=ThirdPartyFeeLedger::build($bookings,$catalog,[$payment]);$canimog=array_values(array_filter($pools,fn($p)=>$p['category']==='entrance'&&$p['location']==='Canimog Island'))[0];
feeLedgerCheck(ThirdPartyFeeLedger::balance($canimog,['boat:1','boat:2'])===['collected'=>30000,'settled'=>15000,'available'=>15000],'Recorded payment reduces outstanding amount once');
feeLedgerCheck(ThirdPartyFeeLedger::balance($canimog,['boat:1'])===['collected'=>10000,'settled'=>10000,'available'=>0],'Filtered booking receives only its actual settled allocation');
feeLedgerCheck(ThirdPartyFeeLedger::balance($canimog,['boat:2'])['available']===15000,'Other booking keeps its unspent balance');
foreach([0,-1,15001] as $amount){try{ThirdPartyFeeLedger::allocate($canimog,['boat:2'],$amount);throw new LogicException('Accepted excess payout');}catch(RuntimeException $expected){$checks++;}}
$valid=['fee_confirmed'=>'1','fee_amount'=>'50.25','fee_method'=>'online','fee_channel'=>'GCash','fee_reference'=>'TXN-123','fee_recipient'=>'Location owner','fee_request_token'=>str_repeat('a',64)];
feeLedgerCheck(ThirdPartyFeeLedger::validate($valid)['minor']===5025,'Online amount and reference validation');
foreach(['fee_confirmed'=>'','fee_channel'=>'','fee_reference'=>'','fee_recipient'=>'','fee_amount'=>'-1','fee_method'=>'card','fee_request_token'=>'bad'] as $key=>$value){try{ThirdPartyFeeLedger::validate(array_replace($valid,[$key=>$value]));throw new LogicException('Accepted invalid '.$key);}catch(RuntimeException $expected){$checks++;}}
feeLedgerCheck(ThirdPartyFeeLedger::validate(array_replace($valid,['fee_method'=>'cash','fee_channel'=>'','fee_reference'=>'']))['method']==='cash','Cash needs no online reference');
$refunded=feeBooking('boat',1,1000,0,0);$pools=ThirdPartyFeeLedger::build([$refunded],$catalog,[$payment]);$pool=array_values(array_filter($pools,fn($p)=>$p['category']==='entrance'&&$p['location']==='Canimog Island'))[0];
feeLedgerCheck(ThirdPartyFeeLedger::balance($pool,['boat:1'])===['collected'=>1000,'settled'=>10000,'available'=>0],'Refund after settlement preserves historical payout without creating negative payable');
$active=feeBooking('boat',3,1000,0,0);$active['accounting']['active_refund']=true;$pools=ThirdPartyFeeLedger::build([$active],$catalog,[]);$pool=array_values(array_filter($pools,fn($p)=>$p['category']==='entrance'&&$p['location']==='Canimog Island'))[0];
feeLedgerCheck(ThirdPartyFeeLedger::balance($pool,['boat:3'])['available']===0,'Processing refunds cannot fund new payouts');
feeLedgerCheck(ThirdPartyFeeLedger::key('entrance','Canimog Island — overnight')===ThirdPartyFeeLedger::key('entrance','Canimog Island'),'Rate variants have stable recipient identity');
$pdo=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$pdo->exec('CREATE TABLE third_party_fee_payouts (payout_id INTEGER PRIMARY KEY,request_token TEXT NOT NULL UNIQUE,amount_minor INTEGER,allocations TEXT)');
$insert=$pdo->prepare('INSERT INTO third_party_fee_payouts (request_token,amount_minor,allocations) VALUES (?,?,?)');
$insert->execute([str_repeat('a',64),15000,json_encode($allocation)]);
try{$insert->execute([str_repeat('a',64),15000,json_encode($allocation)]);throw new LogicException('Duplicate recorded');}catch(PDOException $expected){$checks++;}
feeLedgerCheck((int)$pdo->query('SELECT SUM(amount_minor) FROM third_party_fee_payouts')->fetchColumn()===15000,'Duplicate requests do not increase settled funds');
echo "Passed $checks third-party payout ledger checks.\n";
