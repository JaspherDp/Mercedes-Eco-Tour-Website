# Image performance audit — 2 October 2026

This pass makes a small, database-independent image delivery change: a losslessly recompressed copy of the main logo, plus a dedicated favicon. Existing uploaded photographs, upload optimizers, crop behavior, database paths, service derivatives, and video are unchanged. Nothing was deployed, committed, or pushed.

**Confirmed result:** the accepted logo saves 1,556,103 bytes (29.03%) with identical decoded RGBA pixels, dimensions, and non-image PNG chunks. Local cold-cache homepage image transfer fell by that same amount on desktop and mobile. A load-time or LCP improvement is **not established**. Full authenticated upload/create/update/database round trips remain unverified; isolated upload-function tests passed.

## Scope and evidence

The initial filesystem, source, database-image-reference, and browser audit was read-only. It inventoried 679 local image files and 523 local database image references before image changes. [IMAGE_AUDIT.csv](IMAGE_AUDIT.csv) gives file paths, formats, dimensions, bytes, observed rendering/loading/visibility, database references, derivative conventions, and opportunities. Unknown or unobserved contexts are explicitly identified, rather than assumed absent.

The inventory covers local media, not a copy of Hostinger's uploads or database. It is not proof that every production record/file matches locally. Public routes sampled: homepage; destination directory and destination detail/gallery; package, guide, boat and hotel listings; package details; guide/boat details; hotel details (two properties); About; room availability/cards/viewer; shared navigation, footer, developer and login dialogs. SEO/favicon markup was inspected. The listing includes guide/boat sections under the tours landing; its JavaScript canonicalizes those tab URLs to `tab=tours`.

Evidence and task-specific backups are in `backups/image-delivery-20261002/` (Git-ignored and blocked from HTTP by the existing root configuration): original copies of all edited application files, file inventory, database path inventory, before/after CDP captures, screenshots, comparison sheets, upload fixture outputs, protected-source hashes, and test results. Earlier performance-pass changes and unrelated working-tree edits were preserved. No blanket checkout/reset was used.

## Significant-image audit

Sizes are decimal bytes unless stated otherwise. Rendering varies by viewport; examples below are measured CSS pixels or identified usage bounds. The CSV contains individual source measurements. “Legacy” means current upload settings do not establish how an older stored file was created.

