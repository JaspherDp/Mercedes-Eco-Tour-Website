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
const nodes = {};
const node = id => nodes[id] ??= {};
const scope = {
  serviceAmountInput: { value: '2500.00' }, expenseInputs: [{ value: '150.00' }, { value: '230.00' }],
  totalInput: {}, grandTotalLabel: {}, paymentCollected: { checked: false }, paymentInput: {}, paymentMethod: { value: 'cash' },
  quoteValid: true, document: { getElementById: node }
};
vm.createContext(scope);
vm.runInContext(extract('  const syncBalance = () => {', '  const syncPaxButtons =') + '\nglobalThis.update=syncBalance;', scope);
scope.update();
assert.equal(scope.totalInput.value, '2880.00');
assert.equal(scope.paymentInput.value, '0.00');
assert.equal(scope.paymentMethod.disabled, false);
assert.equal(scope.paymentMethod.required, true);
assert.equal(node('walkinCollectionTitle').textContent, 'Full amount to collect');
assert.match(node('walkinPaymentStatus').textContent, /Unpaid/);
scope.paymentCollected.checked = true;
scope.paymentMethod.value = 'Cash';
scope.update();
assert.equal(scope.paymentInput.value, '2880.00');
assert.equal(scope.paymentMethod.disabled, false);
assert.equal(scope.paymentMethod.required, true);
assert.equal(node('walkinCollectionTitle').textContent, 'Full payment collected');
scope.paymentCollected.checked = false;
scope.update();
assert.equal(scope.paymentInput.value, '0.00');
assert.equal(scope.paymentMethod.value, 'Cash');
scope.paymentCollected.checked = true;
scope.quoteValid = false;
scope.update();
assert.equal(scope.paymentCollected.checked, false);
assert.equal(scope.paymentCollected.disabled, true);
assert.equal(scope.paymentInput.value, '0.00');
scope.quoteValid = true;
scope.paymentCollected.checked = true;
scope.paymentMethod.value = 'qr_code';
scope.update();
assert.equal(scope.paymentCollected.checked, false);
assert.equal(scope.paymentCollected.disabled, true);
assert.equal(node('walkinCashCollectionField').hidden, true);
assert.equal(node('walkinQrAdminDeviceSection').hidden, false);
assert.equal(scope.paymentInput.value, '0.00', 'QR must not be recorded as received before verification.');
assert.match(node('walkinPaymentStatus').textContent, /registered payment phone/);
assert.match(node('walkinPaymentStatus').textContent, /pending until PayMongo verifies/);

vm.runInContext(extract('function parseBookingMeta(', 'const bookingDetailIcons ='), scope);
assert.equal(scope.parseBookingMeta('Walk-in booking | Payment Option: Full Payment | Payment Status: Unpaid').paymentStatus, 'Unpaid');
assert.equal(scope.parseBookingMeta('Walk-in booking | Payment Option: Full Payment | Payment Status: Paid').paymentStatus, 'Paid');
const form = extract('<div id="walkinBookingModal"', '<!-- DECISION REASON MODAL -->');
assert(!form.includes('20%'));
assert(!form.includes('Payment received'));
assert(!form.includes('value="partial"'));
assert(form.indexOf('class="walkin-collection-total"') > form.indexOf('id="walkinPaymentStatus"'));
assert(form.includes('<span>Pay Booking</span>'));
assert(!source.includes('walkinQrHandoff'));
console.log('PASS: bottom collection amount, cash confirmation, QR stays unpaid, Pay Booking action, and accurate unpaid details.');

// Both payment cards use the same registered device and refresh loop.
(async () => {
  const phoneNodes = {};
  let pollingCallback;
  let refreshes = 0;
  let qrSelected = true;
  const phoneNode = id => phoneNodes[id] ??= { dataset: {}, classList: { add() {}, remove() {}, contains: () => true } };
  const registered = { registered: true, device: { device_name: 'Test registered phone', last_used_at: '2026-10-15 13:00:00' } };
  let devicePayload = registered;
  const phoneScope = {
    document: {
      getElementById: id => {
        if (id === 'paymentModal') return { style: { display: 'none' } };
        if (id === 'walkinPaymentMethod') return { value: qrSelected ? 'qr_code' : 'cash' };
        return phoneNode(id);
      },
      querySelector: () => ({ hidden: false })
    },
    adminPhoneRegistrationState: {},
    fetchRegisteredAdminPhone: async () => { refreshes++; return devicePayload; },
    window: { setInterval: callback => { pollingCallback = callback; return 1; }, clearInterval() {} }
  };
  vm.createContext(phoneScope);
  vm.runInContext(extract('function renderRegisteredAdminPhone(', 'function updatePhoneRegistrationStatus('), phoneScope);
  await phoneScope.refreshRegisteredAdminPhone();
  assert.equal(phoneNode('walkinQrAdminDeviceName').textContent, 'Test registered phone');
  assert.match(phoneNode('walkinQrAdminDeviceMeta').textContent, /Notifications active/);
  assert.equal(phoneNode('walkinManageAdminPhoneBtn').textContent, 'Change');
  devicePayload = { registered: false };
  await phoneScope.refreshRegisteredAdminPhone();
  assert.equal(phoneNode('walkinQrAdminDeviceName').textContent, 'No admin phone registered');
  assert.equal(phoneNode('walkinManageAdminPhoneBtn').textContent, 'Register a Phone');
  devicePayload = { registered: true, device: { device_name: 'Replacement phone' } };
  phoneScope.startPaymentDevicePolling();
  await new Promise(setImmediate);
  assert.equal(phoneNode('walkinQrAdminDeviceName').textContent, 'Replacement phone');
  const beforePoll = refreshes;
  pollingCallback();
  await new Promise(setImmediate);
  assert.equal(refreshes, beforePoll + 1, 'Walk-in QR selection keeps phone details current.');
  qrSelected = false;
  pollingCallback();
  assert.equal(phoneScope.adminPhoneRegistrationState.paymentPollTimer, null, 'Cash stops phone polling.');
  phoneScope.fetchRegisteredAdminPhone = async () => { throw new Error('Test connection error'); };
  await phoneScope.refreshRegisteredAdminPhone();
  assert.equal(phoneNode('walkinQrAdminDeviceName').textContent, 'Unable to check registered phone');
  assert.equal(phoneNode('walkinQrAdminDeviceMeta').textContent, 'Test connection error');
  assert.equal(phoneNode('walkinManageAdminPhoneBtn').disabled, false);
  console.log('PASS: walk-in registered phone card, Change/Register states, replacement phone detection, cash polling stop, and error display.');
})().catch(error => { console.error(error); process.exitCode = 1; });
