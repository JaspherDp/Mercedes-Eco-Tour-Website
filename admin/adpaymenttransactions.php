<?php
declare(strict_types=1);

chdir(__DIR__ . '/..');
if (session_status() === PHP_SESSION_NONE) {
require_once __DIR__ . '/../php/session_security.php';
AppSessionStart();
}

require_once __DIR__ . '/../php/db_connection.php';
require_once __DIR__ . '/../php/activity_logger.php';
require_once __DIR__ . '/../php/firebase_config.php';
require_once __DIR__ . '/../php/admin_auth_helper.php';
require_once __DIR__ . '/../php/booking_cancellations_helper.php';
require_once __DIR__ . '/../php/booking_refunds_helper.php';
require_once __DIR__ . '/../php/input_validation.php';
require_once __DIR__ . '/../payments/PayMongoService.php';
require_once __DIR__ . '/../php/app_url_helper.php';
require_once __DIR__ . '/../php/PHPMailer/src/Exception.php';
require_once __DIR__ . '/../php/PHPMailer/src/PHPMailer.php';
require_once __DIR__ . '/../php/PHPMailer/src/SMTP.php';
require_once __DIR__ . '/../php/refund_confirmation_email.php';

use PHPMailer\PHPMailer\PHPMailer;

AdminRequireLogin();
ensureBookingCancellationRequestsTable($pdo);
ensureBookingRefundsTable($pdo);

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

if (empty($_SESSION['payment_admin_csrf'])) {
    $_SESSION['payment_admin_csrf'] = bin2hex(random_bytes(32));
}
$csrfToken = (string)$_SESSION['payment_admin_csrf'];
if (empty($_SESSION['paymongo_admin_csrf'])) {
    $_SESSION['paymongo_admin_csrf'] = bin2hex(random_bytes(32));
}
$payMongoAdminCsrf = (string)$_SESSION['paymongo_admin_csrf'];
$paymentFirebaseConfiguration = firebase_public_configuration();

function paymentMoney(float $amount): string
{
    return '₱' . number_format($amount, 2);
}

function paymentLabel(string $value): string
{
    $value = trim(str_replace(['_', '-'], ' ', strtolower($value)));
    return match ($value) {
        '' => 'Not specified',
        'package' => 'Tour Package',
        'tourguide' => 'Tour Guide',
        default => ucwords($value),
    };
}

function paymentStatusClass(string $status): string
{
    return match (strtolower($status)) {
        'paid', 'succeeded', 'completed' => 'success',
        'pending', 'processing' => 'pending',
        'failed', 'cancelled', 'expired' => 'danger',
        default => 'neutral',
    };
}

function paymentCollectionSource(array $transaction): string
{
    $metadata = json_decode((string)($transaction['metadata'] ?? ''), true);
    $source = is_array($metadata) ? strtolower((string)($metadata['source'] ?? '')) : '';

    return match ($source) {
        'tourist_profile_balance' => 'Collected from tourist profile',
        'booking_checkout' => 'Collected during tourist booking',
        'admin_booking_payment' => 'Collected in admin booking panel',
        'hotel_checkin_payment' => 'Collected during hotel check-in',
        'hotel_checkout_payment' => 'Collected during hotel check-out',
        'admin_payment_page' => 'Recorded in admin payments panel',
        default => strtolower((string)($transaction['provider'] ?? '')) === 'offline'
            ? 'Recorded by administrator'
            : 'Collected through online checkout',
    };
}

function paymentDisplayReference(array $transaction): string
{
    return strtolower((string)($transaction['payment_method_type'] ?? '')) === 'cash'
        ? 'Cash'
        : (string)($transaction['merchant_reference'] ?? '');
}

function paymentDisplayChannel(array $transaction): string
{
    return strtolower((string)($transaction['payment_method_type'] ?? '')) === 'cash'
        ? 'Cash'
        : paymentLabel((string)($transaction['provider'] ?? ''));
}

/** @return array{percent:float,direction:string,tone:string,label:string} */
function paymentTrend(float $current, float $previous, bool $lowerIsBetter = false): array
{
    if (abs($previous) < 0.0001) {
        $percent = abs($current) < 0.0001 ? 0.0 : 100.0;
    } else {
        $percent = (($current - $previous) / abs($previous)) * 100;
    }
    $direction = $percent > 0.05 ? 'up' : ($percent < -0.05 ? 'down' : 'flat');
    $isGood = $direction === 'flat' || ($lowerIsBetter ? $direction === 'down' : $direction === 'up');

    return [
        'percent' => abs($percent),
        'direction' => $direction,
        'tone' => $direction === 'flat' ? 'neutral' : ($isGood ? 'positive' : 'negative'),
        'label' => $direction === 'flat' ? 'No change' : number_format(abs($percent), 1) . '%',
    ];
}

function paymentTrendVisual(array $trend): string
{
    $direction = (string)($trend['direction'] ?? 'flat');
    $tone = (string)($trend['tone'] ?? 'neutral');
    $points = match ($direction) {
        'up' => '2,23 15,18 28,20 43,10 56,13 70,3',
        'down' => '2,5 15,10 28,8 43,18 56,15 70,24',
        default => '2,14 15,12 28,15 43,13 56,14 70,13',
    };
    $tip = $direction === 'up' ? 'M64 3h6v6' : ($direction === 'down' ? 'M64 24h6v-6' : 'M65 10l5 3-5 3');
    $endpointY = $direction === 'up' ? 3 : ($direction === 'down' ? 24 : 13);
    return '<span class="stat-trend-visual '.htmlspecialchars($tone, ENT_QUOTES).'" aria-hidden="true"><svg viewBox="0 0 72 28"><path class="trend-guide" d="M2 25H70"/><polyline class="trend-line" points="'.$points.'"/><circle class="trend-point" cx="70" cy="'.$endpointY.'" r="2.5"/><path class="trend-arrow" d="'.$tip.'"/></svg></span>';
}

/** @return array{current:float,previous:float} */
function paymentComparisonBars(float $current, float $previous): array
{
    $current = max(0, $current);
    $previous = max(0, $previous);
    $maximum = max($current, $previous, 0.01);

    return [
        'current' => $current > 0 ? max(7, ($current / $maximum) * 100) : 0,
        'previous' => $previous > 0 ? max(7, ($previous / $maximum) * 100) : 0,
    ];
}

function paymentCsvSafe(string $value): string
{
    return preg_match('/^\s*[=+\-@]/u', $value) ? "'" . $value : $value;
}

function paymentRedirect(string $type, string $message): never
{
    $_SESSION['payment_page_notice'] = ['type' => $type, 'message' => $message];
    header('Location: adpaymenttransactions.php');
    exit;
}

function refundPageRedirect(string $type, string $title, string $message, int $requestId = 0): never
{
    $_SESSION['alert'] = ['type' => $type, 'title' => $title, 'message' => htmlspecialchars($message, ENT_QUOTES, 'UTF-8')];
    $query = ['view' => 'refunds'];
    if ($requestId > 0) $query['cancellation_request_id'] = $requestId;
    header('Location: adpaymenttransactions.php?' . http_build_query($query));
    exit;
}

function refundTrackingUrl(): string
{
    $baseUrl = ItourTryCanonicalAppUrl('refund tracking email link');
    return $baseUrl === '' ? '' : $baseUrl . '/php/profile.php?section=cancel-bookings';
}

