class UnifiedSearch {
  constructor(config = {}) {
    this.tabs = config.tabs || {};
    this.tabButtons = Array.from(document.querySelectorAll(".search-tab-btn"));
    this.form = document.getElementById("unifiedSearchForm");
    this.formContainer = document.getElementById("searchFormContainer");
    this.formState = {};
    this.locationOptions = [];
    this.locationLoaded = false;
    this.currentTab = this.resolveInitialTab(config.activeTab || "hotels");
    this.initializeFormState();
    this.bindEvents();
    this.renderForm();
    this.updateTabUI();
    this.renderIcons();
  }

  static normalizeTab(tab) {
    const value = String(tab || "").toLowerCase().trim();
    const aliases = {
      hotels: "hotels",
      hotel: "hotels",
      "hotel-resorts": "hotels",
      hotel_resorts: "hotels",
      tours: "tours",
      "tour-packages": "tours",
      packages: "tours",
      guides: "guides",
      "tour-guides": "guides",
      boats: "boats",
      "our-boats": "boats",
      bundle: "bundle",
      "guide-boat": "bundle",
      "guide_boat": "bundle",
      "tour-guide-boat": "bundle",
    };
    return aliases[value] || value;
  }

  resolveInitialTab(fallbackTab) {
    const urlTab = new URLSearchParams(window.location.search).get("tab");
    const normalizedUrlTab = UnifiedSearch.normalizeTab(urlTab);
    if (normalizedUrlTab && this.tabs[normalizedUrlTab]) {
      return normalizedUrlTab;
    }

    const normalizedFallback = UnifiedSearch.normalizeTab(fallbackTab);
    if (normalizedFallback && this.tabs[normalizedFallback]) {
      return normalizedFallback;
    }

    const firstTab = Object.keys(this.tabs)[0];
    return firstTab || "hotels";
  }

  initializeFormState() {
    Object.keys(this.tabs).forEach((tabId) => {
      this.formState[tabId] = this.formState[tabId] || {};
      const fields = this.tabs[tabId]?.fields || {};
      Object.entries(fields).forEach(([fieldKey, field]) => {
        if (field.type === "counter" && field.options) {
          Object.entries(field.options).forEach(([optionKey, option]) => {
            const defaultValue = Number(option.default ?? 0);
            this.formState[tabId][optionKey] = Number.isFinite(defaultValue) ? defaultValue : 0;
          });
          return;
        }
        if (field.type === "daterange") {
          this.formState[tabId].checkin = this.formState[tabId].checkin || "";
          this.formState[tabId].checkout = this.formState[tabId].checkout || "";
          return;
        }
        this.formState[tabId][fieldKey] = this.formState[tabId][fieldKey] || "";
      });
    });
  }

  bindEvents() {
    this.tabButtons.forEach((button) => {
      button.addEventListener("click", () => this.switchTab(button.dataset.tab));
    });

    if (this.form) {
      this.form.addEventListener("submit", (event) => this.handleSearch(event));
    }

    if (this.formContainer) {
      this.formContainer.addEventListener("input", (event) => this.handleInput(event));
      this.formContainer.addEventListener("focusin", (event) => this.handleFocus(event));
      this.formContainer.addEventListener("click", (event) => this.handleContainerClick(event));
    }

    document.addEventListener("click", (event) => {
      if (!this.formContainer) return;
      if (!this.formContainer.contains(event.target)) {
        this.hideLocationDropdowns();
      }
    });
  }

  switchTab(nextTab) {
    const tabId = UnifiedSearch.normalizeTab(nextTab);
    if (!this.tabs[tabId] || this.currentTab === tabId) {
      return;
    }
    this.currentTab = tabId;
    this.renderForm();
    this.updateTabUI();
    this.updateUrlTab(tabId);
  }

  updateTabUI() {
    this.tabButtons.forEach((button) => {
      button.classList.toggle("active", UnifiedSearch.normalizeTab(button.dataset.tab) === this.currentTab);
    });
  }

  updateUrlTab(tabId) {
    const url = new URL(window.location.href);
    url.searchParams.set("tab", tabId);
    history.replaceState({}, "", url.toString());
  }

  renderForm() {
    const tabConfig = this.tabs[this.currentTab];
    if (!tabConfig || !this.formContainer) return;

    this.formContainer.classList.add("transitioning");
    this.formContainer.innerHTML = this.buildForm(tabConfig);
    window.requestAnimationFrame(() => {
      this.formContainer.classList.remove("transitioning");
      this.renderIcons();
    });
  }

  renderIcons() {
    if (window.lucide && typeof window.lucide.createIcons === "function") {
      window.lucide.createIcons();
    }
  }

  buildForm(tabConfig) {
    const fieldsHtml = Object.entries(tabConfig.fields || {})
      .map(([fieldKey, field]) => this.buildFieldHtml(fieldKey, field))
      .join("");

    return `
      <div class="search-form-grid">${fieldsHtml}</div>
      <div class="search-button-wrapper">
        <button type="submit" aria-label="Search ${tabConfig.label}">Search</button>
      </div>
    `;
  }

