<?php
chdir(__DIR__ . '/..');
session_start();
require_once "php/db_connection.php";
include 'php/alert.php';

// ==========================
// Logout handling
// ==========================
if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    session_unset();
    session_destroy();
    echo "<script>
        alert('You have been logged out. Session expired.');
        window.location.href = 'homepage.php';
    </script>";
    exit();
}

// ==========================
// Session check
// ==========================
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    echo "<script>
        alert('Session expired! Please login again.');
        window.location.href = 'php/admin_login.php';
    </script>";
    exit();
}

// ==========================
// Prevent cached pages
// ==========================
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Cache-Control: post-check=0, pre-check=0', false);
header('Pragma: no-cache');
header('Expires: 0');

// ==========================
// Toggle status (AJAX)
// ==========================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['ajax_action'] ?? '') === 'toggle_status') {
    header('Content-Type: application/json; charset=utf-8');
    $op_id = intval($_POST['operator_id'] ?? 0);
    $new_status = ($_POST['new_status'] ?? '') === 'active' ? 'active' : 'inactive';

    if ($op_id > 0) {
        try {
            $stmt = $pdo->prepare("UPDATE operators SET status = ? WHERE operator_id = ?");
            $stmt->execute([$new_status, $op_id]);

            $title = $new_status === 'active' ? 'Operator Activated' : 'Operator Deactivated';
            $message = "The operator has been successfully marked as $new_status.";

            echo json_encode([
                'success' => true,
                'new_status' => $new_status,
                'alert' => [
                    'type' => 'success',
                    'title' => $title,
                    'message' => $message
                ]
            ]);
        } catch (Exception $e) {
            echo json_encode([
                'success' => false,
                'alert' => [
                    'type' => 'error',
                    'title' => 'Update Failed',
                    'message' => $e->getMessage()
                ]
            ]);
        }
    } else {
        echo json_encode([
            'success' => false,
            'alert' => [
                'type' => 'error',
                'title' => 'Invalid ID',
                'message' => 'The operator ID is invalid.'
            ]
        ]);
    }
    exit;
}

// ==========================
// Add operator (form)
// ==========================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_operator'])) {
    $fullname = trim($_POST['fullname']);
    $username = trim($_POST['username']);
    $email = trim($_POST['email']);
    $pass = $_POST['password'];
    $confirm = $_POST['confirm_password'];

    if ($fullname && $username && $email && $pass && $pass === $confirm) {
        $exists = $pdo->prepare("SELECT COUNT(*) FROM operators WHERE username = ? OR email = ?");
        $exists->execute([$username, $email]);
        if ($exists->fetchColumn() > 0) {
            $_SESSION['alert'] = [
                'type' => 'warning',
                'title' => 'Duplicate Entry',
                'message' => 'Username or Email already exists.',
                'showConfirm' => true
            ];
        } else {
            $hash = password_hash($pass, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("INSERT INTO operators (fullname, username, email, password, status) VALUES (?, ?, ?, ?, 'active')");
            $stmt->execute([$fullname, $username, $email, $hash]);

            $_SESSION['alert'] = [
                'type' => 'success',
                'title' => 'Operator Added',
                'message' => "Operator <b>$fullname</b> has been successfully added."
            ];
            header("Location: adoperator.php");
            exit();
        }
    } else {
        $_SESSION['alert'] = [
            'type' => 'error',
            'title' => 'Form Error',
            'message' => 'Please fill all fields correctly.'
        ];
    }
}

// ==========================
// Fetch operator data + completed package bookings
// ==========================
$query = "
    SELECT 
        o.*,
        COALESCE(b.completed_count, 0) AS completed_bookings
    FROM operators o
    LEFT JOIN (
        SELECT operator_id, COUNT(*) AS completed_count
        FROM bookings
        WHERE booking_type = 'package' AND is_complete = 'completed'
        GROUP BY operator_id
    ) b ON o.operator_id = b.operator_id
    ORDER BY o.operator_id ASC
";
$ops = $pdo->query($query)->fetchAll(PDO::FETCH_ASSOC);

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title>iTour Mercedes - Manage Operators</title>
<link rel="icon" type="image/png" href="img/newlogo.png" />
<link href="https://fonts.googleapis.com/css2?family=Roboto&display=swap" rel="stylesheet" />
<style>
:root {
  --green: #2b7a66;
  --gray: #777;
  --light: #f5f7fa;
  --shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
}

