<?php
chdir(__DIR__ . '/..');
session_start();
require 'php/db_connection.php';

// ✅ Include SweetAlert2 alert system
include 'php/alert.php';

// ✅ Session check
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: ../php/admin_login.php');
    exit();
}

// ✅ Prevent cached pages
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

// --- Handle POST actions with decision note ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'], $_POST['id'])) {
    try {
        $id = intval($_POST['id']);
        $action = $_POST['action'];
        $return_tab = $_POST['return_tab'] ?? 'pending';

        $status = null;
        $is_complete = null;
        $decCanNote = null;

        switch ($action) {
            case 'accept':
                $status = 'accepted';
                $is_complete = 'uncomplete';
                break;

            case 'decline':
                $status = 'declined';
                $is_complete = 'declined';
                // Get category and note from POST
                $category = $_POST['decision_category'] ?? null;
                $note = trim($_POST['decision_note'] ?? '');
                $decCanNote = $category ? $category . ($note ? " - $note" : "") : null;
                break;

            case 'finish':
                $status = 'accepted';
                $is_complete = 'completed';
                break;

            case 'cancel':
                $status = 'accepted';
                $is_complete = 'cancelled';
                // Get category and note from POST
                $category = $_POST['decision_category'] ?? null;
                $note = trim($_POST['decision_note'] ?? '');
                $decCanNote = $category ? $category . ($note ? " - $note" : "") : null;
                break;

            default:
                throw new Exception("Invalid action: $action");
        }

        $stmt = $pdo->prepare("UPDATE bookings SET status=:status, is_complete=:is_complete, dec_can_note=:dec_can_note, updated_at=NOW() WHERE booking_id=:id");
        $stmt->execute([
            ':status' => $status,
            ':is_complete' => $is_complete,
            ':dec_can_note' => $decCanNote,
            ':id' => $id
        ]);

        $messages = [
            'accept' => ['success', 'Booking Accepted!', 'Booking moved to Accepted tab.'],
            'decline' => ['error', 'Booking Declined!', 'Booking marked as declined.'],
            'finish' => ['success', 'Booking Completed!', 'Booking moved to Completed tab.'],
            'cancel' => ['info', 'Booking Cancelled!', 'Booking moved to Completed tab as cancelled.']
        ];

        [$type, $title, $message] = $messages[$action] ?? ['info','Updated','Booking updated.'];

        $_SESSION['alert'] = ['type'=>$type,'title'=>$title,'message'=>$message,'redirect'=>"adbookings.php?tab={$return_tab}"];

    } catch (Exception $e) {
        $_SESSION['alert'] = [
            'type' => 'error',
            'title' => 'Action Failed!',
            'message' => 'Error: ' . htmlspecialchars($e->getMessage()),
            'redirect' => "adbookings.php?tab={$return_tab}"
        ];
    }

    header("Location: adbookings.php");
    exit();
}


// ------------------------
// Filters & Search (GET)
// ------------------------
$activeTab = $_GET['tab'] ?? 'pending';
$validTabs = ['pending','accepted','completed'];
if (!in_array($activeTab, $validTabs)) {
    $activeTab = 'pending';
}

$rangeFilter  = strtolower(trim((string)($_GET['range'] ?? ($_GET['filter_mode'] ?? 'all'))));
$validRange   = ['all', 'yearly', 'monthly', 'daily'];
if (!in_array($rangeFilter, $validRange, true)) {
    $rangeFilter = 'all';
}
$selectedYear  = !empty($_GET['year'])  ? intval($_GET['year'])  : (int)date('Y');
$selectedMonth = !empty($_GET['month']) ? intval($_GET['month']) : (int)date('n');
$selectedDate  = !empty($_GET['date'])  ? trim((string)$_GET['date']) : date('Y-m-d');
$search_q     = trim($_GET['search'] ?? '');
$statusFilter = $_GET['status'] ?? ''; // for completed tab
$rowsRaw      = trim((string)($_GET['rows'] ?? '25'));
$rowsPerPage  = (int)$rowsRaw;
if ($rowsPerPage < 1) $rowsPerPage = 25;
if ($rowsPerPage > 300) $rowsPerPage = 300;

$isReport = isset($_GET['report_mode']) && $_GET['report_mode'] == '1';

$where = [];
$params = [];

// ----------------------
// TAB FILTER (skip in report mode)
// ----------------------
if (!$isReport) {
    switch ($activeTab) {
        case 'pending':
            $where[] = "b.status = 'pending'";
            break;
        case 'accepted':
            $where[] = "b.status = 'accepted'";
            $where[] = "b.is_complete = 'uncomplete'";
            break;
        case 'completed':
            $where[] = "b.is_complete IN ('completed','cancelled','declined')";
            break;
    }
}

// ----------------------
// STATUS FILTER (for completed tab)
// ----------------------
if ($activeTab === 'completed' && in_array($statusFilter, ['completed','declined','cancelled'])) {
    $where[] = "b.is_complete = :status";
    $params[':status'] = $statusFilter;
}

// ----------------------
// DATE FILTER
// ----------------------

if ($rangeFilter === 'yearly') {
    $where[] = "YEAR(b.created_at) = :selected_year";
    $params[':selected_year'] = $selectedYear;
} elseif ($rangeFilter === 'monthly') {
    $where[] = "YEAR(b.created_at) = :selected_year";
    $where[] = "MONTH(b.created_at) = :selected_month";
    $params[':selected_year'] = $selectedYear;
    $params[':selected_month'] = $selectedMonth;
} elseif ($rangeFilter === 'daily') {
    $where[] = "DATE(b.created_at) = :selected_date";
    $params[':selected_date'] = $selectedDate;
}

// Combine where conditions
$whereSql = '';
if (!empty($where)) {
    $whereSql = 'WHERE ' . implode(' AND ', $where);
}


// ----------------------
// SEARCH FILTER
// ----------------------
if (!empty($search_q)) {
    $where[] = "(
        t.full_name LIKE :search
        OR b.location LIKE :search
        OR b.booking_date LIKE :search
        OR b.booking_type LIKE :search
        OR b.package_name LIKE :search
    )";
    $params[':search'] = "%$search_q%";
}

// ----------------------
// SORT ORDER
// ----------------------
$sortBy = $_GET['sort_by'] ?? 'time';
$where = $where ?? []; // ensure $where exists

if ($activeTab === 'completed') {
    switch ($sortBy) {
        case 'completed':
        case 'declined':
        case 'cancelled':
            // Add WHERE filter for the selected status
            $where[] = "b.is_complete = :status";
            $params[':status'] = $sortBy;
            // Set order
            $orderClause = "FIELD(b.is_complete, 'completed','cancelled','declined'), b.booking_id DESC";
            break;
        case 'name':
            $orderClause = 't.full_name ASC';
            break;
        case 'time':
        default:
            $orderClause = "FIELD(b.is_complete, 'completed','cancelled','declined'), b.booking_id DESC";
            break;
    }
} else {
    // Other tabs
    switch ($sortBy) {
        case 'name':
            $orderClause = 't.full_name ASC';
            break;
        case 'time':
        default:
            $orderClause = 'b.created_at DESC, b.booking_id DESC';
            break;
    }
}

// ----------------------
// COMPOSE SQL
// ----------------------
$sql = "SELECT 
            b.*,
            b.phone_number AS booking_phone,
            b.location AS booking_location,
            b.booking_type AS booking_type,
            b.package_name AS package_name,
            b.num_adults,
            b.num_children,
            t.tourist_id AS t_id, 
            t.full_name AS t_full_name, 
            t.email AS t_email, 
            t.profile_picture AS t_profile_picture, 
            t.google_id AS t_google_id,
            t.phone_number AS t_phone, 
            t.address AS t_address,
            COALESCE(MONTHNAME(b.created_at), '') AS created_month,
            COALESCE(YEAR(b.created_at), '') AS created_year
        FROM bookings b
        LEFT JOIN tourist t ON b.tourist_id = t.tourist_id";

