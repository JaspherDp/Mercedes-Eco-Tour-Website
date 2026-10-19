<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/../payments/PaymentHelper.php';
if (!in_array(PaymentHelper::env('DB_HOST'), ['localhost','127.0.0.1','::1'], true)) throw new RuntimeException('Localhost only');
require __DIR__ . '/../php/db_connection.php';
require __DIR__ . '/../php/tour_resource_availability_helper.php';
// Require preexisting local schema: never create permanent tables in this check.
foreach (['package_daily_slots','booking_cancellation_requests'] as $table) $pdo->query('SELECT * FROM ' . $table . ' LIMIT 0');
$source = file_get_contents(__DIR__ . '/../php/respond_provider_cancellation.php');
if (!preg_match('/ensureBookingCancellationRequestsTable\(\$pdo\);.*?packageSlotEnsureSchema\(\$pdo\);\s*\$pdo->beginTransaction\(\);/s', $source)) throw new RuntimeException('Schema preflight must precede transaction');
ensureBookingCancellationRequestsTable($pdo);
packageSlotEnsureSchema($pdo);
$pdo->exec('CREATE TEMPORARY TABLE provider_decision_probe (id INT PRIMARY KEY, state VARCHAR(30)) ENGINE=InnoDB');
$pdo->exec("INSERT INTO provider_decision_probe VALUES (1,'original')");
foreach ([false, true] as $commit) {
    $pdo->beginTransaction();
    $pdo->exec("UPDATE provider_decision_probe SET state='rescheduled' WHERE id=1");
    // Real availability path re-enters schema setup; it must retain the transaction.
    packageSlotStatus($pdo, 0, '2099-01-01');
    if (!$pdo->inTransaction()) throw new RuntimeException('Availability implicitly committed the decision');
    if ($commit) $pdo->commit(); else $pdo->rollBack();
    $actual = $pdo->query('SELECT state FROM provider_decision_probe WHERE id=1')->fetchColumn();
    if ($actual !== ($commit ? 'rescheduled' : 'original')) throw new RuntimeException('Commit/rollback did not preserve expected state');
}
echo "PASS: actual MySQL availability retains transaction; rollback and commit work after schema preflight. Only temporary probe rows changed.\n";
