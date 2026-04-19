<?php
chdir(__DIR__ . '/..');
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require 'php/db_connection.php';

// ✅ Session Authentication Check
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    echo "<script>
        alert('Session expired! Please login again.');
        window.location.href = 'php/admin_login.php';
    </script>";
    exit();
}

// ✅ Handle AJAX update/add requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $response = ['success'=>false, 'message'=>'Operation failed'];

    if ($_POST['action'] === 'update' && isset($_POST['id'])) {
        $id = intval($_POST['id']);
        $title = $_POST['title'] ?? '';
        $short_desc = $_POST['short_desc'] ?? '';
        $long_desc = $_POST['long_desc'] ?? '';

        $image_path = null;
        if(isset($_FILES['image']) && $_FILES['image']['error'] === 0){
            $ext = pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION);
            $filename = 'uploads/about_' . time() . '_' . rand(1000,9999) . '.' . $ext;
            move_uploaded_file($_FILES['image']['tmp_name'], $filename);
            $image_path = $filename;
        }

        if ($image_path) {
            $stmt = $pdo->prepare("UPDATE about_gallery SET title=?, short_desc=?, long_desc=?, image_path=? WHERE id=?");
            $updated = $stmt->execute([$title, $short_desc, $long_desc, $image_path, $id]);
        } else {
            $stmt = $pdo->prepare("UPDATE about_gallery SET title=?, short_desc=?, long_desc=? WHERE id=?");
            $updated = $stmt->execute([$title, $short_desc, $long_desc, $id]);
        }

        if($updated) $response = ['success'=>true,'message'=>'Gallery item updated successfully!'];
    }

    if ($_POST['action'] === 'add') {
        $title = $_POST['title'] ?? '';
        $short_desc = $_POST['short_desc'] ?? '';
        $long_desc = $_POST['long_desc'] ?? '';
        $image_path = 'img/default.jpg';
        if(isset($_FILES['image']) && $_FILES['image']['error']===0){
            $ext = pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION);
            $filename = 'uploads/about_' . time() . '_' . rand(1000,9999) . '.' . $ext;
            move_uploaded_file($_FILES['image']['tmp_name'], $filename);
            $image_path = $filename;
        }
        $stmt = $pdo->prepare("INSERT INTO about_gallery (title, short_desc, long_desc, image_path) VALUES (?,?,?,?)");
        $added = $stmt->execute([$title, $short_desc, $long_desc, $image_path]);
        if($added) $response = ['success'=>true,'message'=>'New gallery item added successfully!'];
    }

    header('Content-Type: application/json');
    echo json_encode($response);
    exit;
}

// ✅ Fetch gallery items
$stmt = $pdo->query("SELECT * FROM about_gallery ORDER BY id ASC");
$galleryItems = $stmt->fetchAll(PDO::FETCH_ASSOC);

$showAboutInlineAddButton = $showAboutInlineAddButton ?? true;
?>

<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600&display=swap" rel="stylesheet" />
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.5.13/cropper.min.css" rel="stylesheet">
<link rel="stylesheet" href="styles/admin_panel_theme.css" />
<script src="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.5.13/cropper.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>


