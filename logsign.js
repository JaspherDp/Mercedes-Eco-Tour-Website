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

function initLogSignEvents() {
  // --- Elements ---
  const modalOverlay = document.getElementById("modalOverlay");
  const openModalBtn = document.getElementById("openModalBtn");
  const closeModal = document.getElementById("closeModal");

  const loginForm = document.getElementById("loginForm");
  const signupForm = document.getElementById("signupForm");

  const goSignup = document.getElementById("goSignup");
  const goLogin = document.getElementById("goLogin");

  const loginBtn = document.getElementById("loginBtn");
  const signupBtn = document.getElementById("signupBtn");
  const sendCodeBtn = document.getElementById("sendCodeBtn");
  const verifyEmailBtn = document.getElementById("verifyEmailBtn");

  const phase1 = signupForm.querySelector(".signup-phase-1");
  const phase2 = signupForm.querySelector(".signup-phase-2");
  const steps = signupForm.querySelectorAll(".phase-step");

  const loginEmailInput = document.getElementById('loginEmail');
  const loginPasswordInput = document.getElementById('loginPassword');
  const rememberMeCheckbox = document.getElementById('rememberMe');

  // --- Prefill login if Remember Me cookies exist ---
  const rememberedEmail = getCookie('rememberedEmail');
  const rememberedPassword = getCookie('rememberedPassword'); // Optional, can remove for better security

  if (rememberedEmail) {
    loginEmailInput.value = rememberedEmail;
    rememberMeCheckbox.checked = true;
  }

  if (rememberedPassword) {
    loginPasswordInput.value = rememberedPassword;
  }

  if (!modalOverlay) return;

  // --- Modal open/close ---
  if (openModalBtn) {
    openModalBtn.addEventListener("click", () => {
      modalOverlay.style.display = "flex";

      // Show login, hide everything else
      loginForm.classList.remove("logsign-hidden");
      signupForm.classList.add("logsign-hidden");
      forgotPasswordForm.classList.add("logsign-hidden"); // <-- add this line

      // Reset signup phases
      phase1.classList.add("active");
      phase2.classList.remove("active");
      steps.forEach((s) => {
        s.classList.remove("phase-active", "phase-completed");
      });
      steps[0].classList.add("phase-active");
    });
  }

  if (closeModal) {
    closeModal.addEventListener("click", () => {
      modalOverlay.style.display = "none";
    });
  }

  // --- Form toggles ---
  if (goSignup) {
    goSignup.addEventListener("click", () => {
      loginForm.classList.add("logsign-hidden");
      signupForm.classList.remove("logsign-hidden");

      phase1.classList.add("active");
      phase2.classList.remove("active");
      steps.forEach(s => s.classList.remove("phase-active", "phase-completed"));
      steps[0].classList.add("phase-active");
    });
  }

  if (goLogin) {
    goLogin.addEventListener("click", () => {
      signupForm.classList.add("logsign-hidden");
      loginForm.classList.remove("logsign-hidden");
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
      formData.append("action", "send_code");

      const res = await fetch("php/signup.php", { method: "POST", body: formData });
      const data = await res.json();
      alert(data.message);
    } catch (err) {
      alert("Failed to send code.");
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

// --- Login function with SweetAlert2 ---
async function handleLogin() {
    const email = loginEmailInput.value.trim();
    const password = loginPasswordInput.value;

    if (!email || !password) {
        Swal.fire({
            icon: 'warning',
            title: 'Incomplete Data',
            text: 'Please enter email and password!'
        });
        return;
    }

    const formData = new FormData();
    formData.append('email', email);
    formData.append('password', password);

    try {
    const res = await fetch('php/login.php', { method: 'POST', body: formData });
    const data = await res.json();

      if (data.status === 'success') {
          // Remember Me logic
          if (rememberMeCheckbox.checked) {
              setCookie('rememberedEmail', email, 30);
              setCookie('rememberedPassword', password, 30);
          } else {
              deleteCookie('rememberedEmail');
              deleteCookie('rememberedPassword');
          }

          Swal.fire({
              icon: 'success',
              title: 'Login Successful',
              text: data.message,
              confirmButtonColor: '#2B7066', // custom OK button color
              confirmButtonText: 'OK'
          }).then(() => {
              modalOverlay.style.display = 'none';
              if (data.redirect_url) {
                  window.location.href = data.redirect_url;
              } else {
                  window.location.reload();
              }
          });

      } else if (data.status === 'banned') {
          Swal.fire({
              icon: 'error',
              title: 'Account Banned',
              html: (data.message.split('Reason:')[1] || data.message).trim().replace(/\n/g, '<br>'),
              confirmButtonColor: '#2B7066',
              confirmButtonText: 'OK'
          });

      } else {
          Swal.fire({
              icon: 'error',
              title: 'Login Failed',
              text: data.message,
              confirmButtonColor: '#2B7066',
              confirmButtonText: 'OK'
          });
      }

  } catch (err) {
      console.error(err);
      Swal.fire({
          icon: 'error',
          title: 'Error',
          text: 'Something went wrong. Please try again.',
          confirmButtonColor: '#2B7066',
          confirmButtonText: 'OK'
      });
  }

}

// Attach login button
if (loginBtn) loginBtn.addEventListener('click', handleLogin);
  // --- Signup ---
async function handleSignup() {
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
      confirmButtonColor: '#2B7066'
    });
    return;
  }

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

    Swal.fire({
      icon: data.status === "success" ? 'success' : 'error',
      title: data.status === "success" ? 'Signup Successful' : 'Signup Failed',
      text: data.message,
      confirmButtonColor: '#2B7066'
    }).then(() => {
      if (data.status === "success") {
        modalOverlay.style.display = "none";
        window.location.reload();
      }
    });

  } catch (err) {
    Swal.fire({
      icon: 'error',
      title: 'Signup Failed',
      text: 'Something went wrong. Please try again.',
      confirmButtonColor: '#2B7066'
    });
  }
}

