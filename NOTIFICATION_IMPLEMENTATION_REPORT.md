# Notification enhancement audit and implementation

## Audit and minimal change plan (2026-10-19)

Local schema inspected using a loopback-only, read-only PDO audit: MySQL 8.0.40. No notification table exists. `bookings.is_notif_viewed` backs the interactive main administrator booking feed. Tourist messages are browser local storage booking-success entries. Hotel notifications use property-scoped bookings and session timestamps; operators use operator-scoped bookings and session timestamps. Main admin polls every four seconds. These mechanisms cannot represent separate durable events per recipient.

Reuse: `logActivity` successful business event calls, existing authentication/session expiry and CSRF helpers, `booking_id` and `hotel_booking_id`, existing main-admin `focus_booking`/`focus_request` links, provider booking search and details controls, tourist booking details/billing controls, and email `profile.php?section=bookings&submit_tourists=...` form links. Emails and payment reconciliation will not be rewritten.

Ownership: tour bookings belong to `tourist_id`, with optional `operator_id`; hotel bookings belong to `tourist_id` and `hotel_resort_id`. Main admins centrally review tours and cancellation requests for both domains. There is no main-admin hotel booking details page: ordinary hotel lifecycle notifications go to the tourist and assigned hotel administrator, not unrelated main-admin pages. Main admins receive hotel cancellation workflow events they can review. Hotel staff are resolved from `hotel_admin_accounts`, not an invented hotel-users table.

Plan: add one recipient event table with a uniqueness key and inbox index, no foreign keys across polymorphic roles/domains, no modification to business tables. Extend existing successful activity event dispatch with failure isolation; add only specific missing event hooks. Provide a shared compact inbox in existing bell positions, single 60-second visible-page refresh, persistent reads, and server-validated links. Feature availability is schema-gated; no runtime DDL or automatic migration. Preserve legacy interfaces when unavailable. No production access, SQL execution or deployment is authorized.

Existing uncommitted user work was present in booking, manifest and hotel code. It was preserved; notification edits in shared files were applied contextually. Implementation was performed in the local checkout, with tests isolated in memory/connection-private temporary tables. No production code or database was accessed for execution.

## Implemented enhancements

The four existing bell positions now support a shared compact inbox when the additive notification table is available. The original main-admin feed/API remains in place as the schema-unavailable fallback. The new main-admin destinations retain `focus_booking` and `focus_request` and additionally open existing details/guest-list controls. No duplicate booking page or submission form was created.

- **Tourists:** durable booking lifecycle, information request/submission/update, provider rescheduling, payment pending/confirmed and refund status events. The main administrator's existing information-request action emits the in-app request alongside its unchanged email call. Existing status, ownership, cancellation and email-address eligibility checks remain. The in-app request is still recorded if SMTP fails. Clicking it reuses the email's existing `submit_tourists`/`submit_domain` form destination only while a list is missing and the booking is active; otherwise it opens current details. Terminal bookings use History; payment events use existing billing controls.
- **Main administrators:** new tour bookings and assisted bookings; guest submission/update; confirmation/rejection/completion; cancellation review; rescheduling; payment and refund events. Ordinary hotel lifecycle events are not sent to a main-admin page without a hotel booking interface. Hotel cancellation/refund events go to the existing cross-domain cancellation-review interface.
- **Hotel administrators:** assigned-property submissions, lifecycle/status changes, guest lists, cancellation/refund events, recorded/verified payments and charges, check-in and check-out. Existing `Hotel Rooms` activity events resolve explicitly to the hotel domain. Current active hotel guest-list notifications select the existing Accepted tab and PDF control; stale actions fall back to details/billing.
- **Tour operators:** only assigned tour bookings and their relevant events. Guest-list notifications reuse the booking drawer and existing operator-authorized manifest PDF. Reassignment hides old notifications from the previous operator on subsequent reads/clicks.
- **UI/read behavior:** consistent green/neutral bell, one badge, compact static titles/messages, Manila timestamps, unread treatment, scrollable mobile-aware panel, loading/empty/error states, Escape/focus return, persistent individual/all-read actions. Preview fields contain no names, guest details, bank information or payment amounts. Text is inserted through `textContent`.
- **Refresh/performance:** one 60-second inbox interval per page, no hidden-page requests, no overlapping refresh promise, a 10-second minimum between incidental refreshes, and polling stops on 401. New arrivals are not swallowed by an earlier mark-all snapshot. Existing unrelated dashboard counters are unchanged.

