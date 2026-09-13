(function () {
  if (window.itourMobileScrollReady) return;
  window.itourMobileScrollReady = true;
  const css = document.createElement('link');
  css.rel = 'stylesheet';
  css.href = new URL('../styles/mobile-scroll.css', document.currentScript.src).href;
  document.head.appendChild(css);
  const initialize = () => {
    const indicator = document.createElement('div');
    indicator.className = 'mobile-scroll-indicator';
    indicator.setAttribute('aria-hidden', 'true');
    document.body.appendChild(indicator);
    const mobile = window.matchMedia('(max-width: 980px)');
    const timers = new WeakMap();
    let frame = 0;
    let hideTimer = 0;
    document.addEventListener('scroll', (event) => {
      if (!mobile.matches) return;
      const target = event.target;
      if (target !== document && target !== document.documentElement && target !== document.body) {
        if (!(target instanceof Element)) return;
        target.classList.add('mobile-is-scrolling');
        clearTimeout(timers.get(target));
        timers.set(target, setTimeout(() => target.classList.remove('mobile-is-scrolling'), 800));
        return;
      }
      clearTimeout(hideTimer);
      if (!frame) frame = requestAnimationFrame(() => {
        frame = 0;
        const root = document.scrollingElement;
        const view = document.documentElement.clientHeight;
        const maxScroll = root.scrollHeight - view;
        if (maxScroll <= 0) return;
        const height = Math.max(24, view * view / root.scrollHeight);
        const offset = Math.max(0, Math.min(1, root.scrollTop / maxScroll)) * (view - height - 8) + 4;
        indicator.style.height = height + 'px';
        indicator.style.transform = `translateY(${offset}px)`;
        indicator.classList.add('is-visible');
      });
      hideTimer = setTimeout(() => indicator.classList.remove('is-visible'), 800);
    }, {capture: true, passive: true});
  };
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initialize, {once:true});
  else initialize();
})();
