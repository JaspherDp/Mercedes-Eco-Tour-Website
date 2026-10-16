<?php
declare(strict_types=1);

namespace RefundRoutingFixture;

require_once __DIR__ . '/../payments/PaymentHelper.php';

// Run the real service with an in-memory transport. No requests leave this test.
function itourSecureDnsResolve(string $host, string $ca): array { return ['192.0.2.1']; }
function curl_init(string $url): object { return (object)['url' => $url, 'options' => []]; }
function curl_setopt(object $handle, int $option, mixed $value): bool { $handle->options[$option] = $value; return true; }
function curl_exec(object $handle): string {
    $GLOBALS['refund_fixture_requests'][] = $handle;
    return json_encode(['data' => ['id' => 'ref_fixture', 'attributes' => ['status' => 'processing']]]);
}
function curl_getinfo(object $handle, int $option): int { return 200; }
function curl_errno(object $handle): int { return 0; }
function curl_error(object $handle): string { return ''; }
function curl_close(object $handle): void {}

$source = file_get_contents(__DIR__ . '/../payments/PayMongoService.php');
$source = preg_replace('/^<\?php\s*declare\(strict_types=1\);/', '', $source);
$source = preg_replace('/^require_once .*;\R/m', '', $source);
eval('namespace RefundRoutingFixture; use \\PaymentHelper; use \\RuntimeException; use \\InvalidArgumentException; use \\LogicException; use \\JsonException; use \\Throwable; ' . $source);

function check(bool $condition, string $message): void {
    if (!$condition) throw new \RuntimeException($message);
}

$service = new PayMongoService('sk_test_fixture', 'pk_test_fixture');
$service->createQrPhRefund('pay_fixture', 100, 'requested_by_customer', 'Fixture', 'qr-fixture-1');
$service->createRefund('pay_fixture', 100, 'others', 'Fixture', 'standard-fixture-1');
$requests = $GLOBALS['refund_fixture_requests'];
check(count($requests) === 2, 'Unexpected request count.');
check($requests[0]->url === 'https://refunds-api.paymongo.com/v1/refunds', 'QR Ph used the wrong API host.');
check($requests[1]->url === 'https://api.paymongo.com/v1/refunds', 'Standard refund host changed.');
$payload = json_decode($requests[0]->options[CURLOPT_POSTFIELDS], true)['data']['attributes'];
check($payload['payment_id'] === 'pay_fixture' && $payload['amount'] === 100, 'Payment or amount changed.');
check(in_array('Idempotency-Key: qr-fixture-1', $requests[0]->options[CURLOPT_HTTPHEADER], true), 'Idempotency key lost.');
check($requests[0]->options[CURLOPT_SSL_VERIFYPEER] === true, 'TLS verification disabled.');
check($requests[0]->options[CURLOPT_RESOLVE] === ['refunds-api.paymongo.com:443:192.0.2.1'], 'DNS override used the wrong host.');

$requestMethod = new \ReflectionMethod($service, 'request');
$requestMethod->setAccessible(true);
try {
    $requestMethod->invoke($service, 'POST', '/v1/refunds', [], 'bad-host', 'https://example.invalid');
    throw new \RuntimeException('An unapproved API host was accepted.');
} catch (\InvalidArgumentException) {}

echo "Refund routing tests passed; no network requests sent.\n";

if (!in_array('--mysql', $argv, true)) exit;
$host = \PaymentHelper::env('DB_HOST');
if (!in_array(strtolower($host), ['localhost', '127.0.0.1', '::1'], true)
    || \PaymentHelper::env('APP_ENV') !== 'development') {
    throw new \RuntimeException('MySQL regression requires a local development database.');
}
$pdo = new \PDO('mysql:host=' . $host . ';dbname=' . \PaymentHelper::env('DB_NAME') . ';charset=utf8mb4',
    \PaymentHelper::env('DB_USER'), \PaymentHelper::env('DB_PASSWORD'),
    [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_EMULATE_PREPARES => false]);
// A connection-scoped temporary table shadows the real table; no booking data is changed.
$pdo->exec("CREATE TEMPORARY TABLE booking_refunds (
    booking_refund_id INT PRIMARY KEY, provider_refund_id VARCHAR(100) NULL,
    status VARCHAR(20), failure_code VARCHAR(100), failure_message VARCHAR(500),
    provider_response LONGTEXT, provider_http_status INT, provider_diagnostic LONGTEXT
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
$pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_general_ci");
$pdo->exec("INSERT INTO booking_refunds (booking_refund_id,status) VALUES (1,'initiating')");
$page = file_get_contents(__DIR__ . '/../admin/adpaymenttransactions.php');
check(preg_match('/\$pdo->prepare\("(UPDATE booking_refunds SET provider_refund_id=COALESCE\([^"\r\n]+)"\)/', $page, $matches) === 1, 'Failure update query not found.');
$update = $pdo->prepare($matches[1]);
foreach ([null, 'ref_validated', null] as $id) {
    $update->execute([$id, 'parameter_invalid', 'Refunds are not allowed for payments with source type qrph.', '{}', 400, '{}', 1]);
    $row = $pdo->query('SELECT * FROM booking_refunds WHERE booking_refund_id=1')->fetch(\PDO::FETCH_ASSOC);
    check($row['status'] === 'failed' && (int)$row['provider_http_status'] === 400, 'Rejection not saved as failed.');
    check($row['provider_refund_id'] === ($id ?? ($expectedId ?? null)), 'Provider ID was lost or incorrectly replaced.');
    $expectedId = $row['provider_refund_id'];
}
echo "Mixed-collation MySQL rejection tests passed; only a temporary table was changed.\n";
