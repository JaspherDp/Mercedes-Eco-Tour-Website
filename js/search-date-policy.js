(() => {
  'use strict';

  // Calendar days in Mercedes, independent of the visitor's device timezone.
  function minimumDate(tab, now = new Date()) {
    const parts = new Intl.DateTimeFormat('en-US', {
      timeZone: 'Asia/Manila', year: 'numeric', month: '2-digit', day: '2-digit'
    }).formatToParts(now);
    const value = type => Number(parts.find(part => part.type === type).value);
    const advance = ['tours', 'guides', 'boats', 'bundle'].includes(tab) ? 2 : 0;
    return new Date(Date.UTC(value('year'), value('month') - 1, value('day') + advance))
      .toISOString().slice(0, 10);
  }

  window.SearchDatePolicy = Object.freeze({ minimumDate });
})();
