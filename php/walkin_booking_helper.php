<?php
declare(strict_types=1);
require_once __DIR__ . '/additional_fees_helper.php';
require_once __DIR__ . '/tour_resource_availability_helper.php';
require_once __DIR__ . '/input_validation.php';
require_once __DIR__ . '/booking_capacity_helper.php';
require_once __DIR__ . '/payment_receipt_breakdown.php';
require_once __DIR__ . '/booking_addons_helper.php';

function WalkinBookingEnsureSchema(PDO $pdo): void
{
    BookingAddonsEnsureSchema($pdo);
    $pdo->exec("CREATE TABLE IF NOT EXISTS booking_resource_boats (
        booking_id INT NOT NULL, boat_id INT NOT NULL,
        PRIMARY KEY (booking_id, boat_id), INDEX idx_reserved_boat (boat_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS booking_walkin_details (
        booking_id INT NOT NULL PRIMARY KEY, details LONGTEXT NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function WalkinBookingLocations(): array
{
    return ['Malasugui Island', 'Caringo Island', 'Apuao Grande Island', 'Apuao Pequeña Island', 'Canimog Island', 'Quinapaguian Island', 'Cayucyucan'];
}

/** No-account mode stores a booking contact, never a login or email match. */
function WalkinBookingResolveGuest(PDO $pdo, array $input): array
{
    $phone = trim((string)($input['phone_number'] ?? ''));
    if (($input['guest_mode'] ?? 'existing') !== 'new') {
        $id = ItourValidationInt($input['tourist_id'] ?? null, 'Tourist account', 1, PHP_INT_MAX);
        $stmt = $pdo->prepare('SELECT tourist_id, full_name, email, phone_number, status FROM tourist WHERE tourist_id = ? LIMIT 1');
        $stmt->execute([$id]);
        $tourist = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$tourist || strtolower((string)$tourist['status']) !== 'active') throw new DomainException('Please select an active tourist account.');
        return ['tourist_id'=>$id, 'phone_number'=>$phone !== '' ? $phone : trim((string)$tourist['phone_number']),
            'walkin_guest_name'=>null, 'walkin_guest_email'=>null, 'walkin_guest_address'=>null];
    }
    $name = ItourValidationText($input['guest_name'] ?? '', 'Guest name', 150, true);
    $email = strtolower(trim((string)($input['guest_email'] ?? '')));
    $address = ItourValidationText($input['guest_address'] ?? '', 'Guest address', 255);
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email)>190 || $phone === '') throw new DomainException('Walk-in guest name, valid email, and phone number are required.');
    return ['tourist_id'=>null, 'phone_number'=>$phone, 'walkin_guest_name'=>$name,
        'walkin_guest_email'=>$email, 'walkin_guest_address'=>$address];
}

function WalkinBookingPayment(array $input, float $total): array
{
    if (($input['payment_option'] ?? 'full') !== 'full') throw new DomainException('Walk-in bookings allow full payment only; down payments are not available.');
    $amount = ItourValidationMoney($input['payment_amount'] ?? 0, 'Payment amount');
    $collected = ($input['payment_collected'] ?? '') === '1';
    $method = strtolower(trim((string)($input['payment_method'] ?? '')));
    if ($method === 'qr_code') {
        if ($collected || $amount > 0) throw new DomainException('QR payments are recorded only after PayMongo verifies the payment.');
        return ['amount' => 0.0, 'balance' => $total, 'method' => ''];
    }
    if (!$collected) {
        if ($amount > 0) throw new DomainException('Confirm that the full payment has actually been collected before recording it.');
        return ['amount' => 0.0, 'balance' => $total, 'method' => ''];
    }
    if ($total <= 0 || abs($amount - $total) > 0.009) throw new DomainException('Collect the full amount shown. Partial payments are not allowed for walk-in bookings.');
    if (!in_array($method, ['cash', 'gcash', 'bank transfer'], true)) throw new DomainException('Choose the method used to collect the full payment.');
    return ['amount' => $total, 'balance' => 0.0, 'method' => $method];
}

/** Keep unfinished walk-in payment reservations out of added-booking views. */
function WalkinBookingVisibleSql(string $alias = 'b'): string
{
    if (!preg_match('/^[a-z][a-z0-9_]*$/iD', $alias)) throw new InvalidArgumentException('Invalid booking alias.');
    return "NOT (LOWER(COALESCE({$alias}.preferred_resource,'')) LIKE '%walk-in booking%'
        AND LOWER(COALESCE({$alias}.status,''))='pending'
        AND LOWER(COALESCE({$alias}.is_complete,''))='uncomplete'
        AND NOT (COALESCE({$alias}.is_paid,0)=1 AND COALESCE({$alias}.grand_total,0)>0
            AND COALESCE({$alias}.remaining_balance,1)<=0
            AND COALESCE({$alias}.payment_amount,0)>=COALESCE({$alias}.grand_total,0)))";
}

