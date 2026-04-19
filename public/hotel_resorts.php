<?php
chdir(__DIR__ . '/..');
session_start();
require_once 'php/db_connection.php';
require_once 'php/hotel_rooms_helper.php';
require_once 'php/hotel_content_helper.php';
HoEnsureHotelResortContentColumns($pdo);

$pdo->exec("
CREATE TABLE IF NOT EXISTS hotel_resorts (
  hotel_resort_id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(150) NOT NULL,
  island VARCHAR(100) NOT NULL,
  type VARCHAR(20) NOT NULL,
  price DECIMAL(10,2) NOT NULL DEFAULT 0,
  popular TINYINT(1) NOT NULL DEFAULT 0,
  image_path VARCHAR(255) DEFAULT NULL,
  amenities_json TEXT DEFAULT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'active',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_hotel_resort_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

$pdo->exec("
CREATE TABLE IF NOT EXISTS hotel_resort_reviews (
  review_id INT AUTO_INCREMENT PRIMARY KEY,
  hotel_resort_id INT NOT NULL,
  reviewer_name VARCHAR(120) DEFAULT NULL,
  rating DECIMAL(2,1) NOT NULL,
  review_message TEXT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_hotel_resort_reviews_hotel (hotel_resort_id),
  CONSTRAINT fk_hotel_resort_reviews_hotel
    FOREIGN KEY (hotel_resort_id)
    REFERENCES hotel_resorts(hotel_resort_id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

$dummyHotels = [
    [
        'name' => 'Mercedes Beach Resort',
        'island' => 'Apuao',
        'type' => 'resort',
        'price' => 2500,
        'popular' => 1,
        'image_path' => 'img/sampleimage.png',
        'amenities' => ['Pool', 'Beach Access', 'WiFi', 'Restaurant', 'Snorkeling', 'Kayaking', 'Events Hall']
    ],
    [
        'name' => 'Island View Hotel',
        'island' => 'Malasugui',
        'type' => 'hotel',
        'price' => 1800,
        'popular' => 0,
        'image_path' => 'img/sampleimage.png',
        'amenities' => ['Pool', 'Beach Access', 'WiFi', 'Restaurant', 'Snorkeling', 'Kayaking', 'Events Hall']
    ],
    [
        'name' => 'Paradise Cove Resort',
        'island' => 'Quinapaguian',
        'type' => 'resort',
        'price' => 3200,
        'popular' => 1,
        'image_path' => 'img/sampleimage.png',
        'amenities' => ['Pool', 'Beach Access', 'WiFi', 'Restaurant', 'Snorkeling', 'Kayaking', 'Events Hall']
    ],
    [
        'name' => 'Sunset Bay Resort',
        'island' => 'Apuao',
        'type' => 'resort',
        'price' => 2800,
        'popular' => 1,
        'image_path' => 'img/sampleimage.png',
        'amenities' => ['Pool', 'Beach Access', 'WiFi', 'Restaurant', 'Snorkeling', 'Kayaking', 'Events Hall']
    ],
    [
        'name' => 'Blue Horizon Hotel',
        'island' => 'Malasugui',
        'type' => 'hotel',
        'price' => 1500,
        'popular' => 0,
        'image_path' => 'img/sampleimage.png',
        'amenities' => ['Pool', 'Beach Access', 'WiFi', 'Restaurant', 'Snorkeling', 'Kayaking', 'Events Hall']
    ],
    [
        'name' => 'Coral Garden Resort',
        'island' => 'Quinapaguian',
        'type' => 'resort',
        'price' => 3500,
        'popular' => 1,
        'image_path' => 'img/sampleimage.png',
        'amenities' => ['Pool', 'Beach Access', 'WiFi', 'Restaurant', 'Snorkeling', 'Kayaking', 'Events Hall']
    ],
    [
        'name' => 'Island Breeze Inn',
        'island' => 'Apuao',
        'type' => 'hotel',
        'price' => 1200,
        'popular' => 0,
        'image_path' => 'img/sampleimage.png',
        'amenities' => ['Pool', 'Beach Access', 'WiFi', 'Restaurant', 'Snorkeling', 'Kayaking', 'Events Hall']
    ],
    [
        'name' => 'Lagoon Paradise Resort',
        'island' => 'Malasugui',
        'type' => 'resort',
        'price' => 4000,
        'popular' => 1,
        'image_path' => 'img/sampleimage.png',
        'amenities' => ['Pool', 'Beach Access', 'WiFi', 'Restaurant', 'Snorkeling', 'Kayaking', 'Events Hall']
    ],
    [
        'name' => 'Seaside Comfort Hotel',
        'island' => 'Quinapaguian',
        'type' => 'hotel',
        'price' => 2000,
        'popular' => 0,
        'image_path' => 'img/sampleimage.png',
        'amenities' => ['Pool', 'Beach Access', 'WiFi', 'Restaurant', 'Snorkeling', 'Kayaking', 'Events Hall']
    ],
    [
        'name' => 'Golden Palm Resort',
        'island' => 'Apuao',
        'type' => 'resort',
        'price' => 3000,
        'popular' => 1,
        'image_path' => 'img/sampleimage.png',
        'amenities' => ['Pool', 'Beach Access', 'WiFi', 'Restaurant', 'Snorkeling', 'Kayaking', 'Events Hall']
    ],
    [
        'name' => 'Palms Farm Resort',
        'island' => 'Cayucyucan',
        'type' => 'resort',
        'price' => 3600,
        'popular' => 1,
        'image_path' => 'img/sampleimage.png',
        'amenities' => ['Infinity Pool', 'Garden View', 'WiFi', 'Restaurant', 'Parking', 'Event Pavilion', 'Family Rooms']
    ]
];

$insertHotel = $pdo->prepare("
INSERT INTO hotel_resorts (name, island, type, price, popular, image_path, amenities_json, status)
VALUES (?, ?, ?, ?, ?, ?, ?, 'active')
ON DUPLICATE KEY UPDATE
  island = VALUES(island),
  type = VALUES(type),
  price = VALUES(price),
  popular = VALUES(popular),
  image_path = VALUES(image_path),
  amenities_json = VALUES(amenities_json),
  status = 'active'
");

foreach ($dummyHotels as $hotel) {
    $insertHotel->execute([
        $hotel['name'],
        $hotel['island'],
        $hotel['type'],
        $hotel['price'],
        $hotel['popular'],
        $hotel['image_path'],
        json_encode($hotel['amenities'], JSON_UNESCAPED_UNICODE)
    ]);
}

$hotelMapStmt = $pdo->query("SELECT hotel_resort_id, name FROM hotel_resorts");
$hotelIdByName = [];
while ($row = $hotelMapStmt->fetch(PDO::FETCH_ASSOC)) {
    $hotelIdByName[$row['name']] = (int)$row['hotel_resort_id'];
}

$dummyReviews = [
    'Mercedes Beach Resort' => [
        ['Ana R.', 5.0, 'Excellent beachfront service and very relaxing atmosphere.'],
        ['Leo M.', 4.5, 'Great amenities and clean rooms, perfect for family trips.'],
        ['Mika J.', 4.0, 'Beautiful island view and helpful staff.']
    ],
    'Island View Hotel' => [
        ['Carlo D.', 4.0, 'Accessible location and smooth check-in process.'],
        ['Grace P.', 4.2, 'Comfortable stay with friendly front desk staff.']
    ],
    'Paradise Cove Resort' => [
        ['Nina S.', 5.0, 'Top-tier resort experience with great food and tours.'],
        ['Ken T.', 4.8, 'Very clean facilities and highly recommended for couples.']
    ],
    'Sunset Bay Resort' => [
        ['Lara V.', 4.7, 'Sunset view is amazing and staff were very accommodating.'],
        ['Paul G.', 4.5, 'Worth the price for the quality and ambiance.']
    ],
    'Blue Horizon Hotel' => [
        ['Ivy C.', 4.1, 'Simple but very comfortable and well-maintained rooms.'],
        ['Noel B.', 4.0, 'Good value and convenient for short stays.']
    ],
    'Coral Garden Resort' => [
        ['Josh A.', 5.0, 'Outstanding service and complete amenities.'],
        ['Pam Q.', 4.9, 'Best resort option in the area for long weekends.']
    ],
    'Island Breeze Inn' => [
        ['Mara L.', 4.0, 'Budget-friendly and clean, staff are approachable.'],
        ['Ron E.', 3.9, 'Great for quick stopovers and island hopping plans.']
    ],
    'Lagoon Paradise Resort' => [
        ['Zia F.', 4.8, 'Elegant rooms and excellent customer service.'],
        ['Tom Y.', 4.6, 'Resort ambiance is premium and peaceful.']
    ],
    'Seaside Comfort Hotel' => [
        ['Jill O.', 4.3, 'Nice balance of comfort and affordability.'],
        ['Eric H.', 4.1, 'Good staff support and clean dining area.']
    ],
    'Golden Palm Resort' => [
        ['Mina W.', 4.7, 'Highly recommended for group getaways and events.'],
        ['Aldrin K.', 4.5, 'Very scenic place with complete resort services.']
    ],
    'Palms Farm Resort' => [
        ['Camille N.', 4.9, 'Great location in Cayucyucan with peaceful surroundings and clean rooms.'],
        ['Jerome P.', 4.8, 'Excellent service and perfect for family staycations.']
    ]
];

$insertReview = $pdo->prepare("
INSERT INTO hotel_resort_reviews (hotel_resort_id, reviewer_name, rating, review_message)
VALUES (?, ?, ?, ?)
");

foreach ($dummyReviews as $hotelName => $reviews) {
    if (empty($hotelIdByName[$hotelName])) {
        continue;
    }
    $hotelId = $hotelIdByName[$hotelName];
    $check = $pdo->prepare("SELECT COUNT(*) FROM hotel_resort_reviews WHERE hotel_resort_id = ?");
    $check->execute([$hotelId]);
    $hasReviews = (int)$check->fetchColumn() > 0;
    if ($hasReviews) {
        continue;
    }

    foreach ($reviews as $review) {
        $insertReview->execute([$hotelId, $review[0], $review[1], $review[2]]);
    }
}

$stmtHotels = $pdo->query("
SELECT
  h.hotel_resort_id AS id,
  h.name,
  h.island,
  h.type,
  h.price,
  h.popular,
  h.image_path AS img,
  h.amenities_json,
  COALESCE(ROUND(AVG(r.rating), 1), 0) AS rating,
  COUNT(r.review_id) AS total_reviews,
  SUBSTRING_INDEX(GROUP_CONCAT(r.review_message ORDER BY r.created_at DESC SEPARATOR '||'), '||', 1) AS review_message
FROM hotel_resorts h
LEFT JOIN hotel_resort_reviews r ON r.hotel_resort_id = h.hotel_resort_id
WHERE h.status = 'active'
GROUP BY h.hotel_resort_id, h.name, h.island, h.type, h.price, h.popular, h.image_path, h.amenities_json
ORDER BY h.hotel_resort_id ASC
");

$hotelsFromDb = $stmtHotels->fetchAll(PDO::FETCH_ASSOC);
$lowestRoomPriceByHotel = [];
try {
    HoEnsureHotelRoomsTable($pdo);
    $roomPriceStmt = $pdo->query("
      SELECT hotel_resort_id, MIN(price) AS min_price
      FROM hotel_rooms
      WHERE status = 'active'
      GROUP BY hotel_resort_id
    ");
    $roomPriceRows = $roomPriceStmt ? $roomPriceStmt->fetchAll(PDO::FETCH_ASSOC) : [];
    foreach ($roomPriceRows as $row) {
        $hid = (int)($row['hotel_resort_id'] ?? 0);
        $minPrice = isset($row['min_price']) ? (float)$row['min_price'] : null;
        if ($hid > 0 && $minPrice !== null) {
            $lowestRoomPriceByHotel[$hid] = $minPrice;
        }
    }
} catch (Throwable $e) {
}
foreach ($hotelsFromDb as &$hotel) {
    $hotelId = (int)$hotel['id'];
    $hotel['price'] = array_key_exists($hotelId, $lowestRoomPriceByHotel)
        ? (float)$lowestRoomPriceByHotel[$hotelId]
        : (float)$hotel['price'];
    $hotel['popular'] = (bool)$hotel['popular'];
    $hotel['rating'] = (float)$hotel['rating'];
    $hotel['total_reviews'] = (int)$hotel['total_reviews'];
    $hotel['review_message'] = $hotel['review_message'] ?: 'No review message yet.';
    $hotel['img'] = !empty($hotel['img']) ? $hotel['img'] : 'img/sampleimage.png';
    $decodedAmenities = json_decode($hotel['amenities_json'] ?? '[]', true);
    $hotel['amenities'] = is_array($decodedAmenities) ? $decodedAmenities : [];
    unset($hotel['amenities_json']);
}
unset($hotel);

?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>iTour Mercedes</title>
  <link rel="icon" type="image/png" href="img/newlogo.png" />
  <link href="https://fonts.googleapis.com/css2?family=Roboto&display=swap" rel="stylesheet" />
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css" />
  <link rel="stylesheet" href="styles/hotel_resorts.css" />
</head>

<div id="amenityModal" class="hotel-result-modal">
  <div class="hotel-result-modal-content">
    <h2 id="modalTitle" class="hotel-result-modal-title"></h2>
    <div id="modalAmenities" class="hotel-result-modal-amenities"></div>
    <button class="hotel-result-modal-btn" onclick="closeAmenities()">
      Close
    </button>
  </div>
</div>

<body>

<!-- Header -->
<div id="header"></div>

<!-- HERO SECTION -->
<section class="hotel-hero" id="heroSection">
  <div class="hotel-hero-overlay">
    <h1>Find Your Perfect Stay in Mercedes</h1>
    <p>Hotels & Resorts Near the Islands</p>
  </div>
</section>

<!-- SEARCH BAR -->
<section class="hotel-search" id="searchSection">
  <div class="search-container">

    <!-- Island -->
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

    <!-- Date Range -->
    <div class="search-box">
      <label>Check-in / Check-out</label>
      <div class="date-input-wrap">
        <input type="text" id="dateRangePicker" placeholder="Select stay dates" readonly>
        <span id="stayDurationBadge" class="stay-duration-badge" hidden></span>
      </div>
      <input type="hidden" id="checkin">
      <input type="hidden" id="checkout">
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
            <button onclick="changeValue('adults', -1)">-</button>
            <span id="adults">0</span>
            <button onclick="changeValue('adults', 1)">+</button>
          </div>
        </div>

        <div class="guest-row">
          <span>Children</span>
          <div>
            <button onclick="changeValue('children', -1)">-</button>
            <span id="children">0</span>
            <button onclick="changeValue('children', 1)">+</button>
          </div>
        </div>

        <div class="guest-row">
          <span>Rooms</span>
          <div>
            <button onclick="changeValue('rooms', -1)">-</button>
            <span id="rooms">0</span>
            <button onclick="changeValue('rooms', 1)">+</button>
          </div>
        </div>

        <div class="guest-actions">
          <button class="done-btn" onclick="applyGuestSelection()">Done</button>
        </div>
      </div>
    </div>

    <!-- Search Button -->
    <button class="search-btn" onclick="filterHotels()">Search</button>

  </div>
</section>

<!-- FEATURED RESORTS (VISIBLE ONLY BEFORE SEARCH) -->
<section id="featuredSection" class="hotel-featured-section">
  <h2 class="hotel-featured-title-main">
    Featured Hotel & Resorts in Mercedes
  </h2>
  <div class="hotel-featured-grid" id="featuredGrid"></div>
</section>

<!-- MAIN CONTENT -->
<section class="hotel-main">
  <!-- LEFT FILTER -->
  <div class="hotel-filter">
    <h3>Filter</h3>

    <label><input type="checkbox" class="filter" value="popular"> Most Popular</label>
    <label><input type="checkbox" class="filter" value="reviews"> Highest Reviews</label>
    <label><input type="checkbox" class="filter" value="hotel"> Hotels Only</label>
    <label><input type="checkbox" class="filter" value="resort"> Resorts Only</label>

    <div class="price-filter">
      <h4>Price Filter</h4>

      <div class="price-values">
        ₱<span id="minPrice">200</span> — ₱<span id="maxPrice">6000</span>+
        <img src="img/pricemetric.png" alt="Price metric" class="hotel-price-metric"/>
      </div>

      <div class="price-slider">
        <input type="range" id="rangeMin" min="200" max="6000" value="200">
        <input type="range" id="rangeMax" min="200" max="6000" value="6000">
      </div>
    </div>
  </div>

  <!-- RIGHT LIST -->
  <div class="hotel-list-section">
    <!-- SORT NAV -->
    <div class="hotel-sort">
      <button class="active" onclick="sortHotels('recommended', this)">Recommended</button>
      <button onclick="sortHotels('price', this)">Price (↓↑ Low to High)</button>
      <button onclick="sortHotels('rating', this)">Reviews (↑↓ High to Low)</button>
    </div>

    <!-- HOTEL LIST -->
    <div id="hotelList" class="hotel-list"></div>
  </div>
</section>

<!-- Login/Signup Modal -->
<div id="loginModal"></div>

<?php include 'footer.php'; ?>

<script src="js/header.js"></script>
<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
<script>
  const hotels = <?= json_encode($hotelsFromDb, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

  let filtered = [...hotels];

  document.addEventListener("DOMContentLoaded", () => {
    displayFeatured();
    updatePriceUI();
    document.querySelector(".hotel-main").style.display = "none";

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
        toggle?.addEventListener("click", () => {
          navLinks.classList.toggle("show");
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

        const dateRangeInput = document.getElementById("dateRangePicker");
        const stayDurationBadge = document.getElementById("stayDurationBadge");
        const checkinInput = document.getElementById("checkin");
        const checkoutInput = document.getElementById("checkout");
        const today = new Date();
        const todayStr = today.toISOString().split("T")[0];
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
        const placeCalendarBelow = () => {
          const calendar = rangePicker?.calendarContainer;
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

        const rangePicker = flatpickr(dateRangeInput, {
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
          onOpen: function() {
            requestAnimationFrame(placeCalendarBelow);
          },
          onMonthChange: function() {
            requestAnimationFrame(placeCalendarBelow);
          },
          onChange: function(selectedDates, dateStr) {
            if (selectedDates.length === 2) {
              const checkinDate = new Date(selectedDates[0]);
              const checkoutDate = new Date(selectedDates[1]);
              checkinDate.setHours(0, 0, 0, 0);
              checkoutDate.setHours(0, 0, 0, 0);

              if (checkoutDate <= checkinDate) {
                setError(dateRangeInput, "Check-out must be at least 1 day after check-in");
                checkinInput.value = "";
                checkoutInput.value = "";
                dateRangeInput.value = "";
                updateStayDurationBadge(null, null);
                rangePicker.clear(false);
                return;
              }

              checkinInput.value = flatpickr.formatDate(selectedDates[0], "Y-m-d");
              checkoutInput.value = flatpickr.formatDate(selectedDates[1], "Y-m-d");
              dateRangeInput.value = formatRangeLabel(selectedDates[0], selectedDates[1]);
              updateStayDurationBadge(selectedDates[0], selectedDates[1]);
              clearError(dateRangeInput);
              clearError(checkinInput);
              clearError(checkoutInput);
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
          }
        });
        window.addEventListener("resize", placeCalendarBelow);
        window.addEventListener("scroll", placeCalendarBelow, true);

        const island = document.getElementById("island");
        const updateIslandFieldState = () => {
          const hasLocation = Boolean(island.value);
          island.classList.toggle("is-placeholder", !hasLocation);
        };
        island.addEventListener("change", () => {
          if (island.value) clearError(island);
          updateIslandFieldState();
        });

        const navType = performance.getEntriesByType("navigation")[0].type;
        const savedMode = sessionStorage.getItem("searchMode");
        let savedData = null;

        try {
          savedData = JSON.parse(sessionStorage.getItem("searchData"));
        } catch (e) {
          savedData = null;
        }

        const urlParams = new URLSearchParams(window.location.search);
        const hasExternalSearchParams = urlParams.has("island");
        if (hasExternalSearchParams) {
          savedData = {
            island: urlParams.get("island") || "",
            checkin: urlParams.get("checkin") || "",
            checkout: urlParams.get("checkout") || "",
            adults: Number(urlParams.get("adults") || 1),
            children: Number(urlParams.get("children") || 0),
            rooms: Number(urlParams.get("rooms") || 1)
          };
          sessionStorage.setItem("searchMode", "true");
          sessionStorage.setItem("searchData", JSON.stringify(savedData));
        }

        if (hasExternalSearchParams) {
          document.getElementById("island").value = savedData.island || "";
          checkinInput.value = savedData.checkin || "";
          checkoutInput.value = savedData.checkout || "";
          if (savedData.checkin && savedData.checkout) {
            rangePicker.setDate([savedData.checkin, savedData.checkout], true, "Y-m-d");
            updateStayDurationBadge(new Date(savedData.checkin), new Date(savedData.checkout));
          } else {
            updateStayDurationBadge(null, null);
          }
          document.getElementById("adults").innerText = savedData.adults ?? 1;
          document.getElementById("children").innerText = savedData.children ?? 0;
          document.getElementById("rooms").innerText = savedData.rooms ?? 1;
          applyGuestSelection();
          setTimeout(() => {
            applySearchWithoutValidation(savedData);
          }, 0);
          const cleanUrl = window.location.origin + window.location.pathname;
          window.history.replaceState({}, "", cleanUrl);
        } else if (navType === "reload") {
          if (savedMode === "true" && savedData) {
            document.getElementById("island").value = savedData.island || "";
            checkinInput.value = savedData.checkin || "";
            checkoutInput.value = savedData.checkout || "";
            if (savedData.checkin && savedData.checkout) {
              rangePicker.setDate([savedData.checkin, savedData.checkout], true, "Y-m-d");
              updateStayDurationBadge(new Date(savedData.checkin), new Date(savedData.checkout));
            } else {
              updateStayDurationBadge(null, null);
            }
            document.getElementById("adults").innerText = savedData.adults ?? 0;
            document.getElementById("children").innerText = savedData.children ?? 0;
            document.getElementById("rooms").innerText = savedData.rooms ?? 0;
            applyGuestSelection();
            setTimeout(() => {
              applySearchWithoutValidation(savedData);
            }, 0);
          } else {
            showFeaturedMode();
          }
        } else {
          sessionStorage.removeItem("searchMode");
          sessionStorage.removeItem("searchData");
          showFeaturedMode();
        }
        updateIslandFieldState();

        fetch("logsign-modal.html")
          .then(res => res.text())
          .then(html => {
            const modalContainer = document.getElementById("loginModal");
            if (!modalContainer) return console.error("loginModal container not found");
            modalContainer.innerHTML = html;

            const swalScript = document.createElement("script");
            swalScript.src = "https://cdn.jsdelivr.net/npm/sweetalert2@11";
            swalScript.onload = () => {
              const logsignScript = document.createElement("script");
              logsignScript.src = "logsign.js";
              logsignScript.onload = () => {
                if (typeof initLogSignEvents === "function") initLogSignEvents();
                else console.error("initLogSignEvents not found in logsign.js");
              };
              document.body.appendChild(logsignScript);
            };
            document.body.appendChild(swalScript);
          })
          .catch(err => console.error("Modal load error:", err));
      })
      .catch(err => console.error("Header load error:", err));
  });

  function showFeaturedMode() {
    document.getElementById("featuredSection").style.display = "block";
    document.querySelector(".hotel-main").style.display = "none";
    document.getElementById("heroSection").style.display = "block";
    document.getElementById("searchSection").classList.remove("search-fixed");
  }

  function getStarClass(index, avg) {
    if (index <= Math.floor(avg)) return "filled";
    if (index - avg < 1 && index > avg) return "half";
    return "";
  }

  function renderStars(avg, totalReviews, variant = "result") {
    let stars = "";
    for (let i = 1; i <= 5; i++) {
      const starClass = getStarClass(i, avg);
      stars += `<span class="star ${starClass}">★</span>`;
    }

    return `
      <div class="hotel-star-rating ${variant}">
        ${stars}
        <span class="rating-text">${Number(avg).toFixed(1)} (${totalReviews} reviews)</span>
      </div>
    `;
  }

  function displayHotels(list) {
    const container = document.getElementById("hotelList");
    container.innerHTML = "";

    list.forEach(h => {
      const visibleAmenities = (h.amenities || []).slice(0, 5);

      container.innerHTML += `
      <div class="hotel-result-card hotel-card-link" onclick="openHotelDetails(${h.id}, 'result')">
        <img class="hotel-result-img" src="${h.img}" alt="${h.name}" />
        <div class="hotel-result-info">
          <span class="hotel-result-badge ${h.type}">${h.type.toUpperCase()}</span>
          <h3 class="hotel-result-title">${h.name}</h3>
          <p class="hotel-result-location">${h.island}</p>
          <div class="hotel-result-reviews">
            ${renderStars(h.rating, h.total_reviews || Math.floor(h.rating * 20), "result")}
          </div>
          <div class="hotel-result-amenities">
            ${visibleAmenities.map(a => `<span class="hotel-result-amenity">${a}</span>`).join("")}
          </div>
          <button class="hotel-result-see-more" onclick="event.stopPropagation(); openAmenities('${h.name}')">
            See all amenities
          </button>
        </div>
        <div class="hotel-result-right">
          <div class="hotel-result-price-wrap">
            <span class="hotel-result-price-label">as low as</span>
            <div class="hotel-result-price">
              ₱${h.price}
              <span>/night</span>
            </div>
          </div>
          <button class="hotel-result-btn" onclick="event.stopPropagation(); openHotelDetails(${h.id}, 'result')">Check Availability</button>
        </div>
      </div>
    `;
    });
  }

  const rangeMin = document.getElementById("rangeMin");
  const rangeMax = document.getElementById("rangeMax");
  const minText = document.getElementById("minPrice");
  const maxText = document.getElementById("maxPrice");
  const priceStep = parseInt(rangeMin.step) || 200;

  function updatePriceUI() {
    let minVal = parseInt(rangeMin.value);
    let maxVal = parseInt(rangeMax.value);
    const min = parseInt(rangeMin.min);
    const max = parseInt(rangeMin.max);

    if (minVal > maxVal - 200) {
      minVal = maxVal - 200;
      rangeMin.value = minVal;
    }

    if (maxVal < minVal + 200) {
      maxVal = minVal + 200;
      rangeMax.value = maxVal;
    }

    minText.innerText = minVal;
    maxText.innerText = maxVal;

    const percentMin = ((minVal - min) / (max - min)) * 100;
    const percentMax = ((maxVal - min) / (max - min)) * 100;

    rangeMin.style.setProperty('--min', percentMin + '%');
    rangeMin.style.setProperty('--max', percentMax + '%');
    rangeMax.style.setProperty('--min', percentMin + '%');
    rangeMax.style.setProperty('--max', percentMax + '%');
  }

  rangeMin.addEventListener("input", updatePriceUI);
  rangeMax.addEventListener("input", updatePriceUI);
  rangeMin.addEventListener("change", applyCheckboxFilters);
  rangeMax.addEventListener("change", applyCheckboxFilters);

  function filterHotels() {
    let valid = true;
    const island = document.getElementById("island");
    const checkin = document.getElementById("checkin");
    const checkout = document.getElementById("checkout");
    const dateRangeInput = document.getElementById("dateRangePicker");
    const adults = parseInt(document.getElementById("adults").innerText);
    const rooms = parseInt(document.getElementById("rooms").innerText);

    [island, dateRangeInput].forEach(clearError);

    if (!island.value) {
      setError(island, "This field is required");
      valid = false;
    }

    if (!checkin.value || !checkout.value) {
      setError(dateRangeInput, "Please select check-in and check-out dates");
      valid = false;
    }

    if (adults === 0 || rooms === 0) {
      const guestBox = document.querySelector(".guest-display");
      setError(guestBox, "This field is required");
      valid = false;
    }

    if (!valid) {
      return;
    }

    const checkinDate = new Date(checkin.value);
    const checkoutDate = new Date(checkout.value);

    if (checkoutDate <= checkinDate) {
      setError(dateRangeInput, "Must be at least 1 night stay");
      return;
    }

    document.getElementById("featuredSection").style.display = "none";
    document.querySelector(".hotel-main").style.display = "flex";
    document.getElementById("heroSection").style.display = "none";
    const search = document.getElementById("searchSection");
    search.classList.add("search-fixed");
    document.querySelector(".hotel-main").classList.add("search-active");
    document.querySelector(".hotel-main").style.display = "flex";

    filtered = hotels.filter(h => {
      return !island.value || h.island === island.value;
    });

    applyCheckboxFilters();

    sessionStorage.setItem("searchMode", "true");
    sessionStorage.setItem("searchData", JSON.stringify({
      island: island.value,
      checkin: checkin.value,
      checkout: checkout.value,
      adults: adults,
      children: parseInt(document.getElementById("children").innerText),
      rooms: rooms
    }));
  }

  function applySearchWithoutValidation(data) {
    document.getElementById("featuredSection").style.display = "none";
    document.querySelector(".hotel-main").style.display = "flex";
    document.getElementById("heroSection").style.display = "none";
    const search = document.getElementById("searchSection");
    search.classList.add("search-fixed");
    filtered = hotels.filter(h => {
      return !data.island || h.island === data.island;
    });
    document.querySelector(".hotel-main").classList.add("search-active");
    applyCheckboxFilters();
  }

  document.querySelectorAll(".filter").forEach(cb => {
    cb.addEventListener("change", applyCheckboxFilters);
  });

  function applyCheckboxFilters() {
    let temp = [...filtered];
    const checked = [...document.querySelectorAll(".filter:checked")].map(c => c.value);
    const minPrice = parseInt(rangeMin.value);
    const maxPrice = parseInt(rangeMax.value);

    temp = temp.filter(h => h.price >= minPrice && h.price <= maxPrice);

    if (checked.includes("popular")) {
      temp = temp.filter(h => h.popular);
    }

    if (checked.includes("reviews")) {
      temp = temp.sort((a, b) => b.rating - a.rating);
    }

    if (checked.includes("hotel")) {
      temp = temp.filter(h => h.type === "hotel");
    }

    if (checked.includes("resort")) {
      temp = temp.filter(h => h.type === "resort");
    }

    displayHotels(temp);
  }

  function sortHotels(type, el) {
    document.querySelectorAll(".hotel-sort button").forEach(btn => btn.classList.remove("active"));
    el.classList.add("active");
    let temp = [...filtered];

    if (type === "price") {
      temp.sort((a, b) => a.price - b.price);
    }
    if (type === "rating") {
      temp.sort((a, b) => b.rating - a.rating);
    }

    displayHotels(temp);
  }

  function changeValue(id, delta) {
    const el = document.getElementById(id);
    let val = parseInt(el.innerText);
    val = Math.max(0, val + delta);
    el.innerText = val;
  }

  displayHotels(hotels);

  function toggleGuestBox() {
    const box = document.getElementById("guestBox");
    box.style.display = box.style.display === "block" ? "none" : "block";
  }

  function changeValue(id, delta) {
    const el = document.getElementById(id);
    let val = parseInt(el.innerText);
    val = Math.max(0, val + delta);
    el.innerText = val;
  }

  function applyGuestSelection() {
    const adults = parseInt(document.getElementById("adults").innerText);
    const children = parseInt(document.getElementById("children").innerText);
    const rooms = parseInt(document.getElementById("rooms").innerText);
    const textEl = document.getElementById("guestText");

    if (adults === 0 && rooms === 0) {
      textEl.innerText = "Guests & Rooms";
      textEl.classList.add("placeholder");
    } else {
      textEl.innerText = `${adults} Adult${adults > 1 ? "s" : ""}, ${children} Child${children !== 1 ? "ren" : ""} • ${rooms} Room${rooms > 1 ? "s" : ""}`;
      textEl.classList.remove("placeholder");
    }

    const guestBox = document.querySelector(".guest-display");
    if (adults > 0 || rooms > 0) {
      clearError(guestBox);
    }

    document.getElementById("guestBox").style.display = "none";
  }

  document.addEventListener("click", function (e) {
    const box = document.getElementById("guestBox");
    const display = document.querySelector(".guest-display");

    if (!box.contains(e.target) && !display.contains(e.target)) {
      box.style.display = "none";
    }
  });

  function setError(el, message) {
    el.classList.add("input-error");
    let msg = el.parentNode.querySelector(".error-text");
    if (!msg) {
      msg = document.createElement("div");
      msg.className = "error-text";
      el.parentNode.appendChild(msg);
    }
    msg.innerText = message;
  }

  function clearError(el) {
    el.classList.remove("input-error");
    const parent = el.parentNode;
    const msg = parent.querySelector(".error-text");
    if (msg) msg.remove();
  }

  function displayFeatured() {
    const grid = document.getElementById("featuredGrid");
    grid.innerHTML = "";

    hotels.forEach(h => {
      const visibleAmenities = (h.amenities || []).slice(0, 5);
      const hiddenAmenities = (h.amenities || []).slice(5);

      grid.innerHTML += `
      <div class="hotel-featured-card hotel-card-link" onclick="openHotelDetails(${h.id}, 'featured')">
        <div class="hotel-featured-img-wrap">
          <img class="hotel-featured-img" src="${h.img}" alt="${h.name}" />
          <span class="hotel-featured-badge ${h.type}">${h.type.toUpperCase()}</span>
        </div>
        <div class="hotel-featured-info">
          <h3 class="hotel-featured-title">${h.name}</h3>
          <p class="hotel-featured-location">${h.island}</p>
          <div class="hotel-featured-reviews">
            ${renderStars(h.rating, h.total_reviews || Math.floor(h.rating * 20), "featured")}
          </div>
          <div class="hotel-featured-amenities">
            ${visibleAmenities.map(a => `<span class="hotel-featured-amenity">${a}</span>`).join("")}
            ${hiddenAmenities.length > 0 ? `
              <div class="hotel-featured-more">
                +${hiddenAmenities.length} more
                <div class="hotel-featured-tooltip">
                  ${(h.amenities || []).map(a => `<span class="hotel-featured-amenity">${a}</span>`).join("")}
                </div>
              </div>
            ` : ""}
          </div>
          <div class="hotel-featured-price-wrap">
            <span class="hotel-featured-price-label">as low as</span>
            <div class="hotel-featured-price">
              ₱${h.price}
              <span>/night</span>
            </div>
          </div>
          <button class="hotel-featured-btn" onclick="event.stopPropagation(); openHotelDetails(${h.id}, 'featured')">Check Availability</button>
        </div>
      </div>
    `;
    });
  }

  function openHotelDetails(hotelId, source = "featured") {
    const fromSearchResults = source === "result";
    const searchDataRaw = sessionStorage.getItem("searchData");
    const selectedHotel = hotels.find(h => Number(h.id) === Number(hotelId));
    const island = selectedHotel?.island || "";

    if (fromSearchResults && searchDataRaw) {
      localStorage.setItem("hotelDetailsSearchData", searchDataRaw);
    } else {
      localStorage.removeItem("hotelDetailsSearchData");
      if (island) {
        localStorage.setItem("hotelDetailsFeaturedIsland", island);
      } else {
        localStorage.removeItem("hotelDetailsFeaturedIsland");
      }
    }

    const detailsUrl = `hotel_details.php?id=${encodeURIComponent(hotelId)}&source=${encodeURIComponent(source)}`;
    window.open(detailsUrl, "_blank");
  }

  function openAmenities(name) {
    const hotel = hotels.find(h => h.name === name);
    document.getElementById("modalTitle").innerText = hotel.name;
    document.getElementById("modalAmenities").innerHTML = hotel.amenities.map(a => `<span class="hotel-result-modal-pill">${a}</span>`).join("");
    document.getElementById("amenityModal").style.display = "flex";
  }

  function closeAmenities() {
    document.getElementById("amenityModal").style.display = "none";
  }

  function showFeatured() {
    document.getElementById("featuredSection").style.display = "block";
    document.querySelector(".hotel-main").style.display = "none";
    document.getElementById("heroSection").style.display = "block";
    document.getElementById("searchSection").classList.remove("search-fixed");
  }

  window.addEventListener("pageshow", function (event) {
    const navType = performance.getEntriesByType("navigation")[0].type;
    if (event.persisted || navType === "back_forward") {
      sessionStorage.removeItem("searchMode");
      sessionStorage.removeItem("searchData");
      showFeaturedMode();
    }
  });
</script>

</body>
</html>
