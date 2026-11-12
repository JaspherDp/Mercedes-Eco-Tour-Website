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