function sendRefundProcessingEmail(array $refund): bool
{
    $email = trim((string)($refund['email'] ?? ''));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return false;
    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host = 'smtp.gmail.com';
        $mail->SMTPAuth = true;
        $mail->Username = 'itourmercedes@gmail.com';
        $mail->Password = PaymentHelper::env('SMTP_PASSWORD');
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = 587;
        $mail->Timeout = 45;
        $mail->Timelimit = 60;
        $mail->SMTPOptions = ['ssl' => ['verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true]];
        $mail->CharSet = 'UTF-8';
        $mail->setFrom('itourmercedes@gmail.com', 'iTour Mercedes');
        $mail->addReplyTo('itourmercedes@gmail.com', 'iTour Mercedes');
        $mail->addAddress($email, (string)($refund['full_name'] ?? 'Guest'));
        $mail->isHTML(true);
        $mail->Subject = 'iTour Mercedes | Refund Approved (' . (string)$refund['booking_reference'] . ')';
        $branding = RefundConfirmationEmailBranding($mail);
        $mail->Body = RefundConfirmationEmailTemplate(array_merge($refund, $branding, [
            'guest_name' => (string)($refund['full_name'] ?? 'Guest'),
            'tracking_url' => refundTrackingUrl(),
        ]));
        $mail->AltBody = 'Your refund of ' . BookingConfirmationEmailMoney($refund['amount'] ?? 0)
            . ' for booking ' . (string)$refund['booking_reference'] . ' has been submitted to '
            . (string)($refund['method'] ?? 'your original payment method') . '. Estimated posting time: '
            . (string)($refund['timeline'] ?? 'depends on the provider') . '. Track it at ' . refundTrackingUrl();
        return BookingConfirmationEmailSend($mail);
    } catch (Throwable $error) {
        error_log('Refund confirmation email failed: ' . $error->getMessage());
        return false;
    }
}

function refundRequestRecord(PDO $pdo, int $requestId, bool $lock = false): ?array
{
    $statement = $pdo->prepare("
        SELECT cr.*, t.full_name, t.email, t.profile_picture
        FROM booking_cancellation_requests cr
        LEFT JOIN tourist t ON t.tourist_id=cr.tourist_id
        WHERE cr.cancellation_request_id=? LIMIT 1" . ($lock ? ' FOR UPDATE' : '')
    );
    $statement->execute([$requestId]);
    return $statement->fetch(PDO::FETCH_ASSOC) ?: null;
}

function manualRefundMaskAccount(string $value): string
{
    $value = trim($value);
    if ($value === '') return '';
    $last4 = substr(preg_replace('/\s+/', '', $value) ?: $value, -4);
    return 'Ending in ' . $last4;
}

function payMongoSanitizeDiagnosticValue(mixed $value): mixed
{
    if (!is_array($value)) return $value;
    $blocked = '/(^|_)(authorization|api_?key|secret|secret_?key|client_?secret|access_?token|refresh_?token|password|signature|webhook_?secret|private_?key)($|_)/i';
    foreach ($value as $key => $item) {
        if (preg_match($blocked, (string)$key)) {
            $value[$key] = '[REDACTED]';
        } else {
            $value[$key] = payMongoSanitizeDiagnosticValue($item);
        }
    }
    return $value;
}

/** @return array<string,mixed> */
function payMongoQrRefundDiagnostic(
    PayMongoService $service,
    string $paymentId,
    int $amountMinor,
    array $response,
    ?PayMongoException $error = null
): array {
    $sanitized = payMongoSanitizeDiagnosticValue($response);
    $attributes = is_array($sanitized['data']['attributes'] ?? null) ? $sanitized['data']['attributes'] : [];
    $firstError = is_array($sanitized['errors'][0] ?? null) ? $sanitized['errors'][0] : [];
    $linkOrActionFields = [];
    $walk = static function (mixed $value, string $path = '') use (&$walk, &$linkOrActionFields): void {
        if (!is_array($value)) return;
        foreach ($value as $key => $item) {
            $itemPath = $path === '' ? (string)$key : $path . '.' . (string)$key;
            if (preg_match('/(link|url|redirect|action)/i', (string)$key) && (is_scalar($item) || $item === null)) {
                $linkOrActionFields[$itemPath] = $item;
            }
            if (is_array($item)) $walk($item, $itemPath);
        }
    };
    $walk($sanitized);
    $diagnostic = [
        'recorded_at' => date(DATE_ATOM),
        'mode' => 'test',
        'request' => ['method' => 'POST', 'path' => '/v1/refunds', 'payment_id' => $paymentId, 'amount_minor' => $amountMinor],
        'http_status' => $error ? $error->getHttpStatus() : $service->getLastHttpStatus(),
        'refund_id' => trim((string)($sanitized['data']['id'] ?? '')) ?: null,
        'refund_status' => trim((string)($attributes['status'] ?? '')) ?: null,
        'payment_id' => trim((string)($attributes['payment_id'] ?? $paymentId)),
        'link_or_action_fields' => $linkOrActionFields,
        'paymongo_error_code' => trim((string)($firstError['code'] ?? $firstError['sub_code'] ?? '')) ?: null,
        'paymongo_error_message' => trim((string)($firstError['detail'] ?? $firstError['message'] ?? '')) ?: null,
        'paymongo_error_details_returned' => $firstError !== [],
        'response' => $sanitized,
    ];
    error_log('[PayMongo QR Ph refund diagnostic] ' . json_encode($diagnostic, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
    return $diagnostic;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['manual_refund_details'])) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    try {
        if (!hash_equals($csrfToken, (string)($_GET['csrf_token'] ?? ''))) throw new RuntimeException('Your session expired. Refresh the page and try again.');
        $requestId = max(0, (int)$_GET['manual_refund_details']);
        $request = refundRequestRecord($pdo, $requestId);
        if (!$request || strtolower((string)$request['request_status']) !== 'approved' || strtolower((string)$request['refund_policy']) !== 'partial_refund') {
            throw new RuntimeException('This cancellation does not require a manual partial refund.');
        }
        if (empty($request['refund_destination_account_cipher']) || empty($request['refund_destination_verified_at'])) {
            throw new RuntimeException('The tourist must add a verified refund account before this refund can be recorded.');
        }
        $allocated = $pdo->prepare("SELECT COALESCE(SUM(amount_minor),0) FROM booking_refunds WHERE cancellation_request_id=? AND status IN ('initiating','pending','processing','succeeded')");
        $allocated->execute([$requestId]);
        $remainingMinor = max(0, (int)round((float)$request['refundable_amount'] * 100) - (int)$allocated->fetchColumn());
        if ($remainingMinor < 100) throw new RuntimeException('This refund has already been fully recorded.');
        $source = $pdo->prepare("SELECT payment_method_type,provider_payment_id,merchant_reference FROM payment_transactions WHERE tourist_id=? AND status IN ('paid','succeeded','completed') AND (booking_reference=? OR booking_id=?) ORDER BY paid_at DESC,payment_transaction_id DESC LIMIT 1");
        $source->execute([(int)$request['tourist_id'], (string)$request['booking_reference'], (int)$request['booking_id']]);
        $payment = $source->fetch(PDO::FETCH_ASSOC) ?: [];
        echo json_encode(['success' => true, 'refund' => [
            'request_id' => $requestId,
            'booking_reference' => (string)$request['booking_reference'],
            'tourist' => (string)($request['full_name'] ?? 'Guest'),
            'amount' => number_format($remainingMinor / 100, 2, '.', ''),
            'original_channel' => bookingRefundMethodLabel((string)($payment['payment_method_type'] ?? '')),
            'original_payment_reference' => (string)($payment['provider_payment_id'] ?? $payment['merchant_reference'] ?? ''),
            'destination_institution' => (string)$request['refund_destination_institution'],
            'destination_account_name' => (string)$request['refund_destination_account_name'],
            'destination_account_number' => bookingRefundDecryptAccountNumber((string)$request['refund_destination_account_cipher']),
            'destination_last4' => (string)$request['refund_destination_last4'],
        ]], JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    } catch (Throwable $error) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => $error->getMessage() ?: 'Manual refund details are unavailable.']);
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array((string)($_POST['action'] ?? ''), ['process_refund', 'process_manual_refund', 'refresh_refund'], true)) {
    $requestId = max(0, (int)($_POST['cancellation_request_id'] ?? 0));
    if (!hash_equals($csrfToken, (string)($_POST['csrf_token'] ?? ''))) {
        refundPageRedirect('error', 'Request Expired', 'Refresh the page and try again.', $requestId);
    }
    try {
        $request = refundRequestRecord($pdo, $requestId);
        if (!$request || strtolower((string)$request['request_status']) !== 'approved' || (float)$request['refundable_amount'] <= 0) {
            throw new RuntimeException('This cancellation is not ready for a refund.');
        }
        if (($_POST['action'] ?? '') === 'process_manual_refund') {
            if (strtolower((string)$request['refund_policy']) !== 'partial_refund') throw new RuntimeException('Only partial refunds use the manual refund workflow.');
            if (empty($request['refund_destination_account_cipher']) || empty($request['refund_destination_verified_at'])) throw new RuntimeException('The tourist must add a verified refund account first.');
            if ((string)($_POST['funds_sent_confirmation'] ?? '') !== 'yes') throw new RuntimeException('Confirm that the funds were sent before recording the refund.');
            $manualChannel = trim((string)($_POST['manual_refund_channel'] ?? ''));
            $senderAccountInput = trim((string)($_POST['manual_sender_account'] ?? ''));
            $providerReference = trim((string)($_POST['manual_provider_reference'] ?? ''));
            $manualNote = trim((string)($_POST['manual_refund_note'] ?? ''));
            if ($manualChannel === '' || mb_strlen($manualChannel) > 80 || $senderAccountInput === '' || mb_strlen($senderAccountInput) > 150
                || !preg_match('/^[A-Za-z0-9._\/-]{5,100}$/', $providerReference) || mb_strlen($manualNote) > 500) {
                throw new RuntimeException('Complete the sending channel, source account, and valid transfer reference.');
            }
            $pdo->beginTransaction();
            $locked = refundRequestRecord($pdo, $requestId, true);
            if (!$locked || strtolower((string)$locked['request_status']) !== 'approved') throw new RuntimeException('This cancellation is no longer ready for a refund.');
            $allocated = $pdo->prepare("SELECT COALESCE(SUM(amount_minor),0) FROM booking_refunds WHERE cancellation_request_id=? AND status IN ('initiating','pending','processing','succeeded')");
            $allocated->execute([$requestId]);
            $remainingMinor = max(0, (int)round((float)$locked['refundable_amount'] * 100) - (int)$allocated->fetchColumn());
            if ($remainingMinor < 100) throw new RuntimeException('The full eligible refund has already been recorded.');
            $duplicateReference = $pdo->prepare("SELECT 1 FROM booking_refunds WHERE provider_operation='manual_refund' AND provider_reference_number=? LIMIT 1");
            $duplicateReference->execute([$providerReference]);
            if ($duplicateReference->fetchColumn()) throw new RuntimeException('That transfer reference has already been used for another refund.');
            $senderAccount = manualRefundMaskAccount($senderAccountInput);
            $destinationLabel = trim((string)$locked['refund_destination_institution']) . ' ending in ' . (string)$locked['refund_destination_last4'];
            $audit = ['channel' => $manualChannel, 'source_account' => $senderAccount, 'recorded_by_admin_id' => (int)($_SESSION['admin_id'] ?? 0), 'recorded_at' => date(DATE_ATOM), 'note' => $manualNote];
            $idempotency = 'manual-refund-v1-cr' . $requestId . '-' . $remainingMinor;
            $insert = $pdo->prepare("INSERT INTO booking_refunds (cancellation_request_id,tourist_id,booking_domain,booking_id,booking_reference,provider,provider_operation,provider_reference_number,idempotency_key,amount_minor,currency,payment_method_type,payment_destination,destination_institution,destination_last4,manual_refund_channel,manual_sender_account,status,reason,admin_note,provider_response,initiated_by_admin_id,initiated_at,completed_at) VALUES (?,?,?,?,?,'manual','manual_refund',?,?,?,?,?,?,?,?,?,?,'succeeded','others',?,?,?,NOW(),NOW())");
            $insert->execute([$requestId, (int)$locked['tourist_id'], (string)$locked['booking_type'], (int)$locked['booking_id'], (string)$locked['booking_reference'], $providerReference, $idempotency, $remainingMinor, 'PHP', $manualChannel, $destinationLabel, (string)$locked['refund_destination_institution'], (string)$locked['refund_destination_last4'], $manualChannel, $senderAccount, $manualNote ?: null, json_encode($audit, JSON_UNESCAPED_SLASHES), (int)($_SESSION['admin_id'] ?? 0)]);
            $refundId = (int)$pdo->lastInsertId();
            bookingRefundSyncCancellationStatus($pdo, $requestId);
            $pdo->commit();
            $emailSent = sendRefundProcessingEmail(array_merge($request, [
                'amount' => $remainingMinor / 100,
                'method' => $manualChannel,
                'destination' => $destinationLabel,
                'provider_refund_id' => $providerReference,
                'timeline' => 'Sent by administrator',
                'timeline_detail' => 'The administrator recorded the transfer as sent. Posting time now depends on the receiving bank or e-wallet.',
                'manual_completed' => true,
            ]));
            $pdo->prepare("UPDATE booking_refunds SET email_status=?,email_sent_at=CASE WHEN ?='sent' THEN NOW() ELSE NULL END,email_error=? WHERE booking_refund_id=?")
                ->execute([$emailSent ? 'sent' : 'failed', $emailSent ? 'sent' : 'failed', $emailSent ? null : 'SMTP delivery failed.', $refundId]);
            logActivity($pdo, 'Admin', (int)($_SESSION['admin_id'] ?? 0), (string)($_SESSION['username'] ?? 'Administrator'), 'Manual Refund Recorded', 'Recorded ' . paymentMoney($remainingMinor / 100) . ' sent for ' . (string)$request['booking_reference'] . ' under transfer reference ' . $providerReference . '.', 'Payments & Transactions', $requestId);
            refundPageRedirect('success', 'Manual Refund Completed', paymentMoney($remainingMinor / 100) . ' was recorded as sent to ' . $destinationLabel . '. ' . ($emailSent ? 'The tourist was notified.' : 'The email could not be delivered.'), $requestId);
        }

        $service = PayMongoService::fromEnvironment();

        if (($_POST['action'] ?? '') === 'refresh_refund') {
            $rows = $pdo->prepare("SELECT * FROM booking_refunds WHERE cancellation_request_id=? AND (provider_refund_id IS NOT NULL OR provider_transfer_id IS NOT NULL) AND status IN ('pending','processing','initiating')");
            $rows->execute([$requestId]);
            $updated = 0;
            foreach ($rows->fetchAll(PDO::FETCH_ASSOC) as $refundRow) {
                $refundMethod = strtolower((string)($refundRow['payment_method_type'] ?? ''));
                $response = in_array($refundMethod, ['qrph', 'qr_code'], true)
                    ? $service->retrieveQrPhRefund((string)$refundRow['provider_refund_id'], (string)$refundRow['provider_payment_id'])
                    : $service->retrieveRefund((string)$refundRow['provider_refund_id']);
                $parsed = bookingRefundParsePayMongoResponse($response);
                if (bookingRefundProviderResponseDisposition($parsed['status']) === 'unexpected') {
                    throw new UnexpectedValueException('PayMongo returned an unknown or missing refund status while refreshing transaction #' . (int)$refundRow['payment_transaction_id'] . '.');
                }
                if (($parsed['payment_id'] !== '' && !hash_equals((string)$refundRow['provider_payment_id'], $parsed['payment_id']))
                    || ($parsed['amount_minor'] > 0 && $parsed['amount_minor'] !== (int)$refundRow['amount_minor'])
                    || ($parsed['currency'] !== '' && $parsed['currency'] !== 'PHP')
                    || $parsed['livemode']) {
                    throw new UnexpectedValueException('PayMongo returned a refund resource that does not match the local refund transaction.');
                }
                $nextStatus = bookingRefundMonotonicProviderStatus((string)$refundRow['status'], $parsed['status']);
                $existingResponse = json_decode((string)($refundRow['provider_response'] ?? ''), true);
                $existingTransferLink = (string)($existingResponse['data']['attributes']['transfer_link'] ?? '');
                if (filter_var($existingTransferLink, FILTER_VALIDATE_URL)
                    && empty($response['data']['attributes']['transfer_link'])) {
                    $response['data']['attributes']['transfer_link'] = $existingTransferLink;
                }
                $update = $pdo->prepare("UPDATE booking_refunds SET status=?, provider_response=?, failure_code=CASE WHEN ?='failed' THEN COALESCE(failure_code,'provider_refund_failed') ELSE NULL END, failure_message=CASE WHEN ?='failed' THEN COALESCE(failure_message,'PayMongo reported that the refund failed.') ELSE NULL END, completed_at=CASE WHEN ?='succeeded' THEN NOW() ELSE completed_at END WHERE booking_refund_id=?");
                $update->execute([$nextStatus, json_encode($response, JSON_UNESCAPED_SLASHES), $nextStatus, $nextStatus, $nextStatus, (int)$refundRow['booking_refund_id']]);
                $updated++;
            }
            $status = bookingRefundSyncCancellationStatus($pdo, $requestId);
            refundPageRedirect('success', 'Refund Status Updated', $updated > 0 ? 'The latest provider status is now shown. Current status: ' . bookingCancellationRefundStatusLabel($status, (float)$request['refundable_amount']) . '.' : 'There is no provider refund waiting for an update.', $requestId);
        }

        $targetMinor = (int)round((float)$request['refundable_amount'] * 100);
        $allocatedStatement = $pdo->prepare("SELECT COALESCE(SUM(amount_minor),0) FROM booking_refunds WHERE cancellation_request_id=? AND status IN ('initiating','pending','processing','succeeded')");
        $allocatedStatement->execute([$requestId]);
        $remainingMinor = max(0, $targetMinor - (int)$allocatedStatement->fetchColumn());
        if ($remainingMinor <= 0) {
            bookingRefundSyncCancellationStatus($pdo, $requestId);
            throw new RuntimeException('The full eligible amount has already been submitted. Refresh its provider status instead.');
        }

        $sourceStatement = $pdo->prepare("
            SELECT pt.*,
              COALESCE((SELECT SUM(br.amount_minor) FROM booking_refunds br WHERE br.payment_transaction_id=pt.payment_transaction_id AND br.status IN ('initiating','pending','processing','succeeded')),0) refunded_minor
            FROM payment_transactions pt
            WHERE pt.tourist_id=? AND LOWER(pt.status) IN ('paid','succeeded','completed')
              AND (pt.booking_reference=? OR (pt.booking_id=? AND LOWER(pt.booking_domain)=LOWER(?)))
            ORDER BY pt.paid_at DESC, pt.payment_transaction_id DESC
        ");
        $sourceStatement->execute([(int)$request['tourist_id'], (string)$request['booking_reference'], (int)$request['booking_id'], (string)$request['booking_type']]);
        $sources = $sourceStatement->fetchAll(PDO::FETCH_ASSOC);
        $plannedSources = [];
        $plannedRemainingMinor = $remainingMinor;
        foreach ($sources as $candidate) {
            if ($plannedRemainingMinor <= 0) break;
            $candidateAvailableMinor = max(0, (int)$candidate['amount_minor'] - (int)$candidate['refunded_minor']);
            $candidateMethod = strtolower((string)$candidate['payment_method_type']);
            if ($candidateAvailableMinor < 100
                || strtolower((string)$candidate['provider']) !== 'paymongo'
                || !preg_match('/^pay_[A-Za-z0-9]+$/', (string)$candidate['provider_payment_id'])
                || !bookingRefundSupportsAutomaticPayMongoRefund($candidateMethod)) {
                continue;
            }
            $candidate['planned_refund_minor'] = bookingRefundAutomaticAllocation($candidateMethod, $plannedRemainingMinor, $candidateAvailableMinor);
            if ((int)$candidate['planned_refund_minor'] < 100) continue;
            $candidate['planned_operation'] = 'refund';
            $plannedSources[] = $candidate;
            $plannedRemainingMinor -= (int)$candidate['planned_refund_minor'];
        }
        if ($plannedRemainingMinor > 0) {
            throw new RuntimeException('No eligible paid PayMongo Payment resource was found for the full refund amount.');
        }
        $sources = $plannedSources;
        $submitted = [];
        $failures = [];
        foreach ($sources as $source) {
            if ($remainingMinor <= 0) break;
            $availableMinor = max(0, (int)$source['amount_minor'] - (int)$source['refunded_minor']);
            if ($availableMinor <= 0) continue;
            if (strtolower((string)$source['provider']) !== 'paymongo' || !preg_match('/^pay_[A-Za-z0-9]+$/', (string)$source['provider_payment_id'])) continue;
            if (!bookingRefundSupportsAutomaticPayMongoRefund((string)$source['payment_method_type'])) continue;
            $amountMinor = (int)($source['planned_refund_minor'] ?? min($remainingMinor, $availableMinor));
            if ($amountMinor < 100) continue;

            $paymentResponse = $service->retrievePayment((string)$source['provider_payment_id']);
            $paymentResource = is_array($paymentResponse['data'] ?? null) ? $paymentResponse['data'] : [];
            $paymentAttributes = is_array($paymentResource['attributes'] ?? null) ? $paymentResource['attributes'] : [];
            $remotePaymentId = trim((string)($paymentResource['id'] ?? ''));
            $remotePaymentStatus = strtolower(trim((string)($paymentAttributes['status'] ?? '')));
            $remoteSource = is_array($paymentAttributes['source'] ?? null) ? $paymentAttributes['source'] : [];
            $remoteMethod = strtolower(trim((string)($remoteSource['type'] ?? $source['payment_method_type'])));
            if ($remotePaymentId !== (string)$source['provider_payment_id']) {
                throw new RuntimeException('PayMongo returned a different Payment ID while validating the refund source.');
            }
            if (!in_array($remotePaymentStatus, ['paid', 'succeeded'], true)) {
                throw new RuntimeException('PayMongo Payment ' . $remotePaymentId . ' is not paid or succeeded. Current status: ' . ($remotePaymentStatus ?: 'unknown') . '.');
            }
            if (($paymentAttributes['livemode'] ?? false) === true) {
                throw new RuntimeException('A live PayMongo Payment cannot be refunded with the configured test credentials.');
            }
            $remoteRefunds = bookingRefundRemoteRefundResources($paymentResource);
            if ($remoteRefunds !== []) {
                $knownRefunds = $pdo->prepare("SELECT provider_refund_id FROM booking_refunds WHERE payment_transaction_id=? AND provider_refund_id IS NOT NULL");
                $knownRefunds->execute([(int)$source['payment_transaction_id']]);
                $knownProviderRefundIds = array_fill_keys(array_filter(array_map('strval', $knownRefunds->fetchAll(PDO::FETCH_COLUMN))), true);
                foreach ($remoteRefunds as $remoteRefund) {
                    if (isset($knownProviderRefundIds[$remoteRefund['id']])) continue;
                    if (!in_array($remoteRefund['status'], ['pending', 'processing', 'succeeded'], true)) continue;
                    throw new RuntimeException('PayMongo reports an existing refund for payment transaction #' . (int)$source['payment_transaction_id'] . ' that is not yet reconciled locally. Review the PayMongo refund before submitting another request.');
                }
            }
            $refundWindowDays = bookingRefundWindowDays($remoteMethod);
            $remotePaidAt = (int)($paymentAttributes['paid_at'] ?? 0);
            if ($refundWindowDays !== null && $remotePaidAt > 0 && $remotePaidAt < time() - ($refundWindowDays * 86400)) {
                throw new RuntimeException(bookingRefundMethodLabel($remoteMethod) . ' refunds must be submitted within ' . $refundWindowDays . ' days of payment.');
            }
            // `available_at` is the settlement/payout availability timestamp, not
            // a refund eligibility gate. Let PayMongo accept, queue, or reject the
            // refund based on the paid Payment and the merchant payout balance.
            $idempotencyPrefix = in_array($remoteMethod, ['qrph', 'qr_code'], true) ? 'qrph-refund-v1-' : 'refund-v3-';
            $attemptCount = $pdo->prepare('SELECT COUNT(*) FROM booking_refunds WHERE cancellation_request_id=? AND payment_transaction_id=? AND amount_minor=?');
            $attemptCount->execute([$requestId, (int)$source['payment_transaction_id'], $amountMinor]);
            $attemptSequence = max(1, (int)$attemptCount->fetchColumn() + 1);
            $idempotency = $idempotencyPrefix . 'cr' . $requestId . '-pt' . (int)$source['payment_transaction_id'] . '-' . $amountMinor . '-a' . $attemptSequence;
            $destinationLabel = bookingRefundMethodLabel($remoteMethod);
            $insert = $pdo->prepare("INSERT INTO booking_refunds (cancellation_request_id,payment_transaction_id,tourist_id,booking_domain,booking_id,booking_reference,provider,provider_operation,provider_payment_id,idempotency_key,amount_minor,currency,payment_method_type,payment_destination,destination_institution,destination_last4,reason,status,initiated_by_admin_id,initiated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,'others','initiating',?,NOW())");
            try {
                $insert->execute([$requestId, (int)$source['payment_transaction_id'], (int)$request['tourist_id'], (string)$request['booking_type'], (int)$request['booking_id'], (string)$request['booking_reference'], 'paymongo', 'refund', (string)$source['provider_payment_id'], $idempotency, $amountMinor, (string)$source['currency'], $remoteMethod, $destinationLabel, null, null, (int)($_SESSION['admin_id'] ?? 0)]);
                $refundId = (int)$pdo->lastInsertId();
            } catch (PDOException $duplicate) {
                if ((string)$duplicate->getCode() !== '23000') throw $duplicate;
                continue;
            }
            $isQrPhRefund = in_array($remoteMethod, ['qrph', 'qr_code'], true);
            $responseBindingValidated = false;
            $response = null;
            try {
                $response = $isQrPhRefund
                    ? $service->createQrPhRefund((string)$source['provider_payment_id'], $amountMinor, 'requested_by_customer', 'Approved cancellation ' . (string)$request['booking_reference'], $idempotency)
                    : $service->createRefund((string)$source['provider_payment_id'], $amountMinor, 'others', 'Approved cancellation ' . (string)$request['booking_reference'], $idempotency);
                $response = payMongoSanitizeDiagnosticValue($response);
                $qrDiagnostic = $isQrPhRefund
                    ? payMongoQrRefundDiagnostic($service, (string)$source['provider_payment_id'], $amountMinor, $response)
                    : null;
                $parsed = bookingRefundParsePayMongoResponse($response);
                if ($parsed['id'] === '') throw new RuntimeException('PayMongo did not return a refund reference.');
                if (!hash_equals((string)$source['provider_payment_id'], $parsed['payment_id'])
                    || $parsed['amount_minor'] !== $amountMinor
                    || $parsed['currency'] !== 'PHP'
                    || $parsed['livemode']) {
                    throw new UnexpectedValueException('PayMongo returned a refund resource that does not match the requested payment, amount, currency, or test mode.');
                }
                $responseBindingValidated = true;
                $disposition = bookingRefundProviderResponseDisposition($parsed['status']);
                $update = $pdo->prepare('UPDATE booking_refunds SET provider_refund_id=?,status=?,provider_response=?,provider_http_status=?,provider_diagnostic=?,completed_at=CASE WHEN ?=\'succeeded\' THEN NOW() ELSE NULL END WHERE booking_refund_id=?');
                $update->execute([
                    $parsed['id'],
                    $parsed['status'],
                    json_encode($response, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE),
                    $isQrPhRefund ? $service->getLastHttpStatus() : null,
                    $qrDiagnostic !== null ? json_encode($qrDiagnostic, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) : null,
                    $parsed['status'],
                    $refundId,
                ]);
                if ($disposition !== 'submitted') {
                    $failureCode = $disposition === 'failed' ? 'provider_refund_failed' : 'unexpected_refund_status';
                    $failureMessage = $disposition === 'failed'
                        ? 'PayMongo returned a failed refund for payment transaction #' . (int)$source['payment_transaction_id'] . '.'
                        : 'PayMongo returned an unknown or missing refund status for payment transaction #' . (int)$source['payment_transaction_id'] . '.';
                    $pdo->prepare("UPDATE booking_refunds SET status='failed',failure_code=?,failure_message=? WHERE booking_refund_id=?")
                        ->execute([$failureCode, $failureMessage, $refundId]);
                    bookingRefundSyncCancellationStatus($pdo, $requestId);
                    $failures[] = ['payment_transaction_id' => (int)$source['payment_transaction_id'], 'code' => $failureCode, 'message' => $failureMessage];
                    break;
                }
                $submitted[] = ['id' => $refundId, 'provider_refund_id' => $parsed['id'], 'amount_minor' => $amountMinor, 'method' => $remoteMethod, 'destination' => bookingRefundMethodLabel($remoteMethod) . ' account used for payment', 'claim_url' => $parsed['transfer_link']];
                $remainingMinor -= $amountMinor;
            } catch (Throwable $providerError) {
                $failureCode = 'paymongo_error';
                $failureMessage = $providerError->getMessage();
                $providerResponse = isset($response) && is_array($response) ? $response : null;
                if ($providerError instanceof PayMongoException) {
                    $providerResponse = $providerError->getResponse();
                    $providerErrorData = is_array($providerResponse['errors'][0] ?? null) ? $providerResponse['errors'][0] : [];
                    $providerFailureAttributes = is_array($providerResponse['data']['attributes'] ?? null) ? $providerResponse['data']['attributes'] : [];
                    $failureCode = trim((string)($providerErrorData['code'] ?? $providerErrorData['sub_code'] ?? ($providerFailureAttributes['status'] ?? 'paymongo_error'))) ?: 'paymongo_error';
                    $failureMessage = trim((string)($providerErrorData['detail'] ?? $providerFailureAttributes['failure_message'] ?? $providerFailureAttributes['error_message'] ?? ''));
                    if ($failureMessage === '' && strtolower((string)($providerFailureAttributes['status'] ?? '')) === 'failed') {
                        $failureMessage = 'PayMongo returned a failed refund without an error reason. No refund was submitted. If a retry through the current Refund API also fails, review the Payment in the PayMongo Dashboard or contact PayMongo support.';
                    }
                    if ($failureMessage === '') $failureMessage = $providerError->getMessage();
                }
                $providerResponse = is_array($providerResponse) ? payMongoSanitizeDiagnosticValue($providerResponse) : null;
                if (!$responseBindingValidated && is_array($providerResponse)) {
                    $failedParsed = bookingRefundParsePayMongoResponse($providerResponse);
                    $responseBindingValidated = preg_match('/^ref_[A-Za-z0-9]+$/', $failedParsed['id']) === 1
                        && hash_equals((string)$source['provider_payment_id'], $failedParsed['payment_id'])
                        && $failedParsed['amount_minor'] === $amountMinor
                        && $failedParsed['currency'] === 'PHP'
                        && !$failedParsed['livemode'];
                }
                $qrDiagnostic = $isQrPhRefund
                    ? payMongoQrRefundDiagnostic(
                        $service,
                        (string)$source['provider_payment_id'],
                        $amountMinor,
                        $providerResponse ?? [],
                        $providerError instanceof PayMongoException ? $providerError : null
                    )
                    : null;
                $failedProviderRefundId = $responseBindingValidated ? trim((string)($providerResponse['data']['id'] ?? '')) : '';
                $update = $pdo->prepare("UPDATE booking_refunds SET provider_refund_id=COALESCE(NULLIF(?,''),provider_refund_id),status='failed',failure_code=?,failure_message=?,provider_response=?,provider_http_status=?,provider_diagnostic=? WHERE booking_refund_id=?");
                $update->execute([
                    $failedProviderRefundId,
                    mb_substr($failureCode, 0, 100),
                    mb_substr($failureMessage, 0, 500),
                    $providerResponse !== null ? json_encode($providerResponse, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) : null,
                    $isQrPhRefund ? $service->getLastHttpStatus() : null,
                    $qrDiagnostic !== null ? json_encode($qrDiagnostic, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) : null,
                    $refundId,
                ]);
                bookingRefundSyncCancellationStatus($pdo, $requestId);
                $failures[] = [
                    'payment_transaction_id' => (int)$source['payment_transaction_id'],
                    'code' => $failureCode,
                    'message' => 'PayMongo [' . $failureCode . ']: ' . $failureMessage,
                ];
                break;
            }
        }
        $outcome = bookingRefundOperationOutcome($submitted, $failures);
        if (!$submitted) {
            $failure = $failures[0] ?? null;
            throw new RuntimeException($failure ? (string)$failure['message'] : 'No eligible paid PayMongo Payment resource was found for this refund.');
        }

        $currentStatus = bookingRefundSyncCancellationStatus($pdo, $requestId);
        $emailAmount = array_sum(array_column($submitted, 'amount_minor')) / 100;
        $method = (string)$submitted[0]['method'];
        $timeline = bookingRefundTimeline($method, (int)round($emailAmount * 100));
        $emailSent = sendRefundProcessingEmail(array_merge($request, [
            'amount' => $emailAmount,
            'method' => bookingRefundMethodLabel($method),
            'destination' => (string)($submitted[0]['destination'] ?? bookingRefundMethodLabel($method) . ' account used for payment'),
            'provider_refund_id' => implode(', ', array_column($submitted, 'provider_refund_id')),
            'timeline' => $timeline['short'],
            'timeline_detail' => $timeline['detail'],
            'claim_url' => (string)($submitted[0]['claim_url'] ?? ''),
            'claim_actions' => bookingRefundClaimActions($submitted),
        ]));
        $ids = array_column($submitted, 'id');
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $emailUpdate = $pdo->prepare("UPDATE booking_refunds SET email_status=?,email_sent_at=CASE WHEN ?='sent' THEN NOW() ELSE NULL END,email_error=? WHERE booking_refund_id IN ($marks)");
        $emailUpdate->execute(array_merge([$emailSent ? 'sent' : 'failed', $emailSent ? 'sent' : 'failed', $emailSent ? null : 'SMTP delivery failed.'], $ids));
        $routeDescription = 'the original ' . bookingRefundMethodLabel($method) . ' payment';
        logActivity($pdo, 'Admin', (int)($_SESSION['admin_id'] ?? 0), (string)($_SESSION['username'] ?? 'Administrator'), 'Refund Submitted', 'Submitted ' . paymentMoney($emailAmount) . ' to ' . $routeDescription . ' for ' . (string)$request['booking_reference'] . '.', 'Payments & Transactions', $requestId);
        if ($outcome['state'] === 'partial') {
            $failedTransactions = implode(', ', array_map(static fn(array $failure): string => '#' . (int)$failure['payment_transaction_id'], $failures));
            $message = paymentMoney($emailAmount) . ' was preserved as submitted, but payment transaction ' . $failedTransactions . ' failed. The booking is partially refunded and requires attention. Successful transactions will not be resubmitted.';
            refundPageRedirect('error', 'Partially Refunded — Requires Attention', $message, $requestId);
        }
        $message = paymentMoney($emailAmount) . ' was submitted to ' . $routeDescription . '. ' . ($emailSent ? 'The tourist was emailed the provider timeline.' : 'The refund was accepted, but the confirmation email could not be delivered.');
        refundPageRedirect('success', $currentStatus === 'completed' ? 'Refund Completed' : 'Refund Processing', $message, $requestId);
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $errorMessage = $error->getMessage();
        refundPageRedirect('error', 'Refund Not Processed', $errorMessage, $requestId);
    }
}

// Record an in-person/manual collection while keeping the same immutable ledger
// used by PayMongo. Booking totals and the ledger update in one DB transaction.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'record_payment') {
    if (!hash_equals($csrfToken, (string)($_POST['csrf_token'] ?? ''))) {
        paymentRedirect('error', 'Your session token expired. Please try again.');
    }

    $domain = strtolower((string)($_POST['booking_domain'] ?? ''));
    try {
        $bookingId = ItourValidationInt($_POST['booking_id'] ?? null, 'Booking ID', 1, PHP_INT_MAX);
        $amount = ItourValidationMoney($_POST['amount'] ?? null, 'Payment amount', 10000000.00, false);
        $note = ItourValidationText($_POST['note'] ?? '', 'Payment note', 500);
    } catch (InvalidArgumentException $exception) {
        paymentRedirect('error', $exception->getMessage());
    }
    $method = strtolower((string)($_POST['payment_method'] ?? 'cash'));
    $allowedMethods = ['cash', 'gcash', 'bank_transfer', 'card_terminal', 'other'];

    if (!$bookingId || !in_array($domain, ['package', 'boat', 'tourguide'], true) || $amount <= 0) {
        paymentRedirect('error', 'Please select a valid booking and enter a payment amount.');
    }
    if (!in_array($method, $allowedMethods, true)) {
        $method = 'other';
    }

    try {
        $pdo->beginTransaction();
        $statement = $pdo->prepare(
            "SELECT booking_id, tourist_id, booking_reference, remaining_balance,
                    payment_amount AS amount_paid
             FROM bookings
             WHERE booking_id = ? AND LOWER(booking_type) = ?
             LIMIT 1 FOR UPDATE"
        );
        $statement->execute([(int)$bookingId, $domain]);
        $booking = $statement->fetch(PDO::FETCH_ASSOC);
        if (!$booking) {
            throw new RuntimeException('The selected booking could not be found.');
        }

        $cancellationStatement = $pdo->prepare(
            "SELECT request_status
             FROM booking_cancellation_requests
             WHERE booking_id = ?
               AND LOWER(booking_domain) <> 'hotel'
               AND request_status IN ('pending','approved','completed')
             ORDER BY cancellation_request_id DESC
             LIMIT 1 FOR UPDATE"
        );
        $cancellationStatement->execute([(int)$bookingId]);
        $cancellationStatus = strtolower((string)($cancellationStatement->fetchColumn() ?: ''));
        if ($cancellationStatus === 'pending') {
            throw new RuntimeException('Payment collection is paused while this cancellation request is awaiting review.');
        }
        if (in_array($cancellationStatus, ['approved', 'completed'], true)) {
            throw new RuntimeException('Payment cannot be collected because this booking cancellation has been approved.');
        }

        $balance = round(max(0, (float)$booking['remaining_balance']), 2);
        if ($balance <= 0) {
            throw new RuntimeException('This booking is already fully paid.');
        }
        if ($amount > $balance + 0.009) {
            throw new RuntimeException('The payment cannot be greater than the outstanding balance.');
        }

        $newPaid = round((float)$booking['amount_paid'] + $amount, 2);
        $newBalance = round(max(0, $balance - $amount), 2);
        $update = $pdo->prepare(
            "UPDATE bookings
             SET payment_amount = ?, remaining_balance = ?, is_paid = ?,
                 payment_method = ?, updated_at = NOW()
             WHERE booking_id = ? AND LOWER(booking_type) = ?"
        );
        $update->execute([$newPaid, $newBalance, $newBalance <= 0 ? 1 : 0, $method, (int)$bookingId, $domain]);

        $reference = trim((string)$booking['booking_reference']);
        $unique = bin2hex(random_bytes(16));
        $merchantReference = 'OFF-' . date('YmdHis') . '-' . substr($unique, 0, 8);
        $metadata = json_encode([
            'source' => 'admin_payment_page',
            'recorded_by_admin_id' => (int)($_SESSION['admin_id'] ?? 0),
            'note' => mb_substr($note, 0, 500),
        ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        $insert = $pdo->prepare(
            "INSERT INTO payment_transactions
             (tourist_id, booking_domain, booking_id, booking_reference, provider,
              merchant_reference, idempotency_key, return_token, amount_minor,
              currency, status, payment_method_type, metadata, paid_at)
             VALUES (?, ?, ?, ?, 'offline', ?, ?, ?, ?, 'PHP', 'paid', ?, ?, NOW())"
        );
        $insert->execute([
            (int)$booking['tourist_id'], $domain, (int)$bookingId, $reference,
            $merchantReference, 'offline:' . $unique, hash('sha256', $unique . random_bytes(8)),
            (int)round($amount * 100), $method, $metadata,
        ]);
        $transactionId = (int)$pdo->lastInsertId();

        logActivity(
            $pdo,
            'Admin',
            (int)($_SESSION['admin_id'] ?? 0),
            (string)($_SESSION['username'] ?? 'Administrator'),
            'Payment Recorded',
            'Recorded ' . paymentMoney($amount) . ' via ' . paymentLabel($method) . ' for ' . ($reference ?: ('booking #' . $bookingId)) . '.',
            'Payments & Transactions',
            $transactionId
        );
        $pdo->commit();
        paymentRedirect('success', 'Payment recorded. The booking balance and transaction ledger are now updated.');
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        paymentRedirect('error', $error->getMessage());
    }
}

$notice = $_SESSION['payment_page_notice'] ?? null;
unset($_SESSION['payment_page_notice']);

$search = trim((string)($_GET['q'] ?? ''));
$statusFilter = strtolower((string)($_GET['status'] ?? 'all'));
$domainFilter = strtolower((string)($_GET['domain'] ?? 'all'));
$methodFilter = strtolower((string)($_GET['method'] ?? 'all'));
$dateFrom = trim((string)($_GET['from'] ?? ''));
$dateTo = trim((string)($_GET['to'] ?? ''));
$allowedStatuses = ['all', 'paid', 'pending', 'failed', 'cancelled', 'expired'];
$allowedDomains = ['all', 'package', 'boat', 'tourguide'];
if (!in_array($statusFilter, $allowedStatuses, true)) $statusFilter = 'all';
if (!in_array($domainFilter, $allowedDomains, true)) $domainFilter = 'all';
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) $dateFrom = '';
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) $dateTo = '';

