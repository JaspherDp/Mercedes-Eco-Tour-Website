(() => {
  const rows = document.getElementById('adminAddonRows');
  const template = document.getElementById('adminAddonRowTemplate');
  const add = document.getElementById('adminAddonAdd');
  const money = value => `\u20b1${value.toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
  function update() {
    let total = Math.round((window.StaffBookingAddons?.total(rows.closest('form')) || 0) * 100);
    const items = Array.from(rows.children);
    items.forEach((row, index) => {
      const quantity = Number(row.querySelector('[name="quantity[]"]').value || 0);
      const price = Number(row.querySelector('[name="unit_price[]"]').value || 0);
      const minor = Number.isFinite(quantity) && Number.isFinite(price) ? Math.max(0, Math.round(price * 100) * quantity) : 0;
      total += minor;
      row.querySelector('[data-addon-label]').textContent = `ADD-ON ITEM ${String(index + 1).padStart(2, '0')}`;
      row.querySelector('[data-addon-total]').textContent = money(minor / 100);
      row.querySelector('.admin-addon-remove').disabled = items.length === 1;
    });
    document.getElementById('adminAddonTotal').textContent = money(total / 100);
    add.disabled = items.length >= 25;
  }
  function addRow(focus = false) {
    if (rows.children.length >= 25) return;
    rows.append(template.content.cloneNode(true));
    update();
    if (focus) {
      rows.lastElementChild.querySelector('select').focus();
      rows.lastElementChild.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
    }
  }
  function reset() {
    window.StaffBookingAddons?.reset(rows.closest('form'));
    rows.replaceChildren();
    addRow();
  }
  add.addEventListener('click', () => addRow(true));
  rows.addEventListener('input', update);
  rows.addEventListener('change', update);
  rows.addEventListener('click', event => {
    const remove = event.target.closest('.admin-addon-remove');
    if (!remove || rows.children.length <= 1) return;
    remove.closest('.admin-addon-row').remove();
    update();
  });
  window.AdminBookingAddons = { reset };
  reset();
})();
