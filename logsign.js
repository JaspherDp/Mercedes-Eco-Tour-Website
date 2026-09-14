const logsignScriptUrl = document.currentScript?.src || document.baseURI;
let logsignTurnstilePromise = null;
function getLogsignTurnstile() {
  if (window.ItourTurnstile) return Promise.resolve(window.ItourTurnstile);
  if (!logsignTurnstilePromise) {
    logsignTurnstilePromise = new Promise((resolve, reject) => {
      const script = document.createElement('script');
      script.src = new URL('js/turnstile.js', logsignScriptUrl).href;
      script.async = true;
      script.onload = () => window.ItourTurnstile ? resolve(window.ItourTurnstile) : reject(new Error('Security verification is unavailable.'));
      script.onerror = () => reject(new Error('Security verification is unavailable.'));
      document.head.appendChild(script);
    });
  }
  return logsignTurnstilePromise;
}

async function addLogsignTurnstileToken(formData, widgetName) {
  const helper = await getLogsignTurnstile();
  const token = await helper.token(widgetName);
  if (token) formData.append('cf-turnstile-response', token);
}

// --- Cookie utilities for Remember Me ---
function setCookie(name, value, days) {
  const expires = new Date(Date.now() + days * 864e5).toUTCString();
  document.cookie = name + '=' + encodeURIComponent(value) + '; expires=' + expires + '; path=/';
}

function getCookie(name) {
  return document.cookie.split('; ').reduce((r, v) => {
    const parts = v.split('=');
    return parts[0] === name ? decodeURIComponent(parts[1]) : r;
  }, '');
}

function deleteCookie(name) {
  document.cookie = name + '=; Max-Age=-99999999; path=/';
}

function clearRememberedCredentials() {
  deleteCookie('rememberedEmail');
  deleteCookie('rememberedPassword');
}

// --- Toast notification system ---
function showToast(message, type = 'success') {
  const toastContainer = document.getElementById('toastContainer') || (() => {
    const container = document.createElement('div');
    container.id = 'toastContainer';
    container.style.cssText = 'position: fixed; top: 20px; right: 20px; z-index: 10001; pointer-events: none;';
    document.body.appendChild(container);
    return container;
  })();

  const toast = document.createElement('div');
  toast.style.cssText = `
    background: ${type === 'success' ? '#4CAF50' : '#f44336'};
    color: white;
    padding: 16px 24px;
    border-radius: 8px;
    margin-bottom: 10px;
    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
    animation: slideIn 0.3s ease-in-out;
    pointer-events: auto;
  `;
  toast.textContent = message;

  toastContainer.appendChild(toast);

  setTimeout(() => {
    toast.style.animation = 'slideOut 0.3s ease-in-out';
    setTimeout(() => toast.remove(), 300);
  }, 3000);
}

// Add CSS animations
if (!document.getElementById('toastStyles')) {
  const style = document.createElement('style');
  style.id = 'toastStyles';
  style.textContent = `
    @keyframes slideIn {
      from { transform: translateX(400px); opacity: 0; }
      to { transform: translateX(0); opacity: 1; }
    }
    @keyframes slideOut {
      from { transform: translateX(0); opacity: 1; }
      to { transform: translateX(400px); opacity: 0; }
    }
  `;
  document.head.appendChild(style);
}

// Keep SweetAlert above auth overlay across all pages that load this modal.
if (!document.getElementById('logsignSwalLayerFix')) {
  const swalLayerFix = document.createElement('style');
  swalLayerFix.id = 'logsignSwalLayerFix';
  swalLayerFix.textContent = '.swal2-container{z-index:20000 !important;}';
  document.head.appendChild(swalLayerFix);
}

const authRateLimitButtonTimers = new WeakMap();
function handleAuthRateLimit(response, payload, button, defaultText) {
  const limited = response?.status === 429 || payload?.rate_limited === true || payload?.status === 'rate_limited';
  if (!limited) return false;
  if (window.RequestLimitModal) {
    window.RequestLimitModal.handle(response, payload, { button, defaultText });
    return true;
  }
  const retryAfter = Math.max(1, Math.ceil(Number(payload?.retry_after) || 1));
  const endsAt = Date.now() + retryAfter * 1000;
  const format = seconds => {
    const hours = Math.floor(seconds / 3600);
    const minutes = Math.floor((seconds % 3600) / 60);
    const secs = seconds % 60;
    return hours ? `${hours}:${String(minutes).padStart(2, '0')}:${String(secs).padStart(2, '0')}` : `${minutes}:${String(secs).padStart(2, '0')}`;
  };
  if (button) {
    const previous = authRateLimitButtonTimers.get(button);
    if (previous) clearInterval(previous);
    button.disabled = true;
    const renderButton = () => {
      const remaining = Math.max(0, Math.ceil((endsAt - Date.now()) / 1000));
      button.textContent = remaining ? `Try again in ${format(remaining)}` : defaultText;
      if (!remaining) {
        clearInterval(authRateLimitButtonTimers.get(button));
        authRateLimitButtonTimers.delete(button);
        button.disabled = false;
      }
    };
    renderButton();
    authRateLimitButtonTimers.set(button, setInterval(renderButton, 1000));
  }
  let alertTimer = null;
  Swal.fire({
    icon: 'warning',
    title: payload?.title || 'Request Limit Reached',
    text: payload?.message || 'Please try again after the timer ends.',
    footer: '<strong id="authRequestLimitTime" style="color:#176b55;font-size:1.35rem;font-variant-numeric:tabular-nums"></strong>',
    confirmButtonColor: '#2B7066',
    didOpen: () => {
      const render = () => {
        const remaining = Math.max(0, Math.ceil((endsAt - Date.now()) / 1000));
        const timer = document.getElementById('authRequestLimitTime');
        if (timer) timer.textContent = remaining ? `Try again in ${format(remaining)}` : 'You can try again now.';
        if (!remaining && alertTimer) clearInterval(alertTimer);
      };
      render();
      alertTimer = setInterval(render, 1000);
    },
    willClose: () => { if (alertTimer) clearInterval(alertTimer); }
  });
  return true;
}

