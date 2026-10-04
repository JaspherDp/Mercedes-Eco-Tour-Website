<?php
declare(strict_types=1);

/** Accounting only: never confirms payments, changes booking balances, or sends money. */
final class PayoutAccounting
{
    public static function minor(string|int|float $amount): int
    {
        $value = (string)$amount;
        if (!preg_match('/^(-?)(\d+)(?:\.(\d{1,2}))?$/D', $value, $m)) {
            throw new UnexpectedValueException('Invalid currency amount.');
        }
        return ($m[1] === '-' ? -1 : 1) * ((int)$m[2] * 100 + (int)str_pad($m[3] ?? '', 2, '0'));
    }

    public static function decimal(int $minor): string
    {
        return ($minor < 0 ? '-' : '') . intdiv(abs($minor), 100) . '.' . str_pad((string)(abs($minor) % 100), 2, '0', STR_PAD_LEFT);
    }

    public static function money(?int $minor): string
    {
        if ($minor === null) return 'Unavailable';
        $decimal = self::decimal($minor);
        [$whole, $fraction] = explode('.', $decimal);
        return '₱' . preg_replace('/\B(?=(\d{3})+(?!\d))/', ',', $whole) . '.' . $fraction;
    }

    public static function metadata(array $transaction): array
    {
        $metadata = $transaction['metadata'] ?? null;
        if (is_string($metadata)) $metadata = json_decode($metadata, true);
        return is_array($metadata) ? $metadata : [];
    }

    public static function fee(array $transaction): ?int
    {
        if (!in_array(strtolower((string)($transaction['status'] ?? '')), ['paid', 'succeeded', 'completed'], true)) return null;
        $provider = strtolower((string)($transaction['provider'] ?? ''));
        if (in_array($provider, ['cash', 'offline'], true)) return 0; // No PayMongo charge on an offline receipt.
        if ($provider !== 'paymongo') return null;
        $fee = self::metadata($transaction)['paymongo_accounting'] ?? [];
        if (!is_array($fee) || ($fee['source'] ?? '') !== 'payment_resource'
            || ($fee['payment_id'] ?? '') !== ($transaction['provider_payment_id'] ?? null)
            || ($fee['gross_minor'] ?? null) !== (int)$transaction['amount_minor']
            || !is_int($fee['fee_minor'] ?? null) || !is_int($fee['net_minor'] ?? null)
            || $fee['fee_minor'] < 0 || $fee['net_minor'] < 0
            || $fee['gross_minor'] - $fee['fee_minor'] !== $fee['net_minor']) return null;
        return $fee['fee_minor'];
    }

    public static function transactionDisplay(array $transaction): array
    {
        $successful = in_array(strtolower((string)$transaction['status']), ['paid', 'succeeded', 'completed'], true);
        $fee = self::fee($transaction);
        $net = $fee === null ? null : (int)$transaction['amount_minor'] - $fee;
        return ['fee' => $successful ? self::money($fee) : 'Not applicable',
            'net' => $successful ? self::money($net) : 'Not applicable'];
    }

    public static function transactionHtml(array $transaction): string
    {
        $display = self::transactionDisplay($transaction);
        return '<small class="accounting-transaction">PayMongo fee: ' . $display['fee']
            . '<br>Net before refunds: ' . $display['net'] . '</small>';
    }

    public static function recordedSettlement(array $payout): int
    {
        return self::minor($payout['settled_net_amount'] ?? $payout['net_amount'] ?? $payout['gross_amount'] ?? '0.00');
    }

    public static function settlementReview(array $payout, array $accounting): string
    {
        if ($accounting['net_minor'] === null) return '';
        $difference = self::recordedSettlement($payout) - $accounting['net_minor'];
        return $difference === 0 ? '' : ' Recorded settlement differs from the current net entitlement by '
            . self::money(abs($difference)) . '; manual reconciliation is required. The recorded transfer is unchanged.';
    }

    /** Use integer addition even when the surrounding legacy UI expects peso floats. */
    public static function sum(array $rows, string $field): float
    {
        $minor = 0;
        foreach ($rows as $row) $minor += self::minor($row[$field] ?? '0.00');
        return (float)self::decimal($minor);
    }

    public static function totalLabel(float $amount, int $unknown = 0): string
    {
        if ($unknown > 0) return 'Pending review';
        return self::money(self::minor($amount));
    }

    public static function unknownCount(array $rows): int
    {
        return count(array_filter($rows, static fn($row) => $row['payout_amount'] === null));
    }