  buildFieldHtml(fieldKey, field) {
    switch (field.type) {
      case "location":
        return this.buildLocationField(fieldKey, field);
      case "date":
        return this.buildDateField(fieldKey, field);
      case "daterange":
        return this.buildDateRangeField(field);
      case "counter":
        return this.buildCounterField(field);
      default:
        return "";
    }
  }

  buildLocationField(fieldKey, field) {
    const value = this.escapeHtml(this.formState[this.currentTab]?.[fieldKey] || "");
    const placeholder = this.escapeHtml(field.placeholder || "");
    const label = this.escapeHtml(field.label || "Location");
    const icon = this.iconMarkup(field.icon || "map-pin");
    return `
      <div class="search-field location-field">
        <label>${label}</label>
        <div class="input-group">
          ${icon}
          <input
            type="text"
            data-type="location"
            name="${fieldKey}"
            placeholder="${placeholder}"
            value="${value}"
            autocomplete="off"
            required
          />
          <button type="button" class="clear-btn" aria-label="Clear location" data-action="clear-location">x</button>
        </div>
        <div class="location-dropdown" hidden></div>
      </div>
    `;
  }

  buildDateField(fieldKey, field) {
    const value = this.escapeHtml(this.formState[this.currentTab]?.[fieldKey] || "");
    const label = this.escapeHtml(field.label || "Date");
    const icon = this.iconMarkup(field.icon || "calendar");
    return `
      <div class="search-field date-field">
        <label>${label}</label>
        <div class="input-group">
          ${icon}
          <input type="date" data-type="date" name="${fieldKey}" value="${value}" required />
        </div>
      </div>
    `;
  }

  buildDateRangeField(field) {
    const checkinValue = this.escapeHtml(this.formState[this.currentTab]?.checkin || "");
    const checkoutValue = this.escapeHtml(this.formState[this.currentTab]?.checkout || "");
    const label = this.escapeHtml(field.label || "Dates");
    const icon = this.iconMarkup(field.icon || "calendar");
    return `
      <div class="search-field daterange-field">
        <label>${label}</label>
        <div class="daterange-group">
          <div class="daterange-item">
            ${icon}
            <input type="date" data-type="date" name="checkin" value="${checkinValue}" required />
          </div>
          <span class="daterange-separator">-></span>
          <div class="daterange-item">
            <input type="date" data-type="date" name="checkout" value="${checkoutValue}" required />
          </div>
        </div>
      </div>
    `;
  }

  buildCounterField(field) {
    const label = this.escapeHtml(field.label || "Pax");
    const icon = this.iconMarkup(field.icon || "users");
    const options = Object.entries(field.options || {}).map(([optionKey, option]) => {
      const value = Number(this.formState[this.currentTab]?.[optionKey] ?? option.default ?? 0);
      const min = Number(option.min ?? 0);
      const max = Number(option.max ?? 50);
      return `
        <div class="guest-control" data-option="${optionKey}">
          <button type="button" class="guest-decrement" data-action="decrement" data-field="${optionKey}" data-min="${min}" aria-label="Decrease ${this.escapeHtml(option.label || optionKey)}">-</button>
          <div class="guest-info">
            <span class="guest-label">${this.escapeHtml(option.label || optionKey)}</span>
            <span class="guest-value" data-field="${optionKey}">${value}</span>
          </div>
          <button type="button" class="guest-increment" data-action="increment" data-field="${optionKey}" data-max="${max}" aria-label="Increase ${this.escapeHtml(option.label || optionKey)}">+</button>
          <input type="hidden" name="${optionKey}" value="${value}" />
        </div>
      `;
    }).join("");

    return `
      <div class="search-field guests-field">
        <label>${label}</label>
        <div class="guests-selector">
          ${icon}
          ${options}
        </div>
      </div>
    `;
  }

  handleInput(event) {
    const target = event.target;
    if (!(target instanceof HTMLInputElement)) return;
    const name = target.name;
    if (!name) return;
    this.formState[this.currentTab][name] = target.value;

    if (target.dataset.type === "location") {
      this.renderLocationDropdown(target, target.value);
    }
  }

  handleFocus(event) {
    const target = event.target;
    if (!(target instanceof HTMLInputElement)) return;
    if (target.dataset.type !== "location") return;
    this.loadLocations().then(() => this.renderLocationDropdown(target, target.value));
  }

  handleContainerClick(event) {
    const target = event.target;
    if (!(target instanceof Element)) return;

    const actionButton = target.closest("[data-action]");
    if (actionButton) {
      const action = actionButton.getAttribute("data-action");
      if (action === "increment" || action === "decrement") {
        event.preventDefault();
        this.adjustCounter(actionButton, action === "increment" ? 1 : -1);
        return;
      }
      if (action === "clear-location") {
        event.preventDefault();
        const group = actionButton.closest(".input-group");
        const input = group ? group.querySelector('input[data-type="location"]') : null;
        if (input) {
          input.value = "";
          this.formState[this.currentTab][input.name] = "";
          this.renderLocationDropdown(input, "");
          input.focus();
        }
        return;
      }
    }

    const locationItem = target.closest(".location-item");
    if (locationItem) {
      const value = locationItem.getAttribute("data-value") || "";
      const field = locationItem.closest(".location-field");
      const input = field ? field.querySelector('input[data-type="location"]') : null;
      if (input) {
        input.value = value;
        this.formState[this.currentTab][input.name] = value;
        this.hideLocationDropdown(field);
      }
      return;
    }
  }

