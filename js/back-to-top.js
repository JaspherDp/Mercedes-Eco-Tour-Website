(function () {
  'use strict';
  if (!window.itourMobileScrollReady) {
    const script = document.createElement('script');
    script.src = new URL('mobile-scroll.js', document.currentScript.src).href;
    document.head.appendChild(script);
  }

  const legacyButton = document.getElementById('scroll-to-top-btn');
  if (legacyButton) legacyButton.remove();

  const button = document.createElement('button');
  button.className = 'back-to-top';
  button.type = 'button';
  button.setAttribute('aria-label', 'Back to top');
  button.setAttribute('title', 'Back to top');
  button.innerHTML = `
    <span class="back-to-top__surface" aria-hidden="true">
      <svg class="back-to-top__arrow" viewBox="0 0 24 24">
        <path d="m6 10 6-6 6 6"></path>
        <path d="M12 4v16"></path>
      </svg>
    </span>
    <svg class="back-to-top__meter" viewBox="0 0 64 64" aria-hidden="true">
      <defs>
        <linearGradient id="backToTopProgressGradient" x1="8" y1="8" x2="56" y2="56" gradientUnits="userSpaceOnUse">
          <stop offset="0" stop-color="#69d2ad"></stop>
          <stop offset=".5" stop-color="#2aa17c"></stop>
          <stop offset="1" stop-color="#137457"></stop>
        </linearGradient>
      </defs>
      <circle class="back-to-top__track" cx="32" cy="32" r="27"></circle>
      <circle class="back-to-top__progress" cx="32" cy="32" r="27"></circle>
      <g class="back-to-top__boat">
        <g class="back-to-top__boat-vessel">
          <path class="back-to-top__boat-wave" d="M-11 6.2c2.4-1.45 4.8 1.45 7.2 0s4.8 1.45 7.2 0 4.8 1.45 7.2 0"></path>
          <path class="back-to-top__boat-hull" d="M-10 0h20L6 5.5H-6z"></path>
          <path class="back-to-top__boat-mast" d="M0 0v-8.5"></path>
          <path class="back-to-top__boat-sail" d="M-1-.5v-8l-6.4 8z"></path>
          <path class="back-to-top__boat-sail" d="M1-7.7v7.2h5.7z"></path>
        </g>
      </g>
    </svg>`;

  document.body.appendChild(button);

  const progressCircle = button.querySelector('.back-to-top__progress');
  const boat = button.querySelector('.back-to-top__boat');
  const radius = 27;
  const circumference = 2 * Math.PI * radius;
  let animationFrame = 0;
  let targetProgress = 0;
  let displayedProgress = 0;

  progressCircle.style.strokeDasharray = String(circumference);
  progressCircle.style.strokeDashoffset = String(circumference);

  function render(progress) {
    const angle = progress * 360;
    const radians = angle * Math.PI / 180;
    const boatX = 32 + radius * Math.sin(radians);
    const boatY = 32 - radius * Math.cos(radians);
    progressCircle.style.strokeDashoffset = String(circumference * (1 - progress));
    boat.setAttribute('transform', `translate(${boatX} ${boatY})`);
  }

  function animateProgress() {
    const difference = targetProgress - displayedProgress;
    displayedProgress += difference * .14;

    if (Math.abs(difference) < .00035) displayedProgress = targetProgress;
    render(displayedProgress);

    if (displayedProgress !== targetProgress) {
      animationFrame = window.requestAnimationFrame(animateProgress);
    } else {
      animationFrame = 0;
    }
  }

  function updateTarget() {
    const scrollable = Math.max(document.documentElement.scrollHeight - window.innerHeight, 0);
    targetProgress = scrollable ? Math.min(Math.max(window.scrollY / scrollable, 0), 1) : 0;
    const isVisible = window.scrollY > 180 && scrollable > 0;
    button.classList.toggle('is-visible', isVisible);
    document.body.classList.toggle('back-to-top-visible', isVisible);
    if (!animationFrame) animationFrame = window.requestAnimationFrame(animateProgress);
  }

  button.addEventListener('click', function () {
    const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    window.scrollTo({ top: 0, behavior: reduceMotion ? 'auto' : 'smooth' });
  });

  window.addEventListener('scroll', updateTarget, { passive: true });
  window.addEventListener('resize', updateTarget, { passive: true });
  window.addEventListener('load', updateTarget, { once: true });
  render(0);
  updateTarget();
})();
