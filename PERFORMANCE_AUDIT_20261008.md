# iTour Mercedes performance audit follow-up

Date: 2026-10-08 (Asia/Manila). Local changes only; no deployment.

The attached audit instructions and the additional requirement to retain high-quality images guided this pass. The existing implementation remains the source of truth. This report supplements `PERFORMANCE_AUDIT.md` and `IMAGE_PERFORMANCE_AUDIT.md`; their older browser measurements are historical evidence, not measurements of today's changes.

## A. Problems found

| Location | Problem and cause | Impact / severity | Decision |
| --- | --- | --- | --- |
| `public/homepage.php`, featured-content reads | Ten separate prepare/execute/fetch cycles read ten fields. Each field deliberately selects its own latest nonempty value. | Medium: avoidable database round trips during initial HTML generation. | Batch the unchanged scalar lookups into one SELECT. |
| `admin/adhomepage.php`, chart counts | Accepted, completed and total counts are queried again despite already having been fetched with identical date filters. | Medium: five extra COUNT queries per dashboard request. | Reuse the values already fetched. |
| Homepage package photos and existing service derivatives | Earlier image audit identified large legacy PNGs and visible detail loss in sampled Q76 WebP derivatives. Existing homepage image resolver uses original paths. | High potential payload impact; exact current browser bytes not measured. | Preserve original image delivery in this pass. Do not activate lower-quality derivatives on additional pages. |
| `scripts/build_service_image_cache.php` | Existing cache builder uses WebP quality 76 and a 1400px width cap; it is not the upload pipeline. | Quality/freshness risks if expanded indiscriminately. | Not run or changed. |
| `public/hotel_resorts.php` and `footer.php` | Font Awesome 6.5.1 and 6.5.0 stylesheet URLs both occur in the page dependency chain. | Low/medium: potentially redundant CSS/font transfers. | Deferred pending icon/cascade browser comparisons. |
| `php/header.php`, navigation rankings | Booking-location aggregation includes functions on columns and PHP parsing of comma-separated locations. | Potentially expensive at larger production table sizes. | Keep ranking semantics; no speculative index or query rewrite. |
| Role dashboards and compatibility helpers | Multiple chart/aggregate queries; schema compatibility checks occur in request paths. | Potential server latency; production-scale cost not measured. | Retain all remaining queries and compatibility checks. |
| Static asset references | Many shared assets use filemtime versioning; some references, including favorites assets and destination `styles/style.css`, remain unversioned. | Stale assets possible if cache lifetime is increased globally. | No blanket lifetime extension. |
| Compression / production caching | Previous audit verified production gzip/Brotli and seven-day CSS/JS caching. Today's TLS probes failed certificate verification or timed out. | Historical Pingdom compression grade cannot establish today's server behavior. | Preserve server policy; no second compression layer or private-response caching added. |

The project map and workflow tracing from the existing audits were reviewed against current entry points, shared header/footer, homepage, catalog, upload helpers, session monitor and dashboard sources. Booking authentication/CSRF and webhook verification remain present. The scope includes the PHP public wrappers, shared `php/` and `includes/` helpers, admin/operator/hotel interfaces, `payments/`, browser assets and upload/cache conventions. This is not an exhaustive runtime certification of every page or account role.

## B. Changes made

| File | Change | Preservation / benefit |
| --- | --- | --- |
| `public/homepage.php` | `getLatestHomepageFields()` fetches all ten featured fields with scalar subqueries in one statement. Existing assignments read the returned array. | Every field retains its original WHERE condition, descending ID selection, trimming and fallback. Different fields may still come from different rows. Ten executions become one; this does not claim to eliminate the ten underlying index lookups. |
| `admin/adhomepage.php` | Chart data reuses `$accepted_bookings`, `$completed_bookings` and `$total_bookings`; accepted-unfinished reuses the identical accepted count. | Date filters and predicates are unchanged. Five duplicate COUNT executions are removed. Counts share one request snapshot; no cross-request cache is introduced. |
| `scripts/performance_homepage_test.php` | Added CLI-only regression checks using an in-memory SQLite database and the actual edited function/chart block. | Tests empty and sparse featured rows, NULL/empty/whitespace/Unicode/zero values, different latest rows, and dashboard status counts with and without date filtering. No live records or schemas are changed. |

Only two application files were edited. Pre-existing changes in `admin/adreportsanalytics.php`, `google_callback.php`, `php/profile.php` and `styles/adreportsanalytics.css` were preserved.

### Image quality

No image file, source URL, crop, resolution, quality setting, upload optimizer or gallery/viewer behavior was changed. No photo was recompressed, replaced, removed or routed through an additional compressor. Existing automatic upload compression and category exceptions remain intact. The existing lossless UI logo remains in use; its previously verified pixel identity is documented in the image audit, not newly asserted as a test performed today.

