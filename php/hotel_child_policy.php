<?php
declare(strict_types=1);
require_once __DIR__ . '/input_validation.php';

const HO_CHILD_POLICY_VERSION = 'hotel-child-v1';
const HO_CHILD_NIGHTLY_RATE = 250;

/** Detection only. This feature never creates or alters a table at runtime. */
function HoChildTableExists(PDO $pdo, string $table): bool
{
    if (!in_array($table, ['hotel_room_child_policies', 'hotel_booking_child_policies'], true)) throw new InvalidArgumentException('Invalid policy table.');
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
        $query = $pdo->prepare("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name=?");
    } else {
        $query = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
    }
    $query->execute([$table]);
    return (int)$query->fetchColumn() > 0;
}

function HoChildRequireStorage(PDO $pdo): void
{
    if (!HoChildTableExists($pdo, 'hotel_booking_child_policies') || !HoChildTableExists($pdo, 'hotel_room_child_policies')) {
        throw new DomainException('Hotel child-policy storage is not ready. Please contact the website administrator before booking.');
    }
}

function HoChildSuggestedAllowance(int $adults, int $configured): int
{
    return $adults === 2 ? 2 : ($adults >= 3 && $adults <= 6 ? 3 : max(0, $configured));
}

/** Add approved policy data without changing legacy capacity fields. */
function HoChildAttachRooms(PDO $pdo, array $rooms): array
{
    if (!$rooms || !HoChildTableExists($pdo, 'hotel_room_child_policies')) return $rooms;
    $ids = array_map(static fn(array $room): int => (int)$room['id'], $rooms);
    $query = $pdo->prepare('SELECT * FROM hotel_room_child_policies WHERE hotel_room_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')');
    $query->execute($ids);
    $policies = [];
    foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $row) $policies[(int)$row['hotel_room_id']] = $row;
    foreach ($rooms as &$room) $room['child_policy_approval'] = $policies[(int)$room['id']] ?? null;
    unset($room);
    return $rooms;
}

function HoChildRoomPolicy(array $room): array
{
    $adults = max(1, (int)($room['capacity_adults'] ?? 1));
    $configured = max(0, (int)($room['capacity_children'] ?? 0));
    $fallbackAllowance = $adults >= 2 && $adults <= 6 ? min($configured, HoChildSuggestedAllowance($adults, $configured)) : $configured;
    $approval = $room['child_policy_approval'] ?? null;
    $approved = is_array($approval) && (int)$approval['adult_capacity'] === $adults;
    return [
        'adult_capacity' => $adults,
        'child_allowance' => $approved ? (int)$approval['max_children'] : $fallbackAllowance,
        'max_occupancy' => $approved ? (int)$approval['max_occupancy'] : $adults + $configured,
        'explicitly_approved' => $approved,
        'approval_id' => $approved ? (int)$approval['hotel_room_id'] : null,
        'approved_at' => $approved ? (string)$approval['approved_at'] : null,
    ];
}

function HoChildAges(mixed $ages, int $children): array
{
    if (is_string($ages)) $ages = $ages === '' ? [] : explode(',', $ages);
    if (!is_array($ages) || ($ages !== [] && array_keys($ages) !== range(0, count($ages) - 1)) || count($ages) !== $children) throw new InvalidArgumentException('Select an age for every child.');
    return array_values(array_map(static fn($age): int => ItourValidationInt($age, 'Child age (0–17; age 18+ is an adult)', 0, 17), $ages));
}

function HoChildParty(mixed $adults, mixed $children, mixed $ages): array
{
    $adults = ItourValidationInt($adults, 'Adults', 1, 100);
    $children = ItourValidationInt($children, 'Children', 0, 100);
    if ($adults + $children > 100) throw new InvalidArgumentException('A maximum of 100 occupants is allowed.');
    return ['adults' => $adults, 'children' => $children, 'child_ages' => HoChildAges($ages, $children)];
}

function HoChildEligibility(array $room, array $party): array
{
    $policy = HoChildRoomPolicy($room);
    $reason = '';
    if (($room['status'] ?? 'active') !== 'active') $reason = 'Room is inactive';
    elseif ($party['adults'] > $policy['adult_capacity']) $reason = 'Maximum adult capacity exceeded';
    elseif ($party['children'] > $policy['child_allowance']) $reason = 'Maximum child allowance exceeded';
    elseif ($party['adults'] + $party['children'] > $policy['max_occupancy']) $reason = 'Maximum approved occupancy exceeded';
    return ['eligible' => $reason === '', 'reason' => $reason, 'occupancy' => $policy];
}

