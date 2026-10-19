const fs = require('node:fs'), vm = require('node:vm'), assert = require('node:assert/strict');
const path = require('node:path');
const events = {}, timers = new Map(), requests = [], replacements = [];
const values = new Map([['id', '1'], ['checkin', '2026-10-25'], ['review_rating', ''], ['review_sort', 'newest'], ['review_search', '']]);
const search = { addEventListener(name, fn) { events['search:' + name] = fn; } };
const form = { action: 'https://example.test/hotel_details.php#reviews',
  addEventListener(name, fn) { events[name] = fn; }, querySelector() { return search; } };
const track = { scrollLeft: 0, scrollWidth: 1000, clientWidth: 400, scrollBy() {} };
const prev = {}, next = {};
const results = { busy: false,
  querySelector(selector) { return { '#reviewsTrack': track, '#reviewsPrev': prev, '#reviewsNext': next }[selector]; },
  addEventListener(name, fn) { events['results:' + name] = fn; },
  setAttribute() { this.busy = true; }, removeAttribute() { this.busy = false; },
  replaceChildren(fragment) { replacements.push(fragment); } };
const notice = { hidden: true };
let timerId = 0, address;
const context = vm.createContext({
  document: { querySelector: () => form, getElementById: id => id === 'hotelReviewResults' ? results : notice },
  window: { location: { href: form.action }, innerWidth: 1200,
    requestAnimationFrame: fn => fn(), addEventListener() {},
    history: { state: {}, replaceState(_state, _unused, url) { address = url; } } },
  URL, URLSearchParams, AbortController,
  FormData: class { [Symbol.iterator]() { return values.entries(); } },
  DOMParser: class { parseFromString(html) { return { querySelector: () => html.includes('data-review-results-fragment') ? { html } : null }; } },
  setTimeout(fn) { timers.set(++timerId, fn); return timerId; }, clearTimeout(id) { timers.delete(id); },
  fetch(url, options) { return new Promise(resolve => requests.push({ url: new URL(url), options, resolve })); }
});
vm.runInContext(fs.readFileSync(path.join(__dirname, '../js/hotel-review-filters.js'), 'utf8'), context);
const tick = async () => { for (let i = 0; i < 8; i++) await Promise.resolve(); };
const success = index => requests[index].resolve({ ok: true, text: async () => '<div data-review-results-fragment>results</div>' });
(async () => {
  values.set('review_rating', '4'); events.change({ target: { tagName: 'SELECT' } });
  assert.equal(requests.length, 1); assert.equal(requests[0].url.searchParams.get('review_rating'), '4');
  assert.equal(requests[0].options.headers['X-Review-Fragment'], '1');
  for (const term of ['be', 'bea', 'beach']) { values.set('review_search', term); events['search:input']({}); }
  assert.equal(requests.length, 1); assert.equal(timers.size, 1); assert.equal(requests[0].options.signal.aborted, true);
  success(0); await tick(); assert.equal(replacements.length, 0, 'An old response replaced newer filter input.');
  const run = [...timers.values()][0]; timers.clear(); run();
  assert.equal(requests.length, 2); assert.equal(requests[1].url.searchParams.get('review_search'), 'beach');
  assert.equal(requests[1].url.searchParams.get('checkin'), '2026-10-25');
  assert.equal(requests[1].url.searchParams.has('review_page'), false);
  success(1); await tick(); assert.equal(replacements.length, 1); assert.equal(results.busy, false);
  assert.ok(address.includes('review_search=beach')); assert.equal(next.disabled, false);
  values.set('review_search', ''); events['search:search']();
  assert.equal(requests.length, 3); requests[2].resolve({ ok: false }); await tick();
  assert.equal(notice.hidden, false); assert.equal(replacements.length, 1, 'Failure erased previous reviews.');
  values.set('review_sort', 'lowest'); events.change({ target: { tagName: 'SELECT' } });
  success(3); await tick(); assert.equal(notice.hidden, true);
  const page = { href: 'https://example.test/hotel_details.php?id=1&review_rating=4&review_sort=lowest&review_page=2#reviews' };
  let prevented = false;
  events['results:click']({ target: { closest: () => page }, preventDefault() { prevented = true; } });
  assert.equal(prevented, true); assert.equal(requests[4].url.searchParams.get('review_page'), '2');
  success(4); await tick();
  console.log('PASS: automatic selections, debounced search, stale-response protection, booking context, native search clear, error recovery, and in-place pagination.');
})().catch(error => { console.error(error); process.exitCode = 1; });
