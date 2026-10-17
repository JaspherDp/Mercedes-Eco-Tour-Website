<?php
chdir(__DIR__ . '/..');
require_once __DIR__ . '/../php/session_security.php';
AppSessionStart();
require_once __DIR__ . '/../php/db_connection.php';
require_once __DIR__ . '/../php/admin_management_helper.php';
require_once __DIR__ . '/../php/additional_fees_helper.php';
adminManagementRequireLogin();
require_once __DIR__ . '/../php/booking_addon_catalog_panel.php';
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$adminId = (int)($_SESSION['admin_id'] ?? 0);
$assetPrefix = strtolower(basename(dirname((string)($_SERVER['SCRIPT_NAME'] ?? '')))) === 'admin' ? '../' : '';
$servicePrices = [];
try {
    $priceRows = $pdo->query("SELECT service_type, day_tour_price, overnight_price FROM service_prices WHERE service_type IN ('boat', 'tourguide') AND is_active = 1")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($priceRows as $priceRow) {
        $servicePrices[$priceRow['service_type']] = $priceRow;
    }
} catch (Throwable $error) {
    error_log('Service prices could not be loaded: ' . $error->getMessage());
}
$additionalFeeGroups = [];
$additionalFeesInstalled = false;
try {
    $additionalFeesInstalled = additionalFeeTableExists($pdo);
    foreach (getAdditionalFees($pdo) as $feeCode => $fee) {
        $additionalFeeGroups[$fee['category']][$feeCode] = $fee;
    }
} catch (Throwable $error) {
    error_log('Additional fees could not be loaded: ' . $error->getMessage());
}
$servicePricesReady = isset($servicePrices['boat'], $servicePrices['tourguide']) && $additionalFeesInstalled && $additionalFeeGroups !== [];
$servicePricesCsrf = AppCsrfToken('admin', 'catalog_content');
$priceUpdateUrl = $assetPrefix . 'php/update_service_prices.php';

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Pricing &amp; Add-ons | iTour Mercedes Admin</title>
  <link rel="icon" type="image/png" href="<?= $assetPrefix ?>img/newlogo.png?v=2">
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="<?= $assetPrefix ?>styles/admin_panel_theme.css">
  <link rel="stylesheet" href="<?= $assetPrefix ?>styles/admin_management.css?v=<?= (int)@filemtime(__DIR__ . '/../styles/admin_management.css') ?>">
  <link rel="stylesheet" href="<?= $assetPrefix ?>styles/booking-addon-catalog.css?v=4">
