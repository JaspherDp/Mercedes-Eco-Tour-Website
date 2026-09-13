<?php

declare(strict_types=1);

require_once __DIR__ . '/session_security.php';
AppSessionStart();
require_once __DIR__ . '/db_connection.php';
require_once __DIR__ . '/tourist_auth_helper.php';
require_once __DIR__ . '/favorites_helper.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function favoriteJson(int $status, array $payload): never
{
    http_response_code($status);
    echo json_encode($payload);
    exit;
}

$tourist = TouristRequireLogin($pdo, 'json');
$touristId = (int)$tourist['tourist_id'];

try {
    ensureFavoritesTable($pdo);

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $type = strtolower(trim((string)($_GET['type'] ?? '')));
        $entityId = (int)($_GET['id'] ?? 0);
        favoriteJson(200, [
            'success' => true,
            'favorited' => isFavorite($pdo, $touristId, $type, $entityId),
        ]);
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        favoriteJson(405, ['success' => false, 'message' => 'Method not allowed.']);
    }

    $payload = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($payload)) {
        $payload = $_POST;
    }

    $csrf = (string)($payload['csrf_token'] ?? '');
    if ($csrf === '' || !hash_equals(favoriteCsrfToken(), $csrf)) {
        favoriteJson(403, ['success' => false, 'message' => 'Your session expired. Refresh the page and try again.']);
    }

    $type = strtolower(trim((string)($payload['type'] ?? '')));
    $entityId = (int)($payload['id'] ?? 0);
    $action = strtolower(trim((string)($payload['action'] ?? 'toggle')));

    if (!in_array($action, ['add', 'remove', 'toggle'], true)) {
        favoriteJson(422, ['success' => false, 'message' => 'Invalid favorite action.']);
    }

    if (!isset(favoriteTypes()[$type]) || !isValidFavoriteEntity($pdo, $type, $entityId)) {
        favoriteJson(422, ['success' => false, 'message' => 'This item is not available.']);
    }

    $currentlyFavorite = isFavorite($pdo, $touristId, $type, $entityId);
    $shouldFavorite = $action === 'add' || ($action === 'toggle' && !$currentlyFavorite);

    if ($shouldFavorite) {
        $stmt = $pdo->prepare("
            INSERT IGNORE INTO tourist_favorites (tourist_id, entity_type, entity_id)
            VALUES (?, ?, ?)
        ");
        $stmt->execute([$touristId, $type, $entityId]);
    } else {
        $stmt = $pdo->prepare("
            DELETE FROM tourist_favorites
            WHERE tourist_id = ? AND entity_type = ? AND entity_id = ?
        ");
        $stmt->execute([$touristId, $type, $entityId]);
    }

    favoriteJson(200, [
        'success' => true,
        'favorited' => $shouldFavorite,
        'message' => $shouldFavorite ? 'Added to your favorites.' : 'Removed from your favorites.',
    ]);
} catch (Throwable $error) {
    error_log('Favorites error: ' . $error->getMessage());
    favoriteJson(500, ['success' => false, 'message' => 'Favorites are temporarily unavailable.']);
}
