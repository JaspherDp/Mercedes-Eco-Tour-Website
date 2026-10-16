const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const source = fs.readFileSync(path.join(__dirname, '../operator/opbookings.php'), 'utf8');
const between = (start, end) => {
  const first = source.indexOf(start);
  assert(first >= 0, `Missing source marker: ${start}`);
  const last = source.indexOf(end, first);
  assert(last > first, `Missing end marker: ${end}`);
  return source.slice(first, last);
};

// Exercise the actual page handlers without sending payments or touching bookings.
const nodes = new Map();
const listeners = {};
const node = id => {
  if (!nodes.has(id)) nodes.set(id, {
    value: '', textContent: '', classList: { add() {}, remove() {} },
    setAttribute() {}, removeAttribute() {},
    addEventListener(event, callback) { listeners[`${id}:${event}`] = callback; }
  });
  return nodes.get(id);
};
const alerts = [];
const requests = [];
const updates = [];
const storage = new Map();
let cashResult = { is_paid: true, completed: false };
let qrDelivered = true;
const token = 'a'.repeat(64);
const pendingKey = 'itour_operator_paymongo_pending';
const scope = {
  document: {
    getElementById: node,
    querySelector: () => node('amount'),
    querySelectorAll: () => []
  },
  paymentMethod: node('method'), paymentMethodNote: node('methodNote'),
  paymentSubmit: node('submit'), paymentModal: node('modal'),
  billingMoney: value => `PHP ${value.toFixed(2)}`,
  openModal() {}, closeModal() {},
  startOperatorPaymentPhonePolling() {}, stopOperatorPaymentPhonePolling() {},
  requireOperatorPaymentPhone: async () => true,
  opPayMongoCsrf: 'test-csrf', opPayMongoEndpoint: '/checkout',
  readPaymentJson: async response => response.json(),
  submitSharedUpdate: async (form, action) => {
    assert(!form.elements.complete_after_payment, 'Cash must not request automatic completion');
    updates.push(action);
    return cashResult;
  },
  fetch: async (url, options) => {
    requests.push({ url, options });
    if (url === '/checkout') return {
      ok: true, json: async () => ({ success: true, checkout_url: 'https://checkout.paymongo.com/test',
        return_token: token, phone_notification: { sent: qrDelivered } })
    };
    return { ok: true, json: async () => ({ success: true, status: 'paid', amount: 100,
      booking_id: 25, booking_reference: 'TP26-TEST' }) };
  },
  Swal: { fire: async (...args) => { alerts.push(args[0]); return {}; }, showLoading() {}, DismissReason: { cancel: 'cancel' } },
  sessionStorage: {
    setItem: (key, value) => storage.set(key, value),
    getItem: key => storage.get(key) || null,
    removeItem: key => storage.delete(key)
  },
  window: { location: { href: 'https://example.test/opbookings.php', search: '', reload() {}, assign() {} },
    requestAnimationFrame: callback => callback(),
    addEventListener: (event, callback) => { listeners[event] = callback; } },
  history: { replaceState() {} },
  performance: { getEntriesByType: () => [{ type: 'back_forward' }] },
  URL, URLSearchParams, FormData, setTimeout,
};
vm.createContext(scope);
vm.runInContext([
  between('const paymentFlow =', 'function operatorPhoneSetupUrl()'),
  between('async function monitorOperatorPhoneQrPayment(', 'const expenseLabels ='),
  between("document.getElementById('opPaymentForm')?.addEventListener('submit'", 'const handleOperatorPayMongoReturn ='),
  between('const handleOperatorPayMongoReturn =', 'handleOperatorPayMongoReturn();'),
  between("window.addEventListener('pageshow'", "document.getElementById('opExpenseForm')"),
  'globalThis.prepare = preparePaymentModal; globalThis.methodChanged = updatePaymentMethod;',
  'globalThis.handleReturn = handleOperatorPayMongoReturn;'
].join('\n'), scope);

const form = { elements: { booking_id: node('opPaymentBookingId'), payment_method: node('method'), amount: node('amount') } };
const pay = () => listeners['opPaymentForm:submit']({ preventDefault() {}, currentTarget: form });
const success = () => alerts.findLast(alert => alert?.icon === 'success');
const assertCompletionSuccess = () => {
  assert.equal(success().title, 'Payment Successful');
  assert.match(success().text, /You can now mark the booking as completed/);
};

(async () => {
  assert(!source.includes('>Record Payment</button>'));
  assert(!source.includes('name="complete_after_payment"'));

  scope.prepare(25, 100, true);
  assert.equal(node('opPaymentTitle').textContent, 'Pay the Balance Before Completing This Booking');
  assert.equal(node('amount').readOnly, true);
  node('method').value = 'cash';
  scope.methodChanged();
  assert.equal(node('submit').textContent, 'Pay Balance');
  node('amount').value = '50';
  await pay();
  assert.equal(alerts.at(-1), 'Full Payment Required');
  assert.equal(updates.length, 0);
  node('amount').value = '100';
  await pay();
  assertCompletionSuccess();
  assert.deepEqual(updates, ['confirm_payment']);

  scope.prepare(25, 100);
  assert.equal(node('opPaymentTitle').textContent, 'Pay Balance');
  assert.equal(node('opPaymentContextNote').textContent, 'Record cash or create a secure PayMongo QR.');
  assert.equal(node('amount').readOnly, false);
  node('method').value = 'cash';
  node('amount').value = '50';
  cashResult = { is_paid: false, completed: false };
  await pay();
  assert.equal(success().title, 'Payment Recorded');
  assert.match(success().text, /cash payment was recorded/);

  for (const completion of [true, false]) {
    scope.prepare(25, 100, completion);
    node('method').value = 'qr_code';
    await pay();
    const checkout = requests.findLast(request => request.url === '/checkout');
    assert.equal(checkout.options.body.has('complete_after_payment'), false);
    if (completion) assertCompletionSuccess();
    else assert.equal(success().title, 'QR Payment Verified');
  }

  // Fallback checkout retains context for the redirect and browser Back flows.
  qrDelivered = false;
  scope.prepare(25, 100, true);
  node('method').value = 'qr_code';
  await pay();
  assert.equal(JSON.parse(storage.get(pendingKey)).fromMarkCompleted, true);
  await listeners.pageshow({ persisted: true });
  assertCompletionSuccess();
  assert.equal(storage.has(pendingKey), false);

  scope.window.location.search = `?payment_return=paymongo&payment_return_token=${token}`;
  for (const pending of [
    { token, fromMarkCompleted: true },
    { token, fromMarkCompleted: false },
    { token: 'b'.repeat(64), fromMarkCompleted: true }
  ]) {
    storage.set(pendingKey, JSON.stringify(pending));
    await scope.handleReturn();
    if (pending.token === token && pending.fromMarkCompleted) assertCompletionSuccess();
    else assert.equal(success().title, 'QR Payment Verified');
  }
  console.log('Operator payment flow passed: cash, partial billing payments, phone QR, redirect, history return, and context reset.');
})().catch(error => { console.error(error); process.exitCode = 1; });
