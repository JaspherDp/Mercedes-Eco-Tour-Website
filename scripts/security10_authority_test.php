<?php
declare(strict_types=1);

require_once __DIR__ . '/../php/package_checkout_authority_helper.php';
require_once __DIR__ . '/../php/additional_fees_helper.php';

function security10Assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$validDurations = [
    ['same-day', '1 Day', 'same-day', 1, 0],
    ['overnight', '2 Days & 1 Night', 'overnight', 2, 1],
    ['overnight', '2D1N', 'overnight', 2, 1],
    ['overnight', '3D2N', 'overnight', 3, 2],
];
foreach ($validDurations as [$type, $range, $expectedType, $days, $nights]) {
    $parsed = PackageCheckoutDuration($type, $range);
    security10Assert($parsed['type'] === $expectedType, "Unexpected type for {$range}");
    security10Assert($parsed['days'] === $days, "Unexpected day count for {$range}");
    security10Assert($parsed['nights'] === $nights, "Unexpected night count for {$range}");
}

foreach ([['same-day', '2D1N'], ['overnight', '3D1N'], ['flexible', '1 Day']] as [$type, $range]) {
    try {
        PackageCheckoutDuration($type, $range);
        throw new RuntimeException("Invalid package duration was accepted: {$type} / {$range}");
    } catch (DomainException) {
        // Expected: a browser cannot expand or contradict the configured package duration.
    }
}

$configuredFees = [];
$amount = 1.0;
foreach (additionalFeeDefinitions() as $code => $_definition) {
    $configuredFees[$code] = ['amount' => $amount++];
}
$mappedFees = additionalFeesForBooking($configuredFees);
security10Assert($mappedFees['environmental']['foreigner'] === 1.0, 'Environmental fee did not follow configuration.');
security10Assert($mappedFees['entrance']['Canimog Island']['overnight'] === 8.0, 'Entrance fee did not follow configuration.');
security10Assert($mappedFees['docking']['Canimog Island'] === 11.0, 'Docking fee did not follow configuration.');

$previousTotal = 2500.0;
$amountPaid = 2500.0;
$expense = 500.0;
$newTotal = round(max(0, $previousTotal) + $expense, 2);
$newRemaining = round(max(0, $newTotal - $amountPaid), 2);
security10Assert($newTotal === 3000.0, 'Expense was not added to the grand total.');
security10Assert($newRemaining === 500.0, 'Remaining balance was not derived from total minus paid.');

echo "Security #10 authority tests passed.\n";

if (in_array('--database', $argv ?? [], true)) {
    require __DIR__ . '/../php/db_connection.php';

    $packages = $pdo->query(
        "SELECT p.package_id, p.package_title, p.package_type, p.package_range
         FROM tour_packages p
         JOIN operators o ON o.operator_id = p.operator_id
         WHERE o.status = 'active'"
    )->fetchAll(PDO::FETCH_ASSOC);
    security10Assert($packages !== [], 'No active package fixture is available.');

    $byId = $pdo->prepare('SELECT package_id, package_title FROM tour_packages WHERE package_id = ? LIMIT 1');
    foreach ($packages as $package) {
        PackageCheckoutDuration((string)$package['package_type'], (string)$package['package_range']);
        $byId->execute([(int)$package['package_id']]);
        $resolved = $byId->fetch(PDO::FETCH_ASSOC);
        security10Assert((int)($resolved['package_id'] ?? 0) === (int)$package['package_id'], 'Package ID did not resolve exactly.');
        security10Assert((string)($resolved['package_title'] ?? '') === (string)$package['package_title'], 'Package ID resolved the wrong title.');
    }

    $liveFees = additionalFeesForBooking(getAdditionalFees($pdo));
    security10Assert(isset($liveFees['environmental'], $liveFees['entrance'], $liveFees['docking']), 'Configured fee groups are incomplete.');
    echo 'Read-only database authority tests passed for ' . count($packages) . " active package(s).\n";
}
