import fs from 'node:fs';
import assert from 'node:assert/strict';

// Generate the synthetic fixture with passenger_manifest_pdf_test.php first.
const pdfjs = await import('../js/vendor/pdfjs/pdf.min.mjs');
pdfjs.GlobalWorkerOptions.workerSrc = new URL('../js/vendor/pdfjs/pdf.worker.min.mjs', import.meta.url).href;
const path = new URL('../tmp/pdfs/passenger-manifest-fixture.pdf', import.meta.url);
const pdf = await pdfjs.getDocument({data: new Uint8Array(fs.readFileSync(path)), useSystemFonts: true}).promise;
assert.equal(pdf.numPages, 3);
const vessels = ['Primary Vessel', 'Second Vessel', 'Third Vessel'];
let seen = 0;
for (let pageNumber = 1; pageNumber <= pdf.numPages; pageNumber++) {
  const page = await pdf.getPage(pageNumber);
  const {items} = await page.getTextContent();
  const texts = items.map(item => item.str.trim()).filter(Boolean);
  assert.ok(!texts.some(text => /^Page \d+ of \d+$/.test(text)), 'Printed page counters must be omitted.');
  assert.ok(texts.includes(vessels[pageNumber - 1]));
  for (const vessel of vessels.filter(name => name !== vessels[pageNumber - 1])) {
    assert.ok(!texts.includes(vessel), 'Another vessel name leaked onto this manifest.');
  }
  assert.ok(!texts.some(text => /Tour Boat|Additional Boat/.test(text)));
  let rowNumber = 0;
  for (let index = 0; index < texts.length; index++) {
    if (!texts[index].startsWith('Fixture Guest ')) continue;
    assert.equal(texts[index - 1], String(++rowNumber), 'Passenger numbering must restart at 1.');
    assert.equal(texts[index], `Fixture Guest ${++seen}`, 'A passenger was lost, reordered or duplicated.');
  }
  assert.equal(rowNumber, pageNumber === 3 ? 1 : 8);
  assert.ok(texts.includes('MASTER / SIGNATURE'));
}
assert.equal(seen, 17);
console.log('PASS: Actual PDF text has distinct boat names, 8/8/1 passengers, numbering restarted at 1, no added boat text, and complete footers.');
