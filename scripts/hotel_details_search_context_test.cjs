'use strict';
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const source = fs.readFileSync(path.join(__dirname, '../public/hotel_details.php'), 'utf8');
const helpers = source.slice(source.indexOf('    function normalizeDetailsIsland('), source.indexOf('    function fillSearchFromData('));
const initialization = source.slice(source.indexOf('      const entryData = detailsEntrySearchData();'), source.indexOf('      updateIslandFieldState();\n      updateDetailsSearchSummary();', source.indexOf('      const entryData = detailsEntrySearchData();')));
assert.ok(helpers.length > 0 && initialization.length > 0, 'Find real page initialization');
function context(query, overrides = {}) {
  const calls = [];
  const ctx = {
    parseChildAges: value => String(value || '').split(',').filter(Boolean).map(Number),
    URLSearchParams, window: { location: { search: query } },
    islandInput: { options: ['', 'Apuao', 'Caringo', 'Canimog', 'Custom Cove'].map(value => ({ value })) },
    hotelData: { id: 7, island: 'Caringo Island' },
    navigationType: 'navigate', savedDataIsForCurrentHotel: false, draftDataIsForCurrentHotel: false,
    savedData: null, draftData: null, pageSource: 'result',
    sessionStorage: { getItem: () => null }, localStorage: { removeItem() {} },
    resortsSearchStorageKey: 'searchData', updateIslandFieldState() {},
    fillSearchFromData: data => calls.push(['fill', data]),
    runRoomSearch: data => calls.push(['search', data]),
    collectSearchData: () => calls.find(call => call[0] === 'fill')[1],
    renderRoomsSearchPrompt: () => calls.push(['prompt']), ...overrides
  };
  vm.createContext(ctx);
  vm.runInContext(helpers, ctx);
  ctx.initialize = () => vm.runInContext(initialization, ctx);
  return { ctx, calls };
}
let { ctx, calls } = context('?id=7&source=result&destination=Caringo&island=Caringo%20Island');
assert.equal(ctx.detailsEntrySearchData().island, 'Caringo');
ctx.initialize();
assert.equal(calls[0][1].island, 'Caringo');
assert.equal(calls[0][1].checkin, '');
assert.equal(calls[1][0], 'prompt', 'Location-only search waits for stay dates');
({ ctx, calls } = context('?id=7&destination=Mercedes&island=Apuao%20Grande%20Island&checkin=2026-11-02&checkout=2026-11-04&adults=3&children=2&rooms=2&child_ages=4,8'));
ctx.initialize();
const data = calls[0][1];
assert.equal(data.island, 'Apuao');
assert.equal(data.adults, 3); assert.equal(data.children, 2); assert.equal(data.rooms, 1, 'Legacy multiroom URLs normalize to one room');
assert.equal(Array.from(data.childAges).join(','), '4,8', 'Hotel ages carry over from search');
assert.equal(calls[1][0], 'search');
assert.equal(calls[1][1].checkout, '2026-11-04');
({ ctx, calls } = context('?destination=Caringo', { savedDataIsForCurrentHotel: true, savedData: { island: 'Canimog' } }));
ctx.initialize();
assert.equal(calls[0][1].island, 'Caringo', 'New search takes priority over stale saved form');
({ ctx, calls } = context('?destination=Caringo', { navigationType: 'reload', savedDataIsForCurrentHotel: true, savedData: { island: 'Canimog' } }));
ctx.initialize();
assert.equal(calls[0][1].island, 'Canimog', 'Reload preserves the edited form');
({ ctx } = context('?destination=Mercedes&adults=-2&children=-3&rooms=0'));
assert.equal(ctx.detailsEntrySearchData().island, 'Caringo', 'Broad destination defaults to property location');
assert.equal(ctx.detailsEntrySearchData().adults, 1);
assert.equal(ctx.detailsEntrySearchData().children, 0);
assert.equal(ctx.detailsEntrySearchData().rooms, 1);
assert.equal(ctx.normalizeDetailsIsland('Custom Cove'), 'Custom Cove');
({ ctx } = context('?id=7&source=result'));
assert.equal(ctx.detailsEntrySearchData(), null, 'Ordinary detail links do not override saved stays');
console.log('Hotel details search context checks passed.');
