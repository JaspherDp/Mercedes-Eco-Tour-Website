const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const source = fs.readFileSync(path.join(__dirname, '../admin/adbookings.php'), 'utf8');
const extract = (start, end) => {
  const first = source.indexOf(start);
  const last = source.indexOf(end, first);
  assert(first >= 0 && last > first);
  return source.slice(first, last);
};
const help = {};
const field = { hidden: true, querySelector: () => help, getBoundingClientRect: () => ({ top: 240 }) };
const makeInput = (value, capacity) => ({ value, dataset: { capacity }, checked: false, disabled: false, closest: () => ({ classList: { toggle() {} } }) });
const extra = [makeInput('2', 8), makeInput('3', 8), makeInput('4', 8)];
const handlers = [];
const adults = { value: '8', addEventListener: (_, handler) => handlers.push(handler) };
const children = { value: '0', addEventListener() {} };
const scrolls = [];
let priceUpdates = 0;
const scope = {
  boatSelect: { options: [{ dataset: { capacity: 8 } }], selectedIndex: 0 },
  adultsInput: adults, childrenInput: children, usesBoatTransport: () => true,
  boatCatalog: [{ boat_id: 2, available: true }, { boat_id: 3, available: true }, { boat_id: 4, available: false }],
  document: { getElementById: () => field },
  additionalBoats: { querySelectorAll: selector => extra.filter(input => !selector.includes(':checked') || (input.checked && !input.disabled)) },
  syncSuggestedPrice: () => priceUpdates++,
  window: { requestAnimationFrame: callback => callback() },
  modalBody: { scrollTop: 100, getBoundingClientRect: () => ({ top: 100 }), scrollTo: options => scrolls.push(options) }
};
vm.createContext(scope);
vm.runInContext(extract('  const syncAdditionalBoatVisibility =', '  const filterPackages =')
  + '\nglobalThis.update = syncAdditionalBoatVisibility;', scope);
scope.syncCapacity = scope.update;
scope.update();
assert.equal(field.hidden, true);
vm.runInContext(extract("  [adultsInput, childrenInput].forEach(input => input.addEventListener('input'", '  paxButtons.forEach(button => {'), scope);
const changePax = value => { adults.value = String(value); handlers[0](); };

changePax(9);
assert.equal(field.hidden, false);
assert.equal(scrolls.length, 0, 'Exceeding primary capacity must not scroll the modal.');
extra[0].checked = true;
scope.update();
assert.equal(extra[0].disabled, false, 'Selected boat must remain removable.');
assert.equal(extra[1].disabled, true, 'A second additional boat is blocked when only one is needed.');
assert.equal(extra[2].disabled, true, 'Unavailable boats remain blocked.');

changePax(16);
assert.equal(scrolls.length, 0, 'No scroll while selected boats cover all guests.');
changePax(17);
assert.equal(scrolls.length, 0, 'Crossing two-boat capacity must not scroll the modal.');
assert.equal(extra[1].disabled, false);
extra[1].checked = true;
scope.update();
assert.equal(extra.filter(input => input.checked).length, 2);
assert.match(help.textContent, /2 additional boats selected/);

changePax(9);
assert.equal(extra.filter(input => input.checked).length, 1, 'Pax decrease removes redundant boats.');
assert.equal(extra[1].disabled, true);
changePax(8);
assert.equal(field.hidden, true);
assert.equal(extra.some(input => input.checked), false);
assert(priceUpdates > 0);

// Smaller boat sizes are retained; larger vessels remain capped at eight.
scope.boatSelect.options[0].dataset.capacity = 12;
extra[0].dataset.capacity = 4;
extra[1].dataset.capacity = 12;
changePax(16);
extra[0].checked = true;
scope.update();
assert.equal(extra[1].disabled, false, 'Four extra seats cannot cover six missing seats.');
extra[1].checked = true;
scope.update();
assert.equal(extra[0].checked, false, 'The redundant smaller boat is removed.');
assert.equal(extra[1].checked, true);
assert.equal(extra[0].disabled, true);
extra[1].checked = false;
scope.update();
assert.equal(extra[0].disabled, false, 'Unchecking restores available choices.');
console.log('PASS: one/two additional-boat limits, pax decrease, unavailable boats, mixed capacities, price refresh, and no automatic scroll.');
