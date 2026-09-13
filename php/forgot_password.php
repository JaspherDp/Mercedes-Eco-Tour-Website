<?php
// ---------------------------------------
// Forgot Password Handler - JSON API
// Handles check_email and save_new_password
// ---------------------------------------
    require_once __DIR__ . '/session_security.php';
    AppSessionStart();
header('Content-Type: application/json');

// --- Hide PHP warnings/notices from output to keep JSON valid ---
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);

require_once 'db_connection.php'; // Ensure $pdo is available
require_once __DIR__ . '/activity_logger.php';

try {
    $action = $_POST['action'] ?? '';
    $email = trim($_POST['email'] ?? '');

    if (!$action) {
        echo json_encode(['status' => 'error', 'message' => 'Action required']);
        exit;
    }

    switch ($action) {

        // ---------------------------
        // Check if email exists
        // ---------------------------
        case 'check_email':
            if (!$email) {
                echo json_encode(['exists' => false]);
                exit;
            }

            $stmt = $pdo->prepare("SELECT tourist_id FROM tourist WHERE email = ?");
            $stmt->execute([$email]);
            $exists = $stmt->rowCount() > 0;

            echo json_encode(['exists' => $exists]);
            exit;

        // ---------------------------
        // Save new password
        // ---------------------------
        case 'save_new_password':
            $newPassword = $_POST['newPassword'] ?? '';
            if (!$email || !$newPassword) {
                echo json_encode(['status' => 'error', 'message' => 'Email and new password required']);
                exit;
            }
            if (!is_string($newPassword) || strlen($newPassword) > 128
                || strlen($newPassword) < 10
                || !preg_match('/[A-Z]/', $newPassword)
                || !preg_match('/[a-z]/', $newPassword)
                || !preg_match('/[0-9]/', $newPassword)) {
                http_response_code(422);
                echo json_encode(['status' => 'error', 'message' => 'Password must be 10-128 characters and include uppercase, lowercase, and a number.']);
                exit;
            }
            if (
                !isset($_SESSION['forgot_email'], $_SESSION['forgot_verified_at']) ||
                $_SESSION['forgot_email'] !== $email ||
                (time() - (int)$_SESSION['forgot_verified_at']) > 600
            ) {
                echo json_encode(['status' => 'error', 'message' => 'Verify your email again before changing the password.']);
                exit;
            }

            // Hash password and update
            $passwordHash = password_hash($newPassword, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("UPDATE tourist SET password_hash = ? WHERE email = ?");
            $stmt->execute([$passwordHash, $email]);
            if ($stmt->rowCount() > 0) {
                $userStmt = $pdo->prepare('SELECT tourist_id, full_name FROM tourist WHERE email = ? LIMIT 1');
                $userStmt->execute([$email]);
                $resetUser = $userStmt->fetch(PDO::FETCH_ASSOC);
                if ($resetUser) {
                    logActivity(
                        $pdo, 'Tourist', (int)$resetUser['tourist_id'], (string)$resetUser['full_name'],
                        'Password Reset', 'Reset the tourist account password.', 'Authentication',
                        (int)$resetUser['tourist_id']
                    );
                }
            }

            unset($_SESSION['forgot_email'], $_SESSION['forgot_code'], $_SESSION['forgot_code_time'], $_SESSION['forgot_verified_at']);

            echo json_encode(['status' => 'success', 'message' => 'Password updated successfully']);
            exit;

        default:
            echo json_encode(['status' => 'error', 'message' => 'Invalid action']);
            exit;
    }

} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    exit;
}
