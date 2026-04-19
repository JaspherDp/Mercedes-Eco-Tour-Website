<?php
require_once __DIR__ . '/Ho_common.php';

$hoAdmin = HoRequireHotelAdmin($pdo);
$hoHotelResortId = (int)$hoAdmin['hotel_resort_id'];
$hoPropertyName = trim((string)($hoAdmin['property_name'] ?? ''));

$hoActive = 'contents';
$hoTitle = 'Property Contents';
$hoOwnerName = $hoPropertyName !== '' ? $hoPropertyName . ' Admin' : (string)$hoAdmin['username'];
$hoUnreadBadge = HoGetUnreadCount($pdo, $hoHotelResortId);
$hoNotifItems = HoGetNotificationItems($pdo, 8, $hoHotelResortId);
$hoPendingBadge = HoGetPendingCount($pdo, $hoHotelResortId);

function HoEnsureContentUploadDirectory(): array
{
    $relativeDir = 'uploads/hotel_contents';
    $absoluteDir = __DIR__ . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'hotel_contents';
    if (!is_dir($absoluteDir)) {
        mkdir($absoluteDir, 0777, true);
    }
    return [$absoluteDir, $relativeDir];
}

function HoSaveUploadedContentImage(?array $file, string $absoluteDir, string $relativeDir): ?string
{
    if (!$file || !isset($file['error']) || (int)$file['error'] !== UPLOAD_ERR_OK) {
        return null;
    }
    if (empty($file['tmp_name']) || !is_uploaded_file((string)$file['tmp_name'])) {
        return null;
    }

    $mime = (string)(mime_content_type((string)$file['tmp_name']) ?: '');
    $allowed = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
    ];
    if (!isset($allowed[$mime])) {
        return null;
    }

    $filename = 'content_' . date('YmdHis') . '_' . bin2hex(random_bytes(5)) . '.' . $allowed[$mime];
    $targetAbs = $absoluteDir . DIRECTORY_SEPARATOR . $filename;
    if (!move_uploaded_file((string)$file['tmp_name'], $targetAbs)) {
        return null;
    }
    return $relativeDir . '/' . $filename;
}

function HoSaveUploadedContentImagesIndexed(?array $files, string $absoluteDir, string $relativeDir): array
{
    if (
        !$files ||
        !isset($files['name'], $files['tmp_name'], $files['error']) ||
        !is_array($files['name']) ||
        !is_array($files['tmp_name']) ||
        !is_array($files['error'])
    ) {
        return [];
    }

    $saved = [];
    $count = count($files['name']);
    for ($i = 0; $i < $count; $i++) {
        $entry = [
            'name' => $files['name'][$i] ?? '',
            'type' => $files['type'][$i] ?? '',
            'tmp_name' => $files['tmp_name'][$i] ?? '',
            'error' => $files['error'][$i] ?? UPLOAD_ERR_NO_FILE,
            'size' => $files['size'][$i] ?? 0,
        ];
        $path = HoSaveUploadedContentImage($entry, $absoluteDir, $relativeDir);
        if ($path) {
            $saved[$i] = $path;
        }
    }
    return $saved;
}

if (isset($_POST['ho_action']) && $_POST['ho_action'] === 'mark_notifications_read') {
    HoMarkNotificationsRead($pdo, $hoHotelResortId);
    header('Content-Type: application/json');
    echo json_encode(['ok' => true]);
    exit;
}

