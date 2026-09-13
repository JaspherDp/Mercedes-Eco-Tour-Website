<?php
declare(strict_types=1);

chdir(__DIR__ . '/..');
require_once __DIR__ . '/../php/session_security.php';
AppSessionStart();
require_once __DIR__ . '/../php/admin_auth_helper.php';
require_once __DIR__ . '/../php/db_connection.php';

$isDirectAdminRoute = str_ends_with(str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? ''))), '/admin');
$loginUrl = AdminLoginUrl('admin-phone-setup.php');
$hasValidAdminSession = AdminValidateSession($pdo);
$hasFreshPhoneGrant = is_string($_SESSION['admin_phone_setup_grant'] ?? null)
    && strlen((string)$_SESSION['admin_phone_setup_grant']) >= 32;

if (!$hasValidAdminSession || !$hasFreshPhoneGrant) {
    header('Location: ' . $loginUrl);
    exit;
}

// Consume the grant now. Reloading, reopening, or returning to this URL must
// pass through the Administrator login form again.
unset($_SESSION['admin_phone_setup_grant']);
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

$adminId = (int)$_SESSION['admin_id'];
$adminCheck = $pdo->prepare('SELECT full_name, username FROM admin_users WHERE admin_id = ? LIMIT 1');
$adminCheck->execute([$adminId]);
$admin = $adminCheck->fetch(PDO::FETCH_ASSOC);
if (!$admin) {
    session_unset();
    header('Location: ' . AdminLoginUrl('adhomepage.php'));
    exit;
}

if (empty($_SESSION['admin_push_csrf'])) {
    $_SESSION['admin_push_csrf'] = bin2hex(random_bytes(32));
}

