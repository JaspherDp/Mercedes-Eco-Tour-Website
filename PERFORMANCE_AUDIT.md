# iTour Mercedes performance audit and conservative implementation

Audit dates: 2026-10-01–02 (Asia/Manila). Changes are local; nothing was deployed.

The audit was presented before application files were edited. Existing working-tree changes were preserved. Only three application files were modified by this task, plus this report. Before-images, the task-only diff, browser captures, and local query plans are in `backups/performance-audit-20261001/` (ignored by Git and denied by the existing web-server rules).

## Findings and decisions

| Problem / evidence | Responsible resource | Expected impact | Recommendation / action | Risk and possible functional effect |
| --- | --- | --- | --- | --- |
| Missing root stylesheet: absent locally, production HEAD returns 404 | `footer.php` referencing `style.css` | Eliminate a failed stylesheet URL; Chrome attempted it twice in the homepage baseline | Removed that link | Low; it supplied no styles. Existing inline footer CSS remains |
| Three developer portraits load while their dialog is hidden | `footer.php`, `img/jaspher.png`, `img/jacqueline.png`, `img/oliver.png` | Defer about 5.07 MB of local source images until the dialog opens | Added native `loading="lazy"`; also applied it to dialog branding and footer logos | Low; a cold-cache image may appear shortly after opening/scrolling. Dimensions are already controlled by CSS |
| Navigation popup thumbnails load while `display:none` | `php/header.php` destination/package/hotel thumbnail templates | Defer menu-only requests; actual savings depend on shared URLs and stored content | Added native `loading="lazy"` to the three templates | Low; thumbnails may briefly load on first reveal; menu logic, links and image paths are unchanged |
| Feather CSS request fails in browser and returns CDN 404 with `text/plain` | `public/hotel_resorts.php` → `https://cdn.jsdelivr.net/npm/feather-icons/dist/feather.min.css` | Remove one failed resource URL from the shared tour/hotel listing | Removed the link after verifying failure and no other Feather reference in that template | Low; the failed URL supplied no CSS |
| Pingdom gzip grade disagrees with current production responses | Hostinger/hcdn | No missing homepage compression to recover | Preserve existing compression; do not add another layer | Adding blanket rules without need could interfere with hosting behavior |
| Production static assets already have seven-day caching | Hostinger CSS/JS responses | Repeat visits already benefit | Preserve policy; establish complete asset versioning before considering longer lifetimes | Medium for blanket changes: some assets, uploads and service-worker URLs are unversioned |
| Two Font Awesome versions on listing pages | `public/hotel_resorts.php` (6.5.1) and `footer.php` (6.5.0) | Potential CSS/font request reduction | Flag for separate icon/cascade regression review | Medium; matching library names alone does not establish interchangeable styling |
| Large original images are used at small rendered sizes | Header logo, homepage cards, hidden hotel thumbnail | Potentially much larger savings than JS changes | Review non-destructive derivatives and existing image-cache support | Medium; requires quality checks, stale-derivative handling and production-content verification |
| Repeated featured-field queries and navigation ranking aggregates | `public/homepage.php`, `php/header.php` | Potential server-time reduction as data grows | Keep queries; inspect production plans before redesign | Medium; per-column latest-nonempty values and ranking denominators must be preserved |
| Session cookie accompanies eligible first-party requests | `php/session_security.php`, same-origin static assets | Small header overhead relative to measured image payloads | No new cookie-free domain | Infrastructure complexity is not justified by this audit |

## Entry points and shared dependencies

`index.php` includes `public/homepage.php`. The latter changes its working directory to the project root, starts the hardened session, connects through `php/db_connection.php`, initializes hotel-review compatibility columns, and renders shared `includes/page_loader.php`, `php/header.php` and `footer.php`.

`footer.php` reads session state and creates complaint CSRF state for logged-in tourists. It also renders legal-policy, cookie-consent and developer dialogs. Consequently, closing the homepage session before rendering the footer could lose legitimate session writes; no such change was made.

Other public templates live under `public/`, with root entry wrappers. `php/` contains shared logic, authentication and AJAX endpoints; `payments/` contains PayMongo/booking/payment processing; role interfaces include the root `ad*`, `op*`, `Ho*` files and `admin/` and `operator/`. `styles/`, `js/`, `img/`, `imagess/`, `uploads/` and `php/upload/` hold browser assets and media. Composer supplies Google API dependencies; there is no root frontend build/package pipeline.

