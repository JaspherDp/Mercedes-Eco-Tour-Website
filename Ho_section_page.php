<?php
require_once __DIR__ . '/Ho_common.php';
require_once __DIR__ . '/php/activity_logger.php';
require_once __DIR__ . '/php/project_path_helper.php';

$sectionKey = $sectionKey ?? '';
$allowedSections = ['payments', 'reviews', 'profile'];
if (!in_array($sectionKey, $allowedSections, true)) {
    http_response_code(404);
    exit('Page not found.');
}

$hoAdmin = HoRequireHotelAdmin($pdo);
$hotelId = (int)$hoAdmin['hotel_resort_id'];
$propertyName = trim((string)($hoAdmin['property_name'] ?? ''));
$ownerName = $propertyName !== '' ? $propertyName . ' Admin' : (string)$hoAdmin['username'];
$hoActive = $sectionKey;
$hoPendingBadge = HoGetPendingCount($pdo, $hotelId);
$hoUnreadBadge = HoGetUnreadCount($pdo, $hotelId);
$hoNotifItems = HoGetNotificationItems($pdo, 8, $hotelId);

$sectionTitles = [
    'payments' => 'Payments & Transactions',
    'reviews' => 'Reviews',
    'profile' => 'Profile',
];
$hoTitle = $sectionTitles[$sectionKey];
$hoOwnerName = $ownerName;
$hoProfileHeaderActions = $sectionKey === 'profile';
if ($sectionKey === 'profile') {
    $hoTitle = 'Hotel Administrator Profile';
}

