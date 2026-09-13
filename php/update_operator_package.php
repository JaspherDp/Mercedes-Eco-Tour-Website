<?php
require_once __DIR__ . '/session_security.php';
AppSessionStart();
require 'db_connection.php';
require_once __DIR__ . '/activity_logger.php';
require_once __DIR__ . '/operator_auth_helper.php';
require_once __DIR__ . '/input_validation.php';
require_once __DIR__ . '/secure_upload_helper.php';

header('Content-Type: application/json');

// ✅ Authentication
$operatorAccount = OperatorRequireLogin($pdo, 'json');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}
if (!AppVerifyCsrf('operator', 'package_management', $_POST['csrf_token'] ?? null)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Your session expired. Refresh the page and try again.']);
    exit;
}

$operator_id = $_SESSION['operator_id'];

/* =====================================================
   HELPER FUNCTION: Save Base64 Image
===================================================== */
function validateBase64Image($data): ?array {
    if ($data === '' || $data === null) return null;
    if (!is_string($data) || strlen($data) > 8 * 1024 * 1024
        || !preg_match('#^data:image/(png|jpeg);base64,([A-Za-z0-9+/]*={0,2})$#D', $data, $matches)) {
        throw new InvalidArgumentException('An uploaded package image is invalid or too large.');
    }
    $content = $matches[2];
    $decoded = base64_decode($content, true);
    if ($decoded === false) throw new InvalidArgumentException('An uploaded package image is invalid.');
    $validated = ItourSecureValidateImageBytes($decoded, 5 * 1024 * 1024);
    $declaredMime = $matches[1] === 'png' ? 'image/png' : 'image/jpeg';
    if ((string)$validated['mime'] !== $declaredMime) {
        throw new InvalidArgumentException('The package image type does not match its contents.');
    }
    return $validated;
}

function saveBase64Image(?array $image){
    if ($image === null) return null;
    $uploadDirectory = ItourEnsureProjectDirectory('php/upload');
    $basename = ItourSecureRandomFilename('pkg', (string)$image['extension']);
    $path = $uploadDirectory . DIRECTORY_SEPARATOR . $basename;
    ItourSecureReencodeImageBytes($image, $path);
    $GLOBALS['itourNewOperatorPackageUploads'][] = $path;
    return 'php/upload/' . $basename;
}

/* =====================================================
   COLLECT PACKAGE DATA
===================================================== */
try {
$GLOBALS['itourNewOperatorPackageUploads'] = [];
$operatorPackageCommitted = false;
$rawPackageId = $_POST['package_id'] ?? '';
$package_id = ($rawPackageId === '' || $rawPackageId === '0' || $rawPackageId === 0)
    ? 0 : ItourValidationInt($rawPackageId, 'Package ID', 1, PHP_INT_MAX);
$isPackageUpdate = $package_id > 0;
$operator_id = (int)$operator_id;

// Reject a foreign parent package before decoding or writing any upload and
// before reading or mutating any of its itinerary rows.
if ($isPackageUpdate) {
    $ownershipStmt = $pdo->prepare('SELECT 1 FROM tour_packages WHERE package_id = ? AND operator_id = ? LIMIT 1');
    $ownershipStmt->execute([$package_id, $operator_id]);
    if (!$ownershipStmt->fetchColumn()) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Package not found or access denied.']);
        exit;
    }
}

$validatedPackage = ItourValidationPackageRequest($_POST);
$title = $validatedPackage['title'];
$price = $validatedPackage['price'];
$type = $validatedPackage['type'];
$range = $validatedPackage['range'];
$steps = $validatedPackage['steps'];
$existingIds = [];
if ($isPackageUpdate) {
    $existingStmt = $pdo->prepare('SELECT itinerary_id FROM package_itinerary WHERE package_id = ?');
    $existingStmt->execute([$package_id]);
    $existingIds = array_map('intval', $existingStmt->fetchAll(PDO::FETCH_COLUMN));
}
foreach ($steps as $step) {
    if ($step['id'] > 0 && (!$isPackageUpdate || !in_array($step['id'], $existingIds, true))) {
        throw new InvalidArgumentException('An itinerary step does not belong to this package.');
    }
}

