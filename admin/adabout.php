<?php
chdir(__DIR__ . '/..');
if (session_status() === PHP_SESSION_NONE) {
require_once __DIR__ . '/../php/session_security.php';
AppSessionStart();
}
require 'php/db_connection.php';
require_once __DIR__ . '/../php/activity_logger.php';
require_once __DIR__ . '/../php/admin_auth_helper.php';
require_once __DIR__ . '/../php/input_validation.php';
require_once __DIR__ . '/../php/secure_upload_helper.php';

// ✅ Session Authentication Check
AdminRequireLogin();
$adminContentCsrf = AppCsrfToken('admin', 'catalog_content');

// ✅ Handle AJAX update/add requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!AppVerifyCsrf('admin', 'catalog_content', $_POST['csrf_token'] ?? null)) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Your session expired. Refresh the page and try again.']);
        exit;
    }
    $response = ['success'=>false, 'message'=>'Operation failed'];
    $requestedAction = (string)$_POST['action'];
    $titleInput = trim((string)($_POST['title'] ?? ''));
    if (in_array($requestedAction, ['add', 'update'], true) && $titleInput === '') {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'A gallery title is required.']);
        exit;
    }
    if (in_array($requestedAction, ['add', 'update'], true)) {
        try {
            $titleInput = ItourValidationText($_POST['title'] ?? null, 'Gallery title', 180, true);
            $shortDescriptionInput = ItourValidationText($_POST['short_desc'] ?? '', 'Short description', 500);
            $longDescriptionInput = ItourValidationText($_POST['long_desc'] ?? '', 'Long description', 5000);
        } catch (InvalidArgumentException $exception) {
            http_response_code(422);
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => $exception->getMessage()]);
            exit;
        }
    }

    $validatedImageExtension = null;
    if (isset($_FILES['image']) && (int)$_FILES['image']['error'] !== UPLOAD_ERR_NO_FILE) {
        try {
            $validatedImage = ItourSecureValidateUploadedImage($_FILES['image'], 8 * 1024 * 1024);
            $validatedImageExtension = (string)$validatedImage['extension'];
        } catch (InvalidArgumentException $exception) {
            http_response_code(422);
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => $exception->getMessage()]);
            exit;
        }
    }

    try {

    if ($_POST['action'] === 'update' && isset($_POST['id'])) {
        $id = ItourValidationInt($_POST['id'], 'Gallery item ID', 1, PHP_INT_MAX);
        $title = $titleInput;
        $short_desc = $shortDescriptionInput;
        $long_desc = $longDescriptionInput;

        $image_path = null;
        if ($validatedImageExtension !== null) {
            $filename = ItourSecureRandomFilename('about', $validatedImageExtension);
            $image_path = 'uploads/' . $filename;
            $uploadDirectory = ItourEnsureProjectDirectory('uploads');
            if (!move_uploaded_file($_FILES['image']['tmp_name'], $uploadDirectory . DIRECTORY_SEPARATOR . $filename)) {
                throw new RuntimeException('The uploaded image could not be saved.');
            }
        }

        if ($image_path) {
            $stmt = $pdo->prepare("UPDATE about_gallery SET title=?, short_desc=?, long_desc=?, image_path=? WHERE id=?");
            $updated = $stmt->execute([$title, $short_desc, $long_desc, $image_path, $id]);
        } else {
            $stmt = $pdo->prepare("UPDATE about_gallery SET title=?, short_desc=?, long_desc=? WHERE id=?");
            $updated = $stmt->execute([$title, $short_desc, $long_desc, $id]);
        }

        if($updated) {
            logActivity(
                $pdo, 'Admin', (int)($_SESSION['admin_id'] ?? 0),
                (string)($_SESSION['admin_name'] ?? 'Administrator'),
                'About Content Updated', 'Updated the About gallery item "' . $title . '".',
                'Website Content', $id
            );
            $response = ['success'=>true,'message'=>'Gallery item updated successfully!'];
        }
    }

    if ($_POST['action'] === 'add') {
        $title = $titleInput;
        $short_desc = $shortDescriptionInput;
        $long_desc = $longDescriptionInput;
        if ((int)$pdo->query('SELECT COUNT(*) FROM about_gallery')->fetchColumn() >= 50) {
            throw new InvalidArgumentException('The About gallery may contain at most 50 items.');
        }
        $image_path = 'img/default.jpg';
        if ($validatedImageExtension !== null) {
            $filename = ItourSecureRandomFilename('about', $validatedImageExtension);
            $image_path = 'uploads/' . $filename;
            $uploadDirectory = ItourEnsureProjectDirectory('uploads');
            if (!move_uploaded_file($_FILES['image']['tmp_name'], $uploadDirectory . DIRECTORY_SEPARATOR . $filename)) {
                throw new RuntimeException('The uploaded image could not be saved.');
            }
        }
        $stmt = $pdo->prepare("INSERT INTO about_gallery (title, short_desc, long_desc, image_path) VALUES (?,?,?,?)");
        $added = $stmt->execute([$title, $short_desc, $long_desc, $image_path]);
        if($added) {
            $newGalleryId = (int)$pdo->lastInsertId();
            logActivity(
                $pdo, 'Admin', (int)($_SESSION['admin_id'] ?? 0),
                (string)($_SESSION['admin_name'] ?? 'Administrator'),
                'About Content Added', 'Added the About gallery item "' . $title . '".',
                'Website Content', $newGalleryId
            );
            $response = ['success'=>true,'message'=>'New gallery item added successfully!'];
        }
    }
    } catch (Throwable $error) {
        $response = ['success' => false, 'message' => $error->getMessage() ?: 'The gallery item could not be saved.'];
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
<?php if ($showAboutInlineAddButton): ?>
<link rel="stylesheet" href="styles/admin_panel_theme.css" />
<?php endif; ?>
<script src="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.5.13/cropper.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<style>
/* Self-contained dialog state prevents About editors leaking into sibling tabs. */
.modal-unique {
  position: fixed;
  inset: 0;
  z-index: 10050;
  display: none !important;
  align-items: center;
  justify-content: center;
  overflow: auto;
  padding: 18px;
  background: rgba(6, 31, 24, .62);
  backdrop-filter: blur(5px);
}
.modal-unique.is-open {
  display: flex !important;
  opacity: 1 !important;
  visibility: visible !important;
}
.modal-unique.is-open .modal-dialog { transform: none !important; }
body.about-gallery-modal-open { overflow: hidden; }
</style>


<section class="dashboard-content">
  <?php if ($showAboutInlineAddButton): ?>
  <button class="addnewbutton" id="addNewBtn">Add New Gallery Item</button>
  <?php endif; ?>

  <div id="galleryContainerUnique">
    <?php foreach($galleryItems as $galleryIndex => $item): ?>
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
                <button type="button" class="btn-edit-unique" data-bs-toggle="modal" data-bs-target="#editModalUnique<?= $item['id'] ?>">Edit</button>
            </div>
        </div>

        <!-- Modal -->
        <div class="modal fade modal-unique" id="editModalUnique<?= $item['id'] ?>" tabindex="-1">
          <div class="modal-dialog modal-dialog-centered modal-lg about-gallery-dialog">
            <div class="modal-content about-gallery-modal">
              <div class="modal-header about-gallery-modal-header">
                <div class="about-gallery-modal-heading">
                  <span class="about-gallery-modal-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M4 5.5A1.5 1.5 0 0 1 5.5 4h13A1.5 1.5 0 0 1 20 5.5v13a1.5 1.5 0 0 1-1.5 1.5h-13A1.5 1.5 0 0 1 4 18.5v-13Z"/><circle cx="9" cy="9" r="2"/><path d="m5 17 4.5-4.5 3 3 2-2L20 19"/></svg></span>
                  <div><small>ABOUT PAGE GALLERY</small><h5 class="modal-title">Edit Gallery Item</h5><p>Update the image and public information shown on the About page.</p></div>
                </div>
                <button type="button" class="close-btn" data-bs-dismiss="modal" aria-label="Close editor">&times;</button>
              </div>

              <div class="about-gallery-modal-body">
                <section class="about-gallery-image-section" aria-label="Gallery image">
                  <div class="about-gallery-section-head"><span>Gallery image</span><small>Click the image to replace it</small></div>
                  <label class="custum-file-upload-unique" id="dragAreaUnique<?= $item['id'] ?>">
                      <img id="uploadPreviewUnique<?= $item['id'] ?>" class="gallery-upload-preview" src="<?= htmlspecialchars($item['image_path']) ?>" alt="Current image for <?= htmlspecialchars($item['title']) ?>">
                      <div class="icon-unique">
                          <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24">
                              <path fill="" d="M10 1C9.73478 1 9.48043 1.10536 9.29289 1.29289L3.29289 7.29289C3.10536 7.48043 3 7.73478 3 8V20C3 21.6569 4.34315 23 6 23H7C7.55228 23 8 22.5523 8 22C8 21.4477 7.55228 21 7 21H6C5.44772 21 5 20.5523 5 20V9H10C10.5523 9 11 8.55228 11 8V3H18C18.5523 3 19 3.44772 19 4V9C19 9.55228 19.4477 10 20 10C20.5523 10 21 9.55228 21 9V4C21 2.34315 19.6569 1 18 1H10ZM9 7H6.41421L9 4.41421V7ZM14 15.5C14 14.1193 15.1193 13 16.5 13C17.8807 13 19 14.1193 19 15.5V16V17H20C21.1046 17 22 17.8954 22 19C22 20.1046 21.1046 21 20 21H13C11.8954 21 11 20.1046 11 19C11 17.8954 11.8954 17 13 17H14V16V15.5ZM16.5 11C14.142 11 12.2076 12.8136 12.0156 15.122C10.2825 15.5606 9 17.1305 9 19C9 21.2091 10.7909 23 13 23H20C22.2091 23 24 21.2091 24 19C24 17.1305 22.7175 15.5606 20.9844 15.122C20.7924 12.8136 18.858 11 16.5 11Z"></path>
                          </svg>
                      </div>
                      <div class="text-unique"><span>Click to upload image</span></div>
                      <input type="file" id="fileInputUnique<?= $item['id'] ?>" accept="image/png,image/jpeg,image/webp">
                  </label>
                  <p class="about-gallery-upload-note">Recommended landscape image. PNG, JPG, or WEBP.</p>

                  <div class="about-gallery-cropper" id="cropperContainerUnique<?= $item['id'] ?>" style="display:none">
                    <img id="cropperImageUnique<?= $item['id'] ?>" class="img-fluid">
                    <div class="about-gallery-crop-actions">
                      <button type="button" class="btn-save-unique" id="cropDoneUnique<?= $item['id'] ?>">Done</button>
                      <button type="button" class="btn-cancel-unique" id="cropCancelUnique<?= $item['id'] ?>">Cancel</button>
                    </div>
                  </div>
                </section>
                <section class="modal-form-unique about-gallery-form-section" aria-label="Gallery information">
                  <div class="about-gallery-section-head"><span>Gallery information</span><small>Fields marked required must be completed</small></div>
                  <label for="modalTitleUnique<?= $item['id'] ?>">Title <b aria-hidden="true">*</b></label>
                  <input type="text" id="modalTitleUnique<?= $item['id'] ?>" class="form-control" value="<?= htmlspecialchars($item['title']) ?>" placeholder="Enter a clear destination title" required>
                  <label for="modalShortUnique<?= $item['id'] ?>">Short description</label>
                  <textarea rows="2" id="modalShortUnique<?= $item['id'] ?>" class="form-control" placeholder="Write a brief summary for the gallery card"><?= htmlspecialchars($item['short_desc']) ?></textarea>
                  <label for="modalLongUnique<?= $item['id'] ?>">Full description</label>
                  <textarea rows="4" id="modalLongUnique<?= $item['id'] ?>" class="form-control" placeholder="Provide useful details visitors should know"><?= htmlspecialchars($item['long_desc']) ?></textarea>
                </section>
              </div>

              <div class="about-gallery-modal-footer">
                <p>Changes will appear on the public About page after saving.</p>
                <div>
                <button type="button" class="btn-cancel-unique" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn-save-unique" onclick="saveModalChangesUnique(<?= $item['id'] ?>, this)">Save Changes</button>
                </div>
              </div>
            </div>
          </div>
        </div>

        <?php if ($galleryIndex === 0): ?>
        <!-- New Gallery Item Modal (rendered once, outside duplicate item state) -->
        <div class="modal fade modal-unique" id="addModalUnique" tabindex="-1">
          <div class="modal-dialog modal-dialog-centered modal-lg about-gallery-dialog">
            <div class="modal-content about-gallery-modal">
              <div class="modal-header about-gallery-modal-header">
                <div class="about-gallery-modal-heading">
                  <span class="about-gallery-modal-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M4 5.5A1.5 1.5 0 0 1 5.5 4h13A1.5 1.5 0 0 1 20 5.5v13a1.5 1.5 0 0 1-1.5 1.5h-13A1.5 1.5 0 0 1 4 18.5v-13Z"/><circle cx="9" cy="9" r="2"/><path d="m5 17 4.5-4.5 3 3 2-2L20 19"/><path d="M16 2v5M13.5 4.5h5"/></svg></span>
                  <div><small>ABOUT PAGE GALLERY</small><h5 class="modal-title">Add Gallery Item</h5><p>Create a new destination highlight for the public About page.</p></div>
                </div>
                <button type="button" class="close-btn" data-bs-dismiss="modal" aria-label="Close editor">&times;</button>
              </div>

              <div class="about-gallery-modal-body">
                <section class="about-gallery-image-section" aria-label="Gallery image">
                  <div class="about-gallery-section-head"><span>Gallery image</span><small>Optional, but recommended</small></div>
                  <label class="custum-file-upload-unique" id="dragAreaNew">
                      <img id="uploadPreviewNew" class="gallery-upload-preview" alt="Selected image preview" hidden>
                      <div class="icon-unique">
                          <!-- same SVG icon -->
                          <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24">
                              <path fill="" d="M10 1C9.73478 1 9.48043 1.10536 9.29289 1.29289L3.29289 7.29289C3.10536 7.48043 3 7.73478 3 8V20C3 21.6569 4.34315 23 6 23H7C7.55228 23 8 22.5523 8 22C8 21.4477 7.55228 21 7 21H6C5.44772 21 5 20.5523 5 20V9H10C10.5523 9 11 8.55228 11 8V3H18C18.5523 3 19 3.44772 19 4V9C19 9.55228 19.4477 10 20 10C20.5523 10 21 9.55228 21 9V4C21 2.34315 19.6569 1 18 1H10ZM9 7H6.41421L9 4.41421V7ZM14 15.5C14 14.1193 15.1193 13 16.5 13C17.8807 13 19 14.1193 19 15.5V16V17H20C21.1046 17 22 17.8954 22 19C22 20.1046 21.1046 21 20 21H13C11.8954 21 11 20.1046 11 19C11 17.8954 11.8954 17 13 17H14V16V15.5ZM16.5 11C14.142 11 12.2076 12.8136 12.0156 15.122C10.2825 15.5606 9 17.1305 9 19C9 21.2091 10.7909 23 13 23H20C22.2091 23 24 21.2091 24 19C24 17.1305 22.7175 15.5606 20.9844 15.122C20.7924 12.8136 18.858 11 16.5 11Z"></path>
                          </svg>
                      </div>
                      <div class="text-unique"><span>Choose or drop an image</span><small>Browse files from your device</small></div>
                      <input type="file" id="fileInputNew" accept="image/png,image/jpeg,image/webp">
                  </label>
                  <p class="about-gallery-upload-note">Recommended landscape image. PNG, JPG, or WEBP.</p>

                  <div class="about-gallery-cropper" id="cropperContainerNew" style="display:none">
                    <img id="cropperImageNew" class="img-fluid">
                    <div class="about-gallery-crop-actions">
                      <button type="button" class="btn-save-unique" id="cropDoneNew">Done</button>
                      <button type="button" class="btn-cancel-unique" id="cropCancelNew">Cancel</button>
                    </div>
                  </div>
                </section>

                <section class="modal-form-unique about-gallery-form-section" aria-label="Gallery information">
                  <div class="about-gallery-section-head"><span>Gallery information</span><small>Fields marked required must be completed</small></div>
                  <label for="modalTitleNew">Title <b aria-hidden="true">*</b></label>
                  <input type="text" id="modalTitleNew" class="form-control" placeholder="Enter a clear destination title" required>
                  <label for="modalShortNew">Short description</label>
                  <textarea rows="2" id="modalShortNew" class="form-control" placeholder="Write a brief summary for the gallery card"></textarea>
                  <label for="modalLongNew">Full description</label>
                  <textarea rows="4" id="modalLongNew" class="form-control" placeholder="Provide useful details visitors should know"></textarea>
                </section>
              </div>

              <div class="about-gallery-modal-footer">
                <p>The new item will appear on the public About page after saving.</p>
                <div>
                <button type="button" class="btn-cancel-unique" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn-save-unique" id="saveNewBtn">Add Gallery Item</button>
                </div>
              </div>
            </div>
          </div>
        </div>
        <?php endif; ?>

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
      fileInputUnique<?= $item['id'] ?>.addEventListener('change', e=>{
        if(e.target.files.length){
          const file = e.target.files[0];
          if (!file.type.startsWith('image/')) {
            e.target.value = '';
            Swal.fire({ icon: 'warning', title: 'Invalid file', text: 'Please choose a PNG, JPG, or WEBP image.', confirmButtonColor: '#2b7a66' });
            return;
          }
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
        if (!cropperUnique<?= $item['id'] ?>) return;
        const canvas = cropperUnique<?= $item['id'] ?>.getCroppedCanvas({
            width: 300,
            height: 200
        });
        const preview = document.getElementById('uploadPreviewUnique<?= $item['id'] ?>');
        preview.src = canvas.toDataURL('image/png');
        preview.hidden = false;
        dragAreaUnique<?= $item['id'] ?>.querySelectorAll('.icon-unique, .text-unique').forEach(element => element.style.display = 'none');
        dragAreaUnique<?= $item['id'] ?>.style.display = 'flex';
        cropperContainerUnique<?= $item['id'] ?>.style.display = 'none';
        cropperUnique<?= $item['id'] ?>.destroy();
        cropperUnique<?= $item['id'] ?> = null;
      });

      /* ===========================
        CROP CANCEL
        =========================== */
      document.getElementById('cropCancelUnique<?= $item['id'] ?>').addEventListener('click', ()=>{
        cropperContainerUnique<?= $item['id'] ?>.style.display = 'none';
        dragAreaUnique<?= $item['id'] ?>.style.display = 'flex';
        if (cropperUnique<?= $item['id'] ?>) cropperUnique<?= $item['id'] ?>.destroy();
        cropperUnique<?= $item['id'] ?> = null;
      });
      /* ===========================
        SAVE CHANGES (Edit)
      =========================== */
      function saveModalChangesUnique(id, submitButton){
        const title = document.getElementById(`modalTitleUnique${id}`).value.trim();
        if (!title) {
          Swal.fire({ icon: 'warning', title: 'Title required', text: 'Enter a gallery title before saving.', confirmButtonColor: '#2b7a66' });
          return;
        }
        if (submitButton) submitButton.disabled = true;
        const formData = new FormData();
        formData.append('csrf_token', <?= json_encode($adminContentCsrf, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>);
        formData.append('action','update');
        formData.append('id', id);
        formData.append('title', title);
        formData.append('short_desc', document.getElementById(`modalShortUnique${id}`).value);
        formData.append('long_desc', document.getElementById(`modalLongUnique${id}`).value);

        const dragArea = document.getElementById(`dragAreaUnique${id}`);
        const imgTag = document.getElementById(`uploadPreviewUnique${id}`);

        if(imgTag && !imgTag.hidden && imgTag.src.startsWith('data:')){
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
              if (submitButton) submitButton.disabled = false;
            });
        } else {
          sendUpdate(formData);
        }

        function sendUpdate(fd){
          fetch('adabout.php',{method:'POST',body:fd})
            .then(async res => {
              const payload = await res.json().catch(() => null);
              if (!res.ok || !payload) throw new Error(payload?.message || 'The server returned an invalid response.');
              return payload;
            })
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
                text: err.message || 'An unexpected error occurred.',
                confirmButtonColor: '#d33'
              });
            })
            .finally(() => { if (submitButton) submitButton.disabled = false; });
        }
      }

      </script>

    <?php endforeach; ?>
  </div>
