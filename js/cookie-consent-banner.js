(function () {
  "use strict";

  const banner = document.getElementById("cookieConsentBanner");
  if (!banner) return;

  const consentCookie = "itour_cookie_consent";
  const consentVersion = 3;
  const consentLifetimeSeconds = 60 * 60 * 24 * 180;
  const detailsButton = document.getElementById("cookieConsentDetails");
  const details = document.getElementById("cookieConsentCategories");
  const preferences = document.getElementById("cookieConsentPreferences");
  const thirdParty = document.getElementById("cookieConsentThirdParty");
  const optionalCategories = [preferences, thirdParty];
  const acceptAllButton = document.getElementById("cookieConsentAcceptAll");
  let detailsOpened = false;

  function updateFloatingControlOffset() {
    const visible = !banner.hidden;
    const height = visible ? Math.ceil(banner.getBoundingClientRect().height) : 0;
    document.documentElement.style.setProperty("--cookie-consent-mobile-offset", `${height}px`);
    document.body.classList.toggle("cookie-consent-visible", visible);
  }

  function readCookie(name) {
    const prefix = `${name}=`;
    const entry = document.cookie.split("; ").find(item => item.startsWith(prefix));
    return entry ? entry.slice(prefix.length) : "";
  }

  function readConsent() {
    try {
      const saved = JSON.parse(decodeURIComponent(readCookie(consentCookie)));
      if (saved?.version !== consentVersion || saved?.necessary !== true) return null;
      return saved;
    } catch (_) {
      return null;
    }
  }

  function publishConsent(consent) {
    window.ItourCookieConsent = Object.freeze({ ...consent });
    document.dispatchEvent(new CustomEvent("itour:cookie-consent", { detail: window.ItourCookieConsent }));
  }

  function saveConsent(selection) {
    const consent = {
      version: consentVersion,
      necessary: true,
      preferences: Boolean(selection.preferences),
      rememberLogin: true,
      thirdParty: Boolean(selection.thirdParty),
      acceptedAt: new Date().toISOString()
    };
    const secure = window.location.protocol === "https:" ? "; Secure" : "";
    document.cookie = `${consentCookie}=${encodeURIComponent(JSON.stringify(consent))}; Max-Age=${consentLifetimeSeconds}; Path=/; SameSite=Lax${secure}`;
    publishConsent(consent);
    banner.hidden = true;
    banner.setAttribute("aria-hidden", "true");
    updateFloatingControlOffset();
  }

  function currentSelection() {
    return {
      preferences: preferences.checked,
      thirdParty: thirdParty.checked
    };
  }

  function selectAllCategories() {
    optionalCategories.forEach(input => { input.checked = true; });
  }

  function updateAcceptButton() {
    const label = optionalCategories.every(input => input.checked) ? "Accept all" : "Accept selection";
    acceptAllButton.textContent = label;
  }

  function setDetailsExpanded(expanded) {
    details.hidden = !expanded;
    detailsButton.setAttribute("aria-expanded", String(expanded));
    detailsButton.textContent = expanded ? "Hide details" : "View details";
    banner.classList.toggle("is-expanded", expanded);
    requestAnimationFrame(updateFloatingControlOffset);
    if (expanded) {
      if (!detailsOpened) {
        selectAllCategories();
        detailsOpened = true;
      }
      updateAcceptButton();
      details.querySelector("input:not([disabled])")?.focus();
    }
  }

  detailsButton.addEventListener("click", () => {
    setDetailsExpanded(detailsButton.getAttribute("aria-expanded") !== "true");
  });
  optionalCategories.forEach(input => input.addEventListener("change", updateAcceptButton));
  acceptAllButton.addEventListener("click", () => {
    if (detailsButton.getAttribute("aria-expanded") !== "true") selectAllCategories();
    saveConsent(currentSelection());
  });

  const savedConsent = readConsent();
  if ("ResizeObserver" in window) {
    new ResizeObserver(updateFloatingControlOffset).observe(banner);
  }
  window.addEventListener("resize", updateFloatingControlOffset, { passive: true });
  if (savedConsent) {
    publishConsent(savedConsent);
    updateFloatingControlOffset();
    return;
  }

  publishConsent({ version: consentVersion, necessary: true, preferences: false, rememberLogin: true, thirdParty: false, acceptedAt: null });
  banner.hidden = false;
  banner.setAttribute("aria-hidden", "false");
  requestAnimationFrame(updateFloatingControlOffset);
})();
