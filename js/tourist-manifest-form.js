// Shared tourist-profile and Admin no-account walk-in passenger form.
const touristManifestConfig = window.TouristManifestConfig || {};
const touristManifestEndpoint = touristManifestConfig.endpoint || window.location.pathname;
const touristManifestLocationsEndpoint = touristManifestConfig.locationsEndpoint || 'geonames_locations.php';
const touristManifestFetch = (url, options) => {
  if (touristManifestConfig.adminWalkin) {
    options.body.set('action', options.body.has('fetch_tourists') ? 'fetch_walkin_manifest' : 'save_walkin_manifest');
    options.body.set('walkin_ajax', '1');
    options.body.set('csrf_token', document.querySelector('#touristFormUser [name="csrf_token"]').value);
  }
  return fetch(url, options);
};
let maxPax = 0;
let currentTouristCount = 0;
let touristBookingIsBoat = false;
let touristBookingHasMultipleBoats = false;

const touristModalUser = document.getElementById('touristModalUser');
const touristRowsUser = document.getElementById('touristRowsUser');
const bookingIdInput = document.getElementById('touristBookingId');
const touristAddressModal = document.getElementById('touristAddressModal');
let activeTouristAddressRow = null;
let touristManifestEditing = false;

const touristHtmlValue = value => String(value ?? '')
  .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
  .replace(/"/g, '&quot;').replace(/'/g, '&#039;');

function touristAddressFromRow(row) {
  const read = part => row.querySelector(`[data-address-part="${part}"]`)?.value.trim() || '';
  return {country:read('country'),region:read('region'),province:read('province'),city:read('city'),barangay:read('barangay'),postal_code:read('postal_code'),street:read('street')};
}

function touristAddressLine(address) {
  return [address.street,address.barangay,address.city,address.province,address.region,address.postal_code,address.country]
    .map(value => String(value || '').trim()).filter(Boolean).join(', ');
}

function setTouristRowAddress(row, address) {
  Object.entries(address).forEach(([part, value]) => {
    const input = row.querySelector(`[data-address-part="${part}"]`);
    if (input) input.value = value || '';
  });
  const line = touristAddressLine(address);
  (row.querySelector('input[name="address[]"]') || row.querySelector('#profileAddress')).value = line;
  row.querySelector('.address-display').textContent = line || 'Tap to enter the complete address';
  row.querySelector('.tourist-address-trigger').classList.toggle('has-address', Boolean(line));
}

function renumberTouristRows() {
  const previousScrollTop = touristRowsUser.scrollTop;
  const rows = Array.from(touristRowsUser.querySelectorAll('.tourist-row-user'));
  touristRowsUser.querySelectorAll('.tourist-boat-group').forEach(group => group.remove());
  if (touristBookingHasMultipleBoats) {
    for (let boat = 1; boat <= Math.ceil(maxPax / 8); boat++) {
      const members = rows.filter(row => Number(row.querySelector('[name="boat_number[]"]').value) === boat);
      const group = document.createElement('section');
      group.className = 'tourist-boat-group';
      group.innerHTML = `<h4>Boat ${boat} <span>${members.length}/8 tourists</span></h4><p>Enter the details of the tourists travelling on this boat.</p>`;
      members.forEach(row => group.appendChild(row));
      const add = document.createElement('button');
      add.type = 'button';
      add.className = 'btn add-more-user';
      add.textContent = `+ Add tourist to Boat ${boat}`;
      const capacityError = document.createElement('p');
      capacityError.className = 'tourist-boat-capacity-error';
      capacityError.id = `touristBoatCapacityError${boat}`;
      capacityError.hidden = true;
      capacityError.setAttribute('role', 'alert');
      add.setAttribute('aria-describedby', capacityError.id);
      add.addEventListener('click', () => {
        if (members.length >= 8 || rows.length >= maxPax) {
          capacityError.textContent = members.length >= 8
            ? `Maximum capacity for Boat ${boat} has been reached (8 tourists).`
            : `All ${maxPax} tourists for this booking have already been added.`;
          capacityError.hidden = false;
          return;
        }
        const addedRow = createTouristRowUser({boat_number:boat});
        group.appendChild(addedRow);
        renumberTouristRows();
        showAddedTouristRow(addedRow);
      });
      group.appendChild(add);
      group.appendChild(capacityError);
      touristRowsUser.appendChild(group);
    }
  }
  Array.from(touristRowsUser.querySelectorAll('.tourist-row-user')).forEach((row, index) => {
    row.querySelector('.tourist-row-number').textContent = index + 1;
    row.querySelector('.tourist-row-heading strong').textContent = `Tourist ${index + 1}`;
  });
  currentTouristCount = rows.length;
  document.getElementById('touristFormCount').textContent = `${currentTouristCount}/${maxPax}`;
  updateAddButtonState();
  touristRowsUser.scrollTop = previousScrollTop;
}

function showAddedTouristRow(row) {
  requestAnimationFrame(() => {
    row.querySelector('input[name="full_name[]"]')?.focus({preventScroll:true});
    row.scrollIntoView({block:'nearest', behavior:'smooth'});
  });
}

touristRowsUser.addEventListener('change', event => {
  if (!event.target.matches('[name="boat_number[]"]')) return;
  const select = event.target;
  const count = Array.from(touristRowsUser.querySelectorAll('[name="boat_number[]"]')).filter(input => input.value === select.value).length;
  if (count > 8) {
    alert(`Boat ${select.value} can carry a maximum of 8 tourists. Choose another boat.`);
    select.value = select.dataset.previousBoat;
    return;
  }
  select.dataset.previousBoat = select.value;
  renumberTouristRows();
});

/* ---------- HELPER: create ONE tourist row ---------- */
function createTouristRowUser(tourist = {}) {
  const row = document.createElement('div');
  row.className = 'tourist-row-user';
  const country = tourist.country || (String(tourist.residence || '').toLowerCase() === 'philippines' ? 'Philippines' : '');
  const address = {country,region:tourist.region || '',province:tourist.province || '',city:tourist.city || '',barangay:tourist.barangay || '',postal_code:tourist.postal_code || '',street:tourist.street || ''};
  const structuredAddressLine = touristAddressLine(address);
  const addressLine = structuredAddressLine || tourist.address || '';
  row.innerHTML = `
    <div class="tourist-row-heading"><span class="tourist-row-number">1</span><strong>Tourist 1</strong></div>
    ${touristBookingHasMultipleBoats ? `<label class="tourist-boat-choice">Assigned boat <select name="boat_number[]" aria-label="Assigned boat for this tourist">${Array.from({length:Math.ceil(maxPax / 8)}, (_, i) => `<option value="${i + 1}" ${Number(tourist.boat_number) === i + 1 ? 'selected' : ''}>Boat ${i + 1}</option>`).join('')}</select></label>` : (touristBookingIsBoat ? '<input type="hidden" name="boat_number[]" value="1">' : '')}
    <div class="tourist-row-grid">
      <label class="tourist-field name"><span>Full name</span><input type="text" name="full_name[]" maxlength="180" placeholder="Passenger's complete name" required value="${touristHtmlValue(tourist.full_name)}"></label>
      <label class="tourist-field"><span>Gender (by birth)</span><select name="gender[]" required><option value="">Select gender</option><option value="male" ${String(tourist.gender).toLowerCase() === 'male' ? 'selected' : ''}>Male</option><option value="female" ${String(tourist.gender).toLowerCase() === 'female' ? 'selected' : ''}>Female</option></select></label>
      <label class="tourist-field"><span>Age</span><input type="number" name="age[]" min="0" max="120" inputmode="numeric" placeholder="Age" required value="${touristHtmlValue(tourist.age)}"></label>
      <label class="tourist-field phone"><span>Contact number</span><input type="tel" name="phone_number[]" maxlength="50" placeholder="Mobile or telephone" required value="${touristHtmlValue(tourist.phone_number)}"></label>
      <div class="tourist-field address"><span>Complete address</span><button type="button" class="tourist-address-trigger"><span class="address-display">${touristHtmlValue(addressLine || 'Tap to enter the complete address')}</span><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M12 21s7-5.2 7-12a7 7 0 1 0-14 0c0 6.8 7 12 7 12Z"></path><circle cx="12" cy="9" r="2.5"></circle></svg></button></div>
    </div>
    <input type="hidden" name="address[]" value="${touristHtmlValue(addressLine)}">
    <input type="hidden" name="country[]" data-address-part="country" value="${touristHtmlValue(address.country)}">
    <input type="hidden" name="region[]" data-address-part="region" value="${touristHtmlValue(address.region)}">
    <input type="hidden" name="province[]" data-address-part="province" value="${touristHtmlValue(address.province)}">
    <input type="hidden" name="city[]" data-address-part="city" value="${touristHtmlValue(address.city)}">
    <input type="hidden" name="barangay[]" data-address-part="barangay" value="${touristHtmlValue(address.barangay)}">
    <input type="hidden" name="postal_code[]" data-address-part="postal_code" value="${touristHtmlValue(address.postal_code)}">
    <input type="hidden" name="street[]" data-address-part="street" value="${touristHtmlValue(address.street)}">
    <button type="button" class="remove-tourist-user" aria-label="Remove tourist">&times;</button>
  `;
  row.querySelector('.tourist-address-trigger').classList.toggle('has-address', Boolean(addressLine));
  if (touristBookingHasMultipleBoats) {
    const select = row.querySelector('[name="boat_number[]"]');
    const existing = Array.from(touristRowsUser.querySelectorAll('[name="boat_number[]"]'));
    const available = Array.from({length:Math.ceil(maxPax / 8)}, (_, i) => i + 1)
      .find(number => existing.filter(input => Number(input.value) === number).length < 8);
    select.value = String(Number(tourist.boat_number) || available || 1);
    select.dataset.previousBoat = select.value;
  }
  return row;
}

function closeTouristAddressModal() {
  touristAddressModal.classList.remove('show');
  touristAddressModal.setAttribute('aria-hidden', 'true');
  activeTouristAddressRow = null;
}

function addressModalField(part) {
  return document.getElementById(`touristAddress${part[0].toUpperCase()}${part.slice(1)}`);
}

const touristSearchSelects = new WeakMap();

function enhanceTouristSearchSelect(select) {
  if (touristSearchSelects.has(select)) return touristSearchSelects.get(select);
  const wrapper = document.createElement('div');
  wrapper.className = 'tourist-search-select';
  if (select.id === 'touristAddressCity' || select.id === 'touristAddressBarangay') {
    wrapper.classList.add('open-up');
  }
  const inputWrap = document.createElement('div');
  inputWrap.className = 'tourist-search-input-wrap';
  const input = document.createElement('input');
  input.type = 'text';
  input.className = 'tourist-search-input';
  input.autocomplete = 'off';
  input.setAttribute('role', 'combobox');
  input.setAttribute('aria-autocomplete', 'list');
  input.setAttribute('aria-expanded', 'false');
  const toggle = document.createElement('button');
  toggle.type = 'button';
  toggle.className = 'tourist-search-toggle';
  toggle.innerHTML = '&#9662;';
  toggle.setAttribute('aria-label', 'Show available locations');
  const menu = document.createElement('div');
  menu.className = 'tourist-search-menu';
  menu.setAttribute('role', 'listbox');
  select.parentNode.insertBefore(wrapper, select);
  inputWrap.append(input, toggle);
  wrapper.append(inputWrap, menu, select);
  let activeIndex = -1;

  const close = () => {
    wrapper.classList.remove('open');
    input.setAttribute('aria-expanded', 'false');
    activeIndex = -1;
  };
  const availableOptions = () => Array.from(select.options).filter(option => option.value);
  const render = (query = '') => {
    const normalized = query.trim().toLowerCase();
    const matches = availableOptions().filter(option => !normalized || option.textContent.toLowerCase().includes(normalized));
    menu.innerHTML = '';
    activeIndex = -1;
    if (!matches.length) {
      menu.innerHTML = '<div class="tourist-search-empty">No matching locations</div>';
      return;
    }
    matches.forEach(option => {
      const button = document.createElement('button');
      button.type = 'button';
      button.className = 'tourist-search-option';
      button.dataset.value = option.value;
      button.textContent = option.textContent;
      button.setAttribute('role', 'option');
      button.addEventListener('mousedown', event => event.preventDefault());
      button.addEventListener('click', () => {
        select.value = option.value;
        input.value = option.dataset.name || option.textContent;
        close();
        select.dispatchEvent(new Event('change', {bubbles:true}));
      });
      menu.appendChild(button);
    });
  };
  const open = () => {
    if (select.disabled) return;
    render(input.value);
    wrapper.classList.add('open');
    input.setAttribute('aria-expanded', 'true');
  };
  const refresh = () => {
    const selected = select.selectedOptions[0];
    input.disabled = select.disabled;
    toggle.disabled = select.disabled;
    wrapper.classList.toggle('disabled', select.disabled);
    input.placeholder = select.options[0]?.textContent || 'Search locations';
    input.value = selected?.value ? (selected.dataset.name || selected.textContent) : '';
    close();
  };

  input.addEventListener('focus', open);
  input.addEventListener('click', open);
  input.addEventListener('input', () => {
    render(input.value);
    wrapper.classList.add('open');
    input.setAttribute('aria-expanded', 'true');
  });
  input.addEventListener('blur', () => {
    setTimeout(() => {
      const selected = select.selectedOptions[0];
      input.value = selected?.value ? (selected.dataset.name || selected.textContent) : '';
      close();
    }, 100);
  });
  input.addEventListener('keydown', event => {
    const options = Array.from(menu.querySelectorAll('.tourist-search-option'));
    if (event.key === 'Escape') { close(); return; }
    if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
      event.preventDefault();
      if (!wrapper.classList.contains('open')) open();
      activeIndex = event.key === 'ArrowDown'
        ? Math.min(activeIndex + 1, options.length - 1)
        : Math.max(activeIndex - 1, 0);
      options.forEach((option, index) => option.classList.toggle('active', index === activeIndex));
      options[activeIndex]?.scrollIntoView({block:'nearest'});
    } else if (event.key === 'Enter' && wrapper.classList.contains('open')) {
      event.preventDefault();
      (options[activeIndex >= 0 ? activeIndex : 0])?.click();
    }
  });
  toggle.addEventListener('click', () => {
    if (wrapper.classList.contains('open')) close();
    else { input.focus(); open(); }
  });

  const api = {refresh, close};
  touristSearchSelects.set(select, api);
  refresh();
  return api;
}

