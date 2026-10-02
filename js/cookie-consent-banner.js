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
  const retention = document.getElementById("cookieConsentRetention");
  const title = document.getElementById("cookieConsentTitle");
  const summary = document.getElementById("cookieConsentSummary");
  const optionalCategories = [preferences, thirdParty];
  const acceptAllButton = document.getElementById("cookieConsentAcceptAll");
  let detailsOpened = false;
  let editingSavedConsent = false;
  let retentionTimer = null;

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

  function consentExpiresAt(consent) {
    const acceptedAt = Date.parse(consent?.acceptedAt || "");
    return Number.isFinite(acceptedAt) ? acceptedAt + (consentLifetimeSeconds * 1000) : null;
  }

  function formatRemainingTime(milliseconds) {
    const totalMinutes = Math.max(0, Math.ceil(milliseconds / 60000));
    const days = Math.floor(totalMinutes / 1440);
    const hours = Math.floor((totalMinutes % 1440) / 60);
    const minutes = totalMinutes % 60;
    const parts = [];
    if (days) parts.push(`${days} day${days === 1 ? "" : "s"}`);
    if (hours) parts.push(`${hours} hour${hours === 1 ? "" : "s"}`);
    if (!days && minutes) parts.push(`${minutes} minute${minutes === 1 ? "" : "s"}`);
    return parts.length ? parts.join(", ") : "less than a minute";
  }

  function updateRetentionMessage(consent) {
    if (!retention) return;
    if (!consent?.acceptedAt) {
      retention.textContent = "Your choice will be remembered for 180 days. You can clear this website's cookies in your browser to choose again sooner.";
      return;
    }
    const expiresAt = consentExpiresAt(consent);
    if (!expiresAt) return;
    const preferencesLabel = consent.preferences ? "allowed" : "not allowed";
    const thirdPartyLabel = consent.thirdParty ? "allowed" : "not allowed";
    const expiryDate = new Date(expiresAt).toLocaleString([], { dateStyle: "medium", timeStyle: "short" });
    retention.textContent = `Saved selection: Preferences ${preferencesLabel}; Third-party services ${thirdPartyLabel}. Remaining time: ${formatRemainingTime(expiresAt - Date.now())} (until ${expiryDate}).`;
  }

  function startRetentionTimer(consent) {
    if (retentionTimer) window.clearInterval(retentionTimer);
    updateRetentionMessage(consent);
    retentionTimer = window.setInterval(() => updateRetentionMessage(consent), 60000);
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
    editingSavedConsent = false;
    if (retentionTimer) window.clearInterval(retentionTimer);
    retentionTimer = null;
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
    const label = editingSavedConsent
      ? "Update selection"
      : (optionalCategories.every(input => input.checked) ? "Accept all" : "Accept selection");
    acceptAllButton.textContent = label;
  }

  function setDetailsExpanded(expanded) {
    details.hidden = !expanded;
    detailsButton.setAttribute("aria-expanded", String(expanded));
    detailsButton.textContent = expanded
      ? (editingSavedConsent ? "Cancel" : "Hide details")
      : "View details";
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

  function cancelSavedPreferences() {
    const saved = readConsent();
    if (saved) {
      preferences.checked = Boolean(saved.preferences);
      thirdParty.checked = Boolean(saved.thirdParty);
    }
    editingSavedConsent = false;
    details.hidden = true;
    detailsButton.setAttribute("aria-expanded", "false");
    detailsButton.textContent = "View details";
    banner.classList.remove("is-expanded");
    banner.hidden = true;
    banner.setAttribute("aria-hidden", "true");
    if (retentionTimer) window.clearInterval(retentionTimer);
    retentionTimer = null;
    updateFloatingControlOffset();
  }

  function openSavedPreferences() {
    const saved = readConsent();
    if (!saved) return false;
    editingSavedConsent = true;
    detailsOpened = true;
    preferences.checked = Boolean(saved.preferences);
    thirdParty.checked = Boolean(saved.thirdParty);
    title.textContent = "Update your cookie choices";
    summary.textContent = "Review your saved selection, change optional cookies, or check how long your current choice remains active.";
    banner.hidden = false;
    banner.setAttribute("aria-hidden", "false");
    setDetailsExpanded(true);
    startRetentionTimer(saved);
    requestAnimationFrame(updateFloatingControlOffset);
    return true;
  }

  window.ItourCookiePreferences = Object.freeze({
    hasSavedConsent: () => Boolean(readConsent()),
    open: openSavedPreferences,
    getSavedConsent: readConsent,
    getExpiresAt: () => consentExpiresAt(readConsent())
  });
  document.dispatchEvent(new CustomEvent("itour:cookie-preferences-ready", {
    detail: { hasSavedConsent: Boolean(readConsent()) }
  }));

  detailsButton.addEventListener("click", () => {
    if (editingSavedConsent) {
      cancelSavedPreferences();
      return;
    }
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
