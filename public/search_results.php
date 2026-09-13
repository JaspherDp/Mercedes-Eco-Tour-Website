<?php
chdir(__DIR__ . '/..');
require_once __DIR__ . '/../php/session_security.php';
AppSessionStart();
require_once 'php/db_connection.php';
require_once 'php/unified_search_config.php';
require_once 'php/tour_resource_availability_helper.php';

function normalizeSearchTab(string $tab): string
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

function tableColumns(PDO $pdo, string $table): array
{
    static $cache = [];
    if (isset($cache[$table])) {
        return $cache[$table];
    }

    $stmt = $pdo->prepare("
        SELECT COLUMN_NAME
        FROM information_schema.columns
        WHERE table_schema = DATABASE() AND table_name = ?
    ");
    $stmt->execute([$table]);
    $columns = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
    $cache[$table] = array_map('strtolower', $columns);
    return $cache[$table];
}

function firstColumn(array $columns, array $candidates): ?string
{
    $lookup = array_flip($columns);
    foreach ($candidates as $candidate) {
        if (isset($lookup[strtolower($candidate)])) {
            return $candidate;
        }
    }
    return null;
}

function fetchRows(PDO $pdo, string $sql, array $params = []): array
{
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

function formatPrice(?float $amount, string $suffix = ''): string
{
    if ($amount === null) {
        return '';
    }
    return '₱' . number_format($amount, 0) . $suffix;
}

function valueFromRow(array $row, array $keys, string $fallback = ''): string
{
    foreach ($keys as $key) {
        if (isset($row[$key]) && trim((string)$row[$key]) !== '') {
            return (string)$row[$key];
        }
    }
    return $fallback;
}

function resolveSearchThumbnail(string $path): string
{
    $normalized = ltrim(str_replace('\\', '/', $path), '/');
    $directory = trim(str_replace('\\', '/', dirname($normalized)), '.');
    $thumbnail = ($directory !== '' ? $directory . '/' : '')
        . 'search_thumbnails/'
        . pathinfo($normalized, PATHINFO_FILENAME)
        . '.jpg';
    $projectRoot = dirname(__DIR__);
    $thumbnailFile = $projectRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $thumbnail);
    return is_file($thumbnailFile) ? $thumbnail : $normalized;
}

function resolveSearchImage(?string $value, string $fallback = 'img/sampleimage.png'): string
{
    $raw = trim((string)$value);
    if ($raw === '') {
        return $fallback;
    }

    if (preg_match('/^(https?:)?\/\//i', $raw) === 1 || str_starts_with($raw, 'data:')) {
        return $raw;
    }

    $normalized = ltrim(str_replace('\\', '/', $raw), '/');
    $projectRoot = dirname(__DIR__);
    if (is_file($projectRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $normalized))) {
        return resolveSearchThumbnail($normalized);
    }

    $filename = basename($normalized);
    if ($filename === '') {
        return $fallback;
    }

    $paths = [
        "php/upload/{$filename}",
        "upload/{$filename}",
        "uploads/{$filename}",
        "img/{$filename}"
    ];

    foreach ($paths as $path) {
        $fullPath = $projectRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path);
        if (is_file($fullPath)) {
            return resolveSearchThumbnail($path);
        }
    }

    return $fallback;
}

function appendQueryToUrl(string $url, array $query): string
{
    if (empty($query)) {
        return $url;
    }

    $parts = parse_url($url);
    if ($parts === false) {
        return $url;
    }

    $existingQuery = [];
    if (!empty($parts['query'])) {
        parse_str($parts['query'], $existingQuery);
    }

    foreach ($query as $key => $value) {
        if ($value === null || $value === '') {
            continue;
        }
        $existingQuery[$key] = $value;
    }

    $rebuilt = '';
    if (!empty($parts['path'])) {
        $rebuilt .= $parts['path'];
    }
    $queryString = http_build_query($existingQuery);
    if ($queryString !== '') {
        $rebuilt .= '?' . $queryString;
    }
    if (!empty($parts['fragment'])) {
        $rebuilt .= '#' . $parts['fragment'];
    }
    return $rebuilt;
}

function normalizeSearchDate(string $value): string
{
    $date = trim($value);
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1 ? $date : '';
}

function normalizeTourTypeValue(string $value): string
{
    $normalized = strtolower(trim($value));
    if (in_array($normalized, ['same-day', 'same day', 'sameday', 'day', 'day tour'], true)) {
        return 'same-day';
    }
    if (in_array($normalized, ['overnight', 'night', 'multi-day', 'multiday'], true)) {
        return 'overnight';
    }
    return '';
}

function inferTourTypeFromDuration(string $duration): string
{
    $value = strtolower(trim($duration));
    if ($value === '') {
        return '';
    }
    if (str_contains($value, 'night') || str_contains($value, 'overnight')) {
        return 'overnight';
    }
    if (preg_match('/\b1\s*day\b/', $value) === 1) {
        return 'same-day';
    }
    return '';
}

function normalizeLookupKey(string $value): string
{
    return strtolower(trim($value));
}

function extractPreferredNameFromMeta(string $meta): string
{
    $raw = trim($meta);
    if ($raw === '') {
        return '';
    }

    $parts = array_filter(array_map('trim', explode('|', $raw)));
    foreach ($parts as $part) {
        if (stripos($part, 'preferred:') === 0) {
            return trim(substr($part, strpos($part, ':') + 1));
        }
    }
    if (count($parts) === 1 && strpos($parts[0], ':') === false) {
        return $parts[0];
    }
    return '';
}

