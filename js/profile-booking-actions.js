(() => {
  const dropdowns = Array.from(document.querySelectorAll('.profile-booking-dropdown'));
  let active = null;
  const icon = paths => `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">${paths}</svg>`;

  function close(restoreFocus = false) {
    if (!active) return;
    const dropdown = active;
    active = null;
    dropdown.querySelector('.profile-booking-menu').hidden = true;
    const toggle = dropdown.querySelector('.profile-booking-toggle');
    toggle.setAttribute('aria-expanded', 'false');
    if (restoreFocus) toggle.focus();
  }

  function open(dropdown, focusFirst = false) {
    close();
    const menu = dropdown.querySelector('.profile-booking-menu');
    const toggle = dropdown.querySelector('.profile-booking-toggle');
    active = dropdown;
    menu.hidden = false;
    toggle.setAttribute('aria-expanded', 'true');
    const bounds = toggle.getBoundingClientRect();
    const edge = 12;
    menu.style.width = `${Math.min(230, window.innerWidth - edge * 2)}px`;
    menu.style.maxHeight = `${Math.max(80, window.innerHeight - edge * 2)}px`;
    const rect = menu.getBoundingClientRect();
    const below = window.innerHeight - bounds.bottom - edge - 7;
    const top = below < rect.height && bounds.top > rect.height + edge + 7
      ? bounds.top - rect.height - 7 : Math.min(bounds.bottom + 7, window.innerHeight - rect.height - edge);
    menu.style.top = `${Math.max(edge, top)}px`;
    menu.style.left = `${Math.max(edge, Math.min(bounds.right - rect.width, window.innerWidth - rect.width - edge))}px`;
    if (focusFirst) menu.querySelector('button')?.focus();
  }

  dropdowns.forEach((dropdown, index) => {
    const toggle = dropdown.querySelector('.profile-booking-toggle');
    const menu = dropdown.querySelector('.profile-booking-menu');
    menu.id = `profile-booking-menu-${index}`;
    toggle.setAttribute('aria-controls', menu.id);
    menu.querySelectorAll('button').forEach(button => {
      button.setAttribute('role', 'menuitem');
      if (button.classList.contains('billing-btn-user')) {
        button.insertAdjacentHTML('afterbegin', icon('<rect x="3" y="4" width="18" height="16" rx="2"/><path d="M7 9h10M7 13h6M7 17h4"/>'));
      } else if (button.classList.contains('cancel-booking-btn')) {
        button.textContent = 'Cancel Booking';
        button.insertAdjacentHTML('afterbegin', icon('<circle cx="12" cy="12" r="9"/><path d="m9 9 6 6m0-6-6 6"/>'));
      }
    });
    toggle.addEventListener('click', () => active === dropdown ? close() : open(dropdown));
    toggle.addEventListener('keydown', event => {
      if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
        event.preventDefault();
        event.stopPropagation();
        open(dropdown, true);
        if (event.key === 'ArrowUp') Array.from(menu.querySelectorAll('button')).at(-1)?.focus();
      }
    });
    menu.addEventListener('click', event => { if (event.target.closest('button')) close(); });
  });

  document.addEventListener('click', event => {
    if (active && !active.contains(event.target)) close();
  });
  document.addEventListener('keydown', event => {
    if (!active) return;
    if (event.key === 'Escape') {
      event.preventDefault();
      close(true);
    } else if (event.key === 'Tab') {
      close(true);
    } else if (['ArrowDown', 'ArrowUp', 'Home', 'End'].includes(event.key)) {
      event.preventDefault();
      const buttons = Array.from(active.querySelectorAll('.profile-booking-menu button'));
      const current = buttons.indexOf(document.activeElement);
      const index = event.key === 'Home' ? 0 : event.key === 'End' ? buttons.length - 1
        : (current + (event.key === 'ArrowDown' ? 1 : -1) + buttons.length) % buttons.length;
      buttons[index]?.focus();
    }
  });
  document.addEventListener('scroll', event => {
    if (active && !active.querySelector('.profile-booking-menu').contains(event.target)) close();
  }, true);
  document.addEventListener('input', () => close());
  document.addEventListener('change', () => close());
  window.addEventListener('resize', () => close());
})();
