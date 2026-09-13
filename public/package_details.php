<?php
chdir(__DIR__ . '/..');
require_once __DIR__ . '/../php/session_security.php';
AppSessionStart();
require 'php/db_connection.php';
require_once 'php/favorites_helper.php';
require_once 'php/input_validation.php';

include 'php/alert.php';



$user = null;
if (isset($_SESSION['tourist_id'])) {
    $stmt = $pdo->prepare("SELECT * FROM tourist WHERE tourist_id = ?");
    $stmt->execute([$_SESSION['tourist_id']]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
}

// ===============================
// UNIVERSAL IMAGE FETCHER
// Returns correct path inside php/upload OR fallback
// ===============================
function getSafeImage($filename, $fallback = "img/sampleimage.png") {
    if (!$filename || trim($filename) === "") {
        return $fallback;
    }

    // Extract pure filename (avoid duplicated paths)
    $base = basename($filename);

    // Correct upload directory
    $uploadPath = __DIR__ . "/../php/upload/" . $base;

    if (file_exists($uploadPath)) {
        $optimizedBase = pathinfo($base, PATHINFO_FILENAME) . '.optimized.webp';
        $optimizedPath = __DIR__ . '/../php/upload/' . $optimizedBase;
        if (file_exists($optimizedPath)) {
            return 'php/upload/' . $optimizedBase;
        }
        return "php/upload/" . $base;
    }

    return $fallback;
}

function normalizeGoogleProfileImage(?string $value, int $size = 96): string
{
    $candidate = trim((string)$value);
    if ($candidate === '') {
        return '';
    }
    if (strpos($candidate, '//') === 0) {
        $candidate = 'https:' . $candidate;
    }
    if (!preg_match('~^https?://~i', $candidate)) {
        return $candidate;
    }
    if (stripos($candidate, 'profiles.google.com') !== false
        && preg_match('#profiles\\.google\\.com/(?:s2/photos/profile/)?([^/?#]+)(?:/picture)?#i', $candidate, $m)) {
        return 'https://profiles.google.com/' . rawurlencode($m[1]) . '/picture?sz=' . $size;
    }
    if (stripos($candidate, 'google.com/s2/photos/profile') !== false) {
        $candidate = preg_replace('/([?&])sz=\\d+/i', '$1sz=' . $size, $candidate);
        if (!preg_match('/[?&]sz=/i', $candidate)) {
            $candidate .= (strpos($candidate, '?') !== false ? '&' : '?') . 'sz=' . $size;
        }
        return $candidate;
    }
    if (stripos($candidate, 'googleusercontent.com') !== false) {
        $candidate = preg_replace('/([?&])sz=\\d+/i', '$1sz=' . $size, $candidate);
        $candidate = preg_replace('/=s\\d+-c(?=$|[?&#])/i', '=s' . $size . '-c', $candidate);
        $candidate = preg_replace('/=s\\d+(?=$|[?&#])/i', '=s' . $size, $candidate);
    }
    return $candidate;
}

function resolveProfileImage(?string $path, string $fallback = 'img/profileicon.png'): string
{
    $path = trim((string)$path);
    if ($path === '') {
        return $fallback;
    }
    if (preg_match('~^https?://~i', $path) || strpos($path, '//') === 0) {
        return normalizeGoogleProfileImage($path, 96);
    }

    $clean = ltrim(str_replace('\\', '/', $path), '/');
    $base = basename($clean);
    $candidates = [
        $clean,
        'php/upload/' . $base,
        'uploads/profile_pictures/' . $base,
        'uploads/profile_picture/' . $base,
        'uploads/profile/' . $base,
    ];

    foreach ($candidates as $candidate) {
        if (is_file(__DIR__ . '/../' . str_replace('/', DIRECTORY_SEPARATOR, $candidate))) {
            return $candidate;
        }
    }

    return $fallback;
}

$package_id = $_GET['package_id'] ?? 1;
$searchContextKeys = [
    'tab',
    'destination',
    'destination2',
    'destinations',
    'checkin',
    'checkout',
    'date',
    'adults',
    'children',
    'child_ages',
    'pax',
    'tour_date_mode',
    'tour_type',
    'tour_duration'
];
$searchContext = [];
foreach ($searchContextKeys as $key) {
    if (!isset($_GET[$key])) {
        continue;
    }
    $value = trim((string)$_GET[$key]);
    if ($value === '') {
        continue;
    }
    $searchContext[$key] = $value;
}
$searchContextQuery = http_build_query($searchContext);
$backToSearchUrl = $searchContextQuery !== ''
    ? ('search_results.php?' . $searchContextQuery)
    : 'hotel_resorts.php?tab=tours';
$returnToDetailsUrl = 'package_details.php?package_id=' . (int)$package_id;
if ($searchContextQuery !== '') {
    $returnToDetailsUrl .= '&' . $searchContextQuery;
}
$tourBookingQuery = [
    'booking_type' => 'package',
    'package_id' => (int)$package_id,
    'return' => $returnToDetailsUrl
];
foreach ($searchContext as $key => $value) {
    $tourBookingQuery[$key] = $value;
}
$tourBookingUrl = 'tour_booking.php?' . http_build_query($tourBookingQuery);

// ---------------------
// Fetch package info
// ---------------------
$stmt = $pdo->prepare("SELECT * FROM tour_packages WHERE package_id = ?");
$stmt->execute([$package_id]);
$package = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$package) {
    die("Package not found.");
}

// Auto-fetch operator_id from the selected package
$operator_id = $package['operator_id'];


// ---------------------
// Fetch itinerary steps
// ---------------------
$stmt = $pdo->prepare("SELECT * FROM package_itinerary WHERE package_id = ? ORDER BY display_order ASC");
$stmt->execute([$package_id]);
$itinerary = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Safe defaults for first step
$firstStep = $itinerary[0] ?? null;
$firstDescription = $firstStep['description'] ?? '';
$firstLocationImage = getSafeImage($firstStep['location_image'] ?? null);
$firstRouteImage    = getSafeImage($firstStep['route_image'] ?? null);
$packageMainImage   = getSafeImage($package['package_image'] ?? null);

// ---------------------
// Fetch existing feedback
// ---------------------
$stmt = $pdo->prepare("SELECT * FROM feedback WHERE package_id = ? AND moderation_status = 'published' ORDER BY created_at DESC");
$stmt->execute([$package_id]);
$feedbackList = $stmt->fetchAll(PDO::FETCH_ASSOC);
$totalReviews = count($feedbackList);
$averageRating = 0;
if ($totalReviews > 0) {
    $averageRating = array_sum(array_map(static function ($feedbackRow) {
        return (int)($feedbackRow['rating'] ?? 0);
    }, $feedbackList)) / $totalReviews;
}

