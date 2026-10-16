<?php
declare(strict_types=1);

require_once __DIR__ . '/../php/booking_refunds_helper.php';
require_once __DIR__ . '/../php/refund_confirmation_email.php';
require_once __DIR__ . '/../payments/RefundWebhookReconciler.php';
require_once __DIR__ . '/../payments/PayMongoService.php';

function refundCriticalAssert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

// A. QR Ph partial refunds allocate only the approved portion.
refundCriticalAssert(
    bookingRefundAutomaticAllocation('qrph', 800000, 1000000) === 800000,
    'QR Ph partial refund allocation was rejected or changed.'
);
refundCriticalAssert(
    bookingRefundAutomaticAllocation('maya', 800000, 1000000) === 0,
    'A payment method that does not support partial refunds was made automatically eligible.'
);
refundCriticalAssert(
    bookingRefundAutomaticAllocation('card', 800000, 1000000) === 0,
    'The QR Ph compatibility patch unexpectedly changed non-QR partial-refund routing.'
);

// B/C. Failed and unknown provider states never enter the submitted path.
refundCriticalAssert(bookingRefundProviderResponseDisposition('failed') === 'failed', 'Failed refund was treated as submitted.');
refundCriticalAssert(bookingRefundProviderResponseDisposition('mystery') === 'unexpected', 'Unknown refund status was treated as pending.');
refundCriticalAssert(bookingRefundParsePayMongoResponse(['data' => ['id' => 'ref_unknown', 'attributes' => []]])['status'] === 'unknown', 'Missing status was normalized to pending.');

// D. A successful first Payment remains a partial success when the next Payment fails.
$submitted = [[
    'provider_refund_id' => 'ref_paymentA',
    'amount_minor' => 50000,
    'claim_url' => '',
]];
$failures = [[
    'payment_transaction_id' => 22,
    'code' => 'payment_not_refundable',
    'message' => 'Second payment failed.',
]];
$outcome = bookingRefundOperationOutcome($submitted, $failures);
refundCriticalAssert($outcome['state'] === 'partial' && $outcome['requires_attention'], 'Partial multi-payment failure was not preserved.');
refundCriticalAssert(bookingRefundAggregateStatus(100000, 50000, 0, 1) === 'partial', 'Aggregate booking state did not preserve the successful portion.');
refundCriticalAssert(bookingRefundAutomaticAllocation('card', 50000, 0) === 0, 'Already allocated payment could be selected again.');

// E. Every QR claim link is preserved and rendered with its own amount.
$bookingHistory = [];
foreach ([100, 200, 100, 100] as $index => $amount) {
    $bookingHistory[] = ['booking_refund_id' => $index + 1, 'provider' => 'paymongo', 'status' => 'processing',
        'provider_refund_id' => 'ref_batch' . $index, 'provider_payment_id' => 'pay_batch' . $index, 'amount_minor' => $amount,
        'provider_response' => json_encode(['data' => ['id' => 'ref_batch' . $index, 'attributes' => [
            'payment_id' => 'pay_batch' . $index, 'amount' => $amount, 'currency' => 'PHP',
            'status' => 'processing', 'transfer_link' => 'https://example.test/claim-' . $index]]])];
}
$priorOnly = bookingRefundProgressAmounts([$bookingHistory[0]], 500);
refundCriticalAssert($priorOnly === ['eligible' => 500, 'returned' => 0, 'processing' => 100, 'unsubmitted' => 400], 'PHP 1 processing / PHP 4 remaining breakdown is incorrect.');
$allClaims = bookingRefundPendingClaimBatch($bookingHistory);
refundCriticalAssert(count($allClaims) === 4 && array_sum(array_column($allClaims, 'amount_minor')) === 500, 'Existing and new claims were not consolidated to PHP 5.');
$allEmail = RefundConfirmationEmailTemplate(['guest_name' => 'Fixture Guest', 'booking_reference' => 'TP26-0001',
    'amount' => 5, 'claim_actions' => bookingRefundClaimActions($allClaims),
    'refund_progress' => bookingRefundProgressAmounts($bookingHistory, 500)]);
