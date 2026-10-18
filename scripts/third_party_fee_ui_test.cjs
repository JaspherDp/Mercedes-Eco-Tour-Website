'use strict';
const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict'),path=require('node:path');
class Element{
 constructor(dataset={}){this.dataset=dataset;this.hidden=false;this.value='';this.events={};this.textContent='';this.innerHTML='';this.classList={toggle(){}};this.disabled=false;}
 addEventListener(type,callback){this.events[type]=callback;}focus(){}getClientRects(){return [1];}setAttribute(){}querySelector(){return new Element();}querySelectorAll(){return [];}append(option){this.value=option.value;}
}
const ids={};const get=id=>ids[id]||(ids[id]=new Element());
const modal=get('feeCollectionModal');modal.hidden=true;
const closeButton=new Element(),backButton=new Element();
const tabs=['docking','entrance','environmental'].map(type=>new Element({feeTab:type}));
modal.querySelectorAll=selector=>selector==='[data-fee-close]'?[closeButton]:selector==='[data-fee-back]'?[backButton]:selector==='[data-fee-tab]'?tabs:[];
modal.querySelector=selector=>selector==='[data-fee-close]'?closeButton:get('modalContent');
const cards=['docking','entrance','environmental'].flatMap(type=>['collected','settled','available'].map(key=>new Element({feeCard:type+':'+key})));
const expand=['docking','entrance','environmental'].map(type=>new Element({feeExpand:type}));
const rows=['boat:1','boat:2'].map((key,i)=>{const row=new Element({feeBookingKey:key});row.querySelector=()=>({textContent:'BK-'+(i+1)});return row;});
const form=get('thirdPartySettlementForm');form.elements={fee_method:{value:'cash'},fee_amount:get('feeSettlementAmount')};form.reset=()=>form.elements.fee_method.value='cash';form.reportValidity=()=>true;const submitButton=new Element();form.querySelector=()=>submitButton;
const document={body:{style:{}},querySelector:()=>null,querySelectorAll:selector=>selector==='[data-fee-booking-key]'?rows:selector==='[data-fee-card]'?cards:selector==='[data-fee-expand]'?expand:[],getElementById:get,addEventListener(){},createElement:()=>new Element()};
const pools=[{key:'dock',category:'docking',location:'Canimog Island',rates:[{label:'Canimog Island',unit:'per boat',amount_minor:50000}],collected:{'boat:1':50000,'boat:2':30000},settled:{'boat:1':20000},history:[{allocations:{'boat:1':20000},settled_at:'2026-10-18 12:00:00',recipient_name:'Owner',settlement_method:'cash',reference_number:'R1'}]},{key:'dockzero',category:'docking',location:'Malasugui Island',rates:[{label:'Malasugui Island',unit:'per boat',amount_minor:30000}],collected:{},settled:{},history:[]},{key:'entry',category:'entrance',location:'<Island>',rates:[],collected:{'boat:2':20000},settled:{},history:[]}];
const window={payoutPageData:{thirdParty:{ready:true,pools}},addEventListener(){}};
const context={document,window,Intl,Set,Number,Object,Array,Math,JSON};vm.createContext(context);vm.runInContext(fs.readFileSync(path.join(__dirname,'../js/third-party-fees.js'),'utf8'),context);
assert.match(cards.find(e=>e.dataset.feeCard==='docking:available').textContent,/600.00/);
window.thirdPartyFees.setScope([rows[0]]);assert.match(cards.find(e=>e.dataset.feeCard==='docking:available').textContent,/300.00/);
expand[0].events.click();assert.equal(modal.hidden,false);assert.match(get('feeCollectionRows').innerHTML,/Malasugui Island/);assert.match(get('feeCollectionRows').innerHTML,/0.00/);assert.match(get('feeCollectionRows').innerHTML,/Settle payout/);
const pay=new Element({feePay:'dock'});modal.events.click({target:{closest:s=>s==='[data-fee-pay]'?pay:null}});
assert.equal(get('feeCollectionSettleView').hidden,false);assert.equal(get('feeSettlementAmount').value,'300.00');assert.equal(get('feeSettlementAmount').max,'300.00');assert.equal(get('feeOnlineFields').hidden,true);assert.equal(get('feeReference').required,false);
form.elements.fee_method.value='online';get('feeChannel').value='Bank transfer';get('feeChannel').events.change();assert.equal(get('feeOnlineFields').hidden,false);assert.equal(get('feeReference').required,true);assert.equal(get('feeCustomChannel').required,true);
backButton.events.click();const detail=new Element({feeDetails:'dock'});modal.events.click({target:{closest:s=>s==='[data-fee-details]'?detail:null}});assert.equal(get('feeCollectionDetailView').hidden,false);assert.match(get('feeCollectionDetails').innerHTML,/Payout history/);assert.match(get('feeCollectionDetails').innerHTML,/Owner/);
tabs[1].events.click();assert.match(get('feeCollectionRows').innerHTML,/&lt;Island&gt;/);assert.doesNotMatch(get('feeCollectionRows').innerHTML,/<Island>/);
closeButton.events.click();assert.equal(modal.hidden,true);
console.log('Passed fee modal UI checks: filtered balances, zero records, expand, details, cash/online fields, and escaped labels.');
