(() => {
  'use strict';
  const box=document.querySelector('[data-featured-player]');if(!box)return;
  const video=box.querySelector('video'),frame=box.querySelector('iframe');if(!video&&!frame)return;
  let inView=false,pageGone=false,controller=null,soundButton=null;
  const shouldPlay=()=>inView&&!document.hidden&&!pageGone;
  function apply(){
    if(!controller)return;
    if(!shouldPlay()&&soundButton)soundButton.hidden=true;
    const blocked=error=>{if(error?.name==='NotAllowedError'&&soundButton&&shouldPlay())soundButton.hidden=false;};
    try{const result=shouldPlay()?controller.play():controller.pause();result?.catch?.(blocked);}catch(error){blocked(error);}
  }
  function observe(){
    if('IntersectionObserver' in window){
      const observer=new IntersectionObserver(entries=>{
        const entry=entries.find(item=>item.target===box);if(!entry)return;
        inView=entry.isIntersecting&&entry.intersectionRatio>=(inView?0.1:0.25);apply();
      },{threshold:[0,0.1,0.25,0.5,1]});observer.observe(box);
    }else{
      const update=()=>{const rect=box.getBoundingClientRect();inView=rect.bottom>0&&rect.top<window.innerHeight&&rect.right>0&&rect.left<window.innerWidth;apply();};
      window.addEventListener('scroll',update,{passive:true});window.addEventListener('resize',update);update();
    }
  }
  document.addEventListener('visibilitychange',apply);
  window.addEventListener('pagehide',()=>{pageGone=true;apply();});window.addEventListener('pageshow',()=>{pageGone=false;apply();});
  if(video){
    video.autoplay=false;video.removeAttribute('autoplay');video.removeAttribute('muted');video.defaultMuted=false;video.muted=false;video.playsInline=true;video.pause();
    soundButton=document.createElement('button');soundButton.type='button';soundButton.className='fe-video-play-sound';soundButton.textContent='Play with sound';soundButton.hidden=true;box.appendChild(soundButton);
    soundButton.addEventListener('click',()=>{video.muted=false;apply();});
    video.addEventListener('playing',()=>{soundButton.hidden=true;});
    controller={play:()=>video.play(),pause:()=>video.pause()};
    video.addEventListener('loadedmetadata',apply);video.addEventListener('play',()=>{if(!shouldPlay())video.pause();});
  }else{
    const source=new URL(frame.getAttribute('src'),window.location.href);
    const provider=frame.dataset.mediaProvider||(source.hostname==='www.youtube-nocookie.com'?'youtube':source.hostname==='player.vimeo.com'?'vimeo':source.hostname==='drive.google.com'?'google_drive':'');
    box.dataset.mediaProvider=provider;
    function fallback(){
      const url=source.href;
      controller={play(){if(frame.getAttribute('src')!==url)frame.src=url;},pause(){if(frame.getAttribute('src')!=='about:blank')frame.src='about:blank';}};
      apply();
    }
    function loadScript(url,ready){
      const script=document.createElement('script');script.src=url;script.async=true;script.onload=ready;script.onerror=fallback;document.head.appendChild(script);
    }
    if(provider==='youtube'){
      source.searchParams.set('enablejsapi','1');source.searchParams.set('origin',window.location.origin);source.searchParams.set('playsinline','1');source.searchParams.set('autoplay','0');source.searchParams.set('mute','0');frame.src=source.href;
      const connect=()=>{
        if(!window.YT?.Player){fallback();return;}
        new window.YT.Player(frame,{events:{onReady(event){event.target.unMute();controller={play:()=>event.target.playVideo(),pause:()=>event.target.pauseVideo()};apply();},onError(){fallback();}}});
      };
      if(window.YT?.Player)connect();
      else{const previous=window.onYouTubeIframeAPIReady;window.onYouTubeIframeAPIReady=()=>{try{previous?.();}finally{connect();}};loadScript('https://www.youtube.com/iframe_api',()=>{});}
    }else if(provider==='vimeo'){
      source.searchParams.set('autoplay','0');source.searchParams.set('muted','0');source.searchParams.set('playsinline','1');frame.src=source.href;
      const connect=()=>{
        if(!window.Vimeo?.Player){fallback();return;}
        const player=new window.Vimeo.Player(frame);
        player.ready().then(()=>player.setMuted(false)).then(()=>{controller={play:()=>player.play(),pause:()=>player.pause()};apply();}).catch(fallback);
      };
      if(window.Vimeo?.Player)connect();else loadScript('https://player.vimeo.com/api/player.js',connect);
    }else{
      // Drive has no supported parent-page play/pause API: unload when hidden.
      fallback();
    }
  }
  observe();
})();
