<?php
// CLI-only integration check. Never prints credentials or upstream URLs.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../php/psa_locations.php';
try {
    $regions = psaLocationChildren('psa:country');
    echo 'Regions: ' . count($regions) . PHP_EOL;
    $provinces = psaLocationChildren('psa:region:5:0:0:0');
    $province = array_values(array_filter($provinces, static fn($r)=>$r['name'] === 'Camarines Norte'))[0] ?? null;
    if (!$province) throw new RuntimeException('Camarines Norte missing.');
    $cities = psaLocationChildren($province['id']);
    $city = array_values(array_filter($cities, static fn($r)=>$r['name'] === 'Mercedes'))[0] ?? null;
    if (!$city) throw new RuntimeException('Mercedes missing.');
    $barangays = psaLocationChildren($city['id']);
    if (!$barangays) throw new RuntimeException('Mercedes barangays missing.');
    echo 'Bicol > Camarines Norte > Mercedes: ' . count($barangays) . ' barangays' . PHP_EOL;
    $districts = psaLocationChildren('psa:region:13:0:0:0');
    if (!$districts) throw new RuntimeException('NCR subdivisions missing.');
    foreach ($districts as $district) {
        $cities = psaLocationChildren($district['id']);
        if (!$cities) throw new RuntimeException('NCR cities missing for ' . $district['id'] . ' ' . $district['name']);
    }
    echo 'NCR: ' . count($districts) . ' province/district groups with cities' . PHP_EOL;
    $caloocan = psaLocationChildren('psa:city:13:801:0:0');
    if (!$caloocan) throw new RuntimeException('Independent-city barangays missing.');
    echo 'Caloocan: ' . count($caloocan) . ' barangays' . PHP_EOL;
} catch (Throwable $e) { fwrite(STDERR, $e->getMessage() . PHP_EOL); exit(1); }
