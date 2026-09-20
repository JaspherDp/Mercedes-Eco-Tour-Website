<?php
chdir(__DIR__ . '/..');
require_once __DIR__ . '/../php/session_security.php';
AppSessionStart();
require_once 'php/db_connection.php';
require_once 'php/admin_auth_helper.php';
require_once 'php/hotel_reviews_helper.php';
require_once 'php/hotel_rooms_helper.php';
require_once 'php/hotel_content_helper.php';
require_once 'php/favorites_helper.php';
require_once 'payments/PaymentHelper.php';
HoEnsureHotelResortContentColumns($pdo);
ensureHotelReviewManagementColumns($pdo);
$hasDescriptionText = HoHotelResortsHasColumn($pdo, 'description_text');
$hasRulesJson = HoHotelResortsHasColumn($pdo, 'rules_json');
$hasGalleryImagesJson = HoHotelResortsHasColumn($pdo, 'gallery_images_json');
$hasOwnerContentJson = HoHotelResortsHasColumn($pdo, 'owner_content_json');

$descriptionSelect = $hasDescriptionText ? 'h.description_text' : "NULL AS description_text";
$rulesSelect = $hasRulesJson ? 'h.rules_json' : "NULL AS rules_json";
$gallerySelect = $hasGalleryImagesJson ? 'h.gallery_images_json' : "NULL AS gallery_images_json";
$ownerContentSelect = $hasOwnerContentJson ? 'h.owner_content_json' : "NULL AS owner_content_json";

$groupByParts = ['h.hotel_resort_id', 'h.name', 'h.island', 'h.type', 'h.price', 'h.image_path', 'h.amenities_json'];
if ($hasDescriptionText) $groupByParts[] = 'h.description_text';
if ($hasRulesJson) $groupByParts[] = 'h.rules_json';
if ($hasGalleryImagesJson) $groupByParts[] = 'h.gallery_images_json';
if ($hasOwnerContentJson) $groupByParts[] = 'h.owner_content_json';
$groupBySql = implode(', ', $groupByParts);

$hotelId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$source = isset($_GET['source']) ? trim((string)$_GET['source']) : 'featured';
$source = $source === 'result' ? 'result' : 'featured';
$isLoggedIn = isset($_SESSION['tourist_id']);
$hotelIsFavorite = $isLoggedIn
  ? isFavorite($pdo, (int)$_SESSION['tourist_id'], 'hotel', $hotelId)
  : false;
$favoritesCsrf = favoriteCsrfToken();
$bookingSuccess = isset($_GET['booking_success']) && $_GET['booking_success'] === '1';
$bookingRef = isset($_GET['booking_ref'])
  ? preg_replace('/[^A-Z0-9-]/i', '', trim((string)$_GET['booking_ref']))
  : '';
$hotelVisibilitySql = AdminValidateSession($pdo) ? '' : "AND h.status = 'active'";

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
  {$ownerContentSelect},
  COALESCE(ROUND(AVG(r.rating), 1), 0) AS rating,
  COUNT(r.review_id) AS total_reviews
FROM hotel_resorts h
LEFT JOIN hotel_resort_reviews r ON r.hotel_resort_id = h.hotel_resort_id AND r.moderation_status = 'published'
WHERE h.hotel_resort_id = ? {$hotelVisibilitySql}
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

