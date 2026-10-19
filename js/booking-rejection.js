(function () {
  'use strict';
  window.showBookingDecisionProcessing = async function (title, text) {
    if (!window.Swal) return;
    Swal.fire({
      title: title || 'Processing booking decision',
      text: text || 'Please wait while the booking is updated and the email is sent.',
      color: '#173f34', background: '#ffffff', confirmButtonColor: '#176b58',
      allowOutsideClick: false, allowEscapeKey: false, allowEnterKey: false,
      showConfirmButton: false, showCancelButton: false,
      didOpen: () => Swal.showLoading()
    });
    // Allow the loading indicator to paint before starting the full-page POST.
    await new Promise(resolve => window.setTimeout(resolve, 80));
  };
  document.addEventListener('submit', async event => {
    const form = event.target;
    if (!(form instanceof HTMLFormElement) || !form.matches('[data-cancel-form]')) return;
    event.preventDefault();
    event.stopImmediatePropagation();
    if (form.dataset.rejectionSubmitting === '1') return;
    const hotelDecision = form.dataset.hotelDecision;
    const cancelling = hotelDecision === 'cancel';
    const hotel = cancelling || hotelDecision === 'reject';
    const label = cancelling ? 'cancellation' : 'rejection';
    const explanation = 'The booking will be cancelled and an approved cancellation request created. Any amount paid will be queued for a full refund.';
    let reason = '';
    if (window.Swal) {
      const result = await Swal.fire({
        icon: 'warning', title: hotel ? (cancelling ? 'Cancel accepted booking?' : 'Reject pending booking?') : 'Reject / cancel booking?', text: explanation,
        input: 'textarea', inputLabel: 'Reason shared with the guest', inputPlaceholder: cancelling ? 'Enter the reason for cancelling this accepted booking' : 'Enter the reason for rejecting this pending booking',
        inputAttributes: { maxlength: '1500' }, showCancelButton: true,
        confirmButtonText: cancelling ? 'Cancel booking' : 'Reject booking', cancelButtonText: 'Keep booking', confirmButtonColor: '#2b7a66',
        inputValidator: value => !String(value || '').trim() ? `A ${label} reason is required.` : (String(value).trim().length > 1500 ? 'Use 1,500 characters or fewer.' : undefined)
      });
      if (!result.isConfirmed) return;
      reason = String(result.value || '').trim();
    } else {
      const value = window.prompt(explanation + `\n\n${cancelling ? 'Cancellation' : 'Rejection'} reason (required):`);
      if (value === null) return;
      reason = value.trim();
      if (!reason || reason.length > 1500) { window.alert(`A ${label} reason of 1 to 1,500 characters is required.`); return; }
    }
    let input = form.querySelector('input[name="rejection_reason"]');
    if (!input) { input = document.createElement('input'); input.type = 'hidden'; input.name = 'rejection_reason'; form.appendChild(input); }
    input.value = reason;
    form.dataset.rejectionSubmitting = '1';
    form.querySelectorAll('button[type="submit"]').forEach(button => { button.disabled = true; });
    await window.showBookingDecisionProcessing(hotel ? (cancelling ? 'Cancelling accepted booking' : 'Rejecting pending booking') : 'Rejecting / cancelling booking', hotel ? `Please wait while the ${label} is saved and the guest email is sent.` : 'Please wait while the cancellation is saved and the rejection email is sent.');
    HTMLFormElement.prototype.submit.call(form);
  }, true);
  const flash = window.bookingRejectionFlash;
  if (flash && flash.message) {
    if (window.Swal) Swal.fire({ icon: flash.icon || 'info', title: flash.title || (flash.icon === 'error' ? 'Decision not completed' : 'Booking decision'), text: flash.message, confirmButtonColor: '#176b58', confirmButtonText: 'OK' });
    else window.alert(flash.message);
  }
})();
