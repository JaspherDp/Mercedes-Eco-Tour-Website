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

echo "PayMongo critical refund fixture tests passed.\n";
