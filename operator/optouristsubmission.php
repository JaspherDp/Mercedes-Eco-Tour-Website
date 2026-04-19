<?php
chdir(__DIR__ . '/..');
session_start();
require_once __DIR__ . '/../php/db_connection.php'; // ✅ Ensure DB connection available

// ✅ Logout Action
if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    session_unset();
    session_destroy();
    echo "<script>
        alert('You have been logged out. Session expired.');
        window.location.href = 'homepage.php';
    </script>";
    exit();
}

// ✅ Operator Session Authentication Check
if (!isset($_SESSION['operator_logged_in']) || $_SESSION['operator_logged_in'] !== true) {
    echo "<script>
        alert('Session expired! Please login again.');
        window.location.href = 'php/operator_login.php';
    </script>";
    exit();
}
// ✅ Auto logout if operator is inactive
$operator_id = $_SESSION['operator_id'] ?? null;
$operatorName = $_SESSION['operator_name'] ?? 'Operator';
$opProfilePicFile = trim((string)($_SESSION['operator_profile'] ?? ''));
$opHeaderProfilePic = null;
if ($opProfilePicFile !== '' && strtolower($opProfilePicFile) !== 'img/profileicon.png' && file_exists($opProfilePicFile)) {
    $opHeaderProfilePic = $opProfilePicFile;
}
$opProfileInitial = strtoupper(substr(trim((string)$operatorName) !== '' ? trim((string)$operatorName) : 'O', 0, 1));

if (isset($_POST['op_action']) && $_POST['op_action'] === 'mark_notifications_read') {
    $_SESSION['op_notifications_seen_at'] = date('Y-m-d H:i:s');
    header('Content-Type: application/json');
    echo json_encode(['ok' => true]);
    exit;
}

if ($operator_id) {
    $stmt = $pdo->prepare("SELECT status FROM operators WHERE operator_id = ?");
    $stmt->execute([$operator_id]);
    $status = $stmt->fetchColumn();

    if ($status !== 'active') {
        session_unset();
        session_destroy();
        echo "<script>
            alert('Your account has been deactivated by admin.');
            window.location.href = 'php/operator_login.php';
        </script>";
        exit();
    }
}

$seenAt = trim((string)($_SESSION['op_notifications_seen_at'] ?? ''));
if ($seenAt !== '' && preg_match('/^\d{4}-\d{2}-\d{2}\s\d{2}:\d{2}:\d{2}$/', $seenAt)) {
    $notifStmt = $pdo->prepare("SELECT COUNT(*) FROM bookings WHERE operator_id=? AND status='pending' AND created_at > ?");
    $notifStmt->execute([(int)$operator_id, $seenAt]);
} else {
    $notifStmt = $pdo->prepare("SELECT COUNT(*) FROM bookings WHERE operator_id=? AND status='pending'");
    $notifStmt->execute([(int)$operator_id]);
}
$notificationCount = (int)$notifStmt->fetchColumn();

