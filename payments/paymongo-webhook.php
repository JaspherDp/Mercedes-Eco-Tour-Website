<?php
declare(strict_types=1);

require_once __DIR__ . '/paymongo-config.php';
require_once __DIR__ . '/PayMongoService.php';
require_once __DIR__ . '/PaymentReconciler.php';
require_once __DIR__ . '/RefundWebhookReconciler.php';
require_once __DIR__ . '/../php/db_connection.php';
require_once __DIR__ . '/../php/booking_refunds_helper.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    paymongo_json_response(405, ['received' => false, 'error' => 'method_not_allowed']);
}

try {
    $payMongo = PayMongoService::fromEnvironment(paymongo_env_path());
} catch (Throwable $exception) {
    error_log('PayMongo webhook configuration error: ' . $exception->getMessage());
    paymongo_json_response(503, ['received' => false, 'error' => 'webhook_not_configured']);
}

if (paymongo_env('PAYMONGO_WEBHOOK_SECRET') === '') {
    paymongo_json_response(503, ['received' => false, 'error' => 'webhook_not_configured']);
}

$rawBody = file_get_contents('php://input');
$rawBody = is_string($rawBody) ? $rawBody : '';
$signature = paymongo_request_header('Paymongo-Signature');

if (!$payMongo->verifyTestWebhook($rawBody, $signature)) {
    paymongo_json_response(401, ['received' => false, 'error' => 'invalid_signature']);
}

try {
    $payload = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
} catch (JsonException) {
    paymongo_json_response(400, ['received' => false, 'error' => 'invalid_json']);
}

$event = is_array($payload['data'] ?? null) ? $payload['data'] : [];
$attributes = is_array($event['attributes'] ?? null) ? $event['attributes'] : [];

// PayMongo's event envelope has historically placed the event name and
// resource in attributes.type/attributes.data. Accept the current direct
// type/data form as well so the handler remains compatible with V2 Checkout.
$eventType = trim((string)($attributes['type'] ?? $event['type'] ?? ''));
$allowedEvents = ['checkout_session.payment.paid', 'payment.failed', 'payment.refund.updated', 'payment.refunded'];

if (!in_array($eventType, $allowedEvents, true)) {
    paymongo_json_response(200, ['received' => true, 'ignored' => true]);
}
$resource = is_array($attributes['data'] ?? null)
    ? $attributes['data']
    : (is_array($event['data'] ?? null) ? $event['data'] : []);
$resourceAttributes = is_array($resource['attributes'] ?? null) ? $resource['attributes'] : [];
$livemode = $attributes['livemode'] ?? $event['livemode'] ?? $resourceAttributes['livemode'] ?? false;
if ($livemode === true) {
    paymongo_json_response(400, ['received' => false, 'error' => 'live_event_rejected']);
}

if ($eventType === 'checkout_session.payment.paid') {
    try {
        $result = PaymentReconciler::reconcilePaidCheckout(
            $pdo,
            $resource,
            trim((string)($event['id'] ?? ''))
        );
    } catch (UnexpectedValueException $exception) {
        error_log('PayMongo webhook rejected: ' . $exception->getMessage());
        paymongo_json_response(400, ['received' => false, 'error' => 'event_validation_failed']);
    } catch (Throwable $exception) {
        // A 5xx response allows PayMongo to retry transient database failures.
        error_log('PayMongo webhook reconciliation failed: ' . $exception->getMessage());
        paymongo_json_response(500, ['received' => false, 'error' => 'reconciliation_failed']);
    }

    paymongo_json_response(200, [
        'received' => true,
        'event_id' => (string)($event['id'] ?? ''),
        'event_type' => $eventType,
        'status' => $result['status'],
        'idempotent' => $result['idempotent'],
    ]);
}

if (in_array($eventType, ['payment.refund.updated', 'payment.refunded'], true)) {
    try {
        ensureBookingRefundsTable($pdo);
        $resourceId = trim((string)($resource['id'] ?? ''));
        if (str_starts_with($resourceId, 'pay_')
            && RefundWebhookReconciler::refundResources($resource) === []) {
            $remotePayment = $payMongo->retrievePayment($resourceId);
            $retrievedResource = is_array($remotePayment['data'] ?? null) ? $remotePayment['data'] : [];
            if (!hash_equals($resourceId, trim((string)($retrievedResource['id'] ?? '')))) {
                throw new UnexpectedValueException('PayMongo returned a different Payment resource for the refund event.');
            }
            $resource = $retrievedResource;
        }

        $refunds = RefundWebhookReconciler::refundResources($resource);
        $matched = false;
        $updated = false;
        foreach ($refunds as $providerRefund) {
            $result = RefundWebhookReconciler::reconcile($pdo, $providerRefund);
            $matched = $matched || $result['matched'];
            $updated = $updated || $result['updated'];
        }
        paymongo_json_response(200, [
            'received' => true,
            'event_type' => $eventType,
            'matched' => $matched,
            'updated' => $updated,
            'ignored' => $refunds === [],
        ]);
    } catch (UnexpectedValueException $exception) {
        error_log('PayMongo refund webhook rejected: ' . $exception->getMessage());
        paymongo_json_response(400, ['received' => false, 'error' => 'refund_validation_failed']);
    } catch (Throwable $exception) {
        error_log('PayMongo refund webhook reconciliation failed: ' . $exception->getMessage());
        paymongo_json_response(500, ['received' => false, 'error' => 'refund_reconciliation_failed']);
    }
}

// payment.failed is acknowledged without changing a booking. Checkout
// failures and cancellations remain visible in the local transaction ledger.
paymongo_json_response(200, [
    'received' => true,
    'event_id' => (string)($event['id'] ?? ''),
    'event_type' => $eventType,
]);
