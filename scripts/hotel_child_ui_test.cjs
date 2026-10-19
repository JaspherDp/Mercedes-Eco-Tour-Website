'use strict';
const fs = require('node:fs'), path = require('node:path'), vm = require('node:vm'), assert = require('node:assert/strict'), cp = require('node:child_process');
const root = path.join(__dirname, '..');
const read = file => fs.readFileSync(path.join(root, file), 'utf8');
const scope = { window: {} }; vm.createContext(scope); vm.runInContext(read('js/hotel-child-policy.js'), scope);
const policy = scope.window.HotelChildPolicy;
const cases = JSON.parse(cp.execFileSync('C:/xampp/php/php.exe', [path.join(__dirname, 'hotel_child_policy_test.php'), '--parity'], { encoding: 'utf8' }));
let checks = 0;
for (const item of cases) {
  const result = policy.quote(item.policy, item.adults, item.children, item.ages, item.nights, item.rate);
  assert.equal(result.childFeeTotal, item.quote.child_fee_total);
  assert.ok(Math.abs(result.total - item.quote.total_before_addons) < .009); checks += 2;
}
for (const age of [18, -1, '', 'invalid', null, 6.5, true]) { assert.throws(() => policy.ages([age], 1)); checks++; }
assert.equal(policy.ageLabel(5), 'Free'); assert.equal(policy.ageLabel(6), 'PHP 250/night'); checks += 2;
const walkin = read('Hobookings.php');
const first = walkin.indexOf('      const getWalkinPricing = () =>'), last = walkin.indexOf('      const setWalkinStep =', first);
const selected = { dataset: { price: '1500', occupancyPolicy: JSON.stringify({ adult_capacity: 2, child_allowance: 2, max_occupancy: 4 }) } };
const nodes = { hoWalkinRoom: { selectedOptions: [selected] }, hoWalkinAdults: { value: '2' }, hoWalkinChildren: { value: '2' } };
const walkinScope = { document: { getElementById: id => nodes[id] }, HotelChildPolicy: policy,
  getWalkinDates: () => ({ nights: 2 }), getWalkinChildAges: () => [4, 9] };
vm.createContext(walkinScope); vm.runInContext(walkin.slice(first, last) + '\nglobalThis.calculate=getWalkinPricing;', walkinScope);
assert.equal(walkinScope.calculate().total, 3500); checks++;
const booking = read('public/hotel_booking.php');
const validator = booking.slice(booking.indexOf('      const validateForm = () =>'), booking.indexOf('      const renderValidationErrors ='));
let payload = { adults: 2, children: 2, child_ages: [4,9], checkin: '2099-01-01', checkout: '2099-01-03' };
const touristScope = { window: {}, HotelChildPolicy: policy, hotelOccupancyPolicy: { adult_capacity:2, child_allowance:2, max_occupancy:4 }, unitPrice:1500, collectPayload:()=>({agree_privacy:true,agree_hotel_policy:true,...payload}) };
vm.createContext(touristScope); vm.runInContext(validator + '\nglobalThis.validate=validateForm;', touristScope);
assert.equal(Object.keys(touristScope.validate()).length, 0); checks++;
payload = { ...payload, adults:3 }; assert.ok(touristScope.validate().adults); checks++;
payload = { ...payload, adults:2, children:3, child_ages:[4,9,10] }; assert.ok(touristScope.validate().children); checks++;
payload = { ...payload, children:2, child_ages:[4] }; assert.ok(touristScope.validate().child_ages); checks++;
class Element {
  constructor(tag='div') { this.tag=tag; this.children=[]; this.dataset={}; this.handlers={}; this.value=''; }
  append(...nodes) { this.children.push(...nodes); }
  replaceChildren() { this.children=[]; }
  querySelectorAll(tag) { return this.children.flatMap(child => [ ...(child.tag===tag?[child]:[]), ...child.querySelectorAll(tag) ]); }
  add(option) { this.children.push(option); }
  setAttribute() {}
  addEventListener(name, callback) { this.handlers[name]=callback; }
}
const formNodes = { resultsHotelAdults:new Element('input'), resultsHotelChildren:new Element('input'), resultsHotelPax:new Element('input'), resultsHotelChildAges:new Element() };
formNodes.resultsHotelAdults.value='2'; formNodes.resultsHotelChildren.value='2'; formNodes.resultsHotelChildAges.dataset.ages='4,9';
const formScope={ document:{ getElementById:id=>formNodes[id], createElement:tag=>new Element(tag) }, Option:function(text,value){const element=new Element('option');element.value=value;element.textContent=text;return element;} };
vm.createContext(formScope); vm.runInContext(read('js/hotel-search-child-fields.js'), formScope);
const fields=()=>formNodes.resultsHotelChildAges.querySelectorAll('select');
assert.equal(fields().length,2); assert.equal(fields()[0].value,'4'); assert.equal(fields()[1].value,'9'); checks+=3;
formNodes.resultsHotelChildren.value='3';formNodes.resultsHotelChildren.handlers.input();
assert.equal(fields().length,3);assert.equal(fields()[0].value,'4');assert.equal(fields()[2].value,'');assert.equal(fields()[2].required,true);checks+=4;
formNodes.resultsHotelChildren.value='1';formNodes.resultsHotelChildren.handlers.input();
assert.equal(fields().length,1);assert.equal(fields()[0].value,'4');checks+=2;
formNodes.resultsHotelAdults.value='3';formNodes.resultsHotelAdults.handlers.input();assert.equal(formNodes.resultsHotelPax.value,'4');checks++;
// The existing shared receipt must itemize, rather than double-count, child fees.
const receipt = read('js/booking-payment-summary.js');
const receiptStart = receipt.indexOf('    let baseRows ='), receiptEnd = receipt.indexOf('    const baseChargeRows =', receiptStart);
const receiptScope = { hotel:true, booking:{room_type:'Couple',rooms_booked:1,nights:2,unit_price:1500,
  child_policy:{child_fee_total:500,paying_children:1,nights:2,child_ages:[4,9]}}, data:{serviceAmount:3500}, money:value=>'PHP '+value };
