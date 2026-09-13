<?php
chdir(__DIR__ . '/..');
require_once __DIR__ . '/../php/session_security.php';
AppSessionStart();
require 'php/db_connection.php'; // $pdo is defined here
require_once __DIR__ . '/../php/activity_logger.php';
require_once __DIR__ . '/../php/admin_auth_helper.php';
require_once __DIR__ . '/../php/input_validation.php';

// Initialize alert variable
$alert = '';

// =============================
// LOGOUT ACTION
// =============================
if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    AppDestroySession();
    $alert = "Swal.fire({
        icon: 'info',
        title: 'Logged Out',
        text: 'You have been logged out.',
        confirmButtonColor: '#2B7066',
        confirmButtonText: 'OK'
    }).then(() => {
        window.location.href='homepage.php';
    });";
}

// =============================
// AUTH CHECK
// =============================
AdminRequireLogin();
$adminTouristCsrf = AppCsrfToken('admin', 'tourist_management');

// Disable Back Button Cache
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

// =============================
// BACKEND: BAN TOURIST
// =============================
if (isset($_POST['action']) && $_POST['action'] === 'ban_tourist') {
    if (!AppVerifyCsrf('admin', 'tourist_management', $_POST['csrf_token'] ?? null)) {
        http_response_code(403);
        exit('Invalid or expired request token.');
    }
    try {
        $id = ItourValidationInt($_POST['tourist_id'] ?? null, 'Tourist ID', 1, PHP_INT_MAX);
        $note = ItourValidationText($_POST['ban_note'] ?? null, 'Ban note', 1000, true);
    } catch (InvalidArgumentException $exception) {
        http_response_code(422);
        exit($exception->getMessage());
    }
    $exists = $pdo->prepare('SELECT 1 FROM tourist WHERE tourist_id = ? LIMIT 1');
    $exists->execute([$id]);
    if (!$exists->fetchColumn()) { http_response_code(404); exit('Tourist not found.'); }

    $stmt = $pdo->prepare("UPDATE tourist SET status='banned', ban_note=:note WHERE tourist_id=:id");
    $stmt->execute([':note' => $note, ':id' => $id]);
    logActivity(
        $pdo, 'Admin', (int)($_SESSION['admin_id'] ?? 0),
        (string)($_SESSION['admin_name'] ?? 'Administrator'),
        'Tourist Banned', 'Banned tourist account #' . (int)$id . '.', 'Tourists', (int)$id
    );

    $alert = "Swal.fire({
        icon: 'success',
        title: 'Tourist Banned',
        text: 'The tourist has been successfully banned.',
        confirmButtonColor: '#2B7066',
        confirmButtonText: 'OK'
    }).then(() => {
        window.location.href='adtourists.php';
    });";
}

// =============================
// BACKEND: UNBAN TOURIST
// =============================
if (isset($_POST['action']) && $_POST['action'] === 'unban_tourist') {
    if (!AppVerifyCsrf('admin', 'tourist_management', $_POST['csrf_token'] ?? null)) {
        http_response_code(403);
        exit('Invalid or expired request token.');
    }
    try {
        $id = ItourValidationInt($_POST['tourist_id'] ?? null, 'Tourist ID', 1, PHP_INT_MAX);
    } catch (InvalidArgumentException $exception) {
        http_response_code(422);
        exit($exception->getMessage());
    }
    $exists = $pdo->prepare('SELECT 1 FROM tourist WHERE tourist_id = ? LIMIT 1');
    $exists->execute([$id]);
    if (!$exists->fetchColumn()) { http_response_code(404); exit('Tourist not found.'); }

    $stmt = $pdo->prepare("UPDATE tourist SET status='active', ban_note=NULL WHERE tourist_id=:id");
    $stmt->execute([':id' => $id]);
    logActivity(
        $pdo, 'Admin', (int)($_SESSION['admin_id'] ?? 0),
        (string)($_SESSION['admin_name'] ?? 'Administrator'),
        'Tourist Reactivated', 'Reactivated tourist account #' . (int)$id . '.', 'Tourists', (int)$id
    );

    $alert = "Swal.fire({
        icon: 'success',
        title: 'Tourist Unbanned',
        text: 'The tourist has been successfully unbanned.',
        confirmButtonColor: '#2B7066',
        confirmButtonText: 'OK'
    }).then(() => {
        window.location.href='adtourists.php';
    });";
}



// =============================
// FETCH ALL TOURISTS
// =============================
// Handle sorting
$sortBy = $_GET['sort_by'] ?? 'date';
$sortQuery = "ORDER BY tourist_id DESC"; // default: newest first

if ($sortBy === 'name') {
    $sortQuery = "ORDER BY full_name ASC";
}

