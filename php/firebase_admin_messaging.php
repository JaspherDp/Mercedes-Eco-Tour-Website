<?php
declare(strict_types=1);

require_once __DIR__ . '/../payments/PaymentHelper.php';
require_once __DIR__ . '/secure_dns_resolver.php';

function firebaseBase64UrlEncode(string $value): string
{
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
}

function firebaseRuntimeCachePath(string $type, string $key): string
{
    return rtrim(sys_get_temp_dir(), '\\/') . DIRECTORY_SEPARATOR
        . 'itour_firebase_' . preg_replace('/[^a-z0-9_-]/i', '', $type)
        . '_' . hash('sha256', $key) . '.json';
}

/** @return array<string, mixed>|null */
function firebaseReadRuntimeCache(string $path): ?array
{
    if (!is_file($path) || !is_readable($path)) return null;
    $cached = json_decode((string)@file_get_contents($path), true);
    return is_array($cached) ? $cached : null;
}

/** @param array<string, mixed> $value */
function firebaseWriteRuntimeCache(string $path, array $value): void
{
    $json = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    if (!is_string($json)) return;
    if (@file_put_contents($path, $json, LOCK_EX) !== false) {
        @chmod($path, 0600);
    }
}

/** Apply all cURL settings even when this Windows build rejects custom DNS. */
function firebaseApplyCurlOptions($curl, array $options, string $context): void
{
    foreach ($options as $option => $value) {
        if (curl_setopt($curl, $option, $value)) {
            continue;
        }
        if (defined('CURLOPT_DNS_SERVERS') && $option === CURLOPT_DNS_SERVERS) {
            continue;
        }
        throw new RuntimeException($context . ' networking could not be configured.');
    }
}

/**
 * Resolve Google API hosts through authenticated DNS-over-HTTPS. Some local
 * XAMPP installations cannot reach their router DNS consistently even though
 * HTTPS itself is available. The fixed Google DNS IP is used only to bootstrap
 * the HTTPS lookup; the dns.google certificate is still verified.
 *
 * @return list<string>
 */
function firebaseDnsOverHttpsResolve(string $host, string $caBundlePath): array
{
    $sharedAddresses = itourSecureDnsResolve($host, $caBundlePath);
    if ($sharedAddresses !== []) return $sharedAddresses;

    static $requestCache = [];
    if (isset($requestCache[$host])) return $requestCache[$host];
    $cachePath = firebaseRuntimeCachePath('dns', $host);
    $cached = firebaseReadRuntimeCache($cachePath);
    if (is_array($cached) && (int)($cached['expires_at'] ?? 0) > time()) {
        $addresses = array_values(array_filter(
            is_array($cached['addresses'] ?? null) ? $cached['addresses'] : [],
            static fn($address): bool => is_string($address)
                && filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false
        ));
        if ($addresses !== []) return $requestCache[$host] = $addresses;
    }

    foreach (['8.8.8.8', '8.8.4.4'] as $googleDnsIp) {
        $curl = curl_init('https://dns.google/resolve?name=' . rawurlencode($host) . '&type=A');
        if ($curl === false) {
            continue;
        }
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_RESOLVE => ['dns.google:443:' . $googleDnsIp],
            CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => ['Accept: application/dns-json'],
        ];
        if ($caBundlePath !== '') {
            $options[CURLOPT_CAINFO] = $caBundlePath;
        }
        curl_setopt_array($curl, $options);
        $body = curl_exec($curl);
        $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $error = curl_errno($curl);
        curl_close($curl);
        if ($body === false || $error !== CURLE_OK || $status !== 200) {
            continue;
        }

        $response = json_decode((string)$body, true);
        if (!is_array($response) || (int)($response['Status'] ?? -1) !== 0) {
            continue;
        }
        $resolvedAddresses = [];
        foreach (($response['Answer'] ?? []) as $answer) {
            $address = is_array($answer) ? trim((string)($answer['data'] ?? '')) : '';
            if ((int)($answer['type'] ?? 0) === 1
                && filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                $resolvedAddresses[] = $address;
            }
        }
        if ($resolvedAddresses !== []) {
            $resolvedAddresses = array_values(array_unique($resolvedAddresses));
            firebaseWriteRuntimeCache($cachePath, [
                'addresses' => $resolvedAddresses,
                'expires_at' => time() + 300,
            ]);
            return $requestCache[$host] = $resolvedAddresses;
        }
    }
    return $requestCache[$host] = [];
}

