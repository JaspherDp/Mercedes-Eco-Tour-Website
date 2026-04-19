<?php
session_start();
require 'db_connection.php';

header('Content-Type: application/json');

// ✅ Authentication
if(!isset($_SESSION['operator_id'])){
    echo json_encode(['success'=>false,'message'=>'Unauthorized']);
    exit;
}

$operator_id = $_SESSION['operator_id'];

/* =====================================================
   HELPER FUNCTION: Save Base64 Image
===================================================== */
function saveBase64Image($data){
    if(!$data || strpos($data,'data:image') !== 0) return null;
    [$meta,$content] = explode(',', $data);
    $ext = strpos($meta,'png') !== false ? 'png' : 'jpg';
    $filename = 'upload/'.uniqid('pkg_').'.'.$ext;
    $path = __DIR__.'/../'.$filename;
    file_put_contents($path, base64_decode($content));
    return $filename;
}

/* =====================================================
   COLLECT PACKAGE DATA
===================================================== */
$package_id = (int)($_POST['package_id'] ?? 0);
$title      = trim($_POST['package_title'] ?? '');
$price      = (float)($_POST['price'] ?? 0);
$type       = $_POST['package_type'] ?? '';
$range      = $_POST['package_range'] ?? '';

$img1 = saveBase64Image($_POST['package_image_data'] ?? '');
$img2 = saveBase64Image($_POST['package_image2_data'] ?? '');
$img3 = saveBase64Image($_POST['package_image3_data'] ?? '');
$img4 = saveBase64Image($_POST['package_image4_data'] ?? '');
$location_img = saveBase64Image($_POST['location_image_data'] ?? '');
$route_img    = saveBase64Image($_POST['route_image_data'] ?? '');

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
$ids    = $_POST['itinerary_id'] ?? [];
$titles = $_POST['step_title'] ?? [];
$starts = $_POST['start_time'] ?? [];
$ends   = $_POST['end_time'] ?? [];
$descs  = $_POST['description'] ?? [];

// Fetch existing itinerary IDs
$stmt = $pdo->prepare("SELECT itinerary_id FROM package_itinerary WHERE package_id=?");
$stmt->execute([$package_id]);
$existingIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

$keptIds = [];

try {
    $pdo->beginTransaction();

    foreach($titles as $i => $step_title){
        if(trim($step_title) === '') continue;

        $display_order = $i + 1;
        $itinerary_id  = (int)($ids[$i] ?? 0);

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
                $starts[$i] ?? null,
                $ends[$i] ?? null,
                $descs[$i] ?? '',
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
                $starts[$i] ?? null,
                $ends[$i] ?? null,
                $descs[$i] ?? '',
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
    echo json_encode(['success'=>true]);

} catch(Exception $e){
    $pdo->rollBack();
    echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
}
