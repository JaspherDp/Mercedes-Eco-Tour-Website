<?php
chdir(__DIR__ . '/..');
if (session_status() === PHP_SESSION_NONE) {
require_once __DIR__ . '/../php/session_security.php';
AppSessionStart();
}
require_once 'php/db_connection.php';
require_once __DIR__ . '/../php/activity_logger.php';
require_once __DIR__ . '/../php/admin_auth_helper.php';
include 'php/alert.php';

if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    AppDestroySession();
    header('Location: ' . AdminLoginUrl('adhomepage.php'));
    exit;
}

AdminRequireLogin();

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

if (empty($_SESSION['operator_csrf_token'])) {
    $_SESSION['operator_csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = (string)$_SESSION['operator_csrf_token'];

function operatorRequestIsValid(string $token): bool
{
    return $token !== '' && hash_equals((string)($_SESSION['operator_csrf_token'] ?? ''), $token);
}

function resolveOperatorProfileImage(?string $value): string
{
    $value = trim((string)$value);
    if ($value === '') {
        return 'img/profileicon.png';
    }
    if (preg_match('~^https?://~i', $value) || strpos($value, '//') === 0) {
        return $value;
    }

    $clean = ltrim(str_replace('\\', '/', $value), '/');
    $base = basename($clean);
    foreach ([$clean, 'uploads/profile/' . $base, 'img/' . $base] as $candidate) {
        if (is_file(__DIR__ . '/../' . str_replace('/', DIRECTORY_SEPARATOR, $candidate))) {
            return $candidate;
        }
    }
    return 'img/profileicon.png';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['ajax_action'] ?? '') === 'reset_password') {
    header('Content-Type: application/json; charset=utf-8');
    if (!operatorRequestIsValid((string)($_POST['csrf_token'] ?? ''))) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Your session token expired. Refresh the page and try again.']);
        exit;
    }

    $operatorId = (int)($_POST['operator_id'] ?? 0);
    $newPassword = (string)($_POST['new_password'] ?? '');
    $confirmation = (string)($_POST['confirm_password'] ?? '');
    if ($operatorId < 1) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'Select a valid operator account.']);
        exit;
    }
    if (strlen($newPassword) < 8) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'The new password must be at least 8 characters.']);
        exit;
    }
    if ($newPassword !== $confirmation) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'Password confirmation does not match.']);
        exit;
    }

    try {
        $operatorStmt = $pdo->prepare('SELECT fullname FROM operators WHERE operator_id = ? LIMIT 1');
        $operatorStmt->execute([$operatorId]);
        $operatorName = $operatorStmt->fetchColumn();
        if ($operatorName === false) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'The operator account no longer exists.']);
            exit;
        }

        $passwordStmt = $pdo->prepare('UPDATE operators SET password = ?, updated_at = NOW() WHERE operator_id = ?');
        $passwordStmt->execute([password_hash($newPassword, PASSWORD_DEFAULT), $operatorId]);
        logActivity(
            $pdo,
            'Admin',
            (int)($_SESSION['admin_id'] ?? 0),
            (string)($_SESSION['admin_name'] ?? 'Administrator'),
            'Operator Password Reset',
            'Reset the portal password for tour operator #' . $operatorId . '.',
            'Operators',
            $operatorId
        );
        echo json_encode(['success' => true, 'message' => 'The operator password was changed successfully.']);
    } catch (Throwable $exception) {
        error_log('OPERATOR PASSWORD RESET ERROR: ' . $exception->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'The password could not be changed right now.']);
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['ajax_action'] ?? '') === 'toggle_status') {
    header('Content-Type: application/json; charset=utf-8');
    if (!operatorRequestIsValid((string)($_POST['csrf_token'] ?? ''))) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Your session token expired. Refresh the page and try again.']);
        exit;
    }

    $operatorId = (int)($_POST['operator_id'] ?? 0);
    $newStatus = strtolower((string)($_POST['new_status'] ?? ''));
    if ($operatorId < 1 || !in_array($newStatus, ['active', 'inactive'], true)) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'The requested status update is invalid.']);
        exit;
    }

    try {
        $stmt = $pdo->prepare('UPDATE operators SET status = ? WHERE operator_id = ?');
        $stmt->execute([$newStatus, $operatorId]);
        if ($stmt->rowCount() < 1) {
            $check = $pdo->prepare('SELECT COUNT(*) FROM operators WHERE operator_id = ?');
            $check->execute([$operatorId]);
            if (!(int)$check->fetchColumn()) {
                throw new RuntimeException('The operator account no longer exists.');
            }
        }
        logActivity(
            $pdo,
            'Admin',
            (int)($_SESSION['admin_id'] ?? 0),
            (string)($_SESSION['admin_name'] ?? 'Administrator'),
            'Operator Account Updated',
            'Changed tour operator #' . $operatorId . ' status to ' . $newStatus . '.',
            'Operators',
            $operatorId
        );
        echo json_encode(['success' => true, 'new_status' => $newStatus]);
    } catch (Throwable $exception) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => $exception->getMessage()]);
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_operator'])) {
    $fullname = trim((string)($_POST['fullname'] ?? ''));
    $username = trim((string)($_POST['username'] ?? ''));
    $email = strtolower(trim((string)($_POST['email'] ?? '')));
    $password = (string)($_POST['password'] ?? '');
    $confirmation = (string)($_POST['confirm_password'] ?? '');

    $validationError = '';
    if (!operatorRequestIsValid((string)($_POST['csrf_token'] ?? ''))) {
        $validationError = 'Your session token expired. Refresh the page and try again.';
    } elseif ($fullname === '' || $username === '' || $email === '' || $password === '') {
        $validationError = 'Complete all required fields.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $validationError = 'Enter a valid email address.';
    } elseif (!preg_match('/^[A-Za-z0-9._-]{3,40}$/', $username)) {
        $validationError = 'Username must be 3–40 characters and may use letters, numbers, dots, underscores, or hyphens.';
    } elseif (strlen($password) < 8) {
        $validationError = 'Temporary password must contain at least 8 characters.';
    } elseif ($password !== $confirmation) {
        $validationError = 'The password confirmation does not match.';
    }

    if ($validationError === '') {
        $exists = $pdo->prepare('SELECT COUNT(*) FROM operators WHERE LOWER(username) = LOWER(?) OR LOWER(email) = LOWER(?)');
        $exists->execute([$username, $email]);
        if ((int)$exists->fetchColumn() > 0) {
            $validationError = 'That username or email address is already in use.';
        }
    }

    if ($validationError !== '') {
        $_SESSION['alert'] = ['type' => 'error', 'title' => 'Unable to Add Operator', 'message' => $validationError];
        header('Location: adoperator.php');
        exit;
    } else {
        try {
            $stmt = $pdo->prepare("INSERT INTO operators (fullname, username, email, password, status) VALUES (?, ?, ?, ?, 'active')");
            $stmt->execute([$fullname, $username, $email, password_hash($password, PASSWORD_DEFAULT)]);
            $newOperatorId = (int)$pdo->lastInsertId();
            logActivity(
                $pdo,
                'Admin',
                (int)($_SESSION['admin_id'] ?? 0),
                (string)($_SESSION['admin_name'] ?? 'Administrator'),
                'Operator Account Created',
                'Created the tour operator account for ' . $fullname . '.',
                'Operators',
                $newOperatorId
            );
            $_SESSION['alert'] = ['type' => 'success', 'title' => 'Operator Added', 'message' => $fullname . ' can now access the operator portal.'];
            header('Location: adoperator.php');
            exit;
        } catch (Throwable $exception) {
            $_SESSION['alert'] = ['type' => 'error', 'title' => 'Unable to Add Operator', 'message' => $exception->getMessage()];
        }
    }
}

