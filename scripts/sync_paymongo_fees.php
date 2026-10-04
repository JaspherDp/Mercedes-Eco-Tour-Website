<?php
declare(strict_types=1);

// CLI only. Default is read-only. --apply writes only verified fee metadata.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../php/db_connection.php';
require_once __DIR__ . '/../payments/PayMongoService.php';
require_once __DIR__ . '/../php/payout_fee_sync.php';
$apply = in_array('--apply', $argv, true);
$service = PayMongoService::fromEnvironment();
$rows = $pdo->query("SELECT * FROM payment_transactions WHERE provider='paymongo' AND status='paid' AND provider_payment_id IS NOT NULL ORDER BY payment_transaction_id")->fetchAll();
$counts = ['verified' => 0, 'already_known' => 0, 'other_mode' => 0, 'unavailable' => 0];
foreach ($rows as $transaction) {
    if (PayoutAccounting::fee($transaction) !== null) { $counts['already_known']++; continue; }
    if (!PayoutFeeSync::modeMatches($transaction, $service->isLiveMode())) { $counts['other_mode']++; continue; }
    try {
        $resource = $service->retrievePayment((string)$transaction['provider_payment_id'])['data'] ?? [];
        PayoutAccounting::extract($transaction, $resource, $service->isLiveMode());
        if ($apply) PayoutAccounting::capture($pdo, $transaction, $resource, $service->isLiveMode());
        $counts['verified']++;
    } catch (Throwable $error) { $counts['unavailable']++; }
}
echo ($apply ? 'Verified metadata saved: ' : 'Read-only audit: ') . json_encode($counts) . PHP_EOL;
exit($counts['unavailable'] > 0 ? 1 : 0);
