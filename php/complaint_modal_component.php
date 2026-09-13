<div class="complaint-modal" id="complaintIncidentModal" hidden aria-hidden="true">
  <div class="complaint-modal__backdrop" data-complaint-close></div>
  <section class="complaint-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="complaintModalTitle" aria-describedby="complaintModalDescription">
    <header class="complaint-modal__header">
      <span class="complaint-modal__icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M12 3 3.8 6.4v5.2c0 4.7 3.5 7.9 8.2 9.4 4.7-1.5 8.2-4.7 8.2-9.4V6.4L12 3Z"></path><path d="M12 8v4.5M12 16h.01"></path></svg></span>
      <div class="complaint-modal__heading">
        <span class="complaint-modal__eyebrow">Tourist assistance</span>
        <h2 id="complaintModalTitle">Submit a Complaint or Incident</h2>
        <p id="complaintModalDescription">Give the Tourism Office clear, factual details so your concern can be reviewed properly. Fields marked with an asterisk are required.</p>
      </div>
      <button type="button" class="complaint-modal__close" data-complaint-close aria-label="Close complaint form">&times;</button>
    </header>

    <form class="complaint-form" id="complaintIncidentForm" enctype="multipart/form-data" novalidate>
      <div class="complaint-form__body">
        <div class="complaint-form__notice">
          <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"></circle><path d="M12 10v6M12 7h.01"></path></svg>
          <span>Your report is linked securely to your tourist account. For immediate danger or medical emergencies, contact local emergency services first.</span>
        </div>
        <div class="complaint-validation-summary" id="complaintValidationSummary" role="alert" tabindex="-1" hidden></div>

        <fieldset class="complaint-form__section">
          <legend>Report details</legend>
          <div class="complaint-form__grid">
            <label class="complaint-field"><span>Report type <b class="complaint-required">*</b></span><select name="report_type" required><option value="">Select report type</option><option value="complaint">Complaint</option><option value="incident">Incident</option></select></label>
            <label class="complaint-field"><span>Category <b class="complaint-required">*</b></span><select name="category" required><option value="">Select a category</option><option value="tour-service">Tour or guide service</option><option value="accommodation">Hotel or accommodation</option><option value="transportation">Boat or transportation</option><option value="safety-security">Safety or security</option><option value="environmental">Environmental concern</option><option value="staff-conduct">Staff or operator conduct</option><option value="payment-booking">Payment or booking</option><option value="other">Other concern</option></select></label>
            <label class="complaint-field complaint-field--wide"><span>Subject <b class="complaint-required">*</b></span><input type="text" name="subject" minlength="5" maxlength="180" placeholder="Briefly summarize your concern" required></label>
            <label class="complaint-field"><span>Date and time of event <b class="complaint-required">*</b></span><input type="datetime-local" name="incident_at" required></label>
            <label class="complaint-field"><span>Location <b class="complaint-required">*</b></span><input type="text" name="location" minlength="3" maxlength="220" placeholder="Island, property, boat, or meeting point" required></label>
          </div>
        </fieldset>

        <fieldset class="complaint-form__section">
          <legend>What happened</legend>
          <div class="complaint-form__grid">
            <label class="complaint-field complaint-field--wide"><span>Detailed description <b class="complaint-required">*</b></span><textarea name="description" minlength="30" maxlength="5000" placeholder="Describe the event in order, including what you observed and how it affected you." required></textarea><small>Please provide facts and avoid including unrelated sensitive personal information.</small></label>
            <label class="complaint-field"><span>People or organizations involved</span><textarea name="people_involved" maxlength="500" placeholder="Names, operator, guide, hotel, or boat, if known"></textarea></label>
            <label class="complaint-field"><span>Immediate action already taken</span><textarea name="immediate_action" maxlength="2000" placeholder="Who you notified or what was done after the event"></textarea></label>
          </div>
        </fieldset>

        <fieldset class="complaint-form__section">
          <legend>Evidence and follow-up</legend>
          <div class="complaint-form__grid">
            <div class="complaint-field complaint-field--wide">
              <span>Photo evidence</span>
              <label class="complaint-dropzone" for="complaintEvidence">
                <input id="complaintEvidence" type="file" name="evidence[]" accept="image/jpeg,image/png,image/webp" multiple>
                <span><span class="complaint-dropzone__icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M12 16V5M8 9l4-4 4 4M5 14v4a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2v-4"></path></svg></span><strong>Drag photos here or choose files</strong><span>Up to 3 JPG, PNG, or WebP images &middot; 5 MB each</span></span>
              </label>
              <div class="complaint-file-list" id="complaintFileList" aria-live="polite"></div>
            </div>
            <label class="complaint-field"><span>Preferred contact method <b class="complaint-required">*</b></span><select name="preferred_contact" required><option value="email">Email</option><option value="phone">Phone</option><option value="either">Email or phone</option></select></label>
          </div>
        </fieldset>

        <label class="complaint-consent"><input type="checkbox" name="accuracy_consent" value="1" required><span>I confirm that the information in this report is accurate to the best of my knowledge, and I understand that the Tourism Office may contact me for clarification. <b class="complaint-required">*</b></span></label>
        <div class="complaint-form__status" id="complaintFormStatus" aria-live="polite"></div>
      </div>
      <div class="complaint-form__actions">
        <button type="button" class="complaint-form__cancel" data-complaint-close>Cancel</button>
        <button type="submit" class="complaint-form__submit"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m4 12 5 5L20 6"></path></svg><span>Submit report</span></button>
      </div>
    </form>
  </section>
</div>
