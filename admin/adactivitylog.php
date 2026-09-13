<?php
chdir(__DIR__ . '/..');
require_once __DIR__ . '/../php/session_security.php';
AppSessionStart();
require_once __DIR__ . '/../php/db_connection.php';
require_once __DIR__ . '/../php/activity_logger.php';
require_once __DIR__ . '/../php/admin_auth_helper.php';

if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    logActivity(
        $pdo,
        'Admin',
        (int)($_SESSION['admin_id'] ?? 0),
        (string)($_SESSION['admin_name'] ?? 'Administrator'),
        'Logout',
        'Signed out of the admin panel.',
        'Authentication'
    );
    AppDestroySession();
    header('Location: ' . AdminLoginUrl('adhomepage.php'));
    exit;
}

AdminRequireLogin();

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

function activityEscape($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function activityPageUrl(int $page): string
{
    $query = $_GET;
    unset($query['action']);
    $query['page'] = $page;
    return 'adactivitylog.php?' . http_build_query($query);
}

function activityProfileImage(?string $profilePicture): string
{
    $candidate = html_entity_decode(trim((string)$profilePicture), ENT_QUOTES, 'UTF-8');
    $candidate = str_replace('\\/', '/', $candidate);
    if ($candidate === '') {
        return '';
    }

    if (strpos($candidate, '//') === 0) {
        $candidate = 'https:' . $candidate;
    }
    if (preg_match('#^https?://#i', $candidate)) {
        if (stripos($candidate, 'profiles.google.com') !== false
            && preg_match('#profiles\.google\.com/(?:s2/photos/profile/)?([^/?#]+)(?:/picture)?#i', $candidate, $matches)) {
            return 'https://profiles.google.com/' . rawurlencode($matches[1]) . '/picture?sz=96';
        }
        if (stripos($candidate, 'googleusercontent.com') !== false) {
            $candidate = preg_replace('/([?&])sz=\d+/i', '$1sz=96', $candidate);
            $candidate = preg_replace('/=s\d+-c(?=$|[?&#])/i', '=s96-c', $candidate);
            $candidate = preg_replace('/=s\d+(?=$|[?&#])/i', '=s96', $candidate);
        }
        return $candidate;
    }

    $candidate = ltrim(str_replace('\\', '/', $candidate), '/');
    if ($candidate === '' || str_contains($candidate, '..') || str_contains($candidate, "\0")) {
        return '';
    }

    $paths = [
        $candidate,
        'uploads/profile_pictures/' . basename($candidate),
        'uploads/profile_picture/' . basename($candidate),
        'uploads/profile/' . basename($candidate),
    ];
    foreach (array_unique($paths) as $path) {
        $absolutePath = getcwd() . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path);
        if (is_file($absolutePath)) {
            $scriptDirectory = strtolower(str_replace('\\', '/', dirname($_SERVER['PHP_SELF'] ?? '')));
            $urlPrefix = substr($scriptDirectory, -6) === '/admin' ? '../' : '';
            return $urlPrefix . $path;
        }
    }

    return '';
}

$search = trim((string)($_GET['search'] ?? ''));
$actorType = trim((string)($_GET['actor_type'] ?? ''));
$selectedAction = trim((string)($_GET['log_action'] ?? ''));
$dateFrom = trim((string)($_GET['date_from'] ?? ''));
$dateTo = trim((string)($_GET['date_to'] ?? ''));
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 20;
$allowedActorTypes = ['Admin', 'Tourist', 'Tour Operator', 'Hotel Owner'];

if (!in_array($actorType, $allowedActorTypes, true)) {
    $actorType = '';
}
if ($dateFrom !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) {
    $dateFrom = '';
}
if ($dateTo !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
    $dateTo = '';
}

$where = [];
$params = [];
if ($search !== '') {
    $where[] = '(l.actor_name LIKE :search_actor OR l.action LIKE :search_action OR l.module LIKE :search_module OR l.description LIKE :search_description OR l.ip_address LIKE :search_ip)';
    $searchValue = '%' . $search . '%';
    $params[':search_actor'] = $searchValue;
    $params[':search_action'] = $searchValue;
    $params[':search_module'] = $searchValue;
    $params[':search_description'] = $searchValue;
    $params[':search_ip'] = $searchValue;
}
if ($actorType !== '') {
    $where[] = 'l.actor_type = :actor_type';
    $params[':actor_type'] = $actorType;
}
if ($selectedAction !== '') {
    $where[] = 'l.action = :action';
    $params[':action'] = $selectedAction;
}
if ($dateFrom !== '') {
    $where[] = 'l.created_at >= :date_from';
    $params[':date_from'] = $dateFrom . ' 00:00:00';
}
if ($dateTo !== '') {
    $where[] = 'l.created_at < :date_to';
    $params[':date_to'] = date('Y-m-d 00:00:00', strtotime($dateTo . ' +1 day'));
}
$whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

