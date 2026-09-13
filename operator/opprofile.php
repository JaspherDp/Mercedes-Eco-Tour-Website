<?php
declare(strict_types=1);

chdir(__DIR__ . '/..');
require_once __DIR__ . '/../php/session_security.php';
AppSessionStart();
require_once __DIR__ . '/../php/db_connection.php';
require_once __DIR__ . '/../php/activity_logger.php';
require_once __DIR__ . '/../php/operator_auth_helper.php';
require_once __DIR__ . '/../php/project_path_helper.php';

$operatorAccount = OperatorRequireLogin($pdo);

$operatorId = (int)$_SESSION['operator_id'];
$operatorNotificationCsrf = AppCsrfToken('operator', 'notifications');
if (($_POST['op_action'] ?? '') === 'mark_notifications_read') {
    if (!AppVerifyCsrf('operator', 'notifications', $_POST['csrf_token'] ?? null)) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false]);
        exit;
    }
    $_SESSION['op_notifications_seen_at'] = date('Y-m-d H:i:s');
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => true]);
    exit;
}

if (empty($_SESSION['operator_profile_csrf'])) $_SESSION['operator_profile_csrf'] = bin2hex(random_bytes(32));
$csrfToken = (string)$_SESSION['operator_profile_csrf'];
$errors = [];
$passwordErrors = [];
$openPasswordModal = false;

