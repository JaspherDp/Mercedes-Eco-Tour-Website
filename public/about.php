<?php
chdir(__DIR__ . '/..');
session_start();
require 'php/db_connection.php';

// Fetch ALL about_gallery items (no fixed IDs)
try {
    $stmt = $pdo->query("SELECT * FROM about_gallery ORDER BY id ASC");
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // In production you might log this instead of echoing
    $items = [];
}
?>

<html lang="en">
<head>
  <meta charset="utf-8"/>
  <meta content="width=device-width, initial-scale=1" name="viewport"/>
  <title>iTour Mercedes</title>
  <link rel="icon" type="image/png" href="img/newlogo.png">
  <link href="https://fonts.googleapis.com/css2?family=Roboto&amp;display=swap" rel="stylesheet"/>
  <link rel="stylesheet" href="styles/about.css">
  <link rel="stylesheet" href="styles/homepage.css" />
</head>
<body>
<!-- Header -->
<div id="header"></div>
<!-- Placeholder for Login/Signup Modal -->
<div id="loginModal"></div>

<section class="about-hero">
        <div class="office-image">
            <img src="img/mercedes-hall.png" alt="Mercedes-MunicipalHall">
        </div>
        <div class="about-content">
            <h1>About the Tourism Office</h1>
            <p>The Municipal Tourism Office of Mercedes serves as the primary hub for promoting sustainable tourism and showcasing the natural beauty and cultural heritage of our municipality.</p>
            <p>We are dedicated to providing comprehensive tourism services, coordinating local tours, and assisting visitors in discovering the hidden gems of Mercedes. Our team works closely with tour operators, local businesses, and community stakeholders to ensure authentic and memorable experiences for all travelers.</p>
            <p>Whether you're planning a relaxing beach getaway, an adventure-filled island hopping tour, or seeking to immerse yourself in local culture, our office is here to guide you every step of the way.</p>
        </div>
    </section>

<section class="attractions-section">
  <div class="carousel-container">
    <div class="carousel">

      <?php foreach ($items as $item):
          if (!$item) continue;
          // safe image path fallback
          $imgPath = isset($item['image_path']) && $item['image_path'] !== ''
                    && file_exists($item['image_path']) ? $item['image_path'] : 'img/default.jpg';
      ?>
        <div class="carousel-item"
            data-title="<?= htmlspecialchars($item['title'] ?? '') ?>"
            data-desc="<?= htmlspecialchars($item['short_desc'] ?? '') ?>"
            data-longdesc="<?= htmlspecialchars($item['long_desc'] ?? '') ?>">

          <img src="<?= htmlspecialchars($imgPath) ?>" 
              alt="<?= htmlspecialchars($item['title'] ?? '') ?>">

          <div class="overlay">
            <h5><?= htmlspecialchars($item['title'] ?? '') ?></h5>
            <p><?= htmlspecialchars($item['short_desc'] ?? '') ?></p>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>


<div class="image-modal" id="imageModal">
  <div class="modal-content">
    <span class="close">&times;</span>
    <img id="modalImage" src="" alt="">
    <div class="modal-text">
      <h5 id="modalTitle"></h5>
      <p id="modalDesc"></p>
    </div>
  </div>
</div>


<section class="map-section">
    <div class="map-info">
        <h2>Visit Us</h2>
        <p>Our office is conveniently located near the Mercedes-Manguisoc Port, making it easy for visitors arriving by sea to access our services immediately upon arrival.</p>
        <p>We welcome walk-in visitors during office hours and are always happy to provide tourism information, brochures, and assistance with tour bookings and accommodations.</p>
        
        <div class="contact-details">
            <h3>Contact Information</h3>
            <p><strong>Address:</strong> Municipal Tourism Office, Mercedes, Camarines Norte</p>
            <p><strong>Near:</strong> Mercedes-Manguisoc Port</p>
            <p><strong>Office Hours:</strong> Monday - Friday, 8:00 AM - 5:00 PM</p>
            <p><strong>Email:</strong> tourism@mercedes.gov.ph</p>
        </div>
    </div>
    <div class="map-container">
    <iframe 
        src="https://www.google.com/maps/embed?pb=!1m18!1m12!1m3!1d459.7506012181713!2d123.01277317345628!3d14.108871259920196!2m3!1f0!2f0!3f0!3m2!1i1024!2i768!4f13.1!3m3!1m2!1s0x3398adb7b671f4a3%3A0x2292aae9d5fbe681!2sMercedes-Manguisoc%20Port!5e1!3m2!1sen!2sph!4v1760714493461!5m2!1sen!2sph"
        allowfullscreen=""
        loading="lazy"
        referrerpolicy="no-referrer-when-downgrade">
    </iframe>
    </div>
