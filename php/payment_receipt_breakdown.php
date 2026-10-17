<?php
declare(strict_types=1);

function PaymentReceiptLine(string $description, float $amount, string $detail = '', ?int $quantity = null, ?float $unitPrice = null): array
{
    return ['description' => $description, 'amount' => round($amount, 2), 'detail' => $detail, 'quantity' => $quantity, 'unit_price' => $unitPrice];
}

/** An unchanged rate can support a historical receipt; today's changed rates cannot. */
function PaymentReceiptRateIsHistorical(array $row, string $createdAt): bool
{
    return $createdAt !== '' && !empty($row['created_at']) && !empty($row['updated_at'])
        && $row['created_at'] <= $createdAt && $row['updated_at'] <= $createdAt;
}

function PaymentReceiptHistoricalTourRows(PDO $pdo, array $booking, array $payload, float $originalTotal, string $createdAt): ?array
{
    $type = strtolower((string)($booking['booking_type'] ?? ''));
    $adults = (int)($payload['num_adults'] ?? $booking['num_adults'] ?? 0);
    $children = (int)($payload['num_children'] ?? $booking['num_children'] ?? 0);
    $guests = $adults + $children;
    if ($guests < 1) return null;
    $boats = max(1, (int)ceil($guests / 8));
    $overnight = ($payload['tour_type'] ?? $booking['tour_type'] ?? '') === 'overnight';
    $priceColumn = $overnight ? 'overnight_price' : 'day_tour_price';
    $rows = [];
    $preferred = (string)($payload['preferred_resource'] ?? $booking['preferred_resource'] ?? '');
    $usesBoat = $type === 'boat' || stripos($preferred, 'Add-on: Tour Boat') !== false;
    $usesGuide = in_array($type, ['tourguide', 'guide'], true) || stripos($preferred, 'Add-on: Tour Guide') !== false;
    if ($type === 'package') {
        if (!empty($payload['package_id'])) {
            $query = $pdo->prepare('SELECT * FROM tour_packages WHERE package_id = ? LIMIT 1');
            $query->execute([(int)$payload['package_id']]);
        } else {
            $query = $pdo->prepare('SELECT * FROM tour_packages WHERE package_title = ? AND operator_id = ? LIMIT 1');
            $query->execute([(string)$booking['package_name'], (int)$booking['operator_id']]);
        }
        $package = $query->fetch(PDO::FETCH_ASSOC);
        if (!$package || !PaymentReceiptRateIsHistorical($package, $createdAt)) return null;
        $rows[] = PaymentReceiptLine('Tour package fare — ' . $package['package_title'], (float)$package['price'] * $guests,
            'Package inclusions are bundled; separate boat and guide prices were not recorded.', $guests, (float)$package['price']);
    } else {
        $rates = $pdo->query('SELECT * FROM service_prices WHERE is_active = 1')->fetchAll(PDO::FETCH_ASSOC);
        $byType = [];
        foreach ($rates as $rate) $byType[strtolower((string)$rate['service_type'])] = $rate;
        foreach (['boat' => $usesBoat, 'tourguide' => $usesGuide] as $service => $used) {
            if (!$used) continue;
            if (empty($byType[$service]) || !PaymentReceiptRateIsHistorical($byType[$service], $createdAt)) return null;
            $unit = (float)$byType[$service][$priceColumn];
            $quantity = $service === 'boat' ? $boats : 1;
            $rows[] = PaymentReceiptLine($service === 'boat' ? 'Boat hire' : 'Tour guide fee', $quantity * $unit,
                $overnight ? 'Overnight rate' : 'Day-tour rate', $quantity, $unit);
        }
    }
    $locations = array_values(array_filter(array_map('trim', explode(',', (string)($payload['location'] ?? $booking['location'] ?? '')))));
    if (array_key_exists('entrance_guests', $payload)) {
        if (!is_int($payload['entrance_guests']) || $payload['entrance_guests'] !== $guests) return null;
        $young = 0;
    } elseif (array_key_exists('young_children', $payload)) {
        $young = $payload['young_children'];
        if (!is_int($young) || $young < 0 || $young > $children) return null;
    } else {
        $childAges = $payload['child_ages'] ?? [];
        if ($children > 0 && (!is_array($childAges) || count($childAges) !== $children)) return null;
        $young = count(array_filter($childAges, static fn($age) => (int)$age <= 7));
    }
    $entranceGuests = $guests - $young;
    $entranceCodes = ['Apuao Grande Island' => 'entrance_apuao_grande', 'Caringo Island' => 'entrance_caringo', 'Canimog Island' => $overnight ? 'entrance_canimog_overnight' : 'entrance_canimog_day'];
    $dockCodes = ['Apuao Pequeña Island' => 'docking_apuao_pequena', 'Malasugui Island' => 'docking_malasugui', 'Canimog Island' => 'docking_canimog'];
    foreach ($locations as $location) {
        foreach (['Entrance fee' => $entranceCodes[$location] ?? null, 'Docking / landing fee' => $usesBoat ? ($dockCodes[$location] ?? null) : null] as $label => $code) {
            if ($code === null) continue;
            $query = $pdo->prepare('SELECT * FROM additional_fees WHERE fee_code = ? AND is_active = 1 LIMIT 1');
            $query->execute([$code]);
            $fee = $query->fetch(PDO::FETCH_ASSOC);
            if (!$fee || !PaymentReceiptRateIsHistorical($fee, $createdAt)) return null;
            $quantity = $label === 'Entrance fee' ? $entranceGuests : $boats;
            if ($quantity > 0 && (float)$fee['amount'] > 0) $rows[] = PaymentReceiptLine($label . ' — ' . $location, $quantity * (float)$fee['amount'], '', $quantity, (float)$fee['amount']);
        }
    }
    $environmental = round($originalTotal - array_sum(array_column($rows, 'amount')), 2);
    if ($environmental < -0.009 || $adults < 1) return null;
    // Environmental category was not retained in older drafts. Only accept an
    // allocation consistent with a known, unchanged rate at booking time.
    if ($environmental > 0) {
        $query = $pdo->query("SELECT * FROM additional_fees WHERE fee_code LIKE 'environmental_%' AND is_active = 1");
        $valid = false;
        foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $fee) {
            if (PaymentReceiptRateIsHistorical($fee, $createdAt) && abs((float)$fee['amount'] * $adults - $environmental) < 0.009) $valid = true;
        }
        if (!$valid && $locations !== []) return null;
        // With no selected destinations, the retained checkout charged only
        // the package fare and environmental fee; its remainder is exact.
        $rows[] = PaymentReceiptLine('Environmental fee', $environmental, 'Allocated from the original recorded checkout total.', $adults, $environmental / $adults);
    }
    return $rows;
}