<section class="dashboard-content">
  <?php if ($showAboutInlineAddButton): ?>
  <button class="addnewbutton" id="addNewBtn">Add New Gallery Item</button>
  <?php endif; ?>

  <div id="galleryContainerUnique">
    <?php foreach($galleryItems as $item): ?>
    <div class="gallery-card-unique" id="galleryCardUnique<?= $item['id'] ?>">
        <img src="<?= htmlspecialchars($item['image_path']) ?>" class="gallery-image-unique">
        <div class="gallery-info-unique">
            <div class="gallery-title-unique" style="font-weight: bold; font-size: 1.2rem; margin-bottom: 10px">
                <?= htmlspecialchars($item['title']) ?>
            </div>
            <div class="gallery-short-desc-unique" style="margin-bottom: 15px">
                <?= htmlspecialchars($item['short_desc']) ?>
            </div>
            <div class="gallery-long-desc-unique" style="font-size: 0.85em; color: #555;">
                <?= htmlspecialchars($item['long_desc']) ?>
            </div>
            <div class="gallery-actions-unique">
                <button class="btn-edit-unique" data-bs-toggle="modal" data-bs-target="#editModalUnique<?= $item['id'] ?>">Edit</button>
            </div>
        </div>

        <!-- Modal -->
        <div class="modal fade modal-unique" id="editModalUnique<?= $item['id'] ?>" tabindex="-1">
          <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content p-3 position-relative">
              <button type="button" class="close-btn" data-bs-dismiss="modal">&times;</button>
              <div class="modal-header border-0 d-flex justify-content-center">
                <h5 class="modal-title">Edit About Gallery</h5>
              </div>

              <div class="row g-3">
                <div class="col-md-5">
                  <label class="custum-file-upload-unique" id="dragAreaUnique<?= $item['id'] ?>">
                      <div class="icon-unique">
                          <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24">
                              <path fill="" d="M10 1C9.73478 1 9.48043 1.10536 9.29289 1.29289L3.29289 7.29289C3.10536 7.48043 3 7.73478 3 8V20C3 21.6569 4.34315 23 6 23H7C7.55228 23 8 22.5523 8 22C8 21.4477 7.55228 21 7 21H6C5.44772 21 5 20.5523 5 20V9H10C10.5523 9 11 8.55228 11 8V3H18C18.5523 3 19 3.44772 19 4V9C19 9.55228 19.4477 10 20 10C20.5523 10 21 9.55228 21 9V4C21 2.34315 19.6569 1 18 1H10ZM9 7H6.41421L9 4.41421V7ZM14 15.5C14 14.1193 15.1193 13 16.5 13C17.8807 13 19 14.1193 19 15.5V16V17H20C21.1046 17 22 17.8954 22 19C22 20.1046 21.1046 21 20 21H13C11.8954 21 11 20.1046 11 19C11 17.8954 11.8954 17 13 17H14V16V15.5ZM16.5 11C14.142 11 12.2076 12.8136 12.0156 15.122C10.2825 15.5606 9 17.1305 9 19C9 21.2091 10.7909 23 13 23H20C22.2091 23 24 21.2091 24 19C24 17.1305 22.7175 15.5606 20.9844 15.122C20.7924 12.8136 18.858 11 16.5 11Z"></path>
                          </svg>
                      </div>
                      <div class="text-unique"><span>Click to upload image</span></div>
                      <input type="file" id="fileInputUnique<?= $item['id'] ?>">
                  </label>

                  <div id="cropperContainerUnique<?= $item['id'] ?>" style="display:none">
                    <img id="cropperImageUnique<?= $item['id'] ?>" class="img-fluid">
                    <div class="d-flex gap-2 mt-2">
                      <button class="btn-save-unique" id="cropDoneUnique<?= $item['id'] ?>">Done</button>
                      <button class="btn-cancel-unique" id="cropCancelUnique<?= $item['id'] ?>">Cancel</button>
                    </div>
                  </div>
                </div>
                <div class="col-md-7 modal-form-unique">
                  <input type="text" id="modalTitleUnique<?= $item['id'] ?>" class="form-control" value="<?= htmlspecialchars($item['title']) ?>">
                  <textarea rows="2" id="modalShortUnique<?= $item['id'] ?>" class="form-control"><?= htmlspecialchars($item['short_desc']) ?></textarea>
                  <textarea rows="3" id="modalLongUnique<?= $item['id'] ?>" class="form-control"><?= htmlspecialchars($item['long_desc']) ?></textarea>
                </div>
              </div>

              <div class="mt-3 text-end">
                <button class="btn-cancel-unique" data-bs-dismiss="modal">Cancel</button>
                <button class="btn-save-unique" onclick="saveModalChangesUnique(<?= $item['id'] ?>)">Save Changes</button>
              </div>
            </div>
          </div>
        </div>

        <!-- New Gallery Item Modal -->
        <div class="modal fade modal-unique" id="addModalUnique" tabindex="-1">
          <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content p-3 position-relative">
              <button type="button" class="close-btn" data-bs-dismiss="modal">&times;</button>
              <div class="modal-header border-0 d-flex justify-content-center">
                <h5 class="modal-title">Add New Gallery Item</h5>
              </div>

              <div class="row g-3">
                <div class="col-md-5">
                  <label class="custum-file-upload-unique" id="dragAreaNew">
                      <div class="icon-unique">
                          <!-- same SVG icon -->
                          <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24">
                              <path fill="" d="M10 1C9.73478 1 9.48043 1.10536 9.29289 1.29289L3.29289 7.29289C3.10536 7.48043 3 7.73478 3 8V20C3 21.6569 4.34315 23 6 23H7C7.55228 23 8 22.5523 8 22C8 21.4477 7.55228 21 7 21H6C5.44772 21 5 20.5523 5 20V9H10C10.5523 9 11 8.55228 11 8V3H18C18.5523 3 19 3.44772 19 4V9C19 9.55228 19.4477 10 20 10C20.5523 10 21 9.55228 21 9V4C21 2.34315 19.6569 1 18 1H10ZM9 7H6.41421L9 4.41421V7ZM14 15.5C14 14.1193 15.1193 13 16.5 13C17.8807 13 19 14.1193 19 15.5V16V17H20C21.1046 17 22 17.8954 22 19C22 20.1046 21.1046 21 20 21H13C11.8954 21 11 20.1046 11 19C11 17.8954 11.8954 17 13 17H14V16V15.5ZM16.5 11C14.142 11 12.2076 12.8136 12.0156 15.122C10.2825 15.5606 9 17.1305 9 19C9 21.2091 10.7909 23 13 23H20C22.2091 23 24 21.2091 24 19C24 17.1305 22.7175 15.5606 20.9844 15.122C20.7924 12.8136 18.858 11 16.5 11Z"></path>
                          </svg>
                      </div>
                      <div class="text-unique"><span>Click to upload image</span></div>
                      <input type="file" id="fileInputNew">
                  </label>

                  <div id="cropperContainerNew" style="display:none">
                    <img id="cropperImageNew" class="img-fluid">
                    <div class="d-flex gap-2 mt-2">
                      <button class="btn-save-unique" id="cropDoneNew">Done</button>
                      <button class="btn-cancel-unique" id="cropCancelNew">Cancel</button>
                    </div>
                  </div>
                </div>

                <div class="col-md-7 modal-form-unique">
                  <input type="text" id="modalTitleNew" class="form-control" placeholder="Title">
                  <textarea rows="2" id="modalShortNew" class="form-control" placeholder="Short Description"></textarea>
                  <textarea rows="3" id="modalLongNew" class="form-control" placeholder="Long Description"></textarea>
                </div>
              </div>

              <div class="mt-3 text-end">
                <button class="btn-cancel-unique" data-bs-dismiss="modal">Cancel</button>
                <button class="btn-save-unique" id="saveNewBtn">Add Gallery Item</button>
              </div>
            </div>
          </div>
        </div>

      </div>

      <script>
      let cropperUnique<?= $item['id'] ?>;
      const dragAreaUnique<?= $item['id'] ?> = document.getElementById('dragAreaUnique<?= $item['id'] ?>');
      const fileInputUnique<?= $item['id'] ?> = document.getElementById('fileInputUnique<?= $item['id'] ?>');
      const cropperContainerUnique<?= $item['id'] ?> = document.getElementById('cropperContainerUnique<?= $item['id'] ?>');
      const cropperImageUnique<?= $item['id'] ?> = document.getElementById('cropperImageUnique<?= $item['id'] ?>');

      /* ===========================
        CLICK TO SELECT IMAGE
        =========================== */
      dragAreaUnique<?= $item['id'] ?>.addEventListener('click', ()=> fileInputUnique<?= $item['id'] ?>.click());

      fileInputUnique<?= $item['id'] ?>.addEventListener('change', e=>{
        if(e.target.files.length){
          const file = e.target.files[0];
          const url = URL.createObjectURL(file);

          cropperImageUnique<?= $item['id'] ?>.src = url;
          cropperContainerUnique<?= $item['id'] ?>.style.display = 'block';
          dragAreaUnique<?= $item['id'] ?>.style.display = 'none';

          cropperUnique<?= $item['id'] ?> = new Cropper(cropperImageUnique<?= $item['id'] ?>, {
              aspectRatio: 1.5  // 300w / 200h = landscape
          });
        }
      });

      /* ===========================
        DRAG & DROP SUPPORT
        =========================== */
      dragAreaUnique<?= $item['id'] ?>.addEventListener("dragover", (e) => {
        e.preventDefault();
        dragAreaUnique<?= $item['id'] ?>.style.borderColor = "#49A47A";
        dragAreaUnique<?= $item['id'] ?>.style.backgroundColor = "#f8fffa";
      });

      dragAreaUnique<?= $item['id'] ?>.addEventListener("dragleave", (e) => {
        e.preventDefault();
        dragAreaUnique<?= $item['id'] ?>.style.borderColor = "#cacaca";
        dragAreaUnique<?= $item['id'] ?>.style.backgroundColor = "#fff";
      });

      dragAreaUnique<?= $item['id'] ?>.addEventListener("drop", (e) => {
        e.preventDefault();

        dragAreaUnique<?= $item['id'] ?>.style.borderColor = "#cacaca";
        dragAreaUnique<?= $item['id'] ?>.style.backgroundColor = "#fff";

        if (e.dataTransfer.files.length > 0) {
            fileInputUnique<?= $item['id'] ?>.files = e.dataTransfer.files;

            const file = e.dataTransfer.files[0];
            const url = URL.createObjectURL(file);

            cropperImageUnique<?= $item['id'] ?>.src = url;
            cropperContainerUnique<?= $item['id'] ?>.style.display = 'block';
            dragAreaUnique<?= $item['id'] ?>.style.display = 'none';

            cropperUnique<?= $item['id'] ?> = new Cropper(cropperImageUnique<?= $item['id'] ?>, {
                aspectRatio: 1.5,  // Landscape: 300 / 200
                viewMode: 1,
                autoCropArea: 1
            });

        }
      });

      /* ===========================
        CROP DONE
        =========================== */
      document.getElementById('cropDoneUnique<?= $item['id'] ?>').addEventListener('click', ()=>{
        const canvas = cropperUnique<?= $item['id'] ?>.getCroppedCanvas({
            width: 300,
            height: 200
        });
        dragAreaUnique<?= $item['id'] ?>.innerHTML = '<img src="'+canvas.toDataURL()+'">';
        dragAreaUnique<?= $item['id'] ?>.style.display = 'flex';
        cropperContainerUnique<?= $item['id'] ?>.style.display = 'none';
        cropperUnique<?= $item['id'] ?>.destroy();
      });

      /* ===========================
        CROP CANCEL
        =========================== */
      document.getElementById('cropCancelUnique<?= $item['id'] ?>').addEventListener('click', ()=>{
        cropperContainerUnique<?= $item['id'] ?>.style.display = 'none';
        dragAreaUnique<?= $item['id'] ?>.style.display = 'flex';
        cropperUnique<?= $item['id'] ?>.destroy();
      });
      /* ===========================
        SAVE CHANGES (Edit)
      =========================== */
      function saveModalChangesUnique(id){
        const formData = new FormData();
        formData.append('action','update');
        formData.append('id', id);
        formData.append('title', document.getElementById(`modalTitleUnique${id}`).value);
        formData.append('short_desc', document.getElementById(`modalShortUnique${id}`).value);
        formData.append('long_desc', document.getElementById(`modalLongUnique${id}`).value);

        const dragArea = document.getElementById(`dragAreaUnique${id}`);
        const imgTag = dragArea.querySelector('img');

        if(imgTag && imgTag.src.startsWith('data:')){
          fetch(imgTag.src)
            .then(res => res.blob())
            .then(blob => {
              formData.append('image', blob, 'cropped.png');
              sendUpdate(formData);
            }).catch(err => {
              Swal.fire({
                icon: 'error',
                title: 'Error',
                text: 'Failed to process image.',
                confirmButtonColor: '#49A47A'
              });
            });
        } else {
          sendUpdate(formData);
        }

        function sendUpdate(fd){
          fetch('adabout.php',{method:'POST',body:fd})
            .then(res=>res.json())
            .then(data=>{
              if(data.success){
                Swal.fire({
                  icon: 'success',
                  title: 'Success',
                  text: 'About Gallery Updated Successfully!',
                  confirmButtonColor: '#49A47A'
                }).then(()=> location.reload());
              } else {
                Swal.fire({
                  icon: 'error',
                  title: 'Error',
                  text: data.message || 'Failed to update gallery.',
                  confirmButtonColor: '#d33'
                });
              }
            })
            .catch(err=>{
              console.error(err);
              Swal.fire({
                icon: 'error',
                title: 'Error',
                text: 'An unexpected error occurred.',
                confirmButtonColor: '#d33'
              });
            });
        }
      }

      </script>

    <?php endforeach; ?>
  </div>
