'use strict';
// Exercise the actual selection, filtering and settlement handlers with a DOM fixture. No network or DB.
const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict'),path=require('node:path');
class Element {
  constructor(dataset={}){this.dataset=dataset;this.hidden=false;this.disabled=false;this.checked=false;this.indeterminate=false;this.value='';this.textContent='';this.innerHTML='';this.events={};this.attributes={};this.classList={add(){},remove(){},toggle(){}};}
  addEventListener(type,fn){(this.events[type]??=[]).push(fn);}
  emit(type,event={}){for(const fn of this.events[type]||[])fn(event);}
  setAttribute(key,value){this.attributes[key]=value;}
  focus(){}
  querySelector(){return null;}
}
const ids={};const get=id=>ids[id]??=new Element();
const fields=['bulkSettlementBar','bulkSelectedProvider','bulkSelectedCount','bulkSelectedTotal','bulkClearSelection','bulkSettleSelected'];fields.forEach(get);
const rows=[new Element({state:'available',providerType:'hotel',providerKey:'hotel:1',search:'hr26-0005 resort',payoutAmount:'2107.90'}),new Element({state:'available',providerType:'hotel',providerKey:'hotel:1',search:'hr26-0004 resort',payoutAmount:'1994.62'}),new Element({state:'available',providerType:'boat',providerKey:'boat:1',search:'tb26-0001 marlyn',payoutAmount:'2964.85'})];
const records=[{id:1,reference:'HR26-0005',provider:'Resort <A>',provider_key:'hotel:1',amount_minor:210790,gross_minor:250000,fee_minor:14210,refunded_minor:0,booking_fees_minor:25000,context:'context-1'},
  {id:2,reference:'HR26-0004',provider:'Resort <A>',provider_key:'hotel:1',amount_minor:199462,gross_minor:240000,fee_minor:15538,refunded_minor:0,booking_fees_minor:25000,context:'context-2'},
  {id:3,reference:'TB26-0001',provider:'Marlyn',provider_key:'boat:1',amount_minor:296485,gross_minor:300000,fee_minor:3515,refunded_minor:0,booking_fees_minor:0,context:'context-3'}];
const boxes=records.map((record,i)=>{const box=new Element({bulkPayout:JSON.stringify(record)});box.closest=()=>rows[i];return box;});
const columns=[new Element(),new Element()];
const docEvents={};
const individual=new Element({settle:JSON.stringify({...records[2],amount:'₱2,964.85'})});
const document={getElementById:get,querySelectorAll:selector=>selector==='[data-bulk-payout]'?boxes:selector==='[data-bulk-column]'?columns:selector==='[data-settle]'?[individual]:[],addEventListener(type,fn){(docEvents[type]??=[]).push(fn);},dispatchEvent(event){for(const fn of docEvents[event.type]||[])fn(event);}};
let dialogs=[],submitted=0,opened=0,fetches=0,confirmNext=false;
const window={Swal:{fire:async options=>{dialogs.push(options);return {isConfirmed:confirmNext};}}};
const context={document,window,Swal:window.Swal,Intl,Map,Set,Number,Math,JSON,Array,Object,console,CustomEvent:class{constructor(type,options){this.type=type;this.detail=options.detail;}},
  fetch:async()=>{fetches++;return {ok:true,json:async()=>({success:true,destinations:[]})};},
  openModal:()=>opened++,closeActionMenus(){},escapeHtml:value=>String(value??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]))};
vm.createContext(context);
vm.runInContext(fs.readFileSync(path.join(__dirname,'../js/provider-bulk-settlement.js'),'utf8'),context);
const selection=window.providerBulkSettlement;
assert.equal(get('bulkSettlementBar').hidden,true);
selection.refresh('available');assert.equal(columns[0].hidden,false);
const choose=(index,checked=true)=>{boxes[index].checked=checked;boxes[index].emit('change');};
choose(0);assert.equal(boxes[1].disabled,false);assert.equal(boxes[2].disabled,true);assert.equal(get('bulkSelectedCount').textContent,'1');
choose(1);assert.match(get('bulkSelectedTotal').textContent,/4,102\.52/);
choose(1,false);assert.match(get('bulkSelectedTotal').textContent,/2,107\.90/);
get('bulkClearSelection').emit('click');assert.ok(boxes.every(box=>!box.disabled&&!box.checked));
choose(2);assert.equal(boxes[0].disabled,true);get('bulkClearSelection').emit('click');
choose(0);choose(1);assert.deepEqual(boxes.map(box=>box.checked),[true,true,false]);
get('bulkClearSelection').emit('click');assert.ok(boxes.every(box=>!box.checked&&!box.disabled));
choose(2);assert.deepEqual(boxes.map(box=>box.checked),[false,false,true]);
selection.clear();rows[0].hidden=true;choose(1);assert.deepEqual(boxes.map(box=>box.checked),[false,true,false]);
rows[0].hidden=false;selection.clear();selection.refresh('all');assert.ok(boxes.every(box=>box.disabled));assert.equal(columns[0].hidden,true);