$where = ["LOWER(pt.booking_domain) IN ('package','boat','tourguide')"];
$params = [];
if ($search !== '') {
    $where[] = '(pt.booking_reference LIKE :search OR pt.merchant_reference LIKE :search OR pt.provider_payment_id LIKE :search OR t.full_name LIKE :search OR t.email LIKE :search)';
    $params['search'] = '%' . $search . '%';
}
if ($statusFilter !== 'all') {
    $where[] = 'LOWER(pt.status) = :status';
    $params['status'] = $statusFilter;
}
if ($domainFilter !== 'all') {
    $where[] = 'LOWER(pt.booking_domain) = :domain';
    $params['domain'] = $domainFilter;
}
if ($methodFilter !== 'all') {
    if ($methodFilter === 'paymongo') {
        $where[] = "LOWER(pt.provider) = 'paymongo'";
    } elseif ($methodFilter === 'offline') {
        $where[] = "LOWER(pt.provider) = 'offline'";
    } else {
        $where[] = 'LOWER(pt.payment_method_type) = :method';
        $params['method'] = $methodFilter;
    }
}
if ($dateFrom !== '') {
    $where[] = 'DATE(pt.created_at) >= :date_from';
    $params['date_from'] = $dateFrom;
}
if ($dateTo !== '') {
    $where[] = 'DATE(pt.created_at) <= :date_to';
    $params['date_to'] = $dateTo;
}
$whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

