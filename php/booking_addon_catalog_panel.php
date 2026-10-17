<?php
declare(strict_types=1);
require_once __DIR__ . '/booking_addon_catalog.php';
function AddonCatalogPanel(PDO $pdo, string $role, int $ownerId, int $hotelId, string $endpoint): void
{
    $ready = true;
    try { AddonCatalogEnsureSchema($pdo); $items = AddonCatalogOwned($pdo, $role, $ownerId, $hotelId); }
    catch (Throwable $error) { $ready = false; $items = []; error_log('Add-on catalog unavailable: ' . $error->getMessage()); }
    $esc = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    $scope = $role === 'hotel_admin' ? 'Available only for room bookings at your hotel or resort.'
        : ($role === 'operator' ? 'Available only for tour package bookings.' : 'Available for boat, tour guide, and tour package bookings.');
    ?>
    <article class="am-card addon-catalog" data-addon-manager data-endpoint="<?= $esc($endpoint) ?>" data-role="<?= $esc($role) ?>" data-csrf="<?= $esc(AppCsrfToken($role, 'addon_catalog')) ?>">
      <header class="am-card-header"><div class="am-card-heading"><span class="am-card-icon"><svg viewBox="0 0 24 24" aria-hidden="true"><rect x="4" y="4" width="16" height="16" rx="3"/><path d="M12 8v8M8 12h8"/></svg></span><div><h3>Booking add-ons</h3><p><?= $esc($scope) ?></p></div></div></header>
      <div class="am-card-body">
        <p class="addon-catalog-intro">Create optional extras tourists can select before paying. Each price is per item, charged once per booking. Tourists can choose a quantity.</p>
        <div class="addon-catalog-editor">
          <input type="hidden" data-addon-id value="0" form="addonCatalogIndependent">
          <div class="am-field"><label for="catalogAddonName">Add-on name</label><input id="catalogAddonName" data-addon-name aria-describedby="catalogAddonNotice" form="addonCatalogIndependent" maxlength="160" placeholder="e.g. Packed lunch"></div>
          <div class="am-field"><label for="catalogAddonPrice">Price per item (₱)</label><input id="catalogAddonPrice" data-addon-price aria-describedby="catalogAddonNotice" form="addonCatalogIndependent" type="number" min="0.01" max="1000000" step="0.01" placeholder="0.00"></div>
          <div class="am-field"><label for="catalogAddonActive">Availability</label><select id="catalogAddonActive" data-addon-active form="addonCatalogIndependent"><option value="1">Available</option><option value="0">Hidden</option></select></div>
        </div>
        <div class="addon-catalog-actions"><button type="button" class="am-save-button" data-addon-save <?= !$ready ? 'disabled' : '' ?>><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg><span>Add option</span></button><button type="button" class="am-button" data-addon-cancel hidden>Cancel edit</button></div>
        <div id="catalogAddonNotice" class="addon-catalog-notice<?= !$ready ? ' error' : '' ?>" role="status" aria-live="polite" data-addon-notice <?= $ready ? 'hidden' : '' ?>><svg viewBox="0 0 24 24" aria-hidden="true" data-addon-notice-icon><circle cx="12" cy="12" r="9"/><path d="M12 7v6M12 17h.01"/></svg><span data-addon-notice-text><?= !$ready ? 'Add-ons are temporarily unavailable. Please contact the website administrator.' : '' ?></span></div>
        <div class="addon-catalog-list" data-addon-list></div>
        <p class="addon-catalog-help">Edit prices for future bookings, or hide an option to stop offering it. Existing bookings keep the names and prices selected at checkout.</p>
      </div>
      <script type="application/json" data-addon-items><?= json_encode($items, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE) ?></script>
    </article>
    <?php
}