$stmtRegisteredMapHotels = $pdo->query("
SELECT
  h.hotel_resort_id AS id,
  h.name,
  h.island,
  h.type,
  COALESCE(rp.min_price, h.price) AS price,
  h.image_path AS img,
  COALESCE(ROUND(AVG(r.rating), 1), 0) AS rating,
  COUNT(r.review_id) AS total_reviews
FROM hotel_resorts h
LEFT JOIN hotel_resort_reviews r ON r.hotel_resort_id = h.hotel_resort_id AND r.moderation_status = 'published'
LEFT JOIN (
  SELECT hotel_resort_id, MIN(price) AS min_price
  FROM hotel_rooms
  WHERE status = 'active'
  GROUP BY hotel_resort_id
) rp ON rp.hotel_resort_id = h.hotel_resort_id
WHERE h.status = 'active'
GROUP BY h.hotel_resort_id, h.name, h.island, h.type, h.price, h.image_path, rp.min_price
ORDER BY h.hotel_resort_id ASC
");
$registeredMapHotels = $stmtRegisteredMapHotels->fetchAll(PDO::FETCH_ASSOC);
foreach ($registeredMapHotels as &$registeredMapHotel) {
    $registeredMapHotel['id'] = (int)$registeredMapHotel['id'];
    $registeredMapHotel['price'] = (float)$registeredMapHotel['price'];
    $registeredMapHotel['rating'] = (float)$registeredMapHotel['rating'];
    $registeredMapHotel['total_reviews'] = (int)$registeredMapHotel['total_reviews'];
    $registeredMapHotel['img'] = !empty($registeredMapHotel['img'])
        ? $registeredMapHotel['img']
        : 'img/sampleimage.png';
}
unset($registeredMapHotel);

// =========================================================
// TEMPORARY MAP ADDRESS ARRAY (Updated with your data)
// =========================================================
$hotelAddresses = [
    // Your specific requests
    'Summer Time Beach Resort'    => 'Summer Time Beach Resort, Caringo Island, Mercedes, Camarines Norte',
    'Zamudio Beach Resort'       => 'Zamudio Beach Resort, Cayucyucan, Mercedes, Camarines Norte',
    'Apuao Pequeña Island Resort' => 'Apuao Pequeña Island, Mercedes, Camarines Norte',
    
    // Existing/Other Resorts
    'Palms Farm Resort'          => 'Palms Farm Resort, Cayucyucan, Mercedes, Camarines Norte',
    'Apuao Grande Island Resort' => 'Apuao Grande Island, Mercedes, Camarines Norte',
    'Mercedes Beach Resort'      => 'Mercedes Beach Resort, Apuao Island, Mercedes, Camarines Norte',
    'Paradise Cove Resort'       => 'Paradise Cove Resort, Quinapaguian Island, Mercedes, Camarines Norte',
    'Island View Hotel'          => 'Island View Hotel, Malasugui Island, Mercedes, Camarines Norte',
    'Coral Garden Resort'        => 'Coral Garden Resort, Quinapaguian Island, Mercedes, Camarines Norte',
    'Lagoon Paradise Resort'     => 'Lagoon Paradise Resort, Malasugui Island, Mercedes, Camarines Norte'
];

/* =========================================================
   DYNAMIC MAP ADDRESS ASSIGNMENT
========================================================= */
$hotelName = $hotel['name'] ?? '';

$ownerContent = HoDecodeJsonObject((string)($hotel['owner_content_json'] ?? '{}'));
$ownerAddress = trim((string)($ownerContent['address'] ?? ''));
if ($ownerAddress !== '') {
    $mapAddress = $ownerAddress;
} elseif (array_key_exists($hotelName, $hotelAddresses)) {
    // If the name matches exactly in our array, use that address
    $mapAddress = $hotelAddresses[$hotelName];
} else {
    // Dynamic Fallback: Uses the name + island from the DB
    $island = $hotel['island'] ?? 'Mercedes';
    $mapAddress = $hotelName . ", " . $island . ", Mercedes, Camarines Norte";
}

$stmtReviews = $pdo->prepare("
SELECT 
  r.reviewer_name,
  r.rating,
  r.review_message,
  r.owner_reply,
  r.created_at,
  t.profile_picture,
  t.google_id
FROM hotel_resort_reviews r
LEFT JOIN tourist t ON t.tourist_id = r.tourist_id
WHERE r.hotel_resort_id = ? AND r.moderation_status = 'published'
ORDER BY r.created_at DESC
LIMIT 10
");
$stmtReviews->execute([$hotelId]);
$reviews = $stmtReviews->fetchAll(PDO::FETCH_ASSOC);

/* =========================================================
   AI REVIEW SUMMARY
========================================================= */
function generateReviewSummary(array $reviews): array
{
    $fallback = [
        'positive' => 'Unable to generate summary.',
        'negative' => 'Unable to generate summary.'
    ];

    $apiKey = PaymentHelper::env('GEMINI_API_KEY');
    if ($apiKey === '') {
        return $fallback;
    }

    if (empty($reviews)) {
        return [
            'positive' => 'No reviews available yet.',
            'negative' => 'No complaints available yet.'
        ];
    }

    $texts = [];

    foreach ($reviews as $r) {
        $msg = trim((string)($r['review_message'] ?? ''));
        if ($msg !== '') {
            $texts[] = $msg;
        }
    }

    if (count($texts) === 0) {
        return $fallback;
    }

    $input = implode("\n", array_slice($texts, 0, 20));

    $url = "https://generativelanguage.googleapis.com/v1/models/gemini-2.5-flash:generateContent?key=" . $apiKey;

    $prompt = <<<TXT
Return ONLY valid JSON (no markdown, no explanation):

{
  "positive": "max 80 words summarizing positive feedback",
  "negative": "max 80 words summarizing complaints or issues"
}

Rules:
- Output ONLY JSON
- No ```json blocks
- No extra text
- Must be based only on reviews

Reviews:
$input
TXT;

    $payload = [
        "contents" => [
            [
                "parts" => [
                    ["text" => $prompt]
                ]
            ]
        ],
        "generationConfig" => [
            "temperature" => 0.2,
        ]
    ];

    $ch = curl_init($url);

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ["Content-Type: application/json"],
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_TIMEOUT => 25,
    ]);

    $response = curl_exec($ch);

    if (curl_errno($ch)) {
        curl_close($ch);
        return $fallback;
    }

    curl_close($ch);

$data = json_decode($response, true);

if (!isset($data['candidates'][0]['content']['parts'][0]['text'])) {
    return $fallback;
}

$text = $data['candidates'][0]['content']['parts'][0]['text'];

// =========================
// 🔥 CLEAN TEXT
// =========================
$text = trim($text);
$text = preg_replace('/```json|```/', '', $text);

// =========================
// 🔥 EXTRACT JSON SAFELY
// =========================
if (!preg_match('/\{[\s\S]*\}/', $text, $match)) {
    return $fallback;
}

$json = json_decode($match[0], true);

// =========================
// 🔥 FINAL VALIDATION
// =========================
if (json_last_error() === JSON_ERROR_NONE &&
    isset($json['positive'], $json['negative'])) {
    return [
        'positive' => trim($json['positive']),
        'negative' => trim($json['negative'])
    ];
}

return $fallback;
}

$aiSummary = generateReviewSummary($reviews);

$hotel['id'] = (int)$hotel['id'];
$hotel['price'] = (float)$hotel['price'];
$hotel['rating'] = (float)$hotel['rating'];
$hotel['total_reviews'] = (int)$hotel['total_reviews'];
$hotel['img'] = !empty($hotel['img']) ? $hotel['img'] : 'img/sampleimage.png';
$amenities = json_decode($hotel['amenities_json'] ?? '[]', true);
$hotel['amenities'] = is_array($amenities) ? $amenities : [];
$hotel['description_text'] = trim((string)($hotel['description_text'] ?? ''));
$hotel['gallery_images'] = HoDecodeJsonList((string)($hotel['gallery_images_json'] ?? '[]'));
$hotel['owner_content'] = $ownerContent;
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

function isDefaultProfileImage(?string $value): bool {
    $name = strtolower(basename(trim((string)$value)));
    return in_array($name, ['profileicon.png', 'profileicon2.png'], true);
}

function normalizeProfileImage(?string $value): string {
    $candidate = trim((string)$value);
    if ($candidate === '') return '';
    if (preg_match('~^https?://~i', $candidate)) {
        if (stripos($candidate, 'profiles.google.com') !== false
            && preg_match('#profiles\\.google\\.com/(?:s2/photos/profile/)?([^/?#]+)(?:/picture)?#i', $candidate, $m)) {
            return 'https://profiles.google.com/' . rawurlencode($m[1]) . '/picture?sz=96';
        }
        if (stripos($candidate, 'google.com/s2/photos/profile') !== false) {
            $candidate = preg_replace('/([?&])sz=\\d+/i', '$1sz=96', $candidate);
            if (!preg_match('/[?&]sz=/i', $candidate)) {
                $candidate .= (strpos($candidate, '?') !== false ? '&' : '?') . 'sz=96';
            }
            return $candidate;
        }
        if (stripos($candidate, 'googleusercontent.com') !== false) {
            $candidate = preg_replace('/([?&])sz=\\d+/i', '$1sz=96', $candidate);
            $candidate = preg_replace('/=s\\d+-c(?=$|[?&#])/i', '=s96-c', $candidate);
            $candidate = preg_replace('/=s\\d+(?=$|[?&#])/i', '=s96', $candidate);
        }
        return $candidate;
    }
    return ltrim($candidate, '/\\');
}

function buildGoogleProfileImageById(?string $googleId): string {
    return '';
}

function resolveReviewProfileImage(?string $path, ?string $googleId = null): string {
    $normalized = normalizeProfileImage($path);
    if ($normalized !== '' && !isDefaultProfileImage($normalized)) {
        if (preg_match('~^https?://~i', $normalized)) return $normalized;

        $candidates = [
            $normalized,
            'php/upload/' . basename($normalized),
            'uploads/profile/' . basename($normalized),
            'uploads/profile_pictures/' . basename($normalized),
        ];

        foreach ($candidates as $candidate) {
            if (file_exists(__DIR__ . '/../' . $candidate)) {
                return $candidate;
            }
        }
    }

    $googleImage = buildGoogleProfileImageById($googleId);
    return $googleImage !== '' ? $googleImage : '';
}

$stmtRatings = $pdo->prepare("
SELECT 
  AVG(location_rating) AS location,
  AVG(service_rating) AS service,
  AVG(value_rating) AS value_for_money,
  AVG(cleanliness_rating) AS cleanliness,
  AVG(facilities_rating) AS facilities,
  AVG(room_comfort_rating) AS room_comfort
FROM hotel_resort_reviews
WHERE hotel_resort_id = ? AND moderation_status = 'published'
");
$stmtRatings->execute([$hotelId]);
$ratings = $stmtRatings->fetch(PDO::FETCH_ASSOC);

function ratingPercent($value): float {
    return $value ? ((float)$value / 5) * 100 : 0;
}

function ratingValue($value): float {
    return $value ? round((float)$value, 1) : 0;
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
  <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="anonymous" />
  <link rel="stylesheet" href="styles/hotel_details.css?v=<?= (int)@filemtime(__DIR__ . '/../styles/hotel_details.css') ?>" />
  <link rel="stylesheet" href="styles/favorites.css" />
  <link rel="stylesheet" href="styles/back-to-top.css?v=<?= (int)@filemtime(__DIR__ . '/../styles/back-to-top.css') ?>" />
  <style>
    /* hard-match hotel_resorts search bar + calendar on hotel_details */
    #detailsSearchWrap {
      padding: 7px clamp(24px, 8vw, 150px) !important;
    }
    #detailsSearchWrap .details-search-toggle{display:none;}
    #detailsSearchWrap .search-container {
      background: #fff !important;
      padding: 7px 16px !important;
      border-radius: 18px !important;
      width: 100% !important;
      max-width: 100% !important;
      margin: 0 auto !important;
      display: grid !important;
      grid-template-columns: minmax(150px, 1fr) minmax(210px, 1.25fr) minmax(170px, 1fr) 130px !important;
      gap: 10px !important;
      align-items: end !important;
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
      min-height: 40px !important;
    }
    #detailsSearchWrap .location-search-box select { padding-right: 58px !important; }
    #detailsSearchWrap .search-btn {
      background: #2b7a66 !important; color: #fff !important; border: none !important;
      padding: 9px 18px !important; border-radius: 10px !important; cursor: pointer !important;
      width: 100% !important; min-width: 0 !important; height: 40px !important; white-space: nowrap !important; margin-top: 0 !important; font-weight: 700 !important;
      display: inline-flex !important; align-items: center !important; justify-content: center !important;
    }
    #detailsSearchWrap .search-btn:hover { background: #144d1c !important; }
    #detailsSearchWrap .input-error { border: 1px solid #dc3545 !important; background: #fff5f5 !important; }
    #detailsSearchWrap .error-text { font-size: 12px !important; color: #dc3545 !important; margin-top: 4px !important; }
    #detailsSearchWrap .flatpickr-calendar {
      border-radius: 14px !important; box-shadow: 0 16px 36px rgba(0,0,0,0.16) !important; border: 1px solid #e5ece8 !important;
      z-index: 900 !important; margin-top: 8px !important; padding: 10px 10px 8px !important;
      width: max-content !important; min-width: 1060px !important; max-width: none !important; overflow: visible !important;
    }
    #detailsSearchWrap .flatpickr-day.selected,
    #detailsSearchWrap .flatpickr-day.startRange,
    #detailsSearchWrap .flatpickr-day.endRange { background: #2b7a66 !important; border-color: #2b7a66 !important; }
    #detailsSearchWrap .flatpickr-day.inRange { background: #e8f2ec !important; border-color: #e8f2ec !important; color: #173826 !important; }
    .mobile-gallery-status,
    .mobile-gallery-favorite,
    .mobile-overview-share,
    .mobile-map-trigger{display:none;}
    .overview-support-row{display:contents;}
    #desktopOverviewMapSlot{display:contents;}
    #tabletOverviewMapSlot{display:none;}
    .mobile-floating-reserve{display:none;}
    @media (max-width: 1100px) {
      #detailsSearchWrap { padding-inline: 24px !important; }
      #detailsSearchWrap .search-container {
        grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
        gap: 9px 12px !important;
      }
      #detailsSearchWrap .search-btn{align-self:end !important;}
      .flatpickr-calendar,
      .flatpickr-calendar.inline,
      .flatpickr-calendar.open{
        width:calc(100vw - 32px) !important;
        min-width:0 !important;
        max-width:760px !important;
        overflow:hidden !important;
      }
      .flatpickr-calendar .flatpickr-months,
      .flatpickr-calendar .flatpickr-innerContainer,
      .flatpickr-calendar .flatpickr-rContainer,
      .flatpickr-calendar .flatpickr-days{
        width:100% !important;
        min-width:0 !important;
        max-width:100% !important;
      }
      .flatpickr-calendar .dayContainer,
      .flatpickr-calendar .flatpickr-weekdaycontainer{
        width:50% !important;
        min-width:50% !important;
        max-width:50% !important;
      }
    }
    @media (min-width: 769px) and (max-width: 1100px) {
      #detailsSearchWrap {
        padding-inline: 30px !important;
      }
      #detailsSearchWrap .search-container {
        grid-template-columns: minmax(0, 1fr) minmax(0, 1.15fr) minmax(0, 1fr) 78px !important;
        gap: 8px !important;
        align-items: center !important;
        padding: 7px 0 !important;
      }
      #detailsSearchWrap .search-btn {
        position: relative !important;
        width: 78px !important;
        min-width: 78px !important;
        max-width: 78px !important;
        padding: 0 !important;
        align-self: center !important;
        overflow: hidden !important;
        color: #fff !important;
        font-size: 0 !important;
      }
      #detailsSearchWrap .search-btn::before {
        content: "Search";
        width: auto;
        height: auto;
        display: inline;
        border: 0;
        border-radius: 0;
        font-size: 12px;
        font-weight: 800;
        line-height: 1;
        transform: none;
      }
      #detailsSearchWrap .search-btn::after {
        content: none;
      }
      #hotelTabs.hotel-tabs {
        display: grid;
        grid-template-columns: repeat(6, minmax(0, 1fr));
        align-items: center;
        justify-content: stretch;
        gap: 6px;
        padding: 7px 30px;
        overflow: visible;
        border-top: 1px solid #edf3f0;
        border-bottom: 1px solid #cbded7;
        background: rgba(255,255,255,.98);
        box-shadow: 0 6px 17px rgba(13,58,45,.08);
      }
      #hotelTabs.hotel-tabs a {
        min-width: 0;
        min-height: 40px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        padding: 0 7px;
        border: 1px solid transparent;
        border-radius: 9px;
        color: #405a51;
        background: transparent;
        font-size: 12.5px;
        font-weight: 700;
        line-height: 1.15;
        text-align: center;
      }
      #hotelTabs.hotel-tabs a:hover,
      #hotelTabs.hotel-tabs a:focus-visible {
        border-color: #d3e5de;
        color: #17664f;
        background: #f2f8f5;
      }
      #hotelTabs.hotel-tabs a.active {
        border-color: #b9d9cc;
        color: #155e49;
        background: #e7f4ef;
        box-shadow: inset 0 0 0 1px rgba(255,255,255,.72);
        font-weight: 800;
      }
      .hotel-details-page {
        padding-right: 30px;
        padding-left: 30px;
      }
      #overview {
        display: flex;
        flex-direction: column;
      }
      #overview .overview-grid {
        order: -2;
        display: block;
        width: 100%;
        margin: 0 0 16px;
      }
      #overview .photo-gallery {
        display: block;
      }
      #overview .photo-main {
        position: relative;
        overflow: hidden;
        border-radius: 14px;
        background: #e8efec;
        touch-action: pan-y;
      }
      #overview .photo-main img {
        width: 100%;
        height: clamp(320px, 50vw, 430px);
        display: block;
        border-radius: 14px;
        object-fit: cover;
        user-select: none;
        -webkit-user-drag: none;
      }
      #overview .photo-thumbs,
      #overview .overview-side-column {
        display: none !important;
      }
      #overview .mobile-gallery-status {
        position: absolute;
        right: 14px;
        bottom: 14px;
        display: flex;
        align-items: center;
        gap: 8px;
        padding: 8px 12px;
        border: 1px solid rgba(255,255,255,.34);
        border-radius: 999px;
        color: #fff;
        background: rgba(12,38,31,.78);
        backdrop-filter: blur(6px);
        pointer-events: none;
      }
      #overview .mobile-gallery-status small {
        font-size: 11px;
        font-weight: 650;
      }
      #overview .mobile-gallery-status b {
        font-size: 11px;
      }
      #overview .overview-info-grid {
        grid-template-columns: minmax(0, 1fr);
        gap: 16px;
        align-items: stretch;
      }
      #overview .overview-support-row {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 16px;
        align-items: stretch;
      }
      #overview #tabletOverviewMapSlot {
        display: block;
        min-width: 0;
      }
      #overview .overview-amenities-card,
      #overview #tabletOverviewMapSlot,
      #overview #tabletOverviewMapSlot .map-card,
      #overview #tabletOverviewMapSlot .map-preview {
        height: 100%;
        min-height: 180px;
        box-sizing: border-box;
      }
      #overview #tabletOverviewMapSlot .map-card {
        display: block;
      }
      #overview #tabletOverviewMapSlot #mapPreviewFrame {
        height: 100%;
        min-height: 180px;
      }
      #roomsContent .room-card {
        grid-template-columns: minmax(250px, .9fr) minmax(0, 1.1fr);
        grid-template-areas:
          "gallery content"
          "price price";
        gap: 18px 20px;
        align-items: start;
        padding: 16px;
        border-radius: 14px;
        box-shadow: 0 6px 18px rgba(18,61,48,.06);
      }
      #roomsContent .room-gallery {
        grid-area: gallery;
        align-self: stretch;
      }
      #roomsContent .room-main-photo img {
        height: 210px;
      }
      #roomsContent .room-thumb img {
        height: 98px;
      }
      #roomsContent .room-content {
        grid-area: content;
        padding: 2px 0 0;
      }
      #roomsContent .room-card h3 {
        margin-bottom: 8px;
        font-size: 19px;
      }
      #roomsContent .room-availability-badge {
        margin-bottom: 12px;
        font-size: 11px;
      }
      #roomsContent .room-meta {
        gap: 9px;
        margin: 0 0 9px;
        font-size: 12.5px;
        line-height: 1.45;
      }
      #roomsContent .room-inclusions {
        margin-top: 14px;
      }
      #roomsContent .room-inclusions h4 {
        margin-bottom: 9px;
        font-size: 14px;
      }
      #roomsContent .room-inclusions ul {
        gap: 7px;
      }
      #roomsContent .room-inclusions li {
        padding: 6px 9px;
        font-size: 11px;
      }
      #roomsContent .room-right {
        grid-area: price;
        display: grid;
        grid-template-columns: minmax(0, 1fr) auto;
        grid-template-areas:
          "label action"
          "amount action"
          "note action";
        align-items: center;
        column-gap: 24px;
        row-gap: 2px;
        padding-top: 14px;
        border-top: 1px solid #e1ebe7;
        text-align: left;
      }
      #roomsContent .room-right p {
        grid-area: label;
        font-size: 11px;
      }
      #roomsContent .room-right strong {
        grid-area: amount;
        margin: 0;
        font-size: 22px;
      }
      #roomsContent .room-right small {
        grid-area: note;
        font-size: 11px;
      }
      #roomsContent .room-book-btn {
        grid-area: action;
        width: 190px;
        max-width: 190px;
        min-height: 44px;
        margin: 0;
      }
    }
    @media (max-width: 768px) {
      #detailsSearchWrap {
        padding:7px 10px !important;
        transform:translateY(0);
        opacity:1;
        visibility:visible;
        transition:transform .22s ease,opacity .18s ease,visibility .18s ease;
      }
      #detailsSearchWrap.is-scroll-hidden{
        transform:translateY(calc(-100% - 2px));
        opacity:0;
        visibility:hidden;
        pointer-events:none;
      }
      #hotelTabs.hotel-tabs{
        justify-content:flex-start;
        gap:5px;
        padding:6px 10px 7px;
        border-top:1px solid #eef3f1;
        border-bottom:1px solid #c8ddd5;
        background:rgba(255,255,255,.98);
        box-shadow:0 5px 15px rgba(13,58,45,.07);
        scrollbar-width:none;
        overscroll-behavior-x:contain;
        transition:top .22s ease,box-shadow .2s ease;
      }
      #hotelTabs.hotel-tabs::-webkit-scrollbar{display:none;}
      #hotelTabs.hotel-tabs a{
        min-height:34px;
        display:inline-flex;
        align-items:center;
        justify-content:center;
        flex:0 0 auto;
        padding:8px 12px;
        border:1px solid transparent;
        border-radius:9px;
        color:#425c53;
        font-size:.68rem;
        font-weight:650;
        line-height:1;
      }
      #hotelTabs.hotel-tabs a:hover{border-color:#d4e4de;background:#f4f9f7;color:#1d6c55;}
      #hotelTabs.hotel-tabs a.active{
        border-color:#bddbce;
        background:#e7f4ef;
        color:#17664f;
        box-shadow:inset 0 0 0 1px rgba(255,255,255,.7);
        font-weight:800;
      }
      body.hotel-search-collapsed #hotelTabs.hotel-tabs{box-shadow:0 7px 18px rgba(13,58,45,.11);}
      #detailsSearchWrap .details-search-toggle{
        width:100%;
        min-height:56px;
        display:flex;
        align-items:center;
        gap:10px;
        padding:9px 11px;
        border:1px solid #cfe0da;
        border-radius:13px;
        background:#fff;
        color:#173d32;
        box-shadow:0 5px 16px rgba(18,61,48,.065);
        font:inherit;
        text-align:left;
        cursor:pointer;
      }
      #detailsSearchWrap .details-search-toggle-icon{
        width:34px;
        height:34px;
        display:grid;
        place-items:center;
        flex:0 0 34px;
        border-radius:9px;
        background:#e9f5f0;
        color:#24755e;
      }
      #detailsSearchWrap .details-search-toggle-icon svg{width:17px;height:17px}
      #detailsSearchWrap .details-search-toggle-copy{min-width:0;display:grid;gap:3px;flex:1}
      #detailsSearchWrap .details-search-toggle-copy strong{font-size:.72rem;font-weight:800;line-height:1.2}
      #detailsSearchWrap .details-search-toggle-copy small{overflow:hidden;color:#6b7f78;font-size:.58rem;line-height:1.25;text-overflow:ellipsis;white-space:nowrap}
      #detailsSearchWrap .details-search-toggle-chevron{width:17px;height:17px;flex:none;color:#547269;transition:transform .2s ease}
      #detailsSearchWrap.is-expanded .details-search-toggle-chevron{transform:rotate(180deg)}
      #detailsSearchWrap:not(.is-expanded) .search-container{display:none !important;}
      #detailsSearchWrap.is-expanded .search-container{display:grid !important;margin-top:8px !important;}
      #detailsSearchWrap .search-container {
        width: 100% !important;
        max-width: 100% !important;
        grid-template-columns: minmax(0, 1fr) !important;
        padding: 10px !important;
        gap: 8px !important;
        border: 1px solid #e1ebe7 !important;
        border-radius: 14px !important;
        box-shadow: 0 5px 16px rgba(18, 61, 48, .06) !important;
      }
      #detailsSearchWrap .search-box,
      #detailsSearchWrap .search-btn{width:100% !important;max-width:none !important;}
      #detailsSearchWrap .search-btn{height:40px !important;min-height:40px !important;flex:none !important;}
      #detailsSearchWrap .guest-box{right:0 !important;left:0 !important;width:100% !important;max-width:100% !important;}

      /* Flatpickr is appended to body, so mobile sizing must be global. */
      .flatpickr-calendar,
      .flatpickr-calendar.inline,
      .flatpickr-calendar.open{
        width:calc(100vw - 20px) !important;
        min-width:0 !important;
        max-width:350px !important;
        padding:8px !important;
        overflow:hidden !important;
      }
      .flatpickr-calendar .flatpickr-months,
      .flatpickr-calendar .flatpickr-innerContainer,
      .flatpickr-calendar .flatpickr-rContainer,
      .flatpickr-calendar .flatpickr-days{
        width:100% !important;
        min-width:0 !important;
        max-width:100% !important;
      }
      .flatpickr-calendar .flatpickr-months .flatpickr-month,
      .flatpickr-calendar .dayContainer,
      .flatpickr-calendar .flatpickr-weekdaycontainer{
        width:100% !important;
        min-width:100% !important;
        max-width:100% !important;
      }

      /* Mobile property overview: swipe gallery first, essential details second. */
      #overview{position:relative;display:flex;flex-direction:column;padding-top:8px;}
      #overview .overview-grid{order:-4;display:block;width:100%;margin:0 0 12px;}
      #overview .photo-gallery{display:block;}
      #overview .photo-main{
        position:relative;
        overflow:hidden;
        border-radius:13px;
        background:#e8efec;
        touch-action:pan-y;
      }
      #overview .photo-main img{
        width:100%;
        height:clamp(210px,65vw,275px);
        display:block;
        border-radius:13px;
        object-fit:cover;
        user-select:none;
        -webkit-user-drag:none;
      }
      #overview .photo-thumbs,#overview .overview-side-column{display:none !important;}
      .mobile-gallery-status{
        position:absolute;
        right:9px;
        bottom:9px;
        display:flex;
        align-items:center;
        gap:7px;
        padding:6px 9px;
        border:1px solid rgba(255,255,255,.3);
        border-radius:999px;
        background:rgba(12,38,31,.76);
        color:#fff;
        backdrop-filter:blur(5px);
        pointer-events:none;
      }
      .mobile-gallery-status small{font-size:.52rem;font-weight:650;opacity:.86;}
      .mobile-gallery-status b{font-size:.58rem;letter-spacing:.03em;}
      #overview .overview-top-row{order:-3;margin:0;padding:0 2px;gap:8px;}
      #overview .overview-property-tagline{display:none;}
      #overview .overview-title{font-size:1.24rem;line-height:1.15;letter-spacing:-.015em;}
      #overview .overview-subtitle{margin-top:5px;font-size:.75rem;letter-spacing:.02em;}
      #overview .overview-actions{width:100%;align-items:stretch;}
      #overview .overview-actions-top{width:100%;justify-content:space-between;flex-wrap:nowrap;gap:10px;}
      #overview .overview-price{margin-right:auto;font-size:.75rem;white-space:normal;}
      #overview .overview-price strong{font-size:.88rem;}
      #overview .overview-reserve-btn{min-height:38px;padding:8px 13px;border-radius:9px;font-size:.7rem;white-space:nowrap;}
      #overview .mobile-overview-share{
        width:38px;
        height:38px;
        display:inline-flex;
        flex:0 0 38px;
        border-color:#cfe0da;
        background:#f7fbf9;
        color:#1d6956;
        box-shadow:none;
      }
      #overview .overview-location-row{order:-2;margin:9px 2px 12px;flex-direction:row;align-items:center;justify-content:space-between;gap:10px;}
      #overview .overview-location-text{align-items:center;gap:8px;}
      #overview .overview-location-row p{font-size:.75rem;line-height:1.45;}
      #overview .location-pin{display:none;}
      #overview .mobile-map-trigger{
        width:30px;
        height:30px;
        display:inline-flex;
        align-items:center;
        justify-content:center;
        flex:0 0 30px;
        margin-top:0;
        padding:0;
        border:1px solid #cfe0da;
        border-radius:50%;
        background:#edf7f3;
        color:#1d765e;
        cursor:pointer;
      }
      #overview .mobile-map-trigger svg{width:15px;height:15px;fill:none;stroke:currentColor;stroke-width:1.9;stroke-linecap:round;stroke-linejoin:round;}
      #overview .overview-actions-bottom{display:none;}
      #overview .mobile-gallery-favorite{
        position:absolute;
        z-index:5;
        top:10px;
        right:10px;
        width:36px;
        height:36px;
        display:inline-flex;
        border-color:rgba(255,255,255,.78);
        box-shadow:0 5px 14px rgba(9,38,30,.2);
      }
      #overview #shareHotelBtn{display:none;}
      #overview .overview-info-grid{order:-1;display:grid;gap:10px;margin-top:0;}
      #overview .overview-description-card{
        display:block;
        padding:2px 3px 4px;
        border:0;
        border-radius:0;
        background:transparent;
      }
      #overview .overview-description-card::before{
        content:"About this property";
        display:block;
        margin-bottom:7px;
        color:#173826;
        font-size:.9rem;
        font-weight:750;
      }
      #overview .overview-description-card p{margin:0 0 10px;color:#52665f;font-size:.8125rem;line-height:1.65;}
      #overview .overview-description-card p:last-of-type{margin-bottom:0;}
      #overview .overview-description-points{
        display:block;
        margin:9px 0 0;
        padding-left:17px;
        color:#52665f;
        font-size:.8125rem;
        line-height:1.65;
      }
      #overview .overview-description-points li{margin-bottom:4px;}
      #overview .overview-amenities-card{padding:12px;border-radius:11px;}
      #overview .overview-amenities-card h3{margin-bottom:9px;font-size:.875rem;}
      #overview .overview-amenities-list{gap:6px;}
      #overview .overview-amenities-list span{padding:5px 8px;font-size:.6875rem;}
      .mobile-floating-reserve{
        position:fixed;
        z-index:1180;
        right:72px;
        bottom:max(19px,calc(env(safe-area-inset-bottom) + 3px));
        min-height:44px;
        display:inline-flex;
        align-items:center;
        justify-content:center;
        gap:7px;
        padding:0 16px;
        border:1px solid #176b54;
        border-radius:999px;
        background:linear-gradient(135deg,#238069,#17644f);
        color:#fff;
        box-shadow:0 10px 25px rgba(13,74,56,.27);
        font:800 .7rem/1 "Inter",sans-serif;
        opacity:0;
        visibility:hidden;
        pointer-events:none;
        transform:translateY(12px) scale(.96);
        transition:opacity .2s ease,visibility .2s ease,transform .22s ease;
      }
      .mobile-floating-reserve svg{width:16px;height:16px;fill:none;stroke:currentColor;stroke-width:1.9;stroke-linecap:round;stroke-linejoin:round;}
      .mobile-floating-reserve.is-visible{opacity:1;visibility:visible;pointer-events:auto;transform:translateY(0) scale(1);}
      body.hotel-floating-reserve-visible .back-to-top{
        right:14px;
        bottom:max(16px,env(safe-area-inset-bottom));
      }
      body.no-scroll .mobile-floating-reserve,
      body.no-scroll .back-to-top{opacity:0;visibility:hidden;pointer-events:none;}
    }

    /* ========== NEW REVIEWS SECTION STYLES ========== */

.reviews-section-header {
  margin-bottom: 20px;
}

.reviews-section-header h2 {
  margin: 0 0 8px;
  font-size: 1.25rem;
  font-weight: 700;
  color: #173826;
}

.reviews-verified-badge {
  margin: 0;
  font-size: 0.875rem;
  color: #4b5563;
  font-weight: 500;
}

.reviews-tabs {
  display: flex;
  gap: 12px;
  margin-bottom: 24px;
  border-bottom: 2px solid #e5ece8;
  padding-bottom: 0;
}

.reviews-tab {
  padding: 12px 0;
  border: none;
  background: transparent;
  color: #4b5563;
  font-size: 0.875rem;
  font-weight: 600;
  cursor: pointer;
  border-bottom: 2px solid transparent;
  transition: all 0.2s ease;
  margin-bottom: -2px;
}

.reviews-tab.active {
  color: #2B7066;
  border-bottom-color: #2B7066;
}

.reviews-main-container {
  display: grid;
  grid-template-columns: 280px 1fr;
  gap: 32px;
  margin-top: 24px;
}

/* Left Column: Ratings */
.reviews-left-column {
  display: flex;
  flex-direction: column;
  gap: 24px;
}

.reviews-rating-card {
  background: linear-gradient(135deg, #f0f9f6 0%, #ecf7f2 100%);
  border: 1px solid #d3e2db;
  border-radius: 12px;
  padding: 20px;
  text-align: center;
}

.rating-badge {
  width: 80px;
  height: 80px;
  background: linear-gradient(135deg, #2B7066 0%, #1a4d3f 100%);
  border-radius: 12px;
  display: flex;
  align-items: center;
  justify-content: center;
  margin: 0 auto 16px;
  box-shadow: 0 6px 16px rgba(43, 122, 102, 0.25);
}

.rating-badge strong {
  font-size: 2rem;
  color: #ffffff;
  font-weight: 800;
}

.rating-label {
  margin: 0 0 4px;
  font-size: 1rem;
  font-weight: 700;
  color: #173826;
}

.rating-count {
  margin: 0;
  font-size: 0.875rem;
  color: #4b5563;
}

.rating-categories {
  display: flex;
  flex-direction: column;
  gap: 16px;
}

.rating-item {
  display: flex;
  flex-direction: column;
  gap: 6px;
}

.category-label {
  font-size: 0.875rem;
  font-weight: 600;
  color: #173826;
}

.rating-bar {
  height: 4px;
  background: #e5ece8;
  border-radius: 999px;
  overflow: hidden;
}

.rating-fill {
  height: 100%;
  background: linear-gradient(90deg, #2B7066 0%, #3a9a7f 100%);
  border-radius: 999px;
  transition: width 0.3s ease;
}

.rating-value {
  font-size: 0.875rem;
  font-weight: 700;
  color: #2B7066;
}

/* Right Column: Summary & Reviews */
.reviews-right-column {
  display: flex;
  flex-direction: column;
  gap: 28px;
}

.review-summary-card {
  background: linear-gradient(135deg, #f0f9f6 0%, #ecf7f2 100%);
  border: 1px solid #d3e2db;
  border-radius: 12px;
  padding: 20px;
}

.summary-header {
  display: flex;
  align-items: center;
  gap: 12px;
  margin-bottom: 8px;
}

.summary-icon {
  color: #2B7066;
  flex-shrink: 0;
}

.summary-header h3 {
  margin: 0;
  font-size: 1rem;
  font-weight: 700;
  color: #173826;
}

.summary-source {
  margin: 0 0 16px;
  font-size: 0.75rem;
  font-weight: 600;
  color: #4b5563;
  text-transform: uppercase;
  letter-spacing: 0.5px;
}

.summary-content {
  display: flex;
  flex-direction: column;
  gap: 16px;
  margin-bottom: 20px;
  padding-bottom: 20px;
  border-bottom: 1px solid #d3e2db;
}

.summary-text {
  display: grid;
  grid-template-columns: 26px minmax(0, 1fr);
  align-items: start;
  column-gap: 10px;
  font-size: 0.9375rem;
  line-height: 1.6;
  color: #1f2b35;
}

.summary-text.positive {
  color: #0f5f3d;
}

.summary-text.negative {
  color: #78350f;
}

.summary-check {
  flex-shrink: 0;
  color: #22c55e;
  width: 26px;
  height: 26px;
  margin-top: 0;
  align-self: start;
}

.summary-x {
  flex-shrink: 0;
  color: #ef4444;
  width: 26px;
  height: 26px;
  margin-top: 0;
  align-self: start;
}

.summary-text p {
  margin: 0;
  padding-top: 2px;
}

.summary-helpful {
  text-align: center;
}

.summary-helpful p {
  margin: 0 0 12px;
  font-size: 0.875rem;
  font-weight: 500;
  color: #173826;
}

.helpful-buttons {
  display: flex;
  gap: 10px;
  justify-content: center;
}

.helpful-btn {
  padding: 6px 16px;
  border: 1px solid #d3e2db;
  background: #ffffff;
  border-radius: 6px;
  font-size: 0.875rem;
  font-weight: 600;
  color: #173826;
  cursor: pointer;
  transition: all 0.2s ease;
}

.helpful-btn:hover {
  border-color: #2B7066;
  color: #2B7066;
  background: #f0f9f6;
}

.helpful-btn.no {
  color: #4b5563;
}

.helpful-btn.no:hover {
  color: #dc3545;
  border-color: #dc3545;
  background: #fff5f5;
}

/* Filters */
.review-filters {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 12px;
}

.filter-group {
  display: flex;
  flex-direction: column;
  gap: 6px;
}

.filter-group label {
  font-size: 0.75rem;
  font-weight: 600;
  color: #4b5563;
  text-transform: uppercase;
  letter-spacing: 0.5px;
}

.filter-select {
  padding: 10px 12px;
  border: 1px solid #d3e2db;
  border-radius: 8px;
  background: #ffffff;
  font-size: 0.875rem;
  color: #173826;
  cursor: pointer;
  font-weight: 500;
}

.filter-select:hover,
.filter-select:focus {
  border-color: #2B7066;
  outline: none;
}

/* Review Mentions/Tags */
.review-mentions {
  border-top: 1px solid #e5ece8;
  padding-top: 20px;
}

.mentions-label {
  margin: 0 0 12px;
  font-size: 0.875rem;
  font-weight: 600;
  color: #173826;
}

.mentions-buttons {
  display: flex;
  flex-wrap: wrap;
  gap: 10px;
}

.mention-btn {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 8px 14px;
  border: 1px solid #d3e2db;
  background: #ffffff;
  border-radius: 999px;
  font-size: 0.8125rem;
  font-weight: 500;
  color: #173826;
  cursor: pointer;
  transition: all 0.2s ease;
  white-space: nowrap;
}

.mention-btn:hover {
  border-color: #2B7066;
  background: #f0f9f6;
  color: #2B7066;
}

.mention-btn.with-icon svg {
  width: 16px;
  height: 16px;
  flex-shrink: 0;
  color: currentColor;
}

.mention-btn.more {
  background: #f0f9f6;
  border-color: #2B7066;
  color: #2B7066;
}

/* Individual Reviews */
.reviews-carousel {
  margin-top: 24px;
  border-top: 1px solid #e5ece8;
  padding-top: 24px;
}

.review-card.individual {
  min-height: 220px;
  display: flex;
  flex-direction: column;
  gap: 12px;
}

.review-card.individual .review-card-head {
  flex-direction: column;
  align-items: flex-start;
  margin-bottom: 12px;
}

.review-card.individual .review-user {
  width: 100%;
}

.review-card.individual .review-user strong {
  font-size: 0.875rem;
}

.review-card.individual .review-user p {
  margin: 2px 0 0;
  font-size: 0.75rem;
  color: #4b5563;
  font-weight: 500;
}

.review-owner-response {
  margin: 2px 0 0;
  padding: 11px 12px;
  border-left: 3px solid #2b7a66;
  border-radius: 0 9px 9px 0;
  background: #f1f8f5;
  color: #36554b;
  font-size: 0.78rem;
  line-height: 1.55;
}

.review-owner-response strong {
  display: block;
  margin-bottom: 3px;
  color: #1d654f;
  font-size: 0.68rem;
  letter-spacing: .04em;
  text-transform: uppercase;
}

.review-helpful-footer {
  display: flex;
  align-items: center;
  gap: 10px;
  margin-top: auto;
  padding-top: 12px;
  border-top: 1px solid #e5ece8;
  font-size: 0.75rem;
  font-weight: 600;
  color: #4b5563;
}

.helpful-btn-sm {
  padding: 4px 10px;
  border: 1px solid #d3e2db;
  background: transparent;
  border-radius: 4px;
  font-size: 0.75rem;
  font-weight: 600;
  color: #173826;
  cursor: pointer;
  transition: all 0.2s ease;
}

.helpful-btn-sm:hover {
  border-color: #2B7066;
  color: #2B7066;
  background: #f0f9f6;
}

/* Responsive */
@media (max-width: 1024px) {
  .reviews-main-container {
    grid-template-columns: 1fr;
  }

  .review-filters {
    grid-template-columns: repeat(2, 1fr);
  }
}

@media (max-width: 768px) {
  .reviews-main-container {
    gap: 20px;
  }

  .review-filters {
    grid-template-columns: 1fr;
  }

  .mentions-buttons {
    gap: 8px;
  }

  .mention-btn {
    padding: 6px 12px;
    font-size: 0.75rem;
  }
}

.rooms-prompt {
  border: 1px dashed #bfd0ca;
  border-radius: 12px;
}

.room-book-btn:disabled,
.room-book-btn[disabled] {
  opacity: 0.55;
  background: #9ca3af;
  border-color: #9ca3af;
  cursor: not-allowed;
}

.room-book-btn:disabled:hover,
.room-book-btn[disabled]:hover {
  background: #9ca3af;
  border-color: #9ca3af;
  transform: none;
}


.gemini-powered {
  display: flex;
  align-items: center;
  gap: 8px;
  font-size: 13px;
  color: #444;
}

.gemini-logo {
  display: block;
  width: 22px;
  height: 22px;
  flex-shrink: 0;
}

.summary-text svg {
  width: 26px;
  height: 26px;
  margin-right: 0;
  vertical-align: middle;
}

/* One predictable typography scale for hotel details on phones. */
@media (max-width: 768px) {
  .hotel-details-page {
    --hotel-mobile-section-title: 1.125rem;
    --hotel-mobile-card-title: .875rem;
    --hotel-mobile-body: .8125rem;
    --hotel-mobile-caption: .6875rem;
  }

  .hotel-details-page .detail-section h2,
  .hotel-details-page .reviews-section-header h2 {
    font-size: var(--hotel-mobile-section-title) !important;
    line-height: 1.25 !important;
  }

  .hotel-details-page #overview .overview-title {
    font-size: 1.25rem !important;
    line-height: 1.2 !important;
  }

  .hotel-details-page #overview .overview-description-card::before,
  .hotel-details-page #overview .overview-amenities-card h3,
  .hotel-details-page .property-guest-info-grid h3,
  .hotel-details-page #roomsContent .room-card h3,
  .hotel-details-page #roomsContent .room-inclusions h4,
  .hotel-details-page .summary-header h3,
  .hotel-details-page .rating-label,
  .hotel-details-page .review-user strong {
    font-size: var(--hotel-mobile-card-title) !important;
    line-height: 1.35 !important;
  }

  .hotel-details-page #overview .overview-description-card p,
  .hotel-details-page #overview .overview-description-points,
  .hotel-details-page .rooms-prompt p,
  .hotel-details-page #roomsContent .room-meta,
  .hotel-details-page #roomsContent .room-inclusions li,
  .hotel-details-page .rules-list,
  .hotel-details-page .property-guest-info-grid p,
  .hotel-details-page .property-guest-info-grid ul,
  .hotel-details-page .summary-text,
  .hotel-details-page .review-message,
  .hotel-details-page .review-owner-response {
    font-size: var(--hotel-mobile-body) !important;
    line-height: 1.6 !important;
  }

  .hotel-details-page #overview .overview-subtitle,
  .hotel-details-page #overview .overview-price,
  .hotel-details-page #overview .overview-location-row p,
  .hotel-details-page .reviews-verified-badge,
  .hotel-details-page .rating-count,
  .hotel-details-page .category-label,
  .hotel-details-page .rating-value {
    font-size: .75rem !important;
    line-height: 1.5 !important;
  }

  .hotel-details-page #overview .overview-amenities-list span,
  .hotel-details-page .property-guest-info-grid article > span,
  .hotel-details-page .summary-source,
  .hotel-details-page .review-user p,
  .hotel-details-page .review-owner-response strong {
    font-size: var(--hotel-mobile-caption) !important;
    line-height: 1.4 !important;
  }

  .hotel-details-page .pill,
  .hotel-details-page .rooms-prompt button,
  .hotel-details-page .room-book-btn,
  .hotel-details-page .helpful-btn,
  .hotel-details-page .mention-btn {
    font-size: .75rem !important;
  }
}
  </style>
</head>
<body>
  <div id="header"></div>

  <section class="details-search-wrap" id="detailsSearchWrap">
    <button class="details-search-toggle" id="detailsSearchToggle" type="button" aria-expanded="false" aria-controls="hotelDetailsSearchForm">
      <span class="details-search-toggle-icon" aria-hidden="true">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7"></circle><path d="m20 20-3.5-3.5"></path></svg>
      </span>
      <span class="details-search-toggle-copy">
        <strong>Search available rooms</strong>
        <small id="detailsSearchSummary"><?= htmlspecialchars((string)$hotel['island']) ?> &middot; Select dates &middot; Guests &amp; Rooms</small>
      </span>
      <svg class="details-search-toggle-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="m6 9 6 6 6-6"></path></svg>
    </button>
    <div class="search-container" id="hotelDetailsSearchForm">
      <div class="search-box location-search-box">
        <label>Where to go?</label>
        <div class="location-input-wrap">
          <select id="island">
            <option value="" selected disabled>--select a location--</option>
            <option value="Apuao">Apuao</option>
            <option value="Malasugui">Malasugui</option>
            <option value="Quinapaguian">Quinapaguian</option>
            <option value="Cayucyucan">Cayucyucan</option>
            <option value="Caringo">Caringo</option>
            <option value="Canimog">Canimog</option>
          </select>
        </div>
      </div>
      <div class="search-box">
        <label>Check-in / Check-out</label>
        <div class="date-input-wrap">
          <input type="text" id="dateRangePicker" placeholder="Select stay dates" readonly inputmode="none" autocomplete="off" aria-haspopup="dialog" />
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
          <div id="childAgeRows" class="child-age-list" aria-live="polite"></div>
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
      <a href="#guest-info">Guest Info</a>
      <a href="#reviews">Reviews</a>
    </nav>

    <section id="overview" class="detail-section">
      <div class="overview-top-row">
        <div class="overview-title-wrap">
          <h1 class="overview-title"><?= htmlspecialchars($hotel['name']) ?></h1>
          <?php if (trim((string)($hotel['owner_content']['tagline'] ?? '')) !== ''): ?>
            <p class="overview-property-tagline"><?= htmlspecialchars((string)$hotel['owner_content']['tagline']) ?></p>
          <?php endif; ?>
          <p class="overview-subtitle"><?= htmlspecialchars($hotel['island']) ?> • <?= htmlspecialchars(strtoupper($hotel['type'])) ?></p>
        </div>
        <div class="overview-actions">
          <div class="overview-actions-top">
            <p class="overview-price">As low as <strong>₱<?= number_format($displayBasePrice, 0) ?></strong>/night</p>
            <button type="button" class="icon-action-btn mobile-overview-share" id="mobileShareHotelBtn" aria-label="Share this hotel" title="Share this hotel">
              <svg viewBox="0 0 24 24" aria-hidden="true">
                <circle cx="18" cy="5" r="2.3"></circle>
                <circle cx="6" cy="12" r="2.3"></circle>
                <circle cx="18" cy="19" r="2.3"></circle>
                <path d="M8.1 11.1 15.8 6.9M8.1 12.9l7.7 4.2"></path>
              </svg>
            </button>
            <button type="button" class="overview-reserve-btn" id="reserveOverviewBtn">Reserve Room</button>
          </div>
        </div>
      </div>
      <div class="overview-location-row">
        <div class="overview-location-text">
          <button type="button" class="mobile-map-trigger" id="mobileMapBtn" aria-label="Show hotel on map" title="Show on map">
            <svg viewBox="0 0 24 24" aria-hidden="true">
              <path d="M20 10c0 5-8 11-8 11S4 15 4 10a8 8 0 1 1 16 0Z"></path>
              <circle cx="12" cy="10" r="2.5"></circle>
            </svg>
          </button>
          <span class="location-pin" aria-hidden="true"></span>
          <p><?= htmlspecialchars($mapAddress) ?></p>
        </div>
        <div class="overview-actions-bottom">
          <button
            type="button"
            class="icon-action-btn favorite-toggle<?= $hotelIsFavorite ? ' is-favorite' : '' ?>"
            data-favorite-type="hotel"
            data-favorite-id="<?= (int)$hotel['id'] ?>"
            aria-label="<?= $hotelIsFavorite ? 'Remove from favorites' : 'Add to favorites' ?>"
            aria-pressed="<?= $hotelIsFavorite ? 'true' : 'false' ?>"
            title="<?= $hotelIsFavorite ? 'Remove from favorites' : 'Add to favorites' ?>"
          >
            <svg viewBox="0 0 24 24" aria-hidden="true">
              <path d="M12 20.4c-5.2-3.4-8.4-6.2-8.4-10a4.8 4.8 0 0 1 8.4-3.1 4.8 4.8 0 0 1 8.4 3.1c0 3.8-3.2 6.6-8.4 10Z"></path>
            </svg>
          </button>
          <button type="button" class="icon-action-btn" id="shareHotelBtn" aria-label="Share this hotel" title="Share this hotel">
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
            <button
              type="button"
              class="favorite-toggle mobile-gallery-favorite<?= $hotelIsFavorite ? ' is-favorite' : '' ?>"
              data-favorite-type="hotel"
              data-favorite-id="<?= (int)$hotel['id'] ?>"
              aria-label="<?= $hotelIsFavorite ? 'Remove from favorites' : 'Add to favorites' ?>"
              aria-pressed="<?= $hotelIsFavorite ? 'true' : 'false' ?>"
              title="<?= $hotelIsFavorite ? 'Remove from favorites' : 'Add to favorites' ?>"
            >
              <svg viewBox="0 0 24 24" aria-hidden="true">
                <path d="M12 20.4c-5.2-3.4-8.4-6.2-8.4-10a4.8 4.8 0 0 1 8.4-3.1 4.8 4.8 0 0 1 8.4 3.1c0 3.8-3.2 6.6-8.4 10Z"></path>
              </svg>
            </button>
            <span class="mobile-gallery-status" aria-live="polite">
              <small>Swipe photos</small>
              <b id="mobileGalleryCounter">1 / <?= max(1, count($hotel['gallery_images'])) ?></b>
            </span>
          </div>
          <div class="photo-thumbs" id="photoThumbs"></div>
        </div>
        <aside class="overview-side-column">
          <div class="overview-side-photo">
            <img id="sidePhoto" alt="<?= htmlspecialchars($hotel['name']) ?> side view" />
          </div>
          <div id="desktopOverviewMapSlot"></div>
          <div class="map-card" id="overviewMapCard">
            <div class="map-preview">
              <div id="mapPreviewFrame" class="registered-map-preview-canvas" aria-label="Nearby registered hotels map preview"></div>
              <div class="registered-map-preview-shade"></div>
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
            <?php $ownerHighlights = is_array($hotel['owner_content']['highlights'] ?? null) ? $hotel['owner_content']['highlights'] : []; ?>
            <?php if ($ownerHighlights): ?>
              <ul class="overview-description-points">
                <?php foreach ($ownerHighlights as $highlight): ?>
                  <li><?= htmlspecialchars((string)$highlight) ?></li>
                <?php endforeach; ?>
              </ul>
            <?php endif; ?>
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
        <div class="overview-support-row">
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
          <div id="tabletOverviewMapSlot"></div>
        </div>
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

    <section id="guest-info" class="detail-section property-guest-info-section">
      <h2>Guest Information</h2>
      <?php
        $ownerInfo = $hotel['owner_content'];
        $publicExternalUrl = static function ($value): string {
          $url = trim((string)$value);
          if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) return '';
          $scheme = strtolower((string)(parse_url($url, PHP_URL_SCHEME) ?? ''));
          return in_array($scheme, ['https', 'http'], true) ? $url : '';
        };
        $publicWebsiteUrl = $publicExternalUrl($ownerInfo['website_url'] ?? '');
        $publicFacebookUrl = $publicExternalUrl($ownerInfo['facebook_url'] ?? '');
        $publicCheckin = trim((string)($ownerInfo['checkin_time'] ?? '14:00'));
        $publicCheckout = trim((string)($ownerInfo['checkout_time'] ?? '12:00'));
        $publicMinimumStay = max(1, (int)($ownerInfo['minimum_stay'] ?? 1));
        $publicAttractions = is_array($ownerInfo['nearby_attractions'] ?? null) ? $ownerInfo['nearby_attractions'] : [];
      ?>
      <div class="property-guest-info-grid">
        <article>
          <span>ARRIVAL</span>
          <h3>Check-in and check-out</h3>
          <p><strong>Check-in:</strong> <?= htmlspecialchars(date('g:i A', strtotime($publicCheckin))) ?></p>
          <p><strong>Check-out:</strong> <?= htmlspecialchars(date('g:i A', strtotime($publicCheckout))) ?></p>
          <p><strong>Minimum stay:</strong> <?= $publicMinimumStay ?> night<?= $publicMinimumStay === 1 ? '' : 's' ?></p>
        </article>
        <article>
          <span>BOOKING POLICY</span>
          <h3>Cancellation</h3>
          <p><?= htmlspecialchars(trim((string)($ownerInfo['cancellation_policy'] ?? '')) ?: 'Please contact the property for cancellation and refund conditions.') ?></p>
        </article>
        <article>
          <span>FAMILIES AND PETS</span>
          <h3>Stay conditions</h3>
          <p><strong>Children:</strong> <?= htmlspecialchars(trim((string)($ownerInfo['child_policy'] ?? '')) ?: 'Contact the property for child stay conditions.') ?></p>
          <p><strong>Pets:</strong> <?= htmlspecialchars(trim((string)($ownerInfo['pet_policy'] ?? '')) ?: 'Contact the property for pet conditions.') ?></p>
        </article>
        <article>
          <span>GETTING THERE</span>
          <h3>Transport and parking</h3>
          <p><?= htmlspecialchars(trim((string)($ownerInfo['transport_info'] ?? '')) ?: 'Contact the property for transportation guidance.') ?></p>
          <?php if (trim((string)($ownerInfo['parking_info'] ?? '')) !== ''): ?><p><strong>Parking:</strong> <?= htmlspecialchars((string)$ownerInfo['parking_info']) ?></p><?php endif; ?>
        </article>
        <?php if (trim((string)($ownerInfo['accessibility_info'] ?? '')) !== ''): ?>
          <article>
            <span>ACCESSIBILITY</span>
            <h3>Accessible stay information</h3>
            <p><?= htmlspecialchars((string)$ownerInfo['accessibility_info']) ?></p>
          </article>
        <?php endif; ?>
        <?php if ($publicAttractions): ?>
          <article>
            <span>EXPLORE NEARBY</span>
            <h3>Nearby attractions</h3>
            <ul><?php foreach ($publicAttractions as $attraction): ?><li><?= htmlspecialchars((string)$attraction) ?></li><?php endforeach; ?></ul>
          </article>
        <?php endif; ?>
      </div>
      <?php if (trim((string)($ownerInfo['contact_phone'] ?? '')) !== '' || trim((string)($ownerInfo['contact_email'] ?? '')) !== '' || $publicWebsiteUrl !== '' || $publicFacebookUrl !== ''): ?>
        <div class="property-contact-strip">
          <div><span>Need help before booking?</span><strong>Contact <?= htmlspecialchars($hotel['name']) ?></strong></div>
          <div>
            <?php if (trim((string)($ownerInfo['contact_phone'] ?? '')) !== ''): ?><a href="tel:<?= htmlspecialchars((string)$ownerInfo['contact_phone']) ?>"><?= htmlspecialchars((string)$ownerInfo['contact_phone']) ?></a><?php endif; ?>
            <?php if (trim((string)($ownerInfo['contact_email'] ?? '')) !== ''): ?><a href="mailto:<?= htmlspecialchars((string)$ownerInfo['contact_email']) ?>">Send email</a><?php endif; ?>
            <?php if ($publicWebsiteUrl !== ''): ?><a href="<?= htmlspecialchars($publicWebsiteUrl, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener">Website</a><?php endif; ?>
            <?php if ($publicFacebookUrl !== ''): ?><a href="<?= htmlspecialchars($publicFacebookUrl, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener">Facebook</a><?php endif; ?>
          </div>
        </div>
      <?php endif; ?>
    </section>

    <section id="reviews" class="detail-section">
      <div class="reviews-section-header">
        <h2>Reviews of <?= htmlspecialchars($hotel['name']) ?> from real guests</h2>
        <p class="reviews-verified-badge">Provided by verified guests</p>
      </div>

      <!-- Review Sources Tabs -->
      <div class="reviews-tabs">
        <button class="reviews-tab active" data-source="all">ALL SOURCES (<?= number_format((int)$hotel['total_reviews']) ?>)</button>
      </div>

      <!-- Main Reviews Container -->
      <div class="reviews-main-container">
        <!-- Left: Overall Rating & Stats -->
        <div class="reviews-left-column">
          <div class="reviews-rating-card">
            <div class="rating-badge">
              <strong><?= number_format((float)$hotel['rating'], 1) ?></strong>
            </div>
            <p class="rating-label">
              <?php
                $ratingValue = (float)$hotel['rating']; // assumed 0–5 scale

                if ($ratingValue >= 4.5) echo 'Exceptional';
                else if ($ratingValue >= 4.0) echo 'Excellent';
                else if ($ratingValue >= 3.5) echo 'Very Good';
                else if ($ratingValue >= 3.0) echo 'Good';
                else if ($ratingValue > 0) echo 'Fair';
                else echo 'No Rating Yet';
              ?>
            </p>
            <p class="rating-count">From <?= number_format((int)$hotel['total_reviews']) ?> reviews</p>
          </div>

<!-- Category Ratings -->
<div class="rating-categories">

  <div class="rating-item">
    <span class="category-label">Location</span>
    <div class="rating-bar">
      <div class="rating-fill" style="width: <?= ($ratings['location'] / 5) * 100 ?>%"></div>
    </div>
    <span class="rating-value"><?= number_format($ratings['location'], 1) ?></span>
  </div>

  <div class="rating-item">
    <span class="category-label">Service</span>
    <div class="rating-bar">
      <div class="rating-fill" style="width: <?= ($ratings['service'] / 5) * 100 ?>%"></div>
    </div>
    <span class="rating-value"><?= number_format($ratings['service'], 1) ?></span>
  </div>

  <div class="rating-item">
    <span class="category-label">Value for money</span>
    <div class="rating-bar">
      <div class="rating-fill" style="width: <?= ($ratings['value_for_money'] / 5) * 100 ?>%"></div>
    </div>
    <span class="rating-value"><?= number_format($ratings['value_for_money'], 1) ?></span>
  </div>

  <div class="rating-item">
    <span class="category-label">Cleanliness</span>
    <div class="rating-bar">
      <div class="rating-fill" style="width: <?= ($ratings['cleanliness'] / 5) * 100 ?>%"></div>
    </div>
    <span class="rating-value"><?= number_format($ratings['cleanliness'], 1) ?></span>
  </div>

  <div class="rating-item">
    <span class="category-label">Facilities</span>
    <div class="rating-bar">
      <div class="rating-fill" style="width: <?= ($ratings['facilities'] / 5) * 100 ?>%"></div>
    </div>
    <span class="rating-value"><?= number_format($ratings['facilities'], 1) ?></span>
  </div>

  <div class="rating-item">
    <span class="category-label">Room comfort and quality</span>
    <div class="rating-bar">
      <div class="rating-fill" style="width: <?= ($ratings['room_comfort'] / 5) * 100 ?>%"></div>
    </div>
    <span class="rating-value"><?= number_format($ratings['room_comfort'], 1) ?></span>
  </div>

</div>

</div> <!-- ✅ CLOSE reviews-left-column -->

<!-- RIGHT COLUMN STARTS PROPERLY -->
<div class="reviews-right-column">

<!-- AI SUMMARY -->
<div class="review-summary-card">

  <div class="summary-header">
    <svg class="summary-icon" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
      <path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/>
    </svg>
    <h3>Review Summary</h3>
  </div>

  <!-- ✅ FIXED GEMINI BADGE (reliable logo) -->
<div class="gemini-powered" style="margin-top: -10px;">
  <img 
    src="img/geminilogo.png"
    alt="Gemini AI"
    width="20"
    height="20"
    style="vertical-align: middle; margin-left: 30px;"
  >
  <span style="font-size: 12px; !important">Powered by Gemini AI</span>
</div>

  <div class="summary-content">
    <br>

 <div class="summary-text positive">
  <svg class="summary-check" viewBox="0 0 24 24" fill="none" stroke="#34A853" stroke-width="2">
    <polyline points="20 6 9 17 4 12"></polyline>
  </svg>

  <p style="display:inline;">
    <?= htmlspecialchars($aiSummary['positive'] ?? 'No AI summary available yet.') ?>
  </p>
</div>

   <div class="summary-text negative">
  <svg class="summary-x" viewBox="0 0 24 24" fill="none" stroke="#EA4335" stroke-width="2">
    <circle cx="12" cy="12" r="10"></circle>
    <line x1="15" y1="9" x2="9" y2="15"></line>
    <line x1="9" y1="9" x2="15" y2="15"></line>
  </svg>

  <p style="display:inline;">
    <?= htmlspecialchars($aiSummary['negative'] ?? 'No issues detected yet.') ?>
  </p>
</div>

  </div>
</div>

<!-- FILTERS (UNCHANGED) -->
<div class="review-filters">
  <div class="filter-group">
    <label>Guest Type</label>
    <select class="filter-select">
      <option>All guests (<?= number_format((int)$hotel['total_reviews']) ?>)</option>
    </select>
  </div>

  <div class="filter-group">
    <label>Room Type</label>
    <select class="filter-select">
      <option>All room types</option>
    </select>
  </div>

  <div class="filter-group">
    <label>Language</label>
    <select class="filter-select">
      <option>All languages</option>
    </select>
  </div>
</div>

<!-- TAGS -->
<div class="review-mentions">
  <p class="mentions-label">Show reviews that mention</p>
  <div class="mentions-buttons">
    <button class="mention-btn">All Reviews</button>
  </div>
</div>

<!-- INDIVIDUAL REVIEWS -->
<div class="reviews-carousel <?= count($reviews) <= 2 ? 'is-static' : '' ?>">

  <button type="button" class="reviews-nav prev" id="reviewsPrev">&#8249;</button>

  <div class="reviews-track" id="reviewsTrack">

    <?php if (!empty($reviews)): ?>
      <?php foreach ($reviews as $review): ?>
        <?php
          $name = trim($review['reviewer_name'] ?? '') ?: 'Guest';
          $rating = (float)($review['rating'] ?? 0);

          $profileImage = resolveReviewProfileImage(
            $review['profile_picture'] ?? '',
            (string)($review['google_id'] ?? '')
          );
          $dateText = !empty($review['created_at'])
            ? (new DateTime($review['created_at']))->format('d M Y')
            : 'Recently';
        ?>

        <article class="review-card individual">

          <header class="review-card-head">
            <div class="review-user">
              <span class="review-avatar">
                <?php if ($profileImage !== ''): ?>
                  <img src="<?= htmlspecialchars($profileImage) ?>" alt="<?= htmlspecialchars($name) ?> profile" loading="lazy" decoding="async" referrerpolicy="no-referrer">
                <?php else: ?>
                  <?= htmlspecialchars(strtoupper(substr($name, 0, 1))) ?>
                <?php endif; ?>
              </span>

              <div>
                <strong><?= htmlspecialchars($name) ?></strong>
                <p class="review-date"><?= htmlspecialchars($dateText) ?></p>
              </div>
            </div>
          </header>

          <p class="review-rating-line">
            <span class="review-stars">
              <?php for ($i = 1; $i <= 5; $i++): ?>
                <span class="star <?= reviewStarClass($i, $rating) ?>">★</span>
              <?php endfor; ?>
            </span>

            <span><?= number_format($rating, 1) ?>/5</span>
          </p>

          <p class="review-message">
            <?= htmlspecialchars($review['review_message'] ?? '') ?>
          </p>

          <?php if (trim((string)($review['owner_reply'] ?? '')) !== ''): ?>
            <div class="review-owner-response"><strong>Response from the property</strong><?= nl2br(htmlspecialchars((string)$review['owner_reply'])) ?></div>
          <?php endif; ?>

        </article>

      <?php endforeach; ?>
    <?php else: ?>
      <p class="dummy-text">No reviews yet.</p>
    <?php endif; ?>

  </div>

  <button type="button" class="reviews-nav next" id="reviewsNext">&#8250;</button>

</div>

</div> <!-- END RIGHT COLUMN -->
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

  <div id="shareModal" class="modal-backdrop share-modal" role="dialog" aria-modal="true" aria-labelledby="shareModalTitle" aria-hidden="true">
    <div class="share-modal-card">
      <button type="button" class="share-modal-close" id="closeShareModal" aria-label="Close share options">&times;</button>
      <div class="share-property-summary">
        <img src="<?= htmlspecialchars((string)($hotel['img'] ?? 'img/sampleimage.png'), ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars($hotel['name']) ?>">
        <div><span>Share this stay</span><h3 id="shareModalTitle"><?= htmlspecialchars($hotel['name']) ?></h3><p><?= htmlspecialchars($hotel['island']) ?> &bull; <?= htmlspecialchars(ucfirst((string)$hotel['type'])) ?> &bull; From &#8369;<?= number_format($displayBasePrice, 0) ?>/night</p></div>
      </div>
      <label class="share-link-field" for="shareHotelLink"><span>Direct link</span><input id="shareHotelLink" type="text" readonly></label>
      <div class="share-modal-actions"><button type="button" class="share-copy-btn" id="copyHotelLink">Copy link</button><button type="button" class="share-native-btn" id="nativeShareHotel">Share&hellip;</button></div>
      <p class="share-modal-status" id="shareModalStatus" role="status" aria-live="polite"></p>
    </div>
  </div>

  <div id="mapModal" class="modal-backdrop registered-map-modal">
    <div class="registered-map-dialog">
      <div class="registered-map-topbar">
        <div>
          <span>Explore nearby stays</span>
          <h3>Registered hotels and resorts</h3>
        </div>
        <button type="button" class="registered-map-close" id="closeMapModal" aria-label="Close map">
          <span>Close map</span><b aria-hidden="true">&times;</b>
        </button>
      </div>
      <div class="registered-map-layout">
        <aside class="registered-map-results">
          <p id="detailsMapCount"></p>
          <div id="detailsMapList"></div>
        </aside>
        <div id="mapFrame" class="registered-map-canvas" aria-label="Map of registered hotels and resorts"></div>
      </div>
    </div>
  </div>

  <button type="button" class="mobile-floating-reserve" id="mobileFloatingReserve" aria-label="Reserve a room" aria-hidden="true">
    <svg viewBox="0 0 24 24" aria-hidden="true">
      <path d="M4 7h16v12H4zM7 4v6M17 4v6M4 11h16"></path>
    </svg>
    <span>Reserve Room</span>
  </button>

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
  </script>
  <script src="js/favorites.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
  <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
  <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin="anonymous"></script>
  <script>
    const hotelData = <?= json_encode($hotel, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const registeredMapHotels = <?= json_encode($registeredMapHotels, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const pageSource = <?= json_encode($source) ?>;
    const isLoggedIn = <?= $isLoggedIn ? 'true' : 'false' ?>;
    const bookingSuccess = <?= $bookingSuccess ? 'true' : 'false' ?>;
    const mapAddress = <?= json_encode($mapAddress, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const galleryImages = <?= json_encode(array_values((array)$hotel['gallery_images']), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const escapeHotelDetailsText = value => String(value ?? "")
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;")
      .replace(/'/g, "&#039;");
    window.RecentlyViewed?.add({
      type: String(hotelData.type || "hotel").toLowerCase(),
      id: hotelData.id,
      name: hotelData.name,
      image: hotelData.img || "img/sampleimage.png",
      subtitle: `${hotelData.island || "Mercedes"} · ${String(hotelData.type || "stay").toUpperCase()}`,
      price: <?= json_encode((float)$displayBasePrice) ?>,
      priceUnit: "/night",
      rating: Number(hotelData.rating || 0),
      reviewCount: Number(hotelData.total_reviews || 0),
      href: `hotel_details.php?id=${encodeURIComponent(hotelData.id)}&source=result`
    });
    const dateRangeInput = document.getElementById("dateRangePicker");
    const stayDurationBadge = document.getElementById("stayDurationBadge");
    const checkinInput = document.getElementById("checkin");
    const checkoutInput = document.getElementById("checkout");
    const islandInput = document.getElementById("island");
    const searchActionBtn = document.getElementById("searchActionBtn");
    const searchWrap = document.getElementById("detailsSearchWrap");
    const detailsSearchToggle = document.getElementById("detailsSearchToggle");
    const detailsSearchSummary = document.getElementById("detailsSearchSummary");
    const roomsContent = document.getElementById("roomsContent");
    const hotelSearchStorageKey = `hotelDetailsSearchData:${hotelData.id}`;
    const hotelSearchDraftStorageKey = `hotelDetailsSearchDraft:${hotelData.id}`;
    const hotelSearchExpandedStorageKey = `hotelDetailsSearchExpanded:${hotelData.id}`;
    const legacyHotelSearchStorageKey = "hotelDetailsSearchData";
    const resortsSearchStorageKey = "searchData";
    const mainPhoto = document.getElementById("mainPhoto");
    const sidePhoto = document.getElementById("sidePhoto");
    const thumbsWrap = document.getElementById("photoThumbs");
    const reserveOverviewBtn = document.getElementById("reserveOverviewBtn");
    const mobileFloatingReserve = document.getElementById("mobileFloatingReserve");
    let activeFilteredRooms = [];
    let roomSearchRequestId = 0;

    function updateDetailsSearchSummary() {
      if (!detailsSearchSummary) return;
      const location = islandInput.value || hotelData.island || 'Choose location';
      const dates = dateRangeInput.value || 'Select dates';
      const guests = document.getElementById('guestText')?.textContent?.trim() || 'Guests & Rooms';
      detailsSearchSummary.textContent = `${location} · ${dates} · ${guests}`;
    }

    function setDetailsSearchExpanded(expanded) {
      searchWrap.classList.toggle('is-expanded', expanded);
      detailsSearchToggle?.setAttribute('aria-expanded', String(expanded));
      sessionStorage.setItem(hotelSearchExpandedStorageKey, expanded ? 'true' : 'false');
      requestAnimationFrame(() => syncTabsStickyPosition());
    }

    detailsSearchToggle?.addEventListener('click', () => {
      setDetailsSearchExpanded(!searchWrap.classList.contains('is-expanded'));
    });

    const galleryModal = document.getElementById("galleryModal");
    const galleryModalImage = document.getElementById("galleryModalImage");
    const galleryCounter = document.getElementById("galleryCounter");
    const galleryPrev = document.getElementById("galleryPrev");
    const galleryNext = document.getElementById("galleryNext");
    const closeGalleryModal = document.getElementById("closeGalleryModal");
    const shareModal = document.getElementById("shareModal");
    const shareHotelBtn = document.getElementById("shareHotelBtn");
    const mobileShareHotelBtn = document.getElementById("mobileShareHotelBtn");
    const closeShareModal = document.getElementById("closeShareModal");
    const shareHotelLink = document.getElementById("shareHotelLink");
    const copyHotelLink = document.getElementById("copyHotelLink");
    const nativeShareHotel = document.getElementById("nativeShareHotel");
    const shareModalStatus = document.getElementById("shareModalStatus");

    const mapModal = document.getElementById("mapModal");
    const mapFrame = document.getElementById("mapFrame");
    const mapPreviewFrame = document.getElementById("mapPreviewFrame");
    const openMapBtn = document.getElementById("openMapBtn");
    const mobileMapBtn = document.getElementById("mobileMapBtn");
    const overviewMapCard = document.getElementById("overviewMapCard");
    const desktopOverviewMapSlot = document.getElementById("desktopOverviewMapSlot");
    const tabletOverviewMapSlot = document.getElementById("tabletOverviewMapSlot");
    const closeMapModal = document.getElementById("closeMapModal");
    let detailsPreviewMap = null;
    let detailsRegisteredMap = null;
    let detailsMapMarkers = [];
    let detailsMappedHotels = [];
    let detailsMapHasRendered = false;
    let detailsMapPopupTimer = 0;
    let detailsMapOpenFrame = 0;
    let activeShareTrigger = shareHotelBtn;
    const detailsMapCenter = [14.0865, 123.065];
    const detailsIslandCoordinates = {
      apuao: [14.122, 123.071],
      malasugui: [14.084, 123.085],
      quinapaguian: [14.0695, 123.069],
      cayucyucan: [14.071, 123.038],
      caringo: [14.052, 123.101],
      canimog: [14.107, 123.105],
      mercedes: [14.109, 123.011]
    };

    function syncOverviewMapPlacement() {
      if (!overviewMapCard || !desktopOverviewMapSlot || !tabletOverviewMapSlot) return;
      const useTabletLayout = window.matchMedia('(min-width: 769px) and (max-width: 1100px)').matches;
      const targetSlot = useTabletLayout ? tabletOverviewMapSlot : desktopOverviewMapSlot;
      if (overviewMapCard.parentElement !== targetSlot) {
        targetSlot.appendChild(overviewMapCard);
        window.requestAnimationFrame(() => detailsPreviewMap?.invalidateSize({ pan: false, animate: false }));
      }
    }

    let currentGalleryIndex = 0;
    let activeGalleryImages = galleryImages;
    let mobileGalleryIndex = 0;
    let mobileGalleryTouchStartX = 0;
    let mobileGalleryTouchStartY = 0;
    let mobileGalleryDidSwipe = false;
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
        const shouldBeActive = index === activeIndex;
        const activeStateChanged = link.classList.contains("active") !== shouldBeActive;
        if (activeStateChanged) {
          link.classList.toggle("active", shouldBeActive);
        }
        if (shouldBeActive && activeStateChanged && window.matchMedia('(max-width: 768px)').matches) {
          const targetLeft = link.offsetLeft - ((tabs.clientWidth - link.offsetWidth) / 2);
          tabs.scrollTo({ left: Math.max(0, targetLeft), behavior: 'smooth' });
        }
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

    const getShareUrl = () => {
      const url = new URL(window.location.href);
      url.search = "";
      url.searchParams.set("id", String(hotelData.id));
      return url.toString();
    };

    function closeShare() {
      shareModal.classList.remove("open");
      shareModal.setAttribute("aria-hidden", "true");
      document.body.classList.remove("no-scroll");
      activeShareTrigger?.focus();
    }

    function openShare(event) {
      activeShareTrigger = event?.currentTarget || shareHotelBtn;
      shareHotelLink.value = getShareUrl();
      shareModalStatus.textContent = "";
      nativeShareHotel.hidden = !navigator.share;
      shareModal.classList.add("open");
      shareModal.setAttribute("aria-hidden", "false");
      document.body.classList.add("no-scroll");
      copyHotelLink.focus();
    }

    async function copyShareLink() {
      try {
        if (navigator.clipboard?.writeText) await navigator.clipboard.writeText(shareHotelLink.value);
        else { shareHotelLink.select(); document.execCommand("copy"); }
        shareModalStatus.textContent = "Link copied to your clipboard.";
      } catch (error) {
        shareHotelLink.select();
        shareModalStatus.textContent = "Select and copy the link above.";
      }
    }

    async function useNativeShare() {
      try {
        await navigator.share({ title: hotelData.name, text: `Take a look at ${hotelData.name} in ${hotelData.island}, Mercedes.`, url: shareHotelLink.value });
        shareModalStatus.textContent = "Share options opened.";
      } catch (error) {
        if (error?.name !== "AbortError") shareModalStatus.textContent = "Unable to open sharing options. Copy the link instead.";
      }
    }

    function moveGallery(step) {
      currentGalleryIndex = (currentGalleryIndex + step + activeGalleryImages.length) % activeGalleryImages.length;
      updateGalleryModal();
    }

    function showMobileGalleryImage(index) {
      if (!mainPhoto || galleryImages.length === 0) return;
      mobileGalleryIndex = (index + galleryImages.length) % galleryImages.length;
      mainPhoto.src = galleryImages[mobileGalleryIndex];
      mainPhoto.alt = `${hotelData.name} photo ${mobileGalleryIndex + 1} of ${galleryImages.length}`;
      const mobileCounter = document.getElementById("mobileGalleryCounter");
      if (mobileCounter) mobileCounter.textContent = `${mobileGalleryIndex + 1} / ${galleryImages.length}`;
    }

    function renderGallery() {
      activeGalleryImages = galleryImages;
      mainPhoto.decoding = "async";
      mainPhoto.fetchPriority = "high";
      showMobileGalleryImage(0);
      mainPhoto.addEventListener("click", () => {
        if (mobileGalleryDidSwipe) {
          mobileGalleryDidSwipe = false;
          return;
        }
        openGalleryWithSet(galleryImages, mobileGalleryIndex);
      });

      const mobileGallerySurface = mainPhoto.closest(".photo-main");
      mobileGallerySurface?.addEventListener("touchstart", event => {
        if (window.innerWidth > 1100 || event.touches.length !== 1) return;
        mobileGalleryDidSwipe = false;
        mobileGalleryTouchStartX = event.touches[0].clientX;
        mobileGalleryTouchStartY = event.touches[0].clientY;
      }, { passive: true });
      mobileGallerySurface?.addEventListener("touchend", event => {
        if (window.innerWidth > 1100 || !event.changedTouches[0]) return;
        const horizontalDistance = event.changedTouches[0].clientX - mobileGalleryTouchStartX;
        const verticalDistance = event.changedTouches[0].clientY - mobileGalleryTouchStartY;
        if (Math.abs(horizontalDistance) < 38 || Math.abs(horizontalDistance) <= Math.abs(verticalDistance)) return;
        mobileGalleryDidSwipe = true;
        showMobileGalleryImage(mobileGalleryIndex + (horizontalDistance < 0 ? 1 : -1));
        window.setTimeout(() => { mobileGalleryDidSwipe = false; }, 500);
      }, { passive: true });

      const sideImageIndex = galleryImages[1] ? 1 : 0;
      if (sidePhoto) {
        sidePhoto.loading = "lazy";
        sidePhoto.decoding = "async";
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
            <img src="${escapeHotelDetailsText(img)}" alt="Hotel image ${imageIndex + 1}" loading="lazy" decoding="async" />
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

    function escapeRegisteredMapText(value) {
      return String(value ?? "").replace(/[&<>"']/g, character => ({
        "&": "&amp;",
        "<": "&lt;",
        ">": "&gt;",
        '"': "&quot;",
        "'": "&#039;"
      })[character]);
    }

    function getRegisteredHotelCoordinates(hotel, index = 0) {
      const island = String(hotel?.island || "mercedes").trim().toLowerCase();
      const matchedIsland = Object.keys(detailsIslandCoordinates).find(key => island.includes(key));
      const base = detailsIslandCoordinates[matchedIsland] || detailsMapCenter;
      const seed = Number(hotel?.id || index + 1);
      const angle = (seed * 137.508) * (Math.PI / 180);
      const distance = 0.0012 + ((seed % 4) * 0.00035);
      return [base[0] + Math.sin(angle) * distance, base[1] + Math.cos(angle) * distance];
    }

    function getOrderedRegisteredHotels() {
      return [...registeredMapHotels].sort((first, second) => {
        if (Number(first.id) === Number(hotelData.id)) return -1;
        if (Number(second.id) === Number(hotelData.id)) return 1;
        const firstSameIsland = String(first.island).toLowerCase() === String(hotelData.island).toLowerCase() ? 0 : 1;
        const secondSameIsland = String(second.island).toLowerCase() === String(hotelData.island).toLowerCase() ? 0 : 1;
        if (firstSameIsland !== secondSameIsland) return firstSameIsland - secondSameIsland;
        return Number(second.rating || 0) - Number(first.rating || 0);
      });
    }

    function addRegisteredMapTiles(map) {
      L.tileLayer("https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png", {
        maxZoom: 19,
        attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>'
      }).addTo(map);
    }

    function registeredHotelMarkerIcon(hotel, compact = false) {
      const isCurrent = Number(hotel.id) === Number(hotelData.id);
      const numericPrice = Number(hotel.price || 0);
      const price = numericPrice > 0
        ? `₱${numericPrice.toLocaleString("en-PH", { maximumFractionDigits: 0 })}`
        : "See rates";
      const image = escapeRegisteredMapText(hotel.img || "img/sampleimage.png");
      return L.divIcon({
        className: "details-map-marker-shell",
        html: `<span class="details-map-profile-marker${compact ? " compact" : ""}${isCurrent ? " current" : ""}"><span class="details-map-avatar"><img src="${image}" alt=""></span><span class="details-map-rate">${price}</span></span>`,
        iconSize: compact ? [64, 68] : [80, 84],
        iconAnchor: compact ? [32, 67] : [40, 83],
        popupAnchor: [0, -78]
      });
    }

    function registeredHotelPopup(hotel) {
      const isCurrent = Number(hotel.id) === Number(hotelData.id);
      const mapQuery = encodeURIComponent(`${hotel.name}, ${hotel.island}, Mercedes, Camarines Norte`);
      const nightlyRate = Number(hotel.price || 0) > 0
        ? `₱${Number(hotel.price).toLocaleString("en-PH")} <em>/ night</em>`
        : "See available rates";
      return `
        <div class="details-map-popup">
          <small>${isCurrent ? "YOU ARE VIEWING" : escapeRegisteredMapText(String(hotel.type || "stay").toUpperCase())}</small>
          <strong>${escapeRegisteredMapText(hotel.name)}</strong>
          <span>${escapeRegisteredMapText(hotel.island)}, Mercedes</span>
          <b>${nightlyRate}</b>
          <div>
            ${isCurrent ? "" : `<a href="hotel_details.php?id=${Number(hotel.id)}&source=result">View stay</a>`}
            <a class="directions" href="https://www.google.com/maps/search/?api=1&query=${mapQuery}" target="_blank" rel="noopener">Directions</a>
          </div>
        </div>
      `;
    }

    function initRegisteredMapPreview() {
      if (typeof L === "undefined" || detailsPreviewMap || !mapPreviewFrame) return;
      detailsPreviewMap = L.map(mapPreviewFrame, {
        zoomControl: false,
        attributionControl: false,
        dragging: false,
        scrollWheelZoom: false,
        doubleClickZoom: false,
        keyboard: false,
        touchZoom: false
      }).setView(getRegisteredHotelCoordinates(hotelData), 12);
      addRegisteredMapTiles(detailsPreviewMap);

      getOrderedRegisteredHotels().slice(0, 8).forEach((hotel, index) => {
        L.marker(getRegisteredHotelCoordinates(hotel, index), {
          icon: registeredHotelMarkerIcon(hotel, true),
          interactive: false
        }).addTo(detailsPreviewMap);
      });
    }

    function renderRegisteredHotelsMap() {
      if (!detailsRegisteredMap) return;
      detailsMapMarkers.forEach(marker => marker.remove());
      detailsMapMarkers = [];
      detailsMappedHotels = getOrderedRegisteredHotels();

      detailsMappedHotels.forEach((hotel, index) => {
        const marker = L.marker(getRegisteredHotelCoordinates(hotel, index), {
          icon: registeredHotelMarkerIcon(hotel)
        }).addTo(detailsRegisteredMap);
        marker.bindPopup(registeredHotelPopup(hotel), { minWidth: 215 });
        marker.on("click", () => {
          document.querySelectorAll(".registered-map-result-card").forEach(card => card.classList.remove("active"));
          document.querySelector(`.registered-map-result-card[data-hotel-id="${Number(hotel.id)}"]`)?.classList.add("active");
        });
        detailsMapMarkers.push(marker);
      });

      const count = document.getElementById("detailsMapCount");
      if (count) {
        count.textContent = `${detailsMappedHotels.length} registered ${detailsMappedHotels.length === 1 ? "property" : "properties"} near Mercedes`;
      }

      const list = document.getElementById("detailsMapList");
      if (list) {
        list.innerHTML = detailsMappedHotels.map(hotel => {
          const isCurrent = Number(hotel.id) === Number(hotelData.id);
          return `
            <button type="button" class="registered-map-result-card${isCurrent ? " current" : ""}" data-hotel-id="${Number(hotel.id)}" onclick="focusRegisteredHotel(${Number(hotel.id)})">
              <img src="${escapeRegisteredMapText(hotel.img)}" alt="" loading="lazy">
              <span>
                <small>${isCurrent ? "YOU ARE VIEWING" : `${escapeRegisteredMapText(String(hotel.type || "stay").toUpperCase())} · ${escapeRegisteredMapText(hotel.island)}`}</small>
                <strong>${escapeRegisteredMapText(hotel.name)}</strong>
                <b>${Number(hotel.price || 0) > 0 ? `₱${Number(hotel.price).toLocaleString("en-PH")} <em>/ night</em>` : "See available rates"}</b>
              </span>
            </button>
          `;
        }).join("");
      }

      if (detailsMapMarkers.length) {
        const group = L.featureGroup(detailsMapMarkers);
        detailsRegisteredMap.fitBounds(group.getBounds().pad(0.2), { maxZoom: 14, animate: false });
      }
      detailsMapHasRendered = true;
    }

    function focusRegisteredHotel(hotelId) {
      const index = detailsMappedHotels.findIndex(hotel => Number(hotel.id) === Number(hotelId));
      if (index < 0 || !detailsMapMarkers[index]) return;
      document.querySelectorAll(".registered-map-result-card").forEach(card => card.classList.remove("active"));
      document.querySelector(`.registered-map-result-card[data-hotel-id="${hotelId}"]`)?.classList.add("active");
      const marker = detailsMapMarkers[index];
      detailsRegisteredMap.setView(marker.getLatLng(), 14, { animate: true });
      marker.openPopup();
    }

    function prepareRegisteredHotelsMap() {
      if (typeof L === "undefined" || !mapFrame) return;
      if (!detailsRegisteredMap && typeof L !== "undefined") {
        detailsRegisteredMap = L.map(mapFrame, { zoomControl: true }).setView(detailsMapCenter, 11);
        addRegisteredMapTiles(detailsRegisteredMap);
      }
      detailsRegisteredMap.invalidateSize({ pan: false, animate: false });
      if (!detailsMapHasRendered) renderRegisteredHotelsMap();
    }

    function openMapModal() {
      if (mapModal.classList.contains("open")) return;
      window.clearTimeout(detailsMapPopupTimer);
      window.cancelAnimationFrame(detailsMapOpenFrame);
      mapModal.classList.add("open");
      document.body.classList.add("no-scroll");
      prepareRegisteredHotelsMap();
      detailsMapOpenFrame = requestAnimationFrame(() => {
        detailsRegisteredMap?.invalidateSize({ pan: false, animate: false });
        detailsMapPopupTimer = window.setTimeout(() => {
          if (!mapModal.classList.contains("open")) return;
          const currentIndex = detailsMappedHotels.findIndex(hotel => Number(hotel.id) === Number(hotelData.id));
          detailsMapMarkers[currentIndex]?.openPopup();
        }, 60);
      });
    }

    function closeMap() {
      window.clearTimeout(detailsMapPopupTimer);
      window.cancelAnimationFrame(detailsMapOpenFrame);
      detailsRegisteredMap?.stop();
      detailsRegisteredMap?.closePopup();
      mapModal.classList.remove("open");
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
        roomsContent.innerHTML = `<p class="dummy-text">No rooms available for this hotel.</p>`;
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
        const safeFirstImage = escapeHotelDetailsText(firstImage);
        const safeSecondImage = escapeHotelDetailsText(secondImage);
        const safeThirdImage = escapeHotelDetailsText(thirdImage);
        const safeRoomType = escapeHotelDetailsText(room.roomType || "Room");
        
        // Availability logic
        const isAvailable = room.isAvailable === true;
        const isBookedForDates = room.isBookedForDates === true;
        const insufficientCapacity = room.insufficientCapacity === true;
        const availabilityMessage = isAvailable
          ? 'Ready for selected stay dates'
          : isBookedForDates
            ? 'Not available for selected dates'
            : insufficientCapacity
              ? 'Room capacity is below your guest count'
              : 'Room is currently unavailable';
        
        // Determine badge and button state
        let availabilityBadge = '';
        let buttonText = 'Reserve this room';
        let buttonDisabled = false;
        let roomCardClass = '';
        
        if (!isAvailable) {
          roomCardClass = ' room-card--unavailable';
          buttonDisabled = true;
          if (isBookedForDates) {
            availabilityBadge = '<span class="room-availability-badge room-availability-badge--sold-out">Sold out for your selected date</span>';
            buttonText = 'Sold out for your selected date';
          } else if (insufficientCapacity) {
            availabilityBadge = '<span class="room-availability-badge room-availability-badge--insufficient">Not suitable for guest count</span>';
            buttonText = 'Not suitable for guest count';
          } else {
            availabilityBadge = '<span class="room-availability-badge room-availability-badge--sold-out">Currently unavailable</span>';
            buttonText = 'Currently unavailable';
          }
        } else {
          availabilityBadge = '<span class="room-availability-badge">Available</span>';
        }
        
        const roomSize = roomMeta.size ? `<p class="room-meta"><span class="meta-icon" aria-hidden="true"><svg viewBox="0 0 24 24" focusable="false"><path d="M4 4h6v2H6v4H4V4Zm10 0h6v6h-2V6h-4V4ZM4 14h2v4h4v2H4v-6Zm14 0h2v6h-6v-2h4v-4Z"></path></svg></span>${escapeHotelDetailsText(roomMeta.size)}</p>` : "";
        const roomView = roomMeta.view ? `<p class="room-meta"><span class="meta-icon" aria-hidden="true"><svg viewBox="0 0 24 24" focusable="false"><path d="M12 5c-5.2 0-9.6 3.1-11 7 1.4 3.9 5.8 7 11 7s9.6-3.1 11-7c-1.4-3.9-5.8-7-11-7Zm0 11a4 4 0 1 1 0-8 4 4 0 0 1 0 8Z"></path></svg></span>${escapeHotelDetailsText(roomMeta.view)}</p>` : "";
        
        return `
        <article class="room-card${roomCardClass}">
          <div class="room-gallery">
            <button type="button" class="room-main-photo room-gallery-open" data-start-index="0" ${buttonDisabled ? 'disabled' : ''}>
              <img src="${safeFirstImage}" alt="${safeRoomType} main photo" loading="lazy" decoding="async" />
            </button>
            <div class="room-gallery-thumbs">
              <button type="button" class="room-thumb room-gallery-open" data-start-index="1" ${buttonDisabled ? 'disabled' : ''}>
                <img src="${safeSecondImage}" alt="${safeRoomType} photo 2" loading="lazy" decoding="async" />
              </button>
              <button type="button" class="room-thumb room-gallery-more" data-start-index="3" ${buttonDisabled ? 'disabled' : ''}>
                <img src="${safeThirdImage}" alt="${safeRoomType} photo 3" loading="lazy" decoding="async" />
                <span><small></small>See more..</span>
              </button>
            </div>
          </div>
          <div class="room-content">
            <h3>${safeRoomType}</h3>
            ${availabilityBadge}
            <p class="room-meta${insufficientCapacity ? ' room-meta--unavailable' : ''}">
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
            <p class="room-meta${isAvailable ? '' : ' room-meta--unavailable'}">
              <span class="meta-icon" aria-hidden="true">
                <svg viewBox="0 0 24 24" focusable="false">
                  <path d="M12 2a10 10 0 1 0 10 10A10.011 10.011 0 0 0 12 2Zm1 10.586 2.707 2.707a1 1 0 0 1-1.414 1.414l-3-3A1 1 0 0 1 11 13V7a1 1 0 0 1 2 0Z"></path>
                </svg>
              </span>
              ${availabilityMessage}
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
                    ${escapeHotelDetailsText(item)}
                  </li>
                `).join("")}
              </ul>
            </div>
          </div>
          <div class="room-right">
            <p>Price per night</p>
            <strong>₱${room.price.toLocaleString()}</strong>
            <small>No hidden charges</small>
            <button type="button" class="room-book-btn" data-room-index="${Number(roomIndex)}" ${buttonDisabled ? 'disabled' : ''} title="${escapeHotelDetailsText(!isAvailable ? buttonText : 'Book this room')}">${escapeHotelDetailsText(buttonText)}</button>
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
          // Check if button is disabled
          if (btn.disabled || btn.hasAttribute('disabled')) {
            return;
          }

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
            children: String(searchData.children || 0),
            child_ages: Array.isArray(searchData.childAges) ? searchData.childAges.join(",") : ""
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

    function applyGuestSelection(shouldValidate = true) {
      const adults = Number(document.getElementById("adults").innerText);
      const children = Number(document.getElementById("children").innerText);
      const rooms = Number(document.getElementById("roomsCount").innerText);
      const guestText = document.getElementById("guestText");
      if (shouldValidate && !validateGuestSelection()) return;
      if (!shouldValidate) {
        clearGuestPickerHighlights();
        clearError(document.querySelector(".guest-display"));
      }
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
      updateDetailsSearchSummary();
    }

    function changeValue(id, delta) {
      const el = document.getElementById(id);
      const current = Number(el.innerText || 0);
      el.innerText = Math.max(0, current + delta);
      if (id === "children") renderChildAgeRows();
      el.closest(".guest-row")?.classList.remove("is-invalid");
      persistSearchFormDraft();
    }

    function parseChildAges(value) {
      const values = Array.isArray(value) ? value : String(value || "").split(",");
      return values
        .map(age => String(age).trim())
        .filter(age => /^\d+$/.test(age) && Number(age) >= 0 && Number(age) <= 17)
        .map(Number);
    }

    function getChildAges() {
      return Array.from(document.querySelectorAll("#childAgeRows .child-age-select"))
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

    function validateGuestSelection() {
      const adults = Number(document.getElementById("adults")?.innerText || 0);
      const children = Number(document.getElementById("children")?.innerText || 0);
      const rooms = Number(document.getElementById("roomsCount")?.innerText || 0);
      const guestDisplay = document.querySelector(".guest-display");
      const messages = [];
      clearGuestPickerHighlights();

      if (adults < 1) {
        document.getElementById("adults")?.closest(".guest-row")?.classList.add("is-invalid");
        messages.push("add at least 1 adult");
      }
      if (rooms < 1) {
        document.getElementById("roomsCount")?.closest(".guest-row")?.classList.add("is-invalid");
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
        container.insertAdjacentHTML("beforeend", '<p class="child-age-note">Ages 7 and below: free hotel admission and entrance fees.</p>');
      }
      container.querySelectorAll(".child-age-select").forEach(select => {
        select.addEventListener("change", () => {
          select.removeAttribute("aria-invalid");
          select.closest(".child-age-row")?.classList.remove("is-invalid");
          if (validateGuestSelection()) clearError(document.querySelector(".guest-display"));
          persistSearchFormDraft();
        });
      });
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
      renderChildAgeRows(data.childAges || data.child_ages || []);
      applyGuestSelection(false);
      updateIslandFieldState();
    }

    function openResortsInNewTabBySearch(data) {
      const params = new URLSearchParams({
        island: data.island,
        checkin: data.checkin,
        checkout: data.checkout,
        adults: String(data.adults),
        children: String(data.children),
        child_ages: data.childAges.join(","),
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
        childAges: getChildAges(),
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
      sessionStorage.removeItem(hotelSearchExpandedStorageKey);
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
      if (!validateGuestSelection()) {
        valid = false;
      }
      return valid;
    }

    function handleSearchAttempt() {
      const data = collectSearchData();
      const valid = validateSearchData(data);

      if (!valid) {
        setDetailsSearchExpanded(true);
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
      if (window.matchMedia('(max-width: 768px)').matches) {
        setDetailsSearchExpanded(false);
        collapseMobileSearchHeader();
      }

      /* Wait for the collapsible search and sticky tabs to reach their final size. */
      requestAnimationFrame(() => {
        syncTabsStickyPosition();
        requestAnimationFrame(() => {
          const roomsSection = document.getElementById("rooms");
          const tabs = document.getElementById("hotelTabs");
          if (!roomsSection || !tabs) return;
          const stickyTop = Number(tabs.dataset.stickyTop || 140);
          const offset = stickyTop + (tabs.offsetHeight || 0) + 8;
          const top = Math.max(0, roomsSection.getBoundingClientRect().top + window.scrollY - offset);
          document.querySelectorAll("#hotelTabs a").forEach(link => {
            link.classList.toggle("active", link.getAttribute("href") === "#rooms");
          });
          window.scrollTo({ top, behavior: "smooth" });
        });
      });
    }

    const todayStr = new Date().toISOString().split("T")[0];
    let calendarPositionFrame = 0;
    const placeCalendarBelow = () => {
      if (!picker?.isOpen) return;
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
    const scheduleCalendarPosition = () => {
      if (!picker?.isOpen || calendarPositionFrame) return;
      calendarPositionFrame = requestAnimationFrame(() => {
        calendarPositionFrame = 0;
        placeCalendarBelow();
      });
    };

    const mobileCalendarQuery = window.matchMedia('(max-width: 768px)');
    const picker = flatpickr(dateRangeInput, {
      mode: "range",
      showMonths: mobileCalendarQuery.matches ? 1 : 2,
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
        updateDetailsSearchSummary();
      }
    });
    const updateCalendarMonths = event => {
      picker.set('showMonths', event.matches ? 1 : 2);
      if (picker.isOpen) requestAnimationFrame(placeCalendarBelow);
    };
    if (typeof mobileCalendarQuery.addEventListener === 'function') {
      mobileCalendarQuery.addEventListener('change', updateCalendarMonths);
    } else if (typeof mobileCalendarQuery.addListener === 'function') {
      mobileCalendarQuery.addListener(updateCalendarMonths);
    }
    dateRangeInput.setAttribute('readonly', 'readonly');
    dateRangeInput.setAttribute('inputmode', 'none');
    dateRangeInput.addEventListener('pointerdown', event => {
      if (!mobileCalendarQuery.matches) return;
      event.preventDefault();
      dateRangeInput.blur();
      picker.open();
    });
    dateRangeInput.addEventListener('keydown', event => event.preventDefault());

    function syncTabsStickyPosition() {
      const tabs = document.getElementById("hotelTabs");
      const searchWrapEl = document.getElementById("detailsSearchWrap");
      if (!tabs || !searchWrapEl) return;

      const headerTop = 70;
      const mobileSearchIsHidden = window.matchMedia('(max-width: 768px)').matches
        && searchWrapEl.classList.contains('is-scroll-hidden');
      const computedTop = headerTop + (mobileSearchIsHidden ? 0 : Math.round(searchWrapEl.offsetHeight));
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

    function collapseMobileSearchHeader() {
      if (!window.matchMedia('(max-width: 768px)').matches) return;
      searchWrap.classList.add('is-scroll-hidden');
      document.body.classList.add('hotel-search-collapsed');
      syncTabsStickyPosition();
    }

    let mobileChromeFrame = 0;
    function updateMobileHotelChrome() {
      const isMobile = window.matchMedia('(max-width: 768px)').matches;
      if (!isMobile) {
        const hadMobileState = searchWrap.classList.contains('is-scroll-hidden')
          || document.body.classList.contains('hotel-search-collapsed')
          || document.body.classList.contains('hotel-floating-reserve-visible');
        searchWrap.classList.remove('is-scroll-hidden');
        document.body.classList.remove('hotel-search-collapsed', 'hotel-floating-reserve-visible');
        mobileFloatingReserve?.classList.remove('is-visible');
        mobileFloatingReserve?.setAttribute('aria-hidden', 'true');
        mobileFloatingReserve?.setAttribute('tabindex', '-1');
        if (hadMobileState) syncTabsStickyPosition();
        return;
      }

      const shouldCollapseSearch = window.scrollY > 80;
      const searchWasCollapsed = searchWrap.classList.contains('is-scroll-hidden');
      searchWrap.classList.toggle('is-scroll-hidden', shouldCollapseSearch);
      const searchStateChanged = searchWasCollapsed !== shouldCollapseSearch;
      document.body.classList.toggle('hotel-search-collapsed', shouldCollapseSearch);
      if (searchStateChanged || Number(document.getElementById('hotelTabs')?.dataset?.stickyTop || 0) !== (shouldCollapseSearch ? 70 : 70 + searchWrap.offsetHeight)) {
        syncTabsStickyPosition();
      }

      const reserveRect = reserveOverviewBtn.getBoundingClientRect();
      const originalReserveIsVisible = reserveRect.bottom > 70 && reserveRect.top < window.innerHeight;
      const showFloatingReserve = window.scrollY > 50 && !originalReserveIsVisible;
      mobileFloatingReserve?.classList.toggle('is-visible', showFloatingReserve);
      mobileFloatingReserve?.setAttribute('aria-hidden', String(!showFloatingReserve));
      mobileFloatingReserve?.setAttribute('tabindex', showFloatingReserve ? '0' : '-1');
      document.body.classList.toggle('hotel-floating-reserve-visible', showFloatingReserve);
    }

    function scheduleMobileHotelChrome() {
      if (mobileChromeFrame) return;
      mobileChromeFrame = requestAnimationFrame(() => {
        mobileChromeFrame = 0;
        updateMobileHotelChrome();
      });
    }

    function scrollToRooms() {
      collapseMobileSearchHeader();
      const roomsSection = document.getElementById("rooms");
      const tabs = document.getElementById("hotelTabs");
      if (!roomsSection || !tabs) return;
      const stickyTop = Number(tabs.dataset.stickyTop || 140);
      const offset = stickyTop + (tabs.offsetHeight || 0) + 8;
      const top = roomsSection.getBoundingClientRect().top + window.scrollY - offset;
      window.scrollTo({ top, behavior: "smooth" });
    }

    const updateIslandFieldState = () => {
      const hasLocation = Boolean(islandInput.value);
      islandInput.classList.toggle("is-placeholder", !hasLocation);
    };

    islandInput.addEventListener("change", updateIslandFieldState);
    window.addEventListener("resize", scheduleCalendarPosition, { passive: true });
    window.addEventListener("scroll", scheduleCalendarPosition, { passive: true, capture: true });

    document.addEventListener("DOMContentLoaded", () => {
      const headerQuery = <?= json_encode($bookingSuccess ? ('?booking_success=1' . ($bookingRef !== '' ? '&booking_ref=' . rawurlencode($bookingRef) : '')) : '') ?>;
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

      syncOverviewMapPlacement();
      renderGallery();
      const queueMapPreview = () => {
        const initialize = () => initRegisteredMapPreview();
        if ("requestIdleCallback" in window) {
          window.requestIdleCallback(initialize, { timeout: 600 });
        } else {
          window.setTimeout(initialize, 40);
        }
      };
      if (mapPreviewFrame && "IntersectionObserver" in window) {
        const previewObserver = new IntersectionObserver(entries => {
          if (!entries.some(entry => entry.isIntersecting)) return;
          previewObserver.disconnect();
          queueMapPreview();
        }, { rootMargin: "220px 0px" });
        previewObserver.observe(mapPreviewFrame);
      } else if (mapPreviewFrame) {
        window.addEventListener("load", queueMapPreview, { once: true });
      }

      if (bookingSuccess) {
        clearPersistedSearchState();
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
      updateDetailsSearchSummary();
      if (window.matchMedia('(max-width: 768px)').matches) {
        setDetailsSearchExpanded(sessionStorage.getItem(hotelSearchExpandedStorageKey) === 'true');
      }

      searchActionBtn.addEventListener("click", () => {
        handleSearchAttempt();
      });

      document.getElementById("guestBox").addEventListener("click", () => {
        clearError(document.querySelector(".guest-display"));
      });
      islandInput.addEventListener("change", () => {
        clearError(islandInput);
        persistSearchFormDraft();
        updateDetailsSearchSummary();
      });
      dateRangeInput.addEventListener("change", () => {
        clearError(dateRangeInput);
        persistSearchFormDraft();
        updateDetailsSearchSummary();
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
          if (section.id !== 'overview' || window.scrollY > 80) collapseMobileSearchHeader();
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
      mobileMapBtn?.addEventListener("click", openMapModal);
      reserveOverviewBtn.addEventListener("click", scrollToRooms);
      mobileFloatingReserve?.addEventListener("click", scrollToRooms);
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
      shareHotelBtn?.addEventListener("click", openShare);
      mobileShareHotelBtn?.addEventListener("click", openShare);
      closeShareModal?.addEventListener("click", closeShare);
      copyHotelLink?.addEventListener("click", copyShareLink);
      nativeShareHotel?.addEventListener("click", useNativeShare);
      shareModal?.addEventListener("click", (e) => { if (e.target === shareModal) closeShare(); });

      document.addEventListener("keydown", (e) => {
        if (galleryModal.classList.contains("open")) {
          if (e.key === "ArrowLeft") moveGallery(-1);
          if (e.key === "ArrowRight") moveGallery(1);
          if (e.key === "Escape") closeGallery();
        }
        if (mapModal.classList.contains("open") && e.key === "Escape") {
          closeMap();
        }
        if (shareModal?.classList.contains("open") && e.key === "Escape") closeShare();
      });

      syncTabsStickyPosition();
      setActiveTabByScroll();
      updateMobileHotelChrome();
      let activeTabFrame = 0;
      const scheduleActiveTabUpdate = () => {
        if (activeTabFrame) return;
        activeTabFrame = requestAnimationFrame(() => {
          activeTabFrame = 0;
          setActiveTabByScroll();
        });
      };
      window.addEventListener("scroll", scheduleActiveTabUpdate, { passive: true });
      window.addEventListener("scroll", scheduleMobileHotelChrome, { passive: true });
      window.addEventListener("resize", () => {
        syncOverviewMapPlacement();
        syncTabsStickyPosition();
        scheduleActiveTabUpdate();
        scheduleMobileHotelChrome();
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
<script src="js/booking-payment-confirmation.js"></script>
<script src="js/back-to-top.js?v=<?= (int)@filemtime(__DIR__ . '/../js/back-to-top.js') ?>"></script>
</body>
</html>
