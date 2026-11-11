<?php
declare(strict_types=1);
require_once __DIR__ . '/booking_tourists_helper.php';

/** Boat 1 is the primary selection; remaining selections use stable ID order. */
function passengerManifestBoatNames(PDO $pdo, array $booking): array
{
    $ids = [];
    $primary = (int)($booking['boat_id'] ?? 0);
    if ($primary > 0) $ids[] = $primary;
    try {
        $stmt = $pdo->prepare('SELECT boat_id FROM booking_resource_boats WHERE booking_id=? ORDER BY boat_id');
        $stmt->execute([(int)$booking['booking_id']]);
        $ids = array_values(array_unique(array_merge($ids, array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)))));
    } catch (PDOException $error) {
        // Older bookings/installations may only have a primary boat.
        if ((string)$error->getCode() !== '42S02' && !str_contains($error->getMessage(), 'no such table')) throw $error;
    }
    $names = [];
    $stmt = $pdo->prepare('SELECT name FROM boats WHERE boat_id=?');
    foreach ($ids as $index => $id) {
        $stmt->execute([$id]);
        $names[$index + 1] = trim((string)($stmt->fetchColumn() ?: ''));
    }
    return $names;
}

/** Preserve submitted boat assignments; legacy lists use groups of eight. */
function passengerManifestPages(array $tourists, array $booking, array $boatNames): array
{
    $groups = [];
    if (strtolower((string)($booking['booking_type'] ?? '')) === 'boat') {
        $groups = bookingTouristBoatManifests($tourists);
    } else {
        foreach (array_chunk($tourists, 8) as $index => $passengers) {
            $groups[$index + 1] = $passengers;
        }
    }
    // Historical data must never put more than eight passengers on a vessel.
    foreach ($groups as $group) {
        if (count($group) > 8) {
            $chunks = array_chunk($tourists, 8);
            $groups = array_combine(range(1, count($chunks)), $chunks);
            break;
        }
    }
    if (!$groups) $groups = [1 => []];
    $pages = [];
    foreach ($groups as $number => $passengers) {
        $pages[] = ['boat_name' => $boatNames[$number] ?? '', 'passengers' => $passengers];
    }
    return $pages;
}