function fetchBookedResourceNamesForDate(PDO $pdo, string $bookingType, string $selectedDate): array
{
    $normalizedDate = normalizeSearchDate($selectedDate);
    if ($normalizedDate === '' || !tableExists($pdo, 'bookings')) {
        return [];
    }

    $rows = fetchRows($pdo, "
        SELECT package_name, preferred_resource
        FROM bookings
        WHERE booking_type = :booking_type
          AND DATE(booking_date) = :selected_date
          AND COALESCE(LOWER(status), '') NOT IN ('cancelled', 'declined', 'no-show')
          AND COALESCE(LOWER(is_complete), '') NOT IN ('cancelled', 'declined')
    ", [
        'booking_type' => $bookingType,
        'selected_date' => $normalizedDate
    ]);

    $lookup = [];
    foreach ($rows as $row) {
        $name = $bookingType === 'package'
            ? valueFromRow($row, ['package_name'], '')
            : extractPreferredNameFromMeta(valueFromRow($row, ['preferred_resource'], ''));
        $key = normalizeLookupKey($name);
        if ($key !== '') {
            $lookup[$key] = true;
        }
    }

    return $lookup;
}

function fetchHotelResults(PDO $pdo, string $destination, int $pax, int $rooms): array
{
    $table = tableExists($pdo, 'hotel_resorts') ? 'hotel_resorts' : (tableExists($pdo, 'hotel_resort') ? 'hotel_resort' : null);
    if ($table === null) {
        return [];
    }

    $columns = tableColumns($pdo, $table);
    $locationColumn = firstColumn($columns, ['location', 'island', 'destination']);
    $statusColumn = firstColumn($columns, ['status']);
    $orderColumn = firstColumn($columns, ['rating', 'updated_at', 'hotel_resort_id', 'id']) ?? 'id';

    $sql = "SELECT * FROM `{$table}` WHERE 1=1";
    $params = [];
    if ($destination !== '' && $locationColumn !== null) {
        $sql .= " AND `{$locationColumn}` LIKE :destination";
        $params['destination'] = '%' . $destination . '%';
    }
    if ($statusColumn !== null) {
        $sql .= " AND LOWER(TRIM(`{$statusColumn}`)) = 'active'";
    }
    $sql .= " ORDER BY `{$orderColumn}` DESC LIMIT 50";

    $rows = fetchRows($pdo, $sql, $params);
    $results = [];
    foreach ($rows as $row) {
        $id = valueFromRow($row, ['hotel_resort_id', 'id'], '0');
        $name = valueFromRow($row, ['name', 'hotel_name'], 'Hotel/Resort');
        $location = valueFromRow($row, ['location', 'island', 'destination'], 'Mercedes');
        $image = resolveSearchImage(
            valueFromRow($row, ['image_path', 'image_url', 'image'], ''),
            'img/sampleimage.png'
        );
        $ratingRaw = valueFromRow($row, ['rating'], '');
        $priceRaw = valueFromRow($row, ['price'], '');
        $rating = is_numeric($ratingRaw) ? (float)$ratingRaw : null;
        $price = is_numeric($priceRaw) ? (float)$priceRaw : null;

        $results[] = [
            'type' => 'Hotel/Resort',
            'name' => $name,
            'location' => $location,
            'image' => $image,
            'rating' => $rating,
            'price' => formatPrice($price, '/night'),
            'description' => $rooms . ' room(s) • ' . $pax . ' pax',
            'cta' => 'View Details',
            'url' => 'hotel_details.php?id=' . rawurlencode($id)
        ];
    }

    return $results;
}

function fetchTourPackageResults(PDO $pdo, string $destination, int $pax, string $selectedDate, string $requestedTourType): array
{
    if (!tableExists($pdo, 'tour_packages')) {
        return [];
    }

    $columns = tableColumns($pdo, 'tour_packages');
    $locationColumn = firstColumn($columns, ['destination', 'location']);
    $capacityColumn = firstColumn($columns, ['total_pax', 'capacity', 'max_pax']);
    $statusColumn = firstColumn($columns, ['status']);
    $hasFeedback = tableExists($pdo, 'feedback');
    if ($hasFeedback) {
        $sql = "SELECT p.*, COALESCE(ROUND(AVG(f.rating), 1), 0) AS avg_rating
                FROM tour_packages p
                LEFT JOIN feedback f ON f.package_id = p.package_id AND f.moderation_status = 'published'
                WHERE 1=1";
    } else {
        $sql = "SELECT p.*, 0 AS avg_rating FROM tour_packages p WHERE 1=1";
    }
    $params = [];

    if ($destination !== '' && $locationColumn !== null) {
        $sql .= " AND p.`{$locationColumn}` LIKE :destination";
        $params['destination'] = '%' . $destination . '%';
    }

    if ($pax > 0 && $capacityColumn !== null) {
        $sql .= " AND p.`{$capacityColumn}` >= :pax";
        $params['pax'] = $pax;
    }
    if ($statusColumn !== null) {
        $sql .= " AND LOWER(TRIM(p.`{$statusColumn}`)) = 'active'";
    }

    if ($hasFeedback) {
        $sql .= " GROUP BY p.package_id";
    }
    $sql .= " ORDER BY p.package_id DESC LIMIT 50";
    $rows = fetchRows($pdo, $sql, $params);
    $bookedPackages = fetchBookedResourceNamesForDate($pdo, 'package', $selectedDate);

    $results = [];
    foreach ($rows as $row) {
        $id = valueFromRow($row, ['package_id', 'id'], '0');
        $name = valueFromRow($row, ['package_title', 'name'], 'Tour Package');
        $location = valueFromRow($row, ['destination', 'location'], 'Mercedes');
        $image = resolveSearchImage(
            valueFromRow($row, ['package_image', 'package_image1', 'image_url'], ''),
            'img/sampleimage.png'
        );
        $ratingRaw = valueFromRow($row, ['avg_rating', 'rating'], '');
        $priceRaw = valueFromRow($row, ['price'], '');
        $price = is_numeric($priceRaw) ? (float)$priceRaw : null;
        $rating = is_numeric($ratingRaw) ? (float)$ratingRaw : null;
        $packageRange = valueFromRow($row, ['package_range'], '');
        $capacity = valueFromRow($row, ['total_pax', 'capacity', 'max_pax'], '');
        $packageType = normalizeTourTypeValue(valueFromRow($row, ['package_type'], ''));
        if ($packageType === '') {
            $packageType = inferTourTypeFromDuration($packageRange);
        }

        $isSoldOutOnDate = isset($bookedPackages[normalizeLookupKey($name)]);
        $isDurationMismatch = $requestedTourType !== '' && $packageType !== '' && $packageType !== $requestedTourType;
        $cta = 'View Details';
        $isDisabled = false;
        if ($isSoldOutOnDate) {
            $cta = 'Sold out for the selected date';
            $isDisabled = true;
        } elseif ($isDurationMismatch) {
            $cta = 'Unavailable for selected duration';
            $isDisabled = true;
        }

        $results[] = [
            'type' => 'Tour Package',
            'name' => $name,
            'location' => $location,
            'image' => $image,
            'rating' => $rating,
            'price' => formatPrice($price, '/pax'),
            'description' => valueFromRow($row, ['short_description', 'description'], ''),
            'details' => array_values(array_unique(array_filter([
                $packageRange,
                $capacity !== '' ? $capacity . ' pax maximum' : '',
                $packageType !== '' ? ucwords(str_replace('-', ' ', $packageType)) : ''
            ]))),
            'cta' => $cta,
            'url' => 'package_details.php?package_id=' . rawurlencode($id),
            'is_disabled' => $isDisabled
        ];
    }

    return $results;
}

