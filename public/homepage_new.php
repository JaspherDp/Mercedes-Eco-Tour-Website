<?php
chdir(__DIR__ . '/..');
require_once __DIR__ . '/../php/session_security.php';
AppSessionStart();
require_once 'php/db_connection.php';
require_once 'php/unified_search_config.php';

// Function to get the latest non-empty value for a given column
function getLatestFieldValue($pdo, $column) {
    $stmt = $pdo->prepare("SELECT $column FROM featured_section WHERE $column IS NOT NULL AND $column != '' ORDER BY id DESC LIMIT 1");
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row[$column] ?? '';
}

function tableExists(PDO $pdo, string $table): bool
{
    static $cache = [];
    if (array_key_exists($table, $cache)) {
        return $cache[$table];
    }

    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM information_schema.tables
        WHERE table_schema = DATABASE() AND table_name = ?
    ");
    $stmt->execute([$table]);
    $cache[$table] = ((int)$stmt->fetchColumn()) > 0;
    return $cache[$table];
}

function resolveSearchTab(string $tab): string
{
    $value = strtolower(trim($tab));
    $aliases = [
        'hotel' => 'hotels',
        'hotel-resorts' => 'hotels',
        'hotel_resorts' => 'hotels',
        'tour-packages' => 'tours',
        'packages' => 'tours',
        'tour-guides' => 'guides',
        'our-boats' => 'boats',
        'guide-boat' => 'bundle',
        'guide_boat' => 'bundle'
    ];

    return $aliases[$value] ?? $value;
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

// Fetch popular items for carousel sections
$popularPackages = [];
$popularGuides = [];
$popularBoats = [];
$popularHotels = [];

try {
    if (tableExists($pdo, 'tour_packages')) {
        try {
            $stmt = $pdo->query("
                SELECT p.*, COALESCE(ROUND(AVG(f.rating), 1), 0) AS rating
                FROM tour_packages p
                LEFT JOIN feedback f ON f.package_id = p.package_id AND f.moderation_status = 'published'
                GROUP BY p.package_id
                ORDER BY p.package_id DESC
                LIMIT 10
            ");
            $popularPackages = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
        } catch (Throwable $e) {
            $stmt = $pdo->query("SELECT * FROM tour_packages ORDER BY package_id DESC LIMIT 10");
            $popularPackages = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
        }
    }

    if (tableExists($pdo, 'tour_guides')) {
        $stmt = $pdo->query("SELECT * FROM tour_guides ORDER BY guide_id DESC LIMIT 10");
        $popularGuides = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    }

    if (tableExists($pdo, 'boats')) {
        $stmt = $pdo->query("SELECT * FROM boats ORDER BY boat_id DESC LIMIT 10");
        $popularBoats = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    }

    $hotelTable = null;
    if (tableExists($pdo, 'hotel_resorts')) {
        $hotelTable = 'hotel_resorts';
    } elseif (tableExists($pdo, 'hotel_resort')) {
        $hotelTable = 'hotel_resort';
    }
    if ($hotelTable !== null) {
        $orderColumn = $hotelTable === 'hotel_resorts' ? 'hotel_resort_id' : 'id';
        $stmt = $pdo->query("SELECT * FROM {$hotelTable} ORDER BY {$orderColumn} DESC LIMIT 10");
        $popularHotels = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    }
} catch (Throwable $e) {
}

// Convert search tabs config to JSON for JS
$searchTabsJson = json_encode($SEARCH_TABS);
$requestedTab = resolveSearchTab((string)($_GET['tab'] ?? 'hotels'));
$activeTab = isset($SEARCH_TABS[$requestedTab]) ? $requestedTab : 'hotels';
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>iTour Mercedes - Discover & Book Adventures</title>
  <link rel="icon" type="image/png" href="img/newlogo.png" />
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet" />
  <link rel="stylesheet" href="styles/homepage.css" />
  <link rel="stylesheet" href="styles/unified_search.css" />
</head>

<body class="homepage">

  <!-- Header -->
  <div id="header"></div>

  <!-- ========== UNIFIED SEARCH HERO SECTION ========== -->
  <section class="search-hero-section">
    <div class="search-hero-content">
      <h1 class="search-hero-title">Discover Your Next Adventure</h1>
      <p class="search-hero-subtitle">Search and book hotels, tours, guides, and boats in one place</p>

      <!-- TAB SYSTEM -->
      <div class="search-tabs-wrapper">
        <div class="search-tabs-container">
          <?php foreach ($SEARCH_TABS as $tab): ?>
            <button 
              class="search-tab-btn <?= $tab['id'] === $activeTab ? 'active' : '' ?>" 
              data-tab="<?= $tab['id'] ?>"
            >
              <span class="tab-icon" aria-hidden="true"><i data-lucide="<?= htmlspecialchars((string)$tab['icon']) ?>"></i></span>
              <span><?= $tab['label'] ?></span>
            </button>
          <?php endforeach; ?>
        </div>
      </div>

      <!-- SEARCH FORM -->
      <div class="search-form-wrapper">
        <form id="unifiedSearchForm">
          <div id="searchFormContainer">
            <!-- Dynamic form fields will be rendered here by JavaScript -->
          </div>
        </form>
      </div>
    </div>
  </section>

  <!-- Hidden config for JavaScript -->
  <script id="searchTabsConfig" type="application/json">
    <?= $searchTabsJson ?>
  </script>
  <script id="searchActiveTab" type="application/json">
    <?= json_encode($activeTab) ?>
  </script>
  <script src="https://unpkg.com/lucide@0.469.0/dist/umd/lucide.min.js"></script>
  <script src="js/unified_search.js"></script>

  <!-- Login/Signup Modal -->
  <div id="loginModal"></div>

  <!-- ========== HORIZONTAL CAROUSEL SECTIONS ========== -->

  <?php if (!empty($popularHotels)): ?>
  <section class="carousel-section carousel-shell">
    <h2 class="carousel-section-title">Featured Hotels/Resorts</h2>
    <div class="carousel-wrapper">
      <button class="carousel-chevron prev" onclick="scrollCarousel(this, -1)" aria-label="Previous featured hotels">
        <i data-lucide="chevron-left" aria-hidden="true"></i>
      </button>
      <div class="carousel-container" data-carousel="hotels">
        <?php foreach ($popularHotels as $hotel): ?>
          <div class="result-card">
            <img 
              src="<?= htmlspecialchars($hotel['image_path'] ?? $hotel['image_url'] ?? 'img/default-hotel.png') ?>" 
              alt="<?= htmlspecialchars($hotel['name'] ?? 'Hotel') ?>"
              class="result-card-image"
            />
            <div class="result-card-content">
              <h3 class="result-card-title"><?= htmlspecialchars($hotel['name'] ?? 'Hotel') ?></h3>
              <div class="result-card-location">
                <i data-lucide="map-pin" aria-hidden="true"></i>
                <?= htmlspecialchars($hotel['location'] ?? $hotel['island'] ?? 'Mercedes') ?>
              </div>
              <?php if (isset($hotel['rating'])): ?>
              <div class="result-card-rating">
                <span class="result-card-stars">★★★★★</span>
                <span>(<?= number_format($hotel['rating'], 1) ?>)</span>
              </div>
              <?php endif; ?>
              <?php if (isset($hotel['price'])): ?>
              <div class="result-card-price">
                $<?= number_format($hotel['price'], 0) ?>/night
              </div>
              <?php endif; ?>
              <button class="result-card-button" onclick="window.location='hotel_details.php?id=<?= $hotel['hotel_resort_id'] ?? $hotel['id'] ?? '' ?>'">
                View Details
              </button>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
      <button class="carousel-chevron next" onclick="scrollCarousel(this, 1)" aria-label="Next featured hotels">
        <i data-lucide="chevron-right" aria-hidden="true"></i>
      </button>
    </div>
  </section>
  <?php endif; ?>

  <?php if (!empty($popularGuides)): ?>
  <section class="carousel-section carousel-shell">
    <h2 class="carousel-section-title">Popular Tour Guides</h2>
    <div class="carousel-wrapper">
      <button class="carousel-chevron prev" onclick="scrollCarousel(this, -1)" aria-label="Previous tour guides">
        <i data-lucide="chevron-left" aria-hidden="true"></i>
      </button>
      <div class="carousel-container" data-carousel="guides">
        <?php foreach ($popularGuides as $guide): ?>
          <div class="result-card">
            <img 
              src="<?= htmlspecialchars($guide['profile_picture'] ?? $guide['profile_image'] ?? 'img/default-guide.png') ?>" 
              alt="<?= htmlspecialchars($guide['fullname'] ?? $guide['name'] ?? 'Tour Guide') ?>"
              class="result-card-image"
            />
            <div class="result-card-content">
              <h3 class="result-card-title"><?= htmlspecialchars($guide['fullname'] ?? $guide['name'] ?? 'Tour Guide') ?></h3>
              <div class="result-card-location">
                <i data-lucide="map-pin" aria-hidden="true"></i>
                <?= htmlspecialchars($guide['location'] ?? 'Mercedes') ?>
              </div>
              <?php if (isset($guide['rating'])): ?>
              <div class="result-card-rating">
                <span class="result-card-stars">★★★★★</span>
                <span>(<?= number_format($guide['rating'], 1) ?>)</span>
              </div>
              <?php endif; ?>
              <?php if (isset($guide['daily_rate'])): ?>
              <div class="result-card-price">
                $<?= number_format($guide['daily_rate'], 0) ?>/day
              </div>
              <?php endif; ?>
              <button class="result-card-button" onclick="window.location='tour_booking.php?booking_type=tourguide&amp;preferred=<?= rawurlencode((string)($guide['fullname'] ?? $guide['name'] ?? '')) ?>&amp;return=<?= rawurlencode('hotel_resorts.php?tab=guides') ?>'">
                View Profile
              </button>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
      <button class="carousel-chevron next" onclick="scrollCarousel(this, 1)" aria-label="Next tour guides">
        <i data-lucide="chevron-right" aria-hidden="true"></i>
      </button>
    </div>
  </section>
  <?php endif; ?>

  <?php if (!empty($popularPackages)): ?>
  <section class="carousel-section carousel-shell">
    <h2 class="carousel-section-title">Popular Tour Packages</h2>
    <div class="carousel-wrapper">
      <button class="carousel-chevron prev" onclick="scrollCarousel(this, -1)" aria-label="Previous tour packages">
        <i data-lucide="chevron-left" aria-hidden="true"></i>
      </button>
      <div class="carousel-container" data-carousel="packages">
        <?php foreach ($popularPackages as $pkg): ?>
          <div class="result-card">
            <img 
              src="<?= htmlspecialchars($pkg['package_image'] ?? $pkg['image_url'] ?? 'img/default-package.png') ?>" 
              alt="<?= htmlspecialchars($pkg['package_title'] ?? $pkg['name'] ?? 'Tour Package') ?>"
              class="result-card-image"
            />
            <div class="result-card-content">
              <h3 class="result-card-title"><?= htmlspecialchars($pkg['package_title'] ?? $pkg['name'] ?? 'Tour Package') ?></h3>
              <div class="result-card-location">
                <i data-lucide="map-pin" aria-hidden="true"></i>
                <?= htmlspecialchars($pkg['destination'] ?? 'Mercedes') ?>
              </div>
              <?php if (isset($pkg['rating'])): ?>
              <div class="result-card-rating">
                <span class="result-card-stars">★★★★★</span>
                <span>(<?= number_format($pkg['rating'], 1) ?>)</span>
              </div>
              <?php endif; ?>
              <?php if (isset($pkg['price'])): ?>
              <div class="result-card-price">
                $<?= number_format($pkg['price'], 0) ?>
              </div>
              <?php endif; ?>
              <button class="result-card-button" onclick="window.location='package_details.php?package_id=<?= $pkg['package_id'] ?? $pkg['id'] ?? '' ?>'">
                View Details
              </button>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
      <button class="carousel-chevron next" onclick="scrollCarousel(this, 1)" aria-label="Next tour packages">
        <i data-lucide="chevron-right" aria-hidden="true"></i>
      </button>
    </div>
  </section>
  <?php endif; ?>

  <?php if (!empty($popularBoats)): ?>
  <section class="carousel-section carousel-shell">
    <h2 class="carousel-section-title">Popular Boats</h2>
    <div class="carousel-wrapper">
      <button class="carousel-chevron prev" onclick="scrollCarousel(this, -1)" aria-label="Previous boats">
        <i data-lucide="chevron-left" aria-hidden="true"></i>
      </button>
      <div class="carousel-container" data-carousel="boats">
        <?php foreach ($popularBoats as $boat): ?>
          <div class="result-card">
            <img 
              src="<?= htmlspecialchars($boat['image1'] ?? $boat['image_url'] ?? 'img/default-boat.png') ?>" 
              alt="<?= htmlspecialchars($boat['name'] ?? 'Boat') ?>"
              class="result-card-image"
            />
            <div class="result-card-content">
              <h3 class="result-card-title"><?= htmlspecialchars($boat['name'] ?? 'Boat') ?></h3>
              <div class="result-card-location">
                <i data-lucide="map-pin" aria-hidden="true"></i>
                <?= htmlspecialchars($boat['location'] ?? 'Mercedes') ?>
              </div>
              <?php if (isset($boat['capacity']) || isset($boat['total_pax'])): ?>
              <div class="result-card-location">
                <i data-lucide="users" aria-hidden="true"></i>
                Capacity: <?= intval($boat['capacity'] ?? $boat['total_pax']) ?> people
              </div>
              <?php endif; ?>
              <?php if (isset($boat['price_per_hour'])): ?>
              <div class="result-card-price">
                $<?= number_format($boat['price_per_hour'], 0) ?>/hr
              </div>
              <?php endif; ?>
              <button class="result-card-button" onclick="window.location='tour_booking.php?booking_type=boat&amp;preferred=<?= rawurlencode((string)($boat['name'] ?? '')) ?>&amp;return=<?= rawurlencode('hotel_resorts.php?tab=boats') ?>'">
                Book Now
              </button>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
      <button class="carousel-chevron next" onclick="scrollCarousel(this, 1)" aria-label="Next boats">
        <i data-lucide="chevron-right" aria-hidden="true"></i>
      </button>
    </div>
  </section>
  <?php endif; ?>

  <!-- Footer -->
  <div id="footer"></div>

  <!-- Carousel Navigation Script -->
  <script>
    function scrollCarousel(button, direction) {
      const wrapper = button.closest('.carousel-wrapper');
      const container = wrapper.querySelector('.carousel-container');
      const scrollAmount = 300;
      
      if (direction === -1) {
        container.scrollBy({ left: -scrollAmount, behavior: 'smooth' });
      } else {
        container.scrollBy({ left: scrollAmount, behavior: 'smooth' });
      }

      // Update button visibility
      updateCarouselButtons(wrapper);
    }

    function updateCarouselButtons(wrapper) {
      const container = wrapper.querySelector('.carousel-container');
      const prevBtn = wrapper.querySelector('.carousel-chevron.prev');
      const nextBtn = wrapper.querySelector('.carousel-chevron.next');

      if (container.scrollLeft <= 0) {
        prevBtn.disabled = true;
      } else {
        prevBtn.disabled = false;
      }

      if (container.scrollLeft >= container.scrollWidth - container.clientWidth - 10) {
        nextBtn.disabled = true;
      } else {
        nextBtn.disabled = false;
      }
    }

    // Initialize carousel button states on load
    document.addEventListener('DOMContentLoaded', () => {
      document.querySelectorAll('.carousel-wrapper').forEach(wrapper => {
        updateCarouselButtons(wrapper);
        const container = wrapper.querySelector('.carousel-container');
        container.addEventListener('scroll', () => updateCarouselButtons(wrapper));
      });
      if (window.lucide && typeof window.lucide.createIcons === 'function') {
        window.lucide.createIcons();
      }
    });
  </script>

  <!-- Load header/footer and login modal -->
  <script src="includes/header_loader.js?v=2"></script>

</body>
</html>
