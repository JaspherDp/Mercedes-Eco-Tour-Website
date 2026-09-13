(()=>{'use strict';
const cfg=window.opPaymentsConfig||{},modal=document.getElementById('opCollectModal'),form=document.getElementById('opCollectForm'),booking=document.getElementById('opCollectBookingSelect'),method=document.getElementById('opCollectMethod'),amount=document.getElementById('opCollectAmount'),selected=document.getElementById('opCollectBooking'),balanceText=document.getElementById('opCollectBalance'),qrHelp=document.getElementById('opQrGuidance'),submit=form?.querySelector('.op-collect-submit'),drawer=document.getElementById('opTransactionDrawer'),receiptModal=document.getElementById('opReceiptModal'),receiptPaper=document.getElementById('opReceiptPaper'),viewReceipt=document.getElementById('opViewReceipt');let current=null;
const peso=v=>`₱${Number(v||0).toLocaleString('en-PH',{minimumFractionDigits:2,maximumFractionDigits:2})}`;
const escapeHtml=value=>String(value||'').replace(/[&<>"']/g,char=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[char]));
const receivableDrawer=document.getElementById('opReceivableDetailsDrawer'),receivableBody=document.getElementById('opReceivableDetailsBody');
if(qrHelp)qrHelp.innerHTML='<span class="op-qr-phone-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><rect x="7" y="2" width="10" height="20" rx="2"/><path d="M10 18h4"/></svg></span><div class="op-qr-phone-copy"><small>REGISTERED OPERATOR PHONE</small><strong id="opQrPhoneName">Checking registered phone…</strong><p id="opQrPhoneMeta">Please wait while the device status is refreshed.</p></div><button class="op-qr-phone-change" type="button"><span id="opQrPhoneAction">Change</span></button>';
const phoneState={device:null,baseline:null,pollTimer:null};
const phoneRegistrationModal=document.getElementById('opPaymentsPhoneRegistrationModal'),phoneOverviewModal=document.getElementById('opPaymentsPhoneOverviewModal');
function paymentPhoneSetupUrl(){return new URL(cfg.phoneSetupUrl||'operator-phone-setup.php',location.href).href;}
async function fetchPaymentPhone(){const response=await fetch(cfg.phoneStatusEndpoint,{credentials:'same-origin',cache:'no-store',headers:{Accept:'application/json'}}),result=await response.json().catch(()=>({}));if(!response.ok||!result.success)throw new Error(result.message||'Unable to check the registered phone.');return result;}
function paymentPhoneMeta(device){const stamp=new Date(String(device?.created_at||device?.last_used_at||'').replace(' ','T'));return Number.isNaN(stamp.getTime())?'Notifications active and ready for PayMongo QR.':`Notifications active · Registered ${stamp.toLocaleString('en-PH',{dateStyle:'medium',timeStyle:'short'})}`;}
function renderPaymentPhone(result){
  const registered=Boolean(result?.registered&&result.device),device=registered?result.device:null,name=device?.device_name||'No operator phone registered',meta=registered?paymentPhoneMeta(device):'Register a phone before sending PayMongo QR notifications.';phoneState.device=device;
  [['opQrPhoneName',name],['opQrPhoneMeta',meta],['opPaymentsPhoneCurrentName',name],['opPaymentsPhoneCurrentMeta',meta],['opPaymentsPhoneOverviewName',name],['opPaymentsPhoneOverviewMeta',meta]].forEach(([id,value])=>{const el=document.getElementById(id);if(el)el.textContent=value;});
  qrHelp?.classList.toggle('is-missing',!registered);qrHelp?.classList.remove('is-error');
  const action=document.getElementById('opQrPhoneAction');if(action)action.textContent=registered?'Change':'Register';
  document.getElementById('opPaymentsPhoneCurrent')?.classList.toggle('is-registered',registered);document.getElementById('opPaymentsPhoneOverviewCurrent')?.classList.toggle('is-registered',registered);
  const state=document.getElementById('opPaymentsPhoneCurrentState');if(state)state.textContent=registered?'Registered':'Not registered';
  const overviewAction=document.getElementById('opPaymentsPhoneOverviewAction');if(overviewAction)overviewAction.textContent=registered?'Change':'Register Phone';
}
async function refreshPaymentPhone(silent=false){
  const name=document.getElementById('opQrPhoneName'),meta=document.getElementById('opQrPhoneMeta');if(!silent&&name){name.textContent='Checking registered phone…';if(meta)meta.textContent='Please wait while the device status is refreshed.';}
  try{const result=await fetchPaymentPhone();renderPaymentPhone(result);return result;}catch(error){if(!silent){qrHelp?.classList.add('is-error');if(name)name.textContent='Phone status unavailable';if(meta)meta.textContent=error?.message||'The registered phone could not be checked.';}return null;}
}
const show=(el,open)=>{if(!el)return;el.classList.toggle('open',open);el.setAttribute('aria-hidden',open?'false':'true');document.body.classList.toggle('op-modal-open',!!document.querySelector('.hp-modal.open,.hp-drawer.open'));};
const alertUser=o=>window.Swal?Swal.fire(o):window.alert(o.text||o.title||'');
function setPhoneModalOpen(el,open){if(!el)return;el.inert=!open;el.classList.toggle('show',open);el.setAttribute('aria-hidden',open?'false':'true');document.body.classList.toggle('op-phone-open',open||phoneRegistrationModal?.classList.contains('show')||phoneOverviewModal?.classList.contains('show'));}
function setPhoneRegistrationStatus(type,title,detail){const status=document.getElementById('opPaymentsPhoneRegistrationStatus');if(!status)return;status.classList.toggle('success',type==='success');status.classList.toggle('error',type==='error');status.querySelector('strong').textContent=title;status.querySelector('small').textContent=detail;}
function stopPhoneRegistrationPolling(){if(phoneState.pollTimer)clearInterval(phoneState.pollTimer);phoneState.pollTimer=null;}
async function checkPhoneRegistration(manual=false){
  if(manual)setPhoneRegistrationStatus('','Checking registration…','Looking for a newly registered operator phone.');
  try{const result=await fetchPaymentPhone(),device=result.registered?result.device:null,baseline=phoneState.baseline,changed=device&&(!baseline||Number(device.device_id)!==Number(baseline.device_id)||String(device.last_used_at)!==String(baseline.last_used_at));renderPaymentPhone(result);if(changed){stopPhoneRegistrationPolling();setPhoneRegistrationStatus('success','Operator phone registered',`${device.device_name||"Operator's Phone"} is ready for payment QR notifications.`);alertUser({toast:true,position:'top-end',icon:'success',title:'Operator phone registration detected.',showConfirmButton:false,timer:2200});}else if(manual)setPhoneRegistrationStatus('','No new phone detected',`Checked ${new Date().toLocaleTimeString('en-PH')}. Finish registration on the phone, then check again.`);}catch(error){setPhoneRegistrationStatus('error','Could not check registration',error?.message||'The registered phone could not be checked.');}
}
async function openPhoneOverview(){await refreshPaymentPhone(true);setPhoneModalOpen(phoneOverviewModal,true);}
function closePhoneOverview(){setPhoneModalOpen(phoneOverviewModal,false);}
function openPhoneRegistration(){
  closePhoneOverview();show(modal,false);phoneState.baseline=phoneState.device?{...phoneState.device}:null;
  const input=document.getElementById('opPaymentsPhoneSetupUrl'),title=document.getElementById('opPaymentsPhoneModalTitle');if(input)input.value=paymentPhoneSetupUrl();if(title)title.textContent=phoneState.baseline?'Change Registered Operator Phone':'Register an Operator Phone';
  setPhoneRegistrationStatus('','Waiting for phone registration','Keep this window open while registering the phone.');setPhoneModalOpen(phoneRegistrationModal,true);refreshPaymentPhone(true);stopPhoneRegistrationPolling();phoneState.pollTimer=setInterval(checkPhoneRegistration,2500);
}
function closePhoneRegistration(){stopPhoneRegistrationPolling();setPhoneModalOpen(phoneRegistrationModal,false);refreshPaymentPhone(true);}
async function copyPhoneSetupUrl(){const input=document.getElementById('opPaymentsPhoneSetupUrl'),value=input?.value||paymentPhoneSetupUrl();try{await navigator.clipboard.writeText(value);alertUser({toast:true,position:'top-end',icon:'success',title:'Setup link copied.',showConfirmButton:false,timer:1800});}catch(_error){input?.select();alertUser({icon:'info',title:'Copy the Link',text:'Copy the selected setup address and open it on the operator phone.',confirmButtonColor:'#176b55'});}}
const bookingDetailIcons={guest:'<svg viewBox="0 0 24 24"><circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/></svg>',calendar:'<svg viewBox="0 0 24 24"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M8 3v4m8-4v4M3 10h18"/></svg>',map:'<svg viewBox="0 0 24 24"><path d="m9 18-6 3V6l6-3 6 3 6-3v15l-6 3-6-3Z"/><path d="M9 3v15m6-12v15"/></svg>',payment:'<svg viewBox="0 0 24 24"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 10h18M7 15h4"/></svg>'};
function bookingDetailRow(icon,label,value){return `<div class="ho-bd-detail-row"><span class="ho-bd-row-icon">${bookingDetailIcons[icon]||bookingDetailIcons.calendar}</span><div><small>${escapeHtml(label)}</small><strong>${escapeHtml(value||'—')}</strong></div></div>`;}
function closeReceivableDetails(){if(!receivableDrawer)return;receivableDrawer.classList.remove('show');receivableDrawer.setAttribute('aria-hidden','true');document.body.classList.remove('ho-booking-details-open');}
function openReceivableDetails(button){
  if(!receivableDrawer||!receivableBody)return;let data;try{data=JSON.parse(button.dataset.receivableDetails||'{}');}catch(_error){return;}
  const guest=String(data.guest||'Guest'),initials=guest.split(/\s+/).filter(Boolean).slice(0,2).map(part=>part[0]).join('').toUpperCase()||'G',status=String(data.status||'Pending'),statusClass=status.toLowerCase().replace(/[^a-z0-9-]/g,''),paid=String(data.paid||'₱0.00'),balance=String(data.balance||'₱0.00');
  receivableBody.innerHTML=`<div class="ho-bd-drawer-hero"><div class="ho-bd-reference"><span>BOOKING REFERENCE</span><strong>${escapeHtml(data.reference)}</strong></div><span class="ho-bd-status-pill ho-bd-status-${statusClass}">${escapeHtml(status)}</span></div>
  <section class="ho-bd-drawer-section ho-bd-guest-card"><div class="ho-bd-guest-avatar">${escapeHtml(initials)}</div><div class="ho-bd-guest-primary"><small>PRIMARY GUEST</small><h4>${escapeHtml(guest)}</h4><p>${escapeHtml(data.email||'No email address')}</p></div></section>
  <section class="ho-bd-drawer-section"><div class="ho-bd-section-title"><span>${bookingDetailIcons.guest}</span><div><h4>Guest information</h4><p>Contact details and party size</p></div></div><div class="ho-bd-detail-grid">${bookingDetailRow('guest','Contact number',data.phone)}${bookingDetailRow('guest','Guest count',`${Number(data.pax||0)} guest(s) · ${Number(data.adults||0)} adult(s) · ${Number(data.children||0)} child(ren)`)}</div></section>
  <section class="ho-bd-drawer-section"><div class="ho-bd-section-title"><span>${bookingDetailIcons.calendar}</span><div><h4>Trip information</h4><p>Package, schedule, and meeting details</p></div></div><div class="ho-bd-detail-grid">${bookingDetailRow('calendar','Selected package',data.service)}${bookingDetailRow('calendar','Booking type',data.type)}${bookingDetailRow('map','Destination',data.location)}${bookingDetailRow('calendar','Tour date',data.date)}${bookingDetailRow('map','Jump-off port',data.port)}${bookingDetailRow('calendar','Booked on',data.created)}</div></section>
  <section class="ho-bd-drawer-section ho-bd-payment-card"><div class="ho-bd-section-title"><span>${bookingDetailIcons.payment}</span><div><h4>Payment summary</h4><p>Current financial status of this booking</p></div></div><div class="ho-bd-payment-lines"><div><span>Grand total</span><strong>${escapeHtml(data.total)}</strong></div><div><span>Amount received</span><strong>${escapeHtml(paid)}</strong></div><div class="ho-bd-grand-total"><span>Remaining balance</span><strong>${escapeHtml(balance)}</strong></div></div><div class="ho-bd-payment-stats"><div><small>PAYMENT METHOD</small><strong>${escapeHtml(data.payment_method)}</strong></div><div><small>ACCOUNT AGING</small><strong class="hp-aging ${escapeHtml(data.aging_class)}">${escapeHtml(data.aging)}</strong></div></div></section>`;
  receivableDrawer.classList.add('show');receivableDrawer.setAttribute('aria-hidden','false');document.body.classList.add('ho-booking-details-open');receivableDrawer.querySelector('[data-close-receivable-details]')?.focus();
}
function syncBooking(){const option=booking?.selectedOptions?.[0],value=Number(option?.dataset.balance||0);if(!option?.value){selected.textContent='Choose an outstanding booking';balanceText.textContent='—';amount.value='';amount.removeAttribute('max');return;}selected.textContent=`${option.dataset.reference} · ${option.dataset.guest}`;balanceText.textContent=`${peso(value)} due`;amount.max=value.toFixed(2);amount.value=value.toFixed(2);}
function syncMethod(){const qr=method?.value==='qr_code';if(qrHelp)qrHelp.hidden=!qr;if(qr)refreshPaymentPhone();const label=submit?.querySelector('span');if(label)label.textContent=qr?'Send PayMongo QR':'Confirm Payment';}
function openCollect(id=''){if(booking&&id)booking.value=String(id);syncBooking();syncMethod();show(modal,true);setTimeout(()=>booking?.focus(),40);}
function loading(active,label=''){if(!submit)return;submit.disabled=active;submit.classList.toggle('is-loading',active);const span=submit.querySelector('span');if(span)span.textContent=active?label:(method?.value==='qr_code'?'Send PayMongo QR':'Confirm Payment');}
async function monitor(token,control={cancelled:false}){
  const started=Date.now();
  while(Date.now()-started<600000){
    await new Promise(r=>setTimeout(r,4000));
    if(control.cancelled)return;
    try{
      const response=await fetch(`${cfg.statusEndpoint}&token=${encodeURIComponent(token)}`,{credentials:'same-origin',cache:'no-store'});
      const result=await response.json().catch(()=>({}));
      if(control.cancelled)return;
      if(['paid','succeeded','completed'].includes(result.status)){
        await alertUser({icon:'success',title:'Payment received',text:'The booking balance and transaction ledger have been updated.',confirmButtonColor:'#176b55'});location.reload();return;
      }
      if(['failed','cancelled','expired'].includes(result.status)){
        alertUser({icon:'error',title:'Payment not completed',text:'The PayMongo payment was cancelled, expired, or failed.',confirmButtonColor:'#176b55'});return;
      }
    }catch(_error){/* A brief network interruption should not stop payment verification. */}
  }
  if(!control.cancelled)alertUser({icon:'info',title:'Confirmation pending',text:'PayMongo verification is taking longer than expected. Refresh this page shortly to see the updated transaction.',confirmButtonColor:'#176b55'});
}
async function collect(event){
  event.preventDefault();
  const option=booking?.selectedOptions?.[0],id=Number(option?.value||0),due=Number(option?.dataset.balance||0),paid=Number(amount?.value||0);
  if(!id||!Number.isFinite(paid)||paid<=0||paid>due+.001){
    alertUser({icon:'warning',title:'Check the payment amount',text:`Enter an amount from ₱1.00 up to ${peso(due)}.`,confirmButtonColor:'#176b55'});return;
  }
  try{
    if(method.value==='cash'){
      loading(true,'Recording payment…');
      const body=new URLSearchParams({action:'confirm_payment',booking_id:String(id),amount:paid.toFixed(2),payment_method:'cash',csrf_token:cfg.csrf});
      const response=await fetch(cfg.cashEndpoint,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8','X-Requested-With':'XMLHttpRequest'},body});
      const result=await response.json().catch(()=>({}));
      if(!response.ok||!result.success)throw new Error(result.message||'The cash payment could not be recorded.');
      show(modal,false);
      await alertUser({icon:'success',title:'Payment recorded',text:'The cash collection and booking balance were updated successfully.',confirmButtonColor:'#176b55'});
      location.reload();return;
    }
    loading(true,'Sending QR to phone…');
    const body=new FormData();body.set('type','tour');body.set('id',String(id));body.set('amount',paid.toFixed(2));body.set('csrf_token',cfg.csrf);
    const response=await fetch(cfg.checkoutEndpoint,{method:'POST',credentials:'same-origin',headers:{Accept:'application/json','X-Requested-With':'XMLHttpRequest'},body});
    const result=await response.json().catch(()=>({}));
    if(!response.ok||!result.success)throw new Error(result.message||'The PayMongo QR could not be sent.');
    const checkoutUrl=new URL(String(result.checkout_url||''));
    if(checkoutUrl.protocol!=='https:'||(checkoutUrl.hostname!=='checkout.paymongo.com'&&!checkoutUrl.hostname.endsWith('.paymongo.com')))throw new Error('PayMongo returned an invalid checkout address.');
    show(modal,false);
    if(result.phone_notification?.sent&&/^[a-f0-9]{64}$/.test(String(result.return_token||''))){
      const monitorControl={cancelled:false};
      const waiting=Swal.fire({title:'QR sent to payment phone',text:'Waiting for PayMongo to verify the tourist payment.',allowOutsideClick:false,allowEscapeKey:false,showConfirmButton:false,showCancelButton:true,cancelButtonText:'Cancel waiting',cancelButtonColor:'#687b75',didOpen:()=>Swal.showLoading()});
      monitor(result.return_token,monitorControl);
      const decision=await waiting;
      if(decision.dismiss===Swal.DismissReason.cancel){monitorControl.cancelled=true;await alertUser({icon:'info',title:'Payment waiting cancelled',text:'Payment monitoring has stopped. The PayMongo QR remains valid, and any verified payment will still be recorded.',confirmButtonText:'Okay',confirmButtonColor:'#176b55'});}
      return;
    }
    const fallback=await alertUser({icon:'warning',title:'Phone notification not sent',text:result.phone_notification?.message||'The QR was created but could not be delivered to the registered operator phone.',showCancelButton:true,confirmButtonText:'Open QR here',cancelButtonText:'Close',confirmButtonColor:'#176b55'});
    if(fallback?.isConfirmed)location.assign(checkoutUrl.href);
  }catch(error){
    alertUser({icon:'error',title:'Unable to collect payment',text:error?.message||'Please try again.',confirmButtonColor:'#176b55',footer:method?.value==='qr_code'?`<a href="${cfg.phoneSetupUrl}" target="_blank">Manage the registered payment phone</a>`:undefined});
  }finally{loading(false);}
}
document.querySelector('.op-payments-header .hp-payment-phone-header')?.addEventListener('click',event=>{event.preventDefault();openPhoneOverview();});
qrHelp?.querySelector('.op-qr-phone-change')?.addEventListener('click',openPhoneRegistration);
document.getElementById('opPaymentsPhoneOverviewAction')?.addEventListener('click',openPhoneRegistration);
document.querySelectorAll('[data-close-payments-phone-overview]').forEach(button=>button.addEventListener('click',closePhoneOverview));
document.querySelectorAll('[data-close-payments-phone]').forEach(button=>button.addEventListener('click',closePhoneRegistration));
phoneOverviewModal?.addEventListener('mousedown',event=>{if(event.target===phoneOverviewModal)closePhoneOverview();});
phoneRegistrationModal?.addEventListener('mousedown',event=>{if(event.target===phoneRegistrationModal)closePhoneRegistration();});
document.getElementById('opPaymentsCheckPhoneRegistration')?.addEventListener('click',()=>checkPhoneRegistration(true));
document.getElementById('opPaymentsOpenPhoneSetup')?.addEventListener('click',()=>window.open(paymentPhoneSetupUrl(),'_blank','noopener'));
document.getElementById('opPaymentsCopyPhoneSetupUrl')?.addEventListener('click',copyPhoneSetupUrl);
document.querySelectorAll('[data-receivable-details]').forEach(button=>button.addEventListener('click',()=>openReceivableDetails(button)));
document.querySelectorAll('[data-close-receivable-details]').forEach(button=>button.addEventListener('click',closeReceivableDetails));
receivableDrawer?.addEventListener('mousedown',event=>{if(event.target===receivableDrawer)closeReceivableDetails();});
document.querySelectorAll('[data-finance-tab]').forEach(button=>button.addEventListener('click',()=>{const target=button.dataset.financeTab;document.querySelectorAll('[data-finance-tab]').forEach(item=>item.classList.toggle('active',item===button));document.querySelectorAll('[data-finance-panel]').forEach(panel=>panel.hidden=panel.dataset.financePanel!==target);}));
document.querySelectorAll('[data-kpi-target]').forEach(card=>card.addEventListener('click',()=>{document.querySelector(`[data-finance-tab="${card.dataset.kpiTarget}"]`)?.click();document.getElementById('transactions')?.scrollIntoView({behavior:'smooth',block:'start'});}));document.querySelector('[data-jump-receivables]')?.addEventListener('click',()=>{document.querySelector('[data-finance-tab="receivables"]')?.click();document.getElementById('transactions')?.scrollIntoView({behavior:'smooth'});});
document.querySelector('[data-open-collect]')?.addEventListener('click',()=>openCollect());document.querySelectorAll('[data-collect-booking]').forEach(button=>button.addEventListener('click',()=>openCollect(button.dataset.collectBooking)));document.querySelectorAll('[data-close-collect]').forEach(button=>button.addEventListener('click',()=>show(modal,false)));booking?.addEventListener('change',syncBooking);method?.addEventListener('change',syncMethod);form?.addEventListener('submit',collect);
document.querySelectorAll('[data-transaction]').forEach(button=>button.addEventListener('click',()=>{
  try{current=JSON.parse(button.dataset.transaction);}catch{current=null;}if(!current)return;
  const set=(selector,value)=>{const el=drawer.querySelector(selector);if(el)el.textContent=value||'—';};
  set('[data-detail-reference]',current.reference);set('[data-detail-amount]',current.amount);set('[data-detail-booking]',current.booking_reference);set('[data-detail-guest]',current.guest);set('[data-detail-service]',`${current.service} · ${current.booking_type}`);set('[data-detail-channel]',current.channel);set('[data-detail-method]',current.method);set('[data-detail-date]',current.date);set('[data-detail-source]',current.source);
  const status=drawer.querySelector('[data-detail-status]');if(status){status.className=`hp-status ${current.status_class||''}`;status.innerHTML=`<i></i>${escapeHtml(current.status)}`;}
  if(viewReceipt)viewReceipt.hidden=current.status_class!=='paid';show(drawer,true);
}));
document.querySelectorAll('[data-close-transaction]').forEach(button=>button.addEventListener('click',()=>show(drawer,false)));

function setReceiptOpen(open){
  if(!receiptModal)return;receiptModal.inert=!open;receiptModal.classList.toggle('show',open);receiptModal.setAttribute('aria-hidden',open?'false':'true');
  document.body.classList.toggle('op-modal-open',open||drawer?.classList.contains('open')||modal?.classList.contains('open'));
}
function openReceipt(){
  if(!current||current.status_class!=='paid'||!receiptPaper)return;
  const logo=new URL(`${cfg.assetBase||''}img/newlogo.png`,location.href).href,wordmark=new URL(`${cfg.assetBase||''}img/textlogo2.png`,location.href).href;
  receiptPaper.innerHTML=`<div class="receipt-brand"><div class="receipt-brand-identity"><img class="receipt-brand-logo" src="${logo}" alt="iTour Mercedes seal"><div><img class="receipt-brand-wordmark" src="${wordmark}" alt="iTour Mercedes"><p>OFFICIAL PAYMENT RECEIPT</p></div></div><div class="receipt-number"><small>RECEIPT REFERENCE</small><strong>${escapeHtml(current.receipt_reference||current.reference)}</strong><span class="receipt-paid-stamp">PAID</span></div></div><div class="receipt-meta"><div><small>PAID BY</small><strong>${escapeHtml(current.guest)}</strong></div><div><small>SERVICE</small><strong>${escapeHtml(current.service)}</strong></div><div><small>BOOKING REFERENCE</small><strong>${escapeHtml(current.booking_reference)}</strong></div><div><small>BOOKING TYPE</small><strong>${escapeHtml(current.booking_type)}</strong></div></div><table class="receipt-table"><thead><tr><th>DESCRIPTION</th><th>AMOUNT</th></tr></thead><tbody><tr><td>Payment received for ${escapeHtml(current.service)}</td><td>${escapeHtml(current.amount)}</td></tr><tr class="receipt-total"><td>TOTAL PAYMENT</td><td>${escapeHtml(current.amount)}</td></tr></tbody></table><div class="receipt-meta receipt-payment-meta"><div><small>PAYMENT DATE</small><strong>${escapeHtml(current.date)}</strong></div><div><small>PAYMENT METHOD</small><strong>${escapeHtml(current.method)}</strong></div><div><small>PAYMENT CHANNEL</small><strong>${escapeHtml(current.channel)}</strong></div><div><small>COLLECTED THROUGH</small><strong>${escapeHtml(current.source)}</strong></div></div><div class="receipt-foot">This receipt confirms only the payment transaction shown above. It is not a current booking balance statement. Please retain it as your official payment record.</div>`;
  setReceiptOpen(true);
}
function printReceipt(){
  if(!receiptPaper?.innerHTML.trim())return;const win=open('','_blank','width=900,height=720');if(!win){alertUser({icon:'warning',title:'Pop-up blocked',text:'Allow pop-ups for this site, then try printing again.'});return;}
  const stylesheet=Array.from(document.styleSheets).find(sheet=>String(sheet.href||'').includes('styles/admin_receipt.css'))?.href||new URL(`${cfg.assetBase||''}styles/admin_receipt.css`,location.href).href;
  win.document.write(`<!doctype html><html><head><title>Payment Receipt</title><link rel="stylesheet" href="${stylesheet}"><style>body{margin:0;padding:20px;background:#fff}.admin-receipt-paper{width:560px;min-height:0;margin:auto;box-shadow:none}@page{size:A4 portrait;margin:12mm}</style></head><body><article class="admin-receipt-paper">${receiptPaper.innerHTML}</article></body></html>`);win.document.close();win.addEventListener('load',()=>setTimeout(()=>{win.focus();win.print();},200));
}
async function downloadReceipt(){
  const button=document.getElementById('opDownloadReceipt');if(!receiptPaper?.innerHTML.trim()||!window.html2canvas||!window.jspdf?.jsPDF){alertUser({icon:'error',title:'Download unavailable',text:'Refresh the page and try again.'});return;}
  button.disabled=true;const label=button.textContent;button.textContent='Preparing…';
  try{const canvas=await html2canvas(receiptPaper,{scale:2,backgroundColor:'#fff',useCORS:true});const {jsPDF}=window.jspdf,pdf=new jsPDF({orientation:'portrait',unit:'mm',format:'a4'}),scale=Math.min(190/canvas.width,277/canvas.height),width=canvas.width*scale,height=canvas.height*scale;pdf.addImage(canvas.toDataURL('image/png'),'PNG',(210-width)/2,10,width,height,undefined,'FAST');const reference=String(current?.receipt_reference||'payment').replace(/[^a-z0-9_-]+/gi,'-');pdf.save(`payment-receipt-${reference}.pdf`);}catch(_error){alertUser({icon:'error',title:'Download failed',text:'The receipt PDF could not be prepared.'});}finally{button.disabled=false;button.textContent=label;}
}
viewReceipt?.addEventListener('click',openReceipt);document.querySelectorAll('[data-close-receipt]').forEach(button=>button.addEventListener('click',()=>setReceiptOpen(false)));receiptModal?.addEventListener('mousedown',event=>{if(event.target===receiptModal)setReceiptOpen(false);});document.getElementById('opPrintReceipt')?.addEventListener('click',printReceipt);document.getElementById('opDownloadReceipt')?.addEventListener('click',downloadReceipt);
const notifToggle=document.getElementById('opNotifToggle'),notifPanel=document.getElementById('opNotifPanel');let notificationsMarked=false;
notifToggle?.addEventListener('click',async event=>{event.stopPropagation();const open=notifPanel?.classList.toggle('open')||false;notifToggle.setAttribute('aria-expanded',open?'true':'false');if(open&&!notificationsMarked){notificationsMarked=true;document.querySelector('.op-notif-badge')?.remove();const body=new URLSearchParams({op_action:'mark_notifications_read',csrf_token:String(window.operatorNotificationCsrf||'')});try{await fetch(location.pathname,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/x-www-form-urlencoded'},body});}catch(_error){/* The panel remains usable if the read marker cannot be saved. */}}});
document.addEventListener('click',event=>{if(notifPanel?.classList.contains('open')&&!notifPanel.contains(event.target)){notifPanel.classList.remove('open');notifToggle?.setAttribute('aria-expanded','false');}});
document.addEventListener('keydown',event=>{if(event.key!=='Escape')return;if(receivableDrawer?.classList.contains('show')){closeReceivableDetails();return;}if(phoneRegistrationModal?.classList.contains('show')){closePhoneRegistration();return;}if(phoneOverviewModal?.classList.contains('show')){closePhoneOverview();return;}if(receiptModal?.classList.contains('show')){setReceiptOpen(false);return;}show(modal,false);show(drawer,false);});
})();