</section>
<?php include 'footer.php'; ?>
<!-- Script -->
<script src="js/header.js"></script>
<script>
document.addEventListener("DOMContentLoaded", () => {

  fetch("php/header.php")
  .then(res => res.text())
  .then(html => {
    document.getElementById("header").innerHTML = html;
    if (typeof initHeader === "function") initHeader();

    // Highlight current nav link
    const current = location.pathname.split("/").pop();
    document.querySelectorAll("#header nav ul li a").forEach(link => {
      link.classList.remove("active");
      if (link.getAttribute("href") === current) link.classList.add("active");
    });

    // Scroll To Top
    const scrollToTopBtn = document.getElementById("scroll-to-top-btn");
    if (scrollToTopBtn) {
      window.addEventListener("scroll", () => {
        scrollToTopBtn.style.display = window.scrollY > 200 ? "flex" : "none";
      });
      scrollToTopBtn.addEventListener("click", () => {
        window.scrollTo({ top: 0, behavior: "smooth" });
      });
    }

    // Mobile nav toggle
    const toggle = document.querySelector('#header .menu-toggle');
    const navLinks = document.querySelector('#header nav ul');
    toggle?.addEventListener('click', () => {
      navLinks?.classList.toggle('show');
    });

    // Homepage nav scroll effect
    if (document.body.classList.contains("homepage")) {
      const nav = document.querySelector("#header nav");
      function checkNavScroll() {
        nav?.classList.toggle("scrolled", window.scrollY > 50);
      }
      window.addEventListener("scroll", checkNavScroll);
      window.scrollTo(0, 0);
      checkNavScroll();
    }

    /* ==========================================================
         2. LOAD SWEETALERT2 + LOGIN / SIGNUP MODAL
    ========================================================== */
    const swalScript = document.createElement("script");
    swalScript.src = "https://cdn.jsdelivr.net/npm/sweetalert2@11";
    swalScript.onload = () => {
      // Now load logsign modal
      fetch("logsign-modal.html")
        .then(res => res.text())
        .then(html => {
          document.getElementById("loginModal").innerHTML = html;

          const logsignScript = document.createElement("script");
          logsignScript.src = "logsign.js";
          logsignScript.onload = () => {
            if (typeof initLogSignEvents === "function") initLogSignEvents();
          };
          document.body.appendChild(logsignScript);
        })
        .catch(err => console.error("Login modal load error:", err));
    };
    document.body.appendChild(swalScript);

  })
  .catch(err => console.error("Header load error:", err));

});

    // Mapping locations to Google Maps embed URLs
    const mapSources = {
        'apuao-pequena': 'https://www.google.com/maps/embed?pb=!1m18!1m12!1m3!1d3930.671173076161!2d123.091234!3d14.100123!2m3!1f0!2f0!3f0!3m2!1i1024!2i768!4f13.1!3m3!1m2!1s0x3398b7d1f94dffff%3A0x3e823aa4c85d8d5f!2sApuao%20Peque%C3%B1a!5e0!3m2!1sen!2sph!4v0000000000000'
        // Add more if you want to handle more islands later
    };

    document.querySelectorAll('.carousel-item.clickable').forEach(item => {
        item.addEventListener('click', () => {
            const locationKey = item.getAttribute('data-location');
            const mapSrc = mapSources[locationKey];
            if (mapSrc) {
                document.getElementById('dynamic-map').src = mapSrc;
                document.getElementById('dynamic-map-container').classList.remove('map-hidden');
                document.getElementById('dynamic-map-container').scrollIntoView({ behavior: 'smooth' });
            }
        });
    });

const items = document.querySelectorAll('.carousel-item');
const modal = document.getElementById('imageModal');
const modalImg = document.getElementById('modalImage');
const modalTitle = document.getElementById('modalTitle');
const modalDesc = document.getElementById('modalDesc');
const closeBtn = document.querySelector('.close');

items.forEach(item => {
  const img = item.querySelector('img');
  const title = item.dataset.title;
  const desc = item.dataset.desc;
  const longDesc = item.dataset.longdesc;

  // Fill overlay text dynamically
  item.querySelector('h5').textContent = title;
  item.querySelector('p').textContent = desc;

  // Modal click event
  item.addEventListener('click', () => {
    modalImg.src = img.src;
    modalTitle.textContent = title;
    modalDesc.textContent = longDesc || desc;

    modal.style.display = 'flex';
    setTimeout(() => modal.classList.add('show'), 10);
  });
});

// Close modal
closeBtn.addEventListener('click', () => {
  modal.classList.remove('show');
  setTimeout(() => modal.style.display = 'none', 300);
});

window.addEventListener('click', (e) => {
  if (e.target === modal) {
    modal.classList.remove('show');
    setTimeout(() => modal.style.display = 'none', 300);
  }

const scrollBtn = document.getElementById("scroll-to-top-btn");

// Show button when user scrolls down 100px from top
function handleScroll() {
  if (window.scrollY > 100) {
    scrollBtn.classList.add("show");
  } else {
    scrollBtn.classList.remove("show");
  }
}

// Scroll smoothly to top when clicked
scrollBtn.addEventListener("click", () => {
  window.scrollTo({ top: 0, behavior: "smooth" });
});

// Trigger on scroll and on page load
window.addEventListener("scroll", handleScroll);
window.addEventListener("load", handleScroll);

});

</script>
</body>
</html>