function PaymentReceiptTourBreakdown(PDO $pdo, array $booking, float $baseTotal): array
{
    $type = strtolower((string)($booking['booking_type'] ?? 'tour'));
    $label = $type === 'package' ? 'Tour package fare and included fees' : ($type === 'boat' ? 'Boat hire and included fees' : 'Tour guide service and included fees');
    $fallback = ['rows' => [PaymentReceiptLine($label, $baseTotal, (string)(($booking['package_name'] ?? '') ?: ($booking['location'] ?? '')))],
        'note' => 'The original component prices were not saved for this reservation. A verified combined charge is shown; separately recorded fees and add-ons are listed individually.'];
    try {
        if (stripos((string)($booking['preferred_resource'] ?? ''), 'Walk-in booking') !== false) {
            try {
                $walkin = $pdo->prepare('SELECT details FROM booking_walkin_details WHERE booking_id=?');
                $walkin->execute([(int)$booking['booking_id']]);
                $details = json_decode((string)$walkin->fetchColumn(), true);
                $serviceRows = $details['service_rows'] ?? [];
                if ($serviceRows) {
                    $adjustment = round($baseTotal - array_sum(array_column($serviceRows, 'amount')), 2);
                    if (abs($adjustment) > 0.009) $serviceRows[] = PaymentReceiptLine('Recorded service adjustment', $adjustment);
                    return ['rows' => $serviceRows, 'note' => ''];
                }
            } catch (PDOException $ignored) {
                // Legacy walk-ins predate saved itemized pricing.
            }
        }
        $query = $pdo->prepare("SELECT payload,total_minor,created_at FROM booking_checkout_drafts WHERE booking_id = ? AND booking_domain IN ('package','boat','tourguide') AND status = 'submitted' ORDER BY booking_draft_id ASC LIMIT 1");
        $query->execute([(int)$booking['booking_id']]);
        $draft = $query->fetch(PDO::FETCH_ASSOC);
        if (!$draft) {
            // Walk-in base prices are directly entered by staff; mandatory fees
            // are stored separately in booking_expenses and must not be doubled.
            if (stripos((string)($booking['preferred_resource'] ?? ''), 'Walk-in booking') !== false) {
                $labels = ['boat' => 'Boat hire', 'tourguide' => 'Tour guide fee', 'package' => 'Tour package fare'];
                return ['rows' => [PaymentReceiptLine($labels[$type] ?? 'Reservation service charge', $baseTotal, 'Recorded staff-entered service price.')], 'note' => ''];
            }
            return $fallback;
        }
        $payload = json_decode((string)$draft['payload'], true) ?: [];
        // Catalog extras are separately recorded expenses, excluded from the base fare.
        $originalTotal = round((int)$draft['total_minor'] / 100 - (float)($payload['catalog_addons']['total'] ?? 0), 2);
        $saved = $payload['price_breakdown'] ?? null;
        $rows = is_array($saved) ? ($saved['rows'] ?? []) : [];
        $note = '';
        if (!$rows || abs(array_sum(array_column($rows, 'amount')) - $originalTotal) > 0.009) {
            $rows = PaymentReceiptHistoricalTourRows($pdo, $booking, $payload, $originalTotal, (string)$draft['created_at']);
            if (!$rows) return $fallback;
            $note = 'Original component charges were recovered from the saved checkout details and rates that were unchanged at booking time.';
        }
        $adjustment = round($baseTotal - $originalTotal, 2);
        if (abs($adjustment) > 0.009) $rows[] = PaymentReceiptLine('Recorded reservation adjustment', $adjustment, 'Difference between the original reservation and the current recorded service total.');
        return ['rows' => $rows, 'note' => $note];
    } catch (Throwable $error) {
        error_log('Payment receipt breakdown unavailable: ' . $error->getMessage());
        return $fallback;
    }
}
