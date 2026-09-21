<?php
chdir(__DIR__ . '/..');
require_once __DIR__ . '/../php/session_security.php';
AppSessionStart();
require_once 'php/db_connection.php';
require_once 'php/hotel_rooms_helper.php';
require_once 'php/hotel_content_helper.php';
require_once 'php/favorites_helper.php';
require_once 'php/hotel_reviews_helper.php';
HoEnsureHotelResortContentColumns($pdo);

$landingTab = strtolower(trim((string)($_GET['tab'] ?? 'hotels')));
$isToursLanding = in_array($landingTab, [
  'tours',
  'tour-packages',
  'packages',
  'guides',
  'tour-guides',
  'tour-guide',
  'boats',
  'tour-boats',
  'tour-boat',
  'boat',
], true);

$isSeoToursPage = isset($_GET['tab']) && $isToursLanding;
$seoTitle = $isSeoToursPage
  ? 'Island Hopping & Tour Packages in Mercedes, Camarines Norte | iTour Mercedes'
  : 'Hotels & Resorts in Mercedes, Camarines Norte | iTour Mercedes';
$seoDescription = $isSeoToursPage
  ? 'Explore island hopping adventures, tour packages, local tour guides, and boat services in Mercedes, Camarines Norte.'
  : 'Discover hotels and resorts in Mercedes, Camarines Norte. Explore available accommodations and plan your stay with iTour Mercedes.';
$seoCanonical = $isSeoToursPage
  ? 'https://itourmercedes.com/hotel_resorts.php?tab=tours'
  : 'https://itourmercedes.com/hotel_resorts.php';

$favoriteIds = favoriteIdsByType($pdo, (int)($_SESSION['tourist_id'] ?? 0));
$favoritesCsrf = favoriteCsrfToken();

if (!hoLandingTableExists($pdo, 'hotel_resorts')) {
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
}