function initLogSignEvents() {
  if (window.__logSignEventsBound) return;
  window.__logSignEventsBound = true;

  // --- Elements ---
  const modalOverlay = document.getElementById("modalOverlay");
  const openModalBtn = document.getElementById("openModalBtn");
  const closeModal = document.getElementById("closeModal");

  const loginForm = document.getElementById("loginForm");
  const signupForm = document.getElementById("signupForm");

  const goSignup = document.getElementById("goSignup");
  const goLogin = document.getElementById("goLogin");

  const loginBtn = document.getElementById("loginBtn");
  const loginFormError = document.getElementById("loginFormError");
  const signupBtn = document.getElementById("signupBtn");
  const sendCodeBtn = document.getElementById("sendCodeBtn");
  const signupDetailsNextBtn = document.getElementById("signupDetailsNextBtn");
  const verifySignupCodeBtn = document.getElementById("verifySignupCodeBtn");
  const signupEmailBackBtn = document.getElementById("signupEmailBackBtn");
  const signupPasswordBackBtn = document.getElementById("signupPasswordBackBtn");

  // Toast notification container
  let toastContainer = document.getElementById('toastContainer');
  if (!toastContainer) {
    toastContainer = document.createElement('div');
    toastContainer.id = 'toastContainer';
    toastContainer.style.cssText = 'position: fixed; top: 20px; right: 20px; z-index: 10001; pointer-events: none;';
    document.body.appendChild(toastContainer);
  }

  const phase1 = signupForm.querySelector(".signup-phase-1");
  const phase2 = signupForm.querySelector(".signup-phase-2");
  const phase3 = signupForm.querySelector(".signup-phase-3");
  const steps = signupForm.querySelectorAll(".phase-step");

  const loginEmailInput = document.getElementById('loginEmail');
  const loginPasswordInput = document.getElementById('loginPassword');
  const rememberMeCheckbox = document.getElementById('rememberMe');

  // --- Prefill login if Remember Me cookies exist ---
  const rememberedEmail = getCookie('rememberedEmail');
  const rememberedPassword = getCookie('rememberedPassword');

  if (rememberedEmail && rememberedPassword) {
    loginEmailInput.value = rememberedEmail;
    loginPasswordInput.value = rememberedPassword;
    rememberMeCheckbox.checked = true;
  } else if (rememberedEmail || rememberedPassword) {
    // Treat an incomplete old record as not remembered, so both login forms
    // always agree on the Remember Me state.
    clearRememberedCredentials();
  }

  rememberMeCheckbox?.addEventListener('change', () => {
    if (!rememberMeCheckbox.checked) clearRememberedCredentials();
  });

  if (!modalOverlay) {
    window.__logSignEventsBound = false;
    return;
  }

  getLogsignTurnstile().then(helper => helper.scan(modalOverlay)).catch(() => {});

  const authStore = window.AuthModalStore || {};
  const authSubscribers = authStore._listeners instanceof Set ? authStore._listeners : new Set();
  authStore._listeners = authSubscribers;
  authStore.isOpen = modalOverlay.style.display === "flex";

  const publishAuthState = (isOpen) => {
    authStore.isOpen = isOpen;
    authSubscribers.forEach((listener) => {
      try {
        listener(isOpen);
      } catch (error) {
        console.error("Auth modal listener failed:", error);
      }
    });
  };

  const resetSignupStep = () => {
    phase1.classList.add("active");
    phase2.classList.remove("active");
    phase3.classList.remove("active");
    steps.forEach((s) => {
      s.classList.remove("phase-active", "phase-completed", "phase-inactive");
      s.classList.add("phase-inactive");
    });
    steps[0].classList.remove("phase-inactive");
    steps[0].classList.add("phase-active");
  };

  const openAuthModal = () => {
    clearLoginFormError();
    modalOverlay.style.display = "flex";
    loginForm.classList.remove("logsign-hidden");
    signupForm.classList.add("logsign-hidden");
    forgotPasswordForm?.classList.add("logsign-hidden");
    resetSignupStep();
    getLogsignTurnstile().then(helper => {
      if (!loginForm.classList.contains('logsign-hidden')) helper.render('modal-login');
    }).catch(() => {});
    publishAuthState(true);
  };

  const closeAuthModal = () => {
    if (window.ItourTurnstile) {
      ['modal-login', 'modal-forgot-send', 'modal-signup-send', 'modal-signup-complete'].forEach(name => window.ItourTurnstile.reset(name));
    }
    modalOverlay.style.display = "none";
    publishAuthState(false);
  };

  authStore.open = openAuthModal;
  authStore.close = closeAuthModal;
  authStore.subscribe = (listener) => {
    if (typeof listener !== "function") return () => {};
    authSubscribers.add(listener);
    return () => authSubscribers.delete(listener);
  };
  window.AuthModalStore = authStore;

  // --- Modal open/close ---
  if (openModalBtn) {
    openModalBtn.addEventListener("click", openAuthModal);
  }

  if (closeModal) {
    closeModal.addEventListener("click", closeAuthModal);
  }
  modalOverlay.addEventListener("click", (event) => {
    if (event.target === modalOverlay) {
      closeAuthModal();
    }
  });

  // --- Form toggles ---
  if (goSignup) {
    goSignup.addEventListener("click", () => {
      window.location.href = "signup.php";
    });
  }

  if (goLogin) {
    goLogin.addEventListener("click", () => {
      signupForm.classList.add("logsign-hidden");
      loginForm.classList.remove("logsign-hidden");
      getLogsignTurnstile().then(helper => helper.render('modal-login')).catch(() => {});
    });
  }

  // --- Password toggle ---
  function setupPasswordToggle(inputId, iconId) {
    const inputEl = document.getElementById(inputId);
    const iconEl = document.getElementById(iconId);
    if (!inputEl || !iconEl) return;

    iconEl.src = "img/passwordhide.png";

    iconEl.addEventListener("click", () => {
      if (inputEl.type === "password") {
        inputEl.type = "text";
        iconEl.src = "img/passwordsee.png";
      } else {
        inputEl.type = "password";
        iconEl.src = "img/passwordhide.png";
      }
    });
  }

  setupPasswordToggle("loginPassword", "toggleLoginPassword");
  setupPasswordToggle("signupPassword", "toggleSignupPassword");
  setupPasswordToggle("signupConfirmPassword", "toggleConfirmPassword");

  // --- Send Code countdown ---
  let countdown;
  const cdEl = document.getElementById("codeCountdown");
  cdEl.style.display = "none"; // hide initially

  const setSignupPhase = (phaseNumber) => {
    [phase1, phase2, phase3].forEach((phase, index) => phase.classList.toggle("active", index + 1 === phaseNumber));
    steps.forEach((step, index) => {
      step.classList.remove("phase-active", "phase-completed", "phase-inactive");
      if (index + 1 < phaseNumber) step.classList.add("phase-completed");
      else if (index + 1 === phaseNumber) step.classList.add("phase-active");
      else step.classList.add("phase-inactive");
    });
    if (phaseNumber === 2) getLogsignTurnstile().then(helper => helper.render('modal-signup-send')).catch(() => {});
    if (phaseNumber === 3) getLogsignTurnstile().then(helper => helper.render('modal-signup-complete')).catch(() => {});
  };

  const signupField = (id) => document.getElementById(id);
  const signupLocationIds = ["signupCountry", "signupRegion", "signupProvince", "signupCity", "signupBarangay"];
  const signupGeoCache = new Map();

  const selectedSignupLocation = (id) => signupField(id)?.selectedOptions[0]?.dataset.name || "";

  function resetSignupLocation(id, message) {
    const select = signupField(id);
    select.innerHTML = `<option value="">${message}</option>`;
    select.disabled = true;
  }

  async function fetchSignupLocations(action, parameters = {}) {
    const query = new URLSearchParams({ action, scope: "signup", address_version: "2", ...parameters });
    const key = query.toString();
    if (!signupGeoCache.has(key)) {
      signupGeoCache.set(key, fetch(`php/geonames_locations.php?${query}`, { headers: { Accept: "application/json" } })
        .then(async response => {
          const result = await response.json();
          if (!response.ok || !result.success) throw new Error(result.message || "Locations could not be loaded.");
          return result;
        })
        .catch(error => {
          signupGeoCache.delete(key);
          throw error;
        }));
    }
    return signupGeoCache.get(key);
  }

  function fillSignupLocation(id, locations, placeholder) {
    const select = signupField(id);
    select.innerHTML = `<option value="">${placeholder}</option>`;
    locations.forEach(location => {
      const option = document.createElement("option");
      option.value = String(location.id);
      option.textContent = location.name;
      option.dataset.name = location.name;
      option.dataset.countryCode = location.country_code || "";
      option.dataset.featureCode = location.feature_code || "";
      select.appendChild(option);
    });
    select.disabled = locations.length === 0;
  }

  async function loadSignupChildren(parentId, childId, placeholder, featureCodes) {
    const parent = signupField(parentId);
    const child = signupField(childId);
    const selectedParent = parent.value;
    resetSignupLocation(childId, "Loading locations...");
    if (!selectedParent) return;
    const result = await fetchSignupLocations("children", { parent_id: selectedParent });
    if (parent.value !== selectedParent) return;
    const all = Array.isArray(result.locations) ? result.locations : [];
    const filtered = all.filter(location => featureCodes.includes(location.feature_code));
    fillSignupLocation(childId, filtered.length ? filtered : all, placeholder);
  }

  async function initializeSignupLocations() {
    const country = signupField("signupCountry");
    if (!country || country.dataset.loaded === "true") return;
    const status = signupField("signupAddressStatus");
    try {
      const result = await fetchSignupLocations("countries");
      fillSignupLocation("signupCountry", result.locations || [], "Select a country");
      country.dataset.loaded = "true";
      status.textContent = "";
      status.className = "signup-inline-status";
    } catch (error) {
      resetSignupLocation("signupCountry", "Countries could not be loaded");
      status.textContent = error.message;
      status.className = "signup-inline-status is-error";
    }
  }

  signupField("signupCountry")?.addEventListener("change", async () => {
    resetSignupLocation("signupProvince", "Select region first");
    resetSignupLocation("signupCity", "Select province first");
    resetSignupLocation("signupBarangay", "Select city first");
    signupField("signupPostalCode").value = "";
    try { await loadSignupChildren("signupCountry", "signupRegion", "Select a region or state", ["ADM1"]); }
    catch (error) { signupField("signupAddressStatus").textContent = error.message; signupField("signupAddressStatus").className = "signup-inline-status is-error"; }
  });

  signupField("signupRegion")?.addEventListener("change", async () => {
    resetSignupLocation("signupCity", "Select province first");
    resetSignupLocation("signupBarangay", "Select city first");
    signupField("signupPostalCode").value = "";
    try { await loadSignupChildren("signupRegion", "signupProvince", "Select a province", ["ADM2"]); }
    catch (error) { signupField("signupAddressStatus").textContent = error.message; signupField("signupAddressStatus").className = "signup-inline-status is-error"; }
  });

  signupField("signupProvince")?.addEventListener("change", async () => {
    resetSignupLocation("signupBarangay", "Select city first");
    signupField("signupPostalCode").value = "";
    try { await loadSignupChildren("signupProvince", "signupCity", "Select a city or municipality", ["ADM3"]); }
    catch (error) { signupField("signupAddressStatus").textContent = error.message; signupField("signupAddressStatus").className = "signup-inline-status is-error"; }
  });

  signupField("signupCity")?.addEventListener("change", async () => {
    signupField("signupPostalCode").value = "";
    try { await loadSignupChildren("signupCity", "signupBarangay", "Select a barangay", ["ADM4", "PPL", "PPLX"]); }
    catch (error) { signupField("signupAddressStatus").textContent = error.message; signupField("signupAddressStatus").className = "signup-inline-status is-error"; }
  });

  signupField("signupBarangay")?.addEventListener("change", async () => {
    const postal = signupField("signupPostalCode");
    const city = selectedSignupLocation("signupCity");
    const countryCode = signupField("signupCountry").selectedOptions[0]?.dataset.countryCode || "";
    postal.value = city && countryCode ? "Loading..." : "";
    if (!city || !countryCode) return;
    try {
      const result = await fetchSignupLocations("postal_code", { country_code: countryCode, place_name: city.replace(/^Municipality of\s+/i, "") });
      postal.value = result.postal_code || "";
      if (!result.postal_code) throw new Error("Postal code unavailable. Please enter your postal code.");
    } catch (error) {
      postal.value = "";
      postal.readOnly = false;
      postal.placeholder = "Enter postal code";
      signupField("signupAddressStatus").textContent = "Please enter your postal code; automatic lookup is unavailable.";
      signupField("signupAddressStatus").className = "signup-inline-status is-error";
    }
  });

  sendCodeBtn.addEventListener("click", async () => {
    if (sendCodeBtn.disabled) return;

    const emailInput = document.getElementById("signupEmail");
    const email = emailInput.value.trim();
    if (!email) { alert("Please enter your email!"); return; }

    sendCodeBtn.disabled = true;
    sendCodeBtn.textContent = "Sending...";
    sendCodeBtn.style.cursor = "not-allowed";

    try {
      const formData = new FormData();
      formData.append("email", email);
      formData.append("fname", signupField("signupFirstName").value.trim());
      formData.append("lname", signupField("signupLastName").value.trim());
      formData.append("action", "send_code");
      await addLogsignTurnstileToken(formData, "modal-signup-send");

      const res = await fetch("php/signup.php", { method: "POST", body: formData });
      const data = await res.json();
      if (handleAuthRateLimit(res, data, sendCodeBtn, "Send Code")) return;
      if (data.status !== "success") throw new Error(data.message || "The code could not be sent.");
      signupField("signupEmailStatus").textContent = data.message;
      signupField("signupEmailStatus").className = "signup-inline-status is-success";
    } catch (err) {
      signupField("signupEmailStatus").textContent = err.message || "Failed to send code.";
      signupField("signupEmailStatus").className = "signup-inline-status is-error";
      sendCodeBtn.disabled = false;
      sendCodeBtn.textContent = "Send Code";
      sendCodeBtn.style.cursor = "pointer";
      return;
    } finally {
      window.ItourTurnstile?.reset("modal-signup-send");
    }

    sendCodeBtn.textContent = "Resend Code";
    sendCodeBtn.style.backgroundColor = "#999";

    let timer = 90;
    cdEl.style.display = "block";
    cdEl.textContent = `Resend code in ${timer}s`;

    clearInterval(countdown);
    countdown = setInterval(() => {
      timer--;
      cdEl.textContent = timer > 0 ? `Resend code in ${timer}s` : "You can resend code now";
      if (timer <= 0) {
        clearInterval(countdown);
        sendCodeBtn.disabled = false;
        sendCodeBtn.style.backgroundColor = "#2E7B45"; // active green
        sendCodeBtn.style.cursor = "pointer";
      }
    }, 1000);
  });

function showLoginFormError(message, type = "error") {
    if (!loginFormError) return;
    loginFormError.classList.toggle("is-success", type === "success");
    const messageElement = loginFormError.querySelector("span");
    if (messageElement) messageElement.textContent = message;
    loginFormError.hidden = false;
}

function clearLoginFormError() {
    if (loginBtn?.dataset.locked === "true") return;
    if (loginFormError) {
        loginFormError.hidden = true;
        loginFormError.classList.remove("is-success");
    }
}

loginEmailInput?.addEventListener("input", clearLoginFormError);
loginPasswordInput?.addEventListener("input", clearLoginFormError);

let touristLoginLockoutTimer = null;
let touristLoginLockoutEndsAt = 0;
const touristLoginLockoutStorageKey = "itourTouristLoginLockout";

function readTouristLoginState(rawValue = null) {
    try {
        const raw = rawValue ?? localStorage.getItem(touristLoginLockoutStorageKey) ?? sessionStorage.getItem(touristLoginLockoutStorageKey);
        if (!raw) return null;
        if (/^\d+$/.test(raw)) return { endsAt: Number(raw), email: "", attemptsRemaining: 0 };
        const state = JSON.parse(raw);
        if (!state || typeof state !== "object") return null;
        if (Number(state.attemptsRemaining) > 0 && Number(state.endsAt || 0) <= Date.now()
            && (!Number(state.updatedAt) || Date.now() - Number(state.updatedAt) >= 120000)) return null;
        return state;
    } catch (_) { return null; }
}

function saveTouristLoginState(state) {
    try {
        localStorage.setItem(touristLoginLockoutStorageKey, JSON.stringify({...state, updatedAt:Date.now()}));
        sessionStorage.removeItem(touristLoginLockoutStorageKey);
    } catch (_) {}
}

function resetTouristLoginButton() {
    if (touristLoginLockoutTimer) {
        clearInterval(touristLoginLockoutTimer);
        touristLoginLockoutTimer = null;
    }
    loginBtn.disabled = false;
    if (loginEmailInput) loginEmailInput.disabled = false;
    if (loginPasswordInput) loginPasswordInput.disabled = false;
    loginBtn.classList.remove("is-login-locked");
    loginBtn.removeAttribute("aria-busy");
    loginBtn.removeAttribute("data-locked");
    loginBtn.textContent = "Login";
    loginBtn.style.opacity = "1";
    loginBtn.style.cursor = "pointer";
    touristLoginLockoutEndsAt = 0;
    try { localStorage.removeItem(touristLoginLockoutStorageKey); } catch (_) {}
}

function startTouristLoginLockout(retryAfter, existingEndsAt = 0, lockedEmail = "") {
    if (touristLoginLockoutTimer) clearInterval(touristLoginLockoutTimer);
    touristLoginLockoutEndsAt = existingEndsAt > Date.now()
        ? existingEndsAt
        : Date.now() + (Math.max(1, Math.ceil(Number(retryAfter) || 0)) * 1000);
    const email = String(lockedEmail || loginEmailInput?.value || "").trim();
    saveTouristLoginState({ endsAt: touristLoginLockoutEndsAt, email, attemptsRemaining: 0 });
    if (loginEmailInput && !loginEmailInput.value && email) loginEmailInput.value = email;
    loginBtn.disabled = true;
    if (loginEmailInput) loginEmailInput.disabled = true;
    if (loginPasswordInput) loginPasswordInput.disabled = true;
    loginBtn.dataset.locked = "true";
    loginBtn.classList.add("is-login-locked");
    loginBtn.setAttribute("aria-busy", "true");
    loginBtn.style.opacity = "1";
    loginBtn.style.cursor = "not-allowed";

    const render = () => {
        const remaining = Math.max(0, Math.ceil((touristLoginLockoutEndsAt - Date.now()) / 1000));
        loginBtn.innerHTML = `<span class="logsign-login-spinner" aria-hidden="true"></span><span>Try again in ${remaining}s</span>`;
        showLoginFormError(`Too many failed login attempts. Please wait ${remaining} seconds before trying again.`);
        if (remaining <= 0) {
            resetTouristLoginButton();
            showLoginFormError("You may now try logging in again.", "success");
        }
    };
    render();
    touristLoginLockoutTimer = setInterval(render, 1000);
}

try {
    const savedState = readTouristLoginState();
    const savedLockoutEnd = Number(savedState?.endsAt || 0);
    if (savedState?.email && loginEmailInput && !loginEmailInput.value) loginEmailInput.value = savedState.email;
    if (savedLockoutEnd > Date.now()) {
        startTouristLoginLockout(Math.ceil((savedLockoutEnd - Date.now()) / 1000), savedLockoutEnd, savedState?.email || "");
    } else if (Number(savedState?.attemptsRemaining) > 0) {
        showLoginFormError(`Email or password is wrong. ${savedState.attemptsRemaining} attempts remaining.`);
    } else {
        localStorage.removeItem(touristLoginLockoutStorageKey);
    }
} catch (_) {}

window.addEventListener("storage", (event) => {
    if (event.key !== touristLoginLockoutStorageKey) return;
    const state = readTouristLoginState(event.newValue);
    const endsAt = Number(state?.endsAt || 0);
    if (endsAt > Date.now()) {
        startTouristLoginLockout(Math.ceil((endsAt - Date.now()) / 1000), endsAt, state?.email || "");
    } else if (!state && loginBtn?.dataset.locked === "true") {
        resetTouristLoginButton();
        showLoginFormError("You may now try logging in again.", "success");
    } else if (Number(state?.attemptsRemaining) > 0) {
        if (state.email && loginEmailInput && !loginEmailInput.value) loginEmailInput.value = state.email;
        showLoginFormError(`Email or password is wrong. ${state.attemptsRemaining} attempts remaining.`);
    }
});

// --- Login function ---
async function handleLogin(event) {
    event?.preventDefault();
    if (loginBtn?.dataset.locked === "true") return;
    clearLoginFormError();

    if (!loginForm.checkValidity()) {
        loginForm.reportValidity();
        return;
    }

    const email = loginEmailInput.value.trim();
    const password = loginPasswordInput.value;

    // Disable button and show loading state
    const originalBtnText = loginBtn.textContent;
    loginBtn.disabled = true;
    loginBtn.textContent = 'Logging in...';
    loginBtn.style.opacity = '0.6';
    loginBtn.style.cursor = 'not-allowed';

    const formData = new FormData();
    formData.append('email', email);
    formData.append('password', password);

    try {
        await addLogsignTurnstileToken(formData, 'modal-login');
        const res = await fetch('php/login.php', { method: 'POST', body: formData });
        const data = await res.json();

        if (data.status === 'success') {
            try { localStorage.removeItem(touristLoginLockoutStorageKey); } catch (_) {}
            // Remember Me logic
            if (rememberMeCheckbox.checked) {
                setCookie('rememberedEmail', email, 30);
                setCookie('rememberedPassword', password, 30);
            } else {
                clearRememberedCredentials();
            }

            // Close the login modal so the success alert is fully visible.
            closeAuthModal();

            // Reset button state before redirect
            loginBtn.disabled = false;
            loginBtn.textContent = originalBtnText;
            loginBtn.style.opacity = '1';
            loginBtn.style.cursor = 'pointer';

            await Swal.fire({
                icon: 'success',
                title: 'Login Successful',
                text: 'Welcome back! Redirecting to your account.',
                confirmButtonColor: '#2B7066',
                zIndex: 10000
            });

            if (data.redirect_url) {
                window.location.href = data.redirect_url;
            } else {
                window.location.reload();
            }

        } else if ((data.status === 'locked' || data.locked === true) && Number(data.retry_after) > 0) {
            startTouristLoginLockout(data.retry_after, 0, email);

        } else if (data.status === 'banned') {
            loginBtn.disabled = false;
            loginBtn.textContent = originalBtnText;
            loginBtn.style.opacity = '1';
            loginBtn.style.cursor = 'pointer';

            showLoginFormError(data.message || 'This account has been banned.');

        } else {
            loginBtn.disabled = false;
            loginBtn.textContent = originalBtnText;
            loginBtn.style.opacity = '1';
            loginBtn.style.cursor = 'pointer';

            if (Number(data.attempts_remaining) > 0) {
                saveTouristLoginState({ endsAt: 0, email, attemptsRemaining: Number(data.attempts_remaining) });
            }
            showLoginFormError(data.message || 'Email or password is wrong.');
        }

    } catch (err) {
        console.error(err);
        loginBtn.disabled = false;
        loginBtn.textContent = originalBtnText;
        loginBtn.style.opacity = '1';
        loginBtn.style.cursor = 'pointer';

        showLoginFormError(err.message || 'Something went wrong. Please try again.');
    } finally {
        window.ItourTurnstile?.reset('modal-login');
    }
}

// Attach login form
loginForm?.addEventListener('submit', handleLogin);
  // --- Signup ---
async function handleLegacySignup() {
  const fname = document.getElementById("signupFirstName").value.trim();
  const lname = document.getElementById("signupLastName").value.trim();
  const email = document.getElementById("signupEmail").value.trim();
  const password = document.getElementById("signupPassword").value;
  const confirm = document.getElementById("signupConfirmPassword").value;

  const codeBoxes = document.querySelectorAll(".verification-code-container .code-box");
  let code = "";
  codeBoxes.forEach((box) => { code += box.value.trim(); });

  if (!fname || !lname || !email || !password || !confirm || code.length !== 6) {
    Swal.fire({
      icon: 'warning',
      title: 'Incomplete Data',
      text: 'Please fill all fields and enter 6-digit code!',
      confirmButtonColor: '#2B7066',
      zIndex: 10000
    });
    return;
  }

  // Disable button and show loading state
  const originalBtnText = signupBtn.textContent;
  signupBtn.disabled = true;
  signupBtn.textContent = 'Creating account...';
  signupBtn.style.opacity = '0.6';
  signupBtn.style.cursor = 'not-allowed';

  const formData = new FormData();
  formData.append("fname", fname);
  formData.append("lname", lname);
  formData.append("email", email);
  formData.append("password", password);
  formData.append("confirm", confirm);
  formData.append("code", code);
  formData.append("action", "verify_code");

  try {
    const res = await fetch("php/signup.php", { method: "POST", body: formData });
    const data = await res.json();

    if (data.status === "success") {
      // CRITICAL FIX: Close modal FIRST
      closeAuthModal();

      // Reset button
      signupBtn.disabled = false;
      signupBtn.textContent = originalBtnText;
      signupBtn.style.opacity = '1';
      signupBtn.style.cursor = 'pointer';

      // Show success toast (not behind modal)
      showToast('Account created successfully! Redirecting...', 'success');

      // Redirect after toast
      setTimeout(() => {
        window.location.reload();
      }, 1000);
    } else {
      signupBtn.disabled = false;
      signupBtn.textContent = originalBtnText;
      signupBtn.style.opacity = '1';
      signupBtn.style.cursor = 'pointer';

      Swal.fire({
        icon: 'error',
        title: 'Signup Failed',
        text: data.message,
        confirmButtonColor: '#2B7066',
        zIndex: 10000
      });
    }

  } catch (err) {
    console.error(err);
    signupBtn.disabled = false;
    signupBtn.textContent = originalBtnText;
    signupBtn.style.opacity = '1';
    signupBtn.style.cursor = 'pointer';

    Swal.fire({
      icon: 'error',
      title: 'Signup Failed',
      text: 'Something went wrong. Please try again.',
      confirmButtonColor: '#2B7066',
      zIndex: 10000
    });
  }
}

// The legacy two-step submit handler is retained above for reference only.

// --- Phase 1 → Phase 2 ---
if (false) {
  verifyEmailBtn.addEventListener("click", (e) => {
    e.preventDefault();
    const firstName = document.getElementById('signupFirstName').value.trim();
    const lastName = document.getElementById('signupLastName').value.trim();
    const password = document.getElementById('signupPassword').value.trim();
    const confirmPassword = document.getElementById('signupConfirmPassword').value.trim();

    if (!firstName || !lastName || !password || !confirmPassword) {
      Swal.fire({
        icon: 'warning',
        title: 'Incomplete Step 1',
        text: 'Please fill in all fields in Step 1.',
        confirmButtonColor: '#2B7066'
      });
      return;
    }

    if (password !== confirmPassword) {
      Swal.fire({
        icon: 'error',
        title: 'Passwords Do Not Match',
        text: 'Please make sure both passwords are identical.',
        confirmButtonColor: '#2B7066'
      });
      return;
    }

    // Show Phase 2
    phase1.classList.remove("active");
    phase2.classList.add("active");

    // Update step indicators
    const step1 = signupForm.querySelector('.phase-step[data-phase="1"]');
    const step2 = signupForm.querySelector('.phase-step[data-phase="2"]');
    step1.classList.remove('phase-active');
    step1.classList.add('phase-completed');
    step2.classList.remove('phase-inactive');
    step2.classList.add('phase-active');
  });
}

  // --- Three-step signup ---
  function signupAddress() {
    return [signupField("signupStreet").value.trim(), selectedSignupLocation("signupBarangay"), selectedSignupLocation("signupCity"), selectedSignupLocation("signupProvince"), selectedSignupLocation("signupRegion"), signupField("signupPostalCode").value.trim(), selectedSignupLocation("signupCountry")].filter(Boolean).join(", ");
  }

  function validateSignupDetails() {
    const requiredText = ["signupFirstName", "signupLastName", "signupPhone", "signupStreet", "signupPostalCode"];
    if (!requiredText.every(id => signupField(id).value.trim()) || !signupLocationIds.every(id => signupField(id).value)) {
      Swal.fire({ icon: "warning", title: "Complete Your Details", text: "Enter your name, contact number, and complete address.", confirmButtonColor: "#2B7066" });
      return false;
    }
    const phone = signupField("signupPhone").value.trim();
    if (!/^[0-9+()\-\s]{7,30}$/.test(phone) || phone.replace(/\D/g, "").length < 7) {
      Swal.fire({ icon: "warning", title: "Check Contact Number", text: "Enter a valid contact number using at least 7 digits.", confirmButtonColor: "#2B7066" });
      return false;
    }
    return true;
  }

  signupDetailsNextBtn?.addEventListener("click", () => { if (validateSignupDetails()) setSignupPhase(2); });
  signupEmailBackBtn?.addEventListener("click", () => setSignupPhase(1));
  signupPasswordBackBtn?.addEventListener("click", () => setSignupPhase(2));

  verifySignupCodeBtn?.addEventListener("click", async () => {
    const email = signupField("signupEmail").value.trim();
    const code = Array.from(signupForm.querySelectorAll(".code-box")).map(box => box.value.trim()).join("");
    if (!email || !/^\S+@\S+\.\S+$/.test(email) || code.length !== 6) {
      Swal.fire({ icon: "warning", title: "Verify Your Email", text: "Enter a valid email and the complete 6-digit code.", confirmButtonColor: "#2B7066" });
      return;
    }
    const originalText = verifySignupCodeBtn.textContent;
    verifySignupCodeBtn.disabled = true;
    verifySignupCodeBtn.textContent = "Verifying...";
    try {
      const formData = new FormData();
      formData.append("action", "verify_code");
      formData.append("email", email);
      formData.append("code", code);
      const response = await fetch("php/signup.php", { method: "POST", body: formData });
      const data = await response.json();
      if (!response.ok || data.status !== "success") throw new Error(data.message || "The verification code is invalid.");
      signupField("signupVerifiedEmail").textContent = email;
      setSignupPhase(3);
      signupField("signupPassword").focus();
    } catch (error) {
      Swal.fire({ icon: "error", title: "Verification Failed", text: error.message, confirmButtonColor: "#2B7066" });
    } finally {
      verifySignupCodeBtn.disabled = false;
      verifySignupCodeBtn.textContent = originalText;
    }
  });

  const signupPasswordInput = signupField("signupPassword");
  const signupConfirmInput = signupField("signupConfirmPassword");
  function passwordChecks() {
    const password = signupPasswordInput.value;
    const checks = { length: password.length >= 6, letter: /[A-Za-z]/.test(password), number: /\d/.test(password), match: password.length > 0 && password === signupConfirmInput.value };
    Object.entries(checks).forEach(([rule, valid]) => signupForm.querySelector(`[data-password-rule="${rule}"]`)?.classList.toggle("is-valid", valid));
    return Object.values(checks).every(Boolean);
  }
  signupPasswordInput?.addEventListener("input", passwordChecks);
  signupConfirmInput?.addEventListener("input", passwordChecks);

  async function handleSignup() {
    if (!passwordChecks()) {
      Swal.fire({ icon: "warning", title: "Password Requirements", text: "Use at least 6 characters with at least one letter and one number, then confirm it correctly.", confirmButtonColor: "#2B7066" });
      return;
    }
    const originalBtnText = signupBtn.textContent;
    signupBtn.disabled = true;
    signupBtn.textContent = "Creating account...";
    let rateLimited = false;
    try {
      const formData = new FormData();
      formData.append("action", "complete_signup");
      formData.append("fname", signupField("signupFirstName").value.trim());
      formData.append("lname", signupField("signupLastName").value.trim());
      formData.append("phone", signupField("signupPhone").value.trim());
      formData.append("address", signupAddress());
      formData.append("email", signupField("signupEmail").value.trim());
      formData.append("password", signupPasswordInput.value);
      formData.append("confirm", signupConfirmInput.value);
      await addLogsignTurnstileToken(formData, "modal-signup-complete");
      const response = await fetch("php/signup.php", { method: "POST", body: formData });
      const data = await response.json();
      rateLimited = handleAuthRateLimit(response, data, signupBtn, originalBtnText);
      if (rateLimited) return;
      if (!response.ok || data.status !== "success") throw new Error(data.message || "Account creation failed.");
      closeAuthModal();
      showToast("Account created successfully! Redirecting...", "success");
      setTimeout(() => window.location.reload(), 1000);
    } catch (error) {
      Swal.fire({ icon: "error", title: "Signup Failed", text: error.message, confirmButtonColor: "#2B7066" });
    } finally {
      window.ItourTurnstile?.reset("modal-signup-complete");
      if (!rateLimited) {
        signupBtn.disabled = false;
        signupBtn.textContent = originalBtnText;
      }
    }
  }

  signupBtn?.addEventListener("click", handleSignup);


  // --- Verification code auto-next / auto-prev ---
  const codeBoxes = document.querySelectorAll(".verification-code-container .code-box");

  codeBoxes.forEach((box, idx) => {
    box.addEventListener("input", (e) => {
      // Keep only the first character
      box.value = box.value.replace(/[^0-9]/g, '').charAt(0) || '';
      if (box.value && idx < codeBoxes.length - 1) {
        codeBoxes[idx + 1].focus();
      }
    });

    box.addEventListener("keydown", (e) => {
      if (e.key === "Backspace") {
        if (box.value === '' && idx > 0) {
          codeBoxes[idx - 1].focus();
        } else {
          box.value = '';
        }
      } else if (e.key >= '0' && e.key <= '9') {
        // Allow number keys
      } else if (e.key !== "Tab") {
        e.preventDefault(); // Prevent non-numeric keys
      }
    });
  });

// --- Google Sign-In with processing state ---
const googleLoginBtn = document.getElementById("googleLoginBtn");
const googleSignupBtn = document.getElementById("googleSignupBtn");

function handleGoogleSignIn(button, redirectUrl) {
  if (!button) return;

  const textSpan = button.querySelector('.btn-text');
  if (!textSpan) return;

  const googleLogo = button.querySelector('img');
  const originalText = textSpan.textContent;

  const resetGoogleButton = () => {
    button.disabled = false;
    button.classList.remove('is-processing');
    button.removeAttribute('aria-busy');
    button.querySelector('.google-signin-spinner')?.remove();
    if (googleLogo) googleLogo.hidden = false;
    textSpan.textContent = originalText;
  };

  button.addEventListener("click", () => {
    if (button.disabled) return;

    button.disabled = true;
    button.classList.add('is-processing');
    button.setAttribute('aria-busy', 'true');

    const spinner = document.createElement('span');
    spinner.className = 'google-signin-spinner';
    spinner.setAttribute('aria-hidden', 'true');
    if (googleLogo) googleLogo.hidden = true;
    button.insertBefore(spinner, textSpan);
    textSpan.textContent = "Connecting to Google...";

    window.location.href = redirectUrl;

    // Restore the control if navigation is blocked or fails to begin.
    setTimeout(resetGoogleButton, 5000);
  });

  window.addEventListener('pageshow', resetGoogleButton);
}

// Initialize both buttons
handleGoogleSignIn(googleLoginBtn, "google_login.php");
handleGoogleSignIn(googleSignupBtn, "google_login.php");


  // --- Enter key support ---
  document.addEventListener("keydown", (e) => {
    if (!modalOverlay || modalOverlay.style.display !== "flex") return;
    if (e.key !== "Enter") return;

    if (!signupForm.classList.contains("logsign-hidden") && phase1.classList.contains("active")) {
      signupDetailsNextBtn?.click();
    } else if (!signupForm.classList.contains("logsign-hidden") && phase2.classList.contains("active")) {
      verifySignupCodeBtn?.click();
    } else if (!signupForm.classList.contains("logsign-hidden") && phase3.classList.contains("active")) {
      signupBtn?.click();
    } else if (!loginForm.classList.contains("logsign-hidden")) {
      e.preventDefault();
      loginForm.requestSubmit();
    }
  });

// --- FORGOT PASSWORD SECTION ---
const forgotPasswordLink = document.querySelector('.forgot-password');
const forgotPasswordForm = document.getElementById('forgotPasswordForm');
const forgotSendCodeBtn = document.getElementById('forgotSendCodeBtn');
const forgotEmailInput = document.getElementById('forgotEmail');
const forgotCodeBoxes = document.querySelectorAll('.forgot-code-box');
const emailStatusMsg = document.getElementById('forgotEmailStatus');
const forgotCodeCountdown = document.getElementById('forgotCodeCountdown');
const saveNewPasswordBtn = document.getElementById('saveNewPasswordBtn');

if (forgotPasswordLink && forgotPasswordForm && forgotEmailInput && forgotSendCodeBtn && emailStatusMsg && saveNewPasswordBtn) {

  let forgotEmailCheckTimer = null;
  let forgotEmailCheckVersion = 0;
  let forgotEmailValidated = '';
  let forgotResendCountdown = null;

  // Disable Send Code initially
  forgotSendCodeBtn.disabled = true;
  forgotSendCodeBtn.style.cursor = 'not-allowed';
  forgotSendCodeBtn.style.backgroundColor = '#999';

  // --- Open Forgot Password Modal ---
  forgotPasswordLink.addEventListener('click', () => {
    openAuthModal();
    loginForm.classList.add('logsign-hidden');
    signupForm.classList.add('logsign-hidden');
    forgotPasswordForm.classList.remove('logsign-hidden');
    getLogsignTurnstile().then(helper => helper.render('modal-forgot-send')).catch(() => {});

    forgotEmailInput.value = '';
    clearTimeout(forgotEmailCheckTimer);
    clearInterval(forgotResendCountdown);
    forgotEmailCheckVersion += 1;
    forgotEmailValidated = '';
    emailStatusMsg.textContent = '';
    forgotCodeBoxes.forEach(b => b.value = '');
    forgotSendCodeBtn.disabled = true;
    forgotSendCodeBtn.style.cursor = 'not-allowed';
    forgotSendCodeBtn.style.backgroundColor = '#999';

    // Reset phase indicators
    const step1 = forgotPasswordForm.querySelector('.phase-step[data-phase="1"]');
    const step2 = forgotPasswordForm.querySelector('.phase-step[data-phase="2"]');
    step1.classList.add('phase-active');
    step1.classList.remove('phase-completed');
    step2.classList.remove('phase-active');
    step2.classList.add('phase-inactive');

    forgotPasswordForm.querySelectorAll('.forgot-phase').forEach(p => p.classList.remove('active'));
    forgotPasswordForm.querySelector('.forgot-phase-1').classList.add('active');
  });

  // --- Check email and enable Send Code ---
  forgotEmailInput.addEventListener('input', () => {
    const email = forgotEmailInput.value.trim();
    const checkVersion = ++forgotEmailCheckVersion;
    clearTimeout(forgotEmailCheckTimer);
    clearInterval(forgotResendCountdown);
    forgotEmailValidated = '';
    forgotSendCodeBtn.disabled = true;
    forgotSendCodeBtn.textContent = 'Send Code';
    forgotSendCodeBtn.style.cursor = 'not-allowed';
    forgotSendCodeBtn.style.backgroundColor = '#999';

    if (!forgotEmailInput.checkValidity()) {
      emailStatusMsg.textContent = email ? 'Enter a valid email address.' : '';
      emailStatusMsg.style.color = email ? '#a43b43' : '';
      return;
    }

    emailStatusMsg.textContent = 'Checking for your tourist account...';
    emailStatusMsg.style.color = '#687b75';
    forgotEmailCheckTimer = setTimeout(async () => {
      try {
        const formData = new FormData();
        formData.append('action', 'check_email');
        formData.append('email', email);
        const res = await fetch('php/forgot_password.php', { method: 'POST', body: formData });
        const data = await res.json();
        if (checkVersion !== forgotEmailCheckVersion || email !== forgotEmailInput.value.trim()) return;

        if (res.ok && data.exists) {
          forgotEmailValidated = email;
          emailStatusMsg.textContent = 'Tourist account found. You can send a verification code.';
          emailStatusMsg.style.color = '#176b55';
          forgotSendCodeBtn.disabled = false;
          forgotSendCodeBtn.style.cursor = 'pointer';
          forgotSendCodeBtn.style.backgroundColor = '#2E7B45';
        } else {
          emailStatusMsg.textContent = 'No tourist account is registered with this email.';
          emailStatusMsg.style.color = '#a43b43';
        }
      } catch (err) {
        if (checkVersion !== forgotEmailCheckVersion) return;
        console.error(err);
        emailStatusMsg.textContent = 'Unable to verify this email right now.';
        emailStatusMsg.style.color = '#a43b43';
      }
    }, 350);
  });

  // --- Send verification code ---
  forgotSendCodeBtn.addEventListener('click', async () => {
    const email = forgotEmailInput.value.trim();
    if (!forgotEmailInput.checkValidity()) {
      forgotEmailInput.reportValidity();
      return;
    }
    if (forgotEmailValidated !== email) return;

    forgotSendCodeBtn.disabled = true;
    forgotSendCodeBtn.textContent = 'Sending...';
    forgotSendCodeBtn.style.cursor = 'not-allowed';

    try {
      const formData = new FormData();
      formData.append('action', 'send_code');
      formData.append('email', email);
      await addLogsignTurnstileToken(formData, 'modal-forgot-send');

      const res = await fetch('php/send_verification_codeFP.php', { method: 'POST', body: formData });
      const data = await res.json();
      if (handleAuthRateLimit(res, data, forgotSendCodeBtn, 'Send Code')) return;
      if (!res.ok || data.status !== 'success') throw new Error(data.message || 'Failed to send code.');
      alert(data.message);

      forgotSendCodeBtn.textContent = 'Resend Code';
      forgotSendCodeBtn.style.backgroundColor = '#999';
      forgotCodeBoxes.forEach(box => box.disabled = false);

      // Countdown
      let timer = 90;
      forgotCodeCountdown.style.display = 'block';
      forgotCodeCountdown.textContent = `Resend code in ${timer}s`;

      clearInterval(forgotResendCountdown);
      forgotResendCountdown = setInterval(() => {
        timer--;
        forgotCodeCountdown.textContent = timer > 0 ? `Resend code in ${timer}s` : 'You can resend code now';
        if (timer <= 0) {
          clearInterval(forgotResendCountdown);
          const canResend = forgotEmailValidated === forgotEmailInput.value.trim();
          forgotSendCodeBtn.disabled = !canResend;
          forgotSendCodeBtn.style.cursor = canResend ? 'pointer' : 'not-allowed';
          forgotSendCodeBtn.style.backgroundColor = canResend ? '#2E7B45' : '#999';
        }
      }, 1000);

    } catch (err) {
      alert(err.message || 'Failed to send code.');
      const canRetry = forgotEmailValidated === forgotEmailInput.value.trim();
      forgotSendCodeBtn.disabled = !canRetry;
      forgotSendCodeBtn.textContent = 'Send Code';
      forgotSendCodeBtn.style.cursor = canRetry ? 'pointer' : 'not-allowed';
      forgotSendCodeBtn.style.backgroundColor = canRetry ? '#2E7B45' : '#999';
    } finally {
      window.ItourTurnstile?.reset('modal-forgot-send');
    }
  });

  // --- Verification code input navigation ---
  forgotCodeBoxes.forEach((box, idx) => {
    box.addEventListener('input', () => {
      box.value = box.value.replace(/[^0-9]/g, '').charAt(0) || '';
      if (box.value && idx < forgotCodeBoxes.length - 1) forgotCodeBoxes[idx + 1].focus();
    });

    box.addEventListener('keydown', (e) => {
      if (e.key === 'Backspace') {
        if (box.value === '' && idx > 0) forgotCodeBoxes[idx - 1].focus();
        else box.value = '';
      } else if (!/^[0-9]$/.test(e.key) && e.key !== 'Tab') {
        e.preventDefault();
      }
    });
  });

  // --- SweetAlert2 helper
function showAlert(type, title, message) {
  Swal.fire({
    icon: type,
    title: title,
    html: message.replace(/\n/g, '<br>'),
    confirmButtonColor: '#2B7066'
  });
}

// --- Verify code ---
let submitCodeBtn = document.createElement('button');
submitCodeBtn.textContent = 'Verify Code';
submitCodeBtn.type = 'button';
submitCodeBtn.style.marginTop = '6px';
forgotPasswordForm.querySelector('.forgot-phase-1').appendChild(submitCodeBtn);

submitCodeBtn.addEventListener('click', async () => {
  const email = forgotEmailInput.value.trim();
  const code = Array.from(forgotCodeBoxes).map(b => b.value.trim()).join('');
  if (code.length !== 6) {
    showAlert('warning', 'Invalid Code', 'Please enter the 6-digit code.');
    return;
  }

  try {
    const formData = new FormData();
    formData.append('action', 'verify_code');
    formData.append('email', email);
    formData.append('code', code);

    const res = await fetch('php/send_verification_codeFP.php', { method: 'POST', body: formData });
    const data = await res.json();

    if (data.valid) {
      // Show step 2
      forgotPasswordForm.querySelectorAll('.forgot-phase').forEach(p => p.classList.remove('active'));
      forgotPasswordForm.querySelector('.forgot-phase-2').classList.add('active');

      // Update phase indicators
      const step1 = forgotPasswordForm.querySelector('.phase-step[data-phase="1"]');
      const step2 = forgotPasswordForm.querySelector('.phase-step[data-phase="2"]');
      step1.classList.remove('phase-active');
      step1.classList.add('phase-completed');
      step2.classList.remove('phase-inactive');
      step2.classList.add('phase-active');

      // Setup password toggles for new password fields
      setupPasswordToggle("newPassword", "toggleNewPassword");
      setupPasswordToggle("confirmNewPassword", "toggleConfirmNewPassword");

    } else {
      showAlert('error', 'Verification Failed', data.message || 'Invalid code.');
      forgotCodeBoxes.forEach(b => b.value = '');
      forgotCodeBoxes[0].focus();
    }
  } catch (err) {
    console.error(err);
    showAlert('error', 'Error', 'Failed to verify code.');
  }
});

// --- Save new password ---
saveNewPasswordBtn.addEventListener('click', async () => {
  const email = forgotEmailInput.value.trim();
  const newPassword = document.getElementById('newPassword').value.trim();
  const confirmPassword = document.getElementById('confirmNewPassword').value.trim();

  if (!newPassword || !confirmPassword) {
    showAlert('warning', 'Incomplete Data', 'Please fill in both password fields.');
    return;
  }
  if (newPassword !== confirmPassword) {
    showAlert('warning', 'Mismatch', 'Passwords do not match.');
    return;
  }

  try {
    const formData = new FormData();
    formData.append('action', 'save_new_password');
    formData.append('email', email);
    formData.append('newPassword', newPassword);

    const res = await fetch('php/forgot_password.php', { method: 'POST', body: formData });
    const data = await res.json();

    showAlert(data.status === 'success' ? 'success' : 'error', 'Password Update', data.message);

    if (data.status === 'success') {
      // Reset form
      forgotPasswordForm.querySelectorAll('.forgot-phase').forEach(p => p.classList.remove('active'));
      forgotPasswordForm.querySelector('.forgot-phase-1').classList.add('active');

      // Reset indicators
      const step1 = forgotPasswordForm.querySelector('.phase-step[data-phase="1"]');
      const step2 = forgotPasswordForm.querySelector('.phase-step[data-phase="2"]');
      step1.classList.add('phase-active');
      step1.classList.remove('phase-completed');
      step2.classList.remove('phase-active');
      step2.classList.add('phase-inactive');

      closeAuthModal();
    }
  } catch (err) {
    console.error(err);
    showAlert('error', 'Error', 'Failed to save new password.');
  }
});

  // --- Helper: setup password toggle ---
  function setupPasswordToggle(inputId, toggleId) {
    const input = document.getElementById(inputId);
    const toggle = document.getElementById(toggleId);
    if (!input || !toggle) return;
    toggle.src = 'img/passwordhide.png';
    input.type = 'password';

    toggle.onclick = () => {
      if (input.type === 'password') {
        input.type = 'text';
        toggle.src = 'img/passwordsee.png';
      } else {
        input.type = 'password';
        toggle.src = 'img/passwordhide.png';
      }
    };
  }

  // --- Enter key listener ---
forgotPasswordForm.addEventListener('keydown', (e) => {
  if (e.key !== 'Enter') return;

  const activeElement = document.activeElement;

  if (!forgotSendCodeBtn.disabled && activeElement === forgotEmailInput) {
    // Press Enter on email input → send code
    forgotSendCodeBtn.click();
  } else if (forgotPasswordForm.querySelector('.forgot-phase-2').classList.contains('active')) {
    // Phase 2 active → press Enter → save new password
    saveNewPasswordBtn.click();
  } else {
    // Phase 1 active → verify code
    submitCodeBtn.click();
  }
});

}
}

// Initialize
document.addEventListener("DOMContentLoaded", initLogSignEvents);