| Image / source group | Location and loading | Original size / dimensions | Rendered size | Current optimization behavior | Existing derivative | Opportunity / expected saving | Risk |
|---|---|---|---|---|---|---|---|
| `img/newlogo.png` | Header eager/above fold; footer below fold; initially hidden login/developer/drawer | 5,360,499; PNG 6250×6250 | Header 45×45 desktop, smaller mobile; footer about 120×120 | Static original; no uploader | `newlogo-pdf.png`, 512² / 97,433, used separately | **Implemented lossless copy: 1,556,103 bytes per unique logo fetch** | Low; original fallback |
| Same logo as favicon | Homepage, service listing and public content page heads | Same 5.36 MB source | Browser icon, about 16–32 px, high-DPI | Previously shared full logo | No dedicated small icon in these links | **Implemented 64² PNG, 6,648 bytes**; do not add its savings to logo savings when the old URL was fetched only once | Low; server-side file fallback |
| `img/textlogo2-transparent.png`, `textlogo2.png`, `textlogo2-white.png` | Header/drawer/footer wordmarks | 48,811 / 50,401 / 14,574; 642×163 PNG | Roughly 130–170 px wide by context | Already small transparent static assets | Separate color variants | Leave; no established benefit worth branding risk | Low if unchanged |
| `img/newlogo-pdf.png` | Page loader / existing PDF uses | 97,433; PNG 512² | Existing loader size | Existing small asset | Already a derivative | Retain existing role; rejected as a replacement for larger logo contexts | Visible softness when enlarged |
| `img/herohomepage2.png` | Homepage CSS hero background, eager/above fold | 924,787; PNG 1366×768 | Full hero area | Static photo background | None identified | Candidate for a separate high-quality photo comparison; saving unmeasured | LCP/visual risk; deferred |
| Destination hero/card sources, e.g. `imagess/Apuao Pequena.jpg` and `uploads/destinations/*` | Homepage hero/routes/cards; destination directory/detail; navigation | Current/local card sources up to 1920 edge; representative hero about 463 KB, 1600×1200 | Homepage hero about 328×398; cards/navigation smaller | Working protected uploader; mobile destination hero already selects card source | Existing destination thumbnails where applicable | Keep current upload behavior; context-specific future work only | Crop/viewer integration |
| `imagess/Apuao Grande_Gallery (2).JPG` and destination gallery family | Detail gallery and full viewer | Example 12,275,096; JPEG 5184×3456; 16 of 22 local gallery references exceed 1920 edge | Gallery thumbs versus fullscreen | Legacy originals; current gallery upload max 1920 | 73 files in `imagess/destination-thumbs/`; JS selects matching JPG for legacy `imagess/` paths | Already separated thumbnails/full viewer in destination code; audit missing cases separately | Do not replace viewer original |
| `php/upload/package_image_69fd9fb78ef9e.png` | Homepage/package cards below fold; can still load initially on desktop | 29,268,361; PNG 4601×3451 | Homepage card about 268×186 | Legacy oversized original; current admin uploader already optimizes | `.optimized.webp`, 1400×1050 / 146,796 | Nominal 29,121,565 fewer bytes if reused, **not accepted** after quality comparison | Existing Q76 derivative softer |
| `php/upload/package_image_69fdf20bdd765.png` | Same card family | 18,217,586; PNG 4147×3110 | About 268×186 | Same | `.optimized.webp`, 1400×1050 / 52,158 | Nominal 18,165,428; deferred | Detail loss / gallery reuse |
| `php/upload/package_image_69fdf2358c919.png` | Same card family | 20,539,845; PNG 4147×3110 | About 268×186 | Same | `.optimized.webp`, 1400×1050 / 63,914 | Nominal 20,475,931; deferred | Detail loss / gallery reuse |
| `php/upload/package_image_69fdf248da899.png` | Additional horizontal card, often outside initial viewport | 32,340,568; PNG 4147×3110 | About 268×186 | Same | `.optimized.webp`, 1400×1050 / 382,432 | Nominal 31,958,136; deferred | Detail loss / gallery reuse |
| `uploads/guide*.png` | Guide cards/details; hidden/offscreen carousel cards may remain lazy | Seven local sources, 600²; largest about 649 KB | Homepage about 173×185; listing about 224 px wide; larger detail view | Current 600px cropped uploader | No new derivative needed | Leave; avoid reducing already small portraits | Faces and detail enlargement |
| `uploads/boat*` | Boat listing/detail/viewer | 55 referenced originals; 54 above 1920 edge; some approximately 32 MB | Card versus larger detail/viewer | Current uploader max1920; legacy service cache already used | `.optimized.webp`; existing search JPEG thumbnails | Keep resolver/upload behavior; review specific viewer quality separately | Existing cache may serve larger views |
| `uploads/hotel_contents/*` | Hotel cards, detail hero, tiny detail thumbs and viewer | 38 unique local referenced paths; most are oversized legacy files | Detail hero about 872×360; thumb about 139×90 | Current uploader stores1920 WebP; listing resolves legacy cache | `.optimized.webp` for legacy JPEG/PNG | Dedicated detail thumbnails could save substantial bytes; not measured/implemented | Same original used for zoom |
| `uploads/hotel_rooms/*` | Search-generated room cards and room viewer | 30 unique local paths; current outputs up to1600 WebP, example311,016 | Card/thumbnails and larger viewer | Current uploader already optimizes; helper resolves old PNG paths to existing WebP siblings | WebP sibling compatibility behavior | Preserve current behavior and stored paths | Do not “repair” DB paths |
| `uploads/featured/*` | Homepage featured tiles/background slider/full viewer | 23 unique references; current examples2399×1599, about608–852 KB; some older sources larger | Tiles versus fullscreen | Protected2400 uploader | Current stored upload itself optimized | Leave; separate card variant only after quality review | Viewer and text-detail quality |
| About gallery (`uploads/about*` / DB gallery paths) | About gallery/viewer, below fold | Five local references, up to1920; largest566,044 | Gallery tiles/full view | Protected crop/1920 upload optimization | Stored optimized file | Leave | Preserve crop and viewer |
| `img/jaspher.png`, `img/jacqueline.png`, other developer portrait | Initially hidden developer modal; lazy and absent from initial fetch in tested session | About1.46 MB,1.98 MB,1.63 MB;1254²/1563²/1254² | Small portrait circles/cards | Static; no public upload handler identified | None identified | On-demand payload candidate, not homepage-critical; deferred | Faces/detail |
| Other footer marks | Below fold, existing lazy behavior | Municipality mark about206 KB/512²; tourism mark about519 KB/500² | Roughly120 px | Static branding | Existing source sizes | Leave pending individual alpha/edge comparison | Branding/transparency |
| OG/social/app icon | Inspected public head metadata | No explicit `og:image`, `twitter:image`, or Apple touch icon found in sampled source/DOM | Platform-dependent | No metadata image replacement made | Not established | Separate SEO task; never use64px favicon as social image | High if inferred incorrectly |