$assetBase = $isDirectAdminRoute ? '../' : '';
$saveEndpoint = $isDirectAdminRoute ? 'save-push-device.php' : 'admin/save-push-device.php';
$configEndpoint = $assetBase . 'firebase-public-config.php';
$adminName = trim((string)($admin['full_name'] ?: $admin['username'] ?: 'Administrator'));
$phonePageToken = bin2hex(random_bytes(16));
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Register Administrator Phone - iTour Mercedes</title>
  <link rel="icon" type="image/png" href="<?= htmlspecialchars($assetBase, ENT_QUOTES, 'UTF-8') ?>img/newlogo.png">
  <script>
    (() => {
      const storageKey = 'itour:admin-phone-setup:active-page';
      const pageToken = <?= json_encode($phonePageToken, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
      const loginUrl = <?= json_encode($loginUrl, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
      const readToken = () => {
        try { return sessionStorage.getItem(storageKey); } catch (_) { return null; }
      };
      const clearToken = () => {
        try { sessionStorage.removeItem(storageKey); } catch (_) {}
      };
      const requireFreshLogin = () => window.location.replace(loginUrl);

      try { sessionStorage.setItem(storageKey, pageToken); } catch (_) {}

      window.addEventListener('pagehide', clearToken);
      window.addEventListener('pageshow', event => {
        if (event.persisted || readToken() !== pageToken) {
          clearToken();
          requireFreshLogin();
        }
      });
    })();
  </script>
  <style>
    *{box-sizing:border-box}body{min-height:100vh;margin:0;display:grid;place-items:center;padding:20px;color:#24443b;background:radial-gradient(circle at top,#e8f5f0,#f7faf9 46%,#e2efea);font-family:Arial,sans-serif}.phone-setup{width:min(470px,100%);overflow:hidden;border:1px solid #cfe0da;border-radius:20px;background:#fff;box-shadow:0 24px 70px rgba(14,61,47,.2)}.phone-head{padding:25px 25px 21px;color:#fff;background:linear-gradient(135deg,#103f32,#24745e)}.phone-brand{display:flex;align-items:center;gap:12px}.phone-brand img{width:48px;height:48px;padding:4px;border:1px solid rgba(255,255,255,.2);border-radius:13px;background:rgba(255,255,255,.1);object-fit:contain}.phone-head span{display:block;color:#b9e1d3;font-size:10px;font-weight:800;letter-spacing:.12em}.phone-head h1{margin:4px 0 0;font-size:22px}.phone-body{padding:24px}.phone-user{margin:0 0 19px;padding:11px 13px;border:1px solid #dce9e4;border-radius:10px;color:#567068;background:#f5f9f7;font-size:12px}.phone-user strong{color:#245044}.phone-steps{margin:0 0 20px;padding-left:20px;color:#61776f;font-size:12px;line-height:1.65}.phone-field{display:block;margin-bottom:15px}.phone-field span{display:block;margin-bottom:6px;color:#31574c;font-size:12px;font-weight:800}.phone-field input{width:100%;min-height:44px;padding:10px 12px;border:1px solid #cbdcd6;border-radius:10px;outline:none;color:#24443b;background:#fff}.phone-field input:focus{border-color:#4b9b81;box-shadow:0 0 0 3px rgba(75,155,129,.13)}.admin-push-enable-btn{width:100%;min-height:46px;display:flex;align-items:center;justify-content:center;gap:9px;padding:10px 14px;border:0;border-radius:11px;color:#fff;background:linear-gradient(135deg,#287a63,#185744);font:inherit;font-size:13px;font-weight:800;cursor:pointer;box-shadow:0 7px 16px rgba(28,105,82,.2)}.admin-push-enable-btn:disabled{cursor:wait;opacity:.7}.admin-push-enable-btn.is-enabled{background:#176b50;opacity:1;cursor:default}.admin-push-enable-btn svg{width:18px;height:18px;fill:none;stroke:currentColor;stroke-width:1.8;stroke-linecap:round;stroke-linejoin:round}.admin-push-status{display:block;margin-top:9px;color:#60776f;font-size:11px;line-height:1.45;text-align:center}.admin-push-status:empty{display:none}.admin-push-status[data-status=success]{padding:10px;border-radius:9px;color:#176447;background:#e5f4ed}.admin-push-status[data-status=error]{padding:10px;border-radius:9px;color:#993e49;background:#fff0f1}.admin-push-registration-success{display:grid;grid-template-columns:54px 1fr;gap:14px;margin-bottom:16px;padding:17px;border:1px solid #b8ddcf;border-radius:13px;background:linear-gradient(135deg,#eaf7f1,#f9fcfb);outline:none}.admin-push-success-icon{width:54px;height:54px;display:grid;place-items:center;border-radius:50%;color:#fff;background:#21805f;box-shadow:0 7px 16px rgba(33,128,95,.22)}.admin-push-success-icon svg{width:28px;height:28px;fill:none;stroke:currentColor;stroke-width:2.4;stroke-linecap:round;stroke-linejoin:round}.admin-push-registration-success small{color:#39735f;font-size:9px;font-weight:900;letter-spacing:.1em}.admin-push-registration-success h2{margin:3px 0 7px;color:#174d3d;font-size:18px}.admin-push-registration-success p{margin:5px 0;color:#58726a;font-size:11px;line-height:1.5}.admin-push-success-device{display:block;margin:8px 0;padding:8px 10px;border-radius:8px;color:#195d47;background:#fff;font-size:12px}.phone-foot{padding:14px 24px;border-top:1px solid #e1ebe7;color:#788b84;background:#fbfcfc;font-size:10px;text-align:center}
    .phone-steps[hidden],.phone-field[hidden]{display:none!important}
  </style>
</head>
<body>
  <main class="phone-setup">
    <header class="phone-head">
      <div class="phone-brand"><img src="<?= htmlspecialchars($assetBase, ENT_QUOTES, 'UTF-8') ?>img/newlogo.png" alt=""><div><span>ITOUR MERCEDES</span><h1>Register Admin Phone</h1></div></div>
    </header>
    <section class="phone-body">
      <p class="phone-user">Signed in as <strong><?= htmlspecialchars($adminName, ENT_QUOTES, 'UTF-8') ?></strong></p>
      <ol class="phone-steps"><li>Give this phone a recognizable name.</li><li>Tap the button below.</li><li>Allow notifications when your browser asks.</li></ol>
      <label class="phone-field"><span>Phone name</span><input id="adminPhoneDeviceName" maxlength="100" placeholder="Example: John's Samsung Phone" autocomplete="off"></label>
      <button
        type="button"
        class="admin-push-enable-btn"
        id="enableAdminPaymentNotifications"
        data-device-name-input="adminPhoneDeviceName"
        data-config-endpoint="<?= htmlspecialchars($configEndpoint, ENT_QUOTES, 'UTF-8') ?>"
        data-save-endpoint="<?= htmlspecialchars($saveEndpoint, ENT_QUOTES, 'UTF-8') ?>"
        data-csrf-token="<?= htmlspecialchars((string)$_SESSION['admin_push_csrf'], ENT_QUOTES, 'UTF-8') ?>"
      ><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9M10 21h4"/></svg><span>Register This Phone</span></button>
      <small class="admin-push-status" id="adminPaymentNotificationStatus" role="status" aria-live="polite"></small>
    </section>
    <footer class="phone-foot">This registers notification delivery only. It cannot approve or mark a payment as paid.</footer>
  </main>
  <script type="module" src="<?= htmlspecialchars($assetBase, ENT_QUOTES, 'UTF-8') ?>js/admin-push-notifications.js?v=5"></script>
</body>
</html>
