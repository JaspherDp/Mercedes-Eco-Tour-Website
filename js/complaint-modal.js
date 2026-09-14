(function () {
  'use strict';

  const config = window.ComplaintModalConfig || {};
  const openButtons = Array.from(document.querySelectorAll('#openComplaintModal, [data-open-complaint-modal]'));
  const modal = document.getElementById('complaintIncidentModal');
  if (!openButtons.length || !modal) return;

  const dialog = modal.querySelector('.complaint-modal__dialog');
  const form = document.getElementById('complaintIncidentForm');
  const fileInput = document.getElementById('complaintEvidence');
  const dropzone = modal.querySelector('.complaint-dropzone');
  const fileList = document.getElementById('complaintFileList');
  const status = document.getElementById('complaintFormStatus');
  const validationSummary = document.getElementById('complaintValidationSummary');
  const submitButton = form.querySelector('.complaint-form__submit');
  const incidentAt = form.elements.incident_at;
  let previousFocus = null;
  let selectedFiles = [];

  function ensureSweetAlert() {
    if (window.Swal && typeof window.Swal.fire === 'function') return Promise.resolve(window.Swal);
    if (window.__complaintSweetAlertPromise) return window.__complaintSweetAlertPromise;

    window.__complaintSweetAlertPromise = new Promise(function (resolve, reject) {
      const script = document.createElement('script');
      script.src = 'https://cdn.jsdelivr.net/npm/sweetalert2@11';
      script.async = true;
      script.dataset.complaintSwal = 'true';
      script.onload = function () { resolve(window.Swal); };
      script.onerror = function () { reject(new Error('The confirmation window could not be loaded.')); };
      document.head.appendChild(script);
    });
    return window.__complaintSweetAlertPromise;
  }

  async function showSubmissionSuccess(reference) {
    try {
      await ensureSweetAlert();
      const result = await window.Swal.fire({
        icon: 'success',
        title: 'Report submitted successfully',
        text: 'Your tracking reference is ' + reference + '. You can monitor this report from your tourist profile.',
        showDenyButton: true,
        confirmButtonText: 'Go to complaints',
        denyButtonText: 'Okay',
        confirmButtonColor: '#24775d',
        denyButtonColor: '#6f817b',
        allowOutsideClick: false,
        allowEscapeKey: false,
        customClass: { container: 'complaint-swal-container' }
      });
      if (result.isConfirmed) {
        window.location.href = config.profileUrl || 'php/profile.php?section=complaints';
      }
    } catch (error) {
      const openReports = window.confirm('Report submitted successfully. Your tracking reference is ' + reference + '.\n\nOpen Complaints & Incidents in your profile?');
      if (openReports) window.location.href = config.profileUrl || 'php/profile.php?section=complaints';
    }
  }

  const validationMessages = {
    report_type: 'Select a report type.',
    category: 'Select a report category.',
    subject: 'Enter a clear subject using at least 5 characters.',
    incident_at: 'Enter a valid date and time that is not in the future.',
    location: 'Enter where the event happened.',
    description: 'Describe what happened using at least 30 characters.',
    preferred_contact: 'Choose how you prefer to be contacted.',
    accuracy_consent: 'Confirm that the information provided is accurate.'
  };

  function validationGroup(field) {
    return field.closest('.complaint-field, .complaint-consent');
  }

  function clearFieldError(field) {
    const group = validationGroup(field);
    if (!group) return;
    group.classList.remove('is-invalid');
    field.removeAttribute('aria-invalid');
    const error = group.querySelector('.complaint-field__error');
    if (error) error.remove();
  }

  function fieldErrorMessage(field) {
    return validationMessages[field.name] || field.validationMessage || 'Complete this required field.';
  }

  function markFieldError(field) {
    const group = validationGroup(field);
    if (!group) return;
    clearFieldError(field);
    group.classList.add('is-invalid');
    field.setAttribute('aria-invalid', 'true');

    if (!group.classList.contains('complaint-consent')) {
      const error = document.createElement('small');
      error.className = 'complaint-field__error';
      error.textContent = fieldErrorMessage(field);
      const control = group.querySelector('input, select, textarea');
      control.insertAdjacentElement('afterend', error);
    }
  }

  function renderValidationSummary(invalidFields) {
    validationSummary.innerHTML = '';
    if (!invalidFields.length) {
      validationSummary.hidden = true;
      return;
    }

    const heading = document.createElement('strong');
    heading.textContent = invalidFields.length === 1
      ? 'Please correct 1 field before submitting.'
      : 'Please correct ' + invalidFields.length + ' fields before submitting.';
    const list = document.createElement('ul');
    invalidFields.forEach(function (field) {
      const item = document.createElement('li');
      item.textContent = fieldErrorMessage(field);
      list.appendChild(item);
    });
    validationSummary.append(heading, list);
    validationSummary.hidden = false;
  }

  function validateForm(shouldMoveFocus) {
    const fields = Array.from(form.querySelectorAll('input, select, textarea'));
    const invalidFields = fields.filter(function (field) { return !field.validity.valid; });

    fields.forEach(function (field) {
      if (field.validity.valid) clearFieldError(field);
    });
    invalidFields.forEach(markFieldError);
    renderValidationSummary(invalidFields);

    if (shouldMoveFocus && invalidFields.length) {
      const firstInvalid = invalidFields[0];
      const firstGroup = validationGroup(firstInvalid) || firstInvalid;
      firstGroup.scrollIntoView({ behavior: 'smooth', block: 'center' });
      window.setTimeout(function () {
        firstInvalid.focus({ preventScroll: true });
      }, 280);
    }
    return invalidFields.length === 0;
  }

  function showStatus(message, type) {
    status.textContent = message;
    status.className = 'complaint-form__status is-visible ' + (type === 'success' ? 'is-success' : 'is-error');
    status.setAttribute('role', type === 'success' ? 'status' : 'alert');
  }

  function requestLogin() {
    const loginButton = document.getElementById('openModalBtn');
    if (loginButton) {
      loginButton.click();
      return;
    }
    window.location.href = config.loginUrl || './?open_login=1';
  }

  function openModal() {
    if (!config.loggedIn) {
      requestLogin();
      return;
    }
    previousFocus = document.activeElement;
    modal.hidden = false;
    modal.setAttribute('aria-hidden', 'false');
    document.body.classList.add('complaint-modal-open');
    window.requestAnimationFrame(function () {
      form.elements.report_type.focus();
    });
  }

  function closeModal() {
    modal.hidden = true;
    modal.setAttribute('aria-hidden', 'true');
    document.body.classList.remove('complaint-modal-open');
    if (previousFocus && typeof previousFocus.focus === 'function') previousFocus.focus();
  }

  function syncFileInput() {
    const transfer = new DataTransfer();
    selectedFiles.forEach(function (file) { transfer.items.add(file); });
    fileInput.files = transfer.files;
  }

  function renderFiles() {
    fileList.innerHTML = '';
    selectedFiles.forEach(function (file, index) {
      const item = document.createElement('div');
      item.className = 'complaint-file';
      const preview = document.createElement('img');
      preview.alt = '';
      preview.src = URL.createObjectURL(file);
      preview.addEventListener('load', function () { URL.revokeObjectURL(preview.src); }, { once: true });
      const copy = document.createElement('div');
      const name = document.createElement('strong');
      name.textContent = file.name;
      const size = document.createElement('small');
      size.textContent = (file.size / 1024 / 1024).toFixed(2) + ' MB';
      copy.append(name, size);
      const remove = document.createElement('button');
      remove.type = 'button';
      remove.setAttribute('aria-label', 'Remove ' + file.name);
      remove.innerHTML = '&times;';
      remove.addEventListener('click', function () {
        selectedFiles.splice(index, 1);
        syncFileInput();
        renderFiles();
      });
      item.append(preview, copy, remove);
      fileList.appendChild(item);
    });
  }

  function addFiles(files) {
    const incoming = Array.from(files || []);
    const validTypes = ['image/jpeg', 'image/png', 'image/webp'];
    for (const file of incoming) {
      if (!validTypes.includes(file.type)) {
        showStatus('Only JPG, PNG, and WebP images can be attached.', 'error');
        continue;
      }
      if (file.size > 5 * 1024 * 1024) {
        showStatus(file.name + ' is larger than 5 MB.', 'error');
        continue;
      }
      if (selectedFiles.length >= 3) {
        showStatus('You can attach up to 3 images.', 'error');
        break;
      }
      const duplicate = selectedFiles.some(function (current) {
        return current.name === file.name && current.size === file.size && current.lastModified === file.lastModified;
      });
      if (!duplicate) selectedFiles.push(file);
    }
    syncFileInput();
    renderFiles();
  }

  function setMaximumDate() {
    const now = new Date();
    const local = new Date(now.getTime() - now.getTimezoneOffset() * 60000);
    incidentAt.max = local.toISOString().slice(0, 16);
  }

  openButtons.forEach(function (openButton) {
    openButton.addEventListener('click', function (event) {
      event.preventDefault();
      openModal();
    });
  });

  modal.querySelectorAll('[data-complaint-close]').forEach(function (button) {
    button.addEventListener('click', closeModal);
  });

  modal.addEventListener('keydown', function (event) {
    if (event.key === 'Escape') closeModal();
    if (event.key !== 'Tab') return;
    const focusable = Array.from(dialog.querySelectorAll('button:not(:disabled), input:not(:disabled), select:not(:disabled), textarea:not(:disabled), [tabindex]:not([tabindex="-1"])'));
    if (!focusable.length) return;
    const first = focusable[0];
    const last = focusable[focusable.length - 1];
    if (event.shiftKey && document.activeElement === first) {
      event.preventDefault();
      last.focus();
    } else if (!event.shiftKey && document.activeElement === last) {
      event.preventDefault();
      first.focus();
    }
  });

  fileInput.addEventListener('change', function () { addFiles(fileInput.files); });
  ['dragenter', 'dragover'].forEach(function (name) {
    dropzone.addEventListener(name, function (event) {
      event.preventDefault();
      dropzone.classList.add('is-dragging');
    });
  });
  ['dragleave', 'drop'].forEach(function (name) {
    dropzone.addEventListener(name, function (event) {
      event.preventDefault();
      dropzone.classList.remove('is-dragging');
    });
  });
  dropzone.addEventListener('drop', function (event) { addFiles(event.dataTransfer.files); });

  form.addEventListener('submit', async function (event) {
    event.preventDefault();
    status.className = 'complaint-form__status';
    if (!validateForm(true)) return;

    submitButton.disabled = true;
    const originalText = submitButton.querySelector('span').textContent;
    submitButton.querySelector('span').textContent = 'Submitting securely...';

    try {
      const payload = new FormData(form);
      payload.set('csrf_token', config.csrfToken || '');
      payload.delete('evidence[]');
      selectedFiles.forEach(function (file) { payload.append('evidence[]', file); });

      const response = await fetch(config.endpoint || 'php/submit_complaint_incident.php', {
        method: 'POST',
        body: payload,
        credentials: 'same-origin',
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
      });
      const result = await response.json();

      if (response.status === 401) {
        closeModal();
        requestLogin();
        return;
      }
      if (!response.ok || !result.success) throw new Error(result.message || 'Your report could not be submitted.');

      form.reset();
      form.querySelectorAll('[aria-invalid="true"]').forEach(clearFieldError);
      renderValidationSummary([]);
      selectedFiles = [];
      syncFileInput();
      renderFiles();
      setMaximumDate();
      closeModal();
      await showSubmissionSuccess(result.reference);
    } catch (error) {
      showStatus(error.message || 'Your report could not be submitted. Please try again.', 'error');
    } finally {
      submitButton.disabled = false;
      submitButton.querySelector('span').textContent = originalText;
    }
  });

  form.addEventListener('invalid', function (event) {
    event.preventDefault();
  }, true);

  form.addEventListener('input', function (event) {
    if (!(event.target instanceof HTMLInputElement || event.target instanceof HTMLSelectElement || event.target instanceof HTMLTextAreaElement)) return;
    if (event.target.validity.valid) clearFieldError(event.target);
    if (!validationSummary.hidden) validateForm(false);
  });

  form.addEventListener('change', function (event) {
    if (!(event.target instanceof HTMLInputElement || event.target instanceof HTMLSelectElement || event.target instanceof HTMLTextAreaElement)) return;
    if (event.target.validity.valid) clearFieldError(event.target);
    if (!validationSummary.hidden) validateForm(false);
  });

  setMaximumDate();
  if (window.location.hash === '#complaintIncidentModal') {
    window.requestAnimationFrame(openModal);
  }
})();
