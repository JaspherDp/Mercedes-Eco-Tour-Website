<?php
chdir(__DIR__ . '/..');

if (session_status() === PHP_SESSION_NONE) {
require_once __DIR__ . '/../php/session_security.php';
AppSessionStart();
}

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

require 'php/db_connection.php';
require_once __DIR__ . '/../php/activity_logger.php';
require_once __DIR__ . '/../php/catalog_resource_summary.php';
require_once __DIR__ . '/../php/admin_auth_helper.php';
require_once __DIR__ . '/../php/search_thumbnail_helper.php';
require_once __DIR__ . '/../php/input_validation.php';

AdminRequireLogin();

$boatUploadCsrf = (string)($_SESSION['boat_upload_csrf'] ?? '');
if ($boatUploadCsrf === '') {
    $boatUploadCsrf = bin2hex(random_bytes(32));
    $_SESSION['boat_upload_csrf'] = $boatUploadCsrf;
}
$boatTempUploadToken = bin2hex(random_bytes(16));
$boatUploadTokens = is_array($_SESSION['boat_upload_tokens'] ?? null) ? $_SESSION['boat_upload_tokens'] : [];
$boatUploadTokens = array_filter($boatUploadTokens, static fn($createdAt) => is_int($createdAt) && $createdAt >= time() - 3600);
$boatUploadTokens[$boatTempUploadToken] = time();
$_SESSION['boat_upload_tokens'] = array_slice($boatUploadTokens, -12, null, true);

/* =====================================================
   🚨 CLEAN OUTPUT BUFFER (PREVENT JSON BREAK)
===================================================== */
ob_start();