if (!empty($where)) {
    $sql .= " WHERE " . implode(" AND ", $where);
}

$sql .= " ORDER BY $orderClause";
$sql .= " LIMIT " . (int)$rowsPerPage;

$stmt = $pdo->prepare($sql);
foreach ($params as $key => $val) {
    $stmt->bindValue($key, $val);
}
$stmt->execute();
$bookings = $stmt->fetchAll(PDO::FETCH_ASSOC);



// ------------------------
// Get distinct years
// ------------------------
$years = [];
try {
    $ystmt = $pdo->query("SELECT DISTINCT YEAR(created_at) AS y FROM bookings WHERE created_at IS NOT NULL ORDER BY y DESC");
    $years = $ystmt->fetchAll(PDO::FETCH_COLUMN, 0);
} catch(Exception $e){ /*ignore*/ }
if (empty($years)) {
    $years = [(int)date('Y')];
}
if (!in_array((int)$selectedYear, array_map('intval', $years), true)) {
    array_unshift($years, (int)$selectedYear);
}

// ------------------------
// Function to get finished bookings
// ------------------------
function getFinishedCounts(PDO $pdo, $touristId){
    if(!$touristId) return ['bookings'=>0];

    // Bookings count
    $stmt1 = $pdo->prepare("SELECT COUNT(*) FROM bookings WHERE tourist_id = :touristId AND is_complete='completed'");
    $stmt1->execute(['touristId'=>$touristId]);
    $bookings = (int)$stmt1->fetchColumn();

    return ['bookings'=>$bookings];
}

// ------------------------
// Helper: get profile image
// ------------------------
function getProfileImg($profile_picture) {
    $default = 'img/profileicon.png';
    if (empty($profile_picture)) return $default;
    if (preg_match('#^https?://#i', $profile_picture)) {
        if (stripos($profile_picture, 'profiles.google.com') !== false && preg_match('#profiles\\.google\\.com/(?:s2/photos/profile/)?([^/?#]+)(?:/picture)?#i', $profile_picture, $m)) {
            return 'https://profiles.google.com/' . rawurlencode($m[1]) . '/picture?sz=256';
        }
        if (stripos($profile_picture, 'googleusercontent.com') !== false) {
            $profile_picture = preg_replace('/([?&])sz=\\d+/i', '$1sz=256', $profile_picture);
            $profile_picture = preg_replace('/=s\\d+-c(?=$|[?&#])/i', '=s256-c', $profile_picture);
            $profile_picture = preg_replace('/=s\\d+(?=$|[?&#])/i', '=s256', $profile_picture);
        }
        return $profile_picture;
    }

    $paths = [
        'uploads/profile_pictures/' . basename($profile_picture),
        'uploads/profile_picture/' . basename($profile_picture),
        ltrim($profile_picture,'/'),
    ];

    foreach($paths as $p){
        if(file_exists(getcwd().'/'.$p)) return $p;
    }

    return $default;
}

function getGoogleAvatarById($googleId) {
    $id = trim((string)$googleId);
    if ($id === '') return '';
    return 'https://profiles.google.com/' . rawurlencode($id) . '/picture?sz=256';
}