No applicable `AGENTS.md` was found in the project. No subagents were used.

## Homepage CSS and JavaScript inventory

This inventory was verified against rendered local HTML and a Chrome Network capture. Version query strings are omitted below where generated from `filemtime`; the capture JSON retains exact URLs. Inline styles/scripts do not incur separate HTTP requests.

| Type | Resource | Loader / purpose |
| --- | --- | --- |
| CSS, third-party | `https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap` | Homepage font; also imported by auth markup |
| CSS | `styles/homepage.css` | Homepage layout |
| CSS | `styles/back-to-top.css` | Shared floating controls |
| CSS | `styles/image-viewer.css` | Gallery viewer |
| CSS | `styles/page-loader.css` | Initial page loader |
| CSS, third-party | `https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css` | Footer and dialog icons |
| CSS, removed | `style.css` | Missing file; no styling contribution |
| CSS | `styles/complaint-modal.css` | Shared complaint form |
| CSS | `styles/legal-policy-modal.css` | Legal dialog |
| CSS | `styles/cookie-consent-banner.css` | Consent UI |
| CSS, dynamically inserted | `styles/mobile-scroll.css` | Shared scrolling helper |
| JS, head | `js/page-loader.js` | Loader timing and document-ready behavior |
| JS, footer | `js/complaint-modal.js` | Complaint form/attachment interaction and POST submission |
| JS, footer | `js/legal-policy-modal.js` | Policy modal |
| JS, footer | `js/cookie-consent-banner.js` | Consent preferences |
| JS, bottom | `js/header.js` | Navigation, authentication launcher, logout and notifications UI |
| JS, bottom | `js/recently_viewed.js` | Local recently-viewed history |
| JS, bottom | `js/homepage.js` | Homepage, search, carousel and login initialization |
| JS, dynamic | `js/mobile-scroll.js` | Loaded by header; inserts its stylesheet |
| JS, bottom | `js/back-to-top.js` | Floating controls |
| JS, third-party/dynamic | `https://cdn.jsdelivr.net/npm/sweetalert2@11` | Auth/modal alerts |
| JS, dynamic | `logsign.js?v=17` | Login, signup, OTP and password-reset UI |
| JS, dynamic | `js/turnstile.js?v=7` | Turnstile helper; loads config/API on interaction |

`logsign-modal.html?v=16` is fetched once during homepage initialization. It contains inline styles and the same Inter import. The baseline captured one Google Fonts stylesheet HTTP request, not two. No duplicate jQuery or Bootstrap load was observed on the homepage, and no duplicate external JS library load was observed there.

Four font HTTP requests were observed: two Inter WOFF2 subsets from `fonts.gstatic.com`, plus Font Awesome brands and solid WOFF2 files from cdnjs. Preserve the font family, weights and icon library until there is visual coverage for all consumers.

The homepage's initially recorded 84 DevTools request events comprised 1 document, 12 stylesheet events, 12 scripts, 51 images (including inline data URLs), 4 fonts, 2 media events, 1 fetch and 1 other request. **Ten were inline data URLs, so the actual HTTP(S) count was 74.** Of those, 67 were first-party and 7 third-party. The original Pingdom report and exact test URL were not supplied, so its precise 70-request waterfall cannot be reconstructed from this local capture.

The homepage header is server-rendered. `js/homepage.js` checks for existing header content and skips its fallback `fetch('php/header.php')`; the capture confirms no second header request. Existing lazy loading already covers most homepage content cards. Hero image preloading and autoplay video are intentional current behavior and were retained.

## Compression, caching, security and redirects

Read-only production HEAD checks used the canonical hostname found in the project: `https://itourmercedes.com/`.

| Request | Observed result |
| --- | --- |
| `/`, accepting `gzip, br` | 200; `Content-Encoding: br`; `Vary: Accept-Encoding`; `Cache-Control: no-store, no-cache, must-revalidate`; `Server: hcdn` |
| `/`, accepting only `gzip` | 200; `Content-Encoding: gzip`; `Vary: Accept-Encoding`; same dynamic-cache protection |
| `/styles/homepage.css`, accepting `gzip, br` | 200; Brotli; `Cache-Control: public, max-age=604800`; Expires; Last-Modified; ETag |
| `/js/homepage.js`, accepting `gzip, br` | 200; Brotli; `Cache-Control: public, max-age=604800`; Expires; Last-Modified; ETag |
| `/logsign-modal.html`, accepting `gzip, br` | 200; Brotli; `Vary: Accept-Encoding` |
| `/php/turnstile_config.php`, accepting `gzip, br` | 200; JSON; Brotli; `Cache-Control: no-store` |
| `/style.css` | 404 |
| `/homepage.php` | 302 to `https://itourmercedes.com/` |

