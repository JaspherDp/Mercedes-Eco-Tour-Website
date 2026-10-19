<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require __DIR__.'/../payments/PaymentHelper.php';
$host=PaymentHelper::env('DB_HOST');if(!in_array($host,['localhost','127.0.0.1','::1'],true))throw new RuntimeException('Localhost only');
require __DIR__.'/../php/db_connection.php';require __DIR__.'/../php/booking_rejection_helper.php';require __DIR__.'/../php/hotel_cancellation_review.php';
$dsn='mysql:host='.$host.';dbname='.PaymentHelper::env('DB_NAME').';charset=utf8mb4';
$permanent=new PDO($dsn,PaymentHelper::env('DB_USER'),PaymentHelper::env('DB_PASSWORD'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$hash=function()use($permanent){$v=[];foreach(['bookings','hotel_room_bookings','booking_cancellation_requests','payment_transactions','booking_refunds']as$t)$v[$t]=hash('sha256',json_encode($permanent->query('SELECT * FROM '.$t)->fetchAll()));return $v;};$before=$hash();
$tour=$pdo->query('SELECT * FROM bookings ORDER BY booking_id LIMIT 1')->fetch(PDO::FETCH_ASSOC);$hotel=$pdo->query('SELECT * FROM hotel_room_bookings ORDER BY hotel_booking_id LIMIT 1')->fetch(PDO::FETCH_ASSOC);if(!$tour||!$hotel)throw new RuntimeException('Local fixture seed missing');
foreach(['bookings','hotel_room_bookings','booking_cancellation_requests','payment_transactions','booking_refunds']as$t){
$ddl=$permanent->query('SHOW CREATE TABLE '.$t)->fetch()['Create Table'];
$ddl=preg_replace('/^CREATE TABLE /','CREATE TEMPORARY TABLE ',$ddl);
$ddl=preg_replace('/^  CONSTRAINT .*FOREIGN KEY.*\n?/m','',$ddl);
$ddl=preg_replace('/,\n\)/',"\n)",$ddl);
if(!str_starts_with($ddl,'CREATE TEMPORARY TABLE '))throw new RuntimeException('Temporary schema guard');$pdo->exec($ddl);
}
$insert=function($table,$row)use($pdo){foreach($pdo->query('SHOW COLUMNS FROM '.$table)->fetchAll() as $column)if(str_contains($column['Extra'],'GENERATED'))unset($row[$column['Field']]);$cols=array_keys($row);$q=$pdo->prepare('INSERT INTO '.$table.' (`'.implode('`,`',$cols).'`) VALUES ('.implode(',',array_fill(0,count($cols),'?')).')');$q->execute(array_values($row));};
$tour['status']='pending';$tour['is_complete']='uncomplete';$tour['payment_amount']='400.00';$tour['remaining_balance']='1600.00';$tour['grand_total']='2000.00';$insert('bookings',$tour);
$hotel['booking_status']='pending';$hotel['checked_in_at']=null;$hotel['checked_out_at']=null;$hotel['amount_paid']='600.00';$hotel['remaining_balance']='2400.00';$hotel['total_amount']='3000.00';$insert('hotel_room_bookings',$hotel);

$pdo->exec("UPDATE hotel_room_bookings SET checkin_date='2099-11-20',checkout_date='2099-11-21'");
$q=$pdo->prepare("INSERT INTO booking_cancellation_requests (tourist_id,booking_domain,booking_id,initiated_by,booking_reference,booking_type,service_name,service_date,total_amount,amount_paid,days_before_service,refund_policy,refundable_amount,non_refundable_amount,cancellation_reason,request_status,refund_status,requested_at) VALUES (?,'hotel',?,'tourist',?,'hotel','Temporary hotel','2099-11-20',3000,600,3,'full_refund',600,0,'Change of plans','pending','not_started','2099-11-17 12:00:00')");
$q->execute([(int)$hotel['tourist_id'],(int)$hotel['hotel_booking_id'],(string)$hotel['booking_reference']]);$id=(int)$pdo->lastInsertId();
$r=HotelCancellationReview($pdo,(int)$hotel['hotel_resort_id'],1,$id,'approve');
if($r['request']['request_status']!=='approved'||(float)$r['request']['refundable_amount']!==600.0)throw new RuntimeException('Approval mismatch');
$list=HotelCancellationList($pdo,(int)$hotel['hotel_resort_id']);if($list['total']!==1)throw new RuntimeException('Property list mismatch');
$pdo->exec("UPDATE booking_cancellation_requests SET refund_status='completed' WHERE cancellation_request_id=".$id);
if(HotelCancellationList($pdo,(int)$hotel['hotel_resort_id'])['rows'][0]['refund_status']!=='completed')throw new RuntimeException('Refund visibility mismatch');
if(HotelCancellationMainAdminCanDecide($r['request']))throw new RuntimeException('Main admin must not decide hotel requests');
$pdo=null;if($before!==$hash())throw new RuntimeException('Permanent tables changed');
echo "PASS: actual local MySQL hotel approval, property list, main-admin decision restriction and updated refund-status visibility using temporary tables; permanent records unchanged.\n";
