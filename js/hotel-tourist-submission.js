(() => {
  'use strict';
  const modal = document.getElementById('hotelTouristPdfModal');
  const frame = document.getElementById('hotelTouristPdfFrame');
  const download = document.getElementById('hotelTouristPdfDownload');
  let trigger = null;
  function close() {
    if (!modal.classList.contains('open')) return;
    modal.classList.remove('open'); modal.setAttribute('aria-hidden', 'true'); modal.inert = true;
    frame.removeAttribute('src'); download.href = '#'; trigger?.focus();
  }
  document.querySelectorAll('[data-hotel-tourists]').forEach(button => {
    button.addEventListener('click', () => {
      const id = Number(button.dataset.hotelTourists);
      if (!Number.isInteger(id) || id < 1) return;
      trigger = button;
      const params = new URLSearchParams({booking_id: String(id), booking_domain: 'hotel', viewer: 'hotel_admin'});
      const url = 'php/tourist_submission_pdf.php?' + params.toString();
      document.getElementById('hotelTouristPdfReference').textContent = 'Booking ' + button.dataset.bookingReference;
      download.href = url + '&download=1'; frame.src = url;
      modal.inert = false; modal.classList.add('open'); modal.setAttribute('aria-hidden', 'false');
      modal.querySelector('[data-close-hotel-tourists]').focus();
    });
  });
  modal.querySelector('[data-close-hotel-tourists]').addEventListener('click', close);
  modal.addEventListener('mousedown', event => { if (event.target === modal) close(); });
  document.addEventListener('keydown', event => { if (event.key === 'Escape') close(); });
})();
