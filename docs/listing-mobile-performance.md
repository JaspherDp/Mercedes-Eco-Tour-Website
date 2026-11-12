# Listing mobile performance pass

Scope: hotel_resorts.php and its tours, hotels, boats and guides tabs.
The provided PageSpeed screenshot tests the tours listing; earlier Pingdom and
Yellow Lab screenshots mainly test the homepage. Scores across different URLs,
devices and test methods do not establish a regression. The screenshot does not
show the LCP/TBT breakdown, so no dominant Lighthouse metric is claimed.

Changes:
- Prefer existing prebuilt public-image copies while retaining original URLs
  when copies are unavailable. Keep all catalogue records and search logic.
- Replace 1,480,061-byte tours and 1,193,554-byte hotel PNG backgrounds with
  desktop WebP copies of 110,432 and 55,202 bytes, and mobile copies of 38,290
  and 23,932 bytes. Responsive preloads select the correct banner early.
- Use optimized drawer branding, omit initial src on closed image viewers,
  and reduce high-priority downloads for cards below the search form.
- Stop duplicating the footer icon-font stylesheet. Existing listing icons
  retain their own Font Awesome stylesheet.
- Load authentication on demand through the tested shared loader. Keep the
  booking-success confirmation dependency when booking_success=1 is present.
- Do not construct the hotel-results map preview before a hotel search is
  active. Keep the map button, map library and calendar dependency order.
- Defer head navigation/loader scripts and noncritical font/utility styles.

Verification:
Search guest counts, calendar policy, hotel availability/filter behavior, search
context, date restoration, image paths, lazy auth/retry/resume and preview-map
startup checks passed. Local HTTP smoke checks cover all four tabs and confirm
unchanged non-image catalogue data. Booking-success scripts remain present once.
PHP and inline/external JS syntax checks passed. A browser was unavailable for
full visual/interaction QA, and no new live PageSpeed score is claimed.

Deployment:
Deploy edited public/hotel_resorts.php, js/header.js, styles/hotel_resorts.css,
all new img/homepage-cache/*.webp files, img/tourscover.home.webp,
img/hotelcover2.home.webp, img/tourscover.mobile.webp and
img/hotelcover2.mobile.webp. Retain the prior php/public_image_helper.php.
Retest the exact same tours URL using PageSpeed Mobile, then check search, tab
switching, login, favorites, map and booking return flows in a browser.
The Pingdom compression warning still needs verification against hosting
response headers; these changes do not configure the hosting control panel.
