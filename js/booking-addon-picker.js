(() => {
  'use strict';
  const picker = document.querySelector('[data-booking-addon-picker]');
  if (!picker) { window.BookingAddonPicker = { selections: () => [], total: () => 0, setDomain: () => {} }; return; }
  const options = [...picker.querySelectorAll('[data-addon-option]')];
  const money = value => Number(value).toLocaleString('en-PH', { style: 'currency', currency: 'PHP' });
  const selected = () => options.filter(row => !row.hidden && row.querySelector('[data-addon-check]').checked);
  const quantity = row => Number(row.querySelector('[data-addon-quantity]').value);
  const total = () => selected().reduce((sum,row) => {
    const count = quantity(row);
    return sum + (Number.isInteger(count) && count >= 1 && count <= 100 ? Math.round(Number(row.dataset.price) * 100) * count : 0);
  }, 0) / 100;
  const selections = () => selected().map(row => ({ addon_id: Number(row.dataset.id), quantity: quantity(row) }));
  function update() {
    options.forEach(row => {
      const checked = row.querySelector('[data-addon-check]').checked;
      row.classList.toggle('is-selected', checked);
      row.querySelector('[data-addon-quantity]').disabled = !checked;
    });
    const subtotal = picker.querySelector('[data-addon-subtotal]');
    if (subtotal) subtotal.textContent = money(total());
    document.querySelectorAll('[data-addon-summary]').forEach(summary => {
      summary.replaceChildren(); summary.hidden = selected().length === 0;
      selected().forEach(row => {
        const line = document.createElement('p');
        const name = document.createElement('span'); name.textContent = row.dataset.name + ' × ' + quantity(row);
        const amount = document.createElement('strong'); amount.textContent = money(Number(row.dataset.price) * quantity(row));
        line.append(name,amount); summary.append(line);
      });
    });
  }
  picker.addEventListener('change', event => {
    if (selected().length > 25 && event.target.matches('[data-addon-check]')) {
      event.target.checked = false; picker.querySelector('[data-addon-limit]').hidden = false;
    } else picker.querySelector('[data-addon-limit]').hidden = true;
    update();
    picker.dispatchEvent(new CustomEvent('booking-addons-change', { bubbles: true }));
  });
  picker.addEventListener('input', event => {
    if (!event.target.matches('[data-addon-quantity]')) return;
    update(); picker.dispatchEvent(new CustomEvent('booking-addons-change', { bubbles: true }));
  });
  function setDomain(domain) {
    options.forEach(row => {
      const hidden = row.dataset.packageOnly === '1' && domain !== 'package';
      row.hidden = hidden;
      row.querySelector('[data-addon-check]').disabled = hidden;
      if (hidden) row.querySelector('[data-addon-check]').checked = false;
    });
    update();
  }
  window.BookingAddonPicker = { selections, total, setDomain };
  update();
})();
