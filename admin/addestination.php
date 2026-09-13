<?php
chdir(__DIR__ . '/..');
require_once __DIR__ . '/../php/session_security.php';
AppSessionStart();

require 'php/db_connection.php';
require_once __DIR__ . '/../php/admin_auth_helper.php';
require_once __DIR__ . '/../php/activity_logger.php';
require_once __DIR__ . '/../php/destination_repository.php';
require_once __DIR__ . '/../php/input_validation.php';
require_once __DIR__ . '/../php/project_path_helper.php';

AdminRequireLogin();
destinationEnsureSchema($pdo);
destinationSeedDefaults($pdo);

if (empty($_SESSION['destination_csrf'])) $_SESSION['destination_csrf'] = bin2hex(random_bytes(24));
$csrf = (string)$_SESSION['destination_csrf'];
$uploadDir = ItourEnsureProjectDirectory('uploads/destinations');

function destinationUpload(string $field, string $current = ''): string
{
    global $uploadDir;
    if (!isset($_FILES[$field]) || (int)$_FILES[$field]['error'] === UPLOAD_ERR_NO_FILE) return $current;
    $file = $_FILES[$field];
    if ((int)$file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) throw new RuntimeException('An image could not be uploaded.');
    if ((int)$file['size'] > 8 * 1024 * 1024) throw new RuntimeException('Each image must be 8 MB or smaller.');
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    $extensions = ['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'];
    if (!isset($extensions[$mime])) throw new RuntimeException('Only JPG, PNG, and WEBP images are accepted.');
    $name = $field . '_' . date('YmdHis') . '_' . bin2hex(random_bytes(4)) . '.' . $extensions[$mime];
    if (!move_uploaded_file($file['tmp_name'], $uploadDir . '/' . $name)) throw new RuntimeException('The uploaded image could not be saved.');
    return 'uploads/destinations/' . $name;
}