## Successful event integration and security

The existing `logActivity` success producers dispatch a whitelisted event through a failure-isolated observer. Unknown actions are ignored; existing successful business-event producers supply the whitelisted event names. The new optional domain argument is backward-compatible with existing callers; hotel manifest submissions and tourist/main-admin cancellation operations pass their already-authorized domain explicitly. Activity-log storage failure does not suppress notification dispatch.

Events produced within an existing transaction use that transaction, so a rollback removes their notification writes. Existing after-commit producers remain after commit. The dedicated guest-information request hook runs only after the existing request checks. The refund hook observes the already-saved aggregate status without changing calculations, provider calls, allocations or status definitions. No email is sent by any new helper.

The payment observer resolves the existing booking reference and exact verified provider payment ID from the successful activity event and checks the matching paid transaction. A later pending transaction cannot replace the verified event's identity. Neither payment reconciliation nor checkout/webhook code was modified.

Every inbox read, count, read mutation and click is scoped to recipient role/ID and current booking ownership. Hotel property assignment is loaded from the authenticated account, not accepted from the browser. The click endpoint repeats record access checks and builds an allowlisted internal destination; it accepts no arbitrary redirect URL. Session expiry, inactive-account rules and scoped CSRF helpers are reused. GET polling cannot renew session activity. A saved role-specific notification ID resumes through the same permission-checking resolver after login.

Unavailable/deleted/reassigned records are omitted from inbox queries and denied by the resolver with a return-to-bookings fallback. Client-side stale controls show a concise fallback message rather than opening an empty modal. Read writes require POST and the scoped CSRF token. Opening a dropdown leaves unread items unread until clicked or explicitly marked.

The event uniqueness key suppresses repeated successful callbacks; message previews are static. Notifications are supplementary: missing/unreadable schema skips new writes and keeps the legacy UI. Storage failures are logged without exposing private details. There is no durable retry queue; a transient notification-storage outage can lose an event while the underlying business action succeeds. Severe connection/deadlock failures remain normal database transaction risks and are not claimed to be eliminated by exception handling.

## Modified and created files

Only the notification changes in this table belong to this task. Several listed shared files already had unrelated user edits; their full Git diffs include that prior work.

| File | Change / purpose |
|---|---|
| `.gitignore` | Make the specifically required Hostinger SQL deliverable visible to Git |
| `Ho_header.php` | Schema-gated shared hotel inbox bootstrap |
| `admin/admin_sidebar.php` | Shared main-admin inbox bootstrap; skip the legacy 4-second inbox polling when enabled |
| `operator/operator_sidebar.php` | Shared operator inbox bootstrap across operator pages |
| `admin/adbookings.php` | Explicit cancellation domain; guest-information request event next to existing email call |
| `php/profile.php` | Explicit manifest domain; preserve notification-requested section/deep links across reload, without adding a profile-page bell |
| `php/activity_logger.php` | Backward-compatible successful-event observer and optional domain context |
| `php/booking_refunds_helper.php` | Observe saved refund aggregate status for an in-app event |
| `php/request_booking_cancellation.php` | Pass the validated cancellation booking domain |
| `php/session_security.php` | Treat inbox polling/read calls as passive for session expiry |
| `php/header.php` | Schema-gated tourist inbox configuration for direct and AJAX headers |
| `js/header.js` | Bootstrap the shared tourist inbox while keeping the old bell usable until replacement |
| `includes/header_loader.js` | Cache-bust the changed tourist header script |
| `public/homepage_new.php` | Use the changed loader's modification-time asset version |
| `public/search_results.php` | Same loader cache-version update; preserve prior search-page edits |
| `php/notification_helper.php` (new) | Event definitions, recipient mapping, deduplication, authorized inbox/read queries and destinations |
| `php/notification_component.php` (new) | Schema gate, scoped configuration and shared asset rendering |
| `php/notification_inbox.php` (new) | Authenticated no-cache API, protected reads, stable click/login-resume resolver |
| `js/notification-inbox.js` (new) | Shared inbox UI, refresh/read behavior, existing booking/form/PDF control navigation |
| `styles/adbookings.css` | Booking-header-only wrapping, hidden-filter and control-size stability |
| `scripts/admin_booking_header_test.php` (new) | First-render header fixture checks without a database or JavaScript |
| `styles/notification-inbox.css` (new) | Scoped compact responsive notification styling |
| `scripts/notification_system_test.php` (new) | Memory/temporary-MySQL integration fixtures and exact deployment SQL checks |
| `scripts/notification_inbox_test.cjs` (new) | Four-role DOM/fetch/UI behavior fixtures |
| `HOSTINGER_NOTIFICATION_DATABASE_UPDATE.sql` (new) | Manual additive table/index creation and repeatable legacy history backfills |
| `HOSTINGER_NOTIFICATION_DEPLOYMENT_GUIDE.md` (new) | Database-first manual deployment, preflight, verification and rollback sequence |
| `NOTIFICATION_TEST_RESULTS.md` (new) | Executed results, limitations and staging requirements |
| `NOTIFICATION_ROLLBACK_GUIDE.md` (new) | Code restoration and data-preserving rollback procedure |
| `NOTIFICATION_IMPLEMENTATION_REPORT.md` (new) | This audit, implementation, change inventory and event matrix |

