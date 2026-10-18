(() => {
  'use strict';
  let api;
  window.FeaturedMediaLinks={reset(type,slot){api?.reset(type,slot);},updateMedia(type,slot,response,path){api?.updateMedia(type,slot,response,path);}};
  document.addEventListener('DOMContentLoaded',()=>{
    const modal=document.getElementById('af-media-modal'),input=document.getElementById('af-media-url'),panel=document.getElementById('af-link-panel'),next=document.getElementById('af-media-next'),nextLabel=document.getElementById('af-media-next-label'),status=document.getElementById('af-link-status'),preview=document.getElementById('af-preview-area');
    const tabs=[...document.querySelectorAll('[data-featured-source]')];
    let mode='file',type='image',slot='',busy=false,version=0;
    function valid(){try{const url=new URL(input.value.trim());return input.value.trim().length<=255&&url.protocol==='https:'&&!url.username&&!url.password;}catch(_error){return false;}}
    function message(text,error=false){status.textContent=text;status.classList.toggle('is-error',error);}
    function busyState(on,label){busy=on;input.disabled=on;next.disabled=on||(mode==='link'&&!valid());next.setAttribute('aria-busy',String(on));document.getElementById('af-media-next-loader').style.display=on?'inline-block':'none';if(label)nextLabel.textContent=label;tabs.forEach(tab=>tab.disabled=on);document.getElementById('af-preview-link').disabled=on;}
    function node(media){
      const element=document.createElement(media.kind==='embed'?'iframe':media.kind==='video'?'video':'img');
      element.dataset.mediaProvider=media.provider||'';
      if(media.kind==='embed'){element.title='Featured Mercedes video';element.allow='autoplay; encrypted-media; picture-in-picture; fullscreen';element.allowFullscreen=true;element.referrerPolicy='strict-origin-when-cross-origin';}
      else if(media.kind==='video'){element.controls=true;element.muted=true;element.playsInline=true;element.preload='metadata';}
      else{element.alt='Featured image preview';element.referrerPolicy='no-referrer';}
      element.src=media.url;
      return element;
    }
    function updateMedia(mediaType,mediaSlot,response,path){
      const external=String(path).toLowerCase().startsWith('https://');const url=external?path:path+(path.includes('?')?'&':'?')+'t='+Date.now();
      if(mediaType==='image'){const image=document.querySelector('img[data-media-slot="'+mediaSlot+'"]');if(image)image.src=url;}
      else{const wrapper=document.getElementById('featured-video-preview');if(wrapper){wrapper.dataset.mediaUrl=path;wrapper.replaceChildren(node(response.media||{kind:'video',url}));}}
    }
    function setMode(value){
      if(busy||next.getAttribute('aria-busy')==='true')return;
      version++;resetMediaModal();preview.dataset.provider='';mode=value;modal.dataset.featuredSource=mode;modal.dataset.featuredType=type;message('');
      tabs.forEach(tab=>{const active=tab.dataset.featuredSource===mode;tab.classList.toggle('active',active);tab.setAttribute('aria-pressed',String(active));});
      panel.hidden=mode!=='link';input.required=mode==='link';document.getElementById('af-drop-area').style.display=mode==='file'?'flex':'none';
      document.querySelector('#af-media-modal .af-step-indicator').style.display=mode==='file'&&type==='image'?'flex':'none';
      document.getElementById('af-link-hint').textContent=type==='video'?'Use an HTTPS video file, YouTube, Vimeo, or Google Drive link. Drive files must be shared publicly.':'Use a public HTTPS link that opens the image directly.';
      document.querySelector('#af-preview-wrapper h3').textContent=type==='video'?'Video preview':'Image preview';
      document.querySelector('#af-preview-wrapper p').textContent='Review the selected media before saving.';
      nextLabel.textContent=mode==='link'?'Save link':type==='video'?'Save':'Next';next.disabled=mode==='link'?!valid():true;
      if(mode==='link')input.focus();
    }
    async function request(action){
      const body=new FormData();body.append('action',action);body.append('csrf_token',window.FeaturedMediaCsrf);body.append('media_type',type);body.append('media_slot',slot);body.append('media_source','link');body.append('media_url',input.value.trim());
      const response=await fetch(window.location.href,{method:'POST',body});const result=await response.json();if(!response.ok||!result.success)throw new Error(result.message||'The media link could not be saved.');return result;
    }
    async function previewLink(){
      if(mode!=='link'||busy)return;if(!valid()){input.reportValidity();message('Enter a valid public HTTPS link.',true);return;}
      const stamp=version;busyState(true,'Checking link…');message('Checking link…');
      try{
        const result=await request('preview_media_link');if(stamp!==version)return;
        preview.dataset.provider=result.media.provider||'';
        const element=node(result.media);element.addEventListener('error',()=>message('This media could not load. Check that the link is public and opens the media directly.',true));
        preview.replaceChildren(element);document.getElementById('af-preview-path').textContent=result.path;message(result.media.provider==='google_drive_prepared'?'A browser-compatible 1080p copy is ready for playback.':result.media.provider==='google_drive'?'Keep Drive sharing set to Anyone with the link - Viewer. Upload an H.264 MP4 for native autoplay and pause on scroll.':'Review the preview, then save your link.');
      }catch(error){if(stamp===version)message(error.message,true);}
      finally{if(stamp===version)busyState(false,'Save link');}
    }
    api={reset(mediaType,mediaSlot){
      busy=false;input.disabled=false;tabs.forEach(tab=>tab.disabled=false);next.setAttribute('aria-busy','false');type=mediaType;slot=mediaSlot;
      const current=type==='video'?document.getElementById('featured-video-preview')?.dataset.mediaUrl:document.querySelector('img[data-media-slot="'+slot+'"]')?.getAttribute('src');
      input.value=String(current||'').toLowerCase().startsWith('https://')?current:'';setMode('file');
    },updateMedia};
    tabs.forEach(tab=>tab.addEventListener('click',()=>setMode(tab.dataset.featuredSource)));
    input.addEventListener('input',()=>{version++;message('');if(mode==='link'&&!busy)next.disabled=!valid();});
    input.addEventListener('keydown',event=>{if(event.key==='Enter'){event.preventDefault();previewLink();}});
    document.getElementById('af-preview-link').addEventListener('click',previewLink);
    next.addEventListener('click',async event=>{
      if(mode!=='link')return;event.preventDefault();event.stopImmediatePropagation();if(busy)return;
      if(!valid()){input.reportValidity();message('Enter a valid public HTTPS link.',true);return;}
      busyState(true,'Saving…');message('');
      try{const result=await request('update_media');updateMedia(type,slot,result,result.path);modal.style.display='none';resetMediaModal();Swal.fire({icon:'success',title:type==='video'?'Video updated':'Image updated',text:'Your media link has been saved.',confirmButtonColor:'#246f59'});}
      catch(error){message(error.message,true);}
      finally{busyState(false,'Save link');}
    },true);
    ['af-media-close','af-media-cancel'].forEach(id=>document.getElementById(id).addEventListener('click',event=>{if(mode==='link'&&busy){event.preventDefault();event.stopImmediatePropagation();}},true));
  });
})();
