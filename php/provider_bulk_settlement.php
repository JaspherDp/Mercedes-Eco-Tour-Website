<?php
declare(strict_types=1);
require_once __DIR__ . '/payout_accounting.php';

/** Manual settlement only. The caller must authenticate the admin and verify CSRF. */
final class ProviderBulkSettlement
{
    public static function ids(mixed $input): array
    {
        if (!is_array($input) || !$input || count($input) > 100) {
            throw new RuntimeException('Select between 1 and 100 bookings.');
        }
        $ids = [];
        foreach ($input as $value) {
            if ((!is_int($value) && !is_string($value)) || !preg_match('/^[1-9][0-9]{0,17}$/D', (string)$value)) {
                throw new RuntimeException('The booking selection is invalid.');
            }
            $ids[] = (int)$value;
        }
        if (count(array_unique($ids)) !== count($ids)) throw new RuntimeException('Select each booking only once.');
        sort($ids, SORT_NUMERIC);
        return $ids;
    }

    public static function providerKey(array $row): string
    {
        if (!in_array($row['provider_type'], ['operator', 'hotel', 'boat', 'tourguide'], true) || (int)$row['provider_id'] < 1) {
            throw new RuntimeException('The payout provider could not be verified.');
        }
        return $row['provider_type'] . ':' . (int)$row['provider_id'];
    }

    public static function context(array $payout, string $csrf): string
    {
        $payload = implode('|', [(int)$payout['payout_id'], (string)$payout['provider_type'], (int)$payout['provider_id'],
            (string)$payout['provider_key'], json_encode($payout['accounting'] ?? [], JSON_THROW_ON_ERROR)]);
        return hash_hmac('sha256', $payload, $csrf);
    }

    /** Lock in stable ID order and re-read all eligibility and financial data. No network or DDL. */
    public static function validate(PDO $pdo, array $ids, array $contexts, string $csrf, callable $providerExists, callable $retention): array
    {
        if (!$pdo->inTransaction()) throw new LogicException('Settlement requires a database transaction.');
        $ids = self::ids($ids);
        if (!PayoutAccounting::schemaReady($pdo)) throw new RuntimeException('Apply the payout accounting migration before recording settlements.');
        $payoutQuery = $pdo->prepare('SELECT * FROM provider_payouts WHERE payout_id=? FOR UPDATE');
        $rows = []; $providerKey = null;
        foreach ($ids as $id) {
            $payoutQuery->execute([$id]);
            $row = $payoutQuery->fetch(PDO::FETCH_ASSOC);
            if (!$row) throw new RuntimeException('A selected payout record could not be found.');
            if (!in_array($row['status'], ['pending', 'approved'], true)) throw new RuntimeException('A selected booking is already settled or unavailable. Refresh and review the selection.');
            $key = self::providerKey($row);
            if ($providerKey !== null && $providerKey !== $key) throw new RuntimeException('All selected bookings must belong to the same provider.');
            $providerKey = $key;
            $hotel = $row['booking_domain'] === 'hotel';
            $query = $pdo->prepare(($hotel ? 'SELECT * FROM hotel_room_bookings WHERE hotel_booking_id=?' : 'SELECT * FROM bookings WHERE booking_id=?') . ' FOR UPDATE');
            $query->execute([(int)$row['booking_id']]);
            $booking = $query->fetch(PDO::FETCH_ASSOC);
            if (!$booking) throw new RuntimeException('A selected booking no longer exists.');
            $type = $hotel ? 'hotel' : match (strtolower((string)$booking['booking_type'])) {
                'package' => 'operator', 'boat' => 'boat', 'tourguide' => 'tourguide', default => ''
            };
            $providerId = (int)($booking[match ($type) {
                'hotel' => 'hotel_resort_id', 'operator' => 'operator_id', 'tourguide' => 'guide_id', default => 'boat_id'
            }] ?? 0);
            if ($type !== $row['provider_type'] || $providerId !== (int)$row['provider_id'] || $row['provider_key'] !== $key
                || (!$hotel && strtolower((string)$booking['booking_type']) !== $row['booking_domain'])) {
                throw new RuntimeException('A booking provider changed. Refresh and review the selection.');
            }
            if (!$providerExists($pdo, $type, $providerId)) throw new RuntimeException('The payout provider could not be verified.');
            $completion = strtolower((string)$booking[$hotel ? 'booking_status' : 'is_complete']);
            $bookingStatus = strtolower((string)$booking[$hotel ? 'booking_status' : 'status']);
            $cancellations = $pdo->prepare('SELECT * FROM booking_cancellation_requests WHERE booking_domain=? AND booking_id=? ORDER BY cancellation_request_id DESC FOR UPDATE');
            $cancellations->execute([$hotel ? 'hotel' : 'tour', (int)$row['booking_id']]);
            $requests = $cancellations->fetchAll(PDO::FETCH_ASSOC);
            foreach ($requests as $request) {
                if (!in_array(strtolower((string)$request['request_status']), ['completed', 'rejected', 'cancelled', 'declined', 'rescheduled'], true)
                    || in_array(strtolower((string)$request['refund_status']), ['initiating', 'pending', 'processing', 'failed'], true)) {
                    throw new RuntimeException('A selected booking has an unresolved cancellation or refund.');
                }
            }
            $cap = null;
            if ($completion !== 'completed') {
                $cancellation = $requests[0] ?? [];
                $cancellation['completion_status'] = $completion;
                if (!$retention($cancellation)['ready']) throw new RuntimeException('A selected booking is not yet eligible for settlement.');
                $cap = PayoutAccounting::minor($cancellation['non_refundable_amount']);
            } elseif (in_array($bookingStatus, ['cancelled', 'declined', 'refunded', 'disputed'], true)) {
                throw new RuntimeException('A selected booking has a payment or booking status requiring review.');
            }
            $row['booking_gross'] = $booking[$hotel ? 'amount_paid' : 'payment_amount'];
            $ledger = PayoutAccounting::load($pdo, [$row], true);
            $row['accounting'] = PayoutAccounting::forBooking($ledger, $row['booking_domain'], (int)$row['booking_id'], $row['booking_gross'], $cap);
            // Reuse the existing individual-settlement calculation and immutable snapshot convention.
            PayoutAccounting::settlementAmounts($row, $row['accounting']);
            $context = $contexts[$id] ?? '';
            if (!is_string($context) || $context === '' || !hash_equals(self::context($row, $csrf), $context)) {
                throw new RuntimeException('A payout amount or provider changed while the form was open. Refresh and review the selection.');
            }
            $rows[] = $row;
        }
        return $rows;
    }