$operatorStmt = $pdo->prepare('SELECT * FROM operators WHERE operator_id=? LIMIT 1');
$operatorStmt->execute([$operatorId]);
$operator = $operatorStmt->fetch(PDO::FETCH_ASSOC);
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['profile_action'] ?? '') === 'save_profile') {
    $submittedCsrf = (string)($_POST['csrf_token'] ?? '');
    if (!hash_equals($csrfToken, $submittedCsrf)) $errors[] = 'Your form session expired. Refresh the page and try again.';

    $fullname = trim((string)($_POST['fullname'] ?? ''));
    $username = trim((string)($_POST['username'] ?? ''));
    $email = strtolower(trim((string)($_POST['email'] ?? '')));

    if (mb_strlen($fullname) < 2 || mb_strlen($fullname) > 100) $errors[] = 'Full name must contain 2 to 100 characters.';
    if (!preg_match('/^[A-Za-z0-9._-]{3,50}$/', $username)) $errors[] = 'Username must contain 3 to 50 letters, numbers, dots, underscores, or hyphens.';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 100) $errors[] = 'Enter a valid email address.';

    if (!$errors) {
        $duplicateStmt = $pdo->prepare('SELECT username,email FROM operators WHERE operator_id<>? AND (LOWER(username)=LOWER(?) OR LOWER(email)=LOWER(?)) LIMIT 1');
        $duplicateStmt->execute([$operatorId, $username, $email]);
        $duplicate = $duplicateStmt->fetch(PDO::FETCH_ASSOC);
        if ($duplicate) {
            if (strcasecmp((string)$duplicate['username'], $username) === 0) $errors[] = 'That username is already in use.';
            if (strcasecmp((string)$duplicate['email'], $email) === 0) $errors[] = 'That email address is already in use.';
        }
    }

    $profileFile = trim((string)($operator['profile_pic'] ?? ''));
    $upload = $_FILES['profile_pic'] ?? null;
    if ($upload && (int)$upload['error'] !== UPLOAD_ERR_NO_FILE) {
        if ((int)$upload['error'] !== UPLOAD_ERR_OK) {
            $errors[] = 'The profile image could not be uploaded.';
        } elseif ((int)$upload['size'] > 3 * 1024 * 1024) {
            $errors[] = 'Profile images must be 3 MB or smaller.';
        } else {
            $mime = (new finfo(FILEINFO_MIME_TYPE))->file((string)$upload['tmp_name']);
            $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
            if (!isset($extensions[$mime])) {
                $errors[] = 'Use a JPG, PNG, or WebP profile image.';
            } elseif (!$errors) {
                try {
                    $uploadDirectory = ItourEnsureProjectDirectory('uploads/profile');
                } catch (Throwable $exception) {
                    $errors[] = 'The profile image folder is unavailable.';
                    $uploadDirectory = null;
                }
                if ($uploadDirectory !== null) {
                    $profileFile = 'operator_' . $operatorId . '_' . bin2hex(random_bytes(8)) . '.' . $extensions[$mime];
                    if (!move_uploaded_file((string)$upload['tmp_name'], $uploadDirectory . DIRECTORY_SEPARATOR . $profileFile)) $errors[] = 'The profile image could not be saved.';
                }
            }
        }
    }

    if (!$errors) {
        try {
            $updateStmt = $pdo->prepare('UPDATE operators SET fullname=?,username=?,email=?,profile_pic=?,updated_at=NOW() WHERE operator_id=?');
            $updateStmt->execute([$fullname, $username, $email, $profileFile, $operatorId]);
            $_SESSION['operator_name'] = $fullname;
            $_SESSION['operator_email'] = $email;
            $_SESSION['operator_profile'] = $profileFile !== '' ? 'uploads/profile/' . $profileFile : '';
            $_SESSION['operator_profile_csrf'] = bin2hex(random_bytes(32));
            logActivity($pdo, 'Tour Operator', $operatorId, $fullname, 'Operator Profile Updated', 'Updated operator identity, contact, profile image, or account security settings.', 'Profile', $operatorId);
            $_SESSION['alert'] = ['type' => 'success', 'title' => 'Profile Updated', 'message' => 'Your operator account changes were saved successfully.'];
            header('Location: opprofile.php');
            exit;
        } catch (Throwable $exception) {
            error_log('Operator profile update failed: ' . $exception->getMessage());
            $errors[] = 'Your changes could not be saved right now. Please try again.';
        }
    }

    $operator['fullname'] = $fullname;
    $operator['username'] = $username;
    $operator['email'] = $email;
    $operator['profile_pic'] = $profileFile;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['profile_action'] ?? '') === 'change_password') {
    $openPasswordModal = true;
    $submittedCsrf = (string)($_POST['csrf_token'] ?? '');
    $currentPassword = (string)($_POST['current_password'] ?? '');
    $newPassword = (string)($_POST['new_password'] ?? '');
    $confirmPassword = (string)($_POST['confirm_password'] ?? '');
    if (!hash_equals($csrfToken, $submittedCsrf)) $passwordErrors[] = 'Your form session expired. Refresh the page and try again.';
    if (!password_verify($currentPassword, (string)$operator['password'])) $passwordErrors[] = 'The current password is incorrect.';
    if (strlen($newPassword) < 10) $passwordErrors[] = 'The new password must contain at least 10 characters.';
    if (!preg_match('/[A-Z]/', $newPassword)) $passwordErrors[] = 'Include at least one uppercase letter.';
    if (!preg_match('/[a-z]/', $newPassword)) $passwordErrors[] = 'Include at least one lowercase letter.';
    if (!preg_match('/[0-9]/', $newPassword)) $passwordErrors[] = 'Include at least one number.';
    if ($newPassword !== $confirmPassword) $passwordErrors[] = 'The new password confirmation does not match.';
    if (!$passwordErrors) {
        try {
            $passwordStmt = $pdo->prepare('UPDATE operators SET password=?,updated_at=NOW() WHERE operator_id=?');
            $passwordStmt->execute([password_hash($newPassword, PASSWORD_DEFAULT), $operatorId]);
            $_SESSION['operator_profile_csrf'] = bin2hex(random_bytes(32));
            logActivity($pdo, 'Tour Operator', $operatorId, (string)$operator['fullname'], 'Operator Password Changed', 'Changed the operator account password.', 'Profile', $operatorId);
            $_SESSION['alert'] = ['type' => 'success', 'title' => 'Password Updated', 'message' => 'Your operator account password was changed successfully.'];
            header('Location: opprofile.php');
            exit;
        } catch (Throwable $exception) {
            error_log('Operator password update failed: ' . $exception->getMessage());
            $passwordErrors[] = 'Your password could not be changed right now. Please try again.';
        }
    }
}

$statsStmt = $pdo->prepare("SELECT (SELECT COUNT(*) FROM tour_packages WHERE operator_id=?) package_count,(SELECT COUNT(*) FROM bookings WHERE operator_id=? AND LOWER(booking_type)='package') booking_count,(SELECT COUNT(*) FROM bookings WHERE operator_id=? AND LOWER(booking_type)='package' AND is_complete='completed') completed_count");
$statsStmt->execute([$operatorId, $operatorId, $operatorId]);
$stats = $statsStmt->fetch(PDO::FETCH_ASSOC) ?: ['package_count' => 0, 'booking_count' => 0, 'completed_count' => 0];

