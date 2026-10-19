<?php
if (!isset($reviewResults, $reviews, $hotel)) { http_response_code(404); return; }
?>
<div data-review-results-fragment>
<?php if ($reviewResults['total'] > 0): ?>
<p class="review-results-count" role="status">
    Showing <?= $reviewResults['offset'] + 1 ?>&ndash;<?= $reviewResults['offset'] + count($reviews) ?> of <?= number_format($reviewResults['total']) ?> matching review<?= $reviewResults['total'] === 1 ? '' : 's' ?>
</p>
<?php endif; ?>

<!-- INDIVIDUAL REVIEWS -->
<div class="reviews-carousel <?= count($reviews) <= 2 ? 'is-static' : '' ?><?= !$reviews ? ' is-empty' : '' ?>">

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
      <p class="dummy-text"><?= (int)$hotel['total_reviews'] > 0 ? 'No reviews match your filters. Try another rating or search term.' : 'No reviews yet.' ?></p>
    <?php endif; ?>

  </div>

  <button type="button" class="reviews-nav next" id="reviewsNext">&#8250;</button>

</div>

<?php if ($reviewResults['pages'] > 1): ?>
  <nav class="review-pagination" aria-label="Guest review pages">
    <?php if ($reviewResults['page'] > 1): ?>
      <a href="<?= htmlspecialchars(hotelReviewFilterUrl($_GET, ['review_page' => $reviewResults['page'] - 1]), ENT_QUOTES, 'UTF-8') ?>" rel="prev">Previous reviews</a>
    <?php endif; ?>
    <span>Page <?= $reviewResults['page'] ?> of <?= $reviewResults['pages'] ?></span>
    <?php if ($reviewResults['page'] < $reviewResults['pages']): ?>
      <a href="<?= htmlspecialchars(hotelReviewFilterUrl($_GET, ['review_page' => $reviewResults['page'] + 1]), ENT_QUOTES, 'UTF-8') ?>" rel="next">Next reviews</a>
    <?php endif; ?>
  </nav>
<?php endif; ?>
</div>