The audit did not blindly add lazy loading, srcset, CSS dimensions, or new photo derivatives. Existing native lazy loading on navigation/footer/dialog images remains in place. Critical hero/header images remain immediate. No layout/design changes were made. The accepted UI logo retains full dimensions, so this pass reduces transfer bytes, **not decoded image memory**.

## Existing upload-time optimization — preserved

`php/secure_upload_helper.php` verifies uploaded-file status and real JPEG/PNG/WebP MIME against extensions, validates byte/pixel/edge limits, handles JPEG orientation, preserves aspect ratio without enlarging, and preserves alpha where applicable. Protected content callers accept up to **40 MiB**, **40 million pixels**, **12000px edge** before processing. Outputs use JPEG88, WebP86, or lossless PNG level6 unless a caller explicitly differs. Failures throw/reject rather than silently storing an unoptimized “success”. Output dimensions below are maximum bounds, not forced aspect ratios.

The existing browser optimizer (`js/image-upload-optimizer-v2.js`) accepts40 MiB/40MP/12000px, prepares bounded previews (often4096px), and uses high smoothing. JPEG/WebP starts around0.90, with existing adaptive lower settings when preferred size is exceeded; PNG remains lossless. Depending on uploader/crop/size it may skip encoding or force it. These client and server stages already existed. This task adds **no upload processing stage** and does not change these settings.

| Protected flow | Handler / UI | Crop / resize / format | Filename, database, original and failure behavior |
|---|---|---|---|
| Destination | `admin/addestination.php::destinationUpload`; `js/admin_destinations.js` | Card4:3/1600×1200; hero original ratio bounded2400×1600; gallery3:2/1920×1280 client JPEG export. Server edges1600/2400/1920; native validated format | Field + random hex name in `uploads/destinations`; `destinations.card_image`, `hero_image`, `destination_gallery.image_path`. No new file retains existing path. Stored optimized file is intended asset; no separately retained raw original required. Invalid optimization rejects save. |
| Hotel/Resort | `Hocontents.php::HoSaveUploadedContentImage` and indexed multi-upload wrapper | No crop; preserve ratio; client forced WebP0.90/1920 with preferred2MiB; server WebP86/max1920 | Timestamp/random `.webp` in `uploads/hotel_contents`; `image_path` / gallery JSON. Wrapper cleans newly created files on partial failure. Existing storage architecture retained. |
| Featured | `admin/adfeatured.php::saveFeaturedMedia` | Image preview4096; crop3:2/export2400×1600 JPEG; server max2400/native validated format | Random `featured_…` path in `uploads/featured`; stored media paths retained. Failure rejects save. No change to featured video handling. |
| Tour packages (admin) | `admin/adtourpackages.php`; `php/update_tour_contents.php` | Photo4:3/1600×1200; map/location16:9/1920×1080; server40MiB/max1920/native format | Existing field/unique names in `php/upload`; no-file fields retain paths. Existing transaction rollback/new-file cleanup remains intact. |
| About gallery | `admin/adabout.php` | Crop3:2/export1920×1280 JPEG; server40MiB/max1920/native format | Random about names under `uploads`; existing paths retained without new upload; errors reject save. |