$countStmt = $pdo->prepare('SELECT COUNT(*) FROM admin_activity_logs l' . $whereSql);
$countStmt->execute($params);
$totalLogs = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalLogs / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$logStmt = $pdo->prepare(
    'SELECT l.log_id, l.actor_type, l.actor_id, l.actor_name, l.action, l.module,
            l.description, l.reference_id, l.ip_address, l.created_at,
            t.profile_picture AS actor_profile_picture
     FROM admin_activity_logs l
     LEFT JOIN tourist t
       ON l.actor_type = \'Tourist\' AND t.tourist_id = l.actor_id' . $whereSql . '
     ORDER BY l.created_at DESC, l.log_id DESC
     LIMIT ' . $perPage . ' OFFSET ' . $offset
);
$logStmt->execute($params);
$logs = $logStmt->fetchAll(PDO::FETCH_ASSOC);

$actions = $pdo->query(
    "SELECT DISTINCT action FROM admin_activity_logs
     WHERE action IS NOT NULL AND action <> ''
     ORDER BY action"
)->fetchAll(PDO::FETCH_COLUMN);

$hasFilters = $search !== '' || $actorType !== '' || $selectedAction !== '' || $dateFrom !== '' || $dateTo !== '';
$firstShown = $totalLogs > 0 ? $offset + 1 : 0;
$lastShown = min($offset + $perPage, $totalLogs);

