(() => {
  'use strict';
  const adults = document.getElementById('resultsHotelAdults');
  const children = document.getElementById('resultsHotelChildren');
  const container = document.getElementById('resultsHotelChildAges');
  if (!adults || !children || !container) return;
  let saved = String(container.dataset.ages || '').split(',').filter(value => value !== '');
  const sync = () => {
    const count = Math.min(100, Math.max(0, Number(children.value) || 0));
    const previous = [...container.querySelectorAll('select')].map(select => select.value);
    if (previous.length) saved = previous;
    container.replaceChildren();
    const fields = [];
    for (let index = 0; index < count; index++) {
      const label = document.createElement('label');
      label.textContent = `Child ${index + 1} age`;
      const select = document.createElement('select');
      select.required = true;
      select.setAttribute('aria-label', `Child ${index + 1} age`);
      select.add(new Option('Select age', ''));
      for (let age = 0; age <= 17; age++) select.add(new Option(`${age} — ${age <= 5 ? 'Free' : 'PHP 250/night'}`, String(age)));
      select.value = saved[index] ?? '';
      const control = document.createElement('div'); control.className = 'results-search-control';
      control.append(select); label.append(control); container.append(label); fields.push(select);
    }
    const hidden = document.createElement('input');
    hidden.type = 'hidden'; hidden.name = 'child_ages';
    const update = () => { hidden.value = fields.map(field => field.value).join(','); };
    fields.forEach(field => field.addEventListener('change', update));
    update(); container.append(hidden);
    container.hidden = count === 0;
    document.getElementById('resultsHotelPax').value = String(Number(adults.value || 0) + count);
    saved = saved.slice(0, count);
  };
  adults.addEventListener('input', () => {
    document.getElementById('resultsHotelPax').value = String(Number(adults.value || 0) + Number(children.value || 0));
  });
  children.addEventListener('input', sync);
  sync();
})();