Local PHP permits100M per upload,120M POST,50 files,512M memory. Hostinger PHP limits were **not tested**; code-level40MiB acceptance does not prove identical hosting limits. No hosting settings were changed.

## Other uploaders reviewed — no changes

| Flow | Current handling | Integration / reason to defer |
|---|---|---|
| Operator packages | `operator/optourpackages.php` crops4:3; `php/update_operator_package.php` accepts base64 envelope up to8MiB, decoded image up to5MiB, JPEG/PNG; secure JPEG88/PNG6 re-encode, no equivalent1920 resize | Existing random `pkg` name in `php/upload`, DB path unchanged. Different from admin intentionally left alone; change needs its own workflow test. |
| Tour guides | `admin/adtourguides.php`: JPEG/PNG/WebP,40MiB, crop1:1,600px bound, secure re-encode | Existing profilepicture paths/random guide filenames; portraits may also be enlarged in details. No smaller variant proposed. |
| Boats | `admin/adboats.php`; `admin/upload_boat_image.php`: JPEG/PNG/WebP,40MiB,16:9/1920×1080 crop; server1920 | Existing boat/index/random names and returned URL. Existing `createSearchCardThumbnail` creates max800 JPEG78 with light transparency background. Do not add another layer. |
| Rooms | `Horooms.php`: JPEG/PNG/WebP,40MiB; no crop; client forced WebP0.90/preferred1.5MiB/max1600; server WebP86/1600 | Timestamp/random names, `main_image_path`/gallery JSON; full viewer shares stored image. `HoResolveHotelRoomImagePath` supports missing old-extension paths via WebP sibling. |
| Tourist avatar | `php/profile.php`:5MiB secure native-format re-encode, existing UI crop | Keep profile/session/upload workflow intact. |
| Admin / hotel-owner avatar | `admin/adadministratorprofile.php` and `Ho_section_page.php`:2MiB MIME-validated move, no comparable resize | Separate proposal only: conservative display copy after portrait quality/workflow audit; benefit not yet measured. |
| Operator avatar | `operator/opprofile.php`:3MiB, stored in `uploads/profile`, no comparable resize | Same defer decision; no filename/path/workflow change. |
| Developer portraits | Static `img` assets | No corresponding public uploader found; retain existing lazy on-demand load. |

For all photo flows, do not infer that an old image requires full original retention merely to match a new architecture. Existing uploaders intentionally store their optimized output. Conversely, legacy gallery originals currently used for zoom must not be overwritten.

## Existing derivative/cache system

`scripts/build_service_image_cache.php` scans boat JPEG/PNG, `php/upload/package_image*`, and hotel-content JPEG/PNG. It writes a sibling `basename.optimized.webp`, caps **width** at1400 (not longest edge), preserves ratio/alpha, does not upscale, uses WebP76, and retains the original. It skips a derivative whose mtime is at least the source mtime. Decode/encode failures increment failures; it does not update DB paths. It is not an atomic, validating cache publication system and was **not run or rewritten**.

`public/hotel_resorts.php::hoLandingOptimizedImagePath` resolves existing siblings and otherwise retains the stored/original URL. External URLs are retained. The resolver tests existence, not decoded validity or freshness. `public/package_details.php` and `php/service_catalog_helper.php` also have existing optimized-image selection, including contexts that can reach larger views. Reusing the resolver everywhere without distinguishing card and viewer could reduce visible quality. Existing missing-file fallback is useful, but a corrupt/stale existing derivative is not fully covered. These behaviors were documented, not expanded or rewritten.

Destination JavaScript has a separate existing convention: `imagess/...` gallery sources map to `imagess/destination-thumbs/<basename>.jpg`; an image error restores the source. Viewer URLs use original gallery paths. The mobile destination hero already chooses a card image when appropriate. Both conventions remain unchanged.

The older `compress_existing_images.php` room conversion/deletion utility was not run. No destructive media migration occurred.

## Small safe implementation plan and result

