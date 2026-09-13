<?php
chdir(__DIR__ . '/..');
require_once __DIR__ . '/../php/session_security.php';
AppSessionStart();
require_once 'php/db_connection.php';
require_once 'php/favorites_helper.php';
require_once 'php/service_catalog_helper.php';

$type = strtolower(trim((string)($_GET['type'] ?? 'boat')));
$type = in_array($type, ['boat', 'guide'], true) ? $type : 'boat';
$items = serviceCatalogFetch($pdo, $type);
$requestedId = max(0, (int)($_GET['id'] ?? 0));
$active = null;
foreach ($items as $item) {
    if ((int)$item['id'] === $requestedId) { $active = $item; break; }
}
if (!$active && $items) $active = $items[0];

$favoriteIds = favoriteIdsByType($pdo, (int)($_SESSION['tourist_id'] ?? 0));
$favoritesCsrf = favoriteCsrfToken();
$isBoat = $type === 'boat';
$tab = $isBoat ? 'boats' : 'guides';
$fallback = $isBoat ? 'img/default-boat.png' : 'img/default-guide.png';
$activeImages = $active ? array_map(static fn($image) => serviceCatalogImage((string)$image, $type), $active['images'] ?? []) : [];
if (!$activeImages) $activeImages = [$fallback];
$pageTitle = $active ? ($active['name'] . ' | iTour Mercedes') : 'Service unavailable | iTour Mercedes';

