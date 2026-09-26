<?php
require_once __DIR__ . '/Ho_common.php';
require_once __DIR__ . '/php/activity_logger.php';
require_once __DIR__ . '/php/input_validation.php';
require_once __DIR__ . '/php/project_path_helper.php';
require_once __DIR__ . '/php/secure_upload_helper.php';

$hoAdmin = HoRequireHotelAdmin($pdo);
$hoHotelResortId = (int)$hoAdmin['hotel_resort_id'];
$hoPropertyName = trim((string)($hoAdmin['property_name'] ?? ''));

$hoActive = 'contents';
$hoTitle = 'Property Contents';
$hoOwnerName = $hoPropertyName !== '' ? $hoPropertyName . ' Admin' : (string)$hoAdmin['username'];
$hoUnreadBadge = HoGetUnreadCount($pdo, $hoHotelResortId);
$hoNotifItems = HoGetNotificationItems($pdo, 8, $hoHotelResortId);
$hoPendingBadge = HoGetPendingCount($pdo, $hoHotelResortId);
$hotelContentCsrf = AppCsrfToken('hotel_admin', 'content_management');
$hotelNotificationCsrf = AppCsrfToken('hotel_admin', 'notifications');

function HoEnsureContentUploadDirectory(): array
{
    $relativeDir = 'uploads/hotel_contents';
    $absoluteDir = ItourEnsureProjectDirectory($relativeDir);
    return [$absoluteDir, $relativeDir];
}

