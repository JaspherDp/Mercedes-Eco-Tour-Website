<?php
    require_once __DIR__ . '/session_security.php';
    AppSessionStart();
require 'db_connection.php';
require_once __DIR__ . '/activity_logger.php';
require_once __DIR__ . '/admin_auth_helper.php';
require_once __DIR__ . '/input_validation.php';
require_once __DIR__ . '/project_path_helper.php';

// ✅ Session Authentication Check
AdminRequireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}
if (!AppVerifyCsrf('admin', 'catalog_content', $_POST['csrf_token'] ?? null)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Your session expired. Refresh the page and try again.']);
    exit;
}

// Ensure upload directory exists
$uploadDir = ItourEnsureProjectDirectory('php/upload');

$response = ['success' => false, 'message' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $rawPackageId = $_POST['package_id'] ?? '';
        $package_id = ($rawPackageId === '' || $rawPackageId === '0' || $rawPackageId === 0)
            ? null : ItourValidationInt($rawPackageId, 'Package ID', 1, PHP_INT_MAX);
        $wasPackageUpdate = $package_id !== null;
        $validatedPackage = ItourValidationPackageRequest($_POST);
        $package_title = $validatedPackage['title'];
        $price = $validatedPackage['price'];
        $package_type = $validatedPackage['type'];
        $package_range = $validatedPackage['range'];
        $steps = $validatedPackage['steps'];
        $operator_id = ItourValidationInt($_POST['operator_id'] ?? null, 'Tour operator', 1, PHP_INT_MAX);
        $operatorCheck = $pdo->prepare('SELECT 1 FROM operators WHERE operator_id = ? LIMIT 1');
        $operatorCheck->execute([$operator_id]);
        if (!$operatorCheck->fetchColumn()) throw new InvalidArgumentException('The selected tour operator does not exist.');
        $existingItineraryIds = [];
        if ($package_id !== null) {
            $packageCheck = $pdo->prepare('SELECT 1 FROM tour_packages WHERE package_id = ? LIMIT 1');
            $packageCheck->execute([$package_id]);
            if (!$packageCheck->fetchColumn()) throw new InvalidArgumentException('The selected package does not exist.');
            $itineraryCheck = $pdo->prepare('SELECT itinerary_id FROM package_itinerary WHERE package_id = ?');
            $itineraryCheck->execute([$package_id]);
            $existingItineraryIds = array_map('intval', $itineraryCheck->fetchAll(PDO::FETCH_COLUMN));
        }
        foreach ($steps as $step) {
            if ($step['id'] > 0 && ($package_id === null || !in_array($step['id'], $existingItineraryIds, true))) {
                throw new InvalidArgumentException('An itinerary step does not belong to this package.');
            }
        }

        $uploadFields = ['package_image','package_image2','package_image3','package_image4','location_image','route_image'];
        $allowedMimes = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
        $validatedUploads = [];
        foreach ($uploadFields as $field) {
            if (!isset($_FILES[$field]) || (int)($_FILES[$field]['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) continue;
            $file = $_FILES[$field];
            if (!is_array($file) || (int)($file['error'] ?? -1) !== UPLOAD_ERR_OK
                || (int)($file['size'] ?? 0) < 1 || (int)$file['size'] > 5 * 1024 * 1024
                || !is_uploaded_file((string)($file['tmp_name'] ?? ''))) {
                throw new InvalidArgumentException('A package image is invalid or exceeds 5 MB.');
            }
            $mime = (new finfo(FILEINFO_MIME_TYPE))->file((string)$file['tmp_name']);
            if (!isset($allowedMimes[$mime])) throw new InvalidArgumentException('Package images must be JPEG, PNG, or WebP files.');
            $validatedUploads[$field] = ['file' => $file, 'extension' => $allowedMimes[$mime]];
        }


        // Handle package images
        $images = ['package_image','package_image2','package_image3','package_image4'];
        $imageUpdates = [];
        foreach ($images as $imgKey) {
            if (isset($validatedUploads[$imgKey])) {
                $ext = $validatedUploads[$imgKey]['extension'];
                $filename = uniqid($imgKey.'_').'.'.$ext;
                $targetFile = $uploadDir . DIRECTORY_SEPARATOR . $filename;
                if (move_uploaded_file($validatedUploads[$imgKey]['file']['tmp_name'], $targetFile)) {
                    $imageUpdates[$imgKey] = 'php/upload/' . $filename;
                } else throw new RuntimeException('A package image could not be saved.');
            }
        }

        // Handle general images (location_image / route_image)
        $generalImages = ['location_image','route_image'];
        $generalUpdate = [];
        foreach ($generalImages as $field) {
            if (isset($validatedUploads[$field])) {
                $ext = $validatedUploads[$field]['extension'];
                $filename = uniqid($field.'_').'.'.$ext;
                $targetFile = $uploadDir . DIRECTORY_SEPARATOR . $filename;
                if (move_uploaded_file($validatedUploads[$field]['file']['tmp_name'], $targetFile)) {
                    $generalUpdate[$field] = 'php/upload/' . $filename;
                } else throw new RuntimeException('A package image could not be saved.');
            }
        }

        $pdo->beginTransaction();

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
        if ($steps) {
            foreach ($steps as $step) {
                $stepTitle = $step['title'];
                $startTime = $step['start'];
                $endTime = $step['end'];
                $desc = $step['description'];
                $itineraryId = $step['id'];
                $displayOrder = $step['order'];

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

        $pdo->commit();
        $response['success'] = true;
        $response['message'] = 'Package saved successfully!';
        logActivity(
            $pdo,
            'Admin',
            (int)($_SESSION['admin_id'] ?? 0),
            (string)($_SESSION['admin_name'] ?? 'Administrator'),
            $wasPackageUpdate ? 'Tour Package Updated' : 'Tour Package Added',
            ($wasPackageUpdate ? 'Updated' : 'Added') . ' tour package "' . $package_title . '".',
            'Tour Packages',
            (int)$package_id
        );

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ($e instanceof InvalidArgumentException) http_response_code(422);
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
