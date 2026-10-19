<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/../php/hotel_child_policy.php';
$checks=0;
function availableCheck(bool $condition,string $message):void{global $checks;if(!$condition)throw new RuntimeException($message);$checks++;}
// Real availability SQL on an in-memory database, with schema bootstrap disabled.
function HoEnsureHotelBookingsTable(PDO $pdo):void{}
function HoRoomsTableExists(PDO $pdo,string $table):bool{$q=$pdo->prepare("SELECT COUNT(*) FROM sqlite_master WHERE name=? AND type='table'");$q->execute([$table]);return(bool)$q->fetchColumn();}
function HoGetHotelRooms(PDO $pdo,int $hotel,bool $active=true):array{return $hotel===1?[$GLOBALS['testRoom']]:[];}
function HoRoomCapacityTotal(array $room):int{return HoChildRoomPolicy($room)['max_occupancy'];}
$source=file_get_contents(__DIR__.'/../php/hotel_rooms_helper.php');
$start=strpos($source,'function HoRoomReservationEndSql(');$end=strpos($source,'function HoGetHotelRoomStatusSnapshot(', $start);
eval(substr($source,$start,$end-$start));
$pdo=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$pdo->sqliteCreateFunction('NOW',fn()=>'2099-01-01 12:00:00');
$pdo->sqliteCreateFunction('JSON_UNQUOTE',fn($value)=>$value===null?null:(string)$value);
$pdo->sqliteCreateFunction('GREATEST',fn(...$values)=>max($values));
$pdo->exec('CREATE TABLE hotel_room_bookings(hotel_room_id INT,hotel_resort_id INT,room_type TEXT,booking_status TEXT,checkin_date TEXT,checkout_date TEXT,checked_in_at TEXT,checked_out_at TEXT);
CREATE TABLE booking_checkout_drafts(booking_domain TEXT,status TEXT,expires_at TEXT,payload TEXT)');
$testRoom=['id'=>1,'room_name'=>'Couple','status'=>'active','price'=>1500,'capacity_adults'=>2,'capacity_children'=>0,'child_policy_approval'=>['hotel_room_id'=>1,'adult_capacity'=>2,'max_children'=>2,'max_occupancy'=>4,'approved_at'=>'2026-10-20']];
$party=HoChildParty(2,2,[3,10]);
$search=fn()=>HoGetAvailableHotelRooms($pdo,1,'2099-01-01','2099-01-03',4,$party)[0];
availableCheck($search()['is_available'],'Approved room with free/paying child is available');
$pdo->exec("INSERT INTO hotel_room_bookings VALUES(1,1,'Couple','confirmed','2099-01-02','2099-01-03',NULL,NULL)");
availableCheck(!$search()['is_available'],'Confirmed overlap blocked');
availableCheck(!HoIsHotelRoomAvailable($pdo,1,1,'Couple','2099-01-01','2099-01-03'),'Checkout availability uses same overlap');
availableCheck(HoIsHotelRoomAvailable($pdo,1,1,'Couple','2099-01-03','2099-01-04'),'Adjacent checkout/checkin allowed');
availableCheck(HoGetRoomUnavailableDates($pdo,1,1,'Couple','2099-01-01','2099-01-05')===['2099-01-02'],'Checkout date excluded from reserved nights');
$pdo->exec("UPDATE hotel_room_bookings SET booking_status='pending'");availableCheck(!$search()['is_available'],'Pending reservation blocked');
$pdo->exec("UPDATE hotel_room_bookings SET booking_status='cancelled'");availableCheck($search()['is_available'],'Cancelled reservation releases dates');
$payload=json_encode(['hotel_resort_id'=>1,'hotel_room_id'=>1,'room_type'=>'Couple','checkin_date'=>'2099-01-01','checkout_date'=>'2099-01-03']);
$hold=$pdo->prepare("INSERT INTO booking_checkout_drafts VALUES('hotel','pending',?,?)");$hold->execute(['2099-01-02 12:00:00',$payload]);
availableCheck(!$search()['is_available'],'Unexpired checkout hold blocked');
$pdo->exec("UPDATE booking_checkout_drafts SET expires_at='2098-12-31 12:00:00'");availableCheck($search()['is_available'],'Expired hold released');
$pdo->exec("UPDATE booking_checkout_drafts SET expires_at='2099-01-02 12:00:00',status='cancelled'");availableCheck($search()['is_available'],'Cancelled checkout hold released');
$pdo->exec("UPDATE hotel_room_bookings SET booking_status='confirmed',checkin_date='2020-01-01',checkout_date='2020-01-02',checked_in_at='2020-01-01 12:00:00'");
$occupiedUntil=HoRoomOccupiedUntil();$occupiedToday=(new DateTimeImmutable($occupiedUntil))->modify('-1 day')->format('Y-m-d');
availableCheck(!HoIsHotelRoomAvailable($pdo,1,1,'Couple',$occupiedToday,$occupiedUntil),'Unfinished check-in blocks tonight');
availableCheck(HoIsHotelRoomAvailable($pdo,1,1,'Couple','2099-01-03','2099-01-04'),'Unfinished old check-in does not block every future stay');
$pdo->exec("UPDATE hotel_room_bookings SET booking_status='cancelled'");
$result=HoGetAvailableHotelRooms($pdo,1,'2099-01-01','2099-01-03',5,HoChildParty(3,2,[3,10]));
availableCheck(count($result)===1&&!$result[0]['is_available']&&$result[0]['suitability_reason']==='Maximum adult capacity exceeded','Unsuitable room remains visible with accurate reason');
$result=HoGetAvailableHotelRooms($pdo,1,'2099-01-01','2099-01-03',5,HoChildParty(2,3,[3,10,5]));availableCheck($result[0]['suitability_reason']==='Maximum child allowance exceeded','Child allowance reason');
$testRoom['child_policy_approval']['max_occupancy']=3;$result=$search();availableCheck($result['suitability_reason']==='Maximum approved occupancy exceeded','Free children count toward approved total');

// Exercise the real mutex API with two isolated simulated MySQL connections.
class ChildLockPDO extends PDO{
    public static array $locks=[]; public int $owner;
    public function __construct(int $owner){$this->owner=$owner;}
    public function getAttribute(int $attribute):mixed{return'mysql';}
    public function prepare(string $query,array $options=[]):PDOStatement|false{return new ChildLockStatement($this,$query);}
}
class ChildLockStatement extends PDOStatement{
    private mixed $value=0;
    public function __construct(private ChildLockPDO $connection,private string $sql){}
    public function execute(?array $params=null):bool{
        $name=$params[0];if(str_contains($this->sql,'GET_LOCK')){
            if(isset(ChildLockPDO::$locks[$name])&&ChildLockPDO::$locks[$name]!==$this->connection->owner){$this->value=0;return true;}
            ChildLockPDO::$locks[$name]=$this->connection->owner;$this->value=1;
        }else{if((ChildLockPDO::$locks[$name]??null)===$this->connection->owner)unset(ChildLockPDO::$locks[$name]);$this->value=1;}
        return true;
    }
    public function fetchColumn(int $column=0):mixed{return $this->value;}
}
$first=new ChildLockPDO(1);$second=new ChildLockPDO(2);$name=HoChildReservationLock($first,1,1);
try{HoChildReservationLock($second,1,1);throw new RuntimeException('Concurrent lock accepted');}catch(DomainException){availableCheck(true,'Second checkout cannot hold the same mutex');}
HoChildReservationUnlock($first,$name);availableCheck(HoChildReservationLock($second,1,1)===$name,'Lock available after commit/unlock');HoChildReservationUnlock($second,$name);
// Execute the actual general property-result function with the same room validator.
function tableExists(PDO $pdo,string $table):bool{return HoRoomsTableExists($pdo,$table);}
function tableColumns(PDO $pdo,string $table):array{return array_column($pdo->query("PRAGMA table_info('".$table."')")->fetchAll(PDO::FETCH_ASSOC),'name');}
function resolveSearchImage(?string $value,string $fallback=''):string{return $value?:$fallback;}
$propertySource=file_get_contents(__DIR__.'/../public/search_results.php');
$start=strpos($propertySource,'function firstColumn(');$end=strpos($propertySource,'function resolveSearchThumbnail(',$start);eval(substr($propertySource,$start,$end-$start));
$start=strpos($propertySource,'function fetchHotelResults(');$end=strpos($propertySource,'function fetchTourPackageResults(',$start);eval(substr($propertySource,$start,$end-$start));
$pdo->exec("CREATE TABLE hotel_resorts(hotel_resort_id INT,name TEXT,island TEXT,status TEXT,price NUMERIC); INSERT INTO hotel_resorts VALUES(1,'Fixture stay','Caringo','active',1500),(2,'No rooms','Caringo','active',1000)");
$testRoom['child_policy_approval']['max_occupancy']=4;
$stay=['party'=>$party,'checkin'=>'2099-01-01','checkout'=>'2099-01-03'];
$properties=fetchHotelResults($pdo,'Caringo',4,2,$stay);
availableCheck(count($properties)===1,'Property search excludes properties without a suitable room');
$stay['party']=HoChildParty(3,0,[]);availableCheck(fetchHotelResults($pdo,'Caringo',3,1,$stay)===[],'Property search enforces adult limit');
availableCheck(count(fetchHotelResults($pdo,'Caringo',1,1))===2,'No-date discovery remains backward compatible');
echo "PASS: {$checks} availability/hold checks including simulated mutex contention; no live MySQL or concurrency test.\n";
