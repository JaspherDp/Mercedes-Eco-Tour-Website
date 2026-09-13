<?php
declare(strict_types=1);

require_once __DIR__ . '/../php/booking_refunds_helper.php';

final class RefundWebhookReconciler
{
    /**
     * @return list<array{id:string,payment_id:string,amount_minor:int,currency:string,status:string}>
     */
    public static function refundResources(array $resource): array
    {
        $resourceId = trim((string)($resource['id'] ?? ''));
        $attributes = is_array($resource['attributes'] ?? null) ? $resource['attributes'] : [];
        if (str_starts_with($resourceId, 'ref_')) {
            $refund = self::normalizeRefund($resource);
            return $refund === null ? [] : [$refund];
        }
        if (!str_starts_with($resourceId, 'pay_')) return [];

        $currency = strtoupper(trim((string)($attributes['currency'] ?? '')));
        $refunds = is_array($attributes['refunds'] ?? null) ? $attributes['refunds'] : [];
        $normalized = [];
        foreach ($refunds as $refundResource) {
            if (!is_array($refundResource)) continue;
            if (is_array($refundResource['data'] ?? null)) $refundResource = $refundResource['data'];
            $refund = self::normalizeRefund($refundResource, $resourceId, $currency);
            if ($refund !== null) $normalized[$refund['id']] = $refund;
        }
        return array_values($normalized);
    }

    /**
     * @return array{id:string,payment_id:string,amount_minor:int,currency:string,status:string}|null
     */
    private static function normalizeRefund(array $resource, string $parentPaymentId = '', string $parentCurrency = ''): ?array
    {
        $attributes = is_array($resource['attributes'] ?? null) ? $resource['attributes'] : $resource;
        $refundId = trim((string)($resource['id'] ?? $attributes['id'] ?? ''));
        if (!preg_match('/^ref_[A-Za-z0-9]+$/', $refundId)) return null;
        return [
            'id' => $refundId,
            'payment_id' => trim((string)($attributes['payment_id'] ?? $parentPaymentId)),
            'amount_minor' => is_numeric($attributes['amount'] ?? null) ? (int)$attributes['amount'] : 0,
            'currency' => strtoupper(trim((string)($attributes['currency'] ?? $parentCurrency))),
            'status' => strtolower(trim((string)($attributes['status'] ?? ''))),
        ];
    }

    /** @return array{matched:bool,updated:bool,idempotent:bool,status:string} */
    public static function reconcile(PDO $pdo, array $providerRefund): array
    {
        $refundId = trim((string)($providerRefund['id'] ?? ''));
        $paymentId = trim((string)($providerRefund['payment_id'] ?? ''));
        $amountMinor = (int)($providerRefund['amount_minor'] ?? 0);
        $currency = strtoupper(trim((string)($providerRefund['currency'] ?? '')));
        $providerStatus = self::providerStatus((string)($providerRefund['status'] ?? ''));
        if (!preg_match('/^ref_[A-Za-z0-9]+$/', $refundId)
            || !preg_match('/^pay_[A-Za-z0-9]+$/', $paymentId)
            || $amountMinor < 1 || $currency !== 'PHP' || $providerStatus === null) {
            throw new UnexpectedValueException('The provider refund resource failed validation.');
        }

        $pdo->beginTransaction();
        try {
            $find = $pdo->prepare(
                'SELECT booking_refund_id,cancellation_request_id,provider,provider_refund_id,
                        provider_payment_id,amount_minor,currency,status
                 FROM booking_refunds WHERE provider_refund_id=? LIMIT 1 FOR UPDATE'
            );
            $find->execute([$refundId]);
            $local = $find->fetch(PDO::FETCH_ASSOC);
            if (!$local) {
                $pdo->commit();
                return ['matched' => false, 'updated' => false, 'idempotent' => true, 'status' => 'ignored'];
            }
            $providerStatus = self::validateBinding($providerRefund, $local);

            $localStatus = strtolower((string)$local['status']);
            if ($localStatus === 'succeeded') {
                $pdo->commit();
                return ['matched' => true, 'updated' => false, 'idempotent' => true, 'status' => 'succeeded'];
            }

            $providerSnapshot = json_encode([
                'id' => $refundId,
                'payment_id' => $paymentId,
                'amount' => $amountMinor,
                'currency' => $currency,
                'status' => $providerStatus,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            $update = $pdo->prepare(
                "UPDATE booking_refunds
                 SET status=?,provider_response=?,completed_at=CASE WHEN ?='succeeded' THEN NOW() ELSE completed_at END
                 WHERE booking_refund_id=? AND status<>'succeeded'"
            );
            $update->execute([$providerStatus, $providerSnapshot, $providerStatus, (int)$local['booking_refund_id']]);
            bookingRefundSyncCancellationStatus($pdo, (int)$local['cancellation_request_id']);
            $pdo->commit();
            return [
                'matched' => true,
                'updated' => $update->rowCount() === 1,
                'idempotent' => $update->rowCount() !== 1,
                'status' => $providerStatus,
            ];
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $exception;
        }
    }

    public static function validateBinding(array $providerRefund, array $localRefund): string
    {
        $refundId = trim((string)($providerRefund['id'] ?? ''));
        $paymentId = trim((string)($providerRefund['payment_id'] ?? ''));
        $amountMinor = (int)($providerRefund['amount_minor'] ?? 0);
        $currency = strtoupper(trim((string)($providerRefund['currency'] ?? '')));
        $providerStatus = self::providerStatus((string)($providerRefund['status'] ?? ''));
        if (!preg_match('/^ref_[A-Za-z0-9]+$/', $refundId)
            || !preg_match('/^pay_[A-Za-z0-9]+$/', $paymentId)
            || $amountMinor < 1 || $currency !== 'PHP' || $providerStatus === null) {
            throw new UnexpectedValueException('The provider refund resource failed validation.');
        }
        if (strtolower((string)($localRefund['provider'] ?? '')) !== 'paymongo'
            || !hash_equals((string)($localRefund['provider_refund_id'] ?? ''), $refundId)
            || !hash_equals((string)($localRefund['provider_payment_id'] ?? ''), $paymentId)
            || (int)($localRefund['amount_minor'] ?? 0) !== $amountMinor
            || strtoupper((string)($localRefund['currency'] ?? '')) !== 'PHP') {
            throw new UnexpectedValueException('The provider refund does not match the local refund record.');
        }
        return $providerStatus;
    }

    private static function providerStatus(string $status): ?string
    {
        return match (strtolower(trim($status))) {
            'succeeded', 'success', 'completed', 'refunded' => 'succeeded',
            'processing', 'refunding' => 'processing',
            'pending', 'initiating' => 'pending',
            'failed', 'cancelled', 'canceled' => 'failed',
            default => null,
        };
    }
}
