<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
// Reuses the offline reconciliation fixtures; never loads the application DB or calls PayMongo.
require __DIR__ . '/paymongo_mode_fixture_test.php';
require_once __DIR__ . '/../php/booking_addon_catalog.php';
require_once __DIR__ . '/../php/payment_receipt_breakdown.php';
function catalogCheck(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
function catalogReject(callable $operation): void { try { $operation(); } catch (InvalidArgumentException|DomainException $error) { return; } throw new RuntimeException('Invalid catalog operation was accepted.'); }
function catalogFixture(PDO $pdo): void {
    $pdo->exec('CREATE TABLE booking_addon_catalog (addon_id INTEGER PRIMARY KEY AUTOINCREMENT, owner_role TEXT, owner_id INTEGER, hotel_resort_id INTEGER, name TEXT, price NUMERIC, is_active INTEGER)');
    $pdo->exec('CREATE TABLE booking_expenses (id INTEGER PRIMARY KEY, booking_id INTEGER, expense_type TEXT, expense_name TEXT, quantity INTEGER, unit_price NUMERIC, amount NUMERIC, note TEXT)');
    $pdo->exec('CREATE TABLE hotel_booking_expenses (expense_id INTEGER PRIMARY KEY, hotel_booking_id INTEGER, hotel_resort_id INTEGER, expense_type TEXT, expense_name TEXT, quantity INTEGER, unit_price NUMERIC, total_amount NUMERIC, notes TEXT, payment_status TEXT)');
}
$pdo = modeDatabase(); catalogFixture($pdo);
$admin = AddonCatalogSave($pdo, 'admin', 1, 0, ['name'=>'Packed lunch','price'=>'125.50']);
$operator = AddonCatalogSave($pdo, 'operator', 2, 0, ['name'=>'Snorkeling gear','price'=>'200.25']);
$hotel = AddonCatalogSave($pdo, 'hotel_admin', 3, 10, ['name'=>'Breakfast','price'=>'150']);
$otherHotel = AddonCatalogSave($pdo, 'hotel_admin', 4, 20, ['name'=>'Spa','price'=>'600']);
foreach ([['admin',1,0,$admin],['operator',2,0,$operator],['hotel_admin',3,10,$hotel]] as [$role,$ownerId,$propertyId,$expectedId]) {
    $presets = AddonCatalogStaffAvailable($pdo,$role,$ownerId,$propertyId);
    catalogCheck(count($presets) === 1 && $presets[0]['addon_id'] === $expectedId, 'Staff presets must contain only their own active catalog.');
    catalogCheck(array_keys($presets[0]) === ['addon_id','name','price'], 'Staff preset payload should expose only charge fields.');
}
catalogCheck(AddonCatalogStaffAvailable($pdo,'hotel_admin',3,20) === [], 'Staff presets must not cross property boundaries.');
catalogCheck(AddonCatalogStaffAvailable($pdo,'operator',99) === [], 'Staff presets must not leak another operator catalog.');
catalogReject(fn()=>AddonCatalogStaffAvailable($pdo,'tourist',1));
foreach (['boat','tourguide','package'] as $domain) {
    $expected = $domain === 'package' ? [$admin,$operator] : [$admin];
    catalogCheck(array_map('intval', array_column(AddonCatalogAvailable($pdo,$domain),'addon_id')) === $expected, 'Operator extras must be package-only; admin extras remain on all tour types.');
    $selection = [['addon_id'=>$admin,'quantity'=>3,'price'=>'.01']];
    if ($domain === 'package') $selection[] = ['addon_id'=>$operator,'quantity'=>2,'name'=>'Tampered'];
    $batch = AddonCatalogResolve($pdo,$domain,0,$selection);
    catalogCheck((float)$batch['total'] === ($domain === 'package' ? 777.0 : 376.5) && $batch['rows'][0]['name'] === 'Packed lunch', 'Names and prices must come from the server.');
    if ($domain !== 'package') catalogReject(fn()=>AddonCatalogResolve($pdo,$domain,0,[['addon_id'=>$operator,'quantity'=>1]]));
    catalogReject(fn()=>AddonCatalogResolve($pdo,$domain,0,[['addon_id'=>$hotel,'quantity'=>1]]));
}
catalogCheck(count(AddonCatalogAvailable($pdo,'hotel',10))===1 && (int)AddonCatalogAvailable($pdo,'hotel',10)[0]['addon_id']===$hotel, 'Hotel catalog must be restricted to the selected property.');
catalogReject(fn()=>AddonCatalogResolve($pdo,'hotel',10,[['addon_id'=>$otherHotel,'quantity'=>1]]));
catalogReject(fn()=>AddonCatalogResolve($pdo,'hotel',10,[['addon_id'=>$admin,'quantity'=>1]]));
catalogReject(fn()=>AddonCatalogResolve($pdo,'hotel',10,[['addon_id'=>$operator,'quantity'=>1]]));
catalogReject(fn()=>AddonCatalogSave($pdo,'operator',2,0,['addon_id'=>$admin,'name'=>'Stolen','price'=>'1']));
catalogReject(fn()=>AddonCatalogSave($pdo,'hotel_admin',3,20,['addon_id'=>$hotel,'name'=>'Wrong property','price'=>'1']));
catalogReject(fn()=>AddonCatalogSave($pdo,'tourist',7,0,['name'=>'Invalid','price'=>'1']));
foreach (['0','-1','1.001','1000000.01','abc'] as $price) catalogReject(fn()=>AddonCatalogSave($pdo,'admin',1,0,['name'=>'Invalid','price'=>$price]));
foreach ([0,101,1.5,'abc'] as $quantity) catalogReject(fn()=>AddonCatalogResolve($pdo,'boat',0,[['addon_id'=>$admin,'quantity'=>$quantity]]));
catalogReject(fn()=>AddonCatalogResolve($pdo,'boat',0,[['addon_id'=>$admin],['addon_id'=>$admin]]));
catalogReject(fn()=>AddonCatalogResolve($pdo,'boat',0,str_repeat('x',26)));
$snapshot = AddonCatalogResolve($pdo,'boat',0,[['addon_id'=>$admin,'quantity'=>2]]);
AddonCatalogSave($pdo,'admin',1,0,['addon_id'=>$admin,'name'=>'New price','price'=>'999','is_active'=>0]);
catalogCheck(AddonCatalogStaffAvailable($pdo,'admin',1) === [], 'Hidden options must not be offered as staff charge presets.');
$noCatalog = new PDO('sqlite::memory:');
$noCatalog->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
catalogCheck(AddonCatalogStaffAvailable($noCatalog,'admin',1) === [], 'Manual entry must remain usable without a catalog table.');
catalogReject(fn()=>AddonCatalogResolve($pdo,'boat',0,[['addon_id'=>$admin]]));
catalogCheck((float)$snapshot['total'] === 251.0 && $snapshot['rows'][0]['name'] === 'Packed lunch', 'Existing checkout snapshots must survive price/name edits and archiving.');
$oldMode = getenv('PAYMONGO_MODE');
try {
    putenv('PAYMONGO_MODE=test');
    foreach (['boat','tourguide','package','hotel'] as $domain) foreach (['partial','full'] as $paymentType) {
        $pdo=modeDatabase(); catalogFixture($pdo);
        $payload = ['booking_date'=>'2026-11-01','location'=>'Fixture','package_name'=>'Fixture','phone_number'=>'','operator_id'=>1,'tour_type'=>'same-day','tour_range'=>'','jump_off_port'=>'','preferred_resource'=>'','boat_id'=>1,'guide_id'=>1,'num_adults'=>1,'num_children'=>0,'hotel_resort_id'=>10,'hotel_room_id'=>1,'room_type'=>'Fixture','checkin_date'=>'2026-11-01','checkout_date'=>'2026-11-02','nights'=>1,'adults'=>1,'children'=>0,'first_name'=>'Fixture','last_name'=>'Guest','email'=>'fixture@example.test','special_request'=>'','unit_price'=>5000,
            'catalog_addons'=>$snapshot,'price_breakdown'=>['version'=>1,'rows'=>[PaymentReceiptLine('Saved base charge',5000)]]];
        $amount=$paymentType==='partial'?105020:525100;
        $pdo->prepare("INSERT INTO booking_checkout_drafts (booking_draft_id,tourist_id,booking_domain,payload,total_minor,amount_minor,payment_type,status) VALUES (1,7,?,?,525100,?,?,'pending')")->execute([$domain,json_encode($payload),$amount,$paymentType]);
        $checkout=modeTransaction($pdo,'test',$domain,-1,$amount,['source'=>'booking_checkout','booking_draft_id'=>'1']);
        if ($domain === 'boat' && $paymentType === 'partial') {
            $pdo->exec("CREATE TRIGGER fail_catalog_expense BEFORE INSERT ON booking_expenses BEGIN SELECT RAISE(ABORT, 'Fixture expense failure'); END");
            $failed = false;
            try { PaymentReconciler::reconcilePaidCheckout($pdo,$checkout); } catch (PDOException $error) { $failed = true; }
            catalogCheck($failed && !$pdo->inTransaction() && (int)$pdo->query('SELECT COUNT(*) FROM bookings')->fetchColumn() === 0, 'Expense failures must roll back the booking and payment update atomically.');
            catalogCheck($pdo->query('SELECT status FROM payment_transactions')->fetchColumn() === 'pending', 'Failed finalization must remain retryable.');
            $pdo->exec('DROP TRIGGER fail_catalog_expense');
        }
        $result=PaymentReconciler::reconcilePaidCheckout($pdo,$checkout);
        $table=$domain==='hotel'?'hotel_room_bookings':'bookings'; $expenseTable=$domain==='hotel'?'hotel_booking_expenses':'booking_expenses';
        $row=$pdo->query('SELECT * FROM '.$table)->fetch(PDO::FETCH_ASSOC);
        catalogCheck(abs((float)$row[$domain==='hotel'?'total_amount':'grand_total']-5251)<.009,'Add-ons must be included exactly once in the booking total.');
        catalogCheck(abs((float)$row['remaining_balance']-(5251-$amount/100))<.009,'Deposit and balance must include add-ons.');
        $expenses=$pdo->query('SELECT * FROM '.$expenseTable)->fetchAll(PDO::FETCH_ASSOC);
        catalogCheck(count($expenses)===1 && (float)$expenses[0]['unit_price']===125.5 && (int)$expenses[0]['quantity']===2,'Finalization must save original selected prices and quantity.');
        $again=PaymentReconciler::reconcilePaidCheckout($pdo,$checkout);
        catalogCheck($again['idempotent'] && (int)$pdo->query('SELECT COUNT(*) FROM '.$expenseTable)->fetchColumn()===1,'Repeated paid events must never duplicate add-ons.');
        if($domain!=='hotel') {
            $pdo->exec('ALTER TABLE booking_checkout_drafts ADD COLUMN created_at TEXT');
            $breakdown=PaymentReceiptTourBreakdown($pdo,$row,5000);
            catalogCheck($breakdown['note']==='' && count($breakdown['rows'])===1 && (float)$breakdown['rows'][0]['amount']===5000.0,'Receipt base charges must exclude separately listed add-ons without an artificial adjustment.');
        }
    }
} finally { putenv($oldMode===false?'PAYMONGO_MODE':'PAYMONGO_MODE='.$oldMode); }
echo "PASS: catalog ownership, property and booking-type isolation, price tampering, quantities, archived options, saved prices, all booking types, deposits, full payments, receipt totals, and duplicate-event protection.\n";
