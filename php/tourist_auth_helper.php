<?php
declare(strict_types=1);

require_once __DIR__ . '/session_security.php';

function TouristValidateSession(PDO $pdo): array|false
{
    $touristId = (int)($_SESSION['tourist_id'] ?? 0);
    if ($touristId < 1 || !AppRoleSessionIsActive('tourist', $pdo)) {
        AppClearRoleAuthentication('tourist');
        return false;
    }

    $stmt = $pdo->prepare('SELECT * FROM tourist WHERE tourist_id = ? LIMIT 1');
    $stmt->execute([$touristId]);
    $tourist = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$tourist || strtolower((string)($tourist['status'] ?? 'active')) === 'banned') {
        AppClearRoleAuthentication('tourist');
        session_regenerate_id(true);
        return false;
    }

    return $tourist;
}

/** @return array<string, mixed> */
function TouristRequireLogin(
    PDO $pdo,
    string $mode = 'redirect',
    string $loginUrl = '../homepage.php?open_login=1',
    ?string $returnTo = null
): array {
    $tourist = TouristValidateSession($pdo);
    if (is_array($tourist)) {
        return $tourist;
    }

    if ($returnTo !== null && $returnTo !== '') {
        $_SESSION['post_login_redirect'] = $returnTo;
    }
    if ($mode === 'json') {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(401);
        echo json_encode(['success' => false, 'code' => 'AUTH_REQUIRED', 'message' => 'Your login session is no longer valid. Please log in again.']);
        exit;
    }
    if ($mode === 'text') {
        http_response_code(401);
        echo 'Your login session is no longer valid. Please log in again.';
        exit;
    }

    header('Location: ' . $loginUrl);
    exit;
}

