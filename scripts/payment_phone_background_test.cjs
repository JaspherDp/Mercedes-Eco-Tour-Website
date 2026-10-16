const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const assert = require('node:assert/strict');

(async () => {
  let receive;
  const shown = [];
  const events = {};
  const scope = {
    URL,
    importScripts() {},
    firebase: {
      initializeApp() {},
      messaging: () => ({ onBackgroundMessage(callback) { receive = callback; } })
    },
    self: {
      __ITOUR_APP_URL__: 'https://example.test/app/',
      __ITOUR_FIREBASE_CONFIGURED__: true,
      __ITOUR_FIREBASE_PUBLIC_CONFIG__: {},
      location: { origin: 'https://example.test' },
      addEventListener(name, callback) { events[name] = callback; },
      registration: { showNotification: async (title, options) => { shown.push({ title, options }); } }
    }
  };
  vm.createContext(scope);
  vm.runInContext(fs.readFileSync(path.join(__dirname, '../firebase-messaging-sw.js'), 'utf8'), scope);
  assert.equal(typeof events.notificationclick, 'function', 'Payment-link click handling must remain installed.');
  await receive({ notification: { title: 'Native QR' } });
  assert.equal(shown.length, 0, 'Firebase native notifications must not be displayed twice.');
  await receive({ data: { title: 'Payment QR Ready', body: 'Synthetic booking', url: 'https://example.test/app/admin-payment-handoff.php?token=synthetic', tag: 'synthetic-resend' } });
  assert.equal(shown.length, 1);
  assert.equal(shown[0].options.icon, undefined);
  assert.equal(shown[0].options.badge, undefined);
  assert.equal(shown[0].options.tag, 'synthetic-resend');
  assert.match(shown[0].options.data.url, /admin-payment-handoff/);
  await receive({ data: { url: 'https://untrusted.test/' } });
  assert.equal(shown[1].options.data.url, 'https://example.test/app/');
  console.log('PASS: background QR display avoids artwork fetches, preserves payment links and resend tags, rejects foreign links, and avoids duplicate native notifications.');
})().catch(error => { console.error(error); process.exitCode = 1; });
