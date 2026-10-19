<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require __DIR__.'/../payments/PaymentHelper.php';
$host=PaymentHelper::env('DB_HOST');if(!in_array($host,['localhost','127.0.0.1','::1'],true))throw new RuntimeException('Localhost only');
require __DIR__.'/../php/db_connection.php';require __DIR__.'/../php/booking_rejection_helper.php';
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
$r=BookingRejectAndQueue($pdo,'admin',1,(int)$tour['booking_id'],'Local temporary-table verification');if($r['request']['request_status']!=='approved'||(float)$r['request']['refundable_amount']!==400.0)throw new RuntimeException('Tour queue mismatch');
$h=BookingRejectAndQueue($pdo,'hotel_admin',1,(int)$hotel['hotel_booking_id'],'Local temporary-table verification',(int)$hotel['hotel_resort_id']);if($h['request']['request_status']!=='approved'||(float)$h['request']['refundable_amount']!==600.0)throw new RuntimeException('Hotel queue mismatch');
$repeat=BookingRejectAndQueue($pdo,'admin',1,(int)$tour['booking_id'],'Repeat');if($repeat['created'])throw new RuntimeException('Duplicate request');
$pdo=null;if($before!==$hash())throw new RuntimeException('Permanent tables changed during temporary-table verification');
echo "PASS: actual local MySQL schema/transactions for tour and hotel rejection, approved full-refund queues and replay; temporary tables discarded; permanent booking/payment/refund rows unchanged.\n";