$flash = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['content_action'])) {
    [$uploadAbsDir, $uploadRelDir] = HoEnsureContentUploadDirectory();
    $action = trim((string)$_POST['content_action']);

    $toList = static function (string $raw): array {
        if ($raw === '') return [];
        $lines = preg_split('/\r\n|\r|\n/', $raw);
        $items = [];
        foreach ($lines as $line) {
            $line = trim((string)$line);
            if ($line !== '') $items[] = $line;
        }
        return array_values(array_unique($items));
    };

    $existingStmt = $pdo->prepare("
        SELECT image_path, amenities_json, description_text, rules_json, gallery_images_json
        FROM hotel_resorts
        WHERE hotel_resort_id = ?
        LIMIT 1
    ");
    $existingStmt->execute([$hoHotelResortId]);
    $existing = $existingStmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $descriptionText = trim((string)($existing['description_text'] ?? ''));
    $amenities = HoDecodeJsonList((string)($existing['amenities_json'] ?? '[]'));
    $rules = HoDecodeJsonList((string)($existing['rules_json'] ?? '[]'));
    $gallery = HoDecodeJsonList((string)($existing['gallery_images_json'] ?? '[]'));
    $coverImagePath = trim((string)($existing['image_path'] ?? ''));
    if ($coverImagePath === '') {
        $coverImagePath = 'img/sampleimage.png';
    }

    if ($action === 'save_description') {
        $descriptionText = trim((string)($_POST['description_text'] ?? ''));
    } elseif ($action === 'save_facilities') {
        $amenitiesRaw = trim((string)($_POST['amenities_text'] ?? ''));
        $amenities = $toList($amenitiesRaw);
    } elseif ($action === 'save_rules') {
        $rulesRaw = trim((string)($_POST['rules_text'] ?? ''));
        $rules = $toList($rulesRaw);
    } elseif ($action === 'save_main_image') {
        $coverImagePath = trim((string)($_POST['image_path'] ?? $coverImagePath));
        $uploadedMainImage = HoSaveUploadedContentImage($_FILES['main_image_file'] ?? null, $uploadAbsDir, $uploadRelDir);
        if ($uploadedMainImage) {
            $coverImagePath = $uploadedMainImage;
        }
        if ($coverImagePath === '') {
            $coverImagePath = 'img/sampleimage.png';
        }
    } elseif ($action === 'save_gallery') {
        $galleryPathsPosted = isset($_POST['gallery_paths']) && is_array($_POST['gallery_paths']) ? $_POST['gallery_paths'] : [];
        $uploadedGalleryRows = HoSaveUploadedContentImagesIndexed($_FILES['gallery_row_files'] ?? null, $uploadAbsDir, $uploadRelDir);
        $gallery = [];
        $rowCount = max(count($galleryPathsPosted), !empty($uploadedGalleryRows) ? (max(array_keys($uploadedGalleryRows)) + 1) : 0);
        for ($idx = 0; $idx < $rowCount; $idx++) {
            $path = trim((string)($galleryPathsPosted[$idx] ?? ''));
            if (isset($uploadedGalleryRows[$idx]) && trim((string)$uploadedGalleryRows[$idx]) !== '') {
                $path = trim((string)$uploadedGalleryRows[$idx]);
            }
            if ($path !== '') {
                $gallery[] = $path;
            }
        }
        $gallery = array_values(array_unique($gallery));
    } else {
        $error = 'Invalid update action.';
    }

    if ($error === '') {
        try {
            $update = $pdo->prepare("
                UPDATE hotel_resorts
                SET image_path = ?, amenities_json = ?, description_text = ?, rules_json = ?, gallery_images_json = ?, updated_at = NOW()
                WHERE hotel_resort_id = ?
                LIMIT 1
            ");
            $update->execute([
                $coverImagePath,
                json_encode($amenities, JSON_UNESCAPED_UNICODE),
                $descriptionText,
                json_encode($rules, JSON_UNESCAPED_UNICODE),
                json_encode($gallery, JSON_UNESCAPED_UNICODE),
                $hoHotelResortId
            ]);
            $flash = 'Property content updated successfully.';
        } catch (Throwable $e) {
            $error = 'Failed to update property content. Please try again.';
        }
    }
}

$stmt = $pdo->prepare("
    SELECT hotel_resort_id, name, island, type, image_path, amenities_json, description_text, rules_json, gallery_images_json
    FROM hotel_resorts
    WHERE hotel_resort_id = ?
    LIMIT 1
");
$stmt->execute([$hoHotelResortId]);
$property = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

$propertyName = trim((string)($property['name'] ?? $hoPropertyName));
$propertyIsland = trim((string)($property['island'] ?? ''));
$propertyType = strtoupper(trim((string)($property['type'] ?? '')));
$coverImagePath = trim((string)($property['image_path'] ?? ''));
if ($coverImagePath === '') $coverImagePath = 'img/sampleimage.png';

$descriptionText = trim((string)($property['description_text'] ?? ''));
$amenities = HoDecodeJsonList((string)($property['amenities_json'] ?? '[]'));
$rules = HoDecodeJsonList((string)($property['rules_json'] ?? '[]'));
$galleryImages = HoDecodeJsonList((string)($property['gallery_images_json'] ?? '[]'));

if ($descriptionText === '') {
    $descriptionText = sprintf(
        "%s is a welcoming %s in %s, Mercedes, designed for guests who want a comfortable and relaxing stay.\n\nThe property offers convenient access to nearby coastal attractions and local dining spots, making it ideal for both short vacations and longer getaways.\n\nEach room is prepared with essential comforts, and shared spaces are arranged to support a calm and enjoyable island experience throughout your visit.\n\nWhether you are traveling as a couple, with family, or with friends, this stay provides a balanced mix of comfort, accessibility, and local charm.",
        $propertyName !== '' ? $propertyName : 'This property',
        strtolower($propertyType !== '' ? $propertyType : 'hotel/resort'),
        $propertyIsland !== '' ? $propertyIsland : 'Mercedes'
    );
}
if (empty($rules)) {
    $rules = [
        'Check-in starts at 2:00 PM.',
        'Check-out is until 12:00 PM.',
        'At least 1-night stay is required.'
    ];
}

if (trim((string)($property['description_text'] ?? '')) === '' || trim((string)($property['rules_json'] ?? '')) === '') {
    try {
        $seedStmt = $pdo->prepare("
            UPDATE hotel_resorts
            SET description_text = ?, rules_json = ?, updated_at = NOW()
            WHERE hotel_resort_id = ?
            LIMIT 1
        ");
        $seedStmt->execute([
            $descriptionText,
            json_encode($rules, JSON_UNESCAPED_UNICODE),
            $hoHotelResortId
        ]);
    } catch (Throwable $e) {
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Hocontents | Hotel Owner Contents</title>
  <link rel="icon" type="image/png" href="img/newlogo.png" />
  <link rel="stylesheet" href="styles/Ho_panel.css" />
</head>
<body class="ho-body">
  <div class="ho-layout">
    <?php include __DIR__ . '/Ho_sidebar.php'; ?>

    <main class="ho-main">
      <?php include __DIR__ . '/Ho_header.php'; ?>

      <section class="ho-content">
        <article class="ho-card ho-table-card">
          <div class="ho-table-head">
            <h2 class="ho-section-title">Property Contents</h2>
          </div>

          <?php if ($flash !== ''): ?>
            <div class="ho-banner success"><?= htmlspecialchars($flash) ?></div>
          <?php endif; ?>
          <?php if ($error !== ''): ?>
            <div class="ho-banner error"><?= htmlspecialchars($error) ?></div>
          <?php endif; ?>

          <div class="ho-content-summary">
            <div class="ho-content-summary-image">
              <img src="<?= htmlspecialchars($coverImagePath) ?>" alt="<?= htmlspecialchars($propertyName) ?>" />
            </div>
            <div class="ho-content-summary-meta">
              <h3><?= htmlspecialchars($propertyName !== '' ? $propertyName : 'Assigned Property') ?></h3>
              <p><?= htmlspecialchars($propertyIsland !== '' ? $propertyIsland : 'Island not set') ?> • <?= htmlspecialchars($propertyType !== '' ? $propertyType : 'TYPE') ?></p>
              <small>Update your property details shown to tourists.</small>
            </div>
          </div>

          <div class="ho-content-cards">
            <article class="ho-content-current">
              <div class="ho-content-card-head">
                <h4>Description</h4>
                <button type="button" class="ho-btn" data-open-modal="hoDescriptionModal">Edit</button>
              </div>
              <?php if ($descriptionText !== ''): ?>
                <p><?= nl2br(htmlspecialchars($descriptionText)) ?></p>
              <?php else: ?>
                <p class="muted">No description yet.</p>
              <?php endif; ?>
            </article>

            <article class="ho-content-current">
              <div class="ho-content-card-head">
                <h4>Facilities</h4>
                <button type="button" class="ho-btn" data-open-modal="hoFacilitiesModal">Edit</button>
              </div>
              <?php if (!empty($amenities)): ?>
                <div class="ho-content-pill-list">
                  <?php foreach ($amenities as $facility): ?>
                    <span><?= htmlspecialchars($facility) ?></span>
                  <?php endforeach; ?>
                </div>
              <?php else: ?>
                <p class="muted">No facilities yet.</p>
              <?php endif; ?>
            </article>

            <article class="ho-content-current">
              <div class="ho-content-card-head">
                <h4>Rules</h4>
                <button type="button" class="ho-btn" data-open-modal="hoRulesModal">Edit</button>
              </div>
              <?php if (!empty($rules)): ?>
                <ul class="ho-content-rule-list">
                  <?php foreach ($rules as $rule): ?>
                    <li><?= htmlspecialchars($rule) ?></li>
                  <?php endforeach; ?>
                </ul>
              <?php else: ?>
                <p class="muted">No rules yet.</p>
              <?php endif; ?>
            </article>

            <article class="ho-content-current">
              <div class="ho-content-card-head">
                <h4>Main Image</h4>
                <button type="button" class="ho-btn" data-open-modal="hoMainImageModal">Edit</button>
              </div>
              <div class="ho-content-main-image">
                <img src="<?= htmlspecialchars($coverImagePath) ?>" alt="Main image" />
              </div>
              <p class="muted"><?= htmlspecialchars($coverImagePath) ?></p>
            </article>

            <article class="ho-content-current">
              <div class="ho-content-card-head">
                <h4>Gallery Images</h4>
                <button type="button" class="ho-btn" data-open-modal="hoGalleryModal">Edit</button>
              </div>
              <?php if (!empty($galleryImages)): ?>
                <div class="ho-content-gallery-preview">
                  <?php foreach (array_slice($galleryImages, 0, 8) as $img): ?>
                    <img src="<?= htmlspecialchars((string)$img) ?>" alt="Gallery preview" />
                  <?php endforeach; ?>
                </div>
              <?php else: ?>
                <p class="muted">No gallery images yet.</p>
              <?php endif; ?>
            </article>
          </div>
        </article>
      </section>

      <?php include __DIR__ . '/Ho_footer.php'; ?>
    </main>
  </div>

  <div class="ho-modal" id="hoDescriptionModal" aria-hidden="true">
    <div class="ho-modal-card ho-room-modal-card">
      <div class="ho-modal-head">
        <h3>Edit Description</h3>
        <button type="button" class="ho-close" data-close-modal>&times;</button>
      </div>
      <form method="post" class="ho-room-form">
        <input type="hidden" name="content_action" value="save_description" />
        <label>Property Description
          <textarea name="description_text" rows="9" placeholder="Write a short but clear description of your hotel/resort..."><?= htmlspecialchars($descriptionText) ?></textarea>
        </label>
        <div class="ho-room-actions">
          <button type="button" class="ho-btn cancel" data-close-modal>Cancel</button>
          <button type="submit" class="ho-btn confirm">Save Description</button>
        </div>
      </form>
    </div>
  </div>

  <div class="ho-modal" id="hoFacilitiesModal" aria-hidden="true">
    <div class="ho-modal-card ho-room-modal-card">
      <div class="ho-modal-head">
        <h3>Edit Facilities</h3>
        <button type="button" class="ho-close" data-close-modal>&times;</button>
      </div>
      <form method="post" class="ho-room-form">
        <input type="hidden" name="content_action" value="save_facilities" />
        <label>Facilities / Amenities (one per line)
          <textarea name="amenities_text" rows="9" placeholder="Pool&#10;Beach Access&#10;WiFi"><?= htmlspecialchars(implode("\n", $amenities)) ?></textarea>
        </label>
        <div class="ho-room-actions">
          <button type="button" class="ho-btn cancel" data-close-modal>Cancel</button>
          <button type="submit" class="ho-btn confirm">Save Facilities</button>
        </div>
      </form>
    </div>
  </div>

  <div class="ho-modal" id="hoRulesModal" aria-hidden="true">
    <div class="ho-modal-card ho-room-modal-card">
      <div class="ho-modal-head">
        <h3>Edit Rules</h3>
        <button type="button" class="ho-close" data-close-modal>&times;</button>
      </div>
      <form method="post" class="ho-room-form">
        <input type="hidden" name="content_action" value="save_rules" />
        <label>Rules (one per line)
          <textarea name="rules_text" rows="9" placeholder="Check-in starts at 2:00 PM&#10;Check-out is until 12:00 PM"><?= htmlspecialchars(implode("\n", $rules)) ?></textarea>
        </label>
        <div class="ho-room-actions">
          <button type="button" class="ho-btn cancel" data-close-modal>Cancel</button>
          <button type="submit" class="ho-btn confirm">Save Rules</button>
        </div>
      </form>
    </div>
  </div>

  <div class="ho-modal" id="hoMainImageModal" aria-hidden="true">
    <div class="ho-modal-card ho-room-modal-card">
      <div class="ho-modal-head">
        <h3>Edit Main Image</h3>
        <button type="button" class="ho-close" data-close-modal>&times;</button>
      </div>
      <form method="post" class="ho-room-form" enctype="multipart/form-data">
        <input type="hidden" name="content_action" value="save_main_image" />
        <div class="ho-image-editor-section">
          <h4>Main Image</h4>
          <div class="ho-main-image-row">
            <div class="ho-main-image-preview">
              <img src="<?= htmlspecialchars($coverImagePath) ?>" alt="Main image preview" />
            </div>
            <div class="ho-main-image-fields">
              <label>Main Image Path (big photo)
                <input type="text" name="image_path" value="<?= htmlspecialchars($coverImagePath) ?>" placeholder="img/sampleimage.png or full URL" />
              </label>
              <label>Upload New Main Image
                <input type="file" name="main_image_file" accept="image/*" />
              </label>
            </div>
          </div>
        </div>
        <div class="ho-room-actions">
          <button type="button" class="ho-btn cancel" data-close-modal>Cancel</button>
          <button type="submit" class="ho-btn confirm">Save Main Image</button>
        </div>
      </form>
    </div>
  </div>

  <div class="ho-modal" id="hoGalleryModal" aria-hidden="true">
    <div class="ho-modal-card ho-room-modal-card">
      <div class="ho-modal-head">
        <h3>Edit Gallery Images</h3>
        <button type="button" class="ho-close" data-close-modal>&times;</button>
      </div>
      <form method="post" class="ho-room-form" enctype="multipart/form-data">
        <input type="hidden" name="content_action" value="save_gallery" />
        <div class="ho-image-editor-section">
          <h4>Gallery Images</h4>
          <div class="ho-gallery-list" data-gallery-list>
            <?php foreach ($galleryImages as $galleryPath): ?>
              <div class="ho-gallery-row">
                <img src="<?= htmlspecialchars((string)$galleryPath) ?>" alt="Gallery image preview" class="ho-gallery-thumb" />
                <div class="ho-gallery-input-stack">
                  <input type="text" name="gallery_paths[]" value="<?= htmlspecialchars((string)$galleryPath) ?>" />
                  <label class="ho-gallery-upload-inline">
                    <span>Upload image</span>
                    <input type="file" name="gallery_row_files[]" accept="image/*" />
                  </label>
                </div>
                <button type="button" class="ho-gallery-remove" data-remove-gallery-row aria-label="Remove image">&times;</button>
              </div>
            <?php endforeach; ?>
          </div>
          <div class="ho-gallery-actions">
            <button type="button" class="ho-btn" data-add-gallery-row>+ Add Gallery Row</button>
          </div>
        </div>
        <div class="ho-room-actions">
          <button type="button" class="ho-btn cancel" data-close-modal>Cancel</button>
          <button type="submit" class="ho-btn confirm">Save Gallery</button>
        </div>
      </form>
    </div>
  </div>

  <script>
    (function () {
      const toggle = document.getElementById('hoNotifToggle');
      const panel = document.getElementById('hoNotifPanel');
      const markBtn = document.getElementById('hoNotifMarkRead');
      const badge = document.getElementById('hoNotifBadge');
      let notifMarked = false;

      const markNotificationsRead = async () => {
        if (notifMarked) return;
        notifMarked = true;
        const body = new URLSearchParams();
        body.set('ho_action', 'mark_notifications_read');
        await fetch('Hocontents.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body: body.toString()
        });
        if (badge) badge.remove();
      };

      if (toggle && panel) {
        toggle.addEventListener('click', () => {
          const open = panel.classList.toggle('open');
          toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
          if (open) markNotificationsRead();
        });

        document.addEventListener('click', (e) => {
          if (!panel.contains(e.target) && !toggle.contains(e.target)) {
            panel.classList.remove('open');
            toggle.setAttribute('aria-expanded', 'false');
          }
        });
      }

      if (markBtn) markBtn.addEventListener('click', markNotificationsRead);

      document.querySelectorAll('[data-open-modal]').forEach(btn => {
        btn.addEventListener('click', () => {
          const targetId = btn.getAttribute('data-open-modal');
          const modal = targetId ? document.getElementById(targetId) : null;
          if (modal) modal.classList.add('open');
        });
      });

      document.querySelectorAll('[data-close-modal]').forEach(btn => {
        btn.addEventListener('click', () => {
          const modal = btn.closest('.ho-modal');
          if (modal) modal.classList.remove('open');
        });
      });

      document.querySelectorAll('.ho-modal').forEach(modal => {
        modal.addEventListener('click', (e) => {
          if (e.target === modal) {
            modal.classList.remove('open');
          }
        });
      });

      const buildGalleryRow = (value = '') => {
        const wrapper = document.createElement('div');
        wrapper.className = 'ho-gallery-row';
        wrapper.innerHTML = `
          <img src="${value || 'img/sampleimage.png'}" alt="Gallery image preview" class="ho-gallery-thumb" />
          <div class="ho-gallery-input-stack">
            <input type="text" name="gallery_paths[]" value="${value}" />
            <label class="ho-gallery-upload-inline">
              <span>Upload image</span>
              <input type="file" name="gallery_row_files[]" accept="image/*" />
            </label>
          </div>
          <button type="button" class="ho-gallery-remove" data-remove-gallery-row aria-label="Remove image">&times;</button>
        `;

        const input = wrapper.querySelector('input[name="gallery_paths[]"]');
        const img = wrapper.querySelector('.ho-gallery-thumb');
        const upload = wrapper.querySelector('input[type="file"]');
        if (input && img) {
          input.addEventListener('input', () => {
            img.src = input.value.trim() || 'img/sampleimage.png';
          });
        }
        if (upload && img) {
          upload.addEventListener('change', () => {
            const file = upload.files && upload.files[0];
            if (!file) return;
            img.src = URL.createObjectURL(file);
          });
        }
        return wrapper;
      };

      document.querySelectorAll('[data-add-gallery-row]').forEach(btn => {
        btn.addEventListener('click', () => {
          const form = btn.closest('form');
          const list = form ? form.querySelector('[data-gallery-list]') : null;
          if (!list) return;
          list.appendChild(buildGalleryRow(''));
        });
      });

      document.querySelectorAll('[data-gallery-list]').forEach(list => {
        list.querySelectorAll('.ho-gallery-row').forEach(row => {
          const input = row.querySelector('input[name="gallery_paths[]"]');
          const img = row.querySelector('.ho-gallery-thumb');
          const upload = row.querySelector('input[type="file"]');
          if (input && img) {
            input.addEventListener('input', () => {
              img.src = input.value.trim() || 'img/sampleimage.png';
            });
          }
          if (upload && img) {
            upload.addEventListener('change', () => {
              const file = upload.files && upload.files[0];
              if (!file) return;
              img.src = URL.createObjectURL(file);
            });
          }
        });
      });

      document.addEventListener('click', (e) => {
        const target = e.target;
        if (!(target instanceof HTMLElement)) return;
        if (!target.matches('[data-remove-gallery-row]')) return;
        const row = target.closest('.ho-gallery-row');
        if (row) row.remove();
      });
    })();
  </script>
</body>
</html>
