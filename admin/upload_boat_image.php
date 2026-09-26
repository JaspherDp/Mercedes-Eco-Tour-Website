<?php
chdir(__DIR__ . '/..');

require_once __DIR__ . '/../php/session_security.php';
AppSessionStart();

require 'php/db_connection.php';
require_once __DIR__ . '/../php/admin_auth_helper.php';
require_once __DIR__ . '/../php/search_thumbnail_helper.php';
require_once __DIR__ . '/../php/secure_upload_helper.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

AdminRequireLogin();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$submittedCsrf = (string)($_POST['csrf_token'] ?? '');
$sessionCsrf = (string)($_SESSION['boat_upload_csrf'] ?? '');
if ($submittedCsrf === '' || $sessionCsrf === '' || !hash_equals($sessionCsrf, $submittedCsrf)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Invalid security token']);
    exit;
}

$uploadDir = ItourEnsureProjectDirectory('uploads');

try {

    $boat_id = trim((string)($_POST['boat_id'] ?? ''));
    $imgIndex = filter_var($_POST['imageIndex'] ?? null, FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1, 'max_range' => 5],
    ]);

    if (!preg_match('/^(?:[1-9][0-9]*|temp_[a-f0-9]{32})$/D', $boat_id)) {
        http_response_code(422);
        echo json_encode(['success' => false, 'error' => 'Invalid boat ID']);
        exit;
    }
    if ($imgIndex === false) {
        http_response_code(422);
        echo json_encode(['success' => false, 'error' => 'Invalid image index']);
        exit;
    }

    if (ctype_digit($boat_id)) {
        $boatStmt = $pdo->prepare('SELECT 1 FROM boats WHERE boat_id = ? LIMIT 1');
        $boatStmt->execute([(int)$boat_id]);
        if (!$boatStmt->fetchColumn()) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Boat not found']);
            exit;
        }
    } else {
        $temporaryToken = substr($boat_id, 5);
        $knownTokens = is_array($_SESSION['boat_upload_tokens'] ?? null) ? $_SESSION['boat_upload_tokens'] : [];
        $createdAt = $knownTokens[$temporaryToken] ?? null;
        if (!is_int($createdAt) || $createdAt < time() - 3600) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Temporary upload token expired']);
            exit;
        }
    }

    if (!isset($_FILES['file']) || !is_array($_FILES['file'])) throw new InvalidArgumentException('No file uploaded.');
    $validatedImage = ItourSecureValidateUploadedImage($_FILES['file'], 40 * 1024 * 1024, 40000000, 12000, 12000);

    $outputMime = (string)$validatedImage['mime'];
    $filename = "boat_{$boat_id}_img{$imgIndex}_" . bin2hex(random_bytes(8)) . '.' . $validatedImage['extension'];
    $path = $uploadDir . DIRECTORY_SEPARATOR . $filename;

    try {
        ItourSecureOptimizeUploadedImage($validatedImage, $path, 1920, $outputMime);
        ItourAssertPublicMediaFile($path);
    } catch (Throwable $exception) {
        if (is_file($path)) @unlink($path);
        throw $exception;
    }

    createSearchCardThumbnail($path, 'uploads/' . $filename);

    echo json_encode([
        'success' => true,
        'url' => 'uploads/' . $filename
    ]);
    exit;

} catch (Throwable $e) {
    http_response_code($e instanceof InvalidArgumentException ? 422 : 500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    exit;
}
