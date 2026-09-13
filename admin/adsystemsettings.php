<?php
chdir(__DIR__ . '/..');
require_once __DIR__ . '/../php/session_security.php';
AppSessionStart();
require_once __DIR__ . '/../php/db_connection.php';
require_once __DIR__ . '/../php/activity_logger.php';
require_once __DIR__ . '/../php/admin_management_helper.php';
require_once __DIR__ . '/../php/input_validation.php';
require_once __DIR__ . '/../php/project_path_helper.php';

adminManagementRequireLogin();
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$adminId = (int)($_SESSION['admin_id'] ?? 0);
$adminName = (string)($_SESSION['admin_name'] ?? $_SESSION['username'] ?? 'Administrator');
$pageFile = basename((string)($_SERVER['PHP_SELF'] ?? ''));
$assetPrefix = strtolower(basename(dirname((string)($_SERVER['SCRIPT_NAME'] ?? '')))) === 'admin' ? '../' : '';
$allowedTimezones = ['Asia/Manila', 'Asia/Singapore', 'Asia/Tokyo', 'UTC'];
$allowedCurrencies = ['PHP', 'USD'];
$allowedDateFormats = ['M d, Y', 'd M Y', 'm/d/Y', 'd/m/Y'];
$allowedStatuses = ['pending', 'confirmed'];

[$settings, $settingsMeta] = loadAdminSystemSettings($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyAdminManagementCsrf()) {
        adminManagementRedirect($pageFile, 'error', 'Your form session expired. Please try again.');
    }
    $action = (string)($_POST['action'] ?? 'save_settings');

    if ($action === 'export_settings') {
        $payload = [
            'product' => 'iTour Mercedes',
            'exported_at' => date(DATE_ATOM),
            'settings' => $settings,
        ];
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="itour-system-settings-' . date('Y-m-d') . '.json"');
        echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'reset_settings') {
        try {
            $settings = adminSettingsDefaults();
            saveAdminSystemSettings($pdo, $settings, $adminId);
            logActivity($pdo, 'Admin', $adminId, $adminName, 'Reset Settings', 'Restored all system preferences to their recommended defaults.', 'System Settings');
            adminManagementRedirect($pageFile, 'success', 'System settings were restored to the recommended defaults.');
        } catch (Throwable $e) {
            error_log('Settings reset failed: ' . $e->getMessage());
            adminManagementRedirect($pageFile, 'error', 'Settings could not be reset. Please try again.');
        }
    }

    try {
        $bookingNoticeHours = ItourValidationInt($_POST['booking_notice_hours'] ?? null, 'Booking notice', 0, 720);
        $cancellationWindowHours = ItourValidationInt($_POST['cancellation_window_hours'] ?? null, 'Cancellation window', 0, 720);
        $capacityWarningPercent = ItourValidationInt($_POST['capacity_warning_percent'] ?? null, 'Capacity warning', 1, 100);
        $auditRetentionDays = ItourValidationInt($_POST['audit_retention_days'] ?? null, 'Audit retention', 30, 3650);
        $sessionTimeoutMinutes = ItourValidationInt($_POST['session_timeout_minutes'] ?? null, 'Session timeout', 15, 480);
    } catch (InvalidArgumentException $error) {
        adminManagementRedirect($pageFile, 'error', $error->getMessage());
    }

    $candidate = [
        'site_name' => mb_substr(trim((string)($_POST['site_name'] ?? '')), 0, 100),
        'office_email' => mb_substr(trim((string)($_POST['office_email'] ?? '')), 0, 150),
        'office_phone' => mb_substr(trim((string)($_POST['office_phone'] ?? '')), 0, 40),
        'office_address' => mb_substr(trim((string)($_POST['office_address'] ?? '')), 0, 220),
        'timezone' => in_array($_POST['timezone'] ?? '', $allowedTimezones, true) ? $_POST['timezone'] : 'Asia/Manila',
        'currency' => in_array($_POST['currency'] ?? '', $allowedCurrencies, true) ? $_POST['currency'] : 'PHP',
        'date_format' => in_array($_POST['date_format'] ?? '', $allowedDateFormats, true) ? $_POST['date_format'] : 'M d, Y',
        'booking_notice_hours' => $bookingNoticeHours,
        'cancellation_window_hours' => $cancellationWindowHours,
        'capacity_warning_percent' => $capacityWarningPercent,
        'default_booking_status' => in_array($_POST['default_booking_status'] ?? '', $allowedStatuses, true) ? $_POST['default_booking_status'] : 'pending',
        'notify_new_booking' => isset($_POST['notify_new_booking']),
        'notify_payment' => isset($_POST['notify_payment']),
        'notify_inquiry' => isset($_POST['notify_inquiry']),
        'notify_review' => isset($_POST['notify_review']),
        'email_digest' => isset($_POST['email_digest']),
        'digest_time' => preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', (string)($_POST['digest_time'] ?? '')) ? $_POST['digest_time'] : '08:00',
        'audit_retention_days' => $auditRetentionDays,
        'session_timeout_minutes' => $sessionTimeoutMinutes,
        'support_message' => mb_substr(trim((string)($_POST['support_message'] ?? '')), 0, 300),
    ];

    if ($candidate['site_name'] === '') {
        adminManagementRedirect($pageFile, 'error', 'The portal name is required.');
    }
    if ($candidate['office_email'] !== '' && !filter_var($candidate['office_email'], FILTER_VALIDATE_EMAIL)) {
        adminManagementRedirect($pageFile, 'error', 'Enter a valid tourism office email address.');
    }

    try {
        saveAdminSystemSettings($pdo, $candidate, $adminId);
        logActivity($pdo, 'Admin', $adminId, $adminName, 'Updated Settings', 'Updated portal, booking, notification, regional, or security preferences.', 'System Settings');
        adminManagementRedirect($pageFile, 'success', 'System settings saved successfully.');
    } catch (Throwable $e) {
        error_log('Settings save failed: ' . $e->getMessage());
        adminManagementRedirect($pageFile, 'error', 'Settings could not be saved. Please try again.');
    }
}

