<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__.'/../php/admin_walkin_manifest.php';
require_once __DIR__.'/../php/session_security.php';
require_once __DIR__.'/../php/booking_reference_helper.php';
$checks=0;
function manifestCheck(bool $condition,string $message):void {global $checks;$checks++;if(!$condition)throw new RuntimeException($message);}
function manifestReject(callable $work):void {try{$work();}catch(Throwable $error){manifestCheck(true,'rejected');return;}manifestCheck(false,'Invalid manifest request was accepted.');}
$pdo=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$pdo->exec('CREATE TABLE bookings(booking_id INTEGER PRIMARY KEY,tourist_id INTEGER,walkin_guest_name TEXT,preferred_resource TEXT,status TEXT,is_complete TEXT,is_paid INTEGER,grand_total NUMERIC,payment_amount NUMERIC,remaining_balance NUMERIC,pax INTEGER,num_adults INTEGER,num_children INTEGER,booking_type TEXT,booking_date TEXT)');
$pdo->exec('CREATE TABLE booking_cancellation_requests(booking_domain TEXT,booking_id INTEGER,request_status TEXT)');
$pdo->exec('CREATE TABLE booking_tourists(id INTEGER PRIMARY KEY AUTOINCREMENT,booking_id INTEGER,full_name TEXT,gender TEXT,age INTEGER,address TEXT,country TEXT,region TEXT,province TEXT,city TEXT,barangay TEXT,postal_code TEXT,street TEXT,residence TEXT,phone_number TEXT,boat_number INTEGER)');
$today=(new DateTimeImmutable('now',new DateTimeZone('Asia/Manila')))->format('Y-m-d');
$insert=$pdo->prepare("INSERT INTO bookings VALUES(?,?,'Walk-in Guest','Boat | Walk-in booking','accepted','uncomplete',1,100,100,0,2,2,0,'boat',?)");
$insert->execute([10,null,$today]);$insert->execute([11,7,$today]);
$input=['booking_id'=>10,'action'=>'fetch_walkin_manifest'];
$fetch=static fn()=>AdminWalkinManifestRequest($pdo,$input,3);
$data=$fetch();
manifestCheck($data['tourists']===[] && $data['can_edit'] && $data['pax']===2,'Fresh paid walk-in must open an empty form with authoritative passenger count.');
$save=['booking_id'=>10,'action'=>'save_walkin_manifest','manifest_mode'=>'submit','full_name'=>['Guest One','Guest Two'],'gender'=>['female','male'],'age'=>['29','30'],'phone_number'=>['09123456789','09999999999'],'country'=>['Philippines','Philippines'],'region'=>['Bicol','Bicol'],'province'=>['Camarines Norte','Camarines Norte'],'city'=>['Mercedes','Mercedes'],'barangay'=>['San Roque','San Roque'],'postal_code'=>['4601','4601'],'street'=>['Main Street','Main Street'],'boat_number'=>['1','1']];
$result=AdminWalkinManifestRequest($pdo,$save,3);
manifestCheck($result['success'] && !$result['updated'] && count($fetch()['tourists'])===2,'Upload must store exactly the complete passenger list.');
manifestReject(fn()=>AdminWalkinManifestRequest($pdo,$save,3));
$edit=array_replace($save,['manifest_mode'=>'edit','full_name'=>['Updated Guest','Guest Two']]);
$result=AdminWalkinManifestRequest($pdo,$edit,3);
manifestCheck($result['updated'] && $fetch()['tourists'][0]['full_name']==='Updated Guest','Admin must edit an active walk-in even inside the tourist edit cutoff.');
manifestCheck(count($fetch()['tourists'])===2,'Editing must replace, not duplicate, passengers.');
manifestReject(fn()=>AdminWalkinManifestRequest($pdo,array_replace($edit,['booking_id'=>11]),3));
manifestReject(fn()=>AdminWalkinManifestRequest($pdo,$edit,0));
manifestReject(fn()=>AdminWalkinManifestRequest($pdo,array_replace($edit,['manifest_mode'=>'unknown']),3));
manifestReject(fn()=>AdminWalkinManifestRequest($pdo,array_replace($edit,['age'=>['121','30']]),3));
manifestReject(fn()=>AdminWalkinManifestRequest($pdo,array_replace($edit,['country'=>['','Philippines']]),3));
manifestReject(fn()=>AdminWalkinManifestRequest($pdo,array_replace($edit,['full_name'=>['Only One']]),3));
manifestReject(fn()=>AdminWalkinManifestRequest($pdo,array_replace($edit,['boat_number'=>['2','2']]),3));
$pdo->exec("INSERT INTO booking_cancellation_requests VALUES('tour',10,'decision_required')");
manifestReject($fetch);manifestReject(fn()=>AdminWalkinManifestRequest($pdo,$edit,3));
$pdo->exec('DELETE FROM booking_cancellation_requests');
$pdo->exec("UPDATE bookings SET is_complete='completed' WHERE booking_id=10");
manifestReject(fn()=>AdminWalkinManifestRequest($pdo,$edit,3));
$pdo->exec("UPDATE bookings SET is_complete='uncomplete',is_paid=0 WHERE booking_id=10");
manifestReject(fn()=>AdminWalkinManifestRequest($pdo,$edit,3));
$pdo->exec('UPDATE bookings SET is_paid=1 WHERE booking_id=10');
$pdo->exec("CREATE TRIGGER fail_manifest_insert BEFORE INSERT ON booking_tourists BEGIN SELECT RAISE(ABORT,'Simulated save failure'); END");
manifestReject(fn()=>AdminWalkinManifestRequest($pdo,$edit,3));
manifestCheck($fetch()['tourists'][0]['full_name']==='Updated Guest','A failed edit must restore the saved list.');
$pdo->exec('DROP TRIGGER fail_manifest_insert');
manifestCheck((int)$pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='tourist'")->fetchColumn()===0,'Manifest submission must not create accounts.');

// Exercise the real Admin JSON routing and CSRF check, with schema setup/logging stubbed.
function logActivity(...$arguments):void {}
$source=file_get_contents(__DIR__.'/../admin/adbookings.php');
$start=strpos($source,"if (\$_SERVER['REQUEST_METHOD'] === 'POST' && in_array(\$_POST['action'] ?? '', ['fetch_walkin_manifest','save_walkin_manifest'], true))");
$end=strpos($source,"require_once __DIR__ . '/../php/booking_cancellations_helper.php';",$start);
$route=substr($source,$start,$end-$start);
$route=str_replace('ensureBookingTouristManifestColumns($pdo);','',$route);
$route=str_replace('exit;','return;',$route);
$_SESSION=['admin_id'=>3];$_SERVER['REQUEST_METHOD']='POST';
$csrf=AppCsrfToken('admin','walkin_manifest');
$routeRun=static function(array $post)use($pdo,$route):array {$_POST=$post;http_response_code(200);ob_start();eval($route);$body=ob_get_clean();return [http_response_code(),json_decode($body,true)];};
[$code,$body]=$routeRun($input+['csrf_token'=>'invalid']);
manifestCheck($code===403 && !$body['success'],'Admin JSON requests must reject invalid CSRF.');
[$code,$body]=$routeRun($input+['csrf_token'=>$csrf]);
manifestCheck($code===200 && count($body['tourists'])===2,'Valid Admin JSON fetch must load the saved list.');
[$code,$body]=$routeRun(array_replace($input,['booking_id'=>11,'csrf_token'=>$csrf]));
manifestCheck($code===422 && !$body['success'],'Admin guest route must not expose an account-holder manifest.');

// Render actual actions before and after the manifest exists.
function getProfileImg($value):string{return 'img/profileicon.png';}
require_once __DIR__.'/../php/tour_resource_availability_helper.php';
$start=strpos($source,'function renderBookings(');$end=strpos($source,'function renderAdminCancellationRequests(',$start);eval(substr($source,$start,$end-$start));
$row=$pdo->query('SELECT * FROM bookings WHERE booking_id=10')->fetch(PDO::FETCH_ASSOC)+['booking_reference'=>'TB-TEST','t_full_name'=>'Walk-in Guest','t_email'=>'guest@example.test'];
$_SESSION['cancellation_admin_csrf']='synthetic';
$render=static function()use($row):string {ob_start();renderBookings([$row],'walkin');return ob_get_clean();};
$html=$render();manifestCheck(str_contains($html,'Edit Tourist List') && !str_contains($html,'Send Tourist Submission Alert'),'Submitted no-account walks must show Edit, without email reminder.');
$pdo->exec('DELETE FROM booking_tourists');
$html=$render();manifestCheck(str_contains($html,'Upload Tourist List') && !str_contains($html,'Edit Tourist List'),'Fresh no-account walks must show Upload.');
ob_start();renderBookings([$row],'accepted');$acceptedHtml=ob_get_clean();
manifestCheck(str_contains($acceptedHtml,'Submit Tourists') && !str_contains($acceptedHtml,'No Submission Yet'),'Accepted no-account walk-ins must offer a clickable submission-column action.');
manifestCheck(str_contains($acceptedHtml,"btn-tourists-submit add-tourist-btn-user") && str_contains($acceptedHtml,"data-manifest-mode='submit'"),'Submission-column action must use the shared form rather than the PDF viewer.');
manifestCheck(str_contains(file_get_contents(__DIR__.'/../php/profile.php'),"tourist_manifest_form.php") && str_contains($source,"tourist_manifest_form.php"),'Both pages must use the shared modal.');
manifestCheck(str_contains(file_get_contents(__DIR__.'/../php/profile.php'),'tourist-manifest-form.js') && str_contains($source,'tourist-manifest-form.js'),'Both pages must use the same form controller.');
echo "PASS: $checks Admin no-account manifest checks: upload/edit actions, exact pax, validation, ownership, CSRF, cutoff separation, cancellation/completion protection, rollback, no account creation and shared modal. No real records or emails used.\n";
