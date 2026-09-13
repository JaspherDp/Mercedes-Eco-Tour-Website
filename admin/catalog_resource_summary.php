<?php
$summary = $catalogResourceSummary ?? [];
$scheduleMax = max(1, ...array_map(static fn(array $day): int => (int)$day['count'], $summary['schedule'] ?? []));
?>
<aside class="catalog-summary-panel" aria-label="<?= htmlspecialchars($summary['label'] ?? 'Resource', ENT_QUOTES, 'UTF-8') ?> booking summary">
  <div class="catalog-summary-head">
    <div>
      <span class="catalog-summary-kicker">Operations snapshot</span>
      <h3>Booking summary</h3>
    </div>
    <span class="catalog-live-label"><i></i> Live</span>
  </div>

  <section class="catalog-utilization-card">
    <div class="catalog-utilization-copy">
      <span>Today's utilization</span>
      <strong><?= (int)($summary['utilization'] ?? 0) ?>%</strong>
    </div>
    <div class="catalog-progress" aria-label="Today's utilization is <?= (int)($summary['utilization'] ?? 0) ?> percent"><i style="width: <?= (int)($summary['utilization'] ?? 0) ?>%"></i></div>
    <div class="catalog-utilization-foot">
      <span><b><?= (int)($summary['occupied'] ?? 0) ?></b> active</span>
      <span><b><?= (int)($summary['available'] ?? 0) ?></b> available</span>
    </div>
  </section>

  <div class="catalog-summary-mini-grid">
    <div><span>Upcoming</span><strong><?= (int)($summary['upcoming_count'] ?? 0) ?></strong><small>accepted tours</small></div>
    <div><span>Pending</span><strong><?= (int)($summary['pending'] ?? 0) ?></strong><small>need review</small></div>
  </div>

  <section class="catalog-chart-card">
    <div class="catalog-summary-section-head"><h4>Seven-day workload</h4><span>Assignments</span></div>
    <div class="catalog-bar-chart" aria-label="Accepted assignments over the next seven days">
      <?php foreach (($summary['schedule'] ?? []) as $day):
          $height = (int)$day['count'] > 0 ? max(16, (int)round(((int)$day['count'] / $scheduleMax) * 100)) : 5;
      ?>
      <div class="catalog-bar-day" title="<?= htmlspecialchars($day['label'], ENT_QUOTES, 'UTF-8') ?>: <?= (int)$day['count'] ?> assignment<?= (int)$day['count'] === 1 ? '' : 's' ?>">
        <span><?= (int)$day['count'] ?></span>
        <i style="height: <?= $height ?>%"></i>
        <small><?= htmlspecialchars($day['label'], ENT_QUOTES, 'UTF-8') ?></small>
      </div>
      <?php endforeach; ?>
    </div>
  </section>

  <section class="catalog-upcoming-card">
    <div class="catalog-summary-section-head"><h4>Next assignments</h4><span><?= count($summary['upcoming'] ?? []) ?> shown</span></div>
    <div class="catalog-upcoming-list">
      <?php if (empty($summary['upcoming'])): ?>
        <div class="catalog-upcoming-empty">No accepted upcoming assignments.</div>
      <?php else: ?>
        <?php foreach ($summary['upcoming'] as $booking): ?>
        <article class="catalog-upcoming-item">
          <time datetime="<?= htmlspecialchars($booking['booking_date'], ENT_QUOTES, 'UTF-8') ?>"><b><?= date('d', strtotime($booking['booking_date'])) ?></b><span><?= date('M', strtotime($booking['booking_date'])) ?></span></time>
          <div>
            <strong><?= htmlspecialchars($booking['resource_name'], ENT_QUOTES, 'UTF-8') ?></strong>
            <p><?= htmlspecialchars($booking['package_name'] ?: $booking['location'], ENT_QUOTES, 'UTF-8') ?></p>
            <small><?= (int)$booking['pax'] ?> guest<?= (int)$booking['pax'] === 1 ? '' : 's' ?> · <?= htmlspecialchars($booking['tour_type'] === 'overnight' ? 'Overnight' : 'Day tour', ENT_QUOTES, 'UTF-8') ?></small>
          </div>
        </article>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </section>
</aside>
