(()=>{'use strict';
document.querySelectorAll('[data-operator-header-profile]').forEach(profile=>{profile.addEventListener('click',()=>document.getElementById('opProfile')?.click());profile.addEventListener('keydown',event=>{if(event.key==='Enter'||event.key===' '){event.preventDefault();document.getElementById('opProfile')?.click();}});});

const panel=document.getElementById('opNotifPanel');
if(!panel)return;
panel.setAttribute('role','dialog');
panel.setAttribute('aria-label','Booking notifications');
const heading=panel.querySelector('h4');
if(heading){
  heading.innerHTML='<span class="op-notif-title-icon"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9M10 21h4"></path></svg></span><span class="op-notif-title-copy"><b>Booking Notifications</b><small>Recent booking activity</small></span>';
}
const emptyState=panel.querySelector('.op-notif-empty');
if(emptyState)emptyState.innerHTML='<span class="op-notif-empty-icon">✓</span><b>You are all caught up</b><small>New booking activity will appear here.</small>';

let readPersisted=false;
const persistRead=()=>{
  if(readPersisted)return;
  readPersisted=true;
  document.querySelector('.op-notif-badge')?.remove();
  fetch(location.pathname,{method:'POST',credentials:'same-origin',keepalive:true,headers:{'Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams({op_action:'mark_notifications_read',csrf_token:String(window.operatorNotificationCsrf||'')})}).catch(()=>{readPersisted=false;});
};

const notificationToggle=document.getElementById('opNotifToggle');
notificationToggle?.addEventListener('click',()=>{
  if(!panel.classList.contains('open'))persistRead();
  queueMicrotask(()=>notificationToggle.setAttribute('aria-expanded',panel.classList.contains('open')?'true':'false'));
});

panel.querySelectorAll('.op-notif-list li').forEach(item=>{
  item.classList.add('op-notif-link');
  item.setAttribute('role','link');
  item.tabIndex=0;
  const openBooking=()=>{
    const label=item.querySelector('strong')?.textContent||'';
    const bookingKey=label.split(/\s[-–—]\s/)[0].trim().replace(/^#/,'');
    if(!bookingKey)return;
    persistRead();
    location.href=`opbookings.php?q=${encodeURIComponent(bookingKey)}`;
  };
  item.addEventListener('click',openBooking);
  item.addEventListener('keydown',event=>{if(event.key==='Enter'||event.key===' '){event.preventDefault();openBooking();}});
});
})();