// ---------------------
// Helper: JSON encode safely for JS
// ---------------------
function jsonEncodeForJS($data) {
    return json_encode($data, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
}

foreach ($itinerary as &$step) {
    $step['location_image'] = getSafeImage($step['location_image'] ?? null);
    $step['route_image']    = getSafeImage($step['route_image'] ?? null);
}
unset($step);

$itineraryJson = jsonEncodeForJS($itinerary);

$firstStepJson = jsonEncodeForJS($firstStep);

// ----------------------
// Handle AJAX feedback submission
// ----------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax']) && $_POST['ajax'] == 1) {
    header('Content-Type: application/json');

    if (!isset($_SESSION['tourist_id'])) {
        echo json_encode(['success' => false, 'message' => 'You must be logged in to submit feedback']);
        exit;
    }

    if (!AppVerifyCsrf('tourist', 'engagement', $_POST['csrf_token'] ?? null)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Invalid security token. Refresh and try again.']);
        exit;
    }

    try {
       $tourist_id = ItourValidationInt($_SESSION['tourist_id'], 'Tourist ID', 1, PHP_INT_MAX);
       $package_id = ItourValidationInt($_POST['package_id'] ?? null, 'Package ID', 1, PHP_INT_MAX);
       $rating = ItourValidationInt($_POST['rating'] ?? null, 'Rating', 1, 5);
       $comment = ItourValidationText($_POST['comment'] ?? null, 'Comment', 5000, true);
       $eligible = $pdo->prepare("SELECT 1 FROM bookings b JOIN tour_packages p ON p.package_title = b.package_name AND p.operator_id = b.operator_id
           WHERE b.tourist_id = ? AND p.package_id = ? AND LOWER(b.booking_type) = 'package'
             AND LOWER(b.is_complete) = 'completed' LIMIT 1");
       $eligible->execute([$tourist_id, $package_id]);
       if (!$eligible->fetchColumn()) throw new InvalidArgumentException('Only your completed package booking can be reviewed.');
       $duplicate = $pdo->prepare("SELECT 1 FROM feedback WHERE tourist_id = ? AND service_type = 'package' AND service_id = ? LIMIT 1");
       $duplicate->execute([$tourist_id, $package_id]);
       if ($duplicate->fetchColumn()) throw new InvalidArgumentException('You already reviewed this package.');
       $stmt = $pdo->prepare("
    INSERT INTO feedback (
        tourist_id,
        package_id,
        rating,
        comment,
        created_at,
        booking_type,
        service_type,
        service_id
    ) VALUES (?, ?, ?, ?, NOW(), ?, ?, ?)
");

$stmt->execute([
    $tourist_id,
    $package_id,
    $rating,
    $comment,
    'package',   // booking_type
    'package',   // service_type
    $package_id  // service_id
]);

        echo json_encode(['success'=>true,'message'=>'Feedback submitted']);
    } catch (Exception $e) {
        echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
    }
    exit;
}



// ----------------------
// Fetch tourist info for each feedback
// ----------------------
$feedbackWithUser = [];

foreach ($feedbackList as $feedback) {

    $stmt = $pdo->prepare("SELECT full_name, email, profile_picture 
                           FROM tourist 
                           WHERE tourist_id = ?");
    $stmt->execute([$feedback['tourist_id']]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        $user = [
            'full_name' => 'Unknown User',
            'email' => '',
            'profile_picture' => null
        ];
    }

    // Add profile image fallback
    $profileImg = resolveProfileImage($user['profile_picture'] ?? null, "img/profileicon.png");


    // Merge feedback + user info
    $feedbackWithUser[] = [
        'rating'         => $feedback['rating'],
        'comment'        => $feedback['comment'],
        'created_at'     => $feedback['created_at'],
        'full_name'      => $user['full_name'],
        'email'          => $user['email'],
        'profile_picture'=> $profileImg
    ];
}

$stmt = $pdo->prepare("SELECT * FROM tour_packages WHERE package_id = ?");
$stmt->execute([$package_id]);
$package = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$package) {
    die("Package not found.");
}

// Auto-fetch operator_id from package
$operator_id = $package['operator_id'];

// Fetch all packages for booking dropdown
$stmt = $pdo->prepare("SELECT package_id, package_title FROM tour_packages ORDER BY package_title ASC");
$stmt->execute();
$packages = $stmt->fetchAll(PDO::FETCH_ASSOC);
$packageIsFavorite = isset($_SESSION['tourist_id'])
    ? isFavorite($pdo, (int)$_SESSION['tourist_id'], 'package', (int)$package_id)
    : false;
$favoritesCsrf = favoriteCsrfToken();

?>

<html>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?php echo htmlspecialchars($package['package_title'] ?? 'Package Details'); ?></title>
  <!-- SweetAlert2 CSS -->
<link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">

  <link rel="icon" type="image/png" href="img/newlogo.png">
  <link rel="stylesheet" href="styles/favorites.css">
  <style>
body {
    font-family: 'Roboto', sans-serif;
    margin: 0;
    background: #f9f9f9;
}

.package-navbar {
    position: fixed;
    top: 0;
    width: 100%;
    background: #fff;
    display: flex;
    justify-content: flex-start; /* everything aligned left */
    align-items: center;
    padding: 0 20px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
    z-index: 1000;
    height: 70px;
    gap: 15px; /* spacing between items */
}

.package-navbar .nav-left {
    display: flex;
    align-items: center;
    gap: 15px; /* spacing between elements */
}

.package-navbar .back-btn img {
    width: 22px;
    height: 22px;
    cursor: pointer;
}

.package-navbar .nav-left a.nav-btn {
    text-decoration: none;
    font-weight: 500;
    padding: 6px 12px;
    border-radius: 6px;
    font-size: 0.9rem;
    color: #2b7a66;
    position: relative;
    transition: all 0.2s;
}

.package-navbar .nav-left a.nav-btn:hover,
.package-navbar .nav-left a.nav-btn.active {
    font-weight: bold;
    color: #246036;
}

.package-navbar .nav-left a.nav-btn.active::after {
    content: '';
    position: absolute;
    bottom: -2px;
    left: 10%;
    width: 80%;
    height: 2px;
    background-color: #246036;
    border-radius: 1px;
}

.package-navbar .book-now-btn,
.package-navbar .feedback-btn {
    text-decoration: none;
    background-color: #2b7a66;
    color: white;
    border: none;
    padding: 6px 12px;
    border-radius: 6px;
    font-weight: 500;
    font-size: 0.9rem;
    cursor: pointer;
    transition: background 0.2s;
}

.package-navbar .book-now-btn:hover,
.package-navbar .feedback-btn:hover {
    background-color: #246036;
    color: white;
}


.package-details-wrapper {
    display: flex;
    gap: 20px;
    margin-top: 70px;
    padding: 20px;
    align-items: flex-start; /* important: prevent auto-stretch */
}

.itinerary-box {
    flex: 1;
    max-width: 300px;
    background: #fff;
    border-radius: 8px;
    padding: 50px 37px;
    position: relative;
    display: flex;
    flex-direction: column;
    height: calc(100vh - 250px);       /* dynamic height bsed on content */
    overflow-y: visible; /* allow box to grow */
}


.itinerary-line {
    position: absolute;
    width: 2px;
    background: #2b7a66;
    z-index: 1;
    top: 0;
    /* remove hardcoded left */
}


/* Step dot */
.itinerary-step {
    display: flex;
    align-items: flex-start;
    gap: 12px;
    margin-bottom: 30px;
    position: relative;
}

.step-dot {
    width: 12px;
    height: 12px;
    border-radius: 50%;
    background-color: #2b7a66;
    position: relative;
    z-index: 2;
    flex-shrink: 0;
    transition: all 0.2s ease;
    /* Center the dot content for active enlargement */
    display: flex;
    align-items: center;
    justify-content: center;
}

.itinerary-step.active .step-dot {
    width: 36px;
    height: 36px;
    left: -12px;
}

.step-text h3 {
    margin: 0 0 4px 0;
    font-size: 1rem;
    color: #333;
}

.step-text p {
    margin: 0;
    font-size: 0.85rem;
    color: #555;
}

.itinerary-step.active .step-dot::after {
    content: '';
    position: absolute;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%);
    width: 20px;
    height: 20px;
    background: url('img/locationicon.png') no-repeat center center;
    background-size: contain;
    z-index: 3;
}

/* Step text */
.step-text h3 {
    margin: 0 0 4px 0;
    font-size: 1rem;
    color: #333;
}

.step-text p {
    margin: 0;
    font-size: 0.85rem;
    color: #555;
}

.itinerary-step.active .step-text h3 {
    color: #246036;
    font-weight: bold;
}

/* MIDDLE DESCRIPTION */
.description-box {
    flex: 2;
    background: #fff;
    border-radius: 8px;
    padding: 20px;
    height: calc(100vh - 190px); 
    display: flex;
    flex-direction: column;
    gap: 15px;
}

.description-title {
    margin: 20px 0 10px 0;
    font-size: 2rem;
    align-self: center;
    color: #246036;
    font-weight: bold;
}
.description-box p {
    text-indent: 30px;
    text-align: justify;
    margin: 0 0 10px 0;
    line-height: 1.5;
}

/* RIGHT IMAGES */
.images-box {
    flex: 1;
    display: flex;
    flex-direction: column;
    gap: 15px;

}

.images-box img {
    width: 400px; /* fixed width */
    height: 280px; /* optional: keep aspect ratio or fixed height */
    border-radius: 8px;
    object-fit: cover;
    height: calc(100vh - 448px); 
}

.package-info {
    display: flex;
    align-items: center;
    gap: 12px;
}

.package-name {
    font-weight: 600;
    color: #2b7a66;
    font-size: 0.95rem;
}

.package-price {
    font-weight: bold;
    color: #246036;
    font-size: 0.95rem;
}
/* Modal Overlay */
.af-modal-overlay {
  position: fixed; top:0; left:0;
  width:100%; height:100%;
  background: rgba(0,0,0,0.5);
  display: none;
  justify-content: center;
  align-items: center;
  z-index:2000;
}

/* Modal Box */
.af-modal {
  background:#fff; border-radius:12px;
  padding:24px;
  max-width:500px; width:90%;
  box-shadow:0 8px 20px rgba(0,0,0,0.2);
  display:flex; flex-direction:column; gap:12px;
}

/* Header */
.af-modal-header {
  display:flex; justify-content:center;
  position:relative; padding-bottom:8px;
  border-bottom:1px solid #eee;
}
.af-modal-header button { 
  position:absolute; right:0; top:0; border:none;
  background:none; font-size:1.5rem; cursor:pointer; color:#666;
}

/* Package Title */
.af-package-title { font-weight:600; color:#333; margin-top:12px; }

.rating {
  display: flex;
  flex-direction: row-reverse; /* 5-star on right */
  gap: 4px;
  --fill: #2b7a66;
  --empty: #ccc;    /* unselected color */
  margin-left: 0;   /* align left */
}


.rating input {
  display: none;
}

.rating label {
  cursor: pointer;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  transition: transform 0.2s;
}

.rating label svg {
  width: 30px;
  height: 30px;
  fill: var(--empty);
  stroke: var(--empty);
  transition: fill 0.3s, stroke 0.3s;
}

/* Hover: highlight all stars up to hovered */
.rating label:hover svg,
.rating label:hover ~ label svg {
  fill: var(--fill);
  stroke: var(--fill);
}

/* Checked: highlight all stars up to checked */
.rating input:checked ~ label svg,
.rating input:checked + label svg {
  fill: var(--fill);
  stroke: var(--fill);
}

/* Pop animation */
.rating input:checked + label svg {
  animation: popStar 0.3s ease forwards;
}

@keyframes popStar {
  0% { transform: scale(0.8); }
  50% { transform: scale(1.3); }
  100% { transform: scale(1); }
}



/* Comment */
#af-feedback-comment { width:100%; height:100px; padding:10px; border-radius:8px; border:1px solid #ccc; font-size:1rem; resize:vertical; }

/* Action Buttons */
.af-actions { display:flex; justify-content:flex-end; gap:10px; }
.af-btn { padding:8px 16px; border-radius:6px; border:none; font-weight:500; cursor:pointer; background-color:#2b7a66; color:white; transition:0.2s; }
.af-btn:hover { background-color:#246036; }
.af-btn.secondary { background-color:#ccc; color:#333; }
.af-btn.secondary:hover { background-color:#999; }




@media (max-width: 1024px) {
    .package-details-wrapper {
        flex-direction: column;
        margin-top: 80px;
    }

    .itinerary-box,
    .description-box,
    .images-box {
        max-width: 100%;
        height: auto;
    }

    .images-box img {
        height: 200px;
    }
}

.reviews-section {
    background: #f2f2f2;     /* light gray */
    padding: 40px 20px;      /* adds spacing */
    height: calc(100vh - 140px);
}

.reviews-wrapper {
    width: 100%;
    display: flex;
    justify-content: center;
    align-items: center;
    position: relative;   /* important! */
    overflow: hidden;
}

.review-nav {
    position: absolute;   /* absolute positioning */
    top: 50%;
    transform: translateY(-50%);
    background: rgba(255,255,255,0.8);
    border: none;
    cursor: pointer;
    border-radius: 50%;
    width: 40px;
    height: 40px;
    display: flex;
    justify-content: center;
    align-items: center;
    z-index: 10;
}

.review-nav.prev { left: 10px; }
.review-nav.next { right: 10px; }

.review-nav img {
    width: 25px;
    height: 25px;
}


.reviews-track {
    display: flex;
    gap: 20px;
    transition: transform 0.4s ease;
}

.review-card {
  width: 320px;
  flex-shrink: 0;
  background: #fff;
  border-radius: 12px;
  padding: 18px;
  box-shadow: 0 4px 12px rgba(0,0,0,0.1);
  opacity: 0.4;
  transform: scale(0.8);
  transition: all 0.35s ease;
  position: relative;
  z-index: 1;
  /* display: none; */ /* remove this */
}

.review-card.active {
    opacity: 1;
    transform: scale(1);
    z-index: 3;
    display: block;
}

.review-card.left,
.review-card.right {
    opacity: 0.7;
    transform: scale(0.9);
    z-index: 2;
    display: block;
}

.review-header {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-bottom: 10px;
}

.review-profile {
    width: 50px;
    height: 50px;
    border-radius: 50%;
    object-fit: cover;
}

.review-rating {
    color: #2b7a66;
    font-size: 1.1rem;
    margin-bottom: 10px;
}

.review-comment {
    font-size: 0.9rem;
    color: #333;
}

.review-nav {
    background: none;
    border: none;
    cursor: pointer;
    padding: 0 10px;
    z-index: 5;
}

.review-nav img {
    width: 30px;
    height: 30px;
}

/* Optional: center the middle card in the wrapper */
.reviews-wrapper {
    justify-content: center;
    perspective: 1000px; /* for better scaling effect if needed */
}

    /* ===== MODAL BACKDROP ===== */
#bookingModal {
    position: fixed;
    top: 0; left: 0;
    width: 100%;
    height: 100%;
    display: none;
    align-items: center;
    justify-content: center;
    background: rgba(0,0,0,0.45);
    z-index: 99999;
    overflow-y: auto; 
    padding: 1rem; 
}

#bookingModal.open {
    display: flex;
}

/* ===== MODAL BOX ===== */
.booking-modal {
    background: #fff;
    padding: 1.5rem 2rem;
    border-radius: 12px;
    width: 480px;
    max-width: 95%;
    max-height: 90vh;
    display: flex;
    flex-direction: column;
    position: relative;
    box-shadow: 0 15px 30px rgba(0,0,0,0.2);
    overflow: hidden;
}

/* Scrollable content inside modal */
.booking-modal form {
    overflow-y: auto;
    max-height: calc(90vh - 80px);
    display: flex;
    flex-direction: column;
    gap: 10px; /* uniform spacing */
    padding-right: 6px;
}

/* ===== CLOSE BUTTON ===== */
.close-booking {
    position: absolute;
    top: 12px;
    right: 18px;
    font-size: 1.6rem;
    cursor: pointer;
    color: #555;
}

/* ===== MODAL TITLE ===== */
.booking-modal-title {
    text-align: center;
    color: #2b7a66;
    margin-bottom: 1rem;
    font-weight: 600;
}

/* ===== STEP INDICATOR ===== */
.booking-phase-indicator {
    display: flex;
    align-items: center;
    margin-bottom: 1rem;
    gap: 8px;
}
.phase-step {
    display: flex;
    flex-direction: column;
    align-items: center;
    font-size: 0.85rem;
}
.phase-step .circle {
    width: 28px;
    height: 28px;
    border-radius: 50%;
    border: 2px solid #ccc;
    display: flex;
    align-items: center;
    justify-content: center;
    margin-bottom: 4px;
    background: #fff;
    font-weight: 600;
    transition: all 0.3s;
}
.phase-line {
    flex: 1;
    height: 2px;
    background: #ccc;
    transition: all 0.3s;
}
.phase-step.phase-active .circle {
    background: #2b7a66;
    border-color: #2b7a66;
    color: #fff;
}
.phase-step.phase-inactive .circle {
    background: #eee;
    border-color: #ccc;
    color: #999;
}

/* ===== BOOKING PHASES ===== */
.booking-phase {
    display: none;
    flex-direction: column;
    gap: 10px;
}
.booking-phase.active {
    display: flex;
}

/* ===== INPUT GROUPS & FLOATING LABELS ===== */
.booking-input-group {
    position: relative;
    display: flex;
    flex-direction: column;
    margin: 0; /* remove extra margin */
    margin-bottom: 10px;
    width: 100%;
}
.booking-input-group input,
.booking-input-group select {
    padding: 10px 12px;
    font-size: 1rem;
    border: 2px solid #ccc;
    border-radius: 6px;
    outline: none;
    transition: border-color 0.3s ease;
    width: 100%;
    box-sizing: border-box;
}
.booking-input-group input:focus,
.booking-input-group select:focus {
    border-color: #2b7a66;
}
.booking-input-group label {
    position: absolute;
    left: 12px;
    top: 10px;
    color: #999;
    font-size: 0.9rem;
    pointer-events: none;
    background: #fff;
    padding: 0 4px;
    transition: all 0.2s ease;
}
.booking-input-group input:focus + label,
.booking-input-group input:not(:placeholder-shown) + label,
.booking-input-group select:focus + label,
.booking-input-group select:not([value=""]) + label {
    top: -8px;
    font-size: 0.75rem;
    color: #3368A1;
}

/* ===== LOCATION CHECKBOXES ===== */
#locationCheckboxes {
    display: flex;
    flex-direction: column;
    gap: 4px;
    padding: 8px 10px;
    border-radius: 6px;
    width: 90%;
    max-height: auto;
    overflow-y: auto;
    margin-bottom: 10px;
}
#locationCheckboxes p {
    font-size: 15px;
    font-style: italic;
    font-weight: 500;
    margin: 0 0 4px 0;
}
#locationCheckboxes label {
    display: flex;
    align-items: center;
    font-size: 13px;
    cursor: pointer;
    gap: 6px;
    width: 100%;
}
#locationCheckboxes input[type="checkbox"] {
    width: 16px;
    height: 16px;
    accent-color: #2b7a66;
    flex-shrink: 0;
}