function firebaseServiceAccountAccessToken(
    string $serviceAccountPath,
    string $dnsServers,
    string $caBundlePath,
    string $caDirectoryPath
): string {
    $serviceAccount = json_decode((string)file_get_contents($serviceAccountPath), true);
    if (!is_array($serviceAccount)
        || !filter_var((string)($serviceAccount['client_email'] ?? ''), FILTER_VALIDATE_EMAIL)
        || !str_contains((string)($serviceAccount['private_key'] ?? ''), 'BEGIN PRIVATE KEY')) {
        throw new RuntimeException('Firebase service account credentials are invalid.');
    }
    $tokenCachePath = firebaseRuntimeCachePath(
        'oauth',
        (string)$serviceAccount['client_email'] . '|' . $serviceAccountPath . '|' . (string)@filemtime($serviceAccountPath)
    );
    $cachedToken = firebaseReadRuntimeCache($tokenCachePath);
    $cachedAccessToken = is_array($cachedToken) ? trim((string)($cachedToken['access_token'] ?? '')) : '';
    if ((int)($cachedToken['expires_at'] ?? 0) > time() + 120
        && preg_match('/^[A-Za-z0-9._~-]{20,4096}$/', $cachedAccessToken)) {
        return $cachedAccessToken;
    }
    $tokenUrl = trim((string)($serviceAccount['token_uri'] ?? 'https://oauth2.googleapis.com/token'));
    if ($tokenUrl !== 'https://oauth2.googleapis.com/token') {
        throw new RuntimeException('Firebase service account token endpoint is invalid.');
    }
    $issuedAt = time();
    $header = firebaseBase64UrlEncode(json_encode(['alg' => 'RS256', 'typ' => 'JWT'], JSON_THROW_ON_ERROR));
    $claims = firebaseBase64UrlEncode(json_encode([
        'iss' => (string)$serviceAccount['client_email'],
        'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
        'aud' => $tokenUrl,
        'iat' => $issuedAt,
        'exp' => $issuedAt + 3600,
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    $signature = '';
    if (!openssl_sign($header . '.' . $claims, $signature, (string)$serviceAccount['private_key'], OPENSSL_ALGO_SHA256)) {
        throw new RuntimeException('Firebase authentication assertion could not be signed.');
    }
    $assertion = $header . '.' . $claims . '.' . firebaseBase64UrlEncode($signature);
    $postFields = http_build_query([
        'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
        'assertion' => $assertion,
    ], '', '&', PHP_QUERY_RFC3986);
    $oauthAddresses = firebaseDnsOverHttpsResolve('oauth2.googleapis.com', $caBundlePath);

    $body = false;
    $error = '';
    $status = 0;
    $lastCertificateIssuer = '';
    for ($attempt = 1; $attempt <= 3; $attempt++) {
        $curl = curl_init('https://oauth2.googleapis.com/token');
        if ($curl === false) throw new RuntimeException('Firebase authentication could not be initialized.');
        $attemptOptions = [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $postFields,
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded', 'Accept: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_CERTINFO => true,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_TIMEOUT => 15,
        ];
        if ($dnsServers !== '' && defined('CURLOPT_DNS_SERVERS')) {
            $attemptOptions[CURLOPT_DNS_SERVERS] = $dnsServers;
        }
        if ($caBundlePath !== '') {
            $attemptOptions[CURLOPT_CAINFO] = $caBundlePath;
        } elseif ($caDirectoryPath !== '') {
            $attemptOptions[CURLOPT_CAPATH] = $caDirectoryPath;
        }
        if ($oauthAddresses !== []) {
            $address = $oauthAddresses[($attempt - 1) % count($oauthAddresses)];
            $attemptOptions[CURLOPT_RESOLVE] = ['oauth2.googleapis.com:443:' . $address];
        }
        firebaseApplyCurlOptions($curl, $attemptOptions, 'Firebase authentication');
        $body = curl_exec($curl);
        $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $errorNumber = curl_errno($curl);
        $error = curl_error($curl);
        $certificateInfo = curl_getinfo($curl, CURLINFO_CERTINFO);
        $lastCertificateIssuer = is_array($certificateInfo) && isset($certificateInfo[0]['Issuer'])
            ? (string)$certificateInfo[0]['Issuer']
            : '';
        curl_close($curl);
        if ($body !== false && $error === '') break;
        if (!in_array($errorNumber, [CURLE_COULDNT_RESOLVE_HOST, CURLE_COULDNT_CONNECT, CURLE_OPERATION_TIMEDOUT, 60], true)) break;
        usleep(250000 * $attempt);
    }
    if ($body === false || $error !== '') {
        throw new RuntimeException(
            'Firebase authentication could not be reached' . ($error !== '' ? ': ' . $error : '.')
            . ($caBundlePath !== '' ? ' CA bundle: ' . basename($caBundlePath) . '.' : '')
            . ($oauthAddresses !== [] ? ' Resolved IP: ' . $oauthAddresses[0] . '.' : '')
            . ($lastCertificateIssuer !== '' ? ' Certificate issuer: ' . $lastCertificateIssuer : '')
        );
    }
    $response = json_decode((string)$body, true);
    $accessToken = is_array($response) ? trim((string)($response['access_token'] ?? '')) : '';
    if ($status < 200 || $status >= 300 || $accessToken === '') {
        throw new RuntimeException('Firebase authentication was rejected.');
    }
    $expiresIn = max(300, min(3600, (int)($response['expires_in'] ?? 3600)));
    firebaseWriteRuntimeCache($tokenCachePath, [
        'access_token' => $accessToken,
        'expires_at' => time() + $expiresIn,
    ]);
    return $accessToken;
}

/**
 * Sends a data-only Firebase message to the active phone registered by the
 * main Administrator. No Firebase credentials or device tokens are returned
 * to the browser.
 *
 * @return array{sent: bool, registered: bool, message: string}
 */
function sendAdminPaymentQrNotification(
    PDO $pdo,
    int $adminId,
    int $transactionId,
    string $returnToken,
    string $bookingReference,
    int $amountMinor
): array {
    return sendPaymentQrNotificationForDeviceTable(
        $pdo, 'admin_push_devices', 'admin_id', $adminId, $transactionId,
        $returnToken, $bookingReference, $amountMinor, 'Administrator'
    );
}

/** @return array{sent: bool, registered: bool, message: string} */
function sendHotelAdminPaymentQrNotification(
    PDO $pdo,
    int $hotelAdminId,
    int $transactionId,
    string $returnToken,
    string $bookingReference,
    int $amountMinor
): array {
    return sendPaymentQrNotificationForDeviceTable(
        $pdo, 'hotel_admin_push_devices', 'hotel_admin_id', $hotelAdminId, $transactionId,
        $returnToken, $bookingReference, $amountMinor, 'Hotel Administrator'
    );
}

/** @return array{sent: bool, registered: bool, message: string} */
function sendOperatorPaymentQrNotification(
    PDO $pdo,
    int $operatorId,
    int $transactionId,
    string $returnToken,
    string $bookingReference,
    int $amountMinor
): array {
    return sendPaymentQrNotificationForDeviceTable(
        $pdo, 'operator_push_devices', 'operator_id', $operatorId, $transactionId,
        $returnToken, $bookingReference, $amountMinor, 'Tour Operator'
    );
}

/** @return array{sent: bool, registered: bool, message: string} */
function sendPaymentQrNotificationForDeviceTable(
    PDO $pdo,
    string $deviceTable,
    string $ownerColumn,
    int $ownerId,
    int $transactionId,
    string $returnToken,
    string $bookingReference,
    int $amountMinor,
    string $ownerLabel
): array {
    $allowedTables = [
        'admin_push_devices' => 'admin_id',
        'hotel_admin_push_devices' => 'hotel_admin_id',
        'operator_push_devices' => 'operator_id',
    ];
    if (($allowedTables[$deviceTable] ?? '') !== $ownerColumn
        || $ownerId < 1
        || !preg_match('/^[a-f0-9]{64}$/', $returnToken)) {
        return ['sent' => false, 'registered' => false, 'message' => 'The phone notification request was invalid.'];
    }

    $deviceQuery = $pdo->prepare(
        "SELECT device_id, fcm_token
         FROM {$deviceTable}
         WHERE {$ownerColumn} = ? AND revoked_at IS NULL
         ORDER BY COALESCE(last_used_at, created_at) DESC, device_id DESC
         LIMIT 1"
    );
    $deviceQuery->execute([$ownerId]);
    $device = $deviceQuery->fetch(PDO::FETCH_ASSOC);
    if (!$device) {
        return ['sent' => false, 'registered' => false, 'message' => 'No active ' . $ownerLabel . ' phone is registered.'];
    }

    try {
        $projectId = PaymentHelper::env('FIREBASE_PROJECT_ID');
        $serviceAccountPath = PaymentHelper::env('FIREBASE_SERVICE_ACCOUNT_PATH');
        $appUrl = PaymentHelper::env('APP_URL');
        if ($appUrl === '') {
            $appUrl = PaymentHelper::env('PUBLIC_APP_URL');
        }
        if (!preg_match('/^[a-z0-9-]{4,100}$/i', $projectId)
            || $serviceAccountPath === ''
            || !is_file($serviceAccountPath)
            || !is_readable($serviceAccountPath)) {
            throw new RuntimeException('Firebase Administrator messaging is not fully configured.');
        }

        $handoffUrl = PaymentHelper::publicHttpsUrl(
            $appUrl,
            'admin-payment-handoff.php?token=' . rawurlencode($returnToken) . '&sent=' . time()
        );

        // Local XAMPP installations commonly inherit an unreliable router DNS
        // server. Reuse the explicitly configured PayMongo DNS fallback for
        // both Google OAuth and FCM unless Firebase has its own override.
        $dnsServers = trim(PaymentHelper::env('FIREBASE_DNS_SERVERS'));
        if ($dnsServers === '') {
            $dnsServers = trim(PaymentHelper::env('PAYMONGO_DNS_SERVERS'));
        }
        if ($dnsServers !== '') {
            $servers = array_values(array_filter(array_map('trim', explode(',', $dnsServers))));
            if ($servers === [] || count($servers) > 4
                || array_filter($servers, static fn(string $server): bool => filter_var($server, FILTER_VALIDATE_IP) === false)) {
                throw new RuntimeException('Firebase DNS servers are invalid.');
            }
            $dnsServers = implode(',', $servers);
        }
        $caBundlePath = trim(PaymentHelper::env('FIREBASE_CA_BUNDLE'));
        if ($caBundlePath === '') {
            $localFirebaseCa = __DIR__ . '/../certs/firebase-ca-bundle.pem';
            $caBundlePath = is_file($localFirebaseCa)
                ? $localFirebaseCa
                : trim(PaymentHelper::env('PAYMONGO_CA_BUNDLE'));
        }
        $caDirectoryPath = trim(PaymentHelper::env('FIREBASE_CA_PATH'));
        if ($caDirectoryPath === '') {
            $caDirectoryPath = trim(PaymentHelper::env('PAYMONGO_CA_PATH'));
        }
        if ($caBundlePath !== '' && (!is_file($caBundlePath) || !is_readable($caBundlePath))) {
            throw new RuntimeException('Firebase CA bundle is unavailable.');
        }
        if ($caDirectoryPath !== '' && (!is_dir($caDirectoryPath) || !is_readable($caDirectoryPath))) {
            throw new RuntimeException('Firebase CA directory is unavailable.');
        }

        $accessToken = firebaseServiceAccountAccessToken(
            $serviceAccountPath,
            $dnsServers,
            $caBundlePath,
            $caDirectoryPath
        );

        $reference = trim($bookingReference) !== '' ? trim($bookingReference) : 'the booking';
        $notificationTitle = 'Payment QR Ready';
        $notificationBody = 'Tap to show the PayMongo QR for Booking ' . $reference . ' (PHP ' . number_format($amountMinor / 100, 2) . ').';
        $notificationTag = 'itour-admin-payment-' . $transactionId;
        $notificationIcon = PaymentHelper::publicHttpsUrl($appUrl, 'img/newlogo.png');
        $payload = [
            'message' => [
                'token' => (string)$device['fcm_token'],
                // A native notification makes fcm_options.link clickable even
                // when a phone still has an older service worker installed.
                'notification' => [
                    'title' => $notificationTitle,
                    'body' => $notificationBody,
                ],
                'data' => [
                    'title' => $notificationTitle,
                    'body' => $notificationBody,
                    'url' => $handoffUrl,
                    'link' => $handoffUrl,
                    'tag' => $notificationTag,
                ],
                'webpush' => [
                    'headers' => [
                        'TTL' => '300',
                        'Urgency' => 'high',
                    ],
                    'notification' => [
                        'title' => $notificationTitle,
                        'body' => $notificationBody,
                        'icon' => $notificationIcon,
                        'badge' => $notificationIcon,
                        'tag' => $notificationTag,
                        'data' => ['url' => $handoffUrl, 'link' => $handoffUrl],
                    ],
                    'fcm_options' => ['link' => $handoffUrl],
                ],
            ],
        ];

        $fcmUrl = 'https://fcm.googleapis.com/v1/projects/' . rawurlencode($projectId) . '/messages:send';
        $curlOptions = [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $accessToken,
                'Content-Type: application/json; charset=utf-8',
                'Accept: application/json',
            ],
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
        ];
        if ($dnsServers !== '' && defined('CURLOPT_DNS_SERVERS')) {
            $curlOptions[CURLOPT_DNS_SERVERS] = $dnsServers;
        }
        if ($caBundlePath !== '') {
            $curlOptions[CURLOPT_CAINFO] = $caBundlePath;
        }
        if ($caBundlePath === '' && $caDirectoryPath !== '') {
            $curlOptions[CURLOPT_CAPATH] = $caDirectoryPath;
        }
        $fcmAddresses = firebaseDnsOverHttpsResolve('fcm.googleapis.com', $caBundlePath);

        $responseBody = false;
        $httpStatus = 0;
        $curlError = '';
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $curl = curl_init($fcmUrl);
            if ($curl === false) {
                throw new RuntimeException('Firebase messaging could not be initialized.');
            }
            $attemptOptions = $curlOptions;
            if ($fcmAddresses !== []) {
                $address = $fcmAddresses[($attempt - 1) % count($fcmAddresses)];
                $attemptOptions[CURLOPT_RESOLVE] = ['fcm.googleapis.com:443:' . $address];
            }
            firebaseApplyCurlOptions($curl, $attemptOptions, 'Firebase messaging');
            $responseBody = curl_exec($curl);
            $httpStatus = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            $curlErrorNumber = curl_errno($curl);
            $curlError = curl_error($curl);
            curl_close($curl);
            if ($responseBody !== false && $curlError === '') {
                break;
            }
            if (!in_array($curlErrorNumber, [CURLE_COULDNT_RESOLVE_HOST, CURLE_COULDNT_CONNECT, CURLE_OPERATION_TIMEDOUT, 60], true)) {
                break;
            }
            usleep(250000 * $attempt);
        }

        if ($responseBody === false || $curlError !== '') {
            throw new RuntimeException('Firebase could not be reached' . ($curlError !== '' ? ': ' . $curlError : '.'));
        }

        $response = json_decode((string)$responseBody, true);
        if ($httpStatus < 200 || $httpStatus >= 300 || !is_array($response) || empty($response['name'])) {
            $responseText = strtoupper((string)$responseBody);
            if (str_contains($responseText, 'UNREGISTERED')) {
                // Keep the user's selected phone visible until they explicitly
                // replace or re-register it. A transient/stale Firebase token
                // is a delivery failure, not consent to remove the device.
                return ['sent' => false, 'registered' => true, 'message' => 'The registered phone could not receive this notification. Re-register that phone to refresh its notification token.'];
            }
            throw new RuntimeException('Firebase rejected the phone notification.');
        }

        $touch = $pdo->prepare("UPDATE {$deviceTable} SET last_used_at = NOW() WHERE device_id = ? AND revoked_at IS NULL");
        $touch->execute([(int)$device['device_id']]);
        return ['sent' => true, 'registered' => true, 'message' => 'The payment QR notification was sent to the registered phone.'];
    } catch (Throwable $exception) {
        error_log($ownerLabel . ' payment QR notification failed: ' . $exception->getMessage());
        return ['sent' => false, 'registered' => true, 'message' => 'The QR was created, but the phone notification could not be delivered.'];
    }
}
