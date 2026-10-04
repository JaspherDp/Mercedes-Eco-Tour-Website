(() => {
  'use strict';
  const tabs = [...document.querySelectorAll('[data-portal-panel]')];
  const panels = [...document.querySelectorAll('[data-portal-panel-content]')];
  const form = document.getElementById('portalSettingsForm');
  const save = document.getElementById('portalSettingsSave');
  const storageKey = `itour-portal-settings-panel:${window.location.pathname}`;
  const activate = name => {
    tabs.forEach(tab => { const active = tab.dataset.portalPanel === name; tab.classList.toggle('active', active); tab.setAttribute('aria-pressed', String(active)); });
    panels.forEach(panel => panel.classList.toggle('active', panel.dataset.portalPanelContent === name));
    try { sessionStorage.setItem(storageKey, name); } catch (_) {}
  };
  tabs.forEach(tab => tab.addEventListener('click', () => activate(tab.dataset.portalPanel)));
  try { const saved = sessionStorage.getItem(storageKey); activate(tabs.some(tab => tab.dataset.portalPanel === saved) ? saved : 'general'); } catch (_) { activate('general'); }
  let dirty = false;
  form?.addEventListener('change', () => { dirty = true; });
  form?.addEventListener('submit', event => {
    if (event.submitter?.value === 'export_settings') return;
    dirty = false;
    if (save) { save.disabled = true; save.querySelector('span').textContent = 'Saving…'; }
  });
  window.addEventListener('beforeunload', event => { if (dirty) { event.preventDefault(); event.returnValue = ''; } });
  const modal = document.getElementById('portalSettingsResetModal');
  const opener = document.getElementById('portalSettingsReset');
  const close = () => { modal?.classList.remove('open'); modal?.setAttribute('aria-hidden', 'true'); opener?.focus(); };
  opener?.addEventListener('click', () => { modal.classList.add('open'); modal.setAttribute('aria-hidden', 'false'); modal.querySelector('[data-close-portal-reset]')?.focus(); });
  document.querySelectorAll('[data-close-portal-reset]').forEach(button => button.addEventListener('click', close));
  modal?.addEventListener('click', event => { if (event.target === modal) close(); });
  modal?.querySelector('form')?.addEventListener('submit', () => { dirty = false; });
  document.addEventListener('keydown', event => {
    if (!modal?.classList.contains('open')) return;
    if (event.key === 'Escape') close();
    if (event.key === 'Tab') {
      const focusable = [...modal.querySelectorAll('button:not(:disabled)')];
      const first = focusable[0], last = focusable.at(-1);
      if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
      else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
    }
  });
})();
