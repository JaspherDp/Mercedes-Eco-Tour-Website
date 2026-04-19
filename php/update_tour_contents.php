<?php
session_start();
require 'db_connection.php';

// ✅ Session Authentication Check
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    echo json_encode(['success' => false, 'message' => 'Session expired! Please login again.']);
    exit();
}

// Ensure upload directory exists
$uploadDir = 'upload/';
if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);

$response = ['success' => false, 'message' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $package_id = !empty($_POST['package_id']) ? intval($_POST['package_id']) : null;
        $package_title = $_POST['package_title'] ?? '';
        $price = $_POST['price'] ?? 0;
        $operator_id = $_POST['operator_id'] ?? null;
        $package_type  = $_POST['package_type'] ?? null;
        $package_range = $_POST['package_range'] ?? null;


        // Handle package images
        $images = ['package_image','package_image2','package_image3','package_image4'];
        $imageUpdates = [];
        foreach ($images as $imgKey) {
            if (isset($_FILES[$imgKey]) && $_FILES[$imgKey]['size'] > 0) {
                $ext = pathinfo($_FILES[$imgKey]['name'], PATHINFO_EXTENSION);
                $filename = uniqid($imgKey.'_').'.'.$ext;
                $targetFile = $uploadDir.$filename;
                if (move_uploaded_file($_FILES[$imgKey]['tmp_name'], $targetFile)) {
                    $imageUpdates[$imgKey] = $targetFile;
                }
            }
        }

        // Handle general images (location_image / route_image)
        $generalImages = ['location_image','route_image'];
        $generalUpdate = [];
        foreach ($generalImages as $field) {
            if (isset($_FILES[$field]) && $_FILES[$field]['size'] > 0) {
                $ext = pathinfo($_FILES[$field]['name'], PATHINFO_EXTENSION) ?: 'png';
                $filename = uniqid($field.'_').'.'.$ext;
                $targetFile = $uploadDir.$filename;
                if (move_uploaded_file($_FILES[$field]['tmp_name'], $targetFile)) {
                    $generalUpdate[$field] = $targetFile;
                }
            }
        }

        if ($package_id) {
            // UPDATE existing package
            $sql = "UPDATE tour_packages 
                    SET package_title=?, price=?, operator_id=?, package_type=?, package_range=?";
            $params = [$package_title, $price, $operator_id, $package_type, $package_range];


            foreach ($imageUpdates as $col => $path) {
                $sql .= ", $col=?";
                $params[] = $path;
            }

            $sql .= " WHERE package_id=?";
            $params[] = $package_id;

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
        } else {
            // INSERT new package
            $columns = ['package_title','price','operator_id','package_type','package_range'];
            $placeholders = ['?','?','?','?','?'];
            $params = [$package_title, $price, $operator_id, $package_type, $package_range];


            foreach ($imageUpdates as $col => $path) {
                $columns[] = $col;
                $placeholders[] = '?';
                $params[] = $path;
            }

            $sql = "INSERT INTO tour_packages (".implode(',',$columns).") VALUES (".implode(',',$placeholders).")";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $package_id = $pdo->lastInsertId();
        }

        // Update general images in itinerary
        if (!empty($generalUpdate)) {
            $setParts = [];
            $params = [];
            foreach ($generalUpdate as $col => $path) {
                $setParts[] = "$col=?";
                $params[] = $path;
            }
            $params[] = $package_id;
            $sql = "UPDATE package_itinerary SET ".implode(', ',$setParts)." WHERE package_id=?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
        }

        // Handle Itinerary Steps
        if (isset($_POST['step_title']) && is_array($_POST['step_title'])) {
            $itinerary_ids = $_POST['itinerary_id'] ?? [];
            $display_orders = $_POST['display_order'] ?? [];

            foreach ($_POST['step_title'] as $i => $stepTitle) {
                $startTime = $_POST['start_time'][$i] ?? null;
                $endTime = $_POST['end_time'][$i] ?? null;
                $desc = $_POST['description'][$i] ?? null;
                $itineraryId = $itinerary_ids[$i] ?? null;
                $displayOrder = isset($display_orders[$i]) && $display_orders[$i] !== '' 
                    ? intval($display_orders[$i]) 
                    : ($i + 1);

                if ($itineraryId) {
                    $stmt = $pdo->prepare("
                        UPDATE package_itinerary
                        SET step_title=?, start_time=?, end_time=?, description=?, display_order=?
                        WHERE itinerary_id=? AND package_id=?
                    ");
                    $stmt->execute([$stepTitle,$startTime,$endTime,$desc,$displayOrder,$itineraryId,$package_id]);
                } else {
                    $stmt = $pdo->prepare("
                        INSERT INTO package_itinerary
                        (package_id, step_title, start_time, end_time, description, display_order)
                        VALUES (?,?,?,?,?,?)
                    ");
                    $stmt->execute([$package_id, $stepTitle, $startTime, $endTime, $desc, $displayOrder]);
                }
            }
        }

        $response['success'] = true;
        $response['message'] = 'Package saved successfully!';

    } catch (Exception $e) {
        $response['success'] = false;
        $response['message'] = $e->getMessage();
    }

    header('Content-Type: application/json');
    echo json_encode($response);
    exit();
} else {
    echo json_encode(['success'=>false,'message'=>'Invalid request.']);
    exit();
}
