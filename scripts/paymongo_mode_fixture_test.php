<?php
declare(strict_types=1);

// Offline only. No application database bootstrap, private keys, or HTTP calls.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../payments/PayMongoService.php';
require_once __DIR__ . '/../payments/PaymentReconciler.php';
require_once __DIR__ . '/../payments/RefundWebhookReconciler.php';

$checks = 0;
function modeAssert(bool $condition, string $message): void
{
    global $checks;
    if (!$condition) throw new RuntimeException($message);
    $checks++;
}
function modeReject(callable $action, string $message): void
{
    try { $action(); } catch (UnexpectedValueException|InvalidArgumentException|JsonException $exception) {
        modeAssert(true, $message);
        return;
    }
    throw new RuntimeException($message);
}

/** Translate MySQL syntax only; execute real application SQL on isolated SQLite.
 * This does not test MySQL row locking/concurrent sessions or the hosted API.
 */
final class PayMongoFixturePDO extends PDO
{
    public function __construct()
    {
        parent::__construct('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->sqliteCreateFunction('NOW', static fn() => '2026-10-02 12:00:00');
    }
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        if (str_contains($query, 'information_schema.COLUMNS')) {
            $query = 'SELECT COUNT(*) FROM pragma_table_info(?) WHERE name=?';
        } elseif (str_contains($query, 'information_schema.TABLES')) {
            $query = "SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name=?";
        }
        $query = preg_replace('/\bFOR UPDATE\b/i', '', $query);
        $query = str_replace('INSERT IGNORE', 'INSERT OR IGNORE', $query);
        return parent::prepare($query, $options);
    }
}

function modeDatabase(): PayMongoFixturePDO
{
    $pdo = new PayMongoFixturePDO();
    $pdo->exec("CREATE TABLE payment_transactions (
        payment_transaction_id INTEGER PRIMARY KEY, tourist_id INTEGER, booking_domain TEXT,
        booking_id INTEGER, booking_reference TEXT, provider TEXT DEFAULT 'paymongo',
        merchant_reference TEXT UNIQUE, provider_checkout_session_id TEXT UNIQUE,
        provider_payment_intent_id TEXT, provider_payment_id TEXT UNIQUE, provider_event_id TEXT UNIQUE,
        amount_minor INTEGER, currency TEXT DEFAULT 'PHP', status TEXT DEFAULT 'pending', metadata TEXT,
        payment_method_type TEXT, paid_at TEXT, failed_at TEXT, failure_code TEXT, failure_message TEXT, checkout_url TEXT
    );
    CREATE TABLE booking_reference_sequences (prefix TEXT, reference_year INTEGER, next_number INTEGER, PRIMARY KEY(prefix,reference_year));
    CREATE TABLE booking_checkout_drafts (
        booking_draft_id INTEGER PRIMARY KEY, tourist_id INTEGER, booking_domain TEXT,
        payload TEXT, total_minor INTEGER, amount_minor INTEGER, payment_type TEXT, status TEXT,
        booking_id INTEGER, booking_reference TEXT, submitted_at TEXT
    );
    CREATE TABLE bookings (
        booking_id INTEGER PRIMARY KEY, booking_reference TEXT UNIQUE, tourist_id INTEGER,
        booking_date TEXT, location TEXT, package_name TEXT, phone_number TEXT, booking_type TEXT,
        operator_id INTEGER, tour_type TEXT, tour_range TEXT, jump_off_port TEXT, preferred_resource TEXT,
        boat_id INTEGER, guide_id INTEGER, grand_total NUMERIC, remaining_balance NUMERIC,
        payment_amount NUMERIC, created_at TEXT, updated_at TEXT, status TEXT, is_complete TEXT,
        is_notif_viewed INTEGER, num_adults INTEGER, num_children INTEGER, is_paid INTEGER, payment_method TEXT
    );
    CREATE TABLE hotel_room_bookings (
        hotel_booking_id INTEGER PRIMARY KEY, booking_reference TEXT UNIQUE, tourist_id INTEGER,
        hotel_resort_id INTEGER, hotel_room_id INTEGER, room_type TEXT, checkin_date TEXT, checkout_date TEXT,
        nights INTEGER, rooms_booked INTEGER, adults INTEGER, children INTEGER, first_name TEXT, last_name TEXT,
        email TEXT, phone_number TEXT, special_request TEXT, unit_price NUMERIC, total_amount NUMERIC,
        amount_paid NUMERIC, remaining_balance NUMERIC, payment_type TEXT, booking_status TEXT,
        payment_status TEXT, balance_payment_method TEXT, checked_in_at TEXT, checked_out_at TEXT,
        checkout_additional_charges NUMERIC, checkout_final_payment_amount NUMERIC, checkout_payment_method TEXT, updated_at TEXT
    );
    CREATE TABLE admin_activity_logs (
        admin_id INTEGER, actor_type TEXT, actor_id INTEGER, actor_name TEXT, action TEXT,
        module TEXT, description TEXT, reference_id INTEGER, ip_address TEXT
    );
    CREATE TABLE booking_refunds (
        booking_refund_id INTEGER PRIMARY KEY, payment_transaction_id INTEGER, cancellation_request_id INTEGER,
        provider TEXT, provider_refund_id TEXT UNIQUE, provider_payment_id TEXT,
        amount_minor INTEGER, currency TEXT, status TEXT, provider_response TEXT, completed_at TEXT
    );
    CREATE TABLE booking_cancellation_requests (
        cancellation_request_id INTEGER PRIMARY KEY, refundable_amount NUMERIC, refund_status TEXT, refund_updated_at TEXT
    );
    CREATE TABLE paymongo_webhook_events (event_id TEXT PRIMARY KEY, event_type TEXT, payload_hash TEXT, processed_at TEXT);");
    return $pdo;
}

