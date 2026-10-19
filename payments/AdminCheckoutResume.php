<?php
declare(strict_types=1);

/** Narrow grant for main-admin resumption of the assigned operator's checkout. */
final class AdminCheckoutResume
{
    public static function eligible(array $transaction, array $booking, int $amountMinor, int $adminId): bool
    {
        $metadata = json_decode((string)($transaction['metadata'] ?? ''), true);
        $url = parse_url((string)($transaction['checkout_url'] ?? ''));
        $host = strtolower((string)($url['host'] ?? ''));
        return $adminId > 0 && is_array($metadata)
            && ($transaction['status'] ?? '') === 'pending'
            && ($metadata['source'] ?? '') === 'operator_booking_payment'
            && ($metadata['staff_type'] ?? '') === 'operator'
            && (int)($metadata['operator_id'] ?? 0) > 0
            && (int)$metadata['operator_id'] === (int)($booking['operator_id'] ?? 0)
            && in_array(strtolower((string)($booking['booking_type'] ?? '')), ['package', 'boat', 'tourguide'], true)
            && (int)($transaction['booking_id'] ?? 0) === (int)($booking['booking_id'] ?? 0)
            && ($transaction['booking_domain'] ?? '') === strtolower((string)$booking['booking_type'])
            && (int)($transaction['amount_minor'] ?? 0) === $amountMinor && $amountMinor > 0
            && preg_match('/^cs_[A-Za-z0-9]+$/D', (string)($transaction['provider_checkout_session_id'] ?? '')) === 1
            && strtolower((string)($url['scheme'] ?? '')) === 'https'
            && ($host === 'checkout.paymongo.com' || str_ends_with($host, '.paymongo.com'));
    }

    public static function granted(array $metadata, int $adminId): bool
    {
        return $adminId > 0 && ($metadata['source'] ?? '') === 'operator_booking_payment'
            && ($metadata['staff_type'] ?? '') === 'operator'
            && is_array($metadata['admin_collection_resumed_by'] ?? null)
            && in_array($adminId, $metadata['admin_collection_resumed_by'], true);
    }

    public static function grant(array $metadata, int $adminId): array
    {
        if ($adminId < 1) throw new InvalidArgumentException('Invalid administrator.');
        $ids = is_array($metadata['admin_collection_resumed_by'] ?? null) ? $metadata['admin_collection_resumed_by'] : [];
        $ids[] = $adminId;
        $metadata['admin_collection_resumed_by'] = array_values(array_unique(array_map('intval', $ids)));
        return $metadata;
    }
}
