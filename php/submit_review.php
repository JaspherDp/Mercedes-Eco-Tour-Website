<?php
require_once __DIR__ . '/session_security.php';
AppSessionStart();
require_once 'db_connection.php';
require_once __DIR__ . '/tourist_auth_helper.php';
require_once __DIR__ . '/activity_logger.php';
require_once __DIR__ . '/input_validation.php';

header('Content-Type: application/json');

try {

    $tourist = TouristRequireLogin($pdo, 'json');
    $tourist_id = (int)$tourist['tourist_id'];
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        throw new RuntimeException('Method not allowed.');
    }
    if (!AppVerifyCsrf('tourist', 'engagement', $_POST['csrf_token'] ?? null)) {
        http_response_code(403);
        throw new RuntimeException('Invalid security token. Refresh the page and try again.');
    }

    $service_id = ItourValidationInt($_POST['booking_id'] ?? null, 'Service', 1, PHP_INT_MAX);
    $type = strtolower(ItourValidationText($_POST['type'] ?? null, 'Service type', 20, true));
    if (!in_array($type, ['package', 'boat', 'tourguide'], true)) {
        throw new InvalidArgumentException('Invalid service type.');
    }
    $rating = ItourValidationInt($_POST['rating'] ?? null, 'Rating', 1, 5);
    $comment = ItourValidationText($_POST['comment'] ?? null, 'Comment', 5000, true);

    if ($type === 'package') {
        $eligibleSql = "SELECT 1 FROM tour_packages p JOIN bookings b
            ON b.package_name = p.package_title AND b.operator_id = p.operator_id AND b.tourist_id = ?
            AND LOWER(b.booking_type) = 'package' AND LOWER(b.is_complete) = 'completed'
            WHERE p.package_id = ? LIMIT 1";
    } elseif ($type === 'boat') {
        $eligibleSql = "SELECT 1 FROM boats s JOIN bookings b
            ON b.boat_id = s.boat_id AND b.tourist_id = ?
            AND LOWER(b.booking_type) = 'boat' AND LOWER(b.is_complete) = 'completed'
            WHERE s.boat_id = ? LIMIT 1";
    } else {
        $eligibleSql = "SELECT 1 FROM tour_guides s JOIN bookings b
            ON b.guide_id = s.guide_id AND b.tourist_id = ?
            AND LOWER(b.booking_type) = 'tourguide' AND LOWER(b.is_complete) = 'completed'
            WHERE s.guide_id = ? LIMIT 1";
    }
    $eligible = $pdo->prepare($eligibleSql);
    $eligible->execute([$tourist_id, $service_id]);
    if (!$eligible->fetchColumn()) {
        throw new InvalidArgumentException('Only a service from your completed booking can be reviewed.');
    }
    $duplicate = $pdo->prepare('SELECT 1 FROM feedback WHERE tourist_id = ? AND service_type = ? AND service_id = ? LIMIT 1');
    $duplicate->execute([$tourist_id, $type, $service_id]);
    if ($duplicate->fetchColumn()) {
        throw new InvalidArgumentException('You already reviewed this service.');
    }

    // =========================
    // MAP TO YOUR REAL COLUMNS
    // =========================
    $package_id = null;
    $boat_id = null;
    $tourguide_id = null;

    if ($type === 'package') {
        $package_id = $service_id;
    } elseif ($type === 'boat') {
        $boat_id = $service_id;
    } elseif ($type === 'tourguide') {
        $tourguide_id = $service_id;
    }

    // =========================
    // INSERT (MATCH TABLE STRUCTURE)
    // =========================
    $stmt = $pdo->prepare("
        INSERT INTO feedback (
            tourist_id,
            package_id,
            boat_id,
            tourguide_id,
            rating,
            comment,
            booking_type,
            created_at,
            service_type,
            service_id
        )
        VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), ?, ?)
    ");

    $stmt->execute([
        $tourist_id,
        $package_id,
        $boat_id,
        $tourguide_id,
        $rating,
        $comment,
        $type,
        $type,
        $service_id
    ]);
    logActivity(
        $pdo, 'Tourist', $tourist_id,
        (string)($_SESSION['full_name'] ?? $_SESSION['tourist_email'] ?? 'Tourist'),
        'Review Submitted',
        'Submitted a review for ' . $type . ' #' . $service_id . '.',
        'Reviews', (int)$pdo->lastInsertId()
    );

    echo json_encode([
        'success' => true,
        'message' => 'Review submitted successfully'
    ]);

} catch (Throwable $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