$hoProfileCsrf = '';
$hoProfileFlash = null;
$hoRecentActivity = [];
if ($sectionKey === 'profile') {
    if (empty($_SESSION['hotel_profile_csrf'])) {
        $_SESSION['hotel_profile_csrf'] = bin2hex(random_bytes(32));
    }
    $hoProfileCsrf = (string)$_SESSION['hotel_profile_csrf'];

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $submittedToken = (string)($_POST['csrf_token'] ?? '');
        if ($submittedToken === '' || !hash_equals($hoProfileCsrf, $submittedToken)) {
            $_SESSION['hotel_profile_flash'] = ['error', 'Your form session expired. Please try again.'];
            header('Location: Hoprofile.php');
            exit;
        }

        $profileAction = (string)($_POST['action'] ?? 'update_profile');
        $hotelAdminId = (int)$hoAdmin['hotel_admin_id'];
        $actorName = (string)($hoAdmin['full_name'] ?: $hoAdmin['username']);

        if ($profileAction === 'update_profile') {
            $fullName = mb_substr(trim((string)($_POST['full_name'] ?? '')), 0, 190);
            $username = mb_substr(trim((string)($_POST['username'] ?? '')), 0, 190);
            $phone = mb_substr(trim((string)($_POST['phone'] ?? '')), 0, 40);
            $jobTitle = mb_substr(trim((string)($_POST['job_title'] ?? '')), 0, 100);
            $bio = mb_substr(trim((string)($_POST['bio'] ?? '')), 0, 500);
            if ($fullName === '' || $username === '') {
                $_SESSION['hotel_profile_flash'] = ['error', 'Full name and login username are required.'];
                header('Location: Hoprofile.php'); exit;
            }
            $unique = $pdo->prepare('SELECT COUNT(*) FROM hotel_admin_accounts WHERE username = ? AND hotel_admin_id <> ?');
            $unique->execute([$username, $hotelAdminId]);
            if ((int)$unique->fetchColumn() > 0) {
                $_SESSION['hotel_profile_flash'] = ['error', 'That login username is already assigned to another account.'];
                header('Location: Hoprofile.php'); exit;
            }
            try {
                $pdo->beginTransaction();
                $stmt = $pdo->prepare('UPDATE hotel_admin_accounts SET full_name = ?, username = ? WHERE hotel_admin_id = ?');
                $stmt->execute([$fullName, $username, $hotelAdminId]);
                $stmt = $pdo->prepare('INSERT INTO hotel_admin_profile_details (hotel_admin_id, phone, job_title, bio) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE phone=VALUES(phone), job_title=VALUES(job_title), bio=VALUES(bio)');
                $stmt->execute([$hotelAdminId, $phone ?: null, $jobTitle ?: null, $bio ?: null]);
                $pdo->commit();
                $_SESSION['hotel_admin_name'] = $fullName;
                $_SESSION['hotel_admin_username'] = $username;
                logActivity($pdo, 'Hotel Owner', $hotelAdminId, $fullName, 'Updated Profile', 'Updated hotel administrator identity and contact details.', 'Hotel Administrator Profile', $hotelAdminId);
                $_SESSION['hotel_profile_flash'] = ['success', 'Your hotel administrator profile was updated.'];
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                error_log('Hotel profile update failed: ' . $e->getMessage());
                $_SESSION['hotel_profile_flash'] = ['error', 'Your profile could not be updated. Please try again.'];
            }
            header('Location: Hoprofile.php'); exit;
        }

        if ($profileAction === 'upload_avatar') {
            if (!isset($_FILES['profile_picture']) || $_FILES['profile_picture']['error'] !== UPLOAD_ERR_OK) {
                $_SESSION['hotel_profile_flash'] = ['error', 'Choose a valid image to upload.'];
                header('Location: Hoprofile.php'); exit;
            }
            $file = $_FILES['profile_picture'];
            if ((int)$file['size'] > 2 * 1024 * 1024) {
                $_SESSION['hotel_profile_flash'] = ['error', 'Profile images must be 2 MB or smaller.'];
                header('Location: Hoprofile.php'); exit;
            }
            $mime = (new finfo(FILEINFO_MIME_TYPE))->file((string)$file['tmp_name']);
            $extensions = ['image/jpeg'=>'jpg', 'image/png'=>'png', 'image/webp'=>'webp'];
            if (!isset($extensions[$mime])) {
                $_SESSION['hotel_profile_flash'] = ['error', 'Use a JPEG, PNG, or WebP image.'];
                header('Location: Hoprofile.php'); exit;
            }
            try {
                $uploadDirectory = ItourEnsureProjectDirectory('uploads/hotel_admin_profiles');
            } catch (Throwable $exception) {
                $_SESSION['hotel_profile_flash'] = ['error', 'The profile image folder is not writable.'];
                header('Location: Hoprofile.php'); exit;
            }
            $filename = 'hotel-admin-' . $hotelAdminId . '-' . bin2hex(random_bytes(8)) . '.' . $extensions[$mime];
            $absoluteTarget = $uploadDirectory . DIRECTORY_SEPARATOR . $filename;
            if (!move_uploaded_file((string)$file['tmp_name'], $absoluteTarget)) {
                $_SESSION['hotel_profile_flash'] = ['error', 'The profile image could not be saved.'];
                header('Location: Hoprofile.php'); exit;
            }
            $newPath = 'uploads/hotel_admin_profiles/' . $filename;
            try {
                $stmt = $pdo->prepare('INSERT INTO hotel_admin_profile_details (hotel_admin_id, profile_picture) VALUES (?, ?) ON DUPLICATE KEY UPDATE profile_picture=VALUES(profile_picture)');
                $stmt->execute([$hotelAdminId, $newPath]);
                $oldPath = (string)($hoAdmin['profile_picture'] ?? '');
                if (preg_match('#^uploads/hotel_admin_profiles/[^/]+$#', $oldPath)) {
                    $oldAbsolute = realpath(ItourProjectPath($oldPath));
                    $safeRoot = realpath($uploadDirectory);
                    if ($oldAbsolute && $safeRoot && str_starts_with(strtolower($oldAbsolute), strtolower($safeRoot . DIRECTORY_SEPARATOR)) && is_file($oldAbsolute)) unlink($oldAbsolute);
                }
                $_SESSION['hotel_admin_profile_picture'] = $newPath;
                logActivity($pdo, 'Hotel Owner', $hotelAdminId, $actorName, 'Updated Avatar', 'Changed the hotel administrator profile image.', 'Hotel Administrator Profile', $hotelAdminId);
                $_SESSION['hotel_profile_flash'] = ['success', 'Your profile image was updated.'];
            } catch (Throwable $e) {
                if (is_file($absoluteTarget)) unlink($absoluteTarget);
                $_SESSION['hotel_profile_flash'] = ['error', 'The image could not be attached to your profile.'];
            }
            header('Location: Hoprofile.php'); exit;
        }

        if ($profileAction === 'remove_avatar') {
            $stmt = $pdo->prepare('UPDATE hotel_admin_profile_details SET profile_picture = NULL WHERE hotel_admin_id = ?');
            $stmt->execute([$hotelAdminId]);
            $oldPath = (string)($hoAdmin['profile_picture'] ?? '');
            if (preg_match('#^uploads/hotel_admin_profiles/[^/]+$#', $oldPath)) {
                $oldAbsolute = realpath(ItourProjectPath($oldPath));
                $safeRoot = realpath(ItourProjectPath('uploads/hotel_admin_profiles'));
                if ($oldAbsolute && $safeRoot && str_starts_with(strtolower($oldAbsolute), strtolower($safeRoot . DIRECTORY_SEPARATOR)) && is_file($oldAbsolute)) unlink($oldAbsolute);
            }
            $_SESSION['hotel_admin_profile_picture'] = '';
            logActivity($pdo, 'Hotel Owner', $hotelAdminId, $actorName, 'Removed Avatar', 'Removed the hotel administrator profile image.', 'Hotel Administrator Profile', $hotelAdminId);
            $_SESSION['hotel_profile_flash'] = ['success', 'Your profile image was removed.'];
            header('Location: Hoprofile.php'); exit;
        }

        if ($profileAction === 'change_password') {
            $stmt = $pdo->prepare('SELECT password FROM hotel_admin_accounts WHERE hotel_admin_id = ?');
            $stmt->execute([$hotelAdminId]);
            $passwordHash = (string)$stmt->fetchColumn();
            $currentPassword = (string)($_POST['current_password'] ?? '');
            $newPassword = (string)($_POST['new_password'] ?? '');
            $confirmPassword = (string)($_POST['confirm_password'] ?? '');
            if (!password_verify($currentPassword, $passwordHash)) {
                $_SESSION['hotel_profile_flash'] = ['error', 'The current password you entered is incorrect.'];
            } elseif (strlen($newPassword) < 10 || !preg_match('/[A-Z]/',$newPassword) || !preg_match('/[a-z]/',$newPassword) || !preg_match('/\d/',$newPassword)) {
                $_SESSION['hotel_profile_flash'] = ['error', 'Use at least 10 characters with uppercase, lowercase, and a number.'];
            } elseif ($newPassword !== $confirmPassword) {
                $_SESSION['hotel_profile_flash'] = ['error', 'The new passwords do not match.'];
            } elseif (password_verify($newPassword, $passwordHash)) {
                $_SESSION['hotel_profile_flash'] = ['error', 'Choose a password different from your current password.'];
            } else {
                $stmt = $pdo->prepare('UPDATE hotel_admin_accounts SET password = ? WHERE hotel_admin_id = ?');
                $stmt->execute([password_hash($newPassword, PASSWORD_DEFAULT), $hotelAdminId]);
                session_regenerate_id(true);
                logActivity($pdo, 'Hotel Owner', $hotelAdminId, $actorName, 'Changed Password', 'Changed the hotel administrator account password.', 'Security', $hotelAdminId);
                $_SESSION['hotel_profile_flash'] = ['success', 'Your password was changed securely.'];
            }
            header('Location: Hoprofile.php'); exit;
        }
    }

    $hoProfileFlash = $_SESSION['hotel_profile_flash'] ?? null;
    unset($_SESSION['hotel_profile_flash']);
    try {
        $stmt = $pdo->prepare('SELECT action, module, description, created_at FROM admin_activity_logs WHERE actor_type = "Hotel Owner" AND actor_id = ? ORDER BY created_at DESC LIMIT 6');
        $stmt->execute([(int)$hoAdmin['hotel_admin_id']]);
        $hoRecentActivity = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {}
}

