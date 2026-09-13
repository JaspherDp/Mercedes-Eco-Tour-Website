(() => {
  'use strict';
  const data = window.payoutPageData || {};
  const escapeHtml = value => String(value ?? '').replace(/[&<>'"]/g, char => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#039;','"':'&quot;'}[char]));

  if (data.notice && window.Swal) {
    Swal.fire({icon:data.notice.type === 'success' ? 'success' : 'error',title:data.notice.type === 'success' ? 'Payout updated' : 'Unable to update payout',text:data.notice.message,confirmButtonColor:'#1d6851'});
  }

  document.querySelectorAll('form[data-confirm]').forEach(form => form.addEventListener('submit', event => {
    if (form.dataset.confirmed === 'true') return;
    event.preventDefault();
    const submit = () => { form.dataset.confirmed = 'true'; form.requestSubmit(); };
    if (!window.Swal) { if (window.confirm(form.dataset.confirm)) submit(); return; }
    Swal.fire({icon:'question',title:'Approve payout?',text:form.dataset.confirm,showCancelButton:true,confirmButtonText:'Approve',cancelButtonText:'Cancel',confirmButtonColor:'#1d6851',reverseButtons:true}).then(result => { if (result.isConfirmed) submit(); });
  }));

  const openModal = modal => { modal.classList.add('open'); modal.setAttribute('aria-hidden','false'); document.body.style.overflow='hidden'; const focusable=modal.querySelector('select,input:not([type="hidden"]),button'); if(focusable)setTimeout(()=>focusable.focus(),30); };
  const closeModal = modal => { modal.classList.remove('open'); modal.setAttribute('aria-hidden','true'); if(!document.querySelector('.payout-modal.open'))document.body.style.overflow=''; };
  document.querySelectorAll('[data-close-modal]').forEach(button => button.addEventListener('click',()=>closeModal(button.closest('.payout-modal'))));
  const openDrawer=drawer=>{document.querySelectorAll('.payout-drawer.open').forEach(closeDrawer);drawer.classList.add('open');drawer.setAttribute('aria-hidden','false');document.body.style.overflow='hidden';setTimeout(()=>drawer.querySelector('[data-close-drawer]')?.focus(),220);};
  function closeDrawer(drawer){if(!drawer)return;drawer.classList.remove('open');drawer.setAttribute('aria-hidden','true');if(!document.querySelector('.payout-drawer.open,.payout-modal.open'))document.body.style.overflow='';}
  document.querySelectorAll('[data-close-drawer]').forEach(button=>button.addEventListener('click',()=>closeDrawer(button.closest('.payout-drawer'))));
  document.addEventListener('keydown',event=>{if(event.key==='Escape'){document.querySelectorAll('.payout-modal.open').forEach(closeModal);document.querySelectorAll('.payout-drawer.open').forEach(closeDrawer);}});

  const closeActionMenus=except=>document.querySelectorAll('.action-popover:not([hidden])').forEach(menu=>{
    if(menu===except)return;menu.hidden=true;menu.closest('.action-menu')?.querySelector('.action-menu-toggle')?.setAttribute('aria-expanded','false');
  });
  document.querySelectorAll('.action-menu-toggle').forEach(toggle=>toggle.addEventListener('click',event=>{
    event.stopPropagation();const menu=toggle.closest('.action-menu')?.querySelector('.action-popover');if(!menu)return;
    const willOpen=menu.hidden;closeActionMenus(menu);menu.hidden=!willOpen;toggle.setAttribute('aria-expanded',willOpen?'true':'false');
    if(willOpen){const rect=toggle.getBoundingClientRect(),menuWidth=220,estimatedHeight=210;const left=Math.max(8,Math.min(window.innerWidth-menuWidth-8,rect.right-menuWidth));const top=rect.bottom+6+estimatedHeight>window.innerHeight?Math.max(8,rect.top-estimatedHeight-6):rect.bottom+6;menu.style.left=`${left}px`;menu.style.top=`${top}px`;}
  }));
  document.addEventListener('click',event=>{if(!event.target.closest('.action-menu'))closeActionMenus();});
  window.addEventListener('scroll',()=>closeActionMenus(),true);

  const ledgerFilters=document.getElementById('ledgerFilters');
  const ledgerRows=[...document.querySelectorAll('[data-ledger-row]')];
  const ledgerEmptyRow=document.getElementById('ledgerEmptyRow');
  const ledgerResultCount=document.getElementById('ledgerResultCount');
  const ledgerFilteredTotal=document.getElementById('ledgerFilteredTotal');
  const ledgerReset=document.querySelector('[data-ledger-reset]');
  const ledgerTabs=[...document.querySelectorAll('[data-ledger-status]')];
  const payoutExport=document.getElementById('payoutExport');
  const peso=new Intl.NumberFormat('en-PH',{style:'currency',currency:'PHP',minimumFractionDigits:2,maximumFractionDigits:2});
  const stakeholderFilter=document.getElementById('pageStakeholderFilter');
  const providerFilter=document.getElementById('pageProviderFilter');
  const pageScopeReset=document.getElementById('pageScopeReset');
  const pageRecords=Array.isArray(data.records)?data.records:[];
  const providerBalanceBody=document.getElementById('providerBalanceBody');
  const exposureList=document.getElementById('exposureList');
  const exposureTotal=document.getElementById('exposureTotal');
  const exposureProviderCount=document.getElementById('exposureProviderCount');
  const stakeholderLabels={operator:'Tour Operator',hotel:'Hotel / Resort',tourguide:'Tour Guide',boat:'Boat'};
  let chartSeries={collections:[...(data.collections||[])].map(Number),settled:[...(data.settled||[])].map(Number)};
  let redrawPayoutTrend=()=>{};

  function pageScope(){return {stakeholder:String(stakeholderFilter?.value||'all'),provider:String(providerFilter?.value||'all')};}
  function recordMatchesPageScope(record,scope=pageScope()){
    return (scope.stakeholder==='all'||record.provider_type===scope.stakeholder)&&(scope.provider==='all'||record.provider_key===scope.provider);
  }
  function configureProviderFilter(resetInvalid=true){
    if(!providerFilter||!stakeholderFilter)return;
    const type=stakeholderFilter.value;let selectedStillValid=providerFilter.value==='all';
    [...providerFilter.options].forEach((option,index)=>{
      if(index===0)return;
      const visible=type!=='all'&&option.dataset.providerType===type;
      option.hidden=!visible;option.disabled=!visible;
      if(visible&&option.value===providerFilter.value)selectedStillValid=true;
    });
    providerFilter.options[0].textContent=type==='all'?'All providers':`All ${type==='hotel'?'hotels / resorts':type==='tourguide'?'tour guides':type==='boat'?'boats':'tour operators'}`;
    providerFilter.disabled=type==='all';
    if(resetInvalid&&!selectedStillValid)providerFilter.value='all';
    if(type==='all')providerFilter.value='all';
  }
  function aggregateProviders(records){
    const grouped=new Map();
    records.forEach(record=>{
      if(record.state==='excluded')return;
      if(!grouped.has(record.provider_key))grouped.set(record.provider_key,{key:record.provider_key,name:record.provider_name,type:record.provider_type,pending:0,available:0,settled:0,bookings:0});
      const provider=grouped.get(record.provider_key);if(provider[record.state]!==undefined)provider[record.state]+=Number(record.payout||0);provider.bookings++;
    });
    return [...grouped.values()].sort((a,b)=>(b.pending+b.available)-(a.pending+a.available)||a.name.localeCompare(b.name));
  }
  function renderProviderScope(records){
    const providers=aggregateProviders(records);
    if(providerBalanceBody)providerBalanceBody.innerHTML=providers.length?providers.map(provider=>{
      const outstanding=provider.pending+provider.available;
      return `<tr><td><div class="provider-cell"><span class="provider-mark ${escapeHtml(provider.type)}">${escapeHtml(String(provider.name||'?').charAt(0).toUpperCase())}</span><div><strong>${escapeHtml(provider.name)}</strong><small>${escapeHtml(stakeholderLabels[provider.type]||provider.type)}</small></div></div></td><td class="right muted-money">${escapeHtml(peso.format(provider.pending))}</td><td class="right available-money">${escapeHtml(peso.format(provider.available))}</td><td class="right settled-money">${escapeHtml(peso.format(provider.settled))}</td><td class="right"><strong>${escapeHtml(peso.format(outstanding))}</strong></td></tr>`;
    }).join(''):'<tr><td colspan="5"><div class="table-empty">No provider payout balances match this workspace filter.</div></td></tr>';
    const exposed=providers.filter(provider=>provider.pending+provider.available>0).slice(0,5);
    const outstanding=providers.reduce((sum,provider)=>sum+provider.pending+provider.available,0);
    if(exposureTotal)exposureTotal.textContent=peso.format(outstanding);
    const exposedCount=providers.filter(provider=>provider.pending+provider.available>0).length;
    if(exposureProviderCount)exposureProviderCount.textContent=`Across ${exposedCount.toLocaleString()} provider${exposedCount===1?'':'s'}`;
    if(exposureList){exposureList.innerHTML=exposed.length?exposed.map(provider=>{
      const due=provider.pending+provider.available;
      const share=outstanding>0?Math.min(100,(due/outstanding)*100):0;
      return `<div class="exposure-row"><div class="exposure-ring ${escapeHtml(provider.type)}" style="--exposure-progress:${share.toFixed(1)}%"><div><strong>${share.toFixed(0)}%</strong><span>of total</span></div></div><div class="exposure-provider"><strong>${escapeHtml(provider.name)}</strong><small>${escapeHtml(stakeholderLabels[provider.type]||provider.type)} &middot; ${provider.bookings.toLocaleString()} booking${provider.bookings===1?'':'s'}</small></div><div class="exposure-amount"><strong>${escapeHtml(peso.format(due))}</strong><span>Outstanding</span></div></div>`;
    }).join(''):'<div class="compact-empty">No outstanding provider balances match this filter.</div>';}
  }
  function updatePageSummary(records){
    const totals={collected:0,pending:0,available:0,settled:0},counts={pending:0,available:0,settled:0};
    records.forEach(record=>{totals.collected+=Number(record.collected||0);if(totals[record.state]!==undefined&&record.state!=='collected')totals[record.state]+=Number(record.payout||0);if(counts[record.state]!==undefined)counts[record.state]++;});
    const notes={collected:`${records.length.toLocaleString()} recorded booking payment${records.length===1?'':'s'}`,pending:`${counts.pending.toLocaleString()} booking${counts.pending===1?'':'s'} awaiting completion`,available:`${counts.available.toLocaleString()} completed booking${counts.available===1?'':'s'} ready to settle`,settled:`${counts.settled.toLocaleString()} completed disbursement${counts.settled===1?'':'s'}`};
    Object.keys(totals).forEach(key=>{const card=document.querySelector(`[data-page-metric="${key}"]`);if(!card)return;card.querySelector('strong').textContent=peso.format(totals[key]);card.querySelector('small').textContent=notes[key];});
    const monthKeys=Array.isArray(data.monthKeys)?data.monthKeys:[];const collections=monthKeys.map(()=>0),settled=monthKeys.map(()=>0),pending=monthKeys.map(()=>0),available=monthKeys.map(()=>0);
    records.forEach(record=>{const bookingIndex=monthKeys.indexOf(record.booking_month);if(bookingIndex>=0){collections[bookingIndex]+=Number(record.collected||0);if(record.state==='pending')pending[bookingIndex]+=Number(record.payout||0);if(record.state==='available')available[bookingIndex]+=Number(record.payout||0);}if(record.state==='settled'){const settledIndex=monthKeys.indexOf(record.settled_month);if(settledIndex>=0)settled[settledIndex]+=Number(record.payout||0);}});
    const metricSeries={collected:collections,pending,available,settled};
    Object.entries(metricSeries).forEach(([key,series])=>{
      const card=document.querySelector(`[data-page-metric="${key}"]`);if(!card)return;
      const current=Number(series.at(-1)||0),previous=Number(series.at(-2)||0);
      const change=Math.abs(previous)>0.009?((current-previous)/Math.abs(previous))*100:(current>0.009?100:0);
      const direction=change>0.049?'positive':(change<-.049?'negative':'neutral');
      const trend=card.querySelector('[data-metric-trend]');if(trend){trend.className=`trend-change ${direction}`;trend.querySelector('b').textContent=`${change>0?'+':''}${change.toFixed(1)}%`;}
      const low=Math.min(...series),high=Math.max(...series),range=Math.max(1,high-low),width=78,height=22;
      const points=series.map((value,index)=>`${2+(series.length===1?0:index*(width/(series.length-1)))},${3+(1-(Number(value)-low)/range)*height}`);
      const line=card.querySelector('[data-trend-line]'),point=card.querySelector('[data-trend-point]');if(line)line.setAttribute('points',points.join(' '));if(point&&points.length){const [x,y]=points.at(-1).split(',');point.setAttribute('cx',x);point.setAttribute('cy',y);}
    });
    chartSeries={collections,settled};renderProviderScope(records);redrawPayoutTrend();
  }

  function currentLedgerFilters(){
    return {
      search:String(ledgerFilters?.elements.search?.value||'').trim().toLowerCase(),
      status:String(ledgerFilters?.elements.status?.value||'all').toLowerCase(),
      type:String(ledgerFilters?.elements.type?.value||'all').toLowerCase()
    };
  }
  function syncLedgerUrl(filters,mode){
    const params=new URLSearchParams();
    const scope=pageScope();
    if(scope.stakeholder!=='all')params.set('stakeholder',scope.stakeholder);
    if(scope.provider!=='all')params.set('provider',scope.provider);
    if(filters.search)params.set('search',filters.search);
    if(filters.status!=='all')params.set('status',filters.status);
    if(filters.type!=='all')params.set('type',filters.type);
    const query=params.toString();
    const next=`${window.location.pathname}${query?`?${query}`:''}`;
    if(mode==='push')window.history.pushState({},'',next);
    else if(mode==='replace')window.history.replaceState({},'',next);
    if(payoutExport){const exportParams=new URLSearchParams(params);exportParams.set('export','csv');payoutExport.href=`?${exportParams.toString()}`;}
  }
  function applyLedgerFilters(historyMode='none'){
    if(!ledgerFilters)return;
    const filters=currentLedgerFilters(),scope=pageScope();let shown=0,total=0;
    ledgerRows.forEach(row=>{
      const matchesSearch=!filters.search||String(row.dataset.search||'').includes(filters.search);
      const matchesStatus=filters.status==='all'||row.dataset.state===filters.status;
      const matchesType=filters.type==='all'||row.dataset.providerType===filters.type;
      const matchesScope=(scope.stakeholder==='all'||row.dataset.providerType===scope.stakeholder)&&(scope.provider==='all'||row.dataset.providerKey===scope.provider);
      const visible=matchesSearch&&matchesStatus&&matchesType&&matchesScope;
      row.hidden=!visible;
      if(visible){shown++;total+=Number(row.dataset.payoutAmount||0);}
    });
    if(ledgerEmptyRow)ledgerEmptyRow.hidden=shown!==0;
    if(ledgerResultCount)ledgerResultCount.textContent=`Showing ${shown.toLocaleString()} of ${ledgerRows.length.toLocaleString()} booking payment records`;
    if(ledgerFilteredTotal)ledgerFilteredTotal.textContent=peso.format(total);
    ledgerTabs.forEach(tab=>{const active=tab.dataset.ledgerStatus===filters.status;tab.classList.toggle('active',active);tab.setAttribute('aria-current',active?'page':'false');});
    if(ledgerReset)ledgerReset.hidden=!filters.search&&filters.status==='all'&&filters.type==='all';
    closeActionMenus();syncLedgerUrl(filters,historyMode);
  }
  ledgerFilters?.addEventListener('submit',event=>{event.preventDefault();applyLedgerFilters('push');});
  ledgerTabs.forEach(tab=>tab.addEventListener('click',event=>{event.preventDefault();if(ledgerFilters?.elements.status)ledgerFilters.elements.status.value=tab.dataset.ledgerStatus||'all';applyLedgerFilters('push');}));
  ledgerReset?.addEventListener('click',event=>{event.preventDefault();ledgerFilters.reset();ledgerFilters.elements.search.value='';ledgerFilters.elements.status.value='all';ledgerFilters.elements.type.value='all';applyLedgerFilters('push');});
  function applyPageScope(historyMode='push'){const scoped=pageRecords.filter(record=>recordMatchesPageScope(record));updatePageSummary(scoped);if(pageScopeReset){const scope=pageScope();pageScopeReset.hidden=scope.stakeholder==='all'&&scope.provider==='all';}applyLedgerFilters(historyMode);}
  stakeholderFilter?.addEventListener('change',()=>{configureProviderFilter();providerFilter.value='all';applyPageScope('push');});
  providerFilter?.addEventListener('change',()=>applyPageScope('push'));
  pageScopeReset?.addEventListener('click',()=>{stakeholderFilter.value='all';providerFilter.value='all';configureProviderFilter();applyPageScope('push');});
  window.addEventListener('popstate',()=>{if(!ledgerFilters)return;const params=new URLSearchParams(window.location.search);if(stakeholderFilter)stakeholderFilter.value=params.get('stakeholder')||'all';configureProviderFilter();if(providerFilter&&params.get('provider')&&[...providerFilter.options].some(option=>!option.disabled&&option.value===params.get('provider')))providerFilter.value=params.get('provider');ledgerFilters.elements.search.value=params.get('search')||'';ledgerFilters.elements.status.value=params.get('status')||'all';ledgerFilters.elements.type.value=params.get('type')||'all';applyPageScope('none');});
  configureProviderFilter(false);applyPageScope('replace');

  const detailDrawer=document.getElementById('payoutDetailDrawer');
  document.querySelectorAll('[data-payout-details]').forEach(button=>button.addEventListener('click',()=>{
    let record={};try{record=JSON.parse(button.dataset.payoutDetails||'{}');}catch(_error){return;}
    closeActionMenus();
    const bookingRows=[['Guest',record.guest],['Service',record.service],['Service date',record.service_date],['Booking status',record.booking_status],['Completion',record.completion_status]];
    const financialRows=[['Provider',record.provider],['Provider type',record.provider_type],['Payment method',record.payment_method],['Amount collected',record.amount_paid],['Refund amount',record.refund_amount],...(record.retention_applicable?[['Non-refundable retained',record.retained_amount]]:[]),['Provider payout',record.amount],['Payout status',record.payout_status],['Settlement reference',record.settlement_reference||'Not settled']];
    const refundTone=String(record.refund_status||'').toLowerCase().includes('fail')?'danger':(String(record.refund_status||'').toLowerCase()==='refunded'?'success':'');
    const refundNote=record.payout_note||(record.payout_status==='Not payable'
      ? `This booking is not payable because its completion status is ${record.completion_status||'not completed'}. Review the refund state before taking further action.`
      : 'No refund blocks this provider payout.');
    const rows=list=>'<div class="detail-grid">'+list.map(([label,value])=>`<div><span>${escapeHtml(label)}</span><strong>${escapeHtml(value||'—')}</strong></div>`).join('')+'</div>';
    document.getElementById('payoutDetailBody').innerHTML=`<div class="detail-summary"><div><small>BOOKING REFERENCE</small><strong>${escapeHtml(record.booking)}</strong></div><strong>${escapeHtml(record.amount)}</strong></div><section class="detail-section"><h4>Booking information</h4>${rows(bookingRows)}</section><section class="detail-section"><h4>Disbursement information</h4>${rows(financialRows)}</section><div class="detail-refund ${refundTone}"><small>REFUND STATUS</small><strong>${escapeHtml(record.refund_status)} · ${escapeHtml(record.refund_amount)}</strong><p>${escapeHtml(refundNote)}${record.cancellation_status&&record.cancellation_status!=='Not Specified'?` Cancellation request: ${escapeHtml(record.cancellation_status)}.`:''}</p></div>`;
    openDrawer(detailDrawer);
  }));

  const bookingDrawer=document.getElementById('bookingDetailDrawer');
  document.querySelectorAll('[data-booking-details]').forEach(button=>button.addEventListener('click',()=>{
    let record={};try{record=JSON.parse(button.dataset.bookingDetails||'{}');}catch(_error){return;}
    closeActionMenus();
    const contactRows=[['Guest',record.guest],['Email',record.email||'Not provided'],['Phone',record.phone||'Not provided']];
    const serviceRows=[['Service type',record.service],['Service / provider',record.service_name],['Service date',record.service_date],['Date range',record.date_range||record.service_date],['Location',record.location||'Not specified'],['Jump-off port',record.jump_off||'Not applicable'],['Tour type',record.tour_type],['Guests',`${record.pax||0} total · ${record.adults||0} adult(s) · ${record.children||0} child(ren)`]];
    const statusRows=[['Current status',record.status],['Booking decision',record.booking_status],['Completion',record.completion],['Refund status',record.refund_status],['Payout status',record.payout_status]];
    const rows=list=>'<div class="drawer-detail-grid">'+list.map(([label,value])=>`<div><span>${escapeHtml(label)}</span><strong>${escapeHtml(value||'—')}</strong></div>`).join('')+'</div>';
    const retentionNote=record.payout_note?`<div class="drawer-retention-note"><strong>Late-cancellation payout</strong><p>${escapeHtml(record.payout_note)}</p></div>`:'';
    document.getElementById('bookingDetailBody').innerHTML=`<div class="drawer-hero"><div><small>BOOKING REFERENCE</small><strong>${escapeHtml(record.reference)}</strong><span>${escapeHtml(record.service)}</span></div><b>${escapeHtml(record.status)}</b></div><section class="drawer-section"><h4>Guest information</h4>${rows(contactRows)}</section><section class="drawer-section"><h4>Reservation information</h4>${rows(serviceRows)}</section><section class="drawer-section"><h4>Status and workflow</h4>${rows(statusRows)}</section><section class="drawer-payment-card"><small>PAYMENT SUMMARY</small><div><span>Booking total</span><strong>${escapeHtml(record.total)}</strong></div><div><span>Amount received</span><strong>${escapeHtml(record.paid)}</strong></div><div><span>Refund amount</span><strong>${escapeHtml(record.refund_amount)}</strong></div><div><span>Remaining balance</span><strong>${escapeHtml(record.balance)}</strong></div><div class="balance"><span>Provider payout</span><strong>${escapeHtml(record.provider_payout)}</strong></div><p>${escapeHtml(record.payment_method)}</p></section>${retentionNote}`;
    openDrawer(bookingDrawer);
  }));

  document.querySelectorAll('[data-copy-reference]').forEach(button=>button.addEventListener('click',async()=>{
    const reference=button.dataset.copyReference||'';try{await navigator.clipboard.writeText(reference);if(window.Swal)Swal.fire({icon:'success',title:'Reference copied',text:reference,timer:1300,showConfirmButton:false});}catch(_error){window.prompt('Copy booking reference:',reference);}closeActionMenus();
  }));

  const settlementModal=document.getElementById('settlementModal');
  document.querySelectorAll('[data-settle]').forEach(button=>button.addEventListener('click',()=>{
    let details={};try{details=JSON.parse(button.dataset.settle||'{}');}catch(_error){return;}
    const continueToDetails=()=>{
      document.getElementById('settlementPayoutId').value=details.id||'';
      document.getElementById('settlementBooking').textContent=details.reference||'—';
      document.getElementById('settlementProvider').textContent=details.provider||'—';
      document.getElementById('settlementAmount').textContent=details.amount||'—';
      openModal(settlementModal);
    };
    if(!window.Swal){if(window.confirm(`Do you want to settle ${details.amount||'this payout'} for ${details.provider||'this provider'}?`))continueToDetails();return;}
    Swal.fire({
      icon:'warning',title:'Approve payout for settlement?',
      text:`Confirm ${details.amount||'this payout'} for ${details.provider||'the provider'}. You will enter the transfer reference before it is recorded as settled.`,
      showCancelButton:true,confirmButtonText:'Approve payout',cancelButtonText:'Cancel',confirmButtonColor:'#1d6851',cancelButtonColor:'#6f7f79',reverseButtons:true,focusCancel:true
    }).then(result=>{if(result.isConfirmed)continueToDetails();});
  }));

  const settlementForm=document.getElementById('settlementForm');
  if(settlementForm)settlementForm.addEventListener('submit',event=>{
    if(settlementForm.dataset.confirmed==='true')return;
    event.preventDefault();
    if(!settlementForm.reportValidity())return;
    const amount=document.getElementById('settlementAmount')?.textContent||'this payout';
    const provider=document.getElementById('settlementProvider')?.textContent||'the provider';
    const submit=()=>{settlementForm.dataset.confirmed='true';settlementForm.requestSubmit();};
    if(!window.Swal){if(window.confirm(`Approve and settle ${amount} to ${provider}?`))submit();return;}
    Swal.fire({
      icon:'warning',title:'Approve and settle payout?',
      html:`Confirm that <strong>${escapeHtml(amount)}</strong> will be recorded as paid to <strong>${escapeHtml(provider)}</strong>. This action changes the payout to settled.`,
      showCancelButton:true,confirmButtonText:'Approve & settle',cancelButtonText:'Cancel',confirmButtonColor:'#1d6851',cancelButtonColor:'#6f7f79',reverseButtons:true,focusCancel:true
    }).then(result=>{if(result.isConfirmed)submit();});
  });

  const receiptDrawer=document.getElementById('settlementRecordDrawer');
  document.querySelectorAll('[data-receipt]').forEach(button=>button.addEventListener('click',()=>{
    let record={};try{record=JSON.parse(button.dataset.receipt||'{}');}catch(_error){return;}
    const rows=[['Booking',record.booking],['Recipient',record.provider],['Amount',record.amount],['Method',record.method],['Reference',record.reference],['Settled on',record.settled],['Internal note',record.note||'—']];
    document.getElementById('receiptBody').innerHTML='<div class="receipt-grid">'+rows.map(([label,value])=>`<div><span>${escapeHtml(label)}</span><strong>${escapeHtml(value)}</strong></div>`).join('')+'</div>';
    closeActionMenus();openDrawer(receiptDrawer);
  }));

  const canvas=document.getElementById('payoutTrend');
  if(canvas){
    const draw=()=>{
      const rect=canvas.getBoundingClientRect(),ratio=window.devicePixelRatio||1;if(rect.width<10)return;
      canvas.width=Math.round(rect.width*ratio);canvas.height=Math.round(rect.height*ratio);const ctx=canvas.getContext('2d');ctx.scale(ratio,ratio);
      const width=rect.width,height=rect.height,pad={top:13,right:12,bottom:28,left:54},plotW=width-pad.left-pad.right,plotH=height-pad.top-pad.bottom;
      const collections=chartSeries.collections,settled=chartSeries.settled,max=Math.max(1,...collections,...settled),nice=Math.ceil(max/1000)*1000||1000;
      ctx.font='9px Inter, sans-serif';ctx.textBaseline='middle';ctx.strokeStyle='#e5ece9';ctx.fillStyle='#82918b';ctx.lineWidth=1;
      for(let i=0;i<=4;i++){const y=pad.top+plotH*(i/4),value=nice*(1-i/4);ctx.beginPath();ctx.moveTo(pad.left,y);ctx.lineTo(width-pad.right,y);ctx.stroke();ctx.textAlign='right';ctx.fillText(value>=1000?'₱'+(value/1000).toFixed(value%1000?1:0)+'k':'₱'+value,pad.left-7,y);}
      const count=Math.max(1,collections.length),group=plotW/count,bar=Math.min(18,group*.24);
      collections.forEach((value,i)=>{const center=pad.left+group*(i+.5),h=(value/nice)*plotH;ctx.fillStyle='#27775f';ctx.beginPath();ctx.roundRect(center-bar-1,pad.top+plotH-h,bar,h,3);ctx.fill();const s=settled[i]||0,sh=(s/nice)*plotH;ctx.fillStyle='#91bcad';ctx.beginPath();ctx.roundRect(center+1,pad.top+plotH-sh,bar,sh,3);ctx.fill();ctx.fillStyle='#75867f';ctx.textAlign='center';ctx.fillText((data.labels||[])[i]||'',center,height-10);});
    };redrawPayoutTrend=draw;draw();let timer;window.addEventListener('resize',()=>{clearTimeout(timer);timer=setTimeout(draw,100);});
  }
})();