    public static function total(array $rows): int
    {
        $total = 0;
        foreach ($rows as $row) $total += $row['accounting']['net_minor'];
        return $total;
    }

    /** Existing per-booking records remain the ledger of record; one shared batch ID links bulk rows. */
    public static function record(PDO $pdo, array $rows, array $destination, int $adminId, string $reference, string $note, callable $audit, bool $bulk): string
    {
        if (!$pdo->inTransaction() || !$rows || $adminId < 1) throw new LogicException('An authorized settlement transaction is required.');
        $batch = $bulk ? 'BULK-' . bin2hex(random_bytes(12)) : '';
        $total = self::total($rows);
        $update = $pdo->prepare("UPDATE provider_payouts SET gross_amount=?,paymongo_fee_amount=?,refunded_amount=?,net_amount=?,status='settled',approved_by_admin_id=COALESCE(approved_by_admin_id,?),approved_at=COALESCE(approved_at,NOW()),settlement_method=?,settlement_type='manual',settlement_destination_id=?,settlement_institution=?,settlement_account_name=?,settlement_destination_cipher=?,settlement_destination_last4=?,settlement_destination_label=?,settlement_reference=?,settlement_note=?,settled_by_admin_id=?,settled_at=NOW() WHERE payout_id=? AND status IN ('pending','approved')");
        $items = [];
        foreach ($rows as $row) {
            $a = $row['accounting'];
            $feeAudit = 'Third-party fees excluded: ' . PayoutAccounting::money($a['booking_fee_total_minor']) . ' (environmental ' . PayoutAccounting::money($a['booking_fees_minor']['environmental']) . ', entrance ' . PayoutAccounting::money($a['booking_fees_minor']['entrance']) . ', docking ' . PayoutAccounting::money($a['booking_fees_minor']['docking']) . ', other ' . PayoutAccounting::money($a['booking_fees_minor']['other']) . ').';
            $recordNote = trim($note . PHP_EOL . ($bulk ? $batch . '; ' . count($rows) . ' bookings; total PHP ' . PayoutAccounting::decimal($total) . PHP_EOL : '') . $feeAudit);
            if (mb_strlen($recordNote) > 500) throw new RuntimeException('Shorten the internal note to leave room for the settlement audit details.');
            $amounts = PayoutAccounting::settlementAmounts($row, $a);
            $update->execute([...$amounts, $adminId, $destination['method'], $destination['id'] ?: null, $destination['institution'] ?: null,
                $destination['account_name'] ?: null, $destination['cipher'], $destination['last4'], $destination['label'] ?: null,
                $reference, $recordNote, $adminId, (int)$row['payout_id']]);
            if ($update->rowCount() !== 1) throw new RuntimeException('A payout changed before settlement could be recorded.');
            $items[] = ['payout_id'=>(int)$row['payout_id'], 'booking_domain'=>$row['booking_domain'], 'booking_id'=>(int)$row['booking_id'],
                'booking_reference'=>$row['booking_reference'], 'net_minor'=>$a['net_minor']];
        }
        // Audit must succeed in the same transaction as the settlement records.
        if (!$audit(['batch'=>$batch, 'provider'=>self::providerKey($rows[0]), 'items'=>$items, 'total_minor'=>$total,
            'reference'=>$reference, 'admin_id'=>$adminId, 'method'=>$destination['method']])) {
            throw new RuntimeException('The settlement audit could not be saved. No payouts were changed.');
        }
        return $batch;
    }
}
