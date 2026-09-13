<section class="pc-hero">
  <img class="pc-hero-image" src="<?= htmlspecialchars($coverImagePath) ?>" alt="<?= htmlspecialchars($propertyName) ?>" />
  <div class="pc-hero-shade"></div>
  <button type="button" class="pc-cover-action" data-open-modal="hoMainImageModal" aria-label="Change cover photo">
    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 8h3l2-3h6l2 3h3v11H4z"/><circle cx="12" cy="13" r="3.5"/></svg>Change cover
  </button>
  <div class="pc-hero-content">
    <div class="pc-property-type"><?= htmlspecialchars($propertyType !== '' ? $propertyType : 'PROPERTY') ?></div>
    <h2><?= htmlspecialchars($propertyName !== '' ? $propertyName : 'Assigned Property') ?></h2>
    <p><?= htmlspecialchars($propertyTagline !== '' ? $propertyTagline : 'Add a memorable tagline that tells guests what makes your property special.') ?></p>
    <div class="pc-hero-meta">
      <span><svg viewBox="0 0 24 24"><path d="M12 21s7-6 7-12a7 7 0 1 0-14 0c0 6 7 12 7 12z"/><circle cx="12" cy="9" r="2"/></svg><?= htmlspecialchars($propertyIsland !== '' ? $propertyIsland . ', Mercedes' : 'Location not set') ?></span>
      <span><svg viewBox="0 0 24 24"><path d="M4 20V8l8-5 8 5v12M8 20v-6h8v6"/></svg>Public listing</span>
    </div>
  </div>
  <div class="pc-hero-actions">
    <button type="button" class="pc-button pc-button-light" data-open-modal="hoPropertyInfoModal">Edit property</button>
    <a class="pc-button pc-button-primary" href="hotel_details.php?id=<?= (int)$hoHotelResortId ?>" target="_blank" rel="noopener">Preview listing <span>↗</span></a>
  </div>
</section>

<section class="pc-publish-bar" aria-label="Listing completion">
  <div class="pc-score" style="--progress: <?= $contentCompletionPercent * 3.6 ?>deg"><strong><?= $contentCompletionPercent ?>%</strong></div>
  <div class="pc-publish-copy"><span>Listing readiness</span><h3><?= $contentCompletionPercent === 100 ? 'Your listing is ready to shine' : 'A few details will make your listing stronger' ?></h3><p><?= $completedContentSections ?> of <?= $totalContentSections ?> sections complete. Rich listings help guests book with confidence.</p></div>
  <div class="pc-progress-line"><span style="width: <?= $contentCompletionPercent ?>%"></span></div>
  <?php if ($contentCompletionPercent < 100): ?><button type="button" class="pc-next-button" data-scroll-incomplete>Continue setup <span>→</span></button><?php else: ?><a class="pc-next-button" href="hotel_details.php?id=<?= (int)$hoHotelResortId ?>" target="_blank" rel="noopener">View listing <span>→</span></a><?php endif; ?>
</section>

<nav class="pc-section-nav" aria-label="Property content sections">
  <span>Manage content</span>
  <a class="active" href="#pcOverview"><svg viewBox="0 0 24 24"><path d="M4 20V8l8-5 8 5v12M8 20v-6h8v6"/></svg>Overview</a>
  <a href="#pcExperience"><svg viewBox="0 0 24 24"><path d="M5 18h14M7 18V9h10v9M9 9V6h6v3"/></svg>Experience</a>
  <a href="#pcMedia"><svg viewBox="0 0 24 24"><rect x="3" y="5" width="18" height="14" rx="2"/><circle cx="9" cy="10" r="2"/><path d="m5 17 4-4 3 3 2-2 5 3"/></svg>Photos</a>
  <a href="#pcPolicies"><svg viewBox="0 0 24 24"><path d="M7 3h10v18H7zM10 8h4M10 12h4M10 16h3"/></svg>Policies</a>
  <a href="#pcLocation"><svg viewBox="0 0 24 24"><path d="M12 21s7-6 7-12a7 7 0 1 0-14 0c0 6 7 12 7 12z"/><circle cx="12" cy="9" r="2"/></svg>Location</a>
</nav>