function syncTouristSearchSelect(select) {
  enhanceTouristSearchSelect(select).refresh();
}

['country','region','province','city','barangay'].forEach(part => enhanceTouristSearchSelect(addressModalField(part)));
document.addEventListener('mousedown', event => {
  document.querySelectorAll('.tourist-search-select.open').forEach(wrapper => {
    if (!wrapper.contains(event.target)) wrapper.classList.remove('open');
  });
});

const touristGeoNamesCache = new Map();

async function fetchTouristGeoNames(action, parentId = 0) {
  const cacheKey = `${action}:${parentId}`;
  if (touristGeoNamesCache.has(cacheKey)) return touristGeoNamesCache.get(cacheKey);
  const query = new URLSearchParams({action, address_version:'2'});
  if (parentId) query.set('parent_id', String(parentId));
  const request = fetch(`${touristManifestLocationsEndpoint}?${query}`, {headers:{Accept:'application/json'}})
    .then(async response => {
      const result = await response.json();
      if (!response.ok || !result.success) throw new Error(result.message || 'Locations could not be loaded.');
      return Array.isArray(result.locations) ? result.locations : [];
    });
  touristGeoNamesCache.set(cacheKey, request);
  try {
    return await request;
  } catch (error) {
    touristGeoNamesCache.delete(cacheKey);
    throw error;
  }
}