function fetchGuideResults(PDO $pdo, string $destination, string $selectedDate, string $selectedEndDate = ''): array
{
    if (!tableExists($pdo, 'tour_guides')) {
        return [];
    }

    $columns = tableColumns($pdo, 'tour_guides');
    $locationColumn = firstColumn($columns, ['location', 'destination']);
    $statusColumn = firstColumn($columns, ['status']);
    $sql = "SELECT * FROM tour_guides WHERE 1=1";
    $params = [];
    if ($destination !== '' && $locationColumn !== null) {
        $sql .= " AND `{$locationColumn}` LIKE :destination";
        $params['destination'] = '%' . $destination . '%';
    }
    if ($statusColumn !== null) {
        $sql .= " AND LOWER(TRIM(`{$statusColumn}`)) = 'active'";
    }
    $sql .= " ORDER BY guide_id DESC LIMIT 50";

    $rows = fetchRows($pdo, $sql, $params);
    $unavailableGuideIds = [];
    if ($selectedDate !== '') {
        $rangeEnd = $selectedEndDate !== '' ? $selectedEndDate : $selectedDate;
        foreach (tourResourceActiveRanges($pdo, 'tourguide') as $range) {
            if (tourResourceRangesOverlap($selectedDate, $rangeEnd, $range['start'], $range['end'])) {
                $unavailableGuideIds[(int)$range['resource_id']] = true;
            }
        }
    }
    $results = [];
    foreach ($rows as $row) {
        $name = valueFromRow($row, ['fullname', 'name'], 'Tour Guide');
        $location = valueFromRow($row, ['location', 'destination'], 'Mercedes');
        $image = resolveSearchImage(
            valueFromRow($row, ['profile_picture', 'profile_image'], ''),
            'img/sampleimage.png'
        );
        $priceRaw = valueFromRow($row, ['daily_rate'], '');
        $price = is_numeric($priceRaw) ? (float)$priceRaw : null;
        $guideId = (int)valueFromRow($row, ['guide_id', 'id'], '0');
        $isSoldOutOnDate = isset($unavailableGuideIds[$guideId]);
        $experience = valueFromRow($row, ['years_experience', 'experience'], '');
        $specialty = valueFromRow($row, ['specialization', 'specialty', 'expertise'], '');
        $languages = valueFromRow($row, ['languages', 'language'], '');

        $results[] = [
            'type' => 'Tour Guide',
            'name' => $name,
            'location' => $location,
            'image' => $image,
            'rating' => null,
            'price' => formatPrice($price, '/day'),
            'description' => valueFromRow($row, ['short_description'], ''),
            'details' => array_values(array_filter([
                $specialty,
                $experience !== '' ? $experience . (is_numeric($experience) ? ' years experience' : '') : '',
                $languages !== '' ? 'Speaks ' . $languages : ''
            ])),
            'cta' => $isSoldOutOnDate ? 'Unavailable for selected date' : 'Book Guide',
            'url' => 'tour_booking.php?booking_type=tourguide&preferred=' . rawurlencode($name) . '&return=' . rawurlencode('hotel_resorts.php?tab=guides'),
            'is_disabled' => $isSoldOutOnDate
        ];
    }

    return $results;
}

