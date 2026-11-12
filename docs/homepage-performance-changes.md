# Homepage performance changes

The local homepage now uses prebuilt WebP images for public photos and logos,
defers nonessential JavaScript and styles, and loads the featured video only
when its player enters view. Carousel buttons use `aria-pressed` with group
semantics; the booking icon has an empty alt attribute because its link has text.
Original uploads remain intact. Resizing never runs during a visitor request.

## Local verification

- 44 generated image variants: 133,050,715 original bytes to 3,047,124 selected
  bytes, preserving aspect ratios. This is the sampled asset inventory, not
  initial page weight: many images are lazy-loaded or in closed menus.
- The rendered homepage has three blocking stylesheets, all local. Google Fonts,
  Font Awesome and closed-dialog styles load without blocking rendering.
- Homepage external script tags use defer/async. Destination images are no longer
  all preloaded at startup. The main hero image has high fetch priority.
- The video has no downloadable `src` until the viewport controller starts it.
- PHP/JavaScript syntax, Apache configuration, image dimensions, rendered asset
  paths and alt attributes, booking-image regressions and viewport playback tests
  are checked locally.

## Deployment

Deploy the edited PHP, HTML, JS, CSS and `.htaccess`, the new
`php/public_image_helper.php`, generated `img/*.home.webp`,
`imagess/*.home.webp`, and all of `img/homepage-cache/`. The cache folder contains
only images already exposed by the public homepage. The helper falls back to
original images when a copy is missing or older than the source.

To generate variants after replacing images, save the rendered public homepage
HTML to a local file and run:

```powershell
C:\xampp\php\php.exe scripts/build_homepage_images.php path/to/homepage.html
C:\xampp\php\php.exe scripts/homepage_delivery_test.php
```

The build needs PHP GD with WebP support. New copies must be uploaded with the
code. Text compression is configured conditionally in `.htaccess`; local XAMPP
does not load `mod_deflate`, so compression could not be verified locally. Check
the production response's `Content-Encoding` after deployment.

Rerun Catchpoint/WebPageTest and Yellow Lab against production using the same
device, location and network settings as the baseline. No new production
score or full automated accessibility audit has been claimed. Browser automation
was unavailable during this session; desktop/mobile visual and interaction QA
remains necessary before deployment.

## Second optimization pass

- Homepage login markup, authentication JavaScript, SweetAlert and login-related
  dependencies load on the first login/booking action, or for an explicit
  open_login resume link. Ordinary homepage visits do not load them.
- Footer icons use small inline SVGs. The homepage no longer downloads Font
  Awesome CSS or its icon fonts. Other pages keep their existing icon-font
  availability for compatibility. Deploy php/footer_icons.php with footer.php.
- Popular-navigation and development-team image URLs stay deferred until their
  menus/dialog open. The gallery loads its selected background near the viewport
  and pauses its timer offscreen. A noscript fallback preserves the first photo.
- The hero visual uses native lazy loading for its image and does not schedule
  automatic photo changes when hidden by the mobile layout. Mobile docking does
  not bind an unused desktop scroll handler.
- Static images and scripts/styles receive one-day caching. Assets with a
  nine-or-more-digit file-mtime v parameter receive one-year immutable caching.
  Local HEAD requests verified those headers; homepage HTML remains no-store.
- Run node scripts/homepage_lazy_resources_test.cjs for loader concurrency,
  retry, resume/return-link, and offscreen-gallery regression coverage.

This pass does not claim a new tester score or resolve the unidentified serious
accessibility warning in the screenshots. A CDN requires hosting configuration
and was not enabled. Browser automation remained unavailable for visual QA.