$query = "
    SELECT o.*,
           COALESCE(p.package_count, 0) AS package_count,
           COALESCE(b.total_bookings, 0) AS total_bookings,
           COALESCE(b.completed_bookings, 0) AS completed_bookings
    FROM operators o
    LEFT JOIN (
        SELECT operator_id,
               COUNT(*) AS package_count
        FROM tour_packages
        GROUP BY operator_id
    ) p ON p.operator_id = o.operator_id
    LEFT JOIN (
        SELECT operator_id,
               COUNT(*) AS total_bookings,
               SUM(CASE WHEN is_complete = 'completed' THEN 1 ELSE 0 END) AS completed_bookings
        FROM bookings
        WHERE booking_type = 'package'
        GROUP BY operator_id
    ) b ON b.operator_id = o.operator_id
    ORDER BY o.fullname ASC, o.operator_id ASC
";
$operators = $pdo->query($query)->fetchAll(PDO::FETCH_ASSOC);
$summary = [
    'total' => count($operators),
    'active' => count(array_filter($operators, static fn(array $operator): bool => strtolower((string)($operator['status'] ?? '')) === 'active')),
    'inactive' => count(array_filter($operators, static fn(array $operator): bool => strtolower((string)($operator['status'] ?? '')) !== 'active')),
    'completed' => array_sum(array_map(static fn(array $operator): int => (int)$operator['completed_bookings'], $operators))
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>iTour Mercedes - Tour Operators</title>
<link rel="icon" type="image/png" href="img/newlogo.png">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="styles/admin_panel_theme.css">
<link rel="stylesheet" href="styles/adoperator.css">
</head>
<body>
<div class="admin-container">
  <?php include 'admin_sidebar.php'; ?>
  <main class="main-content operator-admin-main">
    <header class="admin-header admin-page-header operator-page-header">
      <div class="admin-header-left admin-page-title">
        <span class="admin-page-title-icon" aria-hidden="true">
          <svg viewBox="0 0 24 24"><circle cx="9" cy="8" r="4"></circle><path d="M2.5 20v-1.5A4.5 4.5 0 0 1 7 14h4a4.5 4.5 0 0 1 4.5 4.5V20M16 8h5M18.5 5.5v5"></path></svg>
        </span>
        <div class="admin-page-title-copy">
          <h2>Tour Operators</h2>
          <p class="admin-header-subtitle">Manage operator accounts, access, packages, and booking activity</p>
        </div>
      </div>
      <button class="add-operator-btn operator-header-add" id="openOperatorModal" type="button">
        <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"></circle><path d="M12 8v8M8 12h8"></path></svg>
        Add Operator
      </button>
    </header>

    <section class="operator-content">
      <div class="operator-summary" aria-label="Operator account summary">
        <button type="button" class="operator-stat-card is-selected" data-summary-filter="all" aria-pressed="true"><span class="summary-icon total"><svg viewBox="0 0 24 24"><circle cx="9" cy="8" r="4"></circle><path d="M2 21v-2a5 5 0 0 1 5-5h4a5 5 0 0 1 5 5v2M17 7h5M19.5 4.5v5"></path></svg></span><div><small>Operator network</small><strong id="operatorTotalCount"><?= number_format($summary['total']) ?></strong><span>Total operators</span></div><i aria-hidden="true">View all</i></button>
        <button type="button" class="operator-stat-card" data-summary-filter="active" aria-pressed="false"><span class="summary-icon active"><svg viewBox="0 0 24 24"><path d="m5 12 4 4L19 6"></path></svg></span><div><small>Portal access</small><strong id="operatorActiveCount"><?= number_format($summary['active']) ?></strong><span>Active accounts</span></div><i aria-hidden="true">Filter</i></button>
        <button type="button" class="operator-stat-card" data-summary-filter="inactive" aria-pressed="false"><span class="summary-icon inactive"><svg viewBox="0 0 24 24"><path d="M12 8v5M12 17h.01"></path><circle cx="12" cy="12" r="9"></circle></svg></span><div><small>Access review</small><strong id="operatorInactiveCount"><?= number_format($summary['inactive']) ?></strong><span>Inactive accounts</span></div><i aria-hidden="true">Filter</i></button>
        <button type="button" class="operator-stat-card" data-summary-filter="completed" aria-pressed="false"><span class="summary-icon completed"><svg viewBox="0 0 24 24"><path d="M4 19V8M10 19V4M16 19v-7M22 19H2"></path></svg></span><div><small>Tour performance</small><strong><?= number_format($summary['completed']) ?></strong><span>Completed bookings</span></div><i aria-hidden="true">Filter</i></button>
      </div>

      <section class="operator-directory-card">
        <div class="operator-card-head">
          <div class="operator-directory-copy"><h3>Operator Directory</h3><p>Click a profile photo or View details to open the complete operator record.</p></div>
          <div class="operator-toolbar">
            <label class="operator-search">
              <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"></circle><path d="m20 20-4-4"></path></svg>
              <input type="search" id="searchOperator" placeholder="Search name, username, or email..." autocomplete="off">
            </label>
            <label class="operator-filter"><span>Status</span><select id="operatorStatusFilter"><option value="all">All accounts</option><option value="active">Active</option><option value="inactive">Inactive</option></select></label>
            <span class="operator-result-count" id="operatorResultCount"><?= number_format($summary['total']) ?> record<?= $summary['total'] === 1 ? '' : 's' ?></span>
          </div>
        </div>

        <div class="operator-table-scroll">
          <table id="operatorsTable">
            <thead><tr><th>Operator</th><th>Account</th><th>Tour services</th><th>Booking activity</th><th>Status</th><th>Actions</th></tr></thead>
            <tbody>
            <?php foreach ($operators as $operator):
                $status = strtolower((string)($operator['status'] ?? 'inactive')) === 'active' ? 'active' : 'inactive';
                $image = resolveOperatorProfileImage($operator['profile_pic'] ?? null);
                $createdAt = (string)($operator['created_at'] ?? '');
                $updatedAt = (string)($operator['updated_at'] ?? '');
            ?>
              <tr class="operator-row" id="operator-<?= (int)$operator['operator_id'] ?>" data-status="<?= $status ?>" data-completed="<?= (int)$operator['completed_bookings'] ?>" data-search="<?= htmlspecialchars(strtolower(($operator['fullname'] ?? '') . ' ' . ($operator['username'] ?? '') . ' ' . ($operator['email'] ?? '')), ENT_QUOTES) ?>">
                <td>
                  <div class="operator-identity">
                    <button type="button" class="operator-avatar-trigger" aria-label="View details for <?= htmlspecialchars((string)$operator['fullname']) ?>" data-operator='<?= htmlspecialchars(json_encode([
                        'id' => (int)$operator['operator_id'], 'fullname' => (string)$operator['fullname'], 'username' => (string)$operator['username'],
                        'email' => (string)$operator['email'], 'status' => $status, 'image' => $image,
                        'packages' => (int)$operator['package_count'],
                        'bookings' => (int)$operator['total_bookings'], 'completed' => (int)$operator['completed_bookings'],
                        'created_at' => $createdAt, 'updated_at' => $updatedAt
                    ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT), ENT_QUOTES) ?>'>
                      <img src="<?= htmlspecialchars($image) ?>" alt="" loading="lazy" decoding="async" onerror="this.onerror=null;this.src='img/profileicon.png'">
                      <span aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M2.5 12s3.5-6 9.5-6 9.5 6 9.5 6-3.5 6-9.5 6-9.5-6-9.5-6Z"></path><circle cx="12" cy="12" r="2.5"></circle></svg></span>
                    </button>
                    <div><strong><?= htmlspecialchars((string)$operator['fullname']) ?></strong><span>Operator ID #<?= (int)$operator['operator_id'] ?></span></div>
                  </div>
                </td>
                <td><div class="operator-account"><strong>@<?= htmlspecialchars((string)$operator['username']) ?></strong><a href="mailto:<?= htmlspecialchars((string)$operator['email']) ?>"><?= htmlspecialchars((string)$operator['email']) ?></a></div></td>
                <td><div class="metric-cell"><strong><?= number_format((int)$operator['package_count']) ?></strong><span>listed tour package<?= (int)$operator['package_count'] === 1 ? '' : 's' ?></span></div></td>
                <td><div class="metric-cell"><strong><?= number_format((int)$operator['completed_bookings']) ?></strong><span>completed of <?= number_format((int)$operator['total_bookings']) ?> booking<?= (int)$operator['total_bookings'] === 1 ? '' : 's' ?></span></div></td>
                <td><span class="operator-status <?= $status ?>"><i></i><span><?= ucfirst($status) ?></span></span></td>
                <td>
                  <div class="operator-actions">
                    <button type="button" class="view-operator-btn" data-profile-for="<?= (int)$operator['operator_id'] ?>"><svg viewBox="0 0 24 24"><path d="M2.5 12s3.5-6 9.5-6 9.5 6 9.5 6-3.5 6-9.5 6-9.5-6-9.5-6Z"></path><circle cx="12" cy="12" r="2.5"></circle></svg>View details</button>
                    <button type="button" class="change-operator-password-btn" data-operator-id="<?= (int)$operator['operator_id'] ?>" data-operator-name="<?= htmlspecialchars((string)$operator['fullname'], ENT_QUOTES, 'UTF-8') ?>"><svg viewBox="0 0 24 24"><rect x="5" y="10" width="14" height="10" rx="2"></rect><path d="M8 10V7a4 4 0 0 1 8 0v3M12 14v2"></path></svg>Change password</button>
                    <label class="operator-switch" title="<?= $status === 'active' ? 'Deactivate' : 'Activate' ?> operator">
                      <input type="checkbox" data-operator-id="<?= (int)$operator['operator_id'] ?>" data-operator-name="<?= htmlspecialchars((string)$operator['fullname']) ?>" <?= $status === 'active' ? 'checked' : '' ?>>
                      <span></span><em class="sr-only">Toggle operator status</em>
                    </label>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
          <div class="operator-empty-state" id="operatorEmptyState" <?= $operators ? 'hidden' : '' ?>>
            <span><svg viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"></circle><path d="m20 20-4-4"></path></svg></span>
            <h4><?= $operators ? 'No matching operators' : 'No operators yet' ?></h4>
            <p><?= $operators ? 'Try another search term or account status.' : 'Add an operator account to get started.' ?></p>
          </div>
        </div>
      </section>
    </section>
  </main>