<div class="pc-layout">
  <div class="pc-main-column">
    <section class="pc-section" id="pcOverview" data-complete="<?= ($descriptionText !== '' && ($contactPhone !== '' || $contactEmail !== '' || $propertyAddress !== '')) ? 'true' : 'false' ?>">
      <div class="pc-section-heading"><div><span class="pc-step">01</span><h3>Property overview</h3><p>Introduce your property and make it easy for guests to reach you.</p></div></div>
      <article class="pc-editor pc-editor-featured">
        <div class="pc-editor-head"><div class="pc-editor-icon"><svg viewBox="0 0 24 24"><path d="M5 4h14v16H5zM8 8h8M8 12h8M8 16h5"/></svg></div><div><h4>About your property</h4><span><?= str_word_count(strip_tags($descriptionText)) ?> words</span></div><button type="button" class="pc-edit" data-open-modal="hoDescriptionModal"><svg viewBox="0 0 24 24"><path d="m4 20 4-1 11-11-3-3L5 16zM14 7l3 3"/></svg>Edit</button></div>
        <div class="pc-description" data-expandable><?= nl2br(htmlspecialchars($descriptionText)) ?></div>
        <button type="button" class="pc-read-more" data-expand-text>Read full description <span>↓</span></button>
      </article>
      <article class="pc-editor">
        <div class="pc-editor-head"><div class="pc-editor-icon"><svg viewBox="0 0 24 24"><path d="M4 5h16v14H4zM4 8l8 6 8-6"/></svg></div><div><h4>Contact &amp; identity</h4><span>Public information guests can use</span></div><button type="button" class="pc-edit" data-open-modal="hoPropertyInfoModal"><svg viewBox="0 0 24 24"><path d="m4 20 4-1 11-11-3-3L5 16zM14 7l3 3"/></svg>Edit</button></div>
        <dl class="pc-detail-grid"><div class="<?= $contactPhone === '' ? 'is-missing' : '' ?>"><dt>Phone</dt><dd><?= htmlspecialchars($contactPhone !== '' ? $contactPhone : 'Add phone number') ?></dd></div><div class="<?= $contactEmail === '' ? 'is-missing' : '' ?>"><dt>Email</dt><dd><?= htmlspecialchars($contactEmail !== '' ? $contactEmail : 'Add email address') ?></dd></div><div class="pc-detail-wide <?= $propertyAddress === '' ? 'is-missing' : '' ?>"><dt>Address</dt><dd><?= htmlspecialchars($propertyAddress !== '' ? $propertyAddress : 'Add property address') ?></dd></div></dl>
      </article>
    </section>

    <section class="pc-section" id="pcExperience" data-complete="<?= (!empty($amenities) && !empty($propertyHighlights)) ? 'true' : 'false' ?>">
      <div class="pc-section-heading"><div><span class="pc-step">02</span><h3>Guest experience</h3><p>Turn property features into reasons to stay.</p></div></div>
      <div class="pc-editor-pair">
        <article class="pc-editor"><div class="pc-editor-head"><div class="pc-editor-icon"><svg viewBox="0 0 24 24"><path d="M4 18h16M6 18v-6h12v6M8 12V8h8v4"/></svg></div><div><h4>Amenities</h4><span><?= count($amenities) ?> feature<?= count($amenities) === 1 ? '' : 's' ?></span></div><button type="button" class="pc-edit pc-edit-icon" data-open-modal="hoFacilitiesModal"><svg viewBox="0 0 24 24"><path d="m4 20 4-1 11-11-3-3L5 16zM14 7l3 3"/></svg></button></div><?php if ($amenities): ?><div class="pc-tags"><?php foreach ($amenities as $facility): ?><span><i>✓</i><?= htmlspecialchars($facility) ?></span><?php endforeach; ?></div><?php else: ?><button type="button" class="pc-empty" data-open-modal="hoFacilitiesModal"><b>+</b><span><strong>Add amenities</strong>Show guests what is included.</span></button><?php endif; ?></article>
        <article class="pc-editor"><div class="pc-editor-head"><div class="pc-editor-icon"><svg viewBox="0 0 24 24"><path d="m12 3 2.2 5.3L20 9l-4.3 3.7L17 18l-5-2.8L7 18l1.3-5.3L4 9l5.8-.7z"/></svg></div><div><h4>Highlights</h4><span>Your best selling points</span></div><button type="button" class="pc-edit pc-edit-icon" data-open-modal="hoHighlightsModal"><svg viewBox="0 0 24 24"><path d="m4 20 4-1 11-11-3-3L5 16zM14 7l3 3"/></svg></button></div><?php if ($propertyHighlights): ?><ul class="pc-highlight-list"><?php foreach (array_slice($propertyHighlights, 0, 5) as $highlight): ?><li><span>✦</span><?= htmlspecialchars($highlight) ?></li><?php endforeach; ?></ul><?php else: ?><button type="button" class="pc-empty" data-open-modal="hoHighlightsModal"><b>+</b><span><strong>Add highlights</strong>Share memorable guest moments.</span></button><?php endif; ?></article>
      </div>
    </section>

    <section class="pc-section" id="pcMedia" data-complete="<?= !empty($galleryImages) ? 'true' : 'false' ?>">
      <div class="pc-section-heading"><div><span class="pc-step">03</span><h3>Photos &amp; visual story</h3><p>Help guests picture their stay before they arrive.</p></div><button type="button" class="pc-section-action" data-open-modal="hoGalleryModal">Manage photos</button></div>
      <?php if ($galleryImages): ?><div class="pc-gallery"><?php foreach (array_slice($galleryImages, 0, 5) as $index => $img): ?><button type="button" class="pc-gallery-item" data-gallery-view data-image="<?= htmlspecialchars((string)$img) ?>" aria-label="View photo <?= $index + 1 ?>"><img src="<?= htmlspecialchars((string)$img) ?>" alt="Gallery photo <?= $index + 1 ?>" /><?php if ($index === 4 && count($galleryImages) > 5): ?><span>+<?= count($galleryImages) - 5 ?> more</span><?php endif; ?></button><?php endforeach; ?></div><div class="pc-gallery-note"><span><?= count($galleryImages) ?> photos published</span><span>Tip: lead with bright, wide shots of your best spaces.</span></div><?php else: ?><button type="button" class="pc-photo-empty" data-open-modal="hoGalleryModal"><span><svg viewBox="0 0 24 24"><rect x="3" y="5" width="18" height="14" rx="2"/><circle cx="9" cy="10" r="2"/><path d="m5 17 4-4 3 3 2-2 5 3"/></svg></span><strong>Build your photo gallery</strong><small>Add inviting photos of rooms, views, dining, and guest spaces.</small><b>Upload photos</b></button><?php endif; ?>
    </section>

    <section class="pc-section" id="pcPolicies" data-complete="<?= ($checkinTime !== '' && $checkoutTime !== '' && $cancellationPolicy !== '') ? 'true' : 'false' ?>">
      <div class="pc-section-heading"><div><span class="pc-step">04</span><h3>Policies &amp; house rules</h3><p>Set clear expectations for a smooth guest experience.</p></div></div>
      <article class="pc-editor"><div class="pc-editor-head"><div class="pc-editor-icon"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg></div><div><h4>Stay details</h4><span>Arrival and booking conditions</span></div><button type="button" class="pc-edit" data-open-modal="hoGuestPoliciesModal"><svg viewBox="0 0 24 24"><path d="m4 20 4-1 11-11-3-3L5 16zM14 7l3 3"/></svg>Edit</button></div><div class="pc-time-grid"><div><span>Check-in</span><strong><?= htmlspecialchars(date('g:i A', strtotime($checkinTime))) ?></strong><small>Arrival time</small></div><div><span>Check-out</span><strong><?= htmlspecialchars(date('g:i A', strtotime($checkoutTime))) ?></strong><small>Departure time</small></div><div><span>Minimum stay</span><strong><?= $minimumStay ?> night<?= $minimumStay === 1 ? '' : 's' ?></strong><small>Booking length</small></div></div><div class="pc-policy-callout <?= $cancellationPolicy === '' ? 'is-missing' : '' ?>"><svg viewBox="0 0 24 24"><path d="M7 3h10v18H7zM10 8h4M10 12h4M10 16h3"/></svg><div><span>Cancellation policy</span><p><?= htmlspecialchars($cancellationPolicy !== '' ? $cancellationPolicy : 'No cancellation policy added yet. Add one to help guests book with confidence.') ?></p></div></div></article>
      <article class="pc-editor"><div class="pc-editor-head"><div class="pc-editor-icon"><svg viewBox="0 0 24 24"><path d="M7 3h10v18H7zM9 8h6M9 12h6M9 16h4"/></svg></div><div><h4>House rules</h4><span><?= count($rules) ?> guideline<?= count($rules) === 1 ? '' : 's' ?> for guests</span></div><button type="button" class="pc-edit" data-open-modal="hoRulesModal"><svg viewBox="0 0 24 24"><path d="m4 20 4-1 11-11-3-3L5 16zM14 7l3 3"/></svg>Edit</button></div><ul class="pc-rules"><?php foreach ($rules as $rule): ?><li><span>✓</span><?= htmlspecialchars($rule) ?></li><?php endforeach; ?></ul></article>
    </section>

    <section class="pc-section" id="pcLocation" data-complete="<?= ($transportInfo !== '' || !empty($nearbyAttractions)) ? 'true' : 'false' ?>">
      <div class="pc-section-heading"><div><span class="pc-step">05</span><h3>Location &amp; accessibility</h3><p>Give guests the practical details they need to arrive prepared.</p></div><button type="button" class="pc-section-action" data-open-modal="hoLocationInfoModal">Edit details</button></div>
      <div class="pc-location-grid"><article><span class="pc-location-icon"><svg viewBox="0 0 24 24"><path d="M3 17h18M5 17l2-7h10l2 7M8 17v3M16 17v3M8 13h8"/></svg></span><div><h4>Getting there</h4><p><?= htmlspecialchars($transportInfo !== '' ? $transportInfo : 'Add directions and transportation guidance.') ?></p><?php if ($parkingInfo !== ''): ?><small><?= htmlspecialchars($parkingInfo) ?></small><?php endif; ?></div></article><article><span class="pc-location-icon"><svg viewBox="0 0 24 24"><path d="M12 21s7-6 7-12a7 7 0 1 0-14 0c0 6 7 12 7 12z"/><circle cx="12" cy="9" r="2"/></svg></span><div><h4>Nearby attractions</h4><p><?= htmlspecialchars($nearbyAttractions ? implode(' • ', array_slice($nearbyAttractions, 0, 5)) : 'Add nearby beaches, dining, and activities.') ?></p></div></article><article><span class="pc-location-icon"><svg viewBox="0 0 24 24"><circle cx="12" cy="5" r="2"/><path d="M10 9h4l2 5h3M12 9v5l-3 5M8 12H5"/></svg></span><div><h4>Accessibility</h4><p><?= htmlspecialchars($accessibilityInfo !== '' ? $accessibilityInfo : 'Add accessibility information for guests.') ?></p></div></article></div>
    </section>
  </div>

  <aside class="pc-side-column">
    <div class="pc-side-card pc-checklist"><div class="pc-side-head"><div><span>Publishing checklist</span><h3>Build a complete listing</h3></div><b><?= $completedContentSections ?>/<?= $totalContentSections ?></b></div>
      <?php $checklistItems = [['Property description',$descriptionText !== '','hoDescriptionModal'],['Amenities',!empty($amenities),'hoFacilitiesModal'],['House rules',!empty($rules),'hoRulesModal'],['Cover photo',$coverImagePath !== '' && $coverImagePath !== 'img/sampleimage.png','hoMainImageModal'],['Photo gallery',!empty($galleryImages),'hoGalleryModal'],['Contact details',$contactPhone !== '' || $contactEmail !== '' || $propertyAddress !== '','hoPropertyInfoModal'],['Guest policies',$checkinTime !== '' && $checkoutTime !== '' && $cancellationPolicy !== '','hoGuestPoliciesModal'],['Highlights',!empty($propertyHighlights),'hoHighlightsModal'],['Location guide',$transportInfo !== '' || !empty($nearbyAttractions),'hoLocationInfoModal']]; ?>
      <div class="pc-checklist-items"><?php foreach ($checklistItems as [$label,$done,$modalId]): ?><button type="button" class="<?= $done ? 'is-done' : '' ?>" data-open-modal="<?= $modalId ?>"><i><?= $done ? '✓' : '+' ?></i><span><?= htmlspecialchars($label) ?></span><svg viewBox="0 0 24 24"><path d="m9 5 7 7-7 7"/></svg></button><?php endforeach; ?></div>
    </div>
    <div class="pc-side-card pc-tip-card"><span class="pc-tip-icon">✦</span><div><small>OWNER TIP</small><h3>Photos create the first impression</h3><p>A complete gallery builds trust and helps guests understand the experience.</p><button type="button" data-open-modal="hoGalleryModal">Improve gallery <span>→</span></button></div></div>
    <div class="pc-side-card pc-quick-preview"><span>Guest view</span><h3>See what travelers see</h3><p>Review your public page after every major update.</p><a href="hotel_details.php?id=<?= (int)$hoHotelResortId ?>" target="_blank" rel="noopener">Open public listing <span>↗</span></a></div>
  </aside>
</div>

<div class="pc-lightbox" aria-hidden="true"><button type="button" aria-label="Close photo preview">×</button><img src="" alt="Expanded property photo" /></div>
