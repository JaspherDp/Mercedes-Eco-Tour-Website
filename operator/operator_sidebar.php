<?php
chdir(__DIR__ . '/..');
require_once __DIR__ . '/../php/session_security.php';
AppSessionStart();
require 'php/db_connection.php';
require_once __DIR__ . '/../php/activity_logger.php';
require_once __DIR__ . '/../php/operator_auth_helper.php';

// ✅ Logout Action
if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    AppDestroySession();
    AppSessionStart();
    $_SESSION['alert'] = [
        'type' => 'success',
        'title' => 'Logout Successful',
        'message' => 'You have securely signed out of the operator portal.'
    ];
    header('Location: ' . operatorLoginUrl());
    exit();
}

// ✅ Session Validation
if (!isset($_SESSION['operator_id'])) {
    $_SESSION['alert'] = ['type' => 'error', 'title' => 'Session Expired', 'message' => 'Your session expired. Please log in again.'];
    header('Location: ' . operatorLoginUrl());
    exit();
}

$operator_id = $_SESSION['operator_id'];
$operatorNotificationCsrf = AppCsrfToken('operator', 'notifications');
$opPendingStmt = $pdo->prepare("SELECT COUNT(*) FROM bookings WHERE operator_id=? AND status='pending'");
$opPendingStmt->execute([$operator_id]);
$opPendingCount = (int)$opPendingStmt->fetchColumn();

// ✅ Fetch operator data
$stmt = $pdo->prepare("SELECT * FROM operators WHERE operator_id=? LIMIT 1");
$stmt->execute([$operator_id]);
$operator = $stmt->fetch(PDO::FETCH_ASSOC);

$opFullname   = $operator['fullname'];
$opUsername   = $operator['username'];
$opEmail      = $operator['email'];
$opProfilePic = !empty($operator['profile_pic']) && file_exists("uploads/profile/".$operator['profile_pic'])
    ? "uploads/profile/".$operator['profile_pic']
    : "img/profileicon.png";

$_SESSION['operator_profile'] = $opProfilePic; // ✅ sync session for homepage



// ✅ Handle Update Profile (Same File)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['updateProfile'])) {
    http_response_code(405);
    header('Allow: GET');
    exit('Profile updates must be submitted through the operator profile page.');
}
?>

<style>
:root {
    --op-primary: #2b7a66;
    --op-primary-dark: #1d5d4a;
    --op-border: #d8e6e0;
    --op-shadow: 0 12px 30px rgba(17, 67, 53, 0.16);
}

