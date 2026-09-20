<?php
chdir(__DIR__ . '/..');
require_once __DIR__ . '/../php/session_security.php';
AppSessionStart();
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

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8"/>
  <meta content="width=device-width, initial-scale=1" name="viewport"/>
  <meta name="description" content="Learn about iTour Mercedes, a web-based tourism management platform designed to help visitors explore Mercedes, Camarines Norte.">
  <link rel="canonical" href="https://itourmercedes.com/about.php">
  <title>About iTour Mercedes | Mercedes, Camarines Norte Tourism</title>
  <link rel="icon" type="image/png" href="img/newlogo.png">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&amp;display=swap" rel="stylesheet"/>
  <link rel="stylesheet" href="styles/homepage.css?v=<?= (int)@filemtime(__DIR__ . '/../styles/homepage.css') ?>" />
  <link rel="stylesheet" href="styles/about.css?v=<?= (int)@filemtime(__DIR__ . '/../styles/about.css') ?>">
  <link rel="stylesheet" href="styles/back-to-top.css?v=<?= (int)@filemtime(__DIR__ . '/../styles/back-to-top.css') ?>">
  <script>document.documentElement.classList.add('itour-page-loading');</script>
  <link rel="stylesheet" href="styles/page-loader.css?v=<?= (int)@filemtime(__DIR__ . '/../styles/page-loader.css') ?>">
  <script src="js/page-loader.js?v=<?= (int)@filemtime(__DIR__ . '/../js/page-loader.js') ?>"></script>
</head>
<body class="about-page">
<?php include __DIR__ . '/../includes/page_loader.php'; ?>
<!-- Header -->
<div id="header"></div>
<!-- Placeholder for Login/Signup Modal -->
<div id="loginModal"></div>

<main>
  <section class="about-overview" aria-labelledby="officeHeading" data-about-reveal>
    <div class="about-shell">
      <div class="about-overview-heading">
        <div><p class="about-eyebrow">Who we are</p><h2 id="officeHeading">About the Tourism Office</h2></div>
        <p>Official visitor support rooted in local knowledge, responsible tourism, and community partnership.</p>
      </div>

      <div class="about-profile-grid">
        <figure class="about-profile-image">
          <img src="img/mercedes-hall.png" alt="Mercedes Municipal Hall and Tourism Office" decoding="async" fetchpriority="high">
          <div class="about-profile-shade"></div>
          <figcaption><small>Municipal Tourism Office</small><strong>Your local gateway to Mercedes</strong><span>Visitor information &bull; Planning assistance &bull; Local coordination</span></figcaption>
          <div class="about-profile-status"><i aria-hidden="true"></i><span><strong>Open weekdays</strong><small>8:00 AM&ndash;5:00 PM</small></span></div>
        </figure>

        <article class="about-story">
        <p>The Municipal Tourism Office of Mercedes serves as the primary hub for promoting sustainable tourism and showcasing the natural beauty and cultural heritage of our municipality.</p>
        <p>We provide comprehensive tourism services, coordinate local tours, and help visitors discover the hidden gems of Mercedes. Our team works closely with tour operators, local businesses, and community stakeholders to create authentic and memorable experiences.</p>
        <p>Whether you are planning a relaxing beach getaway, an adventure-filled island-hopping tour, or a deeper encounter with local culture, our office is here to guide you every step of the way.</p>
          <blockquote><span>&ldquo;</span><p>We connect visitors with the people, places, and stories that make Mercedes worth discovering.</p></blockquote>
        </article>
      </div>

      <div class="about-services" aria-label="Tourism office services">
        <div class="about-services-heading"><span>What we do</span><strong>Visitor support with a local perspective</strong></div>
        <div class="about-services-list">
          <div class="about-service-item"><b>01</b><div><strong>Travel guidance</strong><p>Practical information for destinations, routes, accommodations, and local experiences.</p></div><span aria-hidden="true">&rarr;</span></div>
          <div class="about-service-item"><b>02</b><div><strong>Tourism coordination</strong><p>Connections with accredited operators, community partners, guides, and transport providers.</p></div><span aria-hidden="true">&rarr;</span></div>
          <div class="about-service-item"><b>03</b><div><strong>Destination stewardship</strong><p>Programs that support responsible travel, local livelihoods, and cultural preservation.</p></div><span aria-hidden="true">&rarr;</span></div>
        </div>
      </div>

    <div class="about-facts" aria-label="Mercedes tourism highlights">
      <div><strong>7+</strong><span>island destinations</span></div>
      <div><strong>Local</strong><span>tourism partnerships</span></div>
      <div><strong>Official</strong><span>visitor information</span></div>
      <div><strong>Weekdays</strong><span>in-person assistance</span></div>
    </div>
    </div>
  </section>

  <section class="attractions-section" aria-labelledby="galleryHeading" data-about-reveal>
    <div class="about-shell">
      <div class="about-section-heading">
        <div><p class="about-eyebrow">About gallery</p><h2 id="galleryHeading">Mercedes through our lens</h2><p>Browse the gallery managed by the Tourism Office. Select any photograph to read its complete story.</p></div>
        <div class="about-carousel-controls" aria-label="Gallery controls">
          <span class="about-gallery-count"><?= count($items) ?> <?= count($items) === 1 ? 'story' : 'stories' ?></span>
          <button type="button" id="aboutGalleryPrev" aria-label="Show previous gallery items">&larr;</button>
          <button type="button" id="aboutGalleryNext" aria-label="Show next gallery items">&rarr;</button>
        </div>
      </div>
      <div class="carousel-container">
        <div class="carousel" id="aboutGallery" role="list" tabindex="0" aria-label="Mercedes tourism gallery">

      <?php foreach ($items as $item):
          if (!$item) continue;
          // safe image path fallback
          $imgPath = isset($item['image_path']) && $item['image_path'] !== ''
                    && file_exists($item['image_path']) ? $item['image_path'] : 'img/default.jpg';
      ?>
        <button type="button" class="carousel-item" role="listitem"
            data-title="<?= htmlspecialchars($item['title'] ?? '') ?>"
            data-desc="<?= htmlspecialchars($item['short_desc'] ?? '') ?>"
            data-longdesc="<?= htmlspecialchars($item['long_desc'] ?? '') ?>">

          <img src="<?= htmlspecialchars($imgPath) ?>" loading="lazy" decoding="async"
              alt="<?= htmlspecialchars($item['title'] ?? '') ?>">

          <div class="overlay">
            <span>Explore story</span>
            <h5><?= htmlspecialchars($item['title'] ?? '') ?></h5>
            <p><?= htmlspecialchars($item['short_desc'] ?? '') ?></p>
          </div>
        </button>
      <?php endforeach; ?>
      <?php if (!$items): ?>
        <div class="about-gallery-empty"><strong>More stories are coming soon.</strong><span>Visit the Tourism Office for current local recommendations.</span></div>
      <?php endif; ?>
        </div>
      </div>
    </div>
