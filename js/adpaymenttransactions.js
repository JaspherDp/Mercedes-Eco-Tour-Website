(function () {
  'use strict';
  const recordModal = document.getElementById('recordPaymentModal');
  const drawer = document.getElementById('transactionDrawer');
  const bookingDrawer = document.getElementById('bookingDetailDrawer');
  const refundDrawer = document.getElementById('refundDetailDrawer');
  const refundDetailBody = document.getElementById('refundDetailBody');
  const bookingDetailBody = document.getElementById('bookingDetailBody');
  const bookingDetailCollectButton = document.getElementById('bookingDetailCollectButton');
  const selection = document.getElementById('bookingSelection');
  const selectedBooking = document.getElementById('selectedBooking');
  const paymentDomain = document.getElementById('paymentDomain');
  const paymentBookingId = document.getElementById('paymentBookingId');
  const paymentAmount = document.getElementById('paymentAmount');
  const amountHelp = document.getElementById('amountHelp');
  const collectionMethod = document.getElementById('collectionPaymentMethod');
  const collectionConfig = window.paymentCollectionConfig || {};
  const exportConfig = window.paymentExportConfig || {};
  const refundProcessingConfig = window.refundProcessingConfig || {};
  const exportModal = document.getElementById('transactionExportModal');
  const receiptModal = document.getElementById('transactionReceiptModal');
  const receiptPaper = document.getElementById('transactionReceiptPaper');
  const phoneRegistrationState = { device: null, baseline: null, pollTimer: null };
  const phoneChangeStorageKey = 'itour_admin_payment_phone_changed';
  let exportInitialized = false;
  let exportPreviewController = null;
  let activeTransaction = null;
  let activeBookingCollectionKey = '';
  let bookingDetailController = null;

  function openRefundDrawer(refund) {
    if (!refundDrawer || !refundDetailBody) return;
    const detail = (label, value) => `<div class="drawer-detail"><span>${escapeHtml(label)}</span><strong>${escapeHtml(value || '—')}</strong></div>`;
    const attempts = Array.isArray(refund.attempts) ? refund.attempts : [];
    const attemptDetails = attempts.length ? `<section class="drawer-section"><h4>Individual refund attempts</h4>${attempts.map(attempt => `
      <div class="refund-attempt-detail">
        ${detail('Payment transaction', attempt.transaction)}${detail('Refund amount', attempt.amount)}${detail('Payment method', attempt.method)}${detail('Status', attempt.status)}${detail('Provider refund reference', attempt.reference || 'Not assigned')}${attempt.claim_required ? detail('Customer action', 'Secure claim link issued by email') : ''}${attempt.failure ? detail('Failure', attempt.failure) : ''}
      </div>`).join('')}</section>` : '';
    refundDetailBody.innerHTML = `
      <section class="drawer-summary refund-drawer-summary">
        <div class="drawer-summary-top"><span>REFUND REQUEST #${Number(refund.request_id)}</span><span class="status-badge ${escapeHtml(refund.status_class)}"><i></i>${escapeHtml(refund.status)}</span></div>
        <span>ELIGIBLE REFUND</span><strong>${escapeHtml(refund.eligible_amount)}</strong>
        <small>${escapeHtml(refund.booking_reference)} · ${escapeHtml(refund.booking_type)}</small>
      </section>
      <section class="refund-drawer-person"><div class="refund-drawer-avatar">${escapeHtml(String(refund.guest || 'G').charAt(0).toUpperCase())}${refund.profile_image ? `<img src="${escapeHtml(refund.profile_image)}" alt="${escapeHtml(refund.guest || 'Tourist')} profile photo" onerror="this.remove()">` : ''}</div><div><small>TOURIST PROFILE</small><strong>${escapeHtml(refund.guest || 'Guest')}</strong><span>${escapeHtml(refund.email || 'No email recorded')}</span></div></section>
      <section class="drawer-section"><h4>Booking and cancellation</h4>
        ${detail('Booking reference', refund.booking_reference)}${detail('Booking type', refund.booking_type)}${detail('Destination / assignment', refund.service)}${detail('Service date', refund.service_date)}${detail('Cancellation requested', refund.requested_at)}${detail('Tourist reason', refund.reason)}
      </section>
      <section class="drawer-section"><h4>Refund calculation</h4>
        ${detail('Amount paid', refund.amount_paid)}${detail('Eligibility rule', refund.policy)}${detail('Eligible refund', refund.eligible_amount)}${detail('Non-refundable', refund.non_refundable)}${detail('Provider-confirmed', refund.refunded_amount)}
      </section>
      <section class="refund-routing-card"><header><span>↩</span><div><small>RETURN ROUTE</small><strong>${escapeHtml(refund.route || 'Original payment method')}</strong></div></header>
        ${detail('Original payment channel', refund.method)}${detail('Destination', refund.destination)}${detail('Payment provider', refund.provider)}${detail('Original payment ID', refund.payment_reference)}${detail('Merchant reference', refund.merchant_reference)}${detail('Payment date', refund.payment_date)}${detail('Refund reference', refund.provider_refund_id || 'Created after submission')}${refund.manual_refund_channel ? detail('Manual refund channel', refund.manual_refund_channel) : ''}${refund.manual_sender_account ? detail('Admin source account', refund.manual_sender_account) : ''}${refund.manual_refund_note ? detail('Administrator note', refund.manual_refund_note) : ''}
      </section>
      ${attemptDetails}
      <section class="refund-timeline-note"><strong>${escapeHtml(refund.timeline)}</strong><p>${escapeHtml(refund.timeline_detail)}</p></section>
      ${refund.failure_message ? `<section class="drawer-error"><strong>Latest PayMongo error${refund.failure_code ? ` (${escapeHtml(refund.failure_code)})` : ''}</strong><br>${escapeHtml(refund.failure_message)}</section>` : ''}
      <p class="refund-privacy-note">Eligible full and partial refunds are returned through PayMongo when the original payment supports API refunds. Any manual refund is recorded only after an administrator confirms the transfer and provider reference.</p>`;
    refundDrawer.classList.add('open');
    refundDrawer.setAttribute('aria-hidden', 'false');
    setPageLock(true);
  }

  function closeRefundDrawer() {
    if (!refundDrawer) return;
    refundDrawer.classList.remove('open');
    refundDrawer.setAttribute('aria-hidden', 'true');
    setPageLock(false);
  }

  function closeRefundMenus(except = null) {
    document.querySelectorAll('.refund-action-popover').forEach(menu => {
      if (menu === except) return;
      menu.hidden = true;
      menu.previousElementSibling?.setAttribute('aria-expanded', 'false');
    });
  }

  function setPageLock(locked) {
    document.body.style.overflow = locked ? 'hidden' : '';
  }
  function openRecordModal(value) {
    if (value && selection) selection.value = value;
    updateBookingSelection();
    recordModal.classList.add('open');
    recordModal.setAttribute('aria-hidden', 'false');
    setPageLock(true);
    setTimeout(() => (value ? paymentAmount : selection).focus(), 30);
  }
  function closeRecordModal() {
    recordModal.classList.remove('open');
    recordModal.setAttribute('aria-hidden', 'true');
    setPageLock(false);
  }
  function updateBookingSelection() {
    if (!selection) return;
    const option = selection.options[selection.selectedIndex];
    const parts = selection.value.split(':');
    paymentDomain.value = parts[0] || '';
    paymentBookingId.value = parts[1] || '';
    const balance = Number(option && option.dataset.balance || 0);
    paymentAmount.max = balance ? balance.toFixed(2) : '';
    if (balance && (!Number(paymentAmount.value) || Number(paymentAmount.value) > balance)) paymentAmount.value = balance.toFixed(2);
    amountHelp.textContent = balance ? `Maximum collectible amount: ₱${balance.toLocaleString('en-PH', {minimumFractionDigits: 2})}` : 'Cannot exceed the outstanding balance.';
    selectedBooking.innerHTML = selection.value
      ? `<span>${escapeHtml(option.dataset.customer || 'Guest')}</span><strong>${escapeHtml(option.dataset.reference || 'Booking')} · ₱${balance.toLocaleString('en-PH', {minimumFractionDigits: 2})} due</strong>`
      : '<span>Select an outstanding booking below</span><strong>No booking selected</strong>';
  }

  function clampCollectionAmountToBalance() {
    if (!paymentAmount) return;
    const maximum = Number(paymentAmount.max || 0);
    const entered = Number(paymentAmount.value);
    if (maximum > 0 && Number.isFinite(entered) && entered > maximum) {
      paymentAmount.value = maximum.toFixed(2);
    }
  }

  async function readPaymentJson(response, fallbackMessage) {
    const text = await response.text();
    try { return JSON.parse(text); } catch (_) { throw new Error(fallbackMessage); }
  }

  function paymentMessage(icon, title, text) {
    if (window.Swal) return Swal.fire({ icon, title, text, confirmButtonColor: '#2b7a66' });
    window.alert(`${title}\n\n${text}`);
    return Promise.resolve();
  }

  async function fetchRegisteredAdminPhone() {
    const response = await fetch(collectionConfig.phoneStatusEndpoint, { credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'application/json' } });
    const result = await readPaymentJson(response, 'The registered payment phone could not be checked.');
    if (!response.ok || !result.success) throw new Error(result.message || 'The registered payment phone could not be checked.');
    return result;
  }

  function registeredPhoneMeta(device) {
    const registeredAt = device?.last_used_at || device?.created_at;
    const date = registeredAt ? new Date(String(registeredAt).replace(' ', 'T')) : null;
    return date && !Number.isNaN(date.getTime())
      ? `Notifications active · Registered ${date.toLocaleString()}`
      : 'Ready to receive PayMongo payment notifications.';
  }

  function renderRegisteredAdminPhone(result) {
    const name = document.getElementById('collectionPhoneName');
    const meta = document.getElementById('collectionPhoneMeta');
    const action = document.getElementById('manageAdminPhoneBtn');
    const overviewName = document.getElementById('adminPhoneOverviewName');
    const overviewMeta = document.getElementById('adminPhoneOverviewMeta');
    const overviewAction = document.getElementById('adminPhoneOverviewAction');
    const overviewCard = document.getElementById('adminPhoneOverviewDevice');
    const modalName = document.getElementById('adminPhoneModalDeviceName');
    const modalMeta = document.getElementById('adminPhoneModalDeviceMeta');
    const modalState = document.getElementById('adminPhoneModalDeviceState');
    const modalCard = document.getElementById('adminPhoneCurrentDevice');
    if (action) action.disabled = false;

    if (result?.registered && result.device) {
      phoneRegistrationState.device = result.device;
      const deviceName = result.device.device_name || 'Administrator phone';
      const deviceMeta = registeredPhoneMeta(result.device);
      if (name) name.textContent = deviceName;
      if (meta) meta.textContent = deviceMeta;
      if (action) action.textContent = 'Change';
      if (overviewName) overviewName.textContent = deviceName;
      if (overviewMeta) overviewMeta.textContent = deviceMeta;
      if (overviewAction) overviewAction.textContent = 'Change';
      overviewCard?.classList.add('is-registered');
      if (modalName) modalName.textContent = deviceName;
      if (modalMeta) modalMeta.textContent = deviceMeta;
      if (modalState) modalState.textContent = 'Registered';
      modalCard?.classList.add('is-registered');
    } else {
      phoneRegistrationState.device = null;
      if (name) name.textContent = 'No payment phone registered';
      if (meta) meta.textContent = 'Register an admin phone before collecting by QR Code.';
      if (action) action.textContent = 'Register a Phone';
      if (overviewName) overviewName.textContent = 'No payment phone registered';
      if (overviewMeta) overviewMeta.textContent = 'Register a phone before sending PayMongo QR notifications.';
      if (overviewAction) overviewAction.textContent = 'Register Phone';
      overviewCard?.classList.remove('is-registered');
      if (modalName) modalName.textContent = 'No payment phone registered';
      if (modalMeta) modalMeta.textContent = 'Use the setup instructions below to register a phone.';
      if (modalState) modalState.textContent = 'Not registered';
      modalCard?.classList.remove('is-registered');
    }
  }

  async function refreshRegisteredAdminPhone(silent = false) {
    const name = document.getElementById('collectionPhoneName');
    const meta = document.getElementById('collectionPhoneMeta');
    const action = document.getElementById('manageAdminPhoneBtn');
    if (!silent) {
      if (name) name.textContent = 'Checking payment phone…';
      if (meta) meta.textContent = 'Please wait.';
      if (action) action.disabled = true;
    }
    try {
      const result = await fetchRegisteredAdminPhone();
      renderRegisteredAdminPhone(result);
      return result;
    } catch (error) {
      if (!silent) {
        if (name) name.textContent = 'Unable to check payment phone';
        if (meta) meta.textContent = error.message;
        if (action) action.disabled = false;
      }
      return null;
    }
  }

  async function refreshCollectionPhone() {
    const result = await refreshRegisteredAdminPhone();
    return Boolean(result?.registered && result.device);
  }

  function updatePhoneRegistrationStatus(type, title, detail) {
    const status = document.getElementById('adminPhoneRegistrationStatus');
    if (!status) return;
    status.classList.toggle('is-success', type === 'success');
    status.classList.toggle('is-error', type === 'error');
    status.querySelector('strong').textContent = title;
    status.querySelector('small').textContent = detail;
  }

  function showPhoneToast(icon, title) {
    if (!window.Swal) return;
    Swal.fire({ toast: true, position: 'top-end', icon, title, showConfirmButton: false, timer: 2200, timerProgressBar: true });
  }

  function stopPhoneRegistrationPolling() {
    if (phoneRegistrationState.pollTimer) window.clearInterval(phoneRegistrationState.pollTimer);
    phoneRegistrationState.pollTimer = null;
  }

  async function checkPhoneRegistrationProgress(manualCheck = false) {
    if (manualCheck) updatePhoneRegistrationStatus('', 'Checking registration…', 'Looking for a newly registered Administrator phone.');
    try {
      const result = await fetchRegisteredAdminPhone();
      const device = result.registered ? result.device : null;
      const baseline = phoneRegistrationState.baseline;
      const changed = device && (!baseline
        || Number(device.device_id) !== Number(baseline.device_id)
        || String(device.last_used_at) !== String(baseline.last_used_at));
      if (changed) {
        renderRegisteredAdminPhone(result);
        stopPhoneRegistrationPolling();
        updatePhoneRegistrationStatus('success', 'Admin phone registered', `${device.device_name || 'Administrator phone'} is ready for payment notifications.`);
        localStorage.setItem(phoneChangeStorageKey, String(Date.now()));
        showPhoneToast('success', 'Admin phone registration detected.');
        return;
      }
      if (manualCheck) {
        updatePhoneRegistrationStatus('', 'No new phone detected', `Checked ${new Date().toLocaleTimeString()}. Complete registration on the phone, then try again.`);
        showPhoneToast('info', 'No new phone registration found yet.');
      } else {
        updatePhoneRegistrationStatus('', 'Waiting for phone registration', 'Keep this window open while registering the phone.');
      }
    } catch (error) {
      updatePhoneRegistrationStatus('error', 'Could not check registration', error.message);
      if (manualCheck) showPhoneToast('error', 'Could not check the phone registration.');
    }
  }

  function buildPhoneSetupUrl() {
    if (collectionConfig.publicAppUrl) {
      const publicBase = new URL(collectionConfig.publicAppUrl);
      publicBase.pathname = publicBase.pathname.endsWith('/') ? publicBase.pathname : `${publicBase.pathname}/`;
      publicBase.search = '';
      publicBase.hash = '';
      return new URL('admin-phone-setup.php', publicBase).href;
    }
    return new URL(collectionConfig.phoneSetupPage, window.location.href).href;
  }

  function openPhoneRegistrationModal() {
    const modal = document.getElementById('adminPhoneRegistrationModal');
    if (!modal || document.getElementById('manageAdminPhoneBtn')?.disabled) return;
    phoneRegistrationState.baseline = phoneRegistrationState.device ? { ...phoneRegistrationState.device } : null;
    document.getElementById('adminPhoneSetupUrl').value = buildPhoneSetupUrl();
    document.getElementById('adminPhoneRegistrationTitle').textContent = phoneRegistrationState.baseline
      ? 'Change Registered Admin Phone'
      : 'Register an Admin Phone';
    updatePhoneRegistrationStatus('', 'Waiting for phone registration', 'Keep this window open while registering the phone.');
    modal.inert = false;
    modal.setAttribute('aria-hidden', 'false');
    modal.classList.add('show');
    stopPhoneRegistrationPolling();
    phoneRegistrationState.pollTimer = window.setInterval(checkPhoneRegistrationProgress, 2500);
  }

  function closePhoneRegistrationModal() {
    const modal = document.getElementById('adminPhoneRegistrationModal');
    if (!modal) return;
    stopPhoneRegistrationPolling();
    modal.classList.remove('show');
    modal.setAttribute('aria-hidden', 'true');
    modal.inert = true;
    refreshRegisteredAdminPhone(true);
  }

  function openPhoneOverviewModal() {
    const modal = document.getElementById('adminPaymentPhoneOverviewModal');
    if (!modal) return;
    modal.inert = false;
    modal.setAttribute('aria-hidden', 'false');
    modal.classList.add('show');
  }

  function closePhoneOverviewModal() {
    const modal = document.getElementById('adminPaymentPhoneOverviewModal');
    if (!modal) return;
    modal.classList.remove('show');
    modal.setAttribute('aria-hidden', 'true');
    modal.inert = true;
  }

  function exportDateValue(date) {
    const year = date.getFullYear();
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');
    return `${year}-${month}-${day}`;
  }

  function initializeExportPeriod() {
    if (exportInitialized) return;
    const period = document.getElementById('exportPeriod');
    const from = document.getElementById('exportDateFrom');
    const to = document.getElementById('exportDateTo');
    if (exportConfig.defaultFrom || exportConfig.defaultTo) {
      period.value = 'custom';
      from.value = exportConfig.defaultFrom || exportConfig.defaultTo || '';
      to.value = exportConfig.defaultTo || exportConfig.defaultFrom || '';
    } else {
      period.value = 'this_month';
    }
    exportInitialized = true;
    syncExportPeriodControls();
  }

  function syncExportPeriodControls() {
    const period = document.getElementById('exportPeriod')?.value || 'this_month';
    const customRange = document.getElementById('exportCustomRange');
    if (customRange) customRange.hidden = period !== 'custom';
  }

  function selectedExportRange() {
    const period = document.getElementById('exportPeriod')?.value || 'this_month';
    const now = new Date();
    if (period === 'all') return { from: '', to: '', label: 'All available dates' };
    if (period === 'last_month') {
      const from = new Date(now.getFullYear(), now.getMonth() - 1, 1);
      const to = new Date(now.getFullYear(), now.getMonth(), 0);
      return { from: exportDateValue(from), to: exportDateValue(to), label: 'Previous month' };
    }
    if (period === 'custom') {
      const from = document.getElementById('exportDateFrom')?.value || '';
      const to = document.getElementById('exportDateTo')?.value || '';
      if (!from || !to) throw new Error('Select both the starting and ending dates for the custom report.');
      if (from > to) throw new Error('The report start date cannot be later than the ending date.');
      return { from, to, label: `${from} to ${to}` };
    }
    const from = new Date(now.getFullYear(), now.getMonth(), 1);
    return { from: exportDateValue(from), to: exportDateValue(now), label: 'This month to date' };
  }

  function buildExportParameters(mode) {
    const range = selectedExportRange();
    const parameters = new URLSearchParams(exportConfig.baseQuery || '');
    parameters.delete('from');
    parameters.delete('to');
    parameters.delete('export');
    parameters.delete('export_preview');
    if (range.from) parameters.set('from', range.from);
    if (range.to) parameters.set('to', range.to);
    parameters.set(mode === 'download' ? 'export' : 'export_preview', mode === 'download' ? 'csv' : '1');
    return { parameters, range };
  }

  function setExportLoading(message) {
    const state = document.getElementById('exportPreviewState');
    const tableWrap = document.getElementById('exportPreviewTableWrap');
    state.className = 'export-preview-state';
    state.innerHTML = `<span></span>${escapeHtml(message)}`;
    state.hidden = false;
    tableWrap.hidden = true;
    document.getElementById('downloadExportCsv').disabled = true;
  }

  async function loadExportPreview() {
    let request;
    try {
      request = buildExportParameters('preview');
    } catch (error) {
      const state = document.getElementById('exportPreviewState');
      state.className = 'export-preview-state error';
      state.innerHTML = `<b>!</b>${escapeHtml(error.message)}`;
      state.hidden = false;
      document.getElementById('exportPreviewTableWrap').hidden = true;
      document.getElementById('downloadExportCsv').disabled = true;
      return;
    }

    if (exportPreviewController) exportPreviewController.abort();
    exportPreviewController = new AbortController();
    setExportLoading(`Preparing ${request.range.label.toLowerCase()} preview…`);
    try {
      const response = await fetch(`${exportConfig.endpoint}?${request.parameters}`, {
        credentials: 'same-origin',
        cache: 'no-store',
        signal: exportPreviewController.signal,
        headers: { Accept: 'application/json' }
      });
      const result = await readPaymentJson(response, 'The CSV preview returned an invalid response.');
      if (!response.ok || !result.success) throw new Error(result.message || 'The CSV preview could not be prepared.');
      const summary = result.summary || {};
      const rows = Array.isArray(result.rows) ? result.rows : [];
      const recordCount = Number(summary.record_count || 0);
      document.getElementById('exportRecordCount').textContent = recordCount.toLocaleString('en-PH');
      document.getElementById('exportPaidAmount').textContent = summary.paid_amount || '₱0.00';
      document.getElementById('exportListedAmount').textContent = summary.listed_amount || '₱0.00';
      document.getElementById('exportPreviewCaption').textContent = recordCount
        ? `Showing ${rows.length} of ${recordCount.toLocaleString('en-PH')} matching records`
        : 'No matching records';
      const body = document.getElementById('exportPreviewRows');
      body.innerHTML = rows.map(row => `
        <tr>
          <td><strong>${escapeHtml(row.reference)}</strong><span>${escapeHtml(row.date)}</span></td>
          <td><strong>${escapeHtml(row.customer)}</strong><span>${escapeHtml(row.booking_reference)}</span></td>
          <td>${escapeHtml(row.booking_type)}</td>
          <td class="export-amount">${escapeHtml(row.amount)}</td>
          <td><span class="status-badge ${escapeHtml(row.status_class)}"><i></i>${escapeHtml(row.status)}</span></td>
        </tr>`).join('');
      const state = document.getElementById('exportPreviewState');
      const tableWrap = document.getElementById('exportPreviewTableWrap');
      if (rows.length) {
        state.hidden = true;
        tableWrap.hidden = false;
      } else {
        state.className = 'export-preview-state empty';
        state.innerHTML = '<b>0</b>No transactions match this reporting period and the current filters.';
        state.hidden = false;
        tableWrap.hidden = true;
      }
      document.getElementById('downloadExportCsv').disabled = recordCount === 0;
    } catch (error) {
      if (error.name === 'AbortError') return;
      const state = document.getElementById('exportPreviewState');
      state.className = 'export-preview-state error';
      state.innerHTML = `<b>!</b>${escapeHtml(error.message)}`;
      state.hidden = false;
      document.getElementById('exportPreviewTableWrap').hidden = true;
      document.getElementById('downloadExportCsv').disabled = true;
    }
  }

  function openExportModal() {
    if (!exportModal) return;
    initializeExportPeriod();
    exportModal.inert = false;
    exportModal.classList.add('show');
    exportModal.setAttribute('aria-hidden', 'false');
    setPageLock(true);
    loadExportPreview();
  }

  function closeExportModal() {
    if (!exportModal) return;
    if (exportPreviewController) exportPreviewController.abort();
    exportModal.classList.remove('show');
    exportModal.setAttribute('aria-hidden', 'true');
    exportModal.inert = true;
    setPageLock(recordModal.classList.contains('open') || drawer.classList.contains('open') || bookingDrawer?.classList.contains('open') || receiptModal?.classList.contains('show'));
  }

  function downloadExportCsv() {
    try {
      const request = buildExportParameters('download');
      window.location.href = `${exportConfig.endpoint}?${request.parameters}`;
    } catch (error) {
      paymentMessage('warning', 'Select a Valid Period', error.message);
    }
  }

  async function cancelPayMongoCollection(domain, bookingId, returnToken) {
    const data = new FormData();
    data.append('action', 'cancel_pending');
    data.append('type', 'tour');
    data.append('id', String(bookingId));
    data.append('return_token', String(returnToken));
    data.append('csrf_token', collectionConfig.csrf || '');
    const response = await fetch(collectionConfig.checkoutEndpoint, {
      method: 'POST',
      body: data,
      credentials: 'same-origin',
      headers: { Accept: 'application/json' }
    });
    const result = await readPaymentJson(response, 'The pending QR payment could not be cancelled.');
    if (!response.ok || !result.success) throw new Error(result.message || 'The pending QR payment could not be cancelled.');
    return Boolean(result.cancelled);
  }

  async function monitorPayMongoCollection(token, domain, bookingId) {
    if (!/^[a-f0-9]{64}$/.test(token)) return;
    let cancelRequested = false;
    if (window.Swal) {
      Swal.fire({
        title: 'QR Sent to Admin Phone',
        text: 'Waiting for PayMongo to verify the tourist payment. The balance will update automatically.',
        allowOutsideClick: false,
        allowEscapeKey: false,
        showCancelButton: true,
        showConfirmButton: false,
        cancelButtonText: 'Cancel Payment',
        cancelButtonColor: '#b5444f',
        didOpen: () => Swal.showLoading()
      }).then(result => {
        if (result.dismiss === Swal.DismissReason.cancel) cancelRequested = true;
      });
    }
    for (let attempt = 0; attempt < 120; attempt += 1) {
      if (cancelRequested) {
        try {
          if (window.Swal) {
            Swal.fire({
              title: 'Cancelling Payment',
              text: 'Closing the pending PayMongo checkout…',
              allowOutsideClick: false,
              allowEscapeKey: false,
              showConfirmButton: false,
              didOpen: () => Swal.showLoading()
            });
          }
          const cancelled = await cancelPayMongoCollection(domain, bookingId, token);
          if (window.Swal) Swal.close();
          await paymentMessage(
            cancelled ? 'info' : 'warning',
            cancelled ? 'Payment Cancelled' : 'Nothing to Cancel',
            cancelled
              ? 'The pending PayMongo payment was cancelled and logged in transactions. No amount was applied to the booking.'
              : 'No active pending payment was found. Refresh the page to confirm the latest payment status.'
          );
          if (cancelled) window.location.reload();
        } catch (error) {
          if (window.Swal) Swal.close();
          await paymentMessage('warning', 'Cancellation Not Confirmed', error.message || 'The payment could not be cancelled. Check its latest status before collecting again.');
        }
        return;
      }
      try {
        const separator = String(collectionConfig.statusEndpoint).includes('?') ? '&' : '?';
        const response = await fetch(`${collectionConfig.statusEndpoint}${separator}token=${encodeURIComponent(token)}`, { cache: 'no-store', headers: { Accept: 'application/json' } });
        const result = await readPaymentJson(response, 'Payment verification returned an invalid response.');
        if (!response.ok || !result.success) throw new Error(result.message || 'Payment status could not be checked.');
        if (result.status === 'paid') {
          if (window.Swal) Swal.close();
          await paymentMessage('success', 'QR Payment Verified', `${result.amount ? `₱${Number(result.amount).toLocaleString('en-PH', {minimumFractionDigits: 2})}` : 'The payment'} was applied to ${result.booking_reference || `Booking #${result.booking_id}`}.`);
          window.location.reload();
          return;
        }
        if (result.status === 'cancelled') {
          if (window.Swal) Swal.close();
          await paymentMessage('info', 'Payment Cancelled', 'The pending PayMongo payment was cancelled and logged in transactions. No amount was applied to the booking.');
          window.location.reload();
          return;
        }
        if (['failed', 'expired'].includes(result.status)) {
          if (window.Swal) Swal.close();
          await paymentMessage('warning', 'Payment Not Completed', 'PayMongo did not verify the payment, so the booking balance was not changed.');
          return;
        }
      } catch (_) {
        // Brief provider or network interruptions should not stop verification.
      }
      await new Promise(resolve => window.setTimeout(resolve, 2500));
    }
    if (window.Swal) Swal.close();
    await paymentMessage('info', 'Confirmation Pending', 'The QR remains active. Refresh this page shortly to check the transaction status.');
  }

  async function startPayMongoCollection() {
    const domain = paymentDomain.value;
    const bookingId = paymentBookingId.value;
    const amount = Number(paymentAmount.value || 0);
    const balance = Number(selection.options[selection.selectedIndex]?.dataset.balance || 0);
    if (!bookingId || amount <= 0 || amount > balance + 0.009) {
      await paymentMessage('warning', 'Invalid Payment', 'Choose a booking and enter an amount that does not exceed its balance.');
      return;
    }
    if (!(await refreshCollectionPhone())) {
      await paymentMessage('warning', 'Payment Phone Required', 'Register an Administrator payment phone before using QR Code (PayMongo).');
      return;
    }

    const button = document.getElementById('savePaymentButton');
    const originalText = button.textContent;
    button.disabled = true;
    button.textContent = 'Opening PayMongo…';
    const data = new FormData();
    data.append('type', 'tour');
    data.append('id', bookingId);
    data.append('amount', amount.toFixed(2));
    data.append('csrf_token', collectionConfig.csrf || '');
    try {
      const response = await fetch(collectionConfig.checkoutEndpoint, { method: 'POST', body: data, credentials: 'same-origin', headers: { Accept: 'application/json' } });
      const result = await readPaymentJson(response, 'The PayMongo payment service returned an invalid response.');
      if (!response.ok || !result.success) throw new Error(result.message || 'The PayMongo QR payment could not be prepared.');
      const token = String(result.return_token || '');
      closeRecordModal();
      if (result.phone_notification?.sent && /^[a-f0-9]{64}$/.test(token)) {
        await monitorPayMongoCollection(token, domain, bookingId);
        return;
      }
      const checkoutUrl = new URL(result.checkout_url || '');
      const validHost = checkoutUrl.hostname === 'checkout.paymongo.com' || checkoutUrl.hostname.endsWith('.paymongo.com');
      if (checkoutUrl.protocol !== 'https:' || !validHost) throw new Error('PayMongo returned an invalid checkout URL.');
      const choice = window.Swal
        ? await Swal.fire({ icon: 'warning', title: 'Phone Notification Not Sent', text: result.phone_notification?.message || 'Open the secure QR checkout on this computer instead?', showCancelButton: true, confirmButtonText: 'Open PayMongo', confirmButtonColor: '#2b7a66' })
        : { isConfirmed: window.confirm('The phone notification was not sent. Open PayMongo on this computer?') };
      if (choice.isConfirmed) window.location.assign(checkoutUrl.href);
    } catch (error) {
      await paymentMessage('error', 'QR Payment Failed', error.message);
    } finally {
      button.disabled = false;
      button.textContent = originalText;
    }
  }
  function escapeHtml(value) {
    return String(value == null ? '' : value).replace(/[&<>'"]/g, character => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[character]));
  }
  function detail(label, value) {
    return `<div class="drawer-detail"><span>${escapeHtml(label)}</span><strong>${escapeHtml(value || '—')}</strong></div>`;
  }
  function bookingMoney(value) {
    return `₱${Number(value || 0).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
  }
  function bookingDate(value) {
    if (!value) return 'Not set';
    const date = new Date(`${String(value).slice(0, 10)}T00:00:00`);
    return Number.isNaN(date.getTime()) ? String(value) : date.toLocaleDateString('en-PH', { year: 'numeric', month: 'long', day: 'numeric' });
  }
  function bookingLabel(value) {
    const text = String(value || '').trim();
    if (!text) return 'Not specified';
    return text.replace(/[_-]+/g, ' ').replace(/\b\w/g, letter => letter.toUpperCase());
  }
  function preferredBookingResource(rawValue) {
    const raw = String(rawValue || '').trim();
    if (!raw) return '';
    try {
      const parsed = JSON.parse(raw);
      return String(parsed.preferred || parsed.resource || parsed.name || '').trim();
    } catch (_) {
      return raw;
    }
  }
  function bookingDetailItem(label, value) {
    return `<div class="booking-detail-item"><span>${escapeHtml(label)}</span><strong>${escapeHtml(value || 'Not specified')}</strong></div>`;
  }
  function bookingDetailLoading() {
    return '<div class="booking-detail-loading"><span></span><p>Loading the latest booking record&hellip;</p></div>';
  }
  function closeBookingDrawer() {
    bookingDetailController?.abort();
    bookingDetailController = null;
    activeBookingCollectionKey = '';
    if (bookingDetailCollectButton) bookingDetailCollectButton.disabled = true;
    bookingDrawer?.classList.remove('open');
    bookingDrawer?.setAttribute('aria-hidden', 'true');
    setPageLock(recordModal.classList.contains('open') || drawer.classList.contains('open') || receiptModal?.classList.contains('show'));
  }
  async function openBookingDrawer(bookingId) {
    if (!bookingDrawer || !bookingDetailBody || !bookingId) return;
    bookingDetailController?.abort();
    bookingDetailController = new AbortController();
    activeBookingCollectionKey = '';
    if (bookingDetailCollectButton) bookingDetailCollectButton.disabled = true;
    bookingDetailBody.innerHTML = bookingDetailLoading();
    bookingDrawer.classList.add('open');
    bookingDrawer.setAttribute('aria-hidden', 'false');
    setPageLock(true);

    try {
      const response = await fetch(`adbookings.php?action=fetchBookingDetails&id=${encodeURIComponent(bookingId)}`, {
        headers: { Accept: 'application/json' },
        signal: bookingDetailController.signal
      });
      const payload = await response.json();
      if (!response.ok || !payload.success || !payload.booking) throw new Error(payload.message || 'Booking details could not be loaded.');

      const booking = payload.booking;
      const domain = String(booking.booking_type || '').toLowerCase();
      if (!['package', 'boat', 'tourguide'].includes(domain)) throw new Error('This booking belongs to a different administration panel.');
      const expenses = Array.isArray(payload.expenses) ? payload.expenses : [];
      const expenseTotal = expenses.reduce((sum, expense) => sum + Number(expense.amount || 0), 0);
      const total = Number(booking.grand_total || 0);
      const paid = Number(booking.payment_amount || 0);
      const balance = Math.max(0, Number(booking.remaining_balance || 0));
      const serviceAmount = Math.max(0, total - expenseTotal);
      const guestName = booking.t_full_name || 'Guest';
      const initials = String(guestName).split(/\s+/).filter(Boolean).slice(0, 2).map(part => part[0]).join('').toUpperCase() || 'G';
      const profilePicture = String(booking.t_profile_picture || '').trim();
      const hasProfilePicture = profilePicture !== '' && !/(^|\/)profileicon2?\.png(?:$|[?#])/i.test(profilePicture);
      const profileImageEndpoint = `adbookings.php?action=fetchTouristProfileImage&id=${encodeURIComponent(booking.booking_id || bookingId)}`;
      const guestAvatar = hasProfilePicture
        ? `${escapeHtml(initials)}<img src="${escapeHtml(profilePicture)}" data-fallback="${escapeHtml(profileImageEndpoint)}" alt="" onerror="const fallback=this.dataset.fallback;if(fallback&&this.src!==new URL(fallback,document.baseURI).href){this.dataset.fallback='';this.src=fallback}else{this.remove()}">`
        : escapeHtml(initials);
      const resource = domain === 'package'
        ? booking.package_name
        : domain === 'boat'
          ? (booking.boat_name || preferredBookingResource(booking.preferred_resource))
          : (booking.guide_name || preferredBookingResource(booking.preferred_resource));
      const service = resource || booking.location || 'Tour service';
      const bookingStatus = String(booking.is_complete || '').toLowerCase() === 'completed' ? 'Completed' : bookingLabel(booking.status || 'Pending');
      const statusClass = /cancel|declin|fail/i.test(bookingStatus) ? 'danger' : /complete|accept|confirm/i.test(bookingStatus) ? 'success' : 'pending';
      const paymentStatus = balance <= 0 ? 'Paid' : paid > 0 ? 'Partially paid' : 'Unpaid';
      const totalGuests = Number(booking.pax || 0) || (Number(booking.num_adults || 0) + Number(booking.num_children || 0));
      const schedule = String(booking.tour_type || '').toLowerCase() === 'overnight' && String(booking.tour_range || '').includes(' to ')
        ? String(booking.tour_range).split(' to ').map(bookingDate).join(' – ')
        : bookingDate(booking.booking_date);
      const expenseRows = expenses.length
        ? expenses.map(expense => `<div><span>${escapeHtml(expense.expense_type || 'Additional expense')}</span><strong>${escapeHtml(bookingMoney(expense.amount))}</strong></div>`).join('')
        : '<div class="booking-expense-empty"><span>No additional expenses</span><strong>₱0.00</strong></div>';

      activeBookingCollectionKey = `${domain}:${Number(booking.booking_id || bookingId)}`;
      if (bookingDetailCollectButton) bookingDetailCollectButton.disabled = balance <= 0;
      bookingDetailBody.innerHTML = `
        <div class="booking-detail-hero">
          <div><span>BOOKING REFERENCE</span><strong>${escapeHtml(booking.booking_reference || `Booking #${bookingId}`)}</strong><small>${escapeHtml(bookingLabel(domain))}</small></div>
          <span class="status-badge ${statusClass}"><i></i>${escapeHtml(bookingStatus)}</span>
        </div>
        <section class="booking-guest-card">
          <span class="booking-guest-avatar" aria-hidden="true">${guestAvatar}</span>
          <div><small>PRIMARY TOURIST</small><h4>${escapeHtml(guestName)}</h4><p>${escapeHtml(booking.t_email || 'No email recorded')}</p></div>
        </section>
        <section class="booking-detail-section">
          <div class="booking-detail-heading"><span>01</span><div><h4>Tourist information</h4><p>Contact details for this reservation</p></div></div>
          <div class="booking-detail-grid">
            ${bookingDetailItem('Contact number', booking.booking_phone || booking.t_phone)}
            ${bookingDetailItem('Home address', booking.t_address)}
          </div>
        </section>
        <section class="booking-detail-section">
          <div class="booking-detail-heading"><span>02</span><div><h4>Trip information</h4><p>Booked service, schedule, and guests</p></div></div>
          <div class="booking-detail-grid">
            ${bookingDetailItem('Selected service', service)}
            ${bookingDetailItem('Destination', booking.location || booking.package_name)}
            ${bookingDetailItem('Tour schedule', schedule)}
            ${bookingDetailItem('Trip duration', bookingLabel(booking.tour_type || 'Day tour'))}
            ${bookingDetailItem('Jump-off port', booking.jump_off_port)}
            ${bookingDetailItem('Guest count', `${totalGuests} total · ${Number(booking.num_adults || 0)} adult(s), ${Number(booking.num_children || 0)} child(ren)`)}
          </div>
        </section>
        <section class="booking-detail-section booking-payment-section">
          <div class="booking-detail-heading"><span>03</span><div><h4>Payment & expenses</h4><p>Live financial summary from the booking record</p></div></div>
          <div class="booking-expense-lines">
            <div><span>Service amount</span><strong>${escapeHtml(bookingMoney(serviceAmount))}</strong></div>
            ${expenseRows}
            <div class="expense-total"><span>Additional expenses</span><strong>${escapeHtml(bookingMoney(expenseTotal))}</strong></div>
            <div class="booking-grand-total"><span>Booking total</span><strong>${escapeHtml(bookingMoney(total))}</strong></div>
          </div>
          <div class="booking-payment-grid">
            <div><span>Amount received</span><strong>${escapeHtml(bookingMoney(paid))}</strong></div>
            <div class="balance"><span>Balance due</span><strong>${escapeHtml(bookingMoney(balance))}</strong></div>
            <div><span>Payment status</span><strong>${escapeHtml(paymentStatus)}</strong></div>
            <div><span>Payment method</span><strong>${escapeHtml(bookingLabel(booking.payment_method))}</strong></div>
          </div>
        </section>
        <div class="booking-detail-note"><span>✓</span><p><strong>Live booking record</strong>Details, expenses, and balances are loaded from the same record used on Booking Management.</p></div>`;
    } catch (error) {
      if (error.name === 'AbortError') return;
      bookingDetailBody.innerHTML = `<div class="booking-detail-error"><span>!</span><h4>Unable to load booking details</h4><p>${escapeHtml(error.message)}</p><button type="button" data-retry-booking="${Number(bookingId)}">Try again</button></div>`;
      bookingDetailBody.querySelector('[data-retry-booking]')?.addEventListener('click', () => openBookingDrawer(bookingId));
    }
  }
  function canIssueReceipt(transaction) {
    return ['paid', 'succeeded', 'completed'].includes(String(transaction?.status || '').toLowerCase());
  }
  function openDrawer(data) {
    activeTransaction = data;
    const receiptButton = document.getElementById('openTransactionReceipt');
    if (receiptButton) receiptButton.hidden = !canIssueReceipt(data);
    document.getElementById('drawerBody').innerHTML = `
      <div class="drawer-summary"><div class="drawer-summary-top"><small>${escapeHtml(data.domain)} payment</small><span class="status-badge ${escapeHtml(data.status_class)}"><i></i>${escapeHtml(data.status)}</span></div><span>Amount</span><strong>${escapeHtml(data.amount)}</strong></div>
      <section class="drawer-section"><h4>Customer & booking</h4>${detail('Customer', data.customer)}${detail('Email', data.email)}${detail('Booking reference', data.booking_reference)}${detail('Service', data.service)}${detail('Booking total', data.booking_total)}${detail('Paid to date', data.booking_paid)}${detail('Balance remaining', data.booking_balance)}</section>
      <section class="drawer-section"><h4>Payment information</h4>${detail('Transaction reference', data.reference)}${detail('Channel', data.provider)}${detail('Collected through', data.collection_source)}${detail('Payment method', data.method)}${detail('Created', data.created)}${detail('Settled', data.paid_at)}${detail('Provider payment ID', data.provider_payment_id)}</section>
      ${data.failure_message ? `<div class="drawer-error"><strong>Payment note</strong><br>${escapeHtml(data.failure_message)}</div>` : ''}`;
    drawer.classList.add('open');
    drawer.setAttribute('aria-hidden', 'false');
    setPageLock(true);
  }
  function closeDrawer() {
    drawer.classList.remove('open');
    drawer.setAttribute('aria-hidden', 'true');
    setPageLock(false);
  }

  function setReceiptModal(open) {
    if (!receiptModal) return;
    receiptModal.inert = !open;
    receiptModal.classList.toggle('show', open);
    receiptModal.setAttribute('aria-hidden', open ? 'false' : 'true');
    setPageLock(open || drawer.classList.contains('open') || bookingDrawer?.classList.contains('open'));
  }

  function openTransactionReceipt() {
    if (!activeTransaction || !receiptPaper || !canIssueReceipt(activeTransaction)) return;
    const transaction = activeTransaction;
    const logoUrl = new URL('img/newlogo.png', window.location.href).href;
    const wordmarkUrl = new URL('img/textlogo2.png', window.location.href).href;
    receiptPaper.innerHTML = `
      <div class="receipt-brand">
        <div class="receipt-brand-identity"><img class="receipt-brand-logo" src="${logoUrl}" alt="iTour Mercedes seal"><div><img class="receipt-brand-wordmark" src="${wordmarkUrl}" alt="iTour Mercedes"><p>OFFICIAL PAYMENT RECEIPT</p></div></div>
        <div class="receipt-number"><small>RECEIPT REFERENCE</small><strong>${escapeHtml(transaction.reference)}</strong><span class="receipt-paid-stamp">PAID</span></div>
      </div>
      <div class="receipt-meta">
        <div><small>PAID BY</small><strong>${escapeHtml(transaction.customer || 'Guest')}</strong></div>
        <div><small>SERVICE</small><strong>${escapeHtml(transaction.service)}</strong></div>
        <div><small>BOOKING REFERENCE</small><strong>${escapeHtml(transaction.booking_reference)}</strong></div>
        <div><small>BOOKING TYPE</small><strong>${escapeHtml(transaction.domain)}</strong></div>
      </div>
      <table class="receipt-table">
        <thead><tr><th>DESCRIPTION</th><th>AMOUNT</th></tr></thead>
        <tbody>
          <tr><td>Payment received for ${escapeHtml(transaction.service || transaction.domain)}</td><td>${escapeHtml(transaction.amount)}</td></tr>
          <tr class="receipt-total"><td>TOTAL PAYMENT</td><td>${escapeHtml(transaction.amount)}</td></tr>
        </tbody>
      </table>
      <div class="receipt-meta receipt-payment-meta">
        <div><small>PAYMENT DATE</small><strong>${escapeHtml(transaction.paid_at === '—' ? transaction.created : transaction.paid_at)}</strong></div>
        <div><small>PAYMENT METHOD</small><strong>${escapeHtml(transaction.method)}</strong></div>
        <div><small>PAYMENT CHANNEL</small><strong>${escapeHtml(transaction.provider)}</strong></div>
        <div><small>COLLECTED THROUGH</small><strong>${escapeHtml(transaction.collection_source)}</strong></div>
      </div>
      <div class="receipt-foot">This receipt confirms only the payment transaction shown above. It is not a current booking balance statement. Please retain it as your official payment record.</div>`;
    setReceiptModal(true);
  }

  function printTransactionReceipt() {
    if (!receiptPaper?.innerHTML.trim()) return;
    const printWindow = window.open('', '_blank', 'width=900,height=720');
    if (!printWindow) {
      window.alert('Allow pop-ups for this site, then try printing the receipt again.');
      return;
    }
    const stylesheet = Array.from(document.styleSheets).find(sheet => String(sheet.href || '').includes('styles/admin_receipt.css'))?.href
      || new URL('styles/admin_receipt.css', window.location.href).href;
    printWindow.document.write(`<!doctype html><html><head><title>Payment Receipt</title><link rel="stylesheet" href="${stylesheet}"><style>body{margin:0;padding:20px;background:#fff}.admin-receipt-paper{width:560px;min-height:0;margin:auto;box-shadow:none}@page{size:A4 portrait;margin:12mm}@media print{body{padding:0}.admin-receipt-paper{width:100%;max-width:560px}}</style></head><body><article class="admin-receipt-paper">${receiptPaper.innerHTML}</article></body></html>`);
    printWindow.document.close();
    printWindow.addEventListener('load', () => setTimeout(() => {
      printWindow.focus();
      printWindow.print();
    }, 200));
  }

  async function downloadTransactionReceipt() {
    const button = document.getElementById('downloadTransactionReceipt');
    if (!receiptPaper?.innerHTML.trim() || !window.html2canvas || !window.jspdf?.jsPDF) {
      window.alert('The PDF tools did not load. Refresh the page and try again.');
      return;
    }
    button.disabled = true;
    const originalText = button.textContent;
    button.textContent = 'Preparing…';
    try {
      const canvas = await html2canvas(receiptPaper, { scale: 2, backgroundColor: '#ffffff', useCORS: true });
      const { jsPDF } = window.jspdf;
      const pdf = new jsPDF({ orientation: 'portrait', unit: 'mm', format: 'a4' });
      const maxWidth = 190;
      const maxHeight = 277;
      const scale = Math.min(maxWidth / canvas.width, maxHeight / canvas.height);
      const width = canvas.width * scale;
      const height = canvas.height * scale;
      pdf.addImage(canvas.toDataURL('image/png'), 'PNG', (210 - width) / 2, 10, width, height, undefined, 'FAST');
      const reference = String(activeTransaction?.reference || 'payment').replace(/[^a-z0-9_-]+/gi, '-');
      pdf.save(`payment-receipt-${reference}.pdf`);
    } catch (error) {
      window.alert('The payment receipt could not be prepared. Please try again.');
    } finally {
      button.disabled = false;
      button.textContent = originalText;
    }
  }

  document.querySelectorAll('[data-open-payment]').forEach(button => button.addEventListener('click', () => openRecordModal('')));
  document.querySelectorAll('[data-collect]').forEach(button => button.addEventListener('click', () => {
    const booking = JSON.parse(button.dataset.collect);
    openRecordModal(`${booking.booking_domain}:${booking.booking_id}`);
  }));
  document.querySelectorAll('[data-view-booking]').forEach(button => button.addEventListener('click', () => openBookingDrawer(Number(button.dataset.viewBooking))));
  document.querySelectorAll('[data-close-booking-drawer]').forEach(button => button.addEventListener('click', closeBookingDrawer));
  bookingDetailCollectButton?.addEventListener('click', () => {
    if (!activeBookingCollectionKey) return;
    const collectionKey = activeBookingCollectionKey;
    closeBookingDrawer();
    openRecordModal(collectionKey);
  });
  document.querySelectorAll('[data-close-modal]').forEach(button => button.addEventListener('click', closeRecordModal));
  document.querySelectorAll('[data-transaction]').forEach(button => button.addEventListener('click', () => openDrawer(JSON.parse(button.dataset.transaction))));
  document.querySelectorAll('[data-close-drawer]').forEach(button => button.addEventListener('click', closeDrawer));
  document.querySelectorAll('[data-refund-details]').forEach(button => button.addEventListener('click', () => {
    closeRefundMenus();
    openRefundDrawer(JSON.parse(button.dataset.refundDetails));
  }));
  document.querySelectorAll('[data-close-refund-drawer]').forEach(button => button.addEventListener('click', closeRefundDrawer));
  document.querySelectorAll('.refund-action-menu').forEach(button => button.addEventListener('click', event => {
    event.stopPropagation();
    const menu = button.nextElementSibling;
    const willOpen = Boolean(menu?.hidden);
    closeRefundMenus(menu);
    if (menu) menu.hidden = !willOpen;
    button.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
  }));
  document.addEventListener('click', event => {
    if (!event.target.closest('.refund-actions')) closeRefundMenus();
  });
  document.querySelectorAll('[data-process-refund]').forEach(form => form.addEventListener('submit', async event => {
    event.preventDefault();
    closeRefundMenus();
    if (!window.Swal) { if (window.confirm('Send this refund to the original payment method?')) form.submit(); return; }
    const result = await Swal.fire({
      icon: 'warning', title: 'Process this refund?',
      html: '<p style="line-height:1.55">PayMongo will return only the approved eligible amount to the original payment method. If the booking has multiple payments, each Payment ID is processed separately.</p>',
      showCancelButton: true, confirmButtonText: 'Yes, process refund', cancelButtonText: 'Cancel',
      confirmButtonColor: '#176b55', cancelButtonColor: '#687b75', focusCancel: true
    });
    if (!result.isConfirmed) return;
    Swal.fire({ title: 'Submitting Refund', text: 'Securely sending the request to PayMongo and preparing the tourist email...', allowOutsideClick: false, allowEscapeKey: false, showConfirmButton: false, didOpen: () => Swal.showLoading() });
    form.submit();
  }));
  document.querySelectorAll('[data-process-manual-refund]').forEach(button => button.addEventListener('click', async () => {
    closeRefundMenus();
    if (!window.Swal) return showFeedback('error', 'Manual Refund', 'SweetAlert is required to securely record this refund.');
    Swal.fire({title:'Loading Refund Details',text:'Opening the verified refund destination...',allowOutsideClick:false,allowEscapeKey:false,showConfirmButton:false,didOpen:()=>Swal.showLoading()});
    try {
      const query = new URLSearchParams({manual_refund_details:button.dataset.requestId || '',csrf_token:refundProcessingConfig.csrf || ''});
      const response = await fetch(`${refundProcessingConfig.detailsEndpoint || 'adpaymenttransactions.php'}?${query}`, {headers:{'Accept':'application/json'}});
      const payload = await response.json();
      if (!response.ok || !payload.success) throw new Error(payload.message || 'The refund details could not be loaded.');
      const refund = payload.refund;
      const result = await Swal.fire({
        icon:'warning',
        title:'Process Manual Refund',
        width:620,
        html:`<div style="text-align:left;color:#37544a;font-size:13px;line-height:1.5">
          <div style="padding:15px;border-radius:11px;background:#edf7f3;border:1px solid #cfe5dc;margin-bottom:14px"><small style="display:block;color:#678078;font-weight:700">REQUIRED REFUND</small><strong style="display:block;color:#0c654b;font-size:25px">&#8369;${escapeHtml(refund.amount)}</strong><span>${escapeHtml(refund.booking_reference)} &middot; ${escapeHtml(refund.tourist)}</span></div>
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:9px;margin-bottom:14px"><div style="padding:10px;border:1px solid #dce7e2;border-radius:8px"><small>Original payment</small><strong style="display:block">${escapeHtml(refund.original_channel)}</strong></div><div style="padding:10px;border:1px solid #dce7e2;border-radius:8px"><small>Original reference</small><strong style="display:block;word-break:break-all">${escapeHtml(refund.original_payment_reference || 'Not recorded')}</strong></div></div>
          <div style="padding:13px;border:1px solid #dce7e2;border-radius:9px;margin-bottom:14px"><small style="font-weight:700;color:#60766d">SEND TO VERIFIED TOURIST ACCOUNT</small><strong style="display:block;margin-top:5px">${escapeHtml(refund.destination_institution)} &middot; ${escapeHtml(refund.destination_account_name)}</strong><span style="display:block;margin-top:3px;font-family:monospace;font-size:14px">${escapeHtml(refund.destination_account_number)}</span></div>
          <label style="display:block;font-weight:700;margin:8px 0 4px" for="manualRefundChannel">Channel used to send refund</label><select id="manualRefundChannel" class="swal2-select" style="display:block;width:100%;margin:0 0 9px"><option value="">Select channel</option><option>GCash</option><option>Maya</option><option>QR Ph</option><option>Bank Transfer</option><option>Cash</option><option>Other</option></select>
          <label style="display:block;font-weight:700;margin:8px 0 4px" for="manualSenderAccount">Admin account used</label><input id="manualSenderAccount" class="swal2-input" style="display:block;width:100%;margin:0 0 9px" maxlength="150" placeholder="Example: iTour Mercedes GCash ending 1234">
          <label style="display:block;font-weight:700;margin:8px 0 4px" for="manualProviderReference">Transfer/reference number</label><input id="manualProviderReference" class="swal2-input" style="display:block;width:100%;margin:0 0 9px" maxlength="100" placeholder="Reference from bank or e-wallet receipt">
          <label style="display:block;font-weight:700;margin:8px 0 4px" for="manualRefundNote">Internal note (optional)</label><textarea id="manualRefundNote" class="swal2-textarea" style="display:block;width:100%;margin:0 0 10px" maxlength="500" placeholder="Additional reconciliation details"></textarea>
          <label style="display:flex;gap:9px;align-items:flex-start;padding:11px;border-radius:8px;background:#fff8e8;border:1px solid #f0d5a3"><input id="manualFundsSent" type="checkbox" style="margin-top:3px"><span><strong style="display:block;color:#6b4711">I confirm the funds were sent</strong>This permanently records the refund as completed and enables cancellation completion.</span></label>
        </div>`,
        showCancelButton:true,
        confirmButtonText:'Record Completed Refund',
        cancelButtonText:'Cancel',
        confirmButtonColor:'#176b55',
        cancelButtonColor:'#687b75',
        focusCancel:true,
        preConfirm:()=>{
          const values = {
            channel:document.getElementById('manualRefundChannel')?.value.trim() || '',
            sender:document.getElementById('manualSenderAccount')?.value.trim() || '',
            reference:document.getElementById('manualProviderReference')?.value.trim() || '',
            note:document.getElementById('manualRefundNote')?.value.trim() || '',
            confirmed:Boolean(document.getElementById('manualFundsSent')?.checked)
          };
          if (!values.channel || !values.sender || !values.reference) return Swal.showValidationMessage('Complete the sending channel, admin account, and transfer reference.');
          if (!values.confirmed) return Swal.showValidationMessage('Confirm that the refund funds were actually sent.');
          return values;
        }
      });
      if (!result.isConfirmed) return;
      const form = document.createElement('form');
      form.method = 'post';
      const fields = {action:'process_manual_refund',csrf_token:refundProcessingConfig.csrf || '',cancellation_request_id:button.dataset.requestId || '',manual_refund_channel:result.value.channel,manual_sender_account:result.value.sender,manual_provider_reference:result.value.reference,manual_refund_note:result.value.note,funds_sent_confirmation:'yes'};
      Object.entries(fields).forEach(([name,value])=>{const input=document.createElement('input');input.type='hidden';input.name=name;input.value=value;form.appendChild(input);});
      document.body.appendChild(form);
      Swal.fire({title:'Recording Manual Refund',text:'Saving the transfer audit record and notifying the tourist...',allowOutsideClick:false,allowEscapeKey:false,showConfirmButton:false,didOpen:()=>Swal.showLoading()});
      form.submit();
    } catch (error) {
      Swal.fire({icon:'error',title:'Manual Refund Unavailable',text:error.message || 'The refund details could not be loaded.',confirmButtonColor:'#176b55'});
    }
  }));
  document.querySelectorAll('[data-refresh-refund]').forEach(form => form.addEventListener('submit', event => {
    if (!window.Swal) return;
    event.preventDefault();
    closeRefundMenus();
    Swal.fire({ title: 'Checking Refund Status', text: 'Getting the latest update from PayMongo...', allowOutsideClick: false, allowEscapeKey: false, showConfirmButton: false, didOpen: () => Swal.showLoading() });
    form.submit();
  }));
  if (selection) selection.addEventListener('change', updateBookingSelection);
  paymentAmount?.addEventListener('blur', clampCollectionAmountToBalance);
  paymentAmount?.addEventListener('change', clampCollectionAmountToBalance);
  document.getElementById('recordPaymentForm').addEventListener('submit', event => {
    clampCollectionAmountToBalance();
    if (!selection.value) { event.preventDefault(); selection.focus(); return; }
    const balance = Number(selection.options[selection.selectedIndex].dataset.balance || 0);
    if (Number(paymentAmount.value) <= 0 || Number(paymentAmount.value) > balance) { event.preventDefault(); paymentAmount.focus(); return; }
    if (!collectionMethod.value) { event.preventDefault(); collectionMethod.focus(); return; }
    if (collectionMethod.value === 'qr_code') {
      event.preventDefault();
      startPayMongoCollection();
      return;
    }
    const button = document.getElementById('savePaymentButton');
    button.disabled = true; button.textContent = 'Recording cash payment…';
  });
  collectionMethod?.addEventListener('change', () => {
    const phonePanel = document.getElementById('collectionPhoneStatus');
    const isQr = collectionMethod.value === 'qr_code';
    phonePanel.hidden = !isQr;
    document.getElementById('savePaymentButton').textContent = isQr ? 'Open PayMongo QR' : 'Confirm payment';
    if (isQr) refreshCollectionPhone();
  });
  document.getElementById('manageAdminPhoneBtn')?.addEventListener('click', async () => {
    const result = await refreshRegisteredAdminPhone();
    if (!result) {
      await paymentMessage('error', 'Phone Check Failed', 'The registered payment phone could not be checked.');
      return;
    }
    openPhoneRegistrationModal();
  });
  document.getElementById('openAdminPaymentPhoneModal')?.addEventListener('click', async event => {
    const button = event.currentTarget;
    const originalContent = button.innerHTML;
    button.disabled = true;
    button.classList.add('is-loading');
    button.innerHTML = '<span class="admin-toolbar-phone-spinner" aria-hidden="true"></span><span>Checking Phone…</span>';
    try {
      const result = await refreshRegisteredAdminPhone();
      if (!result) throw new Error('The registered payment phone could not be checked.');
      openPhoneOverviewModal();
    } catch (error) {
      await paymentMessage('error', 'Phone Check Failed', error.message);
    } finally {
      button.disabled = false;
      button.classList.remove('is-loading');
      button.innerHTML = originalContent;
    }
  });
  document.getElementById('adminPhoneOverviewAction')?.addEventListener('click', () => {
    closePhoneOverviewModal();
    openPhoneRegistrationModal();
  });
  document.querySelectorAll('[data-close-phone-overview]').forEach(button => button.addEventListener('click', closePhoneOverviewModal));
  document.getElementById('adminPaymentPhoneOverviewModal')?.addEventListener('mousedown', event => {
    if (event.target.id === 'adminPaymentPhoneOverviewModal') closePhoneOverviewModal();
  });
  document.querySelectorAll('[data-close-phone-registration]').forEach(button => button.addEventListener('click', closePhoneRegistrationModal));
  document.getElementById('adminPhoneRegistrationModal')?.addEventListener('mousedown', event => {
    if (event.target.id === 'adminPhoneRegistrationModal') closePhoneRegistrationModal();
  });
  document.getElementById('checkAdminPhoneRegistration')?.addEventListener('click', async event => {
    const button = event.currentTarget;
    const originalText = button.textContent;
    button.disabled = true;
    button.textContent = 'Checking…';
    try { await checkPhoneRegistrationProgress(true); }
    finally { button.disabled = false; button.textContent = originalText; }
  });
  document.getElementById('openAdminPhoneSetupPage')?.addEventListener('click', () => {
    const url = document.getElementById('adminPhoneSetupUrl').value;
    if (url) window.open(url, '_blank', 'noopener');
  });
  document.getElementById('copyAdminPhoneSetupUrl')?.addEventListener('click', async () => {
    const input = document.getElementById('adminPhoneSetupUrl');
    let copied = false;
    try {
      if (navigator.clipboard?.writeText && window.isSecureContext) {
        await navigator.clipboard.writeText(input.value);
        copied = true;
      } else {
        input.select();
        copied = document.execCommand('copy');
      }
    } catch (_) {
      copied = false;
    }
    input.blur();
    window.getSelection()?.removeAllRanges();
    showPhoneToast(copied ? 'success' : 'error', copied ? 'Phone setup link copied.' : 'The link could not be copied.');
  });
  window.addEventListener('storage', event => {
    if (event.key === phoneChangeStorageKey) refreshRegisteredAdminPhone(true);
  });
  document.getElementById('exportButton').addEventListener('click', openExportModal);
  document.querySelectorAll('[data-close-export]').forEach(button => button.addEventListener('click', closeExportModal));
  document.getElementById('exportPeriod')?.addEventListener('change', event => {
    syncExportPeriodControls();
    if (event.target.value === 'custom') {
      const now = new Date();
      const from = document.getElementById('exportDateFrom');
      const to = document.getElementById('exportDateTo');
      if (!from.value) from.value = exportDateValue(new Date(now.getFullYear(), now.getMonth(), 1));
      if (!to.value) to.value = exportDateValue(now);
    }
    loadExportPreview();
  });
  document.getElementById('refreshExportPreview')?.addEventListener('click', loadExportPreview);
  document.getElementById('exportDateFrom')?.addEventListener('change', () => {
    if (document.getElementById('exportDateTo')?.value) loadExportPreview();
  });
  document.getElementById('exportDateTo')?.addEventListener('change', () => {
    if (document.getElementById('exportDateFrom')?.value) loadExportPreview();
  });
  document.getElementById('downloadExportCsv')?.addEventListener('click', downloadExportCsv);
  document.getElementById('openTransactionReceipt')?.addEventListener('click', openTransactionReceipt);
  document.getElementById('printTransactionReceipt')?.addEventListener('click', printTransactionReceipt);
  document.getElementById('downloadTransactionReceipt')?.addEventListener('click', downloadTransactionReceipt);
  document.querySelectorAll('[data-close-transaction-receipt]').forEach(button => button.addEventListener('click', () => setReceiptModal(false)));
  receiptModal?.addEventListener('mousedown', event => {
    if (event.target === receiptModal) setReceiptModal(false);
  });
  const transactionPanel = document.querySelector('.transaction-panel');
  const billingPanel = document.getElementById('billingQueue');
  if (transactionPanel && billingPanel) {
    const heading = transactionPanel.querySelector('.transaction-heading');
    const transactionNodes = [
      transactionPanel.querySelector('.transaction-filters'),
      transactionPanel.querySelector('.transaction-table-wrap'),
      transactionPanel.querySelector('.table-footer')
    ].filter(Boolean);
    const transactionBody = document.createElement('div');
    transactionBody.className = 'admin-ledger-transactions';
    transactionNodes.forEach(node => transactionBody.appendChild(node));
    const receivableBody = document.createElement('div');
    receivableBody.className = 'admin-ledger-receivables';
    receivableBody.hidden = true;
    const billingList = billingPanel.querySelector('.billing-list');
    if (billingList) receivableBody.appendChild(billingList);
    const refundBody = transactionPanel.querySelector('.admin-ledger-refunds');
    if (refundBody) refundBody.hidden = true;
    transactionPanel.append(transactionBody, receivableBody);
    billingPanel.remove();
    const tabs = document.createElement('div');
    tabs.className = 'admin-ledger-tabs';
    tabs.setAttribute('role', 'tablist');
    const receivableCount = Number(billingPanel.dataset.receivableCount || 0);
    tabs.innerHTML = `<button type="button" class="active" role="tab" aria-selected="true" data-admin-ledger-tab="transactions">Transactions <b>${Number(document.querySelector('.transaction-results strong')?.textContent.replace(/,/g,'') || 0).toLocaleString()}</b></button><button type="button" role="tab" aria-selected="false" data-admin-ledger-tab="receivables">Receivables <b>${receivableCount.toLocaleString()}</b></button><button type="button" role="tab" aria-selected="false" data-admin-ledger-tab="refunds">Refunds <b>${document.querySelectorAll('.refund-table tbody tr[id^="refund-request-"]').length}</b></button>`;
    heading?.appendChild(tabs);
    const setLedgerTab = name => {
      const receivablesActive = name === 'receivables';
      const refundsActive = name === 'refunds';
      transactionBody.hidden = receivablesActive || refundsActive;
      receivableBody.hidden = !receivablesActive;
      if (refundBody) refundBody.hidden = !refundsActive;
      tabs.querySelectorAll('button').forEach(button => {
        const active = button.dataset.adminLedgerTab === name;
        button.classList.toggle('active', active);
        button.setAttribute('aria-selected', active ? 'true' : 'false');
      });
      const title = heading?.querySelector('h3');
      if (title) title.textContent = refundsActive ? 'Refund management' : (receivablesActive ? 'Outstanding bookings' : 'All transactions');
    };
    tabs.querySelectorAll('button').forEach(button => button.addEventListener('click', () => setLedgerTab(button.dataset.adminLedgerTab)));
    document.querySelectorAll('.receivables-button').forEach(link => link.addEventListener('click', event => {
      event.preventDefault();
      setLedgerTab('receivables');
      transactionPanel.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }));
    document.querySelectorAll('[data-review-refunds]').forEach(link => link.addEventListener('click', event => {
      event.preventDefault();
      setLedgerTab('refunds');
      const url = new URL(window.location.href);
      url.searchParams.set('view', 'refunds');
      url.hash = 'refundLedger';
      window.history.replaceState({}, '', url);
      window.requestAnimationFrame(() => transactionPanel.scrollIntoView({ behavior: 'smooth', block: 'start' }));
    }));
    const initialLedgerView = document.body.dataset.ledgerView || 'transactions';
    setLedgerTab(initialLedgerView);
    if (initialLedgerView === 'refunds') {
      const focusedRow = document.querySelector('.refund-table tr.refund-focus');
      if (focusedRow) {
        setTimeout(() => focusedRow.scrollIntoView({ behavior: 'smooth', block: 'center' }), 120);
        setTimeout(() => focusedRow.classList.remove('refund-focus'), 2200);
      }
    }
  }
  document.querySelectorAll('.payment-notice button').forEach(button => button.addEventListener('click', () => button.parentElement.remove()));
  document.addEventListener('keydown', event => {
    if (event.key !== 'Escape') return;
    if (exportModal?.classList.contains('show')) { closeExportModal(); return; }
    if (document.getElementById('adminPhoneRegistrationModal')?.classList.contains('show')) { closePhoneRegistrationModal(); return; }
    if (document.getElementById('adminPaymentPhoneOverviewModal')?.classList.contains('show')) { closePhoneOverviewModal(); return; }
    if (receiptModal?.classList.contains('show')) { setReceiptModal(false); return; }
    if (bookingDrawer?.classList.contains('open')) { closeBookingDrawer(); return; }
    if (refundDrawer?.classList.contains('open')) { closeRefundDrawer(); return; }
    if (recordModal.classList.contains('open')) closeRecordModal();
    if (drawer.classList.contains('open')) closeDrawer();
  });
})();
