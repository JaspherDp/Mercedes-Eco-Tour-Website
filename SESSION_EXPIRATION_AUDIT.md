# Administrative session expiration audit

## Panels and authentication

| Panel | Login page and handler | Account identity | Server validation | Logout |
| --- | --- | --- | --- | --- |
| Main administrator | `php/admin_login.php` (GET form, POST credentials); `includes/auth/admin_login.php` and `admin-login.php` are aliases | `admin_users.admin_id`, session `admin_id`, `admin_logged_in` | `AdminRequireLogin` / `AdminValidateSession` in `php/admin_auth_helper.php` | `adhomepage.php?action=logout` via `admin/adhomepage.php` |
| Operator | `php/operator_login.php`; `includes/auth/operator_login.php` and `operator-login.php` are aliases | `operators.operator_id`, session `operator_id`, `operator_logged_in` | `OperatorRequireLogin` / `OperatorValidateSession` in `php/operator_auth_helper.php` | `operator/operator_sidebar.php?action=logout` and the operator dashboard logout action |
| Hotel/resort | `php/hotel_admin_login.php` | `hotel_admin_accounts.hotel_admin_id`, session `hotel_admin_id`, `hotel_admin_logged_in` | `HoRequireHotelAdmin` in `Ho_common.php` | `php/hotel_admin_logout.php` |

Root administrator/operator routes wrap files under `admin/` and `operator/`. Hotel routes use `Ho_common.php`, with most section pages rendered by `Ho_section_page.php`. Their existing authentication guards remain authoritative. The sidebars and standalone phone-registration pages now include the shared browser monitor. Report/export and AJAX endpoints retain PHP authentication rather than injecting HTML monitoring into data responses.

## Findings and fixes

1. Main administrators previously read one `admin_system_settings.settings_id=1` timeout, with a static cache. Operator and hotel accounts already had account-scoped settings. All three administrative roles now resolve `portal_account_settings` using **both role and authenticated account ID**. Main-administrator settings save the personal timeout separately from shared booking/office settings. The legacy global value is preserved for the existing tourist policy; it no longer controls administrative sessions.
2. `AppRoleSessionIsActive` previously renewed activity on every validation, including notification polling. Passive GET/AJAX checks, notification fetches, automatic badge updates and session-status checks now validate without touching the inactivity clock. Navigation, submitted actions and throttled trusted interaction reports renew it. Expiration is checked before any renewal.
3. Hotel login previously called the protected-page redirect logic before its credential POST handler whenever an old `hotel_admin_logged_in` flag survived. An expired flag could therefore divert the submitted login back to the login page. Credential POSTs now reach authentication; a GET shortcut is used only for an actually valid session.
4. Expiration previously regenerated the shared PHP session ID, allowing a delayed background response to interfere with a fresh login cookie. Administrative passive requests now read the cookie without issuing replacement session cookies; expiry clears role authentication without rotating the cookie. Successful credential authentication still regenerates the ID. A per-login generation token also prevents an old page from renewing or clearing a newer account session.
5. Login explicitly clears the role's old identity, timing, generation and CSRF state before establishing fresh authentication. Administrator and operator login already reset activity before this audit; an independent stale-timestamp failure was not reproduced in those handlers. The hotel pre-POST redirect defect and background-request behavior were concrete code findings, rather than proof of a specific Hostinger request trace.
6. JSON authentication failures no longer queue an additional login-page expiry alert. The existing administrator fetch guard hands expiration to the shared monitor, which shows one SweetAlert2 dialog and redirects to the panel-specific login page.

## Policy and storage

