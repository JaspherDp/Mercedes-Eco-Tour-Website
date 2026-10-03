<?php
declare(strict_types=1);

require_once __DIR__ . '/PayMongoService.php';
require_once __DIR__ . '/PaymentReconciler.php';

/** Resolve navigation against the ledger; navigation never supplies a paid state. */
final class PaymentReturnStatus
{
    public static function resolve(PDO $pdo, string $token, ?callable $retrieve = null): array
    {
        if (!preg_match('/^[a-f0-9]{64}$/D', $token)) return [];
        $query = $pdo->prepare("SELECT * FROM payment_transactions WHERE return_token = ? AND provider = 'paymongo' LIMIT 1");
        $query->execute([$token]);
        $transaction = $query->fetch(PDO::FETCH_ASSOC) ?: [];
        if (($transaction['status'] ?? '') !== 'pending') return $transaction;

        $sessionId = (string)($transaction['provider_checkout_session_id'] ?? '');
        if (str_starts_with($sessionId, 'cs_')) {
            try {
                PaymentHelper::assertPayMongoTransactionMode($transaction['metadata'] ?? '', PaymentHelper::payMongoIsLiveMode());
                $checkout = $retrieve !== null
                    ? $retrieve($sessionId)
                    : PayMongoService::fromEnvironment()->retrieveCheckoutSession($sessionId);
                $resource = is_array($checkout['data'] ?? null) ? $checkout['data'] : [];
                if (($resource['id'] ?? '') !== $sessionId) {
                    throw new UnexpectedValueException('Checkout Session identity mismatch.');
                }
                $attributes = is_array($resource['attributes'] ?? null) ? $resource['attributes'] : [];
                $payments = $attributes['payments'] ?? $attributes['payment_intent']['attributes']['payments'] ?? [];
                $status = strtolower((string)($attributes['payment_status'] ?? $attributes['status'] ?? ''));
                if (in_array($status, ['paid', 'completed'], true) || !empty($payments)) {
                    // The existing reconciler validates paid resource, mode, amount,
                    // currency, reference and uniqueness inside its transaction.
                    PaymentReconciler::reconcilePaidCheckout($pdo, $resource);
                }
            } catch (Throwable $exception) {
                // No provider payload, credentials, or return token in logs.
                error_log('PayMongo return verification pending: transaction=' . (int)$transaction['payment_transaction_id']
                    . ' reason=' . get_class($exception));
            }
        }
        // A webhook may have completed during retrieval, including a failed retrieval.
        $query->execute([$token]);
        return $query->fetch(PDO::FETCH_ASSOC) ?: [];
    }
}
