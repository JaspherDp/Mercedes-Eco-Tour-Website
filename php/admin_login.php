<?php
session_start();
require 'db_connection.php';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $username = trim($_POST['username']);
    $password = trim($_POST['password']);

    $stmt = $pdo->prepare("SELECT * FROM admin_users WHERE username = ?");
    $stmt->execute([$username]);
    $admin = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($admin && password_verify($password, $admin['password'])) {
        $_SESSION['admin_logged_in'] = true;
        $_SESSION['admin_name'] = $admin['full_name'];

        echo "<script>
                alert('Login Successful!');
                window.location.href = '../adhomepage.php';
              </script>";
        exit();
    } else {
        echo "<script>alert('Incorrect username or password!');</script>";
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>iTour Mercedes - Admin Login</title>
<link rel="icon" type="image/png" href="img/newlogo.png" />

<style>
    body {
        background: rgba(0, 0, 0, 0.6);
        margin: 0;
        font-family: "Roboto", sans-serif;
        display: flex;
        justify-content: center;
        align-items: center;
        height: 100vh;
    }

    .adlog-modal {
        background: #fff;
        padding: 2rem;
        border-radius: 12px;
        width: 380px;
        max-width: 90%;
        position: relative;
        box-shadow: 0 15px 30px rgba(0,0,0,0.2);
    }

    .adlog-title {
        text-align: center;
        margin-bottom: 1rem;
        color: #2E7B45;
        font-weight: 600;
        font-size: 1.5rem;
    }

    .adlog-form {
        display: flex;
        flex-direction: column;
        gap: 1.3rem;
    }

    .adlog-input-group {
        position: relative;
        display: flex;
        flex-direction: column;
    }

    .adlog-input-group input {
        padding: 12px;
        font-size: 1rem;
        border: 2px solid #ccc;
        border-radius: 6px;
        outline: none;
        transition: all 0.3s ease;
    }

    .adlog-input-group input:focus {
        border-color: #2E7B45;
    }

    .adlog-input-group label {
        position: absolute;
        left: 12px;
        top: 12px;
        font-size: 0.9rem;
        color: #999;
        pointer-events: none;
        background: #fff;
        padding: 0 4px;
        transition: all 0.3s ease;
    }

    .adlog-input-group input:focus + label,
    .adlog-input-group input:not(:placeholder-shown) + label {
        top: -10px;
        font-size: 0.75rem;
        color: #3368A1;
    }

    .adlog-btn {
        padding: 12px;
        border-radius: 6px;
        background-color: #2E7B45;
        color: white;
        font-size: 1rem;
        border: none;
        cursor: pointer;
        transition: all 0.3s ease;
    }

    .adlog-btn:hover {
        background-color: #3368A1;
    }
</style>

</head>
<body>

<div class="adlog-modal">
    <h2 class="adlog-title">Admin Login</h2>

    <form class="adlog-form" action="" method="POST">
        <div class="adlog-input-group">
            <input type="text" name="username" required placeholder=" ">
            <label>Username</label>
        </div>

        <div class="adlog-input-group">
            <input type="password" name="password" required placeholder=" ">
            <label>Password</label>
        </div>

        <button type="submit" class="adlog-btn">Login</button>
    </form>
</div>

</body>
</html>

