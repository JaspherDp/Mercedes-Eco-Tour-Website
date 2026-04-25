<?php
chdir(__DIR__ . '/..');
session_start();
require_once 'php/db_connection.php';
require_once 'php/hotel_rooms_helper.php';
require_once 'php/hotel_content_helper.php';
HoEnsureHotelResortContentColumns($pdo);
$hasDescriptionText = HoHotelResortsHasColumn($pdo, 'description_text');
$hasRulesJson = HoHotelResortsHasColumn($pdo, 'rules_json');
$hasGalleryImagesJson = HoHotelResortsHasColumn($pdo, 'gallery_images_json');

$descriptionSelect = $hasDescriptionText ? 'h.description_text' : "NULL AS description_text";
$rulesSelect = $hasRulesJson ? 'h.rules_json' : "NULL AS rules_json";
$gallerySelect = $hasGalleryImagesJson ? 'h.gallery_images_json' : "NULL AS gallery_images_json";

$groupByParts = ['h.hotel_resort_id', 'h.name', 'h.island', 'h.type', 'h.price', 'h.image_path', 'h.amenities_json'];
if ($hasDescriptionText) $groupByParts[] = 'h.description_text';
if ($hasRulesJson) $groupByParts[] = 'h.rules_json';
if ($hasGalleryImagesJson) $groupByParts[] = 'h.gallery_images_json';
$groupBySql = implode(', ', $groupByParts);

$hotelId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$source = isset($_GET['source']) ? trim((string)$_GET['source']) : 'featured';
$source = $source === 'result' ? 'result' : 'featured';
$isLoggedIn = isset($_SESSION['tourist_id']);
$bookingSuccess = isset($_GET['booking_success']) && $_GET['booking_success'] === '1';
$bookingRef = isset($_GET['booking_ref']) ? (int)$_GET['booking_ref'] : 0;

