<?php
chdir(__DIR__ . '/..');
if (session_status() === PHP_SESSION_NONE) {
require_once __DIR__ . '/../php/session_security.php';
AppSessionStart();
}
if (!empty($_SESSION['tourist_id'])) {
    header('Location: homepage.php');
    exit;
}
$initialRateLimitNotice = $_SESSION['request_rate_limit_notice'] ?? null;
unset($_SESSION['request_rate_limit_notice']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="description" content="Create an iTour Mercedes tourist account.">
  <title>Create Tourist Account | iTour Mercedes</title>
  <link rel="icon" type="image/png" href="img/newlogo.png">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Outfit:wght@600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="styles/signup-page.css?v=<?= (int)@filemtime(__DIR__ . '/../styles/signup-page.css') ?>">
</head>
<body class="tourist-auth-page">
  <main class="tourist-auth-main">
    <section class="tourist-auth-shell" aria-labelledby="authPageTitle">
      <aside class="tourist-auth-story">
        <div class="tourist-auth-story-shade"></div>
        <a class="tourist-auth-brand" href="homepage.php" aria-label="iTour Mercedes home">
          <img src="img/newlogo.png" alt="">
          <img class="tourist-auth-wordmark" src="img/textlogo2-transparent.png" alt="iTour Mercedes">
        </a>
        <div class="tourist-auth-story-copy">
          <span>Plan with confidence</span>
          <h1>One account.<br>Every island adventure.</h1>
          <p>Save your favorites, manage bookings, and discover the best of Mercedes in one place.</p>
          <div class="tourist-auth-benefits">
            <span>Verified local tours</span>
            <span>Secure trip management</span>
            <span>Easy booking updates</span>
          </div>
        </div>
        <div class="tourist-auth-location">
          <strong>Apuao Grande</strong>
          <span>Mercedes, Camarines Norte</span>
        </div>
        <svg class="tourist-auth-wave" viewBox="0 0 190 900" preserveAspectRatio="none" aria-hidden="true">
          <path class="wave-back" d="M70 0C5 112 174 176 92 288C18 388 180 474 104 592C29 706 165 780 70 900H190V0Z"/>
          <path class="wave-middle" d="M112 0C35 112 177 211 119 319C61 426 178 519 121 630C64 741 171 817 110 900H190V0Z"/>
          <path class="wave-front" d="M151 0C85 126 184 226 143 343C101 461 185 566 145 681C105 798 177 848 150 900H190V0Z"/>
        </svg>
      </aside>

      <section class="tourist-auth-workspace">
        <div class="tourist-auth-card" id="signupPagePanel" data-current-step="1">
          <div class="tourist-auth-heading">
            <span class="tourist-auth-heading-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><circle cx="9" cy="8" r="4"/><path d="M2 21a7 7 0 0 1 14 0M19 8v6M16 11h6"/></svg></span>
            <span class="tourist-auth-kicker">Tourist registration</span>
            <h2 id="authPageTitle">Create your account</h2>
            <p>Complete four quick steps to start planning your trip.</p>
          </div>

          <div class="tourist-signup-progress" aria-label="Signup progress">
            <div class="tourist-step is-active" data-step-indicator="1"><b>1</b><span>Details</span></div>
            <i></i>
            <div class="tourist-step" data-step-indicator="2"><b>2</b><span>Address</span></div>
            <i></i>
            <div class="tourist-step" data-step-indicator="3"><b>3</b><span>Verify email</span></div>
            <i></i>
            <div class="tourist-step" data-step-indicator="4"><b>4</b><span>Password</span></div>
          </div>

          <form id="touristSignupForm">
            <section class="tourist-form-step is-active" data-signup-step="1">
              <div class="tourist-step-heading"><strong>Your details</strong><span>Tell us who will use this account.</span></div>
              <div class="tourist-field-grid two">
                <label class="tourist-field" data-icon="user"><span>First name</span><input id="pageSignupFirstName" type="text" autocomplete="given-name" placeholder="Juan" required></label>
                <label class="tourist-field" data-icon="user"><span>Last name</span><input id="pageSignupLastName" type="text" autocomplete="family-name" placeholder="Dela Cruz" required></label>
              </div>
              <label class="tourist-field" data-icon="phone"><span>Contact number</span><input id="pageSignupPhone" type="tel" autocomplete="tel" maxlength="30" placeholder="09XX XXX XXXX" required></label>
              <button class="tourist-primary-button" id="pageDetailsNext" type="button">Continue to address <span aria-hidden="true">&rarr;</span></button>
            </section>

            <section class="tourist-form-step" data-signup-step="2">
              <div class="tourist-step-heading"><strong>Your home address</strong><span>Select your country and complete home address.</span></div>
              <div class="tourist-field-grid two address-grid">
                <label class="tourist-field full" data-icon="globe"><span>Country</span><select id="pageSignupCountry" required><option value="">Loading countries...</option></select></label>
                <label class="tourist-field" data-icon="map"><span>Region / State</span><select id="pageSignupRegion" disabled required><option value="">Select country first</option></select></label>
                <label class="tourist-field" data-icon="map"><span>Province</span><select id="pageSignupProvince" disabled required><option value="">Select region first</option></select></label>
                <label class="tourist-field" data-icon="pin"><span>City / Municipality</span><select id="pageSignupCity" disabled required><option value="">Select province first</option></select></label>
                <label class="tourist-field" data-icon="pin"><span>Barangay</span><select id="pageSignupBarangay" disabled required><option value="">Select city first</option></select></label>
                <label class="tourist-field" data-icon="postal"><span>Postal code</span><input id="pageSignupPostal" type="text" placeholder="Auto-filled" readonly required></label>
                <label class="tourist-field" data-icon="home"><span>Street / House No.</span><input id="pageSignupStreet" type="text" autocomplete="street-address" maxlength="180" placeholder="Street, building, or house number" required></label>
              </div>
              <p class="tourist-form-status" id="pageAddressStatus" role="status"></p>
              <div class="tourist-form-actions"><button class="tourist-secondary-button" data-go-step="1" type="button">Back</button><button class="tourist-primary-button" id="pageAddressNext" type="button">Continue to email</button></div>
            </section>

            <section class="tourist-form-step" data-signup-step="3">
              <div class="tourist-step-heading"><strong>Verify your email</strong><span>We will send a secure six-digit code.</span></div>
              <div class="tourist-email-row">
                <label class="tourist-field" data-icon="mail"><span>Email address</span><input id="pageSignupEmail" type="email" autocomplete="email" placeholder="you@example.com" required></label>
                <button class="tourist-send-button" id="pageSendCode" type="button">Send code</button>
              </div>
              <div class="tourist-code-boxes" aria-label="Six-digit verification code">
                <input class="page-code-box" type="text" inputmode="numeric" maxlength="1" aria-label="Digit 1" required>
                <input class="page-code-box" type="text" inputmode="numeric" maxlength="1" aria-label="Digit 2" required>
                <input class="page-code-box" type="text" inputmode="numeric" maxlength="1" aria-label="Digit 3" required>
                <input class="page-code-box" type="text" inputmode="numeric" maxlength="1" aria-label="Digit 4" required>
                <input class="page-code-box" type="text" inputmode="numeric" maxlength="1" aria-label="Digit 5" required>
                <input class="page-code-box" type="text" inputmode="numeric" maxlength="1" aria-label="Digit 6" required>
              </div>
              <p class="tourist-form-status" id="pageEmailStatus" role="status">The code remains valid for 10 minutes.</p>
              <div class="tourist-form-actions"><button class="tourist-secondary-button" data-go-step="2" type="button">Back</button><button class="tourist-primary-button" id="pageVerifyCode" type="button">Verify and continue</button></div>
            </section>

            <section class="tourist-form-step" data-signup-step="4">
              <div class="tourist-step-heading"><strong>Secure your account</strong><span>Create a password you will remember.</span></div>
              <div class="tourist-verified-email"><span>Email verified</span><strong id="pageVerifiedEmail"></strong></div>
              <label class="tourist-field tourist-password-field" data-icon="lock"><span>Create password</span><input id="pageSignupPassword" type="password" autocomplete="new-password" placeholder="Enter your password" required><button class="tourist-password-toggle" type="button" data-toggle-password="pageSignupPassword" aria-label="Show password" aria-pressed="false"><svg class="eye-open" viewBox="0 0 24 24"><path d="M2.5 12s3.5-6 9.5-6 9.5 6 9.5 6-3.5 6-9.5 6-9.5-6-9.5-6Z"/><circle cx="12" cy="12" r="2.5"/></svg><svg class="eye-off" viewBox="0 0 24 24"><path d="M3 3l18 18M10.6 6.2A10.7 10.7 0 0 1 12 6c6 0 9.5 6 9.5 6s-.8 1.4-2.3 2.9M6.1 6.1C3.8 7.8 2.5 12 2.5 12s3.5 6 9.5 6a10.7 10.7 0 0 0 3.4-.5M9.9 9.9a3 3 0 0 0 4.2 4.2"/></svg></button></label>
              <label class="tourist-field tourist-password-field" data-icon="lock"><span>Confirm password</span><input id="pageSignupConfirm" type="password" autocomplete="new-password" placeholder="Enter it again" required><button class="tourist-password-toggle" type="button" data-toggle-password="pageSignupConfirm" aria-label="Show password" aria-pressed="false"><svg class="eye-open" viewBox="0 0 24 24"><path d="M2.5 12s3.5-6 9.5-6 9.5 6 9.5 6-3.5 6-9.5 6-9.5-6-9.5-6Z"/><circle cx="12" cy="12" r="2.5"/></svg><svg class="eye-off" viewBox="0 0 24 24"><path d="M3 3l18 18M10.6 6.2A10.7 10.7 0 0 1 12 6c6 0 9.5 6 9.5 6s-.8 1.4-2.3 2.9M6.1 6.1C3.8 7.8 2.5 12 2.5 12s3.5 6 9.5 6a10.7 10.7 0 0 0 3.4-.5M9.9 9.9a3 3 0 0 0 4.2 4.2"/></svg></button></label>
              <div class="tourist-password-rules" aria-live="polite"><strong>Password requirements</strong><span data-rule="length">At least 6 characters</span><span data-rule="letter">At least one letter</span><span data-rule="number">At least one number</span><span data-rule="match">Passwords match</span></div>
              <div class="tourist-form-actions"><button class="tourist-secondary-button" data-go-step="3" type="button">Back</button><button class="tourist-primary-button" id="pageCreateAccount" type="button">Create account</button></div>
            </section>
          </form>

          <div class="tourist-auth-divider"><span>or continue with</span></div>
          <a class="tourist-google-button" href="google_login.php"><img src="img/googlelogo.png" alt="">Sign up with Google</a>
          <p class="tourist-auth-switch">Already have an account? <button id="showPageLogin" type="button">Login here</button></p>
        </div>

        <div class="tourist-auth-card tourist-login-card" id="loginPagePanel" hidden>
          <div class="tourist-auth-heading">
            <span class="tourist-auth-heading-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M7 10V7a5 5 0 0 1 10 0v3"/><rect x="4" y="10" width="16" height="11" rx="2"/><path d="M12 14v3"/></svg></span>
            <span class="tourist-auth-kicker">Welcome back</span>
            <h2>Login to your account</h2>
            <p>Continue planning your Mercedes adventure.</p>
          </div>
          <form id="pageLoginForm" class="tourist-login-form">
            <div class="tourist-auth-error" id="pageLoginError" role="alert" hidden>
              <svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9"></circle><path d="M12 7v6M12 17h.01"></path></svg>
              <span></span>
            </div>
            <label class="tourist-field" data-icon="mail"><span>Email address</span><input id="pageLoginEmail" type="email" autocomplete="email" placeholder="you@example.com" required></label>
            <label class="tourist-field tourist-password-field" data-icon="lock"><span>Password</span><input id="pageLoginPassword" type="password" autocomplete="off" placeholder="Enter your password" required><button class="tourist-password-toggle" type="button" data-toggle-password="pageLoginPassword" aria-label="Show password" aria-pressed="false"><svg class="eye-open" viewBox="0 0 24 24"><path d="M2.5 12s3.5-6 9.5-6 9.5 6 9.5 6-3.5 6-9.5 6-9.5-6-9.5-6Z"/><circle cx="12" cy="12" r="2.5"/></svg><svg class="eye-off" viewBox="0 0 24 24"><path d="M3 3l18 18M10.6 6.2A10.7 10.7 0 0 1 12 6c6 0 9.5 6 9.5 6s-.8 1.4-2.3 2.9M6.1 6.1C3.8 7.8 2.5 12 2.5 12s3.5 6 9.5 6a10.7 10.7 0 0 0 3.4-.5M9.9 9.9a3 3 0 0 0 4.2 4.2"/></svg></button></label>
            <div class="tourist-login-options"><label class="tourist-remember"><input id="pageRememberMe" type="checkbox"> <span>Remember me</span></label><button class="tourist-forgot-link" id="showPageForgot" type="button">Forgot password?</button></div>
            <button class="tourist-primary-button" id="pageLoginButton" type="submit">Login</button>
          </form>
          <div class="tourist-auth-divider"><span>or continue with</span></div>
          <a class="tourist-google-button" href="google_login.php"><img src="img/googlelogo.png" alt="">Login with Google</a>
          <p class="tourist-auth-switch">New to iTour Mercedes? <button id="showPageSignup" type="button">Create an account</button></p>
        </div>

        <div class="tourist-auth-card tourist-recovery-card" id="forgotPagePanel" hidden>
          <div class="tourist-auth-heading">
            <span class="tourist-auth-heading-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M7 10V7a5 5 0 0 1 9.8-1.4"/><rect x="4" y="10" width="16" height="11" rx="2"/><path d="m17 2 2 2 3-3"/></svg></span>
            <span class="tourist-auth-kicker">Account recovery</span>
            <h2>Reset your password</h2>
            <p>Verify your email, then choose a secure new password.</p>
          </div>

          <div class="tourist-recovery-progress" aria-label="Password recovery progress">
            <div class="is-active" data-recovery-indicator="1"><b>1</b><span>Verify email</span></div><i></i><div data-recovery-indicator="2"><b>2</b><span>New password</span></div>
          </div>

          <form id="pageForgotForm">
            <section class="tourist-recovery-step is-active" data-recovery-step="1">
              <div class="tourist-step-heading"><strong>Find your account</strong><span>We will email a six-digit code.</span></div>
              <div class="tourist-email-row">
                <label class="tourist-field" data-icon="mail"><span>Email address</span><input id="pageForgotEmail" type="email" autocomplete="email" placeholder="you@example.com" required></label>
                <button class="tourist-send-button" id="pageForgotSendCode" type="button" disabled>Send code</button>
              </div>
              <p class="tourist-form-status" id="pageForgotStatus" role="status">Use the email registered to your tourist account.</p>
              <div class="tourist-code-boxes tourist-recovery-code" aria-label="Six-digit password reset code">
                <input class="page-forgot-code" type="text" inputmode="numeric" maxlength="1" aria-label="Digit 1" required><input class="page-forgot-code" type="text" inputmode="numeric" maxlength="1" aria-label="Digit 2" required><input class="page-forgot-code" type="text" inputmode="numeric" maxlength="1" aria-label="Digit 3" required><input class="page-forgot-code" type="text" inputmode="numeric" maxlength="1" aria-label="Digit 4" required><input class="page-forgot-code" type="text" inputmode="numeric" maxlength="1" aria-label="Digit 5" required><input class="page-forgot-code" type="text" inputmode="numeric" maxlength="1" aria-label="Digit 6" required>
              </div>
              <div class="tourist-form-actions"><button class="tourist-secondary-button" id="backToPageLogin" type="button">Back to login</button><button class="tourist-primary-button" id="pageForgotVerify" type="button">Verify code</button></div>
            </section>

            <section class="tourist-recovery-step" data-recovery-step="2">
              <div class="tourist-step-heading"><strong>Create a new password</strong><span>Use at least six characters.</span></div>
              <div class="tourist-verified-email"><span>Verified account</span><strong id="pageForgotVerifiedEmail"></strong></div>
              <label class="tourist-field tourist-password-field" data-icon="lock"><span>New password</span><input id="pageForgotPassword" type="password" autocomplete="new-password" minlength="6" placeholder="Enter a new password" required><button class="tourist-password-toggle" type="button" data-toggle-password="pageForgotPassword" aria-label="Show password" aria-pressed="false"><svg class="eye-open" viewBox="0 0 24 24"><path d="M2.5 12s3.5-6 9.5-6 9.5 6 9.5 6-3.5 6-9.5 6-9.5-6-9.5-6Z"/><circle cx="12" cy="12" r="2.5"/></svg><svg class="eye-off" viewBox="0 0 24 24"><path d="M3 3l18 18M10.6 6.2A10.7 10.7 0 0 1 12 6c6 0 9.5 6 9.5 6s-.8 1.4-2.3 2.9M6.1 6.1C3.8 7.8 2.5 12 2.5 12s3.5 6 9.5 6a10.7 10.7 0 0 0 3.4-.5M9.9 9.9a3 3 0 0 0 4.2 4.2"/></svg></button></label>
              <label class="tourist-field tourist-password-field" data-icon="lock"><span>Confirm new password</span><input id="pageForgotConfirm" type="password" autocomplete="new-password" minlength="6" placeholder="Enter it again" required><button class="tourist-password-toggle" type="button" data-toggle-password="pageForgotConfirm" aria-label="Show password" aria-pressed="false"><svg class="eye-open" viewBox="0 0 24 24"><path d="M2.5 12s3.5-6 9.5-6 9.5 6 9.5 6-3.5 6-9.5 6-9.5-6-9.5-6Z"/><circle cx="12" cy="12" r="2.5"/></svg><svg class="eye-off" viewBox="0 0 24 24"><path d="M3 3l18 18M10.6 6.2A10.7 10.7 0 0 1 12 6c6 0 9.5 6 9.5 6s-.8 1.4-2.3 2.9M6.1 6.1C3.8 7.8 2.5 12 2.5 12s3.5 6 9.5 6a10.7 10.7 0 0 0 3.4-.5M9.9 9.9a3 3 0 0 0 4.2 4.2"/></svg></button></label>
              <p class="tourist-form-status" id="pageForgotPasswordStatus" role="status">Your new password must match in both fields.</p>
              <div class="tourist-form-actions"><button class="tourist-secondary-button" id="backToForgotVerify" type="button">Back</button><button class="tourist-primary-button" id="pageForgotSave" type="button">Update password</button></div>
            </section>
          </form>
        </div>
      </section>
    </section>
  </main>

  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
  <script src="js/mobile-scroll.js"></script>
  <script src="js/request-limit.js?v=<?= (int)@filemtime(__DIR__ . '/../js/request-limit.js') ?>"></script>
  <?php if (is_array($initialRateLimitNotice)): ?>
  <script>window.RequestLimitModal.show(<?= json_encode($initialRateLimitNotice, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) ?>);</script>
  <?php endif; ?>
  <script src="js/signup-page.js?v=<?= (int)@filemtime(__DIR__ . '/../js/signup-page.js') ?>"></script>
</body>
</html>
