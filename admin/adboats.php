<?php
chdir(__DIR__ . '/..');
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require 'php/db_connection.php'; // PDO connection

// Make sure uploads folder exists
$uploadDir = 'uploads/';
if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

// ----------------------
// Handle AJAX image upload
// ----------------------
if (isset($_POST['action']) && $_POST['action'] === 'upload_image_boat') {
    $boat_id = $_POST['boat_id'];
    $imgIndex = $_POST['imageIndex'];

    if (isset($_FILES['file'])) {
        $ext = pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION);
        $filename = "boat{$boat_id}_img{$imgIndex}." . $ext;
        $path = $uploadDir . $filename;
        if (move_uploaded_file($_FILES['file']['tmp_name'], $path)) {
            echo json_encode(['success' => true, 'filename' => $filename, 'url' => $path]);
        } else {
            echo json_encode(['success' => false]);
        }
    } else {
        echo json_encode(['success' => false]);
    }
    exit;
}

// ----------------------
// Handle adding/updating boat
// ----------------------
if (isset($_POST['saveBoat'])) {
    if (empty($_POST['boat_id'])) {
        // Add new boat
        $stmt = $pdo->prepare("INSERT INTO boats 
            (name, total_pax, size, boat_number, short_description, long_description, image1, image2, image3, image4, image5, created_at, updated_at) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())");
        $stmt->execute([
            $_POST['name'],
            $_POST['total_pax'],
            $_POST['size'],
            $_POST['boat_number'],
            $_POST['short_description'],
            $_POST['long_description'],
            $_POST['image1_current'],
            $_POST['image2_current'],
            $_POST['image3_current'],
            $_POST['image4_current'],
            $_POST['image5_current']
        ]);
    } else {
        // Update existing boat
        $boat_id = $_POST['boat_id'];
        $stmt = $pdo->prepare("UPDATE boats SET 
            name = ?, total_pax = ?, size = ?, boat_number = ?, short_description = ?, long_description = ?,
            image1 = ?, image2 = ?, image3 = ?, image4 = ?, image5 = ?, updated_at = NOW()
            WHERE boat_id = ?");
        $stmt->execute([
            $_POST['name'],
            $_POST['total_pax'],
            $_POST['size'],
            $_POST['boat_number'],
            $_POST['short_description'],
            $_POST['long_description'],
            $_POST['image1_current'],
            $_POST['image2_current'],
            $_POST['image3_current'],
            $_POST['image4_current'],
            $_POST['image5_current'],
            $boat_id
        ]);
    }

    // Set session flag for SweetAlert
    $_SESSION['boat_update_success'] = true;
}

// ----------------------
// Fetch boats
// ----------------------
$stmt = $pdo->query("SELECT * FROM boats ORDER BY boat_id ASC");
$boats = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ----------------------
// Check for success alert
// ----------------------
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
</head>
<body>


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


<?php $showBoatInlineToolbar = $showBoatInlineToolbar ?? true; ?>
<div class="boat-section">
<?php if ($showBoatInlineToolbar): ?>
<div class="boat-toolbar">
    <button id="add-boat-btn">Add New Boat</button>
</div>
<?php endif; ?>

<?php foreach($boats as $b): ?>
<div class="boat-card" data-boat='<?php echo json_encode($b); ?>'>
  
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
              <img id="boat-thumb-<?php echo $b['boat_id'].'-'.$i; ?>" src="<?php echo $b["image$i"] ?: 'img/sampleimage.png'; ?>" alt="Image<?php echo $i; ?>">
          <?php endfor; ?>
      </div>

  </div>

  <button type="button" class="boat-edit-btn">Edit</button>
</div>
<?php endforeach; ?>

<!-- Edit Boat Modal -->
<div class="boat-modal" id="boat-edit-modal">
  <div class="boat-modal-content">
    <form id="boat-edit-form" method="POST">
      <div class="boat-modal-header" style="display:flex; justify-content:center; position:relative; padding-bottom:12px; border-bottom:1px solid #eee;">
        <strong style="font-size:1.2rem; color:#2b7a66;">Edit Boat</strong>
      </div>
      <button id="boat-edit-close" type="button" class="boat-close-btn">✕</button>
      <input type="hidden" name="boat_id" id="boat-id-field">
      <label>Name</label><input type="text" name="name" id="boat-name-field">
      <div class="boat-img-stack" id="boat-img-stack">
        <?php for($i=1;$i<=5;$i++): ?>
          <div class="boat-img-item">
            <img id="boat-img-<?php echo $i; ?>" src="" alt="Image<?php echo $i; ?>">
            <button type="button" class="boat-upload-btn" onclick="openUploadModalBoat(<?php echo $i; ?>)">Upload New Image</button>
          </div>
        <?php endfor; ?>
      </div>
      <label>Total Pax</label><input type="number" name="total_pax" id="boat-total-pax">
      <label>Size</label><input type="text" name="size" id="boat-size">
      <label>Boat Number</label><input type="text" name="boat_number" id="boat-number">
      <label>Short Description</label><textarea name="short_description" id="boat-short-description"></textarea>
      <label>Long Description</label><textarea name="long_description" id="boat-long-description"></textarea>
      <?php for($i=1;$i<=5;$i++): ?>
        <input type="hidden" name="image<?php echo $i; ?>_current" id="boat-image<?php echo $i; ?>_current">
      <?php endfor; ?>
      <div class="boat-modal-actions">
          <button type="button" class="boat-cancel-btn" onclick="closeEditModalBoat()">Cancel</button>
          <button type="submit" name="saveBoat" class="boat-done-btn">Save Changes</button>
      </div>
    </form>
  </div>
</div>

<!-- Upload Image Modal -->
<div class="boat-img-modal" id="boat-upload-modal">
  <div class="boat-img-modal-content">
    <div class="boat-upload-title">Upload New Image</div>
    <div class="boat-upload-body">
        <label class="boat-custum-file-upload" id="boat-drag-area">
            <svg xmlns="http://www.w3.org/2000/svg" fill="" viewBox="0 0 24 24"><g stroke-width="0" id="SVGRepo_bgCarrier"></g><g stroke-linejoin="round" stroke-linecap="round" id="SVGRepo_tracerCarrier"></g><g id="SVGRepo_iconCarrier"> <path fill="" d="M10 1C9.73478 1 9.48043 1.10536 9.29289 1.29289L3.29289 7.29289C3.10536 7.48043 3 7.73478 3 8V20C3 21.6569 4.34315 23 6 23H7C7.55228 23 8 22.5523 8 22C8 21.4477 7.55228 21 7 21H6C5.44772 21 5 20.5523 5 20V9H10C10.5523 9 11 8.55228 11 8V3H18C18.5523 3 19 3.44772 19 4V9C19 9.55228 19.4477 10 20 10C20.5523 10 21 9.55228 21 9V4C21 2.34315 19.6569 1 18 1H10ZM9 7H6.41421L9 4.41421V7ZM14 15.5C14 14.1193 15.1193 13 16.5 13C17.8807 13 19 14.1193 19 15.5V16V17H20C21.1046 17 22 17.8954 22 19C22 20.1046 21.1046 21 20 21H13C11.8954 21 11 20.1046 11 19C11 17.8954 11.8954 17 13 17H14V16V15.5ZM16.5 11C14.142 11 12.2076 12.8136 12.0156 15.122C10.2825 15.5606 9 17.1305 9 19C9 21.2091 10.7909 23 13 23H20C22.2091 23 24 21.2091 24 19C24 17.1305 22.7175 15.5606 20.9844 15.122C20.7924 12.8136 18.858 11 16.5 11Z" clip-rule="evenodd" fill-rule="evenodd"></path> </g></svg>
            <div class="boat-text"><span>Click or Drag Image to Upload</span></div>
            <input type="file" id="boat-file-input" accept="image/*">
        </label>
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

<script src="https://cdn.jsdelivr.net/npm/cropperjs@1.5.13/dist/cropper.min.js"></script>
<script>
let currentBoatBoat = null;
let currentImgIndexBoat = null;
let cropperBoat = null;

// Open edit modal
document.querySelectorAll('.boat-edit-btn').forEach(btn => {
  btn.addEventListener('click', e => {
    let card = e.target.closest('.boat-card');
    currentBoatBoat = JSON.parse(card.getAttribute('data-boat'));
    document.querySelector('#boat-edit-form .boat-done-btn').textContent = "Update Boat";

    for (let i = 1; i <= 5; i++) {
      const btn = document.getElementById('boat-img-' + i).nextElementSibling;
      if (btn) btn.textContent = currentBoatBoat['image' + i] ? "Update Image" : "Add Image";
    }

    document.getElementById('boat-id-field').value = currentBoatBoat.boat_id;
    document.getElementById('boat-name-field').value = currentBoatBoat.name;
    document.getElementById('boat-total-pax').value = currentBoatBoat.total_pax;
    document.getElementById('boat-size').value = currentBoatBoat.size;
    document.getElementById('boat-number').value = currentBoatBoat.boat_number;
    document.getElementById('boat-short-description').value = currentBoatBoat.short_description;
    document.getElementById('boat-long-description').value = currentBoatBoat.long_description;

    for (let i = 1; i <= 5; i++) {
      let url = currentBoatBoat['image' + i] || 'img/sampleimage.png';
      let thumb = document.getElementById('boat-thumb-' + currentBoatBoat.boat_id + '-' + i);
      if (thumb) thumb.src = url;
      const editImg = document.getElementById('boat-img-' + i);
      if (editImg) editImg.src = url;
      const hidden = document.getElementById('boat-image' + i + '_current');
      if (hidden) hidden.value = url;
    }

    document.getElementById('boat-edit-modal').style.display = 'flex';
  });
});

// Close edit modal
function closeEditModalBoat() {
  document.getElementById('boat-edit-modal').style.display = 'none';
}
document.getElementById('boat-edit-close').addEventListener('click', closeEditModalBoat);

// Add Boat Modal
const addBoatBtn = document.getElementById('add-boat-btn');
if (addBoatBtn) addBoatBtn.addEventListener('click', () => {
  currentBoatBoat = null;
  document.getElementById('boat-edit-modal').style.display = 'flex';
  document.querySelector('#boat-edit-modal .boat-modal-header strong').textContent = "Add Boat";

  document.getElementById('boat-id-field').value = "";
  document.getElementById('boat-name-field').value = "";
  document.getElementById('boat-total-pax').value = "";
  document.getElementById('boat-size').value = "";
  document.getElementById('boat-number').value = "";
  document.getElementById('boat-short-description').value = "";
  document.getElementById('boat-long-description').value = "";

  for (let i = 1; i <= 5; i++) {
    const editImg = document.getElementById('boat-img-' + i);
    editImg.src = 'img/placeholder.png';
    const hidden = document.getElementById('boat-image' + i + '_current');
    hidden.value = "";
    const btn = editImg.nextElementSibling;
    if (btn) btn.textContent = "Add Image";
  }

  document.querySelector('#boat-edit-form .boat-done-btn').textContent = "Add Boat";
});

// Open Upload Modal
function openUploadModalBoat(imgIndex) {
  currentImgIndexBoat = imgIndex;
  document.getElementById('boat-upload-modal').style.display = 'flex';

  const dragArea = document.getElementById('boat-drag-area');
  const cropContainer = document.getElementById('boat-crop-container');

  if (dragArea) dragArea.style.display = 'flex';
  if (cropContainer) cropContainer.style.display = 'none';
  if (cropperBoat) { cropperBoat.destroy(); cropperBoat = null; }
}

// Cancel upload
document.getElementById('boat-cancel-upload').addEventListener('click', () => {
  document.getElementById('boat-upload-modal').style.display = 'none';
  if (cropperBoat) { cropperBoat.destroy(); cropperBoat = null; }
});

// Done upload
document.getElementById('boat-done-upload').addEventListener('click', () => {
  if (!cropperBoat) return;

  cropperBoat.getCroppedCanvas().toBlob(function(blob) {
    const formData = new FormData();
    formData.append('action', 'upload_image_boat');
    formData.append('boat_id', currentBoatBoat.boat_id);
    formData.append('imageIndex', currentImgIndexBoat);
    formData.append('file', blob, 'boat' + currentBoatBoat.boat_id + '_img' + currentImgIndexBoat + '.png');

    fetch('', { method: 'POST', body: formData })
      .then(res => res.json())
      .then(data => {
        if (data.success) {
          const editImg = document.getElementById('boat-img-' + currentImgIndexBoat);
          if (editImg) editImg.src = data.url;

          const hidden = document.getElementById('boat-image' + currentImgIndexBoat + '_current');
          if (hidden) hidden.value = data.url;

          const thumb = document.getElementById('boat-thumb-' + currentBoatBoat.boat_id + '-' + currentImgIndexBoat);
          if (thumb) thumb.src = data.url;

          document.getElementById('boat-upload-modal').style.display = 'none';
          if (cropperBoat) { cropperBoat.destroy(); cropperBoat = null; }
        }
      });
  });
});

// Drag & Drop / Click to Upload
const boatUploadBox = document.getElementById("boat-drag-area");
const boatFileInput = document.getElementById("boat-file-input");

if (boatUploadBox) {
  boatUploadBox.addEventListener("click", () => boatFileInput.click());

  ['dragenter', 'dragover'].forEach(evt => {
    boatUploadBox.addEventListener(evt, e => {
      e.preventDefault();
      e.stopPropagation();
      boatUploadBox.classList.add("drag-over");
    });
  });

  ['dragleave', 'drop'].forEach(evt => {
    boatUploadBox.addEventListener(evt, e => {
      e.preventDefault();
      e.stopPropagation();
      boatUploadBox.classList.remove("drag-over");
    });
  });

  boatUploadBox.addEventListener("drop", e => {
    if (e.dataTransfer.files.length > 0) {
      handleFile(e.dataTransfer.files[0]);
    }
  });
}

if (boatFileInput) {
  boatFileInput.addEventListener("change", () => {
    if (boatFileInput.files && boatFileInput.files[0]) {
      handleFile(boatFileInput.files[0]);
    }
  });
}

// Handle file for cropper
function handleFile(file) {
  const reader = new FileReader();
  reader.onload = function(e) {
    const img = document.getElementById('boat-crop-image');
    if (cropperBoat) cropperBoat.destroy();
    img.src = e.target.result;

    img.onload = function() {
      const cropContainer = document.getElementById('boat-crop-container');
      const dragArea = document.getElementById('boat-drag-area');
      if (dragArea) dragArea.style.display = 'none';
      if (cropContainer) cropContainer.style.display = 'block';

      cropperBoat = new Cropper(img, {
        aspectRatio: 16 / 9,
        viewMode: 1,
        autoCropArea: 1,
        responsive: true,
        movable: true,
        zoomable: true,
        background: false
      });
    };
  };
  reader.readAsDataURL(file);
}

// Close modals on background click
const boatEditModalEl = document.getElementById('boat-edit-modal');
if (boatEditModalEl) {
  boatEditModalEl.addEventListener('click', e => {
    if (e.target.id === 'boat-edit-modal') closeEditModalBoat();
  });
}
const boatUploadModalEl = document.getElementById('boat-upload-modal');
if (boatUploadModalEl) {
  boatUploadModalEl.addEventListener('click', e => {
    if (e.target.id === 'boat-upload-modal') {
      if (cropperBoat) cropperBoat.destroy();
      document.getElementById('boat-upload-modal').style.display = 'none';
    }
  });
}

</script>
</body>
</html>