</section>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>

// Keep About dialogs isolated from the tab panels and independent of CDN timing.
function mountAboutGalleryModals() {
  document.querySelectorAll('.modal-unique').forEach(modalElement => {
    if (modalElement.parentElement !== document.body) document.body.appendChild(modalElement);
  });
}

function openAboutGalleryModal(modalElement) {
  if (!modalElement) return;
  mountAboutGalleryModals();
  modalElement.classList.add('is-open', 'show');
  modalElement.style.setProperty('display', 'flex', 'important');
  modalElement.setAttribute('aria-modal', 'true');
  modalElement.removeAttribute('aria-hidden');
  document.body.classList.add('about-gallery-modal-open');
  modalElement.querySelector('input, textarea, button')?.focus();
}

function closeAboutGalleryModal(modalElement) {
  if (!modalElement) return;
  modalElement.classList.remove('is-open', 'show');
  modalElement.style.setProperty('display', 'none', 'important');
  modalElement.setAttribute('aria-hidden', 'true');
  if (!document.querySelector('.modal-unique.is-open')) {
    document.body.classList.remove('about-gallery-modal-open');
  }
}

function initializeAboutGalleryModals() {
  mountAboutGalleryModals();
  document.querySelectorAll('[data-bs-target^="#editModalUnique"]').forEach(button => {
    if (button.dataset.aboutModalBound === 'true') return;
    button.dataset.aboutModalBound = 'true';
    button.addEventListener('click', event => {
      event.preventDefault();
      event.stopPropagation();
      openAboutGalleryModal(document.querySelector(button.getAttribute('data-bs-target')));
    });
  });
  document.querySelectorAll('.modal-unique [data-bs-dismiss="modal"]').forEach(button => {
    if (button.dataset.aboutModalBound === 'true') return;
    button.dataset.aboutModalBound = 'true';
    button.addEventListener('click', event => {
      event.preventDefault();
      event.stopPropagation();
      closeAboutGalleryModal(button.closest('.modal-unique'));
    });
  });
  document.querySelectorAll('.modal-unique').forEach(modalElement => {
    if (modalElement.dataset.aboutBackdropBound === 'true') return;
    modalElement.dataset.aboutBackdropBound = 'true';
    modalElement.addEventListener('click', event => {
      if (event.target === modalElement) closeAboutGalleryModal(modalElement);
    });
  });
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', initializeAboutGalleryModals, { once: true });
} else {
  initializeAboutGalleryModals();
}

