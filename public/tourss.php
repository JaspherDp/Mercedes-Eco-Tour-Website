
<?php
chdir(__DIR__ . '/..');
session_start();
require 'php/db_connection.php';

function resolveImage($filename) {
    // Fallback if empty or null
    if (!$filename || trim($filename) === "") {
        return "img/sampleimage.png";
    }

    // Clean the filename
    $file = basename($filename);

    // Check inside php/upload
    if (file_exists(__DIR__ . "/../php/upload/" . $file)) {
        return "php/upload/" . $file;
    }

    // Check inside upload or uploads (whichever your system uses)
    if (file_exists(__DIR__ . "/../upload/" . $file)) {
        return "upload/" . $file;
    }
    if (file_exists(__DIR__ . "/../uploads/" . $file)) {
        return "uploads/" . $file;
    }

    // Check inside img folder
    if (file_exists(__DIR__ . "/../img/" . $file)) {
        return "img/" . $file;
    }

    // Final fallback
    return "img/sampleimage.png";
}


$user = null;
if (isset($_SESSION['tourist_id'])) {
    $stmt = $pdo->prepare("SELECT * FROM tourist WHERE tourist_id = ?");
    $stmt->execute([$_SESSION['tourist_id']]);
    $tourist = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($tourist) {
        // Try to split full_name into first + last
        $nameParts = explode(" ", $tourist['full_name'], 2);
        $firstName = $nameParts[0] ?? '';
        $lastName  = $nameParts[1] ?? '';

        $user = [
            "id"       => $tourist['tourist_id'],
            "first_name" => $firstName,
            "last_name"  => $lastName,
            "email"    => $tourist['email'],
            "phone"    => $tourist['phone'] ?? "",   // optional column
            "profile_picture" => $tourist['profile_picture'] ?? null
        ];
    }
}
$stmt = $pdo->prepare("
    SELECT 
        p.package_id, 
        p.package_title, 
        p.package_type,
        p.package_range,
        p.package_image AS package_image1,
        p.package_image2,
        p.package_image3,
        p.package_image4,
        p.price, 
        p.operator_id, 
        o.fullname,
        o.status AS operator_status
    FROM tour_packages p
    JOIN operators o ON o.operator_id = p.operator_id
    WHERE o.status = 'active'   -- <-- only active operators
    ORDER BY p.package_id ASC
");
$stmt->execute();
$packages = $stmt->fetchAll(PDO::FETCH_ASSOC);


// Fetch average rating and total reviews for each package
$ratings = [];
foreach ($packages as $pkg) {
    $stmt = $pdo->prepare("SELECT AVG(rating) as avg_rating, COUNT(*) as total_reviews 
                           FROM feedback WHERE package_id = ?");
    $stmt->execute([$pkg['package_id']]);
    $ratingData = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $ratings[$pkg['package_id']] = [
        'avg' => round($ratingData['avg_rating'], 1), // round to 1 decimal
        'total' => (int)$ratingData['total_reviews']
    ];
}

// Fetch all boats
$stmt = $pdo->prepare("SELECT * FROM boats ORDER BY boat_id ASC");
$stmt->execute();
$boats = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch service prices (boat & tour guide)
$stmt = $pdo->prepare("
    SELECT service_type, day_tour_price, overnight_price
    FROM service_prices
    WHERE is_active = 1
");
$stmt->execute();
$servicePricesRaw = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Reformat for easy JS usage
$servicePrices = [];
foreach ($servicePricesRaw as $row) {
    $servicePrices[$row['service_type']] = [
        'day' => $row['day_tour_price'],
        'overnight' => $row['overnight_price']
    ];
}

?>

<html lang="en">
<head>
  <meta charset="utf-8"/>
  <meta content="width=device-width, initial-scale=1" name="viewport"/>
  <title>iTour Mercedes</title>
  <link rel="icon" type="image/png" href="img/newlogo.png">
  <link href="https://fonts.googleapis.com/css2?family=Roboto&display=swap" rel="stylesheet"/>
  <link rel="stylesheet" href="styles/tourss.css">
  <link rel="stylesheet" href="styles/homepage.css">
</head>

<br><br>
<br><br>

<body>
    <!-- Placeholder for Header (includes nav inside header.html) -->
    <div id="header"></div>

    <!-- Placeholder for Login/Signup Modal -->
  <div id="loginModal"></div>

<div class="second-navbar-wrapper">
    <div class="second-navbar">
        <div class="wrapper second-navbar-layout">
            <div class="second-nav-left">
                <button class="second-nav-btn" data-target="tour-packages">TOUR PACKAGES</button>
                <button class="second-nav-btn" data-target="tour-guides">OUR TOUR GUIDES</button>
                <button class="second-nav-btn" data-target="our-boats">OUR BOATS</button>
                <a href="tour_booking.php?return=tourss.php" class="book-now-btn top-book-btn">BOOK NOW!</a>
            </div>

            <div class="second-nav-right">
              <button type="button"
                      class="other-fees-btn"
                      onclick="openOtherFeesModal()">
                Other Fees
              </button>
                <form class="second-search-form">
                    <input type="text" placeholder="Search...">
                    <button type="submit">Search</button>
                </form>
            </div>
        </div>
    </div>
</div>


<!-- ===== CONTENT AREA BELOW SECOND NAVBAR ===== -->
<div class="second-content">

<!-- DEFAULT: Tour Packages -->
<div class="content-section" id="tour-packages" style="display: block;">
  <div class="packages-container">
    <?php foreach($packages as $pkg): ?>
      <?php 
        // Use fallback images
        $images = [
            resolveImage($pkg['package_image1']),
            resolveImage($pkg['package_image2']),
            resolveImage($pkg['package_image3']),
            resolveImage($pkg['package_image4']),
        ];

        $location = "Mercedes, Camarines Norte";
      ?>

      <div class="package-card">

            <div class="package-carousel" data-images='<?php echo json_encode($images); ?>'>
              <button class="prev"><img src="img/prevchevron.png" alt="Previous"></button>
              <img class="carousel-main" src="<?php echo $images[0]; ?>" alt="<?php echo htmlspecialchars($pkg['package_title']); ?>">
              <button class="next"><img src="img/nextchevron.png" alt="Next"></button>
              <div class="carousel-dots">
                <?php foreach($images as $i => $img): ?>
                  <span class="dot <?php echo $i === 0 ? 'active' : ''; ?>"></span>
                <?php endforeach; ?>
              </div>
            </div>
          <!-- Entire card clickable except the button -->
          <a href="package_details.php?package_id=<?php echo $pkg['package_id']; ?>" class="package-link">
            <p class="package-location"><?php echo htmlspecialchars($location); ?></p>

            <div class="package-info">
              <div class="package-title-row">
                <h3 class="package-title">
                  <?php echo htmlspecialchars($pkg['package_title']); ?>
                </h3>

                <span class="package-type-badge">
                  <?php echo ucfirst(str_replace('-', ' ', $pkg['package_type'])); ?>
                </span>
              </div>
              <?php if (!empty($pkg['package_range'])): ?>
                <p class="package-range">
                  <?php echo htmlspecialchars($pkg['package_range']); ?>
                </p>
              <?php endif; ?>
              <p class="operator">Operator: <?php echo htmlspecialchars($pkg['fullname']); ?></p>
              <div class="package-rating">
                <?php 
                  $avg = $ratings[$pkg['package_id']]['avg'] ?? 0;
                  $totalReviews = $ratings[$pkg['package_id']]['total'] ?? 0;
                  for($i=1; $i<=5; $i++): 
                    $starClass = $i <= floor($avg) ? 'filled' : ($i - $avg < 1 ? 'half' : '');
                ?>
                  <span class="star <?php echo $starClass; ?>">★</span>
                <?php endfor; ?>
                <span class="rating-text"><?php echo $avg; ?> (<?php echo $totalReviews; ?>)</span>
              </div>
            </div>
          </a>

          <!-- Book Now button outside the <a> tag -->
          <div class="price-book">
            <p class="price"><strong>₱ <?php echo number_format($pkg['price'],0); ?> / pax</strong></p>
            <a
              class="book-now-btn"
              href="tour_booking.php?booking_type=package&amp;package_id=<?php echo (int)$pkg['package_id']; ?>&amp;return=tourss.php"
            >Book Now</a>
          </div>
        </div>

    <?php endforeach; ?>
  </div>
</div>

<div class="content-section" id="tour-guides" style="display: none;">
    <div class="tour-guides-grid">
        <?php
        // Fetch tour guides from database
        $stmt = $pdo->prepare("SELECT * FROM tour_guides ORDER BY guide_id ASC");
        $stmt->execute();
        $guides = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if ($guides):
            foreach($guides as $guide): ?>
                <div class="guide-card">
                    <div class="guide-left">
                        <img src="<?php echo resolveImage($guide['profile_picture']); ?>"
                            alt="<?php echo htmlspecialchars($guide['fullname']); ?>">
                    </div>
                    <div class="guide-right">
                        <h3><?php echo htmlspecialchars($guide['fullname']); ?></h3>
                        <p class="guide-description"><?php echo htmlspecialchars($guide['short_description']); ?></p>
                        <div class="guide-meta">
                            <div class="meta-item">
                                <!-- Age icon -->
                                <svg class="meta-icon" viewBox="0 0 24 24" width="18" height="18">
                                    <path d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z" />
                                </svg>
                                <span><?php echo htmlspecialchars($guide['age']); ?> yrs</span>
                            </div>
                            <div class="meta-item">
                                <!-- Experience icon -->
                                <svg class="meta-icon" viewBox="0 0 24 24" width="18" height="18">
                                    <path d="M12 2L2 7v13h20V7L12 2zm0 2.18L18 7v11H6V7l6-2.82zM11 12h2v6h-2v-6z"/>
                                </svg>
                                <span><?php echo htmlspecialchars($guide['experience']); ?> yrs exp</span>
                            </div>
                        </div>
                        <a
                          class="book-now-btn guide-book-btn"
                          href="tour_booking.php?booking_type=tourguide&amp;preferred=<?php echo rawurlencode((string)$guide['fullname']); ?>&amp;return=tourss.php"
                        >Book Tour Guide</a>
                    </div>
                </div>
            <?php endforeach;
        else: ?>
            <p>No tour guides found.</p>
        <?php endif; ?>
    </div>
</div>


<div class="content-section" id="our-boats" style="display: none;">
  <div class="boats-wrapper">
    <!-- LEFT: list of horizontal boat cards -->
    <div class="boats-list" id="boatsList">
      <?php foreach($boats as $b): 
          // ensure images exist or fallback
          $imgs = [
            !empty($b['image1']) ? $b['image1'] : 'img/sampleimage.png',
            !empty($b['image2']) ? $b['image2'] : 'img/sampleimage.png',
            !empty($b['image3']) ? $b['image3'] : 'img/sampleimage.png',
            !empty($b['image4']) ? $b['image4'] : 'img/sampleimage.png',
            !empty($b['image5']) ? $b['image5'] : 'img/sampleimage.png',
          ];

      ?>
      <div class="boat-card" data-boat='<?php echo json_encode([
            "boat_id"=>$b['boat_id'],
            "name"=>$b['name'],
            "total_pax"=>$b['total_pax'],
            "size"=>$b['size'],
            "boat_number"=>$b['boat_number'],
            "short_description"=>$b['short_description'],
            "long_description"=>$b['long_description'],
            "images"=>$imgs
          ]); ?>'>
        <div class="boat-card-left">
          <img src="<?php echo htmlspecialchars($imgs[0]); ?>" alt="<?php echo htmlspecialchars($b['name']); ?>">
        </div>
        <div class="boat-card-right">
          <h3 class="boat-title"><?php echo htmlspecialchars($b['name']); ?></h3>
          <p class="boat-short"><?php echo htmlspecialchars($b['short_description']); ?></p>
          <div class="boat-meta">
            <div class="meta-item">
              <!-- person icon -->
              <svg class="meta-icon" viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><path d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z" /></svg>
              <span class="meta-text"><?php echo htmlspecialchars($b['total_pax']); ?> pax</span>
            </div>

            <div class="meta-item">
              <!-- ruler icon -->
              <svg class="meta-icon" viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><path d="M3 21h18v-2H3v2zm3-6V7h2v8H6zm4 0V3h2v12h-2zm4 0V9h2v6h-2z"/></svg>
              <span class="meta-text"><?php echo htmlspecialchars($b['size']); ?></span>
            </div>

            <div class="meta-item">
              <!-- tag/hash icon -->
              <svg class="meta-icon" viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><path d="M10 3L3 10v11h18V3H10zM9 5h8v6H9V5zm-3 6H5v9h1v-9zm13 9h-9v-6h9v6z"/></svg>
              <span class="meta-text"><?php echo htmlspecialchars($b['boat_number']); ?></span>
            </div>
          </div>
        </div>
      </div>
      <?php endforeach;?>
    </div>

    <!-- RIGHT: detail/info panel -->
    <div class="boat-detail" id="boatDetail">
            <div class="detail-slider" id="detailSlider">
    <button class="slider-btn prev" id="sliderPrev" aria-label="Previous image">
        <img src="img/prevchevron.png" alt="Previous">
    </button>

    <div class="slider-viewport">
        <!-- images injected by JS -->
    </div>

    <button class="slider-btn next" id="sliderNext" aria-label="Next image">
        <img src="img/nextchevron.png" alt="Next">
    </button>

    <div class="slider-dots" id="sliderDots"></div>
</div>

      <div class="detail-text">
        <h2 id="detailName">Select a boat</h2>
        <p id="detailShort" class="detail-short">Click a boat on the left to see details.</p>
        <p id="detailLong" class="detail-long"></p>
        <div class="detail-meta">
          <div class="detail-meta-item">
            <span class="detail-label">Total pax</span>
            <span class="detail-value" id="detailPax">—</span>
            <!-- icon on right -->
            <svg class="detail-icon" viewBox="0 0 24 24" width="18" height="18"><path d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z" /></svg>
          </div>
          <div class="detail-meta-item">
            <span class="detail-label">Size</span>
            <span class="detail-value" id="detailSize">—</span>
            <svg class="detail-icon" viewBox="0 0 24 24" width="18" height="18"><path d="M3 21h18v-2H3v2zm3-6V7h2v8H6zm4 0V3h2v12h-2zm4 0V9h2v6h-2z"/></svg>
          </div>
          <div class="detail-meta-item">
            <span class="detail-label">Boat #</span>
            <span class="detail-value" id="detailNumber">—</span>
            <svg class="detail-icon" viewBox="0 0 24 24" width="18" height="18"><path d="M10 3L3 10v11h18V3H10zM9 5h8v6H9V5zm-3 6H5v9h1v-9zm13 9h-9v-6h9v6z"/></svg>
          </div>
        </div>
        <a id="detailBookBoatBtn" class="book-now-btn boat-book-btn" href="tour_booking.php?booking_type=boat&amp;return=tourss.php">Book This Boat</a>
      </div>
    </div>
  </div>

  <!-- small helper when no boats -->
  <?php if(empty($boats)): ?>
    <p class="no-boats">No boats found.</p>
  <?php endif; ?>
</div>
</div>

<div id="otherFeesModal_ITOUR" class="modal-overlay">
  <div class="modal-card">

    <div class="modal-header">
      <h2>Other Applicable Fees</h2>
      <button class="modal-close" onclick="closeOtherFeesModal()">×</button>
    </div>

    <div class="modal-body">

      <!-- ECO TOURISM -->
      <section class="fee-section">
        <h3>ECO – TOURISM DEVELOPMENT FEE</h3>
        <ul>
          <li>Foreigner <strong>₱100.00</strong></li>
          <li>Local <strong>₱50.00</strong></li>
          <li>0 – 12 years old <strong>FREE</strong></li>
          <li>Mercedeños <strong>50% Discount</strong></li>
          <li>Senior Citizens <strong>20% Discount</strong></li>
        </ul>
      </section>

      <!-- ENTRANCE -->
      <section class="fee-section">
        <h3>ENTRANCE FEE <span>(Per Head)</span></h3>
        <ul>
          <li>Apuao Grande <strong>₱30.00</strong></li>
          <li>Caringo Island <strong>₱20.00</strong></li>
          <li>Canimog Island (Day Tour) <strong>₱100.00</strong></li>
          <li>Canimog Island (Night Tour) <strong>₱250.00</strong></li>
        </ul>
      </section>

      <!-- DOCKING -->
      <section class="fee-section">
        <h3>DOCKING / LANDING FEE <span>(Per Boat)</span></h3>
        <ul>
          <li>Apuao Pequeña <strong>₱500.00</strong></li>
          <li>Malasugui Island <strong>₱300.00</strong></li>
          <li>Canimog Infinity Pool <strong>₱500.00</strong></li>
        </ul>
      </section>

      <!-- EQUIPMENT -->
      <section class="fee-section">
        <h3>EQUIPMENTS FOR RENT</h3>
        <ul>
          <li>Snorkeling Set (per day) <strong>₱200.00</strong></li>
          <li>Kayak (per day) <strong>₱500.00</strong></li>
        </ul>
      </section>

    </div>

  </div>
</div>

<?php include 'footer.php'; ?>
<script src="js/header.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>

<script>

document.addEventListener("DOMContentLoaded", () => {

  
  /* ==========================================================
      1. LOAD HEADER FIRST
  ========================================================== */
  fetch("php/header.php")
    .then(res => res.text())
    .then(html => {
      const headerEl = document.getElementById("header");
      if (!headerEl) return console.error("Header container not found");
      headerEl.innerHTML = html;

      // -----------------------------
      // 2. Initialize header scripts
      // -----------------------------
      if (typeof initHeader === "function") initHeader();
      initSecondNavbarSticky();

      // Highlight current nav link
      const current = location.pathname.split("/").pop();
      document.querySelectorAll("#header nav ul li a").forEach(link => {
        link.classList.toggle("active", link.getAttribute("href") === current);
      });

      // Scroll To Top button
      const scrollToTopBtn = document.getElementById("scroll-to-top-btn");
      if (scrollToTopBtn) {
        window.addEventListener("scroll", () => {
          scrollToTopBtn.style.display = window.scrollY > 200 ? "flex" : "none";
        });
        scrollToTopBtn.addEventListener("click", () =>
          window.scrollTo({ top: 0, behavior: "smooth" })
        );
      }

      // Mobile nav toggle
      const menuToggle = document.querySelector("#header .menu-toggle");
      const navUl = document.querySelector("#header nav ul");
      menuToggle?.addEventListener("click", () => navUl?.classList.toggle("show"));

      // -----------------------------
      // 3. Initialize header-dependent features
      // -----------------------------
      initSecondNavbarTabs();

      // ✅ APPLY TAB FROM URL (FIXED)
      const params = new URLSearchParams(window.location.search);
      const tabFromURL = params.get("tab");

      if (tabFromURL) {
        const btn = document.querySelector(`.second-nav-btn[data-target="${tabFromURL}"]`);
        if (btn) {
          btn.click();
        }
      }
      initPackageCarousels();

      // ===== Load login/signup modal dynamically =====
      const modalContainer = document.getElementById("loginModal");
      if (!modalContainer) return console.error("loginModal container not found");

      fetch("logsign-modal.html")
        .then(res => res.text())
        .then(modalHtml => {
          modalContainer.innerHTML = modalHtml;

          // Load SweetAlert2 only once
          if (!window.Swal) {
            const swalScript = document.createElement("script");
            swalScript.src = "https://cdn.jsdelivr.net/npm/sweetalert2@11";
            swalScript.onload = loadLogSign;
            document.body.appendChild(swalScript);
          } else {
            loadLogSign();
          }

          function loadLogSign() {
            const logsignScript = document.createElement("script");
            logsignScript.src = "logsign.js";
            logsignScript.onload = () => {
              if (typeof initLogSignEvents === "function") initLogSignEvents();
              else console.error("initLogSignEvents not found in logsign.js");
            };
            document.body.appendChild(logsignScript);
          }
        })
        .catch(err => console.error("Modal load error:", err));
    })
    .catch(err => console.error("Header load error:", err));

});

/* ==========================================================
  PACKAGE CAROUSELS
========================================================== */
function initPackageCarousels() {
  document.querySelectorAll(".package-carousel").forEach(carousel => {
    const images = JSON.parse(carousel.dataset.images || "[]");
    const main = carousel.querySelector(".carousel-main");
    const dots = carousel.querySelectorAll(".dot");
    let i = 0;

    const update = () => {
      main.src = images[i];
      dots.forEach((d, idx) => d.classList.toggle("active", idx === i));
    };

    carousel.querySelector(".next")?.addEventListener("click", () => {
      i = (i + 1) % images.length; update();
    });

    carousel.querySelector(".prev")?.addEventListener("click", () => {
      i = (i - 1 + images.length) % images.length; update();
    });

    dots.forEach((d, idx) =>
      d.addEventListener("click", () => { i = idx; update(); })
    );
  });
}

/* ==========================================================
  FUNCTIONS START HERE (OUTSIDE DOMContentLoaded)
========================================================== */

/* ------------------ NAV HIGHLIGHT ------------------ */
function initNavHighlight() {
  const current = location.pathname.split("/").pop();
  document.querySelectorAll("#header nav ul li a").forEach(link => {
    if (link.getAttribute("href") === current) link.classList.add("active");
  });
}

/* ------------------ SCROLL TO TOP ------------------ */
function initScrollToTop() {
  const btn = document.getElementById("scroll-to-top-btn");
  if (!btn) return;

  window.addEventListener("scroll", () => {
    btn.style.display = window.scrollY > 200 ? "flex" : "none";
  });

  btn.addEventListener("click", () =>
    window.scrollTo({ top: 0, behavior: "smooth" })
  );
}

/* ------------------ MOBILE NAV ------------------ */
function initMobileToggle() {
  const toggle = document.querySelector('#header .menu-toggle');
  const navLinks = document.querySelector('#header nav ul');
  if (!toggle || !navLinks) return;

  toggle.addEventListener('click', () => {
    navLinks.classList.toggle('show');
  });
}

/* ------------------ HOMEPAGE NAV EFFECT ------------------ */
function initHomepageScrollEffect() {
  if (!document.body.classList.contains("homepage")) return;

  const nav = document.querySelector("#header nav");
  if (!nav) return;

  function checkNavScroll() {
    nav.classList.toggle("scrolled", window.scrollY > 50);
  }

  window.addEventListener("scroll", checkNavScroll);
  checkNavScroll();
}

/* ------------------ LOGIN MODAL LOADING ------------------ */
function loadLoginModal() {
  fetch("logsign-modal.html")
    .then(res => res.text())
    .then(html => {
      document.getElementById("loginModal").innerHTML = html;

      const script = document.createElement("script");
      script.src = "logsign.js";
      script.onload = () => {
        if (typeof initLogSignEvents === "function") initLogSignEvents();
      };
      document.body.appendChild(script);
    })
    .catch(err => console.error("Modal load error:", err));
}



/* ==========================================================
  SECOND NAVBAR TABS
========================================================== */

const SERVICE_PRICES = <?php echo json_encode($servicePrices); ?>;

function initSecondNavbarTabs() {
  const tabButtons = document.querySelectorAll(".second-nav-btn");
  const sections = document.querySelectorAll(".content-section");
  const priceBox = document.getElementById("navPriceDisplay");

  tabButtons.forEach(btn => {
    btn.addEventListener("click", () => {

      // Activate tab
      tabButtons.forEach(b => b.classList.remove("active"));
      btn.classList.add("active");

      // Show section
      sections.forEach(sec => sec.style.display = "none");
      document.getElementById(btn.dataset.target).style.display = "block";

      // 🔥 Update price display
      updateNavbarPrice(btn.dataset.target, priceBox);
    });
  });

  tabButtons[0]?.click(); // default
}

function updateNavbarPrice(target, priceBox) {
  priceBox.innerHTML = ""; // reset

  if (target === "tour-guides" && SERVICE_PRICES.tourguide) {
    priceBox.innerHTML = `
      <span>Tour Guide:</span>
      <span>Day Tour ₱${Number(SERVICE_PRICES.tourguide.day).toLocaleString()}</span>
      <span>|</span>
      <span>Overnight (2 Days 1 Night) ₱${Number(SERVICE_PRICES.tourguide.overnight).toLocaleString()}</span>
    `;
  }

  else if (target === "our-boats" && SERVICE_PRICES.boat) {
    priceBox.innerHTML = `
      <span>Boat Rental:</span>
      <span>Day Tour ₱${Number(SERVICE_PRICES.boat.day).toLocaleString()}</span>
      <span>|</span>
      <span>Overnight (2 Days 1 Night) ₱${Number(SERVICE_PRICES.boat.overnight).toLocaleString()}</span>
    `;
  }

  // TOUR PACKAGES → BLANK (do nothing)
}


/* ==========================================================
  STICKY SECOND NAVBAR
========================================================== */
function initSecondNavbarSticky() {
  const navbar = document.querySelector(".second-navbar");
  const wrapper = document.querySelector(".second-navbar-wrapper");

  if (!navbar || !wrapper) return;

  const navbarOffsetTop = wrapper.offsetTop;

  function checkSticky() {
    if (window.scrollY >= navbarOffsetTop) {
      navbar.classList.add("fixed");
      wrapper.style.height = navbar.offsetHeight + "px";
    } else {
      navbar.classList.remove("fixed");
      wrapper.style.height = "auto";
    }
  }

  window.addEventListener("scroll", checkSticky);

  // ✅ Run once immediately on load
  checkSticky();
}



/* ==========================================================
  CAROUSELS
========================================================== */
function initCarousels() {
  const carousels = document.querySelectorAll(".package-carousel");

  carousels.forEach(carousel => {
    const images = JSON.parse(carousel.dataset.images);
    let index = 0;

    const imgEl = carousel.querySelector(".carousel-main");
    const prevBtn = carousel.querySelector(".prev");
    const nextBtn = carousel.querySelector(".next");
    const dots = carousel.querySelectorAll(".dot");

    function update() {
      imgEl.src = images[index];
      dots.forEach((d, i) => d.classList.toggle("active", i === index));
    }

    prevBtn.addEventListener("click", () => {
      index = (index - 1 + images.length) % images.length;
      update();
    });

    nextBtn.addEventListener("click", () => {
      index = (index + 1) % images.length;
      update();
    });

    dots.forEach((dot, i) =>
      dot.addEventListener("click", () => {
        index = i;
        update();
      })
    );

    update();
  });
}



/* ==========================================================
  BOAT SLIDER MODULE  (kept the same)
========================================================== */
(function () {

  const boatsList = document.getElementById('boatsList');
  const detailName = document.getElementById('detailName');
  const detailShort = document.getElementById('detailShort');
  const detailLong = document.getElementById('detailLong');
  const detailPax = document.getElementById('detailPax');
  const detailSize = document.getElementById('detailSize');
  const detailNumber = document.getElementById('detailNumber');
  const detailBookBoatBtn = document.getElementById('detailBookBoatBtn');
  const sliderEl = document.getElementById('detailSlider');
  const sliderViewport = sliderEl.querySelector('.slider-viewport');
  const sliderDots = document.getElementById('sliderDots');
  const sliderPrev = document.getElementById('sliderPrev');
  const sliderNext = document.getElementById('sliderNext');

  let currentBoat = null;
  let currentIndex = 0;
  let autoTimer = null;
  const AUTO_DELAY = 3000;

  function clearAuto() {
    if (autoTimer) clearInterval(autoTimer);
    autoTimer = null;
  }

  function startAuto() {
    clearAuto();
    autoTimer = setInterval(() => nextSlide(), AUTO_DELAY);
  }

  function selectBoatElement(cardEl) {
    const data = JSON.parse(cardEl.dataset.boat);

    document.querySelectorAll('.boat-card').forEach(c => c.classList.remove('active'));
    cardEl.classList.add('active');

    populateDetail(data);
  }

  function populateDetail(data) {
    currentBoat = data;

    detailName.textContent = data.name || '—';
    detailShort.textContent = data.short_description || '';
    detailLong.textContent = data.long_description || '';
    detailPax.textContent = data.total_pax ? `${data.total_pax} pax` : '—';
    detailSize.textContent = data.size || '—';
    detailNumber.textContent = data.boat_number || '—';
    if (detailBookBoatBtn) {
      const preferred = encodeURIComponent(data.name || '');
      detailBookBoatBtn.href = `tour_booking.php?booking_type=boat&preferred=${preferred}&return=tourss.php`;
    }

    sliderViewport.innerHTML = '';
    sliderDots.innerHTML = '';

    const imgs = Array.isArray(data.images) ? data.images : ['img/sampleimage.png'];

    imgs.forEach((src, i) => {
      const img = document.createElement('img');
      img.src = src;
      img.alt = data.name + ' ' + (i + 1);
      sliderViewport.appendChild(img);

      const dot = document.createElement('div');
      dot.className = 'dot' + (i === 0 ? ' active' : '');
      dot.addEventListener('click', () => goToSlide(i));
      sliderDots.appendChild(dot);
    });

    currentIndex = 0;
    updateSliderPosition();
    startAuto();
  }

  function updateSliderPosition() {
    sliderViewport.style.transform = `translateX(${-currentIndex * 100}%)`;

    Array.from(sliderDots.children).forEach((dot, i) =>
      dot.classList.toggle('active', i === currentIndex)
    );
  }

  function goToSlide(i) {
    const len = sliderViewport.children.length;
    currentIndex = (i + len) % len;
    updateSliderPosition();
    startAuto();
  }

  function nextSlide() {
    goToSlide(currentIndex + 1);
  }

  function prevSlide() {
    goToSlide(currentIndex - 1);
  }

  document.querySelectorAll('.boat-card').forEach(card =>
    card.addEventListener('click', () => selectBoatElement(card))
  );

  sliderNext.addEventListener('click', () => { nextSlide(); startAuto(); });
  sliderPrev.addEventListener('click', () => { prevSlide(); startAuto(); });

  window.addEventListener('resize', updateSliderPosition);

  sliderEl.addEventListener("mouseenter", clearAuto);
  sliderEl.addEventListener("mouseleave", startAuto);

  const firstCard = document.querySelector('.boat-card');
  if (firstCard) {
    selectBoatElement(firstCard);
    firstCard.scrollIntoView({ behavior: "smooth", block: "center" });
  }

})();

/* ==========================================================
    UNIVERSAL SEARCH FIX (Packages, Guides, Boats)
========================================================== */
document.addEventListener("DOMContentLoaded", () => {
    const searchInput = document.querySelector(".second-search-form input");
    const searchForm  = document.querySelector(".second-search-form");

    if (!searchInput || !searchForm) return;

    // Prevent form refresh
    searchForm.addEventListener("submit", (e) => {
        e.preventDefault();
        applySearchFilter(searchInput.value.trim().toLowerCase());
    });

    // Live search as typing
    searchInput.addEventListener("keyup", () => {
        applySearchFilter(searchInput.value.trim().toLowerCase());
    });

    function applySearchFilter(keyword) {
        // -------------------------------
        // FILTER TOUR PACKAGES
        // -------------------------------
        document.querySelectorAll("#tour-packages .package-card")
            .forEach(card => {
                const title = card.querySelector("h3")?.textContent.toLowerCase() || "";
                const operator = card.querySelector(".operator")?.textContent.toLowerCase() || "";
                
                card.style.display =
                    title.includes(keyword) ||
                    operator.includes(keyword)
                    ? "block"
                    : "none";
            });

        // -------------------------------
        // FILTER TOUR GUIDES
        // -------------------------------
        document.querySelectorAll("#tour-guides .guide-card")
            .forEach(card => {
                const name = card.querySelector("h3")?.textContent.toLowerCase() || "";
                const desc = card.querySelector(".guide-description")?.textContent.toLowerCase() || "";

                card.style.display =
                    name.includes(keyword) ||
                    desc.includes(keyword)
                    ? "flex"
                    : "none";
            });

        // -------------------------------
        // FILTER BOATS
        // -------------------------------
        document.querySelectorAll("#our-boats .boat-card")
            .forEach(card => {
                const boatData = JSON.parse(card.getAttribute("data-boat"));
                const name = boatData.name.toLowerCase();
                const number = boatData.boat_number.toLowerCase();
                const desc = boatData.short_description.toLowerCase();

                card.style.display =
                    name.includes(keyword) ||
                    number.includes(keyword) ||
                    desc.includes(keyword)
                    ? "flex"
                    : "none";
            });
    }
});
</script>

</body>
</html>