/* ===== PRIVACY CHECKBOX ===== */
.booking-checkbox {
    display: flex;
    align-items: center;
    font-size: 15px;
    margin: 6px 0;
}
.booking-checkbox input[type="checkbox"] {
    width: 16px;
    height: 16px;
    accent-color: #2b7a66;
    margin-right: 6px;
}

/* ===== BUTTONS ===== */
.booking-next-btn,
#submitBooking,
#prevStep {
    padding: 10px;
    border-radius: 6px;
    background-color: #2b7a66;
    color: #fff;
    border: none;
    cursor: pointer;
    font-size: 1rem;
    transition: 0.3s;
    width: 100%;
}
.booking-next-btn:hover,
#submitBooking:hover,
#prevStep:hover {
    background-color: #3368A1;
}

/* ===== NOTES & PARAGRAPHS ===== */
.booking-note {
    font-size: 15px;
    font-style: italic;
    font-weight: 500;
    margin: 0 0 6px 0;
}

/* ===== BOOKING SUMMARY ===== */
.booking-summary-card {
    background: #f9f9f9; /* light neutral background */
    padding: 15px 20px;
    border-radius: 8px;
    border: 1px solid #ddd;
    font-size: 0.95rem;
    color: #333; /* normal text color */
    font-style: normal; /* remove italics */
    line-height: 1.4;
}

