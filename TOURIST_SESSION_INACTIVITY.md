# Tourist inactivity correction

Recorded: 17 October 2026 (Asia/Manila).
Deployment: local workspace only; Hostinger has not been changed.

## Behavior

Tourist authentication now uses the existing inactivity duration rather than expiring while the visitor is actively browsing or completing a booking. The current local duration is 60 minutes; it still comes from the existing admin_system_settings.settings_json session_timeout_minutes value (default 60, supported 15–480). This change does not alter that saved setting.

Public page navigation validates the tourist session and refreshes its activity time before rendering the page. Browsing pages, search/detail pages, booking forms, confirmation, and Profile now render the existing session monitor for authenticated tourists. Clicking, typing, input, and scrolling in the visible tab send a throttled, CSRF-protected activity POST. Passive checks and visibility alone do not refresh the timer.

The server checks expiry before accepting activity. An already expired session cannot be revived by a late event. New tourist logins rotate the activity generation and token so stale tabs cannot invalidate or renew a different/new login. Tourist expiry clears tourist authentication only; it does not destroy another role's session. Existing cookie security and login session-ID rotation are preserved.

An idle tab expires at the configured inactivity boundary, with the existing session-expired dialog and a return to the tourist login modal. Network failures are not treated as proof of expiry.

## Deployment files

Deploy together when the Hostinger update is scheduled:

- php/session_security.php
- php/session_status.php
- php/tourist_activity_helper.php (new)
- php/profile.php
- public/about.php
- public/destination.php
- public/destination_results.php
- public/homepage.php
- public/homepage_new.php
- public/hotelResorts.php
- public/hotel_booking.php
- public/hotel_booking_success.php
- public/hotel_details.php
- public/hotel_resorts.php
- public/package_details.php
- public/search_results.php
- public/service_details.php
- public/tour_booking.php

The existing php/session_monitor.php and js/session-monitor.js are reused. They must be present in the deployed site. Root route wrappers are unchanged. Existing logged-in sessions are adopted on the next page navigation; no forced global logout or database backfill is required.

## Database impact

No new tables, columns, SQL migration, or saved timeout changes. Authentication timestamps, generations, and CSRF values are PHP session data. The feature reads existing settings and tourist account status. Applying the separate add-on SQL migrations does not deploy this session correction.

## Validation

- php scripts/tourist_inactivity_test.php: read-only local tourist account, isolated temporary sessions; browsing renewal, passive checks, valid/invalid CSRF, exact idle boundary, no expired-session revival, role isolation, stale tabs, and new-login generation/token rotation.
- node scripts/session_monitor_test.js: tourist and all staff roles; passive polling, synthetic events, trusted clicks/typing/scroll/input, hidden tabs, visibility, offline handling, and login redirects (mock browser).
- php scripts/session_expiration_test.php: existing admin/operator/hotel real-handler regression suite in the local test environment.
- php scripts/portal_settings_test.php: timeout configuration and role isolation.
- node scripts/paymongo_return_browser_test.js: existing 80 payment-return checks.

Changed PHP files passed syntax checks. No real payment or hosted migration was performed.