function destinationActivities(string $value): array
{
    if (mb_strlen($value) > 5000) throw new InvalidArgumentException('Activities must not exceed 5000 characters.');
    $parts = preg_split('/[,\r\n]+/', $value) ?: [];
    $parts = array_values(array_unique(array_filter(array_map('trim', $parts))));
    if (count($parts) > 30) throw new InvalidArgumentException('A destination may have at most 30 activities.');
    foreach ($parts as $part) {
        if (mb_strlen($part) > 100) throw new InvalidArgumentException('Each activity must not exceed 100 characters.');
    }
    return $parts;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    try {
        if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) throw new RuntimeException('Your session expired. Refresh the page and try again.');
        if ($action === 'archive') {
            $id = ItourValidationInt($_POST['destination_id'] ?? null, 'Destination ID', 1, PHP_INT_MAX);
            $pdo->prepare("UPDATE destinations SET status='archived' WHERE destination_id=?")->execute([$id]);
            logActivity($pdo, 'Admin', (int)($_SESSION['admin_id'] ?? 0), (string)($_SESSION['admin_name'] ?? 'Administrator'), 'Destination Archived', 'Archived destination #' . $id . '.', 'Destinations', $id);
            $_SESSION['destination_notice'] = ['success','Destination archived and hidden from the public website.'];
        } elseif ($action === 'toggle') {
            $id = ItourValidationInt($_POST['destination_id'] ?? null, 'Destination ID', 1, PHP_INT_MAX);
            $statusStatement = $pdo->prepare('SELECT status FROM destinations WHERE destination_id=?');
            $statusStatement->execute([$id]);
            $newStatus = $statusStatement->fetchColumn() === 'archived' ? 'published' : 'archived';
            $pdo->prepare('UPDATE destinations SET status=? WHERE destination_id=?')->execute([$newStatus, $id]);
            $_SESSION['destination_notice'] = $newStatus === 'archived'
                ? ['success','Destination archived and hidden from the public website.']
                : ['success','Destination published successfully.'];
        } elseif ($action === 'duplicate') {
            $id = ItourValidationInt($_POST['destination_id'] ?? null, 'Destination ID', 1, PHP_INT_MAX);
            $stmt = $pdo->prepare('SELECT * FROM destinations WHERE destination_id=?');
            $stmt->execute([$id]);
            $source = $stmt->fetch();
            if (!$source) throw new RuntimeException('Destination not found.');
            $slug = destinationSlug($source['slug'] . '-copy-' . time());
            $copy = $pdo->prepare("INSERT INTO destinations (slug,title,tagline,description,destination_type,location,latitude,longitude,activities,card_image,hero_image,status,is_featured,sort_order) VALUES (?,?,?,?,?,?,?,?,?,?,?,'archived',0,?)");
            $copy->execute([$slug,$source['title'] . ' (Copy)',$source['tagline'],$source['description'],$source['destination_type'],$source['location'],$source['latitude'],$source['longitude'],$source['activities'],$source['card_image'],$source['hero_image'],(int)$source['sort_order'] + 1]);
            $newId = (int)$pdo->lastInsertId();
            $pdo->prepare('INSERT INTO destination_gallery (destination_id,image_path,alt_text,sort_order) SELECT ?,image_path,alt_text,sort_order FROM destination_gallery WHERE destination_id=?')->execute([$newId,$id]);
            $_SESSION['destination_notice'] = ['success','An archived copy was created.'];
        } elseif ($action === 'save') {
            $rawId = $_POST['destination_id'] ?? '';
            $id = ($rawId === '' || $rawId === '0') ? 0 : ItourValidationInt($rawId, 'Destination ID', 1, PHP_INT_MAX);
            $title = ItourValidationText($_POST['title'] ?? null, 'Destination name', 180, true);
            $description = ItourValidationText($_POST['description'] ?? null, 'Destination description', 10000, true);
            $tagline = ItourValidationText($_POST['tagline'] ?? '', 'Destination tagline', 255);
            $destinationType = ItourValidationText($_POST['destination_type'] ?? 'Island escape', 'Destination type', 80, true);
            $location = ItourValidationText($_POST['location'] ?? 'Mercedes, Camarines Norte', 'Destination location', 255, true);
            $latitude = ($_POST['latitude'] ?? '') === '' ? null : ItourValidationDecimal($_POST['latitude'], 'Latitude', -90, 90);
            $longitude = ($_POST['longitude'] ?? '') === '' ? null : ItourValidationDecimal($_POST['longitude'], 'Longitude', -180, 180);
            $sortOrder = ItourValidationInt($_POST['sort_order'] ?? 0, 'Display order', 0, 10000);
            $status = strtolower(ItourValidationText($_POST['status'] ?? 'published', 'Destination status', 20, true));
            if (!in_array($status, ['published', 'archived'], true)) {
                throw new InvalidArgumentException('Destination status must be published or archived.');
            }
            $activities = destinationActivities(ItourValidationText($_POST['activities'] ?? '', 'Activities', 5000));
            foreach (['card_image_current', 'hero_image_current'] as $pathField) {
                $currentPath = (string)($_POST[$pathField] ?? '');
                if ($currentPath !== '' && !preg_match('#^uploads/destinations/[A-Za-z0-9._-]+$#D', $currentPath)) {
                    throw new InvalidArgumentException('Invalid destination image path.');
                }
            }
            $keepGalleryRaw = $_POST['keep_gallery'] ?? [];
            if (!is_array($keepGalleryRaw) || count($keepGalleryRaw) > 50) {
                throw new InvalidArgumentException('Existing gallery selections must be a valid list of at most 50 images.');
            }
            $keepGallery = [];
            foreach ($keepGalleryRaw as $galleryId) {
                $keepGallery[] = ItourValidationInt($galleryId, 'Gallery image ID', 1, PHP_INT_MAX);
            }
            if (count($keepGallery) !== count(array_unique($keepGallery))) {
                throw new InvalidArgumentException('Existing gallery selections must not contain duplicates.');
            }
            if ($keepGallery) {
                if ($id < 1) throw new InvalidArgumentException('A new destination cannot retain existing gallery images.');
                $marks = implode(',', array_fill(0, count($keepGallery), '?'));
                $ownedGallery = $pdo->prepare("SELECT COUNT(*) FROM destination_gallery WHERE destination_id=? AND gallery_id IN ($marks)");
                $ownedGallery->execute(array_merge([$id], $keepGallery));
                if ((int)$ownedGallery->fetchColumn() !== count($keepGallery)) {
                    throw new InvalidArgumentException('One or more selected gallery images do not belong to this destination.');
                }
            }
            if (isset($_FILES['gallery_images'])) {
                foreach (['name', 'type', 'tmp_name', 'error', 'size'] as $uploadKey) {
                    if (!isset($_FILES['gallery_images'][$uploadKey]) || !is_array($_FILES['gallery_images'][$uploadKey])) {
                        throw new InvalidArgumentException('Gallery uploads must be submitted as a valid file list.');
                    }
                }
                $galleryFileCount = count($_FILES['gallery_images']['name']);
                if ($galleryFileCount > 20) throw new InvalidArgumentException('Upload at most 20 gallery images at a time.');
                foreach (['type', 'tmp_name', 'error', 'size'] as $uploadKey) {
                    if (count($_FILES['gallery_images'][$uploadKey]) !== $galleryFileCount) {
                        throw new InvalidArgumentException('Gallery upload fields must have matching item counts.');
                    }
                }
            }
            $slug = destinationSlug((string)($_POST['slug'] ?? $title));
            $check = $pdo->prepare('SELECT COUNT(*) FROM destinations WHERE slug=? AND destination_id<>?');
            $check->execute([$slug,$id]);
            if ((int)$check->fetchColumn()) $slug .= '-' . substr(bin2hex(random_bytes(3)), 0, 6);
            $cardImage = destinationUpload('card_image', (string)($_POST['card_image_current'] ?? ''));
            $heroImage = destinationUpload('hero_image', (string)($_POST['hero_image_current'] ?? ''));
            if ($cardImage === '' || $heroImage === '') throw new RuntimeException('A separate card image and hero cover image are both required.');
            $values = [$slug,$title,$tagline,$description,$destinationType,$location,$latitude,$longitude,json_encode($activities, JSON_UNESCAPED_UNICODE),$cardImage,$heroImage,$status,isset($_POST['is_featured']) ? 1 : 0,$sortOrder];
            $pdo->beginTransaction();
            if ($id > 0) {
                $stmt = $pdo->prepare('UPDATE destinations SET slug=?,title=?,tagline=?,description=?,destination_type=?,location=?,latitude=?,longitude=?,activities=?,card_image=?,hero_image=?,status=?,is_featured=?,sort_order=? WHERE destination_id=?');
                $stmt->execute(array_merge($values, [$id]));
            } else {
                $stmt = $pdo->prepare('INSERT INTO destinations (slug,title,tagline,description,destination_type,location,latitude,longitude,activities,card_image,hero_image,status,is_featured,sort_order) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
                $stmt->execute($values);
                $id = (int)$pdo->lastInsertId();
            }
            if (!empty($_POST['existing_gallery_present'])) {
                if ($keepGallery) {
                    $marks = implode(',', array_fill(0, count($keepGallery), '?'));
                    $pdo->prepare("DELETE FROM destination_gallery WHERE destination_id=? AND gallery_id NOT IN ($marks)")->execute(array_merge([$id], $keepGallery));
                } else $pdo->prepare('DELETE FROM destination_gallery WHERE destination_id=?')->execute([$id]);
            }
            if (isset($_FILES['gallery_images']['name']) && is_array($_FILES['gallery_images']['name'])) {
                $orderStmt = $pdo->prepare('SELECT COALESCE(MAX(sort_order),0)+1 FROM destination_gallery WHERE destination_id=?');
                $orderStmt->execute([$id]);
                $order = (int)$orderStmt->fetchColumn();
                foreach ($_FILES['gallery_images']['name'] as $index => $unused) {
                    if ((int)$_FILES['gallery_images']['error'][$index] === UPLOAD_ERR_NO_FILE) continue;
                    $_FILES['single_gallery'] = ['name'=>$_FILES['gallery_images']['name'][$index],'type'=>$_FILES['gallery_images']['type'][$index],'tmp_name'=>$_FILES['gallery_images']['tmp_name'][$index],'error'=>$_FILES['gallery_images']['error'][$index],'size'=>$_FILES['gallery_images']['size'][$index]];
                    $path = destinationUpload('single_gallery');
                    $pdo->prepare('INSERT INTO destination_gallery (destination_id,image_path,alt_text,sort_order) VALUES (?,?,?,?)')->execute([$id,$path,$title . ' gallery photo',$order++]);
                }
            }
            $pdo->commit();
            logActivity($pdo, 'Admin', (int)($_SESSION['admin_id'] ?? 0), (string)($_SESSION['admin_name'] ?? 'Administrator'), !empty($_POST['destination_id']) ? 'Destination Updated' : 'Destination Added', (!empty($_POST['destination_id']) ? 'Updated' : 'Added') . ' destination "' . $title . '".', 'Destinations', $id);
            $_SESSION['destination_notice'] = ['success','Destination saved successfully.'];
        }
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $_SESSION['destination_notice'] = ['error',$error->getMessage() ?: 'The destination could not be saved.'];
    }
    header('Location: addestination.php');
    exit;
}