/* Optional: individual summary items */
.booking-summary-card div {
    margin-bottom: 8px;
}

/* Summary headings */
.booking-summary-card h4 {
    margin: 0 0 6px 0;
    font-weight: 600;
    font-style: normal !important; /* remove italics if any */
}

/* Buttons container */
.booking-buttons {
    display: flex;
    gap: 10px;
    margin-top: 15px;
}
.booking-buttons button {
    flex: 1;
}

/* ===== Package Details Redesign ===== */
body {
    background: #f3f6f4;
    color: #163228;
}

.package-navbar {
    padding: 0 26px;
    border-bottom: 1px solid #d8e6dd;
}

.package-navbar .nav-left {
    width: 100%;
    gap: 14px;
}

.package-navbar .back-btn {
    width: 36px;
    height: 36px;
    border-radius: 50%;
    background: #eef5f0;
    display: inline-flex;
    align-items: center;
    justify-content: center;
}

.package-navbar .back-btn img {
    width: 18px;
    height: 18px;
}

.package-navbar .book-now-btn,
.package-navbar .feedback-btn {
    padding: 10px 16px;
    border-radius: 10px;
    font-weight: 600;
    font-size: 0.9rem;
}

.package-navbar .feedback-btn {
    background: #1f5a34;
}

.package-name {
    font-size: 1.05rem;
}

.package-price {
    font-size: 1rem;
}

.package-rating {
    font-size: 0.86rem;
    color: #355f4b;
    background: #eaf3ee;
    padding: 6px 10px;
    border-radius: 999px;
    font-weight: 600;
}

.about-section {
    padding: 22px 18px 0;
}

.package-details-wrapper {
    background: #fff;
    border: 1px solid #dbe8e0;
    border-radius: 16px;
    padding: 22px;
    gap: 18px;
    margin: 76px auto 0;
    max-width: 1450px;
}

.itinerary-box {
    max-width: 340px;
    border: 1px solid #e3ece6;
    border-radius: 14px;
    padding: 24px 22px 18px;
    background: #f9fcfa;
    height: auto;
}

.itinerary-header {
    margin: 0 0 16px;
    font-size: 1.08rem;
    color: #1f5a34;
}

.itinerary-step {
    margin-bottom: 18px;
    cursor: pointer;
}

.step-dot {
    width: 24px;
    height: 24px;
    background: #e2f1e8;
    border: 2px solid #2b7a66;
}

.step-number {
    font-size: 0.74rem;
    color: #1f5a34;
    font-weight: 700;
}

.itinerary-step.active .step-dot {
    width: 28px;
    height: 28px;
    left: 0;
    background: #2b7a66;
}