These observations establish working production compression for HTML, CSS, JS and JSON. The cause of the historical Pingdom F/0 cannot be established without its waterfall/date/request headers; a different URL, older deployment, or test interpretation remains possible. SVG/XML/plain-text compression was not exhaustively verified. The exact origin-versus-CDN implementation and Hostinger hPanel settings were not accessible. No compression rules were added, and no JPEG/PNG/WebP/video/archive compression was enabled.

Project `.htaccess` has security headers, access restrictions, the local-host-exempt HTTP-to-HTTPS redirect, and the retired-homepage canonical redirect; it has no compression/Expires directives. `.user.ini` configures session protections only. Local XAMPP's `mod_deflate`, `mod_brotli`, `mod_filter` and `mod_expires` LoadModule lines are commented out and PHP `zlib.output_compression` is Off. Local configuration is therefore not representative of Hostinger compression/caching. The global XAMPP configuration was left unchanged.

No PHP/page/API response received new cache rules. CSP, report-only CSP, HSTS, framing protections, cookies, CSRF, file-access rules and upload restrictions are untouched. No service-worker caching was added. Apache documents output compression and its `Vary` handling in [mod_deflate](https://httpd.apache.org/docs/2.4/mod/mod_deflate.html); its [Expires documentation](https://httpd.apache.org/docs/2.4/mod/mod_expires.html) also distinguishes policies by content type. Existing verified hosting behavior made another application-level policy unnecessary.

The verified `homepage.php` redirect is intentional. Root HTTPS returned 200 directly. The production HTTP-port probe could not connect from this environment, so the live HTTP-to-HTTPS chain was not measured. Its implementation is present in `.htaccess`. Authentication redirects observed locally also work. None was removed. The exact redirect flagged by Pingdom remains unconfirmed without the original report.

The production session cookie is host-scoped, `Path=/`, Secure, HttpOnly, SameSite=Lax. Eligible requests to same-origin CSS/JS/images carry that cookie; it is not sent as the site's session cookie to Google Fonts, cdnjs, jsDelivr or Cloudflare. Those services can have independent cookie behavior. No full production cookie-byte waterfall was available. HTTP/2 provides header compression ([RFC 9113](https://www.rfc-editor.org/rfc/rfc9113.html#section-4.3)); production also advertises HTTP/3 through Alt-Svc, although these probes did not prove negotiated HTTP/3. My assessment is that a separate asset hostname is low priority compared with the measured image payloads and would introduce DNS/TLS, CSP and deployment work. Cookie scope was preserved.

## Images and server-side opportunities retained for review

Local media differs substantially from the reported 1.1 MB production page. Examples:

| Local resource | Source / rendered evidence | Recommendation |
| --- | --- | --- |
| `img/newlogo.png` | 5.36 MB, 6250 px wide; header rendering about 45 px and footer about 120 px | Review smaller transparent derivatives and a dedicated favicon; retain original |
| `php/upload/package_image_69fd9fb78ef9e.png` | About 29.27 MB, 4601 px wide; card about 268 px | Use an appropriately sized derivative after visual comparison |
| `php/upload/package_image_69fdf20bdd765.png` | About 18.22 MB, 4147 px wide; card about 268 px | Same |
| `php/upload/package_image_69fdf2358c919.png` | About 20.54 MB, 4147 px wide; card about 268 px | Same |
| `php/upload/package_image_69fdf248da899.png` | About 32.34 MB on disk | Review when visible/used; not all large files are initial requests |
| `uploads/hotel_contents/content_20260508165749_fa6df893ed.jpg` | About 12.28 MB, 5184 px wide; initially hidden popup | Initial request now deferred; derivative remains an opportunity |
| Three developer portraits | About 5.07 MB combined; desktop avatar about 116 px | Initial requests now deferred; retain originals pending derivative review |
| `img/1762526958_samplevideo.mp4` | About 36.42 MB; autoplay can fetch beyond `preload="metadata"` | Review a smaller video separately; changing autoplay changes current behavior |

`scripts/build_service_image_cache.php` already generates `.optimized.webp` siblings, and `public/hotel_resorts.php` already resolves such derivatives. The homepage resolver does not use that same path. The bulk conversion script was not run, existing images were not overwritten, and upload/database references were not changed. Reusing derivatives on the homepage needs freshness/fallback and visual-quality checks. Native image loading hints preserve normal `src` handling; browser loading behavior is documented in [MDN's image reference](https://developer.mozilla.org/en-US/docs/Web/HTML/Reference/Elements/img#loading).

Read-only local `SHOW INDEX`/`EXPLAIN` findings are saved in `backups/performance-audit-20261001/database-audit.json`:

- The published-destination query uses existing `idx_destination_status_order(status, sort_order, destination_id)`, with an estimate of eight rows. No duplicate index is needed.
- Each latest-featured-field lookup uses the primary key in reverse order. Ten separate lookups occur, including four inside the slider loop. They deliberately select each column's latest nonempty value, which may come from different rows; replacing them with a single latest-row query would change content.
- The header's booking-location query is a full table scan (24 estimated local rows). It applies `TRIM`, `LOWER` and `COALESCE` and parses comma-separated locations in PHP. A simple added status index is not an established fix. Production scale and the exact ranking semantics should guide future work.
- Homepage and header both read destination data for different purposes. Header package/hotel aggregates compute booking shares before slicing to four items; pushing `LIMIT 4` earlier would change the denominator.
- Existing feedback/package and hotel-review/hotel foreign-key indexes are present locally. Composite moderation/grouping indexes need production query plans and write-cost assessment before adoption.
- `ensureHotelReviewManagementColumns()` inspects schema during homepage requests and can issue compatibility ALTERs if columns are absent. Moving that responsibility into deployment migrations requires an explicit deployment strategy; it was not removed.
- Standalone `php/header.php` already releases its session lock before navigation queries. Included-header requests preserve the session for downstream writes. No session-lock changes were made.

## AJAX, scripts and integration boundaries

No endpoint, HTTP method, payload, credentials option, polling interval or dependency order was changed. Homepage auth markup is fetched once. Turnstile config has a memoized fetch with `credentials: same-origin` and `cache: no-store`; its API loads when a widget is required. `logsign.js` continues to POST authentication, signup, OTP and password-reset data. Complaint submission remains POST/FormData; geonames reads remain GET. No GET conversion of state-changing requests was attempted.

The small loader script is parser-blocking in the head and controls the loader deadline. Deferring it changes when that timer starts. Most other homepage scripts are already at the bottom; auth and SweetAlert load in a defined sequence. No blanket `async`/`defer` was added. Destination Leaflet/application scripts already use ordered defer. Listing Leaflet/flatpickr and booking dependencies remain intact.

Shared complaint/legal/cookie assets are loaded on pages with their corresponding footer controls, so they are not proven unused. SweetAlert/auth markup load early; moving them entirely to first interaction would need separate login/open-login timing tests. Firebase SDKs were not loaded by the guest homepage; their dashboard/service-worker integration remains unchanged. No global library purge, concatenation or automatic CSS purge was performed.

## Validation and measurements

Chrome headless, local XAMPP, 1440x1000 desktop and 390x844 mobile, cache disabled, 6.5-second captures. These are single-run diagnostic captures, not controlled production speed benchmarks. Media range requests, near-viewport lazy-loading thresholds, favicon behavior and CDN timing affect totals.

| Homepage capture | Before | After |
| --- | ---: | ---: |
| Desktop HTTP(S) request events | 74 | 63 |
| Desktop completed-response encoded bytes | 106.52 MB | 82.54 MB |
| Mobile HTTP(S) request events | 71 | 60 |
| Mobile completed-response encoded bytes | 34.04 MB | 15.35 MB |
| Desktop DOMContentLoaded | 3.28 s | 3.29 s |
| Desktop load event | 4.42 s | 5.11 s |
| Mobile DOMContentLoaded | 2.35 s | 3.60 s |
| Mobile load event | 3.15 s | 4.17 s |

**These timings do not demonstrate a load-time improvement.** The confirmed effect is fewer initial requests and deferred payload. Eight specific image URLs that disappeared from the desktop initial capture accounted for about 18.61 MB including response headers. Other byte differences are not attributed solely to the changes. Do not extrapolate these local totals to the reported production page or promise a Pingdom score increase. The earlier progress figures of 84→73 and 81→70 included ten inline data-URL events each; the table above excludes them.

| Feature / check | Result and limits |
| --- | --- |
| Homepage desktop/mobile | Renders; screenshots inspected; no JS exceptions; no horizontal overflow; loader dismisses |
| Deferred developer dialog | All three portraits unloaded before opening, all dialog images loaded after opening; dialog opens/closes |
| Deferred navigation thumbnails | Hotel popup opens through keyboard focus and both images load |
| Footer logos | All load after scrolling to footer |
| Destination page | Renders; no failed network requests or JS exceptions in capture |
| Tour and hotel listing tabs | Retested after Feather removal: no failed requests or JS exceptions; no horizontal overflow |
| Search results | Guest result page renders without JS exceptions/failed requests; exhaustive filter combinations not tested |
| Tourist login/signup | Login dialog opens and initializes; signup page renders. No account creation or credential submission |
| Tourist profile, tour booking, hotel booking | Guest access returns to homepage/auth UI; authenticated workflows not exercised |
| Admin/operator/hotel-owner dashboard entry | Guests are redirected to the matching role login page; authenticated dashboard/actions not exercised |
| Turnstile | Helper/config/API and challenge frame load on login interaction; production challenge/credential flow not submitted |
| AJAX | Auth markup/config requests observed successful; no endpoint code changed; authenticated mutations not tested |
| Payment/refund logic | Existing offline PayMongo critical refund fixture passes; no live checkout, webhook, cancellation, reschedule or refund executed |
| Email/OTP | Existing refund-email fixture passes; actual mail delivery and OTP verification not exercised |
| Image uploading | Existing secure-upload regression passes; no real user upload submitted |
| Firebase notifications | Source integration inspected and untouched; push permission/delivery not tested |
| Authorization | Existing static authorization suite passes; not an end-to-end role test |
| HTTPS/session settings | Existing regression suite passes |

The homepage retained an existing canceled MP4 range request (`net::ERR_ABORTED`, HTTP 206). It was present before and after; playback behavior was not changed. Existing report-only CSP diagnostics are distinct from enforced-policy failures and were not suppressed or changed. Captured non-security console errors and runtime exceptions were empty after the edits. No claim is made that all browser/security diagnostics across authenticated workflows have been cleared.

Commands run successfully:

```text
php -l footer.php
php -l php/header.php
php -l public/hotel_resorts.php
php scripts/security12_authorization_test.php
php scripts/security13_upload_regression.php
php scripts/security17_https_regression.php
php scripts/paymongo_refund_critical_fixture_test.php
git diff --check -- footer.php php/header.php public/hotel_resorts.php
```

Authenticated end-to-end coverage still requires designated test accounts and a safe payment/email/push test environment. Do not treat guest rendering and fixture passes as proof of those untested flows.

## Change record, rollback and deployment follow-up

| File changed | Exact task changes |
| --- | --- |
| `footer.php` | Removed one missing stylesheet link; added `loading="lazy"` to eight existing images |
| `php/header.php` | Added `loading="lazy"` to destination, package and hotel popup thumbnail templates |
| `public/hotel_resorts.php` | Removed one non-existent external stylesheet link |
| `PERFORMANCE_AUDIT.md` | Added this audit, resource inventory, evidence, limitations and change record |

Only the intended attributes/links differ from the saved before-images; existing unrelated edits in shared files were preserved. `backups/performance-audit-20261001/changes.patch` is a task-only review diff; its old paths point to the saved before-images, so it is not presented as a direct `git apply` rollback patch. The folder also contains `footer.before.php`, `header.before.php`, `hotel_resorts.before.php`, screenshots, capture JSON and the local audit helpers. Avoid a blanket Git checkout/reset because that would discard pre-existing user edits. Revert only the listed hunks, or restore before-images after checking for any subsequent edits.

No deployment, database migration, package installation, remote mutation or production transaction was performed. After normal deployment, inspect the production homepage and both listing tabs, repeat the same Pingdom URL/location/settings, and confirm the missing stylesheet requests disappear. Verify visible images, login/OTP, authenticated booking/payment/refund flows and push delivery in the designated test environment before considering broader changes.

Intentionally deferred: redundant compression rules; longer blanket cache lifetimes; cookie-free infrastructure; redirect/security changes; image overwrites/bulk conversion; query/schema rewrites; autoplay changes; script rescheduling; Font Awesome consolidation; and automatic CSS removal. The highest-value follow-up is a separate, visually reviewed image-derivative change using the project's existing cache conventions.