function fetchBoatResults(PDO $pdo, string $destination, int $pax, string $selectedDate, string $selectedEndDate = ''): array
{
    if (!tableExists($pdo, 'boats')) {
        return [];
    }

    $columns = tableColumns($pdo, 'boats');
    $locationColumn = firstColumn($columns, ['location', 'destination']);
    $capacityColumn = firstColumn($columns, ['total_pax', 'capacity', 'max_pax']);
    $statusColumn = firstColumn($columns, ['status']);
    $sql = "SELECT * FROM boats WHERE 1=1";
    $params = [];
    if ($destination !== '' && $locationColumn !== null) {
        $sql .= " AND `{$locationColumn}` LIKE :destination";
        $params['destination'] = '%' . $destination . '%';
    }
    if ($statusColumn !== null) {
        $sql .= " AND LOWER(TRIM(`{$statusColumn}`)) = 'active'";
    }
    $sql .= " ORDER BY boat_id DESC LIMIT 50";

    $rows = fetchRows($pdo, $sql, $params);
    $unavailableBoatIds = [];
    if ($selectedDate !== '') {
        $rangeEnd = $selectedEndDate !== '' ? $selectedEndDate : $selectedDate;
        foreach (tourResourceActiveRanges($pdo, 'boat') as $range) {
            if (tourResourceRangesOverlap($selectedDate, $rangeEnd, $range['start'], $range['end'])) {
                $unavailableBoatIds[(int)$range['resource_id']] = true;
            }
        }
    }
    $results = [];
    foreach ($rows as $row) {
        $name = valueFromRow($row, ['name'], 'Boat');
        $location = valueFromRow($row, ['location', 'destination'], 'Mercedes');
        $image = resolveSearchImage(
            valueFromRow($row, ['image1', 'image_url'], ''),
            'img/sampleimage.png'
        );
        $capacityRaw = valueFromRow($row, ['total_pax', 'capacity'], '');
        $capacity = is_numeric($capacityRaw) ? (int)$capacityRaw : 0;

        $priceRaw = valueFromRow($row, ['price_per_hour'], '');
        $price = is_numeric($priceRaw) ? (float)$priceRaw : null;
        $description = $capacity > 0 ? ('Capacity: ' . $capacity . ' pax') : valueFromRow($row, ['size', 'boat_number'], '');
        $boatId = (int)valueFromRow($row, ['boat_id', 'id'], '0');
        $isSoldOutOnDate = isset($unavailableBoatIds[$boatId]);
        $boatSize = valueFromRow($row, ['size', 'boat_size'], '');
        $boatNumber = valueFromRow($row, ['boat_number', 'registration_number'], '');

        $results[] = [
            'type' => 'Tour Boat',
            'name' => $name,
            'location' => $location,
            'image' => $image,
            'rating' => null,
            'price' => formatPrice($price, '/hour'),
            'description' => $description,
            'details' => array_values(array_filter([
                $capacity > 0 ? $capacity . ' pax capacity' : '',
                $boatSize !== '' ? $boatSize . ' boat' : '',
                $boatNumber !== '' ? 'Boat ' . $boatNumber : ''
            ])),
            'cta' => $isSoldOutOnDate ? 'Unavailable for selected date' : 'Book Boat',
            'url' => 'tour_booking.php?booking_type=boat&preferred=' . rawurlencode($name) . '&return=' . rawurlencode('hotel_resorts.php?tab=boats'),
            'is_disabled' => $isSoldOutOnDate
        ];
    }

    return $results;
}

function fetchBundleResults(PDO $pdo, string $destination, int $pax, string $selectedDate, string $selectedEndDate = ''): array
{
    $guides = fetchGuideResults($pdo, $destination, $selectedDate, $selectedEndDate);
    $boats = fetchBoatResults($pdo, $destination, $pax, $selectedDate, $selectedEndDate);

    if (empty($guides) || empty($boats)) {
        return array_merge($guides, $boats);
    }

    $bundleResults = [];
    $maxGuides = min(8, count($guides));
    $maxBoats = min(8, count($boats));

    for ($i = 0; $i < $maxGuides; $i++) {
        for ($j = 0; $j < $maxBoats; $j++) {
            $guide = $guides[$i];
            $boat = $boats[$j];
            $isSoldOutOnDate = !empty($guide['is_disabled']) || !empty($boat['is_disabled']);
            $bundleResults[] = [
                'type' => 'Guide + Boat',
                'name' => $guide['name'] . ' + ' . $boat['name'],
                'location' => $boat['location'] !== '' ? $boat['location'] : $guide['location'],
                'image' => $boat['image'] !== '' ? $boat['image'] : $guide['image'],
                'rating' => null,
                'price' => '',
                'description' => 'Bundle for ' . $pax . ' pax',
                'details' => [$guide['name'] . ' as guide', $boat['name'] . ' as boat'],
                'cta' => $isSoldOutOnDate ? 'Unavailable for selected date' : 'Book Bundle',
                'url' => 'tour_booking.php?booking_type=tourguide&preferred=' . rawurlencode($guide['name']) . '&boat=' . rawurlencode($boat['name']) . '&return=' . rawurlencode('hotel_resorts.php?tab=bundle'),
                'is_disabled' => $isSoldOutOnDate
            ];

            if (count($bundleResults) >= 30) {
                break 2;
            }
        }
    }

    return $bundleResults;
}

if (defined('ITOUR_SEARCH_RESULTS_FUNCTIONS_ONLY') && ITOUR_SEARCH_RESULTS_FUNCTIONS_ONLY) {
    return;
}

$tab = normalizeSearchTab((string)($_GET['tab'] ?? 'hotels'));
$destination = trim((string)($_GET['destination'] ?? ''));
$destination2 = trim((string)($_GET['destination2'] ?? ''));
$destinationsFromList = array_filter(array_map('trim', explode('|', (string)($_GET['destinations'] ?? ''))));
$selectedDestinations = [];
foreach (array_merge([$destination, $destination2], $destinationsFromList) as $candidate) {
    if ($candidate === '' || in_array($candidate, $selectedDestinations, true)) {
        continue;
    }
    $selectedDestinations[] = $candidate;
}
$checkin = trim((string)($_GET['checkin'] ?? ''));
$checkout = trim((string)($_GET['checkout'] ?? ''));
$date = trim((string)($_GET['date'] ?? ''));
$tourDateMode = trim((string)($_GET['tour_date_mode'] ?? ''));
$tourType = trim((string)($_GET['tour_type'] ?? ''));
$tourDuration = trim((string)($_GET['tour_duration'] ?? ''));
$childAges = implode(',', array_slice(array_filter(
    array_map('trim', explode(',', (string)($_GET['child_ages'] ?? ''))),
    static fn($age) => preg_match('/^\d{1,2}$/', $age) === 1 && (int)$age >= 0 && (int)$age <= 17
), 0, max(0, (int)($_GET['children'] ?? 0))));
$selectedDate = normalizeSearchDate($checkin !== '' ? $checkin : $date);
$selectedEndDate = normalizeSearchDate($checkout);
if ($selectedEndDate === '') $selectedEndDate = $selectedDate;
$requestedTourType = normalizeTourTypeValue($tourType !== '' ? $tourType : $tourDateMode);
if ($requestedTourType === '') {
    $requestedTourType = inferTourTypeFromDuration($tourDuration);
}
$pax = (int)($_GET['pax'] ?? 0);
if ($pax <= 0) {
    $adults = (int)($_GET['adults'] ?? 1);
    $children = (int)($_GET['children'] ?? 0);
    $pax = max(1, $adults + $children);
}
$rooms = max(1, (int)($_GET['rooms'] ?? 1));