- This is **inactivity expiration**, not an absolute login lifetime. `auth_login_time[role]` is metadata; `auth_last_activity[role]` controls expiry.
- Settings are minutes, multiplied by 60 once for the expiration calculation. Available choices remain 15, 30, 60, 120, 240 and 480 minutes.
- Preferences are reloaded for the authenticated account on validation. Saving a new timeout applies to current sessions as well as future logins. One account's save/reset does not modify another account's timeout.
- Accounts without a personal preference use the 60-minute default. The previous global administrator setting is not copied over or used as a live administrative policy. Each administrator can now save their own choice.
- `portal_account_settings` already supports all three roles; no additional table or column is required. Apply `portal_account_settings_migration.sql` on Hostinger if that table has not yet been created, then deploy the changed files. No live deployment or Hostinger SQL was performed during this audit.
- Session configuration retains a browser-session cookie, `/` path, HTTPS-dependent Secure, HttpOnly, SameSite=Lax and strict mode. The existing 8-hour PHP garbage-collection window supports the longest selectable duration; application checks still enforce each account's own preference. Hostinger's effective session storage/cleanup configuration was not inspected.
- Public tourist authentication, booking, payment, refund and payout policies were not changed.

## Automatic expiration

`js/session-monitor.js` calls `php/session_status.php` every 30 seconds and checks again when the tab becomes visible or returns from browser history. Passive requests do not renew inactivity. Clicks, typing, input and scrolling report activity with a role-specific CSRF token, at most once per 30 seconds. The first interaction after idling reports promptly. Server validation remains authoritative; client parameters cannot select an account ID or timeout.

Expiry produces “Session Expired” and “Your session has expired. Please log in again.” The dialog redirects on confirmation or after 10 seconds. Network errors do not imply expiry. Background-tab browser timer throttling may delay the dialog; PHP still rejects expired access immediately on the next request.

## Verification performed locally

- `scripts/session_expiration_test.php`: actual credential handlers with six temporary accounts, two per role, configured for 15 and 120 minutes. Tests both account-switch directions, stale authenticated flags, login ID regeneration, passive polling, invalid/valid CSRF activity, exact timeout boundary, expiry then real re-login, three further validity checks, settings/profile navigation, old-page generation isolation, preference reload and actual logout handlers. Temporary accounts, properties, settings, logs and session files are cleaned up in `finally`. Clock boundaries are simulated; tests do not wait 15/120 minutes.
- `scripts/session_monitor_test.js`: mock-browser execution for all three roles verifies 30-second passive polling, trusted versus synthetic activity, offline behavior, exact alert text, one alert and correct redirect. This is not a visual browser test.
- Existing portal settings persistence/render tests and administrator login JSON regression tests pass.
- A local Apache HTTP request with an obsolete PHP session cookie receives 401 from the status endpoint and **no Set-Cookie header**, confirming that passive checks cannot overwrite a new login cookie.
- PHP syntax checks and JavaScript syntax checks cover modified/new runtime files.
- No visual browser automation or live Hostinger verification was performed.

## Files changed for this audit

- `php/session_security.php`: account policy resolution, fresh role state, passive validation, background cookie handling.
- `php/portal_settings_helper.php`: administrator account preferences and preservation of other saved account settings.
- `admin/adsystemsettings.php`: personal administrator timeout load/save/reset and explanatory text.
- `php/admin_login.php`, `php/operator_login.php`, `php/hotel_admin_login.php`: clean role authentication before ID regeneration; account timeout association; hotel login shortcut fix.
- `php/admin_auth_helper.php`, `php/operator_auth_helper.php`, `Ho_common.php`: consistent AJAX failures and single expiry alert handling.
- `php/session_status.php`, `php/session_monitor.php`, `js/session-monitor.js`: shared status endpoint and browser monitor.
- `admin/admin_sidebar.php`, `operator/operator_sidebar.php`, `Ho_sidebar.php`: monitor inclusion; administrator fetch guard integration; centralized operator sidebar validation.
- `admin/admin-phone-setup.php`, `operator-phone-setup.php`, `hotel-admin-phone-setup.php`: monitoring on standalone protected pages.
- `scripts/session_expiration_test.php`, `scripts/session_monitor_test.js`: regression coverage.
- `scripts/portal_settings_test.php`: account ID supplied in administrator timeout regression.
- `SESSION_EXPIRATION_AUDIT.md`: this audit and deployment notes.
