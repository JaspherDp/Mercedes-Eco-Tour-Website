<?php
chdir(__DIR__ . '/..');
session_start();
require_once 'php/db_connection.php';

// Function to get the latest non-empty value for a given column
function getLatestFieldValue($pdo, $column) {
    $stmt = $pdo->prepare("SELECT $column FROM featured_section WHERE $column IS NOT NULL AND $column != '' ORDER BY id DESC LIMIT 1");
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row[$column] ?? '';
}

// Fetch latest value for each field
$description1   = getLatestFieldValue($pdo, 'description1');
$description2   = getLatestFieldValue($pdo, 'description2');
$footer_text    = getLatestFieldValue($pdo, 'footer_text');
$video_path     = getLatestFieldValue($pdo, 'video_path');
$slider_image1  = getLatestFieldValue($pdo, 'slider_image1');
$slider_image2  = getLatestFieldValue($pdo, 'slider_image2');
$slider_image3  = getLatestFieldValue($pdo, 'slider_image3');
$slider_image4  = getLatestFieldValue($pdo, 'slider_image4');
$small_image1   = getLatestFieldValue($pdo, 'small_image1');
$small_image2   = getLatestFieldValue($pdo, 'small_image2');
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>iTour Mercedes</title>
  <link rel="icon" type="image/png" href="img/newlogo.png" />
  <link href="https://fonts.googleapis.com/css2?family=Roboto&display=swap" rel="stylesheet" />
  <link rel="stylesheet" href="styles/homepage.css" />
</head>

<body class="homepage">

  <!-- Header -->
  <div id="header"></div>

<section class="home-hero">
  <div class="hero-overlay"></div>

  <div class="hero-content">
    <br><br><br><br><br><br><br><br><br><br>

    <div class="hero-actions">
      <a href="destination.php" class="hero-btn">Explore</a>

      <div class="hero-search">
        <input type="text" placeholder="Search destinations..." />
        <button>Search</button>
      </div>
    </div>
  </div>
</section>

  <!-- Login/Signup Modal -->
  <div id="loginModal"></div>

  <!-- Booking Types Section -->
<section class="booking-types">
  <div class="bt-container">

      <!-- Packages -->
      <div class="bt-card">
          <img src="img/packageshome.png" alt="Package Icon" class="bt-icon">
          <div class="bt-info">
              <h3>Packages</h3>
              <p>Explore complete travel packages.</p>
              <a href="tourss.php?tab=tour-packages" class="bt-btn">View Packages</a>
          </div>
      </div>
      
      <!-- Tour Guides -->
      <div class="bt-card">
          <img src="img/tourguidehome.png" alt="Tour Guide Icon" class="bt-icon">
          <div class="bt-info">
              <h3>Tour Guides</h3>
              <p>Find professional and local tour guides.</p>
              <a href="tourss.php?tab=tour-guides" class="bt-btn">View Tour Guides</a>
          </div>
      </div>

      <!-- Boats -->
      <div class="bt-card">
          <img src="img/boathome.png" alt="Boat Icon" class="bt-icon">
          <div class="bt-info">
              <h3>Boats</h3>
              <p>Book boats for sea activities.</p>
              <a href="tourss.php?tab=our-boats" class="bt-btn">View Boats</a>
          </div>
      </div>

      <!-- Hotels -->
      <div class="bt-card">
          <img src="img/hotelshome.png" alt="Boat Icon" class="bt-icon">
          <div class="bt-info">
              <h3>Hotels / Resorts</h3>
              <p>Find the perfect place to rest.</p>
              <a href="hotel_resorts.php" class="bt-btn">View Hotels & Resorts</a>
          </div>
      </div>

  </div>
</section>

