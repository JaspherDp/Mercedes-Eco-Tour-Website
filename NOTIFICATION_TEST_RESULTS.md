# Notification verification results

Executed locally on 2026-10-19 (Asia/Manila). No production connection, deployment, permanent local schema change, real booking, real email, or payment-provider request was made.

## Executed checks

| Check | Result | Evidence / scope |
|---|---|---|
| `php scripts/notification_system_test.php --mysql` | PASS, 155 assertions | SQLite memory database plus MySQL 8.0.40 connection-private temporary tables |
| `node scripts/notification_inbox_test.cjs` | PASS, 78 assertions | Mock DOM/fetch, all four roles; no browser rendering |
| PHP syntax | PASS, 16 files | `php -l` on every notification-related modified/new PHP file and the new PHP test |
| JavaScript syntax | PASS, 4 files | `node --check` on shared inbox, header, header loader and new UI test |
| Anonymous local HTTP requests, four roles | PASS | `notification_inbox.php?role=tourist/admin/hotel_admin/operator` each returned 401 |
| Invalid local HTTP role | PASS | HTTP 400 |
| Unsupported local HTTP PUT | PASS | HTTP 405 |
| `git diff --exit-code -- payments` | PASS | No changes to the payment directory, including checkout, reconciliation and webhook files |
| `git diff --check` | PASS | No whitespace errors; existing Windows line-ending conversion warnings are informational |

PHP checks used XAMPP PHP 8.0.30; JavaScript checks used Node 22.18.0. Each MySQL test creates temporary tables with the same names as all referenced application tables, so fixtures shadow real local records for that connection and disappear when it closes. The host guard refuses remote database hosts. SQLite fixtures cover failure injection without an external service.

Expected test diagnostics include an intentionally incompatible activity-log table and an injected notification-insert failure. Those cases passed: the existing booking transaction committed and notification handling did not throw to the caller. No final test suite is failing.

## Four-role fixture coverage

| Role | Verified in executed automated fixtures | Still requires authenticated staging verification |
|---|---|---|
| Tourist | Correct owner; overlapping hotel/tour IDs; submitted/confirmed/rejected/cancelled/completed event definitions; information request/submission/update; rescheduling decision and schedule update; verified/pending payment mapping; actual refund aggregate hook; exact existing form URL; submitted-information fallback; history/billing destination; read persistence and ownership; login-resume resolver URL | Real login/relogin navigation; actual booking/form opening in rendered pages; full provider-decision interface; real SMTP delivery; test-mode PayMongo flow |
| Main administrator | Tour submission recipients; main-admin hotel cancellation scope; exact `focus_booking`/`focus_request` routing; submitted/updated guest information events; independent recipient read state; legacy feed backfill SQL; unchanged existing booking/PDF interfaces reviewed | Rendered main-admin old/new notification clicks, modal/PDF focus, all real successful endpoint workflows |
| Hotel administrator | Only active accounts assigned to the property; unrelated property excluded; explicit hotel manifest domain; cancellation/payment recipients; guest check-in/check-out mappings; current hotel record routing; historical feed backfill | Real hotel account login; details/PDF auto-opening; check-in/out and cancellation endpoints in staging; desktop/mobile layout |
| Tour operator | Assigned operator only; unrelated operator excluded; old assignment loses access; new bookings, guest submissions/updates, rescheduling, payment and completion mappings; exact details/PDF destination; history backfill | Real operator login; embedded existing manifest PDF in booking drawer; real workflow endpoint interactions |

The new event suite verifies dispatch functions and database queries; it does not drive every full booking endpoint end-to-end. Lifecycle event definitions that do not have a supported business workflow were not invented to make the matrix appear complete.

## Database, security and failure cases executed