foreach (range(0, 3) as $index) refundCriticalAssert(str_contains($allEmail, 'https://example.test/claim-' . $index), 'Consolidated email lost a payment claim.');
refundCriticalAssert(substr_count($allEmail, 'Claim this refund securely') === 4, 'Email must include one button per refund.');
refundCriticalAssert(str_contains($allEmail, 'Total eligible refund') && str_contains($allEmail, 'Claim total in this email'), 'Email total labels are missing.');
refundCriticalAssert(preg_match('/<p style="[^"]*text-align:center;text-indent:0;">Use every secure PayMongo link/', $allEmail) === 1, 'Email header instruction is not centered.');
$bookingHistory[] = array_merge($bookingHistory[0], ['status' => 'failed']);
refundCriticalAssert(count(bookingRefundPendingClaimBatch($bookingHistory)) === 4, 'Failed attempts were emailed.');
$bookingHistory[0]['status'] = 'succeeded';
$progress = bookingRefundProgressAmounts($bookingHistory, 500);
refundCriticalAssert($progress['returned'] === 100 && $progress['processing'] === 400 && $progress['unsubmitted'] === 0, 'Failed attempts changed the refund totals.');
refundCriticalAssert(count(bookingRefundPendingClaimBatch($bookingHistory)) === 3, 'Completed claims were emailed again.');

// Execute the page's post-processing email path: new PHP 4 + saved PHP 1.
$bookingHistory[0]['status'] = 'processing';
$submitted = array_map(static fn(array $claim): array => $claim + ['method' => 'qrph'], array_slice(bookingRefundPendingClaimBatch($bookingHistory), 1));
$requestId = 1;
$request = ['refundable_amount' => 5];
$targetMinor = 500;
$emailAmount = 4;
$pdo = new class($bookingHistory) {
    public function __construct(private array $rows) {}
    public function prepare(string $sql): object {
        refundCriticalAssert(str_starts_with($sql, 'SELECT * FROM booking_refunds WHERE cancellation_request_id=?'), 'Email grouping attempted an unexpected query.');
        return new class($this->rows) {
            public function __construct(private array $rows) {}
            public function execute(array $params): void {}
            public function fetchAll(int $mode): array { return $this->rows; }
        };
    }
};
function sendRefundProcessingEmail(array $data): bool { $GLOBALS['consolidated_email_fixture'] = $data; return true; }
$pageSource = file_get_contents(__DIR__ . '/../admin/adpaymenttransactions.php');
$emailStart = strpos($pageSource, '        $pendingLookup = $pdo->prepare(');
$emailEnd = strpos($pageSource, '        $marks = implode(', $emailStart);
refundCriticalAssert($emailStart !== false && $emailEnd !== false, 'Consolidated email path not found.');
eval(substr($pageSource, $emailStart, $emailEnd - $emailStart));
refundCriticalAssert($GLOBALS['consolidated_email_fixture']['amount'] === 5, 'Email only included the newly submitted PHP 4.');
refundCriticalAssert(count($GLOBALS['consolidated_email_fixture']['claim_actions']) === 4, 'Email did not include all existing and new pending claims.');
refundCriticalAssert(count($ids) === 4 && $emailAmount === 4, 'Email grouping changed the newly submitted refund amount.');