<section class="fe-featured">
  <div class="fe-container">

    <!-- TOP ROW -->
    <div class="fe-top">

      <!-- VIDEO -->
      <div class="fe-video">
        <video src="<?= !empty($video_path) ? htmlspecialchars($video_path) : 'img/samplevideo.mp4' ?>" autoplay muted loop controls playsinline></video>
      </div>

      <!-- TEXT -->
      <div class="fe-text">
        <p><?= !empty($description1) ? htmlspecialchars($description1) : 'Your description 1 here...' ?></p>
        <p><?= !empty($description2) ? htmlspecialchars($description2) : 'Your description 2 here...' ?></p>
      </div>

    </div>

    <!-- MIDDLE ROW -->
    <div class="fe-middle">

      <!-- SMALL IMAGE LEFT -->
      <div class="fe-small">
        <?php $img1 = $small_image1 ?? 'img/sampleimage.png'; ?>
        <img src="<?= htmlspecialchars($img1) ?>" alt="Small 1">
      </div>

      <!-- BIG SLIDER CENTER -->
      <div class="fe-gallery">
        <div class="fe-slider">
          <?php for ($i = 1; $i <= 4; $i++): 
            $img = ${"slider_image$i"} ?? 'img/sampleimage.png'; ?>
            <div class="fe-slide <?= $i === 1 ? 'active' : '' ?>" style="background-image: url('<?= htmlspecialchars($img) ?>');"></div>
          <?php endfor; ?>
        </div>

        <div class="fe-dots">
          <?php for ($i = 1; $i <= 4; $i++): ?>
            <span class="<?= $i === 1 ? 'active' : '' ?>"></span>
          <?php endfor; ?>
        </div>
      </div>

      <!-- SMALL IMAGE RIGHT -->
      <div class="fe-small">
        <?php $img2 = $small_image2 ?? 'img/sampleimage.png'; ?>
        <img src="<?= htmlspecialchars($img2) ?>" alt="Small 2">
      </div>

    </div>

    <!-- FOOTER -->
    <div class="fe-footer-text">
      <p><?= !empty($footer_text) ? htmlspecialchars($footer_text) : 'Your footer text here...' ?></p>
    </div>

  </div>

  <!-- MODAL (UNCHANGED) -->
  <div id="feModal" class="fe-modal">
    <span class="fe-close">&times;</span>
    <img class="fe-modal-content" id="feModalImg">
  </div>
</section>

<!-- BOOKING PROCESS SECTION -->
<section class="booking-process">
    <h2 class="bp-title">Booking Process</h2>

    <div class="bp-steps">

       <!-- STEP 1 -->
        <div class="bp-step">
            <div class="bp-circle">1</div>
            <h3>Login / Sign up</h3>
            <p>You need to login to continue booking.</p>
        </div>

        <!-- STEP 2 -->
        <div class="bp-step">
            <div class="bp-circle">2</div>
            <h3>Submit Booking</h3>
            <p>Fill out your booking details and submit your request.</p>
        </div>

        <!-- STEP 3 -->
        <div class="bp-step">
            <div class="bp-circle">3</div>
            <h3>Admin Review Booking</h3>
            <p>Your submitted booking will be checked by our admin.</p>
        </div>

        <!-- STEP 4 -->
        <div class="bp-step">
            <div class="bp-circle">4</div>
            <h3>Booking Accepted or Declined</h3>
            <p>You will receive a notification once reviewed.</p>
        </div>

    </div>
</section> 

<!-- Destination Highlight Section -->
<section class="dest-section">
    <div class="dest-container">

        <!-- RIGHT TEXT CONTENT -->
        <div class="dest-text">
            <h2>Discover the Beauty of Mercedes</h2>
            <p>
                Explore breathtaking islands, beaches, nature trails, and local attractions 
                that make Mercedes a true paradise for travelers seeking unique adventures.
                Explore breathtaking islands, beaches, nature trails, and local attractions 
                that make Mercedes a true paradise for travelers seeking unique adventures.
                Explore breathtaking islands, beaches, nature trails, and local attractions 
                that make Mercedes a true paradise for travelers seeking unique adventures.
                that make Mercedes a true paradise for travelers seeking unique adventures.
                <br>
                <br> 
                that make Mercedes a true paradise for travelers seeking unique adventures.
                 Explore breathtaking islands, beaches, nature trails, and local attractions 
                that make Mercedes a true paradise for travelers seeking unique adventures.
                Explore breathtaking islands, beaches, nature trails, and local attractions 
                that make Mercedes a true paradise for travelers seeking unique adventures.
                that make Mercedes a true paradise for travelers seeking unique adventures.
            </p>

            <a href="destination.php" class="dest-btn">Learn More</a>
        </div>

                <!-- LEFT IMAGES -->
        <div class="dest-images">
            <img src="img/Apuao Pequeña.png" alt="Destination Image 1">
            <img src="img/Apuao Pequeña.png"alt="Destination Image 2">
            <img src="img/Apuao Pequeña.png" alt="Destination Image 3">
            <img src="img/Apuao Pequeña.png" alt="Destination Image 4">
        </div>

    </div>
</section>


<?php include 'footer.php'; ?>


