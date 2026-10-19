<div id="touristModalUser" class="tourist-modal-user" style="display:none;">
  <div class="tourist-modal-content-user extended">

    <div class="tourist-modal-header-user">
      <div>
        <span class="tourist-modal-kicker">Passenger manifest</span>
        <h3 id="touristFormTitle">Add Tourist Information</h3>
        <p id="touristFormDescription">Complete the required details for every passenger in this booking.</p>
      </div>
      <button type="button" class="tourist-modal-close-user" aria-label="Close tourist form">&times;</button>
    </div>

    <form id="touristFormUser" method="POST">
      <input type="hidden" name="booking_id" id="touristBookingId">
      <input type="hidden" name="booking_domain" id="touristBookingDomain" value="tour">
      <input type="hidden" name="manifest_mode" id="touristManifestMode" value="submit">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($manifestCsrf, ENT_QUOTES, 'UTF-8') ?>">

      <div class="tourist-form-summary">
        <div><strong id="touristFormCount" aria-live="polite">0/0</strong><span>Tourists added</span></div>
        <p>Tap an address field to enter a structured address or reuse one already entered.</p>
      </div>

      <div id="touristRowsUser">
        <!-- Tourist rows injected here -->
      </div>

      <div class="tourist-modal-footer-user">
        <button type="button" class="btn add-more-user" id="addMoreTouristUser">+ Add another tourist</button>
        <button type="submit" class="btn tourist-submit-user" disabled>Submit Tourist List</button>
      </div>
    </form>

  </div>
</div>

<div id="touristAddressModal" class="tourist-address-overlay" aria-hidden="true">
  <section class="tourist-address-modal" role="dialog" aria-modal="true" aria-labelledby="touristAddressTitle">
    <header class="tourist-address-header">
      <div>
        <span>Structured address</span>
        <h3 id="touristAddressTitle">Passenger Address</h3>
        <p>Select each location in order to build a complete address.</p>
      </div>
      <button type="button" id="closeTouristAddress" aria-label="Close address form">&times;</button>
    </header>

    <div class="tourist-address-body">
      <div class="tourist-address-copy" id="touristAddressCopyWrap" hidden>
        <label for="touristAddressCopy">Use an address already entered</label>
        <select id="touristAddressCopy">
          <option value="">Choose an address to copy...</option>
        </select>
      </div>

      <div class="tourist-address-grid">
        <label class="tourist-address-field full"><span>Country</span><select id="touristAddressCountry"><option value="">Loading countries...</option></select></label>
        <label class="tourist-address-field"><span>Region / State</span><select id="touristAddressRegion" disabled><option value="">Select a country first</option></select></label>
        <label class="tourist-address-field"><span>Province</span><select id="touristAddressProvince" disabled><option value="">Select a region first</option></select></label>
        <label class="tourist-address-field"><span>City / Municipality</span><select id="touristAddressCity" disabled><option value="">Select a province first</option></select></label>
        <label class="tourist-address-field"><span>Barangay</span><select id="touristAddressBarangay" disabled><option value="">Select a city first</option></select></label>
        <label class="tourist-address-field"><span>Postal Code</span><input type="text" id="touristAddressPostalCode" maxlength="20" placeholder="Filled automatically" readonly></label>
        <label class="tourist-address-field"><span>Street / House No.</span><input type="text" id="touristAddressStreet" maxlength="180" placeholder="Street, building, or house number"></label>
      </div>
      <p class="tourist-address-error" id="touristAddressError" role="alert" hidden></p>
    </div>

    <footer class="tourist-address-footer">
      <button type="button" class="tourist-address-cancel" id="cancelTouristAddress">Cancel</button>
      <button type="button" class="tourist-address-done" id="saveTouristAddress">Done</button>
    </footer>
  </section>
</div>

