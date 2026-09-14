(function () {
  "use strict";

  const escapeHtml = (value) => String(value ?? "").replaceAll("Â·", "·")
    .replaceAll("&", "&amp;")
    .replaceAll("<", "&lt;")
    .replaceAll(">", "&gt;")
    .replaceAll('"', "&quot;")
    .replaceAll("'", "&#039;");

  function loadLoginModal() {
    const container = document.getElementById("loginModal");
    if (!container) return;

    fetch("logsign-modal.html?v=11")
      .then((response) => {
        if (!response.ok) throw new Error("Unable to load the login form.");
        return response.text();
      })
      .then((html) => {
        container.innerHTML = html;
        const loadAuthScript = () => {
          const script = document.createElement("script");
          script.src = "logsign.js?v=11";
          script.onload = () => {
          if (typeof window.initLogSignEvents === "function") window.initLogSignEvents();
          const params = new URLSearchParams(window.location.search);
          if (params.get("open_login") === "1") {
            document.getElementById("openModalBtn")?.click();
            params.delete("open_login");
            const query = params.toString();
            window.history.replaceState({}, "", `${window.location.pathname}${query ? `?${query}` : ""}${window.location.hash}`);
          }
          };
          document.body.appendChild(script);
        };

        if (window.Swal) {
          loadAuthScript();
        } else {
          const sweetAlertScript = document.createElement("script");
          sweetAlertScript.src = "https://cdn.jsdelivr.net/npm/sweetalert2@11";
          sweetAlertScript.onload = loadAuthScript;
          document.body.appendChild(sweetAlertScript);
        }
      })
      .catch((error) => console.error(error));
  }

  function loadHeader() {
    const target = document.getElementById("header");
    if (!target) return;

    fetch("php/header.php")
      .then((response) => {
        if (!response.ok) throw new Error("Unable to load the site navigation.");
        return response.text();
      })
      .then((html) => {
        target.innerHTML = html;
        if (typeof window.initHeader === "function") window.initHeader();
        initMobileNavDocking();
        loadLoginModal();
      })
      .catch((error) => console.error(error));
  }

  function initMobileNavDocking() {
    const heroNav = document.querySelector(".hero-mobile-nav");
    const subnav = document.querySelector(".head-subnav");
    if (!heroNav || !subnav || document.body.dataset.mobileDockReady === "true") return;

    document.body.dataset.mobileDockReady = "true";
    const mobileQuery = window.matchMedia("(max-width: 480px)");
    let frame = 0;

    const update = () => {
      frame = 0;
      const shouldDock = mobileQuery.matches
        && heroNav.getBoundingClientRect().bottom <= subnav.getBoundingClientRect().bottom + 4;
      document.body.classList.toggle("mobile-quick-nav-docked", shouldDock);
    };

    const scheduleUpdate = () => {
      if (!frame) frame = window.requestAnimationFrame(update);
    };

    window.addEventListener("scroll", scheduleUpdate, { passive: true });
    window.addEventListener("resize", scheduleUpdate, { passive: true });
    mobileQuery.addEventListener?.("change", scheduleUpdate);
    update();
  }

  function initHeroVisual() {
    const visual = document.querySelector("[data-hero-visual]");
    if (!visual) return;

    const summary = visual.querySelector(".hero-visual-card");
    const scene = visual.querySelector(".hero-floating-scene");
    const image = document.getElementById("heroVisualImage");
    const title = document.getElementById("heroVisualTitle");
    const location = document.getElementById("heroVisualLocation");
    const action = visual.querySelector(".hero-visual-action");
    const options = Array.from(visual.querySelectorAll(".hero-destination-option"));
    const reduceMotion = window.matchMedia("(prefers-reduced-motion: reduce)").matches;
    if (!summary || !image || !options.length) return;

    let activeIndex = 0;
    let autoTimer = 0;
    let paused = false;

    options.forEach((option) => {
      const preload = new Image();
      preload.src = option.dataset.image || "";
    });

    const restartProgress = () => {
      visual.classList.remove("is-auto-playing");
      void visual.offsetWidth;
      if (!paused && !reduceMotion) visual.classList.add("is-auto-playing");
    };

    const scheduleNext = () => {
      window.clearTimeout(autoTimer);
      restartProgress();
      if (!paused && !reduceMotion) {
        autoTimer = window.setTimeout(() => selectDestination((activeIndex + 1) % options.length), 5500);
      }
    };

    const selectDestination = (index, moveFocus = false) => {
      activeIndex = (index + options.length) % options.length;
      const selected = options[activeIndex];
      visual.dataset.activeStop = String(activeIndex);

      options.forEach((option, optionIndex) => {
        const active = optionIndex === activeIndex;
        option.classList.toggle("active", active);
        option.setAttribute("aria-selected", active ? "true" : "false");
        option.tabIndex = active ? 0 : -1;
      });

      summary.classList.add("is-changing");
      window.setTimeout(() => {
        image.src = selected.dataset.image || image.src;
        image.alt = selected.dataset.alt || selected.dataset.title || "Mercedes island destination";
        if (title) title.textContent = selected.dataset.title || "Mercedes Island";
        if (location) location.textContent = selected.dataset.location || "Mercedes, Camarines Norte";
        if (action) action.setAttribute("aria-label", `Explore ${selected.dataset.title || "this destination"}`);
        window.requestAnimationFrame(() => summary.classList.remove("is-changing"));
      }, reduceMotion ? 0 : 170);

      if (moveFocus) selected.focus();
      scheduleNext();
    };

    options.forEach((option, index) => {
      option.addEventListener("click", () => selectDestination(index));
      option.addEventListener("keydown", (event) => {
        if (event.key !== "ArrowRight" && event.key !== "ArrowLeft") return;
        event.preventDefault();
        selectDestination(activeIndex + (event.key === "ArrowRight" ? 1 : -1), true);
      });
    });

    const pauseAuto = () => {
      paused = true;
      window.clearTimeout(autoTimer);
      visual.classList.remove("is-auto-playing");
    };
    const resumeAuto = () => {
      paused = false;
      scheduleNext();
    };

    visual.addEventListener("mouseenter", pauseAuto);
    visual.addEventListener("mouseleave", resumeAuto);
    visual.addEventListener("focusin", pauseAuto);
    visual.addEventListener("focusout", (event) => {
      if (!visual.contains(event.relatedTarget)) resumeAuto();
    });

    if (!reduceMotion && scene) {
      visual.addEventListener("pointermove", (event) => {
        if (event.pointerType === "touch") return;
        const bounds = visual.getBoundingClientRect();
        const rotateY = ((event.clientX - bounds.left) / bounds.width - .5) * 4.5;
        const rotateX = -((event.clientY - bounds.top) / bounds.height - .5) * 3.5;
        scene.classList.add("is-interacting");
        summary.style.transform = `rotateX(${rotateX.toFixed(2)}deg) rotateY(${rotateY.toFixed(2)}deg)`;
      });
      visual.addEventListener("pointerleave", () => {
        scene.classList.remove("is-interacting");
        summary.style.transform = "";
      });
    }

    selectDestination(0);
  }

  function initGallery() {
    const slides = Array.from(document.querySelectorAll(".fe-slide"));
    const dots = Array.from(document.querySelectorAll(".fe-dots button"));
    const modal = document.getElementById("feModal");
    const modalImage = document.getElementById("feModalImg");
    const closeButton = modal?.querySelector(".fe-close");
    if (!slides.length) return;

    let current = 0;
    let intervalId = 0;
    let lastFocused = null;

    const show = (index) => {
      current = (index + slides.length) % slides.length;
      slides.forEach((slide, slideIndex) => {
        const active = slideIndex === current;
        slide.classList.toggle("active", active);
        slide.tabIndex = active ? 0 : -1;
        slide.setAttribute("aria-hidden", active ? "false" : "true");
      });
      dots.forEach((dot, dotIndex) => {
        const active = dotIndex === current;
        dot.classList.toggle("active", active);
        dot.setAttribute("aria-selected", active ? "true" : "false");
      });
    };

    const stop = () => window.clearInterval(intervalId);
    const start = () => {
      stop();
      if (!window.matchMedia("(prefers-reduced-motion: reduce)").matches) {
        intervalId = window.setInterval(() => show(current + 1), 4500);
      }
    };

    const openModal = (imageUrl, trigger) => {
      if (!modal || !modalImage || !imageUrl) return;
      lastFocused = trigger;
      stop();
      modalImage.src = imageUrl;
      modal.hidden = false;
      document.body.style.overflow = "hidden";
      closeButton?.focus();
    };

    const closeModal = () => {
      if (!modal || modal.hidden) return;
      modal.hidden = true;
      modalImage?.removeAttribute("src");
      document.body.style.overflow = "";
      start();
      lastFocused?.focus();
    };

    dots.forEach((dot, index) => dot.addEventListener("click", () => {
      show(index);
      start();
    }));

    document.querySelectorAll("[data-gallery-image]").forEach((trigger) => {
      trigger.addEventListener("click", () => openModal(trigger.dataset.galleryImage, trigger));
    });

    closeButton?.addEventListener("click", closeModal);
    modal?.addEventListener("click", (event) => {
      if (event.target === modal) closeModal();
    });
    document.addEventListener("keydown", (event) => {
      if (event.key === "Escape") closeModal();
    });

    document.querySelector(".fe-slider")?.addEventListener("mouseenter", stop);
    document.querySelector(".fe-slider")?.addEventListener("mouseleave", start);
    show(0);
    start();
  }

  function initTourCategories() {
    const section = document.querySelector(".tour-categories");
    const viewport = section?.querySelector("[data-tour-category-viewport]");
    const cards = Array.from(section?.querySelectorAll("[data-tour-category-card]") || []);
    const dots = Array.from(section?.querySelectorAll("[data-tour-category-index]") || []);
    const arrows = Array.from(section?.querySelectorAll("[data-tour-category-direction]") || []);
    if (!section || !viewport || !cards.length) return;

    let current = 0;
    let intervalId = 0;

    const select = (index, shouldScroll = false) => {
      current = (index + cards.length) % cards.length;
      cards.forEach((card, cardIndex) => {
        const active = cardIndex === current;
        card.classList.toggle("is-active", active);
        if (active) card.setAttribute("aria-current", "true");
        else card.removeAttribute("aria-current");
      });
      dots.forEach((dot, dotIndex) => {
        const active = dotIndex === current;
        dot.classList.toggle("is-active", active);
        dot.setAttribute("aria-selected", active ? "true" : "false");
      });

      if (shouldScroll && viewport.scrollWidth > viewport.clientWidth) {
        const card = cards[current];
        viewport.scrollTo({
          left: card.offsetLeft - (viewport.clientWidth - card.offsetWidth) / 2,
          behavior: "smooth"
        });
      }
    };

    const stop = () => window.clearInterval(intervalId);
    const start = () => {
      stop();
      if (!window.matchMedia("(prefers-reduced-motion: reduce)").matches) {
        intervalId = window.setInterval(() => select(current + 1, viewport.scrollWidth > viewport.clientWidth), 3800);
      }
    };

    cards.forEach((card, index) => {
      card.addEventListener("pointerenter", () => { stop(); select(index); });
      card.addEventListener("focus", () => { stop(); select(index, true); });
    });
    dots.forEach((dot, index) => dot.addEventListener("click", () => { select(index, true); start(); }));
    arrows.forEach((arrow) => arrow.addEventListener("click", () => {
      select(current + Number(arrow.dataset.tourCategoryDirection || 0), true);
      start();
    }));
    section.addEventListener("pointerleave", start);
    section.addEventListener("focusout", (event) => {
      if (!section.contains(event.relatedTarget)) start();
    });
    document.addEventListener("visibilitychange", () => document.hidden ? stop() : start());

    select(0);
    start();
  }

  function recentHref(item) {
    const supplied = String(item.href || "").trim();
    if (supplied) {
      try {
        const url = new URL(supplied, window.location.href);
        if (url.protocol === "http:" || url.protocol === "https:") return url.href;
      } catch (error) {
        // Use the known item-type route below when a stored URL is malformed.
      }
    }

    const id = encodeURIComponent(item.id || "");
    const type = String(item.type || "").toLowerCase();
    if (type === "hotel" || type === "resort") return `hotel_details.php?id=${id}`;
    if (type === "package" || type === "tour" || type === "tours") return `package_details.php?package_id=${id}`;
    return "hotel_resorts.php";
  }

  function typeLabel(type) {
    const labels = {
      hotel: "Stay",
      resort: "Resort",
      package: "Tour",
      tour: "Tour",
      tours: "Tour",
      guide: "Guide",
      boat: "Boat"
    };
    return labels[String(type || "").toLowerCase()] || "Explore";
  }

  function categoryLabel(type) {
    const labels = {
      hotel: "Hotel",
      resort: "Resort",
      package: "Tour package",
      tour: "Tour package",
      tours: "Tour package",
      guide: "Tour guide",
      boat: "Boat"
    };
    return labels[String(type || "").toLowerCase()] || "Travel service";
  }

  function renderRatingSummary(ratingValue, reviewCountValue) {
    const rating = Math.min(5, Math.max(0, Number(ratingValue) || 0));
    const reviewCount = Math.max(0, Number(reviewCountValue) || 0);
    const fill = (rating / 5) * 100;
    const summary = reviewCount > 0
      ? `${rating.toFixed(1)} (${reviewCount.toLocaleString()} review${reviewCount === 1 ? "" : "s"})`
      : rating > 0
        ? `${rating.toFixed(1)} guest rating`
        : "No reviews yet";

    return `
      <div class="recent-rating" aria-label="${summary}">
        <span class="recent-stars" aria-hidden="true">
          <span class="recent-stars-base">★★★★★</span>
          <span class="recent-stars-fill" style="width:${fill}%">★★★★★</span>
        </span>
        <span class="recent-review-summary">${summary}</span>
      </div>`;
  }

  function renderRecentlyViewed() {
    const section = document.getElementById("recentlyViewedSection");
    const track = document.getElementById("recentlyViewedTrack");
    const kicker = document.getElementById("recentKicker");
    const title = document.getElementById("recentTitle");
    if (!section || !track || !window.RecentlyViewed) return;

    const items = window.RecentlyViewed.getAll().map((item) => {
      const summary = window.homeReviewSummaries?.[item.key];
      if (!summary) return item;
      return {
        ...item,
        rating: Number(summary.rating || 0),
        reviewCount: Number(summary.reviewCount || 0)
      };
    });
    if (!items.length) {
      if (kicker) kicker.textContent = "Popular with travelers";
      if (title) title.textContent = "Popular tours";
      track.setAttribute("aria-label", "Popular tours");
      track.querySelectorAll("[data-popular-tour]").forEach((link) => {
        link.addEventListener("click", () => {
          try {
            window.RecentlyViewed.add(JSON.parse(link.dataset.popularTour || "{}"));
          } catch (error) {
            // Navigation should continue if a fallback card has malformed data.
          }
        });
      });
      section.hidden = false;
      return;
    }

    if (kicker) kicker.textContent = "Continue exploring";
    if (title) title.textContent = "Recently viewed";
    track.setAttribute("aria-label", "Recently viewed items");

    track.innerHTML = items.map((item) => {
      const rating = Number(item.rating || 0);
      const price = Number(item.price || 0);
      let priceMarkup = price > 0
        ? `<strong>₱${price.toLocaleString()} <small>${escapeHtml(item.priceUnit || "")}</small></strong>`
        : "<strong>View details</strong>";
      priceMarkup = price > 0
        ? `<span class="recent-price"><small>Starting from</small><strong>&#8369;${price.toLocaleString()} <em>${escapeHtml(item.priceUnit || "")}</em></strong></span>`
        : "<span class=\"recent-price\"><strong>View details</strong></span>";
      const ratingMarkup = renderRatingSummary(rating, item.reviewCount || 0);

      return `
        <article class="recent-card">
          <a href="${escapeHtml(recentHref(item))}" data-recent-key="${escapeHtml(item.key)}">
            <div class="recent-image">
              <img src="${escapeHtml(item.image || "img/sampleimage.png")}" alt="${escapeHtml(item.name || "Recently viewed item")}" loading="lazy">
              <span class="recent-type">${escapeHtml(typeLabel(item.type))}</span>
            </div>
            <div class="recent-copy">
              <h3>${escapeHtml(item.name || "Recently viewed")}</h3>
              <span class="recent-category">${escapeHtml(categoryLabel(item.type))}</span>
              <p class="recent-location"><span aria-hidden="true">&#9906;</span> Mercedes, Camarines Norte</p>
              <div class="recent-facts" aria-label="Item details">
                <span><i aria-hidden="true">&#9679;</i>${escapeHtml(typeLabel(item.type))}</span>
                <span><i aria-hidden="true">&#9716;</i>${escapeHtml(item.subtitle || "Details available")}</span>
              </div>
              ${ratingMarkup}
              <div class="recent-meta"><span class="recent-card-status">Recently viewed</span>${priceMarkup}</div>
              <span class="recent-view-link">View details <i aria-hidden="true">→</i></span>
            </div>
          </a>
        </article>`;
    }).join("");

    track.querySelectorAll("[data-recent-key]").forEach((link) => {
      link.addEventListener("click", () => {
        const item = items.find((entry) => entry.key === link.dataset.recentKey);
        if (item) window.RecentlyViewed.add(item);
      });
    });
    section.hidden = false;
  }

  function initRecentlyViewedControls() {
    const track = document.getElementById("recentlyViewedTrack");
    document.querySelectorAll("[data-recent-direction]").forEach((button) => {
      button.addEventListener("click", () => {
        if (!track) return;
        const card = track.querySelector(".recent-card");
        const distance = (card?.getBoundingClientRect().width || 285) + 18;
        track.scrollBy({ left: distance * Number(button.dataset.recentDirection || 1), behavior: "smooth" });
      });
    });

    if (!track) return;

    let pointerId = null;
    let startX = 0;
    let startScrollLeft = 0;
    let dragged = false;
    let suppressClick = false;
    let pendingClientX = 0;
    let dragFrame = 0;

    const paintDragPosition = () => {
      dragFrame = 0;
      track.scrollLeft = startScrollLeft - (pendingClientX - startX);
    };

    track.addEventListener("pointerdown", (event) => {
      if (event.pointerType !== "mouse" || event.button !== 0) return;
      pointerId = event.pointerId;
      startX = event.clientX;
      startScrollLeft = track.scrollLeft;
      pendingClientX = event.clientX;
      dragged = false;
      suppressClick = false;
      track.setPointerCapture(pointerId);
      track.classList.add("is-dragging");
    });

    track.addEventListener("pointermove", (event) => {
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
      suppressClick = event.type === "pointerup" && dragged;
      if (dragFrame) {
        window.cancelAnimationFrame(dragFrame);
        paintDragPosition();
      }
      if (track.hasPointerCapture(pointerId)) track.releasePointerCapture(pointerId);
      pointerId = null;
      dragged = false;
      track.classList.remove("is-dragging");
    };

    track.addEventListener("pointerup", finishDrag);
    track.addEventListener("pointercancel", finishDrag);
    track.addEventListener("lostpointercapture", () => {
      if (dragFrame) window.cancelAnimationFrame(dragFrame);
      dragFrame = 0;
      pointerId = null;
      dragged = false;
      track.classList.remove("is-dragging");
    });
    track.addEventListener("click", (event) => {
      if (!suppressClick) return;
      event.preventDefault();
      event.stopImmediatePropagation();
      suppressClick = false;
    }, true);
    track.addEventListener("dragstart", (event) => event.preventDefault());
  }

  function initHomepageReveals() {
    const revealElements = Array.from(document.querySelectorAll("[data-home-reveal]"));
    if (!revealElements.length) return;

    const reduceMotion = window.matchMedia("(prefers-reduced-motion: reduce)").matches;
    if (reduceMotion || !("IntersectionObserver" in window)) {
      revealElements.forEach((element) => element.classList.add("home-revealed"));
      return;
    }

    document.body.classList.add("home-motion-ready");
    const observer = new IntersectionObserver((entries, revealObserver) => {
      entries.forEach((entry) => {
        if (!entry.isIntersecting) return;
        entry.target.classList.add("home-revealed");
        revealObserver.unobserve(entry.target);
      });
    }, { threshold: 0.1, rootMargin: "0px 0px -45px" });

    revealElements.forEach((element) => observer.observe(element));
  }

  function initHeroSearchAutocomplete() {
    const form = document.querySelector(".hero-search");
    const input = document.getElementById("heroDestinationSearch");
    const panel = document.getElementById("heroSearchSuggestions");
    if (!form || !input || !panel) return;

    const storageKey = "itourMercedesRecentDestinationSearches";
    const destinations = [
      { label: "Apuao Pequeña", subtitle: "Island destination · Mercedes" },
      { label: "Apuao Grande", subtitle: "Island destination · Mercedes" },
      { label: "Quinapaguian Island", subtitle: "Island destination · Mercedes" },
      { label: "Canimog Island", subtitle: "Island destination · Mercedes" },
      { label: "Caringo Island", subtitle: "Island destination · Mercedes" },
      { label: "Malasugui Island", subtitle: "Island destination · Mercedes" },
      { label: "Canton Island", subtitle: "Island destination · Mercedes" }
    ];
    const historyIcon = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3.5 12a8.5 8.5 0 1 0 2.1-5.6"></path><path d="M3.5 4.5v4h4"></path><path d="M12 7.5V12l3 2"></path></svg>';
    const locationIcon = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20 10c0 5-8 11-8 11S4 15 4 10a8 8 0 1 1 16 0Z"></path><circle cx="12" cy="10" r="2.5"></circle></svg>';
    let options = [];
    let activeIndex = -1;
    let fitTimer = 0;

    const normalize = (value) => String(value || "").normalize("NFD").replace(/[\u0300-\u036f]/g, "").toLowerCase().trim();
    const readRecent = () => {
      try {
        const stored = JSON.parse(localStorage.getItem(storageKey) || "[]");
        return Array.isArray(stored) ? stored.filter((value) => typeof value === "string" && value.trim()).slice(0, 2) : [];
      } catch (error) {
        return [];
      }
    };
    const saveRecent = (value) => {
      const cleanValue = String(value || "").trim();
      if (!cleanValue) return;
      const normalizedValue = normalize(cleanValue);
      const next = [cleanValue, ...readRecent().filter((item) => normalize(item) !== normalizedValue)].slice(0, 2);
      try {
        localStorage.setItem(storageKey, JSON.stringify(next));
      } catch (error) {
        // Search should continue when storage is unavailable or disabled.
      }
    };

    const createSection = (title, items, kind) => {
      if (!items.length) return null;
      const section = document.createElement("section");
      section.className = "hero-suggestion-section";
      const heading = document.createElement("h3");
      heading.className = "hero-suggestion-heading";
      heading.textContent = title;
      section.appendChild(heading);

      items.forEach((item) => {
        const value = typeof item === "string" ? item : item.label;
        const subtitle = typeof item === "string" ? "Previously searched destination" : item.subtitle;
        const button = document.createElement("button");
        button.type = "button";
        button.className = "hero-suggestion-option";
        button.setAttribute("role", "option");
        button.setAttribute("aria-selected", "false");
        button.dataset.value = value;
        button.innerHTML = `<span class="hero-suggestion-icon">${kind === "recent" ? historyIcon : locationIcon}</span><span class="hero-suggestion-copy"><strong></strong><small></small></span>`;
        button.querySelector("strong").textContent = value;
        button.querySelector("small").textContent = subtitle;
        button.addEventListener("mousedown", (event) => event.preventDefault());
        button.addEventListener("click", () => selectOption(button));
        section.appendChild(button);
      });
      return section;
    };

    const closePanel = () => {
      panel.hidden = true;
      panel.style.removeProperty("max-height");
      input.setAttribute("aria-expanded", "false");
      input.removeAttribute("aria-activedescendant");
      activeIndex = -1;
    };
    const fitPanelInViewport = (behavior = "smooth") => {
      if (panel.hidden) return;
      // Measure the newly rendered menu at its natural size. Otherwise a
      // max-height left by a shorter query permanently constrains the menu.
      panel.style.removeProperty("max-height");
      const formRect = form.getBoundingClientRect();
      const panelRect = panel.getBoundingClientRect();
      const fixedNavigation = Array.from(document.querySelectorAll(".head-nav-main-header, .head-subnav"));
      const safeTop = fixedNavigation.reduce((bottom, element) => {
        const rect = element.getBoundingClientRect();
        return rect.position === "fixed" || getComputedStyle(element).position === "fixed"
          ? Math.max(bottom, rect.bottom)
          : bottom;
      }, 0) + 12;
      const visibleHeight = window.visualViewport?.height || window.innerHeight;
      const visibleBottom = visibleHeight - 14;
      const panelGap = 9;
      const idealTop = Math.max(safeTop, visibleBottom - formRect.height - panelRect.height - panelGap);
      const availablePanelHeight = Math.max(180, visibleBottom - Math.max(safeTop, idealTop) - formRect.height - panelGap);
      panel.style.maxHeight = `${Math.min(410, availablePanelHeight)}px`;

      const refreshedPanelHeight = panel.getBoundingClientRect().height;
      const fittedTop = Math.max(safeTop, visibleBottom - formRect.height - refreshedPanelHeight - panelGap);
      const delta = formRect.top < safeTop
        ? formRect.top - safeTop
        : Math.max(0, formRect.top - fittedTop);
      if (Math.abs(delta) > 7) {
        window.scrollBy({ top: delta, behavior });
      }
    };
    const scheduleViewportFit = () => {
      window.clearTimeout(fitTimer);
      window.requestAnimationFrame(() => fitPanelInViewport("smooth"));
      fitTimer = window.setTimeout(() => fitPanelInViewport("smooth"), 280);
    };
    const refreshOptions = () => {
      options = Array.from(panel.querySelectorAll(".hero-suggestion-option"));
      options.forEach((option, index) => {
        option.id = `heroSuggestion${index}`;
        option.classList.remove("is-active");
        option.setAttribute("aria-selected", "false");
      });
      activeIndex = -1;
    };
    const openPanel = () => {
      panel.hidden = false;
      input.setAttribute("aria-expanded", "true");
      scheduleViewportFit();
    };
    const selectOption = (option) => {
      input.value = option.dataset.value || "";
      closePanel();
      input.focus();
    };
    const setActive = (index) => {
      if (!options.length) return;
      activeIndex = (index + options.length) % options.length;
      options.forEach((option, optionIndex) => {
        const active = optionIndex === activeIndex;
        option.classList.toggle("is-active", active);
        option.setAttribute("aria-selected", active ? "true" : "false");
      });
      input.setAttribute("aria-activedescendant", options[activeIndex].id);
      options[activeIndex].scrollIntoView({ block: "nearest" });
    };

    const render = () => {
      const query = normalize(input.value);
      panel.style.removeProperty("max-height");
      panel.scrollTop = 0;
      panel.replaceChildren();
      if (!query) {
        const recentSection = createSection("Your recent searches", readRecent(), "recent");
        if (recentSection) panel.appendChild(recentSection);
        const suggestedSection = createSection("Suggested destinations", destinations.slice(0, 5), "destination");
        if (suggestedSection) panel.appendChild(suggestedSection);
      } else {
        const prefixMatches = destinations.filter((destination) => normalize(destination.label).split(/\s+/).some((word) => word.startsWith(query)));
        const matches = prefixMatches.length
          ? prefixMatches
          : destinations.filter((destination) => normalize(destination.label).includes(query));
        const suggestedSection = createSection("Suggested destinations", matches.slice(0, 6), "destination");
        if (suggestedSection) {
          panel.appendChild(suggestedSection);
        } else {
          const empty = document.createElement("p");
          empty.className = "hero-suggestion-empty";
          empty.textContent = `No destination suggestions for “${input.value.trim()}”. You can still search this term.`;
          panel.appendChild(empty);
        }
      }
      refreshOptions();
      openPanel();
    };

    input.addEventListener("focus", render);
    input.addEventListener("input", render);
    input.addEventListener("keydown", (event) => {
      if (event.key === "ArrowDown") {
        event.preventDefault();
        if (panel.hidden) render();
        setActive(activeIndex + 1);
      } else if (event.key === "ArrowUp") {
        event.preventDefault();
        if (panel.hidden) render();
        setActive(activeIndex - 1);
      } else if (event.key === "Enter" && activeIndex >= 0) {
        event.preventDefault();
        selectOption(options[activeIndex]);
      } else if (event.key === "Escape") {
        closePanel();
      }
    });
    form.addEventListener("submit", () => {
      saveRecent(input.value);
      closePanel();
    });
    document.addEventListener("pointerdown", (event) => {
      if (!form.contains(event.target)) closePanel();
    });
    window.addEventListener("resize", () => {
      if (!panel.hidden) scheduleViewportFit();
    });
    window.visualViewport?.addEventListener("resize", () => {
      if (!panel.hidden) scheduleViewportFit();
    });
  }

  document.addEventListener("DOMContentLoaded", () => {
    loadHeader();
    initHeroVisual();
    initGallery();
    initTourCategories();
    renderRecentlyViewed();
    initRecentlyViewedControls();
    initHomepageReveals();
    initHeroSearchAutocomplete();
  });
})();
