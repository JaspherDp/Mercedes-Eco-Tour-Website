<?php
declare(strict_types=1);
// Synthetic, connection-scoped MySQL temporary tables only. No application records or DDL.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
date_default_timezone_set('Asia/Manila');
require_once __DIR__.'/../payments/PaymentHelper.php';
require_once __DIR__.'/../php/hotel_rooms_helper.php';
final class HotelAvailabilityFixturePDO extends PDO {
    public function exec(string $statement):int|false {
        // Existing helper bootstrap is not part of these availability tests.
        if(preg_match('/^\s*(CREATE TABLE IF NOT EXISTS|ALTER TABLE)/i',$statement))return 0;
        return parent::exec($statement);
    }
    public function prepare(string $query,array $options=[]):PDOStatement|false {
        if(stripos($query,'information_schema.TABLES')!==false)$query="SELECT CASE WHEN ? IN ('hotel_rooms','hotel_room_bookings','booking_checkout_drafts') THEN 1 ELSE 0 END";
        if(stripos($query,'information_schema.COLUMNS')!==false)$query='SELECT CASE WHEN ? IS NOT NULL AND ? IS NOT NULL THEN 1 ELSE 0 END';
        return parent::prepare($query,$options);
    }
}
$host=PaymentHelper::env('DB_HOST');
if(!in_array($host,['localhost','127.0.0.1','::1'],true))throw new RuntimeException('Availability fixtures require localhost.');
$pdo=new HotelAvailabilityFixturePDO('mysql:host='.$host.';dbname='.PaymentHelper::env('DB_NAME').';charset=utf8mb4',PaymentHelper::env('DB_USER'),PaymentHelper::env('DB_PASSWORD'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
$pdo->exec("SET time_zone='+08:00'");
$pdo->exec("CREATE TEMPORARY TABLE hotel_room_bookings (
    hotel_booking_id INT PRIMARY KEY,hotel_resort_id INT,hotel_room_id INT NULL,room_type VARCHAR(120),booking_status VARCHAR(40),
    checkin_date DATE,checkout_date DATE,checked_in_at DATETIME NULL,checked_out_at DATETIME NULL,
    rooms_booked INT DEFAULT 1,payment_type VARCHAR(40) DEFAULT 'full',payment_status VARCHAR(40) DEFAULT 'unpaid',
    amount_paid DECIMAL(12,2) DEFAULT 0,total_amount DECIMAL(12,2) DEFAULT 0,remaining_balance DECIMAL(12,2) DEFAULT 0
) ENGINE=InnoDB");
$pdo->exec("CREATE TEMPORARY TABLE hotel_rooms (
    hotel_room_id INT PRIMARY KEY,hotel_resort_id INT,room_name VARCHAR(150),description TEXT,price DECIMAL(12,2),
    capacity_adults INT,capacity_children INT,breakfast_for INT,room_meta_json TEXT,inclusions_json TEXT,gallery_images_json TEXT,
    main_image_path TEXT,status VARCHAR(20),updated_at DATETIME
) ENGINE=InnoDB");
$pdo->exec("CREATE TEMPORARY TABLE booking_checkout_drafts (
    booking_draft_id INT PRIMARY KEY,booking_domain VARCHAR(20),status VARCHAR(20),expires_at DATETIME,payload LONGTEXT
) ENGINE=InnoDB");
$pdo->exec("INSERT INTO hotel_rooms VALUES
    (49,3204,'Room 1','',2000,2,1,0,'{}','[]','[]','','active',NOW()),
    (50,3204,'Room 2','',2500,4,1,0,'{}','[]','[]','','active',NOW())");
HoEnsureHotelBookingsTable($pdo);
$checks=0;
function roomCheck(bool $condition,string $message):void {
    global $checks;if(!$condition)throw new RuntimeException($message);$checks++;
}
function day(int $offset):string {return (new DateTimeImmutable('today',new DateTimeZone('Asia/Manila')))->modify(($offset>=0?'+':'').$offset.' days')->format('Y-m-d');}
function resetRoomFixtures(PDO $pdo):void {
    // These names are shadowed by the temporary tables in this connection.
    $pdo->exec('DELETE FROM hotel_room_bookings');$pdo->exec('DELETE FROM booking_checkout_drafts');
}
function reserveRoom(PDO $pdo,int $start,int $end,array $extra=[]):void {
    $r=$extra+['id'=>1,'hotel'=>3204,'room'=>49,'name'=>'Room 1','status'=>'confirmed','in'=>null,'out'=>null];
    $pdo->prepare('INSERT INTO hotel_room_bookings (hotel_booking_id,hotel_resort_id,hotel_room_id,room_type,booking_status,checkin_date,checkout_date,checked_in_at,checked_out_at) VALUES (?,?,?,?,?,?,?,?,?)')
        ->execute([$r['id'],$r['hotel'],$r['room'],$r['name'],$r['status'],day($start),day($end),$r['in'],$r['out']]);
}
function holdRoom(PDO $pdo,int $start,int $end,array $extra=[]):void {
    $r=$extra+['id'=>1,'domain'=>'hotel','status'=>'pending','expires'=>date('Y-m-d H:i:s',time()+3600),'hotel'=>3204,'room'=>49,'name'=>'Room 1'];
    $payload=['hotel_resort_id'=>$r['hotel'],'hotel_room_id'=>$r['room'],'room_type'=>$r['name'],'checkin_date'=>day($start),'checkout_date'=>day($end)];
    $pdo->prepare('INSERT INTO booking_checkout_drafts VALUES (?,?,?,?,?)')->execute([$r['id'],$r['domain'],$r['status'],$r['expires'],json_encode($payload,JSON_THROW_ON_ERROR)]);
}
function checkStay(PDO $pdo,int $start,int $end,bool $expected,string $message):void {
    $available=HoIsHotelRoomAvailable($pdo,3204,49,'Room 1',day($start),day($end));
    roomCheck($available===$expected,$message.' — booking validation');
    $rooms=HoGetAvailableHotelRooms($pdo,3204,day($start),day($end),2);
    $room=array_values(array_filter($rooms,fn($room)=>$room['id']===49))[0]??null;
    roomCheck($room!==null && $room['is_available']===$expected && $room['is_booked_for_dates']===!$expected,$message.' — public room list');
    $dates=HoGetRoomUnavailableDates($pdo,3204,49,'Room 1',day($start),day($end));
    $blocked=false;
    for($i=$start;$i<$end;$i++)if(in_array(day($i),$dates,true))$blocked=true;
    roomCheck($blocked===!$expected,$message.' — calendar nights');
}
reserveRoom($pdo,10,12);
checkStay($pdo,10,11,false,'Booked arrival night');
checkStay($pdo,11,12,false,'Booked last night');
checkStay($pdo,9,13,false,'Stay contains existing reservation');
checkStay($pdo,9,10,true,'Departure at another guest arrival is allowed');
checkStay($pdo,12,13,true,'Arrival on checkout day is allowed');
checkStay($pdo,20,22,true,'Unrelated future dates stay available');
roomCheck(HoGetRoomUnavailableDates($pdo,3204,49,'Room 1',day(9),day(13))===[day(10),day(11)],'Calendar excludes checkout day');
resetRoomFixtures($pdo);reserveRoom($pdo,-10,-8,['in'=>day(-10).' 14:00:00']);
checkStay($pdo,8,10,true,'Unfinished historical check-in does not block all future dates');
checkStay($pdo,0,1,false,'Overdue occupant still blocks tonight');
checkStay($pdo,1,2,true,'Overdue occupation has a bounded daily hold');
checkStay($pdo,-12,-11,true,'Occupation never blocks before its arrival');
resetRoomFixtures($pdo);reserveRoom($pdo,-1,5,['in'=>day(-1).' 14:00:00']);
checkStay($pdo,3,4,false,'Current guest retains the scheduled remaining nights');
checkStay($pdo,5,6,true,'Current guest does not block after scheduled checkout');
foreach(['cancelled','completed','declined','no-show'] as $status){resetRoomFixtures($pdo);reserveRoom($pdo,10,12,['status'=>$status]);checkStay($pdo,10,12,true,$status.' booking does not reserve room');}
resetRoomFixtures($pdo);reserveRoom($pdo,10,12,['status'=>'pending']);checkStay($pdo,10,11,false,'Pending reservation stays protected');
resetRoomFixtures($pdo);reserveRoom($pdo,10,12,['room'=>50,'name'=>'Room 1']);checkStay($pdo,10,11,true,'Renamed different room cannot block Room 1 by stale name');
foreach([null,0] as $legacyId){resetRoomFixtures($pdo);reserveRoom($pdo,10,12,['room'=>$legacyId,'name'=>' room 1 ']);checkStay($pdo,10,11,false,'Legacy room identity uses normalized name');}
resetRoomFixtures($pdo);reserveRoom($pdo,10,12,['hotel'=>999]);checkStay($pdo,10,11,true,'Another resort never blocks this property');
resetRoomFixtures($pdo);holdRoom($pdo,10,12);
checkStay($pdo,10,11,false,'Active hosted-payment hold reserves overlapping nights');
checkStay($pdo,12,13,true,'Hosted-payment hold permits arrival on checkout');
checkStay($pdo,9,10,true,'Hosted-payment hold permits adjacent departure');
checkStay($pdo,20,22,true,'Hosted-payment hold does not block unrelated dates');
foreach(['expired','paid','submitted','failed'] as $status){resetRoomFixtures($pdo);holdRoom($pdo,10,12,['status'=>$status]);checkStay($pdo,10,11,true,'Inactive '.$status.' hold is ignored');}
resetRoomFixtures($pdo);holdRoom($pdo,10,12,['expires'=>date('Y-m-d H:i:s',time()-60)]);checkStay($pdo,10,11,true,'Expired pending hold is ignored');
resetRoomFixtures($pdo);holdRoom($pdo,10,12,['room'=>50,'name'=>'Room 1']);checkStay($pdo,10,11,true,'Another room draft cannot block by stale name');
foreach([null,0] as $legacyId){resetRoomFixtures($pdo);holdRoom($pdo,10,12,['room'=>$legacyId,'name'=>' room 1 ']);checkStay($pdo,10,11,false,'Legacy draft identity uses normalized name');}
resetRoomFixtures($pdo);
$rooms=HoGetAvailableHotelRooms($pdo,3204,day(10),day(11),4);
$room=array_values(array_filter($rooms,fn($room)=>$room['id']===49))[0];
roomCheck(!$room['is_available'] && !$room['is_booked_for_dates'] && $room['insufficient_capacity'],'Capacity restrictions are not mistaken for a booking');
echo "Passed $checks room availability checks (MySQL temporary fixtures; no application records changed).\n";
