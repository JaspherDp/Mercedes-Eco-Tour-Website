<?php
declare(strict_types=1);
require_once __DIR__ . '/PaymentReconciler.php';
require_once __DIR__ . '/PayMongoService.php';

/** Refresh a pending checkout without holding a booking lock during network I/O. */
final class BalanceCheckoutPending
{
    public static function inspect(array $transaction, array $response, bool $live): array
    {
        $resource = $response['data'] ?? [];
        if (!is_array($resource) || ($resource['type'] ?? '') !== 'checkout_session'
            || ($resource['id'] ?? '') !== ($transaction['provider_checkout_session_id'] ?? '')) {
            throw new UnexpectedValueException('Checkout Session identity mismatch.');
        }
        $attributes = $resource['attributes'] ?? [];
        PaymentHelper::assertPayMongoLivemode($attributes['livemode'] ?? null, $live);
        if (($attributes['reference_number'] ?? '') !== ($transaction['merchant_reference'] ?? '')) {
            throw new UnexpectedValueException('Checkout reference mismatch.');
        }
        $payments = $attributes['payments'] ?? $attributes['payment_intent']['attributes']['payments'] ?? [];
        $paid = false;
        $processing = false;
        foreach ((array)$payments as $payment) {
            $status = strtolower((string)($payment['attributes']['status'] ?? $payment['status'] ?? ''));
            $paid = $paid || $status === 'paid';
            $processing = $processing || in_array($status, ['pending', 'processing'], true);
        }
        if ($paid) return ['state' => 'paid', 'resource' => $resource];
        $intentStatus = strtolower((string)($attributes['payment_intent']['attributes']['status'] ?? ''));
        $paymentStatus = strtolower((string)($attributes['payment_status'] ?? ''));
        if ($processing || in_array($intentStatus, ['processing', 'succeeded'], true)
            || in_array($paymentStatus, ['paid', 'processing', 'pending'], true)) {
            return ['state' => 'processing', 'resource' => $resource];
        }
        $state = strtolower((string)($attributes['status'] ?? ''));
        if (!in_array($state, ['active', 'expired'], true)) {
            throw new UnexpectedValueException('Unrecognized checkout state.');
        }
        return ['state' => $state, 'resource' => $resource];
    }

    public static function refresh(PDO $pdo, array $transaction, callable $retrieve, ?callable $reconcile = null): string
    {
        if ($pdo->inTransaction()) throw new LogicException('Release booking locks before refreshing checkout.');
        PaymentHelper::assertPayMongoTransactionMode($transaction['metadata'] ?? null);
        $result = self::inspect($transaction, $retrieve((string)$transaction['provider_checkout_session_id']), PaymentHelper::payMongoIsLiveMode());
        if ($result['state'] === 'paid') {
            if ($reconcile !== null) $reconcile($pdo, $result['resource']);
            else PaymentReconciler::reconcilePaidCheckout($pdo, $result['resource']);
        } elseif ($result['state'] === 'expired') {
            // Provider-confirmed terminal state only. Never retire by local age,
            // URL navigation, QR-code expiry, or a failed provider lookup.
            $update = $pdo->prepare("UPDATE payment_transactions SET status = 'expired', failed_at = CURRENT_TIMESTAMP,
                failure_code = 'checkout_expired', failure_message = 'PayMongo confirmed that the Checkout Session expired.'
                WHERE payment_transaction_id = ? AND status = 'pending' AND provider_checkout_session_id = ?");
            $update->execute([(int)$transaction['payment_transaction_id'], (string)$transaction['provider_checkout_session_id']]);
        }
        return $result['state'];
    }
}
