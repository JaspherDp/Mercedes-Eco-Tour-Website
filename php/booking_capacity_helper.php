<?php
declare(strict_types=1);
require_once __DIR__ . '/input_validation.php';

/** Operational limit applies even when the catalog advertises a larger vessel. */
function BookingBoatCapacity(int $configured): int
{
    return max(0, min(8, $configured));
}

function BookingEnvironmentalFee(int $adults, string $category, array $rates): float
{
    if ($adults < 0 || $adults > 100 || !array_key_exists($category, $rates)) throw new DomainException('Select a valid environmental-fee category and adult count.');
    return round((float)$rates[$category] * $adults, 2);
}

/** Check storage before a checkout can collect money; never run DDL during payment. */
function BookingResourceStorageReady(PDO $pdo): void
{
    try {
        $pdo->query('SELECT booking_id, boat_id FROM booking_resource_boats LIMIT 0');
        $pdo->query('SELECT booking_id, guide_id FROM booking_resource_guides LIMIT 0');
    } catch (PDOException $error) {
        throw new DomainException('Multi-resource booking storage is not ready. Please contact the booking office.');
    }
}

function BookingRequiredResources(int $pax): int
{
    if ($pax < 1 || $pax > 100) throw new DomainException('A booking must have between 1 and 100 guests.');
    return (int)ceil($pax / 8);
}

function BookingResourceIds(mixed $primary, mixed $additional, string $label): array
{
    if (!is_array($additional) || count($additional) > 99) throw new DomainException('Choose valid additional ' . $label . ' selections.');
    $ids = [ItourValidationInt($primary, $label, 1, PHP_INT_MAX)];
    foreach ($additional as $id) $ids[] = ItourValidationInt($id, $label, 1, PHP_INT_MAX);
    if (count($ids) !== count(array_unique($ids))) throw new DomainException('Select each ' . $label . ' only once.');
    return $ids;
}

/** Actual lower boat capacities are retained; no vessel may carry over eight. */
function BookingValidateBoatCapacity(int $pax, array $capacities): void
{
    $required = BookingRequiredResources($pax);
    $effective = array_map('BookingBoatCapacity', $capacities);
    if (!$effective || in_array(0, $effective, true)) throw new DomainException('Choose boats with a valid passenger capacity.');
    $capacity = array_sum($effective);
    if (count($effective) < $required || $capacity < $pax) throw new DomainException($pax . ' guests need more seats. Selected boats provide ' . $capacity . ' seats; choose another available boat.');
    foreach (array_slice($effective, 1) as $extra) {
        if ($capacity - $extra >= $pax) throw new DomainException('Select only the additional boats needed to accommodate all guests. Remove the extra selection.');
    }
}

function BookingValidateGuideCapacity(int $pax, array $ids): void
{
    $required = BookingRequiredResources($pax);
    if (count($ids) !== $required) throw new DomainException($pax . ' guests require ' . $required . ' tour guide(s), with at most 8 passengers per guide. Select the required guides.');
}
