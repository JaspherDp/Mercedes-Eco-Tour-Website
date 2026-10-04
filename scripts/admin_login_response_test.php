<?php
declare(strict_types=1);

// Runs the actual login endpoint with isolated temporary sessions. Empty
// credentials stop before Turnstile verification, throttling or account writes.
// Requires the project's local database connection to be available.
$projectRoot = dirname(__DIR__);
$sessionDirectory = sys_get_temp_dir() . '/itour-admin-login-test-' . bin2hex(random_bytes(8));
if (!mkdir($sessionDirectory, 0700)) {
    throw new RuntimeException('Could not create the isolated session directory.');
}

function loginResponseTestRequest(string $method, bool $pendingAlert, bool $ajaxHeader, bool $jsonAccept = true): string
{
    global $projectRoot, $sessionDirectory;
    $server = [
        'REQUEST_METHOD' => $method,
        'SCRIPT_NAME' => '/php/admin_login.php',
        'HTTP_ACCEPT' => $method === 'POST' && $jsonAccept ? 'application/json' : 'text/html',
    ];
    if ($ajaxHeader) $server['HTTP_X_REQUESTED_WITH'] = 'XMLHttpRequest';
    $code = '<?php ';
    $code .= '$_SERVER = ' . var_export($server, true) . ';';
    $code .= 'ini_set("session.save_path", ' . var_export($sessionDirectory, true) . ');';
    $code .= 'require ' . var_export($projectRoot . '/php/session_security.php', true) . ';';
    $code .= 'AppSessionStart(); $_POST = ["username" => "", "password" => ""];';
    if ($pendingAlert) {
        $code .= '$_SESSION["alert"] = ["type" => "error", "title" => "Session Expired", "message" => "Your session expired. Please log in again."];';
    }
    $code .= 'require ' . var_export($projectRoot . '/php/admin_login.php', true) . ';';
    $process = proc_open(
        [PHP_BINARY, '-d', 'display_errors=stderr'],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        $projectRoot
    );
    if (!is_resource($process)) throw new RuntimeException('Could not run login endpoint.');
    fwrite($pipes[0], $code);
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]);
    $errors = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    if (proc_close($process) !== 0 || trim($errors) !== '') {
        throw new RuntimeException('Login endpoint failed: ' . $errors);
    }
    return $output;
}

try {
    foreach ([
        ['Expired-session AJAX login', true, true],
        ['Normal AJAX login', false, true],
        ['Expired-session JSON Accept login', true, false],
    ] as [$label, $pendingAlert, $ajaxHeader]) {
        $output = loginResponseTestRequest('POST', $pendingAlert, $ajaxHeader);
        try {
            $payload = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new RuntimeException($label . ': response contains HTML instead of pure JSON.', 0, $error);
        }
        if (($payload['success'] ?? null) !== false
            || ($payload['message'] ?? '') !== 'Username and password are required.') {
            throw new RuntimeException($label . ': incorrect validation response.');
        }
        echo 'PASS: ', $label, ' returns clean JSON.', PHP_EOL;
    }

    if (loginResponseTestRequest('POST', true, false, false) !== '') {
        throw new RuntimeException('Normal browser login POST rendered HTML before redirecting.');
    }
    echo 'PASS: Normal browser login POST redirects without premature HTML output.', PHP_EOL;

    $page = loginResponseTestRequest('GET', true, false);
    if (!str_contains($page, '<!-- SweetAlert2 -->')
        || !str_contains($page, 'Your session expired. Please log in again.')
        || !str_contains($page, '<h1 class="adlog-title">Administrator Login</h1>')) {
        throw new RuntimeException('Login page lost its session-expiry notice or login form.');
    }
    echo 'PASS: HTML login page retains the session-expiry notice and login form.', PHP_EOL;
} finally {
    foreach (glob($sessionDirectory . '/sess_*') ?: [] as $sessionFile) unlink($sessionFile);
    rmdir($sessionDirectory);
}
