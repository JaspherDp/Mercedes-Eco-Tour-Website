(() => {
  'use strict';
  if (window.ItourNotificationInbox) { window.ItourNotificationInbox.mount(); return; }
  let state = null;
  let timer = null;
  let request = null;
  let lastFetch = 0;
  const bell = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9M10 21h4"/></svg>';

  function configFromPage() {
    const source = document.querySelector('[data-inbox-config]');
    try { return source ? JSON.parse(source.dataset.inboxConfig) : window.ItourNotificationConfig; }
    catch (_) { return null; }
  }
  function status(message) {
    if (state) state.status.textContent = message;
  }
  function setOpen(open) {
    state.panel.hidden = !open;
    state.button.setAttribute('aria-expanded', String(open));
    if (open) refresh();
  }
  function timestamp(value) {
    const date = new Date(String(value).replace(' ', 'T') + '+08:00');
    if (!Number.isFinite(date.getTime())) return '';
    const minutes = Math.max(0, Math.floor((Date.now() - date.getTime()) / 60000));
    if (minutes < 1) return 'Just now';
    if (minutes < 60) return `${minutes} minute${minutes === 1 ? '' : 's'} ago`;
    return date.toLocaleString(undefined, { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' });
  }
  function render(payload) {
    state.items = Array.isArray(payload.data) ? payload.data : [];
    const unread = Math.max(0, Number(payload.unread) || 0);
    state.badge.hidden = unread === 0;
    state.badge.textContent = unread > 99 ? '99+' : String(unread);
    state.button.setAttribute('aria-label', unread ? `Notifications, ${unread} unread` : 'Notifications');
    state.mark.disabled = unread === 0;
    state.list.replaceChildren();
    state.items.forEach(item => {
      const link = document.createElement('a');
      link.className = 'itour-inbox-item' + (item.read_at ? '' : ' is-unread');
      link.href = `${state.endpoint}&open=${encodeURIComponent(item.notification_id)}`;
      const title = document.createElement('strong');
      title.textContent = item.title;
      const message = document.createElement('span');
      message.textContent = item.message;
      const time = document.createElement('time');
      time.textContent = timestamp(item.created_at);
      time.dateTime = String(item.created_at).replace(' ', 'T') + '+08:00';
      link.append(title, message, time);
      link.addEventListener('click', async event => {
        if (event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) { markRead({ notification_id: item.notification_id }).catch(() => {}); return; }
        event.preventDefault();
        // A failed read write does not prevent opening the authorized booking.
        try { await markRead({ notification_id: item.notification_id }); } catch (_) {}
        window.location.assign(link.href);
      });
      state.list.appendChild(link);
    });
    status(state.items.length ? '' : 'You are all caught up. New booking activity will appear here.');
  }
  async function refresh(force = false) {
    if (!state || document.hidden || state.stopped) return;
    if (request) return request;
    if (!force && Date.now() - lastFetch < 10000) return;
    lastFetch = Date.now();
    request = (async () => {
      try {
        const response = await fetch(state.endpoint, { credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'application/json' } });
        const payload = await response.json();
        if (response.status === 401) state.stopped = true;
        if (!response.ok || !payload.success) throw new Error(payload.message || 'Notifications could not be loaded.');
        render(payload);
      } catch (error) { status(error.message || 'Notifications could not be loaded. Please try again.'); }
      finally { request = null; }
    })();
    return request;
  }
  async function markRead(selection) {
    const response = await fetch(state.endpoint, { method: 'POST', credentials: 'same-origin', cache: 'no-store', keepalive: true,
      headers: { 'Content-Type': 'application/x-www-form-urlencoded', Accept: 'application/json' },
      body: new URLSearchParams({ csrf_token: state.config.csrf, ...selection }) });
    const payload = await response.json();
    if (!response.ok || !payload.success) throw new Error(payload.message || 'Read status could not be saved.');
  }
  function mount() {
    if (state?.wrap.isConnected) return;
    const config = configFromPage();
    if (!config || !['tourist', 'admin', 'operator', 'hotel_admin'].includes(config.role)) return;
    const selector = { tourist: '.head-nav-main-header .head-nav-notif-wrap', admin: '.ap-header-notif-wrap', operator: '.op-notif-wrap', hotel_admin: '.ho-notif-wrap' }[config.role];
    const legacy = document.querySelector(selector);
    if (!legacy) return;
    if (!document.querySelector('link[data-inbox-style]')) {
      const style = document.createElement('link');
      style.rel = 'stylesheet'; style.href = `${config.base}styles/notification-inbox.css?v=5`; style.dataset.inboxStyle = '1';
      document.head.appendChild(style);
    }
    // Replace only the bell. Legacy listeners retain detached nodes, preventing
    // duplicate read requests without changing unrelated header controls.
    const wrap = document.createElement('div');
    wrap.className = 'itour-inbox' + (config.role === 'tourist' ? ' itour-inbox-tourist' : '');
    wrap.innerHTML = `<button type="button" class="itour-inbox-bell" aria-label="Notifications" aria-expanded="false" aria-controls="itourInboxPanel">${bell}<span class="itour-inbox-badge" hidden></span></button><section id="itourInboxPanel" class="itour-inbox-panel" aria-label="Notifications" hidden><div class="itour-inbox-head"><div><strong>Notifications</strong><small>Recent booking activity</small></div><button type="button" class="itour-inbox-mark">Mark all read</button></div><div class="itour-inbox-list"></div><p class="itour-inbox-status" role="status">Loading notifications…</p></section>`;
    if (config.role === 'tourist') {
      const button = wrap.querySelector('.itour-inbox-bell');
      button.className += ' head-nav-notif-btn';
      button.querySelector('svg')?.remove();
      const icon = document.createElement('img');
      icon.src = `${config.base}img/notificon.png`;
      icon.alt = '';
      button.prepend(icon);
    }
    legacy.replaceWith(wrap);
    state = { config, wrap, endpoint: `${config.base}php/notification_inbox.php?role=${encodeURIComponent(config.role)}`, button: wrap.querySelector('.itour-inbox-bell'), badge: wrap.querySelector('.itour-inbox-badge'), panel: wrap.querySelector('.itour-inbox-panel'), list: wrap.querySelector('.itour-inbox-list'), status: wrap.querySelector('.itour-inbox-status'), mark: wrap.querySelector('.itour-inbox-mark'), items: [], stopped: false };
    state.button.addEventListener('click', () => setOpen(state.panel.hidden));
    state.mark.addEventListener('click', async () => {
      const through = Math.max(0, ...state.items.map(item => Number(item.notification_id) || 0));
      if (!through) return;
      state.mark.disabled = true;
      try { await markRead({ through }); await refresh(true); }
      catch (error) { status(error.message); state.mark.disabled = false; }
    });
    if (timer) clearInterval(timer);
    timer = setInterval(refresh, 60000);
    refresh(true);
  }
  function openLinkedBooking() {
    const config = configFromPage();
    if (!config) return;
    const params = new URLSearchParams(window.location.search);
    const id = params.get('notification_booking');
    if (!/^[1-9]\d*$/.test(id || '')) return;
    if (config.role === 'tourist') {
      if (params.get('notification_action') === 'decision') {
        const requestId = params.get('cancellation_request');
        const decision = Array.from(document.querySelectorAll('.respond-provider-cancellation[data-request]')).find(candidate => {
          try { return String(JSON.parse(candidate.dataset.request).cancellation_request_id) === requestId; } catch (_) { return false; }
        });
        if (decision) { decision.closest('tr')?.scrollIntoView({block:'center'}); decision.click(); return; }
      }
      const domain = params.get('notification_domain') === 'hotel' ? 'hotel' : 'tour';
      const button = Array.from(document.querySelectorAll('.booking-btn-user[data-booking]')).find(candidate => {
        try {
          const booking = JSON.parse(candidate.dataset.booking);
          return domain === 'hotel' ? String(booking.hotel_booking_id) === id : !booking.hotel_booking_id && String(booking.booking_id) === id;
        } catch (_) { return false; }
      });
      if (button) {
        button.closest('tr')?.scrollIntoView({ block: 'center' });
        if (params.get('notification_action') === 'billing' && typeof window.openProfileBilling === 'function') window.openProfileBilling(domain, Number(id));
        else button.click();
      } else {
        const billing = Array.from(document.querySelectorAll('.billing-btn-user[data-billing-id]')).find(candidate => candidate.dataset.billingType === domain && candidate.dataset.billingId === id);
        if (billing) { billing.closest('tr')?.scrollIntoView({ block: 'center' }); billing.click(); }
        else showUnavailableBooking();
      }
      return;
    }
    if (config.role === 'admin') {
      const button = Array.from(document.querySelectorAll('.btn-details[data-booking-id]')).find(candidate => candidate.dataset.bookingId === id);
      highlightBookingRow(button);
      return;
    }
    const button = Array.from(document.querySelectorAll('[data-view][data-booking]')).find(candidate => {
      try { return String(JSON.parse(candidate.dataset.booking).booking_id) === id; } catch (_) { return false; }
    });
    highlightBookingRow(button);
  }
  function highlightBookingRow(button) {
    const row = button?.closest('tr');
    if (!row) { showUnavailableBooking(); return; }
    row.scrollIntoView({ block: 'center' });
    row.classList.add('itour-notification-row-focus');
    setTimeout(() => row.classList.remove('itour-notification-row-focus'), 1000);
  }
  function showUnavailableBooking() {
    const main = document.querySelector('main, .main-content, .ho-main, .omain-content');
    if (!main || main.querySelector('.itour-notification-fallback')) return;
    const note = document.createElement('p');
    note.className = 'itour-notification-fallback';
    note.setAttribute('role', 'status');
    note.textContent = 'This booking is no longer available in this view. Please check its current status in Bookings or History.';
    main.prepend(note);
  }
  document.addEventListener('click', event => { if (state && !state.wrap.contains(event.target)) setOpen(false); });
  document.addEventListener('keydown', event => { if (state && event.key === 'Escape' && !state.panel.hidden) { setOpen(false); state.button.focus(); } });
  document.addEventListener('visibilitychange', () => { if (!document.hidden) refresh(); });
  window.ItourNotificationInbox = { mount, refresh };
  const initialize = () => {
    mount(); openLinkedBooking();
    const config = configFromPage();
    if (Number(config?.pending) > 0) window.location.replace(`${config.base}php/notification_inbox.php?role=${encodeURIComponent(config.role)}&open=${Number(config.pending)}`);
  };
  // Parser-deferred panel scripts must follow the legacy header callbacks.
  // AJAX/dynamic homepage scripts can arrive after DOMContentLoaded while the
  // document is still interactive (images/loading resources are unfinished).
  const waitForDom = document.readyState === 'loading'
    || (document.readyState === 'interactive' && document.currentScript?.defer);
  if (waitForDom) document.addEventListener('DOMContentLoaded', initialize);
  else initialize();
})();
