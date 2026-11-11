(() => {
  'use strict';
  const state=window.payoutPageData?.thirdParty||{ready:false,pools:[]};
  const pools=Array.isArray(state.pools)?state.pools:[];
  const modal=document.getElementById('feeCollectionModal');if(!modal)return;
  const esc=value=>String(value??'').replace(/[&<>"']/g,char=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[char]));
  const money=amount=>new Intl.NumberFormat('en-PH',{style:'currency',currency:'PHP'}).format(amount/100);
  const labels={docking:'Docking / landing fees',entrance:'Entrance fees',environmental:'Environmental fees'};
  const bookingRows=[...document.querySelectorAll('[data-fee-booking-key]')];
  const bookingInfo=new Map((state.bookings||[]).map(booking=>[booking.key,booking]));
  const bookingIdentity=key=>{const [type,id]=String(key).split(':');return ({boat:'Boat',tourguide:'Tour guide',package:'Package',hotel:'Hotel'}[type]||'')+' booking #'+id;};
  const bookingLabel=key=>bookingInfo.get(key)?.reference||bookingRows.find(row=>row.dataset.feeBookingKey===key)?.querySelector('.booking-cell strong')?.textContent||bookingIdentity(key);
  const balanceLabels={collected:'Collected',pending:'Pending',available:'To settle',settled:'Settled'};
  let scope=new Set(bookingRows.map(row=>row.dataset.feeBookingKey)),category='docking',status='available',returnFocus=null,selectedPool=null,submitting=false;
  const form=document.getElementById('thirdPartySettlementForm');
  const tableView=document.getElementById('feeCollectionTableView'),detailView=document.getElementById('feeCollectionDetailView'),settleView=document.getElementById('feeCollectionSettleView');
  function balance(pool){
    let collected=0,settled=0,available=0,pending=0;
    Object.entries(pool.collected||{}).forEach(([key,amount])=>{
      if(!scope.has(key))return;
      collected+=Number(amount);
      const remaining=Math.max(0,Number(amount)-Number(pool.settled?.[key]||0));
      if(pool.eligible?.[key])available+=remaining;else pending+=remaining;
    });
    Object.entries(pool.settled||{}).forEach(([key,amount])=>{if(scope.has(key))settled+=Number(amount);});
    return {collected,settled,available,pending};
  }
  function totals(type){return pools.filter(pool=>pool.category===type).reduce((sum,pool)=>{const values=balance(pool);Object.keys(sum).forEach(key=>sum[key]+=values[key]);return sum;},{collected:0,settled:0,available:0,pending:0});}
  function rates(pool){return pool.rates.length?pool.rates.map(rate=>'<div class="fee-rate"><span>'+esc(pool.rates.length>1?rate.label:rate.unit)+'</span><strong>'+money(rate.amount_minor)+(pool.rates.length>1?' <small>/ '+esc(rate.unit)+'</small>':'')+'</strong></div>').join(''):'<span class="fee-muted">Not configured</span>';}
  function closeMenus(){modal.querySelectorAll('.fee-row-popover').forEach(menu=>menu.hidden=true);modal.querySelectorAll('[data-fee-menu]').forEach(button=>button.setAttribute('aria-expanded','false'));}
  function table(){
    const t=totals(category);
    document.getElementById('feeModalSummary').innerHTML=['collected','pending','available','settled'].map(key=>'<div><span>'+balanceLabels[key]+'</span><strong>'+money(t[key])+'</strong></div>').join('');
    modal.querySelectorAll('[data-fee-status]').forEach(button=>{const active=button.dataset.feeStatus===status;button.classList.toggle('active',active);button.setAttribute('aria-current',active?'true':'false');});
    document.getElementById('feeBalancesTable').hidden=status==='history';
    document.getElementById('feeHistoryTable').hidden=status!=='history';
    document.getElementById('feeBalanceColumn').textContent=status==='pending'?'Pending':'To settle';
    document.getElementById('feeStatusNote').textContent=status==='pending'?'Collected fees from bookings that are not completed. These cannot be settled yet.':status==='history'?'Recorded settlements. View details to see the payment and every booking it covered.':'Only fees from completed bookings are available to settle.';
    modal.querySelectorAll('[data-fee-tab]').forEach(button=>{const active=button.dataset.feeTab===category;button.classList.toggle('active',active);button.setAttribute('aria-current',active?'true':'false');});
    document.getElementById('feeCollectionRows').innerHTML=pools.filter(pool=>pool.category===category).map(pool=>{
      const b=balance(pool),disabled=status!=='available'||!state.ready||b.available<=0||pool.location==='Location not recorded';
      const matching=key=>scope.has(key)&&Boolean(pool.eligible?.[key])===(status==='available');
      const collected=Object.entries(pool.collected||{}).reduce((sum,[key,amount])=>sum+(matching(key)?Number(amount):0),0);
      const settled=Object.entries(pool.settled||{}).reduce((sum,[key,amount])=>sum+(matching(key)?Number(amount):0),0);
      return '<tr><td><strong>'+esc(pool.location)+'</strong></td><td>'+rates(pool)+'</td><td class="right">'+money(collected)+'</td><td class="right fee-muted">'+money(settled)+'</td><td class="right fee-table-available">'+money(status==='pending'?b.pending:b.available)+'</td><td class="right"><button type="button" class="fee-row-toggle" data-fee-menu="'+pool.key+'" aria-label="Actions for '+esc(pool.location)+'" aria-expanded="false">⋮</button><div class="fee-row-popover" data-fee-popover="'+pool.key+'" hidden><button type="button" data-fee-details="'+pool.key+'">View details</button><button type="button" class="fee-settle-action" data-fee-pay="'+pool.key+'" '+(disabled?'disabled':'')+' title="'+(status==='pending'?'Only completed bookings can be settled':pool.location==='Location not recorded'?'A saved location is needed to record its payout':b.available<=0?'No balance to settle':'Record a payment you already made')+'"> <svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 9h18M7 14h4"/></svg>Settle payout</button></div></td></tr>';
    }).join('')||'<tr><td colspan="6" class="fee-empty-row">No fee locations are configured.</td></tr>';
    document.getElementById('feeHistoryRows').innerHTML=scopedHistory().map(({pool,payment:p,amount})=>'<tr><td>'+esc(p.settled_at)+'</td><td>'+esc(pool.location)+'</td><td>'+esc(p.recipient_name)+'</td><td>'+esc(p.settlement_method==='cash'?'Cash':p.channel||'Online')+'<small class="fee-history-note">'+esc(p.reference_number||'No reference')+'</small></td><td class="right">'+money(amount)+'</td><td class="right"><button type="button" class="fee-secondary" data-fee-history="'+esc(p.payout_id)+'" data-fee-history-pool="'+pool.key+'">View details</button></td></tr>').join('')||'<tr><td colspan="6" class="fee-empty-row">No settled fees in this view.</td></tr>';
  }
  function cards(){document.querySelectorAll('[data-fee-card]').forEach(element=>{const [type,key]=element.dataset.feeCard.split(':');element.textContent=money(totals(type)[key]);});}
  function screen(view,title,subtitle){
    modal.dataset.view=view;document.getElementById('feeHeaderBack').hidden=view==='table';
    closeMenus();tableView.hidden=view!=='table';detailView.hidden=view!=='details';settleView.hidden=view!=='settle';
    document.getElementById('feeCollectionModalFooter').hidden=view==='settle';
    document.getElementById('feeCollectionTabs').hidden=view!=='table';
    document.getElementById('feeModalTitle').textContent=title;document.getElementById('feeModalSubtitle').textContent=subtitle;
    modal.querySelector('.fee-modal-content').scrollTop=0;
  }
  function showTable(){screen('table',labels[category],'All configured locations, including locations without collections');table();}
  function open(type,button){category=type;status='available';returnFocus=button;modal.hidden=false;document.body.style.overflow='hidden';showTable();modal.querySelector('[data-fee-close]').focus();}
  function close(){if(submitting)return;closeMenus();modal.hidden=true;if(!document.querySelector('.payout-modal.open,.payout-drawer.open'))document.body.style.overflow='';returnFocus?.focus();}
  function scopedHistory(){
    return pools.filter(pool=>pool.category===category).flatMap(pool=>(pool.history||[]).map(payment=>({
      pool,payment,amount:Object.entries(payment.allocations||{}).reduce((sum,[key,value])=>sum+(scope.has(key)?Number(value):0),0)
    }))).filter(row=>row.amount>0).sort((a,b)=>Number(b.payment.payout_id)-Number(a.payment.payout_id));
  }
  function summaryMarkup(values){
    return '<div class="fee-detail-balances">'+['collected','pending','available','settled'].map(key=>'<div><span>'+balanceLabels[key]+'</span><strong>'+money(values[key])+'</strong></div>').join('')+'</div>';
  }
  function details(pool){
    selectedPool=pool;screen('details',pool.location,labels[pool.category]);
    const rows=Object.entries(pool.collected||{}).filter(([key])=>scope.has(key)&&Boolean(pool.eligible?.[key])===(status==='available')).map(([key,amount])=>{
      const settled=Number(pool.settled?.[key]||0),remaining=Math.max(0,Number(amount)-settled);
      const completion=bookingInfo.get(key)?.completion_status|| (pool.eligible?.[key]?'completed':'not completed');
      return '<tr><td><strong>'+esc(bookingLabel(key))+'</strong><small class="fee-history-note">'+esc(bookingIdentity(key))+'</small></td><td>'+esc(completion)+'</td><td class="right">'+money(amount)+'</td><td class="right">'+money(settled)+'</td><td class="right">'+money(remaining)+'</td></tr>';
    }).join('');
    document.getElementById('feeCollectionDetails').innerHTML=summaryMarkup(balance(pool))+'<section class="fee-detail-section"><h4>Current prices</h4>'+rates(pool)+'</section><section class="fee-detail-section"><h4>Collection by booking</h4><div class="fee-modal-table-wrap"><table class="fee-modal-table"><thead><tr><th>Booking / ID</th><th>Booking status</th><th class="right">Collected</th><th class="right">Settled</th><th class="right">'+(status==='pending'?'Pending':'To settle')+'</th></tr></thead><tbody>'+(rows||'<tr><td colspan="5" class="fee-empty-row">No collections in this status.</td></tr>')+'</tbody></table></div></section>';
  }
  function historyDetails(pool,payment){
    screen('details','Settlement #'+payment.payout_id,pool.location+' - '+labels[pool.category]);
    const method=payment.settlement_method==='cash'?'Cash':payment.channel||'Online';
    const fields=[['Settled on',payment.settled_at],['Recipient',payment.recipient_name],['Payment method / channel',method],['Reference',payment.reference_number||'No reference'],['Recorded by',payment.settled_by_name||'Administrator #'+payment.settled_by_admin_id],['Note',payment.note||'No note']];
    const allocations=Object.entries(payment.allocations||{});
    const total=Number(payment.amount_minor)||allocations.reduce((sum,[,amount])=>sum+Number(amount),0);
    const rows=allocations.map(([key,amount])=>'<tr><td><strong>'+esc(bookingLabel(key))+'</strong></td><td>'+esc(bookingIdentity(key))+'</td><td class="right">'+money(amount)+'</td></tr>').join('');
    document.getElementById('feeCollectionDetails').innerHTML='<div class="fee-settlement-overview"><div><span>Total settled</span><strong>'+money(total)+'</strong></div><div><span>Bookings covered</span><strong>'+allocations.length+'</strong></div></div><dl class="fee-history-fields">'+fields.map(([label,value])=>'<div><dt>'+esc(label)+'</dt><dd>'+esc(value)+'</dd></div>').join('')+'</dl><section class="fee-detail-section"><h4>Bookings covered by this settlement</h4><div class="fee-modal-table-wrap"><table class="fee-modal-table"><thead><tr><th>Booking reference</th><th>Booking type / ID</th><th class="right">Amount settled</th></tr></thead><tbody>'+rows+'</tbody></table></div></section>';
  }
  function methodFields(){
    const online=form.elements.fee_method.value==='online';document.getElementById('feeOnlineFields').hidden=!online;
    const channel=document.getElementById('feeChannel');channel.disabled=!online;channel.required=online;
    const custom=online&&['Bank transfer','Other online channel'].includes(channel.value);
    document.getElementById('feeCustomChannelLabel').hidden=!custom;document.getElementById('feeCustomChannel').required=custom;document.getElementById('feeCustomChannel').disabled=!custom;
    document.getElementById('feeReference').required=online;document.getElementById('feeReferenceLabel').innerHTML=online?'Transfer reference number <b>*</b>':'Receipt / acknowledgment reference <em>Optional</em>';
  }
  function settle(pool){
    const b=balance(pool);if(status!=='available'||!state.ready||b.available<=0||pool.location==='Location not recorded')return;
    selectedPool=pool;form.reset();screen('settle','Settle third-party payout',labels[pool.category]);
    document.getElementById('feePoolKey').value=pool.key;document.getElementById('feeBookingKeys').value=JSON.stringify([...scope]);
    document.getElementById('feeSettlementLocation').textContent=pool.location;document.getElementById('feeSettlementAvailable').textContent=money(b.available);
    const amount=document.getElementById('feeSettlementAmount');amount.value=(b.available/100).toFixed(2);amount.max=(b.available/100).toFixed(2);
    document.getElementById('feeFormError').hidden=true;methodFields();amount.focus();
  }
  document.querySelectorAll('[data-fee-expand]').forEach(button=>button.addEventListener('click',()=>open(button.dataset.feeExpand,button)));
  modal.querySelectorAll('[data-fee-close]').forEach(button=>button.addEventListener('click',close));
  modal.querySelectorAll('[data-fee-back]').forEach(button=>button.addEventListener('click',()=>{if(!submitting)showTable();}));
  modal.querySelectorAll('[data-fee-tab]').forEach(button=>button.addEventListener('click',()=>{category=button.dataset.feeTab;showTable();}));
  modal.querySelectorAll('[data-fee-status]').forEach(button=>button.addEventListener('click',()=>{status=button.dataset.feeStatus;showTable();}));
  modal.addEventListener('click',event=>{
    const historyButton=event.target.closest('[data-fee-history]');
    if(historyButton){
      const pool=pools.find(pool=>pool.key===historyButton.dataset.feeHistoryPool);
      const payment=pool?.history?.find(payment=>String(payment.payout_id)===historyButton.dataset.feeHistory);
      if(payment&&scopedHistory().some(row=>row.pool===pool&&row.payment===payment))historyDetails(pool,payment);
      return;
    }
    const toggle=event.target.closest('[data-fee-menu]');
    if(toggle){const menu=modal.querySelector('[data-fee-popover="'+toggle.dataset.feeMenu+'"]');const opening=menu.hidden;closeMenus();if(opening){menu.hidden=false;toggle.setAttribute('aria-expanded','true');const rect=toggle.getBoundingClientRect();menu.style.left=Math.max(8,rect.right-180)+'px';menu.style.top=Math.max(8,Math.min(rect.bottom+4,window.innerHeight-110))+'px';}return;}
    const detail=event.target.closest('[data-fee-details]'),pay=event.target.closest('[data-fee-pay]');
    if(detail||pay){const button=detail||pay;const pool=pools.find(pool=>pool.key===(detail?button.dataset.feeDetails:button.dataset.feePay));if(pool&&!button.disabled)(detail?details:settle)(pool);return;}
    if(!event.target.closest('.fee-row-popover'))closeMenus();
  });
  modal.addEventListener('scroll',closeMenus,true);window.addEventListener('resize',closeMenus);
  form.querySelectorAll('[name="fee_method"]').forEach(radio=>radio.addEventListener('change',methodFields));document.getElementById('feeChannel').addEventListener('change',methodFields);
  form.addEventListener('submit',event=>{
    if(submitting){event.preventDefault();return;}
    const amount=Number(form.elements.fee_amount.value),available=balance(selectedPool).available/100;
    if(!Number.isFinite(amount)||amount<=0||amount>available){event.preventDefault();const error=document.getElementById('feeFormError');error.hidden=false;error.textContent='Enter an amount within the available balance.';return;}
    if(!form.reportValidity()){event.preventDefault();return;}
    const channel=document.getElementById('feeChannel'),custom=document.getElementById('feeCustomChannel');
    if(!custom.disabled){const option=document.createElement('option');option.value=custom.value.trim();option.textContent=option.value;option.selected=true;channel.append(option);}
    submitting=true;const button=form.querySelector('[type="submit"]');button.disabled=true;button.textContent='Recording…';
  });
  document.addEventListener('keydown',event=>{
    if(modal.hidden)return;
    if(event.key==='Escape'){event.preventDefault();close();}
    if(event.key==='Tab'){const focusable=[...modal.querySelectorAll('button,input,select,textarea,[tabindex="0"]')].filter(element=>!element.disabled&&element.getClientRects().length);const first=focusable[0],last=focusable.at(-1);if(event.shiftKey&&document.activeElement===first){event.preventDefault();last?.focus();}else if(!event.shiftKey&&document.activeElement===last){event.preventDefault();first?.focus();}}
  });
  window.thirdPartyFees={setScope(rows){scope=new Set(rows.map(row=>row.dataset.feeBookingKey));cards();if(!modal.hidden)showTable();},balance,totals};
  cards();
})();
