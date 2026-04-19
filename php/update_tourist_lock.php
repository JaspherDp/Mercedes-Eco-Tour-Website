<?php
session_start();
require 'db_connection.php';

header('Content-Type: application/json');

$data = json_decode(file_get_contents('php://input'), true);

if(!isset($data['booking_id'], $data['tourist_locked'])){
    echo json_encode(['success'=>false, 'msg'=>'Invalid request']);
    exit;
}

$booking_id = (int)$data['booking_id'];
$locked = $data['tourist_locked'] ? 1 : 0;

$stmt = $pdo->prepare("UPDATE bookings SET tourist_locked=:locked WHERE booking_id=:booking_id");
$success = $stmt->execute([':locked'=>$locked, ':booking_id'=>$booking_id]);

echo json_encode(['success'=>$success]);