function selectedTouristLocationName(select) {
  return select.selectedOptions[0]?.dataset.name || '';
}

function resetTouristLocationSelect(select, message) {
  select.innerHTML = `<option value="">${touristHtmlValue(message)}</option>`;
  select.disabled = true;
  syncTouristSearchSelect(select);
}

function fillTouristLocationSelect(select, locations, placeholder, selectedName = '') {
  select.innerHTML = `<option value="">${touristHtmlValue(placeholder)}</option>`;
  const seenNames = new Set();
  locations.forEach(location => {
    const normalizedName = String(location.name || '').trim().toLowerCase();
    if (!normalizedName || seenNames.has(normalizedName)) return;
    seenNames.add(normalizedName);
    const option = document.createElement('option');
    option.value = String(location.id);
    option.dataset.name = location.name;
    option.dataset.countryCode = location.country_code || '';
    option.dataset.featureCode = location.feature_code || '';
    option.dataset.adminCode1 = location.admin_code_1 || '';
    option.dataset.adminCode2 = location.admin_code_2 || '';
    option.dataset.adminCode3 = location.admin_code_3 || '';
    option.dataset.adminCode4 = location.admin_code_4 || '';
    option.textContent = location.name;
    select.appendChild(option);
  });
  select.disabled = false;
  if (selectedName) {
    const wanted = selectedName.trim().toLowerCase();
    const match = Array.from(select.options).find(option => (option.dataset.name || '').trim().toLowerCase() === wanted);
    if (match) select.value = match.value;
  }
  syncTouristSearchSelect(select);
}

