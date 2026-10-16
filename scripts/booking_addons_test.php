<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../php/booking_addons_helper.php';
function addonCheck(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
function addonReject(callable $operation): void
{
    try { $operation(); } catch (Throwable $error) { return; }
    throw new RuntimeException('Invalid add-on operation was accepted.');
}
$input = ['expense_type' => ['food', 'others'], 'expense_name' => ['Packed lunch', 'Snorkeling gear'], 'quantity' => ['3', '2'], 'unit_price' => ['125.50', '200.25'], 'note' => ['Vegetarian', 'Two sets']];
$batch = BookingAddonsValidateRows($input);
addonCheck(abs($batch['total'] - 777.00) < 0.001, 'Total must use quantity times unit price for every item.');
addonCheck($batch['rows'][0]['name'] === 'Packed lunch' && $batch['rows'][1]['quantity'] === 2, 'Descriptions and quantities must be preserved.');
$legacy = BookingAddonsValidateRows(['expense_type' => 'food', 'amount' => '90.25', 'note' => 'Existing caller']);
addonCheck($legacy['total'] === 90.25 && $legacy['rows'][0]['quantity'] === 1, 'Single-charge callers must remain compatible.');
foreach ([['quantity', ['0', '2']], ['quantity', ['1.5', '2']], ['unit_price', ['-1', '2']], ['unit_price', ['0.001', '2']], ['expense_name', ['', 'Gear']], ['expense_type', ['invalid', 'others']], ['note', ['Incomplete row']], ['quantity', ['1001', '2']]] as [$field, $bad]) addonReject(static fn() => BookingAddonsValidateRows(array_merge($input, [$field => $bad])));
addonReject(static fn() => BookingAddonsValidateRows(array_merge($input, ['unit_price' => ['10000000','1']])));
$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('CREATE TABLE bookings (booking_id INTEGER PRIMARY KEY, status TEXT, is_complete TEXT, grand_total DECIMAL, payment_amount DECIMAL, remaining_balance DECIMAL, is_paid INTEGER, updated_at TEXT)');
$pdo->exec('CREATE TABLE booking_expenses (id INTEGER PRIMARY KEY AUTOINCREMENT, booking_id INTEGER, expense_type TEXT, expense_name TEXT, quantity INTEGER, unit_price DECIMAL, amount DECIMAL, note TEXT)');
$pdo->exec("INSERT INTO bookings VALUES (42,'accepted','uncomplete',1000,1000,0,1,NULL)");
$result = BookingAddonsSave($pdo,42,$batch);
addonCheck((float)$result['grand_total'] === 1777.0 && (float)$result['remaining_balance'] === 777.0 && (float)$result['payment_amount'] === 1000.0, 'Total and unpaid balance must rise equally without changing payments.');
addonCheck((int)$pdo->query('SELECT is_paid FROM bookings')->fetchColumn() === 0, 'New add-ons must clear fully-paid status.');
addonCheck((int)$pdo->query('SELECT COUNT(*) FROM booking_expenses')->fetchColumn() === 2, 'Each add-on must be a separate charge.');
$pdo->exec("UPDATE bookings SET is_complete = 'completed'");
addonReject(static fn() => BookingAddonsSave($pdo,42,$batch));
$pdo->exec("UPDATE bookings SET is_complete = 'uncomplete'");
$pdo->exec("CREATE TRIGGER fail_addon BEFORE INSERT ON booking_expenses WHEN NEW.expense_type = 'others' BEGIN SELECT RAISE(ABORT, 'Simulated second item failure'); END");
addonReject(static fn() => BookingAddonsSave($pdo,42,$batch));
addonCheck((int)$pdo->query('SELECT COUNT(*) FROM booking_expenses')->fetchColumn() === 2, 'Failed batches must roll back earlier inserts.');
addonCheck((float)$pdo->query('SELECT grand_total FROM bookings')->fetchColumn() === 1777.0, 'Failed batches must not change accounting totals.');
echo "PASS: named items, quantity pricing, decimal totals, legacy callers, input validation, booking eligibility, separate charges, accounting balance, and batch rollback.\n";
