<?php

require __DIR__ . '/db_connection.php';
require_once __DIR__ . '/tour_resource_availability_helper.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$type = strtolower(trim((string)($_GET['type'] ?? '')));
$resourceId = filter_var($_GET['resource_id'] ?? null, FILTER_VALIDATE_INT);
$packageId = filter_var($_GET['package_id'] ?? null, FILTER_VALIDATE_INT);
$packageName = trim((string)($_GET['package_name'] ?? ''));
$operatorId = filter_var($_GET['operator_id'] ?? null, FILTER_VALIDATE_INT);
$requestedGuests = max(1, min(500, (int)($_GET['guests'] ?? 1)));
$from = tourResourceDate((string)($_GET['from'] ?? date('Y-m-d')));
$to = tourResourceDate((string)($_GET['to'] ?? date('Y-m-d', strtotime('+2 years'))));

$validResource = $type === 'package' ? $packageName !== '' : (tourResourceMeta($type) && $resourceId);
if (!$validResource || $from === '' || $to === '' || $to < $from) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Invalid availability request.']);
    exit;
}

try {
    echo json_encode([
        'success' => true,
        'unavailable_dates' => $type === 'package'
            ? tourPackageUnavailableDates($pdo, $packageName, $from, $to, $operatorId ? (int)$operatorId : null, $requestedGuests, $packageId ? (int)$packageId : null)
            : tourResourceUnavailableDates($pdo, $type, (int)$resourceId, $from, $to),
    ], JSON_UNESCAPED_SLASHES);
} catch (Throwable $error) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Availability could not be loaded.']);
}
