# iTour Mercedes — Phase 2 delivery audit

Date: 2026-10-08, Asia/Manila. Changes are local and undeployed. The previous homepage query batching and dashboard optimization remain intact.

## Scope and measurement limits

This pass inspected current public HTML, shared image markup, CSS/JS dependencies, fonts, upload/cache conventions and live compression/cache headers. Live HTTP requests used Node's system certificate store with normal TLS verification; no certificate checks were disabled. Browser inventory returned no available browser.

Consequently, the historical **4.2 MB / 71 requests** cannot be reconstructed exactly. There is no supplied historical HAR, matching test waterfall or browser capture for this pass. The HTTP inventory below measures individual resources, not browser scheduling, lazy loading, cache hits, range requests, rendered geometry or initial page transfer. It must not be substituted for Pingdom/Lighthouse results.

## Current live asset inventory

The live homepage returned 200, Brotli encoding and dynamic `no-store, no-cache, must-revalidate` protection. One standalone GET measured **48,099 encoded HTML body bytes**, approximately **666 ms to response headers** and **776 ms to body completion**. These timings include network/CDN/origin work; they do not isolate PHP time or establish a server regression.

| Resource group | Inventory | Measured size | Interpretation |
| --- | ---: | ---: | --- |
| HTML | 1 document | 48,099 B | Actual encoded standalone GET body |
| Stylesheets directly referenced in HTML | 9 URLs | 50,621 B | Sum of encoded standalone GET bodies |
| Scripts directly referenced in HTML | 9 URLs | 28,250 B | Sum of encoded standalone GET bodies |
| Images/icons directly referenced in HTML | 36 unique URLs | 11,141,104 B | Sum of HEAD Content-Length representations; includes hidden/offscreen assets, not initial browser transfer |
| Video | 1 URL | 36,423,502 B | Complete file Content-Length; autoplay/range download amount unmeasured |
| Known dynamic first-party text | 4 URLs | 20,865 B | Auth markup/script and mobile-scroll JS/CSS standalone GET bodies; not a waterfall |
| Third-party text subset | Google Fonts CSS and Font Awesome CSS | 19,112 B | Already included in stylesheet total; do not add twice |
| Fonts | CSS font candidates | Browser-loaded total unmeasured | HTTP font selection depends on user agent, unicode usage and matching font faces |
| Other requests | Auth/config, favicon timing, optional integrations, background URLs | Browser total unmeasured | Conditional resources remain functional |

The 55 unique direct asset URLs exclude the document, fonts, CSS backgrounds and some dynamic resources. **55 is not an observed replacement for the baseline's 71 requests.** Repeated image references generally share identical URLs; repeated elements are not proof of duplicate downloads.

The asset-by-asset public measurements are in `PHASE2_ASSET_INVENTORY.csv`. `PHASE2_IMAGE_INVENTORY.csv` records the 63 image elements in the local pre-change guest HTML, with source sizes, intrinsic dimensions and loading attributes. Local and deployed content differ, so local image sizes are not used as live payload values.

### Largest significant live resources

| Resource | Live HEAD representation bytes | Exposure / decision |
| --- | ---: | --- |
| `img/1762526958_samplevideo.mp4` | 36,423,502 | Below-hero featured video; existing autoplay, controls, loop and metadata preload retained. Full file size is not initial transfer. |
| `img/jacqueline.png` | 1,988,910 | Hidden developer dialog; already lazy-loaded. |
| `img/oliver.png` | 1,594,227 | Same dialog; already lazy-loaded. |
| `img/jaspher.png` | 1,449,557 | Same dialog; already lazy-loaded. |
| `img/herohomepage2.png` | 924,597 | CSS hero background; early-render candidate. No quality reduction or new loading delay applied. |
| `imagess/Caringo.jpg` | 855,320 | Destination image/card; filename suffix alone does not prove encoded format. Live response reports image/png. |
| `php/upload/package_image_6ab87bb0abd47.png` | 688,122 | Popular-tour content; existing card lazy loading retained. |
| `php/upload/package_image_6ab87bc349910.png` | 652,648 | Same card family. |
| `img/newlogo-ui-v1.png` | 540,047 | Header branding and shared uses; much smaller live representation than the local file. No new conversion applied. |
| `img/HomeBG.png` | 20,549 | CSS page background; local mobile CSS disables it at small widths. Actual browser request behavior unmeasured. |