$multiQr = [
    ['provider_refund_id' => 'ref_qrA', 'amount_minor' => 30000, 'claim_url' => 'https://transfer.example.test/claim-a'],
    ['provider_refund_id' => 'ref_qrB', 'amount_minor' => 50000, 'claim_url' => 'https://transfer.example.test/claim-b'],
];
$claimActions = bookingRefundClaimActions($multiQr);
refundCriticalAssert(count($claimActions) === 2, 'Multiple QR claim links were collapsed.');
$emailHtml = RefundConfirmationEmailTemplate([
    'guest_name' => 'Fixture Guest',
    'booking_reference' => 'FIXTURE-1',
    'amount' => 800,
    'method' => 'QR Ph',
    'destination' => 'Customer-selected bank or e-wallet',
    'provider_refund_id' => 'Two refund references',
    'timeline' => 'Processing',
    'timeline_detail' => 'Fixture only.',
    'claim_actions' => $claimActions,
    'logo_url' => 'https://example.test/logo.png',
    'wordmark_url' => 'https://example.test/wordmark.png',
]);
refundCriticalAssert(str_contains($emailHtml, 'claim-a') && str_contains($emailHtml, 'claim-b'), 'Email omitted a QR claim link.');
refundCriticalAssert(str_contains($emailHtml, '300.00') && str_contains($emailHtml, '500.00'), 'Email did not associate claim links with amounts.');
refundCriticalAssert(!str_contains($emailHtml, 'pay_'), 'Email exposed a PayMongo Payment ID.');
$mergedSnapshot = RefundWebhookReconciler::mergeProviderSnapshot(
    ['data' => ['id' => 'ref_qrA', 'attributes' => ['transfer_link' => 'https://transfer.example.test/claim-a']]],
    ['id' => 'ref_qrA', 'payment_id' => 'pay_qrA', 'amount_minor' => 30000, 'currency' => 'PHP'],
    'succeeded'
);
refundCriticalAssert(str_contains($mergedSnapshot, 'claim-a'), 'Webhook reconciliation discarded a stored QR claim link.');

// F/G. Duplicate and out-of-order webhook states are monotonic.
refundCriticalAssert(RefundWebhookReconciler::monotonicStatus('processing', 'processing') === 'processing', 'Duplicate webhook changed state.');
refundCriticalAssert(RefundWebhookReconciler::monotonicStatus('succeeded', 'processing') === 'succeeded', 'Out-of-order webhook downgraded succeeded.');
refundCriticalAssert(RefundWebhookReconciler::monotonicStatus('processing', 'pending') === 'processing', 'Out-of-order pending webhook downgraded processing.');

$refundResource = [
    'id' => 'ref_fixtureCurrent',
    'attributes' => [
        'amount' => 80000,
        'currency' => 'PHP',
    ],
];

// H. The current refund.succeeded family is accepted and supplies succeeded when omitted.
$currentResources = RefundWebhookReconciler::refundResourcesForEvent('refund.succeeded', $refundResource);
refundCriticalAssert(count($currentResources) === 1 && $currentResources[0]['status'] === 'succeeded', 'refund.succeeded was not normalized correctly.');
refundCriticalAssert($currentResources[0]['payment_id'] === '', 'Documented refund.succeeded fixture unexpectedly required a Payment ID.');
refundCriticalAssert(
    RefundWebhookReconciler::validateBinding($currentResources[0], [
        'provider' => 'paymongo',
        'provider_refund_id' => 'ref_fixtureCurrent',
        'provider_payment_id' => 'pay_fixtureCurrent',
        'amount_minor' => 80000,
        'currency' => 'PHP',
    ]) === 'succeeded',
    'refund.succeeded could not bind through refund ID, amount, and currency when Payment ID was omitted.'
);

// I. Both legacy/current payment refund event names remain supported.
$refundResource['attributes']['status'] = 'processing';
$refundResource['attributes']['payment_id'] = 'pay_fixtureCurrent';
foreach (['payment.refunded', 'payment.refund.updated'] as $eventType) {
    $legacyResources = RefundWebhookReconciler::refundResourcesForEvent($eventType, $refundResource);
    refundCriticalAssert(count($legacyResources) === 1 && $legacyResources[0]['status'] === 'processing', $eventType . ' compatibility regressed.');
}

