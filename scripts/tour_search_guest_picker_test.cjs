const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const source = fs.readFileSync(path.join(__dirname, '../public/hotel_resorts.php'), 'utf8');
const extract = (start,end) => {
  const first = source.indexOf(start), last = source.indexOf(end, first);
  assert(first >= 0 && last > first, 'Search guest fixture boundary changed');
  return source.slice(first,last);
};
const row = { classList: { add() {}, remove() {} } };
const element = (text='') => ({ innerText: text, innerHTML: '', hidden: false, style: {}, value: '', closest: () => row });
const nodes = {
  adults: element('1'), children: element('2'), rooms: element('1'), roomsGuestRow: row,
  guestAdultsLabel: element(), guestChildrenLabel: element(), tourGuestAgePolicy: element(), guestBox: element(),
  checkin: { value: '2099-01-03' }, checkout: { value: '2099-01-04' }
};
const ageBox = element();
let ages = [], hotelRenders = 0, lastError = '';
Object.defineProperty(ageBox, 'innerHTML', {
  get() { return this.html || ''; },
  set(value) {
    this.html = value; hotelRenders++;
    ages = [...value.matchAll(/<select[\s\S]*?<\/select>/g)].map(match => ({
      value: match[0].match(/value="(\d+)" selected/)?.[1] || '',
      addEventListener() {}, removeAttribute() {}, setAttribute() {}, closest: () => row
    }));
  }
});
ageBox.querySelectorAll = () => ages;
ageBox.insertAdjacentHTML = (_,html) => { ageBox.html += html; };
nodes.childAgeRows = ageBox;
assert(!source.includes('tourGuestAgePolicy'), 'Resolved age-policy notice must be removed');
const scope = {
  activeSearchTab: 'hotels', tourDateMode: 'overnight', URLSearchParams,
  document: {
    getElementById: id => nodes[id], querySelector: () => ({}),
    querySelectorAll: selector => selector === '.child-age-select' || selector === '#childAgeRows .child-age-select' ? ages : []
  },
  setError: (_,message) => { lastError=message; }, clearError: () => { lastError=''; },
  getSelectedSearchLocations: () => ['Apuao Grande Island'], buildTourDurationLabel: () => '',
  clearActiveSearchTabForExit() {}, window: { location: { href: '' } }
};
vm.createContext(scope);
vm.runInContext(extract('  function parseChildAges(', '  displayHotels(hotels);') +
  extract('  function redirectToUnifiedSearch()', '  document.addEventListener("DOMContentLoaded"'), scope);
scope.renderChildAgeRows(['5']);
assert.equal(ages.length,2); assert.equal(ages[0].value,'5'); assert.equal(ages[1].value,'');
assert.equal(scope.validateGuestSelection(),false,'Hotel age selection must stay required');
assert.match(lastError,/Child 2/);
for (const tab of ['tours','guides','boats']) {
  scope.activeSearchTab=tab;
  scope.syncSearchGuestClassification();
  const rendersBefore=hotelRenders;
  scope.renderChildAgeRows(['0','0']);
  assert.equal(ageBox.hidden,true); assert.equal(hotelRenders,rendersBefore,'Tour counts must not create or auto-fill ages');
  assert.match(nodes.guestAdultsLabel.innerHTML,/<small>\(14\+ years\)<\/small>/);
  assert.match(nodes.guestChildrenLabel.innerHTML,/<small>\(0–13 years\)<\/small>/);
  assert.equal(scope.getChildAges().length,0,'Hotel age choices must not leak into a tour search');
  assert.equal(scope.validateGuestSelection(),true,'Tour searches must accept counts without individual ages');
  scope.redirectToUnifiedSearch();
  const url=new URL(scope.window.location.href,'https://fixture.invalid/');
  assert.equal(url.searchParams.get('adults'),'1'); assert.equal(url.searchParams.get('children'),'2');
  assert.equal(url.searchParams.get('pax'),'3'); assert.equal(url.searchParams.has('child_ages'),false);
  nodes.children.innerText='0'; scope.renderChildAgeRows(); assert.equal(scope.validateGuestSelection(),true);
  nodes.adults.innerText='0'; assert.equal(scope.validateGuestSelection(),false,'Zero guests must remain invalid');
  nodes.adults.innerText='1'; nodes.children.innerText='2';
}
scope.activeSearchTab='hotels'; scope.syncSearchGuestClassification(); scope.renderChildAgeRows();
assert.equal(ageBox.hidden,false); assert.equal(ages[0].value,'5','Switching tabs must retain prior hotel age selections');
assert.equal(ages[1].value,'','A new child must not be assigned a default age');
assert.match(nodes.guestAdultsLabel.innerHTML,/18\+/); assert.match(nodes.guestChildrenLabel.innerHTML,/0.*17/);
ages[1].value='9'; assert.equal(scope.validateGuestSelection(),true);
scope.redirectToUnifiedSearch();
const hotelUrl=new URL(scope.window.location.href,'https://fixture.invalid/');
assert.equal(hotelUrl.searchParams.get('child_ages'),'5,9'); assert.equal(hotelUrl.searchParams.get('rooms'),'1');
delete nodes.rooms; assert.equal(scope.validateGuestSelection(),true,'Hotel searches must work without a room selector');
assert(!source.includes('id="roomsGuestRow"'));
scope.redirectToUnifiedSearch(); assert.equal(new URL(scope.window.location.href,'https://fixture.invalid/').searchParams.get('rooms'),'1');
console.log('PASS: all three tour tabs use count-only search with age-range labels; no default/stale child ages; tab switching preserves hotel ages and one-room searches.');