</section>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>

const dragAreaNew = document.getElementById('dragAreaNew');
const fileInputNew = document.getElementById('fileInputNew');
const cropperContainerNew = document.getElementById('cropperContainerNew');
const cropperImageNew = document.getElementById('cropperImageNew');
let cropperNew;

// ===========================
// Handle File Selection
// ===========================
function handleNewFile(file) {
  const url = URL.createObjectURL(file);

  // Show cropper, hide drag area content
  cropperImageNew.src = url;
  cropperContainerNew.style.display = 'block';
  dragAreaNew.style.display = 'none';

  // Destroy previous cropper if exists
  if (cropperNew) cropperNew.destroy();

  // Initialize cropper
  cropperNew = new Cropper(cropperImageNew, {
    aspectRatio: 1.5, // landscape 300/200
    viewMode: 1,
    autoCropArea: 1
  });
}

// ===========================
// Drag & Drop + Click
// ===========================
function initDragDropNew() {
  // Click to open file dialog
  dragAreaNew.addEventListener('click', () => fileInputNew.click());

  // Drag over
  dragAreaNew.addEventListener('dragover', e => {
    e.preventDefault();
    dragAreaNew.style.borderColor = "#49A47A";
    dragAreaNew.style.backgroundColor = "#f8fffa";
  });

  // Drag leave
  dragAreaNew.addEventListener('dragleave', e => {
    e.preventDefault();
    dragAreaNew.style.borderColor = "#cacaca";
    dragAreaNew.style.backgroundColor = "#fff";
  });

  // Drop
  dragAreaNew.addEventListener('drop', e => {
    e.preventDefault();
    dragAreaNew.style.borderColor = "#cacaca";
    dragAreaNew.style.backgroundColor = "#fff";

    if (e.dataTransfer.files.length > 0) handleNewFile(e.dataTransfer.files[0]);
  });

  // File input change
  fileInputNew.addEventListener('change', e => {
    if (e.target.files.length > 0) handleNewFile(e.target.files[0]);
  });
}

