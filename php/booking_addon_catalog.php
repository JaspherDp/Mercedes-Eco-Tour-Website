<?php
declare(strict_types=1);
require_once __DIR__ . '/input_validation.php';

function AddonCatalogEnsureSchema(PDO $pdo): void
{
    try { $pdo->query('SELECT addon_id FROM booking_addon_catalog LIMIT 0'); return; }
    catch (PDOException $error) {
        if (($error->errorInfo[1] ?? 0) !== 1146 && !str_contains($error->getMessage(), 'no such table')) throw $error;
    }
    $pdo->exec((string)file_get_contents(__DIR__ . '/../booking_addon_catalog_migration.sql'));
}

function AddonCatalogOwner(string $role, int $id, int $hotelId = 0): void
{
    if (!in_array($role, ['admin', 'operator', 'hotel_admin'], true) || $id < 1 || ($role === 'hotel_admin' && $hotelId < 1)) {
        throw new InvalidArgumentException('Invalid add-on owner.');
    }
}

function AddonCatalogOwned(PDO $pdo, string $role, int $id, int $hotelId = 0): array
{
    AddonCatalogOwner($role, $id, $hotelId);
    $query = $pdo->prepare('SELECT addon_id, name, price, is_active FROM booking_addon_catalog WHERE owner_role = ? AND owner_id = ? AND COALESCE(hotel_resort_id, 0) = CAST(? AS SIGNED) ORDER BY is_active DESC, name ASC, addon_id ASC');
    $query->execute([$role, $id, $role === 'hotel_admin' ? $hotelId : 0]);
    return $query->fetchAll(PDO::FETCH_ASSOC);
}

/** Public catalog never includes private account identifiers. Missing migration leaves legacy bookings usable. */
function AddonCatalogAvailable(PDO $pdo, string $domain, int $hotelId = 0): array
{
    if (!in_array($domain, ['hotel', 'package', 'boat', 'tourguide'], true)) throw new InvalidArgumentException('Invalid add-on booking type.');
    try {
        if ($domain === 'hotel') {
            $query = $pdo->prepare("SELECT addon_id, name, price, owner_role FROM booking_addon_catalog WHERE is_active = 1 AND owner_role = 'hotel_admin' AND hotel_resort_id = ? ORDER BY name, addon_id");
            $query->execute([$hotelId]);
        } elseif ($domain === 'package') {
            $query = $pdo->query("SELECT addon_id, name, price, owner_role FROM booking_addon_catalog WHERE is_active = 1 AND owner_role IN ('admin', 'operator') AND hotel_resort_id IS NULL ORDER BY name, addon_id");
        } else {
            $query = $pdo->query("SELECT addon_id, name, price, owner_role FROM booking_addon_catalog WHERE is_active = 1 AND owner_role = 'admin' AND hotel_resort_id IS NULL ORDER BY name, addon_id");
        }
        return $query->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $error) {
        if (in_array((string)$error->getCode(), ['42S02', 'HY000'], true)) {
            // Only tolerate a missing catalog, never a different database error.
            if (($error->errorInfo[1] ?? 0) === 1146 || str_contains($error->getMessage(), 'no such table')) return [];
        }
        throw $error;
    }
}