- Actual local schema/foreign-key inventory was read before application changes. The local database had no notification table. Existing tour bookings reference `tourist`; tour/hotel manifest tables reference their respective booking tables. Hotel ownership is resolved through `hotel_admin_accounts.hotel_resort_id`.
- The supplied MySQL table definition ran twice as a temporary table. All three exact history `INSERT ... SELECT` statements ran twice against temporary fixtures; the second run did not add duplicate records.
- Tour and hotel bookings with the same numeric ID went to different tourists/providers. A verified hotel payment was resolved using its existing booking reference/provider payment ID, not a guessed domain or later pending transaction.
- Replaying booking submission and payment events did not duplicate records. Failed/unverified payment references did not generate confirmed-payment notifications.
- Notification reads remained recipient-scoped. Another tourist could not mark the owner's item read. Read state persisted in the database across fresh inbox reads and remained independent for administrators.
- Mark-all used an arrival boundary and left a later event unread. Reassigned bookings disappeared from the previous operator's list and failed the click permission predicate.
- A rolled-back transaction left no committed notification. A missing notification table caused no automatic DDL. An injected notification write failure left the saved booking intact. Unavailable audit-log storage did not suppress valid notification delivery.
- The real `bookingRefundSyncCancellationStatus` helper was invoked against SQLite fixtures; its aggregate remained `completed` and emitted the corresponding notification.
- A submitted tourist list caused the information-request link to resolve to existing booking details instead of another submission form.

## UI fixture checks executed

Each role mounted one bell and a compact item, used its own role endpoint, and created only one 60-second timer. Deferred initialization waited for the existing header callbacks. Opening the dropdown did not silently mark everything read. Escape closed the panel and restored focus. Failed mark-read writes remained retryable; successful writes updated the badge. Mutations carried the existing scoped CSRF token and snapshot boundary. Clicks used a stable authenticated resolver URL.

Additional checks covered repeated script loading, hidden-page polling suppression, expired-session polling stop, loading/error/empty states, text-only rendering of hostile markup, invalid timestamps, role-specific login-resume links, hotel/tour ID collision during UI selection, completed-hotel billing fallback and a visible missing-record fallback.

These are mock-DOM tests. Responsive CSS was implemented and source-reviewed, but desktop/mobile screenshots, actual browser layout, accessibility-tree behavior and full browser navigation were **not verified**. Browser inventory returned no browsers; opening an isolated in-app browser returned `Browser is not available: iab`.

## Existing regression suites executed

| Command | Result | Relevant protection |
|---|---|---|
| `php scripts/tourist_submission_alert_test.php` | PASS | Repeat emails remain deliberate; CSRF, stale submission checks, eligibility, email-template content and exact booking form link |
| `php scripts/tourist_manifest_edit_test.php` | PASS | Ownership, cutoff, guest counts, boat validation, cancellation protection, atomic edit/rollback |
| `php scripts/hotel_tourist_manifest_test.php` | PASS | Hotel/tour ID isolation, property scope, shared manifest submit/edit rules |
| `php scripts/paymongo_refund_critical_fixture_test.php` | PASS | Existing refund calculation/routing fixtures; no provider requests |
| `php scripts/payment_phone_notification_test.php` | PASS | Existing phone/QR notification payload remains intact; no push sent |
| `node scripts/tourist_manifest_form_test.cjs` | PASS | Existing guest form behavior, scoped requests, failed-edit handling and PDF links |
| `node scripts/hotel_tourist_submission_ui_test.cjs` | PASS | Existing hotel tourist column and property-scoped PDF preview/download |
| `node scripts/booking_success_receipt_test.cjs` | PASS | Existing receipt modal, loading/error/expired-session behavior |

## Not executed / release requirements

No live Hostinger schema, engine/version, SQL privileges, production load, email service, external PayMongo request, settlement operation, webhook delivery or real customer workflow was tested. MariaDB compatibility was not executed. No full logged-in HTTP session test was run for every role; server authorization query behavior was tested with fixtures and anonymous HTTP denials were tested locally. Server CSRF uses the existing verification helper and rejects invalid tokens in code; a new authenticated end-to-end invalid-CSRF HTTP test remains a staging check.