$seenAt = trim((string)($_SESSION['op_notifications_seen_at'] ?? ''));
if ($seenAt !== '' && preg_match('/^\d{4}-\d{2}-\d{2}\s\d{2}:\d{2}:\d{2}$/', $seenAt)) {
    $notificationStmt = $pdo->prepare("SELECT COUNT(*) FROM bookings WHERE operator_id=? AND status='pending' AND created_at>?");
    $notificationStmt->execute([$operatorId, $seenAt]);
} else {
    $notificationStmt = $pdo->prepare("SELECT COUNT(*) FROM bookings WHERE operator_id=? AND status='pending'");
    $notificationStmt->execute([$operatorId]);
}
$notificationCount = (int)$notificationStmt->fetchColumn();
$notificationStmt = $pdo->prepare('SELECT booking_reference,package_name,booking_date,status,created_at FROM bookings WHERE operator_id=? ORDER BY created_at DESC LIMIT 6');
$notificationStmt->execute([$operatorId]);
$notificationItems = $notificationStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$operatorName = (string)$operator['fullname'];
$profileFile = trim((string)($operator['profile_pic'] ?? ''));
$profilePath = $profileFile !== '' && file_exists(ItourProjectPath('uploads/profile/' . basename($profileFile))) ? 'uploads/profile/' . basename($profileFile) : '';
$initial = strtoupper(substr($operatorName !== '' ? $operatorName : 'O', 0, 1));
$createdAt = !empty($operator['created_at']) ? date('F j, Y', strtotime((string)$operator['created_at'])) : 'Not available';
$updatedAt = !empty($operator['updated_at']) ? date('F j, Y · g:i A', strtotime((string)$operator['updated_at'])) : 'Not available';
$profileOperator = $operator;
$emailConfigured = filter_var((string)$operator['email'], FILTER_VALIDATE_EMAIL) !== false;
$photoConfigured = $profilePath !== '';
$securityScore = 60 + ($emailConfigured ? 25 : 0) + ($photoConfigured ? 15 : 0);
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Operator Profile | iTour Mercedes</title>
  <link rel="icon" href="img/newlogo.png">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="styles/op_profile.css?v=4">
  <link rel="stylesheet" href="styles/operator_header.css?v=5">
