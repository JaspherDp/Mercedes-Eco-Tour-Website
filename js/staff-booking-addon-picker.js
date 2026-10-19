(() => {
  'use strict';
  const catalog = document.getElementById('staffBookingAddonCatalog');
  if (!catalog) return;
  const items = JSON.parse(catalog.textContent);
  const rows = document.getElementById('adminAddonRows') || document.getElementById('hoExpenseRows');
  const form = rows ? rows.closest('form') : document.getElementById('opExpenseForm');
  if (!form) return;
  const money = value => Number(value).toLocaleString('en-PH', { style: 'currency', currency: 'PHP' });
  const box = document.createElement('section');
  box.className = 'staff-saved-addons';
  box.setAttribute('aria-labelledby', 'staffSavedAddonsTitle');
  const title = document.createElement('h4'); title.id = 'staffSavedAddonsTitle'; title.textContent = 'Saved add-ons';
  const hint = document.createElement('p');
  hint.textContent = items.length ? 'Check the add-ons to include. You can also enter other charges in the manual form below.' : 'No saved add-ons are available. Use the manual form below.';
  box.append(title, hint);
  const choices = [];
  items.forEach(item => {
    const option = document.createElement('div'); option.className = 'staff-saved-addon';
    const label = document.createElement('label'); label.className = 'staff-saved-addon-label';
    const check = document.createElement('input'); check.type = 'checkbox'; check.name = 'saved_addon_id[]'; check.value = String(item.addon_id);
    const text = document.createElement('span');
    const name = document.createElement('strong'); name.textContent = item.name;
    const price = document.createElement('small'); price.textContent = money(item.price) + ' per item';
    text.append(name, price); label.append(check, text);
    const qtyLabel = document.createElement('label'); qtyLabel.className = 'staff-saved-addon-qty';
    const qtyText = document.createElement('span'); qtyText.textContent = 'Qty';
    const quantity = document.createElement('input'); quantity.type = 'number'; quantity.name = 'saved_addon_quantity[' + item.addon_id + ']';
    quantity.min = '1'; quantity.max = '1000'; quantity.step = '1'; quantity.value = '1'; quantity.defaultValue = '1'; quantity.disabled = true; quantity.required = true;
    quantity.setAttribute('aria-label', item.name + ' quantity');
    qtyLabel.append(qtyText, quantity); option.append(label, qtyLabel); box.append(option);
    choices.push({ item, check, quantity, option });
  });
  const subtotalLine = document.createElement('div'); subtotalLine.className = 'staff-saved-addon-subtotal';
  const subtotalLabel = document.createElement('span'); subtotalLabel.textContent = 'Selected saved add-ons';
  const subtotal = document.createElement('strong'); subtotalLine.append(subtotalLabel, subtotal); box.append(subtotalLine);
  if (rows) rows.before(box);
  else form.querySelector('[name="expense_type[]"]').closest('label').before(box);

  const total = () => choices.reduce((sum, choice) => {
    const qty = Number(choice.quantity.value);
    return sum + (choice.check.checked && Number.isInteger(qty) && qty >= 1 && qty <= 1000 ? Math.round(Number(choice.item.price) * 100) * qty : 0);
  }, 0) / 100;
  function manualRows() { return rows ? Array.from(rows.children) : [form]; }
  function updateManualRequirements() {
    const hasSaved = choices.some(choice => choice.check.checked);
    manualRows().forEach(row => {
      const inputs = ['expense_type[]', 'expense_name[]', 'unit_price[]', 'quantity[]'].map(name => row.querySelector('[name="' + name + '"]'));
      const note = row.querySelector('[name="note[]"], [name="notes[]"]');
      const empty = inputs.slice(0,3).every(input => !input.value.trim()) && inputs[3].value === '1' && (!note || !note.value.trim());
      inputs.forEach(input => { input.required = !(hasSaved && empty); });
    });
  }
  function update() {
    choices.forEach(choice => {
      choice.quantity.disabled = !choice.check.checked;
      choice.option.classList.toggle('is-selected', choice.check.checked);
    });
    subtotal.textContent = money(total());
    updateManualRequirements();
    if (rows) rows.dispatchEvent(new Event('input', { bubbles: true }));
    else {
      const qty = Number(form.querySelector('[name="quantity[]"]').value);
      const price = Number(form.querySelector('[name="unit_price[]"]').value);
      const amount = Math.round(price * 100) * qty;
      form.querySelector('[data-staff-addon-total]').textContent = money(total() + (Number.isFinite(amount) ? Math.max(0, amount) / 100 : 0));
    }
  }
  box.addEventListener('change', update);
  box.addEventListener('input', update);
  // Synthetic row events update existing totals; real input also updates required fields.
  form.addEventListener('input', event => { if (!box.contains(event.target) && event.target !== rows) { updateManualRequirements(); if (!rows) update(); } });
  form.addEventListener('change', event => { if (!box.contains(event.target)) updateManualRequirements(); });
  form.addEventListener('reset', () => queueMicrotask(update));
  if (rows) new MutationObserver(update).observe(rows, { childList: true });
  window.StaffBookingAddons = {
    total: target => target === form ? total() : 0,
    reset: target => { if (target !== form) return; choices.forEach(choice => { choice.check.checked = false; choice.quantity.value = '1'; }); update(); }
  };
  update();
})();