$stmtHotel = $pdo->prepare("
SELECT
  h.hotel_resort_id AS id,
  h.name,
  h.island,
  h.type,
  h.price,
  h.image_path AS img,
  h.amenities_json,
  {$descriptionSelect},
  {$rulesSelect},
  {$gallerySelect},
  COALESCE(ROUND(AVG(r.rating), 1), 0) AS rating,
  COUNT(r.review_id) AS total_reviews
FROM hotel_resorts h
LEFT JOIN hotel_resort_reviews r ON r.hotel_resort_id = h.hotel_resort_id
WHERE h.hotel_resort_id = ? AND h.status = 'active'
GROUP BY {$groupBySql}
LIMIT 1
");
$stmtHotel->execute([$hotelId]);
$hotel = $stmtHotel->fetch(PDO::FETCH_ASSOC);

if (!$hotel) {
    http_response_code(404);
    echo "Hotel not found.";
    exit;
}

$stmtReviews = $pdo->prepare("
SELECT reviewer_name, rating, review_message, created_at
FROM hotel_resort_reviews
WHERE hotel_resort_id = ?
ORDER BY created_at DESC
LIMIT 10
");
$stmtReviews->execute([$hotelId]);
$reviews = $stmtReviews->fetchAll(PDO::FETCH_ASSOC);

$hotel['id'] = (int)$hotel['id'];
$hotel['price'] = (float)$hotel['price'];
$hotel['rating'] = (float)$hotel['rating'];
$hotel['total_reviews'] = (int)$hotel['total_reviews'];
$hotel['img'] = !empty($hotel['img']) ? $hotel['img'] : 'img/sampleimage.png';
$amenities = json_decode($hotel['amenities_json'] ?? '[]', true);
$hotel['amenities'] = is_array($amenities) ? $amenities : [];
$hotel['description_text'] = trim((string)($hotel['description_text'] ?? ''));
$hotel['gallery_images'] = HoDecodeJsonList((string)($hotel['gallery_images_json'] ?? '[]'));
if (!in_array($hotel['img'], $hotel['gallery_images'], true)) {
    array_unshift($hotel['gallery_images'], $hotel['img']);
}
$defaultGalleryFallback = [
    "https://images.unsplash.com/photo-1566073771259-6a8506099945?auto=format&fit=crop&w=1400&q=80",
    "https://images.unsplash.com/photo-1551882547-ff40c63fe5fa?auto=format&fit=crop&w=1400&q=80",
    "https://images.unsplash.com/photo-1582719478250-c89cae4dc85b?auto=format&fit=crop&w=1400&q=80",
    "https://images.unsplash.com/photo-1578683010236-d716f9a3f461?auto=format&fit=crop&w=1400&q=80",
    "https://images.unsplash.com/photo-1520250497591-112f2f40a3f4?auto=format&fit=crop&w=1400&q=80",
    "https://images.unsplash.com/photo-1590490360182-c33d57733427?auto=format&fit=crop&w=1400&q=80",
    "https://images.unsplash.com/photo-1590490360182-c33d57733427?auto=format&fit=crop&w=1400&q=80",
    "https://images.unsplash.com/photo-1468824357306-a439d58ccb1c?auto=format&fit=crop&w=1400&q=80",
    "https://images.unsplash.com/photo-1519821172141-b5d8a96dfec8?auto=format&fit=crop&w=1400&q=80",
    "https://images.unsplash.com/photo-1584132967334-10e028bd69f7?auto=format&fit=crop&w=1400&q=80"
];
$hotel['gallery_images'] = array_values(array_unique(array_merge($hotel['gallery_images'], $defaultGalleryFallback)));
unset($hotel['amenities_json']);

$roomRows = HoGetHotelRooms($pdo, $hotel['id'], true);
$lowestRoomPrice = null;
foreach ($roomRows as $roomRow) {
    $roomPrice = (float)$roomRow['price'];
    if ($lowestRoomPrice === null || $roomPrice < $lowestRoomPrice) {
        $lowestRoomPrice = $roomPrice;
    }
}
$displayBasePrice = $lowestRoomPrice !== null ? (float)$lowestRoomPrice : (float)$hotel['price'];

function amenityIconSvg(string $amenity): string {
    $name = strtolower($amenity);

    if (strpos($name, 'pool') !== false) {
        return '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M2 18c1.5 0 1.5-.8 3-.8s1.5.8 3 .8 1.5-.8 3-.8 1.5.8 3 .8 1.5-.8 3-.8 1.5.8 3 .8v2c-1.5 0-1.5-.8-3-.8s-1.5.8-3 .8-1.5-.8-3-.8-1.5.8-3 .8-1.5-.8-3-.8-1.5.8-3 .8v-2Zm6-3V7a4 4 0 1 1 8 0v8h-2V7a2 2 0 1 0-4 0v8H8Z"></path></svg>';
    }
    if (strpos($name, 'wifi') !== false || strpos($name, 'wi-fi') !== false) {
        return '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 18a1.8 1.8 0 1 0 0 3.6A1.8 1.8 0 0 0 12 18Zm0-4a6.8 6.8 0 0 0-4.8 2l1.4 1.4a4.8 4.8 0 0 1 6.8 0l1.4-1.4A6.8 6.8 0 0 0 12 14Zm0-4a11 11 0 0 0-7.8 3.2l1.4 1.4a9 9 0 0 1 12.8 0l1.4-1.4A11 11 0 0 0 12 10Zm0-4A15.2 15.2 0 0 0 1.2 6.5l1.4 1.4a13.2 13.2 0 0 1 18.8 0l1.4-1.4A15.2 15.2 0 0 0 12 6Z"></path></svg>';
    }
    if (strpos($name, 'restaurant') !== false || strpos($name, 'food') !== false || strpos($name, 'breakfast') !== false) {
        return '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 3v7a3 3 0 0 0 3 3v8h2v-8a3 3 0 0 0 3-3V3h-2v4H8V3H6v4H4V3H2Zm13 0a4 4 0 0 0-4 4v7h4v7h2V3h-2Z"></path></svg>';
    }
    if (strpos($name, 'beach') !== false || strpos($name, 'snorkel') !== false || strpos($name, 'kayak') !== false) {
        return '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M2 19h20v2H2v-2Zm3-3.5c1.4-3 3.8-4.5 7-4.5s5.6 1.5 7 4.5h-2.2c-1.1-1.6-2.8-2.5-4.8-2.5s-3.7.9-4.8 2.5H5Zm8.5-10.8L17 8.2l-1.4 1.4-2.1-2.1-2.1 2.1L10 8.2l3.5-3.5Z"></path></svg>';
    }
    if (strpos($name, 'event') !== false || strpos($name, 'hall') !== false) {
        return '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 4h14a2 2 0 0 1 2 2v14H3V6a2 2 0 0 1 2-2Zm0 4v10h14V8H5Zm2 2h4v2H7v-2Zm0 3h10v2H7v-2Z"></path></svg>';
    }
    return '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 5h14a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V7a2 2 0 0 1 2-2Zm2 3v8h10V8H7Z"></path></svg>';
}

function reviewRatingLabel(float $rating): string {
    if ($rating >= 4.8) return 'Exceptional';
    if ($rating >= 4.5) return 'Excellent';
    if ($rating >= 4.0) return 'Fantastic';
    if ($rating >= 3.5) return 'Very Good';
    if ($rating >= 3.0) return 'Good';
    return 'Needs Improvement';
}

function reviewStarClass(int $index, float $avg): string {
    if ($index <= floor($avg)) return 'filled';
    if (($index - $avg) < 1 && $index > $avg) return 'half';
    return '';
}

$mapAddress = 'Palms Farm Resort, Cayucyucan, Mercedes, Camarines Norte';
$isPalmsFarm = strcasecmp($hotel['name'], 'Palms Farm Resort') === 0;
if ($isPalmsFarm) {
    $mapAddress = 'Palms Farm Resort, Cayucyucan, Mercedes, Camarines Norte';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title><?= htmlspecialchars($hotel['name']) ?> | iTour Mercedes</title>
  <link rel="icon" type="image/png" href="img/newlogo.png" />
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css" />
  <link rel="stylesheet" href="styles/hotel_details.css" />
  <style>
    /* hard-match hotel_resorts search bar + calendar on hotel_details */
    #detailsSearchWrap {
      padding: 4px 150px !important;
    }
    #detailsSearchWrap .search-container {
      background: #fff !important;
      padding: 7px 16px !important;
      border-radius: 18px !important;
      width: 100% !important;
      max-width: 100% !important;
      margin: 0 auto !important;
      display: flex !important;
      gap: 10px !important;
      align-items: flex-start !important;
      flex-wrap: nowrap !important;
      box-shadow: none !important;
      border: none !important;
      overflow: visible !important;
    }
    #detailsSearchWrap .guest-box {
      z-index: 120 !important;
    }
    #detailsSearchWrap .search-box {
      display: flex !important;
      flex-direction: column !important;
      gap: 4px !important;
      position: relative !important;
      flex: 1 !important;
      min-width: 0 !important;
      min-height: 14px !important;
    }
    #detailsSearchWrap .search-box label {
      display: none !important;
    }
    #detailsSearchWrap .search-box input,
    #detailsSearchWrap .search-box select,
    #detailsSearchWrap .guest-display {
      width: 100% !important;
      padding: 8px 10px !important;
      border-radius: 10px !important;
      border: 1px solid #e3e7ea !important;
      background: #fff !important;
      font-size: 14px !important;
      color: #111111 !important;
    }
    #detailsSearchWrap .location-search-box select { padding-right: 58px !important; }
    #detailsSearchWrap .search-btn {
      background: #2b7a66 !important; color: #fff !important; border: none !important;
      padding: 9px 18px !important; border-radius: 10px !important; cursor: pointer !important;
      flex: 0 0 130px !important; height: 38px !important; white-space: nowrap !important; margin-top: 0 !important; font-weight: 600 !important;
      display: inline-flex !important; align-items: center !important; justify-content: center !important;
    }
    #detailsSearchWrap .search-btn:hover { background: #144d1c !important; }
    #detailsSearchWrap .input-error { border: 1px solid #dc3545 !important; background: #fff5f5 !important; }
    #detailsSearchWrap .error-text { font-size: 12px !important; color: #dc3545 !important; margin-top: 4px !important; }
    #detailsSearchWrap .flatpickr-calendar {
      border-radius: 14px !important; box-shadow: 0 16px 36px rgba(0,0,0,0.16) !important; border: 1px solid #e5ece8 !important;
      z-index: 900 !important; margin-top: 8px !important; padding: 10px 10px 8px !important;
      width: max-content !important; min-width: 960px !important; max-width: none !important; overflow: visible !important;
    }
    #detailsSearchWrap .flatpickr-day.selected,
    #detailsSearchWrap .flatpickr-day.startRange,
    #detailsSearchWrap .flatpickr-day.endRange { background: #2b7a66 !important; border-color: #2b7a66 !important; }
    #detailsSearchWrap .flatpickr-day.inRange { background: #e8f2ec !important; border-color: #e8f2ec !important; color: #173826 !important; }
    @media (max-width: 900px) {
      #detailsSearchWrap .flatpickr-calendar,
      #detailsSearchWrap .flatpickr-calendar.inline,
      #detailsSearchWrap .flatpickr-calendar.open { min-width: 960px !important; max-width: none !important; }
    }
    @media (max-width: 768px) {
      #detailsSearchWrap { padding: 4px 10px !important; }
      #detailsSearchWrap .search-container { width: 100% !important; max-width: 100% !important; padding: 10px !important; gap: 10px !important; flex-direction: column !important; }
    }

  </style>
