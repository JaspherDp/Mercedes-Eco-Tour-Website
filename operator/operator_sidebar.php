<?php
chdir(__DIR__ . '/..');
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require 'php/db_connection.php';

// ✅ Logout Action
if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    session_unset();
    session_destroy();
    echo "<script>
        alert('You have been logged out.');
        window.location.href = 'homepage.php';
    </script>";
    exit();
}

// ✅ Session Validation
if (!isset($_SESSION['operator_id'])) {
    echo "<script>
        alert('Session expired. Please login again.');
        window.location.href = 'homepage.php';
    </script>";
    exit();
}

$operator_id = $_SESSION['operator_id'];
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

    $fullname = trim($_POST['fullname']);
    $username = trim($_POST['username']);
    $email = trim($_POST['email']);
    $oldPassword = $_POST['old_password'];
    $newPassword = $_POST['new_password'];

    $finalPassword = $operator['password']; // default
    $profilePic = $operator['profile_pic'];

    if (!empty($newPassword)) {
        if (!password_verify($oldPassword, $operator['password'])) {
            echo "<script>alert('Wrong current password. Try again.');</script>";
        } else {
            $finalPassword = password_hash($newPassword, PASSWORD_DEFAULT);
        }
    }

    // ✅ Handle profile picture update
    if (!empty($_FILES['profile_pic']['name'])) {
        $uploadDir = "uploads/profile/";
        if (!is_dir($uploadDir)) mkdir($uploadDir, 0775, true);

        $fileName = time() . "_" . basename($_FILES['profile_pic']['name']);
        $target = $uploadDir . $fileName;

        if (move_uploaded_file($_FILES['profile_pic']['tmp_name'], $target)) {
            $profilePic = $fileName;
            $_SESSION['operator_profile'] = $target; // ✅ Update session
        } else {
            echo "<script>alert('Failed to upload profile picture. Check folder permissions.');</script>";
        }
    }


    // ✅ Update query
    $update = $pdo->prepare("
        UPDATE operators SET fullname=?, username=?, email=?, password=?, profile_pic=? 
        WHERE operator_id=?
    ");

    if ($update->execute([$fullname, $username, $email, $finalPassword, $profilePic, $operator_id])) {
        $_SESSION['operator_name'] = $fullname;
        $_SESSION['operator_email'] = $email;

        echo "<script>
            alert('Profile updated successfully!');
            window.location.href = location.href;
        </script>";
        exit();
    } else {
        echo "<script>alert('Update failed.');</script>";
    }
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
    margin-top: 8px;
    display: grid;
    gap: 4px;
    padding: 0 10px;
    flex: 1;
    align-content: start;
}
.op-navlinks a {
    display: flex;
    align-items: center;
    padding: 10px 12px;
    color: #daf7ea;
    text-decoration: none;
    border-radius: 12px;
    border: 1px solid transparent;
    font-size: 13px;
    font-weight: 600;
    transition: all 0.25s ease;
}
.op-navlinks a.active,
.op-navlinks a:hover {
    background: rgba(195, 245, 222, 0.16);
    color: #e8fff5;
    border-color: rgba(208, 250, 231, 0.2);
}
.op-navlinks img {
    width: 18px;
    height: 18px;
    margin-right: 10px;
    filter: brightness(0) saturate(100%) invert(91%) sepia(19%) saturate(309%) hue-rotate(96deg) brightness(106%) contrast(103%);
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
.op-form-container button {
    padding: 11px 12px;
    border-radius: 10px;
    background-color: var(--op-primary);
    color: #fff;
    font-size: 13px;
    font-weight: 700;
    border: 0;
    cursor: pointer;
    transition: background 0.2s ease;
}
.op-form-container button:hover { background-color: var(--op-primary-dark); }
</style>

<div class="op-sidebar" id="opSidebar">
    <a class="op-brand" href="ophomepage.php">
        <img src="img/newlogo.png" alt="iTour Mercedes logo">
        <img src="img/textlogo3.png" alt="iTour Mercedes" class="op-brand-text">
    </a>

    <div class="op-navlinks">
        <a href="ophomepage.php" class="<?= basename($_SERVER['PHP_SELF'])=='ophomepage.php'?'active':'' ?>">
            <img src="img/homeicon2.png"><span>Home</span>
        </a>
        <a href="opbookings.php" class="<?= basename($_SERVER['PHP_SELF'])=='opbookings.php'?'active':'' ?>">
            <img src="img/bookingicon.png"><span>Bookings</span>
            <?php if ($opPendingCount > 0): ?>
                <strong class="op-nav-badge"><?= (int)$opPendingCount ?></strong>
            <?php endif; ?>
        </a>
        <a href="optourpackages.php" class="<?= basename($_SERVER['PHP_SELF'])=='optourpackages.php'?'active':'' ?>">
            <img src="img/packagesicon.png"><span>Packages</span>
        </a>
    </div>

    <div class="op-sidebar-bottom">
        <div class="op-profile" id="opProfile">
            <img src="<?= htmlspecialchars($opProfilePic) ?>" alt="Operator">
            <div class="op-profile-info">
                <p class="op-name"><?= htmlspecialchars($opFullname) ?></p>
                <p class="op-email"><?= htmlspecialchars($opEmail) ?></p>
            </div>
        </div>

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
        <form class="op-form-container" method="POST" enctype="multipart/form-data">
            <input type="hidden" name="updateProfile" value="1">
            <div class="op-input-group">
                <input type="text" name="fullname" value="<?= htmlspecialchars($opFullname) ?>" required>
                <label>Full Name</label>
            </div>
            <div class="op-input-group">
                <input type="text" name="username" value="<?= htmlspecialchars($opUsername) ?>" required>
                <label>Username</label>
            </div>
            <div class="op-input-group">
                <input type="email" name="email" value="<?= htmlspecialchars($opEmail) ?>" required>
                <label>Email</label>
            </div>
            <div class="op-input-group">
                <input type="file" name="profile_pic">
                <label>Profile Picture</label>
            </div>
            <div class="op-input-group">
                <input type="password" name="old_password">
                <label>Current Password</label>
            </div>
            <div class="op-input-group">
                <input type="password" name="new_password">
                <label>New Password</label>
            </div>
            <button type="submit">Save Changes</button>
        </form>
    </div>
</div>

<script>
document.addEventListener("DOMContentLoaded", function() {
    const opProfile = document.getElementById('opProfile');
    const opModalOverlay = document.getElementById('opModalOverlay');
    const opModalClose = document.getElementById('opModalClose');

    // Logout
    window.opConfirmLogout = function(e){
        e.preventDefault();
        if(confirm("Are you sure you want to logout?")){
            window.location.href="ophomepage.php?action=logout";
        }
    }

    // Modal open/close
    opProfile.addEventListener('click', ()=> opModalOverlay.style.display='flex');
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
