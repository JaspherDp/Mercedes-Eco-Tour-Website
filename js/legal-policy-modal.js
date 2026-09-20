(function () {
  function initLegalPolicyModal() {
    const modal = document.getElementById("legalPolicyModal");
    if (!modal || modal.dataset.initialized === "true") return;
    modal.dataset.initialized = "true";

    const dialog = modal.querySelector(".legal-policy-modal__dialog");
    const title = document.getElementById("legalPolicyTitle");
    const scrollArea = modal.querySelector("[data-legal-policy-scroll]");
    const tabs = Array.from(modal.querySelectorAll("[data-legal-policy-tab]"));
    const panels = Array.from(modal.querySelectorAll("[data-legal-policy-panel]"));
    let returnFocus = null;

    function selectPolicy(policy, focusTab) {
      const selected = ["privacy", "terms", "cookies"].includes(policy) ? policy : "privacy";
      tabs.forEach((tab) => {
        const active = tab.dataset.legalPolicyTab === selected;
        tab.setAttribute("aria-selected", active ? "true" : "false");
        tab.tabIndex = active ? 0 : -1;
        if (active && focusTab) tab.focus();
      });
      panels.forEach((panel) => {
        panel.hidden = panel.dataset.legalPolicyPanel !== selected;
      });
      const titles = { privacy: "Privacy Policy", terms: "Terms & Conditions", cookies: "Cookie Notice" };
      title.textContent = titles[selected];
      if (scrollArea) scrollArea.scrollTop = 0;
    }

    function openModal(policy, trigger) {
      returnFocus = trigger instanceof HTMLElement ? trigger : document.activeElement;
      selectPolicy(policy, false);
      modal.hidden = false;
      modal.setAttribute("aria-hidden", "false");
      document.body.classList.add("legal-policy-modal-open");
      dialog.focus();
    }

    function closeModal() {
      if (modal.hidden) return;
      modal.hidden = true;
      modal.setAttribute("aria-hidden", "true");
      document.body.classList.remove("legal-policy-modal-open");
      if (returnFocus && typeof returnFocus.focus === "function") returnFocus.focus();
    }

    document.addEventListener("click", (event) => {
      const trigger = event.target.closest("[data-legal-policy]");
      if (!trigger) return;
      event.preventDefault();
      openModal(trigger.dataset.legalPolicy, trigger);
    });

    modal.querySelectorAll("[data-legal-policy-close]").forEach((button) => {
      button.addEventListener("click", closeModal);
    });

    tabs.forEach((tab, index) => {
      tab.addEventListener("click", () => selectPolicy(tab.dataset.legalPolicyTab, false));
      tab.addEventListener("keydown", (event) => {
        if (event.key !== "ArrowLeft" && event.key !== "ArrowRight") return;
        event.preventDefault();
        const offset = event.key === "ArrowRight" ? 1 : -1;
        const next = tabs[(index + offset + tabs.length) % tabs.length];
        selectPolicy(next.dataset.legalPolicyTab, true);
      });
    });

    modal.addEventListener("keydown", (event) => {
      if (event.key === "Escape") {
        closeModal();
        return;
      }
      if (event.key !== "Tab") return;
      const focusable = Array.from(modal.querySelectorAll('button:not([disabled]), a[href], [tabindex]:not([tabindex="-1"])'))
        .filter((element) => !element.hidden && element.offsetParent !== null);
      if (!focusable.length) return;
      const first = focusable[0];
      const last = focusable[focusable.length - 1];
      if (event.shiftKey && document.activeElement === first) {
        event.preventDefault();
        last.focus();
      } else if (!event.shiftKey && document.activeElement === last) {
        event.preventDefault();
        first.focus();
      }
    });

    if (window.location.hash === "#legalPolicyModal") {
      const requestedPolicy = new URLSearchParams(window.location.search).get("policy");
      openModal(["privacy", "terms", "cookies"].includes(requestedPolicy) ? requestedPolicy : "privacy", null);
    }
  }

  window.initLegalPolicyModal = initLegalPolicyModal;
  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", initLegalPolicyModal, { once: true });
  } else {
    initLegalPolicyModal();
  }
})();
