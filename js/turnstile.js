(function () {
  "use strict";

  if (window.ItourTurnstile) return;

  const scriptElement = document.currentScript;
  const configUrl = new URL("../php/turnstile_config.php", scriptElement?.src || document.baseURI).href;
  const widgets = new WeakMap();
  let configurationPromise = null;
  let apiPromise = null;

  function configuration() {
    if (!configurationPromise) {
      configurationPromise = fetch(configUrl, {
        credentials: "same-origin",
        headers: { Accept: "application/json" },
        cache: "no-store"
      }).then(response => {
        if (!response.ok) throw new Error("Security verification is unavailable.");
        return response.json();
      });
    }
    return configurationPromise;
  }

  function loadApi() {
    if (window.turnstile) return Promise.resolve(window.turnstile);
    if (!apiPromise) {
      apiPromise = new Promise((resolve, reject) => {
        const existing = document.querySelector('script[data-itour-turnstile-api]');
        const script = existing || document.createElement("script");
        const finish = () => window.turnstile ? resolve(window.turnstile) : reject(new Error("Security verification is unavailable."));
        script.addEventListener("load", finish, { once: true });
        script.addEventListener("error", () => reject(new Error("Security verification is unavailable.")), { once: true });
        if (!existing) {
          script.src = "https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit";
          script.async = true;
          script.defer = true;
          script.dataset.itourTurnstileApi = "true";
          document.head.appendChild(script);
        }
      });
    }
    return apiPromise;
  }

  function resolveContainer(target) {
    if (target instanceof Element) {
      return target.matches("[data-itour-turnstile]") ? target : target.querySelector("[data-itour-turnstile]");
    }
    return document.querySelector(`[data-itour-turnstile="${CSS.escape(String(target || ""))}"]`);
  }

  function showLoading(container) {
    container.classList.remove("itour-turnstile-development", "itour-turnstile-unavailable");
    container.classList.add("itour-turnstile-loading");
    container.setAttribute("aria-busy", "true");
  }

  function clearLoading(container) {
    container.classList.remove("itour-turnstile-loading");
    container.removeAttribute("aria-busy");
  }

  function showUnavailable(container) {
    clearLoading(container);
    container.classList.remove("itour-turnstile-development");
    container.classList.add("itour-turnstile-unavailable");
    container.textContent = "Security verification is unavailable. Please try again later.";
  }

  function clearLoadingWhenVisible(container) {
    let observer = null;
    const finishIfVisible = () => {
      const frame = container.querySelector("iframe");
      if (!frame || frame.getClientRects().length === 0 || frame.getBoundingClientRect().height <= 0) return false;
      clearLoading(container);
      observer?.disconnect();
      return true;
    };

    if (finishIfVisible()) return;
    observer = new MutationObserver(finishIfVisible);
    observer.observe(container, { childList: true, subtree: true, attributes: true, attributeFilter: ["style", "class"] });
    requestAnimationFrame(finishIfVisible);
  }

  async function render(target) {
    const container = resolveContainer(target);
    if (!container) return null;
    if (widgets.has(container)) return widgets.get(container);

    showLoading(container);

    let config;
    try {
      config = await configuration();
    } catch (error) {
      showUnavailable(container);
      throw error;
    }
    if (config.development_bypass) {
      clearLoading(container);
      container.classList.add("itour-turnstile-development");
      container.textContent = "Security verification is disabled for local development.";
      const state = { mode: "development-bypass", id: null };
      widgets.set(container, state);
      return state;
    }
    if (!config.enabled || !config.site_key) {
      showUnavailable(container);
      const state = { mode: "unavailable", id: null };
      widgets.set(container, state);
      return state;
    }

    let api;
    let widgetId;
    try {
      api = await loadApi();
      widgetId = api.render(container, {
        sitekey: config.site_key,
        theme: "light",
        size: "flexible",
        appearance: "always"
      });
    } catch (error) {
      showUnavailable(container);
      throw error;
    }
    const state = { mode: "enabled", id: widgetId };
    widgets.set(container, state);
    clearLoadingWhenVisible(container);
    return state;
  }

  async function scan(root = document) {
    const containers = root.matches?.("[data-itour-turnstile]")
      ? [root]
      : Array.from(root.querySelectorAll?.("[data-itour-turnstile]") || []);
    const visibleContainers = containers.filter(container => container.getClientRects().length > 0);
    await Promise.all(visibleContainers.map(container => render(container).catch(() => {
      showUnavailable(container);
    })));
  }

  async function token(target) {
    const container = resolveContainer(target);
    if (!container) throw new Error("Security verification is unavailable.");
    const state = await render(container);
    if (state.mode === "development-bypass") return "";
    if (state.mode !== "enabled" || !window.turnstile) throw new Error("Security verification failed. Please try again.");
    const value = window.turnstile.getResponse(state.id);
    if (!value) throw new Error("Please complete the security verification.");
    return value;
  }

  function reset(target) {
    const container = resolveContainer(target);
    const state = container ? widgets.get(container) : null;
    if (state?.mode === "enabled" && window.turnstile) {
      window.turnstile.reset(state.id);
    }
  }

  window.ItourTurnstile = { scan, render, token, reset };
  document.addEventListener("DOMContentLoaded", () => scan(document));
})();
