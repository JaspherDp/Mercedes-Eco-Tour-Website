<?php
chdir(__DIR__ . '/..');
session_start();
require 'php/db_connection.php';

// ✅ Operator Authentication
if(!isset($_SESSION['operator_id'])){
    echo "<script>alert('Session expired. Please login.'); window.location.href='php/operator_login.php';</script>";
    exit();
}
$operator_id = $_SESSION['operator_id'];
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

$seenAt = trim((string)($_SESSION['op_notifications_seen_at'] ?? ''));
if ($seenAt !== '' && preg_match('/^\d{4}-\d{2}-\d{2}\s\d{2}:\d{2}:\d{2}$/', $seenAt)) {
    $notifStmt = $pdo->prepare("SELECT COUNT(*) FROM bookings WHERE operator_id=? AND status='pending' AND created_at > ?");
    $notifStmt->execute([$operator_id, $seenAt]);
} else {
    $notifStmt = $pdo->prepare("SELECT COUNT(*) FROM bookings WHERE operator_id=? AND status='pending'");
    $notifStmt->execute([$operator_id]);
}
$notificationCount = (int)$notifStmt->fetchColumn();

$notifItemsStmt = $pdo->prepare("
    SELECT booking_id, package_name, booking_date, status, created_at
    FROM bookings
    WHERE operator_id=?
    ORDER BY created_at DESC
    LIMIT 8
");
$notifItemsStmt->execute([$operator_id]);
$notificationItems = $notifItemsStmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch packages for this operator only
$stmt = $pdo->prepare("SELECT * FROM tour_packages WHERE operator_id=? ORDER BY package_id DESC");
$stmt->execute([$operator_id]);
$packages = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Universal function to resolve image paths
function resolveImagePath($imgField){
    if(!$imgField || trim($imgField)==='') return 'img/placeholder.png';

    // Check if file exists in php/upload/
    $uploadPath = 'php/upload/'.basename($imgField);
    if(file_exists(__DIR__.'/'.$uploadPath)) return $uploadPath;

    // Check if file exists in img/
    $imgPath = 'img/'.basename($imgField);
    if(file_exists(__DIR__.'/'.$imgPath)) return $imgPath;

    // Fallback
    return 'img/placeholder.png';
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>iTour Mercedes - My Tour Packages</title>
<link rel="icon" type="image/png" href="img/newlogo.png">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/cropperjs@1.5.13/dist/cropper.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">

<style>
:root {
    --op-primary: #2b7a66;
    --op-primary-dark: #1d5d4a;
    --op-bg: #f4f8f6;
    --op-card: #ffffff;
    --op-border: #d8e6e0;
    --op-text: #132028;
    --op-muted: #60707a;
    --op-shadow: 0 12px 28px rgba(17, 67, 53, 0.08);
}

body { margin: 0; font-family: 'Inter', sans-serif; background: var(--op-bg); color: var(--op-text); }
.admin-container { display: flex; min-height: 100vh; }
.op-main {
    margin-left: 250px;
    flex: 1;
    padding: 86px 18px 24px;
    min-width: 0;
    overflow-x: hidden;
    transition: margin-left 0.3s ease;
    min-height: 100vh;
}
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
    border-bottom: 1px solid rgba(188, 220, 206, 0.6);
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 16px;
    box-sizing: border-box;
}
.operator-header-left h2 {
    color: var(--op-primary-dark);
    font-size: 23px;
    margin: 0;
    font-weight: 700;
    letter-spacing: 0.01em;
}
.operator-header-left p {
    margin: 3px 0 0;
    color: var(--op-muted);
    font-size: 13px;
}
.operator-header-right {
    display: inline-flex;
    align-items: center;
    gap: 10px;
    margin-right: 8px;
    flex-wrap: wrap;
    justify-content: flex-end;
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
    flex-shrink: 0;
}
.op-topbar-profile img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: block;
}
.op-notif-wrap { position: relative; }
.op-notif-btn {
    border: 1px solid var(--op-border);
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
    border: 1px solid var(--op-border);
    border-radius: 12px;
    box-shadow: var(--op-shadow);
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
.op-notif-empty { margin: 0; color: var(--op-muted); font-size: 13px; padding: 8px 4px; }

.dashboard-content {
    margin-top: 10px;
}
.packages-section {
    position: relative;
    background: var(--op-card);
    border: 1px solid var(--op-border);
    border-radius: 16px;
    box-shadow: 0 10px 24px rgba(17, 67, 53, 0.07);
    padding: 18px;
}
.packages-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    margin-bottom: 14px;
    flex-wrap: wrap;
}
.packages-head h3 {
    margin: 0;
    font-size: 18px;
    color: #1f3f49;
}
.add-new-p-btn {
    border: 1px solid #2b7a66;
    background: var(--op-primary);
    color: #fff;
    border-radius: 10px;
    padding: 9px 13px;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    box-shadow: 0 2px 8px rgba(28, 74, 62, 0.16);
}
.add-new-p-btn:hover { background: var(--op-primary-dark); }