vm.createContext(receiptScope);vm.runInContext(receipt.slice(receiptStart,receiptEnd)+'\nglobalThis.rows=baseRows;',receiptScope);
assert.equal(receiptScope.rows.length,2);assert.equal(receiptScope.rows[0].amount,3000);assert.equal(receiptScope.rows[1].amount,500);checks+=3;
const details=read('public/hotel_details.php');
const savedScope={URLSearchParams,window:{location:{search:'?destination=Caringo&pax=3&children=1&child_ages=9'}},
  hotelData:{island:'Caringo'},islandInput:{options:[{value:'Caringo'}]}};
vm.createContext(savedScope);
vm.runInContext(details.slice(details.indexOf('    function parseChildAges('),details.indexOf('    function getChildAges('))+
  details.slice(details.indexOf('    function normalizeDetailsIsland('),details.indexOf('    function fillSearchFromData(')),savedScope);
assert.deepEqual(Array.from(savedScope.parseChildAges(',9')),['',9],'Incomplete draft preserves child positions without assigning age zero');
assert.equal(savedScope.detailsEntrySearchData().adults,2);assert.equal(savedScope.detailsEntrySearchData().children,1);checks+=3;
for(const file of ['public/hotel_booking.php','public/hotel_details.php','public/search_results.php','Hobookings.php','Horooms.php','php/profile.php']) {
  const source=read(file).replace(/<\?[\s\S]*?\?>/g,'null');
  for(const [,attributes,body] of source.matchAll(/<script\b([^>]*)>([\s\S]*?)<\/script>/gi)) {
    if (/\bsrc\s*=|application\/(?:ld\+)?json/i.test(attributes))continue;
    new vm.Script(body,{filename:file}); checks++;
  }
}
console.log('PASS: '+checks+' client checks, including '+cases.length+' PHP/JS parity cases, tourist/walk-in adapters and results age preservation.');

(async () => {
  const page=read('public/hotel_resorts.php');
  const start=page.indexOf('let hotelAvailabilityRequest = 0;'),end=page.indexOf('document.querySelectorAll(".filter")',start);
  const elements=Object.fromEntries(['featuredSection','carouselSection','recentlyViewedSection','heroSection','hotelList'].map(id=>[id,{style:{},textContent:''}]));
  const requests=[],pending=[];let shown=[];
  const state={URLSearchParams,hotels:[{id:1},{id:2}],filtered:[],hotelMatchesSearchIsland:()=>true,
    document:{getElementById:id=>elements[id]||null,querySelector:()=>null},
    fetch:url=>{requests.push(new URL(url,'https://fixture.invalid/'));return new Promise(resolve=>pending.push(resolve));},
    persistHotelSearchUrl(){},applyCheckboxFilters(){shown=state.filtered;},requestAnimationFrame:fn=>fn(),window:{scrollTo(){}}};
  vm.createContext(state);vm.runInContext(page.slice(start,end)+'\nglobalThis.checkHotels=applySearchWithoutValidation;',state);
  const first=state.checkHotels({island:'Caringo',adults:2,children:1,childAges:[4],checkin:'2099-01-01',checkout:'2099-01-03'});
  const second=state.checkHotels({island:'Caringo',adults:2,children:2,childAges:[4,9],checkin:'2099-01-01',checkout:'2099-01-03'});
  assert.equal(requests[1].searchParams.get('adults'),'2');assert.equal(requests[1].searchParams.get('children'),'2');assert.equal(requests[1].searchParams.get('child_ages'),'4,9');
  pending[1]({ok:true,json:async()=>({success:true,hotel_ids:[2]})});await second;
  pending[0]({ok:true,json:async()=>({success:true,hotel_ids:[1]})});await first;
  assert.deepEqual(Array.from(shown,item=>item.id),[2],'Stale availability must not overwrite a newer search');
  console.log('PASS: actual hotel property-search requests preserve child data, filter property IDs, and ignore stale responses.');
})().catch(error=>{console.error(error);process.exitCode=1;});