.op-sidebar {
    background: linear-gradient(180deg, #143a2d 0%, #102b22 100%);
    color: #daf7ea;
    width: 250px;
    height: 100vh;
    position: fixed;
    top: 0;
    left: 0;
    overflow-y: auto;
    transition: width 0.3s ease;
    border-right: 1px solid rgba(196, 243, 220, 0.14);
    box-shadow: 2px 0 16px rgba(0, 0, 0, 0.18);
    z-index: 1000;
    display: flex;
    flex-direction: column;
}
.op-sidebar,
.op-sidebar * {
    box-sizing: border-box;
}

.op-brand {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 14px 12px;
    text-decoration: none;
    min-height: 78px;
}
.op-brand img {
    width: 42px;
    height: 42px;
    object-fit: contain;
    flex-shrink: 0;
}
.op-brand .op-brand-text {
    max-width: 140px;
    width: 100%;
    height: auto;
    filter: drop-shadow(0 0 1px rgba(208, 248, 229, 0.38));
}

.op-profile {
    display: flex;
    align-items: center;
    gap: 10px;
    margin: 0;
    padding: 10px 10px;
    border-radius: 14px;
    cursor: pointer;
    transition: background 0.25s ease;
    color: inherit;
    text-decoration: none;
}
.op-profile:hover { background: rgba(195, 245, 222, 0.14); }
.op-profile img {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    border: 2px solid rgba(208, 250, 231, 0.4);
    object-fit: cover;
    transition: all 0.3s ease;
}
.op-profile-info { margin-top: 0; min-width: 0; }
.op-profile-info .op-name {
    font-weight: 700;
    margin: 0;
    font-size: 14px;
    color: #e8fff5;
}
.op-profile-info .op-email {
    margin: 2px 0 0;
    font-size: 12px;
    color: #b7d7ca;
    word-break: break-word;
}

.op-navlinks {
    margin-top: 4px;
    display: block;
    padding: 0 10px;
    flex: 1;
}
.op-nav-section { margin: 0 0 14px; }
.op-nav-section:last-child { margin-bottom: 6px; }
.op-nav-section-title {
    display: flex;
    align-items: center;
    justify-content: space-between;
    min-height: 26px;
    padding: 0 10px;
    color: #9ccbb9;
    font-size: 10px;
    font-weight: 800;
    letter-spacing: 0.11em;
    line-height: 1;
    text-transform: uppercase;
}
.op-nav-section-title svg {
    width: 13px;
    height: 13px;
    fill: none;
    stroke: currentColor;
    stroke-width: 2;
    stroke-linecap: round;
    stroke-linejoin: round;
}
.op-navlinks a {
    display: flex;
    align-items: center;
    min-height: 42px;
    padding: 10px 12px;
    color: #daf7ea;
    text-decoration: none;
    border-radius: 12px;
    border: 1px solid transparent;
    font-size: 13px;
    font-weight: 600;
    transition: all 0.25s ease;
}
.op-nav-section a + a { margin-top: 3px; }
.op-nav-section-title + a,
.op-nav-section-title ~ a {
    padding-left: 22px;
}
.op-navlinks a.active,
.op-navlinks a:hover {
    background: rgba(195, 245, 222, 0.16);
    color: #e8fff5;
    border-color: rgba(208, 250, 231, 0.2);
}
.op-navlinks a.active::before {
    position: absolute;
    left: 0;
    width: 3px;
    height: 24px;
    content: "";
    border-radius: 0 3px 3px 0;
    background: #72d8b6;
}
.op-navlinks a { position: relative; }
.op-navlinks img {
    width: 18px;
    height: 18px;
    margin-right: 10px;
    filter: brightness(0) saturate(100%) invert(91%) sepia(19%) saturate(309%) hue-rotate(96deg) brightness(106%) contrast(103%);
}
.op-nav-icon {
    width: 18px;
    height: 18px;
    flex: 0 0 18px;
    margin-right: 10px;
    fill: none;
    stroke: currentColor;
    stroke-width: 1.8;
    stroke-linecap: round;
    stroke-linejoin: round;
}
.op-nav-badge {
    margin-left: auto;
    min-width: 20px;
    height: 20px;
    padding: 0 6px;
    border-radius: 999px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    background: #d8efe4;
    color: #1f614e;
    font-size: 11px;
    font-weight: 700;
    line-height: 1;
}
.op-navlinks a.active .op-nav-badge,
.op-navlinks a:hover .op-nav-badge {
    background: rgba(232, 255, 245, 0.92);
    color: #1c5a48;
}
.op-navlinks span { transition: opacity 0.25s ease; }

.op-logout-divider {
    border: 0;
    border-top: 1px solid rgba(208, 250, 231, 0.24);
    width: 100%;
    margin: 10px 0 6px;
}
.op-logout-btn {
    margin-top: 0;
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 10px 12px;
    color: #daf7ea;
    text-decoration: none;
    border-radius: 12px;
    border: 1px solid transparent;
    font-size: 13px;
    font-weight: 600;
    transition: all 0.25s ease;
}
.op-logout-btn:hover {
    background: rgba(195, 245, 222, 0.16);
    color: #e8fff5;
    border-color: rgba(208, 250, 231, 0.2);
}
.op-logout-btn img {
    width: 18px;
    height: 18px;
    filter: brightness(0) saturate(100%) invert(91%) sepia(19%) saturate(309%) hue-rotate(96deg) brightness(106%) contrast(103%);
}

.op-sidebar-bottom {
    padding: 10px;
    margin-top: auto;
}
.op-sidebar-bottom .op-profile {
    border: 1px solid rgba(208, 250, 231, 0.2);
    background: rgba(195, 245, 222, 0.08);
}

.op-modal-overlay {
    position: fixed;
    inset: 0;
    background: rgba(5, 18, 14, 0.62);
    display: none;
    justify-content: center;
    align-items: center;
    z-index: 1001;
}
.op-modal {
    background: #fff;
    padding: 1.5rem 1.25rem;
    border-radius: 16px;
    width: 420px;
    max-width: 94%;
    border: 1px solid var(--op-border);
    position: relative;
    box-shadow: var(--op-shadow);
}
.op-close-modal {
    position: absolute;
    top: 10px;
    right: 14px;
    font-size: 1.4rem;
    cursor: pointer;
    color: #4a6069;
}
.op-form-container {
    display: grid;
    gap: 0.85rem;
}
.op-form-container .form-title {
    text-align: center;
    margin-bottom: 0.4rem;
    color: var(--op-primary-dark);
    font-weight: 700;
}

.op-input-group {
    position: relative;
    display: flex;
    flex-direction: column;
}
.op-input-group input {
    padding: 12px 10px;
    font-size: 13px;
    border: 1px solid #cfe0da;
    border-radius: 10px;
    outline: none;
    transition: border-color 0.2s ease, box-shadow 0.2s ease;
}
.op-input-group input:focus {
    border-color: var(--op-primary);
    box-shadow: 0 0 0 3px rgba(43, 122, 102, 0.12);
}
.op-input-group label {
    margin: 0 0 6px;
    position: static;
    font-size: 12px;
    color: #4a6069;
    font-weight: 600;
    background: transparent;
    padding: 0;
}
.op-form-container button,
.op-form-container .op-profile-link {
    padding: 11px 12px;
    border-radius: 10px;
    background-color: var(--op-primary);
    color: #fff;
    font-size: 13px;
    font-weight: 700;
    border: 0;
    cursor: pointer;
    transition: background 0.2s ease;
    text-align: center;
    text-decoration: none;
}
.op-form-container button:hover,
.op-form-container .op-profile-link:hover { background-color: var(--op-primary-dark); }
</style>

<?php require_once __DIR__ . '/../php/alert.php'; ?>
<div class="op-sidebar" id="opSidebar">
    <a class="op-brand" href="ophomepage.php">
        <img src="img/newlogo.png" alt="iTour Mercedes logo">
        <img src="img/textlogo3.png" alt="iTour Mercedes" class="op-brand-text">
    </a>

    <div class="op-navlinks">
        <div class="op-nav-section">
            <a href="ophomepage.php" class="<?= basename($_SERVER['PHP_SELF'])=='ophomepage.php'?'active':'' ?>">
                <svg class="op-nav-icon" aria-hidden="true" viewBox="0 0 24 24"><path d="m3 11 9-8 9 8"/><path d="M5 10v10h14V10M9 20v-6h6v6"/></svg>
                <span>Dashboard</span>
            </a>
        </div>

        <div class="op-nav-section">
            <div class="op-nav-section-title"><span>Operations</span><svg aria-hidden="true" viewBox="0 0 24 24"><path d="m6 15 6-6 6 6"/></svg></div>
            <a href="opbookings.php" class="<?= basename($_SERVER['PHP_SELF'])=='opbookings.php'?'active':'' ?>">
                <svg class="op-nav-icon" aria-hidden="true" viewBox="0 0 24 24"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 10h18M8 14h.01M12 14h.01M16 14h.01M8 18h.01M12 18h.01"/></svg>
                <span>Bookings</span>
                <?php if ($opPendingCount > 0): ?>
                    <strong class="op-nav-badge"><?= (int)$opPendingCount ?></strong>
                <?php endif; ?>
            </a>
            <a href="oppayments.php" class="<?= basename($_SERVER['PHP_SELF'])=='oppayments.php'?'active':'' ?>">
                <svg class="op-nav-icon" aria-hidden="true" viewBox="0 0 24 24"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 10h18M7 15h4"/></svg>
                <span>Payments &amp; Transactions</span>
            </a>
            <a href="opearnings.php" class="<?= basename($_SERVER['PHP_SELF'])=='opearnings.php'?'active':'' ?>">
                <svg class="op-nav-icon" aria-hidden="true" viewBox="0 0 24 24"><path d="M4 6h14a2 2 0 0 1 2 2v11H4a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h12"/><path d="M20 11h-5a2 2 0 0 0 0 4h5M15 13h.01"/></svg>
                <span>Earnings &amp; Payouts</span>
            </a>
        </div>

        <div class="op-nav-section">
            <div class="op-nav-section-title"><span>Content Management</span><svg aria-hidden="true" viewBox="0 0 24 24"><path d="m6 15 6-6 6 6"/></svg></div>
            <a href="optourpackages.php" class="<?= basename($_SERVER['PHP_SELF'])=='optourpackages.php'?'active':'' ?>">
                <svg class="op-nav-icon" aria-hidden="true" viewBox="0 0 24 24"><path d="M4 7.5 12 3l8 4.5-8 4.5-8-4.5Z"/><path d="m4 12 8 4.5 8-4.5M4 16.5 12 21l8-4.5"/></svg>
                <span>Tour Packages</span>
            </a>
        </div>

        <div class="op-nav-section">
            <div class="op-nav-section-title"><span>Account</span><svg aria-hidden="true" viewBox="0 0 24 24"><path d="m6 15 6-6 6 6"/></svg></div>
            <a href="opprofile.php" class="<?= basename($_SERVER['PHP_SELF'])=='opprofile.php'?'active':'' ?>">
                <svg class="op-nav-icon" aria-hidden="true" viewBox="0 0 24 24"><circle cx="12" cy="8" r="4"/><path d="M5 21a7 7 0 0 1 14 0"/></svg>
                <span>Profile</span>
            </a>
        </div>
    </div>

    <div class="op-sidebar-bottom">
        <a class="op-profile" id="opProfile" href="opprofile.php" aria-label="Open operator profile">
            <img src="<?= htmlspecialchars($opProfilePic) ?>" alt="Operator">
            <div class="op-profile-info">
                <p class="op-name"><?= htmlspecialchars($opFullname) ?></p>
                <p class="op-email"><?= htmlspecialchars($opEmail) ?></p>
            </div>
        </a>

        <hr class="op-logout-divider">

        <a href="#" class="op-logout-btn" onclick="opConfirmLogout(event)">
            <img src="img/logouticon.png"><span>Logout</span>
        </a>
    </div>
</div>


<!-- Edit Profile Modal -->
<div class="op-modal-overlay" id="opModalOverlay">
    <div class="op-modal">
        <span class="op-close-modal" id="opModalClose">&times;</span>
        <h3 class="form-title">Edit Profile</h3>
        <div class="op-form-container">
            <p>Profile details, password, and profile picture are managed on the secure profile page.</p>
            <a href="opprofile.php" class="op-profile-link">Open Profile Settings</a>
        </div>
    </div>
</div>

<script>window.operatorNotificationCsrf = <?= json_encode($operatorNotificationCsrf) ?>;</script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
document.addEventListener("DOMContentLoaded", function() {
    const opProfileTriggers = document.querySelectorAll('[data-op-profile]');
    const opModalOverlay = document.getElementById('opModalOverlay');
    const opModalClose = document.getElementById('opModalClose');

    // Logout
    window.opConfirmLogout = function(e){
        e.preventDefault();
        Swal.fire({
            icon: "question",
            title: "Sign out?",
            text: "Are you sure you want to leave the operator portal?",
            showCancelButton: true,
            confirmButtonColor: "#176b55",
            cancelButtonColor: "#687b75",
            confirmButtonText: "Yes, sign out",
            cancelButtonText: "Stay signed in",
            reverseButtons: true,
            focusCancel: true,
            customClass: {
                cancelButton: "op-swal-cancel"
            }
        }).then((result) => {
            if (result.isConfirmed) {
                window.location.href = "ophomepage.php?action=logout";
            }
        });
    }

    // Modal open/close
    const openOperatorProfile = (event) => {
        event?.preventDefault();
        opModalOverlay.style.display = 'flex';
    };
    opProfileTriggers.forEach((trigger) => {
        trigger.addEventListener('click', openOperatorProfile);
        trigger.addEventListener('keydown', (event) => {
            if (event.key === 'Enter' || event.key === ' ') openOperatorProfile(event);
        });
    });
    opModalClose.addEventListener('click', ()=> opModalOverlay.style.display='none');
    window.addEventListener('click', (e)=> { if(e.target==opModalOverlay) opModalOverlay.style.display='none'; });

    // Disable cached pages after logout
    window.addEventListener('pageshow', function(event) {
        if (event.persisted) window.location.reload();
    });

    // Prevent back navigation cache
    window.history.pushState(null, "", location.href);
    window.onpopstate = function () {
        location.reload();
    };
});
</script>