.itinerary-step.active .step-dot::after {
    display: none;
}

.itinerary-step.active .step-number {
    color: #fff;
}

.step-text h3 {
    font-size: 0.96rem;
    color: #1f2f28;
}

.step-text p {
    font-size: 0.83rem;
    color: #577365;
}

.description-box {
    border: 1px solid #e3ece6;
    border-radius: 14px;
    padding: 24px;
    height: auto;
    min-height: 520px;
}

.description-header {
    border-bottom: 1px solid #e8efeb;
    padding-bottom: 14px;
    margin-bottom: 14px;
}

.description-title {
    margin: 0;
    font-size: 1.55rem;
    color: #1f5a34;
    text-align: left;
}

.description-time {
    margin: 8px 0 0;
    display: inline-block;
    font-size: 0.84rem;
    color: #355f4b;
    background: #ecf5f0;
    border-radius: 999px;
    padding: 6px 10px;
    font-weight: 600;
}

.description-content p {
    text-indent: 0;
    margin: 0 0 10px;
    line-height: 1.7;
    color: #334640;
    font-size: 0.94rem;
}

.images-box {
    gap: 14px;
}

.image-card {
    background: #fff;
    border: 1px solid #e3ece6;
    border-radius: 14px;
    padding: 10px 10px 12px;
}

.image-card img {
    width: 100%;
    height: 230px;
    object-fit: cover;
    border-radius: 10px;
}

.image-card p {
    margin: 10px 2px 0;
    font-size: 0.83rem;
    color: #486759;
    font-weight: 600;
}

.reviews-section {
    margin-top: 18px;
    background: #f3f6f4;
    padding: 34px 20px 42px;
    height: auto;
}

.reviews-title {
    text-align: center;
    color: #1f5a34;
    font-weight: 700;
    margin: 0 0 18px;
    font-size: 2rem;
}

.reviews-empty {
    max-width: 720px;
    margin: 0 auto;
    background: #fff;
    border: 1px dashed #cadbce;
    color: #587264;
    text-align: center;
    border-radius: 12px;
    padding: 24px;
    font-weight: 500;
}

.review-card {
    border: 1px solid #e3ece6;
}

@media (max-width: 1200px) {
    .package-navbar {
        overflow-x: auto;
    }

    .package-details-wrapper {
        margin-top: 86px;
    }

    .itinerary-box {
        max-width: none;
    }
}

/* Refined package details experience */
:root {
    --package-ink: #12271f;
    --package-muted: #5f7169;
    --package-green: #176b55;
    --package-green-dark: #0d4f3d;
    --package-green-soft: #eaf5f0;
    --package-line: #dbe8e1;
    --package-accent: #2b8a70;
    --package-accent-dark: #176b55;
}

body {
    color: var(--package-ink);
    background:
        radial-gradient(circle at 8% 18%, rgba(197, 235, 215, .55), transparent 27rem),
        #f4f8f5;
}

.package-navbar {
    box-sizing: border-box;
    height: 72px;
    padding: 0 max(24px, calc((100vw - 1450px) / 2));
    box-shadow: 0 8px 28px rgba(26, 67, 51, .08);
}

.package-navbar .nav-left {
    min-width: 0;
}

