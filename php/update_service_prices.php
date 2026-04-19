<?php
session_start();
require 'db_connection.php';
header('Content-Type: application/json');

// Admin check
if(!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true){
    echo json_encode(['success'=>false,'message'=>'Unauthorized']);
    exit;
}

if($_SERVER['REQUEST_METHOD'] === 'POST'){
    $boat_day = (float)($_POST['boat_day'] ?? 0);
    $boat_overnight = (float)($_POST['boat_overnight'] ?? 0);
    $tourguide_day = (float)($_POST['tourguide_day'] ?? 0);
    $tourguide_overnight = (float)($_POST['tourguide_overnight'] ?? 0);

    try {
        $stmt = $pdo->prepare("UPDATE service_prices SET day_tour_price=?, overnight_price=?, updated_at=NOW() WHERE service_type=?");

        $stmt->execute([$boat_day, $boat_overnight, 'boat']);
        $stmt->execute([$tourguide_day, $tourguide_overnight, 'tourguide']);

        echo json_encode(['success'=>true,'message'=>'Prices updated successfully.']);
    } catch(Exception $e){
        echo json_encode(['success'=>false,'message'=>'Error updating prices: '.$e->getMessage()]);
    }
}