// ===========================
// Open Add Modal
// ===========================
document.getElementById('addNewBtn').addEventListener('click', () => {
  // Reset form
  document.getElementById('modalTitleNew').value = '';
  document.getElementById('modalShortNew').value = '';
  document.getElementById('modalLongNew').value = '';

  // Reset drag area
  dragAreaNew.querySelector('img')?.remove();
  dragAreaNew.style.display = 'flex';
  dragAreaNew.querySelector('.icon-unique, .text-unique').style.display = 'flex';
  fileInputNew.value = '';
  cropperContainerNew.style.display = 'none';
  if (cropperNew) cropperNew.destroy();

  // Show modal
  new bootstrap.Modal(document.getElementById('addModalUnique')).show();
});

// ===========================
// Crop Done / Cancel
// ===========================
document.getElementById('cropDoneNew').addEventListener('click', () => {
  const canvas = cropperNew.getCroppedCanvas({ width: 300, height: 200 });

  // Show cropped image in drag area
  dragAreaNew.innerHTML = `<img src="${canvas.toDataURL()}" class="img-fluid">`;
  dragAreaNew.style.display = 'flex';
  cropperContainerNew.style.display = 'none';
  if (cropperNew) cropperNew.destroy();
});

document.getElementById('cropCancelNew').addEventListener('click', () => {
  cropperContainerNew.style.display = 'none';
  dragAreaNew.style.display = 'flex';
  dragAreaNew.querySelector('.icon-unique, .text-unique').style.display = 'flex';
  if (cropperNew) cropperNew.destroy();
});


