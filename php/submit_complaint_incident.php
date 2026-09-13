<?php
    require_once __DIR__ . '/session_security.php';
    AppSessionStart();

require_once __DIR__ . '/db_connection.php';
require_once __DIR__ . '/complaints_incidents_helper.php';
require_once __DIR__ . '/activity_logger.php';
require_once __DIR__ . '/complaint_evidence_storage.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function complaintResponse(int $status, bool $success, string $message, array $extra = []): void
{
    http_response_code($status);
    echo json_encode(array_merge(['success' => $success, 'message' => $message], $extra));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    complaintResponse(405, false, 'This request method is not allowed.');
}

$touristId = (int)($_SESSION['tourist_id'] ?? 0);
if ($touristId <= 0) {
    complaintResponse(401, false, 'Please log in before submitting a complaint or incident report.');
}

$touristStatement = $pdo->prepare('SELECT tourist_id, status FROM tourist WHERE tourist_id = ? LIMIT 1');
$touristStatement->execute([$touristId]);
$touristAccount = $touristStatement->fetch(PDO::FETCH_ASSOC);
if (!$touristAccount) {
    unset($_SESSION['tourist_id']);
    complaintResponse(401, false, 'Your login session is no longer valid. Please log in again.');
}
if (strtolower((string)($touristAccount['status'] ?? 'active')) === 'banned') {
    complaintResponse(403, false, 'This account cannot submit reports. Please contact the Tourism Office.');
}

$csrf = (string)($_POST['csrf_token'] ?? '');
if ($csrf === '' || !hash_equals(complaintCsrfToken(), $csrf)) {
    complaintResponse(403, false, 'Your form session expired. Refresh the page and try again.');
}

function complaintText(string $key, int $maxLength): string
{
    $value = trim((string)($_POST[$key] ?? ''));
    return mb_substr($value, 0, $maxLength);
}

$reportType = complaintText('report_type', 20);
$category = complaintText('category', 80);
$subject = complaintText('subject', 180);
$incidentAtRaw = complaintText('incident_at', 30);
$location = complaintText('location', 220);
$description = complaintText('description', 5000);
$peopleInvolved = complaintText('people_involved', 500);
$immediateAction = complaintText('immediate_action', 2000);
$preferredContact = complaintText('preferred_contact', 20);
$consent = (string)($_POST['accuracy_consent'] ?? '');

$allowedTypes = ['complaint', 'incident'];
$allowedCategories = [
    'tour-service', 'accommodation', 'transportation', 'safety-security',
    'environmental', 'staff-conduct', 'payment-booking', 'other'
];
$allowedContacts = ['email', 'phone', 'either'];

if (!in_array($reportType, $allowedTypes, true)) {
    complaintResponse(422, false, 'Choose whether you are reporting a complaint or an incident.');
}
if (!in_array($category, $allowedCategories, true)) {
    complaintResponse(422, false, 'Choose a valid report category.');
}
if (mb_strlen($subject) < 5) {
    complaintResponse(422, false, 'Enter a clear subject with at least 5 characters.');
}
if ($incidentAtRaw === '') {
    complaintResponse(422, false, 'Enter the date and time of the event.');
}
if (mb_strlen($location) < 3) {
    complaintResponse(422, false, 'Enter where the event happened.');
}
if (mb_strlen($description) < 30) {
    complaintResponse(422, false, 'Describe what happened using at least 30 characters.');
}
if (!in_array($preferredContact, $allowedContacts, true)) {
    complaintResponse(422, false, 'Choose how you prefer to be contacted.');
}
if ($consent !== '1') {
    complaintResponse(422, false, 'Confirm that the information you provided is accurate.');
}

$timezone = new DateTimeZone('Asia/Manila');
$incidentAt = DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $incidentAtRaw, $timezone);
$dateErrors = DateTimeImmutable::getLastErrors();
if (!$incidentAt || ($dateErrors !== false && ($dateErrors['warning_count'] > 0 || $dateErrors['error_count'] > 0))) {
    complaintResponse(422, false, 'Enter a valid event date and time.');
}
if ($incidentAt > new DateTimeImmutable('+10 minutes', $timezone)) {
    complaintResponse(422, false, 'The event date and time cannot be in the future.');
}