## Database assessment and changes

**Database changes are required.** The inspected existing schema cannot store per-event/per-recipient persistent reads. Only one new table is introduced. No existing table/column, booking identifier, status enumeration, foreign key or constraint is changed. No users/reference accounts are seeded.

| Database object | Change | Reason | Production SQL required |
|---|---|---|---|
| `app_notifications` | New InnoDB/utf8mb4 table | Durable recipient events and read state | Yes, manual |
| `app_notifications.notification_id` | Unsigned BIGINT auto-increment primary key | Stable history/click/arrival identity | Included in table creation |
| `recipient_role`, `recipient_id`, `event_key` | Required VARCHAR(20), unsigned INT, CHAR(64) | Authorized ownership and deterministic duplicate suppression | Included |
| `event_type`, `booking_domain`, `booking_id` | Required VARCHAR(50), VARCHAR(10), unsigned INT | Domain-safe current record navigation | Included |
| `title`, `message` | Required VARCHAR(100)/VARCHAR(255) | Concise non-sensitive previews | Included |
| `created_at`, `read_at` | DATETIME default CURRENT_TIMESTAMP; nullable DATETIME default NULL | Persistent time/read state | Included |
| `uq_notification_event` | Unique role/recipient/event key | Concurrent replay protection | Included |
| `idx_notification_inbox` | Role/recipient/read time/ID index | Efficient authorized unread counts | Included |
| `idx_notification_history` | Role/recipient/creation time/ID index | Newest-first history retrieval | Included |
| `idx_notification_record` | Domain/booking index | Related-event lookup | Included |
| New main-admin history rows | Repeatable INSERT from existing visible tour bookings/decision responses | Preserve working feed and existing booking read flags | Yes, included |
| New operator/hotel-admin history rows | Repeatable INSERT from current provider assignment | Preserve provider history without replaying session-only historical unread states | Yes, included |

There are no polymorphic foreign keys: recipient IDs refer to different existing role tables and booking IDs refer to two existing booking tables. Application queries revalidate those relationships. Existing verified foreign keys include `bookings.tourist_id → tourist.tourist_id`, `booking_tourists.booking_id → bookings.booking_id`, and `hotel_booking_tourists.booking_id → hotel_room_bookings.hotel_booking_id`. Production schema parity is unverified and must be checked manually.

| Change | Local persistent database | Hostinger database |
|---|---|---|
| New table, primary/unique keys and indexes | Applied locally on 2026-10-19; also tested in temporary fixtures | Pending manual SQL |
| History import rows | Applied locally: history imports completed | Pending manual SQL |
| Existing constraints/business data | Unchanged by this task | Unchanged; no live writes authorized |

The SQL is additive and old-code compatible. Existing data is not rewritten. Historical provider read states cannot be recovered from PHP sessions; imported provider history starts read. Main-admin legacy flags are copied and then reads become independent per administrator. Tourist local-storage records are left in place, but are not imported across accounts. New inboxes show the newest 50 authorized records; older records remain stored, and unread counts include all authorized history. There is no automatic deletion or retention change.

Manual execution order, backups, exact verification commands, conflict handling, cache steps and recovery instructions are in the deployment guide. Apply and verify SQL before switching code. A brief coordinated write pause avoids notification cutover gaps; creating an additive table itself does not require altering booking tables. The old code can be restored while retaining the new table and records. Never blindly restore a database backup over newer financial/booking records.

## Validation and remaining limits

