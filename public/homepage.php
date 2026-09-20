<?php
chdir(__DIR__ . '/..');
require_once __DIR__ . '/../php/session_security.php';
AppSessionStart();
require_once 'php/db_connection.php';
require_once 'php/hotel_reviews_helper.php';
ensureHotelReviewManagementColumns($pdo);

function getLatestFieldValue(PDO $pdo, string $column): string
{
    $allowedColumns = [
        'description1', 'description2', 'footer_text', 'video_path',
        'slider_image1', 'slider_image2', 'slider_image3', 'slider_image4',
        'small_image1', 'small_image2'
    ];
    if (!in_array($column, $allowedColumns, true)) {
        return '';
    }

    $stmt = $pdo->prepare("SELECT {$column} FROM featured_section WHERE {$column} IS NOT NULL AND {$column} != '' ORDER BY id DESC LIMIT 1");
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return trim((string)($row[$column] ?? ''));
}

function resolveHomepageImage(?string $rawPath, string $fallback = 'img/sampleimage.png'): string
{
    $candidate = trim((string)$rawPath);
    if ($candidate === '') return $fallback;
    if (preg_match('#^https?://#i', $candidate)) return $candidate;

    $cleanCandidate = ltrim(str_replace('\\', '/', $candidate), '/');
    if (is_file(__DIR__ . '/../' . $cleanCandidate)) return $cleanCandidate;

    $basename = basename($cleanCandidate);
    foreach (['php/upload/', 'uploads/', 'img/', 'imagess/'] as $directory) {
        $relativePath = $directory . $basename;
        if (is_file(__DIR__ . '/../' . $relativePath)) return $relativePath;
    }
    return $fallback;
}

$description1  = getLatestFieldValue($pdo, 'description1');
$description2  = getLatestFieldValue($pdo, 'description2');
$footerText    = getLatestFieldValue($pdo, 'footer_text');
$videoPath     = getLatestFieldValue($pdo, 'video_path');
$sliderImages  = [];
for ($index = 1; $index <= 4; $index++) {
    $sliderImages[] = getLatestFieldValue($pdo, 'slider_image' . $index) ?: 'img/sampleimage.png';
}
$smallImage1 = getLatestFieldValue($pdo, 'small_image1') ?: 'img/Apuao Pequeña.png';
$smallImage2 = getLatestFieldValue($pdo, 'small_image2') ?: 'img/sampleimagesec.png';

