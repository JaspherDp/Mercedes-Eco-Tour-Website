<?php
declare(strict_types=1);
// Synthetic images, SQLite and localhost HTTP only. Never boots the application database.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__.'/../php/operator_package_media.php';
$checks=0;$created=[];$process=null;$testFiles=[];
function packageMediaCheck(bool $ok,string $message):void {global $checks;if(!$ok)throw new RuntimeException($message);$checks++;}
function packageMediaReject(callable $fn,string $message):void {
    $rejected=false;try{$fn();}catch(InvalidArgumentException $e){$rejected=true;}
    packageMediaCheck($rejected,$message);
}
$directory=sys_get_temp_dir().DIRECTORY_SEPARATOR.'itour-pkg-test-'.bin2hex(random_bytes(8));
if(!mkdir($directory))throw new RuntimeException('Fixture directory could not be created.');
try {
    $blank=OperatorPackageValidateImages([],[]);
    packageMediaCheck(count($blank)===6 && count(array_filter($blank))===0,'Unchanged edit has no image replacements');
    foreach($blank as $image)packageMediaCheck(OperatorPackageSaveImage($image,$created)===null,'Absent images keep existing paths');
    packageMediaCheck($created===[],'No-change save creates no uploads');
    $database=new PDO('sqlite::memory:');$database->exec("CREATE TABLE tour_packages (package_image TEXT);INSERT INTO tour_packages VALUES ('php/upload/existing.webp')");
    $database->prepare('UPDATE tour_packages SET package_image=COALESCE(?,package_image)')->execute([$blank['package_image']]);
    packageMediaCheck($database->query('SELECT package_image FROM tour_packages')->fetchColumn()==='php/upload/existing.webp','Existing media preserved by unchanged update');
    packageMediaReject(fn()=>OperatorPackageValidateImages([],['package_image_data'=>'https://example.test/existing.jpg']),'URLs are not downloaded or treated as uploads');
    packageMediaReject(fn()=>OperatorPackageValidateImages(['package_image'=>['error'=>UPLOAD_ERR_INI_SIZE]],[]),'Server size errors remain rejected');
    packageMediaReject(fn()=>OperatorPackageValidateImages(['package_image'=>['error'=>UPLOAD_ERR_PARTIAL]],[]),'Partial uploads rejected');
    packageMediaReject(fn()=>OperatorPackageValidateImages(['package_image'=>'invalid'],[]),'Malformed upload shape rejected');
    $small=imagecreatetruecolor(64,48);imagefill($small,0,0,imagecolorallocate($small,30,100,65));
    ob_start();imagejpeg($small,null,90);$jpeg=ob_get_clean();imagedestroy($small);
    $large=$jpeg.str_repeat("\0",6*1024*1024);
    $base64=OperatorPackageValidateBase64Image('data:image/jpeg;base64,'.base64_encode($large));
    packageMediaCheck($base64['size']>5*1024*1024,'Legacy genuine base64 images over old 5 MB cap are accepted');
    $saved=OperatorPackageSaveImage($base64,$created);$path=dirname(__DIR__).'/'.$saved;
    packageMediaCheck(is_file($path) && filesize($path)<strlen($large),'Server re-encodes and compresses large input');
    packageMediaReject(fn()=>OperatorPackageValidateBase64Image('data:image/webp;base64,'.base64_encode($jpeg)),'Declared MIME must match genuine contents');
    packageMediaReject(fn()=>OperatorPackageValidateBase64Image('data:image/svg+xml;base64,'.base64_encode('<svg/>')),'Active SVG uploads remain rejected');
    packageMediaReject(fn()=>OperatorPackageValidateBase64Image('data:image/png;base64,'.base64_encode('<?php echo 1;')),'Non-image payloads rejected');

    // Use real HTTP multipart uploads so is_uploaded_file is exercised, not bypassed.
    $root=dirname(__DIR__);$router=$directory.'/router.php';$testFiles[]=$router;
    $routerSource='<?php require_once '.var_export($root.'/php/operator_package_media.php',true).'; '. <<<'PHP'
header('Content-Type: application/json');
if (parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH)!=='/fixture') {http_response_code(404);exit;}
$paths=[];
try {
    $images=OperatorPackageValidateImages($_FILES,$_POST);$result=[];
    foreach($images as $field=>$image){
        $relative=OperatorPackageSaveImage($image,$paths);
        // Resolve through the helper's registered absolute path, not test-router location.
        if($relative!==null){$file=$paths[count($paths)-1];$info=getimagesize($file);$result[$field]=['width'=>$info[0],'height'=>$info[1],'mime'=>$info['mime'],'bytes'=>filesize($file)];}
    }
    echo json_encode(['success'=>true,'images'=>$result]);
}catch(Throwable $e){http_response_code(422);echo json_encode(['success'=>false,'message'=>$e->getMessage()]);}
finally{foreach($paths as $path)if(is_file($path))unlink($path);}
PHP;
    file_put_contents($router,$routerSource);
    $socket=stream_socket_server('tcp://127.0.0.1:0',$errno,$error);if(!$socket)throw new RuntimeException('Fixture port unavailable.');
    $address=stream_socket_get_name($socket,false);fclose($socket);
    $log=$directory.'/server.log';$testFiles[]=$log;
    $process=proc_open([PHP_BINARY,'-d','upload_max_filesize=50M','-d','post_max_size=70M','-d','display_errors=0','-d','upload_tmp_dir="'.str_replace('\\','/',$directory).'"','-S',$address,'-t',$root,$router],[0=>['pipe','r'],1=>['file',$log,'a'],2=>['file',$log,'a']],$pipes,$root);
    if(!is_resource($process))throw new RuntimeException('Fixture server could not start.');fclose($pipes[0]);
    $url='http://'.$address.'/fixture';
    $request=static function(array $fields)use($url):array {
        $curl=curl_init($url);curl_setopt_array($curl,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$fields,CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>3,CURLOPT_TIMEOUT=>15,CURLOPT_HTTPHEADER=>['Expect:']]);
        $body=curl_exec($curl);$status=curl_getinfo($curl,CURLINFO_RESPONSE_CODE);$error=curl_error($curl);curl_close($curl);
        if(!is_string($body))throw new RuntimeException('Fixture HTTP failed: '.$error);
        $json=json_decode($body,true);if(!is_array($json))throw new RuntimeException('Fixture returned invalid JSON: '.$body);
        return [$status,$json];
    };
    for($attempt=0;$attempt<30;$attempt++){
        try{[$status,$result]=$request(['title'=>'Unchanged package']);break;}catch(RuntimeException $e){if($attempt===29)throw $e;usleep(100000);}
    }
    packageMediaCheck($status===200 && $result['success'] && !$result['images'],'Actual multipart no-change request has no image uploads');
    $largePath=$directory.'/large.jpg';$testFiles[]=$largePath;file_put_contents($largePath,$large);unset($base64,$large);
    [$status,$result]=$request(['package_image'=>new CURLFile($largePath,'image/jpeg','large.jpg')]);
    packageMediaCheck($status===200 && $result['images']['package_image']['bytes']<filesize($largePath),'Real upload larger than 5 MB compresses successfully');
    foreach(['png','webp'] as $extension){
        $file=$directory.'/wide.'.$extension;$testFiles[]=$file;$image=imagecreatetruecolor(2600,1950);
        imagealphablending($image,false);imagesavealpha($image,true);imagefill($image,0,0,imagecolorallocatealpha($image,10,100,70,50));
        $extension==='png'?imagepng($image,$file):imagewebp($image,$file,90);imagedestroy($image);
        [$status,$result]=$request(['location_image'=>new CURLFile($file,'image/'.$extension,'wide.'.$extension)]);
        $out=$result['images']['location_image']??[];
        packageMediaCheck($status===200 && ($out['width']??0)===1920 && ($out['height']??0)===1440,'Uploaded '.$extension.' uses same admin 1920px server optimizer');
        packageMediaCheck(($out['mime']??'')==='image/'.$extension,'Uploaded '.$extension.' preserves genuine image format');
    }
    $bad=$directory.'/bad.jpg';$testFiles[]=$bad;file_put_contents($bad,'<?php echo "invalid";');
    [$status,$result]=$request(['package_image'=>new CURLFile($bad,'image/jpeg','bad.jpg')]);
    packageMediaCheck($status===422 && !$result['success'],'Spoofed multipart image rejected');
    packageMediaReject(fn()=>ItourSecureValidateImageBytes(str_repeat('x',40*1024*1024+1),40*1024*1024,40000000,12000,12000),'Payload exceeding shared 40 MB byte limit is rejected before decoding');
    echo "Passed $checks operator package media checks (synthetic images and localhost multipart; no application database access).\n";
}finally{
    if(is_resource($process)){proc_terminate($process);proc_close($process);}
    foreach($created as $path)if(is_file($path))unlink($path);
    foreach($testFiles as $path)if(is_file($path))unlink($path);
    if(is_dir($directory))rmdir($directory);
}
