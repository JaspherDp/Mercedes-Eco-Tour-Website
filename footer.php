<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/php/db_connection.php'; // adjust path if needed
?>


<head>
  <meta charset="UTF-8">
  <title>Paradise Island Tours</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
  <link rel="stylesheet" href="style.css">
</head>

<body>

  <!-- Waves Background -->
  <div class="wave-container">
    <div class="wave"></div>
    <div class="wave"></div>
    <div class="wave"></div>
    <div class="wave"></div>
  </div>

  <!-- Footer Section -->
  <footer>
    <div class="footer-content">

      <!-- LEFT SIDE: LOGOS ABOVE TAGLINE -->
<div class="footer-section footer-logos">
  <div class="logo-wrapper">
    <img src="img/mercedeslogo.png" alt="Logo 1">
    <img src="img/TourismLogo.png" alt="Logo 2">
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
          <li><a href="Termsconditions.php">Terms & Conditions</a></li>
          <li><a href="privacypolicy.php">Privacy Policy</a></li>
          <li><a href="php/operator_login.php">Operator Login</a></li>
          <li><a href="php/admin_login.php">Admin Login</a></li>
          <li><a href="php/hotel_admin_login.php">Hotel Admin Login</a></li>
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
      </div>

    </div>

    <!-- Footer Bottom -->
    <div class="footer-bottom">
      <p>&copy; 2024 Municipal Tourism Office - Mercedes. All rights reserved.</p>
    </div>
  </footer>

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
@media (max-width: 992px) {
  .footer-content {
    grid-template-columns: 1fr 1fr;
    gap: 34px;
  }

  .footer-logos {
    grid-column: 1 / -1;
  }
}

@media (max-width: 680px) {
  .wave-container {
    height: 135px;
    margin-top: 40px;
  }

  .wave {
    height: 150px;
    background-size: 900px 150px;
  }

  footer {
    padding: 52px 18px 22px;
  }

  .footer-content {
    grid-template-columns: 1fr;
    gap: 28px;
  }

  .footer-section {
    text-align: left;
  }
}

</style>