function HoChildQuote(array $room, mixed $adults, mixed $children, mixed $ages, int $nights): array
{
    $party = HoChildParty($adults, $children, $ages);
    $eligibility = HoChildEligibility($room, $party);
    if (!$eligibility['eligible']) throw new DomainException($eligibility['reason']);
    if ($nights < 1) throw new InvalidArgumentException('Invalid number of nights.');
    $paying = count(array_filter($party['child_ages'], static fn(int $age): bool => $age >= 6));
    $roomTotal = round((float)$room['price'] * $nights, 2);
    $fee = $paying * HO_CHILD_NIGHTLY_RATE * $nights;
    return $party + [
        'policy_version' => HO_CHILD_POLICY_VERSION, 'occupancy' => $eligibility['occupancy'],
        'free_children' => $party['children'] - $paying, 'paying_children' => $paying,
        'child_rate' => HO_CHILD_NIGHTLY_RATE, 'child_fee_per_night' => $paying * HO_CHILD_NIGHTLY_RATE,
        'child_fee_total' => $fee, 'nights' => $nights, 'room_rate' => (float)$room['price'], 'room_subtotal' => $roomTotal,
        'rooms_booked' => 1,
        'total_before_addons' => round($roomTotal + $fee, 2), 'bedding_included' => false,
    ];
}

function HoChildSaveSnapshot(PDO $pdo, int $bookingId, array $snapshot): void
{
    HoChildRequireStorage($pdo);
    $query = $pdo->prepare('INSERT INTO hotel_booking_child_policies (hotel_booking_id, policy_version, snapshot_json) VALUES (?, ?, ?)');
    $query->execute([$bookingId, HO_CHILD_POLICY_VERSION, json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)]);
}

function HoChildSnapshot(PDO $pdo, int $bookingId): ?array
{
    if (!HoChildTableExists($pdo, 'hotel_booking_child_policies')) return null;
    $query = $pdo->prepare('SELECT snapshot_json FROM hotel_booking_child_policies WHERE hotel_booking_id=?');
    $query->execute([$bookingId]);
    $value = $query->fetchColumn();
    if ($value === false) return null;
    $snapshot = json_decode((string)$value, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($snapshot) || ($snapshot['policy_version'] ?? '') !== HO_CHILD_POLICY_VERSION) throw new DomainException('Invalid saved hotel child policy.');
    return $snapshot;
}

/** New reservations use their immutable approved limits; historical ones retain legacy handling. */
function HoChildValidateSnapshot(array $booking, array $snapshot): void
{
    $party = HoChildParty($booking['adults'], $booking['children'], $snapshot['child_ages']);
    if ($party['adults'] !== (int)$snapshot['adults'] || $party['children'] !== (int)$snapshot['children']) throw new DomainException('Guest counts differ from the saved reservation.');
    $limits = $snapshot['occupancy'];
    if ($party['adults'] > $limits['adult_capacity'] || $party['children'] > $limits['child_allowance'] || $party['adults'] + $party['children'] > $limits['max_occupancy']) throw new DomainException('The saved reservation exceeds approved occupancy.');
}

function HoChildSnapshotValid(array $booking, array $snapshot): bool
{
    try { HoChildValidateSnapshot($booking, $snapshot); return true; }
    catch (DomainException|InvalidArgumentException $exception) { return false; }
}

/** A room-scoped mutex only; payment session creation still happens after commit/unlock. */
function HoChildReservationLock(PDO $pdo, int $hotelId, int $roomId): string
{
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') return '';
    $name = 'hotel-room-' . $hotelId . '-' . $roomId;
    $query = $pdo->prepare('SELECT GET_LOCK(?, 10)');
    $query->execute([$name]);
    if ((int)$query->fetchColumn() !== 1) throw new DomainException('This room is being reserved. Please try again.');
    return $name;
}

function HoChildReservationUnlock(PDO $pdo, string $name): void
{
    if ($name === '') return;
    $query = $pdo->prepare('SELECT RELEASE_LOCK(?)'); $query->execute([$name]);
}

function HoChildSummary(array $snapshot): string
{
    $ages = array_map(static fn(int $age): string => $age . ' (' . ($age <= 5 ? 'free' : 'PHP 250/night') . ')', $snapshot['child_ages']);
    return 'Child ages: ' . ($ages ? implode(', ', $ages) : 'None') . '. Child accommodation: PHP ' . number_format((float)$snapshot['child_fee_total'], 2) . ' for ' . (int)$snapshot['nights'] . ' night(s). Existing bedding shared; extra bedding is optional.';
}

