<?php
require_once __DIR__ . '/secure_dns_resolver.php';
require_once __DIR__ . '/../payments/PaymentHelper.php';
// PSA credentials and upstream URLs never leave the server.
function psaLocationRows(string $level, array $filters = []): array
{
    $token = PaymentHelper::env('PSA_PSGC_TOKEN');
    $version = PaymentHelper::env('PSA_PSGC_VERSION', 'Q2_2024');
    if (!$token || !preg_match('/^[A-Za-z0-9_]+$/', $version)) throw new RuntimeException('Philippine addresses are not configured.');
    $cache = sys_get_temp_dir() . '/itour_psa_' . hash('sha256', $version . $level . json_encode($filters)) . '.json';
    if (is_file($cache) && filemtime($cache) > time() - 86400) {
        $rows = json_decode((string)file_get_contents($cache), true);
        if (is_array($rows)) return $rows;
    }
    $rows = [];
    for ($page = 1; $page <= 100; $page++) {
        $url = 'https://classification.psa.gov.ph/psgc/' . $version . '/' . $level . '?' . http_build_query($filters + ['token'=>$token, 'page_size'=>1000, 'page'=>$page]);
        $curl = curl_init($url);
        $options = [CURLOPT_RETURNTRANSFER=>true, CURLOPT_CONNECTTIMEOUT=>8, CURLOPT_TIMEOUT=>30, CURLOPT_FOLLOWLOCATION=>false, CURLOPT_SSL_VERIFYPEER=>true, CURLOPT_SSL_VERIFYHOST=>2, CURLOPT_HTTPHEADER=>['Accept: application/json']];
        $ca = dirname(__DIR__) . '/certs/firebase-ca-bundle.pem';
        if (is_file($ca)) $options[CURLOPT_CAINFO] = $ca;
        $addresses = itourSecureDnsResolve('classification.psa.gov.ph', is_file($ca) ? $ca : '');
        if ($addresses) $options[CURLOPT_RESOLVE] = ['classification.psa.gov.ph:443:' . $addresses[0]];
        curl_setopt_array($curl, $options);
        $body = curl_exec($curl);
        $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);
        if (!is_string($body) || $status !== 200) throw new RuntimeException('Philippine addresses could not be loaded from PSA. Please try again.');
        $data = json_decode($body, true);
        $batch = $data['results']['psgc_data'] ?? $data['results'] ?? null;
        if (!is_array($batch) || ($batch && array_keys($batch) !== range(0, count($batch) - 1))) throw new RuntimeException('PSA returned an unexpected address response.');
        foreach ($batch as $row) {
            if (!is_array($row)) continue;
            // Enforce parent filters even if an upstream version ignores them.
            foreach ($filters as $key=>$value) if ((int)($row[$key] ?? -1) !== (int)$value) continue 2;
            unset($row['populations'], $row['population_data']);
            $rows[] = $row;
        }
        if (empty($data['next'])) {
            if (file_put_contents($cache, json_encode($rows), LOCK_EX) !== false) @chmod($cache, 0600);
            return $rows;
        }
    }
    throw new RuntimeException('PSA returned too many address pages.');
}

function psaLocationOption(array $row, string $level): array
{
    $reg = (int)$row['reg']; $prv = (int)$row['prv']; $mun = (int)$row['mun'];
    return ['id'=>"psa:$level:$reg:$prv:$mun:" . (int)($row['bgy'] ?? 0), 'name'=>(string)$row['area_name'], 'country_code'=>'PH', 'feature_code'=>['region'=>'ADM1','province'=>'ADM2','city'=>'ADM3','barangay'=>'ADM4'][$level], 'psgc_code'=>(string)($row['code'] ?? $row['psgc_code'] ?? '')];
}

function psaLocationChildren(string $parent): array
{
    if ($parent === 'psa:country') return array_map(static fn($r)=>psaLocationOption($r, 'region'), psaLocationRows('regions'));
    if (!preg_match('/^psa:(region|province|city):([0-9]+):([0-9]+):([0-9]+):0$/', $parent, $m)) throw new InvalidArgumentException('Invalid Philippine parent location.');
    $filters = ['reg'=>(int)$m[2]];
    if ($m[1] === 'region') {
        $provinces = psaLocationRows('provinces', $filters);
        $options = array_map(static fn($r)=>psaLocationOption($r, 'province'), $provinces);
        $known = array_column($provinces, 'prv');
        // NCR districts and independent cities do not always have a province record.
        foreach (psaLocationRows('municipalities', $filters) as $city) {
            if (in_array($city['prv'], $known)) continue;
            $known[] = $city['prv'];
            $city['area_name'] = (int)$m[2] === 13 ? 'Metro Manila — District ' . $city['prv'] : 'Independent cities — ' . $city['area_name'];
            $city['mun'] = 0;
            $options[] = psaLocationOption($city, 'province');
        }
        return $options;
    }
    $filters['prv'] = (int)$m[3];
    if ($m[1] === 'province') {
        $cities = psaLocationRows('municipalities', $filters);
        if (!$cities) {
            // PSA places highly urbanized cities at the province level (mun=0).
            $cities = array_values(array_filter(psaLocationRows('provinces', ['reg'=>(int)$m[2]]), static fn($r)=>(int)$r['prv'] === (int)$m[3] && strtolower((string)$r['geographic_level']) === 'city'));
        }
        return array_map(static fn($r)=>psaLocationOption($r, 'city'), $cities);
    }
    $filters['mun'] = (int)$m[4];
    return array_map(static fn($r)=>psaLocationOption($r, 'barangay'), psaLocationRows('barangays', $filters));
}
