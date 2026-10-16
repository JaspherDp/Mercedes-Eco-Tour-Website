(function () {
  'use strict';
  window.AdminWalkinPayment = {
    create(options) {
      const { form, button } = options;
      const requestId = Array.from(crypto.getRandomValues(new Uint8Array(16)), value => value.toString(16).padStart(2, '0')).join('');
      let booking = null;
      let token = '';
      let busy = false;
      let finished = false;
      const label = text => { button.querySelector('span').textContent = text; };
      const loading = (title, text) => Swal.fire({ title, text, allowOutsideClick: false, allowEscapeKey: false, showConfirmButton: false, didOpen: () => Swal.showLoading() });
      const post = async (action, extra = {}) => {
        const body = new FormData();
        body.set('walkin_ajax', '1');
        body.set('action', action);
        body.set('booking_id', String(booking.booking_id));
        body.set('walkin_request_id', requestId);
        body.set('csrf_token', form.elements.csrf_token.value);
        Object.entries(extra).forEach(([key, value]) => body.set(key, value));
        const response = await fetch('adbookings.php', { method: 'POST', body, headers: { Accept: 'application/json' } });
        const result = await options.readJson(response, 'The booking payment response could not be read. Retry to check the same booking.');
        if (!response.ok || !result.success) throw new Error(result.message || 'The booking payment could not be completed.');
        return result;
      };
      const success = async result => {
        const paid = result.booking;
        if (!paid || paid.status !== 'accepted' || Number(paid.is_paid) !== 1
            || !(Number(paid.grand_total) > 0) || !(Number(paid.remaining_balance) <= 0)
            || !(Number(paid.payment_amount) >= Number(paid.grand_total))) {
          throw new Error('Full payment has not been verified. The booking has not been added successfully.');
        }
        booking = paid;
        finished = true;
        await Swal.fire({ icon: 'success', title: 'Payment Received', text: `Booking ${booking.booking_reference || booking.booking_id} is confirmed and accepted.`, confirmButtonText: 'OK', confirmButtonColor: '#176b55', allowOutsideClick: false, allowEscapeKey: false });
        options.onSuccess();
      };
      const finalize = async () => success(await post('finish_walkin_booking'));
      const status = async () => {
        const response = await fetch(`adbookings.php?action=paymongoPaymentStatus&token=${encodeURIComponent(token)}`, { cache: 'no-store', headers: { Accept: 'application/json' } });
        const result = await options.readJson(response, 'Payment verification is temporarily unavailable.');
        if (!response.ok || !result.success) throw new Error(result.message || 'Payment verification is temporarily unavailable.');
        return result;
      };
      const sendQr = async phoneName => {
        const result = await options.requestQr(Number(booking.booking_id), Number(booking.remaining_balance));
        token = String(result.return_token || '');
        if (!/^[a-f0-9]{64}$/.test(token)) throw new Error('The payment service returned an invalid verification token.');
        if (result.phone_notification?.sent !== true) throw new Error(result.phone_notification?.message || 'The QR notification could not be sent. Tap Retry Payment to resend it.');
        return result.phone_notification.device_name || phoneName;
      };
      const waitForPayment = async phoneName => {
        let cancelRequested = false;
        let resendRequested = false;
        const showWaiting = () => Swal.fire({
          title: 'Waiting for Payment',
          text: `QR notification requested for ${phoneName}. If it has not appeared on the phone, tap Resend QR. Waiting for the tourist to pay.`,
          allowOutsideClick: false, allowEscapeKey: false, showCancelButton: true, showConfirmButton: false,
          showDenyButton: true, denyButtonText: 'Resend QR', denyButtonColor: '#176b55',
          preDeny: () => { resendRequested = true; return false; },
          cancelButtonText: 'Cancel Payment', cancelButtonColor: '#b5444f', didOpen: () => Swal.showLoading()
        }).then(result => { if (result.dismiss === Swal.DismissReason.cancel) cancelRequested = true; });
        showWaiting();
        for (let attempt = 0; attempt < 120; attempt++) {
          if (cancelRequested) {
            loading('Cancelling Payment', 'Checking payment before cancelling the QR…');
            await options.cancelQr(Number(booking.booking_id), token);
            const latest = await status();
            if (latest.status === 'paid') { await finalize(); return; }
            throw new Error('Payment was cancelled. The booking remains unconfirmed. Tap Retry Payment to try again.');
          }
          let result;
          try { result = await status(); } catch (_) {
            await new Promise(resolve => setTimeout(resolve, 2500));
            continue;
          }
          if (result.status === 'paid') { await finalize(); return; }
          if (['failed', 'expired', 'cancelled'].includes(result.status)) throw new Error(`The QR payment ${result.status}. The booking has not been accepted. Tap Retry Payment to try again.`);
          if (resendRequested) {
            resendRequested = false;
            loading('Resending QR Notification', `Sending another notification to ${phoneName}…`);
            phoneName = await sendQr(phoneName);
            showWaiting();
            attempt = 0;
          }
          await new Promise(resolve => setTimeout(resolve, 2500));
        }
        throw new Error('Payment verification is still pending. Tap Retry Payment to check this same QR payment again.');
      };
      return {
        hasBooking: () => Boolean(booking),
        isBusy: () => busy && !finished,
        async pay() {
          if (busy || finished) return;
          busy = true;
          button.disabled = true;
          label('Processing…');
          try {
            const method = form.elements.payment_method.value;
            if (method === 'cash' && !form.elements.payment_collected.checked) throw new Error('Collect the full cash amount, then check the cash payment confirmation before paying for this booking.');
            if (method === 'qr_code' && !(await options.requirePhone())) return;
            const phone = method === 'qr_code' ? await options.fetchPhone() : null;
            if (method === 'qr_code' && (!phone?.registered || !phone.device)) throw new Error('Register a payment phone before sending the QR.');
            loading(method === 'qr_code' ? 'Sending Payment QR' : 'Recording Cash Payment', method === 'qr_code' ? `Preparing the QR for ${phone.device.device_name || 'the registered admin phone'}…` : 'Recording full payment and confirming the booking…');
            if (!booking) {
              const body = new FormData(form);
              body.set('action', 'create_walkin_booking');
              body.set('walkin_ajax', '1');
              body.set('walkin_request_id', requestId);
              const response = await fetch('adbookings.php', { method: 'POST', body, headers: { Accept: 'application/json' } });
              const result = await options.readJson(response, 'The booking response could not be read. Retry to check the same booking.');
              if (!response.ok || !result.success) throw new Error(result.message || 'The booking could not be prepared.');
              booking = result.booking;
              form.elements.csrf_token.value = result.csrf_token;
              options.onCreated(booking);
            }
            // A retry can discover that the previous verified payment already
            // completed, without creating or charging a second reservation.
            if (Number(booking.is_paid) === 1 && Number(booking.remaining_balance) <= 0) { await finalize(); return; }
            if (token && (await status()).status === 'paid') { await finalize(); return; }
            if (method === 'cash') {
              if (token) {
                await options.cancelQr(Number(booking.booking_id), token);
                if ((await status()).status === 'paid') { await finalize(); return; }
              }
              await success(await post('pay_walkin_cash'));
              return;
            }
            const phoneName = await sendQr(phone.device.device_name || 'the registered admin phone');
            await waitForPayment(phoneName);
          } catch (error) {
            // Payment may have settled while a retry/finalization request lost
            // its response; the next attempt checks this same booking/token.
            if (!finished) await Swal.fire({ icon: 'error', title: 'Payment Not Completed', text: error.message || 'Payment could not be completed. Please retry.', confirmButtonText: 'OK', confirmButtonColor: '#176b55', allowOutsideClick: false });
          } finally {
            busy = false;
            button.disabled = finished;
            label(booking && !finished ? 'Retry Payment' : 'Pay Booking');
          }
        }
      };
    }
  };
})();
