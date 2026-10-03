<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

// Reuse the existing isolated SQLite schemas and real reconciliation fixtures.
require __DIR__ . '/paymongo_mode_fixture_test.php';
require_once __DIR__ . '/../payments/PaymentReturnStatus.php';
$returnChecksStart = $checks;
$savedMode = getenv('PAYMONGO_MODE');
$returnLog = tempnam(sys_get_temp_dir(), 'itour_return_test_');
$savedLog = ini_set('error_log', $returnLog);

function returnFixture(string $domain, string $source = 'tourist_profile_balance'): array
{
    $pdo = modeDatabase();
    $pdo->exec('ALTER TABLE payment_transactions ADD COLUMN return_token TEXT');
    $pdo->exec('CREATE UNIQUE INDEX fixture_return_token ON payment_transactions(return_token)');
    if ($domain === 'hotel') {
        $pdo->exec("INSERT INTO hotel_room_bookings (hotel_booking_id,tourist_id,total_amount,amount_paid,remaining_balance) VALUES (1,7,5000,1000,4000)");
    } else {
        $pdo->exec("INSERT INTO bookings (booking_id,tourist_id,grand_total,payment_amount,remaining_balance,is_complete) VALUES (1,7,5000,1000,4000,'uncomplete')");
        $pdo->prepare('UPDATE bookings SET booking_type=?')->execute([$domain]);
    }
    $resource = modeTransaction($pdo, 'test', $domain, 1, 400000, ['source' => $source]);
    $token = bin2hex(random_bytes(32));
    $pdo->prepare('UPDATE payment_transactions SET return_token=?')->execute([$token]);
    return [$pdo, $token, $resource];
}