Exposure describes source placement, not measured above/below-fold geometry at an arbitrary viewport. The CSS fixes hero photo at 330×400 px, recent cards at 186 px image height, booking icons at 29×29 px, ribbon icons at 18×18 px (16×16 on mobile), footer seal at 92×92 px (72×72 on mobile), collaborator seals at 76×76 px (66×66 on mobile), and developer avatars in fixed containers. These are CSS rules, not browser geometry measurements. Local oversized legacy package files remain a potential issue, but current live package files differ.

## Problems found and changes made

| Location | Cause / severity / evidence | Final change and preservation |
| --- | --- | --- |
| Static homepage images | Missing dimension hints; low severity because most slots are already CSS-sized. Source dimensions verified with getimagesize in the offline audit. | Added dimensions to four booking icons and five destination-category images. CSS width/height/object-fit rules and source URLs unchanged. |
| `footer.php` | Nine static branding/developer images lack dimensions. Already lazy-loaded and CSS-sized. Low severity; CLS improvement not established. | Added exact source width/height attributes only. Lazy loading, fallback handlers, colors, cropping and dialogs unchanged. |
| `public/hotel_resorts.php` | Favorites CSS/JS and payment-confirmation UI script lack cache-busting despite live seven-day static caching. Medium stale-release risk. | Added the existing filemtime versioning convention to three URLs. File contents, script positions/order and behavior unchanged. No longer cache lifetime applied. |
| Production compression | Historical gzip grade conflicts with current verified responses. | No compression change: gzip works for HTML, CSS, JS, JSON and XML; Brotli was observed on HTML/CSS/JS. Vary: Accept-Encoding present on tested text. |
| Production caching | CSS/JS and sampled images have public max-age=604800; HTML/config JSON have no-store protections. | Preserved current policy and private-response protection. No blanket cache rule added. |
| Render-blocking resources | Head CSS, navigation-progress and loader scripts are discoverable before body rendering. Fonts/styles needed for existing design. Impact cannot be isolated without a browser trace. | No script/CSS reordering, async CSS conversion or library removal. |
| Font display | Inter CSS already uses swap. Font Awesome's live CSS uses block; homepage uses one version, while listing+footer sources reference 6.5.1 and 6.5.0. | No typography/icon change. Duplicate-version consolidation deferred until visual/icon verification is available. |

The static dimension change adds **18 dimension pairs** to the local rendered homepage's 63 images, including its shared footer. It adds no metadata reads to page requests. A runtime helper for dynamic image dimensions was evaluated, measured around 11 ms for a full local inventory, and removed before finalization. Dynamic uploaded images remain unchanged. No helper or additional compression stage is shipped.

Most affected slots already reserve space in CSS. Dimension hints address missing markup and provide correct source proportions, but this pass does **not** establish improved CLS, LCP or rendering time. Cache-busting improves release correctness; it does not itself reduce request count or cold-cache transfer.

## Request chain and font audit

The homepage server-renders the header. `js/homepage.js` skips its fallback header fetch when content is present. The homepage has a legacy login container and loads login markup through its existing path; `ensureGlobalAuthModal()` returns early for that container. No confirmed duplicate header/auth request was removed.

`js/header.js` dynamically loads `js/mobile-scroll.js`, which loads its CSS. Homepage authentication initialization fetches `logsign-modal.html?v=16`, then loads SweetAlert2 if needed and `logsign.js?v=18`. Turnstile/config/challenge requests are interaction-dependent. Footer complaint/legal/consent assets remain because those features are used. No required request was removed simply to reduce the count.

Google Fonts' actual audit response contained Inter swap declarations. The HTTP client received TTF candidates; a browser can receive different WOFF2/subset CSS. Font Awesome 6.5.0 WOFF2 HEAD sizes were brands 116,672 B, solid 156,532 B, regular 25,480 B and compatibility 4,796 B; these are candidate file sizes, not proof all four download. CDN font responses already have long-lived immutable caching. SweetAlert2 HEAD reported a 21,297 B Brotli representation and seven-day browser caching. Those libraries remain untouched.

The versioned homepage/shared assets already support cache invalidation. Other unversioned/dynamically constructed asset references, including mobile-scroll URLs, remain candidates for a separately tested change; no global lifetime extension was made.

