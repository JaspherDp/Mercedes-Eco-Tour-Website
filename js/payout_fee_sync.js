(() => {
  'use strict';
  const csrf = window.payoutFeeSync?.csrf;
  if (!csrf) return;
  async function sync() {
    let updated = 0;
    try {
      for (let batch = 0; batch < 100; batch++) {
        const response = await fetch(location.pathname, {
          method: 'POST', credentials: 'same-origin', cache: 'no-store',
          headers: {'Content-Type': 'application/x-www-form-urlencoded', Accept: 'application/json'},
          body: new URLSearchParams({accounting_action: 'sync_fees', csrf_token: csrf})
        });
        if (!response.ok) break;
        const result = await response.json();
        updated += Number(result.updated || 0);
        if (!result.more) break;
      }
    } catch (_) { /* Unavailable amounts stay pending; server-side retries are throttled. */ }
    if (!updated) return;
    // Refresh calculated cards and ledger after enrichment, without interrupting a form.
    const refreshWhenIdle = () => {
      if (document.querySelector('[role="dialog"][aria-modal="true"]')?.closest('.open, .show') ||
          document.querySelector('.payout-modal[aria-hidden="false"],.payout-drawer[aria-hidden="false"],.hp-modal[aria-hidden="false"],.hp-drawer[aria-hidden="false"],.he-drawer[aria-hidden="false"]') ||
          /^(INPUT|SELECT|TEXTAREA)$/.test(document.activeElement?.tagName || '')) {
        setTimeout(refreshWhenIdle, 3000); return;
      }
      location.reload();
    };
    refreshWhenIdle();
  }
  sync();
})();
