<?php
session_start();
require 'db_connection.php';

header('Content-Type: application/json');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        echo json_encode(["status" => "error", "message" => "Invalid request"]);
        exit;
    }

    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($email) || empty($password)) {
        echo json_encode(["status" => "error", "message" => "Email and password are required"]);
        exit;
    }

    // Query the tourist table
    $stmt = $pdo->prepare("SELECT * FROM tourist WHERE email = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    // Check if user exists and password is correct
    if (!$user || !password_verify($password, $user['password_hash'])) {
        echo json_encode(["status" => "error", "message" => "Email or password is wrong"]);
        exit;
    }

    // Check if email is verified
    if (!$user['email_verified']) {
        echo json_encode(["status" => "error", "message" => "Please verify your email before logging in"]);
        exit;
    }

    // Check if account is banned
    if ($user['status'] === 'banned') {
        $ban_note = $user['ban_note'] ?: "No reason provided.";
        echo json_encode([
            "status" => "banned",
            "message" => "Your account has been banned. Reason: " . $ban_note
        ]);
        exit;
    }

    // --- Save session ---
    $_SESSION['tourist_id']    = $user['tourist_id'];
    $_SESSION['tourist_email'] = $user['email'];
    $_SESSION['full_name']     = $user['full_name'];

    // Split full_name into first/last name for forms
    $nameParts = explode(" ", $user['full_name'], 2);
    $_SESSION['first_name'] = $nameParts[0];
    $_SESSION['last_name']  = isset($nameParts[1]) ? $nameParts[1] : "";

    $redirectUrl = $_SESSION['post_login_redirect'] ?? null;
    if ($redirectUrl) {
        unset($_SESSION['post_login_redirect']);
    }

    echo json_encode([
        "status"  => "success",
        "message" => "Login successful!",
        "redirect_url" => $redirectUrl,
        "user"    => [
            "id"         => $_SESSION['tourist_id'],
            "email"      => $_SESSION['tourist_email'],
            "full_name"  => $_SESSION['full_name'],
            "first_name" => $_SESSION['first_name'],
            "last_name"  => $_SESSION['last_name']
        ]
    ]);

} catch (PDOException $e) {
    error_log("Login error: " . $e->getMessage());
    echo json_encode(["status" => "error", "message" => "Email or password is wrong"]);
}