| Exact file | Affected image | Previous → accepted behavior | Benefit / savings | Functional and visual risk / fallback |
|---|---|---|---|---|
| `php/header.php` | Header tourism seal | Original → lossless `img/newlogo-ui-v1.png` |1,556,103 bytes for a unique logo fetch | Same pixels, dimensions, alpha, metadata. One-shot `onerror` restores original. |
| `footer.php` | Footer and developer-dialog seal | Same copy, sharing browser URL with header | Avoids a second logo variant fetch in these contexts; no additive saving for repeated same URL | Existing lazy loading preserved; same fallback. |
| `logsign-modal.html` | Login seal | Same lossless copy | Shared cached asset | Markup source/error handler only; authentication scripts/form fields untouched. |
| `public/homepage.php` | Favicon | Original logo →64×64 PNG if file exists | Small dedicated icon; browser request timing varies | PHP existence check falls back to original. No SEO/social URL substitution. |
| `public/hotel_resorts.php` | Hidden legacy drawer seal and favicon | Same UI copy and icon logic | Prevents fetching both old/new logo merely because hidden drawer markup remains in DOM | Exactly two markup lines changed relative to image-pass backup; cache resolver/business logic unchanged. |
| `public/about.php`, `destination.php`, `destination_results.php`, `homepage_new.php`, `hotel_details.php`, `package_details.php`, `hotelResorts.php`, `search_results.php`, `service_details.php`, `tourss.php`, `tours.php` | Favicon only | Same conditional64px favicon | Prevents public shared-header pages from requesting the old full logo solely as an icon | One head-markup line per file; same original fallback; no page logic changed. |

Initial smaller-logo candidates were rejected on visual review. The final UI copy uses **no resizing or lossy quantization**: PNG row filtering and DEFLATE level9 change only lossless representation. All original RGBA scanlines were independently reconstructed and compared, and all non-IDAT chunks retained. The original source is untouched. No public photo derivative was added.

This step works with separate local and Hostinger databases. It uses static application assets, not local record IDs, local uploaded paths, absolute Windows paths, or a migrated schema. Include both new PNGs in the same Git commit as the edited templates. Git does not automatically include untracked files. The original fallback remains in the repository. Missing UI asset falls back in the browser; missing favicon falls back when PHP renders the page. The eleven additional favicon-only changes prevent a duplicate original-logo request on other public pages using the shared header.

## Required per-image result

| Original path / status | Original format / dimensions / bytes | New path | Output format / dimensions / bytes | Reduction | Quality setting | Usage | Fallback | Visible degradation |
|---|---|---|---|---|---|---|---|---|
| `img/newlogo.png`; oversized static PNG; existing512px PDF variant not suitable as general replacement | PNG /6250×6250 /5,360,499 | `img/newlogo-ui-v1.png` | PNG /6250×6250 /3,804,396 |29.03%;1,556,103 bytes | Lossless adaptive PNG filters + DEFLATE9; identical RGBA and metadata | Header, footer, login, developer modal, hidden listing drawer | `img/newlogo.png` on image error | **No**; pixel identity verified and side-by-side inspected |
| Same original | PNG /6250×6250 /5,360,499 | `img/favicon-64-v1.png` | PNG /64×64 /6,648 |99.876% versus original used alone | GD high-quality resample, alpha retained, lossless PNG9 | Thirteen public content favicon tags only | PHP `is_file` check → original | **No noticeable degradation at intended16/32px icon display**; not suitable as enlarged logo/social image |

The two new files total3,811,044 bytes versus5,360,499 for the old shared source: **1,549,455 fewer bytes if both are fetched once**. Their independent “versus original” savings must not be summed because the old same URL could be downloaded only once. Disk usage increases because originals and new copies are intentionally retained.

Rejected candidates stay only in the ignored evidence directory: existing512px logo, generated1024/2048/4096 logo candidates, and sampled legacy Q76 package derivatives. Smaller resized logos showed softness under enlarged comparison; the accepted lossless full-resolution copy avoids that question. Q76 package comparisons showed detail differences in sand/clouds; no new uses were accepted.

## Validation and limitations