if (!hoLandingTableExists($pdo, 'hotel_resort_reviews')) {
$pdo->exec("
CREATE TABLE IF NOT EXISTS hotel_resort_reviews (
  review_id INT AUTO_INCREMENT PRIMARY KEY,
  hotel_resort_id INT NOT NULL,
  reviewer_name VARCHAR(120) DEFAULT NULL,
  rating DECIMAL(2,1) NOT NULL,
  review_message TEXT NOT NULL,
  moderation_status ENUM('published','hidden','flagged') NOT NULL DEFAULT 'published',
  owner_reply TEXT DEFAULT NULL,
  internal_note TEXT DEFAULT NULL,
  responded_by INT DEFAULT NULL,
  responded_at DATETIME DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_hotel_resort_reviews_hotel (hotel_resort_id),
  CONSTRAINT fk_hotel_resort_reviews_hotel
    FOREIGN KEY (hotel_resort_id)
    REFERENCES hotel_resorts(hotel_resort_id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");
}
ensureHotelReviewManagementColumns($pdo);

$hotelMapStmt = $pdo->query("SELECT hotel_resort_id, name FROM hotel_resorts");
$hotelIdByName = [];
while ($row = $hotelMapStmt->fetch(PDO::FETCH_ASSOC)) {
    $hotelIdByName[$row['name']] = (int)$row['hotel_resort_id'];
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
LEFT JOIN hotel_resort_reviews r ON r.hotel_resort_id = h.hotel_resort_id AND r.moderation_status = 'published'
WHERE h.status = 'active'
GROUP BY h.hotel_resort_id, h.name, h.island, h.type, h.price, h.popular, h.image_path, h.amenities_json
ORDER BY h.hotel_resort_id ASC
");

$hotelsFromDb = $stmtHotels->fetchAll(PDO::FETCH_ASSOC);

function hoLandingTableExists(PDO $pdo, string $table): bool
{
  static $tables = null;
  if ($tables === null) {
    $names = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    $tables = array_fill_keys(array_map('strtolower', $names), true);
  }
  return isset($tables[strtolower($table)]);
}

function hoLandingColumnExists(PDO $pdo, string $table, string $column): bool
{
  static $columnsByTable = [];
  $tableKey = strtolower($table);
  if (!array_key_exists($tableKey, $columnsByTable)) {
    $stmt = $pdo->prepare("
      SELECT COLUMN_NAME
      FROM information_schema.columns
      WHERE table_schema = DATABASE() AND table_name = ?
    ");
    $stmt->execute([$table]);
    $columnsByTable[$tableKey] = array_fill_keys(
      array_map('strtolower', $stmt->fetchAll(PDO::FETCH_COLUMN)),
      true
    );
  }
  return isset($columnsByTable[$tableKey][strtolower($column)]);
}

function hoLandingPickColumn(PDO $pdo, string $table, array $candidates): ?string
{
  foreach ($candidates as $candidate) {
    if (hoLandingColumnExists($pdo, $table, $candidate)) {
      return $candidate;
    }
  }
  return null;
}

function hoLandingOptimizedImagePath(?string $path): string
{
  $original = trim((string)$path);
  if ($original === '' || preg_match('~^(?:https?:|data:)~i', $original)) {
    return $original;
  }

  $normalized = ltrim(str_replace('\\', '/', $original), '/');
  if (str_starts_with($normalized, 'upload/')) {
    $normalized = 'php/' . $normalized;
  }
  $candidate = preg_replace('/\.(?:png|jpe?g)$/i', '.optimized.webp', $normalized);
  $candidatePaths = $candidate ? [$candidate] : [];
  if ($candidate && !str_contains($candidate, '/')) {
    $candidatePaths[] = 'php/upload/' . $candidate;
    $candidatePaths[] = 'uploads/' . $candidate;
  }
  foreach ($candidatePaths as $candidatePath) {
    if (is_file(dirname(__DIR__) . '/' . $candidatePath)) {
      return $candidatePath;
    }
  }

  return $original;
}

// Fetch tour packages
$packagesFromDb = [];
try {
  if (hoLandingTableExists($pdo, 'tour_packages')) {
    $idCol = hoLandingPickColumn($pdo, 'tour_packages', ['package_id', 'tour_package_id', 'id']);
    $nameCol = hoLandingPickColumn($pdo, 'tour_packages', ['package_title', 'package_name', 'name']);
    $locationCol = hoLandingPickColumn($pdo, 'tour_packages', ['destination', 'location']);
    $durationCol = hoLandingPickColumn($pdo, 'tour_packages', ['duration']);
    $typeCol = hoLandingPickColumn($pdo, 'tour_packages', ['package_type', 'type']);
    $rangeCol = hoLandingPickColumn($pdo, 'tour_packages', ['package_range', 'range']);
    $priceCol = hoLandingPickColumn($pdo, 'tour_packages', ['price', 'package_price']);
    $imgCol = hoLandingPickColumn($pdo, 'tour_packages', ['package_image', 'image_path', 'image_url']);
    $statusCol = hoLandingPickColumn($pdo, 'tour_packages', ['status']);
    $popularCol = hoLandingPickColumn($pdo, 'tour_packages', ['popular']);

    if ($idCol && $nameCol) {
      $operatorJoin = "";
      $operatorSelect = "'Unknown Operator' AS operator_name";
      $operatorStatusCondition = "";
      if (
        hoLandingTableExists($pdo, 'operators') &&
        hoLandingColumnExists($pdo, 'tour_packages', 'operator_id') &&
        hoLandingColumnExists($pdo, 'operators', 'operator_id')
      ) {
        $operatorNameCol = hoLandingPickColumn($pdo, 'operators', ['fullname', 'full_name', 'name']);
        $operatorStatusCol = hoLandingPickColumn($pdo, 'operators', ['status']);
        if ($operatorNameCol) {
          $operatorJoin = " LEFT JOIN operators o ON o.operator_id = tp.operator_id ";
          $operatorSelect = "COALESCE(o.`{$operatorNameCol}`, 'Unknown Operator') AS operator_name";
          if ($operatorStatusCol) {
            $operatorStatusCondition = "LOWER(TRIM(o.`{$operatorStatusCol}`)) = 'active'";
          }
        }
      }

      $reviewJoin = "";
      $reviewSelect = "0 AS rating, 0 AS total_reviews";
      $reviewPackageCol = hoLandingColumnExists($pdo, 'feedback', 'package_id')
        ? 'package_id'
        : (hoLandingColumnExists($pdo, 'feedback', 'tour_package_id') ? 'tour_package_id' : null);

      if (
        hoLandingTableExists($pdo, 'feedback') &&
        $reviewPackageCol !== null &&
        hoLandingColumnExists($pdo, 'feedback', 'rating')
      ) {
        $reviewJoin = "
          LEFT JOIN (
            SELECT
              `{$reviewPackageCol}` AS package_ref,
              COALESCE(ROUND(AVG(rating), 1), 0) AS rating,
              COUNT(rating) AS total_reviews
            FROM feedback
            WHERE moderation_status = 'published'
            GROUP BY `{$reviewPackageCol}`
          ) fr ON fr.package_ref = tp.`{$idCol}`
        ";
        $reviewSelect = "COALESCE(fr.rating, 0) AS rating, COALESCE(fr.total_reviews, 0) AS total_reviews";
      }

      $packageStatusCondition = $statusCol
        ? "LOWER(TRIM(tp.`{$statusCol}`)) = 'active'"
        : "";
      $whereConditions = [];
      if ($packageStatusCondition !== "") {
        $whereConditions[] = $packageStatusCondition;
      }
      if ($operatorStatusCondition !== "") {
        $whereConditions[] = $operatorStatusCondition;
      }
      $whereClause = !empty($whereConditions)
        ? " WHERE " . implode(" AND ", $whereConditions) . " "
        : "";
      $sqlPackages = "
        SELECT
          tp.`{$idCol}` AS id,
          tp.`{$nameCol}` AS name,
          " . ($locationCol ? "tp.`{$locationCol}`" : "'Mercedes'") . " AS location,
          " . ($durationCol ? "tp.`{$durationCol}`" : "'Tour Package'") . " AS duration,
          " . ($typeCol ? "tp.`{$typeCol}`" : "''") . " AS package_type,
          " . ($rangeCol ? "tp.`{$rangeCol}`" : "''") . " AS package_range,
          {$operatorSelect},
          " . ($priceCol ? "tp.`{$priceCol}`" : "0") . " AS price,
          " . ($imgCol ? "tp.`{$imgCol}`" : "'img/sampleimage.png'") . " AS img,
          " . ($popularCol ? "tp.`{$popularCol}`" : "0") . " AS popular,
          {$reviewSelect}
        FROM tour_packages tp
        {$operatorJoin}
        {$reviewJoin}
        {$whereClause}
        ORDER BY tp.`{$idCol}` ASC
        LIMIT 20
      ";
      $stmtPackages = $pdo->query($sqlPackages);
      $packagesFromDb = $stmtPackages ? $stmtPackages->fetchAll(PDO::FETCH_ASSOC) : [];
      foreach ($packagesFromDb as &$pkg) {
            $pkg['img'] = !empty($pkg['img'])
                ? trim($pkg['img'])
                : 'img/sampleimage.png';

            $pkg['price_formatted'] = number_format((float)$pkg['price'], 2);
        }
        unset($pkg);

      if (empty($packagesFromDb) && $packageStatusCondition !== "") {
        $fallbackWhereClause = $operatorStatusCondition !== ""
          ? " WHERE {$operatorStatusCondition} "
          : "";
        $sqlPackagesNoStatus = str_replace($whereClause, $fallbackWhereClause, $sqlPackages);
        $stmtPackagesNoStatus = $pdo->query($sqlPackagesNoStatus);
        $packagesFromDb = $stmtPackagesNoStatus ? $stmtPackagesNoStatus->fetchAll(PDO::FETCH_ASSOC) : [];
      }
    }
  }
} catch (Throwable $e) {
  $packagesFromDb = [];
}

$servicePrices = [];

$stmt = $pdo->query("
  SELECT service_type, day_tour_price, overnight_price
  FROM service_prices
  WHERE is_active = 1
");

foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $servicePrices[$row['service_type']] = [
        'day' => (float)$row['day_tour_price'],
        'overnight' => (float)$row['overnight_price']
    ];
}

// Fetch tour boats
$boatsFromDb = [];
try {
  $boatTable = hoLandingTableExists($pdo, 'tour_boats') ? 'tour_boats' : (hoLandingTableExists($pdo, 'boats') ? 'boats' : null);
  if ($boatTable) {
    $idCol = hoLandingPickColumn($pdo, $boatTable, ['tour_boat_id', 'boat_id', 'id']);
    $nameCol = hoLandingPickColumn($pdo, $boatTable, ['boat_name', 'name']);
    $capacityCol = hoLandingPickColumn($pdo, $boatTable, ['capacity', 'total_pax']);
    $priceCol = hoLandingPickColumn($pdo, $boatTable, ['price_per_head', 'price']);
    $imgCol = hoLandingPickColumn($pdo, $boatTable, ['boat_image_path', 'image1', 'image_path']);
    $sizeCol = hoLandingPickColumn($pdo, $boatTable, ['size', 'boat_size']);
    $numberCol = hoLandingPickColumn($pdo, $boatTable, ['boat_number', 'registration_number']);
    $shortDescriptionCol = hoLandingPickColumn($pdo, $boatTable, ['short_description', 'description']);
    $longDescriptionCol = hoLandingPickColumn($pdo, $boatTable, ['long_description', 'details']);
    $statusCol = hoLandingPickColumn($pdo, $boatTable, ['status']);
    $popularCol = hoLandingPickColumn($pdo, $boatTable, ['popular']);
    $boatImageColumns = [];
    for ($imageIndex = 1; $imageIndex <= 5; $imageIndex++) {
      $candidate = hoLandingPickColumn($pdo, $boatTable, ["image{$imageIndex}", "boat_image{$imageIndex}"]);
      if ($candidate) {
        $boatImageColumns[$imageIndex] = $candidate;
      }
    }

    if ($idCol && $nameCol) {
      $whereClause = $statusCol ? " WHERE b.`{$statusCol}` = 'active' " : "";
      $boatImageSelectSql = [];
      for ($imageIndex = 1; $imageIndex <= 5; $imageIndex++) {
        $imageColumn = $boatImageColumns[$imageIndex] ?? null;
        $boatImageSelectSql[] = $imageColumn
          ? "b.`{$imageColumn}` AS image{$imageIndex}"
          : "'' AS image{$imageIndex}";
      }
      $sqlBoats = "
        SELECT
  b.`{$idCol}` AS id,
  b.`{$nameCol}` AS name,
  " . ($capacityCol ? "b.`{$capacityCol}`" : "0") . " AS capacity,
  " . ($priceCol ? "b.`{$priceCol}`" : "0") . " AS price,
  " . ($imgCol ? "b.`{$imgCol}`" : "'img/sampleimage.png'") . " AS img,
  " . ($sizeCol ? "b.`{$sizeCol}`" : "''") . " AS size,
  " . ($numberCol ? "b.`{$numberCol}`" : "''") . " AS boat_number,
  " . ($shortDescriptionCol ? "b.`{$shortDescriptionCol}`" : "''") . " AS short_description,
  " . ($longDescriptionCol ? "b.`{$longDescriptionCol}`" : "''") . " AS long_description,
  " . implode(",\n  ", $boatImageSelectSql) . ",
  " . ($popularCol ? "b.`{$popularCol}`" : "0") . " AS popular,

  COALESCE(ROUND(AVG(f.rating), 1), 0) AS rating,
  COUNT(f.feedback_id) AS total_reviews

FROM {$boatTable} b

LEFT JOIN feedback f
  ON f.boat_id = b.`{$idCol}`
  AND f.booking_type = 'boat'
  AND f.moderation_status = 'published'

{$whereClause}

GROUP BY b.`{$idCol}`

ORDER BY b.`{$idCol}` DESC
LIMIT 20
";
      $stmtBoats = $pdo->query($sqlBoats);
      $boatsFromDb = $stmtBoats ? $stmtBoats->fetchAll(PDO::FETCH_ASSOC) : [];

      foreach ($boatsFromDb as &$boat) {
          $boat['price'] = $servicePrices['boat']['day'] ?? 0;
          $boat['images'] = array_values(array_unique(array_filter(array_map(
              static fn($image): string => trim((string)$image),
              [
                  $boat['image1'] ?? '',
                  $boat['image2'] ?? '',
                  $boat['image3'] ?? '',
                  $boat['image4'] ?? '',
                  $boat['image5'] ?? '',
                  $boat['img'] ?? ''
              ]
          ))));
      }
      unset($boat);

      if (empty($boatsFromDb) && $statusCol) {
        $sqlBoatsNoStatus = str_replace($whereClause, "", $sqlBoats);
        $stmtBoatsNoStatus = $pdo->query($sqlBoatsNoStatus);
        $boatsFromDb = $stmtBoatsNoStatus ? $stmtBoatsNoStatus->fetchAll(PDO::FETCH_ASSOC) : [];
      }
    }
  }
} catch (Throwable $e) {
  $boatsFromDb = [];
}

foreach ($boatsFromDb as &$boat) {
    $boat['images'] = array_values(array_unique(array_filter(array_map(
        static fn($image): string => trim((string)$image),
        [
            $boat['image1'] ?? '',
            $boat['image2'] ?? '',
            $boat['image3'] ?? '',
            $boat['image4'] ?? '',
            $boat['image5'] ?? '',
            $boat['img'] ?? ''
        ]
    ))));
    $boat['price_formatted'] = number_format((float)$boat['price'], 2);
}
unset($boat);



// Fetch tour guides
$guidesFromDb = [];
try {
  if (hoLandingTableExists($pdo, 'tour_guides')) {
    $idCol = hoLandingPickColumn($pdo, 'tour_guides', ['guide_id', 'tour_guide_id', 'id']);
    $nameCol = hoLandingPickColumn($pdo, 'tour_guides', ['fullname', 'guide_name', 'name']);
    $specializationCol = hoLandingPickColumn($pdo, 'tour_guides', ['specialization', 'short_description']);
    $descriptionCol = hoLandingPickColumn($pdo, 'tour_guides', ['short_description', 'description']);
    $ageCol = hoLandingPickColumn($pdo, 'tour_guides', ['age']);
    $experienceCol = hoLandingPickColumn($pdo, 'tour_guides', ['experience', 'years_experience']);
    $priceCol = hoLandingPickColumn($pdo, 'tour_guides', ['price_per_day', 'daily_rate', 'price']);
    $imgCol = hoLandingPickColumn($pdo, 'tour_guides', ['profile_picture', 'guide_image_path', 'image_path']);
    $statusCol = hoLandingPickColumn($pdo, 'tour_guides', ['status']);
    $popularCol = hoLandingPickColumn($pdo, 'tour_guides', ['popular']);

    if ($idCol && $nameCol) {
      $whereClause = $statusCol ? " WHERE g.`{$statusCol}` = 'active' " : "";
      $sqlGuides = "
        SELECT
  g.`{$idCol}` AS id,
  g.`{$nameCol}` AS name,
  " . ($specializationCol ? "g.`{$specializationCol}`" : "'Tour Guide'") . " AS specialization,
  " . ($descriptionCol ? "g.`{$descriptionCol}`" : "''") . " AS description,
  " . ($ageCol ? "g.`{$ageCol}`" : "0") . " AS age,
  " . ($experienceCol ? "g.`{$experienceCol}`" : "0") . " AS experience,
  " . ($priceCol ? "g.`{$priceCol}`" : "0") . " AS price,
  " . ($imgCol ? "g.`{$imgCol}`" : "'img/sampleimage.png'") . " AS img,
  " . ($popularCol ? "g.`{$popularCol}`" : "0") . " AS popular,

  COALESCE(ROUND(AVG(f.rating), 1), 0) AS rating,
  COUNT(f.feedback_id) AS total_reviews

FROM tour_guides g

LEFT JOIN feedback f
  ON f.tourguide_id = g.`{$idCol}`
  AND f.booking_type = 'tourguide'
  AND f.moderation_status = 'published'

{$whereClause}

GROUP BY g.`{$idCol}`

ORDER BY g.`{$idCol}` DESC
LIMIT 20
";
      $stmtGuides = $pdo->query($sqlGuides);
      $guidesFromDb = $stmtGuides ? $stmtGuides->fetchAll(PDO::FETCH_ASSOC) : [];
      foreach ($guidesFromDb as &$guide) {
          $guide['price'] = $servicePrices['tourguide']['day'] ?? 0;
      }
      unset($guide);

      if (empty($guidesFromDb) && $statusCol) {
        $sqlGuidesNoStatus = str_replace($whereClause, "", $sqlGuides);
        $stmtGuidesNoStatus = $pdo->query($sqlGuidesNoStatus);
        $guidesFromDb = $stmtGuidesNoStatus ? $stmtGuidesNoStatus->fetchAll(PDO::FETCH_ASSOC) : [];
      }
    }
  }
} catch (Throwable $e) {
  $guidesFromDb = [];
}

foreach ($guidesFromDb as &$guide) {
    $guide['price_formatted'] = number_format((float)$guide['price'], 2);
}
unset($guide);

foreach ($packagesFromDb as &$package) {
    $package['img'] = hoLandingOptimizedImagePath($package['img'] ?? '');
}
unset($package);

foreach ($boatsFromDb as &$boat) {
    $boat['img'] = hoLandingOptimizedImagePath($boat['img'] ?? '');
    $boat['images'] = array_values(array_map(
        static fn($image): string => hoLandingOptimizedImagePath((string)$image),
        $boat['images'] ?? []
    ));
}
unset($boat);

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
    $hotel['price_formatted'] = number_format((float)$hotel['price'], 2);
    $hotel['popular'] = (bool)$hotel['popular'];
    $hotel['rating'] = (float)$hotel['rating'];
    $hotel['total_reviews'] = (int)$hotel['total_reviews'];
    $hotel['review_message'] = $hotel['review_message'] ?: 'No review message yet.';
    $hotel['img'] = !empty($hotel['img'])
        ? hoLandingOptimizedImagePath($hotel['img'])
        : 'img/sampleimage.png';
    $decodedAmenities = json_decode($hotel['amenities_json'] ?? '[]', true);
    $hotel['amenities'] = is_array($decodedAmenities) ? $decodedAmenities : [];
    unset($hotel['amenities_json']);
}
unset($hotel);

?>

<!DOCTYPE html>
<html lang="en" class="tours-page-root">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover" />
  <meta name="description" content="<?= htmlspecialchars($seoDescription, ENT_QUOTES, 'UTF-8') ?>" />
  <link rel="canonical" href="<?= htmlspecialchars($seoCanonical, ENT_QUOTES, 'UTF-8') ?>" />
  <title><?= htmlspecialchars($seoTitle, ENT_QUOTES, 'UTF-8') ?></title>
  <link rel="icon" type="image/png" href="img/newlogo.png" />
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link rel="preconnect" href="https://cdn.jsdelivr.net" crossorigin />
  <link rel="preconnect" href="https://unpkg.com" crossorigin />
  <link rel="preconnect" href="https://cdnjs.cloudflare.com" crossorigin />
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet" />
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css" />
  <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="anonymous" />
  <link rel="stylesheet" href="styles/hotel_resorts.css?v=<?= (int)@filemtime(__DIR__ . '/../styles/hotel_resorts.css') ?>" />
  <link rel="stylesheet" href="styles/favorites.css" />
  <link rel="stylesheet" href="styles/back-to-top.css?v=<?= (int)@filemtime(__DIR__ . '/../styles/back-to-top.css') ?>" />
  <script>document.documentElement.classList.add('itour-page-loading');</script>
  <link rel="stylesheet" href="styles/page-loader.css?v=<?= (int)@filemtime(__DIR__ . '/../styles/page-loader.css') ?>" />
  <script src="js/page-loader.js?v=<?= (int)@filemtime(__DIR__ . '/../js/page-loader.js') ?>"></script>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/feather-icons/dist/feather.min.css" />
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
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
<?php include __DIR__ . '/../includes/page_loader.php'; ?>

<!-- Header -->
<div id="header"><?php include __DIR__ . '/../php/header.php'; ?></div>

<!-- TOUR GUIDE / BOAT DETAILS DRAWER -->
<div class="service-drawer-overlay" id="serviceDrawerOverlay" aria-hidden="true"></div>
<aside
  class="service-details-drawer"
  id="serviceDetailsDrawer"
  aria-hidden="true"
  aria-labelledby="serviceDrawerTitle"
  role="dialog"
  aria-modal="true">
  <div class="service-drawer-topbar">
    <a class="service-drawer-brand" href="./" aria-label="iTour Mercedes home">
      <img class="service-drawer-brand-seal" src="img/newlogo.png" alt="">
      <img class="service-drawer-brand-wordmark" src="img/textlogo2.png" alt="iTour Mercedes">
    </a>
    <div class="service-drawer-actions">
      <button
        class="favorite-toggle service-drawer-favorite"
        id="serviceDrawerFavorite"
        type="button"
        aria-label="Add to favorites"
        aria-pressed="false"
        title="Add to favorites"
      >
        <svg viewBox="0 0 24 24" aria-hidden="true">
          <path d="M12 20.4c-5.2-3.4-8.4-6.2-8.4-10a4.8 4.8 0 0 1 8.4-3.1 4.8 4.8 0 0 1 8.4 3.1c0 3.8-3.2 6.6-8.4 10Z"></path>
        </svg>
      </button>
      <button class="service-drawer-close" id="serviceDrawerClose" type="button" aria-label="Close details">
        <i class="fa-solid fa-xmark" aria-hidden="true"></i>
      </button>
    </div>
  </div>

  <div class="service-drawer-scroll">
    <div class="service-gallery" id="serviceGallery">
      <img id="serviceGalleryImage" src="img/sampleimage.png" alt="">
      <div class="service-gallery-shade"></div>
      <span class="service-gallery-badge" id="serviceGalleryBadge">Tour Service</span>
      <span class="service-gallery-count" id="serviceGalleryCount">1 / 1</span>
      <button class="service-gallery-expand" id="serviceGalleryExpand" type="button" aria-label="Expand image">
        <i class="fa-solid fa-expand" aria-hidden="true"></i>
      </button>
      <button class="service-gallery-nav service-gallery-prev" id="serviceGalleryPrev" type="button" aria-label="Previous image">
        <i class="fa-solid fa-chevron-left" aria-hidden="true"></i>
      </button>
      <button class="service-gallery-nav service-gallery-next" id="serviceGalleryNext" type="button" aria-label="Next image">
        <i class="fa-solid fa-chevron-right" aria-hidden="true"></i>
      </button>
      <div class="service-gallery-dots" id="serviceGalleryDots" aria-label="Gallery navigation"></div>
    </div>

    <div class="service-drawer-content">
      <div class="service-drawer-heading">
        <p class="service-drawer-eyebrow" id="serviceDrawerEyebrow">Mercedes tour service</p>
        <h2 id="serviceDrawerTitle">Service Details</h2>
        <div class="service-drawer-rating" id="serviceDrawerRating"></div>
      </div>

      <div class="service-detail-stats" id="serviceDetailStats"></div>

      <section class="service-description-section">
        <h3>About this service</h3>
        <p id="serviceDrawerDescription"></p>
      </section>

      <div class="service-assurance">
        <span class="service-assurance-icon" aria-hidden="true"><i class="fa-solid fa-shield-heart"></i></span>
        <div>
          <strong>Plan with confidence</strong>
          <span>Booking requests are reviewed and confirmed by the Mercedes tourism team.</span>
        </div>
      </div>
    </div>
  </div>

  <div class="service-drawer-footer">
    <div class="service-drawer-price">
      <span>Service rate</span>
      <strong id="serviceDrawerPrice">₱0</strong>
      <small id="serviceDrawerPriceUnit">per service</small>
    </div>
    <a class="service-drawer-book-btn" id="serviceDrawerBookBtn" href="#">
      <span>Book Now</span>
      <i class="fa-solid fa-arrow-right" aria-hidden="true"></i>
    </a>
  </div>
</aside>

<div class="service-image-modal" id="serviceImageModal" aria-hidden="true" role="dialog" aria-modal="true" aria-label="Expanded service gallery">
  <div class="service-image-modal-backdrop" id="serviceImageModalBackdrop"></div>
  <div class="service-image-modal-dialog">
    <div class="service-image-modal-toolbar">
      <span id="serviceImageModalCount">1 / 1</span>
      <button id="serviceImageModalClose" type="button" aria-label="Close expanded image">
        <i class="fa-solid fa-xmark" aria-hidden="true"></i>
      </button>
    </div>
    <div class="service-image-modal-stage">
      <img id="serviceImageModalImage" src="img/sampleimage.png" alt="">
      <button class="service-image-modal-nav service-image-modal-prev" id="serviceImageModalPrev" type="button" aria-label="Previous image">
        <i class="fa-solid fa-chevron-left" aria-hidden="true"></i>
      </button>
      <button class="service-image-modal-nav service-image-modal-next" id="serviceImageModalNext" type="button" aria-label="Next image">
        <i class="fa-solid fa-chevron-right" aria-hidden="true"></i>
      </button>
    </div>
    <p class="service-image-modal-caption" id="serviceImageModalCaption"></p>
  </div>
</div>

<!-- HERO SECTION -->
<section class="hotel-hero<?= $isToursLanding ? ' hotel-hero--tours' : '' ?>" id="heroSection">
  <div class="hotel-hero-overlay">
    <?php if ($isToursLanding): ?>
      <span class="hotel-hero-kicker"><i aria-hidden="true"></i> Curated island experiences</span>
      <h1>Discover Tours <span>in Mercedes</span></h1>
    <?php else: ?>
      <h1>Find Your Perfect Stay in Mercedes</h1>
    <?php endif; ?>
    <p><?= $isToursLanding ? 'Island experiences made for your next escape' : 'Hotels & Resorts Near the Islands' ?></p>
  </div>
</section>

<!-- SEARCH BAR -->
<section class="hotel-search" id="searchSection">
  <div class="mobile-hotel-results-heading" aria-label="Hotel search results">
    <a class="mobile-hotel-results-back" href="hotel_resorts.php?tab=tours" aria-label="Back to tours">
      <i class="fa-solid fa-chevron-left" aria-hidden="true"></i>
    </a>
    <strong>Hotels &amp; Resorts available options</strong>
    <span id="mobileHotelResultsCount">0 results</span>
  </div>
  <div class="search-shell">
    <div class="search-mode-tabs" role="tablist" aria-label="Search categories">

      <button type="button" class="search-mode-tab active" data-search-tab="hotels" role="tab" aria-selected="true">
        <i class="fa-solid fa-hotel tab-icon"></i>
        Hotel/Resort
      </button>

      <button type="button" class="search-mode-tab" data-search-tab="tours" role="tab" aria-selected="false">
        <i class="fa-solid fa-map-location-dot tab-icon"></i>
        Tour Packages
      </button>

      <button type="button" class="search-mode-tab" data-search-tab="guides" role="tab" aria-selected="false">
        <i class="fa-solid fa-user-tie tab-icon"></i>
        Tour Guide
      </button>

      <button type="button" class="search-mode-tab" data-search-tab="boats" role="tab" aria-selected="false">
        <i class="fa-solid fa-ship tab-icon"></i>
        Tour Boat
      </button>

    </div>
    <button type="button" class="mobile-search-summary" id="mobileHotelSearchToggle" aria-expanded="false" aria-controls="hotelSearchFields" onclick="toggleMobileHotelSearch()">
      <span class="mobile-search-summary-icon"><i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i></span>
      <span class="mobile-search-summary-copy">
        <strong>Search your stay</strong>
        <small id="mobileHotelSearchSummary">Choose destination, dates, and guests</small>
      </span>
      <i class="fa-solid fa-chevron-down mobile-search-summary-chevron" aria-hidden="true"></i>
    </button>
    <div class="search-container" id="hotelSearchFields">

    <!-- Island -->
    <div class="search-box location-search-box">
      <label id="locationLabel">Where to go?</label>
      <div class="location-input-wrap">
        <div class="search-control-wrap primary-location-wrap">
          <span class="search-control-icon" aria-hidden="true">
            <svg class="search-field-icon" width="16" height="16" viewBox="0 0 24 24" focusable="false">
              <path d="M20 10c0 5-8 11-8 11S4 15 4 10a8 8 0 1 1 16 0Z"></path>
              <circle cx="12" cy="10" r="2.5"></circle>
            </svg>
          </span>
          <select id="island" aria-label="Primary destination">
            <option value="" selected disabled>Choose primary destination</option>
            <option value="Apuao">Apuao</option>
            <option value="Malasugui">Malasugui</option>
            <option value="Quinapaguian">Quinapaguian</option>
            <option value="Cayucyucan">Cayucyucan</option>
            <option value="Caringo">Caringo</option>
            <option value="Canimog">Canimog</option>
          </select>
          <div class="field-error-slot" aria-live="polite"></div>
        </div>
        <div class="search-control-wrap secondary-location-wrap is-hidden">
          <span class="search-control-icon" aria-hidden="true">
            <svg class="search-field-icon" width="16" height="16" viewBox="0 0 24 24" focusable="false">
              <circle cx="12" cy="12" r="6"></circle>
              <circle cx="12" cy="12" r="2"></circle>
              <path d="M12 2v4M12 18v4M2 12h4M18 12h4"></path>
            </svg>
          </span>
          <select id="islandSecondary" class="search-second-location is-hidden" aria-label="Optional second destination">
            <option value="" selected>Add another destination (optional)</option>
            <option value="Apuao">Apuao</option>
            <option value="Malasugui">Malasugui</option>
            <option value="Quinapaguian">Quinapaguian</option>
            <option value="Cayucyucan">Cayucyucan</option>
            <option value="Caringo">Caringo</option>
            <option value="Canimog">Canimog</option>
          </select>
          <div class="field-error-slot" aria-live="polite"></div>
        </div>
      </div>
    </div>

    <!-- Date Range -->
    <div class="search-box search-date-box">
      <div class="date-field-heading">
        <label id="dateLabel">Check-in / Check-out</label>
        <div id="tourDateModeToggle" class="tour-date-mode-toggle" style="display: none;">
          <button type="button" class="date-mode-btn active" data-mode="overnight" onclick="setTourDateMode('overnight')">Overnight</button>
          <button type="button" class="date-mode-btn" data-mode="sameday" onclick="setTourDateMode('sameday')">Same Day</button>
        </div>
      </div>

      <div class="search-control-wrap date-input-wrap">
        <span class="search-control-icon" aria-hidden="true">
          <svg class="search-field-icon" width="16" height="16" viewBox="0 0 24 24" focusable="false">
            <rect x="3" y="5" width="18" height="16" rx="2"></rect>
            <path d="M8 3v4M16 3v4M3 10h18M8 14h.01M12 14h.01M16 14h.01M8 18h.01M12 18h.01M16 18h.01"></path>
          </svg>
        </span>
        <input type="text" id="dateRangePicker" placeholder="Select stay dates" readonly inputmode="none" virtualkeyboardpolicy="manual" autocomplete="off" aria-haspopup="dialog">
        <span id="stayDurationBadge" class="stay-duration-badge" hidden></span>
        <div class="field-error-slot" aria-live="polite"></div>
      </div>
      <input type="hidden" id="checkin">
      <input type="hidden" id="checkout">
    </div>

    <div class="search-box">
      <label id="guestLabel">Guests & Rooms</label>

      <div class="search-control-wrap guest-control-wrap">
        <div class="guest-display" onclick="toggleGuestBox()" role="button" tabindex="0" aria-label="Select guests and rooms">
          <span class="search-control-icon" aria-hidden="true"><i class="fa-solid fa-user-group"></i></span>
          <span id="guestText" class="placeholder">Guests & Rooms</span>
          <i class="fa-solid fa-chevron-down guest-chevron" aria-hidden="true"></i>
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

          <div id="roomsGuestRow" class="guest-row">
            <span>Rooms</span>
            <div>
              <button onclick="changeValue('rooms', -1)">-</button>
              <span id="rooms">0</span>
              <button onclick="changeValue('rooms', 1)">+</button>
            </div>
          </div>

          <div id="childAgeRows" class="child-age-list" aria-live="polite"></div>

          <div class="guest-actions">
            <button class="done-btn" onclick="applyGuestSelection()">Done</button>
          </div>
        </div>
        <div class="field-error-slot" aria-live="polite"></div>
      </div>
    </div>

      <!-- Search Button -->
      <div class="search-submit-wrap">
        <button class="search-btn" onclick="filterHotels()">
          <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
          <span class="search-btn-default-label">Search</span>
          <span class="search-btn-update-label">Update</span>
        </button>
        <div class="field-error-slot" aria-hidden="true"></div>
      </div>

    </div>
  </div>
</section>

<!-- RECENTLY VIEWED -->
<section id="recentlyViewedSection" class="recently-viewed-section" hidden>
  <div class="recently-viewed-header">
    <div class="carousel-heading-copy">
      <span class="carousel-section-eyebrow">Continue exploring</span>
      <h2 class="carousel-title">Recently viewed</h2>
    </div>
    <div class="carousel-controls">
      <button class="carousel-nav-btn" type="button" onclick="scrollRecentlyViewed(-1)" aria-label="Previous recently viewed items">
        <i class="fa-solid fa-chevron-left" aria-hidden="true"></i>
      </button>
      <button class="carousel-nav-btn" type="button" onclick="scrollRecentlyViewed(1)" aria-label="Next recently viewed items">
        <i class="fa-solid fa-chevron-right" aria-hidden="true"></i>
      </button>
    </div>
  </div>
  <div id="recentlyViewedTrack" class="recently-viewed-track"></div>
</section>

<!-- FEATURED RESORTS (VISIBLE ONLY BEFORE SEARCH) -->
<section id="featuredSection" class="hotel-featured-section">
  <div class="carousel-header featured-carousel-header">
    <div class="carousel-heading-copy">
      <span class="carousel-section-eyebrow">Stay in Mercedes</span>
      <h2 class="carousel-title hotel-featured-title-main">Featured Hotels &amp; Resorts</h2>
    </div>
    <div class="carousel-controls">
      <button class="carousel-nav-btn carousel-prev" onclick="scrollFeatured(-1)" aria-label="Previous featured hotels">
        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <polyline points="15 18 9 12 15 6"></polyline>
        </svg>
      </button>
      <button class="carousel-nav-btn carousel-next" onclick="scrollFeatured(1)" aria-label="Next featured hotels">
        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <polyline points="9 18 15 12 9 6"></polyline>
        </svg>
      </button>
    </div>
  </div>
  <div class="hotel-featured-grid" id="featuredGrid"></div>
</section>

<!-- CAROUSEL SECTIONS -->
<section id="carouselSection" class="carousel-container">
  <!-- Tour Packages Carousel -->
  <div class="carousel-wrapper">
    <div class="carousel-header">
      <div class="carousel-heading-copy">
        <span class="carousel-section-eyebrow">Curated experiences</span>
        <h2 class="carousel-title">Popular Tour Packages</h2>
      </div>
      <div class="carousel-controls">
        <button class="carousel-nav-btn carousel-prev" onclick="scrollCarousel('packages', -1)" aria-label="Previous">
          <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <polyline points="15 18 9 12 15 6"></polyline>
          </svg>
        </button>
        <button class="carousel-nav-btn carousel-next" onclick="scrollCarousel('packages', 1)" aria-label="Next">
          <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <polyline points="9 18 15 12 9 6"></polyline>
          </svg>
        </button>
      </div>
    </div>
    <div class="carousel-scroll" id="carouselPackages">
      <!-- Populated by JavaScript -->
    </div>
  </div>

  <!-- Tour Boats Carousel -->
  <div class="carousel-wrapper">
    <div class="carousel-header">
      <div class="carousel-heading-copy">
        <span class="carousel-section-eyebrow">Explore the coast</span>
        <h2 class="carousel-title">Available Tour Boats</h2>
      </div>
      <div class="carousel-controls">
        <button class="carousel-nav-btn carousel-prev" onclick="scrollCarousel('boats', -1)" aria-label="Previous">
          <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <polyline points="15 18 9 12 15 6"></polyline>
          </svg>
        </button>
        <button class="carousel-nav-btn carousel-next" onclick="scrollCarousel('boats', 1)" aria-label="Next">
          <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <polyline points="9 18 15 12 9 6"></polyline>
          </svg>
        </button>
      </div>
    </div>
    <div class="carousel-scroll" id="carouselBoats">
      <!-- Populated by JavaScript -->
    </div>
  </div>

  <!-- Tour Guides Carousel -->
  <div class="carousel-wrapper">
    <div class="carousel-header">
      <div class="carousel-heading-copy">
        <span class="carousel-section-eyebrow">Travel with locals</span>
        <h2 class="carousel-title">Professional Tour Guides</h2>
      </div>
      <div class="carousel-controls">
        <button class="carousel-nav-btn carousel-prev" onclick="scrollCarousel('guides', -1)" aria-label="Previous">
          <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <polyline points="15 18 9 12 15 6"></polyline>
          </svg>
        </button>
        <button class="carousel-nav-btn carousel-next" onclick="scrollCarousel('guides', 1)" aria-label="Next">
          <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <polyline points="9 18 15 12 9 6"></polyline>
          </svg>
        </button>
      </div>
    </div>
    <div class="carousel-scroll" id="carouselGuides">
      <!-- Populated by JavaScript -->
    </div>
  </div>
</section>

<!-- MAIN CONTENT -->
<section class="hotel-main">
  <div class="mobile-results-controls">
    <nav class="mobile-results-nav" aria-label="Hotel result tools">
      <button type="button" id="mobileFilterToggle" aria-expanded="false" aria-controls="hotelFilterRail" onclick="toggleMobileResultsPanel('filter')">
        <i class="fa-solid fa-sliders" aria-hidden="true"></i><span>Filter</span>
      </button>
      <button type="button" id="mobileSortToggle" aria-expanded="false" aria-controls="mobileSortPanel" onclick="toggleMobileResultsPanel('sort')">
        <i class="fa-solid fa-arrow-down-wide-short" aria-hidden="true"></i><span>Sort</span>
      </button>
      <button type="button" onclick="openHotelMap()">
        <i class="fa-solid fa-map-location-dot" aria-hidden="true"></i><span>Map</span>
      </button>
    </nav>
    <div class="mobile-sort-panel" id="mobileSortPanel" hidden></div>
  </div>
  <div class="hotel-filter-rail" id="hotelFilterRail">
    <aside class="hotel-filter-column" aria-label="Search filters">
    <div class="hotel-map-preview">
      <div id="hotelMapPreview" class="hotel-map-preview-canvas" aria-hidden="true"></div>
      <div class="hotel-map-preview-shade"></div>
      <button type="button" class="show-map-btn" onclick="openHotelMap()">
        <i class="fa-solid fa-map-location-dot" aria-hidden="true"></i>
        <span>Show on map</span>
      </button>
    </div>

    <div class="hotel-filter">
      <div class="filter-heading">
        <div>
          <span class="filter-eyebrow">Refine your stay</span>
          <h3>Filter by</h3>
        </div>
        <button type="button" class="filter-reset" onclick="resetHotelFilters()">Reset</button>
      </div>

      <div class="filter-group">
        <h4>Popular filters</h4>
        <label class="filter-option">
          <input type="checkbox" class="filter" value="popular">
          <span class="filter-check"><i class="fa-solid fa-check"></i></span>
          <span>Most popular</span>
        </label>
        <label class="filter-option">
          <input type="checkbox" class="filter" value="reviews">
          <span class="filter-check"><i class="fa-solid fa-check"></i></span>
          <span>Highest reviews</span>
        </label>
      </div>

      <div class="filter-group">
        <h4>Property type</h4>
        <label class="filter-option">
          <input type="checkbox" class="filter" value="hotel">
          <span class="filter-check"><i class="fa-solid fa-check"></i></span>
          <span>Hotels</span>
        </label>
        <label class="filter-option">
          <input type="checkbox" class="filter" value="resort">
          <span class="filter-check"><i class="fa-solid fa-check"></i></span>
          <span>Resorts</span>
        </label>
      </div>

      <div class="price-filter filter-group">
        <div class="price-filter-heading">
          <h4>Price per night</h4>
          <span>PHP</span>
        </div>
        <p class="price-filter-help">Set your preferred nightly budget.</p>
        <div class="price-value-fields" aria-live="polite">
          <div><span>Minimum</span><strong>₱<span id="minPrice">200</span></strong></div>
          <span class="price-value-separator">—</span>
          <div><span>Maximum</span><strong>₱<span id="maxPrice">6000</span></strong></div>
        </div>
        <div class="price-slider">
          <div class="price-track" aria-hidden="true"></div>
          <input type="range" id="rangeMin" min="200" max="6000" value="200" step="200" aria-label="Minimum nightly price">
          <input type="range" id="rangeMax" min="200" max="6000" value="6000" step="200" aria-label="Maximum nightly price">
        </div>
      </div>
    </div>
    </aside>
  </div>

  <div class="hotel-list-section">
    <div class="hotel-results-toolbar">
      <div class="hotel-results-heading">
        <a class="hotel-results-back" href="hotel_resorts.php?tab=tours" aria-label="Back to tours">
          <i class="fa-solid fa-chevron-left" aria-hidden="true"></i>
          <span>Back to tours</span>
        </a>
        <div class="hotel-results-heading-copy">
          <h2 id="hotelResultsTitle">Available properties</h2>
          <p id="hotelResultsCount" class="hotel-results-count"></p>
        </div>
      </div>
      <div class="hotel-sort" aria-label="Sort hotel results">
        <button class="active" onclick="sortHotels('recommended', this)">
          <i class="fa-solid fa-wand-magic-sparkles" aria-hidden="true"></i> Recommended
        </button>
        <button onclick="sortHotels('price', this)">
          <i class="fa-solid fa-arrow-down-short-wide" aria-hidden="true"></i> Price
        </button>
        <button onclick="sortHotels('rating', this)">
          <i class="fa-regular fa-star" aria-hidden="true"></i> Guest rating
        </button>
      </div>
    </div>
    <div id="hotelList" class="hotel-list"></div>
  </div>
</section>

<div class="hotel-map-modal" id="hotelMapModal" aria-hidden="true" role="dialog" aria-modal="true" aria-labelledby="hotelMapTitle">
  <div class="hotel-map-dialog">
    <div class="hotel-map-topbar">
      <div>
        <span class="filter-eyebrow">Map search</span>
        <h2 id="hotelMapTitle">Stays around Mercedes</h2>
      </div>
      <button type="button" class="hotel-map-close" onclick="closeHotelMap()" aria-label="Close map">
        <span>Close map</span><i class="fa-solid fa-xmark" aria-hidden="true"></i>
      </button>
    </div>
    <div class="hotel-map-layout">
      <aside class="hotel-map-results">
        <p id="hotelMapCount" class="hotel-map-count"></p>
        <div id="hotelMapList"></div>
      </aside>
      <div id="hotelMap" class="hotel-map-canvas" aria-label="Map of hotel and resort results"></div>
    </div>
  </div>
</div>

<!-- Login/Signup Modal -->
<div id="loginModal"></div>

<?php
$footerVariant = 'tours';
include 'footer.php';
?>

<script src="js/header.js?v=<?= (int)@filemtime(__DIR__ . '/../js/header.js') ?>"></script>
<script>
  window.RecentlyViewedConfig = {
    accountId: <?= json_encode((string)($_SESSION['tourist_id'] ?? '')) ?>
  };
</script>
<script src="js/recently_viewed.js?v=<?= (int)@filemtime(__DIR__ . '/../js/recently_viewed.js') ?>"></script>
<script>
  window.FavoritesConfig = {
    endpoint: 'php/favorites_api.php',
    csrfToken: <?= json_encode($favoritesCsrf) ?>,
    loginUrl: 'login.php'
  };
  const serviceFavoriteIds = <?= json_encode($favoriteIds, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
</script>
<script src="js/favorites.js"></script>
<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin="anonymous"></script>
<script>


  const hotels = <?= json_encode($hotelsFromDb, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
  const tourPackages = <?= json_encode($packagesFromDb, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
  const tourBoats = <?= json_encode($boatsFromDb, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
  const tourGuides = <?= json_encode($guidesFromDb, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

  let filtered = [...hotels];
  let visibleHotels = [...hotels];
  let hotelMap = null;
  let hotelMapPreview = null;
  let hotelMapMarkers = [];
  let hotelPreviewMarkers = [];
  let mappedHotels = [...hotels];
  const MERCEDES_MAP_CENTER = [14.0865, 123.065];
  const HOTEL_ISLAND_COORDINATES = {
    "apuao": [14.122, 123.071],
    "malasugui": [14.084, 123.085],
    "quinapaguian": [14.0695, 123.069],
    "cayucyucan": [14.071, 123.038],
    "caringo": [14.052, 123.101],
    "canimog": [14.107, 123.105],
    "mercedes": [14.109, 123.011]
  };
  const SEARCH_TAB_ALIASES = {
    "hotel": "hotels",
    "hotel-resorts": "hotels",
    "hotel_resorts": "hotels",
    "tour-packages": "tours",
    "packages": "tours",
    "tour-guides": "guides",
    "tour-guide": "guides",
    "tour-operators": "boats",
    "tour-operator": "boats",
    "tour-boat": "boats",
    "boat": "boats",
    "operators": "boats",
    "our-boats": "boats"
  };
  let activeSearchTab = "hotels";
  let tourDateMode = "overnight";
  const TOUR_TABS = new Set(["tours", "guides", "boats"]);

  function isTourSearchTab(tab = activeSearchTab) {
    return TOUR_TABS.has(tab);
  }

  function getSelectedSearchLocations() {
    const primary = String(document.getElementById("island")?.value || "").trim();
    const secondary = String(document.getElementById("islandSecondary")?.value || "").trim();
    const values = [];
    if (primary) values.push(primary);
    if (secondary && secondary !== primary) values.push(secondary);
    return values.slice(0, 2);
  }

  function formatPrice(value) {
  return Number(value).toLocaleString('en-PH', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2
  });
}

  function recentlyViewedTypeLabel(type) {
    return ({
      hotel: "Hotel",
      resort: "Resort",
      package: "Tour Package",
      guide: "Tour Guide",
      boat: "Tour Boat"
    })[String(type || "").toLowerCase()] || "Travel service";
  }

  function openRecentlyViewed(type, itemId) {
    const normalizedType = String(type || "").toLowerCase();
    const normalizedId = Number(itemId) || 0;
    if (normalizedId <= 0) return;
    const key = `${normalizedType}:${normalizedId}`;
    const storedItem = window.RecentlyViewed?.getAll().find(item => item.key === key);
    if (storedItem) window.RecentlyViewed.add(storedItem);

    if (normalizedType === "boat" || normalizedType === "guide") {
      openServiceDetails(normalizedType, normalizedId);
      return;
    }

    if (normalizedType === "hotel" || normalizedType === "resort") {
      // Reuse the normal property-card path so the property's location is
      // prefilled on the details search and the page opens in a new tab.
      openHotelDetails(normalizedId, "featured");
      return;
    }

    if (normalizedType === "package") {
      clearActiveSearchTabForExit();
      window.location.href = `package_details.php?package_id=${encodeURIComponent(normalizedId)}`;
    }
  }

  function displayRecentlyViewed() {
    const section = document.getElementById("recentlyViewedSection");
    const track = document.getElementById("recentlyViewedTrack");
    if (!section || !track || !window.RecentlyViewed) return;

    const items = window.RecentlyViewed.getAll();
    section.hidden = items.length === 0;
    if (!items.length) {
      track.innerHTML = "";
      return;
    }

    track.innerHTML = items.map(item => {
      const type = String(item.type || "").toLowerCase();
      const price = Number(item.price || 0);
      const fallbackType = ["package", "guide", "boat"].includes(type) ? type : "generic";
      const itemImage = normalizeImagePath(item.image, fallbackType);
      const isStay = type === "hotel" || type === "resort";
      const liveHotel = isStay
        ? hotels.find(hotel => String(hotel.id) === String(item.id))
        : null;
      const itemRating = Number(liveHotel?.rating ?? item.rating ?? 0);
      const itemReviewCount = Math.max(0, Number(liveHotel?.total_reviews ?? item.reviewCount ?? 0));
      const priceLabel = isStay ? "as low as" : "from";
      const actionLabel = isStay ? "View Property" : "View Details";
      return `
        <article class="hotel-featured-card recently-viewed-card hotel-card-link" data-recent-type="${escapeHtml(type)}" data-recent-id="${escapeHtml(item.id)}" tabindex="0" role="button">
          <div class="hotel-featured-img-wrap">
            <img class="hotel-featured-img" src="${escapeHtml(itemImage)}" alt="${escapeHtml(item.name)}" loading="lazy" decoding="async" onerror="this.onerror=null;this.src='${getFallbackImage(fallbackType)}'">
            <span class="hotel-featured-badge ${escapeHtml(type)}">${recentlyViewedTypeLabel(type)}</span>
          </div>
          <div class="hotel-featured-info">
            <h3 class="hotel-featured-title">${escapeHtml(item.name)}</h3>
            <p class="hotel-featured-location">${escapeHtml(item.subtitle || recentlyViewedTypeLabel(type))}</p>
            <div class="hotel-featured-reviews">
              ${renderStars(itemRating, itemReviewCount, "featured")}
            </div>
            <div class="hotel-featured-price-wrap">
              <span class="hotel-featured-price-label">${priceLabel}</span>
              <div class="hotel-featured-price">
                ${price > 0 ? `₱${price.toLocaleString("en-PH")}` : "See rates"}
                <span>${escapeHtml(item.priceUnit || "")}</span>
              </div>
            </div>
            <button class="hotel-featured-btn" type="button">${actionLabel}</button>
          </div>
        </article>
      `;
    }).join("");
    track.querySelectorAll('[data-recent-type][data-recent-id]').forEach(card => {
      const open = () => openRecentlyViewed(card.dataset.recentType, card.dataset.recentId);
      card.addEventListener('click', open);
      card.addEventListener('keydown', event => {
        if (event.key === 'Enter' || event.key === ' ') {
          event.preventDefault();
          open();
        }
      });
      card.querySelector('.hotel-featured-btn')?.addEventListener('click', event => {
        event.stopPropagation();
        open();
      });
    });
  }

  function scrollRecentlyViewed(direction) {
    const track = document.getElementById("recentlyViewedTrack");
    if (!track) return;
    const cardWidth = track.querySelector(".recently-viewed-card")?.getBoundingClientRect().width || 290;
    track.scrollBy({ left: direction * (cardWidth + 16) * 3, behavior: "smooth" });
  }

  function buildTourDurationLabel(checkinValue, checkoutValue, mode) {
    if (!checkinValue || !checkoutValue) return "";
    if (mode === "sameday") return "1 Day";
    const checkinDate = new Date(checkinValue);
    const checkoutDate = new Date(checkoutValue);
    checkinDate.setHours(0, 0, 0, 0);
    checkoutDate.setHours(0, 0, 0, 0);
    const nights = Math.round((checkoutDate - checkinDate) / 86400000);
    if (nights <= 0) return "";
    const days = nights + 1;
    return `${days} Day${days > 1 ? "s" : ""} ${nights} Night${nights > 1 ? "s" : ""}`;
  }

  function normalizeSearchTab(value) {
    const normalized = String(value || "").trim().toLowerCase();
    return SEARCH_TAB_ALIASES[normalized] || normalized || "hotels";
  }

  const ACTIVE_SEARCH_TAB_KEY = "hotelResortsActiveTab";
  const IS_TOURS_PAGE_CONTEXT = <?= $isToursLanding ? 'true' : 'false' ?>;

  function clearActiveSearchTabForExit() {
    sessionStorage.removeItem(ACTIVE_SEARCH_TAB_KEY);
  }

  function syncSearchPageSeo(tab) {
    const isToursPage = IS_TOURS_PAGE_CONTEXT || isTourSearchTab(tab);
    const title = isToursPage
      ? "Island Hopping & Tour Packages in Mercedes, Camarines Norte | iTour Mercedes"
      : "Hotels & Resorts in Mercedes, Camarines Norte | iTour Mercedes";
    const description = isToursPage
      ? "Explore island hopping adventures, tour packages, local tour guides, and boat services in Mercedes, Camarines Norte."
      : "Discover hotels and resorts in Mercedes, Camarines Norte. Explore available accommodations and plan your stay with iTour Mercedes.";
    const canonicalUrl = isToursPage
      ? "https://itourmercedes.com/hotel_resorts.php?tab=tours"
      : "https://itourmercedes.com/hotel_resorts.php";

    document.title = title;
    document.querySelector('meta[name="description"]')?.setAttribute("content", description);
    document.querySelector('link[rel="canonical"]')?.setAttribute("href", canonicalUrl);

    const currentUrl = new URL(window.location.href);
    currentUrl.searchParams.delete("reset_search");
    if (isToursPage) {
      currentUrl.searchParams.set("tab", "tours");
      if (tab === "hotels") {
        currentUrl.searchParams.delete("search_tab");
      } else {
        currentUrl.searchParams.set("search_tab", tab);
      }
    } else {
      currentUrl.searchParams.delete("tab");
      currentUrl.searchParams.delete("search_tab");
    }
    window.history.replaceState({
      ...(window.history.state || {}),
      itourSearchTab: tab
    }, "", currentUrl);
  }

  function setActiveSearchTab(tabId) {
    const normalizedTab = normalizeSearchTab(tabId);
    const allowedTabs = new Set(["hotels", "tours", "guides", "boats"]);
    activeSearchTab = allowedTabs.has(normalizedTab) ? normalizedTab : "hotels";
    sessionStorage.setItem(ACTIVE_SEARCH_TAB_KEY, activeSearchTab);
    syncSearchPageSeo(activeSearchTab);

    document.querySelectorAll(".search-mode-tab").forEach(btn => {
      const isActive = btn.dataset.searchTab === activeSearchTab;
      btn.classList.toggle("active", isActive);
      btn.setAttribute("aria-selected", isActive ? "true" : "false");
    });

    const searchSection = document.getElementById("searchSection");
    const guestLabel = document.getElementById("guestLabel");
    const locationLabel = document.getElementById("locationLabel");
    const dateLabel = document.getElementById("dateLabel");
    const roomsGuestRow = document.getElementById("roomsGuestRow");
    const tourDateModeToggle = document.getElementById("tourDateModeToggle");
    const islandSecondary = document.getElementById("islandSecondary");
    const islandSecondaryWrap = islandSecondary.closest(".secondary-location-wrap");
    const isHotelTab = activeSearchTab === "hotels";
    const isTourTab = isTourSearchTab(activeSearchTab);

    const searchContainer = document.querySelector(".search-container");

    // Every tour-service tab uses the two-stop route layout. Tying this
    // directly to the tab avoids a stale visibility check during tab changes.
    searchContainer.classList.toggle("double-location", isTourTab);

    searchSection.dataset.searchTab = activeSearchTab;
    locationLabel.textContent = isTourTab ? "Destinations (up to 2)" : "Where to go?";
    dateLabel.textContent = isTourTab ? "Stay dates" : "Check-in / Check-out";
    guestLabel.textContent = isHotelTab ? "Guests & Rooms" : "Guests";
    roomsGuestRow.classList.toggle("is-hidden", !isHotelTab);
    tourDateModeToggle.style.display = isTourTab ? "flex" : "none";
    islandSecondary.classList.toggle("is-hidden", !isTourTab);
    if (islandSecondaryWrap) {
      islandSecondaryWrap.classList.toggle("is-hidden", !isTourTab);
    }
    if (!isTourTab) {
      islandSecondary.value = "";
      clearError(islandSecondary);
    }
    applyGuestSelection(false);
    updateMobileHotelSearchSummary();
  }

  function setTourDateMode(mode) {
    if (mode !== "sameday" && mode !== "overnight") return;
    tourDateMode = mode;

    const btns = document.querySelectorAll(".date-mode-btn");
    btns.forEach(btn => btn.classList.toggle("active", btn.dataset.mode === mode));

    const checkin = document.getElementById("checkin");
    const checkout = document.getElementById("checkout");

    if (fp) {
      fp.destroy();
    }

    if (mode === "sameday") {
      initFlatpickrSingleDate();
    } else {
      initFlatpickrDateRange();
    }

    checkin.value = "";
    checkout.value = "";
    document.getElementById("dateRangePicker").value = "";
    document.getElementById("stayDurationBadge").hidden = true;
  }

  let fp = null;
  let searchDatePickerOpenTimer = 0;

  function suppressSearchDateKeyboard() {
    return window.matchMedia("(max-width: 1040px)").matches;
  }

  function lockSearchDateInput() {
    const dateRangeInput = document.getElementById("dateRangePicker");
    if (!dateRangeInput) return;
    dateRangeInput.readOnly = true;
    dateRangeInput.setAttribute("readonly", "readonly");
    dateRangeInput.setAttribute("inputmode", "none");
    dateRangeInput.setAttribute("virtualkeyboardpolicy", "manual");
  }

  function dismissSearchDateKeyboard() {
    if (!suppressSearchDateKeyboard()) return;
    lockSearchDateInput();
    [0, 60, 180, 360].forEach(delay => {
      window.setTimeout(() => {
        const activeElement = document.activeElement;
        if (activeElement instanceof HTMLElement && activeElement !== document.body) activeElement.blur();
        if (navigator.virtualKeyboard && typeof navigator.virtualKeyboard.hide === "function") {
          navigator.virtualKeyboard.hide();
        }
      }, delay);
    });
  }

  function openSearchDatePicker() {
    const dateRangeInput = document.getElementById("dateRangePicker");
    if (!dateRangeInput || !fp) return;
    lockSearchDateInput();
    dateRangeInput.blur();
    window.clearTimeout(searchDatePickerOpenTimer);

    if (window.matchMedia("(max-width: 768px)").matches) {
      const fieldTop = dateRangeInput.getBoundingClientRect().top;
      window.scrollBy({ top: fieldTop - 104, behavior: "smooth" });
      searchDatePickerOpenTimer = window.setTimeout(() => fp?.open(), 220);
      return;
    }

    if (suppressSearchDateKeyboard()) {
      fp.open();
      return;
    }
    fp.open();
  }

  function bindSearchDatePickerTrigger() {
    const dateRangeInput = document.getElementById("dateRangePicker");
    if (!dateRangeInput || dateRangeInput.dataset.pickerOnlyBound === "1") return;
    dateRangeInput.dataset.pickerOnlyBound = "1";
    dateRangeInput.addEventListener("pointerdown", event => {
      if (!suppressSearchDateKeyboard()) return;
      event.preventDefault();
      openSearchDatePicker();
    });
    dateRangeInput.addEventListener("focus", () => {
      if (!suppressSearchDateKeyboard()) return;
      requestAnimationFrame(() => dateRangeInput.blur());
    });
    dateRangeInput.addEventListener("click", event => {
      event.preventDefault();
      if (suppressSearchDateKeyboard()) return;
      openSearchDatePicker();
    });
    dateRangeInput.addEventListener("keydown", event => {
      if (event.key !== "Enter" && event.key !== " ") return;
      event.preventDefault();
      openSearchDatePicker();
    });
  }

  function updateMobileHotelSearchSummary() {
    const summary = document.getElementById("mobileHotelSearchSummary");
    if (!summary) return;
    const destination = document.getElementById("island")?.value || "Any destination";
    const dates = document.getElementById("dateRangePicker")?.value || "Choose dates";
    const guests = document.getElementById("guestText")?.textContent?.trim() || "Add guests";
    summary.textContent = `${destination} · ${dates} · ${guests}`;
  }

  function toggleMobileHotelSearch() {
    const searchSection = document.getElementById("searchSection");
    const toggle = document.getElementById("mobileHotelSearchToggle");
    if (!searchSection || !toggle) return;
    updateMobileHotelSearchSummary();
    const expanded = searchSection.classList.toggle("mobile-search-open");
    toggle.setAttribute("aria-expanded", expanded ? "true" : "false");
    if (expanded) {
      requestAnimationFrame(() => searchSection.scrollIntoView({ behavior: "smooth", block: "start" }));
    }
  }

  function closeMobileResultsPanels() {
    const hotelMain = document.querySelector(".hotel-main");
    const sortPanel = document.getElementById("mobileSortPanel");
    hotelMain?.classList.remove("mobile-filter-open", "mobile-sort-open");
    if (sortPanel) sortPanel.hidden = true;
    document.getElementById("mobileFilterToggle")?.setAttribute("aria-expanded", "false");
    document.getElementById("mobileSortToggle")?.setAttribute("aria-expanded", "false");
  }

  function toggleMobileResultsPanel(panelName) {
    const hotelMain = document.querySelector(".hotel-main");
    const sortPanel = document.getElementById("mobileSortPanel");
    if (!hotelMain) return;
    const className = panelName === "filter" ? "mobile-filter-open" : "mobile-sort-open";
    const willOpen = !hotelMain.classList.contains(className);
    closeMobileResultsPanels();
    if (!willOpen) return;
    hotelMain.classList.add(className);
    if (panelName === "filter") {
      document.getElementById("mobileFilterToggle")?.setAttribute("aria-expanded", "true");
    } else {
      if (sortPanel) sortPanel.hidden = false;
      document.getElementById("mobileSortToggle")?.setAttribute("aria-expanded", "true");
    }
  }

  let mobileResultsStickyFrame = 0;
  function syncMobileResultsStickyState() {
    const controls = document.querySelector(".mobile-results-controls");
    const hotelMain = document.querySelector(".hotel-main");
    if (!controls) return;
    if (!window.matchMedia("(max-width: 768px)").matches || !hotelMain?.classList.contains("search-active")) {
      controls.classList.remove("is-stuck");
      return;
    }
    const stickyTop = Number.parseFloat(window.getComputedStyle(controls).top) || 0;
    controls.classList.toggle("is-stuck", controls.getBoundingClientRect().top <= stickyTop + 1);
  }

  function requestMobileResultsStickySync() {
    if (mobileResultsStickyFrame) return;
    mobileResultsStickyFrame = window.requestAnimationFrame(() => {
      mobileResultsStickyFrame = 0;
      syncMobileResultsStickyState();
    });
  }

  function syncMobileResultsLayout() {
    const sortControls = document.querySelector(".hotel-sort");
    const sortPanel = document.getElementById("mobileSortPanel");
    const desktopToolbar = document.querySelector(".hotel-results-toolbar");
    if (!sortControls || !sortPanel || !desktopToolbar) return;
    if (window.matchMedia("(max-width: 768px)").matches) {
      if (sortControls.parentElement !== sortPanel) sortPanel.appendChild(sortControls);
    } else {
      if (sortControls.parentElement !== desktopToolbar) desktopToolbar.appendChild(sortControls);
      closeMobileResultsPanels();
    }
    updateMobileHotelSearchSummary();
    requestMobileResultsStickySync();
  }

  document.addEventListener("DOMContentLoaded", syncMobileResultsLayout);
  window.addEventListener("resize", syncMobileResultsLayout, { passive: true });
  window.addEventListener("scroll", requestMobileResultsStickySync, { passive: true });

  function initFlatpickrDateRange() {
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
      if (!fp || !fp.calendarContainer || !dateRangeInput) return;
      const inputRect = dateRangeInput.getBoundingClientRect();
      const calendarWidth = fp.calendarContainer.offsetWidth
        || Math.min(window.innerWidth <= 760 ? 380 : 760, window.innerWidth - 16);
      const viewportLeft = window.scrollX + 8;
      const viewportRight = window.scrollX + window.innerWidth - 8;
      const left = Math.min(
        Math.max(viewportLeft, inputRect.left + window.scrollX),
        Math.max(viewportLeft, viewportRight - calendarWidth)
      );
      const top = inputRect.bottom + window.scrollY + 8;
      fp.calendarContainer.style.right = "auto";
      fp.calendarContainer.style.left = `${left}px`;
      fp.calendarContainer.style.top = `${top}px`;
    };

    const scrollCalendarIntoView = () => {
      if (!fp || !fp.calendarContainer) return;
      const calendarRect = fp.calendarContainer.getBoundingClientRect();
      const viewportPadding = 16;
      const bottomOverflow = calendarRect.bottom - (window.innerHeight - viewportPadding);

      if (bottomOverflow > 0) {
        window.scrollBy({
          top: bottomOverflow,
          behavior: window.matchMedia("(prefers-reduced-motion: reduce)").matches ? "auto" : "smooth"
        });
      }
    };

    const revealCalendar = () => {
      placeCalendarBelow();
      requestAnimationFrame(scrollCalendarIntoView);
    };

    fp = flatpickr(dateRangeInput, {
      mode: "range",
      showMonths: window.innerWidth <= 760 ? 1 : 2,
      clickOpens: true,
      allowInput: false,
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
        requestAnimationFrame(revealCalendar);
      },
      onReady: function() {
        lockSearchDateInput();
        bindSearchDatePickerTrigger();
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
            fp.clear(false);
            return;
          }

          checkinInput.value = flatpickr.formatDate(selectedDates[0], "Y-m-d");
          checkoutInput.value = flatpickr.formatDate(selectedDates[1], "Y-m-d");
          dateRangeInput.value = formatRangeLabel(selectedDates[0], selectedDates[1]);
          updateStayDurationBadge(selectedDates[0], selectedDates[1]);
          clearError(dateRangeInput);
          clearError(checkinInput);
          clearError(checkoutInput);
          dismissSearchDateKeyboard();
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
        updateMobileHotelSearchSummary();
      },
      onClose: function() {
        dismissSearchDateKeyboard();
      }
    });
  }

  function initFlatpickrSingleDate() {
    const dateRangeInput = document.getElementById("dateRangePicker");
    const stayDurationBadge = document.getElementById("stayDurationBadge");
    const checkinInput = document.getElementById("checkin");
    const checkoutInput = document.getElementById("checkout");
    const today = new Date();
    const todayStr = today.toISOString().split("T")[0];

    const placeCalendarBelow = () => {
      if (!fp || !fp.calendarContainer || !dateRangeInput) return;
      const inputRect = dateRangeInput.getBoundingClientRect();
      const calendarWidth = fp.calendarContainer.offsetWidth
        || Math.min(window.innerWidth <= 760 ? 380 : 760, window.innerWidth - 16);
      const viewportLeft = window.scrollX + 8;
      const viewportRight = window.scrollX + window.innerWidth - 8;
      const left = Math.min(
        Math.max(viewportLeft, inputRect.left + window.scrollX),
        Math.max(viewportLeft, viewportRight - calendarWidth)
      );
      const top = inputRect.bottom + window.scrollY + 8;
      fp.calendarContainer.style.right = "auto";
      fp.calendarContainer.style.left = `${left}px`;
      fp.calendarContainer.style.top = `${top}px`;
    };

    const scrollCalendarIntoView = () => {
      if (!fp || !fp.calendarContainer) return;
      const calendarRect = fp.calendarContainer.getBoundingClientRect();
      const viewportPadding = 16;
      const bottomOverflow = calendarRect.bottom - (window.innerHeight - viewportPadding);

      if (bottomOverflow > 0) {
        window.scrollBy({
          top: bottomOverflow,
          behavior: window.matchMedia("(prefers-reduced-motion: reduce)").matches ? "auto" : "smooth"
        });
      }
    };

    const revealCalendar = () => {
      placeCalendarBelow();
      requestAnimationFrame(scrollCalendarIntoView);
    };

    fp = flatpickr(dateRangeInput, {
      mode: "single",
      showMonths: window.innerWidth <= 760 ? 1 : 2,
      clickOpens: true,
      allowInput: false,
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
        requestAnimationFrame(revealCalendar);
      },
      onReady: function() {
        lockSearchDateInput();
        bindSearchDatePickerTrigger();
        requestAnimationFrame(placeCalendarBelow);
      },
      onMonthChange: function() {
        requestAnimationFrame(placeCalendarBelow);
      },
      onChange: function(selectedDates, dateStr) {
        if (selectedDates.length === 1) {
          const dateStr = flatpickr.formatDate(selectedDates[0], "Y-m-d");
          checkinInput.value = dateStr;
          checkoutInput.value = dateStr;
          dateRangeInput.value = selectedDates[0].toLocaleDateString("en-US", { month: "short", day: "numeric" });
          stayDurationBadge.textContent = "1 day";
          stayDurationBadge.hidden = false;
          dateRangeInput.classList.add("has-duration");
          clearError(dateRangeInput);
          clearError(checkinInput);
          clearError(checkoutInput);
          dismissSearchDateKeyboard();
        } else {
          checkinInput.value = "";
          checkoutInput.value = "";
          dateRangeInput.value = "";
          stayDurationBadge.hidden = true;
          dateRangeInput.classList.remove("has-duration");
        }
        updateMobileHotelSearchSummary();
      },
      onClose: function() {
        dismissSearchDateKeyboard();
      }
    });
  }

  function redirectToUnifiedSearch() {
    const selectedLocations = getSelectedSearchLocations();
    const destination = selectedLocations[0] || "";
    const destination2 = selectedLocations[1] || "";
    const checkin = document.getElementById("checkin");
    const checkout = document.getElementById("checkout");
    const adults = parseInt(document.getElementById("adults").innerText, 10);
    const children = parseInt(document.getElementById("children").innerText, 10);
    const rooms = parseInt(document.getElementById("rooms").innerText, 10);
    const tourTypeValue = tourDateMode === "sameday" ? "same-day" : "overnight";
    const computedDuration = buildTourDurationLabel(checkin.value, checkout.value, tourDateMode);
    const childAges = getChildAges();
    const params = new URLSearchParams({
      tab: activeSearchTab,
      destination,
      checkin: checkin.value,
      checkout: checkout.value,
      date: checkin.value,
      adults: String(adults),
      children: String(children),
      child_ages: childAges.join(","),
      pax: String(adults + children),
      tour_date_mode: tourDateMode,
      tour_type: tourTypeValue
    });

    if (destination2) {
      params.set("destination2", destination2);
    }
    if (selectedLocations.length > 0) {
      params.set("destinations", selectedLocations.join("|"));
    }
    if (computedDuration) {
      params.set("tour_duration", computedDuration);
    }

    if (activeSearchTab === "hotels") {
      params.set("rooms", String(rooms));
    }

    clearActiveSearchTabForExit();
    window.location.href = `search_results.php?${params.toString()}`;
  }

  document.addEventListener("DOMContentLoaded", () => {
    displayRecentlyViewed();
    displayFeatured();
    displayCarousels();
    initDragScrollableCarousels();
    updatePriceUI();
    document.getElementById("hotelMapModal")?.addEventListener("click", event => {
      if (event.target.id === "hotelMapModal") closeHotelMap();
    });
    document.addEventListener("keydown", event => {
      if (event.key === "Escape" && document.getElementById("hotelMapModal")?.classList.contains("open")) {
        closeHotelMap();
      }
    });
    document.querySelector(".hotel-main").style.display = "none";
    // History state survives refreshes but is not inherited by a fresh visit.
    const landingNavigationType = performance.getEntriesByType("navigation")[0]?.type || "navigate";
    const landingParams = new URLSearchParams(window.location.search);
    const shouldResetSearchTab = landingParams.get("reset_search") === "1";
    if (shouldResetSearchTab) clearActiveSearchTabForExit();
    const refreshedUrlTab = landingParams.get("search_tab");
    const explicitlyRequestedTab = refreshedUrlTab
      ? normalizeSearchTab(refreshedUrlTab)
      : "";
    const retainedTab = shouldResetSearchTab
      ? (explicitlyRequestedTab || "hotels")
      : explicitlyRequestedTab
        || window.history.state?.itourSearchTab
        || sessionStorage.getItem(ACTIVE_SEARCH_TAB_KEY)
        || (landingNavigationType === "reload" ? refreshedUrlTab : "")
        || "hotels";
    setActiveSearchTab(normalizeSearchTab(retainedTab));
    document.querySelectorAll(".search-mode-tab").forEach(btn => {
      btn.addEventListener("click", () => {
        setActiveSearchTab(btn.dataset.searchTab);
      });
    });
    document.addEventListener("click", event => {
      const link = event.target.closest("a[href]");
      if (!link || link.target === "_blank" || link.hasAttribute("download")) return;

      const destination = new URL(link.href, window.location.href);
      if (destination.origin !== window.location.origin || destination.pathname !== window.location.pathname) {
        clearActiveSearchTabForExit();
      }
    }, true);

    // The navigation is server-rendered for immediate display. Keep the fetch
    // fallback for deployments that still serve an empty header host.
    const sharedHeaderHost = document.getElementById("header");
    const sharedHeaderReady = sharedHeaderHost?.firstElementChild
      ? Promise.resolve()
      : fetch("php/header.php")
          .then(res => res.text())
          .then(html => { sharedHeaderHost.innerHTML = html; });

    sharedHeaderReady
      .then(() => {
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
          if (!fp || !fp.calendarContainer || !dateRangeInput) return;
          const inputRect = dateRangeInput.getBoundingClientRect();
          const calendarWidth = fp.calendarContainer.offsetWidth
            || Math.min(window.innerWidth <= 760 ? 380 : 760, window.innerWidth - 16);
          const viewportLeft = window.scrollX + 8;
          const viewportRight = window.scrollX + window.innerWidth - 8;
          const left = Math.min(
            Math.max(viewportLeft, inputRect.left + window.scrollX),
            Math.max(viewportLeft, viewportRight - calendarWidth)
          );
          const top = inputRect.bottom + window.scrollY + 8;
          fp.calendarContainer.style.right = "auto";
          fp.calendarContainer.style.left = `${left}px`;
          fp.calendarContainer.style.top = `${top}px`;
        };

        initFlatpickrDateRange();
        window.addEventListener("resize", placeCalendarBelow);
        window.addEventListener("scroll", placeCalendarBelow, true);

        const island = document.getElementById("island");
        const islandSecondary = document.getElementById("islandSecondary");
        const destinationOptions = [...island.options]
          .filter(option => option.value)
          .map(option => ({ value: option.value, label: option.textContent }));

        const syncDestinationOptions = () => {
          const primaryValue = island.value;
          const secondaryValue = islandSecondary.value;

          [...island.options].forEach(option => {
            if (!option.value) return;
            option.hidden = option.value === secondaryValue;
            option.disabled = option.value === secondaryValue;
          });

          islandSecondary.replaceChildren();
          const placeholder = new Option("Add another destination (optional)", "", false, secondaryValue === "");
          islandSecondary.add(placeholder);
          destinationOptions.forEach(destination => {
            if (destination.value === primaryValue) return;
            islandSecondary.add(new Option(destination.label, destination.value, false, destination.value === secondaryValue));
          });

          if (secondaryValue === primaryValue || !destinationOptions.some(destination => destination.value === secondaryValue)) {
            islandSecondary.value = "";
          }
        };

        const updateIslandFieldState = () => {
          syncDestinationOptions();
          const hasLocation = Boolean(island.value);
          island.classList.toggle("is-placeholder", !hasLocation);
          const hasSecondary = Boolean(islandSecondary.value);
          islandSecondary.classList.toggle("is-placeholder", !hasSecondary);
        };
        island.addEventListener("change", () => {
          if (islandSecondary.value === island.value) islandSecondary.value = "";
          if (island.value) clearError(island);
          updateIslandFieldState();
        });
        islandSecondary.addEventListener("change", () => {
          if (islandSecondary.value) clearError(islandSecondary);
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
        const hasExternalSearchParams = urlParams.has("island") || urlParams.has("destination");
        const tabFromUrl = normalizeSearchTab(
          urlParams.get("search_tab")
          || (hasExternalSearchParams ? urlParams.get("tab") : "")
          || window.history.state?.itourSearchTab
          || sessionStorage.getItem(ACTIVE_SEARCH_TAB_KEY)
          || "hotels"
        );
        if (hasExternalSearchParams) {
          savedData = {
            tab: tabFromUrl,
            island: urlParams.get("destination") || urlParams.get("island") || "",
            island2: urlParams.get("destination2") || "",
            checkin: urlParams.get("checkin") || "",
            checkout: urlParams.get("checkout") || "",
            adults: Number(urlParams.get("adults") || 1),
            children: Number(urlParams.get("children") || 0),
            childAges: parseChildAges(urlParams.get("child_ages") || ""),
            rooms: Number(urlParams.get("rooms") || 1),
            tourDateMode: urlParams.get("tour_date_mode") || "overnight",
            tourDuration: urlParams.get("tour_duration") || ""
          };
          sessionStorage.setItem("searchMode", "true");
          sessionStorage.setItem("searchData", JSON.stringify(savedData));
        }

        if (hasExternalSearchParams) {
          setActiveSearchTab(savedData.tab || tabFromUrl);
          document.getElementById("island").value = savedData.island || "";
          document.getElementById("islandSecondary").value = savedData.island2 || "";
          if (isTourSearchTab(savedData.tab || tabFromUrl) && savedData.tourDateMode) {
            setTourDateMode(savedData.tourDateMode === "sameday" ? "sameday" : "overnight");
          }
          checkinInput.value = savedData.checkin || "";
          checkoutInput.value = savedData.checkout || "";
          if (savedData.checkin && savedData.checkout) {
            fp.setDate([savedData.checkin, savedData.checkout], true, "Y-m-d");
            updateStayDurationBadge(new Date(savedData.checkin), new Date(savedData.checkout));
          } else {
            updateStayDurationBadge(null, null);
          }
          document.getElementById("adults").innerText = savedData.adults ?? 1;
          document.getElementById("children").innerText = savedData.children ?? 0;
          document.getElementById("rooms").innerText = savedData.rooms ?? 1;
          renderChildAgeRows(savedData.childAges || []);
          applyGuestSelection(false);
          setTimeout(() => {
            applySearchWithoutValidation(savedData);
          }, 0);
        } else if (navType === "reload") {
          if (savedMode === "true" && savedData && normalizeSearchTab(savedData.tab) === tabFromUrl) {
            setActiveSearchTab(tabFromUrl);
            document.getElementById("island").value = savedData.island || "";
            document.getElementById("islandSecondary").value = savedData.island2 || "";
            if (isTourSearchTab(savedData.tab || tabFromUrl) && savedData.tourDateMode) {
              setTourDateMode(savedData.tourDateMode === "sameday" ? "sameday" : "overnight");
            }
            checkinInput.value = savedData.checkin || "";
            checkoutInput.value = savedData.checkout || "";
            if (savedData.checkin && savedData.checkout) {
              fp.setDate([savedData.checkin, savedData.checkout], true, "Y-m-d");
              updateStayDurationBadge(new Date(savedData.checkin), new Date(savedData.checkout));
            } else {
              updateStayDurationBadge(null, null);
            }
            document.getElementById("adults").innerText = savedData.adults ?? 0;
            document.getElementById("children").innerText = savedData.children ?? 0;
            document.getElementById("rooms").innerText = savedData.rooms ?? 0;
            renderChildAgeRows(savedData.childAges || []);
            applyGuestSelection(false);
            setTimeout(() => {
              applySearchWithoutValidation(savedData);
            }, 0);
          } else {
            setActiveSearchTab(tabFromUrl);
            showFeaturedMode();
          }
        } else {
          setActiveSearchTab(normalizeSearchTab(
            window.history.state?.itourSearchTab
            || sessionStorage.getItem(ACTIVE_SEARCH_TAB_KEY)
            || "hotels"
          ));
          sessionStorage.removeItem("searchMode");
          sessionStorage.removeItem("searchData");
          showFeaturedMode();
        }
        updateIslandFieldState();

        fetch("logsign-modal.html?v=15")
          .then(res => res.text())
          .then(html => {
            const modalContainer = document.getElementById("loginModal");
            if (!modalContainer) return console.error("loginModal container not found");
            modalContainer.innerHTML = html;

            const swalScript = document.createElement("script");
            swalScript.src = "https://cdn.jsdelivr.net/npm/sweetalert2@11";
            swalScript.onload = () => {
              const logsignScript = document.createElement("script");
              logsignScript.src = "logsign.js?v=16";
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
    document.getElementById("carouselSection").style.display = "block";
    document.querySelector(".hotel-main").style.display = "none";
    document.getElementById("heroSection").style.display = "block";
    document.getElementById("searchSection").classList.remove("search-fixed");
  }

  function getStarClass(index, avg) {
    if (index <= Math.floor(avg)) return "filled";
    if (index - avg < 1 && index > avg) return "half";
    return "";
  }

  function renderStars(rating, totalReviews = 0, variant = "result") {
    const safeRating = Number(rating) || 0;
    const safeReviews = Number(totalReviews) || 0;
    let stars = "";
    for (let i = 1; i <= 5; i++) {
      const starClass = getStarClass(i, safeRating);
      stars += `<span class="star ${starClass}">★</span>`;
    }
    return `
      <div class="hotel-star-rating ${variant}">
        ${stars}
        <span class="rating-text">${safeRating.toFixed(1)} (${safeReviews})</span>
      </div>
    `;
  }

  function escapeMapText(value) {
    return String(value ?? "").replace(/[&<>"']/g, character => ({
      "&": "&amp;",
      "<": "&lt;",
      ">": "&gt;",
      '"': "&quot;",
      "'": "&#039;"
    })[character]);
  }

  function getHotelCoordinates(hotel, index = 0) {
    const islandKey = String(hotel?.island || "mercedes").trim().toLowerCase();
    const matchedIsland = Object.keys(HOTEL_ISLAND_COORDINATES).find(key => islandKey.includes(key));
    const base = HOTEL_ISLAND_COORDINATES[matchedIsland] || MERCEDES_MAP_CENTER;
    const seed = Number(hotel?.id || index + 1);
    const angle = (seed * 137.508) * (Math.PI / 180);
    const distance = 0.0012 + ((seed % 4) * 0.00035);
    return [
      base[0] + Math.sin(angle) * distance,
      base[1] + Math.cos(angle) * distance
    ];
  }

  function getNearbyHotelsForMap() {
    const searchedIsland = String(document.getElementById("island")?.value || "").trim().toLowerCase();
    return [...hotels].sort((first, second) => {
      const firstNearby = String(first.island || "").trim().toLowerCase() === searchedIsland ? 0 : 1;
      const secondNearby = String(second.island || "").trim().toLowerCase() === searchedIsland ? 0 : 1;
      if (firstNearby !== secondNearby) return firstNearby - secondNearby;
      return Number(second.rating || 0) - Number(first.rating || 0);
    });
  }

  function createHotelMarkerIcon(hotel, compact = false) {
    const numericPrice = Number(hotel?.price || 0);
    const formatted = numericPrice > 0
      ? `₱${numericPrice.toLocaleString("en-PH", { maximumFractionDigits: 0 })}`
      : "See rates";
    const image = escapeMapText(hotel?.img || "img/sampleimage.png");
    return L.divIcon({
      className: "hotel-map-marker-shell",
      html: `<span class="hotel-map-profile-marker${compact ? " compact" : ""}"><span class="hotel-map-avatar"><img src="${image}" alt=""></span><span class="hotel-map-rate">${formatted}</span></span>`,
      iconSize: compact ? [64, 68] : [80, 84],
      iconAnchor: compact ? [32, 67] : [40, 83],
      popupAnchor: [0, -78]
    });
  }

  function buildHotelMapPopup(hotel) {
    const mapQuery = encodeURIComponent(`${hotel.name}, ${hotel.island}, Mercedes, Camarines Norte`);
    const nightlyRate = Number(hotel.price || 0) > 0
      ? `₱${Number(hotel.price).toLocaleString("en-PH")} <small>/ night</small>`
      : "See available rates";
    return `
      <div class="hotel-map-popup">
        <strong>${escapeMapText(hotel.name)}</strong>
        <span><i class="fa-solid fa-location-dot"></i> ${escapeMapText(hotel.island)}, Mercedes</span>
        <b>${nightlyRate}</b>
        <div>
          <button type="button" onclick="openHotelDetails(${Number(hotel.id)}, 'result')">View stay</button>
          <a href="https://www.google.com/maps/search/?api=1&query=${mapQuery}" target="_blank" rel="noopener">Directions</a>
        </div>
      </div>
    `;
  }

  function addMapTiles(map) {
    L.tileLayer("https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png", {
      maxZoom: 19,
      attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>'
    }).addTo(map);
  }

  function updatePreviewMap() {
    if (typeof L === "undefined" || !document.getElementById("hotelMapPreview")) return;
    const list = getNearbyHotelsForMap();
    if (!hotelMapPreview) {
      hotelMapPreview = L.map("hotelMapPreview", {
        zoomControl: false,
        attributionControl: false,
        dragging: false,
        scrollWheelZoom: false,
        doubleClickZoom: false,
        keyboard: false,
        touchZoom: false
      }).setView(MERCEDES_MAP_CENTER, 11);
      addMapTiles(hotelMapPreview);
    }

    hotelPreviewMarkers.forEach(marker => marker.remove());
    hotelPreviewMarkers = list.slice(0, 12).map((hotel, index) =>
      L.marker(getHotelCoordinates(hotel, index), {
        icon: createHotelMarkerIcon(hotel, true),
        interactive: false
      }).addTo(hotelMapPreview)
    );
  }

  function renderHotelMap(list = getNearbyHotelsForMap()) {
    if (!hotelMap) return;
    mappedHotels = [...list];

    hotelMapMarkers.forEach(marker => marker.remove());
    hotelMapMarkers = [];

    list.forEach((hotel, index) => {
      const marker = L.marker(getHotelCoordinates(hotel, index), {
        icon: createHotelMarkerIcon(hotel)
      }).addTo(hotelMap);
      marker.bindPopup(buildHotelMapPopup(hotel), { minWidth: 220 });
      marker.on("click", () => {
        document.querySelectorAll(".hotel-map-result-card").forEach(card => card.classList.remove("active"));
        document.querySelector(`.hotel-map-result-card[data-hotel-id="${hotel.id}"]`)?.classList.add("active");
      });
      hotelMapMarkers.push(marker);
    });

    const count = document.getElementById("hotelMapCount");
    if (count) count.textContent = `${list.length} registered ${list.length === 1 ? "property" : "properties"} near Mercedes`;

    const listContainer = document.getElementById("hotelMapList");
    if (listContainer) {
      listContainer.innerHTML = list.length ? list.map(hotel => `
        <button type="button" class="hotel-map-result-card" data-hotel-id="${Number(hotel.id)}" onclick="focusHotelOnMap(${Number(hotel.id)})">
          <img src="${escapeMapText(hotel.img)}" alt="" loading="lazy">
          <span>
            <small>${escapeMapText(String(hotel.type || "stay").toUpperCase())} · ${escapeMapText(hotel.island)}</small>
            <strong>${escapeMapText(hotel.name)}</strong>
            <b>${Number(hotel.price || 0) > 0 ? `₱${Number(hotel.price).toLocaleString("en-PH")} <em>/ night</em>` : "See available rates"}</b>
          </span>
        </button>
      `).join("") : `<div class="hotel-map-empty"><i class="fa-regular fa-map"></i><strong>No stays match these filters</strong><span>Reset the filters to see properties on the map.</span></div>`;
    }

    if (hotelMapMarkers.length) {
      const group = L.featureGroup(hotelMapMarkers);
      hotelMap.fitBounds(group.getBounds().pad(0.22), { maxZoom: 14 });
    } else {
      hotelMap.setView(MERCEDES_MAP_CENTER, 11);
    }
  }

  function focusHotelOnMap(hotelId) {
    const hotelIndex = mappedHotels.findIndex(hotel => Number(hotel.id) === Number(hotelId));
    if (hotelIndex < 0 || !hotelMapMarkers[hotelIndex]) return;
    document.querySelectorAll(".hotel-map-result-card").forEach(card => card.classList.remove("active"));
    document.querySelector(`.hotel-map-result-card[data-hotel-id="${hotelId}"]`)?.classList.add("active");
    const marker = hotelMapMarkers[hotelIndex];
    hotelMap.setView(marker.getLatLng(), 14, { animate: true });
    marker.openPopup();
  }

  function openHotelMap() {
    const modal = document.getElementById("hotelMapModal");
    if (!modal || typeof L === "undefined") return;
    closeMobileResultsPanels();
    const searchedIsland = document.getElementById("island")?.value;
    const mapTitle = document.getElementById("hotelMapTitle");
    if (mapTitle) {
      mapTitle.textContent = searchedIsland
        ? `Stays near ${searchedIsland} and Mercedes`
        : "Stays around Mercedes";
    }
    modal.classList.add("open");
    modal.setAttribute("aria-hidden", "false");
    document.body.classList.add("hotel-map-open");

    if (!hotelMap) {
      hotelMap = L.map("hotelMap", { zoomControl: true }).setView(MERCEDES_MAP_CENTER, 11);
      addMapTiles(hotelMap);
    }
    requestAnimationFrame(() => {
      hotelMap.invalidateSize();
      renderHotelMap(getNearbyHotelsForMap());
    });
  }

  function closeHotelMap() {
    const modal = document.getElementById("hotelMapModal");
    if (!modal) return;
    modal.classList.remove("open");
    modal.setAttribute("aria-hidden", "true");
    document.body.classList.remove("hotel-map-open");
  }

  function resetHotelFilters() {
    document.querySelectorAll(".filter").forEach(input => {
      input.checked = false;
    });
    rangeMin.value = rangeMin.min;
    rangeMax.value = rangeMax.max;
    updatePriceUI();
    applyCheckboxFilters();
  }

  function displayHotels(list) {
    const container = document.getElementById("hotelList");
    visibleHotels = [...list];
    const resultCount = document.getElementById("hotelResultsCount");
    const mobileResultCount = document.getElementById("mobileHotelResultsCount");
    const selectedIsland = document.getElementById("island")?.value;
    if (resultCount) {
      resultCount.textContent = `${list.length} ${list.length === 1 ? "property" : "properties"} found${selectedIsland ? ` in ${selectedIsland}` : ""}`;
    }
    if (mobileResultCount) {
      mobileResultCount.textContent = `${list.length} result${list.length === 1 ? "" : "s"}`;
    }
    updatePreviewMap();
    if (hotelMap && document.getElementById("hotelMapModal")?.classList.contains("open")) {
      renderHotelMap(getNearbyHotelsForMap());
    }

    if (!list.length) {
      container.innerHTML = `
        <div class="hotel-results-empty">
          <span><i class="fa-solid fa-magnifying-glass"></i></span>
          <h3>No properties match your filters</h3>
          <p>Try widening your price range or clearing a property type.</p>
          <button type="button" onclick="resetHotelFilters()">Reset all filters</button>
        </div>
      `;
      return;
    }
    // A single DOM update avoids reflowing and repainting the list for every card.
    const cards = list.map(h => {
      const visibleAmenities = (h.amenities || []).slice(0, 5);
      const hotelId = Number(h.id) || 0;
      const hotelName = escapeHtml(h.name || "Hotel");
      const hotelImage = escapeHtml(h.img || "img/sampleimage.png");
      const hotelType = String(h.type || "hotel").toLowerCase().replace(/[^a-z0-9_-]/g, "") || "hotel";
      const hotelTypeLabel = escapeHtml(String(h.type || "hotel").toUpperCase());
      const hotelIsland = escapeHtml(h.island || "Mercedes");

      return `
      <article class="hotel-result-card hotel-card-link" role="link" tabindex="0" aria-label="View hotel details" data-hotel-id="${hotelId}">
        <img class="hotel-result-img" src="${hotelImage}" alt="${hotelName}" loading="lazy" decoding="async" fetchpriority="low" />
        <div class="hotel-result-info">
          <span class="hotel-result-badge ${hotelType}">${hotelTypeLabel}</span>
          <h3 class="hotel-result-title">${hotelName}</h3>
          <p class="hotel-result-location">${hotelIsland}</p>
          <div class="hotel-result-reviews">
            ${renderStars(h.rating, Number(h.total_reviews ?? 0), "result")}
          </div>
          <div class="hotel-result-amenities">
            ${visibleAmenities.map(a => `<span class="hotel-result-amenity">${escapeHtml(a)}</span>`).join("")}
          </div>
          <button class="hotel-result-see-more" type="button" data-amenities-id="${hotelId}">
            See all amenities
          </button>
        </div>
        <div class="hotel-result-right">
          <div class="hotel-result-price-wrap">
            <span class="hotel-result-price-label">as low as</span>
            <div class="hotel-result-price" style="font-size: 25px; font-weight: bold;">
              ₱${Number(h.price || 0).toLocaleString('en-US')}
              <span>/night</span>
            </div>
          </div>
          <button class="hotel-result-btn" type="button" data-details-id="${hotelId}">Check Availability</button>
        </div>
      </article>
    `;
    }).join("");

    container.innerHTML = cards;
    container.querySelectorAll('.hotel-card-link[data-hotel-id]').forEach(card => {
      const open = () => openHotelDetails(Number(card.dataset.hotelId), 'result');
      card.addEventListener('click', event => {
        if (!event.target.closest('button, a')) open();
      });
      card.addEventListener('keydown', event => {
        if ((event.key === 'Enter' || event.key === ' ') && event.target === card) {
          event.preventDefault();
          open();
        }
      });
    });
    container.querySelectorAll('[data-amenities-id]').forEach(button => {
      button.addEventListener('click', () => openAmenities(Number(button.dataset.amenitiesId)));
    });
    container.querySelectorAll('[data-details-id]').forEach(button => {
      button.addEventListener('click', () => openHotelDetails(Number(button.dataset.detailsId), 'result'));
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

    minText.innerText = minVal.toLocaleString("en-PH");
    maxText.innerText = maxVal.toLocaleString("en-PH");

    const percentMin = ((minVal - min) / (max - min)) * 100;
    const percentMax = ((maxVal - min) / (max - min)) * 100;

    rangeMin.style.setProperty('--min', percentMin + '%');
    rangeMin.style.setProperty('--max', percentMax + '%');
    rangeMax.style.setProperty('--min', percentMin + '%');
    rangeMax.style.setProperty('--max', percentMax + '%');
    const priceTrack = document.querySelector(".price-track");
    if (priceTrack) {
      priceTrack.style.setProperty("--price-min", `${percentMin}%`);
      priceTrack.style.setProperty("--price-max", `${percentMax}%`);
    }
  }

  rangeMin.addEventListener("input", updatePriceUI);
  rangeMax.addEventListener("input", updatePriceUI);
  rangeMin.addEventListener("change", applyCheckboxFilters);
  rangeMax.addEventListener("change", applyCheckboxFilters);

  function persistHotelSearchUrl(data) {
    if (!data || normalizeSearchTab(data.tab) !== "hotels") return;
    const url = new URL(window.location.href);
    url.search = "";
    url.searchParams.set("tab", "hotels");
    if (data.island) url.searchParams.set("destination", data.island);
    if (data.checkin) url.searchParams.set("checkin", data.checkin);
    if (data.checkout) url.searchParams.set("checkout", data.checkout);
    url.searchParams.set("adults", String(data.adults ?? 1));
    url.searchParams.set("children", String(data.children ?? 0));
    url.searchParams.set("rooms", String(data.rooms ?? 1));
    if (Array.isArray(data.childAges) && data.childAges.length) {
      url.searchParams.set("child_ages", data.childAges.join(","));
    }
    window.history.replaceState({ hotelSearch: true }, "", url.toString());
  }

  function filterHotels() {
    let valid = true;
    const island = document.getElementById("island");
    const islandSecondary = document.getElementById("islandSecondary");
    const checkin = document.getElementById("checkin");
    const checkout = document.getElementById("checkout");
    const dateRangeInput = document.getElementById("dateRangePicker");
    const adults = parseInt(document.getElementById("adults").innerText, 10);
    const children = parseInt(document.getElementById("children").innerText, 10);
    const rooms = parseInt(document.getElementById("rooms").innerText, 10);
    const isHotelSearch = activeSearchTab === "hotels";
    const isTourSearch = isTourSearchTab(activeSearchTab);

    [island, islandSecondary, dateRangeInput].forEach(clearError);

    if (!island.value) {
      setError(island, "This field is required");
      valid = false;
    }
    if (isTourSearch && islandSecondary.value && islandSecondary.value === island.value) {
      setError(islandSecondary, "Second location must be different");
      valid = false;
    }

    if (!checkin.value || !checkout.value) {
      setError(dateRangeInput, "Please select check-in and check-out dates");
      valid = false;
    }

    if (!validateGuestSelection(isHotelSearch)) {
      valid = false;
    }

    if (!valid) {
      return;
    }

    const checkinDate = new Date(checkin.value);
    const checkoutDate = new Date(checkout.value);

    const requiresOvernightGap = isHotelSearch || (isTourSearch && tourDateMode === "overnight");
    if (requiresOvernightGap && checkoutDate <= checkinDate) {
      setError(dateRangeInput, "Must be at least 1 night stay");
      return;
    }

    if (!isHotelSearch) {
      redirectToUnifiedSearch();
      return;
    }

    document.getElementById("featuredSection").style.display = "none";
    document.getElementById("carouselSection").style.display = "none";
    document.getElementById("recentlyViewedSection").hidden = true;
    document.querySelector(".hotel-main").style.removeProperty("display");
    document.getElementById("heroSection").style.display = "none";
    const search = document.getElementById("searchSection");
    search.classList.add("search-fixed");
    search.classList.remove("mobile-search-open");
    document.getElementById("mobileHotelSearchToggle")?.setAttribute("aria-expanded", "false");
    updateMobileHotelSearchSummary();
    const hotelMain = document.querySelector(".hotel-main");

    if (hotelMain) {
      hotelMain.style.removeProperty("display");
      hotelMain.classList.add("search-active");
    }

    filtered = hotels.filter(h => {
      return !island.value || h.island === island.value;
    });

    applyCheckboxFilters();
    requestAnimationFrame(() => {
      window.scrollTo({ top: 0, left: 0, behavior: "auto" });
    });

    const selectedLocations = getSelectedSearchLocations();
    const currentSearchData = {
      tab: activeSearchTab,
      island: island.value,
      island2: islandSecondary.value,
      checkin: checkin.value,
      checkout: checkout.value,
      adults: adults,
      children: children,
      childAges: getChildAges(),
      rooms: rooms,
      tourDateMode: tourDateMode,
      tourDuration: buildTourDurationLabel(checkin.value, checkout.value, tourDateMode),
      destinations: selectedLocations
    };
    sessionStorage.setItem("searchMode", "true");
    sessionStorage.setItem("searchData", JSON.stringify(currentSearchData));
    persistHotelSearchUrl(currentSearchData);
  }

function applySearchWithoutValidation(data) {
  document.getElementById("featuredSection").style.display = "none";
  document.getElementById("carouselSection").style.display = "none";
  document.getElementById("recentlyViewedSection").hidden = true;
  document.getElementById("heroSection").style.display = "none";

  const hotelMain = document.querySelector(".hotel-main");
  if (hotelMain) {
    hotelMain.style.removeProperty("display");
    hotelMain.classList.add("search-active");
  }

  const search = document.getElementById("searchSection");
  if (search) {
    search.classList.add("search-fixed");
    search.classList.remove("mobile-search-open");
    document.getElementById("mobileHotelSearchToggle")?.setAttribute("aria-expanded", "false");
    updateMobileHotelSearchSummary();
  }

  filtered = hotels.filter(h => {
    return !data.island || h.island === data.island;
  });

  persistHotelSearchUrl(data);
  applyCheckboxFilters();
  requestAnimationFrame(() => {
    window.scrollTo({ top: 0, left: 0, behavior: "auto" });
  });
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

    const selectedTypes = checked.filter(value => value === "hotel" || value === "resort");
    if (selectedTypes.length) {
      temp = temp.filter(h => selectedTypes.includes(String(h.type).toLowerCase()));
    }

    displayHotels(temp);
  }

  function sortHotels(type, el) {
    document.querySelectorAll(".hotel-sort button").forEach(btn => btn.classList.remove("active"));
    el.classList.add("active");
    if (type === "recommended") {
      applyCheckboxFilters();
      closeMobileResultsPanels();
      return;
    }
    let temp = [...visibleHotels];

    if (type === "price") {
      temp.sort((a, b) => a.price - b.price);
    }
    if (type === "rating") {
      temp.sort((a, b) => b.rating - a.rating);
    }

    displayHotels(temp);
    closeMobileResultsPanels();
  }

  function parseChildAges(value) {
    const values = Array.isArray(value) ? value : String(value || "").split(",");
    return values
      .map(age => String(age).trim())
      .filter(age => /^\d+$/.test(age) && Number(age) >= 0 && Number(age) <= 17)
      .map(Number);
  }

  function getChildAges() {
    return Array.from(document.querySelectorAll(".child-age-select"))
      .map(select => select.value)
      .filter(value => value !== "")
      .map(Number);
  }

  function clearGuestPickerHighlights() {
    document.querySelectorAll("#guestBox .guest-row.is-invalid, #childAgeRows .child-age-row.is-invalid")
      .forEach(row => row.classList.remove("is-invalid"));
    document.querySelectorAll("#childAgeRows .child-age-select[aria-invalid]")
      .forEach(select => select.removeAttribute("aria-invalid"));
  }

  function validateGuestSelection(includeRooms = activeSearchTab === "hotels") {
    const adults = Number(document.getElementById("adults")?.innerText || 0);
    const children = Number(document.getElementById("children")?.innerText || 0);
    const rooms = Number(document.getElementById("rooms")?.innerText || 0);
    const guestDisplay = document.querySelector(".guest-display");
    const messages = [];
    clearGuestPickerHighlights();

    if (includeRooms && adults < 1) {
      document.getElementById("adults")?.closest(".guest-row")?.classList.add("is-invalid");
      messages.push("add at least 1 adult");
    } else if (!includeRooms && adults + children < 1) {
      document.getElementById("adults")?.closest(".guest-row")?.classList.add("is-invalid");
      document.getElementById("children")?.closest(".guest-row")?.classList.add("is-invalid");
      messages.push("add at least 1 guest");
    }

    if (includeRooms && rooms < 1) {
      document.getElementById("roomsGuestRow")?.classList.add("is-invalid");
      messages.push("add at least 1 room");
    }

    const missingAgeLabels = [];
    document.querySelectorAll("#childAgeRows .child-age-select").forEach((select, index) => {
      if (select.value !== "") return;
      select.setAttribute("aria-invalid", "true");
      select.closest(".child-age-row")?.classList.add("is-invalid");
      missingAgeLabels.push(`Child ${index + 1}`);
    });
    if (children > 0 && missingAgeLabels.length > 0) {
      messages.push(`select an age for ${missingAgeLabels.join(", ")}`);
    }

    if (messages.length > 0) {
      setError(guestDisplay, `Please ${messages.join(" and ")}.`);
      document.getElementById("guestBox").style.display = "block";
      return false;
    }

    clearError(guestDisplay);
    return true;
  }

  function renderChildAgeRows(savedAges = null) {
    const container = document.getElementById("childAgeRows");
    if (!container) return;
    const count = Math.max(0, Number(document.getElementById("children")?.innerText || 0));
    const previous = savedAges === null
      ? Array.from(container.querySelectorAll(".child-age-select")).map(select => select.value)
      : parseChildAges(savedAges).map(String);

    container.innerHTML = Array.from({ length: count }, (_, index) => {
      const selectedAge = previous[index] ?? "";
      const options = Array.from({ length: 18 }, (__, age) =>
        `<option value="${age}"${selectedAge === String(age) ? " selected" : ""}>${age} year${age === 1 ? "" : "s"} old${age <= 7 ? " — Free" : ""}</option>`
      ).join("");
      return `<label class="child-age-row">
        <span>Child ${index + 1} age</span>
        <select class="child-age-select" aria-label="Age of child ${index + 1}">
          <option value="">Select age</option>${options}
        </select>
      </label>`;
    }).join("");

    container.hidden = count === 0;
    if (count > 0) {
      container.insertAdjacentHTML("beforeend", '<p class="child-age-note"><i class="fa-solid fa-circle-check" aria-hidden="true"></i> Ages 7 and below: free hotel admission and entrance fees.</p>');
    }
    container.querySelectorAll(".child-age-select").forEach(select => {
      select.addEventListener("change", () => {
        select.removeAttribute("aria-invalid");
        select.closest(".child-age-row")?.classList.remove("is-invalid");
        if (validateGuestSelection()) clearError(document.querySelector(".guest-display"));
      });
    });
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
    if (id === "children") renderChildAgeRows();
    el.closest(".guest-row")?.classList.remove("is-invalid");
  }

  function applyGuestSelection(shouldValidate = true) {
    const adults = parseInt(document.getElementById("adults").innerText, 10);
    const children = parseInt(document.getElementById("children").innerText, 10);
    const rooms = parseInt(document.getElementById("rooms").innerText, 10);
    const textEl = document.getElementById("guestText");
    const includeRooms = activeSearchTab === "hotels";

    if (shouldValidate && !validateGuestSelection(includeRooms)) return;
    if (!shouldValidate) {
      clearGuestPickerHighlights();
      clearError(document.querySelector(".guest-display"));
    }

    if (adults === 0 && children === 0 && (!includeRooms || rooms === 0)) {
      textEl.innerText = includeRooms ? "Guests & Rooms" : "Guests";
      textEl.classList.add("placeholder");
    } else {
      textEl.innerText = includeRooms
        ? `${adults} Adult${adults > 1 ? "s" : ""}, ${children} Child${children !== 1 ? "ren" : ""} • ${rooms} Room${rooms > 1 ? "s" : ""}`
        : `${adults} Adult${adults > 1 ? "s" : ""}, ${children} Child${children !== 1 ? "ren" : ""}`;
      textEl.classList.remove("placeholder");
    }

    const guestBox = document.querySelector(".guest-display");
    if (includeRooms ? (adults > 0 || rooms > 0) : (adults + children > 0)) {
      clearError(guestBox);
    }

    document.getElementById("guestBox").style.display = "none";
    updateMobileHotelSearchSummary();
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
    const controlWrap = el.closest(".search-control-wrap") || el.parentNode;
    controlWrap.classList.add("has-error");
    let msg = controlWrap.querySelector(".field-error-slot");
    if (!msg) {
      msg = document.createElement("div");
      msg.className = "field-error-slot";
      controlWrap.appendChild(msg);
    }
    msg.classList.add("error-text");
    msg.innerText = message;
  }

  function clearError(el) {
    el.classList.remove("input-error");
    const controlWrap = el.closest(".search-control-wrap") || el.parentNode;
    controlWrap.classList.remove("has-error");
    const msg = controlWrap.querySelector(".field-error-slot");
    if (msg) {
      msg.innerText = "";
      msg.classList.remove("error-text");
    }
  }

  function displayFeatured() {
    const grid = document.getElementById("featuredGrid");
    // A single DOM update avoids reflowing and repainting the carousel for every card.
    const cards = hotels.map((h, index) => {
      const visibleAmenities = (h.amenities || []).slice(0, 5);
      const hiddenAmenities = (h.amenities || []).slice(5);
      const imageLoading = index < 5 ? "eager" : "lazy";
      const imagePriority = index < 3 ? "high" : "auto";
      const hotelId = Number(h.id) || 0;
      const hotelImage = escapeHtml(h.img || "img/sampleimage.png");
      const hotelName = escapeHtml(h.name || "Hotel");
      const hotelIsland = escapeHtml(h.island || "Mercedes");
      const hotelType = String(h.type || "hotel").toLowerCase().replace(/[^a-z0-9_-]/g, "") || "hotel";
      const hotelTypeLabel = escapeHtml(String(h.type || "hotel").toUpperCase());

      return `
      <div class="hotel-featured-card hotel-card-link" onclick="openHotelDetails(${hotelId}, 'featured')">
        <div class="hotel-featured-img-wrap">
          <img class="hotel-featured-img" src="${hotelImage}" alt="${hotelName}" loading="${imageLoading}" decoding="async" fetchpriority="${imagePriority}" />
          <span class="hotel-featured-badge ${hotelType}">${hotelTypeLabel}</span>
        </div>
        <div class="hotel-featured-info">
          <h3 class="hotel-featured-title">${hotelName}</h3>
          <p class="hotel-featured-location">${hotelIsland}</p>
          <div class="hotel-featured-reviews">
            ${renderStars(h.rating, Number(h.total_reviews ?? 0), "featured")}
          </div>
          <div class="hotel-featured-amenities">
            ${visibleAmenities.map(a => `<span class="hotel-featured-amenity">${escapeHtml(a)}</span>`).join("")}
            ${hiddenAmenities.length > 0 ? `
              <div class="hotel-featured-more">
                +${hiddenAmenities.length} more
                <div class="hotel-featured-tooltip">
                  ${(h.amenities || []).map(a => `<span class="hotel-featured-amenity">${escapeHtml(a)}</span>`).join("")}
                </div>
              </div>
            ` : ""}
          </div>
          <div class="hotel-featured-price-wrap">
            <span class="hotel-featured-price-label">as low as</span>
            <div class="hotel-featured-price">
              ₱${Number(h.price || 0).toLocaleString('en-PH')}
              <span>/night</span>
            </div>
          </div>
          <button class="hotel-featured-btn" onclick="event.stopPropagation(); openHotelDetails(${hotelId}, 'featured')">Check Availability</button>
        </div>
      </div>
    `;
    }).join("");

    grid.innerHTML = cards;
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

  function openAmenities(hotelId) {
    const hotel = hotels.find(h => Number(h.id) === Number(hotelId));
    if (!hotel) return;
    document.getElementById("modalTitle").textContent = hotel.name || "Hotel amenities";
    const amenityContainer = document.getElementById("modalAmenities");
    amenityContainer.replaceChildren();
    (hotel.amenities || []).forEach(amenity => {
      const pill = document.createElement("span");
      pill.className = "hotel-result-modal-pill";
      pill.textContent = String(amenity);
      amenityContainer.appendChild(pill);
    });
    document.getElementById("amenityModal").style.display = "flex";
  }

  function closeAmenities() {
    document.getElementById("amenityModal").style.display = "none";
  }

  function escapeHtml(value) {
    return String(value ?? "")
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;")
      .replace(/'/g, "&#39;");
  }

  function getFallbackImage(type) {
    if (type === "guide") return "img/default-guide.png";
    if (type === "boat") return "img/default-boat.png";
    if (type === "package") return "img/sampleimage.png";
    return "img/sampleimage.png";
  }

function normalizeImagePath(rawPath, type = "generic") {
  let src = String(rawPath ?? "").trim();

  if (!src) return getFallbackImage(type);

  src = src.replace(/\\/g, "/");

  // absolute or base64
  if (src.startsWith("http") || src.startsWith("data:")) {
    return src;
  }

  // remove leading slashes
  src = src.replace(/^\/+/, "");

  // FIX: prevent double folder injection
  const alreadyHasFolder =
    src.startsWith("uploads/") ||
    src.startsWith("upload/") ||
    src.startsWith("php/upload/") ||
    src.startsWith("img/");

  if (alreadyHasFolder) {
    // normalize only "upload/" → "php/upload/"
    if (src.startsWith("upload/")) {
      return `php/${src}`; // upload → php/upload
    }
    return src;
  }

  // SAFE BASE PATH RULES
  if (type === "package") return `php/upload/${src}`;
  if (type === "boat" || type === "guide") return `uploads/${src}`;

  return `img/${src}`;
}

  const serviceDrawerState = {
    type: "",
    item: null,
    images: [],
    index: 0,
    timer: null,
    previousFocus: null
  };

  function formatServicePrice(value) {
    return `₱${Number(value || 0).toLocaleString("en-PH")}`;
  }

  function getServiceDrawerElements() {
    return {
      drawer: document.getElementById("serviceDetailsDrawer"),
      overlay: document.getElementById("serviceDrawerOverlay"),
      image: document.getElementById("serviceGalleryImage"),
      badge: document.getElementById("serviceGalleryBadge"),
      count: document.getElementById("serviceGalleryCount"),
      dots: document.getElementById("serviceGalleryDots"),
      prev: document.getElementById("serviceGalleryPrev"),
      next: document.getElementById("serviceGalleryNext"),
      eyebrow: document.getElementById("serviceDrawerEyebrow"),
      title: document.getElementById("serviceDrawerTitle"),
      rating: document.getElementById("serviceDrawerRating"),
      stats: document.getElementById("serviceDetailStats"),
      description: document.getElementById("serviceDrawerDescription"),
      price: document.getElementById("serviceDrawerPrice"),
      priceUnit: document.getElementById("serviceDrawerPriceUnit"),
      book: document.getElementById("serviceDrawerBookBtn"),
      favorite: document.getElementById("serviceDrawerFavorite"),
      close: document.getElementById("serviceDrawerClose")
    };
  }

  function stopServiceGalleryAutoplay() {
    if (serviceDrawerState.timer) {
      window.clearInterval(serviceDrawerState.timer);
      serviceDrawerState.timer = null;
    }
  }

  function startServiceGalleryAutoplay() {
    stopServiceGalleryAutoplay();
    if (document.getElementById("serviceImageModal")?.classList.contains("open")) return;
    if (serviceDrawerState.type !== "boat" || serviceDrawerState.images.length < 2) return;
    serviceDrawerState.timer = window.setInterval(() => {
      showServiceGalleryImage(serviceDrawerState.index + 1);
    }, 4500);
  }

  function showServiceGalleryImage(nextIndex) {
    const elements = getServiceDrawerElements();
    const imageCount = serviceDrawerState.images.length;
    if (!elements.image || imageCount === 0) return;

    serviceDrawerState.index = (nextIndex + imageCount) % imageCount;
    const imagePath = serviceDrawerState.images[serviceDrawerState.index];
    const fallback = getFallbackImage(serviceDrawerState.type);

    elements.image.classList.add("is-changing");
    window.setTimeout(() => {
      elements.image.src = imagePath;
      elements.image.alt = `${serviceDrawerState.item?.name || "Tour service"} image ${serviceDrawerState.index + 1}`;
      elements.image.onerror = () => {
        elements.image.onerror = null;
        elements.image.src = fallback;
      };
      elements.image.classList.remove("is-changing");
    }, 130);

    elements.count.textContent = `${serviceDrawerState.index + 1} / ${imageCount}`;
    elements.dots.querySelectorAll("button").forEach((dot, index) => {
      const isActive = index === serviceDrawerState.index;
      dot.classList.toggle("active", isActive);
      dot.setAttribute("aria-current", isActive ? "true" : "false");
    });
    updateExpandedServiceImage();
  }

  function changeServiceGalleryImage(direction) {
    showServiceGalleryImage(serviceDrawerState.index + direction);
    startServiceGalleryAutoplay();
  }

  function updateExpandedServiceImage() {
    const modal = document.getElementById("serviceImageModal");
    if (!modal?.classList.contains("open") || serviceDrawerState.images.length === 0) return;

    const modalImage = document.getElementById("serviceImageModalImage");
    const modalCount = document.getElementById("serviceImageModalCount");
    const modalCaption = document.getElementById("serviceImageModalCaption");
    const modalPrev = document.getElementById("serviceImageModalPrev");
    const modalNext = document.getElementById("serviceImageModalNext");
    const imageCount = serviceDrawerState.images.length;

    modalImage.src = serviceDrawerState.images[serviceDrawerState.index];
    modalImage.alt = `${serviceDrawerState.item?.name || "Tour service"} expanded image ${serviceDrawerState.index + 1}`;
    modalImage.onerror = () => {
      modalImage.onerror = null;
      modalImage.src = getFallbackImage(serviceDrawerState.type);
    };
    modalCount.textContent = `${serviceDrawerState.index + 1} / ${imageCount}`;
    modalCaption.textContent = serviceDrawerState.item?.name || "";
    modalPrev.hidden = imageCount < 2;
    modalNext.hidden = imageCount < 2;
  }

  function openServiceImageModal() {
    if (!serviceDrawerState.item || serviceDrawerState.images.length === 0) return;
    const modal = document.getElementById("serviceImageModal");
    stopServiceGalleryAutoplay();
    modal.classList.add("open");
    modal.setAttribute("aria-hidden", "false");
    updateExpandedServiceImage();
    window.setTimeout(() => document.getElementById("serviceImageModalClose").focus(), 80);
  }

  function closeServiceImageModal() {
    const modal = document.getElementById("serviceImageModal");
    if (!modal.classList.contains("open")) return;
    modal.classList.remove("open");
    modal.setAttribute("aria-hidden", "true");
    document.getElementById("serviceGalleryExpand")?.focus();
    startServiceGalleryAutoplay();
  }

  function buildServiceStat(icon, label, value) {
    return `
      <div class="service-detail-stat">
        <span class="service-detail-stat-icon" aria-hidden="true"><i class="${icon}"></i></span>
        <div>
          <span>${escapeHtml(label)}</span>
          <strong>${escapeHtml(value)}</strong>
        </div>
      </div>
    `;
  }

  function openServiceDetails(type, itemId) {
    clearActiveSearchTabForExit();
    window.location.href = `service_details.php?type=${encodeURIComponent(type)}&id=${encodeURIComponent(itemId)}`;
    return;
    const collection = type === "boat" ? tourBoats : tourGuides;
    const item = collection.find(entry => Number(entry.id) === Number(itemId));
    if (!item) return;

    const elements = getServiceDrawerElements();
    const isBoat = type === "boat";
    const rawImages = isBoat && Array.isArray(item.images) && item.images.length
      ? item.images
      : [item.img];
    const images = [...new Set(rawImages
      .map(image => normalizeImagePath(image, type))
      .filter(Boolean))];

    window.RecentlyViewed?.add({
      type,
      id: item.id,
      name: item.name || (isBoat ? "Tour Boat" : "Tour Guide"),
      image: images[0] || getFallbackImage(type),
      subtitle: isBoat
        ? `${Number(item.capacity || 0) || "Private"} pax · Tour Boat`
        : `${item.specialization || "Mercedes tours"} · Tour Guide`,
      price: Number(item.price || 0),
      priceUnit: isBoat ? "/service" : "/day",
      rating: Number(item.rating || 0)
    });
    displayRecentlyViewed();

    serviceDrawerState.type = type;
    serviceDrawerState.item = item;
    serviceDrawerState.images = images.length ? images : [getFallbackImage(type)];
    serviceDrawerState.index = 0;
    serviceDrawerState.previousFocus = document.activeElement;

    const favoriteType = isBoat ? "boat" : "guide";
    const isFavorite = Boolean(serviceFavoriteIds[favoriteType]?.[Number(item.id)]);
    elements.favorite.dataset.favoriteType = favoriteType;
    elements.favorite.dataset.favoriteId = String(Number(item.id));
    elements.favorite.classList.toggle("is-favorite", isFavorite);
    elements.favorite.setAttribute("aria-pressed", isFavorite ? "true" : "false");
    elements.favorite.setAttribute("aria-label", isFavorite ? "Remove from favorites" : "Add to favorites");
    elements.favorite.title = isFavorite ? "Remove from favorites" : "Add to favorites";

    elements.badge.innerHTML = isBoat
      ? '<i class="fa-solid fa-ship" aria-hidden="true"></i> Tour Boat'
      : '<i class="fa-solid fa-user-tie" aria-hidden="true"></i> Local Tour Guide';
    elements.eyebrow.textContent = isBoat ? "Explore Mercedes by sea" : "Meet your local guide";
    elements.title.textContent = item.name || (isBoat ? "Tour Boat" : "Tour Guide");
    elements.rating.innerHTML = renderStars(item.rating || 0, item.total_reviews || 0, "carousel");

    if (isBoat) {
      elements.stats.innerHTML = [
        buildServiceStat("fa-solid fa-user-group", "Capacity", `${Number(item.capacity || 0) || "—"} guests`),
        buildServiceStat("fa-solid fa-ruler-combined", "Boat size", item.size || "Not specified"),
        buildServiceStat("fa-solid fa-hashtag", "Boat number", item.boat_number || "Not specified")
      ].join("");
      elements.description.textContent = item.long_description || item.short_description ||
        "A locally operated tour boat ready for island transfers and memorable coastal trips around Mercedes.";
      elements.priceUnit.textContent = "per tour service";
      elements.book.href =
        `tour_booking.php?booking_type=boat&preferred=${encodeURIComponent(item.name || "Tour Boat")}&return=${encodeURIComponent("hotel_resorts.php?tab=boats")}`;
    } else {
      const experience = Number(item.experience || 0);
      const age = Number(item.age || 0);
      elements.stats.innerHTML = [
        buildServiceStat("fa-solid fa-briefcase", "Experience", experience > 0 ? `${experience} year${experience === 1 ? "" : "s"}` : "Local guide"),
        buildServiceStat("fa-solid fa-id-card", "Guide age", age > 0 ? `${age} years old` : "Not specified"),
        buildServiceStat("fa-solid fa-map-location-dot", "Specialty", item.specialization || "Mercedes tours")
      ].join("");
      elements.description.textContent = item.description || item.specialization ||
        "A knowledgeable local guide who can help you discover Mercedes destinations with confidence.";
      elements.priceUnit.textContent = "per day";
      elements.book.href =
        `tour_booking.php?booking_type=tourguide&preferred=${encodeURIComponent(item.name || "Tour Guide")}&return=${encodeURIComponent("hotel_resorts.php?tab=guides")}`;
    }

    elements.price.textContent = formatServicePrice(item.price);
    elements.dots.innerHTML = serviceDrawerState.images.map((_, index) => `
      <button type="button" aria-label="Show image ${index + 1}" onclick="showServiceGalleryImage(${index}); startServiceGalleryAutoplay();"></button>
    `).join("");

    const hasGallery = serviceDrawerState.images.length > 1;
    elements.prev.hidden = !hasGallery;
    elements.next.hidden = !hasGallery;
    elements.count.hidden = !hasGallery;
    elements.dots.hidden = !hasGallery;

    showServiceGalleryImage(0);
    elements.drawer.classList.add("open");
    elements.overlay.classList.add("open");
    elements.drawer.setAttribute("aria-hidden", "false");
    elements.overlay.setAttribute("aria-hidden", "false");
    document.body.classList.add("service-drawer-open");
    elements.drawer.querySelector(".service-drawer-scroll").scrollTop = 0;
    window.setTimeout(() => elements.close.focus(), 120);
    startServiceGalleryAutoplay();
  }

  function closeServiceDetails() {
    const elements = getServiceDrawerElements();
    closeServiceImageModal();
    stopServiceGalleryAutoplay();
    elements.drawer.classList.remove("open");
    elements.overlay.classList.remove("open");
    elements.drawer.setAttribute("aria-hidden", "true");
    elements.overlay.setAttribute("aria-hidden", "true");
    document.body.classList.remove("service-drawer-open");
    if (serviceDrawerState.previousFocus && typeof serviceDrawerState.previousFocus.focus === "function") {
      serviceDrawerState.previousFocus.focus();
    }
  }

  document.addEventListener("DOMContentLoaded", () => {
    const elements = getServiceDrawerElements();
    if (!elements.drawer) return;

    elements.close.addEventListener("click", closeServiceDetails);
    elements.overlay.addEventListener("click", closeServiceDetails);
    elements.prev.addEventListener("click", () => changeServiceGalleryImage(-1));
    elements.next.addEventListener("click", () => changeServiceGalleryImage(1));
    elements.image.addEventListener("click", openServiceImageModal);
    document.getElementById("serviceGalleryExpand").addEventListener("click", openServiceImageModal);
    document.getElementById("serviceImageModalClose").addEventListener("click", closeServiceImageModal);
    document.getElementById("serviceImageModalBackdrop").addEventListener("click", closeServiceImageModal);
    document.getElementById("serviceImageModalPrev").addEventListener("click", () => changeServiceGalleryImage(-1));
    document.getElementById("serviceImageModalNext").addEventListener("click", () => changeServiceGalleryImage(1));

    const gallery = document.getElementById("serviceGallery");
    gallery.addEventListener("mouseenter", stopServiceGalleryAutoplay);
    gallery.addEventListener("mouseleave", startServiceGalleryAutoplay);

    let touchStartX = 0;
    gallery.addEventListener("touchstart", event => {
      touchStartX = event.changedTouches[0]?.clientX || 0;
      stopServiceGalleryAutoplay();
    }, { passive: true });
    gallery.addEventListener("touchend", event => {
      const touchEndX = event.changedTouches[0]?.clientX || 0;
      const distance = touchEndX - touchStartX;
      if (Math.abs(distance) > 45) {
        changeServiceGalleryImage(distance > 0 ? -1 : 1);
      } else {
        startServiceGalleryAutoplay();
      }
    }, { passive: true });

    document.addEventListener("keydown", event => {
      if (!elements.drawer.classList.contains("open")) return;
      const imageModalOpen = document.getElementById("serviceImageModal").classList.contains("open");
      if (event.key === "Escape") {
        if (imageModalOpen) {
          closeServiceImageModal();
        } else {
          closeServiceDetails();
        }
        return;
      }
      if (event.key === "ArrowLeft" && serviceDrawerState.images.length > 1) {
        changeServiceGalleryImage(-1);
      }
      if (event.key === "ArrowRight" && serviceDrawerState.images.length > 1) {
        changeServiceGalleryImage(1);
      }
    });
  });

  document.addEventListener("favorite:changed", event => {
    const detail = event.detail || {};
    if (!["guide", "boat"].includes(detail.type) || !detail.id) return;
    if (!serviceFavoriteIds[detail.type]) serviceFavoriteIds[detail.type] = {};
    if (detail.favorited) {
      serviceFavoriteIds[detail.type][Number(detail.id)] = true;
    } else {
      delete serviceFavoriteIds[detail.type][Number(detail.id)];
    }
  });

  function getPackageTypeLabel(packageType, packageRange, packageDuration) {
    const rawType = String(packageType || "").trim().toLowerCase();
    const rawRange = String(packageRange || "").trim().toLowerCase();
    const rawDuration = String(packageDuration || "").trim().toLowerCase();
    const probe = `${rawType} ${rawRange} ${rawDuration}`;
    if (probe.includes("overnight")) return "Overnight";
    if (probe.includes("day tour") || probe.includes("daytour") || probe.includes("same day") || probe.includes("sameday")) return "Same day";
    return "Tour Package";
  }

  function displayCarousels() {
    displayCarouselItems('carouselPackages', tourPackages, 'package');
    displayCarouselItems('carouselBoats', tourBoats, 'boat');
    displayCarouselItems('carouselGuides', tourGuides, 'guide');
  }
function displayCarouselItems(elementId, items, type) {
    const container = document.getElementById(elementId);
    if (!container) return;

    let html = "";

    items.forEach((item, itemIndex) => {

        let cardHtml = "";
        const aboveFoldImage = type === "package" && itemIndex < (window.innerWidth <= 600 ? 1 : 3);
        const imageLoading = aboveFoldImage ? "eager" : "lazy";
        const imagePriority = aboveFoldImage ? "high" : "low";

        switch (type) {

            case "hotel":
                const hotelId = Number(item.id) || 0;
                const hotelImage = escapeHtml(item.img || "img/sampleimage.png");
                const hotelName = escapeHtml(item.name || "Hotel");
                const hotelIsland = escapeHtml(item.island || "Mercedes");
                const hotelType = String(item.type || "hotel").toLowerCase().replace(/[^a-z0-9_-]/g, "") || "hotel";
                const hotelTypeLabel = escapeHtml(String(item.type || "Hotel").toUpperCase());
                cardHtml = `
                    <div class="carousel-card" onclick="openHotelDetails(${hotelId}, 'carousel')">
                        <div class="carousel-card-img">
                            <img
                                src="${hotelImage}"
                                alt="${hotelName}"
                                loading="${imageLoading}"
                                decoding="async"
                                fetchpriority="${imagePriority}">

                            <span class="carousel-card-badge ${hotelType}">
                                ${hotelTypeLabel}
                            </span>
                        </div>

                        <div class="carousel-card-content">
                            <h4 class="carousel-card-title">${hotelName}</h4>

                            <p class="carousel-card-location">
                                ${hotelIsland}
                            </p>

                            <div class="carousel-card-rating">
                                ${renderStars(item.rating || 0, item.total_reviews || 0)}
                            </div>

                            <div class="carousel-card-price">
                                <span class="price-label">from</span>
                                <span class="price">
                                    ₱${parseFloat(item.price || 0).toFixed(0)}
                                </span>
                                <span class="price-unit">/night</span>
                            </div>
                        </div>
                    </div>
                `;
                break;

            case "package": {

                const packageImg = escapeHtml(normalizeImagePath(item.img, "package"));
                const packageName = escapeHtml(item.name || "Tour Package");
                const packageLocation = escapeHtml(item.location || "Mercedes");
                const packageDuration = escapeHtml(item.duration || item.package_range || "Tour Package");
                const packageTypeLabel = getPackageTypeLabel(item.package_type, item.package_range, item.duration);
                const packageTypePill = escapeHtml(packageTypeLabel || "Tour Package");
                const packageOperator = escapeHtml(item.operator_name || "Unknown Operator");
                const packagePrice = escapeHtml(item.price_formatted ?? Number(item.price ?? 0));

                const packageId = Number(item.id) || 0;

                const packageDetailsUrl =
                    packageId > 0
                        ? `package_details.php?package_id=${encodeURIComponent(packageId)}`
                        : "hotel_resorts.php?tab=tours";

                cardHtml = `
                    <div class="carousel-card">

                        <div class="carousel-card-img">
                            <img
                                src="${packageImg}"
                                alt="${packageName}"
                                loading="${imageLoading}"
                                decoding="async"
                                fetchpriority="${imagePriority}"
                                onerror="this.onerror=null;this.src='${getFallbackImage("package")}'">

                            <span class="carousel-card-badge">
                                ${packageDuration}
                            </span>
                        </div>

                        <div class="carousel-card-content">

                            <div class="carousel-card-heading">
                                <h4 class="carousel-card-title package-title">
                                    ${packageName}
                                </h4>

                                <span class="carousel-card-type-pill">
                                    ${packageTypePill}
                                </span>
                            </div>

                            <p class="carousel-card-location">
                                ${packageLocation}
                            </p>

                            <p class="carousel-card-meta">
                                <strong>Operator:</strong> ${packageOperator}
                            </p>

                            <div class="carousel-card-rating">
                                ${renderStars(item.rating || 0, item.total_reviews || 0, "carousel")}
                            </div>

                            <div class="carousel-card-price">
                                <span class="price">
                                    ₱${packagePrice}
                                </span>

                                <span class="price-label">
                                    /pax
                                </span>
                            </div>

                            <a
                                class="carousel-card-btn"
                                href="${packageDetailsUrl}">
                                View Details
                            </a>

                        </div>
                    </div>
                `;
                break;
            }

            case "boat": {

                const boatImg = escapeHtml(normalizeImagePath(item.img, "boat"));
                const boatName = escapeHtml(item.name || "Tour Boat");
                const boatCapacity = item.capacity || 8;
                const boatPrice = item.price || 0;

                cardHtml = `
                    <div class="carousel-card">

                        <div class="carousel-card-img">
                            <img
                                src="${boatImg}"
                                alt="${boatName}"
                                loading="${imageLoading}"
                                decoding="async"
                                fetchpriority="${imagePriority}"
                                onerror="this.onerror=null;this.src='${getFallbackImage("boat")}'">

                            <span class="carousel-card-badge">
                                ${boatCapacity} pax
                            </span>
                        </div>

                        <div class="carousel-card-content">

                            <h4 class="carousel-card-title">
                                ${boatName}
                            </h4>

                            <p class="carousel-card-location">
                                Available for Tours
                            </p>

                            <div class="carousel-card-rating">
                                ${renderStars(item.rating || 0, item.total_reviews || 0, "carousel")}
                            </div>

                            <div class="carousel-card-price">
                                <span class="price-label">
                                    Per ${boatCapacity} pax
                                </span>

                                <span class="price">
                                    ₱${Number(boatPrice).toLocaleString("en-PH")}
                                </span>
                            </div>

                            <a
                                class="carousel-card-btn"
                                href="service_details.php?type=boat&amp;id=${Number(item.id) || 0}">
                                View Details
                            </a>

                        </div>
                    </div>
                `;
                break;
            }

            case "guide": {

                const guideImg = escapeHtml(normalizeImagePath(item.img, "guide"));
                const guideName = escapeHtml(item.name || "Tour Guide");
                const guidePrice = item.price || 0;

                cardHtml = `
                    <div class="carousel-card">

                        <div class="carousel-card-img">
                            <img
                                src="${guideImg}"
                                alt="${guideName}"
                                loading="${imageLoading}"
                                decoding="async"
                                fetchpriority="${imagePriority}"
                                onerror="this.onerror=null;this.src='${getFallbackImage("guide")}'">

                            <span class="carousel-card-badge">
                                Tour Guide
                            </span>
                        </div>

                        <div class="carousel-card-content">

                            <h4 class="carousel-card-title">
                                ${guideName}
                            </h4>

                            <p class="carousel-card-location">
                                Tour Guide
                            </p>

                            <div class="carousel-card-rating">
                                ${renderStars(item.rating || 0, item.total_reviews || 0, "carousel")}
                            </div>

                            <div class="carousel-card-price">
                                <span class="price-label">
                                    Per Day
                                </span>

                                <span class="price">
                                    ₱${Number(guidePrice).toLocaleString("en-PH")}
                                </span>
                            </div>

                            <a
                                class="carousel-card-btn"
                                href="service_details.php?type=guide&amp;id=${Number(item.id) || 0}">
                                View Details
                            </a>

                        </div>
                    </div>
                `;
                break;
            }

        }

        html += cardHtml;

    });

    container.innerHTML = html;
}

  function scrollCarousel(type, direction) {
    const carouselMap = {
      'packages': 'carouselPackages',
      'boats': 'carouselBoats',
      'guides': 'carouselGuides'
    };

    const carouselId = carouselMap[type];
    const carousel = document.getElementById(carouselId);
    if (!carousel) return;

    const cardWidth = carousel.querySelector('.carousel-card')?.offsetWidth || 300;
    const scrollAmount = cardWidth * 5;
    carousel.scrollBy({
      left: direction * scrollAmount,
      behavior: 'smooth'
    });
  }

  function initDragScrollableCarousels() {
    const tracks = document.querySelectorAll(
      "#featuredGrid, #carouselPackages, #carouselBoats, #carouselGuides, #recentlyViewedTrack"
    );

    tracks.forEach(track => {
      if (track.dataset.dragScrollReady === "true") return;
      track.dataset.dragScrollReady = "true";
      track.classList.add("drag-scroll-track");

      let pointerId = null;
      let startX = 0;
      let startScrollLeft = 0;
      let pendingClientX = 0;
      let dragFrame = 0;
      let dragged = false;
      let suppressClick = false;

      const paintDragPosition = () => {
        dragFrame = 0;
        track.scrollLeft = startScrollLeft - (pendingClientX - startX);
      };

      track.addEventListener("pointerdown", event => {
        if (event.pointerType !== "mouse" || event.button !== 0) return;
        pointerId = event.pointerId;
        startX = event.clientX;
        pendingClientX = event.clientX;
        startScrollLeft = track.scrollLeft;
        dragged = false;
        suppressClick = false;
      });

      track.addEventListener("pointermove", event => {
        if (event.pointerId !== pointerId) return;
        const coalescedEvents = event.getCoalescedEvents?.();
        const latestEvent = coalescedEvents?.[coalescedEvents.length - 1] || event;
        const distance = latestEvent.clientX - startX;
        if (Math.abs(distance) > 5 && !dragged) {
          dragged = true;
          track.setPointerCapture(pointerId);
          track.classList.add("drag-scroll-active");
        }
        if (!dragged) return;
        event.preventDefault();
        pendingClientX = latestEvent.clientX;
        if (!dragFrame) dragFrame = window.requestAnimationFrame(paintDragPosition);
      });

      const finishDrag = event => {
        if (event.pointerId !== pointerId) return;
        suppressClick = event.type === "pointerup" && dragged;
        if (dragFrame) {
          window.cancelAnimationFrame(dragFrame);
          paintDragPosition();
        }
        if (track.hasPointerCapture(pointerId)) track.releasePointerCapture(pointerId);
        pointerId = null;
        dragged = false;
        track.classList.remove("drag-scroll-active");
      };

      track.addEventListener("pointerup", finishDrag);
      track.addEventListener("pointercancel", finishDrag);
      track.addEventListener("lostpointercapture", () => {
        if (dragFrame) window.cancelAnimationFrame(dragFrame);
        dragFrame = 0;
        pointerId = null;
        dragged = false;
        track.classList.remove("drag-scroll-active");
      });
      track.addEventListener("click", event => {
        if (!suppressClick) return;
        event.preventDefault();
        event.stopImmediatePropagation();
        suppressClick = false;
      }, true);
      track.addEventListener("dragstart", event => event.preventDefault());
    });
  }

  function scrollFeatured(direction) {
    const featured = document.getElementById('featuredGrid');
    if (!featured) return;

    const cardWidth = featured.querySelector('.hotel-featured-card')?.offsetWidth || 280;
    const scrollAmount = cardWidth * 5;
    featured.scrollBy({
      left: direction * scrollAmount,
      behavior: 'smooth'
    });
  }

  function showFeatured() {
    displayRecentlyViewed();
    document.getElementById("featuredSection").style.display = "block";
    document.getElementById("carouselSection").style.display = "block";
    document.querySelector(".hotel-main").style.display = "none";
    document.getElementById("heroSection").style.display = "block";
    document.getElementById("searchSection").classList.remove("search-fixed");
  }

  window.addEventListener("pageshow", function (event) {
    const navType = performance.getEntriesByType("navigation")[0].type;
    if (event.persisted || navType === "back_forward") {
      sessionStorage.removeItem("searchMode");
      sessionStorage.removeItem("searchData");
      setActiveSearchTab("hotels");
      showFeaturedMode();
    }
  });
</script>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script src="js/booking-payment-confirmation.js"></script>
<script src="js/back-to-top.js?v=<?= (int)@filemtime(__DIR__ . '/../js/back-to-top.js') ?>"></script>
</body>
</html>
