<?php
chdir(__DIR__ . '/..');
// adfeatured.php
require_once 'php/db_connection.php';
if (session_status() === PHP_SESSION_NONE) session_start();
include 'php/alert.php';

// --- Logout action
if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    session_unset(); 
    session_destroy();
    echo "<script>alert('You have been logged out. Session expired.'); window.location.href='homepage.php';</script>";
    exit();
}

// --- Auth check
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    echo "<script>alert('Session expired! Please login again.'); window.location.href='php/admin_login.php';</script>";
    exit();
}

// Prevent cache
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

// --- Helper: move uploaded file
function moveUploadFile($fileField, $defaultPath, $folder = "img") {
    if (!empty($_FILES[$fileField]['name'])) {
        $targetDir = "$folder/";
        if (!is_dir($targetDir)) mkdir($targetDir, 0777, true);
        $fileName = time() . "_" . preg_replace('/[^A-Za-z0-9_\-\.]/', '_', basename($_FILES[$fileField]["name"]));
        $targetFile = $targetDir . $fileName;
        if (move_uploaded_file($_FILES[$fileField]["tmp_name"], $targetFile)) {
            return $targetFile;
        }
    }
    return $defaultPath;
}

// ===== POST actions =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    // --- FAQ Handling
    if ($action === 'update_faq') {
        $id = intval($_POST['id'] ?? 0);
        $question = $_POST['question'] ?? '';
        $answer = $_POST['answer'] ?? '';

        $stmt = $pdo->prepare("UPDATE faqs SET question=:q, answer=:a WHERE id=:id");
        $success = $stmt->execute([':q'=>$question, ':a'=>$answer, ':id'=>$id]);
        echo json_encode(['success'=>$success]);
        exit;
    }

    if ($action === 'add_faq') {
        $question = $_POST['question'] ?? '';
        $answer = $_POST['answer'] ?? '';

        $stmt = $pdo->prepare("INSERT INTO faqs (question, answer) VALUES (:q,:a)");
        $success = $stmt->execute([':q'=>$question, ':a'=>$answer]);
        echo json_encode(['success'=>$success, 'id'=>$pdo->lastInsertId()]);
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
            $value = $_POST['value'] ?? '';

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
                if (!isset($_FILES[$fileField]) || !is_uploaded_file($_FILES[$fileField]['tmp_name'])) {
                    echo json_encode(['success'=>false,'message'=>'No file uploaded.']);
                    exit;
                }

                $targetFile = moveUploadFile($fileField, '');

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
</head>
<body>
<div class="featured-admin-container">
<?php include 'admin_sidebar.php'; ?>
<main class="featured-main">
<header class="featured-header">
    <div class="admin-header-left">
        <h2>Content Management</h2>
        <p class="admin-header-subtitle">Welcome, <?= $admin_username ?? 'Website Admin' ?></p>
    </div>
</header>

<div class="cm-content-shell">
  <div class="cm-secondary-nav">
    <div class="cm-search-wrap">
      <input type="text" id="cmContentSearch" placeholder="Search featured content..." autocomplete="off">
    </div>
    <div class="cm-tab-list" role="tablist" aria-label="Content management tabs">
      <button type="button" class="cm-tab-btn active" data-tab="featured" role="tab" aria-selected="true">Featured</button>
      <button type="button" class="cm-tab-btn" data-tab="faq" role="tab" aria-selected="false">FAQ</button>
      <button type="button" class="cm-tab-btn" data-tab="about" role="tab" aria-selected="false">About</button>
    </div>
  </div>

  <div class="cm-tab-panel active" data-tab="featured">
    <section id="featured-section" class="cm-panel-card">
      <h2 class="section-title">Featured</h2>
      <div class="featured-content">
        <div class="featured-box featured-preview">
          <h3>Video & Descriptions</h3>
          <video src="<?= htmlspecialchars($featured['video_path'] ?? 'img/samplevideo.mp4') ?>" controls muted></video>
          <div class="af-control-row">
            <button class="af-btn" data-open-media="video" data-media-slot="video">Upload New Video</button>
          </div>

          <div style="margin-top:16px;">
            <p>
              <strong>Description 1:</strong>
              <span id="text-description1"><?= nl2br(htmlspecialchars($featured['description1'] ?? '')) ?></span>
            </p>
            <button class="af-btn edit-btn" data-edit-text="description1">Edit</button>

            <p>
              <strong>Description 2:</strong>
              <span id="text-description2"><?= nl2br(htmlspecialchars($featured['description2'] ?? '')) ?></span>
            </p>
            <button class="af-btn edit-btn" data-edit-text="description2">Edit</button>

            <p>
              <strong>Footer Text:</strong>
              <span id="text-footer_text"><?= nl2br(htmlspecialchars($featured['footer_text'] ?? '')) ?></span>
            </p>
            <button class="af-btn edit-btn" data-edit-text="footer_text">Edit</button>
          </div>
        </div>

        <div class="featured-box right-box">
          <h3>Slider & Small Images</h3>

          <div class="featured-slider-grid">
              <?php for($i=1;$i<=4;$i++): $col="slider_image$i"; ?>
              <div>
                <img src="<?= htmlspecialchars($featured[$col] ?? 'img/sampleimage.png') ?>" 
                    alt="Slider <?= $i ?>" 
                    data-media-slot="slider<?= $i ?>">
                <div class="af-control-row">
                  <button class="af-btn" data-open-media="image" data-media-slot="slider<?= $i ?>">Upload New Photo</button>
                </div>
              </div>
              <?php endfor; ?>
            </div>

            <div class="featured-small-grid">
              <?php for($i=1;$i<=2;$i++): $col="small_image$i"; ?>
              <div>
                <img src="<?= htmlspecialchars($featured[$col] ?? 'img/sampleimage.png') ?>" 
                    alt="Small <?= $i ?>" 
                    data-media-slot="small<?= $i ?>">
                <div class="af-control-row">
                  <button class="af-btn" data-open-media="image" data-media-slot="small<?= $i ?>">Upload New Photo</button>
                </div>
              </div>
              <?php endfor; ?>
            </div>
        </div>
      </div>
      <p class="cm-tab-empty" data-empty-for="featured">No matching featured content found.</p>
    </section>
  </div>

  <div class="cm-tab-panel" data-tab="faq">
    <section id="faq-section" class="cm-panel-card">
        <h2 class="section-title">FAQ</h2>
        <div class="cmf-container">
        <div class="cmf-left-box">
            <h3>Existing FAQs</h3>
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
            <label>Question:</label>
            <textarea id="cmf-new-question"></textarea>
            <label>Answer:</label>
            <textarea id="cmf-new-answer"></textarea>
            <div style="margin-top:10px;">
                <button class="cmf-btn" id="cmf-add-faq-btn" disabled>Add FAQ</button>
            </div>
        </div>
      </div>
      <p class="cm-tab-empty" data-empty-for="faq">No matching FAQs found.</p>
    </section>
  </div>

  <div class="cm-tab-panel" data-tab="about">
    <section id="about-section" class="cm-panel-card">
        <div class="cm-about-head">
          <h2 class="section-title">About</h2>
          <button type="button" class="addnewbutton cm-about-add-btn" id="addNewBtn">Add New Gallery Item</button>
        </div>
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
    <div class="af-modal-header" style="display:flex; justify-content:center; position:relative; padding-bottom:12px; border-bottom:1px solid #eee;">
      <strong id="af-media-modal-title" style="font-size:1.2rem; color:#2b7a66;">Upload Media</strong>
      <button id="af-media-close" style="position:absolute; top:8px; right:8px; border:none; background:none; font-size:1.2rem; cursor:pointer; color:#666;">×</button>
    </div>

    <!-- Step Indicator -->
    <div class="af-step-indicator" style="margin:12px 0; display:flex; align-items:center; gap:5px;">
      <div class="af-step phase-active" data-step="1" id="step-upload">
        <div class="circle">1</div>
        <span style="font-size:0.85rem;">Upload</span>
      </div>
      <div class="phase-line" style="flex:1; height:2px; background:#ccc;"></div>
      <div class="af-step phase-inactive" data-step="2" id="step-crop">
        <div class="circle">2</div>
        <span style="font-size:0.85rem;">Crop</span>
      </div>
    </div>

    <!-- Modal Body -->
    <div class="af-modal-body" style="display:flex; gap:16px; flex-wrap:wrap;">
      <!-- Upload Column -->
      <div style="display:flex; flex-direction:column; gap:8px; align-items:center; flex:1; min-width:300px;">
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
      <div style="display:flex; flex-direction:column; flex:1; gap:8px; align-items:center; min-width:300px;">
        <div id="af-preview-wrapper" style="width:100%; text-align:center; color:#333; font-weight:500; margin-bottom:4px;">Preview Image</div>
        <div id="af-preview-area" style="width:300px; height:200px; border:1px solid #ccc; border-radius:10px; display:flex; align-items:center; justify-content:center; overflow:hidden; background:#f5f5f5;">
          <div style="color:#999;">No file selected</div>
        </div>
        <div id="af-preview-path" style="margin-top:4px; font-size:0.85rem; color:#666; word-break:break-all;"></div>
      </div>
    </div>

    <!-- Modal Actions -->
    <div class="af-actions" style="margin-top:12px; display:flex; justify-content:flex-end; gap:10px;">
      <button id="af-media-cancel" class="af-btn secondary">Cancel</button>
      <button id="af-media-next" class="af-btn" disabled>
        Next
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

const cmSearchMeta = {
  featured: { selector: '.featured-box', placeholder: 'Search featured content...' },
  faq: { selector: '.cmf-faq-item', placeholder: 'Search FAQs...' },
  about: { selector: '.gallery-card-unique', placeholder: 'Search about items...' }
};

function setActiveContentTab(tabKey) {
  if (!cmSearchMeta[tabKey]) return;

  cmTabButtons.forEach((btn) => {
    const isActive = btn.dataset.tab === tabKey;
    btn.classList.toggle('active', isActive);
    btn.setAttribute('aria-selected', isActive ? 'true' : 'false');
  });

  cmTabPanels.forEach((panel) => {
    panel.classList.toggle('active', panel.dataset.tab === tabKey);
  });

  if (cmSearchInput) {
    cmSearchInput.placeholder = cmSearchMeta[tabKey].placeholder;
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
});

if (cmSearchInput) {
  cmSearchInput.addEventListener('input', applyContentSearch);
}

setActiveContentTab('featured');

document.addEventListener('DOMContentLoaded', () => {
    const modal = document.getElementById('af-media-modal');
    const inputFile = document.getElementById('af-file-input');
    const nextBtn = document.getElementById('af-media-next');
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

            const stepIndicator = document.querySelector('.af-step-indicator');
            if(currentMediaType === 'video'){
                if(stepIndicator) stepIndicator.style.display = 'none'; // hide steps
                nextBtn.innerText = 'Save';
            } else {
                if(stepIndicator) stepIndicator.style.display = 'flex'; // show steps
                nextBtn.innerText = 'Next';
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
                    stepUpload.classList.replace('phase-active','phase-inactive');
                    stepCrop.classList.replace('phase-inactive','phase-active');
                }
                if(dropArea) dropArea.style.display = 'none';
                resizeModalForCrop();

                nextBtn.innerText = 'Save';
                const cancelBtn = document.getElementById('af-media-cancel');
                if(cancelBtn) cancelBtn.innerText = 'Cancel';
                return;
            }

            // STEP 2 → SAVE
            nextBtn.disabled = true;
            const loader = document.getElementById('af-media-next-loader');
            if(loader) loader.style.display = 'inline-block';

            const fd = new FormData();
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
                body: `action=update_text&field=${currentTextField}&value=${encodeURIComponent(value)}`
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
    if(previewArea) previewArea.innerHTML='<div style="color:#999;text-align:center;padding:10px;">No file selected</div>';

    const previewPath = document.getElementById('af-preview-path');
    if(previewPath) previewPath.innerText='';

    const inputFile = document.getElementById('af-file-input');
    if(inputFile) inputFile.value='';

    if(cropper) cropper.destroy();
    cropper=null;
    resetModalSize();

    const nextBtn = document.getElementById('af-media-next');
    if(nextBtn){
        nextBtn.innerText='Next';
        nextBtn.disabled=true;
    }

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
    const modalContent = document.querySelector('.af-modal');
    if(modalContent){
        modalContent.style.maxWidth='900px';
        modalContent.style.width='90%';
    }
}

function resizeModalForCrop(){
    const modalContent = document.querySelector('.af-modal');
    if(modalContent){
        modalContent.style.maxWidth='400px';
        modalContent.style.width='80%';
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
        body:`action=update_faq&id=${cmfCurrentId}&question=${encodeURIComponent(q)}&answer=${encodeURIComponent(a)}`
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
        body:`action=add_faq&question=${encodeURIComponent(q)}&answer=${encodeURIComponent(a)}`
    }).then(r=>r.json()).then(res=>{
        if(res.success){
            const div=document.createElement('div');
            div.className='cmf-faq-item';
            div.dataset.id=res.id;
            div.innerHTML=`<div><strong>${q}</strong><br><small>${a}</small></div><button class="cmf-btn cmf-edit-btn" data-id="${res.id}" data-question="${q}" data-answer="${a}">Edit</button>`;
            cmfList.prepend(div);
            div.querySelector('.cmf-edit-btn').addEventListener('click',()=>{ cmfOpenModal(res.id,q,a); });
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

