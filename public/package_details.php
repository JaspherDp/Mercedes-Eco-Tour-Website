<?php
chdir(__DIR__ . '/..');
session_start();
require 'php/db_connection.php';

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
        return "php/upload/" . $base;
    }

    return $fallback;
}

$package_id = $_GET['package_id'] ?? 1;

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

// ---------------------
// Fetch existing feedback
// ---------------------
$stmt = $pdo->prepare("SELECT * FROM feedback WHERE package_id = ? ORDER BY created_at DESC");
$stmt->execute([$package_id]);
$feedbackList = $stmt->fetchAll(PDO::FETCH_ASSOC);

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

    $tourist_id = intval($_SESSION['tourist_id']);
    $package_id = intval($_POST['package_id'] ?? 0);
    $rating = intval($_POST['rating'] ?? 0);
    $comment = trim($_POST['comment'] ?? '');

    if ($package_id <= 0 || $rating <= 0) {
        echo json_encode(['success'=>false,'message'=>'Invalid package or rating']);
        exit;
    }

    try {
        $stmt = $pdo->prepare("INSERT INTO feedback (tourist_id, package_id, rating, comment, created_at) 
                               VALUES (?, ?, ?, ?, NOW())");
        $stmt->execute([$tourist_id, $package_id, $rating, $comment]);

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
    $profileImg = getSafeImage($user['profile_picture'] ?? null, "img/profileicon.png");


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

?>

<html>
<head>
  <title><?php echo htmlspecialchars($package['package_title'] ?? 'Package Details'); ?></title>
  <!-- SweetAlert2 CSS -->
<link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">

  <link rel="icon" type="image/png" href="img/newlogo.png">
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
  --fill: #ffc73a;  /* yellow */
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
    color: #ffc73a;
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

</style>
</head>
<body>

<div class="package-navbar">
  <div class="nav-left">
    <div class="back-btn" onclick="window.location.href='tourss.php'">
      <img src="img/prevchevron.png" alt="Back">
    </div>

    <a href="#about" class="nav-btn active">ABOUT</a>
    <a href="#reviews" class="nav-btn">REVIEWS</a>

    <span class="package-name"><?php echo htmlspecialchars($package['package_title']); ?></span>
    <span class="package-price">₱<?php echo number_format($package['price'], 2); ?>/pax</span>

    <a
      class="book-now-btn"
      href="tour_booking.php?booking_type=package&amp;package_id=<?php echo (int)$package_id; ?>&amp;return=<?php echo rawurlencode('package_details.php?package_id=' . (int)$package_id); ?>"
    >
      Book Now
    </a>



    <?php if(isset($_SESSION['tourist_id'])): ?>
      <button class="feedback-btn" 
        onclick="openFeedbackModal(<?php echo $package_id; ?>, '<?php echo addslashes($package['package_title']); ?>')">
        Submit Feedback
      </button>
    <?php else: ?>
      <button class="feedback-btn" onclick="Swal.fire({icon:'warning',title:'Please log in to submit feedback',confirmButtonColor:'#49A47A'})">
        Submit Feedback
      </button>
    <?php endif; ?>
  </div>
</div>


<section id="about" class="about-section">
<div class="package-details-wrapper">
  <div class="itinerary-box">
    <div class="itinerary-line" id="itineraryLine"></div>
    <?php foreach($itinerary as $index => $step): ?>
  <div class="itinerary-step <?php echo $index === 0 ? 'active' : ''; ?>" data-step-id="<?php echo $step['itinerary_id']; ?>">
    <span class="step-dot"></span>
    <div class="step-text">
      <h3><?php echo htmlspecialchars($step['step_title']); ?></h3>
      <p><?php echo htmlspecialchars($step['start_time'] . ' - ' . $step['end_time']); ?></p>
    </div>
  </div>
<?php endforeach; ?>

  </div>

<!-- MIDDLE: Description -->
<div class="description-box">
    <h2 id="descriptionTitle" class="description-title"><?php echo htmlspecialchars($firstStep['step_title'] ?? 'Description'); ?></h2>
    <div id="stepDescription"></div>
</div>


  <!-- RIGHT: Images -->
  <div class="images-box">
    <img id="stepLocationImage" src="<?php echo $firstLocationImage; ?>" alt="Step Image">
    <img id="stepRouteImage" src="<?php echo $firstRouteImage; ?>" alt="Route Image">
  </div>
</div>

</section>


<div id="af-feedback-modal" class="af-modal-overlay">
  <div class="af-modal">
    <!-- Header -->
    <div class="af-modal-header">
      <strong>Submit Feedback</strong>
      <button id="af-feedback-close">×</button>
    </div>

    <!-- Package Title -->
    <p class="af-package-title">
      Package: <span id="af-feedback-package-title">Package Name</span>
    </p>

    <div style="display:flex; justify-content:flex-start;">
        <div class="rating">
  <input type="radio" id="star-1" name="star-radio" value="5">
  <label for="star-1">
    <svg viewBox="0 0 24 24"><path d="M12,17.27L18.18,21L16.54,13.97L22,9.24L14.81,8.62L12,2L9.19,8.62L2,9.24L7.45,13.97L5.82,21L12,17.27Z"/></svg>
  </label>

  <input type="radio" id="star-2" name="star-radio" value="4">
  <label for="star-2">
    <svg viewBox="0 0 24 24"><path d="M12,17.27L18.18,21L16.54,13.97L22,9.24L14.81,8.62L12,2L9.19,8.62L2,9.24L7.45,13.97L5.82,21L12,17.27Z"/></svg>
  </label>

  <input type="radio" id="star-3" name="star-radio" value="3">
  <label for="star-3">
    <svg viewBox="0 0 24 24"><path d="M12,17.27L18.18,21L16.54,13.97L22,9.24L14.81,8.62L12,2L9.19,8.62L2,9.24L7.45,13.97L5.82,21L12,17.27Z"/></svg>
  </label>

  <input type="radio" id="star-4" name="star-radio" value="2">
  <label for="star-4">
    <svg viewBox="0 0 24 24"><path d="M12,17.27L18.18,21L16.54,13.97L22,9.24L14.81,8.62L12,2L9.19,8.62L2,9.24L7.45,13.97L5.82,21L12,17.27Z"/></svg>
  </label>

  <input type="radio" id="star-5" name="star-radio" value="1">
  <label for="star-5">
    <svg viewBox="0 0 24 24"><path d="M12,17.27L18.18,21L16.54,13.97L22,9.24L14.81,8.62L12,2L9.19,8.62L2,9.24L7.45,13.97L5.82,21L12,17.27Z"/></svg>
  </label>
</div>
    </div>


    <!-- Comment -->
    <textarea id="af-feedback-comment" placeholder="Write your feedback (optional)"></textarea>

    <!-- Actions -->
    <div class="af-actions">
      <button id="af-feedback-submit" class="af-btn">Submit</button>
      <button id="af-feedback-cancel" class="af-btn secondary">Cancel</button>
    </div>
  </div>
</div>

<!-- REVIEWS SECTION -->
<section id="reviews" class="reviews-section">
  <h2 style="text-align:center; color:#246036; font-weight:bold; margin-bottom:20px;">Reviews</h2>

  <div class="reviews-wrapper">
    <button class="review-nav prev"><img src="img/prevchevron.png" alt="Previous"></button>

    <div class="reviews-track">
      <?php foreach($feedbackList as $feedback): 
        // Fetch tourist info for this feedback
        $stmt = $pdo->prepare("SELECT full_name, email, profile_picture FROM tourist WHERE tourist_id = ?");
        $stmt->execute([$feedback['tourist_id']]);
        $tourist = $stmt->fetch(PDO::FETCH_ASSOC);

        // Use tourist profile picture or default if empty
        $profileImg = !empty($tourist['profile_picture']) ? $tourist['profile_picture'] : 'img/profileicon.png';
      ?>
      
      <div class="review-card">
        <div class="review-header">
          <img src="<?php echo htmlspecialchars($profileImg); ?>" alt="Profile" class="review-profile">
          <div>
            <strong><?php echo htmlspecialchars($tourist['full_name']); ?></strong><br>
            <small><?php echo htmlspecialchars($tourist['email']); ?></small>
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
</section>


<!-- SweetAlert2 JS -->
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
const steps = document.querySelectorAll('.itinerary-step');
const descContainer = document.getElementById('stepDescription');
const descTitle = document.getElementById('descriptionTitle');
const locImg = document.getElementById('stepLocationImage');
const routeImg = document.getElementById('stepRouteImage');


// Function to format text into paragraphs (3 sentences per paragraph)
function formatDescription(text, sentencesPerParagraph = 3) {
    const sentenceRegex = /([^.!?]+[.!?]+)/g;
    const sentences = text.match(sentenceRegex) || [text];
    const paragraphs = [];
    for (let i = 0; i < sentences.length; i += sentencesPerParagraph) {
        paragraphs.push(sentences.slice(i, i + sentencesPerParagraph).join(' '));
    }
    return paragraphs.map(p => `<p>${p}</p>`).join('');
}

// Initial description and title
const firstStepDescription = <?php echo json_encode($firstDescription); ?>;
const firstStepTitle = <?php echo json_encode($firstStep['step_title'] ?? 'Description'); ?>;
descContainer.innerHTML = formatDescription(firstStepDescription);
descTitle.textContent = firstStepTitle;

// Update description, title, images when clicking on steps
steps.forEach(step => {
    step.addEventListener('click', () => {
        steps.forEach(s => s.classList.remove('active'));
        step.classList.add('active');

        const stepId = step.dataset.stepId;
        const stepData = <?php echo json_encode($itinerary); ?>.find(s => s.itinerary_id == stepId);

        descTitle.textContent = stepData.step_title;
        descContainer.innerHTML = formatDescription(stepData.description);

        locImg.src = stepData.location_image || 'img/sampleimage.png';
        routeImg.src = stepData.route_image || 'img/sampleimage.png';
    });
});

// Update itinerary line
function updateLine() {
    if (!steps.length) return;

    const line = document.getElementById('itineraryLine');
    const firstDot = steps[0].querySelector('.step-dot');
    const lastDot = steps[steps.length - 1].querySelector('.step-dot');
    const container = document.querySelector('.itinerary-box');

    const containerRect = container.getBoundingClientRect();
    const firstDotRect = firstDot.getBoundingClientRect();
    const lastDotRect = lastDot.getBoundingClientRect();

    const top = firstDotRect.top - containerRect.top + firstDotRect.height / 2;
    const bottom = lastDotRect.top - containerRect.top + lastDotRect.height / 2;
    line.style.top = top + 'px';
    line.style.height = (bottom - top) + 'px';

    const left = firstDotRect.left - containerRect.left + firstDotRect.width / 2;
    line.style.left = left + 'px';
}

window.addEventListener('load', updateLine);
window.addEventListener('resize', updateLine);

// ----------------------
// FEEDBACK MODAL JS
// ----------------------
let selectedRating = 0;
let feedbackPackageId = null;

function openFeedbackModal(packageId, packageTitle){
    feedbackPackageId = packageId;
    selectedRating = 0;
    document.getElementById('af-feedback-package-title').innerText = packageTitle;
    document.getElementById('af-feedback-modal').style.display = 'flex';
    document.getElementById('af-feedback-comment').value = '';
    document.querySelectorAll('.rating input').forEach(i => i.checked = false);
}

// Close modal
['af-feedback-close','af-feedback-cancel'].forEach(id=>{
    document.getElementById(id).addEventListener('click', ()=>document.getElementById('af-feedback-modal').style.display='none');
});

// Star selection
document.querySelectorAll('.rating input').forEach(input=>{
    input.addEventListener('change',()=>selectedRating=parseInt(input.value));
});

document.getElementById('af-feedback-submit').addEventListener('click', () => {
    if (selectedRating === 0) {
        Swal.fire({
            icon: 'warning',
            title: 'Select a rating',
            confirmButtonColor: '#49A47A'
        });
        return;
    }

    const comment = document.getElementById('af-feedback-comment').value;
    const fd = new FormData();
    fd.append('package_id', feedbackPackageId);
    fd.append('rating', selectedRating);
    fd.append('comment', comment);
    fd.append('ajax', 1); // tells PHP it's an AJAX request

    fetch('package_details.php', {
        method: 'POST',
        body: fd
    })
    .then(response => response.json())
    .then(data => {
        // Always hide modal, even if error
        document.getElementById('af-feedback-modal').style.display = 'none';

        if (data.success) {
            Swal.fire({
                icon: 'success',
                title: 'Feedback Submitted',
                confirmButtonColor: '#49A47A'
            });
        } else {
            Swal.fire({
                icon: 'error',
                title: 'Submission Failed',
                html: `<p>${data.message}</p>`,
                confirmButtonColor: '#d33'
            });
        }
    })
    .catch(err => {
        console.error(err);
        document.getElementById('af-feedback-modal').style.display = 'none';
        Swal.fire({
            icon: 'error',
            title: 'Submission Failed',
            html: '<p>Error sending feedback</p>',
            confirmButtonColor: '#d33'
        });
    });
});

// REVIEW CAROUSEL LOGIC
const track = document.querySelector('.reviews-track');
const cards = document.querySelectorAll('.review-card');
const prevBtn = document.querySelector('.review-nav.prev');
const nextBtn = document.querySelector('.review-nav.next');

let index = 2; // center card

function updateCarousel() {
    cards.forEach((card, i) => {
        card.classList.remove('active', 'left', 'right');

        if (i === index) card.classList.add('active');
        else if (i === index - 1) card.classList.add('left');
        else if (i === index + 1) card.classList.add('right');
    });

    // Move track so center card is always centered
    const cardWidth = cards[0].offsetWidth + 20; 
    const offset = (track.offsetWidth / 2) - (cardWidth * (index + 0.5));
    track.style.transform = `translateX(${offset}px)`;
}

prevBtn.addEventListener('click', () => {
    index = (index - 1 + cards.length) % cards.length;
    updateCarousel();
});

nextBtn.addEventListener('click', () => {
    index = (index + 1) % cards.length;
    updateCarousel();
});

// Initial load
window.addEventListener('load', () => {
    updateCarousel();   // keep carousel logic
    setActiveNav();     // set initial active nav
});

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


</body>
</html>

