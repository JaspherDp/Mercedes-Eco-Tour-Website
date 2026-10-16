const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const { webcrypto } = require('node:crypto');
const source = fs.readFileSync(path.join(__dirname, '../js/admin-walkin-payment.js'), 'utf8');
const adminSource = fs.readFileSync(path.join(__dirname, '../admin/adbookings.php'), 'utf8');
const sharedRequest = adminSource.slice(adminSource.indexOf('async function requestAdminPaymentQr('), adminSource.indexOf('async function refreshRegisteredAdminPhone('));
const tick = () => new Promise(setImmediate);

function fixture({ method = 'qr_code', confirmed = false, failures = 0, deliveryFailures = 0, lostResponse = false, resend = false, unpaidFinalization = false, staysPending = false } = {}) {
  const elements = { payment_method: { value: method }, payment_collected: { checked: confirmed }, csrf_token: { value: 'original-csrf' } };
  const form = { elements };
  class Fields {
    constructor(from) { this.values = new Map(); if (from) { this.set('csrf_token', from.elements.csrf_token.value); this.set('payment_method', from.elements.payment_method.value); } }
    set(key, value) { this.values.set(key, value); }
    append(key, value) { this.set(key, value); }
    get(key) { return this.values.get(key); }
  }
  const calls = [];
  const alerts = [];
  const state = { booking: null, creates: 0, createAttempts: 0, checkoutAttempts: 0, closed: false, requestIds: [], successOK: null, accepted: false };
  const text = { textContent: 'Pay Booking' };
  const button = { disabled: false, querySelector: () => text };
  let remainingFailures = failures;
  let remainingDeliveryFailures = deliveryFailures;
  const token = 'a'.repeat(64);
  const response = payload => ({ ok: true, payload });
  const context = {
    window: {}, crypto: webcrypto, FormData: Fields, URL,
    adminPayMongoCsrf: 'paymongo-csrf', adminPayMongoCheckoutEndpoint: '/checkout', readAdminPaymentJson: async response => response.payload,
    setTimeout: callback => { queueMicrotask(callback); },
    Swal: {
      DismissReason: { cancel: 'cancel' }, showLoading() {},
      fire: options => {
        alerts.push(options);
        if (options.showDenyButton && resend && state.checkoutAttempts === 1) queueMicrotask(options.preDeny);
        if (options.icon === 'success') return new Promise(resolve => { state.successOK = resolve; });
        return Promise.resolve({});
      }
    },
    fetch: async (url, options = {}) => {
      calls.push({ url, body: options.body });
      const action = options.body?.get('action');
      if (action === 'create_walkin_booking') {
        state.createAttempts++;
        state.requestIds.push(options.body.get('walkin_request_id'));
        if (!state.booking) {
          state.creates++;
          state.booking = { booking_id: 42, booking_reference: 'TB26-0042', grand_total: 2880, payment_amount: 0, remaining_balance: 2880, is_paid: method === 'cash' && confirmed ? 1 : 0, status: 'pending' };
          if (state.booking.is_paid) { state.booking.remaining_balance = 0; state.booking.payment_amount = 2880; state.booking.status = 'accepted'; }
        }
        if (lostResponse && state.createAttempts === 1) throw new Error('Lost response');
        return response({ success: true, booking: { ...state.booking }, csrf_token: 'payment-csrf' });
      }
      if (url === '/checkout') {
        state.checkoutAttempts++;
        assert.equal(options.body.get('walkin_full_payment'), '1');
        assert.equal(options.body.get('id'), '42');
        assert.equal(state.booking.status, 'pending', 'Unpaid QR booking cannot be accepted.');
        return response({ success: true, checkout_url: 'https://checkout.paymongo.com/test', return_token: token, phone_notification: { sent: remainingDeliveryFailures-- <= 0 } });
      }
      if (url.includes('paymongoPaymentStatus')) {
        if (staysPending) return response({ success: true, status: 'pending' });
        if (resend && state.checkoutAttempts === 1) return response({ success: true, status: 'pending' });
        if (remainingFailures-- > 0) return response({ success: true, status: 'failed' });
        state.booking.is_paid = 1;
        state.booking.remaining_balance = 0;
        state.booking.payment_amount = 2880;
        return response({ success: true, status: 'paid' });
      }
      if (action === 'finish_walkin_booking' || action === 'pay_walkin_cash') {
        assert.equal(options.body.get('csrf_token'), 'payment-csrf');
        if (action === 'pay_walkin_cash') { state.booking.is_paid = 1; state.booking.remaining_balance = 0; state.booking.payment_amount = 2880; }
        assert.equal(state.booking.is_paid, 1, 'Finalization requires verified full payment.');
        state.booking.status = 'accepted';
        state.accepted = true;
        return response({ success: true, booking: { ...state.booking, ...(unpaidFinalization ? { is_paid: 0, payment_amount: 0, remaining_balance: 2880 } : {}) } });
      }
      throw new Error(`Unexpected request ${url} ${action}`);
    }
  };
  vm.createContext(context);
  vm.runInContext(sharedRequest, context);
  vm.runInContext(source, context);
  const controller = context.window.AdminWalkinPayment.create({
    form, button, readJson: async response => response.payload,
    requirePhone: async () => true, fetchPhone: async () => ({ registered: true, device: { device_name: 'Front desk phone' } }),
    requestQr: (id, amount) => context.requestAdminPaymentQr(id, amount, true), cancelQr: async () => true,
    onCreated: () => { state.locked = true; }, onSuccess: () => { assert(!controller.isBusy()); state.closed = true; }
  });
  return { controller, state, alerts, calls, text, elements };
}

