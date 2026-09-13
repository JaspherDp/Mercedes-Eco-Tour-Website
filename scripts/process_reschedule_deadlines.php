<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../php/db_connection.php';
require_once __DIR__ . '/../php/booking_cancellations_helper.php';
require_once __DIR__ . '/../php/activity_logger.php';
require_once __DIR__ . '/../php/provider_cancellation_email.php';

ensureBookingCancellationRequestsTable($pdo);
$due = $pdo->query("SELECT cancellation_request_id FROM booking_cancellation_requests
    WHERE request_status='decision_required' AND reschedule_offered=1 AND tourist_decision IS NULL
      AND decision_deadline IS NOT NULL AND decision_deadline<=NOW()
    ORDER BY decision_deadline ASC LIMIT 100")->fetchAll(PDO::FETCH_COLUMN);

$processed = 0;
foreach ($due as $requestId) {
    try {
        $pdo->beginTransaction();
        $request = bookingCancellationFinalizeProviderRefund($pdo, (int)$requestId, 'expired_no_response', true);
        $pdo->commit();
        $touristStatement = $pdo->prepare('SELECT full_name,email FROM tourist WHERE tourist_id=?');
        $touristStatement->execute([(int)$request['tourist_id']]);
        $tourist = $touristStatement->fetch(PDO::FETCH_ASSOC) ?: [];
        $fresh = $pdo->prepare('SELECT * FROM booking_cancellation_requests WHERE cancellation_request_id=?');
        $fresh->execute([(int)$requestId]);
        $record = $fresh->fetch(PDO::FETCH_ASSOC) ?: $request;
        sendProviderCancellationEmail($record, $tourist);
        logActivity($pdo, 'Admin', 0, 'System Scheduler', 'Reschedule Decision Expired', 'Automatically cancelled ' . ($record['booking_reference'] ?: ('booking #' . $record['booking_id'])) . ' and submitted the amount paid for full-refund processing.', 'Bookings', (int)$record['booking_id']);
        $processed++;
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('Reschedule deadline #' . (int)$requestId . ' failed: ' . $error->getMessage());
    }
}

echo 'Processed ' . $processed . ' expired reschedule decision(s).' . PHP_EOL;