## LCP and initial document investigation

The exact LCP element remains **unidentified in this pass**, because no browser performance entries or historical waterfall are available. Candidates include the heading, CSS hero background, initial hero photo and intentional loader content. The hero photo is server-rendered and has no lazy-loading attribute. Hero CSS background discovery follows stylesheet loading. Neither was made lazy. No priority/preload change was made without an established candidate.

The loader intentionally waits a minimum 480 ms and has a 1400 ms maximum timer; it dismisses after document readiness rather than waiting for all assets. Its behavior remains unchanged. Carousel/background loading changes could alter immediate appearance, automatic rotation or gallery interactions, so none was applied.

Initial response sources were reviewed for duplicate includes/queries and external blocking work. Existing homepage featured batching is retained. Shared navigation queries and compatibility checks remain; this phase found no new profiled database bottleneck warranting a rewrite. A single live TTFB value cannot establish that a particular PHP query is responsible for delay.

## Regression checks

- PHP lint passes for homepage, footer, catalog template and the added CLI test.
- `scripts/performance_image_delivery_test.php` verifies all 18 new static image tags against source dimensions and ensures no runtime metadata helper remains.
- Local before/after DOM comparison preserves all 63 image elements and every non-dimension image attribute. Entire homepage responses match after stripping dimension pairs and normalizing CRLF/LF. No source URL, loading flag, alternative text, handler or other markup changed in that comparison.
- The featured-content/dashboard offline regression remains passing.
- Existing authorization static suite, upload regression, HTTPS regression and PayMongo critical-refund fixtures pass.
- Guest HTTP smoke tests cover homepage, destinations, tour/hotel listing tabs, About, search, signup, tourist profile/booking redirects and admin/operator/hotel login redirects. Final responses are 200 with no scanned PHP fatal/parse/PDO exception output.
- Catalog versioned URLs are checked to serve the same bytes as their unversioned paths.
- Task diff checks pass. No JavaScript, CSS, image binary, uploader, database schema, security/payment/session handler or business calculation was edited by this phase.

No browser-level verification is claimed: navigation, modal interactions, fonts/icons, visual layout, JS console, exact fold, LCP, Google login, OTP, authenticated sessions, booking/boat/PAX/capacity/availability, hotel bookings, checkout/return/status/balance/refunds, actual upload create/update, notifications and email delivery remain untested end to end. Source preservation and offline fixtures do not replace those tests. No production records or financial transactions were modified.

## Performance comparison

| Metric | Historical baseline | After Phase 2 deployment |
| --- | ---: | --- |
| Page size | 4.2 MB | Not measured; not deployed |
| Requests | 71 | Not measured |
| Load time | 3.35 s | Not measured |
| Pingdom | 80/C | Not measured |
| LCP | 3.3 s | Not measured |
| FCP | 1.2 s | Not measured |
| CLS | 0.025 | Not measured |
| Speed Index | 3.3 s | Not measured |
| Hostinger performance | 1/100 | Not measured |

The standalone current live measurements above are audit observations before deployment, not an after comparison. No website speed, payload reduction or request-count improvement is claimed. Local HTML grows slightly to carry dimension attributes. Actual benefits require the same external test after deployment.

## Intentionally unchanged and follow-up

All photo bytes, source resolution, existing automatic upload compression/settings and quality exceptions remain unchanged. Q76 derivatives were not expanded or generated. Original zoom/gallery behavior remains intact. No cookie-free domain was introduced: there is no measured cookie-overhead benefit sufficient to justify additional domain/DNS/security/deployment work. No aggressive private/static caching, security weakening, risky script reordering, architecture rewrite or broad database optimization was applied.

The largest remaining opportunities require a browser waterfall and quality review: actual video range transfer, hero-background delivery, which package/gallery images load initially, and verified duplicate icon CSS on the catalog. Re-test the canonical homepage and catalog after normal deployment, with matching Pingdom location/settings and desktop/mobile browser traces. Keep historical metrics until new tests exist.

Evidence and pre-change application snapshots are in `backups/phase2-performance-20261008/` (ignored and HTTP-blocked). Rollback only this phase's dimension and versioning hunks; preserve the earlier query optimizations and unrelated working-tree edits. No deployment, commit or push was performed.
