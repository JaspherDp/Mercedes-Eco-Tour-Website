<?php

declare(strict_types=1);

require_once __DIR__ . '/session_security.php';

function operatorPortalBaseUrl(): string
{
    $documentRoot = realpath((string)($_SERVER['DOCUMENT_ROOT'] ?? ''));
    $projectRoot = realpath(__DIR__ . '/..');

    if ($documentRoot !== false && $projectRoot !== false) {
        $documentRoot = rtrim(str_replace('\\', '/', $documentRoot), '/');
        $projectRoot = rtrim(str_replace('\\', '/', $projectRoot), '/');
        $prefix = $documentRoot . '/';
        if (strcasecmp($projectRoot, $documentRoot) === 0) return '';
        if (strncasecmp($projectRoot, $prefix, strlen($prefix)) === 0) {
            $relative = substr($projectRoot, strlen($prefix));
            $segments = array_filter(explode('/', $relative), static fn(string $segment): bool => $segment !== '');
            return '/' . implode('/', array_map('rawurlencode', $segments));
        }
    }

    $script = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
    if (preg_match('~^(.*?)/(?:operator|php)(?:/|$)~i', $script, $match)) {
        return rtrim((string)$match[1], '/');
    }
    return '';
}

function operatorLoginUrl(): string
{
    return operatorPortalBaseUrl() . '/php/operator_login.php';
}

function OperatorValidateSession(PDO $pdo): array|false
{
    $operatorId = (int)($_SESSION['operator_id'] ?? 0);
    if (($_SESSION['operator_logged_in'] ?? false) !== true
        || $operatorId < 1
        || !AppRoleSessionIsActive('operator', $pdo)) {
        AppClearRoleAuthentication('operator');
        return false;
    }

    $stmt = $pdo->prepare('SELECT operator_id, fullname, status FROM operators WHERE operator_id = ? LIMIT 1');
    $stmt->execute([$operatorId]);
    $operator = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$operator || strtolower((string)$operator['status']) !== 'active') {
        AppClearRoleAuthentication('operator');
        session_regenerate_id(true);
        return false;
    }
    $_SESSION['operator_name'] = (string)$operator['fullname'];
    return $operator;
}

function OperatorRequireLogin(PDO $pdo, string $mode = 'redirect'): array
{
    $operator = OperatorValidateSession($pdo);
    if (is_array($operator)) {
        return $operator;
    }
    if ($mode === 'json') {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(401);
        echo json_encode(['success' => false, 'code' => 'SESSION_EXPIRED', 'message' => 'Your operator session expired. Please log in again.']);
        exit;
    }
    $_SESSION['alert'] = [
        'type' => 'error',
        'title' => 'Session Expired',
        'message' => 'Your operator session expired. Please log in again.',
    ];
    header('Location: ' . operatorLoginUrl());
    exit;
}
