<?php
declare(strict_types=1);

// CLI only: never expose payment diagnostics as a public web endpoint.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$reference = trim((string)($argv[1] ?? ''));
$refresh = in_array('--refresh', array_slice($argv, 2), true);
if (!preg_match('/^[A-Za-z0-9_-]{1,100}$/', $reference)) {
    fwrite(STDERR, "Usage: php scripts/refund_diagnostics.php BOOKING_REFERENCE [--refresh]\n");
    exit(1);
}

require_once __DIR__ . '/../payments/PayMongoService.php';
require_once __DIR__ . '/../php/booking_refunds_helper.php';
require_once __DIR__ . '/../php/db_connection.php';

/** Only allowlisted fields leave this command; no claim tokens or bank details. */
function refundDiagnosticSummary(array $response): array
{
    $parsed = bookingRefundParsePayMongoResponse($response);
    $attributes = (array)($response['data']['attributes'] ?? []);
    $failure = bookingRefundFailureDetails($attributes);
    $apiError = is_array($response['errors'][0] ?? null) ? $response['errors'][0] : [];
    return [
        'refund_id' => $parsed['id'],
        'payment_id' => $parsed['payment_id'],
        'status' => $parsed['status'],
        'amount_centavos' => $parsed['amount_minor'],
        'amount_pesos' => number_format($parsed['amount_minor'] / 100, 2, '.', ''),
        'currency' => $parsed['currency'],
        'livemode' => $parsed['livemode'],
        'claim_domain' => $parsed['transfer_link'] !== '' ? parse_url($parsed['transfer_link'], PHP_URL_HOST) : null,
        'created_at' => is_numeric($attributes['created_at'] ?? null) ? (int)$attributes['created_at'] : null,
        'updated_at' => is_numeric($attributes['updated_at'] ?? null) ? (int)$attributes['updated_at'] : null,
        'failure_code' => $failure['code'] ?? (is_string($apiError['code'] ?? null) ? $apiError['code'] : null),
        // Messages may contain recipient information; only indicate availability.
        'failure_message_available' => $failure['message'] !== null || !empty($apiError['detail']),
    ];
}

try {
    // No schema creation, UPDATE, refund creation, or email sending in this tool.
    $statement = $pdo->prepare('SELECT br.*, pt.metadata AS source_metadata
        FROM booking_refunds br
        LEFT JOIN payment_transactions pt ON pt.payment_transaction_id=br.payment_transaction_id
        WHERE br.booking_reference=? ORDER BY br.booking_refund_id DESC LIMIT 20');
    $statement->execute([$reference]);
    $records = $statement->fetchAll(PDO::FETCH_ASSOC);
    $service = $refresh ? PayMongoService::fromEnvironment() : null;
    $report = ['booking_reference' => $reference, 'configured_mode' => PaymentHelper::payMongoMode(),
        'read_only' => true, 'provider_lookup_requested' => $refresh, 'refunds' => []];
    foreach ($records as $row) {
        $saved = json_decode((string)($row['provider_response'] ?? ''), true);
        $diagnostic = json_decode((string)($row['provider_diagnostic'] ?? ''), true);
        $initial = is_array($diagnostic['response'] ?? null) ? $diagnostic['response'] : null;
        $entry = ['local_refund_id' => (int)$row['booking_refund_id'],
            'local_status' => (string)$row['status'],
            'local_failure_code' => $row['failure_code'] ?? null,
            'provider' => (string)$row['provider'],
            'payment_method' => (string)$row['payment_method_type'],
            'last_saved_http_status' => $row['provider_http_status'] ?? null,
            'saved_response' => refundDiagnosticSummary(is_array($saved) ? $saved : []),
            'initial_creation_response' => $initial !== null ? refundDiagnosticSummary($initial) : null];
        if ($initial !== null) {
            $entry['provider_changed_from_processing_to_failed'] = in_array(
                $entry['initial_creation_response']['status'], ['pending', 'processing'], true
            ) && $entry['saved_response']['status'] === 'failed';
        }
        if ($service !== null && ($row['provider'] ?? '') === 'paymongo' && !empty($row['provider_refund_id'])) {
            try {
                PaymentHelper::assertPayMongoTransactionMode($row['source_metadata'] ?? null, $service->isLiveMode());
                $response = in_array(strtolower((string)$row['payment_method_type']), ['qrph', 'qr_code'], true)
                    ? $service->retrieveQrPhRefund((string)$row['provider_refund_id'], (string)$row['provider_payment_id'])
                    : $service->retrieveRefund((string)$row['provider_refund_id']);
                $parsed = bookingRefundParsePayMongoResponse($response);
                if ($parsed['id'] !== (string)$row['provider_refund_id']
                    || ($parsed['payment_id'] !== '' && $parsed['payment_id'] !== (string)$row['provider_payment_id'])
                    || $parsed['amount_minor'] !== (int)$row['amount_minor'] || $parsed['currency'] !== 'PHP') {
                    throw new UnexpectedValueException('Resource binding mismatch.');
                }
                $entry['current_provider_response'] = refundDiagnosticSummary($response);
            } catch (Throwable $error) {
                $entry['lookup_error_type'] = get_class($error);
                $entry['lookup_http_status'] = $error instanceof PayMongoException ? $error->getHttpStatus() : null;
                $entry['lookup_result'] = 'Unavailable or rejected; no record was changed.';
            }
        }
        $report['refunds'][] = $entry;
    }
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
} catch (Throwable $error) {
    // Avoid emitting connection details, credentials, or raw provider responses.
    fwrite(STDERR, 'Refund diagnostics could not finish (' . get_class($error) . "). Check the server configuration and schema.\n");
    exit(1);
}
