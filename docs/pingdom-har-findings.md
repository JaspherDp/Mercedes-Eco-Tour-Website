# Pingdom HAR findings and safe request reduction

Source: the user-provided Downloads/har (1).json. The HAR itself was not copied
into the repository; it may contain request cookies and other private metadata.

## What the capture proves

- 66 requests; the captured response body sizes sum to 2,174,054 bytes.
- Homepage HTML is marked Content-Encoding: br: 171,667 decoded bytes and
  53,238 recorded response bytes. All 10 recorded JavaScript responses also
  have br, and all eight stylesheet responses have br or gzip. The gzip F grade
  must not be interpreted as proof that the site sends uncompressed text.
- Images account for 1,878,202 recorded bytes across 40 requests; fonts account
  for 134,154 bytes across five requests. These are the larger remaining costs.
- The tested URL starts at http://www.itourmercedes.com/ and redirects first to
  HTTPS www, then to https://itourmercedes.com/. Those two responses took about
  683 ms combined in this capture. Use the canonical HTTPS URL when comparing
  tests. The first redirect is hosting-level; this local change does not alter it.
- Static requests carry cookies. Session cookie behavior was preserved. A
  separate cookieless asset host requires DNS/hosting configuration and was not
  invented or configured in application code.

## Change made

The homepage's nine runtime source files (including the mobile-scroll helper)
are combined without minification into js/homepage-runtime.bundle.js in their
required dependency order. It occupies the same JS directory, preserving relative
URLs derived from document.currentScript. Early navigation/loader scripts remain
separate. Footer controllers are not also loaded individually on bundled visits.
The standalone header/back-to-top loaders also avoid duplicate mobile-script
insertion while it is pending.

php/homepage_assets.php validates every normalized source hash and the generated
bundle hash against js/homepage-runtime.manifest.json. If anything is absent or
stale, both footer and homepage use their original individual script tags.
Line-ending normalization allows Windows-built artifacts to validate on Linux.
Existing component source files remain editable and runnable independently.

Rebuild after editing any runtime source:

    C:\xampp\php\php.exe scripts/build_homepage_runtime.php
    node --check js/homepage-runtime.bundle.js

Deploy all changed source files, php/homepage_assets.php, the bundle and its
manifest together. A missing build artifact safely retains the old request count.

## Verification and limits

Local HTTP checks verified bundle selection and the full original-script fallback
after a deliberately stale manifest, restored immediately afterwards. Syntax,
media rendering/playback, lazy-login retry/resume, guest selection, calendar and
hotel filtering checks passed. Existing page/data regression checks passed.
No deployment or new Pingdom score is claimed. Browser visual QA remains needed.

The largest captured photos include newer package_image_6ab87... uploads that do
not exist locally. Their original photos were not overwritten or replaced. On the
host containing those files, build fresh public image copies using the existing
scripts/build_homepage_images.php workflow and deploy its outputs. This needs a
rendered homepage snapshot that references those new upload paths.

Compare the same canonical URL, test region, and cache conditions after deploying.
A lower request count/load time need not produce an identical change in Pingdom's
grade, especially while it flags br-compressed responses under its gzip rule.

## Follow-up after Pingdom 82 / 59 requests

- Homepage style links drop from eight to three in server-rendered markup,
  including the unchanged Google Fonts link. The dynamically loaded mobile-scroll
  stylesheet remains independent. Four head styles are concatenated in exactly
  their original order, and three footer styles in their original order. Groups
  stay at their original document positions; source rules are not minified,
  pruned or recolored. Each group has its own source/output hash validation and
  independent original-file fallback.
- Six allowlisted tiny service-icon images can be embedded as data URLs. The
  rendered local page contains 16 such icon elements; the encoded bytes were
  compared with the previously selected files and match exactly. Photos, large
  logos, dynamic external images and oversized icons retain URL loading.
- Static-file Expires rules apply only when mod_expires is available. HTML/PHP
  and session/cache policy remain unchanged; local HTML is still no-store.
  The existing Brotli negotiation and login cookies were not altered.
- PHP/Apache configuration, rendered pages, static dimensions, stale-group
  fallback, icon payload equality, media, lazy auth, guest counts, calendars,
  availability/filter behavior and boat-image checks passed locally. A new
  production score and full visual browser QA have not been performed.

Rebuild after editing any bundled stylesheet:

    C:\xampp\php\php.exe scripts/build_homepage_styles.php

Deploy the edited files plus scripts/build_homepage_styles.php,
styles/homepage-head.bundle.css, styles/homepage-footer.bundle.css and
styles/homepage-styles.manifest.json. Keep the original stylesheets: they are
required by the integrity fallback and other pages. Test the canonical HTTPS URL
from the same region after deploying and clearing any hosting cache.

The cookie-free-domain and hosting-level redirect recommendations still require
hosting/DNS configuration. The gzip grade must be interpreted alongside the HAR's
actual br response headers; a higher Pingdom grade is not guaranteed by these
additional reductions.

## Final pass after the reported 89 / 794 ms / 44 requests

- The homepage loader has no minimum display delay: once the document is usable,
  it removes the overlay immediately instead of holding it for 480 ms plus a
  fade. Other pages retain their existing loader timing.
- An independent 1.8-second inline fail-safe unlocks the homepage and removes its
  loader if the external loader script fails. This addresses a possible covered
  screen failure mode; it does not prove the cause of the tester's blank preview.
- Versioned static files with a nine-or-more-digit v parameter now receive a
  one-year Expires interval when mod_expires is enabled, matching their existing
  immutable Cache-Control policy. Other static files retain one-day expiry;
  PHP/HTML/account responses are excluded.
- The complete Apache configuration plus project .htaccess was syntax-checked
  in a temporary validation config with expires_module explicitly loaded. The
  running Apache configuration was not changed. Loader tests, original loader
  behavior on other pages, bundle integrity, login, playback, search guests,
  calendars and filters passed locally. The temporary config was removed.

Deploy .htaccess, public/homepage.php and js/page-loader.js; the current runtime
and style bundles remain valid and do not need regeneration for this pass.
The new regression test is scripts/page_loader_reveal_test.cjs.

Retest https://itourmercedes.com/ directly, with the same region and cache
conditions. The earlier HAR recorded two redirects from the HTTP www address.
No deployed score of 90 or higher is promised: hosting compression negotiation,
asset-host cookies, the test URL and the tester's grading remain relevant.
