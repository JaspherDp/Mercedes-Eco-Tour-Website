<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
putenv('PAYMONGO_MODE=test');
require_once __DIR__.'/../payments/BalanceCheckoutPending.php';
$n=0;
function ck($ok,$message){global $n;$n++;if(!$ok)throw new RuntimeException($message);}
function reject($fn){try{$fn();}catch(Throwable $e){return;}throw new RuntimeException('Expected rejection');}
$tx=['payment_transaction_id'=>1,'merchant_reference'=>'PM-TEST','provider_checkout_session_id'=>'cs_test','metadata'=>'{"paymongo_mode":"test"}'];
function resource($state='active',$payments=[]){return ['data'=>['id'=>'cs_test','type'=>'checkout_session','attributes'=>['reference_number'=>'PM-TEST','livemode'=>false,'status'=>$state,'payment_status'=>'unpaid','payments'=>$payments]]];}
$pdo=new PDO('sqlite::memory:');$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE payment_transactions(payment_transaction_id INTEGER PRIMARY KEY,status TEXT,provider_checkout_session_id TEXT,failed_at TEXT,failure_code TEXT,failure_message TEXT)');
$pdo->exec("INSERT INTO payment_transactions VALUES(1,'pending','cs_test',NULL,NULL,NULL)");
$status=fn()=>$pdo->query('SELECT status FROM payment_transactions WHERE payment_transaction_id=1')->fetchColumn();
ck(BalanceCheckoutPending::refresh($pdo,$tx,fn()=>resource())==='active' && $status()==='pending','Active checkout must remain pending for reuse');
reject(fn()=>BalanceCheckoutPending::refresh($pdo,$tx,function(){throw new RuntimeException('network unavailable');}));ck($status()==='pending','Network failure must not retire payment');
foreach(['id','reference','mode','state'] as $bad){$r=resource('expired');if($bad==='id')$r['data']['id']='cs_other';if($bad==='reference')$r['data']['attributes']['reference_number']='PM-OTHER';if($bad==='mode')$r['data']['attributes']['livemode']=true;if($bad==='state')$r['data']['attributes']['status']='unknown';reject(fn()=>BalanceCheckoutPending::refresh($pdo,$tx,fn()=>$r));ck($status()==='pending','Invalid provider resource must not retire payment');}
ck(BalanceCheckoutPending::refresh($pdo,$tx,fn()=>resource('expired',[['attributes'=>['status'=>'processing']]]))==='processing'&&$status()==='pending','Processing payment must block replacement');
ck(BalanceCheckoutPending::refresh($pdo,$tx,fn()=>resource('active',[['attributes'=>['status'=>'failed']]]))==='active','Failed attempt does not expire its session');
$called=0;ck(BalanceCheckoutPending::refresh($pdo,$tx,fn()=>resource('active',[['attributes'=>['status'=>'paid']]]),function($db,$r)use(&$called){$called++;})==='paid'&&$called===1,'Paid resource must go through existing reconciler');
ck($status()==='pending','Helper itself cannot credit bookings');
ck(BalanceCheckoutPending::refresh($pdo,$tx,fn()=>resource('expired'))==='expired'&&$status()==='expired','Only confirmed expired session releases active payment');
$pdo->exec("UPDATE payment_transactions SET status='paid' WHERE payment_transaction_id=1");BalanceCheckoutPending::refresh($pdo,$tx,fn()=>resource('expired'));ck($status()==='paid','Concurrent paid state must not be overwritten');
$pdo->exec("UPDATE payment_transactions SET status='pending', provider_checkout_session_id='cs_replaced' WHERE payment_transaction_id=1");BalanceCheckoutPending::refresh($pdo,$tx,fn()=>resource('expired'));ck($status()==='pending','Concurrent session replacement must not be overwritten');
$pdo->beginTransaction();reject(fn()=>BalanceCheckoutPending::refresh($pdo,$tx,fn()=>resource()));$pdo->rollBack();
$endpoint=file_get_contents(__DIR__.'/../payments/create-balance-checkout.php');ck(strpos($endpoint,'$pdo->commit();',strpos($endpoint,'$pendingId ='))<strpos($endpoint,'BalanceCheckoutPending::refresh'),'Provider call must happen after lock release');ck(strpos($endpoint,"uq_payment_active_booking")!==false,'Only active-key conflict is reported as concurrent payment');
echo "PASS: $n pending-checkout checks; no network or application database used.\n";
