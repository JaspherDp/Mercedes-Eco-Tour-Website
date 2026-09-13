(() => {
  const drawer = document.getElementById('hrDrawer');
  const form = document.getElementById('hrReviewForm');
  const toast = document.getElementById('hrToast');
  const bulk = document.getElementById('hrBulk');
  const checks = [...document.querySelectorAll('.hr-row-check')];
  const selectAll = document.getElementById('hrSelectAll');
  const escapeHtml = value => String(value ?? '').replace(/[&<>'"]/g, character => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#39;', '"': '&quot;'
  })[character]);
  const initials = name => {
    const parts = String(name || 'Guest').trim().split(/\s+/);
    return ((parts[0]?.[0] || 'G') + (parts.length > 1 ? parts[parts.length - 1][0] : '')).toUpperCase();
  };
  const fullDate = value => value
    ? new Date(String(value).replace(' ', 'T')).toLocaleString('en-PH', { dateStyle: 'medium', timeStyle: 'short' })
    : 'Not recorded';
  const stayDate = value => value
    ? new Date(String(value) + 'T00:00:00').toLocaleDateString('en-PH', { dateStyle: 'medium' })
    : 'Not recorded';
  const dataBox = (label, value) => '<div class="hr-data"><small>' + escapeHtml(label) + '</small><strong>' + escapeHtml(value || 'Not available') + '</strong></div>';

  function notify(message, error = false) {
    toast.textContent = message;
    toast.classList.toggle('error', error);
    toast.hidden = false;
    setTimeout(() => { toast.hidden = true; }, 3200);
  }

  function openReview(review) {
    const guest = review.tourist_name || review.reviewer_name || 'Guest';
    document.getElementById('hrReviewId').value = review.review_id;
    document.getElementById('hrStatus').value = review.moderation_status || 'published';
    document.getElementById('hrReply').value = review.owner_reply || '';
    document.getElementById('hrNote').value = review.internal_note || '';
    document.getElementById('hrDrawerSubtitle').textContent = 'Review #' + review.review_id + ' · ' + (review.booking_reference || 'Verified stay');
    document.getElementById('hrDrawerGuest').textContent = guest;
    document.getElementById('hrDrawerGuestMeta').textContent = (review.tourist_email || 'Verified guest') + ' · ' + (review.room_name || 'Room stay');

    const avatar = document.getElementById('hrDrawerAvatar');
    avatar.replaceChildren();
    if (review.profile_url) {
      const image = document.createElement('img');
      image.src = review.profile_url;
      image.alt = '';
      image.addEventListener('error', () => { avatar.textContent = initials(guest); });
      avatar.appendChild(image);
    } else {
      avatar.textContent = initials(guest);
    }

    const nights = Number(review.nights || 0);
    const adults = Number(review.adults || 0);
    const children = Number(review.children || 0);
    document.getElementById('hrStayDetails').innerHTML =
      dataBox('Room', review.room_name) +
      dataBox('Booking', review.booking_reference || '#' + review.hotel_booking_id) +
      dataBox('Check-in', stayDate(review.checkin_date)) +
      dataBox('Check-out', stayDate(review.checkout_date)) +
      dataBox('Length', nights ? nights + ' night' + (nights === 1 ? '' : 's') : 'Not recorded') +
      dataBox('Guests', adults + ' adult' + (adults === 1 ? '' : 's') + ' · ' + children + ' child' + (children === 1 ? '' : 'ren'));

    const roundedScore = Math.max(0, Math.min(5, Math.round(Number(review.rating) || 0)));
    document.getElementById('hrBigStars').textContent = '★'.repeat(roundedScore) + '☆'.repeat(5 - roundedScore);
    document.getElementById('hrScore').textContent = Number(review.rating || 0).toFixed(1) + ' out of 5';
    document.getElementById('hrMessage').textContent = review.review_message || 'No written feedback.';

    const ratings = [
      ['Location', review.location_rating], ['Service', review.service_rating],
      ['Value', review.value_rating], ['Cleanliness', review.cleanliness_rating],
      ['Facilities', review.facilities_rating], ['Room comfort', review.room_comfort_rating]
    ];
    document.getElementById('hrRatingGrid').innerHTML = ratings.map(item =>
      '<div class="hr-rating-item"><span>' + escapeHtml(item[0]) + '</span><strong>★ ' + Number(item[1] || 0).toFixed(1) + '</strong></div>'
    ).join('');
    document.getElementById('hrGuestDetails').innerHTML =
      dataBox('Name', guest) + dataBox('Email', review.tourist_email) +
      dataBox('Phone', review.tourist_phone) + dataBox('Address', review.tourist_address) +
      dataBox('Submitted', fullDate(review.created_at)) +
      dataBox('Current status', String(review.moderation_status || 'published').replace(/^./, character => character.toUpperCase()));
    drawer.hidden = false;
    document.body.style.overflow = 'hidden';
  }

  document.querySelectorAll('[data-hotel-review]').forEach(button => {
    button.addEventListener('click', () => {
      try { openReview(JSON.parse(button.dataset.hotelReview)); }
      catch (error) { notify('Unable to open this review.', true); }
    });
  });

  function closeDrawer() {
    drawer.hidden = true;
    document.body.style.overflow = '';
  }
  document.querySelector('.hr-close')?.addEventListener('click', closeDrawer);
  drawer?.addEventListener('click', event => { if (event.target === drawer) closeDrawer(); });
  document.addEventListener('keydown', event => { if (event.key === 'Escape' && !drawer.hidden) closeDrawer(); });

  async function submitData(data) {
    const response = await fetch('Horeviews.php', {
      method: 'POST',
      body: data,
      headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' }
    });
    const payload = await response.json();
    if (!response.ok || !payload.success) throw new Error(payload.message || 'Update failed.');
    return payload;
  }

  form?.addEventListener('submit', async event => {
    event.preventDefault();
    const button = form.querySelector('[type=submit]');
    button.disabled = true;
    button.textContent = 'Saving...';
    try {
      const result = await submitData(new FormData(form));
      notify(result.message);
      setTimeout(() => location.reload(), 650);
    } catch (error) {
      notify(error.message, true);
      button.disabled = false;
      button.textContent = 'Save review changes';
    }
  });

  function syncBulk() {
    const selected = checks.filter(check => check.checked).length;
    document.getElementById('hrSelected').textContent = selected;
    bulk.classList.toggle('active', selected > 0);
    if (selectAll) {
      selectAll.checked = selected === checks.length && selected > 0;
      selectAll.indeterminate = selected > 0 && selected < checks.length;
    }
  }
  checks.forEach(check => check.addEventListener('change', syncBulk));
  selectAll?.addEventListener('change', () => {
    checks.forEach(check => { check.checked = selectAll.checked; });
    syncBulk();
  });
  bulk?.addEventListener('submit', async event => {
    event.preventDefault();
    if (!event.submitter || !checks.some(check => check.checked)) return;
    const data = new FormData(bulk);
    data.set('status', event.submitter.value);
    event.submitter.disabled = true;
    try {
      const result = await submitData(data);
      notify(result.message);
      setTimeout(() => location.reload(), 650);
    } catch (error) {
      notify(error.message, true);
      event.submitter.disabled = false;
    }
  });
})();