$transactionSelect = "
    SELECT pt.*, t.full_name, t.email,
           COALESCE(NULLIF(b.package_name, ''), NULLIF(b.location, ''), NULLIF(b.preferred_resource, ''), 'Tour booking payment') AS service_name,
           COALESCE(GREATEST(b.grand_total, b.payment_amount + b.remaining_balance), 0) AS booking_total,
           COALESCE(b.payment_amount, 0) AS booking_paid,
           COALESCE(b.remaining_balance, 0) AS booking_balance
    FROM payment_transactions pt
    LEFT JOIN tourist t ON t.tourist_id = pt.tourist_id
    LEFT JOIN bookings b ON b.booking_id = pt.booking_id AND LOWER(b.booking_type) = LOWER(pt.booking_domain)
";

$exportSummarySql = "
    SELECT COUNT(*) AS record_count,
           COALESCE(SUM(CASE WHEN LOWER(pt.status) = 'paid' THEN pt.amount_minor ELSE 0 END), 0) AS paid_amount_minor,
           COALESCE(SUM(pt.amount_minor), 0) AS listed_amount_minor
    FROM payment_transactions pt
    LEFT JOIN tourist t ON t.tourist_id = pt.tourist_id
";

if (($_GET['export_preview'] ?? '') === '1') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    try {
        $summaryStatement = $pdo->prepare($exportSummarySql . $whereSql);
        $summaryStatement->execute($params);
        $previewSummary = $summaryStatement->fetch(PDO::FETCH_ASSOC) ?: [];

        $previewStatement = $pdo->prepare($transactionSelect . $whereSql . ' ORDER BY pt.created_at DESC, pt.payment_transaction_id DESC LIMIT 12');
        $previewStatement->execute($params);
        $previewRows = [];
        foreach ($previewStatement->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $previewRows[] = [
                'reference' => paymentDisplayReference($row),
                'date' => date('M j, Y · g:i A', strtotime((string)$row['created_at'])),
                'customer' => (string)($row['full_name'] ?: 'Guest'),
                'booking_reference' => (string)$row['booking_reference'],
                'booking_type' => paymentLabel((string)$row['booking_domain']),
                'amount' => paymentMoney(((int)$row['amount_minor']) / 100),
                'status' => paymentLabel((string)$row['status']),
                'status_class' => paymentStatusClass((string)$row['status']),
            ];
        }

        echo json_encode([
            'success' => true,
            'summary' => [
                'record_count' => (int)($previewSummary['record_count'] ?? 0),
                'paid_amount' => paymentMoney(((int)($previewSummary['paid_amount_minor'] ?? 0)) / 100),
                'listed_amount' => paymentMoney(((int)($previewSummary['listed_amount_minor'] ?? 0)) / 100),
            ],
            'rows' => $previewRows,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    } catch (Throwable $error) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'The export preview could not be prepared.']);
    }
    exit;
}

if (($_GET['export'] ?? '') === 'csv') {
    $export = $pdo->prepare($transactionSelect . $whereSql . ' ORDER BY pt.created_at DESC, pt.payment_transaction_id DESC');
    $export->execute($params);
    $exportRows = $export->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $periodLabel = $dateFrom && $dateTo
        ? date('M j, Y', strtotime($dateFrom)) . ' to ' . date('M j, Y', strtotime($dateTo))
        : ($dateFrom ? 'From ' . date('M j, Y', strtotime($dateFrom)) : ($dateTo ? 'Through ' . date('M j, Y', strtotime($dateTo)) : 'All available dates'));
    $filterParts = [
        $statusFilter !== 'all' ? 'Status: ' . paymentLabel($statusFilter) : null,
        $domainFilter !== 'all' ? 'Booking type: ' . paymentLabel($domainFilter) : null,
        $methodFilter !== 'all' ? 'Channel: ' . paymentLabel($methodFilter) : null,
        $search !== '' ? 'Search: ' . $search : null,
    ];
    $filterLabel = implode(' | ', array_filter($filterParts)) ?: 'All statuses, booking types, and channels';
    $filenamePeriod = $dateFrom || $dateTo ? ($dateFrom ?: 'start') . '-to-' . ($dateTo ?: date('Y-m-d')) : 'all-dates';

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="itour-tour-payment-report-' . $filenamePeriod . '.csv"');
    echo "\xEF\xBB\xBF";
    $output = fopen('php://output', 'wb');
    fputcsv($output, ['iTOUR MERCEDES — ADMINISTRATION']);
    fputcsv($output, ['TOUR PAYMENT & TRANSACTION REPORT']);
    fputcsv($output, ['Report period', $periodLabel]);
    fputcsv($output, ['Generated', date('F j, Y · g:i A')]);
    fputcsv($output, ['Applied filters', paymentCsvSafe($filterLabel)]);
    fputcsv($output, ['Records exported', count($exportRows)]);
    fputcsv($output, []);
    fputcsv($output, [
        'Transaction Reference', 'Payment Date', 'Booking Reference', 'Customer Name', 'Customer Email',
        'Service', 'Booking Type', 'Payment Channel', 'Payment Method', 'Collected Through',
        'Amount (PHP)', 'Status', 'Provider Payment ID', 'Payment Note',
    ]);
    foreach ($exportRows as $row) {
        fputcsv($output, [
            paymentCsvSafe(paymentDisplayReference($row)),
            date('Y-m-d H:i:s', strtotime((string)($row['paid_at'] ?: $row['created_at']))),
            paymentCsvSafe((string)$row['booking_reference']),
            paymentCsvSafe((string)($row['full_name'] ?: 'Guest')),
            paymentCsvSafe((string)$row['email']),
            paymentCsvSafe((string)$row['service_name']),
            paymentCsvSafe(paymentLabel((string)$row['booking_domain'])),
            paymentCsvSafe(paymentDisplayChannel($row)),
            paymentCsvSafe(paymentLabel((string)$row['payment_method_type'])),
            paymentCsvSafe(paymentCollectionSource($row)),
            number_format(((int)$row['amount_minor']) / 100, 2, '.', ''),
            paymentLabel((string)$row['status']),
            paymentCsvSafe((string)$row['provider_payment_id']),
            paymentCsvSafe((string)$row['failure_message']),
        ]);
    }
    fclose($output);
    exit;
}