function AddonCatalogSave(PDO $pdo, string $role, int $ownerId, int $hotelId, array $input): int
{
    AddonCatalogOwner($role, $ownerId, $hotelId);
    $id = ItourValidationInt($input['addon_id'] ?? 0, 'Add-on ID', 0, PHP_INT_MAX);
    $name = ItourValidationText($input['name'] ?? '', 'Add-on name', 160, true);
    $price = ItourValidationMoney($input['price'] ?? '', 'Add-on price', 1000000, false);
    $active = ItourValidationInt($input['is_active'] ?? 1, 'Availability', 0, 1);
    $property = $role === 'hotel_admin' ? $hotelId : null;
    if ($id > 0) {
        $query = $pdo->prepare('SELECT addon_id FROM booking_addon_catalog WHERE addon_id = ? AND owner_role = ? AND owner_id = ? AND COALESCE(hotel_resort_id, 0) = CAST(? AS SIGNED)');
        $query->execute([$id, $role, $ownerId, $property ?? 0]);
        if (!$query->fetchColumn()) throw new DomainException('This add-on is not available to your account.');
        $update = $pdo->prepare('UPDATE booking_addon_catalog SET name = ?, price = ?, is_active = ? WHERE addon_id = ? AND owner_role = ? AND owner_id = ? AND COALESCE(hotel_resort_id, 0) = CAST(? AS SIGNED)');
        $update->execute([$name, $price, $active, $id, $role, $ownerId, $property ?? 0]);
    } else {
        $insert = $pdo->prepare('INSERT INTO booking_addon_catalog (owner_role, owner_id, hotel_resort_id, name, price, is_active) VALUES (?, ?, ?, ?, ?, ?)');
        $insert->execute([$role, $ownerId, $property, $name, $price, $active]);
        $id = (int)$pdo->lastInsertId();
    }
    return $id;
}

/** Resolve only IDs and quantities; all names and prices come from the eligible server catalog. */
function AddonCatalogResolve(PDO $pdo, string $domain, int $hotelId, mixed $selection): array
{
    if (!is_array($selection) || count($selection) > 25 || ($selection !== [] && array_keys($selection) !== range(0, count($selection) - 1))) {
        throw new InvalidArgumentException('Choose no more than 25 add-ons.');
    }
    if (!$selection) return ['rows' => [], 'total' => 0.0];
    $available = array_column(AddonCatalogAvailable($pdo, $domain, $hotelId), null, 'addon_id');
    $rows = [];
    $seen = [];
    $minor = 0;
    foreach ($selection as $item) {
        if (!is_array($item)) throw new InvalidArgumentException('Invalid add-on selection.');
        $id = ItourValidationInt($item['addon_id'] ?? null, 'Add-on ID', 1, PHP_INT_MAX);
        $quantity = ItourValidationInt($item['quantity'] ?? 1, 'Add-on quantity', 1, 100);
        if (!isset($available[$id]) || isset($seen[$id])) throw new DomainException('A selected add-on is no longer available. Refresh the booking form and select your add-ons again.');
        $seen[$id] = true;
        $option = $available[$id];
        $unitMinor = (int)round((float)$option['price'] * 100);
        $amountMinor = $unitMinor * $quantity;
        $minor += $amountMinor;
        if ($unitMinor <= 0 || $minor > 1000000000) throw new InvalidArgumentException('Selected add-ons exceed the allowed booking total.');
        $rows[] = ['addon_id' => $id, 'name' => (string)$option['name'], 'quantity' => $quantity, 'unit_price' => $unitMinor / 100, 'amount' => $amountMinor / 100];
    }
    return ['rows' => $rows, 'total' => $minor / 100];
}

/** Called within paid-draft finalization's existing transaction; never changes totals a second time. */
function AddonCatalogRecordBooking(PDO $pdo, string $domain, int $bookingId, array $payload, bool $fullyPaid): void
{
    $rows = $payload['catalog_addons']['rows'] ?? [];
    if (!$rows) return;
    if ($domain === 'hotel') {
        $insert = $pdo->prepare('INSERT INTO hotel_booking_expenses (hotel_booking_id, hotel_resort_id, expense_type, expense_name, quantity, unit_price, total_amount, notes, payment_status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
        foreach ($rows as $row) $insert->execute([$bookingId, (int)$payload['hotel_resort_id'], 'others', $row['name'], $row['quantity'], $row['unit_price'], $row['amount'], 'Selected during booking', $fullyPaid ? 'paid' : 'unpaid']);
    } else {
        $insert = $pdo->prepare('INSERT INTO booking_expenses (booking_id, expense_type, expense_name, quantity, unit_price, amount, note) VALUES (?, ?, ?, ?, ?, ?, ?)');
        foreach ($rows as $row) $insert->execute([$bookingId, 'others', $row['name'], $row['quantity'], $row['unit_price'], $row['amount'], 'Selected during booking']);
    }
}
