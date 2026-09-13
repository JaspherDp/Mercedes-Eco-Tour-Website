<?php
declare(strict_types=1);

    require_once __DIR__ . '/session_security.php';
    AppSessionStart();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, max-age=300');

require_once __DIR__ . '/../payments/PayMongoService.php';
require_once __DIR__ . '/db_connection.php';
require_once __DIR__ . '/tourist_auth_helper.php';
require_once __DIR__ . '/booking_cancellations_helper.php';
require_once __DIR__ . '/booking_refunds_helper.php';

$authenticatedTourist = TouristRequireLogin($pdo, 'json');
$touristId = (int)$authenticatedTourist['tourist_id'];

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $sessionCsrf = (string)($_SESSION['booking_cancellation_csrf'] ?? '');
        if ($sessionCsrf === '' || !hash_equals($sessionCsrf, (string)($_POST['csrf_token'] ?? ''))) {
            throw new RuntimeException('Your session expired. Refresh the page and try again.');
        }
        ensureBookingCancellationRequestsTable($pdo);
        $requestId = max(0, (int)($_POST['cancellation_request_id'] ?? 0));
        $institution = trim((string)($_POST['refund_institution'] ?? ''));
        $bic = strtoupper(trim((string)($_POST['refund_bic'] ?? '')));
        $accountName = trim((string)($_POST['refund_account_name'] ?? ''));
        $accountNumber = preg_replace('/\s+/', '', trim((string)($_POST['refund_account_number'] ?? ''))) ?: '';
        if ($requestId <= 0 || $institution === '' || $accountName === '' || !preg_match('/^[A-Z0-9]{8,20}$/', $bic)
            || mb_strlen($accountName) > 150 || !preg_match('/^[0-9A-Za-z+._-]{5,40}$/', $accountNumber)) {
            throw new RuntimeException('Complete the refund bank or e-wallet details correctly.');
        }
        $find = $pdo->prepare("SELECT refund_policy,refund_status FROM booking_cancellation_requests WHERE cancellation_request_id=? AND tourist_id=? AND request_status IN ('pending','approved') LIMIT 1");
        $find->execute([$requestId, $touristId]);
        $request = $find->fetch(PDO::FETCH_ASSOC);
        if (!$request || (string)$request['refund_policy'] !== 'partial_refund' || in_array(strtolower((string)$request['refund_status']), ['processing','completed','refunded'], true)) {
            throw new RuntimeException('This request no longer accepts refund destination changes.');
        }
        $update = $pdo->prepare('UPDATE booking_cancellation_requests SET refund_destination_institution=?,refund_destination_bic=?,refund_destination_account_name=?,refund_destination_account_cipher=?,refund_destination_last4=?,refund_destination_verified_at=NOW() WHERE cancellation_request_id=? AND tourist_id=?');
        $update->execute([$institution, $bic, $accountName, bookingRefundEncryptAccountNumber($accountNumber), substr($accountNumber, -4), $requestId, $touristId]);
        echo json_encode(['success' => true, 'message' => 'Your refund destination was securely saved.'], JSON_UNESCAPED_SLASHES);
        exit;
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
        exit;
    }
    $response = PayMongoService::fromEnvironment()->listReceivingInstitutions('instapay');
    $data = is_array($response['data'] ?? null) ? $response['data'] : [];
    $institutions = [];
    foreach ($data as $resource) {
        if (!is_array($resource)) continue;
        $attributes = is_array($resource['attributes'] ?? null) ? $resource['attributes'] : $resource;
        $name = trim((string)($attributes['name'] ?? $attributes['bank_name'] ?? $attributes['provider_name'] ?? ''));
        $bic = strtoupper(trim((string)($attributes['provider_code'] ?? $attributes['bic'] ?? $attributes['bank_code'] ?? '')));
        if ($name === '' || !preg_match('/^[A-Z0-9]{8,20}$/', $bic)) continue;
        $institutions[$bic] = ['name' => $name, 'bic' => $bic];
    }
    uasort($institutions, static fn(array $a, array $b): int => strcasecmp($a['name'], $b['name']));
    echo json_encode(['success' => true, 'institutions' => array_values($institutions)], JSON_UNESCAPED_SLASHES);
} catch (Throwable $error) {
    http_response_code(502);
    echo json_encode(['success' => false, 'message' => 'Refund institutions are temporarily unavailable. Please try again.']);
}