$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 12;
$countStatement = $pdo->prepare('SELECT COUNT(*) FROM payment_transactions pt LEFT JOIN tourist t ON t.tourist_id = pt.tourist_id' . $whereSql);
$countStatement->execute($params);
$transactionCount = (int)$countStatement->fetchColumn();
$totalPages = max(1, (int)ceil($transactionCount / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;
$transactionsStatement = $pdo->prepare($transactionSelect . $whereSql . ' ORDER BY pt.created_at DESC, pt.payment_transaction_id DESC LIMIT ' . $perPage . ' OFFSET ' . $offset);
$transactionsStatement->execute($params);
$transactions = $transactionsStatement->fetchAll(PDO::FETCH_ASSOC);

$activeLedgerView = strtolower((string)($_GET['view'] ?? 'transactions'));
if (!in_array($activeLedgerView, ['transactions', 'receivables', 'refunds'], true)) $activeLedgerView = 'transactions';
$focusedCancellationId = max(0, (int)($_GET['cancellation_request_id'] ?? 0));
$refundSearch = trim((string)($_GET['refund_q'] ?? ''));
$refundStateFilter = strtolower((string)($_GET['refund_state'] ?? 'all'));
if (!in_array($refundStateFilter, ['all', 'ready', 'processing', 'partial', 'succeeded', 'failed'], true)) $refundStateFilter = 'all';

$refundRequestSql = "
    SELECT cr.*, t.full_name, t.email, t.profile_picture,
      COALESCE(SUM(CASE WHEN br.status='succeeded' THEN br.amount_minor ELSE 0 END),0) refunded_minor,
      COALESCE(SUM(CASE WHEN br.status IN ('initiating','pending','processing') THEN br.amount_minor ELSE 0 END),0) active_refund_minor,
      COALESCE(SUM(CASE WHEN br.status='failed' THEN 1 ELSE 0 END),0) failed_refund_count,
      MAX(COALESCE(br.provider_refund_id,br.provider_reference_number)) provider_refund_id,
      MAX(br.provider_transfer_id) provider_transfer_id,
      MAX(br.manual_refund_channel) manual_refund_channel,
      MAX(br.manual_sender_account) manual_sender_account,
      MAX(br.admin_note) manual_refund_note,
      MAX(br.updated_at) refund_activity_at
    FROM booking_cancellation_requests cr
    LEFT JOIN tourist t ON t.tourist_id=cr.tourist_id
    LEFT JOIN booking_refunds br ON br.cancellation_request_id=cr.cancellation_request_id
    WHERE cr.request_status='approved' AND cr.refundable_amount > 0
    GROUP BY cr.cancellation_request_id
    ORDER BY COALESCE(cr.refund_updated_at,cr.reviewed_at,cr.requested_at) DESC, cr.cancellation_request_id DESC
";
$refundRequests = $pdo->query($refundRequestSql)->fetchAll(PDO::FETCH_ASSOC);
$refundRows = [];
$refundStats = ['all' => 0, 'ready' => 0, 'processing' => 0, 'partial' => 0, 'succeeded' => 0, 'failed' => 0, 'eligible_minor' => 0, 'refunded_minor' => 0, 'pending_minor' => 0];
$sourceLookup = $pdo->prepare("
    SELECT pt.*,
      COALESCE((SELECT SUM(br.amount_minor) FROM booking_refunds br WHERE br.payment_transaction_id=pt.payment_transaction_id AND br.status IN ('initiating','pending','processing','succeeded')),0) allocated_minor
    FROM payment_transactions pt
    WHERE pt.tourist_id=? AND LOWER(pt.status) IN ('paid','succeeded','completed')
      AND (pt.booking_reference=? OR (pt.booking_id=? AND LOWER(pt.booking_domain)=LOWER(?)))
    ORDER BY pt.paid_at DESC, pt.payment_transaction_id DESC
");
$historyLookup = $pdo->prepare('SELECT * FROM booking_refunds WHERE cancellation_request_id=? ORDER BY booking_refund_id DESC');
foreach ($refundRequests as $request) {
    $targetMinor = (int)round((float)$request['refundable_amount'] * 100);
    $refundedMinor = (int)$request['refunded_minor'];
    $activeMinor = (int)$request['active_refund_minor'];
    $state = $refundedMinor >= $targetMinor ? 'succeeded'
        : ($activeMinor > 0 ? 'processing'
            : ($refundedMinor > 0 ? 'partial' : ((int)$request['failed_refund_count'] > 0 ? 'failed' : 'ready')));
    $refundStats['all']++;
    $refundStats[$state]++;
    $refundStats['eligible_minor'] += $targetMinor;
    $refundStats['refunded_minor'] += min($targetMinor, $refundedMinor);
    $refundStats['pending_minor'] += max(0, $targetMinor - $refundedMinor);
    if ($refundStateFilter !== 'all' && $state !== $refundStateFilter) continue;
    $haystack = strtolower(implode(' ', [
        (string)$request['full_name'],
        (string)$request['email'],
        (string)$request['booking_reference'],
        (string)$request['booking_type'],
        paymentLabel((string)$request['booking_type']),
        (string)$request['service_name'],
    ]));
    if ($refundSearch !== '' && !str_contains($haystack, strtolower($refundSearch))) continue;

    $sourceLookup->execute([(int)$request['tourist_id'], (string)$request['booking_reference'], (int)$request['booking_id'], (string)$request['booking_type']]);
    $sources = $sourceLookup->fetchAll(PDO::FETCH_ASSOC);
    $historyLookup->execute([(int)$request['cancellation_request_id']]);
    $history = $historyLookup->fetchAll(PDO::FETCH_ASSOC);
    $primarySource = $sources[0] ?? [];
    $availableAutomaticMinor = 0;
    $automaticRemainingMinor = max(0, $targetMinor - $refundedMinor - $activeMinor);
    foreach ($sources as $source) {
        if (
            strtolower((string)$source['provider']) === 'paymongo'
            && preg_match('/^pay_[A-Za-z0-9]+$/', (string)$source['provider_payment_id'])
            && bookingRefundSupportsAutomaticPayMongoRefund((string)$source['payment_method_type'])
        ) {
            $sourceAvailableMinor = max(0, (int)$source['amount_minor'] - (int)$source['allocated_minor']);
            $sourceMethod = strtolower((string)$source['payment_method_type']);
            $usableMinor = bookingRefundAutomaticAllocation($sourceMethod, $automaticRemainingMinor, $sourceAvailableMinor);
            $availableAutomaticMinor += $usableMinor;
            $automaticRemainingMinor -= $usableMinor;
        }
    }
    $method = (string)($primarySource['payment_method_type'] ?? '');
    $automaticCapable = (int)max(0, $targetMinor - $refundedMinor - $activeMinor) >= 100
        && $automaticRemainingMinor === 0
        && $availableAutomaticMinor >= (int)max(0, $targetMinor - $refundedMinor - $activeMinor);
    $manualRequired = strtolower((string)$request['refund_policy']) === 'partial_refund' && !$automaticCapable;
    $timeline = $manualRequired
        ? ['short' => 'Recorded after transfer', 'detail' => 'The administrator sends the eligible partial amount and records the transfer reference before completion.']
        : bookingRefundTimeline($method, $targetMinor);
    $request['refund_state'] = $state;
    $request['refund_state_label'] = bookingRefundStatusLabel($state);
    $request['target_minor'] = $targetMinor;
    $request['remaining_minor'] = max(0, $targetMinor - $refundedMinor - $activeMinor);
    $request['method'] = $method;
    $request['method_label'] = bookingRefundMethodLabel($method);
    $request['provider'] = (string)($primarySource['provider'] ?? '');
    $request['provider_payment_id'] = (string)($primarySource['provider_payment_id'] ?? '');
    $request['merchant_reference'] = (string)($primarySource['merchant_reference'] ?? '');
    $request['payment_date'] = (string)($primarySource['paid_at'] ?? $primarySource['created_at'] ?? '');
    $request['automatic_available'] = $automaticCapable;
    $request['manual_required'] = $manualRequired;
    $request['manual_available'] = $manualRequired && (int)$request['remaining_minor'] >= 100
        && !empty($request['refund_destination_account_cipher']) && !empty($request['refund_destination_verified_at']);
    $request['timeline'] = $timeline;
    $request['refund_history'] = $history;
    $refundRows[] = $request;
}
$refundVisibleCount = count($refundRows);
$refundPendingCount = $refundStats['ready'] + $refundStats['processing'] + $refundStats['partial'] + $refundStats['failed'];
$refundCompletionPercent = $refundStats['eligible_minor'] > 0
    ? min(100, max(0, ($refundStats['refunded_minor'] / $refundStats['eligible_minor']) * 100))
    : 100;

$stats = $pdo->query("
    SELECT
      COALESCE(SUM(CASE WHEN status = 'paid' AND paid_at >= DATE_FORMAT(CURRENT_DATE, '%Y-%m-01') THEN amount_minor ELSE 0 END), 0) / 100 AS month_collected,
      COALESCE(SUM(CASE WHEN status = 'paid'
                        AND paid_at >= DATE_FORMAT(CURRENT_DATE - INTERVAL 1 MONTH, '%Y-%m-01')
                        AND paid_at < DATE_FORMAT(CURRENT_DATE, '%Y-%m-01')
                   THEN amount_minor ELSE 0 END), 0) / 100 AS previous_month_collected,
      COALESCE(SUM(CASE WHEN status = 'paid' THEN amount_minor ELSE 0 END), 0) / 100 AS total_collected,
      SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) AS pending_transactions,
      SUM(CASE WHEN status IN ('failed','cancelled','expired') THEN 1 ELSE 0 END) AS attention_transactions,
      SUM(CASE WHEN status IN ('pending','failed','cancelled','expired')
                    AND created_at >= DATE_FORMAT(CURRENT_DATE, '%Y-%m-01') THEN 1 ELSE 0 END) AS month_attention,
      SUM(CASE WHEN status IN ('pending','failed','cancelled','expired')
                    AND created_at >= DATE_FORMAT(CURRENT_DATE - INTERVAL 1 MONTH, '%Y-%m-01')
                    AND created_at < DATE_FORMAT(CURRENT_DATE, '%Y-%m-01') THEN 1 ELSE 0 END) AS previous_month_attention
    FROM payment_transactions
    WHERE LOWER(booking_domain) IN ('package','boat','tourguide')
")->fetch(PDO::FETCH_ASSOC);

$billingStats = $pdo->query("
    SELECT
      COALESCE(SUM(remaining), 0) AS outstanding,
      SUM(CASE WHEN balance_state = 'partial' THEN 1 ELSE 0 END) AS partial_count,
      SUM(CASE WHEN balance_state = 'paid' THEN 1 ELSE 0 END) AS paid_count,
      SUM(CASE WHEN balance_state = 'unpaid' THEN 1 ELSE 0 END) AS unpaid_count,
      SUM(CASE WHEN balance_state = 'paid' AND created_at >= DATE_FORMAT(CURRENT_DATE, '%Y-%m-01') THEN 1 ELSE 0 END) AS month_paid_count,
      SUM(CASE WHEN balance_state = 'paid'
                    AND created_at >= DATE_FORMAT(CURRENT_DATE - INTERVAL 1 MONTH, '%Y-%m-01')
                    AND created_at < DATE_FORMAT(CURRENT_DATE, '%Y-%m-01') THEN 1 ELSE 0 END) AS previous_month_paid_count,
      COALESCE(SUM(CASE WHEN remaining > 0 AND created_at >= DATE_FORMAT(CURRENT_DATE, '%Y-%m-01') THEN remaining ELSE 0 END), 0) AS month_outstanding,
      COALESCE(SUM(CASE WHEN remaining > 0
                             AND created_at >= DATE_FORMAT(CURRENT_DATE - INTERVAL 1 MONTH, '%Y-%m-01')
                             AND created_at < DATE_FORMAT(CURRENT_DATE, '%Y-%m-01') THEN remaining ELSE 0 END), 0) AS previous_month_outstanding
    FROM (
      SELECT GREATEST(remaining_balance, 0) AS remaining,
             CASE WHEN remaining_balance <= 0 THEN 'paid' WHEN payment_amount > 0 THEN 'partial' ELSE 'unpaid' END AS balance_state,
             created_at
      FROM bookings
      WHERE LOWER(booking_type) IN ('package','boat','tourguide')
        AND is_complete NOT IN ('cancelled','declined')
        AND NOT EXISTS (
          SELECT 1
          FROM booking_cancellation_requests cr
          WHERE cr.booking_id = bookings.booking_id
            AND LOWER(cr.booking_domain) <> 'hotel'
            AND cr.request_status IN ('approved','completed')
        )
    ) billing
")->fetch(PDO::FETCH_ASSOC);

$balanceRecords = $pdo->query("
    SELECT b.booking_id,
           LOWER(b.booking_type) AS booking_domain,
           b.booking_reference,
           b.is_complete,
           t.full_name,
           t.email,
           t.profile_picture,
           COALESCE(NULLIF(b.package_name,''), NULLIF(b.location,''), NULLIF(b.preferred_resource,''), 'Tour booking') AS service_name,
           GREATEST(b.grand_total, b.payment_amount + b.remaining_balance) AS total_amount,
           b.payment_amount AS amount_paid,
           b.remaining_balance,
           b.booking_date AS service_date,
           b.created_at,
           cr.cancellation_request_id,
           cr.request_status AS cancellation_status,
           cr.refund_status AS cancellation_refund_status,
           cr.refundable_amount AS cancellation_refundable_amount
    FROM bookings b
    LEFT JOIN tourist t ON t.tourist_id = b.tourist_id
    LEFT JOIN booking_cancellation_requests cr
      ON cr.cancellation_request_id = (
        SELECT cr2.cancellation_request_id
        FROM booking_cancellation_requests cr2
        WHERE cr2.booking_id = b.booking_id
          AND LOWER(cr2.booking_domain) <> 'hotel'
        ORDER BY cr2.cancellation_request_id DESC
        LIMIT 1
      )
    WHERE b.remaining_balance > 0
      AND LOWER(b.booking_type) IN ('package','boat','tourguide')
      AND b.is_complete NOT IN ('cancelled','declined')
      AND (cr.request_status IS NULL OR cr.request_status NOT IN ('approved','completed'))
    ORDER BY b.booking_date ASC, b.created_at ASC
")->fetchAll(PDO::FETCH_ASSOC);
$balanceQueue = $balanceRecords;
$balances = array_values(array_filter($balanceRecords, static function (array $row): bool {
    return !in_array(strtolower((string)($row['cancellation_status'] ?? '')), ['approved', 'completed'], true)
        && !in_array(strtolower((string)($row['is_complete'] ?? '')), ['cancelled', 'declined'], true);
}));
$collectableBalances = array_values(array_filter($balances, static function (array $row): bool {
    return strtolower((string)($row['cancellation_status'] ?? '')) !== 'pending';
}));

$receivableTotal = array_sum(array_map(static fn(array $row): float => (float)$row['remaining_balance'], $balances));
$overdueReceivables = 0.0;
$overdueBookingCount = 0;
foreach ($balances as $balance) {
    $serviceDate = trim((string)($balance['service_date'] ?? ''));
    if ($serviceDate !== '' && $serviceDate < date('Y-m-d')) {
        $overdueReceivables += (float)$balance['remaining_balance'];
        $overdueBookingCount++;
    }
}
$currentReceivables = max(0, $receivableTotal - $overdueReceivables);
$currentReceivablePercent = $receivableTotal > 0 ? ($currentReceivables / $receivableTotal) * 100 : 100;
$collectionTrend = paymentTrend((float)$stats['month_collected'], (float)$stats['previous_month_collected']);
$outstandingTrend = paymentTrend((float)$billingStats['month_outstanding'], (float)$billingStats['previous_month_outstanding'], true);
$paidTrend = paymentTrend((float)$billingStats['month_paid_count'], (float)$billingStats['previous_month_paid_count']);
$attentionTrend = paymentTrend((float)$stats['month_attention'], (float)$stats['previous_month_attention'], true);
$collectionBars = paymentComparisonBars((float)$stats['month_collected'], (float)$stats['previous_month_collected']);
$outstandingBars = paymentComparisonBars((float)$billingStats['month_outstanding'], (float)$billingStats['previous_month_outstanding']);
$paidBars = paymentComparisonBars((float)$billingStats['month_paid_count'], (float)$billingStats['previous_month_paid_count']);
$attentionBars = paymentComparisonBars((float)$stats['month_attention'], (float)$stats['previous_month_attention']);
$currentMonthLabel = date('M');
$previousMonthLabel = date('M', strtotime('first day of last month'));
$collectionTrendLabels = [];
$collectionTrendValues = [];
$collectionTrendMap = [];
$collectionTrendStatement = $pdo->query("SELECT DATE_FORMAT(COALESCE(paid_at,created_at),'%Y-%m') month_key, COALESCE(SUM(amount_minor),0)/100 amount FROM payment_transactions WHERE LOWER(booking_domain) IN ('package','boat','tourguide') AND LOWER(status) IN ('paid','succeeded','completed') AND COALESCE(paid_at,created_at)>=DATE_FORMAT(CURRENT_DATE - INTERVAL 5 MONTH,'%Y-%m-01') GROUP BY month_key");
foreach ($collectionTrendStatement->fetchAll(PDO::FETCH_ASSOC) as $trendRow) $collectionTrendMap[(string)$trendRow['month_key']] = (float)$trendRow['amount'];
for ($monthOffset = 5; $monthOffset >= 0; $monthOffset--) {
    $monthTimestamp = strtotime("first day of -{$monthOffset} month");
    $monthKey = date('Y-m', $monthTimestamp);
    $collectionTrendLabels[] = date('M', $monthTimestamp);
    $collectionTrendValues[] = $collectionTrendMap[$monthKey] ?? 0.0;
}
$collectionTrendMaximum = max(1.0, ...$collectionTrendValues);

$queryForLinks = $_GET;
unset($queryForLinks['page'], $queryForLinks['export']);
$exportBaseFilters = $_GET;
unset($exportBaseFilters['page'], $exportBaseFilters['export'], $exportBaseFilters['export_preview'], $exportBaseFilters['from'], $exportBaseFilters['to']);
$exportFilterLabels = array_filter([
    $statusFilter !== 'all' ? paymentLabel($statusFilter) . ' status' : null,
    $domainFilter !== 'all' ? paymentLabel($domainFilter) : null,
    $methodFilter !== 'all' ? paymentLabel($methodFilter) . ' channel' : null,
    $search !== '' ? 'Search: “' . $search . '”' : null,
]);
$exportFilterSummary = $exportFilterLabels ? implode(' · ', $exportFilterLabels) : 'All statuses, booking types, and payment channels';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Payments & Transactions | iTour Mercedes Admin</title>
  <link rel="icon" type="image/png" href="img/newlogo.png">
  <link rel="stylesheet" href="styles/admin_panel_theme.css">
  <link rel="stylesheet" href="styles/adpaymenttransactions.css?v=36">
  <link rel="stylesheet" href="styles/admin_receipt.css?v=2">
</head>
<body data-ledger-view="<?= htmlspecialchars($activeLedgerView) ?>">
<div class="admin-container">
  <?php include __DIR__ . '/admin_sidebar.php'; ?>
  <?php include __DIR__ . '/../php/alert.php'; ?>
  <main class="main-content payment-main">
    <header class="admin-header admin-page-header">
      <div class="admin-header-left admin-page-title">
        <span class="admin-page-title-icon" aria-hidden="true">
          <svg viewBox="0 0 24 24"><rect x="2.5" y="5" width="19" height="14" rx="2"/><path d="M2.5 10h19M7 15h3"/></svg>
        </span>
        <div class="admin-page-title-copy">
          <h2>Payments & Transactions</h2>
          <p class="admin-header-subtitle">Tour package, boat, and tour guide payment activity</p>
        </div>
      </div>
      <div class="admin-header-right payment-header-actions">
        <button class="payment-button secondary" type="button" id="exportButton">
          <svg viewBox="0 0 24 24"><path d="M12 3v12m0 0 4-4m-4 4-4-4M4 17v3h16v-3"/></svg>
          Export CSV
        </button>
        <button class="payment-button secondary payment-phone-header" type="button" id="openAdminPaymentPhoneModal">
          <svg viewBox="0 0 24 24"><rect x="6" y="2" width="12" height="20" rx="2"/><path d="M10 18h4"/></svg>
          <span>Payment Phone</span>
        </button>
        <button class="payment-button primary" type="button" data-open-payment>
          <svg viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg>
          Collect payment
        </button>
      </div>
    </header>

    <section class="payment-workspace">
      <?php if (is_array($notice)): ?>
        <div class="payment-notice <?= ($notice['type'] ?? '') === 'success' ? 'success' : 'error' ?>" role="alert">
          <span><?= ($notice['type'] ?? '') === 'success' ? '✓' : '!' ?></span>
          <p><?= htmlspecialchars((string)($notice['message'] ?? '')) ?></p>
          <button type="button" aria-label="Dismiss">×</button>
        </div>
      <?php endif; ?>

      <div class="payment-overview-grid">
        <div class="payment-stat-grid">
          <article class="payment-stat collected" tabindex="0" aria-label="Collected this month: <?= htmlspecialchars(paymentMoney((float)$stats['month_collected'])) ?>">
            <div class="stat-top"><span>Collected this month</span><i><svg viewBox="0 0 24 24"><path d="M12 2v20M17 6.5c0-1.4-2.2-2.5-5-2.5S7 5.1 7 6.5 9.2 9 12 9s5 1.1 5 2.5S14.8 14 12 14s-5 1.1-5 2.5S9.2 19 12 19s5-1.1 5-2.5"/></svg></i></div>
            <strong><?= paymentMoney((float)$stats['month_collected']) ?></strong>
            <?= paymentTrendVisual($collectionTrend) ?>
            <div class="stat-comparison">
              <div class="stat-comparison-head"><span>Collection movement</span><b><?= $currentMonthLabel ?> vs <?= $previousMonthLabel ?></b></div>
              <div class="stat-bar-row" title="<?= $currentMonthLabel ?> collections: <?= htmlspecialchars(paymentMoney((float)$stats['month_collected'])) ?>"><span><?= $currentMonthLabel ?></span><div><i style="width:<?= number_format($collectionBars['current'], 1, '.', '') ?>%"></i></div><b><?= paymentMoney((float)$stats['month_collected']) ?></b></div>
              <div class="stat-bar-row previous" title="<?= $previousMonthLabel ?> collections: <?= htmlspecialchars(paymentMoney((float)$stats['previous_month_collected'])) ?>"><span><?= $previousMonthLabel ?></span><div><i style="width:<?= number_format($collectionBars['previous'], 1, '.', '') ?>%"></i></div><b><?= paymentMoney((float)$stats['previous_month_collected']) ?></b></div>
            </div>
            <div class="stat-footer"><small><?= paymentMoney((float)$stats['total_collected']) ?> all time</small><span class="trend-pill <?= $collectionTrend['tone'] ?>"><b><?= $collectionTrend['direction'] === 'up' ? '↑' : ($collectionTrend['direction'] === 'down' ? '↓' : '→') ?></b><?= $collectionTrend['label'] ?><em>vs last month</em></span></div>
          </article>
          <article class="payment-stat outstanding" tabindex="0" aria-label="Outstanding balance: <?= htmlspecialchars(paymentMoney((float)$billingStats['outstanding'])) ?>">
            <div class="stat-top"><span>Outstanding balance</span><i><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg></i></div>
            <strong><?= paymentMoney((float)$billingStats['outstanding']) ?></strong>
            <?= paymentTrendVisual($outstandingTrend) ?>
            <div class="stat-comparison">
              <div class="stat-comparison-head"><span>New balance movement</span><b><?= $currentMonthLabel ?> vs <?= $previousMonthLabel ?></b></div>
              <div class="stat-bar-row" title="<?= $currentMonthLabel ?> new outstanding balance: <?= htmlspecialchars(paymentMoney((float)$billingStats['month_outstanding'])) ?>"><span><?= $currentMonthLabel ?></span><div><i style="width:<?= number_format($outstandingBars['current'], 1, '.', '') ?>%"></i></div><b><?= paymentMoney((float)$billingStats['month_outstanding']) ?></b></div>
              <div class="stat-bar-row previous" title="<?= $previousMonthLabel ?> new outstanding balance: <?= htmlspecialchars(paymentMoney((float)$billingStats['previous_month_outstanding'])) ?>"><span><?= $previousMonthLabel ?></span><div><i style="width:<?= number_format($outstandingBars['previous'], 1, '.', '') ?>%"></i></div><b><?= paymentMoney((float)$billingStats['previous_month_outstanding']) ?></b></div>
            </div>
            <div class="stat-footer"><small><?= (int)$billingStats['partial_count'] ?> partially paid</small><span class="trend-pill <?= $outstandingTrend['tone'] ?>"><b><?= $outstandingTrend['direction'] === 'up' ? '↑' : ($outstandingTrend['direction'] === 'down' ? '↓' : '→') ?></b><?= $outstandingTrend['label'] ?><em>new balance</em></span></div>
          </article>
          <article class="payment-stat paid" tabindex="0" aria-label="Fully paid bookings: <?= number_format((int)$billingStats['paid_count']) ?>">
            <div class="stat-top"><span>Fully paid bookings</span><i><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="m8 12 2.5 2.5L16 9"/></svg></i></div>
            <strong><?= number_format((int)$billingStats['paid_count']) ?></strong>
            <?= paymentTrendVisual($paidTrend) ?>
            <div class="stat-comparison">
              <div class="stat-comparison-head"><span>Paid booking activity</span><b><?= $currentMonthLabel ?> vs <?= $previousMonthLabel ?></b></div>
              <div class="stat-bar-row" title="<?= $currentMonthLabel ?> fully paid bookings: <?= (int)$billingStats['month_paid_count'] ?>"><span><?= $currentMonthLabel ?></span><div><i style="width:<?= number_format($paidBars['current'], 1, '.', '') ?>%"></i></div><b><?= (int)$billingStats['month_paid_count'] ?></b></div>
              <div class="stat-bar-row previous" title="<?= $previousMonthLabel ?> fully paid bookings: <?= (int)$billingStats['previous_month_paid_count'] ?>"><span><?= $previousMonthLabel ?></span><div><i style="width:<?= number_format($paidBars['previous'], 1, '.', '') ?>%"></i></div><b><?= (int)$billingStats['previous_month_paid_count'] ?></b></div>
            </div>
            <div class="stat-footer"><small>Packages, boats, and guides</small><span class="trend-pill <?= $paidTrend['tone'] ?>"><b><?= $paidTrend['direction'] === 'up' ? '↑' : ($paidTrend['direction'] === 'down' ? '↓' : '→') ?></b><?= $paidTrend['label'] ?><em>vs last month</em></span></div>
          </article>
          <article class="payment-stat pending" tabindex="0" aria-label="Transactions needing attention: <?= number_format((int)$stats['pending_transactions'] + (int)$stats['attention_transactions']) ?>">
            <div class="stat-top"><span>Needs attention</span><i><svg viewBox="0 0 24 24"><path d="M12 3 2.8 20h18.4L12 3Z"/><path d="M12 9v5M12 17.5h.01"/></svg></i></div>
            <strong><?= number_format((int)$stats['pending_transactions'] + (int)$stats['attention_transactions']) ?></strong>
            <?= paymentTrendVisual($attentionTrend) ?>
            <div class="stat-comparison">
              <div class="stat-comparison-head"><span>Attention activity</span><b><?= $currentMonthLabel ?> vs <?= $previousMonthLabel ?></b></div>
              <div class="stat-bar-row" title="<?= $currentMonthLabel ?> transactions needing attention: <?= (int)$stats['month_attention'] ?>"><span><?= $currentMonthLabel ?></span><div><i style="width:<?= number_format($attentionBars['current'], 1, '.', '') ?>%"></i></div><b><?= (int)$stats['month_attention'] ?></b></div>
              <div class="stat-bar-row previous" title="<?= $previousMonthLabel ?> transactions needing attention: <?= (int)$stats['previous_month_attention'] ?>"><span><?= $previousMonthLabel ?></span><div><i style="width:<?= number_format($attentionBars['previous'], 1, '.', '') ?>%"></i></div><b><?= (int)$stats['previous_month_attention'] ?></b></div>
            </div>
            <div class="stat-footer"><small><?= (int)$stats['pending_transactions'] ?> pending · <?= (int)$stats['attention_transactions'] ?> unsuccessful</small><span class="trend-pill <?= $attentionTrend['tone'] ?>"><b><?= $attentionTrend['direction'] === 'up' ? '↑' : ($attentionTrend['direction'] === 'down' ? '↓' : '→') ?></b><?= $attentionTrend['label'] ?><em>vs last month</em></span></div>
          </article>
        </div>

        <div class="admin-payment-insights">
        <article class="admin-collection-card">
          <header><div><span class="section-kicker">Collection performance</span><h3>Payments received</h3></div><span>Last 6 months</span></header>
          <div class="admin-collection-chart" aria-label="Payment collections during the last six months">
            <?php foreach ($collectionTrendValues as $trendIndex => $trendValue): ?>
              <div class="admin-collection-column" title="<?= htmlspecialchars($collectionTrendLabels[$trendIndex].': '.paymentMoney($trendValue)) ?>"><strong><?= $trendValue > 0 ? paymentMoney($trendValue) : '' ?></strong><div><i style="height:<?= $trendValue > 0 ? max(7, ($trendValue / $collectionTrendMaximum) * 100) : 2 ?>%"></i></div><small><?= htmlspecialchars($collectionTrendLabels[$trendIndex]) ?></small></div>
            <?php endforeach; ?>
          </div>
        </article>

        <article class="refund-insight-card">
          <header>
            <div class="refund-insight-title"><span class="refund-insight-icon"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9 7H5v4"/><path d="M5.5 10.5A7 7 0 1 1 7 17"/><path d="M8 14h8M8 17h5"/></svg></span><div><span class="section-kicker">Refund liability</span><h3>Pending refunds</h3></div></div>
            <span class="refund-insight-count"><?= number_format($refundPendingCount) ?> open</span>
          </header>
          <div class="refund-insight-overview">
            <div class="refund-insight-total"><span>Amount awaiting return</span><strong><?= paymentMoney($refundStats['pending_minor'] / 100) ?></strong><small>Across <?= number_format($refundPendingCount) ?> approved request<?= $refundPendingCount === 1 ? '' : 's' ?></small></div>
            <div class="refund-progress-ring" style="--refund-progress:<?= number_format($refundCompletionPercent, 1, '.', '') ?>" role="img" aria-label="<?= number_format($refundCompletionPercent, 1) ?> percent of approved refunds completed"><div><strong><?= number_format($refundCompletionPercent, 0) ?>%</strong><span>returned</span></div></div>
          </div>
          <div class="refund-insight-progress"><span style="width:<?= number_format($refundCompletionPercent, 1, '.', '') ?>%"></span></div>
          <div class="refund-insight-breakdown">
            <div><i class="ready"></i><span>Ready</span><strong><?= number_format($refundStats['ready']) ?></strong></div>
            <div><i class="processing"></i><span>Processing</span><strong><?= number_format($refundStats['processing']) ?></strong></div>
            <div><i class="attention"></i><span>Attention</span><strong><?= number_format($refundStats['failed']) ?></strong></div>
          </div>
          <footer><span><i></i><?= paymentMoney($refundStats['refunded_minor'] / 100) ?> provider-confirmed</span><a href="adpaymenttransactions.php?view=refunds#refundLedger" data-review-refunds>Review refunds <b>→</b></a></footer>
        </article>

        <article class="receivables-card">
          <header><div><span class="receivables-icon"><svg viewBox="0 0 24 24"><path d="M6 3h10l3 3v15H6V3Z"/><path d="M15 3v4h4M9 11h7M9 15h7"/></svg></span><div><span class="section-kicker">Accounts receivable</span><h3>Pending payments</h3></div></div><span class="receivable-count"><?= count($balances) ?> open</span></header>
          <div class="receivable-total"><span>Total unpaid balance</span><strong><?= paymentMoney($receivableTotal) ?></strong><small>Across <?= count($balances) ?> active booking<?= count($balances) === 1 ? '' : 's' ?></small></div>
          <div class="receivable-bar" aria-label="Receivables breakdown"><span class="current" style="width:<?= number_format($currentReceivablePercent, 1, '.', '') ?>%"></span><span class="overdue" style="width:<?= number_format(100 - $currentReceivablePercent, 1, '.', '') ?>%"></span></div>
          <div class="receivable-breakdown"><div><span>Current / upcoming</span><strong><?= paymentMoney($currentReceivables) ?></strong></div><div class="overdue"><span>Overdue</span><strong><?= paymentMoney($overdueReceivables) ?></strong><small><?= $overdueBookingCount ?> booking<?= $overdueBookingCount === 1 ? '' : 's' ?></small></div></div>
          <footer><span><i></i><?= (int)$stats['pending_transactions'] ?> online payment<?= (int)$stats['pending_transactions'] === 1 ? '' : 's' ?> pending</span><a class="receivables-button" href="#billingQueue">Review queue <b>→</b></a></footer>
        </article>
        </div>
      </div>

      <section class="payment-panel billing-panel" id="billingQueue" data-receivable-count="<?= count($balances) ?>">
        <div class="panel-heading">
          <div><span class="section-kicker">Billing queue</span><h3>Outstanding bookings</h3></div>
          <button class="text-button" type="button" data-open-payment>Record a collection <span>→</span></button>
        </div>
        <div class="billing-list">
          <?php if (!$balanceQueue): ?>
            <div class="payment-empty compact"><span>✓</span><strong>All balances are settled</strong><p>There are no outstanding bookings right now.</p></div>
          <?php else: foreach ($balanceQueue as $balance):
            $progress = (float)$balance['total_amount'] > 0 ? min(100, max(0, ((float)$balance['amount_paid'] / (float)$balance['total_amount']) * 100)) : 0;
            $profilePicture = trim((string)($balance['profile_picture'] ?? ''));
            $profileImageName = strtolower(basename((string)(parse_url($profilePicture, PHP_URL_PATH) ?: $profilePicture)));
            $hasProfilePicture = $profilePicture !== '' && !in_array($profileImageName, ['profileicon.png', 'profileicon2.png'], true);
            $cancellationStatus = strtolower((string)($balance['cancellation_status'] ?? ''));
            $cancellationRequestId = (int)($balance['cancellation_request_id'] ?? 0);
            $isCancellationPending = $cancellationRequestId > 0 && $cancellationStatus === 'pending';
            $workflowUrl = $isCancellationPending
                ? 'adbookings.php?tab=cancellations&focus_request=' . $cancellationRequestId
                : '';
          ?>
            <article class="billing-item<?= $isCancellationPending ? ' cancellation-pending' : '' ?>">
              <div class="customer-avatar" aria-hidden="true"><?= htmlspecialchars(strtoupper(substr(trim((string)$balance['full_name']) ?: 'G', 0, 1))) ?><?php if ($hasProfilePicture): ?><img src="adbookings.php?action=fetchTouristProfileImage&amp;id=<?= (int)$balance['booking_id'] ?>" alt="" onerror="this.remove()"><?php endif; ?></div>
              <div class="billing-person"><strong><?= htmlspecialchars((string)$balance['full_name'] ?: 'Guest') ?></strong><span><?= htmlspecialchars((string)$balance['booking_reference'] ?: ('Booking #' . $balance['booking_id'])) ?> · <?= htmlspecialchars(paymentLabel((string)$balance['booking_domain'])) ?></span><?php if ($isCancellationPending): ?><em class="billing-workflow-mark">Requesting For Cancellation</em><?php endif; ?></div>
              <div class="billing-service"><strong><?= htmlspecialchars((string)$balance['service_name']) ?></strong><span><?= $balance['service_date'] ? date('M j, Y', strtotime((string)$balance['service_date'])) : 'Date not set' ?></span></div>
              <div class="billing-progress"><div><span style="width:<?= number_format($progress, 1, '.', '') ?>%"></span></div><small><?= paymentMoney((float)$balance['amount_paid']) ?> of <?= paymentMoney((float)$balance['total_amount']) ?></small></div>
              <div class="billing-due"><span>Balance due</span><strong><?= paymentMoney((float)$balance['remaining_balance']) ?></strong></div>
              <div class="billing-actions">
                <button class="view-booking-button" type="button" data-view-booking="<?= (int)$balance['booking_id'] ?>" aria-label="View details for <?= htmlspecialchars((string)$balance['full_name'] ?: 'this booking') ?>">
                  <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M2.5 12s3.5-6 9.5-6 9.5 6 9.5 6-3.5 6-9.5 6-9.5-6-9.5-6Z"/><circle cx="12" cy="12" r="2.8"/></svg>
                  View details
                </button>
                <?php if ($workflowUrl !== ''): ?>
                  <a class="collect-button billing-workflow-view" href="<?= htmlspecialchars($workflowUrl) ?>" aria-label="View cancellation request for <?= htmlspecialchars((string)$balance['full_name'] ?: 'this booking') ?>">View</a>
                <?php else: ?>
                  <button class="collect-button" type="button" data-collect='<?= htmlspecialchars(json_encode($balance, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT), ENT_QUOTES, 'UTF-8') ?>'>Collect</button>
                <?php endif; ?>
              </div>
            </article>
          <?php endforeach; endif; ?>
        </div>
      </section>

      <section class="payment-panel transaction-panel" id="refundLedger">
        <div class="panel-heading transaction-heading">
          <div class="transaction-heading-copy">
            <span class="section-kicker">Payment ledger</span>
            <h3>All transactions</h3>
          </div>
          <p class="transaction-results"><strong><?= number_format($transactionCount) ?></strong><span>result<?= $transactionCount === 1 ? '' : 's' ?><span class="transaction-results-context"> matching the current view.</span></span></p>
        </div>
        <form method="get" class="transaction-filters" id="transactionFilters">
<label class="filter-search"><input type="text" role="searchbox" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="Search reference, customer, email…"></label>
          <select name="status" aria-label="Payment status"><option value="all">All statuses</option><?php foreach (array_slice($allowedStatuses, 1) as $option): ?><option value="<?= $option ?>" <?= $statusFilter === $option ? 'selected' : '' ?>><?= paymentLabel($option) ?></option><?php endforeach; ?></select>
          <select name="domain" aria-label="Booking type"><option value="all">All booking types</option><?php foreach (array_slice($allowedDomains, 1) as $option): ?><option value="<?= $option ?>" <?= $domainFilter === $option ? 'selected' : '' ?>><?= paymentLabel($option) ?></option><?php endforeach; ?></select>
          <select name="method" aria-label="Payment channel">
            <option value="all">All channels</option><option value="paymongo" <?= $methodFilter === 'paymongo' ? 'selected' : '' ?>>PayMongo</option><option value="offline" <?= $methodFilter === 'offline' ? 'selected' : '' ?>>Staff recorded</option><option value="gcash" <?= $methodFilter === 'gcash' ? 'selected' : '' ?>>GCash</option><option value="cash" <?= $methodFilter === 'cash' ? 'selected' : '' ?>>Cash</option><option value="bank_transfer" <?= $methodFilter === 'bank_transfer' ? 'selected' : '' ?>>Bank transfer</option>
          </select>
          <div class="date-filter"><input type="date" name="from" value="<?= htmlspecialchars($dateFrom) ?>" aria-label="From date"><span>to</span><input type="date" name="to" value="<?= htmlspecialchars($dateTo) ?>" aria-label="To date"></div>
          <button type="submit" class="filter-apply">Apply</button>
          <?php if ($search || $statusFilter !== 'all' || $domainFilter !== 'all' || $methodFilter !== 'all' || $dateFrom || $dateTo): ?><a href="adpaymenttransactions.php" class="filter-clear">Clear</a><?php endif; ?>
        </form>

        <div class="transaction-table-wrap">
          <table class="transaction-table">
            <thead><tr><th>Transaction</th><th>Customer & booking</th><th>Channel</th><th>Date</th><th class="amount-column">Amount</th><th>Status</th><th><span class="sr-only">Actions</span></th></tr></thead>
            <tbody>
            <?php if (!$transactions): ?>
              <tr><td colspan="7"><div class="payment-empty"><span>⌕</span><strong>No transactions found</strong><p>Adjust the filters or search for a different reference.</p></div></td></tr>
            <?php else: foreach ($transactions as $transaction):
              $transactionData = [
                'id' => (int)$transaction['payment_transaction_id'], 'reference' => paymentDisplayReference($transaction),
                'booking_reference' => (string)$transaction['booking_reference'], 'customer' => (string)$transaction['full_name'],
                'email' => (string)$transaction['email'], 'service' => (string)$transaction['service_name'],
                'domain' => paymentLabel((string)$transaction['booking_domain']), 'provider' => paymentDisplayChannel($transaction),
                'method' => paymentLabel((string)$transaction['payment_method_type']), 'amount' => paymentMoney(((int)$transaction['amount_minor']) / 100),
                'collection_source' => paymentCollectionSource($transaction),
                'status' => paymentLabel((string)$transaction['status']), 'status_class' => paymentStatusClass((string)$transaction['status']),
                'created' => date('M j, Y · g:i A', strtotime((string)$transaction['created_at'])),
                'paid_at' => $transaction['paid_at'] ? date('M j, Y · g:i A', strtotime((string)$transaction['paid_at'])) : '—',
                'provider_payment_id' => (string)$transaction['provider_payment_id'], 'failure_message' => (string)$transaction['failure_message'],
                'booking_total' => paymentMoney((float)$transaction['booking_total']), 'booking_paid' => paymentMoney((float)$transaction['booking_paid']),
                'booking_balance' => paymentMoney((float)$transaction['booking_balance']),
              ];
            ?>
              <tr>
                <td><div class="transaction-ref"><strong><?= htmlspecialchars(paymentDisplayReference($transaction)) ?></strong><span>#<?= (int)$transaction['payment_transaction_id'] ?></span></div></td>
                <td><div class="transaction-customer"><strong><?= htmlspecialchars((string)$transaction['full_name'] ?: 'Guest') ?></strong><span><?= htmlspecialchars((string)$transaction['booking_reference'] ?: ('Booking #' . $transaction['booking_id'])) ?> · <?= htmlspecialchars(paymentLabel((string)$transaction['booking_domain'])) ?></span></div></td>
                <td><div class="transaction-channel"><strong><?= htmlspecialchars(paymentDisplayChannel($transaction)) ?></strong><span class="channel-source"><?= htmlspecialchars(paymentCollectionSource($transaction)) ?></span><small><?= htmlspecialchars(paymentLabel((string)$transaction['payment_method_type'])) ?></small></div></td>
                <td><div class="transaction-date"><strong><?= date('M j, Y', strtotime((string)$transaction['created_at'])) ?></strong><span><?= date('g:i A', strtotime((string)$transaction['created_at'])) ?></span></div></td>
                <td class="amount-column"><strong><?= paymentMoney(((int)$transaction['amount_minor']) / 100) ?></strong><span><?= htmlspecialchars((string)$transaction['currency']) ?></span></td>
                <td><span class="status-badge <?= paymentStatusClass((string)$transaction['status']) ?>"><i></i><?= htmlspecialchars(paymentLabel((string)$transaction['status'])) ?></span></td>
                <td class="row-action-cell"><button class="row-menu" type="button" aria-label="View transaction details" data-transaction='<?= htmlspecialchars(json_encode($transactionData, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT), ENT_QUOTES, 'UTF-8') ?>'><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="5" cy="12" r="1.35"/><circle cx="12" cy="12" r="1.35"/><circle cx="19" cy="12" r="1.35"/></svg></button></td>
              </tr>
            <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>
        <?php if ($transactionCount > 0): ?>
          <div class="table-footer"><span>Showing <?= number_format($offset + 1) ?>–<?= number_format(min($offset + $perPage, $transactionCount)) ?> of <?= number_format($transactionCount) ?></span><nav aria-label="Transaction pages">
            <a class="<?= $page <= 1 ? 'disabled' : '' ?>" href="?<?= htmlspecialchars(http_build_query(array_merge($queryForLinks, ['page' => max(1, $page - 1)]))) ?>">‹</a>
            <span>Page <?= $page ?> of <?= $totalPages ?></span>
            <a class="<?= $page >= $totalPages ? 'disabled' : '' ?>" href="?<?= htmlspecialchars(http_build_query(array_merge($queryForLinks, ['page' => min($totalPages, $page + 1)]))) ?>">›</a>
          </nav></div>
        <?php endif; ?>

        <div class="admin-ledger-refunds" <?= $activeLedgerView === 'refunds' ? '' : 'hidden' ?>>
          <section class="refund-dashboard" aria-label="Refund processing queue">
            <div class="refund-summary-grid">
              <article><span>Eligible requests</span><strong><?= number_format($refundStats['all']) ?></strong><small><?= paymentMoney($refundStats['eligible_minor'] / 100) ?> approved</small></article>
              <article><span>Ready to process</span><strong><?= number_format($refundStats['ready']) ?></strong><small>Waiting for administrator action</small></article>
              <article><span>Provider processing</span><strong><?= number_format($refundStats['processing']) ?></strong><small>Returned to original channels</small></article>
              <article><span>Refunded</span><strong><?= number_format($refundStats['succeeded']) ?></strong><small>Provider-confirmed refunds</small></article>
              <article class="<?= $refundStats['failed'] > 0 ? 'attention' : '' ?>"><span>Needs attention</span><strong><?= number_format($refundStats['failed']) ?></strong><small>Failed attempts requiring review</small></article>
            </div>
            <div class="refund-policy-note">
              <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3 5 6v5c0 4.6 2.8 8 7 10 4.2-2 7-5.4 7-10V6l-7-3Z"/><path d="m9 12 2 2 4-4"/></svg>
              <div><strong>Refund routing control</strong><span>Eligible full or partial amounts use PayMongo's Refund API when the original payment supports it. Manual transfer remains available only when the approved partial amount cannot be routed automatically.</span></div>
            </div>
            <form method="get" class="refund-filters <?= ($refundSearch !== '' || $refundStateFilter !== 'all') ? 'has-clear' : '' ?>">
              <input type="hidden" name="view" value="refunds">
              <label class="filter-search"><svg viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><path d="m20 20-4-4"/></svg><input type="search" name="refund_q" value="<?= htmlspecialchars($refundSearch) ?>" placeholder="Search tourist, reference, or booking type..."></label>
              <select name="refund_state" aria-label="Refund status">
                <?php foreach (['all' => 'All refund statuses', 'ready' => 'Ready to refund', 'processing' => 'Provider processing', 'partial' => 'Partially refunded', 'succeeded' => 'Refunded', 'failed' => 'Failed / requires attention'] as $value => $label): ?>
                  <option value="<?= $value ?>" <?= $refundStateFilter === $value ? 'selected' : '' ?>><?= $label ?></option>
                <?php endforeach; ?>
              </select>
              <button type="submit" class="filter-apply">Apply filter</button>
              <?php if ($refundSearch !== '' || $refundStateFilter !== 'all'): ?><a href="adpaymenttransactions.php?view=refunds" class="filter-clear">Clear</a><?php endif; ?>
            </form>
            <div class="refund-table-wrap">
              <table class="refund-table">
                <thead><tr><th>Tourist & booking</th><th>Booking type</th><th>Refund destination</th><th>Eligible refund</th><th>Provider status</th><th>Expected posting</th><th>Actions</th></tr></thead>
                <tbody>
                <?php if (!$refundRows): ?>
                  <tr><td colspan="7"><div class="payment-empty"><span>✓</span><strong>No refunds match this view</strong><p>Approved, refund-eligible cancellations will appear here automatically.</p></div></td></tr>
                <?php else: foreach ($refundRows as $refund):
                  $requestId = (int)$refund['cancellation_request_id'];
                  $focused = $focusedCancellationId === $requestId;
                  $initial = strtoupper(substr(trim((string)$refund['full_name']) ?: 'G', 0, 1));
                  $latestFailureCode = '';
                  $latestFailureMessage = '';
                  foreach ((array)$refund['refund_history'] as $refundAttempt) {
                    if (strtolower((string)($refundAttempt['status'] ?? '')) !== 'failed') continue;
                    $latestFailureCode = (string)($refundAttempt['failure_code'] ?? '');
                    $latestFailureMessage = (string)($refundAttempt['failure_message'] ?? '');
                    break;
                  }
                  $refundAttempts = array_map(static function (array $attempt): array {
                    $providerResponse = json_decode((string)($attempt['provider_response'] ?? ''), true);
                    $providerAttributes = is_array($providerResponse['data']['attributes'] ?? null) ? $providerResponse['data']['attributes'] : [];
                    return [
                      'transaction' => !empty($attempt['payment_transaction_id']) ? '#' . (int)$attempt['payment_transaction_id'] : 'Manual refund',
                      'amount' => paymentMoney(((int)($attempt['amount_minor'] ?? 0)) / 100),
                      'method' => bookingRefundMethodLabel((string)($attempt['payment_method_type'] ?? '')),
                      'status' => strtolower((string)($attempt['status'] ?? '')) === 'failed'
                        ? 'Failed'
                        : bookingRefundStatusLabel((string)($attempt['status'] ?? '')),
                      'reference' => (string)($attempt['provider_refund_id'] ?? $attempt['provider_reference_number'] ?? ''),
                      'claim_required' => filter_var((string)($providerAttributes['transfer_link'] ?? ''), FILTER_VALIDATE_URL) !== false,
                      'failure' => (string)($attempt['failure_message'] ?? ''),
                    ];
                  }, (array)$refund['refund_history']);
                  $details = [
                    'request_id' => $requestId,
                    'guest' => (string)$refund['full_name'],
                    'email' => (string)$refund['email'],
                    'profile_image' => 'adbookings.php?action=fetchTouristProfileImage&id=' . (int)$refund['booking_id'],
                    'booking_reference' => (string)$refund['booking_reference'],
                    'booking_type' => paymentLabel((string)$refund['booking_type']),
                    'service' => (string)$refund['service_name'],
                    'service_date' => date('M j, Y', strtotime((string)$refund['service_date'])),
                    'requested_at' => date('M j, Y · g:i A', strtotime((string)$refund['requested_at'])),
                    'policy' => bookingCancellationPolicyLabel((string)$refund['refund_policy']),
                    'amount_paid' => paymentMoney((float)$refund['amount_paid']),
                    'eligible_amount' => paymentMoney((float)$refund['refundable_amount']),
                    'non_refundable' => paymentMoney((float)$refund['non_refundable_amount']),
                    'refunded_amount' => paymentMoney(((int)$refund['refunded_minor']) / 100),
                    'status' => (string)$refund['refund_state_label'],
                    'status_class' => (string)$refund['refund_state'],
                    'method' => (string)$refund['method_label'],
                    'destination' => !empty($refund['manual_required'])
                        ? (trim((string)($refund['refund_destination_institution'] ?? '')) . (!empty($refund['refund_destination_last4']) ? ' ending in ' . (string)$refund['refund_destination_last4'] : ''))
                        : ($refund['automatic_available'] ? (string)$refund['method_label'] . ' account used for the original payment' : 'Automatic refund requirements not met'),
                    'route' => !empty($refund['manual_required']) ? 'Administrator-recorded transfer' : 'Original payment method',
                    'provider' => paymentLabel((string)$refund['provider']),
                    'payment_reference' => (string)$refund['provider_payment_id'],
                    'merchant_reference' => (string)$refund['merchant_reference'],
                    'provider_refund_id' => (string)($refund['provider_refund_id'] ?: $refund['provider_transfer_id']),
                    'manual_refund_channel' => (string)($refund['manual_refund_channel'] ?? ''),
                    'manual_sender_account' => (string)($refund['manual_sender_account'] ?? ''),
                    'manual_refund_note' => (string)($refund['manual_refund_note'] ?? ''),
                    'payment_date' => $refund['payment_date'] ? date('M j, Y · g:i A', strtotime((string)$refund['payment_date'])) : 'Not recorded',
                    'timeline' => (string)$refund['timeline']['short'],
                    'timeline_detail' => (string)$refund['timeline']['detail'],
                    'reason' => (string)$refund['cancellation_reason'],
                    'automatic_available' => (bool)$refund['automatic_available'],
                    'failure_code' => $latestFailureCode,
                    'failure_message' => $latestFailureMessage,
                    'attempts' => $refundAttempts,
                  ];
                ?>
                  <tr id="refund-request-<?= $requestId ?>" class="<?= $focused ? 'refund-focus' : '' ?>">
                    <td><div class="refund-guest"><span class="refund-avatar"><?= htmlspecialchars($initial) ?><img src="adbookings.php?action=fetchTouristProfileImage&amp;id=<?= (int)$refund['booking_id'] ?>" alt="" onerror="this.remove()"></span><div><strong><?= htmlspecialchars((string)$refund['full_name'] ?: 'Guest') ?></strong><small><?= htmlspecialchars((string)$refund['booking_reference']) ?> · <?= htmlspecialchars(paymentLabel((string)$refund['booking_type'])) ?></small></div></div></td>
                    <td><div class="refund-cell refund-booking-type"><span class="refund-type-pill"><?= htmlspecialchars(paymentLabel((string)$refund['booking_type'])) ?></span><small>Service date: <?= date('M j, Y', strtotime((string)$refund['service_date'])) ?></small></div></td>
                    <td><div class="refund-cell refund-route"><strong><?= htmlspecialchars(!empty($refund['manual_required']) ? ((string)($refund['refund_destination_institution'] ?? '') ?: 'Refund account required') : (string)$refund['method_label']) ?></strong><small><?= !empty($refund['manual_required']) ? (!empty($refund['refund_destination_last4']) ? 'Manual transfer to account ending ' . htmlspecialchars((string)$refund['refund_destination_last4']) : 'Tourist must add refund account') : ($refund['automatic_available'] ? 'Returns through PayMongo API' : 'API refund unavailable') ?></small></div></td>
                    <td><div class="refund-cell refund-amount"><strong><?= paymentMoney((float)$refund['refundable_amount']) ?></strong><small><?= htmlspecialchars(bookingCancellationPolicyLabel((string)$refund['refund_policy'])) ?></small></div></td>
                    <td><span class="refund-state <?= htmlspecialchars((string)$refund['refund_state']) ?>"><?= htmlspecialchars((string)$refund['refund_state_label']) ?></span><?php if ($refund['provider_refund_id'] || $refund['provider_transfer_id']): ?><small class="refund-provider-ref"><?= htmlspecialchars((string)($refund['provider_refund_id'] ?: $refund['provider_transfer_id'])) ?></small><?php endif; ?></td>
                    <td><div class="refund-cell"><strong><?= htmlspecialchars((string)$refund['timeline']['short']) ?></strong><small><?= $refund['automatic_available'] ? 'After provider acceptance' : 'Review refund requirements' ?></small></div></td>
                    <td class="refund-actions">
                      <button type="button" class="refund-action-menu" aria-haspopup="true" aria-expanded="false">Actions <svg class="refund-menu-chevron" viewBox="0 0 24 24" aria-hidden="true"><path d="m7 9 5 5 5-5"/></svg></button>
                      <div class="refund-action-popover" hidden>
                        <button type="button" data-refund-details='<?= htmlspecialchars(json_encode($details, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT), ENT_QUOTES, 'UTF-8') ?>'><svg class="refund-action-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M2.5 12s3.5-6 9.5-6 9.5 6 9.5 6-3.5 6-9.5 6-9.5-6-9.5-6Z"/><circle cx="12" cy="12" r="2.8"/></svg>View details</button>
                        <?php if (in_array($refund['refund_state'], ['ready', 'partial', 'failed'], true)): ?>
                          <?php if (!empty($refund['manual_required']) && !empty($refund['manual_available'])): ?><button type="button" data-process-manual-refund data-request-id="<?= $requestId ?>"><svg class="refund-action-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7h16v10H4z"/><path d="M7 11h5M16 10v2"/></svg>Process manual refund</button>
                          <?php elseif (!empty($refund['manual_required'])): ?><span class="refund-action-disabled" title="The tourist must add a verified refund account first.">Refund account required</span>
                          <?php elseif ($refund['automatic_available']): ?><form method="post" data-process-refund><input type="hidden" name="action" value="process_refund"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>"><input type="hidden" name="cancellation_request_id" value="<?= $requestId ?>"><button type="submit"><svg class="refund-action-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"/></svg><?= $refund['refund_state'] === 'partial' ? 'Continue remaining refund' : ($refund['refund_state'] === 'failed' ? 'Retry API refund' : 'Process API refund') ?></button></form>
                          <?php else: ?><span class="refund-action-disabled" title="The payment does not meet PayMongo API refund requirements.">API refund unavailable</span><?php endif; ?>
                        <?php elseif ($refund['refund_state'] === 'processing'): ?>
                          <form method="post" data-refresh-refund><input type="hidden" name="action" value="refresh_refund"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>"><input type="hidden" name="cancellation_request_id" value="<?= $requestId ?>"><button type="submit"><svg class="refund-action-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M20 11a8 8 0 0 0-14.8-4L3 10"/><path d="M3 5v5h5M4 13a8 8 0 0 0 14.8 4L21 14"/><path d="M21 19v-5h-5"/></svg>Refresh provider status</button></form>
                        <?php else: ?><span class="refund-action-success">✓ Provider confirmed</span><?php endif; ?>
                      </div>
                    </td>
                  </tr>
                <?php endforeach; endif; ?>
                </tbody>
              </table>
            </div>
            <footer class="refund-table-footer">Showing <?= number_format($refundVisibleCount) ?> of <?= number_format($refundStats['all']) ?> approved refund request<?= $refundStats['all'] === 1 ? '' : 's' ?>.</footer>
          </section>
        </div>
      </section>
    </section>
  </main>
</div>

<div class="transaction-export-modal" id="transactionExportModal" aria-hidden="true" inert>
  <div class="transaction-export-backdrop" data-close-export></div>
  <section class="transaction-export-card" role="dialog" aria-modal="true" aria-labelledby="transactionExportTitle">
    <header class="transaction-export-head">
      <span class="transaction-export-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M6 3h9l4 4v14H6V3Z"/><path d="M14 3v5h5M9 12h7M9 16h7"/></svg></span>
      <div><span>FORMAL DATA REPORT</span><h3 id="transactionExportTitle">Export Payment Transactions</h3><p>Choose a reporting period, review the matching records, then download the CSV.</p></div>
      <button type="button" class="transaction-export-close" data-close-export aria-label="Close export preview">&times;</button>
    </header>
    <div class="transaction-export-body">
      <section class="export-period-panel">
        <div class="export-period-heading"><div><span>REPORTING PERIOD</span><strong>Select transactions to include</strong></div><span class="export-format-badge">CSV</span></div>
        <div class="export-period-controls">
          <label><span>Period</span><select id="exportPeriod"><option value="this_month">This month</option><option value="last_month">Previous month</option><option value="custom">Custom date range</option><option value="all">All available dates</option></select></label>
          <div class="export-custom-range" id="exportCustomRange" hidden>
            <label><span>From</span><input type="date" id="exportDateFrom"></label>
            <span>to</span>
            <label><span>Through</span><input type="date" id="exportDateTo"></label>
          </div>
          <button type="button" class="export-preview-button" id="refreshExportPreview"><svg viewBox="0 0 24 24"><path d="M4 12a8 8 0 0 1 14-5M20 12a8 8 0 0 1-14 5"/><path d="m18 3 .3 4.2L14 7M6 21l-.3-4.2L10 17"/></svg>Preview</button>
        </div>
        <div class="export-active-filters"><svg viewBox="0 0 24 24"><path d="M4 5h16l-6 7v6l-4 2v-8L4 5Z"/></svg><span>Current page filters:</span><strong><?= htmlspecialchars($exportFilterSummary) ?></strong></div>
      </section>

      <div class="export-summary-grid">
        <article><span>Matching records</span><strong id="exportRecordCount">—</strong><small>Rows in the CSV</small></article>
        <article><span>Verified paid value</span><strong id="exportPaidAmount">—</strong><small>Successful payments</small></article>
        <article><span>Listed amount</span><strong id="exportListedAmount">—</strong><small>All matching statuses</small></article>
      </div>

      <section class="export-preview-panel">
        <header><div><span>REPORT PREVIEW</span><h4>Transactions included</h4></div><small id="exportPreviewCaption">Preparing preview…</small></header>
        <div class="export-preview-state" id="exportPreviewState"><span></span>Loading matching transactions…</div>
        <div class="export-preview-table-wrap" id="exportPreviewTableWrap" hidden>
          <table class="export-preview-table"><thead><tr><th>Transaction</th><th>Customer & booking</th><th>Type</th><th>Amount</th><th>Status</th></tr></thead><tbody id="exportPreviewRows"></tbody></table>
        </div>
      </section>
    </div>
    <footer class="transaction-export-footer">
      <p><svg viewBox="0 0 24 24"><path d="M12 3 5 6v5c0 4.6 2.8 8 7 10 4.2-2 7-5.4 7-10V6l-7-3Z"/><path d="m9 12 2 2 4-4"/></svg>The downloaded report includes only the selected period and current ledger filters.</p>
      <div><button type="button" class="payment-button secondary" data-close-export>Close</button><button type="button" class="payment-button primary" id="downloadExportCsv" disabled><svg viewBox="0 0 24 24"><path d="M12 3v12m0 0 4-4m-4 4-4-4M4 18v3h16v-3"/></svg>Download CSV</button></div>
    </footer>
  </section>
</div>

<div class="payment-modal" id="recordPaymentModal" aria-hidden="true">
  <div class="payment-modal-backdrop" data-close-modal></div>
  <section class="payment-modal-card" role="dialog" aria-modal="true" aria-labelledby="recordPaymentTitle">
    <header><div><span class="modal-icon"><svg viewBox="0 0 24 24"><path d="M12 3v18M17 7.5H9.5a3 3 0 0 0 0 6h5a3 3 0 0 1 0 6H7"/></svg></span><div><span class="payment-modal-kicker">PAYMENT COLLECTION</span><h3 id="recordPaymentTitle">Pay Balance</h3><p>Collect a cash or PayMongo payment for an outstanding booking.</p></div></div><button type="button" data-close-modal aria-label="Close">&times;</button></header>
    <form method="post" id="recordPaymentForm">
      <input type="hidden" name="action" value="record_payment"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
      <div class="selected-booking" id="selectedBooking"><span>Select an outstanding booking below</span><strong>No booking selected</strong></div>
      <label><span>Outstanding booking</span><select name="booking_selection" id="bookingSelection" required><option value="">Choose a booking…</option><?php foreach ($collectableBalances as $balance): ?><option value="<?= htmlspecialchars($balance['booking_domain'] . ':' . $balance['booking_id']) ?>" data-balance="<?= number_format((float)$balance['remaining_balance'], 2, '.', '') ?>" data-customer="<?= htmlspecialchars((string)$balance['full_name']) ?>" data-reference="<?= htmlspecialchars((string)$balance['booking_reference']) ?>"><?= htmlspecialchars(((string)$balance['booking_reference'] ?: ('Booking #' . $balance['booking_id'])) . ' — ' . (string)$balance['full_name'] . ' — ' . paymentMoney((float)$balance['remaining_balance'])) ?></option><?php endforeach; ?></select></label>
      <input type="hidden" name="booking_domain" id="paymentDomain"><input type="hidden" name="booking_id" id="paymentBookingId">
      <p class="pay-balance-context">Record the amount received. Partial payments are allowed.</p>
      <div class="modal-form-grid"><label><span>Payment method</span><select name="payment_method" id="collectionPaymentMethod" required><option value="" selected disabled>Select payment method</option><option value="cash">Cash</option><option value="qr_code">QR Code (PayMongo)</option></select></label><label><span>Amount paid</span><div class="money-input"><i>₱</i><input type="number" name="amount" id="paymentAmount" min="0.01" step="0.01" required placeholder="0.00"></div><small id="amountHelp">Cannot exceed the outstanding balance.</small></label></div>
      <section class="collection-phone-status" id="collectionPhoneStatus" hidden><svg viewBox="0 0 24 24"><rect x="6" y="2" width="12" height="20" rx="2"/><path d="M10 18h4"/></svg><div><small>REGISTERED ADMIN PHONE</small><strong id="collectionPhoneName">Checking payment phone…</strong><span id="collectionPhoneMeta">Please wait.</span></div><button type="button" id="manageAdminPhoneBtn">Change</button></section>
      <div class="modal-security-note"><svg viewBox="0 0 24 24"><path d="M12 3 5 6v5c0 4.6 2.8 8 7 10 4.2-2 7-5.4 7-10V6l-7-3Z"/><path d="m9 12 2 2 4-4"/></svg><p>Cash payments are recorded immediately. PayMongo payments update the booking only after secure provider verification.</p></div>
      <footer><button type="button" class="payment-button secondary" data-close-modal>Cancel</button><button type="submit" class="payment-button primary" id="savePaymentButton">Confirm payment</button></footer>
    </form>
  </section>
</div>

<div id="adminPaymentPhoneOverviewModal" class="admin-phone-registration-overlay admin-phone-overview-overlay" aria-hidden="true" inert>
  <section class="admin-phone-registration-modal admin-phone-overview-modal" role="dialog" aria-modal="true" aria-labelledby="adminPaymentPhoneOverviewTitle">
    <header class="admin-phone-registration-head">
      <div class="admin-phone-registration-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><rect x="6" y="2" width="12" height="20" rx="2"/><path d="M10 18h4"/></svg></div>
      <div><span>PAYMENT NOTIFICATIONS</span><h3 id="adminPaymentPhoneOverviewTitle">Payment Phone</h3><p>The registered phone receives secure PayMongo QR notifications.</p></div>
      <button type="button" class="admin-phone-registration-x" data-close-phone-overview aria-label="Close payment phone">&times;</button>
    </header>
    <div class="admin-phone-registration-body admin-phone-overview-body">
      <section class="admin-phone-current-device" id="adminPhoneOverviewDevice" aria-live="polite">
        <div class="admin-phone-current-device-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><rect x="6" y="2" width="12" height="20" rx="2"/><path d="M10 18h4"/></svg></div>
        <div class="admin-phone-current-device-copy"><small>REGISTERED PAYMENT PHONE</small><strong id="adminPhoneOverviewName">Checking registered phone…</strong><span id="adminPhoneOverviewMeta">Please wait.</span></div>
        <button type="button" class="admin-phone-overview-change" id="adminPhoneOverviewAction">Change</button>
      </section>
    </div>
    <footer class="admin-phone-registration-footer"><button type="button" class="admin-phone-registration-btn secondary" data-close-phone-overview>Close</button></footer>
  </section>
</div>

<div id="adminPhoneRegistrationModal" class="admin-phone-registration-overlay" aria-hidden="true" inert>
  <section class="admin-phone-registration-modal" role="dialog" aria-modal="true" aria-labelledby="adminPhoneRegistrationTitle">
    <header class="admin-phone-registration-head">
      <div class="admin-phone-registration-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><rect x="6" y="2" width="12" height="20" rx="2"/><path d="M10 18h4"/></svg></div>
      <div><span>ADMINISTRATOR DEVICE</span><h3 id="adminPhoneRegistrationTitle">Register an Admin Phone</h3><p>Complete these steps using the phone that should display payment QR notifications.</p></div>
      <button type="button" class="admin-phone-registration-x" data-close-phone-registration aria-label="Close phone registration">&times;</button>
    </header>
    <div class="admin-phone-registration-body">
      <section class="admin-phone-current-device" id="adminPhoneCurrentDevice" aria-live="polite">
        <div class="admin-phone-current-device-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><rect x="6" y="2" width="12" height="20" rx="2"/><path d="M10 18h4"/></svg></div>
        <div class="admin-phone-current-device-copy"><small>CURRENT PAYMENT PHONE</small><strong id="adminPhoneModalDeviceName">Checking registered phone…</strong><span id="adminPhoneModalDeviceMeta">Please wait.</span></div>
        <span class="admin-phone-current-device-state" id="adminPhoneModalDeviceState">Checking</span>
      </section>
      <ol class="admin-phone-registration-steps">
        <li><b>Open the setup address on the admin phone.</b><span>Log in with the same main Administrator account when asked.</span></li>
        <li><b>Name and register the phone.</b><span>Tap Register This Phone and allow browser notifications.</span></li>
        <li><b>Return to this computer.</b><span>This window detects the newly registered phone automatically.</span></li>
      </ol>
      <label class="admin-phone-setup-link"><span>PHONE SETUP ADDRESS</span><div><input type="text" id="adminPhoneSetupUrl" readonly><button type="button" id="copyAdminPhoneSetupUrl">Copy Link</button></div></label>
      <div class="admin-phone-registration-status" id="adminPhoneRegistrationStatus"><span></span><div><strong>Waiting for phone registration</strong><small>Keep this window open while registering the phone.</small></div></div>
    </div>
    <footer class="admin-phone-registration-footer">
      <button type="button" class="admin-phone-registration-btn secondary" data-close-phone-registration>Close</button>
      <button type="button" class="admin-phone-registration-btn secondary" id="checkAdminPhoneRegistration">Check Again</button>
      <button type="button" class="admin-phone-registration-btn primary" id="openAdminPhoneSetupPage">Open Setup Page</button>
    </footer>
  </section>
</div>

<div class="payment-drawer booking-detail-drawer" id="bookingDetailDrawer" aria-hidden="true">
  <div class="payment-modal-backdrop" data-close-booking-drawer></div>
  <aside role="dialog" aria-modal="true" aria-labelledby="bookingDetailTitle">
    <header>
      <div class="booking-detail-brand">
        <span class="booking-detail-brand-icon"><img src="img/newlogo.png" alt=""></span>
        <div><span class="section-kicker">iTour Mercedes</span><h3 id="bookingDetailTitle">Booking details</h3></div>
      </div>
      <button type="button" data-close-booking-drawer aria-label="Close booking details">&times;</button>
    </header>
    <div class="drawer-body booking-detail-body" id="bookingDetailBody">
      <div class="booking-detail-loading"><span></span><p>Loading booking details&hellip;</p></div>
    </div>
    <footer>
      <button class="payment-button secondary" type="button" data-close-booking-drawer>Close details</button>
      <button class="payment-button primary" type="button" id="bookingDetailCollectButton" disabled>Collect payment</button>
    </footer>
  </aside>
</div>

<div class="payment-drawer refund-detail-drawer" id="refundDetailDrawer" aria-hidden="true">
  <div class="payment-modal-backdrop" data-close-refund-drawer></div>
  <aside role="dialog" aria-modal="true" aria-labelledby="refundDetailTitle">
    <header>
      <div class="booking-detail-brand">
        <span class="booking-detail-brand-icon"><img src="img/newlogo.png" alt=""></span>
        <div><span class="section-kicker">iTour Mercedes finance</span><h3 id="refundDetailTitle">Refund details</h3></div>
      </div>
      <button type="button" data-close-refund-drawer aria-label="Close refund details">&times;</button>
    </header>
    <div class="drawer-body refund-detail-body" id="refundDetailBody"></div>
    <footer><button class="payment-button secondary" type="button" data-close-refund-drawer>Close details</button></footer>
  </aside>
</div>

<div class="payment-drawer" id="transactionDrawer" aria-hidden="true">
  <div class="payment-modal-backdrop" data-close-drawer></div>
  <aside role="dialog" aria-modal="true" aria-labelledby="drawerTitle">
    <header><div><span class="section-kicker">Transaction details</span><h3 id="drawerTitle">Payment record</h3></div><button type="button" data-close-drawer aria-label="Close">×</button></header>
    <div class="drawer-body" id="drawerBody"></div>
    <footer><button class="payment-button secondary" type="button" id="openTransactionReceipt" hidden>View receipt</button><button class="payment-button primary" type="button" data-close-drawer>Done</button></footer>
  </aside>
</div>

<div id="transactionReceiptModal" class="admin-receipt-overlay" aria-hidden="true" inert>
  <section class="admin-receipt-shell" role="dialog" aria-modal="true" aria-labelledby="transactionReceiptTitle">
    <header class="admin-receipt-head">
      <div><span>OFFICIAL PAYMENT RECORD</span><h3 id="transactionReceiptTitle">Payment Receipt</h3></div>
      <button type="button" class="admin-receipt-x" data-close-transaction-receipt aria-label="Close payment receipt">&times;</button>
    </header>
    <div class="admin-receipt-stage">
      <article class="admin-receipt-paper" id="transactionReceiptPaper"></article>
    </div>
    <footer class="admin-receipt-footer">
      <button type="button" class="admin-receipt-btn secondary" data-close-transaction-receipt>Close</button>
      <button type="button" class="admin-receipt-btn print" id="printTransactionReceipt">Print</button>
      <button type="button" class="admin-receipt-btn primary" id="downloadTransactionReceipt">Download</button>
    </footer>
  </section>
</div>

<script>
window.paymentExportConfig = <?= json_encode([
  'endpoint' => 'adpaymenttransactions.php',
  'baseQuery' => http_build_query($exportBaseFilters),
  'defaultFrom' => $dateFrom,
  'defaultTo' => $dateTo,
], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
window.paymentCollectionConfig = <?= json_encode([
  'csrf' => $payMongoAdminCsrf,
  'checkoutEndpoint' => (str_contains(str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? '')), '/admin/') ? '../' : '') . 'payments/create-balance-checkout.php',
  'statusEndpoint' => 'adbookings.php?action=paymongoPaymentStatus',
  'phoneStatusEndpoint' => (str_contains(str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? '')), '/admin/') ? '' : 'admin/') . 'push-device-status.php',
  'phoneSetupPage' => (str_contains(str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? '')), '/admin/') ? '../' : '') . 'admin-phone-setup.php',
  'publicAppUrl' => (string)($paymentFirebaseConfiguration['app_url'] ?? ''),
], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
window.refundProcessingConfig = <?= json_encode([
  'csrf' => $csrfToken,
  'detailsEndpoint' => 'adpaymenttransactions.php',
], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
</script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
<script src="https://html2canvas.hertzen.com/dist/html2canvas.min.js"></script>
<script src="js/adpaymenttransactions.js?v=26"></script>
</body>
</html>
