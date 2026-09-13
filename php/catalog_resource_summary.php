<?php

function catalogResourceSummary(PDO $pdo, string $resourceType, int $total, int $occupied, int $available): array
{
    $isBoat = $resourceType === 'boat';
    $resourceColumn = $isBoat ? 'boat_id' : 'guide_id';
    $resourceTable = $isBoat ? 'boats' : 'tour_guides';
    $resourceName = $isBoat ? 'name' : 'fullname';
    $resourceId = $resourceColumn;

    $pendingStmt = $pdo->query("SELECT COUNT(*) FROM bookings WHERE {$resourceColumn} IS NOT NULL AND status = 'pending' AND is_complete = 'uncomplete' AND booking_date >= CURDATE()");
    $pending = (int)$pendingStmt->fetchColumn();

    $upcomingStmt = $pdo->query("SELECT COUNT(*) FROM bookings WHERE {$resourceColumn} IS NOT NULL AND status = 'accepted' AND is_complete = 'uncomplete' AND booking_date >= CURDATE()");
    $upcomingCount = (int)$upcomingStmt->fetchColumn();

    $scheduleStmt = $pdo->query("
        SELECT booking_date, COUNT(*) AS booking_count
        FROM bookings
        WHERE {$resourceColumn} IS NOT NULL
          AND status = 'accepted'
          AND is_complete = 'uncomplete'
          AND booking_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 6 DAY)
        GROUP BY booking_date
    ");
    $scheduleRows = $scheduleStmt->fetchAll(PDO::FETCH_KEY_PAIR);
    $schedule = [];
    for ($offset = 0; $offset < 7; $offset++) {
        $date = date('Y-m-d', strtotime("+{$offset} day"));
        $schedule[] = [
            'date' => $date,
            'label' => $offset === 0 ? 'Today' : date('D', strtotime($date)),
            'count' => (int)($scheduleRows[$date] ?? 0),
        ];
    }

    $listStmt = $pdo->query("
        SELECT b.booking_reference, b.booking_date, b.tour_type, b.location,
               b.package_name, b.pax, r.{$resourceName} AS resource_name
        FROM bookings b
        INNER JOIN {$resourceTable} r ON r.{$resourceId} = b.{$resourceColumn}
        WHERE b.{$resourceColumn} IS NOT NULL
          AND b.status = 'accepted'
          AND b.is_complete = 'uncomplete'
          AND b.booking_date >= CURDATE()
        ORDER BY b.booking_date ASC, b.booking_id ASC
        LIMIT 5
    ");

    return [
        'type' => $resourceType,
        'label' => $isBoat ? 'Boat' : 'Tour guide',
        'label_plural' => $isBoat ? 'boats' : 'tour guides',
        'total' => $total,
        'occupied' => $occupied,
        'available' => $available,
        'pending' => $pending,
        'upcoming_count' => $upcomingCount,
        'utilization' => $total > 0 ? (int)round(($occupied / $total) * 100) : 0,
        'schedule' => $schedule,
        'upcoming' => $listStmt->fetchAll(PDO::FETCH_ASSOC),
    ];
}