/* ===========================
  SAVE NEW GALLERY ITEM (Add)
=========================== */
document.getElementById('saveNewBtn').addEventListener('click', () => {
  const formData = new FormData();
  formData.append('action', 'add');
  formData.append('title', document.getElementById('modalTitleNew').value);
  formData.append('short_desc', document.getElementById('modalShortNew').value);
  formData.append('long_desc', document.getElementById('modalLongNew').value);

  const imgTag = dragAreaNew.querySelector('img');
  if (imgTag && imgTag.src.startsWith('data:')) {
    fetch(imgTag.src)
      .then(res => res.blob())
      .then(blob => {
        formData.append('image', blob, 'cropped.png');
        sendAdd(formData);
      }).catch(err => {
        Swal.fire({
          icon: 'error',
          title: 'Error',
          text: 'Failed to process image.',
          confirmButtonColor: '#49A47A'
        });
      });
  } else {
    sendAdd(formData);
  }

  function sendAdd(fd) {
    fetch('adabout.php', { method: 'POST', body: fd })
      .then(res => res.json())
      .then(data => {
        if(data.success){
          Swal.fire({
            icon: 'success',
            title: 'Success',
            text: 'About Gallery Added Successfully!',
            confirmButtonColor: '#49A47A'
          }).then(()=> location.reload());
        } else {
          Swal.fire({
            icon: 'error',
            title: 'Error',
            text: data.message || 'Failed to add gallery.',
            confirmButtonColor: '#d33'
          });
        }
      })
      .catch(err=>{
        console.error(err);
        Swal.fire({
          icon: 'error',
          title: 'Error',
          text: 'An unexpected error occurred.',
          confirmButtonColor: '#d33'
        });
      });
  }
});

// ===========================
// Initialize Drag & Drop
// ===========================
initDragDropNew();


// Prevent back button caching
window.addEventListener('pageshow', e => { if(e.persisted) location.reload(); });
window.history.pushState(null, "", location.href);
window.onpopstate = () => location.reload();
</script>
