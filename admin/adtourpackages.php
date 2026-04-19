<?php
chdir(__DIR__ . '/..');
session_start();
require 'php/db_connection.php';

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

// ✅ Session Authentication Check
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    echo "<script>
        alert('Session expired! Please login again.');
        window.location.href = 'php/admin_login.php';
    </script>";
    exit();
}

// Fetch operators
$operatorsStmt = $pdo->query("SELECT operator_id, fullname FROM operators ORDER BY fullname ASC");
$operators = $operatorsStmt->fetchAll(PDO::FETCH_ASSOC);

// ✅ Fetch All Tour Packages
$stmt = $pdo->query("SELECT * FROM tour_packages ORDER BY package_id DESC");
$packages = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>iTour Mercedes - Tour Packages</title>
<link rel="icon" type="image/png" href="img/newlogo.png">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/cropperjs@1.5.13/dist/cropper.min.css">


<style>
body { margin:0; font-family:'Inter',sans-serif; background:#f5f7fa; scroll-behavior:smooth; }
.admin-container { display:flex; min-height:100vh; }
.main-content { flex:1; margin-left:240px; display:flex; flex-direction:column; }
.admin-sidebar.collapsed ~ .main-content { margin-left:80px; }
.admin-header { background:white; padding:1rem 2rem; border-bottom:2px solid #eee; display:flex; justify-content:space-between; align-items:center; box-shadow:0 2px 5px rgba(0,0,0,0.05); }
.admin-header h2 { color:#2b7a66; font-size:1.6rem; font-weight:700; margin:0; }
.nav-links a { margin-left:20px; text-decoration:none; color:#2b7a66; font-weight:600; }
.nav-links a:hover { color:#2f7d54; }
.dashboard-content {
    width: 100%;
    max-width: 1420px;
    margin: 0 auto;
    padding: 18px 24px 28px;
    box-sizing: border-box;
}
.tour-content-shell {
    display: grid;
    gap: 18px;
}
.tour-secondary-nav {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    flex-wrap: wrap;
    background: #ffffff;
    border: 1px solid #dbe7e2;
    border-radius: 14px;
    padding: 10px 12px;
}
.tour-tab-list {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    background: #edf3f0;
    border: 1px solid #d2e2dc;
    border-radius: 999px;
    padding: 4px;
    order: 2;
    margin-left: auto;
}
.tour-tab-btn {
    border: 0;
    background: transparent;
    border-radius: 999px;
    color: #40616e;
    font-size: 12px;
    font-weight: 700;
    line-height: 1.2;
    padding: 7px 14px;
    min-width: 112px;
    cursor: pointer;
    transition: background .18s ease, color .18s ease, box-shadow .18s ease;
}
.tour-tab-btn:hover {
    background: rgba(255,255,255,0.65);
    color: #1f4854;
}
.tour-tab-btn.active {
    background: #ffffff;
    color: #1f4854;
    box-shadow: 0 3px 10px rgba(20, 56, 45, 0.12);
}
.tour-search-wrap {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    order: 1;
    flex: 1 1 340px;
    max-width: 460px;
}
.tour-search-wrap input {
    width: 100%;
    max-width: 100%;
    height: 38px;
    border: 1px solid #d1dfd9;
    border-radius: 10px;
    padding: 0 12px;
    font-size: 12.5px;
    color: #1f2f3a;
    background: #fff;
}
.tour-search-wrap input:focus {
    outline: none;
    border-color: #8eb9aa;
    box-shadow: 0 0 0 3px rgba(43, 122, 102, 0.12);
}
.tour-tab-panel {
    display: none;
}
.tour-tab-panel.active {
    display: grid;
    gap: 16px;
}
section {
    margin: 0;
    padding: 0;
}
.tour-panel-card {
    background: #ffffff;
    border: 1px solid #dbe7e2;
    border-radius: 14px;
    box-shadow: 0 5px 16px rgba(20, 55, 44, 0.06);
    padding: 18px;
}
.section-title {
    font-size: 21px;
    font-weight: 800;
    color: #2b7a66;
    margin: 0;
    line-height: 1.2;
    position: relative;
}
.section-title::after {
    content:'';
    position:absolute;
    width:52px;
    height:3px;
    background:#2b7a66;
    left:0;
    bottom:-5px;
    border-radius:2px;
}
.tour-section-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    margin-bottom: 16px;
}
.tour-section-head .section-title {
    font-size: 21px;
}
.tour-tab-empty {
    display: none;
    margin: 8px 0 0;
    font-size: 13px;
    color: #5e6f78;
}
.tour-tab-empty.show {
    display: block;
}
.service-prices-grid {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 14px;
}
.service-prices-grid .form-group {
    min-width: 0;
}
.service-prices-grid .form-input {
    height: 36px;
    margin-top: 6px;
    padding: 0 10px;
    box-sizing: border-box;
    font-size: 13px;
}
#service-prices .form-group label {
    font-size: 13px;
}
.tour-panel-card label {
    font-size: 14px;
    font-weight: 700;
}
.add-new-p-btn {
    background: #2b7a66;
    color: #fff;
    border: 0;
    border-radius: 9px;
    padding: 9px 14px;
    font-size: 13px;
    font-weight: 700;
    cursor: pointer;
    white-space: nowrap;
}
/* Container for cards: 3 per row */
.cards-container {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
    gap: 16px;
    margin-top: 0;
}

.modal-content h3, h4 {
    margin-bottom: 1rem;
}

/* Package card styling remains same */
.package-card {
    background:white;
    border-radius:12px;
    border: 1px solid #dbe7e2;
    box-shadow: 0 4px 14px rgba(19, 53, 43, 0.08);
    display:flex;
    flex-direction:column;
    overflow:hidden;
    min-height: 400px;
    height: 100%;
}
.package-card:hover { transform: translateY(-2px); }
.package-card img { width:100%; height:230px; object-fit:cover; }
.package-card-content { padding:1rem; display:flex; flex-direction:column; flex:1; }
.package-card-content h4 { margin:0 0 0.5rem; color:#2b7a66; font-size:1rem; line-height:1.35; }
.package-card-content p {
    margin: 12px 0 1rem;
    font-weight: 700;
    color: #162530;
    font-size: .97rem;
}
.package-card-content button { padding:0.56rem .92rem; background:#49A47A; color:white; border:none; border-radius:8px; cursor:pointer; font-weight:600; font-size:13px; transition:0.2s; }
.package-card-content button:hover { background:#2f7d54; }

.tour-tab-panel[data-tab="guides"] .jg_guide-grid {
    gap: 14px !important;
}
.tour-tab-panel[data-tab="guides"] .jg_guide-card {
    border: 1px solid #dbe7e2 !important;
    box-shadow: 0 4px 14px rgba(19, 53, 43, 0.08) !important;
    border-radius: 12px !important;
    padding: 10px !important;
    gap: 12px !important;
    min-height: 0 !important;
}
.tour-tab-panel[data-tab="guides"] .jg_guide-name {
    font-size: 15px !important;
}
.tour-tab-panel[data-tab="guides"] .jg_guide-short {
    font-size: 12.5px !important;
    margin: 4px 0 8px !important;
}
.tour-tab-panel[data-tab="guides"] .jg_guide-img-col {
    width: 96px !important;
}
.tour-tab-panel[data-tab="guides"] .jg_guide-photo {
    width: 96px !important;
    height: 96px !important;
}
.tour-tab-panel[data-tab="guides"] .jg_guide-capsules {
    gap: 6px !important;
}
.tour-tab-panel[data-tab="guides"] .jg_guide-capsules span {
    padding: 4px 10px !important;
    font-size: 12px !important;
}
.tour-tab-panel[data-tab="guides"] .jg_guide-footer {
    margin-top: 8px !important;
}

.tour-tab-panel[data-tab="boats"] .boat-card {
    border: 1px solid #dbe7e2 !important;
    box-shadow: 0 4px 14px rgba(19, 53, 43, 0.08) !important;
    border-radius: 12px !important;
    margin-bottom: 12px !important;
    padding: 10px 14px 12px !important;
}
.tour-tab-panel[data-tab="boats"] .boat-info h3 {
    font-size: 19px !important;
    margin: 0 0 6px !important;
}
.tour-tab-panel[data-tab="boats"] .boat-info p {
    font-size: 12.5px !important;
    margin: 4px 0 !important;
}
.tour-tab-panel[data-tab="boats"] .boat-details {
    margin-top: 10px !important;
    gap: 6px !important;
}
.tour-tab-panel[data-tab="boats"] .boat-details span {
    font-size: 12px !important;
    padding: 3px 8px !important;
}
.tour-tab-panel[data-tab="boats"] .boat-images {
    margin-top: 12px !important;
    gap: 8px !important;
}
.tour-tab-panel[data-tab="boats"] .boat-images img {
    width: 108px !important;
    height: 76px !important;
}
.tour-tab-panel[data-tab="boats"] .boat-edit-btn {
    bottom: 10px !important;
    right: 12px !important;
    padding: 7px 12px !important;
    font-size: 12px !important;
}

@media (max-width: 1180px) {
    .dashboard-content {
        padding: 16px 16px 24px;
    }
    .service-prices-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
}
@media (max-width: 760px) {
    .tour-secondary-nav {
        align-items: stretch;
    }
    .tour-search-wrap {
        width: 100%;
        max-width: none;
    }
    .tour-search-wrap input {
        width: 100%;
    }
    .tour-tab-list {
        width: 100%;
        order: 2;
        overflow-x: auto;
    }
    .tour-tab-btn {
        min-width: 104px;
        padding: 7px 11px;
    }
    .service-prices-grid {
        grid-template-columns: 1fr;
    }
}

/* Modal overlay */
/* Modal overlay */
.modal {
  display: none;               /* hidden by default */
  position: fixed;
  top: 0;
  left: 0;
  width: 100%;
  height: 100%;
  background: rgba(0,0,0,0.5);
  z-index: 1000;
  justify-content: center;
  align-items: center;
  padding: 1rem;
  box-sizing: border-box;
}

/* Modal content */
.modal-content {
  background: white;
  border-radius: 10px;
  width: 90%;
  max-width: 1000px;
  max-height: 90vh;
  overflow-y: auto;    /* scroll inside modal if content overflows */
  padding: 1.5rem;
  box-sizing: border-box;
  position: relative; /* <-- Add this */
}

/* Custom upload */
.custum-file-upload {
  height: 200px;
  width: 100%;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 20px;
  cursor: pointer;
  border: 2px dashed #cacaca;
  background-color: #fff;
  padding: 1.5rem;
  border-radius: 10px;
  box-shadow: 0px 48px 35px -48px rgba(0,0,0,0.1);
}

.custum-file-upload .icon svg {
  height: 80px;
  fill: rgba(75, 85, 99, 1);
}

.custum-file-upload .text span {
  font-weight: 400;
  color: rgba(75, 85, 99, 1);
}

.custum-file-upload input {
  display: none;
}
.close {
    position: absolute;
    top: 15px;
    right: 15px;
    font-size: 28px;
    font-weight: bold;
    color: #aaa;
    cursor: pointer;
}
.close:hover { color: #000; }

.drag-area { border:2px dashed #2b7a66; padding:25px; text-align:center; border-radius:10px; margin-bottom:15px; cursor:pointer; font-weight:600; color:#2b7a66; transition:0.2s; }
.drag-area:hover { background:#f0fff4; border-color:#2f7d54; }
.drag-area img { max-width:100%; max-height:250px; object-fit:cover; border-radius:8px; }

.itinerary-step {
    display: flex;
    flex-direction: row; /* handle + content */
    align-items: center; /* <-- center vertically */
    padding: 15px 20px;
    margin-bottom: 15px;
    border-radius: 12px;
    background: #ffffff;
    border: 1px solid #d2d2d2;
    box-shadow: 0 2px 10px rgba(0,0,0,0.05);
    gap: 15px;
    position: relative;
}

.drag-handle {
    cursor: grab;
    font-size: 1.5rem;
    color: #2b7a66;
    flex-shrink: 0;
    user-select: none;
    display: flex;
    align-items: center; /* vertical center inside its container */
    justify-content: center;
}

.drag-handle:active {
    cursor: grabbing;
}


.step-content {
    flex: 1;
    display: flex;
    flex-direction: column;
    gap: 10px;
}

.step-content .top-row {
    width: 95%;
    display: grid;
    grid-template-columns: repeat(3, 0.5fr); /* 3 equal columns */
    gap: 45px; /* spacing between inputs */
}

.step-content .top-row .form-group {
    display: flex;
    flex-direction: column;
}

/* Description textarea */
.step-content textarea {
    margin-top: 10px;
    width: 95%;
    padding: 8px 10px;
    border-radius: 8px;
    border: 1px solid #ccc;
    font-size: 0.95rem;
    resize: vertical;
    outline: none;
    transition: all 0.2s ease;
}

.step-content textarea:focus {
    border-color: #49A47A;
    box-shadow: 0 0 5px rgba(73,164,122,0.3);
}

/* Inputs */
.step-content input {
    margin-top: 10px;
    width: 100%;
    padding: 8px 10px;
    border-radius: 8px;
    border: 1px solid #ccc;
    font-size: 0.95rem;
    outline: none;
    transition: all 0.2s ease;
}

.step-content input:focus {
    border-color: #49A47A;
    box-shadow: 0 0 5px rgba(73,164,122,0.3);
}

/* Remove Step button */
.itinerary-step .btn-red {
    margin-top: 10px;
    align-self: flex-start;
}

/* Responsive */
@media (max-width: 600px) {
    .step-content .top-row {
        flex-direction: column;
    }
}

/* Add Step Button */
.add-step-btn { 
    margin-top: 10px; 
    background-color: #2b7a66; 
    color: white; 
    padding: 0.6rem 1rem; 
    border: none; 
    border-radius: 8px; 
    cursor: pointer; 
    font-weight: 600; 
    transition: 0.2s;
}
.add-step-btn:hover { background-color: #2f7d54; }
.btn-green { background:#2b7a66; color:white; padding:0.5rem 1rem; border:none; border-radius:8px; cursor:pointer; font-weight:600; transition:0.2s; }
.btn-green:hover { background:#2f7d54; }
.btn-red { background:#e74c3c; color:white; padding:0.5rem 1rem; border:none; border-radius:8px; cursor:pointer; font-weight:600; transition:0.2s; }
.btn-red:hover { background:#c0392b; }

#cropContainer {
    display:flex;
    justify-content:center;
    align-items:center;
    padding:15px 0;
}

/* Flex row for side-by-side inputs */
.form-row {
    display: flex;
    gap: 20px; /* space between fields */
    flex-wrap: wrap; /* responsive wrap */
}

/* Each input group takes half width */
.form-row .form-group {
    flex: 1 1 200px; /* grow, shrink, min-width 200px */
    display: flex;
    flex-direction: column;
}

/* Input fields style */
.form-input {
    margin-top: 10px;
    padding: 10px 15px;
    border-radius: 10px;
    border: 1px solid #ccc;
    font-size: 1rem;
    transition: all 0.2s ease;
    outline: none;
    box-shadow: inset 0 1px 3px rgba(0,0,0,0.05);
}

/* Input focus effect */
.form-input:focus {
    border-color: #49A47A;
    box-shadow: 0 0 5px rgba(73,164,122,0.5);
}

/* Image Upload & Crop Modal Size */
#imageModal .modal-content {
    width: 650px !important;
    max-width: 650px;
    height: auto !important;      /* auto height to fit content */
    max-height: 90vh;             /* limit max height to viewport */
    padding: 10px;
    box-sizing: border-box;
    display: flex;
    flex-direction: column;
    justify-content: center;
    align-items: center;
    position: relative;
}

/* Drag area stays fixed */
#imageModal .custum-file-upload {
    width: 550px;       /* fixed width */
    max-width: 550px;
    height: 300px;      /* fixed height */
    margin: 0 auto;     /* center horizontally */
    box-sizing: border-box;
}

/* Crop container auto size */
#cropContainer {
    width: auto;        /* fit content */
    height: auto;
    max-width: 550px;   /* same max width as drag area */
    max-height: 70vh;   /* leave space for buttons */
    margin: 10px auto 0 auto;
    padding: 0;
}

/* Cropper image */
#cropContainer img {
    width: 100%;        /* fill crop container width */
    height: auto;       /* maintain aspect ratio */
    max-height: 100%;
    object-fit: contain;
    border-radius: 5px;
}

.btn-cancel {
    background: #f0f0f0;        /* gray background */
    padding: 0.5rem 1rem;
    border: none;
    border-radius: 8px;
    cursor: pointer;
    font-weight: 600;
    transition: 0.2s;
}
.btn-cancel:hover {
    background: #888;
}

.add-new-p-btn:hover {
    background: #24614f!important;
}

.p-edit-p-btn:hover {
    background: #24614f !important;
}

</style>
<link rel="stylesheet" href="styles/admin_panel_theme.css" />
</head>

<body>
<div class="admin-container">
<?php include 'admin_sidebar.php'; ?>

<main class="main-content">
<header class="admin-header">
    <div class="admin-header-left">
        <h2>Manage Tour Packages</h2>
        <p class="admin-header-subtitle">Welcome, <?= $admin_username ?? 'Website Admin' ?></p>
    </div>
</header>

<?php
function getImagePath($imgField) {
    // Fallback for empty value
    if (!$imgField || trim($imgField) === '') {
        return 'img/placeholder.png';
    }

    // Remove leading slashes
    $imgField = ltrim($imgField, '/');

    // If the path already exists as is
    if (file_exists(__DIR__ . '/' . $imgField)) {
        return $imgField;
    }

    // Check in upload folder
    $uploadPath = 'php/upload/' . basename($imgField);
    if (file_exists(__DIR__ . '/' . $uploadPath)) {
        return $uploadPath;
    }

    // Check in img folder
    $imgPath = 'img/' . basename($imgField);
    if (file_exists(__DIR__ . '/' . $imgPath)) {
        return $imgPath;
    }

    // Default fallback
    return 'img/placeholder.png';
}
?>

<div class="dashboard-content">
<?php
$pricesStmt = $pdo->query("SELECT * FROM service_prices WHERE service_type IN ('boat','tourguide') AND is_active=1");
$services = $pricesStmt->fetchAll(PDO::FETCH_ASSOC);

$serviceMap = [];
foreach($services as $s){
    $serviceMap[$s['service_type']] = $s;
}
?>

<div class="tour-content-shell">
    <div class="tour-secondary-nav">
        <div class="tour-tab-list" role="tablist" aria-label="Manage tour content tabs">
            <button type="button" class="tour-tab-btn active" data-tab="packages" role="tab" aria-selected="true">Tour Packages</button>
            <button type="button" class="tour-tab-btn" data-tab="guides" role="tab" aria-selected="false">Tour Guides</button>
            <button type="button" class="tour-tab-btn" data-tab="boats" role="tab" aria-selected="false">Boats</button>
        </div>
        <div class="tour-search-wrap">
            <input type="text" id="tourContentSearch" placeholder="Search tour packages..." autocomplete="off">
        </div>
    </div>

    <div class="tour-tab-panel active" data-tab="packages">
        <section id="service-prices" class="tour-panel-card">
            <h3 class="section-title">Boat and Tourguide Prices</h3>
            <form id="updatePricesForm" style="margin-top:1.2rem;">
                <div class="form-row service-prices-grid">
                    <div class="form-group">
                        <label style="font-weight:bold">Boat Price (Day Tour):</label>
                        <input type="number" step="0.01" name="boat_day" class="form-input"
                               value="<?= $serviceMap['boat']['day_tour_price'] ?? '' ?>" required>
                    </div>
                    <div class="form-group">
                        <label style="font-weight:bold">Boat Price (Overnight):</label>
                        <input type="number" step="0.01" name="boat_overnight" class="form-input"
                               value="<?= $serviceMap['boat']['overnight_price'] ?? '' ?>" required>
                    </div>
                    <div class="form-group">
                        <label style="font-weight:bold">Tour Guide Price (Day Tour):</label>
                        <input type="number" step="0.01" name="tourguide_day" class="form-input"
                               value="<?= $serviceMap['tourguide']['day_tour_price'] ?? '' ?>" required>
                    </div>
                    <div class="form-group">
                        <label style="font-weight:bold">Tour Guide Price (Overnight):</label>
                        <input type="number" step="0.01" name="tourguide_overnight" class="form-input"
                               value="<?= $serviceMap['tourguide']['overnight_price'] ?? '' ?>" required>
                    </div>
                </div>
                <button type="submit" class="btn-green" style="margin-top:12px;">Update Prices</button>
            </form>
        </section>

        <section id="tour-packages" class="tour-panel-card">
            <div class="tour-section-head">
                <h3 class="section-title">Tour Packages</h3>
                <button class="add-new-p-btn" onclick="openAddModal()">Add New Tour Package</button>
            </div>
            <div class="cards-container">
                <?php foreach($packages as $package): ?>
                <div class="package-card">
                    <img src="<?= getImagePath($package['package_image']); ?>" alt="Package Image">
                    <div class="package-card-content">
                        <h4><?= htmlspecialchars($package['package_title']); ?></h4>
                        <p>₱<?= number_format($package['price'],2); ?> / pax</p>
                        <button class="p-edit-p-btn" style="background-color: #2b7a66" onclick="openModal(<?= $package['package_id']; ?>)">Edit Package</button>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <p class="tour-tab-empty" data-empty-for="packages">No matching tour packages found.</p>
        </section>
    </div>

    <div class="tour-tab-panel" data-tab="guides">
        <section id="tour-guides" class="tour-panel-card">
            <div class="tour-section-head">
                <h3 class="section-title">Tour Guides</h3>
                <button id="jg_guide_add_btn" type="button" class="add-new-p-btn">Add New Tour Guide</button>
            </div>
            <?php $showGuideInlineToolbar = false; ?>
            <?php include 'adtourguides.php'; ?>
            <p class="tour-tab-empty" data-empty-for="guides">No matching tour guides found.</p>
        </section>
    </div>

    <div class="tour-tab-panel" data-tab="boats">
        <section id="boats" class="tour-panel-card">
            <div class="tour-section-head">
                <h3 class="section-title">Boats</h3>
                <button id="add-boat-btn" type="button" class="add-new-p-btn">Add New Boat</button>
            </div>
            <?php $showBoatInlineToolbar = false; ?>
            <?php include 'adboats.php'; ?>
            <p class="tour-tab-empty" data-empty-for="boats">No matching boats found.</p>
        </section>
    </div>
</div>

</div>

</main>

</div>
<!-- Edit Package Modal (Modernized & Rounded) -->
<div id="editModal" class="modal">
    <div class="modal-content">
        <span class="close" onclick="closeModal()">&times;</span>
        <div class="af-modal-header" style="display:flex; justify-content:center; position:relative; padding-bottom:12px; border-bottom:1px solid #eee; margin-bottom: 1rem">
        <strong id="af-media-modal-title" style="font-size:1.2rem; color:#2b7a66;">Edit Package</strong>
        </div>
        <form id="editPackageForm" method="post" enctype="multipart/form-data">
            <input type="hidden" name="package_id" id="package_id">
        <div class="form-row">
            <div class="form-group">
                <label for="package_title" style="font-weight: bold">Package Title:</label>
                <input type="text" name="package_title" id="package_title" class="form-input" placeholder="Enter package title" required>
            </div>

            <div class="form-group">
                <label for="price" style="font-weight: bold">Price:</label>
                <input type="number" name="price" id="price" class="form-input" placeholder="Enter price" step="0.01" required>
            </div>
        </div>

        <div class="form-row" style="margin-top: 10px">
            <div class="form-group">
                <label style="font-weight:bold">Operator</label>
                <select name="operator_id" id="operator_id" class="form-input" required></select>
            </div>

        <div class="form-group">
            <label for="package_type" style="font-weight:bold">Package Type</label>
            <select name="package_type" id="package_type" class="form-input" required>
                <option value="">-- Select Package Type --</option>
                <option value="same-day">Same-day</option>
                <option value="overnight">Overnight</option>
            </select>
        </div>


            <div class="form-group">
                <label style="font-weight:bold">Package Range</label>
                <input
                    type="text"
                    name="package_range"
                    id="package_range"
                    class="form-input"
                    placeholder="(e.g. 1 day | 1 Day 1 Night)"
                >
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
        <h3 style="color:#2b7a66; text-align:center;">Upload & Crop Image</h3>

        <!-- Custom Drag Area -->
        <label class="custum-file-upload" for="imageInput">
            <div class="icon">
                <svg xmlns="http://www.w3.org/2000/svg" fill="" viewBox="0 0 24 24">
                    <path fill="" d="M10 1C9.73478 1 9.48043 1.10536 9.29289 1.29289L3.29289 7.29289C3.10536 7.48043 3 7.73478 3 8V20C3 21.6569 4.34315 23 6 23H7C7.55228 23 8 22.5523 8 22C8 21.4477 7.55228 21 7 21H6C5.44772 21 5 20.5523 5 20V9H10C10.5523 9 11 8.55228 11 8V3H18C18.5523 3 19 3.44772 19 4V9C19 9.55228 19.4477 10 20 10C20.5523 10 21 9.55228 21 9V4C21 2.34315 19.6569 1 18 1H10ZM9 7H6.41421L9 4.41421V7ZM14 15.5C14 14.1193 15.1193 13 16.5 13C17.8807 13 19 14.1193 19 15.5V16V17H20C21.1046 17 22 17.8954 22 19C22 20.1046 21.1046 21 20 21H13C11.8954 21 11 20.1046 11 19C11 17.8954 11.8954 17 13 17H14V16V15.5ZM16.5 11C14.142 11 12.2076 12.8136 12.0156 15.122C10.2825 15.5606 9 17.1305 9 19C9 21.2091 10.7909 23 13 23H20C22.2091 23 24 21.2091 24 19C24 17.1305 22.7175 15.5606 20.9844 15.122C20.7924 12.8136 18.858 11 16.5 11Z"></path>
                </svg>
            </div>
            <div class="text">
                <span>Click to upload image</span>
            </div>
            <input type="file" id="imageInput" accept="image/*">
        </label>

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
<script>
Sortable.create(itineraryContainer, {
    handle: '.drag-handle',
    animation: 200,
    ghostClass: 'sortable-ghost',
    onEnd: function () {
        Array.from(itineraryContainer.children).forEach((el, index) => {
            let input = el.querySelector('input[name="display_order[]"]');
            if (!input) {
                input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'display_order[]';
                el.appendChild(input);
            }
            input.value = index + 1;
        });
    }
});


</script>
<script>
<?php

// --- Universal function to resolve image paths
function resolveImagePath($imgField) {
    if (!$imgField || trim($imgField) === '') {
        return 'img/placeholder.png';
    }

    $filename = basename($imgField); // just the file name
    $uploadPath = 'php/upload/' . $filename; // where files are actually stored

    if (file_exists(__DIR__ . '/' . $uploadPath)) {
        return $uploadPath; // return the relative path
    }

    // fallback to placeholder
    return 'img/placeholder.png';
}



// --- Build JS package data
$jsPackages = [];

foreach ($packages as $p) {
    $packageId = $p['package_id'];

    // --- Fetch first itinerary step (for location & route image preview)
    $stmtIt = $pdo->prepare("SELECT location_image, route_image FROM package_itinerary WHERE package_id=? ORDER BY display_order ASC LIMIT 1");
    $stmtIt->execute([$packageId]);
    $firstStep = $stmtIt->fetch(PDO::FETCH_ASSOC) ?: [];

    // --- Fetch all itinerary steps
    $stepsStmt = $pdo->prepare("SELECT * FROM package_itinerary WHERE package_id=? ORDER BY display_order ASC");
    $stepsStmt->execute([$packageId]);
    $steps = $stepsStmt->fetchAll(PDO::FETCH_ASSOC);

    // --- Assign package data
    $jsPackages[$packageId] = [
        'package_title' => $p['package_title'],
        'price' => $p['price'],
        'package_image' => resolveImagePath($p['package_image']),
        'package_image2' => resolveImagePath($p['package_image2']),
        'package_image3' => resolveImagePath($p['package_image3']),
        'package_image4' => resolveImagePath($p['package_image4']),
        'location_image' => resolveImagePath($firstStep['location_image'] ?? ''),
        'route_image' => resolveImagePath($firstStep['route_image'] ?? ''),
        'operator_id' => $p['operator_id'], // <-- Add this
        'package_type' => $p['package_type'],
        'package_range' => $p['package_range'],
        'itinerary' => $steps
    ];

}

// --- Output JS variable
echo "let packagesData = " . json_encode($jsPackages, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) . ";";

?>
// ===============================
// GLOBAL VARIABLES
// ===============================
let editModal = document.getElementById("editModal");
let packageImagesContainer = document.getElementById("packageImagesContainer");
let itineraryContainer = document.getElementById("itineraryContainer");

let imageModal = document.getElementById("imageModal");
let imageInput = document.getElementById("imageInput");
let cropContainer = document.getElementById("cropContainer");
let cropImage = document.getElementById("cropImage");
let doneBtn = document.getElementById("doneBtn");
let cancelBtn = document.getElementById("cancelBtn");

let croppedFiles = {}; // temporary blobs
let cropper = null;
let currentField = null;
let currentPackageId = null;
let currentImgElement = null;

// ===============================
// OPEN EDIT PACKAGE MODAL
// ===============================
// --- Prepare operators as JS array once
const operators = <?= json_encode($operators, JSON_HEX_TAG); ?>;

// Helper function to populate operator select
function populateOperatorSelect(selectElement, selectedId = '') {
    selectElement.innerHTML = '<option value="">-- Select Operator --</option>';
    operators.forEach(op => {
        const option = document.createElement('option');
        option.value = op.operator_id;
        option.textContent = op.fullname;
        if (selectedId && selectedId == op.operator_id) {
            option.selected = true;
        }
        selectElement.appendChild(option);
    });
}

// --- Open Edit Package Modal
// --- Open Edit Package Modal
function openModal(packageId) {
    const data = packagesData[packageId];
    currentPackageId = packageId;

    document.getElementById('af-media-modal-title').textContent = 'Edit Package';
    document.getElementById('package_id').value = packageId;
    document.getElementById('package_title').value = data.package_title;
    document.getElementById('price').value = data.price;
    document.getElementById('package_type').value = data.package_type || '';
    document.getElementById('package_range').value = data.package_range || '';



    // Populate operator select and auto-select the operator handling this package
    const operatorSelect = document.getElementById('operator_id');
    populateOperatorSelect(operatorSelect, data.operator_id || '');

    // Package images
    packageImagesContainer.innerHTML = '';
    ['package_image','package_image2','package_image3','package_image4'].forEach((field) => {
        const hasImage = data[field] && data[field] !== '' && data[field] !== 'img/placeholder.png';
        const imgSrc = hasImage ? data[field] : 'img/placeholder.png';
        const wrapper = document.createElement('div');
        wrapper.style.textAlign = 'center';
        wrapper.innerHTML = `
            <img src="${imgSrc}" id="${field}_preview" style="width:150px;height:100px;object-fit:cover;border-radius:5px;"><br>
            <button type="button" class="btn btn-green" style="margin-top: 10px;" 
                onclick="openImageModal('${field}', ${packageId}, '${field}_preview')">
                ${hasImage ? 'Update Image' : 'Add Image'}
            </button>
        `;
        packageImagesContainer.appendChild(wrapper);
    });

    // General images: location & route
    const locationHasImage = data.location_image && data.location_image !== '' && data.location_image !== 'img/placeholder.png';
    const routeHasImage = data.route_image && data.route_image !== '' && data.route_image !== 'img/placeholder.png';
    document.getElementById('location_image_preview').src = locationHasImage ? data.location_image : 'img/placeholder.png';
    document.querySelector('#generalImagesContainer button[onclick*="location_image"]').textContent = locationHasImage ? 'Update Image' : 'Add Image';

    document.getElementById('route_image_preview').src = routeHasImage ? data.route_image : 'img/placeholder.png';
    document.querySelector('#generalImagesContainer button[onclick*="route_image"]').textContent = routeHasImage ? 'Update Image' : 'Add Image';

    // Itinerary steps
    itineraryContainer.innerHTML = '';
    data.itinerary.forEach(step => addItineraryStep(step));

    editModal.style.display = 'flex';
}

// --- Open Add New Package Modal
function openAddModal() {
    currentPackageId = null;

    document.getElementById('af-media-modal-title').textContent = 'Add New Package';
    document.getElementById('package_id').value = '';
    document.getElementById('package_title').value = '';
    document.getElementById('price').value = '';
    document.getElementById('package_type').value = '';
    document.getElementById('package_range').value = '';


    // Operator select empty
    const operatorSelect = document.getElementById('operator_id');
    populateOperatorSelect(operatorSelect);

    // Package images placeholders
    packageImagesContainer.innerHTML = '';
    ['package_image','package_image2','package_image3','package_image4'].forEach((field) => {
        const wrapper = document.createElement('div');
        wrapper.style.textAlign = 'center';
        wrapper.innerHTML = `
            <img src="img/placeholder.png" id="${field}_preview" style="width:150px;height:100px;object-fit:cover;border-radius:5px;"><br>
            <button type="button" class="btn btn-green" style="margin-top:10px;" 
                onclick="openImageModal('${field}', null, '${field}_preview')">Add Image</button>
        `;
        packageImagesContainer.appendChild(wrapper);
    });

    // General images placeholders
    document.getElementById('location_image_preview').src = 'img/placeholder.png';
    document.querySelector('#generalImagesContainer button[onclick*="location_image"]').textContent = 'Add Image';

    document.getElementById('route_image_preview').src = 'img/placeholder.png';
    document.querySelector('#generalImagesContainer button[onclick*="route_image"]').textContent = 'Add Image';

    // No itinerary
    itineraryContainer.innerHTML = '';

    editModal.style.display = 'flex';
}



function closeModal() {
    editModal.style.display = 'none';
}

// ===============================
// ADD ITINERARY STEP
// ===============================
function addItineraryStep(step = {}) {
    let div = document.createElement('div');
    div.className = 'itinerary-step';

    let stepId = step.itinerary_id || '';
    let displayOrder = step.display_order || '';

    div.innerHTML = `
    <span class="drag-handle">☰</span>
    <input type="hidden" name="itinerary_id[]" value="${step.itinerary_id || ''}">
    <input type="hidden" name="display_order[]" value="${step.display_order || ''}">

    <div class="step-content">
        <div class="top-row">
            <div class="form-group">
                <label>Step Title:</label>
                <input type="text" name="step_title[]" value="${step.step_title || ''}" required>
            </div>
            <div class="form-group">
                <label>Start Time:</label>
                <input type="time" name="start_time[]" value="${step.start_time || ''}">
            </div>
            <div class="form-group">
                <label>End Time:</label>
                <input type="time" name="end_time[]" value="${step.end_time || ''}">
            </div>
        </div>

        <div class="form-group">
            <label>Description:</label>
            <textarea name="description[]" rows="2">${step.description || ''}</textarea>
        </div>

        <button type="button" class="btn-red" onclick="this.parentElement.parentElement.remove()">Remove Step</button>
    </div>
`;


    itineraryContainer.appendChild(div);
}


// ===============================
// OPEN IMAGE MODAL
// ===============================
function openImageModal(field, packageId, imgElementId) {
    currentField = field;
    currentPackageId = packageId;
    currentImgElement = document.getElementById(imgElementId);

    resetImageModal();
    imageModal.style.display = "flex";
}

function openGeneralImageModal(field) {
    currentField = field;
    currentPackageId = document.getElementById("package_id").value;
    currentImgElement = document.getElementById(field + "_preview");

    resetImageModal();
    imageModal.style.display = "flex";
}

function closeImageModal() {
    resetImageModal();
    imageModal.style.display = "none";
}

// ===============================
// RESET IMAGE MODAL
// ===============================
function resetImageModal() {
    document.querySelector(".custum-file-upload").style.display = "flex";
    cropContainer.style.display = "none";
    cropImage.src = "";

    doneBtn.style.display = "none";
    cancelBtn.style.display = "none";

    if (cropper) {
        cropper.destroy();
        cropper = null;
    }

    imageInput.value = "";
}

// ===============================
// DRAG & DROP / FILE UPLOAD
// ===============================
const dragArea = document.querySelector(".custum-file-upload");

dragArea.addEventListener("dragover", (e) => {
    e.preventDefault();
    dragArea.style.background = "#e6fff2";
});
dragArea.addEventListener("dragleave", () => {
    dragArea.style.background = "#fff";
});
dragArea.addEventListener("drop", (e) => {
    e.preventDefault();
    dragArea.style.background = "#fff";
    const file = e.dataTransfer.files[0];
    handleFile(file);
});
imageInput.addEventListener("change", () => {
    const file = imageInput.files[0];
    handleFile(file);
});

// ===============================
// HANDLE SELECTED FILE AND INIT CROPPER
// ===============================
function handleFile(file) {
    if (!file) return;

    const reader = new FileReader();
    reader.onload = function(e) {
        cropImage.src = e.target.result;

        document.querySelector(".custum-file-upload").style.display = "none";
        cropContainer.style.display = "block";

        doneBtn.style.display = "inline-block";
        cancelBtn.style.display = "inline-block";

        if (cropper) cropper.destroy();

        cropper = new Cropper(cropImage, {
            aspectRatio: (currentField === "location_image" || currentField === "route_image") ? 16/9 : 4/3,
            viewMode: 1,
            autoCropArea: 0.9
        });
    };
    reader.readAsDataURL(file);
}

// ===============================
// DONE BUTTON
// ===============================
doneBtn.addEventListener("click", () => {
    if (!cropper) return;

    cropper.getCroppedCanvas().toBlob((blob) => {
        // Store temporarily
        croppedFiles[currentField] = blob;

        // Replace preview in EditModal
        const previewURL = URL.createObjectURL(blob);
        currentImgElement.src = previewURL;

        closeImageModal();
    });
});

// ===============================
// CANCEL BUTTON
// ===============================
cancelBtn.addEventListener("click", resetImageModal);

// ===============================
// SUBMIT FORM WITH CROPPED FILES AND SWEETALERT2 FEEDBACK
// ===============================
document.getElementById('editPackageForm').addEventListener('submit', function(e){
    e.preventDefault();
    const formData = new FormData(this);

    // Append cropped images
    for (let field in croppedFiles){
        formData.append(field, croppedFiles[field], field + '.png');
    }

    fetch('php/update_tour_contents.php', {
            method: 'POST',
            body: formData
        })
        .then(res => res.json())
        .then(data => {
            if(data.success){
                Swal.fire({
                    icon: 'success',
                    title: 'Success',
                    text: data.message,
                    confirmButtonColor: '#2b7a66'
                }).then(() => location.reload());
            } else {
                Swal.fire({
                    icon: 'error',
                    title: 'Error',
                    text: data.message,
                    confirmButtonColor: '#e74c3c'
                });
            }
        })
        .catch(err => {
            console.error(err);
            Swal.fire({
                icon: 'error',
                title: 'Error',
                text: 'Unexpected error occurred.',
                confirmButtonColor: '#e74c3c'
            });
        });

});

const tourTabButtons = Array.from(document.querySelectorAll('.tour-tab-btn'));
const tourTabPanels = Array.from(document.querySelectorAll('.tour-tab-panel'));
const tourSearchInput = document.getElementById('tourContentSearch');

const tourSearchMeta = {
    packages: { selector: '.package-card', placeholder: 'Search tour packages...' },
    guides: { selector: '.jg_guide-card', placeholder: 'Search tour guides...' },
    boats: { selector: '.boat-card', placeholder: 'Search boats...' }
};

function setActiveTourTab(tabKey) {
    if (!tourSearchMeta[tabKey]) return;

    tourTabButtons.forEach((btn) => {
        const isActive = btn.dataset.tab === tabKey;
        btn.classList.toggle('active', isActive);
        btn.setAttribute('aria-selected', isActive ? 'true' : 'false');
    });

    tourTabPanels.forEach((panel) => {
        panel.classList.toggle('active', panel.dataset.tab === tabKey);
    });

    if (tourSearchInput) {
        tourSearchInput.placeholder = tourSearchMeta[tabKey].placeholder;
        applyTourContentSearch();
    }
}

function applyTourContentSearch() {
    if (!tourSearchInput) return;

    const activePanel = document.querySelector('.tour-tab-panel.active');
    if (!activePanel) return;

    const activeTab = activePanel.dataset.tab;
    const meta = tourSearchMeta[activeTab];
    if (!meta) return;

    const query = tourSearchInput.value.trim().toLowerCase();
    const cards = activePanel.querySelectorAll(meta.selector);
    let visibleCount = 0;

    cards.forEach((card) => {
        const text = (card.textContent || '').toLowerCase();
        const shouldShow = query === '' || text.includes(query);
        card.style.display = shouldShow ? '' : 'none';
        if (shouldShow) visibleCount += 1;
    });

    const emptyState = activePanel.querySelector(`.tour-tab-empty[data-empty-for="${activeTab}"]`);
    if (emptyState) {
        emptyState.classList.toggle('show', cards.length > 0 && visibleCount === 0);
    }
}

tourTabButtons.forEach((btn) => {
    btn.addEventListener('click', () => setActiveTourTab(btn.dataset.tab));
});

if (tourSearchInput) {
    tourSearchInput.addEventListener('input', applyTourContentSearch);
}

setActiveTourTab('packages');

document.getElementById('updatePricesForm').addEventListener('submit', function(e){
    e.preventDefault();
    const formData = new FormData(this);

    fetch('php/update_service_prices.php', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        if(data.success){
            Swal.fire({
                icon: 'success',
                title: 'Success',
                text: data.message,
                confirmButtonColor: '#2b7a66'
            });
        } else {
            Swal.fire({
                icon: 'error',
                title: 'Error',
                text: data.message,
                confirmButtonColor: '#e74c3c'
            });
        }
    })
    .catch(err => {
        console.error(err);
        Swal.fire({
            icon: 'error',
            title: 'Error',
            text: 'Unexpected error occurred.',
            confirmButtonColor: '#e74c3c'
        });
    });
});

</script>


</body>
</html>