<script src="js/header.js"></script>
<script>
document.addEventListener("DOMContentLoaded", () => {
  // --- Load header dynamically ---
  fetch("php/header.php")
    .then(res => res.text())
    .then(html => {
      document.getElementById("header").innerHTML = html;
      if (typeof initHeader === "function") initHeader();

      // Scroll to Top Button
      const scrollToTopBtn = document.getElementById("scroll-to-top-btn");
      window.addEventListener("scroll", () => {
        if (scrollToTopBtn) scrollToTopBtn.style.display = window.scrollY > 200 ? "flex" : "none";
      });
      scrollToTopBtn?.addEventListener("click", () => {
        window.scrollTo({ top: 0, behavior: "smooth" });
      });

      // Mobile nav toggle
      const toggle = document.querySelector('#header .menu-toggle');
      const navLinks = document.querySelectorAll("#header nav a");
      toggle?.addEventListener('click', () => {
        navLinks.classList.toggle('show');
      });

      // Homepage nav scroll effect
      if (document.body.classList.contains("homepage")) {
        const nav = document.querySelector("#header nav");
        function checkNavScroll() {
          if (window.scrollY > 50) nav.classList.add("scrolled");
          else nav.classList.remove("scrolled");
        }
        window.addEventListener("scroll", checkNavScroll);
        window.scrollTo(0, 0);
        checkNavScroll();
      }

      // --- Load login/signup modal dynamically ---
      fetch("logsign-modal.html")
        .then(res => res.text())
        .then(html => {
          const modalContainer = document.getElementById("loginModal");
          if (!modalContainer) return console.error("loginModal container not found");
          modalContainer.innerHTML = html;

          // Load SweetAlert2 dynamically
          const swalScript = document.createElement("script");
          swalScript.src = "https://cdn.jsdelivr.net/npm/sweetalert2@11";
          swalScript.onload = () => {
            // Load logsign.js after SweetAlert2
            const logsignScript = document.createElement("script");
            logsignScript.src = "logsign.js";
            logsignScript.onload = () => {
              if (typeof initLogSignEvents === "function") {
                initLogSignEvents();
                const params = new URLSearchParams(window.location.search);
                if (params.get("open_login") === "1") {
                  const openBtn = document.getElementById("openModalBtn");
                  openBtn?.click();
                  params.delete("open_login");
                  const newQuery = params.toString();
                  const newUrl = `${window.location.pathname}${newQuery ? `?${newQuery}` : ""}${window.location.hash}`;
                  window.history.replaceState({}, "", newUrl);
                }
              } else {
                console.error("initLogSignEvents not found in logsign.js");
              }
            };
            document.body.appendChild(logsignScript);
          };
          document.body.appendChild(swalScript);
        })
        .catch(err => console.error("Modal load error:", err));
    })
    .catch(err => console.error("Header load error:", err));
});



// ===== IMAGE SLIDER =====
const slides = document.querySelectorAll(".fe-slide");
const dots = document.querySelectorAll(".fe-dots span");
let current = 0;
let slideInterval; // store the interval for control

function showSlide(index) {
  slides.forEach((slide, i) => {
    slide.classList.toggle("active", i === index);
    dots[i].classList.toggle("active", i === index);
  });
}

function nextSlide() {
  current = (current + 1) % slides.length;
  showSlide(current);
}

function startSlider() {
  slideInterval = setInterval(nextSlide, 2000); // every 3 seconds
}

function stopSlider() {
  clearInterval(slideInterval);
}

startSlider(); // start automatically


// ===== IMAGE POPUP =====
const modal = document.getElementById("feModal");
const modalImg = document.getElementById("feModalImg");
const closeBtn = document.querySelector(".fe-close");

// -- Small image popup --
document.querySelectorAll(".fe-image img").forEach(img => {
  img.addEventListener("click", e => {
    e.preventDefault();
    stopSlider(); // pause slider
    modal.style.display = "block";
    modalImg.src = img.src;
  });
});

// -- Slider image popup --
slides.forEach((slide) => {
  slide.addEventListener("click", () => {
    const bgImage = slide.style.backgroundImage;
    const imageUrl = bgImage.slice(5, -2); // remove url("...")

    stopSlider(); // pause slider while modal open
    modal.style.display = "block";
    modalImg.src = imageUrl;
  });
});

// -- Close modal logic --
closeBtn.addEventListener("click", () => {
  modal.style.display = "none";
  startSlider(); // resume slider
});

window.addEventListener("click", e => {
  if (e.target === modal) {
    modal.style.display = "none";
    startSlider(); // resume slider
  }
});


</script>

</body>
</html>