// Remote Payment refund data must be strongly bound before it can block another attempt.
$remoteRefunds = bookingRefundRemoteRefundResources([
    'id' => 'pay_fixtureCurrent',
    'attributes' => [
        'currency' => 'PHP',
        'refunds' => [[
            'id' => 'ref_remoteExisting',
            'attributes' => ['payment_id' => 'pay_fixtureCurrent', 'amount' => 10000, 'currency' => 'PHP', 'status' => 'succeeded'],
        ]],
    ],
]);
refundCriticalAssert(count($remoteRefunds) === 1 && $remoteRefunds[0]['id'] === 'ref_remoteExisting', 'Strongly bound remote refund was not recognized.');

// Webhook update validation occurs locally; these calls cannot reach the network.
$service = new PayMongoService('sk_test_fixture', 'pk_test_fixture');
try {
    $service->updateWebhook('invalid', 'https://example.test/webhook', ['refund.succeeded']);
    throw new RuntimeException('Invalid webhook ID passed validation.');
} catch (InvalidArgumentException) {
}
try {
    $service->updateWebhook('hook_fixture');
    throw new RuntimeException('Empty webhook update passed validation.');
} catch (InvalidArgumentException) {
}

// An active PHP 1 claim must not hide or reallocate the remaining PHP 4.
$page = file_get_contents(__DIR__ . '/../admin/adpaymenttransactions.php');
$planStart = strpos($page, '        $plannedSources = [];');
$planEnd = strpos($page, '        $sources = $plannedSources;', $planStart);
refundCriticalAssert($planStart !== false && $planEnd !== false, 'Refund planner not found.');
$service = new class { public function isLiveMode(): bool { return false; } };
$sources = [];
for ($index = 1; $index <= 5; $index++) {
    $sources[] = ['payment_transaction_id' => $index, 'provider' => 'paymongo',
        'provider_payment_id' => 'pay_fixture' . $index, 'payment_method_type' => 'qrph',
        'amount_minor' => 100, 'refunded_minor' => $index === 1 ? 100 : 0,
        'metadata' => ['paymongo_mode' => 'test']];
}
$remainingMinor = 400;
eval(substr($page, $planStart, $planEnd - $planStart));
refundCriticalAssert(count($plannedSources) === 4, 'Remaining payment sources were not selected.');
refundCriticalAssert(array_sum(array_column($plannedSources, 'planned_refund_minor')) === 400, 'Remaining refund must be exactly PHP 4.');
refundCriticalAssert(!in_array(1, array_column($plannedSources, 'payment_transaction_id'), true), 'Active PHP 1 refund was selected again.');

$marker = "<?php elseif (\$refund['refund_state'] === 'processing'): ?>";
$menuStart = strpos($page, $marker) + strlen($marker);
$menuEnd = strpos($page, '<?php else: ?><span class="refund-action-success">', $menuStart);
refundCriticalAssert($menuEnd !== false, 'Processing action menu not found.');
if (!function_exists('paymentMoney')) {
    function paymentMoney(float $amount): string { return 'PHP ' . number_format($amount, 2); }
}
$csrfToken = 'fixture';
$requestId = 1;
foreach ([[400, true], [0, false], [400, false]] as [$remaining, $available]) {
    $refund = ['remaining_minor' => $remaining, 'automatic_available' => $available];
    ob_start();
    eval('?>' . substr($page, $menuStart, $menuEnd - $menuStart));
    $menu = ob_get_clean();
    refundCriticalAssert(str_contains($menu, 'data-refresh-refund'), 'Provider refresh was hidden.');
    refundCriticalAssert(str_contains($menu, 'data-process-refund') === ($remaining >= 100 && $available), 'Remaining-refund action eligibility is incorrect.');
    if ($available) refundCriticalAssert(str_contains($menu, 'Process remaining PHP 4.00'), 'Remaining amount was not shown.');
}

echo "PayMongo critical refund fixture tests passed.\n";
