<?php
require_once __DIR__ . '/../php/tour_resource_availability_helper.php';

$calendarType = ($resourceCalendarType ?? '') === 'boat' ? 'boat' : 'tourguide';
$calendarPrefix = $calendarType === 'boat' ? 'boatSchedule' : 'guideSchedule';
$calendarLabel = $calendarType === 'boat' ? 'Boat' : 'Tour guide';
$calendarPlural = $calendarType === 'boat' ? 'boats' : 'tour guides';
$calendarColumn = $calendarType === 'boat' ? 'boat_id' : 'guide_id';
$calendarTable = $calendarType === 'boat' ? 'boats' : 'tour_guides';
$calendarName = $calendarType === 'boat' ? 'name' : 'fullname';

$calendarStmt = $pdo->query("SELECT b.booking_id, b.booking_reference, b.booking_date, b.tour_type, b.tour_range,
    b.status, b.location, b.pax, b.num_adults, b.num_children, r.{$calendarName} AS resource_name,
    COALESCE(t.full_name, 'Tourist') AS tourist_name
    FROM bookings b
    INNER JOIN {$calendarTable} r ON r.{$calendarColumn} = b.{$calendarColumn}
    LEFT JOIN tourist t ON t.tourist_id = b.tourist_id
    WHERE b.{$calendarColumn} IS NOT NULL
      AND LOWER(COALESCE(b.status, '')) IN ('pending', 'accepted')
      AND LOWER(COALESCE(b.is_complete, 'uncomplete')) = 'uncomplete'
    ORDER BY b.booking_date ASC, b.booking_id ASC");
$calendarBookings = [];
foreach ($calendarStmt->fetchAll(PDO::FETCH_ASSOC) as $booking) {
    $booking['end_date'] = tourResourceEndDate($booking);
    $booking['pax'] = max((int)($booking['pax'] ?? 0), (int)($booking['num_adults'] ?? 0) + (int)($booking['num_children'] ?? 0));
    $calendarBookings[] = $booking;
}
?>
<nav class="resource-calendar-header-action" aria-label="<?= htmlspecialchars($calendarLabel) ?> schedule actions">
  <button type="button" class="resource-calendar-open" data-calendar-open="<?= $calendarPrefix ?>">
    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7 3v4M17 3v4M4 9h16"/><rect x="4" y="5" width="16" height="16" rx="2"/><path d="M8 13h3M13 13h3M8 17h3"/></svg>
    Calendar
  </button>
</nav>

<div class="resource-calendar-modal" id="<?= $calendarPrefix ?>Modal" aria-hidden="true">
  <div class="resource-calendar-backdrop" data-calendar-close></div>
  <section class="resource-calendar-dialog" role="dialog" aria-modal="true" aria-labelledby="<?= $calendarPrefix ?>Title">
    <header class="resource-calendar-dialog-head">
      <div class="resource-calendar-heading-icon"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7 3v4M17 3v4M4 9h16"/><rect x="4" y="5" width="16" height="16" rx="2"/></svg></div>
      <div><span>Operations schedule</span><h2 id="<?= $calendarPrefix ?>Title"><?= htmlspecialchars($calendarLabel) ?> booking calendar</h2><p>Review scheduled <?= htmlspecialchars($calendarPlural) ?> and daily assignments.</p></div>
      <button type="button" class="resource-calendar-close" data-calendar-close aria-label="Close calendar">&times;</button>
    </header>
    <div class="resource-calendar-body">
      <section class="resource-calendar-main">
        <div class="resource-calendar-toolbar">
          <button type="button" data-calendar-prev aria-label="Previous month"><svg viewBox="0 0 24 24"><path d="m15 18-6-6 6-6"/></svg></button>
          <div><strong data-calendar-month></strong><button type="button" data-calendar-today>Today</button></div>
          <button type="button" data-calendar-next aria-label="Next month"><svg viewBox="0 0 24 24"><path d="m9 18 6-6-6-6"/></svg></button>
        </div>
        <div class="resource-calendar-weekdays"><span>Sun</span><span>Mon</span><span>Tue</span><span>Wed</span><span>Thu</span><span>Fri</span><span>Sat</span></div>
        <div class="resource-calendar-grid" data-calendar-grid></div>
        <div class="resource-calendar-legend"><span><i class="accepted"></i>Accepted booking</span><span><i class="pending"></i>Pending request</span><span><i class="today"></i>Today</span></div>
      </section>
      <aside class="resource-calendar-agenda">
        <div class="resource-calendar-agenda-head"><span>Selected date</span><h3 data-agenda-date></h3><p data-agenda-summary></p></div>
        <div class="resource-calendar-agenda-list" data-agenda-list></div>
      </aside>
    </div>
  </section>
</div>

<script>
(() => {
  const prefix = <?= json_encode($calendarPrefix) ?>;
  const bookings = <?= json_encode($calendarBookings, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
  const modal = document.getElementById(prefix + 'Modal');
  if (!modal) return;
  const grid = modal.querySelector('[data-calendar-grid]');
  const monthLabel = modal.querySelector('[data-calendar-month]');
  const agendaDate = modal.querySelector('[data-agenda-date]');
  const agendaSummary = modal.querySelector('[data-agenda-summary]');
  const agendaList = modal.querySelector('[data-agenda-list]');
  const today = new Date(); today.setHours(0,0,0,0);
  let viewDate = new Date(today.getFullYear(), today.getMonth(), 1);
  let selectedDate = new Date(today);
  const key = d => `${d.getFullYear()}-${String(d.getMonth()+1).padStart(2,'0')}-${String(d.getDate()).padStart(2,'0')}`;
  const parse = value => { const p=String(value||'').split('-').map(Number); return p.length===3 ? new Date(p[0],p[1]-1,p[2]) : null; };
  const escape = value => String(value??'').replace(/[&<>'"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[c]));
  const onDate = dateKey => bookings.filter(item => String(item.booking_date) <= dateKey && String(item.end_date || item.booking_date) >= dateKey);

  function renderAgenda() {
    const dateKey = key(selectedDate), items = onDate(dateKey);
    agendaDate.textContent = selectedDate.toLocaleDateString('en-PH',{weekday:'long',month:'long',day:'numeric',year:'numeric'});
    agendaSummary.textContent = items.length ? `${items.length} scheduled assignment${items.length===1?'':'s'}` : 'No assignments scheduled';
    agendaList.innerHTML = items.length ? items.map(item => `
      <article class="resource-agenda-item ${escape(String(item.status).toLowerCase())}">
        <div class="resource-agenda-time"><strong>${escape(new Date(item.booking_date+'T00:00:00').toLocaleDateString('en-PH',{month:'short',day:'numeric'}))}</strong><span>${escape(item.tour_type==='overnight'?'Overnight':'Day tour')}</span></div>
        <div class="resource-agenda-copy"><div><h4>${escape(item.resource_name)}</h4><span class="resource-status ${escape(String(item.status).toLowerCase())}">${escape(item.status)}</span></div><p>${escape(item.tourist_name)} · ${escape(item.booking_reference || ('Booking #'+item.booking_id))}</p><small>${escape(item.location || 'Location not specified')} · ${Number(item.pax||0)} guest${Number(item.pax||0)===1?'':'s'}</small></div>
      </article>`).join('') : `<div class="resource-calendar-empty"><svg viewBox="0 0 24 24"><path d="M7 3v4M17 3v4M4 9h16"/><rect x="4" y="5" width="16" height="16" rx="2"/><path d="m9 15 2 2 4-4"/></svg><strong>No bookings this day</strong><p>Select a highlighted date to review its assignments.</p></div>`;
  }

  function renderCalendar() {
    monthLabel.textContent = viewDate.toLocaleDateString('en-PH',{month:'long',year:'numeric'});
    const first = new Date(viewDate.getFullYear(),viewDate.getMonth(),1), last = new Date(viewDate.getFullYear(),viewDate.getMonth()+1,0);
    const start = new Date(first); start.setDate(first.getDate()-first.getDay());
    const cells=[];
    for(let i=0;i<42;i++){
      const date=new Date(start); date.setDate(start.getDate()+i); const dateKey=key(date), items=onDate(dateKey);
      const accepted=items.filter(x=>String(x.status).toLowerCase()==='accepted').length, pending=items.length-accepted;
      const classes=['resource-calendar-day'];
      if(date.getMonth()!==viewDate.getMonth()) classes.push('outside');
      if(dateKey===key(today)) classes.push('today');
      if(dateKey===key(selectedDate)) classes.push('selected');
      if(items.length) classes.push('booked');
      const chipClass = accepted && pending ? 'mixed' : (pending ? 'pending-only' : 'accepted-only');
      const chipLabel = items.length === 1 ? (accepted ? '1 accepted' : '1 pending') : `${items.length} bookings`;
      cells.push(`<button type="button" class="${classes.join(' ')}" data-date="${dateKey}" aria-label="${escape(date.toLocaleDateString('en-PH'))}${items.length?', '+items.length+' bookings':''}"><span>${date.getDate()}</span>${items.length?`<b class="resource-booking-chip ${chipClass}">${chipLabel}</b>`:''}</button>`);
    }
    grid.innerHTML=cells.join('');
    grid.querySelectorAll('[data-date]').forEach(button=>button.addEventListener('click',()=>{ selectedDate=parse(button.dataset.date); viewDate=new Date(selectedDate.getFullYear(),selectedDate.getMonth(),1); renderCalendar(); renderAgenda(); }));
  }
  const open=()=>{modal.classList.add('open');modal.setAttribute('aria-hidden','false');document.body.classList.add('resource-calendar-opened');renderCalendar();renderAgenda();setTimeout(()=>modal.querySelector('[data-calendar-close]').focus(),30);};
  const close=()=>{modal.classList.remove('open');modal.setAttribute('aria-hidden','true');document.body.classList.remove('resource-calendar-opened');};
  document.querySelector(`[data-calendar-open="${prefix}"]`)?.addEventListener('click',open);
  modal.querySelectorAll('[data-calendar-close]').forEach(x=>x.addEventListener('click',close));
  modal.querySelector('[data-calendar-prev]').addEventListener('click',()=>{viewDate=new Date(viewDate.getFullYear(),viewDate.getMonth()-1,1);renderCalendar();});
  modal.querySelector('[data-calendar-next]').addEventListener('click',()=>{viewDate=new Date(viewDate.getFullYear(),viewDate.getMonth()+1,1);renderCalendar();});
  modal.querySelector('[data-calendar-today]').addEventListener('click',()=>{selectedDate=new Date(today);viewDate=new Date(today.getFullYear(),today.getMonth(),1);renderCalendar();renderAgenda();});
  document.addEventListener('keydown',event=>{if(event.key==='Escape'&&modal.classList.contains('open'))close();});
})();
</script>