Executed: **155 backend/database assertions**, **78 DOM-based UI assertions**, six negative local HTTP checks, syntax checks on 16 PHP/four JavaScript files, and eight existing regression suites. Exact table DDL and all three history inserts ran twice against local MySQL temporary fixtures. `git diff --exit-code -- payments` and `git diff --check` passed. See `NOTIFICATION_TEST_RESULTS.md` for commands and per-role coverage.

No production SQL, deployment, real SMTP delivery, external PayMongo request or settlement was executed. No browser was available, so rendered desktop/mobile layouts and full authenticated browser workflows remain required staging checks. Real Hostinger schema/version/permissions/load and MariaDB compatibility remain unverified.

The audited active workflow supports guest-list initial submission/edits, provider reschedule offers followed by tourist decisions, immediate successful schedule changes, and expiry/full-refund handling. It does not provide an active standalone correction-request/approval state, general tourist reschedule-request approval/rejection workflow or hotel rescheduling workflow. Existing guest-list edit notifications and the actual provider-decision events are integrated; new business states/forms were not invented. The separate legacy tourist-lock endpoint references a column absent from the inspected local `bookings` schema and is not used to invent a correction workflow in this release.

Pre-existing local hotel manifest/status changes remain a deployment dependency to review separately; this notification SQL does not silently migrate those features. No claim is made that unrelated dirty-tree changes are production-ready. No commit, staging deployment or production deployment was performed. **The implementation is ready for code/staging review, with production approval withheld.**

## Notification event matrix

All entries are triggered by existing successful activity events except the explicit information-request and saved-refund-status hooks. `T/P/A` below means the booking owner, assigned provider, and central main admins for tours; for hotel lifecycle events it means owner plus assigned hotel admin. Cancellation/refund events also include central main admins for either domain. The information request is tourist-only. No unassigned provider receives a broadcast.

Destinations: tourists use their exact existing form/details/billing/history or cancellation decision; main admins use existing booking details/guest documents or cancellation review; providers use their exact scoped booking drawer or existing guest document interface. Every destination is recomputed with current ownership/status at click time.

