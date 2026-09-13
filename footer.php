<?php
if (session_status() === PHP_SESSION_NONE) {
    require_once __DIR__ . '/php/session_security.php';
    AppSessionStart();
}
require_once __DIR__ . '/php/db_connection.php'; // adjust path if needed
require_once __DIR__ . '/php/complaints_incidents_helper.php';
$complaintLoggedIn = !empty($_SESSION['tourist_id']);
$complaintCsrf = $complaintLoggedIn ? complaintCsrfToken() : '';
?>


<head>
  <meta charset="UTF-8">
  <title>Paradise Island Tours</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
  <link rel="stylesheet" href="style.css">
  <link rel="stylesheet" href="styles/complaint-modal.css?v=<?= (int)@filemtime(__DIR__ . '/styles/complaint-modal.css') ?>">
</head>

<body>

  <!-- Waves Background -->
  <div class="wave-container site-footer-waves<?= ($footerVariant ?? '') === 'tours' ? ' wave-container--tours' : '' ?>">
    <div class="wave"></div>
    <div class="wave"></div>
    <div class="wave"></div>
    <div class="wave"></div>
  </div>

  <!-- Footer Section -->
  <footer class="site-footer<?= ($footerVariant ?? '') === 'tours' ? ' footer--tours' : '' ?>">
    <div class="footer-content">

      <!-- LEFT SIDE: LOGOS ABOVE TAGLINE -->
<div class="footer-section footer-logos">
  <div class="logo-wrapper">
    <img src="img/mercedeslogo.png" alt="Logo 1">
    <img src="img/TourismLogo.png" alt="Logo 2">
    <img src="img/newlogo.png" alt="iTour Mercedes logo">
  </div>
  <p class="footer-tagline">
    Catering to travel agencies, tour operators,<br>
    or vacation planning services
  </p>
