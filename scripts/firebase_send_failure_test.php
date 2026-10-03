<?php
declare(strict_types=1);

require_once __DIR__ . '/../php/firebase_admin_messaging.php';

$cases = [
    [404, ['status' => 'NOT_FOUND', 'details' => [['errorCode' => 'UNREGISTERED']]], 'stale_token'],
    [403, ['status' => 'PERMISSION_DENIED'], 'credentials'],
    [503, ['status' => 'UNAVAILABLE'], 'retryable'],
    [400, ['status' => 'INVALID_ARGUMENT', 'details' => 'unexpected'], 'rejected'],
];

foreach ($cases as [$httpStatus, $error, $expected]) {
    $result = firebaseClassifySendFailure($httpStatus, json_encode(['error' => $error], JSON_THROW_ON_ERROR));
    $valid = match ($expected) {
        'stale_token' => $result['stale_token'] && !$result['retryable'],
        'credentials' => str_contains($result['message'], 'credentials') && !$result['retryable'],
        'retryable' => $result['retryable'],
        'rejected' => !$result['retryable'] && !$result['stale_token'],
    };
    if (!$valid) {
        fwrite(STDERR, "Firebase failure classification failed for HTTP {$httpStatus}.\n");
        exit(1);
    }
}

echo "Firebase failure classifications passed.\n";
