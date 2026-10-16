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

require_once __DIR__ . '/../php/booking_refunds_helper.php';
function bookingRefundParsePayMongoResponse(array $response): array { return \bookingRefundParsePayMongoResponse($response); }
function bookingRefundClaimActions(array $claims): array { return \bookingRefundClaimActions($claims); }
function sendRefundProcessingEmail(array $email): bool { $GLOBALS['refund_fixture_email'] = $email; return true; }
function refundPageRedirect(string $type, string $title, string $message, int $id): never {
    $GLOBALS['refund_fixture_redirect'] = $type;
    throw new \RuntimeException('fixture_redirect');
}
$page = file_get_contents(__DIR__ . '/../admin/adpaymenttransactions.php');
$start = strpos($page, "        if ((\$_POST['action'] ?? '') === 'send_refund_link')");
$end = strpos($page, "        if ((\$_POST['action'] ?? '') === 'process_manual_refund')", $start);
check($start !== false && $end !== false, 'Send-link handler missing.');
$_POST['action'] = 'send_refund_link';
$requestId = 1;
$request = ['email' => 'guest@example.test', 'booking_reference' => 'TEST-1'];
$snapshot = ['data' => ['id' => 'ref_existing', 'attributes' => ['payment_id' => 'pay_existing', 'amount' => 100, 'currency' => 'PHP', 'status' => 'processing', 'transfer_link' => 'https://example.test/claim']]];
$pdo = new class($snapshot) {
    public array $queries = [];
    public function __construct(private array $snapshot) {}
    public function prepare(string $query): object {
        check(str_starts_with($query, 'SELECT * FROM booking_refunds') || str_starts_with($query, 'UPDATE booking_refunds SET email_status='), 'Send-link action attempted an unexpected mutation.');
        $this->queries[] = $query;
        return new class($this->snapshot) {
            public function __construct(private array $snapshot) {}
            public function execute(array $values): void {}
            public function fetchAll(int $mode): array {
                $row = ['booking_refund_id' => 3, 'provider_refund_id' => 'ref_existing', 'provider_payment_id' => 'pay_existing', 'amount_minor' => 100, 'provider_response' => json_encode($this->snapshot)];
                $tampered = $row;
                $tampered['provider_payment_id'] = 'pay_other';
                return [$row, $tampered];
            }
        };
    }
};
try {
    eval('namespace RefundRoutingFixture; use \\RuntimeException; use \\PDO; ' . substr($page, $start, $end - $start));
    throw new \RuntimeException('Send-link handler failed to redirect.');
} catch (\RuntimeException $error) {
    check($error->getMessage() === 'fixture_redirect', $error->getMessage());
}
check($GLOBALS['refund_fixture_redirect'] === 'success', 'Claim email did not succeed.');
check(count($GLOBALS['refund_fixture_email']['claim_actions']) === 1, 'Mismatched payment claim was not excluded.');
check($GLOBALS['refund_fixture_email']['claim_actions'][0]['claim_url'] === 'https://example.test/claim', 'Existing claim URL was not emailed.');
check(count($GLOBALS['refund_fixture_requests']) === 2, 'Send-link action made a provider request.');
echo "Saved claim email handler tests passed; no email or refund requests sent.\n";

function bookingRefundProviderResponseDisposition(string $status): string { return \bookingRefundProviderResponseDisposition($status); }
function bookingRefundSyncCancellationStatus(object $pdo, int $id): string { return 'processing'; }
$start = strpos($page, '                if ($responseBindingValidated' . "\n");
$end = strpos($page, "                \$failureCode = 'paymongo_error';", $start);
check($start !== false && $end !== false, 'Accepted-refund safeguard missing.');
$responseBindingValidated = true;
$parsed = \bookingRefundParsePayMongoResponse($snapshot);
$response = $snapshot;
$refundId = 3;
$providerError = new \PDOException('Fixture collation error');
$pdo = new class {
    public bool $saved = false;
    public function prepare(string $query): object {
        check(str_contains($query, 'status=?') && !str_contains($query, "status='failed'"), 'Accepted refund was marked failed.');
        return new class($this) {
            public function __construct(private object $owner) {}
            public function execute(array $values): void {
                check($values[0] === 'ref_existing' && $values[1] === 'processing', 'Accepted refund identity/status was lost.');
                $this->owner->saved = true;
            }
        };
    }
};
try {
    eval('namespace RefundRoutingFixture; use \\RuntimeException; use \\Throwable; ' . substr($page, $start, $end - $start));
    throw new \RuntimeException('Safeguard did not stop the failed-refund path.');
} catch (\RuntimeException $error) {
    check(str_contains($error->getMessage(), 'Do not retry this refund'), $error->getMessage());
}
check($pdo->saved, 'Accepted refund recovery save was not attempted.');
echo "Accepted-refund database-failure safeguard test passed.\n";

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
    provider_response LONGTEXT, provider_http_status INT, provider_diagnostic LONGTEXT,
    completed_at DATETIME NULL, email_status VARCHAR(20), email_sent_at DATETIME NULL, email_error VARCHAR(500)
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