function activityTone(string $action): string
{
    $action = strtolower($action);
    if (preg_match('/reject|declin|delet|archive|ban|cancel/', $action)) return 'danger';
    if (preg_match('/login|accept|confirm|complete|create|add|submit/', $action)) return 'success';
    if (preg_match('/update|edit|payment|check/', $action)) return 'info';
    if (str_contains($action, 'logout')) return 'neutral';
    return 'primary';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Activity Log | iTour Mercedes</title>
  <link rel="icon" type="image/png" href="img/newlogo.png">
  <link rel="stylesheet" href="styles/admin_panel_theme.css">
  <style>
    * { box-sizing: border-box; }
    body { margin: 0; }
    .admin-container { display: flex; min-height: 100vh; }
    .main-content { flex: 1; margin-left: 250px; min-width: 0; transition: margin-left .3s ease; }
    .admin-sidebar.collapsed ~ .main-content { margin-left: 90px; }
    .activity-header { display:flex; align-items:center; justify-content:space-between; gap:16px; }
    .activity-title { display:flex; align-items:center; gap:12px; }
    .activity-title-icon { width:40px; height:40px; border-radius:12px; display:grid; place-items:center; color:#fff; background:linear-gradient(135deg,#2b7a66,#1d5d4a); box-shadow:0 8px 18px rgba(29,93,74,.2); }
    .activity-title-icon svg, .activity-control-icon svg, .activity-empty svg, .activity-row-icon svg { width:20px; height:20px; fill:none; stroke:currentColor; stroke-width:1.8; stroke-linecap:round; stroke-linejoin:round; }
    .activity-count { display:inline-flex; align-items:center; min-height:30px; padding:0 11px; border-radius:999px; color:#60707a; background:#eef1f2; font-size:13px; font-weight:600; white-space:nowrap; }
    .activity-panel { background:#fff; border:1px solid var(--ap-border); border-radius:16px; box-shadow:var(--ap-shadow); overflow:hidden; }
    .activity-filters { padding:16px; display:grid; grid-template-columns:minmax(220px,2fr) repeat(4,minmax(145px,1fr)) auto; gap:10px; align-items:end; border-bottom:1px solid #e6efeb; }
    .activity-field { min-width:0; }
    .activity-field label { display:block; margin:0 0 6px; color:#52656e; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.055em; }
    .activity-input-wrap { position:relative; }
    .activity-control-icon { position:absolute; left:11px; top:50%; transform:translateY(-50%); display:grid; color:#71858c; pointer-events:none; }
    .activity-control-icon svg { width:17px; height:17px; }
    .activity-filters input, .activity-filters select { width:100%; height:42px; padding:0 11px; border:1px solid #d8e6e0 !important; border-radius:12px !important; background-color:#fff !important; font:500 13px Inter,sans-serif; }
    .activity-filters input:focus, .activity-filters select:focus { border-color:#75aa98 !important; box-shadow:0 0 0 3px rgba(43,122,102,.12) !important; }
    .activity-input-wrap input { padding-left:39px !important; background-image:none !important; }
    .activity-filter-actions { display:flex; gap:8px; }
    .activity-button { height:42px; border-radius:10px; border:0; padding:0 15px; display:inline-flex; align-items:center; justify-content:center; gap:7px; font:700 13px Inter,sans-serif; cursor:pointer; text-decoration:none; white-space:nowrap; }
    .activity-button svg { width:17px; height:17px; fill:none; stroke:currentColor; stroke-width:2; }
    .activity-button.apply { color:#fff; background:linear-gradient(135deg,#2b7a66,#236552); box-shadow:0 7px 14px rgba(29,93,74,.15); }
    .activity-button.clear { color:#3e5962; background:#fff; border:1px solid #d8e6e0; padding:0 12px; }
    .activity-table-wrap { overflow-x:auto; }
    .activity-table { min-width:920px; border:0 !important; border-radius:0 !important; }
    .activity-table th { padding:12px 15px !important; background:#f5faf7 !important; color:#5f727a !important; font-size:10.5px; text-transform:uppercase; letter-spacing:.065em; }
    .activity-table td { padding:14px 15px !important; color:#2a4049; font-size:12.5px; vertical-align:middle; }
    .activity-table tbody tr:last-child td { border-bottom:0 !important; }
    .activity-actor { display:flex; align-items:center; gap:10px; min-width:180px; }
    .activity-avatar { width:36px; height:36px; border-radius:11px; flex:0 0 36px; display:grid; place-items:center; overflow:hidden; color:#fff; background:#2b7a66; font-size:13px; font-weight:800; }
    .activity-avatar img { display:block; width:100%; height:100%; object-fit:cover; border-radius:inherit; }
    .activity-avatar.tourist { background:#3478a3; } .activity-avatar.tour-operator { background:#8a642f; } .activity-avatar.hotel-owner { background:#7351a1; }
    .activity-actor strong { display:block; color:#20343c; font-size:13px; }
    .activity-role { display:inline-flex; margin-top:4px; padding:3px 7px; border-radius:999px; background:#edf6f2; color:#25644f; font-size:9.5px; font-weight:800; text-transform:uppercase; letter-spacing:.04em; }
    .activity-role.tourist { color:#285f80; background:#edf6fb; } .activity-role.tour-operator { color:#76531e; background:#fbf5e9; } .activity-role.hotel-owner { color:#61418b; background:#f5effb; }
    .activity-action { display:flex; align-items:center; gap:8px; }
    .activity-row-icon { width:31px; height:31px; border-radius:9px; flex:0 0 31px; display:grid; place-items:center; color:#226b55; background:#eaf6f1; }
    .activity-row-icon svg { width:16px; height:16px; }
    .activity-row-icon.danger { color:#aa3543; background:#fcecef; } .activity-row-icon.info { color:#2c6f95; background:#eaf4fa; } .activity-row-icon.neutral { color:#63727a; background:#eff2f3; }
    .action-badge { padding:5px 8px; border-radius:7px; color:#246b55; background:#edf7f3; font-weight:800; white-space:nowrap; }
    .action-badge.danger { color:#ab3342; background:#fdecef; } .action-badge.info { color:#2b6d92; background:#eaf4fa; } .action-badge.neutral { color:#5e6e76; background:#eef2f3; }
    .activity-description { max-width:390px; line-height:1.45; color:#40565f; }
    .activity-module { display:block; margin-top:3px; color:#839198; font-size:10.5px; font-weight:600; }
    .activity-ip { font-family:ui-monospace,SFMono-Regular,Consolas,monospace; color:#62747c; white-space:nowrap; }
    .activity-date { white-space:nowrap; color:#344b54; font-weight:600; }
    .activity-time { display:block; margin-top:3px; color:#829097; font-size:11px; font-weight:500; }
    .activity-footer { padding:13px 16px; border-top:1px solid #e6efeb; display:flex; align-items:center; justify-content:space-between; gap:15px; color:#697b83; font-size:12px; }
    .activity-pagination { display:flex; align-items:center; gap:5px; }
    .activity-page { min-width:34px; height:34px; padding:0 9px; border:1px solid #d8e6e0; border-radius:9px; display:grid; place-items:center; color:#405b64; background:#fff; text-decoration:none; font-weight:700; }
    .activity-page.active { color:#fff; border-color:#2b7a66; background:#2b7a66; }
    .activity-page.disabled { opacity:.45; pointer-events:none; }
    .activity-empty { padding:65px 20px; text-align:center; color:#71828a; }
    .activity-empty-icon { width:58px; height:58px; margin:0 auto 14px; border-radius:17px; display:grid; place-items:center; color:#2b7a66; background:#edf7f3; }
    .activity-empty svg { width:27px; height:27px; }
    .activity-empty h3 { margin:0 0 6px; color:#29434c; font-size:16px; }
    .activity-empty p { margin:0; font-size:13px; }
    @media (max-width:1250px) { .activity-filters { grid-template-columns:repeat(3,1fr); } .activity-search { grid-column:span 2; } }
    @media (max-width:800px) { .main-content,.admin-sidebar.collapsed ~ .main-content { margin-left:0 !important; } .activity-header { align-items:flex-start; } .activity-filters { grid-template-columns:1fr 1fr; } .activity-search { grid-column:1/-1; } .activity-filter-actions { grid-column:1/-1; } .activity-filter-actions .apply { flex:1; } .activity-footer { align-items:flex-start; flex-direction:column; } }
    @media (max-width:520px) { .activity-filters { grid-template-columns:1fr; } .activity-search,.activity-filter-actions { grid-column:auto; } .activity-count { display:none; } }
  </style>
</head>
<body>
<div class="admin-container">
  <?php include 'admin_sidebar.php'; ?>
  <main class="main-content">
    <header class="admin-header admin-page-header activity-header">
      <div class="admin-header-left admin-page-title activity-title">
        <span class="admin-page-title-icon activity-title-icon" aria-hidden="true">
          <svg viewBox="0 0 24 24"><path d="M12 8v4l2.5 1.5"/><circle cx="12" cy="12" r="8.5"/><path d="M4.2 4.2 2.5 6M19.8 4.2 21.5 6"/></svg>
        </span>
        <div class="admin-page-title-copy">
          <h2>Activity Logs</h2>
          <p class="admin-header-subtitle">Track administrator actions and security events</p>
        </div>
      </div>
      <div class="admin-header-right">
        <div class="activity-count"><?= number_format($totalLogs) ?> <?= $totalLogs === 1 ? 'record' : 'records' ?></div>
      </div>
    </header>

    <div class="dashboard-content">
      <section class="activity-panel">
        <form class="activity-filters" method="get" action="adactivitylog.php">
          <div class="activity-field activity-search">
            <label for="search">Search activity</label>
            <div class="activity-input-wrap">
              <span class="activity-control-icon"><svg viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><path d="m20 20-4-4"/></svg></span>
              <input id="search" name="search" value="<?= activityEscape($search) ?>" placeholder="Actor, action, description or IP">
            </div>
          </div>
          <div class="activity-field">
            <label for="actor_type">Actor / role</label>
            <select id="actor_type" name="actor_type">
              <option value="">All roles</option>
              <?php foreach ($allowedActorTypes as $role): ?>
                <option value="<?= activityEscape($role) ?>" <?= $actorType === $role ? 'selected' : '' ?>><?= activityEscape($role) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="activity-field">
            <label for="log_action">Action</label>
            <select id="log_action" name="log_action">
              <option value="">All actions</option>
              <?php foreach ($actions as $actionName): ?>
                <option value="<?= activityEscape($actionName) ?>" <?= $selectedAction === $actionName ? 'selected' : '' ?>><?= activityEscape($actionName) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="activity-field">
            <label for="date_from">From date</label>
            <input id="date_from" type="date" name="date_from" value="<?= activityEscape($dateFrom) ?>">
          </div>
          <div class="activity-field">
            <label for="date_to">To date</label>
            <input id="date_to" type="date" name="date_to" value="<?= activityEscape($dateTo) ?>">
          </div>
          <div class="activity-filter-actions">
            <button class="activity-button apply" type="submit">
              <svg viewBox="0 0 24 24"><path d="M4 5h16l-6 7v5l-4 2v-7Z"/></svg> Apply
            </button>
            <?php if ($hasFilters): ?>
              <a class="activity-button clear" href="adactivitylog.php" title="Clear filters" aria-label="Clear filters">
                <svg viewBox="0 0 24 24"><path d="M6 6l12 12M18 6 6 18"/></svg>
              </a>
            <?php endif; ?>
          </div>
        </form>

        <?php if (!$logs): ?>
          <div class="activity-empty">
            <div class="activity-empty-icon"><svg viewBox="0 0 24 24"><path d="M9 5h6M9 9h6M9 13h4"/><rect x="5" y="2.5" width="14" height="19" rx="2"/></svg></div>
            <h3><?= $hasFilters ? 'No matching activity' : 'No activity recorded yet' ?></h3>
            <p><?= $hasFilters ? 'Try changing or clearing the current filters.' : 'Important account and management actions will appear here.' ?></p>
          </div>
        <?php else: ?>
          <div class="activity-table-wrap">
            <table class="activity-table">
              <thead><tr><th>Actor</th><th>Action</th><th>Description</th><th>IP address</th><th>Date &amp; time</th></tr></thead>
              <tbody>
              <?php foreach ($logs as $log):
                  $roleClass = strtolower(str_replace(' ', '-', (string)$log['actor_type']));
                  $tone = activityTone((string)$log['action']);
                  $initial = mb_strtoupper(mb_substr(trim((string)$log['actor_name']) ?: '?', 0, 1));
                  $profileImage = $log['actor_type'] === 'Tourist'
                      ? activityProfileImage($log['actor_profile_picture'] ?? null)
                      : '';
              ?>
                <tr>
                  <td>
                    <div class="activity-actor">
                      <span class="activity-avatar <?= activityEscape($roleClass) ?>" data-initial="<?= activityEscape($initial) ?>">
                        <?php if ($profileImage !== ''): ?>
                          <img src="<?= activityEscape($profileImage) ?>" alt="<?= activityEscape($log['actor_name']) ?> profile" loading="lazy" onerror="this.parentElement.textContent=this.parentElement.dataset.initial;">
                        <?php else: ?>
                          <?= activityEscape($initial) ?>
                        <?php endif; ?>
                      </span>
                      <span>
                        <strong><?= activityEscape($log['actor_name']) ?></strong>
                        <span class="activity-role <?= activityEscape($roleClass) ?>"><?= activityEscape($log['actor_type']) ?></span>
                      </span>
                    </div>
                  </td>
                  <td>
                    <div class="activity-action">
                      <span class="activity-row-icon <?= activityEscape($tone) ?>"><svg viewBox="0 0 24 24"><path d="M12 3v4M12 17v4M3 12h4M17 12h4"/><circle cx="12" cy="12" r="4"/></svg></span>
                      <span class="action-badge <?= activityEscape($tone) ?>"><?= activityEscape($log['action']) ?></span>
                    </div>
                  </td>
                  <td class="activity-description">
                    <?= activityEscape($log['description']) ?>
                    <span class="activity-module"><?= activityEscape($log['module']) ?><?= $log['reference_id'] ? ' · #' . (int)$log['reference_id'] : '' ?></span>
                  </td>
                  <td class="activity-ip"><?= activityEscape($log['ip_address'] ?: 'unknown') ?></td>
                  <td class="activity-date">
                    <?= activityEscape(date('M j, Y', strtotime((string)$log['created_at']))) ?>
                    <span class="activity-time"><?= activityEscape(date('g:i:s A', strtotime((string)$log['created_at']))) ?></span>
                  </td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <footer class="activity-footer">
            <span>Showing <?= number_format($firstShown) ?>–<?= number_format($lastShown) ?> of <?= number_format($totalLogs) ?></span>
            <?php if ($totalPages > 1): ?>
              <nav class="activity-pagination" aria-label="Activity log pages">
                <a class="activity-page <?= $page <= 1 ? 'disabled' : '' ?>" href="<?= activityEscape(activityPageUrl($page - 1)) ?>" aria-label="Previous page">‹</a>
                <?php
                  $startPage = max(1, $page - 2);
                  $endPage = min($totalPages, $page + 2);
                  for ($i = $startPage; $i <= $endPage; $i++):
                ?>
                  <a class="activity-page <?= $i === $page ? 'active' : '' ?>" href="<?= activityEscape(activityPageUrl($i)) ?>"><?= $i ?></a>
                <?php endfor; ?>
                <a class="activity-page <?= $page >= $totalPages ? 'disabled' : '' ?>" href="<?= activityEscape(activityPageUrl($page + 1)) ?>" aria-label="Next page">›</a>
              </nav>
            <?php endif; ?>
          </footer>
        <?php endif; ?>
      </section>
    </div>
  </main>
</div>
<script>
window.addEventListener('pageshow', function (event) {
  if (event.persisted) window.location.reload();
});
</script>
</body>
</html>