// Run the real ledger filter handler to prove selection clears on search, filter and page changes.
const source=fs.readFileSync(path.join(__dirname,'../js/adearningsdisbursements.js'),'utf8');
const filterSource=source.slice(source.indexOf("  function applyLedgerFilters("),source.indexOf("  ledgerPagination?.addEventListener"));
let filters={search:'',status:'available',type:'all'};const emptyCell={};
Object.assign(context,{ledgerFilters:{},ledgerRows:rows,ledgerPage:1,ledgerPageSize:2,currentLedgerFilters:()=>filters,pageScope:()=>({stakeholder:'all',provider:'all'}),ledgerEmptyRow:{querySelector:()=>emptyCell},ledgerResultCount:{},ledgerFilteredTotal:{},ledgerTabs:[],ledgerReset:null,renderLedgerPagination(){},renderFeeCollections(){},totalLabel:amount=>String(amount),syncLedgerUrl(){}});
vm.runInContext(filterSource,context);
context.applyLedgerFilters();choose(0);filters={...filters,search:'marlyn'};context.applyLedgerFilters();assert.ok(boxes.every(box=>!box.checked));assert.deepEqual(rows.map(row=>row.hidden),[true,true,false]);
choose(2);filters={search:'',status:'available',type:'hotel'};context.applyLedgerFilters();assert.ok(boxes.every(box=>!box.checked));assert.deepEqual(rows.map(row=>row.hidden),[false,false,true]);
choose(0);filters={search:'',status:'available',type:'all'};context.ledgerPage=2;context.applyLedgerFilters('push',true);assert.ok(boxes.every(box=>!box.checked));assert.deepEqual(rows.map(row=>row.hidden),[true,true,false]);
context.ledgerPage=1;context.applyLedgerFilters('push',true);choose(0);filters.status='settled';context.applyLedgerFilters();assert.ok(boxes.every(box=>!box.checked));assert.equal(columns[0].hidden,true);

// Run the existing settlement modal handlers, including the added bulk confirmation path.
const form=get('settlementForm');form.elements={action:{value:'settle'},csrf_token:{value:'fixture-csrf'}};
const submitButton=new Element();form.querySelector=()=>submitButton;form.reset=()=>{};form.reportValidity=()=>true;form.requestSubmit=()=>submitted++;
get('settlementMethod').options=[{text:'Cash'}];get('settlementMethod').selectedIndex=0;
vm.runInContext(source.slice(source.indexOf('  const settlementModal='),source.indexOf('  const receiptDrawer=')),context);
const tick=()=>new Promise(resolve=>setImmediate(resolve));
(async()=>{
  filters={search:'',status:'available',type:'all'};context.applyLedgerFilters();choose(0);choose(1);
  get('bulkSettleSelected').emit('click');await tick();
  assert.equal(opened,1);assert.equal(fetches,1);assert.equal(submitted,0,'Opening bulk review never submits payouts');
  assert.equal(form.elements.action.value,'settle_bulk');assert.deepEqual(JSON.parse(get('bulkPayoutIds').value),[1,2]);
  assert.deepEqual(JSON.parse(get('bulkSettlementContexts').value),{'1':'context-1','2':'context-2'});
  assert.match(get('bulkSettlementReview').innerHTML,/HR26-0005/);assert.match(get('bulkSettlementReview').innerHTML,/HR26-0004/);
  assert.match(get('settlementAmount').textContent,/4,102\.52/);assert.equal(get('bulkSettlementReview').hidden,false);
  get('settlementMethod').value='cash';get('cashReceivedBy').value='Fixture recipient';get('settlementReference').value='TEST-REF';
  form.emit('submit',{preventDefault(){}});await tick();assert.equal(submitted,0,'Cancel confirmation preserves records');
  assert.match(dialogs.at(-1).html,/HR26-0005/);assert.match(dialogs.at(-1).html,/HR26-0004/);assert.match(dialogs.at(-1).html,/Resort &lt;A&gt;/);
  confirmNext=true;form.emit('submit',{preventDefault(){}});await tick();assert.equal(submitted,1);assert.equal(submitButton.disabled,true);assert.equal(dialogs.at(-1).confirmButtonText,'Confirm Settlement');
  confirmNext=false;selection.clear();individual.emit('click');await tick();assert.equal(form.elements.action.value,'settle_bulk','Cancelled individual review does not submit or replace the batch');
  confirmNext=true;individual.emit('click');await tick();await tick();assert.equal(form.elements.action.value,'settle');assert.equal(get('bulkSettlementReview').hidden,true);assert.equal(get('bulkPayoutIds').value,'[]');assert.equal(submitButton.disabled,false,'Individual review resets submit button');assert.equal(submitted,1);
  assert.equal(selection.money(1),'₱0.01');assert.equal(selection.money(410252),'₱4,102.52');
  console.log('Passed bulk selection and modal checks: providers, exact totals, row selection, clear, filters, pagination, tabs, escaped review, cancel, confirm and individual settlement.');
})().catch(error=>{console.error(error);process.exitCode=1;});
