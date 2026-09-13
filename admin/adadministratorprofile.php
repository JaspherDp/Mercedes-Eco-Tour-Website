<?php
chdir(__DIR__ . '/..');
require_once __DIR__ . '/../php/session_security.php';
AppSessionStart();
require_once __DIR__ . '/../php/db_connection.php';
require_once __DIR__ . '/../php/activity_logger.php';
require_once __DIR__ . '/../php/admin_management_helper.php';
require_once __DIR__ . '/../php/project_path_helper.php';

adminManagementRequireLogin();
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
ensureAdminManagementTables($pdo);

$pageFile = basename((string)($_SERVER['PHP_SELF'] ?? ''));
$assetPrefix = strtolower(basename(dirname((string)($_SERVER['SCRIPT_NAME'] ?? '')))) === 'admin' ? '../' : '';
$adminId = (int)($_SESSION['admin_id'] ?? 0);
if ($adminId <= 0 && !empty($_SESSION['username'])) {
    $stmt = $pdo->prepare('SELECT admin_id FROM admin_users WHERE username = ? LIMIT 1');
    $stmt->execute([(string)$_SESSION['username']]);
    $adminId = (int)$stmt->fetchColumn();
    $_SESSION['admin_id'] = $adminId;
}

function fetchAdministratorProfile(PDO $pdo, int $adminId): ?array
{
    $stmt = $pdo->prepare(
        'SELECT a.admin_id, a.username, a.password, a.full_name, a.email, a.created_at,
                p.phone, p.job_title, p.bio, p.profile_picture, p.updated_at
         FROM admin_users a
         LEFT JOIN admin_profile_details p ON p.admin_id = a.admin_id
         WHERE a.admin_id = ? LIMIT 1'
    );
    $stmt->execute([$adminId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

$admin = fetchAdministratorProfile($pdo, $adminId);
if (!$admin) {
    adminManagementRedirect('adhomepage.php', 'error', 'The administrator account could not be found.');
}
$actorName = (string)($admin['full_name'] ?: $admin['username']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyAdminManagementCsrf()) {
        adminManagementRedirect($pageFile, 'error', 'Your form session expired. Please try again.');
    }
    $action = (string)($_POST['action'] ?? 'update_profile');

    if ($action === 'update_profile') {
        $fullName = mb_substr(trim((string)($_POST['full_name'] ?? '')), 0, 100);
        $username = mb_substr(trim((string)($_POST['username'] ?? '')), 0, 50);
        $email = mb_substr(trim((string)($_POST['email'] ?? '')), 0, 100);
        $phone = mb_substr(trim((string)($_POST['phone'] ?? '')), 0, 40);
        $jobTitle = mb_substr(trim((string)($_POST['job_title'] ?? '')), 0, 100);
        $bio = mb_substr(trim((string)($_POST['bio'] ?? '')), 0, 500);

        if ($fullName === '' || $username === '' || $email === '') {
            adminManagementRedirect($pageFile, 'error', 'Full name, username, and email are required.');
        }
        if (!preg_match('/^[A-Za-z0-9._-]{3,50}$/', $username)) {
            adminManagementRedirect($pageFile, 'error', 'Username must be 3–50 characters and use only letters, numbers, dots, underscores, or hyphens.');
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            adminManagementRedirect($pageFile, 'error', 'Enter a valid administrator email address.');
        }
        $unique = $pdo->prepare('SELECT COUNT(*) FROM admin_users WHERE (username = ? OR email = ?) AND admin_id <> ?');
        $unique->execute([$username, $email, $adminId]);
        if ((int)$unique->fetchColumn() > 0) {
            adminManagementRedirect($pageFile, 'error', 'That username or email is already used by another administrator.');
        }

        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare('UPDATE admin_users SET full_name = ?, username = ?, email = ? WHERE admin_id = ?');
            $stmt->execute([$fullName, $username, $email, $adminId]);
            $stmt = $pdo->prepare(
                'INSERT INTO admin_profile_details (admin_id, phone, job_title, bio)
                 VALUES (?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE phone = VALUES(phone), job_title = VALUES(job_title), bio = VALUES(bio)'
            );
            $stmt->execute([$adminId, $phone ?: null, $jobTitle ?: null, $bio ?: null]);
            $pdo->commit();
            $_SESSION['username'] = $username;
            $_SESSION['admin_name'] = $fullName;
            logActivity($pdo, 'Admin', $adminId, $fullName, 'Updated Profile', 'Updated administrator identity and contact details.', 'Administrator Profile', $adminId);
            adminManagementRedirect($pageFile, 'success', 'Your administrator profile was updated.');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('Admin profile update failed: ' . $e->getMessage());
            adminManagementRedirect($pageFile, 'error', 'Your profile could not be updated. Please try again.');
        }
    }

    if ($action === 'upload_avatar') {
        if (!isset($_FILES['profile_picture']) || $_FILES['profile_picture']['error'] !== UPLOAD_ERR_OK) {
            adminManagementRedirect($pageFile, 'error', 'Choose a valid image to upload.');
        }
        $file = $_FILES['profile_picture'];
        if ((int)$file['size'] > 2 * 1024 * 1024) {
            adminManagementRedirect($pageFile, 'error', 'Profile images must be 2 MB or smaller.');
        }
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file((string)$file['tmp_name']);
        $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
        if (!isset($extensions[$mime])) {
            adminManagementRedirect($pageFile, 'error', 'Use a JPEG, PNG, or WebP image.');
        }
        try {
            $uploadDirectory = ItourEnsureProjectDirectory('uploads/admin_profiles');
        } catch (Throwable $exception) {
            adminManagementRedirect($pageFile, 'error', 'The profile image folder is not writable.');
        }
        $filename = 'admin-' . $adminId . '-' . bin2hex(random_bytes(8)) . '.' . $extensions[$mime];
        $absoluteTarget = $uploadDirectory . DIRECTORY_SEPARATOR . $filename;
        if (!move_uploaded_file((string)$file['tmp_name'], $absoluteTarget)) {
            adminManagementRedirect($pageFile, 'error', 'The image could not be saved. Please try again.');
        }
        $newPath = 'uploads/admin_profiles/' . $filename;
        try {
            $stmt = $pdo->prepare(
                'INSERT INTO admin_profile_details (admin_id, profile_picture) VALUES (?, ?)
                 ON DUPLICATE KEY UPDATE profile_picture = VALUES(profile_picture)'
            );
            $stmt->execute([$adminId, $newPath]);
            $oldPath = (string)($admin['profile_picture'] ?? '');
            if (preg_match('#^uploads/admin_profiles/[^/]+$#', $oldPath)) {
                $oldAbsolute = realpath(ItourProjectPath($oldPath));
                $safeRoot = realpath($uploadDirectory);
                if ($oldAbsolute && $safeRoot && str_starts_with(strtolower($oldAbsolute), strtolower($safeRoot . DIRECTORY_SEPARATOR)) && is_file($oldAbsolute)) unlink($oldAbsolute);
            }
            logActivity($pdo, 'Admin', $adminId, $actorName, 'Updated Avatar', 'Changed the administrator profile image.', 'Administrator Profile', $adminId);
            adminManagementRedirect($pageFile, 'success', 'Your profile image was updated.');
        } catch (Throwable $e) {
            if (is_file($absoluteTarget)) unlink($absoluteTarget);
            error_log('Admin avatar update failed: ' . $e->getMessage());
            adminManagementRedirect($pageFile, 'error', 'The image could not be attached to your profile.');
        }
    }

    if ($action === 'remove_avatar') {
        try {
            $stmt = $pdo->prepare('UPDATE admin_profile_details SET profile_picture = NULL WHERE admin_id = ?');
            $stmt->execute([$adminId]);
            $oldPath = (string)($admin['profile_picture'] ?? '');
            if (preg_match('#^uploads/admin_profiles/[^/]+$#', $oldPath)) {
                $oldAbsolute = realpath(ItourProjectPath($oldPath));
                $safeRoot = realpath(ItourProjectPath('uploads/admin_profiles'));
                if ($oldAbsolute && $safeRoot && str_starts_with(strtolower($oldAbsolute), strtolower($safeRoot . DIRECTORY_SEPARATOR)) && is_file($oldAbsolute)) unlink($oldAbsolute);
            }
            logActivity($pdo, 'Admin', $adminId, $actorName, 'Removed Avatar', 'Removed the administrator profile image.', 'Administrator Profile', $adminId);
            adminManagementRedirect($pageFile, 'success', 'Your profile image was removed.');
        } catch (Throwable $e) {
            adminManagementRedirect($pageFile, 'error', 'The profile image could not be removed.');
        }
    }

    if ($action === 'change_password') {
        $currentPassword = (string)($_POST['current_password'] ?? '');
        $newPassword = (string)($_POST['new_password'] ?? '');
        $confirmPassword = (string)($_POST['confirm_password'] ?? '');
        if (!password_verify($currentPassword, (string)$admin['password'])) {
            adminManagementRedirect($pageFile, 'error', 'The current password you entered is incorrect.');
        }
        if (strlen($newPassword) < 10 || !preg_match('/[A-Z]/', $newPassword) || !preg_match('/[a-z]/', $newPassword) || !preg_match('/\d/', $newPassword)) {
            adminManagementRedirect($pageFile, 'error', 'Use at least 10 characters with uppercase, lowercase, and a number.');
        }
        if ($newPassword !== $confirmPassword) {
            adminManagementRedirect($pageFile, 'error', 'The new passwords do not match.');
        }
        if (password_verify($newPassword, (string)$admin['password'])) {
            adminManagementRedirect($pageFile, 'error', 'Choose a password different from your current password.');
        }
        try {
            $stmt = $pdo->prepare('UPDATE admin_users SET password = ? WHERE admin_id = ?');
            $stmt->execute([password_hash($newPassword, PASSWORD_DEFAULT), $adminId]);
            session_regenerate_id(true);
            logActivity($pdo, 'Admin', $adminId, $actorName, 'Changed Password', 'Changed the administrator account password.', 'Security', $adminId);
            adminManagementRedirect($pageFile, 'success', 'Your password was changed securely.');
        } catch (Throwable $e) {
            error_log('Admin password update failed: ' . $e->getMessage());
            adminManagementRedirect($pageFile, 'error', 'Your password could not be changed. Please try again.');
        }
    }
}

$admin = fetchAdministratorProfile($pdo, $adminId);
$flash = adminManagementFlash();
$csrfToken = adminManagementCsrfToken();
$recentActivity = [];
try {
    $stmt = $pdo->prepare('SELECT action, module, description, ip_address, created_at FROM admin_activity_logs WHERE actor_type = "Admin" AND actor_id = ? ORDER BY created_at DESC LIMIT 6');
    $stmt->execute([$adminId]);
    $recentActivity = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}
$initialsSource = trim((string)($admin['full_name'] ?: $admin['username']));
$words = preg_split('/\s+/', $initialsSource) ?: [];
$initials = strtoupper(substr((string)($words[0] ?? 'A'), 0, 1) . (count($words) > 1 ? substr((string)end($words), 0, 1) : ''));
$avatarUrl = adminProfileImageUrl((string)($admin['profile_picture'] ?? ''));
$createdTimestamp = strtotime((string)($admin['created_at'] ?? '')) ?: time();
$updatedTimestamp = strtotime((string)($admin['updated_at'] ?? '')) ?: null;
$profileCompleteFields = [$admin['full_name'], $admin['email'], $admin['phone'], $admin['job_title'], $admin['bio'], $admin['profile_picture']];
$profileCompletion = (int)round((count(array_filter($profileCompleteFields, fn($value) => trim((string)$value) !== '')) / count($profileCompleteFields)) * 100);
$securityScore = 60 + ($admin['email'] ? 15 : 0) + ($admin['profile_picture'] ? 10 : 0) + ($admin['phone'] ? 10 : 0) + 5;
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Administrator Profile | iTour Mercedes Admin</title>
  <link rel="icon" type="image/png" href="<?= $assetPrefix ?>img/newlogo.png?v=2">
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="<?= $assetPrefix ?>styles/admin_panel_theme.css">
  <link rel="stylesheet" href="<?= $assetPrefix ?>styles/admin_management.css?v=6">
</head>
<body>
<div class="admin-container">
  <?php include __DIR__ . '/admin_sidebar.php'; ?>
  <main class="main-content admin-management-main admin-profile-main">
    <header class="admin-header admin-page-header profile-page-header">
      <div class="admin-header-left admin-page-title"><span class="admin-page-title-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/></svg></span><div class="admin-page-title-copy"><h2>Administrator Profile</h2><p class="admin-header-subtitle">Manage your identity, contact details, and account security</p></div></div>
      <div class="admin-header-right am-header-actions"><button class="am-button" type="button" data-open-modal="passwordModal"><svg viewBox="0 0 24 24"><rect x="4" y="10" width="16" height="11" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/></svg>Change password</button><button class="am-save-button" type="submit" form="profileForm"><svg viewBox="0 0 24 24"><path d="M5 4h12l2 2v14H5V4Z"/><path d="M8 4v6h8V4M8 20v-6h8v6"/></svg>Save profile</button></div>
    </header>

    <div class="admin-management-content"><div class="am-shell">
      <?php if ($flash): ?><div class="am-flash <?= ($flash['status'] ?? '') === 'error' ? 'error' : '' ?>"><span><?= adminManagementEscape($flash['message'] ?? '') ?></span><button type="button" aria-label="Dismiss" onclick="this.parentElement.remove()">&times;</button></div><?php endif; ?>

      <section class="am-profile-hero">
        <div class="am-profile-identity"><div class="am-avatar"><?php if ($avatarUrl): ?><img src="<?= adminManagementEscape($avatarUrl) ?>" alt="<?= adminManagementEscape($initialsSource) ?> profile image"><?php else: ?><?= adminManagementEscape($initials) ?><?php endif; ?></div><div><h3><?= adminManagementEscape($initialsSource) ?></h3><p><?= adminManagementEscape($admin['email']) ?></p><span class="am-role-pill">● Website administrator</span></div></div>
        <div class="am-profile-hero-meta"><div><strong><?= $profileCompletion ?>%</strong><span>Profile complete</span></div><div><strong><?= date('M Y', $createdTimestamp) ?></strong><span>Member since</span></div><div><strong>Active</strong><span>Account status</span></div></div>
      </section>

      <div class="am-profile-grid">
        <div>
          <form id="profileForm" method="post" action="<?= adminManagementEscape($pageFile) ?>">
            <input type="hidden" name="csrf_token" value="<?= adminManagementEscape($csrfToken) ?>"><input type="hidden" name="action" value="update_profile">
            <article class="am-card"><header class="am-card-header"><div class="am-card-heading"><span class="am-card-icon"><svg viewBox="0 0 24 24"><circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/></svg></span><div><h3>Personal information</h3><p>Your administrator identity and public office role</p></div></div><button class="am-button am-change-photo" type="button" data-open-modal="avatarModal"><svg viewBox="0 0 24 24"><rect x="3" y="6" width="18" height="14" rx="2"/><path d="m8 6 1.5-2h5L16 6"/><circle cx="12" cy="13" r="3.5"/></svg>Change photo</button></header><div class="am-card-body am-grid-2">
              <div class="am-field"><label for="full_name">Full name</label><input id="full_name" name="full_name" maxlength="100" required autocomplete="name" value="<?= adminManagementEscape($admin['full_name']) ?>"></div>
              <div class="am-field"><label for="job_title">Position / job title <span>(optional)</span></label><input id="job_title" name="job_title" maxlength="100" value="<?= adminManagementEscape($admin['job_title']) ?>" placeholder="Tourism Officer"></div>
              <div class="am-field full"><label for="bio">Professional bio <span>(optional)</span></label><textarea id="bio" name="bio" maxlength="500" placeholder="Briefly describe your role and responsibilities..."><?= adminManagementEscape($admin['bio']) ?></textarea><div class="am-field-help"><span id="bioCount"><?= mb_strlen((string)$admin['bio']) ?></span>/500 characters</div></div>
            </div></article>

            <article class="am-card"><header class="am-card-header"><div class="am-card-heading"><span class="am-card-icon"><svg viewBox="0 0 24 24"><path d="M4 5h16v14H4zM8 9h8M8 13h5"/></svg></span><div><h3>Contact & login details</h3><p>Information used to identify and contact this administrator</p></div></div></header><div class="am-card-body am-grid-2">
              <div class="am-field"><label for="username">Username</label><input id="username" name="username" maxlength="50" required autocomplete="username" value="<?= adminManagementEscape($admin['username']) ?>"><div class="am-field-help">Letters, numbers, dots, underscores, and hyphens only.</div></div>
              <div class="am-field"><label for="email">Email address</label><input id="email" name="email" type="email" maxlength="100" required autocomplete="email" value="<?= adminManagementEscape($admin['email']) ?>"></div>
              <div class="am-field"><label for="phone">Phone number <span>(optional)</span></label><input id="phone" name="phone" type="tel" maxlength="40" autocomplete="tel" value="<?= adminManagementEscape($admin['phone']) ?>" placeholder="+63 9XX XXX XXXX"></div>
              <div class="am-field"><label>Account identifier</label><input value="ADM-<?= str_pad((string)$adminId, 5, '0', STR_PAD_LEFT) ?>" readonly></div>
            </div><footer class="am-card-footer"><span>Changing your username updates your next sign-in.</span><span>Account #<?= (int)$adminId ?></span></footer></article>
          </form>
        </div>

        <aside>
          <article class="am-card"><header class="am-card-header"><div class="am-card-heading"><span class="am-card-icon"><svg viewBox="0 0 24 24"><path d="M12 3 4 6v6c0 5 3.4 8 8 9 4.6-1 8-4 8-9V6l-8-3Z"/><path d="m8.5 12 2.3 2.3 4.7-4.8"/></svg></span><div><h3>Account security</h3><p>Security health at a glance</p></div></div></header><div class="am-card-body"><div class="am-security-score"><div class="am-score-ring" style="--score:<?= min(100,$securityScore) ?>"><strong><?= min(100,$securityScore) ?>%</strong></div><div><h4><?= $securityScore >= 90 ? 'Strong account setup' : 'Complete your profile' ?></h4><p>Use a unique password and keep your recovery contact details current.</p></div></div><div class="am-quick-list" style="margin-top:15px"><div class="am-quick-row"><div><span class="am-quick-icon"><svg viewBox="0 0 24 24"><rect x="4" y="10" width="16" height="11" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/></svg></span>Password</div><span>Protected</span></div><div class="am-quick-row"><div><span class="am-quick-icon"><svg viewBox="0 0 24 24"><path d="M4 5h16v14H4zM4 7l8 6 8-6"/></svg></span>Email</div><span><?= $admin['email'] ? 'Configured' : 'Missing' ?></span></div><div class="am-quick-row"><div><span class="am-quick-icon"><svg viewBox="0 0 24 24"><path d="M7 3h10v18H7zM10 18h4"/></svg></span>Phone</div><span><?= $admin['phone'] ? 'Configured' : 'Not added' ?></span></div></div><button class="am-button am-security-password" type="button" data-open-modal="passwordModal"><svg viewBox="0 0 24 24"><rect x="4" y="10" width="16" height="11" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/></svg>Change password</button></div></article>

          <article class="am-card"><header class="am-card-header"><div class="am-card-heading"><span class="am-card-icon"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg></span><div><h3>Current session</h3><p>Your present administrator access</p></div></div></header><div class="am-card-body am-quick-list"><div class="am-quick-row"><div>Signed in as</div><span><?= adminManagementEscape($admin['username']) ?></span></div><div class="am-quick-row"><div>IP address</div><span><?= adminManagementEscape($_SERVER['REMOTE_ADDR'] ?? 'Unknown') ?></span></div><div class="am-quick-row"><div>Session started</div><span><?= adminManagementEscape(date('M d · h:i A', (int)($_SESSION['admin_session_started'] ?? $_SERVER['REQUEST_TIME'] ?? time()))) ?></span></div><div class="am-quick-row"><div>Profile updated</div><span><?= $updatedTimestamp ? date('M d, Y', $updatedTimestamp) : 'Not yet' ?></span></div></div></article>

        </aside>
      </div>

      <article class="am-card am-activity-wide"><header class="am-card-header"><div class="am-card-heading"><span class="am-card-icon"><svg viewBox="0 0 24 24"><path d="M3 12a9 9 0 1 0 3-6.7L3 8"/><path d="M3 3v5h5M12 7v5l3 2"/></svg></span><div><h3>Recent activity</h3><p>Your latest account and security events</p></div></div><a class="am-button" href="adactivitylog.php">View all activity</a></header><div class="am-card-body"><?php if (!$recentActivity): ?><div class="am-empty">No administrator activity has been recorded yet.</div><?php else: ?><div class="am-activity-list"><?php foreach ($recentActivity as $activity): ?><div class="am-activity-item"><span class="am-activity-dot"></span><div><strong><?= adminManagementEscape($activity['action']) ?> · <?= adminManagementEscape($activity['module']) ?></strong><p><?= adminManagementEscape($activity['description']) ?><br><?= adminManagementEscape(date('M d, Y · h:i A', strtotime($activity['created_at']))) ?></p></div></div><?php endforeach; ?></div><?php endif; ?></div></article>
    </div></div>
  </main>
</div>

<div class="am-modal" id="avatarModal" role="dialog" aria-modal="true" aria-labelledby="avatarTitle"><div class="am-modal-dialog"><header class="am-modal-header"><h3 id="avatarTitle">Update profile image</h3><button class="am-modal-close" type="button" data-close-modal aria-label="Close">&times;</button></header><form method="post" enctype="multipart/form-data" action="<?= adminManagementEscape($pageFile) ?>"><input type="hidden" name="csrf_token" value="<?= adminManagementEscape($csrfToken) ?>"><input type="hidden" name="action" value="upload_avatar"><div class="am-modal-body"><div class="am-avatar-upload"><div class="am-avatar-preview" id="avatarPreview"><?php if ($avatarUrl): ?><img src="<?= adminManagementEscape($avatarUrl) ?>" alt="Current profile image"><?php else: ?><?= adminManagementEscape($initials) ?><?php endif; ?></div><label class="am-button" for="profile_picture">Choose image</label><input id="profile_picture" name="profile_picture" type="file" accept="image/jpeg,image/png,image/webp" hidden required><p>JPEG, PNG, or WebP · maximum 2 MB</p></div></div><footer class="am-modal-footer"><?php if ($avatarUrl): ?><button class="am-button danger" type="button" data-open-modal="removeAvatarModal">Remove photo</button><?php endif; ?><button class="am-button" type="button" data-close-modal>Cancel</button><button class="am-save-button" type="submit">Upload photo</button></footer></form></div></div>

<div class="am-modal" id="removeAvatarModal" role="dialog" aria-modal="true" aria-labelledby="removeAvatarTitle"><div class="am-modal-dialog"><header class="am-modal-header"><h3 id="removeAvatarTitle">Remove profile image?</h3><button class="am-modal-close" type="button" data-close-modal aria-label="Close">&times;</button></header><div class="am-modal-body"><div class="am-callout warning"><svg viewBox="0 0 24 24"><path d="M12 3 2.8 20h18.4L12 3Z"/><path d="M12 9v5M12 17.5h.01"/></svg><div>Your initials will be displayed in place of your photo.</div></div></div><footer class="am-modal-footer"><button class="am-button" type="button" data-close-modal>Cancel</button><form method="post" action="<?= adminManagementEscape($pageFile) ?>"><input type="hidden" name="csrf_token" value="<?= adminManagementEscape($csrfToken) ?>"><input type="hidden" name="action" value="remove_avatar"><button class="am-button danger" type="submit">Remove photo</button></form></footer></div></div>

<div class="am-modal" id="passwordModal" role="dialog" aria-modal="true" aria-labelledby="passwordTitle"><div class="am-modal-dialog"><header class="am-modal-header"><div class="am-card-heading"><span class="am-card-icon"><svg viewBox="0 0 24 24"><rect x="4" y="10" width="16" height="11" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/></svg></span><div><h3 id="passwordTitle">Change administrator password</h3><p>Choose a strong password you do not use elsewhere</p></div></div><button class="am-modal-close" type="button" data-close-modal aria-label="Close">&times;</button></header><form id="passwordForm" method="post" action="<?= adminManagementEscape($pageFile) ?>"><input type="hidden" name="csrf_token" value="<?= adminManagementEscape($csrfToken) ?>"><input type="hidden" name="action" value="change_password"><div class="am-modal-body"><div class="am-grid-2" style="grid-template-columns:1fr"><div class="am-field"><label for="current_password">Current password</label><div class="am-password-wrap"><input id="current_password" name="current_password" type="password" required autocomplete="current-password"><button class="am-password-toggle" type="button" aria-label="Show password"><svg viewBox="0 0 24 24"><path d="M2.5 12s3.5-6 9.5-6 9.5 6 9.5 6-3.5 6-9.5 6-9.5-6-9.5-6Z"/><circle cx="12" cy="12" r="2.5"/></svg></button></div></div><div class="am-field"><label for="new_password">New password</label><div class="am-password-wrap"><input id="new_password" name="new_password" type="password" minlength="10" required autocomplete="new-password" aria-describedby="passwordRules"><button class="am-password-toggle" type="button" aria-label="Show password"><svg viewBox="0 0 24 24"><path d="M2.5 12s3.5-6 9.5-6 9.5 6 9.5 6-3.5 6-9.5 6-9.5-6-9.5-6Z"/><circle cx="12" cy="12" r="2.5"/></svg></button></div><div class="am-password-meter"><span id="passwordMeter"></span></div><div class="am-password-rules" id="passwordRules"><span class="am-password-rule" data-password-rule="length">10+ characters</span><span class="am-password-rule" data-password-rule="upper">Uppercase letter</span><span class="am-password-rule" data-password-rule="lower">Lowercase letter</span><span class="am-password-rule" data-password-rule="number">Number</span></div></div><div class="am-field"><label for="confirm_password">Confirm new password</label><div class="am-password-wrap"><input id="confirm_password" name="confirm_password" type="password" minlength="10" required autocomplete="new-password" aria-describedby="passwordMatchHint"><button class="am-password-toggle" type="button" aria-label="Show password"><svg viewBox="0 0 24 24"><path d="M2.5 12s3.5-6 9.5-6 9.5 6 9.5 6-3.5 6-9.5 6-9.5-6-9.5-6Z"/><circle cx="12" cy="12" r="2.5"/></svg></button></div><div class="am-password-match" id="passwordMatchHint">Re-enter your new password.</div></div></div></div><footer class="am-modal-footer"><button class="am-button" type="button" data-close-modal>Cancel</button><button class="am-save-button" id="passwordSubmit" type="submit">Update password</button></footer></form></div></div>

<script>
(() => {
  document.querySelectorAll('[data-open-modal]').forEach(button => button.addEventListener('click', () => {
    const current = button.closest('.am-modal');
    if (current && button.dataset.openModal !== current.id) current.classList.remove('open');
    document.getElementById(button.dataset.openModal)?.classList.add('open');
  }));
  document.querySelectorAll('[data-close-modal]').forEach(button => button.addEventListener('click', () => button.closest('.am-modal')?.classList.remove('open')));
  document.querySelectorAll('.am-modal').forEach(modal => modal.addEventListener('click', event => { if (event.target === modal) modal.classList.remove('open'); }));
  document.addEventListener('keydown', event => { if (event.key === 'Escape') document.querySelectorAll('.am-modal.open').forEach(modal => modal.classList.remove('open')); });

  const bio = document.getElementById('bio');
  bio?.addEventListener('input', () => document.getElementById('bioCount').textContent = bio.value.length);
  const avatarInput = document.getElementById('profile_picture');
  avatarInput?.addEventListener('change', () => {
    const file = avatarInput.files?.[0]; if (!file) return;
    if (file.size > 2 * 1024 * 1024) { alert('Please choose an image no larger than 2 MB.'); avatarInput.value = ''; return; }
    const reader = new FileReader(); reader.onload = event => { document.getElementById('avatarPreview').innerHTML = `<img src="${event.target.result}" alt="Selected profile image">`; }; reader.readAsDataURL(file);
  });
  const passwordEyeOpen = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M2.5 12s3.5-6 9.5-6 9.5 6 9.5 6-3.5 6-9.5 6-9.5-6-9.5-6Z"/><circle cx="12" cy="12" r="2.5"/></svg>';
  const passwordEyeOff = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 3l18 18"/><path d="M10.6 6.2A10.7 10.7 0 0 1 12 6c6 0 9.5 6 9.5 6a17 17 0 0 1-2.4 3.1M6.1 6.1C3.7 7.9 2.5 12 2.5 12s3.5 6 9.5 6a10.7 10.7 0 0 0 3.4-.5"/><path d="M9.9 9.9a3 3 0 0 0 4.2 4.2"/></svg>';
  document.querySelectorAll('.am-password-toggle').forEach(button => button.addEventListener('click', () => {
    const input = button.parentElement.querySelector('input'); const showing = input.type === 'password'; input.type = showing ? 'text' : 'password'; button.innerHTML = showing ? passwordEyeOff : passwordEyeOpen; button.setAttribute('aria-pressed', showing ? 'true' : 'false'); button.setAttribute('aria-label', showing ? 'Hide password' : 'Show password');
  }));
  const password = document.getElementById('new_password');
  const confirmation = document.getElementById('confirm_password');
  const matchHint = document.getElementById('passwordMatchHint');
  const passwordRules = {
    length: value => value.length >= 10,
    upper: value => /[A-Z]/.test(value),
    lower: value => /[a-z]/.test(value),
    number: value => /\d/.test(value)
  };
  const updatePasswordFeedback = () => {
    const value = password?.value || '';
    let passed = 0;
    Object.entries(passwordRules).forEach(([name, test]) => {
      const valid = test(value);
      if (valid) passed++;
      document.querySelector(`[data-password-rule="${name}"]`)?.classList.toggle('valid', valid);
    });
    const meter = document.getElementById('passwordMeter');
    meter.style.width = `${passed * 25}%`;
    meter.style.background = passed < 2 ? '#c84454' : passed < 4 ? '#d39329' : '#2b8a68';
    password.setCustomValidity(passed === 4 || value === '' ? '' : 'Password must meet all four requirements.');
    if (confirmation.value === '') {
      matchHint.textContent = 'Re-enter your new password.';
      matchHint.className = 'am-password-match';
      confirmation.setCustomValidity('');
    } else {
      const matches = confirmation.value === value;
      matchHint.textContent = matches ? 'Passwords match.' : 'Passwords do not match.';
      matchHint.className = `am-password-match ${matches ? 'valid' : 'invalid'}`;
      confirmation.setCustomValidity(matches ? '' : 'Passwords do not match.');
    }
    return passed === 4;
  };
  password?.addEventListener('input', updatePasswordFeedback);
  confirmation?.addEventListener('input', updatePasswordFeedback);
  document.getElementById('passwordForm')?.addEventListener('submit', event => {
    const strongEnough = updatePasswordFeedback();
    if (!strongEnough || password.value !== confirmation.value) {
      event.preventDefault();
      (strongEnough ? confirmation : password).reportValidity();
      return;
    }
    const submit = document.getElementById('passwordSubmit');
    submit.disabled = true;
    submit.textContent = 'Updating...';
  });

  const form = document.getElementById('profileForm'); let dirty = false;
  form?.addEventListener('input', () => { dirty = true; }); form?.addEventListener('submit', () => { dirty = false; });
  window.addEventListener('beforeunload', event => { if (dirty) { event.preventDefault(); event.returnValue = ''; } });
})();
</script>
</body>
</html>