if (!isset($SEARCH_TABS[$tab])) {
    header('Location: hotel_resorts.php?tab=hotels');
    exit;
}

$tabConfig = $SEARCH_TABS[$tab];
$resultType = $tabConfig['result_type'];
$results = [];

switch ($resultType) {
    case 'hotels':
        $results = fetchHotelResults($pdo, $destination, $pax, $rooms);
        break;
    case 'tours':
        $results = fetchTourPackageResults($pdo, $destination, $pax, $selectedDate, $requestedTourType);
        break;
    case 'guides':
        $results = fetchGuideResults($pdo, $destination, $selectedDate, $selectedEndDate);
        break;
    case 'boats':
        $results = fetchBoatResults($pdo, $destination, $pax, $selectedDate, $selectedEndDate);
        break;
    case 'bundle':
        $results = fetchBundleResults($pdo, $destination, $pax, $selectedDate, $selectedEndDate);
        break;
}

$searchContext = array_filter([
    'tab' => $tab,
    'destination' => $destination,
    'destination2' => $destination2,
    'destinations' => !empty($selectedDestinations) ? implode('|', $selectedDestinations) : '',
    'checkin' => $checkin,
    'checkout' => $checkout,
    'date' => $date,
    'adults' => (string)($_GET['adults'] ?? ''),
    'children' => (string)($_GET['children'] ?? ''),
    'child_ages' => $childAges,
    'pax' => $pax > 0 ? (string)$pax : '',
    'rooms' => (string)($_GET['rooms'] ?? ''),
    'tour_date_mode' => $tourDateMode,
    'tour_type' => $tourType,
    'tour_duration' => $tourDuration
], static fn($value) => $value !== null && $value !== '');

$returnToResults = 'search_results.php';
if (!empty($searchContext)) {
    $returnToResults .= '?' . http_build_query($searchContext);
}

foreach ($results as &$item) {
    if ($resultType !== 'hotels') {
        $contextDetails = [];
        if ($selectedDate !== '') {
            $dateLabel = date('M j, Y', strtotime($selectedDate));
            if ($selectedEndDate !== '' && $selectedEndDate !== $selectedDate) {
                $dateLabel .= ' – ' . date('M j, Y', strtotime($selectedEndDate));
            }
            $contextDetails[] = $dateLabel;
        }
        $contextDetails[] = $pax . ' guest' . ($pax !== 1 ? 's' : '');
        $item['details'] = array_values(array_unique(array_filter(array_merge(
            (array)($item['details'] ?? []),
            $contextDetails
        ))));
    }

    $itemUrl = (string)($item['url'] ?? '');
    if ($itemUrl === '') {
        continue;
    }

    if (stripos($itemUrl, 'tour_booking.php') !== false) {
        $item['url'] = appendQueryToUrl($itemUrl, $searchContext + ['return' => $returnToResults]);
        continue;
    }

    if (stripos($itemUrl, 'package_details.php') !== false) {
        $item['url'] = appendQueryToUrl($itemUrl, $searchContext);
    }
}
unset($item);

// Keep bookable options ahead of unavailable matches on every screen size.
usort($results, static function (array $left, array $right): int {
    return (int) (!empty($left['is_disabled'])) <=> (int) (!empty($right['is_disabled']));
});

$resultCount = count($results);
$backUrl = 'hotel_resorts.php?tab=' . rawurlencode($tab);
$locationChoices = array_values(array_unique(array_filter(array_merge(
    ['Apuao', 'Malasugui', 'Quinapaguian', 'Cayucyucan', 'Caringo', 'Canimog'],
    $selectedDestinations
))));
sort($locationChoices, SORT_NATURAL | SORT_FLAG_CASE);
$primaryDestination = $selectedDestinations[0] ?? $destination;
$secondaryDestination = $selectedDestinations[1] ?? $destination2;
$searchStartDate = $checkin !== '' ? $checkin : $date;
$isSameDaySearch = $requestedTourType === 'same-day';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title><?= htmlspecialchars($tabConfig['label']) ?> - Search Results</title>
    <link rel="icon" type="image/png" href="img/newlogo.png" />
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet" />
    <link rel="stylesheet" href="styles/unified_search.css?v=<?= (int) @filemtime(__DIR__ . '/../styles/unified_search.css') ?>" />
</head>

