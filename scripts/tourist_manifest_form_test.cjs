const fs = require('node:fs');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const source = fs.readFileSync(require('node:path').join(__dirname, '../js/tourist-manifest-form.js'), 'utf8');
const slice = (start, end) => source.slice(source.indexOf(start), source.indexOf(end, source.indexOf(start)));
const tick = () => new Promise(setImmediate);

async function fixture({ admin = true, editing = true, canEdit = true, saveFails = false } = {}) {
  const events = {};
  const alerts = [];
  const calls = [];
  const shown = [];
  const rows = [{ name: 'Passenger One' }, { name: 'Passenger Two' }];
  const address = {country:'Philippines',region:'Bicol',province:'Camarines Norte',city:'Mercedes',barangay:'San Roque',postal_code:'4601',street:'Main Street'};
  let reloaded = 0;
  let pdfOpened = 0;
  let buttonClick;
  const button = {dataset:{bookingId:'10',pax:'999',bookingType:'boat',manifestMode:editing?'edit':'submit'},closest:()=>null,addEventListener:(_,handler)=>{buttonClick=handler;}};
  const submit = {disabled:false,textContent:''};
  const modal = {style:{display:'none'}};
  const fields = {};
  const node = id => fields[id] ??= {value:'',textContent:'',style:{},addEventListener:(name,handler)=>{events[`${id}:${name}`]=handler;}};
  const rowContainer = {innerHTML:'',appendChild:row=>shown.push(row),querySelectorAll:()=>rows,addEventListener(){}};
  const form = {checkValidity:()=>true,reportValidity(){},querySelector:()=>submit,addEventListener:(name,handler)=>{events[`form:${name}`]=handler;}};
  class Fields {
    constructor(from){this.data=new Map();if(from)this.set('csrf_token','form-csrf');}
    set(key,value){this.data.set(key,String(value));} append(key,value){this.set(key,value);} has(key){return this.data.has(key);} get(key){return this.data.get(key);}
  }
  const context = {
    URLSearchParams,FormData:Fields,Number,console:{error(){}},
    window:{TouristManifestConfig:admin?{adminWalkin:true,locationsEndpoint:'php/geonames_locations.php'}:{},location:{pathname:admin?'/adbookings.php':'/php/profile.php',search:''}},
    document:{getElementById:id=>id==='touristModalUser'?modal:id==='touristRowsUser'?rowContainer:id==='touristFormUser'?form:node(id),
      querySelector:selector=>selector.includes('csrf_token')?{value:'admin-csrf'}:selector.includes('tourist-submit-user')?submit:null,
      querySelectorAll:()=>[button]},
    location:{reload:()=>{reloaded++;}},localStorage:{setItem(){}},
    alert:message=>alerts.push(message),Swal:{fire:async()=>({isConfirmed:true})},
    createTouristRowUser:row=>row||{},renumberTouristRows(){},updateAddButtonState(){},
    touristAddressFromRow:()=>address,openTouristAddressModal(){},openTouristProfilePdf(){pdfOpened++;},
    fetch:async(url,options)=>{
      calls.push({url,body:options.body});
      return {ok:true,json:async()=>options.body.has('fetch_tourists')?{success:true,can_edit:canEdit,pax:2,booking_type:'boat',edit_deadline_label:'Test cutoff',tourists:editing?rows:[]}:{success:!saveFails,updated:editing,message:saveFails?'Synthetic rejection':'Saved'}};
    }
  };
  vm.createContext(context);
  vm.runInContext(source.slice(0,source.indexOf('const touristModalUser')),context);
  // Test the real opening/submission event handlers against synthetic DOM and HTTP responses.
  vm.runInContext(`const touristModalUser=document.getElementById('touristModalUser');const touristRowsUser=document.getElementById('touristRowsUser');const bookingIdInput=document.getElementById('touristBookingId');let touristManifestEditing=false;`,context);
  vm.runInContext(slice('/* ---------- OPEN MODAL ---------- */','/* ---------- CLOSE MODAL ---------- */'),context);
  vm.runInContext(source.slice(source.indexOf('/* ---------- SUBMIT TOURISTS ---------- */')),context);
  await buttonClick();
  return {context,events,calls,modal,alerts,shown,form,rows,fields,submit,pdf:()=>pdfOpened,reloads:()=>reloaded,tick};
}

(async()=>{
  const admin=await fixture();
  assert.equal(admin.modal.style.display,'flex');
  assert.equal(admin.shown[0].name,'Passenger One','Saved passengers must populate Edit Tourist List.');
  assert.equal(admin.calls[0].body.get('action'),'fetch_walkin_manifest');
  assert.equal(admin.calls[0].body.get('csrf_token'),'admin-csrf');
  assert.equal(admin.fields.touristManifestMode.value,'edit');
  assert(!admin.fields.touristFormDescription.textContent.includes('undefined'));
  admin.events['form:submit'].call(admin.form,{preventDefault(){}});
  await tick();
  assert.equal(admin.calls[1].body.get('action'),'save_walkin_manifest');
  assert.equal(admin.modal.style.display,'none');
  assert.equal(admin.reloads(),1,'Saved admin list must reload the current tab to update Upload/Edit actions.');
  assert.equal(admin.pdf(),0,'Admin save must not call the tourist-account PDF route.');
  const failed=await fixture({saveFails:true});
  failed.events['form:submit'].call(failed.form,{preventDefault(){}});await tick();
  assert.equal(failed.modal.style.display,'flex');assert.equal(failed.reloads(),0,'Rejected edits must retain the form for retry.');
  const closed=await fixture({canEdit:false});
  assert.equal(closed.modal.style.display,'none');assert.equal(closed.alerts.length,1);
  const tourist=await fixture({admin:false});
  assert.equal(tourist.calls[0].url,'/php/profile.php');
  assert.equal(tourist.calls[0].body.get('action'),undefined,'Customer form must retain its original endpoint contract.');
  vm.runInContext('maxPax=2;',tourist.context);
  tourist.events['form:submit'].call(tourist.form,{preventDefault(){}});await tick();
  assert.equal(tourist.pdf(),1,'Customer success must retain its existing PDF option.');
  console.log('PASS: shared Admin/customer modal opens, loads existing tourists, dispatches scoped CSRF requests, trusts server pax, preserves failed edits, refreshes Upload/Edit actions, and retains customer endpoint/PDF behavior.');
})().catch(error=>{console.error(error);process.exitCode=1;});