| Successful trigger | Recipients | Title | Description | Related record | Interactive destination |
|---|---|---|---|---|---|
| Booking Submitted | T/P/A (according to booking domain) | Booking Submitted | A booking has been submitted and is awaiting review. | Tour/hotel booking as resolved from successful producer ID | Existing exact details; terminal tourist record in History |
| Hotel Booking Submitted | Tourist + assigned hotel admin | Booking Submitted | A hotel booking has been submitted and is awaiting review. | Hotel booking ID | Existing exact details; terminal tourist record in History |
| Walk-in Booking Added | T/P/A (according to booking domain) | Booking Submitted | An assisted booking is ready for management. | Tour/hotel booking as resolved from successful producer ID | Existing exact details; terminal tourist record in History |
| Walk-in Hotel Booking Created | Tourist + assigned hotel admin | Booking Submitted | An assisted hotel booking is ready for management. | Hotel booking ID | Existing exact details; terminal tourist record in History |
| Booking Accepted | T/P/A (according to booking domain) | Booking Confirmed | The booking has been confirmed. | Tour/hotel booking as resolved from successful producer ID | Existing exact details; terminal tourist record in History |
| Hotel Booking Accepted | Tourist + assigned hotel admin | Booking Confirmed | The hotel booking has been confirmed. | Hotel booking ID | Existing exact details; terminal tourist record in History |
| Booking Rejected | T/P/A (according to booking domain) | Booking Rejected | The booking was not approved. Please review its details. | Tour/hotel booking as resolved from successful producer ID | Existing exact details; terminal tourist record in History |
| Hotel Booking Rejected | Tourist + assigned hotel admin | Booking Rejected | The hotel booking was not approved. Please review its details. | Hotel booking ID | Existing exact details; terminal tourist record in History |
| Booking Cancelled | T/P/A (according to booking domain) | Booking Cancelled | The booking has been cancelled. | Tour/hotel booking as resolved from successful producer ID | Existing exact details; terminal tourist record in History |
| Provider Cancellation | T/P/A (according to booking domain) | Booking Cancelled | The booking has been cancelled. Review the cancellation details. | Tour/hotel booking as resolved from successful producer ID | Existing exact details; terminal tourist record in History |
| Provider Cancellation Accepted | T/P/A (according to booking domain) | Booking Cancelled | The tourist selected cancellation and a full refund. | Tour/hotel booking as resolved from successful producer ID | Existing exact details; terminal tourist record in History |
| Cancellation Requested | Tourist + assigned provider + main admin | Cancellation Requested | A booking cancellation request is ready for review. | Tour/hotel booking as resolved from successful producer ID | Existing cancellation/decision view; scoped provider booking |
| Cancellation Approved | Tourist + assigned provider + main admin | Cancellation Approved | The cancellation request has been approved. | Tour/hotel booking as resolved from successful producer ID | Existing cancellation/decision view; scoped provider booking |
| Cancellation Rejected | Tourist + assigned provider + main admin | Cancellation Not Approved | The cancellation request was not approved. Review the decision. | Tour/hotel booking as resolved from successful producer ID | Existing cancellation/decision view; scoped provider booking |
| Cancellation Completed | Tourist + assigned provider + main admin | Cancellation Completed | The cancellation process has been completed. | Tour/hotel booking as resolved from successful producer ID | Existing cancellation/decision view; scoped provider booking |
| Booking Completed | T/P/A (according to booking domain) | Booking Completed | The booking has been marked as completed. | Tour/hotel booking as resolved from successful producer ID | Existing exact details; terminal tourist record in History |
| Hotel Booking No-show | Tourist + assigned hotel admin | Booking Status Updated | The hotel booking has been marked as no-show. | Hotel booking ID | Existing exact details; terminal tourist record in History |
| Guest Checked In | Tourist + assigned hotel admin | Check-in Recorded | The hotel check-in has been recorded. | Hotel booking ID | Existing exact details; terminal tourist record in History |
| Guest Checked Out | Tourist + assigned hotel admin | Booking Completed | The hotel check-out has been recorded. | Hotel booking ID | Existing exact details; terminal tourist record in History |
| Tourist Submission Requested | Tourist only | Tourist Information Required | Please submit the guest details for your booking. | Tour/hotel booking as resolved from successful producer ID | Existing email form link; current details if already submitted |
| Tourist Data Submitted | T/P/A (according to booking domain) | Tourist Details Submitted | Guest information has been submitted and is ready for review. | Tour/hotel booking as resolved from successful producer ID | Exact booking + existing guest document/PDF |
| Tourist Data Updated | T/P/A (according to booking domain) | Tourist Details Updated | Updated guest information is ready for review. | Tour/hotel booking as resolved from successful producer ID | Exact booking + existing guest document/PDF |
| Reschedule Offered | Tourist + assigned provider + main admin | Rescheduling Decision Required | Please review the rescheduling options for your booking. | Tour/hotel booking as resolved from successful producer ID | Existing cancellation/decision view; scoped provider booking |
| Booking Rescheduled | Tourist + assigned provider + main admin | Booking Schedule Updated | The booking schedule has been updated successfully. | Tour/hotel booking as resolved from successful producer ID | Existing cancellation/decision view; scoped provider booking |
| Reschedule Decision Expired | T/P/A (according to booking domain) | Rescheduling Decision Expired | The decision period ended. Review the cancellation and refund status. | Tour/hotel booking as resolved from successful producer ID | Existing exact details; terminal tourist record in History |
| Booking Payment Verified | T/P/A (according to booking domain) | Payment Confirmed | Payment has been verified and recorded for the booking. | Tour/hotel booking as resolved from successful producer ID | Existing billing; exact administrator/provider booking |
| Online Balance Payment Verified | T/P/A (according to booking domain) | Payment Confirmed | Payment has been verified and recorded for the booking. | Tour/hotel booking as resolved from successful producer ID | Existing billing; exact administrator/provider booking |
| Booking Payment Updated | T/P/A (according to booking domain) | Payment Recorded | A payment has been recorded for the booking. | Tour/hotel booking as resolved from successful producer ID | Existing billing; exact administrator/provider booking |
| Hotel Booking Payment Recorded | Tourist + assigned hotel admin | Payment Recorded | A payment has been recorded for the hotel booking. | Hotel booking ID | Existing billing; exact administrator/provider booking |
| Online Balance Payment Initiated | T/P/A (according to booking domain) | Payment Pending | A payment is awaiting verification. Review booking billing. | Tour/hotel booking as resolved from successful producer ID | Existing billing; exact administrator/provider booking |
| Booking Expense Added | T/P/A (according to booking domain) | Booking Charges Updated | Booking charges have been updated. Review the billing details. | Tour/hotel booking as resolved from successful producer ID | Existing exact details; terminal tourist record in History |
| Hotel Booking Expense Added | Tourist + assigned hotel admin | Booking Charges Updated | Hotel booking charges have been updated. Review the billing details. | Hotel booking ID | Existing exact details; terminal tourist record in History |
| Refund Status Updated | Tourist + assigned provider + main admin | Refund Status Updated | The refund status has changed. Review the cancellation details. | Tour/hotel booking as resolved from successful producer ID | Existing cancellation/decision view; scoped provider booking |

