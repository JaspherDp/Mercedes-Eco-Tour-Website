(() => {
  'use strict';
  document.querySelectorAll('[data-child-policy-settings]').forEach(panel => {
    const form = panel.closest('form');
    const adults = form.querySelector('[name="capacity_adults"]');
    const configured = form.querySelector('[name="capacity_children"]');
    const allowance = panel.querySelector('[name="policy_max_children"]');
    let edited = false;
    allowance.addEventListener('input', () => { edited = true; });
    adults.addEventListener('input', () => {
      if (edited) return;
      const count = Number(adults.value);
      allowance.value = String(count === 2 ? 2 : count >= 3 && count <= 6 ? 3 : Number(configured.value || 0));
      // Never raise the approved occupancy field or check the approval box.
    });
  });
})();