No standalone correction-request/resubmission-approval workflow or generic reschedule approval/rejection process exists in the audited active workflow. Existing guest-list edits emit updates, and the provider reschedule offer/decision/expiry process emits its actual supported events. These unsupported states are not marked as passed tests.

Before release, follow `HOSTINGER_NOTIFICATION_DEPLOYMENT_GUIDE.md` and run a dedicated-account desktop/mobile staging matrix across booking creation, approval/rejection, cancellation/review, guest submission/update, rescheduling, completion/check-out, payment pending/verification and refunds. Verify existing emails and payment processing independently. Keep production deployment and live SQL under the owner's explicit control.

## Local activation follow-up ? 2026-10-19

After the initial isolated tests, the owner reported missing localhost alerts. The notification table was confirmed absent, then the supplied additive SQL was applied to the verified local database only, creating the table/indexes and importing 43 history entries. The 149 backend and 66 UI fixture assertions passed again using isolated fixtures. No email was sent by the agent. Earlier assertions about no permanent local schema changes describe the initial test phase; the localhost migration is now applied. Hostinger remains untouched.

## Homepage dynamic-script lifecycle fix ? 2026-10-19

The tourist information request was confirmed stored and unread in localhost. The homepage calls `initHeader` during DOMContentLoaded and then loads the inbox module dynamically. While the document remained interactive, that module incorrectly waited for DOMContentLoaded a second time; its mount never ran, and the legacy handler had been disabled. The initializer now distinguishes parser-deferred scripts from late dynamic scripts. The header also explicitly mounts on script load, keeps the existing bell handler until replacement, and permits a failed script load to be retried. Shared-script/cache versions were advanced. Regression fixtures now cover late dynamic loading with no second DOMContentLoaded and opening the resulting bell: 149 backend and 68 UI assertions pass. Local Apache HTTP checks confirmed both updated scripts are served. No new SQL, emails or production changes were performed for this fix. Actual browser interaction remains unverified because no browser surface is available in the session.

## Tourist header-only placement and original design ? 2026-10-19

Following the owner?s preference, the standalone tourist profile inbox slot and its sticky positioning were removed. Tourist inbox mounting is restricted to `.head-nav-main-header .head-nav-notif-wrap`. The original `img/notificon.png` black bell, transparent button, and original dropdown dimensions/colors are restored for tourists; other role inbox styling is unchanged. Profile pages continue loading only the logic required to open authorized booking/form links and resume login destinations; without a site header they create no bell, inbox fetch or polling timer. New fixtures verify the original image, header-free placement, exact booking navigation and login resume. Current results: 149 backend and 72 UI assertions passed, plus PHP/JS syntax and whitespace checks. Asset versions were advanced to 3. No database changes or production deployment were made for this UI adjustment.

## Admin booking entry point and header stability ? 2026-10-19

The unstyled notification destination was caused by linking directly to the implementation at `/admin/adbookings.php`, where relative asset URLs resolved into nonexistent `/admin/styles`, `/admin/js` and `/admin/img` directories. Administrator notification/fallback destinations now use the existing public `adbookings.php` wrapper; the same public-wrapper correction is applied to operator destinations. Direct GET visits to the admin implementation redirect to the root wrapper with the complete query string preserved. No booking, authentication, payment or cancellation processing was changed.

The booking header now renders its bell/profile controls server-side and hides irrelevant date filters in the initial HTML. A local inline initializer immediately handles filter changes, independently of delayed third-party scripts; the old late initializer was removed. Booking-specific responsive CSS keeps control sizes and wrapping consistent, and its stylesheet uses a modification-time cache version.

Executed: 155 backend assertions, 78 UI/initializer assertions, 28 first-render PHP header assertions; exact booking/cancellation canonical redirects verified over local HTTP, plus HTTP 200 checks for both booking/theme stylesheets, the logo and inbox script. Existing tourist submission email, manifest UI and manifest edit regression suites passed again. Source syntax and whitespace checks passed; `payments/` remains unchanged. Actual rendered browser alignment remains unverified in this session because there is no connected browser surface. No database updates or production deployment were performed for this fix.