.cards-container {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 270px));
    justify-content: start;
    gap: 16px;
    margin-top: 2px;
    align-items: stretch;
}

.package-card {
    background: #fff;
    border: 1px solid #dfeae6;
    border-radius: 12px;
    box-shadow: 0 8px 18px rgba(17, 67, 53, 0.06);
    display: flex;
    flex-direction: column;
    overflow: hidden;
    min-width: 0;
}
.package-card img { width: 100%; height: 150px; object-fit: cover; }
.package-card-content {
    padding: 14px;
    display: flex;
    flex-direction: column;
    flex: 1;
    gap: 8px;
    min-width: 0;
}
.package-card-content h4 {
    margin: 0;
    color: #214952;
    font-size: 17px;
    line-height: 1.3;
    word-break: break-word;
}
.package-card-content p {
    margin: 0;
    font-weight: 800;
    color: #102028;
    font-size: 1.2rem;
}
.package-card-content button {
    margin-top: auto;
    padding: 9px 11px;
    border: 1px solid var(--op-border);
    border-radius: 10px;
    background: #fff;
    color: #24434d;
    font-weight: 600;
    font-size: 13px;
    cursor: pointer;
}
.package-card-content button:hover {
    background: #f4faf7;
    border-color: #c9dfd6;
}

.modal {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(5, 18, 14, 0.58);
    z-index: 1000;
    justify-content: center;
    align-items: center;
    padding: 1rem;
    box-sizing: border-box;
}
.modal-content {
    background: #fff;
    border-radius: 14px;
    border: 1px solid var(--op-border);
    width: 90%;
    max-width: 1000px;
    max-height: 90vh;
    overflow-y: auto;
    padding: 1.2rem;
    position: relative;
    box-shadow: var(--op-shadow);
}
.close {
    position: absolute;
    top: 10px;
    right: 14px;
    font-size: 26px;
    font-weight: 700;
    color: #5a6f79;
    cursor: pointer;
}
.close:hover { color: #1d3640; }

.modal-section-head {
    display: flex;
    justify-content: center;
    position: relative;
    padding-bottom: 10px;
    border-bottom: 1px solid #e7efec;
    margin-bottom: 1rem;
}
.modal-section-head strong {
    font-size: 1.15rem;
    color: #244650;
}
.image-modal-title {
    color: #244650;
    text-align: center;
    margin: 0 0 8px;
}

.custum-file-upload {
    width: 100%;
    max-width: 460px;
    height: 220px;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 15px;
    cursor: pointer;
    border: 2px dashed var(--op-primary);
    background-color: #f8fcfa;
    padding: 1rem;
    border-radius: 12px;
    margin: 0 auto;
}
.custum-file-upload .icon svg {
    height: 72px;
    fill: #60707a;
}
.custum-file-upload .text span {
    font-weight: 500;
    color: #4f636c;
}
.custum-file-upload input { display: none; }
.custum-file-upload.dragover {
    background-color: #edf8f3;
    border-color: var(--op-primary-dark);
}

.itinerary-step {
    display: flex;
    gap: 12px;
    align-items: flex-start;
    margin-bottom: 12px;
    border-radius: 12px;
    border: 1px solid #dfeae6;
    background: #fbfdfc;
    padding: 12px 14px;
}
.drag-handle {
    cursor: grab;
    font-size: 1.3rem;
    color: var(--op-primary);
    user-select: none;
}
.drag-handle:active { cursor: grabbing; }

.step-content { flex: 1; display: flex; flex-direction: column; gap: 10px; }
.step-content .top-row {
    width: 100%;
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 12px;
}
.step-content textarea,
.step-content input {
    margin-top: 8px;
    width: 100%;
    padding: 9px 10px;
    border-radius: 10px;
    border: 1px solid #cfe0da;
    font-size: 0.95rem;
    outline: none;
    transition: border-color 0.2s ease, box-shadow 0.2s ease;
}
.step-content textarea {
    resize: vertical;
    min-height: 96px;
}
.step-content textarea:focus,
.step-content input:focus,
.form-input:focus,
.form-group select:focus {
    border-color: var(--op-primary);
    box-shadow: 0 0 0 3px rgba(43, 122, 102, 0.12);
}

.itinerary-step .btn-red { margin-top: 10px; align-self: flex-start; }
.add-step-btn,
.btn-green,
.btn-cancel,
.btn-red {
    padding: 9px 12px;
    border: 0;
    border-radius: 10px;
    cursor: pointer;
    font-weight: 700;
}
.add-step-btn,
.btn-green { background: var(--op-primary); color: #fff; }
.add-step-btn:hover,
.btn-green:hover { background: var(--op-primary-dark); }
.btn-red,
.btn-cancel { background: #bf3545; color: #fff; }
.btn-red:hover,
.btn-cancel:hover { background: #9d2a38; }

#cropContainer { display: flex; justify-content: center; align-items: center; padding: 12px 0; }

.form-group {
    display: flex;
    flex-direction: column;
    margin-bottom: 12px;
    gap: 6px;
}
.form-input,
.form-group input,
.form-group select,
.form-group textarea {
    padding: 10px 12px;
    border-radius: 10px;
    border: 1px solid #cfe0da;
    font-size: 0.95rem;
    width: 100%;
    box-sizing: border-box;
    font-family: 'Inter', sans-serif;
}
.form-group label {
    font-weight: 700;
    color: #4a6069;
    font-size: 0.9rem;
}

#imageModal .modal-content {
    width: 520px !important;
    max-width: 520px;
    max-height: 420px;
    overflow: hidden;
    display: flex;
    flex-direction: column;
    align-items: center;
}

#cropContainer > div {
    width: 100% !important;
    height: 220px !important;
}

.swal2-confirm-green {
    background-color: var(--op-primary) !important;
    color: #fff !important;
}
.swal2-confirm-green:hover {
    background-color: var(--op-primary-dark) !important;
}
@media (max-width: 1280px) {
    .cards-container {
        grid-template-columns: repeat(3, minmax(0, 260px));
    }
}
@media (max-width: 900px) {
    .operator-header {
        position: static;
        left: auto;
        width: auto;
        border-radius: 14px;
    }
    .op-main {
        margin-left: 250px;
        padding-top: 18px;
    }
    .cards-container {
        grid-template-columns: repeat(2, minmax(0, 1fr));
        justify-content: stretch;
    }
}
@media (max-width: 640px) {
    .cards-container {
        grid-template-columns: 1fr;
    }
}
</style>
</head>

<body>
<div class="admin-container">
<?php include 'operator_sidebar.php'; ?>

<main class="op-main">
<header class="operator-header">
    <div class="operator-header-left">
        <h2>My Tour Packages</h2>
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

<div class="dashboard-content">
<section id="tour-packages" class="packages-section">
    <div class="packages-head">
        <h3>All Tour Packages</h3>
        <button class="add-new-p-btn" onclick="openAddModal()">+ Add New Tour Package</button>
    </div>
    <div class="cards-container">
        <?php foreach($packages as $package): ?>
        <div class="package-card">
            <img src="<?= resolveImagePath($package['package_image']); ?>" alt="Package Image">
            <div class="package-card-content">
                <h4><?= htmlspecialchars($package['package_title']); ?></h4>
                <p>₱<?= number_format($package['price'],2); ?> / pax</p>
                <button class="p-edit-p-btn" onclick="openModal(<?= $package['package_id']; ?>)">Edit Package</button>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</section>
</div>
</main>
</div>

<!-- Edit Package Modal (Modernized & Rounded) -->
<div id="editModal" class="modal">
    <div class="modal-content">
        <span class="close" onclick="closeModal()">&times;</span>
        <div class="modal-section-head">
        <strong id="af-media-modal-title">Edit Package</strong>
        </div>
        <form id="editPackageForm" method="post" enctype="multipart/form-data">
            <input type="hidden" name="package_id" id="package_id">
        <div class="form-row" style="display:flex; gap:20px; flex-wrap:wrap;">
    <div class="form-group" style="flex:1;">
        <label for="package_title" style="font-weight:bold;">Package Title:</label>
        <input type="text" name="package_title" id="package_title" class="form-input" placeholder="Enter package title" required>
    </div>

    <div class="form-group" style="flex:1;">
        <label for="price" style="font-weight:bold;">Price:</label>
        <input type="number" name="price" id="price" class="form-input" placeholder="Enter price" step="0.01" required>
    </div>
</div>

<div class="form-row" style="display:flex; gap:20px; flex-wrap:wrap; margin-top:10px;">
    <div class="form-group" style="flex:1;">
        <label for="package_type" style="font-weight:bold;">Package Type:</label>
        <select name="package_type" id="package_type" class="form-input" required>
            <option value="">-- Select Package Type --</option>
            <option value="same-day">Same-day</option>
            <option value="overnight">Overnight</option>
        </select>
    </div>

    <div class="form-group" style="flex:1;">
        <label for="package_range" style="font-weight:bold;">Package Range:</label>
        <input type="text" name="package_range" id="package_range" class="form-input" placeholder="(e.g. 1 day | 1 Day 1 Night)">
    </div>
</div>



        <h4>Package Images</h4>
        <div id="packageImagesContainer" style="display:flex; gap:15px; flex-wrap:wrap;"></div>

        <h4>General Images (All Steps)</h4>
        <div id="generalImagesContainer" style="display:flex; gap:20px; flex-wrap:wrap;">
            <div style="text-align: left;">
                <label style="font-weight: bold; display:block; text-align:left;">Location Image:</label><br>
                <img id="location_image_preview" src="img/sampleimage.png" style="width:150px;height:100px;object-fit:cover;border-radius:8px;"><br>
                <button type="button" class="btn-green" onclick="openGeneralImageModal('location_image')" style="margin-top: 9px">Update Image</button>
            </div>
            <div style="text-align: left;">
                <label style="font-weight: bold; display:block; text-align:left; margin-bottom: 19px ">Route Image:</label>
                <img id="route_image_preview" src="img/sampleimage.png" style="width:150px;height:100px;object-fit:cover;border-radius:8px;"><br>
                <button type="button" class="btn-green" onclick="openGeneralImageModal('route_image')" style="margin-top: 10px">Update Image</button>
            </div>
        </div>

            <h4>Itinerary Steps</h4>
            <div id="itineraryContainer"></div>
            <button type="button" class="add-step-btn" onclick="addItineraryStep()">Add Step</button>
            <br><br>
            <button type="button" class="btn-cancel" onclick="closeModal()">Cancel</button>
            <button type="submit" class="btn-green">Save Changes</button>
        </form>
    </div>
</div>

<!-- Image Upload & Crop Modal -->
<div id="imageModal" class="modal">
    <div class="modal-content">
        <span class="close" onclick="closeImageModal()">&times;</span>
        <h3 class="image-modal-title">Upload & Crop Image</h3>

        <!-- Custom Drag Area -->
        <div class="custum-file-upload" id="dragArea">
            <div class="icon">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24">
                    <path d="M10 1L3 7v13c0 1.7 1.3 3 3 3h1"/>
                </svg>
            </div>
            <div class="text">
                <span>Drag & drop image here or click</span>
            </div>
            <input type="file" id="imageInput" accept="image/*" hidden>
        </div>

        <!-- Cropper Container -->
        <div id="cropContainer" style="display:none; margin-top:20px; text-align:center;">
            <div style="width:100%; max-width:500px; height:350px; margin:0 auto; border:1px solid #ddd; border-radius:5px; overflow:hidden;">
                <img id="cropImage" style="width:100%; height:100%; object-fit:contain;">
            </div>
        </div>

        <!-- Controls -->
        <div style="margin-top:10px; display:flex; gap:10px; justify-content:center;">
            <button id="doneBtn" class="btn btn-green" style="display:none;">Done</button>
            <button id="cancelBtn" class="btn btn-red" style="display:none;">Cancel</button>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/cropperjs@1.5.13/dist/cropper.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.0/Sortable.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>

<script>
// ===============================
// Packages Data (JS)
let packagesData = <?php
$jsPackages = [];
foreach($packages as $p){
    $packageId = $p['package_id'];

    // Get first itinerary step for general images
    $stmtIt = $pdo->prepare("SELECT location_image, route_image FROM package_itinerary WHERE package_id=? ORDER BY display_order ASC LIMIT 1");
    $stmtIt->execute([$packageId]);
    $firstStep = $stmtIt->fetch(PDO::FETCH_ASSOC) ?: [];

    // Get all itinerary steps
    $stmtSteps = $pdo->prepare("SELECT * FROM package_itinerary WHERE package_id=? ORDER BY display_order ASC");
    $stmtSteps->execute([$packageId]);
    $steps = $stmtSteps->fetchAll(PDO::FETCH_ASSOC);

    $jsPackages[$packageId] = [
        'package_title'=>$p['package_title'],
        'package_image'=>resolveImagePath($p['package_image']),
        'price' => $p['price'], // ← Add this line
        'package_image2'=>resolveImagePath($p['package_image2']),
        'package_image3'=>resolveImagePath($p['package_image3']),
        'package_image4'=>resolveImagePath($p['package_image4']),
        'location_image'=>resolveImagePath($firstStep['location_image'] ?? ''),
        'route_image'=>resolveImagePath($firstStep['route_image'] ?? ''),
        'package_type'=>$p['package_type'],
        'package_range'=>$p['package_range'],
        'itinerary'=>$steps
    ];
}
echo json_encode($jsPackages, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG);
?>;

/* =====================================================
   GLOBAL STATE
===================================================== */
let currentPackageId = null;
let cropper = null;
let currentImageField = null;

/* =====================================================
   DOM ELEMENTS (DECLARE ONCE)
===================================================== */
const editModal = document.getElementById('editModal');
const imageModal = document.getElementById('imageModal');
const dragArea = document.getElementById('dragArea');
const imageInput = document.getElementById('imageInput');
const cropContainer = document.getElementById('cropContainer');
const cropImage = document.getElementById('cropImage');
const doneBtn = document.getElementById('doneBtn');
const cancelBtn = document.getElementById('cancelBtn');
const itineraryContainer = document.getElementById('itineraryContainer');

/* =====================================================
   MODAL OPEN / CLOSE
===================================================== */

// ======= OPEN EDIT MODAL =======
function openModal(packageId){
    currentPackageId = packageId;
    const pkg = packagesData[packageId];
    if(!pkg) return;

    document.getElementById('af-media-modal-title').innerText = 'Edit Package';
    editModal.style.display = 'flex';

    document.getElementById('package_id').value = packageId;
    document.getElementById('package_title').value = pkg.package_title || '';
    document.getElementById('price').value = pkg.price || '';
    document.getElementById('package_type').value = pkg.package_type || '';
    document.getElementById('package_range').value = pkg.package_range || '';

    renderPackageImages(pkg);
    renderGeneralImages(pkg);
    renderItinerary(pkg);
}

function closeModal(){
    editModal.style.display = 'none';
    itineraryContainer.innerHTML = '';
}

/* =====================================================
   ADD PACKAGE
===================================================== */
// ======= OPEN ADD PACKAGE =======
function openAddModal(){
    currentPackageId = 0;
    editModal.style.display = 'flex';
    document.getElementById('af-media-modal-title').innerText = 'Add New Package';
    document.getElementById('editPackageForm').reset();
    document.getElementById('package_id').value = 0;

    renderEmptyPackageImages();
    document.getElementById('location_image_preview').src = 'img/placeholder.png';
    document.getElementById('route_image_preview').src = 'img/placeholder.png';

    itineraryContainer.innerHTML = '';
    addItineraryStep(); // Always start with 1 empty step
}



/* =====================================================
   PACKAGE IMAGES
===================================================== */
function renderPackageImages(pkg){
    const container = document.getElementById('packageImagesContainer');
    container.innerHTML = '';

    ['package_image','package_image2','package_image3','package_image4'].forEach(field=>{
        const imgSrc = pkg[field] || 'img/placeholder.png';
        const wrap = document.createElement('div');
        wrap.innerHTML = `
            <img src="${imgSrc}" data-field="${field}" style="width:150px;height:100px;object-fit:cover;border-radius:8px;">
            <br>
            <button type="button" class="btn-green" onclick="openGeneralImageModal('${field}')">Update Image</button>
        `;
        container.appendChild(wrap);
    });
}

function renderGeneralImages(pkg){
    document.getElementById('location_image_preview').src = pkg.location_image || 'img/placeholder.png';
    document.getElementById('route_image_preview').src = pkg.route_image || 'img/placeholder.png';
}

/* =====================================================
   ITINERARY
===================================================== */

// ======= RENDER ITINERARY =======
function renderItinerary(pkg){
    itineraryContainer.innerHTML = '';

    if(pkg.itinerary && pkg.itinerary.length){
        pkg.itinerary.forEach(step => addItineraryStep(step));
    } else {
        addItineraryStep();
    }

    // Destroy previous Sortable instance if exists
    if(window.itinerarySortable) window.itinerarySortable.destroy();

    // Create new Sortable instance
    window.itinerarySortable = new Sortable(itineraryContainer, {
        handle: '.drag-handle',
        animation: 150,
        onEnd: updateDisplayOrders // ✅ Update display_order after dragging
    });

    // Initialize display_order values
    updateDisplayOrders();
}

// ======= ADD ITINERARY STEP =======
function addItineraryStep(step = {}) {
    const div = document.createElement('div');
    div.className = 'itinerary-step';
    div.innerHTML = `
        <input type="hidden" name="itinerary_id[]" value="${step.itinerary_id || ''}">
        <input type="hidden" name="display_order[]" value="${step.display_order || ''}">
        <div class="drag-handle">☰</div>
        <div class="step-content">
            <div class="top-row">
                <div class="form-group">
                    <label>Step Title</label>
                    <input type="text" name="step_title[]" value="${step.step_title || ''}">
                </div>
                <div class="form-group">
                    <label>Start Time</label>
                    <input type="time" name="start_time[]" value="${step.start_time || ''}">
                </div>
                <div class="form-group">
                    <label>End Time</label>
                    <input type="time" name="end_time[]" value="${step.end_time || ''}">
                </div>
            </div>
            <div class="form-group">
                <label>Description</label>
                <textarea name="description[]">${step.description || ''}</textarea>
            </div>
            <button type="button" class="btn-red" onclick="this.closest('.itinerary-step').remove()">Remove Step</button>
        </div>
    `;
    itineraryContainer.appendChild(div);
}

function updateDisplayOrders() {
    document.querySelectorAll('.itinerary-step').forEach((step, index) => {
        const input = step.querySelector('input[name="display_order[]"]');
        if (input) input.value = index + 1; // top step = 1, next = 2...
    });
}



/* =====================================================
   IMAGE MODAL
===================================================== */
// ======= IMAGE MODAL =======
function openGeneralImageModal(field){
    currentImageField = field;
    imageInput.value = '';
    dragArea.style.display = 'flex';
    cropContainer.style.display = 'none';
    doneBtn.style.display = 'none';
    cancelBtn.style.display = 'none';
    if(cropper){ cropper.destroy(); cropper = null; }
    imageModal.style.display = 'flex';
}

function closeImageModal(){
    imageModal.style.display = 'none';
    imageInput.value = '';

    if(cropper){ cropper.destroy(); cropper = null; }

    dragArea.style.display = 'flex';
    cropContainer.style.display = 'none';
    doneBtn.style.display = 'none';
    cancelBtn.style.display = 'none';
}

/* =====================================================
   DRAG & DROP
===================================================== */
dragArea.addEventListener('click',()=>imageInput.click());

dragArea.addEventListener('dragover',e=>{
    e.preventDefault();
    dragArea.classList.add('dragover');
});

dragArea.addEventListener('dragleave',()=>dragArea.classList.remove('dragover'));

dragArea.addEventListener('drop',e=>{
    e.preventDefault();
    dragArea.classList.remove('dragover');
    if(!e.dataTransfer.files.length) return;
    imageInput.files = e.dataTransfer.files;
    imageInput.dispatchEvent(new Event('change',{bubbles:true}));
});

/* =====================================================
   IMAGE INPUT
===================================================== */
imageInput.addEventListener('change',()=>{
    const file = imageInput.files[0];
    if(!file) return;

    cropImage.src = URL.createObjectURL(file);
    dragArea.style.display = 'none';
    cropContainer.style.display = 'block';
    doneBtn.style.display = 'inline-block';
    cancelBtn.style.display = 'inline-block';

    if(cropper) cropper.destroy();
    cropper = new Cropper(cropImage,{aspectRatio:4/3,viewMode:1});
});

/* =====================================================
   CROP CONFIRM
===================================================== */
doneBtn.addEventListener('click',()=>{
    if(!cropper || !currentImageField) return;
    const canvas = cropper.getCroppedCanvas({width:600,height:400});
    const dataUrl = canvas.toDataURL('image/jpeg');

    if(currentImageField.startsWith('package_image')){
        const img = document.querySelector(`img[data-field="${currentImageField}"]`);
        if(img) img.src = dataUrl;
    } else if(currentImageField === 'location_image'){
        document.getElementById('location_image_preview').src = dataUrl;
    } else if(currentImageField === 'route_image'){
        document.getElementById('route_image_preview').src = dataUrl;
    }
    closeImageModal();
});

cancelBtn.addEventListener('click',closeImageModal);

/* =====================================================
   SUBMIT
===================================================== */
// ======= FORM SUBMIT =======
document.getElementById('editPackageForm').addEventListener('submit', e => {
    e.preventDefault();
    const formData = new FormData(e.target);

    document.querySelectorAll('#packageImagesContainer img').forEach(img => {
        formData.append(img.dataset.field + '_data', img.src);
    });
    formData.append('location_image_data', document.getElementById('location_image_preview').src);
    formData.append('route_image_data', document.getElementById('route_image_preview').src);

    fetch('php/update_operator_package.php', {
        method: 'POST',
        body: formData
    }).then(r => r.json())
    .then(d => {
        if (d.success) {
            Swal.fire({
                title: 'Success',
                text: 'Package saved',
                icon: 'success',
                confirmButtonText: 'OK',
                customClass: {
                    confirmButton: 'swal2-confirm-green'
                }
            }).then(() => location.reload());
        } else {
            Swal.fire({
                title: 'Error',
                text: d.message,
                icon: 'error',
                confirmButtonText: 'OK',
                customClass: {
                    confirmButton: 'swal2-confirm-green'
                }
            });
        }
    });
});

function renderEmptyPackageImages(){
    const container = document.getElementById('packageImagesContainer');
    container.innerHTML = '';

    ['package_image','package_image2','package_image3','package_image4'].forEach(field=>{
        const wrap = document.createElement('div');
        wrap.innerHTML = `
            <img src="img/placeholder.png"
                 data-field="${field}"
                 style="width:150px;height:100px;object-fit:cover;border-radius:8px;">
            <br>
            <button type="button"
                    class="btn-green"
                    onclick="openGeneralImageModal('${field}')">
                Update Image
            </button>
        `;
        container.appendChild(wrap);
    });
}

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
        await fetch('optourpackages.php', {
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