async function loadTouristLocationChildren(parentSelect, childSelect, placeholder, selectedName = '', featureCodes = []) {
  const parentId = parentSelect.value;
  if (!parentId) {
    resetTouristLocationSelect(childSelect, placeholder);
    return [];
  }
  resetTouristLocationSelect(childSelect, 'Loading locations...');
  const allLocations = await fetchTouristGeoNames('children', parentId);
  const filtered = featureCodes.length
    ? allLocations.filter(location => featureCodes.includes(location.feature_code))
    : allLocations;
  const locations = filtered.length ? filtered : allLocations;
  if (parentSelect.value !== parentId) return [];
  fillTouristLocationSelect(childSelect, locations, locations.length ? placeholder : 'No subdivisions found', selectedName);
  if (!locations.length) childSelect.disabled = true;
  return locations;
}

async function hydrateTouristAddressDropdowns(address = {}) {
  const country = addressModalField('country');
  const region = addressModalField('region');
  const province = addressModalField('province');
  const city = addressModalField('city');
  const barangay = addressModalField('barangay');
  country.innerHTML = '<option value="">Loading countries...</option>';
  country.disabled = true;
  syncTouristSearchSelect(country);
  resetTouristLocationSelect(region, 'Select a country first');
  resetTouristLocationSelect(province, 'Select a region first');
  resetTouristLocationSelect(city, 'Select a province first');
  resetTouristLocationSelect(barangay, 'Select a city first');
  addressModalField('postalCode').value = address.postal_code || '';
  addressModalField('street').value = address.street || '';

  try {
    const countries = await fetchTouristGeoNames('countries');
    fillTouristLocationSelect(country, countries, 'Select a country', address.country || '');
    if (!country.value) return;
    await loadTouristLocationChildren(country, region, 'Select a region or state', address.region || '', ['ADM1']);
    if (!region.value) return;
    await loadTouristLocationChildren(region, province, 'Select a province', address.province || '', ['ADM2']);
    if (!province.value) return;
    await loadTouristLocationChildren(province, city, 'Select a city or municipality', address.city || '', ['ADM3']);
    if (!city.value) return;
    await loadTouristLocationChildren(city, barangay, 'Select a barangay', address.barangay || '', ['ADM4', 'PPL', 'PPLX']);
  } catch (error) {
    country.innerHTML = '<option value="">Countries could not be loaded</option>';
    country.disabled = true;
    syncTouristSearchSelect(country);
    const errorBox = document.getElementById('touristAddressError');
    errorBox.textContent = error.message;
    errorBox.hidden = false;
  }
}

