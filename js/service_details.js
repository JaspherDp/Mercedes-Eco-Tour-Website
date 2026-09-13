(function () {
  const config = window.ServiceDetailsConfig;
  if (!config || !Array.isArray(config.items) || !config.items.length) return;

  const type = config.type === "guide" ? "guide" : "boat";
  const isBoat = type === "boat";
  const favorites = config.favoriteIds || {};
  let activeItem = null;
  let images = [];
  let imageIndex = 0;

  const byId = (id) => document.getElementById(id);
  const escapeText = (value) => String(value == null ? "" : value)
    .replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;")
    .replace(/"/g, "&quot;").replace(/'/g, "&#039;");
  const iconStat = (icon, label, value) => `
    <div class="service-profile-stat">
      <span aria-hidden="true"><i class="${icon}"></i></span>
      <small>${label}</small><strong>${escapeText(value)}</strong>
    </div>`;

  function imagePath(raw) {
    let src = String(raw || "").trim().replace(/\\/g, "/").replace(/^\/+/, "");
    if (!src) return config.fallback;
    if (/^(https?:|data:)/i.test(src)) return src;
    if (src.startsWith("upload/")) return `php/${src}`;
    if (["uploads/", "php/upload/", "img/", "imagess/"].some((folder) => src.startsWith(folder))) {
      return isBoat && /\.(png|jpe?g)$/i.test(src) ? src.replace(/\.(png|jpe?g)$/i, ".optimized.webp") : src;
    }
    src = `uploads/${src}`;
    return isBoat && /\.(png|jpe?g)$/i.test(src) ? src.replace(/\.(png|jpe?g)$/i, ".optimized.webp") : src;
  }

  function formatPrice(value) {
    return `₱${Number(value || 0).toLocaleString("en-PH")}`;
  }

  function showImage(nextIndex) {
    const gallery = byId("serviceProfileGallery");
    const mainImage = byId("serviceMainImage");
    if (!gallery || !mainImage || !images.length) return;
    imageIndex = (nextIndex + images.length) % images.length;
    gallery.classList.add("is-changing");
    window.setTimeout(() => {
      mainImage.src = images[imageIndex];
      mainImage.alt = `${activeItem.name || "Tour service"} image ${imageIndex + 1}`;
      mainImage.onerror = () => { mainImage.onerror = null; mainImage.src = config.fallback; };
      gallery.classList.remove("is-changing");
    }, 120);
    byId("serviceImageCount").textContent = `${imageIndex + 1} / ${images.length}`;
    byId("serviceImageDots").querySelectorAll("button").forEach((dot, index) => {
      dot.classList.toggle("active", index === imageIndex);
      dot.setAttribute("aria-current", index === imageIndex ? "true" : "false");
    });
  }

  function render(item, updateHistory) {
    if (!item) return;
    activeItem = item;
    const rawImages = Array.isArray(item.images) && item.images.length ? item.images : [item.img];
    images = [...new Set(rawImages.map(imagePath).filter(Boolean))];
    if (!images.length) images = [config.fallback];
    imageIndex = 0;

    let activeCard = null;
    document.querySelectorAll(".service-option-card").forEach((card) => {
      const active = Number(card.dataset.serviceId) === Number(item.id);
      card.classList.toggle("is-active", active);
      card.setAttribute("aria-current", active ? "true" : "false");
      if (active) activeCard = card;
    });
    if (!updateHistory && activeCard) {
      window.requestAnimationFrame(() => activeCard.scrollIntoView({ block: "nearest", inline: "nearest" }));
    }
    byId("serviceName").textContent = item.name || (isBoat ? "Tour Boat" : "Tour Guide");
    byId("serviceRating").textContent = `${Number(item.rating || 0).toFixed(1)} (${Number(item.total_reviews || 0)})`;
    byId("servicePrice").textContent = formatPrice(item.price);
    byId("servicePriceUnit").textContent = isBoat ? "per tour service" : "per day";
    byId("serviceAboutTitle").textContent = isBoat ? "Your island journey starts here" : "Explore with local knowledge";
    byId("serviceDescription").textContent = isBoat
      ? (item.long_description || item.short_description || "A locally operated tour boat ready for island transfers and memorable coastal trips around Mercedes.")
      : (item.description || item.specialization || "A knowledgeable local guide who can help you discover Mercedes destinations with confidence.");

    if (isBoat) {
      byId("serviceStats").innerHTML = [
        iconStat("fa-solid fa-user-group", "Capacity", Number(item.capacity || 0) > 0 ? `${Number(item.capacity)} guests` : "Private group"),
        iconStat("fa-solid fa-ruler-combined", "Boat size", item.size || "Not specified"),
        iconStat("fa-solid fa-hashtag", "Boat number", item.boat_number || "Not specified")
      ].join("");
    } else {
      const experience = Number(item.experience || 0);
      const age = Number(item.age || 0);
      byId("serviceStats").innerHTML = [
        iconStat("fa-solid fa-briefcase", "Experience", experience ? `${experience} year${experience === 1 ? "" : "s"}` : "Local guide"),
        iconStat("fa-solid fa-id-card", "Guide age", age ? `${age} years old` : "Not specified"),
        iconStat("fa-solid fa-map-location-dot", "Specialty", item.specialization || "Mercedes tours")
      ].join("");
    }

    const favorite = byId("serviceFavorite");
    const isFavorite = Boolean(favorites[item.id] || favorites[String(item.id)]);
    favorite.dataset.favoriteId = String(item.id);
    favorite.classList.toggle("is-favorite", isFavorite);
    favorite.setAttribute("aria-pressed", isFavorite ? "true" : "false");
    favorite.setAttribute("aria-label", isFavorite ? "Remove from favorites" : "Add to favorites");

    const returnUrl = encodeURIComponent(config.backUrl);
    byId("serviceBookButton").href = `tour_booking.php?booking_type=${isBoat ? "boat" : "tourguide"}&preferred=${encodeURIComponent(item.name || "")}&return=${returnUrl}`;
    const dots = byId("serviceImageDots");
    dots.innerHTML = images.map((_, index) => `<button type="button" aria-label="Show image ${index + 1}"></button>`).join("");
    dots.querySelectorAll("button").forEach((dot, index) => dot.addEventListener("click", () => showImage(index)));
    [byId("serviceImagePrev"), byId("serviceImageNext"), byId("serviceImageCount"), dots].forEach((el) => { if (el) el.hidden = images.length < 2; });
    showImage(0);

    window.RecentlyViewed?.add({
      type, id: item.id, name: item.name, image: images[0],
      subtitle: isBoat ? `${Number(item.capacity || 0) || "Private"} pax · Tour Boat` : `${item.specialization || "Mercedes tours"} · Tour Guide`,
      price: Number(item.price || 0), priceUnit: isBoat ? "/service" : "/day",
      rating: Number(item.rating || 0), reviewCount: Number(item.total_reviews || 0)
    });

    if (updateHistory) {
      history.pushState({ serviceId: item.id }, "", `service_details.php?type=${type}&id=${encodeURIComponent(item.id)}`);
      document.title = `${item.name} | iTour Mercedes`;
      byId("serviceProfile").scrollIntoView({ behavior: "smooth", block: "start" });
    }
  }

  document.querySelectorAll(".service-option-card").forEach((card) => {
    card.addEventListener("click", (event) => {
      if (event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
      event.preventDefault();
      render(config.items.find((item) => Number(item.id) === Number(card.dataset.serviceId)), true);
    });
  });

  const selectorGrid = byId("serviceSelectorGrid");
  selectorGrid?.addEventListener("wheel", (event) => {
    if (selectorGrid.scrollWidth <= selectorGrid.clientWidth) return;

    const horizontalIntent = Math.abs(event.deltaX) > Math.abs(event.deltaY);
    const delta = horizontalIntent ? event.deltaX : event.deltaY;
    const maxScroll = selectorGrid.scrollWidth - selectorGrid.clientWidth;
    const atStart = selectorGrid.scrollLeft <= 1;
    const atEnd = selectorGrid.scrollLeft >= maxScroll - 1;

    if ((!atStart || delta > 0) && (!atEnd || delta < 0)) {
      event.preventDefault();
      selectorGrid.scrollLeft += delta;
    }
  }, { passive: false });

  byId("serviceImagePrev")?.addEventListener("click", () => showImage(imageIndex - 1));
  byId("serviceImageNext")?.addEventListener("click", () => showImage(imageIndex + 1));
  window.addEventListener("popstate", () => {
    const id = Number(new URLSearchParams(location.search).get("id"));
    render(config.items.find((item) => Number(item.id) === id) || config.items[0], false);
  });
  document.addEventListener("favorite:changed", (event) => {
    const detail = event.detail || {};
    if (detail.type !== type) return;
    if (detail.favorited) favorites[detail.id] = true; else delete favorites[detail.id];
  });
  render(config.items.find((item) => Number(item.id) === Number(config.activeId)) || config.items[0], false);
})();