</head>
<body>
<div class="op-profile-layout">
  <?php include __DIR__ . '/operator_sidebar.php'; ?>
  <main class="op-profile-main">
    <header class="operator-header">
      <div class="operator-header-left"><span class="operator-header-icon"><svg viewBox="0 0 24 24"><circle cx="12" cy="8" r="4"/><path d="M5 21a7 7 0 0 1 14 0"/></svg></span><div class="operator-header-copy"><h2>My Profile</h2><p>Manage your operator identity, contact details, and account security</p></div></div>
      <div class="operator-header-right">
        <div class="op-notif-wrap"><button type="button" class="op-notif-btn" id="opNotifToggle" aria-label="Notifications" aria-expanded="false"><svg viewBox="0 0 24 24"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9M10 21h4"/></svg><span class="op-header-sr">Notifications</span><?php if ($notificationCount > 0): ?><span class="op-notif-badge"><?= $notificationCount ?></span><?php endif; ?></button><div class="op-notif-panel" id="opNotifPanel"><h4>Recent Bookings</h4><?php if ($notificationItems): ?><ul class="op-notif-list"><?php foreach ($notificationItems as $item): ?><li><strong><?= htmlspecialchars((string)($item['booking_reference'] ?: 'Booking')) ?> - <?= htmlspecialchars((string)$item['package_name']) ?></strong><span><?= htmlspecialchars(ucfirst((string)$item['status'])) ?> · <?= htmlspecialchars((string)$item['booking_date']) ?></span><small><?= htmlspecialchars((string)$item['created_at']) ?></small></li><?php endforeach; ?></ul><?php else: ?><p class="op-notif-empty">No notifications yet.</p><?php endif; ?></div></div>
        <div class="op-topbar-profile" title="<?= htmlspecialchars($operatorName) ?>" data-operator-header-profile role="button" tabindex="0" aria-label="Open operator profile"><?php if ($profilePath): ?><img src="<?= htmlspecialchars($profilePath) ?>" alt="<?= htmlspecialchars($operatorName) ?>"><?php else: ?><?= htmlspecialchars($initial) ?><?php endif; ?></div>
      </div>
    </header>

    <div class="op-profile-workspace">
      <section class="op-profile-hero">
        <div class="op-profile-identity"><div class="op-profile-avatar"><?php if ($profilePath): ?><img src="<?= htmlspecialchars($profilePath) ?>" alt="<?= htmlspecialchars($operatorName) ?>"><?php else: ?><?= htmlspecialchars($initial) ?><?php endif; ?></div><div class="op-profile-copy"><small>TOUR OPERATOR ACCOUNT</small><h2><?= htmlspecialchars($operatorName) ?></h2><p>@<?= htmlspecialchars((string)$profileOperator['username']) ?> · <?= htmlspecialchars((string)$profileOperator['email']) ?></p><span class="op-profile-status"><i></i><?= htmlspecialchars((string)$profileOperator['status']) ?> account</span></div></div>
        <div class="op-profile-stats"><div class="op-profile-stat"><span>Tour packages</span><strong><?= number_format((int)$stats['package_count']) ?></strong></div><div class="op-profile-stat"><span>Total bookings</span><strong><?= number_format((int)$stats['booking_count']) ?></strong></div><div class="op-profile-stat"><span>Completed</span><strong><?= number_format((int)$stats['completed_count']) ?></strong></div></div>
      </section>

      <div class="op-profile-grid">
        <section class="op-profile-card">
          <header class="op-profile-card-head"><span class="op-profile-card-icon"><svg viewBox="0 0 24 24"><circle cx="12" cy="8" r="4"/><path d="M5 21a7 7 0 0 1 14 0"/></svg></span><div><h3>Profile information</h3><p>Keep your public operator details accurate and current.</p></div></header>
          <form class="op-profile-form" method="post" enctype="multipart/form-data">
            <input type="hidden" name="profile_action" value="save_profile"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
            <?php if ($errors): ?><div class="op-profile-errors" role="alert"><strong>Please review the following:</strong><ul><?php foreach ($errors as $error): ?><li><?= htmlspecialchars($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
            <div class="op-profile-photo-row"><div class="op-profile-photo-preview" id="opProfilePreview"><?php if ($profilePath): ?><img src="<?= htmlspecialchars($profilePath) ?>" alt="Current profile picture"><?php else: ?><span><?= htmlspecialchars($initial) ?></span><?php endif; ?></div><div class="op-profile-photo-copy"><strong>Profile picture</strong><span>Upload a square JPG, PNG, or WebP image up to 3 MB.</span><div class="op-profile-file"><input type="file" id="opProfilePhoto" name="profile_pic" accept="image/jpeg,image/png,image/webp"><label for="opProfilePhoto">Choose new photo</label></div></div></div>
            <div class="op-profile-fields"><div class="op-profile-field full"><label for="opFullname">Operator or business name</label><input id="opFullname" name="fullname" maxlength="100" value="<?= htmlspecialchars((string)$profileOperator['fullname']) ?>" autocomplete="name" required></div><div class="op-profile-field"><label for="opUsername">Username</label><input id="opUsername" name="username" maxlength="50" value="<?= htmlspecialchars((string)$profileOperator['username']) ?>" autocomplete="username" required><small>Letters, numbers, dots, underscores, and hyphens only.</small></div><div class="op-profile-field"><label for="opEmail">Email address</label><input id="opEmail" type="email" name="email" maxlength="100" value="<?= htmlspecialchars((string)$profileOperator['email']) ?>" autocomplete="email" required></div></div>
            <div class="op-profile-actions"><a class="op-profile-btn" href="ophomepage.php">Cancel</a><button class="op-profile-btn primary" type="submit">Save profile changes</button></div>
          </form>
        </section>

        <aside class="op-profile-side">
          <section class="op-profile-card"><header class="op-profile-card-head"><span class="op-profile-card-icon"><svg viewBox="0 0 24 24"><path d="M12 3 4 6v6c0 5 3.4 8 8 9 4.6-1 8-4 8-9V6l-8-3Z"/><path d="m9 12 2 2 4-4"/></svg></span><div><h3>Account details</h3><p>Administrative information for this operator account.</p></div></header><div class="op-account-list"><div class="op-account-row"><span>Account status</span><strong class="op-account-badge"><?= htmlspecialchars((string)$profileOperator['status']) ?></strong></div><div class="op-account-row"><span>Operator ID</span><strong>OP-<?= str_pad((string)$operatorId, 5, '0', STR_PAD_LEFT) ?></strong></div><div class="op-account-row"><span>Member since</span><strong><?= htmlspecialchars($createdAt) ?></strong></div><div class="op-account-row"><span>Last profile update</span><strong><?= htmlspecialchars($updatedAt) ?></strong></div></div></section>
          <section class="op-profile-card op-security-card"><header class="op-profile-card-head"><span class="op-profile-card-icon"><svg viewBox="0 0 24 24"><path d="M12 3 5 6v5c0 4.5 2.8 8 7 10 4.2-2 7-5.5 7-10V6l-7-3Z"/><path d="m9 12 2 2 4-4"/></svg></span><div><h3>Account security</h3><p>Security health at a glance</p></div></header><div class="op-security-health"><div class="op-security-summary"><div class="op-security-ring" style="--score:<?= $securityScore ?>"><strong><?= $securityScore ?>%</strong></div><div><h4><?= $securityScore === 100 ? 'Your profile is protected' : 'Complete your profile' ?></h4><p>Use a unique password and keep your account details current.</p></div></div><div class="op-security-checks"><div><span class="op-security-check-icon"><svg viewBox="0 0 24 24"><rect x="5" y="10" width="14" height="11" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/></svg></span><strong>Password</strong><em>Protected</em></div><div><span class="op-security-check-icon"><svg viewBox="0 0 24 24"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="m4 7 8 6 8-6"/></svg></span><strong>Email</strong><em><?= $emailConfigured ? 'Configured' : 'Not added' ?></em></div><div><span class="op-security-check-icon"><svg viewBox="0 0 24 24"><circle cx="12" cy="8" r="4"/><path d="M5 21a7 7 0 0 1 14 0"/></svg></span><strong>Profile photo</strong><em><?= $photoConfigured ? 'Added' : 'Not added' ?></em></div></div><button type="button" class="op-change-password-btn" id="opOpenPasswordModal"><svg viewBox="0 0 24 24"><rect x="5" y="10" width="14" height="11" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/></svg><span>Change password</span></button></div></section>
        </aside>
      </div>
    </div>
  </main>
</div>
<div class="op-password-overlay<?= $openPasswordModal ? ' show' : '' ?>" id="opPasswordModal" aria-hidden="<?= $openPasswordModal ? 'false' : 'true' ?>">
  <section class="op-password-modal" role="dialog" aria-modal="true" aria-labelledby="opPasswordTitle">
    <header class="op-password-head"><span class="op-password-head-icon"><svg viewBox="0 0 24 24"><rect x="5" y="10" width="14" height="11" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/></svg></span><div><h3 id="opPasswordTitle">Change operator password</h3><p>Choose a strong password you do not use elsewhere.</p></div><button type="button" class="op-password-close" data-close-password aria-label="Close password modal">&times;</button></header>
    <form method="post" id="opPasswordForm"><input type="hidden" name="profile_action" value="change_password"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
      <div class="op-password-body">
        <?php if ($passwordErrors): ?><div class="op-profile-errors" role="alert"><strong>Password was not changed:</strong><ul><?php foreach ($passwordErrors as $error): ?><li><?= htmlspecialchars($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
        <label class="op-password-field"><span>Current password</span><div><input type="password" name="current_password" autocomplete="current-password" required><button type="button" data-toggle-password aria-label="Show current password"><svg viewBox="0 0 24 24"><path d="M2 12s3.5-6 10-6 10 6 10 6-3.5 6-10 6S2 12 2 12Z"/><circle cx="12" cy="12" r="2.5"/></svg></button></div></label>
        <label class="op-password-field"><span>New password</span><div><input type="password" id="opNewPassword" name="new_password" minlength="10" autocomplete="new-password" required><button type="button" data-toggle-password aria-label="Show new password"><svg viewBox="0 0 24 24"><path d="M2 12s3.5-6 10-6 10 6 10 6-3.5 6-10 6S2 12 2 12Z"/><circle cx="12" cy="12" r="2.5"/></svg></button></div></label>
        <div class="op-password-strength"><div><span id="opPasswordStrengthBar"></span></div><ul><li data-rule="length">10+ characters</li><li data-rule="uppercase">Uppercase letter</li><li data-rule="lowercase">Lowercase letter</li><li data-rule="number">Number</li></ul></div>
        <label class="op-password-field"><span>Confirm new password</span><div><input type="password" id="opConfirmPassword" name="confirm_password" minlength="10" autocomplete="new-password" required><button type="button" data-toggle-password aria-label="Show password confirmation"><svg viewBox="0 0 24 24"><path d="M2 12s3.5-6 10-6 10 6 10 6-3.5 6-10 6S2 12 2 12Z"/><circle cx="12" cy="12" r="2.5"/></svg></button></div><small id="opPasswordMatch">Re-enter your new password.</small></label>
      </div>
      <footer class="op-password-foot"><button type="button" class="op-profile-btn" data-close-password>Cancel</button><button type="submit" class="op-profile-btn primary">Update password</button></footer>
    </form>
  </section>
</div>
<script src="js/operator_header.js?v=4"></script>
<script>
(()=>{'use strict';
const input=document.getElementById('opProfilePhoto'),preview=document.getElementById('opProfilePreview');input?.addEventListener('change',()=>{const file=input.files?.[0];if(!file||!file.type.startsWith('image/'))return;const url=URL.createObjectURL(file);preview.innerHTML=`<img src="${url}" alt="New profile picture preview">`;});
const toggle=document.getElementById('opNotifToggle'),panel=document.getElementById('opNotifPanel');let marked=false;toggle?.addEventListener('click',async event=>{event.stopPropagation();const open=panel?.classList.toggle('open')||false;toggle.setAttribute('aria-expanded',open?'true':'false');if(open&&!marked){marked=true;document.querySelector('.op-notif-badge')?.remove();try{await fetch(location.pathname,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams({op_action:'mark_notifications_read',csrf_token:<?= json_encode($operatorNotificationCsrf) ?>})});}catch(_error){}}});document.addEventListener('click',event=>{if(panel?.classList.contains('open')&&!panel.contains(event.target)){panel.classList.remove('open');toggle?.setAttribute('aria-expanded','false');}});
const passwordModal=document.getElementById('opPasswordModal'),newPassword=document.getElementById('opNewPassword'),confirmPassword=document.getElementById('opConfirmPassword'),strengthBar=document.getElementById('opPasswordStrengthBar'),matchText=document.getElementById('opPasswordMatch');
const setPasswordModal=open=>{passwordModal?.classList.toggle('show',open);passwordModal?.setAttribute('aria-hidden',open?'false':'true');document.body.classList.toggle('op-password-open',open);if(open)setTimeout(()=>passwordModal.querySelector('input')?.focus(),50);};
document.getElementById('opOpenPasswordModal')?.addEventListener('click',()=>setPasswordModal(true));document.querySelectorAll('[data-close-password]').forEach(button=>button.addEventListener('click',()=>setPasswordModal(false)));passwordModal?.addEventListener('mousedown',event=>{if(event.target===passwordModal)setPasswordModal(false);});document.addEventListener('keydown',event=>{if(event.key==='Escape'&&passwordModal?.classList.contains('show'))setPasswordModal(false);});
const passwordEyeOpen='<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M2 12s3.5-6 10-6 10 6 10 6-3.5 6-10 6S2 12 2 12Z"/><circle cx="12" cy="12" r="2.5"/></svg>',passwordEyeOff='<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 3l18 18"/><path d="M10.6 6.2A10.7 10.7 0 0 1 12 6c6 0 10 6 10 6a17 17 0 0 1-2.5 3.2M6.2 6.2C3.6 8.1 2 12 2 12s3.5 6 10 6a11 11 0 0 0 3.4-.5"/><path d="M9.9 9.9a3 3 0 0 0 4.2 4.2"/></svg>';
document.querySelectorAll('[data-toggle-password]').forEach(button=>button.addEventListener('click',()=>{const field=button.parentElement?.querySelector('input');if(!field)return;const showing=field.type==='password';field.type=showing?'text':'password';button.innerHTML=showing?passwordEyeOff:passwordEyeOpen;button.setAttribute('aria-pressed',showing?'true':'false');button.setAttribute('aria-label',showing?'Hide password':'Show password');}));
const updatePasswordStrength=()=>{const value=newPassword?.value||'',rules={length:value.length>=10,uppercase:/[A-Z]/.test(value),lowercase:/[a-z]/.test(value),number:/[0-9]/.test(value)},score=Object.values(rules).filter(Boolean).length;document.querySelectorAll('[data-rule]').forEach(item=>item.classList.toggle('valid',Boolean(rules[item.dataset.rule])));if(strengthBar){strengthBar.style.width=`${score*25}%`;strengthBar.style.background=score<2?'#c34858':score<4?'#d69a2d':'#21805f';}if(matchText){const confirmation=confirmPassword?.value||'';matchText.className=confirmation?(confirmation===value?'op-password-match-ok':'op-password-match-error'):'';matchText.textContent=confirmation?(confirmation===value?'Passwords match.':'Passwords do not match.'):'Re-enter your new password.';}};
newPassword?.addEventListener('input',updatePasswordStrength);confirmPassword?.addEventListener('input',updatePasswordStrength);if(passwordModal?.classList.contains('show'))document.body.classList.add('op-password-open');
})();
</script>
</body>
</html>
