<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
// Render real templates with fixture data only: no application DB bootstrap,
// authentication changes, saves, migrations, HTTP requests or browser interaction.
require_once __DIR__ . '/../php/session_security.php';
require_once __DIR__ . '/../php/admin_management_helper.php';
require_once __DIR__ . '/../php/portal_settings_helper.php';
require_once __DIR__ . '/../php/booking_addon_catalog_panel.php';
function pricingRenderCheck(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}
function pricingRenderTemplate(string $file, array $variables): DOMXPath {
    $source=file_get_contents(__DIR__ . '/../' . $file);
    $start=strpos($source,'<!DOCTYPE html>');
    pricingRenderCheck($start!==false,'Missing HTML template.');
    $view=substr($source,$start);
    $view=str_replace("<?php include __DIR__ . '/admin_sidebar.php'; ?>",'<aside class="fixture-sidebar"></aside>',$view);
    extract($variables,EXTR_SKIP);
    ob_start();
    try { eval('?>'.$view); $html=(string)ob_get_contents(); }
    finally { ob_end_clean(); }
    $doc=new DOMDocument(); libxml_use_internal_errors(true); $doc->loadHTML($html); libxml_clear_errors();
    return new DOMXPath($doc);
}
$pdo=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$pdo->exec("CREATE TABLE booking_addon_catalog (addon_id INT, owner_role TEXT, owner_id INT, hotel_resort_id INT, name TEXT, price NUMERIC, is_active INT);
INSERT INTO booking_addon_catalog VALUES (1,'admin',7,NULL,'Fixture picnic',150,1);");
foreach (['','../'] as $prefix) {
    $variables=['pdo'=>$pdo,'adminId'=>7,'assetPrefix'=>$prefix,'servicePricesReady'=>true,
        'priceUpdateUrl'=>$prefix.'php/update_service_prices.php','servicePricesCsrf'=>AppCsrfToken('admin','catalog_content'),
        'servicePrices'=>['boat'=>['day_tour_price'=>2500,'overnight_price'=>3500],'tourguide'=>['day_tour_price'=>700,'overnight_price'=>1400]],
        'additionalFeeGroups'=>['Environmental'=>['environmental_local'=>['label'=>'Local','unit'=>'per person','amount'=>50]]]];
    $xpath=pricingRenderTemplate('admin/adservicepricing.php',$variables);
    pricingRenderCheck($xpath->query('//nav[contains(@class,"am-nav")]//button[@data-panel]')->length===2,'Pricing tabs missing.');
    pricingRenderCheck($xpath->query('//form')->length===1,'The pricing/add-on page must contain exactly one independent price form.');
    pricingRenderCheck($xpath->query('//form[@id="servicePricesForm" and @action="'.$prefix.'php/update_service_prices.php"]/input[@name="csrf_token"]')->length===1,'Price action/CSRF association changed.');
    pricingRenderCheck($xpath->query('//input[@form="servicePricesForm"]')->length===5,'Base rates and visitor fees must retain the independent form owner.');
    pricingRenderCheck($xpath->query('//*[@data-addon-manager and @data-endpoint="'.$prefix.'php/booking_addon_catalog_manage.php"]')->length===1,'Add-on endpoint changed.');
    pricingRenderCheck($xpath->query('//*[@data-addon-name and @form="addonCatalogIndependent"]')->length===1,'Add-on fields joined the price form.');
    pricingRenderCheck($xpath->query('//form[@id="settingsForm"]')->length===0,'System preferences leaked into pricing.');
    $variables['servicePricesReady']=false;
    $xpath=pricingRenderTemplate('admin/adservicepricing.php',$variables);
    pricingRenderCheck($xpath->query('//button[@id="servicePricingSaveButton" and @disabled]')->length===1,'Missing-rate state must prevent saving.');
    pricingRenderCheck($xpath->query('//input[@form="servicePricesForm"]')->length===0,'Missing rates must not render editable zero-price substitutes.');
}
$xpath=pricingRenderTemplate('admin/adsystemsettings.php',[
    'settings'=>adminSettingsDefaults(),'assetPrefix'=>'','pageFile'=>'adsystemsettings.php','csrfToken'=>'fixture-settings-token',
    'flash'=>null,'tableCount'=>0,'dbSizeMb'=>0,'dbVersion'=>'fixture','uploadWritable'=>true,'lastUpdated'=>null,'settingsEditor'=>'',
    'allowedTimezones'=>['Asia/Manila'],'allowedCurrencies'=>['PHP'],'allowedDateFormats'=>['M d, Y']
]);
pricingRenderCheck($xpath->query('//*[@data-panel="prices" or @data-panel="addons" or @data-addon-manager]')->length===0,'Admin settings still contains pricing or add-on tabs.');
pricingRenderCheck($xpath->query('//form[@id="servicePricesForm"]')->length===0,'Price form must leave settings.');
pricingRenderCheck($xpath->query('//form[@id="settingsForm"]')->length===1,'The settings save form was lost.');
pricingRenderCheck($xpath->query('//select[@name="session_timeout_minutes"]')->length===1,'Session settings were lost.');
echo "PASS: real pricing/settings templates on isolated fixture data, both asset prefixes, independent forms and CSRF, missing-rate state, and preserved settings sections.\n";
