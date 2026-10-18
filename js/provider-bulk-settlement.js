(() => {
  'use strict';
  const boxes = [...document.querySelectorAll('[data-bulk-payout]')];
  const bar = document.getElementById('bulkSettlementBar');
  if (!bar) return;
  const records = new Map(boxes.map(box => [box, JSON.parse(box.dataset.bulkPayout)]));
  const columns = [...document.querySelectorAll('[data-bulk-column]')];
  let available = false;
  // Integer centavos throughout, including display formatting.
  const money = minor => {
    if (!Number.isSafeInteger(minor) || minor < 0) throw new Error('Invalid payout amount.');
    return `₱${Math.floor(minor / 100).toLocaleString('en-PH')}.${String(minor % 100).padStart(2, '0')}`;
  };
  const visible = () => boxes.filter(box => available && !box.closest('[data-ledger-row]').hidden);
  const selected = () => visible().filter(box => box.checked);
  function refresh(status) {
    if (status !== undefined) available = status === 'available';
    columns.forEach(column => { column.hidden = !available; });
    const visibleBoxes = visible();
    boxes.forEach(box => { if (!visibleBoxes.includes(box)) box.checked = false; });
    const chosen = selected();
    const key = chosen.length ? records.get(chosen[0]).provider_key : null;
    // Defensive enforcement also covers browser-restored checkbox state.
    boxes.forEach(box => {
      const other = key !== null && records.get(box).provider_key !== key;
      box.disabled = !available || other;
      if (other) box.checked = false;
      box.title = other ? 'Clear selection before choosing another provider.' : '';
    });
    const rows = selected().map(box => records.get(box));
    bar.hidden = rows.length === 0;
    document.getElementById('bulkSelectedProvider').textContent = rows[0]?.provider || '';
    document.getElementById('bulkSelectedCount').textContent = String(rows.length);
    document.getElementById('bulkSelectedTotal').textContent = money(rows.reduce((sum, row) => sum + row.amount_minor, 0));
  }
  function clear() {
    boxes.forEach(box => { box.checked = false; });
    refresh();
  }
  boxes.forEach(box => box.addEventListener('change', () => refresh()));
  document.getElementById('bulkClearSelection').addEventListener('click', clear);
  document.getElementById('bulkSettleSelected').addEventListener('click', () => {
    const items = selected().map(box => records.get(box));
    if (!items.length) return;
    document.dispatchEvent(new CustomEvent('provider-bulk-settlement', { detail: items }));
  });
  window.providerBulkSettlement = { clear, refresh, money };
  clear();
})();