</div>

<div class="operator-modal-overlay" id="operatorModal" aria-hidden="true">
  <section class="operator-modal" role="dialog" aria-modal="true" aria-labelledby="operatorModalTitle">
    <header><div><span>NEW SERVICE PROVIDER</span><h3 id="operatorModalTitle">Add Tour Operator</h3><p>Create secure portal access for a new operator.</p></div><button type="button" class="operator-modal-close" aria-label="Close add operator form"><svg viewBox="0 0 24 24"><path d="m6 6 12 12M18 6 6 18"></path></svg></button></header>
    <form method="post" autocomplete="off" id="addOperatorForm">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
      <div class="operator-form-grid">
        <label class="wide"><span>Operator or business name <b>*</b></span><input type="text" name="fullname" maxlength="120" required placeholder="e.g. Mercedes Island Tours"></label>
        <label><span>Username <b>*</b></span><input type="text" name="username" minlength="3" maxlength="40" pattern="[A-Za-z0-9._-]+" required placeholder="operator.username"><small>Letters, numbers, dots, underscores, and hyphens.</small></label>
        <label><span>Email address <b>*</b></span><input type="email" name="email" maxlength="190" required placeholder="operator@example.com"></label>
        <label><span>Temporary password <b>*</b></span><div class="operator-password"><input type="password" name="password" minlength="8" required placeholder="At least 8 characters"><button type="button" class="password-toggle" aria-label="Show password" aria-pressed="false"><svg class="password-eye-open" viewBox="0 0 24 24" aria-hidden="true"><path d="M2.5 12s3.5-6 9.5-6 9.5 6 9.5 6-3.5 6-9.5 6-9.5-6-9.5-6Z"></path><circle cx="12" cy="12" r="2.5"></circle></svg><svg class="password-eye-off" viewBox="0 0 24 24" aria-hidden="true"><path d="M3 3l18 18"></path><path d="M10.6 6.2A10.7 10.7 0 0 1 12 6c6 0 9.5 6 9.5 6a16.8 16.8 0 0 1-2.3 3.1M6.1 6.1C3.8 7.8 2.5 12 2.5 12s3.5 6 9.5 6a10.7 10.7 0 0 0 3.4-.5"></path><path d="M9.9 9.9a3 3 0 0 0 4.2 4.2"></path></svg></button></div></label>
        <label><span>Confirm password <b>*</b></span><div class="operator-password"><input type="password" name="confirm_password" minlength="8" required placeholder="Repeat temporary password"><button type="button" class="password-toggle" aria-label="Show password" aria-pressed="false"><svg class="password-eye-open" viewBox="0 0 24 24" aria-hidden="true"><path d="M2.5 12s3.5-6 9.5-6 9.5 6 9.5 6-3.5 6-9.5 6-9.5-6-9.5-6Z"></path><circle cx="12" cy="12" r="2.5"></circle></svg><svg class="password-eye-off" viewBox="0 0 24 24" aria-hidden="true"><path d="M3 3l18 18"></path><path d="M10.6 6.2A10.7 10.7 0 0 1 12 6c6 0 9.5 6 9.5 6s-.8 1.4-2.3 2.9M6.1 6.1C3.8 7.8 2.5 12 2.5 12s3.5 6 9.5 6a10.7 10.7 0 0 0 3.4-.5"></path><path d="M9.9 9.9a3 3 0 0 0 4.2 4.2"></path></svg></button></div></label>
      </div>
      <footer><button type="button" class="operator-modal-cancel">Cancel</button><button type="submit" name="add_operator" class="operator-modal-submit"><svg viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"></path></svg>Create Operator</button></footer>
    </form>
  </section>
