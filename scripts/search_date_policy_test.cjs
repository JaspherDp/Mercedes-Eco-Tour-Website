const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const root = path.join(__dirname, '..');
const read = file => fs.readFileSync(path.join(root, file), 'utf8');
const context = vm.createContext({ window: {}, Intl, Date });
vm.runInContext(read('js/search-date-policy.js'), context);
const minimum = context.window.SearchDatePolicy.minimumDate;
let checks = 0;
for (const [instant, expected] of [
  ['2026-10-19T00:00:00+08:00', '2026-10-21'],
  ['2026-10-19T23:59:59+08:00', '2026-10-21'],
  ['2026-10-19T16:00:00Z', '2026-10-22'],
  ['2026-12-31T12:00:00+08:00', '2027-01-02'],
  ['2028-02-28T12:00:00+08:00', '2028-03-01']
]) {
  for (const tab of ['tours', 'guides', 'boats', 'bundle']) {
    assert.equal(minimum(tab, new Date(instant)), expected);
    checks++;
  }
}
assert.equal(minimum('hotels', new Date('2026-10-19T00:00:00+08:00')), '2026-10-19');
checks++;

// Execute the real landing-page picker initializers and tab minimum sync.
const landing = read('public/hotel_resorts.php');
const elements = Object.fromEntries(['checkin', 'checkout', 'dateRangePicker', 'stayDurationBadge']
  .map(id => [id, { value: '', hidden: false, classList: { remove() {} } }]));
let options;
const pickerContext = vm.createContext({
  window: { innerWidth: 1000, SearchDatePolicy: {
    updateCalendarNote: context.window.SearchDatePolicy.updateCalendarNote,
    minimumDate: tab => minimum(tab, new Date('2026-10-19T12:00:00+08:00'))
  } },
  document: { getElementById: id => elements[id] },
  Date, requestAnimationFrame() {},
  flatpickr(_input, config) {
    options = config;
    return { set(key, value) { options[key] = value; }, clear() {}, destroy() {} };
  }
});
vm.runInContext('let fp = null; let activeSearchTab = "tours";', pickerContext);
const syncStart = landing.indexOf('  function syncSearchDateMinimum()');
const syncEnd = landing.indexOf('\n  }', syncStart) + '\n  }'.length;
vm.runInContext(landing.slice(syncStart, syncEnd), pickerContext);
const rangeStart = landing.indexOf('  function initFlatpickrDateRange()');
const singleStart = landing.indexOf('  function initFlatpickrSingleDate()');
const rangeEnd = landing.indexOf('  function redirectToUnifiedSearch()', singleStart);
vm.runInContext(landing.slice(rangeStart, rangeEnd), pickerContext);
for (const tab of ['tours', 'guides', 'boats', 'hotels']) {
  const expected = tab === 'hotels' ? '2026-10-19' : '2026-10-21';
  vm.runInContext(`activeSearchTab = ${JSON.stringify(tab)}; initFlatpickrDateRange();`, pickerContext);
  assert.equal(options.mode, 'range');
  assert.equal(options.minDate, expected);
  vm.runInContext('initFlatpickrSingleDate();', pickerContext);
  assert.equal(options.mode, 'single');
  assert.equal(options.minDate, expected);
  checks += 4;
}
elements.checkin.value = '2026-10-19';
elements.checkout.value = '2026-10-20';
vm.runInContext('activeSearchTab = "boats"; syncSearchDateMinimum();', pickerContext);
assert.equal(elements.checkin.value, '');
assert.equal(elements.checkout.value, '');
assert.equal(elements.checkin.min, '2026-10-21');
assert.equal(elements.stayDurationBadge.hidden, true);
checks += 4;

// The secondary homepage emits native date controls with the same minimum.
vm.runInContext(read('js/unified_search.js').replace(/document.addEventListener\("DOMContentLoaded",[\s\S]*$/, ''), context);
vm.runInContext('globalThis.search = Object.create(UnifiedSearch.prototype); search.formState = { boats: {} }; search.currentTab = "boats";', context);
const nativeField = vm.runInContext('search.buildDateField("date", {})', context);
assert.ok(nativeField.includes(`min="${minimum('boats')}"`));
checks++;

// Parse embedded JavaScript too, after replacing server-rendered scalar values.
for (const file of ['public/hotel_resorts.php', 'public/search_results.php', 'public/homepage_new.php']) {
  const source = read(file).replace(/<\?[\s\S]*?\?>/g, '0');
  for (const [, attributes, body] of source.matchAll(/<script\b([^>]*)>([\s\S]*?)<\/script>/gi)) {
    if (/\bsrc\s*=|application\/(?:ld\+)?json/.test(attributes)) continue;
    new vm.Script(body, { filename: file });
    checks++;
  }
}
console.log(`PASS: ${checks} search calendar checks; no browser, database, or payment calls.`);
