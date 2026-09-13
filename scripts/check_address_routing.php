<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$_GET = ['action'=>'countries', 'scope'=>'signup'];
ob_start();
require __DIR__ . '/../php/geonames_locations.php';
$result = json_decode(ob_get_clean(), true);
if (empty($result['success'])) { fwrite(STDERR, "Country lookup failed.\n"); exit(1); }
$countries = $result['locations'];
$ph = array_values(array_filter($countries, static fn($r)=>$r['country_code'] === 'PH'))[0] ?? [];
$us = array_values(array_filter($countries, static fn($r)=>$r['country_code'] === 'US'))[0] ?? [];
if (($ph['id'] ?? '') !== 'psa:country' || !is_int($us['id'] ?? null)) { fwrite(STDERR, "Country routing failed.\n"); exit(1); }
$states = geonamesRequest('childrenJSON', ['geonameId'=>$us['id'], 'maxRows'=>1000]);
if (empty($states['geonames'])) { fwrite(STDERR, "International lookup failed.\n"); exit(1); }
echo "Philippines routes to PSA; US subdivisions load from GeoNames.\n";
