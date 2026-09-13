(() => {
  "use strict";

  const tabs = Array.from(document.querySelectorAll(".destination-results-tab"));
  const cards = Array.from(document.querySelectorAll(".destination-result-card"));
  const summary = document.getElementById("destinationResultsSummary");
  const empty = document.getElementById("destinationFilterEmpty");

  const labels = {
    all: "all services",
    hotels: "hotels and resorts",
    tours: "tour packages",
    boats: "tour boats",
    guides: "tour guides"
  };

  function selectCategory(category, focusTab = false) {
    let visibleCount = 0;
    cards.forEach((card) => {
      const visible = category === "all" || card.dataset.category === category;
      card.hidden = !visible;
      if (visible) visibleCount += 1;
    });

    tabs.forEach((tab) => {
      const active = tab.dataset.category === category;
      tab.classList.toggle("is-active", active);
      tab.setAttribute("aria-selected", active ? "true" : "false");
      tab.tabIndex = active ? 0 : -1;
      if (active && focusTab) tab.focus();
    });

    if (summary) {
      summary.textContent = `${visibleCount} bookable option${visibleCount === 1 ? "" : "s"} in ${labels[category] || "this category"}`;
    }
    if (empty) empty.hidden = visibleCount !== 0;
  }

  if (tabs.length && cards.length) {
    tabs.forEach((tab, index) => {
      tab.addEventListener("click", () => selectCategory(tab.dataset.category || "all"));
      tab.addEventListener("keydown", (event) => {
        if (event.key !== "ArrowLeft" && event.key !== "ArrowRight") return;
        event.preventDefault();
        const direction = event.key === "ArrowRight" ? 1 : -1;
        const nextIndex = (index + direction + tabs.length) % tabs.length;
        selectCategory(tabs[nextIndex].dataset.category || "all", true);
      });
    });
  }

  const form = document.querySelector(".destination-results-search");
  const input = document.getElementById("destinationResultsInput");
  const suggestions = document.getElementById("destinationResultsSuggestions");
  if (!form || !input || !suggestions) return;

  const storageKey = "itourMercedesRecentDestinationSearches";
  const destinations = [
    ["Apuao Pequeña", "Quiet shores and island stays"],
    ["Apuao Grande", "Beach escapes and day tours"],
    ["Quinapaguian Island", "Island-hopping favorite"],
    ["Canimog Island", "Nature and coastal adventures"],
    ["Caringo Island", "Marine sanctuary experiences"],
    ["Malasugui Island", "Local island discoveries"],
    ["Canton Island", "Off-the-beaten-path escape"]
  ];
  let options = [];
  let activeIndex = -1;

  const normalize = (value) => String(value || "").normalize("NFD").replace(/[\u0300-\u036f]/g, "").toLowerCase().trim();
  const readRecent = () => {
    try {
      const stored = JSON.parse(localStorage.getItem(storageKey) || "[]");
      return Array.isArray(stored) ? stored.filter((item) => typeof item === "string" && item.trim()).slice(0, 4) : [];
    } catch (error) {
      return [];
    }
  };
  const saveRecent = (value) => {
    const clean = String(value || "").trim();
    if (!clean) return;
    const next = [clean, ...readRecent().filter((item) => normalize(item) !== normalize(clean))].slice(0, 4);
    try { localStorage.setItem(storageKey, JSON.stringify(next)); } catch (error) { /* Search can continue. */ }
  };
  const icon = (kind) => kind === "recent"
    ? '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3.5 12a8.5 8.5 0 1 0 2.1-5.6"></path><path d="M3.5 4.5v4h4M12 7.5V12l3 2"></path></svg>'
    : '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20 10c0 5-8 11-8 11S4 15 4 10a8 8 0 1 1 16 0Z"></path><circle cx="12" cy="10" r="2.5"></circle></svg>';

  function closeSuggestions() {
    suggestions.hidden = true;
    input.setAttribute("aria-expanded", "false");
    input.removeAttribute("aria-activedescendant");
    activeIndex = -1;
  }

  function choose(option) {
    input.value = option.dataset.value || "";
    closeSuggestions();
    input.focus();
  }

  function addSection(title, items, kind) {
    if (!items.length) return;
    const section = document.createElement("section");
    section.className = "destination-suggestion-section";
    section.innerHTML = `<h3 class="destination-suggestion-heading">${title}</h3>`;
    items.forEach((item) => {
      const value = Array.isArray(item) ? item[0] : item;
      const subtitle = Array.isArray(item) ? item[1] : "Previously searched destination";
      const button = document.createElement("button");
      button.type = "button";
      button.className = "destination-suggestion-option";
      button.setAttribute("role", "option");
      button.setAttribute("aria-selected", "false");
      button.dataset.value = value;
      button.innerHTML = `<span class="destination-suggestion-icon">${icon(kind)}</span><span class="destination-suggestion-copy"><strong></strong><small></small></span><span class="destination-suggestion-tag">${kind === "recent" ? "Recent" : "Recommended"}</span>`;
      button.querySelector("strong").textContent = value;
      button.querySelector("small").textContent = subtitle;
      button.addEventListener("mousedown", (event) => event.preventDefault());
      button.addEventListener("click", () => choose(button));
      section.appendChild(button);
    });
    suggestions.appendChild(section);
  }

  function renderSuggestions(showDiscovery = false) {
    const query = showDiscovery ? "" : normalize(input.value);
    suggestions.replaceChildren();
    if (!query) {
      addSection("Recent searches", readRecent(), "recent");
      addSection("Recommended destinations", destinations.slice(0, 6), "recommended");
    } else {
      const matches = destinations.filter(([name]) => normalize(name).includes(query));
      addSection("Matching destinations", matches, "recommended");
    }
    options = Array.from(suggestions.querySelectorAll(".destination-suggestion-option"));
    options.forEach((option, index) => { option.id = `destinationSuggestion${index}`; });
    activeIndex = -1;
    suggestions.hidden = options.length === 0;
    input.setAttribute("aria-expanded", options.length ? "true" : "false");
  }

  input.addEventListener("focus", () => {
    input.select();
    renderSuggestions(true);
  });
  input.addEventListener("input", () => renderSuggestions(false));
  input.addEventListener("keydown", (event) => {
    if (event.key === "Escape") return closeSuggestions();
    if (event.key !== "ArrowDown" && event.key !== "ArrowUp" && !(event.key === "Enter" && activeIndex >= 0)) return;
    event.preventDefault();
    if (event.key === "Enter") return choose(options[activeIndex]);
    if (suggestions.hidden) renderSuggestions(true);
    if (!options.length) return;
    const direction = event.key === "ArrowDown" ? 1 : -1;
    activeIndex = (activeIndex + direction + options.length) % options.length;
    options.forEach((option, index) => {
      const active = index === activeIndex;
      option.classList.toggle("is-active", active);
      option.setAttribute("aria-selected", active ? "true" : "false");
    });
    input.setAttribute("aria-activedescendant", options[activeIndex].id);
    options[activeIndex].scrollIntoView({ block: "nearest" });
  });
  form.addEventListener("submit", () => saveRecent(input.value));
  document.addEventListener("pointerdown", (event) => { if (!form.contains(event.target)) closeSuggestions(); });
})();
