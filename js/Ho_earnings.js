(function () {
  'use strict';

  const drawer = document.getElementById('hePayoutDrawer');
  const body = document.getElementById('heDrawerBody');
  const operatorView = document.body.classList.contains('op-earnings-body');
  if (!drawer || !body) return;

  const escapeHtml = (value) => String(value ?? '').replace(/[&<>'"]/g, (char) => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#39;', '"': '&quot;'
  })[char]);

  const field = (label, value) => `<div><span>${escapeHtml(label)}</span><strong>${escapeHtml(value || '—')}</strong></div>`;

  function openDrawer(data) {
    const settled = data.payout_status === 'Paid out';
    const statusClass = settled
      ? 'settled'
      : (data.payout_status === 'Available for settlement' ? 'ready' : (data.payout_status === 'Pending eligibility' ? 'pending' : 'not_payable'));
    body.innerHTML = `
      <section class="he-drawer-status">
        <span>Current payout status</span>
        <strong><span class="he-status ${statusClass}"><i></i>${escapeHtml(data.payout_status)}</span></strong>
        <small>${escapeHtml(data.payout_note)}</small>
      </section>
      <section class="he-detail-section">
        <h4>Booking information</h4>
        <div class="he-detail-grid">
          ${field('Booking reference', data.booking)}
          ${field('Guest', data.guest)}
          ${field('Email', data.email)}
          ${field('Phone', data.phone)}
          ${field(operatorView ? 'Package' : 'Room', data.room)}
          ${field(operatorView ? 'Tour date' : 'Stay dates', data.stay)}
          ${field(operatorView ? 'Tour schedule' : 'Nights', data.nights)}
          ${field('Guests', data.guests)}
        </div>
      </section>
      <section class="he-detail-section">
        <h4>Payment & earnings</h4>
        <div class="he-detail-grid">
          ${field('Booking status', data.booking_status)}
          ${field('Payment status', data.payment_status)}
          ${field('Booking total', data.booking_total)}
          ${field('Amount paid', data.amount_paid)}
          ${field('Guest balance', data.balance)}
          ${field('Payout amount', data.payout_amount)}
        </div>
      </section>
      <section class="he-detail-section">
        <h4>Administrator settlement</h4>
        <div class="he-settlement-ref">
          <span>${settled ? 'Settled ' + escapeHtml(data.settled_at) : 'No settlement has been recorded yet'}</span>
          <strong>${settled ? escapeHtml(data.method) + ' · ' + escapeHtml(data.reference || 'No reference') : 'Awaiting platform administrator'}</strong>
          ${data.note ? `<p>${escapeHtml(data.note)}</p>` : ''}
        </div>
      </section>`;
    drawer.removeAttribute('inert');
    drawer.setAttribute('aria-hidden', 'false');
    drawer.classList.add('open');
    document.body.classList.add('he-lock');
    drawer.querySelector('[data-close-payout]')?.focus();
  }

  function closeDrawer() {
    drawer.classList.remove('open');
    drawer.setAttribute('aria-hidden', 'true');
    drawer.setAttribute('inert', '');
    document.body.classList.remove('he-lock');
  }

  document.querySelectorAll('[data-payout]').forEach((button) => {
    button.innerHTML = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 12s3.5-6 9-6 9 6 9 6-3.5 6-9 6-9-6-9-6Z"/><circle cx="12" cy="12" r="2.5"/></svg><span>View details</span>';
    button.setAttribute('aria-label', 'View payout details');
    button.addEventListener('click', () => {
      try { openDrawer(JSON.parse(button.dataset.payout || '{}')); } catch (_) { /* Invalid row data. */ }
    });
  });
  drawer.querySelectorAll('[data-close-payout]').forEach((button) => button.addEventListener('click', closeDrawer));
  document.addEventListener('keydown', (event) => { if (event.key === 'Escape' && drawer.classList.contains('open')) closeDrawer(); });
})();
