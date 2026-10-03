'use strict';
// Execute actual navigation/return scripts in a browser stub. No HTTP or DB.
const fs = require('node:fs');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const token = 'a'.repeat(64);
let checks = 0;
function context(statuses, query = '', source = 'tourist_profile_balance') {
  const calls = { fetch: 0, alerts: [], redirects: [], reloads: 0, events: {} };
  const location = new URL('https://example.test/php/profile.php' + query);
  location.replace = value => calls.redirects.push(value);
  location.reload = () => calls.reloads++;
  const ctx = {
    URL, URLSearchParams, location, console,
    document: { currentScript: { src: 'https://example.test/js/paymongo-return-navigation.js' } },
    history: { state: null, replaceState(state) { this.state = state; } },
    performance: { getEntriesByType: () => [{ type: 'back_forward' }] },
    sessionStorage: { removeItem() {} },
    setTimeout: callback => { callback(); return 1; },
    fetch: async () => ({ ok: true, json: async () => ({ success: true, status: statuses[Math.min(calls.fetch++, statuses.length - 1)], source, booking_reference: 'FIXTURE', amount: 100 }) }),
    Swal: { fire: (...args) => { calls.alerts.push(args); return Promise.resolve({}); }, showLoading() {}, DismissReason: { cancel: 'cancel' } },
    adminPayMongoPendingKey: 'fixture', formatBookingMoney: String, billingMoney: String,
    readAdminPaymentJson: response => response.json(), readPaymentJson: response => response.json(),
    addEventListener: (name, fn) => { calls.events[name] = fn; }
  };
  ctx.window = ctx;
  return { ctx: vm.createContext(ctx), calls };
}
function check(condition, message) { assert.ok(condition, message); checks++; }
function section(file, start, end) {
  const source = fs.readFileSync(file, 'utf8');
  const a = source.indexOf(start), b = source.indexOf(end, a + start.length);
  assert.ok(a >= 0 && b > a, 'Missing return handler: ' + file);
  return source.slice(a, b);
}
(async () => {
  const navigation = fs.readFileSync('js/paymongo-return-navigation.js', 'utf8');
  for (const statuses of [['paid'], ['pending', 'paid'], ['pending'], ['failed'], ['cancelled'], ['expired']]) {
    const { ctx, calls } = context(statuses);
    vm.runInContext(navigation, ctx);
    ctx.ItourPayMongoNavigation.remember(token);
    await calls.events.pageshow({ persisted: true });
    const paid = statuses.includes('paid');
    check(calls.redirects.length === (paid ? 1 : 0), 'History redirected without authoritative paid state.');
    check(calls.fetch <= 4, 'History polling was unbounded.');
    if (paid) {
      check(calls.redirects[0] === 'https://example.test/payments/payment-success.php?token=' + token, 'History used a different success route.');
      await calls.events.pageshow({ persisted: true });
      check(calls.redirects.length === 2, 'Repeated back/forward lost server verification.');
    }
  }
  const handlers = [
    ['php/profile.php', 'async function handleProfilePayMongoReturn()', '\nhandleProfilePayMongoReturn();', 'handleProfilePayMongoReturn()', 'cancelled'],
    ['admin/adbookings.php', 'async function handleAdminPayMongoReturn(', '\nhandleAdminPayMongoReturn();', 'handleAdminPayMongoReturn()', 'cancelled'],
    ['operator/opbookings.php', 'const handleOperatorPayMongoReturn =', '\n      handleOperatorPayMongoReturn();', 'handleOperatorPayMongoReturn()', 'cancelled']
  ];
  for (const [file, start, end, invoke, flag] of handlers) {
    const script = section(file, start, end);
    for (const statuses of [['paid'], ['pending', 'paid'], ['pending'], ['failed'], ['expired'], ['cancelled']]) {
      const { ctx, calls } = context(statuses, `?payment_return=${flag}&payment_return_token=${token}`);
      vm.runInContext(script, ctx);
      await vm.runInContext(invoke, ctx);
      const text = JSON.stringify(calls.alerts);
      if (statuses.includes('paid')) {
        check(!/cancelled|not completed|verification failed/i.test(text), file + ': paid cancel URL showed failure.');
        check(calls.reloads === 1, file + ': paid return did not use existing success confirmation.');
      } else if (statuses[0] === 'pending') {
        check(/pending/i.test(text) && !/cancelled/i.test(text), file + ': pending became cancelled.');
        check(calls.fetch <= 15, file + ': unbounded retries.');
      } else {
        check(calls.reloads === 0 && calls.redirects.length === 0, file + ': terminal failure shown as paid.');
        check(/cancelled|not completed/i.test(text), file + ': lost failure handling.');
      }
    }
  }
  const profile = section('php/profile.php', 'async function handleProfilePayMongoReturn()', '\nhandleProfilePayMongoReturn();');
  for (const result of ['token', 'cancelled']) {
    const {ctx, calls} = context(['paid'], `?payment_return=${result}&payment_return_token=${token}`, 'booking_checkout');
    vm.runInContext(profile, ctx);
    await vm.runInContext('handleProfilePayMongoReturn()', ctx);
    check(calls.redirects[0] === '../payments/payment-success.php?token=' + token, 'Initial booking lost normal success route.');
    check(!/cancelled/i.test(JSON.stringify(calls.alerts)), 'Initial booking showed cancellation.');
  }
  const phone = section('payments/payment-phone-return.php', '    const statusUrl =', '\n    checkPhonePayment();')
    .replace(/<\?=[\s\S]*?\?>/g, '"Verified fixture payment"');
  const hotel = section('Hobookings.php', '      const pollHotelPayMongo =', '\n      const startHotelPayMongoCheckout');
  for (const statuses of [['paid'], ['pending', 'paid'], ['pending'], ['failed'], ['expired'], ['cancelled']]) {
    const { ctx, calls } = context(statuses, `?result=cancelled&token=${token}`);
    const elements = {};
    ctx.document.getElementById = id => elements[id] ||= { textContent: '' };
    vm.runInContext(phone, ctx);
    await vm.runInContext('checkPhonePayment()', ctx);
    if (statuses.includes('paid')) check(elements.paymentResultTitle.textContent === 'Payment Verified', 'Phone missed paid state.');
    else if (statuses[0] === 'pending') check(elements.paymentResultTitle.textContent === 'Verification Pending', 'Phone cancelled a pending payment.');
    else check(calls.reloads === 1, 'Phone did not refresh authoritative failure state.');
    check(calls.fetch <= 4, 'Phone retries unbounded.');

    const test = context(statuses);
    test.ctx.location = { href: 'https://example.test/Hobookings.php', reload: () => test.calls.reloads++ };
    test.ctx.hotelPayMongoPendingKey = 'fixture';
    test.ctx.readHotelPaymentJson = response => response.json();
    vm.runInContext(hotel, test.ctx);
    const paid = await vm.runInContext(`pollHotelPayMongo('${token}', 0, 4)`, test.ctx);
    check(paid === statuses.includes('paid'), 'Hotel return incorrectly resolved payment state.');
    check(test.calls.fetch <= 4, 'Hotel return retries unbounded.');
  }
  // Also syntax-check inline scripts in the changed return/form/staff pages.
  for (const file of ['payments/payment-phone-return.php', 'public/tour_booking.php', 'public/hotel_booking.php', 'php/profile.php', 'admin/adbookings.php', 'operator/opbookings.php', 'Hobookings.php']) {
    const html = fs.readFileSync(file, 'utf8').replace(/<\?php[\s\S]*?\?>|<\?=[\s\S]*?\?>/g, 'null');
    for (const match of html.matchAll(/<script\b([^>]*)>([\s\S]*?)<\/script>/gi)) {
      if (/src=|application\/ld\+json/i.test(match[1]) || !match[2].trim()) continue;
      new vm.Script(match[2], { filename: file });
    }
  }
  console.log(`PayMongo return browser stubs passed: ${checks} checks; inline script syntax passed.`);
})().catch(error => { console.error(error); process.exitCode = 1; });
