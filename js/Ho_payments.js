document.addEventListener('DOMContentLoaded', () => {
  const modal = document.getElementById('hpCollectModal');
  const drawer = document.getElementById('hpTransactionDrawer');
  const select = document.getElementById('hpBookingSelect');
  const amount = document.getElementById('hpPaymentAmount');
  const selected = document.getElementById('hpSelectedBooking');
  const method = document.getElementById('hpPaymentMethod');
  const confirmPayment = document.getElementById('hpConfirmPayment');
  const paymentConfig = window.hoPaymentConfig || {};
  let phonePollTimer = null;

  const openModal = () => {
    if (!modal) return;
    modal.classList.add('open'); modal.setAttribute('aria-hidden', 'false');
    document.body.style.overflow = 'hidden';
    if (method) method.value = 'cash';
    syncPaymentMethod();
    setTimeout(() => select?.focus(), 30);
  };
  const stopPhonePolling = () => { if (phonePollTimer) clearInterval(phonePollTimer); phonePollTimer = null; };
  const closeModal = () => { stopPhonePolling(); modal?.classList.remove('open'); modal?.setAttribute('aria-hidden', 'true'); document.body.style.overflow = ''; };
  const syncBooking = () => {
    const option = select?.selectedOptions[0];
    const balance = Number(option?.dataset.balance || 0);
    if (amount) { amount.max = balance > 0 ? balance.toFixed(2) : ''; amount.value = balance > 0 ? balance.toFixed(2) : ''; }
    if (selected) selected.innerHTML = balance > 0
      ? `<span>${option.dataset.reference || 'Booking'} · ${option.dataset.guest || 'Guest'}</span><strong>Balance ₱${balance.toLocaleString('en-PH',{minimumFractionDigits:2})}</strong>`
      : '<span>Select an outstanding booking</span><strong>Current balance will appear here</strong>';
  };
  document.querySelectorAll('[data-open-collect]').forEach(button => button.addEventListener('click', openModal));
  document.querySelectorAll('[data-close-collect]').forEach(button => button.addEventListener('click', closeModal));
  select?.addEventListener('change', syncBooking);
  amount?.addEventListener('input', () => { const max=Number(amount.max||0); if(max&&Number(amount.value)>max) amount.value=max.toFixed(2); });
  document.querySelectorAll('[data-collect-booking]').forEach(button => button.addEventListener('click', () => {
    if (select) { select.value = button.dataset.collectBooking || ''; syncBooking(); } openModal();
  }));
  const readJson = async response => {
    if (!(response.headers.get('content-type') || '').includes('application/json')) throw new Error('The payment service returned an unexpected response.');
    return response.json();
  };
  const refreshPhone = async (silent = false) => {
    const name = document.getElementById('hpPhoneName');
    const meta = document.getElementById('hpPhoneMeta');
    if (!silent) { name.textContent = 'Checking registered phone…'; meta.textContent = 'Please wait.'; }
    try {
      const response = await fetch(paymentConfig.phoneStatusEndpoint, {credentials:'same-origin',cache:'no-store',headers:{Accept:'application/json'}});
      const data = await readJson(response);
      if (!response.ok || !data.success) throw new Error(data.message || 'The payment phone could not be checked.');
      if (data.registered && data.device) {
        const registered = new Date(String(data.device.last_used_at || data.device.created_at || '').replace(' ','T'));
        name.textContent = data.device.device_name || 'Hotel administrator phone';
        meta.textContent = Number.isNaN(registered.getTime()) ? 'Notifications active.' : `Notifications active · Registered ${registered.toLocaleString()}`;
      } else {
        name.textContent = 'No hotel phone registered'; meta.textContent = 'Register a phone before sending PayMongo QR notifications.';
      }
      return Boolean(data.registered);
    } catch (error) {
      if (!silent) { name.textContent = 'Unable to check phone'; meta.textContent = error.message; }
      return false;
    }
  };
  function syncPaymentMethod() {
    const isQr = method?.value === 'qr_code';
    document.getElementById('hpPhoneStatus').hidden = !isQr;
    document.getElementById('hpPaymentContext').textContent = isQr
      ? 'PayMongo will send a secure QR to the registered hotel-admin phone. The balance updates only after verification.'
      : 'Record the amount received. Partial payments are allowed.';
    if (confirmPayment) confirmPayment.textContent = isQr ? 'Open PayMongo QR' : 'Confirm payment';
    stopPhonePolling();
    if (isQr) { refreshPhone(); phonePollTimer = setInterval(() => refreshPhone(true), 2500); }
  }
  method?.addEventListener('change', syncPaymentMethod);
  document.body.insertAdjacentHTML('beforeend', `<div class="hp-phone-overview" id="hpPhoneOverview" aria-hidden="true" inert><div class="hp-modal-backdrop" data-close-phone-overview></div><section class="hp-phone-overview-card" role="dialog" aria-modal="true" aria-labelledby="hpPhoneOverviewTitle"><header><span><svg viewBox="0 0 24 24"><rect x="6" y="2" width="12" height="20" rx="2"/><path d="M10 18h4"/></svg></span><div><small>PAYMENT NOTIFICATIONS</small><h3 id="hpPhoneOverviewTitle">Payment Phone</h3><p>The registered phone receives secure PayMongo QR notifications.</p></div><button type="button" data-close-phone-overview aria-label="Close">×</button></header><div class="hp-phone-overview-body"><section class="hp-phone-overview-device"><span><svg viewBox="0 0 24 24"><rect x="6" y="2" width="12" height="20" rx="2"/><path d="M10 18h4"/></svg></span><div><small>REGISTERED PAYMENT PHONE</small><strong id="hpOverviewPhoneName">Checking registered phone…</strong><p id="hpOverviewPhoneMeta">Please wait.</p></div><button type="button" id="hpOverviewPhoneChange">Change</button></section></div><footer><button class="hp-btn secondary" type="button" data-close-phone-overview>Close</button></footer></section></div>`);
  document.body.insertAdjacentHTML('beforeend', `<div class="hp-phone-modal" id="hpPhoneModal" aria-hidden="true" inert><div class="hp-modal-backdrop" data-close-phone-modal></div><section class="hp-phone-card" role="dialog" aria-modal="true" aria-labelledby="hpPhoneModalTitle"><header><span class="hp-phone-card-icon"><svg viewBox="0 0 24 24"><rect x="6" y="2" width="12" height="20" rx="2"/><path d="M10 18h4"/></svg></span><div><small>HOTEL ADMINISTRATOR DEVICE</small><h3 id="hpPhoneModalTitle">Change Registered Hotel Phone</h3><p>Complete these steps using the phone that should display payment QR notifications.</p></div><button type="button" data-close-phone-modal aria-label="Close">×</button></header><div class="hp-phone-card-body"><section class="hp-current-phone"><span><svg viewBox="0 0 24 24"><rect x="6" y="2" width="12" height="20" rx="2"/><path d="M10 18h4"/></svg></span><div><small>CURRENT PAYMENT PHONE</small><strong id="hpModalPhoneName">Checking registered phone…</strong><p id="hpModalPhoneMeta">Please wait.</p></div><b id="hpModalPhoneState">Checking</b></section><ol class="hp-phone-steps"><li><i>1</i><div><strong>Open the setup address on the hotel phone.</strong><span>Log in with the same hotel administrator account when asked.</span></div></li><li><i>2</i><div><strong>Name and register the phone.</strong><span>Tap Register This Phone and allow browser notifications.</span></div></li><li><i>3</i><div><strong>Return to this computer.</strong><span>This window detects the newly registered phone automatically.</span></div></li></ol><label class="hp-setup-address"><span>PHONE SETUP ADDRESS</span><div><input type="text" id="hpPhoneSetupUrl" readonly><button type="button" id="hpCopyPhoneUrl">Copy Link</button></div></label><div class="hp-registration-wait" id="hpRegistrationWait"><i></i><div><strong>Waiting for phone registration</strong><span>Keep this window open while registering the phone.</span></div></div></div><footer><button class="hp-btn secondary" type="button" data-close-phone-modal>Close</button><button class="hp-btn secondary" type="button" id="hpCheckPhoneAgain">Check Again</button><button class="hp-btn primary" type="button" id="hpOpenPhoneSetup">Open Setup Page</button></footer></section></div>`);
  const phoneOverview = document.getElementById('hpPhoneOverview');
  const phoneModal = document.getElementById('hpPhoneModal');
  let registrationTimer = null;
  let registrationBaseline = '';
  const phoneSetupUrl = (() => {
    try {
      if (paymentConfig.publicAppUrl) {
        const base = new URL(paymentConfig.publicAppUrl);
        base.pathname = base.pathname.endsWith('/') ? base.pathname : `${base.pathname}/`;
        base.search = ''; base.hash = '';
        return new URL(paymentConfig.phoneSetupPage, base).href;
      }
    } catch (_) {}
    return new URL(paymentConfig.phoneSetupPage, window.location.href).href;
  })();
  document.getElementById('hpPhoneSetupUrl').value = phoneSetupUrl;
  const phoneSignature = data => data?.registered && data.device ? `${data.device.device_id}:${data.device.last_used_at || data.device.created_at || ''}` : '';
  const loadPhoneModalStatus = async (detectChange = false) => {
    const response = await fetch(paymentConfig.phoneStatusEndpoint,{credentials:'same-origin',cache:'no-store',headers:{Accept:'application/json'}});
    const data = await readJson(response); if(!response.ok || !data.success) throw new Error(data.message || 'Unable to check the registered phone.');
    const name=document.getElementById('hpModalPhoneName'), meta=document.getElementById('hpModalPhoneMeta'), state=document.getElementById('hpModalPhoneState');
    if(data.registered && data.device){const date=new Date(String(data.device.last_used_at||data.device.created_at||'').replace(' ','T'));name.textContent=data.device.device_name||'Hotel administrator phone';meta.textContent=Number.isNaN(date.getTime())?'Notifications active.':`Notifications active · Registered ${date.toLocaleString()}`;state.textContent='Registered';}
    else{name.textContent='No payment phone registered';meta.textContent='Use the setup address below to register one.';state.textContent='Not registered';}
    const signature=phoneSignature(data);
    if(detectChange && signature && signature!==registrationBaseline){registrationBaseline=signature;const wait=document.getElementById('hpRegistrationWait');wait.classList.add('success');wait.querySelector('strong').textContent='New phone registered';wait.querySelector('span').textContent='The hotel payment phone has been updated successfully.';stopRegistrationPolling();refreshPhone(true);renderPhoneOverview(data);}
    return data;
  };
  const renderPhoneOverview = data => {
    const name = document.getElementById('hpOverviewPhoneName');
    const meta = document.getElementById('hpOverviewPhoneMeta');
    const action = document.getElementById('hpOverviewPhoneChange');
    if (data?.registered && data.device) {
      const date = new Date(String(data.device.last_used_at || data.device.created_at || '').replace(' ', 'T'));
      name.textContent = data.device.device_name || 'Hotel administrator phone';
      meta.textContent = Number.isNaN(date.getTime()) ? 'Notifications active.' : `Notifications active · Registered ${date.toLocaleString()}`;
      action.textContent = 'Change';
    } else {
      name.textContent = 'No payment phone registered';
      meta.textContent = 'Register a phone before sending PayMongo QR notifications.';
      action.textContent = 'Register Phone';
    }
  };
  const loadPhoneOverviewStatus = async () => {
    const data = await loadPhoneModalStatus();
    renderPhoneOverview(data);
    return data;
  };
  const stopRegistrationPolling=()=>{if(registrationTimer)clearInterval(registrationTimer);registrationTimer=null;};
  const closePhoneOverview=()=>{phoneOverview.classList.remove('open');phoneOverview.setAttribute('aria-hidden','true');phoneOverview.inert=true;document.body.style.overflow=modal?.classList.contains('open')?'hidden':'';};
  const openPhoneOverview=async event=>{const button=event?.currentTarget;const original=button?.innerHTML;if(button){button.disabled=true;button.classList.add('is-loading');button.innerHTML='<i class="hp-header-phone-spinner" aria-hidden="true"></i><span>Checking Phone…</span>';}try{await loadPhoneOverviewStatus();phoneOverview.inert=false;phoneOverview.classList.add('open');phoneOverview.setAttribute('aria-hidden','false');document.body.style.overflow='hidden';}catch(error){document.getElementById('hpOverviewPhoneName').textContent='Unable to check phone';document.getElementById('hpOverviewPhoneMeta').textContent=error.message;phoneOverview.inert=false;phoneOverview.classList.add('open');phoneOverview.setAttribute('aria-hidden','false');document.body.style.overflow='hidden';}finally{if(button){button.disabled=false;button.classList.remove('is-loading');button.innerHTML=original;}}};
  const closePhoneModal=()=>{stopRegistrationPolling();phoneModal.classList.remove('open');phoneModal.setAttribute('aria-hidden','true');phoneModal.inert=true;document.body.style.overflow=modal?.classList.contains('open')?'hidden':'';};
  const openPhoneModal=async()=>{stopPhonePolling();phoneModal.inert=false;phoneModal.classList.add('open');phoneModal.setAttribute('aria-hidden','false');document.body.style.overflow='hidden';const wait=document.getElementById('hpRegistrationWait');wait.classList.remove('success');wait.querySelector('strong').textContent='Waiting for phone registration';wait.querySelector('span').textContent='Keep this window open while registering the phone.';try{const data=await loadPhoneModalStatus();registrationBaseline=phoneSignature(data);}catch(error){document.getElementById('hpModalPhoneName').textContent='Unable to check phone';document.getElementById('hpModalPhoneMeta').textContent=error.message;}registrationTimer=setInterval(()=>loadPhoneModalStatus(true).catch(()=>{}),2000);};
  document.getElementById('hpManagePhone')?.addEventListener('click', openPhoneModal);
  document.getElementById('hpOpenPaymentPhone')?.addEventListener('click', openPhoneOverview);
  phoneOverview.querySelectorAll('[data-close-phone-overview]').forEach(button=>button.addEventListener('click',closePhoneOverview));
  document.getElementById('hpOverviewPhoneChange')?.addEventListener('click',()=>{closePhoneOverview();openPhoneModal();});
  phoneModal.querySelectorAll('[data-close-phone-modal]').forEach(button=>button.addEventListener('click',closePhoneModal));
  document.getElementById('hpCheckPhoneAgain')?.addEventListener('click',()=>loadPhoneModalStatus(true).catch(error=>{document.getElementById('hpModalPhoneMeta').textContent=error.message;}));
  document.getElementById('hpCopyPhoneUrl')?.addEventListener('click',async event=>{try{await navigator.clipboard.writeText(phoneSetupUrl);event.currentTarget.textContent='Copied';setTimeout(()=>event.currentTarget.textContent='Copy Link',1400);}catch(_){const input=document.getElementById('hpPhoneSetupUrl');input.select();document.execCommand('copy');}});
  document.getElementById('hpOpenPhoneSetup')?.addEventListener('click',()=>window.open(phoneSetupUrl,'_blank','noopener'));
  const cancelPendingQr = async (bookingId, returnToken) => {
    const form = new FormData(); form.append('action','cancel_pending'); form.append('type','hotel'); form.append('id',String(bookingId)); form.append('return_token',String(returnToken)); form.append('csrf_token',paymentConfig.csrf || '');
    const response=await fetch(paymentConfig.checkoutEndpoint,{method:'POST',body:form,credentials:'same-origin',headers:{Accept:'application/json'}});
    const data=await readJson(response); if(!response.ok||!data.success)throw new Error(data.message||'The pending QR payment could not be cancelled.');
    return Boolean(data.cancelled);
  };
  const monitorQr = async (token, bookingId) => {
    let cancelRequested=false;
    if(window.Swal)Swal.fire({title:'QR Sent to Hotel Phone',text:'Waiting for the guest payment. The balance updates automatically after PayMongo verifies it.',allowOutsideClick:false,allowEscapeKey:false,showCancelButton:true,showConfirmButton:false,cancelButtonText:'Cancel Payment',cancelButtonColor:'#b5444f',didOpen:()=>Swal.showLoading()}).then(result=>{if(result.dismiss===Swal.DismissReason.cancel)cancelRequested=true;});
    for (let attempt=0; attempt<120; attempt+=1) {
      if(cancelRequested){try{Swal.fire({title:'Cancelling Payment',text:'Closing the pending PayMongo checkout…',allowOutsideClick:false,allowEscapeKey:false,showConfirmButton:false,didOpen:()=>Swal.showLoading()});const cancelled=await cancelPendingQr(bookingId,token);await Swal.fire({icon:cancelled?'info':'warning',title:cancelled?'Payment Cancelled':'Nothing to Cancel',text:cancelled?'The pending PayMongo payment was cancelled and logged in transactions. No amount was applied to the booking.':'No active pending PayMongo payment was found. Refresh the page to confirm its latest status.',confirmButtonColor:'#176b55'});if(cancelled)window.location.reload();}catch(error){await Swal.fire('Cancellation Not Confirmed',error.message||'The pending payment could not be cancelled. Check its latest status before trying again.','warning');}return;}
      try {
        const response=await fetch(`${paymentConfig.statusEndpoint}&token=${encodeURIComponent(token)}`,{cache:'no-store',headers:{Accept:'application/json'}}); const data=await readJson(response);
        if (response.ok && data.success && data.status==='paid') { if(window.Swal) Swal.close(); await Swal.fire({icon:'success',title:'QR Payment Verified',text:`${data.booking_reference || 'The booking'} was updated successfully.`,confirmButtonColor:'#176b55'}); window.location.reload(); return; }
        if(response.ok&&data.success&&data.status==='cancelled'){if(window.Swal)Swal.close();await Swal.fire('Payment Cancelled','The pending PayMongo payment was cancelled and logged in transactions. No amount was applied to the booking.','info');window.location.reload();return;}
        if (response.ok && data.success && ['failed','expired'].includes(data.status)) { if(window.Swal) Swal.close(); await Swal.fire('Payment Not Completed','No amount was applied to the booking.','warning'); window.location.reload(); return; }
      } catch (_) {}
      await new Promise(resolve=>setTimeout(resolve,2500));
    }
    if(window.Swal) Swal.close(); await Swal.fire('Confirmation Pending','PayMongo confirmation is taking longer than expected. Refresh this page shortly.','info');
  };
  const startQrPayment = async () => {
    const bookingId=Number(select?.value||0), paymentAmount=Number(amount?.value||0), balance=Number(select?.selectedOptions[0]?.dataset.balance||0);
    if (!bookingId || paymentAmount<=0 || paymentAmount>balance+.009) throw new Error('Choose a booking and enter an amount within its outstanding balance.');
    if (!(await refreshPhone())) throw new Error('Register a hotel payment phone before using QR Code (PayMongo).');
    const form=new FormData(); form.append('type','hotel'); form.append('id',String(bookingId)); form.append('amount',paymentAmount.toFixed(2)); form.append('csrf_token',paymentConfig.csrf||'');
    const response=await fetch(paymentConfig.checkoutEndpoint,{method:'POST',body:form,headers:{Accept:'application/json'}}); const data=await readJson(response);
    if (!response.ok || !data.success) throw new Error(data.message || 'The PayMongo QR could not be prepared.');
    if (!data.phone_notification?.sent) throw new Error(data.phone_notification?.message || 'The QR could not be delivered to the registered phone.');
    const token=String(data.return_token||''); if(!/^[a-f0-9]{64}$/.test(token)) throw new Error('PayMongo returned an invalid payment token.');
    closeModal(); await monitorQr(token,bookingId);
  };
  document.getElementById('hpCollectForm')?.addEventListener('submit', async event => {
    if (!select?.value || Number(amount?.value || 0) <= 0) { event.preventDefault(); amount?.focus(); return; }
    if (method?.value !== 'qr_code') return;
    event.preventDefault(); const original=confirmPayment.textContent; confirmPayment.disabled=true; confirmPayment.textContent='Sending QR…';
    try { await startQrPayment(); } catch(error) { if(window.Swal) await Swal.fire('QR Payment Failed',error.message,'error'); else alert(error.message); }
    finally { confirmPayment.disabled=false; confirmPayment.textContent=original; }
  });

  const tabs = document.querySelectorAll('[data-finance-tab]');
  const panels = document.querySelectorAll('[data-finance-panel]');
  const activateTab = name => {
    tabs.forEach(tab => tab.classList.toggle('active', tab.dataset.financeTab === name));
    panels.forEach(panel => panel.hidden = panel.dataset.financePanel !== name);
  };
  tabs.forEach(tab => tab.addEventListener('click', () => activateTab(tab.dataset.financeTab)));
  const openKpiTarget = card => {
    const target = card?.dataset.kpiTarget || 'transactions';
    activateTab(target);
    document.getElementById('transactions')?.scrollIntoView({behavior:'smooth',block:'start'});
  };
  document.querySelectorAll('[data-kpi-target]').forEach(card => {
    card.addEventListener('click', () => openKpiTarget(card));
    card.addEventListener('keydown', event => {
      if (event.key === 'Enter' || event.key === ' ') { event.preventDefault(); openKpiTarget(card); }
    });
  });
  document.querySelector('[data-jump-receivables]')?.addEventListener('click', () => { activateTab('receivables'); document.getElementById('transactions')?.scrollIntoView({behavior:'smooth',block:'start'}); });

  const esc = value => String(value ?? '').replace(/[&<>"']/g, char => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[char]));
  const bookingDetails = document.getElementById('hpBookingDetails');
  const bookingDetailsBody = document.getElementById('hpBookingDetailsBody');
  const bookingIcons = {
    guest:'<svg viewBox="0 0 24 24"><path d="M20 21a8 8 0 0 0-16 0"/><circle cx="12" cy="7" r="4"/></svg>',
    calendar:'<svg viewBox="0 0 24 24"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 10h18"/></svg>',
    hotel:'<svg viewBox="0 0 24 24"><path d="M4 21V5a2 2 0 0 1 2-2h12a2 2 0 0 1 2 2v16M2 21h20M8 7h2M14 7h2M8 11h2M14 11h2M9 21v-5h6v5"/></svg>',
    users:'<svg viewBox="0 0 24 24"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/></svg>',
    payment:'<svg viewBox="0 0 24 24"><rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20M6 15h2"/></svg>',
    note:'<svg viewBox="0 0 24 24"><path d="M5 3h14a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2H9l-5 4V5a2 2 0 0 1 1-2Z"/><path d="M8 8h8M8 12h5"/></svg>'
  };
  const bookingMoney = value => `₱${Number(value || 0).toLocaleString('en-PH',{minimumFractionDigits:2,maximumFractionDigits:2})}`;
  const bookingDate = (value, includeTime=false) => { if(!value)return '—';const date=new Date(includeTime?String(value).replace(' ','T'):`${value}T00:00:00`);return Number.isNaN(date.getTime())?String(value):date.toLocaleDateString('en-PH',{year:'numeric',month:'long',day:'numeric',...(includeTime?{hour:'numeric',minute:'2-digit'}:{})}); };
  const bookingRow = (icon,label,value) => `<div class="ho-bd-detail-row"><span class="ho-bd-row-icon">${bookingIcons[icon]}</span><div><small>${esc(label)}</small><strong>${esc(value || '—')}</strong></div></div>`;
  const closeBookingDetails = () => { bookingDetails?.classList.remove('show');bookingDetails?.setAttribute('aria-hidden','true');if(bookingDetails)bookingDetails.inert=true;document.body.classList.remove('ho-booking-details-open'); };
  document.querySelectorAll('[data-view-booking]').forEach(button=>button.addEventListener('click',()=>{
    const data=JSON.parse(button.dataset.viewBooking||'{}');
    const status=String(data.booking_status||'pending');
    const statusClass=status.toLowerCase().replace(/[^a-z0-9-]/g,'');
    const paymentStatus=String(data.payment_status||'unpaid');
    const paymentClass=paymentStatus.toLowerCase().replace(/[^a-z0-9-]/g,'');
    const guest=String(data.guest||'Guest');
    const initials=guest.split(/\s+/).filter(Boolean).slice(0,2).map(part=>part[0]).join('').toUpperCase()||'G';
    const avatar=String(data.profile_image||'').trim()?`<img src="${esc(data.profile_image)}" alt="${esc(guest)} profile picture" loading="lazy" decoding="async" referrerpolicy="no-referrer" onerror="this.remove();this.parentElement.textContent='${esc(initials)}';">`:esc(initials);
    const guestTotal=Number(data.adults||0)+Number(data.children||0);
    bookingDetailsBody.innerHTML=`<div class="ho-bd-drawer-hero"><div class="ho-bd-reference"><span>BOOKING ID</span><strong>${esc(data.id)}</strong></div><span class="ho-bd-status-pill ho-bd-status-${statusClass}">${esc(status)}</span></div><section class="ho-bd-drawer-section ho-bd-guest-card"><div class="ho-bd-guest-avatar">${avatar}</div><div class="ho-bd-guest-primary"><small>PRIMARY GUEST</small><h4>${esc(guest)}</h4><p>${esc(data.email||'—')}</p></div></section><section class="ho-bd-drawer-section"><div class="ho-bd-section-title"><span>${bookingIcons.guest}</span><div><h4>Guest information</h4><p>Contact details used for this reservation</p></div></div><div class="ho-bd-detail-grid">${bookingRow('guest','Contact number',data.phone)}${bookingRow('users','Guest count',`${guestTotal} total · ${data.adults||0} adult(s), ${data.children||0} child(ren)`)}</div></section><section class="ho-bd-drawer-section"><div class="ho-bd-section-title"><span>${bookingIcons.hotel}</span><div><h4>Stay information</h4><p>Property, room, and reservation schedule</p></div></div><div class="ho-bd-detail-grid">${bookingRow('hotel','Hotel',data.hotel)}${bookingRow('hotel','Room type',data.room_type)}${bookingRow('calendar','Check-in',bookingDate(data.checkin))}${bookingRow('calendar','Check-out',bookingDate(data.checkout))}${bookingRow('hotel','Rooms booked',data.rooms)}${bookingRow('calendar','Booked on',bookingDate(data.created_at,true))}</div></section><section class="ho-bd-drawer-section ho-bd-payment-card"><div class="ho-bd-section-title"><span>${bookingIcons.payment}</span><div><h4>Payment summary</h4><p>Current financial status of this booking</p></div></div><div class="ho-bd-payment-lines"><div><span>Total booking amount</span><strong>${bookingMoney(data.total)}</strong></div><div><span>Amount received</span><strong>${bookingMoney(data.amount_paid)}</strong></div><div class="ho-bd-grand-total"><span>Remaining balance</span><strong>${bookingMoney(data.remaining_balance)}</strong></div></div><div class="ho-bd-payment-stats"><div><small>PAYMENT OPTION</small><strong>${esc(data.payment_type||'—')}</strong></div><div><small>PAYMENT STATUS</small><strong class="ho-bd-payment-${paymentClass}">${esc(paymentStatus)}</strong></div></div></section><section class="ho-bd-drawer-section"><div class="ho-bd-section-title"><span>${bookingIcons.note}</span><div><h4>Special request</h4><p>Additional notes submitted with the reservation</p></div></div><div class="ho-bd-request">${esc(data.special_request||'No special request provided.')}</div></section><div class="ho-bd-confidence-note"><span>${bookingIcons.hotel}</span><p><strong>Booking record verified</strong>Details shown here are loaded from the current hotel booking record.</p></div>`;
    bookingDetails.inert=false;bookingDetails.classList.add('show');bookingDetails.setAttribute('aria-hidden','false');document.body.classList.add('ho-booking-details-open');bookingDetails.querySelector('[data-close-booking-details]')?.focus();
  }));
  bookingDetails?.querySelectorAll('[data-close-booking-details]').forEach(button=>button.addEventListener('click',closeBookingDetails));
  bookingDetails?.addEventListener('mousedown',event=>{if(event.target===bookingDetails)closeBookingDetails();});
  const drawerRow = (label, value) => `<div class="hp-drawer-row"><span>${esc(label)}</span><strong>${esc(value || '—')}</strong></div>`;
  const receiptModal = document.getElementById('hpReceiptModal');
  const receiptPaper = document.getElementById('hpReceiptPaper');
  const receiptButton = document.getElementById('hpViewReceipt');
  let activeTransaction = null;
  const canIssueReceipt = data => ['paid','succeeded','completed'].includes(String(data?.status_raw || '').toLowerCase());
  const collectionLocation = value => {
    const source = String(value || '').toLowerCase();
    if (source.includes('during room booking')) return 'Received during booking';
    if (source.includes('tourist profile')) return 'Received on tourist profile';
    if (source.includes('hotel payment') || source.includes('hotel booking') || source.includes('property staff')) return 'Received on hotel admin';
    return value || 'Received through online checkout';
  };
  const closeDrawer = () => { drawer?.classList.remove('open'); drawer?.setAttribute('aria-hidden','true'); document.body.style.overflow=''; };
  document.querySelectorAll('[data-transaction]').forEach(button => {
    try {
      const data = JSON.parse(button.dataset.transaction || '{}');
      const isCash = String(data.method || '').toLowerCase() === 'cash';
      if (isCash) {
        data.reference = 'Cash';
        data.provider = 'Cash';
        const reference = button.closest('tr')?.querySelector('.hp-ref strong');
        const channel = button.closest('tr')?.querySelector('td:nth-child(4) > strong');
        if (reference) reference.textContent = 'Cash';
        if (channel) channel.textContent = 'Cash';
        button.dataset.transaction = JSON.stringify(data);
      }
      const channelSource = button.closest('tr')?.querySelector('td:nth-child(4) .hp-cell-sub');
      if (channelSource && data.collection_source) {
        channelSource.textContent = collectionLocation(data.collection_source);
        channelSource.classList.add('hp-channel-source');
      }
    } catch (_) {}
  });
  document.querySelectorAll('[data-transaction]').forEach(button => button.addEventListener('click', () => {
    const data = JSON.parse(button.dataset.transaction || '{}');
    data.collection_source = collectionLocation(data.collection_source);
    activeTransaction = data;
    if (receiptButton) receiptButton.hidden = !canIssueReceipt(data);
    document.getElementById('hpDrawerBody').innerHTML = `<div class="hp-drawer-summary"><div class="hp-drawer-summary-top"><small>Hotel room payment</small><span class="hp-status ${esc(data.status_raw)}"><i></i>${esc(data.status)}</span></div><span>Amount</span><strong>${esc(data.amount)}</strong></div><section class="hp-drawer-section"><h4>Customer & booking</h4>${drawerRow('Customer',data.guest)}${drawerRow('Email',data.email)}${drawerRow('Booking reference',data.booking_reference)}${drawerRow('Property',data.property)}${drawerRow('Room',data.room)}${drawerRow('Booking total',data.booking_total)}${drawerRow('Paid to date',data.booking_paid)}${drawerRow('Balance remaining',data.booking_balance)}</section><section class="hp-drawer-section"><h4>Payment information</h4>${drawerRow('Transaction reference',data.reference)}${drawerRow('Channel',data.provider)}${drawerRow('Collected through',data.collection_source)}${drawerRow('Payment method',data.method)}${drawerRow('Created',data.created)}${drawerRow('Settled',data.paid_at)}</section><div class="hp-drawer-note">This record belongs exclusively to a room booking under this property account.</div>`;
    drawer?.classList.add('open'); drawer?.setAttribute('aria-hidden','false'); document.body.style.overflow='hidden';
  }));
  document.querySelectorAll('[data-close-drawer]').forEach(button => button.addEventListener('click', closeDrawer));

  const setReceiptOpen = open => {
    if (!receiptModal) return;
    receiptModal.inert = !open; receiptModal.classList.toggle('show',open); receiptModal.setAttribute('aria-hidden',open?'false':'true');
    document.body.style.overflow = open ? 'hidden' : (drawer?.classList.contains('open') ? 'hidden' : '');
  };
  const buildReceipt = () => {
    if (!activeTransaction || !receiptPaper || !canIssueReceipt(activeTransaction)) return;
    const tx = activeTransaction;
    receiptPaper.innerHTML = `<div class="receipt-brand"><div class="receipt-brand-identity"><img class="receipt-brand-logo" src="img/newlogo.png" alt="iTour Mercedes seal"><div><img class="receipt-brand-wordmark" src="img/textlogo2.png" alt="iTour Mercedes"><p>OFFICIAL HOTEL PAYMENT RECEIPT</p></div></div><div class="receipt-number"><small>RECEIPT REFERENCE</small><strong>${esc(tx.reference)}</strong><span class="receipt-paid-stamp">PAID</span></div></div><div class="receipt-meta"><div><small>PAID BY</small><strong>${esc(tx.guest)}</strong></div><div><small>PROPERTY</small><strong>${esc(tx.property)}</strong></div><div><small>BOOKING REFERENCE</small><strong>${esc(tx.booking_reference)}</strong></div><div><small>ROOM</small><strong>${esc(tx.room)}</strong></div></div><table class="receipt-table"><thead><tr><th>DESCRIPTION</th><th>AMOUNT</th></tr></thead><tbody><tr><td>Payment received for ${esc(tx.service)}</td><td>${esc(tx.amount)}</td></tr><tr class="receipt-total"><td>TOTAL PAYMENT</td><td>${esc(tx.amount)}</td></tr></tbody></table><div class="receipt-meta receipt-payment-meta"><div><small>PAYMENT DATE</small><strong>${esc(tx.paid_at === '—' ? tx.created : tx.paid_at)}</strong></div><div><small>PAYMENT METHOD</small><strong>${esc(tx.method)}</strong></div><div><small>PAYMENT CHANNEL</small><strong>${esc(tx.provider)}</strong></div><div><small>COLLECTED THROUGH</small><strong>${esc(tx.collection_source)}</strong></div></div><div class="receipt-summary"><div><small>BOOKING TOTAL</small><strong>${esc(tx.booking_total)}</strong></div><div><small>PAID TO DATE</small><strong>${esc(tx.booking_paid)}</strong></div><div><small>CURRENT BALANCE</small><strong>${esc(tx.booking_balance)}</strong></div></div><div class="receipt-foot">This official receipt confirms only the successful hotel payment transaction shown above. Please retain it as your payment record.</div>`;
    setReceiptOpen(true);
  };
  receiptButton?.addEventListener('click', buildReceipt);
  document.querySelectorAll('[data-close-receipt]').forEach(button => button.addEventListener('click',()=>setReceiptOpen(false)));
  document.getElementById('hpPrintReceipt')?.addEventListener('click', () => {
    if (!receiptPaper?.innerHTML.trim()) return;
    const printWindow=window.open('','_blank','width=900,height=720'); if(!printWindow){alert('Allow pop-ups to print the receipt.');return;}
    const stylesheet=new URL('styles/admin_receipt.css',window.location.href).href;
    printWindow.document.write(`<!doctype html><html><head><title>Hotel Payment Receipt</title><link rel="stylesheet" href="${stylesheet}"><style>body{margin:0;padding:20px;background:#fff}.admin-receipt-paper{width:560px;min-height:0;margin:auto;box-shadow:none}</style></head><body><article class="admin-receipt-paper">${receiptPaper.innerHTML}</article></body></html>`); printWindow.document.close(); printWindow.addEventListener('load',()=>setTimeout(()=>{printWindow.focus();printWindow.print();},200));
  });
  document.getElementById('hpDownloadReceipt')?.addEventListener('click', async event => {
    if (!receiptPaper?.innerHTML.trim() || !window.html2canvas || !window.jspdf?.jsPDF) { alert('The PDF tools did not load. Refresh and try again.'); return; }
    const button=event.currentTarget, original=button.textContent; button.disabled=true; button.textContent='Preparing…';
    try { const canvas=await html2canvas(receiptPaper,{scale:2,backgroundColor:'#fff',useCORS:true}); const {jsPDF}=window.jspdf; const pdf=new jsPDF({orientation:'portrait',unit:'mm',format:'a4'}); const scale=Math.min(190/canvas.width,277/canvas.height); const width=canvas.width*scale,height=canvas.height*scale; pdf.addImage(canvas.toDataURL('image/png'),'PNG',(210-width)/2,10,width,height,undefined,'FAST'); pdf.save(`hotel-payment-receipt-${String(activeTransaction?.reference||'payment').replace(/[^a-z0-9_-]+/gi,'-')}.pdf`); }
    catch (_) { alert('The payment receipt could not be prepared.'); } finally { button.disabled=false; button.textContent=original; }
  });
  document.querySelector('.hp-notice button')?.addEventListener('click', event => event.currentTarget.parentElement.hidden = true);
  const notificationToggle = document.getElementById('hoNotifToggle');
  const notificationPanel = document.getElementById('hoNotifPanel');
  notificationToggle?.addEventListener('click', event => {
    event.stopPropagation();
    const open = !notificationPanel?.classList.contains('open');
    notificationPanel?.classList.toggle('open', open);
    notificationToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
  });
  document.getElementById('hoNotifMarkRead')?.addEventListener('click', async () => {
    const body = new URLSearchParams({ho_action: 'mark_notifications_read', csrf_token: String(config.notificationCsrf || '')});
    try {
      const response = await fetch('Hopayments.php', {method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body});
      if (response.ok) {
        document.getElementById('hoNotifBadge')?.remove();
        notificationPanel?.querySelectorAll('.is-unread').forEach(item => item.classList.remove('is-unread'));
        notificationPanel?.querySelectorAll('.ho-notif-unread-pill').forEach(item => item.remove());
      }
    } catch (_) {}
  });
  document.addEventListener('click', event => {
    if (notificationPanel && notificationToggle && !notificationPanel.contains(event.target) && !notificationToggle.contains(event.target)) {
      notificationPanel.classList.remove('open'); notificationToggle.setAttribute('aria-expanded','false');
    }
  });
  document.addEventListener('keydown', event => { if(event.key==='Escape'){if(bookingDetails?.classList.contains('show'))closeBookingDetails();else if(phoneModal?.classList.contains('open'))closePhoneModal();else if(phoneOverview?.classList.contains('open'))closePhoneOverview();else if(receiptModal?.classList.contains('show'))setReceiptOpen(false);else{closeModal();closeDrawer();}} });
});