$notifItemsStmt = $pdo->prepare("
    SELECT booking_id, package_name, booking_date, status, created_at
    FROM bookings
    WHERE operator_id=?
    ORDER BY created_at DESC
    LIMIT 8
");
$notifItemsStmt->execute([(int)$operator_id]);
$notificationItems = $notifItemsStmt->fetchAll(PDO::FETCH_ASSOC);


// ✅ Prevent Back-Button Access After Logout
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Cache-Control: post-check=0, pre-check=0', false);
header('Pragma: no-cache');
header('Expires: 0');
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>iTour Mercedes - Tourist Submission</title>
<link rel="icon" type="image/png" href="img/newlogo.png">

<style>
  body {
    margin: 0;
    font-family: 'Inter', 'Segoe UI', sans-serif;
    display: flex;
    min-height: 100vh;
    background: #f4f8f6;
    color: #132028;
  }

  .op-layout {
    display: flex;
    min-height: 100vh;
    width: 100%;
  }

  /* Main Content Container */
  .omain-content {
    flex: 1;
    margin-left: 250px;
    padding: 86px 18px 24px;
  }

  /* Header */
  .operator-header {
    background: #fff;
    border-radius: 0 0 14px 14px;
    padding: 14px 18px;
    position: fixed;
    top: 0;
    left: 250px;
    width: calc(100vw - 250px);
    min-height: 78px;
    z-index: 90;
    border-bottom: 1px solid rgba(188, 220, 206, 0.7);
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 16px;
  }
  .operator-header-left h2 {
    color: #1d5d4a;
    font-size: 23px;
    margin: 0;
    font-weight: 700;
  }
  .operator-header-left p {
    margin: 3px 0 0;
    color: #60707a;
    font-size: 13px;
  }
  .operator-header-right {
    display: inline-flex;
    align-items: center;
    gap: 10px;
    margin-right: 8px;
  }
  .op-topbar-profile {
    width: 38px;
    height: 38px;
    border-radius: 50%;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    background: linear-gradient(135deg, #2b7a66 0%, #1f614e 100%);
    color: #fff;
    font-size: 14px;
    font-weight: 800;
    text-transform: uppercase;
    border: 1px solid rgba(43, 122, 102, 0.18);
    box-shadow: 0 4px 10px rgba(28, 74, 62, 0.14);
    overflow: hidden;
  }
  .op-topbar-profile img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: block;
  }
  .op-notif-wrap { position: relative; }
  .op-notif-btn {
    border: 1px solid #d8e6e0;
    background: #fff;
    border-radius: 10px;
    padding: 8px 11px;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    color: #26404a;
    font-weight: 600;
    cursor: pointer;
    font-size: 13px;
  }
  .op-notif-badge {
    min-width: 20px;
    height: 20px;
    border-radius: 50%;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    background: #bf3545;
    color: #fff;
    font-size: 12px;
    font-weight: 700;
  }
  .op-notif-panel {
    position: absolute;
    right: 0;
    top: calc(100% + 8px);
    width: min(420px, 88vw);
    background: #fff;
    border: 1px solid #d8e6e0;
    border-radius: 12px;
    box-shadow: 0 12px 30px rgba(17, 67, 53, 0.08);
    padding: 10px;
    display: none;
    z-index: 120;
  }
  .op-notif-panel.open { display: block; }
  .op-notif-panel h4 { margin: 0 0 8px; font-size: 14px; }
  .op-notif-list {
    margin: 0;
    padding: 0;
    list-style: none;
    display: grid;
    gap: 8px;
    max-height: 320px;
    overflow: auto;
  }
  .op-notif-list li {
    border: 1px solid #e8f0ed;
    background: #fbfefd;
    border-radius: 10px;
    padding: 8px;
    display: grid;
    gap: 2px;
  }
  .op-notif-list li strong { font-size: 12px; color: #1d343e; }
  .op-notif-list li span,
  .op-notif-list li small { font-size: 12px; color: #63747d; }
  .op-notif-empty { margin: 0; color: #60707a; font-size: 13px; padding: 8px 4px; }
  .empty-card {
    margin-top: 10px;
    background: #fff;
    border: 1px solid #d8e6e0;
    border-radius: 16px;
    box-shadow: 0 10px 24px rgba(17, 67, 53, 0.07);
    padding: 18px;
    font-size: 14px;
    color: #60707a;
  }

</style>
</head>
<body>

<div class="op-layout">
  <?php include 'operator_sidebar.php'; ?>

  <main class="omain-content">
    <header class="operator-header">
      <div class="operator-header-left">
        <h2>Tourist Submissions</h2>
        <p>Welcome, <?= htmlspecialchars((string)$operatorName) ?></p>
      </div>
      <div class="operator-header-right">
        <div class="op-notif-wrap">
          <button type="button" class="op-notif-btn" id="opNotifToggle" aria-label="Notifications">
            Notifications
            <?php if ($notificationCount > 0): ?>
              <span class="op-notif-badge"><?= $notificationCount ?></span>
            <?php endif; ?>
          </button>
          <div class="op-notif-panel" id="opNotifPanel">
            <h4>Recent Bookings</h4>
            <?php if (!empty($notificationItems)): ?>
              <ul class="op-notif-list">
                <?php foreach ($notificationItems as $item): ?>
                  <li>
                    <strong>#<?= (int)$item['booking_id'] ?> - <?= htmlspecialchars((string)$item['package_name']) ?></strong>
                    <span><?= htmlspecialchars((string)$item['status']) ?> • <?= htmlspecialchars((string)$item['booking_date']) ?></span>
                    <small><?= htmlspecialchars((string)$item['created_at']) ?></small>
                  </li>
                <?php endforeach; ?>
              </ul>
            <?php else: ?>
              <p class="op-notif-empty">No notifications yet.</p>
            <?php endif; ?>
          </div>
        </div>
        <div class="op-topbar-profile" title="<?= htmlspecialchars((string)$operatorName) ?>">
          <?php if ($opHeaderProfilePic): ?>
            <img src="<?= htmlspecialchars($opHeaderProfilePic) ?>" alt="<?= htmlspecialchars((string)$operatorName) ?>">
          <?php else: ?>
            <?= htmlspecialchars($opProfileInitial) ?>
          <?php endif; ?>
        </div>
      </div>
    </header>
    <section class="empty-card">
      Tourist submission content will appear here.
    </section>
  </main>
</div>

<script>
  const opNotifToggle = document.getElementById('opNotifToggle');
  const opNotifPanel = document.getElementById('opNotifPanel');
  const opNotifBadge = document.querySelector('.op-notif-badge');
  let opNotifMarked = false;

  async function markOpNotificationsRead() {
    if (opNotifMarked) return;
    opNotifMarked = true;
    if (opNotifBadge) opNotifBadge.remove();
    const body = new URLSearchParams();
    body.set('op_action', 'mark_notifications_read');
    try {
      await fetch('optouristsubmission.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: body.toString()
      });
    } catch (e) {
      console.error('Failed to mark notifications as read', e);
    }
  }

  if (opNotifToggle && opNotifPanel) {
    opNotifToggle.addEventListener('click', () => {
      const open = opNotifPanel.classList.toggle('open');
      if (open) markOpNotificationsRead();
    });
    document.addEventListener('click', (event) => {
      if (!opNotifPanel.contains(event.target) && !opNotifToggle.contains(event.target)) {
        opNotifPanel.classList.remove('open');
      }
    });
  }
</script>

</body>
</html>

