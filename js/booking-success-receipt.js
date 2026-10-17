(() => {
  const button = document.getElementById('successReceipt');
  const error = document.getElementById('successReceiptError');
  if (!button) return;
  button.addEventListener('click', async () => {
    if (button.disabled) return;
    button.disabled = true;
    button.setAttribute('aria-busy', 'true');
    error.hidden = true;
    try {
      const url = new URL(window.location.href);
      url.searchParams.set('format', 'receipt');
      const response = await fetch(url, { headers: { Accept: 'application/json' }, cache: 'no-store' });
      if (!response.headers.get('content-type')?.includes('application/json')) {
        throw new Error('Your receipt session has expired. Please open your booking to view its receipt.');
      }
      const data = await response.json();
      if (!response.ok || !data.success || !data.receipt) throw new Error(data.message || 'Your receipt could not be loaded. Please try again.');
      window.BookingPaymentSummary.open(data.receipt);
    } catch (failure) {
      error.textContent = failure.message || 'Your receipt could not be loaded. Please try again.';
      error.hidden = false;
    } finally {
      button.disabled = false;
      button.removeAttribute('aria-busy');
    }
  });
})();
