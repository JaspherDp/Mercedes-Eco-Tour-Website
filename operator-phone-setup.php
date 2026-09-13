<?php
declare(strict_types=1);

require_once __DIR__ . '/php/session_security.php';
AppSessionStart();
require_once __DIR__ . '/php/db_connection.php';
require_once __DIR__ . '/php/operator_auth_helper.php';
$_SESSION['operator_login_return_to'] = '../operator-phone-setup.php';
$operatorAccount = OperatorRequireLogin($pdo);
unset($_SESSION['operator_login_return_to']);
$operatorId = (int)$_SESSION['operator_id'];
$operatorQuery = $pdo->prepare("SELECT fullname, username FROM operators WHERE operator_id = ? AND status = 'active' LIMIT 1");
$operatorQuery->execute([$operatorId]);
$operator = $operatorQuery->fetch(PDO::FETCH_ASSOC);
if (!$operator) {
    unset($_SESSION['operator_logged_in'], $_SESSION['operator_id'], $_SESSION['operator_name']);
    header('Location: php/operator_login.php');
    exit;
}
if (empty($_SESSION['operator_push_csrf'])) $_SESSION['operator_push_csrf'] = bin2hex(random_bytes(32));
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
$operatorName = trim((string)($operator['fullname'] ?: $operator['username'] ?: 'Tour Operator'));
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Register Operator Payment Phone - iTour Mercedes</title>
  <link rel="icon" type="image/png" href="img/newlogo.png">
  <style>
    *{box-sizing:border-box}body{min-height:100vh;margin:0;display:grid;place-items:center;padding:20px;color:#24443b;background:radial-gradient(circle at top,#e8f5f0,#f7faf9 46%,#e2efea);font-family:Arial,sans-serif}.phone-setup{width:min(470px,100%);overflow:hidden;border:1px solid #cfe0da;border-radius:20px;background:#fff;box-shadow:0 24px 70px rgba(14,61,47,.2)}.phone-head{padding:25px;color:#fff;background:linear-gradient(135deg,#103f32,#24745e)}.phone-brand{display:flex;align-items:center;gap:12px}.phone-brand img{width:48px;height:48px;padding:4px;border:1px solid rgba(255,255,255,.2);border-radius:13px;background:rgba(255,255,255,.1);object-fit:contain}.phone-head span{display:block;color:#b9e1d3;font-size:10px;font-weight:800;letter-spacing:.12em}.phone-head h1{margin:4px 0 0;font-size:22px}.phone-body{padding:24px}.phone-user{margin:0 0 19px;padding:11px 13px;border:1px solid #dce9e4;border-radius:10px;color:#567068;background:#f5f9f7;font-size:12px}.phone-user strong{color:#245044}.phone-steps{margin:0 0 20px;padding-left:20px;color:#61776f;font-size:12px;line-height:1.65}.phone-field{display:block;margin-bottom:15px}.phone-field span{display:block;margin-bottom:6px;color:#31574c;font-size:12px;font-weight:800}.phone-field input{width:100%;min-height:44px;padding:10px 12px;border:1px solid #cbdcd6;border-radius:10px;outline:none}.admin-push-enable-btn{width:100%;min-height:46px;display:flex;align-items:center;justify-content:center;padding:10px 14px;border:0;border-radius:11px;color:#fff;background:#185744;font:inherit;font-size:13px;font-weight:800;cursor:pointer}.admin-push-enable-btn:disabled{cursor:wait;opacity:.7}.admin-push-enable-btn.is-enabled{opacity:1;cursor:default;background:#176b50}.admin-push-status{display:block;margin-top:10px;font-size:11px;line-height:1.45;text-align:center}.admin-push-status[data-status=success]{padding:10px;border-radius:9px;color:#176447;background:#e5f4ed}.admin-push-status[data-status=error]{padding:10px;border-radius:9px;color:#993e49;background:#fff0f1}.permission-help{margin:0 0 16px;padding:15px;border:1px solid #e8c9a0;border-radius:12px;color:#694b22;background:#fff8ec}.permission-help[hidden]{display:none}.permission-help strong{display:block;margin-bottom:7px;color:#714815;font-size:13px}.permission-help p{margin:0 0 8px;font-size:11px;line-height:1.5}.permission-help ol{margin:0 0 11px;padding-left:20px;font-size:11px;line-height:1.65}.permission-help button{width:100%;min-height:38px;border:1px solid #d8b477;border-radius:9px;color:#714815;background:#fff;font-weight:800;cursor:pointer}.admin-push-registration-success{display:grid;grid-template-columns:54px 1fr;gap:14px;margin-bottom:16px;padding:17px;border:1px solid #b8ddcf;border-radius:13px;background:linear-gradient(135deg,#eaf7f1,#f9fcfb);outline:none}.admin-push-success-icon{width:54px;height:54px;display:grid;place-items:center;border-radius:50%;color:#fff;background:#21805f}.admin-push-success-icon svg{width:28px;height:28px;fill:none;stroke:currentColor;stroke-width:2.4}.admin-push-registration-success small{color:#39735f;font-size:9px;font-weight:900;letter-spacing:.1em}.admin-push-registration-success h2{margin:3px 0 7px;color:#174d3d;font-size:18px}.admin-push-registration-success p{margin:5px 0;color:#58726a;font-size:11px;line-height:1.5}.admin-push-success-device{display:block;margin:8px 0;padding:8px 10px;border-radius:8px;color:#195d47;background:#fff;font-size:12px}.phone-foot{padding:14px 24px;border-top:1px solid #e1ebe7;color:#788b84;background:#fbfcfc;font-size:10px;text-align:center}.phone-steps[hidden],.phone-field[hidden]{display:none!important}
  </style>
</head>
<body>
  <main class="phone-setup">
    <header class="phone-head"><div class="phone-brand"><img src="img/newlogo.png" alt=""><div><span>ITOUR MERCEDES</span><h1>Register Operator Payment Phone</h1></div></div></header>
    <section class="phone-body">
      <p class="phone-user">Signed in as <strong><?= htmlspecialchars($operatorName, ENT_QUOTES, 'UTF-8') ?></strong></p>
      <section class="permission-help" id="notificationPermissionHelp" hidden>
        <strong>Allow notifications in your browser settings</strong>
        <p id="notificationPermissionHelpMessage">This browser is currently preventing the website from asking for notification permission.</p>
        <ol><li>Tap the tune, lock, or site icon beside the address bar.</li><li>Open <b>Permissions</b> or <b>Site settings</b>.</li><li>Set <b>Notifications</b> to <b>Allow</b>. If it is missing, enable “Sites can ask to send notifications” in the browser’s notification settings.</li></ol>
        <p><b>iPhone or iPad:</b> add this HTTPS page to the Home Screen, open the installed web app, and register the phone there.</p>
        <button type="button" id="reloadAfterPermissionChange">Reload After Allowing</button>
      </section>
      <ol class="phone-steps"><li>Give this phone a recognizable name.</li><li>Tap Register This Phone.</li><li>Allow notifications when asked.</li></ol>
      <label class="phone-field"><span>Phone name</span><input id="operatorPhoneDeviceName" maxlength="100" placeholder="Example: Tour Desk Phone" autocomplete="off"></label>
      <button type="button" class="admin-push-enable-btn" id="enableAdminPaymentNotifications"
        data-device-name-input="operatorPhoneDeviceName" data-config-endpoint="firebase-public-config.php"
        data-save-endpoint="operator-save-push-device.php" data-csrf-token="<?= htmlspecialchars((string)$_SESSION['operator_push_csrf'], ENT_QUOTES, 'UTF-8') ?>"
        data-account-label="Tour Operator" data-return-label="operator bookings page"><span>Register This Phone</span></button>
      <small class="admin-push-status" id="adminPaymentNotificationStatus" role="status" aria-live="polite"></small>
    </section>
    <footer class="phone-foot">Registration enables QR notifications only. Payments are still verified by PayMongo.</footer>
  </main>
  <script type="module" src="js/admin-push-notifications.js?v=6"></script>
</body>
</html>