Future image-delivery work should retain original zoom sources and accept display variants only after visual review at their actual desktop/mobile/high-DPI sizes. A nominal quality number alone does not establish acceptable visual quality. Existing Q76 samples were not accepted by the prior image pass, so this pass does not expand their usage.

## C. Features verified

| Check | Result / limit |
| --- | --- |
| Edited PHP templates | PHP 8.0.30 lint passes; task diff whitespace check passes. |
| Featured content | Offline regression passes; current local MySQL old/new results are identical. EXPLAIN retains PRIMARY backward index scans for each subquery. |
| Admin charts | Offline fixture passes for accepted NULL/uncomplete, completed, cancelled, declined and date-filtered records. Authenticated browser dashboard not tested. |
| Public HTTP rendering | Homepage, destinations, tours listing, hotels listing, About, search and signup return 200 with no scanned PHP fatal/parse/PDO exception output. This checks HTML delivery, not browser interactions. |
| Guest access boundaries | Tourist profile, tour booking and hotel booking redirect guests; admin/operator/hotel dashboard entries redirect to role login pages. Final responses are 200. |
| Authorization | Existing `security12_authorization_test.php` static suite passes. |
| Upload handling | Existing `security13_upload_regression.php` passes; no real upload/create/update submitted. |
| HTTPS/security policy | Existing `security17_https_regression.php` passes. |
| Refund behavior | Existing `paymongo_refund_critical_fixture_test.php` passes; no provider request or financial transaction submitted. |
| Images and compression sources | Task diff contains no edits to image assets, image markup, upload pipeline or cache builder. No new visual transformation to evaluate. |

Browser inventory returned no available browser. Consequently this pass did not measure JavaScript console errors, image loading/visual layout, navigation/modals, interactive search, Google login, OTP, per-account session expiration, authenticated booking/PAX/capacity/availability, hotel check-in/out/charges, cancellation/rescheduling/refunds, payment downpayment/balance, emails or push delivery end to end. Passing offline fixtures or guest redirects does not prove those flows.

No account, booking, payment or financial-history record was changed by these checks. The SQL benchmark reads only local data and EXPLAIN plans. The regression fixture stores its data only in memory. HTTP checks use guest GET requests; ordinary session creation is expected. No production data/schema mutation, destructive operation, commit or deployment occurred.

## D. Performance comparison

Measured against saved pre-change homepage code on the same local MySQL connection: 60 samples per version after warm-up, alternating execution order, no parallel benchmark workload. The test excludes connection creation, page rendering, downloads and browser execution.

| Measured local metric | Before | After |
| --- | ---: | ---: |
| Featured-content query executions per request | 10 | 1 |
| Featured-content lookup median | 8.84355 ms | 1.39615 ms |
| Repeated dashboard count executions removed by this change | 5 | 0 |

The featured lookup median decreased about 84.2% in this local sample. This is a component measurement, not an 84.2% improvement in website load time. Dashboard execution-time savings were not measured. Query-count reduction is verified from source and the exercised chart block.

| User-supplied website metric | Before | After |
| --- | ---: | --- |
| Page size | 4.2 MB | Not measured |
| Requests | 71 | Not measured |
| Load time | 3.35 s | Not measured |
| Pingdom grade | 80/C | Not measured |
| LCP | 3.3 s | Not measured |
| FCP | 1.2 s | Not measured |
| CLS | 0.025 | Not measured |
| Speed Index | 3.3 s | Not measured |
| Hostinger performance score | 1/100 | Not measured |

No new Pingdom/Lighthouse/production speed claim is made. HTML response sizes in the smoke-check evidence are document-only bytes, not page-size totals. Local data differs from deployed data. The two PHP edits do not aim to reduce browser request count or photo bytes.

## E. Changes not made

Payment, gross/provider-fee/net-payout calculations, booking/capacity/availability rules, session durations, authentication/authorization, CSRF, Turnstile, webhook validation, email/OTP triggers, push notifications, logs, reports, SEO and UI styles remain unchanged by this pass. No autoplay change, script rescheduling, library consolidation, photo derivative generation, database migration/index addition, full-page cache, aggressive asset caching or cookie-free-domain setup was applied.

Potentially larger image savings remain deferred because the user's quality requirement takes precedence over payload reduction. Authenticated regression testing requires designated test accounts and a safe test workflow. Browser and production performance measurements remain outstanding because browser automation was unavailable and TLS probes failed. Re-test after normal deployment using the same Pingdom URL/location/settings; retain this report's unmeasured values until actual results exist.

## Evidence and rollback

`backups/performance-audit-20261008/` contains pre-change copies, the pre-existing diff, query timings/plans, refreshed read-only database audit and HTTP results. The existing backup directory is ignored by Git and blocked by server rules. Benchmark evidence contains timings/plans, not user rows or credentials.

Revert only the task's two application-file hunks and remove the added test/report if rollback is needed. Do not reset the entire working tree. No schema or image rollback is necessary. Deploy the PHP files through the project's normal release process; this task did not publish them.
