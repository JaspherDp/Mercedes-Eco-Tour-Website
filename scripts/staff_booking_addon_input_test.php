<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../php/staff_booking_addon_picker.php';
require_once __DIR__ . '/../php/booking_addons_helper.php';
function staffCheck(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
function staffReject(callable $fn): void { try { $fn(); } catch (InvalidArgumentException $error) { return; } throw new RuntimeException('Invalid selection accepted.'); }
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE booking_addon_catalog (addon_id INTEGER PRIMARY KEY, owner_role TEXT, owner_id INTEGER, hotel_resort_id INTEGER, name TEXT, price NUMERIC, is_active INTEGER)');
$pdo->exec("INSERT INTO booking_addon_catalog VALUES (1,'admin',10,NULL,'Kayaking',500,1),(2,'operator',20,NULL,'Lunch',125.50,1),(3,'hotel_admin',30,40,'Breakfast',150,1),(4,'admin',10,NULL,'Hidden',20,0),(5,'operator',21,NULL,'Private',5,1)");
$empty = ['expense_type'=>[''],'expense_name'=>[''],'quantity'=>['1'],'unit_price'=>[''],'note'=>['']];
foreach ([['admin',10,0,1,500],['operator',20,0,2,125.5],['hotel_admin',30,40,3,150]] as [$role,$owner,$hotel,$id,$price]) {
    $input = $empty;
    if ($role === 'hotel_admin') { $input['notes']=$input['note']; unset($input['note']); }
    $input['saved_addon_id'] = [(string)$id]; $input['saved_addon_quantity'] = [$id=>'3'];
    $merged = StaffBookingAddonInput($pdo,$role,$owner,$hotel,$input);
    staffCheck(count($merged['expense_type'])===1 && $merged['quantity']===[3], 'Saved-only submission must skip the untouched manual row.');
    staffCheck((float)$merged['unit_price'][0] === (float)$price, 'Saved price must come from the server catalog.');
    if ($role !== 'hotel_admin') staffCheck(BookingAddonsValidateRows($merged)['total'] === $price*3, 'Saved quantity must affect the charge total.');
    $input['expense_type'] = [$role === 'hotel_admin' ? 'Food' : 'food'];
    $input['expense_name'] = ['Manual snack']; $input['unit_price']=['10.25']; $input['quantity']=['2'];
    $mixed = StaffBookingAddonInput($pdo,$role,$owner,$hotel,$input);
    staffCheck($mixed['expense_name'][0] === 'Manual snack' && count($mixed['expense_name']) === 2, 'Manual and saved items must both remain in the batch.');
    if ($role !== 'hotel_admin') staffCheck(abs(BookingAddonsValidateRows($mixed)['total'] - ($price*3+20.5)) < 0.001, 'Mixed charges must count each item once.');
}
staffCheck(StaffBookingAddonInput($pdo,'admin',10,0,$empty)===$empty,'Manual-only input must remain unchanged.');
foreach ([['admin',10,0,[2]],['operator',20,0,[1]],['hotel_admin',30,41,[3]],['admin',10,0,[4]],['operator',20,0,[5]],['admin',10,0,[1,1]]] as [$role,$owner,$hotel,$ids]) {
    staffReject(fn()=>StaffBookingAddonInput($pdo,$role,$owner,$hotel,['saved_addon_id'=>$ids]));
}
foreach (['0','1.5','1001','bad'] as $quantity) staffReject(fn()=>StaffBookingAddonInput($pdo,'admin',10,0,['saved_addon_id'=>[1],'saved_addon_quantity'=>[1=>$quantity]]));
staffReject(fn()=>StaffBookingAddonInput($pdo,'admin',10,0,['saved_addon_id'=>str_repeat('x',26)]));
$partial=$empty; $partial['expense_name']=['Incomplete']; $partial['saved_addon_id']=[1];
staffReject(fn()=>BookingAddonsValidateRows(StaffBookingAddonInput($pdo,'admin',10,0,$partial)));
$changedQty=$empty; $changedQty['quantity']=['2']; $changedQty['saved_addon_id']=[1];
staffReject(fn()=>BookingAddonsValidateRows(StaffBookingAddonInput($pdo,'admin',10,0,$changedQty)));
$tooMany=array_map(fn($v)=>array_fill(0,25,$v),['expense_type'=>'food','expense_name'=>'Manual','quantity'=>'1','unit_price'=>'1','note'=>'']);
$tooMany['saved_addon_id']=[1]; staffReject(fn()=>StaffBookingAddonInput($pdo,'admin',10,0,$tooMany));
$bad=$empty; $bad['saved_addon_id']=[1]; $bad['note']=[]; staffReject(fn()=>StaffBookingAddonInput($pdo,'admin',10,0,$bad));
echo "PASS: saved-only and mixed charges in all panels, manual-only compatibility, server prices, quantities, owner/property isolation, hidden options, duplicates, partial rows, and combined limits.\n";