<body class="results-page results-type-<?= htmlspecialchars($resultType) ?>">
    <div id="header"></div>

    <main class="results-container">
        <section class="results-intro" aria-labelledby="results-title">
            <div class="results-header">
                <p class="results-eyebrow">Explore Mercedes</p>
                <h1 id="results-title">
                    <span class="results-desktop-title"><?= htmlspecialchars($tabConfig['label']) ?></span>
                    <span class="results-mobile-title"><?= htmlspecialchars($tabConfig['label']) ?> available options</span>
                </h1>
                <span class="results-mobile-count"><?= $resultCount ?> result<?= $resultCount !== 1 ? 's' : '' ?></span>
                <p class="results-subtitle">
                    <?= $resultCount === 1 ? '1 option matches' : number_format($resultCount) . ' options match' ?> your search criteria
                </p>
            </div>
        </section>

        <section class="results-search-panel" aria-label="Refine your search">
            <button
                class="results-search-toggle"
                id="resultsSearchToggle"
                type="button"
                aria-expanded="false"
                aria-controls="resultsSearchForm"
            >
                <span class="results-search-toggle-icon"><i data-lucide="search" aria-hidden="true"></i></span>
                <span class="results-search-toggle-copy">
                    <strong>Search your trip</strong>
                    <small id="resultsSearchSummary"><?= htmlspecialchars($primaryDestination ?: 'Choose destination') ?> · <?= htmlspecialchars($searchStartDate ?: 'Choose dates') ?> · <?= $pax ?> guest<?= $pax !== 1 ? 's' : '' ?></small>
                </span>
                <i class="results-search-toggle-chevron" data-lucide="chevron-down" aria-hidden="true"></i>
            </button>

            <div class="results-search-heading">
                <span class="results-search-heading-icon"><i data-lucide="search" aria-hidden="true"></i></span>
                <div>
                    <h2 id="refine-search-title">Refine your search</h2>
                    <p>Change your details and update the available options.</p>
                </div>
            </div>

            <form class="results-search-form" id="resultsSearchForm" action="search_results.php" method="get">
                <input type="hidden" name="tab" value="<?= htmlspecialchars($tab) ?>">

                <div class="results-search-field results-search-field--destination">
                    <label for="resultsDestination">Destination</label>
                    <div class="results-search-control">
                        <i data-lucide="map-pin" aria-hidden="true"></i>
                        <select id="resultsDestination" name="destination" required>
                            <option value="">Select destination</option>
                            <?php foreach ($locationChoices as $locationChoice): ?>
                                <option value="<?= htmlspecialchars($locationChoice) ?>" <?= strcasecmp($primaryDestination, $locationChoice) === 0 ? 'selected' : '' ?>><?= htmlspecialchars($locationChoice) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <?php if ($resultType !== 'hotels'): ?>
                <div class="results-search-field results-search-field--destination">
                    <label for="resultsDestination2">Second destination <span>Optional</span></label>
                    <div class="results-search-control">
                        <i data-lucide="map-pinned" aria-hidden="true"></i>
                        <select id="resultsDestination2" name="destination2">
                            <option value="">Add destination</option>
                            <?php foreach ($locationChoices as $locationChoice): ?>
                                <option value="<?= htmlspecialchars($locationChoice) ?>" <?= strcasecmp($secondaryDestination, $locationChoice) === 0 ? 'selected' : '' ?>><?= htmlspecialchars($locationChoice) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <?php endif; ?>

                <?php if ($resultType === 'tours'): ?>
                <div class="results-search-field results-search-field--mode">
                    <label for="resultsTourMode">Trip type</label>
                    <div class="results-search-control">
                        <i data-lucide="clock-3" aria-hidden="true"></i>
                        <select id="resultsTourMode" name="tour_date_mode">
                            <option value="overnight" <?= !$isSameDaySearch ? 'selected' : '' ?>>Overnight</option>
                            <option value="same-day" <?= $isSameDaySearch ? 'selected' : '' ?>>Same day</option>
                        </select>
                    </div>
                </div>
                <input type="hidden" id="resultsTourType" name="tour_type" value="<?= $isSameDaySearch ? 'same-day' : 'overnight' ?>">
                <input type="hidden" id="resultsTourDuration" name="tour_duration" value="<?= htmlspecialchars($tourDuration) ?>">
                <?php endif; ?>

                <div class="results-search-field results-search-field--date">
                    <label for="resultsStartDate"><?= $resultType === 'hotels' ? 'Check-in' : 'Travel date' ?></label>
                    <div class="results-search-control">
                        <i data-lucide="calendar-days" aria-hidden="true"></i>
                        <input id="resultsStartDate" type="date" name="<?= in_array($resultType, ['guides', 'boats', 'bundle'], true) ? 'date' : 'checkin' ?>" value="<?= htmlspecialchars($searchStartDate) ?>" required>
                    </div>
                </div>

                <?php if (in_array($resultType, ['hotels', 'tours'], true)): ?>
                <div class="results-search-field results-search-field--date" id="resultsEndDateField" <?= $resultType === 'tours' && $isSameDaySearch ? 'hidden' : '' ?>>
                    <label for="resultsEndDate"><?= $resultType === 'hotels' ? 'Check-out' : 'Return date' ?></label>
                    <div class="results-search-control">
                        <i data-lucide="calendar-check" aria-hidden="true"></i>
                        <input id="resultsEndDate" type="date" name="checkout" value="<?= htmlspecialchars($checkout) ?>" <?= $resultType === 'tours' && $isSameDaySearch ? 'disabled' : 'required' ?>>
                    </div>
                </div>
                <?php endif; ?>

                <div class="results-search-field results-search-field--number">
                    <label for="resultsPax">Guests</label>
                    <div class="results-search-control">
                        <i data-lucide="users" aria-hidden="true"></i>
                        <input id="resultsPax" type="number" name="pax" min="1" max="50" value="<?= $pax ?>" required>
                    </div>
                </div>

                <?php if ($resultType === 'hotels'): ?>
                <div class="results-search-field results-search-field--number">
                    <label for="resultsRooms">Rooms</label>
                    <div class="results-search-control">
                        <i data-lucide="bed-double" aria-hidden="true"></i>
                        <input id="resultsRooms" type="number" name="rooms" min="1" max="10" value="<?= $rooms ?>" required>
                    </div>
                </div>
                <?php endif; ?>

                <div class="results-search-submit">
                    <button type="submit">
                        <i data-lucide="search" aria-hidden="true"></i>
                        <span class="results-update-label-full">Update results</span>
                        <span class="results-update-label-tablet">Update</span>
                    </button>
                </div>
            </form>
        </section>

        <svg class="results-icon-sprite" aria-hidden="true">
            <defs>
                <symbol id="results-icon-pin" viewBox="0 0 24 24"><path d="M20 10c0 5-8 12-8 12S4 15 4 10a8 8 0 1 1 16 0Z"></path><circle cx="12" cy="10" r="3"></circle></symbol>
                <symbol id="results-icon-star" viewBox="0 0 24 24"><path d="m12 2 3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2Z"></path></symbol>
                <symbol id="results-icon-check" viewBox="0 0 24 24"><path d="m5 12 4 4L19 6"></path></symbol>
                <symbol id="results-icon-calendar-x" viewBox="0 0 24 24"><path d="M8 2v4M16 2v4M3 10h18M5 4h14a2 2 0 0 1 2 2v13a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2Z"></path><path d="m10 14 4 4m0-4-4 4"></path></symbol>
                <symbol id="results-icon-arrow" viewBox="0 0 24 24"><path d="M5 12h14m-6-6 6 6-6 6"></path></symbol>
            </defs>
        </svg>

        <?php if (empty($results)): ?>
            <div class="no-results">
                <span class="no-results-icon"><i data-lucide="search-x" aria-hidden="true"></i></span>
                <h2>No results found</h2>
                <p>We could not find an exact match. Try changing your destination, dates, or group size.</p>
                <a href="<?= htmlspecialchars($backUrl) ?>" class="result-card-button no-results-btn">Modify search</a>
            </div>
        <?php else: ?>
            <div class="results-list-heading">
                <div>
                    <h2>Available options</h2>
                    <p>Choose an option to view details and continue your booking.</p>
                </div>
                <span class="results-count"><?= $resultCount ?> result<?= $resultCount !== 1 ? 's' : '' ?></span>
            </div>
            <div class="results-grid">
                <?php foreach ($results as $resultIndex => $item): ?>
                    <article class="result-card<?= $resultType === 'tours' ? ' result-card--with-rating' : '' ?>"<?= empty($item['is_disabled']) ? ' data-result-url="' . htmlspecialchars($item['url'], ENT_QUOTES, 'UTF-8') . '"' : '' ?>>
                        <div class="result-card-media">
                            <img
                                src="<?= htmlspecialchars($item['image']) ?>"
                                alt="<?= htmlspecialchars($item['name']) ?>"
                                class="result-card-image"
                                loading="<?= $resultIndex < 4 ? 'eager' : 'lazy' ?>"
                                decoding="async"
                                fetchpriority="<?= $resultIndex === 0 ? 'high' : 'auto' ?>"
                                width="640"
                                height="360"
                                onerror="this.src='img/sampleimage.png'"
                            />
                            <span class="result-card-badge"><?= htmlspecialchars($item['type']) ?></span>
                            <?php if ($resultType !== 'hotels'): ?>
                                <span class="result-card-status <?= empty($item['is_disabled']) ? 'is-available' : 'is-unavailable' ?>">
                                    <?= empty($item['is_disabled']) ? 'Available' : 'Unavailable' ?>
                                </span>
                            <?php endif; ?>
                        </div>
                        <div class="result-card-content">
                            <div class="result-card-main">
                                <h3 class="result-card-title"><?= htmlspecialchars($item['name']) ?></h3>
                                <div class="result-card-location">
                                    <svg aria-hidden="true"><use href="#results-icon-pin"></use></svg>
                                    <span><?= htmlspecialchars($item['location']) ?></span>
                                </div>
                                <?php if (!empty($item['rating'])): ?>
                                    <div class="result-card-rating" aria-label="Rated <?= number_format((float)$item['rating'], 1) ?> out of 5">
                                        <svg aria-hidden="true"><use href="#results-icon-star"></use></svg>
                                        <strong><?= number_format((float)$item['rating'], 1) ?></strong>
                                        <span>Guest rating</span>
                                    </div>
                                <?php elseif ($resultType === 'tours'): ?>
                                    <div class="result-card-rating result-card-rating--empty" aria-label="No ratings yet">
                                        <svg aria-hidden="true"><use href="#results-icon-star"></use></svg>
                                        <span>No ratings yet</span>
                                    </div>
                                <?php endif; ?>
                                <?php if (!empty($item['details'])): ?>
                                    <div class="result-card-details" aria-label="Option details">
                                        <?php foreach (array_slice($item['details'], 0, 3) as $detail): ?>
                                            <span><svg aria-hidden="true"><use href="#results-icon-check"></use></svg><?= htmlspecialchars($detail) ?></span>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                                <?php if (!empty($item['description'])): ?>
                                    <p class="result-card-description"><?= htmlspecialchars($item['description']) ?></p>
                                <?php endif; ?>
                            </div>
                            <div class="result-card-footer">
                                <?php if (!empty($item['price'])): ?>
                                    <div class="result-card-price-wrap">
                                        <span>Starting from</span>
                                        <div class="result-card-price"><?= htmlspecialchars($item['price']) ?></div>
                                    </div>
                                <?php endif; ?>
                                <?php if (!empty($item['is_disabled'])): ?>
                                    <span class="result-card-availability">
                                        <svg aria-hidden="true"><use href="#results-icon-calendar-x"></use></svg>
                                        <span><?= htmlspecialchars($item['cta']) ?></span>
                                    </span>
                                <?php else: ?>
                                    <a class="result-card-button" href="<?= htmlspecialchars($item['url']) ?>">
                                        <span class="result-card-button-desktop-label"><?= htmlspecialchars($item['cta']) ?></span>
                                        <span class="result-card-button-mobile-label">Book now</span>
                                        <svg aria-hidden="true"><use href="#results-icon-arrow"></use></svg>
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </main>

    <div id="footer"></div>

    <script>
        function removeFilter(filterType) {
            const params = new URLSearchParams(window.location.search);
            if (filterType === "destination") {
                params.delete("destination");
                params.delete("destination2");
                params.delete("destinations");
            }
            if (filterType === "dates") {
                params.delete("checkin");
                params.delete("checkout");
            }
            if (filterType === "date") params.delete("date");
            if (filterType === "pax") {
                params.delete("pax");
                params.delete("adults");
                params.delete("children");
            }
            if (filterType === "rooms") params.delete("rooms");
            if (filterType === "tourmeta") {
                params.delete("tour_date_mode");
                params.delete("tour_type");
                params.delete("tour_duration");
            }
            window.location.search = params.toString();
        }
        document.addEventListener('DOMContentLoaded', () => {
            if (window.lucide && typeof window.lucide.createIcons === 'function') {
                window.lucide.createIcons();
            }

            const searchForm = document.getElementById('resultsSearchForm');
            const searchPanel = document.querySelector('.results-search-panel');
            const searchToggle = document.getElementById('resultsSearchToggle');
            const searchSummary = document.getElementById('resultsSearchSummary');
            const startDate = document.getElementById('resultsStartDate');
            const endDate = document.getElementById('resultsEndDate');
            const endDateField = document.getElementById('resultsEndDateField');
            const tourMode = document.getElementById('resultsTourMode');
            const tourType = document.getElementById('resultsTourType');
            const tourDuration = document.getElementById('resultsTourDuration');
            const primaryDestination = document.getElementById('resultsDestination');
            const secondaryDestination = document.getElementById('resultsDestination2');
            const paxInput = document.getElementById('resultsPax');

            const formatShortDate = (value) => {
                if (!value) return '';
                const parsed = new Date(`${value}T00:00:00`);
                if (Number.isNaN(parsed.getTime())) return value;
                return parsed.toLocaleDateString(undefined, { month: 'short', day: 'numeric' });
            };

            const syncSearchSummary = () => {
                if (!searchSummary) return;
                const destinations = [primaryDestination?.value, secondaryDestination?.value].filter(Boolean).join(' + ');
                const dates = [formatShortDate(startDate?.value), formatShortDate(endDate?.disabled ? '' : endDate?.value)].filter(Boolean).join('–');
                const guests = Math.max(1, Number.parseInt(paxInput?.value || '1', 10) || 1);
                searchSummary.textContent = `${destinations || 'Choose destination'} · ${dates || 'Choose dates'} · ${guests} guest${guests === 1 ? '' : 's'}`;
            };

            searchToggle?.addEventListener('click', () => {
                const expanded = searchPanel?.classList.toggle('is-expanded') ?? false;
                searchToggle.setAttribute('aria-expanded', String(expanded));
                if (expanded) {
                    window.requestAnimationFrame(() => searchPanel?.scrollIntoView({ behavior: 'smooth', block: 'start' }));
                }
            });

            searchForm?.addEventListener('change', syncSearchSummary);
            searchForm?.addEventListener('input', syncSearchSummary);

            const syncTourDates = () => {
                if (!tourMode || !endDate || !endDateField) return;
                const isSameDay = tourMode.value === 'same-day';
                endDateField.hidden = isSameDay;
                endDate.disabled = isSameDay;
                endDate.required = !isSameDay;
                if (tourType) tourType.value = isSameDay ? 'same-day' : 'overnight';
            };

            tourMode?.addEventListener('change', syncTourDates);
            endDate?.addEventListener('input', () => endDate.setCustomValidity(''));
            secondaryDestination?.addEventListener('change', () => secondaryDestination.setCustomValidity(''));
            syncTourDates();
            syncSearchSummary();

            const mobileResults = window.matchMedia('(max-width: 700px)');
            document.querySelectorAll('.result-card[data-result-url]').forEach((card) => {
                const syncCardAccessibility = () => {
                    if (mobileResults.matches) {
                        card.setAttribute('role', 'link');
                        card.setAttribute('tabindex', '0');
                        card.setAttribute('aria-label', `View ${card.querySelector('.result-card-title')?.textContent?.trim() || 'details'}`);
                    } else {
                        card.removeAttribute('role');
                        card.removeAttribute('tabindex');
                        card.removeAttribute('aria-label');
                    }
                };

                card.addEventListener('click', (event) => {
                    if (!mobileResults.matches || event.target.closest('a, button, input, select, textarea')) return;
                    window.location.assign(card.dataset.resultUrl);
                });
                card.addEventListener('keydown', (event) => {
                    if (!mobileResults.matches || (event.key !== 'Enter' && event.key !== ' ')) return;
                    event.preventDefault();
                    window.location.assign(card.dataset.resultUrl);
                });
                mobileResults.addEventListener?.('change', syncCardAccessibility);
                syncCardAccessibility();
            });

            searchForm?.addEventListener('submit', (event) => {
                if (secondaryDestination?.value && secondaryDestination.value === primaryDestination?.value) {
                    event.preventDefault();
                    secondaryDestination.setCustomValidity('Please choose a different second destination.');
                    secondaryDestination.reportValidity();
                    return;
                }
                secondaryDestination?.setCustomValidity('');

                if (endDate && !endDate.disabled && startDate?.value && endDate.value) {
                    const start = new Date(`${startDate.value}T00:00:00`);
                    const end = new Date(`${endDate.value}T00:00:00`);
                    if (end <= start) {
                        event.preventDefault();
                        endDate.setCustomValidity('The return date must be after the start date.');
                        endDate.reportValidity();
                        return;
                    }
                    endDate.setCustomValidity('');
                }

                if (tourMode && tourDuration && startDate?.value) {
                    if (tourMode.value === 'same-day') {
                        tourDuration.value = '1 Day';
                    } else if (endDate?.value) {
                        const start = new Date(`${startDate.value}T00:00:00`);
                        const end = new Date(`${endDate.value}T00:00:00`);
                        const nights = Math.round((end - start) / 86400000);
                        tourDuration.value = `${nights + 1} Days ${nights} Night${nights !== 1 ? 's' : ''}`;
                    }
                }
            });
        });
    </script>
    <script src="https://unpkg.com/lucide@0.469.0/dist/umd/lucide.min.js"></script>
    <script src="includes/header_loader.js"></script>
</body>
</html>
