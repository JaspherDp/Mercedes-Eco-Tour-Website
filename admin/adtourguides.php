<?php
chdir(__DIR__ . '/..');
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require 'php/db_connection.php'; // Your PDO connection

// Make sure uploads folder exists
$uploadDir = 'uploads/';
if(!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

// ----------------------
// Handle AJAX image upload (for tour guides)
// ----------------------
if(isset($_POST['action']) && $_POST['action'] === 'upload_image_guide'){
    $guide_id = isset($_POST['guide_id']) ? intval($_POST['guide_id']) : 0;

    if(isset($_FILES['file']) && $guide_id){
        $ext = pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION);
        $filename = "guide{$guide_id}.".$ext;
        $path = $uploadDir.$filename;
        if(move_uploaded_file($_FILES['file']['tmp_name'], $path)){
            echo json_encode(['success'=>true, 'filename'=>$filename, 'url'=>$path]);
        } else {
            echo json_encode(['success'=>false, 'error'=>'move_failed']);
        }
    } else {
        echo json_encode(['success'=>false, 'error'=>'no_file_or_id']);
    }
    exit;
}

// ----------------------
// Handle add/update guide (POST from form)
// ----------------------
if (isset($_POST['saveGuide'])) {
    $guide_id = !empty($_POST['guide_id']) ? intval($_POST['guide_id']) : null;
    $fullname = $_POST['fullname'] ?? '';
    $short_description = $_POST['short_description'] ?? '';
    $age = !empty($_POST['age']) ? intval($_POST['age']) : null;
    $experience = !empty($_POST['experience']) ? intval($_POST['experience']) : null;
    $profile_picture = $_POST['profile_picture_current'] ?? '';

    if ($guide_id) {
        // update
        $stmt = $pdo->prepare("UPDATE tour_guides SET fullname = ?, short_description = ?, age = ?, experience = ?, profile_picture = ?, updated_at = NOW() WHERE guide_id = ?");
        $stmt->execute([$fullname, $short_description, $age, $experience, $profile_picture, $guide_id]);
    } else {
        // insert
        $stmt = $pdo->prepare("INSERT INTO tour_guides (fullname, short_description, age, experience, profile_picture, created_at, updated_at) VALUES (?, ?, ?, ?, ?, NOW(), NOW())");
        $stmt->execute([$fullname, $short_description, $age, $experience, $profile_picture]);
    }

    // Set session flag for alert
    $_SESSION['guide_update_success'] = true;

    // Only redirect if this file is loaded directly, not included
    if (basename($_SERVER['PHP_SELF']) === 'adtourguides.php') {
        header("Location: " . $_SERVER['PHP_SELF']);
        exit;
    }
}

// ----------------------
// Show success alert if redirected or included
// ----------------------
$showAlert = false;
if (isset($_SESSION['guide_update_success'])) {
    $showAlert = true;
    unset($_SESSION['guide_update_success']);
}

