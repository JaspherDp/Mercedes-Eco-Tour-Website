const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const source = fs.readFileSync(path.join(__dirname,'../js/admin-service-pricing.js'),'utf8');
const makeNode = dataset => ({ dataset, listeners:{}, hidden:false, disabled:false, attributes:{},
  classList:{toggle(){}}, setAttribute(key,value){this.attributes[key]=value;},
  addEventListener(event,fn){this.listeners[event]=fn;}
});
function fixture({ready=true,query='',saved=''}={}) {
  const tabs=[makeNode({panel:'prices'}),makeNode({panel:'addons'})];
  const panels=[makeNode({panelContent:'prices'}),makeNode({panelContent:'addons'})];
  const form=makeNode({}); form.action='../php/update_service_prices.php';
  const button=makeNode({}), field=makeNode({}), events={}, storage=new Map();
  storage.set('itour-settings-panel','security');
  if(saved)storage.set('itour-service-pricing-panel',saved);
  const requests=[],dialogs=[];
  let response={ok:true,json:async()=>({success:true,message:'Saved'})};
  const scope={
    URLSearchParams,document:{currentScript:{dataset:{pricesReady:ready?'1':'0'}},body:makeNode({}),
      getElementById:id=>id==='servicePricesForm'?form:button,
      querySelectorAll:selector=>selector==='[data-panel]'?tabs:selector==='[data-panel-content]'?panels:[field,button]},
    window:{location:{search:query},addEventListener:(name,fn)=>{events[name]=fn;}},
    sessionStorage:{getItem:key=>storage.get(key),setItem:(key,value)=>storage.set(key,value)},
    FormData:class{constructor(owner){assert.equal(owner,form);this.owner=owner;}},
    fetch:async(url,options)=>{requests.push({url,options});return typeof response==='function'?response():response;},
    Swal:{fire:async options=>{dialogs.push(options);}}
  };
  vm.runInNewContext(source,scope);
  return {tabs,panels,form,button,field,events,storage,requests,dialogs,setResponse:value=>{response=value;}};
}
async function run() {
  const f=fixture({saved:'addons'});
  assert(f.button.hidden);assert.equal(f.panels[0].hidden,true);assert.equal(f.panels[1].hidden,false);
  f.tabs[0].listeners.click();assert.equal(f.button.hidden,false);assert.equal(f.panels[1].hidden,true);
  assert.equal(f.tabs[0].attributes['aria-pressed'],'true');
  f.field.listeners.input();let warned=false;f.events.beforeunload({preventDefault(){warned=true;}});assert(warned);
  f.tabs[1].listeners.click();f.tabs[0].listeners.click();
  assert.equal(f.storage.get('itour-settings-panel'),'security','Pricing tabs must not overwrite the settings tab');
  let resolveFetch;f.setResponse(()=>new Promise(resolve=>{resolveFetch=resolve;}));
  const first=f.form.listeners.submit({preventDefault(){}});
  await f.form.listeners.submit({preventDefault(){}});
  assert.equal(f.requests.length,1,'Double submit must not send repeated price updates');assert(f.button.disabled);
  resolveFetch({ok:true,json:async()=>({success:true})});await first;
  assert.equal(f.requests[0].url,'../php/update_service_prices.php');
  assert.equal(f.requests[0].options.credentials,'same-origin');assert.equal(f.requests[0].options.method,'POST');
  assert.equal(f.dialogs[0].icon,'success');assert.equal(f.button.disabled,false);
  warned=false;f.events.beforeunload({preventDefault(){warned=true;}});assert.equal(warned,false,'Saved prices should clear the unsaved warning');
  f.field.listeners.input();f.setResponse({ok:false,json:async()=>({success:false,message:'Rejected'})});
  await f.form.listeners.submit({preventDefault(){}});assert.equal(f.dialogs[1].icon,'error');
  warned=false;f.events.beforeunload({preventDefault(){warned=true;}});assert(warned,'Failed save must retain the unsaved warning');
  const unavailable=fixture({ready:false,query:'?panel=prices'});assert(unavailable.button.disabled);
  await unavailable.form.listeners.submit({preventDefault(){}});assert.equal(unavailable.requests.length,0);
  const linked=fixture({query:'?panel=addons'});assert(linked.button.hidden);assert.equal(linked.panels[1].hidden,false);
  const fallback=fixture({query:'?panel=unknown'});assert.equal(fallback.panels[0].hidden,false);
  console.log('PASS: pricing/add-on tabs, independent tab memory, protected endpoint request, duplicate-save prevention, unsaved changes, errors, unavailable prices, and direct tab links.');
}
run().catch(error=>{console.error(error);process.exitCode=1;});
