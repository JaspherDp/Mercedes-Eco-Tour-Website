(function () {
  'use strict';

  var startedAt = Date.now();
  var minimumDisplayMs = 180;
  var maximumDisplayMs = 900;
  var dismissed = false;

  function removeLoader() {
    if (dismissed) return;
    dismissed = true;

    var loader = document.getElementById('itourPageLoader');
    document.documentElement.classList.remove('itour-page-loading');
    if (!loader) return;

    loader.classList.add('is-leaving');
    loader.setAttribute('aria-hidden', 'true');
    window.setTimeout(function () {
      if (loader.parentNode) loader.parentNode.removeChild(loader);
    }, 400);
  }

  function dismissAfterMinimum() {
    var remaining = Math.max(0, minimumDisplayMs - (Date.now() - startedAt));
    window.setTimeout(removeLoader, remaining);
  }

  // Reveal as soon as the document is usable. Waiting for window.load kept the
  // whole screen covered while below-the-fold images and third-party map/CDN
  // assets were still downloading.
  if (document.readyState === 'interactive' || document.readyState === 'complete') {
    dismissAfterMinimum();
  } else {
    document.addEventListener('DOMContentLoaded', dismissAfterMinimum, { once: true });
  }

  window.addEventListener('pageshow', function (event) {
    if (event.persisted) removeLoader();
  }, { once: true });

  window.setTimeout(removeLoader, maximumDisplayMs);
}());
