<?php
// Keep PHP-generated and MySQL-generated timestamps consistent across every
// web entry point, CLI task, payment return, and webhook.
date_default_timezone_set('Asia/Manila');

require_once __DIR__ . '/../payments/PaymentHelper.php';

$host = PaymentHelper::env('DB_HOST');
$db   = PaymentHelper::env('DB_NAME');
$user = PaymentHelper::env('DB_USER');
$pass = PaymentHelper::env('DB_PASSWORD');
$charset = 'utf8mb4';

if ($host === '' || $db === '' || $user === '' || $pass === '') {
    error_log('Database configuration is incomplete.');
    http_response_code(503);
    die('Database service is temporarily unavailable.');
}

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
    $pdo->exec("SET time_zone = '+08:00'");
} catch (\PDOException $e) {
    error_log('Database connection failed with PDO error code ' . (string)$e->getCode() . '.');
    http_response_code(503);
    die('Database service is temporarily unavailable.');
}
?>
