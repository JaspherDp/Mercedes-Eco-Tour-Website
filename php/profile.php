<?php
session_start();
require_once __DIR__ . '/db_connection.php';

// ---------- AUTH ----------
if (!isset($_SESSION['tourist_id'])) {
    echo "<script>alert('Please log in first.'); window.location.href = '../login.php';</script>";
    exit;
}
$tourist_id = (int) $_SESSION['tourist_id'];

// ---------- fetch fresh user ----------
$stmt = $pdo->prepare("SELECT * FROM tourist WHERE tourist_id = ? LIMIT 1");
$stmt->execute([$tourist_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$user) {
    echo "<script>alert('User not found.'); window.location.href = '../login.php';</script>";
    exit;
}

// ---------- helper: get profile image ----------
function getProfileImage($user) {
    if (!is_array($user)) return '../img/profileicon.png';
    $profile = trim((string)($user['profile_picture'] ?? ''));

    if (!empty($profile)) {
        if (preg_match('#^https?://#i', $profile)) {
            if (stripos($profile, 'profiles.google.com') !== false && preg_match('#profiles\\.google\\.com/(?:s2/photos/profile/)?([^/?#]+)(?:/picture)?#i', $profile, $m)) {
                $profile = 'https://profiles.google.com/' . rawurlencode($m[1]) . '/picture?sz=256';
            } elseif (stripos($profile, 'googleusercontent.com') !== false) {
                $profile = preg_replace('/([?&])sz=\\d+/i', '$1sz=256', $profile);
                $profile = preg_replace('/=s\\d+-c(?=$|[?&#])/i', '=s256-c', $profile);
                $profile = preg_replace('/=s\\d+(?=$|[?&#])/i', '=s256', $profile);
            }
        }
        if (preg_match('#^https?://#i', $profile)) return $profile;
        return '../' . ltrim($profile, '/');
    }

    $googleId = trim((string)($user['google_id'] ?? ''));
    if ($googleId !== '') {
        return 'https://profiles.google.com/' . rawurlencode($googleId) . '/picture?sz=256';
    }
    return '../img/profileicon.png';
}
$display_profile_pic = getProfileImage($user);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['booking_id'], $_POST['full_name'])) {
    header('Content-Type: application/json'); // tell browser it's JSON

    try {
        $booking_id = (int) $_POST['booking_id'];
        if ($booking_id <= 0) throw new Exception('Invalid booking ID.');

        $stmtCheck = $pdo->prepare("SELECT COUNT(*) FROM booking_tourists WHERE booking_id = ?");
        $stmtCheck->execute([$booking_id]);
        if ($stmtCheck->fetchColumn() > 0) throw new Exception('Tourists already submitted for this booking.');

        $pdo->beginTransaction();
        $stmt = $pdo->prepare("
            INSERT INTO booking_tourists
            (booking_id, full_name, gender, residence, phone_number)
            VALUES (?, ?, ?, ?, ?)
        ");

        foreach ($_POST['full_name'] as $i => $name) {
            if (empty(trim($name))) continue;
            $stmt->execute([
                $booking_id,
                trim($name),
                $_POST['gender'][$i] ?? null,
                $_POST['residence'][$i] ?? null,
                $_POST['phone_number'][$i] ?? null
            ]);
        }

        $pdo->commit();

        echo json_encode([
            'success' => true,
            'message' => 'Tourists submitted successfully.'
        ]);
        exit;

    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();

        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
        exit;
    }
}



// ---------- POST handlers ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // Logout
    if ($action === 'logout') {
        session_unset();
        session_destroy();
        echo "<script>alert('Logged out successfully'); location.href='../homepage.php';</script>";
        exit;
    }

    // Update profile
    if ($action === 'update_profile') {
        $address = trim($_POST['address'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $profile_picture = $user['profile_picture'] ?? null;

        if (!empty($_FILES['profile_picture']['name']) && $_FILES['profile_picture']['error'] === UPLOAD_ERR_OK) {
            $ext = pathinfo($_FILES['profile_picture']['name'], PATHINFO_EXTENSION);
            $safeExt = preg_replace('/[^a-z0-9]/i', '', $ext);
            $filename = 'profile_' . $tourist_id . '_' . time() . '.' . $safeExt;
            $targetDir = dirname(__DIR__) . '/uploads/profile_pictures/';
            if (!is_dir($targetDir)) mkdir($targetDir, 0777, true);
            $targetPath = $targetDir . $filename;
            if (move_uploaded_file($_FILES['profile_picture']['tmp_name'], $targetPath)) {
                $profile_picture = 'uploads/profile_pictures/' . $filename;
            }
        }

        $stmt = $pdo->prepare("UPDATE tourist SET address = ?, phone_number = ?, profile_picture = ?, updated_at = NOW() WHERE tourist_id = ?");
        $stmt->execute([$address, $phone, $profile_picture, $tourist_id]);

        echo "<script>alert('Profile updated successfully'); window.location.href='profile.php';</script>";
        exit;
    }

      // Change password
    if ($action === 'change_password') {
    $old = trim($_POST['old_password'] ?? '');
    $new = trim($_POST['new_password'] ?? '');

    if (empty($old) || empty($new)) {
        echo "<script>alert('Please fill both password fields.'); window.location.href='profile.php';</script>";
        exit;
    }

    $storedPassword = trim($user['password_hash'] ?? '');

    if (empty($storedPassword)) {
        echo "<script>alert('You do not have a local password set. Please use Google login or reset password first.'); window.location.href='profile.php';</script>";
        exit;
    }

    // Check hashed password
    if (!password_verify($old, $storedPassword)) {
        echo "<script>alert('Old password is incorrect.'); window.location.href='profile.php';</script>";
        exit;
    }

    // Hash new password and update
    $new_hash = password_hash($new, PASSWORD_DEFAULT);
    $stmt = $pdo->prepare("UPDATE tourist SET password_hash = ?, updated_at = NOW() WHERE tourist_id = ?");
    $stmt->execute([$new_hash, $tourist_id]);

    echo "<script>alert('Password changed successfully'); window.location.href='profile.php';</script>";
    exit;
}

}

// ---------- DATA FETCH ----------

// Active bookings (status pending, accepted, declined, uncomplete)
$bookings_active_stmt = $pdo->prepare("
    SELECT b.*, t.full_name AS t_full_name, t.email AS t_email, t.profile_picture AS t_profile_picture
    FROM bookings b
    LEFT JOIN tourist t ON b.tourist_id = t.tourist_id
    WHERE b.tourist_id = ?
      AND b.is_complete = 'uncomplete'
      AND b.status IN ('pending','accepted')
    ORDER BY b.created_at DESC
");
$bookings_active_stmt->execute([$tourist_id]);
$bookings_active = $bookings_active_stmt->fetchAll(PDO::FETCH_ASSOC);

// Booking history (status completed, cancelled, declined)
$bookings_history_stmt = $pdo->prepare("
    SELECT b.*, t.full_name AS t_full_name, t.email AS t_email, t.profile_picture AS t_profile_picture
    FROM bookings b
    LEFT JOIN tourist t ON b.tourist_id = t.tourist_id
    WHERE b.tourist_id = ?
      AND (b.is_complete != 'uncomplete' OR b.status IN ('declined','cancelled','completed'))
    ORDER BY b.created_at DESC
");
$bookings_history_stmt->execute([$tourist_id]);
$bookings_history = $bookings_history_stmt->fetchAll(PDO::FETCH_ASSOC);

// ---------- UPCOMING BOOKINGS ----------
$upcoming_stmt = $pdo->prepare("
    SELECT *
    FROM bookings
    WHERE tourist_id = ?
      AND is_complete = 'uncomplete'
      AND status = 'accepted'
    ORDER BY booking_date ASC
");
$upcoming_stmt->execute([$tourist_id]);
$upcoming_bookings = $upcoming_stmt->fetchAll(PDO::FETCH_ASSOC);

function hasTourists(PDO $pdo, int $booking_id): bool {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM booking_tourists WHERE booking_id = ?");
    $stmt->execute([$booking_id]);
    return $stmt->fetchColumn() > 0;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['fetch_tourists'])) {
    header('Content-Type: application/json');

    $booking_id = (int)$_POST['booking_id'];
    $stmt = $pdo->prepare("SELECT * FROM booking_tourists WHERE booking_id = ?");
    $stmt->execute([$booking_id]);

    echo json_encode([
        'success' => true,
        'tourists' => $stmt->fetchAll(PDO::FETCH_ASSOC)
    ]);
    exit;
}

?>

<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>iTour Mercedes - My Profile</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<link href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;500;700&display=swap" rel="stylesheet">
<link rel="icon" type="image/png" href="img/newlogo.png" />
<style>
:root{--primary:#2e7d66;--muted:#f1f7f5;--card:#fff;--text:#222;--border:#e9eef0}
*{box-sizing:border-box;font-family:Roboto,system-ui,-apple-system,Segoe UI,Arial}
body{margin:0;background: #fff;}
.container{display:flex;min-height:100vh}
.sidebar{
  width:270px;background:#2b7a66;border-right:1px solid #eef3f6;padding:20px 18px;display:flex;flex-direction:column;gap:12px;
  position:fixed;left:0;top:0;bottom:0;
}
.back{cursor:pointer;color: #fff;font-weight:700;display:flex;align-items:center;gap:8px}
.profile-area{text-align:center;padding:6px 0;border-bottom:1px solid #f1f5f9}
.profile-img{width:84px;height:84px;border-radius:50px;object-fit:cover;}
.profile-area h3{margin:8px 0 0 0;font-size:1.15rem; color: #fff;}
.profile-area p {
    margin: 6px 0 0;
    color: #fff;
    font-size: 0.8rem;
    max-width: 100%;        /* ensure it doesn’t exceed sidebar width */
    white-space: normal;    /* allow line breaks */
    word-break: break-word; /* break long words/emails if needed */
    overflow-wrap: anywhere;/* wrap text anywhere if too long */
}

.nav-links{margin-top:12px;display:flex;flex-direction:column;gap:6px}
.nav-links a{padding:10px 12px;border-radius:8px;text-decoration:none;color: #fff ;font-weight:700}
.nav-links a.active{background: rgba(255,255,255,0.15)}
.logout{margin-top:auto;padding-top:10px;border-top:1px solid #f1f5f9;text-align:}
.logout a{color:var(--text);text-decoration:none;font-weight:700}
.logout .btn {
  background: #0b67a3 !important;
}

/* main area */
.main{margin-left:250px;flex:1;padding:20px 28px}
.header{background:#fff;padding:12px;border-radius:8px;margin-bottom:12px;border:1px solid #eef3f6;box-shadow:0 2px 6px rgba(0,0,0,0.03)}
.header h2{color:var(--primary);margin:0}
.card{background:var(--card);border-radius:10px;padding:16px;border:1px solid var(--border);box-shadow:0 6px 20px rgba(0,0,0,0.04);margin-bottom:16px}
.profile-row{display:flex;gap:14px;align-items:center}
.profile-row img{width:120px;height:120px;border-radius:60px;object-fit:cover;}
.profile-info h1{margin:0;font-size:1.3rem;color:var(--primary)}
.small{color:#6b7280}

/* forms */
.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-top:10px}
.input{padding:9px;border-radius:8px;border:1px solid #e6eef3;width:100%}
.btn{padding:9px 12px;border-radius:8px;background:var(--primary);color:#fff;border:none;cursor:pointer;font-weight:700}

/* tables (admin-like) */
.table{width:100%;border-collapse:collapse;margin-top:12px}
thead th{background:var(--primary);color:#fff;padding:10px 12px;text-transform:uppercase;font-size:12px}
th,td{padding:12px;border-bottom:1px solid var(--border);text-align:left}
tbody tr:hover{background:linear-gradient(90deg, rgba(46,125,102,0.03), rgba(0,0,0,0.01))}
.profile-cell{display:flex;align-items:center;gap:10px}
.profile-icon{width:44px;height:44px;border-radius:50%;object-fit:cover;border:2px solid var(--primary)}
.pill{display:inline-block;padding:6px 8px;border-radius:999px;font-size:0.82rem;font-weight:700}
.pill.pending{background:#fff7e6;color:#a05b00}
.pill.accepted{background:#e9fbf4;color:#0b7a50}
.pill.declined{background:#fff1f1;color:#a42b2b}
.pill.finished{background:#ebf6ff;color:#0b67a3}
.diag{font-size:13px;color:#6b7280;margin-bottom:10px}
@media(max-width:900px){.sidebar{display:none}.main{margin-left:0;padding:12px}}

.pill.cancelled {
    background: #ffe5e5; /* light red */
    color: #a42b2b;      /* dark red text */
}

.pill.completed{background:#e9fbf4;color:#0b7a50}

.booking-details-modal-overlay-user {
    position: fixed;
    top:0; left:0;
    width:100%; height:100%;
    background: rgba(0,0,0,0.5);
    display:none; justify-content:center; align-items:center;
    z-index:1000;
}
.booking-details-modal-overlay-user.show { display:flex; }

.booking-details-modal-user {
    background:#fff; padding:20px 25px; border-radius:10px;
    width:450px; max-width:90%;
    box-shadow:0 5px 20px rgba(0,0,0,0.3);
}

.booking-details-modal-header-user {
    display:flex; justify-content:space-between; align-items:center;
    border-bottom:1px solid #e2e8f0; margin-bottom:15px;
}

.booking-details-modal-close-user {
    background:none; border:none; font-size:20px; cursor:pointer; color:#777;
}

.booking-details-modal-close-user:hover { color:#2e7d66; }

.booking-details-modal-body-user { font-size:14px; line-height:1.6; color:#333; }
.booking-details-modal-body-user p { margin-bottom:6px; }

.booking-details-modal-footer-user { margin-top:20px; display:flex; justify-content:flex-end; }
.booking-details-modal-btn-close-user {
    padding:6px 14px; border-radius:6px; border:none;
    cursor:pointer; font-weight:500;
    background:#2e7d66; color:#fff;
}
.booking-details-modal-btn-close-user:hover { background:#245447; }

.btn-details-user.booking-btn-user {
    padding:4px 8px; border-radius:6px; border:none; cursor:pointer;
    font-weight:500; font-size:13px;
    background-color:#2e7d66; color:#fff; transition:all 0.2s;
}
.btn-details-user.booking-btn-user:hover {
    background-color:#245447;
}



/* Modal overlay */
.tourist-modal-user {
  position: fixed;
  inset: 0; /* top, right, bottom, left = 0 */
  background: rgba(0,0,0,0.5);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 9999;
  padding: 20px; /* space around modal for small screens */
  overflow: auto; /* allow scrolling if modal is taller than viewport */
}

/* Modal content */
.tourist-modal-content-user {
  background: #fff;
  width: 95%;
  max-width: 800px;
  max-height: 90vh; /* limit modal height */
  overflow-y: auto; /* vertical scroll if content exceeds height */
  border-radius: 10px;
  padding: 20px;
  display: flex;
  flex-direction: column;
  box-sizing: border-box;
}

/* Optional: keep footer visible if needed */
.tourist-modal-footer-user {
  margin-top: 16px;
  text-align: right;
  flex-shrink: 0;
}


.tourist-fields-user {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 8px;
  margin-bottom: 8px;
}

.tourist-fields-user input,
.tourist-fields-user select {
  padding: 8px;
  border-radius: 6px;
  border: 1px solid #ddd;
}

.tourist-modal-content-user.extended {
  width: 95%;
  max-width: 800px;
}

.tourist-modal-header-user {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 14px;
}

.tourist-modal-close-user {
  background: none;
  border: none;
  font-size: 22px;
  cursor: pointer;
  color: #777;
}

.tourist-row-user {
  display: grid;
  grid-template-columns: 2fr 1fr 1.2fr 1.5fr auto;
  gap: 8px;
  align-items: center;
  margin-bottom: 8px;
}

.tourist-row-user input,
.tourist-row-user select {
  padding: 8px;
  border-radius: 6px;
  border: 1px solid #ddd;
}

.remove-tourist-user {
  background: #ffe5e5;
  color: #a42b2b;
  border: none;
  border-radius: 6px;
  padding: 6px 10px;
  cursor: pointer;
  font-weight: bold;
}

.remove-tourist-user:hover {
  background: #ffcccc;
}

.add-more-user {
  margin-top: 10px;
}

.tourist-modal-footer-user {
  margin-top: 16px;
  text-align: right;
}

.view-tourist-btn-user {
  background: #9ca3af !important; /* gray */
  cursor: pointer;
}
.view-tourist-btn-user:hover {
  background: #6b7280 !important;
}


</style>
</head>
<body>
<div class="container">
  <aside class="sidebar">
    <div class="back" onclick="history.back()">← Back</div>
    <div class="profile-area">
      <img src="<?= htmlspecialchars($display_profile_pic, ENT_QUOTES, 'UTF-8') ?>" alt="profile" class="profile-img" onerror="this.onerror=null;this.src='../img/profileicon.png';">
      <h3><?= htmlspecialchars($user['full_name']) ?></h3>
      <p><?= htmlspecialchars($user['email']) ?></p>
    </div>
    <nav class="nav-links">
      <a href="#" class="active" data-section="profile">My Profile</a>
      <a href="#" data-section="bookings">Bookings</a>
      <a href="#" data-section="history">History</a>
    </nav>
   <div class="logout" style="margin-top:auto;">
      <form method="POST" onsubmit="return confirm('Logout?');">
        <input type="hidden" name="action" value="logout">
        <button type="submit" style="
          border:none; 
          background:none; 
          cursor:pointer; 
          display:flex; 
          align-items:center; 
          gap:8px; 
          padding:10px 12px; 
          color:#fff; 
          font-weight:700; 
          font-size:1rem;
          justify-content:flex-end;
          width:100%;
        ">
          Logout
          <img src="../img/logouticon.png" alt="Logout" style="width:24px; height:24px;">
        </button>
      </form>
    </div>

  </aside>

  <main class="main">
    <div class="header"><h2>My Profile</h2></div>

<!-- PROFILE SECTION -->
<section id="profile" class="card section active">

  <!-- PROFILE ROW -->
  <div class="profile-row">
    <img src="<?= htmlspecialchars($display_profile_pic, ENT_QUOTES, 'UTF-8') ?>" alt="profile" onerror="this.onerror=null;this.src='../img/profileicon.png';">
    <div class="profile-info" style="flex:1">
      <h1><?= htmlspecialchars($user['full_name']) ?></h1>
      <div class="small"><?= htmlspecialchars($user['address'] ?? 'No address yet') ?></div>
      <div class="small"><?= htmlspecialchars($user['email']) ?> | <?= htmlspecialchars($user['phone_number'] ?? 'No phone number') ?></div>
      <button id="toggleEditBtn" class="btn" style="margin-top:12px">Edit Profile Details</button>
    </div>
  </div>

  <!-- EDIT PANEL -->
  <div id="editArea" style="display:none;margin-top:16px">
    <!-- PROFILE UPDATE -->
    <div class="card">
      <form method="POST" enctype="multipart/form-data">
        <input type="hidden" name="action" value="update_profile">
        <div class="form-grid">
          <div>
            <label><strong>Profile Picture</strong></label>
            <input type="file" name="profile_picture" accept="image/*" class="input">
          </div>
          <div>
            <label><strong>Phone Number</strong></label>
            <input type="text" name="phone" value="<?= htmlspecialchars($user['phone_number'] ?? '') ?>" class="input">
          </div>
          <div style="grid-column:1 / -1">
            <label><strong>Address</strong></label>
            <input type="text" name="address" value="<?= htmlspecialchars($user['address'] ?? '') ?>" class="input">
          </div>
        </div>
        <div style="margin-top:10px">
          <button class="btn" type="submit">Save Profile</button>
        </div>
      </form>
    </div>

    <!-- PASSWORD CHANGE -->
    <div class="card" style="margin-top:12px">
      <form method="POST">
        <input type="hidden" name="action" value="change_password">
        <div class="form-grid">
          <div>
            <label><strong>Old Password</strong></label>
            <input type="password" name="old_password" class="input" required>
          </div>
          <div>
            <label><strong>New Password</strong></label>
            <input type="password" name="new_password" class="input" required>
          </div>
        </div>
        <div style="margin-top:10px">
          <button class="btn" type="submit">Change Password</button>
        </div>
      </form>
    </div>
  </div>

</section>

<!-- UPCOMING BOOKINGS SECTION OUTSIDE PROFILE -->
<section id="upcoming-bookings" class="card section" style="margin-top:24px">
  <h3>Upcoming Bookings</h3>
  <?php if (count($upcoming_bookings) === 0): ?>
    <p>No upcoming bookings.</p>
  <?php else: ?>
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:12px;margin-top:10px">
      <?php foreach ($upcoming_bookings as $b):
        $adults = (int)($b['num_adults'] ?? 0);
        $children = (int)($b['num_children'] ?? 0);
        $pax = (int)($b['pax'] ?? ($adults + $children));
        $bookingDate = new DateTime($b['booking_date']);
        $today = new DateTime();
        $interval = $today->diff($bookingDate);
        $days_from_now = (int)$interval->format('%r%a');
      ?>
        <div class="card" style="height:270px;display:flex;flex-direction:column;justify-content:space-between;">
          <!-- Header -->
          <div style="background:var(--primary);color:#fff;padding:8px 12px;border-radius:8px 8px 0 0;font-weight:700;text-transform:capitalize;">
            <?= htmlspecialchars($b['booking_type'] ?? 'N/A') ?>
          </div>
          <!-- Body -->
          <div style="padding:12px;flex:1;display:flex;flex-direction:column;justify-content:space-between; padding: 20px">
            <div><strong>Date:</strong> <?= htmlspecialchars($b['booking_date'] ?? $b['created_at']) ?></div>
            <div>
              <strong><?= in_array(strtolower($b['booking_type'] ?? ''), ['boat','tourguide']) ? 'Location' : 'Package' ?>:</strong>
              <?= htmlspecialchars(
                  in_array(strtolower($b['booking_type'] ?? ''), ['boat','tourguide'])
                      ? ($b['location'] ?? 'N/A')
                      : ($b['package_name'] ?? 'N/A')
              ) ?>
            </div>
            <div style="margin-top:6px;display:flex;gap:6px;flex-wrap:wrap">
              <span style="background:#e2e8f0;padding:4px 8px;border-radius:999px;font-size:0.85rem;">Adults: <?= $adults ?></span>
              <span style="background:#e2e8f0;padding:4px 8px;border-radius:999px;font-size:0.85rem;">Children: <?= $children ?></span>
              <span style="background:#e2e8f0;padding:4px 8px;border-radius:999px;font-size:0.85rem;">Pax: <?= $pax ?></span>
            </div>
          </div>
          <!-- Footer -->
          <div style="background:#f1f7f5;padding:8px 12px;border-radius:0 0 8px 8px;font-size:0.85rem;color:#555;">
            <?php if ($days_from_now >= 0): ?>
              <?= $days_from_now ?> day<?= $days_from_now != 1 ? 's' : '' ?> from now
            <?php else: ?>
              <?= abs($days_from_now) ?> day<?= abs($days_from_now) != 1 ? 's' : '' ?> ago
            <?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</section>


<!-- BOOKINGS -->
<section id="bookings" class="card section" style="display:none">
  <h3>Active Bookings</h3>

  <?php
  // Separate accepted and pending
  $accepted = array_filter($bookings_active, fn($b) => strtolower($b['status']) === 'accepted');
  $pending  = array_filter($bookings_active, fn($b) => strtolower($b['status']) === 'pending');
  ?>

  <?php if (empty($accepted) && empty($pending)): ?>
    <p>No active bookings.</p>
  <?php else: ?>

    <?php if (!empty($accepted)): ?>
    <div class="card" style="margin-bottom:16px; padding:12px;">
      <h4 style="margin-top:0; color:var(--primary)">Accepted Bookings</h4>
      <table class="table">
        <thead>
          <tr>
            <th>Date</th>
            <th>Location/Package</th>
            <th>Type</th>
            <th>Adults</th>
            <th>Children</th>
            <th>Total Pax</th>
            <th>Other Details</th>
            <th>Tourists Submission</th>
            <th>Status</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($accepted as $b):
            $adults = (int)($b['num_adults'] ?? 0);
            $children = (int)($b['num_children'] ?? 0);
            $pax = (int)($b['pax'] ?? ($adults + $children));
          ?>
          <tr>
            <td><?= htmlspecialchars($b['booking_date'] ?? $b['created_at']) ?></td>
            <td>
              <?= htmlspecialchars(
                  in_array(strtolower($b['booking_type'] ?? ''), ['boat','tourguide'])
                      ? ($b['location'] ?? 'N/A')
                      : ($b['package_name'] ?? 'N/A')
              ) ?>
            </td>
            <td><?= htmlspecialchars($b['booking_type'] ?? 'N/A') ?></td>
            <td><?= $adults ?></td>
            <td><?= $children ?></td>
            <td><?= $pax ?></td>
            <td>
              <button class="btn-details-user booking-btn-user" 
                      data-booking='<?= htmlspecialchars(json_encode($b, JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES, "UTF-8") ?>'>
                View
              </button>
            </td>
            <?php if ($b['status'] === 'accepted' && $b['is_complete'] === 'uncomplete'): ?>
              <td>
                <?php if (hasTourists($pdo, $b['booking_id'])): ?>
                  <button
                    class="btn view-tourist-btn-user"
                    onclick="window.open('tourists_pdf.php?booking_id=<?= (int)$b['booking_id'] ?>','_blank')">
                    View Tourists
                  </button>
              <?php else: ?>
                <button
                  class="btn add-tourist-btn-user"
                  data-booking-id="<?= (int)$b['booking_id'] ?>">
                  Add Tourists
                </button>
              <?php endif; ?>
              </td>
              <?php else: ?>
              <td>-</td>
              <?php endif; ?>
            <td><span class="pill <?= htmlspecialchars($b['status']) ?>"><?= htmlspecialchars($b['status']) ?></span></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>

    <?php if (!empty($pending)): ?>
    <div class="card" style="padding:12px;">
      <h4 style="margin-top:0; color:#a05b00">Pending Bookings</h4>
      <table class="table">
        <thead>
          <tr>
            <th>Date</th>
            <th>Location/Package</th>
            <th>Type</th>
            <th>Adults</th>
            <th>Children</th>
            <th>Total Pax</th>
            <th>Other Details</th>
            <th>Status</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($pending as $b):
            $adults = (int)($b['num_adults'] ?? 0);
            $children = (int)($b['num_children'] ?? 0);
            $pax = (int)($b['pax'] ?? ($adults + $children));
          ?>
          <tr>
            <td><?= htmlspecialchars($b['booking_date'] ?? $b['created_at']) ?></td>
            <td>
              <?= htmlspecialchars(
                  in_array(strtolower($b['booking_type'] ?? ''), ['boat','tourguide'])
                      ? ($b['location'] ?? 'N/A')
                      : ($b['package_name'] ?? 'N/A')
              ) ?>
            </td>
            <td><?= htmlspecialchars($b['booking_type'] ?? 'N/A') ?></td>
            <td><?= $adults ?></td>
            <td><?= $children ?></td>
            <td><?= $pax ?></td>
            <td>
              <button class="btn-details-user booking-btn-user" 
                      data-booking='<?= htmlspecialchars(json_encode($b, JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES, "UTF-8") ?>'>
                View
              </button>
            </td>
            <td><span class="pill <?= htmlspecialchars($b['status']) ?>"><?= htmlspecialchars($b['status']) ?></span></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>

  <?php endif; ?>
</section>


    <!-- HISTORY -->
    <section id="history" class="card section" style="display:none">
      <h3>Booking History</h3>
      <?php if (count($bookings_history) === 0): ?>
        <p>No past bookings.</p>
      <?php else: ?>
      <table class="table">
        <thead>
          <tr>
            <th>Date</th>
            <th>Location/Package</th>
            <th>Type</th>
            <th>Adults</th>
            <th>Children</th>
            <th>Total Pax</th>
            <th>Other Details</th> <!-- NEW COLUMN -->
            <th>Status</th>
          </tr>
        </thead>
        <tbody>
<?php foreach ($bookings_history as $b):
    $adults = (int)($b['num_adults'] ?? 0);
    $children = (int)($b['num_children'] ?? 0);
    $pax = (int)($b['pax'] ?? ($adults + $children));
$status_class = strtolower(trim($b['is_complete'] ?? $b['status'] ?? 'uncomplete'));
$display_text = ucfirst($b['is_complete'] ?? $b['status'] ?? 'uncomplete');
?>
<tr>
    <td><?= htmlspecialchars($b['created_at']) ?></td>
    <td>
      <?= htmlspecialchars(
          in_array(strtolower($b['booking_type'] ?? ''), ['boat','tourguide'])
              ? ($b['location'] ?? 'N/A')
              : ($b['package_name'] ?? 'N/A')
      ) ?>
    </td>
    <td><?= htmlspecialchars($b['booking_type'] ?? 'N/A') ?></td>
    <td><?= $adults ?></td>
    <td><?= $children ?></td>
    <td><?= $pax ?></td>
    <td>
      <button class="btn-details-user booking-btn-user" 
              data-booking='<?= htmlspecialchars(json_encode($b, JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES, "UTF-8") ?>'>
        View
      </button>
    </td>
    <td>
<span class="pill <?= htmlspecialchars($status_class) ?>">
    <?= htmlspecialchars($display_text) ?>
</span>
        <?php if (!empty($b['dec_can_note'])): ?>
          <button class="note-btn" data-note="<?= htmlspecialchars($b['dec_can_note']) ?>" 
                  style="margin-left:6px;cursor:pointer;background:none;border:none;padding:0;">
            <img src="../img/noteicon.png" alt="Note" style="width:18px;height:18px;vertical-align:middle;">
          </button>
        <?php endif; ?>
    </td>
</tr>
<?php endforeach; ?>

        </tbody>
      </table>
      <?php endif; ?>
    </section>
  </main>
</div>
<div id="noteModal" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.5);align-items:center;justify-content:center;">
  <div style="background:#fff;padding:20px;border-radius:10px;max-width:400px;width:90%;position:relative;">
    <button id="closeModal" style="position:absolute;top:10px;right:10px;border:none;background:none;font-size:18px;cursor:pointer">&times;</button>
    <h4>Booking Note</h4>
    <p id="modalNote" style="margin-top:10px;"></p>
  </div>
</div>

<div id="touristModalUser" class="tourist-modal-user" style="display:none;">
  <div class="tourist-modal-content-user extended">

    <div class="tourist-modal-header-user">
      <h3>Add Tourists</h3>
      <button class="tourist-modal-close-user">&times;</button>
    </div>

    <form id="touristFormUser" method="POST">
      <input type="hidden" name="booking_id" id="touristBookingId">

      <div id="touristRowsUser">
        <!-- Tourist rows injected here -->
      </div>

      <button type="button" class="btn add-more-user" id="addMoreTouristUser">
        + Add Tourist
      </button>

      <div class="tourist-modal-footer-user">
        <button type="submit" class="btn">Submit Tourists</button>
      </div>
    </form>

  </div>
</div>

<!-- Booking Details Modal -->
<div id="bookingDetailsModalUser" class="booking-details-modal-overlay-user">
  <div class="booking-details-modal-user">
    <div class="booking-details-modal-header-user">
      <h3>Booking Details</h3>
      <button onclick="closeBookingDetailsModalUser()" class="booking-details-modal-close-user">×</button>
    </div>
    <div class="booking-details-modal-body-user" id="bookingDetailsContentUser">
      <!-- Booking details injected here -->
    </div>
    <div class="booking-details-modal-footer-user">
      <button onclick="closeBookingDetailsModalUser()" class="booking-details-modal-btn-close-user">Close</button>
    </div>
  </div>
</div>


<script>
document.querySelectorAll('.nav-links a').forEach(a=>{
  a.addEventListener('click', e=>{
    e.preventDefault();
    document.querySelectorAll('.nav-links a').forEach(x=>x.classList.remove('active'));
    a.classList.add('active');
    const sec = a.getAttribute('data-section');

    document.querySelectorAll('.section').forEach(s=>{
      s.style.display='none';
    });

    document.getElementById(sec).style.display = 'block';

    // If profile tab, also show upcoming bookings
    if(sec === 'profile'){
      document.getElementById('upcoming-bookings').style.display = 'block';
    } else {
      document.getElementById('upcoming-bookings').style.display = 'none';
    }

    window.scrollTo({top:0,behavior:'smooth'});
  });
});

document.getElementById('profile').style.display = 'block';

document.getElementById('toggleEditBtn').addEventListener('click', ()=>{
  const el = document.getElementById('editArea');
  el.style.display = (el.style.display==='block')?'none':'block';
});

document.querySelectorAll('.note-btn').forEach(btn=>{
  btn.addEventListener('click', ()=>{
    document.getElementById('modalNote').textContent = btn.dataset.note;
    document.getElementById('noteModal').style.display = 'flex';
  });
});
document.getElementById('closeModal').addEventListener('click', ()=>{
  document.getElementById('noteModal').style.display = 'none';
});

// Open modal with booking details
document.querySelectorAll('.btn-details-user.booking-btn-user').forEach(btn => {
    btn.addEventListener('click', function() {
        const booking = JSON.parse(this.getAttribute('data-booking'));
        openBookingDetailsModalUser(booking);
    });
});

function openBookingDetailsModalUser(booking) {
    const modal = document.getElementById('bookingDetailsModalUser');
    const content = document.getElementById('bookingDetailsContentUser');

    if (!modal || !content) return;

    content.innerHTML = `
        <p><strong>Tourist:</strong> ${booking.t_full_name}</p>
        <p><strong>Email:</strong> ${booking.t_email}</p>
        <p><strong>Phone:</strong> ${booking.phone_number || '-'}</p> <!-- USE bookings.phone_number -->
        <p><strong>Booking Type:</strong> ${booking.booking_type || '-'}</p>
        <p><strong>Package / Location:</strong> ${booking.package_name || booking.location || '-'}</p>
        <p><strong>Adults:</strong> ${booking.num_adults || 0}</p>
        <p><strong>Children:</strong> ${booking.num_children || 0}</p>
        <p><strong>Total Pax:</strong> ${booking.pax || (booking.num_adults + booking.num_children)}</p>
        <p><strong>Booking Date:</strong> ${booking.booking_date || booking.created_at}</p>
        <p><strong>Status:</strong> ${booking.status} / ${booking.is_complete || 'uncomplete'}</p>
    `;

    modal.classList.add('show');
}

// Close modal
function closeBookingDetailsModalUser() {
    const modal = document.getElementById('bookingDetailsModalUser');
    if (modal) modal.classList.remove('show');
}

const touristModalUser = document.getElementById('touristModalUser');
const touristRowsUser = document.getElementById('touristRowsUser');
const bookingIdInput = document.getElementById('touristBookingId');

/* ---------- HELPER: create ONE tourist row ---------- */
function createTouristRowUser(tourist = {}) {
  const row = document.createElement('div');
  row.className = 'tourist-row-user';
  row.innerHTML = `
    <input type="text" name="full_name[]" placeholder="Full Name" required value="${tourist.full_name || ''}">

    <select name="gender[]" required>
      <option value="">-- Gender (by birth) --</option>
      <option value="male" ${tourist.gender === 'male' ? 'selected' : ''}>Male</option>
      <option value="female" ${tourist.gender === 'female' ? 'selected' : ''}>Female</option>
    </select>

    <select name="residence[]" required>
      <option value="">-- Residence --</option>
      <option value="philippines" ${tourist.residence === 'philippines' ? 'selected' : ''}>Philippines</option>
      <option value="foreign" ${tourist.residence === 'foreign' ? 'selected' : ''}>Foreign</option>
    </select>

    <input type="text" name="phone_number[]" placeholder="Phone" value="${tourist.phone_number || ''}">

    <button type="button" class="remove-tourist-user">✕</button>
  `;
  return row;
}

/* ---------- OPEN MODAL ---------- */
document.querySelectorAll('.add-tourist-btn-user').forEach(btn => {
  btn.addEventListener('click', async () => {
    const bookingId = btn.dataset.bookingId;
    bookingIdInput.value = bookingId;

    // Fetch existing tourists for this booking
    const formData = new FormData();
    formData.append('fetch_tourists', '1');
    formData.append('booking_id', bookingId);

    try {
      const res = await fetch('<?= basename(__FILE__) ?>', { method: 'POST', body: formData });
      const data = await res.json();

      // Populate modal
      touristRowsUser.innerHTML = '';
      if (data.success && data.tourists.length > 0) {
        data.tourists.forEach(t => touristRowsUser.appendChild(createTouristRowUser(t)));
      } else {
        touristRowsUser.appendChild(createTouristRowUser());
      }

      touristModalUser.style.display = 'flex';
    } catch (err) {
      console.error(err);
      alert('Failed to load existing tourists.');
    }
  });
});

/* ---------- CLOSE MODAL ---------- */
document.querySelector('.tourist-modal-close-user').onclick = () => {
  touristModalUser.style.display = 'none';
};

/* ---------- ADD MORE TOURISTS ROW ---------- */
document.getElementById('addMoreTouristUser').addEventListener('click', () => {
  touristRowsUser.appendChild(createTouristRowUser());
});

/* ---------- REMOVE TOURIST ROW ---------- */
touristRowsUser.addEventListener('click', e => {
  if (e.target.classList.contains('remove-tourist-user')) {
    e.target.closest('.tourist-row-user').remove();
  }
});

/* ---------- SUBMIT TOURISTS ---------- */
document.getElementById('touristFormUser').addEventListener('submit', function(e) {
  e.preventDefault();

  // Save current active tab
  const activeTab = document.querySelector('.nav-links a.active')?.getAttribute('data-section');
  if (activeTab) localStorage.setItem('activeTab', activeTab);

  const formData = new FormData(this);
  const submitBtn = this.querySelector('button[type="submit"]');
  submitBtn.disabled = true;
  submitBtn.textContent = 'Submitting...';

  fetch('<?= basename(__FILE__) ?>', { method: 'POST', body: formData })
    .then(res => res.json())
    .then(resData => {
      if (resData.success) {
        alert(resData.message);
        location.reload(); // reloads, active tab will be restored
      } else {
        alert('Error: ' + resData.message);
      }
    })
    .catch(err => {
      console.error(err);
      alert('Submission failed.');
    })
    .finally(() => {
      submitBtn.disabled = false;
      submitBtn.textContent = 'Submit Tourists';
    });
});

/* ---------- RESTORE ACTIVE TAB AFTER RELOAD ---------- */
document.addEventListener('DOMContentLoaded', () => {
  const savedTab = localStorage.getItem('activeTab');
  if (savedTab) {
    // Remove active class from all tabs
    document.querySelectorAll('.nav-links a').forEach(a => a.classList.remove('active'));
    // Set saved tab active
    const tabLink = document.querySelector(`.nav-links a[data-section="${savedTab}"]`);
    if (tabLink) tabLink.classList.add('active');

    // Hide all sections
    document.querySelectorAll('.section').forEach(s => s.style.display = 'none');
    // Show saved section
    const section = document.getElementById(savedTab);
    if (section) section.style.display = 'block';

    // Remove saved tab
    localStorage.removeItem('activeTab');
  }
});

</script>
</body>
</html>

