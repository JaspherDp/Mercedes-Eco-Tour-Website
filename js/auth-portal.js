document.addEventListener("DOMContentLoaded", () => {
  document.querySelectorAll(".adlog-eye-icon").forEach((icon) => {
    const inputId = icon.dataset.passwordInput;
    const input = inputId ? document.getElementById(inputId) : null;

    if (!input) return;

    const togglePassword = () => {
      const willShow = input.type === "password";
      input.type = willShow ? "text" : "password";
      icon.src = willShow ? icon.dataset.visibleIcon : icon.dataset.hiddenIcon;
      icon.alt = willShow ? "Hide password" : "Show password";
      icon.setAttribute("aria-label", icon.alt);
    };

    icon.addEventListener("click", togglePassword);
    icon.addEventListener("keydown", (event) => {
      if (event.key === "Enter" || event.key === " ") {
        event.preventDefault();
        togglePassword();
      }
    });
  });

  document.querySelectorAll(".adlog-form").forEach((form) => {
    const submitButton = form.querySelector('.adlog-btn[type="submit"]');
    const buttonLabel = submitButton?.querySelector("span");

    if (!submitButton || !buttonLabel) return;

    const originalLabel = buttonLabel.textContent;
    const usernameInput = form.querySelector('input[name="username"]');
    const lockableInputs = [...form.querySelectorAll('input:not([type="hidden"])')];
    const loginScope = form.dataset.loginScope || "portal";
    const lockoutStorageKey = `itourPortalLoginLockout:${loginScope}`;
    let lockoutTimer = null;
    let lockoutEndsAt = 0;

    const formatCountdown = (totalSeconds) => {
      const seconds = Math.max(0, Number(totalSeconds) || 0);
      const minutes = Math.floor(seconds / 60);
      return `${String(minutes).padStart(2, "0")}:${String(seconds % 60).padStart(2, "0")}`;
    };

    const resetSubmitButton = () => {
      if (lockoutTimer) {
        window.clearInterval(lockoutTimer);
        lockoutTimer = null;
      }
      submitButton.disabled = false;
      submitButton.classList.remove("is-loading", "is-locked");
      submitButton.removeAttribute("aria-busy");
      buttonLabel.textContent = originalLabel;
      submitButton.querySelector(".adlog-spinner")?.remove();
      lockableInputs.forEach((input) => { input.disabled = false; });
      delete form.dataset.submitting;
      delete form.dataset.locked;
      lockoutEndsAt = 0;
      try { sessionStorage.removeItem(lockoutStorageKey); } catch (_) {}
    };

    const showLoginError = (message, type = "error") => {
      let alert = form.parentElement?.querySelector(".adlog-alert");

      if (!alert) {
        alert = document.createElement("div");
        alert.className = "adlog-alert";
        alert.setAttribute("role", "alert");
        alert.innerHTML = `
          <svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <circle cx="12" cy="12" r="9"></circle>
            <path d="M12 7v6M12 17h.01"></path>
          </svg>
          <span></span>
        `;
        form.parentElement?.insertBefore(alert, form);
      }

      alert.classList.toggle("is-success", type === "success");
      const alertMessage = alert.querySelector("span");
      if (alertMessage) alertMessage.textContent = message;
      alert.scrollIntoView({ behavior: "smooth", block: "nearest" });
      return alertMessage;
    };

    const startLockout = (retryAfter, existingEndsAt = 0, identifier = "") => {
      if (lockoutTimer) window.clearInterval(lockoutTimer);
      lockoutEndsAt = existingEndsAt > Date.now()
        ? existingEndsAt
        : Date.now() + (Math.max(1, Math.ceil(Number(retryAfter) || 0)) * 1000);
      const lockedIdentifier = String(identifier || usernameInput?.value || "").trim();
      try {
        sessionStorage.setItem(lockoutStorageKey, JSON.stringify({ endsAt: lockoutEndsAt, identifier: lockedIdentifier }));
      } catch (_) {}
      form.dataset.locked = "true";
      delete form.dataset.submitting;
      submitButton.disabled = true;
      lockableInputs.forEach((input) => { input.disabled = true; });
      submitButton.classList.remove("is-loading");
      submitButton.classList.add("is-locked");
      submitButton.setAttribute("aria-busy", "true");
      if (!submitButton.querySelector(".adlog-spinner")) {
        const spinner = document.createElement("span");
        spinner.className = "adlog-spinner";
        spinner.setAttribute("aria-hidden", "true");
        submitButton.insertBefore(spinner, buttonLabel);
      }

      const render = () => {
        const remaining = Math.max(0, Math.ceil((lockoutEndsAt - Date.now()) / 1000));
        const countdown = formatCountdown(remaining);
        buttonLabel.textContent = `Try again in ${countdown}`;
        const alertMessage = showLoginError(`Too many failed login attempts. Please wait ${countdown} before trying again.`, "error");
        if (remaining <= 0) {
          resetSubmitButton();
          showLoginError("You may now try logging in again.", "success");
        }
      };
      render();
      lockoutTimer = window.setInterval(render, 1000);
    };

    const openDashboard = async (redirect) => {
      const destination = new URL(redirect, window.location.href);

      // Never inject a document fetched from another origin. The login
      // endpoints currently return same-origin dashboard URLs, but retain a
      // normal navigation fallback if that contract ever changes.
      if (destination.origin !== window.location.origin) {
        window.location.assign(destination.href);
        return;
      }

      const response = await fetch(destination.href, {
        method: "GET",
        credentials: "same-origin",
        headers: {
          Accept: "text/html"
        }
      });

      if (!response.ok) {
        throw new Error("The dashboard could not be opened. Please try again.");
      }

      const dashboardHtml = await response.text();
      const finalDestination = new URL(response.url || destination.href);

      if (finalDestination.origin !== window.location.origin) {
        window.location.assign(finalDestination.href);
        return;
      }

      // Commit the already-loaded response directly. Keeping the login
      // document active during the fetch prevents browsers from freezing its
      // spinner while a traditional navigation waits for the server.
      window.history.replaceState(null, "", finalDestination.href);
      document.open();
      document.write(dashboardHtml);
      document.close();
    };

    form.addEventListener("submit", async (event) => {
      event.preventDefault();

      if (form.dataset.submitting === "true" || form.dataset.locked === "true") {
        return;
      }

      form.dataset.submitting = "true";
      submitButton.disabled = true;
      submitButton.classList.add("is-loading");
      submitButton.setAttribute("aria-busy", "true");
      buttonLabel.textContent = "Logging in...";

      const spinner = document.createElement("span");
      spinner.className = "adlog-spinner";
      spinner.setAttribute("aria-hidden", "true");
      submitButton.insertBefore(spinner, buttonLabel);

      try {
        const response = await fetch(form.action || window.location.href, {
          method: "POST",
          body: new FormData(form),
          credentials: "same-origin",
          headers: {
            Accept: "application/json",
            "X-Requested-With": "XMLHttpRequest"
          }
        });

        const payload = await response.json();
        if (!response.ok || !payload.success) {
          if (payload.locked && Number(payload.retry_after) > 0) {
            startLockout(payload.retry_after, 0, usernameInput?.value || "");
          } else {
            showLoginError(payload.message || "Unable to log in. Please try again.");
            resetSubmitButton();
          }
          return;
        }

        if (!payload.redirect) {
          throw new Error("Login succeeded, but no destination was provided.");
        }

        buttonLabel.textContent = "Opening dashboard...";
        await openDashboard(payload.redirect);
      } catch (error) {
        showLoginError(error instanceof Error ? error.message : "Unable to log in. Please try again.");
        resetSubmitButton();
      }
    });

    // Restore an active lockout after refresh or back/forward navigation.
    // The server still validates the same lockout if this browser state is removed.
    try {
      const saved = JSON.parse(sessionStorage.getItem(lockoutStorageKey) || "null");
      const savedEndsAt = Number(saved?.endsAt || 0);
      if (savedEndsAt > Date.now()) {
        if (usernameInput && !usernameInput.value && saved.identifier) usernameInput.value = saved.identifier;
        startLockout(Math.ceil((savedEndsAt - Date.now()) / 1000), savedEndsAt, saved.identifier || "");
      } else {
        sessionStorage.removeItem(lockoutStorageKey);
      }
    } catch (_) {
      try { sessionStorage.removeItem(lockoutStorageKey); } catch (_) {}
    }

    // Browsers may restore a submitted page from the back-forward cache.
    window.addEventListener("pageshow", () => {
      if (form.dataset.locked !== "true") resetSubmitButton();
    });
  });
});
