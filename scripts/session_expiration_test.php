<?php
declare(strict_types=1);

// LOCAL ONLY: real credentials handlers and status endpoint with temporary
// fixture accounts, isolated session files, and cleanup in finally.
require_once __DIR__ . '/../php/db_connection.php';
require_once __DIR__ . '/../php/session_security.php';
require_once __DIR__ . '/../php/app_url_helper.php';
if (ItourAppIsProduction()) throw new RuntimeException('Run this regression test only on the local development database.');
$root = dirname(__DIR__);
$directory = sys_get_temp_dir() . '/itour-session-audit-' . bin2hex(random_bytes(8));
mkdir($directory, 0700);
$fixtures = [];
$properties = [];
$checks = [];

function sessionAuditAssert(bool $ok, string $message): void
{
    if (!$ok) throw new RuntimeException($message);
}

function sessionAuditRequest(string $page, string $sessionId, array $post = [], array $get = [], string $setup = '', bool $html = false): array
{
    global $root, $directory;
    $snapshot = $directory . '/snapshot-' . bin2hex(random_bytes(8)) . '.json';
    $server = ['REQUEST_METHOD' => $post ? 'POST' : 'GET', 'SCRIPT_NAME' => '/' . $page,
        'PHP_SELF' => '/' . $page, 'REQUEST_URI' => '/' . $page, 'HTTP_HOST' => 'localhost',
        'DOCUMENT_ROOT' => $root, 'REMOTE_ADDR' => '127.0.0.1',
        'HTTP_ACCEPT' => $html ? 'text/html' : 'application/json',
        'HTTP_SEC_FETCH_DEST' => $html ? 'document' : 'empty'];
    if (!$html) $server['HTTP_X_REQUESTED_WITH'] = 'XMLHttpRequest';
    $code = '<?php $_SERVER=' . var_export($server, true) . ';';
    $code .= 'putenv("APP_ENV=testing");putenv("CLOUDFLARE_TURNSTILE_SITE_KEY=1x00000000000000000000AA");putenv("CLOUDFLARE_TURNSTILE_SECRET_KEY=1x0000000000000000000000000000000AA");';
    $code .= 'ini_set("session.save_path",' . var_export($directory, true) . ');';
    $code .= 'require ' . var_export($root . '/php/session_security.php', true) . ';';
    if ($sessionId !== '') $code .= 'session_id(' . var_export($sessionId, true) . ');';
    $code .= 'AppSessionStart(); $_POST=' . var_export($post, true) . ';$_GET=' . var_export($get, true) . ';';
    $code .= $setup;
    $code .= 'register_shutdown_function(function(){file_put_contents(' . var_export($snapshot, true) . ',json_encode(["id"=>session_id(),"session"=>$_SESSION,"status"=>http_response_code()]));});';
    $code .= 'require ' . var_export($root . '/' . $page, true) . ';';
    $process = proc_open([PHP_BINARY, '-d', 'display_errors=stderr'], [0 => ['pipe','r'], 1 => ['pipe','w'], 2 => ['pipe','w']], $pipes, $root);
    if (!is_resource($process)) throw new RuntimeException('Could not execute ' . $page);
    fwrite($pipes[0], $code); fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]); $errors = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    sessionAuditAssert(proc_close($process) === 0 && trim($errors) === '', $page . ': ' . $errors);
    $state = json_decode((string)file_get_contents($snapshot), true, 512, JSON_THROW_ON_ERROR);
    unlink($snapshot);
    return [$html ? $output : json_decode($output, true, 512, JSON_THROW_ON_ERROR), $state];
}

