<?php
declare(strict_types=1);
require_once __DIR__ . '/booking_addon_catalog.php';
function BookingAddonPicker(PDO $pdo, string $domain, int $hotelId = 0): void
{
    $items = AddonCatalogAvailable($pdo, $domain, $hotelId);
    $esc = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    ?>
    <section class="booking-addon-picker" data-booking-addon-picker aria-labelledby="bookingAddonHeading">
      <div class="booking-addon-heading"><span aria-hidden="true"><svg viewBox="0 0 24 24"><rect x="4" y="4" width="16" height="16" rx="3"/><path d="M12 8v8M8 12h8"/></svg></span><div><h3 id="bookingAddonHeading">Add a little extra</h3><p>Optional add-ons · priced per item, once per booking</p></div><small>Optional</small></div>
      <?php if (!$items): ?><p class="booking-addon-empty">No optional add-ons are available at the moment.</p><?php endif; ?>
      <div class="booking-addon-options">
      <?php foreach ($items as $item): $id = (int)$item['addon_id']; ?>
        <div class="booking-addon-option" data-addon-option data-package-only="<?= ($item['owner_role'] ?? '') === 'operator' ? '1' : '0' ?>" data-id="<?= $id ?>" data-price="<?= $esc($item['price']) ?>" data-name="<?= $esc($item['name']) ?>">
          <label class="booking-addon-choice"><input type="checkbox" name="catalog_addon_selected[]" value="<?= $id ?>" data-addon-check><span><strong><?= $esc($item['name']) ?></strong><small>₱<?= number_format((float)$item['price'], 2) ?> per item</small></span></label>
          <label class="booking-addon-quantity">Qty<input type="number" data-addon-quantity name="catalog_addon_quantity[<?= $id ?>]" min="1" max="100" step="1" value="1" disabled aria-label="<?= $esc($item['name']) ?> quantity"></label>
        </div>
      <?php endforeach; ?>
      </div>
      <?php if ($items): ?><div class="booking-addon-subtotal"><span>Selected add-ons</span><strong data-addon-subtotal>₱0.00</strong></div><?php endif; ?>
      <p class="booking-addon-limit" data-addon-limit role="status" hidden>Select up to 25 add-ons per booking.</p>
    </section>
    <?php
}
