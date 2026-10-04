<?php
declare(strict_types=1);

chdir(__DIR__ . '/..');
require_once __DIR__ . '/session_security.php';
AppSessionStart();
require_once __DIR__ . '/db_connection.php';
require_once __DIR__ . '/operator_auth_helper.php';
require_once __DIR__ . '/activity_logger.php';

if (($portalRole ?? '') === 'operator') {
    $account = OperatorRequireLogin($pdo);
    $accountId = (int)$account['operator_id'];
    $accountName = (string)$account['fullname'];
    $portalLabel = 'Tour operator';
    $pageFile = 'opsystemsettings.php';
    $profilePage = 'opprofile.php';
} elseif (($portalRole ?? '') === 'hotel_admin') {
    require_once __DIR__ . '/../Ho_common.php';
    $account = HoRequireHotelAdmin($pdo);
    $accountId = (int)$account['hotel_admin_id'];
    $accountName = (string)($account['full_name'] ?: $account['username']);
    $portalLabel = 'Hotel administrator';
    $pageFile = 'Hosystemsettings.php';
    $profilePage = 'Hoprofile.php';
    $hoActive = 'settings';
    $hoPendingBadge = HoGetPendingCount($pdo, (int)$account['hotel_resort_id']);
} else {
    http_response_code(404);
    exit('Page not found.');
}
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
$portalBase = operatorPortalBaseUrl() . '/';
$settingsUrl = $portalBase . $pageFile;
$csrfToken = AppCsrfToken($portalRole, 'system_settings');
$noticeKey = $portalRole . '_settings_notice';
$settings = PortalSettingsDefaults($portalRole);
$storageReady = true;
try {
    if (!PortalSettingsTableExists($pdo)) PortalEnsureSettingsTable($pdo);
    $settings = PortalLoadSettings($pdo, $portalRole, $accountId);
} catch (Throwable $error) {
    $storageReady = false;
    error_log('Portal settings storage failed: ' . $error->getMessage());
}
$redirectNotice = static function (string $type, string $message) use ($noticeKey, $settingsUrl): never {
    $_SESSION[$noticeKey] = ['type' => $type, 'message' => $message];
    header('Location: ' . $settingsUrl);
    exit;
};
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!AppVerifyCsrf($portalRole, 'system_settings', $_POST['csrf_token'] ?? null)) {
        $redirectNotice('error', 'Your form session expired. Refresh the page and try again.');
    }
    if (!$storageReady) $redirectNotice('error', 'Settings storage is unavailable. Please contact the website administrator.');
    $action = (string)($_POST['action'] ?? 'save_settings');
    if ($action === 'export_settings') {
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="itour-' . $portalRole . '-settings.json"');
        echo json_encode(['portal' => $portalLabel, 'exported_at' => date(DATE_ATOM), 'settings' => $settings], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        exit;
    }
    try {
        if ($action === 'reset_settings') {
            $candidate = PortalSettingsDefaults($portalRole);
        } elseif ($action === 'save_settings') {
            $candidate = [
                'session_timeout_minutes' => $_POST['session_timeout_minutes'] ?? null,
                'landing_page' => $_POST['landing_page'] ?? null,
            ];
        } else {
            throw new InvalidArgumentException('Choose an available settings action.');
        }
        // Both role and account ID come from the authenticated server-side account.
        PortalSaveSettings($pdo, $portalRole, $accountId, $candidate);
        logActivity($pdo, $portalRole === 'operator' ? 'Tour Operator' : 'Hotel Owner', $accountId, $accountName,
            $action === 'reset_settings' ? 'Reset Settings' : 'Update Settings',
            'Updated personal session timeout and portal start page.', 'System Settings');
        $redirectNotice('success', $action === 'reset_settings' ? 'Recommended settings restored.' : 'Settings saved. Your session timeout is now active.');
    } catch (InvalidArgumentException $error) {
        $redirectNotice('error', $error->getMessage());
    } catch (Throwable $error) {
        error_log('Portal settings save failed: ' . $error->getMessage());
        $redirectNotice('error', 'Settings could not be saved. Please try again.');
    }
}
$notice = $_SESSION[$noticeKey] ?? null;
unset($_SESSION[$noticeKey]);
$escape = static fn(mixed $value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
$landingPages = PortalLandingPageOptions($portalRole);
$icons = [
    'settings' => '<circle cx="12" cy="12" r="3"/><path d="M9 3h6l1 3 3 1 2 5-2 5-3 1-1 3H9l-1-3-3-1-2-5 2-5 3-1z"/>',
    'shield' => '<path d="M12 3 4 6v6c0 5 3.4 8 8 9 4.6-1 8-4 8-9V6l-8-3Z"/><path d="m8.5 12 2.3 2.3 4.7-4.8"/>',
    'clock' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
    'general' => '<rect x="4" y="5" width="16" height="14" rx="2"/><path d="M8 9h8M8 13h5"/>',
    'export' => '<path d="M12 3v12m0 0 4-4m-4 4-4-4M5 20h14"/>',
    'save' => '<path d="M5 4h12l2 2v14H5V4Z"/><path d="M8 4v6h8V4M8 20v-6h8v6"/>',
];
$portalSettingsIcon = static fn(string $name): string => '<svg viewBox="0 0 24 24" aria-hidden="true">' . $icons[$name] . '</svg>';
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
  <base href="<?= $escape($portalBase) ?>">
  <title>System Settings | iTour Mercedes <?= $escape($portalLabel) ?></title>
  <link rel="icon" href="img/newlogo.png">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <?php if ($portalRole === 'hotel_admin'): ?><link rel="stylesheet" href="styles/Ho_panel.css?v=notifications-4"><?php endif; ?>
  <link rel="stylesheet" href="styles/admin_management.css?v=<?= (int)filemtime(__DIR__ . '/../styles/admin_management.css') ?>">
  <link rel="stylesheet" href="styles/portal_settings.css?v=2">
</head>
<body class="portal-settings-page<?= $portalRole === 'hotel_admin' ? ' ho-body' : '' ?>">
<div class="portal-settings-layout<?= $portalRole === 'hotel_admin' ? ' ho-layout' : '' ?>">
  <?php include $portalRole === 'operator' ? __DIR__ . '/../operator/operator_sidebar.php' : __DIR__ . '/../Ho_sidebar.php'; ?>
  <main class="admin-management-main portal-settings-main">
    <header class="portal-settings-header">
      <div class="portal-settings-title"><span class="am-card-icon"><?= $portalSettingsIcon('settings') ?></span><div><h1>System Settings</h1><p>Manage your session security and portal preferences</p></div></div>
      <div class="am-header-actions">
        <form method="post" action="<?= $escape($settingsUrl) ?>"><input type="hidden" name="csrf_token" value="<?= $escape($csrfToken) ?>"><input type="hidden" name="action" value="export_settings"><button class="am-button" type="submit" <?= !$storageReady ? 'disabled' : '' ?>><?= $portalSettingsIcon('export') ?>Export</button></form>
        <button class="am-save-button" id="portalSettingsSave" type="submit" form="portalSettingsForm" <?= !$storageReady ? 'disabled' : '' ?>><?= $portalSettingsIcon('save') ?><span>Save changes</span></button>
        <a class="portal-settings-avatar" href="<?= $escape($profilePage) ?>" aria-label="Open profile" title="<?= $escape($accountName) ?>"><?= $escape(mb_strtoupper(mb_substr($accountName, 0, 1))) ?></a>
      </div>
    </header>
    <div class="admin-management-content"><div class="am-shell">
      <?php if (!$storageReady): ?><div class="portal-settings-notice error" role="alert">Settings storage is unavailable. Ask the website administrator to apply the portal settings migration.</div><?php endif; ?>
      <?php if ($notice): ?><div class="portal-settings-notice <?= $notice['type'] === 'success' ? 'success' : 'error' ?>" role="status"><?= $escape($notice['message']) ?></div><?php endif; ?>
      <section class="am-summary-grid" aria-label="Your portal settings">
        <?php foreach ([
            ['shield', 'Protected', PortalSessionTimeoutOptions()[$settings['session_timeout_minutes']] ?? $settings['session_timeout_minutes'] . ' minutes', 'Sign out after inactivity'],
            ['general', 'Configured', $landingPages[$settings['landing_page']], 'Your page after signing in'],
            ['settings', 'Personal', $portalLabel, 'Preferences saved to your account'],
            ['clock', 'Local time', 'Asia/Manila', 'Philippine time · PHP currency'],
        ] as [$symbol, $status, $title, $description]): ?>
          <article class="am-summary-card"><div class="am-summary-top"><span class="am-summary-icon"><?= $portalSettingsIcon($symbol) ?></span><span class="am-status"><?= $escape($status) ?></span></div><strong><?= $escape($title) ?></strong><p><?= $escape($description) ?></p></article>
        <?php endforeach; ?>
      </section>
      <form id="portalSettingsForm" method="post" action="<?= $escape($settingsUrl) ?>">
        <input type="hidden" name="csrf_token" value="<?= $escape($csrfToken) ?>"><input type="hidden" name="action" value="save_settings">
        <div class="am-layout">
          <nav class="am-nav" aria-label="Settings sections">
            <div class="am-nav-label">Configuration</div>
            <button type="button" class="active" data-portal-panel="general"><?= $portalSettingsIcon('general') ?>General</button>
            <button type="button" data-portal-panel="security"><?= $portalSettingsIcon('shield') ?>Security &amp; data</button>
            <button type="button" data-portal-panel="information"><?= $portalSettingsIcon('settings') ?>System information</button>
            <div class="am-nav-divider"></div><div class="am-nav-note"><strong>Saved to your account</strong>Your preferences follow you across browsers and devices.</div>
          </nav>
          <div class="am-panels">
            <section class="am-panel active" data-portal-panel-content="general">
              <article class="am-card"><header class="am-card-header"><div class="am-card-heading"><span class="am-card-icon"><?= $portalSettingsIcon('general') ?></span><div><h3>Portal preferences</h3><p>Choose where you begin after signing in</p></div></div></header><div class="am-card-body">
                <div class="am-field"><label for="landing_page">Start page after login</label><select id="landing_page" name="landing_page" required><?php foreach ($landingPages as $page => $label): ?><option value="<?= $escape($page) ?>" <?= $settings['landing_page'] === $page ? 'selected' : '' ?>><?= $escape($label) ?></option><?php endforeach; ?></select><div class="am-field-help">A session-expiry return link or payment setup link takes priority.</div></div>
              </div></article>
            </section>
            <section class="am-panel" data-portal-panel-content="security">
              <article class="am-card"><header class="am-card-header"><div class="am-card-heading"><span class="am-card-icon"><?= $portalSettingsIcon('shield') ?></span><div><h3>Security policy</h3><p>Control when your inactive session ends</p></div></div></header><div class="am-card-body">
                <div class="am-field"><label for="session_timeout_minutes">Session expiration</label><select id="session_timeout_minutes" name="session_timeout_minutes" required><?php foreach (PortalSessionTimeoutOptions() as $minutes => $label): ?><option value="<?= $minutes ?>" <?= (int)$settings['session_timeout_minutes'] === $minutes ? 'selected' : '' ?>><?= $escape($label) ?></option><?php endforeach; ?></select><div class="am-field-help">Recommended: 30–60 minutes on shared computers. Changes apply after saving.</div></div>
              </div></article>
              <article class="am-card"><header class="am-card-header"><div class="am-card-heading"><span class="am-card-icon"><?= $portalSettingsIcon('general') ?></span><div><h3>Configuration tools</h3><p>Export your saved preferences or restore recommended values</p></div></div></header><div class="am-card-body portal-settings-tools">
                <button class="am-button" type="submit" name="action" value="export_settings" <?= !$storageReady ? 'disabled' : '' ?>><?= $portalSettingsIcon('export') ?>Export JSON</button>
                <button class="am-button danger" type="button" id="portalSettingsReset" <?= !$storageReady ? 'disabled' : '' ?>>Restore defaults</button>
                <a class="am-button" href="<?= $escape($profilePage) ?>">Manage password &amp; profile</a>
              </div></article>
            </section>
            <section class="am-panel" data-portal-panel-content="information">
              <article class="am-card"><header class="am-card-header"><div class="am-card-heading"><span class="am-card-icon"><?= $portalSettingsIcon('settings') ?></span><div><h3>Portal information</h3><p>Your account and current configuration</p></div></div></header><div class="am-card-body am-system-info">
                <?php foreach (['Application' => 'iTour Mercedes', 'Portal' => $portalLabel, 'Signed in as' => $accountName, 'Timezone' => 'Asia/Manila', 'Currency' => 'PHP — Philippine Peso', 'Settings scope' => 'Your account only'] as $label => $value): ?><div class="am-info-row"><span><?= $escape($label) ?></span><strong><?= $escape($value) ?></strong></div><?php endforeach; ?>
              </div></article>
            </section>
          </div>
        </div>
      </form>
    </div></div>
    <div class="am-modal" id="portalSettingsResetModal" role="dialog" aria-modal="true" aria-labelledby="portalResetTitle" aria-hidden="true"><div class="am-modal-dialog"><header class="am-modal-header"><h3 id="portalResetTitle">Restore recommended defaults?</h3><button class="am-modal-close" type="button" data-close-portal-reset aria-label="Close">&times;</button></header><div class="am-modal-body">Your session timeout will return to 1 hour and your start page to Dashboard.</div><footer class="am-modal-footer"><button class="am-button" type="button" data-close-portal-reset>Cancel</button><form method="post" action="<?= $escape($settingsUrl) ?>"><input type="hidden" name="csrf_token" value="<?= $escape($csrfToken) ?>"><input type="hidden" name="action" value="reset_settings"><button class="am-button danger" type="submit">Restore defaults</button></form></footer></div></div>
  </main>
</div>
  <script src="js/portal_settings.js?v=1"></script>
</body>
</html>