$destinations = destinationRows($pdo);
$destinationPayload = [];
foreach ($destinations as $destination) {
    $destination['gallery'] = destinationGallery($pdo, (int)$destination['destination_id']);
    $destination['activities_text'] = implode(', ', json_decode((string)$destination['activities'], true) ?: []);
    $destinationPayload[(int)$destination['destination_id']] = $destination;
}
$notice = $_SESSION['destination_notice'] ?? null;
unset($_SESSION['destination_notice']);
$publishedCount = count(array_filter($destinations, fn($item) => $item['status'] === 'published'));
$archivedCount = count(array_filter($destinations, fn($item) => $item['status'] === 'archived'));
$featuredCount = count(array_filter($destinations, fn($item) => $item['status'] === 'published' && (int)$item['is_featured'] === 1));
$destinationTypes = array_values(array_unique(array_filter(array_map(fn($item) => trim((string)$item['destination_type']), $destinations))));
natcasesort($destinationTypes);
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
?>
<!DOCTYPE html><html lang="en"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Destinations | iTour Mercedes Admin</title>
<link rel="icon" href="img/newlogo.png"><link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet"><link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/cropperjs@1.6.2/dist/cropper.min.css"><link rel="stylesheet" href="styles/admin_panel_theme.css"><link rel="stylesheet" href="styles/admin_destinations.css?v=<?= (int)@filemtime('styles/admin_destinations.css') ?>"><script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script><script src="https://cdn.jsdelivr.net/npm/cropperjs@1.6.2/dist/cropper.min.js"></script>
</head><body><div class="admin-container"><?php include __DIR__ . '/admin_sidebar.php'; ?><main class="main-content destination-admin-main">
<header class="admin-header admin-page-header"><div class="admin-header-left admin-page-title"><span class="admin-page-title-icon"><svg viewBox="0 0 24 24"><path d="m3 6 6-3 6 3 6-3v15l-6 3-6-3-6 3V6Z"/><path d="M9 3v15M15 6v15"/></svg></span><div class="admin-page-title-copy"><h2>Destination Management</h2><p class="admin-header-subtitle">Manage destination listings and tourism information</p></div></div><div class="admin-header-right"><button class="primary-action destination-header-add" type="button" data-open-editor="new"><svg viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg>Add destination</button></div></header>
<div class="destination-workspace">
<section class="destination-metrics" aria-label="Destination overview"><article><span class="metric-icon"><svg viewBox="0 0 24 24"><path d="M4 5h16v14H4z"/><path d="m4 15 4-4 3 3 3-4 6 7"/></svg></span><div><strong><?= count($destinations) ?></strong><span>Total destinations</span></div></article><article><span class="metric-icon"><svg viewBox="0 0 24 24"><path d="M20 6 9 17l-5-5"/></svg></span><div><strong><?= $publishedCount ?></strong><span>Published</span></div></article><article><span class="metric-icon"><svg viewBox="0 0 24 24"><path d="M5 7h14M9 7V5h6v2m-8 0 1 12h8l1-12M10 11v5m4-5v5"/></svg></span><div><strong><?= $archivedCount ?></strong><span>Total archived</span></div></article><article><span class="metric-icon"><svg viewBox="0 0 24 24"><path d="m12 3 2.8 5.7 6.2.9-4.5 4.4 1.1 6.2-5.6-3-5.6 3 1.1-6.2L3 9.6l6.2-.9L12 3Z"/></svg></span><div><strong><?= $featuredCount ?></strong><span>Featured places</span></div></article></section>
<section class="destination-panel"><header class="panel-head"><div><h2>Destination directory</h2><p>Card thumbnails and large hero covers are managed separately.</p></div><div class="directory-tools"><label class="directory-select-filter directory-status-filter"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 5h16l-6.2 7.1V18l-3.6 1.8v-7.7L4 5Z"/></svg><select id="destinationStatusFilter" aria-label="Filter by status"><option value="all">All statuses</option><option value="published">Published</option><option value="archived">Archived</option><option value="featured">Featured</option></select></label><label class="directory-select-filter directory-type-filter"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m12 3 8 4-8 4-8-4 8-4Z"/><path d="m4 12 8 4 8-4M4 17l8 4 8-4"/></svg><select id="destinationTypeFilter" aria-label="Filter by destination type"><option value="all">All types</option><?php foreach ($destinationTypes as $type): ?><option value="<?= htmlspecialchars(strtolower($type)) ?>"><?= htmlspecialchars($type) ?></option><?php endforeach; ?></select></label><label class="directory-search"><svg viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><path d="m16 16 5 5"/></svg><input id="destinationFilter" type="search" placeholder="Search destinations..."></label></div></header><div class="destination-grid" id="destinationGrid">
<?php foreach ($destinations as $destination): ?><article class="destination-admin-card" data-status="<?= htmlspecialchars($destination['status']) ?>" data-type="<?= htmlspecialchars(strtolower(trim((string)$destination['destination_type']))) ?>" data-featured="<?= (int)$destination['is_featured'] ?>" data-search="<?= htmlspecialchars(strtolower($destination['title'] . ' ' . $destination['tagline'] . ' ' . $destination['destination_type'] . ' ' . $destination['status'])) ?>"><div class="destination-card-media"><img src="<?= htmlspecialchars($destination['card_image']) ?>" alt=""><span class="status-pill <?= $destination['status'] ?>"><?= htmlspecialchars(ucfirst($destination['status'])) ?></span><?php if ((int)$destination['is_featured']): ?><span class="featured-pill">Featured</span><?php endif; ?></div><div class="destination-card-body"><div class="card-title-row"><div><span class="destination-type-badge"><?= htmlspecialchars($destination['destination_type']) ?></span><h3><?= htmlspecialchars($destination['title']) ?></h3></div><b>#<?= str_pad((string)$destination['sort_order'], 2, '0', STR_PAD_LEFT) ?></b></div><p><?= htmlspecialchars($destination['tagline'] ?: 'No tagline added') ?></p><div class="card-meta"><span><?= (int)$destination['gallery_count'] ?> gallery photos</span><span><?= count(json_decode((string)$destination['activities'], true) ?: []) ?> activities</span></div></div><footer class="destination-card-actions"><button type="button" class="edit-action" data-edit-id="<?= (int)$destination['destination_id'] ?>">Edit content</button><form method="post"><input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>"><input type="hidden" name="action" value="toggle"><input type="hidden" name="destination_id" value="<?= (int)$destination['destination_id'] ?>"><button class="visibility-action" title="<?= $destination['status'] === 'archived' ? 'Publish destination' : 'Archive destination' ?>" aria-label="<?= $destination['status'] === 'archived' ? 'Publish destination' : 'Archive destination' ?>"><svg viewBox="0 0 24 24"><?php if ($destination['status'] === 'archived'): ?><path d="M3 3l18 18M10.6 6.2A10.7 10.7 0 0 1 12 6c6 0 9.5 6 9.5 6s-.8 1.4-2.3 2.9M6.1 6.1C3.8 7.8 2.5 12 2.5 12s3.5 6 9.5 6a10.7 10.7 0 0 0 3.4-.5M9.9 9.9a3 3 0 0 0 4.2 4.2"/><?php else: ?><path d="M2.5 12s3.5-6 9.5-6 9.5 6 9.5 6-3.5 6-9.5 6-9.5-6-9.5-6Z"/><circle cx="12" cy="12" r="2.5"/><?php endif; ?></svg></button></form><button type="button" class="more-action" data-menu-id="<?= (int)$destination['destination_id'] ?>" aria-label="More actions"><svg viewBox="0 0 24 24"><circle cx="5" cy="12" r="1.5"/><circle cx="12" cy="12" r="1.5"/><circle cx="19" cy="12" r="1.5"/></svg></button><div class="card-menu" data-card-menu="<?= (int)$destination['destination_id'] ?>"><form method="post"><input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>"><input type="hidden" name="action" value="duplicate"><input type="hidden" name="destination_id" value="<?= (int)$destination['destination_id'] ?>"><button>Duplicate as archived</button></form><?php if ($destination['status'] !== 'archived'): ?><button type="button" class="archive-action" data-archive-id="<?= (int)$destination['destination_id'] ?>" data-archive-name="<?= htmlspecialchars($destination['title']) ?>">Archive destination</button><?php endif; ?></div></footer></article><?php endforeach; ?>
</div><div class="destination-empty" id="destinationEmpty" <?= $destinations ? 'hidden' : '' ?>><strong>No destinations yet</strong><p>Add your first destination to start building the public directory.</p></div></section></div></main></div>
<dialog class="destination-editor" id="destinationEditor"><form method="post" enctype="multipart/form-data" id="destinationForm"><input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>"><input type="hidden" name="action" value="save"><input type="hidden" name="destination_id" id="destinationId"><input type="hidden" name="card_image_current" id="cardImageCurrent"><input type="hidden" name="hero_image_current" id="heroImageCurrent"><input type="hidden" name="existing_gallery_present" value="1">
<header class="editor-head"><div><span class="editor-icon"><svg viewBox="0 0 24 24"><path d="m3 6 6-3 6 3 6-3v15l-6 3-6-3-6 3V6Z"/></svg></span><div><small id="editorEyebrow">DESTINATION EDITOR</small><h2 id="editorTitle">Add destination</h2><p id="editorSubtitle">Build the card and full visitor-facing destination story.</p></div></div><button type="button" data-close-editor aria-label="Close">×</button></header>
<div class="editor-body"><nav class="editor-tabs" aria-label="Editor sections"><button type="button" class="active" data-tab="details"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 4h16v16H4z"/><path d="M8 9h8M8 13h8M8 17h5"/></svg><span>Details</span></button><button type="button" data-tab="media"><svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2"/><circle cx="9" cy="10" r="2"/><path d="m4 17 5-4 3 2 3-4 5 6"/></svg><span>Media</span></button><button type="button" data-tab="location"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20 10c0 5-8 11-8 11S4 15 4 10a8 8 0 1 1 16 0Z"/><circle cx="12" cy="10" r="2.5"/></svg><span>Location &amp; activities</span></button><button type="button" data-tab="publishing"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3v12M7 8l5-5 5 5"/><path d="M5 13v7h14v-7"/></svg><span>Publishing</span></button></nav>
<section class="editor-pane active" data-pane="details"><div class="field-grid"><label class="field span-2"><span>Destination name <em class="required-mark">*</em></span><input name="title" id="fieldTitle" required maxlength="180" placeholder="e.g. Apuao Grande Island"></label><label class="field"><span>URL slug</span><input name="slug" id="fieldSlug" maxlength="160" placeholder="Generated from name"></label><label class="field"><span>Destination type</span><select name="destination_type" id="fieldType"><option>Island escape</option><option>Beach</option><option>Heritage landmark</option><option>Nature attraction</option><option>Local landmark</option></select></label><label class="field span-2"><span>Tagline</span><input name="tagline" id="fieldTagline" maxlength="255" placeholder="A short, inviting line for the card"></label><label class="field span-2"><span>About this destination <em class="required-mark">*</em></span><textarea name="description" id="fieldDescription" rows="8" required placeholder="Tell visitors what makes this place special..."></textarea><small><b id="descriptionCount">0</b> characters</small></label></div></section>
<section class="editor-pane" data-pane="media"><div class="media-note"><strong>Use two purpose-specific images</strong><p>The compact card image is intentionally separate from the wide hero cover used on the destination details page.</p></div><div class="upload-grid"><div class="image-upload"><span>Card profile image <em class="required-mark">*</em></span><div class="image-preview card-preview"><img id="cardPreview" hidden><div><svg viewBox="0 0 24 24"><path d="M4 5h16v14H4z"/><path d="m4 15 4-4 3 3 3-4 6 7"/></svg><strong>Choose card image</strong><small>Recommended 4:3 · JPG, PNG or WEBP</small></div><button class="change-photo" type="button" data-media-trigger="card"><svg viewBox="0 0 24 24"><path d="M4 16v4h4M20 8V4h-4M5.5 9a7 7 0 0 1 11-3M18.5 15a7 7 0 0 1-11 3"/></svg><span class="empty-label">Choose photo</span><span class="filled-label">Change photo</span></button></div><input type="file" name="card_image" id="cardImage" accept="image/jpeg,image/png,image/webp" hidden></div><div class="image-upload"><span>Hero cover image <em class="required-mark">*</em></span><div class="image-preview hero-preview"><img id="heroPreview" hidden><div><svg viewBox="0 0 24 24"><path d="M4 5h16v14H4z"/><path d="m4 15 4-4 3 3 3-4 6 7"/></svg><strong>Choose wide cover</strong><small>Preserves banner ratio · JPG, PNG or WEBP</small></div><button class="change-photo" type="button" data-media-trigger="hero"><svg viewBox="0 0 24 24"><path d="M4 16v4h4M20 8V4h-4M5.5 9a7 7 0 0 1 11-3M18.5 15a7 7 0 0 1-11 3"/></svg><span class="empty-label">Choose photo</span><span class="filled-label">Change photo</span></button></div><input type="file" name="hero_image" id="heroImage" accept="image/jpeg,image/png,image/webp" hidden></div></div><div class="gallery-manager"><div><h3>Destination gallery</h3><p>Add supporting photos. Uncheck an existing photo to remove it when saving.</p></div><div class="existing-gallery" id="existingGallery"></div><button class="gallery-add" type="button" data-media-trigger="gallery"><svg viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg><span>Add gallery photo</span></button><input type="file" name="gallery_images[]" id="galleryImages" accept="image/jpeg,image/png,image/webp" multiple hidden><div class="new-gallery" id="newGallery"></div></div></section>
<section class="editor-pane" data-pane="location"><div class="field-grid"><label class="field span-2"><span>Display location</span><input name="location" id="fieldLocation" value="Mercedes, Camarines Norte"></label><label class="field"><span>Latitude</span><input type="number" name="latitude" id="fieldLatitude" step="0.0000001" min="-90" max="90" placeholder="14.0851907"></label><label class="field"><span>Longitude</span><input type="number" name="longitude" id="fieldLongitude" step="0.0000001" min="-180" max="180" placeholder="123.0908549"></label><label class="field span-2"><span>Activities</span><textarea name="activities" id="fieldActivities" rows="7" placeholder="Swimming, Kayaking, Photography"></textarea><small>Separate activities with commas or new lines.</small></label></div></section>
<section class="editor-pane" data-pane="publishing"><div class="publish-layout"><label class="field"><span>Status</span><select name="status" id="fieldStatus"><option value="published">Published — visible publicly</option><option value="archived">Archived — hidden from visitors</option></select></label><label class="field"><span>Display order</span><input type="number" name="sort_order" id="fieldOrder" min="0" value="0"><small>Lower numbers appear first.</small></label><label class="check-card"><input type="checkbox" name="is_featured" id="fieldFeatured" value="1"><span><strong>Feature this destination</strong><small>Marks this place as a highlighted tourism destination.</small></span></label></div></section></div>
<footer class="editor-footer"><span>Fields marked <em class="required-mark">*</em> are required.</span><div><button type="button" class="secondary-action" data-close-editor>Cancel</button><button type="button" class="secondary-action wizard-action" id="editorPrevious" hidden>Previous</button><button type="button" class="primary-action wizard-action" id="editorNext" hidden>Next step</button><button type="submit" class="primary-action" id="editorSave">Save destination</button></div></footer></form></dialog>
<dialog class="media-picker" id="mediaPicker"><div class="media-picker-shell"><header class="media-picker-head"><div><small id="mediaPickerEyebrow">DESTINATION MEDIA</small><h2 id="mediaPickerTitle">Choose a photo</h2><p id="mediaPickerSubtitle">Upload a clear JPG, PNG, or WEBP image.</p></div><button type="button" data-close-media aria-label="Close">×</button></header><section class="media-select-step" id="mediaSelectStep"><button class="media-dropzone" id="mediaDropzone" type="button"><svg viewBox="0 0 24 24"><path d="M12 16V4m0 0L8 8m4-4 4 4M5 15v4h14v-4"/></svg><strong>Drag your image here</strong><span>or tap to select from your files</span><small>JPG, PNG or WEBP · Maximum 8 MB</small></button><input type="file" id="mediaSourceInput" accept="image/jpeg,image/png,image/webp" hidden></section><section class="media-crop-step" id="mediaCropStep" hidden><div class="crop-stage" id="cropStage"><img id="mediaCropImage" alt="Image to crop"></div><div class="crop-tools" aria-label="Crop tools"><button type="button" data-crop-action="zoom-in" title="Zoom in">＋</button><button type="button" data-crop-action="zoom-out" title="Zoom out">−</button><button type="button" data-crop-action="rotate-left" title="Rotate left">↶</button><button type="button" data-crop-action="rotate-right" title="Rotate right">↷</button><button type="button" data-crop-action="reset">Reset</button></div><p class="crop-hint" id="cropHint">Move and resize the image inside the crop area.</p></section><footer class="media-picker-footer"><button type="button" class="secondary-action" id="mediaBackButton" hidden>Choose another</button><div><button type="button" class="secondary-action" data-close-media>Cancel</button><button type="button" class="primary-action" id="applyCropButton" hidden>Use cropped photo</button></div></footer></div></dialog>
<form method="post" id="archiveForm" hidden><input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>"><input type="hidden" name="action" value="archive"><input type="hidden" name="destination_id" id="archiveId"></form>
<script>window.destinationAdminData=<?= json_encode($destinationPayload, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;window.destinationNotice=<?= json_encode($notice, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;</script><script src="js/admin_destinations.js?v=<?= (int)@filemtime('js/admin_destinations.js') ?>"></script></body></html>
