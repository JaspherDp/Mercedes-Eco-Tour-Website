<?php
    require_once __DIR__ . '/session_security.php';
    AppSessionStart();
require_once 'db_connection.php';
require_once __DIR__ . '/activity_logger.php';
require_once __DIR__ . '/tourist_auth_helper.php';
require_once __DIR__ . '/input_validation.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $tourist = TouristRequireLogin($pdo, 'text');
    if (!AppVerifyCsrf('tourist', 'engagement', $_POST['csrf_token'] ?? null)) {
        http_response_code(403);
        exit('Invalid security token. Refresh the page and try again.');
    }

    try {
        $hotel_resort_id = ItourValidationInt($_POST['hotel_resort_id'] ?? null, 'Hotel', 1, PHP_INT_MAX);
        $hotel_booking_id = ItourValidationInt($_POST['hotel_booking_id'] ?? null, 'Booking', 1, PHP_INT_MAX);
        $rating = ItourValidationInt($_POST['rating'] ?? null, 'Overall rating', 1, 5);
        $value_rating = ItourValidationInt($_POST['value_rating'] ?? null, 'Value rating', 1, 5);
        $service_rating = ItourValidationInt($_POST['service_rating'] ?? null, 'Service rating', 1, 5);
        $cleanliness_rating = ItourValidationInt($_POST['cleanliness_rating'] ?? null, 'Cleanliness rating', 1, 5);
        $facilities_rating = ItourValidationInt($_POST['facilities_rating'] ?? null, 'Facilities rating', 1, 5);
        $room_comfort_rating = ItourValidationInt($_POST['room_comfort_rating'] ?? null, 'Room comfort rating', 1, 5);
        $review_message = ItourValidationText($_POST['review_message'] ?? null, 'Review message', 5000, true);
    } catch (InvalidArgumentException $exception) {
        http_response_code(422);
        exit($exception->getMessage());
    }
    $tourist_id = (int)$tourist['tourist_id'];

    if ($hotel_resort_id <= 0 || $hotel_booking_id <= 0 || $tourist_id <= 0) {
        exit("Invalid request.");
    }

    $eligible = $pdo->prepare("SELECT 1 FROM hotel_room_bookings
        WHERE hotel_booking_id = ? AND hotel_resort_id = ? AND tourist_id = ?
          AND LOWER(COALESCE(booking_status, '')) = 'completed' LIMIT 1");
    $eligible->execute([$hotel_booking_id, $hotel_resort_id, $tourist_id]);
    if (!$eligible->fetchColumn()) {
        http_response_code(422);
        exit('Only a completed booking for this hotel can be reviewed.');
    }

    // CHECK IF REVIEW ALREADY EXISTS (IMPORTANT FIX)
    $check = $pdo->prepare("
        SELECT review_id 
        FROM hotel_resort_reviews
        WHERE hotel_resort_id = ?
          AND hotel_booking_id = ?
          AND tourist_id = ?
        LIMIT 1
    ");
    $check->execute([$hotel_resort_id, $hotel_booking_id, $tourist_id]);

    if ($check->fetch()) {
        exit("You already submitted a review for this booking.");
    }

    // GET USER NAME
    $stmtTourist = $pdo->prepare("
        SELECT full_name
        FROM tourist
        WHERE tourist_id = ?
        LIMIT 1
    ");
    $stmtTourist->execute([$tourist_id]);
    $tourist = $stmtTourist->fetch(PDO::FETCH_ASSOC);

    $reviewer_name = $tourist['full_name'] ?? 'Guest';

    // INSERT REVIEW
    $stmt = $pdo->prepare("
        INSERT INTO hotel_resort_reviews (
            hotel_resort_id,
            hotel_booking_id,
            tourist_id,
            reviewer_name,
            rating,
            review_message,
            location_rating,
            service_rating,
            value_rating,
            cleanliness_rating,
            facilities_rating,
            room_comfort_rating,
            created_at
        ) VALUES (
            ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW()
        )
    ");

    $stmt->execute([
        $hotel_resort_id,
        $hotel_booking_id,
        $tourist_id,
        $reviewer_name,
        $rating,
        $review_message,
        $rating,
        $service_rating,
        $value_rating,
        $cleanliness_rating,
        $facilities_rating,
        $room_comfort_rating
    ]);
    logActivity(
        $pdo, 'Tourist', $tourist_id, (string)$reviewer_name,
        'Hotel Review Submitted',
        'Submitted a review for hotel booking #' . $hotel_booking_id . '.',
        'Reviews', (int)$pdo->lastInsertId()
    );

    // SUCCESS ALERT
    echo "
    <!DOCTYPE html>
    <html>
    <head>
        <script src='https://cdn.jsdelivr.net/npm/sweetalert2@11'></script>
    </head>
    <body>
    <script>
    Swal.fire({
        title: 'Success!',
        text: 'Your review has been submitted successfully.',
        icon: 'success',
        confirmButtonColor: '#2b7a66'
    }).then(() => {
        window.location.href = '" . $_SERVER['HTTP_REFERER'] . "';
    });
    </script>
    </body>
    </html>
    ";
    exit;
}
?>
