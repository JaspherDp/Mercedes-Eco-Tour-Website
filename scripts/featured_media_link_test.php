<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/../php/featured_media_helper.php';
$checks=0;
function mediaCheck(bool $valid,string $message):void{global $checks;if(!$valid)throw new RuntimeException($message);$checks++;}
set_error_handler(static function($severity,$message){throw new ErrorException($message,0,$severity);});
$signed='https://cdn.example.com/image?id=12&signature=abc%2Fdef%3D';
mediaCheck(FeaturedMediaValidateLink($signed,'image')===$signed,'Signed image URL is retained without cache-busting parameters');
mediaCheck(FeaturedMediaValidateLink(' https://cdn.example.com/image.webp ','image')==='https://cdn.example.com/image.webp','Outer whitespace trimmed');
foreach(['javascript:alert(1)','data:image/svg+xml,test','file:///secret','http://example.com/image.jpg','https://user:pass@example.com/image.jpg','https://example.com/a\\b.jpg',"https://example.com/a'file.jpg",'https://example.com/<script>','https://example.com/'.str_repeat('a',260),''] as $url){try{FeaturedMediaValidateLink($url,'image');throw new LogicException('Unsafe URL accepted: '.$url);}catch(InvalidArgumentException){$checks++;}}
foreach(['https://youtu.be/dQw4w9WgXcQ','https://www.youtube.com/watch?v=dQw4w9WgXcQ&feature=share','https://youtube.com/shorts/dQw4w9WgXcQ','https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ'] as $url){FeaturedMediaValidateLink($url,'video');mediaCheck(FeaturedMediaVideoDescriptor($url)===['kind'=>'embed','url'=>'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ'],'YouTube links normalize to the allowed privacy-enhanced embed host');}
mediaCheck(FeaturedMediaVideoDescriptor('https://vimeo.com/123456789')['url']==='https://player.vimeo.com/video/123456789','Vimeo share link');
mediaCheck(FeaturedMediaVideoDescriptor('https://vimeo.com/123456789/a1b2')['url']==='https://player.vimeo.com/video/123456789?h=a1b2','Private Vimeo hash preserved');
foreach(['https://youtube.com/watch?v[]=bad','https://youtu.be/not-valid','https://vimeo.com/letters','https://player.vimeo.com/video/123?h[]=bad'] as $url){try{FeaturedMediaValidateLink($url,'video');throw new LogicException('Invalid provider URL accepted');}catch(InvalidArgumentException){$checks++;}}
mediaCheck(FeaturedMediaVideoDescriptor('https://cdn.example.com/file.webm?token=x')['kind']==='video','Direct WebM retains native playback');
mediaCheck(FeaturedMediaVideoDescriptor('uploads/featured/video.mp4')['kind']==='video','Local upload retains native playback');
$html=FeaturedMediaVideoHtml('https://www.youtube.com/watch?v=dQw4w9WgXcQ',true);
mediaCheck(str_contains($html,'<iframe')&&str_contains($html,'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ'),'Public linked YouTube uses an iframe');
mediaCheck(str_contains(FeaturedMediaVideoHtml('uploads/featured/video.mp4',true),'loop data-viewport-video')&&!str_contains(FeaturedMediaVideoHtml('uploads/featured/video.mp4',true),' autoplay'),'Public uploaded video delegates autoplay to viewport controls and retains looping');
mediaCheck(!str_contains(FeaturedMediaVideoHtml('uploads/featured/video.mp4',true),' muted'),'Homepage videos default to sound enabled');
mediaCheck(str_contains(FeaturedMediaVideoHtml('https://cdn.example.com/file.mp4?x=1&y=2'),'&amp;y=2'),'Direct video attributes escape query parameters');
mediaCheck(!str_contains(FeaturedMediaVideoHtml('javascript:alert(1)'),'javascript:'),'Unsafe stored video source falls back');
$driveId='Example_Drive_File_12345';
foreach(['https://drive.google.com/file/d/'.$driveId.'/view?usp=sharing','https://drive.google.com/file/d/'.$driveId.'/preview','https://drive.google.com/open?id='.$driveId,'https://drive.google.com/uc?export=download&id='.$driveId] as $url){FeaturedMediaValidateLink($url,'video');$drive=FeaturedMediaVideoDescriptor($url);mediaCheck($drive['kind']==='embed'&&$drive['provider']==='google_drive'&&$drive['url']==='https://drive.google.com/file/d/'.$driveId.'/preview','Unprepared Drive links retain its supported embedded player');}
mediaCheck(FeaturedMediaVideoDescriptor('https://drive.google.com/file/d/'.$driveId.'/view?resourcekey=0-example_key')['url']==='https://drive.google.com/file/d/'.$driveId.'/preview?resourcekey=0-example_key','Drive resource keys retained');
$preparedId='PlaybackFixture_'.bin2hex(random_bytes(12));
$preparedRelative='uploads/featured/drive_'.hash('sha256',$preparedId).'.mp4';
$preparedAbsolute=__DIR__.'/../'.$preparedRelative;
try{
    file_put_contents($preparedAbsolute,'fixture');
    $preparedUrl='https://drive.google.com/file/d/'.$preparedId.'/view';
    $prepared=FeaturedMediaVideoDescriptor($preparedUrl);
    mediaCheck($prepared['kind']==='video'&&$prepared['url']===$preparedRelative&&$prepared['provider']==='google_drive_prepared','Prepared Drive files select the compatible website copy');
    $preparedHtml=FeaturedMediaVideoHtml($preparedUrl,true);
    mediaCheck(str_contains($preparedHtml,'<video')&&str_contains($preparedHtml,'loop data-viewport-video')&&!str_contains($preparedHtml,'drive.usercontent.google.com'),'Prepared copies use native viewport-controlled playback without Drive download links');
}finally{if(is_file($preparedAbsolute))unlink($preparedAbsolute);}
mediaCheck(FeaturedMediaVideoDescriptor('https://drive.google.com/file/d/'.$preparedId.'/view')['kind']==='embed','Missing prepared media falls back to the Drive player');
foreach(['https://drive.google.com/drive/folders/'.$driveId,'https://drive.google.com/open?id[]=bad','https://drive.google.com/file/d/'.$driveId.'/view?resourcekey[]=bad'] as $url){try{FeaturedMediaValidateLink($url,'video');throw new LogicException('Invalid Drive link accepted');}catch(InvalidArgumentException){$checks++;}}
$policy=file_get_contents(__DIR__.'/../.htaccess');mediaCheck(substr_count($policy,'https://player.vimeo.com https://drive.google.com;')===2,'Both media frame policies allow Drive playback');
echo "Passed $checks featured media URL and rendering checks.\n";