try {
    sessionAuditAssert(PortalSettingsTableExists($pdo), 'Apply portal_account_settings_migration.sql first.');
    $password = bin2hex(random_bytes(20));
    $hash = password_hash($password, PASSWORD_DEFAULT);
    foreach (['admin', 'operator', 'hotel_admin'] as $role) {
        $accounts = [];
        foreach ([15, 120] as $minutes) {
            $name = 'sessiontest_' . bin2hex(random_bytes(7));
            if ($role === 'admin') {
                $stmt = $pdo->prepare('INSERT INTO admin_users(username,password,full_name,email) VALUES (?,?,?,?)');
                $stmt->execute([$name,$hash,$name,$name.'@example.invalid']);
            } elseif ($role === 'operator') {
                $stmt = $pdo->prepare("INSERT INTO operators(username,password,fullname,email,status) VALUES (?,?,?,?,'active')");
                $stmt->execute([$name,$hash,$name,$name.'@example.invalid']);
            } else {
                $stmt = $pdo->prepare("INSERT INTO hotel_resorts(name,island,type) VALUES (?,'Test','Hotel')");
                $stmt->execute([$name]); $propertyId = (int)$pdo->lastInsertId(); $properties[] = $propertyId;
                $stmt = $pdo->prepare("INSERT INTO hotel_admin_accounts(hotel_resort_id,username,password,full_name,status) VALUES (?,?,?,?,'active')");
                $stmt->execute([$propertyId,$name,$hash,$name]);
            }
            $id = (int)$pdo->lastInsertId();
            $fixture = ['role'=>$role,'id'=>$id,'username'=>$name,'minutes'=>$minutes];
            $fixtures[] = $fixture; $accounts[] = $fixture;
            PortalSaveSettings($pdo,$role,$id,array_replace(PortalSettingsDefaults($role),['session_timeout_minutes'=>$minutes]));
        }
        $login = 'php/' . match($role) {'admin'=>'admin_login.php','operator'=>'operator_login.php',default=>'hotel_admin_login.php'};
        $sessionId = '';
        // A -> B -> A verifies both account-switch directions.
        foreach ([$accounts[0], $accounts[1], $accounts[0]] as $account) {
            [$payload,$state] = sessionAuditRequest($login,$sessionId,['username'=>$account['username'],'password'=>$password,'cf-turnstile-response'=>'local-test-token']);
            sessionAuditAssert(($payload['success']??false) === true, $role . ': actual credentials login failed.');
            sessionAuditAssert($state['id'] !== $sessionId, $role . ': login did not regenerate the ID.');
            $sessionId = $state['id'];
            sessionAuditAssert((int)$state['session']['auth_identity'][$role] === $account['id'], 'Login kept the wrong identity.');
            sessionAuditAssert((int)$state['session']['auth_timeout_minutes'][$role] === $account['minutes'], 'Login inherited another account timeout.');
            $query = ['role'=>$role,'generation'=>$state['session']['auth_generation'][$role]];
            $age = 60;
            [$payload,$passive] = sessionAuditRequest('php/session_status.php',$sessionId,[],$query,
                '$_SESSION["auth_last_activity"]['.var_export($role,true).']=time()-'.$age.';');
            sessionAuditAssert($payload['active'] === true && $payload['timeout_minutes'] === $account['minutes'], 'Status uses wrong policy.');
            $before = $passive['session']['auth_last_activity'][$role];
            [$payload,$passive2] = sessionAuditRequest('php/session_status.php',$sessionId,[],$query);
            sessionAuditAssert($passive2['session']['auth_last_activity'][$role] === $before, 'Passive polling extends inactivity.');
            [$payload,$badCsrf] = sessionAuditRequest('php/session_status.php',$sessionId,['csrf_token'=>'invalid'],$query);
            sessionAuditAssert($badCsrf['status'] === 403 && $badCsrf['session']['auth_last_activity'][$role] === $before, 'Invalid activity token extended session.');
            // Simulate the exact timeout boundary without waiting hours.
            [$payload,$expired] = sessionAuditRequest('php/session_status.php',$sessionId,[],$query,
                '$_SESSION["auth_last_activity"]['.var_export($role,true).']=time()-'.($account['minutes']*60).';');
            sessionAuditAssert($payload['active'] === false && $expired['status'] === 401, 'Session did not expire.');
            sessionAuditAssert(!isset($expired['session'][$role.'_logged_in']), 'Expired authentication survived.');
            sessionAuditAssert(str_ends_with($payload['login_url'],basename($login)), 'Wrong panel login redirect.');
            $stale = '$_SESSION['.var_export($role.'_logged_in',true).']=true;$_SESSION['.var_export($role.'_id',true).']='.$account['id'].';$_SESSION["auth_last_activity"]['.var_export($role,true).']=time()-86400;';
            [$payload,$fresh] = sessionAuditRequest($login,$sessionId,['username'=>$account['username'],'password'=>$password,'cf-turnstile-response'=>'local-test-token'],[],$stale);
            sessionAuditAssert(($payload['success']??false) === true, 'Re-login failed.');
            sessionAuditAssert($fresh['id'] !== $sessionId, 'Re-login reused expired ID.');
            $sessionId = $fresh['id'];
            $freshQuery = ['role'=>$role,'generation'=>$fresh['session']['auth_generation'][$role]];
            [$oldTab,$untouched] = sessionAuditRequest('php/session_status.php',$sessionId,[],$query);
            sessionAuditAssert($oldTab['code'] === 'SESSION_REPLACED' && ($untouched['session'][$role.'_logged_in']??false) === true, 'Old tab invalidated new login.');
            foreach ([1,2,3] as $navigation) {
                [$payload] = sessionAuditRequest('php/session_status.php',$sessionId,[],$freshQuery);
                sessionAuditAssert($payload['active'] === true, 'Fresh session immediately expired.');
            }
            $pages = match($role) {
                'admin'=>['adsystemsettings.php','adadministratorprofile.php'],
                'operator'=>['opsystemsettings.php','opprofile.php'],
                default=>['Hosystemsettings.php','Hoprofile.php'],
            };
            foreach ($pages as $page) {
                [$html,$pageState] = sessionAuditRequest($page,$sessionId,[],[],'',true);
                sessionAuditAssert(str_contains($html,'js/session-monitor.js'), $page . ': new session could not navigate to protected page.');
            }
            [$payload,$active] = sessionAuditRequest('php/session_status.php',$sessionId,['csrf_token'=>$pageState['session']['csrf_'.$role.'_session_activity']],$freshQuery);
            sessionAuditAssert($payload['active'] === true, 'Valid activity rejected.');
            // Live preference changes are read again, with no stale cache.
            PortalSaveSettings($pdo,$role,$account['id'],array_replace(PortalSettingsDefaults($role),['session_timeout_minutes'=>30]));
            [$payload] = sessionAuditRequest('php/session_status.php',$sessionId,[],$freshQuery);
            sessionAuditAssert($payload['timeout_minutes'] === 30, 'Current session ignored saved preference change.');
            PortalSaveSettings($pdo,$role,$account['id'],array_replace(PortalSettingsDefaults($role),['session_timeout_minutes'=>$account['minutes']]));
        }
        $logout = match($role) {'admin'=>'adhomepage.php','operator'=>'operator/operator_sidebar.php',default=>'php/hotel_admin_logout.php'};
        [$output,$loggedOut] = sessionAuditRequest($logout,$sessionId,[],['action'=>'logout'],'',true);
        sessionAuditAssert(!isset($loggedOut['session'][$role.'_logged_in']) && !isset($loggedOut['session']['auth_last_activity'][$role]), 'Logout retained authentication or timing.');
        $checks[] = strtoupper($role) . ': stale authenticated login, two accounts (15/120 min), real login/re-login, ID rotation, page navigation, passive checks, expiry, correct route, stale tabs, CSRF, live preference reload, logout';
        echo 'PASS: ', end($checks), PHP_EOL;
    }
} finally {
    foreach ($fixtures as $fixture) {
        $role=$fixture['role']; $id=$fixture['id'];
        $pdo->prepare('DELETE FROM portal_account_settings WHERE role=? AND account_id=?')->execute([$role,$id]);
        $pdo->prepare('DELETE FROM admin_activity_logs WHERE actor_name=?')->execute([$fixture['username']]);
        if ($role==='admin') $pdo->prepare('DELETE FROM admin_profile_details WHERE admin_id=?')->execute([$id]);
        if ($role==='hotel_admin') $pdo->prepare('DELETE FROM hotel_admin_profile_details WHERE hotel_admin_id=?')->execute([$id]);
        [$table,$key]=match($role){'admin'=>['admin_users','admin_id'],'operator'=>['operators','operator_id'],default=>['hotel_admin_accounts','hotel_admin_id']};
        $pdo->prepare("DELETE FROM $table WHERE $key=? AND username=?")->execute([$id,$fixture['username']]);
    }
    foreach ($properties as $id) $pdo->prepare('DELETE FROM hotel_resorts WHERE hotel_resort_id=?')->execute([$id]);
    foreach (glob($directory.'/*') ?: [] as $file) unlink($file);
    rmdir($directory);
}

