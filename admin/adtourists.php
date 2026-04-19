<?php
chdir(__DIR__ . '/..');
session_start();
require 'php/db_connection.php'; // $pdo is defined here

// Initialize alert variable
$alert = '';

// =============================
// LOGOUT ACTION
// =============================
if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    session_unset();
    session_destroy();
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
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    $alert = "Swal.fire({
        icon: 'warning',
        title: 'Session Expired',
        text: 'Please login again.',
        confirmButtonColor: '#2B7066',
        confirmButtonText: 'OK'
    }).then(() => {
        window.location.href='php/admin_login.php';
    });";
}

// Disable Back Button Cache
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

// =============================
// BACKEND: BAN TOURIST
// =============================
if (isset($_POST['action']) && $_POST['action'] === 'ban_tourist') {
    $id = $_POST['tourist_id'];
    $note = $_POST['ban_note'];

    $stmt = $pdo->prepare("UPDATE tourist SET status='banned', ban_note=:note WHERE tourist_id=:id");
    $stmt->execute([':note' => $note, ':id' => $id]);

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
    $id = $_POST['tourist_id'];

    $stmt = $pdo->prepare("UPDATE tourist SET status='active', ban_note=NULL WHERE tourist_id=:id");
    $stmt->execute([':id' => $id]);

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
         FROM bookings b 
         WHERE b.tourist_id = t.tourist_id 
           AND b.is_complete = 'completed') AS completed_count
    FROM tourist t
    $sortQuery
");

$stmt->execute();
$tourists = $stmt->fetchAll(PDO::FETCH_ASSOC);

?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>iTour Mercedes - Admin Panel</title>
<link rel="icon" type="image/png" href="img/newlogo.png" />
<link href="https://fonts.googleapis.com/css2?family=Roboto&display=swap" rel="stylesheet" />
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
</head>

<body>

<div class="admin-container">
    <?php include 'admin_sidebar.php'; ?>

    <main class="main-content">
        <header class="admin-header tourists-header">
            <h2>Manage Tourists</h2>
            <div class="tourists-toolbar">
                <div class="tourists-toolbar-group">
                    <form method="GET" id="sortForm">
                        <label for="sortSelect" class="sort-label">Sort by:</label>
                        <select name="sort_by" id="sortSelect" onchange="document.getElementById('sortForm').submit()"
                            style="padding:8px 12px; border-radius:8px; border:1px solid #ccc; font-size:0.95rem; outline:none; cursor:pointer; transition:all 0.2s; background:#fff;">
                            <option value="date" <?= ($sortBy ?? 'date') === 'date' ? 'selected' : '' ?>>Date Created</option>
                            <option value="name" <?= ($sortBy ?? '') === 'name' ? 'selected' : '' ?>>Name (A-Z)</option>
                        </select>
                    </form>
                </div>

                <div class="tourists-toolbar-group">
                    <input type="text" id="searchTourist" placeholder="Search by name..."
                        style="padding:8px 12px; border-radius:8px; border:1px solid #ccc; font-size:0.95rem; outline:none; width:200px; transition:all 0.2s;">
                </div>
            </div>
        </header>

        <!-- TABLE CARD -->
        <div class="card">
            <table id="touristTable">
                <thead>
                    <tr>
                        <th>PROFILE</th>
                        <th>CONTACT</th>
                        <th>ADDRESS</th>
                        <th>STATUS</th>
                        <th style="text-align:center;">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($tourists as $t): ?>
                    <tr>
                        <td>
                            <div class="profile-cell">
                                <img src="<?= !empty($t['profile_picture']) ? htmlspecialchars($t['profile_picture']) : 'img/profileicon.png' ?>" 
                                    class="profile-img" alt="Profile Picture">
                                <div>
                                    <div class="profile-name"><?= htmlspecialchars($t['full_name']) ?></div>
                                    <div class="profile-email"><?= htmlspecialchars($t['email']) ?></div>
                                    <div style="font-size:0.85rem; color:#49A47A; margin-top:4px;">
                                        Completed Bookings: <?= $t['completed_count'] ?>
                                    </div>
                                </div>
                            </div>
                        </td>

                        <td><?= $t['phone_number'] ?: 'No phone' ?></td>
                        <td><?= $t['address'] ?: '—' ?></td>
                        <td>
                            <?php if($t['status']=='banned'): ?>
                                <span class="pill declined">Banned</span>
                            <?php else: ?>
                                <span class="pill accepted">Active</span>
                            <?php endif; ?>
                        </td>
                        <td style="text-align:center;">
                            <?php if($t['status']=='active'): ?>
                                <button class="action-btn decline banBtn" data-id="<?= $t['tourist_id'] ?>" data-name="<?= htmlspecialchars($t['full_name']) ?>">Ban</button>
                            <?php else: ?>
                                <button class="action-btn accept unbanBtn" data-id="<?= $t['tourist_id'] ?>" data-name="<?= htmlspecialchars($t['full_name']) ?>">Unban</button>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </main>
</div>


<!-- BAN MODAL -->
<div class="decision-modal-overlay" id="banModal">
    <div class="decision-modal">
        <div class="decision-modal-header">
            <span>Ban Tourist</span>
            <button class="decision-modal-close" onclick="closeBanModal()">×</button>
        </div>

        <form method="POST">
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
                <button class="decision-btn ok">Ban</button>
            </div>
        </form>
    </div>
</div>

<!-- UNBAN MODAL -->
<div class="decision-modal-overlay" id="unbanModal">
    <div class="decision-modal">
        <div class="decision-modal-header">
            Unban Tourist
            <button class="decision-modal-close" onclick="closeUnbanModal()">×</button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="unban_tourist">
            <input type="hidden" name="tourist_id" id="unbanTouristID">
            <div class="decision-modal-body">
                <label class="decision-label">Tourist</label>
                <input type="text" class="decision-input" id="unbanTouristName" readonly>
                <p style="margin-top:10px;color:#666;">Are you sure you want to unban this tourist?</p>
            </div>
            <div class="decision-modal-footer">
                <button type="button" class="decision-btn cancel" onclick="closeUnbanModal()">Cancel</button>
                <button class="decision-btn ok">Unban</button>
            </div>
        </form>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    // SEARCH FUNCTIONALITY
    const searchInput = document.getElementById('searchTourist');
    const table = document.getElementById('touristTable');
    if (searchInput && table) {
        searchInput.addEventListener('keyup', () => {
            const filter = searchInput.value.toLowerCase();
            const trs = table.getElementsByTagName('tr');
            for (let i = 1; i < trs.length; i++) { // skip header row
                const nameCell = trs[i].querySelector('.profile-name');
                trs[i].style.display = nameCell && nameCell.textContent.toLowerCase().includes(filter) ? '' : 'none';
            }
        });
    }

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
});
</script>


</body>
</html>

