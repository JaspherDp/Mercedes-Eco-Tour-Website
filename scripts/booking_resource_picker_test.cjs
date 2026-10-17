const fs = require('node:fs');
const vm = require('node:vm');
const assert = require('node:assert/strict');
class Element {
  constructor(tag) { this.tagName = tag; this.children = []; this.dataset = {}; this.listeners = {}; this.value = ''; }
  appendChild(child) { this.children.push(child); return child; }
  replaceChildren() { this.children = []; }
  add(option) { this.children.push(option); }
  addEventListener(event, fn) { this.listeners[event] = fn; }
  querySelectorAll(selector) {
    const resource = selector.match(/data-resource="(.*?)"/)?.[1];
    const result = [];
    const visit = node => { if (node.tagName === 'select' && (!resource || node.dataset.resource === resource)) result.push(node); (node.children || []).forEach(visit); };
    this.children.forEach(visit); return result;
  }
}
const container = new Element('div');
const window = {};
vm.runInNewContext(fs.readFileSync(require('node:path').join(__dirname, '../js/booking-resource-picker.js'), 'utf8'), {
  window, document: { createElement: tag => new Element(tag) },
  Option: class { constructor(text, value) { this.text = text; this.value = value; } }
});
let pax = 1, type = 'boat', addon = '', primaryBoat = 1, primaryGuide = 1;
const picker = new window.BookingResourcePicker({ container,
  boats: [{ id: 1, name: 'A', capacity: 8 }, { id: 2, name: 'B', capacity: 8 }, { id: 3, name: 'C', capacity: 4 }, { id: 4, name: 'D', capacity: 12 }],
  guides: [{ id: 1, name: 'G1' }, { id: 2, name: 'G2' }, { id: 3, name: 'G3' }],
  type: () => type, addon: () => addon, pax: () => pax,
  primaryBoat: () => primaryBoat, primaryGuide: () => primaryGuide, change: () => picker.sync()
});
const choose = (resource, index, id) => { const select = container.querySelectorAll('select[data-resource="' + resource + '"]')[index]; assert(select); select.value = String(id); select.listeners.change(); };
for (const [count, required] of [[1,1],[8,1],[9,2],[16,2],[17,3]]) {
  pax = count; picker.sync(); assert.equal(picker.boatCount, required);
}
for (const [count, required] of [[8,1],[9,2],[16,2],[17,3]]) {
  pax=count;addon='tourguide';picker.sync();
  assert.equal(picker.guideCount,required,'Optional guides must use total pax at eight each');
  addon='';picker.sync();assert.equal(picker.guideCount,0,'Removing the add-on must remove guide charges');
}
pax = 9; picker.sync(); assert.match(picker.validate(), /Select/); choose('boat', 0, 2); assert.equal(picker.validate(), '');
pax = 8; picker.sync(); assert.equal(picker.boatIds.length, 1, 'Decreasing pax removes extra boats');
pax = 9; picker.sync(); assert.equal(picker.boatIds[1], 0, 'Removed selections do not return');
choose('boat', 0, 3); assert.equal(picker.boatCount, 2);
pax = 13; picker.sync(); assert.equal(picker.boatCount, 3, 'Smaller actual capacity requires another boat');
primaryBoat = 4; pax = 9; picker.sync(); assert.equal(picker.boatCount, 2, 'Larger boats remain capped at eight');
addon = 'tourguide'; picker.sync(); assert.equal(picker.guideCount, 2);
choose('tourguide', 0, 1); choose('tourguide', 1, 2);
assert.equal(picker.guideIds.join(','), '1,2');
addon = ''; picker.sync(); assert.equal(picker.guideCount, 0);
type = 'tourguide'; pax = 17; picker.sync(); assert.equal(picker.guideCount, 3);
choose('tourguide', 0, 2); choose('tourguide', 1, 3); assert.equal(picker.validate(), '');
pax = 8; picker.sync(); assert.equal(picker.guideCount, 1);
addon = 'boat'; pax = 9; picker.sync(); assert.equal(picker.boatCount, 2); choose('boat', 0, 1); choose('boat', 1, 2); choose('tourguide', 0, 2); assert.equal(picker.validate(), '');
primaryGuide = 0; picker.sync(); assert.match(picker.validate(), /Select/);
type = 'package'; addon = ''; picker.sync(); assert.equal(picker.boatCount, 0); assert.equal(picker.guideCount, 0); assert(container.hidden);
console.log('PASS: guest increases/decreases, 1/2/3 resources, smaller boats, eight-seat maximum, missing resources, guide and boat add-ons, package isolation.');