function WalkinBookingIsFullyPaid(array $booking): bool
{
    return (int)($booking['is_paid'] ?? 0) === 1
        && (float)($booking['grand_total'] ?? 0) > 0
        && isset($booking['remaining_balance'], $booking['payment_amount'])
        && (float)$booking['remaining_balance'] <= 0
        && (float)$booking['payment_amount'] >= (float)$booking['grand_total'];
}

function WalkinBookingPaymentStatus(PDO $pdo, int $bookingId): array
{
    $stmt = $pdo->prepare('SELECT booking_id, booking_reference, status, is_complete, is_paid, grand_total, payment_amount, remaining_balance FROM bookings WHERE booking_id=?');
    $stmt->execute([$bookingId]);
    $booking = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$booking) throw new DomainException('The walk-in booking could not be found.');
    return $booking;
}

function WalkinBookingAcceptPaid(PDO $pdo, int $bookingId): array
{
    $update = $pdo->prepare("UPDATE bookings SET status='accepted', updated_at=NOW()
        WHERE booking_id=? AND LOWER(status) IN ('pending','accepted') AND LOWER(is_complete)='uncomplete'
          AND is_paid=1 AND remaining_balance<=0 AND payment_amount>=grand_total AND grand_total>0
          AND preferred_resource LIKE '%Walk-in booking%'");
    $update->execute([$bookingId]);
    $booking = WalkinBookingPaymentStatus($pdo, $bookingId);
    if (strtolower((string)$booking['status']) !== 'accepted' || !WalkinBookingIsFullyPaid($booking)) {
        throw new DomainException('Full payment has not been verified. The booking has not been confirmed or accepted.');
    }
    return $booking;
}

function WalkinBookingCollectCash(PDO $pdo, int $bookingId, int $adminId, string $adminName): void
{
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT * FROM bookings WHERE booking_id=? FOR UPDATE');
        $stmt->execute([$bookingId]);
        $booking = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$booking || stripos((string)$booking['preferred_resource'], 'Walk-in booking') === false
            || !in_array(strtolower((string)$booking['status']), ['pending','accepted'], true)
            || strtolower((string)$booking['is_complete']) !== 'uncomplete') throw new DomainException('This booking cannot accept a walk-in payment.');
        if ((int)$booking['is_paid'] === 1 && (float)$booking['remaining_balance'] <= 0) { $pdo->commit(); return; }
        $pending = $pdo->prepare("SELECT payment_transaction_id FROM payment_transactions WHERE booking_id=? AND booking_domain=? AND status='pending' LIMIT 1 FOR UPDATE");
        $pending->execute([$bookingId, $booking['booking_type']]);
        if ($pending->fetchColumn()) throw new DomainException('Cancel the active QR payment before recording cash to avoid collecting twice.');
        $amount = round((float)$booking['remaining_balance'], 2);
        if ($amount <= 0) throw new DomainException('There is no payment left to collect.');
        $update = $pdo->prepare("UPDATE bookings SET payment_amount=payment_amount+?, remaining_balance=0, is_paid=1, payment_method='cash', status='accepted', updated_at=NOW() WHERE booking_id=?");
        $update->execute([$amount, $bookingId]);
        $unique = bin2hex(random_bytes(16));
        $metadata = json_encode(['source'=>'admin_booking_payment','admin_id'=>$adminId,'admin_name'=>$adminName,'staff_type'=>'admin','display_reference'=>'Cash'], JSON_THROW_ON_ERROR);
        $ledger = $pdo->prepare("INSERT INTO payment_transactions (tourist_id,booking_domain,booking_id,booking_reference,provider,merchant_reference,idempotency_key,return_token,amount_minor,currency,status,payment_method_type,metadata,paid_at)
            VALUES (?,?,?,?,'cash',?,?,?,?,'PHP','paid','cash',?,NOW())");
        $ledger->execute([(int)$booking['tourist_id'],$booking['booking_type'],$bookingId,$booking['booking_reference'],
            'CASH-'.strtoupper($unique),'admin-walkin-cash:'.$unique,hash('sha256',$unique), (int)round($amount*100),$metadata]);
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
}

function WalkinBookingCatalog(PDO $pdo, string $start = '', string $end = ''): array
{
    $boats = $pdo->query('SELECT boat_id, name, total_pax FROM boats ORDER BY name, boat_id')->fetchAll(PDO::FETCH_ASSOC);
    foreach ($boats as &$boat) $boat['total_pax'] = BookingBoatCapacity((int)$boat['total_pax']);
    unset($boat);
    $guides = $pdo->query('SELECT guide_id, fullname FROM tour_guides ORDER BY fullname, guide_id')->fetchAll(PDO::FETCH_ASSOC);
    foreach (['boat' => &$boats, 'tourguide' => &$guides] as $type => &$rows) {
        $ranges = $start !== '' ? tourResourceActiveRanges($pdo, $type) : [];
        foreach ($rows as &$row) {
            $id = (int)$row[$type === 'boat' ? 'boat_id' : 'guide_id'];
            $row['available'] = $start !== '';
            foreach ($ranges as $range) {
                if ($range['resource_id'] === $id && tourResourceRangesOverlap($start, $end ?: $start, $range['start'], $range['end'])) {
                    $row['available'] = false;
                    break;
                }
            }
        }
        unset($row);
    }
    unset($rows);
    return ['boats' => $boats, 'guides' => $guides];
}

function WalkinBookingBoatIds(array $input): array
{
    $raw = $input['additional_boat_ids'] ?? [];
    if (!is_array($raw) || count($raw) > 25) throw new DomainException('Choose valid additional boats.');
    if (!empty($input['boat_id'])) array_unshift($raw, $input['boat_id']);
    $ids = [];
    foreach ($raw as $id) $ids[] = ItourValidationInt($id, 'Boat', 1, PHP_INT_MAX);
    if (count($ids) !== count(array_unique($ids))) throw new DomainException('Select each boat only once.');
    return $ids;
}

// Shared by the live quote endpoint and final creation; client-supplied totals
// are never used to decide the charges or reserve boats.
function WalkinBookingQuote(PDO $pdo, array $input, bool $checkAvailability = true): array
{
    $type = (string)($input['booking_type'] ?? '');
    $tourType = (string)($input['tour_type'] ?? '');
    if (!in_array($type, ['package', 'boat', 'tourguide'], true) || !in_array($tourType, ['same-day', 'overnight'], true)) {
        throw new DomainException('Choose the booking type and tour type first.');
    }
    $start = ItourValidationDate($input['booking_date'] ?? '', 'Tour date');
    $end = $tourType === 'overnight' ? ItourValidationDate($input['booking_end_date'] ?? '', 'Tour end date') : $start;
    if ($start < date('Y-m-d') || ($tourType === 'overnight' && $end <= $start)) throw new DomainException('Choose a valid tour duration.');
    $adults = ItourValidationInt($input['num_adults'] ?? 1, 'Adults', 1, 100);
    $children = ItourValidationInt($input['num_children'] ?? 0, 'Children', 0, 99);
    $pax = $adults + $children;
    if ($pax > 100) throw new DomainException('A maximum of 100 guests is allowed.');
    $locations = $input['locations'] ?? [];
    if (is_array($locations)) $locations = array_values(array_filter($locations, static fn($value) => $value !== ''));
    if (!is_array($locations) || count($locations) > 2 || count($locations) !== count(array_unique($locations))) throw new DomainException('Choose up to two different destinations.');
    foreach ($locations as $location) if (!in_array($location, WalkinBookingLocations(), true)) throw new DomainException('Choose a valid destination.');
    if ($type !== 'package' && !$locations) throw new DomainException('Choose one or two destinations.');
    $boats = ($type === 'boat' || ($type === 'tourguide' && !empty($input['include_boats']))) ? WalkinBookingBoatIds($input) : [];
    if (($type === 'boat' || !empty($input['include_boats'])) && !$boats) throw new DomainException('Choose a boat for this booking.');
    $rateKey = $tourType === 'overnight' ? 'overnight_price' : 'day_tour_price';
    $rates = [];
    foreach ($pdo->query('SELECT service_type, day_tour_price, overnight_price FROM service_prices WHERE is_active=1')->fetchAll(PDO::FETCH_ASSOC) as $rate) {
        $rates[strtolower((string)$rate['service_type'])] = (float)$rate[$rateKey];
    }
    $serviceRows = [];
    $capacity = 0;
    $boatCapacities = [];
    foreach ($boats as $id) {
        $stmt = $pdo->prepare('SELECT name, total_pax FROM boats WHERE boat_id=?');
        $stmt->execute([$id]);
        $boat = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$boat) throw new DomainException('The selected boat no longer exists.');
        if ($checkAvailability && !tourResourceIsAvailable($pdo, 'boat', $id, $start, $end)) throw new DomainException($boat['name'] . ' is unavailable for the selected tour duration.');
        $capacity += BookingBoatCapacity((int)$boat['total_pax']);
        $boatCapacities[] = (int)$boat['total_pax'];
        if (!isset($rates['boat'])) throw new DomainException('Configure an active boat price before adding this booking.');
        $serviceRows[] = PaymentReceiptLine('Boat hire — ' . $boat['name'], $rates['boat'], $tourType === 'overnight' ? 'Overnight rate' : 'Day-tour rate', 1, $rates['boat']);
    }
    if ($boats) BookingValidateBoatCapacity($pax, $boatCapacities);
    $guideIds = [];
    if ($type === 'tourguide') {
        $guideIds = BookingResourceIds($input['guide_id'] ?? null, $input['additional_guide_ids'] ?? [], 'tour guide');
        BookingValidateGuideCapacity($pax, $guideIds);
        if (count($guideIds) > 1) BookingResourceStorageReady($pdo);
        if (!isset($rates['tourguide'])) throw new DomainException('Configure an active tour guide price first.');
        foreach ($guideIds as $guideId) {
            $stmt = $pdo->prepare('SELECT fullname FROM tour_guides WHERE guide_id=?');
            $stmt->execute([$guideId]);
            $name = $stmt->fetchColumn();
            if (!$name || ($checkAvailability && !tourResourceIsAvailable($pdo, 'tourguide', $guideId, $start, $end))) throw new DomainException('The selected tour guide is unavailable.');
            $serviceRows[] = PaymentReceiptLine('Tour guide — ' . $name, $rates['tourguide'], $tourType === 'overnight' ? 'Overnight rate' : 'Day-tour rate', 1, $rates['tourguide']);
        }
    }
    if ($type === 'package') {
        $stmt = $pdo->prepare('SELECT * FROM tour_packages WHERE package_id=?');
        $stmt->execute([ItourValidationInt($input['package_id'] ?? null, 'Package', 1, PHP_INT_MAX)]);
        $package = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$package) throw new DomainException('Choose a valid package.');
        require_once __DIR__ . '/package_checkout_authority_helper.php';
        $duration = PackageCheckoutDuration((string)$package['package_type'], (string)$package['package_range']);
        if ($duration['type'] !== $tourType || ($tourType === 'overnight' && (new DateTimeImmutable($start))->modify('+' . $duration['nights'] . ' days')->format('Y-m-d') !== $end)) {
            throw new DomainException('Choose the tour type and duration specified by this package (' . $package['package_range'] . ').');
        }
        if ($checkAvailability && !tourPackageIsAvailable($pdo, $package['package_title'], $start, $end, $pax, (int)$package['operator_id'], (int)$package['package_id'])) throw new DomainException('This package has insufficient availability for the selected date and guests.');
        $serviceRows[] = PaymentReceiptLine('Tour package — ' . $package['package_title'], (float)$package['price'] * $pax, 'Package inclusions are bundled.', $pax, (float)$package['price']);
    }
    $fees = additionalFeesForBooking(getAdditionalFees($pdo));
    $category = (string)($input['eco_category'] ?? '');
    if (!array_key_exists($category, $fees['environmental'])) throw new DomainException('Choose an environmental-fee category.');
    $environmental = BookingEnvironmentalFee($adults, $category, $fees['environmental']);
    $feeRows = [PaymentReceiptLine('Environmental fee', $environmental, ucfirst($category) . ' category; adults only.', $adults, (float)$fees['environmental'][$category])];
    $entrance = $docking = 0.0;
    foreach ($locations as $location) {
        $entranceRate = $fees['entrance'][$location] ?? 0;
        if (is_array($entranceRate)) $entranceRate = $entranceRate[$tourType === 'overnight' ? 'overnight' : 'day'];
        $entry = round($entranceRate * $pax, 2);
        $dockRate = (float)($fees['docking'][$location] ?? 0);
        $dock = round($dockRate * count($boats), 2);
        $entrance += $entry;
        $docking += $dock;
        if ($entry > 0) $feeRows[] = PaymentReceiptLine('Entrance fee — ' . $location, $entry, 'Per passenger, including adults and children.', $pax, (float)$entranceRate);
        if ($dock > 0) $feeRows[] = PaymentReceiptLine('Docking / landing fee — ' . $location, $dock, '', count($boats), $dockRate);
    }
    $other = ItourValidationMoney($input['expense_other'] ?? 0, 'Other fees');
    if ($other > 0) $feeRows[] = PaymentReceiptLine('Other fees', $other);
    $service = round(array_sum(array_column($serviceRows, 'amount')), 2);
    return ['service_amount' => $service, 'environmental' => $environmental, 'entrance' => $entrance, 'docking' => $docking, 'other' => $other,
        'total' => round($service + $environmental + $entrance + $docking + $other, 2), 'service_rows' => $serviceRows, 'fee_rows' => $feeRows,
        'entrance_guests' => $pax, 'guide_ids' => $guideIds, 'boat_ids' => $boats, 'capacity' => $capacity, 'locations' => $locations];
}