function getPaymentStatusFromMeta($preferredResource) {
    $raw = strtolower((string)$preferredResource);
    if ($raw === '') {
        return ['label' => 'Unpaid', 'class' => 'unpaid'];
    }

    if (preg_match('/payment status\s*:\s*([^|]+)/i', (string)$preferredResource, $m)) {
        $explicit = strtolower(trim((string)$m[1]));
        if (strpos($explicit, 'partial') !== false) {
            return ['label' => 'Partial', 'class' => 'partial'];
        }
        if (strpos($explicit, 'paid') !== false || strpos($explicit, 'full') !== false) {
            return ['label' => 'Paid', 'class' => 'paid'];
        }
    }

    if (preg_match('/payment option\s*:\s*([^|]+)/i', (string)$preferredResource, $m) || preg_match('/payment\s*:\s*([^|]+)/i', (string)$preferredResource, $m)) {
        $option = strtolower(trim((string)$m[1]));
        if (strpos($option, 'partial') !== false || strpos($option, '20%') !== false) {
            return ['label' => 'Partial', 'class' => 'partial'];
        }
        if (strpos($option, 'full') !== false) {
            return ['label' => 'Paid', 'class' => 'paid'];
        }
    }

    return ['label' => 'Unpaid', 'class' => 'unpaid'];
}
// --- Handle AJAX fetch bookings ---
if(isset($_GET['action']) && $_GET['action'] === 'fetchBookings') {
    header('Content-Type: application/json');

    try {

        $stmt = $pdo->query("
            SELECT 
                b.booking_id,
                t.full_name AS name,
                t.email,
                COALESCE(b.phone_number, t.phone_number) AS phone,
                b.package_name,
                b.location,
                b.booking_type,
                b.status,
                b.booking_date AS `date`,
                b.created_at,   -- ADD THIS LINE
                b.booking_date,
                CASE 
                    WHEN b.booking_type = 'package' THEN b.package_name
                    WHEN b.booking_type IN ('boat', 'tourguide') THEN b.location
                    ELSE ''
                END AS display_name
            FROM bookings b
            LEFT JOIN tourist t ON b.tourist_id = t.tourist_id
            ORDER BY b.booking_id DESC

        ");

        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode($results);

    } catch(Exception $e){
        echo json_encode(['error' => $e->getMessage()]);
    }

    exit();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>iTour Mercedes - Admin: Bookings</title>
<link rel="icon" type="image/png" href="img/newlogo.png">
<link href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;500;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="styles/admin_panel_theme.css" />
<link rel="stylesheet" href="styles/adbookings.css" />
</head>
<body>
<div class="admin-container">
<?php include 'admin_sidebar.php'; ?>
<main class="main-content">
<header class="admin-header">
  <div class="admin-header-left">
    <h2>Booking Management</h2>
    <p class="admin-header-subtitle">Welcome, <?= $admin_username ?? 'Website Admin' ?></p>
  </div>
  <div class="admin-header-right">
    <form id="overviewFilterForm" method="GET" class="ad-global-filter-form">
      <input type="hidden" name="tab" value="<?= htmlspecialchars($activeTab) ?>">
      <input type="hidden" name="sort_by" value="<?= htmlspecialchars($sortBy) ?>">
      <input type="hidden" name="rows" value="<?= (int)$rowsPerPage ?>">
      <input type="hidden" name="status" value="<?= htmlspecialchars($statusFilter) ?>">
      <input type="hidden" name="search" value="<?= htmlspecialchars($search_q) ?>">
      <label for="adRangeFilter" class="ad-global-filter-label">Overview Filter</label>
      <select id="adRangeFilter" name="range" class="ad-global-filter-select">
        <option value="all" <?= $rangeFilter === 'all' ? 'selected' : '' ?>>All</option>
        <option value="yearly" <?= $rangeFilter === 'yearly' ? 'selected' : '' ?>>Yearly</option>
        <option value="monthly" <?= $rangeFilter === 'monthly' ? 'selected' : '' ?>>Monthly</option>
        <option value="daily" <?= $rangeFilter === 'daily' ? 'selected' : '' ?>>Daily</option>
      </select>
      <select id="adRangeYear" name="year" class="ad-global-filter-select">
        <?php foreach($years as $y): ?>
          <option value="<?= (int)$y ?>" <?= (int)$selectedYear === (int)$y ? 'selected' : '' ?>><?= (int)$y ?></option>
        <?php endforeach; ?>
      </select>
      <select id="adRangeMonth" name="month" class="ad-global-filter-select">
        <?php for ($m = 1; $m <= 12; $m++): ?>
          <option value="<?= $m ?>" <?= (int)$selectedMonth === $m ? 'selected' : '' ?>><?= date('F', mktime(0,0,0,$m,1)) ?></option>
        <?php endfor; ?>
      </select>
      <input id="adRangeDate" type="date" name="date" class="ad-global-filter-select ad-global-filter-date" value="<?= htmlspecialchars($selectedDate) ?>">
      <button type="submit" class="ad-global-filter-apply">Apply</button>
    </form>
    <button class="btn-generate-report" id="openReportModal">
      <img src="https://img.icons8.com/ios-filled/24/ffffff/document.png" alt="icon">
      Generate Report
    </button>
  </div>
</header>

<!-- Report Modal -->
<div class="af-modal-overlay" id="reportModal" report-mode="1">
  <div class="af-modal" style= "padding:24px; border-radius:12px;">

    <!-- Header -->
    <div style="display:flex; justify-content:center; position:relative; padding-bottom:12px; border-bottom:1px solid #eee;">
      <strong style="font-size:1.2rem; color:#2b7a66;">Booking Report Preview</strong>
      <button id="closeReportModal" style="position:absolute; top:8px; right:8px; border:none; background:none; font-size:1.2rem; cursor:pointer; color:#666;">×</button>
    </div>

    <!-- Filter Panel -->
    <div class="report-filter-panel" style="display:flex; flex-wrap:wrap; gap:12px; margin-top:16px; align-items:center;">
      <!-- Hidden input for report mode -->
      <input type="hidden" id="reportMode" value="1">

      <select id="reportFilterMode" style="padding:8px 10px; border-radius:8px; border:1px solid #ccc; font-family:'Poppins', sans-serif;">
        <option value="all">All</option>
        <option value="yearly">Yearly</option>
        <option value="monthly">Monthly</option>
      </select>

      <select id="reportFilterYear" style="padding:8px 10px; border-radius:8px; border:1px solid #ccc; font-family:'Poppins', sans-serif;">
        <option value="">Select Year</option>
        <?php foreach($years as $y): ?>
        <option value="<?= $y ?>"><?= $y ?></option>
        <?php endforeach; ?>
      </select>

      <select id="reportFilterMonth" style="padding:8px 10px; border-radius:8px; border:1px solid #ccc; font-family:'Poppins', sans-serif;">
        <option value="">Select Month</option>
        <?php foreach(range(1,12) as $m): ?>
        <option value="<?= $m ?>"><?= date("F", mktime(0,0,0,$m,1)) ?></option>
        <?php endforeach; ?>
      </select>

      <select id="reportPaperSize" style="padding:8px 10px; border-radius:8px; border:1px solid #ccc; font-family:'Poppins', sans-serif;">
        <option value="letter">Letter</option>
        <option value="A4" selected>A4</option>
        <option value="long">Legal</option>
      </select>

        <select id="reportOrientation" style="padding:8px 10px; border-radius:8px; border:1px solid #ccc; font-family:'Poppins', sans-serif;">
            <option value="portrait" selected>Portrait</option>
            <option value="landscape">Landscape</option>
        </select>


      <button class="af-btn" id="applyReportFilter" style="margin-left:auto;">Apply Filter</button>
    </div>

    <!-- Report Preview -->
    <div class="report-preview" id="reportPreview" style="margin-top:20px; background:#f9fafb; padding:16px; border-radius:10px; box-shadow:0 4px 12px rgba(0,0,0,0.05);">
      <h4 style="color:#2b7a66; margin-top:0;">Booking Report</h4>
      <p style="color:#555;">Select filters and click "Apply Filter" to preview the report</p>
      <div id="reportContent" style="margin-top:12px;"></div>
    </div>

    <!-- Footer -->
    <div class="af-actions" style="margin-top:20px; display:flex; justify-content:flex-end; gap:10px; align-items:center;">
      <button class="af-btn" id="downloadReport">Download PDF</button>
      <button class="af-btn" id="printReport">Print Preview</button>
      <button class="af-btn secondary" id="closeReportModalFooter">Close</button>
    </div>

  </div>
</div>


<div class="card bookings-card">
<div class="bookings-head">
  <h3>All Bookings</h3>
  <div class="tabs">
    <button class="tab-btn <?= $activeTab==='pending'?'active':'' ?>" data-tab="pending">Pending</button>
    <button class="tab-btn <?= $activeTab==='accepted'?'active':'' ?>" data-tab="accepted">Accepted</button>
    <button class="tab-btn <?= $activeTab==='completed'?'active':'' ?>" data-tab="completed">Completed</button>
  </div>
</div>

<form id="searchForm" class="booking-toolbar" method="GET">
  <input type="hidden" name="tab" value="<?= htmlspecialchars($activeTab) ?>">
  <input type="hidden" name="range" value="<?= htmlspecialchars($rangeFilter) ?>">
  <input type="hidden" name="year" value="<?= (int)$selectedYear ?>">
  <input type="hidden" name="month" value="<?= (int)$selectedMonth ?>">
  <input type="hidden" name="date" value="<?= htmlspecialchars($selectedDate) ?>">
  <span class="rows-label">Rows</span>
  <input type="number" id="rows" name="rows" class="input rows-input" min="1" max="300" step="1" value="<?= (int)$rowsPerPage ?>">
  <select name="status" class="select status-select">
    <option value="" <?= $statusFilter===''?'selected':'' ?>>All Statuses</option>
    <option value="completed" <?= $statusFilter==='completed'?'selected':'' ?>>Completed</option>
    <option value="declined" <?= $statusFilter==='declined'?'selected':'' ?>>Declined</option>
    <option value="cancelled" <?= $statusFilter==='cancelled'?'selected':'' ?>>Cancelled</option>
  </select>
  <select id="sort_by" name="sort_by" class="select sort-select">
    <?php
      $sortBy = $_GET['sort_by'] ?? 'time';
      if ($activeTab === 'completed') {
          $options = [
              'time' => 'Sort: Latest (Default)',
              'name' => 'Sort: Name (A-Z)',
              'completed' => 'Sort: Completed',
              'declined' => 'Sort: Declined',
              'cancelled' => 'Sort: Cancelled'
          ];
      } else {
          $options = [
              'time' => 'Sort: Latest (Default)',
              'name' => 'Sort: Name (A-Z)'
          ];
      }
      foreach ($options as $value => $label) {
          $selected = ($sortBy === $value) ? 'selected' : '';
          echo "<option value=\"$value\" $selected>$label</option>";
      }
    ?>
  </select>
  <button type="submit" class="btn-primary toolbar-apply-btn">Apply Filter</button>
  <input type="text" name="search" class="search-input" placeholder="Search by guest name or booking ID" value="<?= htmlspecialchars($search_q) ?>">
</form>

<?php
// ------------------------
// Render Bookings
// ------------------------
function renderBookings($bookings, $filter_status){
    // Filter bookings first
    $filteredBookings = array_filter($bookings, function($b) use ($filter_status) {
        $status = $b['status'] ?? 'pending';
        $is_complete = $b['is_complete'] ?? 'uncomplete';

        if($filter_status==='pending' && $status!=='pending') return false;
        if($filter_status==='accepted' && !($status==='accepted' && $is_complete==='uncomplete')) return false;
        if($filter_status==='completed' && !in_array($is_complete,['completed','cancelled','declined'])) return false;
        return true;
    });

    if(count($filteredBookings) === 0){
        echo "<tr><td colspan='8' style='text-align:center; color:#777;'>No bookings found.</td></tr>";
        return;
    }

    // Render rows
    foreach($filteredBookings as $b){
        $status = $b['status'] ?? 'pending';
        $is_complete = $b['is_complete'] ?? 'uncomplete';
        $t_name = $b['t_full_name'] ?? 'Guest';
        $t_email = $b['t_email'] ?? '-';
        $t_phone = !empty($b['booking_phone']) ? $b['booking_phone'] : (!empty($b['t_phone']) ? $b['t_phone'] : '-');
        $t_address = $b['t_address'] ?? '-';
        $finishedCounts = getFinishedCounts($GLOBALS['pdo'], $b['t_id'] ?? 0);

        $resolvedPic = getProfileImg($b['t_profile_picture'] ?? '');
        if ($resolvedPic === 'img/profileicon.png') {
            $googlePic = getGoogleAvatarById($b['t_google_id'] ?? '');
            if ($googlePic !== '') {
                $resolvedPic = $googlePic;
            }
        }
        $picEsc = htmlspecialchars($resolvedPic);
        $adults = (int)($b['num_adults'] ?? 0);
        $children = (int)($b['num_children'] ?? 0);
        $pax = (int)($b['pax'] ?? ($adults + $children));

        $created_month = !empty($b['created_month']) ? htmlspecialchars($b['created_month']) : '';
        $created_year = !empty($b['created_year']) ? htmlspecialchars($b['created_year']) : '';

        $bookingTypeRaw = strtolower(trim((string)($b['booking_type'] ?? '')));
        $booking_type = htmlspecialchars(ucfirst($bookingTypeRaw !== '' ? $bookingTypeRaw : 'n/a'));
        if (in_array($bookingTypeRaw, ['boat', 'tourguide'], true)) {
            $location = htmlspecialchars($b['location'] ?? '-');
        } elseif ($bookingTypeRaw === 'package') {
            $location = htmlspecialchars($b['package_name'] ?? '-');
        } else {
            $location = '-';
        }

        // Status pill logic
        $pill_classes = [
            'completed' => 'finished',
            'cancelled' => 'cancel',
            'declined'  => 'declined',
            'uncomplete'=> 'pending'
        ];

        $pill_labels = [
            'completed' => 'Completed',
            'cancelled' => 'Cancelled',
            'declined'  => 'Declined',
            'uncomplete'=> 'Pending'
        ];

        if ($filter_status === 'completed') {
            $pill_class = $pill_classes[$is_complete] ?? 'pending';
            $pill_label = $pill_labels[$is_complete] ?? ucfirst($is_complete);
        } else {
            $pill_class = $filter_status;
            $pill_label = ucfirst($filter_status);
        }

        $state_pill = "<span class='pill {$pill_class}'>".$pill_label."</span>";
        $bookingJson = htmlspecialchars(json_encode($b, JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES, "UTF-8");
        $paymentStatus = getPaymentStatusFromMeta($b['preferred_resource'] ?? '');
        $paymentPill = "<span class='pill payment {$paymentStatus['class']}'>" . htmlspecialchars($paymentStatus['label']) . "</span>";
        $viewDetailsBtn = "<button type='button' class='btn-details action-btn view' data-booking='{$bookingJson}'>View Details</button>";

        // Only show Tourist Submission button on Accepted tab
        $touristSubmissionTd = '';
        if($filter_status === 'accepted'){
            $bookingId = $b['booking_id'];
            $stmtTourist = $GLOBALS['pdo']->prepare("SELECT COUNT(*) FROM booking_tourists WHERE booking_id = :bookingId");
            $stmtTourist->execute(['bookingId' => $bookingId]);
            $touristCount = (int)$stmtTourist->fetchColumn();

            $touristBtn = $touristCount > 0 
                ? "<button class='btn-tourists btn-primary' data-booking='$bookingId'>View Tourists</button>" 
                : "<button class='btn-tourists-nosub btn-disabled' disabled>No Submission Yet</button>";

            $touristSubmissionTd = "<td style='text-align:center;'>{$touristBtn}</td>";
        }


        echo "<tr>
    <td class='booker-cell' style='width:32%;'>
        <div class='booker-cell-wrap'>
            <img src='{$picEsc}' class='profile-img hover-profile' alt='profile'
                onerror=\"this.onerror=null;this.src='../img/profileicon.png';\"
                data-fullname='".htmlspecialchars($t_name)."' 
                data-email='".htmlspecialchars($t_email)."' 
                data-phone='".htmlspecialchars($t_phone)."' 
                data-address='".htmlspecialchars($t_address)."' 
                data-finished-bookings='".htmlspecialchars($finishedCounts['bookings'])."'
            >
            <div class='booker-details'>
                <div class='booker-name-row'>
                    <div class='profile-name'>{$t_name}</div>
                    ".(!empty($created_month) ? "<div class='pill-month'>{$created_month} {$created_year}</div>" : "")."
                </div>
                <div class='profile-email'>{$t_email}</div>
                <div class='profile-phone'>{$t_phone}</div>
            </div>
        </div>
    </td>
    <td>".htmlspecialchars($b['booking_id'])."</td>
    <td>{$booking_type}</td>";

// Only show Pax for tabs other than 'accepted'
if ($filter_status !== 'accepted') {
    echo "<td>Adults: {$adults}<br>Children: {$children}<br>Total: {$pax}</td>";
}

echo "<td>".htmlspecialchars($b['booking_date'])."</td>
    <td>{$paymentPill}</td>
    <td>{$state_pill}</td>
    {$touristSubmissionTd}
    <td style='width:auto;'>
      <div class='row-actions'>
        <button type='button' class='action-toggle-btn'>Actions</button>
        <div class='action-menu'>
          {$viewDetailsBtn}";

// ------------------------
// Action buttons
// ------------------------
if ($filter_status === 'pending') {

    // ACCEPT stays the same
    echo "
    <form method='POST' class='inline-form' 
        onsubmit='return confirmAction(event, this, \"accept\");'>
        <input type='hidden' name='id' value='".htmlspecialchars($b['booking_id'])."'>
        <input type='hidden' name='action' value='accept'>
        <input type='hidden' name='return_tab' value='pending'>
        <button class='action-btn accept' type='submit'>Accept</button>
    </form>

    <!-- DECLINE now opens decision reason modal -->
    <form method='POST' class='inline-form'
        onsubmit='return openDecisionModal(event, this, \"decline\", \"completed\");'>
        <input type='hidden' name='id' value='".htmlspecialchars($b['booking_id'])."'>
        <input type='hidden' name='action' value='decline'>
        <input type='hidden' name='return_tab' value='completed'>
        <button class='action-btn decline' type='submit'>Decline</button>
    </form>
    ";

} elseif ($filter_status === 'accepted') {

    // MARK COMPLETED stays the same
    echo "
    <form method='POST' class='inline-form' 
        onsubmit='return confirmAction(event, this, \"complete\");'>
        <input type='hidden' name='id' value='".htmlspecialchars($b['booking_id'])."'>
        <input type='hidden' name='action' value='finish'>
        <input type='hidden' name='return_tab' value='completed'>
        <button class='action-btn finish' type='submit'>Mark Completed</button>
    </form>

    <!-- CANCEL now uses decision reason modal -->
    <form method='POST' class='inline-form'
        onsubmit='return openDecisionModal(event, this, \"cancel\", \"completed\");'>
        <input type='hidden' name='id' value='".htmlspecialchars($b['booking_id'])."'>
        <input type='hidden' name='action' value='cancel'>
        <input type='hidden' name='return_tab' value='completed'>
        <button class='action-btn cancel' type='submit'>Cancel</button>
    </form>
    ";
}

echo "    </div>
      </div>
    </td></tr>";

    }
}
?>


<?php 
// Base columns
$columnsBase = "<th>Tourist</th><th>ID</th><th>Type</th><th>Pax</th><th>Date</th><th>Payment Status</th><th>Status</th>";

// Pending tab: no Tourist Submission
$columnsPending = $columnsBase . "<th>Actions</th>";

// Accepted tab: remove Pax and add Tourist Submission before Actions
$columnsAccepted = "<th>Tourist</th><th>ID</th><th>Type</th><th>Date</th><th>Payment Status</th><th>Status</th><th>Tourist Submission</th><th>Actions</th>";

// Completed tab: include Actions for View Details
$columnsCompleted = $columnsBase . "<th>Actions</th>";
?>

<div id="pending" class="tab-page <?= $activeTab==='pending'?'active':'' ?>">
    <table>
        <thead>
            <tr><?= $columnsPending ?></tr>
        </thead>
        <tbody>
            <?php renderBookings($bookings,'pending'); ?>
        </tbody>
    </table>
</div>

<div id="accepted" class="tab-page <?= $activeTab==='accepted'?'active':'' ?>">
    <table>
        <thead>
            <tr><?= $columnsAccepted ?></tr>
        </thead>
        <tbody>
            <?php renderBookings($bookings,'accepted'); ?>
        </tbody>
    </table>
</div>

<div id="completed" class="tab-page <?= $activeTab==='completed'?'active':'' ?>">
    <table>
        <thead>
            <tr><?= $columnsCompleted ?></tr>
        </thead>
        <tbody>
            <?php renderBookings($bookings, 'completed'); ?>
        </tbody>
    </table>
</div>


<!-- DECISION REASON MODAL -->
<div id="decisionModal" class="decision-modal-overlay">
  <div class="decision-modal">

    <!-- Header -->
    <!-- Example modal header -->
    <div class="decision-modal-header">
      <h3 id="decisionModalTitle">Reason Required</h3>
      <button id="decisionModalClose" class="decision-modal-close">×</button>
    </div>


    <!-- Body -->
    <div class="decision-modal-body">

      <label class="decision-label">Select Category</label>
      <select id="decisionCategory" class="decision-input">
        <option value="" disabled selected>Select a reason</option>
        <option value="Incorrect Information">Incorrect Information</option>
        <option value="Invalid Booking Details">Invalid Booking Details</option>
        <option value="Duplicate Booking">Duplicate Booking</option>
        <option value="Fraudulent activity">Fraudulent activity</option>
        <option value="Unavailability">Unavailability</option>
        <option value="Tourist-related issues">Tourist-related issues</option>
        <option value="Platform policy violations">Platform policy violations</option>
        <option value="Others">Others</option>
      </select>

      <label class="decision-label">Additional Note (optional)</label>
      <textarea id="decisionNote" class="decision-textarea" placeholder="Add note here..."></textarea>

    </div>

    <!-- Footer -->
    <div class="decision-modal-footer">
      <button class="decision-btn cancel" id="decisionCancelBtn">Cancel</button>
      <button class="decision-btn ok" id="decisionOkBtn">OK</button>
    </div>

  </div>
</div>

<div id="bookingDetailsModal" class="booking-details-modal-overlay">
  <div class="booking-details-modal">
    <div class="booking-details-modal-header">
      <h3>Booking Details</h3>
      <button onclick="closeBookingDetailsModal()" class="booking-details-modal-close">×</button>
    </div>
    <div class="booking-details-modal-body" id="bookingDetailsContent">
      <!-- Details injected here -->
    </div>
    <div class="booking-details-modal-footer">
      <button onclick="closeBookingDetailsModal()" class="booking-details-modal-btn-close">Close</button>
    </div>
  </div>
</div>

<!-- Tourist Modal -->
<div id="touristModal" class="tourist-modal">
  <div class="tourist-modal-wrapper">
    <div class="tourist-modal-content">
      <div class="tourist-modal-header">
        <h2>Tourist List</h2>
        <button class="tourist-modal-close" onclick="closeTouristModal()">&times;</button>
      </div>
      <div class="tourist-modal-body">
        <iframe id="touristPdfFrame" src="" frameborder="0"></iframe>
      </div>
    </div>
  </div>
</div>



</main>
</div>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
<script src="https://html2canvas.hertzen.com/dist/html2canvas.min.js"></script>
<script>
// ---------------------------------------------------------------------
// TABS — preserves filters + search
// ---------------------------------------------------------------------
document.querySelectorAll('.tab-btn').forEach(btn => {
    btn.addEventListener('click', () => {
        const url = new URL(window.location);
        url.searchParams.set('tab', btn.dataset.tab);
        window.location = url.toString();
    });
});


// ===========================================================
// ----------------- GLOBAL VARIABLES -----------------------
// ===========================================================
const reportModal = document.getElementById('reportModal');
const openReportBtn = document.getElementById('openReportModal');
const closeReportBtns = [
  document.getElementById('closeReportModal'),
  document.getElementById('closeReportModalFooter')
];

// ===========================================================
// ----------------- MODAL HANDLING -------------------------
// ===========================================================
function openModal(modal) {
  if (modal) modal.classList.add('show');
}
function closeModal(modal) {
  if (modal) modal.classList.remove('show');
}
if (openReportBtn) openReportBtn.addEventListener('click', () => openModal(reportModal));
closeReportBtns.forEach(btn => { if (btn) btn.addEventListener('click', () => closeModal(reportModal)); });
if (reportModal) reportModal.addEventListener('click', (e) => { if (e.target === reportModal) closeModal(reportModal); });

// ===========================================================
// ----------------- FETCH BOOKINGS -------------------------
// ===========================================================
async function fetchBookings(status = 'all', reportMode = '0') {
  try {
    const res = await fetch(`adbookings.php?action=fetchBookings&report_mode=${reportMode}&status=${status}`);
    const data = await res.json();
    if (data.error) {
      console.error("Error fetching bookings:", data.error);
      return [];
    }
    return data;
  } catch (err) {
    console.error("Fetch failed:", err);
    return [];
  }
}
// Get buttons
const downloadButton = document.getElementById('downloadReport');
const printButton = document.getElementById('printReport');

// Disable buttons initially
downloadButton.disabled = true;
printButton.disabled = true;
downloadButton.style.backgroundColor = '#ccc';
printButton.style.backgroundColor = '#ccc';

// ===========================================================
// ----------------- GENERATE REPORT PREVIEW ----------------
// ===========================================================
async function generateReportPreview() {
  const mode = document.getElementById('reportFilterMode').value;
  const year = document.getElementById('reportFilterYear').value;
  const month = document.getElementById('reportFilterMonth').value;
  const reportMode = document.getElementById('reportMode').value;
  const orientation = document.getElementById('reportOrientation').value || 'portrait';

  const reportContent = document.getElementById('reportContent');
  reportContent.innerHTML = '<p>Loading...</p>';

  let allBookings = await fetchBookings('all', reportMode);

  allBookings = allBookings.map(b => ({
    ...b,
    display_name:
      b.booking_type === "package" ? b.package_name :
      (b.booking_type === "boat" || b.booking_type === "tourguide") ? b.location :
      ""
  }));

  let filtered = allBookings;
  if (year) filtered = filtered.filter(b => new Date(b.created_at).getFullYear() == year);
  if (month) filtered = filtered.filter(b => new Date(b.created_at).getMonth() + 1 == month);

  if (filtered.length === 0) {
    reportContent.innerHTML = '<p>No bookings found for selected filters.</p>';

    // Disable buttons if no data
    downloadButton.disabled = true;
    printButton.disabled = true;
    downloadButton.style.backgroundColor = '#ccc';
    printButton.style.backgroundColor = '#ccc';
    return;
  }

  // Enable buttons since data exists
  downloadButton.disabled = false;
  printButton.disabled = false;
  downloadButton.style.backgroundColor = ''; // restore original
  printButton.style.backgroundColor = '';

  // Formal table layout
  const table = document.createElement('table');
  table.style.width = orientation === 'landscape' ? '1200px' : '800px';
  table.innerHTML = `
    <thead>
      <tr>
        <th>#</th>
        <th>Tourist Name</th>
        <th>Email</th>
        <th>Phone</th>
        <th>Package / Location</th>
        <th>Status</th>
        <th>Booking Created</th>
        <th>Date Booked</th>
      </tr>
    </thead>
    <tbody>
      ${filtered.map((b, i) => `
        <tr>
          <td>${i + 1}</td>
          <td>${b.name}</td>
          <td>${b.email}</td>
          <td>${b.phone || ''}</td>
          <td>${b.display_name}</td>
          <td>${b.status}</td>
          <td>${b.created_at}</td>
          <td>${b.booking_date}</td>
        </tr>
      `).join('')}
    </tbody>
  `;
  reportContent.innerHTML = '';
  reportContent.appendChild(table);
}

// Apply filter
document.getElementById('applyReportFilter').addEventListener('click', generateReportPreview);

document.addEventListener('DOMContentLoaded', () => {
    const actionRows = Array.from(document.querySelectorAll('.row-actions'));
    const closeAllActionMenus = () => {
        actionRows.forEach(row => row.classList.remove('open'));
    };

    document.querySelectorAll('.action-toggle-btn').forEach(btn => {
        btn.addEventListener('click', (e) => {
            e.preventDefault();
            e.stopPropagation();
            const row = btn.closest('.row-actions');
            if (!row) return;
            const willOpen = !row.classList.contains('open');
            closeAllActionMenus();
            if (willOpen) row.classList.add('open');
        });
    });

    document.addEventListener('click', (e) => {
        if (!e.target.closest('.row-actions')) {
            closeAllActionMenus();
        }
    });
    
    // Booking details modal
    document.querySelectorAll('.btn-details').forEach(btn => {
        btn.addEventListener('click', () => {
            const booking = JSON.parse(btn.dataset.booking);
            openBookingDetailsModal(booking);
        });
    });

    // Tourist modal
    document.querySelectorAll('.btn-tourists').forEach(btn => {
        btn.addEventListener('click', function(e){
            e.preventDefault();
            const bookingId = this.dataset.booking;
            if(!bookingId) return;

            const iframe = document.getElementById('touristPdfFrame');
            iframe.src = `php/tourists_pdf.php?booking_id=${bookingId}`;

            const modal = document.getElementById('touristModal');
            if(modal) modal.style.display = 'flex';
        });
    });

    // Close tourist modal
    const touristModalCloseBtn = document.getElementById('touristModalClose');
    if (touristModalCloseBtn) {
        touristModalCloseBtn.addEventListener('click', closeTouristModal);
    }
    const touristModalEl = document.getElementById('touristModal');
    if (touristModalEl) {
        touristModalEl.addEventListener('click', (e) => {
            if(e.target.id === 'touristModal') closeTouristModal();
        });
    }

});

function parseBookingMeta(preferredResource) {
  const result = {
    preferred: '-',
    addOn: '-',
    boatBill: '-',
    paymentOption: '-',
    payNow: '-',
    paymentStatus: 'Unpaid'
  };
  const raw = String(preferredResource || '').trim();
  if (!raw) return result;

  const parts = raw.split('|').map(p => p.trim()).filter(Boolean);
  for (const part of parts) {
    const lower = part.toLowerCase();
    if (lower.startsWith('preferred:')) {
      result.preferred = part.substring(part.indexOf(':') + 1).trim() || '-';
    } else if (lower.startsWith('add-on:')) {
      result.addOn = part.substring(part.indexOf(':') + 1).trim() || '-';
    } else if (lower.startsWith('boat bill:') || lower.startsWith('boat bill total:')) {
      result.boatBill = part.substring(part.indexOf(':') + 1).trim() || '-';
    } else if (lower.startsWith('payment:') || lower.startsWith('payment option:')) {
      result.paymentOption = part.substring(part.indexOf(':') + 1).trim() || '-';
    } else if (lower.startsWith('payment status:')) {
      result.paymentStatus = part.substring(part.indexOf(':') + 1).trim() || 'Unpaid';
    } else if (lower.startsWith('pay now:')) {
      result.payNow = part.substring(part.indexOf(':') + 1).trim() || '-';
    }
  }

  if (result.paymentStatus === 'Unpaid' && result.paymentOption !== '-') {
    const paymentOption = String(result.paymentOption).toLowerCase();
    if (paymentOption.includes('partial') || paymentOption.includes('20%')) {
      result.paymentStatus = 'Partial';
    } else if (paymentOption.includes('full')) {
      result.paymentStatus = 'Paid';
    }
  }

  return result;
}

function openBookingDetailsModal(booking) {
  const modal = document.getElementById('bookingDetailsModal');
  const content = document.getElementById('bookingDetailsContent');
  
  if (!booking || !content || !modal) return;
  const details = parseBookingMeta(booking.preferred_resource);
  const bookingType = String(booking.booking_type || '').toLowerCase();
  const packageOrLocation = bookingType === 'package'
    ? (booking.package_name || '-')
    : (booking.location || '-');

  // Render the details
  content.innerHTML = `
    <p><strong>Booking ID:</strong> ${booking.booking_id}</p>
    <p><strong>Tourist:</strong> ${booking.t_full_name}</p>
    <p><strong>Email:</strong> ${booking.t_email}</p>
    <p><strong>Phone:</strong> ${booking.booking_phone || booking.t_phone}</p>
    <p><strong>Booking Type:</strong> ${booking.booking_type}</p>
    <p><strong>Package / Location:</strong> ${packageOrLocation}</p>
    <p><strong>Pax:</strong> Adults: ${booking.num_adults}, Children: ${booking.num_children}</p>
    <p><strong>Jump Off Port:</strong> ${booking.jump_off_port || '-'}</p>
    <p><strong>Tour Type:</strong> ${booking.tour_type || '-'}</p>
    <p><strong>Tour Range:</strong> ${booking.tour_range || '-'}</p>
    <p><strong>Preferred Resource:</strong> ${details.preferred}</p>
    <p><strong>Add-on Service:</strong> ${details.addOn}</p>
    <p><strong>Total Boat Bill:</strong> ${details.boatBill}</p>
    <p><strong>Payment Option:</strong> ${details.paymentOption}</p>
    <p><strong>Amount to Pay:</strong> ${details.payNow}</p>
    <p><strong>Payment Status:</strong> ${details.paymentStatus}</p>
    <p><strong>Booking Date:</strong> ${booking.booking_date}</p>
    <p><strong>Status:</strong> ${booking.status} / ${booking.is_complete}</p>
  `;

  modal.classList.add('show');
}

function closeBookingDetailsModal() {
  const modal = document.getElementById('bookingDetailsModal');
  if (modal) modal.classList.remove('show');
}

function closeTouristModal() {
    const modal = document.getElementById('touristModal');
    if(modal) modal.style.display = 'none';
    const iframe = document.getElementById('touristPdfFrame');
    if(iframe) iframe.src = '';
}

// ===========================================================
// ----------------- PRINT PREVIEW & PDF DESIGN -------------
// ===========================================================

function getFilteredText() {
  const mode = document.getElementById('reportFilterMode').value;
  const year = document.getElementById('reportFilterYear').value;
  const month = document.getElementById('reportFilterMonth').value;

  if (mode === 'yearly' && year) return `Year: ${year}`;
  if (mode === 'monthly' && year && month) {
    const monthName = new Date(0, month - 1).toLocaleString('default', { month: 'long' });
    return `Month: ${monthName} ${year}`;
  }
  return 'All Bookings';
}

function generateReportHTML() {
  const content = document.getElementById('reportContent').innerHTML;
  if (!content) return null;

  const orientation = document.getElementById('reportOrientation').value || 'portrait';
  const filteredText = getFilteredText();

  return `
    <html>
      <head>
        <title>Booking Report</title>
        <style>
          body { font-family: 'Poppins', sans-serif; padding:0; margin:0; font-size:12px; color:#333; }
          @page { size: ${orientation} A4; margin: 1in; }
          h2 { text-align:center; color:#2b7a66; margin-bottom:10px; font-size:16px; }
          table { border-collapse: collapse; width: 100%; font-size:12pt; }
          table, th, td { border:1px solid #555; }
          th, td { padding:6px; text-align:left; }
          caption { caption-side: top; text-align:left; font-weight:bold; margin-bottom:6px; }
          .filter-date { text-align:right; font-size:14pt; font-weight:bold; color:#2b7a66; margin-bottom:6px; }
        </style>
      </head>
      <body>
        <h2>Booking Report</h2>
        <div class="filter-date">${filteredText}</div>
        ${content}
      </body>
    </html>
  `;
}

// ---------------- PRINT PREVIEW ----------------
document.getElementById('printReport').addEventListener('click', () => {
  const html = generateReportHTML();
  if (!html) return alert('No bookings to print');

  const iframe = document.createElement('iframe');
  iframe.style.position = 'absolute';
  iframe.style.width = '0';
  iframe.style.height = '0';
  iframe.style.border = '0';
  document.body.appendChild(iframe);

  const doc = iframe.contentWindow.document;
  doc.open();
  doc.write(html);
  doc.close();
  iframe.contentWindow.focus();
  iframe.contentWindow.print();
  setTimeout(() => document.body.removeChild(iframe), 1000);
});

// ---------------- PDF EXPORT ----------------
async function downloadReportPDF() {
  const button = document.getElementById('downloadReport');

  const table = document.getElementById('reportContent');
  if (!table || table.innerHTML.trim() === '') {
    alert("No content to export!");
    return;
  }

  // Disable button, gray it out, and show "Downloading..."
  button.disabled = true;
  const originalText = button.innerText;
  const originalBackground = button.style.backgroundColor;
  button.innerText = 'Downloading...';
  button.style.backgroundColor = '#ccc'; // Gray color

  try {
    const { jsPDF } = window.jspdf;
    const orientation = document.getElementById('reportOrientation').value || 'portrait';
    const paperSize = document.getElementById('reportPaperSize').value;

    let pdfFormat = 'a4';
    if (paperSize === 'letter') pdfFormat = 'letter';
    else if (paperSize === 'long') pdfFormat = 'legal';

    const doc = new jsPDF(orientation[0], 'pt', pdfFormat);
    const pdfWidth = doc.internal.pageSize.getWidth();
    const pdfHeight = doc.internal.pageSize.getHeight();
    const margin = 72; // 1 inch = 72pt

    // Add filtered text at top
    const filteredText = getFilteredText();
    doc.setFontSize(12); 
    doc.setTextColor(43, 122, 102);
    doc.text('Booking Report', pdfWidth / 2, margin / 2, { align: 'center' });
    doc.setFontSize(12);
    doc.text(filteredText, pdfWidth - margin, margin / 2, { align: 'right' });

    // Convert HTML table to canvas
    await new Promise(r => setTimeout(r, 300));
    const canvas = await html2canvas(table, { scale: 2, useCORS: true });
    const imgData = canvas.toDataURL('image/png');

    const imgProps = doc.getImageProperties(imgData);
    const pdfImgWidth = pdfWidth - margin * 2;
    const pdfImgHeight = (imgProps.height * pdfImgWidth) / imgProps.width;

    let position = margin;
    let remainingHeight = pdfImgHeight;

    while (remainingHeight > 0) {
      const pageHeight = pdfHeight - margin * 2;
      const renderHeight = remainingHeight > pageHeight ? pageHeight : remainingHeight;

      doc.addImage(imgData, 'PNG', margin, position, pdfImgWidth, pdfImgHeight, undefined, 'FAST');
      remainingHeight -= pageHeight;
      if (remainingHeight > 0) doc.addPage();
    }

    // Generate filename
    const mode = document.getElementById('reportFilterMode').value;
    const year = document.getElementById('reportFilterYear').value;
    const month = document.getElementById('reportFilterMonth').value;
    let filename = 'bookings_all.pdf';
    if (mode === 'yearly' && year) filename = `bookings_${year}.pdf`;
    else if (mode === 'monthly' && year && month) {
      const monthName = new Date(0, month - 1).toLocaleString('default', { month: 'long' });
      filename = `bookings_${monthName}_${year}.pdf`;
    }

    doc.save(filename);

  } catch (error) {
    console.error('Error generating PDF:', error);
    alert('An error occurred while generating the PDF.');
  } finally {
    // Re-enable button and restore original text and color
    button.disabled = false;
    button.innerText = originalText;
    button.style.backgroundColor = originalBackground;
  }
}

document.getElementById('downloadReport').addEventListener('click', downloadReportPDF);
const decisionModal = document.getElementById("decisionModal");
const decisionCloseBtns = [
    document.getElementById("decisionModalClose"),
    document.getElementById("decisionCancelBtn")
];

// Close modal on close buttons click
decisionCloseBtns.forEach(btn => {
    if (btn) {
        btn.onclick = () => {
            decisionModal.style.display = "none";
        };
    }
});

// Function to open modal
function openDecisionModal(event, form, action, returnTab) {
    event.preventDefault();

    const bookingId = form.querySelector("input[name='id']").value;

    // Helper to create or update hidden input
    function setHiddenInput(id, value) {
        let input = document.getElementById(id);
        if (!input) {
            input = document.createElement("input");
            input.type = "hidden";
            input.id = id;
            decisionModal.appendChild(input);
        }
        input.value = value;
    }

    setHiddenInput("decisionBookingId", bookingId);
    setHiddenInput("decisionAction", action);
    setHiddenInput("decisionReturnTab", returnTab);

    // -----------------------------
    // DYNAMIC MODAL TITLE
    // -----------------------------
    const modalTitle = decisionModal.querySelector(".decision-modal-header h3");
    if (modalTitle) {
        if (action.toLowerCase() === "cancel") {
            modalTitle.textContent = "Cancel Reason";
        } else if (action.toLowerCase() === "decline") {
            modalTitle.textContent = "Decline Reason";
        } else {
            modalTitle.textContent = "Reason Required";
        }
    }

    // Show modal
    decisionModal.style.display = "flex";

    // Get category field and submit button
    const categoryField = decisionModal.querySelector("select#decisionCategory");
    const submitBtn = decisionModal.querySelector("#decisionOkBtn");

    if (!submitBtn) return;

    function updateSubmitButton() {
        if (!categoryField || !categoryField.value || categoryField.value.trim() === "") {
            submitBtn.disabled = true;
            submitBtn.style.backgroundColor = "#ccc";
            submitBtn.style.cursor = "not-allowed";
        } else {
            submitBtn.disabled = false;
            submitBtn.style.backgroundColor = "";
            submitBtn.style.cursor = "";
        }
    }

    updateSubmitButton();

    if (categoryField) {
        categoryField.addEventListener("input", updateSubmitButton);
        categoryField.addEventListener("change", updateSubmitButton);
    }

    submitBtn.onclick = () => {
        const bookingId = document.getElementById("decisionBookingId").value;
        const action = document.getElementById("decisionAction").value;
        const returnTab = document.getElementById("decisionReturnTab").value;
        const category = categoryField ? categoryField.value : "";
        const note = decisionModal.querySelector("#decisionNote")?.value.trim() || "";

        const form = document.createElement("form");
        form.method = "POST";
        form.style.display = "none";
        form.innerHTML = `
            <input name="id" value="${bookingId}">
            <input name="action" value="${action}">
            <input name="return_tab" value="${returnTab}">
            <input name="decision_category" value="${category}">
            <input name="decision_note" value="${note}">
        `;
        document.body.appendChild(form);
        form.submit();
    };

    return false;
}



// ===========================================================
// ----------------- SWEETALERT CONFIRMATION ----------------
// ===========================================================
function confirmAction(event, form, action) {
  event.preventDefault();

  const texts = {
    accept: "Accept this booking?",
    decline: "Decline this booking?",
    finish: "Mark this booking as Completed?",
    cancel: "Cancel this booking?"
  };

  const icons = {
    accept: 'question',
    decline: 'warning',
    finish: 'info',
    cancel: 'warning'
  };

  Swal.fire({
    icon: icons[action] || 'question',
    title: 'Confirm Action',
    html: `<p style="font-size:16px;margin-top:8px;">${texts[action] || "Proceed?"}</p>`,
    showCancelButton: true,
    confirmButtonColor: "#49a47a",
    cancelButtonColor: "#d33",
    confirmButtonText: "Yes, proceed",
    cancelButtonText: "Cancel"
  }).then(r => {
    if (r.isConfirmed) form.submit();
  });

  return false;
}

// ===========================================================
// ----------------- SMART TOOLTIP ---------------------------
// ===========================================================

// ----------------- TOOLTIP SETUP ----------------
const tooltip = document.createElement('div');
tooltip.className = 'profile-tooltip'; // Add your CSS class for styling
tooltip.style.position = 'absolute';
tooltip.style.display = 'none';
tooltip.style.zIndex = '9999';
document.body.appendChild(tooltip);

let hideTimeout;

function positionTooltip(img) {
  const rect = img.getBoundingClientRect();
  const tRect = tooltip.getBoundingClientRect();
  const spacing = 8;
  const pageTop = window.scrollY;
  const pageLeft = window.scrollX;
  const viewportTop = pageTop + 10;
  const viewportBottom = pageTop + window.innerHeight - 10;
  const viewportRight = pageLeft + window.innerWidth - 10;
  let top = rect.bottom + pageTop + spacing;
  let left = rect.left + pageLeft;

  // Prevent overflow right
  if (left + tRect.width > viewportRight) {
    left = viewportRight - tRect.width;
  }

  // Prevent overflow left
  if (left < pageLeft + 10) {
    left = pageLeft + 10;
  }

  // Move above if not enough bottom space
  if (top + tRect.height > viewportBottom) {
    top = rect.top + pageTop - tRect.height - spacing;
  }
  if (top < viewportTop) {
    top = viewportTop;
  }

  tooltip.style.top = `${top}px`;
  tooltip.style.left = `${left}px`;
}

function showTooltip(img) {
  clearTimeout(hideTimeout);

  tooltip.innerHTML = `
    <div class="tooltip-header">
      <img src="${img.src}" alt="profile">
      <div class="tooltip-info">
        <div class="tooltip-name">${img.dataset.fullname || 'Guest'}</div>
        <div class="tooltip-text">Email: ${img.dataset.email || '-'}</div>
        <div class="tooltip-text">Phone: ${img.dataset.phone || '-'}</div>
        <div class="tooltip-text">Address: ${img.dataset.address || '-'}</div>
        <div class="tooltip-text">Finished Bookings: ${img.dataset.finishedBookings || 0}</div>
        <a href="mailto:${img.dataset.email || ''}" class="tooltip-button">
          <img src="img/emailicon.png" alt="email"> Send Email
        </a>
      </div>
    </div>
  `;

  tooltip.style.display = 'block';
  tooltip.style.visibility = 'hidden';

  requestAnimationFrame(() => {
    positionTooltip(img);
    tooltip.style.visibility = 'visible';
    tooltip.classList.add('show');
  });
}

function hideTooltip() {
  hideTimeout = setTimeout(() => {
    tooltip.classList.remove('show');
    tooltip.style.display = 'none';
  }, 150);
}

document.querySelectorAll('.hover-profile').forEach(img => {
  img.addEventListener('mouseenter', () => showTooltip(img));
  img.addEventListener('mouseleave', hideTooltip);
});

tooltip.addEventListener('mouseenter', () => clearTimeout(hideTimeout));
tooltip.addEventListener('mouseleave', hideTooltip);

document.addEventListener('click', (e) => {
  if (!tooltip.contains(e.target) && !e.target.classList.contains('hover-profile')) {
    tooltip.classList.remove('show');
    tooltip.style.display = 'none';
  }
});


// ===========================================================
// ----------------- OVERVIEW FILTER -------------------------
// ===========================================================
const overviewForm = document.getElementById('overviewFilterForm');
if (overviewForm) {
  const range = overviewForm.querySelector('#adRangeFilter');
  const year = overviewForm.querySelector('#adRangeYear');
  const month = overviewForm.querySelector('#adRangeMonth');
  const date = overviewForm.querySelector('#adRangeDate');

  const syncOverviewFields = () => {
    const mode = range ? range.value : 'all';
    if (year) year.style.display = (mode === 'yearly' || mode === 'monthly' || mode === 'daily') ? '' : 'none';
    if (month) month.style.display = mode === 'monthly' ? '' : 'none';
    if (date) date.style.display = mode === 'daily' ? '' : 'none';
  };

  if (range) range.addEventListener('change', syncOverviewFields);
  syncOverviewFields();
}

// ===========================================================
// ----------------- LIVE SEARCH ----------------------------
// ===========================================================
const searchInput = document.querySelector('#searchForm input[name="search"]');
const tabPages = document.querySelectorAll('.tab-page');

if (searchInput) searchInput.addEventListener('input', () => {
  const query = searchInput.value.toLowerCase().trim();

  tabPages.forEach(tab => {
    const tbody = tab.querySelector('tbody');
    const rows = Array.from(tbody.querySelectorAll('tr'));

    // Remove any existing "no results" row
    const oldNoResults = tbody.querySelector('.no-results');
    if (oldNoResults) oldNoResults.remove();

    let anyVisible = false;

    rows.forEach(row => {
      row.style.display = row.classList.contains('no-results') ? 'none' :
        row.innerText.toLowerCase().includes(query) ? '' : 'none';
      if (row.style.display === '') anyVisible = true;
    });

    // Add "No results found" if nothing matches
    if (!anyVisible) {
      const tr = document.createElement('tr');
      tr.className = 'no-results';
      tr.innerHTML = `<td colspan="100%" style="text-align:center;color:#777;">No results found</td>`;
      tbody.appendChild(tr);
    }
  });
});
</script>
</body>
</html>