document.addEventListener('keydown', event => {
  if (event.key === 'Escape') closeAboutGalleryModal(document.querySelector('.modal-unique.is-open'));
});

const dragAreaNew = document.getElementById('dragAreaNew');
const fileInputNew = document.getElementById('fileInputNew');
const cropperContainerNew = document.getElementById('cropperContainerNew');
const cropperImageNew = document.getElementById('cropperImageNew');
let cropperNew;

// ===========================
// Handle File Selection
// ===========================
function handleNewFile(file) {
  if (!file || !file.type.startsWith('image/')) {
    Swal.fire({ icon: 'warning', title: 'Invalid file', text: 'Please choose a PNG, JPG, or WEBP image.', confirmButtonColor: '#2b7a66' });
    return;
  }
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
function openNewAboutGalleryItemModal() {
  const addModal = document.getElementById('addModalUnique');
  const titleField = document.getElementById('modalTitleNew');
  const shortField = document.getElementById('modalShortNew');
  const longField = document.getElementById('modalLongNew');
  const newPreview = document.getElementById('uploadPreviewNew');
  if (!addModal || !titleField || !shortField || !longField || !newPreview) {
    Swal.fire({ icon: 'info', title: 'Gallery unavailable', text: 'Reload the page and try adding the gallery item again.', confirmButtonColor: '#2b7a66' });
    return;
  }
  // Reset form
  titleField.value = '';
  shortField.value = '';
  longField.value = '';

  // Reset drag area
  newPreview.src = '';
  newPreview.hidden = true;
  if (dragAreaNew) {
    dragAreaNew.style.display = 'flex';
    dragAreaNew.querySelectorAll('.icon-unique, .text-unique').forEach(element => element.style.display = 'flex');
  }
  if (fileInputNew) fileInputNew.value = '';
  if (cropperContainerNew) cropperContainerNew.style.display = 'none';
  if (cropperNew) cropperNew.destroy();
  cropperNew = null;

  // Show modal
  openAboutGalleryModal(addModal);
}

document.getElementById('addNewBtn')?.addEventListener('click', event => {
  event.preventDefault();
  openNewAboutGalleryItemModal();
});

// ===========================
// Crop Done / Cancel
// ===========================
document.getElementById('cropDoneNew')?.addEventListener('click', () => {
  if (!cropperNew) return;
  const canvas = cropperNew.getCroppedCanvas({ width: 300, height: 200 });

  // Show cropped image in drag area
  const preview = document.getElementById('uploadPreviewNew');
  preview.src = canvas.toDataURL('image/png');
  preview.hidden = false;
  dragAreaNew.querySelectorAll('.icon-unique, .text-unique').forEach(element => element.style.display = 'none');
  dragAreaNew.style.display = 'flex';
  cropperContainerNew.style.display = 'none';
  if (cropperNew) cropperNew.destroy();
  cropperNew = null;
});

document.getElementById('cropCancelNew')?.addEventListener('click', () => {
  cropperContainerNew.style.display = 'none';
  dragAreaNew.style.display = 'flex';
  dragAreaNew.querySelectorAll('.icon-unique, .text-unique').forEach(element => element.style.display = 'flex');
  if (cropperNew) cropperNew.destroy();
  cropperNew = null;
});


/* ===========================
  SAVE NEW GALLERY ITEM (Add)
=========================== */
document.getElementById('saveNewBtn')?.addEventListener('click', () => {
  const submitButton = document.getElementById('saveNewBtn');
  const title = document.getElementById('modalTitleNew').value.trim();
  if (!title) {
    Swal.fire({ icon: 'warning', title: 'Title required', text: 'Enter a gallery title before adding the item.', confirmButtonColor: '#2b7a66' });
    return;
  }
  submitButton.disabled = true;
  const formData = new FormData();
  formData.append('csrf_token', <?= json_encode($adminContentCsrf, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>);
  formData.append('action', 'add');
  formData.append('title', title);
  formData.append('short_desc', document.getElementById('modalShortNew').value);
  formData.append('long_desc', document.getElementById('modalLongNew').value);

  const imgTag = document.getElementById('uploadPreviewNew');
  if (imgTag && !imgTag.hidden && imgTag.src.startsWith('data:')) {
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
        submitButton.disabled = false;
      });
  } else {
    sendAdd(formData);
  }

  function sendAdd(fd) {
    fetch('adabout.php', { method: 'POST', body: fd })
      .then(async res => {
        const payload = await res.json().catch(() => null);
        if (!res.ok || !payload) throw new Error(payload?.message || 'The server returned an invalid response.');
        return payload;
      })
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
          text: err.message || 'An unexpected error occurred.',
          confirmButtonColor: '#d33'
        });
      })
      .finally(() => { submitButton.disabled = false; });
  }
});

// ===========================
// Initialize Drag & Drop
// ===========================
if (dragAreaNew && fileInputNew && cropperContainerNew && cropperImageNew) initDragDropNew();


// Prevent back button caching
<?php if ($showAboutInlineAddButton): ?>
window.addEventListener('pageshow', e => { if(e.persisted) location.reload(); });
<?php endif; ?>
</script>