.admin-sidebar.collapsed ~ .main-content { 
  margin-left:80px; 
}

body {
  margin: 0;
  font-family: 'Segoe UI', sans-serif;
  background: var(--light);
}

.admin-container {
  display: flex;
  min-height: 100vh;
}

.main-content {
  flex: 1;
  margin-left: 240px;
  display: flex;
  flex-direction: column;
  transition:margin-left 0.3s ease;
}

.admin-header {
  background: white;
  border-bottom: 2px solid #eee;
  padding: 1rem 2rem;
  box-shadow: 0 2px 5px rgba(0, 0, 0, 0.05);
}

.admin-header h2 {
  color: var(--green);
  margin: 0;
  font-size: 1.5rem;
}

.dashboard-content {
  padding: 2rem;
  display: flex;
  flex-direction: column;
  gap: 2rem;
}

.operators-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
  gap: 1.5rem;
}

.operator-card {
  background: white;
  border-radius: 12px;
  box-shadow: var(--shadow);
  padding: 1.5rem;
  display: flex;
  flex-direction: column;
  align-items: center;
  transition: transform 0.2s;
}

.operator-card:hover {
  transform: translateY(-4px);
}

.operator-card img {
  width: 80px;
  height: 80px;
  border-radius: 50%;
  object-fit: cover;
  margin-bottom: 1rem;
  border: 3px solid var(--green);
}

.operator-card h3 {
  margin: 0;
  color: var(--green);
}

.operator-card p {
  margin: 2px 0;
  color: #444;
  font-size: 0.95rem;
}

.stats {
  margin-top: 0.5rem;
  text-align: center;
  font-size: 0.9rem;
  color: #555;
}

.stats span {
  display: block;
  margin: 3px 0;
}

.status-badge {
  margin-top: 0.6rem;
  display: inline-block;
  padding: 6px 12px;
  border-radius: 20px;
  color: #fff;
  font-size: 0.85rem;
}

.status-badge.active {
  background: var(--green);
}

.status-badge.inactive {
  background: var(--gray);
}

.switch {
  position: relative;
  display: inline-block;
  width: 50px;
  height: 26px;
  margin-top: 10px;
}

.switch input {
  display: none;
}

.slider {
  position: absolute;
  cursor: pointer;
  top: 0;
  left: 0;
  right: 0;
  bottom: 0;
  background: #ccc;
  transition: 0.3s;
  border-radius: 26px;
}

.slider:before {
  content: "";
  position: absolute;
  height: 20px;
  width: 20px;
  left: 3px;
  top: 3px;
  background: white;
  border-radius: 50%;
  transition: 0.3s;
}

.switch input:checked + .slider {
  background: var(--green);
}

.switch input:checked + .slider:before {
  transform: translateX(24px);
}

.add-operator-btn {
  background: var(--green);
  color: white;
  border: none;
  padding: 12px 20px;
  border-radius: 8px;
  font-weight: 600;
  cursor: pointer;
  align-self: center;
  box-shadow: var(--shadow);
}

.add-operator-btn:hover {
  background: #3a865f;
}

.modal {
  display: none;
  position: fixed;
  inset: 0;
  background: rgba(0, 0, 0, 0.4);
  justify-content: center;
  align-items: center;
  z-index: 1000;
}

.modal-content {
  background: white;
  border-radius: 12px;
  padding: 2rem 1.5rem;
  width: 90%;
  max-width: 450px;
  box-shadow: var(--shadow);
  position: relative;
  display: flex;
  flex-direction: column;
  gap: 1rem;
}

.modal-content h3 {
  color: var(--green);
  margin-top: 0;
  text-align: center;
}

.modal-content label {
  display: block;
  margin-bottom: 4px;
  color: #333;
  font-weight: 500;
}

.modal-content input {
  width: 100%;
  padding: 10px 40px 10px 12px; /* space for password toggle icon */
  border: 1px solid #ccc;
  border-radius: 6px;
  font-size: 0.95rem;
  box-sizing: border-box;
  margin-bottom: 8px;
}

.password-wrapper {
  position: relative;
}

