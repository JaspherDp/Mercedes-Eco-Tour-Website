<?php
declare(strict_types=1);

require_once __DIR__ . '/session_security.php';

function AdminNormalizeReturnTo(?string $value): string
{
    $candidate = trim((string)$value);
    if ($candidate === '' || preg_match('/[\x00-\x1F\x7F]/', $candidate)) {
        return 'adhomepage.php';
    }

    $parts = parse_url($candidate);
    if (!is_array($parts) || isset($parts['scheme']) || isset($parts['host'])) {
        return 'adhomepage.php';
    }

    $page = basename(str_replace('\\', '/', (string)($parts['path'] ?? '')));
    $allowedPages = [
        'adhomepage.php',
        'adbookings.php',
        'adtourists.php',
        'adoperator.php',
        'adtourpackages.php',
        'adboats.php',
        'adtourguides.php',
        'adhotelresorts.php',
        'adfeatured.php',
        'adabout.php',
        'adactivitylog.php',
        'adpaymenttransactions.php',
        'adearningsdisbursements.php',
        'adreportsanalytics.php',
        'adreviewsfeedback.php',
        'adsystemsettings.php',
        'adadministratorprofile.php',
        'adcomplaintsincidents.php',
        'addestination.php',
        'admin-placeholder.php',
        'admin-phone-setup.php',
        'admin-payment-handoff.php',
    ];
    if (!in_array($page, $allowedPages, true)) {
        return 'adhomepage.php';
    }

    $query = trim((string)($parts['query'] ?? ''));
    if ($page === 'admin-payment-handoff.php'
        && preg_match('/^token=[a-f0-9]{64}$/', $query) !== 1) {
        return 'adhomepage.php';
    }

    return $page . ($query !== '' ? '?' . $query : '');
}

function AdminProjectWebPath(): string
{
    $scriptPath = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
    $directory = rtrim(str_replace('\\', '/', dirname($scriptPath)), '/');
    if (in_array(strtolower(basename($directory)), ['admin', 'php'], true)) {
        $directory = rtrim(str_replace('\\', '/', dirname($directory)), '/');
    }
    return $directory === '.' ? '' : str_replace(' ', '%20', $directory);
}

function AdminLoginUrl(?string $returnTo = null): string
{
    if ($returnTo === null) {
        if (AdminRequestExpectsJson()) {
            $returnTo = trim((string)($_SERVER['HTTP_X_ADMIN_RETURN_TO'] ?? ''));
            if ($returnTo === '') {
                $returnTo = 'adhomepage.php';
            }
        } else {
            $returnTo = (string)($_SERVER['REQUEST_URI'] ?? $_SERVER['SCRIPT_NAME'] ?? '');
        }
    }
    $safeReturnTo = AdminNormalizeReturnTo($returnTo);
    return AdminProjectWebPath() . '/php/admin_login.php?return_to=' . rawurlencode($safeReturnTo);
}

function AdminRequestExpectsJson(): bool
{
    return strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest'
        || str_contains(strtolower((string)($_SERVER['HTTP_ACCEPT'] ?? '')), 'application/json');
}

function AdminRedirectToLogin(?string $returnTo = null): never
{
    $loginUrl = AdminLoginUrl($returnTo);
    if (AdminRequestExpectsJson()) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(401);
        echo json_encode([
            'success' => false,
            'code' => 'SESSION_EXPIRED',
            'message' => 'Your administrator session expired. Please log in again.',
            'login_url' => $loginUrl,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    header('Location: ' . $loginUrl);
    exit;
}

function AdminValidateSession(PDO $pdo): bool
{
    $adminId = (int)($_SESSION['admin_id'] ?? 0);
    if (($_SESSION['admin_logged_in'] ?? false) !== true
        || $adminId < 1
        || !AppRoleSessionIsActive('admin', $pdo)) {
        AppClearRoleAuthentication('admin');
        return false;
    }

    $stmt = $pdo->prepare('SELECT admin_id FROM admin_users WHERE admin_id = ? LIMIT 1');
    $stmt->execute([$adminId]);
    if (!$stmt->fetchColumn()) {
        AppClearRoleAuthentication('admin');
        session_regenerate_id(true);
        return false;
    }
    return true;
}

function AdminRequireLogin(?PDO $pdo = null): void
{
    if (!$pdo) {
        $pdo = $GLOBALS['pdo'] ?? null;
    }
    if ($pdo instanceof PDO && AdminValidateSession($pdo)) {
        return;
    }

    $hasAdminCookie = !empty($_COOKIE['admin_seen']);
    $_SESSION['alert'] = [
        'type' => 'error',
        'title' => $hasAdminCookie ? 'Session Expired' : 'Access Denied',
        'message' => $hasAdminCookie
            ? 'Your session expired. Please log in again.'
            : 'Please log in before accessing the administrator portal.',
    ];
    AdminRedirectToLogin();
}
