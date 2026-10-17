'use strict';
const fs = require('node:fs');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const source = fs.readFileSync('js/booking-success-receipt.js', 'utf8');
async function exercise(response) {
  const calls = { opened: [], requests: [] };
  const button = { disabled: false, attrs: {}, setAttribute(k,v) { this.attrs[k]=v; }, removeAttribute(k) { delete this.attrs[k]; }, addEventListener(_, fn) { this.click=fn; } };
  const error = { hidden: true, textContent: '' };
  const ctx = { URL, document: { getElementById: id => id === 'successReceipt' ? button : error },
    window: { location: { href: 'https://example.test/booking_success.php' }, BookingPaymentSummary: { open: data => calls.opened.push(data) } },
    fetch: async (url, options) => { calls.requests.push({ url: String(url), options }); return response; } };
  vm.runInNewContext(source, ctx);
  await button.click();
  assert.equal(button.disabled, false);
  assert.equal(button.attrs['aria-busy'], undefined);
  return { calls, error };
}
(async () => {
  const receipt = { booking: { booking_reference: 'TEST' }, amountPaid: 329.8, balance: 1319.2 };
  const ok = await exercise({ ok: true, headers: { get: () => 'application/json' }, json: async () => ({ success: true, receipt }) });
  assert.equal(ok.calls.opened[0], receipt);
  assert.equal(ok.calls.requests[0].url, 'https://example.test/booking_success.php?format=receipt');
  assert.equal(ok.calls.requests[0].options.cache, 'no-store');
  assert.equal(ok.error.hidden, true);
  const failure = await exercise({ ok: false, headers: { get: () => 'application/json' }, json: async () => ({ success: false, message: 'Try again' }) });
  assert.equal(failure.calls.opened.length, 0);
  assert.equal(failure.error.hidden, false);
  assert.equal(failure.error.textContent, 'Try again');
  const expired = await exercise({ headers: { get: () => 'text/html' } });
  assert.match(expired.error.textContent, /session has expired/);
  assert.equal(expired.calls.opened.length, 0);
  console.log('PASS: receipt button loads current data, opens the existing modal, restores loading state, and handles failures and expired sessions.');
})().catch(error => { console.error(error); process.exitCode=1; });
