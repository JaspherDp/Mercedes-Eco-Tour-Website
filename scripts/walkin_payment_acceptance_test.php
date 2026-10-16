<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../php/walkin_booking_helper.php';
class WalkinSettlementDatabase extends PDO {
    public function prepare(string $query, array $options = []): PDOStatement|false {
        return parent::prepare(str_replace(' FOR UPDATE', '', $query), $options);
    }
}
function acceptanceCheck(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
function acceptanceReject(callable $work, string $message): void {
    try { $work(); } catch (Throwable $error) { acceptanceCheck(str_contains($error->getMessage(),$message), $error->getMessage()); return; }
    throw new RuntimeException('Expected rejection: '.$message);
}
$pdo = new WalkinSettlementDatabase('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$pdo->sqliteCreateFunction('NOW', static fn()=>date('Y-m-d H:i:s'));
$pdo->exec('CREATE TABLE bookings (booking_id INTEGER PRIMARY KEY, tourist_id INTEGER, booking_type TEXT, booking_reference TEXT, preferred_resource TEXT, status TEXT, is_complete TEXT, is_paid INTEGER, grand_total DECIMAL, remaining_balance DECIMAL, payment_amount DECIMAL, payment_method TEXT, updated_at TEXT)');
$pdo->exec('CREATE TABLE payment_transactions (payment_transaction_id INTEGER PRIMARY KEY, tourist_id INTEGER, booking_domain TEXT, booking_id INTEGER, booking_reference TEXT, provider TEXT, merchant_reference TEXT UNIQUE, idempotency_key TEXT UNIQUE, return_token TEXT, amount_minor INTEGER, currency TEXT, status TEXT, payment_method_type TEXT, metadata TEXT, paid_at TEXT)');
$pdo->exec("INSERT INTO bookings VALUES (1,7,'boat','TB26-TEST','Boat | Walk-in booking | Payment Option: Full Payment | Payment Status: Unpaid','pending','uncomplete',0,2880,2880,0,NULL,NULL)");
acceptanceReject(fn()=>WalkinBookingAcceptPaid($pdo,1), 'Full payment has not been verified');
acceptanceCheck(WalkinBookingPaymentStatus($pdo,1)['status']==='pending','Unpaid reservation must stay pending.');
$pdo->exec("INSERT INTO payment_transactions (booking_domain,booking_id,status) VALUES ('boat',1,'pending')");
acceptanceReject(fn()=>WalkinBookingCollectCash($pdo,1,3,'Test admin'), 'Cancel the active QR');
acceptanceCheck((float)WalkinBookingPaymentStatus($pdo,1)['payment_amount']===0.0,'Blocked cash payment must not change amounts.');
$pdo->exec("UPDATE payment_transactions SET status='cancelled'");
WalkinBookingCollectCash($pdo,1,3,'Test admin');
$paid = WalkinBookingAcceptPaid($pdo,1);
acceptanceCheck($paid['status']==='accepted' && (int)$paid['is_paid']===1 && (float)$paid['remaining_balance']===0.0,'Full cash settlement must accept the booking.');
WalkinBookingCollectCash($pdo,1,3,'Test admin');
acceptanceCheck((int)$pdo->query("SELECT COUNT(*) FROM payment_transactions WHERE provider='cash'")->fetchColumn()===1,'A retry cannot record the cash payment twice.');
$pdo->exec("UPDATE bookings SET status='cancelled',is_paid=0,remaining_balance=2880,payment_amount=0 WHERE booking_id=1");
acceptanceReject(fn()=>WalkinBookingCollectCash($pdo,1,3,'Test admin'),'cannot accept');
acceptanceReject(fn()=>WalkinBookingAcceptPaid($pdo,1),'Full payment has not been verified');

// Exercise the actual webhook acceptance gate against the same isolated DB.
$source = file_get_contents(__DIR__.'/../payments/PaymentReconciler.php');
$start = strpos($source,"                if (\$isAdminPayment\n                    && !empty(\$metadata['accept_walkin_after_payment'])");
$end = strpos($source,'                if ($isAdminPayment', $start+25);
acceptanceCheck($start!==false && $end!==false,'Webhook acceptance gate must be present.');
$gate = substr($source,$start,$end-$start);
$isAdminPayment=true; $metadata=['accept_walkin_after_payment'=>true]; $bookingId=1; $touristId=7; $newBalance=2880;
$touristBindingSql = 'tourist_id=?';
$pdo->exec("UPDATE bookings SET status='pending' WHERE booking_id=1");
eval($gate);
acceptanceCheck(WalkinBookingPaymentStatus($pdo,1)['status']==='pending','A QR intent must not accept an unpaid booking.');
$newBalance=0;
$pdo->exec('UPDATE bookings SET is_paid=1,remaining_balance=0,payment_amount=2880 WHERE booking_id=1');
eval($gate);
acceptanceCheck(WalkinBookingPaymentStatus($pdo,1)['status']==='accepted','Verified full QR payment must accept a pending walk-in.');
$pdo->exec("UPDATE bookings SET status='cancelled' WHERE booking_id=1");
eval($gate);
acceptanceCheck(WalkinBookingPaymentStatus($pdo,1)['status']==='cancelled','Payment verification must not revive a cancelled reservation.');
echo "PASS: unpaid reservations stay pending, paid-only acceptance, cash ledger idempotency, active QR protection, and webhook acceptance/cancellation guards.\n";

// Exercise the actual view predicate on an isolated database, including legacy
// unpaid walk-ins. No rows are deleted or marked paid to hide unfinished drafts.
$visible = static function () use ($pdo): array {
    return array_map('intval', $pdo->query('SELECT b.booking_id FROM bookings b WHERE '.WalkinBookingVisibleSql('b').' ORDER BY b.booking_id')->fetchAll(PDO::FETCH_COLUMN));
};
$pdo->exec("UPDATE bookings SET status='pending',is_complete='uncomplete',is_paid=0,remaining_balance=2880,payment_amount=0 WHERE booking_id=1");
acceptanceCheck($visible() === [], 'Unpaid walk-in must not appear in added bookings or reports.');
$pdo->exec("UPDATE bookings SET payment_amount=100,remaining_balance=2780 WHERE booking_id=1");
acceptanceCheck($visible() === [], 'Partial collection cannot complete a full-payment walk-in.');
$pdo->exec("UPDATE bookings SET is_paid=1,remaining_balance=0 WHERE booking_id=1");
acceptanceCheck($visible() === [], 'A paid flag alone cannot make an underpaid walk-in visible.');
acceptanceCheck(!WalkinBookingIsFullyPaid(WalkinBookingPaymentStatus($pdo,1)), 'Paid flag must agree with received amount.');
$pdo->exec("UPDATE bookings SET is_paid=0,payment_amount=0,remaining_balance=2880,preferred_resource='Online tourist reservation' WHERE booking_id=1");
acceptanceCheck($visible() === [1], 'Ordinary unpaid online bookings must remain visible.');
$pdo->exec("UPDATE bookings SET preferred_resource='Boat | Walk-in booking',status='cancelled',is_complete='cancelled' WHERE booking_id=1");
acceptanceCheck($visible() === [1], 'Historical cancelled reservations must remain accessible.');
$pdo->exec("UPDATE bookings SET status='pending',is_complete='uncomplete',is_paid=1,payment_amount=2880,remaining_balance=0 WHERE booking_id=1");
acceptanceCheck($visible() === [1], 'Verified full payment must reveal the same booking record.');
WalkinBookingAcceptPaid($pdo,1);
acceptanceCheck($visible() === [1], 'Paid acceptance must not create a second booking.');
acceptanceCheck((int)$pdo->query('SELECT COUNT(*) FROM bookings')->fetchColumn() === 1, 'Draft remains one persistent reservation for reconciliation.');
acceptanceReject(fn()=>WalkinBookingVisibleSql('b; DROP TABLE bookings'), 'Invalid booking alias');
$adminSource = file_get_contents(__DIR__.'/../admin/adbookings.php');
$guardStart = strpos($adminSource, "              if (\$bookingToAccept && stripos(");
$guardEnd = strpos($adminSource, '              if ($bookingToAccept) {', $guardStart);
acceptanceCheck($guardStart !== false && $guardEnd !== false, 'Manual acceptance must enforce payment before resource assignment.');
$manualGuard = substr($adminSource, $guardStart, $guardEnd-$guardStart);
$bookingToAccept = ['preferred_resource'=>'Boat | Walk-in booking','is_paid'=>0,'grand_total'=>2880,'remaining_balance'=>2880,'payment_amount'=>0];
acceptanceReject(static function () use ($manualGuard, $bookingToAccept): void { eval($manualGuard); }, 'Full payment has not been verified');
$bookingToAccept['preferred_resource'] = 'Online reservation';
eval($manualGuard);
$bookingToAccept = ['preferred_resource'=>'Boat | Walk-in booking','is_paid'=>1,'grand_total'=>2880,'remaining_balance'=>0,'payment_amount'=>2880];
eval($manualGuard);
echo "PASS: actual manual-accept handler rejects unpaid walk-ins and preserves paid walk-in and ordinary booking acceptance.\n";
echo "PASS: unpaid and partial walk-ins excluded, inconsistent paid flag rejected, verified payment reveals the same booking, ordinary bookings and cancelled history preserved.\n";
