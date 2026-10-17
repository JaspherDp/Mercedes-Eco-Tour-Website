(() => {
  'use strict';
  const form = document.getElementById('servicePricesForm');
  if (!form) return;
  const ready = document.currentScript?.dataset.pricesReady === '1';
  const tabs = [...document.querySelectorAll('[data-panel]')];
  const panels = [...document.querySelectorAll('[data-panel-content]')];
  const saveButton = document.getElementById('servicePricingSaveButton');
  const fields = [...document.querySelectorAll('[form="servicePricesForm"]')];
  let dirty = false;
  let saving = false;
  const activate = requested => {
    const name = tabs.some(tab => tab.dataset.panel === requested) ? requested : 'prices';
    tabs.forEach(tab => {
      const active = tab.dataset.panel === name;
      tab.classList.toggle('active', active);
      tab.setAttribute('aria-pressed', String(active));
    });
    panels.forEach(panel => {
      const active = panel.dataset.panelContent === name;
      panel.classList.toggle('active', active);
      panel.hidden = !active;
    });
    document.body.classList.toggle('addon-settings-active', name === 'addons');
    saveButton.hidden = name === 'addons';
    saveButton.disabled = !ready || saving;
    try { sessionStorage.setItem('itour-service-pricing-panel', name); } catch (error) {}
  };
  tabs.forEach(tab => tab.addEventListener('click', () => activate(tab.dataset.panel)));
  let initial = new URLSearchParams(window.location.search).get('panel');
  try { if (!initial) initial = sessionStorage.getItem('itour-service-pricing-panel'); } catch (error) {}
  activate(initial);
  fields.forEach(field => field.addEventListener('input', () => { dirty = true; }));
  form.addEventListener('submit', async event => {
    event.preventDefault();
    if (!ready || saving) return;
    saving = true;
    saveButton.disabled = true;
    try {
      const response = await fetch(form.action, { method: 'POST', body: new FormData(form), credentials: 'same-origin' });
      const result = await response.json();
      if (!response.ok || !result.success) throw new Error(result.message || 'Service prices could not be saved.');
      dirty = false;
      await Swal.fire({ icon: 'success', title: 'Service prices saved', text: result.message || 'Prices updated successfully.', confirmButtonColor: '#176b58' });
    } catch (error) {
      await Swal.fire({ icon: 'error', title: 'Unable to save prices', text: error.message || 'Please try again.', confirmButtonColor: '#176b58' });
    } finally {
      saving = false;
      saveButton.disabled = !ready;
    }
  });
  window.addEventListener('beforeunload', event => {
    if (dirty) { event.preventDefault(); event.returnValue = ''; }
  });
})();