.toggle-password {
  position: absolute;
  right: 10px;
  top: 50%;
  transform: translateY(-50%);
  width: 22px;
  height: 22px;
  cursor: pointer;
  transition: transform 0.2s;
}

.toggle-password:hover {
  transform: translateY(-50%) scale(1.2);
}

.modal-content button {
  margin-top: 12px;
  width: 100%;
  background: var(--green);
  color: white;
  border: none;
  padding: 12px;
  border-radius: 6px;
  cursor: pointer;
  font-weight: 600;
  transition: background 0.3s;
}

.modal-content button:hover {
  background: #3a865f;
}

.close-btn {
  position: absolute;
  right: 16px;
  top: 10px;
  font-size: 1.4rem;
  color: #333;
  cursor: pointer;
}

.add-operator-wrapper {
  display: flex;
  justify-content: center;
  margin-top: 1rem; /* space above button */
}


</style>
<link rel="stylesheet" href="styles/admin_panel_theme.css" />
</head>
<body>
<div class="admin-container">
  <?php include 'admin_sidebar.php'; ?>
  <main class="main-content">
    <header class="admin-header">
      <h2>Manage Operators</h2>
    </header>

    <div class="dashboard-content operators-dashboard-content">

  <div class="operators-actions" style="display:flex; justify-content:space-between; align-items:center;">
    <button class="add-operator-btn" id="openModalBtn">Add Operator</button>
    <input type="text" id="searchOperator" placeholder="Search operator..." style="padding:8px 12px; border-radius:6px; border:1px solid #ccc; width:200px;">
  </div>

  <table id="operatorsTable" style="width:100%; border-collapse:collapse; background:white; border-radius:12px; overflow:hidden;">
    <thead style="background:var(--green); color:white;">
      <tr>
        <th style="padding:12px;">Profile</th>
        <th style="padding:12px;">Full Name</th>
        <th style="padding:12px;">Email</th>
        <th style="padding:12px;">Status</th>
        <th style="padding:12px;">Completed Bookings</th>
        <th style="padding:12px;">Toggle Status</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($ops as $op): 
        $imgPath = 'img/profileicon.png';
        if (!empty($op['profile_pic'])) {
          if (file_exists(__DIR__ . '/' . $op['profile_pic'])) $imgPath = $op['profile_pic'];
          elseif (file_exists(__DIR__ . '/../uploads/profile/' . $op['profile_pic'])) $imgPath = 'uploads/profile/' . $op['profile_pic'];
        }
      ?>
      <tr id="op-<?= $op['operator_id'] ?>" style="border-bottom:1px solid #eee;">
        <td style="padding:8px; text-align:center;">
          <img src="<?= htmlspecialchars($imgPath) ?>" alt="Profile" style="width:50px;height:50px;border-radius:50%;object-fit:cover;" onerror="this.src='img/profileicon.png'">
        </td>
        <td style="padding:8px;"><?= htmlspecialchars($op['fullname']) ?></td>
        <td style="padding:8px;"><?= htmlspecialchars($op['email']) ?></td>
        <td style="padding:8px;">
          <span class="status-badge <?= htmlspecialchars($op['status']) ?>"><?= ucfirst($op['status']) ?></span>
        </td>
        <td style="padding:8px; text-align:center;"><?= htmlspecialchars($op['completed_bookings']) ?></td>
        <td style="padding:8px; text-align:center;">
          <label class="switch">
            <input type="checkbox" data-opid="<?= $op['operator_id'] ?>" <?= $op['status']==='active'?'checked':'' ?>>
            <span class="slider"></span>
          </label>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<!-- Modal Form remains the same -->
<div class="modal" id="operatorModal">
  <div class="modal-content">
    <span class="close-btn" id="closeModal">&times;</span>
    <h3>Add Operator</h3>
    <form method="POST" autocomplete="off" class="add-operator-form">
      <div class="form-group">
        <label>Full Name</label>
        <input type="text" name="fullname" required>
      </div>
      <div class="form-group">
        <label>Username</label>
        <input type="text" name="username" required>
      </div>
      <div class="form-group">
        <label>Email</label>
        <input type="email" name="email" required>
      </div>
      <div class="form-group password-group">
        <label>Temporary Password</label>
        <div class="password-wrapper">
          <input type="password" name="password" required>
          <img src="img/passwordhide.png" class="toggle-password" alt="Toggle Password">
        </div>
      </div>
      <div class="form-group password-group">
        <label>Confirm Password</label>
        <div class="password-wrapper">
          <input type="password" name="confirm_password" required>
          <img src="img/passwordhide.png" class="toggle-password" alt="Toggle Password">
        </div>
      </div>
      <button type="submit" name="add_operator">Add Operator</button>
    </form>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
