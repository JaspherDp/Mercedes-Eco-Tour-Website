<?php
declare(strict_types=1);
require_once __DIR__ . '/booking_addon_catalog.php';

/** Merge owned saved choices with manual rows before the existing charge validators run. */
function StaffBookingAddonInput(PDO $pdo, string $role, int $ownerId, int $hotelId, array $input): array
{
    $selected = $input['saved_addon_id'] ?? [];
    if (!is_array($selected) || count($selected) > 25) throw new InvalidArgumentException('Choose up to 25 saved add-ons.');
    if (!$selected) return $input;
    $available = array_column(AddonCatalogStaffAvailable($pdo, $role, $ownerId, $hotelId), null, 'addon_id');
    $noteKey = $role === 'hotel_admin' ? 'notes' : 'note';
    $keys = ['expense_type', 'expense_name', 'quantity', 'unit_price', $noteKey];
    $count = count(is_array($input['expense_type'] ?? null) ? $input['expense_type'] : []);
    if ($count > 25) throw new InvalidArgumentException('Add no more than 25 add-on items.');
    foreach ($keys as $key) {
        $list = $input[$key] ?? [];
        if (!is_array($list) || count($list) !== $count || ($list && array_keys($list) !== range(0, $count - 1))) {
            throw new InvalidArgumentException('Add-on fields must contain matching item rows.');
        }
        $input[$key] = $list;
    }
    $manual = array_fill_keys($keys, []);
    for ($index = 0; $index < $count; $index++) {
        // An untouched manual row is optional when saved items have been checked.
        $empty = true;
        foreach (['expense_type', 'expense_name', 'unit_price', $noteKey] as $key) {
            if (!is_scalar($input[$key][$index]) || trim((string)$input[$key][$index]) !== '') $empty = false;
        }
        if ($empty && (string)$input['quantity'][$index] === '1') continue;
        foreach ($keys as $key) $manual[$key][] = $input[$key][$index];
    }
    $quantities = $input['saved_addon_quantity'] ?? [];
    if (!is_array($quantities)) throw new InvalidArgumentException('Invalid saved add-on quantities.');
    $seen = [];
    foreach ($selected as $rawId) {
        $id = ItourValidationInt($rawId, 'Add-on ID', 1, PHP_INT_MAX);
        if (!isset($available[$id]) || isset($seen[$id])) throw new InvalidArgumentException('A saved add-on is no longer available. Refresh the page and choose again.');
        $seen[$id] = true;
        $quantity = ItourValidationInt($quantities[$id] ?? 1, 'Add-on quantity', 1, 1000);
        $manual['expense_type'][] = $role === 'hotel_admin' ? 'Others' : 'others';
        $manual['expense_name'][] = $available[$id]['name'];
        $manual['quantity'][] = $quantity;
        $manual['unit_price'][] = $available[$id]['price'];
        $manual[$noteKey][] = '';
    }
    if (count($manual['expense_type']) > 25) throw new InvalidArgumentException('Select and enter no more than 25 add-on items in total.');
    return array_replace($input, $manual);
}

function StaffBookingAddonPicker(PDO $pdo, string $role, int $ownerId, int $hotelId = 0): void
{
    $items = AddonCatalogStaffAvailable($pdo, $role, $ownerId, $hotelId);
    // Saved choices are resolved again on the server; manual charges keep their original form.
    ?>
    <script type="application/json" id="staffBookingAddonCatalog"><?= json_encode($items, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE) ?></script>
    <link rel="stylesheet" href="styles/staff-booking-addon-picker.css?v=<?= filemtime(__DIR__ . '/../styles/staff-booking-addon-picker.css') ?>">
    <script src="js/staff-booking-addon-picker.js?v=<?= filemtime(__DIR__ . '/../js/staff-booking-addon-picker.js') ?>"></script>
    <?php
}
