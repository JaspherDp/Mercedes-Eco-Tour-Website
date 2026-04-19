<?php
chdir(__DIR__ . '/..');
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once 'php/db_connection.php';
?>

<html lang="en">
  <head>
    <meta charset="utf-8" />
    <meta content="width=device-width, initial-scale=1" name="viewport" />
    <title>iTour Mercedes</title>
    <link rel="icon" type="image/png" href="img/newlogo.png" />
    <link
      href="https://fonts.googleapis.com/css2?family=Roboto&display=swap"
      rel="stylesheet"
    />
    <link rel="stylesheet" href="styles/destination.css" />
    <link rel="stylesheet" href="styles/homepage.css" />
    <link rel="stylesheet" href="styles/style.css" />
  </head>
  
<body>

<!-- Header -->
  <div id="header"></div>

  <!-- Login/Signup Modal -->
  <div id="loginModal"></div>

  <div class="des_container">
    <div class="des_places-grid" id="placeGridContainer"></div>
  </div>

  <!-- PLACE PAGE -->
  <div id="des_placePage" class="des_place-page" aria-hidden="true">
    <!-- Header Image -->
    <div class="des_place-page-header" id="placeHeader">
      <img id="des_pageHeaderImg" src="" alt="" />
      <button class="des_close-btn" onclick="closePlacePage()">&times;</button>
    </div>

    <!-- Navbar under image -->
    <nav class="des_place-navbar">
  <ul>
    <li><a href="#" id="navAbout" class="active" onclick="showSection('about'); return false;">ABOUT</a></li>
    <li><a href="#" id="navResorts" onclick="showSection('resort'); return false;">RESORTS</a></li>
  </ul>
</nav>


    <!-- Page Body -->
    <div class="des_place-page-body" id="placeBody">
      <!-- About Section -->
      <section id="sectionAbout" class="des_section show">
        
        <!-- Title -->
        <h2 class="des_place-title" id="des_pageTitle"></h2>

        <!-- OUTSIDE FRAME HEADER -->
        <h3 class="des_section-header">About this Place</h3>

        <!-- FRAMED CONTENT ONLY -->
        <div class="des_info-section" id="placeAbout">
          <p id="des_pageDescription"></p>
        </div>

        <div class="des_activities-section" id="placeActivitiesWrap">
          <h3>Activities You Can Do</h3>
          <div class="des_activities-grid" id="des_activitiesGrid"></div>
        </div>

        <div class="des_image-gallery" id="des_imageGallery"></div>

        <!-- Map -->
        <div class="des_map-section page-transition" id="placeMapSection" aria-hidden="true">
          <div class="des_map-header">
            <h3>Location Map</h3>
            <div class="des_map-controls">
              <button class="map-btn" onclick="mapZoomChange(1)">＋</button>
              <button class="map-btn" onclick="mapZoomChange(-1)">－</button>
              <button class="map-btn" onclick="openInGoogleMaps()">Open in Google Maps</button>
            </div>
          </div>
          <iframe id="des_placeMap" width="100%" height="300" style="border:0; border-radius:12px; margin-top:8px;" loading="lazy" allowfullscreen referrerpolicy="no-referrer-when-downgrade"></iframe>
          <div class="map-meta"></div>
        </div>
      </section>

      <!-- Resorts Section -->
      <section id="sectionResorts" class="des_section">
        <div id="resortList"></div>
      </section>
    </div>
  </div>

  <!-- Modals (unchanged) -->
  <div id="des_imageModal" class="des_image-modal" aria-hidden="true">
    <div class="des_image-modal-content">
      <button class="des_image-close-btn" onclick="closeImageModal()">&times;</button>
      <button class="des_nav-btn des_prev" onclick="changeImage(-1)">‹</button>
      <img id="des_imageModalImg" class="des_image-modal-img" src="" alt="" />
      <button class="des_nav-btn des_next" onclick="changeImage(1)">›</button>
      <div class="des_image-counter" id="des_imageCounter"></div>
    </div>
  </div>

  <div id="des_resortModal" class="des_image-modal" aria-hidden="true">
    <div class="des_image-modal-content">
      <button class="des_image-close-btn" onclick="closeResortImageModal()">&times;</button>
      <button class="des_nav-btn des_prev" onclick="changeResortImage(-1)">‹</button>
      <img id="des_resortModalImg" class="des_image-modal-img" src="" alt="" />
      <button class="des_nav-btn des_next" onclick="changeResortImage(1)">›</button>
      <div class="des_image-counter" id="des_resortCounter"></div>
    </div>
  </div>

</body>
<?php include 'footer.php'; ?>

<script src="destination.js"></script>
<script src="js/header.js"></script>
<script>
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


// Scroll to top
const scrollToTopBtn = document.getElementById("scroll-to-top-btn");
window.addEventListener("scroll", () => {
  scrollToTopBtn.style.display = window.scrollY > 200 ? "flex" : "none";
});
scrollToTopBtn.addEventListener("click", () => {
  window.scrollTo({ top: 0, behavior: "smooth" });
});

</script>

  </body>
</html>