</section>


<div class="image-modal" id="imageModal" aria-hidden="true" role="dialog" aria-modal="true" aria-labelledby="modalTitle">
  <div class="modal-content">
    <button type="button" class="close" aria-label="Close story">&times;</button>
    <img id="modalImage" src="" alt="">
    <div class="modal-text">
      <span>Mercedes tourism story</span>
      <h5 id="modalTitle"></h5>
      <p id="modalDesc"></p>
    </div>
  </div>
</div>


<section class="map-section" id="visitOffice" aria-labelledby="visitHeading" data-about-reveal>
  <div class="about-shell map-section-grid">
    <div class="map-info">
        <p class="about-eyebrow">Plan your visit</p>
        <h2 id="visitHeading">Visit the Tourism Office</h2>
        <p>Our office is conveniently located near the Mercedes-Manguisoc Port, making it easy for visitors arriving by sea to access our services immediately upon arrival.</p>
        <p>We welcome walk-in visitors during office hours and are always happy to provide tourism information, brochures, and assistance with tour bookings and accommodations.</p>
        
        <div class="contact-details">
            <div><span>Address</span><strong>Municipal Tourism Office, Mercedes, Camarines Norte</strong></div>
            <div><span>Landmark</span><strong>Near Mercedes-Manguisoc Port</strong></div>
            <div><span>Office hours</span><strong>Monday&ndash;Friday, 8:00 AM&ndash;5:00 PM</strong></div>
            <div><span>Email</span><a href="mailto:tourism@mercedes.gov.ph">tourism@mercedes.gov.ph</a></div>
        </div>
        <a class="about-button about-button--primary about-directions" href="https://maps.app.goo.gl/KbuTauSSe7rLZ2mX9" target="_blank" rel="noopener">Open Map <span aria-hidden="true">&nearr;</span></a>
    </div>
    <div class="map-container">
    <div class="about-map-label"><span>Municipal Tourism Office</span><strong>Mercedes, Camarines Norte</strong></div>
    <iframe 
        src="https://www.google.com/maps/embed?pb=!1m18!1m12!1m3!1d459.7506012181713!2d123.01277317345628!3d14.108871259920196!2m3!1f0!2f0!3f0!3m2!1i1024!2i768!4f13.1!3m3!1m2!1s0x3398adb7b671f4a3%3A0x2292aae9d5fbe681!2sMercedes-Manguisoc%20Port!5e1!3m2!1sen!2sph!4v1760714493461!5m2!1sen!2sph"
        allowfullscreen=""
        loading="lazy"
        referrerpolicy="no-referrer-when-downgrade">
    </iframe>
    </div>
  </div>
</section>

</main>
<a class="about-mobile-floating-book" href="hotel_resorts.php?tab=tours" aria-label="Book a tour">
  <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7.5A2.5 2.5 0 0 0 6.5 10 2.5 2.5 0 0 0 4 12.5V17a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-4.5a2.5 2.5 0 0 0 0-5V7a2 2 0 0 0-2-2H6a2 2 0 0 0-2 2v.5Z"></path><path d="M14 8.5h3M14 12h3M14 15.5h2"></path></svg>
  <span>Book a Tour</span>
</a>
<?php include 'footer.php'; ?>
<!-- Script -->
<script src="js/header.js?v=<?= (int)@filemtime(__DIR__ . '/../js/header.js') ?>"></script>
<script>
if (false) {
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
      fetch("logsign-modal.html?v=15")
        .then(res => res.text())
        .then(html => {
          document.getElementById("loginModal").innerHTML = html;

          const logsignScript = document.createElement("script");
          logsignScript.src = "logsign.js?v=16";
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
}

</script>
<script src="js/about.js?v=<?= (int)@filemtime(__DIR__ . '/../js/about.js') ?>"></script>
<script src="js/back-to-top.js?v=<?= (int)@filemtime(__DIR__ . '/../js/back-to-top.js') ?>"></script>
</body>
</html>

