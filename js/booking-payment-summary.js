(() => {
  const scriptUrl = document.currentScript.src;
  const root = new URL('../', scriptUrl);
  const stylesheet = new URL('styles/admin_receipt.css?v=4', root).href;
  const escape = value => String(value ?? '').replace(/[&<>"']/g, character => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[character]);
  const money = value => `\u20b1${Number(value || 0).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
  const statusLabel = value => String(value || '').replace(/_/g, ' ').replace(/\b[a-z]/g, character => character.toUpperCase());
  const labels = { additional_boat: 'Additional Boat', additional_tourguide: 'Additional Tour Guide', food: 'Food', others: 'Other Add-on' };
  let modal = document.getElementById('adminReceiptModal');
  if (!modal) {
    document.body.insertAdjacentHTML('beforeend', `<div id="adminReceiptModal" class="admin-receipt-overlay" aria-hidden="true" inert>
      <section class="admin-receipt-shell" role="dialog" aria-modal="true" aria-labelledby="adminReceiptTitle">
        <header class="admin-receipt-head"><div><span>ACCOUNT PAYMENT RECORD</span><h3 id="adminReceiptTitle">Payment Receipt</h3></div><button type="button" class="admin-receipt-x" data-close-admin-receipt aria-label="Close payment receipt">&times;</button></header>
        <div class="admin-receipt-stage"><article class="admin-receipt-paper" id="adminReceiptPaper"></article></div>
        <footer class="admin-receipt-footer"><button type="button" class="admin-receipt-btn secondary" data-close-admin-receipt>Close</button><button type="button" class="admin-receipt-btn print" id="printAdminReceipt">Print</button><button type="button" class="admin-receipt-btn primary" id="downloadAdminReceipt">Download</button></footer>
      </section></div>`);
    modal = document.getElementById('adminReceiptModal');
  }
  modal.querySelector('h3').textContent = 'Payment Receipt';
  modal.querySelector('.admin-receipt-head span').textContent = 'ACCOUNT PAYMENT RECORD';
  const buttonIcon = path => `<svg viewBox="0 0 24 24" aria-hidden="true"><path d="${path}"/></svg>`;
  document.getElementById('printAdminReceipt').innerHTML = buttonIcon('M7 8V3h10v5M7 17H4V8h16v9h-3M7 14h10v7H7zM17 11h.01') + '<span>Print</span>';
  document.getElementById('downloadAdminReceipt').innerHTML = buttonIcon('M12 3v12m-5-5 5 5 5-5M4 16v5h16v-5') + '<span>Download</span>';
  const paper = document.getElementById('adminReceiptPaper');
  let current;
  let previousFocus;
  let toolsPromise;

  function close() {
    if (!modal.classList.contains('show')) return;
    if (modal.contains(document.activeElement)) document.activeElement.blur();
    modal.classList.remove('show');
    modal.setAttribute('aria-hidden', 'true');
    modal.inert = true;
    document.body.classList.remove('payment-summary-open');
    if (previousFocus?.isConnected) previousFocus.focus();
  }

  function open(data) {
    if (!data?.booking) return;
    current = data;
    const booking = data.booking;
    const hotel = data.kind === 'hotel';
    const reference = booking.booking_reference || booking.hotel_booking_id || booking.booking_id;
    const guest = booking.t_full_name || booking.guest_name || data.guest || 'Guest';
    const service = data.serviceName || booking.room_type || booking.package_name || booking.boat_name || booking.guide_name || booking.location || 'Tour service';
    const type = hotel ? 'Hotel accommodation' : String(booking.booking_type || (data.kind === 'operator' ? 'Tour package' : 'Tour')).replace(/_/g, ' ');
    const bookingStatus = booking.is_complete === 'completed' ? 'Completed' : statusLabel(booking.booking_status || booking.status || 'Active');
    const expenses = data.expenses || [];
    let baseRows = data.priceBreakdown?.rows || [];
    let breakdownNote = data.priceBreakdown?.note || '';
    if (hotel) {
      const rooms = Math.max(1, Number(booking.rooms_booked || 1));
      const nights = Math.max(1, Number(booking.checkout_total_nights || booking.nights || 1));
      const unit = Number(booking.unit_price || 0);
      const rateDetail = Math.abs(rooms * nights * unit - data.serviceAmount) < 0.009
        ? `${rooms} room(s) \u00d7 ${nights} night(s) \u00d7 ${money(unit)} per room per night. ` : '';
      baseRows = [{ description: `Room accommodation — ${booking.room_type || 'Reserved room'}`, amount: data.serviceAmount,
        detail: `${rateDetail}${booking.checkin_date || ''} — ${booking.checkout_date || ''}` }];
    }
    if (!Array.isArray(baseRows) || !baseRows.length || Math.abs(baseRows.reduce((sum, row) => sum + Number(row.amount || 0), 0) - Number(data.serviceAmount)) > 0.009) {
      baseRows = [{ description: `Recorded ${String(booking.booking_type || 'reservation').toLowerCase()} charge`, amount: data.serviceAmount }];
      breakdownNote = 'The original component prices were not saved for this reservation. Separately recorded charges and add-ons are listed below.';
    }
    const baseChargeRows = baseRows.map(row => {
      const detail = [row.quantity != null && row.unit_price != null ? `${row.quantity} \u00d7 ${money(row.unit_price)}` : '', row.detail].filter(Boolean).join(' \u00b7 ');
      return `<tr><td>${escape(row.description)}${detail ? `<small class="receipt-line-detail">${escape(detail)}</small>` : ''}</td><td>${money(row.amount)}</td></tr>`;
    }).join('');
    const rows = expenses.map(item => {
      const name = item.expense_name || labels[item.expense_type] || String(item.expense_type || 'Additional charge').replace(/_/g, ' ');
      const detail = [item.quantity && item.unit_price != null ? `${item.quantity} \u00d7 ${money(item.unit_price)}` : '', item.notes || item.note].filter(Boolean).join(' \u00b7 ');
      return `<tr><td>${escape(name)}${detail ? `<small class="receipt-line-detail">${escape(detail)}</small>` : ''}</td><td>${money(hotel ? item.total_amount : item.amount)}</td></tr>`;
    }).join('');
    const generated = new Intl.DateTimeFormat('en-PH', { timeZone: 'Asia/Manila', dateStyle: 'medium', timeStyle: 'short' }).format(new Date());
    paper.innerHTML = `<div class="receipt-brand">
      <div class="receipt-brand-identity"><img class="receipt-brand-logo" src="${new URL('img/newlogo.png', root).href}" alt="iTour Mercedes seal"><div><img class="receipt-brand-wordmark" src="${new URL('img/textlogo2.png', root).href}" alt="iTour Mercedes"><p>PAYMENT RECEIPT</p></div></div>
      <div class="receipt-number"><small>RECEIPT REFERENCE</small><strong>${escape(reference)}</strong></div></div>
      <div class="receipt-meta"><div><small>BILLED TO</small><strong>${escape(guest)}</strong></div><div><small>SERVICE</small><strong>${escape(service)}</strong></div><div><small>RESERVATION TYPE</small><strong>${escape(type)}</strong></div><div><small>RESERVATION STATUS</small><strong>${escape(bookingStatus)}</strong></div></div>
      <table class="receipt-table"><thead><tr><th>DESCRIPTION</th><th>AMOUNT</th></tr></thead><tbody>
        ${baseChargeRows}
        ${rows}
        ${hotel && data.checkoutCharges > 0 ? `<tr><td>Checkout additional charges</td><td>${money(data.checkoutCharges)}</td></tr>` : ''}
        <tr class="receipt-total"><td>TOTAL</td><td>${money(data.billingTotal)}</td></tr></tbody></table>
      <div class="receipt-summary"><div><small>AMOUNT PAID</small><strong>${money(data.amountPaid)}</strong></div><div><small>BALANCE</small><strong>${money(data.balance)}</strong></div><div><small>PAYMENT STATUS</small><strong>${escape(statusLabel(data.paymentStatus))}</strong></div></div>
      <div class="receipt-foot">${breakdownNote ? `${escape(breakdownNote)}<br><br>` : ''}This receipt shows charges, recorded payments, and the outstanding balance at the time it was generated. Please retain it for your records.<br>Generated ${escape(generated)} (Manila time).</div>`;
    previousFocus = document.activeElement;
    modal.inert = false;
    modal.classList.add('show');
    modal.setAttribute('aria-hidden', 'false');
    modal.querySelector('.admin-receipt-stage').scrollTop = 0;
    document.body.classList.add('payment-summary-open');
    modal.querySelector('.admin-receipt-x').focus();
  }

  function print() {
    if (!current) return;
    const popup = window.open('', '_blank', 'width=900,height=720');
    if (!popup) { alert('Allow pop-ups for this site to print the payment receipt.'); return; }
    popup.document.write(`<!doctype html><html><head><meta charset="utf-8"><title>Payment Receipt</title><link rel="stylesheet" href="${stylesheet}"><style>body{margin:0;padding:20px;background:#fff}.admin-receipt-paper{width:560px;min-height:0;margin:auto;box-shadow:none}@page{size:A4 portrait;margin:12mm}@media print{body{padding:0}.admin-receipt-paper{width:100%;max-width:560px}tr{break-inside:avoid}}</style></head><body><article class="admin-receipt-paper">${paper.innerHTML}</article></body></html>`);
    popup.document.close();
    popup.addEventListener('load', () => { popup.focus(); popup.print(); }, { once: true });
  }

  function loadScript(url, ready) {
    if (ready()) return Promise.resolve();
    return new Promise((resolve, reject) => {
      const script = document.createElement('script');
      script.src = url;
      script.onload = () => ready() ? resolve() : reject(new Error('PDF tools did not initialize.'));
      script.onerror = () => reject(new Error('The PDF tools could not be loaded. Please try again.'));
      document.head.append(script);
    });
  }

  async function download() {
    if (!current) return;
    const button = document.getElementById('downloadAdminReceipt');
    if (button.disabled) return;
    const snapshot = current;
    const markup = paper.innerHTML;
    let exportHolder;
    button.disabled = true;
    button.querySelector('span').textContent = 'Preparing...';
    try {
      toolsPromise ??= Promise.all([
        loadScript(new URL('js/vendor/payment-summary/html2canvas.min.js', root).href, () => !!window.html2canvas),
        loadScript(new URL('js/vendor/payment-summary/jspdf.umd.min.js', root).href, () => !!window.jspdf?.jsPDF)
      ]).catch(error => { toolsPromise = null; throw error; });
      await toolsPromise;
      exportHolder = document.createElement('div');
      exportHolder.style.cssText = 'position:fixed;left:-10000px;top:0;width:560px;pointer-events:none';
      exportHolder.setAttribute('aria-hidden', 'true');
      const exportPaper = document.createElement('article');
      exportPaper.className = 'admin-receipt-paper payment-summary-export';
      exportPaper.innerHTML = markup;
      exportHolder.append(exportPaper);
      document.body.append(exportHolder);
      await Promise.all(Array.from(exportPaper.querySelectorAll('img'), image => image.decode().catch(() => {})));
      const canvas = await window.html2canvas(exportPaper, { scale: 2, backgroundColor: '#fff', useCORS: true });
      const pdf = new window.jspdf.jsPDF({ orientation: 'portrait', unit: 'mm', format: 'a4' });
      const ratio = 190 / canvas.width;
      const sliceHeight = Math.floor(277 / ratio);
      for (let top = 0, page = 0; top < canvas.height; top += sliceHeight, page++) {
        if (page) pdf.addPage();
        const slice = document.createElement('canvas');
        slice.width = canvas.width;
        slice.height = Math.min(sliceHeight, canvas.height - top);
        slice.getContext('2d').drawImage(canvas, 0, top, canvas.width, slice.height, 0, 0, slice.width, slice.height);
        pdf.addImage(slice.toDataURL('image/png'), 'PNG', 10, 10, 190, slice.height * ratio, undefined, 'FAST');
      }
      const booking = snapshot.booking;
      const reference = String(booking.booking_reference || booking.hotel_booking_id || booking.booking_id).replace(/[^a-z0-9_-]+/gi, '-');
      pdf.save(`payment-receipt-${reference}.pdf`);
    } catch (error) {
      if (window.Swal) await Swal.fire('Download Failed', error.message, 'error');
      else alert(error.message);
    } finally {
      exportHolder?.remove();
      button.disabled = false;
      button.querySelector('span').textContent = 'Download';
    }
  }

  modal.querySelectorAll('[data-close-admin-receipt]').forEach(button => button.addEventListener('click', close));
  document.getElementById('printAdminReceipt').addEventListener('click', print);
  document.getElementById('downloadAdminReceipt').addEventListener('click', download);
  modal.addEventListener('mousedown', event => { if (event.target === modal) close(); });
  document.addEventListener('keydown', event => { if (event.key === 'Escape') close(); });
  window.BookingPaymentSummary = { open, close, print, download };
})();