function modeTransaction(PDO $pdo, string $mode, string $domain, int $bookingId, int $amount, array $metadata = []): array
{
    static $next = 0;
    $id = ++$next;
    $metadata = array_merge(['paymongo_mode' => $mode, 'source' => 'tourist_profile_balance'], $metadata);
    $pdo->prepare('INSERT INTO payment_transactions (payment_transaction_id,tourist_id,booking_domain,booking_id,booking_reference,merchant_reference,provider_checkout_session_id,amount_minor,metadata) VALUES (?,7,?,?,?,?,?,?,?)')
        ->execute([$id, $domain, $bookingId, 'BOOK-' . $bookingId, 'REF-' . $id, 'cs_fixture' . $id, $amount, json_encode($metadata)]);
    return [
        'id' => 'cs_fixture' . $id, 'type' => 'checkout_session',
        'attributes' => [
            'livemode' => $mode === 'live', 'reference_number' => 'REF-' . $id,
            'payments' => [[
                'id' => 'pay_fixture' . $id,
                'attributes' => ['livemode' => $mode === 'live', 'status' => 'paid', 'amount' => $amount,
                    'currency' => 'PHP', 'payment_intent_id' => 'pi_fixture' . $id, 'source' => ['type' => 'qrph']],
            ]],
        ],
    ];
}

