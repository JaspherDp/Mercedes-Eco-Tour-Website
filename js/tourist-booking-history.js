(() => {
  'use strict';
  const escape = value => String(value ?? '').replace(/[&<>"']/g, char => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[char]));
  const date = (value, includeTime = false) => {
    if (!value) return 'Not recorded';
    const parsed = new Date(String(value).replace(' ', 'T'));
    if (Number.isNaN(parsed.getTime())) return 'Not recorded';
    return parsed.toLocaleString('en-PH', {month: 'short', day: 'numeric', year: 'numeric', ...(includeTime ? {hour: 'numeric', minute: '2-digit'} : {})});
  };
  const overlay = document.createElement('div');
  overlay.id = 'touristBookingHistory';
  overlay.className = 'tourist-profile-overlay';
  overlay.setAttribute('aria-hidden', 'true');
  overlay.innerHTML = `<aside class="tourist-profile-drawer" role="dialog" aria-modal="true" aria-labelledby="touristHistoryTitle">
    <header class="tourist-profile-header"><div class="tourist-profile-brand"><img src="img/newlogo.png" alt=""><div><span>ITOUR MERCEDES</span><h3 id="touristHistoryTitle">Booking History</h3></div></div>
    <button type="button" class="tourist-profile-close" aria-label="Close booking history"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m6 6 12 12M18 6 6 18"/></svg></button></header>
    <div class="tourist-profile-body"><div class="tourist-history-summary" aria-live="polite"></div><p class="tourist-history-note">Tour reservations, newest first. Includes pending and cancelled bookings.</p><div class="tourist-history-list"></div><p class="tourist-history-message" role="status"></p><button type="button" class="tourist-history-more" hidden>Load older bookings</button></div>
    <footer class="tourist-profile-footer"><button type="button" class="tourist-profile-close-btn">Close History</button></footer></aside>`;
  document.body.appendChild(overlay);
  const summary = overlay.querySelector('.tourist-history-summary');
  const list = overlay.querySelector('.tourist-history-list');
  const message = overlay.querySelector('.tourist-history-message');
  const more = overlay.querySelector('.tourist-history-more');
  let trigger, touristId, offset = 0, request;
  const close = () => {
    request?.abort();
    overlay.classList.remove('show');
    overlay.setAttribute('aria-hidden', 'true');
    document.body.classList.remove('tourist-history-open');
    trigger?.focus();
  };
  const load = async () => {
    request?.abort();
    const controller = new AbortController();
    request = controller;
    more.disabled = true;
    message.textContent = 'Loading booking history…';
    try {
      const response = await fetch(`php/admin_tourist_booking_history.php?tourist_id=${encodeURIComponent(touristId)}&offset=${offset}`, {signal: controller.signal, cache: 'no-store', credentials: 'same-origin'});
      const data = await response.json();
      if (!response.ok) throw new Error(data.error || 'Unable to load booking history.');
      if (controller.signal.aborted) return;
      summary.innerHTML = `<strong>${escape(data.name)}</strong><span>${Number(data.total)}X · ${Number(data.total)} tour booking${Number(data.total) === 1 ? '' : 's'}</span>`;
      list.insertAdjacentHTML('beforeend', data.bookings.map(booking => {
        const type = String(booking.booking_type || 'tour');
        const service = type === 'boat' ? booking.boat_name : ['tourguide', 'guide'].includes(type) ? booking.guide_name : booking.package_name;
        const status = ['completed', 'cancelled', 'declined'].includes(String(booking.is_complete).toLowerCase()) ? booking.is_complete : booking.status || 'pending';
        return `<article class="tourist-history-entry"><header><span>${escape(booking.booking_reference || `Booking #${booking.booking_id}`)}</span><span class="tourist-history-status" data-status="${escape(String(status).toLowerCase())}">${escape(status)}</span></header><h4>${escape(service || type)}</h4><p>${escape(type === 'tourguide' ? 'Tour guide' : type)} · ${escape(booking.location || 'Location not recorded')}</p><dl><dt>Trip date</dt><dd>${escape(date(booking.booking_date))}</dd><dt>Booked on</dt><dd>${escape(date(booking.created_at, true))}</dd></dl></article>`;
      }).join(''));
      offset = Number(data.next_offset);
      more.hidden = !data.has_more;
      more.textContent = 'Load older bookings';
      message.textContent = data.total ? '' : 'No tour bookings found for this tourist.';
    } catch (error) {
      if (controller.signal.aborted || error.name === 'AbortError') return;
      message.textContent = error.message || 'Unable to load booking history. Please try again.';
      more.hidden = false;
      more.textContent = 'Try again';
    } finally {
      if (request === controller) more.disabled = false;
    }
  };
  document.addEventListener('click', event => {
    const button = event.target.closest?.('[data-tourist-history]');
    if (!button) return;
    event.preventDefault();
    trigger = button;
    touristId = button.dataset.touristHistory;
    offset = 0;
    summary.textContent = 'Tourist booking history';
    list.replaceChildren();
    more.hidden = true;
    overlay.classList.add('show');
    overlay.setAttribute('aria-hidden', 'false');
    document.body.classList.add('tourist-history-open');
    overlay.querySelector('.tourist-profile-close').focus();
    load();
  });
  overlay.querySelectorAll('.tourist-profile-close, .tourist-profile-close-btn').forEach(button => button.addEventListener('click', close));
  more.addEventListener('click', load);
  overlay.addEventListener('click', event => { if (event.target === overlay) close(); });
  document.addEventListener('keydown', event => {
    if (!overlay.classList.contains('show')) return;
    if (event.key === 'Escape') { event.preventDefault(); close(); }
    if (event.key !== 'Tab') return;
    const buttons = [...overlay.querySelectorAll('button')].filter(button => !button.hidden && !button.disabled);
    const first = buttons[0], last = buttons[buttons.length - 1];
    if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
    else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
  });
})();