$hoDisplayName = trim((string)($hoAdmin['full_name'] ?: $hoAdmin['username']));
$hoNameParts = preg_split('/\s+/', $hoDisplayName) ?: [];
$hoInitials = strtoupper(substr((string)($hoNameParts[0] ?? 'H'), 0, 1) . (count($hoNameParts) > 1 ? substr((string)end($hoNameParts), 0, 1) : ''));
$hoAvatarUrl = trim((string)($hoAdmin['profile_picture'] ?? ''));
$hoCreatedAt = strtotime((string)($hoAdmin['account_created_at'] ?? '')) ?: time();
$hoUpdatedAt = strtotime((string)($hoAdmin['profile_updated_at'] ?? $hoAdmin['account_updated_at'] ?? '')) ?: null;
$hoCompletionFields = [$hoAdmin['full_name'], $hoAdmin['username'], $hoAdmin['phone'], $hoAdmin['job_title'], $hoAdmin['bio'], $hoAdmin['profile_picture']];
$hoProfileCompletion = (int)round((count(array_filter($hoCompletionFields, fn($value) => trim((string)$value) !== '')) / count($hoCompletionFields)) * 100);
$hoSecurityScore = min(100, 65 + ($hoAdmin['phone'] ? 10 : 0) + ($hoAdmin['profile_picture'] ? 10 : 0) + ($hoAdmin['bio'] ? 5 : 0) + 10);