$originalMode = getenv('PAYMONGO_MODE');
$fixtureEnv = tempnam(sys_get_temp_dir(), 'itour_mode_env_');
$fixtureLog = tempnam(sys_get_temp_dir(), 'itour_mode_log_');
$originalLog = ini_set('error_log', $fixtureLog);
try {
    putenv('PAYMONGO_MODE');
    modeAssert(PaymentHelper::payMongoMode($fixtureEnv) === 'test', 'Missing mode must default to test.');
    modeReject(fn() => PaymentHelper::normalizePayMongoMode('production'), 'Invalid mode accepted.');
    modeReject(fn() => PaymentHelper::normalizePayMongoMode(''), 'Empty mode accepted.');
    foreach (['test', 'live'] as $mode) {
        putenv('PAYMONGO_MODE=' . $mode);
        $live = $mode === 'live';
        $opposite = $live ? 'test' : 'live';
        $service = new PayMongoService('sk_' . $mode . '_fixture', 'pk_' . $mode . '_fixture', webhookSecret: 'whsk_fixture', mode: $mode);
        modeAssert($service->isConfigured() && $service->isLiveMode() === $live, 'Valid key pair rejected.');
        modeReject(fn() => new PayMongoService('sk_' . $opposite . '_fixture', 'pk_' . $mode . '_fixture', mode: $mode), 'Cross-mode secret accepted.');
        modeReject(fn() => new PayMongoService('sk_' . $mode . '_fixture', 'pk_' . $opposite . '_fixture', mode: $mode), 'Mixed key pair accepted.');
        modeReject(fn() => new PayMongoService('sk_' . $mode . '_', 'pk_' . $mode . '_fixture', mode: $mode), 'Empty key suffix accepted.');
        modeAssert(!(new PayMongoService('', '', mode: $mode))->isConfigured(), 'Missing keys configured.');
        PaymentHelper::assertPayMongoLivemode($live);
        foreach ([$opposite === 'live', null, 'false', 'true', 0, 1] as $badMode) {
            modeReject(fn() => PaymentHelper::assertPayMongoLivemode($badMode), 'Nonmatching or untyped mode accepted.');
        }
        modeReject(fn() => PaymentHelper::assertPayMongoResourceMode(['attributes' => ['livemode' => $live, 'payments' => [['attributes' => ['livemode' => !$live]]]]]), 'Nested cross-mode payment accepted.');
        modeReject(fn() => PaymentHelper::assertPayMongoTransactionMode(['paymongo_mode' => $opposite]), 'Wrong local transaction mode accepted.');
        modeReject(fn() => PaymentHelper::assertPayMongoTransactionMode('{invalid'), 'Malformed metadata accepted.');
        if ($live) modeReject(fn() => PaymentHelper::assertPayMongoTransactionMode(null), 'Legacy test transaction accepted in live mode.');
        else PaymentHelper::assertPayMongoTransactionMode(null);

        $body = json_encode(['data' => ['attributes' => ['type' => 'payment.failed', 'livemode' => $live]]]);
        $now = time();
        $signature = hash_hmac('sha256', $now . '.' . $body, 'whsk_fixture');
        $slot = $live ? 'li' : 'te';
        $otherSlot = $live ? 'te' : 'li';
        $header = "t={$now},{$slot}={$signature},{$otherSlot}=";
        modeAssert($service->verifyWebhook($body, $header), 'Matching signature rejected.');
        modeAssert(!$service->verifyWebhook($body . ' ', $header), 'Tampered raw body accepted.');
        modeAssert(!$service->verifyWebhook($body, "t={$now},{$otherSlot}={$signature}"), 'Wrong signature slot accepted.');
        modeAssert(!$service->verifyWebhook($body, ''), 'Missing signature accepted.');
        modeAssert(!$service->verifyWebhook($body, $header . ",t={$now}"), 'Ambiguous duplicate timestamp accepted.');
        modeAssert(!PaymentHelper::verifyPayMongoSignature($body, $header, 'whsk_other', $live), 'Wrong signing secret accepted.');
        modeAssert(!PaymentHelper::verifyPayMongoSignature($body, $header, 'whsk_fixture', $live, 300, $now + 301), 'Expired signature accepted.');
        modeAssert(!PaymentHelper::verifyPayMongoSignature($body, $header, 'whsk_fixture', $live, 300, $now - 301), 'Future signature accepted.');
        if ($live) modeAssert(!$service->verifyTestWebhook($body, "t={$now},te={$signature}"), 'Legacy method accepted test signature on live service.');

        foreach (['package', 'boat', 'tourguide', 'hotel'] as $domain) {
            $pdo = modeDatabase();
            $payload = [
                'booking_date' => '2026-11-01', 'location' => 'Fixture', 'package_name' => 'Fixture',
                'phone_number' => '', 'operator_id' => 1, 'tour_type' => 'same-day', 'tour_range' => '',
                'jump_off_port' => '', 'preferred_resource' => '', 'boat_id' => 1, 'guide_id' => 1,
                'num_adults' => 1, 'num_children' => 0, 'hotel_resort_id' => 1, 'hotel_room_id' => 1,
                'room_type' => 'Fixture', 'checkin_date' => '2026-11-01', 'checkout_date' => '2026-11-02',
                'nights' => 1, 'adults' => 1, 'children' => 0, 'first_name' => 'Fixture', 'last_name' => 'Guest',
                'email' => 'fixture@example.test', 'special_request' => '', 'unit_price' => 5000,
            ];
            $pdo->prepare("INSERT INTO booking_checkout_drafts (booking_draft_id,tourist_id,booking_domain,payload,total_minor,amount_minor,payment_type,status) VALUES (1,7,?,?,500000,100000,'partial','pending')")
                ->execute([$domain, json_encode($payload)]);
            $checkout = modeTransaction($pdo, $mode, $domain, -1, 100000, ['source' => 'booking_checkout', 'booking_draft_id' => '1']);
            $result = PaymentReconciler::reconcilePaidCheckout($pdo, $checkout, 'evt_initial');
            $id = $result['booking_id'];
            $table = $domain === 'hotel' ? 'hotel_room_bookings' : 'bookings';
            $idColumn = $domain === 'hotel' ? 'hotel_booking_id' : 'booking_id';
            $paidColumn = $domain === 'hotel' ? 'amount_paid' : 'payment_amount';
            $row = $pdo->query("SELECT * FROM {$table} WHERE {$idColumn}={$id}")->fetch(PDO::FETCH_ASSOC);
            modeAssert((float)$row[$paidColumn] === 1000.0 && (float)$row['remaining_balance'] === 4000.0, "$mode $domain downpayment calculation changed.");
            $logs = (int)$pdo->query('SELECT COUNT(*) FROM admin_activity_logs')->fetchColumn();
            $duplicate = PaymentReconciler::reconcilePaidCheckout($pdo, $checkout, 'evt_retry');
            modeAssert($duplicate['idempotent'] && (int)$pdo->query("SELECT COUNT(*) FROM {$table}")->fetchColumn() === 1, 'Duplicate created a second booking.');
            modeAssert((int)$pdo->query('SELECT COUNT(*) FROM admin_activity_logs')->fetchColumn() === $logs, 'Duplicate created activity/notification side effects.');
            $changedPayment = $checkout;
            $changedPayment['attributes']['payments'][0]['id'] = 'pay_different';
            modeReject(fn() => PaymentReconciler::reconcilePaidCheckout($pdo, $changedPayment), 'Already paid session accepted a different payment.');

            $balance = modeTransaction($pdo, $mode, $domain, $id, 400000);
            $result = PaymentReconciler::reconcilePaidCheckout($pdo, $balance, 'evt_balance');
            $row = $pdo->query("SELECT * FROM {$table} WHERE {$idColumn}={$id}")->fetch(PDO::FETCH_ASSOC);
            modeAssert((float)$row[$paidColumn] === 5000.0 && (float)$row['remaining_balance'] === 0.0, 'Balance payment did not accumulate correctly.');
            modeAssert($domain === 'hotel' ? $row['payment_status'] === 'paid' : (int)$row['is_paid'] === 1, 'Final payment status incorrect.');
            modeAssert((int)$pdo->query("SELECT COUNT(*) FROM payment_transactions WHERE status='paid'")->fetchColumn() === 2, 'Second payment overwrote a previous transaction.');
            modeAssert(PaymentReconciler::reconcilePaidCheckout($pdo, $balance)['idempotent'], 'Duplicate balance credited twice.');
            putenv('PAYMONGO_MODE=' . $opposite);
            modeReject(fn() => PaymentHelper::assertPayMongoBookingMode($pdo, $domain, $id), 'Booking could mix test and live funding.');
            putenv('PAYMONGO_MODE=' . $mode);

            $pdo->prepare("INSERT INTO booking_checkout_drafts (booking_draft_id,tourist_id,booking_domain,payload,total_minor,amount_minor,payment_type,status) VALUES (2,7,?,?,500000,500000,'full','pending')")
                ->execute([$domain, json_encode($payload)]);
            $full = modeTransaction($pdo, $mode, $domain, -2, 500000, ['source' => 'booking_checkout', 'booking_draft_id' => '2']);
            $fullResult = PaymentReconciler::reconcilePaidCheckout($pdo, $full);
            $fullRow = $pdo->query("SELECT * FROM {$table} WHERE {$idColumn}=" . $fullResult['booking_id'])->fetch(PDO::FETCH_ASSOC);
            modeAssert((float)$fullRow[$paidColumn] === 5000.0 && (float)$fullRow['remaining_balance'] === 0.0, 'Upfront full payment was not preserved.');
        }

        $pdo = modeDatabase();
        $pdo->exec("INSERT INTO bookings (booking_id,tourist_id,booking_type,payment_amount,remaining_balance,status,is_complete) VALUES (1,7,'boat',0,5000,'accepted','uncomplete')");
        $checkout = modeTransaction($pdo, $mode, 'boat', 1, 500000, ['source' => 'admin_booking_payment', 'complete_after_payment' => true, 'admin_id' => 1]);
        foreach (['wrong_amount', 'fractional_amount', 'wrong_currency', 'wrong_reference', 'wrong_session', 'wrong_mode', 'wrong_payment_mode', 'failed', 'expired', 'missing_mode'] as $case) {
            $bad = $checkout;
            switch ($case) {
                case 'wrong_amount': $bad['attributes']['payments'][0]['attributes']['amount'] = 100; break;
                case 'fractional_amount': $bad['attributes']['payments'][0]['attributes']['amount'] = 500000.5; break;
                case 'wrong_currency': $bad['attributes']['payments'][0]['attributes']['currency'] = 'USD'; break;
                case 'wrong_reference': $bad['attributes']['reference_number'] = 'WRONG'; break;
                case 'wrong_session': $bad['id'] = 'cs_other'; break;
                case 'wrong_mode': $bad['attributes']['livemode'] = !$live; break;
                case 'wrong_payment_mode': $bad['attributes']['payments'][0]['attributes']['livemode'] = !$live; break;
                case 'failed': $bad['attributes']['payments'][0]['attributes']['status'] = 'failed'; break;
                case 'expired': $bad['attributes']['status'] = 'expired'; $bad['attributes']['payments'] = []; break;
                case 'missing_mode': unset($bad['attributes']['payments'][0]['attributes']['livemode']); break;
            }
            modeReject(fn() => PaymentReconciler::reconcilePaidCheckout($pdo, $bad), "$mode $case accepted.");
            modeAssert(!$pdo->inTransaction() && (float)$pdo->query('SELECT payment_amount FROM bookings WHERE booking_id=1')->fetchColumn() === 0.0, "$case partially updated booking.");
        }
        $pdo->exec("UPDATE payment_transactions SET metadata='{}'");
        if ($live) modeReject(fn() => PaymentReconciler::reconcilePaidCheckout($pdo, $checkout), 'Live provider payment modified a legacy test row.');
        $pdo->prepare('UPDATE payment_transactions SET metadata=?')->execute([json_encode(['paymongo_mode' => $opposite])]);
        modeReject(fn() => PaymentReconciler::reconcilePaidCheckout($pdo, $checkout), 'Cross-mode local transaction credited.');
        $pdo->prepare('UPDATE payment_transactions SET metadata=?')->execute([json_encode(['paymongo_mode' => $mode, 'source' => 'admin_booking_payment', 'complete_after_payment' => true, 'admin_id' => 1])]);
        $result = PaymentReconciler::reconcilePaidCheckout($pdo, $checkout);
        modeAssert($pdo->query('SELECT is_complete FROM bookings')->fetchColumn() === 'completed', 'Staff completion behavior changed.');
        $transactionId = $result['transaction_id'];
        PaymentHelper::linkPayMongoCheckout($pdo, $transactionId, 7, $checkout['id'], 'https://checkout.paymongo.com/fixture');
        modeAssert($pdo->query('SELECT status FROM payment_transactions')->fetchColumn() === 'paid', 'Late creation response downgraded paid transaction.');
        modeAssert(PaymentReconciler::reconcilePaidCheckout($pdo, $checkout, 'evt_afterCallback')['idempotent'], 'Late creation response enabled double credit.');
        modeAssert($pdo->query('SELECT provider_event_id FROM payment_transactions')->fetchColumn() === 'evt_afterCallback', 'Webhook ID not recorded after API fallback credited first.');
        modeReject(fn() => PaymentHelper::linkPayMongoCheckout($pdo, $transactionId, 7, 'cs_other', 'https://checkout.paymongo.com/other'), 'Checkout link overwrote bound provider ID.');

        $pdo->exec("INSERT INTO bookings (booking_id,tourist_id,booking_type,payment_amount,remaining_balance,status,is_complete) VALUES (2,7,'boat',0,5000,'accepted','uncomplete')");
        $reusedPayment = modeTransaction($pdo, $mode, 'boat', 2, 500000);
        $reusedPayment['attributes']['payments'][0]['id'] = $checkout['attributes']['payments'][0]['id'];
        try {
            PaymentReconciler::reconcilePaidCheckout($pdo, $reusedPayment);
            throw new RuntimeException('Duplicate Payment ID credited another transaction.');
        } catch (PDOException $exception) {
            modeAssert((string)$exception->getCode() === '23000', 'Expected unique payment constraint failure.');
            modeAssert(!$pdo->inTransaction() && (float)$pdo->query('SELECT payment_amount FROM bookings WHERE booking_id=2')->fetchColumn() === 0.0, 'Unique constraint failed to roll back booking credit.');
        }

        $pdo->exec("INSERT INTO hotel_room_bookings (hotel_booking_id,tourist_id,total_amount,amount_paid,remaining_balance,checked_in_at) VALUES (1,7,5000,1000,4000,'2026-10-01')");
        $hotelCheckout = modeTransaction($pdo, $mode, 'hotel', 1, 425000, ['source' => 'hotel_checkout_payment', 'checkout_base_remaining' => '4000.00', 'checkout_additional_charges' => '250.00']);
        PaymentReconciler::reconcilePaidCheckout($pdo, $hotelCheckout);
        $hotel = $pdo->query('SELECT * FROM hotel_room_bookings')->fetch(PDO::FETCH_ASSOC);
        modeAssert((float)$hotel['amount_paid'] === 5250.0 && (float)$hotel['total_amount'] === 5250.0 && $hotel['payment_status'] === 'paid', 'Hotel checkout charges changed.');

        $pdo->exec("INSERT INTO booking_cancellation_requests (cancellation_request_id,refundable_amount,refund_status) VALUES (1,5000,'processing')");
        $paymentId = $checkout['attributes']['payments'][0]['id'];
        $pdo->prepare("INSERT INTO booking_refunds (booking_refund_id,payment_transaction_id,cancellation_request_id,provider,provider_refund_id,provider_payment_id,amount_minor,currency,status,provider_response) VALUES (1,?,1,'paymongo','ref_fixture',?,500000,'PHP','processing',?)")
            ->execute([$transactionId, $paymentId, json_encode(['data' => ['attributes' => ['transfer_link' => 'https://example.test/claim']]])]);
        $refund = ['id' => 'ref_fixture', 'payment_id' => $paymentId, 'amount_minor' => 500000, 'currency' => 'PHP', 'status' => 'succeeded', 'livemode' => $live];
        modeReject(fn() => RefundWebhookReconciler::reconcile($pdo, array_replace($refund, ['livemode' => !$live])), 'Cross-mode refund accepted.');
        modeReject(fn() => RefundWebhookReconciler::reconcile($pdo, array_replace($refund, ['amount_minor' => 1])), 'Wrong refund amount accepted.');
        modeAssert(RefundWebhookReconciler::reconcile($pdo, $refund)['updated'], 'Valid refund was not applied.');
        modeAssert(RefundWebhookReconciler::reconcile($pdo, $refund)['idempotent'], 'Duplicate refund reapplied.');
        modeAssert(RefundWebhookReconciler::reconcile($pdo, array_replace($refund, ['status' => 'pending']))['idempotent'], 'Late refund event downgraded success.');
        modeAssert($pdo->query('SELECT refund_status FROM booking_cancellation_requests')->fetchColumn() === 'completed', 'Refund aggregate not completed.');
        modeAssert(str_contains((string)$pdo->query('SELECT provider_response FROM booking_refunds')->fetchColumn(), 'https://example.test/claim'), 'Refund claim link lost.');
        modeAssert(bookingRefundRecordWebhookEvent($pdo, 'evt_refund', 'refund.succeeded', '{}'), 'Refund event not saved.');
        modeAssert(!bookingRefundRecordWebhookEvent($pdo, 'evt_refund', 'refund.succeeded', '{}') && bookingRefundWebhookEventProcessed($pdo, 'evt_refund'), 'Refund event deduplication failed.');
    }
    // Configuration loaded from an explicit fixture file, never the private environment.
    putenv('PAYMONGO_MODE=live');
    file_put_contents($fixtureEnv, "PAYMONGO_SECRET_KEY=sk_live_fixture\nPAYMONGO_PUBLIC_KEY=pk_live_fixture\nPAYMONGO_WEBHOOK_SECRET=whsk_fixture\n");
    // Use a new path because environment files are cached for the duration of a request.
    $configuredEnv = tempnam(sys_get_temp_dir(), 'itour_mode_config_');
    copy($fixtureEnv, $configuredEnv);
    modeAssert(PayMongoService::fromEnvironment($configuredEnv)->isLiveMode(), 'fromEnvironment lost configured mode.');
    $diagnostic = PaymentHelper::sanitizePayMongoDiagnostic(['detail' => 'Rejected sk_live_fixture whsk_fixture', 'authorization' => 'Basic fixture', 'account_number' => '1234567890']);
    modeAssert(!str_contains(json_encode($diagnostic), 'fixture') && !str_contains(json_encode($diagnostic), '1234567890'), 'Diagnostic leaked a secret or account number.');
    $exception = new PayMongoException('Rejected sk_live_fixture', 400, ['secret' => 'fixture']);
    modeAssert(!str_contains($exception->getMessage(), 'sk_live_') && $exception->getResponse()['secret'] === '[REDACTED]', 'Exception exposed credentials.');
    modeAssert(str_contains((string)file_get_contents($fixtureLog), 'payment_duplicate'), 'Duplicate diagnostics missing.');
    $originalEncryption = [];
    foreach (['REFUND_DESTINATION_ENCRYPTION_KEY', 'REFUND_LEGACY_DESTINATION_ENCRYPTION_KEY', 'PAYMONGO_SECRET_KEY'] as $variable) {
        $originalEncryption[$variable] = getenv($variable);
    }
    try {
        putenv('REFUND_DESTINATION_ENCRYPTION_KEY=fixture_dedicated_material');
        putenv('REFUND_LEGACY_DESTINATION_ENCRYPTION_KEY=fixture_previous_material');
        putenv('PAYMONGO_SECRET_KEY=sk_live_fixture_rotated');
        $iv = random_bytes(12);
        $tag = '';
        $legacyCipher = openssl_encrypt('fixture-account', 'aes-256-gcm', hash_hkdf('sha256', 'fixture_previous_material', 32, 'itour-mercedes-refund-destination-v1'), OPENSSL_RAW_DATA, $iv, $tag);
        modeAssert(bookingRefundDecryptAccountNumber(base64_encode($iv . $tag . $legacyCipher)) === 'fixture-account', 'Key rotation broke legacy refund destination decryption.');
        modeAssert(bookingRefundDecryptAccountNumber(bookingRefundEncryptAccountNumber('fixture-new-account')) === 'fixture-new-account', 'Dedicated destination encryption regressed.');
    } finally {
        foreach ($originalEncryption as $variable => $value) putenv($value === false ? $variable : $variable . '=' . $value);
    }
    echo "PayMongo mode fixtures passed: {$checks} checks (offline SQLite; no API calls or application DB access).\n";
} finally {
    if ($originalMode === false) putenv('PAYMONGO_MODE'); else putenv('PAYMONGO_MODE=' . $originalMode);
    ini_set('error_log', (string)$originalLog);
    foreach ([$fixtureEnv, $fixtureLog, $configuredEnv ?? null] as $temporaryFile) {
        if (is_string($temporaryFile) && is_file($temporaryFile)) unlink($temporaryFile);
    }
}