</div>

<div class="operator-modal-overlay" id="operatorPasswordModal" aria-hidden="true">
  <section class="operator-modal operator-password-reset-modal" role="dialog" aria-modal="true" aria-labelledby="operatorPasswordModalTitle">
    <header><div><span>OPERATOR PORTAL SECURITY</span><h3 id="operatorPasswordModalTitle">Change Operator Password</h3><p>Set a new login password for this operator account.</p></div><button type="button" class="operator-modal-close" aria-label="Close password form"><svg viewBox="0 0 24 24"><path d="m6 6 12 12M18 6 6 18"></path></svg></button></header>
    <form method="post" autocomplete="off" id="operatorPasswordForm">
      <input type="hidden" name="ajax_action" value="reset_password">
      <input type="hidden" name="operator_id" value="">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
      <div class="operator-password-account"><small>Selected operator</small><strong id="operatorPasswordAccountName">Tour operator</strong></div>
      <div class="operator-password-reset-fields">
        <label><span>New password <b>*</b></span><div class="operator-password"><input type="password" name="new_password" minlength="8" required autocomplete="new-password" placeholder="At least 8 characters"><button type="button" class="password-toggle" aria-label="Show password" aria-pressed="false"><svg class="password-eye-open" viewBox="0 0 24 24"><path d="M2.5 12s3.5-6 9.5-6 9.5 6 9.5 6-3.5 6-9.5 6-9.5-6-9.5-6Z"></path><circle cx="12" cy="12" r="2.5"></circle></svg><svg class="password-eye-off" viewBox="0 0 24 24"><path d="M3 3l18 18"></path><path d="M10.6 6.2A10.7 10.7 0 0 1 12 6c6 0 9.5 6 9.5 6s-.8 1.4-2.3 2.9M6.1 6.1C3.8 7.8 2.5 12 2.5 12s3.5 6 9.5 6a10.7 10.7 0 0 0 3.4-.5"></path><path d="M9.9 9.9a3 3 0 0 0 4.2 4.2"></path></svg></button></div><small>Use at least 8 characters.</small></label>
        <label><span>Confirm new password <b>*</b></span><div class="operator-password"><input type="password" name="confirm_password" minlength="8" required autocomplete="new-password" placeholder="Repeat the new password"><button type="button" class="password-toggle" aria-label="Show password" aria-pressed="false"><svg class="password-eye-open" viewBox="0 0 24 24"><path d="M2.5 12s3.5-6 9.5-6 9.5 6 9.5 6-3.5 6-9.5 6-9.5-6-9.5-6Z"></path><circle cx="12" cy="12" r="2.5"></circle></svg><svg class="password-eye-off" viewBox="0 0 24 24"><path d="M3 3l18 18"></path><path d="M10.6 6.2A10.7 10.7 0 0 1 12 6c6 0 9.5 6 9.5 6s-.8 1.4-2.3 2.9M6.1 6.1C3.8 7.8 2.5 12 2.5 12s3.5 6 9.5 6a10.7 10.7 0 0 0 3.4-.5"></path><path d="M9.9 9.9a3 3 0 0 0 4.2 4.2"></path></svg></button></div></label>
      </div>
      <div class="operator-password-error" role="alert"></div>
      <footer><button type="button" class="operator-modal-cancel">Cancel</button><button type="submit" class="operator-modal-submit"><svg viewBox="0 0 24 24"><rect x="5" y="10" width="14" height="10" rx="2"></rect><path d="M8 10V7a4 4 0 0 1 8 0v3"></path></svg>Change Password</button></footer>
    </form>
  </section>