$flash = adminManagementFlash();
$csrfToken = adminManagementCsrfToken();
$dbVersion = 'Unavailable';
$dbSizeMb = 0.0;
$tableCount = 0;
try {
    $dbVersion = (string)$pdo->query('SELECT VERSION()')->fetchColumn();
    $tableCount = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE'")->fetchColumn();
    $dbSizeMb = (float)$pdo->query('SELECT COALESCE(SUM(data_length + index_length),0) / 1024 / 1024 FROM information_schema.tables WHERE table_schema = DATABASE()')->fetchColumn();
} catch (Throwable $e) {}
$uploadPath = ItourProjectPath('uploads');
$uploadWritable = is_dir($uploadPath) && is_writable($uploadPath);
$lastUpdated = $settingsMeta['updated_at'] ?? null;
$settingsEditor = '';
if (!empty($settingsMeta['updated_by'])) {
    try {
        $stmt = $pdo->prepare('SELECT COALESCE(NULLIF(full_name, ""), username) FROM admin_users WHERE admin_id = ?');
        $stmt->execute([(int)$settingsMeta['updated_by']]);
        $settingsEditor = (string)$stmt->fetchColumn();
    } catch (Throwable $e) {}
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>System Settings | iTour Mercedes Admin</title>
  <link rel="icon" type="image/png" href="<?= $assetPrefix ?>img/newlogo.png?v=2">
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="<?= $assetPrefix ?>styles/admin_panel_theme.css">
  <link rel="stylesheet" href="<?= $assetPrefix ?>styles/admin_management.css?v=5">
</head>
<body>
<div class="admin-container">
  <?php include __DIR__ . '/admin_sidebar.php'; ?>
  <main class="main-content admin-management-main">
    <header class="admin-header admin-page-header">
      <div class="admin-header-left admin-page-title">
        <span class="admin-page-title-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.9l.1.1-2.8 2.8-.1-.1a1.7 1.7 0 0 0-1.9-.3 1.7 1.7 0 0 0-1 1.6v.2h-4V21a1.7 1.7 0 0 0-1-1.6 1.7 1.7 0 0 0-1.9.3l-.1.1L4.2 17l.1-.1a1.7 1.7 0 0 0 .3-1.9A1.7 1.7 0 0 0 3 14H2.8v-4H3a1.7 1.7 0 0 0 1.6-1 1.7 1.7 0 0 0-.3-1.9L4.2 7 7 4.2l.1.1A1.7 1.7 0 0 0 9 4.6 1.7 1.7 0 0 0 10 3v-.2h4V3a1.7 1.7 0 0 0 1 1.6 1.7 1.7 0 0 0 1.9-.3l.1-.1L19.8 7l-.1.1a1.7 1.7 0 0 0-.3 1.9 1.7 1.7 0 0 0 1.6 1h.2v4H21a1.7 1.7 0 0 0-1.6 1Z"/></svg></span>
        <div class="admin-page-title-copy"><h2>System Settings</h2><p class="admin-header-subtitle">Configure portal preferences, booking rules, and operational controls</p></div>
      </div>
      <div class="admin-header-right am-header-actions">
        <form method="post"><input type="hidden" name="csrf_token" value="<?= adminManagementEscape($csrfToken) ?>"><input type="hidden" name="action" value="export_settings"><button class="am-button" type="submit"><svg viewBox="0 0 24 24"><path d="M12 3v12m0 0 4-4m-4 4-4-4M5 20h14"/></svg>Export</button></form>
        <button class="am-save-button" type="submit" form="settingsForm"><svg viewBox="0 0 24 24"><path d="M5 4h12l2 2v14H5V4Z"/><path d="M8 4v6h8V4M8 20v-6h8v6"/></svg>Save changes</button>
      </div>
    </header>

    <div class="admin-management-content"><div class="am-shell">
      <?php if ($flash): ?><div class="am-flash <?= ($flash['status'] ?? '') === 'error' ? 'error' : '' ?>"><span><?= adminManagementEscape($flash['message'] ?? '') ?></span><button type="button" aria-label="Dismiss" onclick="this.parentElement.remove()">&times;</button></div><?php endif; ?>

      <section class="am-summary-grid" aria-label="System health">
        <article class="am-summary-card"><div class="am-summary-top"><span class="am-summary-icon"><svg viewBox="0 0 24 24"><path d="M4 6c0-2 3.6-3.5 8-3.5S20 4 20 6s-3.6 3.5-8 3.5S4 8 4 6Z"/><path d="M4 6v6c0 2 3.6 3.5 8 3.5s8-1.5 8-3.5V6M4 12v6c0 2 3.6 3.5 8 3.5s8-1.5 8-3.5v-6"/></svg></span><span class="am-status">Connected</span></div><strong>Database healthy</strong><p><?= number_format($tableCount) ?> tables · <?= number_format($dbSizeMb, 1) ?> MB</p></article>
        <article class="am-summary-card"><div class="am-summary-top"><span class="am-summary-icon"><svg viewBox="0 0 24 24"><path d="M4 4h16v16H4zM8 2v4M16 2v4M2 9h20"/></svg></span><span class="am-status">Configured</span></div><strong><?= adminManagementEscape($settings['timezone']) ?></strong><p>Portal timezone · <?= adminManagementEscape($settings['currency']) ?> currency</p></article>
        <article class="am-summary-card"><div class="am-summary-top"><span class="am-summary-icon"><svg viewBox="0 0 24 24"><path d="M12 3 4 6v6c0 5 3.4 8 8 9 4.6-1 8-4 8-9V6l-8-3Z"/><path d="m8.5 12 2.3 2.3 4.7-4.8"/></svg></span><span class="am-status">Protected</span></div><strong><?= (int)$settings['session_timeout_minutes'] ?> minute session</strong><p><?= (int)$settings['audit_retention_days'] ?> days audit retention</p></article>
        <article class="am-summary-card"><div class="am-summary-top"><span class="am-summary-icon"><svg viewBox="0 0 24 24"><path d="M4 19V5m0 14h16M7 15l4-4 3 2 5-6"/></svg></span><span class="am-status">Ready</span></div><strong>Storage <?= $uploadWritable ? 'writable' : 'needs attention' ?></strong><p>PHP <?= adminManagementEscape(PHP_VERSION) ?> · MySQL <?= adminManagementEscape(preg_replace('/-.*/', '', $dbVersion)) ?></p></article>
      </section>

      <form id="settingsForm" method="post" action="<?= adminManagementEscape($pageFile) ?>">
        <input type="hidden" name="csrf_token" value="<?= adminManagementEscape($csrfToken) ?>"><input type="hidden" name="action" value="save_settings">
        <div class="am-layout">
          <nav class="am-nav" aria-label="Settings sections">
            <div class="am-nav-label">Configuration</div>
            <button type="button" class="active" data-panel="general"><svg viewBox="0 0 24 24"><path d="M4 5h16v14H4zM8 9h8M8 13h5"/></svg>General</button>
            <button type="button" data-panel="bookings"><svg viewBox="0 0 24 24"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 10h18"/></svg>Booking rules</button>
            <button type="button" data-panel="notifications"><svg viewBox="0 0 24 24"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9M10 21h4"/></svg>Notifications</button>
            <button type="button" data-panel="security"><svg viewBox="0 0 24 24"><path d="M12 3 4 6v6c0 5 3.4 8 8 9 4.6-1 8-4 8-9V6l-8-3Z"/><path d="m8.5 12 2.3 2.3 4.7-4.8"/></svg>Security & data</button>
            <button type="button" data-panel="system"><svg viewBox="0 0 24 24"><path d="M5 4h14v16H5zM8 8h8M8 12h8M8 16h5"/></svg>System information</button>
            <div class="am-nav-divider"></div><div class="am-nav-note"><strong>Saved centrally</strong>Settings remain available to every administrator using this portal.</div>
          </nav>

          <div class="am-panels">
            <section class="am-panel active" data-panel-content="general">
              <article class="am-card"><header class="am-card-header"><div class="am-card-heading"><span class="am-card-icon"><svg viewBox="0 0 24 24"><path d="M4 5h16v14H4zM8 9h8M8 13h5"/></svg></span><div><h3>Portal identity</h3><p>Official name and contact information used by the tourism office</p></div></div></header><div class="am-card-body am-grid-2">
                <div class="am-field"><label for="site_name">Portal name</label><input id="site_name" name="site_name" maxlength="100" required value="<?= adminManagementEscape($settings['site_name']) ?>"></div>
                <div class="am-field"><label for="office_email">Official email <span>(optional)</span></label><input id="office_email" name="office_email" type="email" maxlength="150" value="<?= adminManagementEscape($settings['office_email']) ?>" placeholder="tourism@mercedes.gov.ph"></div>
                <div class="am-field"><label for="office_phone">Contact number <span>(optional)</span></label><input id="office_phone" name="office_phone" maxlength="40" value="<?= adminManagementEscape($settings['office_phone']) ?>" placeholder="+63 9XX XXX XXXX"></div>
                <div class="am-field"><label for="office_address">Office address</label><input id="office_address" name="office_address" maxlength="220" value="<?= adminManagementEscape($settings['office_address']) ?>"></div>
                <div class="am-field full"><label for="support_message">Visitor support message</label><textarea id="support_message" name="support_message" maxlength="300"><?= adminManagementEscape($settings['support_message']) ?></textarea><div class="am-field-help">A concise contact instruction that can be reused on confirmations and support views.</div></div>
              </div></article>
              <article class="am-card"><header class="am-card-header"><div class="am-card-heading"><span class="am-card-icon"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg></span><div><h3>Regional formatting</h3><p>Default timezone, currency, and date presentation</p></div></div></header><div class="am-card-body am-grid-3">
                <div class="am-field"><label for="timezone">Timezone</label><select id="timezone" name="timezone"><?php foreach ($allowedTimezones as $item): ?><option value="<?= adminManagementEscape($item) ?>" <?= $settings['timezone'] === $item ? 'selected' : '' ?>><?= adminManagementEscape($item) ?></option><?php endforeach; ?></select></div>
                <div class="am-field"><label for="currency">Currency</label><select id="currency" name="currency"><?php foreach ($allowedCurrencies as $item): ?><option value="<?= $item ?>" <?= $settings['currency'] === $item ? 'selected' : '' ?>><?= $item === 'PHP' ? 'PHP — Philippine Peso' : 'USD — US Dollar' ?></option><?php endforeach; ?></select></div>
                <div class="am-field"><label for="date_format">Date format</label><select id="date_format" name="date_format"><?php foreach ($allowedDateFormats as $item): ?><option value="<?= adminManagementEscape($item) ?>" <?= $settings['date_format'] === $item ? 'selected' : '' ?>><?= adminManagementEscape(date($item)) ?></option><?php endforeach; ?></select></div>
              </div></article>
            </section>

            <section class="am-panel" data-panel-content="bookings">
              <article class="am-card"><header class="am-card-header"><div class="am-card-heading"><span class="am-card-icon"><svg viewBox="0 0 24 24"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 10h18"/></svg></span><div><h3>Reservation policies</h3><p>Operational defaults for accepting and reviewing reservations</p></div></div></header><div class="am-card-body am-grid-2">
                <div class="am-field"><label for="booking_notice_hours">Minimum booking notice</label><div class="am-input-unit"><input id="booking_notice_hours" name="booking_notice_hours" type="number" min="0" max="720" value="<?= (int)$settings['booking_notice_hours'] ?>"><span>hours</span></div><div class="am-field-help">Recommended: 24 hours to allow provider coordination.</div></div>
                <div class="am-field"><label for="cancellation_window_hours">Cancellation window</label><div class="am-input-unit"><input id="cancellation_window_hours" name="cancellation_window_hours" type="number" min="0" max="720" value="<?= (int)$settings['cancellation_window_hours'] ?>"><span>hours</span></div><div class="am-field-help">Used as the standard policy reference for staff.</div></div>
                <div class="am-field"><label for="capacity_warning_percent">Capacity warning threshold</label><div class="am-input-unit"><input id="capacity_warning_percent" name="capacity_warning_percent" type="number" min="1" max="100" value="<?= (int)$settings['capacity_warning_percent'] ?>"><span>percent</span></div><div class="am-field-help">Highlights schedules nearing capacity.</div></div>
                <div class="am-field"><label for="default_booking_status">New booking status</label><select id="default_booking_status" name="default_booking_status"><option value="pending" <?= $settings['default_booking_status'] === 'pending' ? 'selected' : '' ?>>Pending review (recommended)</option><option value="confirmed" <?= $settings['default_booking_status'] === 'confirmed' ? 'selected' : '' ?>>Confirmed automatically</option></select><div class="am-field-help">Pending review provides the safest operational workflow.</div></div>
              </div><footer class="am-card-footer"><span>These values define the portal's saved operating policy.</span><span>Changes are recorded in Activity Logs</span></footer></article>
              <div class="am-callout warning"><svg viewBox="0 0 24 24"><path d="M12 3 2.8 20h18.4L12 3Z"/><path d="M12 9v5M12 17.5h.01"/></svg><div><strong>Review before enabling automatic confirmation.</strong><br>Confirm that tour operators, guides, boats, and accommodations have reliable real-time availability.</div></div>
            </section>

            <section class="am-panel" data-panel-content="notifications">
              <article class="am-card"><header class="am-card-header"><div class="am-card-heading"><span class="am-card-icon"><svg viewBox="0 0 24 24"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9M10 21h4"/></svg></span><div><h3>Administrator alerts</h3><p>Choose the operational events administrators should monitor</p></div></div></header><div class="am-card-body am-setting-list">
                <?php $toggles = [['notify_new_booking','New bookings','Alert administrators when a tourist creates a reservation.'],['notify_payment','Payments and transactions','Alert administrators when payment activity requires attention.'],['notify_inquiry','Visitor inquiries','Alert administrators when a new inquiry is received.'],['notify_review','Reviews and feedback','Alert administrators when new visitor feedback is submitted.'],['email_digest','Daily email digest','Prepare a daily summary of important portal activity.']]; foreach ($toggles as [$key,$label,$description]): ?>
                  <div class="am-setting-row"><div class="am-setting-copy"><strong><?= adminManagementEscape($label) ?></strong><p><?= adminManagementEscape($description) ?></p></div><label class="am-switch"><input type="checkbox" name="<?= $key ?>" <?= !empty($settings[$key]) ? 'checked' : '' ?>><span></span></label></div>
                <?php endforeach; ?>
              </div></article>
              <article class="am-card"><header class="am-card-header"><div class="am-card-heading"><span class="am-card-icon"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg></span><div><h3>Digest schedule</h3><p>Preferred local delivery time for operational summaries</p></div></div></header><div class="am-card-body"><div class="am-field" style="max-width:280px"><label for="digest_time">Daily digest time</label><input id="digest_time" name="digest_time" type="time" value="<?= adminManagementEscape($settings['digest_time']) ?>"></div></div></article>
            </section>

            <section class="am-panel" data-panel-content="security">
              <article class="am-card"><header class="am-card-header"><div class="am-card-heading"><span class="am-card-icon"><svg viewBox="0 0 24 24"><path d="M12 3 4 6v6c0 5 3.4 8 8 9 4.6-1 8-4 8-9V6l-8-3Z"/><path d="m8.5 12 2.3 2.3 4.7-4.8"/></svg></span><div><h3>Security policy</h3><p>Session and audit-history controls for administrative access</p></div></div></header><div class="am-card-body am-grid-2">
                <div class="am-field"><label for="session_timeout_minutes">Administrative session timeout</label><div class="am-input-unit"><input id="session_timeout_minutes" name="session_timeout_minutes" type="number" min="15" max="480" value="<?= (int)$settings['session_timeout_minutes'] ?>"><span>minutes</span></div><div class="am-field-help">Recommended: 30–60 minutes on shared office computers.</div></div>
                <div class="am-field"><label for="audit_retention_days">Activity log retention</label><div class="am-input-unit"><input id="audit_retention_days" name="audit_retention_days" type="number" min="30" max="3650" value="<?= (int)$settings['audit_retention_days'] ?>"><span>days</span></div><div class="am-field-help">Recommended: at least 365 days for accountability.</div></div>
              </div></article>
              <article class="am-card"><header class="am-card-header"><div class="am-card-heading"><span class="am-card-icon"><svg viewBox="0 0 24 24"><path d="M4 5h16v14H4zM8 9h8M8 13h5"/></svg></span><div><h3>Configuration tools</h3><p>Download a portable copy or restore recommended values</p></div></div></header><div class="am-card-body"><div style="display:flex;gap:10px;flex-wrap:wrap"><button class="am-button" type="submit" name="action" value="export_settings"><svg viewBox="0 0 24 24"><path d="M12 3v12m0 0 4-4m-4 4-4-4M5 20h14"/></svg>Export JSON</button><button class="am-button danger" type="button" data-open-modal="resetModal"><svg viewBox="0 0 24 24"><path d="M3 12a9 9 0 1 0 3-6.7L3 8"/><path d="M3 3v5h5"/></svg>Restore defaults</button></div></div></article>
            </section>

            <section class="am-panel" data-panel-content="system">
              <article class="am-card"><header class="am-card-header"><div class="am-card-heading"><span class="am-card-icon"><svg viewBox="0 0 24 24"><path d="M5 4h14v16H5zM8 8h8M8 12h8M8 16h5"/></svg></span><div><h3>Environment information</h3><p>Read-only diagnostics for administrators and technical support</p></div></div></header><div class="am-card-body am-system-info">
                <div class="am-info-row"><span>Application</span><strong>iTour Mercedes Admin</strong></div><div class="am-info-row"><span>PHP version</span><strong><?= adminManagementEscape(PHP_VERSION) ?></strong></div><div class="am-info-row"><span>Database</span><strong>MySQL <?= adminManagementEscape(preg_replace('/-.*/', '', $dbVersion)) ?></strong></div><div class="am-info-row"><span>Database size</span><strong><?= number_format($dbSizeMb, 2) ?> MB</strong></div><div class="am-info-row"><span>Database tables</span><strong><?= number_format($tableCount) ?></strong></div><div class="am-info-row"><span>Upload storage</span><strong><?= $uploadWritable ? 'Writable' : 'Not writable' ?></strong></div><div class="am-info-row"><span>Server time</span><strong><?= adminManagementEscape(date('M d, Y · h:i A')) ?></strong></div><div class="am-info-row"><span>Last settings update</span><strong><?= $lastUpdated ? adminManagementEscape(date('M d, Y · h:i A', strtotime($lastUpdated))) : 'Using defaults' ?></strong></div>
              </div><footer class="am-card-footer"><span><?= $settingsEditor !== '' ? 'Last changed by ' . adminManagementEscape($settingsEditor) : 'No saved configuration history yet' ?></span><span>Passwords and credentials are never included in exports</span></footer></article>
              <div class="am-callout"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M12 11v5M12 8h.01"/></svg><div>This information can help technical support diagnose configuration issues. It contains no administrator password or private visitor data.</div></div>
            </section>
          </div>
        </div>
      </form>
    </div></div>
  </main>
</div>

<div class="am-modal" id="resetModal" role="dialog" aria-modal="true" aria-labelledby="resetTitle">
  <div class="am-modal-dialog"><header class="am-modal-header"><h3 id="resetTitle">Restore recommended defaults?</h3><button class="am-modal-close" type="button" data-close-modal aria-label="Close">&times;</button></header><div class="am-modal-body"><div class="am-callout warning"><svg viewBox="0 0 24 24"><path d="M12 3 2.8 20h18.4L12 3Z"/><path d="M12 9v5M12 17.5h.01"/></svg><div>Your office contact details, booking policies, and notification preferences will be replaced. You can export the current configuration first.</div></div></div><footer class="am-modal-footer"><button class="am-button" type="button" data-close-modal>Cancel</button><form method="post" action="<?= adminManagementEscape($pageFile) ?>"><input type="hidden" name="csrf_token" value="<?= adminManagementEscape($csrfToken) ?>"><input type="hidden" name="action" value="reset_settings"><button class="am-button danger" type="submit">Restore defaults</button></form></footer></div>
</div>

<script>
(() => {
  const tabs = [...document.querySelectorAll('[data-panel]')];
  const panels = [...document.querySelectorAll('[data-panel-content]')];
  const activate = name => {
    tabs.forEach(tab => tab.classList.toggle('active', tab.dataset.panel === name));
    panels.forEach(panel => panel.classList.toggle('active', panel.dataset.panelContent === name));
    try { sessionStorage.setItem('itour-settings-panel', name); } catch (error) {}
  };
  tabs.forEach(tab => tab.addEventListener('click', () => activate(tab.dataset.panel)));
  try { const saved = sessionStorage.getItem('itour-settings-panel'); if (tabs.some(tab => tab.dataset.panel === saved)) activate(saved); } catch (error) {}

  document.querySelectorAll('[data-open-modal]').forEach(button => button.addEventListener('click', () => document.getElementById(button.dataset.openModal)?.classList.add('open')));
  document.querySelectorAll('[data-close-modal]').forEach(button => button.addEventListener('click', () => button.closest('.am-modal')?.classList.remove('open')));
  document.querySelectorAll('.am-modal').forEach(modal => modal.addEventListener('click', event => { if (event.target === modal) modal.classList.remove('open'); }));
  document.addEventListener('keydown', event => { if (event.key === 'Escape') document.querySelectorAll('.am-modal.open').forEach(modal => modal.classList.remove('open')); });

  const form = document.getElementById('settingsForm');
  let clean = new FormData(form);
  let dirty = false;
  form.addEventListener('input', () => { dirty = true; });
  form.addEventListener('submit', () => { dirty = false; });
  window.addEventListener('beforeunload', event => { if (dirty) { event.preventDefault(); event.returnValue = ''; } });
})();
</script>
</body>
</html>
