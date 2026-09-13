(function () {
  "use strict";

  const byId = (id) => document.getElementById(id);
  const signupPanel = byId("signupPagePanel");
  const loginPanel = byId("loginPagePanel");
  const forgotPanel = byId("forgotPagePanel");
  const signupForm = byId("touristSignupForm");
  if (!signupPanel || !loginPanel || !forgotPanel || !signupForm) return;

  const showAlert = (icon, title, text) => window.Swal
    ? Swal.fire({ icon, title, text, confirmButtonColor: "#176b55" })
    : Promise.resolve(window.alert(text));

  const handleRateLimit = (response, payload, button, defaultText) => Boolean(
    window.RequestLimitModal?.handle(response, payload, { button, defaultText })
  );

  function setCookie(name, value, days) {
    document.cookie = `${name}=${encodeURIComponent(value)}; expires=${new Date(Date.now() + days * 864e5).toUTCString()}; path=/; SameSite=Lax`;
  }

  function getCookie(name) {
    return document.cookie.split("; ").reduce((result, entry) => {
      const parts = entry.split("=");
      return parts[0] === name ? decodeURIComponent(parts.slice(1).join("=")) : result;
    }, "");
  }

  function deleteCookie(name) {
    document.cookie = `${name}=; Max-Age=0; path=/; SameSite=Lax`;
  }

  function saveRememberedCredentials(email, password) {
    setCookie("rememberedEmail", email, 30);
    setCookie("rememberedPassword", password, 30);
  }

  function clearRememberedCredentials() {
    deleteCookie("rememberedEmail");
    deleteCookie("rememberedPassword");
  }

  function reportFirstInvalid(fields) {
    const invalidField = fields.find(field => field && !field.checkValidity());
    if (!invalidField) return false;
    invalidField.reportValidity();
    return true;
  }

  const signupSteps = Array.from(document.querySelectorAll("[data-signup-step]"));
  const indicators = Array.from(document.querySelectorAll("[data-step-indicator]"));
  let currentStep = 1;

  function goToStep(step) {
    currentStep = step;
    signupPanel.dataset.currentStep = String(step);
    signupSteps.forEach(section => section.classList.toggle("is-active", Number(section.dataset.signupStep) === step));
    indicators.forEach(indicator => {
      const number = Number(indicator.dataset.stepIndicator);
      indicator.classList.toggle("is-active", number === step);
      indicator.classList.toggle("is-complete", number < step);
      const badge = indicator.querySelector("b");
      if (badge) badge.textContent = number < step ? "✓" : String(number);
    });
  }

  document.querySelectorAll("[data-go-step]").forEach(button => button.addEventListener("click", () => goToStep(Number(button.dataset.goStep))));

  byId("showPageLogin").addEventListener("click", () => {
    signupPanel.hidden = true;
    forgotPanel.hidden = true;
    loginPanel.hidden = false;
    byId("pageLoginError").hidden = true;
    byId("pageLoginEmail").focus();
  });
  byId("showPageSignup").addEventListener("click", () => {
    loginPanel.hidden = true;
    forgotPanel.hidden = true;
    signupPanel.hidden = false;
  });

  byId("showPageForgot").addEventListener("click", () => {
    signupPanel.hidden = true;
    loginPanel.hidden = true;
    forgotPanel.hidden = false;
    resetRecovery();
    const recoveryEmail = byId("pageForgotEmail");
    recoveryEmail.value = byId("pageLoginEmail").value.trim();
    if (recoveryEmail.value) recoveryEmail.dispatchEvent(new Event("input", { bubbles: true }));
    recoveryEmail.focus();
  });

  byId("backToPageLogin").addEventListener("click", showLoginPanel);

  document.querySelectorAll("[data-toggle-password]").forEach(button => {
    button.addEventListener("click", () => {
      const input = byId(button.dataset.togglePassword);
      const showing = input.type === "text";
      input.type = showing ? "password" : "text";
      button.setAttribute("aria-label", showing ? "Show password" : "Hide password");
      button.setAttribute("aria-pressed", String(!showing));
    });
  });

  const locationIds = ["pageSignupCountry", "pageSignupRegion", "pageSignupProvince", "pageSignupCity", "pageSignupBarangay"];
  const geoCache = new Map();
  const selectedName = (id) => byId(id)?.selectedOptions[0]?.dataset.name || "";

  function setStatus(id, message, type = "") {
    const status = byId(id);
    status.textContent = message;
    status.className = `tourist-form-status${type ? ` is-${type}` : ""}`;
  }

  function resetSelect(id, message) {
    const select = byId(id);
    select.innerHTML = `<option value="">${message}</option>`;
    select.disabled = true;
  }

  async function getGeoNames(action, parameters = {}) {
    const query = new URLSearchParams({ action, scope: "signup", address_version: "2", ...parameters });
    const key = query.toString();
    if (!geoCache.has(key)) {
      geoCache.set(key, fetch(`php/geonames_locations.php?${query}`, { headers: { Accept: "application/json" } })
        .then(async response => {
          const result = await response.json();
          if (!response.ok || !result.success) throw new Error(result.message || "Locations could not be loaded.");
          return result;
        }).catch(error => { geoCache.delete(key); throw error; }));
    }
    return geoCache.get(key);
  }

  function fillSelect(id, locations, placeholder) {
    const select = byId(id);
    select.innerHTML = `<option value="">${placeholder}</option>`;
    const names = new Set();
    locations.forEach(location => {
      const normalized = String(location.name || "").trim().toLowerCase();
      if (!normalized || names.has(normalized)) return;
      names.add(normalized);
      const option = document.createElement("option");
      option.value = String(location.id);
      option.textContent = location.name;
      option.dataset.name = location.name;
      option.dataset.countryCode = location.country_code || "";
      option.dataset.featureCode = location.feature_code || "";
      select.appendChild(option);
    });
    select.disabled = select.options.length <= 1;
  }

  async function loadChildren(parentId, childId, placeholder, allowedCodes) {
    const parent = byId(parentId);
    const parentValue = parent.value;
    resetSelect(childId, "Loading locations...");
    if (!parentValue) return;
    const result = await getGeoNames("children", { parent_id: parentValue });
    if (parent.value !== parentValue) return;
    const all = Array.isArray(result.locations) ? result.locations : [];
    const preferred = all.filter(location => allowedCodes.includes(location.feature_code));
    fillSelect(childId, preferred.length ? preferred : all, placeholder);
  }

  async function initializeCountries() {
    try {
      const result = await getGeoNames("countries");
      fillSelect("pageSignupCountry", result.locations || [], "Select a country");
      setStatus("pageAddressStatus", "");
    } catch (error) {
      resetSelect("pageSignupCountry", "Countries could not be loaded");
      setStatus("pageAddressStatus", error.message, "error");
    }
  }

  byId("pageSignupCountry").addEventListener("change", async () => {
    resetSelect("pageSignupProvince", "Select region first"); resetSelect("pageSignupCity", "Select province first"); resetSelect("pageSignupBarangay", "Select city first"); byId("pageSignupPostal").value = "";
    try { await loadChildren("pageSignupCountry", "pageSignupRegion", "Select a region or state", ["ADM1"]); }
    catch (error) { setStatus("pageAddressStatus", error.message, "error"); }
  });
  byId("pageSignupRegion").addEventListener("change", async () => {
    resetSelect("pageSignupCity", "Select province first"); resetSelect("pageSignupBarangay", "Select city first"); byId("pageSignupPostal").value = "";
    try { await loadChildren("pageSignupRegion", "pageSignupProvince", "Select a province", ["ADM2"]); }
    catch (error) { setStatus("pageAddressStatus", error.message, "error"); }
  });
  byId("pageSignupProvince").addEventListener("change", async () => {
    resetSelect("pageSignupBarangay", "Select city first"); byId("pageSignupPostal").value = "";
    try { await loadChildren("pageSignupProvince", "pageSignupCity", "Select a city or municipality", ["ADM3"]); }
    catch (error) { setStatus("pageAddressStatus", error.message, "error"); }
  });
  byId("pageSignupCity").addEventListener("change", async () => {
    byId("pageSignupPostal").value = "";
    try { await loadChildren("pageSignupCity", "pageSignupBarangay", "Select a barangay", ["ADM4", "PPL", "PPLX"]); }
    catch (error) { setStatus("pageAddressStatus", error.message, "error"); }
  });
  byId("pageSignupBarangay").addEventListener("change", async () => {
    const postal = byId("pageSignupPostal");
    const city = selectedName("pageSignupCity");
    const countryCode = byId("pageSignupCountry").selectedOptions[0]?.dataset.countryCode || "";
    postal.value = city && countryCode ? "Loading..." : "";
    if (!city || !countryCode) return;
    try {
      const result = await getGeoNames("postal_code", { country_code: countryCode, place_name: city.replace(/^Municipality of\s+/i, "") });
      postal.value = result.postal_code || "";
      if (!postal.value) throw new Error("Postal code unavailable. Please enter your postal code.");
      setStatus("pageAddressStatus", "Address location completed.", "success");
    } catch (error) { postal.value = ""; postal.readOnly = false; postal.placeholder = "Enter postal code"; setStatus("pageAddressStatus", "Please enter your postal code; automatic lookup is unavailable.", "error"); }
  });

  function addressLine() {
    return [byId("pageSignupStreet").value.trim(), selectedName("pageSignupBarangay"), selectedName("pageSignupCity"), selectedName("pageSignupProvince"), selectedName("pageSignupRegion"), byId("pageSignupPostal").value.trim(), selectedName("pageSignupCountry")].filter(Boolean).join(", ");
  }

  function detailsAreValid() {
    const required = ["pageSignupFirstName", "pageSignupLastName", "pageSignupPhone"].map(byId);
    if (reportFirstInvalid(required)) return false;
    const phone = byId("pageSignupPhone").value.trim();
    if (!/^[0-9+()\-\s]{7,30}$/.test(phone) || phone.replace(/\D/g, "").length < 7) {
      showAlert("warning", "Check contact number", "Enter a valid contact number containing at least 7 digits.");
      return false;
    }
    return true;
  }

  byId("pageDetailsNext").addEventListener("click", () => { if (detailsAreValid()) goToStep(2); });

  function addressIsValid() {
    const addressFields = [...locationIds.map(byId), byId("pageSignupPostal"), byId("pageSignupStreet")];
    if (reportFirstInvalid(addressFields)) return false;
    return true;
  }

  byId("pageAddressNext").addEventListener("click", () => { if (addressIsValid()) goToStep(3); });

  const codeBoxes = Array.from(document.querySelectorAll(".page-code-box"));
  codeBoxes.forEach((box, index) => {
    box.addEventListener("input", () => {
      box.value = box.value.replace(/\D/g, "").slice(0, 1);
      if (box.value && codeBoxes[index + 1]) codeBoxes[index + 1].focus();
    });
    box.addEventListener("keydown", event => {
      if (event.key === "Backspace" && !box.value && codeBoxes[index - 1]) codeBoxes[index - 1].focus();
    });
    box.addEventListener("paste", event => {
      const digits = event.clipboardData.getData("text").replace(/\D/g, "").slice(0, 6);
      if (digits.length < 2) return;
      event.preventDefault();
      digits.split("").forEach((digit, digitIndex) => { if (codeBoxes[digitIndex]) codeBoxes[digitIndex].value = digit; });
      codeBoxes[Math.min(digits.length, 6) - 1].focus();
    });
  });

  let countdownTimer = null;
  byId("pageSendCode").addEventListener("click", async () => {
    const button = byId("pageSendCode");
    const email = byId("pageSignupEmail").value.trim();
    if (!byId("pageSignupEmail").checkValidity()) { byId("pageSignupEmail").reportValidity(); return; }
    button.disabled = true; button.textContent = "Sending...";
    try {
      const body = new FormData();
      body.append("action", "send_code"); body.append("email", email); body.append("fname", byId("pageSignupFirstName").value.trim()); body.append("lname", byId("pageSignupLastName").value.trim());
      const response = await fetch("php/signup.php", { method: "POST", body });
      const result = await response.json();
      if (handleRateLimit(response, result, button, "Send code")) return;
      if (!response.ok || result.status !== "success") throw new Error(result.message || "The verification code could not be sent.");
      setStatus("pageEmailStatus", result.message, "success");
      codeBoxes[0].focus();
      let seconds = 90;
      clearInterval(countdownTimer);
      button.textContent = `Resend in ${seconds}s`;
      countdownTimer = setInterval(() => {
        seconds -= 1;
        button.textContent = seconds > 0 ? `Resend in ${seconds}s` : "Resend code";
        if (seconds <= 0) { clearInterval(countdownTimer); button.disabled = false; }
      }, 1000);
    } catch (error) {
      button.disabled = false; button.textContent = "Send code";
      setStatus("pageEmailStatus", error.message, "error");
    }
  });

  byId("pageVerifyCode").addEventListener("click", async () => {
    const button = byId("pageVerifyCode");
    const email = byId("pageSignupEmail").value.trim();
    const code = codeBoxes.map(box => box.value).join("");
    if (!byId("pageSignupEmail").checkValidity()) { byId("pageSignupEmail").reportValidity(); return; }
    if (reportFirstInvalid(codeBoxes)) return;
    button.disabled = true; button.textContent = "Verifying...";
    try {
      const body = new FormData(); body.append("action", "verify_code"); body.append("email", email); body.append("code", code);
      const response = await fetch("php/signup.php", { method: "POST", body });
      const result = await response.json();
      if (!response.ok || result.status !== "success") throw new Error(result.message || "The code is invalid.");
      byId("pageVerifiedEmail").textContent = email;
      goToStep(4);
      byId("pageSignupPassword").focus();
    } catch (error) { showAlert("error", "Verification failed", error.message); }
    finally { button.disabled = false; button.textContent = "Verify and continue"; }
  });

  function passwordChecks() {
    const password = byId("pageSignupPassword").value;
    const checks = { length: password.length >= 6, letter: /[A-Za-z]/.test(password), number: /\d/.test(password), match: password.length > 0 && password === byId("pageSignupConfirm").value };
    Object.entries(checks).forEach(([rule, valid]) => document.querySelector(`[data-rule="${rule}"]`)?.classList.toggle("is-valid", valid));
    return Object.values(checks).every(Boolean);
  }
  byId("pageSignupPassword").addEventListener("input", passwordChecks);
  byId("pageSignupConfirm").addEventListener("input", passwordChecks);

  byId("pageCreateAccount").addEventListener("click", async () => {
    if (reportFirstInvalid([byId("pageSignupPassword"), byId("pageSignupConfirm")])) return;
    if (!passwordChecks()) { showAlert("warning", "Check your password", "Use at least 6 characters with a letter and number, then confirm it correctly."); return; }
    const button = byId("pageCreateAccount"); button.disabled = true; button.textContent = "Creating account...";
    let rateLimited = false;
    try {
      const body = new FormData();
      body.append("action", "complete_signup"); body.append("fname", byId("pageSignupFirstName").value.trim()); body.append("lname", byId("pageSignupLastName").value.trim()); body.append("phone", byId("pageSignupPhone").value.trim()); body.append("address", addressLine()); body.append("email", byId("pageSignupEmail").value.trim()); body.append("password", byId("pageSignupPassword").value); body.append("confirm", byId("pageSignupConfirm").value);
      const response = await fetch("php/signup.php", { method: "POST", body });
      const result = await response.json();
      rateLimited = handleRateLimit(response, result, button, "Create account");
      if (rateLimited) return;
      if (!response.ok || result.status !== "success") throw new Error(result.message || "Your account could not be created.");
      await showAlert("success", "Account created", "Welcome to iTour Mercedes!");
      window.location.href = result.redirect_url || "homepage.php";
    } catch (error) { showAlert("error", "Signup failed", error.message); }
    finally { if (!rateLimited) { button.disabled = false; button.textContent = "Create account"; } }
  });

  const loginEmail = byId("pageLoginEmail");
  const loginPassword = byId("pageLoginPassword");
  const rememberMe = byId("pageRememberMe");
  const loginError = byId("pageLoginError");
  const rememberedEmail = getCookie("rememberedEmail");
  const rememberedPassword = getCookie("rememberedPassword");
  if (rememberedEmail && rememberedPassword) {
    loginEmail.value = rememberedEmail;
    loginPassword.value = rememberedPassword;
    rememberMe.checked = true;
  } else if (rememberedEmail || rememberedPassword) {
    clearRememberedCredentials();
  }

  rememberMe.addEventListener("change", () => {
    if (!rememberMe.checked) clearRememberedCredentials();
  });

  const touristLoginStateKey = "itourTouristLoginLockout";
  let pageLoginTimer = null;
  let pageLoginEndsAt = 0;

  function readTouristLoginState(rawValue = null) {
    try {
      const raw = rawValue ?? localStorage.getItem(touristLoginStateKey) ?? sessionStorage.getItem(touristLoginStateKey);
      if (!raw) return null;
      if (/^\d+$/.test(raw)) return {endsAt:Number(raw),email:"",attemptsRemaining:0};
      const state = JSON.parse(raw);
      if (!state || typeof state !== "object") return null;
      if (Number(state.attemptsRemaining) > 0 && Number(state.endsAt || 0) <= Date.now()
        && (!Number(state.updatedAt) || Date.now() - Number(state.updatedAt) >= 120000)) return null;
      return state;
    } catch (_) { return null; }
  }

  function saveTouristLoginState(state) {
    try {
      localStorage.setItem(touristLoginStateKey, JSON.stringify({...state, updatedAt:Date.now()}));
      sessionStorage.removeItem(touristLoginStateKey);
    } catch (_) {}
  }

  function showPageLoginError(message, type = "error") {
    const messageElement = loginError?.querySelector("span");
    if (messageElement) messageElement.textContent = message;
    if (loginError) {
      loginError.classList.toggle("is-success", type === "success");
      loginError.hidden = false;
    }
  }

  function clearPageLoginError() {
    if (byId("pageLoginForm").dataset.locked === "true") return;
    if (loginError) { loginError.hidden = true; loginError.classList.remove("is-success"); }
  }

  function resetPageLoginLockout(clearStored = true) {
    if (pageLoginTimer) clearInterval(pageLoginTimer);
    pageLoginTimer = null; pageLoginEndsAt = 0;
    byId("pageLoginForm").removeAttribute("data-locked");
    loginEmail.disabled = false; loginPassword.disabled = false;
    const button = byId("pageLoginButton");
    button.disabled = false; button.textContent = "Login"; button.classList.remove("is-login-locked");
    if (clearStored) try { localStorage.removeItem(touristLoginStateKey); } catch (_) {}
  }

  function startPageLoginLockout(retryAfter, existingEndsAt = 0, lockedEmail = "") {
    if (pageLoginTimer) clearInterval(pageLoginTimer);
    pageLoginEndsAt = existingEndsAt > Date.now() ? existingEndsAt : Date.now() + Math.max(1, Math.ceil(Number(retryAfter) || 0)) * 1000;
    const email = String(lockedEmail || loginEmail.value || "").trim();
    saveTouristLoginState({endsAt:pageLoginEndsAt,email,attemptsRemaining:0});
    if (!loginEmail.value && email) loginEmail.value = email;
    byId("pageLoginForm").dataset.locked = "true";
    loginEmail.disabled = true; loginPassword.disabled = true;
    const button = byId("pageLoginButton");
    button.disabled = true; button.classList.add("is-login-locked");
    const render = () => {
      const remaining = Math.max(0, Math.ceil((pageLoginEndsAt - Date.now()) / 1000));
      button.innerHTML = `<span class="tourist-login-spinner" aria-hidden="true"></span><span>Try again in ${remaining}s</span>`;
      showPageLoginError(`Too many failed login attempts. Please wait ${remaining} seconds before trying again.`);
      if (remaining <= 0) {
        resetPageLoginLockout();
        showPageLoginError("You may now try logging in again.", "success");
      }
    };
    render();
    pageLoginTimer = setInterval(render, 1000);
  }

  loginEmail.addEventListener("input", clearPageLoginError);
  loginPassword.addEventListener("input", clearPageLoginError);

  byId("pageLoginForm").addEventListener("submit", async event => {
    event.preventDefault();
    const loginFormElement = event.currentTarget;
    if (loginFormElement.dataset.locked === "true") return;
    clearPageLoginError();
    if (!loginFormElement.checkValidity()) { loginFormElement.reportValidity(); return; }
    const email = loginEmail.value.trim(); const password = loginPassword.value;
    const button = byId("pageLoginButton");
    button.disabled = true;
    button.classList.add("is-login-loading");
    button.innerHTML = '<span class="tourist-login-spinner" aria-hidden="true"></span><span>Logging in...</span>';
    try {
      const body = new FormData(); body.append("email", email); body.append("password", password);
      const response = await fetch("php/login.php", { method: "POST", body });
      const result = await response.json();
      if (!response.ok || result.status !== "success") {
        if ((result.status === "locked" || result.locked === true) && Number(result.retry_after) > 0) {
          startPageLoginLockout(result.retry_after, 0, email);
        } else {
          if (Number(result.attempts_remaining) > 0) saveTouristLoginState({endsAt:0,email,attemptsRemaining:Number(result.attempts_remaining)});
          showPageLoginError(result.message || "Email or password is incorrect.");
        }
        return;
      }
      try { localStorage.removeItem(touristLoginStateKey); } catch (_) {}
      if (rememberMe.checked) saveRememberedCredentials(email, password); else clearRememberedCredentials();
      await showAlert("success", "Login successful", "Welcome back! Redirecting to your account.");
      window.location.href = result.redirect_url || "homepage.php";
    } catch (error) { showPageLoginError(error.message); }
    finally {
      button.classList.remove("is-login-loading");
      if (loginFormElement.dataset.locked !== "true") { button.disabled = false; button.textContent = "Login"; }
    }
  });

  try {
    const savedState = readTouristLoginState();
    const savedEndsAt = Number(savedState?.endsAt || 0);
    if (savedState?.email && !loginEmail.value) loginEmail.value = savedState.email;
    if (savedEndsAt > Date.now()) startPageLoginLockout(Math.ceil((savedEndsAt - Date.now()) / 1000), savedEndsAt, savedState?.email || "");
    else if (Number(savedState?.attemptsRemaining) > 0) showPageLoginError(`Email or password is wrong. ${savedState.attemptsRemaining} attempts remaining.`);
    else localStorage.removeItem(touristLoginStateKey);
  } catch (_) {}

  window.addEventListener("storage", event => {
    if (event.key !== touristLoginStateKey) return;
    const state = readTouristLoginState(event.newValue);
    const endsAt = Number(state?.endsAt || 0);
    if (endsAt > Date.now()) startPageLoginLockout(Math.ceil((endsAt - Date.now()) / 1000), endsAt, state?.email || "");
    else if (!state && byId("pageLoginForm").dataset.locked === "true") {
      resetPageLoginLockout(false);
      showPageLoginError("You may now try logging in again.", "success");
    } else if (Number(state?.attemptsRemaining) > 0) {
      if (state.email && !loginEmail.value) loginEmail.value = state.email;
      showPageLoginError(`Email or password is wrong. ${state.attemptsRemaining} attempts remaining.`);
    }
  });

  const forgotEmail = byId("pageForgotEmail");
  const forgotStatus = byId("pageForgotStatus");
  const forgotCodeBoxes = Array.from(document.querySelectorAll(".page-forgot-code"));
  const forgotPassword = byId("pageForgotPassword");
  const forgotConfirm = byId("pageForgotConfirm");
  let forgotCountdown = null;
  let forgotEmailCheckTimer = null;
  let forgotEmailCheckVersion = 0;
  let forgotEmailValidated = "";
  let recoveryVerified = false;

  function showLoginPanel() {
    forgotPanel.hidden = true;
    signupPanel.hidden = true;
    loginPanel.hidden = false;
    loginEmail.focus();
  }

  function setRecoveryStep(step) {
    document.querySelectorAll("[data-recovery-step]").forEach(panel => panel.classList.toggle("is-active", Number(panel.dataset.recoveryStep) === step));
    document.querySelectorAll("[data-recovery-indicator]").forEach(indicator => {
      const number = Number(indicator.dataset.recoveryIndicator);
      indicator.classList.toggle("is-active", number === step);
      indicator.classList.toggle("is-complete", number < step);
      const badge = indicator.querySelector("b");
      if (badge) badge.textContent = number < step ? "✓" : String(number);
    });
  }

  function resetRecovery() {
    clearInterval(forgotCountdown);
    recoveryVerified = false;
    setRecoveryStep(1);
    forgotCodeBoxes.forEach(box => { box.value = ""; });
    forgotPassword.value = "";
    forgotConfirm.value = "";
    forgotPanel.querySelectorAll("[data-toggle-password]").forEach(button => {
      const input = byId(button.dataset.togglePassword);
      if (input) input.type = "password";
      button.setAttribute("aria-label", "Show password");
      button.setAttribute("aria-pressed", "false");
    });
    clearTimeout(forgotEmailCheckTimer);
    forgotEmailCheckVersion += 1;
    forgotEmailValidated = "";
    byId("pageForgotSendCode").disabled = true;
    byId("pageForgotSendCode").textContent = "Send code";
    setStatus("pageForgotStatus", "Use the email registered to your tourist account.", "");
    setStatus("pageForgotPasswordStatus", "Your new password must match in both fields.", "");
  }

  forgotCodeBoxes.forEach((box, index) => {
    box.addEventListener("input", () => {
      box.value = box.value.replace(/\D/g, "").slice(0, 1);
      if (box.value && forgotCodeBoxes[index + 1]) forgotCodeBoxes[index + 1].focus();
    });
    box.addEventListener("keydown", event => {
      if (event.key === "Backspace" && !box.value && forgotCodeBoxes[index - 1]) forgotCodeBoxes[index - 1].focus();
    });
    box.addEventListener("paste", event => {
      const digits = event.clipboardData.getData("text").replace(/\D/g, "").slice(0, 6);
      if (digits.length < 2) return;
      event.preventDefault();
      digits.split("").forEach((digit, digitIndex) => { if (forgotCodeBoxes[digitIndex]) forgotCodeBoxes[digitIndex].value = digit; });
      forgotCodeBoxes[Math.min(digits.length, 6) - 1].focus();
    });
  });

  forgotEmail.addEventListener("input", () => {
    const email = forgotEmail.value.trim();
    const button = byId("pageForgotSendCode");
    const checkVersion = ++forgotEmailCheckVersion;
    clearTimeout(forgotEmailCheckTimer);
    clearInterval(forgotCountdown);
    forgotEmailValidated = "";
    button.disabled = true;
    button.textContent = "Send code";

    if (!forgotEmail.checkValidity()) {
      setStatus("pageForgotStatus", email ? "Enter a valid email address." : "Use the email registered to your tourist account.", email ? "error" : "");
      return;
    }

    setStatus("pageForgotStatus", "Checking for your tourist account...", "");
    forgotEmailCheckTimer = setTimeout(async () => {
      try {
        const body = new FormData();
        body.append("action", "check_email");
        body.append("email", email);
        const response = await fetch("php/forgot_password.php", { method: "POST", body });
        const result = await response.json();
        if (checkVersion !== forgotEmailCheckVersion || email !== forgotEmail.value.trim()) return;
        if (response.ok && result.exists) {
          forgotEmailValidated = email;
          button.disabled = false;
          setStatus("pageForgotStatus", "Tourist account found. You can send a verification code.", "success");
        } else {
          setStatus("pageForgotStatus", "No tourist account is registered with this email.", "error");
        }
      } catch (error) {
        if (checkVersion !== forgotEmailCheckVersion) return;
        setStatus("pageForgotStatus", "Unable to verify this email right now. Please try again.", "error");
      }
    }, 350);
  });

  byId("pageForgotSendCode").addEventListener("click", async () => {
    if (!forgotEmail.checkValidity()) { forgotEmail.reportValidity(); return; }
    if (forgotEmailValidated !== forgotEmail.value.trim()) return;
    const button = byId("pageForgotSendCode");
    button.disabled = true;
    button.textContent = "Sending...";
    setStatus("pageForgotStatus", "Checking your account and sending a secure code...", "");
    try {
      const body = new FormData();
      body.append("action", "send_code");
      body.append("email", forgotEmail.value.trim());
      const response = await fetch("php/send_verification_codeFP.php", { method: "POST", body });
      const result = await response.json();
      if (handleRateLimit(response, result, button, "Send code")) return;
      if (!response.ok || result.status !== "success") throw new Error(result.message || "The verification code could not be sent.");
      setStatus("pageForgotStatus", result.message || "Verification code sent. Check your email.", "success");
      forgotCodeBoxes[0].focus();
      let seconds = 90;
      clearInterval(forgotCountdown);
      button.textContent = `Resend in ${seconds}s`;
      forgotCountdown = setInterval(() => {
        seconds -= 1;
        button.textContent = seconds > 0 ? `Resend in ${seconds}s` : "Resend code";
        if (seconds <= 0) {
          clearInterval(forgotCountdown);
          button.disabled = forgotEmailValidated !== forgotEmail.value.trim();
        }
      }, 1000);
    } catch (error) {
      button.disabled = false;
      button.textContent = "Send code";
      setStatus("pageForgotStatus", error.message, "error");
    }
  });

  byId("pageForgotVerify").addEventListener("click", async () => {
    if (!forgotEmail.checkValidity()) { forgotEmail.reportValidity(); return; }
    if (reportFirstInvalid(forgotCodeBoxes)) return;
    const button = byId("pageForgotVerify");
    button.disabled = true;
    button.textContent = "Verifying...";
    try {
      const body = new FormData();
      body.append("action", "verify_code");
      body.append("email", forgotEmail.value.trim());
      body.append("code", forgotCodeBoxes.map(box => box.value).join(""));
      const response = await fetch("php/send_verification_codeFP.php", { method: "POST", body });
      const result = await response.json();
      if (!response.ok || !result.valid) throw new Error(result.message || "The code is invalid or expired.");
      recoveryVerified = true;
      byId("pageForgotVerifiedEmail").textContent = forgotEmail.value.trim();
      setRecoveryStep(2);
      forgotPassword.focus();
    } catch (error) {
      setStatus("pageForgotStatus", error.message, "error");
      forgotCodeBoxes.forEach(box => { box.value = ""; });
      forgotCodeBoxes[0].focus();
    } finally {
      button.disabled = false;
      button.textContent = "Verify code";
    }
  });

  byId("backToForgotVerify").addEventListener("click", () => setRecoveryStep(1));

  byId("pageForgotSave").addEventListener("click", async () => {
    if (reportFirstInvalid([forgotPassword, forgotConfirm])) return;
    if (!recoveryVerified) { setRecoveryStep(1); setStatus("pageForgotStatus", "Verify your email before changing the password.", "error"); return; }
    if (forgotPassword.value !== forgotConfirm.value) {
      forgotConfirm.setCustomValidity("Passwords do not match.");
      forgotConfirm.reportValidity();
      forgotConfirm.setCustomValidity("");
      return;
    }
    const button = byId("pageForgotSave");
    button.disabled = true;
    button.textContent = "Updating...";
    try {
      const body = new FormData();
      body.append("action", "save_new_password");
      body.append("email", forgotEmail.value.trim());
      body.append("newPassword", forgotPassword.value);
      const response = await fetch("php/forgot_password.php", { method: "POST", body });
      const result = await response.json();
      if (!response.ok || result.status !== "success") throw new Error(result.message || "Your password could not be updated.");
      await showAlert("success", "Password updated", "You can now log in with your new password.");
      loginEmail.value = forgotEmail.value.trim();
      loginPassword.value = "";
      resetRecovery();
      showLoginPanel();
    } catch (error) {
      setStatus("pageForgotPasswordStatus", error.message, "error");
    } finally {
      button.disabled = false;
      button.textContent = "Update password";
    }
  });

  initializeCountries();
})();
