document.addEventListener('DOMContentLoaded', () => {
  const headerHost = document.getElementById('header');
  const loginHost = document.getElementById('loginModal');
  const gallery = document.getElementById('aboutGallery');
  const galleryItems = Array.from(document.querySelectorAll('.carousel-item'));
  const previousButton = document.getElementById('aboutGalleryPrev');
  const nextButton = document.getElementById('aboutGalleryNext');
  const modal = document.getElementById('imageModal');
  const modalImage = document.getElementById('modalImage');
  const modalTitle = document.getElementById('modalTitle');
  const modalDescription = document.getElementById('modalDesc');
  const closeButton = modal?.querySelector('.close');
  let lastTrigger = null;

  const revealElements = Array.from(document.querySelectorAll('[data-about-reveal]'));
  if ('IntersectionObserver' in window && revealElements.length) {
    document.body.classList.add('about-motion-ready');
    const revealObserver = new IntersectionObserver((entries, observer) => {
      entries.forEach((entry) => {
        if (!entry.isIntersecting) return;
        entry.target.classList.add('about-revealed');
        observer.unobserve(entry.target);
      });
    }, { threshold: 0.12, rootMargin: '0px 0px -45px' });
    revealElements.forEach((element) => revealObserver.observe(element));
  } else {
    revealElements.forEach((element) => element.classList.add('about-revealed'));
  }

  fetch('php/header.php')
    .then((response) => response.text())
    .then((html) => {
      if (!headerHost) return;
      headerHost.innerHTML = html;
      if (typeof initHeader === 'function') initHeader();

      const currentPage = location.pathname.split('/').pop().toLowerCase();
      headerHost.querySelectorAll('nav ul li a').forEach((link) => {
        const targetPage = String(link.getAttribute('href') || '').split('?')[0].toLowerCase();
        link.classList.toggle('active', targetPage === currentPage);
      });

      const toggle = headerHost.querySelector('.menu-toggle');
      const navLinks = headerHost.querySelector('nav ul');
      toggle?.addEventListener('click', () => navLinks?.classList.toggle('show'));
    })
    .catch((error) => console.error('Header load error:', error));

  const loadLoginModal = () => {
    if (!loginHost) return;
    fetch('logsign-modal.html?v=11')
      .then((response) => response.text())
      .then((html) => {
        loginHost.innerHTML = html;
        const script = document.createElement('script');
        script.src = 'logsign.js?v=11';
        script.addEventListener('load', () => {
          if (typeof initLogSignEvents === 'function') initLogSignEvents();
        });
        document.body.appendChild(script);
      })
      .catch((error) => console.error('Login modal load error:', error));
  };

  if (typeof window.Swal !== 'undefined') {
    loadLoginModal();
  } else {
    const sweetAlertScript = document.createElement('script');
    sweetAlertScript.src = 'https://cdn.jsdelivr.net/npm/sweetalert2@11';
    sweetAlertScript.addEventListener('load', loadLoginModal);
    document.body.appendChild(sweetAlertScript);
  }

  const closeStory = () => {
    if (!modal || !modal.classList.contains('show')) return;
    modal.classList.remove('show');
    modal.setAttribute('aria-hidden', 'true');
    document.body.style.overflow = '';
    window.setTimeout(() => {
      modal.style.display = 'none';
      lastTrigger?.focus();
    }, 200);
  };

  const openStory = (item) => {
    const image = item.querySelector('img');
    if (!modal || !modalImage || !modalTitle || !modalDescription || !image) return;
    lastTrigger = item;
    modalImage.src = image.currentSrc || image.src;
    modalImage.alt = item.dataset.title || image.alt || '';
    modalTitle.textContent = item.dataset.title || '';
    modalDescription.textContent = item.dataset.longdesc || item.dataset.desc || '';
    modal.style.display = 'flex';
    modal.setAttribute('aria-hidden', 'false');
    document.body.style.overflow = 'hidden';
    requestAnimationFrame(() => {
      modal.classList.add('show');
      closeButton?.focus();
    });
  };

  galleryItems.forEach((item) => item.addEventListener('click', () => openStory(item)));
  closeButton?.addEventListener('click', closeStory);
  modal?.addEventListener('click', (event) => {
    if (event.target === modal) closeStory();
  });

  const scrollGallery = (direction) => {
    if (!gallery) return;
    gallery.scrollBy({ left: direction * Math.max(290, gallery.clientWidth * 0.78), behavior: 'smooth' });
  };
  previousButton?.addEventListener('click', () => scrollGallery(-1));
  nextButton?.addEventListener('click', () => scrollGallery(1));

  if (gallery) {
    let pointerId = null;
    let startX = 0;
    let startScrollLeft = 0;
    let pendingClientX = 0;
    let dragFrame = 0;
    let dragged = false;
    let suppressClick = false;

    const paintDragPosition = () => {
      dragFrame = 0;
      gallery.scrollLeft = startScrollLeft - (pendingClientX - startX);
    };

    gallery.addEventListener('pointerdown', (event) => {
      if (event.pointerType !== 'mouse' || event.button !== 0) return;
      pointerId = event.pointerId;
      startX = event.clientX;
      pendingClientX = event.clientX;
      startScrollLeft = gallery.scrollLeft;
      dragged = false;
      suppressClick = false;
      gallery.setPointerCapture(pointerId);
      gallery.classList.add('is-dragging');
    });

    gallery.addEventListener('pointermove', (event) => {
      if (event.pointerId !== pointerId) return;
      const coalescedEvents = event.getCoalescedEvents?.();
      const latestEvent = coalescedEvents?.[coalescedEvents.length - 1] || event;
      const distance = latestEvent.clientX - startX;
      if (Math.abs(distance) > 5) dragged = true;
      if (!dragged) return;
      event.preventDefault();
      pendingClientX = latestEvent.clientX;
      if (!dragFrame) dragFrame = window.requestAnimationFrame(paintDragPosition);
    });

    const finishDrag = (event) => {
      if (event.pointerId !== pointerId) return;
      suppressClick = event.type === 'pointerup' && dragged;
      if (dragFrame) {
        window.cancelAnimationFrame(dragFrame);
        paintDragPosition();
      }
      if (gallery.hasPointerCapture(pointerId)) gallery.releasePointerCapture(pointerId);
      pointerId = null;
      dragged = false;
      gallery.classList.remove('is-dragging');
    };

    gallery.addEventListener('pointerup', finishDrag);
    gallery.addEventListener('pointercancel', finishDrag);
    gallery.addEventListener('lostpointercapture', () => {
      if (dragFrame) window.cancelAnimationFrame(dragFrame);
      dragFrame = 0;
      pointerId = null;
      dragged = false;
      gallery.classList.remove('is-dragging');
    });
    gallery.addEventListener('click', (event) => {
      if (!suppressClick) return;
      event.preventDefault();
      event.stopImmediatePropagation();
      suppressClick = false;
    }, true);
    gallery.addEventListener('dragstart', (event) => event.preventDefault());
  }

  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') closeStory();
    if (!modal?.classList.contains('show') && document.activeElement === gallery) {
      if (event.key === 'ArrowLeft') scrollGallery(-1);
      if (event.key === 'ArrowRight') scrollGallery(1);
    }
  });

  const scrollButton = document.getElementById('scroll-to-top-btn');
  if (scrollButton) {
    const updateScrollButton = () => scrollButton.classList.toggle('show', window.scrollY > 180);
    window.addEventListener('scroll', updateScrollButton, { passive: true });
    scrollButton.addEventListener('click', () => window.scrollTo({ top: 0, behavior: 'smooth' }));
    updateScrollButton();
  }
});
