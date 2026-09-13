<?php
    require_once __DIR__ . '/session_security.php';
    AppSessionStart();
require 'db_connection.php';
require_once __DIR__ . '/activity_logger.php';
require_once __DIR__ . '/additional_fees_helper.php';
require_once __DIR__ . '/admin_auth_helper.php';
require_once __DIR__ . '/input_validation.php';
header('Content-Type: application/json');

// Admin check
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

if($_SERVER['REQUEST_METHOD'] === 'POST'){
    try {
        $boat_day = ItourValidationMoney($_POST['boat_day'] ?? null, 'Boat day-tour price');
        $boat_overnight = ItourValidationMoney($_POST['boat_overnight'] ?? null, 'Boat overnight price');
        $tourguide_day = ItourValidationMoney($_POST['tourguide_day'] ?? null, 'Tour-guide day-tour price');
        $tourguide_overnight = ItourValidationMoney($_POST['tourguide_overnight'] ?? null, 'Tour-guide overnight price');
        if (!is_array($_POST['additional_fees'] ?? null)) {
            throw new InvalidArgumentException('Additional fees must be submitted as a list.');
        }
        $submittedAdditionalFees = $_POST['additional_fees'];
        $allowedFees = additionalFeeDefinitions();
        if (array_diff_key($submittedAdditionalFees, $allowedFees)) {
            throw new InvalidArgumentException('An unknown additional fee was submitted.');
        }
        $validatedFees = [];
        foreach ($submittedAdditionalFees as $feeCode => $rawAmount) {
            $validatedFees[$feeCode] = ItourValidationMoney($rawAmount, (string)($allowedFees[$feeCode]['label'] ?? $feeCode));
        }

        $pdo->beginTransaction();
        $stmt = $pdo->prepare("UPDATE service_prices SET day_tour_price=?, overnight_price=?, updated_at=NOW() WHERE service_type=?");

        $stmt->execute([$boat_day, $boat_overnight, 'boat']);
        $stmt->execute([$tourguide_day, $tourguide_overnight, 'tourguide']);

        if (!additionalFeeTableExists($pdo)) {
            throw new RuntimeException('Additional fee settings are not installed.');
        }
        $feeUpdate = $pdo->prepare("UPDATE additional_fees SET amount = ?, updated_at = NOW() WHERE fee_code = ?");
        foreach ($validatedFees as $feeCode => $amount) {
            $feeUpdate->execute([$amount, $feeCode]);
        }
        logActivity(
            $pdo, 'Admin', (int)($_SESSION['admin_id'] ?? 0),
            (string)($_SESSION['admin_name'] ?? 'Administrator'),
            'Service Prices Updated',
            'Updated boat, tour guide, entrance, docking, environmental, and equipment fees.',
            'Service Prices'
        );
        $pdo->commit();

        echo json_encode(['success'=>true,'message'=>'Prices updated successfully.']);
    } catch(Throwable $e){
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ($e instanceof InvalidArgumentException) http_response_code(422);
        echo json_encode(['success'=>false,'message'=>'Error updating prices: '.$e->getMessage()]);
    }
}