function queryLiteral(string $source, string $prefix): string {
    foreach (token_get_all($source) as $token) {
        if (!is_array($token) || $token[0] !== T_CONSTANT_ENCAPSED_STRING) continue;
        // Evaluate only literal tokens from trusted repository files.
        $value = eval('return ' . $token[1] . ';');
        if (str_starts_with($value, $prefix)) return $value;
    }
    throw new \RuntimeException('Query not found: ' . $prefix);
}

$save = $pdo->prepare(queryLiteral($page, 'UPDATE booking_refunds SET provider_refund_id=?,status=?'));
$snapshot = json_encode(['data' => ['id' => 'ref_accepted', 'attributes' => ['status' => 'processing', 'transfer_link' => 'https://example.test/claim']]]);
$save->execute(['ref_accepted', 'processing', $snapshot, 200, '{}', 0, 1]);
$row = $pdo->query('SELECT * FROM booking_refunds WHERE booking_refund_id=1')->fetch(\PDO::FETCH_ASSOC);
check($row['status'] === 'processing' && $row['completed_at'] === null, 'Accepted refund incorrectly failed or completed.');
check(str_contains($row['provider_response'], '/claim'), 'Accepted claim link was lost.');

$refresh = $pdo->prepare(queryLiteral($page, 'UPDATE booking_refunds SET status=?, provider_response=?, failure_code=CASE'));
$refresh->execute(['processing', $snapshot, 0, 0, 0, 1]);
$refresh->execute(['failed', $snapshot, 1, 1, 0, 1]);
$row = $pdo->query('SELECT * FROM booking_refunds WHERE booking_refund_id=1')->fetch(\PDO::FETCH_ASSOC);
check($row['failure_code'] === 'provider_refund_failed' && $row['completed_at'] === null, 'Provider failure refresh was not recorded.');

$emailSql = queryLiteral($page, 'UPDATE booking_refunds SET email_status=?,email_sent_at=CASE');
$email = $pdo->prepare($emailSql);
$email->execute(['sent', 1, null, 1]);
$row = $pdo->query('SELECT * FROM booking_refunds WHERE booking_refund_id=1')->fetch(\PDO::FETCH_ASSOC);
check($row['email_status'] === 'sent' && $row['email_sent_at'] !== null, 'Email success tracking failed.');
$email->execute(['failed', 0, 'SMTP failed', 1]);
$row = $pdo->query('SELECT * FROM booking_refunds WHERE booking_refund_id=1')->fetch(\PDO::FETCH_ASSOC);
check($row['email_status'] === 'failed' && $row['email_sent_at'] === null, 'Email failure tracking failed.');

$webhookSource = file_get_contents(__DIR__ . '/../payments/RefundWebhookReconciler.php');
$webhook = $pdo->prepare(queryLiteral($webhookSource, "UPDATE booking_refunds\n"));
$webhook->execute(['succeeded', $snapshot, 1, 1]);
$row = $pdo->query('SELECT * FROM booking_refunds WHERE booking_refund_id=1')->fetch(\PDO::FETCH_ASSOC);
check($row['status'] === 'succeeded' && $row['completed_at'] !== null, 'Webhook completion was not persisted.');
$webhook->execute(['processing', $snapshot, 0, 1]);
check($pdo->query('SELECT status FROM booking_refunds WHERE booking_refund_id=1')->fetchColumn() === 'succeeded', 'A late update downgraded a completed refund.');
echo "Mixed-collation success, refresh, email and webhook query tests passed.\n";
