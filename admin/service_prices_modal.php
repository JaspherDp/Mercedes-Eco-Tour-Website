<?php
if (!isset($pdo)) {
    require __DIR__ . '/../php/db_connection.php';
}
require_once __DIR__ . '/../php/additional_fees_helper.php';
$servicePricesCsrf = AppCsrfToken('admin', 'catalog_content');

$priceRows = $pdo->query(
    "SELECT service_type, day_tour_price, overnight_price
     FROM service_prices
     WHERE service_type IN ('boat', 'tourguide') AND is_active = 1"
)->fetchAll(PDO::FETCH_ASSOC);

$servicePrices = [];
foreach ($priceRows as $priceRow) {
    $servicePrices[$priceRow['service_type']] = $priceRow;
}
$additionalFees = getAdditionalFees($pdo);
$additionalFeeGroups = [];
foreach ($additionalFees as $feeCode => $fee) {
    $additionalFeeGroups[$fee['category']][$feeCode] = $fee;
}

$priceUpdateUrl = strpos($_SERVER['SCRIPT_NAME'] ?? '', '/admin/') !== false
    ? '../php/update_service_prices.php'
    : 'php/update_service_prices.php';
?>

<button type="button" class="catalog-secondary-btn service-prices-open" id="servicePricesOpen" aria-haspopup="dialog" aria-controls="servicePricesModal">
  <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M15.5 8.5c-.7-.7-1.8-1-3-1-1.7 0-3 .8-3 2s1.1 1.8 3 2.1 3 1 3 2.3-1.3 2.2-3.1 2.2c-1.2 0-2.4-.4-3.2-1.2M12.4 5.8v12.4"/></svg>
  Prices
</button>

<div class="service-prices-modal" id="servicePricesModal" role="dialog" aria-modal="true" aria-labelledby="servicePricesTitle" aria-hidden="true">
  <div class="service-prices-dialog">
    <div class="catalog-modal-heading service-prices-heading">
      <div>
        <h3 id="servicePricesTitle">Service prices</h3>
        <p>Manage service rates and all additional tourism fees used in public estimates.</p>
      </div>
      <button type="button" class="service-prices-close" id="servicePricesClose" aria-label="Close prices">&times;</button>
    </div>

    <form id="servicePricesForm">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($servicePricesCsrf, ENT_QUOTES, 'UTF-8') ?>">
      <div class="service-prices-body">
        <section class="service-price-group" aria-labelledby="boatPricesTitle">
          <div class="service-price-group-title">
            <span class="service-price-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M3 18c2 2 4 2 6 0 2 2 4 2 6 0 2 2 4 2 6 0M5 14h14l-2.5 4H7.5L5 14ZM8 14V7h8v7"/></svg></span>
            <div><h4 id="boatPricesTitle">Boat rates</h4><p>Base rates for vessel services.</p></div>
          </div>
          <div class="service-price-fields">
            <label>Day tour
              <span class="service-price-input"><span>₱</span><input type="number" min="0" step="0.01" name="boat_day" value="<?= htmlspecialchars((string)($servicePrices['boat']['day_tour_price'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" required></span>
            </label>
            <label>Overnight
              <span class="service-price-input"><span>₱</span><input type="number" min="0" step="0.01" name="boat_overnight" value="<?= htmlspecialchars((string)($servicePrices['boat']['overnight_price'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" required></span>
            </label>
          </div>
        </section>

        <section class="service-price-group" aria-labelledby="guidePricesTitle">
          <div class="service-price-group-title">
            <span class="service-price-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><circle cx="12" cy="7" r="3"/><path d="M5 21v-2a7 7 0 0 1 14 0v2"/></svg></span>
            <div><h4 id="guidePricesTitle">Tour guide rates</h4><p>Base rates for guide services.</p></div>
          </div>
          <div class="service-price-fields">
            <label>Day tour
              <span class="service-price-input"><span>₱</span><input type="number" min="0" step="0.01" name="tourguide_day" value="<?= htmlspecialchars((string)($servicePrices['tourguide']['day_tour_price'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" required></span>
            </label>
            <label>Overnight
              <span class="service-price-input"><span>₱</span><input type="number" min="0" step="0.01" name="tourguide_overnight" value="<?= htmlspecialchars((string)($servicePrices['tourguide']['overnight_price'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" required></span>
            </label>
          </div>
        </section>

        <?php foreach ($additionalFeeGroups as $category => $fees): ?>
        <section class="service-price-group service-additional-group" aria-labelledby="<?= htmlspecialchars(strtolower($category), ENT_QUOTES, 'UTF-8') ?>FeesTitle">
          <div class="service-price-group-title">
            <span class="service-price-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M12 3v18M7 7h7.5a2.5 2.5 0 0 1 0 5h-5a2.5 2.5 0 0 0 0 5H17"/></svg></span>
            <div>
              <h4 id="<?= htmlspecialchars(strtolower($category), ENT_QUOTES, 'UTF-8') ?>FeesTitle"><?= htmlspecialchars($category, ENT_QUOTES, 'UTF-8') ?> fees</h4>
              <p><?= $category === 'Environmental' ? 'Rates per eligible visitor.' : ($category === 'Entrance' ? 'Destination entrance rates per head.' : ($category === 'Docking' ? 'Landing rates charged per boat.' : 'Daily equipment rental rates.')) ?></p>
            </div>
          </div>
          <div class="service-price-fields service-additional-fields">
            <?php foreach ($fees as $feeCode => $fee): ?>
            <label><?= htmlspecialchars($fee['label'], ENT_QUOTES, 'UTF-8') ?>
              <small><?= htmlspecialchars($fee['unit'], ENT_QUOTES, 'UTF-8') ?></small>
              <span class="service-price-input"><span>₱</span><input type="number" min="0" step="0.01" name="additional_fees[<?= htmlspecialchars($feeCode, ENT_QUOTES, 'UTF-8') ?>]" value="<?= htmlspecialchars(number_format((float)$fee['amount'], 2, '.', ''), ENT_QUOTES, 'UTF-8') ?>" required></span>
            </label>
            <?php endforeach; ?>
          </div>
        </section>
        <?php endforeach; ?>

        <p class="service-prices-feedback" id="servicePricesFeedback" role="status" aria-live="polite"></p>
      </div>

      <div class="service-prices-actions">
        <button type="button" class="catalog-secondary-btn" id="servicePricesCancel">Cancel</button>
        <button type="submit" class="catalog-primary-btn" id="servicePricesSave">Save prices</button>
      </div>
    </form>
  </div>
