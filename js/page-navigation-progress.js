(() => {
  if (window.__itourPageProgressReady) return;
  window.__itourPageProgressReady = true;

  const storageKey = 'itour-page-navigation-pending';
  const style = document.createElement('style');
  style.textContent = `
    #itour-page-progress {
      position: fixed;
      top: 0;
      left: 0;
      z-index: 2147483647;
      width: 0;
      height: 3px;
      pointer-events: none;
      opacity: 0;
      background: #159460;
      box-shadow: 0 0 8px rgba(21, 148, 96, .38);
      transition: width .4s ease, opacity .2s ease;
    }
    @media (max-width: 760px) {
      #itour-page-progress { display: none; }
    }
  `;
  (document.head || document.documentElement).appendChild(style);

  const bar = document.createElement('div');
  bar.id = 'itour-page-progress';
  bar.setAttribute('role', 'progressbar');
  bar.setAttribute('aria-label', 'Loading page');
  document.documentElement.appendChild(bar);

  let resetTimer;
  const reset = () => {
    clearTimeout(resetTimer);
    bar.style.opacity = '0';
    setTimeout(() => { bar.style.width = '0'; }, 220);
  };
  const start = (width) => {
    clearTimeout(resetTimer);
    bar.style.transition = 'none';
    bar.style.width = width;
    bar.style.opacity = '1';
    bar.getBoundingClientRect();
    bar.style.transition = 'width .4s ease, opacity .2s ease';
    requestAnimationFrame(() => { bar.style.width = '82%'; });
  };
  const finish = () => {
    clearTimeout(resetTimer);
    bar.style.width = '100%';
    resetTimer = setTimeout(reset, 230);
  };

  let arrivingFromNavigation = false;
  try {
    arrivingFromNavigation = sessionStorage.getItem(storageKey) === '1';
    sessionStorage.removeItem(storageKey);
  } catch (error) {
    // Navigation still works when browser storage is unavailable.
  }
  start(arrivingFromNavigation ? '65%' : '8%');
  if (document.readyState === 'complete') {
    setTimeout(finish, 100);
  } else {
    window.addEventListener('load', finish, { once: true });
  }

  document.addEventListener('click', (event) => {
    if (event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
    const link = event.target.closest?.('a[href]');
    if (!link || link.hasAttribute('download')) return;
    if (link.target && link.target.toLowerCase() !== '_self') return;

    let destination;
    try { destination = new URL(link.href, location.href); } catch (error) { return; }
    if (!['http:', 'https:'].includes(destination.protocol) || destination.origin !== location.origin) return;
    if (destination.pathname === location.pathname) {
      if (destination.search === location.search || !link.closest('nav, aside, [role="navigation"]')) return;
    }

    queueMicrotask(() => {
      if (event.defaultPrevented) return;
      try { sessionStorage.setItem(storageKey, '1'); } catch (error) { /* optional */ }
      start('8%');
      resetTimer = setTimeout(() => {
        try { sessionStorage.removeItem(storageKey); } catch (error) { /* optional */ }
        reset();
      }, 10000);
    });
  });

  window.addEventListener('pageshow', (event) => {
    if (event.persisted) reset();
  });
})();
