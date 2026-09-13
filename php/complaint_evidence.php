<?php
declare(strict_types=1);

require_once __DIR__ . '/session_security.php';
AppSessionStart();
require_once __DIR__ . '/db_connection.php';
require_once __DIR__ . '/admin_auth_helper.php';
require_once __DIR__ . '/tourist_auth_helper.php';
require_once __DIR__ . '/complaint_evidence_storage.php';

header('Cache-Control: private, no-store, max-age=0');
header('X-Content-Type-Options: nosniff');

$adminAuthorized = AdminValidateSession($pdo);
$tourist = $adminAuthorized ? false : TouristValidateSession($pdo);
if (!$adminAuthorized && !is_array($tourist)) {
    http_response_code(401);
    exit('Authentication required.');
}

$reportId = filter_input(INPUT_GET, 'report_id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$fileIndex = filter_input(INPUT_GET, 'file', FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 2]]);
if ($reportId === false || $reportId === null || $fileIndex === false || $fileIndex === null) {
    http_response_code(404);
    exit('Evidence not found.');
}

$statement = $pdo->prepare('SELECT tourist_id, evidence_paths FROM complaints_incidents WHERE complaint_incident_id = ? LIMIT 1');
$statement->execute([(int)$reportId]);
$report = $statement->fetch(PDO::FETCH_ASSOC);
if (!$report || (!$adminAuthorized && (int)$report['tourist_id'] !== (int)$tourist['tourist_id'])) {
    http_response_code(404);
    exit('Evidence not found.');
}

$evidence = json_decode((string)($report['evidence_paths'] ?? ''), true);
$reference = is_array($evidence) ? ($evidence[(int)$fileIndex] ?? null) : null;
$path = is_string($reference) ? ItourResolveComplaintEvidence($reference) : null;
if ($path === null) {
    http_response_code(404);
    exit('Evidence not found.');
}

$mime = (new finfo(FILEINFO_MIME_TYPE))->file($path);
$allowed = ['image/jpeg', 'image/png', 'image/webp'];
if (!is_string($mime) || !in_array($mime, $allowed, true)) {
    http_response_code(404);
    exit('Evidence not found.');
}

header('Content-Type: ' . $mime);
header('Content-Disposition: inline; filename="evidence-' . (int)$reportId . '-' . ((int)$fileIndex + 1) . '"');
header('Content-Length: ' . (string)filesize($path));
readfile($path);
