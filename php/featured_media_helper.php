<?php
declare(strict_types=1);

/** External media is referenced by the browser, never downloaded by the server. */
function FeaturedMediaValidateLink(mixed $value, string $type): string
{
    if (!is_string($value) || !in_array($type, ['image','video'], true)) throw new InvalidArgumentException('Choose a valid media link.');
    $url=trim($value);
    if ($url==='' || strlen($url)>255 || preg_match('/[\x00-\x20\x7f<>"\'\\\\]/', $url) || !filter_var($url,FILTER_VALIDATE_URL)) {
        throw new InvalidArgumentException('Enter a public media URL of 255 characters or fewer.');
    }
    $parts=parse_url($url);
    if (strtolower($parts['scheme']??'')!=='https' || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
        throw new InvalidArgumentException('Use a public HTTPS link without a username or password.');
    }
    if ($type==='video') FeaturedMediaVideoDescriptor($url);
    return $url;
}

function FeaturedMediaVideoDescriptor(string $url): array
{
    $parts=parse_url($url);$host=strtolower($parts['host']??'');$path=trim($parts['path']??'','/');
    if($host==='drive.google.com'){
        $query=[];parse_str($parts['query']??'',$query);
        $id=preg_match('~^file/d/([A-Za-z0-9_-]+)(?:/(?:view|preview|edit))?/?$~D',$path,$m)?$m[1]:(in_array($path,['open','uc'],true)?($query['id']??''):'');
        if(!is_string($id)||!preg_match('/^[A-Za-z0-9_-]{10,200}$/D',$id))throw new InvalidArgumentException('Use a Google Drive file sharing or preview link, not a folder link.');
        $resourceKey=$query['resourcekey']??'';
        if(!is_string($resourceKey)||($resourceKey!==''&&!preg_match('/^[A-Za-z0-9_-]{1,200}$/D',$resourceKey)))throw new InvalidArgumentException('The Google Drive resource key is invalid.');
        // Prepared H.264 copies support native playback without Drive download
        // restrictions or an HEVC decoder. Keep other Drive links on its player.
        $prepared='uploads/featured/drive_'.hash('sha256',$id).'.mp4';
        if(is_file(__DIR__.'/../'.$prepared)&&filesize(__DIR__.'/../'.$prepared)>0){
            return ['kind'=>'video','provider'=>'google_drive_prepared','url'=>$prepared];
        }
        return ['kind'=>'embed','provider'=>'google_drive','url'=>'https://drive.google.com/file/d/'.$id.'/preview'.($resourceKey!==''?'?resourcekey='.rawurlencode($resourceKey):'')];
    }
    if(in_array($host,['youtube.com','www.youtube.com','m.youtube.com','youtu.be','www.youtu.be','youtube-nocookie.com','www.youtube-nocookie.com'],true)){
        $query=[];parse_str($parts['query']??'',$query);
        $id=in_array($host,['youtu.be','www.youtu.be'],true)?explode('/',$path)[0]:($path==='watch'?($query['v']??''):(preg_match('~^(?:embed|shorts|live)/([^/]+)~',$path,$m)?$m[1]:''));
        if(!is_string($id)||!preg_match('/^[A-Za-z0-9_-]{11}$/D',$id))throw new InvalidArgumentException('Use a valid YouTube watch, share, or Shorts link.');
        return ['kind'=>'embed','url'=>'https://www.youtube-nocookie.com/embed/'.$id];
    }
    if(in_array($host,['vimeo.com','www.vimeo.com','player.vimeo.com'],true)){
        if(!preg_match('~^(?:video/)?([0-9]+)(?:/([a-zA-Z0-9]+))?$~D',$path,$m))throw new InvalidArgumentException('Use a direct Vimeo video link.');
        $query=[];parse_str($parts['query']??'',$query);$hash=$query['h']??($m[2]??'');
        if(!is_string($hash)||($hash!==''&&!preg_match('/^[a-zA-Z0-9]+$/D',$hash)))throw new InvalidArgumentException('The Vimeo video link is invalid.');
        return ['kind'=>'embed','url'=>'https://player.vimeo.com/video/'.$m[1].($hash!==''?'?h='.rawurlencode($hash):'')];
    }
    return ['kind'=>'video','url'=>$url];
}

function FeaturedMediaVideoHtml(string $path, bool $public=false): string
{
    $path=$path!==''?$path:'img/samplevideo.mp4';
    try {
        if(preg_match('~^https?://~i',$path))FeaturedMediaValidateLink($path,'video');
        elseif(str_contains($path,':'))throw new InvalidArgumentException('Invalid video source.');
        $media=FeaturedMediaVideoDescriptor($path);
    }catch(InvalidArgumentException){$media=['kind'=>'video','url'=>'img/samplevideo.mp4'];}
    $provider=$media['provider']??(str_starts_with($media['url'],'https://www.youtube-nocookie.com/')?'youtube':(str_starts_with($media['url'],'https://player.vimeo.com/')?'vimeo':''));
    $url=htmlspecialchars($media['url'],ENT_QUOTES,'UTF-8');
    if($media['kind']==='embed')return '<iframe class="featured-video-embed" data-media-provider="'.htmlspecialchars($provider,ENT_QUOTES,'UTF-8').'" src="'.$url.'" title="Featured Mercedes video" loading="lazy" allow="autoplay; encrypted-media; picture-in-picture; fullscreen" allowfullscreen referrerpolicy="strict-origin-when-cross-origin"></iframe>';
    return '<video src="'.$url.'" controls'.($public?'':' muted').' playsinline preload="metadata"'.($public?' loop data-viewport-video':'').'></video>';
}
