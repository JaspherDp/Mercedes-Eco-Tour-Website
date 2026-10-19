const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const { execFileSync } = require('node:child_process');
const root = path.join(__dirname, '..');
const read = file => fs.readFileSync(path.join(root, file), 'utf8');
let checks = 0;
const service = read('js/service_details.js');
const imageStart = service.indexOf('  function imagePath(raw)');
const imageEnd = service.indexOf('  function formatPrice', imageStart);
const context = vm.createContext({ config: { fallback: 'img/boathome.png' }, isBoat: true });
vm.runInContext(service.slice(imageStart, imageEnd), context);
for (const [input, expected] of [
  ['uploads/boats/original.jpg', 'uploads/boats/original.jpg'],
  ['uploads/boats/original.png', 'uploads/boats/original.png'],
  ['uploads/boats/existing.optimized.webp', 'uploads/boats/existing.optimized.webp'],
  ['upload/original.jpg', 'php/upload/original.jpg'],
  ['boat.jpg', 'uploads/boat.jpg'],
  ['https://example.test/boat.jpg', 'https://example.test/boat.jpg'],
  ['', 'img/boathome.png']
]) {
  context.input = input;
  assert.equal(vm.runInContext('imagePath(input)', context), expected);
  checks++;
}

// The gallery must assign the original URL and install a working error handler.
const mainImage = {};
const nodes = {
  serviceMainImage: mainImage,
  serviceProfileGallery: { classList: { add() {}, remove() {} } },
  serviceImageCount: {}, serviceImageDots: { querySelectorAll: () => [] }
};
Object.assign(context, { byId: id => nodes[id], window: { setTimeout: callback => callback() },
  images: ['uploads/boats/original.jpg'], imageIndex: 0, activeItem: { name: 'Fixture boat' } });
vm.runInContext(service.slice(service.indexOf('  function showImage('), service.indexOf('  function render(')), context);
vm.runInContext('showImage(0)', context);
assert.equal(mainImage.src, 'uploads/boats/original.jpg');
mainImage.onerror();
assert.equal(mainImage.src, 'img/boathome.png');
checks += 2;

// Existing browser history must prefer the current catalog over guessed WebP.
const landing = read('public/hotel_resorts.php');
const track = { innerHTML: '', querySelectorAll: () => [] };
const recentStart = landing.indexOf('  function displayRecentlyViewed()');
const recentEnd = landing.indexOf('\n  function ', recentStart + 10);
const recent = vm.createContext({
  document: { getElementById: id => id === 'recentlyViewedTrack' ? track : {} },
  window: { RecentlyViewed: { getAll: () => [{ type: 'boat', id: 1, name: 'Fixture', image: 'uploads/missing.optimized.webp' }] } },
  tourBoats: [{ id: 1, images: ['uploads/boats/original.jpg'] }], hotels: [],
  normalizeImagePath: value => value, escapeHtml: String, renderCardIcon: () => '',
  recentlyViewedTypeLabel: () => 'Tour Boat', renderStars: () => '', getFallbackImage: () => 'img/boathome.png'
});
vm.runInContext(landing.slice(recentStart, recentEnd), recent);
vm.runInContext('displayRecentlyViewed()', recent);
assert.ok(track.innerHTML.includes('src="uploads/boats/original.jpg"'));
assert.ok(!track.innerHTML.includes('missing.optimized.webp'));
checks += 2;

// Exercise the real PHP resolver against a tracked original-only image.
const result = JSON.parse(execFileSync('php', ['-r',
  'require $argv[1]; echo json_encode([serviceCatalogImage("img/boat1_img2.png", "boat"), serviceCatalogImage("", "boat"), serviceCatalogImage("", "guide")]);',
  path.join(root, 'php/service_catalog_helper.php')], { encoding: 'utf8' }));
assert.equal(result[0], 'img/boat1_img2.png');
for (const resolved of result) assert.ok(fs.existsSync(path.join(root, resolved)), resolved);
checks += 4;
console.log(`PASS: ${checks} boat image checks; no database writes or network requests.`);