// ----------------------
// Fetch tour guides
// ----------------------
$stmt = $pdo->query("SELECT * FROM tour_guides ORDER BY guide_id ASC");
$guides = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<head>
<link href="https://cdn.jsdelivr.net/npm/cropperjs@1.5.13/dist/cropper.min.css" rel="stylesheet"/>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600&display=swap" rel="stylesheet" />
<link rel="stylesheet" href="styles/admin_panel_theme.css" />
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<style>
:root{ --accent:#2b7a66; --muted:#e6e6e6; --card-bg:#fff; }

/* Container grid: 3 columns responsive */
.jg_guide-grid { display:grid; grid-template-columns: repeat(3, 1fr); gap:18px;}
@media(max-width:1000px){ .jg_guide-grid{ grid-template-columns: repeat(2,1fr); } }
@media(max-width:600px){ .jg_guide-grid{ grid-template-columns: 1fr; } }

/* Card */
.jg_guide-card {
    display: flex;           /* row layout: image left, text right */
    flex-direction: row;
    align-items: flex-start;
    padding: 16px;
    border-radius: 10px;
    box-shadow: 0 6px 18px rgba(15,15,15,0.06);
    gap: 16px;               /* spacing between image and text */
}

.jg_guide-img-col {
    width: 120px;
    flex-shrink: 0;
}

.jg_guide-info {
    flex: 1;
}

.jg_guide-footer {
    margin-top: 12px;
    display: flex;
    justify-content: flex-end;
    position: static;
}

.jg_guide-edit-btn {
    background: var(--accent);
    color: #fff;
    padding: 8px 12px;
    border-radius: 8px;
    border: none;
    cursor: pointer;
}

.jg_guide-edit-btn:hover {
    background: #236050;
}

.jg_guide-left { flex:1; min-width:0; }
.jg_guide-name { font-size:1.15rem; color:var(--accent); font-weight:600; margin-bottom:6px; }
.jg_guide-short { margin:6px 0 12px 0; color:#444; font-size:0.95rem; }
.jg_guide-capsules { display:flex; gap:8px; align-items:center; }
.jg_guide-capsules span { background:var(--muted); padding:6px 12px; border-radius:999px; font-weight:600; font-size:0.9rem; color:#333; }

.jg_guide-photo { width:120px; height:120px; border-radius:8px; object-fit:cover; border:1px solid #e1e1e1; }

/* Modal */
.jg_guide-modal, .jg_guide-img-modal { display:none; position:fixed; z-index:10000; inset:0; background:rgba(0,0,0,0.5); align-items:center; justify-content:center; padding:30px; box-sizing:border-box; }
.jg_guide-modal-content, .jg_guide-img-modal-content { background:#fff; border-radius:12px; width:100%; max-width:920px; padding:22px; max-height:calc(100vh - 80px); overflow:auto; position:relative; }
.jg_guide-close { position:absolute; top:12px; right:12px; border:none; background:none; font-size:18px; cursor:pointer; }

/* Form */
.jg_guide-form { display:flex; gap:18px; flex-direction:column; }
.jg_guide-form-row { display:flex; gap:12px; }
.jg_guide-form input[type="text"], .jg_guide-form input[type="number"], .jg_guide-form textarea { width:100%; padding:10px; border-radius:8px; border:1px solid #ddd; font-size:14px; }
.jg_guide-form textarea { min-height:90px; resize:vertical; }

.jg_guide-photo-large { width:220px; height:220px; object-fit:cover; border-radius:8px; border:1px solid #e1e1e1; }
.jg_guide-update-image-btn { margin-top:10px; background:#f3f3f3; border:1px dashed #ccc; padding:8px 10px; border-radius:8px; cursor:pointer; }

.jg_guide-actions { display:flex; justify-content:flex-end; gap:12px; margin-top:10px; }
.jg_guide-save, .jg_guide-cancel { padding:8px 14px; border-radius:8px; border:none; cursor:pointer; }
.jg_guide-save { background:var(--accent); color:#fff; }
.jg_guide-cancel { background:#f0f0f0; }

/* Upload modal */
.jg_guide_upload_modal {
  display:none;
  position:fixed;
  z-index:10000;
  inset:0;
  background:rgba(0,0,0,0.5);
  align-items:center;
  justify-content:center;
  padding:30px;
  box-sizing:border-box;
}

.jg_guide_upload_modal_content {
  background:#fff;
  border-radius:12px;
  width:100%;
  max-width:500px;
  padding:20px;
  display:flex;
  flex-direction:column;
  align-items:center;
  justify-content:center;
  position:relative;
  max-height:90vh;
  overflow:hidden;
}

/* Close button */
.jg_guide_upload_close {
  position:absolute;
  top:12px;
  right:12px;
  border:none;
  background:none;
  font-size:18px;
  cursor:pointer;
}

/* Custom drag/upload area */
.jg_guide_upload_custom_file {
  height: 220px;
  width: 100%;
  max-width: 400px;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 16px;
  cursor: pointer;
  border: 2px dashed #cacaca;
  background-color: #fff;
  padding: 1.5rem;
  border-radius: 12px;
  box-shadow: 0px 4px 8px rgba(0,0,0,0.08);
  transition: 0.2s;
}

.jg_guide_upload_custom_file:hover {
  border-color: #2b7a66;
}

.jg_guide_upload_custom_file .icon svg {
  height: 80px;
  width: 80px;
  fill: rgba(75, 85, 99, 1);
}

.jg_guide_upload_custom_file .text span {
  font-weight: 500;
  color: rgba(75, 85, 99, 1);
  font-size: 14px;
  text-align: center;
}

.jg_guide_upload_custom_file input {
  display: none;
}

/* Crop container */
.jg_guide_upload_crop_container {
  width:100%;
  max-height:400px;
  display:none;
  margin-top: 16px;
}

.jg_guide_upload_crop_image {
  width:100%;
  display:block;
  max-height:400px;
  object-fit:contain;
}

.jg_guide-update-image-btn {
    margin-top: 12px;
    background: var(--accent);
    color: #fff;
    border: none;
    padding: 10px 14px;
    border-radius: 8px;
    font-weight: 600;
    cursor: pointer;
    transition: 0.2s;
}

.jg_guide-update-image-btn:hover {
    background: #236050;
}

.jg_guide-save {
    padding: 8px 14px;
    border-radius: 8px;
    border: none;
    cursor: pointer;
    background: var(--accent);
    color: #fff;
    font-weight: 600;
    transition: 0.2s;
}

.jg_guide-save:hover {
    background: #236050;
}

.jg_guide-cancel {
    padding: 8px 14px;
    border-radius: 8px;
    border: none;
    cursor: pointer;
    background: #f0f0f0;
    color: #333;
    font-weight: 600;
    transition: 0.2s;
}

.jg_guide-cancel:hover {
    background: #ddd;
}

.jg_guide-toolbar {
    display: flex;
    justify-content: flex-end;
    margin: 0 0 14px;
}

#jg_guide_upload_drag_area.drag-over {
  border-color: #2b7a66; /* change border to accent color */
  background-color: rgba(43, 122, 102, 0.1); /* subtle highlight */
  transition: 0.2s;
}
</style>
</head>
<body>

<?php if($showAlert): ?>
<script>
try {
    Swal.fire({
        icon: 'success',
        title: 'Guide saved successfully',
        showConfirmButton: true,
        confirmButtonText: 'OK',
        customClass: {
            confirmButton: 'swal2-confirm-custom'
        }
    });
} catch(e) {
    // Fallback if SweetAlert fails
    alert('Guide saved successfully');
    console.error('SweetAlert error:', e);
}
</script>

<style>
.swal2-confirm-custom {
    background-color: #2b7a66 !important;
    color: #fff !important;
    border: none !important;
}
.swal2-confirm-custom:hover {
    background-color: #236050 !important; /* slightly darker on hover */
}
</style>
<?php endif; ?>



<?php if (!isset($showGuideInlineToolbar) || $showGuideInlineToolbar !== false): ?>
<div class="jg_guide-toolbar">
    <button id="jg_guide_add_btn" class="jg_guide-save">Add New Tour Guide</button>
</div>
<?php endif; ?>

<div class="jg_guide-grid">
<?php foreach($guides as $g): ?>
  <div class="jg_guide-card" data-guide='<?php echo json_encode($g); ?>'>

    <!-- IMAGE COLUMN -->
    <div class="jg_guide-img-col">
      <img class="jg_guide-photo"
           id="jg_guide_thumb_<?php echo $g['guide_id']; ?>"
           src="<?php echo $g['profile_picture'] ?: 'img/tourguide.png'; ?>"
           alt="<?php echo htmlspecialchars($g['fullname']); ?>">
    </div>

    <!-- TEXT COLUMN -->
    <div class="jg_guide-info">
      <div class="jg_guide-name"><?php echo htmlspecialchars($g['fullname']); ?></div>
      <div class="jg_guide-short"><?php echo htmlspecialchars($g['short_description']); ?></div>

      <div class="jg_guide-capsules">
        <span>Age: <?php echo htmlspecialchars($g['age']); ?></span>
        <span>Exp: <?php echo htmlspecialchars($g['experience']); ?> yrs</span>
      </div>

      <div class="jg_guide-footer">
        <button type="button" class="jg_guide-edit-btn">Edit</button>
      </div>
    </div>
  </div>
<?php endforeach; ?>
</div>

<!-- Edit Modal -->
<div class="jg_guide-modal" id="jg_guide_edit_modal">
  <div class="jg_guide-modal-content">
    <button class="jg_guide-close" id="jg_guide_edit_close">✕</button>
    <form class="jg_guide-form" id="jg_guide_edit_form" method="POST">
      <div style="display:flex; gap:18px; align-items:flex-start;">
        <div style="flex:1;">
          <input type="hidden" name="guide_id" id="jg_guide_id_field">
          <div style="margin-bottom: 16px;">
            <label>Full name</label>
            <input type="text" name="fullname" id="jg_guide_fullname">
          </div>

          <div style="margin-bottom: 16px;">
            <label>Short description</label>
            <textarea name="short_description" id="jg_guide_short_description"></textarea>
          </div>

          <div style="display:flex; gap:40px; margin-top:8px; flex-wrap:wrap;">
            <div style="flex:1; min-width: 150px;">
              <label>Age</label>
              <input type="number" name="age" id="jg_guide_age">
            </div>
            <div style="flex:1; min-width: 80px;">
              <label>Experience (years)</label>
              <input type="number" name="experience" id="jg_guide_experience">
            </div>
          </div>

          <input type="hidden" name="profile_picture_current" id="jg_guide_profile_picture_current">
        </div>

        <div style="width:260px; text-align:center;">
          <img id="jg_guide_profile_preview" class="jg_guide-photo-large" src="img/tourguide.png" alt="profile">
          <button type="button" id="jg_guide_update_image_btn" class="jg_guide-update-image-btn">Update Image</button>
        </div>
      </div>

      <div class="jg_guide-actions">
        <button type="button" class="jg_guide-cancel" id="jg_guide_cancel_btn">Cancel</button>
        <button type="submit" name="saveGuide" class="jg_guide-save">Save Changes</button>
      </div>
    </form>
  </div>
</div>

<!-- Image Upload Modal -->
<div class="jg_guide_upload_modal" id="jg_guide_upload_modal">
  <div class="jg_guide_upload_modal_content">
    <button class="jg_guide_upload_close" id="jg_guide_upload_close">✕</button>
    <h3 style="color:var(--accent); text-align:center; margin-top:4px;">Update Profile Image</h3>

    <div style="margin-top:12px; display:flex; flex-direction:column; align-items:center; gap:16px;">
      <label class="jg_guide_upload_custom_file" id="jg_guide_upload_drag_area">
        <div class="icon">
          <svg xmlns="http://www.w3.org/2000/svg" fill="" viewBox="0 0 24 24">
            <path fill="" d="M10 1C9.73478 1 9.48043 1.10536 9.29289 1.29289L3.29289 7.29289C3.10536 7.48043 3 7.73478 3 8V20C3 21.6569 4.34315 23 6 23H7C7.55228 23 8 22.5523 8 22C8 21.4477 7.55228 21 7 21H6C5.44772 21 5 20.5523 5 20V9H10C10.5523 9 11 8.55228 11 8V3H18C18.5523 3 19 3.44772 19 4V9C19 9.55228 19.4477 10 20 10C20.5523 10 21 9.55228 21 9V4C21 2.34315 19.6569 1 18 1H10ZM9 7H6.41421L9 4.41421V7ZM14 15.5C14 14.1193 15.1193 13 16.5 13C17.8807 13 19 14.1193 19 15.5V16V17H20C21.1046 17 22 17.8954 22 19C22 20.1046 21.1046 21 20 21H13C11.8954 21 11 20.1046 11 19C11 17.8954 11.8954 17 13 17H14V16V15.5ZM16.5 11C14.142 11 12.2076 12.8136 12.0156 15.122C10.2825 15.5606 9 17.1305 9 19C9 21.2091 10.7909 23 13 23H20C22.2091 23 24 21.2091 24 19C24 17.1305 22.7175 15.5606 20.9844 15.122C20.7924 12.8136 18.858 11 16.5 11Z" clip-rule="evenodd" fill-rule="evenodd"></path>
          </svg>
        </div>
        <div class="text">
          <span>Click or Drag Image Here</span>
        </div>
        <input type="file" id="jg_guide_upload_file_input" accept="image/*">
      </label>

      <div class="jg_guide_upload_crop_container" id="jg_guide_upload_crop_container">
        <img id="jg_guide_upload_crop_image" class="jg_guide_upload_crop_image" src="">
      </div>

      <div style="display:flex; gap:12px; justify-content:center; margin-top:12px;">
        <button type="button" id="jg_guide_upload_done" class="jg_guide-save">Done</button>
        <button type="button" id="jg_guide_upload_cancel" class="jg_guide-cancel">Cancel</button>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/cropperjs@1.5.13/dist/cropper.min.js"></script>
<script>
// ------------------------------
// Variables
// ------------------------------
let jg_currentGuide = null;
let jg_cropper = null;

// ------------------------------
// Edit Buttons & Add New
// ------------------------------
document.querySelectorAll('.jg_guide-edit-btn').forEach(btn => {
  btn.addEventListener('click', e => {
    const card = e.target.closest('.jg_guide-card');
    jg_currentGuide = JSON.parse(card.getAttribute('data-guide'));
    jg_openEditModalWithGuide(jg_currentGuide);
  });
});

const jgAddBtn = document.getElementById('jg_guide_add_btn');
if (jgAddBtn) {
  jgAddBtn.addEventListener('click', () => {
    jg_currentGuide = null;
    jg_openEditModalForNew();
  });
}

// ------------------------------
// Open Edit Modal
// ------------------------------
function jg_openEditModalWithGuide(guide){
  document.getElementById('jg_guide_id_field').value = guide.guide_id;
  document.getElementById('jg_guide_fullname').value = guide.fullname;
  document.getElementById('jg_guide_short_description').value = guide.short_description;
  document.getElementById('jg_guide_age').value = guide.age;
  document.getElementById('jg_guide_experience').value = guide.experience;
  const pic = guide.profile_picture && guide.profile_picture !== '' ? guide.profile_picture : 'img/tourguide.png';
  document.getElementById('jg_guide_profile_preview').src = pic;
  document.getElementById('jg_guide_profile_picture_current').value = pic;
  document.getElementById('jg_guide_edit_modal').style.display = 'flex';
}

function jg_openEditModalForNew(){
  document.getElementById('jg_guide_id_field').value = '';
  document.getElementById('jg_guide_fullname').value = '';
  document.getElementById('jg_guide_short_description').value = '';
  document.getElementById('jg_guide_age').value = '';
  document.getElementById('jg_guide_experience').value = '';
  document.getElementById('jg_guide_profile_preview').src = 'img/tourguide.png';
  document.getElementById('jg_guide_profile_picture_current').value = '';
  document.getElementById('jg_guide_edit_modal').style.display = 'flex';
}

// ------------------------------
// Close Edit Modal
// ------------------------------
function jg_closeEditModal() {
  document.getElementById('jg_guide_edit_modal').style.display = 'none';
}
document.getElementById('jg_guide_edit_close').addEventListener('click', jg_closeEditModal);
document.getElementById('jg_guide_cancel_btn').addEventListener('click', jg_closeEditModal);

// ------------------------------
// Open Upload Modal
// ------------------------------
document.getElementById('jg_guide_update_image_btn').addEventListener('click', () => {
  document.getElementById('jg_guide_upload_modal').style.display = 'flex';
  document.getElementById('jg_guide_upload_crop_container').style.display = 'none';
  if(jg_cropper){ jg_cropper.destroy(); jg_cropper = null; }
});

// ------------------------------
// Upload Drag & Drop
// ------------------------------
const jg_dragArea = document.getElementById('jg_guide_upload_drag_area');
const jg_fileInput = document.getElementById('jg_guide_upload_file_input');

// Highlight on drag over
['dragenter', 'dragover'].forEach(event => {
  jg_dragArea.addEventListener(event, e => {
    e.preventDefault();
    e.stopPropagation();
    jg_dragArea.classList.add('drag-over');
  });
});

// Remove highlight on drag leave or drop
['dragleave', 'drop'].forEach(event => {
  jg_dragArea.addEventListener(event, e => {
    e.preventDefault();
    e.stopPropagation();
    jg_dragArea.classList.remove('drag-over');
  });
});

jg_dragArea.addEventListener('click', () => jg_fileInput.click());
jg_dragArea.addEventListener('drop', e => {
  if(e.dataTransfer.files && e.dataTransfer.files.length) jg_handleUploadFile(e.dataTransfer.files[0]);
});
jg_fileInput.addEventListener('change', () => {
  if(jg_fileInput.files && jg_fileInput.files[0]) jg_handleUploadFile(jg_fileInput.files[0]);
});

// ------------------------------
// Handle File
// ------------------------------
function jg_handleUploadFile(file) {
  const reader = new FileReader();
  reader.onload = function(ev){
    const img = document.getElementById('jg_guide_upload_crop_image');
    img.src = ev.target.result;
    img.onload = function(){
      document.getElementById('jg_guide_upload_crop_container').style.display = 'block';
      jg_dragArea.style.display = 'none';
      if(jg_cropper) jg_cropper.destroy();
      jg_cropper = new Cropper(img, {
        aspectRatio: 1,
        viewMode:1,
        autoCropArea:1,
        responsive:true,
        background:false
      });
    }
  }
  reader.readAsDataURL(file);
}

// ------------------------------
// Cancel / Close Upload
// ------------------------------
function jg_closeUploadModal() {
  if(jg_cropper){ jg_cropper.destroy(); jg_cropper = null; }
  document.getElementById('jg_guide_upload_modal').style.display = 'none';
  jg_dragArea.style.display = 'flex';
}
document.getElementById('jg_guide_upload_cancel').addEventListener('click', jg_closeUploadModal);
document.getElementById('jg_guide_upload_close').addEventListener('click', jg_closeUploadModal);

// ------------------------------
// Done / Upload
// ------------------------------
document.getElementById('jg_guide_upload_done').addEventListener('click', () => {
  if(!jg_cropper) return;
  jg_cropper.getCroppedCanvas({width:600, height:600}).toBlob(function(blob){
    const fd = new FormData();
    fd.append('action','jg_upload_image');
    fd.append('guide_id', jg_currentGuide ? jg_currentGuide.guide_id : 0);
    fd.append('file', blob, 'guide_profile.png');

    fetch('', { method:'POST', body: fd })
      .then(r=>r.json())
      .then(data=>{
        if(data.success){
          const url = data.url;

          // Update modal only
          document.getElementById('jg_guide_profile_preview').src = url;
          document.getElementById('jg_guide_profile_picture_current').value = url;

          jg_closeUploadModal();
        } else {
          alert('Upload failed');
        }
      })
      .catch(()=> alert('Upload error'));
  }, 'image/png');
});

// ------------------------------
// Close modals by clicking outside
// ------------------------------
document.getElementById('jg_guide_edit_modal').addEventListener('click', e => {
  if(e.target.id === 'jg_guide_edit_modal') jg_closeEditModal();
});
document.getElementById('jg_guide_upload_modal').addEventListener('click', e => {
  if(e.target.id === 'jg_guide_upload_modal') jg_closeUploadModal();
});
</script>


</body>
