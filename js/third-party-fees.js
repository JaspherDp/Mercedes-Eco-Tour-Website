(() => {
  'use strict';
  const state=window.payoutPageData?.thirdParty||{ready:false,pools:[]};
  const pools=Array.isArray(state.pools)?state.pools:[];
  const modal=document.getElementById('feeCollectionModal');if(!modal)return;
  const esc=value=>String(value??'').replace(/[&<>"']/g,char=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[char]));
  const money=amount=>new Intl.NumberFormat('en-PH',{style:'currency',currency:'PHP'}).format(amount/100);
  const labels={docking:'Docking / landing fees',entrance:'Entrance fees',environmental:'Environmental fees'};
  const bookingRows=[...document.querySelectorAll('[data-fee-booking-key]')];
  let scope=new Set(bookingRows.map(row=>row.dataset.feeBookingKey)),category='docking',returnFocus=null,selectedPool=null,submitting=false;
  const form=document.getElementById('thirdPartySettlementForm');
  const tableView=document.getElementById('feeCollectionTableView'),detailView=document.getElementById('feeCollectionDetailView'),settleView=document.getElementById('feeCollectionSettleView');
  function balance(pool){
    let collected=0,settled=0;
    Object.entries(pool.collected||{}).forEach(([key,amount])=>{if(scope.has(key))collected+=Number(amount);});
    Object.entries(pool.settled||{}).forEach(([key,amount])=>{if(scope.has(key))settled+=Number(amount);});
    return {collected,settled,available:Math.max(0,collected-settled)};
  }
  function totals(type){return pools.filter(pool=>pool.category===type).reduce((sum,pool)=>{const values=balance(pool);Object.keys(sum).forEach(key=>sum[key]+=values[key]);return sum;},{collected:0,settled:0,available:0});}
  function rates(pool){return pool.rates.length?pool.rates.map(rate=>'<div class="fee-rate"><span>'+esc(pool.rates.length>1?rate.label:rate.unit)+'</span><strong>'+money(rate.amount_minor)+(pool.rates.length>1?' <small>/ '+esc(rate.unit)+'</small>':'')+'</strong></div>').join(''):'<span class="fee-muted">Not configured</span>';}
  function closeMenus(){modal.querySelectorAll('.fee-row-popover').forEach(menu=>menu.hidden=true);modal.querySelectorAll('[data-fee-menu]').forEach(button=>button.setAttribute('aria-expanded','false'));}
  function table(){
    const t=totals(category);
    document.getElementById('feeModalSummary').innerHTML=['collected','settled','available'].map(key=>'<div><span>'+({collected:'Collected',settled:'Settled',available:'To settle'}[key])+'</span><strong>'+money(t[key])+'</strong></div>').join('');
    modal.querySelectorAll('[data-fee-tab]').forEach(button=>{const active=button.dataset.feeTab===category;button.classList.toggle('active',active);button.setAttribute('aria-current',active?'true':'false');});
    document.getElementById('feeCollectionRows').innerHTML=pools.filter(pool=>pool.category===category).map(pool=>{
      const b=balance(pool),disabled=!state.ready||b.available<=0||pool.location==='Location not recorded';
      return '<tr><td><strong>'+esc(pool.location)+'</strong></td><td>'+rates(pool)+'</td><td class="right">'+money(b.collected)+'</td><td class="right fee-muted">'+money(b.settled)+'</td><td class="right fee-table-available">'+money(b.available)+'</td><td class="right"><button type="button" class="fee-row-toggle" data-fee-menu="'+pool.key+'" aria-label="Actions for '+esc(pool.location)+'" aria-expanded="false">⋮</button><div class="fee-row-popover" data-fee-popover="'+pool.key+'" hidden><button type="button" data-fee-details="'+pool.key+'">View details</button><button type="button" class="fee-settle-action" data-fee-pay="'+pool.key+'" '+(disabled?'disabled':'')+' title="'+(pool.location==='Location not recorded'?'A saved location is needed to record its payout':b.available<=0?'No balance to settle':'Record a payment you already made')+'"> <svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 9h18M7 14h4"/></svg>Settle payout</button></div></td></tr>';
    }).join('')||'<tr><td colspan="6" class="fee-empty-row">No fee locations are configured.</td></tr>';
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
  function open(type,button){category=type;returnFocus=button;modal.hidden=false;document.body.style.overflow='hidden';showTable();modal.querySelector('[data-fee-close]').focus();}
  function close(){if(submitting)return;closeMenus();modal.hidden=true;if(!document.querySelector('.payout-modal.open,.payout-drawer.open'))document.body.style.overflow='';returnFocus?.focus();}
  function details(pool){
    selectedPool=pool;const b=balance(pool);screen('details',pool.location,labels[pool.category]);
    const history=pool.history.map(payment=>{const amount=Object.entries(payment.allocations).reduce((sum,[key,value])=>sum+(scope.has(key)?Number(value):0),0);return {payment,amount};}).filter(row=>row.amount>0);
    const bookingDetails=bookingRows.filter(row=>scope.has(row.dataset.feeBookingKey)&&(pool.collected[row.dataset.feeBookingKey]||pool.settled[row.dataset.feeBookingKey])).map(row=>{const key=row.dataset.feeBookingKey;return '<tr><td>'+esc(row.querySelector('.booking-cell strong')?.textContent||key)+'</td><td class="right">'+money(pool.collected[key]||0)+'</td><td class="right">'+money(pool.settled[key]||0)+'</td><td class="right">'+money(Math.max(0,(pool.collected[key]||0)-(pool.settled[key]||0)))+'</td></tr>';}).join('');
    document.getElementById('feeCollectionDetails').innerHTML='<div class="fee-detail-balances">'+Object.entries(b).map(([key,value])=>'<div><span>'+({collected:'Collected',settled:'Settled',available:'To settle'}[key])+'</span><strong>'+money(value)+'</strong></div>').join('')+'</div><section class="fee-detail-section"><h4>Current prices</h4>'+rates(pool)+'</section><section class="fee-detail-section"><h4>Collection by booking</h4><div class="fee-modal-table-wrap"><table class="fee-modal-table"><thead><tr><th>Booking</th><th class="right">Collected</th><th class="right">Settled</th><th class="right">Remaining</th></tr></thead><tbody>'+(bookingDetails||'<tr><td colspan="4" class="fee-empty-row">No recorded collections in this view.</td></tr>')+'</tbody></table></div></section><section class="fee-detail-section"><h4>Payout history</h4><div class="fee-modal-table-wrap"><table class="fee-modal-table"><thead><tr><th>Date</th><th>Recipient</th><th>Method / channel</th><th>Reference / note</th><th class="right">Settled</th></tr></thead><tbody>'+(history.map(({payment:p,amount})=>'<tr><td>'+esc(p.settled_at)+'</td><td>'+esc(p.recipient_name)+'</td><td>'+esc(p.settlement_method==='cash'?'Cash':p.channel)+'</td><td>'+esc(p.reference_number||'—')+(p.note?'<small class="fee-history-note">'+esc(p.note)+'</small>':'')+'</td><td class="right">'+money(amount)+'</td></tr>').join('')||'<tr><td colspan="5" class="fee-empty-row">No payouts recorded for these collections.</td></tr>')+'</tbody></table></div></section>';
  }
  function methodFields(){
    const online=form.elements.fee_method.value==='online';document.getElementById('feeOnlineFields').hidden=!online;
    const channel=document.getElementById('feeChannel');channel.disabled=!online;channel.required=online;
    const custom=online&&['Bank transfer','Other online channel'].includes(channel.value);
    document.getElementById('feeCustomChannelLabel').hidden=!custom;document.getElementById('feeCustomChannel').required=custom;document.getElementById('feeCustomChannel').disabled=!custom;
    document.getElementById('feeReference').required=online;document.getElementById('feeReferenceLabel').innerHTML=online?'Transfer reference number <b>*</b>':'Receipt / acknowledgment reference <em>Optional</em>';
  }
  function settle(pool){
    const b=balance(pool);if(!state.ready||b.available<=0||pool.location==='Location not recorded')return;
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
  modal.addEventListener('click',event=>{
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
