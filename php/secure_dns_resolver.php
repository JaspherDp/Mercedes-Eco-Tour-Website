<?php
declare(strict_types=1);

/** @return list<string> */
function itourSecureDnsResolve(string $host, string $caBundlePath = ''): array
{
    if (!preg_match('/^[a-z0-9.-]+$/i', $host)) return [];
    static $memory = [];
    if (isset($memory[$host])) return $memory[$host];

    $cachePath = rtrim(sys_get_temp_dir(), '\\/') . DIRECTORY_SEPARATOR
        . 'itour_secure_dns_' . hash('sha256', $host) . '.json';
    if (is_file($cachePath) && is_readable($cachePath)) {
        $cached = json_decode((string)@file_get_contents($cachePath), true);
        if (is_array($cached) && (int)($cached['expires_at'] ?? 0) > time()) {
            $addresses = array_values(array_filter(
                is_array($cached['addresses'] ?? null) ? $cached['addresses'] : [],
                static fn($address): bool => is_string($address)
                    && filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false
            ));
            if ($addresses !== []) return $memory[$host] = $addresses;
        }
    }

    foreach (['8.8.8.8', '8.8.4.4'] as $bootstrapIp) {
        $curl = curl_init('https://dns.google/resolve?name=' . rawurlencode($host) . '&type=A');
        if ($curl === false) continue;
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_RESOLVE => ['dns.google:443:' . $bootstrapIp],
            CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => ['Accept: application/dns-json'],
        ];
        if ($caBundlePath !== '') $options[CURLOPT_CAINFO] = $caBundlePath;
        curl_setopt_array($curl, $options);
        $body = curl_exec($curl);
        $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $error = curl_errno($curl);
        curl_close($curl);
        if (!is_string($body) || $error !== CURLE_OK || $status !== 200) continue;
        $response = json_decode($body, true);
        if (!is_array($response) || (int)($response['Status'] ?? -1) !== 0) continue;

        $addresses = [];
        foreach (($response['Answer'] ?? []) as $answer) {
            $address = is_array($answer) ? trim((string)($answer['data'] ?? '')) : '';
            if ((int)($answer['type'] ?? 0) === 1
                && filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                $addresses[] = $address;
            }
        }
        $addresses = array_values(array_unique($addresses));
        if ($addresses === []) continue;
        $cache = json_encode(['addresses' => $addresses, 'expires_at' => time() + 300]);
        if (is_string($cache) && @file_put_contents($cachePath, $cache, LOCK_EX) !== false) {
            @chmod($cachePath, 0600);
        }
        return $memory[$host] = $addresses;
    }
    return $memory[$host] = [];
}
