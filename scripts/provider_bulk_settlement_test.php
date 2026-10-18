<?php
declare(strict_types=1);
// Synthetic data only. --mysql creates and drops its own random, localhost-only test schema.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../php/provider_bulk_settlement.php';
$checks = 0;
function bulkCheck(bool $ok, string $message): void {
    global $checks;
    if (!$ok) throw new RuntimeException($message);
    $checks++;
}
final class BulkFixturePDO extends PDO {
    public function __construct(string $path=':memory:') {
        parent::__construct('sqlite:'.$path, null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
        $this->sqliteCreateFunction('NOW', static fn()=>date('Y-m-d H:i:s'));
        $this->exec('PRAGMA busy_timeout=300');
    }
    public function prepare(string $query, array $options=[]): PDOStatement|false {
        if (str_contains($query, 'information_schema.tables')) $query="SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name=?";
        return parent::prepare(str_replace(' FOR UPDATE', '', $query), $options);
    }
    public function query(string $query, ?int $fetchMode=null, mixed ...$fetchModeArgs): PDOStatement|false {
        if (str_contains($query, 'information_schema.columns')) $query='SELECT 3';
        return parent::query($query);
    }
}
/** Connection-scoped temporary tables shadow application names; no application table is written. */
final class BulkMysqlTemporaryPDO extends PDO {
    public array $fixtureTables=[];
    public function prepare(string $query,array $options=[]):PDOStatement|false {
        if(str_contains($query,'information_schema.tables')) {
            $names=implode(',',array_map(fn($name)=>$this->quote($name),$this->fixtureTables));
            $query="SELECT CASE WHEN ? IN ($names) THEN 1 ELSE 0 END";
        }
        return parent::prepare($query,$options);
    }
    public function query(string $query,?int $fetchMode=null,mixed ...$args):PDOStatement|false {
        if(str_contains($query,'information_schema.columns'))$query='SELECT 3';
        return parent::query($query);
    }
}
function bulkConnect(string $database='',bool $temporary=false): PDO {
    require_once __DIR__ . '/../payments/PaymentHelper.php';
    $host=PaymentHelper::env('DB_HOST');
    if (!in_array($host, ['localhost','127.0.0.1','::1'], true)) throw new RuntimeException('MySQL fixture tests require localhost.');
    if ($database!=='' && !preg_match('/^itour_bulk_test_[a-f0-9]{16}$/D', $database)) throw new RuntimeException('Unsafe test schema.');
    if($temporary)$database=PaymentHelper::env('DB_NAME');
    $testUser=getenv('ITOUR_BULK_TEST_DB_USER');
    $user=$testUser!==false && $testUser!==''?$testUser:PaymentHelper::env('DB_USER');
    $password=$testUser!==false && $testUser!==''?(getenv('ITOUR_BULK_TEST_DB_PASSWORD')?:''):PaymentHelper::env('DB_PASSWORD');
    $class=$temporary?BulkMysqlTemporaryPDO::class:PDO::class;
    return new $class('mysql:host='.$host.($database!==''?';dbname='.$database:'').';charset=utf8mb4', $user, $password, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
}
function bulkProvider(PDO $pdo, string $type, int $id): bool {
    $q=$pdo->prepare('SELECT 1 FROM fixture_providers WHERE type=? AND id=?');$q->execute([$type,$id]);return (bool)$q->fetchColumn();
}
function bulkRetention(array $request): array {
    return ['ready'=>($request['completion_status']??'')==='cancelled' && ($request['request_status']??'')==='completed' && ($request['refund_policy']??'')==='deposit_non_refundable'];
}
function bulkContexts(PDO $pdo): array {
    $contexts=[];
    foreach ($pdo->query('SELECT * FROM provider_payouts')->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $ledger=PayoutAccounting::load($pdo,[$row]);
        $cap=(int)$row['payout_id']===9?10000:null;
        $row['accounting']=PayoutAccounting::forBooking($ledger,$row['booking_domain'],(int)$row['booking_id'],$row['gross_amount'],$cap);
        $contexts[(int)$row['payout_id']]=ProviderBulkSettlement::context($row,'fixture-csrf');
    }
    return $contexts;
}
function bulkRecord(PDO $pdo, array $rows, bool $bulk=true, bool $auditOk=true, string $note=''): void {
    ProviderBulkSettlement::record($pdo,$rows,['method'=>'cash','id'=>0,'institution'=>'Cash','account_name'=>'Fixture recipient','cipher'=>null,'last4'=>null,'label'=>'Received by Fixture'],1,'FIXTURE-ONLY',$note,
        static function(array $audit) use($pdo,$auditOk): bool {
            if (!$auditOk) return false;
            return $pdo->prepare('INSERT INTO fixture_audit (payload) VALUES (?)')->execute([json_encode($audit,JSON_THROW_ON_ERROR)]);
        },$bulk);
}
function bulkRun(PDO $pdo, array $ids, array $contexts, bool $bulk=true, bool $auditOk=true, string $note=''): array {
    $pdo->beginTransaction();
    try {
        $rows=ProviderBulkSettlement::validate($pdo,$ids,$contexts,'fixture-csrf','bulkProvider','bulkRetention');
        bulkRecord($pdo,$rows,$bulk,$auditOk,$note);$pdo->commit();return $rows;
    } catch(Throwable $e) { if($pdo->inTransaction())$pdo->rollBack();throw $e; }
}
function bulkReject(PDO $pdo, array $ids, array $contexts, string $message, bool $auditOk=true, string $note=''): void {
    $before=$pdo->query('SELECT * FROM provider_payouts ORDER BY payout_id')->fetchAll(PDO::FETCH_ASSOC);
    $audits=(int)$pdo->query('SELECT COUNT(*) FROM fixture_audit')->fetchColumn();
    $rejected=false;
    try { bulkRun($pdo,$ids,$contexts,true,$auditOk,$note); }
    catch(RuntimeException $e) { $rejected=true; }
    bulkCheck($rejected,$message.' rejects the request');
    bulkCheck($before===$pdo->query('SELECT * FROM provider_payouts ORDER BY payout_id')->fetchAll(PDO::FETCH_ASSOC),$message.' preserves every row');
    bulkCheck($audits===(int)$pdo->query('SELECT COUNT(*) FROM fixture_audit')->fetchColumn(),$message.' preserves audit history');
}
// A separate process probes actual InnoDB locks without making any settlement writes.
if (($argv[1]??'')==='--sqlite-worker') {
    $path=realpath($argv[2]??'');$temp=realpath(sys_get_temp_dir());
    if($path===false || $temp===false || strcasecmp(dirname($path),$temp)!==0 || !preg_match('/^itour-bulk-[a-f0-9]{24}\.sqlite$/D',basename($path)))throw new RuntimeException('Unsafe fixture path.');
    $pdo=new BulkFixturePDO($path);
    try { bulkRun($pdo,[1],bulkContexts($pdo));echo 'UNEXPECTED';exit(1); }
    catch(Throwable $e){echo $e instanceof PDOException && str_contains($e->getMessage(),'locked')?'LOCKED':(str_contains($e->getMessage(),'already settled')?'SETTLED':'OTHER');exit;}
}
if (($argv[1]??'')==='--worker') {
    $pdo=bulkConnect($argv[2]??'');$pdo->exec('SET innodb_lock_wait_timeout=1');
    try {
        $pdo->beginTransaction();
        ProviderBulkSettlement::validate($pdo,[1],[],'fixture-csrf','bulkProvider','bulkRetention');
        $pdo->rollBack();echo 'UNEXPECTED';exit(1);
    } catch(Throwable $e) {
        if($pdo->inTransaction())$pdo->rollBack();
        echo $e instanceof PDOException && (int)($e->errorInfo[1]??0)===1205 ? 'LOCKED' : (str_contains($e->getMessage(),'already settled')?'SETTLED':'OTHER');
        exit;
    }
}
$temporary=($argv[1]??'')==='--mysql-temporary';
$mysql=($argv[1]??'')==='--mysql'||$temporary;$server=null;$database=null;$created=false;$sqlitePath=null;
try {
    if($temporary)$pdo=bulkConnect('',true);
    elseif($mysql){
        $server=bulkConnect();$database='itour_bulk_test_'.bin2hex(random_bytes(8));
        $server->exec("CREATE DATABASE `$database` CHARACTER SET utf8mb4");$created=true;$pdo=bulkConnect($database);
    }else {
        $sqlitePath=sys_get_temp_dir().DIRECTORY_SEPARATOR.'itour-bulk-'.bin2hex(random_bytes(12)).'.sqlite';
        $handle=fopen($sqlitePath,'x');
        if($handle===false)throw new RuntimeException('Fixture file could not be created.');
        fclose($handle);
        $pdo=new BulkFixturePDO($sqlitePath);
    }
    $engine=$mysql?' ENGINE=InnoDB':'';
    $schemas=[
        'fixture_providers'=>'type VARCHAR(30), id INT, PRIMARY KEY(type,id)',
        'fixture_audit'=>'payload TEXT',
        'provider_payouts'=>"payout_id INT PRIMARY KEY,booking_domain VARCHAR(20),booking_id INT,booking_reference VARCHAR(30),provider_type VARCHAR(30),provider_id INT,provider_key VARCHAR(80),provider_name VARCHAR(100),status VARCHAR(20),gross_amount DECIMAL(12,2),paymongo_fee_amount DECIMAL(12,2),refunded_amount DECIMAL(12,2),net_amount DECIMAL(12,2),approved_by_admin_id INT,approved_at DATETIME,settlement_method VARCHAR(40),settlement_type VARCHAR(20),settlement_destination_id INT,settlement_institution VARCHAR(150),settlement_account_name VARCHAR(190),settlement_destination_cipher TEXT,settlement_destination_last4 VARCHAR(4),settlement_destination_label VARCHAR(190),settlement_reference VARCHAR(120),settlement_note VARCHAR(500),settled_by_admin_id INT,settled_at DATETIME",
        'hotel_room_bookings'=>'hotel_booking_id INT PRIMARY KEY,hotel_resort_id INT,booking_status VARCHAR(30),amount_paid DECIMAL(12,2),total_amount DECIMAL(12,2)',
        'bookings'=>'booking_id INT PRIMARY KEY,booking_type VARCHAR(20),operator_id INT,boat_id INT,guide_id INT,is_complete VARCHAR(30),status VARCHAR(30),payment_amount DECIMAL(12,2),grand_total DECIMAL(12,2),preferred_resource VARCHAR(100)',
        'booking_cancellation_requests'=>'cancellation_request_id INT PRIMARY KEY,booking_domain VARCHAR(20),booking_id INT,request_status VARCHAR(30),refund_status VARCHAR(30),refund_policy VARCHAR(30),non_refundable_amount DECIMAL(12,2)',
        'payment_transactions'=>'payment_transaction_id INT PRIMARY KEY,booking_domain VARCHAR(20),booking_id INT,provider VARCHAR(30),provider_payment_id VARCHAR(60),amount_minor INT,currency VARCHAR(3),status VARCHAR(30),metadata TEXT',
        'booking_refunds'=>'booking_id INT,booking_domain VARCHAR(20),amount_minor INT,currency VARCHAR(3),status VARCHAR(30)',
        'hotel_booking_expenses'=>'hotel_booking_id INT,expense_name VARCHAR(100),expense_type VARCHAR(100),total_amount DECIMAL(12,2)',
        'booking_checkout_drafts'=>'booking_draft_id INT,booking_id INT,booking_domain VARCHAR(20),status VARCHAR(30),payload TEXT,total_minor INT,created_at DATETIME',
    ];
    foreach($schemas as $table=>$ddl)$pdo->exec('CREATE '.($temporary?'TEMPORARY ':'')."TABLE $table ($ddl)$engine");
    if($temporary)$pdo->fixtureTables=array_keys($schemas);
    $pdo->exec("INSERT INTO fixture_providers VALUES ('hotel',1),('hotel',2),('boat',1)");
    $p=$pdo->prepare("INSERT INTO provider_payouts (payout_id,booking_domain,booking_id,booking_reference,provider_type,provider_id,provider_key,provider_name,status,gross_amount) VALUES (?,?,?,?,?,?,?,?,?,?)");
    $b=$pdo->prepare('INSERT INTO hotel_room_bookings VALUES (?,?,?,?,?)');
    $t=$pdo->prepare("INSERT INTO payment_transactions VALUES (?,'hotel',?,'paymongo',?,?,'PHP','paid',?)");
    foreach([1=>[250000,14210,25000],2=>[240000,15538,25000],3=>[300000,3515,0],4=>[100000,0,0],5=>[100000,0,0],6=>[100000,0,0],7=>[100000,0,0],8=>[100000,0,0],9=>[100000,0,0],10=>[100000,0,0]] as $id=>[$gross,$fee,$third]){
        $provider=$id===3?2:1;$status=$id===4?'pending':($id===9?'cancelled':'completed');
        $p->execute([$id,'hotel',$id,'HR-FIXTURE-'.$id,'hotel',$provider,'hotel:'.$provider,'Same display name','pending',PayoutAccounting::decimal($gross)]);
        $b->execute([$id,$provider,$status,PayoutAccounting::decimal($gross),PayoutAccounting::decimal($gross)]);
        $metadata=['paymongo_accounting'=>['source'=>'payment_resource','payment_id'=>'pay_fixture_'.$id,'gross_minor'=>$gross,'fee_minor'=>$fee,'net_minor'=>$gross-$fee]];
        $t->execute([$id,$id,'pay_fixture_'.$id,$gross,json_encode($metadata)]);
        if($third)$pdo->prepare("INSERT INTO hotel_booking_expenses VALUES (?,'Environmental fee','Environmental fee',?)")->execute([$id,PayoutAccounting::decimal($third)]);
    }
    $pdo->exec("INSERT INTO booking_refunds VALUES (5,'hotel',1000,'PHP','pending')");
    $pdo->exec("INSERT INTO booking_cancellation_requests VALUES (1,'hotel',6,'pending','not_started','full_refund',0),(2,'hotel',9,'completed','not_applicable','deposit_non_refundable',100.00)");
    $pdo->exec("UPDATE payment_transactions SET metadata='{}' WHERE booking_id=7");
    $pdo->exec("INSERT INTO bookings VALUES (11,'boat',NULL,1,NULL,'completed','accepted',1000.00,1000.00,'')");
    $p->execute([11,'boat',11,'TB-FIXTURE-11','boat',1,'boat:1','Same display name','pending','1000.00']);
    $pdo->exec("INSERT INTO payment_transactions VALUES (11,'boat',11,'cash',NULL,100000,'PHP','paid','{}')");
    $contexts=bulkContexts($pdo);
    bulkCheck(ProviderBulkSettlement::ids(['2',1])===[1,2],'Selection locks in stable order');
    foreach([[],[1,1],[0],['1x'],[true],range(1,101)] as $invalid){
        $rejected=false;try {ProviderBulkSettlement::ids($invalid);}catch(RuntimeException $e){$rejected=true;}
        bulkCheck($rejected,'Reject invalid selection');
    }
    bulkReject($pdo,[1,3],$contexts,'Mixed canonical provider IDs with identical names');
    bulkReject($pdo,[1,11],$contexts,'Different provider types sharing the same numeric ID');
    bulkReject($pdo,[1,999],$contexts,'Missing booking');
    bulkReject($pdo,[4],$contexts,'Incomplete booking');
    bulkReject($pdo,[5],$contexts,'Active refund');
    bulkReject($pdo,[6],$contexts,'Unresolved cancellation');
    bulkReject($pdo,[7],$contexts,'Unverified payment fee');
    $bad=$contexts;$bad[1]='forged';bulkReject($pdo,[1],$bad,'Forged financial context');
    $pdo->exec('UPDATE hotel_room_bookings SET hotel_resort_id=2 WHERE hotel_booking_id=8');
    bulkReject($pdo,[8],$contexts,'Changed canonical provider');
    $pdo->exec('DELETE FROM hotel_room_bookings WHERE hotel_booking_id=8');
    bulkReject($pdo,[8],$contexts,'Missing underlying booking');
    $pdo->exec("DELETE FROM fixture_providers WHERE type='boat' AND id=1");
    bulkReject($pdo,[11],$contexts,'Missing canonical provider');
    $pdo->exec("INSERT INTO fixture_providers VALUES ('boat',1)");
    $pdo->exec('UPDATE payment_transactions SET amount_minor=100001 WHERE booking_id=10');
    bulkReject($pdo,[10],$contexts,'Changed payment ledger');
    $paymentsBefore=$pdo->query('SELECT * FROM payment_transactions ORDER BY payment_transaction_id')->fetchAll(PDO::FETCH_ASSOC);
    $refundsBefore=$pdo->query('SELECT * FROM booking_refunds')->fetchAll(PDO::FETCH_ASSOC);
    bulkReject($pdo,[1,2],$contexts,'Audit failure rolls back the batch',false);
    bulkReject($pdo,[1,2],$contexts,'Oversized note rolls back the batch',true,str_repeat('x',480));
    bulkCheck((int)$pdo->query("SELECT COUNT(*) FROM provider_payouts WHERE status='settled'")->fetchColumn()===0,'Failed batches never partially settle');
    if($mysql && !$temporary){
        $pdo->beginTransaction();$pdo->query('SELECT payout_id FROM provider_payouts WHERE payout_id=1 FOR UPDATE');
        $proc=proc_open([PHP_BINARY,__FILE__,'--worker',$database],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
        if(!is_resource($proc))throw new RuntimeException('Concurrency worker could not start.');
        fclose($pipes[0]);$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($proc);$pdo->rollBack();
        bulkCheck($exit===0 && $out==='LOCKED','Independent connection cannot settle a locked booking: '.$out.$err);
    }elseif(!$mysql){
        $pdo->beginTransaction();$pdo->exec('UPDATE provider_payouts SET provider_name=provider_name WHERE payout_id=1');
        $proc=proc_open([PHP_BINARY,__FILE__,'--sqlite-worker',$sqlitePath],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
        if(!is_resource($proc))throw new RuntimeException('Concurrency worker could not start.');
        fclose($pipes[0]);$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($proc);$pdo->rollBack();
        bulkCheck($exit===0 && $out==='LOCKED','Independent SQLite connection cannot record a concurrent settlement: '.$out.$err);
    }
    $rows=bulkRun($pdo,[2,1],$contexts);
    bulkCheck(ProviderBulkSettlement::total($rows)===410252,'Exact example total PHP 4,102.52');
    $records=$pdo->query('SELECT * FROM provider_payouts WHERE payout_id IN (1,2) ORDER BY payout_id')->fetchAll(PDO::FETCH_ASSOC);
    bulkCheck(PayoutAccounting::minor($records[0]['net_amount'])===210790 && PayoutAccounting::minor($records[1]['net_amount'])===199462,'Each snapshot records the exact independent payout');
    bulkCheck($records[0]['status']==='settled' && $records[1]['status']==='settled','Both bookings settle together');
    preg_match('/BULK-[a-f0-9]{24}/',$records[0]['settlement_note'],$batch);
    bulkCheck(isset($batch[0]) && str_contains($records[1]['settlement_note'],$batch[0]),'Shared batch link on each record');
    $audit=json_decode($pdo->query('SELECT payload FROM fixture_audit')->fetchColumn(),true);
    bulkCheck($audit['provider']==='hotel:1' && count($audit['items'])===2 && $audit['total_minor']===410252 && $audit['admin_id']===1,'Audit contains provider, bookings, exact amounts, total and administrator');
    bulkReject($pdo,[1,2],$contexts,'Duplicate batch submission');
    bulkReject($pdo,[1],$contexts,'Individual settlement cannot repeat a bulk payout');
    bulkReject($pdo,[2,3],$contexts,'Already settled booking in another selection');
    $single=bulkRun($pdo,[3],$contexts,false);
    bulkCheck(ProviderBulkSettlement::total($single)===296485,'Existing individual workflow still settles another provider');
    $retained=bulkRun($pdo,[9],$contexts,false);
    bulkCheck(ProviderBulkSettlement::total($retained)===10000,'Eligible cancellation retention remains supported');
    $tour=bulkRun($pdo,[11],$contexts,false);
    bulkCheck(ProviderBulkSettlement::total($tour)===100000,'Tour-domain provider and offline payment settlement remain supported');
    bulkCheck((int)$pdo->query('SELECT COUNT(*) FROM payment_transactions')->fetchColumn()===11 && (int)$pdo->query('SELECT COUNT(*) FROM booking_refunds')->fetchColumn()===1,'Payments and refunds remain intact');
    bulkCheck($paymentsBefore===$pdo->query('SELECT * FROM payment_transactions ORDER BY payment_transaction_id')->fetchAll(PDO::FETCH_ASSOC),'Every payment field is unchanged by settlements');
    bulkCheck($refundsBefore===$pdo->query('SELECT * FROM booking_refunds')->fetchAll(PDO::FETCH_ASSOC),'Every refund field is unchanged by settlements');
    echo "Passed $checks provider bulk settlement checks (".($temporary?'connection-scoped MySQL/InnoDB temporary fixtures':($mysql?'isolated MySQL/InnoDB':'offline SQLite')).").\n";
} finally {
    if(isset($pdo) && $pdo->inTransaction())$pdo->rollBack();
    if($created && $server instanceof PDO && preg_match('/^itour_bulk_test_[a-f0-9]{16}$/D',(string)$database)) {
        $pdo=null;$server->exec("DROP DATABASE `$database`");echo "Removed isolated test schema.\n";
    }
    if($sqlitePath!==null && is_file($sqlitePath)){
        unset($p,$b,$t,$pdo);
        if(!unlink($sqlitePath))throw new RuntimeException('Could not remove the fixture database.');
    }
}