</div>

<div class="operator-profile-overlay" id="operatorProfileDrawer" aria-hidden="true">
  <aside class="operator-profile-drawer" role="dialog" aria-modal="true" aria-labelledby="operatorProfileTitle">
    <header class="operator-profile-header">
      <div class="operator-profile-brand"><img src="img/newlogo.png" alt=""><div><span>ITOUR MERCEDES</span><h3 id="operatorProfileTitle">Operator Details</h3></div></div>
      <button type="button" class="operator-profile-close" aria-label="Close operator details"><svg viewBox="0 0 24 24"><path d="m6 6 12 12M18 6 6 18"></path></svg></button>
    </header>
    <div class="operator-profile-body" id="operatorProfileContent"></div>
    <footer class="operator-profile-footer"><button type="button" class="operator-profile-close-btn">Close Details</button></footer>
  </aside>
</div>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
const operatorCsrfToken = <?= json_encode($csrfToken, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
const operatorModal = document.getElementById('operatorModal');
const operatorPasswordModal = document.getElementById('operatorPasswordModal');
const operatorPasswordForm = document.getElementById('operatorPasswordForm');
const operatorDrawer = document.getElementById('operatorProfileDrawer');
let lastOperatorTrigger = null;
let lastOperatorPasswordTrigger = null;
let operatorSummaryFilter = 'all';

function escapeOperatorHtml(value) {
  return String(value ?? '').replace(/[&<>"']/g, character => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'})[character]);
}
function operatorDate(value) {
  if (!value) return 'Not recorded';
  const date = new Date(String(value).replace(' ', 'T'));
  return Number.isNaN(date.getTime()) ? String(value) : date.toLocaleDateString('en-PH', { year: 'numeric', month: 'long', day: 'numeric' });
}
function updateOperatorFilters() {
  const query = document.getElementById('searchOperator').value.trim().toLowerCase();
  const status = document.getElementById('operatorStatusFilter').value;
  let visible = 0;
  document.querySelectorAll('.operator-row').forEach(row => {
    const matchesSummary = operatorSummaryFilter === 'all'
      || (operatorSummaryFilter === 'completed' && Number(row.dataset.completed || 0) > 0)
      || row.dataset.status === operatorSummaryFilter;
    const matches = (!query || row.dataset.search.includes(query))
      && (status === 'all' || row.dataset.status === status)
      && matchesSummary;
    row.hidden = !matches;
    if (matches) visible++;
  });
  document.getElementById('operatorResultCount').textContent = `${visible} record${visible === 1 ? '' : 's'}`;
  document.getElementById('operatorEmptyState').hidden = visible !== 0;
  const rows = [...document.querySelectorAll('.operator-row')];
  document.getElementById('operatorTotalCount').textContent = rows.length.toLocaleString();
  document.getElementById('operatorActiveCount').textContent = rows.filter(row => row.dataset.status === 'active').length.toLocaleString();
  document.getElementById('operatorInactiveCount').textContent = rows.filter(row => row.dataset.status === 'inactive').length.toLocaleString();
}
document.getElementById('searchOperator').addEventListener('input', updateOperatorFilters);
document.getElementById('operatorStatusFilter').addEventListener('change', event => {
  operatorSummaryFilter = event.target.value;
  document.querySelectorAll('.operator-stat-card').forEach(card => {
    const selected = card.dataset.summaryFilter === operatorSummaryFilter;
    card.classList.toggle('is-selected', selected);
    card.setAttribute('aria-pressed', selected ? 'true' : 'false');
  });
  updateOperatorFilters();
});
document.querySelectorAll('.operator-stat-card').forEach(card => card.addEventListener('click', () => {
  operatorSummaryFilter = card.dataset.summaryFilter || 'all';
  const statusSelect = document.getElementById('operatorStatusFilter');
  statusSelect.value = ['active', 'inactive'].includes(operatorSummaryFilter) ? operatorSummaryFilter : 'all';
  document.querySelectorAll('.operator-stat-card').forEach(item => {
    const selected = item === card;
    item.classList.toggle('is-selected', selected);
    item.setAttribute('aria-pressed', selected ? 'true' : 'false');
  });
  updateOperatorFilters();
  document.querySelector('.operator-directory-card')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
}));

function openAddOperatorModal() {
  operatorModal.classList.add('show');
  operatorModal.setAttribute('aria-hidden', 'false');
  document.body.classList.add('operator-dialog-open');
  setTimeout(() => operatorModal.querySelector('input[name="fullname"]')?.focus(), 60);
}
function closeAddOperatorModal() {
  operatorModal.classList.remove('show');
  operatorModal.setAttribute('aria-hidden', 'true');
  document.body.classList.remove('operator-dialog-open');
  operatorModal.querySelectorAll('.password-toggle').forEach(button => {
    button.previousElementSibling.type = 'password';
    button.setAttribute('aria-label', 'Show password');
    button.setAttribute('aria-pressed', 'false');
  });
  document.getElementById('openOperatorModal')?.focus();
}
document.getElementById('openOperatorModal').addEventListener('click', openAddOperatorModal);
operatorModal.querySelector('.operator-modal-close').addEventListener('click', closeAddOperatorModal);
operatorModal.querySelector('.operator-modal-cancel').addEventListener('click', closeAddOperatorModal);
operatorModal.addEventListener('mousedown', event => { if (event.target === operatorModal) closeAddOperatorModal(); });

function openOperatorPasswordModal(button) {
  lastOperatorPasswordTrigger = button;
  operatorPasswordForm.reset();
  operatorPasswordForm.elements.operator_id.value = button.dataset.operatorId || '';
  document.getElementById('operatorPasswordAccountName').textContent = button.dataset.operatorName || 'Tour operator';
  operatorPasswordForm.querySelector('.operator-password-error').textContent = '';
  operatorPasswordModal.classList.add('show');
  operatorPasswordModal.setAttribute('aria-hidden', 'false');
  document.body.classList.add('operator-dialog-open');
  setTimeout(() => operatorPasswordForm.elements.new_password?.focus(), 60);
}
function closeOperatorPasswordModal() {
  operatorPasswordModal.classList.remove('show');
  operatorPasswordModal.setAttribute('aria-hidden', 'true');
  document.body.classList.remove('operator-dialog-open');
  operatorPasswordForm.reset();
  operatorPasswordForm.querySelector('.operator-password-error').textContent = '';
  operatorPasswordModal.querySelectorAll('.password-toggle').forEach(button => {
    button.previousElementSibling.type = 'password';
    button.setAttribute('aria-label', 'Show password');
    button.setAttribute('aria-pressed', 'false');
  });
  lastOperatorPasswordTrigger?.focus();
}
document.querySelectorAll('.change-operator-password-btn').forEach(button => button.addEventListener('click', () => openOperatorPasswordModal(button)));
operatorPasswordModal.querySelector('.operator-modal-close').addEventListener('click', closeOperatorPasswordModal);
operatorPasswordModal.querySelector('.operator-modal-cancel').addEventListener('click', closeOperatorPasswordModal);
operatorPasswordModal.addEventListener('mousedown', event => { if (event.target === operatorPasswordModal) closeOperatorPasswordModal(); });

document.querySelectorAll('.password-toggle').forEach(button => button.addEventListener('click', () => {
  const input = button.previousElementSibling;
  const show = input.type === 'password';
  input.type = show ? 'text' : 'password';
  button.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
  button.setAttribute('aria-pressed', show ? 'true' : 'false');
}));
document.getElementById('addOperatorForm').addEventListener('submit', event => {
  const form = event.currentTarget;
  const password = form.elements.password;
  const confirmation = form.elements.confirm_password;
  confirmation.setCustomValidity(password.value === confirmation.value ? '' : 'Passwords do not match.');
  if (!form.checkValidity()) {
    event.preventDefault();
    form.reportValidity();
  }
});

operatorPasswordForm.addEventListener('submit', async event => {
  event.preventDefault();
  const newPassword = operatorPasswordForm.elements.new_password;
  const confirmation = operatorPasswordForm.elements.confirm_password;
  const errorBox = operatorPasswordForm.querySelector('.operator-password-error');
  confirmation.setCustomValidity(newPassword.value === confirmation.value ? '' : 'Passwords do not match.');
  if (!operatorPasswordForm.checkValidity()) {
    operatorPasswordForm.reportValidity();
    return;
  }

  const submitButton = operatorPasswordForm.querySelector('button[type="submit"]');
  submitButton.disabled = true;
  errorBox.textContent = '';
  try {
    const response = await fetch('adoperator.php', {
      method: 'POST',
      credentials: 'same-origin',
      headers: {'Accept': 'application/json'},
      body: new FormData(operatorPasswordForm)
    });
    const result = await response.json();
    if (!response.ok || !result.success) throw new Error(result.message || 'The password could not be changed.');
    closeOperatorPasswordModal();
    await Swal.fire({icon: 'success', title: 'Password changed', text: result.message, confirmButtonColor: '#2b7a66'});
  } catch (error) {
    errorBox.textContent = error.message || 'The password could not be changed.';
  } finally {
    submitButton.disabled = false;
  }
});

function openOperatorDetails(data, trigger) {
  lastOperatorTrigger = trigger;
  const statusLabel = data.status === 'active' ? 'Active' : 'Inactive';
  const content = document.getElementById('operatorProfileContent');
  const emailHref = data.email ? `mailto:${encodeURIComponent(data.email)}` : '#';
  const icon = {
    mail: '<svg viewBox="0 0 24 24"><rect x="3" y="5" width="18" height="14" rx="2"></rect><path d="m4 7 8 6 8-6"></path></svg>',
    user: '<svg viewBox="0 0 24 24"><circle cx="12" cy="8" r="4"></circle><path d="M4 21v-2a6 6 0 0 1 6-6h4a6 6 0 0 1 6 6v2"></path></svg>',
    shield: '<svg viewBox="0 0 24 24"><path d="M12 3 4.5 6v5.5c0 4.8 3.2 8 7.5 9.5 4.3-1.5 7.5-4.7 7.5-9.5V6L12 3Z"></path><path d="m9 12 2 2 4-4"></path></svg>',
    package: '<svg viewBox="0 0 24 24"><path d="m4 7 8-4 8 4-8 4-8-4Z"></path><path d="M4 7v10l8 4 8-4V7M12 11v10"></path></svg>',
    calendar: '<svg viewBox="0 0 24 24"><rect x="3" y="5" width="18" height="16" rx="2"></rect><path d="M16 3v4M8 3v4M3 10h18"></path></svg>'
  };
  content.innerHTML = `
    <section class="opd-hero"><img src="${escapeOperatorHtml(data.image || 'img/profileicon.png')}" alt="" onerror="this.onerror=null;this.src='img/profileicon.png'"><div><small>REGISTERED TOUR OPERATOR</small><h2>${escapeOperatorHtml(data.fullname)}</h2><p>Operator ID: #${escapeOperatorHtml(data.id)}</p><span class="${data.status === 'active' ? 'positive' : 'muted'}">${statusLabel} account</span></div></section>
    <section class="opd-stats"><div><strong>${Number(data.packages || 0).toLocaleString()}</strong><span>Total packages</span></div><div><strong>${Number(data.bookings || 0).toLocaleString()}</strong><span>Total bookings</span></div><div><strong>${Number(data.completed || 0).toLocaleString()}</strong><span>Completed tours</span></div></section>
    <section class="opd-section"><div class="opd-section-heading"><span>${icon.user}</span><div><h3>Account information</h3><p>Portal identity and access details</p></div></div><div class="opd-info-list"><div class="opd-info-row"><span>${icon.user}</span><div><small>Username</small><strong>@${escapeOperatorHtml(data.username)}</strong></div></div><div class="opd-info-row"><span>${icon.mail}</span><div><small>Email address</small><strong>${escapeOperatorHtml(data.email || 'Not provided')}</strong></div></div><div class="opd-info-row"><span>${icon.shield}</span><div><small>Access status</small><strong>${statusLabel}</strong></div></div></div><a class="opd-email-action${data.email ? '' : ' is-disabled'}" href="${emailHref}">${icon.mail}<span>Email operator</span><b aria-hidden="true">&rarr;</b></a></section>
    <section class="opd-section"><div class="opd-section-heading"><span>${icon.package}</span><div><h3>Service performance</h3><p>Packages and package-booking activity</p></div></div><div class="opd-account-grid"><div><small>Listed packages</small><strong>${Number(data.packages || 0).toLocaleString()}</strong></div><div><small>Total bookings</small><strong>${Number(data.bookings || 0).toLocaleString()}</strong></div><div><small>Completed bookings</small><strong>${Number(data.completed || 0).toLocaleString()}</strong></div><div><small>Completion rate</small><strong>${Number(data.bookings || 0) ? Math.round((Number(data.completed || 0) / Number(data.bookings)) * 100) : 0}%</strong></div></div></section>
    ${(data.created_at || data.updated_at) ? `<section class="opd-section"><div class="opd-section-heading"><span>${icon.calendar}</span><div><h3>Account timeline</h3><p>Dates recorded by the system</p></div></div><div class="opd-timeline"><div><i></i><span><small>Account created</small><strong>${escapeOperatorHtml(operatorDate(data.created_at))}</strong></span></div><div><i></i><span><small>Last profile update</small><strong>${escapeOperatorHtml(operatorDate(data.updated_at))}</strong></span></div></div></section>` : ''}`;
  operatorDrawer.classList.add('show');
  operatorDrawer.setAttribute('aria-hidden', 'false');
  document.body.classList.add('operator-dialog-open');
  operatorDrawer.querySelector('.operator-profile-close').focus();
}
function closeOperatorDetails() {
  operatorDrawer.classList.remove('show');
  operatorDrawer.setAttribute('aria-hidden', 'true');
  document.body.classList.remove('operator-dialog-open');
  lastOperatorTrigger?.focus();
}
document.querySelectorAll('.operator-avatar-trigger').forEach(button => button.addEventListener('click', () => openOperatorDetails(JSON.parse(button.dataset.operator), button)));
document.querySelectorAll('.view-operator-btn').forEach(button => button.addEventListener('click', () => {
  const trigger = document.querySelector(`#operator-${button.dataset.profileFor} .operator-avatar-trigger`);
  if (trigger) openOperatorDetails(JSON.parse(trigger.dataset.operator), button);
}));
operatorDrawer.querySelector('.operator-profile-close').addEventListener('click', closeOperatorDetails);
operatorDrawer.querySelector('.operator-profile-close-btn').addEventListener('click', closeOperatorDetails);
operatorDrawer.addEventListener('mousedown', event => { if (event.target === operatorDrawer) closeOperatorDetails(); });

document.querySelectorAll('.operator-switch input').forEach(checkbox => checkbox.addEventListener('change', async () => {
  const newStatus = checkbox.checked ? 'active' : 'inactive';
  const oldStatus = checkbox.checked ? 'inactive' : 'active';
  const name = checkbox.dataset.operatorName;
  const result = await Swal.fire({
    icon: 'question', title: `${newStatus === 'active' ? 'Activate' : 'Deactivate'} operator?`,
    text: `${name} will ${newStatus === 'active' ? 'regain access to' : 'no longer be able to access'} the operator portal.`,
    showCancelButton: true, confirmButtonColor: '#2b7a66', cancelButtonColor: '#74817c',
    confirmButtonText: newStatus === 'active' ? 'Activate account' : 'Deactivate account'
  });
  if (!result.isConfirmed) { checkbox.checked = !checkbox.checked; return; }
  checkbox.disabled = true;
  try {
    const response = await fetch('adoperator.php', { method: 'POST', headers: {'Content-Type':'application/x-www-form-urlencoded','Accept':'application/json'}, body: new URLSearchParams({ajax_action:'toggle_status', operator_id:checkbox.dataset.operatorId, new_status:newStatus, csrf_token:operatorCsrfToken}) });
    const data = await response.json();
    if (!response.ok || !data.success) throw new Error(data.message || 'The status could not be updated.');
    const row = document.getElementById(`operator-${checkbox.dataset.operatorId}`);
    row.dataset.status = newStatus;
    const badge = row.querySelector('.operator-status');
    badge.className = `operator-status ${newStatus}`;
    badge.querySelector('span').textContent = newStatus.charAt(0).toUpperCase() + newStatus.slice(1);
    const trigger = row.querySelector('.operator-avatar-trigger');
    const profileData = JSON.parse(trigger.dataset.operator);
    profileData.status = newStatus;
    trigger.dataset.operator = JSON.stringify(profileData);
    updateOperatorFilters();
    await Swal.fire({icon:'success', title:'Status updated', text:`${name} is now ${newStatus}.`, confirmButtonColor:'#2b7a66'});
  } catch (error) {
    checkbox.checked = oldStatus === 'active';
    Swal.fire({icon:'error', title:'Update failed', text:error.message, confirmButtonColor:'#b34747'});
  } finally { checkbox.disabled = false; }
}));

document.addEventListener('keydown', event => {
  if (event.key !== 'Escape') return;
  if (operatorDrawer.classList.contains('show')) closeOperatorDetails();
  else if (operatorPasswordModal.classList.contains('show')) closeOperatorPasswordModal();
  else if (operatorModal.classList.contains('show')) closeAddOperatorModal();
});
</script>
</body>
</html>