// Fetch tourists with sorting and completed bookings count
$stmt = $pdo->prepare("
    SELECT t.*, 
        (SELECT COUNT(*)
         FROM bookings all_bookings
         WHERE all_bookings.tourist_id = t.tourist_id) AS total_count,
        (SELECT COUNT(*) 
         FROM bookings b 
         WHERE b.tourist_id = t.tourist_id 
           AND b.is_complete = 'completed') AS completed_count
    FROM tourist t
    $sortQuery
");

$stmt->execute();
$tourists = $stmt->fetchAll(PDO::FETCH_ASSOC);

$touristSummary = [
    'total' => count($tourists),
    'active' => count(array_filter($tourists, static fn($tourist) => ($tourist['status'] ?? 'active') === 'active')),
    'banned' => count(array_filter($tourists, static fn($tourist) => ($tourist['status'] ?? '') === 'banned')),
    'verified' => count(array_filter($tourists, static fn($tourist) => (int)($tourist['email_verified'] ?? 0) === 1)),
];

function normalizeGoogleProfileImage(?string $value, int $size = 96): string
{
    $candidate = trim((string)$value);
    if ($candidate === '') {
        return '';
    }
    if (strpos($candidate, '//') === 0) {
        $candidate = 'https:' . $candidate;
    }
    if (!preg_match('~^https?://~i', $candidate)) {
        return $candidate;
    }
    if (stripos($candidate, 'profiles.google.com') !== false
        && preg_match('#profiles\\.google\\.com/(?:s2/photos/profile/)?([^/?#]+)(?:/picture)?#i', $candidate, $m)) {
        return 'https://profiles.google.com/' . rawurlencode($m[1]) . '/picture?sz=' . $size;
    }
    if (stripos($candidate, 'google.com/s2/photos/profile') !== false) {
        $candidate = preg_replace('/([?&])sz=\\d+/i', '$1sz=' . $size, $candidate);
        if (!preg_match('/[?&]sz=/i', $candidate)) {
            $candidate .= (strpos($candidate, '?') !== false ? '&' : '?') . 'sz=' . $size;
        }
        return $candidate;
    }
    if (stripos($candidate, 'googleusercontent.com') !== false) {
        $candidate = preg_replace('/([?&])sz=\\d+/i', '$1sz=' . $size, $candidate);
        $candidate = preg_replace('/=s\\d+-c(?=$|[?&#])/i', '=s' . $size . '-c', $candidate);
        $candidate = preg_replace('/=s\\d+(?=$|[?&#])/i', '=s' . $size, $candidate);
    }
    return $candidate;
}

function resolveTouristProfileImage(?string $value, string $fallback = 'img/profileicon.png'): string
{
    $value = trim((string)$value);
    if ($value === '') {
        return $fallback;
    }
    if (preg_match('~^https?://~i', $value) || strpos($value, '//') === 0) {
        return normalizeGoogleProfileImage($value, 96);
    }

    $clean = ltrim(str_replace('\\', '/', $value), '/');
    $base = basename($clean);
    $candidates = [
        $clean,
        'uploads/profile_pictures/' . $base,
        'uploads/profile_picture/' . $base,
        'uploads/profile/' . $base,
        'img/' . $base
    ];

    foreach ($candidates as $candidate) {
        $full = __DIR__ . '/../' . str_replace('/', DIRECTORY_SEPARATOR, $candidate);
        if (is_file($full)) {
            return $candidate;
        }
    }

    return $fallback;
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>iTour Mercedes - Admin Panel</title>
<link rel="icon" type="image/png" href="img/newlogo.png" />
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet" />
<!-- SweetAlert2 -->
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
document.addEventListener('DOMContentLoaded', () => {
    <?php if(!empty($alert)) echo $alert; ?>
});
</script>




<style>
/* Your CSS styles (same as the one you sent) */
body{margin:0;font-family:'Segoe UI',sans-serif;background:#f5f7fa;}
.admin-container{display:flex;min-height:100vh;}
.main-content{flex:1;margin-left:240px;transition:0.3s;}
.admin-header{padding:18px 30px;background:white;border-bottom:2px solid #eee;display:flex;align-items:center;}
.admin-header h2{color:#2b7a66;font-size:1.6rem;margin:0;}
.card{margin:30px;padding:20px;background:white;border-radius:10px;box-shadow:0 3px 10px rgba(0,0,0,0.08);}
table{width:100%;border-collapse:collapse;}
thead th{text-align:left;padding:12px;background:#2b7a66;font-weight:600;color:#fff;}
tbody td{padding:15px 12px;border-bottom:1px solid #eee;vertical-align:middle;}
.profile-cell{display:flex;align-items:center;gap:10px;}
.profile-img{width:55px;height:55px;border-radius:50%;object-fit:cover;border:2px solid #ddd;}
.profile-name{font-weight:600;color:#333;}
.profile-email{font-size:0.9rem;color:#777;}
.pill{padding:6px 14px;border-radius:20px;font-size:0.85rem;font-weight:600;}
.accepted{background:#d4f2e2;color:#2d7a53;}
.declined{background:#ffd3d3;color:#b72b2b;}
.action-btn{padding:8px 14px;border:none;border-radius:6px;cursor:pointer;font-weight:600;}
.accept{background:#49A47A;color:white;}
.decline{background:#d9534f;color:white;}
/* MODAL IMPROVED */
.decision-modal-overlay {
    position: fixed;
    top: 0; left: 0;
    width: 100%; height: 100%;
    background: rgba(0,0,0,0.5);
    display: none;
    justify-content: center;
    align-items: center;
    z-index: 1000;
    padding: 15px; /* added padding for smaller screens */
}
.decision-modal-overlay.show { display: flex; }

.decision-modal {
    width: 100%;
    max-width: 480px; /* slightly wider for better spacing */
    background: #fff;
    border-radius: 12px;
    overflow: hidden;
    animation: fadeIn 0.25s ease-out;
    box-shadow: 0 6px 20px rgba(0,0,0,0.15);
}

.decision-modal-header {
    background: #49A47A;
    color: #fff;
    padding: 18px 20px; /* more breathing room */
    font-size: 1.25rem;
    display: flex;
    justify-content: space-between;
    align-items: center;
    border-top-left-radius: 12px;
    border-top-right-radius: 12px;
}


.decision-modal-close {
    background: none;
    border: none;
    color: #fff;
    font-size: 1.5rem;
    cursor: pointer;
    margin-left: 10px; /* prevent sticking to edge */
}

.decision-modal-body {
    padding: 25px 20px; /* more spacing */
}

.decision-label {
    font-weight: 600;
    display: block;
    margin-bottom: 8px;
}

.decision-input,
.decision-textarea {
    width: 100%;
    padding: 12px;
    border: 1px solid #ccc;
    border-radius: 8px;
    margin-bottom: 15px;
    font-size: 1rem;
    box-sizing: border-box;
}

.decision-textarea {
    height: 100px;
    resize: vertical;
}

.decision-modal-footer {
    padding: 15px 20px;
    display: flex;
    gap: 10px;
    justify-content: flex-end;
    border-top: 1px solid #eee;
}

.decision-btn {
    padding: 10px 16px;
    border: none;
    border-radius: 8px;
    cursor: pointer;
    font-weight: 600;
    font-size: 0.95rem;
    transition: background 0.2s;
}

.decision-btn.cancel {
    background: #ddd;
}
.decision-btn.cancel:hover { background: #ccc; }

.decision-btn.ok {
    background: #49A47A;
    color: #fff;
}
.decision-btn.ok:hover { background: #3d8f69; }

@keyframes fadeIn {
    from { opacity: 0; transform: scale(0.95); }
    to { opacity: 1; transform: scale(1); }
}

/* Responsive modal for mobile */
@media(max-width: 500px){
    .decision-modal { max-width: 95%; }
    .decision-modal-header { font-size: 1.1rem; }
}
</style>
<link rel="stylesheet" href="styles/admin_panel_theme.css" />
<link rel="stylesheet" href="styles/adtourists.css" />
</head>

<body>

<div class="admin-container">
    <?php include 'admin_sidebar.php'; ?>

    <main class="main-content">
        <header class="admin-header admin-page-header tourists-header">
            <div class="admin-header-left admin-page-title tourists-title">
                <span class="admin-page-title-icon tourists-title-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24"><path d="M16 20v-1.8a4.2 4.2 0 0 0-4.2-4.2H6.2A4.2 4.2 0 0 0 2 18.2V20"></path><circle cx="9" cy="7" r="4"></circle><path d="M17 11a4 4 0 0 0 0-8M22 20v-1.8a4.2 4.2 0 0 0-3-4"></path></svg>
                </span>
                <div class="admin-page-title-copy">
                    <h2>Tourists</h2>
                    <p class="admin-header-subtitle">Review registered accounts, contact details, and access status</p>
                </div>
            </div>
        </header>

        <section class="tourist-summary" aria-label="Tourist account summary">
            <button type="button" class="tourist-stat-card is-selected" data-tourist-filter="all" aria-pressed="true">
                <span class="summary-icon total"><svg viewBox="0 0 24 24"><path d="M16 20v-1.5a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4V20"></path><circle cx="9" cy="7" r="4"></circle><path d="M22 20v-1.5a4 4 0 0 0-3-3.8M16 3.2a4 4 0 0 1 0 7.6"></path></svg></span>
                <div><small>Tourist network</small><strong><?= number_format($touristSummary['total']) ?></strong><span>Total tourists</span></div><i>View all</i>
            </button>
            <button type="button" class="tourist-stat-card" data-tourist-filter="active" aria-pressed="false">
                <span class="summary-icon active"><svg viewBox="0 0 24 24"><path d="m5 12 4 4L19 6"></path></svg></span>
                <div><small>Account access</small><strong><?= number_format($touristSummary['active']) ?></strong><span>Active accounts</span></div><i>Filter</i>
            </button>
            <button type="button" class="tourist-stat-card" data-tourist-filter="verified" aria-pressed="false">
                <span class="summary-icon verified"><svg viewBox="0 0 24 24"><path d="M12 3 4.5 6v5.5c0 4.8 3.2 8 7.5 9.5 4.3-1.5 7.5-4.7 7.5-9.5V6L12 3Z"></path><path d="m9 12 2 2 4-4"></path></svg></span>
                <div><small>Identity assurance</small><strong><?= number_format($touristSummary['verified']) ?></strong><span>Verified emails</span></div><i>Filter</i>
            </button>
            <button type="button" class="tourist-stat-card" data-tourist-filter="restricted" aria-pressed="false">
                <span class="summary-icon restricted"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"></circle><path d="m8 8 8 8"></path></svg></span>
                <div><small>Access review</small><strong><?= number_format($touristSummary['banned']) ?></strong><span>Restricted accounts</span></div><i>Filter</i>
            </button>
        </section>

        <!-- TABLE CARD -->
        <div class="card tourist-card">
            <div class="tourist-card-head">
                <div class="tourist-directory-copy"><h3>Tourist Directory</h3></div>
                <div class="tourists-toolbar">
                    <form method="GET" id="sortForm" class="tourists-sort">
                        <label for="sortSelect" class="sort-label">Sort by</label>
                        <select name="sort_by" id="sortSelect" onchange="document.getElementById('sortForm').submit()">
                            <option value="date" <?= ($sortBy ?? 'date') === 'date' ? 'selected' : '' ?>>Date Created</option>
                            <option value="name" <?= ($sortBy ?? '') === 'name' ? 'selected' : '' ?>>Name (A-Z)</option>
                        </select>
                    </form>
                    <label class="tourist-toolbar-select" for="touristStatusFilter">
                        <span>Status</span>
                        <select id="touristStatusFilter">
                            <option value="all">All statuses</option>
                            <option value="active">Active</option>
                            <option value="restricted">Restricted</option>
                            <option value="verified">Verified email</option>
                            <option value="unverified">Unverified email</option>
                        </select>
                    </label>
                    <label class="tourist-toolbar-select tourist-rows-select" for="touristRowsPerPage">
                        <span>Rows</span>
                        <select id="touristRowsPerPage">
                            <option value="10">10</option>
                            <option value="25" selected>25</option>
                            <option value="50">50</option>
                            <option value="100">100</option>
                        </select>
                    </label>
                    <label class="tourist-search">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"></circle><path d="m20 20-4-4"></path></svg>
                        <input type="search" id="searchTourist" placeholder="Search tourists..." autocomplete="off">
                    </label>
                    <span id="visibleTouristCount"><?= number_format($touristSummary['total']) ?> records</span>
                </div>
            </div>
            <div class="tourist-table-scroll">
            <table id="touristTable">
                <thead>
                    <tr>
                        <th>Tourist</th>
                        <th>Contact</th>
                        <th>Location</th>
                        <th>Account</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($tourists as $touristIndex => $t): ?>
                    <?php
                        $profileImage = resolveTouristProfileImage($t['profile_picture'] ?? null);
                        $status = ($t['status'] ?? 'active') === 'banned' ? 'banned' : 'active';
                    ?>
                    <tr class="tourist-row" data-account-status="<?= htmlspecialchars($status) ?>" data-email-verified="<?= (int)($t['email_verified'] ?? 0) ?>"<?= $touristIndex >= 25 ? ' hidden' : '' ?>>
                        <td class="tourist-cell">
                            <div class="profile-cell">
                                <img src="<?= htmlspecialchars($profileImage) ?>"
                                    class="profile-img tourist-profile-trigger"
                                    alt=""
                                    loading="lazy"
                                    decoding="async"
                                    referrerpolicy="no-referrer"
                                    role="button"
                                    tabindex="0"
                                    aria-label="Open full profile for <?= htmlspecialchars($t['full_name']) ?>"
                                    data-tourist-id="<?= (int)$t['tourist_id'] ?>"
                                    data-fullname="<?= htmlspecialchars($t['full_name']) ?>"
                                    data-email="<?= htmlspecialchars($t['email']) ?>"
                                    data-phone="<?= htmlspecialchars($t['phone_number'] ?: '-') ?>"
                                    data-address="<?= htmlspecialchars($t['address'] ?: '-') ?>"
                                    data-total-bookings="<?= (int)($t['total_count'] ?? 0) ?>"
                                    data-completed-bookings="<?= (int)($t['completed_count'] ?? 0) ?>"
                                    data-account-status="<?= htmlspecialchars($status) ?>"
                                    data-email-verified="<?= (int)($t['email_verified'] ?? 0) ?>"
                                    data-google-connected="<?= !empty($t['google_id']) ? '1' : '0' ?>"
                                    data-created-at="<?= htmlspecialchars($t['created_at'] ?? '') ?>"
                                    data-updated-at="<?= htmlspecialchars($t['updated_at'] ?? '') ?>"
                                    data-ban-note="<?= htmlspecialchars($t['ban_note'] ?? '') ?>">
                                <div class="profile-copy">
                                    <div class="profile-name-line">
                                        <div class="profile-name"><?= htmlspecialchars($t['full_name']) ?></div>
                                        <span class="tourist-id">ID #<?= (int)$t['tourist_id'] ?></span>
                                    </div>
                                    <div class="profile-email"><?= htmlspecialchars($t['email']) ?></div>
                                    <div class="booking-count"><?= (int)$t['completed_count'] ?> completed booking<?= (int)$t['completed_count'] === 1 ? '' : 's' ?></div>
                                </div>
                            </div>
                        </td>

                        <td>
                            <div class="table-detail">
                                <span>Phone number</span>
                                <strong><?= htmlspecialchars($t['phone_number'] ?: 'Not provided') ?></strong>
                            </div>
                        </td>
                        <td>
                            <div class="table-detail location-detail">
                                <span>Home address</span>
                                <strong><?= htmlspecialchars($t['address'] ?: 'Not provided') ?></strong>
                            </div>
                        </td>
                        <td>
                            <span class="status-pill <?= $status ?>"><i></i><?= $status === 'banned' ? 'Banned' : 'Active' ?></span>
                            <small class="verification-state <?= (int)($t['email_verified'] ?? 0) === 1 ? 'verified' : '' ?>"><?= (int)($t['email_verified'] ?? 0) === 1 ? 'Verified email' : 'Email unverified' ?></small>
                        </td>
                        <td class="tourist-actions">
                            <button type="button" class="view-profile-btn" data-profile-for="<?= (int)$t['tourist_id'] ?>">
                                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M2.5 12s3.5-6 9.5-6 9.5 6 9.5 6-3.5 6-9.5 6-9.5-6-9.5-6Z"></path><circle cx="12" cy="12" r="2.5"></circle></svg>
                                View
                            </button>
                            <?php if($status === 'active'): ?>
                                <button type="button" class="account-action restrict banBtn" data-id="<?= (int)$t['tourist_id'] ?>" data-name="<?= htmlspecialchars($t['full_name']) ?>">Restrict</button>
                            <?php else: ?>
                                <button type="button" class="account-action restore unbanBtn" data-id="<?= (int)$t['tourist_id'] ?>" data-name="<?= htmlspecialchars($t['full_name']) ?>">Restore</button>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <div class="tourist-empty-state" id="touristEmptyState" hidden>
                <span>No matching tourists</span>
                <p>Try a different name, email, phone number, or address.</p>
            </div>
            </div>
            <nav class="tourist-pagination" id="touristPagination" aria-label="Tourist directory pagination">
                <p id="touristPaginationSummary" aria-live="polite"><?= $touristSummary['total'] > 0 ? 'Showing 1&ndash;' . number_format(min(25, $touristSummary['total'])) . ' out of ' . number_format($touristSummary['total']) : 'Showing 0 out of 0' ?></p>
                <div class="tourist-pagination-controls">
                    <button type="button" id="touristPreviousPage" class="tourist-page-arrow" aria-label="Previous page">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m15 18-6-6 6-6"></path></svg>
                    </button>
                    <div id="touristPageNumbers" class="tourist-page-numbers" aria-label="Page numbers"></div>
                    <button type="button" id="touristNextPage" class="tourist-page-arrow" aria-label="Next page">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m9 18 6-6-6-6"></path></svg>
                    </button>
                </div>
            </nav>
        </div>
    </main>
</div>

<div id="touristProfileDrawer" class="tourist-profile-overlay" aria-hidden="true">
    <aside class="tourist-profile-drawer" role="dialog" aria-modal="true" aria-labelledby="touristProfileTitle">
        <header class="tourist-profile-header">
            <div class="tourist-profile-brand">
                <img src="img/newlogo.png" alt="">
                <div><span>ITOUR MERCEDES</span><h3 id="touristProfileTitle">Tourist Profile</h3></div>
            </div>
            <button type="button" class="tourist-profile-close" aria-label="Close tourist profile">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m6 6 12 12M18 6 6 18"></path></svg>
            </button>
        </header>
        <div class="tourist-profile-body" id="touristProfileContent"></div>
        <footer class="tourist-profile-footer">
            <button type="button" class="tourist-profile-close-btn">Close Profile</button>
        </footer>
    </aside>
</div>


<!-- BAN MODAL -->
<div class="decision-modal-overlay" id="banModal">
    <div class="decision-modal">
        <div class="decision-modal-header">
            <span>Restrict Tourist Account</span>
            <button type="button" class="decision-modal-close" aria-label="Close" onclick="closeBanModal()">&times;</button>
        </div>

        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($adminTouristCsrf, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="action" value="ban_tourist">
            <input type="hidden" name="tourist_id" id="banTouristID">
            <div class="decision-modal-body">
                <label class="decision-label">Tourist</label>
                <input type="text" class="decision-input" id="banTouristName" readonly>
                <label class="decision-label">Reason</label>
                <textarea class="decision-textarea" name="ban_note" id="banNote" required></textarea>
            </div>
            <div class="decision-modal-footer">
                <button type="button" class="decision-btn cancel" onclick="closeBanModal()">Cancel</button>
                <button class="decision-btn ok danger">Restrict Account</button>
            </div>
        </form>
    </div>
</div>

<!-- UNBAN MODAL -->
<div class="decision-modal-overlay" id="unbanModal">
    <div class="decision-modal">
        <div class="decision-modal-header">
            Restore Tourist Account
            <button type="button" class="decision-modal-close" aria-label="Close" onclick="closeUnbanModal()">&times;</button>
        </div>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($adminTouristCsrf, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="action" value="unban_tourist">
            <input type="hidden" name="tourist_id" id="unbanTouristID">
            <div class="decision-modal-body">
                <label class="decision-label">Tourist</label>
                <input type="text" class="decision-input" id="unbanTouristName" readonly>
                <p style="margin-top:10px;color:#666;">Restore access for this tourist account?</p>
            </div>
            <div class="decision-modal-footer">
                <button type="button" class="decision-btn cancel" onclick="closeUnbanModal()">Cancel</button>
                <button class="decision-btn ok">Restore Account</button>
            </div>
        </form>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const escapeProfileValue = value => String(value ?? '-').replace(/[&<>"']/g, character => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'
    })[character]);

    const formatProfileDate = value => {
        if (!value) return 'Not available';
        const parsed = new Date(String(value).replace(' ', 'T'));
        if (Number.isNaN(parsed.getTime())) return value;
        return parsed.toLocaleDateString('en-PH', { year: 'numeric', month: 'long', day: 'numeric' });
    };

    const profileIcons = {
        mail: '<svg viewBox="0 0 24 24"><rect x="3" y="5" width="18" height="14" rx="2"></rect><path d="m4 7 8 6 8-6"></path></svg>',
        phone: '<svg viewBox="0 0 24 24"><path d="M7 3H4a1 1 0 0 0-1 1c0 9.4 7.6 17 17 17a1 1 0 0 0 1-1v-3l-4-2-2 3a15 15 0 0 1-9-9l3-2-2-4Z"></path></svg>',
        map: '<svg viewBox="0 0 24 24"><path d="M20 10c0 5-8 12-8 12S4 15 4 10a8 8 0 1 1 16 0Z"></path><circle cx="12" cy="10" r="2.5"></circle></svg>',
        shield: '<svg viewBox="0 0 24 24"><path d="M12 3 4.5 6v5.5c0 4.8 3.2 8 7.5 9.5 4.3-1.5 7.5-4.7 7.5-9.5V6L12 3Z"></path><path d="m9 12 2 2 4-4"></path></svg>',
        calendar: '<svg viewBox="0 0 24 24"><rect x="3" y="5" width="18" height="16" rx="2"></rect><path d="M16 3v4M8 3v4M3 10h18"></path></svg>'
    };

    const profileTooltip = document.createElement('div');
    profileTooltip.className = 'tourist-profile-tooltip';
    profileTooltip.setAttribute('role', 'tooltip');
    document.body.appendChild(profileTooltip);
    let tooltipTimer;
    let lastProfileTrigger = null;

    const hideProfileTooltip = (immediate = false) => {
        clearTimeout(tooltipTimer);
        const hide = () => profileTooltip.classList.remove('show');
        if (immediate) hide(); else tooltipTimer = setTimeout(hide, 130);
    };

    const positionProfileTooltip = trigger => {
        const triggerRect = trigger.getBoundingClientRect();
        const tooltipRect = profileTooltip.getBoundingClientRect();
        const edge = 12;
        const gap = 9;
        let left = triggerRect.left;
        let top = triggerRect.bottom + gap;
        if (left + tooltipRect.width > window.innerWidth - edge) left = window.innerWidth - tooltipRect.width - edge;
        if (top + tooltipRect.height > window.innerHeight - edge) top = triggerRect.top - tooltipRect.height - gap;
        profileTooltip.style.left = `${Math.max(edge, left)}px`;
        profileTooltip.style.top = `${Math.max(edge, top)}px`;
    };

    const showProfileTooltip = trigger => {
        clearTimeout(tooltipTimer);
        const name = escapeProfileValue(trigger.dataset.fullname || 'Tourist');
        const email = escapeProfileValue(trigger.dataset.email || '-');
        const phone = escapeProfileValue(trigger.dataset.phone || '-');
        const address = escapeProfileValue(trigger.dataset.address || '-');
        const completed = escapeProfileValue(trigger.dataset.completedBookings || 0);
        const profileSrc = escapeProfileValue(trigger.src);
        const emailHref = trigger.dataset.email && trigger.dataset.email !== '-'
            ? `mailto:${encodeURIComponent(trigger.dataset.email)}`
            : '#';

        profileTooltip.innerHTML = `
            <div class="tooltip-accent"></div>
            <div class="tooltip-profile-head">
                <img src="${profileSrc}" alt="">
                <div><small>TOURIST PROFILE</small><strong>${name}</strong><span>${completed} completed booking${Number(trigger.dataset.completedBookings || 0) === 1 ? '' : 's'}</span></div>
            </div>
            <div class="tooltip-profile-details">
                <div>${profileIcons.mail}<span><small>Email address</small><strong>${email}</strong></span></div>
                <div>${profileIcons.phone}<span><small>Phone number</small><strong>${phone}</strong></span></div>
                <div>${profileIcons.map}<span><small>Home address</small><strong>${address}</strong></span></div>
            </div>
            <a href="${emailHref}" class="tooltip-email${emailHref === '#' ? ' is-disabled' : ''}">
                ${profileIcons.mail}<span>Send email</span><b aria-hidden="true">&rarr;</b>
            </a>`;
        profileTooltip.classList.add('show');
        requestAnimationFrame(() => positionProfileTooltip(trigger));
    };

    const openProfileDrawer = trigger => {
        const overlay = document.getElementById('touristProfileDrawer');
        const content = document.getElementById('touristProfileContent');
        if (!overlay || !content || !trigger) return;
        hideProfileTooltip(true);
        lastProfileTrigger = trigger;

        const name = escapeProfileValue(trigger.dataset.fullname || 'Tourist');
        const email = escapeProfileValue(trigger.dataset.email || '-');
        const phone = escapeProfileValue(trigger.dataset.phone || '-');
        const address = escapeProfileValue(trigger.dataset.address || '-');
        const touristId = escapeProfileValue(trigger.dataset.touristId || '-');
        const total = escapeProfileValue(trigger.dataset.totalBookings || 0);
        const completed = escapeProfileValue(trigger.dataset.completedBookings || 0);
        const statusRaw = trigger.dataset.accountStatus || 'active';
        const status = escapeProfileValue(statusRaw.charAt(0).toUpperCase() + statusRaw.slice(1));
        const verified = trigger.dataset.emailVerified === '1';
        const googleConnected = trigger.dataset.googleConnected === '1';
        const joined = escapeProfileValue(formatProfileDate(trigger.dataset.createdAt));
        const updated = escapeProfileValue(formatProfileDate(trigger.dataset.updatedAt));
        const banNote = escapeProfileValue(trigger.dataset.banNote || '');
        const profileSrc = escapeProfileValue(trigger.src);
        const emailHref = trigger.dataset.email && trigger.dataset.email !== '-'
            ? `mailto:${encodeURIComponent(trigger.dataset.email)}`
            : '#';

        content.innerHTML = `
            <section class="tp-hero">
                <img src="${profileSrc}" alt="">
                <div><small>REGISTERED TOURIST</small><h2>${name}</h2><p>Tourist ID: #${touristId}</p>
                    <span class="tp-status ${statusRaw === 'banned' ? 'banned' : ''}">${status}</span>
                    <span class="tp-verified ${verified ? '' : 'warning'}">${verified ? 'Email verified' : 'Email unverified'}</span>
                </div>
            </section>
            <section class="tp-stats">
                <div><strong>${total}</strong><span>Total bookings</span></div>
                <div><strong>${completed}</strong><span>Completed tours</span></div>
                <div><strong>${joined}</strong><span>Member since</span></div>
            </section>
            <section class="tp-section">
                <div class="tp-section-title"><i>${profileIcons.mail}</i><div><h3>Contact information</h3><p>Primary contact details for this tourist</p></div></div>
                <div class="tp-detail-list">
                    <div><i>${profileIcons.mail}</i><span><small>Email address</small><strong>${email}</strong></span></div>
                    <div><i>${profileIcons.phone}</i><span><small>Phone number</small><strong>${phone}</strong></span></div>
                    <div><i>${profileIcons.map}</i><span><small>Home address</small><strong>${address}</strong></span></div>
                </div>
                <a href="${emailHref}" class="tp-email${emailHref === '#' ? ' is-disabled' : ''}">${profileIcons.mail}<span>Send email to tourist</span><b>&rarr;</b></a>
            </section>
            <section class="tp-section">
                <div class="tp-section-title"><i>${profileIcons.shield}</i><div><h3>Account information</h3><p>Registration, verification, and access status</p></div></div>
                <div class="tp-account-grid">
                    <div><small>Account status</small><strong>${status}</strong></div>
                    <div><small>Email verification</small><strong>${verified ? 'Verified' : 'Not verified'}</strong></div>
                    <div><small>Sign-in connection</small><strong>${googleConnected ? 'Google connected' : 'Email and password'}</strong></div>
                    <div><small>Last profile update</small><strong>${updated}</strong></div>
                </div>
            </section>
            <section class="tp-section">
                <div class="tp-section-title"><i>${profileIcons.calendar}</i><div><h3>Profile timeline</h3><p>Account dates recorded by the system</p></div></div>
                <div class="tp-timeline"><div><i></i><span><small>Account created</small><strong>${joined}</strong></span></div><div><i></i><span><small>Information last updated</small><strong>${updated}</strong></span></div></div>
            </section>
            ${banNote ? `<section class="tp-ban-note"><strong>Account restriction note</strong><p>${banNote}</p></section>` : ''}`;

        overlay.classList.add('show');
        overlay.setAttribute('aria-hidden', 'false');
        document.body.classList.add('tourist-profile-open');
        overlay.querySelector('.tourist-profile-close')?.focus();
    };

    const closeProfileDrawer = () => {
        const overlay = document.getElementById('touristProfileDrawer');
        if (!overlay) return;
        overlay.classList.remove('show');
        overlay.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('tourist-profile-open');
        lastProfileTrigger?.focus();
    };

    document.querySelectorAll('.tourist-profile-trigger').forEach(trigger => {
        trigger.addEventListener('mouseenter', () => showProfileTooltip(trigger));
        trigger.addEventListener('mouseleave', () => hideProfileTooltip());
        trigger.addEventListener('click', () => openProfileDrawer(trigger));
        trigger.addEventListener('keydown', event => {
            if (event.key === 'Enter' || event.key === ' ') {
                event.preventDefault();
                openProfileDrawer(trigger);
            }
        });
    });
    profileTooltip.addEventListener('mouseenter', () => clearTimeout(tooltipTimer));
    profileTooltip.addEventListener('mouseleave', () => hideProfileTooltip());
    window.addEventListener('scroll', () => hideProfileTooltip(true), true);
    window.addEventListener('resize', () => hideProfileTooltip(true));

    document.querySelectorAll('.view-profile-btn').forEach(button => {
        button.addEventListener('click', () => {
            const trigger = document.querySelector(`.tourist-profile-trigger[data-tourist-id="${Number(button.dataset.profileFor)}"]`);
            if (trigger) openProfileDrawer(trigger);
        });
    });
    document.querySelector('.tourist-profile-close')?.addEventListener('click', closeProfileDrawer);
    document.querySelector('.tourist-profile-close-btn')?.addEventListener('click', closeProfileDrawer);
    document.getElementById('touristProfileDrawer')?.addEventListener('mousedown', event => {
        if (event.target.id === 'touristProfileDrawer') closeProfileDrawer();
    });

    // SEARCH FUNCTIONALITY
    const searchInput = document.getElementById('searchTourist');
    const table = document.getElementById('touristTable');
    const statusFilter = document.getElementById('touristStatusFilter');
    const rowsPerPageSelect = document.getElementById('touristRowsPerPage');
    const previousPageButton = document.getElementById('touristPreviousPage');
    const nextPageButton = document.getElementById('touristNextPage');
    const pageNumbers = document.getElementById('touristPageNumbers');
    const paginationSummary = document.getElementById('touristPaginationSummary');
    let touristCardFilter = 'all';
    let currentTouristPage = 1;

    const matchesTouristFilter = (row, filter) => filter === 'all'
        || (filter === 'active' && row.dataset.accountStatus === 'active')
        || (filter === 'restricted' && row.dataset.accountStatus === 'banned')
        || (filter === 'verified' && row.dataset.emailVerified === '1')
        || (filter === 'unverified' && row.dataset.emailVerified !== '1');

    const getVisiblePageNumbers = totalPages => {
        if (totalPages <= 5) return Array.from({ length: totalPages }, (_, index) => index + 1);
        if (currentTouristPage <= 3) return [1, 2, 3, 4, 'ellipsis', totalPages];
        if (currentTouristPage >= totalPages - 2) return [1, 'ellipsis', totalPages - 3, totalPages - 2, totalPages - 1, totalPages];
        return [1, 'ellipsis-start', currentTouristPage - 1, currentTouristPage, currentTouristPage + 1, 'ellipsis-end', totalPages];
    };

    const renderTouristPagination = totalMatches => {
        const rowsPerPage = Number(rowsPerPageSelect?.value || 25);
        const totalPages = Math.max(1, Math.ceil(totalMatches / rowsPerPage));
        currentTouristPage = Math.min(Math.max(1, currentTouristPage), totalPages);
        const first = totalMatches === 0 ? 0 : ((currentTouristPage - 1) * rowsPerPage) + 1;
        const last = Math.min(currentTouristPage * rowsPerPage, totalMatches);

        if (paginationSummary) {
            paginationSummary.textContent = totalMatches === 0
                ? 'Showing 0 out of 0'
                : `Showing ${first}\u2013${last} out of ${totalMatches}`;
        }
        if (previousPageButton) previousPageButton.disabled = currentTouristPage === 1 || totalMatches === 0;
        if (nextPageButton) nextPageButton.disabled = currentTouristPage === totalPages || totalMatches === 0;
        if (!pageNumbers) return;

        pageNumbers.replaceChildren();
        if (totalMatches === 0) return;
        getVisiblePageNumbers(totalPages).forEach(page => {
            if (String(page).startsWith('ellipsis')) {
                const ellipsis = document.createElement('span');
                ellipsis.className = 'tourist-page-ellipsis';
                ellipsis.textContent = '\u2026';
                pageNumbers.appendChild(ellipsis);
                return;
            }
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'tourist-page-number';
            button.textContent = page;
            button.setAttribute('aria-label', `Go to page ${page}`);
            if (page === currentTouristPage) {
                button.classList.add('is-current');
                button.setAttribute('aria-current', 'page');
            }
            button.addEventListener('click', () => {
                currentTouristPage = page;
                updateTouristDirectory(false);
            });
            pageNumbers.appendChild(button);
        });
    };

    const updateTouristDirectory = (resetPage = true) => {
        if (!table) return;
        if (resetPage) currentTouristPage = 1;
        const filter = searchInput?.value.trim().toLowerCase() || '';
        const rows = Array.from(table.querySelectorAll('tbody .tourist-row'));
        const selectedStatus = statusFilter?.value || 'all';
        const matchingRows = rows.filter(row => {
            const matchesSearch = row.textContent.toLowerCase().includes(filter);
            return matchesSearch
                && matchesTouristFilter(row, touristCardFilter)
                && matchesTouristFilter(row, selectedStatus);
        });
        const rowsPerPage = Number(rowsPerPageSelect?.value || 25);
        const totalPages = Math.max(1, Math.ceil(matchingRows.length / rowsPerPage));
        currentTouristPage = Math.min(currentTouristPage, totalPages);
        const pageStart = (currentTouristPage - 1) * rowsPerPage;
        const rowsOnPage = new Set(matchingRows.slice(pageStart, pageStart + rowsPerPage));
        rows.forEach(row => {
            row.hidden = !rowsOnPage.has(row);
        });
        const count = document.getElementById('visibleTouristCount');
        const empty = document.getElementById('touristEmptyState');
        if (count) count.textContent = `${matchingRows.length} record${matchingRows.length === 1 ? '' : 's'}`;
        if (empty) empty.hidden = matchingRows.length !== 0;
        renderTouristPagination(matchingRows.length);
    };
    if (searchInput && table) {
        searchInput.addEventListener('input', updateTouristDirectory);
    }
    statusFilter?.addEventListener('change', () => {
        touristCardFilter = 'all';
        document.querySelectorAll('.tourist-stat-card').forEach((item, index) => {
            item.classList.toggle('is-selected', index === 0);
            item.setAttribute('aria-pressed', index === 0 ? 'true' : 'false');
        });
        updateTouristDirectory();
    });
    rowsPerPageSelect?.addEventListener('change', updateTouristDirectory);
    previousPageButton?.addEventListener('click', () => {
        if (currentTouristPage <= 1) return;
        currentTouristPage--;
        updateTouristDirectory(false);
    });
    nextPageButton?.addEventListener('click', () => {
        currentTouristPage++;
        updateTouristDirectory(false);
    });
    document.querySelectorAll('.tourist-stat-card').forEach(card => card.addEventListener('click', () => {
        touristCardFilter = card.dataset.touristFilter || 'all';
        if (statusFilter) statusFilter.value = 'all';
        document.querySelectorAll('.tourist-stat-card').forEach(item => {
            const selected = item === card;
            item.classList.toggle('is-selected', selected);
            item.setAttribute('aria-pressed', selected ? 'true' : 'false');
        });
        updateTouristDirectory();
        document.querySelector('.tourist-card')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }));
    updateTouristDirectory();

    // BAN BUTTONS
    const banBtns = document.querySelectorAll('.banBtn');
    if (banBtns.length > 0) {
        banBtns.forEach(btn => {
            btn.addEventListener('click', () => {
                const modal = document.getElementById('banModal');
                if (!modal) return;
                modal.classList.add('show');
                const idInput = document.getElementById('banTouristID');
                const nameInput = document.getElementById('banTouristName');
                if (idInput) idInput.value = btn.dataset.id;
                if (nameInput) nameInput.value = btn.dataset.name;
            });
        });
    }

    // UNBAN BUTTONS
    const unbanBtns = document.querySelectorAll('.unbanBtn');
    if (unbanBtns.length > 0) {
        unbanBtns.forEach(btn => {
            btn.addEventListener('click', () => {
                const modal = document.getElementById('unbanModal');
                if (!modal) return;
                modal.classList.add('show');
                const idInput = document.getElementById('unbanTouristID');
                const nameInput = document.getElementById('unbanTouristName');
                if (idInput) idInput.value = btn.dataset.id;
                if (nameInput) nameInput.value = btn.dataset.name;
            });
        });
    }

    // MODAL CLOSE FUNCTIONS
    window.closeBanModal = () => {
        const modal = document.getElementById('banModal');
        if (modal) modal.classList.remove('show');
    };

    window.closeUnbanModal = () => {
        const modal = document.getElementById('unbanModal');
        if (modal) modal.classList.remove('show');
    };

    document.querySelectorAll('.decision-modal-overlay').forEach(modal => {
        modal.addEventListener('mousedown', event => {
            if (event.target !== modal) return;
            modal.classList.remove('show');
        });
    });

    document.addEventListener('keydown', event => {
        if (event.key !== 'Escape') return;
        if (document.getElementById('touristProfileDrawer')?.classList.contains('show')) {
            closeProfileDrawer();
            return;
        }
        window.closeBanModal();
        window.closeUnbanModal();
    });
});
</script>


</body>
</html>

