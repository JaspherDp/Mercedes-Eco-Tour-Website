<?php
declare(strict_types=1);

define('ITOUR_SEARCH_RESULTS_FUNCTIONS_ONLY', true);
require __DIR__ . '/search_results.php';

function destinationResultsH($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function destinationResultsSearchTerm(string $destination): string
{
    $normalized = strtolower(trim($destination));
    foreach (['Apuao', 'Malasugui', 'Quinapaguian', 'Cayucyucan', 'Caringo', 'Canimog', 'Canton'] as $knownDestination) {
        if (str_contains($normalized, strtolower($knownDestination))) {
            return $knownDestination;
        }
    }
    return preg_replace('/\s+island$/i', '', trim($destination)) ?: trim($destination);
}

function destinationResultsPrice(string $price): string
{
    $clean = preg_replace('/^[^0-9]+/u', '', trim($price));
    $clean = trim((string)$clean);
    if ($clean === '') return '';
    $numericAmount = (float)str_replace(',', '', preg_replace('/[^0-9.,].*$/u', '', $clean));
    return $numericAmount > 0 ? $clean : '';
}

function destinationResultsPrepare(array $items, string $category, string $categoryLabel, string $destination): array
{
    $prepared = [];
    foreach ($items as $item) {
        if (!empty($item['is_disabled'])) continue;
        $item['category'] = $category;
        $item['category_label'] = $categoryLabel;
        $item['destination_context'] = $destination;
        $prepared[] = $item;
    }
    return $prepared;
}

$destination = trim((string)($_GET['destination'] ?? ''));
$searchTerm = destinationResultsSearchTerm($destination);
$displayDestination = $destination !== '' ? $destination : 'Mercedes';

$catalog = [
    'hotels' => destinationResultsPrepare(fetchHotelResults($pdo, $searchTerm, 1, 1), 'hotels', 'Hotel & Resort', $displayDestination),
    'tours' => destinationResultsPrepare(fetchTourPackageResults($pdo, $searchTerm, 1, '', ''), 'tours', 'Tour Package', $displayDestination),
    'boats' => destinationResultsPrepare(fetchBoatResults($pdo, '', 1, ''), 'boats', 'Tour Boat', $displayDestination),
    'guides' => destinationResultsPrepare(fetchGuideResults($pdo, '', ''), 'guides', 'Tour Guide', $displayDestination),
];

$destinationReturnUrl = 'destination_results.php?' . http_build_query(['destination' => $destination]);
foreach ($catalog as &$categoryItems) {
    foreach ($categoryItems as &$categoryItem) {
        $itemUrl = (string)($categoryItem['url'] ?? '');
        if ($itemUrl === '') continue;
        if (stripos($itemUrl, 'tour_booking.php') !== false) {
            $categoryItem['url'] = appendQueryToUrl($itemUrl, [
                'destination' => $displayDestination,
                'return' => $destinationReturnUrl,
            ]);
        } elseif (stripos($itemUrl, 'hotel_details.php') !== false) {
            $categoryItem['url'] = appendQueryToUrl($itemUrl, ['source' => 'result']);
        } elseif (stripos($itemUrl, 'package_details.php') !== false) {
            $categoryItem['url'] = appendQueryToUrl($itemUrl, ['destination' => $displayDestination]);
        }
    }
    unset($categoryItem);
}
unset($categoryItems);

$allResults = array_merge($catalog['hotels'], $catalog['tours'], $catalog['boats'], $catalog['guides']);
$counts = array_map('count', $catalog);
$totalResults = count($allResults);
$tabs = [
    'all' => ['label' => 'All', 'count' => $totalResults],
    'hotels' => ['label' => 'Hotels', 'count' => $counts['hotels']],
    'tours' => ['label' => 'Tour Packages', 'count' => $counts['tours']],
    'boats' => ['label' => 'Boats', 'count' => $counts['boats']],
    'guides' => ['label' => 'Tour Guides', 'count' => $counts['guides']],
];
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= destinationResultsH($displayDestination) ?> | Explore Mercedes</title>
  <link rel="icon" type="image/png" href="img/newlogo.png">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="styles/destination_results.css?v=<?= (int)@filemtime(__DIR__ . '/../styles/destination_results.css') ?>">
  <link rel="stylesheet" href="styles/back-to-top.css?v=<?= (int)@filemtime(__DIR__ . '/../styles/back-to-top.css') ?>">
</head>
<body class="destination-results-page">
  <div id="header"></div>

  <main>
    <section class="destination-results-hero" aria-labelledby="destinationResultsTitle">
      <div class="destination-results-hero__glow" aria-hidden="true"></div>
      <div class="destination-results-shell destination-results-hero__inner">
        <a class="destination-results-back" href="./">
          <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m15 18-6-6 6-6"></path></svg>
          Back to home
        </a>
        <div class="destination-results-heading">
          <div>
            <span class="destination-results-eyebrow">Explore Mercedes</span>
            <h1 id="destinationResultsTitle">Everything you can book in <span><?= destinationResultsH($displayDestination) ?></span></h1>
          </div>
          <p>Compare trusted stays, curated tours, local guides, and tour boats for your next island escape.</p>
        </div>

        <form class="destination-results-search" action="destination_results.php" method="get" role="search" autocomplete="off">
          <label for="destinationResultsInput">Destination</label>
          <div class="destination-results-search__field">
            <div class="destination-results-search__control">
              <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20 10c0 5-8 12-8 12S4 15 4 10a8 8 0 1 1 16 0Z"></path><circle cx="12" cy="10" r="3"></circle></svg>
              <input id="destinationResultsInput" name="destination" value="<?= destinationResultsH($destination) ?>" placeholder="Try Apuao, Caringo, or Canimog" required role="combobox" aria-autocomplete="list" aria-expanded="false" aria-controls="destinationResultsSuggestions">
            </div>
          </div>
          <div class="destination-results-suggestions" id="destinationResultsSuggestions" role="listbox" aria-label="Destination suggestions" hidden></div>
          <button type="submit">
            <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"></circle><path d="m20 20-4-4"></path></svg>
            Update results
          </button>
        </form>
      </div>
    </section>

    <section class="destination-results-catalog destination-results-shell" aria-labelledby="destinationCatalogTitle">
      <header class="destination-results-catalog__header">
        <div>
          <span class="destination-results-kicker">Book your trip</span>
          <h2 id="destinationCatalogTitle">Choose your experience</h2>
        </div>
        <p id="destinationResultsSummary"><?= $totalResults ?> bookable option<?= $totalResults === 1 ? '' : 's' ?> across all services</p>
      </header>

      <nav class="destination-results-tabs" aria-label="Filter available services" role="tablist">
        <?php foreach ($tabs as $tabKey => $tab): ?>
          <button class="destination-results-tab<?= $tabKey === 'all' ? ' is-active' : '' ?>" type="button" role="tab" aria-selected="<?= $tabKey === 'all' ? 'true' : 'false' ?>" data-category="<?= destinationResultsH($tabKey) ?>">
            <span><?= destinationResultsH($tab['label']) ?></span>
            <small><?= (int)$tab['count'] ?></small>
          </button>
        <?php endforeach; ?>
      </nav>

      <?php if ($totalResults === 0): ?>
        <div class="destination-results-empty">
          <span><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"></circle><path d="m20 20-4-4M8.5 11h5"></path></svg></span>
          <h2>No bookable options found</h2>
          <p>Try a broader destination such as Apuao, Caringo, Canimog, or Malasugui.</p>
          <a href="destination.php">Explore all destinations</a>
        </div>
      <?php else: ?>
        <div class="destination-results-grid" id="destinationResultsGrid">
          <?php foreach ($allResults as $index => $item):
            $price = destinationResultsPrice((string)($item['price'] ?? ''));
            $details = array_slice(array_values(array_filter((array)($item['details'] ?? []))), 0, 2);
            $rating = isset($item['rating']) && is_numeric($item['rating']) ? (float)$item['rating'] : null;
          ?>
            <article class="destination-result-card" data-category="<?= destinationResultsH($item['category']) ?>">
              <a class="destination-result-card__media" href="<?= destinationResultsH($item['url'] ?? '#') ?>" aria-label="View <?= destinationResultsH($item['name'] ?? 'option') ?>">
                <img src="<?= destinationResultsH($item['image'] ?? 'img/sampleimage.png') ?>" alt="<?= destinationResultsH($item['name'] ?? '') ?>" loading="<?= $index < 8 ? 'eager' : 'lazy' ?>" decoding="async" onerror="this.onerror=null;this.src='img/sampleimage.png'">
                <span class="destination-result-card__type destination-result-card__type--<?= destinationResultsH($item['category']) ?>"><?= destinationResultsH($item['category_label']) ?></span>
                <span class="destination-result-card__available"><i></i> Bookable</span>
              </a>
              <div class="destination-result-card__body">
                <div class="destination-result-card__heading">
                  <h3><a href="<?= destinationResultsH($item['url'] ?? '#') ?>"><?= destinationResultsH($item['name'] ?? 'Bookable option') ?></a></h3>
                  <?php if ($rating !== null && $rating > 0): ?>
                    <span class="destination-result-card__rating" aria-label="Rated <?= number_format($rating, 1) ?> out of 5">★ <?= number_format($rating, 1) ?></span>
                  <?php endif; ?>
                </div>
                <p class="destination-result-card__location">
                  <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20 10c0 5-8 12-8 12S4 15 4 10a8 8 0 1 1 16 0Z"></path><circle cx="12" cy="10" r="3"></circle></svg>
                  <?= destinationResultsH($item['category'] === 'boats' || $item['category'] === 'guides' ? 'Available for ' . $displayDestination : ($item['location'] ?? $displayDestination)) ?>
                </p>
                <?php if (!empty($item['description'])): ?>
                  <p class="destination-result-card__description"><?= destinationResultsH($item['description']) ?></p>
                <?php endif; ?>
                <?php if ($details): ?>
                  <div class="destination-result-card__details">
                    <?php foreach ($details as $detail): ?><span>✓ <?= destinationResultsH($detail) ?></span><?php endforeach; ?>
                  </div>
                <?php endif; ?>
                <div class="destination-result-card__footer">
                  <div class="destination-result-card__price">
                    <small><?= $price !== '' ? 'Starting from' : 'Plan your trip' ?></small>
                    <strong><?= $price !== '' ? '&#8369;' . destinationResultsH($price) : 'See details' ?></strong>
                  </div>
                  <a class="destination-result-card__action" href="<?= destinationResultsH($item['url'] ?? '#') ?>">
                    <?= destinationResultsH($item['cta'] ?? 'View details') ?>
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12h14m-6-6 6 6-6 6"></path></svg>
                  </a>
                </div>
              </div>
            </article>
          <?php endforeach; ?>
        </div>
        <div class="destination-results-filter-empty" id="destinationFilterEmpty" hidden>No options are available in this category for <?= destinationResultsH($displayDestination) ?>.</div>
      <?php endif; ?>
    </section>
  </main>

  <div id="loginModal"></div>
  <div id="footer"></div>
  <script src="includes/header_loader.js?v=<?= (int)@filemtime(__DIR__ . '/../includes/header_loader.js') ?>"></script>
  <script src="js/destination_results.js?v=<?= (int)@filemtime(__DIR__ . '/../js/destination_results.js') ?>"></script>
  <script src="js/back-to-top.js?v=<?= (int)@filemtime(__DIR__ . '/../js/back-to-top.js') ?>"></script>
</body>
</html>