function HoChildCheckClientTotal(array $input, array $quote): void
{
    foreach (['child_fee_total' => 'child_fee_total', 'child_fee_per_night' => 'child_fee_per_night', 'unit_price' => 'room_rate', 'room_rate' => 'room_rate'] as $field => $key) {
        if (array_key_exists($field, $input) && abs(ItourValidationMoney($input[$field], 'Child accommodation fee') - $quote[$key]) > 0.009) throw new DomainException('The submitted child fee does not match the current quote. Refresh and try again.');
    }
}

function HoChildCheckSubmittedTotal(array $input, float $total): void
{
    foreach (['total', 'total_amount', 'total_price', 'booking_total', 'grand_total', 'walkin_total'] as $field) {
        if (array_key_exists($field, $input) && abs(ItourValidationMoney($input[$field], 'Booking total') - $total) > 0.009) throw new DomainException('The submitted total does not match the current hotel quote. Refresh and try again.');
    }
}

function HoChildApprovalInput(PDO $pdo, int $adults, int $configured, array $input): ?array
{
    if (($input['approve_child_occupancy'] ?? '') !== '1') return null;
    HoChildRequireStorage($pdo);
    $children = ItourValidationInt($input['policy_max_children'] ?? null, 'Approved child allowance', 0, 100);
    $total = ItourValidationInt($input['policy_max_occupancy'] ?? null, 'Approved maximum occupancy', 1, 100);
    if ($adults >= 2 && $adults <= 6 && $children > HoChildSuggestedAllowance($adults, $configured)) throw new InvalidArgumentException('The child allowance exceeds the standard limit for this room.');
    if ($adults === 1 && $children > $configured) throw new InvalidArgumentException('A single-adult room must retain its configured child allowance.');
    if ($total < $adults || $children + $adults < $total) throw new InvalidArgumentException('Approved occupancy must be at least adult capacity and no greater than adult capacity plus child allowance.');
    return ['adult_capacity' => $adults, 'max_children' => $children, 'max_occupancy' => $total];
}

function HoChildSaveApproval(PDO $pdo, int $roomId, int $adminId, array $approval): void
{
    $suffix = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite'
        ? ' ON CONFLICT(hotel_room_id) DO UPDATE SET adult_capacity=excluded.adult_capacity, max_children=excluded.max_children, max_occupancy=excluded.max_occupancy, approved_by=excluded.approved_by, approved_at=CURRENT_TIMESTAMP'
        : ' ON DUPLICATE KEY UPDATE adult_capacity=VALUES(adult_capacity), max_children=VALUES(max_children), max_occupancy=VALUES(max_occupancy), approved_by=VALUES(approved_by), approved_at=CURRENT_TIMESTAMP';
    $query = $pdo->prepare('INSERT INTO hotel_room_child_policies (hotel_room_id, adult_capacity, max_children, max_occupancy, approved_by) VALUES (?, ?, ?, ?, ?)' . $suffix);
    $query->execute([$roomId, $approval['adult_capacity'], $approval['max_children'], $approval['max_occupancy'], $adminId]);
}

function HoChildRoomSettings(array $room): void
{
    $policy = HoChildRoomPolicy($room);
    $suggested = HoChildSuggestedAllowance($policy['adult_capacity'], (int)($room['capacity_children'] ?? 0));
    ?>
    <fieldset class="ho-image-editor-section" data-child-policy-settings>
      <legend>Child occupancy approval</legend>
      <p>Existing capacity fields remain unchanged. Ages 0–5 are free; ages 6–17 add PHP 250 per child per night. All occupants share existing bedding unless extra bedding is selected separately.</p>
      <div class="ho-room-row">
        <label>Maximum additional children<input type="number" name="policy_max_children" min="0" max="100" value="<?= $policy['explicitly_approved'] ? $policy['child_allowance'] : $suggested ?>"></label>
        <label>Approved maximum total occupants<input type="number" name="policy_max_occupancy" min="1" max="100" value="<?= $policy['max_occupancy'] ?>"></label>
      </div>
      <label><input type="checkbox" name="approve_child_occupancy" value="1"> I confirm this room is approved for the total occupancy entered above, including safe infant arrangements. Save this approval for future reservations.</label>
      <p>Suggested child allowance: <?= $suggested ?>. No expanded occupancy is enabled until explicitly approved. Existing reservations retain their saved terms. To revoke an allowance for future reservations, approve a child allowance of zero and an appropriate total limit.</p>
    </fieldset>
    <?php
}
