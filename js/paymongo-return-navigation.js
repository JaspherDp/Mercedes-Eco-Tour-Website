(() => {
  'use strict';
  const scriptUrl = document.currentScript.src;
  const returnUrl = new URL('../payments/payment-success.php', scriptUrl);
  const stateKey = 'itourPayMongoReturnToken';
  let checking = false;
  window.ItourPayMongoNavigation = {
    remember(token) {
      if (!/^[a-f0-9]{64}$/.test(String(token || ''))) return;
      history.replaceState({ ...history.state, [stateKey]: token }, '', location.href);
    }
  };
  window.addEventListener('pageshow', async event => {
    const navigation = performance.getEntriesByType('navigation')[0];
    const token = history.state?.[stateKey];
    if (checking || !(event.persisted || navigation?.type === 'back_forward')
        || !/^[a-f0-9]{64}$/.test(String(token || ''))
        || new URLSearchParams(location.search).has('payment_return')) return;
    checking = true;
    const statusUrl = new URL(returnUrl);
    statusUrl.searchParams.set('token', token);
    statusUrl.searchParams.set('format', 'json');
    try {
      for (let attempt = 0; attempt < 4; attempt += 1) {
        const response = await fetch(statusUrl, { cache: 'no-store', headers: { Accept: 'application/json' } });
        const result = await response.json();
        if (!response.ok || !result.success) break;
        if (result.status === 'paid') {
          statusUrl.searchParams.delete('format');
          location.replace(statusUrl.href);
          return;
        }
        if (result.status !== 'pending') break;
        if (attempt < 3) await new Promise(resolve => setTimeout(resolve, 1500));
      }
    } catch (_) {
      // An unavailable status check cannot prove either payment or cancellation.
    } finally { checking = false; }
  });
})();
