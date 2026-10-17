<?php
declare(strict_types=1);

// Render real GET routes in isolated CLI sessions. No credentials or saves.
$projectRoot = dirname(__DIR__);
require_once $projectRoot . '/php/db_connection.php';
$accounts = [
    'operator' => $pdo->query("SELECT operator_id FROM operators WHERE LOWER(status)='active' LIMIT 1")->fetchColumn(),
    'hotel_admin' => $pdo->query("SELECT hotel_admin_id FROM hotel_admin_accounts WHERE LOWER(status)='active' LIMIT 1")->fetchColumn(),
    'admin' => $pdo->query('SELECT admin_id FROM admin_users LIMIT 1')->fetchColumn(),
];
foreach ([
    ['operator', 'opsystemsettings.php'],
    ['operator', 'operator/opsystemsettings.php'],
    ['hotel_admin', 'Hosystemsettings.php'],
    ['admin', 'adsystemsettings.php'],
    ['admin', 'adservicepricing.php'],
    ['admin', 'admin/adservicepricing.php'],
] as [$role, $page]) {
    $accountId = $accounts[$role];
    if (!$accountId) throw new RuntimeException('No fixture account available for ' . $role);
    $directory = sys_get_temp_dir() . '/itour-settings-render-' . bin2hex(random_bytes(8));
    mkdir($directory, 0700);
    try {
        $server = ['REQUEST_METHOD' => 'GET', 'SCRIPT_NAME' => '/' . $page, 'PHP_SELF' => '/' . $page, 'DOCUMENT_ROOT' => $projectRoot];
        $code = '<?php $_SERVER=' . var_export($server, true) . ';';
        $code .= 'ini_set("session.save_path",' . var_export($directory, true) . ');';
        $code .= 'require ' . var_export($projectRoot . '/php/session_security.php', true) . '; AppSessionStart();';
        $code .= '$_SESSION[' . var_export($role . '_id', true) . ']=' . (int)$accountId . ';';
        $code .= '$_SESSION[' . var_export($role . '_logged_in', true) . ']=true; AppMarkRoleAuthenticated(' . var_export($role, true) . ');';
        $code .= 'require ' . var_export($projectRoot . '/' . $page, true) . ';';
        $process = proc_open([PHP_BINARY, '-d', 'display_errors=stderr'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $projectRoot);
        if (!is_resource($process)) throw new RuntimeException('Could not render settings page.');
        fwrite($pipes[0], $code);
        fclose($pipes[0]);
        $html = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($process);
        if ($status !== 0 || trim($errors) !== '') throw new RuntimeException($page . ' render failed: ' . $errors);
        $doc = new DOMDocument();
        libxml_use_internal_errors(true);
        $doc->loadHTML($html);
        libxml_clear_errors();
        $xpath = new DOMXPath($doc);
        if ($role === 'admin' && basename($page) === 'adservicepricing.php') {
            if ($xpath->query('//nav[contains(@class,"am-nav")]//button[@data-panel]')->length !== 2
                || $xpath->query('//form[@id="servicePricesForm"]/input[@name="csrf_token"]')->length !== 1
                || $xpath->query('//*[@data-addon-manager]')->length !== 1
                || $xpath->query('//form[@id="settingsForm"]')->length !== 0) {
                throw new RuntimeException('Separate pricing page must provide independent pricing/add-on editors: ' . $page);
            }
            if ($xpath->query('//*[@data-addon-manager]//form')->length !== 0
                || $xpath->query('//*[@data-addon-name and @form="addonCatalogIndependent"]')->length !== 1) {
                throw new RuntimeException('Add-on fields must stay independent of the price form: ' . $page);
            }
            echo 'PASS: ', $page, ' renders separate pricing and add-on tabs with protected forms.', PHP_EOL;
            continue;
        }
        if ($role === 'hotel_admin') {
            $layout = '//div[contains(concat(" ",normalize-space(@class)," ")," ho-layout ")]';
            if ($xpath->query($layout . '/aside[contains(@class,"ho-sidebar")]')->length !== 1
                || $xpath->query($layout . '/main[contains(@class,"portal-settings-main")]')->length !== 1) {
                throw new RuntimeException('Hotel sidebar and settings must share the two-column layout wrapper.');
            }
            if ($xpath->query('//body[contains(concat(" ",normalize-space(@class)," ")," ho-body ")]')->length !== 1) {
                throw new RuntimeException('Hotel page is missing its shared body styles.');
            }
        }
        if ($xpath->query('//select[@name="session_timeout_minutes"]/option')->length < 6) throw new RuntimeException('Timeout choices missing: ' . $page);
        if ($xpath->query('//input[@name="session_timeout_minutes"]')->length !== 0) throw new RuntimeException('Timeout still requires typed input: ' . $page);
        if ($role !== 'admin' && $xpath->query('//select[@name="landing_page"]/option')->length !== 5) throw new RuntimeException('Start page choices missing: ' . $page);
        if ($xpath->query('//input[@name="csrf_token"]')->length < 3) throw new RuntimeException('Settings actions missing CSRF fields: ' . $page);
        if (!str_contains($html, 'System Settings') || !str_contains($doc->textContent, 'Security & data')) throw new RuntimeException('Settings sections missing: ' . $page);
        if ($role === 'admin') {
            if ($xpath->query('//*[@data-addon-manager or @data-panel="prices" or @data-panel="addons"]')->length !== 0
                || $xpath->query('//form[@id="servicePricesForm"]')->length !== 0) {
                throw new RuntimeException('Pricing and add-ons must be separate from admin settings.');
            }
        } else {
            if ($xpath->query('//*[@data-addon-manager]')->length !== 1 || $xpath->query('//*[@data-addon-save]')->length !== 1) throw new RuntimeException('Add-on editor missing: ' . $page);
            if ($xpath->query('//*[@data-addon-manager]//form')->length !== 0) throw new RuntimeException('Add-on editor must not nest forms: ' . $page);
            if ($xpath->query('//*[@data-addon-name and @form="addonCatalogIndependent"]')->length !== 1) throw new RuntimeException('Add-on fields must be independent of portal preferences: ' . $page);
        }
        echo 'PASS: ', $page, ' renders settings sections, timeout dropdown and protected actions.', PHP_EOL;
    } finally {
        foreach (glob($directory . '/sess_*') ?: [] as $sessionFile) unlink($sessionFile);
        rmdir($directory);
    }
}
