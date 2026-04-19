<?php
chdir(__DIR__ . '/..');
session_start();

// ✅ Logout Action
if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    session_unset();
    session_destroy();
    echo "<script>
        alert('You have been logged out. Session expired.');
        window.location.href = 'homepage.php';
    </script>";
    exit();
}

// ✅ Session Authentication Check
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    echo "<script>
        alert('Session expired! Please login again.');
        window.location.href = 'php/admin_login.php';
    </script>";
    exit();
}

// ✅ Prevent Access using Back Button / Cached Pages
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Cache-Control: post-check=0, pre-check=0', false);
header('Pragma: no-cache');
header('Expires: 0');
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>iTour Mercedes - Admin Panel</title>
  <link rel="icon" type="image/png" href="img/newlogo.png" />
  <link href="https://fonts.googleapis.com/css2?family=Roboto&display=swap" rel="stylesheet" />

  <!-- ✅ Sidebar CSS -->
  <style>
    body {
      margin: 0;
      font-family: 'Segoe UI', sans-serif;
      background-color: #f5f7fa;
    }

    .admin-container {
      display: flex;
      min-height: 100vh;
    }

    .main-content {
      flex: 1;
      margin-left: 240px;
      transition: margin-left 0.3s ease;
      display: flex;
      flex-direction: column;
    }

    .admin-sidebar.collapsed ~ .main-content {
      margin-left: 80px;
    }

    .admin-header {
      background-color: white;
      padding: 1rem 2rem;
      border-bottom: 2px solid #eee;
      box-shadow: 0 2px 5px rgba(0,0,0,0.05);
    }

    .admin-header h2 {
      color: #49A47A;
      font-size: 1.5rem;
      margin: 0;
      margin-left: 1rem;
    }

    .dashboard-content {
      padding: 3rem;
    }

    .dashboard-content h3 {
      color: #49A47A;
      margin-bottom: 0.5rem;
    }

    .dashboard-content p {
      color: #555;
      font-size: 1rem;
    }
  </style>
  <link rel="stylesheet" href="styles/admin_panel_theme.css" />
</head>

<body>
  <div class="admin-container">
    
    <!-- ✅ Include Sidebar -->
    <?php include 'admin_sidebar.php'; ?>

    <!-- ✅ Main Content -->
    <main class="main-content">
      <header class="admin-header">
        <div class="admin-header-left">
          <h2>Activity Log</h2>
          <p class="admin-header-subtitle">Welcome, <?= $admin_username ?? 'Website Admin' ?></p>
        </div>
      </header>
    </main>
  </div>

  <script>
    // Disable cached pages on back navigation
    window.addEventListener('pageshow', function(event) {
        if (event.persisted) window.location.reload();
    });
    window.history.pushState(null, "", window.location.href);
    window.onpopstate = function () { window.location.reload(); };
  </script>
</body>
</html>

