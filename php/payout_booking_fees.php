<?php
declare(strict_types=1);
require_once __DIR__ . '/payment_receipt_breakdown.php';

/** Recorded third-party charges only; service add-ons remain provider earnings. */
final class PayoutBookingFees
{
    public static function category(string $label): ?string
    {
        $label = strtolower(trim($label));
        if (preg_match('/^environmental(?:[ _-]|$)/', $label)) return 'environmental';
        if (preg_match('/^entrance(?:[ _-]|$)/', $label)) return 'entrance';
        if (preg_match('/^(?:docking|landing)(?:[ _\/-]|$)/', $label)) return 'docking';
        if (preg_match('/^(?:other|additional)[ _-]+fees?$/', $label)) return 'other';
        return null;
    }

    public static function load(PDO $pdo, string $domain, int $id, bool $lock): array
    {
        $fees = ['environmental'=>0, 'entrance'=>0, 'docking'=>0, 'other'=>0];
        $locations = ['entrance'=>[], 'docking'=>[]];
        $hotel = $domain === 'hotel';
        $query = $pdo->prepare(($hotel ? 'SELECT * FROM hotel_room_bookings WHERE hotel_booking_id=?' : 'SELECT * FROM bookings WHERE booking_id=?') . ($lock ? ' FOR UPDATE' : ''));
        $query->execute([$id]);
        $booking = $query->fetch(PDO::FETCH_ASSOC);
        if (!$booking) return ['categories'=>$fees, 'total_minor'=>0, 'booking_total_minor'=>0, 'verified'=>false, 'valid'=>false];
        $total = PayoutAccounting::minor($booking[$hotel ? 'total_amount' : 'grand_total']);
        $table = $hotel ? 'hotel_booking_expenses' : 'booking_expenses';
        $expenses = [];
        if (PayoutAccounting::tableExists($pdo, $table)) {
            $query = $pdo->prepare('SELECT * FROM ' . $table . ' WHERE ' . ($hotel ? 'hotel_booking_id' : 'booking_id') . '=?' . ($lock ? ' FOR UPDATE' : ''));
            $query->execute([$id]);
            $expenses = $query->fetchAll(PDO::FETCH_ASSOC);
        }
        $expenseTotal = 0;
        foreach ($expenses as $expense) {
            $amount = PayoutAccounting::minor($expense[$hotel ? 'total_amount' : 'amount']);
            $expenseTotal += $amount;
            $category = self::category((string)($expense['expense_name'] ?? '')) ?? self::category((string)$expense['expense_type']);
            if ($category !== null) {
                $fees[$category] += $amount;
                self::recordLocation($locations, $category, (string)($expense['expense_name'] ?? $expense['expense_type']), $amount);
            }
        }
        $verified = true;
        if (!$hotel) {
            $breakdown = PaymentReceiptTourBreakdown($pdo, $booking, (float)PayoutAccounting::decimal(max(0, $total - $expenseTotal)));
            foreach ($breakdown['rows'] as $line) {
                if (str_contains((string)$line['description'], 'and included fees')) $verified = false;
                $category = self::category((string)$line['description']);
                if ($category !== null) {
                    $amount = PayoutAccounting::minor($line['amount']);
                    $fees[$category] += $amount;
                    self::recordLocation($locations, $category, (string)$line['description'], $amount);
                }
            }
        }
        $valid = $total > 0 && min($fees) >= 0 && array_sum($fees) <= $total && $expenseTotal <= $total;
        if (!$valid) $verified = false;
        return ['locations'=>$locations, 'categories'=>$fees, 'total_minor'=>array_sum($fees), 'booking_total_minor'=>$total, 'verified'=>$verified, 'valid'=>$valid];
    }

    private static function recordLocation(array &$locations, string $category, string $label, int $amount): void
    {
        if (!isset($locations[$category]) || $amount <= 0) return;
        $parts = preg_split('/\s+[—–-]\s+/u', trim($label), 2);
        $location = !empty($parts[1]) ? trim($parts[1]) : 'Location not recorded';
        $locations[$category][$location] = ($locations[$category][$location] ?? 0) + $amount;
    }

    public static function allocateLocations(array $locations, int $target): array
    {
        $locations = array_filter($locations, static fn($amount) => $amount > 0);
        if ($target <= 0) return [];
        if (!$locations) return ['Location not recorded'=>$target];
        $total = array_sum($locations); $allocated = [];
        foreach ($locations as $location => $amount) $allocated[$location] = (int)floor($amount * $target / $total);
        $remaining = $target - array_sum($allocated);
        foreach ($allocated as $location => $_) { if ($remaining > 0) { $allocated[$location]++; $remaining--; } }
        arsort($allocated);
        return array_filter($allocated, static fn($amount) => $amount > 0);
    }

    /** Allocate recorded charges proportionally to receipts still retained after refunds. */
    public static function apply(array $accounting, array $fees, ?int $cap): array
    {
        $retained = max(0, $accounting['gross_minor'] - $accounting['refund_minor']);
        if ($cap !== null) $retained = min($retained, max(0, $cap));
        $categories = array_fill_keys(['environmental','entrance','docking','other'], 0);
        $verified = $fees['verified'];
        $valid = $fees['valid'] ?? ($fees['booking_total_minor'] > 0 && min($fees['categories']) >= 0 && $fees['total_minor'] <= $fees['booking_total_minor']);
        if ($valid) {
            $total = max(1, $fees['booking_total_minor']);
            $covered = min($retained, $total);
            $target = (int)round($fees['total_minor'] * $covered / $total);
            foreach ($categories as $key => $_) $categories[$key] = (int)floor($fees['categories'][$key] * $covered / $total);
            $remainder = $target - array_sum($categories);
            foreach ($categories as $key => $_) {
                if ($remainder > 0 && $fees['categories'][$key] > 0) { $categories[$key]++; $remainder--; }
            }
        }
        $accounting['booking_fee_locations_minor'] = [];
        foreach (['entrance','docking'] as $category) $accounting['booking_fee_locations_minor'][$category] = self::allocateLocations($fees['locations'][$category] ?? [], $categories[$category]);
        $accounting['booking_fees_minor'] = $categories;
        $accounting['booking_fee_total_minor'] = array_sum($categories);
        $accounting['booking_fees_verified'] = $verified;
        if (!$valid && $retained > 0) {
            $accounting['net_minor'] = null;
            $accounting['blocked_reason'] = trim($accounting['blocked_reason'] . ' Recorded booking fees are inconsistent with the booking total.');
        } elseif ($accounting['net_minor'] !== null) {
            $accounting['net_minor'] = max(0, $accounting['net_minor'] - array_sum($categories));
        }
        return $accounting;
    }
}