if (signupBtn) signupBtn.addEventListener("click", handleSignup);

// --- Phase 1 → Phase 2 ---
if (verifyEmailBtn) {
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

  const textSpan = button.querySelector('.btn-text'); // span around text
  if (!textSpan) return;

  button.addEventListener("click", () => {
    // Disable button and show processing
    button.disabled = true;
    const originalText = textSpan.textContent;
    textSpan.textContent = "Processing...";
    button.style.cursor = "not-allowed";

    // Optional: add spinner inside the span
    // textSpan.innerHTML = '<span class="spinner"></span> Processing...';

    // Redirect to Google OAuth
    window.location.href = redirectUrl;

    // Fallback reset if redirect fails (mostly for single-page apps)
    setTimeout(() => {
      button.disabled = false;
      textSpan.textContent = originalText;
      button.style.cursor = "pointer";
    }, 3000);
  });
}

// Initialize both buttons
handleGoogleSignIn(googleLoginBtn, "google_login.php");
handleGoogleSignIn(googleSignupBtn, "google_login.php");


  // --- Enter key support ---
  document.addEventListener("keydown", (e) => {
    if (!modalOverlay || modalOverlay.style.display !== "flex") return;
    if (e.key !== "Enter") return;

    if (!signupForm.classList.contains("logsign-hidden") && !phase2.classList.contains("active")) {
      verifyEmailBtn.click();
    } else if (!signupForm.classList.contains("logsign-hidden")) {
      handleSignup();
    } else if (!loginForm.classList.contains("logsign-hidden")) {
      handleLogin();
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

  // Disable Send Code initially
  forgotSendCodeBtn.disabled = true;
  forgotSendCodeBtn.style.cursor = 'not-allowed';
  forgotSendCodeBtn.style.backgroundColor = '#999';

  // --- Open Forgot Password Modal ---
  forgotPasswordLink.addEventListener('click', () => {
    modalOverlay.style.display = 'flex';
    loginForm.classList.add('logsign-hidden');
    signupForm.classList.add('logsign-hidden');
    forgotPasswordForm.classList.remove('logsign-hidden');

    forgotEmailInput.value = '';
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
  forgotEmailInput.addEventListener('input', async () => {
    const email = forgotEmailInput.value.trim();
    if (!email) {
      emailStatusMsg.textContent = '';
      forgotSendCodeBtn.disabled = true;
      return;
    }

    try {
      const formData = new FormData();
      formData.append('action', 'check_email');
      formData.append('email', email);

      const res = await fetch('php/forgot_password.php', { method: 'POST', body: formData });
      const data = await res.json();

      if (data.exists) {
        emailStatusMsg.textContent = 'Email found';
        emailStatusMsg.style.color = 'green';
        forgotSendCodeBtn.disabled = false;
        forgotSendCodeBtn.style.cursor = 'pointer';
        forgotSendCodeBtn.style.backgroundColor = '#2E7B45';
      } else {
        emailStatusMsg.textContent = 'Email not found';
        emailStatusMsg.style.color = 'red';
        forgotSendCodeBtn.disabled = true;
        forgotSendCodeBtn.style.cursor = 'not-allowed';
        forgotSendCodeBtn.style.backgroundColor = '#999';
      }
    } catch (err) {
      console.error(err);
    }
  });

  // --- Send verification code ---
  forgotSendCodeBtn.addEventListener('click', async () => {
    const email = forgotEmailInput.value.trim();
    if (!email) return alert('Please enter your email.');

    forgotSendCodeBtn.disabled = true;
    forgotSendCodeBtn.textContent = 'Sending...';
    forgotSendCodeBtn.style.cursor = 'not-allowed';

    try {
      const formData = new FormData();
      formData.append('action', 'send_code');
      formData.append('email', email);

      const res = await fetch('php/send_verification_codeFP.php', { method: 'POST', body: formData });
      const data = await res.json();
      alert(data.message);

      forgotSendCodeBtn.textContent = 'Resend Code';
      forgotSendCodeBtn.style.backgroundColor = '#999';
      forgotCodeBoxes.forEach(box => box.disabled = false);

      // Countdown
      let timer = 90;
      forgotCodeCountdown.style.display = 'block';
      forgotCodeCountdown.textContent = `Resend code in ${timer}s`;

      const countdown = setInterval(() => {
        timer--;
        forgotCodeCountdown.textContent = timer > 0 ? `Resend code in ${timer}s` : 'You can resend code now';
        if (timer <= 0) {
          clearInterval(countdown);
          forgotSendCodeBtn.disabled = false;
          forgotSendCodeBtn.style.cursor = 'pointer';
          forgotSendCodeBtn.style.backgroundColor = '#2E7B45';
        }
      }, 1000);

    } catch (err) {
      alert('Failed to send code.');
      forgotSendCodeBtn.disabled = false;
      forgotSendCodeBtn.textContent = 'Send Code';
      forgotSendCodeBtn.style.cursor = 'pointer';
      forgotSendCodeBtn.style.backgroundColor = '#2E7B45';
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

      modalOverlay.style.display = 'none';
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
