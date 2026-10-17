/* Resource selections are estimates; checkout validates capacity, rates and availability. */
(function (global) {
  'use strict';
  const seats = value => Math.max(0, Math.min(8, Number(value) || 0));
  class BookingResourcePicker {
    constructor(options) { this.options = options; this.boatIds = []; this.guideIds = []; this.boatCount = 0; this.guideCount = 0; this.signature = ''; }
    sync() {
      const o = this.options, type = o.type(), addon = o.addon(), pax = o.pax();
      const usesBoat = type === 'boat' || addon === 'boat', usesGuide = type === 'tourguide' || addon === 'tourguide';
      const old = resource => [...o.container.querySelectorAll('select[data-resource="' + resource + '"]')].map(select => Number(select.value) || 0);
      let boats = usesBoat ? old('boat') : [], guides = usesGuide ? old('tourguide') : [];
      if (type === 'boat') boats.unshift(Number(o.primaryBoat()) || 0);
      if (type === 'tourguide') guides.unshift(Number(o.primaryGuide()) || 0);
      if (usesBoat && !boats.length) boats = [0];
      if (usesGuide && !guides.length) guides = [0];
      const boatSeats = id => seats(o.boats.find(boat => Number(boat.id) === id)?.capacity);
      // Remove redundant extra boats when counts fall. Missing selections estimate eight seats.
      let capacity = boats.reduce((sum, id) => sum + (id ? boatSeats(id) : 8), 0);
      for (let i = boats.length - 1; i > 0; i--) {
        const size = boats[i] ? boatSeats(boats[i]) : 8;
        if (capacity - size >= pax) { boats.splice(i, 1); capacity -= size; }
      }
      while (usesBoat && capacity < pax && boats.length < 100) { boats.push(0); capacity += 8; }
      const minimum = Math.max(1, Math.ceil(pax / 8));
      if (usesGuide) { guides = guides.slice(0, minimum); while (guides.length < minimum) guides.push(0); }
      this.boatIds = boats; this.guideIds = guides; this.boatCount = boats.length; this.guideCount = guides.length;
      const signature = JSON.stringify([type, addon, pax, boats, guides]);
      if (signature === this.signature) return;
      this.signature = signature;
      o.container.replaceChildren();
      const heading = document.createElement('p');
      heading.className = 'helper-text';
      heading.textContent = [usesBoat ? boats.length + ' boat(s) required' : '', usesGuide ? guides.length + ' guide(s) required' : ''].filter(Boolean).join(' · ') + '. Adults and children count toward capacity. Select each required resource; availability is checked before payment.';
      o.container.appendChild(heading);
      for (const [resource, ids, rows] of [['boat', boats, o.boats], ['tourguide', guides, o.guides]]) {
        ids.forEach((id, index) => {
          if (index === 0 && type === resource) return;
          const label = document.createElement('label');
          label.textContent = (resource === 'boat' ? 'Boat ' : 'Tour guide ') + (index + 1);
          const select = document.createElement('select');
          select.dataset.resource = resource;
          select.required = true;
          select.add(new Option('Select ' + (resource === 'boat' ? 'boat' : 'tour guide'), ''));
          rows.forEach(row => {
            const option = new Option(row.name + (resource === 'boat' ? ' — ' + seats(row.capacity) + ' seats' : ''), String(row.id));
            option.disabled = (Number(row.id) !== id && ids.includes(Number(row.id))) || (resource === 'boat' && seats(row.capacity) < 1);
            select.add(option);
          });
          select.value = id ? String(id) : '';
          select.addEventListener('change', () => { this.signature = ''; o.change(); });
          label.appendChild(select); o.container.appendChild(label);
        });
      }
      o.container.hidden = !usesBoat && !usesGuide;
    }
    validate() {
      this.sync();
      const valid = ids => ids.every(id => id > 0) && new Set(ids).size === ids.length;
      if (!valid(this.boatIds) || !valid(this.guideIds)) return 'Select a different available boat or guide for every required resource.';
      const capacity = this.boatIds.reduce((sum, id) => sum + seats(this.options.boats.find(boat => Number(boat.id) === id)?.capacity), 0);
      if (this.boatIds.length && capacity < this.options.pax()) return 'Choose additional boats to provide seats for every adult and child.';
      return '';
    }
  }
  global.BookingResourcePicker = BookingResourcePicker;
})(window);