    /** Immutable settlement snapshot, in MySQL DECIMAL strings. Reject repeated settlements. */
    public static function settlementAmounts(array $payout, array $accounting): array
    {
        if (!in_array($payout['status'] ?? '', ['pending', 'approved'], true)) {
            throw new RuntimeException('Only an unsettled payout can be settled.');
        }
        if ($accounting['blocked_reason'] !== '') throw new RuntimeException($accounting['blocked_reason']);
        if ($accounting['fee_minor'] === null || $accounting['net_minor'] === null) {
            throw new RuntimeException('Verified PayMongo fees are required before settlement.');
        }
        if ($accounting['net_minor'] <= 0) throw new RuntimeException('This booking has no provider amount available to settle.');
        return array_map([self::class, 'decimal'], [$accounting['gross_minor'], $accounting['fee_minor'], $accounting['refund_minor'], $accounting['net_minor']]);
    }

    /** Accept only an authenticated, matched Payment resource; all values are centavos. */
    public static function extract(array $transaction, array $resource, bool $live): array
    {
        $a = $resource['attributes'] ?? [];
        $foreignFee = $a['foreign_fee'] ?? 0;
        if (($resource['type'] ?? '') !== 'payment' || ($resource['id'] ?? '') !== ($transaction['provider_payment_id'] ?? '')
            || ($a['status'] ?? '') !== 'paid' || ($a['currency'] ?? '') !== 'PHP' || ($a['livemode'] ?? null) !== $live
            || ($a['amount'] ?? null) !== (int)$transaction['amount_minor']
            || !is_int($a['fee'] ?? null) || !is_int($a['net_amount'] ?? null)
            || !is_int($foreignFee) || $foreignFee < 0
            || $a['fee'] < 0 || $a['net_amount'] < 0 || $a['amount'] - $a['fee'] - $foreignFee !== $a['net_amount']) {
            throw new UnexpectedValueException('Authoritative payment fee is unavailable or inconsistent.');
        }
        return ['source' => 'payment_resource', 'payment_id' => $resource['id'], 'gross_minor' => $a['amount'],
            'fee_minor' => $a['fee'] + $foreignFee, 'processing_fee_minor' => $a['fee'], 'foreign_fee_minor' => $foreignFee,
            'net_minor' => $a['net_amount'], 'livemode' => $live, 'verified_at' => gmdate('c')];
    }

    public static function capture(PDO $pdo, array $transaction, array $resource, bool $live): void
    {
        $fee = self::extract($transaction, $resource, $live);
        // JSON_SET preserves every existing metadata key and never alters the gross amount.
        $stmt = $pdo->prepare("UPDATE payment_transactions SET metadata=JSON_SET(COALESCE(metadata,JSON_OBJECT()),'$.paymongo_accounting',JSON_EXTRACT(?, '$')) WHERE payment_transaction_id=? AND provider='paymongo' AND status='paid' AND provider_payment_id=? AND amount_minor=?");
        $stmt->execute([json_encode($fee, JSON_THROW_ON_ERROR), $transaction['payment_transaction_id'], $resource['id'], $fee['gross_minor']]);
    }

    /** Optional post-commit enrichment must never cause a successful payment to fail. No API calls. */
    public static function captureOptional(PDO $pdo, array $transaction, array $resource, bool $live): void
    {
        try {
            $transaction['provider_payment_id'] = $resource['id'] ?? '';
            self::capture($pdo, $transaction, $resource, $live);
        } catch (Throwable $error) {
            // Missing fees remain unknown; no provider payloads, credentials or customer data are logged.
        }
    }

    public static function calculate(array $transactions, array $refunds, int $bookingGross, ?int $cap = null): array
    {
        $gross = 0; $fees = 0; $unknown = 0; $refunded = 0; $active = false; $invalid = false;
        foreach ($transactions as $t) {
            if (!in_array(strtolower((string)$t['status']), ['paid', 'succeeded', 'completed'], true)) continue;
            if (($t['currency'] ?? '') !== 'PHP') { $invalid = true; continue; }
            $gross += (int)$t['amount_minor'];
            $fee = self::fee($t);
            if ($fee === null) $unknown++; else $fees += $fee;
        }
        foreach ($refunds as $r) {
            if (in_array($r['status'], ['initiating', 'pending', 'processing'], true)) $active = true;
            if ($r['status'] !== 'succeeded') continue;
            if (($r['currency'] ?? '') !== 'PHP') { $invalid = true; continue; }
            $refunded += (int)$r['amount_minor'];
        }
        $mismatch = $gross !== $bookingGross;
        // Retain historical booking receipts on screen, but never infer a fee for unlinked money.
        $displayGross = max($gross, $bookingGross);
        $reason = $invalid ? 'Currency requires review.' : ($mismatch ? 'Payment ledger and booking receipts require reconciliation.' : ($unknown ? 'PayMongo fee unavailable; retrieve verified fees before settlement.' : ($active ? 'A refund is still processing.' : '')));
        $feeTotal = ($unknown || $mismatch || $invalid) ? null : $fees;
        $retained = max(0, $gross - $refunded);
        if ($cap !== null) $retained = min($retained, max(0, $cap));
        $net = $cap === 0 || ($gross > 0 && $refunded >= $gross)
            ? 0 : ($feeTotal === null ? null : max(0, $retained - $feeTotal));
        return ['gross_minor' => $displayGross, 'fee_minor' => $feeTotal, 'known_fee_minor' => $fees,
            'refund_minor' => $refunded, 'net_minor' => $net, 'unknown_fees' => $unknown,
            'blocked_reason' => $reason, 'active_refund' => $active];
    }