</div>


      <!-- CENTER: QUICK LINKS -->
      <div class="footer-section quick-links">
        <h3>Quick Links</h3>
        <ul>
          <li><a href="About.php">About Us</a></li>
          <li><a href="Termsconditions.php">Terms &amp; Conditions</a></li>
          <li><a href="privacypolicy.php">Privacy Policy</a></li>
          <li><a href="#complaintIncidentModal" id="openComplaintModal">Submit Complaint &amp; Incident</a></li>
          <li><a href="php/operator_login.php" target="_blank" rel="noopener noreferrer">Operator Login</a></li>
          <li><a href="php/admin_login.php" target="_blank" rel="noopener noreferrer">Admin Login</a></li>
          <li><a href="php/hotel_admin_login.php" target="_blank" rel="noopener noreferrer">Hotel Admin Login</a></li>
        </ul>
      </div>

      <!-- RIGHT SIDE: CONTACT INFO -->
      <div class="footer-section">
        <h3>Contact Info</h3>

        <a href="https://www.facebook.com/mercedes.tourism.2024" target="_blank" class="contact-line">
          <i class="fab fa-facebook-f contact-icon"></i>
          Municipal Tourism Office - LGU Mercedes
        </a>

        <div class="contact-line">
          <i class="fa-solid fa-phone contact-icon"></i>
          <a href="tel:+639123456789">+63 912 345 6789</a>
        </div>

        <div class="contact-line">
          <i class="fa-solid fa-envelope contact-icon"></i>
          <a href="mailto:baliksiglamercedes@gmail.com">baliksiglamercedes@gmail.com</a>
        </div>

        <div class="contact-line">
          <i class="fa-solid fa-location-dot contact-icon"></i>
          <a href="https://maps.app.goo.gl/KbuTauSSe7rLZ2mX9" target="_blank">
            Municipal Hall, Mercedes, Camarines Norte
          </a>
        </div>

        <button type="button" class="contact-line contact-line--button" id="developersModalTrigger" aria-haspopup="dialog">
          <i class="fa-solid fa-users contact-icon" aria-hidden="true"></i>
          <span>Developers</span>
        </button>
      </div>

    </div>

    <!-- Footer Bottom -->
    <div class="footer-bottom">
      <p>&copy; 2024 Municipal Tourism Office - Mercedes. All rights reserved.</p>
    </div>
  </footer>

  <div class="complaint-modal" id="complaintIncidentModal" hidden aria-hidden="true">
    <div class="complaint-modal__backdrop" data-complaint-close></div>
    <section class="complaint-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="complaintModalTitle" aria-describedby="complaintModalDescription">
      <header class="complaint-modal__header">
        <span class="complaint-modal__icon" aria-hidden="true">
          <svg viewBox="0 0 24 24"><path d="M12 3 3.8 6.4v5.2c0 4.7 3.5 7.9 8.2 9.4 4.7-1.5 8.2-4.7 8.2-9.4V6.4L12 3Z"></path><path d="M12 8v4.5"></path><path d="M12 16h.01"></path></svg>
        </span>
        <div class="complaint-modal__heading">
          <span class="complaint-modal__eyebrow">Tourist assistance</span>
          <h2 id="complaintModalTitle">Submit a Complaint or Incident</h2>
          <p id="complaintModalDescription">Give the Tourism Office clear, factual details so your concern can be reviewed properly. Fields marked with an asterisk are required.</p>
        </div>
        <button type="button" class="complaint-modal__close" data-complaint-close aria-label="Close complaint form">&times;</button>
      </header>

      <form class="complaint-form" id="complaintIncidentForm" enctype="multipart/form-data" novalidate>
        <div class="complaint-form__body">
          <div class="complaint-form__notice">
            <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"></circle><path d="M12 10v6"></path><path d="M12 7h.01"></path></svg>
            <span>Your report is linked securely to your tourist account. For immediate danger or medical emergencies, contact local emergency services first.</span>
          </div>
          <div class="complaint-validation-summary" id="complaintValidationSummary" role="alert" tabindex="-1" hidden></div>

        <fieldset class="complaint-form__section">
          <legend>Report details</legend>
          <div class="complaint-form__grid">
            <label class="complaint-field">
              <span>Report type <b class="complaint-required">*</b></span>
              <select name="report_type" required>
                <option value="">Select report type</option>
                <option value="complaint">Complaint</option>
                <option value="incident">Incident</option>
              </select>
            </label>
            <label class="complaint-field">
              <span>Category <b class="complaint-required">*</b></span>
              <select name="category" required>
                <option value="">Select a category</option>
                <option value="tour-service">Tour or guide service</option>
                <option value="accommodation">Hotel or accommodation</option>
                <option value="transportation">Boat or transportation</option>
                <option value="safety-security">Safety or security</option>
                <option value="environmental">Environmental concern</option>
                <option value="staff-conduct">Staff or operator conduct</option>
                <option value="payment-booking">Payment or booking</option>
                <option value="other">Other concern</option>
              </select>
            </label>
            <label class="complaint-field complaint-field--wide">
              <span>Subject <b class="complaint-required">*</b></span>
              <input type="text" name="subject" minlength="5" maxlength="180" placeholder="Briefly summarize your concern" required>
            </label>
            <label class="complaint-field">
              <span>Date and time of event <b class="complaint-required">*</b></span>
              <input type="datetime-local" name="incident_at" required>
            </label>
            <label class="complaint-field">
              <span>Location <b class="complaint-required">*</b></span>
              <input type="text" name="location" minlength="3" maxlength="220" placeholder="Island, property, boat, or meeting point" required>
            </label>
          </div>
        </fieldset>

        <fieldset class="complaint-form__section">
          <legend>What happened</legend>
          <div class="complaint-form__grid">
            <label class="complaint-field complaint-field--wide">
              <span>Detailed description <b class="complaint-required">*</b></span>
              <textarea name="description" minlength="30" maxlength="5000" placeholder="Describe the event in order, including what you observed and how it affected you." required></textarea>
              <small>Please provide facts and avoid including unrelated sensitive personal information.</small>
            </label>
            <label class="complaint-field">
              <span>People or organizations involved</span>
              <textarea name="people_involved" maxlength="500" placeholder="Names, operator, guide, hotel, or boat, if known"></textarea>
            </label>
            <label class="complaint-field">
              <span>Immediate action already taken</span>
              <textarea name="immediate_action" maxlength="2000" placeholder="Who you notified or what was done after the event"></textarea>
            </label>
          </div>
        </fieldset>

        <fieldset class="complaint-form__section">
          <legend>Evidence and follow-up</legend>
          <div class="complaint-form__grid">
            <div class="complaint-field complaint-field--wide">
              <span>Photo evidence</span>
              <label class="complaint-dropzone" for="complaintEvidence">
                <input id="complaintEvidence" type="file" name="evidence[]" accept="image/jpeg,image/png,image/webp" multiple>
                <span>
                  <span class="complaint-dropzone__icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M12 16V5"></path><path d="m8 9 4-4 4 4"></path><path d="M5 14v4a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2v-4"></path></svg></span>
                  <strong>Drag photos here or choose files</strong>
                  <span>Up to 3 JPG, PNG, or WebP images &middot; 5 MB each</span>
                </span>
              </label>
              <div class="complaint-file-list" id="complaintFileList" aria-live="polite"></div>
            </div>
            <label class="complaint-field">
              <span>Preferred contact method <b class="complaint-required">*</b></span>
              <select name="preferred_contact" required>
                <option value="email">Email</option>
                <option value="phone">Phone</option>
                <option value="either">Email or phone</option>
              </select>
            </label>
          </div>
        </fieldset>

          <label class="complaint-consent">
            <input type="checkbox" name="accuracy_consent" value="1" required>
            <span>I confirm that the information in this report is accurate to the best of my knowledge, and I understand that the Tourism Office may contact me for clarification. <b class="complaint-required">*</b></span>
          </label>

          <div class="complaint-form__status" id="complaintFormStatus" aria-live="polite"></div>
        </div>
        <div class="complaint-form__actions">
          <button type="button" class="complaint-form__cancel" data-complaint-close>Cancel</button>
          <button type="submit" class="complaint-form__submit">
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m4 12 5 5L20 6"></path></svg>
            <span>Submit report</span>
          </button>
        </div>
      </form>
    </section>
  </div>

  <div class="developers-modal" id="developersModal" hidden aria-hidden="true">
    <div class="developers-modal__backdrop" data-developers-close></div>
    <section class="developers-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="developersModalTitle" aria-describedby="developersModalDescription" tabindex="-1">
      <header class="developers-modal__header">
        <div class="developers-modal__header-content">
          <div class="developers-modal__brand" aria-label="iTour Mercedes">
            <img class="developers-modal__brand-logo" src="img/newlogo.png" alt="iTour Mercedes logo">
            <img class="developers-modal__wordmark" src="img/textlogo2-white.png" alt="iTour Mercedes">
          </div>
          <div class="developers-modal__heading">
            <h2 id="developersModalTitle">Development Team</h2>
            <p id="developersModalDescription">The student developers behind the iTour Mercedes digital tourism platform.</p>
          </div>
        </div>
        <button type="button" class="developers-modal__close" data-developers-close aria-label="Close developers dialog">&times;</button>
      </header>

      <div class="developers-modal__grid">
        <article class="developer-profile-card">
          <div class="developer-profile-avatar"><img src="img/jaspher.png" alt="John Jaspher O. Dela Pacion"></div>
          <div class="developer-profile-details">
            <span class="developer-profile-role"><i class="fa-solid fa-code" aria-hidden="true"></i> Platform Developer</span>
            <h3>John Jaspher O. Dela Pacion</h3>
            <ul class="developer-profile-meta">
              <li><i class="fa-solid fa-graduation-cap" aria-hidden="true"></i><span><small>Degree Program</small><strong>BS Information Systems</strong></span></li>
              <li class="developer-profile-meta-school"><i class="fa-solid fa-building-columns" aria-hidden="true"></i><span><small>Institution</small><strong>UNIVERSITY OF CAMARINES NORTE</strong></span></li>
              <li><i class="fa-solid fa-envelope" aria-hidden="true"></i><span><small>Email</small><a href="mailto:johnjaspherdelapacion29@gmail.com">johnjaspherdelapacion29@gmail.com</a></span></li>
            </ul>
          </div>
        </article>
        <article class="developer-profile-card">
          <div class="developer-profile-avatar"><img src="img/jacqueline.png" alt="Jacqueline Alyzza G. Asis"></div>
          <div class="developer-profile-details">
            <span class="developer-profile-role"><i class="fa-solid fa-code" aria-hidden="true"></i> Platform Developer</span>
            <h3>Jacqueline Alyzza G. Asis</h3>
            <ul class="developer-profile-meta">
              <li><i class="fa-solid fa-graduation-cap" aria-hidden="true"></i><span><small>Degree Program</small><strong>BS Information Systems</strong></span></li>
              <li class="developer-profile-meta-school"><i class="fa-solid fa-building-columns" aria-hidden="true"></i><span><small>Institution</small><strong>UNIVERSITY OF CAMARINES NORTE</strong></span></li>
              <li><i class="fa-solid fa-envelope" aria-hidden="true"></i><span><small>Email</small><a href="mailto:jacquelineasis29@gmail.com">jacquelineasis29@gmail.com</a></span></li>
            </ul>
          </div>
        </article>
        <article class="developer-profile-card">
          <div class="developer-profile-avatar"><img src="img/oliver.png" alt="Mark Oliver Coronel"></div>
          <div class="developer-profile-details">
            <span class="developer-profile-role"><i class="fa-solid fa-code" aria-hidden="true"></i> Platform Developer</span>
            <h3>Mark Oliver Coronel</h3>
            <ul class="developer-profile-meta">
              <li><i class="fa-solid fa-graduation-cap" aria-hidden="true"></i><span><small>Degree Program</small><strong>BS Information Systems</strong></span></li>
              <li class="developer-profile-meta-school"><i class="fa-solid fa-building-columns" aria-hidden="true"></i><span><small>Institution</small><strong>UNIVERSITY OF CAMARINES NORTE</strong></span></li>
              <li><i class="fa-solid fa-envelope" aria-hidden="true"></i><span><small>Email</small><a href="mailto:oliver002118@gmail.com">oliver002118@gmail.com</a></span></li>
            </ul>
          </div>
        </article>
      </div>
    </section>
  </div>

  <script>
    window.ComplaintModalConfig = {
      loggedIn: <?= $complaintLoggedIn ? 'true' : 'false' ?>,
      csrfToken: <?= json_encode($complaintCsrf, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
      endpoint: 'php/submit_complaint_incident.php'
    };
  </script>
  <script src="js/complaint-modal.js?v=<?= (int)@filemtime(__DIR__ . '/js/complaint-modal.js') ?>"></script>
  <script>
    (() => {
      const trigger = document.getElementById('developersModalTrigger');
      const modal = document.getElementById('developersModal');
      const dialog = modal?.querySelector('.developers-modal__dialog');
      if (!trigger || !modal || !dialog) return;

      let returnFocus = null;
      const close = () => {
        modal.hidden = true;
        modal.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('developers-modal-open');
        returnFocus?.focus();
      };
      const open = () => {
        returnFocus = document.activeElement;
        modal.hidden = false;
        modal.setAttribute('aria-hidden', 'false');
        document.body.classList.add('developers-modal-open');
        dialog.focus();
      };
      trigger.addEventListener('click', open);
      modal.querySelectorAll('[data-developers-close]').forEach(button => button.addEventListener('click', close));
      document.addEventListener('keydown', event => {
        if (event.key === 'Escape' && !modal.hidden) close();
      });
    })();
  </script>

  <!-- ================= SCRIPT ================= -->
  <script>
    function scrollToContent() {
      document.getElementById('content').scrollIntoView({ behavior: 'smooth' });
    }

    function goToDestination() {
      window.location.href = "destination.html";
    }
  </script>

</body>

<!-- Styling -->
<style>
/* ================= WAVES ================= */
.wave-container {
  position: relative;
  background: #f8f9fa;
  height: 150px;
  margin-top: 50px;
}

.wave {
  position: absolute;
  bottom: 0;
  left: 0;
  width: 100%;
  height: 170px;
  background-repeat: repeat-x;
  background-size: cover;
  animation: wave 10s linear infinite;
}

.wave:nth-child(1) {
  background: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1440 320"><path fill="%231e3040" d="M0,160 C240,240 480,80 720,160 C960,240 1200,80 1440,160 L1440,320 L0,320 Z"/></svg>') repeat-x;
  z-index: 4;
}

.wave:nth-child(2) {
  bottom: 20px;
  background: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1440 320"><path fill="%23115481" d="M0,150 C240,230 480,70 720,150 C960,230 1200,70 1440,150 L1440,320 L0,320 Z"/></svg>') repeat-x;
  animation: wave 15s linear infinite reverse;
  z-index: 3;
}

.wave:nth-child(3) {
  bottom: 40px;
  background: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1440 320"><path fill="%233784b6" d="M0,140 C240,220 480,60 720,140 C960,220 1200,60 1440,140 L1440,320 L0,320 Z"/></svg>') repeat-x;
  animation: wave 20s linear infinite;
  z-index: 2;
}

.wave:nth-child(4) {
  bottom: 60px;
  background: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1440 320"><path fill="%2320a9ea" d="M0,130 C240,210 480,50 720,130 C960,210 1200,50 1440,130 L1440,320 L0,320 Z"/></svg>') repeat-x;
  animation: wave 25s linear infinite reverse;
  z-index: 1;
}

@keyframes wave {
  0% {
    background-position-x: 0;
  }
  100% {
    background-position-x: 1440px;
  }
}

/* ================= FOOTER ================= */
footer {
  background: linear-gradient(180deg, #1c2f40 0%, #172836 100%);
  color: #eef4f8;
  padding: 42px 24px 28px;
}

.footer-content {
  max-width: 1280px;
  margin: 0 auto;
  display: grid;
  grid-template-columns: 1.1fr 0.9fr 1fr;
  gap: 46px;
  align-items: start;
}

.footer-section h3 {
  margin: 0 0 18px;
  font-size: 1.12rem;
  letter-spacing: 0.04em;
  text-transform: uppercase;
  color: #ffffff;
}

.footer-logos {
  display: flex;
  flex-direction: column;
  align-items: flex-start;
}

.logo-wrapper {
  display: flex;
  gap: 16px;
  margin-bottom: 16px;
}

.logo-wrapper img {
  width: 120px;
  height: 120px;
  object-fit: contain;
  border-radius: 50%;
  background: rgba(255, 255, 255, 0.08);
  border: 1px solid rgba(255, 255, 255, 0.22);
  padding: 8px;
  transition: transform 260ms ease, box-shadow 260ms ease;
}

.logo-wrapper img:hover {
  transform: translateY(-4px);
  box-shadow: 0 12px 24px rgba(0, 0, 0, 0.25);
}

.footer-tagline {
  margin: 0;
  color: #d0deea;
  font-size: 0.98rem;
  line-height: 1.7;
  max-width: 350px;
}

.quick-links ul {
  list-style: none;
  padding: 0;
  margin: 0;
}

.quick-links li {
  margin-bottom: 10px;
}

.quick-links a {
  color: #dbe6ef;
  text-decoration: none;
  display: inline-flex;
  align-items: center;
  gap: 8px;
  padding: 6px 0;
  position: relative;
  transition: color 220ms ease, transform 220ms ease;
}

.quick-links a::after {
  content: "";
  position: absolute;
  left: 0;
  bottom: 2px;
  width: 0;
  height: 2px;
  border-radius: 999px;
  background: #62d6f8;
  transition: width 220ms ease;
}

.quick-links a:hover {
  color: #ffffff;
  transform: translateX(4px);
}

.quick-links a:hover::after {
  width: 100%;
}

.contact-line {
  margin: 0 0 12px;
  display: flex;
  gap: 12px;
  align-items: center;
  font-size: 0.96rem;
  text-decoration: none;
  color: #dbe6ef;
  transition: transform 220ms ease, color 220ms ease;
}

.contact-line a {
  color: inherit;
  text-decoration: none;
  transition: color 220ms ease;
}

.contact-icon {
  width: 34px;
  height: 34px;
  border-radius: 50%;
  background: rgba(255, 255, 255, 0.08);
  border: 1px solid rgba(255, 255, 255, 0.18);
  display: inline-flex;
  align-items: center;
  justify-content: center;
  color: #bfe9ff;
  flex: 0 0 34px;
  transition: background 220ms ease, color 220ms ease, transform 220ms ease;
}

.contact-line:hover {
  color: #ffffff;
  transform: translateX(4px);
}

.contact-line:hover .contact-icon {
  background: rgba(98, 214, 248, 0.18);
  color: #ffffff;
  transform: translateY(-2px);
}

.contact-line--button {
  width: 100%;
  padding: 0;
  border: 0;
  background: transparent;
  font: inherit;
  text-align: left;
  cursor: pointer;
}

.developers-modal-open { overflow: hidden; }
.developers-modal[hidden] { display: none; }
.developers-modal { position: fixed; inset: 0; z-index: 5000; display: grid; place-items: center; padding: 24px; }
.developers-modal__backdrop { position: absolute; inset: 0; background: rgba(8, 24, 34, .72); backdrop-filter: blur(5px); }
.developers-modal__dialog { position: relative; width: min(100%, 1120px); max-height: min(820px, calc(100vh - 48px)); overflow: auto; border: 1px solid rgba(23, 80, 69, .18); border-radius: 20px; background: radial-gradient(circle at 100% 0, rgba(199, 235, 220, .34), transparent 34%), #f7faf9; box-shadow: 0 30px 90px rgba(0, 0, 0, .36); outline: 0; }
.developers-modal__header { position: sticky; top: 0; z-index: 5; display: flex; justify-content: space-between; align-items: center; gap: 24px; padding: 24px 34px; color: #fff; background: linear-gradient(125deg, #093f35, #176c59); box-shadow: 0 7px 20px rgba(4, 45, 35, .13); }
.developers-modal__header-content { display: grid; grid-template-columns: 235px minmax(0, 1fr); align-items: center; gap: 24px; }
.developers-modal__brand { display: flex; align-items: center; gap: 12px; padding-right: 24px; border-right: 1px solid rgba(255,255,255,.22); }
.developers-modal__brand-logo { width: 54px; height: 54px; flex: 0 0 54px; object-fit: contain; border-radius: 50%; background: #fff; box-shadow: 0 5px 14px rgba(0,0,0,.18); }
.developers-modal__wordmark { display: block; width: 145px; height: 44px; object-fit: contain; }
.developers-modal__heading { min-width: 0; }
.developers-modal__header h2 { margin: 0; font-size: clamp(1.5rem, 3vw, 1.95rem); letter-spacing: -.03em; }
.developers-modal__header p { max-width: 580px; margin: 6px 0 0; color: rgba(255,255,255,.8); font-size: .88rem; line-height: 1.5; }
.developers-modal__close { width: 40px; height: 40px; flex: 0 0 40px; border: 1px solid rgba(255,255,255,.32); border-radius: 50%; color: #fff; background: rgba(255,255,255,.1); font-size: 1.8rem; line-height: 1; cursor: pointer; }
.developers-modal__close:hover, .developers-modal__close:focus-visible { background: rgba(255,255,255,.22); outline: 2px solid #b9e5d5; outline-offset: 2px; }
.developers-modal__grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); align-items: stretch; gap: 24px; padding: 28px 34px 38px; }
.developer-profile-card { position: relative; display: flex; flex-direction: column; align-items: center; margin-top: 60px; padding-top: 58px; border: 1px solid #d7e5df; border-radius: 16px; background: linear-gradient(180deg, #fff 0%, #f9fcfb 100%); box-shadow: 0 12px 30px rgba(15, 60, 49, .09); transition: transform 180ms ease, border-color 180ms ease, box-shadow 180ms ease; }
.developer-profile-card::before { content: ""; position: absolute; top: 0; left: 24px; right: 24px; height: 3px; border-radius: 0 0 999px 999px; background: linear-gradient(90deg, transparent, #38a584, transparent); }
.developer-profile-card:hover { border-color: #bedbce; transform: translateY(-4px); box-shadow: 0 18px 38px rgba(15, 60, 49, .13); }
.developer-profile-avatar { position: absolute; z-index: 1; top: -60px; width: 116px; height: 116px; overflow: hidden; border: 5px solid #fff; border-radius: 50%; background: linear-gradient(145deg, #eaf4ef, #d5eae1); box-shadow: 0 0 0 2px #239477, 0 8px 20px rgba(15, 70, 56, .18); }
.developer-profile-avatar img { display: block; width: 100%; height: 100%; object-fit: cover; object-position: center 22%; }
.developer-profile-details { width: 100%; height: 100%; box-sizing: border-box; display: flex; flex-direction: column; padding: 10px 20px 22px; text-align: center; }
.developer-profile-role { width: fit-content; max-width: 100%; align-self: center; display: inline-flex; align-items: center; justify-content: center; gap: 6px; margin-bottom: 10px; padding: 5px 10px; border: 1px solid #d6ede4; border-radius: 999px; color: #176d57; background: #ebf7f2; font-size: .64rem; font-weight: 800; letter-spacing: .08em; line-height: 1; text-align: center; text-transform: uppercase; white-space: nowrap; }
.developer-profile-card h3 { min-height: 2.8em; margin: 0 0 13px; color: #143d33; font-size: .88rem; line-height: 1.4; letter-spacing: .025em; text-transform: uppercase; }
.developer-profile-meta { display: grid; gap: 3px; margin: auto 0 0; padding: 12px 0 0; border-top: 1px solid #e1ebe7; list-style: none; text-align: left; }
.developer-profile-meta li { display: grid; grid-template-columns: 29px minmax(0, 1fr); align-items: center; gap: 9px; padding: 7px 0; }
.developer-profile-meta li > i { width: 29px; height: 29px; display: inline-flex; align-items: center; justify-content: center; border-radius: 8px; color: #19745d; background: #eaf5f0; font-size: .75rem; }
.developer-profile-meta li > span { min-width: 0; display: grid; gap: 1px; }
.developer-profile-meta small { color: #788983; font-size: .62rem; font-weight: 700; letter-spacing: .055em; text-transform: uppercase; }
.developer-profile-meta strong, .developer-profile-meta a { overflow-wrap: anywhere; color: #264a41; font-size: .75rem; font-weight: 650; line-height: 1.4; text-decoration: none; }
.developer-profile-meta .developer-profile-meta-school { margin: 1px -8px; padding: 9px 8px; border: 1px solid #d4ebe1; border-radius: 10px; background: linear-gradient(135deg, #edf8f3, #f8fcfa); }
.developer-profile-meta-school > i { color: #fff !important; background: linear-gradient(145deg, #28846b, #17604d) !important; box-shadow: 0 4px 10px rgba(23, 96, 77, .18); }
.developer-profile-meta-school small { color: #3d7968; }
.developer-profile-meta-school strong { color: #155541; font-size: .7rem; font-weight: 850; letter-spacing: .025em; line-height: 1.35; }
.developer-profile-meta a:hover, .developer-profile-meta a:focus-visible { color: #08765a; text-decoration: underline; }

.footer-bottom {
  max-width: 1280px;
  margin: 28px auto 0;
  border-top: 1px solid rgba(255, 255, 255, 0.16);
  padding-top: 16px;
  text-align: center;
}

.footer-bottom p {
  margin: 0;
  color: #b8cbd9;
  font-size: 0.88rem;
  letter-spacing: 0.03em;
}

/* ================= RESPONSIVE ================= */
@media (max-width: 1100px) {
  /* Keep the page canvas white and retain the animated wave layers. */
  .wave-container.site-footer-waves {
    overflow: hidden;
    margin-bottom: -2px;
    background-color: #f8f9fa;
  }

  .site-footer-waves .wave {
    display: block;
  }

  .site-footer .footer-bottom {
    border-top: 0 !important;
  }

  .site-footer {
    margin-bottom: -2px;
    border-bottom: 2px solid #172836;
    box-shadow: 0 6px 0 6px #172836;
  }
}

@media (max-width: 992px) {
  .footer-content {
    grid-template-columns: 1fr 1fr;
    gap: 34px;
  }

  .footer-logos {
    grid-column: 1 / -1;
  }
}

/* Tablet/iPad layout. Phone and desktop layouts remain unchanged. */
@media (min-width: 600px) and (max-width: 1100px) {
  .about-page .wave-container.site-footer-waves {
    background: #fff;
  }

  .site-footer-waves {
    position: relative;
    z-index: 2;
    margin-bottom: 0;
  }

  .site-footer-waves .wave {
    height: 150px;
    background-size: 650px 150px;
    will-change: background-position;
  }

  .site-footer-waves .wave:nth-child(1) {
    bottom: 0;
    animation: footerTabletWave 5s linear infinite;
  }

  .site-footer-waves .wave:nth-child(2) {
    bottom: 10px;
    animation: footerTabletWave 7s linear infinite reverse;
  }

  .site-footer-waves .wave:nth-child(3) {
    bottom: 20px;
    animation: footerTabletWave 9s linear infinite;
  }

  .site-footer-waves .wave:nth-child(4) {
    bottom: 30px;
    animation: footerTabletWave 11s linear infinite reverse;
  }

  .site-footer {
    position: relative;
    z-index: 3;
    margin-top: -2px;
    padding: 28px 38px 54px;
    border-top: 0;
  }

  .site-footer::before {
    content: "";
    position: absolute;
    top: -18px;
    right: 0;
    left: 0;
    height: 20px;
    background: #1c2f40;
    pointer-events: none;
  }

  .site-footer .footer-content {
    grid-template-columns: minmax(0, 1fr) minmax(0, 1fr) !important;
    column-gap: 54px;
    row-gap: 34px;
    align-items: start;
  }

  .site-footer .footer-logos {
    grid-column: 1 / -1;
    display: flex !important;
    flex-direction: row !important;
    justify-content: center;
    align-items: center;
    gap: 26px;
    padding: 0;
    border: 0;
    text-align: left;
  }

  .site-footer .logo-wrapper {
    justify-content: flex-start;
    gap: 10px;
    margin: 0;
    flex: 0 0 auto;
  }

  .site-footer .logo-wrapper img {
    width: 104px;
    height: 104px;
    padding: 7px;
  }

  .site-footer .footer-tagline {
    max-width: 320px;
    font-size: .94rem;
    line-height: 1.6;
  }

  .site-footer .footer-section h3 {
    margin-bottom: 15px;
    font-size: 1rem;
    letter-spacing: .06em;
  }

  .site-footer .quick-links ul {
    display: block;
  }

  .site-footer .quick-links li {
    min-width: 0;
    margin: 0 0 3px;
  }

  .site-footer .quick-links a,
  .site-footer .contact-line {
    font-size: .92rem;
    line-height: 1.5;
  }

  .site-footer .quick-links a {
    min-height: 36px;
    padding: 6px 0;
  }

  .site-footer .contact-line {
    gap: 11px;
    margin-bottom: 10px;
  }

  .site-footer .contact-icon {
    width: 34px;
    height: 34px;
    flex-basis: 34px;
    font-size: .78rem;
  }

  .site-footer .footer-bottom {
    margin-top: 30px;
    padding-top: 0;
  }

  .site-footer .footer-bottom p {
    font-size: .8rem;
  }

  @keyframes footerTabletWave {
    from { background-position-x: 0; }
    to { background-position-x: 650px; }
  }
}

@media (max-width: 680px) {
  .wave-container,
  .homepage .wave-container {
    height: 128px;
    margin: 0;
    overflow: hidden;
    background: linear-gradient(to bottom, transparent 0 64%, #1c2f40 64% 100%);
  }

  .about-page .wave-container {
    position: relative;
    height: 128px;
    margin: 0;
    overflow: hidden;
    background: linear-gradient(to bottom, #fff 0 64%, #1c2f40 64% 100%);
  }

  .wave {
    height: 92px;
    background-size: 620px 92px;
  }

  .wave:nth-child(1) { bottom: 0; }
  .wave:nth-child(2) { bottom: 12px; }
  .wave:nth-child(3) { bottom: 24px; }
  .wave:nth-child(4) { bottom: 36px; }

  footer {
    position: relative;
    z-index: 5;
    margin-top: -2px;
    padding: 28px 18px calc(82px + env(safe-area-inset-bottom));
  }

  .footer-content {
    grid-template-columns: 1fr;
    gap: 24px;
    width: 100%;
  }

  .footer-section {
    min-width: 0;
    text-align: left;
  }

  .footer-logos {
    grid-column: auto;
    align-items: center;
    padding-bottom: 22px;
    border-bottom: 1px solid rgba(255, 255, 255, .12);
    text-align: center;
  }

  .logo-wrapper {
    justify-content: center;
    gap: 14px;
    margin-bottom: 12px;
  }

  .logo-wrapper img {
    width: 78px;
    height: 78px;
    padding: 5px;
  }

  .footer-tagline {
    max-width: 275px;
    font-size: .72rem;
    line-height: 1.55;
  }

  .footer-section h3 {
    display: flex;
    align-items: center;
    gap: 9px;
    margin-bottom: 12px;
    font-size: .76rem;
    letter-spacing: .09em;
  }

  .footer-section h3::before {
    content: "";
    width: 18px;
    height: 2px;
    flex: 0 0 18px;
    border-radius: 99px;
    background: #62d6f8;
  }

  .quick-links ul {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    column-gap: 18px;
    row-gap: 2px;
  }

  .quick-links li {
    min-width: 0;
    margin: 0;
  }

  .quick-links a {
    width: fit-content;
    max-width: 100%;
    min-height: 32px;
    padding: 5px 0;
    font-size: .7rem;
    line-height: 1.35;
  }

  .contact-line {
    min-width: 0;
    gap: 10px;
    margin-bottom: 8px;
    font-size: .7rem;
    line-height: 1.4;
  }

  .contact-line > a,
  .contact-line > span {
    min-width: 0;
    overflow-wrap: anywhere;
  }

  .contact-line--button,
  .contact-line--button > span {
    font-family: inherit;
    font-size: .7rem !important;
    font-weight: 400;
    line-height: 1.4;
  }

  .contact-icon {
    width: 29px;
    height: 29px;
    flex-basis: 29px;
    font-size: .7rem;
  }

  .footer-bottom {
    margin-top: 22px;
    padding-top: 13px;
  }

  .footer-bottom p {
    font-size: .61rem;
    line-height: 1.5;
    letter-spacing: .02em;
  }

  .developers-modal { padding: 12px; }
  .developers-modal__dialog { max-height: calc(100dvh - 24px); scrollbar-gutter: auto; border-radius: 16px; }
  .developers-modal__header { align-items: flex-start; padding: 16px 50px 15px 16px; }
  .developers-modal__header-content { display: block; }
  .developers-modal__brand { width: 100%; box-sizing: border-box; display: flex; gap: 8px; margin-bottom: 10px; padding: 0 40px 9px 0; border-right: 0; border-bottom: 1px solid rgba(255,255,255,.2); }
  .developers-modal__brand-logo { width: 36px; height: 36px; flex-basis: 36px; }
  .developers-modal__wordmark { width: 112px; height: 32px; }
  .developers-modal__header h2 { font-size: 1.18rem; line-height: 1.2; }
  .developers-modal__header p { margin-top: 4px; font-size: .7rem; line-height: 1.45; }
  .developers-modal__close { position: absolute; top: 12px; right: 12px; width: 32px; height: 32px; flex-basis: 32px; font-size: 1.35rem; }
  .developers-modal__grid { grid-template-columns: 1fr; gap: 17px; padding: 16px 14px 24px; }
  .developer-profile-card { display: flex; margin-top: 43px; padding-top: 43px; border-radius: 14px; }
  .developer-profile-avatar {
    top: -43px;
    left: 50%;
    width: 82px;
    height: 82px;
    border-width: 3px;
    transform: translateX(-50%);
  }
  .developer-profile-details { padding: 7px 14px 16px; text-align: center; }
  .developer-profile-role { margin-bottom: 8px; padding: 4px 8px; font-size: .55rem; }
  .developer-profile-card h3 { min-height: 0; margin-bottom: 11px; font-size: .7rem; }
  .developer-profile-meta { gap: 2px; padding-top: 9px; }
  .developer-profile-meta li { grid-template-columns: 27px minmax(0, 1fr); gap: 8px; padding: 5px 0; }
  .developer-profile-meta li > i { width: 27px; height: 27px; font-size: .64rem; }
  .developer-profile-meta small { font-size: .52rem; }
  .developer-profile-meta strong,
  .developer-profile-meta a { font-size: .62rem; line-height: 1.35; }
  .developer-profile-meta .developer-profile-meta-school { margin-inline: -5px; padding: 7px 6px; }
  .developer-profile-meta-school strong { font-size: .59rem; letter-spacing: .018em; }
}

@media (min-width: 681px) and (max-width: 900px) {
  .developers-modal { padding: 20px; }
  .developers-modal__dialog { max-height: calc(100dvh - 40px); }
  .developers-modal__header { padding: 20px 26px; }
  .developers-modal__header-content { grid-template-columns: 190px minmax(0, 1fr); gap: 20px; }
  .developers-modal__brand { gap: 9px; padding-right: 18px; }
  .developers-modal__brand-logo { width: 45px; height: 45px; flex-basis: 45px; }
  .developers-modal__wordmark { width: 118px; height: 38px; }
  .developers-modal__header h2 { font-size: 1.4rem; }
  .developers-modal__header p { font-size: .75rem; }
  .developers-modal__grid { grid-template-columns: 1fr 1fr; gap: 20px; padding: 25px 26px 32px; }
  .developer-profile-card { margin-top: 54px; padding-top: 52px; }
  .developer-profile-avatar { top: -54px; width: 102px; height: 102px; }
  .developer-profile-card:last-child { width: calc(50% - 10px); grid-column: 1 / -1; justify-self: center; }
}

/* Tours ends on a white section, so keep the exposed canvas behind the waves white. */
.wave-container.site-footer-waves.wave-container--tours {
  background: #fff;
}

@media (max-width: 680px) {
  .wave-container.site-footer-waves.wave-container--tours {
    background: linear-gradient(to bottom, #fff 0 64%, #1c2f40 64% 100%);
  }
}

</style>
