<?php
chdir(__DIR__ . '/..');
if (session_status() === PHP_SESSION_NONE) {
require_once __DIR__ . '/../php/session_security.php';
AppSessionStart();
}
require_once 'php/db_connection.php';
require_once 'php/destination_repository.php';
$schemaTables = $pdo->query("
    SELECT COUNT(*)
    FROM information_schema.tables
    WHERE table_schema = DATABASE()
      AND table_name IN ('destination_settings', 'destinations', 'destination_gallery')
")->fetchColumn();
if ((int)$schemaTables < 3) {
    destinationEnsureSchema($pdo);
    destinationSeedDefaults($pdo);
}

$publicDestinations = [];
$galleryByDestination = [];
$galleryRows = $pdo->query('SELECT destination_id, image_path FROM destination_gallery ORDER BY destination_id, sort_order, gallery_id')->fetchAll(PDO::FETCH_ASSOC);
foreach ($galleryRows as $photo) {
    $galleryByDestination[(int)$photo['destination_id']][] = (string)$photo['image_path'];
}
foreach (destinationRows($pdo, true) as $destination) {
    $gallery = $galleryByDestination[(int)$destination['destination_id']] ?? [];
    $publicDestinations[(string)$destination['slug']] = [
        'title' => (string)$destination['title'],
        'name' => (string)$destination['tagline'],
        'type' => (string)$destination['destination_type'],
        'location' => (string)$destination['location'],
        'image' => (string)$destination['hero_image'],
        'heroImage' => (string)$destination['hero_image'],
        'cardImage' => (string)$destination['card_image'],
        'description' => (string)$destination['description'],
        'coords' => $destination['latitude'] !== null && $destination['longitude'] !== null
            ? ['lat' => (float)$destination['latitude'], 'lng' => (float)$destination['longitude']]
            : null,
        'activities' => json_decode((string)$destination['activities'], true) ?: [],
        'gallery' => $gallery,
        'resorts' => [],
    ];
}
?>

<!DOCTYPE html>
<html lang="en">
  <head>
    <meta charset="utf-8" />
    <meta content="width=device-width, initial-scale=1" name="viewport" />
    <meta name="description" content="Explore beautiful islands, beaches, and tourist destinations in Mercedes, Camarines Norte. Discover places to visit and plan your next adventure." />
    <link rel="canonical" href="https://itourmercedes.com/destination.php" />
    <title>Tourist Destinations in Mercedes, Camarines Norte | iTour Mercedes</title>
    <link rel="icon" type="image/png" href="img/newlogo.png" />
    <link rel="preconnect" href="https://fonts.googleapis.com" />
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
    <link rel="preconnect" href="https://unpkg.com" crossorigin />
    <link
      href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap"
      rel="stylesheet"
    />
    <link rel="stylesheet" href="styles/homepage.css?v=<?= (int)@filemtime(__DIR__ . '/../styles/homepage.css') ?>" />
    <link rel="stylesheet" href="styles/style.css" />
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="anonymous" />
    <link rel="stylesheet" href="styles/destination.css?v=<?= (int)@filemtime(__DIR__ . '/../styles/destination.css') ?>" />
    <link rel="stylesheet" href="styles/back-to-top.css?v=<?= (int)@filemtime(__DIR__ . '/../styles/back-to-top.css') ?>" />
    <script>document.documentElement.classList.add('itour-page-loading');</script>
    <link rel="stylesheet" href="styles/page-loader.css?v=<?= (int)@filemtime(__DIR__ . '/../styles/page-loader.css') ?>" />
    <script src="js/page-loader.js?v=<?= (int)@filemtime(__DIR__ . '/../js/page-loader.js') ?>"></script>
  </head>
  
<body class="destination-page">
<?php include __DIR__ . '/../includes/page_loader.php'; ?>

<!-- Header -->
  <div id="header"></div>

  <!-- Login/Signup Modal -->
  <div id="loginModal"></div>

  <section class="des_page-intro">
    <div class="des_intro-inner">
      <div class="des_intro-content">
        <p class="des_intro-eyebrow">Discover Mercedes, Camarines Norte</p>
        <h1 class="des_intro-title">Find a place that feels <span>far from ordinary.</span></h1>

        <form class="des_destination-search" id="destinationSearchForm" role="search">
          <label for="destinationSearch" class="sr-only">Search destinations</label>
          <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M10.5 3a7.5 7.5 0 1 0 4.67 13.37L19.8 21 21 19.8l-4.63-4.63A7.5 7.5 0 0 0 10.5 3Zm0 2a5.5 5.5 0 1 1 0 11 5.5 5.5 0 0 1 0-11Z"/></svg>
          <input id="destinationSearch" type="search" placeholder="Search an island or landmark..." autocomplete="off" role="combobox" aria-autocomplete="list" aria-expanded="false" aria-controls="destinationSearchSuggestions">
          <button type="submit"><span class="des_search-label-full">Find a place</span><span class="des_search-label-mobile">Find</span></button>
          <div class="hero-search-suggestions des_search-suggestions" id="destinationSearchSuggestions" role="listbox" aria-label="Destination suggestions" hidden></div>
        </form>

        <div class="des_intro-highlights" aria-label="Destination highlights">
          <span><strong><?= count($publicDestinations) ?></strong> places to explore</span>
          <i aria-hidden="true"></i>
          <span><strong>20+</strong> local activities</span>
          <i aria-hidden="true"></i>
          <span><strong>1</strong> coastal community</span>
        </div>
      </div>

      <div class="des_intro-visual" aria-hidden="true">
        <img class="des_intro-image des_intro-image--main" src="imagess/Apuao Grande.jpg" alt="">
        <img class="des_intro-image des_intro-image--small" src="imagess/Caringo.jpg" alt="">
        <div class="des_intro-visual-note"><span>Featured route</span><strong>Mercedes Island Trail</strong><small>Beaches · nature · heritage</small></div>
        <svg class="des_intro-route" viewBox="0 0 150 100"><path d="M6 87C35 83 26 42 60 47s35 34 56 14c10-9 14-25 28-42"></path></svg>
        <span class="des_intro-route-boat" aria-hidden="true">
          <svg viewBox="0 0 42 42"><path d="M7 24.5h28l-5.6 8.2H12.5L7 24.5Zm10.8-15 10.7 12H17.8v-12Zm-2.7 2.3v9.7H8.8l6.3-9.7Z"></path><path class="des_boat-wave" d="M7 35c3 2 5 2 8 0 3 2 5 2 8 0 3 2 5 2 8 0"></path></svg>
        </span>
      </div>
    </div>
  </section>

  <?php
    $destinationRibbonItems = [
      ['href' => '#featuredDestinations', 'icon' => 'img/locationicon.png', 'label' => 'Featured Island Destinations'],
      ['href' => 'hotel_resorts.php?tab=tours', 'icon' => 'img/packageshome.png', 'label' => 'Island-Hopping Packages'],
      ['href' => 'hotel_resorts.php?tab=hotels', 'icon' => 'img/hotelshome.png', 'label' => 'Hotels & Resorts'],
      ['href' => 'hotel_resorts.php?tab=guides', 'icon' => 'img/tourguidehome.png', 'label' => 'Trusted Local Guides'],
      ['href' => 'hotel_resorts.php?tab=boats', 'icon' => 'img/boathome.png', 'label' => 'Boat Rentals'],
      ['href' => 'hotel_resorts.php?tab=tours', 'icon' => 'img/bookingicon.png', 'label' => 'Plan Your Island Escape']
    ];
  ?>
  <section class="hero-service-ribbon" aria-label="Explore Mercedes travel services">
    <div class="hero-ribbon-fade hero-ribbon-fade--left" aria-hidden="true"></div>
    <div class="hero-ribbon-track">
      <?php for ($ribbonCopy = 0; $ribbonCopy < 2; $ribbonCopy++): ?>
        <div class="hero-ribbon-group" <?= $ribbonCopy === 1 ? 'aria-hidden="true"' : '' ?>>
          <?php foreach ($destinationRibbonItems as $ribbonItem): ?>
            <a href="<?= htmlspecialchars($ribbonItem['href'], ENT_QUOTES, 'UTF-8') ?>" class="hero-ribbon-item" <?= $ribbonCopy === 1 ? 'tabindex="-1"' : '' ?>>
              <span class="hero-ribbon-icon"><img src="<?= htmlspecialchars($ribbonItem['icon'], ENT_QUOTES, 'UTF-8') ?>" alt=""></span>
              <span><?= htmlspecialchars($ribbonItem['label'], ENT_QUOTES, 'UTF-8') ?></span>
              <i aria-hidden="true"></i>
            </a>
          <?php endforeach; ?>
        </div>
      <?php endfor; ?>
    </div>
    <div class="hero-ribbon-fade hero-ribbon-fade--right" aria-hidden="true"></div>
  </section>

  <div class="des_container" id="featuredDestinations">
    <div class="des_listing-header">
      <div>
        <p class="des_intro-eyebrow">Explore the coast</p>
        <h2>Featured destinations</h2>
      </div>
      <p><span id="destinationResultCount">0</span> places to discover</p>
    </div>
    <div class="des_places-grid" id="placeGridContainer"></div>
    <div class="des_empty-state" id="destinationEmptyState" hidden>
      <strong>No destinations found</strong>
      <p>Try another island, landmark, or activity name.</p>
    </div>
  </div>

  <!-- PLACE PAGE -->
  <div id="des_placePage" class="des_place-page" aria-hidden="true">
    <!-- Header Image -->
    <div class="des_place-page-header" id="placeHeader">
      <img id="des_pageHeaderImg" src="" alt="" />
      <button class="des_close-btn" type="button" aria-label="Back to all destinations" onclick="closePlacePage()">
        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m15 5-7 7 7 7"></path></svg>
        <span>Back to destinations</span>
      </button>
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
        <div class="des_editorial-intro">
          <div class="des_editorial-copy">
            <header class="des_editorial-heading">
              <span>Destination story</span>
              <h2 class="des_place-title" id="des_pageTitle"></h2>
            </header>
            <h3 class="des_section-header">About this place</h3>
            <div class="des_info-section" id="placeAbout">
              <div id="des_pageDescription"></div>
            </div>
          </div>
          <button class="des_editorial-image des_editorial-image--feature" type="button" onclick="openImageModal(0)" aria-label="Open featured destination image">
            <img id="des_aboutFeatureImage" src="" alt="">
            <span>Featured view <i aria-hidden="true">&nearr;</i></span>
          </button>
        </div>

        <div class="des_editorial-details">
          <button class="des_editorial-image des_editorial-image--support" type="button" onclick="openImageModal(1)" aria-label="Open supporting destination image">
            <img id="des_aboutSupportImage" src="" alt="">
            <span>Closer look <i aria-hidden="true">&nearr;</i></span>
          </button>

          <div class="des_editorial-info-columns">
            <div class="des_activities-section" id="placeActivitiesWrap">
              <h3>Popular experiences</h3>
              <div class="des_activities-grid" id="des_activitiesGrid"></div>
            </div>

            <aside class="des_trip-card" aria-label="Destination overview">
              <div class="des_trip-card-heading">
                <span class="des_trip-pin" aria-hidden="true">
                  <svg viewBox="0 0 24 24"><path d="M12 2a7 7 0 0 0-7 7c0 5.2 7 13 7 13s7-7.8 7-13a7 7 0 0 0-7-7Zm0 9.5A2.5 2.5 0 1 1 12 6a2.5 2.5 0 0 1 0 5.5Z"/></svg>
                </span>
                <div><small>Destination guide</small><strong>Mercedes, Camarines Norte</strong></div>
              </div>
              <dl class="des_trip-facts">
                <div><dt>Experience</dt><dd id="des_factType">Island escape</dd></div>
                <div><dt>Things to do</dt><dd id="des_factActivities">0 activities</dd></div>
                <div><dt>Photo guide</dt><dd id="des_factGallery">0 photos</dd></div>
                <div><dt>Coordinates</dt><dd id="des_factCoordinates">Available</dd></div>
              </dl>
              <div class="des_destination-map-preview">
                <div id="destinationMapPreview" class="des_destination-map-preview-canvas" aria-label="Map preview of Mercedes destinations"></div>
                <div class="des_destination-map-shade"></div>
                <button type="button" class="des_show-map-btn" onclick="openDestinationMap()">
                  <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m15 5-6-2-6 2v16l6-2 6 2 6-2V3l-6 2Zm-5 .4 4 1.34v11.84l-4-1.34V5.4Zm-5 1.04 3-1v11.8l-3 1V6.44Zm14 11.12-3 1V6.76l3-1v11.8Z"/></svg>
                  <span>Show on map</span>
                </button>
              </div>
              <button type="button" class="des_nearby-action" onclick="showSection('resort')">Explore nearby stays <span aria-hidden="true">&rarr;</span></button>
            </aside>
          </div>
        </div>

        <div class="des_content-heading des_content-heading--editorial">
          <div><span>Explore visually</span><h3>Destination gallery</h3></div>
          <div class="des_gallery-toolbar" aria-label="Gallery controls">
            <p id="des_galleryCount">Local photos</p>
            <div class="des_gallery-arrows">
              <button type="button" onclick="changeGallerySet(-1)" aria-label="Show previous gallery photos">&#8249;</button>
              <button type="button" onclick="changeGallerySet(1)" aria-label="Show next gallery photos">&#8250;</button>
            </div>
          </div>
        </div>
        <div class="des_image-gallery des_image-gallery--editorial" id="des_imageGallery"></div>

      </section>

      <!-- Resorts Section -->
      <section id="sectionResorts" class="des_section">
        <div id="resortList"></div>
      </section>
    </div>
  </div>

  <div class="des_destination-map-modal" id="destinationMapModal" aria-hidden="true" role="dialog" aria-modal="true" aria-labelledby="destinationMapTitle">
    <div class="des_destination-map-dialog">
      <header class="des_destination-map-topbar">
        <div><span>Explore Mercedes</span><h2 id="destinationMapTitle">Destinations on the map</h2></div>
        <button type="button" onclick="closeDestinationMap()" aria-label="Close destination map">&times;</button>
      </header>
      <div class="des_destination-map-layout">
        <aside class="des_destination-map-results">
          <p>Choose a destination to locate it on the map.</p>
          <div id="destinationMapResults"></div>
        </aside>
        <div id="destinationMapCanvas" class="des_destination-map-canvas" aria-label="Map of Mercedes destinations"></div>
      </div>
    </div>
  </div>

  <!-- Modals (unchanged) -->
  <div id="des_imageModal" class="des_image-modal" aria-hidden="true">
    <div class="des_image-modal-content">
      <button class="des_image-close-btn" type="button" aria-label="Close image viewer" onclick="closeImageModal()">&times;</button>
      <button class="des_nav-btn des_prev" type="button" aria-label="Previous image" onclick="changeImage(-1)">&lsaquo;</button>
      <img id="des_imageModalImg" class="des_image-modal-img" src="" alt="" />
      <button class="des_nav-btn des_next" type="button" aria-label="Next image" onclick="changeImage(1)">&rsaquo;</button>
      <div class="des_image-counter" id="des_imageCounter"></div>
    </div>
  </div>

  <div id="des_resortModal" class="des_image-modal" aria-hidden="true">
    <div class="des_image-modal-content">
      <button class="des_image-close-btn" type="button" aria-label="Close resort image viewer" onclick="closeResortImageModal()">&times;</button>
      <button class="des_nav-btn des_prev" type="button" aria-label="Previous resort image" onclick="changeResortImage(-1)">&lsaquo;</button>
      <img id="des_resortModalImg" class="des_image-modal-img" src="" alt="" />
      <button class="des_nav-btn des_next" type="button" aria-label="Next resort image" onclick="changeResortImage(1)">&rsaquo;</button>
      <div class="des_image-counter" id="des_resortCounter"></div>
    </div>
  </div>

  <a class="des_mobile-floating-book" href="hotel_resorts.php?tab=tours" aria-label="Book a tour">
    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7.5A2.5 2.5 0 0 0 6.5 10 2.5 2.5 0 0 0 4 12.5V17a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-4.5a2.5 2.5 0 0 0 0-5V7a2 2 0 0 0-2-2H6a2 2 0 0 0-2 2v.5Z"></path><path d="M14 8.5h3M14 12h3M14 15.5h2"></path></svg>
    <span>Book a Tour</span>
  </a>

<?php include 'footer.php'; ?>

<script defer src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin="anonymous"></script>
<script>window.destinationData = <?= json_encode($publicDestinations, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;</script>
<script defer src="destination.js?v=<?= (int)@filemtime(__DIR__ . '/../destination.js') ?>"></script>
<script src="js/header.js?v=<?= (int)@filemtime(__DIR__ . '/../js/header.js') ?>"></script>
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


</script>
<script src="js/back-to-top.js?v=<?= (int)@filemtime(__DIR__ . '/../js/back-to-top.js') ?>"></script>

</body>
</html>