**Visual:** white and dark background comparisons at45,120,240px; homepage desktop1440×1000, mobile390×844, and desktop DPR2 screenshots; favicon comparison at16,32,64px. Accepted logo has identical decoded RGBA, including every alpha value, and unchanged color/metadata chunks. Favicon transparency and sampled branding color were retained. No stylesheet/aspect-ratio/crop change. No newly introduced visible degradation found in accepted assets.

**Uploads:** real HTTP multipart uploads of a12,275,096-byte /5184×3456 JPEG through exact extracted Destination, Hotel, and Featured upload functions, with byte-identical helper copies in an isolated filesystem. No DB connection or authenticated admin endpoint was used. Results:

| Flow | Stored output in isolated fixture | Output bytes |
|---|---|---:|
| Destination card |1600×1067 JPEG, expected `uploads/destinations/card_image_…jpg` |709,533|
| Destination hero |2400×1600 JPEG, expected `hero_image_…jpg` |1,455,041|
| Destination gallery |1920×1280 JPEG, expected `single_gallery_…jpg` |986,178|
| Hotel/Resort |1920×1280 WebP, expected `uploads/hotel_contents/content_…webp` |893,194|
| Featured |2400×1600 JPEG, expected `uploads/featured/featured_…jpg` |1,455,041|

Invalid fake JPEG was rejected; source fixture hash unchanged. Existing `scripts/security13_upload_regression.php` passed. Thirteen uploader/client/helper source files were SHA256-identical to the image-pass baseline; cache builder and service catalog helper also identical. The listing file changed only its logo/favicon lines; its derivative resolver is unchanged. Thus no new compression stage was introduced. **These tests do not verify authenticated crop UI, successful database insert/update, existing-record replacement, or Hostinger upload limits.** They must not be described as full end-to-end admin regression tests.

**Public interactions:** homepage/header; hotel navigation popup via keyboard focus; footer lazy images after scroll; developer portraits after opening; login modal appearance and existing auth/Turnstile initialization; destination gallery opens original image on desktop/mobile; hotel viewer opens on desktop/mobile; room search with two adults/one room and sample dates returns cards, and room viewer loads WebP on desktop/mobile; featured viewer opens its original stored image. Guide/boat cards and detail navigation were checked separately. No booking/reservation/payment was submitted.

**Fallbacks:** CDP blocked `img/newlogo-ui-v1.png`; header, developer, footer, login and listing drawer each loaded `img/newlogo.png`. The original is tracked and retained. Both present/missing favicon expression branches passed for all13 edited favicon templates, using an isolated absent path for the missing branch; no application asset was deleted. HTTP checks on13 public routes returned200 after normal redirects, with the expected small favicon and no PHP fatal-error output. Photo fallbacks were not rewritten. Arbitrary pre-existing corrupt derivative files are outside this change.

**Public page smoke checks:** homepage desktop/mobile/high-DPI, destination directory, package/guide/boat/hotel listing sections, About, package detail, guide detail, boat detail, two hotel details, and room gallery. Sampled initial image requests had no HTTP404 or image load failures; captured JavaScript exception arrays were empty. The unchanged video sometimes reports an aborted206 request; it was not optimized. Local HTTP testing cannot establish production HTTPS/mixed-content behavior. No new remote image origin was added. Optional/offscreen lazy images are not classified as broken simply because they have not loaded.

PHP lint passed for all15 changed PHP templates, and `git diff --check` passed. A comparison against the task-specific backups verified that all16 edited application files differ only in the intended logo/favicon references and fallbacks. Existing protected auth/payment/security logic was not edited. No functional regression was observed in the tested paths; this is **not a certification of all authenticated business workflows or production behavior**. Full upload-update regression requires designated test accounts/records and remains outstanding.

## Before/after measurement

Local XAMPP, Chrome154 via CDP, cache disabled, fresh tab,6.5-second initial capture, desktop1440×1000/mobile390×844. The local data set includes enormous legacy PNG package sources and is not comparable to the user's earlier1.1MB production Pingdom result. Counts below are total observed HTTP requests; image bytes are CDP requests classified as Image. Dynamic response bytes, favicon timing, font/service requests and page-loader timing can vary. Some final captures overlapped independent regression checks, making elapsed-time comparisons especially unsuitable for a speed claim.

