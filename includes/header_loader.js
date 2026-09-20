(function () {
  function loadScriptOnce(src, key, ready) {
    if (typeof ready === "function" && ready()) return Promise.resolve();
    window.__headerLoaderScripts = window.__headerLoaderScripts || {};
    if (window.__headerLoaderScripts[key]) return window.__headerLoaderScripts[key];

    window.__headerLoaderScripts[key] = new Promise((resolve, reject) => {
      const existing = document.querySelector(`script[data-loader-key="${key}"]`);
      if (existing) {
        if (typeof ready === "function" && ready()) {
          resolve();
          return;
        }
        existing.addEventListener("load", () => resolve(), { once: true });
        existing.addEventListener("error", () => reject(new Error(`Failed to load ${src}`)), { once: true });
        return;
      }

      const script = document.createElement("script");
      script.src = src;
      script.async = true;
      script.dataset.loaderKey = key;
      script.onload = () => resolve();
      script.onerror = () => reject(new Error(`Failed to load ${src}`));
      document.body.appendChild(script);
    });

    return window.__headerLoaderScripts[key];
  }

  async function injectHtml(targetId, url) {
    const target = document.getElementById(targetId);
    if (!target) return;
    const response = await fetch(url, { credentials: "same-origin" });
    if (!response.ok) throw new Error(`Failed to load ${url}`);
    target.innerHTML = await response.text();
  }

  async function loadLegacyAuthModal() {
    const modalContainer = document.getElementById("loginModal");
    if (!modalContainer || document.getElementById("modalOverlay")) return;

    const modalResponse = await fetch("logsign-modal.html?v=15", { credentials: "same-origin" });
    if (!modalResponse.ok) return;
    modalContainer.innerHTML = await modalResponse.text();

    await loadScriptOnce("https://cdn.jsdelivr.net/npm/sweetalert2@11", "sweetalert2", () => !!window.Swal);
    await loadScriptOnce("logsign.js?v=16", "logsign", () => typeof window.initLogSignEvents === "function");
    if (typeof window.initLogSignEvents === "function") {
      window.initLogSignEvents();
    }
  }

  document.addEventListener("DOMContentLoaded", async () => {
    try {
      await loadScriptOnce("js/header.js?v=mobile-sidebar-3", "site-header", () => typeof window.initHeader === "function");
      await injectHtml("header", "php/header.php");
      if (typeof window.initHeader === "function") {
        window.initHeader();
      }
    } catch (error) {
      console.error(error);
    }

    try {
      await injectHtml("footer", "footer.php");
      await loadScriptOnce(
        "js/legal-policy-modal.js?v=1",
        "legal-policy-modal",
        () => typeof window.initLegalPolicyModal === "function"
      );
      if (typeof window.initLegalPolicyModal === "function") {
        window.initLegalPolicyModal();
      }
    } catch (error) {
      console.error(error);
    }

    try {
      await loadLegacyAuthModal();
    } catch (error) {
      console.error(error);
    }
  });
})();