</div>

<script>
(() => {
  const modal = document.getElementById('servicePricesModal');
  const openButton = document.getElementById('servicePricesOpen');
  const closeButton = document.getElementById('servicePricesClose');
  const cancelButton = document.getElementById('servicePricesCancel');
  const form = document.getElementById('servicePricesForm');
  const saveButton = document.getElementById('servicePricesSave');
  const feedback = document.getElementById('servicePricesFeedback');

  if (!modal || !openButton || !form) return;

  const openModal = () => {
    modal.style.display = 'flex';
    modal.setAttribute('aria-hidden', 'false');
    document.body.classList.add('modal-open');
    feedback.textContent = '';
    feedback.className = 'service-prices-feedback';
    window.setTimeout(() => form.querySelector('input')?.focus(), 30);
  };

  const closeModal = () => {
    modal.style.display = 'none';
    modal.setAttribute('aria-hidden', 'true');
    document.body.classList.remove('modal-open');
    openButton.focus();
  };

  openButton.addEventListener('click', openModal);
  closeButton?.addEventListener('click', closeModal);
  cancelButton?.addEventListener('click', closeModal);
  modal.addEventListener('click', event => {
    if (event.target === modal) closeModal();
  });
  document.addEventListener('keydown', event => {
    if (event.key === 'Escape' && modal.style.display === 'flex') closeModal();
  });

  form.addEventListener('submit', async event => {
    event.preventDefault();
    saveButton.disabled = true;
    saveButton.textContent = 'Saving…';
    feedback.textContent = '';
    feedback.className = 'service-prices-feedback';

    try {
      const response = await fetch('<?= htmlspecialchars($priceUpdateUrl, ENT_QUOTES, 'UTF-8') ?>', {
        method: 'POST',
        body: new FormData(form)
      });
      const result = await response.json();
      if (!result.success) throw new Error(result.message || 'Unable to update prices.');
      feedback.textContent = result.message || 'Prices updated successfully.';
      feedback.classList.add('success');
    } catch (error) {
      feedback.textContent = error.message || 'An unexpected error occurred.';
      feedback.classList.add('error');
    } finally {
      saveButton.disabled = false;
      saveButton.textContent = 'Save prices';
    }
  });
})();
</script>