## Local activation follow-up ? 2026-10-19

The first handoff left the persistent localhost migration pending. This caused successful tourist submission emails to send while the schema guard disabled the in-app inbox. After the owner reported this, the supplied additive SQL was applied to the verified loopback-only local database: `app_notifications`, all indexes and the three history imports now exist locally (43 history rows at activation). No existing business tables were altered. The 149 backend and 66 UI fixture assertions passed again. Earlier reminder sends while the table was absent were not stored as information-request notifications; refresh the administrator and tourist pages and resend through the existing action to create one. Hostinger SQL and deployment remain pending owner control.

## Homepage dynamic-script lifecycle fix ? 2026-10-19

The tourist information request was confirmed stored and unread in localhost. The homepage calls `initHeader` during DOMContentLoaded and then loads the inbox module dynamically. While the document remained interactive, that module incorrectly waited for DOMContentLoaded a second time; its mount never ran, and the legacy handler had been disabled. The initializer now distinguishes parser-deferred scripts from late dynamic scripts. The header also explicitly mounts on script load, keeps the existing bell handler until replacement, and permits a failed script load to be retried. Shared-script/cache versions were advanced. Regression fixtures now cover late dynamic loading with no second DOMContentLoaded and opening the resulting bell: 149 backend and 68 UI assertions pass. Local Apache HTTP checks confirmed both updated scripts are served. No new SQL, emails or production changes were performed for this fix. Actual browser interaction remains unverified because no browser surface is available in the session.

## Tourist header-only placement and original design ? 2026-10-19

Following the owner?s preference, the standalone tourist profile inbox slot and its sticky positioning were removed. Tourist inbox mounting is restricted to `.head-nav-main-header .head-nav-notif-wrap`. The original `img/notificon.png` black bell, transparent button, and original dropdown dimensions/colors are restored for tourists; other role inbox styling is unchanged. Profile pages continue loading only the logic required to open authorized booking/form links and resume login destinations; without a site header they create no bell, inbox fetch or polling timer. New fixtures verify the original image, header-free placement, exact booking navigation and login resume. Current results: 149 backend and 72 UI assertions passed, plus PHP/JS syntax and whitespace checks. Asset versions were advanced to 3. No database changes or production deployment were made for this UI adjustment.

## Content-sized tourist dropdown ? 2026-10-19

Removed the fixed tourist dropdown height. The panel now sizes naturally to its contents, capped at 520px or the available viewport height. The notification list scrolls only beyond its maximum height; the heading remains visible. Original header-only bell placement and styling are retained. Cache versions advanced to 4.

## Admin booking entry point and header stability ? 2026-10-19

The unstyled notification destination was caused by linking directly to the implementation at `/admin/adbookings.php`, where relative asset URLs resolved into nonexistent `/admin/styles`, `/admin/js` and `/admin/img` directories. Administrator notification/fallback destinations now use the existing public `adbookings.php` wrapper; the same public-wrapper correction is applied to operator destinations. Direct GET visits to the admin implementation redirect to the root wrapper with the complete query string preserved. No booking, authentication, payment or cancellation processing was changed.

The booking header now renders its bell/profile controls server-side and hides irrelevant date filters in the initial HTML. A local inline initializer immediately handles filter changes, independently of delayed third-party scripts; the old late initializer was removed. Booking-specific responsive CSS keeps control sizes and wrapping consistent, and its stylesheet uses a modification-time cache version.

Executed: 155 backend assertions, 78 UI/initializer assertions, 28 first-render PHP header assertions; exact booking/cancellation canonical redirects verified over local HTTP, plus HTTP 200 checks for both booking/theme stylesheets, the logo and inbox script. Existing tourist submission email, manifest UI and manifest edit regression suites passed again. Source syntax and whitespace checks passed; `payments/` remains unchanged. Actual rendered browser alignment remains unverified in this session because there is no connected browser surface. No database updates or production deployment were performed for this fix.
