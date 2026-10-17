<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
// Uses a read-only existing local tourist account and isolated temporary sessions.
// No user records, timeout settings, bookings, or payments are modified.
require_once __DIR__ . '/../php/db_connection.php';
require_once __DIR__ . '/../php/session_security.php';
require_once __DIR__ . '/../php/app_url_helper.php';
if (ItourAppIsProduction()) throw new RuntimeException('Run this check only in the local development environment.');
$root = dirname(__DIR__);
$touristId = (int)$pdo->query("SELECT tourist_id FROM tourist WHERE status <> 'banned' LIMIT 1")->fetchColumn();
if ($touristId < 1) throw new RuntimeException('A local tourist account is required for read-only testing.');
$timeout = AppSessionTimeoutMinutes($pdo, 'tourist');
$directory = sys_get_temp_dir() . '/itour-tourist-idle-' . bin2hex(random_bytes(8));
mkdir($directory, 0700);
function touristIdleCheck(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
function touristIdleRequest(?string $page, string $id = '', array $post = [], array $get = [], string $setup = '', bool $html = false): array
{
    global $root, $directory;
    $snapshot = $directory . '/state-' . bin2hex(random_bytes(8)) . '.json';
    $file = $page ?? 'session-fixture.php';
    $server = ['REQUEST_METHOD' => $post ? 'POST' : 'GET', 'SCRIPT_NAME' => '/' . $file, 'PHP_SELF' => '/' . $file,
        'REQUEST_URI' => '/' . $file, 'DOCUMENT_ROOT' => $root, 'HTTP_HOST' => 'localhost',
        'HTTP_ACCEPT' => $html ? 'text/html' : 'application/json', 'HTTP_SEC_FETCH_DEST' => $html ? 'document' : 'empty'];
    if (!$html) $server['HTTP_X_REQUESTED_WITH'] = 'XMLHttpRequest';
    $code = '<?php $_SERVER=' . var_export($server, true) . ';';
    $code .= 'ini_set("session.save_path",' . var_export($directory, true) . ');require ' . var_export($root . '/php/session_security.php', true) . ';';
    if ($id !== '') $code .= 'session_id(' . var_export($id, true) . ');';
    $code .= 'AppSessionStart();$_POST=' . var_export($post, true) . ';$_GET=' . var_export($get, true) . ';';
    $code .= $setup;
    $code .= 'register_shutdown_function(function(){file_put_contents(' . var_export($snapshot, true) . ',json_encode(["id"=>session_id(),"session"=>$_SESSION,"status"=>http_response_code()]));});';
    $code .= $page ? 'require ' . var_export($root . '/' . $page, true) . ';' : 'echo "{}";';
    $process = proc_open([PHP_BINARY, '-d', 'display_errors=stderr'], [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes, $root);
    if (!is_resource($process)) throw new RuntimeException('Could not start fixture request.');
    fwrite($pipes[0], $code); fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]); $errors = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    touristIdleCheck(proc_close($process) === 0 && trim($errors) === '', $file . ': ' . $errors);
    $state = json_decode((string)file_get_contents($snapshot), true, 512, JSON_THROW_ON_ERROR); unlink($snapshot);
    return [$html ? $output : json_decode($output, true, 512, JSON_THROW_ON_ERROR), $state];
}
try {
    [, $initial] = touristIdleRequest(null, '', [], [], '$_SESSION["tourist_id"]=' . $touristId . ';$_SESSION["tourist_logged_in"]=true;AppMarkRoleAuthenticated("tourist");AppCsrfToken("tourist","session_activity");$_SESSION["admin_logged_in"]=true;$_SESSION["admin_id"]=123;');
    $id = $initial['id'];
    $query = ['role'=>'tourist', 'generation'=>$initial['session']['auth_generation']['tourist']];
    $csrf = $initial['session']['csrf_tourist_session_activity'];
    [$result,$passive] = touristIdleRequest('php/session_status.php',$id,[],$query,'$_SESSION["auth_last_activity"]["tourist"]=time()-90;');
    $before = $passive['session']['auth_last_activity']['tourist'];
    touristIdleCheck($result['active'] && $result['timeout_minutes'] === $timeout, 'Tourist status must preserve the configured timeout.');
    [, $passive2] = touristIdleRequest('php/session_status.php',$id,[],$query);
    touristIdleCheck($passive2['session']['auth_last_activity']['tourist'] === $before, 'Passive checks must not keep an idle tourist signed in.');
    [, $invalid] = touristIdleRequest('php/session_status.php',$id,['csrf_token'=>'invalid'],$query);
    touristIdleCheck($invalid['status'] === 403 && $invalid['session']['auth_last_activity']['tourist'] === $before, 'Invalid activity token must not refresh the timer.');
    [$active,$touched] = touristIdleRequest('php/session_status.php',$id,['csrf_token'=>$csrf],$query);
    touristIdleCheck($active['active'] && $touched['session']['auth_last_activity']['tourist'] > $before, 'Valid form/click activity must refresh the tourist timer.');
    [$html,$navigated] = touristIdleRequest('index.php',$id,[],[],'$_SESSION["auth_last_activity"]["tourist"]=time()-90;',true);
    touristIdleCheck(time() - $navigated['session']['auth_last_activity']['tourist'] < 5, 'Public browsing must refresh tourist activity.');
    touristIdleCheck(str_contains($html,'js/session-monitor.js') && str_contains($html,'"role":"tourist"'), 'Public pages must render the tourist activity monitor.');
    [, $expired] = touristIdleRequest('php/session_status.php',$id,['csrf_token'=>$csrf],$query,'$_SESSION["auth_last_activity"]["tourist"]=time()-' . ($timeout*60) . ';');
    touristIdleCheck($expired['status'] === 401 && !isset($expired['session']['tourist_id']), 'Expiry must occur at the inactivity boundary before a new activity can revive it.');
    touristIdleCheck(($expired['session']['admin_logged_in'] ?? false) === true && $expired['session']['admin_id'] === 123, 'Tourist expiry must not clear another role.');
    [, $fresh] = touristIdleRequest(null,$id,[],[],'$_SESSION["tourist_id"]=' . $touristId . ';$_SESSION["tourist_logged_in"]=true;AppMarkRoleAuthenticated("tourist");AppCsrfToken("tourist","session_activity");');
    [$oldTab,$untouched] = touristIdleRequest('php/session_status.php',$id,['csrf_token'=>$csrf],$query);
    touristIdleCheck($oldTab['code'] === 'SESSION_REPLACED' && isset($untouched['session']['tourist_id']), 'An old tab must not expire or renew a fresh tourist login.');
    touristIdleCheck($fresh['session']['auth_generation']['tourist'] !== $query['generation'] && $fresh['session']['csrf_tourist_session_activity'] !== $csrf, 'New logins must rotate the activity generation and token.');
    $newQuery = ['role'=>'tourist','generation'=>$fresh['session']['auth_generation']['tourist']];
    [$newStatus] = touristIdleRequest('php/session_status.php',$id,[],$newQuery);
    touristIdleCheck($newStatus['active'] && str_ends_with($oldTab['login_url'],'/?open_login=1'), 'Fresh tourist sessions and login redirects must remain usable.');
    echo "PASS: tourist timeout preserved; public navigation and valid activity refresh it; passive polls, bad CSRF, idle expiry, role isolation, stale tabs, and re-login work correctly.\n";
} finally {
    foreach (glob($directory . '/*') ?: [] as $file) unlink($file);
    rmdir($directory);
}