async function openTouristAddressModal(row) {
  activeTouristAddressRow = row;
  const isProfileAddress = row.id === 'profileAddressFields';
  document.getElementById('touristAddressTitle').textContent = isProfileAddress ? 'Home Address' : 'Passenger Address';
  const current = touristAddressFromRow(row);

  const copySelect = document.getElementById('touristAddressCopy');
  copySelect.innerHTML = '<option value="">Choose an address to copy...</option>';
  const unique = new Set();
  Array.from(touristRowsUser.querySelectorAll('.tourist-row-user')).forEach((candidate, index) => {
    if (candidate === row) return;
    const address = touristAddressFromRow(candidate);
    const line = touristAddressLine(address);
    if (!line || unique.has(line.toLowerCase())) return;
    unique.add(line.toLowerCase());
    const option = document.createElement('option');
    option.value = String(index);
    option.textContent = `Tourist ${index + 1} — ${line}`;
    option.dataset.address = JSON.stringify(address);
    copySelect.appendChild(option);
  });
  document.getElementById('touristAddressCopyWrap').hidden = isProfileAddress || copySelect.options.length <= 1;
  document.getElementById('touristAddressError').hidden = true;
  touristAddressModal.classList.add('show');
  touristAddressModal.setAttribute('aria-hidden', 'false');
  await hydrateTouristAddressDropdowns(current);
  setTimeout(() => addressModalField('country').focus(), 0);
}

touristRowsUser.addEventListener('click', event => {
  const trigger = event.target.closest('.tourist-address-trigger');
  if (trigger) openTouristAddressModal(trigger.closest('.tourist-row-user'));
});

document.getElementById('touristAddressCopy').addEventListener('change', async event => {
  const option = event.target.selectedOptions[0];
  if (!option?.dataset.address) return;
  const address = JSON.parse(option.dataset.address);
  await hydrateTouristAddressDropdowns(address);
});

document.getElementById('chooseProfileAddress')?.addEventListener('click', () => {
  openTouristAddressModal(document.getElementById('profileAddressFields'));
});

addressModalField('country').addEventListener('change', async () => {
  resetTouristLocationSelect(addressModalField('province'), 'Select a region first');
  resetTouristLocationSelect(addressModalField('city'), 'Select a province first');
  resetTouristLocationSelect(addressModalField('barangay'), 'Select a city first');
  addressModalField('postalCode').value = '';
  try {
    await loadTouristLocationChildren(addressModalField('country'), addressModalField('region'), 'Select a region or state', '', ['ADM1']);
  } catch (error) {
    const errorBox = document.getElementById('touristAddressError'); errorBox.textContent = error.message; errorBox.hidden = false;
  }
});

