<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__.'/../payments/AdminCheckoutResume.php';
$n=0;function ok($v,$m){global $n;$n++;if(!$v)throw new RuntimeException($m);}
$meta=['source'=>'operator_booking_payment','staff_type'=>'operator','operator_id'=>'1'];
$tx=['status'=>'pending','booking_domain'=>'package','booking_id'=>8,'amount_minor'=>1000,'provider_checkout_session_id'=>'cs_example','checkout_url'=>'https://checkout.paymongo.com/cs_example','metadata'=>json_encode($meta)];
$booking=['booking_id'=>8,'booking_type'=>'Package','operator_id'=>1];
ok(AdminCheckoutResume::eligible($tx,$booking,1000,7),'Matching operator payment can be resumed');
foreach(['amount','booking','domain','operator','paid','url','session','source','admin'] as $bad){$t=$tx;$b=$booking;$a=1000;$id=7;if($bad==='amount')$a=900;if($bad==='booking')$b['booking_id']=9;if($bad==='domain')$b['booking_type']='Boat';if($bad==='operator')$b['operator_id']=2;if($bad==='paid')$t['status']='paid';if($bad==='url')$t['checkout_url']='https://checkout.paymongo.com.evil.test/';if($bad==='session')$t['provider_checkout_session_id']='';if($bad==='source')$t['metadata']=json_encode(['source'=>'tourist_profile_balance']);if($bad==='admin')$id=0;ok(!AdminCheckoutResume::eligible($t,$b,$a,$id),'Reject mismatched '.$bad);}
$grant=AdminCheckoutResume::grant($meta,7);ok(AdminCheckoutResume::granted($grant,7),'Resuming admin can monitor/cancel');ok(!AdminCheckoutResume::granted($grant,8),'Other admins need their own resume grant');ok(!AdminCheckoutResume::granted($meta,7),'No grant before resume');ok($grant['source']===$meta['source']&&$grant['operator_id']==='1'&&$grant['staff_type']==='operator','Original attribution preserved');
$grant=AdminCheckoutResume::grant(AdminCheckoutResume::grant($grant,7),8);ok(count($grant['admin_collection_resumed_by'])===2,'Repeated resume is idempotent and preserves grants');
$endpoint=file_get_contents(__DIR__.'/../payments/create-balance-checkout.php');ok(strpos($endpoint,'BalanceCheckoutPending::refresh')<strpos($endpoint,'AdminCheckoutResume::eligible'),'Provider verification precedes resumption');ok(strpos($endpoint,'AdminCheckoutResume::granted($pendingMetadata, $requestAdminId)')!==false,'Cancellation requires matching explicit grant');
$admin=file_get_contents(__DIR__.'/../admin/adbookings.php');ok(strpos($admin,'AdminCheckoutResume::granted($metadata, (int)($_SESSION[\'admin_id\'] ?? 0))')!==false,'Status polling checks explicit grant');
echo "PASS: $n operator/admin checkout-resume checks.\n";