  adjustCounter(button, step) {
    const fieldName = button.getAttribute("data-field");
    if (!fieldName) return;
    const optionWrap = button.closest(".guest-control");
    if (!optionWrap) return;
    const valueElement = optionWrap.querySelector(`.guest-value[data-field="${fieldName}"]`);
    const hiddenInput = optionWrap.querySelector(`input[name="${fieldName}"]`);
    if (!valueElement || !hiddenInput) return;

    const min = Number(button.getAttribute("data-min") || 0);
    const max = Number(button.getAttribute("data-max") || 50);
    const current = Number(valueElement.textContent || 0);
    const next = Math.max(min, Math.min(max, current + step));

    valueElement.textContent = String(next);
    hiddenInput.value = String(next);
    this.formState[this.currentTab][fieldName] = next;
  }

  async loadLocations() {
    if (this.locationLoaded) return;
    this.locationLoaded = true;
    try {
      const response = await fetch("php/get_locations.php", { credentials: "same-origin" });
      if (!response.ok) return;
      const payload = await response.json();
      this.locationOptions = Array.isArray(payload) ? payload : [];
    } catch (_error) {
      this.locationOptions = [];
    }
  }

  renderLocationDropdown(input, query) {
    const field = input.closest(".location-field");
    if (!field) return;
    const dropdown = field.querySelector(".location-dropdown");
    if (!dropdown) return;

    const normalizedQuery = String(query || "").toLowerCase().trim();
    const options = this.locationOptions
      .map((item) => String(item?.name || "").trim())
      .filter(Boolean)
      .filter((name) => name.toLowerCase().includes(normalizedQuery))
      .slice(0, 10);

    if (!options.length) {
      dropdown.innerHTML = "";
      dropdown.hidden = true;
      return;
    }

    dropdown.innerHTML = options
      .map((name) => `<div class="location-item" data-value="${this.escapeHtml(name)}">${this.escapeHtml(name)}</div>`)
      .join("");
    dropdown.hidden = false;
  }

  hideLocationDropdown(field) {
    if (!field) return;
    const dropdown = field.querySelector(".location-dropdown");
    if (!dropdown) return;
    dropdown.hidden = true;
  }

  hideLocationDropdowns() {
    if (!this.formContainer) return;
    this.formContainer.querySelectorAll(".location-dropdown").forEach((dropdown) => {
      dropdown.hidden = true;
    });
  }

  validateForm() {
    if (!this.form) return false;

    const requiredInputs = this.form.querySelectorAll("input[required]");
    for (const input of requiredInputs) {
      if (!String(input.value || "").trim()) {
        input.focus();
        alert("Please complete all required fields.");
        return false;
      }
    }

    const checkin = this.form.querySelector('input[name="checkin"]');
    const checkout = this.form.querySelector('input[name="checkout"]');
    if (checkin && checkout && checkin.value && checkout.value) {
      if (new Date(checkout.value) <= new Date(checkin.value)) {
        checkout.focus();
        alert("Check-out date must be later than check-in date.");
        return false;
      }
    }

    const paxInput = this.form.querySelector('input[name="pax"]');
    if (paxInput && Number(paxInput.value || 0) <= 0) {
      alert("Pax must be at least 1.");
      return false;
    }

    const roomsInput = this.form.querySelector('input[name="rooms"]');
    if (roomsInput && Number(roomsInput.value || 0) <= 0) {
      alert("Room selection must be at least 1.");
      return false;
    }

    return true;
  }

  handleSearch(event) {
    event.preventDefault();
    if (!this.validateForm()) return;
    if (!this.form) return;

    const params = new URLSearchParams();
    params.set("tab", this.currentTab);

    const data = new FormData(this.form);
    for (const [key, value] of data.entries()) {
      if (value !== null && String(value).trim() !== "") {
        params.set(key, String(value).trim());
      }
    }

    const url = `search_results.php?${params.toString()}`;
    const opened = window.open(url, "_blank", "noopener,noreferrer");
    if (!opened) {
      window.location.href = url;
    }
  }

  escapeHtml(value) {
    return String(value)
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;")
      .replace(/'/g, "&#039;");
  }

  iconMarkup(iconName) {
    const safeIcon = this.escapeHtml(iconName || "circle");
    return `<span class="field-icon" aria-hidden="true"><i data-lucide="${safeIcon}"></i></span>`;
  }
}

document.addEventListener("DOMContentLoaded", () => {
  const tabsConfig = JSON.parse(document.getElementById("searchTabsConfig")?.textContent || "{}");
  const activeTabConfig = JSON.parse(document.getElementById("searchActiveTab")?.textContent || "\"hotels\"");
  window.unifiedSearch = new UnifiedSearch({
    tabs: tabsConfig,
    activeTab: activeTabConfig,
  });
});