addressModalField('region').addEventListener('change', async () => {
  resetTouristLocationSelect(addressModalField('city'), 'Select a province first');
  resetTouristLocationSelect(addressModalField('barangay'), 'Select a city first');
  addressModalField('postalCode').value = '';
  try {
    await loadTouristLocationChildren(addressModalField('region'), addressModalField('province'), 'Select a province', '', ['ADM2']);
  } catch (error) {
    const errorBox = document.getElementById('touristAddressError'); errorBox.textContent = error.message; errorBox.hidden = false;
  }
});

addressModalField('province').addEventListener('change', async () => {
  resetTouristLocationSelect(addressModalField('barangay'), 'Select a city first');
  addressModalField('postalCode').value = '';
  try {
    await loadTouristLocationChildren(addressModalField('province'), addressModalField('city'), 'Select a city or municipality', '', ['ADM3']);
  } catch (error) {
    const errorBox = document.getElementById('touristAddressError'); errorBox.textContent = error.message; errorBox.hidden = false;
  }
});

addressModalField('city').addEventListener('change', async () => {
  addressModalField('postalCode').value = '';
  try {
    await loadTouristLocationChildren(addressModalField('city'), addressModalField('barangay'), 'Select a barangay', '', ['ADM4', 'PPL', 'PPLX']);
  } catch (error) {
    const errorBox = document.getElementById('touristAddressError'); errorBox.textContent = error.message; errorBox.hidden = false;
  }
});

async function fillTouristPostalCode() {
  const postalField = addressModalField('postalCode');
  if (!addressModalField('barangay').value) {
    postalField.value = '';
    return;
  }
  const countryOption = addressModalField('country').selectedOptions[0];
  const cityName = selectedTouristLocationName(addressModalField('city'));
  const countryCode = countryOption?.dataset.countryCode || '';
  if (!countryCode || !cityName) {
    postalField.value = '';
    return;
  }
  postalField.value = 'Loading...';
  try {
    const query = new URLSearchParams({action:'postal_code', country_code:countryCode, place_name:cityName.replace(/^Municipality of\s+/i, '')});
    const response = await fetch(`${touristManifestLocationsEndpoint}?${query}`, {headers:{Accept:'application/json'}});
    const result = await response.json();
    if (!response.ok || !result.success) throw new Error(result.message || 'Postal code could not be loaded.');
    if (selectedTouristLocationName(addressModalField('city')) !== cityName) return;
    if (!result.postal_code) throw new Error('Postal code unavailable. Please enter your postal code.');
    postalField.value = result.postal_code;
  } catch (error) {
    postalField.value = '';
    postalField.readOnly = false;
    postalField.placeholder = 'Enter postal code';
    const errorBox = document.getElementById('touristAddressError');
    errorBox.textContent = 'Please enter your postal code; automatic lookup is unavailable.';
    errorBox.hidden = false;
  }
}

addressModalField('barangay').addEventListener('change', fillTouristPostalCode);

document.getElementById('saveTouristAddress').addEventListener('click', () => {
  if (!activeTouristAddressRow) return;
  const address = {
    country:selectedTouristLocationName(addressModalField('country')),
    region:selectedTouristLocationName(addressModalField('region')),
    province:selectedTouristLocationName(addressModalField('province')),
    city:selectedTouristLocationName(addressModalField('city')),
    barangay:selectedTouristLocationName(addressModalField('barangay')),
    postal_code:addressModalField('postalCode').value.trim(),
    street:addressModalField('street').value.trim()
  };
  const missing = Object.entries(address).filter(([, value]) => !value).map(([part]) => part);
  if (missing.length) {
    const error = document.getElementById('touristAddressError');
    error.textContent = `Complete the ${missing.join(', ')} field${missing.length > 1 ? 's' : ''}.`;
    error.hidden = false;
    return;
  }
  setTouristRowAddress(activeTouristAddressRow, address);
  closeTouristAddressModal();
});

document.getElementById('closeTouristAddress').addEventListener('click', closeTouristAddressModal);
document.getElementById('cancelTouristAddress').addEventListener('click', closeTouristAddressModal);
touristAddressModal.addEventListener('mousedown', event => { if (event.target === touristAddressModal) closeTouristAddressModal(); });
document.addEventListener('keydown', event => {
  if (event.key === 'Escape' && touristAddressModal.classList.contains('show')) {
    closeTouristAddressModal();
  }
});

