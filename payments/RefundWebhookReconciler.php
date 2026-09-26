<?php
declare(strict_types=1);

require_once __DIR__ . '/../php/booking_refunds_helper.php';

final class RefundWebhookReconciler
{
    public static function supportsEventType(string $eventType): bool
    {
        return in_array(trim($eventType), ['refund.succeeded', 'payment.refund.updated', 'payment.refunded'], true);
    }

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

    /** @return list<array{id:string,payment_id:string,amount_minor:int,currency:string,status:string}> */
    public static function refundResourcesForEvent(string $eventType, array $resource): array
    {
        if (!self::supportsEventType($eventType)) return [];
        $refunds = self::refundResources($resource);
        if ($eventType !== 'refund.succeeded') return $refunds;
        foreach ($refunds as &$refund) {
            if (trim((string)$refund['status']) === '') $refund['status'] = 'succeeded';
        }
        unset($refund);
        return $refunds;
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
            || ($paymentId !== '' && !preg_match('/^pay_[A-Za-z0-9]+$/', $paymentId))
            || $amountMinor < 1 || $currency !== 'PHP' || $providerStatus === null) {
            throw new UnexpectedValueException('The provider refund resource failed validation.');
        }

        $pdo->beginTransaction();
        try {
            $find = $pdo->prepare(
                'SELECT booking_refund_id,cancellation_request_id,provider,provider_refund_id,
                        provider_payment_id,amount_minor,currency,status,provider_response
                 FROM booking_refunds WHERE provider_refund_id=? LIMIT 1 FOR UPDATE'
            );
            $find->execute([$refundId]);
            $local = $find->fetch(PDO::FETCH_ASSOC);
            if (!$local) {
                $pdo->commit();
                return ['matched' => false, 'updated' => false, 'idempotent' => true, 'status' => 'ignored'];
            }
            $providerStatus = self::validateBinding($providerRefund, $local);
            if ($paymentId === '') {
                $paymentId = (string)$local['provider_payment_id'];
                $providerRefund['payment_id'] = $paymentId;
            }

            $localStatus = strtolower((string)$local['status']);
            $nextStatus = self::monotonicStatus($localStatus, $providerStatus);
            if ($nextStatus === $localStatus) {
                $pdo->commit();
                return ['matched' => true, 'updated' => false, 'idempotent' => true, 'status' => $localStatus];
            }

            $providerSnapshot = self::mergeProviderSnapshot($local['provider_response'] ?? null, $providerRefund, $nextStatus);
            $update = $pdo->prepare(
                "UPDATE booking_refunds
                 SET status=?,provider_response=?,completed_at=CASE WHEN ?='succeeded' THEN NOW() ELSE completed_at END
                 WHERE booking_refund_id=? AND status<>'succeeded'"
            );
            $update->execute([$nextStatus, $providerSnapshot, $nextStatus, (int)$local['booking_refund_id']]);
            bookingRefundSyncCancellationStatus($pdo, (int)$local['cancellation_request_id']);
            $pdo->commit();
            return [
                'matched' => true,
                'updated' => $update->rowCount() === 1,
                'idempotent' => $update->rowCount() !== 1,
                'status' => $nextStatus,
            ];
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $exception;
        }
    }

    public static function monotonicStatus(string $localStatus, string $providerStatus): string
    {
        return bookingRefundMonotonicProviderStatus($localStatus, $providerStatus);
    }

    public static function mergeProviderSnapshot(mixed $existingResponse, array $providerRefund, string $status): string
    {
        $existing = is_string($existingResponse) ? json_decode($existingResponse, true) : $existingResponse;
        if (!is_array($existing)) $existing = [];
        if (!is_array($existing['data'] ?? null)) $existing['data'] = [];
        if (!is_array($existing['data']['attributes'] ?? null)) $existing['data']['attributes'] = [];

        $attributes = &$existing['data']['attributes'];
        $existing['data']['id'] = trim((string)($providerRefund['id'] ?? ''));
        $existing['data']['type'] = 'refund';
        $attributes['payment_id'] = trim((string)($providerRefund['payment_id'] ?? ''));
        $attributes['amount'] = (int)($providerRefund['amount_minor'] ?? 0);
        $attributes['currency'] = strtoupper(trim((string)($providerRefund['currency'] ?? '')));
        $attributes['status'] = $status;
        unset($attributes);

        return json_encode($existing, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    public static function validateBinding(array $providerRefund, array $localRefund): string
    {
        $refundId = trim((string)($providerRefund['id'] ?? ''));
        $paymentId = trim((string)($providerRefund['payment_id'] ?? ''));
        $amountMinor = (int)($providerRefund['amount_minor'] ?? 0);
        $currency = strtoupper(trim((string)($providerRefund['currency'] ?? '')));
        $providerStatus = self::providerStatus((string)($providerRefund['status'] ?? ''));
        if (!preg_match('/^ref_[A-Za-z0-9]+$/', $refundId)
            || ($paymentId !== '' && !preg_match('/^pay_[A-Za-z0-9]+$/', $paymentId))
            || $amountMinor < 1 || $currency !== 'PHP' || $providerStatus === null) {
            throw new UnexpectedValueException('The provider refund resource failed validation.');
        }
        if (strtolower((string)($localRefund['provider'] ?? '')) !== 'paymongo'
            || !hash_equals((string)($localRefund['provider_refund_id'] ?? ''), $refundId)
            || ($paymentId !== '' && !hash_equals((string)($localRefund['provider_payment_id'] ?? ''), $paymentId))
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