$popularTours = [];
try {
    $popularToursStmt = $pdo->query("
        SELECT p.package_id, p.package_title, p.package_type, p.package_range,
               p.price, p.package_image,
               COALESCE(ROUND(AVG(f.rating), 1), 0) AS rating,
               COUNT(f.feedback_id) AS review_count
        FROM tour_packages p
        LEFT JOIN feedback f ON f.package_id = p.package_id AND f.moderation_status = 'published'
        GROUP BY p.package_id, p.package_title, p.package_type,
                 p.package_range, p.price, p.package_image
        ORDER BY review_count DESC, rating DESC, p.package_id DESC
        LIMIT 8
    ");
    $popularTours = $popularToursStmt ? $popularToursStmt->fetchAll(PDO::FETCH_ASSOC) : [];
} catch (Throwable $error) {
    $popularTours = [];
}

$reviewSummaryByKey = [];
foreach ($popularTours as $tour) {
    $reviewSummaryByKey['package:' . (int)$tour['package_id']] = [
        'rating' => (float)($tour['rating'] ?? 0),
        'reviewCount' => (int)($tour['review_count'] ?? 0)
    ];
}
try {
    $hotelReviewStmt = $pdo->query("
        SELECT h.hotel_resort_id, LOWER(h.type) AS item_type,
               COALESCE(ROUND(AVG(r.rating), 1), 0) AS rating,
               COUNT(r.review_id) AS review_count
        FROM hotel_resorts h
        LEFT JOIN hotel_resort_reviews r ON r.hotel_resort_id = h.hotel_resort_id AND r.moderation_status = 'published'
        GROUP BY h.hotel_resort_id, h.type
    ");
    foreach ($hotelReviewStmt ? $hotelReviewStmt->fetchAll(PDO::FETCH_ASSOC) : [] as $summary) {
        $type = in_array($summary['item_type'], ['hotel', 'resort'], true) ? $summary['item_type'] : 'hotel';
        $reviewSummaryByKey[$type . ':' . (int)$summary['hotel_resort_id']] = [
            'rating' => (float)$summary['rating'],
            'reviewCount' => (int)$summary['review_count']
        ];
    }
} catch (Throwable $error) {
}
try {
    $serviceReviewStmt = $pdo->query("
        SELECT 'boat' AS item_type, boat_id AS item_id,
               COALESCE(ROUND(AVG(rating), 1), 0) AS rating,
               COUNT(feedback_id) AS review_count
        FROM feedback WHERE boat_id IS NOT NULL AND moderation_status = 'published' GROUP BY boat_id
        UNION ALL
        SELECT 'guide' AS item_type, tourguide_id AS item_id,
               COALESCE(ROUND(AVG(rating), 1), 0) AS rating,
               COUNT(feedback_id) AS review_count
        FROM feedback WHERE tourguide_id IS NOT NULL AND moderation_status = 'published' GROUP BY tourguide_id
    ");
    foreach ($serviceReviewStmt ? $serviceReviewStmt->fetchAll(PDO::FETCH_ASSOC) : [] as $summary) {
        $reviewSummaryByKey[$summary['item_type'] . ':' . (int)$summary['item_id']] = [
            'rating' => (float)$summary['rating'],
            'reviewCount' => (int)$summary['review_count']
        ];
    }
} catch (Throwable $error) {
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="theme-color" content="#155a49">
  <meta name="description" content="Discover Mercedes, Camarines Norte with iTour Mercedes. Explore tourist destinations, island hopping tours, hotels, resorts, and local tourism experiences.">
  <link rel="canonical" href="https://itourmercedes.com/">
  <title>iTour Mercedes | Tourism Guide to Mercedes, Camarines Norte</title>
  <link rel="icon" type="image/png" href="img/newlogo.png">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="styles/homepage.css?v=<?= (int)@filemtime(__DIR__ . '/../styles/homepage.css') ?>">
  <link rel="stylesheet" href="styles/back-to-top.css?v=<?= (int)@filemtime(__DIR__ . '/../styles/back-to-top.css') ?>">
  <script>document.documentElement.classList.add('itour-page-loading');</script>
  <link rel="stylesheet" href="styles/page-loader.css?v=<?= (int)@filemtime(__DIR__ . '/../styles/page-loader.css') ?>">
  <script defer src="js/page-loader.js?v=<?= (int)@filemtime(__DIR__ . '/../js/page-loader.js') ?>"></script>
</head>
<body class="homepage">
  <?php include __DIR__ . '/../includes/page_loader.php'; ?>
  <div id="header"></div>

  <main>
    <section class="home-hero" aria-labelledby="heroTitle">
      <div class="hero-overlay"></div>
      <div class="hero-shell">
        <div class="hero-content">
          <span class="hero-eyebrow">Welcome to Mercedes, Camarines Norte</span>
          <h1 id="heroTitle">Find your kind of <span>island adventure.</span></h1>
          <p class="hero-tagline">From quiet beaches to unforgettable island-hopping, plan a local escape with trusted tours, stays, guides, and boats.</p>

          <form class="hero-search" action="destination_results.php" method="get" aria-label="Search bookable services by destination">
            <label class="hero-search-field" for="heroDestinationSearch">
              <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2a7 7 0 0 0-7 7c0 5.25 7 13 7 13s7-7.75 7-13a7 7 0 0 0-7-7Zm0 9.5A2.5 2.5 0 1 1 12 6a2.5 2.5 0 0 1 0 5.5Z"/></svg>
              <span class="sr-only">Destination</span>
              <input id="heroDestinationSearch" type="search" name="destination" placeholder="Where do you want to go?" autocomplete="off" role="combobox" aria-autocomplete="list" aria-expanded="false" aria-controls="heroSearchSuggestions">
            </label>
            <button type="submit" aria-label="Search all services">
              <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m21 20-4.35-4.35a8 8 0 1 0-1.42 1.42L19.59 21 21 20ZM5 11a6 6 0 1 1 12 0 6 6 0 0 1-12 0Z"/></svg>
              <span class="hero-search-button-label">Search all</span>
            </button>
            <div class="hero-search-suggestions" id="heroSearchSuggestions" role="listbox" aria-label="Destination suggestions" hidden></div>
          </form>

          <div class="hero-actions">
            <a href="destination.php" class="hero-btn hero-btn--primary">Explore destinations</a>
            <a href="hotel_resorts.php?tab=tours" class="hero-btn hero-btn--ghost">Browse all tours <span aria-hidden="true">→</span></a>
          </div>
          <nav class="hero-mobile-nav" aria-label="Mobile quick links">
            <a href="./" aria-current="page">
              <span class="hero-mobile-nav-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="m3.5 10.5 8.5-7 8.5 7M5.5 9v11.5h13V9"/><path class="icon-accent" d="M9.5 20.5v-6h5v6"/></svg></span>
              <span>Home</span>
            </a>
            <a href="destination.php">
              <span class="hero-mobile-nav-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M19.5 9.5c0 5.2-7.5 11.5-7.5 11.5S4.5 14.7 4.5 9.5a7.5 7.5 0 1 1 15 0Z"/><circle class="icon-accent" cx="12" cy="9.5" r="2.4"/></svg></span>
              <span>Destinations</span>
            </a>
            <a href="hotel_resorts.php?tab=tours">
              <span class="hero-mobile-nav-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><rect x="3" y="7" width="18" height="14" rx="3"/><path class="icon-accent" d="M8.5 7V4.5A1.5 1.5 0 0 1 10 3h4a1.5 1.5 0 0 1 1.5 1.5V7"/><path d="M8 11v6M16 11v6"/></svg></span>
              <span>Tours</span>
            </a>
            <a href="about.php">
              <span class="hero-mobile-nav-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><circle class="icon-accent" cx="12" cy="7.5" r="1.25"/><path d="M12 11v6"/></svg></span>
              <span>About</span>
            </a>
          </nav>
        </div>

        <aside class="hero-visual" aria-label="Interactive Mercedes island highlights" data-hero-visual>
          <div class="hero-floating-scene">
            <span class="hero-scene-ring hero-scene-ring--one" aria-hidden="true"></span>
            <span class="hero-scene-ring hero-scene-ring--two" aria-hidden="true"></span>
            <svg class="hero-scene-trail" viewBox="0 0 460 470" aria-hidden="true">
              <path d="M22 360C70 340 51 252 116 240s69 78 135 50 41-121 101-143 64 29 91-61"></path>
            </svg>

            <span class="hero-scene-boat hero-scene-boat--main" aria-hidden="true">
              <svg viewBox="0 0 42 42"><path d="M7 24.5h28l-5.6 8.2H12.5L7 24.5Zm10.8-15 10.7 12H17.8v-12Zm-2.7 2.3v9.7H8.8l6.3-9.7Z"></path><path class="boat-wave" d="M7 35c3 2 5 2 8 0 3 2 5 2 8 0 3 2 5 2 8 0"></path></svg>
            </span>
            <span class="hero-scene-boat hero-scene-boat--small" aria-hidden="true">
              <svg viewBox="0 0 42 42"><path d="M7 24.5h28l-5.6 8.2H12.5L7 24.5Zm10.8-15 10.7 12H17.8v-12Zm-2.7 2.3v9.7H8.8l6.3-9.7Z"></path></svg>
            </span>

            <figure class="hero-visual-card">
              <img id="heroVisualImage" src="imagess/Apuao Grande.jpg" alt="Apuao Grande island">
              <div class="hero-card-shade"></div>
              <figcaption aria-live="polite">
                <span>Featured island escape</span>
                <strong id="heroVisualTitle">Apuao Grande</strong>
                <small id="heroVisualLocation">Island retreat · Mercedes</small>
              </figcaption>
              <a class="hero-visual-action" href="destination.php" aria-label="Explore Apuao Grande">Explore <span aria-hidden="true">↗</span></a>
            </figure>

            <div class="hero-float-note hero-float-note--favorite">
              <span>★</span><div><strong>Local favorite</strong><small>Curated island experience</small></div>
            </div>
            <div class="hero-float-note hero-float-note--islands"><strong>7+</strong><span>islands to discover</span></div>

            <div class="hero-island-selector" role="tablist" aria-label="Choose a featured island">
              <span class="hero-selector-label">Explore route</span>
              <button class="hero-destination-option active" type="button" role="tab" aria-selected="true" title="Apuao Grande"
                      data-stop="0" data-title="Apuao Grande" data-location="Island retreat · Mercedes" data-image="imagess/Apuao Grande.jpg">
                <img src="imagess/Apuao Grande.jpg" alt=""><span>01</span>
              </button>
              <button class="hero-destination-option" type="button" role="tab" aria-selected="false" title="Caringo Island"
                      data-stop="1" data-title="Caringo Island" data-location="White sand beach · Mercedes" data-image="imagess/Caringo.jpg">
                <img src="imagess/Caringo.jpg" alt=""><span>02</span>
              </button>
              <button class="hero-destination-option" type="button" role="tab" aria-selected="false" title="Quinapaguian Island"
                      data-stop="2" data-title="Quinapaguian Island" data-location="Quiet coastline · Mercedes" data-image="imagess/Quinapaguian.jpg">
                <img src="imagess/Quinapaguian.jpg" alt=""><span>03</span>
              </button>
            </div>
            <span class="hero-switch-progress" aria-hidden="true"><span></span></span>
          </div>
        </aside>
      </div>
    </section>

    <?php
      $heroRibbonItems = [
        ['href' => 'hotel_resorts.php?tab=tours', 'icon' => 'img/packageshome.png', 'label' => 'Curated Tour Packages'],
        ['href' => 'hotel_resorts.php?tab=guides', 'icon' => 'img/tourguidehome.png', 'label' => 'Trusted Local Guides'],
        ['href' => 'hotel_resorts.php?tab=boats', 'icon' => 'img/boathome.png', 'label' => 'Island Boat Rentals'],
        ['href' => 'hotel_resorts.php?tab=hotels', 'icon' => 'img/hotelshome.png', 'label' => 'Hotels & Resorts'],
        ['href' => 'destination.php', 'icon' => 'img/locationicon.png', 'label' => 'Island Destinations'],
        ['href' => 'hotel_resorts.php?tab=tours', 'icon' => 'img/bookingicon.png', 'label' => 'Plan Your Escape']
      ];
    ?>
    <section class="hero-service-ribbon" aria-label="Explore iTour Mercedes services">
      <div class="hero-ribbon-fade hero-ribbon-fade--left" aria-hidden="true"></div>
      <div class="hero-ribbon-track">
        <?php for ($ribbonCopy = 0; $ribbonCopy < 2; $ribbonCopy++): ?>
          <div class="hero-ribbon-group" <?= $ribbonCopy === 1 ? 'aria-hidden="true"' : '' ?>>
            <?php foreach ($heroRibbonItems as $ribbonItem): ?>
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

    <section class="booking-types" aria-labelledby="bookingTypesTitle" data-home-reveal>
      <div class="section-shell">
        <div class="section-heading section-heading--compact">
          <div>
            <span class="section-kicker">Start planning</span>
            <h2 id="bookingTypesTitle">Everything for your trip, in one place</h2>
          </div>
          <p>Choose what you need and start exploring verified local options.</p>
        </div>

        <div class="bt-container">
          <a href="hotel_resorts.php?tab=tours" class="bt-card-link">
            <span class="bt-icon-wrap"><img src="img/packageshome.png" alt=""></span>
            <span class="bt-card-copy"><strong>Tour packages</strong><small>Ready-made island experiences</small></span>
            <span class="bt-arrow" aria-hidden="true">→</span>
          </a>
          <a href="hotel_resorts.php?tab=guides" class="bt-card-link">
            <span class="bt-icon-wrap"><img src="img/tourguidehome.png" alt=""></span>
            <span class="bt-card-copy"><strong>Local guides</strong><small>Explore with local expertise</small></span>
            <span class="bt-arrow" aria-hidden="true">→</span>
          </a>
          <a href="hotel_resorts.php?tab=boats" class="bt-card-link">
            <span class="bt-icon-wrap"><img src="img/boathome.png" alt=""></span>
            <span class="bt-card-copy"><strong>Boat rentals</strong><small>Travel safely between islands</small></span>
            <span class="bt-arrow" aria-hidden="true">→</span>
          </a>
          <a href="hotel_resorts.php?tab=hotels" class="bt-card-link">
            <span class="bt-icon-wrap"><img src="img/hotelshome.png" alt=""></span>
            <span class="bt-card-copy"><strong>Hotels &amp; resorts</strong><small>Find your home by the coast</small></span>
            <span class="bt-arrow" aria-hidden="true">→</span>
          </a>
        </div>
      </div>
    </section>

    <!-- Statistics section temporarily hidden for future relocation.
    <section class="home-stats" aria-labelledby="homeStatsTitle">
      <div class="section-shell home-stats-shell">
        <h2 id="homeStatsTitle" class="sr-only">Mercedes tourism statistics</h2>
        <dl class="home-stats-grid">
          <div class="home-stat"><dt><strong>7<span>+</span></strong><span>Island destinations</span></dt><dd>More shores to discover</dd></div>
          <div class="home-stat"><dt><strong>4</strong><span>Ways to book</span></dt><dd>Tours, guides, boats, and stays</dd></div>
          <div class="home-stat"><dt><strong>100<span>%</span></strong><span>Local focus</span></dt><dd>Built around Mercedes tourism</dd></div>
          <div class="home-stat"><dt><strong>1</strong><span>Easy platform</span></dt><dd>Everything for your trip</dd></div>
        </dl>
      </div>
    </section>
    -->

    <section id="recentlyViewedSection" class="recent-section" aria-labelledby="recentTitle" data-home-reveal>
      <div class="section-shell">
        <div class="section-heading">
          <div>
            <span id="recentKicker" class="section-kicker">Popular with travelers</span>
            <h2 id="recentTitle">Popular tours</h2>
          </div>
          <div class="carousel-controls">
            <button type="button" data-recent-direction="-1" aria-label="Show previous recently viewed items">←</button>
            <button type="button" data-recent-direction="1" aria-label="Show next recently viewed items">→</button>
          </div>
        </div>
        <div id="recentlyViewedTrack" class="recent-track" tabindex="0" aria-label="Popular tours">
          <?php foreach ($popularTours as $tour): ?>
            <?php
              $tourId = (int)($tour['package_id'] ?? 0);
              $tourName = trim((string)($tour['package_title'] ?? 'Tour package'));
              $tourImage = resolveHomepageImage($tour['package_image'] ?? null);
              $tourMeta = array_filter([trim((string)($tour['package_type'] ?? '')), trim((string)($tour['package_range'] ?? ''))]);
              $tourSubtitle = $tourMeta ? implode(' · ', $tourMeta) : 'Mercedes, Camarines Norte';
              $tourRating = (float)($tour['rating'] ?? 0);
              $tourType = trim((string)($tour['package_type'] ?? '')) ?: 'Tour package';
              $tourDuration = trim((string)($tour['package_range'] ?? '')) ?: 'Details available';
              $tourReviewCount = (int)($tour['review_count'] ?? 0);
              $tourRatingWidth = max(0, min(100, ($tourRating / 5) * 100));
              $tourReviewSummary = $tourReviewCount > 0
                  ? number_format($tourRating, 1) . ' (' . number_format($tourReviewCount) . ' ' . ($tourReviewCount === 1 ? 'review' : 'reviews') . ')'
                  : ($tourRating > 0 ? number_format($tourRating, 1) . ' guest rating' : 'No reviews yet');
              $tourPrice = (float)($tour['price'] ?? 0);
              $tourHref = 'package_details.php?package_id=' . $tourId;
              $tourRecentData = json_encode([
                  'type' => 'package', 'id' => $tourId, 'name' => $tourName,
                  'image' => $tourImage, 'subtitle' => $tourSubtitle,
                  'price' => $tourPrice, 'priceUnit' => 'per package',
                  'rating' => $tourRating, 'reviewCount' => $tourReviewCount,
                  'href' => $tourHref
              ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            ?>
            <article class="recent-card">
              <a href="<?= htmlspecialchars($tourHref, ENT_QUOTES, 'UTF-8') ?>" data-popular-tour="<?= htmlspecialchars((string)$tourRecentData, ENT_QUOTES, 'UTF-8') ?>">
                <div class="recent-image">
                  <img src="<?= htmlspecialchars($tourImage, ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars($tourName, ENT_QUOTES, 'UTF-8') ?>" loading="lazy">
                  <span class="recent-type">Popular tour</span>
                </div>
                <div class="recent-copy">
                  <h3><?= htmlspecialchars($tourName, ENT_QUOTES, 'UTF-8') ?></h3>
                  <span class="recent-category">Tour package</span>
                  <p class="recent-location"><span aria-hidden="true">&#9906;</span> Mercedes, Camarines Norte</p>
                  <div class="recent-facts" aria-label="Tour details">
                    <span><i aria-hidden="true">&#9679;</i><?= htmlspecialchars($tourType, ENT_QUOTES, 'UTF-8') ?></span>
                    <span><i aria-hidden="true">&#9716;</i><?= htmlspecialchars($tourDuration, ENT_QUOTES, 'UTF-8') ?></span>
                  </div>
                  <div class="recent-rating" aria-label="<?= htmlspecialchars($tourReviewSummary, ENT_QUOTES, 'UTF-8') ?>">
                    <span class="recent-stars" aria-hidden="true">
                      <span class="recent-stars-base">★★★★★</span>
                      <span class="recent-stars-fill" style="width:<?= number_format($tourRatingWidth, 2, '.', '') ?>%">★★★★★</span>
                    </span>
                    <span class="recent-review-summary"><?= htmlspecialchars($tourReviewSummary, ENT_QUOTES, 'UTF-8') ?></span>
                  </div>
                  <div class="recent-meta">
                    <span class="recent-card-status">Popular choice</span>
                    <span class="recent-price"><small>Starting from</small><strong><?= $tourPrice > 0 ? '&#8369;' . number_format($tourPrice) : 'View details' ?></strong></span>
                  </div>
                  <span class="recent-view-link">View details <i aria-hidden="true">→</i></span>
                </div>
              </a>
            </article>
          <?php endforeach; ?>
          <?php if (!$popularTours): ?>
            <article class="recent-card recent-card--empty">
              <a href="hotel_resorts.php?tab=tours"><div class="recent-copy"><h3>Discover Mercedes tours</h3><p>Explore available island experiences and local packages.</p><div class="recent-meta"><span>Start exploring</span><strong>View tours</strong></div></div></a>
            </article>
          <?php endif; ?>
        </div>
      </div>
    </section>

    <section class="tour-categories" aria-labelledby="tourCategoriesTitle" data-home-reveal>
      <div class="section-shell tc-panel">
        <div class="tc-heading">
          <span class="section-kicker">Find your kind of escape</span>
          <h2 id="tourCategoriesTitle">Tour categories</h2>
          <p>Pick a mood and discover a Mercedes experience made for it.</p>
        </div>

        <div class="tc-viewport" data-tour-category-viewport>
          <div class="tc-track">
            <a class="tc-card is-active" href="hotel_resorts.php?tab=tours" data-tour-category-card>
              <span class="tc-card-media"><img src="imagess/Apuao Grande.jpg" alt="Aerial view of Apuao Grande island" loading="lazy"><span class="tc-card-number">01</span><span class="tc-card-icon" aria-hidden="true">&#9965;</span></span>
              <strong>Island hopping</strong><small>See curated tours <span aria-hidden="true">&rarr;</span></small>
            </a>
            <a class="tc-card" href="destination.php" data-tour-category-card>
              <span class="tc-card-media"><img src="imagess/Caringo.jpg" alt="Clear coastal water and beach at Caringo Island" loading="lazy"><span class="tc-card-number">02</span><span class="tc-card-icon" aria-hidden="true">&#9728;</span></span>
              <strong>Beach escapes</strong><small>Find a quiet shore <span aria-hidden="true">&rarr;</span></small>
            </a>
            <a class="tc-card" href="destination.php" data-tour-category-card>
              <span class="tc-card-media"><img src="imagess/Canimog.jpg" alt="Lush natural scenery on Canimog Island" loading="lazy"><span class="tc-card-number">03</span><span class="tc-card-icon" aria-hidden="true">&#10047;</span></span>
              <strong>Nature discoveries</strong><small>Explore island life <span aria-hidden="true">&rarr;</span></small>
            </a>
            <a class="tc-card" href="destination.php" data-tour-category-card>
              <span class="tc-card-media"><img src="imagess/Church.jpg" alt="Historic church and local heritage site in Mercedes" loading="lazy"><span class="tc-card-number">04</span><span class="tc-card-icon" aria-hidden="true">&#9670;</span></span>
              <strong>Culture &amp; heritage</strong><small>Meet local stories <span aria-hidden="true">&rarr;</span></small>
            </a>
            <a class="tc-card" href="hotel_resorts.php?tab=boats" data-tour-category-card>
              <span class="tc-card-media"><img src="imagess/Quinapaguian.jpg" alt="Blue water surrounding Quinapaguian Island" loading="lazy"><span class="tc-card-number">05</span><span class="tc-card-icon" aria-hidden="true">&#9875;</span></span>
              <strong>Boat adventures</strong><small>Choose your ride <span aria-hidden="true">&rarr;</span></small>
            </a>
          </div>
        </div>

        <div class="tc-controls" aria-label="Tour category controls">
          <button class="tc-arrow" type="button" data-tour-category-direction="-1" aria-label="Previous tour category">&larr;</button>
          <div class="tc-dots" role="tablist" aria-label="Choose a tour category">
            <?php for ($categoryIndex = 0; $categoryIndex < 5; $categoryIndex++): ?>
              <button type="button" class="<?= $categoryIndex === 0 ? 'is-active' : '' ?>" data-tour-category-index="<?= $categoryIndex ?>" aria-label="Show tour category <?= $categoryIndex + 1 ?>" aria-selected="<?= $categoryIndex === 0 ? 'true' : 'false' ?>"></button>
            <?php endfor; ?>
          </div>
          <button class="tc-arrow" type="button" data-tour-category-direction="1" aria-label="Next tour category">&rarr;</button>
        </div>
      </div>
    </section>

    <section class="home-stats booking-stats" aria-labelledby="bookingStatsTitle" data-home-reveal>
      <div class="section-shell home-stats-shell">
        <div class="booking-stats-heading">
          <div>
            <span class="section-kicker">Plan with confidence</span>
            <h2 id="bookingStatsTitle">One local platform. More ways to experience Mercedes.</h2>
          </div>
          <div class="booking-stats-intro">
            <p>Bring the essentials of your trip together—from island tours and trusted guides to boats and coastal stays.</p>
            <a href="hotel_resorts.php?tab=tours">Explore booking options <span aria-hidden="true">&rarr;</span></a>
          </div>
        </div>

        <dl class="home-stats-grid">
          <div class="home-stat">
            <div class="booking-stat-top"><span class="booking-stat-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7" rx="2"></rect><rect x="14" y="3" width="7" height="7" rx="2"></rect><rect x="3" y="14" width="7" height="7" rx="2"></rect><rect x="14" y="14" width="7" height="7" rx="2"></rect></svg></span><span class="booking-stat-spark" aria-hidden="true"><svg viewBox="0 0 76 30"><polyline points="2,25 16,19 27,22 42,11 54,15 72,4"></polyline><path d="m65 4 7 0 0 7"></path></svg></span></div>
            <dt><strong>4</strong><span>Ways to book</span></dt><dd>Tours, guides, boats, and stays</dd>
            <span class="booking-stat-trend"><i aria-hidden="true">&nearr;</i> More ways to plan</span>
          </div>
          <div class="home-stat">
            <div class="booking-stat-top"><span class="booking-stat-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M20 10c0 5-8 11-8 11S4 15 4 10a8 8 0 1 1 16 0Z"></path><circle cx="12" cy="10" r="2.5"></circle></svg></span><span class="booking-stat-spark" aria-hidden="true"><svg viewBox="0 0 76 30"><polyline points="2,24 14,17 27,19 39,13 51,14 72,3"></polyline><path d="m65 3 7 0 0 7"></path></svg></span></div>
            <dt><strong>7<span>+</span></strong><span>Island destinations</span></dt><dd>More shores and stories to discover</dd>
            <span class="booking-stat-trend"><i aria-hidden="true">&nearr;</i> More places to explore</span>
          </div>
          <div class="home-stat">
            <div class="booking-stat-top"><span class="booking-stat-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M12 3 4 7v5c0 5 3.4 8 8 9 4.6-1 8-4 8-9V7l-8-4Z"></path><path d="m9 12 2 2 4-5"></path></svg></span><span class="booking-stat-spark" aria-hidden="true"><svg viewBox="0 0 76 30"><polyline points="2,25 13,22 25,14 39,18 53,8 72,4"></polyline><path d="m65 4 7 0 0 7"></path></svg></span></div>
            <dt><strong>Local</strong><span>Tourism support</span></dt><dd>Built around Mercedes communities</dd>
            <span class="booking-stat-trend"><i aria-hidden="true">&nearr;</i> Community-led travel</span>
          </div>
          <div class="home-stat">
            <div class="booking-stat-top"><span class="booking-stat-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M5 12.5 10 17 19 7"></path><circle cx="12" cy="12" r="9"></circle></svg></span><span class="booking-stat-spark" aria-hidden="true"><svg viewBox="0 0 76 30"><polyline points="2,24 15,25 27,17 41,19 54,10 72,3"></polyline><path d="m65 3 7 0 0 7"></path></svg></span></div>
            <dt><strong>1</strong><span>Easy planning hub</span></dt><dd>Your trip essentials in one place</dd>
            <span class="booking-stat-trend"><i aria-hidden="true">&nearr;</i> One connected journey</span>
          </div>
        </dl>
      </div>
    </section>

    <section class="fe-featured" aria-labelledby="featuredTitle" data-home-reveal>
      <div class="section-shell fe-container">
        <div class="fe-top">
          <div class="fe-video">
            <video src="<?= htmlspecialchars($videoPath ?: 'img/samplevideo.mp4', ENT_QUOTES, 'UTF-8') ?>" autoplay muted loop controls playsinline preload="metadata"></video>
            <span class="fe-video-label">A glimpse of Mercedes</span>
          </div>

          <div class="fe-text">
            <span class="section-kicker">Made for meaningful escapes</span>
            <h2 id="featuredTitle">More than a destination. It’s a story worth experiencing.</h2>
            <p><?= htmlspecialchars($description1 ?: 'Experience the natural beauty, welcoming communities, and unhurried island life of Mercedes.', ENT_QUOTES, 'UTF-8') ?></p>
            <p><?= htmlspecialchars($description2 ?: 'Every trip supports local tourism and brings you closer to the people and places that make this coast special.', ENT_QUOTES, 'UTF-8') ?></p>
            <a href="about.php" class="text-link">Meet iTour Mercedes <span aria-hidden="true">→</span></a>
          </div>
        </div>

        <div class="fe-gallery-layout">
          <button class="fe-small" type="button" data-gallery-image="<?= htmlspecialchars($smallImage1, ENT_QUOTES, 'UTF-8') ?>" aria-label="Open destination photo">
            <img src="<?= htmlspecialchars($smallImage1, ENT_QUOTES, 'UTF-8') ?>" alt="A scenic destination in Mercedes" loading="lazy">
          </button>

          <div class="fe-gallery">
            <div class="fe-slider" aria-label="Mercedes photo gallery">
              <?php foreach ($sliderImages as $index => $image): ?>
                <button class="fe-slide <?= $index === 0 ? 'active' : '' ?>" type="button" style="background-image:url('<?= htmlspecialchars($image, ENT_QUOTES, 'UTF-8') ?>')" data-gallery-image="<?= htmlspecialchars($image, ENT_QUOTES, 'UTF-8') ?>" aria-label="Open gallery photo <?= $index + 1 ?>"></button>
              <?php endforeach; ?>
              <div class="fe-slider-caption"><span>Island moments</span><strong>See Mercedes up close</strong></div>
            </div>
            <div class="fe-dots" role="tablist" aria-label="Choose gallery image">
              <?php foreach ($sliderImages as $index => $image): ?>
                <button type="button" class="<?= $index === 0 ? 'active' : '' ?>" data-slide-index="<?= $index ?>" aria-label="Show photo <?= $index + 1 ?>"></button>
              <?php endforeach; ?>
            </div>
          </div>

          <button class="fe-small" type="button" data-gallery-image="<?= htmlspecialchars($smallImage2, ENT_QUOTES, 'UTF-8') ?>" aria-label="Open destination photo">
            <img src="<?= htmlspecialchars($smallImage2, ENT_QUOTES, 'UTF-8') ?>" alt="A coastal experience in Mercedes" loading="lazy">
          </button>
        </div>

        <?php if ($footerText !== ''): ?>
          <p class="fe-footer-text"><?= htmlspecialchars($footerText, ENT_QUOTES, 'UTF-8') ?></p>
        <?php endif; ?>
      </div>
    </section>

    <section class="booking-process" aria-labelledby="bookingProcessTitle" data-home-reveal>
      <div class="section-shell">
        <div class="section-heading section-heading--center">
          <div>
            <span class="section-kicker">Simple and transparent</span>
            <h2 id="bookingProcessTitle">Your trip, booked in four easy steps</h2>
          </div>
          <p class="booking-process-copy">Send a request and stay informed while our team confirms the details.</p>
        </div>
        <div class="bp-steps">
          <article class="bp-step"><span class="bp-circle">01</span><h3>Login or sign up</h3><p>Create an account so your requests and updates stay secure.</p></article>
          <article class="bp-step"><span class="bp-circle">02</span><h3>Submit your booking</h3><p>Choose your dates and share the trip details that matter.</p></article>
          <article class="bp-step"><span class="bp-circle">03</span><h3>We review it</h3><p>Our team checks availability and confirms your request.</p></article>
          <article class="bp-step"><span class="bp-circle">04</span><h3>Get your update</h3><p>Receive a notification as soon as your booking is decided.</p></article>
        </div>
      </div>
    </section>

    <section class="dest-section" aria-labelledby="destinationTitle" data-home-reveal>
      <div class="section-shell dest-container">
        <div class="dest-collage">
          <svg class="dest-route-trail" viewBox="0 0 650 520" preserveAspectRatio="none" aria-hidden="true">
            <path d="M38 410C88 372 28 323 66 276C105 228 45 170 99 125C155 78 215 91 259 50C306 7 370 69 421 38C474 7 542 55 558 111C575 169 625 183 588 243C550 305 632 326 577 386C533 434 466 405 421 455"></path>
          </svg>
          <span class="dest-floating-boat dest-floating-boat--one" aria-hidden="true">
            <svg viewBox="0 0 42 42"><path d="M7 24.5h28l-5.6 8.2H12.5L7 24.5Zm10.8-15 10.7 12H17.8v-12Zm-2.7 2.3v9.7H8.8l6.3-9.7Z"></path><path class="boat-wave" d="M7 35c3 2 5 2 8 0 3 2 5 2 8 0 3 2 5 2 8 0"></path></svg>
          </span>
          <span class="dest-floating-boat dest-floating-boat--two" aria-hidden="true">
            <svg viewBox="0 0 42 42"><path d="M7 24.5h28l-5.6 8.2H12.5L7 24.5Zm10.8-15 10.7 12H17.8v-12Zm-2.7 2.3v9.7H8.8l6.3-9.7Z"></path><path class="boat-wave" d="M7 35c3 2 5 2 8 0 3 2 5 2 8 0 3 2 5 2 8 0"></path></svg>
          </span>
          <img class="dest-image-main" src="imagess/Apuao Grande_header-img.png" alt="Aerial view of an island in Mercedes" loading="lazy">
          <img class="dest-image-small" src="imagess/Caringo_header-img.png" alt="Clear coastal water in Mercedes" loading="lazy">
          <div class="dest-note"><strong>Nature feels closer here</strong><span>Beaches · islands · local culture</span></div>
        </div>
        <div class="dest-text">
          <span class="section-kicker">Explore Mercedes</span>
          <h2 id="destinationTitle">Small islands. Big reasons to stay awhile.</h2>
          <p>Discover tranquil beaches, nature trails, historic landmarks, and welcoming communities across Mercedes. Whether you want a slow morning by the shore or a full day on the water, your next favorite memory is closer than you think.</p>
          <ul class="dest-points">
            <li><span>✓</span> Curated island destinations</li>
            <li><span>✓</span> Local travel partners</li>
            <li><span>✓</span> Easy trip planning</li>
          </ul>
          <a href="destination.php" class="hero-btn hero-btn--primary">Discover all destinations <span aria-hidden="true">→</span></a>
        </div>
      </div>
    </section>
  </main>

  <a class="mobile-floating-book" href="hotel_resorts.php?tab=tours" aria-label="Book a tour">
    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7.5A2.5 2.5 0 0 0 6.5 10 2.5 2.5 0 0 0 4 12.5V17a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-4.5a2.5 2.5 0 0 0 0-5V7a2 2 0 0 0-2-2H6a2 2 0 0 0-2 2v.5Z"/><path d="M14 8.5h3M14 12h3M14 15.5h2"/></svg>
    <span>Book a Tour</span>
  </a>
 
  <div id="loginModal"></div>
  <div id="feModal" class="fe-modal" role="dialog" aria-modal="true" aria-label="Gallery preview" hidden>
    <button class="fe-close" type="button" aria-label="Close image preview">×</button>
    <img class="fe-modal-content" id="feModalImg" alt="Expanded gallery view">
  </div>

  <?php include 'footer.php'; ?>

  <script>
    window.homeReviewSummaries = <?= json_encode($reviewSummaryByKey, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    window.RecentlyViewedConfig = {
      accountId: <?= json_encode((string)($_SESSION['tourist_id'] ?? '')) ?>
    };
  </script>
  <script src="js/header.js?v=<?= (int)@filemtime(__DIR__ . '/../js/header.js') ?>"></script>
  <script src="js/recently_viewed.js?v=<?= (int)@filemtime(__DIR__ . '/../js/recently_viewed.js') ?>"></script>
  <script src="js/homepage.js?v=<?= (int)@filemtime(__DIR__ . '/../js/homepage.js') ?>"></script>
  <script src="js/back-to-top.js?v=<?= (int)@filemtime(__DIR__ . '/../js/back-to-top.js') ?>"></script>
</body>
</html>
<?php exit; ?>
Masin Bildsare in a sense. There's an extension that let's be transfer the entire chat context to all clicks. I will may imagine dragons today. Because if you guys don't know, we are coming logged in this coming eight and the look is very, very minimal, very click up lock long tile I'm so excited for you guys to try our new product, our new launches coming eight, and yes, I'm a sample today. And so ready and junge to the shops garlic butters where it's coming from never answers wrong againaway I love you eight thirty three to see if Instagram and now we are here going all the way to thank you thank you for having me of course sweet the sweetness so first of all how would you describe your vaster best how would you describe more than modern to be known century how could this be pre I have to maybe binning shadows go your body looks so good how could this be pre I keep your secret tables in coing smack shale sixty kingstyns seventy nine papa six hundred logic seven money shakments he batty one switch complete on complete fifty eight on sink sharle are you know since you completely place one of the trusted draws when it comes to charge and I think this is their most affordable barround capital and damning rest of this is the underpower for ten thousand mhic up is caps vents are you in reconnect being whateverreputation restrict your show in your sword and select these lakes to stuck and make it like me and set all it's a child that you remain cause I know that's daddy is it too soon to do this cause I knowshe gets a net is a match nonetheless modification parking area mathematical extra telmogring to hindi colok is your birthday schengen shrup nesh like is this schengen visa business stole the cradle the most powerful object in the universe cups inch cheesecake which is massale may not mear casina but small bake no miss polden cugil and flour fruit mountains made legal shit all in bars as a first record sport ski late hinds to put in guys by the far flug no gay guy twenty thousand image product for banker published on its own indian phati caring polare malina startshakmost generous shows mulam canicala