try {
    putenv('PAYMONGO_MODE=test');
    foreach (['hotel', 'package', 'boat', 'tourguide'] as $domain) {
        [$pdo, $token, $resource] = returnFixture($domain);
        $unpaid = $resource;
        $unpaid['attributes']['payments'] = [];
        $unpaid['attributes']['payment_status'] = 'unpaid';
        $pending = PaymentReturnStatus::resolve($pdo, $token, fn() => ['data' => $unpaid]);
        modeAssert($pending['status'] === 'pending', "$domain unpaid back navigation became paid.");
        $paid = PaymentReturnStatus::resolve($pdo, $token, fn() => ['data' => $resource]);
        modeAssert($paid['status'] === 'paid', "$domain delayed webhook API fallback did not resolve paid.");
        $table = $domain === 'hotel' ? 'hotel_room_bookings' : 'bookings';
        $column = $domain === 'hotel' ? 'amount_paid' : 'payment_amount';
        $before = $pdo->query("SELECT $column, remaining_balance FROM $table")->fetch(PDO::FETCH_ASSOC);
        $logsBefore = (int)$pdo->query('SELECT COUNT(*) FROM admin_activity_logs')->fetchColumn();
        $calls = 0;
        $noApi = function () use (&$calls) { ++$calls; throw new RuntimeException('Paid must not call API.'); };
        foreach (['merchant', 'chevron', 'refresh', 'history', 'revisit'] as $navigation) {
            modeAssert(PaymentReturnStatus::resolve($pdo, $token, $noApi)['status'] === 'paid', "$domain $navigation lost success.");
        }
        modeAssert($calls === 0, 'Already-paid return called the API.');
        PaymentReconciler::reconcilePaidCheckout($pdo, $resource, 'evt_return_duplicate');
        PaymentReconciler::reconcilePaidCheckout($pdo, $resource, 'evt_return_duplicate');
        $after = $pdo->query("SELECT $column, remaining_balance FROM $table")->fetch(PDO::FETCH_ASSOC);
        modeAssert($before === $after && (float)$after[$column] === 5000.0 && (float)$after['remaining_balance'] === 0.0, 'Return/duplicate webhook double credited balance.');
        modeAssert((int)$pdo->query('SELECT COUNT(*) FROM payment_transactions')->fetchColumn() === 1, 'Return inserted another transaction.');
        modeAssert((int)$pdo->query('SELECT COUNT(*) FROM admin_activity_logs')->fetchColumn() === $logsBefore, 'Return/duplicate repeated fulfillment log.');
    }

    foreach (['failed', 'expired', 'cancelled'] as $status) {
        [$pdo, $token] = returnFixture('boat');
        $pdo->prepare('UPDATE payment_transactions SET status=?')->execute([$status]);
        modeAssert(PaymentReturnStatus::resolve($pdo, $token, fn() => throw new RuntimeException('Unexpected API call'))['status'] === $status, 'Terminal failure lost its status.');
    }
    [$pdo, $token, $resource] = returnFixture('package');
    $race = PaymentReturnStatus::resolve($pdo, $token, function () use ($pdo, $resource) {
        // Simulate a webhook completing while browser API retrieval fails.
        PaymentReconciler::reconcilePaidCheckout($pdo, $resource, 'evt_race');
        throw new RuntimeException('Simulated provider timeout');
    });
    modeAssert($race['status'] === 'paid', 'API error hid concurrent webhook success.');

    foreach (['amount', 'currency', 'mode', 'session', 'paid_flag_only', 'api_error'] as $case) {
        [$pdo, $token, $resource] = returnFixture('boat');
        if ($case === 'amount') $resource['attributes']['payments'][0]['attributes']['amount']++;
        if ($case === 'currency') $resource['attributes']['payments'][0]['attributes']['currency'] = 'USD';
        if ($case === 'mode') $resource['attributes']['livemode'] = true;
        if ($case === 'session') $resource['id'] = 'cs_other';
        if ($case === 'paid_flag_only') {
            $resource['attributes']['payment_status'] = 'paid';
            $resource['attributes']['payments'] = [];
        }
        $result = PaymentReturnStatus::resolve($pdo, $token, function () use ($case, $resource) {
            if ($case === 'api_error') throw new RuntimeException('Simulated timeout');
            return ['data' => $resource];
        });
        modeAssert($result['status'] === 'pending', "$case was accepted as paid.");
        modeAssert((float)$pdo->query('SELECT payment_amount FROM bookings')->fetchColumn() === 1000.0, "$case changed booking credit.");
    }
    modeAssert(PaymentReturnStatus::resolve($pdo, 'invalid', fn() => []) === [], 'Invalid token accepted.');
    modeAssert(PaymentReturnStatus::resolve($pdo, str_repeat('f', 64), fn() => []) === [], 'Unknown token accepted.');
    $pdo->exec("UPDATE payment_transactions SET provider='cash'");
    modeAssert(PaymentReturnStatus::resolve($pdo, $token, fn() => []) === [], 'Non-PayMongo transaction accepted.');

    // New initial bookings: all domains, both downpayment and full payment.
    foreach (['hotel', 'package', 'boat', 'tourguide'] as $domain) {
        foreach (['partial' => 100000, 'full' => 500000] as $kind => $amount) {
            $pdo = modeDatabase();
            $pdo->exec('ALTER TABLE payment_transactions ADD COLUMN return_token TEXT');
            $pdo->exec('CREATE UNIQUE INDEX fixture_return_token ON payment_transactions(return_token)');
            $draft = [
                'booking_date' => '2026-11-01', 'location' => 'Fixture', 'package_name' => 'Fixture',
                'phone_number' => '', 'operator_id' => 1, 'tour_type' => 'same-day', 'tour_range' => '',
                'jump_off_port' => '', 'preferred_resource' => '', 'boat_id' => 1, 'guide_id' => 1,
                'num_adults' => 1, 'num_children' => 0, 'hotel_resort_id' => 1, 'hotel_room_id' => 1,
                'room_type' => 'Fixture', 'checkin_date' => '2026-11-01', 'checkout_date' => '2026-11-02',
                'nights' => 1, 'adults' => 1, 'children' => 0, 'first_name' => 'Fixture', 'last_name' => 'Guest',
                'email' => 'fixture@example.test', 'special_request' => '', 'unit_price' => 5000,
            ];
            $pdo->prepare("INSERT INTO booking_checkout_drafts (booking_draft_id,tourist_id,booking_domain,payload,total_minor,amount_minor,payment_type,status) VALUES (1,7,?,?,500000,?,?,'pending')")
                ->execute([$domain, json_encode($draft), $amount, $kind]);
            $resource = modeTransaction($pdo, 'test', $domain, -1, $amount, ['source' => 'booking_checkout', 'booking_draft_id' => '1']);
            $token = bin2hex(random_bytes(32));
            $pdo->prepare('UPDATE payment_transactions SET return_token=?')->execute([$token]);
            $result = PaymentReturnStatus::resolve($pdo, $token, fn() => ['data' => $resource]);
            modeAssert($result['status'] === 'paid' && (int)$result['booking_id'] > 0 && $result['booking_reference'] !== '', "$domain $kind missing confirmed booking.");
            PaymentReturnStatus::resolve($pdo, $token, fn() => throw new RuntimeException('Unexpected duplicate retrieval'));
            $table = $domain === 'hotel' ? 'hotel_room_bookings' : 'bookings';
            modeAssert((int)$pdo->query("SELECT COUNT(*) FROM $table")->fetchColumn() === 1, 'Return duplicated booking creation.');
        }
    }
    $count = $checks - $returnChecksStart;
    echo "PayMongo return fixtures passed: $count checks (mock API, isolated SQLite, no production access).\n";
} finally {
    putenv($savedMode === false ? 'PAYMONGO_MODE' : 'PAYMONGO_MODE=' . $savedMode);
    ini_set('error_log', (string)$savedLog);
    unlink($returnLog);
}