(async () => {
  const pendingQr = fixture({ staysPending: true });
  await pendingQr.controller.pay();
  assert.equal(pendingQr.state.accepted, false, 'Sending the notification must not accept an unpaid reservation.');
  assert.equal(pendingQr.state.closed, false, 'A payment wait timeout must not report successful Add Booking.');
  assert(!pendingQr.alerts.some(alert => alert.icon === 'success'));
  assert.equal(pendingQr.text.textContent, 'Retry Payment');
  assert.equal(pendingQr.state.creates, 1);

  const unverified = fixture({ unpaidFinalization: true });
  await unverified.controller.pay();
  assert.equal(unverified.state.closed, false, 'An unpaid finalization response must not close Add Booking as successful.');
  assert(!unverified.alerts.some(alert => alert.icon === 'success'), 'Unpaid booking must never display success.');
  assert.equal(unverified.alerts.at(-1).title, 'Payment Not Completed');

  const qr = fixture();
  const paying = qr.controller.pay();
  await tick();
  assert(qr.state.successOK);
  assert.equal(qr.state.accepted, true);
  assert.equal(qr.state.closed, false, 'The booking modal must stay open behind success until OK.');
  assert(qr.alerts.some(alert => alert.showConfirmButton === false && /Front desk phone/.test(alert.text)));
  assert.match(qr.alerts.at(-1).text, /TB26-0042 is confirmed and accepted/);
  qr.state.successOK({ isConfirmed: true });
  await paying;
  assert.equal(qr.state.closed, true);
  assert.equal(qr.state.creates, 1);

  const resending = fixture({ resend: true });
  const sentAgain = resending.controller.pay();
  await tick();
  assert.equal(resending.state.checkoutAttempts, 2);
  assert.equal(resending.state.creates, 1);
  assert(resending.alerts.some(alert => alert.title === 'Resending QR Notification'));
  resending.state.successOK({ isConfirmed: true });
  await sentAgain;

  const retry = fixture({ failures: 2 });
  await retry.controller.pay();
  assert.equal(retry.state.closed, false);
  assert.equal(retry.state.accepted, false);
  assert.equal(retry.text.textContent, 'Retry Payment');
  const retrying = retry.controller.pay();
  await tick();
  retry.state.successOK({ isConfirmed: true });
  await retrying;
  assert.equal(retry.state.creates, 1, 'Payment retry must reuse the pending reservation.');
  assert.equal(retry.state.checkoutAttempts, 2, 'A failed QR payment can be sent again for the same reservation.');

  const delivery = fixture({ deliveryFailures: 1 });
  await delivery.controller.pay();
  assert.equal(delivery.state.closed, false);
  assert.equal(delivery.text.textContent, 'Retry Payment');
  const resend = delivery.controller.pay();
  await tick();
  delivery.state.successOK({ isConfirmed: true });
  await resend;
  assert.equal(delivery.state.creates, 1);

  const lost = fixture({ lostResponse: true });
  await lost.controller.pay();
  const checking = lost.controller.pay();
  await tick();
  lost.state.successOK({ isConfirmed: true });
  await checking;
  assert.equal(lost.state.creates, 1);
  assert.equal(lost.state.requestIds[0], lost.state.requestIds[1], 'Lost create responses must retry with the same idempotency key.');

  const unpaid = fixture({ method: 'cash' });
  await unpaid.controller.pay();
  assert.equal(unpaid.state.creates, 0);
  assert.equal(unpaid.state.closed, false);
  assert.match(unpaid.alerts.at(-1).text, /Collect the full cash amount/);
  const cash = fixture({ method: 'cash', confirmed: true });
  const recording = cash.controller.pay();
  await tick();
  assert.equal(cash.state.closed, false);
  cash.state.successOK({ isConfirmed: true });
  await recording;
  assert.equal(cash.state.accepted, true);
  assert.equal(cash.state.checkoutAttempts, 0);
  assert.equal(cash.state.closed, true);
  assert(!source.includes('openPaymentModal'), 'Walk-in payments must not use the balance payment modal.');
  assert(adminSource.includes('const data = await requestAdminPaymentQr(bookingId, amount);'));
  console.log('PASS: shared Pay Balance sender, named-phone spinner, Resend QR, no duplicate booking, failure/retry, paid-only acceptance, and close only after success OK.');
})().catch(error => { console.error(error); process.exitCode = 1; });
