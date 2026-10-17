(() => {
  'use strict';
  const escape = value => String(value ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const money = value => Number(value).toLocaleString('en-PH', { style: 'currency', currency: 'PHP' });
  document.querySelectorAll('[data-addon-manager]').forEach(panel => {
    let items = JSON.parse(panel.querySelector('[data-addon-items]').textContent);
    const field = selector => panel.querySelector(selector);
    const id = field('[data-addon-id]'), name = field('[data-addon-name]'), price = field('[data-addon-price]'), active = field('[data-addon-active]');
    const save = field('[data-addon-save]'), cancel = field('[data-addon-cancel]'), notice = field('[data-addon-notice]');
    const showNotice = (message = '', type = 'info') => {
      notice.hidden = !message;
      notice.classList.toggle('error', type === 'error');
      notice.classList.toggle('success', type === 'success');
      notice.setAttribute('role', type === 'error' ? 'alert' : 'status');
      const text = notice.querySelector('[data-addon-notice-text]');
      if (text.textContent !== message) text.textContent = message;
      notice.querySelector('[data-addon-notice-icon]').innerHTML = '<circle cx="12" cy="12" r="9"/>' +
        (type === 'success' ? '<path d="m8 12 3 3 5-6"/>' : '<path d="M12 7v6M12 17h.01"/>');
    };
    const clearValidation = () => [name, price].forEach(input => input.removeAttribute('aria-invalid'));
    const validateEditor = (focusInvalid = false) => {
      const missingName = !name.value.trim();
      const missingPrice = !price.value;
      const invalidPrice = missingPrice || !price.checkValidity() || Number(price.value) <= 0;
      name.setAttribute('aria-invalid', String(missingName));
      price.setAttribute('aria-invalid', String(invalidPrice));
      if (!missingName && !invalidPrice) return true;
      const first = Number(id.value) === 0 ? 'Complete this add-on first. ' : 'Complete this add-on before saving. ';
      let message;
      if (missingName && missingPrice) message = first + 'Enter its name and price before adding it.';
      else if (missingName) message = first + 'Enter an add-on name.';
      else if (missingPrice) message = first + 'Enter a price before adding it.';
      else message = first + 'Enter a price greater than ₱0, up to ₱1,000,000, with no more than 2 decimal places.';
      showNotice(message, 'error');
      if (focusInvalid) (missingName ? name : price).focus();
      return false;
    };
    [name, price].forEach(input => input.addEventListener('input', () => {
      if (input.getAttribute('aria-invalid') !== 'true') return;
      if (validateEditor()) { clearValidation(); showNotice(); }
    }));
    const reset = () => { clearValidation(); showNotice(); id.value = '0'; name.value = ''; price.value = ''; active.value = '1'; save.querySelector('span').textContent = 'Add option'; cancel.hidden = true; };
    const render = () => {
      field('[data-addon-list]').innerHTML = items.length ? items.map(item => 
        '<div class="addon-catalog-row"><div><strong>' + escape(item.name) + '</strong><span class="addon-catalog-state ' + (Number(item.is_active) ? '' : 'is-hidden') + '">' + (Number(item.is_active) ? 'Available' : 'Hidden') + '</span></div><strong class="addon-catalog-price">' + money(item.price) + '</strong><button type="button" class="am-button" data-edit-addon="' + Number(item.addon_id) + '">Edit<span class="addon-sr-only"> ' + escape(item.name) + '</span></button></div>'
      ).join('') : '<div class="addon-catalog-empty"><strong>No add-ons yet</strong><p>Add your first option above. It will appear on eligible booking forms.</p></div>';
    };
    panel.addEventListener('click', event => {
      const edit = event.target.closest('[data-edit-addon]');
      if (!edit || save.disabled) return;
      const item = items.find(item => Number(item.addon_id) === Number(edit.dataset.editAddon));
      if (!item) return;
      clearValidation(); showNotice();
      id.value = item.addon_id; name.value = item.name; price.value = Number(item.price).toFixed(2); active.value = String(item.is_active);
      save.querySelector('span').textContent = 'Save add-on'; cancel.hidden = false; name.focus();
    });
    cancel.addEventListener('click', reset);
    [name, price, active].forEach(input => input.addEventListener('keydown', event => { if (event.key === 'Enter') { event.preventDefault(); save.click(); } }));
    save.addEventListener('click', async () => {
      if (save.disabled) return;
      if (!validateEditor(true)) return;
      save.disabled = true; cancel.disabled = true; showNotice('Saving add-on…');
      try {
        const body = new FormData();
        Object.entries({ role: panel.dataset.role, csrf_token: panel.dataset.csrf, addon_id: id.value, name: name.value.trim(), price: price.value, is_active: active.value }).forEach(([key,value]) => body.set(key,value));
        const response = await fetch(panel.dataset.endpoint, { method: 'POST', body, headers: { Accept: 'application/json' } });
        if (!response.headers.get('content-type')?.includes('application/json')) throw new Error('Your session expired. Refresh the page and sign in again.');
        const result = await response.json();
        if (!response.ok || !result.success) throw new Error(result.message || 'Add-on could not be saved.');
        items = result.items; render(); reset(); showNotice(result.message || 'Add-on saved.', 'success');
      } catch (error) { showNotice(error.message || 'The add-on could not be saved. Please try again.', 'error'); }
      finally { save.disabled = false; cancel.disabled = false; }
    });
    render();
  });
})();
