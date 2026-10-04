(() => {
    'use strict';
    const config = window.itourSessionMonitor;
    if (!config || window.itourSessionMonitorStarted) return;
    window.itourSessionMonitorStarted = true;
    let stopped = false;
    let busy = false;
    let dirty = false;
    let lastActivitySent = 0;
    const interval = 30000;
    const endpoint = new URL(config.endpoint, location.href);
    endpoint.searchParams.set('role', config.role);
    endpoint.searchParams.set('generation', config.generation);

    async function expire(payload) {
        if (stopped) return;
        stopped = true;
        clearInterval(timer);
        const redirect = () => location.replace(payload.login_url);
        if (payload.code === 'SESSION_REPLACED') { redirect(); return; }
        if (!window.Swal) {
            try {
                await new Promise((resolve, reject) => {
                    const script = document.createElement('script');
                    script.src = 'https://cdn.jsdelivr.net/npm/sweetalert2@11';
                    script.onload = resolve;
                    script.onerror = reject;
                    document.head.appendChild(script);
                });
            } catch (_) { redirect(); return; }
        }
        await Swal.fire({
            icon: 'warning', title: 'Session Expired',
            text: 'Your session has expired. Please log in again.',
            confirmButtonText: 'Log in again', confirmButtonColor: '#176b55',
            allowOutsideClick: false, allowEscapeKey: false,
            timer: 10000, timerProgressBar: true
        });
        redirect();
    }

    window.itourHandleSessionExpired = expire;

    async function check() {
        if (stopped || busy) return;
        busy = true;
        const sendActivity = dirty && document.visibilityState === 'visible';
        dirty = false;
        try {
            const options = { credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'application/json' } };
            if (sendActivity) {
                lastActivitySent = Date.now();
                options.method = 'POST';
                options.body = new URLSearchParams({ csrf_token: config.csrf });
            }
            const response = await fetch(endpoint, options);
            if (response.status >= 500) return;
            const payload = await response.json();
            if (payload.active === false && payload.login_url) await expire(payload);
        } catch (_) {
            // A network failure is not proof of expiry; the server decides.
            if (sendActivity) dirty = true;
        } finally { busy = false; }
    }

    function activity(event) {
        if (!event.isTrusted || document.visibilityState !== 'visible' || stopped) return;
        dirty = true;
        if (Date.now() - lastActivitySent >= interval) void check();
    }
    ['pointerdown', 'keydown', 'scroll', 'input'].forEach(name => document.addEventListener(name, activity, { passive: true, capture: true }));
    const timer = setInterval(check, interval);
    document.addEventListener('visibilitychange', () => { if (document.visibilityState === 'visible') void check(); });
    window.addEventListener('pageshow', () => void check());
    void check();
})();
