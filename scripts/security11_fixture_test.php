<?php
declare(strict_types=1);

require_once __DIR__ . '/../payments/RefundWebhookReconciler.php';
require_once __DIR__ . '/../payments/PaymentHelper.php';

function security11Assert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$refund = [
    'id' => 'ref_fixtureA',
    'payment_id' => 'pay_fixtureA',
    'amount_minor' => 50000,
    'currency' => 'PHP',
    'status' => 'succeeded',
];
$local = [
    'provider' => 'paymongo',
    'provider_refund_id' => 'ref_fixtureA',
    'provider_payment_id' => 'pay_fixtureA',
    'amount_minor' => 50000,
    'currency' => 'PHP',
];
security11Assert(RefundWebhookReconciler::validateBinding($refund, $local) === 'succeeded', 'Valid binding failed.');

foreach ([
    ['id' => 'ref_other'],
    ['payment_id' => 'pay_other'],
    ['amount_minor' => 49999],
    ['currency' => 'USD'],
] as $change) {
    try {
        RefundWebhookReconciler::validateBinding(array_replace($refund, $change), $local);
        throw new RuntimeException('A mismatched refund binding was accepted.');
    } catch (UnexpectedValueException) {
    }
}
security11Assert(
    RefundWebhookReconciler::validateBinding(array_replace($refund, ['status' => 'pending']), $local) === 'pending',
    'Pending provider state was treated as successful.'
);
security11Assert(
    RefundWebhookReconciler::validateBinding(array_replace($refund, ['status' => 'failed']), $local) === 'failed',
    'Failed provider state was treated as successful.'
);

$paymentResource = [
    'id' => 'pay_fixtureA',
    'attributes' => [
        'currency' => 'PHP',
        'refunds' => [
            ['id' => 'ref_fixtureA', 'attributes' => ['amount' => 50000, 'status' => 'succeeded']],
            ['id' => 'ref_outOfBand', 'attributes' => ['amount' => 10000, 'status' => 'succeeded']],
        ],
    ],
];
$resources = RefundWebhookReconciler::refundResources($paymentResource);
security11Assert(count($resources) === 2, 'Payment refund list was not parsed exactly.');
security11Assert($resources[0]['id'] === 'ref_fixtureA', 'Expected provider refund ID was not preserved.');
security11Assert($resources[1]['id'] === 'ref_outOfBand', 'Out-of-band refund ID was not kept distinct.');
security11Assert($resources[0]['payment_id'] === 'pay_fixtureA', 'Parent payment ID was not bound.');

$body = '{"data":{"type":"event"}}';
$timestamp = 1700000000;
$webhookSecret = 'whsk_fixture_only';
$signature = hash_hmac('sha256', $timestamp . '.' . $body, $webhookSecret);
$header = "t={$timestamp},te={$signature}";
security11Assert(PaymentHelper::verifyPayMongoTestSignature($body, $header, $webhookSecret, 300, $timestamp), 'Valid signature failed.');
security11Assert(!PaymentHelper::verifyPayMongoTestSignature($body, '', $webhookSecret, 300, $timestamp), 'Missing signature passed.');
security11Assert(!PaymentHelper::verifyPayMongoTestSignature($body, "t={$timestamp},te=" . str_repeat('0', 64), $webhookSecret, 300, $timestamp), 'Invalid signature passed.');
security11Assert(!PaymentHelper::verifyPayMongoTestSignature($body, $header, $webhookSecret, 300, $timestamp + 301), 'Expired signature passed.');

echo "Security #11 fixture tests passed.\n";
