'use strict';
const fs = require('node:fs');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const events = {};
function option(id, name, price) {
  const check = { checked: false };
  const quantity = { value: '1', disabled: true, matches: selector => selector === '[data-addon-quantity]' };
  check.matches = selector => selector === '[data-addon-check]';
  return { dataset: { id: String(id), name, price: String(price) }, check, quantity, selected: false,
    classList: { toggle(_, value) { this.value=value; } },
    querySelector(selector) { return selector === '[data-addon-check]' ? check : quantity; } };
}
const rows = [option(1,'Lunch',125.5),option(2,'Gear',200.25)];
const subtotal = { textContent: '' }, limit = { hidden: true };
const picker = { querySelectorAll: () => rows, querySelector: selector => selector === '[data-addon-subtotal]' ? subtotal : limit,
  addEventListener: (name, fn) => { events[name]=fn; }, dispatchEvent() {} };
const summary = { lines: [], hidden: true, replaceChildren() { this.lines=[]; }, append(line) { this.lines.push(line); } };
const document = { querySelector: () => picker, querySelectorAll: () => [summary], createElement: () => ({ children: [], append(...children) { this.children.push(...children); } }) };
const ctx = { document, window: {}, CustomEvent: class {} };
vm.runInNewContext(fs.readFileSync('js/booking-addon-picker.js','utf8'),ctx);
assert.equal(ctx.window.BookingAddonPicker.total(),0);
assert.equal(summary.hidden,true);
rows[0].check.checked=true; rows[0].quantity.value='3'; rows[1].check.checked=true; rows[1].quantity.value='2';
events.change({target:rows[0].check});
assert.equal(ctx.window.BookingAddonPicker.total(),777);
assert.equal(subtotal.textContent.includes('777.00'),true);
assert.equal(summary.lines.length,2);
assert.equal(summary.lines[0].children[0].textContent,'Lunch × 3');
assert.equal(JSON.stringify(ctx.window.BookingAddonPicker.selections()),JSON.stringify([{addon_id:1,quantity:3},{addon_id:2,quantity:2}]));
rows[0].check.checked=false; events.change({target:rows[0].check});
assert.equal(rows[0].quantity.disabled,true);
assert.equal(ctx.window.BookingAddonPicker.total(),400.5);
rows[1].quantity.value='1.5'; events.input({target:rows[1].quantity});
assert.equal(ctx.window.BookingAddonPicker.total(),0);
assert.equal(ctx.window.BookingAddonPicker.selections()[0].quantity,1.5); // server rejects invalid quantity
for(let i=3;i<=27;i++)rows.push(option(i,'Extra '+i,1));
rows.forEach((row,index)=>{row.check.checked=index<25;row.quantity.value='1';});
vm.runInNewContext(fs.readFileSync('js/booking-addon-picker.js','utf8'),ctx);
rows[25].check.checked=true;
events.change({target:rows[25].check});
assert.equal(rows[25].check.checked,false);
assert.equal(limit.hidden,false);
rows[1].dataset.packageOnly='1';
ctx.window.BookingAddonPicker.setDomain('package');
rows[1].check.checked=true;
events.change({target:rows[1].check});
assert.equal(rows[1].hidden,false);
ctx.window.BookingAddonPicker.setDomain('boat');
assert.equal(rows[1].hidden,true);
assert.equal(rows[1].check.checked,false);
assert.equal(rows[1].check.disabled,true);
assert.equal(ctx.window.BookingAddonPicker.selections().some(item=>item.addon_id===2),false);
ctx.window.BookingAddonPicker.setDomain('tourguide');
assert.equal(rows[1].hidden,true);
ctx.window.BookingAddonPicker.setDomain('package');
assert.equal(rows[1].hidden,false);
assert.equal(rows[1].check.disabled,false);
console.log('PASS: add-on selection, quantities, centavo totals, review rows, deselection, invalid quantity handling, and selection limit.');
