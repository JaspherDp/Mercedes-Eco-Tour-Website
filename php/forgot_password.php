<?php
// ---------------------------------------
// Forgot Password Handler - JSON API
// Handles check_email and save_new_password
// ---------------------------------------
session_start();
header('Content-Type: application/json');

// --- Hide PHP warnings/notices from output to keep JSON valid ---
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);

require_once 'db_connection.php'; // Ensure $pdo is available

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

            // Hash password and update
            $passwordHash = password_hash($newPassword, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("UPDATE tourist SET password_hash = ? WHERE email = ?");
            $stmt->execute([$passwordHash, $email]);

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
