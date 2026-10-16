<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__.'/../payments/PaymentHelper.php';
if (!in_array(strtolower(PaymentHelper::env('DB_HOST')), ['localhost','127.0.0.1','::1'], true)
    || strtolower(PaymentHelper::env('APP_ENV')) !== 'development'
    || PaymentHelper::payMongoMode() !== 'test') {
    fwrite(STDERR, "Refusing migration: local development database with test payments required.\n"); exit(1);
}
require __DIR__.'/../php/db_connection.php';
$changes = [];
foreach (['bookings'] as $table) {
    $column = $pdo->query("SHOW COLUMNS FROM `$table` LIKE 'tourist_id'")->fetch();
    if (!$column || strtolower((string)$column['Type']) !== 'int unsigned') throw new RuntimeException('Unexpected tourist_id schema; review manually.');
    if ($column['Null'] !== 'YES') $changes[] = "ALTER TABLE `$table` MODIFY `tourist_id` INT UNSIGNED NULL";
}
foreach (['walkin_guest_name'=>'VARCHAR(150)', 'walkin_guest_email'=>'VARCHAR(190)', 'walkin_guest_address'=>'VARCHAR(255)'] as $name=>$type) {
    $column = $pdo->query("SHOW COLUMNS FROM bookings LIKE '$name'")->fetch();
    if (!$column) $changes[] = "ALTER TABLE bookings ADD COLUMN `$name` $type NULL";
}
foreach ($changes as $sql) {
    echo $sql, ";\n";
    if (in_array('--apply-local', $argv, true)) $pdo->exec($sql);
}
echo in_array('--apply-local', $argv, true) ? "Local schema ready. Existing account and booking data retained.\n" : "Dry run only. Use --apply-local for this local development database.\n";