function serviceDetailH($value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
function serviceDetailRating(array $item): string {
    $rating = (float)($item['rating'] ?? 0);
    $reviews = (int)($item['total_reviews'] ?? 0);
    return number_format($rating, 1) . ' (' . $reviews . ')';
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= serviceDetailH($pageTitle) ?></title>
  <link rel="icon" type="image/png" href="img/newlogo.png">
  <link rel="stylesheet" href="styles/hotel_resorts.css">
  <link rel="stylesheet" href="styles/favorites.css">
  <link rel="stylesheet" href="styles/service_details.css?v=<?= (int)@filemtime(__DIR__ . '/../styles/service_details.css') ?>">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
</head>
<body class="service-page service-page--<?= $type ?>">
  <div id="header"><?php include 'php/header.php'; ?></div>

  <main class="service-page-main">
    <?php if (!$active): ?>
      <section class="service-empty">
        <span><i class="fa-solid <?= $isBoat ? 'fa-ship' : 'fa-user-tie' ?>" aria-hidden="true"></i></span>
        <h1>No <?= $isBoat ? 'boats' : 'tour guides' ?> are available right now</h1>
        <p>Please check again later or explore another tour service.</p>
        <a href="hotel_resorts.php?tab=<?= $tab ?>">Return to tours</a>
      </section>
    <?php else: ?>
      <div class="service-page-layout">
        <aside class="service-selector" aria-label="Choose another <?= $isBoat ? 'boat' : 'tour guide' ?>">
          <div class="service-selector-heading">
            <div class="service-selector-title">
              <a class="service-back" href="hotel_resorts.php?tab=<?= $tab ?>" aria-label="Back to <?= $isBoat ? 'boats' : 'tour guides' ?>" title="Back to <?= $isBoat ? 'boats' : 'tour guides' ?>">
                <i class="fa-solid fa-arrow-left" aria-hidden="true"></i>
              </a>
              <h1>Choose <?= $isBoat ? 'a boat' : 'your guide' ?></h1>
            </div>
            <div class="service-selector-label">
              <p><?= $isBoat ? 'Fleet' : 'Tour guides' ?></p>
              <span class="service-selector-count"><?= count($items) ?> <?= count($items) === 1 ? 'option' : 'options' ?></span>
            </div>
          </div>
          <div class="service-selector-grid" id="serviceSelectorGrid">
            <?php foreach ($items as $item):
              $isActive = (int)$item['id'] === (int)$active['id'];
              $thumbRaw = ($item['images'][0] ?? $item['img'] ?? '');
              $thumb = serviceCatalogImage((string)$thumbRaw, $type);
            ?>
              <a class="service-option-card<?= $isActive ? ' is-active' : '' ?>"
                 href="service_details.php?type=<?= $type ?>&amp;id=<?= (int)$item['id'] ?>"
                 data-service-id="<?= (int)$item['id'] ?>"
                 aria-current="<?= $isActive ? 'true' : 'false' ?>">
                <div class="service-option-image">
                  <img src="<?= serviceDetailH($thumb) ?>" alt="" loading="lazy" onerror="this.onerror=null;this.src='<?= $fallback ?>'">
                  <span class="service-active-indicator"><i class="fa-solid fa-check" aria-hidden="true"></i> Viewing</span>
                </div>
                <div class="service-option-copy">
                  <strong><?= serviceDetailH($item['name']) ?></strong>
                  <?php if ($isBoat): ?>
                    <?php
                      $boatCapacity = max(0, (int)($item['capacity'] ?? 0));
                      $boatSize = trim((string)($item['size'] ?? ''));
                      $boatNumber = trim((string)($item['boat_number'] ?? ''));
                      $boatRating = max(0, min(5, (float)($item['rating'] ?? 0)));
                      $boatReviews = max(0, (int)($item['total_reviews'] ?? 0));
                      $boatDescription = trim((string)($item['short_description'] ?? $item['long_description'] ?? 'Locally operated boat for island tours around Mercedes.'));
                    ?>
                    <p class="service-boat-description" title="<?= serviceDetailH($boatDescription) ?>"><?= serviceDetailH($boatDescription) ?></p>
                    <div class="service-boat-capsules" aria-label="Boat information">
                      <span><i class="fa-solid fa-user-group" aria-hidden="true"></i><?= $boatCapacity > 0 ? $boatCapacity . ' guests' : 'Private group' ?></span>
                      <?php if ($boatSize !== ''): ?><span><i class="fa-solid fa-ruler-combined" aria-hidden="true"></i><?= serviceDetailH($boatSize) ?></span><?php endif; ?>
                      <?php if ($boatNumber !== ''): ?><span><i class="fa-solid fa-hashtag" aria-hidden="true"></i><?= serviceDetailH($boatNumber) ?></span><?php endif; ?>
                    </div>
                    <div class="service-boat-review" aria-label="Rated <?= number_format($boatRating, 1) ?> out of 5 from <?= $boatReviews ?> reviews">
                      <span class="service-boat-stars" aria-hidden="true">
                        <?php for ($star = 1; $star <= 5; $star++): ?>
                          <i class="<?= $boatRating >= $star - 0.25 ? 'fa-solid fa-star' : ($boatRating >= $star - 0.75 ? 'fa-solid fa-star-half-stroke' : 'fa-regular fa-star') ?>"></i>
                        <?php endfor; ?>
                      </span>
                      <span class="service-boat-review-summary"><?= $boatReviews > 0 ? number_format($boatRating, 1) . ' (' . $boatReviews . ')' : 'No reviews yet' ?></span>
                    </div>
                  <?php else:
                    $guideExperience = max(0, (int)($item['experience'] ?? 0));
                    $guideAge = max(0, (int)($item['age'] ?? 0));
                    $guideRating = max(0, min(5, (float)($item['rating'] ?? 0)));
                    $guideReviews = max(0, (int)($item['total_reviews'] ?? 0));
                    $guideDescription = trim((string)($item['description'] ?? $item['specialization'] ?? 'Knowledgeable local guide for Mercedes tours.'));
                  ?>
                    <p class="service-guide-description" title="<?= serviceDetailH($guideDescription) ?>"><?= serviceDetailH($guideDescription) ?></p>
                    <div class="service-guide-capsules" aria-label="Guide information">
                      <span><i class="fa-solid fa-briefcase" aria-hidden="true"></i><?= $guideExperience > 0 ? $guideExperience . ' yr' . ($guideExperience === 1 ? '' : 's') . ' experience' : 'Local guide' ?></span>
                      <?php if ($guideAge > 0): ?><span><i class="fa-regular fa-id-card" aria-hidden="true"></i><?= $guideAge ?> years old</span><?php endif; ?>
                    </div>
                    <div class="service-guide-review" aria-label="Rated <?= number_format($guideRating, 1) ?> out of 5 from <?= $guideReviews ?> reviews">
                      <span class="service-guide-stars" aria-hidden="true">
                        <?php for ($star = 1; $star <= 5; $star++): ?>
                          <i class="<?= $guideRating >= $star - 0.25 ? 'fa-solid fa-star' : ($guideRating >= $star - 0.75 ? 'fa-solid fa-star-half-stroke' : 'fa-regular fa-star') ?>"></i>
                        <?php endfor; ?>
                      </span>
                      <span class="service-guide-review-summary"><?= $guideReviews > 0 ? number_format($guideRating, 1) . ' (' . $guideReviews . ')' : 'No reviews yet' ?></span>
                    </div>
                  <?php endif; ?>
                </div>
              </a>
            <?php endforeach; ?>
          </div>
        </aside>

        <article class="service-profile" id="serviceProfile" aria-live="polite">
          <div class="service-profile-scroll">
            <div class="service-profile-gallery" id="serviceProfileGallery">
              <img id="serviceMainImage" src="<?= serviceDetailH($activeImages[0]) ?>" alt="<?= serviceDetailH($active['name']) ?>">
              <div class="service-profile-gallery-shade"></div>
              <span class="service-profile-badge"><i class="fa-solid <?= $isBoat ? 'fa-ship' : 'fa-user-tie' ?>" aria-hidden="true"></i> <?= $isBoat ? 'Tour Boat' : 'Local Tour Guide' ?></span>
              <span class="service-profile-count" id="serviceImageCount">1 / <?= count($activeImages) ?></span>
              <button class="service-gallery-arrow prev" id="serviceImagePrev" type="button" aria-label="Previous image"><i class="fa-solid fa-chevron-left"></i></button>
              <button class="service-gallery-arrow next" id="serviceImageNext" type="button" aria-label="Next image"><i class="fa-solid fa-chevron-right"></i></button>
              <div class="service-profile-dots" id="serviceImageDots"></div>
            </div>

            <div class="service-profile-content">
              <div class="service-profile-title-row">
                <div>
                  <p class="service-profile-eyebrow" id="serviceEyebrow"><?= $isBoat ? 'Explore Mercedes by sea' : 'Meet your local guide' ?></p>
                  <h2 id="serviceName"><?= serviceDetailH($active['name']) ?></h2>
                  <div class="service-profile-rating"><span class="service-stars">★★★★★</span><strong id="serviceRating"><?= serviceDetailH(serviceDetailRating($active)) ?></strong></div>
                </div>
                <button class="favorite-toggle service-profile-favorite<?= !empty($favoriteIds[$type][(int)$active['id']]) ? ' is-favorite' : '' ?>"
                        id="serviceFavorite" type="button" data-favorite-type="<?= $type ?>" data-favorite-id="<?= (int)$active['id'] ?>"
                        aria-label="Add to favorites" aria-pressed="<?= !empty($favoriteIds[$type][(int)$active['id']]) ? 'true' : 'false' ?>">
                  <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 20.4c-5.2-3.4-8.4-6.2-8.4-10a4.8 4.8 0 0 1 8.4-3.1 4.8 4.8 0 0 1 8.4 3.1c0 3.8-3.2 6.6-8.4 10Z"></path></svg>
                </button>
              </div>

              <div class="service-profile-stats" id="serviceStats"></div>
              <section class="service-profile-about">
                <p>About this service</p>
                <h3 id="serviceAboutTitle"><?= $isBoat ? 'Your island journey starts here' : 'Explore with local knowledge' ?></h3>
                <div id="serviceDescription"></div>
              </section>
              <div class="service-confidence">
                <span><i class="fa-solid fa-shield-heart" aria-hidden="true"></i></span>
                <div><strong>Plan with confidence</strong><p>Booking requests are reviewed and confirmed by the Mercedes tourism team.</p></div>
              </div>
            </div>
          </div>

          <footer class="service-profile-footer">
            <div><span>Service rate</span><strong id="servicePrice"></strong><small id="servicePriceUnit"></small></div>
            <a id="serviceBookButton" href="#">Book now <i class="fa-solid fa-arrow-right" aria-hidden="true"></i></a>
          </footer>
        </article>
      </div>
    <?php endif; ?>
  </main>

  <script>
    window.ServiceDetailsConfig = {
      type: <?= json_encode($type) ?>,
      items: <?= json_encode($items, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
      activeId: <?= (int)($active['id'] ?? 0) ?>,
      favoriteIds: <?= json_encode($favoriteIds[$type] ?? []) ?>,
      fallback: <?= json_encode($fallback) ?>,
      backUrl: <?= json_encode('hotel_resorts.php?tab=' . $tab) ?>
    };
    window.RecentlyViewedConfig = {
      accountId: <?= json_encode((string)($_SESSION['tourist_id'] ?? '')) ?>
    };
    window.FavoritesConfig = { endpoint: 'php/favorites_api.php', csrfToken: <?= json_encode($favoritesCsrf) ?>, loginUrl: 'login.php' };
  </script>
  <script src="js/header.js?v=<?= (int)@filemtime(__DIR__ . '/../js/header.js') ?>"></script>
  <script src="js/favorites.js"></script>
  <script src="js/recently_viewed.js?v=<?= (int)@filemtime(__DIR__ . '/../js/recently_viewed.js') ?>"></script>
  <script src="js/service_details.js"></script>
  <script>if (typeof window.initHeader === 'function') window.initHeader();</script>
</body>
</html>