.package-navbar .package-name {
    max-width: 320px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.package-navbar .package-price {
    margin-left: auto;
    white-space: nowrap;
}

.package-navbar .book-now-btn {
    min-height: 42px;
    padding: 0 20px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 9px;
    border-radius: 12px;
    border: 1px solid #176b55;
    background: linear-gradient(135deg, #247d65, #176b55);
    color: #fff;
    box-shadow: 0 8px 20px rgba(23, 107, 85, .24);
    font-size: .94rem;
    font-weight: 800;
    white-space: nowrap;
}

.package-navbar .book-now-btn:hover {
    color: #fff;
    background: linear-gradient(135deg, #176b55, #0d4f3d);
    box-shadow: 0 10px 24px rgba(13, 79, 61, .3);
    transform: translateY(-1px);
}

.book-now-btn svg,
.package-hero-cta svg {
    width: 18px;
    height: 18px;
    fill: none;
    stroke: currentColor;
    stroke-width: 2;
    stroke-linecap: round;
    stroke-linejoin: round;
}

.package-hero {
    max-width: 1450px;
    margin: 94px auto 0;
    padding: 0 18px;
}

.package-hero-card {
    position: relative;
    overflow: hidden;
    display: grid;
    grid-template-columns: minmax(0, 1fr) auto;
    align-items: center;
    gap: 32px;
    padding: clamp(28px, 4vw, 52px);
    border-radius: 24px;
    background:
        radial-gradient(circle at 88% 12%, rgba(255, 255, 255, .16), transparent 17rem),
        linear-gradient(135deg, #0c4938 0%, #16705a 62%, #25886e 100%);
    color: #fff;
    box-shadow: 0 20px 48px rgba(17, 78, 59, .18);
}

.package-hero-card::after {
    content: "";
    position: absolute;
    right: -80px;
    bottom: -145px;
    width: 330px;
    height: 330px;
    border: 1px solid rgba(255, 255, 255, .15);
    border-radius: 50%;
    box-shadow: 0 0 0 42px rgba(255, 255, 255, .04), 0 0 0 84px rgba(255, 255, 255, .025);
}

.package-hero-copy,
.package-hero-booking {
    position: relative;
    z-index: 1;
}

.package-eyebrow {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    margin: 0 0 12px;
    color: #ccebdd;
    font-size: .78rem;
    font-weight: 800;
    letter-spacing: .12em;
    text-transform: uppercase;
}

.package-eyebrow::before {
    content: "";
    width: 28px;
    height: 2px;
    border-radius: 99px;
    background: #8fd7be;
}

.package-hero h1 {
    max-width: 850px;
    margin: 0;
    color: #fff;
    font-size: clamp(2rem, 4vw, 3.45rem);
    line-height: 1.08;
    letter-spacing: -.035em;
}

.package-hero-subtitle {
    max-width: 720px;
    margin: 14px 0 22px;
    color: rgba(255, 255, 255, .8);
    font-size: 1rem;
    line-height: 1.7;
}

.package-quick-facts {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
}

.package-fact {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    min-height: 34px;
    padding: 0 12px;
    border: 1px solid rgba(255, 255, 255, .18);
    border-radius: 999px;
    background: rgba(255, 255, 255, .09);
    color: #f4fbf7;
    font-size: .84rem;
    font-weight: 650;
    backdrop-filter: blur(6px);
}

.package-fact svg {
    width: 16px;
    height: 16px;
    fill: none;
    stroke: #a7e2ce;
    stroke-width: 2;
}

.package-hero-booking {
    width: min(310px, 100%);
    padding: 22px;
    border: 1px solid rgba(255, 255, 255, .2);
    border-radius: 18px;
    background: rgba(255, 255, 255, .97);
    color: var(--package-ink);
    box-shadow: 0 18px 40px rgba(5, 42, 31, .2);
}

.package-hero-booking small {
    display: block;
    margin-bottom: 4px;
    color: var(--package-muted);
    font-size: .78rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .06em;
}

.package-hero-price {
    display: flex;
    align-items: baseline;
    gap: 6px;
    margin-bottom: 14px;
}

.package-hero-price strong {
    color: var(--package-green-dark);
    font-size: 2rem;
    letter-spacing: -.03em;
}

.package-hero-price span {
    color: var(--package-muted);
    font-size: .88rem;
}

.package-hero-cta {
    min-height: 52px;
    width: 100%;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 10px;
    border-radius: 13px;
    border: 1px solid #176b55;
    background: linear-gradient(135deg, #247d65, #176b55);
    color: #fff;
    text-decoration: none;
    font-weight: 850;
    box-shadow: 0 10px 22px rgba(23, 107, 85, .24);
    transition: transform .2s ease, box-shadow .2s ease;
}

.package-hero-cta:hover {
    transform: translateY(-2px);
    background: linear-gradient(135deg, #176b55, #0d4f3d);
    box-shadow: 0 14px 28px rgba(13, 79, 61, .3);
}

.package-booking-assurance {
    margin: 12px 0 0;
    color: #63756c;
    text-align: center;
    font-size: .78rem;
    line-height: 1.45;
}

.about-section {
    padding: 0 18px;
}

.package-details-wrapper {
    display: grid;
    grid-template-columns: minmax(230px, .8fr) minmax(420px, 1.65fr) minmax(260px, .95fr);
    margin: 20px auto 0;
    padding: 18px;
    gap: 18px;
    border-radius: 20px;
    box-shadow: 0 15px 40px rgba(25, 70, 52, .08);
}

.itinerary-box,
.description-box,
.image-card {
    box-shadow: 0 5px 18px rgba(26, 65, 50, .04);
}

.itinerary-box {
    max-width: none;
    position: sticky;
    top: 92px;
}

.itinerary-header {
    padding-bottom: 14px;
    border-bottom: 1px solid var(--package-line);
}

.itinerary-step {
    padding: 8px;
    margin: 0 0 6px;
    border-radius: 11px;
    transition: background .2s ease, transform .2s ease;
}

.itinerary-step:hover {
    background: #eef7f2;
    transform: translateX(2px);
}

.itinerary-step.active {
    background: #e6f3ed;
}

.description-box {
    min-width: 0;
    background: #fff;
}

.description-header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 18px;
}

.description-time {
    flex: 0 0 auto;
    margin: 0;
    min-width: 112px;
    min-height: 32px;
    box-sizing: border-box;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 6px 12px;
    text-indent: 0 !important;
    text-align: center !important;
    line-height: 1;
}

.description-content {
    padding-top: 2px;
}

.image-card {
    overflow: hidden;
    padding: 8px 8px 12px;
}

.image-card img {
    transition: transform .35s ease;
}

.image-card:hover img {
    transform: scale(1.025);
}

.reviews-section {
    border-top: 1px solid var(--package-line);
}

.reviews-title {
    font-size: clamp(1.65rem, 3vw, 2.1rem);
    letter-spacing: -.025em;
}

@media (max-width: 1100px) {
    .package-navbar .package-name,
    .package-navbar .package-rating {
        display: none;
    }

    .package-details-wrapper {
        grid-template-columns: minmax(220px, .75fr) minmax(0, 1.4fr);
    }

    .images-box {
        grid-column: 1 / -1;
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    .itinerary-box {
        top: 88px;
    }
}

@media (max-width: 760px) {
    .package-navbar {
        height: 64px;
        padding: 0 14px;
    }

    .package-navbar .nav-left {
        gap: 8px;
    }

    .package-navbar .nav-btn,
    .package-navbar .package-price,
    .package-navbar .feedback-btn {
        display: none;
    }

    .package-navbar .book-now-btn {
        margin-left: auto;
        min-height: 40px;
        padding: 0 16px;
    }

    .package-hero {
        margin-top: 80px;
        padding: 0 12px;
    }

    .package-hero-card {
        grid-template-columns: 1fr;
        gap: 22px;
        padding: 26px 20px;
        border-radius: 18px;
    }

    .package-hero-booking {
        box-sizing: border-box;
        width: 100%;
    }

    .about-section {
        padding: 0 12px;
    }

    .package-details-wrapper {
        grid-template-columns: 1fr;
        padding: 12px;
        border-radius: 16px;
    }

    .itinerary-box {
        position: static;
    }

    .description-box {
        min-height: 0;
        padding: 20px;
    }

    .description-header {
        display: block;
    }

    .description-time {
        margin-top: 10px;
    }

    .images-box {
        grid-column: auto;
        grid-template-columns: 1fr;
    }
}

</style>
<link rel="stylesheet" href="styles/package_details_redesign.css?v=<?php echo (int)@filemtime(__DIR__ . '/../styles/package_details_redesign.css'); ?>">
</head>
<body>

<div class="package-navbar">
  <div class="nav-left">
    <a class="back-btn" href="<?php echo htmlspecialchars($backToSearchUrl, ENT_QUOTES); ?>" aria-label="Back to search results">
      <img src="img/prevchevron.png" alt="Back">
    </a>

    <a href="#about" class="nav-btn active">ABOUT</a>
    <a href="#reviews" class="nav-btn">REVIEWS</a>

    <span class="package-name"><?php echo htmlspecialchars($package['package_title']); ?></span>
    <span class="package-price">₱<?php echo number_format($package['price'], 2); ?>/pax</span>
    <span class="package-rating">★ <?php echo number_format($averageRating, 1); ?> (<?php echo (int)$totalReviews; ?> reviews)</span>

    <a
      class="book-now-btn"
      href="<?php echo htmlspecialchars($tourBookingUrl); ?>"
      aria-label="Book <?php echo htmlspecialchars($package['package_title']); ?> now"
    >
      <svg aria-hidden="true" viewBox="0 0 24 24"><path d="M8 2v3M16 2v3M3 9h18M5 4h14a2 2 0 0 1 2 2v14H3V6a2 2 0 0 1 2-2Z"/><path d="m9 15 2 2 4-4"/></svg>
      Book Now
    </a>



    <?php if(isset($_SESSION['tourist_id'])): ?>
      <button class="feedback-btn" onclick="goToTouristReviews()">
        My Reviews
      </button>
    <?php else: ?>
      <button class="feedback-btn" onclick="Swal.fire({icon:'warning',title:'Please log in and review in your account',confirmButtonColor:'#49A47A'})">
        My Reviews
      </button>
    <?php endif; ?>
  </div>
</div>

<main>
<section class="package-hero" aria-labelledby="packageHeroTitle">
  <div class="package-hero-card">
    <figure class="package-hero-media">
      <img src="<?php echo htmlspecialchars($packageMainImage); ?>" alt="<?php echo htmlspecialchars($package['package_title']); ?>" decoding="async" fetchpriority="high">
      <figcaption><span>Mercedes, Camarines Norte</span><strong>Your island story starts here</strong></figcaption>
    </figure>
    <div class="package-hero-copy">
      <p class="package-eyebrow"><span></span> Thoughtfully curated local experience</p>
      <h1 id="packageHeroTitle"><?php echo htmlspecialchars($package['package_title']); ?></h1>
      <p class="package-hero-subtitle">
        Follow a day shaped by island views, local stories, and unhurried moments in Mercedes.
      </p>
      <div class="package-quick-facts" aria-label="Package highlights">
        <span class="package-fact">
          <svg aria-hidden="true" viewBox="0 0 24 24"><path d="M12 21s7-5.2 7-12a7 7 0 1 0-14 0c0 6.8 7 12 7 12Z"/><circle cx="12" cy="9" r="2.4"/></svg>
          Mercedes, Camarines Norte
        </span>
        <span class="package-fact">
          <svg aria-hidden="true" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>
          <?php echo htmlspecialchars((string)($package['package_range'] ?? $package['package_type'] ?? 'Guided tour')); ?>
        </span>
        <span class="package-fact">
          <svg aria-hidden="true" viewBox="0 0 24 24"><path d="m12 3 2.7 5.5 6.1.9-4.4 4.3 1 6.1-5.4-2.9-5.4 2.9 1-6.1-4.4-4.3 6.1-.9L12 3Z"/></svg>
          <?php echo $totalReviews > 0 ? number_format($averageRating, 1) . ' guest rating' : 'New experience'; ?>
        </span>
      </div>
    </div>

    <aside class="package-hero-booking" aria-label="Package booking">
      <button
        type="button"
        class="favorite-toggle<?= $packageIsFavorite ? ' is-favorite' : '' ?>"
        data-favorite-type="package"
        data-favorite-id="<?php echo (int)$package_id; ?>"
        aria-label="<?php echo $packageIsFavorite ? 'Remove from favorites' : 'Add to favorites'; ?>"
        aria-pressed="<?php echo $packageIsFavorite ? 'true' : 'false'; ?>"
        title="<?php echo $packageIsFavorite ? 'Remove from favorites' : 'Add to favorites'; ?>"
      >
        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 20.4c-5.2-3.4-8.4-6.2-8.4-10a4.8 4.8 0 0 1 8.4-3.1 4.8 4.8 0 0 1 8.4 3.1c0 3.8-3.2 6.6-8.4 10Z"></path></svg>
      </button>
      <small>From</small>
      <div class="package-hero-price">
        <strong>₱<?php echo number_format((float)$package['price'], 2); ?></strong>
        <span>per person</span>
      </div>
      <a class="package-hero-cta" href="<?php echo htmlspecialchars($tourBookingUrl); ?>">
        <svg aria-hidden="true" viewBox="0 0 24 24"><path d="M8 2v3M16 2v3M3 9h18M5 4h14a2 2 0 0 1 2 2v14H3V6a2 2 0 0 1 2-2Z"/><path d="m9 15 2 2 4-4"/></svg>
        Check availability
      </a>
      <p class="package-booking-assurance"><span aria-hidden="true">&#10003;</span> You can review every detail before confirming</p>
    </aside>
  </div>
</section>


<section id="about" class="about-section">
<header class="section-heading itinerary-heading">
  <div>
    <p class="section-kicker">The journey</p>
    <h2>Your day, mapped out</h2>
  </div>
  <p>Choose any stop on the route to preview what happens, where you will go, and what you will see.</p>
</header>
<div class="package-details-wrapper">
  <div class="itinerary-box">
    <div class="itinerary-header">
      <div><span><?php echo count($itinerary); ?></span> planned stops</div>
      <small>Tap a stop to explore</small>
    </div>
    <div class="itinerary-steps">
      <svg class="itinerary-line" aria-hidden="true" viewBox="0 0 100 100" preserveAspectRatio="none">
        <path d="M 50 0 C 86 10, 14 20, 50 32 S 86 53, 50 65 S 14 87, 50 100" pathLength="1" />
      </svg>
      <?php foreach($itinerary as $index => $step): ?>
        <button class="itinerary-step <?php echo $index === 0 ? 'active' : ''; ?>" type="button" data-step-id="<?php echo $step['itinerary_id']; ?>" data-step-index="<?php echo $index + 1; ?>" aria-pressed="<?php echo $index === 0 ? 'true' : 'false'; ?>">
          <span class="step-dot"><span class="step-number"><?php echo $index + 1; ?></span></span>
          <div class="step-text">
            <h3><?php echo htmlspecialchars($step['step_title']); ?></h3>
            <p><?php echo htmlspecialchars($step['start_time'] . ' - ' . $step['end_time']); ?></p>
          </div>
        </button>
      <?php endforeach; ?>
      <?php if (empty($itinerary)): ?>
        <p class="itinerary-empty">The detailed itinerary will be available soon.</p>
      <?php endif; ?>
    </div>

  <div class="description-box">
    <p class="description-kicker">
      <span class="description-stop-icon" aria-hidden="true">
        <svg viewBox="0 0 24 24"><path d="M12 21s6-4.7 6-11a6 6 0 1 0-12 0c0 6.3 6 11 6 11Z"/><circle cx="12" cy="10" r="2"/></svg>
      </span>
      <span id="descriptionKickerLabel">Stop <?php echo $firstStep ? '01' : '--'; ?></span>
    </p>
    <div class="description-header">
      <h2 id="descriptionTitle" class="description-title"><?php echo htmlspecialchars($firstStep['step_title'] ?? 'Description'); ?></h2>
      <p id="descriptionTime" class="description-time"><?php echo htmlspecialchars(trim(($firstStep['start_time'] ?? '') . ' - ' . ($firstStep['end_time'] ?? '')) ?: 'Schedule to be announced'); ?></p>
    </div>
    <div id="stepDescription" class="description-content"></div>
  </div>
</div>

  <div class="images-box">
    <div class="image-card">
      <img id="stepLocationImage" src="<?php echo $firstLocationImage; ?>" alt="Step Image" loading="lazy" decoding="async">
      <p><span>01</span> Destination preview</p>
    </div>
    <div class="image-card">
      <img id="stepRouteImage" src="<?php echo $firstRouteImage; ?>" alt="Route Image" loading="lazy" decoding="async">
      <p><span>02</span> Route and area view</p>
    </div>
  </div>
</div>

</section>
</main>


<!-- REVIEWS SECTION -->
<section id="reviews" class="reviews-section">
  <div class="reviews-heading">
    <div>
      <span>Traveler feedback</span>
      <h2 class="reviews-title">Guest Reviews</h2>
      <p><?php echo $totalReviews > 0 ? number_format($averageRating, 1) . ' average from ' . (int)$totalReviews . ' verified review' . ($totalReviews !== 1 ? 's' : '') : 'Be the first guest to share an experience.'; ?></p>
    </div>
    <?php if(isset($_SESSION['tourist_id'])): ?>
      <button type="button" class="reviews-account-link" onclick="goToTouristReviews()">My Reviews</button>
    <?php endif; ?>
  </div>

  <?php if (empty($feedbackWithUser)): ?>
    <div class="reviews-empty">No reviews yet for this package. Completed tourists can leave a review from their account history.</div>
  <?php else: ?>
  <div class="reviews-wrapper">
    <button class="review-nav prev"><img src="img/prevchevron.png" alt="Previous"></button>

    <div class="reviews-track">
      <?php foreach($feedbackWithUser as $feedback): ?>
      <div class="review-card">
        <div class="review-header">
          <img src="<?php echo htmlspecialchars($feedback['profile_picture']); ?>" alt="Profile" class="review-profile" loading="lazy" decoding="async" referrerpolicy="no-referrer">
          <div>
            <strong><?php echo htmlspecialchars($feedback['full_name']); ?></strong><br>
            <small><?php echo htmlspecialchars($feedback['email']); ?></small>
          </div>
        </div>

        <div class="review-rating">
          <?php
            $r = intval($feedback['rating']);
            for($i = 1; $i <= 5; $i++){
               echo ($i <= $r) ? '★' : '☆';
            }
          ?>
        </div>

        <p class="review-comment"><?php echo htmlspecialchars($feedback['comment']); ?></p>
      </div>
      <?php endforeach; ?>
    </div>

    <button class="review-nav next"><img src="img/nextchevron.png" alt="Next"></button>
  </div>
  <?php endif; ?>
</section>


<!-- SweetAlert2 JS -->
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
window.RecentlyViewedConfig = {
    accountId: <?php echo jsonEncodeForJS((string)($_SESSION['tourist_id'] ?? '')); ?>
};
</script>
<script src="js/recently_viewed.js?v=<?php echo (int)@filemtime(__DIR__ . '/../js/recently_viewed.js'); ?>"></script>
<script>
window.FavoritesConfig = {
    endpoint: 'php/favorites_api.php',
    csrfToken: <?php echo jsonEncodeForJS($favoritesCsrf); ?>,
    loginUrl: 'login.php'
};
</script>
<script src="js/favorites.js"></script>
<script>
window.RecentlyViewed?.add({
    type: "package",
    id: <?php echo json_encode((int)$package_id); ?>,
    name: <?php echo jsonEncodeForJS((string)($package['package_title'] ?? 'Tour Package')); ?>,
    image: <?php echo jsonEncodeForJS($packageMainImage); ?>,
    subtitle: <?php echo jsonEncodeForJS(trim((string)($package['destination'] ?? 'Mercedes') . ' · Tour Package')); ?>,
    price: <?php echo json_encode((float)($package['price'] ?? 0)); ?>,
    priceUnit: "/pax",
    rating: <?php echo json_encode((float)$averageRating); ?>,
    reviewCount: <?php echo json_encode((int)$totalReviews); ?>,
    href: <?php echo jsonEncodeForJS('package_details.php?package_id=' . (int)$package_id); ?>
});

const steps = document.querySelectorAll('.itinerary-step');
const itineraryData = <?php echo $itineraryJson; ?>;
const descContainer = document.getElementById('stepDescription');
const descTitle = document.getElementById('descriptionTitle');
const descTime = document.getElementById('descriptionTime');
const descKicker = document.getElementById('descriptionKickerLabel');
const locImg = document.getElementById('stepLocationImage');
const routeImg = document.getElementById('stepRouteImage');


// Function to format text into paragraphs (3 sentences per paragraph)
function renderDescription(container, text, sentencesPerParagraph = 3) {
    const sentenceRegex = /([^.!?]+[.!?]+)/g;
    const cleanText = String(text || '').trim();
    const sentences = cleanText.match(sentenceRegex) || [cleanText || 'More details for this stop will be available soon.'];
    container.replaceChildren();
    for (let i = 0; i < sentences.length; i += sentencesPerParagraph) {
        const paragraph = document.createElement('p');
        paragraph.textContent = sentences.slice(i, i + sentencesPerParagraph).join(' ');
        container.appendChild(paragraph);
    }
}

// Initial description and title
const firstStepDescription = <?php echo jsonEncodeForJS($firstDescription); ?>;
const firstStepTitle = <?php echo jsonEncodeForJS($firstStep['step_title'] ?? 'Description'); ?>;
if (descContainer) {
    renderDescription(descContainer, firstStepDescription);
}
if (descTitle) {
    descTitle.textContent = firstStepTitle;
}

// Update description, title, images when clicking on steps
steps.forEach(step => {
    step.addEventListener('click', () => {
        steps.forEach(s => {
            s.classList.remove('active');
            s.setAttribute('aria-pressed', 'false');
        });
        step.classList.add('active');
        step.setAttribute('aria-pressed', 'true');

        const stepId = step.dataset.stepId;
        const stepData = itineraryData.find(s => String(s.itinerary_id) === String(stepId));
        if (!stepData) {
            return;
        }

        descTitle.textContent = stepData.step_title;
        renderDescription(descContainer, stepData.description);
        if (descKicker) {
            descKicker.textContent = `Stop ${String(step.dataset.stepIndex || '').padStart(2, '0')}`;
        }
        if (descTime) {
            const stepRange = `${stepData.start_time || ''} - ${stepData.end_time || ''}`.trim();
            descTime.textContent = stepRange === '-' ? 'Schedule to be announced' : stepRange;
        }

        locImg.src = stepData.location_image || 'img/sampleimage.png';
        routeImg.src = stepData.route_image || 'img/sampleimage.png';
    });

    step.addEventListener('keydown', event => {
        if (event.key !== 'Enter' && event.key !== ' ') return;
        event.preventDefault();
        step.click();
    });
});

function goToTouristReviews() {
    localStorage.setItem('activeTab', 'history');
    window.location.href = 'php/profile.php#history';
}

// REVIEW CAROUSEL LOGIC
const track = document.querySelector('.reviews-track');
const cards = document.querySelectorAll('.review-card');
const prevBtn = document.querySelector('.review-nav.prev');
const nextBtn = document.querySelector('.review-nav.next');
const tabletReviewsQuery = window.matchMedia('(min-width: 761px) and (max-width: 1024px) and (min-height: 600px)');

let index = cards.length > 2 ? 1 : 0;

function updateCarousel() {
    if (!track || !cards.length) {
        return;
    }

    cards.forEach((card, i) => {
        card.classList.remove('active', 'left', 'right');

        if (i === index) card.classList.add('active');
        else if (i === index - 1) card.classList.add('left');
        else if (i === index + 1) card.classList.add('right');
    });

    // Move track so center card is always centered
    const trackStyles = window.getComputedStyle(track);
    const trackGap = parseFloat(trackStyles.columnGap || trackStyles.gap) || 0;
    const cardWidth = cards[0].offsetWidth + trackGap;
    const isMobileReviews = window.matchMedia('(max-width: 820px)').matches;
    const isTabletReviews = tabletReviewsQuery.matches;
    const offset = isTabletReviews
        ? (track.offsetWidth / 2) - (cardWidth * (index + 0.5))
        : (isMobileReviews ? 0 : (track.offsetWidth / 2) - (cardWidth * (index + 0.5)));
    track.style.transform = `translateX(${offset}px)`;
}

if (prevBtn && nextBtn) {
    if (cards.length <= 1) {
        prevBtn.style.display = 'none';
        nextBtn.style.display = 'none';
    }

    prevBtn.addEventListener('click', () => {
        index = (index - 1 + cards.length) % cards.length;
        updateCarousel();
    });

    nextBtn.addEventListener('click', () => {
        index = (index + 1) % cards.length;
        updateCarousel();
    });
}

// Initial load
window.addEventListener('load', () => {
    updateCarousel();   // keep carousel logic
    setActiveNav();     // set initial active nav
});
window.addEventListener('resize', updateCarousel);

// Sections and nav links
const sections = document.querySelectorAll('section');
const navLinks = document.querySelectorAll('.nav-btn');

// Function to set active nav based on scroll
function setActiveNav() {
    let scrollPos = window.scrollY + 70; // 70px offset for fixed navbar
    let activeSet = false;

    sections.forEach(sec => {
        const top = sec.offsetTop;
        const bottom = top + sec.offsetHeight;

        if(scrollPos >= top && scrollPos < bottom){
            navLinks.forEach(link => link.classList.remove('active'));
            const activeLink = document.querySelector(`.nav-btn[href="#${sec.id}"]`);
            if(activeLink) activeLink.classList.add('active');
            activeSet = true;
        }
    });

    // If no section matches (top of page), make About active
    if(!activeSet){
        navLinks.forEach(link => link.classList.remove('active'));
        const aboutLink = document.querySelector('.nav-btn[href="#about"]');
        if(aboutLink) aboutLink.classList.add('active');
    }
}

// Listen to scroll
window.addEventListener('scroll', setActiveNav);

// Smooth scroll for nav links
// Smooth scroll for nav links with 60px offset
navLinks.forEach(link => {
    link.addEventListener('click', e => {
        e.preventDefault();
        const target = document.querySelector(link.getAttribute('href'));
        if (target) {
            const targetPos = target.offsetTop - 70; // subtract navbar height
            window.scrollTo({
                top: targetPos,
                behavior: 'smooth'
            });
        }
    });
});

</script>


<script src="js/booking-payment-confirmation.js"></script>
<script src="js/mobile-scroll.js"></script>
</body>
</html>

