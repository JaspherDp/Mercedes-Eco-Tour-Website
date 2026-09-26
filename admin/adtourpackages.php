<?php
chdir(__DIR__ . '/..');
require_once __DIR__ . '/../php/session_security.php';
AppSessionStart();
require 'php/db_connection.php';
require_once __DIR__ . '/../php/admin_auth_helper.php';

// ✅ Logout Action
if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    AppDestroySession();
    echo "<script>
        alert('You have been logged out. Session expired.');
        window.location.href = './';
    </script>";
    exit();
}

// ✅ Session Authentication Check
AdminRequireLogin();
$adminCatalogCsrf = AppCsrfToken('admin', 'catalog_content');

// Fetch operators
$operatorsStmt = $pdo->query("SELECT operator_id, fullname FROM operators ORDER BY fullname ASC");
$operators = $operatorsStmt->fetchAll(PDO::FETCH_ASSOC);

// ✅ Fetch All Tour Packages
$stmt = $pdo->query("SELECT * FROM tour_packages ORDER BY package_id ASC");
$packages = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Package catalog and booking summary.
$packageTotal = count($packages);
$packageAvailable = $packageTotal; // Every saved package is currently published and bookable.
$packageBooked = (int)$pdo->query("
    SELECT COUNT(DISTINCT p.package_id)
    FROM tour_packages p
    INNER JOIN bookings b ON b.package_name = p.package_title
    WHERE b.status = 'accepted'
      AND b.is_complete = 'uncomplete'
      AND b.booking_date >= CURDATE()
")->fetchColumn();
$packageCompleted = (int)$pdo->query("
    SELECT COUNT(*)
    FROM bookings b
    INNER JOIN tour_packages p ON p.package_title = b.package_name
    WHERE b.is_complete = 'completed'
")->fetchColumn();
$packageUpcomingCount = (int)$pdo->query("
    SELECT COUNT(*)
    FROM bookings b
    INNER JOIN tour_packages p ON p.package_title = b.package_name
    WHERE b.status = 'accepted'
      AND b.is_complete = 'uncomplete'
      AND b.booking_date >= CURDATE()
")->fetchColumn();
$packagePendingCount = (int)$pdo->query("
    SELECT COUNT(*)
    FROM bookings b
    INNER JOIN tour_packages p ON p.package_title = b.package_name
    WHERE b.status = 'pending'
      AND b.is_complete = 'uncomplete'
      AND b.booking_date >= CURDATE()
")->fetchColumn();
$packageUpcomingGuests = (int)$pdo->query("
    SELECT COALESCE(SUM(b.pax), 0)
    FROM bookings b
    INNER JOIN tour_packages p ON p.package_title = b.package_name
    WHERE b.status = 'accepted'
      AND b.is_complete = 'uncomplete'
      AND b.booking_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)
")->fetchColumn();

$bestSellerStmt = $pdo->query("
    SELECT p.package_id, p.package_title, p.price, COUNT(b.booking_id) AS booking_count,
           COALESCE(SUM(b.pax), 0) AS guest_count
    FROM tour_packages p
    LEFT JOIN bookings b ON b.package_name = p.package_title AND b.status = 'accepted'
    GROUP BY p.package_id, p.package_title, p.price
    ORDER BY booking_count DESC, guest_count DESC, p.package_id ASC
    LIMIT 1
");
$bestSellerPackage = $bestSellerStmt->fetch(PDO::FETCH_ASSOC) ?: null;

$packageScheduleStmt = $pdo->query("
    SELECT b.booking_date, COUNT(*) AS booking_count
    FROM bookings b
    INNER JOIN tour_packages p ON p.package_title = b.package_name
    WHERE b.status = 'accepted'
      AND b.is_complete = 'uncomplete'
      AND b.booking_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 6 DAY)
    GROUP BY b.booking_date
");
$packageScheduleRows = $packageScheduleStmt->fetchAll(PDO::FETCH_KEY_PAIR);
$packageSchedule = [];
for ($scheduleOffset = 0; $scheduleOffset < 7; $scheduleOffset++) {
    $scheduleDate = date('Y-m-d', strtotime("+{$scheduleOffset} day"));
    $packageSchedule[] = [
        'date' => $scheduleDate,
        'label' => $scheduleOffset === 0 ? 'Today' : date('D', strtotime($scheduleDate)),
        'count' => (int)($packageScheduleRows[$scheduleDate] ?? 0),
    ];
}
$packageScheduleMax = max(1, ...array_column($packageSchedule, 'count'));

$packageUpcomingStmt = $pdo->query("
    SELECT b.booking_reference, b.booking_date, b.package_name, b.pax, b.tour_type,
           t.full_name AS tourist_name
    FROM bookings b
    INNER JOIN tour_packages p ON p.package_title = b.package_name
    LEFT JOIN tourist t ON t.tourist_id = b.tourist_id
    WHERE b.status = 'accepted'
      AND b.is_complete = 'uncomplete'
      AND b.booking_date >= CURDATE()
    ORDER BY b.booking_date ASC, b.booking_id ASC
    LIMIT 5
");
$packageUpcoming = $packageUpcomingStmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>iTour Mercedes - Tour Contents</title>
<link rel="icon" type="image/png" href="img/newlogo.png">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
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
.tour-package-panel {
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
.tour-tab-empty {
    display: none;
    margin: 8px 0 0;
    font-size: 13px;
    color: #5e6f78;
}
.tour-tab-empty.show {
    display: block;
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

@media (max-width: 1180px) {
    .dashboard-content {
        padding: 16px 16px 24px;
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
    .tour-secondary-nav .add-new-p-btn { width: 100%; }
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

/* Formal package editor */
#editModal, #imageModal {
    background: rgba(7, 25, 20, .68);
    padding: 24px;
}
#editModal .modal-content, #imageModal .modal-content {
    border: 1px solid #d4e3dd;
    border-radius: 18px;
    box-shadow: 0 28px 70px rgba(7,35,27,.28);
    padding: 0;
    overflow: hidden;
}
#editModal .modal-content { display: grid; grid-template-rows: auto minmax(0, 1fr); height: min(90vh, 820px); max-height: min(90vh, 820px); }
#imageModal .modal-content { display: grid; grid-template-rows: auto minmax(0, 1fr) auto; max-height: min(88vh, 720px); }
#editModal .af-modal-header, #imageModal .af-modal-header {
    display: grid;
    gap: 5px;
    padding: 20px 64px 16px 22px !important;
    border-color: #dfeae6 !important;
    border-bottom: 1px solid #dfeae6;
}
#editModal .af-modal-header strong, #imageModal .af-modal-header strong { color: #173d32 !important; font-size: 19px !important; }
#editModal .af-modal-header p, #imageModal .af-modal-header p { margin: 0; color: #6a7b75; font-size: 12px; }
#editPackageForm { display: grid; grid-template-rows: minmax(0, 1fr) auto; gap: 0; min-height: 0; height: 100%; overflow: hidden; }
.package-modal-body { min-height: 0; max-height: none; overflow-y: auto; overscroll-behavior: contain; scrollbar-gutter: stable; padding: 18px 22px; display: grid; grid-auto-rows: max-content; align-content: start; gap: 14px; }
.package-modal-body > .form-row,
.package-modal-body > #packageImagesContainer,
.package-modal-body > #generalImagesContainer,
.package-modal-body > #itineraryContainer {
    padding: 16px;
    background: #f8fbfa;
    border: 1px solid #e0ebe7;
    border-radius: 13px;
}
#editPackageForm label { color: #334b43; font-size: 12px; font-weight: 700; }
#editPackageForm .form-input, #editPackageForm input, #editPackageForm textarea, #editPackageForm select {
    border: 1px solid #cddbd6;
    border-radius: 9px;
    background: #fff;
}
#editPackageForm .form-input:focus, #editPackageForm input:focus, #editPackageForm textarea:focus, #editPackageForm select:focus {
    outline: none;
    border-color: #5c9b88;
    box-shadow: 0 0 0 3px rgba(43,122,102,.11);
}
#editPackageForm h4 { margin: 3px 0 -9px; color: #31594c; font-size: 12px; letter-spacing: .06em; text-transform: uppercase; }
.package-modal-actions { min-height: 66px; padding: 13px 22px; display: flex; align-items: center; justify-content: flex-end; gap: 10px; border-top: 1px solid #e1ebe7; background: #fff; }
.package-modal-actions .btn-cancel, .package-modal-actions .btn-green { width: auto !important; min-width: 110px; min-height: 39px; margin: 0 !important; border-radius: 9px; }
.package-modal-actions .btn-cancel { border: 1px solid #d4e0dc; background: #fff; color: #30473f; }
#packageImagesContainer > div, #generalImagesContainer > div { padding: 10px; border: 1px solid #e0e9e5; border-radius: 10px; background: #fff; }
#packageImagesContainer img, #generalImagesContainer img { width: 138px !important; height: 92px !important; border-radius: 8px !important; }
#imageModal .custum-file-upload { border-color: #9fc6b8; background: #f8fbfa; box-shadow: none; }
#imageModal h3 { color: #173d32 !important; font-size: 19px; }
#imageModal .image-modal-body { min-height: 0; overflow-y: auto; padding: 18px 22px; }
#imageModal .custum-file-upload, #imageModal #cropContainer { margin: 0 auto; }
#imageModal .modal-content > div:last-child { margin: 0 !important; padding: 14px 22px; border-top: 1px solid #e1ebe7; background: #fff; }
#editModal .close, #imageModal .close { position: absolute; top: 13px; right: 14px; width: 34px; height: 34px; display: grid; place-items: center; border: 0; border-radius: 50%; background: #f1f6f4; color: #3e554d; font-size: 23px; cursor: pointer; z-index: 5; }
body.modal-open { overflow: hidden; }

/* Two-mode package editor: sidebar for editing, guided steps for adding. */
.package-editor-layout { min-height: 0; overflow: hidden; }
.package-editor-nav { min-height: 0; }
.package-editor-nav button { border: 0; color: #60756d; font-family: inherit; font-weight: 700; cursor: pointer; }
.package-editor-nav button svg { width: 18px; height: 18px; flex: 0 0 18px; fill: none; stroke: currentColor; stroke-width: 1.7; stroke-linecap: round; stroke-linejoin: round; }
.package-editor-pane { display: none; }
.package-editor-pane.active { display: grid; align-content: start; gap: 14px; }
.package-editor-pane > .form-row { padding: 16px; background: #f8fbfa; border: 1px solid #e0ebe7; border-radius: 13px; }
.package-editor-pane[data-package-pane="media"] > #packageImagesContainer,
.package-editor-pane[data-package-pane="media"] > #generalImagesContainer { padding: 14px; border: 1px solid #e0ebe7; border-radius: 13px; background: #f8fbfa; }
.package-information-row { margin-top: 0 !important; }
.package-editor-pane > h4 { margin: 0 !important; }
.package-itinerary-heading { display: flex; align-items: center; justify-content: space-between; gap: 16px; }
.package-itinerary-heading h4 { margin: 0 !important; }
.package-itinerary-heading p { margin: 5px 0 0; color: #72847d; font-size: 10px; }
.package-itinerary-heading .add-step-btn { flex: 0 0 auto; margin: 0; }
.package-required { display: none; color: #dc3545; font-style: normal; font-weight: 800; }
.package-required-note { display: none; margin-right: auto; color: #7b8c85; font-size: 9px; }
.package-required-note em { color: #dc3545; font-style: normal; font-weight: 800; }
.package-wizard-action[hidden], #packageSave[hidden] { display: none !important; }
.package-modal-body { scrollbar-width: thin; scrollbar-color: #278068 #e6f0ec; }
.package-modal-body::-webkit-scrollbar { width: 6px; height: 6px; }
.package-modal-body::-webkit-scrollbar-track { background: #e6f0ec; border-radius: 999px; }
.package-modal-body::-webkit-scrollbar-thumb { background: #278068; border-radius: 999px; }
.package-modal-body::-webkit-scrollbar-thumb:hover { background: #145d4b; }

#editModal[data-mode="edit"] .package-editor-layout { display: grid; grid-template-columns: 190px minmax(0,1fr); }
#editModal[data-mode="edit"] .package-editor-nav { padding: 17px 12px; border-right: 1px solid #e2ebe7; background: #f5f8f7; }
#editModal[data-mode="edit"] .package-editor-nav button { width: 100%; display: flex; align-items: center; gap: 9px; padding: 11px 12px; border-radius: 8px; background: transparent; text-align: left; font-size: 11px; }
#editModal[data-mode="edit"] .package-editor-nav button.active { background: #dff1ea; color: #176b58; }
#editModal[data-mode="edit"] .package-modal-body { padding: 18px 22px; }

#editModal[data-mode="add"] .package-editor-layout { display: grid; grid-template-rows: auto minmax(0,1fr); }
#editModal[data-mode="add"] .package-editor-nav { counter-reset:package-step; display: grid; grid-template-columns: repeat(3,minmax(0,1fr)); padding: 16px 80px 14px; border-bottom: 1px solid #e2ebe7; background: #f8fbfa; }
#editModal[data-mode="add"] .package-editor-nav button { counter-increment: package-step; position: relative; display: flex; flex-direction: column; align-items: center; gap: 6px; padding: 0; background: transparent; overflow: visible; font-size: 9.5px; }
#editModal[data-mode="add"] .package-editor-nav button svg { display: none; }
#editModal[data-mode="add"] .package-editor-nav button:before { content: counter(package-step); position: relative; z-index: 2; width: 30px; height: 30px; display: grid; place-items: center; border: 2px solid #c8dcd5; border-radius: 50%; background: #fff; color: #6d827a; font-size: 10px; }
#editModal[data-mode="add"] .package-editor-nav button:not(:last-child):after { content: ''; position: absolute; z-index: 1; top: 14px; left: calc(50% + 15px); width: calc(100% - 30px); height: 2px; background: #dce8e4; }
#editModal[data-mode="add"] .package-editor-nav button.active { color: #145f4d; }
#editModal[data-mode="add"] .package-editor-nav button.active:before { border-color: #176b58; background: #176b58; color: #fff; box-shadow: 0 0 0 4px #dcefe9; }
#editModal[data-mode="add"] .package-editor-nav button.completed { color: #347764; }
#editModal[data-mode="add"] .package-editor-nav button.completed:before { content: '✓'; border-color: #3a8d75; background: #e2f3ed; color: #176b58; }
#editModal[data-mode="add"] .package-editor-nav button.completed:after { background: #68aa97; }
#editModal[data-mode="add"] .package-modal-body { padding: 18px 28px; }
#editModal[data-mode="add"] .package-required, #editModal[data-mode="add"] .package-required-note { display: inline; }
@media (max-width: 700px) {
    #editModal, #imageModal { padding: 10px; }
    #editModal .modal-content, #imageModal .modal-content { width: 100%; }
    .package-modal-body { padding: 14px; }
    .package-modal-actions { padding: 12px 14px; }
    #editModal[data-mode="edit"] .package-editor-layout { display: block; }
    #editModal[data-mode="edit"] .package-editor-nav { display: flex; overflow-x: auto; padding: 10px; border-right: 0; border-bottom: 1px solid #e2ebe7; }
    #editModal[data-mode="edit"] .package-editor-nav button { width: auto; flex: 0 0 auto; }
    #editModal[data-mode="add"] .package-editor-nav { padding: 13px 12px 11px; }
    #editModal[data-mode="add"] .package-modal-body, #editModal[data-mode="edit"] .package-modal-body { padding: 14px; }
    .package-modal-actions .package-required-note { display: none !important; }
}

</style>
<link rel="stylesheet" href="styles/admin_panel_theme.css" />
<link rel="stylesheet" href="styles/admin_catalog_pages.css?v=20260920-1" />
<style>
/* Tour-package catalog workspace and operational summary. */
.package-header-actions { display: inline-flex; align-items: center; gap: 9px; }
.package-header-actions .catalog-primary-btn { display: inline-flex; align-items: center; gap: 7px; white-space: nowrap; }
.package-header-actions svg { width: 17px; height: 17px; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; }
.catalog-content > .tour-content-shell { min-height: 0; height: 100%; grid-template-rows: auto minmax(0, 1fr); gap: 10px; }
.package-catalog-toolbar { grid-template-columns: minmax(165px, .55fr) minmax(330px, 1fr) minmax(300px, .95fr); gap: 12px; }
.package-catalog-toolbar .catalog-metrics { grid-column: auto; grid-row: auto; grid-template-columns: repeat(4, minmax(70px, 1fr)); gap: 7px; }
.package-catalog-toolbar .catalog-metric { min-height: 46px; padding: 5px 8px; }
.package-catalog-toolbar .catalog-metric strong { font-size: 14px; }
.package-catalog-toolbar .catalog-metric small { font-size: 8px; }
.package-catalog-toolbar .catalog-search-area { width: 100%; display: flex; align-items: center; justify-content: flex-end; gap: 8px; }
.package-catalog-toolbar .catalog-search { min-width: 0; max-width: none; flex: 1 1 auto; }
.package-calendar-btn { min-width: 105px; height: 42px; display: inline-flex; align-items: center; justify-content: center; gap: 7px; padding: 0 13px; border: 1px solid #1e664f; border-radius: 10px; background: #26735d; color: #fff; font: inherit; font-size: 11px; font-weight: 750; white-space: nowrap; cursor: pointer; box-shadow: 0 6px 13px rgba(38,115,93,.16); transition: background .18s ease, border-color .18s ease, transform .18s ease; }
.package-calendar-btn:hover { border-color: #174f3e; background: #1d5f4c; color: #fff; transform: translateY(-1px); }
.package-calendar-btn:focus-visible { outline: 3px solid rgba(43,122,102,.16); outline-offset: 1px; }
.package-calendar-btn svg { width: 16px; height: 16px; fill: none; stroke: currentColor; stroke-width: 1.9; stroke-linecap: round; stroke-linejoin: round; }
.package-directory-title h3 { margin: 0; color: #173d32; font-size: 16px; }
.package-directory-title p { margin: 3px 0 0; color: #768780; font-size: 10px; }
.package-workspace .tour-package-panel,
.package-workspace .tour-panel-card { min-width: 0; min-height: 0; }
.package-workspace .catalog-list-scroll { scroll-behavior: auto; -webkit-overflow-scrolling: touch; }
.package-workspace .tour-panel-card { height: auto; overflow: visible; padding: 1px 1px 22px; border: 0; background: transparent; box-shadow: none; }
.package-workspace .cards-container { grid-template-columns: repeat(3, minmax(0, 1fr)); align-items: stretch; gap: 13px; }
.package-workspace .package-card { min-width: 0; min-height: 380px; contain: layout paint style; content-visibility: auto; contain-intrinsic-size: 380px; border-radius: 14px; box-shadow: 0 4px 12px rgba(18,65,52,.055); transition: border-color .12s ease; }
.package-workspace .package-card:hover { transform: none; border-color: #bdd7ce; box-shadow: 0 4px 12px rgba(18,65,52,.055); }
.package-workspace .package-card > img { display: block; width: 100%; height: 230px; box-sizing: border-box; object-fit: cover; object-position: center; background: #edf3f0; border-bottom: 1px solid #dce8e3; }
.package-workspace .package-card-content { padding: 13px 14px 14px; }
.package-workspace .package-card-content h4 { min-height: 38px; margin: 0; color: #173f33; font-size: 13px; line-height: 1.4; }
.package-workspace .package-card-content p { margin: 7px 0 12px; color: #263f37; font-size: 12px; }
.package-workspace .package-card-content .p-edit-p-btn { width: 100%; margin-top: auto; min-height: 37px; border: 1px solid #1e664f !important; background: #26735d !important; color: #fff !important; box-shadow: 0 6px 13px rgba(38,115,93,.16); }
.package-workspace .package-card-content .p-edit-p-btn:hover { border-color: #174f3e !important; background: #1d5f4c !important; color: #fff !important; }
.package-best-seller-card { padding: 14px; border-radius: 13px; background: linear-gradient(145deg,#173f33,#26725c); color: #fff; }
.package-best-seller-card > span { color: #bfe3d7; font-size: 9px; font-weight: 800; letter-spacing: .09em; text-transform: uppercase; }
.package-best-seller-card h4 { margin: 7px 0 4px; color: #fff; font-size: 15px; line-height: 1.35; }
.package-best-seller-card p { margin: 0; color: #d8ece5; font-size: 10px; }
.package-best-seller-card p b { color: #fff; }
.package-summary-mini-grid { grid-template-columns: repeat(3, minmax(0, 1fr)); }
.package-summary-mini-grid > div { padding-inline: 9px; }
.package-summary-mini-grid strong { font-size: 18px; }
.package-upcoming-item strong { font-size: 10.5px; }
.package-upcoming-item p { font-size: 9px; }
@media (max-width: 1280px) and (min-width: 761px) {
  .package-catalog-toolbar { grid-template-columns: minmax(150px, .5fr) minmax(300px, 1fr) minmax(280px, .85fr); }
  .package-catalog-toolbar .catalog-metrics { grid-column: auto; grid-row: auto; }
}
@media (max-width: 1100px) {
  .package-workspace .tour-panel-card { overflow: visible; }
}
@media (max-width: 760px) {
  .package-catalog-toolbar { grid-template-columns: 1fr; }
  .package-catalog-toolbar .catalog-search-area { justify-self: stretch; }
  .package-calendar-btn { min-width: 42px; padding: 0 11px; }
  .package-calendar-btn span { display: none; }
  .package-catalog-toolbar .catalog-metrics { grid-template-columns: repeat(2, minmax(0, 1fr)); }
  .package-workspace .cards-container { grid-template-columns: 1fr; }
  .package-header-actions .catalog-primary-btn { min-height: 38px; padding-inline: 12px !important; font-size: 11px; }
}

/* Destination-editor visual language for package forms. */
#editModal { font-family: Poppins, Inter, sans-serif; backdrop-filter: blur(4px); }
#editModal .modal-content { width: min(900px, calc(100vw - 28px)); height: min(640px, calc(100vh - 28px)); max-width: 900px; max-height: 92vh; border-radius: 17px; }
#editModal .package-editor-header { display: flex; align-items: center; gap: 13px; min-height: 84px; padding: 16px 58px 16px 20px !important; }
.package-editor-header .package-editor-icon { width: 42px; height: 42px; flex: 0 0 42px; display: grid; place-items: center; border-radius: 11px; background: #e9f6f1; color: #17705b; }
.package-editor-header .package-editor-icon svg { width: 21px; height: 21px; fill: none; stroke: currentColor; stroke-width: 1.7; stroke-linecap: round; stroke-linejoin: round; }
.package-editor-header > div { min-width: 0; }
#editModal .package-editor-header small { display: block; color: #34806c; font-size: 8px; font-weight: 700; letter-spacing: .12em; }
#editModal .package-editor-header strong { display: block; margin: 2px 0; color: #17251f !important; font-size: 17px !important; line-height: 1.2; }
#editModal .package-editor-header p { color: #7a8882; font-size: 10px; }
#editModal .close { top: 16px; right: 20px; width: 32px; height: 32px; border-radius: 8px; font-size: 21px; }

#editModal[data-mode="edit"] .package-editor-layout { grid-template-columns: 190px minmax(0,1fr); }
#editModal[data-mode="edit"] .package-editor-nav { padding: 17px 12px; background: #f5f8f7; }
#editModal[data-mode="edit"] .package-editor-nav button { gap: 9px; padding: 11px 12px; font-size: 11px; font-weight: 600; }
#editModal[data-mode="add"] .package-editor-nav { padding-top: 14px; padding-bottom: 13px; }

#editModal .package-modal-body { padding: 22px !important; gap: 17px; background: #fff; }
#editModal .package-editor-layout,
#editModal .package-modal-body,
#editModal .package-editor-pane,
#editModal .form-group,
#editModal .itinerary-step,
#editModal .step-content,
#editModal #editPackageForm input,
#editModal #editPackageForm select,
#editModal #editPackageForm textarea { box-sizing: border-box; }
#editModal .package-editor-pane.active { gap: 17px; }
#editModal .package-editor-pane[data-package-pane="information"] { grid-template-columns: repeat(2,minmax(0,1fr)); }
#editModal .package-editor-pane[data-package-pane="information"] > .form-row { display: contents; }
#editModal .package-editor-pane > .form-row { padding: 0; border: 0; border-radius: 0; background: transparent; }
#editModal .package-information-row .form-group:last-child { grid-column: 1 / -1; }
#editModal .form-group { min-width: 0; display: flex; flex-direction: column; }
#editModal #editPackageForm label { display: block; margin: 0 0 7px; color: #354a42; font-size: 10px; font-weight: 700; }
#editModal #editPackageForm .form-input,
#editModal #editPackageForm input:not([type="hidden"]),
#editModal #editPackageForm select,
#editModal #editPackageForm textarea { width: 100%; min-height: 38px; margin: 0; padding: 9px 11px !important; border: 1px solid #d6e2dd !important; border-radius: 8px !important; background: #fff !important; color: #253c33; font: 11px Poppins, sans-serif; box-shadow: none; }
#editModal #editPackageForm textarea { min-height: 72px; line-height: 1.5; resize: vertical; }
#editModal #editPackageForm .form-input:focus,
#editModal #editPackageForm input:focus,
#editModal #editPackageForm select:focus,
#editModal #editPackageForm textarea:focus { border-color: #55a08c !important; box-shadow: 0 0 0 3px rgba(81,162,139,.12) !important; }
#editModal #editPackageForm h4 { margin: 0 !important; color: #31594c; font-size: 10px; letter-spacing: .08em; }

#editModal .package-editor-pane[data-package-pane="media"] { grid-template-columns: 1fr; }
#editModal .package-editor-pane[data-package-pane="media"] > #packageImagesContainer { display: grid !important; grid-template-columns: repeat(4,minmax(0,1fr)); gap: 12px !important; padding: 0; border: 0; background: transparent; }
#editModal .package-editor-pane[data-package-pane="media"] > #generalImagesContainer { display: grid !important; grid-template-columns: repeat(2,minmax(0,1fr)); gap: 12px !important; padding: 0; border: 0; background: transparent; }
#editModal #packageImagesContainer > div,
#editModal #generalImagesContainer > div { min-width: 0; padding: 10px; border: 1px solid #dce8e3; border-radius: 10px; background: #f8fbfa; text-align: left !important; }
#editModal #packageImagesContainer img,
#editModal #generalImagesContainer img { width: 100% !important; height: 105px !important; display: block; object-fit: cover; border-radius: 8px !important; }
#editModal #packageImagesContainer br,
#editModal #generalImagesContainer br { display: none; }
#editModal #packageImagesContainer button,
#editModal #generalImagesContainer button { width: 100%; min-height: 34px; margin-top: 9px !important; padding: 7px 9px; border-radius: 7px; font: 600 9px Poppins, sans-serif; }
#editModal #generalImagesContainer label { margin-bottom: 7px !important; }

#editModal .package-itinerary-heading { padding-bottom: 1px; }
#editModal .package-itinerary-heading p { font-size: 9px; }
#editModal .package-add-step-bottom { width: max-content; min-height: 34px; margin: 0; padding: 7px 11px; border-radius: 7px; font: 600 9px Poppins, sans-serif; }
#editModal .itinerary-step { display: grid; grid-template-columns: 28px minmax(0,1fr); align-items: start; gap: 10px; margin: 0 0 12px; padding: 13px; border: 1px solid #dce7e3; border-radius: 10px; background: #fff; box-shadow: 0 3px 12px rgba(16,55,43,.06); }
#editModal .drag-handle { width: 28px; min-height: 38px; color: #238069; font-size: 18px; }
#editModal .step-content { min-width: 0; gap: 12px; }
#editModal .step-content .top-row { width: 100%; grid-template-columns: minmax(0,1.15fr) repeat(2,minmax(0,.85fr)); gap: 12px; }
#editModal .step-content .top-row .form-group { width: 100%; min-width: 0; }
#editModal .step-content .top-row input,
#editModal .step-content input[type="time"] { width: 100% !important; min-width: 0 !important; max-width: 100% !important; }
#editModal .step-content textarea { width: 100%; }
#editModal .itinerary-step .btn-red { width: max-content; margin: 0; padding: 7px 11px; border-radius: 7px; font: 600 9px Poppins, sans-serif; }

#editModal .package-modal-actions { min-height: 68px; padding: 14px 20px; gap: 9px; background: #fbfcfc; }
#editModal .package-modal-actions .btn-cancel,
#editModal .package-modal-actions .btn-green { min-width: 0; min-height: 42px; padding: 10px 17px; border-radius: 10px; font: 600 12px Poppins, sans-serif; }
#editModal .package-modal-actions .btn-cancel { border: 0; background: #eef4f1; color: #345248; }
#editModal .package-modal-actions .btn-green { background: #176b58; color: #fff; }
#editModal .package-modal-actions .btn-green:hover { background: #0c4f40; }

@media (max-width: 700px) {
  #editModal .modal-content { width: calc(100vw - 20px); height: calc(100vh - 20px); }
  #editModal .package-editor-header { padding: 14px 52px 14px 14px !important; }
  #editModal[data-mode="edit"] .package-editor-layout { display: grid; grid-template-columns: 1fr; grid-template-rows: auto minmax(0,1fr); }
  #editModal .package-modal-body { padding: 14px !important; }
  #editModal .package-editor-pane[data-package-pane="information"] { grid-template-columns: 1fr; }
  #editModal .package-information-row .form-group:last-child { grid-column: auto; }
  #editModal .package-editor-pane[data-package-pane="media"] > #packageImagesContainer { grid-template-columns: repeat(2,minmax(0,1fr)); }
  #editModal .step-content .top-row { grid-template-columns: 1fr; }
}
@media (max-width: 460px) {
  #editModal .package-editor-pane[data-package-pane="media"] > #packageImagesContainer,
  #editModal .package-editor-pane[data-package-pane="media"] > #generalImagesContainer { grid-template-columns: 1fr; }
}
</style>
</head>

<body class="catalog-page">
<div class="admin-container">
<?php include 'admin_sidebar.php'; ?>

<main class="main-content catalog-page-main">
<header class="admin-header admin-page-header">
    <div class="admin-header-left admin-page-title">
        <span class="admin-page-title-icon" aria-hidden="true">
          <svg viewBox="0 0 24 24"><path d="m4 6 5-2 6 2 5-2v14l-5 2-6-2-5 2V6Z"/><path d="M9 4v14M15 6v14"/></svg>
        </span>
        <div class="admin-page-title-copy">
          <h2>Tour Packages</h2>
          <p class="admin-header-subtitle">Create and maintain the tour packages shown to visitors</p>
        </div>
    </div>
    <nav class="package-header-actions" aria-label="Tour package actions">
      <button class="catalog-primary-btn" type="button" onclick="openAddModal()"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>Add tour package</button>
    </nav>
</header>

<?php
function getImagePath($imgField) {

    if (!$imgField || trim($imgField) === '') {
        return 'img/placeholder.png';
    }

    $imgField = trim($imgField);

    // If DB says upload/, convert to php/upload/
    if (strpos($imgField, 'upload/') === 0) {
        return 'php/' . $imgField;
    }

    return $imgField;
}

function getPackageCardImagePath($imgField) {
    $originalPath = getImagePath($imgField);
    $optimizedPath = preg_replace('/\.[^.\/]+$/', '.optimized.webp', $originalPath);

    return $optimizedPath && is_file($optimizedPath) ? $optimizedPath : $originalPath;
}
?>

<div class="dashboard-content catalog-content">
<div class="tour-content-shell">
    <div class="catalog-toolbar package-catalog-toolbar">
        <div class="package-directory-title"><h3>Current packages</h3><p>Browse and maintain visitor-facing tour offers.</p></div>
        <div class="catalog-metrics" aria-label="Tour package summary">
          <span class="catalog-metric neutral" title="All packages in the catalog"><strong><?= $packageTotal ?></strong><small>Total</small></span>
          <span class="catalog-metric available" title="Packages currently published and bookable"><strong><?= $packageAvailable ?></strong><small>Available</small></span>
          <span class="catalog-metric booked" title="Packages with accepted upcoming bookings"><strong><?= $packageBooked ?></strong><small>Booked</small></span>
          <span class="catalog-metric occupied" title="Completed package bookings"><strong><?= $packageCompleted ?></strong><small>Completed</small></span>
        </div>
        <div class="catalog-search-area">
          <label class="catalog-search" for="tourContentSearch">
            <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-4-4"/></svg>
            <input type="search" id="tourContentSearch" placeholder="Search tour packages..." autocomplete="off">
            <button type="button" class="catalog-search-clear" id="tourContentSearchClear" aria-label="Clear package search">&times;</button>
          </label>
          <button type="button" class="package-calendar-btn" aria-label="Open package calendar" title="Package calendar">
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7 3v4M17 3v4M4 9h16"/><rect x="4" y="5" width="16" height="16" rx="2"/></svg>
            <span>Calendar</span>
          </button>
        </div>
    </div>

    <div class="catalog-workspace package-workspace">
      <div class="catalog-list-scroll" aria-label="Tour package directory">
      <div class="tour-package-panel">
        <section id="tour-packages" class="tour-panel-card">
            <div class="cards-container">
                <?php foreach($packages as $packageIndex => $package): ?>
                <div class="package-card">
                    <img src="<?= htmlspecialchars(getPackageCardImagePath($package['package_image'])); ?>"
                         alt="<?= htmlspecialchars($package['package_title']); ?>"
                         width="420" height="230" decoding="async"
                         loading="<?= $packageIndex < 3 ? 'eager' : 'lazy'; ?>">
                    <div class="package-card-content">
                        <h4><?= htmlspecialchars($package['package_title']); ?></h4>
                        <p>₱<?= number_format($package['price'],2); ?> / pax</p>
                        <button type="button" class="p-edit-p-btn" style="background-color: #2b7a66" onclick="openModal(<?= $package['package_id']; ?>)">Edit Package</button>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <p class="tour-tab-empty" data-empty-for="packages">No matching tour packages found.</p>
        </section>
      </div>
      </div>

      <aside class="catalog-summary-panel package-summary-panel" aria-label="Tour package booking summary">
        <div class="catalog-summary-head">
          <div><span class="catalog-summary-kicker">Package performance</span><h3>Booking summary</h3></div>
          <span class="catalog-live-label"><i></i> Live</span>
        </div>

        <section class="package-best-seller-card">
          <span>Best seller</span>
          <?php if ($bestSellerPackage && (int)$bestSellerPackage['booking_count'] > 0): ?>
            <h4><?= htmlspecialchars($bestSellerPackage['package_title']) ?></h4>
            <p><b><?= (int)$bestSellerPackage['booking_count'] ?></b> accepted booking<?= (int)$bestSellerPackage['booking_count'] === 1 ? '' : 's' ?> &middot; <?= (int)$bestSellerPackage['guest_count'] ?> guests</p>
          <?php else: ?>
            <h4>No bestseller yet</h4><p>Accepted bookings will determine the leading package.</p>
          <?php endif; ?>
        </section>

        <div class="catalog-summary-mini-grid package-summary-mini-grid">
          <div><span>Upcoming</span><strong><?= $packageUpcomingCount ?></strong><small>accepted</small></div>
          <div><span>Pending</span><strong><?= $packagePendingCount ?></strong><small>need review</small></div>
          <div><span>Guests</span><strong><?= $packageUpcomingGuests ?></strong><small>next 30 days</small></div>
        </div>

        <section class="catalog-chart-card">
          <div class="catalog-summary-section-head"><h4>Seven-day demand</h4><span>Accepted bookings</span></div>
          <div class="catalog-bar-chart" aria-label="Accepted package bookings over the next seven days">
            <?php foreach ($packageSchedule as $scheduleDay):
              $scheduleHeight = $scheduleDay['count'] > 0 ? max(16, (int)round(($scheduleDay['count'] / $packageScheduleMax) * 100)) : 5;
            ?>
            <div class="catalog-bar-day" title="<?= htmlspecialchars($scheduleDay['label']) ?>: <?= $scheduleDay['count'] ?> booking<?= $scheduleDay['count'] === 1 ? '' : 's' ?>">
              <span><?= $scheduleDay['count'] ?></span><i style="height: <?= $scheduleHeight ?>%"></i><small><?= htmlspecialchars($scheduleDay['label']) ?></small>
            </div>
            <?php endforeach; ?>
          </div>
        </section>

        <section class="catalog-upcoming-card">
          <div class="catalog-summary-section-head"><h4>Upcoming bookings</h4><span><?= count($packageUpcoming) ?> shown</span></div>
          <div class="catalog-upcoming-list">
            <?php if (empty($packageUpcoming)): ?>
              <div class="catalog-upcoming-empty">No accepted upcoming package bookings.</div>
            <?php else: foreach ($packageUpcoming as $upcomingBooking): ?>
              <article class="catalog-upcoming-item package-upcoming-item">
                <time datetime="<?= htmlspecialchars($upcomingBooking['booking_date']) ?>"><b><?= date('d', strtotime($upcomingBooking['booking_date'])) ?></b><span><?= date('M', strtotime($upcomingBooking['booking_date'])) ?></span></time>
                <div><strong><?= htmlspecialchars($upcomingBooking['package_name']) ?></strong><p><?= htmlspecialchars($upcomingBooking['tourist_name'] ?: $upcomingBooking['booking_reference']) ?></p><small><?= (int)$upcomingBooking['pax'] ?> guest<?= (int)$upcomingBooking['pax'] === 1 ? '' : 's' ?> &middot; <?= $upcomingBooking['tour_type'] === 'overnight' ? 'Overnight' : 'Day tour' ?></small></div>
              </article>
            <?php endforeach; endif; ?>
          </div>
        </section>
      </aside>
    </div>

</div>

</div>

</main>

</div>
<!-- Edit Package Modal (Modernized & Rounded) -->
<div id="editModal" class="modal">
    <div class="modal-content">
        <button type="button" class="close" onclick="closeModal()" aria-label="Close package editor">&times;</button>
        <div class="af-modal-header package-editor-header">
          <span class="package-editor-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="m4 6 5-2 6 2 5-2v14l-5 2-6-2-5 2V6Z"/><path d="M9 4v14M15 6v14"/></svg></span>
          <div><small id="packageEditorEyebrow">PACKAGE EDITOR</small><strong id="af-media-modal-title">Edit package</strong><p id="packageEditorSubtitle">Maintain package details, imagery, and itinerary information.</p></div>
        </div>
        <form id="editPackageForm" method="post" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($adminCatalogCsrf, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="package_id" id="package_id">
            <div class="package-editor-layout">
                <nav class="package-editor-nav" aria-label="Package editor sections">
                    <button type="button" class="active" data-package-tab="information"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 4h16v16H4z"/><path d="M8 9h8M8 13h8M8 17h5"/></svg><span>Information</span></button>
                    <button type="button" data-package-tab="media"><svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2"/><circle cx="9" cy="10" r="2"/><path d="m4 17 5-4 3 2 3-4 5 6"/></svg><span>Media</span></button>
                    <button type="button" data-package-tab="itinerary"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 4h14v16H5z"/><path d="M9 8h6M9 12h6M9 16h4"/></svg><span>Itinerary</span></button>
                </nav>
                <div class="package-modal-body">
                    <section class="package-editor-pane active" data-package-pane="information">
                        <div class="form-row">
                            <div class="form-group">
                                <label for="package_title">Package Title <em class="package-required">*</em></label>
                                <input type="text" name="package_title" id="package_title" class="form-input" placeholder="Enter package title" required>
                            </div>
                            <div class="form-group">
                                <label for="price">Price <em class="package-required">*</em></label>
                                <input type="number" name="price" id="price" class="form-input" placeholder="Enter price" step="0.01" required>
                            </div>
                        </div>
                        <div class="form-row package-information-row">
                            <div class="form-group">
                                <label for="operator_id">Operator <em class="package-required">*</em></label>
                                <select name="operator_id" id="operator_id" class="form-input" required></select>
                            </div>
                            <div class="form-group">
                                <label for="package_type">Package Type <em class="package-required">*</em></label>
                                <select name="package_type" id="package_type" class="form-input" required>
                                    <option value="">-- Select Package Type --</option>
                                    <option value="same-day">Same-day</option>
                                    <option value="overnight">Overnight</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label for="package_range">Package Range</label>
                                <input type="text" name="package_range" id="package_range" class="form-input" placeholder="(e.g. 1 day | 1 Day 1 Night)">
                            </div>
                        </div>
                    </section>
                    <section class="package-editor-pane" data-package-pane="media">
                        <h4>Package Images</h4>
                        <div id="packageImagesContainer" style="display:flex; gap:15px; flex-wrap:wrap;"></div>
                        <h4>General Images</h4>
                        <div id="generalImagesContainer" style="display:flex; gap:20px; flex-wrap:wrap;">
                            <div style="text-align:left;">
                                <label style="display:block;text-align:left;">Location Image</label><br>
                                <img id="location_image_preview" src="img/sampleimage.png" style="width:150px;height:100px;object-fit:cover;border-radius:8px;"><br>
                                <button type="button" class="btn-green" onclick="openGeneralImageModal('location_image')" style="margin-top:9px">Update Image</button>
                            </div>
                            <div style="text-align:left;">
                                <label style="display:block;text-align:left;margin-bottom:19px">Route Image</label>
                                <img id="route_image_preview" src="img/sampleimage.png" style="width:150px;height:100px;object-fit:cover;border-radius:8px;"><br>
                                <button type="button" class="btn-green" onclick="openGeneralImageModal('route_image')" style="margin-top:10px">Update Image</button>
                            </div>
                        </div>
                    </section>
                    <section class="package-editor-pane" data-package-pane="itinerary">
                        <div class="package-itinerary-heading"><div><h4>Itinerary Steps</h4><p>Build the package schedule and drag steps to reorder them.</p></div></div>
                        <div id="itineraryContainer"></div>
                        <button type="button" class="add-step-btn package-add-step-bottom" onclick="addItineraryStep()">Add Step</button>
                    </section>
                </div>
            </div>
            <div class="package-modal-actions">
                <span class="package-required-note">Fields marked <em>*</em> are required.</span>
                <button type="button" class="btn-cancel" onclick="closeModal()">Cancel</button>
                <button type="button" class="btn-cancel package-wizard-action" id="packagePrevious" hidden>Previous</button>
                <button type="button" class="btn-green package-wizard-action" id="packageNext" hidden>Next step</button>
                <button type="submit" class="btn-green" id="packageSave">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<!-- Image Upload & Crop Modal -->
<div id="imageModal" class="modal">
    <div class="modal-content">
        <button type="button" class="close" onclick="closeImageModal()" aria-label="Close image editor">&times;</button>
        <div class="af-modal-header">
          <strong>Upload and crop image</strong>
          <p>Choose a high-quality image and adjust the crop before applying it.</p>
        </div>

        <div class="image-modal-body">
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
        </div>

        <!-- Controls -->
        <div style="margin-top:10px; display:flex; gap:10px; justify-content:center;">
            <button id="doneBtn" class="btn btn-green" style="display:none;">Done</button>
            <button id="cancelBtn" class="btn btn-red" style="display:none;">Cancel</button>
        </div>
    </div>
</div>


<script src="https://cdn.jsdelivr.net/npm/cropperjs@1.5.13/dist/cropper.min.js"></script>
<script src="js/image-upload-optimizer.js?v=<?= (int)@filemtime(__DIR__ . '/../js/image-upload-optimizer.js') ?>"></script>

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

    $imgField = trim($imgField);

    if (strpos($imgField, 'upload/') === 0) {
        return 'php/' . $imgField;
    }

    return $imgField;
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
echo "let packagesData = " . json_encode($jsPackages, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . ";";

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
let currentUploadMime = 'image/jpeg';
let currentUploadUrl = '';
const packageEditorSteps = ['information', 'media', 'itinerary'];

function switchPackageEditorPane(name) {
    const index = Math.max(0, packageEditorSteps.indexOf(name));
    const isAdd = editModal.dataset.mode === 'add';
    document.querySelectorAll('[data-package-tab]').forEach((button, buttonIndex) => {
        const active = button.dataset.packageTab === name;
        button.classList.toggle('active', active);
        button.classList.toggle('completed', isAdd && buttonIndex < index);
        if (active) button.setAttribute('aria-current', 'step');
        else button.removeAttribute('aria-current');
    });
    document.querySelectorAll('[data-package-pane]').forEach((pane) => pane.classList.toggle('active', pane.dataset.packagePane === name));
    const previous = document.getElementById('packagePrevious');
    const next = document.getElementById('packageNext');
    const save = document.getElementById('packageSave');
    previous.hidden = !isAdd || index === 0;
    next.hidden = !isAdd || index === packageEditorSteps.length - 1;
    save.hidden = isAdd && index !== packageEditorSteps.length - 1;
    save.textContent = isAdd ? 'Create Package' : 'Save Changes';
    document.querySelector('.package-modal-body').scrollTop = 0;
}

document.querySelectorAll('[data-package-tab]').forEach((button) => button.addEventListener('click', () => {
    const target = packageEditorSteps.indexOf(button.dataset.packageTab);
    const current = packageEditorSteps.indexOf(document.querySelector('[data-package-tab].active')?.dataset.packageTab || 'information');
    if (editModal.dataset.mode === 'add' && target > current) {
        document.getElementById('packageNext').click();
        return;
    }
    switchPackageEditorPane(button.dataset.packageTab);
}));
document.getElementById('packagePrevious').addEventListener('click', () => {
    const current = packageEditorSteps.indexOf(document.querySelector('[data-package-tab].active')?.dataset.packageTab || 'information');
    switchPackageEditorPane(packageEditorSteps[Math.max(0, current - 1)]);
});
document.getElementById('packageNext').addEventListener('click', () => {
    const currentName = document.querySelector('[data-package-tab].active')?.dataset.packageTab || 'information';
    const pane = document.querySelector(`[data-package-pane="${currentName}"]`);
    const invalid = Array.from(pane?.querySelectorAll('[required]') || []).find((field) => !field.checkValidity());
    if (invalid) { invalid.reportValidity(); return; }
    const current = packageEditorSteps.indexOf(currentName);
    switchPackageEditorPane(packageEditorSteps[Math.min(packageEditorSteps.length - 1, current + 1)]);
});

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
    croppedFiles = {};
    editModal.dataset.mode = 'edit';

    document.getElementById('packageEditorEyebrow').textContent = 'PACKAGE EDITOR';
    document.getElementById('af-media-modal-title').textContent = 'Edit package';
    document.getElementById('packageEditorSubtitle').textContent = 'Maintain package details, imagery, and itinerary information.';
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

    switchPackageEditorPane('information');
    editModal.style.display = 'flex';
    document.body.classList.add('modal-open');
}

// --- Open Add New Package Modal
function openAddModal() {
    currentPackageId = null;
    croppedFiles = {};
    editModal.dataset.mode = 'add';

    document.getElementById('packageEditorEyebrow').textContent = 'NEW TOUR PACKAGE';
    document.getElementById('af-media-modal-title').textContent = 'Add tour package';
    document.getElementById('packageEditorSubtitle').textContent = 'Complete the three steps to create a visitor-ready tour package.';
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

    switchPackageEditorPane('information');
    editModal.style.display = 'flex';
    document.body.classList.add('modal-open');
}



function closeModal() {
    editModal.style.display = 'none';
    document.body.classList.remove('modal-open');
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
                <label>Step Title <em class="package-required">*</em></label>
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
    document.body.classList.add('modal-open');
}

function openGeneralImageModal(field) {
    currentField = field;
    currentPackageId = document.getElementById("package_id").value;
    currentImgElement = document.getElementById(field + "_preview");

    resetImageModal();
    imageModal.style.display = "flex";
    document.body.classList.add('modal-open');
}

function closeImageModal() {
    resetImageModal();
    imageModal.style.display = "none";
    if (editModal.style.display !== 'flex') document.body.classList.remove('modal-open');
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
    if (currentUploadUrl) {
        URL.revokeObjectURL(currentUploadUrl);
        currentUploadUrl = '';
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
async function handleFile(file) {
    if (!file) return;
    doneBtn.disabled = true;doneBtn.textContent = 'Optimizing image...';
    try {
        const optimizedFile = await ItourImageOptimizer.optimizeSource(file, 4096);
        currentUploadMime = optimizedFile.type;
        if (currentUploadUrl) URL.revokeObjectURL(currentUploadUrl);
        currentUploadUrl = URL.createObjectURL(optimizedFile);
        cropImage.src = currentUploadUrl;

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
    } catch (error) {
        alert(error.message || 'The image could not be processed. Please try another photo.');
    } finally {
        doneBtn.disabled = false;doneBtn.textContent = 'Done';
    }
}

// ===============================
// DONE BUTTON
// ===============================
doneBtn.addEventListener("click", async () => {
    if (!cropper) return;
    doneBtn.disabled = true;doneBtn.textContent = 'Optimizing image...';
    try {
        const isWide = currentField === 'location_image' || currentField === 'route_image';
        const blob = await ItourImageOptimizer.exportCrop(cropper, currentUploadMime, {
            maxWidth: isWide ? 1920 : 1600,
            maxHeight: isWide ? 1080 : 1200
        });
        // Store temporarily
        croppedFiles[currentField] = blob;

        // Replace preview in EditModal
        const previewURL = URL.createObjectURL(blob);
        currentImgElement.src = previewURL;

        closeImageModal();
    } catch (error) {
        alert(error.message || 'The cropped image could not be prepared.');
    } finally {
        doneBtn.disabled = false;doneBtn.textContent = 'Done';
    }
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
    const activePane = document.querySelector('[data-package-tab].active')?.dataset.packageTab || 'information';
    if (editModal.dataset.mode === 'add' && activePane !== 'itinerary') {
        document.getElementById('packageNext').click();
        return;
    }
    const formData = new FormData(this);

    // Append cropped images
    for (let field in croppedFiles){
        const blob = croppedFiles[field];
        const extension = blob.type === 'image/jpeg' ? 'jpg' : blob.type.split('/')[1];
        formData.append(field, blob, field + '.' + extension);
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

const tourSearchInput = document.getElementById('tourContentSearch');

function applyTourContentSearch() {
    if (!tourSearchInput) return;

    const query = tourSearchInput.value.trim().toLowerCase();
    const cards = document.querySelectorAll('.tour-package-panel .package-card');
    let visibleCount = 0;

    cards.forEach((card) => {
        const text = (card.textContent || '').toLowerCase();
        const shouldShow = query === '' || text.includes(query);
        card.style.display = shouldShow ? '' : 'none';
        if (shouldShow) visibleCount += 1;
    });

    const emptyState = document.querySelector('.tour-tab-empty[data-empty-for="packages"]');
    if (emptyState) {
        emptyState.classList.toggle('show', visibleCount === 0);
    }
}

if (tourSearchInput) {
    tourSearchInput.addEventListener('input', applyTourContentSearch);
}
const tourSearchClear = document.getElementById('tourContentSearchClear');
if (tourSearchInput && tourSearchClear) {
    const syncTourSearchClear = () => tourSearchClear.classList.toggle('visible', tourSearchInput.value.length > 0);
    tourSearchInput.addEventListener('input', syncTourSearchClear);
    tourSearchClear.addEventListener('click', () => {
        tourSearchInput.value = '';
        syncTourSearchClear();
        applyTourContentSearch();
        tourSearchInput.focus();
    });
}

[editModal, imageModal].forEach(modal => {
    modal.addEventListener('click', event => {
        if (event.target !== modal) return;
        if (modal === imageModal) closeImageModal();
        else closeModal();
    });
});
document.addEventListener('keydown', event => {
    if (event.key !== 'Escape') return;
    if (imageModal.style.display === 'flex') closeImageModal();
    else if (editModal.style.display === 'flex') closeModal();
});

</script>


</body>
</html>