// Exercise the actual admin modal guide controls as counts and dates change.
const adminSource = fs.readFileSync(require('node:path').join(__dirname, '../admin/adbookings.php'), 'utf8');
const adminStart = adminSource.indexOf('  const syncGuideCapacity =');
const adminEnd = adminSource.indexOf('  let availabilityRequest =', adminStart);
assert(adminStart >= 0 && adminEnd > adminStart);
const adminBox = new Element('div');
const guideControl = { value: '1', setCustomValidity(message) { this.error = message; } };
const adminScope = {
  additionalGuides: adminBox, typeSelect: { value: 'tourguide' },
  adultsInput: { value: '6' }, childrenInput: { value: '3' }, guideSelect: guideControl,
  guideCatalog: [{ guide_id: 1, fullname: 'G1', available: true }, { guide_id: 2, fullname: 'G2', available: true }, { guide_id: 3, fullname: 'G3', available: true }],
  document: { createElement: tag => new Element(tag) }, Option: class { constructor(text,value) { this.text = text; this.value = value; } },
  syncCapacity() { adminScope.update(); }, syncSuggestedPrice() {}
};
vm.createContext(adminScope);
vm.runInContext(adminSource.slice(adminStart, adminEnd) + '\nglobalThis.update = syncGuideCapacity;', adminScope);
adminScope.update(); assert.equal(adminBox.querySelectorAll('select').length, 1); assert(guideControl.error);
adminBox.querySelectorAll('select')[0].value = '2'; adminScope.update(); assert.equal(guideControl.error, '');
adminScope.adultsInput.value = '14'; adminScope.update(); assert.equal(adminBox.querySelectorAll('select').length, 2); assert(guideControl.error);
adminBox.querySelectorAll('select')[1].value = '3'; adminScope.update(); assert.equal(guideControl.error, '');
adminScope.childrenInput.value = '0'; adminScope.adultsInput.value = '8'; adminScope.update(); assert.equal(adminBox.querySelectorAll('select').length, 0);
adminScope.adultsInput.value = '9'; adminScope.update(); assert.equal(adminBox.querySelectorAll('select')[0].value, '');
adminBox.querySelectorAll('select')[0].value = '2'; adminScope.guideCatalog[1].available = false; adminScope.update(); assert(guideControl.error); assert.equal(adminBox.querySelectorAll('select')[0].value, '');
adminScope.typeSelect.value = 'boat'; adminScope.update(); assert(adminBox.hidden); assert.equal(guideControl.error, '');
console.log('PASS: actual admin guide selection, two/three guides, count decreases, unavailable guides, and hidden-field validation reset.');

// Execute the tourist page's real price-preview function with configured fixture rates.
const page = fs.readFileSync(require('node:path').join(__dirname, '../public/tour_booking.php'), 'utf8');
const priceStart = page.indexOf('      const calculatePricing =');
const priceEnd = page.indexOf('      const updateSectionsByType =', priceStart);
assert(priceStart >= 0 && priceEnd > priceStart);
let adults = 6, children = 3, rateKey = 'day';
const priceScope = {
  bookingType: { value: 'tourguide' }, packageSelect: { selectedOptions: [{ dataset: {} }] },
  getSelectedLocations: () => ['Apuao Grande Island'], getAdultCount: () => adults, getChildCount: () => children,
  getRateKey: () => rateKey, getAddOnType: () => 'boat',
  resourcePicker: { boatCount: 2, guideCount: 2 }, MAX_BOAT_PAX: 8,
  getServiceRate: (type,key) => ({ boat: { day: 1000, overnight: 1700 }, tourguide: { day: 250, overnight: 400 } })[type][key],
  ecoCategory: { value: 'local' }, OTHER_FEES: { environmental: { local: 11 }, docking: {} },
  getEntranceFeePerHead: () => 20, window: { BookingAddonPicker: { total: () => 0 } }, paymentOption: { value: 'partial' }
};
vm.createContext(priceScope);
vm.runInContext(page.slice(priceStart,priceEnd) + '\nglobalThis.price = calculatePricing;',priceScope);
let quote = priceScope.price(); assert.equal(quote.grandTotal, 2746); assert.equal(quote.environmentalFee,66); assert.equal(quote.payableNow,549.2);
rateKey = 'overnight'; quote = priceScope.price(); assert.equal(quote.grandTotal,4446); assert.equal(quote.payableNow,889.2);
children = 0; quote = priceScope.price(); assert.equal(quote.environmentalFee,66);
priceScope.paymentOption.value = 'full'; quote = priceScope.price(); assert.equal(quote.payableNow,quote.grandTotal);
console.log('PASS: actual tourist frontend totals match PHP fixtures for day/overnight, adults-only environmental fees, full passenger counts, 20% deposit and full payment.');

