(() => {
  'use strict';
  const form = document.querySelector('form.review-filters');
  const results = document.getElementById('hotelReviewResults');
  const notice = document.getElementById('reviewFilterNotice');
  if (!form || !results) return;
  let timer, controller, version = 0;

  function updateNavigation() {
    const track = results.querySelector('#reviewsTrack');
    const prev = results.querySelector('#reviewsPrev');
    const next = results.querySelector('#reviewsNext');
    if (!track || !prev || !next) return;
    const update = () => {
      prev.disabled = track.scrollLeft <= 2;
      next.disabled = track.scrollLeft >= Math.max(0, track.scrollWidth - track.clientWidth - 2);
    };
    const step = () => Math.max(280, Math.floor(track.clientWidth / (window.innerWidth <= 768 ? 1 : 2)));
    prev.onclick = () => track.scrollBy({ left: -step(), behavior: 'smooth' });
    next.onclick = () => track.scrollBy({ left: step(), behavior: 'smooth' });
    track.onscroll = update;
    window.requestAnimationFrame(update);
  }

  function filterUrl() {
    const url = new URL(form.action, window.location.href);
    url.search = new URLSearchParams(new FormData(form)).toString();
    url.searchParams.delete('review_page');
    return url;
  }

  async function load(url) {
    clearTimeout(timer);
    controller?.abort();
    controller = new AbortController();
    const request = ++version;
    results.setAttribute('aria-busy', 'true');
    if (notice) notice.hidden = true;
    try {
      const response = await fetch(url.href, {
        headers: { 'X-Review-Fragment': '1', Accept: 'text/html' },
        credentials: 'same-origin', cache: 'no-store', signal: controller.signal
      });
      if (!response.ok) throw new Error('Review request failed.');
      const html = await response.text();
      if (request !== version) return;
      const parsed = new DOMParser().parseFromString(html, 'text/html');
      const fragment = parsed.querySelector('[data-review-results-fragment]');
      if (!fragment) throw new Error('Review results unavailable.');
      results.replaceChildren(fragment);
      window.history.replaceState(window.history.state, '', url.pathname + url.search + url.hash);
      updateNavigation();
    } catch (error) {
      if (error.name === 'AbortError' || request !== version) return;
      if (notice) {
        notice.textContent = 'Reviews could not update. Check your connection and change a filter to try again.';
        notice.hidden = false;
      }
    } finally {
      if (request === version) results.removeAttribute('aria-busy');
    }
  }

  form.addEventListener('change', event => {
    if (event.target.tagName === 'SELECT') load(filterUrl());
  });
  const search = form.querySelector('[name="review_search"]');
  search?.addEventListener('input', event => {
    if (event.isComposing) return;
    clearTimeout(timer);
    controller?.abort();
    ++version;
    timer = setTimeout(() => load(filterUrl()), 350);
  });
  search?.addEventListener('search', () => load(filterUrl()));
  form.addEventListener('submit', event => { event.preventDefault(); load(filterUrl()); });
  results.addEventListener('click', event => {
    const link = event.target.closest('.review-pagination a');
    if (!link || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey || (event.button && event.button !== 0)) return;
    event.preventDefault();
    load(new URL(link.href, window.location.href));
  });
  window.addEventListener('resize', updateNavigation);
  updateNavigation();
})();