$validatedImages = [];
foreach (['package_image_data', 'package_image2_data', 'package_image3_data', 'package_image4_data',
          'location_image_data', 'route_image_data'] as $imageField) {
    $validatedImages[$imageField] = validateBase64Image($_POST[$imageField] ?? '');
}
$img1 = saveBase64Image($validatedImages['package_image_data']);
$img2 = saveBase64Image($validatedImages['package_image2_data']);
$img3 = saveBase64Image($validatedImages['package_image3_data']);
$img4 = saveBase64Image($validatedImages['package_image4_data']);
$location_img = saveBase64Image($validatedImages['location_image_data']);
$route_img    = saveBase64Image($validatedImages['route_image_data']);

$pdo->beginTransaction();
/* =====================================================
   ADD OR UPDATE PACKAGE
===================================================== */
if($package_id > 0){
    // UPDATE
    $stmt = $pdo->prepare("
        UPDATE tour_packages SET
            package_title=?,
            price=?,
            package_type=?,
            package_range=?,
            package_image=COALESCE(?,package_image),
            package_image2=COALESCE(?,package_image2),
            package_image3=COALESCE(?,package_image3),
            package_image4=COALESCE(?,package_image4)
        WHERE package_id=? AND operator_id=?
    ");
    $stmt->execute([$title,$price,$type,$range,$img1,$img2,$img3,$img4,$package_id,$operator_id]);
} else {
    // INSERT
    $stmt = $pdo->prepare("
        INSERT INTO tour_packages
        (operator_id, package_title, price, package_type, package_range,
         package_image, package_image2, package_image3, package_image4)
        VALUES (?,?,?,?,?,?,?,?,?)
    ");
    $stmt->execute([$operator_id,$title,$price,$type,$range,$img1,$img2,$img3,$img4]);
    $package_id = $pdo->lastInsertId();
}

/* =====================================================
   ITINERARY: ADD / UPDATE / DELETE / REORDER
===================================================== */
$keptIds = [];

    foreach($steps as $step){
        $step_title = $step['title'];
        $display_order = $step['order'];
        $itinerary_id = $step['id'];

        if($itinerary_id > 0){
            // UPDATE existing step
            $stmt = $pdo->prepare("
                UPDATE package_itinerary SET
                    step_title=?, start_time=?, end_time=?, description=?,
                    display_order=?, location_image=COALESCE(?,location_image),
                    route_image=COALESCE(?,route_image)
                WHERE itinerary_id=? AND package_id=?
            ");
            $stmt->execute([
                $step_title,
                $step['start'],
                $step['end'],
                $step['description'],
                $display_order,
                $location_img,
                $route_img,
                $itinerary_id,
                $package_id
            ]);
            $keptIds[] = $itinerary_id;
        } else {
            // INSERT new step
            $stmt = $pdo->prepare("
                INSERT INTO package_itinerary
                (package_id, step_title, start_time, end_time, description,
                 location_image, route_image, display_order)
                VALUES (?,?,?,?,?,?,?,?)
            ");
            $stmt->execute([
                $package_id,
                $step_title,
                $step['start'],
                $step['end'],
                $step['description'],
                $location_img,
                $route_img,
                $display_order
            ]);
            $keptIds[] = $pdo->lastInsertId();
        }
    }

    // DELETE removed steps
    $toDelete = array_diff($existingIds, $keptIds);
    if($toDelete){
        $in = implode(',', array_fill(0,count($toDelete),'?'));
        $pdo->prepare("DELETE FROM package_itinerary WHERE itinerary_id IN ($in)")
            ->execute(array_values($toDelete));
    }

    $pdo->commit();
    $operatorPackageCommitted = true;
    logActivity(
        $pdo,
        'Tour Operator',
        (int)$operator_id,
        (string)($_SESSION['operator_name'] ?? 'Tour Operator'),
        $isPackageUpdate ? 'Tour Package Updated' : 'Tour Package Added',
        ($isPackageUpdate ? 'Updated' : 'Added') . ' tour package "' . $title . '".',
        'Tour Packages',
        (int)$package_id
    );
    echo json_encode(['success'=>true]);

} catch(Throwable $e){
    if ($pdo->inTransaction()) $pdo->rollBack();
    if (!$operatorPackageCommitted) {
        foreach (($GLOBALS['itourNewOperatorPackageUploads'] ?? []) as $newUpload) {
            if (is_string($newUpload) && is_file($newUpload)) @unlink($newUpload);
        }
    }
    http_response_code($e instanceof InvalidArgumentException ? 422 : 500);
    echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
}