function HoSaveUploadedContentImage(?array $file, string $absoluteDir, string $relativeDir): ?string
{
    if (!$file || (int)($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return null;
    $validated = ItourSecureValidateUploadedImage($file, 40 * 1024 * 1024, 40000000, 12000, 12000);
    $filename = 'content_' . date('YmdHis') . '_' . bin2hex(random_bytes(5)) . '.' . $validated['extension'];
    $targetAbs = $absoluteDir . DIRECTORY_SEPARATOR . $filename;
    try {
        ItourSecureOptimizeUploadedImage($validated, $targetAbs, 2400);
        ItourAssertPublicMediaFile($targetAbs);
    } catch (Throwable $exception) {
        if (is_file($targetAbs)) @unlink($targetAbs);
        throw $exception;
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
    try {
        for ($i = 0; $i < $count; $i++) {
            $entry = [
                'name' => $files['name'][$i] ?? '',
                'type' => $files['type'][$i] ?? '',
                'tmp_name' => $files['tmp_name'][$i] ?? '',
                'error' => $files['error'][$i] ?? UPLOAD_ERR_NO_FILE,
                'size' => $files['size'][$i] ?? 0,
            ];
            $path = HoSaveUploadedContentImage($entry, $absoluteDir, $relativeDir);
            if ($path) $saved[$i] = $path;
        }
    } catch (Throwable $exception) {
        foreach ($saved as $newPath) {
            $basename = basename((string)$newPath);
            $absolute = $absoluteDir . DIRECTORY_SEPARATOR . $basename;
            if ($basename !== '' && is_file($absolute)) @unlink($absolute);
        }
        throw $exception;
    }
    return $saved;
}

if (isset($_POST['ho_action']) && $_POST['ho_action'] === 'mark_notifications_read') {
    if (!AppVerifyCsrf('hotel_admin', 'notifications', $_POST['csrf_token'] ?? null)) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['ok' => false]);
        exit;
    }
    HoMarkNotificationsRead($pdo, $hoHotelResortId);
    header('Content-Type: application/json');
    echo json_encode(['ok' => true]);
    exit;
}

$flash = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['content_action'])) {
    if (!AppVerifyCsrf('hotel_admin', 'content_management', $_POST['csrf_token'] ?? null)) {
        http_response_code(403);
        exit('Invalid security token. Refresh the page and try again.');
    }
    [$uploadAbsDir, $uploadRelDir] = HoEnsureContentUploadDirectory();
    $action = trim((string)$_POST['content_action']);

    $toList = static function (string $raw): array {
        if (mb_strlen($raw) > 10000) throw new InvalidArgumentException('List content must not exceed 10000 characters.');
        if ($raw === '') return [];
        $lines = preg_split('/\r\n|\r|\n/', $raw);
        $items = [];
        foreach ($lines as $line) {
            $line = trim((string)$line);
            if ($line !== '') $items[] = $line;
        }
        $items = array_values(array_unique($items));
        if (count($items) > 50) throw new InvalidArgumentException('A content list may contain at most 50 items.');
        foreach ($items as $item) {
            if (mb_strlen($item) > 180) throw new InvalidArgumentException('Each content-list item must not exceed 180 characters.');
        }
        return $items;
    };

    $existingStmt = $pdo->prepare("
        SELECT image_path, amenities_json, description_text, rules_json, gallery_images_json, owner_content_json
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
    $ownerContent = HoDecodeJsonObject((string)($existing['owner_content_json'] ?? '{}'));
    $coverImagePath = trim((string)($existing['image_path'] ?? ''));
    if ($coverImagePath === '') {
        $coverImagePath = 'img/sampleimage.png';
    }

    try {
    if ($action === 'save_description') {
        $descriptionText = ItourValidationText($_POST['description_text'] ?? '', 'Property description', 10000);
    } elseif ($action === 'save_facilities') {
        $amenitiesRaw = ItourValidationText($_POST['amenities_text'] ?? '', 'Facilities', 10000);
        $amenities = $toList($amenitiesRaw);
    } elseif ($action === 'save_rules') {
        $rulesRaw = ItourValidationText($_POST['rules_text'] ?? '', 'Rules', 10000);
        $rules = $toList($rulesRaw);
    } elseif ($action === 'save_main_image') {
        $coverImagePath = ItourValidationText($_POST['image_path'] ?? $coverImagePath, 'Main image path', 255);
        if ($coverImagePath !== '' && !preg_match('#^(?:img/[A-Za-z0-9._-]+|uploads/hotel_contents/[A-Za-z0-9._-]+)$#D', $coverImagePath)) {
            throw new InvalidArgumentException('Invalid property image path.');
        }
        $uploadedMainImage = HoSaveUploadedContentImage($_FILES['main_image_file'] ?? null, $uploadAbsDir, $uploadRelDir);
        if ($uploadedMainImage) {
            $coverImagePath = $uploadedMainImage;
        }
        if ($coverImagePath === '') {
            $coverImagePath = 'img/sampleimage.png';
        }
    } elseif ($action === 'save_gallery') {
        if (!is_array($_POST['gallery_paths'] ?? null)) throw new InvalidArgumentException('Gallery paths must be submitted as a list.');
        $galleryPathsPosted = $_POST['gallery_paths'];
        if (count($galleryPathsPosted) > 20) throw new InvalidArgumentException('The gallery may contain at most 20 images.');
        foreach ($galleryPathsPosted as $postedPath) {
            $postedPath = ItourValidationText($postedPath, 'Gallery image path', 255);
            if ($postedPath !== '' && !preg_match('#^(?:img/[A-Za-z0-9._-]+|uploads/hotel_contents/[A-Za-z0-9._-]+)$#D', $postedPath)) {
                throw new InvalidArgumentException('Invalid gallery image path.');
            }
        }
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
    } elseif ($action === 'save_property_info') {
        $contactEmail = ItourValidationText($_POST['contact_email'] ?? '', 'Contact email', 190);
        $websiteUrl = ItourValidationText($_POST['website_url'] ?? '', 'Website URL', 500);
        $facebookUrl = ItourValidationText($_POST['facebook_url'] ?? '', 'Facebook URL', 500);
        $websiteScheme = strtolower((string)(parse_url($websiteUrl, PHP_URL_SCHEME) ?? ''));
        $facebookScheme = strtolower((string)(parse_url($facebookUrl, PHP_URL_SCHEME) ?? ''));
        if ($contactEmail !== '' && !filter_var($contactEmail, FILTER_VALIDATE_EMAIL)) {
            $error = 'Please enter a valid public contact email.';
        } elseif ($websiteUrl !== '' && (!filter_var($websiteUrl, FILTER_VALIDATE_URL) || !in_array($websiteScheme, ['https', 'http'], true))) {
            $error = 'Please enter a valid website URL beginning with https:// or http://.';
        } elseif ($facebookUrl !== '' && (!filter_var($facebookUrl, FILTER_VALIDATE_URL) || !in_array($facebookScheme, ['https', 'http'], true))) {
            $error = 'Please enter a valid Facebook URL beginning with https:// or http://.';
        } else {
            $ownerContent['tagline'] = ItourValidationText($_POST['tagline'] ?? '', 'Property tagline', 255);
            $ownerContent['contact_phone'] = ItourValidationText($_POST['contact_phone'] ?? '', 'Contact phone', 30);
            $ownerContent['contact_email'] = $contactEmail;
            $ownerContent['website_url'] = $websiteUrl;
            $ownerContent['facebook_url'] = $facebookUrl;
            $ownerContent['address'] = ItourValidationText($_POST['property_address'] ?? '', 'Property address', 500);
        }
    } elseif ($action === 'save_guest_policies') {
        $checkinTime = ItourValidationClock($_POST['checkin_time'] ?? null, 'Check-in time', true);
        $checkoutTime = ItourValidationClock($_POST['checkout_time'] ?? null, 'Check-out time', true);
        $ownerContent['checkin_time'] = $checkinTime;
        $ownerContent['checkout_time'] = $checkoutTime;
        $ownerContent['minimum_stay'] = ItourValidationInt($_POST['minimum_stay'] ?? 1, 'Minimum stay', 1, 30);
        $ownerContent['cancellation_policy'] = ItourValidationText($_POST['cancellation_policy'] ?? '', 'Cancellation policy', 5000);
        $ownerContent['child_policy'] = ItourValidationText($_POST['child_policy'] ?? '', 'Child policy', 5000);
        $ownerContent['pet_policy'] = ItourValidationText($_POST['pet_policy'] ?? '', 'Pet policy', 5000);
    } elseif ($action === 'save_highlights') {
        $ownerContent['highlights'] = $toList(ItourValidationText($_POST['highlights_text'] ?? '', 'Highlights', 10000));
    } elseif ($action === 'save_location_info') {
        $ownerContent['transport_info'] = ItourValidationText($_POST['transport_info'] ?? '', 'Transport information', 5000);
        $ownerContent['parking_info'] = ItourValidationText($_POST['parking_info'] ?? '', 'Parking information', 5000);
        $ownerContent['accessibility_info'] = ItourValidationText($_POST['accessibility_info'] ?? '', 'Accessibility information', 5000);
        $ownerContent['nearby_attractions'] = $toList(ItourValidationText($_POST['nearby_attractions_text'] ?? '', 'Nearby attractions', 10000));
    } else {
        $error = 'Invalid update action.';
    }
    } catch (InvalidArgumentException $exception) {
        $error = $exception->getMessage();
    }

    if ($error === '') {
        try {
            $update = $pdo->prepare("
                UPDATE hotel_resorts
                SET image_path = ?, amenities_json = ?, description_text = ?, rules_json = ?, gallery_images_json = ?, owner_content_json = ?, updated_at = NOW()
                WHERE hotel_resort_id = ?
                LIMIT 1
            ");
            $update->execute([
                $coverImagePath,
                json_encode($amenities, JSON_UNESCAPED_UNICODE),
                $descriptionText,
                json_encode($rules, JSON_UNESCAPED_UNICODE),
                json_encode($gallery, JSON_UNESCAPED_UNICODE),
                json_encode($ownerContent, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                $hoHotelResortId
            ]);
            $contentLabels = [
                'save_description' => 'description',
                'save_facilities' => 'facilities',
                'save_rules' => 'rules',
                'save_main_image' => 'main image',
                'save_gallery' => 'gallery',
                'save_property_info' => 'contact and property information',
                'save_guest_policies' => 'guest policies',
                'save_highlights' => 'property highlights',
                'save_location_info' => 'location and accessibility information'
            ];
            logActivity(
                $pdo, 'Hotel Owner', (int)$hoAdmin['hotel_admin_id'], (string)$hoOwnerName,
                'Hotel Content Updated',
                'Updated the property ' . ($contentLabels[$action] ?? 'content') . '.',
                'Hotel Content', $hoHotelResortId
            );
            $flash = 'Property content updated successfully.';
        } catch (Throwable $e) {
            $error = $e instanceof InvalidArgumentException
                ? $e->getMessage()
                : 'Failed to update property content. Please try again.';
        }
    }
}

$stmt = $pdo->prepare("
    SELECT hotel_resort_id, name, island, type, image_path, amenities_json, description_text, rules_json, gallery_images_json, owner_content_json
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
$ownerContent = HoDecodeJsonObject((string)($property['owner_content_json'] ?? '{}'));
$propertyTagline = trim((string)($ownerContent['tagline'] ?? ''));
$contactPhone = trim((string)($ownerContent['contact_phone'] ?? ''));
$contactEmail = trim((string)($ownerContent['contact_email'] ?? ''));
$websiteUrl = trim((string)($ownerContent['website_url'] ?? ''));
$facebookUrl = trim((string)($ownerContent['facebook_url'] ?? ''));
$propertyAddress = trim((string)($ownerContent['address'] ?? ''));
$checkinTime = trim((string)($ownerContent['checkin_time'] ?? '14:00'));
$checkoutTime = trim((string)($ownerContent['checkout_time'] ?? '12:00'));
$minimumStay = max(1, (int)($ownerContent['minimum_stay'] ?? 1));
$cancellationPolicy = trim((string)($ownerContent['cancellation_policy'] ?? ''));
$childPolicy = trim((string)($ownerContent['child_policy'] ?? ''));
$petPolicy = trim((string)($ownerContent['pet_policy'] ?? ''));
$propertyHighlights = is_array($ownerContent['highlights'] ?? null) ? array_values(array_filter(array_map('trim', $ownerContent['highlights']))) : [];
$transportInfo = trim((string)($ownerContent['transport_info'] ?? ''));
$parkingInfo = trim((string)($ownerContent['parking_info'] ?? ''));
$accessibilityInfo = trim((string)($ownerContent['accessibility_info'] ?? ''));
$nearbyAttractions = is_array($ownerContent['nearby_attractions'] ?? null) ? array_values(array_filter(array_map('trim', $ownerContent['nearby_attractions']))) : [];

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

$contentCompletion = [
    $descriptionText !== '',
    !empty($amenities),
    !empty($rules),
    $coverImagePath !== '' && $coverImagePath !== 'img/sampleimage.png',
    !empty($galleryImages),
    $contactPhone !== '' || $contactEmail !== '' || $propertyAddress !== '',
    $checkinTime !== '' && $checkoutTime !== '' && $cancellationPolicy !== '',
    !empty($propertyHighlights),
    $transportInfo !== '' || !empty($nearbyAttractions),
];
$completedContentSections = count(array_filter($contentCompletion));
$totalContentSections = count($contentCompletion);
$contentCompletionPercent = (int)round(($completedContentSections / max(1, $totalContentSections)) * 100);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Hocontents | Hotel Owner Contents</title>
  <link rel="icon" type="image/png" href="img/newlogo.png" />
  <link rel="stylesheet" href="styles/Ho_panel.css?v=<?= (int)@filemtime(__DIR__ . '/styles/Ho_panel.css') ?>" />
  <link rel="stylesheet" href="styles/Ho_contents_redesign.css?v=2" />
</head>
<body class="ho-body">
  <div class="ho-layout">
    <?php include __DIR__ . '/Ho_sidebar.php'; ?>

    <main class="ho-main">
      <?php include __DIR__ . '/Ho_header.php'; ?>

      <section class="ho-content">
        <div class="ho-content-workspace">
          <?php if ($flash !== ''): ?>
            <div class="ho-banner success"><?= htmlspecialchars($flash) ?></div>
          <?php endif; ?>
          <?php if ($error !== ''): ?>
            <div class="ho-banner error"><?= htmlspecialchars($error) ?></div>
          <?php endif; ?>

          <?php include __DIR__ . '/includes/Ho_contents_workspace.php'; ?>

          <?php if (false): // Legacy layout retained temporarily for safe rollback. ?>
          <section class="ho-content-overview">
            <div class="ho-content-overview-image">
              <img src="<?= htmlspecialchars($coverImagePath) ?>" alt="<?= htmlspecialchars($propertyName) ?>" />
              <button type="button" data-open-modal="hoMainImageModal">Change cover</button>
            </div>
            <div class="ho-content-overview-copy">
              <span><?= htmlspecialchars($propertyType !== '' ? $propertyType : 'PROPERTY') ?></span>
              <h3><?= htmlspecialchars($propertyName !== '' ? $propertyName : 'Assigned Property') ?></h3>
              <p><?= htmlspecialchars($propertyIsland !== '' ? $propertyIsland : 'Island not set') ?> • <?= htmlspecialchars($propertyType !== '' ? $propertyType : 'TYPE') ?></p>
              <p class="ho-content-overview-tagline"><?= htmlspecialchars($propertyTagline !== '' ? $propertyTagline : 'Add a short property tagline.') ?></p>
              <small><?= htmlspecialchars($propertyIsland !== '' ? $propertyIsland . ', Mercedes' : 'Location not set') ?></small>
            </div>
            <div class="ho-content-overview-side">
              <div class="ho-content-progress-card">
                <div class="ho-content-progress-head">
                  <span>Profile readiness</span>
                  <strong><?= $contentCompletionPercent ?>%</strong>
                </div>
                <div class="ho-content-progress-track"><span style="width: <?= $contentCompletionPercent ?>%"></span></div>
                <p><?= $completedContentSections ?> of <?= $totalContentSections ?> sections ready</p>
              </div>
              <a class="ho-content-preview-btn" href="hotel_details.php?id=<?= (int)$hoHotelResortId ?>" target="_blank" rel="noopener">
                View public listing <span aria-hidden="true">&nearr;</span>
              </a>
            </div>
          </section>

          <nav class="ho-content-section-nav" aria-label="Property content sections">
            <a href="#hoContentEssentials">Contact</a>
            <a href="#hoContentGuestExperience">Amenities</a>
            <a href="#hoContentPolicies">Policies</a>
            <a href="#hoContentMedia">Photos</a>
          </nav>

          <div class="ho-content-dashboard-grid">
              <article class="ho-property-editor-card" id="hoContentEssentials">
                <div class="ho-property-card-icon">i</div>
                <div class="ho-property-card-copy">
                  <div class="ho-property-card-title"><div><h4>Property information</h4><span>Contact and public listing details</span></div><button type="button" data-open-modal="hoPropertyInfoModal">Edit</button></div>
                  <dl class="ho-content-facts">
                    <div><dt>Phone</dt><dd><?= htmlspecialchars($contactPhone !== '' ? $contactPhone : 'Not added') ?></dd></div>
                    <div><dt>Email</dt><dd><?= htmlspecialchars($contactEmail !== '' ? $contactEmail : 'Not added') ?></dd></div>
                    <div><dt>Address</dt><dd><?= htmlspecialchars($propertyAddress !== '' ? $propertyAddress : 'Not added') ?></dd></div>
                  </dl>
                </div>
              </article>

            <article class="ho-content-current">
              <div class="ho-content-card-head">
                <div><h4>Description</h4><small>Your property story and guest experience</small></div>
                <button type="button" class="ho-btn" data-open-modal="hoDescriptionModal">Edit</button>
              </div>
              <?php if ($descriptionText !== ''): ?>
                <p><?= nl2br(htmlspecialchars($descriptionText)) ?></p>
              <?php else: ?>
                <p class="muted">No description yet.</p>
              <?php endif; ?>
            </article>

            <article class="ho-content-current" id="hoContentGuestExperience">
              <div class="ho-content-card-head">
                <div><h4>Facilities and Amenities</h4><small>Services and features available on-site</small></div>
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

            <article class="ho-content-current" id="hoContentPolicies">
              <div class="ho-content-card-head">
                <div><h4>House Rules</h4><small>Guidelines guests must follow</small></div>
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

            <article class="ho-content-current" id="hoContentMedia">
              <div class="ho-content-card-head">
                <div><h4>Cover Image</h4><small>The first image guests see</small></div>
                <button type="button" class="ho-btn" data-open-modal="hoMainImageModal">Edit</button>
              </div>
              <div class="ho-content-main-image">
                <img src="<?= htmlspecialchars($coverImagePath) ?>" alt="Main image" />
              </div>
              <p class="muted"><?= htmlspecialchars($coverImagePath) ?></p>
            </article>

            <article class="ho-content-current">
              <div class="ho-content-card-head">
                <div><h4>Photo Gallery</h4><small><?= count($galleryImages) ?> image<?= count($galleryImages) === 1 ? '' : 's' ?> uploaded</small></div>
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

            <article class="ho-content-current">
              <div class="ho-content-card-head">
                <div><h4>Property Highlights</h4><small>Show what makes your property special</small></div>
                <button type="button" class="ho-btn" data-open-modal="hoHighlightsModal">Edit</button>
              </div>
              <?php if ($propertyHighlights): ?>
                <ul class="ho-content-check-list">
                  <?php foreach (array_slice($propertyHighlights, 0, 5) as $highlight): ?><li><?= htmlspecialchars($highlight) ?></li><?php endforeach; ?>
                </ul>
              <?php else: ?>
                <p class="muted">Add your best views, services, or unique guest experiences.</p>
              <?php endif; ?>
            </article>

            <article class="ho-content-current">
              <div class="ho-content-card-head">
                <div><h4>Guest Policies</h4><small>Arrival times and booking conditions</small></div>
                <button type="button" class="ho-btn" data-open-modal="hoGuestPoliciesModal">Edit</button>
              </div>
              <div class="ho-content-time-grid">
                <div><small>CHECK-IN</small><strong><?= htmlspecialchars(date('g:i A', strtotime($checkinTime))) ?></strong></div>
                <div><small>CHECK-OUT</small><strong><?= htmlspecialchars(date('g:i A', strtotime($checkoutTime))) ?></strong></div>
                <div><small>MINIMUM STAY</small><strong><?= $minimumStay ?> night<?= $minimumStay === 1 ? '' : 's' ?></strong></div>
              </div>
              <p class="muted"><?= htmlspecialchars($cancellationPolicy !== '' ? $cancellationPolicy : 'Add your cancellation policy.') ?></p>
            </article>

            <article class="ho-content-current ho-content-card-wide">
              <div class="ho-content-card-head">
                <div><h4>Location, Transport, and Accessibility</h4><small>Help guests plan their arrival and nearby activities</small></div>
                <button type="button" class="ho-btn" data-open-modal="hoLocationInfoModal">Edit</button>
              </div>
              <div class="ho-content-info-columns">
                <div><small>GETTING THERE</small><p><?= htmlspecialchars($transportInfo !== '' ? $transportInfo : 'Transportation guidance not added.') ?></p></div>
                <div><small>NEARBY ATTRACTIONS</small><p><?= htmlspecialchars($nearbyAttractions ? implode(' • ', array_slice($nearbyAttractions, 0, 5)) : 'Nearby places not added.') ?></p></div>
                <div><small>ACCESSIBILITY</small><p><?= htmlspecialchars($accessibilityInfo !== '' ? $accessibilityInfo : 'Accessibility information not added.') ?></p></div>
              </div>
            </article>
          </div>
          <?php endif; ?>
        </div>
      </section>

      <?php include __DIR__ . '/Ho_footer.php'; ?>
    </main>
  </div>

  <div class="ho-modal" id="hoPropertyInfoModal" aria-hidden="true">
    <div class="ho-modal-card ho-room-modal-card ho-content-editor-modal">
      <div class="ho-modal-head">
        <div><h3>Property Information</h3><p>Public contact details shown to potential guests.</p></div>
        <button type="button" class="ho-close" data-close-modal>&times;</button>
      </div>
      <form method="post" class="ho-room-form">
        <input type="hidden" name="content_action" value="save_property_info" />
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($hotelContentCsrf, ENT_QUOTES, 'UTF-8') ?>" />
        <label>Property Tagline
          <input type="text" name="tagline" maxlength="140" value="<?= htmlspecialchars($propertyTagline) ?>" placeholder="A peaceful island escape made for families and groups" />
          <small>Use one short sentence that communicates your strongest selling point.</small>
        </label>
        <div class="ho-content-form-grid">
          <label>Public Phone
            <input type="tel" name="contact_phone" value="<?= htmlspecialchars($contactPhone) ?>" placeholder="+63 912 345 6789" />
          </label>
          <label>Public Email
            <input type="email" name="contact_email" value="<?= htmlspecialchars($contactEmail) ?>" placeholder="reservations@example.com" />
          </label>
          <label>Website URL
            <input type="url" name="website_url" value="<?= htmlspecialchars($websiteUrl) ?>" placeholder="https://example.com" />
          </label>
          <label>Facebook Page
            <input type="url" name="facebook_url" value="<?= htmlspecialchars($facebookUrl) ?>" placeholder="https://facebook.com/yourproperty" />
          </label>
        </div>
        <label>Complete Property Address
          <textarea name="property_address" rows="3" placeholder="Barangay, island, municipality, province"><?= htmlspecialchars($propertyAddress) ?></textarea>
        </label>
        <div class="ho-room-actions">
          <button type="button" class="ho-btn cancel" data-close-modal>Cancel</button>
          <button type="submit" class="ho-btn confirm">Save Information</button>
        </div>
      </form>
    </div>
  </div>

  <div class="ho-modal" id="hoHighlightsModal" aria-hidden="true">
    <div class="ho-modal-card ho-room-modal-card ho-content-editor-modal">
      <div class="ho-modal-head">
        <div><h3>Property Highlights</h3><p>Add the strongest reasons travelers should choose your property.</p></div>
        <button type="button" class="ho-close" data-close-modal>&times;</button>
      </div>
      <form method="post" class="ho-room-form">
        <input type="hidden" name="content_action" value="save_highlights" />
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($hotelContentCsrf, ENT_QUOTES, 'UTF-8') ?>" />
        <label>Highlights <span class="ho-field-note">One item per line</span>
          <textarea name="highlights_text" rows="9" placeholder="Private beach access&#10;Sunset-facing rooms&#10;Family-friendly activities"><?= htmlspecialchars(implode("\n", $propertyHighlights)) ?></textarea>
          <small>Keep each highlight concise and specific. Five to eight items works best.</small>
        </label>
        <div class="ho-room-actions">
          <button type="button" class="ho-btn cancel" data-close-modal>Cancel</button>
          <button type="submit" class="ho-btn confirm">Save Highlights</button>
        </div>
      </form>
    </div>
  </div>

  <div class="ho-modal" id="hoGuestPoliciesModal" aria-hidden="true">
    <div class="ho-modal-card ho-room-modal-card ho-content-editor-modal">
      <div class="ho-modal-head">
        <div><h3>Guest Policies</h3><p>Set expectations clearly before a guest books.</p></div>
        <button type="button" class="ho-close" data-close-modal>&times;</button>
      </div>
      <form method="post" class="ho-room-form">
        <input type="hidden" name="content_action" value="save_guest_policies" />
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($hotelContentCsrf, ENT_QUOTES, 'UTF-8') ?>" />
        <div class="ho-content-form-grid three">
          <label>Check-in Time
            <input type="time" name="checkin_time" value="<?= htmlspecialchars($checkinTime) ?>" required />
          </label>
          <label>Check-out Time
            <input type="time" name="checkout_time" value="<?= htmlspecialchars($checkoutTime) ?>" required />
          </label>
          <label>Minimum Stay
            <input type="number" name="minimum_stay" min="1" max="30" value="<?= $minimumStay ?>" required />
          </label>
        </div>
        <label>Cancellation Policy
          <textarea name="cancellation_policy" rows="4" placeholder="Explain cancellation deadlines, refunds, and no-show charges."><?= htmlspecialchars($cancellationPolicy) ?></textarea>
        </label>
        <label>Children Policy
          <textarea name="child_policy" rows="3" placeholder="Explain age limits, free stays, or extra-bed conditions."><?= htmlspecialchars($childPolicy) ?></textarea>
        </label>
        <label>Pet Policy
          <textarea name="pet_policy" rows="3" placeholder="State whether pets are allowed and any applicable conditions."><?= htmlspecialchars($petPolicy) ?></textarea>
        </label>
        <div class="ho-room-actions">
          <button type="button" class="ho-btn cancel" data-close-modal>Cancel</button>
          <button type="submit" class="ho-btn confirm">Save Policies</button>
        </div>
      </form>
    </div>
  </div>

  <div class="ho-modal" id="hoLocationInfoModal" aria-hidden="true">
    <div class="ho-modal-card ho-room-modal-card ho-content-editor-modal">
      <div class="ho-modal-head">
        <div><h3>Location and Guest Access</h3><p>Give guests the practical information they need before arrival.</p></div>
        <button type="button" class="ho-close" data-close-modal>&times;</button>
      </div>
      <form method="post" class="ho-room-form">
        <input type="hidden" name="content_action" value="save_location_info" />
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($hotelContentCsrf, ENT_QUOTES, 'UTF-8') ?>" />
        <label>Getting There
          <textarea name="transport_info" rows="4" placeholder="Describe boat transfers, pickup points, travel time, and schedules."><?= htmlspecialchars($transportInfo) ?></textarea>
        </label>
        <label>Parking Information
          <textarea name="parking_info" rows="3" placeholder="Explain where guests can park and whether fees apply."><?= htmlspecialchars($parkingInfo) ?></textarea>
        </label>
        <label>Accessibility Information
          <textarea name="accessibility_info" rows="3" placeholder="Describe stairs, ramps, accessible rooms, paths, or assistance available."><?= htmlspecialchars($accessibilityInfo) ?></textarea>
        </label>
        <label>Nearby Attractions <span class="ho-field-note">One item per line</span>
          <textarea name="nearby_attractions_text" rows="6" placeholder="Mercedes Fish Port — 15 minutes&#10;Caringo Island — boat trip"><?= htmlspecialchars(implode("\n", $nearbyAttractions)) ?></textarea>
        </label>
        <div class="ho-room-actions">
          <button type="button" class="ho-btn cancel" data-close-modal>Cancel</button>
          <button type="submit" class="ho-btn confirm">Save Location Details</button>
        </div>
      </form>
    </div>
  </div>

  <div class="ho-modal" id="hoDescriptionModal" aria-hidden="true">
    <div class="ho-modal-card ho-room-modal-card">
      <div class="ho-modal-head">
        <h3>Edit Description</h3>
        <button type="button" class="ho-close" data-close-modal>&times;</button>
      </div>
      <form method="post" class="ho-room-form">
        <input type="hidden" name="content_action" value="save_description" />
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($hotelContentCsrf, ENT_QUOTES, 'UTF-8') ?>" />
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
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($hotelContentCsrf, ENT_QUOTES, 'UTF-8') ?>" />
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
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($hotelContentCsrf, ENT_QUOTES, 'UTF-8') ?>" />
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
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($hotelContentCsrf, ENT_QUOTES, 'UTF-8') ?>" />
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
              <div class="ho-room-image-control">
                <input type="file" name="main_image_file" accept="image/*" data-room-image-input hidden />
                <button type="button" class="ho-room-image-trigger" data-room-image-trigger>Update cover image</button>
                <small>JPG, PNG, or WebP up to 40 MB</small>
              </div>
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
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($hotelContentCsrf, ENT_QUOTES, 'UTF-8') ?>" />
        <div class="ho-image-editor-section">
          <h4>Gallery Images</h4>
          <div class="ho-gallery-list" data-gallery-list>
            <?php foreach ($galleryImages as $galleryPath): ?>
              <div class="ho-gallery-row">
                <img src="<?= htmlspecialchars((string)$galleryPath) ?>" alt="Gallery image preview" class="ho-gallery-thumb" />
                <div class="ho-gallery-input-stack">
                  <input type="text" name="gallery_paths[]" value="<?= htmlspecialchars((string)$galleryPath) ?>" />
                  <button type="button" class="ho-gallery-upload-inline" data-room-image-trigger>Update image</button>
                  <input type="file" name="gallery_row_files[]" accept="image/*" data-room-image-input hidden />
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

  <div class="ho-modal" id="hoRoomImageUploadModal" aria-hidden="true">
    <div class="ho-modal-card ho-room-image-upload-card" role="dialog" aria-modal="true" aria-labelledby="hoRoomImageUploadTitle">
      <div class="ho-modal-head">
        <div><h3 id="hoRoomImageUploadTitle">Update property image</h3><p>Choose a high-quality image before applying it to your property.</p></div>
        <button type="button" class="ho-close" id="hoRoomImageUploadClose" aria-label="Close image upload">&times;</button>
      </div>
      <div class="ho-room-image-upload-body">
        <label class="ho-room-image-dropzone" for="hoRoomImagePicker" data-room-image-dropzone>
          <span class="ho-room-image-upload-icon" aria-hidden="true">
            <svg viewBox="0 0 24 24"><path d="M10 1a1 1 0 0 0-.71.29l-6 6A1 1 0 0 0 3 8v12a3 3 0 0 0 3 3h1a1 1 0 1 0 0-2H6a1 1 0 0 1-1-1V9h5a1 1 0 0 0 1-1V3h7a1 1 0 0 1 1 1v5a1 1 0 1 0 2 0V4a3 3 0 0 0-3-3h-8ZM9 7H6.41L9 4.41V7Zm7.5 4a4.5 4.5 0 0 0-4.48 4.12A4 4 0 0 0 13 23h7a4 4 0 0 0 .98-7.88A4.5 4.5 0 0 0 16.5 11Zm0 2a2.5 2.5 0 0 1 2.5 2.5V17h1a2 2 0 1 1 0 4h-7a2 2 0 1 1 0-4h1v-1.5a2.5 2.5 0 0 1 2.5-2.5Z"/></svg>
          </span>
          <strong>Click or drag an image here</strong>
          <small>JPG, PNG, or WebP · maximum 40 MB</small>
          <input type="file" id="hoRoomImagePicker" accept="image/jpeg,image/png,image/webp" hidden />
        </label>
        <div class="ho-room-image-selected" id="hoRoomImageSelected" hidden>
          <img id="hoRoomImageSelectedPreview" alt="Selected property image preview" />
          <div><strong id="hoRoomImageSelectedName"></strong><span>Ready to apply</span></div>
          <button type="button" id="hoRoomImageChooseAgain">Choose another</button>
        </div>
      </div>
      <div class="ho-room-image-upload-actions">
        <button type="button" class="ho-btn" id="hoRoomImageUploadCancel">Cancel</button>
        <button type="button" class="ho-btn confirm" id="hoRoomImageUploadApply" disabled>Apply image</button>
      </div>
    </div>
  </div>

  <script src="js/image-upload-optimizer.js?v=<?= (int)@filemtime(__DIR__ . '/js/image-upload-optimizer.js') ?>"></script>
  <script>
    (function () {
      const toggle = document.getElementById('hoNotifToggle');
      const panel = document.getElementById('hoNotifPanel');
      const markBtn = document.getElementById('hoNotifMarkRead');
      const badge = document.getElementById('hoNotifBadge');
      const unreadSelector = '.ho-notif-item.is-unread';
      let notifMarked = false;
      const hideBadge = () => {
        if (badge) badge.style.display = 'none';
      };
      const hasUnreadItems = () => panel ? panel.querySelector(unreadSelector) !== null : false;
      const clearUnreadState = () => {
        if (!panel) return;
        panel.querySelectorAll(unreadSelector).forEach((item) => item.classList.remove('is-unread'));
        panel.querySelectorAll('.ho-notif-unread-pill').forEach((pill) => pill.remove());
      };

      const markNotificationsRead = async () => {
        if (notifMarked || !hasUnreadItems()) return;
        notifMarked = true;
        const body = new URLSearchParams();
        body.set('ho_action', 'mark_notifications_read');
        body.set('csrf_token', <?= json_encode($hotelNotificationCsrf) ?>);
        try {
          const response = await fetch('Hocontents.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body.toString()
          });
          if (!response.ok) {
            throw new Error(`Failed to mark notifications as read (${response.status})`);
          }
          hideBadge();
          clearUnreadState();
        } catch (error) {
          notifMarked = false;
          console.error(error);
        }
      };

      const closePanelAndMarkRead = () => {
        if (!panel || !toggle) return;
        const wasOpen = panel.classList.contains('open');
        panel.classList.remove('open');
        toggle.setAttribute('aria-expanded', 'false');
        if (wasOpen) markNotificationsRead();
      };

      if (toggle && panel) {
        toggle.addEventListener('click', () => {
          const willOpen = !panel.classList.contains('open');
          if (!willOpen) {
            closePanelAndMarkRead();
            return;
          }
          panel.classList.add('open');
          toggle.setAttribute('aria-expanded', 'true');
          hideBadge();
        });

        document.addEventListener('click', (e) => {
          if (!panel.contains(e.target) && !toggle.contains(e.target)) {
            closePanelAndMarkRead();
          }
        });
      }

      if (markBtn) markBtn.addEventListener('click', markNotificationsRead);

      document.querySelectorAll('[data-open-modal]').forEach(btn => {
        btn.addEventListener('click', () => {
          const targetId = btn.getAttribute('data-open-modal');
          const modal = targetId ? document.getElementById(targetId) : null;
          if (modal) {
            modal.classList.add('open');
            modal.setAttribute('aria-hidden', 'false');
            modal.querySelector('form input:not([type="hidden"]), form textarea, form select')?.focus();
          }
        });
      });

      document.querySelectorAll('[data-close-modal]').forEach(btn => {
        btn.addEventListener('click', () => {
          const modal = btn.closest('.ho-modal');
          if (modal) {
            modal.classList.remove('open');
            modal.setAttribute('aria-hidden', 'true');
          }
        });
      });

      document.querySelectorAll('.ho-modal').forEach(modal => {
        modal.addEventListener('click', (e) => {
          if (e.target === modal) {
            modal.classList.remove('open');
            modal.setAttribute('aria-hidden', 'true');
          }
        });
      });

      document.addEventListener('keydown', event => {
        if (event.key !== 'Escape') return;
        const lightbox = document.querySelector('.pc-lightbox.open');
        if (lightbox) {
          lightbox.classList.remove('open');
          lightbox.setAttribute('aria-hidden', 'true');
          return;
        }
        const openModals = [...document.querySelectorAll('.ho-modal.open')];
        const modal = openModals[openModals.length - 1];
        if (!modal) return;
        modal.classList.remove('open');
        modal.setAttribute('aria-hidden', 'true');
      });

      const contentNavLinks = [...document.querySelectorAll('.pc-section-nav a')];
      const contentSections = [...document.querySelectorAll('.pc-section[id]')];
      contentNavLinks.forEach(link => {
        link.addEventListener('click', event => {
          const section = document.querySelector(link.getAttribute('href'));
          if (!section) return;
          event.preventDefault();
          section.scrollIntoView({ behavior: 'smooth', block: 'start' });
        });
      });
      if ('IntersectionObserver' in window && contentSections.length) {
        const sectionObserver = new IntersectionObserver(entries => {
          const visible = entries.filter(entry => entry.isIntersecting).sort((a, b) => b.intersectionRatio - a.intersectionRatio)[0];
          if (!visible) return;
          contentNavLinks.forEach(link => link.classList.toggle('active', link.getAttribute('href') === `#${visible.target.id}`));
        }, { rootMargin: '-18% 0px -66% 0px', threshold: [0, .15, .4] });
        contentSections.forEach(section => sectionObserver.observe(section));
      }

      const expandButton = document.querySelector('[data-expand-text]');
      const expandableText = document.querySelector('[data-expandable]');
      expandButton?.addEventListener('click', () => {
        const expanded = expandableText?.classList.toggle('expanded') || false;
        expandButton.innerHTML = expanded ? 'Show less <span>↑</span>' : 'Read full description <span>↓</span>';
      });

      document.querySelector('[data-scroll-incomplete]')?.addEventListener('click', () => {
        const firstIncomplete = document.querySelector('.pc-section[data-complete="false"]');
        firstIncomplete?.scrollIntoView({ behavior: 'smooth', block: 'start' });
      });

      const lightbox = document.querySelector('.pc-lightbox');
      const lightboxImage = lightbox?.querySelector('img');
      const closeLightbox = () => {
        if (!lightbox) return;
        lightbox.classList.remove('open');
        lightbox.setAttribute('aria-hidden', 'true');
      };
      document.querySelectorAll('[data-gallery-view]').forEach(button => {
        button.addEventListener('click', () => {
          if (!lightbox || !lightboxImage) return;
          lightboxImage.src = button.getAttribute('data-image') || '';
          lightbox.classList.add('open');
          lightbox.setAttribute('aria-hidden', 'false');
          lightbox.querySelector('button')?.focus();
        });
      });
      lightbox?.querySelector('button')?.addEventListener('click', closeLightbox);
      lightbox?.addEventListener('click', event => {
        if (event.target === lightbox) closeLightbox();
      });

      const mainImageModal = document.getElementById('hoMainImageModal');
      const mainImagePreview = mainImageModal?.querySelector('.ho-main-image-preview img');
      const mainImagePathInput = mainImageModal?.querySelector('input[name="image_path"]');
      const mainImageFileInput = mainImageModal?.querySelector('input[name="main_image_file"]');
      mainImagePathInput?.addEventListener('input', () => {
        if (mainImagePreview) mainImagePreview.src = mainImagePathInput.value.trim() || 'img/sampleimage.png';
      });
      mainImageFileInput?.addEventListener('change', () => {
        const file = mainImageFileInput.files?.[0];
        if (file && mainImagePreview) mainImagePreview.src = URL.createObjectURL(file);
      });

      const buildGalleryRow = (value = '') => {
        const wrapper = document.createElement('div');
        wrapper.className = 'ho-gallery-row';
        wrapper.innerHTML = `
          <img src="${value || 'img/sampleimage.png'}" alt="Gallery image preview" class="ho-gallery-thumb" />
          <div class="ho-gallery-input-stack">
            <input type="text" name="gallery_paths[]" value="${value}" />
            <button type="button" class="ho-gallery-upload-inline" data-room-image-trigger>Choose image</button>
            <input type="file" name="gallery_row_files[]" accept="image/*" data-room-image-input hidden />
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

      const propertyImageModal = document.getElementById('hoRoomImageUploadModal');
      const propertyImagePicker = document.getElementById('hoRoomImagePicker');
      const propertyImageDropzone = propertyImageModal?.querySelector('[data-room-image-dropzone]');
      const propertyImageSelected = document.getElementById('hoRoomImageSelected');
      const propertyImagePreview = document.getElementById('hoRoomImageSelectedPreview');
      const propertyImageName = document.getElementById('hoRoomImageSelectedName');
      const propertyImageApply = document.getElementById('hoRoomImageUploadApply');
      const propertyImageCancel = document.getElementById('hoRoomImageUploadCancel');
      const propertyImageClose = document.getElementById('hoRoomImageUploadClose');
      const propertyImageChooseAgain = document.getElementById('hoRoomImageChooseAgain');
      let propertyImageTargetInput = null;
      let propertyImagePendingFile = null;
      let propertyImagePreviewUrl = '';

      const resetPropertyImageUpload = () => {
        propertyImagePendingFile = null;
        if (propertyImagePicker) propertyImagePicker.value = '';
        if (propertyImagePreviewUrl) URL.revokeObjectURL(propertyImagePreviewUrl);
        propertyImagePreviewUrl = '';
        if (propertyImagePreview) propertyImagePreview.removeAttribute('src');
        if (propertyImageName) propertyImageName.textContent = '';
        if (propertyImageSelected) propertyImageSelected.hidden = true;
        if (propertyImageDropzone) propertyImageDropzone.hidden = false;
        ItourImageOptimizer.setButtonBusy(propertyImageApply, false, '', true);
      };

      const closePropertyImageUpload = () => {
        propertyImageModal?.classList.remove('open');
        propertyImageModal?.setAttribute('aria-hidden', 'true');
        propertyImageTargetInput = null;
        resetPropertyImageUpload();
      };

      const openPropertyImageUpload = targetInput => {
        if (!propertyImageModal || !targetInput) return;
        resetPropertyImageUpload();
        propertyImageTargetInput = targetInput;
        propertyImageModal.classList.add('open');
        propertyImageModal.setAttribute('aria-hidden', 'false');
      };

      const selectPropertyImage = async file => {
        if (!file) return;
        ItourImageOptimizer.setButtonBusy(propertyImageApply, true, 'Optimizing image...');
        if (propertyImageName) propertyImageName.textContent = 'Optimizing image...';
        try {
          const optimizedFile = await ItourImageOptimizer.optimizeSource(file, 2400);
          if (propertyImagePreviewUrl) URL.revokeObjectURL(propertyImagePreviewUrl);
          propertyImagePendingFile = optimizedFile;
          propertyImagePreviewUrl = URL.createObjectURL(optimizedFile);
          if (propertyImagePreview) propertyImagePreview.src = propertyImagePreviewUrl;
          if (propertyImageName) propertyImageName.textContent = optimizedFile.name;
          if (propertyImageDropzone) propertyImageDropzone.hidden = true;
          if (propertyImageSelected) propertyImageSelected.hidden = false;
          ItourImageOptimizer.setButtonBusy(propertyImageApply, false);
        } catch (error) {
          if (propertyImageName) propertyImageName.textContent = '';
          ItourImageOptimizer.setButtonBusy(propertyImageApply, false, '', true);
          window.alert(error.message || 'The image could not be processed. Please try another photo.');
        }
      };

      document.addEventListener('click', event => {
        const trigger = event.target instanceof Element ? event.target.closest('[data-room-image-trigger]') : null;
        if (!trigger) return;
        const scope = trigger.closest('.ho-main-image-fields, .ho-gallery-input-stack');
        openPropertyImageUpload(scope?.querySelector('[data-room-image-input]'));
      });
      propertyImagePicker?.addEventListener('change', () => selectPropertyImage(propertyImagePicker.files?.[0]));
      propertyImageChooseAgain?.addEventListener('click', () => propertyImagePicker?.click());
      [propertyImageCancel, propertyImageClose].forEach(button => button?.addEventListener('click', closePropertyImageUpload));
      propertyImageModal?.addEventListener('click', event => {
        if (event.target === propertyImageModal) closePropertyImageUpload();
      });
      ['dragenter', 'dragover'].forEach(type => propertyImageDropzone?.addEventListener(type, event => {
        event.preventDefault();
        propertyImageDropzone.classList.add('is-dragging');
      }));
      ['dragleave', 'drop'].forEach(type => propertyImageDropzone?.addEventListener(type, event => {
        event.preventDefault();
        propertyImageDropzone.classList.remove('is-dragging');
      }));
      propertyImageDropzone?.addEventListener('drop', event => selectPropertyImage(event.dataTransfer?.files?.[0]));
      propertyImageApply?.addEventListener('click', () => {
        if (!propertyImageTargetInput || !propertyImagePendingFile) return;
        const transfer = new DataTransfer();
        transfer.items.add(propertyImagePendingFile);
        propertyImageTargetInput.files = transfer.files;
        propertyImageTargetInput.dispatchEvent(new Event('change', { bubbles: true }));
        closePropertyImageUpload();
      });

      document.querySelectorAll('form[enctype="multipart/form-data"]').forEach(form => {
        form.addEventListener('submit', event => {
          const hasImageUpload = Array.from(form.querySelectorAll('input[type="file"]')).some(input => input.files?.length);
          if (!hasImageUpload || form.dataset.imageSubmitting === 'true') return;
          event.preventDefault();
          form.dataset.imageSubmitting = 'true';
          const submitButton = form.querySelector('button[type="submit"]');
          ItourImageOptimizer.setButtonBusy(submitButton, true, 'Uploading images...');
          requestAnimationFrame(() => form.submit());
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