| Metric | Desktop before | Desktop accepted after | Mobile before | Mobile accepted after |
|---|---:|---:|---:|---:|
| Initial image transfer bytes |81,387,115|79,831,012|14,278,201|12,722,098|
| Total initial HTTP transfer bytes |82,541,586|80,985,625|15,432,557|13,876,724|
| HTTP request count |63|63|61|61|
| DOMContentLoaded ms (single sample) |1,723.3|2,279.7|1,548.6|2,020.7|
| Load event ms (single sample) |3,048.8|3,241.8|2,205.0|2,662.6|
| CLS (single sample) |0.01344|0.01840|0|0|

**Confirmed:** image transfer decreases1,556,103 bytes on each sampled viewport; no request-count reduction in these samples; logo file decreases29.03% with pixel identity. Total transfer fell1,555,961 desktop /1,555,833 mobile bytes, with minor dynamic-response differences. Accepted DPR2 capture also served the lossless copy with no captured JS exceptions.

**Expected:** fewer network bytes on live pages that use these templates and include the new assets. Actual Hostinger before/after bytes and cache behavior need measurement after a separately authorized deployment. Other pages that still independently request the original logo/favicon may fetch both versions; no universal site-wide payload saving is claimed.

**Inconclusive:** LCP and wall-clock speed. Recorded LCP candidates changed among text, wordmark, background and page-loader content. Final load events were slower in these uncontrolled samples. No speed improvement or CLS improvement is claimed. This change should be evaluated as verified byte reduction with preserved image quality.

## Files changed and deferred work

Application files changed in this image pass:

- `php/header.php`, `footer.php`, `logsign-modal.html`, `public/homepage.php`, `public/hotel_resorts.php` (logo/favicon references).
- `public/about.php`, `public/destination.php`, `public/destination_results.php`, `public/homepage_new.php`, `public/hotel_details.php`, `public/package_details.php`, `public/hotelResorts.php`, `public/search_results.php`, `public/service_details.php`, `public/tourss.php`, `public/tours.php` (one favicon line each).

New deployable images: `img/newlogo-ui-v1.png`, `img/favicon-64-v1.png`. New documentation: this report and `IMAGE_AUDIT.csv`. Test/candidate/backup artifacts remain in the ignored backup directory. The temporary localhost-only upload test endpoint was removed. Existing dirty files outside this list belong to earlier work and were not overwritten by this image pass.

No other uploader was improved or rewritten. No photo cache builder was executed; no duplicate service derivative was created. No database paths, records, migrations, uploads, video, payment/auth/session/CSRF/CSP/HSTS/Firebase/PayMongo code or email/OTP integration were changed by the implementation.

Intentionally deferred:

1. Legacy homepage package PNGs: largest remaining byte opportunity. The existing Q76 derivatives did not meet this pass's visual bar. Next image task should compare higher-quality **card-only** variants on desktop/mobile/DPR2, retain zoom sources, and use existence/validity/original fallback with no DB migration. Generation must operate against the target environment's files; local ignored uploads/cache files do not reach Hostinger through a code-only push.
2. Oversized hotel detail thumbnails and legacy gallery sources: separate thumbnail/display versus viewer URLs only after compatibility review.
3. Avatar/developer photos: preserve face detail; optional on-demand bytes are lower priority than initial package images.
4. Standalone signup/booking/payment-success favicon/logo references: left unchanged, along with email/PDF/receipt/push/social contexts. These pages do not use the shared PHP header/footer. No app-icon/OG metadata invention.
5. Global responsive variants/srcset, cache freshness/corruption handling, and architecture changes: require a separate bounded plan. Do not route already optimized upload outputs through another compressor.
6. Authenticated upload update/create/display regressions and live Hostinger measurements: not complete; require a designated test workflow. Existing source preservation and isolated tests reduce risk but do not replace these checks.
7. Video optimization remains explicitly out of scope.

Recommended next performance task: the first item above, using code-level, target-filesystem-aware delivery with original fallbacks and conservative visual comparison. Do not solve it by editing only the local database.
