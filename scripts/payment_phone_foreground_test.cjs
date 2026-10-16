const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const source = fs.readFileSync(path.join(__dirname, '../js/admin-push-notifications.js'), 'utf8').replaceAll('import(', '__import(');
const tick = () => new Promise(setImmediate);
(async () => {
  let listener;
  let bindings = 0;
  let unsubscribes = 0;
  let registrations = 0;
  let saves = 0;
  let click;
  let displayFails = false;
  const shown = [];
  const span = { textContent: 'Register' };
  const button = { dataset: { configEndpoint: '/config', saveEndpoint: '/save', csrfToken: 'synthetic-csrf' }, classList: { add() {}, remove() {} }, closest: () => null, querySelector: () => span, addEventListener: (_, handler) => { click = handler; } };
  const status = { dataset: {} };
  const notification = { permission: 'granted', requestPermission() { throw Error('A registered phone must not be asked for permission again on reload.'); } };
  const registration = { active: true, showNotification: async (title, options) => { if (displayFails) throw Error('Blocked'); shown.push({ title, options }); } };
  const firebaseApp = {};
  const appModule = { initializeApp: () => firebaseApp };
  const messagingModule = { getMessaging: () => ({}), getToken: async () => 'synthetic-token', isSupported: async () => true,
    onMessage: (_, callback) => { bindings++; listener = callback; return () => { unsubscribes++; }; } };
  const config = { configured: true, app_url: 'https://example.test/', firebase: {}, vapid_public_key: 'synthetic-key' };
  const scope = {
    URL, Notification: notification,
    window: { isSecureContext: true, Notification: notification, location: { origin: 'https://example.test' }, setTimeout },
    navigator: { platform: 'Test phone', userAgent: 'Test browser', serviceWorker: { getRegistration: async () => registration, register: async () => { registrations++; return registration; } } },
    document: { getElementById: id => id === 'enableAdminPaymentNotifications' ? button : (id === 'adminPaymentNotificationStatus' ? status : null), querySelector: () => null },
    __import: async url => url.endsWith('firebase-app.js') ? appModule : messagingModule,
    fetch: async url => { if (url === '/save') saves++; return { ok: true, headers: { get: () => 'application/json' }, json: async () => url === '/save' ? { success: true } : config }; }
  };
  vm.createContext(scope);
  vm.runInContext(source, scope);
  await tick();
  assert.equal(bindings, 1, 'Reopening the phone page must reconnect the foreground listener automatically.');
  assert.equal(registrations, 0);
  assert.equal(saves, 0, 'Automatic reconnect must not replace the registered device.');
  listener({ data: { title: 'Payment QR Ready', body: 'Test booking', tag: 'fresh-send-1', url: 'https://example.test/admin-payment-handoff.php?token=synthetic' } });
  await tick();
  assert.equal(shown.length, 1);
  assert.equal(shown[0].options.icon, undefined, 'Urgent foreground alerts must not wait for website icons.');
  assert.equal(shown[0].options.badge, undefined, 'Urgent foreground alerts must not wait for website badges.');
  assert.match(shown[0].options.data.url, /admin-payment-handoff/);
  assert.equal(shown[0].options.tag, 'fresh-send-1');
  listener({ data: { url: 'https://untrusted.test/' }, notification: { title: 'Native title', body: 'Native message' } });
  await tick();
  assert.equal(shown[1].title, 'Native title');
  assert.equal(shown[1].options.data.url, 'https://example.test/');
  await click();
  assert.equal(bindings, 2);
  assert.equal(unsubscribes, 1, 'Registering again must not create duplicate notification listeners.');
  assert.equal(saves, 1);
  displayFails = true;
  listener({ data: { title: 'Test failure' } });
  await tick();
  assert.equal(status.dataset.status, 'error');
  assert.match(status.textContent, /browser could not display/);
  console.log('PASS: phone-page reload reconnects notifications without registration, foreground QR display, safe handoff, no duplicate listener, and visible display failures.');
})().catch(error => { console.error(error); process.exitCode = 1; });