/* ---------- OPEN MODAL ---------- */
document.querySelectorAll('.add-tourist-btn-user, .edit-tourist-btn-user').forEach(btn => {
  btn.addEventListener('click', async () => {
    if (touristManifestConfig.adminWalkin) {
      btn.closest('.row-actions')?.classList.remove('open', 'drop-up');
      btn.closest('.row-actions')?.querySelector('.action-toggle-btn')?.setAttribute('aria-expanded', 'false');
    }
    const bookingId = btn.dataset.bookingId;
    maxPax = parseInt(btn.dataset.pax || 0);
    touristBookingIsBoat = btn.dataset.bookingType === 'boat';
    touristBookingHasMultipleBoats = touristBookingIsBoat && maxPax > 8;

    bookingIdInput.value = bookingId;
    touristManifestEditing = btn.dataset.manifestMode === 'edit';
    document.getElementById('touristManifestMode').value = touristManifestEditing ? 'edit' : 'submit';
    document.getElementById('touristFormTitle').textContent = touristManifestEditing ? 'Edit Tourist Information' : 'Add Tourist Information';
    document.querySelector('#touristFormUser .tourist-submit-user').textContent = touristManifestEditing ? 'Save Changes' : 'Submit Tourist List';

    const formData = new FormData();
    formData.append('fetch_tourists', '1');
    formData.append('booking_id', bookingId);

    try {
      const res = await touristManifestFetch(touristManifestEndpoint, {
        method: 'POST',
        body: formData
      });

      const data = await res.json();
      if (bookingIdInput.value !== bookingId) return;
      if (!res.ok || !data.success) throw new Error(data.message || 'Tourist details could not be loaded.');
      if (touristManifestConfig.adminWalkin) {
        maxPax = Number(data.pax);
        touristBookingIsBoat = data.booking_type === 'boat';
        touristBookingHasMultipleBoats = touristBookingIsBoat && maxPax > 8;
      }
      if (touristManifestEditing && !data.can_edit) throw new Error(touristManifestConfig.adminWalkin
        ? 'This walk-in tourist list can no longer be edited. Refresh the booking and try again.'
        : 'Editing is closed. Tourist lists can only be edited until three days before the booking date.');
      document.getElementById('touristFormDescription').textContent = touristManifestEditing
        ? `Update the passenger details below.${touristManifestConfig.adminWalkin ? "" : ` Editing closes ${data.edit_deadline_label}.`}`
        : 'Complete the required details for every passenger in this booking.';

      touristRowsUser.innerHTML = '';

      currentTouristCount = (data.success && data.tourists)
        ? data.tourists.length
        : 0;

      if (data.success && data.tourists.length > 0) {
        data.tourists.forEach(t =>
          touristRowsUser.appendChild(createTouristRowUser(t))
        );
      } else {
        touristRowsUser.appendChild(createTouristRowUser());
      }

      renumberTouristRows();

      touristModalUser.style.display = 'flex';

    } catch (err) {
      console.error(err);
      alert(err.message || 'Failed to load existing tourists.');
    }
  });
});

/* ---------- CLOSE MODAL ---------- */
// Email links can only open a form belonging to a booking rendered for this account.
const touristSubmissionId = new URLSearchParams(window.location.search).get('submit_tourists');
function openRequestedTouristSubmission() {
  if (!touristSubmissionId || !/^[1-9]\d*$/.test(touristSubmissionId)) return;
  const submissionButton = Array.from(document.querySelectorAll('.add-tourist-btn-user, .edit-tourist-btn-user'))
    .find(button => button.dataset.bookingId === touristSubmissionId);
  const bookingRow = document.getElementById('booking-' + touristSubmissionId);
  bookingRow?.scrollIntoView({block: 'center'});
  if (submissionButton) submissionButton.click();
  else if (bookingRow?.querySelector('.view-tourist-btn-user')) bookingRow.querySelector('.view-tourist-btn-user').click();
  else alert('The tourist submission form is unavailable for this booking. Please use the account that made the booking and check its status in My Bookings.');
}

document.querySelector('.tourist-modal-close-user').onclick = () => {
  touristModalUser.style.display = 'none';
};

/* ---------- ADD MORE TOURISTS ROW ---------- */
document.getElementById('addMoreTouristUser').addEventListener('click', () => {
  if (currentTouristCount >= maxPax) {
    alert(`You can only add up to ${maxPax} tourists for this booking.`);
    return;
  }

  const addedRow = createTouristRowUser();
  touristRowsUser.appendChild(addedRow);
  renumberTouristRows();
  showAddedTouristRow(addedRow);
});