// Only one Children count is collected. No second age group or hidden age split.
assert(!page.includes('tourYoungChildren'));
assert(!page.includes('num_young_children'));
assert(!page.includes("select.name = 'child_ages[]'"));
assert(!adminSource.includes('walkinYoungChildren'));
assert(!adminSource.includes('num_young_children'));
assert(page.includes('Adults (14+ years)') && page.includes('Children (0–13 years)'));
assert(adminSource.includes('Adults (14+ years)') && adminSource.includes('Children (0–13 years)'));
console.log('PASS: one child count only, no age-split field, corrected 14+ adult labels and unchanged child pax inclusion.');

// Exercise the page's real navigation and submit handlers. These formerly referenced
// a removed boat-confirmation variable, blocking boat bookings before payment.
const stepStart=page.indexOf('      const validateCurrentStep =');
const stepEnd=page.indexOf('      nextStepButton.addEventListener',stepStart);
assert(stepStart>=0 && stepEnd>stepStart);
let formErrors={};
const stepScope={
  currentStep:1, bookingType:{value:'boat'}, MAX_BOAT_PAX:8, getTotalGuests:()=>10,
  validateForm:()=>formErrors, stepFieldKeys:{1:['num_adults','num_children'],2:['selected_resources']},
  renderValidationErrors(){},focusFirstInvalidField(){},showToast(){}
};
vm.createContext(stepScope);
vm.runInContext(page.slice(stepStart,stepEnd)+'\nglobalThis.checkStep=validateCurrentStep;',stepScope);
assert.equal(stepScope.checkStep(),true,'Boat groups above eight must reach resource selection');
stepScope.currentStep=2;formErrors={selected_resources:'Select both guides'};
assert.equal(stepScope.checkStep(),false,'Missing guide selections must block advancing');
formErrors={};assert.equal(stepScope.checkStep(),true);

async function verifyBoatSubmission() {
  let handler,submissions=0;const navigations=[];
  const submitScope={
    bookingForm:{addEventListener(event,fn){assert.equal(event,'submit');handler=fn;}},
    isOpeningPayment:false,bookingType:{value:'boat'},MAX_BOAT_PAX:8,getTotalGuests:()=>10,
    validateForm:()=>formErrors,fieldOrder:['selected_resources'],stepFieldKeys:{2:['selected_resources']},
    showBookingStep(){},renderValidationErrors(){},focusFirstInvalidField(){},showToast(){},clearAllErrors(){},
    calculatePricing:()=>({usesBoat:true,totalGuests:10,requiredBoats:2}),
    setOpeningPaymentState(value){submitScope.isOpeningPayment=value;},
    submitBooking:async()=>{submissions++;return {success:true,checkout_url:'https://fixture.invalid/checkout'};},
    leavingForPayment:false,window:{location:{assign:url=>navigations.push(url)}},
    Swal:{fire(){throw new Error('Unexpected payment error or confirmation');}}
  };
  vm.createContext(submitScope);
  const first=page.indexOf('      bookingForm.addEventListener("submit"');
  const last=page.indexOf('      showBookingStep(1, { scroll: false });',first);
  assert(first>=0 && last>first);vm.runInContext(page.slice(first,last),submitScope);
  formErrors={selected_resources:'Choose two guides'};await handler({preventDefault(){}});assert.equal(submissions,0);
  formErrors={};await handler({preventDefault(){}});assert.equal(submissions,1);assert.equal(navigations.length,1);
  console.log('PASS: actual two-boat navigation and submission; missing guides blocked; valid booking reaches mocked checkout without JavaScript errors.');
}
verifyBoatSubmission().catch(error=>{console.error(error);process.exitCode=1;});
