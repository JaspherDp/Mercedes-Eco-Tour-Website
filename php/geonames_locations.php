<?php
    require_once __DIR__ . '/session_security.php';
    AppSessionStart();
require_once __DIR__ . '/secure_dns_resolver.php';
require_once __DIR__ . '/psa_locations.php';
require_once __DIR__ . '/../payments/PaymentHelper.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, max-age=3600');

$signupScope = ($_GET['scope'] ?? '') === 'signup';
if (empty($_SESSION['tourist_id']) && !$signupScope) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Please sign in again.']);
    exit;
}

function geonamesUsername(): string
{
    $username = PaymentHelper::env('GEONAMES_USERNAME');
    if ($username === '') {
        throw new RuntimeException('GeoNames is not configured in the private server environment.');
    }
    return $username;
}

function geonamesRequest(string $service, array $parameters): array
{
    $parameters['username'] = geonamesUsername();
    $parameters['lang'] = 'en';
    $cacheKeyParameters = $parameters;
    unset($cacheKeyParameters['username']);
    $cachePath = rtrim(sys_get_temp_dir(), '\\/') . DIRECTORY_SEPARATOR
        . 'itour_geonames_' . hash('sha256', $service . '|' . json_encode($cacheKeyParameters)) . '.json';
    $staleData = null;
    if (is_file($cachePath) && is_readable($cachePath)) {
        $cached = json_decode((string)@file_get_contents($cachePath), true);
        if (is_array($cached) && is_array($cached['data'] ?? null)) {
            $staleData = $cached['data'];
            if ((int)($cached['expires_at'] ?? 0) > time()) return $staleData;
        }
    }
    $host = 'secure.geonames.org';
    $url = 'https://' . $host . '/' . $service . '?' . http_build_query($parameters, '', '&', PHP_QUERY_RFC3986);
    $caBundle = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'certs' . DIRECTORY_SEPARATOR . 'firebase-ca-bundle.pem';
    $resolvedAddresses = itourSecureDnsResolve($host, is_file($caBundle) ? $caBundle : '');
    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 6,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
        CURLOPT_USERAGENT => 'iTour-Mercedes/1.0',
    ];
    if (is_file($caBundle)) $options[CURLOPT_CAINFO] = $caBundle;

    $attemptAddresses = $resolvedAddresses !== []
        ? array_merge($resolvedAddresses, $resolvedAddresses, $resolvedAddresses)
        : [null, null, null];
    $body = false;
    $status = 0;
    $error = '';
    foreach ($attemptAddresses as $address) {
        $curl = curl_init($url);
        $attemptOptions = $options;
        if (is_string($address) && $address !== '') {
            $attemptOptions[CURLOPT_RESOLVE] = [$host . ':443:' . $address];
        }
        curl_setopt_array($curl, $attemptOptions);
        $body = curl_exec($curl);
        $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $error = curl_error($curl);
        $curlError = curl_errno($curl);
        curl_close($curl);
        if (is_string($body) && $curlError === CURLE_OK && $status >= 200 && $status < 300) break;
    }

    if (!is_string($body) || $status < 200 || $status >= 300) {
        if (is_array($staleData)) return $staleData;
        throw new RuntimeException($error !== '' ? $error : 'GeoNames is temporarily unavailable.');
    }
    $data = json_decode($body, true);
    if (!is_array($data)) throw new RuntimeException('GeoNames returned an invalid response.');
    if (isset($data['status']['message'])) throw new RuntimeException((string)$data['status']['message']);
    $cache = json_encode(['expires_at' => time() + 604800, 'data' => $data], JSON_INVALID_UTF8_SUBSTITUTE);
    if (is_string($cache) && @file_put_contents($cachePath, $cache, LOCK_EX) !== false) @chmod($cachePath, 0600);
    return $data;
}

try {
    $action = strtolower(trim((string)($_GET['action'] ?? 'countries')));
    if ($action === 'countries') {
        $data = geonamesRequest('countryInfoJSON', []);
        $locations = array_map(static fn(array $country): array => [
            'id' => ($country['countryCode'] ?? '') === 'PH' ? 'psa:country' : (int)($country['geonameId'] ?? 0),
            'name' => (string)($country['countryName'] ?? ''),
            'country_code' => (string)($country['countryCode'] ?? ''),
        ], $data['geonames'] ?? []);
    } elseif ($action === 'children') {
        if (str_starts_with((string)($_GET['parent_id'] ?? ''), 'psa:')) {
            $locations = psaLocationChildren((string)$_GET['parent_id']);
        } else {
        $parentId = (int)($_GET['parent_id'] ?? 0);
        if ($parentId <= 0) throw new InvalidArgumentException('Invalid parent location.');
        $data = geonamesRequest('childrenJSON', ['geonameId' => $parentId, 'maxRows' => 1000]);
        $locations = array_map(static fn(array $place): array => [
            'id' => (int)($place['geonameId'] ?? 0),
            'name' => (string)($place['name'] ?? ''),
            'country_code' => (string)($place['countryCode'] ?? ''),
            'feature_code' => (string)($place['fcode'] ?? $place['featureCode'] ?? ''),
            'admin_code_1' => (string)($place['adminCode1'] ?? ''),
            'admin_code_2' => (string)($place['adminCode2'] ?? ''),
            'admin_code_3' => (string)($place['adminCode3'] ?? ''),
            'admin_code_4' => (string)($place['adminCode4'] ?? ''),
        ], $data['geonames'] ?? []);
        }
    } elseif ($action === 'postal_code') {
        $countryCode = strtoupper(trim((string)($_GET['country_code'] ?? '')));
        $placeName = trim((string)($_GET['place_name'] ?? ''));
        if (!preg_match('/^[A-Z]{2}$/', $countryCode) || $placeName === '') {
            throw new InvalidArgumentException('Invalid postal-code location.');
        }
        $data = geonamesRequest('postalCodeSearchJSON', [
            'country' => $countryCode,
            'placename' => $placeName,
            'maxRows' => 25,
        ]);
        $matches = is_array($data['postalCodes'] ?? null) ? $data['postalCodes'] : [];
        $postalCode = '';
        foreach ($matches as $match) {
            $candidate = trim((string)($match['postalCode'] ?? ''));
            if ($candidate !== '') {
                $postalCode = $candidate;
                break;
            }
        }
        echo json_encode(['success' => true, 'postal_code' => $postalCode], JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    } else {
        throw new InvalidArgumentException('Invalid GeoNames action.');
    }

    $locations = array_values(array_filter($locations, static fn(array $location): bool => (string)$location['id'] !== '' && $location['name'] !== ''));
    $uniqueLocations = [];
    foreach ($locations as $location) {
        $normalizedName = mb_strtolower(trim($location['name']), 'UTF-8');
        if ($normalizedName !== '' && !isset($uniqueLocations[$normalizedName])) {
            $uniqueLocations[$normalizedName] = $location;
        }
    }
    $locations = array_values($uniqueLocations);
    usort($locations, static fn(array $a, array $b): int => strcasecmp($a['name'], $b['name']));
    echo json_encode(['success' => true, 'locations' => $locations], JSON_INVALID_UTF8_SUBSTITUTE);
} catch (Throwable $error) {
    header('Cache-Control: no-store');
    http_response_code($error instanceof InvalidArgumentException ? 400 : 502);
    echo json_encode(['success' => false, 'message' => $error->getMessage()], JSON_INVALID_UTF8_SUBSTITUTE);
}