// ✅ Modal controls
const modal = document.getElementById('operatorModal');
const openBtn = document.getElementById('openModalBtn');
const closeBtn = document.getElementById('closeModal');
// ✅ Operator search
const searchInput = document.getElementById('searchOperator');
searchInput.addEventListener('input', () => {
  const filter = searchInput.value.toLowerCase();
  document.querySelectorAll('#operatorsTable tbody tr').forEach(row => {
    const name = row.cells[1].textContent.toLowerCase();
    const email = row.cells[2].textContent.toLowerCase();
    row.style.display = (name.includes(filter) || email.includes(filter)) ? '' : 'none';
  });
});


// Open modal
openBtn.addEventListener('click', () => {
  modal.style.display = 'flex';
});

// Close modal
closeBtn.addEventListener('click', () => {
  modal.style.display = 'none';
});

// Close modal when clicking outside content
window.addEventListener('click', (e) => {
  if (e.target === modal) modal.style.display = 'none';
});

// ✅ Toggle status for operators with SweetAlert2
document.querySelectorAll('.switch input').forEach(cb => {
  cb.addEventListener('change', function() {
    const opId = this.dataset.opid;
    const newStatus = this.checked ? 'active' : 'inactive';
    const checkbox = this;
    const badge = document.querySelector(`#op-${opId} .status-badge`);

    // ⚠️ Step 1: Confirmation alert
    Swal.fire({
      icon: 'warning',
      title: 'Change Operator Status?',
      html: `<p style="font-size:16px;margin-top:8px;">
             Are you sure you want to mark this operator as <b>${newStatus}</b>?</p>`,
      showCancelButton: true,
      confirmButtonColor: '#49A47A',
      cancelButtonColor: '#d33',
      confirmButtonText: 'Yes, proceed',
      cancelButtonText: 'Cancel',
      focusCancel: true
    }).then((result) => {
      if (result.isConfirmed) {
        // ✅ Step 2: Send AJAX request
        fetch('adoperator.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body: new URLSearchParams({
            ajax_action: 'toggle_status',
            operator_id: opId,
            new_status: newStatus
          })
        })
        .then(r => r.json())
        .then(data => {
          if (data.success) {
            // Update badge
            badge.textContent = newStatus.charAt(0).toUpperCase() + newStatus.slice(1);
            badge.className = 'status-badge ' + newStatus;

            // ✅ Success alert
            Swal.fire({
              icon: 'success',
              title: 'Status Updated',
              html: `<p style="font-size:16px;margin-top:8px;">
                     Operator is now <b>${newStatus}</b>.</p>`,
              confirmButtonColor: '#49A47A'
            });
          } else {
            // ❌ Error alert
            Swal.fire({
              icon: 'error',
              title: 'Update Failed',
              html: `<p style="font-size:16px;margin-top:8px;">
                     ${data.message || 'Failed to update operator status.'}</p>`,
              confirmButtonColor: '#d33'
            });
            checkbox.checked = !checkbox.checked;
          }
        })
        .catch(() => {
          Swal.fire({
            icon: 'error',
            title: 'Network Error',
            html: `<p style="font-size:16px;margin-top:8px;">
                   Could not reach the server. Please try again.</p>`,
            confirmButtonColor: '#d33'
          });
          checkbox.checked = !checkbox.checked;
        });
      } else {
        // Revert checkbox if cancelled
        checkbox.checked = !checkbox.checked;
      }
    });
  });
});

// ✅ Password toggle
document.querySelectorAll('.toggle-password').forEach(icon => {
  icon.addEventListener('click', () => {
    const input = icon.previousElementSibling;
    if (input.type === 'password') {
      input.type = 'text';
      icon.src = 'img/passwordsee.png';
    } else {
      input.type = 'password';
      icon.src = 'img/passwordhide.png';
    }
  });
});
</script>

</body>
</html>

