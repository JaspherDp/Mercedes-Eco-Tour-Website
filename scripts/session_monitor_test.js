const fs = require('fs');
const vm = require('vm');
const assert = require('assert/strict');
const source = fs.readFileSync(require('path').join(__dirname, '../js/session-monitor.js'), 'utf8');
const flush = async () => { for (let i = 0; i < 12; i++) await Promise.resolve(); };

async function run(role, login) {
    const events = {};
    const requests = [];
    const alerts = [];
    const redirects = [];
    let interval;
    let next = { active: true };
    let networkFailure = false;
    const window = { itourSessionMonitor: { role, endpoint: '/site/php/session_status.php', generation: 'fresh', csrf: 'csrf' },
        addEventListener: (name, callback) => { events[name] = callback; } };
    const context = { window, URL, URLSearchParams, Date, Promise,
        location: { href: 'https://example.invalid/site/dashboard.php', replace: value => redirects.push(value) },
        document: { visibilityState: 'visible', addEventListener: (name, callback) => { events[name] = callback; } },
        setInterval: (callback, milliseconds) => { assert.equal(milliseconds, 30000); interval = callback; return 1; },
        clearInterval: () => {},
        fetch: async (url, options) => {
            requests.push({ url: String(url), options });
            if (networkFailure) throw new Error('Offline');
            return { status: next.active === false ? 401 : 200, json: async () => next };
        },
        Swal: { fire: async options => { alerts.push(options); } }
    };
    window.Swal = context.Swal;
    vm.runInNewContext(source, context);
    await flush();
    await interval(); await flush();
    assert.equal(requests.length, 2);
    assert.ok(requests.every(request => !request.options.method), 'Passive check sent activity');
    assert.ok(requests.every(request => new URL(request.url).searchParams.get('role') === role));
    events.keydown({ isTrusted: false });
    await interval(); await flush();
    assert.ok(!requests.at(-1).options.method, 'Synthetic event extended activity');
    events.keydown({ isTrusted: true }); await flush();
    assert.equal(requests.at(-1).options.method, 'POST', 'Real activity was not sent promptly');
    assert.equal(requests.at(-1).options.body.get('csrf_token'), 'csrf');
    networkFailure = true;
    await interval(); await flush();
    assert.equal(redirects.length, 0, 'Offline connection treated as expiry');
    networkFailure = false;
    next = { active: false, code: 'SESSION_EXPIRED', login_url: login };
    await interval(); await flush();
    await interval(); await window.itourHandleSessionExpired(next); await flush();
    assert.equal(alerts.length, 1, 'Duplicate expiry alert');
    assert.equal(alerts[0].title, 'Session Expired');
    assert.equal(alerts[0].text, 'Your session has expired. Please log in again.');
    assert.deepEqual(redirects, [login]);
    console.log(`PASS: ${role}: passive polling, trusted activity, offline handling, one SweetAlert and correct redirect (mock browser)`);
}

(async () => {
    await run('admin', '/site/php/admin_login.php');
    await run('operator', '/site/php/operator_login.php');
    await run('hotel_admin', '/site/php/hotel_admin_login.php');
})().catch(error => { console.error(error); process.exitCode = 1; });