</head>
<body class="admin-settings-page admin-service-pricing-page">
<div class="admin-container">
  <?php include __DIR__ . '/admin_sidebar.php'; ?>
  <main class="main-content admin-management-main">
    <header class="admin-header admin-page-header">
      <div class="admin-header-left admin-page-title">
        <span class="admin-page-title-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M15 8.5c-.6-.7-1.6-1-2.7-1-1.7 0-3 .8-3 2s1.1 1.8 3 2.1 3 1 3 2.3-1.3 2.2-3.1 2.2c-1.2 0-2.4-.4-3.2-1.2M12 5.8v12.4"/></svg></span>
        <div class="admin-page-title-copy"><h2>Pricing &amp; Add-ons</h2><p class="admin-header-subtitle">Manage tour service rates, visitor fees, and booking extras</p></div>
      </div>
      <div class="admin-header-right am-header-actions">
        <button class="am-save-button" id="servicePricingSaveButton" type="submit" form="servicePricesForm" <?= !$servicePricesReady ? 'disabled' : '' ?>><svg viewBox="0 0 24 24"><path d="M5 4h12l2 2v14H5V4Z"/><path d="M8 4v6h8V4M8 20v-6h8v6"/></svg><span>Save prices</span></button>
      </div>
    </header>
    <div class="admin-management-content"><div class="am-shell">
      <form id="servicePricesForm" method="post" action="<?= adminManagementEscape($priceUpdateUrl) ?>"><input type="hidden" name="csrf_token" value="<?= adminManagementEscape($servicePricesCsrf) ?>"></form>
      <div class="am-layout">
        <nav class="am-nav" aria-label="Service pricing sections">
          <div class="am-nav-label">Services</div>
          <button type="button" class="active" data-panel="prices" aria-pressed="true"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M15.5 8.5c-.7-.7-1.8-1-3-1-1.7 0-3 .8-3 2s1.1 1.8 3 2.1 3 1 3 2.3-1.3 2.2-3.1 2.2c-1.2 0-2.4-.4-3.2-1.2M12.4 5.8v12.4"/></svg>Service prices</button>
          <button type="button" data-panel="addons" aria-pressed="false"><svg viewBox="0 0 24 24" aria-hidden="true"><rect x="4" y="4" width="16" height="16" rx="3"/><path d="M12 8v8M8 12h8"/></svg>Add-ons</button>
        </nav>
        <div class="am-panels">
            <section class="am-panel active" data-panel-content="prices">
              <?php if (!$servicePricesReady): ?>
                <div class="am-callout warning"><svg viewBox="0 0 24 24"><path d="M12 3 2.8 20h18.4L12 3Z"/><path d="M12 9v5M12 17.5h.01"/></svg><div>Service rates are unavailable. Check that boat, tour guide, and additional fee records are installed before editing prices.</div></div>
              <?php else: ?>
                <div class="am-prices-grid">
                  <?php foreach (['boat' => ['Boat rates', 'Base rates for vessel services.'], 'tourguide' => ['Tour guide rates', 'Base rates for guide services.']] as $serviceType => [$title, $description]): ?>
                    <article class="am-card"><header class="am-card-header"><div class="am-card-heading"><span class="am-card-icon" aria-hidden="true">₱</span><div><h3><?= adminManagementEscape($title) ?></h3><p><?= adminManagementEscape($description) ?></p></div></div></header><div class="am-card-body am-grid-2">
                      <?php foreach (['day_tour_price' => ['Day tour', $serviceType . '_day'], 'overnight_price' => ['Overnight', $serviceType . '_overnight']] as $priceKey => [$label, $fieldName]): ?>
                        <div class="am-field"><label for="price_<?= $fieldName ?>"><?= adminManagementEscape($label) ?></label><div class="am-price-input"><span aria-hidden="true">₱</span><input id="price_<?= $fieldName ?>" form="servicePricesForm" name="<?= $fieldName ?>" type="number" min="0" step="0.01" required value="<?= adminManagementEscape(number_format((float)$servicePrices[$serviceType][$priceKey], 2, '.', '')) ?>"></div></div>
                      <?php endforeach; ?>
                    </div></article>
                  <?php endforeach; ?>
                  <?php foreach ($additionalFeeGroups as $category => $fees): ?>
                    <article class="am-card"><header class="am-card-header"><div class="am-card-heading"><span class="am-card-icon" aria-hidden="true">₱</span><div><h3><?= adminManagementEscape($category) ?> fees</h3><p><?= adminManagementEscape($category === 'Environmental' ? 'Rates per eligible visitor.' : ($category === 'Entrance' ? 'Destination entrance rates per head.' : ($category === 'Docking' ? 'Landing rates charged per boat.' : 'Daily equipment rental rates.'))) ?></p></div></div></header><div class="am-card-body am-grid-2">
                      <?php foreach ($fees as $feeCode => $fee): ?>
                        <div class="am-field"><label for="price_<?= adminManagementEscape($feeCode) ?>"><?= adminManagementEscape($fee['label']) ?> <span>(<?= adminManagementEscape($fee['unit']) ?>)</span></label><div class="am-price-input"><span aria-hidden="true">₱</span><input id="price_<?= adminManagementEscape($feeCode) ?>" form="servicePricesForm" name="additional_fees[<?= adminManagementEscape($feeCode) ?>]" type="number" min="0" step="0.01" required value="<?= adminManagementEscape(number_format((float)$fee['amount'], 2, '.', '')) ?>"></div></div>
                      <?php endforeach; ?>
                    </div></article>
                  <?php endforeach; ?>
                </div>
              <?php endif; ?>
            </section>

            <section class="am-panel" data-panel-content="addons" hidden>
              <?php AddonCatalogPanel($pdo, 'admin', $adminId, 0, $assetPrefix . 'php/booking_addon_catalog_manage.php'); ?>
            </section>
        </div>
      </div>
    </div></div>
  </main>
</div>
<script src="<?= $assetPrefix ?>js/admin-service-pricing.js?v=<?= (int)@filemtime(__DIR__ . '/../js/admin-service-pricing.js') ?>" data-prices-ready="<?= $servicePricesReady ? '1' : '0' ?>"></script>
<script src="<?= $assetPrefix ?>js/booking-addon-catalog.js?v=2"></script>
</body>
</html>