$paymentSummary = ['total' => 0.0, 'collected' => 0.0, 'outstanding' => 0.0, 'paid_count' => 0];
$paymentRows = [];
$reviewRows = [];
$reviewSummary = ['review_count' => 0, 'average_rating' => 0];
if ($sectionKey === 'payments') {
    $summaryStmt = $pdo->prepare("SELECT COALESCE(SUM(total_amount),0) total, COALESCE(SUM(amount_paid),0) collected, COALESCE(SUM(remaining_balance),0) outstanding, SUM(CASE WHEN LOWER(payment_status)='paid' THEN 1 ELSE 0 END) paid_count FROM hotel_room_bookings WHERE hotel_resort_id=? AND booking_status <> 'cancelled'");
    $summaryStmt->execute([$hotelId]);
    $paymentSummary = $summaryStmt->fetch(PDO::FETCH_ASSOC) ?: $paymentSummary;
    $rowsStmt = $pdo->prepare("SELECT booking_reference, first_name, last_name, total_amount, amount_paid, remaining_balance, payment_status, created_at FROM hotel_room_bookings WHERE hotel_resort_id=? ORDER BY created_at DESC LIMIT 25");
    $rowsStmt->execute([$hotelId]);
    $paymentRows = $rowsStmt->fetchAll(PDO::FETCH_ASSOC);
}
if ($sectionKey === 'reviews' && HoTableExists($pdo, 'hotel_resort_reviews')) {
    $reviewSummaryStmt = $pdo->prepare("SELECT COUNT(*) review_count, COALESCE(AVG(rating),0) average_rating FROM hotel_resort_reviews WHERE hotel_resort_id=?");
    $reviewSummaryStmt->execute([$hotelId]);
    $reviewSummary = $reviewSummaryStmt->fetch(PDO::FETCH_ASSOC) ?: $reviewSummary;
    $reviewsStmt = $pdo->prepare("SELECT reviewer_name, rating, review_message, created_at FROM hotel_resort_reviews WHERE hotel_resort_id=? ORDER BY created_at DESC LIMIT 25");
    $reviewsStmt->execute([$hotelId]);
    $reviewRows = $reviewsStmt->fetchAll(PDO::FETCH_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title><?= htmlspecialchars($hoTitle) ?> | Hotel Admin</title>
  <link rel="icon" type="image/png" href="img/newlogo.png" />
  <link rel="stylesheet" href="styles/Ho_panel.css?v=notifications-4" />
  <?php if ($sectionKey === 'profile'): ?><link rel="stylesheet" href="styles/admin_management.css?v=5" /><link rel="stylesheet" href="styles/Ho_profile.css?v=5" /><?php endif; ?>
  <style>
    .ho-section-stack{display:grid;gap:18px}.ho-section-intro{padding:22px 24px;border:1px solid var(--ho-border);border-radius:16px;background:linear-gradient(135deg,#fff 0%,#f3faf7 100%);box-shadow:var(--ho-shadow)}
    .ho-section-intro span{display:block;margin-bottom:6px;color:var(--ho-primary);font-size:11px;font-weight:800;letter-spacing:.11em;text-transform:uppercase}.ho-section-intro h2{margin:0;color:#19352d;font-size:22px}.ho-section-intro p{max-width:680px;margin:7px 0 0;color:var(--ho-muted);font-size:13px;line-height:1.6}
    .ho-section-metrics{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px}.ho-section-metric{padding:18px;border:1px solid var(--ho-border);border-radius:14px;background:#fff;box-shadow:var(--ho-shadow)}.ho-section-metric small{color:var(--ho-muted);font-weight:600}.ho-section-metric strong{display:block;margin-top:9px;color:#173a30;font-size:22px}
    .ho-section-panel{overflow:hidden;border:1px solid var(--ho-border);border-radius:16px;background:#fff;box-shadow:var(--ho-shadow)}.ho-section-panel-head{padding:17px 20px;border-bottom:1px solid #e8f0ed}.ho-section-panel-head h3{margin:0;font-size:15px}.ho-section-table{width:100%;border-collapse:collapse;font-size:12px}.ho-section-table th,.ho-section-table td{padding:13px 18px;border-bottom:1px solid #edf3f0;text-align:left}.ho-section-table th{color:#63756e;background:#f8fbfa;font-size:10px;letter-spacing:.07em;text-transform:uppercase}.ho-section-table td strong{color:#1d3c33}.ho-payment-status{display:inline-flex;padding:4px 8px;border-radius:999px;background:#eef3f1;color:#52665f;font-size:10px;font-weight:800;text-transform:capitalize}.ho-payment-status.paid{background:#e2f6ec;color:#176b4b}.ho-payment-status.partial,.ho-payment-status.partially_paid{background:#fff2d8;color:#8a5a00}.ho-empty-state{display:grid;place-items:center;min-height:260px;padding:36px;text-align:center}.ho-empty-icon{display:grid;place-items:center;width:58px;height:58px;margin-bottom:14px;border-radius:18px;background:#e8f5ef;color:#26745d}.ho-empty-icon svg{width:26px;height:26px;fill:none;stroke:currentColor;stroke-width:1.8}.ho-empty-state h3{margin:0;color:#1e3c34}.ho-empty-state p{max-width:480px;margin:8px 0 0;color:var(--ho-muted);font-size:13px;line-height:1.6}.ho-profile-grid{display:grid;grid-template-columns:180px 1fr;gap:24px;padding:24px}.ho-profile-identity{display:grid;align-content:center;justify-items:center;text-align:center}.ho-profile-avatar{display:grid;place-items:center;width:82px;height:82px;border-radius:50%;background:linear-gradient(135deg,#2b7a66,#174c3d);color:#fff;font-size:28px;font-weight:800}.ho-profile-identity strong{margin-top:12px}.ho-profile-identity small{margin-top:3px;color:var(--ho-muted)}.ho-profile-details{display:grid;grid-template-columns:1fr 1fr;gap:14px}.ho-profile-field{padding:14px 16px;border:1px solid #e3ece8;border-radius:12px;background:#fbfdfc}.ho-profile-field small{display:block;margin-bottom:5px;color:var(--ho-muted);font-size:10px;font-weight:700;letter-spacing:.06em;text-transform:uppercase}.ho-profile-field strong{color:#213c34;font-size:13px;word-break:break-word}
    .ho-review-summary{display:flex;align-items:center;gap:18px;padding:18px 20px;border-bottom:1px solid #e8f0ed}.ho-review-score{font-size:30px;font-weight:800;color:#1f624f}.ho-review-score small{font-size:13px;color:#718078}.ho-review-card{padding:18px 20px;border-bottom:1px solid #edf3f0}.ho-review-card:last-child{border-bottom:0}.ho-review-card-head{display:flex;align-items:center;justify-content:space-between;gap:12px}.ho-review-card-head strong{color:#1e3c34;font-size:13px}.ho-review-stars{color:#d89416;font-size:13px;font-weight:800}.ho-review-card p{margin:8px 0 5px;color:#435851;font-size:13px;line-height:1.6}.ho-review-card small{color:#82918b;font-size:11px}
    @media(max-width:900px){.ho-section-metrics{grid-template-columns:1fr 1fr}.ho-profile-grid{grid-template-columns:1fr}.ho-profile-details{grid-template-columns:1fr}}@media(max-width:620px){.ho-section-metrics{grid-template-columns:1fr}.ho-section-panel{overflow-x:auto}}
  </style>
</head>
<body class="ho-body">
<div class="ho-layout">
  <?php include __DIR__ . '/Ho_sidebar.php'; ?>
  <main class="ho-main<?= $sectionKey === 'profile' ? ' admin-management-main admin-profile-main ho-profile-main' : '' ?>">
    <?php include __DIR__ . '/Ho_header.php'; ?>
    <section class="ho-content ho-section-stack<?= $sectionKey === 'profile' ? ' ho-profile-content' : '' ?>">
      <?php if ($sectionKey === 'payments'): ?>
        <div class="ho-section-intro"><span>Operations</span><h2>Payment overview</h2><p>Track reservation value, collected payments, and outstanding balances for <?= htmlspecialchars($propertyName ?: 'your property') ?>.</p></div>
        <div class="ho-section-metrics">
          <article class="ho-section-metric"><small>Total booking value</small><strong>₱<?= number_format((float)$paymentSummary['total'], 2) ?></strong></article>
          <article class="ho-section-metric"><small>Collected</small><strong>₱<?= number_format((float)$paymentSummary['collected'], 2) ?></strong></article>
          <article class="ho-section-metric"><small>Outstanding</small><strong>₱<?= number_format((float)$paymentSummary['outstanding'], 2) ?></strong></article>
          <article class="ho-section-metric"><small>Fully paid bookings</small><strong><?= (int)$paymentSummary['paid_count'] ?></strong></article>
        </div>
        <div class="ho-section-panel"><div class="ho-section-panel-head"><h3>Recent transactions</h3></div>
          <?php if ($paymentRows): ?><table class="ho-section-table"><thead><tr><th>Booking</th><th>Guest</th><th>Total</th><th>Paid</th><th>Balance</th><th>Status</th></tr></thead><tbody>
          <?php foreach ($paymentRows as $row): $status = strtolower((string)($row['payment_status'] ?? 'unpaid')); ?><tr><td><strong><?= htmlspecialchars((string)$row['booking_reference']) ?></strong></td><td><?= htmlspecialchars(trim((string)$row['first_name'].' '.(string)$row['last_name'])) ?></td><td>₱<?= number_format((float)$row['total_amount'],2) ?></td><td>₱<?= number_format((float)$row['amount_paid'],2) ?></td><td>₱<?= number_format((float)$row['remaining_balance'],2) ?></td><td><span class="ho-payment-status <?= htmlspecialchars($status) ?>"><?= htmlspecialchars(str_replace('_',' ',$status)) ?></span></td></tr><?php endforeach; ?>
          </tbody></table><?php else: ?><div class="ho-empty-state"><h3>No transactions yet</h3><p>Payment activity will appear here as guests make reservations.</p></div><?php endif; ?>
        </div>
      <?php elseif ($sectionKey === 'reviews'): ?>
        <div class="ho-section-intro"><span>Guest Experience</span><h2>Guest reviews</h2><p>Feedback for <?= htmlspecialchars($propertyName ?: 'your property') ?> will be collected here, giving your team one place to monitor the guest experience.</p></div>
        <div class="ho-section-panel">
          <?php if ($reviewRows): ?>
            <div class="ho-review-summary"><div class="ho-review-score"><?= number_format((float)$reviewSummary['average_rating'], 1) ?> <small>/ 5</small></div><div><strong><?= (int)$reviewSummary['review_count'] ?> guest review<?= (int)$reviewSummary['review_count'] === 1 ? '' : 's' ?></strong><p style="margin:3px 0 0;color:var(--ho-muted);font-size:12px">Average rating across submitted stays</p></div></div>
            <div><?php foreach ($reviewRows as $review): ?><article class="ho-review-card"><div class="ho-review-card-head"><strong><?= htmlspecialchars((string)($review['reviewer_name'] ?: 'Guest')) ?></strong><span class="ho-review-stars">★ <?= number_format((float)$review['rating'], 1) ?></span></div><p><?= nl2br(htmlspecialchars((string)$review['review_message'])) ?></p><small><?= htmlspecialchars(date('F j, Y', strtotime((string)$review['created_at']))) ?></small></article><?php endforeach; ?></div>
          <?php else: ?>
            <div class="ho-empty-state"><div class="ho-empty-icon"><svg viewBox="0 0 24 24"><path d="m12 3 2.8 5.7 6.2.9-4.5 4.4 1.1 6.2-5.6-3-5.6 3 1.1-6.2L3 9.6l6.2-.9L12 3Z"/></svg></div><h3>No property reviews yet</h3><p>New guest ratings and written feedback will appear here when they are submitted.</p></div>
          <?php endif; ?>
        </div>
      <?php else: ?>
        <?php if ($hoProfileFlash): ?><div class="am-flash ho-profile-flash <?= ($hoProfileFlash[0] ?? '') === 'error' ? 'error' : '' ?>"><span><?= htmlspecialchars((string)($hoProfileFlash[1] ?? '')) ?></span><button type="button" aria-label="Dismiss" onclick="this.parentElement.remove()">&times;</button></div><?php endif; ?>

        <section class="am-profile-hero">
          <div class="am-profile-identity"><div class="am-avatar"><?php if ($hoAvatarUrl): ?><img src="<?= htmlspecialchars($hoAvatarUrl) ?>" alt="<?= htmlspecialchars($hoDisplayName) ?> profile image"><?php else: ?><?= htmlspecialchars($hoInitials) ?><?php endif; ?></div><div><h3><?= htmlspecialchars($hoDisplayName) ?></h3><p><?= htmlspecialchars((string)$hoAdmin['username']) ?></p><span class="am-role-pill">● Hotel property administrator</span></div></div>
          <div class="am-profile-hero-meta"><div><strong><?= $hoProfileCompletion ?>%</strong><span>Profile complete</span></div><div><strong><?= date('M Y',$hoCreatedAt) ?></strong><span>Member since</span></div><div><strong><?= htmlspecialchars(ucfirst((string)$hoAdmin['status'])) ?></strong><span>Account status</span></div></div>
        </section>

        <div class="am-profile-grid">
          <div>
            <form id="hoProfileForm" method="post" action="Hoprofile.php"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($hoProfileCsrf) ?>"><input type="hidden" name="action" value="update_profile">
              <article class="am-card"><header class="am-card-header"><div class="am-card-heading"><span class="am-card-icon"><svg viewBox="0 0 24 24"><circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/></svg></span><div><h3>Personal information</h3><p>Your identity and role within the assigned property</p></div></div><button class="am-button am-change-photo" type="button" data-open-modal="hoAvatarModal"><svg viewBox="0 0 24 24"><rect x="3" y="6" width="18" height="14" rx="2"/><path d="m8 6 1.5-2h5L16 6"/><circle cx="12" cy="13" r="3.5"/></svg>Change photo</button></header><div class="am-card-body am-grid-2">
                <div class="am-field"><label for="ho_full_name">Full name</label><input id="ho_full_name" name="full_name" maxlength="190" required autocomplete="name" value="<?= htmlspecialchars((string)$hoAdmin['full_name']) ?>"></div>
                <div class="am-field"><label for="ho_job_title">Position / job title <span>(optional)</span></label><input id="ho_job_title" name="job_title" maxlength="100" value="<?= htmlspecialchars((string)$hoAdmin['job_title']) ?>" placeholder="Property Manager"></div>
                <div class="am-field full"><label for="ho_bio">Professional bio <span>(optional)</span></label><textarea id="ho_bio" name="bio" maxlength="500" placeholder="Briefly describe your role and responsibilities..."><?= htmlspecialchars((string)$hoAdmin['bio']) ?></textarea><div class="am-field-help"><span id="hoBioCount"><?= mb_strlen((string)$hoAdmin['bio']) ?></span>/500 characters</div></div>
              </div></article>
              <article class="am-card"><header class="am-card-header"><div class="am-card-heading"><span class="am-card-icon"><svg viewBox="0 0 24 24"><path d="M4 5h16v14H4zM8 9h8M8 13h5"/></svg></span><div><h3>Contact & login details</h3><p>Information used to identify and contact this property administrator</p></div></div></header><div class="am-card-body am-grid-2">
                <div class="am-field"><label for="ho_username">Login username or email</label><input id="ho_username" name="username" maxlength="190" required autocomplete="username" value="<?= htmlspecialchars((string)$hoAdmin['username']) ?>"><div class="am-field-help">This will be used on your next sign-in.</div></div>
                <div class="am-field"><label for="ho_phone">Phone number <span>(optional)</span></label><input id="ho_phone" name="phone" maxlength="40" type="tel" autocomplete="tel" value="<?= htmlspecialchars((string)$hoAdmin['phone']) ?>" placeholder="+63 9XX XXX XXXX"></div>
                <div class="am-field"><label>Account identifier</label><input value="HTL-<?= str_pad((string)$hoAdmin['hotel_admin_id'],5,'0',STR_PAD_LEFT) ?>" readonly></div>
                <div class="am-field"><label>Assigned property ID</label><input value="PROP-<?= str_pad((string)$hotelId,5,'0',STR_PAD_LEFT) ?>" readonly></div>
              </div><footer class="am-card-footer"><span>Property assignment can only be changed by the website administrator.</span><span>Account #<?= (int)$hoAdmin['hotel_admin_id'] ?></span></footer></article>
            </form>
          </div>

          <aside>
            <article class="am-card"><header class="am-card-header"><div class="am-card-heading"><span class="am-card-icon"><svg viewBox="0 0 24 24"><path d="M12 3 4 6v6c0 5 3.4 8 8 9 4.6-1 8-4 8-9V6l-8-3Z"/><path d="m8.5 12 2.3 2.3 4.7-4.8"/></svg></span><div><h3>Account security</h3><p>Security health at a glance</p></div></div></header><div class="am-card-body"><div class="am-security-score"><div class="am-score-ring" style="--score:<?= $hoSecurityScore ?>"><strong><?= $hoSecurityScore ?>%</strong></div><div><h4><?= $hoSecurityScore >= 90 ? 'Strong account setup' : 'Complete your profile' ?></h4><p>Use a unique password and keep your property contact information current.</p></div></div><div class="am-quick-list" style="margin-top:15px"><div class="am-quick-row"><div><span class="am-quick-icon"><svg viewBox="0 0 24 24"><rect x="4" y="10" width="16" height="11" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/></svg></span>Password</div><span>Protected</span></div><div class="am-quick-row"><div><span class="am-quick-icon"><svg viewBox="0 0 24 24"><path d="M7 3h10v18H7zM10 18h4"/></svg></span>Phone</div><span><?= $hoAdmin['phone'] ? 'Configured' : 'Not added' ?></span></div><div class="am-quick-row"><div><span class="am-quick-icon"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="m8 12 2.5 2.5L16 9"/></svg></span>Status</div><span><?= htmlspecialchars(ucfirst((string)$hoAdmin['status'])) ?></span></div></div><button class="am-button ho-security-password" type="button" data-open-modal="hoPasswordModal"><svg viewBox="0 0 24 24"><rect x="4" y="10" width="16" height="11" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/></svg>Change password</button></div></article>

            <article class="am-card"><header class="am-card-header"><div class="am-card-heading"><span class="am-card-icon"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg></span><div><h3>Current session</h3><p>Your present property-console access</p></div></div></header><div class="am-card-body am-quick-list"><div class="am-quick-row"><div>Signed in as</div><span><?= htmlspecialchars((string)$hoAdmin['username']) ?></span></div><div class="am-quick-row"><div>IP address</div><span><?= htmlspecialchars((string)($_SERVER['REMOTE_ADDR'] ?? 'Unknown')) ?></span></div><div class="am-quick-row"><div>Profile updated</div><span><?= $hoUpdatedAt ? date('M d, Y',$hoUpdatedAt) : 'Not yet' ?></span></div></div></article>
          </aside>
        </div>

        <article class="am-property-card am-card"><div class="am-property-banner"<?php if (!empty($hoAdmin['property_image'])): ?> style="--property-image:url('<?= htmlspecialchars((string)$hoAdmin['property_image'],ENT_QUOTES) ?>')"<?php endif; ?>><div><small>Assigned property</small><strong><?= htmlspecialchars($propertyName ?: 'Assigned Property') ?></strong><span>Managed through this hotel administrator account</span></div></div><div class="am-property-details"><div class="am-property-detail"><small>Location</small><strong><?= htmlspecialchars((string)($hoAdmin['property_island'] ?: 'Mercedes')) ?></strong></div><div class="am-property-detail"><small>Property type</small><strong><?= htmlspecialchars(ucfirst((string)($hoAdmin['property_type'] ?: 'Accommodation'))) ?></strong></div><div class="am-property-detail"><small>Listing status</small><strong class="am-property-status"><?= htmlspecialchars(ucfirst((string)($hoAdmin['property_status'] ?: 'Active'))) ?></strong></div></div></article>

        <article class="am-card am-activity-wide"><header class="am-card-header"><div class="am-card-heading"><span class="am-card-icon"><svg viewBox="0 0 24 24"><path d="M3 12a9 9 0 1 0 3-6.7L3 8"/><path d="M3 3v5h5M12 7v5l3 2"/></svg></span><div><h3>Recent activity</h3><p>Your latest account and security events</p></div></div></header><div class="am-card-body"><?php if (!$hoRecentActivity): ?><div class="am-empty">No account activity has been recorded yet.</div><?php else: ?><div class="am-activity-list"><?php foreach ($hoRecentActivity as $activity): ?><div class="am-activity-item"><span class="am-activity-dot"></span><div><strong><?= htmlspecialchars((string)$activity['action']) ?> · <?= htmlspecialchars((string)$activity['module']) ?></strong><p><?= htmlspecialchars((string)$activity['description']) ?><br><?= date('M d, Y · h:i A',strtotime((string)$activity['created_at'])) ?></p></div></div><?php endforeach; ?></div><?php endif; ?></div></article>

        <div class="am-modal" id="hoAvatarModal" role="dialog" aria-modal="true" aria-labelledby="hoAvatarTitle"><div class="am-modal-dialog"><header class="am-modal-header"><h3 id="hoAvatarTitle">Update profile image</h3><button class="am-modal-close" type="button" data-close-modal aria-label="Close">&times;</button></header><form method="post" enctype="multipart/form-data" action="Hoprofile.php"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($hoProfileCsrf) ?>"><input type="hidden" name="action" value="upload_avatar"><div class="am-modal-body"><div class="am-avatar-upload"><div class="am-avatar-preview" id="hoAvatarPreview"><?php if ($hoAvatarUrl): ?><img src="<?= htmlspecialchars($hoAvatarUrl) ?>" alt="Current profile image"><?php else: ?><?= htmlspecialchars($hoInitials) ?><?php endif; ?></div><label class="am-button" for="ho_profile_picture">Choose image</label><input id="ho_profile_picture" name="profile_picture" type="file" accept="image/jpeg,image/png,image/webp" hidden required><p>JPEG, PNG, or WebP · maximum 2 MB</p></div></div><footer class="am-modal-footer"><?php if ($hoAvatarUrl): ?><button class="am-button danger" type="button" data-open-modal="hoRemoveAvatarModal">Remove photo</button><?php endif; ?><button class="am-button" type="button" data-close-modal>Cancel</button><button class="am-save-button" type="submit">Upload photo</button></footer></form></div></div>
        <div class="am-modal" id="hoRemoveAvatarModal" role="dialog" aria-modal="true" aria-labelledby="hoRemoveAvatarTitle"><div class="am-modal-dialog"><header class="am-modal-header"><h3 id="hoRemoveAvatarTitle">Remove profile image?</h3><button class="am-modal-close" type="button" data-close-modal aria-label="Close">&times;</button></header><div class="am-modal-body"><div class="am-callout warning">Your initials will be displayed in place of your profile image.</div></div><footer class="am-modal-footer"><button class="am-button" type="button" data-close-modal>Cancel</button><form method="post" action="Hoprofile.php"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($hoProfileCsrf) ?>"><input type="hidden" name="action" value="remove_avatar"><button class="am-button danger" type="submit">Remove photo</button></form></footer></div></div>
        <div class="am-modal" id="hoPasswordModal" role="dialog" aria-modal="true" aria-labelledby="hoPasswordTitle"><div class="am-modal-dialog"><header class="am-modal-header"><div class="am-card-heading"><span class="am-card-icon"><svg viewBox="0 0 24 24"><rect x="4" y="10" width="16" height="11" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/></svg></span><div><h3 id="hoPasswordTitle">Change account password</h3><p>Choose a strong password you do not use elsewhere</p></div></div><button class="am-modal-close" type="button" data-close-modal aria-label="Close">&times;</button></header><form id="hoPasswordForm" method="post" action="Hoprofile.php"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($hoProfileCsrf) ?>"><input type="hidden" name="action" value="change_password"><div class="am-modal-body"><div class="am-grid-2" style="grid-template-columns:1fr"><div class="am-field"><label for="ho_current_password">Current password</label><div class="am-password-wrap"><input id="ho_current_password" name="current_password" type="password" required autocomplete="current-password"><button class="am-password-toggle" type="button" aria-label="Show password"><svg viewBox="0 0 24 24"><path d="M2.5 12s3.5-6 9.5-6 9.5 6 9.5 6-3.5 6-9.5 6-9.5-6-9.5-6Z"/><circle cx="12" cy="12" r="2.5"/></svg></button></div></div><div class="am-field"><label for="ho_new_password">New password</label><div class="am-password-wrap"><input id="ho_new_password" name="new_password" type="password" minlength="10" required autocomplete="new-password"><button class="am-password-toggle" type="button" aria-label="Show password"><svg viewBox="0 0 24 24"><path d="M2.5 12s3.5-6 9.5-6 9.5 6 9.5 6-3.5 6-9.5 6-9.5-6-9.5-6Z"/><circle cx="12" cy="12" r="2.5"/></svg></button></div><div class="am-password-meter"><span id="hoPasswordMeter"></span></div><div class="am-password-rules"><span class="am-password-rule" data-ho-rule="length">10+ characters</span><span class="am-password-rule" data-ho-rule="upper">Uppercase letter</span><span class="am-password-rule" data-ho-rule="lower">Lowercase letter</span><span class="am-password-rule" data-ho-rule="number">Number</span></div></div><div class="am-field"><label for="ho_confirm_password">Confirm new password</label><div class="am-password-wrap"><input id="ho_confirm_password" name="confirm_password" type="password" minlength="10" required autocomplete="new-password"><button class="am-password-toggle" type="button" aria-label="Show password"><svg viewBox="0 0 24 24"><path d="M2.5 12s3.5-6 9.5-6 9.5 6 9.5 6-3.5 6-9.5 6-9.5-6-9.5-6Z"/><circle cx="12" cy="12" r="2.5"/></svg></button></div><div class="am-password-match" id="hoPasswordMatch">Re-enter your new password.</div></div></div></div><footer class="am-modal-footer"><button class="am-button" type="button" data-close-modal>Cancel</button><button class="am-save-button" id="hoPasswordSubmit" type="submit">Update password</button></footer></form></div></div>
      <?php endif; ?>
    </section>
    <?php include __DIR__ . '/Ho_footer.php'; ?>
  </main>
</div>
<?php if ($sectionKey === 'profile'): ?>
<script>
(() => {
  const closeModal = modal => {
    modal?.classList.remove('open');
    if (modal?.id === 'hoPasswordModal') {
      document.getElementById('hoPasswordForm')?.reset();
      document.querySelectorAll('[data-ho-rule]').forEach(rule => rule.classList.remove('valid'));
      const meter = document.getElementById('hoPasswordMeter'); if (meter) meter.style.width = '0';
      const hint = document.getElementById('hoPasswordMatch'); if (hint) { hint.textContent = 'Re-enter your new password.'; hint.className = 'am-password-match'; }
    }
  };
  document.querySelectorAll('[data-open-modal]').forEach(button => button.addEventListener('click', () => {
    const current = button.closest('.am-modal');
    if (current && current.id !== button.dataset.openModal) closeModal(current);
    const modal = document.getElementById(button.dataset.openModal);
    modal?.classList.add('open');
    setTimeout(() => modal?.querySelector('input:not([type="hidden"]),button')?.focus(), 30);
  }));
  document.querySelectorAll('[data-close-modal]').forEach(button => button.addEventListener('click', () => closeModal(button.closest('.am-modal'))));
  document.querySelectorAll('.am-modal').forEach(modal => modal.addEventListener('click', event => { if (event.target === modal) closeModal(modal); }));
  document.addEventListener('keydown', event => { if (event.key === 'Escape') document.querySelectorAll('.am-modal.open').forEach(closeModal); });

  const bio = document.getElementById('ho_bio');
  bio?.addEventListener('input', () => document.getElementById('hoBioCount').textContent = bio.value.length);
  const avatarInput = document.getElementById('ho_profile_picture');
  avatarInput?.addEventListener('change', () => {
    const file = avatarInput.files?.[0]; if (!file) return;
    if (file.size > 2 * 1024 * 1024) { alert('Please choose an image no larger than 2 MB.'); avatarInput.value = ''; return; }
    const reader = new FileReader(); reader.onload = event => { document.getElementById('hoAvatarPreview').innerHTML = `<img src="${event.target.result}" alt="Selected profile image">`; }; reader.readAsDataURL(file);
  });
  const passwordEyeOpen = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M2.5 12s3.5-6 9.5-6 9.5 6 9.5 6-3.5 6-9.5 6-9.5-6-9.5-6Z"/><circle cx="12" cy="12" r="2.5"/></svg>';
  const passwordEyeOff = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 3l18 18"/><path d="M10.6 6.2A10.7 10.7 0 0 1 12 6c6 0 9.5 6 9.5 6a17 17 0 0 1-2.4 3.1M6.1 6.1C3.7 7.9 2.5 12 2.5 12s3.5 6 9.5 6a10.7 10.7 0 0 0 3.4-.5"/><path d="M9.9 9.9a3 3 0 0 0 4.2 4.2"/></svg>';
  document.querySelectorAll('.am-password-toggle').forEach(button => button.addEventListener('click', () => {
    const input = button.parentElement.querySelector('input'); const showing = input.type === 'password'; input.type = showing ? 'text' : 'password'; button.innerHTML = showing ? passwordEyeOff : passwordEyeOpen; button.setAttribute('aria-pressed', showing ? 'true' : 'false'); button.setAttribute('aria-label', showing ? 'Hide password' : 'Show password');
  }));

  const password = document.getElementById('ho_new_password');
  const confirmation = document.getElementById('ho_confirm_password');
  const matchHint = document.getElementById('hoPasswordMatch');
  const rules = {length:value=>value.length>=10,upper:value=>/[A-Z]/.test(value),lower:value=>/[a-z]/.test(value),number:value=>/\d/.test(value)};
  const updatePassword = () => {
    const value = password.value; let passed = 0;
    Object.entries(rules).forEach(([name,test]) => { const valid=test(value); if(valid) passed++; document.querySelector(`[data-ho-rule="${name}"]`)?.classList.toggle('valid',valid); });
    const meter=document.getElementById('hoPasswordMeter'); meter.style.width=`${passed*25}%`; meter.style.background=passed<2?'#c84454':passed<4?'#d39329':'#2b8a68';
    password.setCustomValidity(passed===4||value===''?'':'Password must meet all four requirements.');
    if(confirmation.value===''){matchHint.textContent='Re-enter your new password.';matchHint.className='am-password-match';confirmation.setCustomValidity('');}
    else{const matches=confirmation.value===value;matchHint.textContent=matches?'Passwords match.':'Passwords do not match.';matchHint.className=`am-password-match ${matches?'valid':'invalid'}`;confirmation.setCustomValidity(matches?'':'Passwords do not match.');}
    return passed===4;
  };
  password?.addEventListener('input',updatePassword); confirmation?.addEventListener('input',updatePassword);
  document.getElementById('hoPasswordForm')?.addEventListener('submit',event=>{const strong=updatePassword();if(!strong||password.value!==confirmation.value){event.preventDefault();(strong?confirmation:password).reportValidity();return;}const button=document.getElementById('hoPasswordSubmit');button.disabled=true;button.textContent='Updating...';});

  const profileForm = document.getElementById('hoProfileForm'); let dirty = false;
  profileForm?.addEventListener('input',()=>{dirty=true;}); profileForm?.addEventListener('submit',()=>{dirty=false;});
  window.addEventListener('beforeunload',event=>{if(dirty){event.preventDefault();event.returnValue='';}});
})();
</script>
<?php endif; ?>
</body>
</html>