/* =====================================================
   🔥 AJAX IMAGE UPLOAD (BOAT) - FIXED VERSION
===================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['action'])
    && $_POST['action'] === 'upload_image_boat'
) {
    header('Content-Type: application/json; charset=utf-8');
    ob_clean();
    http_response_code(410);
    echo json_encode(['success' => false, 'error' => 'Use the dedicated boat image upload endpoint.']);
    exit;
}

/* =====================================================
   🚢 SAVE / UPDATE BOAT (UNCHANGED - SAFE)
===================================================== */
if (isset($_POST['saveBoat'])) {
    $submittedBoatCsrf = $_POST['csrf_token'] ?? null;
    if (!is_string($submittedBoatCsrf) || $submittedBoatCsrf === '' || !hash_equals($boatUploadCsrf, $submittedBoatCsrf)) {
        http_response_code(403);
        exit('Invalid or expired request token.');
    }
    try {
    $rawBoatId = $_POST['boat_id'] ?? '';
    $boatId = ($rawBoatId === '' || $rawBoatId === '0') ? 0 : ItourValidationInt($rawBoatId, 'Boat ID', 1, PHP_INT_MAX);
    $isBoatUpdate = $boatId > 0;
    $name = ItourValidationText($_POST['name'] ?? null, 'Boat name', 120, true);
    $totalPax = ItourValidationInt($_POST['total_pax'] ?? null, 'Passenger capacity', 1, 500);
    $size = ItourValidationText($_POST['size'] ?? null, 'Boat size', 60, true);
    $boatNumber = ItourValidationText($_POST['boat_number'] ?? '', 'Boat number', 80);
    $shortDescription = ItourValidationText($_POST['short_description'] ?? '', 'Short description', 500);
    $longDescription = ItourValidationText($_POST['long_description'] ?? '', 'Long description', 5000);
    $imagePaths = [];
    for ($imageIndex = 1; $imageIndex <= 5; $imageIndex++) {
        $imagePaths[] = ItourValidationMediaPath($_POST['image' . $imageIndex . '_current'] ?? '', 'Boat image');
    }
    if ($isBoatUpdate) {
        $boatCheck = $pdo->prepare('SELECT 1 FROM boats WHERE boat_id = ? LIMIT 1');
        $boatCheck->execute([$boatId]);
        if (!$boatCheck->fetchColumn()) throw new InvalidArgumentException('Boat not found.');
    }

    if (!$isBoatUpdate) {

        $stmt = $pdo->prepare("INSERT INTO boats 
        (name, total_pax, size, boat_number, short_description, long_description,
         image1, image2, image3, image4, image5, created_at, updated_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())");

        $stmt->execute([
            $name, $totalPax, $size, $boatNumber, $shortDescription, $longDescription,
            ...$imagePaths
        ]);

    } else {

        $stmt = $pdo->prepare("UPDATE boats SET 
            name = ?, total_pax = ?, size = ?, boat_number = ?, short_description = ?, long_description = ?,
            image1 = ?, image2 = ?, image3 = ?, image4 = ?, image5 = ?, updated_at = NOW()
            WHERE boat_id = ?");

        $stmt->execute([
            $name, $totalPax, $size, $boatNumber, $shortDescription, $longDescription,
            ...$imagePaths, $boatId
        ]);
    }
    $savedBoatId = $isBoatUpdate ? $boatId : (int)$pdo->lastInsertId();
    logActivity(
        $pdo, 'Admin', (int)($_SESSION['admin_id'] ?? 0),
        (string)($_SESSION['admin_name'] ?? 'Administrator'),
        $isBoatUpdate ? 'Boat Updated' : 'Boat Added',
        ($isBoatUpdate ? 'Updated' : 'Added') . ' boat "' . $name . '".',
        'Boats', $savedBoatId
    );

    $_SESSION['boat_update_success'] = true;
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
    exit;
    } catch (InvalidArgumentException $exception) {
        http_response_code(422);
        exit($exception->getMessage());
    }
}

/* =====================================================
   🚢 FETCH BOATS
===================================================== */
$stmt = $pdo->query("SELECT * FROM boats ORDER BY boat_id ASC");
$boats = $stmt->fetchAll(PDO::FETCH_ASSOC);
$boatTotal = count($boats);
$boatOccupied = (int)$pdo->query("SELECT COUNT(DISTINCT boat_id) FROM bookings WHERE boat_id IS NOT NULL AND status = 'accepted' AND is_complete = 'uncomplete' AND (booking_date = CURDATE() OR (tour_type = 'overnight' AND booking_date = DATE_SUB(CURDATE(), INTERVAL 1 DAY)))")->fetchColumn();
$boatBooked = (int)$pdo->query("SELECT COUNT(DISTINCT boat_id) FROM bookings WHERE boat_id IS NOT NULL AND status = 'accepted' AND is_complete = 'uncomplete' AND booking_date > CURDATE()")->fetchColumn();
$boatAvailable = max(0, $boatTotal - $boatOccupied);
$catalogResourceSummary = catalogResourceSummary($pdo, 'boat', $boatTotal, $boatOccupied, $boatAvailable);

function boatDisplayImage(?string $image): string {
    $image = trim((string)$image);
    if ($image === '') return 'img/sampleimage.png';
    $relative = ltrim(str_replace('\\', '/', $image), '/');
    $extension = pathinfo($relative, PATHINFO_EXTENSION);
    if ($extension !== '') {
        $optimized = substr($relative, 0, -(strlen($extension) + 1)) . '.optimized.webp';
        if (is_file(__DIR__ . '/../' . $optimized)) return $optimized;
    }
    return $relative;
}

/* =====================================================
   🚢 ALERT FLAG
===================================================== */
$showAlert = false;

if (isset($_SESSION['boat_update_success'])) {
    $showAlert = true;
    unset($_SESSION['boat_update_success']);
}
?>


<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Boats Management</title>
<link rel="icon" type="image/png" href="img/newlogo.png">
<link href="https://cdn.jsdelivr.net/npm/cropperjs@1.5.13/dist/cropper.min.css" rel="stylesheet"/>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600&display=swap" rel="stylesheet" />
<link rel="stylesheet" href="styles/admin_panel_theme.css" />
<style>
body {
  font-family: 'Poppins', sans-serif;
}


/* Boat Card */
.boat-card {
    background: white;
    padding: 0 20px 20px 20px;
    margin-bottom: 20px;
    display: flex;
    border-radius: 10px;
    box-shadow: 0 4px 10px rgba(0,0,0,0.1);
    flex-wrap: wrap;
    position: relative; /* for positioning button */
}

/* Boat Info */
.boat-info {
    flex: 1;
    min-width: 300px;
}

/* Boat Title bigger */
.boat-info h3 {
    font-size: 1.6rem;  /* bigger */
    margin-bottom: 10px;
    color: #2b7a66;
}

/* Description paragraph spacing */
.boat-info p {
    margin: 10px 0;
}

/* PAX, Size, Boat# capsule style */
.boat-details {
    display: flex;
    gap: 8px;
    margin-top: 15px;
    flex-wrap: wrap;
}
.boat-details span {
    background: #e0e0e0; /* gray capsule */
    color: #333;
    padding: 4px 10px;
    border-radius: 50px;
    font-size: 0.85rem;
    font-weight: 500;
}

/* Boat Images vertical */
.boat-images {
    margin-top: 20px;
    display: flex;
    flex-direction: row;      /* side by side */
    align-items: center;      /* vertically centered */
    gap: 10px;
}

.boat-images img {
    width: 130px;
    height: 100px;
    object-fit: cover;
    border-radius: 6px;
    border: 1px solid #ddd;
}

/* Edit Button lower right */
.boat-edit-btn {
    position: absolute;
    bottom: 15px;
    right: 15px;
    background: #2b7a66;
    color: white;
    padding: 8px 14px;
    border-radius: 6px;
}

.boat-edit-btn:hover {
    background: #236050;
}

.boat-images img:hover {
    transform: scale(1.05);
}

.boat-toolbar {
    display: flex;
    justify-content: flex-end;
    margin: 0 0 14px;
}

/* Buttons */
.boat-edit-btn, .boat-done-btn, .boat-cancel-btn, .boat-upload-btn {
    cursor: pointer;
    padding: 7px 14px;
    border-radius: 6px;
    border: none;
    font-weight: bold;
    transition: background 0.2s, transform 0.1s;
}

.boat-upload-btn {
    margin-top: 15px;
    background: #2b7a66;
    color: white;
}
.boat-upload-btn:hover { background: #236050; }

/* Modal Styles */

.boat-modal, .boat-img-modal {
    display: none;
    position: fixed;
    z-index: 10000;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;

    background: rgba(0,0,0,0.5);
    align-items: center;
    justify-content: center;

    padding: 40px;   /* 🔥 KEY FIX: prevents sticking to screen edges */
    box-sizing: border-box;
}

.boat-modal-content, .boat-img-modal-content {
    background: white;
    padding: 25px;
    border-radius: 12px;

    width: 90%;
    max-width: 900px;

    max-height: calc(100vh - 120px);  /* 🔥 leaves top+bottom space */
    overflow-y: auto;

    gap: 25px;
    position: relative;
}

.boat-modal-content, 
.boat-img-modal-content {
    padding-bottom: 30px !important;
}

/* Bigger textarea height */
.boat-modal-content form textarea {
    width: 100%;
    min-height: 80px;      /* Increased height */
    resize: vertical;       /* Allow user to drag resize */
    padding: 10px;
    border-radius: 6px;
    border: 1px solid #ccc;
    font-size: 14px;
}

/* Save + Cancel container */
.boat-modal-actions {
    display: flex;
    justify-content: flex-end;
    gap: 12px;
    margin-top: 20px;
    margin-bottom: 25px !important;     /* added bottom spacing */
}

/* Upload modal also needs bottom padding */
.boat-img-modal-content {
    padding-bottom: 50px !important;
}

/* Make button width based on text only */
.boat-done-btn, .boat-cancel-btn {
    background: #2b7a66;
    color: #fff;
    width: auto !important;   /* button fits text */
    padding: 8px 18px;        /* better button spacing */
}

.boat-cancel-btn {
    background: #f0f0f0;
    color: #333;
    width: auto !important;   /* button fits text */
    padding: 8px 18px;        /* better button spacing */
}

.boat-done-btn:hover { background: #236050; }

.boat-cancel-btn:hover { 
  background: #888; 
}

/* Form inside modal */
.boat-modal-content form {
    flex: 1;
    display: flex;
    flex-direction: column;
    gap: 12px;
}

.boat-modal-content form input {
    width: 100%;
    padding: 10px;
    height: 100%;
    border-radius: 6px;
    border: 1px solid #ccc;
    font-size: 14px;
}

.long-desc-boat {
    font-size: 0.9rem; /* slightly smaller */
    color: #555;       /* optional: a bit lighter color for readability */
    line-height: 1.4;  /* keep it readable */
}

.boat-custum-file-upload {
    pointer-events: auto;
}

.boat-custum-file-upload svg,
.boat-custum-file-upload .boat-text {
    pointer-events: none;
}

.boat-custum-file-upload input {
    display: none;
}

/* Crop Container */
.boat-crop-container {
    max-width: 100%;
    max-height: 400px;
    overflow: hidden;
}
.boat-cropper-img {
    width: 100%;
    display: block;
    max-height: 400px;
}

/* Responsive */
@media(max-width:900px) {
    .boat-modal-content, .boat-img-modal-content {
        flex-direction: column;
    }
    .boat-img-stack {
        flex-direction: row;
        flex-wrap: wrap;
        gap: 8px;
    }
    .boat-img-stack img {
        width: 80px;
        height: 50px;
    }
    .boat-images img {
        width: 80px;
        height: 50px;
    }
}

/* Make upload modal smaller */
#boat-upload-modal .boat-img-modal-content {
    width: 450px !important;
    max-width: 90%;
    flex-direction: column;
    padding: 20px 25px;
    align-items: center;
}

/* Title */
.boat-upload-title {
    font-size: 1.3rem;
    font-weight: bold;
    color: #2b7a66;
    width: 100%;
    text-align: center;
    margin-bottom: 15px;
}

/* Drag area & crop aligned in center */
.boat-upload-body {
    width: 100%;
    display: flex;
    flex-direction: column;
    align-items: center;
}

/* Buttons at bottom */
.boat-upload-actions {
    width: 100%;
    display: flex;
    justify-content: center;
    gap: 15px;
    margin-top: 20px;
}

.boat-custum-file-upload.drag-over {
    border-color: #236050;       /* darker green border */
    background-color: rgba(43, 122, 102, 0.05); /* subtle green tint */
    transition: 0.2s;
}


/* Image Stack */
.boat-img-stack {
    display: flex;
    gap: 12px;
    flex-shrink: 0;
}
.boat-img-stack img {
    width: 150px;
    height: 100px;
    object-fit: cover;
    border-radius: 6px;
    border: 1px solid #ddd;
}
.boat-close-btn {
    position: absolute;
    top: 10px;          /* closer to the very top */
    right: 10px;       /* aligned to far right of modal */
    background: none;
    border: none;
    font-size: 15px;   /* clean modern size */
    font-weight: 600;
    cursor: pointer;
    color: #444;
    padding: 5px;
    line-height: 1;
    transition: 0.2s ease;
    z-index: 10;
}

.boat-close-btn:hover {
    color: #2b7a66;
    transform: scale(1.15);
}

#add-boat-btn {
    position: relative;  /* put it above other content */
    z-index: 10;         /* higher than cards */
    margin-top: -30px;    /* reset negative margin */
    border: none;
    outline: none;
    background: #2b7a66;
    color: white;
    padding: 10px 10px;
    font-weight: bold;
    border-radius: 8px;
    cursor: pointer;
    transition: background 0.2s, transform 0.1s;
}

#add-boat-btn:hover {
    background: #236050;
    transform: scale(1.03);
}


.boat-section {
  margin: 0;
}

.boat-custum-file-upload {
  height: 200px;
  width: 300px;
  display: flex;
  flex-direction: column;
  align-items: space-between;
  justify-content: space-between;
  gap: 20px;
  cursor: pointer;
  align-items: center;
  justify-content: center;
  border: 2px dashed #cacaca;
  background-color: rgba(255, 255, 255, 1);
  padding: 1.5rem;
  border-radius: 10px;
  box-shadow: 0px 48px 35px -48px rgba(0,0,0,0.1);
}

.boat-custum-file-upload * {
    pointer-events: none;
}

.boat-custum-file-upload .icon {
  display: flex;
  align-items: center;
  justify-content: center;
}

.boat-custum-file-upload .icon svg {
  height: 80px !important;
  fill: rgba(75, 85, 99, 1) !important;
}


</style>
<link rel="stylesheet" href="styles/admin_catalog_pages.css?v=20260920-1" />
<link rel="stylesheet" href="styles/admin_resource_calendar.css?v=20260813-1" />
</head>
<body class="catalog-page">
<div class="admin-container">
<?php include 'admin_sidebar.php'; ?>
<main class="main-content catalog-page-main">
  <header class="admin-header admin-page-header">
    <div class="admin-header-left admin-page-title">
      <span class="admin-page-title-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M3 18c2 2 4 2 6 0 2 2 4 2 6 0 2 2 4 2 6 0M5 14h14l-2.5 4H7.5L5 14ZM8 14V7h8v7M12 7V3l4 2-4 2Z"/></svg></span>
      <div class="admin-page-title-copy">
        <h2>Boats</h2>
        <p class="admin-header-subtitle">Manage vessel information, capacity, and gallery images</p>
      </div>
    </div>
    <?php $resourceCalendarType = 'boat'; include __DIR__ . '/resource_schedule_calendar.php'; ?>
  </header>
  <div class="dashboard-content catalog-content">
    <div class="catalog-toolbar">
      <div class="catalog-search-area">
        <label class="catalog-search" for="boatSearch">
          <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-4-4"/></svg>
          <input id="boatSearch" type="search" placeholder="Search by boat name, number, size, or capacity" autocomplete="off">
          <button type="button" class="catalog-search-clear" id="boatSearchClear" aria-label="Clear boat search">&times;</button>
        </label>
      </div>
      <div class="catalog-metrics" aria-label="Boat availability summary">
        <span class="catalog-metric neutral" title="All boats in the directory"><strong><?= $boatTotal ?></strong><small>Total</small></span>
        <span class="catalog-metric available" title="Boats without an active assignment today"><strong><?= $boatAvailable ?></strong><small>Available</small></span>
        <span class="catalog-metric occupied" title="Boats assigned to an accepted tour today"><strong><?= $boatOccupied ?></strong><small>Occupied</small></span>
        <span class="catalog-metric booked" title="Boats assigned to future accepted tours"><strong><?= $boatBooked ?></strong><small>Booked</small></span>
      </div>
      <div class="catalog-toolbar-actions">
        <?php include __DIR__ . '/service_prices_modal.php'; ?>
        <button id="add-boat-btn" class="catalog-primary-btn" type="button">+ Add boat</button>
      </div>
    </div>


<?php if ($showAlert): ?>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
try {
    Swal.fire({
        icon: 'success',
        title: 'Boat saved successfully',
        confirmButtonText: 'OK',
        customClass: {
            confirmButton: 'swal2-confirm-custom'
        }
    });
} catch(e) {
    alert('Boat saved successfully');
    console.error('SweetAlert error:', e);
}
</script>
<style>
.swal2-confirm-custom {
    background-color: #2b7066 !important;
    color: #fff !important;
    border: none !important;
}
.swal2-confirm-custom:hover {
    background-color: #236050 !important;
}
</style>
<?php endif; ?>

<div class="catalog-workspace">
<div class="catalog-list-scroll" aria-label="Boat directory">
<div class="boat-section">
<?php foreach($boats as $b): ?>
<?php
$boatCardData = $b;
for ($imageIndex = 1; $imageIndex <= 5; $imageIndex++) {
    $boatCardData["image{$imageIndex}_display"] = boatDisplayImage($b["image{$imageIndex}"] ?? '');
}
?>
<div class="boat-card" data-boat="<?php echo htmlspecialchars(json_encode($boatCardData), ENT_QUOTES, 'UTF-8'); ?>">
  
  <div class="boat-info">
      <h3><?php echo htmlspecialchars($b['name']); ?></h3>
      <p><?php echo htmlspecialchars($b['short_description']); ?></p>
      <p class="long-desc-boat"><?php echo htmlspecialchars($b['long_description']); ?></p>
      
      <div class="boat-details">
          <span>Pax: <?php echo $b['total_pax']; ?></span>
          <span>Size: <?php echo $b['size']; ?></span>
          <span>Boat #: <?php echo $b['boat_number']; ?></span>
      </div>

      <div class="boat-images">
          <?php for($i=1;$i<=5;$i++): ?>
              <img id="boat-thumb-<?php echo $b['boat_id'].'-'.$i; ?>" src="<?php echo htmlspecialchars(boatDisplayImage($b["image$i"] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" alt="<?php echo htmlspecialchars($b['name']); ?> gallery image <?php echo $i; ?>" loading="lazy" decoding="async">
          <?php endfor; ?>
      </div>

  </div>

  <button type="button" class="boat-edit-btn">Edit</button>
</div>
<?php endforeach; ?>
<div class="catalog-empty" id="boatEmptyState">No boats match your search.</div>
</div>
</div>
<?php include __DIR__ . '/catalog_resource_summary.php'; ?>
</div>

<!-- Edit Boat Modal -->
<div class="boat-modal" id="boat-edit-modal">
  <div class="boat-modal-content">
    <form id="boat-edit-form" method="POST">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($boatUploadCsrf, ENT_QUOTES, 'UTF-8') ?>">
      <div class="boat-modal-header catalog-modal-heading">
        <h3 id="boat-modal-title">Edit boat</h3>
        <p>Keep specifications and visitor-facing descriptions complete and accurate.</p>
      </div>
      <button id="boat-edit-close" type="button" class="boat-close-btn">✕</button>
      <input type="hidden" name="boat_id" id="boat-id-field">
      <div class="catalog-modal-body">
      <div class="catalog-form-section">
      <span class="catalog-form-section-title">Boat information</span>
      <label for="boat-name-field">Boat name</label><input type="text" name="name" id="boat-name-field" required maxlength="120">
      <div style="display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:12px; margin-top:12px;">
        <div><label for="boat-total-pax">Passenger capacity</label><input type="number" name="total_pax" id="boat-total-pax" min="1" required></div>
        <div><label for="boat-size">Vessel size</label><input type="text" name="size" id="boat-size" required maxlength="60"></div>
        <div><label for="boat-number">Boat number</label><input type="text" name="boat_number" id="boat-number" required maxlength="60"></div>
      </div>
      <label for="boat-short-description" style="margin-top:12px;">Short description</label><textarea name="short_description" id="boat-short-description" required maxlength="300"></textarea>
      <label for="boat-long-description">Detailed description</label><textarea name="long_description" id="boat-long-description" required maxlength="2000"></textarea>
      </div>
      <div class="catalog-form-section">
      <span class="catalog-form-section-title">Photo gallery</span>
      <div class="boat-img-stack" id="boat-img-stack">
        <?php for($i=1;$i<=5;$i++): ?>
          <div class="boat-img-item">
            <img id="boat-img-<?php echo $i; ?>" src="" alt="Boat gallery image <?php echo $i; ?>" decoding="async">
            <button type="button" class="boat-upload-btn" onclick="openUploadModalBoat(<?php echo $i; ?>)">Upload New Image</button>
          </div>
        <?php endfor; ?>
      </div>
      </div>
      <?php for($i=1;$i<=5;$i++): ?>
        <input type="hidden" name="image<?php echo $i; ?>_current" id="boat-image<?php echo $i; ?>_current">
      <?php endfor; ?>
      </div>
      <div class="boat-modal-actions">
          <button type="button" class="boat-cancel-btn" onclick="closeEditModalBoat()">Cancel</button>
          <button type="submit" name="saveBoat" class="boat-done-btn">Save boat</button>
      </div>
    </form>
  </div>
</div>

<!-- Upload Image Modal -->
<div class="boat-img-modal" id="boat-upload-modal">
  <div class="boat-img-modal-content">
    <button type="button" class="boat-close-btn" id="boat-upload-close" aria-label="Close image upload">&times;</button>
    <div class="boat-upload-title catalog-modal-heading">
      <h3>Upload boat image</h3>
      <p>Choose an image and adjust the crop before applying it.</p>
    </div>
    <div class="boat-upload-body">
      <div class="boat-custum-file-upload" id="boat-drag-area">
          <div class="icon">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" aria-hidden="true">
              <path d="M10 1C9.73478 1 9.48043 1.10536 9.29289 1.29289L3.29289 7.29289C3.10536 7.48043 3 7.73478 3 8V20C3 21.6569 4.34315 23 6 23H7C7.55228 23 8 22.5523 8 22C8 21.4477 7.55228 21 7 21H6C5.44772 21 5 20.5523 5 20V9H10C10.5523 9 11 8.55228 11 8V3H18C18.5523 3 19 3.44772 19 4V9C19 9.55228 19.4477 10 20 10C20.5523 10 21 9.55228 21 9V4C21 2.34315 19.6569 1 18 1H10ZM9 7H6.41421L9 4.41421V7ZM14 15.5C14 14.1193 15.1193 13 16.5 13C17.8807 13 19 14.1193 19 15.5V17H20C21.1046 17 22 17.8954 22 19C22 20.1046 21.1046 21 20 21H13C11.8954 21 11 20.1046 11 19C11 17.8954 11.8954 17 13 17H14V15.5ZM16.5 11C14.142 11 12.2076 12.8136 12.0156 15.122C10.2825 15.5606 9 17.1305 9 19C9 21.2091 10.7909 23 13 23H20C22.2091 23 24 21.2091 24 19C24 17.1305 22.7175 15.5606 20.9844 15.122C20.7924 12.8136 18.858 11 16.5 11Z"></path>
            </svg>
          </div>
          <div class="boat-text">
              <span>Click to upload image</span>
          </div>

      </div>

      <input type="file" id="boat-file-input" accept="image/*" hidden>
        <div class="boat-crop-container" id="boat-crop-container" style="display:none;">
            <img id="boat-crop-image" class="boat-cropper-img" src="">
        </div>
    </div>
    <div class="boat-upload-actions">
      <button type="button" class="boat-done-btn" id="boat-done-upload">Done</button>
      <button type="button" class="boat-cancel-btn" id="boat-cancel-upload">Cancel</button>
    </div>
  </div>
</div>

</div>
</main>
</div>

<script src="https://cdn.jsdelivr.net/npm/cropperjs@1.5.13/dist/cropper.min.js"></script>
<script src="js/image-upload-optimizer.js?v=1"></script>

<script>
document.addEventListener("DOMContentLoaded", () => {

console.log("BOAT SCRIPT LOADED");

let currentBoatBoat = null;
let currentImgIndexBoat = null;
let cropperBoat = null;
let boatUploadMime = 'image/jpeg';
let boatSourceUrl = '';

/* ---------------- GLOBAL ERROR DEBUG ---------------- */
window.addEventListener("error", (e) => {
    console.error("GLOBAL JS ERROR:", e.message, e.filename, e.lineno);
});

/* ---------------- SAFE ELEMENT GETTER ---------------- */
function el(id) {
    return document.getElementById(id);
}

/* ---------------- EDIT BOAT ---------------- */
document.querySelectorAll('.boat-edit-btn').forEach(btn => {
    btn.addEventListener('click', (e) => {

        const card = e.target.closest('.boat-card');
        if (!card) return;

        try {
            currentBoatBoat = JSON.parse(card.getAttribute('data-boat'));
        } catch (err) {
            console.error("Invalid boat JSON:", err);
            return;
        }

        const formBtn = document.querySelector('#boat-edit-form .boat-done-btn');
        if (formBtn) formBtn.textContent = "Update boat";
        const modalTitle = el('boat-modal-title');
        if (modalTitle) modalTitle.textContent = "Edit boat";

        for (let i = 1; i <= 5; i++) {
            const imgBtn = el('boat-img-' + i)?.nextElementSibling;
            if (imgBtn) {
                imgBtn.textContent =
                    currentBoatBoat['image' + i] ? "Update Image" : "Add Image";
            }
        }

        el('boat-id-field').value = currentBoatBoat.boat_id || "";
        el('boat-name-field').value = currentBoatBoat.name || "";
        el('boat-total-pax').value = currentBoatBoat.total_pax || "";
        el('boat-size').value = currentBoatBoat.size || "";
        el('boat-number').value = currentBoatBoat.boat_number || "";
        el('boat-short-description').value = currentBoatBoat.short_description || "";
        el('boat-long-description').value = currentBoatBoat.long_description || "";

        for (let i = 1; i <= 5; i++) {
            let url = currentBoatBoat['image' + i] || 'img/sampleimage.png';
            const displayUrl = currentBoatBoat['image' + i + '_display'] || url;

            const editImg = el('boat-img-' + i);
            if (editImg && editImg.getAttribute('src') !== displayUrl) editImg.src = displayUrl;

            const hidden = el('boat-image' + i + '_current');
            if (hidden) hidden.value = url;
        }

        el('boat-edit-modal').style.display = 'flex';
        document.body.classList.add('modal-open');
    });
});

/* ---------------- CLOSE MODAL ---------------- */
window.closeEditModalBoat = function() {
    el('boat-edit-modal').style.display = 'none';
    if (el('boat-edit-modal').style.display !== 'flex') document.body.classList.remove('modal-open');
};
el('boat-edit-close')?.addEventListener('click', closeEditModalBoat);

/* ---------------- ADD BOAT ---------------- */
el('add-boat-btn')?.addEventListener('click', () => {

    currentBoatBoat = null;

    el('boat-edit-modal').style.display = 'flex';
    document.body.classList.add('modal-open');

    const title = el('boat-modal-title');
    if (title) title.textContent = "Add boat";
    const formBtn = document.querySelector('#boat-edit-form .boat-done-btn');
    if (formBtn) formBtn.textContent = "Add boat";

    el('boat-id-field').value = "";
    el('boat-name-field').value = "";
    el('boat-total-pax').value = "";
    el('boat-size').value = "";
    el('boat-number').value = "";
    el('boat-short-description').value = "";
    el('boat-long-description').value = "";
    for (let i = 1; i <= 5; i++) {
        el('boat-img-' + i).src = 'img/sampleimage.png';
        el('boat-image' + i + '_current').value = '';
        const imgBtn = el('boat-img-' + i)?.nextElementSibling;
        if (imgBtn) imgBtn.textContent = 'Add image';
    }
});

/* ---------------- OPEN UPLOAD MODAL ---------------- */
window.openUploadModalBoat = function(imgIndex) {

    currentImgIndexBoat = imgIndex;

    const modal = el('boat-upload-modal');
    const dragArea = el('boat-drag-area');
    const cropContainer = el('boat-crop-container');
    const img = el('boat-crop-image');

    modal.style.display = 'flex';
    document.body.classList.add('modal-open');
    dragArea.style.display = 'flex';
    cropContainer.style.display = 'none';

    img.src = "";

    if (cropperBoat) {
        cropperBoat.destroy();
        cropperBoat = null;
    }
};

/* ---------------- CLOSE UPLOAD ---------------- */
el('boat-cancel-upload')?.addEventListener('click', () => {
    el('boat-upload-modal').style.display = 'none';
    if (el('boat-edit-modal').style.display !== 'flex') document.body.classList.remove('modal-open');

    if (cropperBoat) {
        cropperBoat.destroy();
        cropperBoat = null;
    }
    if (boatSourceUrl) {
        URL.revokeObjectURL(boatSourceUrl);
        boatSourceUrl = '';
    }
});

/* ---------------- DONE UPLOAD (FIXED JSON CRASH) ---------------- */
el('boat-done-upload')?.addEventListener('click', async () => {

    if (!cropperBoat) {
        alert("Cropper not ready");
        return;
    }

    let boatId = el('boat-id-field').value;
    if (!boatId || boatId === "undefined") boatId = <?= json_encode('temp_' . $boatTempUploadToken) ?>;

    const doneButton = el('boat-done-upload');
    doneButton.disabled = true;
    doneButton.textContent = 'Optimizing image...';
    try {
        const blob = await ItourImageOptimizer.exportCrop(cropperBoat, boatUploadMime, {maxWidth: 1920, maxHeight: 1080});

        const formData = new FormData();
        formData.append('action', 'upload_image_boat');
        formData.append('boat_id', boatId);
        formData.append('imageIndex', currentImgIndexBoat);
        formData.append('current_path', el('boat-image' + currentImgIndexBoat + '_current')?.value || '');
        formData.append('csrf_token', <?= json_encode($boatUploadCsrf, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>);
        const extension = blob.type === 'image/jpeg' ? 'jpg' : blob.type.split('/')[1];
        formData.append('file', blob, `boat.${extension}`);

        const res = await fetch('admin/upload_boat_image.php', {
            method: 'POST',
            headers: {'Accept': 'application/json','X-Requested-With': 'XMLHttpRequest'},
            body: formData
        });

        const text = await res.text();
        let data;
        try { data = JSON.parse(text); }
        catch (_error) { throw new Error('The server returned an invalid upload response.'); }
        if (!res.ok || !data.success) throw new Error(data.error || 'The image could not be uploaded.');

        const imgEl = el('boat-img-' + currentImgIndexBoat);
        if (imgEl) imgEl.src = data.url + '?t=' + Date.now();

        const hidden = el('boat-image' + currentImgIndexBoat + '_current');
        if (hidden) hidden.value = data.url;

        el('boat-upload-modal').style.display = 'none';
        if (el('boat-edit-modal').style.display !== 'flex') document.body.classList.remove('modal-open');

        cropperBoat.destroy();cropperBoat = null;
        if (boatSourceUrl) { URL.revokeObjectURL(boatSourceUrl);boatSourceUrl = ''; }
    } catch (error) {
        alert(error.message || 'The image could not be processed. Please try another photo.');
    } finally {
        doneButton.disabled = false;doneButton.textContent = 'Done';
    }
});

/* ---------------- FILE INPUT + DRAG DROP ---------------- */
const uploadBox = el("boat-drag-area");
const fileInput = el("boat-file-input");

if (uploadBox && fileInput) {

    uploadBox.addEventListener("click", () => fileInput.click());

    fileInput.addEventListener("change", e => {
        if (e.target.files?.length) handleFile(e.target.files[0]);
    });

    ["dragenter","dragover","dragleave","drop"].forEach(evt => {
        uploadBox.addEventListener(evt, e => e.preventDefault());
    });

    uploadBox.addEventListener("drop", e => {
        const file = e.dataTransfer.files?.[0];
        if (file) handleFile(file);
    });
}

/* ---------------- HANDLE FILE ---------------- */
async function handleFile(file) {

    const modal = el('boat-upload-modal');
    const img = el('boat-crop-image');
    const cropContainer = el('boat-crop-container');
    const dragArea = el('boat-drag-area');

    modal.style.display = 'flex';
    dragArea.style.display = 'none';
    cropContainer.style.display = 'block';

    if (cropperBoat) cropperBoat.destroy();

    const doneButton = el('boat-done-upload');
    doneButton.disabled = true;doneButton.textContent = 'Optimizing image...';
    try {
      const optimizedFile = await ItourImageOptimizer.optimizeSource(file, 4096);
      boatUploadMime = optimizedFile.type;
      if (boatSourceUrl) URL.revokeObjectURL(boatSourceUrl);
      boatSourceUrl = URL.createObjectURL(optimizedFile);
      img.src = boatSourceUrl;
    } catch (error) {
      dragArea.style.display = 'flex';cropContainer.style.display = 'none';
      alert(error.message || 'The image could not be processed. Please try another photo.');
      doneButton.disabled = false;doneButton.textContent = 'Done';
      return;
    }

    img.onload = () => {

        setTimeout(() => {
            cropperBoat = new Cropper(img, {
                aspectRatio: 16 / 9,
                viewMode: 1,
                autoCropArea: 1
            });
            doneButton.disabled = false;doneButton.textContent = 'Done';
        }, 50);

    };
}

/* ---------------- CLOSE MODALS ---------------- */
el('boat-edit-modal')?.addEventListener('click', e => {
    if (e.target.id === 'boat-edit-modal') closeEditModalBoat();
});

el('boat-upload-modal')?.addEventListener('click', e => {
    if (e.target.id === 'boat-upload-modal') {
        if (cropperBoat) cropperBoat.destroy();
        el('boat-upload-modal').style.display = 'none';
        if (el('boat-edit-modal').style.display !== 'flex') document.body.classList.remove('modal-open');
    }
});
el('boat-upload-close')?.addEventListener('click', () => el('boat-cancel-upload')?.click());

document.addEventListener('keydown', e => {
    if (e.key !== 'Escape') return;
    if (el('boat-upload-modal')?.style.display === 'flex') el('boat-cancel-upload')?.click();
    else if (el('boat-edit-modal')?.style.display === 'flex') closeEditModalBoat();
});

const boatSearch = el('boatSearch');
const boatCards = Array.from(document.querySelectorAll('.boat-card'));
const boatEmptyState = el('boatEmptyState');
const boatSearchClear = el('boatSearchClear');
function applyBoatSearch() {
    const query = boatSearch.value.trim().toLowerCase();
    let visible = 0;
    boatCards.forEach(card => {
        const show = !query || (card.textContent || '').toLowerCase().includes(query);
        card.style.display = show ? '' : 'none';
        if (show) visible++;
    });
    boatEmptyState?.classList.toggle('show', boatCards.length > 0 && visible === 0);
    boatSearchClear?.classList.toggle('visible', query.length > 0);
}
boatSearch?.addEventListener('input', applyBoatSearch);
boatSearchClear?.addEventListener('click', () => {
    boatSearch.value = '';
    applyBoatSearch();
    boatSearch.focus();
});

});
</script>
</body>
</html>