</head>
<body>
  <div id="header"></div>

  <section class="details-search-wrap" id="detailsSearchWrap">
    <div class="search-container">
      <div class="search-box location-search-box">
        <label>Where to go?</label>
        <div class="location-input-wrap">
          <select id="island">
            <option value="" selected disabled>--select a location--</option>
            <option value="Apuao">Apuao</option>
            <option value="Malasugui">Malasugui</option>
            <option value="Quinapaguian">Quinapaguian</option>
            <option value="Cayucyucan">Cayucyucan</option>
          </select>
        </div>
      </div>
      <div class="search-box">
        <label>Check-in / Check-out</label>
        <div class="date-input-wrap">
          <input type="text" id="dateRangePicker" placeholder="Select stay dates" readonly />
          <span id="stayDurationBadge" class="stay-duration-badge" hidden></span>
        </div>
        <input type="hidden" id="checkin" />
        <input type="hidden" id="checkout" />
      </div>
      <div class="search-box">
        <label>Guests & Rooms</label>
        <div class="guest-display" onclick="toggleGuestBox()">
          <span id="guestText" class="placeholder">Guests & Rooms</span>
        </div>
        <div id="guestBox" class="guest-box">
          <div class="guest-row">
            <span>Adults</span>
            <div>
              <button type="button" onclick="changeValue('adults', -1)">-</button>
              <span id="adults">0</span>
              <button type="button" onclick="changeValue('adults', 1)">+</button>
            </div>
          </div>
          <div class="guest-row">
            <span>Children</span>
            <div>
              <button type="button" onclick="changeValue('children', -1)">-</button>
              <span id="children">0</span>
              <button type="button" onclick="changeValue('children', 1)">+</button>
            </div>
          </div>
          <div class="guest-row">
            <span>Rooms</span>
            <div>
              <button type="button" onclick="changeValue('roomsCount', -1)">-</button>
              <span id="roomsCount">0</span>
              <button type="button" onclick="changeValue('roomsCount', 1)">+</button>
            </div>
          </div>
          <div class="guest-actions">
            <button type="button" class="done-btn" onclick="applyGuestSelection()">Done</button>
          </div>
        </div>
      </div>
      <button id="searchActionBtn" class="search-btn" type="button">Search Room</button>
    </div>
  </section>

  <main class="hotel-details-page">
    <nav class="hotel-tabs" id="hotelTabs">
      <a href="#overview" class="active">Overview</a>
      <a href="#rooms">Rooms</a>
      <a href="#facilities">Facilities</a>
      <a href="#rules">Rules</a>
      <a href="#reviews">Reviews</a>
    </nav>

    <section id="overview" class="detail-section">
      <div class="overview-top-row">
        <div class="overview-title-wrap">
          <h1 class="overview-title"><?= htmlspecialchars($hotel['name']) ?></h1>
          <p class="overview-subtitle"><?= htmlspecialchars($hotel['island']) ?> • <?= htmlspecialchars(strtoupper($hotel['type'])) ?></p>
        </div>
        <div class="overview-actions">
          <div class="overview-actions-top">
            <p class="overview-price">As low as <strong>₱<?= number_format($displayBasePrice, 0) ?></strong>/night</p>
            <button type="button" class="overview-reserve-btn" id="reserveOverviewBtn">Reserve Room</button>
          </div>
        </div>
      </div>
      <div class="overview-location-row">
        <div class="overview-location-text">
          <span class="location-pin" aria-hidden="true"></span>
          <p><?= htmlspecialchars($mapAddress) ?></p>
        </div>
        <div class="overview-actions-bottom">
          <button type="button" class="icon-action-btn" aria-label="Add to favorites" title="Add to favorites">
            <svg viewBox="0 0 24 24" aria-hidden="true">
              <path d="M12 20.4c-5.2-3.4-8.4-6.2-8.4-10a4.8 4.8 0 0 1 8.4-3.1 4.8 4.8 0 0 1 8.4 3.1c0 3.8-3.2 6.6-8.4 10Z"></path>
            </svg>
          </button>
          <button type="button" class="icon-action-btn" aria-label="Share this hotel" title="Share this hotel">
            <svg viewBox="0 0 24 24" aria-hidden="true">
              <circle cx="18" cy="5" r="2.3"></circle>
              <circle cx="6" cy="12" r="2.3"></circle>
              <circle cx="18" cy="19" r="2.3"></circle>
              <path d="M8.1 11.1 15.8 6.9M8.1 12.9l7.7 4.2"></path>
            </svg>
          </button>
        </div>
      </div>
      <div class="overview-grid">
        <div class="photo-gallery">
          <div class="photo-main">
            <img id="mainPhoto" alt="<?= htmlspecialchars($hotel['name']) ?>" />
          </div>
          <div class="photo-thumbs" id="photoThumbs"></div>
        </div>
        <aside class="overview-side-column">
          <div class="overview-side-photo">
            <img id="sidePhoto" alt="<?= htmlspecialchars($hotel['name']) ?> side view" />
          </div>
          <div class="map-card">
            <div class="map-preview">
              <iframe
                id="mapPreviewFrame"
                title="Hotel location preview"
                loading="lazy"
                referrerpolicy="no-referrer-when-downgrade"
                src="">
              </iframe>
              <button type="button" id="openMapBtn">Show on map</button>
            </div>
          </div>
        </aside>
      </div>
      <div class="overview-info-grid">
        <article class="overview-description-card">
          <?php if ($hotel['description_text'] !== ''): ?>
            <?php foreach (preg_split('/\r\n|\r|\n/', $hotel['description_text']) as $descLine): ?>
              <?php $descLine = trim((string)$descLine); if ($descLine === '') continue; ?>
              <p><?= htmlspecialchars($descLine) ?></p>
            <?php endforeach; ?>
          <?php else: ?>
            <p>
              <?= htmlspecialchars($hotel['name']) ?> is a welcoming <?= htmlspecialchars(strtolower($hotel['type'])) ?> in
              <?= htmlspecialchars($hotel['island']) ?>, Mercedes, designed for guests who want a comfortable and relaxing stay.
            </p>
            <p>
              The property offers convenient access to nearby coastal attractions and local dining spots, making it ideal for both short vacations and longer getaways.
            </p>
            <p>
              Each room is prepared with essential comforts, and shared spaces are arranged to support a calm and enjoyable island experience throughout your visit.
            </p>
            <p>
              Whether you are traveling as a couple, with family, or with friends, this stay provides a balanced mix of comfort, accessibility, and local charm.
            </p>
            <ul class="overview-description-points">
              <li>Close to beach areas and local points of interest</li>
              <li>Suitable for weekend trips, family holidays, and group stays</li>
              <li>Friendly service with practical amenities for a hassle-free stay</li>
            </ul>
          <?php endif; ?>
        </article>
        <aside class="overview-amenities-card">
          <h3>Amenities</h3>
          <?php if (!empty($hotel['amenities'])): ?>
            <div class="overview-amenities-list">
              <?php foreach ($hotel['amenities'] as $amenity): ?>
                <span><?= htmlspecialchars($amenity) ?></span>
              <?php endforeach; ?>
            </div>
          <?php else: ?>
            <p class="dummy-text">Amenities information will be updated soon.</p>
          <?php endif; ?>
        </aside>
      </div>
    </section>

    <section id="rooms" class="detail-section">
      <h2>Rooms</h2>
      <div id="roomsContent"></div>
    </section>

    <section id="facilities" class="detail-section">
      <h2>Facilities</h2>
      <div class="pill-wrap">
        <?php foreach ($hotel['amenities'] as $amenity): ?>
          <span class="pill">
            <span class="facility-icon"><?= amenityIconSvg((string)$amenity) ?></span>
            <span class="facility-label"><?= htmlspecialchars($amenity) ?></span>
          </span>
        <?php endforeach; ?>
      </div>
    </section>

    <section id="rules" class="detail-section">
      <h2>Rules</h2>
      <?php $rulesList = HoDecodeJsonList((string)($hotel['rules_json'] ?? '[]')); ?>
      <?php if (!empty($rulesList)): ?>
        <ul class="rules-list">
          <?php foreach ($rulesList as $rule): ?>
            <li><?= htmlspecialchars($rule) ?></li>
          <?php endforeach; ?>
        </ul>
      <?php else: ?>
        <ul class="rules-list">
          <li>Check-in starts at 2:00 PM.</li>
          <li>Check-out is until 12:00 PM.</li>
          <li>At least 1-night stay is required.</li>
        </ul>
      <?php endif; ?>
    </section>

    <section id="reviews" class="detail-section">
      <h2>Reviews</h2>
      <div class="reviews-head">
        <p class="reviews-score">
          <strong><?= number_format((float)$hotel['rating'], 1) ?></strong>
        </p>
        <p class="reviews-total">/5 (<?= number_format((int)$hotel['total_reviews']) ?>) Total Reviews</p>
      </div>
      <div class="reviews-carousel <?= count($reviews) <= 2 ? 'is-static' : '' ?>">
        <button type="button" class="reviews-nav prev" id="reviewsPrev" aria-label="Previous reviews">&#8249;</button>
        <div class="reviews-track" id="reviewsTrack">
          <?php if (!empty($reviews)): ?>
            <?php foreach ($reviews as $review): ?>
              <?php
                $name = trim((string)($review['reviewer_name'] ?? '')) ?: 'Guest';
                $rating = (float)($review['rating'] ?? 0);
                $dateText = '';
                try {
                    $dateText = (new DateTime((string)$review['created_at']))->format('d M Y');
                } catch (Exception $e) {
                    $dateText = '';
                }
              ?>
              <article class="review-card">
                <header class="review-card-head">
                  <div class="review-user">
                    <span class="review-avatar"><?= htmlspecialchars(strtoupper(substr($name, 0, 1))) ?></span>
                    <strong><?= htmlspecialchars($name) ?></strong>
                  </div>
                  <span class="review-date"><?= $dateText ? 'Reviewed: ' . htmlspecialchars($dateText) : '' ?></span>
                </header>
                <p class="review-rating-line">
                  <span class="review-stars" aria-label="Rating: <?= number_format($rating, 1) ?> out of 5">
                    <?php for ($i = 1; $i <= 5; $i++): ?>
                      <span class="star <?= reviewStarClass($i, $rating) ?>">★</span>
                    <?php endfor; ?>
                  </span>
                  <span class="review-rating-text"><?= number_format($rating, 1) ?>/5.0</span>
                  <em><?= htmlspecialchars(reviewRatingLabel($rating)) ?></em>
                </p>
                <p class="review-message"><?= htmlspecialchars((string)($review['review_message'] ?? '')) ?></p>
              </article>
            <?php endforeach; ?>
          <?php else: ?>
            <article class="review-card">
              <p class="dummy-text">No reviews yet for this hotel.</p>
            </article>
          <?php endif; ?>
        </div>
        <button type="button" class="reviews-nav next" id="reviewsNext" aria-label="Next reviews">&#8250;</button>
      </div>
    </section>
  </main>

  <div id="galleryModal" class="modal-backdrop">
    <div class="modal-card gallery-modal-card">
      <button type="button" class="modal-close" id="closeGalleryModal" aria-label="Close gallery">&times;</button>
      <button type="button" class="gallery-nav prev" id="galleryPrev" aria-label="Previous image">&#8249;</button>
      <img id="galleryModalImage" alt="Hotel gallery image" />
      <button type="button" class="gallery-nav next" id="galleryNext" aria-label="Next image">&#8250;</button>
      <p id="galleryCounter"></p>
    </div>
  </div>

  <div id="mapModal" class="modal-backdrop">
    <div class="modal-card map-modal-card">
      <button type="button" class="modal-close" id="closeMapModal" aria-label="Close map">&times;</button>
      <h3>Map View</h3>
      <div class="map-embed-wrap">
        <iframe
          id="mapFrame"
          title="Hotel location map"
          loading="lazy"
          referrerpolicy="no-referrer-when-downgrade"
          src="">
        </iframe>
      </div>
    </div>
  </div>

  <script src="js/header.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
  <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
  <script>
    const hotelData = <?= json_encode($hotel, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    const pageSource = <?= json_encode($source) ?>;
    const isLoggedIn = <?= $isLoggedIn ? 'true' : 'false' ?>;
    const bookingSuccess = <?= $bookingSuccess ? 'true' : 'false' ?>;
    const mapAddress = <?= json_encode($mapAddress) ?>;
    const galleryImages = <?= json_encode(array_values((array)$hotel['gallery_images']), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    const dateRangeInput = document.getElementById("dateRangePicker");
    const stayDurationBadge = document.getElementById("stayDurationBadge");
    const checkinInput = document.getElementById("checkin");
    const checkoutInput = document.getElementById("checkout");
    const islandInput = document.getElementById("island");
    const searchActionBtn = document.getElementById("searchActionBtn");
    const searchWrap = document.getElementById("detailsSearchWrap");
    const roomsContent = document.getElementById("roomsContent");
    const hotelSearchStorageKey = `hotelDetailsSearchData:${hotelData.id}`;
    const hotelSearchDraftStorageKey = `hotelDetailsSearchDraft:${hotelData.id}`;
    const legacyHotelSearchStorageKey = "hotelDetailsSearchData";
    const resortsSearchStorageKey = "searchData";
    const mainPhoto = document.getElementById("mainPhoto");
    const sidePhoto = document.getElementById("sidePhoto");
    const thumbsWrap = document.getElementById("photoThumbs");
    const reserveOverviewBtn = document.getElementById("reserveOverviewBtn");
    let activeFilteredRooms = [];
    let roomSearchRequestId = 0;

    const galleryModal = document.getElementById("galleryModal");
    const galleryModalImage = document.getElementById("galleryModalImage");
    const galleryCounter = document.getElementById("galleryCounter");
    const galleryPrev = document.getElementById("galleryPrev");
    const galleryNext = document.getElementById("galleryNext");
    const closeGalleryModal = document.getElementById("closeGalleryModal");

    const mapModal = document.getElementById("mapModal");
    const mapFrame = document.getElementById("mapFrame");
    const mapPreviewFrame = document.getElementById("mapPreviewFrame");
    const openMapBtn = document.getElementById("openMapBtn");
    const closeMapModal = document.getElementById("closeMapModal");

    let currentGalleryIndex = 0;
    let activeGalleryImages = galleryImages;
    const formatRangeLabel = (start, end) => {
      const options = { month: "short", day: "numeric" };
      const startText = start.toLocaleDateString("en-US", options);
      const endText = end.toLocaleDateString("en-US", options);
      return `${startText} — ${endText}`;
    };

    const updateStayDurationBadge = (start, end) => {
      if (!stayDurationBadge || !dateRangeInput) return;
      if (!(start instanceof Date) || !(end instanceof Date)) {
        stayDurationBadge.hidden = true;
        stayDurationBadge.textContent = "";
        dateRangeInput.classList.remove("has-duration");
        return;
      }

      const startDate = new Date(start);
      const endDate = new Date(end);
      startDate.setHours(0, 0, 0, 0);
      endDate.setHours(0, 0, 0, 0);

      const nights = Math.round((endDate - startDate) / 86400000);
      if (nights <= 0) {
        stayDurationBadge.hidden = true;
        stayDurationBadge.textContent = "";
        dateRangeInput.classList.remove("has-duration");
        return;
      }

      const days = nights + 1;
      stayDurationBadge.textContent = `${days} day${days !== 1 ? "s" : ""} • ${nights} night${nights !== 1 ? "s" : ""}`;
      stayDurationBadge.hidden = false;
      dateRangeInput.classList.add("has-duration");
    };

    function setActiveTabByScroll() {
      const links = [...document.querySelectorAll("#hotelTabs a")];
      const sections = links.map(l => document.querySelector(l.getAttribute("href")));
      const tabs = document.getElementById("hotelTabs");
      const stickyTop = Number(tabs?.dataset?.stickyTop || 140);
      const stickyOffset = stickyTop + (tabs?.offsetHeight || 0) + 8;
      const probeLine = window.scrollY + stickyOffset + 16;

      let activeIndex = 0;
      for (let i = 0; i < sections.length; i++) {
        const section = sections[i];
        if (!section) continue;
        if (probeLine >= section.offsetTop) {
          activeIndex = i;
        } else {
          break;
        }
      }

      links.forEach((link, index) => {
        link.classList.toggle("active", index === activeIndex);
      });
    }

    function updateGalleryModal() {
      galleryModalImage.src = activeGalleryImages[currentGalleryIndex];
      galleryCounter.innerText = `${currentGalleryIndex + 1} / ${activeGalleryImages.length}`;
    }

    function openGallery(index) {
      currentGalleryIndex = Math.max(0, Math.min(index, activeGalleryImages.length - 1));
      updateGalleryModal();
      galleryModal.classList.add("open");
      document.body.classList.add("no-scroll");
    }

    function openGalleryWithSet(images, index) {
      activeGalleryImages = images;
      currentGalleryIndex = Math.max(0, Math.min(index, activeGalleryImages.length - 1));
      updateGalleryModal();
      galleryModal.classList.add("open");
      document.body.classList.add("no-scroll");
    }

    function closeGallery() {
      galleryModal.classList.remove("open");
      document.body.classList.remove("no-scroll");
    }

    function moveGallery(step) {
      currentGalleryIndex = (currentGalleryIndex + step + activeGalleryImages.length) % activeGalleryImages.length;
      updateGalleryModal();
    }

    function renderGallery() {
      activeGalleryImages = galleryImages;
      mainPhoto.src = galleryImages[0];
      mainPhoto.addEventListener("click", () => openGalleryWithSet(galleryImages, 0));

      const sideImageIndex = galleryImages[1] ? 1 : 0;
      if (sidePhoto) {
        sidePhoto.src = galleryImages[sideImageIndex];
        sidePhoto.addEventListener("click", () => openGalleryWithSet(galleryImages, sideImageIndex));
      }

      const visibleThumbs = galleryImages.slice(2, 8);
      thumbsWrap.innerHTML = visibleThumbs.map((img, idx) => {
        const imageIndex = idx + 2;
        const isLastThumb = idx === visibleThumbs.length - 1;
        const overlay = isLastThumb ? `<span class="thumb-overlay"><small>See more</small></span>` : "";
        return `
          <button type="button" class="thumb-btn" data-index="${imageIndex}">
            <img src="${img}" alt="Hotel image ${imageIndex + 1}" />
            ${overlay}
          </button>
        `;
      }).join("");

      thumbsWrap.querySelectorAll(".thumb-btn").forEach(btn => {
        btn.addEventListener("click", () => {
          openGalleryWithSet(galleryImages, Number(btn.dataset.index));
        });
      });
    }

    function buildMapEmbedSrc() {
      return `https://www.google.com/maps?q=${encodeURIComponent(mapAddress)}&output=embed`;
    }

    function openMapModal() {
      mapFrame.src = buildMapEmbedSrc();
      mapModal.classList.add("open");
      document.body.classList.add("no-scroll");
    }

    function closeMap() {
      mapModal.classList.remove("open");
      mapFrame.src = "";
      document.body.classList.remove("no-scroll");
    }

    async function fetchAvailableRooms(searchData) {
      const totalGuests = Math.max(1, Number(searchData.adults || 0) + Number(searchData.children || 0));
      const params = new URLSearchParams({
        hotel_id: String(hotelData.id),
        checkin: String(searchData.checkin || ""),
        checkout: String(searchData.checkout || ""),
        guests: String(totalGuests)
      });

      const response = await fetch(`php/hotel_room_availability.php?${params.toString()}`, {
        method: "GET",
        headers: { "Accept": "application/json" }
      });
      if (!response.ok) {
        throw new Error("Failed to fetch room availability.");
      }

      const payload = await response.json();
      if (!payload || payload.success !== true || !Array.isArray(payload.rooms)) {
        throw new Error(payload?.message || "Invalid room availability response.");
      }
      return payload.rooms;
    }

    function renderRoomsFromSearch(searchData, filteredRooms) {
      activeFilteredRooms = Array.isArray(filteredRooms) ? filteredRooms : [];

      if (!activeFilteredRooms.length) {
        roomsContent.innerHTML = `<p class="dummy-text">No available room matches your selected dates and guest count.</p>`;
        return;
      }

      roomsContent.innerHTML = activeFilteredRooms.map((room, roomIndex) => {
          const roomGallery = Array.isArray(room.galleryImages) && room.galleryImages.length
            ? room.galleryImages
            : [room.mainImage || "img/sampleimage.png"];
        const firstImage = room.mainImage || roomGallery[0] || "img/sampleimage.png";
        const secondImage = roomGallery[1] || firstImage;
        const thirdImage = roomGallery[2] || secondImage;
        const roomMeta = room.meta || {};
        const roomSize = roomMeta.size ? `<p class="room-meta"><span class="meta-icon" aria-hidden="true"><svg viewBox="0 0 24 24" focusable="false"><path d="M4 4h6v2H6v4H4V4Zm10 0h6v6h-2V6h-4V4ZM4 14h2v4h4v2H4v-6Zm14 0h2v6h-6v-2h4v-4Z"></path></svg></span>${roomMeta.size}</p>` : "";
        const roomView = roomMeta.view ? `<p class="room-meta"><span class="meta-icon" aria-hidden="true"><svg viewBox="0 0 24 24" focusable="false"><path d="M12 5c-5.2 0-9.6 3.1-11 7 1.4 3.9 5.8 7 11 7s9.6-3.1 11-7c-1.4-3.9-5.8-7-11-7Zm0 11a4 4 0 1 1 0-8 4 4 0 0 1 0 8Z"></path></svg></span>${roomMeta.view}</p>` : "";
        return `
        <article class="room-card">
          <div class="room-gallery">
            <button type="button" class="room-main-photo room-gallery-open" data-start-index="0">
              <img src="${firstImage}" alt="${room.roomType} main photo" />
            </button>
            <div class="room-gallery-thumbs">
              <button type="button" class="room-thumb room-gallery-open" data-start-index="1">
                <img src="${secondImage}" alt="${room.roomType} photo 2" />
              </button>
              <button type="button" class="room-thumb room-gallery-more" data-start-index="3">
                <img src="${thirdImage}" alt="${room.roomType} photo 3" />
                <span><small></small>See more..</span>
              </button>
            </div>
          </div>
          <div class="room-content">
            <h3>${room.roomType}</h3>
            <span class="room-availability-badge">Available</span>
            <p class="room-meta">
              <span class="meta-icon" aria-hidden="true">
                <svg viewBox="0 0 24 24" focusable="false">
                  <path d="M16 11a3 3 0 1 0-2.999-3A3 3 0 0 0 16 11Zm-8 0a3 3 0 1 0-3-3A3 3 0 0 0 8 11Zm0 2c-2.761 0-5 1.79-5 4v1h10v-1c0-2.21-2.239-4-5-4Zm8 0c-.333 0-.653.028-.967.075A5.93 5.93 0 0 1 17 17v1h4v-1c0-2.21-2.239-4-5-4Z"></path>
                </svg>
              </span>
              Fits up to ${room.capacityTotal || (room.capacityAdults + room.capacityChildren)} guest(s)
            </p>
            <p class="room-meta">
              <span class="meta-icon" aria-hidden="true">
                <svg viewBox="0 0 24 24" focusable="false">
                  <path d="M4 5a1 1 0 0 0 0 2h1v7.5A2.5 2.5 0 0 0 7.5 17H18a1 1 0 0 0 0-2H7.5a.5.5 0 0 1-.5-.5V14h12a2 2 0 0 0 2-2V7h1a1 1 0 1 0 0-2H4Zm3 7V7h12v5H7Zm2-4.5a.75.75 0 0 0-.75.75v2.5a.75.75 0 0 0 1.5 0v-2.5A.75.75 0 0 0 9 7.5Z"></path>
                </svg>
              </span>
              Free breakfast for ${room.breakfastFor}
            </p>
            <p class="room-meta">
              <span class="meta-icon" aria-hidden="true">
                <svg viewBox="0 0 24 24" focusable="false">
                  <path d="M12 2a10 10 0 1 0 10 10A10.011 10.011 0 0 0 12 2Zm1 10.586 2.707 2.707a1 1 0 0 1-1.414 1.414l-3-3A1 1 0 0 1 11 13V7a1 1 0 0 1 2 0Z"></path>
                </svg>
              </span>
              Ready for selected stay dates
            </p>
            ${roomSize}
            ${roomView}
            <div class="room-inclusions">
              <h4>Inclusions</h4>
              <ul>
                ${room.inclusions.map(item => `
                  <li>
                    <span class="check-icon" aria-hidden="true">
                      <svg viewBox="0 0 24 24" focusable="false">
                        <path d="M9.55 16.2 5.8 12.45l1.4-1.4 2.35 2.35 7.25-7.25 1.4 1.4Z"></path>
                      </svg>
                    </span>
                    ${item}
                  </li>
                `).join("")}
              </ul>
            </div>
          </div>
          <div class="room-right">
            <p>Price per night</p>
            <strong>₱${room.price.toLocaleString()}</strong>
            <small>No hidden charges</small>
            <button type="button" class="room-book-btn" data-room-index="${roomIndex}">Reserve this room</button>
          </div>
        </article>
      `;
      }).join("");

      roomsContent.querySelectorAll(".room-gallery-open, .room-gallery-more").forEach(btn => {
        btn.addEventListener("click", () => {
          const startIndex = Number(btn.dataset.startIndex || 0);
          const roomCard = btn.closest('.room-card');
          const roomName = roomCard ? roomCard.querySelector('h3')?.textContent : null;
          const selected = activeFilteredRooms.find(r => r.roomType === roomName) || activeFilteredRooms[0];
          const selectedGallery = selected && Array.isArray(selected.galleryImages) && selected.galleryImages.length
            ? selected.galleryImages
            : [selected?.mainImage || 'img/sampleimage.png'];
          openGalleryWithSet(selectedGallery, Math.min(startIndex, selectedGallery.length - 1));
        });
      });

      roomsContent.querySelectorAll(".room-book-btn").forEach(btn => {
        btn.addEventListener("click", () => {
          if (!isLoggedIn) {
            if (window.Swal) {
              Swal.fire({
                icon: "warning",
                title: "Login Required",
                text: "You must be logged in to reserve a room.",
                confirmButtonColor: "#2b7a66"
              });
            } else {
              alert("Login required. Please log in first.");
            }
            return;
          }

          const roomIndex = Number(btn.dataset.roomIndex || 0);
          const room = activeFilteredRooms[roomIndex];
          if (!room) return;
          const params = new URLSearchParams({
            hotel_id: String(hotelData.id),
            room_id: String(room.id || 0),
            room_type: room.roomType,
            checkin: String(searchData.checkin || ""),
            checkout: String(searchData.checkout || ""),
            adults: String(searchData.adults || 0),
            children: String(searchData.children || 0)
          });
          clearPersistedSearchState();
          window.location.href = `hotel_booking.php?${params.toString()}`;
        });
      });
    }

    async function runRoomSearch(searchData) {
      const requestId = ++roomSearchRequestId;
      roomsContent.innerHTML = `<p class="dummy-text">Checking room availability...</p>`;
      try {
        const availableRooms = await fetchAvailableRooms(searchData);
        if (requestId !== roomSearchRequestId) return;
        renderRoomsFromSearch(searchData, availableRooms);
      } catch (err) {
        if (requestId !== roomSearchRequestId) return;
        roomsContent.innerHTML = `<p class="dummy-text">Unable to load available rooms right now. Please try again.</p>`;
      }
    }

    function renderRoomsSearchPrompt() {
      roomsContent.innerHTML = `
        <div class="rooms-prompt">
          <p>Search first to view available rooms for your dates and guests.</p>
          <button type="button" id="focusSearchBtn">Check Availability</button>
        </div>
      `;
      document.getElementById("focusSearchBtn").addEventListener("click", () => {
        handleSearchAttempt();
      });
    }

    function applyGuestSelection() {
      const adults = Number(document.getElementById("adults").innerText);
      const children = Number(document.getElementById("children").innerText);
      const rooms = Number(document.getElementById("roomsCount").innerText);
      const guestText = document.getElementById("guestText");
      if ((adults + children) === 0) {
        guestText.innerText = "Guests & Rooms";
        guestText.classList.add("placeholder");
      } else {
        guestText.innerText = `${adults} Adult${adults > 1 ? "s" : ""}, ${children} Child${children !== 1 ? "ren" : ""} • ${rooms} Room${rooms > 1 ? "s" : ""}`;
        guestText.classList.remove("placeholder");
      }
      document.getElementById("guestBox").style.display = "none";
      clearError(document.querySelector(".guest-display"));
      persistSearchFormDraft();
    }

    function changeValue(id, delta) {
      const el = document.getElementById(id);
      const current = Number(el.innerText || 0);
      el.innerText = Math.max(0, current + delta);
      persistSearchFormDraft();
    }

    function toggleGuestBox() {
      const box = document.getElementById("guestBox");
      box.style.display = box.style.display === "block" ? "none" : "block";
    }

    function fillSearchFromData(data) {
      islandInput.value = data.island || "";
      checkinInput.value = data.checkin || "";
      checkoutInput.value = data.checkout || "";
      if (data.checkin && data.checkout) {
        picker.setDate([data.checkin, data.checkout], true, "Y-m-d");
        updateStayDurationBadge(new Date(data.checkin), new Date(data.checkout));
      } else {
        updateStayDurationBadge(null, null);
      }
      document.getElementById("adults").innerText = data.adults ?? 0;
      document.getElementById("children").innerText = data.children ?? 0;
      document.getElementById("roomsCount").innerText = data.rooms ?? 0;
      applyGuestSelection();
      updateIslandFieldState();
    }

    function openResortsInNewTabBySearch(data) {
      const params = new URLSearchParams({
        island: data.island,
        checkin: data.checkin,
        checkout: data.checkout,
        adults: String(data.adults),
        children: String(data.children),
        rooms: String(data.rooms)
      });
      window.open(`hotel_resorts.php?${params.toString()}`, "_blank");
    }

    function collectSearchData() {
      return {
        island: islandInput.value,
        checkin: checkinInput.value,
        checkout: checkoutInput.value,
        adults: Number(document.getElementById("adults").innerText),
        children: Number(document.getElementById("children").innerText),
        rooms: Number(document.getElementById("roomsCount").innerText)
      };
    }

    function parseStoredSearchData(rawValue) {
      if (!rawValue) return null;
      try {
        const parsed = JSON.parse(rawValue);
        return parsed && typeof parsed === "object" ? parsed : null;
      } catch {
        return null;
      }
    }

    function persistSearchFormDraft() {
      const draftData = { ...collectSearchData(), hotelId: hotelData.id };
      sessionStorage.setItem(hotelSearchDraftStorageKey, JSON.stringify(draftData));
    }

    function clearHotelDetailsSearchState() {
      sessionStorage.removeItem(hotelSearchStorageKey);
      sessionStorage.removeItem(hotelSearchDraftStorageKey);
      localStorage.removeItem(hotelSearchStorageKey);
      localStorage.removeItem(legacyHotelSearchStorageKey);
    }

    function clearPersistedSearchState() {
      clearHotelDetailsSearchState();
      sessionStorage.removeItem(resortsSearchStorageKey);
    }

    function getSearchBox(element) {
      return element?.closest(".search-box");
    }

    function clearError(element) {
      if (!element) return;
      element.classList.remove("input-error");
      const box = getSearchBox(element);
      if (!box) return;
      const err = box.querySelector(".error-text");
      if (err) err.remove();
      requestAnimationFrame(syncTabsStickyPosition);
    }

    function setError(element, message) {
      if (!element) return;
      element.classList.add("input-error");
      const box = getSearchBox(element);
      if (!box) return;
      let err = box.querySelector(".error-text");
      if (!err) {
        err = document.createElement("div");
        err.className = "error-text";
        box.appendChild(err);
      }
      err.textContent = message;
      requestAnimationFrame(syncTabsStickyPosition);
    }

    function validateSearchData(data) {
      const guestDisplay = document.querySelector(".guest-display");
      [islandInput, dateRangeInput, guestDisplay].forEach(clearError);

      let valid = true;
      if (!data.island) {
        setError(islandInput, "Please fill up this field");
        valid = false;
      }
      if (!data.checkin || !data.checkout) {
        setError(dateRangeInput, "Please fill up this field");
        valid = false;
      }
      if ((data.adults + data.children) <= 0) {
        setError(guestDisplay, "Please fill up this field");
        valid = false;
      }
      return valid;
    }

    function handleSearchAttempt() {
      const data = collectSearchData();
      const valid = validateSearchData(data);

      if (!valid) {
        searchWrap.classList.add("search-highlight");
        searchWrap.scrollIntoView({ behavior: "smooth", block: "start" });
        setTimeout(() => searchWrap.classList.remove("search-highlight"), 1300);
        return;
      }

      if (data.island && data.island !== (hotelData.island || "")) {
        openResortsInNewTabBySearch(data);
        return;
      }

      sessionStorage.setItem(hotelSearchStorageKey, JSON.stringify({ ...data, hotelId: hotelData.id }));
      sessionStorage.removeItem(hotelSearchDraftStorageKey);
      runRoomSearch(data);
      const roomsSection = document.getElementById("rooms");
      if (roomsSection) {
        roomsSection.scrollIntoView({ behavior: "smooth", block: "start" });
      }
    }

    const todayStr = new Date().toISOString().split("T")[0];
    const placeCalendarBelow = () => {
      const calendar = picker?.calendarContainer;
      if (!calendar || !dateRangeInput) return;
      const inputRect = dateRangeInput.getBoundingClientRect();
      const calendarWidth = calendar.offsetWidth || 960;
      const left = Math.min(
        Math.max(8, inputRect.left + window.scrollX),
        window.scrollX + window.innerWidth - calendarWidth - 8
      );
      const top = inputRect.bottom + window.scrollY + 8;
      calendar.style.right = "auto";
      calendar.style.left = `${left}px`;
      calendar.style.top = `${top}px`;
    };

    const picker = flatpickr(dateRangeInput, {
      mode: "range",
      showMonths: 2,
      static: false,
      appendTo: document.body,
      positionElement: dateRangeInput,
      position: "below left",
      monthSelectorType: "static",
      nextArrow: "&#8250;",
      prevArrow: "&#8249;",
      minDate: todayStr,
      dateFormat: "Y-m-d",
      disableMobile: true,
      onOpen() {
        requestAnimationFrame(placeCalendarBelow);
      },
      onMonthChange() {
        requestAnimationFrame(placeCalendarBelow);
      },
      onChange(selectedDates) {
        if (selectedDates.length === 2) {
          const checkinDate = new Date(selectedDates[0]);
          const checkoutDate = new Date(selectedDates[1]);
          checkinDate.setHours(0, 0, 0, 0);
          checkoutDate.setHours(0, 0, 0, 0);
          if (checkoutDate <= checkinDate) {
            checkinInput.value = "";
            checkoutInput.value = "";
            dateRangeInput.value = "";
            updateStayDurationBadge(null, null);
            picker.clear(false);
            persistSearchFormDraft();
            return;
          }
          checkinInput.value = flatpickr.formatDate(selectedDates[0], "Y-m-d");
          checkoutInput.value = flatpickr.formatDate(selectedDates[1], "Y-m-d");
          dateRangeInput.value = formatRangeLabel(selectedDates[0], selectedDates[1]);
          updateStayDurationBadge(selectedDates[0], selectedDates[1]);
          clearError(dateRangeInput);
        } else if (selectedDates.length === 1) {
          checkinInput.value = flatpickr.formatDate(selectedDates[0], "Y-m-d");
          checkoutInput.value = "";
          updateStayDurationBadge(null, null);
        } else {
          checkinInput.value = "";
          checkoutInput.value = "";
          dateRangeInput.value = "";
          updateStayDurationBadge(null, null);
        }
        persistSearchFormDraft();
      }
    });

    function syncTabsStickyPosition() {
      const tabs = document.getElementById("hotelTabs");
      const searchWrapEl = document.getElementById("detailsSearchWrap");
      if (!tabs || !searchWrapEl) return;

      const headerTop = 70;
      const computedTop = headerTop + Math.round(searchWrapEl.offsetHeight);
      tabs.style.top = `${computedTop}px`;
      tabs.dataset.stickyTop = String(computedTop);

      const tabsHeight = tabs.offsetHeight || 56;
      const page = document.querySelector(".hotel-details-page");
      if (page) {
        page.style.paddingTop = `${tabsHeight + 8}px`;
      }
      const scrollOffset = computedTop + tabsHeight + 8;
      document.documentElement.style.setProperty("--details-tabs-scroll-offset", `${scrollOffset}px`);
    }

    const updateIslandFieldState = () => {
      const hasLocation = Boolean(islandInput.value);
      islandInput.classList.toggle("is-placeholder", !hasLocation);
    };

    islandInput.addEventListener("change", updateIslandFieldState);
    window.addEventListener("resize", placeCalendarBelow);
    window.addEventListener("scroll", placeCalendarBelow, true);

    document.addEventListener("DOMContentLoaded", () => {
      const navigationEntry = performance.getEntriesByType("navigation")[0];
      const navigationType = navigationEntry?.type || "navigate";
      const headerQuery = <?= json_encode($bookingSuccess ? ('?booking_success=1' . ($bookingRef > 0 ? '&booking_ref=' . (int)$bookingRef : '')) : '') ?>;
      const clearBookingSuccessQuery = () => {
        if (!window.history?.replaceState) return;
        const url = new URL(window.location.href);
        if (!url.searchParams.has("booking_success") && !url.searchParams.has("booking_ref")) return;
        url.searchParams.delete("booking_success");
        url.searchParams.delete("booking_ref");
        window.history.replaceState({}, "", `${url.pathname}${url.search}${url.hash}`);
      };
      fetch(`php/header.php${headerQuery}`)
        .then(res => res.text())
        .then(html => {
          document.getElementById("header").innerHTML = html;
          if (typeof initHeader === "function") initHeader();
          clearBookingSuccessQuery();
        });

      renderGallery();
      if (mapPreviewFrame) {
        mapPreviewFrame.src = buildMapEmbedSrc();
      }

      if (bookingSuccess) {
        clearPersistedSearchState();
      } else if (navigationType !== "reload") {
        clearHotelDetailsSearchState();
      }

      const savedDataRaw = sessionStorage.getItem(hotelSearchStorageKey) || localStorage.getItem(hotelSearchStorageKey) || localStorage.getItem(legacyHotelSearchStorageKey);
      const savedData = parseStoredSearchData(savedDataRaw);
      const draftData = parseStoredSearchData(sessionStorage.getItem(hotelSearchDraftStorageKey));

      const savedDataIsForCurrentHotel = savedData && (
        Number(savedData.hotelId || hotelData.id) === Number(hotelData.id)
      );
      const draftDataIsForCurrentHotel = draftData && (
        Number(draftData.hotelId || hotelData.id) === Number(hotelData.id)
      );

      if (savedDataIsForCurrentHotel) {
        fillSearchFromData(savedData);
        runRoomSearch(savedData);
      } else if (draftDataIsForCurrentHotel) {
        fillSearchFromData(draftData);
        renderRoomsSearchPrompt();
      } else {
        if (pageSource === "featured") {
          const featuredIsland = localStorage.getItem("hotelDetailsFeaturedIsland") || hotelData.island || "";
          if (featuredIsland) {
            islandInput.value = featuredIsland;
            updateIslandFieldState();
          }
          localStorage.removeItem("hotelDetailsFeaturedIsland");
          renderRoomsSearchPrompt();
        } else if (pageSource === "result" && !savedData && !draftData) {
          const resortsSearchRaw = sessionStorage.getItem(resortsSearchStorageKey);
          if (resortsSearchRaw) {
            try {
              const resortsData = JSON.parse(resortsSearchRaw);
              if (resortsData && resortsData.island && resortsData.checkin && resortsData.checkout) {
                fillSearchFromData(resortsData);
                runRoomSearch(collectSearchData());
              } else {
                renderRoomsSearchPrompt();
              }
            } catch {
              renderRoomsSearchPrompt();
            }
          } else {
            renderRoomsSearchPrompt();
          }
        } else {
          renderRoomsSearchPrompt();
        }
      }
      searchActionBtn.innerText = "Search Room";
      updateIslandFieldState();

      searchActionBtn.addEventListener("click", () => {
        handleSearchAttempt();
      });

      document.getElementById("guestBox").addEventListener("click", () => {
        clearError(document.querySelector(".guest-display"));
      });
      islandInput.addEventListener("change", () => {
        clearError(islandInput);
        persistSearchFormDraft();
      });
      dateRangeInput.addEventListener("change", () => {
        clearError(dateRangeInput);
        persistSearchFormDraft();
      });

      const reviewsTrack = document.getElementById("reviewsTrack");
      const reviewsPrev = document.getElementById("reviewsPrev");
      const reviewsNext = document.getElementById("reviewsNext");
      if (reviewsTrack && reviewsPrev && reviewsNext) {
        const updateReviewNav = () => {
          const maxScroll = Math.max(0, reviewsTrack.scrollWidth - reviewsTrack.clientWidth - 2);
          reviewsPrev.disabled = reviewsTrack.scrollLeft <= 2;
          reviewsNext.disabled = reviewsTrack.scrollLeft >= maxScroll;
        };
        const scrollStep = () => Math.max(280, Math.floor(reviewsTrack.clientWidth / (window.innerWidth <= 768 ? 1 : 2)));
        reviewsPrev.addEventListener("click", () => {
          reviewsTrack.scrollBy({ left: -scrollStep(), behavior: "smooth" });
        });
        reviewsNext.addEventListener("click", () => {
          reviewsTrack.scrollBy({ left: scrollStep(), behavior: "smooth" });
        });
        reviewsTrack.addEventListener("scroll", updateReviewNav, { passive: true });
        window.addEventListener("resize", updateReviewNav);
        requestAnimationFrame(updateReviewNav);
      }

      document.addEventListener("click", function (e) {
        const box = document.getElementById("guestBox");
        const display = document.querySelector(".guest-display");
        if (!box.contains(e.target) && !display.contains(e.target)) {
          box.style.display = "none";
        }
      });

      document.querySelectorAll("#hotelTabs a").forEach(link => {
        link.addEventListener("click", (e) => {
          e.preventDefault();
          const section = document.querySelector(link.getAttribute("href"));
          if (!section) return;
          const tabs = document.getElementById("hotelTabs");
          const stickyTop = Number(tabs?.dataset?.stickyTop || 140);
          const offset = stickyTop + (tabs?.offsetHeight || 0) + 8;
          const top = section.getBoundingClientRect().top + window.scrollY - offset;
          window.scrollTo({ top, behavior: "smooth" });
          document.querySelectorAll("#hotelTabs a").forEach(a => a.classList.remove("active"));
          link.classList.add("active");
        });
      });

      openMapBtn.addEventListener("click", openMapModal);
      reserveOverviewBtn.addEventListener("click", () => {
        const roomsSection = document.getElementById("rooms");
        const tabs = document.getElementById("hotelTabs");
        if (!roomsSection || !tabs) return;
        const stickyTop = Number(tabs.dataset.stickyTop || 140);
        const offset = stickyTop + (tabs.offsetHeight || 0) + 8;
        const top = roomsSection.getBoundingClientRect().top + window.scrollY - offset;
        window.scrollTo({ top, behavior: "smooth" });
      });
      closeMapModal.addEventListener("click", closeMap);
      mapModal.addEventListener("click", (e) => {
        if (e.target === mapModal) closeMap();
      });

      closeGalleryModal.addEventListener("click", closeGallery);
      galleryPrev.addEventListener("click", () => moveGallery(-1));
      galleryNext.addEventListener("click", () => moveGallery(1));
      galleryModal.addEventListener("click", (e) => {
        if (e.target === galleryModal) closeGallery();
      });

      document.addEventListener("keydown", (e) => {
        if (galleryModal.classList.contains("open")) {
          if (e.key === "ArrowLeft") moveGallery(-1);
          if (e.key === "ArrowRight") moveGallery(1);
          if (e.key === "Escape") closeGallery();
        }
        if (mapModal.classList.contains("open") && e.key === "Escape") {
          closeMap();
        }
      });

      syncTabsStickyPosition();
      setActiveTabByScroll();
      window.addEventListener("scroll", setActiveTabByScroll, { passive: true });
      window.addEventListener("resize", () => {
        syncTabsStickyPosition();
        setActiveTabByScroll();
      });
    });

    document.addEventListener("click", (e) => {
      const target = e.target instanceof Element ? e.target : null;
      const link = target ? target.closest("a[href]") : null;
      if (!link) return;
      if (link.target === "_blank") return;
      if (e.defaultPrevented || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;

      let nextUrl = null;
      try {
        nextUrl = new URL(link.getAttribute("href") || "", window.location.href);
      } catch {
        return;
      }
      if (!nextUrl) return;

      const samePageAnchorOnly =
        nextUrl.origin === window.location.origin &&
        nextUrl.pathname === window.location.pathname &&
        nextUrl.search === window.location.search &&
        nextUrl.hash !== "";

      if (!samePageAnchorOnly) {
        clearPersistedSearchState();
      }
    }, true);
  </script>
</body>
</html>
