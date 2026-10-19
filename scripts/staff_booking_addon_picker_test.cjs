
'use strict';
const assert=require('node:assert/strict'),fs=require('node:fs'),vm=require('node:vm'),path=require('node:path');
const root=path.join(__dirname,'..');
class Element {
  constructor(tag='div') { this.tag=tag;this.children=[];this.events={};this.value='';this.required=false;this.disabled=false;this.textContent='';this.classList={toggle:(_,on)=>{this.selected=on;}}; }
  append(...nodes) { nodes.forEach(node=>{node.parent=this;this.children.push(node);}); }
  before(node) { const index=this.parent.children.indexOf(this);node.parent=this.parent;this.parent.children.splice(index,0,node); }
  setAttribute(key,value) { this[key]=value; }
  closest() { let item=this;while(item && item.tag!=='form' && item.tag!=='label')item=item.parent;return item; }
  contains(target) { return target===this || this.children.some(child=>child.contains(target)); }
  addEventListener(type,fn) { (this.events[type] ||= []).push(fn); }
  dispatchEvent(event) { if(!event.target)event.target=this;(this.events[event.type]||[]).forEach(fn=>fn(event)); }
  walk() { return this.children.flatMap(child=>[child,...child.walk()]); }
  querySelector(selector) { if(this.fields && this.fields[selector])return this.fields[selector]; return this.walk().find(node=>selector==='[data-staff-addon-total]' && node.total)||null; }
}
function fixture(role,items) {
  const form=new Element('form'),rows=role==='operator'?null:new Element();
  if(rows){ rows.closest=()=>form;form.append(rows); }
  const makeManual=()=>{
    const row=rows?new Element():form;
    row.fields={};
    for(const name of ['expense_type[]','expense_name[]','unit_price[]','quantity[]','note[]']) {
      const label=new Element('label'),input=new Element('input');input.name=name;input.required=name!=='note[]';input.value=name==='quantity[]'?'1':'';
      label.append(input);row.append(label);row.fields['[name="'+name+'"]']=input;
      if(name==='note[]')row.fields['[name="note[]"], [name="notes[]"]']=input;
    }
    if(rows)rows.append(row);
    return row;
  };
  const manual=makeManual();
  if(!rows){const total=new Element();total.total=true;form.append(total);}
  const observers=[];
  const ctx={window:{},queueMicrotask:fn=>fn(),Event:class{constructor(type){this.type=type;}},MutationObserver:class{constructor(fn){observers.push(fn);}observe(){}},
    document:{createElement:tag=>new Element(tag),getElementById:id=>id==='staffBookingAddonCatalog'?{textContent:JSON.stringify(items)}:id===(role==='admin'?'adminAddonRows':role==='hotel'?'hoExpenseRows':'opExpenseForm')?(rows||form):null}};
  vm.runInNewContext(fs.readFileSync(path.join(root,'js/staff-booking-addon-picker.js'),'utf8'),ctx);
  const box=form.children.find(child=>child.className==='staff-saved-addons');
  const choices=box.children.filter(child=>child.className==='staff-saved-addon');
  const check=index=>choices[index].walk().find(node=>node.type==='checkbox');
  const qty=index=>choices[index].walk().find(node=>node.type==='number');
  const update=()=>box.dispatchEvent({type:'change',target:box});
  return {form,rows,manual,box,choices,check,qty,update,api:ctx.window.StaffBookingAddons,makeManual,observers};
}
for(const role of ['admin','hotel','operator']) {
  const f=fixture(role,[{addon_id:1,name:'<Kayaking>',price:500},{addon_id:2,name:'Lunch',price:125.5}]);
  assert.equal(f.box.children[0].textContent,'Saved add-ons');
  assert.equal(f.choices.length,2);assert.equal(f.manual.walk().some(n=>n.tag==='select'),false,'No per-row saved dropdowns');
  assert.equal(f.qty(0).disabled,true);assert.equal(f.api.total(f.form),0);
  f.check(0).checked=true;f.qty(0).value='2';f.check(1).checked=true;f.update();
  assert.equal(f.api.total(f.form),1125.5);assert.equal(f.qty(0).disabled,false);
  assert.equal(f.manual.fields['[name="expense_name[]"]'].value,'','Selecting saved items leaves manual fields alone');
  assert.equal(f.manual.fields['[name="expense_name[]"]'].required,false,'Untouched manual entry is optional with saved selection');
  f.manual.fields['[name="expense_name[]"]'].value='Manual charge';
  f.form.dispatchEvent({type:'input',target:f.manual.fields['[name="expense_name[]"]']});
  assert.equal(f.manual.fields['[name="unit_price[]"]'].required,true,'Partial manual rows must be completed');
  f.manual.fields['[name="unit_price[]"]'].value='10.25';f.manual.fields['[name="quantity[]"]'].value='3';
  f.update();
  if(role==='operator') assert.ok(f.form.querySelector('[data-staff-addon-total]').textContent.includes('1,156.25'));
  assert.equal(f.choices[0].walk().find(n=>n.tag==='strong').textContent,'<Kayaking>','Safe text labels');
  f.qty(0).value='1.5';f.update();assert.equal(f.api.total(f.form),125.5,'Invalid saved quantity is excluded from preview and rejected by server');
  f.api.reset(f.form);assert.equal(f.check(0).checked,false);assert.equal(f.qty(0).value,'1');assert.equal(f.api.total(f.form),0);
  if(f.rows){const next=f.makeManual();f.observers.forEach(fn=>fn());assert.equal(next.fields['[name="expense_name[]"]'].required,true);assert.equal(f.form.children.filter(n=>n.className==='staff-saved-addons').length,1);}
  f.check(0).checked=true;f.update();f.check(0).checked=false;f.form.dispatchEvent({type:'reset',target:f.form});
  assert.equal(f.api.total(f.form),0);assert.equal(f.qty(0).disabled,true);
}
const empty=fixture('admin',[]);assert.equal(empty.choices.length,0);assert.ok(empty.box.children[1].textContent.includes('No saved add-ons'));
for(const file of ['admin/adbookings.php','operator/opbookings.php','Hobookings.php']) {
 const text=fs.readFileSync(path.join(root,file),'utf8');assert.equal((text.match(/StaffBookingAddonPicker\(/g)||[]).length,1);assert.ok(text.includes('StaffBookingAddonInput('));
}
assert.ok(fs.readFileSync(path.join(root,'js/admin-booking-addons.js'),'utf8').includes('StaffBookingAddons?.total'));
assert.ok(fs.readFileSync(path.join(root,'Hobookings.php'),'utf8').includes('StaffBookingAddons?.total'));
console.log('PASS: checkbox box above manual form in all panels, multiple saved choices, quantities/totals, optional untouched manual row, partial validation, empty catalogs, safe labels, row addition, and resets.');