    public static function tableExists(PDO $pdo, string $table): bool
    {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?');
        $stmt->execute([$table]);
        return (int)$stmt->fetchColumn() > 0;
    }

    public static function schemaReady(PDO $pdo): bool
    {
        static $cache = [];
        $key = spl_object_id($pdo);
        if (!array_key_exists($key, $cache)) {
            $stmt = $pdo->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='provider_payouts' AND column_name IN ('paymongo_fee_amount','refunded_amount','net_amount')");
            $cache[$key] = (int)$stmt->fetchColumn() === 3;
        }
        return $cache[$key];
    }

    /** Batch read scoped bookings; no remote calls and no writes while rendering. */
    public static function load(PDO $pdo, array $bookings, bool $lock = false): array
    {
        $groups = []; $result = [];
        foreach ($bookings as $b) {
            $domain = strtolower((string)$b['booking_domain']); $id = (int)$b['booking_id'];
            $groups[$domain][$id] = $id;
            $result[$domain . ':' . $id] = ['transactions' => [], 'refunds' => []];
        }
        $hasPayments = self::tableExists($pdo, 'payment_transactions');
        $hasRefunds = self::tableExists($pdo, 'booking_refunds');
        foreach ($groups as $domain => $ids) {
            foreach (array_chunk(array_values($ids), 300) as $chunk) {
                $marks = implode(',', array_fill(0, count($chunk), '?'));
                if ($hasPayments) {
                    $stmt = $pdo->prepare("SELECT payment_transaction_id,booking_id,provider,provider_payment_id,amount_minor,currency,status,metadata FROM payment_transactions WHERE booking_domain=? AND booking_id IN ($marks)" . ($lock ? ' FOR UPDATE' : ''));
                    $stmt->execute(array_merge([$domain], $chunk));
                    foreach ($stmt as $t) $result[$domain . ':' . $t['booking_id']]['transactions'][] = $t;
                }
                if ($hasRefunds) {
                    // Refund architecture uses 'tour' for package, boat and guide bookings.
                    $stmt = $pdo->prepare("SELECT booking_id,amount_minor,currency,status FROM booking_refunds WHERE booking_domain=? AND booking_id IN ($marks)" . ($lock ? ' FOR UPDATE' : ''));
                    $stmt->execute(array_merge([$domain === 'hotel' ? 'hotel' : 'tour'], $chunk));
                    foreach ($stmt as $r) $result[$domain . ':' . $r['booking_id']]['refunds'][] = $r;
                }
            }
        }
        return $result;
    }

    public static function forBooking(array $ledger, string $domain, int $id, string|int|float $paid, ?int $cap = null): array
    {
        $data = $ledger[strtolower($domain) . ':' . $id] ?? ['transactions' => [], 'refunds' => []];
        return self::calculate($data['transactions'], $data['refunds'], self::minor($paid), $cap);
    }

    public static function summary(array $rows): string
    {
        $gross = 0; $fees = 0; $refunds = 0; $net = 0; $unknown = 0;
        foreach ($rows as $row) {
            $a = $row['accounting']; $gross += $a['gross_minor']; $fees += $a['known_fee_minor'];
            $refunds += $a['refund_minor']; $net += $a['net_minor'] ?? 0;
            if ($a['net_minor'] === null) $unknown++;
        }
        return '<p class="accounting-summary"><span>All bookings in this account</span> Gross customer payments: <strong>' . self::money($gross)
            . '</strong> · ' . ($unknown ? 'Known ' : '') . 'PayMongo fees: <strong>' . self::money($fees)
            . '</strong> · Refunded: <strong>' . self::money($refunds) . '</strong> · '
            . ($unknown ? 'Known net payout subtotal' : 'Net payout') . ': <strong>' . self::money($net)
            . '</strong>' . ($unknown ? ' · ' . $unknown . ' booking(s) have unavailable fees or unreconciled receipts; excluded from net totals.' : '')
            . ' Gross payments remain the original customer receipts. Net totals include pending eligibility; amounts available to settle are shown separately.</p>';
    }
}