function updateAddButtonState() {
  const addBtn = document.getElementById('addMoreTouristUser');
  const submitBtn = document.querySelector('#touristFormUser .tourist-submit-user');

  if (currentTouristCount >= maxPax) {
    addBtn.disabled = true;
    addBtn.style.opacity = 0.5;
    addBtn.style.cursor = 'not-allowed';
  } else {
    addBtn.disabled = false;
    addBtn.style.opacity = 1;
    addBtn.style.cursor = 'pointer';
  }

  const boatCounts = touristBookingHasMultipleBoats ? Array.from({length:Math.ceil(maxPax / 8)}, (_, i) =>
    Array.from(touristRowsUser.querySelectorAll('[name="boat_number[]"]')).filter(input => Number(input.value) === i + 1).length) : [];
  const hasRequiredPax = maxPax > 0 && currentTouristCount === maxPax && boatCounts.every(count => count > 0 && count <= 8);
  submitBtn.disabled = !hasRequiredPax;
  const remainingPax = Math.max(maxPax - currentTouristCount, 0);
  submitBtn.title = hasRequiredPax
    ? (touristManifestEditing ? 'Save changes to the tourist list' : 'Submit the completed tourist list')
    : `Add ${remainingPax} more tourist${remainingPax === 1 ? '' : 's'} to submit`;
}

/* ---------- REMOVE TOURIST ROW ---------- */
touristRowsUser.addEventListener('click', e => {
  const removeButton = e.target.closest('.remove-tourist-user');
  if (removeButton) {
    removeButton.closest('.tourist-row-user').remove();
    renumberTouristRows();
  }
});

/* ---------- SUBMIT TOURISTS ---------- */
document.getElementById('touristFormUser').addEventListener('submit', function(e) {
  e.preventDefault();

  const rows = Array.from(touristRowsUser.querySelectorAll('.tourist-row-user'));
  if (!rows.length) {
    alert('Add at least one tourist.');
    return;
  }
  if (rows.length !== maxPax) {
    alert(`Complete all ${maxPax} tourist entries before submitting.`);
    return;
  }
  const incompleteAddressIndex = rows.findIndex(row => Object.values(touristAddressFromRow(row)).some(value => !value));
  if (incompleteAddressIndex >= 0) {
    alert(`Complete the address for Tourist ${incompleteAddressIndex + 1}.`);
    openTouristAddressModal(rows[incompleteAddressIndex]);
    return;
  }
  if (!this.checkValidity()) {
    this.reportValidity();
    return;
  }

  // Save current active tab
  const activeTab = document.querySelector('.nav-links a.active')?.getAttribute('data-section');
  if (activeTab) localStorage.setItem('activeTab', activeTab);

  const formData = new FormData(this);
  const submitBtn = this.querySelector('button[type="submit"]');
  const submittedBookingId = bookingIdInput.value;
  submitBtn.disabled = true;
  submitBtn.textContent = touristManifestEditing ? 'Saving Changes...' : 'Submitting...';

  touristManifestFetch(touristManifestEndpoint, { method: 'POST', body: formData })
    .then(res => res.json())
    .then(async resData => {
      if (resData.success) {
        touristModalUser.style.display = 'none';
        const result = await Swal.fire({
          icon: 'success',
          title: resData.updated ? 'Tourist List Updated' : 'Tourist List Submitted',
          text: resData.message || 'The passenger manifest was saved successfully.',
          showCancelButton: !touristManifestConfig.adminWalkin,
          confirmButtonText: touristManifestConfig.adminWalkin ? 'Okay' : 'View PDF',
          cancelButtonText: 'Okay',
          confirmButtonColor: '#26745f',
          cancelButtonColor: '#71817b',
          reverseButtons: true,
          allowOutsideClick: false
        });
        if (result.isConfirmed && !touristManifestConfig.adminWalkin) {
          openTouristProfilePdf(submittedBookingId, true);
        } else {
          location.reload(); // reloads, active tab will be restored
        }
      } else {
        await Swal.fire({
          icon: 'error',
          title: 'Submission Failed',
          text: resData.message || 'The tourist list could not be submitted.',
          confirmButtonText: 'Okay',
          confirmButtonColor: '#26745f'
        });
      }
    })
    .catch(async err => {
      console.error(err);
      await Swal.fire({
        icon: 'error',
        title: 'Submission Failed',
        text: 'The tourist list could not be submitted. Please try again.',
        confirmButtonText: 'Okay',
        confirmButtonColor: '#26745f'
      });
    })
    .finally(() => {
      submitBtn.textContent = touristManifestEditing ? 'Save Changes' : 'Submit Tourist List';
      updateAddButtonState();
    });
});

