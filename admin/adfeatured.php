<?php
chdir(__DIR__ . '/..');
// adfeatured.php
require_once 'php/db_connection.php';
require_once __DIR__ . '/../php/activity_logger.php';
require_once __DIR__ . '/../php/admin_auth_helper.php';
require_once __DIR__ . '/../php/session_security.php';
require_once __DIR__ . '/../php/input_validation.php';
require_once __DIR__ . '/../php/secure_upload_helper.php';
AppSessionStart();

// --- Logout action
if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    AppDestroySession();
    echo "<script>alert('You have been logged out. Session expired.'); window.location.href='homepage.php';</script>";
    exit();
}

// --- Auth check
AdminRequireLogin();
$adminContentCsrf = AppCsrfToken('admin', 'catalog_content');
include 'php/alert.php';

// Prevent cache
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

function saveFeaturedMedia(string $fileField, string $mediaType): string {
    $file = $_FILES[$fileField] ?? null;
    if (!is_array($file)) throw new InvalidArgumentException('No file was uploaded.');

    $targetDir = ItourEnsureProjectDirectory('uploads/featured');

    if ($mediaType === 'image') {
        $validated = ItourSecureValidateUploadedImage($file, 8 * 1024 * 1024);
        $filename = ItourSecureRandomFilename('featured', (string)$validated['extension']);
        $absoluteTarget = $targetDir . DIRECTORY_SEPARATOR . $filename;
        ItourSecureReencodeImageFile($validated, $absoluteTarget);
        return 'uploads/featured/' . $filename;
    }

    if ((int)($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
        || !is_uploaded_file((string)($file['tmp_name'] ?? ''))) {
        throw new InvalidArgumentException('The selected video could not be uploaded.');
    }
    $declaredSize = (int)($file['size'] ?? 0);
    $actualSize = @filesize((string)$file['tmp_name']);
    if ($actualSize === false || $actualSize < 1 || $actualSize > 50 * 1024 * 1024
        || $declaredSize < 1 || $declaredSize > 50 * 1024 * 1024) {
        throw new InvalidArgumentException('Featured videos must be 50 MB or smaller.');
    }
    $allowedVideos = ['video/mp4' => 'mp4', 'video/webm' => 'webm'];
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file((string)$file['tmp_name']);
    if (!is_string($mime) || !isset($allowedVideos[$mime])) {
        throw new InvalidArgumentException('Only genuine MP4 and WebM videos are accepted.');
    }
    $filename = ItourSecureRandomFilename('featured_video', $allowedVideos[$mime]);
    if (!move_uploaded_file((string)$file['tmp_name'], $targetDir . DIRECTORY_SEPARATOR . $filename)) {
        throw new RuntimeException('The validated video could not be saved.');
    }
    return 'uploads/featured/' . $filename;
}

// ===== POST actions =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!AppVerifyCsrf('admin', 'catalog_content', $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null))) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Your session expired. Refresh the page and try again.']);
        exit;
    }
    $action = $_POST['action'];

    // --- FAQ Handling
    if ($action === 'update_faq') {
        try {
            $id = ItourValidationInt($_POST['id'] ?? null, 'FAQ ID', 1, PHP_INT_MAX);
            $question = ItourValidationText($_POST['question'] ?? null, 'FAQ question', 500, true);
            $answer = ItourValidationText($_POST['answer'] ?? null, 'FAQ answer', 5000, true);
        } catch (InvalidArgumentException $exception) {
            http_response_code(422); echo json_encode(['success'=>false,'message'=>$exception->getMessage()]); exit;
        }

        $stmt = $pdo->prepare("UPDATE faqs SET question=:q, answer=:a WHERE id=:id");
        $success = $stmt->execute([':q'=>$question, ':a'=>$answer, ':id'=>$id]);
        if ($success) {
            logActivity(
                $pdo, 'Admin', (int)($_SESSION['admin_id'] ?? 0),
                (string)($_SESSION['admin_name'] ?? 'Administrator'),
                'FAQ Updated', 'Updated FAQ #' . $id . '.', 'Website Content', $id
            );
        }
        echo json_encode(['success'=>$success]);
        exit;
    }

    if ($action === 'add_faq') {
        try {
            $question = ItourValidationText($_POST['question'] ?? null, 'FAQ question', 500, true);
            $answer = ItourValidationText($_POST['answer'] ?? null, 'FAQ answer', 5000, true);
            if ((int)$pdo->query('SELECT COUNT(*) FROM faqs')->fetchColumn() >= 100) {
                throw new InvalidArgumentException('A maximum of 100 FAQs is allowed.');
            }
        } catch (InvalidArgumentException $exception) {
            http_response_code(422); echo json_encode(['success'=>false,'message'=>$exception->getMessage()]); exit;
        }

        $stmt = $pdo->prepare("INSERT INTO faqs (question, answer) VALUES (:q,:a)");
        $success = $stmt->execute([':q'=>$question, ':a'=>$answer]);
        $faqId = (int)$pdo->lastInsertId();
        if ($success) {
            logActivity(
                $pdo, 'Admin', (int)($_SESSION['admin_id'] ?? 0),
                (string)($_SESSION['admin_name'] ?? 'Administrator'),
                'FAQ Added', 'Added a new frequently asked question.', 'Website Content', $faqId
            );
        }
        echo json_encode(['success'=>$success, 'id'=>$faqId]);
        exit;
    }

    // --- Featured Handling
    // Fetch latest featured or default
    $stmt = $pdo->query("SELECT * FROM featured_section ORDER BY id DESC LIMIT 1");
    $featured = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$featured) {
        $pdo->exec("INSERT INTO featured_section 
            (description1, description2, footer_text, video_path, slider_image1, slider_image2, slider_image3, slider_image4, small_image1, small_image2)
            VALUES ('', '', '', 'img/samplevideo.mp4', 'img/sampleimage.png', 'img/sampleimage.png', 'img/sampleimage.png', 'img/sampleimage.png', 'img/sampleimage.png', 'img/sampleimage.png')");
        $stmt = $pdo->query("SELECT * FROM featured_section ORDER BY id DESC LIMIT 1");
        $featured = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    // Base array
    $base = [
        'description1'=>$featured['description1'],
        'description2'=>$featured['description2'],
        'footer_text'=>$featured['footer_text'],
        'video_path'=>$featured['video_path'],
        'slider_image1'=>$featured['slider_image1'],
        'slider_image2'=>$featured['slider_image2'],
        'slider_image3'=>$featured['slider_image3'],
        'slider_image4'=>$featured['slider_image4'],
        'small_image1'=>$featured['small_image1'],
        'small_image2'=>$featured['small_image2'],
    ];

    switch($action) {

        case 'update_text':
            $field = $_POST['field'] ?? '';
            try {
                $value = ItourValidationText($_POST['value'] ?? '', 'Featured text', 5000);
            } catch (InvalidArgumentException $exception) {
                http_response_code(422); echo json_encode(['success'=>false,'message'=>$exception->getMessage()]); exit;
            }

            if (!in_array($field, ['description1','description2','footer_text'])) {
                echo json_encode(['success'=>false,'message'=>'Invalid text field.']);
                exit;
            }

            $base[$field] = $value;

            $stmt = $pdo->prepare("INSERT INTO featured_section
                (description1, description2, footer_text, video_path, slider_image1, slider_image2, slider_image3, slider_image4, small_image1, small_image2)
                VALUES (:d1,:d2,:ft,:vid,:s1,:s2,:s3,:s4,:sm1,:sm2)");
            $success = $stmt->execute([
                ':d1'=>$base['description1'], ':d2'=>$base['description2'], ':ft'=>$base['footer_text'],
                ':vid'=>$base['video_path'], ':s1'=>$base['slider_image1'], ':s2'=>$base['slider_image2'],
                ':s3'=>$base['slider_image3'], ':s4'=>$base['slider_image4'], ':sm1'=>$base['small_image1'], ':sm2'=>$base['small_image2']
            ]);
            if ($success) {
                logActivity(
                    $pdo, 'Admin', (int)($_SESSION['admin_id'] ?? 0),
                    (string)($_SESSION['admin_name'] ?? 'Administrator'),
                    'Featured Content Updated',
                    'Updated the featured section ' . str_replace('_', ' ', $field) . '.',
                    'Website Content', (int)$pdo->lastInsertId()
                );
            }
            echo json_encode(['success'=>$success,'message'=>'Text updated.']);
            exit;

        case 'update_media':
            $media_type = $_POST['media_type'] ?? '';
            $media_slot = $_POST['media_slot'] ?? '';

            $allowed_image_slots = ['slider1','slider2','slider3','slider4','small1','small2'];
            $allowed_video_slots = ['video'];

            if (($media_type==='image' && in_array($media_slot,$allowed_image_slots)) || 
                ($media_type==='video' && in_array($media_slot,$allowed_video_slots))) {

                $fileField = 'file';
                if (!isset($_FILES[$fileField]) || !is_array($_FILES[$fileField])) {
                    echo json_encode(['success'=>false,'message'=>'No file uploaded.']);
                    exit;
                }

                try {
                    $targetFile = saveFeaturedMedia($fileField, $media_type);
                } catch (Throwable $exception) {
                    http_response_code($exception instanceof InvalidArgumentException ? 422 : 500);
                    echo json_encode(['success'=>false,'message'=>$exception->getMessage()]);
                    exit;
                }

                if ($media_type==='image') {
                    $map = ['slider1'=>'slider_image1','slider2'=>'slider_image2','slider3'=>'slider_image3','slider4'=>'slider_image4',
                            'small1'=>'small_image1','small2'=>'small_image2'];
                    $base[$map[$media_slot]] = $targetFile;
                } else {
                    $base['video_path'] = $targetFile;
                }

                $stmt = $pdo->prepare("INSERT INTO featured_section
                    (description1, description2, footer_text, video_path, slider_image1, slider_image2, slider_image3, slider_image4, small_image1, small_image2)
                    VALUES (:d1,:d2,:ft,:vid,:s1,:s2,:s3,:s4,:sm1,:sm2)");
                $success = $stmt->execute([
                    ':d1'=>$base['description1'], ':d2'=>$base['description2'], ':ft'=>$base['footer_text'],
                    ':vid'=>$base['video_path'], ':s1'=>$base['slider_image1'], ':s2'=>$base['slider_image2'],
                    ':s3'=>$base['slider_image3'], ':s4'=>$base['slider_image4'], ':sm1'=>$base['small_image1'], ':sm2'=>$base['small_image2']
                ]);
                if ($success) {
                    logActivity(
                        $pdo, 'Admin', (int)($_SESSION['admin_id'] ?? 0),
                        (string)($_SESSION['admin_name'] ?? 'Administrator'),
                        'Featured Media Updated',
                        'Updated the featured section ' . $media_slot . ' media.',
                        'Website Content', (int)$pdo->lastInsertId()
                    );
                }

                echo json_encode(['success'=>$success,'message'=>($media_type==='image')?'Image updated.':'Video updated.','path'=>$targetFile]);
                exit;

            } else {
                echo json_encode(['success'=>false,'message'=>'Invalid media type or slot.']);
                exit;
            }

        default:
            echo json_encode(['success'=>false,'message'=>'Invalid action.']);
            exit;
    }
}

// ===== Fetch data for page load =====
$faqs = $pdo->query("SELECT * FROM faqs ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC) ?? [];
$stmt = $pdo->query("SELECT * FROM featured_section ORDER BY id DESC LIMIT 1");
$featured = $stmt->fetch(PDO::FETCH_ASSOC) ?? [];
$aboutItemCount = (int)$pdo->query("SELECT COUNT(*) FROM about_gallery")->fetchColumn();

?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width,initial-scale=1" />
<title>iTour Mercedes - Featured Admin</title>
<link rel="icon" type="image/png" href="img/newlogo.png" />
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600&display=swap" rel="stylesheet" />
<link href="https://unpkg.com/cropperjs@1.5.13/dist/cropper.min.css" rel="stylesheet"/>
<style>
/* ==== Updated Featured Admin Styles ==== */
body {
  margin: 0;
  font-family: 'Poppins', sans-serif;
  background: #f5f7fa;
  color: #222;
  scroll-behavior: smooth;
}

/* HEADER + NAV */
.featured-header {
  background: #fff;
  padding: 1rem 1.5rem;
  border-bottom: 1px solid #eee;
  box-shadow: 0 2px 5px rgba(0,0,0,0.03);
  position: sticky;
  top: 0;
  z-index: 999;
  display: flex;
  justify-content: flex-start;
  align-items: center;
  margin-left: 0;
}
.featured-header h2 {
  margin: 0;
  color: #2b7a66;
  font-size: 25px;
  font-weight: bold;
}
.cm-content-shell {
  width: 100%;
  max-width: 1420px;
  margin: 0 auto;
  padding: 8px 10px 14px;
  box-sizing: border-box;
  display: grid;
  gap: 12px;
}

.cm-secondary-nav {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
  flex-wrap: wrap;
  background: #fff;
  border: 1px solid #dbe7e2;
  border-radius: 14px;
  padding: 8px 10px;
}

.cm-search-wrap {
  order: 1;
  flex: 1 1 340px;
  max-width: 460px;
}

.cm-search-wrap input {
  width: 100%;
  height: 38px;
  border: 1px solid #d1dfd9;
  border-radius: 10px;
  padding: 0 12px;
  font-size: 12.5px;
  color: #1f2f3a;
  background: #fff;
}

.cm-search-wrap input:focus {
  outline: none;
  border-color: #8eb9aa;
  box-shadow: 0 0 0 3px rgba(43, 122, 102, 0.12);
}

.cm-tab-list {
  order: 2;
  margin-left: auto;
  display: inline-flex;
  align-items: center;
  gap: 4px;
  background: #edf3f0;
  border: 1px solid #d2e2dc;
  border-radius: 999px;
  padding: 4px;
  max-width: 100%;
  overflow-x: auto;
}

.cm-tab-btn {
  border: 0;
  background: transparent;
  border-radius: 999px;
  color: #40616e;
  font-size: 12px;
  font-weight: 700;
  line-height: 1.2;
  padding: 7px 14px;
  min-width: 104px;
  cursor: pointer;
  transition: background .18s ease, color .18s ease, box-shadow .18s ease;
}

.cm-tab-btn:hover {
  background: rgba(255,255,255,0.65);
  color: #1f4854;
}

.cm-tab-btn.active {
  background: #fff;
  color: #1f4854;
  box-shadow: 0 3px 10px rgba(20, 56, 45, 0.12);
}

.cm-tab-panel {
  display: none;
}

.cm-tab-panel.active {
  display: grid;
  gap: 12px;
}

.cm-panel-card {
  background: #fff;
  border: 1px solid #dbe7e2;
  border-radius: 14px;
  box-shadow: 0 5px 16px rgba(20, 55, 44, 0.06);
  padding: 16px;
}

.cm-tab-empty {
  display: none;
  margin: 8px 10px 0;
  font-size: 13px;
  color: #5e6f78;
}

.cm-tab-empty.show {
  display: block;
}

/* Featured Admin Layout */
.featured-admin-container {
  display: flex;
  min-height: 100vh;
}

.featured-main {
  flex: 1;
  margin-left: 240px;
  display: flex;
  flex-direction: column;
  min-height: 100vh;
  box-sizing: border-box;
  overflow-x: hidden;
  transition: margin-left 0.3s ease, width 0.3s ease;
}
.featured-admin-container .featured-main {
  padding: 0 0.45rem 0.75rem !important;
}
.featured-admin-container .featured-header {
  margin: 0 -0.45rem 0.35rem !important;
  padding: 0.68rem 0.85rem !important;
}
.admin-sidebar.collapsed ~ .featured-main {
  margin-left: 80px;
}

/* Sections spacing */
section {
  padding: 0;
}

/* Flex container for left/right boxes */
.featured-content {
  display: grid;
  grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
  gap: 14px;
  padding: 0;
  align-items: stretch;
  width: 100%;
  box-sizing: border-box;
  margin-top: 0;
}

/* LEFT BOX: Video & Descriptions */
.featured-preview {
  display: flex;
  flex-direction: column;
  gap: 16px;
  min-width: 0;
}

/* RIGHT BOX: Slider & Small Images */
.featured-box.right-box {
  display: flex;
  flex-direction: column;
  gap: 16px;
  min-width: 0;
}

/* Boxes */
.featured-box {
  background: #fff;
  border-radius: 12px;
  border: 1px solid #dbe7e2;
  box-shadow: 0 4px 14px rgba(19, 53, 43, 0.08);
  padding: 16px;
  box-sizing: border-box;
  display: flex;
  flex-direction: column;
  min-width: 0;
  height: 100%;
}

/* Titles & paragraphs */
.featured-box h3 {
  margin-top: 0;
  color: #2b7a66;
  font-size: 20px;
}
.featured-box p {
  display: flex;
  flex-direction: column; /* stack button below title */
  gap: 6px;
}

/* Images & Video */
.featured-preview img,
.featured-preview video,
.featured-slider-grid img,
.featured-small-grid img,
#af-crop-wrapper img {
  width: 100%;
  max-width: 100%;
  height: auto;
  aspect-ratio: 3 / 2;
  object-fit: cover;
  border-radius: 10px;
}

.featured-preview video {
  width: 100%;
  max-width: 100%;
  height: auto;
  aspect-ratio: 16 / 9;
  object-fit: cover;
  border-radius: 10px;
}

/* Buttons */
.af-control-row {
  display: flex;
  gap: 8px;
  align-items: center;
  margin-top: 15px;
}
.af-btn {
  background: #2b7a66;
  color: #fff;
  border: none;
  padding: 8px 12px;
  border-radius: 8px;
  cursor: pointer;
  font-weight: 600;
  display: inline-flex;
  align-items: center;
  gap: 8px;
  font-size: 13px;
  margin-bottom: 10px;
}

.af-btn:hover {
  background: #24614f;
}
.af-btn.secondary {
  background: #f0f0f0;
  color: #333;
}

.af-btn[disabled] {
  opacity: 0.6;
  cursor: default;
}

/* Edit buttons matching Upload button design but auto width */
.af-btn.edit-btn {
  background: #2b7a66;
  color: #fff;
  border: none;
  padding: 8px 12px;       /* keeps size like upload buttons */
  border-radius: 8px;
  cursor: pointer;
  font-weight: 600;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: auto;             /* auto width to fit the text */
  box-sizing: border-box;
  gap: 8px;
  margin-top: 6px;         /* spacing from the text */
  font-size: 13px;
}

.af-btn.edit-btn:hover {
  background: #24614f;
}



/* Slider & small grids */
.featured-slider-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 12px;
}
.featured-small-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 12px;
  margin-top: 12px;
}

/* Media modal */
.af-modal-overlay {
  display: none;
  position: fixed;
  top: 0;
  left: 0;
  width: 100%;
  height: 100%;
  background: rgba(0,0,0,0.4);
  align-items: center;
  justify-content: center;
  z-index: 9999;
}
.af-modal {
  background: #fff;
  border-radius: 12px;
  max-width: 900px;
  width: 90%;
  padding: 20px;
  display: flex;
  flex-direction: column;
  gap: 10px;
}
/* Media Modal Step Indicator (matches signup style) */
.af-step-indicator {
  display: flex;
  align-items: center;
  margin-bottom: 10px;
  padding: 0 100px;
  gap: 5px;
}

.af-step {
  display: flex;
  flex-direction: column;
  align-items: center;
  font-size: 0.85rem;
}

.af-step .circle {
  width: 28px;
  height: 28px;
  border-radius: 50%;
  border: 2px solid #ccc;
  display: flex;
  align-items: center;
  justify-content: center;
  margin-bottom: 4px;
  background-color: #fff;
  font-weight: 600;
  transition: all 0.3s;
}

.phase-line {
  flex: 1;
  height: 2px;
  background: #ccc;
  transition: all 0.3s;
}

/* Step states */
.af-step.phase-active .circle {
  background: #2E7B45;
  border-color: #2E7B45;
  color: #fff;
}

.af-step.phase-inactive .circle {
  background: #eee;
  border-color: #ccc;
  color: #999;
}

.af-step.phase-completed .circle {
  background: #2E7B45;
  border-color: #2E7B45;
  color: #fff;
}

.af-actions {
  display: flex;
  justify-content: flex-end;
  gap: 10px;
  margin-top: 6px;
}
.af-loader {
  display: inline-block;
  width: 24px;
  height: 24px;
  border: 3px solid #2b7a66;
  border-top: 3px solid transparent;
  border-radius: 50%;
  animation: spin 1s linear infinite;
}
@keyframes spin {
  to { transform: rotate(360deg); }
}

.custum-file-upload {
  height: 200px;
  width: 300px;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 20px;
  cursor: pointer;
  border: 2px dashed #cacaca;
  background-color: rgba(255, 255, 255, 1);
  padding: 1.5rem;
  border-radius: 10px;
  box-shadow: 0px 48px 35px -48px rgba(0,0,0,0.1);
}

.custum-file-upload .icon {
  display: flex;
  align-items: center;
  justify-content: center;
}

.custum-file-upload .icon svg {
  height: 80px;
  fill: rgba(75, 85, 99, 1);
}

.custum-file-upload .text {
  display: flex;
  align-items: center;
  justify-content: center;
}

.custum-file-upload .text span {
  font-weight: 400;
  color: rgba(75, 85, 99, 1);
}

.custum-file-upload input {
  display: none;
}

.custum-file-upload.drag-over {
  border-color: #2E7B45;
  background-color: #f0fff5;
}

/* === Unique Content Management FAQ Styles === */
.cmf-container {
    display: grid;
    grid-template-columns: minmax(0, 1.2fr) minmax(300px, 0.8fr);
    gap: 14px;
    align-items: start;
    margin: 0;
}

.cmf-left-box {
    background: #fff;
    border-radius: 12px;
    border: 1px solid #dbe7e2;
    box-shadow: 0 4px 14px rgba(19, 53, 43, 0.08);
    padding: 16px;
    min-width: 0;
    height: auto;
}

.cmf-right-box {
    background: #fff;
    border-radius: 12px;
    border: 1px solid #dbe7e2;
    box-shadow: 0 4px 14px rgba(19, 53, 43, 0.08);
    padding: 16px;
    min-width: 0;
    height: auto;
}

.cmf-faq-item { border-bottom:1px solid #eee; padding:10px 0; display:flex; justify-content:space-between; align-items:flex-start; gap:10px; }
.cmf-faq-item:last-child { border-bottom:none; }

.cmf-btn { background: #2b7a66; color: #fff; border: none; padding: 8px 12px; border-radius: 8px; cursor: pointer; font-weight: 600; transition: background 0.2s; 
  font-size: 13px;
}
.cmf-btn:hover { background: #1f5c46; }
.cmf-btn.cmf-secondary { background:#f0f0f0; color:#333; }
.cmf-btn:disabled { background:#ccc; cursor:not-allowed; color:#666; opacity:0.8; }

.cmf-right-box textarea { width:100%; padding:12px 14px; border-radius:8px; border:1px solid #ccc; font-size:0.95rem; min-height:80px; resize:vertical; box-sizing:border-box; }

.cmf-modal-overlay { display:none; position:fixed; top:0; left:0; width:100%; height:100%; background: rgba(0,0,0,0.4); justify-content:center; align-items:center; z-index:9999; padding:10px; box-sizing:border-box; }
.cmf-modal { background:#fff; border-radius:12px; max-width:450px; width:100%; padding:25px 20px; display:flex; flex-direction:column; gap:12px; }
.cmf-modal-header { display:flex; justify-content:center; position:relative; padding-bottom:12px; border-bottom:1px solid #eee; }
.cmf-modal-header button { position:absolute; top:8px; right:8px; border:none; background:none; font-size:1.2rem; cursor:pointer; color:#666; }
.cmf-modal textarea, .cmf-modal input { width:100%; padding:12px 14px; border-radius:8px; border:1px solid #ccc; font-size:0.95rem; box-sizing:border-box; }
.cmf-modal textarea { min-height:100px; resize:vertical; }
.cmf-modal-actions { display:flex; justify-content:flex-end; gap:10px; margin-top:10px; }

/* Beautiful Section Titles */
.section-title {
    font-family: 'Poppins', sans-serif;
    font-size: 21px;
    font-weight: 800;
    color: #2b7a66;
    text-transform: none;
    letter-spacing: 0;
    position: relative;
    padding-bottom: 6px;
    margin: 0 0 16px;
    line-height: 1.2;
}

/* Underline effect */
.section-title::after {
    content: '';
    position: absolute;
    width: 52px;
    height: 3px;
    background: #2b7a66;
    left: 0;
    bottom: 0;
    border-radius: 2px;
}


/* Container for gallery cards */
#galleryContainerUnique {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 16px;
  width: 100%;
  box-sizing: border-box;
}


/* Gallery card */
.gallery-card-unique {
    display: flex;
    flex-direction: row;
    background: #fff;
    border-radius: 12px;
    padding: 1rem;
    box-shadow: 0 4px 12px rgba(0,0,0,0.05);
    align-items: stretch;
    width: 100%;
}

/* Gallery image */
.gallery-image-unique {
    width: 150px;
    height: 150px;
    object-fit: cover;
    border-radius: 10px;
    margin-right: 1rem;
}

/* Info section */
.gallery-info-unique {
    flex: 1;
    display: flex;
    flex-direction: column;
    justify-content: space-between;
}

/* Action buttons */
.gallery-actions-unique {
    margin-top: auto;
    display: flex;
    justify-content: flex-end;
}

.btn-edit-unique,
.btn-save-unique,
.btn-cancel-unique,
.addnewbutton {
    background: #2b7a66;
    color: #fff;
    border: none;
    padding: 8px 12px;       /* keeps size like upload buttons */
    border-radius: 8px;
    cursor: pointer;
    font-weight: 600;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: auto;             /* auto width to fit the text */
    box-sizing: border-box;
    gap: 8px;
    margin-top: 6px;
    font-size: 13px;
}

.btn-edit-unique:hover,
.btn-save-unique:hover,
.btn-cancel-unique:hover,
.addnewbutton {
    background: #24614f;
}

.addnewbutton {
  float: none;
  margin: 0 0 12px auto;
  display: inline-flex;
}

.cm-about-head {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 12px;
  margin-bottom: 10px;
}

.cm-about-head .section-title {
  margin: 0;
}

.cm-about-add-btn {
  margin: 0 !important;
  white-space: nowrap;
}

.cm-tab-panel[data-tab="about"] .dashboard-content {
  margin: 0 !important;
  padding: 0 !important;
  max-width: none !important;
  width: 100% !important;
}

.cm-tab-panel[data-tab="about"] #galleryContainerUnique {
  margin-top: 0;
}

.cm-tab-panel[data-tab="about"] .gallery-card-unique {
  min-width: 0;
  height: 100%;
}

/* Modal & drag/drop */
.modal-unique .modal-dialog {max-width:800px;}
.drag-drop-area-unique, .custum-file-upload-unique {border:2px dashed #49A47A; border-radius:10px; width:250px; height:250px; display:flex; align-items:center; justify-content:center; cursor:pointer; margin-bottom:1rem; overflow:hidden; background-color:#f5f7fa;}
.drag-drop-area-unique img, .custum-file-upload-unique img {max-width:100%; max-height:100%; display:block;}
.modal-form-unique input, .modal-form-unique textarea {margin-bottom:1rem;}
.save-btn, .addnewbutton {background-color:#49A47A; color:white; border:none; border-radius:8px; padding:0.6rem 1.2rem; font-weight:bold; cursor:pointer; transition:all 0.3s;}
.save-btn:hover, .addnewbutton:hover {background-color:#3a8762; transform:translateY(-2px);}
.close-btn {position:absolute; top:12px; right:12px; background:none; border:none; font-size:28px; cursor:pointer; color:#555; z-index:10;}
.close-btn:hover {color:#000;}

/* --- New Drag & Drop Upload Area (Same Style as Provided) --- */
.custum-file-upload-unique {
  height: 200px;
  width: 100%;
  max-width: 300px;
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
  transition: 0.3s ease;
}

.custum-file-upload-unique:hover {
  border-color: #49A47A;
  background-color: #f8fffa;
}

.custum-file-upload-unique .icon-unique {
  display: flex;
  align-items: center;
  justify-content: center;
}

.custum-file-upload-unique .icon-unique svg {
  height: 80px;
  fill: rgba(75, 85, 99, 1);
}

.custum-file-upload-unique .text-unique {
  display: flex;
  align-items: center;
  justify-content: center;
}

.custum-file-upload-unique .text-unique span {
  font-weight: 400;
  color: rgba(75, 85, 99, 1);
}

.custum-file-upload-unique input {
  display: none;
}

/* When image is previewed */
.custum-file-upload-unique img {
  width: 100%;
  height: 100%;
  object-fit: cover;
  border-radius: 10px;
}
.custum-file-upload-unique .gallery-upload-preview[hidden] { display: none !important; }

@media screen and (max-width: 760px) {
  .cm-search-wrap {
    max-width: none;
    width: 100%;
  }
  .cm-tab-list {
    width: 100%;
  }
}

@media screen and (max-width: 1180px) {
  .cm-content-shell {
    padding: 7px 8px 12px;
  }
  .featured-content {
    grid-template-columns: 1fr;
  }
  .cmf-container {
    grid-template-columns: 1fr;
  }
}

</style>
<link rel="stylesheet" href="styles/admin_panel_theme.css" />
<style>
/* Content management workspace: intentionally loaded after the shared theme. */
.featured-admin-container .featured-main {
  background: linear-gradient(180deg, #f8fbfa 0%, #f2f7f4 100%);
  padding: 0 18px 28px !important;
}

.featured-admin-container .admin-sidebar.collapsed ~ .featured-main {
  margin-left: 90px !important;
}

.featured-admin-container .featured-header {
  margin: 0 -18px !important;
  border-radius: 0 !important;
}

.cm-content-shell {
  max-width: 1500px;
  padding: 10px 0 0;
  gap: 12px;
}

.cm-secondary-nav {
  min-height: 64px;
  padding: 10px 12px 10px 16px;
  flex-wrap: nowrap;
  border-color: #d7e5df;
  border-radius: 14px;
  box-shadow: 0 8px 24px rgba(19, 65, 51, .055);
}

.cm-search-wrap {
  position: relative;
  max-width: 520px;
}

.cm-search-icon {
  position: absolute;
  left: 14px;
  top: 50%;
  width: 18px;
  height: 18px;
  color: #698078;
  transform: translateY(-50%);
  pointer-events: none;
}

.cm-search-wrap input {
  height: 42px;
  padding: 0 42px;
  border-color: transparent;
  background: #f3f7f5;
  font-family: "Inter", sans-serif;
  font-size: 13px;
  font-weight: 500;
  box-sizing: border-box;
}

.cm-search-wrap input:hover { background: #eef5f2; }
.cm-search-wrap input::-webkit-search-cancel-button { appearance: none; }
.cm-search-wrap input:focus {
  background: #fff;
  border-color: #74a998;
  box-shadow: 0 0 0 3px rgba(43, 122, 102, .11);
}

.cm-search-clear {
  position: absolute;
  right: 8px;
  top: 50%;
  width: 28px;
  height: 28px;
  display: none;
  place-items: center;
  border: 0;
  border-radius: 7px;
  color: #61756e;
  background: transparent;
  font-size: 18px;
  cursor: pointer;
  transform: translateY(-50%);
}
.cm-search-clear.visible { display: grid; }
.cm-search-clear:hover { color: #174f40; background: #e3efea; }

.cm-tab-list {
  padding: 3px;
  gap: 2px;
  border: 0;
  border-radius: 10px;
  background: #edf3f0;
  overflow: visible;
}

.cm-tab-btn {
  min-width: 112px;
  padding: 9px 15px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 7px;
  border-radius: 8px;
  color: #536b63;
  font-family: "Inter", sans-serif;
  font-size: 13px;
  font-weight: 650;
}
.cm-tab-btn svg { width: 16px; height: 16px; }
.cm-tab-btn:hover { color: #185a47; background: rgba(255,255,255,.7); }
.cm-tab-btn.active {
  color: #fff;
  background: #246f5a;
  box-shadow: 0 4px 10px rgba(25, 93, 73, .2);
}

.cm-panel-card {
  padding: 0;
  background: transparent !important;
  border: 0 !important;
  border-radius: 0;
  box-shadow: none !important;
}

.cm-section-head {
  display: flex;
  align-items: flex-end;
  justify-content: space-between;
  gap: 18px;
  margin: 0 2px 16px;
}
.cm-section-head-copy { min-width: 0; }
.cm-section-kicker {
  display: block;
  margin-bottom: 4px;
  color: #2b7a66;
  font-size: 11px;
  font-weight: 750;
  letter-spacing: .09em;
  text-transform: uppercase;
}
.cm-section-head .section-title {
  margin: 0;
  padding: 0;
  color: #17382f;
  font-family: "Inter", sans-serif;
  font-size: 23px;
  font-weight: 750;
  letter-spacing: -.025em;
}
.cm-section-head .section-title::after { display: none; }
.cm-section-head p {
  margin: 5px 0 0;
  color: #687b74;
  font-size: 12.5px;
  line-height: 1.5;
}

.featured-content,
.cmf-container {
  gap: 0;
  overflow: hidden;
  background: #fff;
  border: 1px solid #d8e6e0;
  border-radius: 16px;
  box-shadow: 0 12px 30px rgba(17, 67, 53, .06);
}

.featured-box,
.cmf-left-box,
.cmf-right-box {
  padding: 22px !important;
  background: transparent !important;
  border: 0 !important;
  border-radius: 0 !important;
  box-shadow: none !important;
}
.featured-box + .featured-box,
.cmf-right-box { border-left: 1px solid #e2ebe7 !important; }

.featured-box h3,
.cmf-left-box h3,
.cmf-right-box h3 {
  margin: 0 0 5px;
  color: #17382f;
  font-family: "Inter", sans-serif;
  font-size: 17px;
  font-weight: 700;
}
.cm-block-hint {
  margin: 0 0 18px;
  color: #71817b;
  font-size: 12px;
  line-height: 1.45;
}

.featured-preview { gap: 0; }
.featured-preview video { border-radius: 12px; background: #10221d; }
.featured-box.right-box { gap: 0; }
.featured-media-item { min-width: 0; }
.featured-media-label {
  display: block;
  margin: 8px 1px 0;
  color: #354d45;
  font-size: 11.5px;
  font-weight: 650;
}
.featured-slider-grid,
.featured-small-grid { gap: 14px; }
.featured-small-grid {
  padding-top: 18px;
  margin-top: 18px;
  border-top: 1px solid #e5ece9;
}

.af-control-row { margin-top: 8px; }
.af-btn,
.cmf-btn,
.btn-edit-unique,
.btn-save-unique,
.btn-cancel-unique,
.addnewbutton {
  min-height: 36px;
  padding: 8px 13px;
  border-radius: 8px;
  font-family: "Inter", sans-serif;
  font-size: 12px;
  font-weight: 650;
  box-shadow: none;
}
.af-btn { margin-bottom: 0; }

.featured-copy-list {
  margin-top: 20px;
  border-top: 1px solid #e2ebe7;
}
.featured-copy-item {
  display: grid;
  grid-template-columns: minmax(0, 1fr) auto;
  align-items: center;
  gap: 16px;
  padding: 15px 0;
  border-bottom: 1px solid #edf2f0;
}
.featured-copy-item:last-child { border-bottom: 0; padding-bottom: 0; }
.featured-copy-item p { margin: 0; gap: 4px; }
.featured-copy-label {
  color: #63776f;
  font-size: 11px;
  font-weight: 700;
  letter-spacing: .055em;
  text-transform: uppercase;
}
.featured-copy-item span:not(.featured-copy-label) {
  color: #263c35;
  font-size: 13px;
  line-height: 1.55;
}
.af-btn.edit-btn { margin: 0; align-self: center; }

.cmf-container { grid-template-columns: minmax(0, 1.35fr) minmax(320px, .65fr); }
.cmf-left-box { max-height: 640px; overflow: auto; }
.cmf-faq-item {
  padding: 14px 2px;
  border-bottom-color: #e8efec;
  align-items: center;
}
.cmf-faq-item > div { min-width: 0; }
.cmf-faq-item strong { color: #223b33; font-size: 13.5px; line-height: 1.4; }
.cmf-faq-item small { display: block; margin-top: 4px; color: #687a74; font-size: 12px; line-height: 1.5; }
.cmf-right-box { background: #f9fbfa !important; }
.cmf-right-box label {
  display: block;
  margin: 14px 0 6px;
  color: #40564e;
  font-size: 12px;
  font-weight: 650;
}
.cmf-right-box textarea {
  min-height: 96px;
  border-color: #d4e1dc;
  border-radius: 9px;
  background: #fff;
  font-family: "Inter", sans-serif;
  font-size: 13px;
}
.cmf-right-box textarea:focus {
  outline: none;
  border-color: #78a999;
  box-shadow: 0 0 0 3px rgba(43, 122, 102, .1);
}

.cm-about-head { margin: 0; }
.cm-about-add-btn { min-height: 40px; background: #246f5a; }
.cm-tab-panel[data-tab="about"] .dashboard-content { padding: 0 !important; }
#galleryContainerUnique { gap: 14px; }
.gallery-card-unique {
  min-height: 210px;
  padding: 16px !important;
  border-color: #dce8e3 !important;
  border-radius: 14px !important;
  box-shadow: 0 5px 16px rgba(17, 67, 53, .045) !important;
  transition: border-color .18s ease, box-shadow .18s ease, transform .18s ease;
}
.gallery-card-unique:hover {
  border-color: #b8d2c8 !important;
  box-shadow: 0 10px 22px rgba(17, 67, 53, .08) !important;
  transform: translateY(-1px);
}
.gallery-image-unique { width: 150px; height: 100%; min-height: 178px; border-radius: 10px; }
.gallery-title-unique { color: #173c31; font-family: "Inter", sans-serif; }
.gallery-short-desc-unique { color: #354b44; font-size: 13px; line-height: 1.5; }
.gallery-long-desc-unique { line-height: 1.55; }

.cm-tab-empty.show {
  padding: 28px;
  margin: 0;
  text-align: center;
  background: #fff;
  border: 1px dashed #c9dcd4;
  border-radius: 12px;
}

@media (max-width: 1180px) {
  .featured-content,
  .cmf-container { grid-template-columns: 1fr; }
  .featured-box + .featured-box,
  .cmf-right-box {
    border-left: 0 !important;
    border-top: 1px solid #e2ebe7 !important;
  }
}

@media (max-width: 780px) {
  .featured-admin-container .featured-main { padding: 0 12px 22px !important; }
  .featured-admin-container .featured-header { margin: 0 -12px !important; }
  .cm-content-shell { padding-top: 12px; gap: 18px; }
  .cm-secondary-nav { align-items: stretch; flex-direction: column; padding: 10px; }
  .cm-search-wrap { width: 100%; max-width: none; flex: none; }
  .cm-tab-list { width: 100%; box-sizing: border-box; }
  .cm-tab-btn { flex: 1 1 0; min-width: 0; padding-inline: 8px; }
  .cm-section-head { align-items: flex-start; flex-direction: column; }
  .cm-about-add-btn { width: 100%; }
  .featured-box, .cmf-left-box, .cmf-right-box { padding: 17px !important; }
  #galleryContainerUnique { grid-template-columns: 1fr; }
}

@media (max-width: 540px) {
  .cm-tab-btn svg { display: none; }
  .featured-slider-grid, .featured-small-grid { grid-template-columns: 1fr; }
  .gallery-card-unique { flex-direction: column; }
  .gallery-image-unique { width: 100%; height: 190px; min-height: 0; margin: 0 0 14px; }
}

/* Header tabs and separated FAQ surfaces. */
.featured-header .cm-header-tabs {
  order: initial;
  flex: 0 0 auto;
  width: auto;
  margin: 0 0 0 auto;
}

.featured-header .ap-header-right { margin-left: 0 !important; }

.cm-secondary-nav {
  min-height: auto;
  padding: 0;
  background: transparent;
  border: 0;
  border-radius: 0;
  box-shadow: none;
}

.cm-secondary-nav .cm-search-wrap { max-width: 540px; }
.cm-secondary-nav .cm-search-wrap input {
  border: 1px solid #d7e5df;
  background: #fff;
  box-shadow: 0 5px 16px rgba(17, 67, 53, .04);
}

.cm-page-toolbar {
  display: grid;
  grid-template-columns: minmax(120px, auto) minmax(300px, 1fr) auto;
  align-items: center;
  gap: 18px;
  min-height: 68px;
  padding: 11px 13px 11px 20px;
  box-sizing: border-box;
  background: #fff;
  border: 1px solid #d7e5df;
  border-radius: 14px;
  box-shadow: 0 8px 24px rgba(19, 65, 51, .055);
}

.cm-toolbar-title h2 {
  margin: 0;
  color: #17382f;
  font-family: "Inter", sans-serif;
  font-size: 22px;
  font-weight: 750;
  letter-spacing: -.025em;
}

.cm-page-toolbar .cm-search-wrap {
  width: 100%;
  max-width: 680px;
  justify-self: center;
}

.cm-page-toolbar .cm-search-wrap input {
  border: 1px solid #d7e5df;
  background: #f5f8f7;
  box-shadow: none;
}

.cm-toolbar-actions { justify-self: end; }
.cm-toolbar-context {
  display: none;
  align-items: center;
  justify-content: flex-end;
  gap: 10px;
}
.cm-toolbar-context.active { display: flex; }

.cm-content-count {
  min-height: 34px;
  display: inline-flex;
  align-items: center;
  gap: 5px;
  padding: 0 11px;
  white-space: nowrap;
  color: #587068;
  background: #f0f5f3;
  border: 1px solid #dce8e3;
  border-radius: 8px;
  font-size: 11.5px;
  font-weight: 600;
}
.cm-content-count strong { color: #1d6652; font-size: 13px; }

.cm-toolbar-add {
  min-height: 36px;
  margin: 0 !important;
  padding: 8px 14px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  border: 0;
  border-radius: 8px;
  color: #fff;
  background: #246f5a;
  font-family: "Inter", sans-serif;
  font-size: 12px;
  font-weight: 700;
  white-space: nowrap;
  cursor: pointer;
}
.cm-toolbar-add:hover { background: #1c5c49; }

.cm-section-head { margin-bottom: 14px; }

.cmf-container {
  gap: 18px;
  overflow: visible;
  background: transparent;
  border: 0;
  border-radius: 0;
  box-shadow: none;
}

.cmf-left-box,
.cmf-right-box {
  background: #fff !important;
  border: 1px solid #d8e6e0 !important;
  border-radius: 16px !important;
  box-shadow: 0 10px 26px rgba(17, 67, 53, .055) !important;
}

.cmf-right-box {
  border-left: 1px solid #d8e6e0 !important;
  background: #fff !important;
}

#cm-panel-faq .cmf-left-box {
  max-height: none;
  overflow: visible;
}

/* `overflow-x: hidden` on the page shell creates a non-scrolling sticky container. */
.featured-admin-container .featured-main {
  overflow-x: clip;
}

#cm-panel-faq .cmf-right-box {
  position: sticky;
  top: 14px;
  align-self: start;
  z-index: 8;
}

.cmf-list-head {
  min-height: 36px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 16px;
}

.cmf-list-head h3 {
  margin: 0;
}

.cmf-list-head .cm-content-count {
  flex: 0 0 auto;
}

.cmf-add-action { margin-top: 20px; }
.cm-about-head { margin-bottom: 22px; }

@media (max-width: 1280px) {
  .featured-header .admin-page-title { order: 1; }
  .featured-header .ap-header-right { order: 2; margin-left: auto !important; }
  .featured-header .cm-header-tabs {
    order: 3;
    width: 100%;
    margin: 2px 0 0;
  }
  .featured-header .cm-tab-btn { flex: 1 1 0; }
}

@media (max-width: 1180px) {
  .cmf-right-box {
    border-top: 1px solid #d8e6e0 !important;
    border-left: 1px solid #d8e6e0 !important;
  }
  #cm-panel-faq .cmf-right-box {
    position: static;
  }
}

@media (max-width: 780px) {
  .cm-secondary-nav { padding: 0; }
  .cm-secondary-nav .cm-search-wrap { max-width: none; }
  .featured-header .cm-header-tabs { width: 100%; }
  .cm-page-toolbar {
    grid-template-columns: 1fr auto;
    gap: 10px;
    padding: 12px;
  }
  .cm-page-toolbar .cm-search-wrap {
    grid-column: 1 / -1;
    grid-row: 2;
    max-width: none;
  }
  .cm-toolbar-actions { grid-column: 2; grid-row: 1; }
}

@media (max-width: 480px) {
  .cm-content-count { display: none; }
  .cm-toolbar-title h2 { font-size: 20px; }
}

/* Polished featured-media uploader. */
#af-media-modal {
  padding: 20px;
  box-sizing: border-box;
  background: rgba(5, 29, 23, .58);
  backdrop-filter: blur(4px);
}
#af-media-modal .af-modal {
  width: min(940px, 96vw) !important;
  max-width: 940px !important;
  max-height: calc(100vh - 40px);
  gap: 0;
  overflow: hidden;
  padding: 0;
  border: 1px solid #cfdfd9;
  border-radius: 18px;
  box-shadow: 0 28px 75px rgba(4, 32, 24, .3);
}
#af-media-modal .af-modal-header {
  min-height: 68px;
  display: flex !important;
  align-items: center;
  justify-content: space-between !important;
  position: relative;
  padding: 0 22px !important;
  border-bottom: 1px solid #e2ebe7 !important;
}
.af-modal-title-group { display: flex; align-items: center; gap: 12px; }
.af-modal-title-icon {
  width: 38px; height: 38px; display: grid; place-items: center;
  border-radius: 11px; background: #e7f3ee; color: #1e6b55;
}
.af-modal-title-icon svg {
  width: 20px; height: 20px; fill: none; stroke: currentColor;
  stroke-width: 1.8; stroke-linecap: round; stroke-linejoin: round;
}
.af-modal-title-copy small {
  display: block; margin-bottom: 2px; color: #778b83;
  font-size: 9px; font-weight: 800; letter-spacing: .1em; text-transform: uppercase;
}
#af-media-modal-title { color: #173c31 !important; font-size: 18px !important; font-weight: 800; }
#af-media-close {
  position: static !important; width: 34px; height: 34px; display: grid; place-items: center;
  padding: 0; border: 1px solid transparent !important; border-radius: 9px;
  color: #5f736c !important; background: transparent; font-size: 21px !important; line-height: 1;
}
#af-media-close:hover { border-color: #d9e6e1 !important; background: #f2f7f5 !important; color: #183e33 !important; }

#af-media-modal .af-step-indicator {
  position: relative; display: grid !important; grid-template-columns: 1fr 1fr;
  gap: 54px !important; margin: 0 !important; padding: 16px 80px 14px !important;
  border-bottom: 1px solid #e8efec; background: #f8fbfa;
}
#af-media-modal .af-step {
  position: relative; z-index: 1; display: flex; flex-direction: column;
  align-items: center; justify-content: flex-start; padding: 0;
  border: 0; border-radius: 0; background: transparent; color: #71847d;
}
#af-media-modal .af-step .circle {
  width: 34px; height: 34px; flex: 0 0 34px; margin: 0 0 6px;
  border: 1px solid #c8d7d1; background: #edf3f0; color: #667a73; font-size: 11px;
}
#af-media-modal .af-step > span { color: inherit; font-size: 11.5px !important; font-weight: 800; text-align: center; }
#af-media-modal .af-step > span::after {
  content: "Select a file"; display: block; margin-top: 2px;
  color: #91a19b; font-size: 8.5px; font-weight: 600;
}
#af-media-modal #step-crop > span::after { content: "Adjust and save"; }
#af-media-modal .phase-line {
  position: absolute; top: 32px; left: calc(25% + 18px); right: calc(25% + 18px);
  width: auto; height: 2px !important; background: #d4e1dc !important;
}
#af-media-modal .af-step.phase-active,
#af-media-modal .af-step.phase-completed {
  color: #1f6853; box-shadow: none;
}
#af-media-modal .af-step.phase-active .circle,
#af-media-modal .af-step.phase-completed .circle { border-color: #246f59; background: #246f59; color: #fff; }
#af-media-modal .af-step.phase-completed .circle { font-size: 0; }
#af-media-modal .af-step.phase-completed .circle::after { content: "\2713"; font-size: 13px; }
#af-media-modal .af-modal.is-crop-step .phase-line { background: #5a9b85 !important; }

#af-media-modal .af-modal-body {
  display: grid !important; grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 18px !important; overflow-y: auto; padding: 20px 22px; background: #fff;
}
.af-media-pane { min-width: 0; display: flex; flex-direction: column; align-items: stretch; }
.af-media-pane-head { min-height: 47px; margin-bottom: 9px; }
.af-media-pane-head h3 { margin: 0; color: #203d34; font-size: 13.5px; font-weight: 800; }
.af-media-pane-head p { margin: 4px 0 0; color: #7a8c85; font-size: 10.5px; line-height: 1.4; }
#af-media-modal .custum-file-upload,
#af-media-modal #af-preview-area {
  width: 100% !important; height: 230px !important; min-height: 230px;
  box-sizing: border-box; overflow: hidden; border-radius: 13px !important;
}
#af-media-modal .custum-file-upload {
  gap: 11px; padding: 22px; border: 1.5px dashed #9ebeb2;
  background: linear-gradient(180deg,#fbfdfc,#f4f9f7); box-shadow: none;
  transition: border-color .18s ease, background .18s ease, transform .18s ease;
}
#af-media-modal .custum-file-upload:hover,
#af-media-modal .custum-file-upload.drag-over { border-color: #28735d; background: #edf7f3; transform: translateY(-1px); }
#af-media-modal .custum-file-upload .icon {
  width: 58px; height: 58px; display: grid; place-items: center;
  border-radius: 15px; background: #e4f1ec; color: #236b56;
}
#af-media-modal .custum-file-upload .icon svg { width: 34px; height: 34px !important; fill: currentColor !important; }
#af-media-modal .custum-file-upload .text span { color: #29483e; font-size: 12.5px; font-weight: 750; }
#af-media-modal .custum-file-upload .text span::after {
  content: "PNG, JPG, WEBP, or MP4"; display: block; margin-top: 6px;
  color: #83948e; font-size: 9px; font-weight: 600; text-align: center;
}
#af-media-modal #af-preview-area {
  display: flex; align-items: center; justify-content: center;
  border: 1px solid #d7e3de !important; background: #f5f8f7 !important;
}
#af-media-modal #af-preview-area img,
#af-media-modal #af-preview-area video { width: 100%; height: 100%; object-fit: contain; background: #12251f; }
.af-preview-empty { color: #81918b; font-size: 11px; text-align: center; }
.af-preview-empty::before {
  content: "No preview yet"; display: block; margin-bottom: 4px;
  color: #526a62; font-size: 12px; font-weight: 750;
}
#af-preview-path { min-height: 16px; margin-top: 7px !important; color: #61766e !important; font-size: 9.5px !important; text-align: center; word-break: break-all; }
#af-media-modal .af-actions {
  min-height: 62px; align-items: center; margin: 0 !important; padding: 0 22px;
  border-top: 1px solid #e4ece9; background: #f8faf9;
}
#af-media-modal .af-actions .af-btn {
  min-width: 86px; min-height: 38px; justify-content: center; margin: 0;
  border: 1px solid #246f59; border-radius: 9px;
}
#af-media-modal .af-actions .af-btn.secondary { border-color: #d3e1dc; background: #fff; color: #34544a; }
#af-media-modal .af-modal.is-crop-step { width: min(690px, 94vw) !important; max-width: 690px !important; }
#af-media-modal .af-modal.is-crop-step .af-modal-body { grid-template-columns: 1fr; }
#af-media-modal .af-modal.is-crop-step .af-upload-pane { display: none; }
#af-media-modal .af-modal.is-crop-step .af-preview-pane { width: min(520px,100%); margin: 0 auto; }
#af-media-modal .af-modal.is-crop-step #af-preview-area { height: 300px !important; }

@media (max-width: 700px) {
  #af-media-modal { padding: 10px; }
  #af-media-modal .af-modal { max-height: calc(100vh - 20px); }
  #af-media-modal .af-step-indicator { gap: 10px !important; padding: 12px 16px !important; }
  #af-media-modal .phase-line { display: none; }
  #af-media-modal .af-step { padding: 0; }
  #af-media-modal .af-step .circle { width: 30px; height: 30px; flex-basis: 30px; }
  #af-media-modal .af-modal-body { grid-template-columns: 1fr; padding: 15px; }
  #af-media-modal .custum-file-upload,
  #af-media-modal #af-preview-area { height: 190px !important; min-height: 190px; }
}

/* Formal About gallery add/edit dialogs. */
.modal-backdrop.show { opacity: .58; }
.modal-unique {
  position: fixed;
  inset: 0;
  z-index: 10050;
  display: none !important;
  align-items: center;
  justify-content: center;
  overflow-x: hidden;
  overflow-y: auto;
  padding: 18px;
  background: rgba(6, 31, 24, .62);
  backdrop-filter: blur(5px);
  font-family: "Inter", system-ui, sans-serif;
}
.modal-unique.is-open { display: flex !important; opacity: 1 !important; visibility: visible !important; }
.modal-unique.is-open .modal-dialog { transform: none !important; }
body.about-gallery-modal-open { overflow: hidden; }
.modal-unique .about-gallery-dialog { width: min(920px, calc(100vw - 32px)); max-width: 920px; margin-inline: auto; }
.modal-unique .about-gallery-modal {
  overflow: hidden;
  padding: 0 !important;
  border: 1px solid #cfdfd9;
  border-radius: 17px;
  background: #fff;
  box-shadow: 0 28px 75px rgba(4,32,24,.28);
}
.about-gallery-modal-header {
  min-height: 76px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 13px 20px !important;
  border: 0 !important;
  border-bottom: 1px solid #dde8e4 !important;
  background: linear-gradient(180deg,#fff,#fbfdfc);
}
.about-gallery-modal-heading { min-width: 0; display: flex; align-items: center; gap: 12px; }
.about-gallery-modal-icon {
  width: 42px; height: 42px; flex: 0 0 42px; display: grid; place-items: center;
  border: 1px solid #cce2d9; border-radius: 12px; background: #e9f4f0; color: #17634d;
}
.about-gallery-modal-icon svg {
  width: 22px; height: 22px; fill: none; stroke: currentColor;
  stroke-width: 1.7; stroke-linecap: round; stroke-linejoin: round;
}
.about-gallery-modal-heading small {
  display: block; margin: 0 0 2px; color: #568074;
  font-size: 8.5px; font-weight: 800; letter-spacing: .1em;
}
.about-gallery-modal-heading .modal-title {
  margin: 0; color: #173b30; font-size: 18px; font-weight: 800; letter-spacing: -.015em;
}
.about-gallery-modal-heading p { margin: 3px 0 0; color: #74867f; font-size: 10.5px; line-height: 1.35; }
.about-gallery-modal .close-btn {
  position: static !important; width: 34px; height: 34px; flex: 0 0 34px;
  display: grid; place-items: center; padding: 0; border: 1px solid transparent;
  border-radius: 9px; background: transparent; color: #63776f; font-size: 22px; line-height: 1;
}
.about-gallery-modal .close-btn:hover { border-color: #d7e4df; background: #f1f6f4; color: #173c31; }

.about-gallery-modal-body {
  display: grid;
  grid-template-columns: minmax(280px, .82fr) minmax(360px, 1.18fr);
  gap: 24px;
  padding: 20px;
}
.about-gallery-image-section,
.about-gallery-form-section { min-width: 0; }
.about-gallery-image-section {
  padding: 15px;
  border: 1px solid #e0ebe7;
  border-radius: 13px;
  background: #f8fbfa;
}
.about-gallery-section-head {
  min-height: 36px; display: flex; align-items: flex-start; justify-content: space-between;
  gap: 10px; margin-bottom: 10px;
}
.about-gallery-section-head > span { color: #29483e; font-size: 11.5px; font-weight: 800; }
.about-gallery-section-head > small { color: #879790; font-size: 8.5px; line-height: 1.35; text-align: right; }

.about-gallery-modal .custum-file-upload-unique {
  position: relative;
  width: 100%;
  max-width: none;
  height: 218px;
  margin: 0;
  padding: 18px;
  gap: 10px;
  box-sizing: border-box;
  border: 1.5px dashed #9dbdb1;
  border-radius: 12px;
  background: #fff;
  box-shadow: none;
}
.about-gallery-modal .custum-file-upload-unique:hover { border-color: #27735c; background: #f0f8f5; }
.about-gallery-modal .custum-file-upload-unique .icon-unique {
  width: 54px; height: 54px; flex: 0 0 54px; border-radius: 14px;
  background: #e8f3ef; color: #236c56;
}
.about-gallery-modal .custum-file-upload-unique .icon-unique svg { width: 31px; height: 31px; fill: currentColor; }
.about-gallery-modal .custum-file-upload-unique .text-unique { flex-direction: column; gap: 4px; text-align: center; }
.about-gallery-modal .custum-file-upload-unique .text-unique span { color: #29483e; font-size: 11.5px; font-weight: 800; }
.about-gallery-modal .custum-file-upload-unique .text-unique small { color: #82938c; font-size: 8.5px; font-weight: 600; }
.about-gallery-modal .gallery-upload-preview { width: 100%; height: 100%; border-radius: 9px; object-fit: cover; }
.about-gallery-modal .gallery-upload-preview:not([hidden]) ~ .icon-unique,
.about-gallery-modal .gallery-upload-preview:not([hidden]) ~ .text-unique { display: none; }
.about-gallery-modal .custum-file-upload-unique:has(.gallery-upload-preview:not([hidden]))::after {
  content: "Click to replace image";
  position: absolute; left: 50%; bottom: 12px; padding: 6px 10px;
  border: 1px solid rgba(255,255,255,.32); border-radius: 999px;
  background: rgba(10,49,38,.78); color: #fff; font-size: 8.5px; font-weight: 750;
  opacity: 0; transform: translate(-50%,5px); transition: opacity .16s ease, transform .16s ease;
}
.about-gallery-modal .custum-file-upload-unique:has(.gallery-upload-preview:not([hidden])):hover::after { opacity: 1; transform: translate(-50%,0); }
.about-gallery-upload-note { margin: 9px 0 0; color: #778a83; font-size: 8.5px; line-height: 1.4; text-align: center; }

.about-gallery-form-section { display: flex; flex-direction: column; }
.about-gallery-form-section label {
  display: block; margin: 0 0 5px; color: #3c574e;
  font-size: 10px; font-weight: 750;
}
.about-gallery-form-section label b { color: #b43c4e; }
.about-gallery-modal .modal-form-unique input,
.about-gallery-modal .modal-form-unique textarea {
  width: 100%; margin: 0 0 11px; padding: 9px 11px;
  border: 1px solid #d3e1dc; border-radius: 9px; background: #fbfcfc;
  color: #213c33; font-family: "Inter",sans-serif; font-size: 11.5px; line-height: 1.45;
  box-shadow: none; resize: vertical;
}
.about-gallery-modal .modal-form-unique input { height: 40px; }
.about-gallery-modal .modal-form-unique textarea:last-child { margin-bottom: 0; }
.about-gallery-modal .modal-form-unique input::placeholder,
.about-gallery-modal .modal-form-unique textarea::placeholder { color: #98a59f; }
.about-gallery-modal .modal-form-unique input:focus,
.about-gallery-modal .modal-form-unique textarea:focus {
  outline: 0; border-color: #69a28f; background: #fff; box-shadow: 0 0 0 3px rgba(43,122,102,.1);
}

.about-gallery-cropper { width: 100%; }
.about-gallery-cropper > img { display: block; max-width: 100%; border-radius: 10px; }
.about-gallery-crop-actions { display: flex; justify-content: flex-end; gap: 7px; margin-top: 10px; }
.about-gallery-modal-footer {
  min-height: 64px; display: flex; align-items: center; justify-content: space-between;
  gap: 16px; padding: 11px 20px; border-top: 1px solid #dfe9e5; background: #f8faf9;
}
.about-gallery-modal-footer > p { margin: 0; color: #7a8c85; font-size: 9px; }
.about-gallery-modal-footer > div { display: flex; align-items: center; gap: 8px; }
.about-gallery-modal .btn-save-unique,
.about-gallery-modal .btn-cancel-unique {
  min-width: 90px; min-height: 38px; display: inline-flex; align-items: center; justify-content: center;
  margin: 0; padding: 0 13px; border-radius: 9px; font-family: "Inter",sans-serif;
  font-size: 10.5px; font-weight: 750; transition: background .16s ease, border-color .16s ease, transform .16s ease;
}
.about-gallery-modal .btn-save-unique { border: 1px solid #216a54; background: #246f59; color: #fff; }
.about-gallery-modal .btn-save-unique:hover { background: #1a5946; transform: translateY(-1px); }
.about-gallery-modal .btn-cancel-unique { border: 1px solid #d2e0db; background: #fff; color: #38564c; }
.about-gallery-modal .btn-cancel-unique:hover { border-color: #adc7bd; background: #f0f6f3; }
.about-gallery-modal .btn-save-unique:disabled { opacity: .58; cursor: wait; transform: none; }

@media (max-width: 760px) {
  .modal-unique .about-gallery-dialog { width: calc(100vw - 20px); margin: 10px auto; }
  .about-gallery-modal-body { grid-template-columns: 1fr; gap: 15px; padding: 15px; max-height: calc(100vh - 175px); overflow-y: auto; }
  .about-gallery-modal-header { padding: 11px 15px !important; }
  .about-gallery-modal-heading p { display: none; }
  .about-gallery-modal .custum-file-upload-unique { height: 190px; }
  .about-gallery-modal-footer { align-items: stretch; flex-direction: column; padding: 10px 15px; }
  .about-gallery-modal-footer > p { display: none; }
  .about-gallery-modal-footer > div { width: 100%; }
  .about-gallery-modal-footer .btn-save-unique,
  .about-gallery-modal-footer .btn-cancel-unique { flex: 1; }
}
</style>
</head>
<body>
<div class="featured-admin-container">
<?php include 'admin_sidebar.php'; ?>
<main class="featured-main">
<header class="featured-header admin-page-header">
    <div class="admin-header-left admin-page-title">
        <span class="admin-page-title-icon" aria-hidden="true">
          <svg viewBox="0 0 24 24"><path d="m12 3 8 4-8 4-8-4 8-4Z"/><path d="m4 12 8 4 8-4M4 17l8 4 8-4"/></svg>
        </span>
        <div class="admin-page-title-copy">
          <h2>Content Management</h2>
          <p class="admin-header-subtitle">Manage featured content, FAQs, and public information</p>
        </div>
    </div>
    <div class="cm-tab-list cm-header-tabs" role="tablist" aria-label="Content management tabs">
      <button type="button" class="cm-tab-btn active" id="cm-tab-featured" data-tab="featured" role="tab" aria-controls="cm-panel-featured" aria-selected="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="m12 3 2.7 5.5 6.1.9-4.4 4.3 1 6.1-5.4-2.9-5.4 2.9 1-6.1-4.4-4.3 6.1-.9L12 3Z"/></svg>Featured</button>
      <button type="button" class="cm-tab-btn" id="cm-tab-faq" data-tab="faq" role="tab" aria-controls="cm-panel-faq" aria-selected="false" tabindex="-1"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M9.5 9a2.6 2.6 0 1 1 4.1 2.1c-1 .7-1.6 1.2-1.6 2.4"/><path d="M12 17h.01"/><circle cx="12" cy="12" r="9"/></svg>FAQ</button>
      <button type="button" class="cm-tab-btn" id="cm-tab-about" data-tab="about" role="tab" aria-controls="cm-panel-about" aria-selected="false" tabindex="-1"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 11v6M12 7h.01"/></svg>About</button>
    </div>
</header>

<div class="cm-content-shell">
  <div class="cm-page-toolbar">
    <div class="cm-toolbar-title">
      <h2 id="cmToolbarTitle">Featured</h2>
    </div>
    <div class="cm-search-wrap">
      <svg class="cm-search-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-4-4"/></svg>
      <input type="search" id="cmContentSearch" placeholder="Search featured content..." aria-label="Search content" autocomplete="off">
      <button type="button" class="cm-search-clear" id="cmSearchClear" aria-label="Clear search">&times;</button>
    </div>
    <div class="cm-toolbar-actions">
      <div class="cm-toolbar-context active" data-context="featured">
        <span class="cm-content-count"><strong>7</strong> media slots</span>
      </div>
      <div class="cm-toolbar-context" data-context="faq">
        <button type="button" class="cm-toolbar-add" id="cmAddFaqShortcut">Add FAQ</button>
      </div>
      <div class="cm-toolbar-context" data-context="about">
        <span class="cm-content-count"><strong><?= $aboutItemCount ?></strong> gallery items</span>
        <button type="button" class="addnewbutton cm-toolbar-add" id="addNewBtn">Add gallery item</button>
      </div>
    </div>
  </div>

  <div class="cm-tab-panel active" id="cm-panel-featured" data-tab="featured" role="tabpanel" aria-labelledby="cm-tab-featured">
    <section id="featured-section" class="cm-panel-card">
      <div class="featured-content">
        <div class="featured-box featured-preview">
          <h3>Video and descriptions</h3>
          <p class="cm-block-hint">Preview the current video and maintain the text displayed with it.</p>
          <video src="<?= htmlspecialchars($featured['video_path'] ?? 'img/samplevideo.mp4') ?>" controls muted></video>
          <div class="af-control-row">
            <button class="af-btn" data-open-media="video" data-media-slot="video">Replace video</button>
          </div>

          <div class="featured-copy-list">
            <div class="featured-copy-item">
              <p><span class="featured-copy-label">Description 1</span>
              <span id="text-description1"><?= nl2br(htmlspecialchars($featured['description1'] ?? '')) ?></span>
              </p>
              <button class="af-btn edit-btn" data-edit-text="description1">Edit</button>
            </div>

            <div class="featured-copy-item">
              <p><span class="featured-copy-label">Description 2</span>
              <span id="text-description2"><?= nl2br(htmlspecialchars($featured['description2'] ?? '')) ?></span>
              </p>
              <button class="af-btn edit-btn" data-edit-text="description2">Edit</button>
            </div>

            <div class="featured-copy-item">
              <p><span class="featured-copy-label">Footer text</span>
              <span id="text-footer_text"><?= nl2br(htmlspecialchars($featured['footer_text'] ?? '')) ?></span>
              </p>
              <button class="af-btn edit-btn" data-edit-text="footer_text">Edit</button>
            </div>
          </div>
        </div>

        <div class="featured-box right-box">
          <h3>Image collection</h3>
          <p class="cm-block-hint">Replace slider and supporting images while keeping their assigned positions.</p>

          <div class="featured-slider-grid">
              <?php for($i=1;$i<=4;$i++): $col="slider_image$i"; ?>
              <div class="featured-media-item">
                <img src="<?= htmlspecialchars($featured[$col] ?? 'img/sampleimage.png') ?>" 
                    alt="Slider <?= $i ?>" 
                    data-media-slot="slider<?= $i ?>">
                <span class="featured-media-label">Slider image <?= $i ?></span>
                <div class="af-control-row">
                  <button class="af-btn" data-open-media="image" data-media-slot="slider<?= $i ?>">Replace image</button>
                </div>
              </div>
              <?php endfor; ?>
            </div>

            <div class="featured-small-grid">
              <?php for($i=1;$i<=2;$i++): $col="small_image$i"; ?>
              <div class="featured-media-item">
                <img src="<?= htmlspecialchars($featured[$col] ?? 'img/sampleimage.png') ?>" 
                    alt="Small <?= $i ?>" 
                    data-media-slot="small<?= $i ?>">
                <span class="featured-media-label">Supporting image <?= $i ?></span>
                <div class="af-control-row">
                  <button class="af-btn" data-open-media="image" data-media-slot="small<?= $i ?>">Replace image</button>
                </div>
              </div>
              <?php endfor; ?>
            </div>
        </div>
      </div>
      <p class="cm-tab-empty" data-empty-for="featured">No matching featured content found.</p>
    </section>
  </div>

  <div class="cm-tab-panel" id="cm-panel-faq" data-tab="faq" role="tabpanel" aria-labelledby="cm-tab-faq">
    <section id="faq-section" class="cm-panel-card">
        <div class="cmf-container">
        <div class="cmf-left-box">
            <div class="cmf-list-head">
              <h3>Published questions</h3>
              <span class="cm-content-count"><strong id="cmfFaqCountValue"><?= count($faqs) ?></strong> published</span>
            </div>
            <p class="cm-block-hint" id="cmfFaqCountHint"><?= count($faqs) ?> question<?= count($faqs) === 1 ? '' : 's' ?> currently available.</p>
            <div id="cmf-faq-list">
            <?php foreach($faqs as $faq): ?>
                <div class="cmf-faq-item" data-id="<?= $faq['id'] ?>">
                    <div>
                        <strong><?= htmlspecialchars($faq['question']) ?></strong><br>
                        <small><?= nl2br(htmlspecialchars($faq['answer'])) ?></small>
                    </div>
                    <button class="cmf-btn cmf-edit-btn" data-id="<?= $faq['id'] ?>" data-question="<?= htmlspecialchars($faq['question']) ?>" data-answer="<?= htmlspecialchars($faq['answer']) ?>">Edit</button>
                </div>
            <?php endforeach; ?>
            </div>
        </div>

        <div class="cmf-right-box">
            <h3>Add New FAQ</h3>
            <p class="cm-block-hint">Create a concise question and a clear answer for the public FAQ.</p>
            <label for="cmf-new-question">Question</label>
            <textarea id="cmf-new-question" placeholder="Enter the question"></textarea>
            <label for="cmf-new-answer">Answer</label>
            <textarea id="cmf-new-answer" placeholder="Write a helpful answer"></textarea>
            <div class="cmf-add-action">
                <button class="cmf-btn" id="cmf-add-faq-btn" disabled>Add question</button>
            </div>
        </div>
      </div>
      <p class="cm-tab-empty" data-empty-for="faq">No matching FAQs found.</p>
    </section>
  </div>

  <div class="cm-tab-panel" id="cm-panel-about" data-tab="about" role="tabpanel" aria-labelledby="cm-tab-about">
    <section id="about-section" class="cm-panel-card">
        <?php
          $showAboutInlineAddButton = false;
          include 'adabout.php';
        ?>
        <p class="cm-tab-empty" data-empty-for="about">No matching about items found.</p>
    </section>
  </div>
</div>

<!-- MEDIA MODAL -->
<div id="af-media-modal" class="af-modal-overlay" aria-hidden="true">
  <div class="af-modal" role="dialog" aria-modal="true">

    <!-- Modal Header -->
    <div class="af-modal-header">
      <div class="af-modal-title-group">
        <span class="af-modal-title-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M4 16.5V19a1 1 0 0 0 1 1h14a1 1 0 0 0 1-1v-2.5M12 4v11M7.5 8.5 12 4l4.5 4.5"/></svg></span>
        <div class="af-modal-title-copy"><small>Featured content</small><strong id="af-media-modal-title">Upload Media</strong></div>
      </div>
      <button id="af-media-close" style="position:absolute; top:8px; right:8px; border:none; background:none; font-size:1.2rem; cursor:pointer; color:#666;">×</button>
    </div>

    <!-- Step Indicator -->
    <div class="af-step-indicator">
      <div class="af-step phase-active" data-step="1" id="step-upload">
        <div class="circle">1</div>
        <span>Upload</span>
      </div>
      <div class="phase-line"></div>
      <div class="af-step phase-inactive" data-step="2" id="step-crop">
        <div class="circle">2</div>
        <span>Crop</span>
      </div>
    </div>

    <!-- Modal Body -->
    <div class="af-modal-body">
      <!-- Upload Column -->
      <div class="af-media-pane af-upload-pane">
        <div class="af-media-pane-head"><h3>Choose a media file</h3><p>Select a file from your device or drag it into the upload area.</p></div>
        <label class="custum-file-upload" for="af-file-input" id="af-drop-area">
          <div class="icon">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" style="height:80px; fill:#4B5563;">
              <path d="M10 1C9.73478 1 9.48043 1.10536 9.29289 1.29289L3.29289 7.29289C3.10536 7.48043 3 7.73478 3 8V20C3 21.6569 4.34315 23 6 23H7C7.55228 23 8 22.5523 8 22C8 21.4477 7.55228 21 7 21H6C5.44772 21 5 20.5523 5 20V9H10C10.5523 9 11 8.55228 11 8V3H18C18.5523 3 19 3.44772 19 4V9C19 9.55228 19.4477 10 20 10C20.5523 10 21 9.55228 21 9V4C21 2.34315 19.6569 1 18 1H10ZM9 7H6.41421L9 4.41421V7ZM14 15.5C14 14.1193 15.1193 13 16.5 13C17.8807 13 19 14.1193 19 15.5V16V17H20C21.1046 17 22 17.8954 22 19C22 20.1046 21.1046 21 20 21H13C11.8954 21 11 20.1046 11 19C11 17.8954 11.8954 17 13 17H14V16V15.5ZM16.5 11C14.142 11 12.2076 12.8136 12.0156 15.122C10.2825 15.5606 9 17.1305 9 19C9 21.2091 10.7909 23 13 23H20C22.2091 23 24 21.2091 24 19C24 17.1305 22.7175 15.5606 20.9844 15.122C20.7924 12.8136 18.858 11 16.5 11Z"/>
            </svg>
          </div>
          <div class="text">
            <span>Click or Drag to upload image</span>
          </div>
          <input type="file" id="af-file-input">
        </label>
      </div>

      <!-- Crop / Preview Column -->
      <div class="af-media-pane af-preview-pane">
        <div id="af-preview-wrapper" class="af-media-pane-head"><h3>File preview</h3><p>Review the selected media before continuing.</p></div>
        <div id="af-preview-area">
          <div class="af-preview-empty">Choose a file to display it here.</div>
        </div>
        <div id="af-preview-path"></div>
      </div>
    </div>

    <!-- Modal Actions -->
    <div class="af-actions">
      <button id="af-media-cancel" class="af-btn secondary">Cancel</button>
      <button id="af-media-next" class="af-btn" disabled>
        <span id="af-media-next-label">Next</span>
        <span class="af-loader" id="af-media-next-loader" style="display:none;"></span>
      </button>
    </div>
  </div>
</div>

<!-- TEXT MODAL -->
<div id="af-text-modal" class="af-modal-overlay">
  <div class="af-modal" style="max-width:500px; width:90%; padding:24px; border-radius:12px;">
    <!-- Header -->
    <div style="display:flex; justify-content:center; position:relative; padding-bottom:12px; border-bottom:1px solid #eee;">
      <strong style="font-size:1.2rem; color:#2b7a66;">Edit Text</strong>
      <button id="af-text-close" style="position:absolute; top:8px; right:8px; border:none; background:none; font-size:1.2rem; cursor:pointer; color:#666;">×</button>
    </div>

    <textarea id="af-textarea" style="width:100%;height:150px; margin-top:12px; padding:8px; border-radius:8px; border:1px solid #ccc; font-size:1rem;"></textarea>

    <div class="af-actions" style="margin-top:16px; display:flex; justify-content:flex-end; gap:10px;">
      <button id="af-text-save" class="af-btn">Save</button>
      <button id="af-text-cancel" class="af-btn secondary">Cancel</button>
    </div>
  </div>
</div>

<!-- Edit FAQ Modal -->
<div id="cmf-faq-modal" class="cmf-modal-overlay">
    <div class="cmf-modal">
        <div class="cmf-modal-header">
            <strong>Edit FAQ</strong>
            <button id="cmf-modal-close">×</button>
        </div>
        <label>Question:</label>
        <textarea id="cmf-modal-question"></textarea>
        <label>Answer:</label>
        <textarea id="cmf-modal-answer"></textarea>
        <div class="cmf-modal-actions">
            <button class="cmf-btn" id="cmf-save-faq-btn">Save</button>
            <button class="cmf-btn cmf-secondary" id="cmf-cancel-faq-btn">Cancel</button>
        </div>
    </div>
</div>

<script src="https://unpkg.com/cropperjs@1.5.13/dist/cropper.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
// ==== JS for modal & upload ====

let currentMediaType = null;
let currentMediaSlot = null;
let cropper = null;
let currentTextField = null;

const cmTabButtons = Array.from(document.querySelectorAll('.cm-tab-btn'));
const cmTabPanels = Array.from(document.querySelectorAll('.cm-tab-panel'));
const cmSearchInput = document.getElementById('cmContentSearch');
const cmSearchClear = document.getElementById('cmSearchClear');
const cmToolbarTitle = document.getElementById('cmToolbarTitle');
const cmToolbarContexts = Array.from(document.querySelectorAll('.cm-toolbar-context'));

const cmSearchMeta = {
  featured: { selector: '.featured-box', placeholder: 'Search featured content...' },
  faq: { selector: '.cmf-faq-item', placeholder: 'Search FAQs...' },
  about: { selector: '.gallery-card-unique', placeholder: 'Search about items...' }
};

function setActiveContentTab(tabKey) {
  if (!cmSearchMeta[tabKey]) return;

  if (tabKey !== 'about') {
    document.querySelectorAll('.modal-unique.is-open, .modal-unique.show').forEach(modalElement => {
      if (typeof closeAboutGalleryModal === 'function') {
        closeAboutGalleryModal(modalElement);
      } else {
        modalElement.classList.remove('is-open', 'show');
        modalElement.setAttribute('aria-hidden', 'true');
      }
    });
    document.querySelectorAll('.modal-backdrop').forEach(backdrop => backdrop.remove());
    document.body.classList.remove('about-gallery-modal-open', 'modal-open');
  }

  const tabTitle = tabKey === 'faq' ? 'FAQ' : tabKey.charAt(0).toUpperCase() + tabKey.slice(1);
  if (cmToolbarTitle) cmToolbarTitle.textContent = tabTitle;
  cmToolbarContexts.forEach((context) => {
    context.classList.toggle('active', context.dataset.context === tabKey);
  });

  cmTabButtons.forEach((btn) => {
    const isActive = btn.dataset.tab === tabKey;
    btn.classList.toggle('active', isActive);
    btn.setAttribute('aria-selected', isActive ? 'true' : 'false');
    btn.tabIndex = isActive ? 0 : -1;
  });

  cmTabPanels.forEach((panel) => {
    panel.classList.toggle('active', panel.dataset.tab === tabKey);
  });

  if (cmSearchInput) {
    cmSearchInput.placeholder = cmSearchMeta[tabKey].placeholder;
    cmSearchInput.value = '';
    if (cmSearchClear) cmSearchClear.classList.remove('visible');
    applyContentSearch();
  }
}

function applyContentSearch() {
  if (!cmSearchInput) return;
  const activePanel = document.querySelector('.cm-tab-panel.active');
  if (!activePanel) return;

  const activeTab = activePanel.dataset.tab;
  const meta = cmSearchMeta[activeTab];
  if (!meta) return;

  const query = cmSearchInput.value.trim().toLowerCase();
  const cards = activePanel.querySelectorAll(meta.selector);
  let visibleCount = 0;

  cards.forEach((card) => {
    const text = (card.textContent || '').toLowerCase();
    const shouldShow = query === '' || text.includes(query);
    card.style.display = shouldShow ? '' : 'none';
    if (shouldShow) visibleCount += 1;
  });

  const emptyState = activePanel.querySelector(`.cm-tab-empty[data-empty-for="${activeTab}"]`);
  if (emptyState) {
    emptyState.classList.toggle('show', cards.length > 0 && visibleCount === 0);
  }
}

cmTabButtons.forEach((btn) => {
  btn.addEventListener('click', () => setActiveContentTab(btn.dataset.tab));
  btn.addEventListener('keydown', (event) => {
    if (event.key !== 'ArrowLeft' && event.key !== 'ArrowRight') return;
    event.preventDefault();
    const direction = event.key === 'ArrowRight' ? 1 : -1;
    const nextIndex = (cmTabButtons.indexOf(btn) + direction + cmTabButtons.length) % cmTabButtons.length;
    setActiveContentTab(cmTabButtons[nextIndex].dataset.tab);
    cmTabButtons[nextIndex].focus();
  });
});

if (cmSearchInput) {
  cmSearchInput.addEventListener('input', () => {
    if (cmSearchClear) cmSearchClear.classList.toggle('visible', cmSearchInput.value.length > 0);
    applyContentSearch();
  });
}

if (cmSearchClear) {
  cmSearchClear.addEventListener('click', () => {
    cmSearchInput.value = '';
    cmSearchClear.classList.remove('visible');
    applyContentSearch();
    cmSearchInput.focus();
  });
}

const cmAddFaqShortcut = document.getElementById('cmAddFaqShortcut');
if (cmAddFaqShortcut) {
  cmAddFaqShortcut.addEventListener('click', () => {
    const addFaqBox = document.querySelector('.cmf-right-box');
    const questionField = document.getElementById('cmf-new-question');
    if (addFaqBox) addFaqBox.scrollIntoView({ behavior: 'smooth', block: 'start' });
    if (questionField) window.setTimeout(() => questionField.focus(), 350);
  });
}

setActiveContentTab('featured');

document.addEventListener('DOMContentLoaded', () => {
    const modal = document.getElementById('af-media-modal');
    const inputFile = document.getElementById('af-file-input');
    const nextBtn = document.getElementById('af-media-next');
    const nextBtnLabel = document.getElementById('af-media-next-label');
    const cropWrapper = document.getElementById('af-crop-wrapper') || document.createElement('div');
    const stepUpload = document.getElementById('step-upload');
    const stepCrop = document.getElementById('step-crop');
    const dropArea = document.getElementById('af-drop-area');

    // --- OPEN MEDIA MODAL ---
    document.querySelectorAll('[data-open-media]').forEach(btn => {
        btn.addEventListener('click', () => {
            currentMediaType = btn.dataset.openMedia;
            currentMediaSlot = btn.dataset.mediaSlot;
            if(modal) modal.style.display = 'flex';
            modal?.querySelector('.af-modal')?.classList.remove('is-crop-step');

            const modalTitle = document.getElementById('af-media-modal-title');
            const previewTitle = document.querySelector('#af-preview-wrapper h3');
            if (modalTitle) modalTitle.textContent = currentMediaType === 'video' ? 'Replace Video' : 'Replace Image';
            if (previewTitle) previewTitle.textContent = currentMediaType === 'video' ? 'Video preview' : 'Image preview';
            if (inputFile) inputFile.accept = currentMediaType === 'video' ? 'video/mp4,video/webm' : 'image/png,image/jpeg,image/webp';

            const stepIndicator = document.querySelector('.af-step-indicator');
            if(currentMediaType === 'video'){
                if(stepIndicator) stepIndicator.style.display = 'none'; // hide steps
                if(nextBtnLabel) nextBtnLabel.textContent = 'Save';
            } else {
                if(stepIndicator) stepIndicator.style.display = 'flex'; // show steps
                if(nextBtnLabel) nextBtnLabel.textContent = 'Next';
            }

            // Reset modal contents
            if(cropWrapper) cropWrapper.innerHTML = '<div style="color:#999;text-align:center;padding:10px;">No file selected</div>';
            if(nextBtn) nextBtn.disabled = true;
            if(inputFile) inputFile.value = '';
            if(dropArea) dropArea.style.display = 'flex';

            const textSpan = dropArea.querySelector('.text span');
            if(currentMediaType === 'video'){
                textSpan.innerText = "Click or Drag to upload video";
            } else {
                textSpan.innerText = "Click or Drag to upload image";
            }

            resetModalSize();
        });
    });


    // --- CLOSE MODAL ---
    ['af-media-close','af-media-cancel'].forEach(id => {
        const el = document.getElementById(id);
        if(el) el.addEventListener('click', () => {
            if(modal) modal.style.display = 'none';
            resetMediaModal();
        });
    });

    // --- FILE SELECT / PREVIEW ---
    if(inputFile){
        inputFile.addEventListener('change', e => {
            const file = e.target.files?.[0];
            if (!file) return;

            if(nextBtn) nextBtn.disabled = false;
            const previewArea = document.getElementById('af-preview-area');
            const previewPath = document.getElementById('af-preview-path');

            if(previewArea) previewArea.innerHTML = '';
            if(previewPath) previewPath.innerText = file.name;

            if(currentMediaType === 'image'){
                if(previewArea){
                    const img = document.createElement('img');
                    img.src = URL.createObjectURL(file);
                    img.style.maxWidth = '100%';
                    img.style.maxHeight = '100%';
                    img.style.objectFit = 'cover';
                    previewArea.appendChild(img);
                }
            } else {
                if(previewArea){
                    const vid = document.createElement('video');
                    vid.src = URL.createObjectURL(file);
                    vid.controls = true;
                    vid.style.width = '100%';
                    vid.style.height = '100%';
                    previewArea.appendChild(vid);
                }
            }
        });
    }

    // --- NEXT / SAVE BUTTON ---
    if(nextBtn){
        nextBtn.addEventListener('click', () => {
            if(!inputFile || !inputFile.files[0]) return;

            // STEP 1 → STEP 2 (IMAGE CROPPING)
            if(currentMediaType === 'image' && !cropper){
                const file = inputFile.files[0];
                const previewArea = document.getElementById('af-preview-area');
                if(previewArea) previewArea.innerHTML = '';

                const img = document.createElement('img');
                img.id = 'af-crop-image';
                img.src = URL.createObjectURL(file);
                img.style.width = '100%';
                img.style.height = 'auto';
                if(previewArea) previewArea.appendChild(img);

                cropper = new Cropper(img, {
                    aspectRatio: 300 / 200,
                    viewMode: 1,
                    autoCropArea: 1,
                });

                if(stepUpload && stepCrop){
                    stepUpload.classList.replace('phase-active','phase-completed');
                    stepCrop.classList.replace('phase-inactive','phase-active');
                }
                const previewHeading = document.querySelector('#af-preview-wrapper h3');
                const previewHint = document.querySelector('#af-preview-wrapper p');
                if(previewHeading) previewHeading.textContent = 'Crop image';
                if(previewHint) previewHint.textContent = 'Adjust the frame, then save your updated image.';
                if(dropArea) dropArea.style.display = 'none';
                resizeModalForCrop();

                if(nextBtnLabel) nextBtnLabel.textContent = 'Save';
                const cancelBtn = document.getElementById('af-media-cancel');
                if(cancelBtn) cancelBtn.innerText = 'Cancel';
                return;
            }

            // STEP 2 → SAVE
            nextBtn.disabled = true;
            const loader = document.getElementById('af-media-next-loader');
            if(loader) loader.style.display = 'inline-block';

            const fd = new FormData();
            fd.append('csrf_token', <?= json_encode($adminContentCsrf, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>);
            fd.append('action','update_media');
            fd.append('media_type',currentMediaType);
            fd.append('media_slot',currentMediaSlot);

            const handleResponse = (j, path) => {
                if (j.success) {
                    if (currentMediaType === 'image') {
                    const imgEl = document.querySelector(`img[data-media-slot="${currentMediaSlot}"]`);
                    if (imgEl) imgEl.src = path + '?t=' + Date.now(); // reload image immediately
                    } else if (currentMediaType === 'video') {
                        const videoEl = document.querySelector('.featured-preview video');
                        if (videoEl) videoEl.src = path + '?t=' + Date.now();
                    }

                    if (modal) modal.style.display = 'none';
                    resetMediaModal();

                    // ✅ SweetAlert success
                    Swal.fire({
                        icon: 'success',
                        title: `${currentMediaType.charAt(0).toUpperCase() + currentMediaType.slice(1)} Updated`,
                        html: `<p style="font-size:16px;margin-top:8px;">${currentMediaType.charAt(0).toUpperCase() + currentMediaType.slice(1)} updated successfully!</p>`,
                        confirmButtonColor: '#49A47A',
                        confirmButtonText: 'OK'
                    });

                } else {
                    // ✅ SweetAlert error
                    Swal.fire({
                        icon: 'error',
                        title: 'Update Failed',
                        html: `<p style="font-size:16px;margin-top:8px;">${j.message}</p>`,
                        confirmButtonColor: '#d33',
                        confirmButtonText: 'OK'
                    });
                }
            };
            if(currentMediaType==='image' && cropper){
                cropper.getCroppedCanvas({width:300,height:200}).toBlob(blob => {
                    fd.append('file',blob,inputFile.files[0].name);
                    fetch(window.location.href,{method:'POST',body:fd})
                    .then(r=>r.json())
                    .then(j=>handleResponse(j,j.path))
                    .finally(()=>{
                        if(nextBtn) nextBtn.disabled = false;
                        if(loader) loader.style.display='none';
                    });
                });
            } else {
                fd.append('file',inputFile.files[0]);
                fetch(window.location.href,{method:'POST',body:fd})
                .then(r=>r.json())
                .then(j=>handleResponse(j,j.path))
                .finally(()=>{
                    if(nextBtn) nextBtn.disabled = false;
                    if(loader) loader.style.display='none';
                });
            }
        });
    }

    // ==== TEXT EDITING ====
    document.querySelectorAll('[data-edit-text]').forEach(btn=>{
        btn.addEventListener('click',()=>{
            currentTextField = btn.dataset.editText;
            const textEl = document.getElementById('text-'+currentTextField);
            const textarea = document.getElementById('af-textarea');
            const textModal = document.getElementById('af-text-modal');
            if(textEl && textarea) textarea.value = textEl.innerText;
            if(textModal) textModal.style.display='flex';
        });
    });

    ['af-text-close','af-text-cancel'].forEach(id=>{
        const el = document.getElementById(id);
        if(el){
            el.addEventListener('click',()=>{
                const textModal = document.getElementById('af-text-modal');
                if(textModal) textModal.style.display='none';
            });
        }
    });

    const textSaveBtn = document.getElementById('af-text-save');
    if(textSaveBtn){
        textSaveBtn.addEventListener('click',()=>{
            const textarea = document.getElementById('af-textarea');
            if(!textarea) return;
            const value = textarea.value;
            // --- Text update
            fetch(window.location.href, {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: new URLSearchParams({action:'update_text', field:currentTextField, value, csrf_token:<?= json_encode($adminContentCsrf, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>})
            }).then(r => r.json()).then(j => {
                const textEl = document.getElementById('text-' + currentTextField);
                const textModal = document.getElementById('af-text-modal');

                if (j.success) {
                    if (textEl) textEl.innerText = value;
                    if (textModal) textModal.style.display = 'none';

                    Swal.fire({
                        icon: 'success',
                        title: 'Text Updated',
                        html: `<p style="font-size:16px;margin-top:8px;">Text updated successfully!</p>`,
                        confirmButtonColor: '#49A47A',
                        confirmButtonText: 'OK'
                    });
                } else {
                    Swal.fire({
                        icon: 'error',
                        title: 'Update Failed',
                        html: `<p style="font-size:16px;margin-top:8px;">${j.message}</p>`,
                        confirmButtonColor: '#d33',
                        confirmButtonText: 'OK'
                    });
                }
            });
        });
    }

    // ==== DRAG & DROP ====
    if(dropArea){
        ['dragenter','dragover'].forEach(eventName=>{
            dropArea.addEventListener(eventName,e=>{
                e.preventDefault(); e.stopPropagation();
                dropArea.classList.add('drag-over');
            });
        });

        ['dragleave','drop'].forEach(eventName=>{
            dropArea.addEventListener(eventName,e=>{
                e.preventDefault(); e.stopPropagation();
                dropArea.classList.remove('drag-over');
            });
        });

        dropArea.addEventListener('drop', e=>{
            e.preventDefault(); e.stopPropagation();
            dropArea.classList.remove('drag-over');

            const files = e.dataTransfer.files;
            if(files.length && inputFile){
                inputFile.files = files;
                inputFile.dispatchEvent(new Event('change',{bubbles:true}));
            }
        });
    }

    // Optional manual file log
    if(inputFile){
        inputFile.addEventListener('change', ()=>{
            if(inputFile.files.length){
                console.log('Selected file:',inputFile.files[0]);
            }
        });
    }

});

function resetMediaModal(){
    const stepIndicator = document.querySelector('.af-step-indicator');
    if(stepIndicator) stepIndicator.style.display = 'flex'; // show for images

    const dropArea = document.getElementById('af-drop-area');
    if(dropArea) dropArea.style.display='flex';

    const previewArea = document.getElementById('af-preview-area');
    if(previewArea) previewArea.innerHTML='<div class="af-preview-empty">Choose a file to display it here.</div>';

    const previewPath = document.getElementById('af-preview-path');
    if(previewPath) previewPath.innerText='';

    const inputFile = document.getElementById('af-file-input');
    if(inputFile) inputFile.value='';

    if(cropper) cropper.destroy();
    cropper=null;
    resetModalSize();

    const nextBtn = document.getElementById('af-media-next');
    if(nextBtn){
        nextBtn.disabled=true;
    }
    const nextBtnLabel = document.getElementById('af-media-next-label');
    if(nextBtnLabel) nextBtnLabel.textContent='Next';

    const cancelBtn = document.getElementById('af-media-cancel');
    if(cancelBtn) cancelBtn.innerText='Cancel';

    const stepUpload = document.getElementById('step-upload');
    const stepCrop = document.getElementById('step-crop');
    if(stepUpload){
        stepUpload.classList.add('phase-active');
        stepUpload.classList.remove('phase-inactive','phase-completed');
    }
    if(stepCrop){
        stepCrop.classList.add('phase-inactive');
        stepCrop.classList.remove('phase-active','phase-completed');
    }
}


function resetModalSize(){
    const modalContent = document.querySelector('#af-media-modal .af-modal');
    if(modalContent){
        modalContent.classList.remove('is-crop-step');
        modalContent.style.maxWidth='900px';
        modalContent.style.width='90%';
    }
}

function resizeModalForCrop(){
    const modalContent = document.querySelector('#af-media-modal .af-modal');
    if(modalContent){
        modalContent.classList.add('is-crop-step');
        modalContent.style.maxWidth='690px';
        modalContent.style.width='94%';
    }
}

let cmfCurrentId = null;

// Elements
const cmfAddBtn = document.getElementById('cmf-add-faq-btn');
const cmfNewQ = document.getElementById('cmf-new-question');
const cmfNewA = document.getElementById('cmf-new-answer');
const cmfList = document.getElementById('cmf-faq-list');
const cmfModal = document.getElementById('cmf-faq-modal');
const cmfModalQ = document.getElementById('cmf-modal-question');
const cmfModalA = document.getElementById('cmf-modal-answer');

// Toggle add button
function cmfToggleAdd() {
    if(cmfNewQ.value.trim() && cmfNewA.value.trim()) {
        cmfAddBtn.disabled = false;
        cmfAddBtn.style.background = '#2b7a66';
        cmfAddBtn.style.cursor = 'pointer';
    } else {
        cmfAddBtn.disabled = true;
        cmfAddBtn.style.background = '#ccc';
        cmfAddBtn.style.cursor = 'not-allowed';
    }
}
cmfToggleAdd();
cmfNewQ.addEventListener('input', cmfToggleAdd);
cmfNewA.addEventListener('input', cmfToggleAdd);

// Open modal
function cmfOpenModal(id,q,a){
    cmfCurrentId=id;
    cmfModalQ.value=q;
    cmfModalA.value=a;
    cmfModal.style.display='flex';
}

// Attach existing edit buttons
document.querySelectorAll('.cmf-edit-btn').forEach(btn=>{
    btn.addEventListener('click',()=>{ cmfOpenModal(btn.dataset.id, btn.dataset.question, btn.dataset.answer); });
});

// Close modal
['cmf-modal-close','cmf-cancel-faq-btn'].forEach(id=>{
    document.getElementById(id).addEventListener('click',()=>{ cmfModal.style.display='none'; });
});

// Save edited FAQ
document.getElementById('cmf-save-faq-btn').addEventListener('click',()=>{
    const q = cmfModalQ.value.trim();
    const a = cmfModalA.value.trim();

    fetch(window.location.href,{
        method:'POST',
        headers:{'Content-Type':'application/x-www-form-urlencoded'},
        body:new URLSearchParams({action:'update_faq', id:String(cmfCurrentId), question:q, answer:a, csrf_token:<?= json_encode($adminContentCsrf, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>})
    }).then(r=>r.json()).then(res=>{
        if(res.success){
          const item = document.querySelector(`.cmf-faq-item[data-id='${cmfCurrentId}']`);
          item.querySelector('strong').innerText = q;
          item.querySelector('small').innerText = a;

          // Update edit button dataset
          const editBtn = item.querySelector('.cmf-edit-btn');
          editBtn.dataset.question = q;
          editBtn.dataset.answer = a;

          cmfModal.style.display = 'none';
          Swal.fire({icon:'success',title:'FAQ Updated',confirmButtonColor:'#49A47A'});
      }
    });
});

// Add new FAQ
cmfAddBtn.addEventListener('click',()=>{
    const q=cmfNewQ.value.trim();
    const a=cmfNewA.value.trim();
    if(!q||!a) return;

    fetch(window.location.href,{
        method:'POST',
        headers:{'Content-Type':'application/x-www-form-urlencoded'},
        body:new URLSearchParams({action:'add_faq', question:q, answer:a, csrf_token:<?= json_encode($adminContentCsrf, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>})
    }).then(r=>r.json()).then(res=>{
        if(res.success){
            const div=document.createElement('div');
            div.className='cmf-faq-item';
            div.dataset.id=res.id;
            div.innerHTML=`<div><strong>${q}</strong><br><small>${a}</small></div><button class="cmf-btn cmf-edit-btn" data-id="${res.id}" data-question="${q}" data-answer="${a}">Edit</button>`;
            cmfList.prepend(div);
            div.querySelector('.cmf-edit-btn').addEventListener('click',()=>{ cmfOpenModal(res.id,q,a); });
            const faqCountValue = document.getElementById('cmfFaqCountValue');
            const faqCountHint = document.getElementById('cmfFaqCountHint');
            const updatedFaqCount = cmfList.querySelectorAll('.cmf-faq-item').length;
            if (faqCountValue) faqCountValue.textContent = updatedFaqCount;
            if (faqCountHint) faqCountHint.textContent = `${updatedFaqCount} question${updatedFaqCount === 1 ? '' : 's'} currently available.`;
            cmfNewQ.value=''; cmfNewA.value=''; cmfToggleAdd();
            Swal.fire({icon:'success',title:'FAQ Added',confirmButtonColor:'#49A47A'});
        }else{
            Swal.fire({icon:'error',title:'Add Failed',confirmButtonColor:'#d33'});
        }
    });
});

</script>



</body>
</html>

