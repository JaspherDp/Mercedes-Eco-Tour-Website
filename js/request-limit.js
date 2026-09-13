(function () {
  "use strict";

  if (window.RequestLimitModal) return;

  let modalTimer = null;
  const buttonTimers = new WeakMap();

  const secondsLabel = (seconds) => {
    const value = Math.max(0, Math.ceil(Number(seconds) || 0));
    const hours = Math.floor(value / 3600);
    const minutes = Math.floor((value % 3600) / 60);
    const secs = value % 60;
    return hours > 0
      ? `${hours}:${String(minutes).padStart(2, "0")}:${String(secs).padStart(2, "0")}`
      : `${minutes}:${String(secs).padStart(2, "0")}`;
  };

  function ensureModal() {
    if (!document.getElementById("requestLimitModalStyles")) {
      const style = document.createElement("style");
      style.id = "requestLimitModalStyles";
      style.textContent = `
        .request-limit-overlay{position:fixed;inset:0;z-index:40000;display:none;align-items:center;justify-content:center;padding:20px;background:rgba(4,28,23,.72);backdrop-filter:blur(5px)}
        .request-limit-overlay.is-open{display:flex}.request-limit-card{width:min(92vw,430px);padding:30px;text-align:center;border:1px solid rgba(255,255,255,.75);border-radius:20px;background:#fff;box-shadow:0 28px 70px rgba(2,31,24,.34);font-family:Inter,"Segoe UI",Arial,sans-serif;color:#17342d}
        .request-limit-icon{width:62px;height:62px;margin:0 auto 16px;display:grid;place-items:center;border-radius:19px;background:#fff2e6;color:#b85b19}.request-limit-icon svg{width:31px;height:31px;fill:none;stroke:currentColor;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}
        .request-limit-card h2{margin:0;font-size:1.45rem}.request-limit-card p{margin:10px 0 18px;color:#63756f;font-size:.92rem;line-height:1.55}.request-limit-time{display:block;margin-bottom:20px;color:#176b55;font-size:1.7rem;font-weight:800;font-variant-numeric:tabular-nums}
        .request-limit-close{min-width:130px;min-height:44px;padding:10px 18px;border:0;border-radius:11px;background:#176b55;color:#fff;font-family:inherit;font-size:.9rem;font-weight:700;cursor:pointer}.request-limit-close:hover{background:#105744}
      `;
      document.head.appendChild(style);
    }
    let overlay = document.getElementById("requestLimitModal");
    if (!overlay) {
      overlay = document.createElement("div");
      overlay.id = "requestLimitModal";
      overlay.className = "request-limit-overlay";
      overlay.setAttribute("role", "dialog");
      overlay.setAttribute("aria-modal", "true");
      overlay.setAttribute("aria-labelledby", "requestLimitTitle");
      overlay.innerHTML = `<div class="request-limit-card"><div class="request-limit-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"></circle><path d="M12 7v5l3 2M8 3 5 6"></path></svg></div><h2 id="requestLimitTitle">Request Limit Reached</h2><p id="requestLimitMessage">Please try again after the timer ends.</p><strong class="request-limit-time" id="requestLimitTime">0:00</strong><button class="request-limit-close" type="button">Close</button></div>`;
      document.body.appendChild(overlay);
      overlay.querySelector(".request-limit-close").addEventListener("click", () => {
        overlay.classList.remove("is-open");
      });
    }
    return overlay;
  }

  function lockButton(button, seconds, defaultText) {
    if (!button) return;
    const oldTimer = buttonTimers.get(button);
    if (oldTimer) clearInterval(oldTimer);
    const endsAt = Date.now() + seconds * 1000;
    const label = defaultText || button.dataset.requestLimitDefaultText || button.textContent.trim();
    button.dataset.requestLimitDefaultText = label;
    button.disabled = true;
    const render = () => {
      const remaining = Math.max(0, Math.ceil((endsAt - Date.now()) / 1000));
      button.textContent = remaining > 0 ? `Try again in ${secondsLabel(remaining)}` : label;
      if (remaining <= 0) {
        clearInterval(buttonTimers.get(button));
        buttonTimers.delete(button);
        button.disabled = false;
      }
    };
    render();
    buttonTimers.set(button, setInterval(render, 1000));
  }

  function show(payload, options) {
    const settings = options || {};
    const retryAfter = Math.max(1, Math.ceil(Number(payload?.retry_after) || 1));
    const endsAt = Date.now() + retryAfter * 1000;
    const overlay = ensureModal();
    overlay.querySelector("#requestLimitTitle").textContent = payload?.title || "Request Limit Reached";
    overlay.querySelector("#requestLimitMessage").textContent = payload?.message || "Please try again after the timer ends.";
    overlay.classList.add("is-open");
    overlay.querySelector(".request-limit-close").focus();
    lockButton(settings.button, retryAfter, settings.defaultText);
    if (modalTimer) clearInterval(modalTimer);
    const render = () => {
      const remaining = Math.max(0, Math.ceil((endsAt - Date.now()) / 1000));
      overlay.querySelector("#requestLimitTime").textContent = secondsLabel(remaining);
      if (remaining <= 0) {
        clearInterval(modalTimer);
        modalTimer = null;
        overlay.querySelector("#requestLimitMessage").textContent = "You can try your request again now.";
      }
    };
    render();
    modalTimer = setInterval(render, 1000);
  }

  function handle(response, payload, options) {
    const limited = response?.status === 429 || payload?.rate_limited === true || payload?.status === "rate_limited";
    if (!limited) return false;
    show(payload || {}, options);
    return true;
  }

  window.RequestLimitModal = { handle, show, secondsLabel };
})();