$savedAbsolutePaths = [];
$savedRelativePaths = [];

try {
    $files = $_FILES['evidence'] ?? null;
    if ($files && is_array($files['name'] ?? null)) {
        $fileCount = count($files['name']);
        if ($fileCount > 3) {
            complaintResponse(422, false, 'You can attach up to 3 images.');
        }

        $uploadDirectory = ItourEnsureComplaintEvidenceRoot();

        $allowedMimes = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
        ];
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $validatedUploads = [];

        for ($index = 0; $index < $fileCount; $index++) {
            $error = (int)($files['error'][$index] ?? UPLOAD_ERR_NO_FILE);
            if ($error === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            if ($error !== UPLOAD_ERR_OK) {
                complaintResponse(422, false, 'One of the selected images could not be uploaded.');
            }

            $size = (int)($files['size'][$index] ?? 0);
            $temporaryPath = (string)($files['tmp_name'][$index] ?? '');
            if ($size <= 0 || $size > 5 * 1024 * 1024) {
                complaintResponse(422, false, 'Each image must be no larger than 5 MB.');
            }

            $mime = $finfo->file($temporaryPath);
            if (!isset($allowedMimes[$mime]) || @getimagesize($temporaryPath) === false) {
                complaintResponse(422, false, 'Only genuine JPG, PNG, and WebP images are accepted.');
            }

            $validatedUploads[] = [
                'temporary_path' => $temporaryPath,
                'extension' => $allowedMimes[$mime],
            ];
        }

        foreach ($validatedUploads as $upload) {
            $filename = 'report_' . $touristId . '_' . bin2hex(random_bytes(12)) . '.' . $upload['extension'];
            $absolutePath = $uploadDirectory . DIRECTORY_SEPARATOR . $filename;
            if (!move_uploaded_file($upload['temporary_path'], $absolutePath)) {
                throw new RuntimeException('An evidence image could not be saved.');
            }

            $savedAbsolutePaths[] = $absolutePath;
            $savedRelativePaths[] = ItourComplaintEvidenceReference($filename);
        }
    }

    ensureComplaintsIncidentsTable($pdo);
    $reference = 'CIR-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(4)));

    $statement = $pdo->prepare(
        'INSERT INTO complaints_incidents
        (reference_number, tourist_id, report_type, category, subject, incident_at, location,
         description, people_involved, immediate_action, preferred_contact, evidence_paths, status)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, \'submitted\')'
    );
    $statement->execute([
        $reference,
        $touristId,
        $reportType,
        $category,
        $subject,
        $incidentAt->format('Y-m-d H:i:s'),
        $location,
        $description,
        $peopleInvolved !== '' ? $peopleInvolved : null,
        $immediateAction !== '' ? $immediateAction : null,
        $preferredContact,
        $savedRelativePaths ? json_encode($savedRelativePaths, JSON_UNESCAPED_SLASHES) : null,
    ]);

    $reportId = (int)$pdo->lastInsertId();
    logActivity(
        $pdo,
        'Tourist',
        $touristId,
        (string)($_SESSION['full_name'] ?? $_SESSION['tourist_email'] ?? 'Tourist'),
        'Complaint or Incident Submitted',
        'Submitted report ' . $reference . '.',
        'Complaints & Incidents',
        $reportId
    );

    complaintResponse(201, true, 'Your report was submitted successfully.', ['reference' => $reference]);
} catch (Throwable $error) {
    foreach ($savedAbsolutePaths as $savedPath) {
        if (is_file($savedPath)) {
            @unlink($savedPath);
        }
    }
    error_log('Complaint submission failed: ' . $error->getMessage());
    complaintResponse(500, false, 'We could not submit your report right now. Please try again.');
}
