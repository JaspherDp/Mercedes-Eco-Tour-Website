<?php
require_once __DIR__ . '/session_security.php';
AppSessionStart();
require_once __DIR__ . '/db_connection.php';
require_once __DIR__ . '/tourist_auth_helper.php';
require_once __DIR__ . '/activity_logger.php';
require_once __DIR__ . '/favorites_helper.php';
require_once __DIR__ . '/hotel_rooms_helper.php';
require_once __DIR__ . '/booking_tourists_helper.php';
require_once __DIR__ . '/complaints_incidents_helper.php';
require_once __DIR__ . '/booking_cancellations_helper.php';
require_once __DIR__ . '/input_validation.php';
require_once __DIR__ . '/secure_upload_helper.php';
HoEnsureHotelBookingsTable($pdo);
ensureBookingTouristManifestColumns($pdo);
ensureComplaintsIncidentsTable($pdo);
ensureBookingCancellationRequestsTable($pdo);

// ---------- AUTH ----------
$user = TouristRequireLogin($pdo, 'redirect', '../?open_login=1', (string)($_SERVER['REQUEST_URI'] ?? ''));
$tourist_id = (int) $_SESSION['tourist_id'];

// Used by the shared PayMongo balance-checkout endpoint. The token is tied to
// this tourist session so a balance payment cannot be started for somebody
// else's booking.
if (empty($_SESSION['paymongo_balance_csrf'])) {
    $_SESSION['paymongo_balance_csrf'] = bin2hex(random_bytes(32));
}
$profilePayMongoCsrf = (string)$_SESSION['paymongo_balance_csrf'];

// A separate token protects the password form without coupling it to payment flows.
if (empty($_SESSION['profile_password_csrf'])) {
    $_SESSION['profile_password_csrf'] = bin2hex(random_bytes(32));
}
$profilePasswordCsrf = (string)$_SESSION['profile_password_csrf'];

if (empty($_SESSION['profile_logout_csrf'])) {
    $_SESSION['profile_logout_csrf'] = bin2hex(random_bytes(32));
}
$profileLogoutCsrf = (string)$_SESSION['profile_logout_csrf'];

if (empty($_SESSION['booking_cancellation_csrf'])) {
    $_SESSION['booking_cancellation_csrf'] = bin2hex(random_bytes(32));
}
$bookingCancellationCsrf = (string)$_SESSION['booking_cancellation_csrf'];
$profileMutationCsrf = AppCsrfToken('tourist', 'profile');
$manifestCsrf = AppCsrfToken('tourist', 'manifest');
$touristEngagementCsrf = AppCsrfToken('tourist', 'engagement');

// ---------- fetch fresh user ----------
// ---------- helper: get profile image ----------
function getProfileImage($user) {
    if (!is_array($user)) return '../img/profileicon.png';
    $profile = trim((string)($user['profile_picture'] ?? ''));

    if (!empty($profile)) {
        if (preg_match('#^https?://#i', $profile)) {
            if (stripos($profile, 'profiles.google.com') !== false && preg_match('#profiles\\.google\\.com/(?:s2/photos/profile/)?([^/?#]+)(?:/picture)?#i', $profile, $m)) {
                $profile = 'https://profiles.google.com/' . rawurlencode($m[1]) . '/picture?sz=256';
            } elseif (stripos($profile, 'googleusercontent.com') !== false) {
                $profile = preg_replace('/([?&])sz=\\d+/i', '$1sz=256', $profile);
                $profile = preg_replace('/=s\\d+-c(?=$|[?&#])/i', '=s256-c', $profile);
                $profile = preg_replace('/=s\\d+(?=$|[?&#])/i', '=s256', $profile);
            }
        }
        if (preg_match('#^https?://#i', $profile)) return $profile;
        return '../' . ltrim($profile, '/');
    }

    return '../img/profileicon.png';
}
$display_profile_pic = getProfileImage($user);
$profileCompletionChecks = [
    trim((string)($user['full_name'] ?? '')) !== '',
    trim((string)($user['email'] ?? '')) !== '',
    trim((string)($user['phone_number'] ?? '')) !== '',
    trim((string)($user['address'] ?? '')) !== '',
    trim((string)($user['profile_picture'] ?? '')) !== '',
];
$profileCompletedItems = count(array_filter($profileCompletionChecks));
$profileCompletion = (int)round(($profileCompletedItems / count($profileCompletionChecks)) * 100);

function favoriteProfileImage(?string $value): string
{
    $value = trim((string)$value);
    if ($value === '') {
        return '../img/sampleimage.png';
    }
    if (preg_match('~^https?://~i', $value) || str_starts_with($value, '//')) {
        return $value;
    }

    $clean = ltrim(str_replace('\\', '/', $value), '/');
    $base = basename($clean);
    $candidates = array_unique([
        $clean,
        'php/upload/' . $base,
        'uploads/' . $base,
        'img/' . $base,
    ]);
    foreach ($candidates as $candidate) {
        if (is_file(__DIR__ . '/../' . str_replace('/', DIRECTORY_SEPARATOR, $candidate))) {
            return '../' . $candidate;
        }
    }
    return '../img/sampleimage.png';
}

$favoriteItems = getTouristFavorites($pdo, $tourist_id);
$favoriteCounts = ['all' => count($favoriteItems), 'hotel' => 0, 'package' => 0, 'guide' => 0, 'boat' => 0];
foreach ($favoriteItems as $favoriteItem) {
    $favoriteType = (string)$favoriteItem['entity_type'];
    if (isset($favoriteCounts[$favoriteType])) {
        $favoriteCounts[$favoriteType]++;
    }
}
$favoriteTypeLabels = [
    'hotel' => 'Hotel & Resort',
    'package' => 'Tour Package',
    'guide' => 'Tour Guide',
    'boat' => 'Tour Boat',
];
$favoritesCsrf = favoriteCsrfToken();

$complaintStatement = $pdo->prepare(
    'SELECT complaint_incident_id, reference_number, report_type, category, subject, incident_at,
            location, description, people_involved, immediate_action, preferred_contact,
            evidence_paths, status, submitted_at, updated_at
     FROM complaints_incidents
     WHERE tourist_id = ?
     ORDER BY submitted_at DESC, complaint_incident_id DESC'
);
$complaintStatement->execute([$tourist_id]);
$complaintReports = $complaintStatement->fetchAll(PDO::FETCH_ASSOC) ?: [];

$complaintCounts = ['all' => count($complaintReports), 'submitted' => 0, 'in_review' => 0, 'resolved' => 0, 'dismissed' => 0];
$complaintCategoryLabels = [
    'tour-service' => 'Tour or guide service',
    'accommodation' => 'Hotel or accommodation',
    'transportation' => 'Boat or transportation',
    'safety-security' => 'Safety or security',
    'environmental' => 'Environmental concern',
    'staff-conduct' => 'Staff or operator conduct',
    'payment-booking' => 'Payment or booking',
    'other' => 'Other concern',
];
$complaintStatusLabels = [
    'submitted' => 'Submitted',
    'in_review' => 'In review',
    'resolved' => 'Resolved',
    'dismissed' => 'Closed',
];

foreach ($complaintReports as &$complaintReport) {
    $statusKey = strtolower((string)($complaintReport['status'] ?? 'submitted'));
    if (isset($complaintCounts[$statusKey])) {
        $complaintCounts[$statusKey]++;
    }

    $complaintReport['_evidence'] = [];
    $decodedEvidence = json_decode((string)($complaintReport['evidence_paths'] ?? ''), true);
    if (is_array($decodedEvidence)) {
        foreach (array_values($decodedEvidence) as $evidenceIndex => $evidencePath) {
            $cleanPath = ltrim(str_replace('\\', '/', (string)$evidencePath), '/');
            if (!preg_match('#^uploads/complaints/[A-Za-z0-9_.-]+$#D', $cleanPath)
                && !preg_match('/^private:[A-Za-z0-9_.-]+$/D', $cleanPath)) {
                continue;
            }
            $complaintReport['_evidence'][] = 'complaint_evidence.php?report_id=' . (int)$complaintReport['complaint_incident_id']
                . '&file=' . (int)$evidenceIndex;
        }
    }
}
unset($complaintReport);

function profileBillingRecord(PDO $pdo, int $touristId, string $type, int $bookingId): array
{
    if ($type === 'hotel') {
        $stmt = $pdo->prepare("
            SELECT b.*, h.name AS service_name,
                   TRIM(CONCAT(COALESCE(b.first_name, ''), ' ', COALESCE(b.last_name, ''))) AS guest_name
            FROM hotel_room_bookings b
            LEFT JOIN hotel_resorts h ON h.hotel_resort_id = b.hotel_resort_id
            WHERE b.hotel_booking_id = ? AND b.tourist_id = ? LIMIT 1
        ");
        $stmt->execute([$bookingId, $touristId]);
        $booking = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$booking) throw new RuntimeException('Hotel booking not found.');
        $expenseStmt = $pdo->prepare("
            SELECT expense_type, expense_name, quantity, unit_price, total_amount, notes
            FROM hotel_booking_expenses WHERE hotel_booking_id = ? AND hotel_resort_id = ? ORDER BY created_at ASC
        ");
        $expenseStmt->execute([$bookingId, (int)$booking['hotel_resort_id']]);
        $completed = strtolower((string)$booking['booking_status']) === 'completed' || !empty($booking['checked_out_at']);
        return ['type' => 'hotel', 'booking' => $booking, 'expenses' => $expenseStmt->fetchAll(PDO::FETCH_ASSOC) ?: [], 'completed' => $completed];
    }

    if ($type !== 'tour') throw new RuntimeException('Invalid booking type.');
    $stmt = $pdo->prepare("SELECT b.*, t.full_name AS guest_name FROM bookings b LEFT JOIN tourist t ON t.tourist_id=b.tourist_id WHERE b.booking_id=? AND b.tourist_id=? LIMIT 1");
    $stmt->execute([$bookingId, $touristId]);
    $booking = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$booking) throw new RuntimeException('Tour booking not found.');
    $expenseStmt = $pdo->prepare("SELECT expense_type, amount, note FROM booking_expenses WHERE booking_id=? ORDER BY id ASC");
    $expenseStmt->execute([(string)$bookingId]);
    $completed = strtolower((string)$booking['is_complete']) === 'completed';
    return ['type' => 'tour', 'booking' => $booking, 'expenses' => $expenseStmt->fetchAll(PDO::FETCH_ASSOC) ?: [], 'completed' => $completed];
}

if (($_GET['billing_action'] ?? '') === 'details') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $record = profileBillingRecord($pdo, $tourist_id, strtolower((string)($_GET['type'] ?? '')), (int)($_GET['id'] ?? 0));
        echo json_encode(['success' => true] + $record, JSON_INVALID_UTF8_SUBSTITUTE);
    } catch (Throwable $e) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_INVALID_UTF8_SUBSTITUTE);
    }
    exit;
}

if (($_GET['billing_action'] ?? '') === 'payment_status') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    $token = strtolower(trim((string)($_GET['token'] ?? '')));
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid payment reference.']);
        exit;
    }
    try {
        $statusStmt = $pdo->prepare(
            "SELECT status, booking_reference, amount_minor, metadata
             FROM payment_transactions
             WHERE return_token = ? AND tourist_id = ? AND provider = 'paymongo'
             LIMIT 1"
        );
        $statusStmt->execute([$token, $tourist_id]);
        $payment = $statusStmt->fetch(PDO::FETCH_ASSOC);
        $paymentMetadata = json_decode((string)($payment['metadata'] ?? ''), true);
        if (!$payment || !is_array($paymentMetadata) || ($paymentMetadata['source'] ?? '') !== 'tourist_profile_balance') {
            throw new RuntimeException('Payment reference not found.');
        }
        echo json_encode([
            'success' => true,
            'status' => strtolower((string)$payment['status']),
            'booking_reference' => (string)$payment['booking_reference'],
            'amount' => ((int)$payment['amount_minor']) / 100,
        ], JSON_INVALID_UTF8_SUBSTITUTE);
    } catch (Throwable $e) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_INVALID_UTF8_SUBSTITUTE);
    }
    exit;
}

if (($_GET['billing_action'] ?? '') === 'download_receipt') {
    try {
        $record = profileBillingRecord($pdo, $tourist_id, strtolower((string)($_GET['type'] ?? '')), (int)($_GET['id'] ?? 0));
        if (!$record['completed']) throw new RuntimeException('The receipt is available only after the booking is completed.');
        $b = $record['booking'];
        $isHotel = $record['type'] === 'hotel';
        $reference = (string)($b['booking_reference'] ?? ($isHotel ? $b['hotel_booking_id'] : $b['booking_id']));
        $guest = (string)($b['guest_name'] ?? 'Tourist');
        $service = $isHotel ? (string)($b['service_name'] ?? $b['room_type'] ?? 'Hotel stay') : (string)($b['package_name'] ?? $b['location'] ?? 'Tour service');
        $paid = (float)($isHotel ? $b['amount_paid'] : $b['payment_amount']);
        $balance = max(0, (float)($b['remaining_balance'] ?? 0));
        $total = max((float)($isHotel ? $b['total_amount'] : $b['grand_total']), $paid + $balance);
        require_once __DIR__ . '/../tcpdf/tcpdf.php';
        $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
        $pdf->SetCreator('iTour Mercedes');
        $pdf->SetTitle('Receipt ' . $reference);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetMargins(18, 18, 18);
        $pdf->AddPage();
        $html = '<div style="font-family:helvetica;color:#173b32">'
            . '<table cellpadding="5"><tr><td width="68%"><h1 style="color:#17624f;font-size:22px">iTour Mercedes</h1><div style="color:#638078;font-size:10px">OFFICIAL BOOKING RECEIPT</div></td><td width="32%" align="right"><b>Receipt</b><br><span style="font-size:10px">' . htmlspecialchars($reference) . '</span></td></tr></table>'
            . '<hr style="color:#b9d6cc"><table cellpadding="6" style="font-size:11px"><tr><td><b>Billed to</b><br>' . htmlspecialchars($guest) . '</td><td><b>Service</b><br>' . htmlspecialchars($service) . '</td></tr><tr><td><b>Booking type</b><br>' . ($isHotel ? 'Hotel reservation' : 'Tour reservation') . '</td><td><b>Issued</b><br>' . date('F j, Y') . '</td></tr></table>'
            . '<br><table cellpadding="8" border="1" style="border-color:#d7e4df;font-size:11px"><tr style="background-color:#edf6f2"><td><b>Payment summary</b></td><td align="right"><b>Amount</b></td></tr><tr><td>Booking total</td><td align="right">PHP ' . number_format($total, 2) . '</td></tr><tr><td>Amount paid</td><td align="right">PHP ' . number_format($paid, 2) . '</td></tr><tr style="background-color:#e4f1ec"><td><b>Remaining balance</b></td><td align="right"><b>PHP ' . number_format($balance, 2) . '</b></td></tr></table>'
            . '<br><div style="padding:12px;background-color:#f4f8f6;font-size:10px;color:#526b64">This computer-generated receipt confirms the payment record for the completed booking above.</div></div>';
        $pdf->writeHTML($html, true, false, true, false, '');
        $pdf->Output('itour-receipt-' . preg_replace('/[^A-Za-z0-9_-]/', '-', $reference) . '.pdf', 'D');
    } catch (Throwable $e) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        echo $e->getMessage();
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['billing_action'] ?? '') === 'pay_remaining') {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(410);
    echo json_encode(['success' => false, 'message' => 'Direct payment recording is disabled. Please use the secure PayMongo checkout.']);
    exit;
}

function profileTouristManifestRows(array $input): array
{
    $manifestKeys = ['full_name', 'gender', 'age', 'phone_number', 'country', 'region', 'province', 'city', 'barangay', 'postal_code', 'street'];
    foreach ($manifestKeys as $key) {
        if (!is_array($input[$key] ?? null)) throw new InvalidArgumentException('Tourist details must be submitted as aligned lists.');
    }
    $names = $input['full_name'];
    if (count($names) > 100) throw new InvalidArgumentException('A manifest may contain at most 100 tourists.');
    foreach ($manifestKeys as $key) {
        if (count($input[$key]) !== count($names)) throw new InvalidArgumentException('Tourist detail lists must have matching row counts.');
    }
    $rows = [];
    foreach ($names as $index => $rawName) {
        $name = ItourValidationText($rawName, 'Tourist name', 180);
        if ($name === '') {
            continue;
        }

        $gender = strtolower(trim((string)($input['gender'][$index] ?? '')));
        $ageValue = filter_var($input['age'][$index] ?? null, FILTER_VALIDATE_INT);
        $phone = ItourValidationText($input['phone_number'][$index] ?? '', 'Tourist phone', 50);
        $country = ItourValidationText($input['country'][$index] ?? '', 'Country', 100);
        $region = ItourValidationText($input['region'][$index] ?? '', 'Region', 120);
        $province = ItourValidationText($input['province'][$index] ?? '', 'Province', 120);
        $city = ItourValidationText($input['city'][$index] ?? '', 'City', 120);
        $barangay = ItourValidationText($input['barangay'][$index] ?? '', 'Barangay', 120);
        $postalCode = ItourValidationText($input['postal_code'][$index] ?? '', 'Postal code', 20);
        $street = ItourValidationText($input['street'][$index] ?? '', 'Street', 180);

        if (!in_array($gender, ['male', 'female'], true)) {
            throw new InvalidArgumentException("Select a valid gender for tourist " . ($index + 1) . '.');
        }
        if ($ageValue === false || $ageValue < 0 || $ageValue > 120) {
            throw new InvalidArgumentException("Enter a valid age from 0 to 120 for tourist " . ($index + 1) . '.');
        }
        if ($phone === '') {
            throw new InvalidArgumentException("Enter a contact number for tourist " . ($index + 1) . '.');
        }
        if (!preg_match('/^[0-9+().\-\s]{7,50}$/D', $phone)) {
            throw new InvalidArgumentException("Enter a valid contact number for tourist " . ($index + 1) . '.');
        }
        if ($country === '' || $region === '' || $province === '' || $city === '' || $barangay === '' || $postalCode === '' || $street === '') {
            throw new InvalidArgumentException("Complete the address for tourist " . ($index + 1) . '.');
        }

        $address = implode(', ', [$street, $barangay, $city, $province, $region, $postalCode, $country]);
        $rows[] = [
            'full_name' => $name,
            'gender' => $gender,
            'age' => $ageValue,
            'address' => ItourValidationText($address, 'Tourist address', 500, true),
            'country' => $country,
            'region' => $region,
            'province' => $province,
            'city' => $city,
            'barangay' => $barangay,
            'postal_code' => $postalCode,
            'street' => $street,
            'residence' => strtolower($country) === 'philippines' ? 'philippines' : 'foreign',
            'phone_number' => $phone,
        ];
    }

    if (!$rows) {
        throw new InvalidArgumentException('Add at least one tourist.');
    }
    return $rows;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['booking_id'], $_POST['full_name'])) {
    header('Content-Type: application/json'); // tell browser it's JSON

    try {
        if (!AppVerifyCsrf('tourist', 'manifest', $_POST['csrf_token'] ?? null)) {
            http_response_code(403);
            throw new Exception('Invalid security token. Refresh the page and try again.');
        }
        $booking_id = ItourValidationInt($_POST['booking_id'], 'Booking ID', 1, PHP_INT_MAX);

        $bookingCheck = $pdo->prepare("SELECT COALESCE(NULLIF(pax, 0), num_adults + num_children) FROM bookings WHERE booking_id = ? AND tourist_id = ? LIMIT 1");
        $bookingCheck->execute([$booking_id, $tourist_id]);
        $allowedPax = (int)$bookingCheck->fetchColumn();
        if ($allowedPax <= 0) throw new Exception('Booking not found or has no passenger capacity.');

        $stmtCheck = $pdo->prepare("SELECT COUNT(*) FROM booking_tourists WHERE booking_id = ?");
        $stmtCheck->execute([$booking_id]);
        if ($stmtCheck->fetchColumn() > 0) throw new Exception('Tourists already submitted for this booking.');

        $touristRows = profileTouristManifestRows($_POST);
        if (count($touristRows) !== $allowedPax) throw new Exception("Complete all {$allowedPax} tourist entries before submitting.");
        $pdo->beginTransaction();
        $stmt = $pdo->prepare("
            INSERT INTO booking_tourists
            (booking_id, full_name, gender, age, address, country, region, province, city, barangay, postal_code, street, residence, phone_number)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        foreach ($touristRows as $touristRow) {
            $stmt->execute([
                $booking_id,
                $touristRow['full_name'],
                $touristRow['gender'],
                $touristRow['age'],
                $touristRow['address'],
                $touristRow['country'],
                $touristRow['region'],
                $touristRow['province'],
                $touristRow['city'],
                $touristRow['barangay'],
                $touristRow['postal_code'],
                $touristRow['street'],
                $touristRow['residence'],
                $touristRow['phone_number'],
            ]);
        }

        $pdo->commit();

        echo json_encode([
            'success' => true,
            'message' => 'Tourists submitted successfully.'
        ]);
        exit;

    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();

        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    try {

        if (isset($_POST['action']) && $_POST['action'] === 'logout') {
            if (!hash_equals($profileLogoutCsrf, (string)($_POST['csrf_token'] ?? ''))) {
                throw new RuntimeException('Your logout request expired. Refresh the page and try again.');
            }
            try {
                logActivity(
                    $pdo, 'Tourist', $tourist_id,
                    (string)($user['full_name'] ?? $_SESSION['full_name'] ?? 'Tourist'),
                    'Logout', 'Signed out of the tourist account.', 'Authentication'
                );
            } catch (Throwable $logoutLogError) {
                // Signing out should still succeed if activity logging is unavailable.
            }
            AppDestroySession();
            header('Location: ../');
            exit;
        }

       // =====================================================
        // ✅ CHANGE PASSWORD (CLEAN VERSION)
        // =====================================================
        if (isset($_POST['action']) && $_POST['action'] === 'change_password') {

            $submittedCsrf = (string)($_POST['csrf_token'] ?? '');
            if ($submittedCsrf === '' || !hash_equals($profilePasswordCsrf, $submittedCsrf)) {
                header("Location: profile.php?error=invalid_password_request");
                exit;
            }

            $stmt = $pdo->prepare("SELECT password_hash FROM tourist WHERE tourist_id = ?");
            $stmt->execute([$tourist_id]);
            $dbUser = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$dbUser) {
                header("Location: profile.php?error=user_not_found");
                exit;
            }

            $old_password = $_POST['old_password'] ?? '';
            $new_password = $_POST['new_password'] ?? '';
            $confirm_password = $_POST['confirm_password'] ?? '';

            if ($old_password === '' || $new_password === '' || $confirm_password === '') {
                header("Location: profile.php?error=empty_password");
                exit;
            }
            if ($new_password !== $confirm_password) {
                header("Location: profile.php?error=password_mismatch");
                exit;
            }
            if (
                !is_string($new_password) || strlen($new_password) > 128 || strlen($new_password) < 10 ||
                !preg_match('/[A-Z]/', $new_password) ||
                !preg_match('/[a-z]/', $new_password) ||
                !preg_match('/[0-9]/', $new_password)
            ) {
                header("Location: profile.php?error=weak_password");
                exit;
            }

            if (!password_verify($old_password, $dbUser['password_hash'])) {
                header("Location: profile.php?error=wrong_old_password");
                exit;
            }
            if (password_verify($new_password, $dbUser['password_hash'])) {
                header("Location: profile.php?error=reused_password");
                exit;
            }

            $hashed = password_hash($new_password, PASSWORD_DEFAULT);

            $stmt = $pdo->prepare("
                UPDATE tourist
                SET password_hash = ?
                WHERE tourist_id = ?
            ");

            $stmt->execute([$hashed, $tourist_id]);
            $_SESSION['profile_password_csrf'] = bin2hex(random_bytes(32));
            logActivity(
                $pdo, 'Tourist', $tourist_id,
                (string)($user['full_name'] ?? $_SESSION['full_name'] ?? 'Tourist'),
                'Password Updated', 'Updated the tourist account password.', 'Profile', $tourist_id
            );

            header("Location: profile.php?password_changed=1");
            exit;
        }

        // =====================================================
        // ✅ TOURIST SUBMISSION (YOUR ORIGINAL LOGIC - FIXED SAFE)
        // =====================================================
        if (isset($_POST['booking_id'], $_POST['full_name']) && !isset($_POST['action'])) {

            if (!AppVerifyCsrf('tourist', 'manifest', $_POST['csrf_token'] ?? null)) {
                http_response_code(403);
                throw new Exception('Invalid security token. Refresh the page and try again.');
            }

            $booking_id = ItourValidationInt($_POST['booking_id'], 'Booking ID', 1, PHP_INT_MAX);

            $bookingCheck = $pdo->prepare("SELECT COALESCE(NULLIF(pax, 0), num_adults + num_children) FROM bookings WHERE booking_id = ? AND tourist_id = ? LIMIT 1");
            $bookingCheck->execute([$booking_id, $tourist_id]);
            $allowedPax = (int)$bookingCheck->fetchColumn();
            if ($allowedPax <= 0) throw new Exception('Booking not found or has no passenger capacity.');

            $stmtCheck = $pdo->prepare("SELECT COUNT(*) FROM booking_tourists WHERE booking_id = ?");
            $stmtCheck->execute([$booking_id]);

            if ($stmtCheck->fetchColumn() > 0) {
                throw new Exception('Tourists already submitted for this booking.');
            }

            $touristRows = profileTouristManifestRows($_POST);
            if (count($touristRows) !== $allowedPax) throw new Exception("Complete all {$allowedPax} tourist entries before submitting.");
            $pdo->beginTransaction();

            $stmt = $pdo->prepare("
                INSERT INTO booking_tourists
                (booking_id, full_name, gender, age, address, country, region, province, city, barangay, postal_code, street, residence, phone_number)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");

            foreach ($touristRows as $touristRow) {
                $stmt->execute([
                    $booking_id,
                    $touristRow['full_name'],
                    $touristRow['gender'],
                    $touristRow['age'],
                    $touristRow['address'],
                    $touristRow['country'],
                    $touristRow['region'],
                    $touristRow['province'],
                    $touristRow['city'],
                    $touristRow['barangay'],
                    $touristRow['postal_code'],
                    $touristRow['street'],
                    $touristRow['residence'],
                    $touristRow['phone_number'],
                ]);
            }

            $pdo->commit();
            logActivity(
                $pdo, 'Tourist', $tourist_id,
                (string)($user['full_name'] ?? $_SESSION['full_name'] ?? 'Tourist'),
                'Tourist Data Submitted',
                'Submitted traveler details for booking #' . $booking_id . '.',
                'Bookings', $booking_id
            );

            echo json_encode([
                'success' => true,
                'message' => 'Tourists submitted successfully.'
            ]);
            exit;
        }

        // =====================================================
        // ✅ UPDATE PROFILE (SMART VERSION)
        // =====================================================
        if (isset($_POST['action']) && $_POST['action'] === 'update_profile') {

            if (!AppVerifyCsrf('tourist', 'profile', $_POST['csrf_token'] ?? null)) {
                http_response_code(403);
                throw new Exception('Invalid security token. Refresh the page and try again.');
            }

            $phone = ItourValidationText($_POST['phone'] ?? '', 'Phone number', 30);
            $address = ItourValidationText($_POST['address'] ?? '', 'Address', 500);
            if ($phone !== '' && (!preg_match('/^[0-9+().\-\s]{7,30}$/D', $phone)
                || strlen(preg_replace('/\D+/', '', $phone)) < 7)) {
                throw new InvalidArgumentException('Enter a valid contact number using at least 7 digits.');
            }

            // original values (for comparison)
            $oldPhone = $user['phone_number'] ?? '';
            $oldAddress = $user['address'] ?? '';
            $oldPic = $user['profile_picture'] ?? '';

            $profile_picture = $oldPic;
            $newProfileAbsolute = null;

            $updatedFields = [];

            // ---------- IMAGE UPLOAD ----------
            if (isset($_FILES['profile_picture'])
                && (int)($_FILES['profile_picture']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                $uploadDir = ItourEnsureProjectDirectory('uploads/profile_pictures');
                $validatedImage = ItourSecureValidateUploadedImage($_FILES['profile_picture'], 5 * 1024 * 1024);
                $fileName = ItourSecureRandomFilename('tourist_' . $tourist_id, (string)$validatedImage['extension']);
                $targetPath = $uploadDir . DIRECTORY_SEPARATOR . $fileName;
                ItourSecureReencodeImageFile($validatedImage, $targetPath);
                $newProfileAbsolute = $targetPath;
                $profile_picture = 'uploads/profile_pictures/' . $fileName;
                $updatedFields[] = 'pic';
            }

            // ---------- CHECK CHANGES ----------
            if ($phone !== $oldPhone) {
                $updatedFields[] = 'phone';
            }

            if ($address !== $oldAddress) {
                $updatedFields[] = 'address';
            }

            // ---------- UPDATE DB ----------
            $stmt = $pdo->prepare("
                UPDATE tourist
                SET phone_number = ?, address = ?, profile_picture = ?
                WHERE tourist_id = ?
            ");

            try {
                $stmt->execute([$phone, $address, $profile_picture, $tourist_id]);
            } catch (Throwable $exception) {
                if ($newProfileAbsolute !== null && is_file($newProfileAbsolute)) @unlink($newProfileAbsolute);
                throw $exception;
            }
            if ($newProfileAbsolute !== null && is_string($oldPic)
                && preg_match('#^uploads/profile_pictures/[A-Za-z0-9._-]+$#D', $oldPic)) {
                $oldAbsolute = realpath(ItourProjectPath($oldPic));
                $safeRoot = realpath(ItourProjectPath('uploads/profile_pictures'));
                if ($oldAbsolute && $safeRoot && $oldAbsolute !== realpath($newProfileAbsolute)
                    && str_starts_with(strtolower($oldAbsolute), strtolower($safeRoot . DIRECTORY_SEPARATOR))
                    && is_file($oldAbsolute)) {
                    @unlink($oldAbsolute);
                }
            }
            if ($updatedFields) {
                $fieldLabels = array_map(static function ($field) {
                    return $field === 'pic' ? 'profile picture' : $field;
                }, $updatedFields);
                logActivity(
                    $pdo, 'Tourist', $tourist_id,
                    (string)($user['full_name'] ?? $_SESSION['full_name'] ?? 'Tourist'),
                    'Profile Updated',
                    'Updated ' . implode(', ', $fieldLabels) . ' on the tourist profile.',
                    'Profile', $tourist_id
                );
            }

            // build query string
            $query = http_build_query([
                'profile_updated' => 1,
                'fields' => implode(',', $updatedFields)
            ]);

            header("Location: profile.php?$query");
            exit;
        }
        
        // =====================================================
        // 🚀 PLACEHOLDER (SAFE FOR FUTURE REVIEW SYSTEM)
        // =====================================================
        if (isset($_POST['type'], $_POST['rating'], $_POST['comment'], $_POST['booking_id'])) {

            if (!AppVerifyCsrf('tourist', 'engagement', $_POST['csrf_token'] ?? null)) {
                http_response_code(403);
                throw new Exception('Invalid security token. Refresh the page and try again.');
            }

            $booking_id = ItourValidationInt($_POST['booking_id'], 'Booking ID', 1, PHP_INT_MAX);
            $type = strtolower(ItourValidationText($_POST['type'], 'Review type', 20, true));
            if (!in_array($type, ['package', 'boat', 'tourguide'], true)) throw new InvalidArgumentException('Invalid review type.');
            $rating = ItourValidationInt($_POST['rating'], 'Rating', 1, 5);
            $comment = ItourValidationText($_POST['comment'], 'Review comment', 5000, true);
            $eligible = $pdo->prepare('SELECT 1 FROM bookings WHERE booking_id = ? AND tourist_id = ? AND LOWER(booking_type) = ? AND LOWER(is_complete) = \'completed\' LIMIT 1');
            $eligible->execute([$booking_id, $tourist_id, $type]);
            if (!$eligible->fetchColumn()) throw new InvalidArgumentException('Only your completed booking can be reviewed.');
            $duplicate = $pdo->prepare('SELECT 1 FROM reviews WHERE tourist_id = ? AND booking_id = ? LIMIT 1');
            $duplicate->execute([$tourist_id, $booking_id]);
            if ($duplicate->fetchColumn()) throw new InvalidArgumentException('This booking has already been reviewed.');

            $stmt = $pdo->prepare("
                INSERT INTO reviews (tourist_id, booking_id, type, rating, comment)
                VALUES (?, ?, ?, ?, ?)
            ");

            $stmt->execute([
                $tourist_id,
                $booking_id,
                $type,
                $rating,
                $comment
            ]);

            echo json_encode([
                'success' => true,
                'message' => 'Review submitted successfully.'
            ]);
            exit;
        }

        } catch (Exception $e) {

        if (isset($pdo) && $pdo->inTransaction()) {
            $pdo->rollBack();
        }

        // always redirect to profile with error
        header("Location: profile.php?error=" . urlencode($e->getMessage()));
        exit;
    }
}

// ---------- DATA FETCH ----------

$cancellationStatement = $pdo->prepare("
    SELECT *
    FROM booking_cancellation_requests
    WHERE tourist_id = ?
    ORDER BY requested_at DESC, cancellation_request_id DESC
");
$cancellationStatement->execute([$tourist_id]);
$cancellationRequests = $cancellationStatement->fetchAll(PDO::FETCH_ASSOC) ?: [];
$tourCancellationDetails = $pdo->prepare("
    SELECT b.*, t.full_name AS t_full_name, t.email AS t_email,
           t.phone_number AS t_phone_number, t.address AS t_address,
           t.profile_picture AS t_profile_picture
    FROM bookings b
    LEFT JOIN tourist t ON t.tourist_id = b.tourist_id
    WHERE b.booking_id = ? AND b.tourist_id = ? LIMIT 1
");
$hotelCancellationDetails = $pdo->prepare("
    SELECT b.*, h.name AS hotel_name, t.full_name AS t_full_name,
           t.email AS t_email, t.phone_number AS t_phone_number, t.address AS t_address,
           t.profile_picture AS t_profile_picture
    FROM hotel_room_bookings b
    LEFT JOIN hotel_resorts h ON h.hotel_resort_id = b.hotel_resort_id
    LEFT JOIN tourist t ON t.tourist_id = b.tourist_id
    WHERE b.hotel_booking_id = ? AND b.tourist_id = ? LIMIT 1
");
foreach ($cancellationRequests as &$cancellationRequest) {
    $isHotelCancellation = strtolower((string)$cancellationRequest['booking_domain']) === 'hotel';
    $detailStatement = $isHotelCancellation ? $hotelCancellationDetails : $tourCancellationDetails;
    $detailStatement->execute([(int)$cancellationRequest['booking_id'], $tourist_id]);
    $bookingDetail = $detailStatement->fetch(PDO::FETCH_ASSOC);
    if (!$bookingDetail) {
        $bookingDetail = $isHotelCancellation
            ? [
                'hotel_booking_id' => (int)$cancellationRequest['booking_id'],
                'booking_reference' => $cancellationRequest['booking_reference'],
                'booking_status' => $cancellationRequest['request_status'],
                'hotel_name' => $cancellationRequest['service_name'],
                'checkin_date' => $cancellationRequest['service_date'],
                'total_amount' => $cancellationRequest['total_amount'],
                'amount_paid' => $cancellationRequest['amount_paid'],
            ]
            : [
                'booking_id' => (int)$cancellationRequest['booking_id'],
                'booking_reference' => $cancellationRequest['booking_reference'],
                'booking_type' => $cancellationRequest['booking_type'],
                'status' => $cancellationRequest['request_status'],
                'package_name' => $cancellationRequest['service_name'],
                'booking_date' => $cancellationRequest['service_date'],
                'grand_total' => $cancellationRequest['total_amount'],
                'payment_amount' => $cancellationRequest['amount_paid'],
            ];
    }
    $bookingDetail['cancellation_request_status'] = $cancellationRequest['request_status'];
    $cancellationRequest['_booking_detail'] = $bookingDetail;
}
unset($cancellationRequest);
$activeCancellationKeys = [];
$approvedCancellationKeys = [];
$rescheduledBookingKeys = [];
$decisionRequiredRequests = [];
$decisionRequestByBookingKey = [];
foreach ($cancellationRequests as $cancellationRequest) {
    $requestKey = strtolower((string)$cancellationRequest['booking_domain']) . ':' . (int)$cancellationRequest['booking_id'];
    $requestStatus = strtolower((string)$cancellationRequest['request_status']);
    if (in_array($requestStatus, ['pending', 'approved', 'decision_required'], true)) $activeCancellationKeys[$requestKey] = true;
    if ($requestStatus === 'approved') $approvedCancellationKeys[$requestKey] = true;
    if ($requestStatus === 'rescheduled') $rescheduledBookingKeys[$requestKey] = true;
    if ($requestStatus === 'decision_required' && !empty($cancellationRequest['reschedule_offered'])) {
        $decisionRequiredRequests[] = $cancellationRequest;
        $decisionRequestByBookingKey[$requestKey] = $cancellationRequest;
    }
}

// Active bookings (status pending, accepted, declined, uncomplete)
$bookings_active_stmt = $pdo->prepare("
    SELECT b.*,
           t.full_name AS t_full_name,
           t.email AS t_email,
           t.phone_number AS t_phone_number,
           t.address AS t_address,
           t.profile_picture AS t_profile_picture
    FROM bookings b
    LEFT JOIN tourist t ON b.tourist_id = t.tourist_id
    WHERE b.tourist_id = ?
      AND b.is_complete = 'uncomplete'
      AND b.status IN ('pending','accepted')
      AND NOT EXISTS (
          SELECT 1 FROM booking_cancellation_requests cr
          WHERE cr.booking_domain = 'tour'
            AND cr.booking_id = b.booking_id
            AND cr.request_status IN ('pending','approved')
      )
    ORDER BY b.created_at DESC
");
$bookings_active_stmt->execute([$tourist_id]);
$bookings_active = $bookings_active_stmt->fetchAll(PDO::FETCH_ASSOC);

// Booking history (status completed, cancelled, declined)
// Booking history (status completed, cancelled, declined)
$bookings_history_stmt = $pdo->prepare("
    SELECT b.*,
        t.full_name AS t_full_name,
        t.email AS t_email,
        t.phone_number AS t_phone_number,
        t.address AS t_address,
        t.profile_picture AS t_profile_picture,
        
        -- PACKAGE
        tp.package_id AS matched_package_id,
        tp.package_title AS package_name,
        
        -- TOUR GUIDE
        tg.fullname AS tourguide_name,
        
        -- BOAT
        bo.name AS boat_name
        
    FROM bookings b

    LEFT JOIN tourist t
        ON b.tourist_id = t.tourist_id
    
    LEFT JOIN tour_packages tp
        ON b.package_name = tp.package_title AND b.operator_id = tp.operator_id
        
    LEFT JOIN tour_guides tg
        ON b.guide_id = tg.guide_id
        
    LEFT JOIN boats bo
        ON b.boat_id = bo.boat_id
        
    WHERE b.tourist_id = ?
      AND (b.is_complete != 'uncomplete'
           OR b.status IN ('declined','cancelled','completed'))
      AND NOT EXISTS (
          SELECT 1 FROM booking_cancellation_requests cr
          WHERE cr.booking_domain = 'tour'
            AND cr.booking_id = b.booking_id
            AND cr.request_status = 'approved'
      )
           
    ORDER BY b.created_at DESC
");
$bookings_history_stmt->execute([$tourist_id]);
$bookings_history = $bookings_history_stmt->fetchAll(PDO::FETCH_ASSOC);

// ---------- UPCOMING BOOKINGS ----------
$upcoming_stmt = $pdo->prepare("
    SELECT *
    FROM bookings
    WHERE tourist_id = ?
      AND is_complete = 'uncomplete'
      AND status = 'accepted'
      AND NOT EXISTS (
          SELECT 1 FROM booking_cancellation_requests cr
          WHERE cr.booking_domain = 'tour'
            AND cr.booking_id = bookings.booking_id
            AND cr.request_status IN ('pending','approved','decision_required')
      )
    ORDER BY booking_date ASC
");
$upcoming_stmt->execute([$tourist_id]);
$upcoming_bookings = $upcoming_stmt->fetchAll(PDO::FETCH_ASSOC);

function hasTourists(PDO $pdo, int $booking_id): bool {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) 
        FROM booking_tourists 
        WHERE booking_id = ?
    ");
    $stmt->execute([$booking_id]);
    return (int)$stmt->fetchColumn() > 0;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['fetch_tourists'])) {
    header('Content-Type: application/json');

    $booking_id = (int)($_POST['booking_id'] ?? 0);
    $stmt = $pdo->prepare("
        SELECT bt.*
        FROM booking_tourists bt
        INNER JOIN bookings b ON b.booking_id = bt.booking_id
        WHERE b.booking_id = ? AND b.tourist_id = ?
        ORDER BY bt.id ASC
    ");
    $stmt->execute([$booking_id, $tourist_id]);

    echo json_encode([
        'success' => true,
        'tourists' => $stmt->fetchAll(PDO::FETCH_ASSOC)
    ]);
    exit;
}

/// ---------- HOTEL BOOKINGS (ONLY SOURCE OF TRUTH) ----------
$hotel_stmt = $pdo->prepare("
    SELECT DISTINCT
        hrb.hotel_booking_id,
        hrb.booking_reference,
        hrb.tourist_id,
        hrb.hotel_resort_id,
        hrb.room_type,
        hrb.checkin_date,
        hrb.checkout_date,
        hrb.adults,
        hrb.children,
        hrb.first_name,
        hrb.last_name,
        hrb.email,
        hrb.phone_number,
        hrb.special_request,
        hrb.nights,
        hrb.rooms_booked,
        hrb.total_amount,
        hrb.amount_paid,
        hrb.remaining_balance,
        hrb.payment_type,
        hrb.payment_status,
        hrb.booking_status,
        hrb.checked_out_at,
        hrb.created_at,
        hr.name AS hotel_name,
        hr.type AS hotel_type,
        t.full_name AS t_full_name,
        t.email AS t_email,
        t.phone_number AS t_phone_number,
        t.address AS t_address,
        t.profile_picture AS t_profile_picture
    FROM hotel_room_bookings hrb
    LEFT JOIN hotel_resorts hr
        ON hr.hotel_resort_id = hrb.hotel_resort_id
    LEFT JOIN tourist t
        ON t.tourist_id = hrb.tourist_id
    WHERE hrb.tourist_id = ?
    GROUP BY hrb.hotel_booking_id
    ORDER BY hrb.checkin_date DESC
");

$hotel_stmt->execute([$tourist_id]);

$hotel_active_bookings = $hotel_stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
$hotel_stmt->execute([$tourist_id]);

// ---------- SPLIT ONLY FROM THIS ARRAY ----------

// Upcoming
$hotel_upcoming_bookings = array_values(array_filter(
    $hotel_active_bookings,
    fn($h) =>
        empty($h['checked_out_at']) &&
        !in_array(strtolower($h['booking_status'] ?? ''), ['cancelled', 'completed']) &&
        empty($activeCancellationKeys['hotel:' . (int)$h['hotel_booking_id']])
));

usort($upcoming_bookings, function($a, $b) {
    return strtotime($a['booking_date']) <=> strtotime($b['booking_date']);
});

usort($hotel_upcoming_bookings, function($a, $b) {
    return strtotime($a['checkin_date']) <=> strtotime($b['checkin_date']);
});

// Completed
$completed_hotel_bookings = array_values(array_filter(
    $hotel_active_bookings,
    fn($h) =>
        (!empty($h['checked_out_at']) ||
        in_array(strtolower($h['booking_status'] ?? ''), ['completed', 'cancelled'])) &&
        empty($approvedCancellationKeys['hotel:' . (int)$h['hotel_booking_id']])
));

// ---------- TOTAL UPCOMING ----------
$hotel_upcoming_count = array_filter(
    $hotel_active_bookings,
    fn($h) =>
        empty($h['checked_out_at']) &&
        !in_array(strtolower($h['booking_status'] ?? ''), ['cancelled', 'completed']) &&
        empty($activeCancellationKeys['hotel:' . (int)$h['hotel_booking_id']])
);

$totalUpcoming = count($upcoming_bookings) + count($hotel_upcoming_count);

$activeTourAcceptedCount = count(array_filter(
    $bookings_active,
    fn($booking) => strtolower((string)($booking['status'] ?? '')) === 'accepted'
));
$activeTourPendingCount = count(array_filter(
    $bookings_active,
    fn($booking) => strtolower((string)($booking['status'] ?? '')) === 'pending'
));
$activeHotelConfirmedCount = count(array_filter(
    $hotel_upcoming_bookings,
    fn($booking) => in_array(strtolower((string)($booking['booking_status'] ?? '')), ['accepted', 'confirmed', 'approved'], true)
));
$activeHotelPendingCount = count(array_filter(
    $hotel_upcoming_bookings,
    fn($booking) => in_array(strtolower((string)($booking['booking_status'] ?? 'pending')), ['', 'pending'], true)
));
$bookingSummary = [
    'total' => count($bookings_active) + count($hotel_upcoming_bookings),
    'confirmed' => $activeTourAcceptedCount + $activeHotelConfirmedCount,
    'pending' => $activeTourPendingCount + $activeHotelPendingCount,
    'hotels' => count($hotel_upcoming_bookings),
];
$cancellationSummary = ['total' => count($cancellationRequests), 'pending' => 0, 'approved' => 0, 'refund_processing' => 0, 'refunded' => 0];
foreach ($cancellationRequests as $cancellationRequest) {
    $requestStatus = strtolower((string)$cancellationRequest['request_status']);
    $refundStatus = strtolower((string)$cancellationRequest['refund_status']);
    if ($requestStatus === 'pending') $cancellationSummary['pending']++;
    if ($requestStatus === 'approved') $cancellationSummary['approved']++;
    if (in_array($refundStatus, ['pending', 'processing'], true)) $cancellationSummary['refund_processing']++;
    if (in_array($refundStatus, ['completed', 'refunded'], true)) $cancellationSummary['refunded']++;
}

$historySummary = ['total' => count($bookings_history) + count($completed_hotel_bookings), 'packages' => 0, 'boat_guides' => 0, 'hotels' => count($completed_hotel_bookings)];
foreach ($bookings_history as $historyBooking) {
    $historyType = strtolower((string)($historyBooking['booking_type'] ?? ''));
    if (in_array($historyType, ['package', 'tour'], true)) {
        $historySummary['packages']++;
    } elseif (in_array($historyType, ['boat', 'tourguide', 'guide'], true)) {
        $historySummary['boat_guides']++;
    }
}

$reviewMap = [];

// Get all reviews by THIS logged-in user only
$stmt = $pdo->prepare("
    SELECT hotel_resort_id, hotel_booking_id
    FROM hotel_resort_reviews
    WHERE tourist_id = ?
");
$stmt->execute([$tourist_id]);

while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {

    $key = $row['hotel_resort_id'] . '-' . $row['hotel_booking_id'];

    $reviewMap[$key] = true;
}

// --- NEW: Map for Tour/Guide Feedbacks ---
$feedbackMap = [
    'package'   => [],
    'tourguide' => [],
    'boat'      => []
];

$feedback_stmt = $pdo->prepare("
    SELECT package_id, tourguide_id, boat_id, booking_type
    FROM feedback 
    WHERE tourist_id = ?
");
$feedback_stmt->execute([$tourist_id]);

while ($row = $feedback_stmt->fetch(PDO::FETCH_ASSOC)) {
    $bookingType = strtolower(trim((string)($row['booking_type'] ?? '')));
    if ($bookingType === 'package' && $row['package_id']) {
        $feedbackMap['package'][$row['package_id']] = true;
    }
    if ($bookingType === 'tourguide' && $row['tourguide_id']) {
        $feedbackMap['tourguide'][$row['tourguide_id']] = true;
    }
    if ($bookingType === 'boat' && $row['boat_id']) {
        $feedbackMap['boat'][$row['boat_id']] = true;
    }
}
?>

<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>iTour Mercedes - My Profile</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="icon" type="image/png" href="../img/newlogo.png" />
<link rel="stylesheet" href="../styles/favorites.css" />
<link rel="stylesheet" href="../styles/complaint-modal.css?v=<?= (int)@filemtime(__DIR__ . '/../styles/complaint-modal.css') ?>" />
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css" />
<script>
(() => {
  const allowedTabs = ['profile', 'bookings', 'cancel-bookings', 'favorites', 'complaints', 'history'];
  const requestedTab = new URLSearchParams(window.location.search).get('section');
  let savedTab = null;
  try { savedTab = sessionStorage.getItem('profileActiveTab') || localStorage.getItem('activeTab'); } catch (error) {}
  const navigationEntry = performance.getEntriesByType?.('navigation')?.[0];
  const isReload = navigationEntry ? navigationEntry.type === 'reload' : performance.navigation?.type === 1;
  const isHistoryTraversal = navigationEntry
    ? navigationEntry.type === 'back_forward'
    : performance.navigation?.type === 2;
  const initialTab = isReload
    ? (allowedTabs.includes(savedTab) ? savedTab : (allowedTabs.includes(requestedTab) ? requestedTab : 'profile'))
    : (isHistoryTraversal ? 'profile' : (allowedTabs.includes(requestedTab) ? requestedTab : 'profile'));
  document.documentElement.dataset.profileTab = initialTab;
})();

/* Keep a stable return destination for the Profile back controls. */
(function rememberProfileEntryPage() {
  const storageKey = 'profileReturnUrl';
  const fallbackUrl = new URL('../', window.location.href).href;
  try {
    const currentUrl = new URL(window.location.href);
    const referrerUrl = document.referrer ? new URL(document.referrer, currentUrl) : null;
    const navigationEntry = performance.getEntriesByType?.('navigation')?.[0];
    const isReload = navigationEntry
      ? navigationEntry.type === 'reload'
      : performance.navigation?.type === 1;
    const cameFromProfile = referrerUrl
      && referrerUrl.origin === currentUrl.origin
      && referrerUrl.pathname === currentUrl.pathname;

    if (referrerUrl && referrerUrl.origin === currentUrl.origin && !cameFromProfile) {
      sessionStorage.setItem(storageKey, referrerUrl.href);
    } else if (cameFromProfile || isReload) {
      if (!sessionStorage.getItem(storageKey)) sessionStorage.setItem(storageKey, fallbackUrl);
    } else {
      sessionStorage.setItem(storageKey, fallbackUrl);
    }
  } catch (error) {}
})();

function returnFromProfile() {
  const fallbackUrl = new URL('../', window.location.href);
  let destination = fallbackUrl;
  try {
    const storedUrl = sessionStorage.getItem('profileReturnUrl');
    const candidate = storedUrl ? new URL(storedUrl, window.location.href) : fallbackUrl;
    if (candidate.origin === window.location.origin && candidate.pathname !== window.location.pathname) {
      destination = candidate;
    }
    sessionStorage.removeItem('profileReturnUrl');
  } catch (error) {}
  window.location.assign(destination.href);
}
</script>
<style>
:root{--primary:#2e7d66;--primary-dark:#173f34;--primary-soft:#e8f3ef;--muted:#f1f7f5;--card:#fff;--text:#17231f;--text-muted:#66756f;--border:#dfe9e5;--page:#f4f7f6}
*{box-sizing:border-box;font-family:Inter,system-ui,-apple-system,Segoe UI,Arial}
body{margin:0;background:var(--page);color:var(--text)}
html, body {
  height: 100%;
  overflow-x: hidden; /* prevents sideways page scroll */
}
/* ONLY main scrolls */
.main {
  margin-left: 270px;
  height: 100vh;
  overflow-y: auto;
  padding: 0 14px 48px!important;
}
.container{display:flex;min-height:100vh}
.sidebar{
  width:270px;background:linear-gradient(180deg, #143a2d 0%, #102b22 100%);border-right:1px solid #eef3f6;padding:20px 18px;display:flex;flex-direction:column;gap:12px;
  position:fixed;left:0;top:0;bottom:0;
}
.back{cursor:pointer;color: #fff;font-weight:700;display:flex;align-items:center;gap:8px}
.sidebar > .back{display:none}
.sidebar-back{
  display:inline-flex;
  align-items:center;
  gap:8px;
  width:max-content;
  padding:8px 9px;
  border:0;
  border-radius:8px;
  background:transparent;
  color:rgba(255,255,255,.82);
  font-size:.83rem;
  font-weight:700;
  cursor:pointer;
}
.sidebar-back:hover{background:rgba(255,255,255,.08);color:#fff}
.sidebar-back svg{width:17px;height:17px}
.profile-area{text-align:center;padding:6px 0;border-bottom:1px solid #f1f5f9}
.profile-img{width:84px;height:84px;border-radius:50px;object-fit:cover;}
.profile-area h3{margin:8px 0 0;font-size:1rem;color:#fff}
.profile-area p {
    margin: 6px 0 0;
    color: #fff;
    font-size: 0.8rem;
    max-width: 100%;        /* ensure it doesn’t exceed sidebar width */
    white-space: normal;    /* allow line breaks */
    word-break: break-word; /* break long words/emails if needed */
    overflow-wrap: anywhere;/* wrap text anywhere if too long */
}

.nav-links{margin-top:12px;display:flex;flex-direction:column;gap:6px}
.nav-links a{display:flex;align-items:center;min-height:42px;padding:10px 12px;border-radius:9px;text-decoration:none;color:rgba(255,255,255,.82);font-weight:700;transition:background .18s,color .18s,transform .18s}
.nav-links a:hover{background:rgba(255,255,255,.08);color:#fff;transform:translateX(2px)}
.nav-links a.active{background:rgba(255,255,255,.14);color:#fff;box-shadow:inset 3px 0 0 #75c7a9}
html[data-profile-tab="profile"] .nav-links a[data-section="profile"],
html[data-profile-tab="bookings"] .nav-links a[data-section="bookings"],
html[data-profile-tab="cancel-bookings"] .nav-links a[data-section="cancel-bookings"],
html[data-profile-tab="favorites"] .nav-links a[data-section="favorites"],
html[data-profile-tab="complaints"] .nav-links a[data-section="complaints"],
html[data-profile-tab="history"] .nav-links a[data-section="history"]{background:rgba(255,255,255,.14);color:#fff;box-shadow:inset 3px 0 0 #75c7a9}
html[data-profile-tab] .section{display:none!important}
html[data-profile-tab="profile"] #profile,
html[data-profile-tab="profile"] #upcoming-bookings,
html[data-profile-tab="bookings"] #bookings,
html[data-profile-tab="cancel-bookings"] #cancel-bookings,
html[data-profile-tab="favorites"] #favorites,
html[data-profile-tab="complaints"] #complaints,
html[data-profile-tab="history"] #history{display:block!important}
.nav-item-label{display:inline-flex;align-items:center;gap:10px}
.nav-item-label svg{width:18px;height:18px;flex:none;opacity:.9}
.logout{margin-top:auto;padding-top:10px;border-top:1px solid #f1f5f9;text-align:}
.logout a{color:var(--text);text-decoration:none;font-weight:700}
.logout .btn {
  background: #0b67a3 !important;
}

/* main area */
.main{margin-left:270px;flex:1;padding:0 14px 48px}
.header{background:#fff;padding:12px;border-radius:8px;margin-bottom:12px;border:1px solid #eef3f6;box-shadow:0 2px 6px rgba(0,0,0,0.03)}
.header h2{color:var(--primary);margin:0}
.card{background:var(--card);border-radius:10px;padding:16px;border:1px solid var(--border);box-shadow:0 6px 20px rgba(0,0,0,0.04);margin-bottom:16px}
.profile-row{display:flex;gap:14px;align-items:center}
.profile-row img{width:120px;height:120px;border-radius:60px;object-fit:cover;}
.profile-info h1{margin:0;font-size:1.3rem;color:var(--primary)}
.small{color:#6b7280}

/* forms */
.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-top:10px}
.input{padding:9px;border-radius:8px;border:1px solid #e6eef3;width:100%}
.btn{padding:9px 12px;border-radius:8px;background:var(--primary);color:#fff;border:none;cursor:pointer;font-weight:700}

/* tables (admin-like) */
.table{width:100%;border-collapse:collapse;margin-top:12px}
thead th{background:var(--primary);color:#fff;padding:10px 12px;text-transform:uppercase;font-size:12px}
th,td{padding:12px;border-bottom:1px solid var(--border);text-align:left; font-size: 14px !important;}
.profile-cell{display:flex;align-items:center;gap:10px}
.profile-icon{width:44px;height:44px;border-radius:50%;object-fit:cover;border:2px solid var(--primary)}
.pill{display:inline-block;padding:6px 8px;border-radius:999px;font-size:0.82rem;font-weight:700}
.pill.pending{background:#fff7e6;color:#a05b00}
.pill.accepted{background:#e9fbf4;color:#0b7a50}
.pill.declined{background:#fff1f1;color:#a42b2b}
.pill.finished{background:#ebf6ff;color:#0b67a3}
.diag{font-size:13px;color:#6b7280;margin-bottom:10px}
@media(max-width:900px){.sidebar{display:none}.main{margin-left:0!important;padding:20px 14px 36px!important}}

.pill.cancelled {
    background: #ffe5e5; /* light red */
    color: #a42b2b;      /* dark red text */
}

.pill.completed,.pill.complete{border-color:#b9d7f5;background:#e5f1ff;color:#1d5f9f}

.booking-details-modal-overlay-user{position:fixed;inset:0;z-index:10000;display:block;visibility:hidden;background:rgba(7,31,24,.38);opacity:0;transition:opacity .24s ease,visibility .24s ease}
.booking-details-modal-overlay-user.show{visibility:visible;opacity:1}
.booking-details-modal-user{position:absolute;top:0;right:0;display:flex;width:min(460px,100%);height:100%;flex-direction:column;overflow:hidden;border-left:1px solid #cfdfd9;background:#f5f8f7;box-shadow:-18px 0 44px rgba(8,44,33,.16);transform:translateX(100%);transition:transform .28s ease}
.booking-details-modal-overlay-user.show .booking-details-modal-user{transform:translateX(0)}
.booking-details-modal-header-user{display:flex;align-items:flex-start;justify-content:space-between;gap:18px;padding:22px 22px 18px;border-bottom:1px solid #d9e5e1;background:#fff}
.booking-details-modal-title-user{display:flex;align-items:center;gap:12px;min-width:0}
.booking-details-modal-title-icon-user{display:grid;width:39px;height:39px;flex:none;place-items:center;border:1px solid #cce2da;border-radius:11px;background:#edf8f4;color:#2e7d66}
.booking-details-modal-title-icon-user svg{width:19px;height:19px}
.booking-details-modal-title-user h3{margin:0;color:#17231f;font-size:1.05rem}
.booking-details-modal-title-user p{margin:3px 0 0;color:#71817b;font-size:.76rem}
.booking-details-modal-close-user{display:grid;width:34px;height:34px;flex:none;place-items:center;border:1px solid #d7e3df;border-radius:9px;background:#fff;color:#567068;font-size:22px;line-height:1;cursor:pointer}
.booking-details-modal-close-user:hover{border-color:#b9d3ca;background:#edf8f4;color:#245447}
.booking-details-modal-body-user{flex:1;padding:20px 22px;overflow-y:auto;color:#30453e}
.booking-detail-hero-user{padding:17px;border:1px solid #cfe1da;border-radius:13px;background:linear-gradient(135deg,#fff 0%,#edf7f3 100%)}
.booking-detail-hero-top-user{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:10px}
.booking-detail-label-user{color:#71817b;font-size:.69rem;font-weight:750;letter-spacing:.06em;text-transform:uppercase}
.booking-detail-reference-user{margin:0;color:#173f34;font-size:1.05rem;overflow-wrap:anywhere}
.booking-detail-status-user{padding:5px 9px;border-radius:999px;font-size:.68rem;font-weight:800;text-transform:uppercase}
.booking-detail-status-user.accepted{background:#dff4eb;color:#1d7159}
.booking-detail-status-user.pending{background:#fff0c9;color:#9a5c00}
.booking-detail-status-user.declined,.booking-detail-status-user.cancelled{background:#fee7e7;color:#a43a3a}
.booking-detail-section-user{margin-top:16px;border:1px solid #dce7e3;border-radius:12px;background:#fff;overflow:hidden}
.booking-detail-section-title-user{display:flex;align-items:center;gap:8px;margin:0;padding:11px 14px;border-bottom:1px solid #e5ece9;background:#fafcfb;color:#23483c;font-size:.76rem;font-weight:800;text-transform:uppercase;letter-spacing:.035em}
.booking-detail-section-title-user svg{width:15px;height:15px;color:#2e7d66}
.booking-detail-grid-user{display:grid;grid-template-columns:1fr 1fr;gap:0}
.booking-detail-item-user{min-width:0;padding:12px 14px;border-bottom:1px solid #edf2f0}
.booking-detail-item-user:nth-last-child(-n+2){border-bottom:0}
.booking-detail-item-user.full{grid-column:1/-1}
.booking-detail-value-user{display:block;margin-top:4px;color:#1f342d;font-size:.84rem;font-weight:700;line-height:1.35;overflow-wrap:anywhere}
.booking-details-modal-footer-user{display:flex;justify-content:flex-end;padding:14px 22px;border-top:1px solid #d9e5e1;background:#fff}
.booking-details-modal-btn-close-user{min-width:100px;padding:9px 15px;border:0;border-radius:8px;background:#2e7d66;color:#fff;font-weight:700;cursor:pointer}
.booking-details-modal-btn-close-user:hover{background:#245447}
body.booking-drawer-open{overflow:hidden}
@media(max-width:520px){.booking-details-modal-user{width:100%}.booking-details-modal-header-user,.booking-details-modal-body-user{padding-left:17px;padding-right:17px}.booking-detail-grid-user{grid-template-columns:1fr}.booking-detail-item-user,.booking-detail-item-user:nth-last-child(-n+2){border-bottom:1px solid #edf2f0}.booking-detail-item-user:last-child{border-bottom:0}}

/* Booking drawer visual structure */
.booking-details-modal-user{width:min(590px,100%);border-radius:18px 0 0 18px;background:#f1f7f4}
.booking-details-modal-header-user{align-items:center;padding:18px 24px}
.booking-details-modal-title-user{gap:11px}
.booking-details-brand-logo-user{width:43px;height:43px;flex:none;object-fit:contain;border-radius:50%}
.booking-details-modal-title-user .booking-brand-kicker-user{margin:0 0 2px;color:#2e7d66;font-size:.61rem;font-weight:850;letter-spacing:.14em;text-transform:uppercase}
.booking-details-modal-title-user h3{font-size:1.12rem}
.booking-details-modal-body-user{padding:18px 19px 24px;scrollbar-color:#9fcaba transparent;scrollbar-width:thin}
.booking-details-modal-body-user::-webkit-scrollbar{width:7px}
.booking-details-modal-body-user::-webkit-scrollbar-thumb{border-radius:999px;background:#9fcaba}
.booking-detail-hero-user{padding:20px;border:0;border-radius:16px;background:linear-gradient(135deg,#115b45,#20785f);box-shadow:0 8px 18px rgba(19,89,68,.12)}
.booking-detail-hero-user .booking-detail-label-user{color:#c7e2d8}
.booking-detail-reference-user{color:#fff;font-size:1.28rem}
.booking-detail-status-user{border:1px solid rgba(255,255,255,.42);background:rgba(255,255,255,.12)!important;color:#fff!important}
.booking-primary-guest-user{display:grid;grid-template-columns:46px minmax(0,1fr);gap:12px;align-items:center;margin-top:13px;padding:16px;border:1px solid #d8e5e0;border-radius:15px;background:#fff;box-shadow:0 5px 14px rgba(17,63,49,.045)}
.booking-primary-avatar-user{display:grid;width:46px;height:46px;place-items:center;overflow:hidden;border-radius:12px;background:#e2f1eb;color:#176047;font-size:.88rem;font-weight:850}
.booking-primary-avatar-user img{display:block;width:100%;height:100%;object-fit:cover}
.booking-primary-guest-user .booking-detail-value-user{font-size:.9rem}
.booking-primary-email-user{display:block;margin-top:4px;color:#71817b;font-size:.74rem;overflow-wrap:anywhere}
.booking-detail-section-user{margin-top:13px;padding:15px;border-radius:15px;box-shadow:0 5px 14px rgba(17,63,49,.035)}
.booking-detail-section-heading-user{display:flex;align-items:center;gap:10px;padding-bottom:12px;border-bottom:1px solid #e4ece9}
.booking-detail-section-icon-user,.booking-detail-card-icon-user{display:grid;flex:none;place-items:center;border-radius:9px;background:#e7f3ee;color:#176047}
.booking-detail-section-icon-user{width:35px;height:35px}
.booking-detail-card-icon-user{width:32px;height:32px}
.booking-detail-section-icon-user svg,.booking-detail-card-icon-user svg{width:17px;height:17px}
.booking-detail-section-heading-user h4{margin:0;color:#18392f;font-size:.84rem}
.booking-detail-section-heading-user p{margin:3px 0 0;color:#83918c;font-size:.64rem}
.booking-detail-cards-user{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-top:12px}
.booking-detail-card-user{display:grid;grid-template-columns:32px minmax(0,1fr);gap:9px;align-items:center;min-width:0;padding:10px;border:1px solid #dfe9e5;border-radius:11px;background:#f8fbfa}
.booking-detail-card-user.full{grid-column:1/-1}
.booking-detail-card-user .booking-detail-label-user{font-size:.57rem;letter-spacing:.025em}
.booking-detail-card-user .booking-detail-value-user{margin-top:3px;font-size:.73rem}
.booking-billing-value-user{color:#145c46!important;font-size:.84rem!important}
.booking-payment-state-user{display:inline-flex;width:max-content;padding:4px 7px;border-radius:999px;background:#e0f3eb;color:#176047;font-size:.66rem;font-weight:850;text-transform:uppercase}
.booking-payment-state-user.partial{background:#fff0c9;color:#925800}
.booking-payment-state-user.unpaid{background:#fee7e7;color:#a43a3a}
.booking-details-modal-footer-user{padding:13px 19px;box-shadow:0 -5px 16px rgba(17,63,49,.06)}
.booking-details-modal-btn-close-user{min-width:128px;padding:11px 17px;border-radius:10px;box-shadow:0 5px 12px rgba(23,96,71,.15)}
@media(max-width:520px){.booking-detail-cards-user{grid-template-columns:1fr}.booking-detail-card-user.full{grid-column:auto}.booking-details-modal-header-user{padding:15px 17px}.booking-details-brand-logo-user{width:38px;height:38px}}

.btn-details-user.booking-btn-user {
    padding:4px 8px; border-radius:6px; border:none; cursor:pointer;
    font-weight:500; font-size:13px;
    background-color:#2e7d66; color:#fff; transition:all 0.2s;
}
.btn-details-user.booking-btn-user:hover {
    background-color:#245447;
}

/* Modal overlay */
.tourist-modal-user {
  position: fixed;
  inset: 0; /* top, right, bottom, left = 0 */
  background: rgba(0,0,0,0.5);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 9999;
  padding: 20px; /* space around modal for small screens */
  overflow: auto; /* allow scrolling if modal is taller than viewport */
}

/* Modal content */
.tourist-modal-content-user {
  background: #fff;
  width: 95%;
  max-width: 800px;
  max-height: 90vh; /* limit modal height */
  overflow-y: auto; /* vertical scroll if content exceeds height */
  border-radius: 10px;
  padding: 20px;
  display: flex;
  flex-direction: column;
  box-sizing: border-box;
}

/* Optional: keep footer visible if needed */
.tourist-modal-footer-user {
  margin-top: 16px;
  text-align: right;
  flex-shrink: 0;
}


.tourist-fields-user {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 8px;
  margin-bottom: 8px;
}

.tourist-fields-user input,
.tourist-fields-user select {
  padding: 8px;
  border-radius: 6px;
  border: 1px solid #ddd;
}

.tourist-modal-content-user.extended {
  width: 95%;
  max-width: 800px;
}

.tourist-modal-header-user {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 14px;
}

.tourist-modal-close-user {
  background: none;
  border: none;
  font-size: 22px;
  cursor: pointer;
  color: #777;
}

.tourist-row-user {
  display: grid;
  grid-template-columns: 2fr 1fr 1.2fr 1.5fr auto;
  gap: 8px;
  align-items: center;
  margin-bottom: 8px;
}

.tourist-row-user input,
.tourist-row-user select {
  padding: 8px;
  border-radius: 6px;
  border: 1px solid #ddd;
}

.remove-tourist-user {
  background: #ffe5e5;
  color: #a42b2b;
  border: none;
  border-radius: 6px;
  padding: 6px 10px;
  cursor: pointer;
  font-weight: bold;
}

.remove-tourist-user:hover {
  background: #ffcccc;
}

.add-more-user {
  margin-top: 10px;
}

.tourist-modal-footer-user {
  margin-top: 16px;
  text-align: right;
}

.view-tourist-btn-user {
  background: #9ca3af !important; /* gray */
  cursor: pointer;
}
.view-tourist-btn-user:hover {
  background: #6b7280 !important;
}

.pill.confirmed,
.pill.accepted {
  background: #2b7a66;   /* green */
  color: #fff;
}

.add-tourist-btn-user {
  background: #2b7a66;
  color: #fff;
  padding: 8px 14px;
  border-radius: 8px;
  border: none;
  cursor: pointer;
  transition: all 0.2s ease;
  font-weight: 500;
  font-size: 12px;
}

.add-tourist-btn-user:hover {
  background: #102b24;
  transform: translateY(-2px);
  box-shadow: 0 6px 14px rgba(37, 99, 235, 0.25);
}

.add-tourist-btn-user:active {
  transform: translateY(0);
  box-shadow: none;
}

.add-tourist-btn-user:disabled {
  background: #94a3b8;
  cursor: not-allowed;
  transform: none;
  box-shadow: none;
}

/* =========================
   UPCOMING BOOKINGS PRO UI
========================= */

.upcoming-section {
  margin-top: 24px;
}

/* HEADER */
.section-header h3 {
  font-size: 1.6rem;
  font-weight: 800;
  color: var(--primary);
  margin-bottom: 16px;
}

/* ROW SCROLL CONTAINER */
.booking-row {
  display: flex;
  gap: 14px;
  overflow-x: auto;
  overflow-y: hidden;
  scroll-behavior: smooth;
  padding: 10px 5px;
  max-width: 100%;
}
.booking-row::-webkit-scrollbar {
  display: none;
}

/* CARD WIDTH FIXED */
.booking-card {
  min-width: 280px;
  flex: 0 0 auto;
}

/* --- DYNAMIC DATE STATUS LABELS --- */
.capsule-past-due {
  background-color: #ffe8e8;
  color: #dc3545; /* Red */
  border: 1px solid #ffcaca;
  padding: 4px 12px;
  border-radius: 50px;
  font-size: 0.85rem;
  font-weight: bold;
  display: inline-flex;
  align-items: center;
  gap: 6px;
}

.days-left {
  color: #28a745; /* Green */
  font-size: 0.9rem;
  display: inline-flex;
  align-items: center;
  gap: 6px;
}

/* --- FIXED SCROLL CONTROLS --- */
.scroll-controls {
  display: flex;
  gap: 8px;
  align-items: center;
}

.scroll-btn {
  background: #ffffff;
  border: 1px solid #ddd;
  border-radius: 50%;
  width: 34px;
  height: 34px;
  cursor: pointer;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  color: #555;
  box-shadow: 0 2px 4px rgba(0,0,0,0.05);
  transition: all 0.2s ease;
}

.scroll-btn:hover {
  background: #f8f9fa;
  border-color: #bbb;
  color: #000;
  transform: scale(1.05);
}

.scroll-btn:active {
  transform: scale(0.95);
}

/* HEADER WITH ARROWS */
.group-title {
  display: flex;
  align-items: center; 
  justify-content: space-between; /* Pushes content to opposite ends */
  width: 100%;
  margin-bottom: 12px;
}

.title-text {
  display: flex;
  align-items: center;
  gap: 10px;
  font-weight: 700;
  font-size: 1.1rem;
}

.scroll-controls {
  display: flex;
  gap: 8px;
}

.scroll-btn {
  background: #ffffff;
  border: 1px solid #ced4da;
  border-radius: 6px;
  width: 32px;
  height: 32px;
  display: flex;
  align-items: center;
  justify-content: center;
  cursor: pointer;
  transition: all 0.2s;
}

.scroll-btn:hover {
  background: #00695c;
  color: #fff;
  border-color: #00695c;
}

/* ICON STYLE */
.scroll-btn i {
  font-size: 14px;
}

/* HEADER COLORS FIX */
.tour-title {
  border-left: 4px solid #0ea5e9;
}

.hotel-title {
  border-left: 4px solid #10b981;
}
/* CARD */
.booking-card {
  background: #fff;
  border: 1px solid #e9eef0;
  border-radius: 14px;
  overflow: hidden;
  transition: 0.2s ease;
  box-shadow: 0 3px 10px rgba(0,0,0,0.04);
}

.booking-card:hover {
  transform: translateY(-4px);
  box-shadow: 0 10px 25px rgba(0,0,0,0.08);
}

/* --- GROUP TITLE LAYOUT --- */
.group-title {
  display: flex;
  align-items: center; /* Aligns items vertically in the center */
  gap: 16px; /* Space between the buttons and the title text */
  margin-bottom: 16px; /* Space between title row and the cards */
}

.title-text {
  font-size: 1.1rem;
  font-weight: bold;
  color: var(--primary); /* Keep your existing color theme */
  display: flex;
  align-items: center;
  gap: 8px; /* Space between text and the number */
}

/* Optional: Style the number count so it stands out */
.booking-count {
  background-color: #e9ecef;
  color: #333;
  padding: 2px 8px;
  border-radius: 12px;
  font-size: 0.9rem;
  font-weight: bold;
}

/* HEADER */
.booking-card-header {
  padding: 10px 14px;
  color: #fff;
  font-weight: 800;
  font-size: 0.85rem;
  letter-spacing: 0.3px;
}

/* BODY */
.booking-card-body {
  padding: 14px;
  display: flex;
  flex-direction: column;
  gap: 8px;
  font-size: 0.9rem;
  color: #374151;
}

/* TAGS */
.meta-tags {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
  margin-top: 6px;
}

.meta-tags span {
  background: #f3f4f6;
  padding: 5px 10px;
  border-radius: 999px;
  font-size: 0.75rem;
  color: #374151;
}

/* FOOTER */
.booking-card-footer {
  padding: 10px 14px;
  background: #f9fafb;
  border-top: 1px solid #eef2f7;
  font-size: 0.78rem;
  color: #6b7280;
}

/* COLORS */
.header-tour {
  background: #2b7a66;
}

.header-hotel {
  background: #2b7a66;
}

.history-section h4 {
  margin-top: 16px;
  padding: 10px 0;
  border-bottom: 2px solid #eef2f7;
}

.pill.paid {
  background: #dcfce7;
  color: #166534;
  padding: 4px 10px;
  border-radius: 999px;
  font-size: 0.8rem;
  font-weight: 600;
}

.pill.unpaid {
  background: #fee2e2;
  color: #991b1b;
  padding: 4px 10px;
  border-radius: 999px;
  font-size: 0.8rem;
  font-weight: 600;
}

.pill.partial {
  background: #fef9c3;
  color: #854d0e;
  padding: 4px 10px;
  border-radius: 999px;
  font-size: 0.8rem;
  font-weight: 600;
}

.review-btn{
  background: #2b7a66;
  color: #fff;
  border: none;
  padding: 6px 12px;
  border-radius: 6px;
  cursor: pointer;
  font-size: 13px;
  transition: 0.2s;
}

.review-btn:hover{
  background: #065f46;
}

/* ===================== MODAL OVERLAY ===================== */
.af-modal-overlay {
  position: fixed;
  inset: 0;
  background: rgba(0, 0, 0, 0.55);
  display: none;
  justify-content: center;
  align-items: center;

  z-index: 9999;
  backdrop-filter: blur(2px);

  padding: 20px;
  overflow-y: auto; /* ✅ allows scroll if needed */
}

.af-modal-overlay.active {
  display: flex;
}

/* ===================== GRID LAYOUT FOR RATINGS ===================== */
#reviewForm {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 14px 18px;
}

/* each rating box */
.review-group {
  margin-bottom: 0;
}

/* COMMENT + ACTIONS should span full width */
.review-group:has(textarea),
.af-actions {
  grid-column: 1 / -1;
}

/* ===================== MODAL BOX ===================== */
.af-modal {
  background: #ffffff;
  width: 100%;
  max-width: 520px;

  max-height: 90vh;   /* ✅ prevents touching top/bottom */
  overflow-y: auto;   /* ✅ scroll inside modal */

  border-radius: 18px;
  box-shadow: 0 18px 50px rgba(0, 0, 0, 0.18);

  padding: 22px;      /* slightly reduced */
  animation: modalFade 0.25s ease;

  font-family: system-ui, -apple-system, Segoe UI, Roboto, sans-serif;
  border: 2px solid #e5f3ef;
}
/* ===================== ANIMATION ===================== */
@keyframes modalFade {
  from {
    opacity: 0;
    transform: translateY(15px) scale(.97);
  }

  to {
    opacity: 1;
    transform: translateY(0) scale(1);
  }
}

/* ===================== HEADER ===================== */
.af-modal-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 18px;
  padding-bottom: 14px;
  border-bottom: 1px solid #dcefe9;
}

.af-modal-header strong {
  font-size: 20px;
  color: #2b7a66;
  font-weight: 700;
}

.af-modal-header button {
  border: none;
  background: transparent;
  font-size: 28px;
  line-height: 1;
  cursor: pointer;
  color: #2b7a66;
  transition: 0.2s ease;
}

.af-modal-header button:hover {
  transform: scale(1.1);
  color: #1f5f4f;
}

/* ===================== REVIEW GROUP ===================== */
.review-group {
  margin-bottom: 14px;
}

/* ===================== TITLES ===================== */
.af-package-title {
  font-size: 14px;
  font-weight: 700;
  color: #1f2937;
  margin-bottom: 8px;
}

/* ===================== STAR RATING ===================== */
.rating {
  display: flex;
  flex-direction: row-reverse;
  justify-content: flex-end;
  align-items: center;
  gap: 6px;
  min-height: 34px;
}

/* IMPORTANT FIX */
.rating label {
  display: flex;
  align-items: center;
  justify-content: center;
  line-height: 0;
  cursor: pointer;
  display: inline-flex;
}

/* hide radio */
.rating input {
  display: none;
}

/* star */
.rating svg {
  width: 30px;
  height: 30px;
  fill: #d1d5db;
  transition: fill 0.2s ease,
              transform 0.2s ease;
}

/* hover effect */
.rating label:hover svg,
.rating label:hover ~ label svg {
  fill: #2b7a66;
  transform: scale(1.08);
}

/* checked effect */
.rating input:checked ~ label svg {
  fill: #2b7a66;
}

/* ===================== TEXTAREA ===================== */
textarea {
  width: 100%;
  min-height: 110px;
  resize: vertical;
  padding: 14px;
  border-radius: 14px;
  border: 1px solid #cfe6df;
  background: #f8fffc;
  font-size: 14px;
  outline: none;
  transition: 0.2s ease;
  box-sizing: border-box;
}

textarea:focus {
  border-color: #2b7a66;
  background: #ffffff;
  box-shadow: 0 0 0 4px rgba(43, 122, 102, 0.12);
}

/* ===================== ACTION BUTTONS ===================== */
.af-actions {
  display: flex;
  justify-content: flex-end;
  gap: 12px;
  margin-top: 24px;
}

/* primary */
.af-btn {
  border: none;
  border-radius: 12px;
  padding: 11px 18px;
  font-size: 14px;
  font-weight: 700;
  cursor: pointer;
  transition: 0.2s ease;
}

/* submit */
.af-btn:not(.secondary) {
  background: #2b7a66;
  color: white;
}

.af-btn:not(.secondary):hover {
  background: #236655;
  transform: translateY(-1px);
}

/* cancel */
.af-btn.secondary {
  background: #edf5f2;
  color: #2b7a66;
}

.af-btn.secondary:hover {
  background: #dcefe9;
}

/* VIEW MODE LOCK */
#reviewForm input:disabled + label {
  pointer-events: none;
  cursor: default;
}

/* REMOVE HOVER EFFECT IN VIEW MODE */
#reviewForm input:disabled + label:hover svg {
  transform: none !important;
  filter: none !important;
}

/* OPTIONAL: make view mode visually dim */
#reviewForm input:disabled + label svg {
  opacity: 0.85;
}

/* VIEW REVIEW BUTTON */
.view-review-btn {
  background: #f3f4f6;          /* soft gray */
  color: #374151;              /* dark readable text */
  border: 1px solid #e5e7eb;
  padding: 8px 14px;
  border-radius: 10px;
  font-size: 10px;
  font-weight: 600;
  cursor: pointer;
  transition: all 0.2s ease;
  letter-spacing: 0.2px;
}

/* hover */
.view-review-btn:hover {
  background: #e5e7eb;
  border-color: #d1d5db;
  transform: translateY(-1px);
}

/* active click */
.view-review-btn:active {
  transform: scale(0.98);
}

/* focus (keyboard accessibility) */
.view-review-btn:focus {
  outline: none;
  box-shadow: 0 0 0 3px rgba(156, 163, 175, 0.4);
}

/* ===================== MOBILE ===================== */
@media (max-width: 600px) {

  .af-modal {
    padding: 20px;
    border-radius: 16px;
  }

  .rating svg {
    width: 26px;
    height: 26px;
  }

  .af-actions {
    flex-direction: column;
  }

  .af-btn {
    width: 100%;
  }
}

#touristFormUser {
  display: flex;
  flex-direction: column;
  gap: 5px;
}

#touristRowsUser {
  display: flex;
  flex-direction: column;
  gap: 3px;
}

/* Passenger manifest form */
.tourist-modal-user{z-index:11000;padding:24px;background:rgba(7,29,24,.68);backdrop-filter:blur(4px)}
.tourist-modal-content-user.extended{width:min(1040px,100%);max-width:1040px;max-height:calc(100vh - 48px);padding:0;overflow:hidden;border:1px solid #d7e5e0;border-radius:18px;box-shadow:0 28px 80px rgba(7,34,27,.3)}
.tourist-modal-header-user{margin:0;padding:22px 24px;border-bottom:1px solid #dce8e3;background:linear-gradient(135deg,#f9fcfb,#eaf5f1)}
.tourist-modal-header-user>div{min-width:0}.tourist-modal-kicker{display:block;margin-bottom:4px;color:#26745f;font-size:.62rem;font-weight:850;letter-spacing:.11em;text-transform:uppercase}.tourist-modal-header-user h3{margin:0;color:#173c32;font-size:1.25rem}.tourist-modal-header-user p{margin:5px 0 0;color:#687e76;font-size:.75rem}.tourist-modal-close-user{display:grid;width:34px;height:34px;flex:none;place-items:center;border-radius:9px;color:#537067}.tourist-modal-close-user:hover{background:#dcece6;color:#174f40}
#touristFormUser{min-height:0;overflow:hidden;gap:0;background:#f7faf9}.tourist-form-summary{display:flex;align-items:center;justify-content:space-between;gap:20px;padding:13px 24px;border-bottom:1px solid #e0eae6;background:#fff}.tourist-form-summary>div{display:flex;align-items:baseline;gap:8px}.tourist-form-summary strong{color:#17634f;font-size:1.15rem}.tourist-form-summary span{color:#536b63;font-size:.7rem;font-weight:750}.tourist-form-summary p{margin:0;color:#788b85;font-size:.66rem}
#touristRowsUser{max-height:52vh;padding:16px 24px;overflow-y:auto;gap:12px;scrollbar-width:thin;scrollbar-color:#91b9ac #edf3f1}
.tourist-row-user{position:relative;display:block;margin:0;padding:16px 48px 16px 16px;border:1px solid #dbe7e2;border-radius:13px;background:#fff;box-shadow:0 3px 10px rgba(21,67,53,.045)}
.tourist-row-heading{display:flex;align-items:center;gap:9px;margin-bottom:12px}.tourist-row-number{display:grid;width:25px;height:25px;place-items:center;border-radius:7px;color:#fff;background:#26745f;font-size:.68rem;font-weight:850}.tourist-row-heading strong{color:#294a40;font-size:.77rem}.tourist-row-grid{display:grid;grid-template-columns:minmax(180px,1.5fr) minmax(135px,.8fr) minmax(85px,.45fr) minmax(155px,.9fr);gap:10px}.tourist-field{display:block;min-width:0}.tourist-field.address{grid-column:1/-1}.tourist-field>span{display:block;margin:0 0 5px;color:#526d64;font-size:.62rem;font-weight:800}.tourist-field input,.tourist-field select,.tourist-address-trigger{width:100%;height:40px;padding:9px 11px;border:1px solid #cedcd7;border-radius:8px;outline:0;background:#fff;color:#29463d;font:inherit;font-size:.72rem;transition:border-color .16s,box-shadow .16s}.tourist-field input:focus,.tourist-field select:focus,.tourist-address-trigger:focus{border-color:#4b997f;box-shadow:0 0 0 3px rgba(61,145,117,.12)}.tourist-address-trigger{display:flex;align-items:center;justify-content:space-between;gap:12px;text-align:left;cursor:pointer}.tourist-address-trigger span{overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:#82928d}.tourist-address-trigger.has-address span{color:#29463d}.tourist-address-trigger svg{width:17px;height:17px;flex:none;color:#27745f}.remove-tourist-user{position:absolute;top:14px;right:14px;display:grid;width:30px;height:30px;place-items:center;padding:0;border:1px solid #f0cccc;border-radius:8px;background:#fff2f2;color:#ae4141}.remove-tourist-user:hover{background:#fbe1e1}
.tourist-modal-footer-user{display:flex;align-items:center;justify-content:space-between;gap:12px;margin:0;padding:15px 24px;border-top:1px solid #dce7e3;background:#fff}.tourist-modal-footer-user .btn{min-height:40px;padding:9px 15px;border-radius:9px;font-size:.72rem;font-weight:800}.tourist-modal-footer-user .add-more-user{margin:0;color:#1c654f;background:#edf7f3;border:1px solid #c5ded5}.tourist-submit-user{color:#fff;background:#26745f;border:1px solid #26745f;box-shadow:0 5px 13px rgba(38,116,95,.17)}.tourist-submit-user:disabled{color:#82908b!important;background:#d9dfdd!important;border-color:#d0d7d4!important;box-shadow:none!important;cursor:not-allowed!important;opacity:1!important}
.tourist-address-overlay{position:fixed;inset:0;z-index:11100;display:none;align-items:center;justify-content:center;padding:22px;background:rgba(7,29,24,.65);backdrop-filter:blur(5px)}.tourist-address-overlay.show{display:flex}.tourist-address-modal{width:min(620px,100%);overflow:hidden;border:1px solid #d4e3dd;border-radius:17px;background:#fff;box-shadow:0 28px 85px rgba(5,31,25,.32)}.tourist-address-header{display:flex;align-items:flex-start;justify-content:space-between;gap:18px;padding:20px 22px;border-bottom:1px solid #dce7e3;background:linear-gradient(135deg,#f9fcfb,#eaf5f0)}.tourist-address-header span{color:#26745f;font-size:.58rem;font-weight:850;letter-spacing:.1em;text-transform:uppercase}.tourist-address-header h3{margin:3px 0;color:#173c32;font-size:1.12rem}.tourist-address-header p{margin:0;color:#6c817a;font-size:.68rem}.tourist-address-header button{display:grid;width:32px;height:32px;place-items:center;border:0;border-radius:8px;background:transparent;color:#567067;font-size:22px;cursor:pointer}.tourist-address-header button:hover{background:#dbece5}.tourist-address-body{padding:19px 22px}.tourist-address-copy{padding:12px;margin-bottom:15px;border:1px solid #cde1d9;border-radius:10px;background:#eff8f4}.tourist-address-copy label{display:block;margin-bottom:6px;color:#28624f;font-size:.65rem;font-weight:800}.tourist-address-copy select{width:100%;height:39px;padding:8px 10px;border:1px solid #bdd7cd;border-radius:8px;background:#fff;color:#29483e;font-size:.72rem}.tourist-address-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}.tourist-address-field.full{grid-column:1/-1}.tourist-address-field span{display:block;margin-bottom:5px;color:#506a61;font-size:.64rem;font-weight:800}.tourist-address-field input,.tourist-address-field select{width:100%;height:41px;padding:9px 11px;border:1px solid #cadad4;border-radius:8px;outline:0;color:#29483f;background:#fff;font:inherit;font-size:.73rem}.tourist-address-field input:focus,.tourist-address-field select:focus{border-color:#4b997f;box-shadow:0 0 0 3px rgba(61,145,117,.12)}.tourist-address-field select:disabled{color:#8a9994;background:#f1f5f3;cursor:not-allowed}.tourist-address-error{margin:12px 0 0;padding:9px 11px;border-radius:8px;background:#fff0f1;color:#a23d47;font-size:.67rem}.tourist-address-footer{display:flex;justify-content:flex-end;gap:9px;padding:14px 22px;border-top:1px solid #dde8e4;background:#fafcfb}.tourist-address-footer button{min-height:39px;padding:9px 15px;border-radius:8px;font:inherit;font-size:.7rem;font-weight:800;cursor:pointer}.tourist-address-cancel{border:1px solid #cddbd6;background:#fff;color:#526a62}.tourist-address-done{border:1px solid #26745f;background:#26745f;color:#fff}
.tourist-address-modal{overflow:hidden!important;border-radius:18px!important}.tourist-address-header{border-radius:17px 17px 0 0}.tourist-address-footer{border-radius:0 0 17px 17px}.tourist-search-select{position:relative}.tourist-search-select>select{position:absolute!important;width:1px!important;height:1px!important;padding:0!important;overflow:hidden!important;clip:rect(0,0,0,0)!important;white-space:nowrap!important;border:0!important}.tourist-search-input-wrap{position:relative}.tourist-search-input{padding-right:38px!important}.tourist-search-toggle{position:absolute!important;top:0;right:0;width:38px!important;height:41px!important;padding:0!important;border:0!important;background:transparent!important;color:#60756d!important;font-size:14px!important;cursor:pointer}.tourist-search-menu{position:absolute;z-index:30;top:calc(100% + 5px);right:0;left:0;display:none;max-height:210px;padding:5px;overflow-y:auto;border:1px solid #c9d9d3;border-radius:9px;background:#fff;box-shadow:0 12px 30px rgba(18,57,45,.18);scrollbar-width:thin}.tourist-search-select.open-up .tourist-search-menu{top:auto;bottom:calc(100% + 5px)}.tourist-search-select.open .tourist-search-menu{display:block}.tourist-search-option{display:block;width:100%;padding:8px 9px;border:0;border-radius:6px;background:#fff;color:#29483f;font:inherit;font-size:.72rem;text-align:left;cursor:pointer}.tourist-search-option:hover,.tourist-search-option.active{background:#e8f4ef;color:#17614d}.tourist-search-empty{padding:9px;color:#82938d;font-size:.68rem;text-align:center}.tourist-search-select.disabled .tourist-search-input{color:#8a9994;background:#f1f5f3;cursor:not-allowed}.tourist-search-select.disabled .tourist-search-toggle{cursor:not-allowed}
.tourist-profile-pdf-overlay{position:fixed;inset:0;z-index:12100;display:none;align-items:center;justify-content:center;padding:22px;background:rgba(6,29,23,.7);backdrop-filter:blur(5px)}.tourist-profile-pdf-overlay.show{display:flex}.tourist-profile-pdf-modal{display:flex;width:min(1180px,94vw);height:min(900px,calc(100vh - 44px));flex-direction:column;overflow:hidden;border:1px solid #cfdfd9;border-radius:18px;background:#f4f8f6;box-shadow:0 28px 85px rgba(4,29,22,.38)}.tourist-profile-pdf-header{display:flex;align-items:center;justify-content:space-between;gap:18px;padding:15px 18px;border-bottom:1px solid #d8e5e0;background:linear-gradient(135deg,#fbfdfc,#e9f4f0)}.tourist-profile-pdf-heading{display:flex;align-items:center;gap:12px;min-width:0}.tourist-profile-pdf-heading img{width:44px;height:44px;object-fit:contain}.tourist-profile-pdf-heading span{display:block;color:#26745f;font-size:.58rem;font-weight:850;letter-spacing:.11em}.tourist-profile-pdf-heading h3{margin:2px 0;color:#173e32;font-size:1.05rem}.tourist-profile-pdf-heading p{margin:0;color:#70837c;font-size:.67rem}.tourist-profile-pdf-header-actions{display:flex;align-items:center;gap:11px;margin-left:auto}.tourist-profile-pdf-meta{display:flex;flex-direction:column;align-items:flex-end;gap:2px;padding-right:3px}.tourist-profile-pdf-meta strong{color:#294b40;font-size:.68rem}.tourist-profile-pdf-meta span{color:#82918c;font-size:.56rem}.tourist-profile-pdf-header-actions>a{display:inline-flex;min-height:36px;align-items:center;padding:8px 13px;border-radius:8px;background:#26745f;color:#fff;font-size:.68rem;font-weight:850;text-decoration:none;box-shadow:0 5px 12px rgba(38,116,95,.16)}.tourist-profile-pdf-close{display:grid;width:36px;height:36px;flex:none;place-items:center;padding:0;border:1px solid #ccdcd6;border-radius:9px;background:#fff;color:#46645a;font-size:23px;cursor:pointer}.tourist-profile-pdf-close:hover{color:#145a45;background:#e4f1ec}.tourist-profile-pdf-body{position:relative;min-height:0;flex:1;padding:10px;background:#e9efed}.tourist-profile-pdf-body iframe{width:100%;height:100%;border:0;border-radius:9px;background:#3d3d3d;transition:opacity .18s ease}.tourist-profile-pdf-body.is-loading iframe{opacity:0}.tourist-profile-pdf-loading{position:absolute;inset:10px;z-index:2;display:none;place-items:center;border-radius:9px;background:#f7faf9;color:#315e51;text-align:center}.tourist-profile-pdf-body.is-loading .tourist-profile-pdf-loading{display:grid}.tourist-profile-pdf-loading-content{display:grid;justify-items:center;gap:13px}.tourist-profile-pdf-spinner{width:46px;height:46px;border:4px solid #d5e7e0;border-top-color:#26745f;border-radius:50%;animation:touristPdfSpin .8s linear infinite}.tourist-profile-pdf-loading strong{font-size:.84rem}.tourist-profile-pdf-loading span{color:#71867f;font-size:.66rem}@keyframes touristPdfSpin{to{transform:rotate(360deg)}}.tourist-profile-pdf-open{overflow:hidden}
@media(max-width:760px){.tourist-modal-user{padding:0;align-items:stretch}.tourist-modal-content-user.extended{max-height:100vh;border-radius:0}.tourist-form-summary{align-items:flex-start;flex-direction:column;gap:4px}.tourist-row-grid{grid-template-columns:1fr 1fr}.tourist-field.name,.tourist-field.phone,.tourist-field.address{grid-column:1/-1}#touristRowsUser{max-height:none}.tourist-modal-footer-user{position:sticky;bottom:0}.tourist-address-overlay{padding:0;align-items:flex-end}.tourist-address-modal{max-height:100vh;border-radius:17px 17px 0 0}.tourist-address-grid{grid-template-columns:1fr}.tourist-address-field.full{grid-column:auto}.tourist-profile-pdf-overlay{padding:0}.tourist-profile-pdf-modal{width:100%;height:100vh;border:0;border-radius:0}.tourist-profile-pdf-header{padding:11px 12px}.tourist-profile-pdf-heading img{width:38px;height:38px}.tourist-profile-pdf-heading p,.tourist-profile-pdf-meta{display:none}.tourist-profile-pdf-header-actions{gap:7px}.tourist-profile-pdf-header-actions>a{min-height:34px;padding:7px 10px}.tourist-profile-pdf-close{width:34px;height:34px}.tourist-profile-pdf-body{padding:6px}}

.add-more-user {
  width: fit-content;
  margin-top: 10px;
}

.rating {
  display: flex;
  flex-direction: row-reverse;
  justify-content: flex-start;
  gap: 5px;
}

.rating input {
  display: none;
}

.rating label {
  font-size: 28px;
  color: #ccc;
  cursor: pointer;
  transition: 0.2s;
}

.rating input:checked ~ label,
.rating label:hover,
.rating label:hover ~ label {
  color: gold;
}

.profile-avatar {
  width: 90px;
  height: 90px;
  border-radius: 50%;
  object-fit: cover;
  border: 3px solid #e2e8f0;
}

.dropzone {
  width: 100% !important;
  max-width: 860px !important;

  height: 260px !important;   /* 🔥 forces rectangle */
  min-height: 160px !important;

  border: 2px dashed #cbd5e1;
  border-radius: 14px;

  padding: 0; /* important so height stays consistent */

  display: flex;
  flex-direction: column;
  justify-content: center;
  align-items: center;

  text-align: center;
  cursor: pointer;

  background: #f8fafc;
  transition: 0.25s ease;
}

.dropzone:hover {
  border-color: #7cc2b1;
  background: #f1f5f9;
}

.dz-content {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 6px;
  color: #475569;
}

.dz-icon {
  font-size: 32px;
}

.dz-text {
  margin: 0;
  font-weight: 600;
}

.dz-sub {
  font-size: 12px;
  color: #94a3b8;
}

.crop-modal {
  position: fixed;
  inset: 0;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 20px;
  visibility: hidden;
  opacity: 0;
  pointer-events: none;
  background: rgba(7, 30, 24, 0.72);
  backdrop-filter: blur(6px);
  transition: opacity .2s ease, visibility .2s ease;
  z-index: 100600;
}
.crop-modal.is-open{visibility:visible;opacity:1;pointer-events:auto}
body.crop-modal-open{overflow:hidden}
body.crop-modal-open .main{overflow:hidden}

.crop-container {
  width: min(540px, 100%);
  min-height: 0;
  max-height: calc(100vh - 40px);
  display:flex;
  flex-direction:column;
  background: #ffffff;
  border:1px solid #d5e3de;
  border-radius: 18px;
  box-shadow: 0 28px 80px rgba(0,0,0,0.36);
  overflow: hidden;
  animation: pop 0.2s ease;
}

@keyframes pop {
  from { transform: scale(0.95); opacity: 0; }
  to { transform: scale(1); opacity: 1; }
}

/* HEADER */
.crop-header {
  display: grid;
  grid-template-columns:42px minmax(0,1fr) 34px;
  gap:11px;
  align-items: center;
  padding: 16px 18px;
  border-bottom: 1px solid #e2e8f0;
}
.crop-header-icon{width:42px;height:42px;display:grid;place-items:center;border-radius:11px;color:#1f7058;background:#e8f5f0}
.crop-header-icon svg{width:20px;height:20px}
.crop-header-copy{min-width:0}

.crop-header h3 {
  margin: 0;
  font-size: 15px;
  font-weight: 700;
  color: #173c31;
}
.crop-header p{margin:4px 0 0;color:#73857e;font-size:10px;line-height:1.4}

.crop-close {
  width:34px;
  height:34px;
  display:grid;
  place-items:center;
  padding:0;
  border: 0;
  border-radius:9px;
  background: #eef4f2;
  font-size: 0;
  cursor: pointer;
  color: #567067;
}
.crop-close:hover{background:#e1ece8;color:#174f40}
.crop-close::before{content:"\00D7";font-size:24px;line-height:1}

/* BODY */
.crop-body {
  min-height:0;
  padding: 16px 18px 13px;
  background: #edf3f1;
  overflow-y:auto;
}
.crop-stage{
  position:relative;
  width:100%;
  height:min(52vh,410px);
  min-height:280px;
  overflow: hidden;
  border:1px solid #cbd9d4;
  border-radius:13px;
  background:#202825;
}

.crop-stage img {
  max-width: 100%;
  display: block;
}
.crop-stage .cropper-container{width:100%!important;height:100%!important}
.crop-stage .cropper-view-box{border-radius:50%;outline:2px solid rgba(255,255,255,.95);outline-offset:-2px;box-shadow:0 0 0 9999px rgba(4,15,12,.44)}
.crop-stage .cropper-face{border-radius:50%;background:transparent}
.crop-stage .cropper-line,.crop-stage .cropper-point,.crop-stage .cropper-dashed,.crop-stage .cropper-center{display:none}
.crop-tools{display:flex;align-items:center;justify-content:center;gap:7px;margin-top:11px}
.crop-tool-button{min-width:38px;height:34px;padding:0 10px;border:1px solid #cadad4;border-radius:8px;display:inline-flex;align-items:center;justify-content:center;color:#365f52;background:#fff;font:inherit;font-size:12px;font-weight:800;cursor:pointer}
.crop-tool-button:hover{border-color:#91b9ab;background:#e7f2ee}
.crop-tool-button.reset{min-width:auto;padding:0 13px;font-size:10px}
.crop-help{margin:8px 0 0;color:#70837c;font-size:9px;line-height:1.45;text-align:center}

/* FOOTER */
.crop-footer {
  display: flex;
  justify-content: flex-end;
  gap: 10px;
  padding: 13px 18px;
  border-top: 1px solid #e2e8f0;
  background:#fff;
}
.crop-footer .crop-cancel,.crop-footer .crop-use{min-height:40px;padding:9px 15px;border-radius:9px;font:inherit;font-size:11px;font-weight:800;cursor:pointer}
.crop-footer .crop-cancel{border:1px solid #c8d8d2!important;background:#edf3f1!important;color:#294f43!important;box-shadow:none!important}
.crop-footer .crop-cancel:hover{border-color:#a9c5bb!important;background:#e1ece8!important}
.crop-footer .crop-use{border:1px solid var(--primary);background:var(--primary);color:#fff;box-shadow:0 5px 12px rgba(46,125,102,.18)}
.crop-footer .crop-use:hover{background:#246852}
.crop-footer .crop-use:disabled{border-color:#9db4ac;background:#9db4ac;box-shadow:none;cursor:not-allowed}
@media(max-width:560px){.crop-modal{align-items:flex-end;padding:0}.crop-container{width:100%;max-height:96vh;border-radius:18px 18px 0 0}.crop-stage{height:min(48vh,380px);min-height:250px}.crop-footer .crop-cancel,.crop-footer .crop-use{flex:1}}

/* BUTTONS */
.btn-primary {
  background: var(--primary);
  color: white;
  border: none;
  padding: 8px 14px;
  border-radius: 8px;
  cursor: pointer;
  font-weight: 500;
}

.btn-primary:hover {
  background: #246852;
}

.btn-secondary {
  background: #e2e8f0;
  color: #0f172a;
  border: none;
  padding: 8px 14px;
  border-radius: 8px;
  cursor: pointer;
}

.btn-secondary:hover {
  background: #cbd5e1;
}

.preview-img {
  width: 90px;
  height: 90px;
  border-radius: 50%;
  object-fit: cover;
  display: block;
  margin: 0 auto 10px;
  border: 2px solid #e2e8f0;
}

/* --- FORMAL BOOKINGS SECTION REFRESH --- */

/* Page Header */
.page-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  flex-wrap: wrap;
  gap: 16px;
  margin-bottom: 24px;
  padding-bottom: 16px;
  border-bottom: 1px solid var(--border);
}
.header-titles {
  display: flex;
  align-items: center;
  gap: 12px;
}
.header-icon {
  width: 28px;
  height: 28px;
  color: var(--primary);
}
.header-titles h3 {
  margin: 0;
  font-size: 1.4rem;
  color: var(--text);
  font-weight: 700;
}
.header-titles p {
  margin: 4px 0 0;
  color: #6b7280;
  font-size: 0.95rem;
}

/* Stat Badges */
.header-stats {
  display: flex;
  gap: 10px;
  flex-wrap: wrap;
}
.stat-badge {
  display: flex;
  align-items: center;
  gap: 6px;
  padding: 6px 14px;
  border-radius: 6px;
  font-size: 0.85rem;
  font-weight: 600;
  border: 1px solid #e5e7eb;
  background: var(--card);
  color: var(--text);
}
.stat-badge svg {
  width: 14px;
  height: 14px;
}
.stat-accepted svg { color: var(--primary); }
.stat-pending svg { color: #f59e0b; }
.stat-hotel svg { color: #0ea5e9; }

/* Empty State */
.empty-state {
  text-align: center;
  padding: 60px 20px;
  background: var(--card);
  border: 1px dashed #cbd5e1;
  border-radius: 10px;
  color: #64748b;
}
.empty-state svg {
  width: 48px;
  height: 48px;
  color: #cbd5e1;
  margin-bottom: 16px;
}
.empty-state h4 {
  margin: 0 0 8px;
  color: var(--text);
  font-size: 1.1rem;
}

/* Formal Cards */
.formal-card {
  background: var(--card);
  border: 1px solid var(--border);
  border-radius: 8px;
  margin-bottom: 24px;
  overflow: hidden;
  box-shadow: 0 1px 3px rgba(0,0,0,0.02);
}
.formal-card-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 16px 20px;
  background: #f8fafc;
  border-bottom: 1px solid var(--border);
}
.title-group {
  display: flex;
  align-items: center;
  gap: 10px;
}
.title-group svg {
  width: 18px;
  height: 18px;
  color: var(--primary);
}
.title-group h4 {
  margin: 0;
  font-size: 1.05rem;
  color: var(--text);
}
.count-pill {
  background: var(--primary);
  color: #fff;
  padding: 4px 10px;
  border-radius: 999px;
  font-size: 0.75rem;
  font-weight: 700;
  letter-spacing: 0.5px;
}
.count-pill.pill-warning {
  background: #f59e0b;
}

/* Formal Tables */
.table-responsive {
  overflow-x: auto;
}
.formal-table {
  width: 100%;
  border-collapse: collapse;
}
.formal-table th {
  background: #ffffff;
  color: #64748b;
  font-size: 0.75rem;
  text-transform: uppercase;
  letter-spacing: 0.5px;
  padding: 12px 20px;
  border-bottom: 2px solid var(--border);
  text-align: left;
}
.formal-table td {
  padding: 14px 20px;
  border-bottom: 1px solid var(--border);
  font-size: 0.9rem;
  color: #334155;
  vertical-align: middle;
}
.formal-table tr:last-child td {
  border-bottom: none;
}
.formal-table tr:hover {
  background: #f8fafc;
}

/* Typography & Tags */
.text-muted {
  color: #94a3b8;
  font-size: 0.85rem;
}
.tag-outline {
  border: 1px solid #e2e8f0;
  padding: 4px 8px;
  border-radius: 6px;
  font-size: 0.8rem;
  color: #475569;
  text-transform: capitalize;
  background: #f8fafc;
}

/* Guest Grouping */
.guest-group {
  display: flex;
  gap: 8px;
  flex-wrap: wrap;
  align-items: center;
}
.guest-group span {
  font-size: 0.8rem;
  color: #64748b;
}
.guest-group .highlight {
  background: var(--muted);
  color: var(--primary);
  padding: 2px 8px;
  border-radius: 4px;
  font-weight: 600;
}
.guest-group .highlight-warning {
  background: #fef3c7;
  color: #b45309;
  padding: 2px 8px;
  border-radius: 4px;
  font-weight: 600;
}

/* Dates & Payments */
.date-group {
  display: flex;
  flex-direction: column;
  gap: 4px;
  font-size: 0.85rem;
}
.payment-group {
  display: flex;
  flex-direction: column;
  gap: 4px;
}
.payment-status {
  font-size: 0.75rem;
  font-weight: 600;
}
.payment-status.paid { color: var(--primary); }
.payment-status.unpaid { color: #ef4444; }
.payment-status.partial { color: #f59e0b; }

/* Status Pills */
.status-pill {
  display: inline-flex;
  padding: 4px 10px;
  border-radius: 999px;
  font-size: 0.75rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.5px;
}
.status-pill.accepted { background: #dcfce7; color: #166534; }
.status-pill.pending { background: #fef3c7; color: #92400e; }
.status-pill.confirmed { background: var(--primary); color: #fff; }

/* Action Buttons */
.btn-action {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 6px 12px;
  border-radius: 6px;
  border: 1px solid var(--primary);
  background: var(--primary);
  color: #fff;
  font-size: 0.8rem;
  font-weight: 600;
  cursor: pointer;
  transition: all 0.2s ease;
}
.btn-action:hover {
  background: #245447;
  border-color: #245447;
}
.btn-action svg {
  width: 14px;
  height: 14px;
}
.btn-secondary {
  background: #ffffff;
  color: var(--primary);
  border-color: #cbd5e1;
}
.btn-secondary:hover {
  background: #f1f5f9;
  color: #1e293b;
  border-color: #94a3b8;
}

.nav-link-row{display:flex!important;align-items:center;justify-content:space-between;gap:10px}
.nav-count{min-width:24px;height:24px;padding:0 7px;display:inline-flex;align-items:center;justify-content:center;border-radius:999px;background:rgba(255,255,255,.14);font-size:.75rem}
.nav-links a.active .nav-count{background:#fff;color:#1f6b57}

/* Tourist profile sidebar */
.sidebar{isolation:isolate;z-index:100;width:270px;padding:14px 14px 16px;gap:0;overflow-y:auto;overflow-x:hidden;border-right:1px solid rgba(173,215,199,.2);background:linear-gradient(180deg,#103b2e 0%,#0b2f25 52%,#08271f 100%);box-shadow:10px 0 30px rgba(7,39,30,.08);scrollbar-width:thin;scrollbar-color:rgba(158,207,188,.32) transparent}
.sidebar>*{position:relative;z-index:1}
.sidebar::before,.sidebar::after{display:none}
.sidebar-brand{min-height:58px;display:flex;align-items:center;gap:10px;padding:2px 6px 12px;border-bottom:1px solid rgba(213,235,226,.15);color:#fff;text-decoration:none}
.sidebar-brand-row{min-height:58px;display:flex;align-items:center;gap:7px;border-bottom:1px solid rgba(213,235,226,.15)}
.sidebar-brand-row .sidebar-brand{min-width:0;flex:1;padding:2px 0 12px;border-bottom:0}
.sidebar-brand-row .sidebar-brand-copy img{max-width:100%}
.sidebar-brand-back{width:34px;height:34px;display:grid;place-items:center;flex:0 0 34px;margin:0 0 10px;padding:0;border:1px solid rgba(186,222,208,.16);border-radius:9px;background:rgba(255,255,255,.06);color:#d7eee5;cursor:pointer;transition:.18s ease}
.sidebar-brand-back:hover{border-color:rgba(157,210,190,.35);background:rgba(255,255,255,.12);color:#fff;transform:translateX(-1px)}
.sidebar-brand-back svg{width:17px;height:17px}
.sidebar-brand-mark{width:43px;height:43px;flex:none;object-fit:contain;filter:drop-shadow(0 5px 10px rgba(0,0,0,.18))}
.sidebar-brand-copy{min-width:0;display:grid;gap:2px}
.sidebar-brand-copy img{width:142px;height:29px;display:block;object-fit:contain;object-position:left center}
.sidebar-brand-copy small{color:#9fc6b7;font-size:.54rem;font-weight:800;letter-spacing:.16em;text-transform:uppercase}
.sidebar-back{width:100%;min-height:52px;margin:11px 0 13px;padding:8px;gap:10px;border:1px solid rgba(186,222,208,.15);border-radius:11px;background:rgba(255,255,255,.045);color:#edf8f4;text-align:left}
.sidebar-back:hover{border-color:rgba(157,210,190,.3);background:rgba(255,255,255,.085);transform:translateY(-1px)}
.sidebar-back-icon{width:34px;height:34px;display:grid;place-items:center;flex:none;border-radius:9px;color:#c7e7db;background:rgba(126,196,170,.13)}
.sidebar-back-icon svg{width:17px;height:17px}
.sidebar-back>span:last-child{min-width:0;display:grid;gap:2px}
.sidebar-back strong{font-size:.72rem;font-weight:800}
.sidebar-back small{color:#99b9ad;font-size:.56rem;font-weight:600}
.profile-area{position:relative;margin:0 0 14px;padding:15px 12px 14px;border:0;background:transparent;box-shadow:none;text-align:center}
.sidebar-avatar-wrap{position:relative;width:82px;height:82px;margin:0 auto 9px}
.profile-img{width:82px;height:82px;display:block;border:3px solid rgba(255,255,255,.84);border-radius:50%;object-fit:cover;box-shadow:0 0 0 2px rgba(114,190,163,.38),0 8px 18px rgba(0,0,0,.2)}
.sidebar-avatar-wrap>span{position:absolute;right:2px;bottom:5px;width:14px;height:14px;border:3px solid #164335;border-radius:50%;background:#55d49f;box-shadow:0 0 0 2px rgba(85,212,159,.13)}
.sidebar-account-label{display:block;margin-bottom:4px;color:#8fc4b1;font-size:.53rem;font-weight:850;letter-spacing:.13em;text-transform:uppercase}
.profile-area h3{margin:0;color:#fff;font-size:.92rem;font-weight:800;letter-spacing:-.01em}
.profile-area p{margin:4px auto 0;color:#bdd5cc;font-size:.63rem;line-height:1.4}
.sidebar-account-state{display:inline-flex;align-items:center;gap:5px;margin-top:9px;padding:4px 8px;border:1px solid rgba(104,198,164,.2);border-radius:999px;color:#c6eadc;background:rgba(65,157,124,.12);font-size:.53rem;font-weight:800;text-transform:uppercase;letter-spacing:.04em}
.sidebar-account-state i{width:5px;height:5px;border-radius:50%;background:#5cdda8}
.sidebar-nav-title{display:block;padding:0 8px;color:#7fa99a;font-size:.51rem;font-weight:850;letter-spacing:.13em;text-transform:uppercase}
.nav-links{margin-top:7px;gap:5px}
.nav-links a{min-height:45px;padding:6px 8px;border:1px solid transparent;border-radius:10px;color:#c8dbd4;font-size:.82rem;font-weight:750;box-shadow:none}
.nav-links a:hover{border-color:rgba(180,218,203,.12);background:rgba(255,255,255,.06);color:#fff;transform:none}
.nav-item-label{min-width:0;flex:1;gap:10px}
.nav-item-label svg{box-sizing:content-box;width:17px;height:17px;padding:7px;flex:none;border-radius:8px;color:#b4d8ca;background:rgba(255,255,255,.055);opacity:1;transition:.18s ease}
.nav-links a.active,html[data-profile-tab="profile"] .nav-links a[data-section="profile"],html[data-profile-tab="bookings"] .nav-links a[data-section="bookings"],html[data-profile-tab="cancel-bookings"] .nav-links a[data-section="cancel-bookings"],html[data-profile-tab="favorites"] .nav-links a[data-section="favorites"],html[data-profile-tab="complaints"] .nav-links a[data-section="complaints"],html[data-profile-tab="history"] .nav-links a[data-section="history"]{border-color:rgba(149,211,187,.22);background:linear-gradient(90deg,rgba(84,170,139,.25),rgba(255,255,255,.08));color:#fff;box-shadow:inset 3px 0 0 #78d4b2,0 5px 12px rgba(0,0,0,.08)}
.nav-links a.active .nav-item-label svg,html[data-profile-tab="profile"] .nav-links a[data-section="profile"] svg,html[data-profile-tab="bookings"] .nav-links a[data-section="bookings"] svg,html[data-profile-tab="cancel-bookings"] .nav-links a[data-section="cancel-bookings"] svg,html[data-profile-tab="favorites"] .nav-links a[data-section="favorites"] svg,html[data-profile-tab="complaints"] .nav-links a[data-section="complaints"] svg,html[data-profile-tab="history"] .nav-links a[data-section="history"] svg{color:#fff;background:rgba(119,210,177,.2)}
.nav-count{min-width:22px;height:22px;padding:0 6px;border:1px solid rgba(177,220,204,.13);background:rgba(255,255,255,.09);font-size:.62rem}
.logout{margin-top:auto;padding:13px 4px 0;border-top:1px solid rgba(207,232,222,.14);text-align:left}
.sidebar-session-status{display:flex;align-items:center;justify-content:space-between;gap:8px;padding:0 4px 9px;color:#91afa4;font-size:.52rem}
.sidebar-session-status>span{display:flex;align-items:center;gap:5px;font-weight:700}
.sidebar-session-status i{width:5px;height:5px;border-radius:50%;background:#55d49f}
.sidebar-session-status small{color:#77988c;font-size:.49rem}
.sidebar-logout-button{width:100%;min-height:48px;display:grid;grid-template-columns:32px minmax(0,1fr);align-items:center;gap:9px;padding:7px 9px;border:1px solid rgba(230,188,188,.13);border-radius:10px;color:#f3dddd;background:rgba(168,64,64,.065);font:inherit;text-align:left;cursor:pointer;transition:.18s ease}
.sidebar-logout-button:hover{border-color:rgba(235,174,174,.25);background:rgba(174,68,68,.13);color:#fff}
.sidebar-logout-button>svg{width:17px;height:17px;margin:auto;color:#eabbbb}
.sidebar-logout-button>span{display:grid;gap:2px}
.sidebar-logout-button strong{font-size:.68rem;font-weight:800}
.sidebar-logout-button small{color:#aa918e;font-size:.52rem}
.favorites-section{padding:0!important;overflow:visible;border-radius:0!important}
.favorites-hero{display:flex;align-items:center;justify-content:space-between;gap:24px;padding:26px 28px;background:linear-gradient(125deg,#123d30 0%,#226f59 72%,#2e8369 100%);color:#fff}
.favorites-hero-copy{display:flex;align-items:center;gap:16px}
.favorites-hero-icon{width:50px;height:50px;display:grid;place-items:center;border-radius:15px;background:rgba(255,255,255,.14);border:1px solid rgba(255,255,255,.18)}
.favorites-hero-icon svg{width:25px;height:25px;fill:#fff}
.favorites-hero h3{margin:0 0 5px;font-size:1.45rem}
.favorites-hero p{margin:0;color:rgba(255,255,255,.78);font-size:.92rem}
.favorites-total{min-width:94px;padding:11px 16px;border-radius:13px;background:rgba(255,255,255,.13);border:1px solid rgba(255,255,255,.18);text-align:center}
.favorites-total strong{display:block;font-size:1.45rem}.favorites-total span{font-size:.76rem;color:rgba(255,255,255,.78);text-transform:uppercase;letter-spacing:.07em}
.favorites-toolbar{display:flex;align-items:center;justify-content:space-between;gap:16px;padding:18px 24px;border-bottom:1px solid #e7efec;background:#fff}
.favorites-filters{display:flex;gap:8px;flex-wrap:wrap}
.favorite-filter{border:1px solid #dce8e3;background:#f8fbfa;color:#456158;padding:9px 13px;border-radius:999px;font-weight:700;cursor:pointer;transition:.18s ease}
.favorite-filter:hover,.favorite-filter.active{background:#e4f3ed;border-color:#b9d9cd;color:#155844}
.favorite-filter span{margin-left:5px;color:#71877f;font-size:.76rem}
.favorites-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:18px;padding:24px;background:#f7faf9}
.favorite-profile-card{position:relative;display:flex;flex-direction:column;min-width:0;background:#fff;border:1px solid #e0eae6;border-radius:15px;overflow:hidden;box-shadow:0 7px 20px rgba(22,65,52,.07);transition:transform .2s ease,box-shadow .2s ease}
.favorite-profile-card[hidden]{display:none!important}
.favorite-profile-card:hover{transform:translateY(-3px);box-shadow:0 13px 28px rgba(22,65,52,.12)}
.favorite-card-image{height:180px;background:#dfeae6;overflow:hidden}
.favorite-card-image img{width:100%;height:100%;display:block;object-fit:cover;transition:transform .28s ease}
.favorite-profile-card:hover .favorite-card-image img{transform:scale(1.035)}
.favorite-card-body{display:flex;flex-direction:column;flex:1;padding:16px}
.favorite-kind{align-self:flex-start;margin-bottom:9px;padding:5px 9px;border-radius:999px;background:#eaf5f1;color:#176149;font-size:.69rem;font-weight:800;text-transform:uppercase;letter-spacing:.05em}
.favorite-card-body h4{margin:0;color:#14251f;font-size:1.04rem;line-height:1.3}
.favorite-card-subtitle{margin:7px 0 13px;color:#6b7d76;font-size:.84rem;line-height:1.45}
.favorite-card-footer{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-top:auto;padding-top:13px;border-top:1px solid #edf2f0}
.favorite-price{font-size:.92rem;color:#183d32;font-weight:800}.favorite-price.is-muted{font-size:.78rem;color:#80918b;font-weight:600}
.favorite-open{display:inline-flex;align-items:center;gap:6px;padding:8px 11px;border-radius:9px;background:#edf6f2;color:#176149;text-decoration:none;font-size:.8rem;font-weight:800}
.favorite-open:hover{background:#dcefe7}.favorite-open svg{width:14px;height:14px;fill:none;stroke:currentColor;stroke-width:2}
.favorite-profile-card .favorite-toggle-card{top:12px;right:12px}
.favorites-empty{grid-column:1/-1;padding:58px 24px;text-align:center;background:#fff;border:1px dashed #cbded6;border-radius:15px}
.favorites-empty svg{width:44px;height:44px;fill:none;stroke:#6a9c8b;stroke-width:1.5}
.favorites-empty h4{margin:12px 0 5px;color:#193c31;font-size:1.1rem}.favorites-empty p{margin:0 0 16px;color:#74857f}
.favorites-empty a{display:inline-flex;padding:10px 15px;border-radius:9px;background:#24745d;color:#fff;text-decoration:none;font-weight:700}
.favorites-panel{overflow:hidden;border:1px solid #d6e3de;border-radius:14px;background:#fff;box-shadow:0 7px 22px rgba(18,59,47,.045)}
.favorites-section .page-header .stat-badge svg{color:var(--primary)}
.favorites-section .favorites-toolbar{min-height:61px;padding:11px 17px;background:#fff;border-bottom:1px solid #e0e9e6}
.favorites-section .favorites-filters{gap:7px}
.favorites-section .favorite-filter{min-height:37px;padding:7px 12px;border-color:#d5e2dd;border-radius:9px;background:#fafcfb;color:#4d665d;font-size:.75rem;font-weight:750}
.favorites-section .favorite-filter:hover{border-color:#bdd5cc;background:#f0f7f4;color:#1d654f}
.favorites-section .favorite-filter.active{border-color:#9fc9b9;background:#e5f3ed;color:#155844;box-shadow:0 0 0 2px rgba(46,125,102,.06)}
.favorites-section .favorite-filter span{display:inline-grid;min-width:19px;height:19px;margin-left:6px;place-items:center;border-radius:999px;background:#edf3f1;color:#60766e;font-size:.63rem}
.favorites-section .favorite-filter.active span{background:#fff;color:#176047}
.favorites-section .favorites-grid{gap:16px;padding:18px;background:#f8fbfa}
.favorites-section .favorite-profile-card{border-color:#dbe6e2;border-radius:12px;box-shadow:0 5px 15px rgba(22,65,52,.055)}
.favorites-section .favorite-profile-card:hover{transform:translateY(-2px);box-shadow:0 10px 22px rgba(22,65,52,.1)}
.favorites-section .favorite-card-image{height:170px}
.favorites-section .favorite-card-body{padding:14px}
.favorites-section .favorite-kind{margin-bottom:8px;padding:4px 8px;font-size:.61rem}
.favorites-section .favorite-card-body h4{font-size:.92rem}
.favorites-section .favorite-card-subtitle{margin:6px 0 12px;font-size:.75rem}
.favorites-section .favorite-price{font-size:.82rem}
.favorites-section .favorite-open{padding:7px 10px;border-radius:7px;font-size:.71rem}
.complaints-section{margin-top:0!important}
.complaint-header-actions{display:flex;align-items:center;gap:8px}
.complaint-header-submit{display:inline-flex;align-items:center;gap:7px;min-height:32px;padding:7px 11px;border:1px solid #226e57;border-radius:8px;background:#267c63;color:#fff;font-size:.69rem;font-weight:800;white-space:nowrap;cursor:pointer;box-shadow:0 4px 10px rgba(28,103,79,.15);transition:background .18s ease,transform .18s ease,box-shadow .18s ease}
.complaint-header-submit svg{width:14px;height:14px;fill:none;stroke:currentColor;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}
.complaint-header-submit:hover{background:#185f49;transform:translateY(-1px);box-shadow:0 6px 13px rgba(28,103,79,.2)}
@media(max-width:680px){.complaints-section .page-header{align-items:flex-start}.complaint-header-actions{align-items:flex-end;flex-direction:column-reverse}.complaint-header-submit{padding:7px 9px;font-size:.64rem}}
.complaints-section .sr-only{position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0}
.profile-stat-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:11px;margin-bottom:15px}
.profile-stat-card{position:relative;min-height:92px;display:grid;grid-template-columns:42px minmax(0,1fr);align-items:center;gap:11px;overflow:hidden;padding:14px;border:1px solid rgba(255,255,255,.13);border-radius:12px;color:#fff;background:linear-gradient(135deg,#123f32,#1f7058);box-shadow:0 7px 18px rgba(18,65,50,.14)}
.profile-stat-card:nth-child(2){background:linear-gradient(135deg,#18513f,#287c60)}.profile-stat-card:nth-child(3){background:linear-gradient(135deg,#1b5945,#31866a)}.profile-stat-card:nth-child(4){background:linear-gradient(135deg,#22644f,#3b9173)}
.profile-stat-card::after{content:"";position:absolute;width:82px;height:82px;right:-31px;top:-42px;border:1px solid rgba(255,255,255,.12);border-radius:50%;box-shadow:0 0 0 19px rgba(255,255,255,.035)}
.profile-stat-card-icon{width:42px;height:42px;display:grid;place-items:center;border:1px solid rgba(255,255,255,.16);border-radius:11px;color:#d8f3e8;background:rgba(255,255,255,.1)}.profile-stat-card-icon svg{width:20px;height:20px;fill:none;stroke:currentColor;stroke-width:1.7;stroke-linecap:round;stroke-linejoin:round}
.profile-stat-card-copy{min-width:0;display:grid;grid-template-columns:minmax(0,1fr) auto;align-items:end;gap:1px 8px}.profile-stat-card-copy small{color:#b8ddd0;font-size:.57rem;font-weight:800;letter-spacing:.05em;text-transform:uppercase}.profile-stat-card-copy strong{grid-row:1/3;grid-column:2;font-size:1.5rem;line-height:1}.profile-stat-card-copy p{margin:0;overflow:hidden;color:#d2e8df;font-size:.61rem;text-overflow:ellipsis;white-space:nowrap}
.complaint-total-badge{border-color:#cce2d9!important;color:#24644f!important;background:#eaf5f0!important}
.complaint-summary-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:11px;margin-bottom:15px}
.complaint-summary-grid article{position:relative;display:grid;grid-template-columns:42px minmax(0,1fr);align-items:center;gap:11px;min-width:0;min-height:92px;overflow:hidden;padding:14px;border:1px solid rgba(255,255,255,.13);border-radius:12px;color:#fff;background:linear-gradient(135deg,#123f32,#1f7058);box-shadow:0 7px 18px rgba(18,65,50,.14)}
.complaint-summary-grid article:nth-child(2){background:linear-gradient(135deg,#18513f,#287c60)}.complaint-summary-grid article:nth-child(3){background:linear-gradient(135deg,#1b5945,#31866a)}.complaint-summary-grid article:nth-child(4){background:linear-gradient(135deg,#22644f,#3b9173)}
.complaint-summary-grid article::after{content:"";position:absolute;width:82px;height:82px;right:-31px;top:-42px;border:1px solid rgba(255,255,255,.12);border-radius:50%;box-shadow:0 0 0 19px rgba(255,255,255,.035)}
.complaint-summary-icon{width:42px;height:42px;display:grid;place-items:center;border:1px solid rgba(255,255,255,.16);border-radius:11px;color:#d8f3e8;background:rgba(255,255,255,.1)}
.complaint-summary-icon svg{width:20px;height:20px;fill:none;stroke:currentColor;stroke-width:1.7;stroke-linecap:round;stroke-linejoin:round}
.complaint-summary-icon.is-submitted,.complaint-summary-icon.is-review,.complaint-summary-icon.is-resolved{color:#d8f3e8;background:rgba(255,255,255,.1)}
.complaint-summary-grid article div{min-width:0;display:grid;grid-template-columns:1fr auto;align-items:end;gap:1px 8px}
.complaint-summary-grid small{color:#b8ddd0;font-size:.59rem;font-weight:800;text-transform:uppercase;letter-spacing:.045em}.complaint-summary-grid strong{grid-row:1/3;grid-column:2;color:#fff;font-size:1.45rem;line-height:1}.complaint-summary-grid p{margin:0;color:#d2e8df;font-size:.61rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.complaint-report-panel{overflow:hidden;border:1px solid #d7e4df;border-radius:14px;background:#f7faf9;box-shadow:0 7px 22px rgba(18,59,47,.045)}
.complaint-report-toolbar{display:flex;align-items:center;justify-content:space-between;gap:14px;padding:12px 14px;border-bottom:1px solid #dee9e5;background:#fff}
.complaint-type-filters{display:flex;gap:6px;flex-wrap:wrap}.complaint-type-filters button{min-height:34px;padding:7px 11px;border:1px solid #d5e3de;border-radius:8px;color:#587068;background:#fafcfb;font-size:.68rem;font-weight:750;cursor:pointer}.complaint-type-filters button:hover,.complaint-type-filters button.active{border-color:#a9cfc0;color:#1c624d;background:#e7f4ef}.complaint-type-filters button span{display:inline-grid;min-width:18px;height:18px;margin-left:5px;place-items:center;border-radius:99px;color:#577269;background:#edf3f1;font-size:.57rem}.complaint-type-filters button.active span{color:#1d654f;background:#fff}
.complaint-report-tools{display:flex;align-items:center;gap:7px}.complaint-report-search{position:relative;display:block}.complaint-report-search svg{position:absolute;left:10px;top:50%;width:15px;transform:translateY(-50%);fill:none;stroke:#70867e;stroke-width:1.8}.complaint-report-search input,.complaint-status-filter select{height:35px;border:1px solid #d3e1dc;border-radius:8px;outline:0;color:#39564d;background:#fbfdfc;font-size:.68rem}.complaint-report-search input{width:220px;padding:8px 10px 8px 32px}.complaint-status-filter select{padding:7px 28px 7px 10px}.complaint-report-search input:focus,.complaint-status-filter select:focus{border-color:#6caa94;box-shadow:0 0 0 3px rgba(51,136,107,.09)}
.complaint-report-list{display:grid;gap:11px;padding:13px}.complaint-report-card{overflow:hidden;border:1px solid #dbe6e2;border-radius:12px;background:#fff;box-shadow:0 4px 13px rgba(21,64,51,.035);transition:border-color .18s,box-shadow .18s,transform .18s}.complaint-report-card:hover{border-color:#bfd6cd;box-shadow:0 8px 20px rgba(21,64,51,.07);transform:translateY(-1px)}
.complaint-report-card-head{display:grid;grid-template-columns:42px minmax(0,1fr) auto;align-items:center;gap:11px;padding:13px 14px}.complaint-report-type-icon{width:42px;height:42px;display:grid;place-items:center;border-radius:11px;color:#2b755e;background:#e7f4ef}.complaint-report-type-icon.is-incident{color:#9c6410;background:#fff1d5}.complaint-report-type-icon svg{width:21px;height:21px;fill:none;stroke:currentColor;stroke-width:1.7;stroke-linecap:round;stroke-linejoin:round}
.complaint-report-title{min-width:0}.complaint-report-title>div{display:flex;align-items:center;gap:6px;margin-bottom:4px;color:#4e7468;font-size:.56rem;font-weight:850;text-transform:uppercase;letter-spacing:.06em}.complaint-report-title>div i{width:3px;height:3px;border-radius:50%;background:#9db1aa}.complaint-report-title code{color:#798d86;font:750 .57rem Inter,sans-serif;letter-spacing:.035em}.complaint-report-title h4{margin:0 0 3px;overflow:hidden;color:#183b31;font-size:.87rem;text-overflow:ellipsis;white-space:nowrap}.complaint-report-title p{margin:0;color:#84948e;font-size:.61rem}
.complaint-status-pill{display:inline-flex;align-items:center;gap:6px;padding:6px 9px;border:1px solid transparent;border-radius:99px;font-size:.59rem;font-weight:800;white-space:nowrap}.complaint-status-pill i{width:6px;height:6px;border-radius:50%;background:currentColor}.complaint-status-pill.is-submitted{border-color:#efdcac;color:#97630b;background:#fff5dc}.complaint-status-pill.is-in_review{border-color:#c6d9ef;color:#386da9;background:#edf4fc}.complaint-status-pill.is-resolved{border-color:#bde0d2;color:#237157;background:#e7f5ef}.complaint-status-pill.is-dismissed{border-color:#d8dfdc;color:#6b7c76;background:#f1f4f3}
.complaint-report-overview{display:grid;grid-template-columns:1.05fr 1fr 1.3fr .55fr;border-top:1px solid #e8efec;border-bottom:1px solid #e8efec;background:#fafcfb}.complaint-report-overview>div{min-width:0;padding:9px 13px;border-right:1px solid #e5edea}.complaint-report-overview>div:last-child{border-right:0}.complaint-report-overview small{display:block;margin-bottom:3px;color:#81918b;font-size:.51rem;font-weight:800;text-transform:uppercase;letter-spacing:.055em}.complaint-report-overview strong{display:block;overflow:hidden;color:#405e55;font-size:.65rem;font-weight:700;text-overflow:ellipsis;white-space:nowrap}
.complaint-report-preview{display:-webkit-box;margin:0;padding:11px 14px 12px;overflow:hidden;color:#60736d;font-size:.68rem;line-height:1.55;-webkit-box-orient:vertical;-webkit-line-clamp:2}
.complaint-report-details{border-top:1px solid #e8efec}.complaint-report-details summary{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:9px 14px;color:#276d57;background:#fbfdfc;font-size:.64rem;font-weight:800;cursor:pointer;list-style:none}.complaint-report-details summary::-webkit-details-marker{display:none}.complaint-report-details summary svg{width:16px;fill:none;stroke:currentColor;stroke-width:2;transition:transform .2s}.complaint-report-details[open] summary svg{transform:rotate(180deg)}.complaint-report-details-body{display:grid;grid-template-columns:1fr 1fr;gap:10px;padding:13px 14px 15px;border-top:1px solid #e8efec;background:#f7faf9}.complaint-report-details-body section{padding:11px;border:1px solid #e0e9e6;border-radius:9px;background:#fff}.complaint-report-details-body section:first-child{grid-column:1/-1}.complaint-report-details-body small{display:block;margin-bottom:5px;color:#39705f;font-size:.54rem;font-weight:850;text-transform:uppercase;letter-spacing:.06em}.complaint-report-details-body p{margin:0;color:#546b63;font-size:.67rem;line-height:1.6}.complaint-report-contact{display:flex;align-items:center;justify-content:space-between;gap:12px;grid-column:1/-1;padding:9px 11px;border:1px solid #dce8e3;border-radius:8px;color:#70827c;background:#fff;font-size:.61rem}.complaint-report-contact strong{color:#2a6351;text-transform:capitalize}
.complaint-evidence-gallery{grid-column:1/-1;display:flex;gap:8px;flex-wrap:wrap}.complaint-evidence-gallery a{position:relative;width:104px;height:76px;overflow:hidden;border:2px solid #fff;border-radius:9px;box-shadow:0 3px 10px rgba(16,58,45,.15)}.complaint-evidence-gallery img{width:100%;height:100%;display:block;object-fit:cover;transition:transform .25s}.complaint-evidence-gallery span{position:absolute;inset:auto 0 0;padding:5px;color:#fff;background:linear-gradient(transparent,rgba(7,39,30,.82));font-size:.52rem;font-weight:750;text-align:center}.complaint-evidence-gallery a:hover img{transform:scale(1.06)}
.complaint-filter-empty{display:grid;place-items:center;padding:44px 20px;color:#72857e;text-align:center}.complaint-filter-empty[hidden]{display:none}.complaint-filter-empty svg{width:34px;height:34px;margin-bottom:9px;fill:none;stroke:#76a392;stroke-width:1.6}.complaint-filter-empty strong{margin-bottom:4px;color:#315c4e;font-size:.82rem}.complaint-filter-empty span{font-size:.66rem}
.complaints-placeholder{display:grid;place-items:center;min-height:330px;padding:48px 24px;border:1px dashed #bdd5cc;border-radius:14px;text-align:center;background:linear-gradient(145deg,#fff,#f4faf7)}.complaints-placeholder-icon{width:58px;height:58px;display:grid;place-items:center;margin-bottom:16px;border:1px solid #c6e1d6;border-radius:17px;color:#28775f;background:#e7f4ef;box-shadow:0 9px 22px rgba(29,103,79,.09)}.complaints-placeholder-icon svg{width:28px;height:28px;fill:none;stroke:currentColor;stroke-width:1.7;stroke-linecap:round;stroke-linejoin:round}.complaints-placeholder h4{margin:0 0 7px;color:#193c31;font-size:1.08rem}.complaints-placeholder p{max-width:490px;margin:0 0 15px;color:#71847d;font-size:.8rem;line-height:1.6}.complaints-placeholder a{padding:9px 14px;border-radius:8px;color:#fff;background:#26745d;text-decoration:none;font-size:.72rem;font-weight:750}
@media(max-width:1100px){.profile-stat-grid,.complaint-summary-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.complaint-report-overview{grid-template-columns:1fr 1fr}.complaint-report-overview>div:nth-child(2){border-right:0}.complaint-report-overview>div:nth-child(-n+2){border-bottom:1px solid #e5edea}}
@media(max-width:760px){.profile-stat-grid,.complaint-summary-grid{grid-template-columns:1fr 1fr}.profile-stat-card{min-height:84px;padding:11px}.complaint-report-toolbar{align-items:stretch;flex-direction:column}.complaint-report-tools{align-items:stretch;flex-direction:column}.complaint-report-search input,.complaint-status-filter select{width:100%}.complaint-report-card-head{grid-template-columns:38px minmax(0,1fr)}.complaint-report-type-icon{width:38px;height:38px}.complaint-status-pill{grid-column:2;width:max-content}.complaint-report-overview{grid-template-columns:1fr}.complaint-report-overview>div{border-right:0;border-bottom:1px solid #e5edea!important}.complaint-report-overview>div:last-child{border-bottom:0!important}.complaint-report-details-body{grid-template-columns:1fr}.complaint-report-details-body section{grid-column:1/-1}}
@media(max-width:480px){.profile-stat-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media(max-width:1050px){.favorites-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media(max-width:760px){.favorites-toolbar{align-items:flex-start;flex-direction:column}.favorites-grid{grid-template-columns:1fr;padding:16px}.favorites-total{min-width:0}.favorite-card-image{height:200px}.favorites-section .favorites-toolbar{padding:11px 13px}.favorites-section .favorites-filters{width:100%}.favorites-section .favorite-filter{flex:1 1 auto}}

/* Profile workspace refinements */
.main > .section{max-width:none;margin-inline:auto}
.profile-section{display:block}
.profile-section > * + *{margin-top:12px}
.main .page-header{
  min-height:58px;
  margin:0 -14px 14px!important;
  padding:9px 14px!important;
  border:1px solid #d8e6e0!important;
  border-top:0!important;
  border-right:0!important;
  border-left:0!important;
  border-radius:0!important;
  background:#fff!important;
  box-shadow:0 8px 18px rgba(11,44,35,.05)!important;
}
.profile-section .page-header{
  margin-bottom:0!important;
}
.profile-page-heading{display:flex;align-items:center;gap:14px}
.profile-heading-icon{
  width:34px;
  height:34px;
  display:grid;
  place-items:center;
  flex:none;
  border-radius:10px;
  background:linear-gradient(145deg,#e3f2ed,#f2f8f6);
  border:1px solid #cfe2db;
  color:var(--primary);
}
.profile-heading-icon svg{width:17px;height:17px}
.profile-eyebrow{display:block;margin-bottom:2px;color:#688078;font-size:.61rem;font-weight:800;letter-spacing:.11em;text-transform:uppercase}
.profile-page-heading h1{margin:0;color:#173c31;font-size:1.08rem;line-height:1.2;letter-spacing:-.015em}
.profile-page-heading p{margin:3px 0 0;color:var(--text-muted);font-size:.74rem}
.profile-status{
  display:inline-flex;
  align-items:center;
  gap:7px;
  padding:6px 9px;
  border:1px solid #cce2d9;
  border-radius:999px;
  background:#edf8f4;
  color:#176149;
  font-size:.68rem;
  font-weight:800;
}
.profile-status::before{content:"";width:7px;height:7px;border-radius:50%;background:#32a577;box-shadow:0 0 0 3px rgba(50,165,119,.13)}
.profile-summary-card{
  position:relative;
  display:grid;
  grid-template-columns:auto minmax(0,1fr) auto;
  gap:18px;
  padding:16px 20px;
  border:1px solid var(--border);
  border-radius:16px;
  background:#fff;
  box-shadow:0 12px 30px rgba(19,62,49,.07);
  overflow:hidden;
}
.profile-summary-card::after{
  content:"";
  position:absolute;
  width:180px;
  height:180px;
  right:-70px;
  top:-90px;
  border-radius:50%;
  background:rgba(46,125,102,.06);
  pointer-events:none;
}
.profile-summary-card .profile-avatar{
  width:76px;
  height:76px;
  border:4px solid #fff;
  box-shadow:0 0 0 1px #cfe0da,0 8px 20px rgba(20,65,52,.13);
}
.profile-summary-card .profile-info{position:relative;z-index:1;align-self:center}
.profile-summary-card .profile-info h2{margin:0 0 4px;color:var(--text);font-size:1.05rem;letter-spacing:-.012em}
.profile-email{margin:0;color:#6d7f78;font-size:.78rem;overflow-wrap:anywhere}
.profile-meta{display:flex;flex-wrap:wrap;gap:7px;margin-top:11px;color:#50665e;font-size:.75rem}
.profile-meta > span{display:inline-flex;align-items:center;gap:6px;min-width:0;padding:6px 8px;border-radius:7px;background:#f5f8f7;border:1px solid #e7eeeb}
.profile-meta svg{width:15px;height:15px;flex:none;color:var(--primary)}
.profile-meta span span{overflow-wrap:anywhere}
.profile-summary-actions{position:relative;z-index:1;display:flex;align-items:center;align-self:center}
.profile-summary-card .btn{display:inline-flex;align-items:center;gap:7px;margin:0!important;padding:8px 12px;border-radius:8px;font-size:.78rem;box-shadow:0 4px 10px rgba(46,125,102,.14)}
.profile-summary-card .btn svg{width:16px;height:16px}
#editArea{margin-top:18px!important}
.account-settings-panel{overflow:hidden;border:1px solid var(--border);border-radius:16px;background:#fff;box-shadow:0 10px 28px rgba(19,62,49,.06)}
.settings-panel-header{display:flex;align-items:center;justify-content:space-between;gap:16px;padding:15px 20px;border-bottom:1px solid var(--border);background:#f7faf9}
.settings-panel-header h2{margin:0;color:#1b3c32;font-size:1rem}
.settings-panel-header p{margin:5px 0 0;color:#718079;font-size:.84rem}
.icon-button{width:36px;height:36px;display:grid;place-items:center;border:1px solid #d8e3df;border-radius:9px;background:#fff;color:#657a72;cursor:pointer}
.icon-button:hover{background:#edf5f2;color:var(--primary)}
.icon-button svg{width:17px;height:17px}
.settings-content{display:grid;grid-template-columns:minmax(0,1.55fr) minmax(280px,.7fr);align-items:stretch;gap:14px;padding:16px;background:#f5f8f7}
.settings-card{display:block!important;min-width:0;overflow:hidden;border:1px solid #dfe9e5;border-radius:12px;background:#fff}
.settings-side-column{min-width:0;height:100%;display:grid;grid-template-rows:auto 1fr;gap:14px}
.personal-settings{display:flex!important;flex-direction:column;height:100%}
.personal-settings .settings-card-body{display:flex;flex:1;flex-direction:column}
.personal-settings .settings-actions{margin-top:auto}
.settings-card-header{display:flex;align-items:flex-start;gap:10px;padding:14px 16px;border-bottom:1px solid #e6eeeb}
.settings-card-icon{width:36px;height:36px;display:grid;place-items:center;flex:none;border-radius:10px;background:var(--primary-soft);color:var(--primary)}
.settings-card-icon.security{background:#fff4df;color:#a56813}
.settings-card-icon svg{width:18px;height:18px}
.settings-card-header h3{margin:0;color:#203a32;font-size:.88rem}
.settings-card-header p{margin:4px 0 0;color:#7a8983;font-size:.79rem;line-height:1.4}
.settings-card-body{padding:16px}
.profile-edit-grid{display:grid;grid-template-columns:190px minmax(0,1fr);gap:24px;align-items:start}
.avatar-field-label{display:block;margin:0 0 8px;color:#334a42;font-size:.78rem;font-weight:800}
.dropzone{
  width:100%!important;
  height:190px!important;
  min-height:190px!important;
  max-width:none!important;
  padding:16px!important;
  border:1.5px dashed #bcd2ca!important;
  border-radius:12px!important;
  background:#f7faf9!important;
}
.dropzone:hover{border-color:var(--primary)!important;background:#eff7f4!important}
.dropzone.is-dragging{border-color:var(--primary)!important;background:#e9f5f0!important}
.dropzone .preview-img{width:68px;height:68px;margin:0 0 10px;border:3px solid #fff;box-shadow:0 0 0 1px #d5e2dd}
.dropzone .dz-icon{display:none}
.dropzone .dz-text{color:#315348;font-size:.82rem}
.dropzone .dz-sub{font-size:.72rem}
.avatar-help{margin:8px 0 0;color:#87948f;font-size:.7rem;line-height:1.45}
.profile-fields{display:grid;grid-template-columns:1fr 1fr;gap:15px}
.field-group{min-width:0}
.field-group.field-full{grid-column:1/-1}
.field-label{display:flex;align-items:center;justify-content:space-between;gap:8px;margin:0 0 7px;color:#334a42;font-size:.78rem;font-weight:800}
.field-label .field-note{color:#8a9993;font-size:.67rem;font-weight:500}
.input{
  min-height:42px;
  padding:10px 12px;
  border:1px solid #d5e1dc;
  border-radius:9px;
  background:#fff;
  color:#263f36;
  font-size:.86rem;
  outline:none;
  transition:border-color .18s,box-shadow .18s,background .18s;
}
.input:focus{border-color:var(--primary);box-shadow:0 0 0 3px rgba(46,125,102,.1)}
.input[readonly]{background:#f4f7f6;color:#75847f;cursor:not-allowed}
.settings-actions{display:flex;align-items:center;justify-content:flex-end;gap:9px;margin-top:18px;padding-top:16px;border-top:1px solid #e8efec}
.btn-save,.btn-neutral{display:inline-flex;align-items:center;justify-content:center;gap:7px;min-height:40px;padding:9px 14px;border-radius:9px;font-size:.82rem;font-weight:800;cursor:pointer}
.btn-save{border:1px solid var(--primary);background:var(--primary);color:#fff;box-shadow:0 4px 10px rgba(46,125,102,.14)}
.btn-save:hover{background:#246852}
.btn-neutral{border:1px solid #d5e1dc;background:#fff;color:#53675f}
.btn-neutral:hover{background:#f1f5f3}
.btn-save svg,.btn-neutral svg{width:15px;height:15px}
.password-fields{display:grid;gap:14px}
.password-input-wrap{position:relative}
.password-input-wrap .input{padding-right:42px}
.password-toggle{position:absolute;right:5px;top:50%;width:33px;height:33px;display:grid;place-items:center;transform:translateY(-50%);border:0;border-radius:7px;background:transparent;color:#82918b;cursor:pointer}
.password-toggle:hover{background:#edf4f1;color:var(--primary)}
.password-toggle svg{width:16px;height:16px}
.password-tip{display:flex;gap:8px;margin:14px 0 0;padding:10px 11px;border-radius:9px;background:#fff8e9;color:#806126;font-size:.72rem;line-height:1.45}
.password-tip svg{width:15px;height:15px;flex:none;margin-top:1px}
.security-settings .settings-actions{justify-content:stretch}
.security-settings .settings-actions .btn-save{width:100%}
.security-overview{display:grid;grid-template-rows:repeat(2,minmax(44px,1fr));flex:1;gap:2px}
.security-overview-row{display:grid;grid-template-columns:30px minmax(0,1fr) auto;align-items:center;gap:9px;padding:8px 0;border-bottom:1px solid #e8efec}
.security-overview-row:first-child{padding-top:0}
.security-overview-row:last-child{border-bottom:0}
.security-overview-icon{width:30px;height:30px;display:grid;place-items:center;border-radius:9px;background:#edf6f2;color:var(--primary)}
.security-overview-icon svg{width:15px;height:15px}
.security-overview-copy{min-width:0}
.security-overview-copy strong{display:block;color:#2b4b41;font-size:.77rem}
.security-overview-copy span{display:block;margin-top:2px;overflow-wrap:anywhere;color:#7a8b84;font-size:.67rem;line-height:1.35}
.security-overview-state{padding:4px 7px;border-radius:99px;background:#e5f4ee;color:#237158;font-size:.62rem;font-weight:800;text-transform:uppercase}
.security-settings .settings-card-header{padding-top:11px;padding-bottom:11px}
.security-settings{display:flex!important;flex-direction:column;height:100%}
.security-settings .settings-card-body{display:flex;flex:1;flex-direction:column;padding-top:12px;padding-bottom:12px}
.security-settings .settings-actions{margin-top:8px;padding-top:12px}
.change-password-button{width:100%;min-height:42px}
.profile-progress-card .settings-card-header{padding-top:10px;padding-bottom:10px}
.profile-progress-content{display:flex;align-items:center;gap:12px;padding:10px 16px 12px}
.profile-progress-ring{--profile-progress:0;position:relative;width:60px;height:60px;flex:0 0 60px;border-radius:50%;display:grid;place-items:center;background:conic-gradient(var(--primary) calc(var(--profile-progress) * 1%),#e2ece8 0)}
.profile-progress-ring::before{content:"";position:absolute;inset:6px;border-radius:50%;background:#fff}
.profile-progress-ring strong{position:relative;z-index:1;color:#17634d;font-size:.92rem}
.profile-progress-copy{min-width:0}
.profile-progress-copy strong{display:block;color:#24473c;font-size:.79rem}
.profile-progress-copy p{margin:4px 0 0;color:#74867f;font-size:.67rem;line-height:1.45}
.profile-progress-copy span{display:block;margin-top:7px;color:#29745d;font-size:.63rem;font-weight:800}

body.password-modal-open{overflow:hidden}
body.password-modal-open .main{overflow:hidden}
.password-modal-overlay{position:fixed;inset:0;z-index:100500;display:flex;align-items:center;justify-content:center;padding:20px;visibility:hidden;opacity:0;pointer-events:none;background:rgba(7,34,26,.65);backdrop-filter:blur(5px);transition:opacity .2s ease,visibility .2s ease}
.password-modal-overlay.is-open{visibility:visible;opacity:1;pointer-events:auto}
.password-modal{width:min(500px,100%);max-height:calc(100vh - 40px);display:flex;flex-direction:column;overflow:hidden;border:1px solid #d4e2dd;border-radius:17px;background:#fff;box-shadow:0 28px 75px rgba(4,34,26,.3);transform:translateY(12px) scale(.985);transition:transform .2s ease}
.password-modal-overlay.is-open .password-modal{transform:translateY(0) scale(1)}
.password-modal-header{display:grid;grid-template-columns:42px minmax(0,1fr) 34px;align-items:center;gap:11px;padding:18px 20px;border-bottom:1px solid #e0e9e5}
.password-modal-icon{width:42px;height:42px;border-radius:10px;display:grid;place-items:center;color:#1e6c55;background:#e8f5f0}
.password-modal-icon svg{width:19px;height:19px}
.password-modal-header h3{margin:0;color:#1f4036;font-size:.95rem}
.password-modal-header p{margin:4px 0 0;color:#74867f;font-size:.68rem;line-height:1.4}
.password-modal-close{width:34px;height:34px;padding:0;border:0;border-radius:9px;display:grid;place-items:center;color:#577168;background:#f0f5f3;cursor:pointer}
.password-modal-close:hover{color:#174d3d;background:#e5efeb}
.password-modal-close svg{width:16px;height:16px}
.password-modal form{min-height:0;display:flex;flex-direction:column}
.password-modal-body{padding:19px 20px;overflow-y:auto;display:grid;gap:14px}
.password-modal-field{display:grid;gap:6px}
.password-modal-field>label{color:#2b4e43;font-size:.74rem;font-weight:800}
.password-modal-field .password-input-wrap .input{width:100%}
.password-strength-track{height:5px;overflow:hidden;border-radius:999px;background:#e4ece9}
.password-strength-track span{width:0;height:100%;display:block;background:#c34858;transition:width .2s ease,background .2s ease}
.password-rules{margin:8px 0 0;padding:0;display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:6px 12px;list-style:none}
.password-rule{color:#8a9993;font-size:.66rem}
.password-rule::before{content:"\25CB";margin-right:5px;color:#9aaba4}
.password-rule.is-valid{color:#21805f}
.password-rule.is-valid::before{content:"\2713";font-weight:900}
.password-match-message{min-height:15px;margin:0;color:#7f9089;font-size:.65rem}
.password-match-message.is-valid{color:#21805f}
.password-match-message.is-invalid{color:#b23d4c}
.password-modal-error{padding:10px 12px;border:1px solid #efc7ce;border-radius:9px;color:#8d3341;background:#fff2f4;font-size:.7rem;line-height:1.45}
.password-modal-error[hidden]{display:none}
.password-modal-footer{padding:13px 20px;border-top:1px solid #e0e9e5;display:flex;justify-content:flex-end;gap:8px}
.password-modal-footer .btn-save:disabled{border-color:#9ab4aa;background:#9ab4aa;box-shadow:none;cursor:not-allowed}
@media(max-width:560px){.password-modal-overlay{align-items:flex-end;padding:0}.password-modal{width:100%;max-height:94vh;border-radius:17px 17px 0 0}.password-rules{grid-template-columns:1fr}.password-modal-footer .btn-neutral,.password-modal-footer .btn-save{flex:1}}
.main .page-header .header-titles{gap:10px}
.main .page-header .header-titles > .header-icon{
  box-sizing:content-box;
  width:17px!important;
  height:17px!important;
  padding:8px;
  border:1px solid #cce2da;
  border-radius:10px;
  background:#edf8f4;
  color:var(--primary)!important;
}
.main .page-header .header-titles h3{font-size:1rem}
.main .page-header .header-titles p{margin-top:2px;font-size:.74rem}
.main .page-header .header-stats{gap:6px}
.main .page-header .stat-badge{padding:5px 9px;font-size:.7rem}

.upcoming-section{
  max-width:none;
  margin:14px auto 0;
  overflow:hidden;
  border:1px solid #d8e6e0;
  border-radius:12px;
  background:#fff;
  box-shadow:0 8px 18px rgba(11,44,35,.045);
}
.upcoming-section > .section-header{padding:10px 14px;border-bottom:1px solid #e1ebe7;background:#f8fbfa}
.upcoming-section > .section-header h3{margin:0;font-size:.94rem;color:#183d32;font-weight:750}
.upcoming-section > .booking-group{padding:12px 14px}
.upcoming-section > br{display:none}
.upcoming-section .group-title{
  min-height:38px;
  margin:0 0 9px;
  padding:6px 8px;
  border:1px solid #e0eae6;
  border-left:3px solid var(--primary);
  border-radius:8px;
  background:#fbfdfc;
}
.upcoming-section .title-text{margin-left:0!important;font-size:.82rem;gap:7px}
.upcoming-section .booking-count{padding:2px 7px;font-size:.7rem}
.upcoming-section .scroll-btn{width:28px;height:28px;border-radius:7px}
.upcoming-section .scroll-btn svg{width:14px;height:14px}
.upcoming-section .booking-row{padding:3px 2px 5px}
.upcoming-section > .empty-state{margin:12px;padding:24px;font-size:.8rem}

/* Profile overview: upcoming bookings */
.upcoming-section > .section-header{display:flex;align-items:center;justify-content:space-between;gap:14px;padding:13px 14px;background:linear-gradient(105deg,#f7fbf9 0%,#fff 66%)}
.upcoming-heading{display:flex;align-items:center;gap:10px;min-width:0}
.upcoming-heading-icon,.upcoming-group-icon,.upcoming-card-icon{display:inline-flex;align-items:center;justify-content:center;flex:0 0 auto;color:#14795f;background:#e9f6f1;border:1px solid #cee7de}
.upcoming-heading-icon{width:34px;height:34px;border-radius:10px}.upcoming-heading-icon svg{width:17px;height:17px}.upcoming-heading-copy{min-width:0}
.upcoming-section > .section-header .upcoming-heading-copy h3{font-size:.94rem;line-height:1.2}.upcoming-heading-copy p{margin:2px 0 0;color:#667c74;font-size:.69rem;line-height:1.35}
.upcoming-total{flex:0 0 auto;padding:5px 9px;border:1px solid #cfe3dc;border-radius:999px;background:#edf7f3;color:#17664f;font-size:.68rem;font-weight:800}
.upcoming-section > .booking-group{padding:11px 14px 13px}.upcoming-section > .booking-group + .booking-group{border-top:1px solid #e5eeea}
.upcoming-section .group-title{min-height:34px;margin-bottom:10px;padding:0 1px 8px;border:0;border-bottom:1px solid #e1ebe7;border-radius:0;background:transparent}.upcoming-section .title-text{gap:7px;color:#345d50;font-size:.72rem;text-transform:uppercase;letter-spacing:.055em}
.upcoming-title-label{display:inline-flex;align-items:center;gap:7px}.upcoming-group-icon{width:23px;height:23px;border:0;border-radius:7px}.upcoming-group-icon svg{width:13px;height:13px}
.upcoming-section .booking-count{min-width:21px;padding:2px 6px;text-align:center;background:#e7f2ee;color:#17654f}.upcoming-section .scroll-controls{gap:6px}
.upcoming-section .scroll-btn{color:#315d50;border-color:#d2e1dc;background:#fff;box-shadow:0 2px 7px rgba(19,75,59,.07)}.upcoming-section .scroll-btn:hover{color:#fff;background:#207e65;border-color:#207e65;transform:translateY(-1px)}
.upcoming-section .booking-row{align-items:stretch;gap:12px;padding:2px 1px 4px}
.upcoming-section .booking-card{display:flex;flex:0 0 310px;flex-direction:column;min-width:310px;max-width:310px;min-height:220px;border:1px solid #bcd8ce;border-top:3px solid #267c63;border-radius:12px;background:linear-gradient(150deg,#fff 58%,#f1f8f5);box-shadow:0 5px 14px rgba(14,59,47,.075)}
.upcoming-section .booking-card:hover{border-color:#b9d8cd;transform:translateY(-2px);box-shadow:0 10px 22px rgba(14,59,47,.09)}
.upcoming-section .booking-card-header{display:flex;align-items:center;gap:9px;padding:10px 12px;color:#fff;border-bottom:1px solid #1d684f;background:linear-gradient(115deg,#216e57,#318a70)}
.upcoming-card-icon{width:31px;height:31px;border-radius:9px}.upcoming-card-icon svg{width:16px;height:16px}.upcoming-card-identity{display:flex;flex-direction:column;gap:2px;min-width:0}
.upcoming-section .booking-card-header .upcoming-card-icon{color:#fff;background:rgba(255,255,255,.13);border-color:rgba(255,255,255,.25)}
.upcoming-card-type{color:#d8eee7;font-size:.61rem;font-weight:800;letter-spacing:.08em;line-height:1;text-transform:uppercase}.upcoming-card-reference{overflow:hidden;color:#fff;font-size:.78rem;font-weight:800;text-overflow:ellipsis;white-space:nowrap}
.upcoming-section .booking-card-body{flex:1;gap:10px;padding:12px;color:#213a32}.upcoming-service-label{margin-bottom:2px;color:#6b8179;font-size:.61rem;font-weight:800;letter-spacing:.07em;text-transform:uppercase}
.upcoming-service-name{overflow:hidden;color:#102f26;font-size:.88rem;font-weight:800;line-height:1.3;text-overflow:ellipsis;white-space:nowrap}.upcoming-date-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:7px}.upcoming-date-grid.is-single{grid-template-columns:1fr}
.upcoming-date-item{padding:8px 9px;border:1px solid #cfe3dc;border-radius:8px;background:#eef7f3}.upcoming-date-item span{display:block;margin-bottom:2px;color:#58756b;font-size:.6rem;font-weight:750;text-transform:uppercase}.upcoming-date-item strong{display:block;color:#174737;font-size:.72rem;line-height:1.25}
.upcoming-section .meta-tags{gap:5px;margin-top:auto}.upcoming-section .meta-tags span{padding:4px 8px;color:#426258;background:#eef5f2;font-size:.66rem;font-weight:700}
.upcoming-section .booking-card-footer{display:flex;align-items:center;justify-content:space-between;min-height:43px;padding:8px 12px;color:#698078;background:#fbfdfc;border-top-color:#e4ece9}.upcoming-footer-label{font-size:.61rem;font-weight:800;letter-spacing:.07em;text-transform:uppercase}
.upcoming-section .capsule-past-due,.upcoming-section .days-left{padding:4px 8px;border-radius:999px;font-size:.67rem;font-weight:800}.upcoming-section .days-left{color:#17664f;background:#e9f6f0;border:1px solid #cde7dc}.upcoming-section .capsule-past-due svg,.upcoming-section .days-left svg{width:12px;height:12px}
@media(max-width:560px){.upcoming-heading-copy p{display:none}.upcoming-section .booking-card{flex-basis:272px;min-width:272px;max-width:272px}.upcoming-date-grid{grid-template-columns:1fr}}
.bookings-section,.favorites-section,#history{margin-top:0!important}

/* Reusable table discovery, filtering and pagination controls */
.table-tools{
  display:flex;
  align-items:center;
  justify-content:space-between;
  gap:14px;
  padding:13px 18px;
  background:#fff;
  border-bottom:1px solid var(--border);
}
.table-tools-primary,.table-tools-secondary{display:flex;align-items:center;gap:10px;min-width:0}
.table-search{position:relative;min-width:230px;max-width:360px;flex:1}
.table-search svg{position:absolute;left:11px;top:50%;width:16px;height:16px;transform:translateY(-50%);color:#789087;pointer-events:none}
.table-search input,.table-tools select{
  height:38px;
  border:1px solid #cfddd8;
  border-radius:9px;
  background:#fff;
  color:#30453e;
  font-size:.84rem;
  outline:none;
  transition:border-color .18s ease,box-shadow .18s ease;
}
.table-search input{width:100%;padding:0 12px 0 36px}
.table-tools select{padding:0 30px 0 11px;cursor:pointer}
.table-search input:focus,.table-tools select:focus{border-color:var(--primary);box-shadow:0 0 0 3px rgba(46,125,102,.11)}
.table-rows-label{display:inline-flex;align-items:center;gap:7px;color:#687a73;font-size:.8rem;white-space:nowrap}
.table-results{color:#71837c;font-size:.78rem;white-space:nowrap}
.table-pagination{display:flex;align-items:center;justify-content:space-between;gap:14px;padding:12px 18px;background:#fbfdfc;border-top:1px solid var(--border)}
.table-page-info{font-size:.8rem;color:#687a73}
.table-page-actions{display:flex;gap:7px}
.table-page-btn{width:34px;height:32px;display:grid;place-items:center;border:1px solid #d4e0dc;border-radius:8px;background:#fff;color:#315348;cursor:pointer;transition:.18s ease}
.table-page-btn:hover:not(:disabled){background:var(--primary-soft);border-color:#b9d3ca}
.table-page-btn:disabled{opacity:.38;cursor:not-allowed}
.table-empty-filter td{text-align:center!important;padding:34px 20px!important;color:#71837c!important}
.formal-card{border-radius:13px;box-shadow:0 7px 24px rgba(19,62,49,.05)}
.formal-card-header{padding:15px 18px;background:#f7faf9}
.formal-table tbody tr{transition:background .16s ease}
.mobile-profile-nav{display:none}

/* Formal profile data tables */
.bookings-section .formal-card,#history .formal-card{
  margin-bottom:20px;
  border:1px solid #d6e3de;
  border-radius:14px;
  box-shadow:0 7px 22px rgba(18,59,47,.045);
}
.bookings-section .formal-card-header,#history .formal-card-header{
  min-height:57px;
  padding:12px 17px;
  background:linear-gradient(180deg,#fbfdfc 0%,#f6faf8 100%);
  border-bottom:1px solid #dce7e3;
}
.bookings-section .title-group,#history .title-group{gap:10px}
.bookings-section .title-group > svg,#history .title-group > svg{
  box-sizing:content-box;
  width:16px;
  height:16px;
  padding:7px;
  border:1px solid #cfe2da;
  border-radius:9px;
  background:#eaf5f1;
  color:#216f58;
}
.bookings-section .title-group h4,#history .title-group h4{
  color:#17382e;
  font-size:.92rem;
  font-weight:780;
  letter-spacing:-.01em;
}
.bookings-section .count-pill,#history .count-pill{
  padding:5px 10px;
  border:1px solid rgba(255,255,255,.34);
  box-shadow:0 2px 5px rgba(28,103,80,.1);
  font-size:.65rem;
  letter-spacing:.02em;
}
.bookings-section .count-pill.pill-warning,#history .count-pill.pill-warning{background:#e99108}
.bookings-section .table-tools,#history .table-tools{
  min-height:62px;
  padding:11px 17px;
  background:#fff;
  border-bottom:1px solid #e0e9e6;
}
.bookings-section .table-search,#history .table-search{width:260px;min-width:220px;max-width:320px}
.bookings-section .table-search input,#history .table-search input,
.bookings-section .table-tools select,#history .table-tools select{
  height:39px;
  border-color:#d3e0dc;
  border-radius:9px;
  background:#fbfdfc;
  color:#29463d;
  font-size:.78rem;
}
.bookings-section .table-search input::placeholder,#history .table-search input::placeholder{color:#83938d}
.bookings-section .table-rows-label,#history .table-rows-label,
.bookings-section .table-results,#history .table-results{font-size:.72rem;color:#71817b}
.bookings-section .table-results,#history .table-results{padding-left:10px;border-left:1px solid #dce6e2;font-weight:700}
.bookings-section .table-responsive,#history .table-responsive{scrollbar-width:thin;scrollbar-color:#b8cec6 #f3f7f5}
.bookings-section .table-responsive::-webkit-scrollbar,#history .table-responsive::-webkit-scrollbar{height:7px}
.bookings-section .table-responsive::-webkit-scrollbar-track,#history .table-responsive::-webkit-scrollbar-track{background:#f3f7f5}
.bookings-section .table-responsive::-webkit-scrollbar-thumb,#history .table-responsive::-webkit-scrollbar-thumb{border-radius:999px;background:#b8cec6}
.bookings-section .formal-table,#history .formal-table{min-width:780px;table-layout:auto}
.bookings-section .formal-table thead th,#history .formal-table thead th{
  height:43px;
  padding:10px 17px;
  border-bottom:1px solid #d8e5e0;
  background:#f3f8f6;
  color:#587168;
  font-size:.68rem!important;
  font-weight:800;
  line-height:1.2;
  letter-spacing:.065em;
  white-space:nowrap;
}
.bookings-section .formal-table tbody td,#history .formal-table tbody td{
  height:61px;
  padding:12px 17px;
  border-bottom:1px solid #e5edea;
  color:#2c4039;
  font-size:.8rem!important;
  line-height:1.38;
}
.bookings-section .formal-table tbody tr:nth-child(even),#history .formal-table tbody tr:nth-child(even){background:#fcfefd}
.bookings-section .formal-table tbody tr:hover,#history .formal-table tbody tr:hover{background:#f1f8f5}
.bookings-section .formal-table tbody td strong,#history .formal-table tbody td strong{color:#19372e;font-weight:750}
.formal-table .table-col-details,.formal-table .table-col-status,.formal-table .table-col-tourists,.formal-table .table-col-payment{text-align:center}
.formal-table .table-col-type,.formal-table .table-col-room{white-space:nowrap}
.formal-table .table-col-date,.formal-table .table-col-dates{white-space:nowrap}
.bookings-section .tag-outline,#history .tag-outline{
  display:inline-flex;
  padding:4px 8px;
  border-color:#d5e2dd;
  border-radius:7px;
  background:#f7faf9;
  color:#3e5b51;
  font-size:.7rem;
  font-weight:650;
}
.bookings-section .guest-group,#history .guest-group{gap:5px;flex-wrap:nowrap;white-space:nowrap}
.bookings-section .guest-group span,#history .guest-group span{font-size:.7rem;color:#667c74}
.bookings-section .guest-group .highlight,#history .guest-group .highlight,
.bookings-section .guest-group .highlight-warning,#history .guest-group .highlight-warning{
  padding:4px 7px;
  border-radius:6px;
  font-size:.67rem;
  font-weight:800;
}
.bookings-section .guest-group .highlight,#history .guest-group .highlight{background:#e4f3ed;color:#176047}
.bookings-section .guest-group .highlight-warning,#history .guest-group .highlight-warning{background:#fff1ce;color:#965b04}
.bookings-section .date-group,#history .date-group{gap:2px;color:#4e675e;font-size:.72rem}
.bookings-section .date-group strong,#history .date-group strong{display:inline-block;min-width:27px;color:#21634f;font-size:.62rem;letter-spacing:.035em}
.bookings-section .payment-group,#history .payment-group{gap:2px}
.bookings-section .payment-group > strong,#history .payment-group > strong{font-size:.8rem}
.bookings-section .payment-status,#history .payment-status{font-size:.66rem;font-weight:750}
.bookings-section .status-pill,#history .status-pill{
  min-width:72px;
  justify-content:center;
  padding:5px 9px;
  border:1px solid transparent;
  font-size:.63rem;
  letter-spacing:.04em;
}
.bookings-section .status-pill.pending,#history .status-pill.pending{border-color:#f5dfa3;background:#fff3cf;color:#945706}
.bookings-section .status-pill.accepted,#history .status-pill.accepted{border-color:#bfe3d4;background:#e3f5ed;color:#176047}
#history .formal-table .pill{min-width:72px;padding:5px 9px;border:1px solid transparent;text-align:center;font-size:.63rem;font-weight:800;letter-spacing:.035em;text-transform:uppercase}
#history .formal-table .pill.completed,#history .formal-table .pill.complete{border-color:#b9d7f5;background:#e5f1ff;color:#1d5f9f}
#history .formal-table .pill.cancelled,#history .formal-table .pill.declined{border-color:#f1cccc;background:#fceaea;color:#a43a3a}
#history .formal-table .pill.pending{border-color:#f5dfa3;background:#fff3cf;color:#945706}
.bookings-section .btn-details-user.booking-btn-user,#history .btn-details-user.booking-btn-user{
  min-height:30px;
  padding:6px 10px;
  border:1px solid #24745c;
  border-radius:7px;
  background:#2e7d66;
  box-shadow:0 2px 5px rgba(32,104,81,.12);
  font-size:.68rem;
  font-weight:750;
}
.bookings-section .btn-details-user.booking-btn-user:hover,#history .btn-details-user.booking-btn-user:hover{background:#205e4c;transform:translateY(-1px)}
.bookings-section .btn-details-user.booking-btn-user svg,#history .btn-details-user.booking-btn-user svg{width:14px;height:14px}
.bookings-section .text-muted,#history .text-muted{font-size:.71rem;color:#91a09b}
#history .btn-review-user{min-height:30px;padding:6px 10px;border-radius:7px;font-size:.68rem;font-weight:750;white-space:nowrap}
#history .formal-card[style*="margin-top"]{margin-top:20px!important}
#history .formal-card-header[style]{background:linear-gradient(180deg,#fbfdfc 0%,#f6faf8 100%)!important}
.bookings-section .table-pagination,#history .table-pagination{
  min-height:48px;
  padding:9px 17px;
  background:#fafcfb;
  border-top:0;
}
.bookings-section .table-pagination[hidden],#history .table-pagination[hidden]{display:none!important}
.bookings-section .table-page-info,#history .table-page-info{font-size:.72rem}
.bookings-section .table-page-btn,#history .table-page-btn{width:32px;height:30px;border-radius:7px}
@media(max-width:760px){
  .profile-stat-grid{gap:8px;margin-bottom:13px}
  .profile-stat-card{
    min-height:94px;
    grid-template-columns:32px minmax(0,1fr);
    align-content:center;
    gap:7px;
    padding:10px;
    border-radius:11px;
  }
  .profile-stat-card-icon{width:32px;height:32px;border-radius:9px}
  .profile-stat-card-icon svg{width:16px;height:16px}
  .profile-stat-card-copy{grid-template-columns:minmax(0,1fr) auto;gap:3px 5px}
  .profile-stat-card-copy small{font-size:.48rem;line-height:1.25;letter-spacing:.035em}
  .profile-stat-card-copy strong{font-size:1.15rem}
  .profile-stat-card-copy p{grid-column:1/-1;font-size:.5rem;line-height:1.3;white-space:normal}

  /* Mobile booking tables become swipeable, complete booking cards. */
  .bookings-section .formal-card{overflow:hidden}
  .bookings-section .table-responsive{
    overflow:visible;
    padding:8px 11px 13px;
    background:linear-gradient(180deg,#f8fbfa,#f3f8f6);
  }
  .bookings-section .table-responsive::before{
    content:"Swipe horizontally to view bookings";
    display:block;
    margin:0 2px 7px;
    color:#71847d;
    font-size:.54rem;
    font-weight:700;
    letter-spacing:.025em;
  }
  .bookings-section .formal-table{display:block;min-width:0;width:100%;margin:0}
  .bookings-section .formal-table thead{display:none}
  .bookings-section .formal-table tbody{
    width:100%;
    display:flex;
    gap:10px;
    padding:2px 2px 9px;
    overflow-x:auto;
    scroll-snap-type:x mandatory;
    scroll-padding-inline:2px;
    overscroll-behavior-x:contain;
    scrollbar-width:none;
    -webkit-overflow-scrolling:touch;
  }
  .bookings-section .formal-table tbody::-webkit-scrollbar{display:none}
  .bookings-section .formal-table tbody tr{
    position:relative;
    min-width:0;
    display:grid;
    flex:0 0 calc(100% - 42px);
    align-content:start;
    overflow:hidden;
    border:1px solid #d2e2dc;
    border-top:3px solid #287b63;
    border-radius:12px;
    background:#fff!important;
    box-shadow:0 7px 18px rgba(20,67,53,.075);
    scroll-snap-align:start;
  }
  .bookings-section .formal-table tbody tr[hidden]{display:none!important}
  .bookings-section .formal-table tbody td{
    min-height:38px;
    display:grid;
    grid-template-columns:82px minmax(0,1fr);
    align-items:center;
    gap:9px;
    height:auto;
    padding:8px 11px;
    border:0;
    border-bottom:1px solid #e7efec;
    color:#2f473f;
    text-align:left!important;
    font-size:.68rem!important;
    line-height:1.35;
    white-space:normal!important;
  }
  .bookings-section .formal-table tbody td:last-child{border-bottom:0}
  .bookings-section .formal-table tbody td::before{
    content:attr(data-label);
    grid-column:1;
    color:#6c8179;
    font-size:.49rem;
    font-weight:850;
    line-height:1.25;
    letter-spacing:.065em;
    text-transform:uppercase;
  }
  .bookings-section .formal-table tbody td > *{min-width:0;grid-column:2}
  .bookings-section .formal-table tbody td strong{font-size:.7rem;line-height:1.35}
  .bookings-section .formal-table .guest-group{flex-wrap:wrap;white-space:normal}
  .bookings-section .formal-table .date-group{font-size:.65rem}
  .bookings-section .formal-table .payment-group{align-items:flex-start}
  .bookings-section .formal-table .table-col-details button,
  .bookings-section .formal-table .table-col-tourists button,
  .bookings-section .formal-table .table-col-action button{
    width:100%;
    min-height:32px;
    margin:0;
    justify-content:center;
  }
  .bookings-section .formal-table .table-col-status > *,
  .bookings-section .formal-table .table-col-payment > *{justify-self:start;margin:0}
  .bookings-section .formal-table .booking-decision-alert-row{border-top-color:#d89a27}
  .bookings-section .formal-table .booking-decision-alert-row td{display:block;padding:10px}
  .bookings-section .formal-table .booking-decision-alert-row td::before{display:none}
  .bookings-section .formal-table .table-empty-filter{flex-basis:100%;border-top-color:#90afa4}
  .bookings-section .formal-table .table-empty-filter td{display:block!important;padding:28px 16px!important;text-align:center!important}
  .bookings-section .formal-table .table-empty-filter td::before{display:none}

  .bookings-section .formal-card-header,#history .formal-card-header{padding:11px 13px}
  .bookings-section .table-tools,#history .table-tools{padding:11px 13px}
  .bookings-section .table-search,#history .table-search{width:100%;max-width:none}
  .bookings-section .formal-table thead th,#history .formal-table thead th,
  .bookings-section .formal-table tbody td,#history .formal-table tbody td{padding-left:13px;padding-right:13px}
}

@media(max-width:760px){
  /* Cancellation and history rows use the same mobile card carousel. */
  :is(.cancellation-section,#history) .formal-card{overflow:hidden}
  :is(.cancellation-section,#history) .table-responsive{
    overflow:visible;
    padding:8px 11px 13px;
    background:linear-gradient(180deg,#f8fbfa,#f3f8f6);
  }
  :is(.cancellation-section,#history) .table-responsive::before{
    content:"Swipe horizontally to view records";
    display:block;
    margin:0 2px 7px;
    color:#71847d;
    font-size:.54rem;
    font-weight:700;
    letter-spacing:.025em;
  }
  :is(.cancellation-section,#history) .formal-table{display:block;width:100%;min-width:0;margin:0}
  :is(.cancellation-section,#history) .formal-table thead{display:none}
  :is(.cancellation-section,#history) .formal-table tbody{
    width:100%;
    display:flex;
    gap:10px;
    padding:2px 2px 9px;
    overflow-x:auto;
    scroll-snap-type:x mandatory;
    scroll-padding-inline:2px;
    overscroll-behavior-x:contain;
    scrollbar-width:none;
    -webkit-overflow-scrolling:touch;
  }
  :is(.cancellation-section,#history) .formal-table tbody::-webkit-scrollbar{display:none}
  :is(.cancellation-section,#history) .formal-table tbody tr{
    position:relative;
    min-width:0;
    display:grid;
    flex:0 0 calc(100% - 42px);
    align-content:start;
    overflow:hidden;
    border:1px solid #d2e2dc;
    border-top:3px solid #287b63;
    border-radius:12px;
    background:#fff!important;
    box-shadow:0 7px 18px rgba(20,67,53,.075);
    scroll-snap-align:start;
  }
  :is(.cancellation-section,#history) .formal-table tbody tr[hidden]{display:none!important}
  :is(.cancellation-section,#history) .formal-table tbody td{
    min-height:39px;
    display:grid;
    grid-template-columns:88px minmax(0,1fr);
    align-items:center;
    gap:9px;
    height:auto;
    padding:8px 11px!important;
    border:0;
    border-bottom:1px solid #e7efec;
    color:#2f473f;
    text-align:left!important;
    font-size:.68rem!important;
    line-height:1.38;
    white-space:normal!important;
  }
  :is(.cancellation-section,#history) .formal-table tbody td:last-child{border-bottom:0}
  :is(.cancellation-section,#history) .formal-table tbody td::before{
    content:attr(data-label);
    grid-column:1;
    color:#6c8179;
    font-size:.49rem;
    font-weight:850;
    line-height:1.25;
    letter-spacing:.06em;
    text-transform:uppercase;
  }
  :is(.cancellation-section,#history) .formal-table tbody td > *{min-width:0;grid-column:2}
  :is(.cancellation-section,#history) .formal-table tbody td strong{font-size:.7rem;line-height:1.35}
  :is(.cancellation-section,#history) .formal-table .table-col-details button,
  :is(.cancellation-section,#history) .formal-table .table-col-review button,
  :is(.cancellation-section,#history) .formal-table .table-col-actions button{
    width:100%;
    min-height:32px;
    margin:0!important;
    justify-content:center;
  }
  #history .formal-table .table-col-pax-breakdown>div{font-size:.68rem!important}
  #history .formal-table .history-payment-column{text-align:left!important}
  #history .formal-table td.history-payment-column .pill{margin:0}
  .cancellation-section .cancellation-status,
  .cancellation-section .refund-eligibility,
  .cancellation-section .refund-status{
    width:max-content;
    max-width:100%;
    min-width:0;
    justify-self:start;
  }
  #history .formal-table .history-payment-column .pill{
    width:max-content;
    max-width:100%;
    min-width:0;
    justify-self:start;
  }
  #history .formal-table .table-col-status .pill{
    width:max-content;
    max-width:100%;
    min-width:0;
    justify-self:center;
  }
  .cancellation-section .cancellation-booking-name{max-width:none;white-space:normal}
  .cancellation-section .cancellation-cell-note{white-space:normal}
  .cancellation-section .cancellation-row-actions{display:flex;width:100%;min-width:0;gap:7px}
  .cancellation-section .cancellation-row-actions .view-cancellation-reason{flex:0 0 auto;width:auto!important;min-width:0;min-height:32px}
  .cancellation-section .cancellation-row-actions .cancellation-details-btn,
  .cancellation-section .cancellation-row-actions .cancellation-reason-btn{flex:0 0 36px;width:36px!important;min-width:36px!important;min-height:36px}
  .cancellation-section .cancellation-row-actions .refund-destination-button{flex-basis:100%;width:100%!important}
  :is(.cancellation-section,#history) .formal-table .table-empty-filter{flex-basis:100%;border-top-color:#90afa4}
  :is(.cancellation-section,#history) .formal-table .table-empty-filter td{display:block!important;padding:28px 16px!important;text-align:center!important}
  :is(.cancellation-section,#history) .formal-table .table-empty-filter td::before{display:none}

  /* Keep mobile table controls aligned and evenly sized. */
  :is(.bookings-section,.cancellation-section,#history) .formal-card-header{
    align-items:center;
    gap:8px;
    padding:11px 12px;
  }
  :is(.bookings-section,.cancellation-section,#history) .formal-card-header .title-group{min-width:0;gap:8px}
  :is(.bookings-section,.cancellation-section,#history) .formal-card-header .title-group h4{font-size:.78rem;line-height:1.25}
  :is(.bookings-section,.cancellation-section,#history) .formal-card-header .count-pill{flex:none;white-space:nowrap}
  :is(.bookings-section,.cancellation-section,#history) .table-tools{
    gap:9px;
    padding:11px 12px;
    background:#fff;
  }
  :is(.bookings-section,.cancellation-section,#history) .table-tools-primary{
    display:grid;
    grid-template-columns:1fr;
    gap:8px;
  }
  :is(.bookings-section,.cancellation-section,#history) .table-search,
  :is(.bookings-section,.cancellation-section,#history) .table-category-filter{width:100%;max-width:none}
  :is(.bookings-section,.cancellation-section,#history) .table-tools-primary select{width:100%;flex:none}
  :is(.bookings-section,.cancellation-section,#history) .table-tools-secondary{
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:8px;
    flex-wrap:nowrap;
    padding-top:2px;
  }
  :is(.bookings-section,.cancellation-section,#history) .table-rows-label{gap:6px;flex-wrap:nowrap}
  :is(.bookings-section,.cancellation-section,#history) .table-page-size{
    width:72px;
    min-width:72px;
    flex:none;
    padding-right:24px!important;
    padding-left:10px!important;
    color:#29483f!important;
    background-color:#fff!important;
    font-weight:700;
    opacity:1;
  }
  :is(.bookings-section,.cancellation-section,#history) .table-page-size option{color:#29483f;background:#fff}
  :is(.bookings-section,.cancellation-section,#history) .table-results{
    min-width:max-content;
    margin-left:auto;
    padding-left:9px;
    border-left:1px solid #dde7e3;
    text-align:right;
  }
  :is(.bookings-section,.cancellation-section,#history) .table-pagination{
    min-height:46px;
    align-items:center;
    padding:8px 12px;
  }
  :is(.bookings-section,.cancellation-section,#history) .table-page-actions{margin-left:auto}

  /* Booking tables use search, then status and row count on one line. */
  :is(.bookings-section,.cancellation-section,#history) .table-tools{
    display:grid;
    grid-template-columns:minmax(0,1fr) auto;
    align-items:center;
    gap:8px;
  }
  :is(.bookings-section,.cancellation-section,#history) .table-tools-primary,
  :is(.bookings-section,.cancellation-section,#history) .table-tools-secondary{display:contents}
  :is(.bookings-section,.cancellation-section,#history) .table-search{grid-row:1;grid-column:1/-1}
  :is(.bookings-section,.cancellation-section,#history) .table-category-filter{grid-row:2;grid-column:1;width:100%}
  :is(.bookings-section,.cancellation-section,#history) .table-rows-label{grid-row:2;grid-column:2;justify-self:end}
  :is(.bookings-section,.cancellation-section,#history) .table-results{display:none}

  /* Keep complaint heading and actions on deliberate, aligned rows. */
  .complaints-section .page-header{display:grid;grid-template-columns:1fr;gap:9px;padding-top:10px!important;padding-bottom:10px!important}
  .complaints-section .header-titles{width:100%;align-items:flex-start}
  .complaints-section .header-titles>div{min-width:0}
  .complaints-section .header-titles h3{font-size:.92rem;line-height:1.2}
  .complaints-section .header-titles p{font-size:.65rem;line-height:1.35}
  .complaint-header-actions{
    width:100%;
    display:flex;
    align-items:center;
    justify-content:space-between;
    flex-direction:row;
    gap:8px;
  }
  .complaint-header-submit{order:1;min-height:31px;padding:6px 9px;font-size:.61rem}
  .complaint-total-badge{order:2;min-height:31px;margin-left:auto;padding:6px 9px!important;font-size:.61rem!important}
}

@media(max-width:760px){
  .profile-summary-card{grid-template-columns:1fr;padding:22px;text-align:center}
  .profile-summary-card .profile-avatar{margin:auto}
  .profile-meta{justify-content:center;flex-direction:column;gap:8px}
  .profile-meta > span{justify-content:center}
  .profile-summary-actions{justify-content:center}
  .page-header{align-items:flex-start}
  .profile-status{display:none}
  .profile-edit-grid,.profile-fields{grid-template-columns:1fr}
  .field-group.field-full{grid-column:auto}
  .settings-content{grid-template-columns:1fr;padding:12px}
  .personal-settings .settings-actions{margin-top:18px}
  .settings-panel-header,.settings-card-header,.settings-card-body{padding-left:16px;padding-right:16px}
  .table-tools{align-items:stretch;flex-direction:column}
  .table-tools-primary,.table-tools-secondary{width:100%;flex-wrap:wrap}
  .table-search{max-width:none;min-width:100%}
  .table-tools select{flex:1}
}
@media(min-width:761px) and (max-width:1120px){
  .settings-content{grid-template-columns:1fr}
  .personal-settings .settings-actions{margin-top:18px}
  .profile-summary-card{grid-template-columns:auto minmax(0,1fr)}
  .profile-summary-actions{grid-column:2}
}
@media(max-width:900px){
  .main{padding-top:118px!important}
  .main .page-header{
    margin-right:-16px!important;
    margin-left:-16px!important;
    padding-right:16px!important;
    padding-left:16px!important;
  }
  .mobile-profile-nav{
    position:fixed;
    top:0;
    right:0;
    left:0;
    z-index:1000;
    display:flex;
    flex-direction:column;
    align-items:center;
    gap:0;
    height:108px;
    margin:0;
    padding:0;
    overflow:hidden;
    background:rgba(255,255,255,.97);
    border-bottom:1px solid #d5e4df;
    box-shadow:0 4px 14px rgba(14,62,48,.08);
    backdrop-filter:blur(14px);
    -webkit-backdrop-filter:blur(14px);
  }
  .mobile-profile-topbar{
    width:100%;
    height:56px;
    display:flex;
    align-items:center;
    gap:8px;
    flex:0 0 56px;
    padding:7px 11px;
    border-bottom:2px solid var(--primary);
    background:#fff;
  }
  .mobile-profile-back{
    width:36px;
    height:36px;
    display:grid;
    place-items:center;
    flex:0 0 36px;
    padding:0;
    border:1px solid #cee0da;
    border-radius:11px;
    background:#edf6f2;
    color:#1f6f58;
    cursor:pointer;
  }
  .mobile-profile-back svg{width:19px;height:19px}
  .mobile-profile-brand{min-width:0;display:flex;align-items:center;gap:7px;flex:1}
  .mobile-profile-brand-mark{width:34px;height:34px;display:block;flex:0 0 34px;object-fit:contain}
  .mobile-profile-brand-text{width:clamp(88px,31vw,122px);height:28px;display:block;object-fit:contain;object-position:left center}
  .mobile-profile-account{
    width:37px;
    height:37px;
    display:grid;
    place-items:center;
    flex:0 0 37px;
    padding:2px;
    overflow:hidden;
    border:1px solid #cce0d8;
    border-radius:50%;
    background:#edf6f2;
    cursor:pointer;
  }
  .mobile-profile-account img{width:100%;height:100%;display:block;border-radius:50%;object-fit:cover}
  .mobile-profile-back:focus-visible,.mobile-profile-account:focus-visible,.mobile-profile-nav a:focus-visible{outline:3px solid rgba(46,125,102,.2);outline-offset:2px}
  .mobile-profile-nav-scroll{
    width:100%;
    height:52px;
    min-width:0;
    display:flex;
    align-items:center;
    gap:6px;
    flex:1;
    padding:7px 10px;
    overflow-x:auto;
    overscroll-behavior-x:contain;
    scrollbar-width:none;
    -webkit-overflow-scrolling:touch;
  }
  .mobile-profile-nav-scroll::-webkit-scrollbar{display:none}
  .mobile-profile-nav::after{
    content:"";
    position:absolute;
    top:63px;
    right:0;
    bottom:7px;
    width:18px;
    pointer-events:none;
    background:linear-gradient(90deg,rgba(255,255,255,0),rgba(255,255,255,.96));
  }
  .mobile-profile-nav a{
    min-height:38px;
    display:inline-flex;
    align-items:center;
    gap:6px;
    flex:0 0 auto;
    padding:8px 11px;
    border:1px solid #d7e3df;
    border-radius:10px;
    background:#fff;
    color:#476158;
    text-decoration:none;
    font-size:.7rem;
    font-weight:750;
    line-height:1;
    white-space:nowrap;
  }
  .mobile-profile-nav a svg{width:15px;height:15px;flex:none}
.mobile-profile-nav a.active{background:var(--primary);border-color:var(--primary);color:#fff}
html[data-profile-tab="profile"] .mobile-profile-nav a[data-section="profile"],
html[data-profile-tab="bookings"] .mobile-profile-nav a[data-section="bookings"],
html[data-profile-tab="cancel-bookings"] .mobile-profile-nav a[data-section="cancel-bookings"],
html[data-profile-tab="favorites"] .mobile-profile-nav a[data-section="favorites"],
html[data-profile-tab="complaints"] .mobile-profile-nav a[data-section="complaints"],
  html[data-profile-tab="history"] .mobile-profile-nav a[data-section="history"]{background:var(--primary);border-color:var(--primary);color:#fff}

  /* The mobile shell provides context, so tab title bars are unnecessary. */
  .main > .section > .page-header{display:none!important}
  .complaints-section > .page-header{
    min-height:0;
    display:block!important;
    margin:0!important;
    padding:9px 0 8px!important;
    border:0!important;
    background:transparent!important;
    box-shadow:none!important;
  }
  .complaints-section > .page-header .header-titles,
  .complaints-section > .page-header .complaint-total-badge{display:none!important}
  .complaints-section > .page-header .complaint-header-actions{justify-content:flex-end}
}

/* Tourist billing and receipt workflow */
.billing-btn-user{display:inline-flex;align-items:center;justify-content:center;gap:5px;height:30px;min-height:30px;margin-left:6px;padding:6px 10px;vertical-align:middle;box-sizing:border-box;border:1px solid #bcd9ce;border-radius:7px;color:#17614d;background:#edf7f3;font:inherit;font-size:.68rem;font-weight:800;line-height:1;white-space:nowrap;cursor:pointer;transition:.18s ease}
.billing-btn-user:hover{border-color:#72ad98;background:#dff1ea;transform:translateY(-1px)}
.bookings-section .formal-table .table-col-details,#history .formal-table .table-col-details{white-space:nowrap}
.bookings-section .formal-table .table-col-details .btn-details-user.booking-btn-user,#history .formal-table .table-col-details .btn-details-user.booking-btn-user{height:30px;min-height:30px;vertical-align:middle;box-sizing:border-box;line-height:1}
.cancel-booking-btn{display:inline-flex;align-items:center;justify-content:center;min-width:68px;height:30px;padding:6px 12px;border:1px solid #b91c1c;border-radius:7px;background:#dc2626;color:#fff;font:inherit;font-size:.68rem;font-weight:800;line-height:1;white-space:nowrap;cursor:pointer;box-shadow:0 3px 8px rgba(185,28,28,.18);transition:.18s ease}
.cancel-booking-btn:hover{border-color:#991b1b;background:#b91c1c;transform:translateY(-1px);box-shadow:0 5px 12px rgba(153,27,27,.25)}
.cancel-booking-btn:focus-visible{outline:3px solid rgba(239,68,68,.25);outline-offset:2px}
.cancellation-policy-card{display:flex;align-items:flex-start;gap:12px;margin:0 0 16px;padding:15px 17px;border:1px solid #f1d5ae;border-radius:12px;background:linear-gradient(135deg,#fff9ed,#fffdf8);box-shadow:0 4px 14px rgba(141,91,23,.06)}
.cancellation-policy-icon{width:37px;height:37px;display:grid;place-items:center;flex:0 0 37px;border-radius:10px;background:#fff0cf;color:#a66509}
.cancellation-policy-icon svg{width:20px;height:20px}
.cancellation-policy-card strong{display:block;margin-bottom:4px;color:#66430f;font-size:.8rem}
.cancellation-policy-card p{margin:0;color:#80633b;font-size:.68rem;line-height:1.65}
.cancellation-stat-icon{color:#9f3a3a!important;background:#fcebea!important}
.cancellation-empty{min-height:260px;border:1px dashed #d7e4df;border-radius:13px;background:#fff}
.cancellation-tracker-card{overflow:hidden}
.cancellation-table{min-width:1210px}
.cancellation-section .cancellation-table thead th{font-size:.52rem!important;line-height:1.2;letter-spacing:.045em}
.cancellation-cell-note{display:block;margin-top:4px;color:#819089;font-size:.58rem;line-height:1.35;white-space:nowrap}
.cancellation-booking-name{display:block;max-width:190px;color:#153d31;white-space:normal}
.cancellation-status,.refund-eligibility,.refund-status{display:inline-flex;align-items:center;padding:5px 8px;border-radius:999px;font-size:.6rem;font-weight:800;line-height:1.2;white-space:nowrap}
.cancellation-status.pending,.refund-status.not-started,.refund-status.pending{color:#98620a;background:#fff1d3}
.cancellation-status.approved,.cancellation-status.rescheduled,.refund-status.completed,.refund-status.refunded{color:#17644e;background:#dff4ea}
.cancellation-status.rejected,.cancellation-status.cancelled,.refund-status.rejected,.refund-status.declined{color:#a13440;background:#fde7e9}
.refund-status.processing{color:#235f91;background:#e4f1fb}
.refund-eligibility.full-refund{color:#17644e;background:#dff4ea}
.refund-eligibility.partial-refund{color:#9a650b;background:#fff0d0}
.refund-eligibility.deposit-non-refundable,.refund-eligibility.no-payment{color:#a13440;background:#fde7e9}
.refund-amount{color:#17644e;white-space:nowrap}
.view-cancellation-reason{display:inline-flex;align-items:center;justify-content:center;gap:5px;min-width:88px;height:29px;padding:6px 10px;border:1px solid #12604c;border-radius:7px;background:#17735b;color:#fff;font:inherit;font-size:.61rem;font-weight:800;line-height:1;white-space:nowrap;cursor:pointer;box-shadow:0 3px 8px rgba(18,96,76,.16);transition:.18s ease}
.view-cancellation-reason:hover{border-color:#0b4939;background:#105a47;transform:translateY(-1px)}
.view-cancellation-reason svg{width:13px;height:13px;fill:none;stroke:currentColor;stroke-width:2}
.cancellation-row-actions{display:flex;align-items:center;flex-wrap:wrap;gap:6px;min-width:max-content}
.cancellation-row-actions .cancellation-details-btn,.cancellation-row-actions .view-cancellation-reason{width:auto;height:34px;padding:5px 8px;font-size:.58rem;font-weight:800}
.cancellation-row-actions .cancellation-details-btn,.cancellation-row-actions .cancellation-reason-btn{display:inline-flex;flex:0 0 34px;width:34px!important;min-width:34px!important;align-items:center;justify-content:center;padding:0!important}
.cancellation-row-actions .cancellation-details-btn{border:1px solid #24745c;border-radius:7px;background:#2e7d66;color:#fff;box-shadow:0 3px 8px rgba(18,96,76,.14)}
.cancellation-row-actions .cancellation-details-btn:hover{background:#205e4c;transform:translateY(-1px)}
.cancellation-row-actions .cancellation-details-btn svg,.cancellation-row-actions .cancellation-reason-btn svg{width:18px;height:18px;fill:none;stroke:currentColor;stroke-width:2}
.cancellation-row-actions .refund-destination-button{flex-basis:100%}
.cancellation-reason-modal-text{color:#455b53!important;font-size:.82rem!important;line-height:1.65!important;text-align:left!important;white-space:pre-wrap!important}
.profile-billing-overlay{position:fixed;inset:0;z-index:12000;display:none;align-items:center;justify-content:center;padding:22px;background:rgba(8,31,27,.64);backdrop-filter:blur(4px)}
.profile-billing-overlay.show{display:flex}.profile-billing-overlay.receipt-layer{z-index:12020}
.profile-billing-modal{display:flex;width:min(640px,100%);max-height:calc(100vh - 44px);flex-direction:column;overflow:hidden;border:1px solid #d4e4de;border-radius:17px;background:#fff;box-shadow:0 28px 85px rgba(5,31,25,.3)}
.profile-billing-header{display:grid;grid-template-columns:44px 1fr 34px;gap:13px;align-items:center;padding:20px 22px;border-bottom:1px solid #dbe7e2;background:linear-gradient(135deg,#f8fbfa,#eaf5f0)}
.profile-billing-icon{display:grid;width:44px;height:44px;place-items:center;border-radius:11px;color:#fff;background:#1f705a;font-size:20px;font-weight:900;box-shadow:0 7px 16px rgba(31,112,90,.2)}
.profile-billing-header span,.profile-receipt-modal-head span{display:block;color:#668078;font-size:.56rem;font-weight:850;letter-spacing:.12em}.profile-billing-header h3,.profile-receipt-modal-head h3{margin:2px 0 3px;color:#173b32;font-size:1.15rem}.profile-billing-header p{margin:0;color:#687d76;font-size:.69rem}
.profile-modal-x{display:grid;width:32px;height:32px;place-items:center;border:0;border-radius:8px;color:#59736b;background:transparent;font-size:23px;cursor:pointer}.profile-modal-x:hover{color:#164f40;background:#dcece6}
.profile-billing-body{overflow-y:auto;padding:20px 22px;background:#fbfcfc;scrollbar-width:thin;scrollbar-color:#83b7a6 #edf4f1}.profile-billing-body::-webkit-scrollbar{width:7px}.profile-billing-body::-webkit-scrollbar-thumb{border-radius:999px;background:#83b7a6}
.profile-billing-loading{min-height:290px;display:grid;place-content:center;justify-items:center;gap:11px;color:#6d837b;font-size:.72rem}.profile-billing-loading>span{width:29px;height:29px;border:3px solid #d6e6e0;border-top-color:#287a63;border-radius:50%;animation:profileBillSpin .7s linear infinite}@keyframes profileBillSpin{to{transform:rotate(360deg)}}
.profile-bill-top{display:flex;align-items:center;justify-content:space-between;gap:14px;margin-bottom:14px}.profile-bill-ref small,.profile-bill-stat small{display:block;margin-bottom:3px;color:#748780;font-size:.55rem;font-weight:850;letter-spacing:.07em}.profile-bill-ref strong{color:#1d4439;font-size:.85rem}.profile-bill-status{padding:6px 10px;border:1px solid;border-radius:999px;font-size:.62rem;font-weight:850}.profile-bill-status.paid{color:#17694a;background:#e8f6ee;border-color:#bfe1ce}.profile-bill-status.partial{color:#936000;background:#fff7e2;border-color:#eed69b}.profile-bill-status.unpaid{color:#a53b45;background:#fff0f1;border-color:#ecc6ca}
.formal-table .table-col-payment .profile-bill-status{display:inline-flex;min-width:62px;align-items:center;justify-content:center;text-transform:uppercase;letter-spacing:.035em}
.profile-bill-party{display:grid;grid-template-columns:1fr 1fr;gap:11px;padding:13px;margin-bottom:15px;border:1px solid #dde9e5;border-radius:10px;background:#fff}.profile-bill-party small{display:block;margin-bottom:3px;color:#758781;font-size:.55rem;font-weight:850}.profile-bill-party strong{display:block;color:#294c42;font-size:.73rem;overflow-wrap:anywhere}
.profile-bill-title{margin:0 0 8px;color:#294e43;font-size:.66rem;font-weight:850;letter-spacing:.06em;text-transform:uppercase}.profile-bill-lines{overflow:hidden;margin-bottom:15px;border:1px solid #dbe7e2;border-radius:10px;background:#fff}.profile-bill-line{display:flex;justify-content:space-between;gap:18px;padding:10px 12px;border-bottom:1px solid #edf2f0;color:#546d65;font-size:.69rem}.profile-bill-line:last-child{border:0}.profile-bill-line strong{color:#294a40;white-space:nowrap}.profile-bill-line small{display:block;margin-top:3px;color:#8a9a95;font-size:.58rem}.profile-bill-line.total{padding-block:13px;color:#174f40;background:#e4f1ec;font-size:.8rem;font-weight:850}.profile-bill-line.total strong{color:#124c3c;font-size:1rem}
.profile-bill-stats{display:grid;grid-template-columns:repeat(3,1fr);gap:9px}.profile-bill-stat{padding:12px;border:1px solid #dce7e3;border-radius:9px;background:#fff}.profile-bill-stat strong{display:block;color:#21483d;font-size:.79rem}.profile-bill-stat.balance strong{color:#a23f48}.profile-bill-stat.balance.zero strong{color:#176b4c}
.profile-billing-footer,.profile-receipt-footer{display:flex;justify-content:flex-end;align-items:flex-start;gap:9px;padding:14px 22px;border-top:1px solid #dce7e3;background:#fff}.profile-billing-button{min-height:39px;padding:9px 14px;border:1px solid transparent;border-radius:8px;font:inherit;font-size:.69rem;font-weight:850;cursor:pointer}.profile-billing-button.secondary{color:#405b54;background:#fff;border-color:#cfdcd7}.profile-billing-button.receipt{color:#1d654f;background:#edf6f2;border-color:#cce1d9}.profile-billing-button.primary{color:#fff;background:#1f705a;box-shadow:0 5px 12px rgba(31,112,90,.15)}.profile-billing-button:disabled{color:#899b95;background:#e4eae8;border-color:transparent;box-shadow:none;cursor:not-allowed}.profile-paymongo-loading{display:inline-flex;align-items:center;justify-content:center;gap:8px}.profile-paymongo-spinner{width:15px;height:15px;border:2px solid rgba(255,255,255,.42);border-top-color:#fff;border-radius:50%;animation:profilePayMongoSpin .65s linear infinite}@keyframes profilePayMongoSpin{to{transform:rotate(360deg)}}
.profile-receipt-shell{display:flex;width:min(720px,100%);max-height:calc(100vh - 44px);flex-direction:column;overflow:hidden;border-radius:17px;background:#eef4f1;box-shadow:0 28px 85px rgba(5,31,25,.3)}.profile-receipt-modal-head{display:flex;align-items:flex-start;justify-content:space-between;padding:18px 22px;border-bottom:1px solid #d7e4df;background:#fff}.profile-receipt-stage{overflow-y:auto;padding:24px}.profile-receipt-paper{width:min(560px,100%);min-height:620px;margin:auto;padding:35px 38px;color:#29483f;background:#fff;box-shadow:0 12px 36px rgba(20,55,44,.13)}
.receipt-brand{display:flex;justify-content:space-between;gap:20px;padding-bottom:20px;border-bottom:2px solid #26745f}.receipt-brand h2{margin:0;color:#17624f;font-size:1.35rem}.receipt-brand p{margin:3px 0 0;color:#71857e;font-size:.62rem;font-weight:800;letter-spacing:.09em}.receipt-number{text-align:right}.receipt-number small{display:block;color:#7a8c86;font-size:.56rem;font-weight:800}.receipt-number strong{font-size:.77rem}.receipt-paid-stamp{display:inline-block;margin-top:6px;padding:4px 8px;border:1px solid #63a88f;border-radius:999px;color:#1c7055;background:#e8f6ef;font-size:.58rem;font-weight:900}.receipt-meta{display:grid;grid-template-columns:1fr 1fr;gap:13px;margin:21px 0}.receipt-meta small{display:block;margin-bottom:3px;color:#82928d;font-size:.55rem;font-weight:800}.receipt-meta strong{font-size:.72rem}.receipt-table{width:100%;border-collapse:collapse;font-size:.68rem}.receipt-table th,.receipt-table td{padding:10px;border-bottom:1px solid #e5eeea;text-align:left}.receipt-table th:last-child,.receipt-table td:last-child{text-align:right}.receipt-table th{color:#5d756d;background:#f3f8f6;font-size:.58rem;letter-spacing:.05em}.receipt-table .receipt-total td{color:#144d3e;background:#e5f2ed;font-size:.8rem;font-weight:900}.receipt-summary{display:grid;grid-template-columns:repeat(3,1fr);gap:8px;margin-top:18px}.receipt-summary div{padding:10px;border:1px solid #dce8e4;border-radius:8px}.receipt-summary small{display:block;margin-bottom:3px;color:#81928c;font-size:.52rem;font-weight:800}.receipt-summary strong{font-size:.7rem}.receipt-foot{margin-top:25px;padding:12px;color:#647a72;background:#f5f9f7;border-radius:8px;font-size:.59rem;line-height:1.55}.profile-download-wrap{display:grid;justify-items:center}.profile-download-wrap small{max-width:245px;margin-top:5px;color:#71827c;font-size:.58rem;text-align:center}
.profile-payment-shell{width:min(430px,100%);overflow:hidden;border-radius:15px;background:#fff;box-shadow:0 28px 80px rgba(5,31,25,.3)}.profile-payment-body{padding:18px 22px}.profile-payment-due{padding:13px 15px;margin-bottom:15px;border-radius:10px;color:#fff;background:linear-gradient(135deg,#185b4b,#287a65)}.profile-payment-due small{display:block;font-size:.56rem;font-weight:850;letter-spacing:.08em;opacity:.78}.profile-payment-due strong{font-size:1.25rem}.profile-payment-body label{display:block;margin-top:12px}.profile-payment-body label>span{display:block;margin-bottom:5px;color:#405c54;font-size:.68rem;font-weight:800}.profile-payment-body select,.profile-payment-money{width:100%;min-height:41px;border:1px solid #cbdad5;border-radius:9px;background:#fff}.profile-payment-body select{padding:9px 11px;color:#29483f}.profile-payment-money{display:flex;align-items:center;overflow:hidden}.profile-payment-money i{padding-left:11px;color:#526d65;font-style:normal}.profile-payment-money input{width:100%;padding:10px;border:0;outline:0;background:#f7faf9}.profile-payment-body>p{margin:11px 0 0;color:#71847e;font-size:.62rem}

/* History payment alignment and review-dialog refresh */
#history .formal-table .history-payment-column{text-align:center;vertical-align:middle;white-space:nowrap}
#history .formal-table td.history-payment-column .pill{display:inline-flex;min-width:66px;align-items:center;justify-content:center;margin:0 auto;padding:5px 10px;text-transform:uppercase;letter-spacing:.035em}
.af-modal-overlay{z-index:100400;padding:20px;background:rgba(7,30,24,.68);backdrop-filter:blur(6px)}
#reviewModal .af-modal,#af-feedback-modal .af-modal{width:min(610px,100%);max-width:none;max-height:calc(100vh - 40px);display:flex;flex-direction:column;padding:0;overflow:hidden;border:1px solid #d3e2dd;border-radius:18px;background:#fff;box-shadow:0 28px 80px rgba(4,29,22,.34);font-family:Inter,system-ui,-apple-system,"Segoe UI",sans-serif}
#af-feedback-modal .af-modal{width:min(500px,100%);overflow-y:auto;scrollbar-width:thin;scrollbar-color:#83b3a4 #edf3f1}
#reviewModal .af-modal-header,#af-feedback-modal .af-modal-header{min-height:70px;display:flex;align-items:center;justify-content:space-between;gap:14px;margin:0;padding:14px 18px;border-bottom:1px solid #dce7e3;background:linear-gradient(135deg,#fbfdfc,#eaf5f0)}
#reviewModal .af-modal-header strong,#af-feedback-modal .af-modal-header strong{display:flex;align-items:center;gap:11px;color:#194d3e;font-size:15px;font-weight:850}
#reviewModal .af-modal-header strong::before,#af-feedback-modal .af-modal-header strong::before{content:"";width:38px;height:38px;display:block;flex:0 0 38px;border-radius:10px;background-color:#dff1ea;background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24'%3E%3Cpath fill='%231e7058' d='m12 2.7 2.8 5.7 6.3.9-4.6 4.5 1.1 6.3-5.6-3-5.6 3 1.1-6.3-4.6-4.5 6.3-.9L12 2.7Z'/%3E%3C/svg%3E");background-position:center;background-repeat:no-repeat;background-size:17px 17px}
#reviewModal .af-modal-header button,#af-feedback-modal .af-modal-header button{width:34px;height:34px;display:grid;place-items:center;padding:0;border:0;border-radius:9px;color:#567168;background:#edf3f1;font-size:22px;font-weight:500;cursor:pointer}
#reviewModal .af-modal-header button:hover,#af-feedback-modal .af-modal-header button:hover{color:#154f3e;background:#dceae5;transform:none}
#reviewForm{min-height:0;padding:17px 18px 16px;overflow-y:auto;gap:12px;background:#fbfcfc;scrollbar-width:thin;scrollbar-color:#83b3a4 #edf3f1}
.hotel-review-context{grid-column:1/-1;display:grid;grid-template-columns:40px minmax(0,1fr);align-items:center;gap:11px;padding:11px 13px;border:1px solid #cfe2da;border-radius:11px;background:linear-gradient(135deg,#f5faf8,#edf7f3)}
.hotel-review-context-icon{width:40px;height:40px;display:grid;place-items:center;border-radius:10px;color:#1d7057;background:#dcefe8}
.hotel-review-context-icon svg{width:19px;height:19px}
.hotel-review-context>div{min-width:0}
.hotel-review-context small{display:block;margin-bottom:2px;color:#40806c;font-size:8px;font-weight:900;letter-spacing:.1em}
.hotel-review-context strong{display:block;overflow:hidden;color:#21483c;font-size:12px;text-overflow:ellipsis;white-space:nowrap}
.hotel-review-context>div>span{display:block;margin-top:3px;overflow-wrap:anywhere;color:#73867f;font-size:9px}
#reviewForm .review-group{min-width:0;margin:0;padding:12px 13px;border:1px solid #dce8e4;border-radius:11px;background:#fff}
#reviewForm .review-group:has(textarea){padding:0;border:0;background:transparent}
#reviewForm .af-package-title{margin:0 0 8px;color:#36584e;font-size:10px;font-weight:850;letter-spacing:.015em}
#reviewForm .rating{min-height:29px;gap:4px}
#reviewForm .rating svg{width:25px;height:25px;filter:drop-shadow(0 1px 1px rgba(20,72,56,.08))}
#reviewForm textarea,#af-feedback-modal textarea{width:100%;min-height:105px;padding:12px 13px;border:1px solid #cbded7;border-radius:10px;color:#29463d;background:#f7fbf9;font:inherit;font-size:11px;line-height:1.5;resize:vertical;outline:0}
#reviewForm textarea:focus,#af-feedback-modal textarea:focus{border-color:#58a087;background:#fff;box-shadow:0 0 0 3px rgba(43,122,102,.11)}
#reviewForm textarea:disabled,#af-feedback-modal textarea[readonly]{color:#49655c;background:#f1f6f4;opacity:1}
#reviewForm .af-actions{margin:2px 0 0;padding-top:14px;border-top:1px solid #e0e9e5}
#reviewModal .af-btn,#af-feedback-modal .af-btn{min-height:40px;padding:9px 15px;border:1px solid transparent;border-radius:9px;font:inherit;font-size:10px;font-weight:850;box-shadow:none}
#reviewModal .af-btn:not(.secondary),#af-feedback-modal .af-btn:not(.secondary){border-color:#1f7059;background:linear-gradient(135deg,#2b7a66,#1d654f);box-shadow:0 5px 12px rgba(31,105,84,.16)}
#reviewModal .af-btn.secondary,#af-feedback-modal .af-btn.secondary{border-color:#cbdad5;background:#fff;color:#3d5a51}
#reviewModal .af-btn.secondary:hover,#af-feedback-modal .af-btn.secondary:hover{border-color:#acc9bf;background:#edf5f2}
#af-feedback-modal .af-review-info{display:grid;gap:8px;margin:17px 18px 0;padding:12px 13px;border:1px solid #d9e7e2;border-radius:11px;background:#f5faf8}
#af-feedback-modal .af-review-info p{display:grid;grid-template-columns:82px minmax(0,1fr);gap:8px;margin:0;color:#778983;font-size:10px;font-weight:700}
#af-feedback-modal .af-review-info span{overflow-wrap:anywhere;color:#284a40;font-weight:800}
#af-feedback-modal #af-star-rating{position:relative;min-height:82px;justify-content:center;gap:7px;margin:12px 18px 0;padding:34px 14px 12px;border:1px solid #dce8e4;border-radius:11px;background:#fff}
#af-feedback-modal #af-star-rating::after{content:"Overall rating";position:absolute;top:11px;left:13px;color:#36584e;font-size:10px;font-weight:850}
#af-feedback-modal #af-star-rating label{color:#ced8d4;font-size:31px;line-height:1;transition:color .16s,transform .16s}
#af-feedback-modal #af-star-rating label:hover,#af-feedback-modal #af-star-rating label:hover~label,#af-feedback-modal #af-star-rating input:checked~label{color:#e2aa27;transform:translateY(-1px)}
#af-feedback-modal>*,#af-feedback-modal .af-modal>*{box-sizing:border-box}
#af-feedback-modal .af-modal>br{display:none}
#af-feedback-modal #af-feedback-comment{width:calc(100% - 36px);min-height:105px;margin:12px 18px 0}
#af-feedback-modal .af-actions{margin:0;padding:14px 18px 16px;border-top:1px solid #e0e9e5;background:#fff}
@media(max-width:620px){.af-modal-overlay{align-items:flex-end;padding:0}#reviewModal .af-modal,#af-feedback-modal .af-modal{width:100%;max-height:96vh;border-radius:18px 18px 0 0}#reviewForm{grid-template-columns:1fr}#reviewForm .review-group:has(textarea),#reviewForm .af-actions{grid-column:auto}#reviewModal .af-actions .af-btn,#af-feedback-modal .af-actions .af-btn{flex:1}}
@media(max-width:620px){.profile-billing-overlay{padding:0;align-items:stretch}.profile-billing-modal,.profile-receipt-shell{max-height:100vh;border-radius:0}.profile-bill-party,.profile-bill-stats{grid-template-columns:1fr}.profile-billing-footer{display:grid;grid-template-columns:1fr 1fr}.profile-billing-footer .primary{grid-column:1/-1}.profile-receipt-stage{padding:12px}.profile-receipt-paper{min-height:0;padding:24px 19px}.receipt-summary{grid-template-columns:1fr}.profile-receipt-footer{padding:12px}.profile-download-wrap{flex:1}.profile-download-wrap .primary{width:100%}}

/* Provider cancellation decision: booking-row alert and formal modal */
.booking-decision-required-row td{background:#fffdf6!important;border-top:1px solid #e7bf70;border-bottom:0!important}.booking-decision-required-row td:first-child{border-left:3px solid #d79a27}.booking-decision-required-row td:last-child{border-right:1px solid #e7bf70}.booking-decision-date-note{display:block;margin-top:4px;color:#9a6410;font-size:.52rem;font-weight:850;white-space:nowrap}.status-pill.decision-required{color:#8a5705!important;background:#fff0cd!important;border:1px solid #e8ca87!important;white-space:normal;text-align:center}.booking-decision-row-actions{display:grid;min-width:105px}.booking-respond-btn{border:0;border-radius:7px;padding:8px 11px;color:#fff;background:#176b58;box-shadow:0 3px 8px rgba(23,107,88,.17);font:inherit;font-size:.56rem;font-weight:850;white-space:nowrap;cursor:pointer}.booking-decision-alert-row td{padding:0 12px 11px!important;border:0!important;background:#fffdf6!important}.booking-decision-alert-row td:first-child{border-left:3px solid #d79a27!important}.booking-decision-inline-alert{display:flex;align-items:center;gap:10px;padding:10px 12px;border:1px solid #ecd8a9;border-radius:9px;background:#fff7e5}.booking-decision-alert-icon{display:grid;place-items:center;flex:0 0 31px;width:31px;height:31px;border-radius:8px;color:#98610a;background:#ffe6ae}.booking-decision-alert-icon svg{width:17px;height:17px;fill:none;stroke:currentColor;stroke-width:1.9}.booking-decision-inline-alert>div{min-width:0;flex:1}.booking-decision-inline-alert strong{display:block;color:#704d13;font-size:.62rem}.booking-decision-inline-alert p{margin:3px 0 0;color:#876a37;font-size:.54rem;line-height:1.45}.booking-decision-inline-alert p b{color:#72521e}.booking-decision-inline-alert p span{margin:0 4px}
.profile-decision-overlay{position:fixed;inset:0;z-index:130000;display:none;align-items:center;justify-content:center;padding:20px;background:rgba(4,30,23,.72);backdrop-filter:blur(6px)}.profile-decision-overlay.open{display:flex;animation:profileDecisionFade .18s ease-out}.profile-decision-modal{position:relative;width:min(700px,100%);max-height:calc(100vh - 40px);overflow:auto;border:1px solid #c9ddd5;border-radius:18px;background:#fff;box-shadow:0 30px 90px rgba(3,27,20,.38);animation:profileDecisionRise .2s ease-out}.profile-decision-header{display:grid;grid-template-columns:46px minmax(0,1fr) 36px;align-items:center;gap:13px;padding:20px 23px;border-bottom:1px solid #dbe8e3;background:linear-gradient(135deg,#f1f8f5,#fff 76%)}.profile-decision-header-icon{display:grid;place-items:center;width:46px;height:46px;border:1px solid #c8e1d8;border-radius:12px;color:#17654f;background:#def0e9}.profile-decision-header svg,.profile-decision-close svg,.profile-decision-options svg,.profile-decision-provider-alert svg,.profile-decision-btn svg{width:20px;height:20px;fill:none;stroke:currentColor;stroke-width:1.8;stroke-linecap:round;stroke-linejoin:round}.profile-decision-header>div:nth-child(2)>span{display:block;color:#4e806f;font-size:.51rem;font-weight:900;letter-spacing:.13em}.profile-decision-header h3{margin:3px 0;color:#123e31;font-size:1.05rem}.profile-decision-header p{margin:0;color:#697f77;font-size:.61rem}.profile-decision-close{display:grid;place-items:center;width:34px;height:34px;border:1px solid #d5e2dd;border-radius:9px;color:#557068;background:#fff;cursor:pointer}.profile-decision-close:hover{color:#a72c3b;background:#fff3f4}.profile-decision-close svg{width:16px;height:16px}
.profile-decision-progress{display:grid;grid-template-columns:auto 1fr auto 1fr auto;align-items:center;padding:13px 25px;border-bottom:1px solid #e2ebe7;background:#fbfdfc}.profile-decision-progress>i{height:1px;margin:0 12px;background:#d8e4df}.profile-decision-progress>div{display:flex;align-items:center;gap:7px;color:#879790}.profile-decision-progress>div span{display:grid;place-items:center;width:25px;height:25px;border:1px solid #cbd9d4;border-radius:50%;background:#fff;font-size:.55rem;font-weight:900}.profile-decision-progress>div small{font-size:.53rem;font-weight:800;white-space:nowrap}.profile-decision-progress>div.active{color:#17634f}.profile-decision-progress>div.active span{border-color:#17634f;background:#176b58;color:#fff}.profile-decision-progress>div.complete{color:#3f7866}.profile-decision-progress>div.complete span{border-color:#bdd9cf;background:#e3f2ec;color:#17634f}
.profile-decision-body{padding:18px 23px 21px}.profile-decision-context{display:grid;grid-template-columns:1fr 1.45fr 1fr;gap:1px;overflow:hidden;margin-bottom:12px;border:1px solid #dbe7e3;border-radius:9px;background:#dbe7e3}.profile-decision-context>div{min-width:0;padding:10px 12px;background:#f5f9f7}.profile-decision-context small,.profile-decision-locked-grid small,.profile-decision-review small{display:block;margin-bottom:3px;color:#788b84;font-size:.48rem;font-weight:900;letter-spacing:.08em}.profile-decision-context strong{display:block;overflow:hidden;color:#1c463a;font-size:.62rem;text-overflow:ellipsis;white-space:nowrap}.profile-decision-provider-alert{display:flex;align-items:flex-start;gap:10px;margin-bottom:17px;padding:11px 12px;border-left:3px solid #d89a27;border-radius:8px;color:#77551d;background:#fff7e5}.profile-decision-provider-alert>svg{flex:0 0 18px;width:18px;height:18px}.profile-decision-provider-alert strong{display:block;font-size:.62rem}.profile-decision-provider-alert p{margin:3px 0;color:#826635;font-size:.55rem;line-height:1.4}.profile-decision-provider-alert small{font-size:.5rem}
#profileDecisionOriginal{overflow:visible;line-height:1.35;text-overflow:clip;white-space:normal}
.profile-decision-step{display:none}.profile-decision-step.active{display:block;animation:profileDecisionStep .18s ease-out}.profile-decision-section-heading{display:flex;gap:10px;margin-bottom:13px}.profile-decision-section-heading>span{display:grid;place-items:center;flex:0 0 30px;height:30px;border-radius:8px;color:#17644f;background:#e1f1eb;font-size:.52rem;font-weight:900}.profile-decision-section-heading h4{margin:1px 0 3px;color:#173f33;font-size:.76rem}.profile-decision-section-heading p{margin:0;color:#74867f;font-size:.54rem;line-height:1.4}.profile-decision-options{display:grid;grid-template-columns:1fr 1fr;gap:11px}.profile-decision-options>label{position:relative;display:grid;grid-template-columns:39px minmax(0,1fr) 17px;gap:10px;min-height:112px;padding:14px;border:1px solid #d7e5e0;border-radius:11px;cursor:pointer;transition:.17s}.profile-decision-options>label:hover{border-color:#8ebdab;box-shadow:0 5px 15px rgba(24,91,70,.08)}.profile-decision-options>label.refund:hover{border-color:#ddaab0}.profile-decision-options input{position:absolute;opacity:0}.profile-decision-option-icon{display:grid;place-items:center;width:39px;height:39px;border-radius:10px;color:#17664f;background:#def0e9}.profile-decision-options label.refund .profile-decision-option-icon{color:#b12c3c;background:#fbe7e9}.profile-decision-options strong{display:block;margin:2px 0 5px;color:#17503f;font-size:.7rem}.profile-decision-options label.refund strong{color:#a62535}.profile-decision-options small{display:block;color:#687c75;font-size:.56rem;line-height:1.5}.profile-decision-options label>i{width:17px;height:17px;margin-top:3px;border:1.5px solid #acbbb5;border-radius:50%;background:#fff}.profile-decision-options label:has(input:checked){border-color:#23735b;background:#f3faf7;box-shadow:0 0 0 2px rgba(35,115,91,.09)}.profile-decision-options label.refund:has(input:checked){border-color:#b43242;background:#fff7f7}.profile-decision-options input:checked~i{border:5px solid #176b58}.profile-decision-options label.refund input:checked~i{border-color:#b52d3d}
.profile-decision-locked-grid,.profile-decision-review{display:grid;grid-template-columns:1fr 1fr;gap:8px}.profile-decision-locked-grid>div,.profile-decision-review>div{min-width:0;padding:10px 12px;border:1px solid #dce8e4;border-radius:8px;background:#f7faf9}.profile-decision-locked-grid strong,.profile-decision-review strong{display:block;overflow-wrap:anywhere;color:#21483c;font-size:.61rem}.profile-decision-date-field{display:block;margin-top:14px}.profile-decision-date-field>span{display:block;margin-bottom:6px;color:#285044;font-size:.61rem;font-weight:850}.profile-decision-date-field b{color:#b32a3a}.profile-decision-date-field input{width:100%;height:41px;padding:0 11px;border:1px solid #cbdcd6;border-radius:8px;color:#244a3e;background:#fff;font:inherit;font-size:.65rem;outline:0}.profile-decision-date-field input:focus{border-color:#278064;box-shadow:0 0 0 3px rgba(39,128,100,.11)}.profile-decision-date-field small{display:block;margin-top:5px;color:#7d8e88;font-size:.51rem}.profile-decision-review .wide{grid-column:1/-1}.profile-decision-final-notice{margin-top:11px;padding:11px 13px;border-left:3px solid #258064;border-radius:7px;color:#285f4d;background:#edf7f3;font-size:.56rem;line-height:1.55}.profile-decision-final-notice.refund{border-left-color:#bd3444;color:#812936;background:#fff1f2}.profile-decision-ack{display:flex;gap:8px;margin-top:12px;padding:10px 11px;border:1px solid #d8e4e0;border-radius:8px;color:#526a62;font-size:.55rem;line-height:1.4;cursor:pointer}.profile-decision-ack input{width:14px;height:14px;margin:0;accent-color:#176b58}.profile-decision-error{min-height:13px;margin:6px 0 0;color:#b22b3b;font-size:.52rem;font-weight:750}
.profile-decision-date-field input[readonly]{cursor:pointer;background:#fff}.profile-decision-calendar-meta{display:flex;align-items:center;justify-content:space-between;gap:10px;margin-top:6px;color:#6e827a;font-size:.51rem}.profile-decision-calendar-meta>span:first-child{font-weight:700}.profile-decision-calendar-legend{display:inline-flex;align-items:center;gap:5px;white-space:nowrap}.profile-decision-calendar-legend i{position:relative;width:15px;height:15px;border-radius:4px;background:#e5e8e7}.profile-decision-calendar-legend i:after{content:"";position:absolute;top:7px;left:2px;width:11px;height:1px;background:#929c98;transform:rotate(-35deg)}.profile-decision-range-summary{margin-top:8px;padding:8px 10px;border:1px solid #cfe3db;border-radius:8px;color:#275849;background:#eff8f4;font-size:.54rem;font-weight:750}.profile-decision-range-summary[hidden]{display:none}
.flatpickr-calendar.profile-reschedule-calendar{z-index:130100!important;height:auto!important;max-height:none!important;padding:10px;border:1px solid #d9e7e1;border-radius:16px;box-shadow:0 20px 55px rgba(20,65,51,.22);overflow:visible!important;font-family:Inter,system-ui,sans-serif}.flatpickr-calendar.profile-reschedule-calendar.tour-single-calendar{width:307px;max-width:calc(100vw - 32px);box-sizing:content-box}.flatpickr-calendar.profile-reschedule-calendar.tour-range-calendar{width:780px;min-width:760px;max-width:calc(100vw - 20px);box-sizing:border-box}.flatpickr-calendar.profile-reschedule-calendar .flatpickr-months{padding:3px 8px 8px}.flatpickr-calendar.profile-reschedule-calendar .flatpickr-current-month,.flatpickr-calendar.profile-reschedule-calendar .flatpickr-weekday{color:#23483c;font-weight:800}.flatpickr-calendar.profile-reschedule-calendar .flatpickr-weekdays{background:#f1f8f4;border-radius:10px}.flatpickr-calendar.profile-reschedule-calendar .flatpickr-day{border-radius:9px;color:#29483e;font-weight:650}.flatpickr-calendar.profile-reschedule-calendar .flatpickr-day:hover{border-color:#d8ebe4;background:#eaf5f1}.flatpickr-calendar.profile-reschedule-calendar .flatpickr-day.today{border-color:#2b7a66;color:#1f5f4e}.flatpickr-calendar.profile-reschedule-calendar .flatpickr-day.selected,.flatpickr-calendar.profile-reschedule-calendar .flatpickr-day.startRange,.flatpickr-calendar.profile-reschedule-calendar .flatpickr-day.endRange{border-color:#2b7a66;background:#2b7a66;color:#fff}.flatpickr-calendar.profile-reschedule-calendar .flatpickr-day.inRange{border-color:#e8f2ec;background:#e8f2ec;color:#173826;box-shadow:-5px 0 0 #e8f2ec,5px 0 0 #e8f2ec}.flatpickr-calendar.profile-reschedule-calendar .flatpickr-day.flatpickr-disabled,.flatpickr-calendar.profile-reschedule-calendar .flatpickr-day.flatpickr-disabled:hover{border-color:transparent!important;background:#e5e8e7!important;color:#9aa39f!important;box-shadow:none!important;cursor:not-allowed!important;text-decoration:line-through;opacity:1!important}.flatpickr-calendar.profile-reschedule-calendar.tour-range-calendar .dayContainer,.flatpickr-calendar.profile-reschedule-calendar.tour-range-calendar .flatpickr-weekdaycontainer{min-width:360px;max-width:360px;width:360px}.flatpickr-calendar.profile-reschedule-calendar.tour-range-calendar .flatpickr-days{width:auto!important;padding:0 8px 6px;overflow:visible!important}.flatpickr-calendar.profile-reschedule-calendar.tour-range-calendar .flatpickr-innerContainer,.flatpickr-calendar.profile-reschedule-calendar.tour-range-calendar .flatpickr-rContainer{min-width:720px;height:auto!important;max-height:none!important;overflow:visible!important}.flatpickr-calendar.profile-reschedule-calendar.tour-range-calendar .flatpickr-day{min-width:calc(100% / 7);width:calc(100% / 7);max-width:none}
.profile-decision-footer{display:flex;align-items:center;gap:8px;padding:14px 23px;border-top:1px solid #dbe7e3;background:#f8fbfa}.profile-decision-footer>span{flex:1}.profile-decision-btn{display:inline-flex;align-items:center;justify-content:center;gap:6px;min-height:37px;padding:8px 13px;border-radius:8px;font:inherit;font-size:.58rem;font-weight:850;cursor:pointer}.profile-decision-btn svg{width:13px;height:13px}.profile-decision-btn.primary{border:1px solid #17634f;color:#fff;background:#176b58}.profile-decision-btn.primary.refund{border-color:#ac2737;background:#ba2d3e}.profile-decision-btn.secondary,.profile-decision-btn.ghost{border:1px solid #ccd9d4;color:#50675e;background:#fff}.profile-decision-btn[hidden]{display:none!important}.profile-decision-processing{position:absolute;inset:0;z-index:4;display:flex;flex-direction:column;align-items:center;justify-content:center;color:#174c3c;background:rgba(255,255,255,.95)}.profile-decision-processing[hidden]{display:none}.profile-decision-processing>span{width:32px;height:32px;margin-bottom:11px;border:3px solid #d5e7e0;border-top-color:#176b58;border-radius:50%;animation:profileDecisionSpin .7s linear infinite}.profile-decision-processing strong{font-size:.72rem}.profile-decision-processing small{margin-top:4px;color:#71847d;font-size:.52rem}
@keyframes profileDecisionFade{from{opacity:0}to{opacity:1}}@keyframes profileDecisionRise{from{opacity:0;transform:translateY(10px) scale(.987)}to{opacity:1;transform:none}}@keyframes profileDecisionStep{from{opacity:0;transform:translateX(7px)}to{opacity:1;transform:none}}@keyframes profileDecisionSpin{to{transform:rotate(360deg)}}
@media(max-width:820px){.flatpickr-calendar.profile-reschedule-calendar.tour-range-calendar{width:307px!important;min-width:0;max-width:calc(100vw - 20px)!important}.flatpickr-calendar.profile-reschedule-calendar.tour-range-calendar .flatpickr-innerContainer,.flatpickr-calendar.profile-reschedule-calendar.tour-range-calendar .flatpickr-rContainer,.flatpickr-calendar.profile-reschedule-calendar.tour-range-calendar .flatpickr-days,.flatpickr-calendar.profile-reschedule-calendar.tour-range-calendar .dayContainer,.flatpickr-calendar.profile-reschedule-calendar.tour-range-calendar .flatpickr-weekdaycontainer{width:307px!important;min-width:307px;max-width:307px}}
@media(max-width:700px){.booking-decision-inline-alert{align-items:flex-start;flex-wrap:wrap}.booking-inline-respond{margin-left:41px}.profile-decision-overlay{align-items:flex-end;padding:0}.profile-decision-modal{width:100%;max-height:96vh;border-radius:17px 17px 0 0}.profile-decision-header{padding:16px}.profile-decision-progress{padding:11px 16px}.profile-decision-body{padding:15px}.profile-decision-context{grid-template-columns:1fr}.profile-decision-options,.profile-decision-locked-grid{grid-template-columns:1fr}.profile-decision-options>label{min-height:0}.profile-decision-footer{padding:12px 15px;flex-wrap:wrap}.profile-decision-footer>span{display:none}.profile-decision-btn{flex:1}.profile-decision-calendar-meta{align-items:flex-start;flex-direction:column}}
</style>
</head>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/cropperjs@1.6.2/dist/cropper.min.css">
<script src="https://cdn.jsdelivr.net/npm/cropperjs@1.6.2/dist/cropper.min.js"></script>

<?php if (isset($_GET['profile_updated'])): ?>
<?php
$allowedProfileUpdatedFields = ['pic', 'phone', 'address'];
$requestedProfileUpdatedFields = array_filter(array_map('trim', explode(',', (string)($_GET['fields'] ?? ''))));
$safeProfileUpdatedFields = array_values(array_intersect($allowedProfileUpdatedFields, $requestedProfileUpdatedFields));
?>
<script>
document.addEventListener("DOMContentLoaded", function () {

    let fields = <?= json_encode(implode(',', $safeProfileUpdatedFields), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    let list = [];

    if (fields.includes("pic")) list.push("Profile Picture");
    if (fields.includes("phone")) list.push("Phone Number");
    if (fields.includes("address")) list.push("Address");

    Swal.fire({
        icon: 'success',
        title: 'Profile Updated!',
        html: "Updated: <b>" + list.join(", ") + "</b>",
        confirmButtonColor: '#0ea5e9'
    });

    window.history.replaceState({}, document.title, "profile.php");
});
</script>
<?php endif; ?>

<script>
document.addEventListener("DOMContentLoaded", function () {

    const params = new URLSearchParams(window.location.search);

    const fire = (opt) => {
        Swal.fire(opt).then(() => {
            window.history.replaceState({}, document.title, "profile.php");
        });
    };

    // PASSWORD SUCCESS
    if (params.get("password_changed") === "1") {
        fire({
            icon: 'success',
            title: 'Password Changed!',
            text: 'Your password was updated successfully.',
            confirmButtonColor: '#10b981'
        });
    }

    // ERRORS
    const error = params.get("error");

    if (error === "user_not_found") {
        fire({
            icon: 'error',
            title: 'User Not Found',
            text: 'Please re-login.',
            confirmButtonColor: '#ef4444'
        });
    }

    if (params.get("error") === "server_error") {
    fire({
        icon: 'error',
        title: 'Server Error',
        text: params.get("msg") || 'Something went wrong.',
        confirmButtonColor: '#ef4444'
    });
}
});
</script>
<body>
<div class="container">
  <aside class="sidebar">
    <div class="sidebar-brand-row">
      <button class="sidebar-brand-back" type="button" aria-label="Back to previous page" title="Back to previous page" onclick="returnFromProfile()">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m15 18-6-6 6-6"></path></svg>
      </button>
      <a class="sidebar-brand" href="../" aria-label="iTour Mercedes homepage">
        <img class="sidebar-brand-mark" src="../img/email-logo.png" alt="">
        <span class="sidebar-brand-copy">
          <img src="../img/textlogo2-white.png" alt="iTour Mercedes">
          <small>Tourist Portal</small>
        </span>
      </a>
    </div>
    <div class="profile-area">
      <div class="sidebar-avatar-wrap">
        <img src="<?= htmlspecialchars($display_profile_pic, ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars((string)$user['full_name'], ENT_QUOTES, 'UTF-8') ?>" class="profile-img" onerror="this.onerror=null;this.src='../img/profileicon.png';">
        <span aria-label="Account active"></span>
      </div>
      <small class="sidebar-account-label">Tourist account</small>
      <h3><?= htmlspecialchars($user['full_name']) ?></h3>
      <p><?= htmlspecialchars($user['email']) ?></p>
      <span class="sidebar-account-state"><i></i> Active account</span>
    </div>
    <span class="sidebar-nav-title">Account navigation</span>
    <nav class="nav-links" aria-label="Tourist account sections">
      <a href="#" data-section="profile">
        <span class="nav-item-label"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="8" r="4"></circle><path d="M4 21a8 8 0 0 1 16 0"></path></svg>My Profile</span>
      </a>
      <a href="#" data-section="bookings">
        <span class="nav-item-label"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="5" width="18" height="16" rx="2"></rect><path d="M16 3v4M8 3v4M3 11h18"></path></svg>Bookings</span>
      </a>
      <a href="#" class="nav-link-row" data-section="cancel-bookings">
        <span class="nav-item-label"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="12" r="9"></circle><path d="m9 9 6 6M15 9l-6 6"></path></svg>Cancel Bookings</span>
        <?php if ($cancellationSummary['total'] > 0): ?><span class="nav-count"><?= (int)$cancellationSummary['total'] ?></span><?php endif; ?>
      </a>
      <a href="#" class="nav-link-row" data-section="favorites">
        <span class="nav-item-label"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M20.8 4.6a5.5 5.5 0 0 0-7.8 0L12 5.7l-1.1-1.1a5.5 5.5 0 0 0-7.8 7.8l1.1 1.1L12 21l7.8-7.5 1.1-1.1a5.5 5.5 0 0 0-.1-7.8Z"></path></svg>Favorites</span>
        <span class="nav-count" id="favoritesNavCount"><?= (int)$favoriteCounts['all'] ?></span>
      </a>
      <a href="#" class="nav-link-row" data-section="complaints">
        <span class="nav-item-label"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 3 3.8 6.4v5.2c0 4.7 3.5 7.9 8.2 9.4 4.7-1.5 8.2-4.7 8.2-9.4V6.4L12 3Z"></path><path d="M12 8v4.5M12 16h.01"></path></svg>Complaints &amp; Incidents</span>
        <?php if ($complaintCounts['all'] > 0): ?><span class="nav-count"><?= (int)$complaintCounts['all'] ?></span><?php endif; ?>
      </a>
      <a href="#" data-section="history">
        <span class="nav-item-label"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M3 12a9 9 0 1 0 3-6.7L3 8"></path><path d="M3 3v5h5M12 7v5l3 2"></path></svg>History</span>
      </a>
    </nav>
   <div class="logout">
      <div class="sidebar-session-status">
        <span><i></i> Signed in securely</span>
        <small>Tourist ID T-<?= str_pad((string)$tourist_id, 5, '0', STR_PAD_LEFT) ?></small>
      </div>
      <form method="POST" onsubmit="return confirm('Logout?');">
        <input type="hidden" name="action" value="logout">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($profileLogoutCsrf, ENT_QUOTES, 'UTF-8') ?>">
        <button class="sidebar-logout-button" type="submit">
          <svg width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10 17l5-5-5-5M15 12H3"></path><path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"></path></svg>
          <span><strong>Sign out</strong><small>End your current session</small></span>
        </button>
      </form>
    </div>

  </aside>

  <main class="main">
    <nav class="mobile-profile-nav" aria-label="Profile sections">
      <div class="mobile-profile-topbar">
        <button class="mobile-profile-back" type="button" aria-label="Back to previous page" title="Back to previous page" onclick="returnFromProfile()">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m15 18-6-6 6-6"></path></svg>
        </button>
        <div class="mobile-profile-brand" aria-label="iTour Mercedes">
          <img class="mobile-profile-brand-mark" src="../img/newlogo.png" alt="Mercedes tourism logo">
          <img class="mobile-profile-brand-text" src="../img/textlogo2.png" alt="iTour Mercedes">
        </div>
        <button class="mobile-profile-account" type="button" aria-label="Open profile" title="Open profile" onclick="document.querySelector('.mobile-profile-nav-scroll a[data-section=\'profile\']')?.click();">
          <img src="<?= htmlspecialchars($display_profile_pic, ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars((string)$user['full_name'], ENT_QUOTES, 'UTF-8') ?>" onerror="this.onerror=null;this.src='../img/profileicon.png';">
        </button>
      </div>
      <div class="mobile-profile-nav-scroll">
        <a href="#profile" data-section="profile"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="8" r="4"></circle><path d="M4 21a8 8 0 0 1 16 0"></path></svg><span>Profile</span></a>
        <a href="#bookings" data-section="bookings"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="5" width="18" height="16" rx="2"></rect><path d="M16 3v4M8 3v4M3 11h18"></path></svg><span>Bookings</span></a>
        <a href="#cancel-bookings" data-section="cancel-bookings"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="12" r="9"></circle><path d="m9 9 6 6M15 9l-6 6"></path></svg><span>Cancel Bookings</span></a>
        <a href="#favorites" data-section="favorites"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M20.8 4.6a5.5 5.5 0 0 0-7.8 0L12 5.7l-1.1-1.1a5.5 5.5 0 0 0-7.8 7.8l1.1 1.1L12 21l7.8-7.5 1.1-1.1a5.5 5.5 0 0 0-.1-7.8Z"></path></svg><span>Favorites</span></a>
        <a href="#complaints" data-section="complaints"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 3 3.8 6.4v5.2c0 4.7 3.5 7.9 8.2 9.4 4.7-1.5 8.2-4.7 8.2-9.4V6.4L12 3Z"></path><path d="M12 8v4.5M12 16h.01"></path></svg><span>Complaints</span></a>
        <a href="#history" data-section="history"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M3 12a9 9 0 1 0 3-6.7L3 8"></path><path d="M3 3v5h5M12 7v5l3 2"></path></svg><span>History</span></a>
      </div>
    </nav>

<!-- PROFILE SECTION -->
<section id="profile" class="profile-section section active">

  <div class="page-header">
    <div class="profile-page-heading">
      <span class="profile-heading-icon">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="8" r="4"></circle><path d="M4 21a8 8 0 0 1 16 0"></path></svg>
      </span>
      <div>
        <span class="profile-eyebrow">My account</span>
        <h1>Profile &amp; preferences</h1>
        <p>Keep your contact details accurate for smoother bookings and updates.</p>
      </div>
    </div>
    <span class="profile-status">Account active</span>
  </div>

  <!-- PROFILE ROW -->
  <div class="profile-row profile-summary-card">
    <img src="<?= htmlspecialchars($display_profile_pic, ENT_QUOTES, 'UTF-8') ?>" 
         alt="profile" 
         class="profile-avatar"
         onerror="this.onerror=null;this.src='../img/profileicon.png';">

    <div class="profile-info">
      <h2><?= htmlspecialchars($user['full_name']) ?></h2>
      <p class="profile-email"><?= htmlspecialchars($user['email']) ?></p>

      <div class="profile-meta">
        <span>
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M21 10c0 7-9 12-9 12S3 17 3 10a9 9 0 1 1 18 0Z"></path><circle cx="12" cy="10" r="3"></circle></svg>
          <span><?= htmlspecialchars($user['address'] ?? 'No address yet') ?></span>
        </span>
        <span>
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.8 19.8 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6A19.8 19.8 0 0 1 2.12 4.18 2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.12.9.33 1.78.62 2.63a2 2 0 0 1-.45 2.11L8 9.73a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.85.29 1.73.5 2.63.62A2 2 0 0 1 22 16.92Z"></path></svg>
          <span><?= htmlspecialchars($user['phone_number'] ?? 'No phone number') ?></span>
        </span>
      </div>

    </div>
    <div class="profile-summary-actions">
      <button id="toggleEditBtn" class="btn" type="button" aria-expanded="false" aria-controls="editArea">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9"><path d="M12 20h9"></path><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L8 18l-4 1 1-4Z"></path></svg>
        <span>Edit profile</span>
      </button>
    </div>
  </div>

  <!-- EDIT PANEL -->
  <div id="editArea" class="account-settings-panel" style="display:none">
    <div class="settings-panel-header">
      <div>
        <h2>Account settings</h2>
        <p>Update your contact information, profile photo, or password.</p>
      </div>
      <button type="button" class="icon-button close-edit-panel" aria-label="Close account settings">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6 6 18M6 6l12 12"></path></svg>
      </button>
    </div>

    <div class="settings-content">
      <form method="POST" enctype="multipart/form-data" class="settings-card personal-settings">
        <input type="hidden" name="action" value="update_profile">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($profileMutationCsrf, ENT_QUOTES, 'UTF-8') ?>">
        <div class="settings-card-header">
          <span class="settings-card-icon">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="8" r="4"></circle><path d="M4 21a8 8 0 0 1 16 0"></path></svg>
          </span>
          <div>
            <h3>Personal information</h3>
            <p>Details used for confirmations and booking communication.</p>
          </div>
        </div>
        <div class="settings-card-body">
          <div class="profile-edit-grid">
            <div>
              <label class="avatar-field-label" for="fileInput">Profile photo</label>
              <div id="dropzone" class="dropzone" role="button" tabindex="0" aria-label="Upload a new profile picture">
                <img id="previewImage" class="preview-img" src="<?= htmlspecialchars($display_profile_pic, ENT_QUOTES, 'UTF-8') ?>" alt="Profile photo preview">
                <div class="dz-content">
                  <p class="dz-text">Choose a new photo</p>
                  <span class="dz-sub">Click or drag an image here</span>
                </div>
                <input type="file" name="profile_picture" id="fileInput" accept="image/jpeg,image/png,image/webp" hidden>
              </div>
              <p class="avatar-help">JPG, PNG, or WebP. Square photos work best.</p>
            </div>

            <div class="profile-fields">
              <div class="field-group">
                <label class="field-label" for="profileFullName">Full name <span class="field-note">Contact support to change</span></label>
                <input id="profileFullName" type="text" value="<?= htmlspecialchars($user['full_name']) ?>" class="input" readonly>
              </div>
              <div class="field-group">
                <label class="field-label" for="profileEmail">Email address <span class="field-note">Verified account</span></label>
                <input id="profileEmail" type="email" value="<?= htmlspecialchars($user['email']) ?>" class="input" readonly>
              </div>
              <div class="field-group">
                <label class="field-label" for="profilePhone">Phone number</label>
                <input id="profilePhone" type="text" name="phone" value="<?= htmlspecialchars($user['phone_number'] ?? '') ?>" placeholder="e.g. +63 912 345 6789" class="input">
              </div>
              <div class="field-group field-full">
                <label class="field-label" for="profileAddress">Home address</label>
                <input id="profileAddress" type="text" name="address" value="<?= htmlspecialchars($user['address'] ?? '') ?>" placeholder="Street, municipality, province" class="input">
              </div>
            </div>
          </div>

          <div class="settings-actions">
            <button class="btn-neutral close-edit-panel" type="button">Cancel</button>
            <button class="btn-save" type="submit">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2Z"></path><path d="M17 21v-8H7v8M7 3v5h8"></path></svg>
              Save changes
            </button>
          </div>
        </div>
      </form>

      <div class="settings-side-column">
      <section class="settings-card profile-progress-card">
        <div class="settings-card-header">
          <span class="settings-card-icon">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 3 5 6v5c0 4.5 2.8 8 7 10 4.2-2 7-5.5 7-10V6l-7-3Z"></path><path d="m9 12 2 2 4-4"></path></svg>
          </span>
          <div>
            <h3>Profile progress</h3>
            <p>Track your account completion.</p>
          </div>
        </div>
        <div class="profile-progress-content">
          <div class="profile-progress-ring" style="--profile-progress:<?= $profileCompletion ?>" aria-label="Profile <?= $profileCompletion ?> percent complete">
            <strong><?= $profileCompletion ?>%</strong>
          </div>
          <div class="profile-progress-copy">
            <strong><?= $profileCompletion === 100 ? 'Your profile is complete' : 'Complete your profile' ?></strong>
            <p><?= $profileCompletion === 100 ? 'Your essential details are up to date.' : 'Add missing details for smoother bookings.' ?></p>
            <span><?= $profileCompletedItems ?> of <?= count($profileCompletionChecks) ?> details completed</span>
          </div>
        </div>
      </section>

      <section class="settings-card security-settings">
        <div class="settings-card-header">
          <span class="settings-card-icon security">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="4" y="10" width="16" height="11" rx="2"></rect><path d="M8 10V7a4 4 0 0 1 8 0v3"></path></svg>
          </span>
          <div>
            <h3>Password &amp; security</h3>
            <p>Manage your password securely.</p>
          </div>
        </div>
        <div class="settings-card-body">
          <div class="security-overview" aria-label="Security overview">
            <div class="security-overview-row">
              <span class="security-overview-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 3 5 6v5c0 4.5 2.8 8 7 10 4.2-2 7-5.5 7-10V6l-7-3Z"></path><path d="m9 12 2 2 4-4"></path></svg></span>
              <span class="security-overview-copy"><strong>Password protection</strong><span>Your password is securely protected.</span></span>
              <span class="security-overview-state">Active</span>
            </div>
            <div class="security-overview-row">
              <span class="security-overview-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="5" width="18" height="14" rx="2"></rect><path d="m4 7 8 6 8-6"></path></svg></span>
              <span class="security-overview-copy"><strong>Verified email</strong><span><?= htmlspecialchars($user['email']) ?></span></span>
              <span class="security-overview-state">Verified</span>
            </div>
          </div>
          <div class="settings-actions">
            <button class="btn-save change-password-button" id="openPasswordModal" type="button" aria-haspopup="dialog" aria-controls="passwordModal">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 17v.01M5 10V7a7 7 0 0 1 14 0v3"></path><rect x="3" y="10" width="18" height="11" rx="2"></rect></svg>
              Change password
            </button>
          </div>
        </div>
      </section>
      </div>
    </div>
  </div>

</section>

<div class="password-modal-overlay" id="passwordModal" aria-hidden="true">
  <section class="password-modal" role="dialog" aria-modal="true" aria-labelledby="passwordModalTitle" aria-describedby="passwordModalDescription">
    <header class="password-modal-header">
      <span class="password-modal-icon">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="5" y="10" width="14" height="11" rx="2"></rect><path d="M8 10V7a4 4 0 0 1 8 0v3"></path></svg>
      </span>
      <div>
        <h3 id="passwordModalTitle">Change account password</h3>
        <p id="passwordModalDescription">Choose a strong password you do not use elsewhere.</p>
      </div>
      <button class="password-modal-close" type="button" data-close-password-modal aria-label="Close password dialog">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6 6 18M6 6l12 12"></path></svg>
      </button>
    </header>
    <form method="POST" id="passwordChangeForm" novalidate>
      <input type="hidden" name="action" value="change_password">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($profilePasswordCsrf, ENT_QUOTES, 'UTF-8') ?>">
      <div class="password-modal-body">
        <div class="password-modal-error" id="passwordModalError" role="alert" hidden></div>
        <div class="password-modal-field">
          <label for="currentPassword">Current password</label>
          <div class="password-input-wrap">
            <input id="currentPassword" type="password" name="old_password" class="input" autocomplete="current-password" required>
            <button type="button" class="password-toggle" data-modal-password-toggle="currentPassword" aria-label="Show current password" aria-pressed="false">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M2 12s3.5-6 10-6 10 6 10 6-3.5 6-10 6S2 12 2 12Z"></path><circle cx="12" cy="12" r="3"></circle></svg>
            </button>
          </div>
        </div>
        <div class="password-modal-field">
          <label for="newPassword">New password</label>
          <div class="password-input-wrap">
            <input id="newPassword" type="password" name="new_password" class="input" autocomplete="new-password" minlength="10" required aria-describedby="passwordRules">
            <button type="button" class="password-toggle" data-modal-password-toggle="newPassword" aria-label="Show new password" aria-pressed="false">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M2 12s3.5-6 10-6 10 6 10 6-3.5 6-10 6S2 12 2 12Z"></path><circle cx="12" cy="12" r="3"></circle></svg>
            </button>
          </div>
          <div id="passwordRules">
            <div class="password-strength-track" aria-hidden="true"><span id="passwordStrengthBar"></span></div>
            <ul class="password-rules">
              <li class="password-rule" data-password-rule="length">10+ characters</li>
              <li class="password-rule" data-password-rule="uppercase">Uppercase letter</li>
              <li class="password-rule" data-password-rule="lowercase">Lowercase letter</li>
              <li class="password-rule" data-password-rule="number">Number</li>
            </ul>
          </div>
        </div>
        <div class="password-modal-field">
          <label for="confirmPassword">Confirm new password</label>
          <div class="password-input-wrap">
            <input id="confirmPassword" type="password" name="confirm_password" class="input" autocomplete="new-password" minlength="10" required aria-describedby="passwordMatchMessage">
            <button type="button" class="password-toggle" data-modal-password-toggle="confirmPassword" aria-label="Show password confirmation" aria-pressed="false">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M2 12s3.5-6 10-6 10 6 10 6-3.5 6-10 6S2 12 2 12Z"></path><circle cx="12" cy="12" r="3"></circle></svg>
            </button>
          </div>
          <p class="password-match-message" id="passwordMatchMessage">Re-enter your new password.</p>
        </div>
      </div>
      <footer class="password-modal-footer">
        <button class="btn-neutral" type="button" data-close-password-modal>Cancel</button>
        <button class="btn-save" id="passwordSubmitButton" type="submit" disabled>
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 17v.01M5 10V7a7 7 0 0 1 14 0v3"></path><rect x="3" y="10" width="18" height="11" rx="2"></rect></svg>
          Update password
        </button>
      </footer>
    </form>
  </section>
</div>

<section id="upcoming-bookings" class="upcoming-section section">

  <div class="section-header">
    <div class="upcoming-heading">
      <span class="upcoming-heading-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="5" width="18" height="16" rx="2"></rect><path d="M16 3v4M8 3v4M3 10h18"></path></svg></span>
      <div class="upcoming-heading-copy"><h3>Upcoming Bookings</h3><p>Your next confirmed tours, boat trips, and hotel stays.</p></div>
    </div>
    <span class="upcoming-total"><?= (int)$totalUpcoming ?> scheduled</span>
  </div>

  <?php if ($totalUpcoming === 0): ?>
    <p class="empty-state">No upcoming bookings.</p>
  <?php else: ?>

    <?php if (!empty($upcoming_bookings)): ?>
    <!-- ================= TOUR ================= -->
    <div class="booking-group">

    <div class="group-title tour-title">
        <div class="title-text">
            <span class="upcoming-title-label"><span class="upcoming-group-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 18h18M5 18l2-7h10l2 7M9 11V6l3-2 3 2v5"></path></svg></span>Tours & boat trips</span>
            <span class="booking-count"><?= count($upcoming_bookings) ?></span>
        </div>

        <?php if (count($upcoming_bookings) > 1): ?>
        <div class="scroll-controls">
            <button class="scroll-btn" onclick="scrollRow('tourRow', -300)" aria-label="Scroll Left">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="m15 18-6-6 6-6"/></svg>
            </button>
            <button class="scroll-btn" onclick="scrollRow('tourRow', 300)" aria-label="Scroll Right">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="m9 18 6-6-6-6"/></svg>
            </button>
        </div>
        <?php endif; ?>
    </div>

      <div class="booking-row" id="tourRow">
        <?php foreach ($upcoming_bookings as $b): 
            // Calculate Days Left or Past Due
            $today = new DateTime('today');
            $bDate = new DateTime($b['booking_date']);
            $bDate->setTime(0, 0, 0);
            $interval = $today->diff($bDate);
            $days = $interval->days;
            $isPast = $bDate < $today;

            if ($isPast && $days > 0) {
                $statusHtml = '<span class="capsule-past-due"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg> Past Due</span>';
            } elseif ($days === 0) {
                $statusHtml = '<span class="days-left"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg> <strong>Today</strong></span>';
            } else {
                $statusHtml = '<span class="days-left"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg> <strong>' . $days . ' Days Left</strong></span>';
            }
        ?>
          <article class="booking-card tour-card">

            <div class="booking-card-header header-tour">
              <span class="upcoming-card-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 18h18M5 18l2-7h10l2 7M9 11V6l3-2 3 2v5"></path></svg></span>
              <span class="upcoming-card-identity">
                <span class="upcoming-card-type"><?= htmlspecialchars($b['booking_type'] ?? 'Tour') ?> booking</span>
                <span class="upcoming-card-reference"><?= htmlspecialchars((string)($b['booking_reference'] ?: $b['booking_id'])) ?></span>
              </span>
            </div>

            <div class="booking-card-body">
              <div>
                <div class="upcoming-service-label">Destination</div>
                <div class="upcoming-service-name"><?= htmlspecialchars($b['location'] ?? $b['package_name'] ?? 'Destination to be confirmed') ?></div>
              </div>
              <div class="upcoming-date-grid is-single"><div class="upcoming-date-item"><span>Travel date</span><strong><?= htmlspecialchars($bDate->format('M j, Y')) ?></strong></div></div>

              <div class="meta-tags">
                <span>Adults: <?= (int)($b['num_adults'] ?? 0) ?></span>
                <span>Children: <?= (int)($b['num_children'] ?? 0) ?></span>
                <span>Total: <?= (int)($b['pax'] ?? 0) ?></span>
              </div>
            </div>

            <div class="booking-card-footer">
              <span class="upcoming-footer-label">Schedule</span>
              <?= $statusHtml ?>
            </div>

          </article>
        <?php endforeach; ?>
      </div>

    </div> <!-- ✅ CLOSE TOUR GROUP -->
    <?php endif; ?>

    <br>

    <?php if (!empty($hotel_upcoming_bookings)): ?>
    <!-- ================= HOTEL ================= -->
    <div class="booking-group">

      <div class="group-title hotel-title">
        <div class="title-text">
            <span class="upcoming-title-label"><span class="upcoming-group-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 21V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v16M8 8h2M14 8h2M8 12h2M14 12h2M9 21v-5h6v5M2 21h20"></path></svg></span>Hotel stays</span>
            <span class="booking-count"><?= count($hotel_upcoming_bookings) ?></span>
        </div>

        <?php if (count($hotel_upcoming_bookings) > 1): ?>
        <div class="scroll-controls">
            <button class="scroll-btn" onclick="scrollRow('hotelRow', -300)" aria-label="Scroll Left">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="m15 18-6-6 6-6"/></svg>
            </button>
            <button class="scroll-btn" onclick="scrollRow('hotelRow', 300)" aria-label="Scroll Right">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="m9 18 6-6-6-6"/></svg>
            </button>
        </div>
        <?php endif; ?>
    </div>

      <div class="booking-row" id="hotelRow">
        <?php foreach ($hotel_upcoming_bookings as $h): 
            // Calculate Days Left or Past Due
            $today = new DateTime('today');
            $hDate = new DateTime($h['checkin_date']);
            $hDate->setTime(0, 0, 0);
            $interval = $today->diff($hDate);
            $days = $interval->days;
            $isPast = $hDate < $today;

            if ($isPast && $days > 0) {
                $statusHtml = '<span class="capsule-past-due"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg> Past Due</span>';
            } elseif ($days === 0) {
                $statusHtml = '<span class="days-left"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg> <strong>Today</strong></span>';
            } else {
                $statusHtml = '<span class="days-left"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg> <strong>' . $days . ' Days Left</strong></span>';
            }
        ?>
          <article class="booking-card hotel-card">

            <div class="booking-card-header header-hotel">
              <span class="upcoming-card-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 21V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v16M8 8h2M14 8h2M8 12h2M14 12h2M9 21v-5h6v5M2 21h20"></path></svg></span>
              <span class="upcoming-card-identity">
                <span class="upcoming-card-type">Hotel booking</span>
                <span class="upcoming-card-reference"><?= htmlspecialchars((string)($h['booking_reference'] ?: $h['hotel_booking_id'])) ?></span>
              </span>
            </div>

            <div class="booking-card-body">
              <div><div class="upcoming-service-label">Hotel & room</div><div class="upcoming-service-name"><?= htmlspecialchars($h['hotel_name'] ?? 'Hotel stay') ?> &middot; <?= htmlspecialchars($h['room_type'] ?? 'Room to be confirmed') ?></div></div>
              <div class="upcoming-date-grid">
                <div class="upcoming-date-item"><span>Check-in</span><strong><?= htmlspecialchars($hDate->format('M j, Y')) ?></strong></div>
                <div class="upcoming-date-item"><span>Check-out</span><strong><?= htmlspecialchars((new DateTime($h['checkout_date']))->format('M j, Y')) ?></strong></div>
              </div>

              <div class="meta-tags">
                <span>Adults: <?= (int)($h['adults'] ?? 0) ?></span>
                <span>Children: <?= (int)($h['children'] ?? 0) ?></span>
              </div>
            </div>

            <div class="booking-card-footer">
              <span class="upcoming-footer-label">Schedule</span>
              <?= $statusHtml ?>
            </div>

          </article>
        <?php endforeach; ?>
      </div>

    </div> <!-- ✅ CLOSE HOTEL GROUP -->
    <?php endif; ?>

  <?php endif; ?>

</section>

<!-- BOOKINGS -->
<section id="bookings" class="bookings-section section" style="display:none;">

  <!-- Page Header -->
  <div class="page-header">
    <div class="header-titles">
      <svg class="header-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
        <path d="M19 4H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6a2 2 0 0 0-2-2z"></path>
        <path d="M16 2v4"></path>
        <path d="M8 2v4"></path>
        <path d="M3 10h18"></path>
      </svg>
      <div>
        <h3>My Bookings</h3>
        <p>Manage your active tour, boat, and hotel reservations.</p>
      </div>
    </div>

  </div>

  <div class="profile-stat-grid" aria-label="Active booking summary">
    <article class="profile-stat-card"><span class="profile-stat-card-icon"><svg viewBox="0 0 24 24"><rect x="3" y="5" width="18" height="16" rx="2"></rect><path d="M16 3v4M8 3v4M3 11h18"></path></svg></span><div class="profile-stat-card-copy"><small>Active bookings</small><strong><?= (int)$bookingSummary['total'] ?></strong><p>Current reservations</p></div></article>
    <article class="profile-stat-card"><span class="profile-stat-card-icon"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"></circle><path d="m8 12 2.5 2.5L16.5 8"></path></svg></span><div class="profile-stat-card-copy"><small>Confirmed</small><strong><?= (int)$bookingSummary['confirmed'] ?></strong><p>Ready or accepted</p></div></article>
    <article class="profile-stat-card"><span class="profile-stat-card-icon"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"></circle><path d="M12 7v5l3 2"></path></svg></span><div class="profile-stat-card-copy"><small>Pending</small><strong><?= (int)$bookingSummary['pending'] ?></strong><p>Awaiting confirmation</p></div></article>
    <article class="profile-stat-card"><span class="profile-stat-card-icon"><svg viewBox="0 0 24 24"><path d="M4 21V5h11v16M15 9h5v12M8 9h3M8 13h3M8 17h3M18 13h.01M18 17h.01"></path></svg></span><div class="profile-stat-card-copy"><small>Hotel stays</small><strong><?= (int)$bookingSummary['hotels'] ?></strong><p>Active room bookings</p></div></article>
  </div>

  <?php
    // TOUR / BOAT BOOKINGS
    $accepted = array_filter($bookings_active, fn($b) => strtolower($b['status']) === 'accepted');
    $pending  = array_filter($bookings_active, fn($b) => strtolower($b['status']) === 'pending');
  ?>

  <?php if (empty($accepted) && empty($pending) && empty($hotel_upcoming_bookings)): ?>

    <!-- Empty State -->
    <div class="empty-state">
      <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
        <rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect>
        <line x1="16" y1="2" x2="16" y2="6"></line>
        <line x1="8" y1="2" x2="8" y2="6"></line>
        <line x1="3" y1="10" x2="21" y2="10"></line>
      </svg>
      <h4>No Active Bookings</h4>
      <p>Your current and upcoming reservations will appear here.</p>
    </div>

  <?php else: ?>

  <!-- ACCEPTED BOOKINGS -->
  <?php if (!empty($accepted)): ?>
  <div class="formal-card">
    <div class="formal-card-header">
      <div class="title-group">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
        <h4>Accepted Tour & Boat Bookings</h4>
      </div>
      <span class="count-pill"><?= count($accepted) ?> Accepted</span>
    </div>

    <div class="table-responsive">
      <table class="formal-table">
        <thead>
          <tr>
            <th>Date</th>
            <th>Location / Package</th>
            <th>Type</th>
            <th>Guests</th>
            <th>Details</th>
            <th>Tourists</th>
            <th>Payment</th>
            <th>Status</th>
            <th>Action</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($accepted as $b):
            $adults   = (int)($b['num_adults'] ?? 0);
            $children = (int)($b['num_children'] ?? 0);
            $pax      = (int)($b['pax'] ?? ($adults + $children));
            $amountPaid = max(0, (float)($b['payment_amount'] ?? 0));
            $remainingBalance = max(0, (float)($b['remaining_balance'] ?? 0));
            $paymentStatus = $remainingBalance <= 0.009 ? 'Paid' : ($amountPaid > 0 ? 'Partial' : 'Unpaid');
            $cancellationPolicy = bookingCancellationRefundPolicy(
              (string)$b['booking_date'],
              max((float)($b['grand_total'] ?? 0), $amountPaid + $remainingBalance),
              $amountPaid
            );
            $decisionRequest = $decisionRequestByBookingKey['tour:' . (int)$b['booking_id']] ?? null;
            $awaitingProviderDecision = is_array($decisionRequest);
            $decisionPayload = $awaitingProviderDecision ? array_merge($b, [
              'cancellation_request_id' => (int)$decisionRequest['cancellation_request_id'],
              'booking_reference' => $decisionRequest['booking_reference'],
              'service_name' => $decisionRequest['service_name'],
              'booking_type' => $decisionRequest['booking_type'],
              'original_service_date' => $decisionRequest['original_service_date'] ?: $decisionRequest['service_date'],
              'cancellation_reason' => $decisionRequest['cancellation_reason'],
              'decision_deadline' => $decisionRequest['decision_deadline'],
              'amount_paid' => $decisionRequest['amount_paid'],
              'total_amount' => $decisionRequest['total_amount'],
            ]) : [];
            $decisionPayloadJson = $awaitingProviderDecision
              ? htmlspecialchars(json_encode($decisionPayload, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP), ENT_QUOTES, 'UTF-8')
              : '';
          ?>
          <tr id="<?= $awaitingProviderDecision ? 'cancellation-' . (int)$decisionRequest['cancellation_request_id'] : 'booking-' . (int)$b['booking_id'] ?>" class="<?= $awaitingProviderDecision ? 'booking-decision-required-row' : '' ?>">
            <td><strong><?= htmlspecialchars($b['booking_date'] ?? $b['created_at']) ?></strong><?php if ($awaitingProviderDecision): ?><small class="booking-decision-date-note">Original date stopped</small><?php endif; ?></td>
            <td>
              <?= htmlspecialchars(
                in_array(strtolower($b['booking_type'] ?? ''), ['boat','tourguide'])
                  ? ($b['location'] ?? 'N/A')
                  : ($b['package_name'] ?? 'N/A')
              ) ?>
            </td>
            <td><span class="tag-outline"><?= htmlspecialchars($b['booking_type'] ?? 'N/A') ?></span></td>
            <td>
              <div class="guest-group">
                <span>A: <?= $adults ?></span>
                <span>C: <?= $children ?></span>
                <span class="highlight">Pax: <?= $pax ?></span>
              </div>
            </td>
<td>
  <button
    class="btn-details-user booking-btn-user"
    data-booking='<?= htmlspecialchars(json_encode($b, JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES, "UTF-8") ?>'
    style="display:inline-flex;align-items:center;gap:6px;"
  >
    <!-- Eye Icon (White) -->
    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16"
      viewBox="0 0 24 24"
      fill="none"
      stroke="#fff"
      stroke-width="2"
      stroke-linecap="round"
      stroke-linejoin="round">
      <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
      <circle cx="12" cy="12" r="3"/>
    </svg>

    View Details
  </button>
</td>
            <?php if ($b['status'] === 'accepted' && $b['is_complete'] === 'uncomplete'): ?>
            <td>
              <?php if (hasTourists($pdo, $b['booking_id'])): ?>
                <button type="button" class="btn-action btn-secondary view-tourist-btn-user" onclick="openTouristProfilePdf(<?= (int)$b['booking_id'] ?>)">
                  <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line><polyline points="10 9 9 9 8 9"></polyline></svg>
                  PDF
                </button>
              <?php else: ?>
                <button class="btn-action add-tourist-btn-user" data-booking-id="<?= (int)$b['booking_id'] ?>" data-pax="<?= (int)$pax ?>">
                  <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
                  Add
                </button>
              <?php endif; ?>
            </td>
            <?php else: ?>
            <td><span class="text-muted">-</span></td>
            <?php endif; ?>
            <td>
              <span class="profile-bill-status <?= strtolower($paymentStatus) ?>"><?= $paymentStatus ?></span>
            </td>
            <td><span class="status-pill <?= $awaitingProviderDecision ? 'decision-required' : 'accepted' ?>"><?= $awaitingProviderDecision ? 'Decision Required' : (!empty($rescheduledBookingKeys['tour:' . (int)$b['booking_id']]) ? 'Rescheduled' : 'Accepted') ?></span></td>
            <td>
              <?php if ($awaitingProviderDecision): ?>
              <div class="booking-decision-row-actions">
                <button type="button" class="respond-provider-cancellation booking-respond-btn" data-provider-action="choice" data-request='<?= $decisionPayloadJson ?>'>Review Options</button>
              </div>
              <?php else: ?>
              <button type="button" class="cancel-booking-btn" data-booking-domain="tour" data-booking-id="<?= (int)$b['booking_id'] ?>" data-booking-reference="<?= htmlspecialchars((string)($b['booking_reference'] ?? ('BOOKING-' . $b['booking_id'])), ENT_QUOTES, 'UTF-8') ?>" data-service-date="<?= htmlspecialchars((string)$b['booking_date'], ENT_QUOTES, 'UTF-8') ?>" data-refund-policy="<?= htmlspecialchars(bookingCancellationPolicyLabel($cancellationPolicy['refund_policy']), ENT_QUOTES, 'UTF-8') ?>" data-refund-policy-code="<?= htmlspecialchars((string)$cancellationPolicy['refund_policy'], ENT_QUOTES, 'UTF-8') ?>" data-refund-amount="<?= htmlspecialchars(number_format((float)$cancellationPolicy['refundable_amount'], 2, '.', ''), ENT_QUOTES, 'UTF-8') ?>">
                Cancel
              </button>
              <?php endif; ?>
            </td>
          </tr>
          <?php if ($awaitingProviderDecision): ?>
          <tr class="booking-decision-alert-row">
            <td colspan="9">
              <div class="booking-decision-inline-alert">
                <span class="booking-decision-alert-icon"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"></circle><path d="M12 7v6M12 17h.01"></path></svg></span>
                <div><strong>This booking cannot proceed on its original date.</strong><p><b>Provider reason:</b> <?= htmlspecialchars((string)$decisionRequest['cancellation_reason']) ?> <span>•</span> <b>Respond by:</b> <?= htmlspecialchars(date('M j, Y g:i A', strtotime((string)$decisionRequest['decision_deadline']))) ?></p></div>
              </div>
            </td>
          </tr>
          <?php endif; ?>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php endif; ?>

  <!-- PENDING BOOKINGS -->
  <?php if (!empty($pending)): ?>
  <div class="formal-card">
    <div class="formal-card-header">
      <div class="title-group">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>
        <h4>Pending Tour & Boat Bookings</h4>
      </div>
      <span class="count-pill pill-warning"><?= count($pending) ?> Pending</span>
    </div>

    <div class="table-responsive">
      <table class="formal-table">
        <thead>
          <tr>
            <th>Date</th>
            <th>Location / Package</th>
            <th>Type</th>
            <th>Guests</th>
            <th>Details</th>
            <th class="table-col-payment">Payment Status</th>
            <th>Status</th>
            <th>Action</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($pending as $b):
            $adults   = (int)($b['num_adults'] ?? 0);
            $children = (int)($b['num_children'] ?? 0);
            $pax      = (int)($b['pax'] ?? ($adults + $children));
            $amountPaid = max(0, (float)($b['payment_amount'] ?? 0));
            $remainingBalance = max(0, (float)($b['remaining_balance'] ?? 0));
            $paymentStatus = $remainingBalance <= 0.009 ? 'Paid' : ($amountPaid > 0 ? 'Partial' : 'Unpaid');
            $cancellationPolicy = bookingCancellationRefundPolicy(
              (string)$b['booking_date'],
              max((float)($b['grand_total'] ?? 0), $amountPaid + $remainingBalance),
              $amountPaid
            );
          ?>
          <tr>
            <td><strong><?= htmlspecialchars($b['booking_date'] ?? $b['created_at']) ?></strong></td>
            <td>
              <?= htmlspecialchars(
                in_array(strtolower($b['booking_type'] ?? ''), ['boat','tourguide'])
                  ? ($b['location'] ?? 'N/A')
                  : ($b['package_name'] ?? 'N/A')
              ) ?>
            </td>
            <td><span class="tag-outline"><?= htmlspecialchars($b['booking_type'] ?? 'N/A') ?></span></td>
            <td>
              <div class="guest-group">
                <span>A: <?= $adults ?></span>
                <span>C: <?= $children ?></span>
                <span class="highlight-warning">Pax: <?= $pax ?></span>
              </div>
            </td>
<td>
  <button
    class="btn-details-user booking-btn-user"
    data-booking='<?= htmlspecialchars(json_encode($b, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG), ENT_QUOTES, "UTF-8") ?>'
    style="display:inline-flex;align-items:center;gap:6px;"
  >
    <!-- Eye Icon -->
    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16"
      viewBox="0 0 24 24"
      fill="none"
      stroke="#fff"
      stroke-width="2"
      stroke-linecap="round"
      stroke-linejoin="round">
      <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
      <circle cx="12" cy="12" r="3"/>
    </svg>

    View Details
  </button>
</td>
            <td class="table-col-payment">
              <span class="profile-bill-status <?= strtolower($paymentStatus) ?>"><?= $paymentStatus ?></span>
            </td>
            <td><span class="status-pill pending">Pending</span></td>
            <td>
              <button type="button" class="cancel-booking-btn" data-booking-domain="tour" data-booking-id="<?= (int)$b['booking_id'] ?>" data-booking-reference="<?= htmlspecialchars((string)($b['booking_reference'] ?? ('BOOKING-' . $b['booking_id'])), ENT_QUOTES, 'UTF-8') ?>" data-service-date="<?= htmlspecialchars((string)$b['booking_date'], ENT_QUOTES, 'UTF-8') ?>" data-refund-policy="<?= htmlspecialchars(bookingCancellationPolicyLabel($cancellationPolicy['refund_policy']), ENT_QUOTES, 'UTF-8') ?>" data-refund-policy-code="<?= htmlspecialchars((string)$cancellationPolicy['refund_policy'], ENT_QUOTES, 'UTF-8') ?>" data-refund-amount="<?= htmlspecialchars(number_format((float)$cancellationPolicy['refundable_amount'], 2, '.', ''), ENT_QUOTES, 'UTF-8') ?>">
                Cancel
              </button>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php endif; ?>

  <!-- HOTEL BOOKINGS -->
  <?php if (!empty($hotel_upcoming_bookings)): ?>
  <div class="formal-card">
    <div class="formal-card-header">
      <div class="title-group">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path><polyline points="9 22 9 12 15 12 15 22"></polyline></svg>
        <h4>Hotel Room Bookings</h4>
      </div>
      <span class="count-pill"><?= count($hotel_upcoming_bookings) ?> Hotels</span>
    </div>

    <div class="table-responsive">
      <table class="formal-table">
        <thead>
          <tr>
            <th>Dates</th>
            <th>Hotel / Resort</th>
            <th>Room</th>
            <th>Guests</th>
            <th>Details</th>
            <th>Tourists</th>
            <th>Payment</th>
            <th>Status</th>
            <th>Action</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($hotel_upcoming_bookings as $h):
            $guests = (int)($h['adults'] ?? 0) + (int)($h['children'] ?? 0);
            $hotelAmountPaid = max(0, (float)($h['amount_paid'] ?? 0));
            $hotelRemainingBalance = max(0, (float)($h['remaining_balance'] ?? 0));
            $cancellationPolicy = bookingCancellationRefundPolicy(
              (string)$h['checkin_date'],
              max((float)($h['total_amount'] ?? 0), $hotelAmountPaid + $hotelRemainingBalance),
              $hotelAmountPaid
            );
          ?>
          <tr>
            <td>
              <div class="date-group">
                <span><strong>IN:</strong> <?= htmlspecialchars($h['checkin_date'] ?? '-') ?></span>
                <span><strong>OUT:</strong> <?= htmlspecialchars($h['checkout_date'] ?? '-') ?></span>
              </div>
            </td>
            <td><strong><?= htmlspecialchars($h['hotel_name'] ?? $h['resort_name'] ?? 'N/A') ?></strong></td>
            <td><?= htmlspecialchars($h['room_type'] ?? 'N/A') ?></td>
            <td>
              <div class="guest-group">
                <span>A: <?= (int)($h['adults'] ?? 0) ?></span>
                <span>C: <?= (int)($h['children'] ?? 0) ?></span>
                <span class="highlight">Total: <?= $guests ?></span>
              </div>
            </td>
            <td>
              <button
                type="button"
                class="btn-details-user booking-btn-user"
                data-booking='<?= htmlspecialchars(json_encode($h, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG), ENT_QUOTES, "UTF-8") ?>'
                style="display:inline-flex;align-items:center;gap:6px;"
              >
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                  <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
                  <circle cx="12" cy="12" r="3"/>
                </svg>
                View Details
              </button>
            </td>

            <?php 
              $hotelBookingId = (int)($h['hotel_booking_id'] ?? 0);
              $status = strtolower($h['booking_status'] ?? '');
              $isAccepted = in_array($status, ['accepted', 'confirmed']);
            ?>
            <td>
              <?php if (!$isAccepted): ?>
                <span class="text-muted">Not available</span>
              <?php elseif ($hotelBookingId > 0 && hasTourists($pdo, $hotelBookingId)): ?>
                <button class="btn-action btn-secondary view-tourist-btn-user" onclick="window.open('tourists_pdf.php?booking_id=<?= $hotelBookingId ?>','_blank')">
                  <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline></svg>
                  PDF
                </button>
              <?php else: ?>
                <button class="btn-action add-tourist-btn-user" data-booking-id="<?= (int)$h['hotel_booking_id'] ?>" data-pax="<?= (int)$guests ?>">
                  <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
                  Add
                </button>
              <?php endif; ?>
            </td>
            <td>
              <div class="payment-group">
                <strong>₱<?= number_format((float)($h['total_amount'] ?? 0), 2) ?></strong>
                <span class="payment-status <?= strtolower($h['payment_status'] ?? 'unpaid') ?>">
                  <?= ucfirst($h['payment_status'] ?? 'Unpaid') ?>
                </span>
              </div>
            </td>
            <td><span class="status-pill <?= htmlspecialchars($h['booking_status'] ?? 'pending') ?>"><?= ucfirst($h['booking_status'] ?? 'Pending') ?></span></td>
            <td>
              <button type="button" class="cancel-booking-btn" data-booking-domain="hotel" data-booking-id="<?= (int)$hotelBookingId ?>" data-booking-reference="<?= htmlspecialchars((string)($h['booking_reference'] ?? ('HOTEL-' . $hotelBookingId)), ENT_QUOTES, 'UTF-8') ?>" data-service-date="<?= htmlspecialchars((string)$h['checkin_date'], ENT_QUOTES, 'UTF-8') ?>" data-refund-policy="<?= htmlspecialchars(bookingCancellationPolicyLabel($cancellationPolicy['refund_policy']), ENT_QUOTES, 'UTF-8') ?>" data-refund-policy-code="<?= htmlspecialchars((string)$cancellationPolicy['refund_policy'], ENT_QUOTES, 'UTF-8') ?>" data-refund-amount="<?= htmlspecialchars(number_format((float)$cancellationPolicy['refundable_amount'], 2, '.', ''), ENT_QUOTES, 'UTF-8') ?>">
                Cancel
              </button>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php endif; ?>

  <?php endif; ?>
</section>

<!-- CANCELLATION & REFUND TRACKER -->
<section id="cancel-bookings" class="section cancellation-section" style="display:none;">
  <div class="page-header">
    <div class="header-titles">
      <svg class="header-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><circle cx="12" cy="12" r="9"></circle><path d="m9 9 6 6M15 9l-6 6"></path></svg>
      <div>
        <h3>Cancellation &amp; Refunds</h3>
        <p>Track cancellation approval and the current status of eligible refunds.</p>
      </div>
    </div>
  </div>

  <div class="profile-stat-grid" aria-label="Cancellation summary">
    <article class="profile-stat-card"><span class="profile-stat-card-icon cancellation-stat-icon"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"></circle><path d="M12 7v5l3 2"></path></svg></span><div class="profile-stat-card-copy"><small>Awaiting approval</small><strong><?= (int)$cancellationSummary['pending'] ?></strong><p>Requests under review</p></div></article>
    <article class="profile-stat-card"><span class="profile-stat-card-icon cancellation-stat-icon"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"></circle><path d="m8 12 2.5 2.5L16.5 8"></path></svg></span><div class="profile-stat-card-copy"><small>Approved</small><strong><?= (int)$cancellationSummary['approved'] ?></strong><p>Cancellation approved</p></div></article>
    <article class="profile-stat-card"><span class="profile-stat-card-icon cancellation-stat-icon"><svg viewBox="0 0 24 24"><path d="M4 7h16v10H4z"></path><path d="M7 11h5M16 10v2"></path></svg></span><div class="profile-stat-card-copy"><small>Refund processing</small><strong><?= (int)$cancellationSummary['refund_processing'] ?></strong><p>Refunds currently in progress</p></div></article>
    <article class="profile-stat-card"><span class="profile-stat-card-icon cancellation-stat-icon"><svg viewBox="0 0 24 24"><path d="M4 7h16v10H4z"></path><path d="m8 12 2 2 5-5"></path></svg></span><div class="profile-stat-card-copy"><small>Refunded</small><strong><?= (int)$cancellationSummary['refunded'] ?></strong><p>Completed refunds</p></div></article>
  </div>

  <div class="cancellation-policy-card">
    <span class="cancellation-policy-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 3 4 6v5c0 4.8 3.3 8 8 10 4.7-2 8-5.2 8-10V6l-8-3Z"></path><path d="M12 8v4M12 16h.01"></path></svg></span>
    <div><strong>Refund eligibility policy</strong><p>Cancel at least 3 days before the service date for a full refund of the amount paid. For later cancellations, the first 20% of the booking total is non-refundable; any amount paid above that 20% is eligible for refund.</p></div>
  </div>

  <?php if (!$cancellationRequests): ?>
    <div class="empty-state cancellation-empty">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="4" y="4" width="16" height="16" rx="3"></rect><path d="m9 9 6 6M15 9l-6 6"></path></svg>
      <h4>No Cancellation Requests</h4>
      <p>Bookings you request to cancel will appear here with their approval and refund progress.</p>
    </div>
  <?php else: ?>
    <div class="formal-card cancellation-tracker-card">
      <div class="formal-card-header">
        <div class="title-group"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 4h14v16H5z"></path><path d="M8 8h8M8 12h8M8 16h5"></path></svg><h4>Cancellation Requests</h4></div>
        <span class="count-pill"><?= count($cancellationRequests) ?> Request<?= count($cancellationRequests) === 1 ? '' : 's' ?></span>
      </div>
      <div class="table-responsive">
        <table class="formal-table cancellation-table">
          <thead><tr><th>Requested</th><th>Booking</th><th>Service Date</th><th>Status</th><th>Refund Eligibility</th><th>Estimated Refund</th><th>Refund Status</th><th>Actions</th></tr></thead>
          <tbody>
          <?php foreach ($cancellationRequests as $request):
            $requestStatus = strtolower((string)$request['request_status']);
            $refundStatus = strtolower((string)$request['refund_status']);
            $refundAmount = max(0, (float)$request['refundable_amount']);
            $policyCode = strtolower((string)$request['refund_policy']);
            $requestStatusClass = in_array($requestStatus, ['pending','approved','rescheduled','rejected','cancelled'], true) ? $requestStatus : 'pending';
            $refundStatusClass = in_array($refundStatus, ['not_started','pending','processing','completed','refunded','rejected','declined'], true) ? str_replace('_', '-', $refundStatus) : 'not-started';
            $policyClass = in_array($policyCode, ['full_refund','partial_refund','deposit_non_refundable','no_payment'], true) ? str_replace('_', '-', $policyCode) : 'review';
            $typeLabels = ['hotel'=>'Hotel','package'=>'Tour package','tour'=>'Tour package','boat'=>'Boat','tourguide'=>'Tour guide','guide'=>'Tour guide'];
            $typeLabel = $typeLabels[strtolower((string)$request['booking_type'])] ?? ucfirst((string)$request['booking_type']);
          ?>
            <tr>
              <td><strong><?= htmlspecialchars(date('M j, Y', strtotime((string)$request['requested_at']))) ?></strong><small class="cancellation-cell-note"><?= htmlspecialchars(date('g:i A', strtotime((string)$request['requested_at']))) ?></small></td>
              <td><strong class="cancellation-booking-name"><?= htmlspecialchars((string)$request['service_name']) ?></strong><small class="cancellation-cell-note"><?= htmlspecialchars($typeLabel) ?> &middot; <?= htmlspecialchars((string)($request['booking_reference'] ?: ('#' . $request['booking_id']))) ?></small></td>
              <td><strong><?= htmlspecialchars(date('M j, Y', strtotime((string)$request['service_date']))) ?></strong><small class="cancellation-cell-note"><?= (int)$request['days_before_service'] ?> day<?= abs((int)$request['days_before_service']) === 1 ? '' : 's' ?> before</small></td>
              <td><span class="cancellation-status <?= $requestStatusClass ?>"><?= htmlspecialchars(bookingCancellationRequestStatusLabel($requestStatus)) ?></span></td>
              <td><span class="refund-eligibility <?= $policyClass ?>"><?= htmlspecialchars(bookingCancellationPolicyLabel($policyCode)) ?></span><small class="cancellation-cell-note"><?= $policyCode === 'full_refund' ? 'Cancelled at least 3 days before' : ($policyCode === 'partial_refund' ? '20% booking deposit retained' : '') ?></small></td>
              <td><strong class="refund-amount">₱<?= number_format($refundAmount, 2) ?></strong><small class="cancellation-cell-note">of ₱<?= number_format((float)$request['amount_paid'], 2) ?> paid</small></td>
              <td><span class="refund-status <?= $refundStatusClass ?>"><?= htmlspecialchars(bookingCancellationRefundStatusLabel($refundStatus, $refundAmount)) ?></span><?php if (!empty($request['refund_updated_at'])): ?><small class="cancellation-cell-note">Updated <?= htmlspecialchars(date('M j, Y', strtotime((string)$request['refund_updated_at']))) ?></small><?php endif; ?></td>
              <td>
                <div class="cancellation-row-actions">
                  <button type="button" class="btn-details-user booking-btn-user cancellation-details-btn" data-booking='<?= htmlspecialchars(json_encode($request['_booking_detail'], JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP), ENT_QUOTES, 'UTF-8') ?>' aria-label="View booking details" title="View booking details">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M2.5 12s3.5-6 9.5-6 9.5 6 9.5 6-3.5 6-9.5 6-9.5-6-9.5-6Z"></path><circle cx="12" cy="12" r="2.5"></circle></svg>
                  </button>
                  <button type="button" class="view-cancellation-reason cancellation-reason-btn" data-booking-reference="<?= htmlspecialchars((string)($request['booking_reference'] ?: ('#' . $request['booking_id'])), ENT_QUOTES, 'UTF-8') ?>" data-cancellation-reason="<?= htmlspecialchars((string)$request['cancellation_reason'], ENT_QUOTES, 'UTF-8') ?>" data-admin-note="<?= htmlspecialchars((string)($request['admin_note'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" aria-label="View cancellation reason" title="View cancellation reason">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 5h16v14H4z"></path><path d="M8 9h8M8 13h8M8 17h5"></path></svg>
                  </button>
                  <?php if ($policyCode === 'partial_refund' && !in_array($refundStatus, ['processing','completed','refunded'], true)): ?>
                    <button type="button" class="view-cancellation-reason refund-destination-button" data-cancellation-request-id="<?= (int)$request['cancellation_request_id'] ?>" data-current-institution="<?= htmlspecialchars((string)($request['refund_destination_institution'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" data-current-last4="<?= htmlspecialchars((string)($request['refund_destination_last4'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                      <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7h16v10H4z"></path><path d="M7 11h5M16 10v2"></path></svg><?= empty($request['refund_destination_verified_at']) ? 'Add refund account' : 'Update refund account' ?>
                    </button>
                  <?php endif; ?>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  <?php endif; ?>
</section>

<!-- FAVORITES -->
<section id="favorites" class="section favorites-section" style="display:none;">
  <div class="page-header">
    <div class="header-titles">
      <svg class="header-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
        <path d="M20.8 4.6a5.5 5.5 0 0 0-7.8 0L12 5.7l-1.1-1.1a5.5 5.5 0 0 0-7.8 7.8l1.1 1.1L12 21l7.8-7.5 1.1-1.1a5.5 5.5 0 0 0-.1-7.8Z"></path>
      </svg>
      <div>
        <h3>My Favorites</h3>
        <p>Manage your saved stays, tour packages, guides, and boats.</p>
      </div>
    </div>
    <div class="header-stats">
      <div class="stat-badge stat-favorites">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M20.8 4.6a5.5 5.5 0 0 0-7.8 0L12 5.7l-1.1-1.1a5.5 5.5 0 0 0-7.8 7.8l1.1 1.1L12 21l7.8-7.5 1.1-1.1a5.5 5.5 0 0 0-.1-7.8Z"></path></svg>
        Saved: <strong id="favoritesTotalCount"><?= (int)$favoriteCounts['all'] ?></strong>
      </div>
    </div>
  </div>

  <div class="favorites-panel">
    <div class="favorites-toolbar">
    <div class="favorites-filters" role="tablist" aria-label="Filter favorites">
      <?php
      $favoriteFilters = [
          'all' => 'All',
          'hotel' => 'Hotels & Resorts',
          'package' => 'Tour Packages',
          'guide' => 'Tour Guides',
          'boat' => 'Tour Boats',
      ];
      foreach ($favoriteFilters as $filterKey => $filterLabel):
      ?>
        <button type="button" class="favorite-filter<?= $filterKey === 'all' ? ' active' : '' ?>" data-favorite-filter="<?= $filterKey ?>">
          <?= htmlspecialchars($filterLabel) ?><span><?= (int)$favoriteCounts[$filterKey] ?></span>
        </button>
      <?php endforeach; ?>
    </div>
  </div>

    <div class="favorites-grid" id="favoritesGrid">
    <?php foreach ($favoriteItems as $item):
        $itemType = (string)$item['entity_type'];
        $itemId = (int)$item['entity_id'];
        $itemHref = '..' . (string)$item['link_prefix'] . $itemId;
    ?>
      <article class="favorite-profile-card" data-favorite-card data-favorite-category="<?= htmlspecialchars($itemType) ?>">
        <div class="favorite-card-image">
          <img src="<?= htmlspecialchars(favoriteProfileImage($item['image'] ?? null), ENT_QUOTES, 'UTF-8') ?>"
               alt="<?= htmlspecialchars((string)$item['title'], ENT_QUOTES, 'UTF-8') ?>"
               onerror="this.onerror=null;this.src='../img/sampleimage.png';">
        </div>
        <button
          type="button"
          class="favorite-toggle favorite-toggle-card is-favorite"
          data-favorite-type="<?= htmlspecialchars($itemType) ?>"
          data-favorite-id="<?= $itemId ?>"
          data-favorite-action="remove"
          aria-label="Remove from favorites"
          aria-pressed="true"
          title="Remove from favorites"
        ><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 20.4c-5.2-3.4-8.4-6.2-8.4-10a4.8 4.8 0 0 1 8.4-3.1 4.8 4.8 0 0 1 8.4 3.1c0 3.8-3.2 6.6-8.4 10Z"></path></svg></button>
        <div class="favorite-card-body">
          <span class="favorite-kind"><?= htmlspecialchars($favoriteTypeLabels[$itemType] ?? 'Favorite') ?></span>
          <h4><?= htmlspecialchars((string)$item['title']) ?></h4>
          <p class="favorite-card-subtitle"><?= htmlspecialchars((string)$item['subtitle']) ?></p>
          <div class="favorite-card-footer">
            <?php if ($item['price'] !== null): ?>
              <span class="favorite-price">₱<?= number_format((float)$item['price'], 0) ?><?= $itemType === 'hotel' ? '/night' : '/pax' ?></span>
            <?php else: ?>
              <span class="favorite-price is-muted">View availability</span>
            <?php endif; ?>
            <a class="favorite-open" href="<?= htmlspecialchars($itemHref, ENT_QUOTES, 'UTF-8') ?>">
              View
              <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m9 18 6-6-6-6"/></svg>
            </a>
          </div>
        </div>
      </article>
    <?php endforeach; ?>

    <div class="favorites-empty" id="favoritesEmpty"<?= $favoriteItems ? ' hidden' : '' ?>>
      <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 21C7 17.7 3 14.6 3 9.5A5 5 0 0 1 12 6a5 5 0 0 1 9 3.5c0 5.1-4 8.2-9 11.5Z"/></svg>
      <h4>No favorites here yet</h4>
      <p>Tap the heart on a stay, package, guide, or boat to save it here.</p>
      <a href="../hotel_resorts.php">Explore Hotels &amp; Resorts</a>
    </div>
    </div>
  </div>
</section>

<!-- COMPLAINTS & INCIDENTS -->
<section id="complaints" class="section complaints-section" style="display:none;">
  <div class="page-header">
    <div class="header-titles">
      <svg class="header-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
        <path d="M12 3 3.8 6.4v5.2c0 4.7 3.5 7.9 8.2 9.4 4.7-1.5 8.2-4.7 8.2-9.4V6.4L12 3Z"></path><path d="M12 8v4.5M12 16h.01"></path>
      </svg>
      <div>
        <h3>Complaints &amp; Incidents</h3>
        <p>Track reports submitted to the Municipal Tourism Office.</p>
      </div>
    </div>
    <div class="header-stats complaint-header-actions">
      <button type="button" class="complaint-header-submit" id="openComplaintModal">
        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5v14M5 12h14"></path></svg>
        Submit complaint or incident
      </button>
      <div class="stat-badge complaint-total-badge">Reports: <strong><?= (int)$complaintCounts['all'] ?></strong></div>
    </div>
  </div>

  <div class="complaint-summary-grid" aria-label="Complaint and incident summary">
    <article><span class="complaint-summary-icon is-total"><svg viewBox="0 0 24 24"><path d="M6 3h9l4 4v14H6z"></path><path d="M14 3v5h5M9 13h7M9 17h5"></path></svg></span><div><small>Total reports</small><strong><?= (int)$complaintCounts['all'] ?></strong><p>All submissions</p></div></article>
    <article><span class="complaint-summary-icon is-submitted"><svg viewBox="0 0 24 24"><path d="M12 3v12"></path><path d="m7 10 5 5 5-5"></path><path d="M5 19h14"></path></svg></span><div><small>Submitted</small><strong><?= (int)$complaintCounts['submitted'] ?></strong><p>Awaiting review</p></div></article>
    <article><span class="complaint-summary-icon is-review"><svg viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"></circle><path d="m16 16 5 5M11 8v3l2 2"></path></svg></span><div><small>In review</small><strong><?= (int)$complaintCounts['in_review'] ?></strong><p>Being assessed</p></div></article>
    <article><span class="complaint-summary-icon is-resolved"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"></circle><path d="m8 12 2.5 2.5L16.5 8"></path></svg></span><div><small>Resolved</small><strong><?= (int)$complaintCounts['resolved'] ?></strong><p>Completed reports</p></div></article>
  </div>

  <?php if ($complaintReports): ?>
    <div class="complaint-report-panel">
      <div class="complaint-report-toolbar">
        <div class="complaint-type-filters" role="group" aria-label="Filter report type">
          <button type="button" class="active" data-complaint-type="all">All <span><?= (int)$complaintCounts['all'] ?></span></button>
          <button type="button" data-complaint-type="complaint">Complaints</button>
          <button type="button" data-complaint-type="incident">Incidents</button>
        </div>
        <div class="complaint-report-tools">
          <label class="complaint-report-search">
            <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"></circle><path d="m16 16 5 5"></path></svg>
            <span class="sr-only">Search reports</span>
            <input type="search" id="complaintReportSearch" placeholder="Search reference or subject">
          </label>
          <label class="complaint-status-filter">
            <span class="sr-only">Filter by status</span>
            <select id="complaintStatusFilter">
              <option value="all">All statuses</option>
              <option value="submitted">Submitted</option>
              <option value="in_review">In review</option>
              <option value="resolved">Resolved</option>
              <option value="dismissed">Closed</option>
            </select>
          </label>
        </div>
      </div>

      <div class="complaint-report-list" id="complaintReportList">
        <?php foreach ($complaintReports as $report):
          $reportType = strtolower((string)($report['report_type'] ?? 'complaint')) === 'incident' ? 'incident' : 'complaint';
          $statusKey = strtolower((string)($report['status'] ?? 'submitted'));
          if (!isset($complaintStatusLabels[$statusKey])) $statusKey = 'submitted';
          $categoryLabel = $complaintCategoryLabels[(string)($report['category'] ?? '')] ?? 'Other concern';
          $submittedTimestamp = strtotime((string)($report['submitted_at'] ?? '')) ?: time();
          $incidentTimestamp = strtotime((string)($report['incident_at'] ?? '')) ?: $submittedTimestamp;
          $searchValue = strtolower(implode(' ', [
              (string)$report['reference_number'], (string)$report['subject'], $categoryLabel,
              (string)$report['location'], (string)$report['description']
          ]));
        ?>
          <article class="complaint-report-card" data-report-type="<?= $reportType ?>" data-report-status="<?= $statusKey ?>" data-report-search="<?= htmlspecialchars($searchValue, ENT_QUOTES, 'UTF-8') ?>">
            <div class="complaint-report-card-head">
              <span class="complaint-report-type-icon is-<?= $reportType ?>" aria-hidden="true">
                <?php if ($reportType === 'incident'): ?>
                  <svg viewBox="0 0 24 24"><path d="M12 3 2.8 20h18.4z"></path><path d="M12 9v4M12 17h.01"></path></svg>
                <?php else: ?>
                  <svg viewBox="0 0 24 24"><path d="M4 5h16v11H8l-4 4z"></path><path d="M8 9h8M8 12h5"></path></svg>
                <?php endif; ?>
              </span>
              <div class="complaint-report-title">
                <div><span><?= ucfirst($reportType) ?></span><i aria-hidden="true"></i><code><?= htmlspecialchars((string)$report['reference_number']) ?></code></div>
                <h4><?= htmlspecialchars((string)$report['subject']) ?></h4>
                <p>Submitted <?= date('M d, Y', $submittedTimestamp) ?> at <?= date('h:i A', $submittedTimestamp) ?></p>
              </div>
              <span class="complaint-status-pill is-<?= $statusKey ?>"><i></i><?= htmlspecialchars($complaintStatusLabels[$statusKey]) ?></span>
            </div>

            <div class="complaint-report-overview">
              <div><small>Category</small><strong><?= htmlspecialchars($categoryLabel) ?></strong></div>
              <div><small>Event date</small><strong><?= date('M d, Y &middot; h:i A', $incidentTimestamp) ?></strong></div>
              <div><small>Location</small><strong><?= htmlspecialchars((string)$report['location']) ?></strong></div>
              <div><small>Evidence</small><strong><?= count($report['_evidence']) ?> <?= count($report['_evidence']) === 1 ? 'photo' : 'photos' ?></strong></div>
            </div>

            <p class="complaint-report-preview"><?= htmlspecialchars((string)$report['description']) ?></p>

            <details class="complaint-report-details">
              <summary><span>View full report</span><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m7 10 5 5 5-5"></path></svg></summary>
              <div class="complaint-report-details-body">
                <section><small>Complete description</small><p><?= nl2br(htmlspecialchars((string)$report['description'])) ?></p></section>
                <?php if (trim((string)$report['people_involved']) !== ''): ?><section><small>People or organizations involved</small><p><?= nl2br(htmlspecialchars((string)$report['people_involved'])) ?></p></section><?php endif; ?>
                <?php if (trim((string)$report['immediate_action']) !== ''): ?><section><small>Immediate action taken</small><p><?= nl2br(htmlspecialchars((string)$report['immediate_action'])) ?></p></section><?php endif; ?>
                <div class="complaint-report-contact"><span>Preferred follow-up</span><strong><?= $report['preferred_contact'] === 'either' ? 'Email or phone' : ucfirst((string)$report['preferred_contact']) ?></strong></div>
                <?php if ($report['_evidence']): ?>
                  <div class="complaint-evidence-gallery">
                    <?php foreach ($report['_evidence'] as $evidenceIndex => $evidenceUrl): ?>
                      <a href="<?= htmlspecialchars($evidenceUrl, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener" aria-label="Open evidence photo <?= $evidenceIndex + 1 ?>">
                        <img src="<?= htmlspecialchars($evidenceUrl, ENT_QUOTES, 'UTF-8') ?>" alt="Evidence attached to report <?= htmlspecialchars((string)$report['reference_number']) ?>" loading="lazy">
                        <span>View photo</span>
                      </a>
                    <?php endforeach; ?>
                  </div>
                <?php endif; ?>
              </div>
            </details>
          </article>
        <?php endforeach; ?>

        <div class="complaint-filter-empty" id="complaintFilterEmpty" hidden>
          <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"></circle><path d="m16 16 5 5M8 11h6"></path></svg>
          <strong>No matching reports</strong><span>Try changing your search or filters.</span>
        </div>
      </div>
    </div>
  <?php else: ?>
    <div class="complaints-placeholder">
      <span class="complaints-placeholder-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M12 3 3.8 6.4v5.2c0 4.7 3.5 7.9 8.2 9.4 4.7-1.5 8.2-4.7 8.2-9.4V6.4L12 3Z"></path><path d="M9 12.5 11 14l4-4"></path></svg></span>
      <h4>No reports submitted</h4>
      <p>Complaints and incident reports submitted through your tourist account will appear here with their tracking status.</p>
      <a href="../#complaintIncidentModal">Submit a report</a>
    </div>
  <?php endif; ?>
</section>

<!-- HISTORY -->
<section id="history" class="section" style="display:none; margin-top: 10px;">

  <div class="page-header">
    <div class="header-titles">
      <svg class="header-icon" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor">
        <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
      </svg>
      <div>
        <h3>Booking History</h3>
        <p>Review your past tours, boats, and hotel reservations.</p>
      </div>
    </div>
  </div>

  <div class="profile-stat-grid" aria-label="Booking history summary">
    <article class="profile-stat-card"><span class="profile-stat-card-icon"><svg viewBox="0 0 24 24"><path d="M3 12a9 9 0 1 0 3-6.7L3 8"></path><path d="M3 3v5h5M12 7v5l3 2"></path></svg></span><div class="profile-stat-card-copy"><small>Total records</small><strong><?= (int)$historySummary['total'] ?></strong><p>All past reservations</p></div></article>
    <article class="profile-stat-card"><span class="profile-stat-card-icon"><svg viewBox="0 0 24 24"><path d="M4 6h16v13H4z"></path><path d="M8 6V4h8v2M8 11h8M8 15h5"></path></svg></span><div class="profile-stat-card-copy"><small>Tours &amp; packages</small><strong><?= (int)$historySummary['packages'] ?></strong><p>Past tour experiences</p></div></article>
    <article class="profile-stat-card"><span class="profile-stat-card-icon"><svg viewBox="0 0 24 24"><path d="M3 15h18l-3 5H6zM8 15V7l7 8M8 7h7"></path><path d="M5 22c2-1 3 1 5 0s3 1 5 0 3 1 5 0"></path></svg></span><div class="profile-stat-card-copy"><small>Boats &amp; guides</small><strong><?= (int)$historySummary['boat_guides'] ?></strong><p>Completed local services</p></div></article>
    <article class="profile-stat-card"><span class="profile-stat-card-icon"><svg viewBox="0 0 24 24"><path d="M4 21V5h11v16M15 9h5v12M8 9h3M8 13h3M8 17h3M18 13h.01M18 17h.01"></path></svg></span><div class="profile-stat-card-copy"><small>Hotel stays</small><strong><?= (int)$historySummary['hotels'] ?></strong><p>Past accommodations</p></div></article>
  </div>

  <?php
  $completed_hotel_bookings = $completed_hotel_bookings ?? [];
  ?>

  <?php if (empty($bookings_history) && empty($completed_hotel_bookings)): ?>
    <!-- Professional Empty State -->
    <div class="empty-state" style="text-align: center; padding: 60px 20px; background: var(--card); border: 1px dashed #cbd5e1; border-radius: 10px; color: #64748b;">
      <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" style="width: 48px; height: 48px; color: #cbd5e1; margin-bottom: 16px;">
        <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z" />
      </svg>
      <h4 style="margin: 0 0 8px; color: var(--text); font-size: 1.1rem;">No past bookings</h4>
      <p style="margin: 0;">Your completed reservations will appear here once finished.</p>
    </div>
  <?php else: ?>

    <!-- ===================== TOURS HISTORY ===================== -->
    <?php if (!empty($bookings_history)): ?>
      <div class="formal-card">
        <div class="formal-card-header">
          <div class="title-group">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" d="M9 6.75V15m6-6v8.25m.503 3.498 4.875-2.437c.381-.19.622-.58.622-1.006V4.82c0-.836-.88-1.38-1.628-1.006l-3.869 1.934c-.317.159-.69.159-1.006 0L9.503 3.252a1.125 1.125 0 0 0-1.006 0L3.622 5.689C3.24 5.88 3 6.27 3 6.695V19.18c0 .836.88 1.38 1.628 1.006l3.869-1.934c.317-.159.69-.159 1.006 0l4.994 2.497c.317.158.69.158 1.006 0Z" />
            </svg>
            <h4>Tour, Boat &amp; Guide History</h4>
          </div>
          <span class="count-pill table-header-results"><?= count($bookings_history) ?> result<?= count($bookings_history) === 1 ? '' : 's' ?></span>
        </div>

        <div class="table-responsive">
          <table class="formal-table">
            <thead>
              <tr>
                <th>Date</th>
                <th>Service Name</th>
                <th>Type</th>
                <th>Pax Breakdown</th>
                <th class="history-payment-column">Payment</th>
                <th>Status</th>
                <th>Details</th>
                <th>Review</th>
              </tr>
            </thead>
            <tbody>
            <?php foreach ($bookings_history as $b):

              $adults   = (int)($b['num_adults'] ?? 0);
              $children = (int)($b['num_children'] ?? 0);
              $pax      = (int)($b['pax'] ?? ($adults + $children));

              $status_raw = $b['is_complete'] ?? $b['status'] ?? 'uncomplete';
              $status_class = strtolower(trim($status_raw));
              $display_text = ucfirst($status_raw);

              $type = strtolower($b['booking_type'] ?? '');

              $historyAmountPaid = max(0, (float)($b['payment_amount'] ?? 0));
              $historyBalance = max(0, (float)($b['remaining_balance'] ?? 0));
              $historyTotal = max(0, (float)($b['grand_total'] ?? 0));
              $historyStoredPayment = strtolower(trim((string)($b['payment_status'] ?? '')));
              $historyPaidFlag = in_array(strtolower((string)($b['is_paid'] ?? '')), ['1', 'true', 'paid', 'yes'], true);
              if (in_array($historyStoredPayment, ['paid', 'partial', 'unpaid'], true)) {
                  $historyPaymentStatus = $historyStoredPayment;
              } elseif ($historyPaidFlag || ($historyTotal > 0 && $historyBalance <= 0.009)) {
                  $historyPaymentStatus = 'paid';
              } elseif ($historyAmountPaid > 0) {
                  $historyPaymentStatus = 'partial';
              } else {
                  $historyPaymentStatus = 'unpaid';
              }

              $reviewServiceId = 0;
              $has_review = false;

              if ($type === 'package') {
                  $serviceName = $b['package_name'] ?? 'Package';
                  $title = $serviceName;
                  $reviewServiceId = (int)($b['matched_package_id'] ?? $b['package_id'] ?? 0);
                  $has_review = $reviewServiceId > 0 && isset($feedbackMap['package'][$reviewServiceId]);
              } elseif ($type === 'boat') {
                  $serviceName = $b['boat_name'] ?? 'Boat Service';
                  $title = $serviceName;
                  $reviewServiceId = (int)($b['boat_id'] ?? 0);
                  $has_review = $reviewServiceId > 0 && isset($feedbackMap['boat'][$reviewServiceId]);
              } elseif ($type === 'tourguide') {
                  $serviceName = $b['tourguide_name'] ?? 'Tour Guide Service';
                  $title = $serviceName;
                  $reviewServiceId = (int)($b['guide_id'] ?? 0);
                  $has_review = $reviewServiceId > 0 && isset($feedbackMap['tourguide'][$reviewServiceId]);
              } else {
                  $serviceName = 'N/A';
                  $title = $b['location'] ?? 'Unknown';
              }
            ?>
              <tr>
                <td><span class="tag-outline"><?= htmlspecialchars($b['created_at'] ?? '-') ?></span></td>
                
                <td style="font-weight: 500; color: #1e293b;"><?= htmlspecialchars($serviceName) ?></td>
                
                <td><span class="text-muted"><?= ucfirst(htmlspecialchars($b['booking_type'] ?? 'N/A')) ?></span></td>
                
                <td>
                  <div style="font-size: 0.85rem;">
                    <strong><?= $pax ?></strong> Total<br>
                    <span class="text-muted">(<?= $adults ?> Adults, <?= $children ?> Children)</span>
                  </div>
                </td>

                <td class="history-payment-column">
                  <span class="pill <?= htmlspecialchars($historyPaymentStatus) ?>">
                    <?= htmlspecialchars(ucfirst($historyPaymentStatus)) ?>
                  </span>
                </td>

                <td>
                  <span class="pill <?= htmlspecialchars($status_class) ?>" style="display: inline-flex; align-items: center; gap: 4px;">
                    <?= htmlspecialchars($display_text) ?>
                  </span>

                  <?php if (!empty($b['dec_can_note'])): ?>
                    <button class="note-btn"
                      data-note="<?= htmlspecialchars($b['dec_can_note']) ?>"
                      title="View Cancellation/Decline Note"
                      style="margin-left:6px; background:none; border:none; cursor:pointer; color: #94a3b8; vertical-align: middle;">
                      <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" style="width: 18px; height: 18px;">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z" />
                      </svg>
                    </button>
                  <?php endif; ?>
                </td>

                <td>
                  <button class="btn-details-user booking-btn-user"
                    style="display: inline-flex; align-items: center; gap: 4px;"
                    data-booking='<?= htmlspecialchars(json_encode($b, JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES, "UTF-8") ?>'>
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" style="width: 14px; height: 14px;">
                      <path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178Z" />
                      <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
                    </svg>
                    View
                  </button>
                </td>

                <!-- ================= REVIEW BUTTON ================= -->
                <td>
                  <?php if ($status_class === 'complete' || $status_class === 'completed'): ?>
                    <button class="btn-review-user <?= $has_review ? 'view-review-btn' : 'review-btn' ?>"
                      style="display: inline-flex; align-items: center; gap: 4px;"
                      data-booking-id="<?= (int)$reviewServiceId ?>"
                      data-type="<?= htmlspecialchars($type, ENT_QUOTES, 'UTF-8') ?>"
                      data-title="<?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?>"
                      <?php if ($has_review): ?>data-view="1"<?php endif; ?>
                    >
                      <?php if ($has_review): ?>
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" style="width: 14px; height: 14px;">
                          <path stroke-linecap="round" stroke-linejoin="round" d="M8.625 12a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm0 0H8.25m4.125 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm0 0H12m4.125 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm0 0h-.375M21 12c0 4.556-4.03 8.25-9 8.25a9.764 9.764 0 0 1-2.555-.337A5.972 5.972 0 0 1 5.41 20.97a5.969 5.969 0 0 1-.474-.065 4.48 4.48 0 0 0 .978-2.025c.09-.457-.133-.901-.467-1.226C3.93 16.178 3 14.189 3 12c0-4.556 4.03-8.25 9-8.25s9 3.694 9 8.25Z" />
                        </svg>
                        View Review
                      <?php else: ?>
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" style="width: 14px; height: 14px;">
                          <path stroke-linecap="round" stroke-linejoin="round" d="M11.48 3.499a.562.562 0 0 1 1.04 0l2.125 5.111a.563.563 0 0 0 .475.345l5.518.442c.499.04.701.663.321.988l-4.204 3.602a.563.563 0 0 0-.182.557l1.285 5.385a.562.562 0 0 1-.84.61l-4.725-2.885a.562.562 0 0 0-.586 0L6.982 20.54a.562.562 0 0 1-.84-.61l1.285-5.386a.562.562 0 0 0-.182-.557l-4.204-3.602a.562.562 0 0 1 .321-.988l5.518-.442a.563.563 0 0 0 .475-.345L11.48 3.5Z" />
                        </svg>
                        Give Review
                      <?php endif; ?>
                    </button>
                  <?php else: ?>
                    <span style="opacity:.4; font-size:12px;">Not available</span>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    <?php endif; ?>

    <!-- ===================== HOTEL HISTORY ===================== -->
    <?php if (!empty($completed_hotel_bookings)): ?>
      <div class="formal-card" style="margin-top: 30px;">
        <div class="formal-card-header" style="background: #f0fdfa;">
          <div class="title-group">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" style="color: #0d9488;">
              <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 21h19.5m-18-18v18m10.5-18v18m6-13.5V21M6.75 6.75h.75m-.75 3h.75m-.75 3h.75m3-6h.75m-.75 3h.75m-.75 3h.75M6.75 21v-3.375c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21M3 3h12m-.75 4.5H21m-3.75 3.75h.008v.008h-.008v-.008Zm0 3h.008v.008h-.008v-.008Zm0 3h.008v.008h-.008v-.008Z" />
            </svg>
            <h4 style="color: #0f766e;">Completed Hotel Bookings</h4>
          </div>
          <span class="count-pill table-header-results" style="background: #0d9488;"><?= count($completed_hotel_bookings) ?> result<?= count($completed_hotel_bookings) === 1 ? '' : 's' ?></span>
        </div>

        <div class="table-responsive">
          <table class="formal-table">
            <thead>
              <tr>
                <th>Timeline</th>
                <th>Hotel & Room</th>
                <th>Guests</th>
                <th class="history-payment-column">Payment</th>
                <th>Amount</th>
                <th>Status</th>
                <th>Action</th>
              </tr>
            </thead>
            <tbody>
<?php foreach ($completed_hotel_bookings as $h):
              $guests = (int)($h['adults'] ?? 0) + (int)($h['children'] ?? 0);
              
              // 1. Get the actual status from the database (default to completed just in case)
              $raw_status = $h['booking_status'] ?? 'completed';
              
              // 2. Format it for the CSS class (e.g., "no show" becomes "no-show")
              $status_class = strtolower(str_replace(' ', '-', $raw_status));
              
              // 3. Format it for display (e.g., "cancelled" becomes "Cancelled")
              $display_status = ucwords(str_replace(['_', '-'], ' ', $raw_status));
            ?>
              <tr>
                <td>
                  <div style="display: flex; align-items: center; gap: 6px; font-size: 0.85rem;">
                    <span class="tag-outline"><?= htmlspecialchars($h['checkin_date'] ?? '-') ?></span>
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" style="width: 14px; height: 14px; color: #94a3b8;">
                      <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5 21 12m0 0-7.5 7.5M21 12H3" />
                    </svg>
                    <span class="tag-outline"><?= htmlspecialchars($h['checkout_date'] ?? '-') ?></span>
                  </div>
                </td>

                <td>
                  <strong style="color: #1e293b;"><?= htmlspecialchars($h['hotel_name'] ?? $h['resort_name'] ?? 'N/A') ?></strong><br>
                  <span class="text-muted"><?= htmlspecialchars($h['room_type'] ?? 'N/A') ?></span>
                </td>

                <td>
                  <div style="font-size: 0.85rem;">
                    <strong><?= $guests ?></strong> Total<br>
                    <span class="text-muted">(<?= (int)($h['adults'] ?? 0) ?> Adults, <?= (int)($h['children'] ?? 0) ?> Children)</span>
                  </div>
                </td>

                <td class="history-payment-column">
                  <?php $payment = strtolower($h['payment_status'] ?? 'unpaid'); ?>
                  <span class="pill <?= $payment ?>" style="display: inline-flex; align-items: center; gap: 4px;">
                    <?= ucfirst($payment) ?>
                  </span>
                </td>

                <td style="font-weight: 600; color: #1e293b;">
                  ₱<?= number_format((float)($h['total_amount'] ?? 0), 2) ?>
                </td>

                <td>
                  <!-- DYNAMIC STATUS PILL -->
                  <span class="pill <?= htmlspecialchars($status_class) ?>" style="display: inline-flex; align-items: center; gap: 4px;">
                    <?= htmlspecialchars($display_status) ?>
                  </span>
                  <button type="button" class="billing-btn-user" data-billing-type="hotel" data-billing-id="<?= (int)$h['hotel_booking_id'] ?>">Billing</button>
                </td>

                <td>
                <?php 
                  $key = $h['hotel_resort_id'] . '-' . $h['hotel_booking_id'];
                ?>
                <?php if (
                    strtolower($h['booking_status'] ?? '') === 'completed'
                    && strtolower($h['booking_status'] ?? '') !== 'cancelled'
                ): ?>

                    <!-- FIX: RESTORED ORIGINAL CLASSES AND ONCLICKS FOR HOTEL MODALS -->
                    <?php if (isset($reviewMap[$key])): ?>
                        <button class="btn-review view-review-btn"
                            style="display: inline-flex; align-items: center; gap: 4px;"
                            data-hotel-id="<?= (int)$h['hotel_resort_id'] ?>"
                            data-booking-id="<?= (int)$h['hotel_booking_id'] ?>"
                            data-hotel-name="<?= htmlspecialchars((string)($h['hotel_name'] ?? $h['resort_name'] ?? 'Hotel stay'), ENT_QUOTES, 'UTF-8') ?>"
                            data-room-type="<?= htmlspecialchars((string)($h['room_type'] ?? 'Room'), ENT_QUOTES, 'UTF-8') ?>"
                            data-stay-dates="<?= htmlspecialchars((string)($h['checkin_date'] ?? '-') . ' to ' . (string)($h['checkout_date'] ?? '-'), ENT_QUOTES, 'UTF-8') ?>"
                            onclick="viewReview(<?= (int)$h['hotel_resort_id'] ?>, <?= (int)$h['hotel_booking_id'] ?>)">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" style="width: 14px; height: 14px;">
                              <path stroke-linecap="round" stroke-linejoin="round" d="M8.625 12a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm0 0H8.25m4.125 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm0 0H12m4.125 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm0 0h-.375M21 12c0 4.556-4.03 8.25-9 8.25a9.764 9.764 0 0 1-2.555-.337A5.972 5.972 0 0 1 5.41 20.97a5.969 5.969 0 0 1-.474-.065 4.48 4.48 0 0 0 .978-2.025c.09-.457-.133-.901-.467-1.226C3.93 16.178 3 14.189 3 12c0-4.556 4.03-8.25 9-8.25s9 3.694 9 8.25Z" />
                            </svg>
                            View Review
                        </button>
                    <?php else: ?>
                        <button class="btn-review review-btn"
                            style="display: inline-flex; align-items: center; gap: 4px;"
                            data-hotel-id="<?= (int)$h['hotel_resort_id'] ?>"
                            data-booking-id="<?= (int)$h['hotel_booking_id'] ?>"
                            data-hotel-name="<?= htmlspecialchars((string)($h['hotel_name'] ?? $h['resort_name'] ?? 'Hotel stay'), ENT_QUOTES, 'UTF-8') ?>"
                            data-room-type="<?= htmlspecialchars((string)($h['room_type'] ?? 'Room'), ENT_QUOTES, 'UTF-8') ?>"
                            data-stay-dates="<?= htmlspecialchars((string)($h['checkin_date'] ?? '-') . ' to ' . (string)($h['checkout_date'] ?? '-'), ENT_QUOTES, 'UTF-8') ?>">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" style="width: 14px; height: 14px;">
                              <path stroke-linecap="round" stroke-linejoin="round" d="M11.48 3.499a.562.562 0 0 1 1.04 0l2.125 5.111a.563.563 0 0 0 .475.345l5.518.442c.499.04.701.663.321.988l-4.204 3.602a.563.563 0 0 0-.182.557l1.285 5.385a.562.562 0 0 1-.84.61l-4.725-2.885a.562.562 0 0 0-.586 0L6.982 20.54a.562.562 0 0 1-.84-.61l1.285-5.386a.562.562 0 0 0-.182-.557l-4.204-3.602a.562.562 0 0 1 .321-.988l5.518-.442a.563.563 0 0 0 .475-.345L11.48 3.5Z" />
                            </svg>
                            Give Review
                        </button>
                    <?php endif; ?>
                <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    <?php endif; ?>

  <?php endif; ?>

</section>
  </main>

<div id="reviewModal" class="af-modal-overlay">
  <div class="af-modal">

    <!-- HEADER -->
    <div class="af-modal-header">
      <strong id="reviewModalTitle">Give Your Review</strong>
      <button type="button" onclick="closeReviewModal()">×</button>
    </div>

    <form method="POST" action="submit_hotel_review.php" id="reviewForm">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($touristEngagementCsrf, ENT_QUOTES, 'UTF-8') ?>">

      <!-- HOTEL ID -->
      <input type="hidden" name="hotel_resort_id" id="review_hotel_id">
      <input type="hidden" name="hotel_booking_id" id="review_booking_id">

      <div class="hotel-review-context" aria-label="Hotel being reviewed">
        <span class="hotel-review-context-icon">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M3 21h18M5 21V5h10v16M15 10h4v11M8 8h1M11 8h1M8 12h1M11 12h1M8 16h1M11 16h1"></path></svg>
        </span>
        <div>
          <small>REVIEWING</small>
          <strong id="hotelReviewTarget">Hotel stay</strong>
          <span id="hotelReviewDetails">Room and stay details</span>
        </div>
      </div>

      <!-- OVERALL RATING -->
      <div class="review-group">
        <p class="af-package-title">Overall Rating</p>
        <div class="rating" id="rating_overall">

          <input type="radio" id="overall5" name="rating" value="5" required>
          <label for="overall5">
            <svg viewBox="0 0 24 24">
              <path d="M12,17.27L18.18,21L16.54,13.97L22,9.24L14.81,8.62L12,2L9.19,8.62L2,9.24L7.45,13.97L5.82,21L12,17.27Z"/>
            </svg>
          </label>

          <input type="radio" id="overall4" name="rating" value="4">
          <label for="overall4">
            <svg viewBox="0 0 24 24">
              <path d="M12,17.27L18.18,21L16.54,13.97L22,9.24L14.81,8.62L12,2L9.19,8.62L2,9.24L7.45,13.97L5.82,21L12,17.27Z"/>
            </svg>
          </label>

          <input type="radio" id="overall3" name="rating" value="3">
          <label for="overall3">
            <svg viewBox="0 0 24 24">
              <path d="M12,17.27L18.18,21L16.54,13.97L22,9.24L14.81,8.62L12,2L9.19,8.62L2,9.24L7.45,13.97L5.82,21L12,17.27Z"/>
            </svg>
          </label>

          <input type="radio" id="overall2" name="rating" value="2">
          <label for="overall2">
            <svg viewBox="0 0 24 24">
              <path d="M12,17.27L18.18,21L16.54,13.97L22,9.24L14.81,8.62L12,2L9.19,8.62L2,9.24L7.45,13.97L5.82,21L12,17.27Z"/>
            </svg>
          </label>

          <input type="radio" id="overall1" name="rating" value="1">
          <label for="overall1">
            <svg viewBox="0 0 24 24">
              <path d="M12,17.27L18.18,21L16.54,13.97L22,9.24L14.81,8.62L12,2L9.19,8.62L2,9.24L7.45,13.97L5.82,21L12,17.27Z"/>
            </svg>
          </label>

        </div>
      </div>

       <!-- VALUE -->
      <div class="review-group">
        <p class="af-package-title">Value for Money</p>
        <div class="rating" id="rating_value">

          <input type="radio" id="value5" name="value_rating" value="5" required>
          <label for="value5"><svg viewBox="0 0 24 24"><path d="M12,17.27L18.18,21L16.54,13.97L22,9.24L14.81,8.62L12,2L9.19,8.62L2,9.24L7.45,13.97L5.82,21L12,17.27Z"/></svg></label>

          <input type="radio" id="value4" name="value_rating" value="4">
          <label for="value4"><svg viewBox="0 0 24 24"><path d="M12,17.27L18.18,21L16.54,13.97L22,9.24L14.81,8.62L12,2L9.19,8.62L2,9.24L7.45,13.97L5.82,21L12,17.27Z"/></svg></label>

          <input type="radio" id="value3" name="value_rating" value="3">
          <label for="value3"><svg viewBox="0 0 24 24"><path d="M12,17.27L18.18,21L16.54,13.97L22,9.24L14.81,8.62L12,2L9.19,8.62L2,9.24L7.45,13.97L5.82,21L12,17.27Z"/></svg></label>

          <input type="radio" id="value2" name="value_rating" value="2">
          <label for="value2"><svg viewBox="0 0 24 24"><path d="M12,17.27L18.18,21L16.54,13.97L22,9.24L14.81,8.62L12,2L9.19,8.62L2,9.24L7.45,13.97L5.82,21L12,17.27Z"/></svg></label>

          <input type="radio" id="value1" name="value_rating" value="1">
          <label for="value1"><svg viewBox="0 0 24 24"><path d="M12,17.27L18.18,21L16.54,13.97L22,9.24L14.81,8.62L12,2L9.19,8.62L2,9.24L7.45,13.97L5.82,21L12,17.27Z"/></svg></label>

        </div>
      </div>

      <!-- SERVICE -->
      <div class="review-group">
        <p class="af-package-title">Service</p>
        <div class="rating" id="rating_service">

          <input type="radio" id="service5" name="service_rating" value="5" required>
          <label for="service5"><svg viewBox="0 0 24 24"><path d="M12,17.27L18.18,21L16.54,13.97L22,9.24L14.81,8.62L12,2L9.19,8.62L2,9.24L7.45,13.97L5.82,21L12,17.27Z"/></svg></label>

          <input type="radio" id="service4" name="service_rating" value="4">
          <label for="service4"><svg viewBox="0 0 24 24"><path d="M12,17.27L18.18,21L16.54,13.97L22,9.24L14.81,8.62L12,2L9.19,8.62L2,9.24L7.45,13.97L5.82,21L12,17.27Z"/></svg></label>

          <input type="radio" id="service3" name="service_rating" value="3">
          <label for="service3"><svg viewBox="0 0 24 24"><path d="M12,17.27L18.18,21L16.54,13.97L22,9.24L14.81,8.62L12,2L9.19,8.62L2,9.24L7.45,13.97L5.82,21L12,17.27Z"/></svg></label>

          <input type="radio" id="service2" name="service_rating" value="2">
          <label for="service2"><svg viewBox="0 0 24 24"><path d="M12,17.27L18.18,21L16.54,13.97L22,9.24L14.81,8.62L12,2L9.19,8.62L2,9.24L7.45,13.97L5.82,21L12,17.27Z"/></svg></label>

          <input type="radio" id="service1" name="service_rating" value="1">
          <label for="service1"><svg viewBox="0 0 24 24"><path d="M12,17.27L18.18,21L16.54,13.97L22,9.24L14.81,8.62L12,2L9.19,8.62L2,9.24L7.45,13.97L5.82,21L12,17.27Z"/></svg></label>

        </div>
      </div>

      <!-- CLEANLINESS -->
      <div class="review-group">
        <p class="af-package-title">Cleanliness</p>
        <div class="rating" id="rating_clean">

          <input type="radio" id="clean5" name="cleanliness_rating" value="5" required>
          <label for="clean5"><svg viewBox="0 0 24 24"><path d="M12,17.27L18.18,21L16.54,13.97L22,9.24L14.81,8.62L12,2L9.19,8.62L2,9.24L7.45,13.97L5.82,21L12,17.27Z"/></svg></label>

          <input type="radio" id="clean4" name="cleanliness_rating" value="4">
          <label for="clean4"><svg viewBox="0 0 24 24"><path d="M12,17.27L18.18,21L16.54,13.97L22,9.24L14.81,8.62L12,2L9.19,8.62L2,9.24L7.45,13.97L5.82,21L12,17.27Z"/></svg></label>

          <input type="radio" id="clean3" name="cleanliness_rating" value="3">
          <label for="clean3"><svg viewBox="0 0 24 24"><path d="M12,17.27L18.18,21L16.54,13.97L22,9.24L14.81,8.62L12,2L9.19,8.62L2,9.24L7.45,13.97L5.82,21L12,17.27Z"/></svg></label>

          <input type="radio" id="clean2" name="cleanliness_rating" value="2">
          <label for="clean2"><svg viewBox="0 0 24 24"><path d="M12,17.27L18.18,21L16.54,13.97L22,9.24L14.81,8.62L12,2L9.19,8.62L2,9.24L7.45,13.97L5.82,21L12,17.27Z"/></svg></label>

          <input type="radio" id="clean1" name="cleanliness_rating" value="1">
          <label for="clean1"><svg viewBox="0 0 24 24"><path d="M12,17.27L18.18,21L16.54,13.97L22,9.24L14.81,8.62L12,2L9.19,8.62L2,9.24L7.45,13.97L5.82,21L12,17.27Z"/></svg></label>

        </div>
      </div>

      <!-- FACILITIES -->
      <div class="review-group">
        <p class="af-package-title">Facilities</p>
        <div class="rating" id="rating_fac">

          <input type="radio" id="fac5" name="facilities_rating" value="5" required>
          <label for="fac5"><svg viewBox="0 0 24 24"><path d="M12,17.27L18.18,21L16.54,13.97L22,9.24L14.81,8.62L12,2L9.19,8.62L2,9.24L7.45,13.97L5.82,21L12,17.27Z"/></svg></label>

          <input type="radio" id="fac4" name="facilities_rating" value="4">
          <label for="fac4"><svg viewBox="0 0 24 24"><path d="M12,17.27L18.18,21L16.54,13.97L22,9.24L14.81,8.62L12,2L9.19,8.62L2,9.24L7.45,13.97L5.82,21L12,17.27Z"/></svg></label>

          <input type="radio" id="fac3" name="facilities_rating" value="3">
          <label for="fac3"><svg viewBox="0 0 24 24"><path d="M12,17.27L18.18,21L16.54,13.97L22,9.24L14.81,8.62L12,2L9.19,8.62L2,9.24L7.45,13.97L5.82,21L12,17.27Z"/></svg></label>

          <input type="radio" id="fac2" name="facilities_rating" value="2">
          <label for="fac2"><svg viewBox="0 0 24 24"><path d="M12,17.27L18.18,21L16.54,13.97L22,9.24L14.81,8.62L12,2L9.19,8.62L2,9.24L7.45,13.97L5.82,21L12,17.27Z"/></svg></label>

          <input type="radio" id="fac1" name="facilities_rating" value="1">
          <label for="fac1"><svg viewBox="0 0 24 24"><path d="M12,17.27L18.18,21L16.54,13.97L22,9.24L14.81,8.62L12,2L9.19,8.62L2,9.24L7.45,13.97L5.82,21L12,17.27Z"/></svg></label>

        </div>
      </div>

      <!-- ROOM COMFORT -->
      <div class="review-group">
        <p class="af-package-title">Room Comfort</p>
        <div class="rating" id="rating_room">

          <input type="radio" id="room5" name="room_comfort_rating" value="5" required>
          <label for="room5"><svg viewBox="0 0 24 24"><path d="M12,17.27L18.18,21L16.54,13.97L22,9.24L14.81,8.62L12,2L9.19,8.62L2,9.24L7.45,13.97L5.82,21L12,17.27Z"/></svg></label>

          <input type="radio" id="room4" name="room_comfort_rating" value="4">
          <label for="room4"><svg viewBox="0 0 24 24"><path d="M12,17.27L18.18,21L16.54,13.97L22,9.24L14.81,8.62L12,2L9.19,8.62L2,9.24L7.45,13.97L5.82,21L12,17.27Z"/></svg></label>

          <input type="radio" id="room3" name="room_comfort_rating" value="3">
          <label for="room3"><svg viewBox="0 0 24 24"><path d="M12,17.27L18.18,21L16.54,13.97L22,9.24L14.81,8.62L12,2L9.19,8.62L2,9.24L7.45,13.97L5.82,21L12,17.27Z"/></svg></label>

          <input type="radio" id="room2" name="room_comfort_rating" value="2">
          <label for="room2"><svg viewBox="0 0 24 24"><path d="M12,17.27L18.18,21L16.54,13.97L22,9.24L14.81,8.62L12,2L9.19,8.62L2,9.24L7.45,13.97L5.82,21L12,17.27Z"/></svg></label>

          <input type="radio" id="room1" name="room_comfort_rating" value="1">
          <label for="room1"><svg viewBox="0 0 24 24"><path d="M12,17.27L18.18,21L16.54,13.97L22,9.24L14.81,8.62L12,2L9.19,8.62L2,9.24L7.45,13.97L5.82,21L12,17.27Z"/></svg></label>

        </div>
      </div>

      <!-- COMMENT -->
      <div class="review-group">
        <p class="af-package-title">Comment</p>

        <textarea
          name="review_message"
          id="review_message"
          placeholder="Share your experience..."></textarea>
      </div>

      <!-- ACTIONS -->
      <div class="af-actions" id="reviewActions">

        <button type="submit" class="af-btn" id="submitBtn">
          Submit Review
        </button>

        <button type="button" class="af-btn secondary" onclick="closeReviewModal()">
          Cancel
        </button>

      </div>

    </form>
  </div>
</div>
</div>

<div id="af-feedback-modal" class="af-modal-overlay" style="display:none;">
  <div class="af-modal">

    <!-- HEADER -->
    <div class="af-modal-header">
      <strong id="af-feedback-title">Give Your Review</strong>
      <button id="af-feedback-close" type="button">×</button>
    </div>

    <!-- INFO -->
    <div class="af-review-info">
      <p>Review Type: <span id="af-feedback-type-label">---</span></p>
      <p>Review for: <span id="af-feedback-target-title">---</span></p>
    </div>

    <input type="hidden" id="af-booking-id">
    <input type="hidden" id="af-booking-type">

    <!-- ================= STAR RATING (FIXED STYLE HOOK) ================= -->
    <div class="rating" id="af-star-rating">
      <input type="radio" id="star-5" name="star-radio" value="5">
      <label for="star-5">★</label>

      <input type="radio" id="star-4" name="star-radio" value="4">
      <label for="star-4">★</label>

      <input type="radio" id="star-3" name="star-radio" value="3">
      <label for="star-3">★</label>

      <input type="radio" id="star-2" name="star-radio" value="2">
      <label for="star-2">★</label>

      <input type="radio" id="star-1" name="star-radio" value="1">
      <label for="star-1">★</label>
    </div>

    <br>

    <textarea id="af-feedback-comment" placeholder="Write your review..."></textarea>

    <!-- ================= ACTIONS ================= -->

    <!-- VIEW MODE -->
    <div class="af-actions" id="af-actions-view" style="display:none;">
      <button type="button" class="af-btn secondary" id="close-view-btn">Close</button>
    </div>

    <!-- EDIT MODE -->
    <div class="af-actions" id="af-actions-edit" style="display:none;">
      <button type="button" class="af-btn" id="submit-review-btn">Submit</button>
      <button type="button" class="af-btn secondary" id="cancel-review-btn">Cancel</button>
    </div>

  </div>
</div>
<div id="noteModal" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.5);align-items:center;justify-content:center;">
  <div style="background:#fff;padding:20px;border-radius:10px;max-width:400px;width:90%;position:relative;">
    <button id="closeModal" style="position:absolute;top:10px;right:10px;border:none;background:none;font-size:18px;cursor:pointer">&times;</button>
    <h4>Booking Note</h4>
    <p id="modalNote" style="margin-top:10px;"></p>
  </div>
</div>

<div id="touristModalUser" class="tourist-modal-user" style="display:none;">
  <div class="tourist-modal-content-user extended">

    <div class="tourist-modal-header-user">
      <div>
        <span class="tourist-modal-kicker">Passenger manifest</span>
        <h3>Add Tourist Information</h3>
        <p>Complete the required details for every passenger in this booking.</p>
      </div>
      <button type="button" class="tourist-modal-close-user" aria-label="Close tourist form">&times;</button>
    </div>

    <form id="touristFormUser" method="POST">
      <input type="hidden" name="booking_id" id="touristBookingId">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($manifestCsrf, ENT_QUOTES, 'UTF-8') ?>">

      <div class="tourist-form-summary">
        <div><strong id="touristFormCount">1</strong><span>Tourists added</span></div>
        <p>Tap an address field to enter a structured address or reuse one already entered.</p>
      </div>

      <div id="touristRowsUser">
        <!-- Tourist rows injected here -->
      </div>

      <div class="tourist-modal-footer-user">
        <button type="button" class="btn add-more-user" id="addMoreTouristUser">+ Add another tourist</button>
        <button type="submit" class="btn tourist-submit-user" disabled>Submit Tourist List</button>
      </div>
    </form>

  </div>
</div>

<div id="touristAddressModal" class="tourist-address-overlay" aria-hidden="true">
  <section class="tourist-address-modal" role="dialog" aria-modal="true" aria-labelledby="touristAddressTitle">
    <header class="tourist-address-header">
      <div>
        <span>Structured address</span>
        <h3 id="touristAddressTitle">Passenger Address</h3>
        <p>Select each location in order to build a complete address.</p>
      </div>
      <button type="button" id="closeTouristAddress" aria-label="Close address form">&times;</button>
    </header>

    <div class="tourist-address-body">
      <div class="tourist-address-copy" id="touristAddressCopyWrap" hidden>
        <label for="touristAddressCopy">Use an address already entered</label>
        <select id="touristAddressCopy">
          <option value="">Choose an address to copy...</option>
        </select>
      </div>

      <div class="tourist-address-grid">
        <label class="tourist-address-field full"><span>Country</span><select id="touristAddressCountry"><option value="">Loading countries...</option></select></label>
        <label class="tourist-address-field"><span>Region / State</span><select id="touristAddressRegion" disabled><option value="">Select a country first</option></select></label>
        <label class="tourist-address-field"><span>Province</span><select id="touristAddressProvince" disabled><option value="">Select a region first</option></select></label>
        <label class="tourist-address-field"><span>City / Municipality</span><select id="touristAddressCity" disabled><option value="">Select a province first</option></select></label>
        <label class="tourist-address-field"><span>Barangay</span><select id="touristAddressBarangay" disabled><option value="">Select a city first</option></select></label>
        <label class="tourist-address-field"><span>Postal Code</span><input type="text" id="touristAddressPostalCode" maxlength="20" placeholder="Filled automatically" readonly></label>
        <label class="tourist-address-field"><span>Street / House No.</span><input type="text" id="touristAddressStreet" maxlength="180" placeholder="Street, building, or house number"></label>
      </div>
      <p class="tourist-address-error" id="touristAddressError" role="alert" hidden></p>
    </div>

    <footer class="tourist-address-footer">
      <button type="button" class="tourist-address-cancel" id="cancelTouristAddress">Cancel</button>
      <button type="button" class="tourist-address-done" id="saveTouristAddress">Done</button>
    </footer>
  </section>
</div>

<div id="touristProfilePdfModal" class="tourist-profile-pdf-overlay" aria-hidden="true">
  <section class="tourist-profile-pdf-modal" role="dialog" aria-modal="true" aria-labelledby="touristProfilePdfTitle">
    <header class="tourist-profile-pdf-header">
      <div class="tourist-profile-pdf-heading">
        <img src="../img/email-logo.png" alt="" aria-hidden="true">
        <div><span>PASSENGER DOCUMENT</span><h3 id="touristProfilePdfTitle">Passenger Information Record</h3><p>Preview or download your submitted traveler details.</p></div>
      </div>
      <div class="tourist-profile-pdf-header-actions">
        <div class="tourist-profile-pdf-meta"><strong>iTour passenger record</strong><span>System-generated booking document</span></div>
        <a id="touristProfilePdfDownload" href="#" download>Download PDF</a>
        <button type="button" class="tourist-profile-pdf-close" onclick="closeTouristProfilePdf()" aria-label="Close PDF viewer">&times;</button>
      </div>
    </header>
    <div class="tourist-profile-pdf-body">
      <div id="touristProfilePdfLoading" class="tourist-profile-pdf-loading" role="status" aria-live="polite">
        <div class="tourist-profile-pdf-loading-content">
          <span class="tourist-profile-pdf-spinner" aria-hidden="true"></span>
          <strong>Generating passenger PDF...</strong>
          <span>Please wait while your document is prepared.</span>
        </div>
      </div>
      <iframe id="touristProfilePdfFrame" src="about:blank" title="Passenger information PDF"></iframe>
    </div>
  </section>
</div>

<!-- Provider cancellation response modal -->
<div id="profileDecisionModal" class="profile-decision-overlay" aria-hidden="true">
  <section class="profile-decision-modal" role="dialog" aria-modal="true" aria-labelledby="profileDecisionTitle">
    <header class="profile-decision-header">
      <div class="profile-decision-header-icon"><svg viewBox="0 0 24 24"><path d="M12 3 4 6v5c0 4.8 3.3 8 8 10 4.7-2 8-5.2 8-10V6l-8-3Z"></path><path d="M8.5 12h7M12 8.5V15"></path></svg></div>
      <div><span>BOOKING SCHEDULE UPDATE</span><h3 id="profileDecisionTitle">Respond to Provider Cancellation</h3><p id="profileDecisionSubtitle">Choose how you would like us to handle this booking.</p></div>
      <button type="button" id="profileDecisionClose" class="profile-decision-close" aria-label="Close"><svg viewBox="0 0 24 24"><path d="m6 6 12 12M18 6 6 18"></path></svg></button>
    </header>
    <div class="profile-decision-progress">
      <div class="active" data-profile-progress="1"><span>1</span><small>Response</small></div><i></i>
      <div data-profile-progress="2"><span>2</span><small>New Date</small></div><i></i>
      <div data-profile-progress="3"><span>3</span><small>Review</small></div>
    </div>
    <div class="profile-decision-body">
      <div class="profile-decision-context">
        <div><small>BOOKING</small><strong id="profileDecisionReference">—</strong></div>
        <div><small>SERVICE</small><strong id="profileDecisionService">—</strong></div>
        <div><small>ORIGINAL SCHEDULE</small><strong id="profileDecisionOriginal">—</strong></div>
      </div>
      <div class="profile-decision-provider-alert"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"></circle><path d="M12 7v6M12 17h.01"></path></svg><div><strong>The original schedule will not proceed</strong><p id="profileDecisionReason">—</p><small>Decision deadline: <b id="profileDecisionDeadline">—</b></small></div></div>

      <div class="profile-decision-step active" data-profile-step="1">
        <div class="profile-decision-section-heading"><span>01</span><div><h4>Choose your preferred resolution</h4><p>Your booking details and existing payment remain protected while you decide.</p></div></div>
        <div class="profile-decision-options">
          <label><input type="radio" name="profileDecisionChoice" value="reschedule"><span class="profile-decision-option-icon"><svg viewBox="0 0 24 24"><path d="M20 11a8 8 0 1 0-2.3 5.7"></path><path d="M20 4v7h-7"></path></svg></span><span><strong>Reschedule Booking</strong><small>Choose a free new date. Package, guests, services, and payment stay unchanged.</small></span><i></i></label>
          <label class="refund"><input type="radio" name="profileDecisionChoice" value="full_refund"><span class="profile-decision-option-icon"><svg viewBox="0 0 24 24"><path d="M4 7h16v10H4z"></path><path d="M7 11h5M16 10v2"></path></svg></span><span><strong>Cancel &amp; Get Full Refund</strong><small id="profileDecisionRefundCopy">Cancel now and refund the amount successfully paid.</small></span><i></i></label>
        </div>
        <p class="profile-decision-error" id="profileDecisionChoiceError"></p>
      </div>

      <div class="profile-decision-step" data-profile-step="2">
        <div class="profile-decision-section-heading"><span>02</span><div><h4>Select your new booking date</h4><p>All other booking details are read-only and cannot be changed here.</p></div></div>
        <div class="profile-decision-locked-grid">
          <div><small>TOURISTS / PAX</small><strong id="profileDecisionPax">—</strong></div><div><small>AMOUNT PAID</small><strong id="profileDecisionPaid">—</strong></div>
          <div><small>REMAINING BALANCE</small><strong id="profileDecisionBalance">—</strong></div><div><small>SELECTED SERVICE</small><strong id="profileDecisionSelection">—</strong></div>
        </div>
        <label class="profile-decision-date-field"><span>New booking date <b>*</b></span><input type="text" id="profileDecisionNewDate" placeholder="Select an available date" readonly autocomplete="off"></label>
        <div class="profile-decision-calendar-meta"><span id="profileDecisionAvailability">Checking available dates...</span><span class="profile-decision-calendar-legend"><i></i>Unavailable date</span></div>
        <div id="profileDecisionRangeSummary" class="profile-decision-range-summary" hidden></div>
        <p class="profile-decision-error" id="profileDecisionDateError"></p>
      </div>

      <div class="profile-decision-step" data-profile-step="3">
        <div class="profile-decision-section-heading"><span>03</span><div><h4>Review and confirm your decision</h4><p>Check the information carefully before submitting.</p></div></div>
        <div class="profile-decision-review"><div><small>YOUR DECISION</small><strong id="profileDecisionReviewChoice">—</strong></div><div><small>PAYMENT AFFECTED</small><strong id="profileDecisionReviewPayment">—</strong></div><div class="wide"><small>DATE CHANGE</small><strong id="profileDecisionReviewDates">—</strong></div></div>
        <div id="profileDecisionFinalNotice" class="profile-decision-final-notice"></div>
        <label class="profile-decision-ack"><input type="checkbox" id="profileDecisionAck"><span id="profileDecisionAckText">I reviewed these details and confirm my decision.</span></label>
        <p class="profile-decision-error" id="profileDecisionReviewError"></p>
      </div>
    </div>
    <footer class="profile-decision-footer">
      <button type="button" id="profileDecisionBack" class="profile-decision-btn secondary" hidden><svg viewBox="0 0 24 24"><path d="m15 18-6-6 6-6"></path></svg>Back</button>
      <span></span>
      <button type="button" id="profileDecisionLater" class="profile-decision-btn ghost">Decide Later</button>
      <button type="button" id="profileDecisionNext" class="profile-decision-btn primary">Continue<svg viewBox="0 0 24 24"><path d="m9 18 6-6-6-6"></path></svg></button>
      <button type="button" id="profileDecisionSubmit" class="profile-decision-btn primary" hidden>Confirm Decision</button>
    </footer>
    <div id="profileDecisionProcessing" class="profile-decision-processing" hidden><span></span><strong>Saving your decision…</strong><small>Please keep this page open.</small></div>
  </section>
</div>

<!-- Booking Details Modal -->
<div id="bookingDetailsModalUser" class="booking-details-modal-overlay-user" aria-hidden="true">
  <aside class="booking-details-modal-user" role="dialog" aria-modal="true" aria-labelledby="bookingDetailsTitleUser">
    <div class="booking-details-modal-header-user">
      <div class="booking-details-modal-title-user">
        <img class="booking-details-brand-logo-user" src="../img/newlogo.png" alt="Mercedes Tourism logo">
        <div>
          <p class="booking-brand-kicker-user">ITour Mercedes</p>
          <h3 id="bookingDetailsTitleUser">Booking Details</h3>
        </div>
      </div>
      <button type="button" onclick="closeBookingDetailsModalUser()" class="booking-details-modal-close-user" aria-label="Close booking details">&times;</button>
    </div>
    <div class="booking-details-modal-body-user" id="bookingDetailsContentUser">
      <!-- Booking details injected here -->
    </div>
    <div class="booking-details-modal-footer-user">
      <button type="button" onclick="closeBookingDetailsModalUser()" class="booking-details-modal-btn-close-user">Close Details</button>
    </div>
  </aside>
</div>

<!-- Billing Modal -->
<div id="profileBillingModal" class="profile-billing-overlay" aria-hidden="true">
  <section class="profile-billing-modal" role="dialog" aria-modal="true" aria-labelledby="profileBillingTitle">
    <header class="profile-billing-header">
      <div class="profile-billing-icon" aria-hidden="true">₱</div>
      <div><span>BOOKING ACCOUNT</span><h3 id="profileBillingTitle">Billing Details</h3><p id="profileBillingSubtitle">Loading your payment information…</p></div>
      <button type="button" class="profile-modal-x" data-close-profile-billing aria-label="Close billing details">&times;</button>
    </header>
    <div class="profile-billing-body" id="profileBillingBody"><div class="profile-billing-loading"><span></span><p>Preparing billing statement…</p></div></div>
    <footer class="profile-billing-footer">
      <button type="button" class="profile-billing-button secondary" data-close-profile-billing>Close</button>
      <button type="button" class="profile-billing-button receipt" id="profileViewReceipt">View Receipt</button>
      <button type="button" class="profile-billing-button primary" id="profilePayRemaining">Pay Remaining Balance Online</button>
    </footer>
  </section>
</div>

<!-- Receipt Preview Modal -->
<div id="profileReceiptModal" class="profile-billing-overlay receipt-layer" aria-hidden="true">
  <section class="profile-receipt-shell" role="dialog" aria-modal="true" aria-labelledby="profileReceiptTitle">
    <header class="profile-receipt-modal-head"><div><span>OFFICIAL PAYMENT RECORD</span><h3 id="profileReceiptTitle">Booking Receipt</h3></div><button type="button" class="profile-modal-x" data-close-profile-receipt>&times;</button></header>
    <div class="profile-receipt-stage"><article class="profile-receipt-paper" id="profileReceiptPaper"></article></div>
    <footer class="profile-receipt-footer">
      <button type="button" class="profile-billing-button secondary" data-close-profile-receipt>Close</button>
      <div class="profile-download-wrap"><button type="button" class="profile-billing-button primary" id="profileDownloadReceipt">Download Receipt</button><small id="profileReceiptDownloadNote" hidden>The receipt will be downloadable after the booking is completed.</small></div>
    </footer>
  </section>
</div>

<!-- Remaining Balance Payment Modal -->
<div id="profilePaymentModal" class="profile-billing-overlay receipt-layer" aria-hidden="true">
  <section class="profile-payment-shell" role="dialog" aria-modal="true" aria-labelledby="profilePaymentTitle">
    <header class="profile-receipt-modal-head"><div><span>SECURE ONLINE PAYMENT</span><h3 id="profilePaymentTitle">Pay Remaining Balance Online</h3></div><button type="button" class="profile-modal-x" data-close-profile-payment>&times;</button></header>
    <div class="profile-payment-body">
      <div class="profile-payment-due"><small>AMOUNT DUE</small><strong id="profilePaymentDue">₱0.00</strong></div>
      <label><span>Payment Method</span><select id="profilePaymentMethod" required><option value="online_payment" selected>Online Payment</option></select></label>
      <label><span>Payment Amount</span><div class="profile-payment-money"><i>₱</i><input id="profilePaymentAmount" type="number" readonly /></div></label>
      <p>This transaction will be processed as an online payment. Cash payments are recorded only through authorized staff and administrator accounts.</p>
    </div>
    <footer class="profile-receipt-footer"><button type="button" class="profile-billing-button secondary" data-close-profile-payment>Cancel</button><button type="button" class="profile-billing-button primary" id="profileConfirmPayment">Pay Online</button></footer>
  </section>
</div>

<div id="cropModal" class="crop-modal" aria-hidden="true">

  <section class="crop-container" role="dialog" aria-modal="true" aria-labelledby="cropModalTitle" aria-describedby="cropModalDescription">

    <!-- HEADER -->
    <div class="crop-header">
      <span class="crop-header-icon">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="8" r="4"></circle><path d="M4 21a8 8 0 0 1 16 0"></path><path d="m17 3 1 2 2 1-2 1-1 2-1-2-2-1 2-1 1-2Z"></path></svg>
      </span>
      <div class="crop-header-copy">
        <h3 id="cropModalTitle">Adjust profile photo</h3>
        <p id="cropModalDescription">Position your photo inside the circular preview.</p>
      </div>
      <button type="button" onclick="closeCrop()" class="crop-close">✕</button>
    </div>

    <!-- BODY -->
    <div class="crop-body">
      <div class="crop-stage">
        <img id="cropImage" alt="Photo selected for cropping">
      </div>
      <div class="crop-tools" aria-label="Photo zoom controls">
        <button class="crop-tool-button" id="cropZoomOut" type="button" aria-label="Zoom out">&minus;</button>
        <button class="crop-tool-button reset" id="cropReset" type="button">Reset</button>
        <button class="crop-tool-button" id="cropZoomIn" type="button" aria-label="Zoom in">+</button>
      </div>
      <p class="crop-help">Drag the photo to reposition it. Scroll or use the controls to zoom.</p>
    </div>

    <!-- FOOTER -->
    <div class="crop-footer">
      <button type="button" class="crop-cancel" onclick="closeCrop()">Cancel</button>
      <button type="button" class="crop-use" id="cropSaveButton" onclick="saveCrop()" disabled>Use this photo</button>
    </div>

  </section>

</div>
  <script>
function scrollRow(id, distance) {
  document.getElementById(id).scrollBy({
    left: distance,
    behavior: 'smooth'
  });
}
</script>

<script>

const dropzone = document.getElementById('dropzone');
const fileInput = document.getElementById('fileInput');

let cropper;
const cropModal = document.getElementById('cropModal');
const cropImage = document.getElementById('cropImage');
const cropSaveButton = document.getElementById('cropSaveButton');
const cropZoomOut = document.getElementById('cropZoomOut');
const cropZoomIn = document.getElementById('cropZoomIn');
const cropReset = document.getElementById('cropReset');
let cropPreviewUrl = null;
cropModal?.querySelector('.crop-close')?.setAttribute('aria-label', 'Close photo editor');

// ===================== CLICK =====================
dropzone.addEventListener('click', () => {
  fileInput.click();
});
dropzone.addEventListener('keydown', event => {
  if (event.key === 'Enter' || event.key === ' ') {
    event.preventDefault();
    fileInput.click();
  }
});

// ===================== DRAG EVENTS =====================
['dragenter', 'dragover', 'dragleave', 'drop'].forEach(event => {
  dropzone.addEventListener(event, e => {
    e.preventDefault();
    e.stopPropagation();
  });
});

// highlight on drag
dropzone.addEventListener('dragover', () => {
  dropzone.classList.add('is-dragging');
});

// reset style
function resetDropzone() {
  dropzone.classList.remove('is-dragging');
}

dropzone.addEventListener('dragleave', resetDropzone);

// ===================== DROP =====================
dropzone.addEventListener('drop', (e) => {
  resetDropzone();

  const file = e.dataTransfer.files[0];
  if (!file) return;

  fileInput.files = e.dataTransfer.files;

  openCropper(file); // 🔥 IMPORTANT FIX
});

// ===================== FILE INPUT =====================
fileInput.addEventListener('change', () => {
  const file = fileInput.files[0];
  if (file) {
    openCropper(file); // 🔥 IMPORTANT FIX
  }
});

// ===================== CROPPER =====================
function openCropper(file) {
  const reader = new FileReader();

  reader.onload = function (e) {
    cropImage.src = e.target.result;

    cropModal.classList.add('is-open');
    cropModal.setAttribute('aria-hidden', 'false');
    document.body.classList.add('crop-modal-open');
    if (cropSaveButton) cropSaveButton.disabled = true;

    if (cropper) cropper.destroy();

    cropper = new Cropper(cropImage, {
      aspectRatio: 1,
      viewMode: 1,
      dragMode: 'move',
      autoCropArea: 0.82,
      guides: false,
      center: false,
      highlight: false,
      background: false,
      cropBoxMovable: false,
      cropBoxResizable: false,
      toggleDragModeOnDblclick: false,
      wheelZoomRatio: 0.08,
      ready() {
        if (cropSaveButton) cropSaveButton.disabled = false;
      }
    });

    setTimeout(() => cropModal.querySelector('.crop-close')?.focus(), 40);
  };

  reader.readAsDataURL(file);
}

// ===================== CLOSE CROPPER =====================
function closeCrop(keepFile = false) {
  cropModal.classList.remove('is-open');
  cropModal.setAttribute('aria-hidden', 'true');
  document.body.classList.remove('crop-modal-open');
  if (cropper) {
    cropper.destroy();
    cropper = null;
  }
  if (cropSaveButton) cropSaveButton.disabled = true;
  if (!keepFile) fileInput.value = '';
}

cropZoomOut?.addEventListener('click', () => cropper?.zoom(-0.1));
cropZoomIn?.addEventListener('click', () => cropper?.zoom(0.1));
cropReset?.addEventListener('click', () => cropper?.reset());
cropModal?.addEventListener('mousedown', event => {
  if (event.target === cropModal) closeCrop();
});
document.addEventListener('keydown', event => {
  if (event.key === 'Escape' && cropModal?.classList.contains('is-open')) closeCrop();
});

// ===================== SAVE CROPPED IMAGE =====================
function saveCrop() {
  if (!cropper) return;
  const canvas = cropper.getCroppedCanvas({
    width: 512,
    height: 512,
    imageSmoothingEnabled: true,
    imageSmoothingQuality: 'high'
  });

  if (!canvas) return;

  canvas.toBlob((blob) => {

    if (!blob) return;

    // create file from cropped image
    const file = new File([blob], "profile.png", { type: "image/png" });

    // put into input (THIS is what will be uploaded later)
    const dt = new DataTransfer();
    dt.items.add(file);
    fileInput.files = dt.files;

    // 🔥 UPDATE DROPZONE PREVIEW (IMPORTANT)
    const previewImage = document.getElementById('previewImage');
    if (cropPreviewUrl) URL.revokeObjectURL(cropPreviewUrl);
    cropPreviewUrl = URL.createObjectURL(file);
    previewImage.src = cropPreviewUrl;

    closeCrop(true);

  }, "image/png");
}

let maxPax = 0;
let currentTouristCount = 0;

const modal = document.getElementById('af-feedback-modal');
const closeBtn = document.getElementById('af-feedback-close');
const closeViewBtn = document.getElementById('close-view-btn');
const cancelBtn = document.getElementById('cancel-review-btn');
const submitBtn = document.getElementById('submit-review-btn');
const viewActions = document.getElementById('af-actions-view');
const editActions = document.getElementById('af-actions-edit');
const feedbackTitle = document.getElementById('af-feedback-title');

function openModal() {
  if (modal) modal.style.display = 'flex';
}

function setFeedbackReadOnly(readOnly) {
  const commentBox = document.getElementById('af-feedback-comment');
  if (commentBox) commentBox.readOnly = readOnly;
  document.querySelectorAll('input[name="star-radio"]').forEach(input => {
    input.disabled = readOnly;
  });
}

function setFeedbackMode(isViewOnly) {
  if (feedbackTitle) feedbackTitle.textContent = isViewOnly ? 'Your Review' : 'Give Your Review';
  if (viewActions) viewActions.style.display = isViewOnly ? 'flex' : 'none';
  if (editActions) editActions.style.display = isViewOnly ? 'none' : 'flex';
  setFeedbackReadOnly(isViewOnly);
}

function clearFeedbackForm() {
  const commentBox = document.getElementById('af-feedback-comment');
  if (commentBox) commentBox.value = '';
  document.querySelectorAll('input[name="star-radio"]').forEach(r => r.checked = false);
}

function closeModal() {
  if (!modal) return;
  modal.style.display = 'none';
  clearFeedbackForm();
  setFeedbackMode(false);
}

// ===================== OPEN REVIEW =====================
document.addEventListener('click', async (e) => {
  const btn = e.target.closest('.btn-review-user');
  if (!btn) return;

  const type = btn.dataset.type || '';
  const title = btn.dataset.title || '';
  const bookingId = btn.getAttribute('data-booking-id') || '';

  const isViewOnly = btn.dataset.view === "1";

  if (!bookingId) {
    alert("Missing booking ID");
    return;
  }

  const typeLabel = document.getElementById('af-feedback-type-label');
  const titleLabel = document.getElementById('af-feedback-target-title');
  const typeInput = document.getElementById('af-booking-type');
  const idInput = document.getElementById('af-booking-id');
  const commentBox = document.getElementById('af-feedback-comment');

  if (typeLabel) {
    typeLabel.textContent =
      type === 'package' ? 'Package' :
      type === 'boat' ? 'Boat Service' :
      type === 'tourguide' ? 'Tour Guide Service' : 'Service';
  }

  if (titleLabel) titleLabel.textContent = title;
  if (typeInput) typeInput.value = type;
  if (idInput) idInput.value = bookingId;

  // ===================== VIEW MODE =====================
  if (isViewOnly) {
    setFeedbackMode(true);
    clearFeedbackForm();
    try {
      const res = await fetch(`get_review.php?booking_id=${encodeURIComponent(bookingId)}&type=${encodeURIComponent(type)}`);
      const data = await res.json();

      if (!data.success || !data.data) {
        alert(data.message || 'No review found');
        return;
      }

      if (commentBox) commentBox.value = data.data.comment || '';
      if (data.data.rating) {
        const star = document.querySelector(`input[name="star-radio"][value="${Math.round(data.data.rating)}"]`);
        if (star) star.checked = true;
      }
    } catch (err) {
      console.error(err);
      alert('Unable to load review.');
      return;
    }
  } else {
    setFeedbackMode(false);
    clearFeedbackForm();
  }

  openModal();
});

// ===================== CLOSE =====================
if (closeBtn) closeBtn.addEventListener('click', closeModal);
if (closeViewBtn) closeViewBtn.addEventListener('click', closeModal);
if (cancelBtn) cancelBtn.addEventListener('click', closeModal);

if (modal) {
  modal.addEventListener('click', (e) => {
    if (e.target === modal) closeModal();
  });
}

// ===================== SUBMIT =====================
if (submitBtn) {
  submitBtn.addEventListener('click', async () => {

    const bookingId = document.getElementById('af-booking-id')?.value?.trim();
    const type = document.getElementById('af-booking-type')?.value?.trim();
    const comment = document.getElementById('af-feedback-comment')?.value?.trim();
    const rating = document.querySelector('input[name="star-radio"]:checked')?.value;

    if (!bookingId || !type || !rating || !comment) {
      alert("Missing fields");
      return;
    }

    try {
      const res = await fetch('submit_review.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({
          csrf_token: <?= json_encode($touristEngagementCsrf) ?>,
          booking_id: bookingId,
          type,
          rating,
          comment
        })
      });

      const text = await res.text();
      const data = JSON.parse(text);

      if (data.success) {
        Swal.fire({
          icon: 'success',
          title: 'Review submitted successfully!',
          text: data.message || 'Thank you for your feedback.',
          confirmButtonColor: '#2e7d66'
        }).then(() => {
          closeModal();
          location.reload();
        });
      } else {
        alert(data.message || "Unable to submit review.");
      }

    } catch (err) {
      console.error(err);
      alert("Something went wrong.");
    }
  });
}
function keepMobileProfileTabVisible(link, behavior = 'smooth') {
  if (!link?.closest('.mobile-profile-nav')) return;
  const track = link.closest('.mobile-profile-nav-scroll');
  if (!track) return;
  const trackRect = track.getBoundingClientRect();
  const linkRect = link.getBoundingClientRect();
  const targetLeft = track.scrollLeft + linkRect.left - trackRect.left - 2;
  track.scrollTo({ left: Math.max(0, targetLeft), behavior });
}

document.querySelectorAll('.nav-links a, .mobile-profile-nav a').forEach(a=>{
  a.addEventListener('click', e=>{
    e.preventDefault();
    const sec = a.getAttribute('data-section');
    document.documentElement.dataset.profileTab = sec;
    localStorage.setItem('activeTab', sec);
    sessionStorage.setItem('profileActiveTab', sec);
    const tabUrl = new URL(window.location.href);
    tabUrl.searchParams.set('section', sec);
    window.history.replaceState({ section: sec }, '', `${tabUrl.pathname}${tabUrl.search}${tabUrl.hash}`);
    document.querySelectorAll('.nav-links a, .mobile-profile-nav a').forEach(x=>{
      x.classList.toggle('active', x.getAttribute('data-section') === sec);
    });
    keepMobileProfileTabVisible(a);

    document.querySelectorAll('.section').forEach(s=>{
      s.style.display='none';
    });

    document.getElementById(sec).style.display = 'block';
    // If profile tab, also show upcoming bookings
    if(sec === 'profile'){
      document.getElementById('upcoming-bookings').style.display = 'block';
    } else {
      document.getElementById('upcoming-bookings').style.display = 'none';
    }

    document.querySelector('.main')?.scrollTo({top:0,behavior:'smooth'});
  });
});

const bookingCancellationCsrf = <?= json_encode($bookingCancellationCsrf, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
const providerDecisionButtons = document.querySelectorAll('.provider-decision-legacy-disabled');
const escapeProviderDecision = value => String(value ?? '').replace(/[&<>"']/g, character => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[character]));
const providerDecisionMoney = value => `₱${Number(value || 0).toLocaleString('en-PH', {minimumFractionDigits:2, maximumFractionDigits:2})}`;

providerDecisionButtons.forEach(button => button.addEventListener('click', async () => {
  let request = {};
  try { request = JSON.parse(button.dataset.request || '{}'); } catch (_) { return; }
  const reference = request.booking_reference || `Booking #${request.booking_id || ''}`;
  const choice = await Swal.fire({
    title: 'Respond to Cancellation',
    html: `<div style="padding:10px 12px;margin-bottom:14px;border-radius:9px;background:#fff8e8;color:#72531c;text-align:left;font-size:12px;line-height:1.55"><strong>${escapeProviderDecision(reference)}</strong><br>The original schedule on ${escapeProviderDecision(request.original_service_date)} will no longer proceed.<br><strong>Reason:</strong> ${escapeProviderDecision(request.cancellation_reason)}<br><strong>Respond by:</strong> ${escapeProviderDecision(new Date(String(request.decision_deadline).replace(' ', 'T')).toLocaleString('en-PH'))}</div>
      <label style="display:flex;gap:11px;padding:13px;margin:8px 0;border:1px solid #cfe4dc;border-radius:10px;text-align:left;cursor:pointer"><input type="radio" name="providerDecision" value="reschedule" style="margin-top:4px"><span><strong style="color:#176b58">Reschedule Booking</strong><small style="display:block;margin-top:4px;color:#70817a">Choose only a new date. All booking and payment details stay locked.</small></span></label>
      <label style="display:flex;gap:11px;padding:13px;margin:8px 0;border:1px solid #f0d1d4;border-radius:10px;text-align:left;cursor:pointer"><input type="radio" name="providerDecision" value="full_refund" style="margin-top:4px"><span><strong style="color:#b02333">Cancel &amp; Get Full Refund</strong><small style="display:block;margin-top:4px;color:#70817a">Cancel now and refund ${escapeProviderDecision(providerDecisionMoney(request.amount_paid))}, the amount paid.</small></span></label>`,
    showCancelButton:true, confirmButtonText:'Continue', cancelButtonText:'Decide Later', confirmButtonColor:'#176b58', cancelButtonColor:'#71817b', reverseButtons:true,
    preConfirm:()=>{const selected=document.querySelector('input[name="providerDecision"]:checked');if(!selected){Swal.showValidationMessage('Choose how you want to proceed.');return false;}return selected.value;}
  });
  if (!choice.isConfirmed) return;

  if (choice.value === 'full_refund') {
    const confirmation = await Swal.fire({icon:'warning',title:'Cancel and request full refund?',html:`<p style="color:#5e7069;line-height:1.6">This permanently cancels <strong>${escapeProviderDecision(reference)}</strong>. A refund of <strong>${escapeProviderDecision(providerDecisionMoney(request.amount_paid))}</strong> will be submitted for processing.</p>`,showCancelButton:true,confirmButtonText:'Yes, Cancel & Refund',cancelButtonText:'Go Back',confirmButtonColor:'#c62838',reverseButtons:true});
    if (!confirmation.isConfirmed) return;
    await submitProviderDecision(button, request, 'full_refund', '');
    return;
  }

  const pax = Number(request.pax || (Number(request.num_adults || 0) + Number(request.num_children || 0)) || 0);
  const remaining = Number(request.remaining_balance || Math.max(0, Number(request.total_amount || 0) - Number(request.amount_paid || 0)));
  const selection = request.preferred_resource || request.boat_name || request.tourguide_name || (request.booking_type === 'Package' || request.booking_type === 'package' ? request.service_name : 'As originally selected');
  const tomorrow = new Date(); tomorrow.setDate(tomorrow.getDate() + 1);
  const minimumDate = `${tomorrow.getFullYear()}-${String(tomorrow.getMonth()+1).padStart(2,'0')}-${String(tomorrow.getDate()).padStart(2,'0')}`;
  const dateResult = await Swal.fire({
    title:'Reschedule Booking',
    width:620,
    html:`<div style="display:grid;grid-template-columns:1fr 1fr;gap:9px;text-align:left;font-size:12px">
      <div style="padding:10px;background:#f3f8f6;border-radius:8px"><small>BOOKING ID</small><strong style="display:block;margin-top:4px;color:#174f40">${escapeProviderDecision(reference)}</strong></div>
      <div style="padding:10px;background:#f3f8f6;border-radius:8px"><small>PACKAGE / SERVICE</small><strong style="display:block;margin-top:4px;color:#174f40">${escapeProviderDecision(request.service_name)}</strong></div>
      <div style="padding:10px;background:#f3f8f6;border-radius:8px"><small>ORIGINAL DATE</small><strong style="display:block;margin-top:4px;color:#174f40">${escapeProviderDecision(request.original_service_date)}</strong></div>
      <div style="padding:10px;background:#f3f8f6;border-radius:8px"><small>TOURISTS / PAX</small><strong style="display:block;margin-top:4px;color:#174f40">${pax}</strong></div>
      <div style="padding:10px;background:#f3f8f6;border-radius:8px"><small>SELECTED SERVICE</small><strong style="display:block;margin-top:4px;color:#174f40">${escapeProviderDecision(selection)}</strong></div>
      <div style="padding:10px;background:#f3f8f6;border-radius:8px"><small>PAYMENT</small><strong style="display:block;margin-top:4px;color:#174f40">${escapeProviderDecision(providerDecisionMoney(request.amount_paid))} paid · ${escapeProviderDecision(providerDecisionMoney(remaining))} remaining</strong></div>
      </div><label for="providerNewDate" style="display:block;margin-top:17px;text-align:left;color:#174f40;font-size:13px;font-weight:800">Choose a new booking date</label><input id="providerNewDate" type="date" min="${minimumDate}" class="swal2-input" style="display:block;width:100%;margin:7px 0"><small style="display:block;text-align:left;color:#72827c;line-height:1.5">Only the date can be changed. Availability and existing booking conflict rules will be checked before saving.</small>`,
    showCancelButton:true,confirmButtonText:'Review New Date',cancelButtonText:'Back',confirmButtonColor:'#176b58',reverseButtons:true,
    preConfirm:()=>{const date=document.getElementById('providerNewDate')?.value||'';if(!date||date===request.original_service_date){Swal.showValidationMessage('Choose a valid new date.');return false;}return date;}
  });
  if (!dateResult.isConfirmed) return;
  const confirmation = await Swal.fire({icon:'question',title:'Confirm reschedule',html:`<div style="font-size:17px;font-weight:800;color:#174f40">${escapeProviderDecision(request.original_service_date)} &nbsp;→&nbsp; ${escapeProviderDecision(dateResult.value)}</div><p style="color:#64766f;line-height:1.55">The same booking ID, guests, service, and existing payment will be kept.</p>`,showCancelButton:true,confirmButtonText:'Confirm New Date',cancelButtonText:'Go Back',confirmButtonColor:'#176b58',reverseButtons:true});
  if (!confirmation.isConfirmed) return;
  await submitProviderDecision(button, request, 'reschedule', dateResult.value);
}));

async function submitProviderDecision(button, request, decision, newDate) {
  button.disabled = true;
  Swal.fire({title:'Saving your decision',text:'Please do not close this page.',allowOutsideClick:false,didOpen:()=>Swal.showLoading()});
  try {
    const response = await fetch('respond_provider_cancellation.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded','Accept':'application/json'},body:new URLSearchParams({csrf_token:bookingCancellationCsrf,cancellation_request_id:String(request.cancellation_request_id||''),decision,new_date:newDate})});
    const payload = await response.json();
    if (!response.ok || !payload.success) throw new Error(payload.message || 'Your decision could not be saved.');
    await Swal.fire({icon:'success',title:decision==='reschedule'?'Booking Rescheduled':'Cancellation Confirmed',text:payload.message,confirmButtonColor:'#176b58'});
    window.location.href = decision === 'reschedule' ? 'profile.php?section=bookings' : 'profile.php?section=cancel-bookings';
  } catch (error) {
    button.disabled = false;
    await Swal.fire({icon:'error',title:'Unable to Save Decision',text:error.message||'Please try again.',confirmButtonColor:'#176b58'});
  }
}

const focusedProviderRequest = new URLSearchParams(window.location.search).get('cancellation_request');
if (focusedProviderRequest) {
  const focusedButton = document.querySelector(`#cancellation-${CSS.escape(focusedProviderRequest)} .respond-provider-cancellation`);
  if (focusedButton) setTimeout(()=>{document.getElementById(`cancellation-${focusedProviderRequest}`)?.scrollIntoView({behavior:'smooth',block:'center'});},250);
}

const profileDecisionModal = document.getElementById('profileDecisionModal');
const profileDecisionState = {request:null, step:1, sourceButton:null, picker:null, durationDays:0, originalStart:'', originalEnd:'', selectedStart:'', selectedEnd:'', unavailable:new Set(), availabilityController:null, availabilityLoading:false, availabilityReady:false, syncingPicker:false};
const profileDecisionNewDate = document.getElementById('profileDecisionNewDate');
const profileDecisionNext = document.getElementById('profileDecisionNext');
const profileDecisionBack = document.getElementById('profileDecisionBack');
const profileDecisionSubmit = document.getElementById('profileDecisionSubmit');
const profileDecisionText = (id,value)=>{const element=document.getElementById(id);if(element)element.textContent=value??'—';};
const profileDecisionChoice = ()=>profileDecisionModal?.querySelector('input[name="profileDecisionChoice"]:checked')?.value||'';

const profileDecisionDateKey = date=>`${date.getFullYear()}-${String(date.getMonth()+1).padStart(2,'0')}-${String(date.getDate()).padStart(2,'0')}`;
const profileDecisionParseDate = value=>{const match=String(value||'').match(/^(\d{4})-(\d{2})-(\d{2})$/);return match?new Date(Number(match[1]),Number(match[2])-1,Number(match[3])):null;};
const profileDecisionAddDays = (date,days)=>{const next=new Date(date.getFullYear(),date.getMonth(),date.getDate());next.setDate(next.getDate()+days);return next;};
const profileDecisionDisplayDate = value=>{const date=profileDecisionParseDate(value);return date?date.toLocaleDateString('en-PH',{year:'numeric',month:'short',day:'numeric'}):value||'-';};

function profileDecisionDurationDays(request){
  const start=profileDecisionParseDate(request.original_service_date||request.booking_date);const range=String(request.tour_range||'').trim();
  const exact=range.match(/^\d{4}-\d{2}-\d{2}\s+to\s+(\d{4}-\d{2}-\d{2})$/i);
  if(start&&exact){const end=profileDecisionParseDate(exact[1]);if(end)return Math.max(0,Math.round((end-start)/86400000));}
  const described=range.match(/(\d+)\s*days?\s+(\d+)\s*nights?/i);if(described)return Math.max(0,Number(described[2]));
  return String(request.tour_type||'').toLowerCase()==='overnight'?1:0;
}

function profileDecisionRangeUnavailable(start,end){
  if(!start||!end)return false;
  for(let date=new Date(start);date<=end;date.setDate(date.getDate()+1)){if(profileDecisionState.unavailable.has(profileDecisionDateKey(date)))return true;}
  return false;
}

function profileDecisionUpdateRangeSummary(){
  const summary=document.getElementById('profileDecisionRangeSummary');if(!summary)return;
  if(!profileDecisionState.selectedStart){summary.hidden=true;summary.textContent='';return;}
  summary.hidden=false;
  summary.textContent=profileDecisionState.durationDays>0
    ? `New fixed schedule: ${profileDecisionDisplayDate(profileDecisionState.selectedStart)} to ${profileDecisionDisplayDate(profileDecisionState.selectedEnd)} (${profileDecisionState.durationDays+1} days)`
    : `New schedule: ${profileDecisionDisplayDate(profileDecisionState.selectedStart)}`;
}

function profileDecisionClearDate(message=''){
  profileDecisionState.selectedStart='';profileDecisionState.selectedEnd='';
  if(profileDecisionState.picker&&!profileDecisionState.syncingPicker){profileDecisionState.syncingPicker=true;profileDecisionState.picker.clear(false);profileDecisionState.syncingPicker=false;}
  else if(profileDecisionNewDate)profileDecisionNewDate.value='';
  profileDecisionUpdateRangeSummary();profileDecisionText('profileDecisionDateError',message);
}

function profileDecisionInitCalendar(request){
  profileDecisionState.picker?.destroy();profileDecisionState.picker=null;profileDecisionState.durationDays=profileDecisionDurationDays(request);profileDecisionState.selectedStart='';profileDecisionState.selectedEnd='';profileDecisionState.unavailable=new Set();
  if(typeof flatpickr!=='function'){profileDecisionText('profileDecisionAvailability','Calendar could not be loaded. Refresh the page and try again.');return;}
  const isRange=profileDecisionState.durationDays>0;
  profileDecisionState.picker=flatpickr(profileDecisionNewDate,{
    mode:isRange?'range':'single',showMonths:isRange&&window.innerWidth>820?2:1,minDate:profileDecisionAddDays(new Date(),1),maxDate:profileDecisionAddDays(new Date(),730-profileDecisionState.durationDays),dateFormat:'M j, Y',disableMobile:true,appendTo:document.body,positionElement:profileDecisionNewDate,position:'auto left',monthSelectorType:'static',nextArrow:'&#8250;',prevArrow:'&#8249;',
    disable:[date=>profileDecisionState.unavailable.has(profileDecisionDateKey(date))],
    onReady:(_,__,instance)=>instance.calendarContainer.classList.add('profile-reschedule-calendar',isRange?'tour-range-calendar':'tour-single-calendar'),
    onChange:(selectedDates,_,instance)=>{
      if(profileDecisionState.syncingPicker)return;
      const start=selectedDates[0];if(!start){profileDecisionClearDate();return;}
      const end=profileDecisionAddDays(start,profileDecisionState.durationDays);
      if(profileDecisionRangeUnavailable(start,end)){profileDecisionClearDate(profileDecisionState.durationDays>0?'That date range includes an unavailable date. Choose another start date.':'That date is unavailable. Choose another date.');return;}
      profileDecisionState.selectedStart=profileDecisionDateKey(start);profileDecisionState.selectedEnd=profileDecisionDateKey(end);
      if(isRange){profileDecisionState.syncingPicker=true;instance.setDate([start,end],false);profileDecisionState.syncingPicker=false;}
      profileDecisionText('profileDecisionDateError','');profileDecisionUpdateRangeSummary();
    }
  });
  profileDecisionUpdateRangeSummary();
}

async function profileDecisionLoadAvailability(request){
  profileDecisionState.availabilityController?.abort();const controller=new AbortController();profileDecisionState.availabilityController=controller;profileDecisionState.availabilityLoading=true;profileDecisionState.availabilityReady=false;
  profileDecisionText('profileDecisionAvailability','Checking available dates...');
  const rawType=String(request.booking_type||'').toLowerCase();const type=rawType==='guide'?'tourguide':rawType;
  const today=new Date(),limit=profileDecisionAddDays(today,730),pax=Math.max(1,Number(request.pax||(Number(request.num_adults||0)+Number(request.num_children||0))||1));
  const params=new URLSearchParams({type,from:profileDecisionDateKey(today),to:profileDecisionDateKey(limit)});
  if(type==='package'){params.set('package_name',String(request.package_name||request.service_name||''));if(Number(request.operator_id||0)>0)params.set('operator_id',String(request.operator_id));params.set('guests',String(pax));}
  else if(type==='boat')params.set('resource_id',String(request.boat_id||0));
  else if(type==='tourguide')params.set('resource_id',String(request.guide_id||request.tourguide_id||0));
  try{
    const response=await fetch(`tour_resource_availability.php?${params}`,{headers:{Accept:'application/json'},signal:controller.signal});const payload=await response.json();
    if(!response.ok||!payload.success)throw new Error(payload.message||'Availability could not be loaded.');
    profileDecisionState.unavailable=new Set(payload.unavailable_dates||[]);profileDecisionState.availabilityReady=true;profileDecisionState.picker?.set('disable',[date=>profileDecisionState.unavailable.has(profileDecisionDateKey(date))]);
    profileDecisionText('profileDecisionAvailability',profileDecisionState.unavailable.size?'Booked and unavailable dates are disabled.':'All displayed dates are currently available.');
    if(profileDecisionState.selectedStart){const start=profileDecisionParseDate(profileDecisionState.selectedStart),end=profileDecisionParseDate(profileDecisionState.selectedEnd);if(profileDecisionRangeUnavailable(start,end))profileDecisionClearDate('Your selected schedule is no longer available. Choose another date.');}
  }catch(error){if(error.name!=='AbortError')profileDecisionText('profileDecisionAvailability','Availability could not be loaded. Please close this window and try again.');}
  finally{if(profileDecisionState.availabilityController===controller)profileDecisionState.availabilityLoading=false;}
}

window.addEventListener('resize',()=>{
  if(!profileDecisionState.picker||profileDecisionState.durationDays<1)return;
  const months=window.innerWidth>820?2:1;
  if(profileDecisionState.picker.config.showMonths!==months)profileDecisionState.picker.set('showMonths',months);
});

function profileDecisionShowStep(step){
  profileDecisionState.step=step;
  profileDecisionModal.querySelectorAll('.profile-decision-step').forEach(panel=>panel.classList.toggle('active',Number(panel.dataset.profileStep)===step));
  profileDecisionModal.querySelectorAll('[data-profile-progress]').forEach(item=>{const number=Number(item.dataset.profileProgress);item.classList.toggle('active',number===step);item.classList.toggle('complete',number<step);item.querySelector('span').textContent=number<step?'✓':String(number);});
  profileDecisionBack.hidden=step===1;profileDecisionNext.hidden=step===3;profileDecisionSubmit.hidden=step!==3;
  profileDecisionText('profileDecisionTitle',step===1?'Respond to Provider Cancellation':step===2?'Choose a New Booking Date':'Review Your Decision');
  profileDecisionText('profileDecisionSubtitle',step===1?'Choose how you would like us to handle this booking.':step===2?'Only your booking date can be changed.':'Confirm the details before submitting.');
  if(step===3)profileDecisionPrepareReview();
}

function profileDecisionPrepareReview(){
  const request=profileDecisionState.request||{};const choice=profileDecisionChoice();const paid=providerDecisionMoney(request.amount_paid);const newDate=profileDecisionState.selectedStart;const newEnd=profileDecisionState.selectedEnd;
  profileDecisionText('profileDecisionReviewChoice',choice==='reschedule'?'Reschedule this booking':'Cancel and receive a full refund');
  profileDecisionText('profileDecisionReviewPayment',choice==='reschedule'?`${paid} remains applied`:`${paid} submitted for refund`);
  const schedule=profileDecisionState.durationDays>0?`${profileDecisionDisplayDate(newDate)} to ${profileDecisionDisplayDate(newEnd)}`:profileDecisionDisplayDate(newDate);
  const originalSchedule=profileDecisionState.durationDays>0?`${profileDecisionDisplayDate(profileDecisionState.originalStart)} to ${profileDecisionDisplayDate(profileDecisionState.originalEnd)}`:profileDecisionDisplayDate(profileDecisionState.originalStart);
  profileDecisionText('profileDecisionReviewDates',choice==='reschedule'?`${originalSchedule} → ${schedule}`:'Original booking will be cancelled');
  const notice=document.getElementById('profileDecisionFinalNotice');
  if(choice==='reschedule'){
    notice.className='profile-decision-final-notice';notice.textContent='The same booking ID, tourists, package or service, and payment will be retained. Only the scheduled date will change.';
    profileDecisionSubmit.textContent='Confirm New Date';profileDecisionSubmit.classList.remove('refund');profileDecisionText('profileDecisionAckText','I confirm the new date and understand the original schedule will no longer proceed.');
  }else{
    notice.className='profile-decision-final-notice refund';notice.textContent=`This immediately cancels the booking and submits ${paid}, the amount actually paid, for full-refund processing.`;
    profileDecisionSubmit.textContent='Cancel & Request Refund';profileDecisionSubmit.classList.add('refund');profileDecisionText('profileDecisionAckText','I understand this cancellation decision is final and authorize the full-refund request.');
  }
}

function profileDecisionOpen(button){
  let request={};try{request=JSON.parse(button.dataset.request||'{}');}catch(_){return;}
  profileDecisionState.request=request;profileDecisionState.sourceButton=button;
  profileDecisionState.durationDays=profileDecisionDurationDays(request);
  profileDecisionState.originalStart=String(request.original_service_date||request.booking_date||'').slice(0,10);
  const originalStartDate=profileDecisionParseDate(profileDecisionState.originalStart);
  profileDecisionState.originalEnd=originalStartDate?profileDecisionDateKey(profileDecisionAddDays(originalStartDate,profileDecisionState.durationDays)):profileDecisionState.originalStart;
  profileDecisionModal.querySelectorAll('input[name="profileDecisionChoice"]').forEach(input=>input.checked=false);
  profileDecisionNewDate.value='';document.getElementById('profileDecisionAck').checked=false;document.getElementById('profileDecisionProcessing').hidden=true;profileDecisionSubmit.disabled=false;
  ['profileDecisionChoiceError','profileDecisionDateError','profileDecisionReviewError'].forEach(id=>profileDecisionText(id,''));
  const pax=Number(request.pax||(Number(request.num_adults||0)+Number(request.num_children||0))||0);
  const remaining=Number(request.remaining_balance||Math.max(0,Number(request.total_amount||0)-Number(request.amount_paid||0)));
  const selection=request.preferred_resource||request.boat_name||request.tourguide_name||request.service_name||'As originally selected';
  const originalSchedule=profileDecisionState.durationDays>0?`${profileDecisionDisplayDate(profileDecisionState.originalStart)} to ${profileDecisionDisplayDate(profileDecisionState.originalEnd)}`:profileDecisionDisplayDate(profileDecisionState.originalStart);
  profileDecisionText('profileDecisionReference',request.booking_reference||`Booking #${request.booking_id||''}`);profileDecisionText('profileDecisionService',request.service_name);profileDecisionText('profileDecisionOriginal',originalSchedule);profileDecisionText('profileDecisionReason',request.cancellation_reason);profileDecisionText('profileDecisionDeadline',new Date(String(request.decision_deadline).replace(' ','T')).toLocaleString('en-PH'));profileDecisionText('profileDecisionRefundCopy',`Cancel now and refund ${providerDecisionMoney(request.amount_paid)}, the amount successfully paid.`);profileDecisionText('profileDecisionPax',`${pax} tourist${pax===1?'':'s'}`);profileDecisionText('profileDecisionPaid',providerDecisionMoney(request.amount_paid));profileDecisionText('profileDecisionBalance',providerDecisionMoney(remaining));profileDecisionText('profileDecisionSelection',selection);
  const directReschedule=button.dataset.providerAction==='reschedule';
  if(directReschedule){const radio=profileDecisionModal.querySelector('input[value="reschedule"]');if(radio)radio.checked=true;profileDecisionShowStep(2);}else profileDecisionShowStep(1);
  profileDecisionModal.classList.add('open');profileDecisionModal.setAttribute('aria-hidden','false');document.body.style.overflow='hidden';
  profileDecisionInitCalendar(request);profileDecisionLoadAvailability(request);
  setTimeout(()=>directReschedule?profileDecisionState.picker?.open():profileDecisionModal.querySelector('input[name="profileDecisionChoice"]')?.focus(),50);
}

function profileDecisionClose(){if(!document.getElementById('profileDecisionProcessing').hidden)return;profileDecisionState.availabilityController?.abort();profileDecisionState.picker?.close();profileDecisionModal.classList.remove('open');profileDecisionModal.setAttribute('aria-hidden','true');document.body.style.overflow='';}
document.querySelectorAll('.respond-provider-cancellation').forEach(button=>button.addEventListener('click',()=>profileDecisionOpen(button)));
profileDecisionNext?.addEventListener('click',()=>{const choice=profileDecisionChoice();if(profileDecisionState.step===1){if(!choice){profileDecisionText('profileDecisionChoiceError','Choose an option before continuing.');return;}profileDecisionText('profileDecisionChoiceError','');profileDecisionShowStep(choice==='reschedule'?2:3);if(choice==='reschedule')setTimeout(()=>profileDecisionState.picker?.open(),80);return;}if(profileDecisionState.step===2){if(profileDecisionState.availabilityLoading){profileDecisionText('profileDecisionDateError','Please wait while available dates are being checked.');return;}if(!profileDecisionState.availabilityReady){profileDecisionText('profileDecisionDateError','Available dates could not be verified. Close this window and try again.');return;}if(!profileDecisionState.selectedStart||profileDecisionState.selectedStart===profileDecisionState.request.original_service_date){profileDecisionText('profileDecisionDateError','Choose a valid date different from the original schedule.');profileDecisionState.picker?.open();return;}profileDecisionText('profileDecisionDateError','');profileDecisionShowStep(3);}});
profileDecisionBack?.addEventListener('click',()=>{const choice=profileDecisionChoice();profileDecisionShowStep(profileDecisionState.step===3&&choice==='full_refund'?1:profileDecisionState.step-1);});
['profileDecisionClose','profileDecisionLater'].forEach(id=>document.getElementById(id)?.addEventListener('click',profileDecisionClose));
profileDecisionModal?.querySelectorAll('input[name="profileDecisionChoice"]').forEach(input=>input.addEventListener('change',()=>profileDecisionText('profileDecisionChoiceError','')));
document.getElementById('profileDecisionAck')?.addEventListener('change',()=>profileDecisionText('profileDecisionReviewError',''));
profileDecisionSubmit?.addEventListener('click',()=>{if(!document.getElementById('profileDecisionAck').checked){profileDecisionText('profileDecisionReviewError','Confirm that you reviewed and accept this decision.');return;}profileSubmitProviderDecision(profileDecisionState.sourceButton,profileDecisionState.request,profileDecisionChoice(),profileDecisionChoice()==='reschedule'?profileDecisionState.selectedStart:'');});
profileDecisionModal?.addEventListener('click',event=>{if(event.target===profileDecisionModal)profileDecisionClose();});
document.addEventListener('keydown',event=>{if(event.key==='Escape'&&profileDecisionModal?.classList.contains('open'))profileDecisionClose();});

async function profileSubmitProviderDecision(button,request,decision,newDate){
  document.getElementById('profileDecisionProcessing').hidden=false;profileDecisionSubmit.disabled=true;
  try{
    const response=await fetch('respond_provider_cancellation.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded','Accept':'application/json'},body:new URLSearchParams({csrf_token:bookingCancellationCsrf,cancellation_request_id:String(request.cancellation_request_id||''),decision,new_date:newDate})});
    const payload=await response.json();if(!response.ok||!payload.success)throw new Error(payload.message||'Your decision could not be saved.');
    await Swal.fire({icon:'success',title:decision==='reschedule'?'Booking Rescheduled':'Cancellation Confirmed',text:payload.message,confirmButtonColor:'#176b58'});
    window.location.href=decision==='reschedule'?'profile.php?section=bookings':'profile.php?section=cancel-bookings';
  }catch(error){document.getElementById('profileDecisionProcessing').hidden=true;profileDecisionSubmit.disabled=false;await Swal.fire({icon:'error',title:'Unable to Save Decision',text:error.message||'Please try again.',confirmButtonColor:'#176b58'});}
}
document.querySelectorAll('.cancel-booking-btn').forEach(button => {
  button.addEventListener('click', async () => {
    const reference = button.dataset.bookingReference || `Booking #${button.dataset.bookingId}`;
    const serviceDate = button.dataset.serviceDate || '-';
    const refundPolicy = button.dataset.refundPolicy || 'Refund eligibility will be reviewed.';
    const refundPolicyCode = button.dataset.refundPolicyCode || '';
    const refundAmount = Number(button.dataset.refundAmount || 0);
    const refundText = refundAmount > 0
      ? `${refundPolicy}. Estimated refundable amount: ₱${refundAmount.toLocaleString('en-PH', {minimumFractionDigits:2, maximumFractionDigits:2})}.`
      : `${refundPolicy}. No refundable amount is currently estimated.`;

    let institutions = [];
    if (refundPolicyCode === 'partial_refund') {
      Swal.fire({title:'Loading refund channels',text:'Getting the available banks and e-wallets from PayMongo...',allowOutsideClick:false,didOpen:()=>Swal.showLoading()});
      try {
        const institutionResponse = await fetch('refund_institutions.php', {headers:{'Accept':'application/json'}});
        const institutionPayload = await institutionResponse.json();
        if (!institutionResponse.ok || !institutionPayload.success || !Array.isArray(institutionPayload.institutions) || !institutionPayload.institutions.length) {
          throw new Error(institutionPayload.message || 'No refund institutions are currently available.');
        }
        institutions = institutionPayload.institutions;
      } catch (error) {
        await Swal.fire({icon:'error',title:'Refund channel unavailable',text:error.message || 'Please try again later.',confirmButtonColor:'#176b58'});
        return;
      }
    }
    const escapeCancellationField = value => String(value ?? '').replace(/[&<>"']/g, character => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[character]));
    const institutionOptions = institutions.map(item => `<option value="${escapeCancellationField(item.bic)}" data-name="${escapeCancellationField(item.name)}">${escapeCancellationField(item.name)}</option>`).join('');
    const destinationFields = refundPolicyCode === 'partial_refund' ? `
      <div style="margin-top:16px;padding-top:14px;border-top:1px solid #dce8e3;text-align:left">
        <strong style="display:block;margin-bottom:5px;color:#174f40;font-size:13px">Refund destination</strong>
        <small style="display:block;margin-bottom:10px;color:#6e7f78;line-height:1.45">Your eligible refund will be sent automatically from the iTour Mercedes PayMongo Wallet after approval.</small>
        <select id="cancelRefundInstitution" class="swal2-select" style="display:block;width:100%;margin:7px 0"><option value="">Select bank or e-wallet</option>${institutionOptions}</select>
        <input id="cancelRefundAccountName" class="swal2-input" style="width:100%;margin:7px 0" maxlength="150" placeholder="Account holder name">
        <input id="cancelRefundAccountNumber" class="swal2-input" style="width:100%;margin:7px 0" maxlength="40" autocomplete="off" placeholder="Account or mobile number">
        <small style="display:block;margin-top:7px;color:#7c3e20;line-height:1.45">Check these details carefully. The receiving institution may reject a transfer when the name or number is incorrect.</small>
      </div>` : '';

    const result = await Swal.fire({
      icon: 'warning',
      title: 'Request cancellation?',
      html: `<p style="color:#586c64;line-height:1.55">${escapeCancellationField(reference)} is scheduled for ${escapeCancellationField(serviceDate)}. ${escapeCancellationField(refundText)} The final cancellation and refund require approval.</p>
        <label for="cancelReason" style="display:block;margin-top:14px;text-align:left;color:#174f40;font-size:13px;font-weight:700">Reason for cancellation</label>
        <textarea id="cancelReason" class="swal2-textarea" maxlength="1500" style="display:block;width:100%;margin:7px 0" placeholder="Briefly explain why you need to cancel this booking..."></textarea>${destinationFields}`,
      showCancelButton: true,
      confirmButtonText: 'Submit request',
      cancelButtonText: 'Keep booking',
      confirmButtonColor: '#c62828',
      cancelButtonColor: '#71817b',
      reverseButtons: true,
      showLoaderOnConfirm: true,
      preConfirm: async () => {
        try {
          const reason = document.getElementById('cancelReason')?.value.trim() || '';
          if (!reason) throw new Error('Please enter a reason for cancellation.');
          const institutionSelect = document.getElementById('cancelRefundInstitution');
          const selectedInstitution = institutionSelect?.selectedOptions?.[0];
          const refundBic = institutionSelect?.value || '';
          const refundInstitution = selectedInstitution?.dataset?.name || '';
          const refundAccountName = document.getElementById('cancelRefundAccountName')?.value.trim() || '';
          const refundAccountNumber = document.getElementById('cancelRefundAccountNumber')?.value.trim() || '';
          if (refundPolicyCode === 'partial_refund' && (!refundBic || !refundAccountName || !refundAccountNumber)) {
            throw new Error('Complete the refund bank or e-wallet destination.');
          }
          const response = await fetch('request_booking_cancellation.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded', 'Accept': 'application/json'},
            body: new URLSearchParams({
              csrf_token: bookingCancellationCsrf,
              booking_domain: button.dataset.bookingDomain || '',
              booking_id: button.dataset.bookingId || '',
              reason,
              refund_institution: refundInstitution,
              refund_bic: refundBic,
              refund_account_name: refundAccountName,
              refund_account_number: refundAccountNumber
            })
          });
          const payload = await response.json();
          if (!response.ok || !payload.success) throw new Error(payload.message || 'The cancellation request could not be submitted.');
          return payload;
        } catch (error) {
          Swal.showValidationMessage(error.message || 'The cancellation request could not be submitted.');
          return false;
        }
      },
      allowOutsideClick: () => !Swal.isLoading()
    });

    if (!result.isConfirmed || !result.value?.success) return;
    await Swal.fire({
      icon: 'success',
      title: 'Cancellation requested',
      text: `${result.value.message} Track approval and refund progress in Cancel Bookings.`,
      confirmButtonText: 'View request',
      confirmButtonColor: '#176b58'
    });
    localStorage.setItem('activeTab', 'cancel-bookings');
    sessionStorage.setItem('profileActiveTab', 'cancel-bookings');
    window.location.href = 'profile.php?section=cancel-bookings';
  });
});

document.querySelectorAll('.view-cancellation-reason:not(.refund-destination-button)').forEach(button => {
  button.addEventListener('click', () => {
    const reference = button.dataset.bookingReference || 'Booking';
    const reason = button.dataset.cancellationReason || 'No cancellation reason was provided.';
    const adminNote = (button.dataset.adminNote || '').trim();
    const modalText = `Booking: ${reference}\n\nReason:\n${reason}`
      + (adminNote ? `\n\nAdmin note:\n${adminNote}` : '');

    Swal.fire({
      icon: 'info',
      title: 'Cancellation reason',
      text: modalText,
      confirmButtonText: 'Close',
      confirmButtonColor: '#176b58',
      customClass: {htmlContainer: 'cancellation-reason-modal-text'}
    });
  });
});

document.querySelectorAll('.refund-destination-button').forEach(button => {
  button.addEventListener('click', async event => {
    event.stopPropagation();
    Swal.fire({title:'Loading refund channels',text:'Getting the available banks and e-wallets from PayMongo...',allowOutsideClick:false,didOpen:()=>Swal.showLoading()});
    try {
      const response = await fetch('refund_institutions.php', {headers:{'Accept':'application/json'}});
      const payload = await response.json();
      if (!response.ok || !payload.success || !Array.isArray(payload.institutions) || !payload.institutions.length) throw new Error(payload.message || 'No refund institutions are currently available.');
      const safeField = value => String(value ?? '').replace(/[&<>"']/g, character => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[character]));
      const options = payload.institutions.map(item => `<option value="${safeField(item.bic)}" data-name="${safeField(item.name)}">${safeField(item.name)}</option>`).join('');
      const current = button.dataset.currentInstitution || '';
      const last4 = button.dataset.currentLast4 || '';
      const result = await Swal.fire({
        icon:'info',
        title: last4 ? 'Update refund account' : 'Add refund account',
        html:`<p style="color:#60736b;line-height:1.55;text-align:left">This verified destination is used only when PayMongo cannot partially reverse the original payment.${current ? ` Current destination: <strong>${safeField(current)} ending in ${safeField(last4)}</strong>.` : ''}</p><select id="savedRefundInstitution" class="swal2-select" style="display:block;width:100%;margin:9px 0"><option value="">Select bank or e-wallet</option>${options}</select><input id="savedRefundAccountName" class="swal2-input" style="width:100%;margin:9px 0" maxlength="150" placeholder="Account holder name"><input id="savedRefundAccountNumber" class="swal2-input" style="width:100%;margin:9px 0" maxlength="40" autocomplete="off" placeholder="Account or mobile number"><small style="display:block;color:#7c3e20;text-align:left;line-height:1.45">Entering a new destination replaces the previous encrypted account details.</small>`,
        showCancelButton:true,
        confirmButtonText:'Save refund account',
        confirmButtonColor:'#176b58',
        showLoaderOnConfirm:true,
        preConfirm:async()=>{
          try {
            const select = document.getElementById('savedRefundInstitution');
            const accountName = document.getElementById('savedRefundAccountName')?.value.trim() || '';
            const accountNumber = document.getElementById('savedRefundAccountNumber')?.value.trim() || '';
            if (!select?.value || !accountName || !accountNumber) throw new Error('Complete all refund destination fields.');
            const saveResponse = await fetch('refund_institutions.php', {method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded','Accept':'application/json'},body:new URLSearchParams({csrf_token:bookingCancellationCsrf,cancellation_request_id:button.dataset.cancellationRequestId || '',refund_institution:select.selectedOptions[0].dataset.name || '',refund_bic:select.value,refund_account_name:accountName,refund_account_number:accountNumber})});
            const savePayload = await saveResponse.json();
            if (!saveResponse.ok || !savePayload.success) throw new Error(savePayload.message || 'The refund account could not be saved.');
            return savePayload;
          } catch (error) {
            Swal.showValidationMessage(error.message || 'The refund account could not be saved.');
            return false;
          }
        },
        allowOutsideClick:()=>!Swal.isLoading()
      });
      if (result.isConfirmed) {
        await Swal.fire({icon:'success',title:'Refund account saved',text:result.value.message,confirmButtonColor:'#176b58'});
        window.location.reload();
      }
    } catch (error) {
      await Swal.fire({icon:'error',title:'Refund channel unavailable',text:error.message || 'Please try again later.',confirmButtonColor:'#176b58'});
    }
  });
});

// A browser Back/Forward return may restore this page from the back-forward
// cache without rerunning its scripts, so explicitly reset that return to the
// default profile tab. Normal reloads are left alone and keep the current tab.
window.addEventListener('pageshow', event => {
  const navigationEntry = performance.getEntriesByType?.('navigation')?.[0];
  const isHistoryTraversal = event.persisted || (navigationEntry
    ? navigationEntry.type === 'back_forward'
    : performance.navigation?.type === 2);
  if (isHistoryTraversal) {
    document.querySelector('.nav-links a[data-section="profile"]')?.click();
  }
});

document.getElementById('profile').style.display = 'block';

const editToggle = document.getElementById('toggleEditBtn');
const editArea = document.getElementById('editArea');

function setEditArea(open) {
  if (!editArea || !editToggle) return;
  editArea.style.display = open ? 'block' : 'none';
  editToggle.setAttribute('aria-expanded', String(open));
  const label = editToggle.querySelector('span');
  if (label) label.textContent = open ? 'Close settings' : 'Edit profile';
  if (open) {
    editArea.scrollIntoView({behavior: 'smooth', block: 'start'});
  }
}

editToggle?.addEventListener('click', () => {
  setEditArea(editArea.style.display !== 'block');
});

document.querySelectorAll('.close-edit-panel').forEach(button => {
  button.addEventListener('click', () => setEditArea(false));
});

const passwordModal = document.getElementById('passwordModal');
const passwordForm = document.getElementById('passwordChangeForm');
const currentPassword = document.getElementById('currentPassword');
const newPassword = document.getElementById('newPassword');
const confirmPassword = document.getElementById('confirmPassword');
const passwordStrengthBar = document.getElementById('passwordStrengthBar');
const passwordMatchMessage = document.getElementById('passwordMatchMessage');
const passwordSubmitButton = document.getElementById('passwordSubmitButton');
const passwordModalError = document.getElementById('passwordModalError');
let passwordModalTrigger = null;

const passwordEyeOpen = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M2 12s3.5-6 10-6 10 6 10 6-3.5 6-10 6S2 12 2 12Z"></path><circle cx="12" cy="12" r="3"></circle></svg>';
const passwordEyeClosed = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="m3 3 18 18"></path><path d="M10.6 6.2A10.7 10.7 0 0 1 12 6c6 0 10 6 10 6a17 17 0 0 1-2.5 3.2M6.2 6.2C3.6 8.1 2 12 2 12s3.5 6 10 6a11 11 0 0 0 3.4-.5"></path><path d="M9.9 9.9a3 3 0 0 0 4.2 4.2"></path></svg>';

function getPasswordRules() {
  const value = newPassword?.value || '';
  return {
    length: value.length >= 10,
    uppercase: /[A-Z]/.test(value),
    lowercase: /[a-z]/.test(value),
    number: /[0-9]/.test(value)
  };
}

function updatePasswordStatus() {
  const rules = getPasswordRules();
  const validRuleCount = Object.values(rules).filter(Boolean).length;
  document.querySelectorAll('[data-password-rule]').forEach(item => {
    item.classList.toggle('is-valid', Boolean(rules[item.dataset.passwordRule]));
  });
  if (passwordStrengthBar) {
    passwordStrengthBar.style.width = `${validRuleCount * 25}%`;
    passwordStrengthBar.style.background = validRuleCount < 2 ? '#c34858' : validRuleCount < 4 ? '#d69a2d' : '#21805f';
  }

  const confirmation = confirmPassword?.value || '';
  const passwordsMatch = confirmation !== '' && confirmation === (newPassword?.value || '');
  if (passwordMatchMessage) {
    passwordMatchMessage.classList.toggle('is-valid', passwordsMatch);
    passwordMatchMessage.classList.toggle('is-invalid', confirmation !== '' && !passwordsMatch);
    passwordMatchMessage.textContent = confirmation === ''
      ? 'Re-enter your new password.'
      : (passwordsMatch ? 'Passwords match.' : 'Passwords do not match.');
  }
  confirmPassword?.setCustomValidity(confirmation !== '' && !passwordsMatch ? 'Passwords do not match.' : '');
  if (passwordSubmitButton) {
    passwordSubmitButton.disabled = !currentPassword?.value || validRuleCount !== 4 || !passwordsMatch;
  }
}

function setPasswordModal(open, options = {}) {
  if (!passwordModal) return;
  if (open) {
    passwordModalTrigger = document.activeElement;
    passwordModal.classList.add('is-open');
    passwordModal.setAttribute('aria-hidden', 'false');
    document.body.classList.add('password-modal-open');
    setTimeout(() => currentPassword?.focus(), 40);
    return;
  }
  passwordModal.classList.remove('is-open');
  passwordModal.setAttribute('aria-hidden', 'true');
  document.body.classList.remove('password-modal-open');
  if (options.reset !== false) {
    passwordForm?.reset();
    passwordModalError?.setAttribute('hidden', '');
    document.querySelectorAll('[data-modal-password-toggle]').forEach(button => {
      const field = document.getElementById(button.dataset.modalPasswordToggle);
      if (field) field.type = 'password';
      button.innerHTML = passwordEyeOpen;
      button.setAttribute('aria-pressed', 'false');
    });
    updatePasswordStatus();
  }
  if (passwordModalTrigger instanceof HTMLElement) passwordModalTrigger.focus();
}

document.getElementById('openPasswordModal')?.addEventListener('click', () => setPasswordModal(true));
document.querySelectorAll('[data-close-password-modal]').forEach(button => {
  button.addEventListener('click', () => {
    setPasswordModal(false);
    if (new URLSearchParams(location.search).has('error')) {
      history.replaceState({}, document.title, 'profile.php');
    }
  });
});
passwordModal?.addEventListener('mousedown', event => {
  if (event.target === passwordModal) setPasswordModal(false);
});
document.addEventListener('keydown', event => {
  if (!passwordModal?.classList.contains('is-open')) return;
  if (event.key === 'Escape') {
    setPasswordModal(false);
    return;
  }
  if (event.key === 'Tab') {
    const focusable = [...passwordModal.querySelectorAll('button:not(:disabled), input:not(:disabled)')];
    if (!focusable.length) return;
    const first = focusable[0];
    const last = focusable[focusable.length - 1];
    if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
    if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
  }
});

document.querySelectorAll('[data-modal-password-toggle]').forEach(button => {
  button.addEventListener('click', () => {
    const field = document.getElementById(button.dataset.modalPasswordToggle);
    if (!field) return;
    const reveal = field.type === 'password';
    field.type = reveal ? 'text' : 'password';
    button.innerHTML = reveal ? passwordEyeClosed : passwordEyeOpen;
    button.setAttribute('aria-pressed', String(reveal));
    button.setAttribute('aria-label', `${reveal ? 'Hide' : 'Show'} password`);
  });
});

[currentPassword, newPassword, confirmPassword].forEach(field => field?.addEventListener('input', updatePasswordStatus));
passwordForm?.addEventListener('submit', event => {
  updatePasswordStatus();
  if (!passwordForm.checkValidity() || passwordSubmitButton?.disabled) {
    event.preventDefault();
    passwordForm.reportValidity();
  }
});

const passwordErrors = {
  wrong_old_password: 'The current password you entered is incorrect.',
  empty_password: 'Complete all three password fields before continuing.',
  weak_password: 'Use at least 10 characters with an uppercase letter, lowercase letter, and number.',
  password_mismatch: 'The new password and confirmation do not match.',
  reused_password: 'Your new password must be different from your current password.',
  invalid_password_request: 'This password request expired. Please try again.'
};
const passwordError = new URLSearchParams(location.search).get('error');
if (passwordErrors[passwordError] && passwordModalError) {
  passwordModalError.textContent = passwordErrors[passwordError];
  passwordModalError.removeAttribute('hidden');
  setPasswordModal(true);
}
updatePasswordStatus();

document.querySelectorAll('.note-btn').forEach(btn=>{
  btn.addEventListener('click', ()=>{
    document.getElementById('modalNote').textContent = btn.dataset.note;
    document.getElementById('noteModal').style.display = 'flex';
  });
});
document.getElementById('closeModal').addEventListener('click', ()=>{
  document.getElementById('noteModal').style.display = 'none';
});


/* ---------- HOTEL REVIEW MODAL ---------- */

function openReviewModal() {
  const modal = document.getElementById('reviewModal');
  modal.classList.add('active');
  modal.style.display = 'flex'; // force consistency
}

function closeReviewModal() {
  const modal = document.getElementById('reviewModal');

  modal.classList.remove('active');
  modal.style.display = 'none';

  const form = document.getElementById('reviewForm');
  form.reset();

  // 🔥 IMPORTANT: re-enable inputs after VIEW MODE
  document.querySelectorAll('#reviewForm input, #reviewForm textarea')
    .forEach(el => el.disabled = false);

  document.getElementById('reviewModalTitle').innerText = 'Give Your Review';

  document.getElementById('reviewActions').innerHTML = `
    <button type="submit" class="af-btn" id="submitBtn">Submit Review</button>
    <button type="button" class="af-btn secondary" onclick="closeReviewModal()">Cancel</button>
  `;
}

/* RESET EVERYTHING (VERY IMPORTANT FIX) */
function resetReviewModal() {

  const form = document.getElementById('reviewForm');

  form.reset();

  // restore title
  document.getElementById('reviewModalTitle').innerText = 'Give Your Review';

  // restore actions
  document.getElementById('reviewActions').innerHTML = `
    <button type="submit" class="af-btn" id="submitBtn">Submit Review</button>
    <button type="button" class="af-btn secondary" onclick="closeReviewModal()">Cancel</button>
  `;

  // clear ratings manually (IMPORTANT)
  document.querySelectorAll('#reviewForm input[type="radio"]').forEach(r => {
    r.checked = false;
  });

  // clear textarea
  const textarea = document.getElementById('review_message');
  if (textarea) textarea.value = '';
}

/* OPEN FROM BUTTON */
document.querySelectorAll('.btn-review').forEach(btn => {
  btn.addEventListener('click', () => {

    document.getElementById('review_hotel_id').value = btn.dataset.hotelId;
    document.getElementById('review_booking_id').value = btn.dataset.bookingId;

    const reviewTarget = document.getElementById('hotelReviewTarget');
    const reviewDetails = document.getElementById('hotelReviewDetails');
    if (reviewTarget) reviewTarget.textContent = btn.dataset.hotelName || 'Hotel stay';
    if (reviewDetails) {
      const room = btn.dataset.roomType || 'Room';
      const dates = btn.dataset.stayDates || '';
      reviewDetails.textContent = dates ? `${room} · ${dates}` : room;
    }

    openReviewModal();
  });
});

/* VIEW REVIEW (READ ONLY MODE) */
function viewReview(hotelId, bookingId) {

  fetch(`get_hotel_review.php?hotel_resort_id=${hotelId}&hotel_booking_id=${bookingId}`)
    .then(res => res.json())
    .then(res => {

      if (!res.success) {
        alert(res.message || "No review found");
        return;
      }

      const data = res.data;

      openReviewModal();

      document.getElementById('reviewModalTitle').innerText = 'Your Review';

      // lock inputs
      document.querySelectorAll('#reviewForm input, #reviewForm textarea')
        .forEach(el => el.disabled = true);

      document.getElementById('reviewActions').innerHTML = `
        <button type="button" class="af-btn secondary" onclick="closeReviewModal()">Close</button>
      `;

      // fill comment
      document.getElementById('review_message').value = data.review_message || '';

      // fill ratings (FIXED MAPPING)
      setRadio('rating', data.rating);
      setRadio('value_rating', data.value_rating);
      setRadio('service_rating', data.service_rating);
      setRadio('cleanliness_rating', data.cleanliness_rating);
      setRadio('facilities_rating', data.facilities_rating);
      setRadio('room_comfort_rating', data.room_comfort_rating);
    });
}


/* FIXED RADIO HELPER */
function setRadio(name, value) {
  if (!value) return;

  const el = document.querySelector(
    `input[name="${name}"][value="${Math.round(value)}"]`
  );

  if (el) el.checked = true;
}
const profileBillingState = { type: '', id: 0, data: null, completed: false, balance: 0 };
const profilePayMongoCsrf = <?= json_encode($profilePayMongoCsrf, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
const profileMoney = value => `₱${Number(value || 0).toLocaleString('en-PH', {minimumFractionDigits: 2, maximumFractionDigits: 2})}`;
const profileSafe = value => {
  const node = document.createElement('span');
  node.textContent = value === null || value === undefined || value === '' ? '-' : String(value);
  return node.innerHTML;
};
const profileCloseModal = modal => { modal?.classList.remove('show'); modal?.setAttribute('aria-hidden', 'true'); };

/* Add Billing beside every existing booking-details control. */
document.querySelectorAll('.booking-btn-user[data-booking]:not(.cancellation-details-btn)').forEach(detailsButton => {
  try {
    const booking = JSON.parse(detailsButton.dataset.booking || '{}');
    const type = booking.hotel_booking_id ? 'hotel' : 'tour';
    const id = Number(booking.hotel_booking_id || booking.booking_id || 0);
    if (!id || detailsButton.parentElement?.querySelector(`.billing-btn-user[data-billing-type="${type}"][data-billing-id="${id}"]`)) return;
    const billingButton = document.createElement('button');
    billingButton.type = 'button';
    billingButton.className = 'billing-btn-user';
    billingButton.dataset.billingType = type;
    billingButton.dataset.billingId = String(id);
    billingButton.textContent = 'Billing';
    detailsButton.insertAdjacentElement('afterend', billingButton);
  } catch (error) {}
});

function profileBillingNormalized(record) {
  const booking = record.booking || {};
  const isHotel = record.type === 'hotel';
  const expenses = Array.isArray(record.expenses) ? record.expenses : [];
  const paid = Number(isHotel ? booking.amount_paid : booking.payment_amount || 0);
  const balance = Math.max(0, Number(booking.remaining_balance || 0));
  const total = Math.max(Number((isHotel ? booking.total_amount : booking.grand_total) || 0), paid + balance);
  const expenseTotal = expenses.reduce((sum, expense) => sum + Number(isHotel ? expense.total_amount : expense.amount || 0), 0);
  const checkoutCharges = isHotel ? Number(booking.checkout_additional_charges || 0) : 0;
  const serviceAmount = Math.max(0, total - expenseTotal - checkoutCharges);
  const guest = isHotel ? (booking.guest_name || 'Tourist') : (booking.guest_name || 'Tourist');
  const service = isHotel ? (booking.service_name || booking.room_type || 'Hotel reservation') : (booking.package_name || booking.location || booking.preferred_resource || 'Tour reservation');
  const reference = booking.booking_reference || (isHotel ? booking.hotel_booking_id : booking.booking_id);
  const paymentStatus = balance <= .009 ? 'Paid' : paid > 0 ? 'Partial' : 'Unpaid';
  const terminal = isHotel
    ? ['completed', 'cancelled', 'no-show'].includes(String(booking.booking_status || '').toLowerCase())
    : String(booking.is_complete || '').toLowerCase() !== 'uncomplete';
  return {booking, isHotel, expenses, total, paid, balance, expenseTotal, checkoutCharges, serviceAmount, guest, service, reference, paymentStatus, terminal};
}

async function openProfileBilling(type, id) {
  const modal = document.getElementById('profileBillingModal');
  const body = document.getElementById('profileBillingBody');
  modal.classList.add('show');
  modal.setAttribute('aria-hidden', 'false');
  body.innerHTML = '<div class="profile-billing-loading"><span></span><p>Preparing billing statement…</p></div>';
  document.getElementById('profileBillingSubtitle').textContent = 'Loading your payment information…';
  document.getElementById('profilePayRemaining').disabled = true;

  try {
    const response = await fetch(`profile.php?billing_action=details&type=${encodeURIComponent(type)}&id=${encodeURIComponent(id)}`, {headers: {Accept: 'application/json'}});
    const record = await response.json();
    if (!response.ok || !record.success) throw new Error(record.message || 'Billing details could not be loaded.');
    const bill = profileBillingNormalized(record);
    profileBillingState.type = type;
    profileBillingState.id = Number(id);
    profileBillingState.data = record;
    profileBillingState.completed = Boolean(record.completed);
    profileBillingState.balance = bill.balance;
    const expenseRows = bill.expenses.length ? bill.expenses.map(expense => {
      const name = bill.isHotel ? (expense.expense_name || expense.expense_type) : expense.expense_type;
      const amount = bill.isHotel ? expense.total_amount : expense.amount;
      const detail = bill.isHotel ? `${expense.expense_type || 'Expense'} · ${expense.quantity || 1} × ${profileMoney(expense.unit_price)}` : (expense.note || 'Additional charge');
      return `<div class="profile-bill-line"><span>${profileSafe(name)}<small>${profileSafe(detail)}</small></span><strong>${profileMoney(amount)}</strong></div>`;
    }).join('') : '<div class="profile-bill-line"><span>No additional charges</span><strong>₱0.00</strong></div>';
    document.getElementById('profileBillingSubtitle').textContent = `${bill.guest} · ${bill.service}`;
    body.innerHTML = `
      <div class="profile-bill-top"><div class="profile-bill-ref"><small>BOOKING REFERENCE</small><strong>${profileSafe(bill.reference)}</strong></div><span class="profile-bill-status ${bill.paymentStatus.toLowerCase()}">${bill.paymentStatus}</span></div>
      <div class="profile-bill-party"><div><small>BILLED TO</small><strong>${profileSafe(bill.guest)}</strong></div><div><small>SERVICE</small><strong>${profileSafe(bill.service)}</strong></div></div>
      <h4 class="profile-bill-title">Detailed Charges</h4>
      <div class="profile-bill-lines"><div class="profile-bill-line"><span>${bill.isHotel ? 'Room accommodation' : 'Tour service amount'}</span><strong>${profileMoney(bill.serviceAmount)}</strong></div>${expenseRows}${bill.checkoutCharges > 0 ? `<div class="profile-bill-line"><span>Check-out charges</span><strong>${profileMoney(bill.checkoutCharges)}</strong></div>` : ''}<div class="profile-bill-line total"><span>Total amount due</span><strong>${profileMoney(bill.total)}</strong></div></div>
      <h4 class="profile-bill-title">Payment Summary</h4>
      <div class="profile-bill-stats"><div class="profile-bill-stat"><small>AMOUNT PAID</small><strong>${profileMoney(bill.paid)}</strong></div><div class="profile-bill-stat balance ${bill.balance <= .009 ? 'zero' : ''}"><small>CURRENT BALANCE</small><strong>${profileMoney(bill.balance)}</strong></div><div class="profile-bill-stat"><small>PAYMENT STATUS</small><strong>${bill.paymentStatus}</strong></div></div>`;
    document.getElementById('profilePayRemaining').disabled = bill.balance <= .009 || bill.terminal;
  } catch (error) {
    body.innerHTML = `<div class="profile-billing-loading"><p>${profileSafe(error.message)}</p></div>`;
  }
}

function openProfileReceipt() {
  if (!profileBillingState.data) return;
  const bill = profileBillingNormalized(profileBillingState.data);
  const paper = document.getElementById('profileReceiptPaper');
  const expenseRows = bill.expenses.length ? bill.expenses.map(expense => `<tr><td>${profileSafe(bill.isHotel ? (expense.expense_name || expense.expense_type) : expense.expense_type)}</td><td>${profileMoney(bill.isHotel ? expense.total_amount : expense.amount)}</td></tr>`).join('') : '<tr><td>No additional charges</td><td>₱0.00</td></tr>';
  paper.innerHTML = `
    <div class="receipt-brand"><div><h2>iTour Mercedes</h2><p>OFFICIAL BOOKING RECEIPT</p></div><div class="receipt-number"><small>RECEIPT REFERENCE</small><strong>${profileSafe(bill.reference)}</strong>${profileBillingState.completed ? '<span class="receipt-paid-stamp">COMPLETED</span>' : ''}</div></div>
    <div class="receipt-meta"><div><small>BILLED TO</small><strong>${profileSafe(bill.guest)}</strong></div><div><small>SERVICE</small><strong>${profileSafe(bill.service)}</strong></div><div><small>BOOKING TYPE</small><strong>${bill.isHotel ? 'Hotel reservation' : 'Tour reservation'}</strong></div><div><small>RECEIPT STATUS</small><strong>${profileBillingState.completed ? 'Final receipt' : 'Preview only'}</strong></div></div>
    <table class="receipt-table"><thead><tr><th>DESCRIPTION</th><th>AMOUNT</th></tr></thead><tbody><tr><td>${bill.isHotel ? 'Room accommodation' : 'Tour service'}</td><td>${profileMoney(bill.serviceAmount)}</td></tr>${expenseRows}${bill.checkoutCharges > 0 ? `<tr><td>Check-out charges</td><td>${profileMoney(bill.checkoutCharges)}</td></tr>` : ''}<tr class="receipt-total"><td>TOTAL</td><td>${profileMoney(bill.total)}</td></tr></tbody></table>
    <div class="receipt-summary"><div><small>AMOUNT PAID</small><strong>${profileMoney(bill.paid)}</strong></div><div><small>BALANCE</small><strong>${profileMoney(bill.balance)}</strong></div><div><small>PAYMENT STATUS</small><strong>${bill.paymentStatus}</strong></div></div>
    <div class="receipt-foot">This receipt preview reflects the current booking account. The final downloadable PDF becomes available when the booking is marked completed.</div>`;
  const download = document.getElementById('profileDownloadReceipt');
  download.disabled = !profileBillingState.completed;
  document.getElementById('profileReceiptDownloadNote').hidden = profileBillingState.completed;
  const modal = document.getElementById('profileReceiptModal');
  modal.classList.add('show'); modal.setAttribute('aria-hidden', 'false');
}

document.addEventListener('click', event => {
  const button = event.target.closest('.billing-btn-user');
  if (button) openProfileBilling(button.dataset.billingType, button.dataset.billingId);
});
document.querySelectorAll('[data-close-profile-billing]').forEach(button => button.addEventListener('click', () => profileCloseModal(document.getElementById('profileBillingModal'))));
document.querySelectorAll('[data-close-profile-receipt]').forEach(button => button.addEventListener('click', () => profileCloseModal(document.getElementById('profileReceiptModal'))));
document.querySelectorAll('[data-close-profile-payment]').forEach(button => button.addEventListener('click', () => profileCloseModal(document.getElementById('profilePaymentModal'))));
document.getElementById('profileViewReceipt')?.addEventListener('click', openProfileReceipt);
document.getElementById('profileDownloadReceipt')?.addEventListener('click', () => {
  if (!profileBillingState.completed) return;
  window.location.href = `profile.php?billing_action=download_receipt&type=${encodeURIComponent(profileBillingState.type)}&id=${encodeURIComponent(profileBillingState.id)}`;
});
document.getElementById('profilePayRemaining')?.addEventListener('click', () => {
  if (profileBillingState.balance <= 0) return;
  profileCloseModal(document.getElementById('profileBillingModal'));
  document.getElementById('profilePaymentDue').textContent = profileMoney(profileBillingState.balance);
  document.getElementById('profilePaymentAmount').value = profileBillingState.balance.toFixed(2);
  document.getElementById('profilePaymentMethod').value = 'online_payment';
  const modal = document.getElementById('profilePaymentModal'); modal.classList.add('show'); modal.setAttribute('aria-hidden', 'false');
});
document.getElementById('profileConfirmPayment')?.addEventListener('click', async () => {
  const method = document.getElementById('profilePaymentMethod').value;
  if (method !== 'online_payment') { Swal.fire('Online Payment Required', 'Tourist balances can only be paid through Online Payment.', 'warning'); return; }
  const button = document.getElementById('profileConfirmPayment');
  button.disabled = true;
  button.classList.add('profile-paymongo-loading');
  button.innerHTML = '<span class="profile-paymongo-spinner" aria-hidden="true"></span><span>Opening PayMongo&hellip;</span>';
  let redirectingToPayMongo = false;
  const form = new FormData();
  form.append('type', profileBillingState.type);
  form.append('id', String(profileBillingState.id));
  form.append('amount', profileBillingState.balance.toFixed(2));
  form.append('csrf_token', profilePayMongoCsrf);
  try {
    const response = await fetch('../payments/create-balance-checkout.php', {
      method: 'POST',
      headers: {Accept: 'application/json'},
      body: form
    });
    const text = await response.text();
    let result;
    try { result = JSON.parse(text); } catch (error) { throw new Error('The PayMongo service returned an invalid response.'); }
    if (!response.ok || !result.success || !result.checkout_url) throw new Error(result.message || 'The PayMongo payment page could not be opened.');
    const checkoutUrl = new URL(result.checkout_url);
    if (checkoutUrl.protocol !== 'https:' || (checkoutUrl.hostname !== 'checkout.paymongo.com' && !checkoutUrl.hostname.endsWith('.paymongo.com'))) {
      throw new Error('PayMongo returned an invalid checkout address.');
    }
    redirectingToPayMongo = true;
    window.location.assign(checkoutUrl.href);
  } catch (error) { Swal.fire('Payment Failed', error.message, 'error'); }
  finally {
    if (!redirectingToPayMongo) {
      button.disabled = false;
      button.classList.remove('profile-paymongo-loading');
      button.textContent = 'Pay Online';
    }
  }
});

async function handleProfilePayMongoReturn() {
  const params = new URLSearchParams(window.location.search);
  const result = params.get('payment_return');
  const token = params.get('payment_return_token') || '';
  if (!['token', 'cancelled'].includes(result || '') || !/^[a-f0-9]{64}$/.test(token)) return;

  // Remove the one-time result from the address so refreshing cannot replay
  // the alert, while keeping the requested profile section intact.
  params.delete('payment_return');
  params.delete('payment_return_token');
  const cleanQuery = params.toString();
  history.replaceState({}, '', `${window.location.pathname}${cleanQuery ? `?${cleanQuery}` : ''}${window.location.hash}`);

  if (result === 'cancelled') {
    await Swal.fire('Payment Cancelled', 'The PayMongo payment was cancelled. No payment was recorded.', 'error');
    return;
  }
  try {
    const response = await fetch(`profile.php?billing_action=payment_status&token=${encodeURIComponent(token)}`, {headers: {Accept: 'application/json'}});
    const payment = await response.json();
    if (!response.ok || !payment.success) throw new Error(payment.message || 'The payment status could not be verified.');
    if (payment.status === 'paid') {
      await Swal.fire({
        icon: 'success',
        title: 'Payment Successful',
        text: `Booking ${payment.booking_reference} was paid successfully.`,
        confirmButtonColor: '#2e7d66'
      });
      window.location.reload();
      return;
    }
    await Swal.fire('Verification Pending', 'PayMongo has not confirmed the payment yet. Your balance will update only after verification.', 'info');
  } catch (error) {
    await Swal.fire('Payment Verification Failed', error.message, 'error');
  }
}
handleProfilePayMongoReturn();
['profileBillingModal','profileReceiptModal','profilePaymentModal'].forEach(id => document.getElementById(id)?.addEventListener('mousedown', event => { if (event.target === event.currentTarget) profileCloseModal(event.currentTarget); }));

// Open modal with booking details
document.querySelectorAll('.btn-details-user.booking-btn-user').forEach(btn => {
    btn.addEventListener('click', function() {
        const booking = JSON.parse(this.getAttribute('data-booking'));
        openBookingDetailsModalUser(booking);
    });
});

function openBookingDetailsModalUser(booking) {
    const modal = document.getElementById('bookingDetailsModalUser');
    const content = document.getElementById('bookingDetailsContentUser');

    if (!modal || !content) return;

    const safe = value => {
      const node = document.createElement('span');
      node.textContent = value === null || value === undefined || value === '' ? '-' : String(value);
      return node.innerHTML;
    };
    const readableDate = value => {
      if (!value || value === '-') return '-';
      const parsed = new Date(`${String(value).slice(0, 10)}T00:00:00`);
      return Number.isNaN(parsed.getTime())
        ? value
        : parsed.toLocaleDateString('en-PH', {month: 'long', day: 'numeric', year: 'numeric'});
    };
    const isHotel = Boolean(booking.hotel_booking_id);
    const adults = Number(isHotel ? booking.adults : (booking.num_adults || 0));
    const children = Number(isHotel ? booking.children : (booking.num_children || 0));
    const totalPax = Number(booking.pax || (adults + children));
    const status = String((isHotel ? booking.booking_status : booking.status) || 'pending').toLowerCase();
    const referenceId = isHotel ? booking.hotel_booking_id : booking.booking_id;
    const reference = booking.booking_reference || `Booking #${referenceId || '-'}`;
    const schedule = isHotel ? booking.checkin_date : (booking.booking_date || booking.created_at || '-');
    const parseDateOnly = value => {
      const match = String(value || '').slice(0, 10).match(/^(\d{4})-(\d{2})-(\d{2})$/);
      return match ? new Date(Number(match[1]), Number(match[2]) - 1, Number(match[3])) : null;
    };
    const addDateDays = (date, days) => {
      const next = new Date(date.getFullYear(), date.getMonth(), date.getDate());
      next.setDate(next.getDate() + days);
      return next;
    };
    const dateOnlyKey = date => `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
    const dateDifference = (startValue, endValue) => {
      const startDate = parseDateOnly(startValue), endDate = parseDateOnly(endValue);
      return startDate && endDate ? Math.max(0, Math.round((endDate - startDate) / 86400000)) : 0;
    };
    const tourStart = String(booking.booking_date || '').slice(0, 10);
    let tourEnd = tourStart;
    const storedTourRange = String(booking.tour_range || '').trim();
    const explicitRange = storedTourRange.match(/^\d{4}-\d{2}-\d{2}\s+to\s+(\d{4}-\d{2}-\d{2})$/i);
    const describedRange = storedTourRange.match(/(\d+)\s*days?\s+(\d+)\s*nights?/i);
    if (explicitRange) {
      tourEnd = explicitRange[1];
    } else if (parseDateOnly(tourStart) && describedRange) {
      tourEnd = dateOnlyKey(addDateDays(parseDateOnly(tourStart), Math.max(0, Number(describedRange[2]))));
    } else if (parseDateOnly(tourStart) && String(booking.tour_type || '').toLowerCase() === 'overnight') {
      tourEnd = dateOnlyKey(addDateDays(parseDateOnly(tourStart), 1));
    }
    const tourNights = dateDifference(tourStart, tourEnd);
    const hotelNights = dateDifference(booking.checkin_date, booking.checkout_date);
    const service = isHotel
      ? (booking.hotel_name || 'Hotel reservation')
      : (booking.package_name || booking.preferred_resource || booking.location || '-');
    const destination = isHotel
      ? (booking.room_type || '-')
      : (booking.location || booking.package_name || '-');
    const tripType = isHotel ? 'Hotel room' : (booking.booking_type || '-');
    const destinationLabel = isHotel ? 'Room type' : 'Destination';
    const scheduleLabel = isHotel ? 'Stay schedule' : 'Tour schedule';
    const scheduleValue = isHotel
      ? `${readableDate(booking.checkin_date)} – ${readableDate(booking.checkout_date)}`
      : (tourNights > 0 ? `${readableDate(tourStart)} – ${readableDate(tourEnd)}` : readableDate(schedule));
    const durationLabel = isHotel ? 'Length of stay' : 'Trip duration';
    const durationValue = isHotel
      ? `${hotelNights} night${hotelNights === 1 ? '' : 's'}`
      : (tourNights > 0 ? `${tourNights + 1} days · ${tourNights} night${tourNights === 1 ? '' : 's'}` : '1 day');
    const arrangementValue = String(booking.tour_type || '').toLowerCase() === 'overnight' || tourNights > 0
      ? 'Overnight / multi-day tour'
      : 'Day tour';
    const phone = booking.phone_number || booking.t_phone_number || '-';
    const address = booking.t_address || '-';
    const grandTotal = Number(isHotel ? booking.total_amount : (booking.grand_total || 0));
    const totalPaid = Number(isHotel ? booking.amount_paid : (booking.payment_amount || 0));
    const existingBalance = Number(booking.remaining_balance || Math.max(grandTotal - totalPaid, 0));
    const paidFlag = ['1', 'true', 'paid', 'yes'].includes(String(booking.is_paid || '').toLowerCase());
    const storedHotelPaymentStatus = String(booking.payment_status || '').toLowerCase();
    const paymentState = isHotel && ['paid', 'partial', 'unpaid'].includes(storedHotelPaymentStatus)
      ? storedHotelPaymentStatus.charAt(0).toUpperCase() + storedHotelPaymentStatus.slice(1)
      : (paidFlag || (grandTotal > 0 && existingBalance <= 0.009) ? 'Paid' : (totalPaid > 0 ? 'Partial' : 'Unpaid'));
    const paymentStateClass = paymentState.toLowerCase();
    const paymentMethod = isHotel
      ? `${String(booking.payment_type || 'full').replace(/[_-]+/g, ' ')} payment`
      : (booking.payment_method || 'Not specified');
    const paymentMethodLabel = isHotel ? 'Payment plan' : 'Payment method';
    const hotelGuestName = `${booking.first_name || ''} ${booking.last_name || ''}`.trim();
    const guestName = hotelGuestName || booking.t_full_name || 'Tourist';
    const guestEmail = booking.email || booking.t_email || '-';
    const initials = guestName.split(/\s+/).filter(Boolean).slice(0, 2).map(part => part[0]).join('').toUpperCase() || 'T';
    const profilePicture = (() => {
      const picture = String(booking.t_profile_picture || booking.profile_picture || '').trim().replace(/\\/g, '/');
      if (!picture) return '';
      if (/^https?:\/\//i.test(picture) || /^blob:/i.test(picture) || /^data:image\//i.test(picture)) return picture;
      if (/^[a-z][a-z0-9+.-]*:/i.test(picture)) return '';
      if (picture.startsWith('../') || picture.startsWith('./')) return picture;
      return `../${picture.replace(/^\/+/, '')}`;
    })();
    const guestAvatar = profilePicture
      ? `<img src="${safe(profilePicture)}" alt="${safe(guestName)} profile picture">`
      : safe(initials);
    const money = amount => `₱${Number(amount || 0).toLocaleString('en-PH', {minimumFractionDigits: 2, maximumFractionDigits: 2})}`;
    const icons = {
      person: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="7" r="4"></circle><path d="M5 22v-2a7 7 0 0 1 14 0v2"></path></svg>',
      phone: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M8 3H5a2 2 0 0 0-2 2c0 8.8 7.2 16 16 16a2 2 0 0 0 2-2v-3l-4-1-1.4 2.1a14.8 14.8 0 0 1-8.7-8.7L9 7 8 3Z"></path></svg>',
      map: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="m9 18-6 3V6l6-3 6 3 6-3v15l-6 3-6-3Z"></path><path d="M9 3v15M15 6v15"></path></svg>',
      calendar: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="5" width="18" height="16" rx="2"></rect><path d="M16 3v4M8 3v4M3 10h18"></path></svg>',
      service: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M5 3h14v18H5zM8 7h8M8 11h8M8 15h5"></path></svg>',
      wallet: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M4 6h15a2 2 0 0 1 2 2v11H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h14"></path><path d="M16 11h5v4h-5a2 2 0 0 1 0-4Z"></path></svg>',
      peso: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M6 4h7a5 5 0 0 1 0 10H6M6 8h11M6 12h11M8 4v16"></path></svg>',
      users: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="9" cy="8" r="3"></circle><path d="M3 20v-2a6 6 0 0 1 12 0v2M16 5a3 3 0 0 1 0 6M18 14a5 5 0 0 1 3 4.6V20"></path></svg>'
    };

    content.innerHTML = `
      <div class="booking-detail-hero-user">
        <div class="booking-detail-hero-top-user">
          <span class="booking-detail-label-user">Booking reference</span>
          <span class="booking-detail-status-user ${safe(status)}">${safe(status)}</span>
        </div>
        <h4 class="booking-detail-reference-user">${safe(reference)}</h4>
      </div>
      <div class="booking-primary-guest-user">
        <span class="booking-primary-avatar-user">${guestAvatar}</span>
        <div>
          <span class="booking-detail-label-user">Primary guest</span>
          <span class="booking-detail-value-user">${safe(guestName)}</span>
          <span class="booking-primary-email-user">${safe(guestEmail)}</span>
        </div>
      </div>
      <section class="booking-detail-section-user">
        <div class="booking-detail-section-heading-user">
          <span class="booking-detail-section-icon-user">${icons.person}</span>
          <div><h4>Guest information</h4><p>Contact details used for this reservation</p></div>
        </div>
        <div class="booking-detail-cards-user">
          <div class="booking-detail-card-user"><span class="booking-detail-card-icon-user">${icons.phone}</span><div><span class="booking-detail-label-user">Contact number</span><span class="booking-detail-value-user">${safe(phone)}</span></div></div>
          <div class="booking-detail-card-user"><span class="booking-detail-card-icon-user">${icons.map}</span><div><span class="booking-detail-label-user">Home address</span><span class="booking-detail-value-user">${safe(address)}</span></div></div>
        </div>
      </section>
      <section class="booking-detail-section-user">
        <div class="booking-detail-section-heading-user">
          <span class="booking-detail-section-icon-user">${icons.calendar}</span>
          <div><h4>Trip information</h4><p>Service, schedule, destination, and guests</p></div>
        </div>
        <div class="booking-detail-cards-user">
          <div class="booking-detail-card-user"><span class="booking-detail-card-icon-user">${icons.service}</span><div><span class="booking-detail-label-user">Booking type</span><span class="booking-detail-value-user">${safe(tripType)}</span></div></div>
          <div class="booking-detail-card-user"><span class="booking-detail-card-icon-user">${icons.service}</span><div><span class="booking-detail-label-user">Selected service</span><span class="booking-detail-value-user">${safe(service)}</span></div></div>
          <div class="booking-detail-card-user"><span class="booking-detail-card-icon-user">${icons.map}</span><div><span class="booking-detail-label-user">${safe(destinationLabel)}</span><span class="booking-detail-value-user">${safe(destination)}</span></div></div>
          <div class="booking-detail-card-user"><span class="booking-detail-card-icon-user">${icons.calendar}</span><div><span class="booking-detail-label-user">${safe(scheduleLabel)}</span><span class="booking-detail-value-user">${safe(scheduleValue)}</span></div></div>
          <div class="booking-detail-card-user"><span class="booking-detail-card-icon-user">${icons.calendar}</span><div><span class="booking-detail-label-user">${safe(durationLabel)}</span><span class="booking-detail-value-user">${safe(durationValue)}</span></div></div>
          ${isHotel ? '' : `<div class="booking-detail-card-user"><span class="booking-detail-card-icon-user">${icons.service}</span><div><span class="booking-detail-label-user">Tour arrangement</span><span class="booking-detail-value-user">${safe(arrangementValue)}</span></div></div>`}
          <div class="booking-detail-card-user full"><span class="booking-detail-card-icon-user">${icons.users}</span><div><span class="booking-detail-label-user">Guests</span><span class="booking-detail-value-user">${safe(totalPax)} pax · ${safe(adults)} adult${adults === 1 ? '' : 's'} · ${safe(children)} child${children === 1 ? '' : 'ren'}</span></div></div>
        </div>
      </section>
      <section class="booking-detail-section-user">
        <div class="booking-detail-section-heading-user">
          <span class="booking-detail-section-icon-user">${icons.wallet}</span>
          <div><h4>Billing information</h4><p>Payment totals and current balance</p></div>
        </div>
        <div class="booking-detail-cards-user">
          <div class="booking-detail-card-user"><span class="booking-detail-card-icon-user">${icons.peso}</span><div><span class="booking-detail-label-user">Booking total</span><span class="booking-detail-value-user booking-billing-value-user">${safe(money(grandTotal))}</span></div></div>
          <div class="booking-detail-card-user"><span class="booking-detail-card-icon-user">${icons.peso}</span><div><span class="booking-detail-label-user">Total paid</span><span class="booking-detail-value-user booking-billing-value-user">${safe(money(totalPaid))}</span></div></div>
          <div class="booking-detail-card-user"><span class="booking-detail-card-icon-user">${icons.wallet}</span><div><span class="booking-detail-label-user">Existing balance</span><span class="booking-detail-value-user booking-billing-value-user">${safe(money(existingBalance))}</span></div></div>
          <div class="booking-detail-card-user"><span class="booking-detail-card-icon-user">${icons.wallet}</span><div><span class="booking-detail-label-user">Payment status</span><span class="booking-detail-value-user"><span class="booking-payment-state-user ${safe(paymentStateClass)}">${safe(paymentState)}</span></span></div></div>
          <div class="booking-detail-card-user full"><span class="booking-detail-card-icon-user">${icons.wallet}</span><div><span class="booking-detail-label-user">${safe(paymentMethodLabel)}</span><span class="booking-detail-value-user">${safe(paymentMethod)}</span></div></div>
        </div>
      </section>`;

    const guestAvatarImage = content.querySelector('.booking-primary-avatar-user img');
    guestAvatarImage?.addEventListener('error', () => {
      guestAvatarImage.parentElement.textContent = initials;
    }, {once: true});

    modal.classList.add('show');
    modal.setAttribute('aria-hidden', 'false');
    document.body.classList.add('booking-drawer-open');
    modal.querySelector('.booking-details-modal-close-user')?.focus();
}

// Close modal
function closeBookingDetailsModalUser() {
    const modal = document.getElementById('bookingDetailsModalUser');
    if (modal) {
      modal.classList.remove('show');
      modal.setAttribute('aria-hidden', 'true');
    }
    document.body.classList.remove('booking-drawer-open');
}

document.getElementById('bookingDetailsModalUser')?.addEventListener('click', event => {
  if (event.target === event.currentTarget) closeBookingDetailsModalUser();
});
document.addEventListener('keydown', event => {
  if (event.key === 'Escape' && document.getElementById('bookingDetailsModalUser')?.classList.contains('show')) {
    closeBookingDetailsModalUser();
  }
});

let refreshProfileAfterPdfClose = false;
function openTouristProfilePdf(bookingId, refreshAfterClose = false) {
  const id = Number(bookingId || 0);
  if (!id) return;
  const modal = document.getElementById('touristProfilePdfModal');
  const frame = document.getElementById('touristProfilePdfFrame');
  const body = frame?.closest('.tourist-profile-pdf-body');
  const download = document.getElementById('touristProfilePdfDownload');
  const url = `tourist_submission_pdf.php?booking_id=${encodeURIComponent(id)}`;
  refreshProfileAfterPdfClose = Boolean(refreshAfterClose);
  body?.classList.add('is-loading');
  frame.src = url;
  download.href = url;
  modal.classList.add('show');
  modal.setAttribute('aria-hidden', 'false');
  document.body.classList.add('tourist-profile-pdf-open');
}

document.getElementById('touristProfilePdfFrame')?.addEventListener('load', event => {
  const frame = event.currentTarget;
  if (frame.getAttribute('src') !== 'about:blank') {
    frame.closest('.tourist-profile-pdf-body')?.classList.remove('is-loading');
  }
});

function closeTouristProfilePdf() {
  const modal = document.getElementById('touristProfilePdfModal');
  const frame = document.getElementById('touristProfilePdfFrame');
  modal?.classList.remove('show');
  modal?.setAttribute('aria-hidden', 'true');
  if (frame) {
    frame.closest('.tourist-profile-pdf-body')?.classList.remove('is-loading');
    frame.src = 'about:blank';
  }
  document.body.classList.remove('tourist-profile-pdf-open');
  if (refreshProfileAfterPdfClose) {
    refreshProfileAfterPdfClose = false;
    location.reload();
  }
}

document.getElementById('touristProfilePdfModal')?.addEventListener('mousedown', event => {
  if (event.target === event.currentTarget) closeTouristProfilePdf();
});
document.addEventListener('keydown', event => {
  if (event.key === 'Escape' && document.getElementById('touristProfilePdfModal')?.classList.contains('show')) {
    closeTouristProfilePdf();
  }
});

const touristModalUser = document.getElementById('touristModalUser');
const touristRowsUser = document.getElementById('touristRowsUser');
const bookingIdInput = document.getElementById('touristBookingId');
const touristAddressModal = document.getElementById('touristAddressModal');
let activeTouristAddressRow = null;

const touristHtmlValue = value => String(value ?? '')
  .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
  .replace(/"/g, '&quot;').replace(/'/g, '&#039;');

function touristAddressFromRow(row) {
  const read = part => row.querySelector(`[data-address-part="${part}"]`)?.value.trim() || '';
  return {country:read('country'),region:read('region'),province:read('province'),city:read('city'),barangay:read('barangay'),postal_code:read('postal_code'),street:read('street')};
}

function touristAddressLine(address) {
  return [address.street,address.barangay,address.city,address.province,address.region,address.postal_code,address.country]
    .map(value => String(value || '').trim()).filter(Boolean).join(', ');
}

function setTouristRowAddress(row, address) {
  Object.entries(address).forEach(([part, value]) => {
    const input = row.querySelector(`[data-address-part="${part}"]`);
    if (input) input.value = value || '';
  });
  const line = touristAddressLine(address);
  row.querySelector('input[name="address[]"]').value = line;
  row.querySelector('.address-display').textContent = line || 'Tap to enter the complete address';
  row.querySelector('.tourist-address-trigger').classList.toggle('has-address', Boolean(line));
}

function renumberTouristRows() {
  const rows = Array.from(touristRowsUser.querySelectorAll('.tourist-row-user'));
  rows.forEach((row, index) => {
    row.querySelector('.tourist-row-number').textContent = index + 1;
    row.querySelector('.tourist-row-heading strong').textContent = `Tourist ${index + 1}`;
  });
  currentTouristCount = rows.length;
  document.getElementById('touristFormCount').textContent = currentTouristCount;
  updateAddButtonState();
}

/* ---------- HELPER: create ONE tourist row ---------- */
function createTouristRowUser(tourist = {}) {
  const row = document.createElement('div');
  row.className = 'tourist-row-user';
  const country = tourist.country || (String(tourist.residence || '').toLowerCase() === 'philippines' ? 'Philippines' : '');
  const address = {country,region:tourist.region || '',province:tourist.province || '',city:tourist.city || '',barangay:tourist.barangay || '',postal_code:tourist.postal_code || '',street:tourist.street || ''};
  const structuredAddressLine = touristAddressLine(address);
  const addressLine = structuredAddressLine || tourist.address || '';
  row.innerHTML = `
    <div class="tourist-row-heading"><span class="tourist-row-number">1</span><strong>Tourist 1</strong></div>
    <div class="tourist-row-grid">
      <label class="tourist-field name"><span>Full name</span><input type="text" name="full_name[]" maxlength="180" placeholder="Passenger's complete name" required value="${touristHtmlValue(tourist.full_name)}"></label>
      <label class="tourist-field"><span>Gender (by birth)</span><select name="gender[]" required><option value="">Select gender</option><option value="male" ${String(tourist.gender).toLowerCase() === 'male' ? 'selected' : ''}>Male</option><option value="female" ${String(tourist.gender).toLowerCase() === 'female' ? 'selected' : ''}>Female</option></select></label>
      <label class="tourist-field"><span>Age</span><input type="number" name="age[]" min="0" max="120" inputmode="numeric" placeholder="Age" required value="${touristHtmlValue(tourist.age)}"></label>
      <label class="tourist-field phone"><span>Contact number</span><input type="tel" name="phone_number[]" maxlength="50" placeholder="Mobile or telephone" required value="${touristHtmlValue(tourist.phone_number)}"></label>
      <div class="tourist-field address"><span>Complete address</span><button type="button" class="tourist-address-trigger"><span class="address-display">${touristHtmlValue(addressLine || 'Tap to enter the complete address')}</span><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M12 21s7-5.2 7-12a7 7 0 1 0-14 0c0 6.8 7 12 7 12Z"></path><circle cx="12" cy="9" r="2.5"></circle></svg></button></div>
    </div>
    <input type="hidden" name="address[]" value="${touristHtmlValue(addressLine)}">
    <input type="hidden" name="country[]" data-address-part="country" value="${touristHtmlValue(address.country)}">
    <input type="hidden" name="region[]" data-address-part="region" value="${touristHtmlValue(address.region)}">
    <input type="hidden" name="province[]" data-address-part="province" value="${touristHtmlValue(address.province)}">
    <input type="hidden" name="city[]" data-address-part="city" value="${touristHtmlValue(address.city)}">
    <input type="hidden" name="barangay[]" data-address-part="barangay" value="${touristHtmlValue(address.barangay)}">
    <input type="hidden" name="postal_code[]" data-address-part="postal_code" value="${touristHtmlValue(address.postal_code)}">
    <input type="hidden" name="street[]" data-address-part="street" value="${touristHtmlValue(address.street)}">
    <button type="button" class="remove-tourist-user" aria-label="Remove tourist">&times;</button>
  `;
  row.querySelector('.tourist-address-trigger').classList.toggle('has-address', Boolean(addressLine));
  return row;
}

function closeTouristAddressModal() {
  touristAddressModal.classList.remove('show');
  touristAddressModal.setAttribute('aria-hidden', 'true');
  activeTouristAddressRow = null;
}

function addressModalField(part) {
  return document.getElementById(`touristAddress${part[0].toUpperCase()}${part.slice(1)}`);
}

const touristSearchSelects = new WeakMap();

function enhanceTouristSearchSelect(select) {
  if (touristSearchSelects.has(select)) return touristSearchSelects.get(select);
  const wrapper = document.createElement('div');
  wrapper.className = 'tourist-search-select';
  if (select.id === 'touristAddressCity' || select.id === 'touristAddressBarangay') {
    wrapper.classList.add('open-up');
  }
  const inputWrap = document.createElement('div');
  inputWrap.className = 'tourist-search-input-wrap';
  const input = document.createElement('input');
  input.type = 'text';
  input.className = 'tourist-search-input';
  input.autocomplete = 'off';
  input.setAttribute('role', 'combobox');
  input.setAttribute('aria-autocomplete', 'list');
  input.setAttribute('aria-expanded', 'false');
  const toggle = document.createElement('button');
  toggle.type = 'button';
  toggle.className = 'tourist-search-toggle';
  toggle.innerHTML = '&#9662;';
  toggle.setAttribute('aria-label', 'Show available locations');
  const menu = document.createElement('div');
  menu.className = 'tourist-search-menu';
  menu.setAttribute('role', 'listbox');
  select.parentNode.insertBefore(wrapper, select);
  inputWrap.append(input, toggle);
  wrapper.append(inputWrap, menu, select);
  let activeIndex = -1;

  const close = () => {
    wrapper.classList.remove('open');
    input.setAttribute('aria-expanded', 'false');
    activeIndex = -1;
  };
  const availableOptions = () => Array.from(select.options).filter(option => option.value);
  const render = (query = '') => {
    const normalized = query.trim().toLowerCase();
    const matches = availableOptions().filter(option => !normalized || option.textContent.toLowerCase().includes(normalized));
    menu.innerHTML = '';
    activeIndex = -1;
    if (!matches.length) {
      menu.innerHTML = '<div class="tourist-search-empty">No matching locations</div>';
      return;
    }
    matches.forEach(option => {
      const button = document.createElement('button');
      button.type = 'button';
      button.className = 'tourist-search-option';
      button.dataset.value = option.value;
      button.textContent = option.textContent;
      button.setAttribute('role', 'option');
      button.addEventListener('mousedown', event => event.preventDefault());
      button.addEventListener('click', () => {
        select.value = option.value;
        input.value = option.dataset.name || option.textContent;
        close();
        select.dispatchEvent(new Event('change', {bubbles:true}));
      });
      menu.appendChild(button);
    });
  };
  const open = () => {
    if (select.disabled) return;
    render(input.value);
    wrapper.classList.add('open');
    input.setAttribute('aria-expanded', 'true');
  };
  const refresh = () => {
    const selected = select.selectedOptions[0];
    input.disabled = select.disabled;
    toggle.disabled = select.disabled;
    wrapper.classList.toggle('disabled', select.disabled);
    input.placeholder = select.options[0]?.textContent || 'Search locations';
    input.value = selected?.value ? (selected.dataset.name || selected.textContent) : '';
    close();
  };

  input.addEventListener('focus', open);
  input.addEventListener('click', open);
  input.addEventListener('input', () => {
    render(input.value);
    wrapper.classList.add('open');
    input.setAttribute('aria-expanded', 'true');
  });
  input.addEventListener('blur', () => {
    setTimeout(() => {
      const selected = select.selectedOptions[0];
      input.value = selected?.value ? (selected.dataset.name || selected.textContent) : '';
      close();
    }, 100);
  });
  input.addEventListener('keydown', event => {
    const options = Array.from(menu.querySelectorAll('.tourist-search-option'));
    if (event.key === 'Escape') { close(); return; }
    if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
      event.preventDefault();
      if (!wrapper.classList.contains('open')) open();
      activeIndex = event.key === 'ArrowDown'
        ? Math.min(activeIndex + 1, options.length - 1)
        : Math.max(activeIndex - 1, 0);
      options.forEach((option, index) => option.classList.toggle('active', index === activeIndex));
      options[activeIndex]?.scrollIntoView({block:'nearest'});
    } else if (event.key === 'Enter' && wrapper.classList.contains('open')) {
      event.preventDefault();
      (options[activeIndex >= 0 ? activeIndex : 0])?.click();
    }
  });
  toggle.addEventListener('click', () => {
    if (wrapper.classList.contains('open')) close();
    else { input.focus(); open(); }
  });

  const api = {refresh, close};
  touristSearchSelects.set(select, api);
  refresh();
  return api;
}

function syncTouristSearchSelect(select) {
  enhanceTouristSearchSelect(select).refresh();
}

['country','region','province','city','barangay'].forEach(part => enhanceTouristSearchSelect(addressModalField(part)));
document.addEventListener('mousedown', event => {
  document.querySelectorAll('.tourist-search-select.open').forEach(wrapper => {
    if (!wrapper.contains(event.target)) wrapper.classList.remove('open');
  });
});

const touristGeoNamesCache = new Map();

async function fetchTouristGeoNames(action, parentId = 0) {
  const cacheKey = `${action}:${parentId}`;
  if (touristGeoNamesCache.has(cacheKey)) return touristGeoNamesCache.get(cacheKey);
  const query = new URLSearchParams({action, address_version:'2'});
  if (parentId) query.set('parent_id', String(parentId));
  const request = fetch(`geonames_locations.php?${query}`, {headers:{Accept:'application/json'}})
    .then(async response => {
      const result = await response.json();
      if (!response.ok || !result.success) throw new Error(result.message || 'Locations could not be loaded.');
      return Array.isArray(result.locations) ? result.locations : [];
    });
  touristGeoNamesCache.set(cacheKey, request);
  try {
    return await request;
  } catch (error) {
    touristGeoNamesCache.delete(cacheKey);
    throw error;
  }
}

function selectedTouristLocationName(select) {
  return select.selectedOptions[0]?.dataset.name || '';
}

function resetTouristLocationSelect(select, message) {
  select.innerHTML = `<option value="">${touristHtmlValue(message)}</option>`;
  select.disabled = true;
  syncTouristSearchSelect(select);
}

function fillTouristLocationSelect(select, locations, placeholder, selectedName = '') {
  select.innerHTML = `<option value="">${touristHtmlValue(placeholder)}</option>`;
  const seenNames = new Set();
  locations.forEach(location => {
    const normalizedName = String(location.name || '').trim().toLowerCase();
    if (!normalizedName || seenNames.has(normalizedName)) return;
    seenNames.add(normalizedName);
    const option = document.createElement('option');
    option.value = String(location.id);
    option.dataset.name = location.name;
    option.dataset.countryCode = location.country_code || '';
    option.dataset.featureCode = location.feature_code || '';
    option.dataset.adminCode1 = location.admin_code_1 || '';
    option.dataset.adminCode2 = location.admin_code_2 || '';
    option.dataset.adminCode3 = location.admin_code_3 || '';
    option.dataset.adminCode4 = location.admin_code_4 || '';
    option.textContent = location.name;
    select.appendChild(option);
  });
  select.disabled = false;
  if (selectedName) {
    const wanted = selectedName.trim().toLowerCase();
    const match = Array.from(select.options).find(option => (option.dataset.name || '').trim().toLowerCase() === wanted);
    if (match) select.value = match.value;
  }
  syncTouristSearchSelect(select);
}

async function loadTouristLocationChildren(parentSelect, childSelect, placeholder, selectedName = '', featureCodes = []) {
  const parentId = parentSelect.value;
  if (!parentId) {
    resetTouristLocationSelect(childSelect, placeholder);
    return [];
  }
  resetTouristLocationSelect(childSelect, 'Loading locations...');
  const allLocations = await fetchTouristGeoNames('children', parentId);
  const filtered = featureCodes.length
    ? allLocations.filter(location => featureCodes.includes(location.feature_code))
    : allLocations;
  const locations = filtered.length ? filtered : allLocations;
  if (parentSelect.value !== parentId) return [];
  fillTouristLocationSelect(childSelect, locations, locations.length ? placeholder : 'No subdivisions found', selectedName);
  if (!locations.length) childSelect.disabled = true;
  return locations;
}

async function hydrateTouristAddressDropdowns(address = {}) {
  const country = addressModalField('country');
  const region = addressModalField('region');
  const province = addressModalField('province');
  const city = addressModalField('city');
  const barangay = addressModalField('barangay');
  country.innerHTML = '<option value="">Loading countries...</option>';
  country.disabled = true;
  syncTouristSearchSelect(country);
  resetTouristLocationSelect(region, 'Select a country first');
  resetTouristLocationSelect(province, 'Select a region first');
  resetTouristLocationSelect(city, 'Select a province first');
  resetTouristLocationSelect(barangay, 'Select a city first');
  addressModalField('postalCode').value = address.postal_code || '';
  addressModalField('street').value = address.street || '';

  try {
    const countries = await fetchTouristGeoNames('countries');
    fillTouristLocationSelect(country, countries, 'Select a country', address.country || '');
    if (!country.value) return;
    await loadTouristLocationChildren(country, region, 'Select a region or state', address.region || '', ['ADM1']);
    if (!region.value) return;
    await loadTouristLocationChildren(region, province, 'Select a province', address.province || '', ['ADM2']);
    if (!province.value) return;
    await loadTouristLocationChildren(province, city, 'Select a city or municipality', address.city || '', ['ADM3']);
    if (!city.value) return;
    await loadTouristLocationChildren(city, barangay, 'Select a barangay', address.barangay || '', ['ADM4', 'PPL', 'PPLX']);
  } catch (error) {
    country.innerHTML = '<option value="">Countries could not be loaded</option>';
    country.disabled = true;
    syncTouristSearchSelect(country);
    const errorBox = document.getElementById('touristAddressError');
    errorBox.textContent = error.message;
    errorBox.hidden = false;
  }
}

async function openTouristAddressModal(row) {
  activeTouristAddressRow = row;
  const current = touristAddressFromRow(row);

  const copySelect = document.getElementById('touristAddressCopy');
  copySelect.innerHTML = '<option value="">Choose an address to copy...</option>';
  const unique = new Set();
  Array.from(touristRowsUser.querySelectorAll('.tourist-row-user')).forEach((candidate, index) => {
    if (candidate === row) return;
    const address = touristAddressFromRow(candidate);
    const line = touristAddressLine(address);
    if (!line || unique.has(line.toLowerCase())) return;
    unique.add(line.toLowerCase());
    const option = document.createElement('option');
    option.value = String(index);
    option.textContent = `Tourist ${index + 1} — ${line}`;
    option.dataset.address = JSON.stringify(address);
    copySelect.appendChild(option);
  });
  document.getElementById('touristAddressCopyWrap').hidden = copySelect.options.length <= 1;
  document.getElementById('touristAddressError').hidden = true;
  touristAddressModal.classList.add('show');
  touristAddressModal.setAttribute('aria-hidden', 'false');
  await hydrateTouristAddressDropdowns(current);
  setTimeout(() => addressModalField('country').focus(), 0);
}

touristRowsUser.addEventListener('click', event => {
  const trigger = event.target.closest('.tourist-address-trigger');
  if (trigger) openTouristAddressModal(trigger.closest('.tourist-row-user'));
});

document.getElementById('touristAddressCopy').addEventListener('change', async event => {
  const option = event.target.selectedOptions[0];
  if (!option?.dataset.address) return;
  const address = JSON.parse(option.dataset.address);
  await hydrateTouristAddressDropdowns(address);
});

addressModalField('country').addEventListener('change', async () => {
  resetTouristLocationSelect(addressModalField('province'), 'Select a region first');
  resetTouristLocationSelect(addressModalField('city'), 'Select a province first');
  resetTouristLocationSelect(addressModalField('barangay'), 'Select a city first');
  addressModalField('postalCode').value = '';
  try {
    await loadTouristLocationChildren(addressModalField('country'), addressModalField('region'), 'Select a region or state', '', ['ADM1']);
  } catch (error) {
    const errorBox = document.getElementById('touristAddressError'); errorBox.textContent = error.message; errorBox.hidden = false;
  }
});

addressModalField('region').addEventListener('change', async () => {
  resetTouristLocationSelect(addressModalField('city'), 'Select a province first');
  resetTouristLocationSelect(addressModalField('barangay'), 'Select a city first');
  addressModalField('postalCode').value = '';
  try {
    await loadTouristLocationChildren(addressModalField('region'), addressModalField('province'), 'Select a province', '', ['ADM2']);
  } catch (error) {
    const errorBox = document.getElementById('touristAddressError'); errorBox.textContent = error.message; errorBox.hidden = false;
  }
});

addressModalField('province').addEventListener('change', async () => {
  resetTouristLocationSelect(addressModalField('barangay'), 'Select a city first');
  addressModalField('postalCode').value = '';
  try {
    await loadTouristLocationChildren(addressModalField('province'), addressModalField('city'), 'Select a city or municipality', '', ['ADM3']);
  } catch (error) {
    const errorBox = document.getElementById('touristAddressError'); errorBox.textContent = error.message; errorBox.hidden = false;
  }
});

addressModalField('city').addEventListener('change', async () => {
  addressModalField('postalCode').value = '';
  try {
    await loadTouristLocationChildren(addressModalField('city'), addressModalField('barangay'), 'Select a barangay', '', ['ADM4', 'PPL', 'PPLX']);
  } catch (error) {
    const errorBox = document.getElementById('touristAddressError'); errorBox.textContent = error.message; errorBox.hidden = false;
  }
});

async function fillTouristPostalCode() {
  const postalField = addressModalField('postalCode');
  if (!addressModalField('barangay').value) {
    postalField.value = '';
    return;
  }
  const countryOption = addressModalField('country').selectedOptions[0];
  const cityName = selectedTouristLocationName(addressModalField('city'));
  const countryCode = countryOption?.dataset.countryCode || '';
  if (!countryCode || !cityName) {
    postalField.value = '';
    return;
  }
  postalField.value = 'Loading...';
  try {
    const query = new URLSearchParams({action:'postal_code', country_code:countryCode, place_name:cityName.replace(/^Municipality of\s+/i, '')});
    const response = await fetch(`geonames_locations.php?${query}`, {headers:{Accept:'application/json'}});
    const result = await response.json();
    if (!response.ok || !result.success) throw new Error(result.message || 'Postal code could not be loaded.');
    if (selectedTouristLocationName(addressModalField('city')) !== cityName) return;
    if (!result.postal_code) throw new Error('Postal code unavailable. Please enter your postal code.');
    postalField.value = result.postal_code;
  } catch (error) {
    postalField.value = '';
    postalField.readOnly = false;
    postalField.placeholder = 'Enter postal code';
    const errorBox = document.getElementById('touristAddressError');
    errorBox.textContent = 'Please enter your postal code; automatic lookup is unavailable.';
    errorBox.hidden = false;
  }
}

addressModalField('barangay').addEventListener('change', fillTouristPostalCode);

document.getElementById('saveTouristAddress').addEventListener('click', () => {
  if (!activeTouristAddressRow) return;
  const address = {
    country:selectedTouristLocationName(addressModalField('country')),
    region:selectedTouristLocationName(addressModalField('region')),
    province:selectedTouristLocationName(addressModalField('province')),
    city:selectedTouristLocationName(addressModalField('city')),
    barangay:selectedTouristLocationName(addressModalField('barangay')),
    postal_code:addressModalField('postalCode').value.trim(),
    street:addressModalField('street').value.trim()
  };
  const missing = Object.entries(address).filter(([, value]) => !value).map(([part]) => part);
  if (missing.length) {
    const error = document.getElementById('touristAddressError');
    error.textContent = `Complete the ${missing.join(', ')} field${missing.length > 1 ? 's' : ''}.`;
    error.hidden = false;
    return;
  }
  setTouristRowAddress(activeTouristAddressRow, address);
  closeTouristAddressModal();
});

document.getElementById('closeTouristAddress').addEventListener('click', closeTouristAddressModal);
document.getElementById('cancelTouristAddress').addEventListener('click', closeTouristAddressModal);
touristAddressModal.addEventListener('mousedown', event => { if (event.target === touristAddressModal) closeTouristAddressModal(); });
document.addEventListener('keydown', event => {
  if (event.key === 'Escape' && touristAddressModal.classList.contains('show')) {
    closeTouristAddressModal();
  }
});

/* ---------- OPEN MODAL ---------- */
document.querySelectorAll('.add-tourist-btn-user').forEach(btn => {
  btn.addEventListener('click', async () => {
    const bookingId = btn.dataset.bookingId;
    maxPax = parseInt(btn.dataset.pax || 0);

    bookingIdInput.value = bookingId;

    const formData = new FormData();
    formData.append('fetch_tourists', '1');
    formData.append('booking_id', bookingId);

    try {
      const res = await fetch('<?= basename(__FILE__) ?>', {
        method: 'POST',
        body: formData
      });

      const data = await res.json();

      touristRowsUser.innerHTML = '';

      currentTouristCount = (data.success && data.tourists)
        ? data.tourists.length
        : 0;

      if (data.success && data.tourists.length > 0) {
        data.tourists.forEach(t =>
          touristRowsUser.appendChild(createTouristRowUser(t))
        );
      } else {
        touristRowsUser.appendChild(createTouristRowUser());
      }

      renumberTouristRows();

      touristModalUser.style.display = 'flex';

    } catch (err) {
      console.error(err);
      alert('Failed to load existing tourists.');
    }
  });
});

/* ---------- CLOSE MODAL ---------- */
document.querySelector('.tourist-modal-close-user').onclick = () => {
  touristModalUser.style.display = 'none';
};

/* ---------- ADD MORE TOURISTS ROW ---------- */
document.getElementById('addMoreTouristUser').addEventListener('click', () => {
  if (currentTouristCount >= maxPax) {
    alert(`You can only add up to ${maxPax} tourists for this booking.`);
    return;
  }

  const addedRow = createTouristRowUser();
  touristRowsUser.appendChild(addedRow);
  renumberTouristRows();
  requestAnimationFrame(() => {
    touristRowsUser.scrollTo({top: touristRowsUser.scrollHeight, behavior: 'smooth'});
    addedRow.querySelector('input[name="full_name[]"]')?.focus({preventScroll:true});
  });
});

function updateAddButtonState() {
  const addBtn = document.getElementById('addMoreTouristUser');
  const submitBtn = document.querySelector('#touristFormUser .tourist-submit-user');

  if (currentTouristCount >= maxPax) {
    addBtn.disabled = true;
    addBtn.style.opacity = 0.5;
    addBtn.style.cursor = 'not-allowed';
  } else {
    addBtn.disabled = false;
    addBtn.style.opacity = 1;
    addBtn.style.cursor = 'pointer';
  }

  const hasRequiredPax = maxPax > 0 && currentTouristCount === maxPax;
  submitBtn.disabled = !hasRequiredPax;
  const remainingPax = Math.max(maxPax - currentTouristCount, 0);
  submitBtn.title = hasRequiredPax
    ? 'Submit the completed tourist list'
    : `Add ${remainingPax} more tourist${remainingPax === 1 ? '' : 's'} to submit`;
}

/* ---------- REMOVE TOURIST ROW ---------- */
touristRowsUser.addEventListener('click', e => {
  const removeButton = e.target.closest('.remove-tourist-user');
  if (removeButton) {
    removeButton.closest('.tourist-row-user').remove();
    renumberTouristRows();
  }
});

/* ---------- SUBMIT TOURISTS ---------- */
document.getElementById('touristFormUser').addEventListener('submit', function(e) {
  e.preventDefault();

  const rows = Array.from(touristRowsUser.querySelectorAll('.tourist-row-user'));
  if (!rows.length) {
    alert('Add at least one tourist.');
    return;
  }
  if (rows.length !== maxPax) {
    alert(`Complete all ${maxPax} tourist entries before submitting.`);
    return;
  }
  const incompleteAddressIndex = rows.findIndex(row => Object.values(touristAddressFromRow(row)).some(value => !value));
  if (incompleteAddressIndex >= 0) {
    alert(`Complete the address for Tourist ${incompleteAddressIndex + 1}.`);
    openTouristAddressModal(rows[incompleteAddressIndex]);
    return;
  }
  if (!this.checkValidity()) {
    this.reportValidity();
    return;
  }

  // Save current active tab
  const activeTab = document.querySelector('.nav-links a.active')?.getAttribute('data-section');
  if (activeTab) localStorage.setItem('activeTab', activeTab);

  const formData = new FormData(this);
  const submitBtn = this.querySelector('button[type="submit"]');
  const submittedBookingId = bookingIdInput.value;
  submitBtn.disabled = true;
  submitBtn.textContent = 'Submitting...';

  fetch('<?= basename(__FILE__) ?>', { method: 'POST', body: formData })
    .then(res => res.json())
    .then(async resData => {
      if (resData.success) {
        touristModalUser.style.display = 'none';
        const result = await Swal.fire({
          icon: 'success',
          title: 'Tourist List Submitted',
          text: resData.message || 'The passenger manifest was saved successfully.',
          showCancelButton: true,
          confirmButtonText: 'View PDF',
          cancelButtonText: 'Okay',
          confirmButtonColor: '#26745f',
          cancelButtonColor: '#71817b',
          reverseButtons: true,
          allowOutsideClick: false
        });
        if (result.isConfirmed) {
          openTouristProfilePdf(submittedBookingId, true);
        } else {
          location.reload(); // reloads, active tab will be restored
        }
      } else {
        await Swal.fire({
          icon: 'error',
          title: 'Submission Failed',
          text: resData.message || 'The tourist list could not be submitted.',
          confirmButtonText: 'Okay',
          confirmButtonColor: '#26745f'
        });
      }
    })
    .catch(async err => {
      console.error(err);
      await Swal.fire({
        icon: 'error',
        title: 'Submission Failed',
        text: 'The tourist list could not be submitted. Please try again.',
        confirmButtonText: 'Okay',
        confirmButtonColor: '#26745f'
      });
    })
    .finally(() => {
      submitBtn.textContent = 'Submit Tourists';
      updateAddButtonState();
    });
});

/* ---------- RESTORE ACTIVE TAB AFTER RELOAD ---------- */
document.addEventListener('DOMContentLoaded', () => {
  const allowedTabs = ['profile', 'bookings', 'cancel-bookings', 'favorites', 'complaints', 'history'];
  const activeTab = document.documentElement.dataset.profileTab;
  if (activeTab && allowedTabs.includes(activeTab)) {
    document.documentElement.dataset.profileTab = activeTab;
    localStorage.setItem('activeTab', activeTab);
    sessionStorage.setItem('profileActiveTab', activeTab);
    // Remove active class from all tabs
    document.querySelectorAll('.nav-links a, .mobile-profile-nav a').forEach(a => a.classList.remove('active'));
    // Set saved tab active
    document.querySelectorAll(`.nav-links a[data-section="${activeTab}"], .mobile-profile-nav a[data-section="${activeTab}"]`)
      .forEach(tabLink => {
        tabLink.classList.add('active');
        keepMobileProfileTabVisible(tabLink, 'auto');
      });

    // Hide all sections
    document.querySelectorAll('.section').forEach(s => s.style.display = 'none');
    // Show saved section
    const section = document.getElementById(activeTab);
    if (section) section.style.display = 'block';
    const upcoming = document.getElementById('upcoming-bookings');
    if (upcoming) upcoming.style.display = activeTab === 'profile' ? 'block' : 'none';
  }
});

</script>
<script>
/* Complaints and incidents dashboard filters. */
(function () {
  const reportList = document.getElementById('complaintReportList');
  if (!reportList) return;

  const cards = Array.from(reportList.querySelectorAll('.complaint-report-card'));
  const typeButtons = Array.from(document.querySelectorAll('[data-complaint-type]'));
  const statusSelect = document.getElementById('complaintStatusFilter');
  const searchInput = document.getElementById('complaintReportSearch');
  const emptyState = document.getElementById('complaintFilterEmpty');
  let activeType = 'all';

  const applyFilters = () => {
    const status = statusSelect?.value || 'all';
    const query = (searchInput?.value || '').trim().toLowerCase();
    let visible = 0;

    cards.forEach(card => {
      const typeMatches = activeType === 'all' || card.dataset.reportType === activeType;
      const statusMatches = status === 'all' || card.dataset.reportStatus === status;
      const searchMatches = query === '' || (card.dataset.reportSearch || '').includes(query);
      const show = typeMatches && statusMatches && searchMatches;
      card.hidden = !show;
      if (show) visible++;
    });

    if (emptyState) emptyState.hidden = visible !== 0;
  };

  typeButtons.forEach(button => {
    button.addEventListener('click', () => {
      activeType = button.dataset.complaintType || 'all';
      typeButtons.forEach(item => item.classList.toggle('active', item === button));
      applyFilters();
    });
  });
  statusSelect?.addEventListener('change', applyFilters);
  searchInput?.addEventListener('input', applyFilters);

  reportList.querySelectorAll('.complaint-report-details').forEach(details => {
    details.addEventListener('toggle', () => {
      if (!details.open) return;
      reportList.querySelectorAll('.complaint-report-details[open]').forEach(other => {
        if (other !== details) other.open = false;
      });
    });
  });
})();
</script>
<script>
/* Reusable controls for every booking/history table. */
(function () {
  const normalize = value => (value || '').replace(/\s+/g, ' ').trim().toLowerCase();

  document.querySelectorAll('.formal-card .formal-table').forEach((table, tableIndex) => {
    const body = table.tBodies[0];
    if (!body) return;

    const rows = Array.from(body.rows);
    if (!rows.length) return;

    const wrapper = table.closest('.table-responsive');
    const card = table.closest('.formal-card');
    const heading = card?.querySelector('.formal-card-header h4')?.textContent?.trim() || `Table ${tableIndex + 1}`;
    const headers = Array.from(table.tHead?.rows[0]?.cells || []).map(cell => normalize(cell.textContent));
    const columnClasses = headers.map(label => `table-col-${label.replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '') || 'column'}`);
    Array.from(table.tHead?.rows[0]?.cells || []).forEach((cell, index) => cell.classList.add(columnClasses[index]));
    rows.forEach(row => Array.from(row.cells).forEach((cell, index) => {
      if (columnClasses[index]) cell.classList.add(columnClasses[index]);
      const sourceHeader = table.tHead?.rows[0]?.cells[index];
      if (sourceHeader) cell.dataset.label = sourceHeader.textContent.replace(/\s+/g, ' ').trim();
    }));
    let filterColumn = headers.findIndex(label => label === 'status');
    if (filterColumn < 0) filterColumn = headers.findIndex(label => label === 'type');

    const tools = document.createElement('div');
    tools.className = 'table-tools';
    tools.innerHTML = `
      <div class="table-tools-primary">
        <label class="table-search">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="11" cy="11" r="7"></circle><path d="m20 20-3.5-3.5"></path></svg>
          <input type="search" placeholder="Search this table..." aria-label="Search ${heading}">
        </label>
        ${filterColumn >= 0 ? '<select class="table-category-filter" aria-label="Filter table"><option value="">All statuses</option></select>' : ''}
      </div>
      <div class="table-tools-secondary">
        <label class="table-rows-label">Show
          <select class="table-page-size" aria-label="Rows per page">
            <option value="5">5</option>
            <option value="10" selected>10</option>
            <option value="25">25</option>
            <option value="all">All</option>
          </select>
          rows
        </label>
        <span class="table-results" aria-live="polite"></span>
      </div>`;

    const pagination = document.createElement('div');
    pagination.className = 'table-pagination';
    pagination.innerHTML = `
      <span class="table-page-info" aria-live="polite"></span>
      <div class="table-page-actions">
        <button type="button" class="table-page-btn table-prev" aria-label="Previous page">
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3"><path d="m15 18-6-6 6-6"></path></svg>
        </button>
        <button type="button" class="table-page-btn table-next" aria-label="Next page">
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3"><path d="m9 18 6-6-6-6"></path></svg>
        </button>
      </div>`;

    wrapper.before(tools);
    wrapper.after(pagination);

    const search = tools.querySelector('input[type="search"]');
    const category = tools.querySelector('.table-category-filter');
    const pageSize = tools.querySelector('.table-page-size');
    const results = tools.querySelector('.table-results');
    const headerResults = card?.querySelector('.table-header-results');
    const pageInfo = pagination.querySelector('.table-page-info');
    const previous = pagination.querySelector('.table-prev');
    const next = pagination.querySelector('.table-next');
    let currentPage = 1;

    if (category && filterColumn >= 0) {
      const values = [...new Set(rows.map(row => row.cells[filterColumn]?.textContent?.replace(/\s+/g, ' ').trim()).filter(Boolean))];
      values.sort((a, b) => a.localeCompare(b)).forEach(value => {
        const option = document.createElement('option');
        option.value = normalize(value);
        option.textContent = value;
        category.appendChild(option);
      });
    }

    const refresh = () => {
      const query = normalize(search.value);
      const categoryValue = category?.value || '';
      const filtered = rows.filter(row => {
        const matchesSearch = !query || normalize(row.textContent).includes(query);
        const cellValue = filterColumn >= 0 ? normalize(row.cells[filterColumn]?.textContent) : '';
        return matchesSearch && (!categoryValue || cellValue === categoryValue);
      });

      const size = pageSize.value === 'all' ? Math.max(filtered.length, 1) : Number(pageSize.value);
      const totalPages = Math.max(1, Math.ceil(filtered.length / size));
      currentPage = Math.min(currentPage, totalPages);
      const start = (currentPage - 1) * size;
      const visible = new Set(filtered.slice(start, start + size));

      rows.forEach(row => { row.hidden = !visible.has(row); });
      if (window.matchMedia('(max-width: 760px)').matches) {
        body.scrollTo({ left: 0, behavior: 'auto' });
      }
      let emptyRow = body.querySelector('.table-empty-filter');
      if (!filtered.length) {
        if (!emptyRow) {
          emptyRow = body.insertRow();
          emptyRow.className = 'table-empty-filter';
          const cell = emptyRow.insertCell();
          cell.colSpan = Math.max(headers.length, 1);
          cell.textContent = 'No rows match your filters.';
        }
        emptyRow.hidden = false;
      } else if (emptyRow) {
        emptyRow.hidden = true;
      }

      const rangeStart = filtered.length ? start + 1 : 0;
      const rangeEnd = Math.min(start + size, filtered.length);
      results.textContent = `${filtered.length} result${filtered.length === 1 ? '' : 's'}`;
      if (headerResults) headerResults.textContent = `${filtered.length} result${filtered.length === 1 ? '' : 's'}`;
      pageInfo.textContent = `Showing ${rangeStart}\u2013${rangeEnd} of ${filtered.length}`;
      previous.disabled = currentPage <= 1;
      next.disabled = currentPage >= totalPages;
      pagination.hidden = pageSize.value === 'all' || filtered.length <= size;
    };

    search.addEventListener('input', () => { currentPage = 1; refresh(); });
    category?.addEventListener('change', () => { currentPage = 1; refresh(); });
    pageSize.addEventListener('change', () => { currentPage = 1; refresh(); });
    previous.addEventListener('click', () => { if (currentPage > 1) { currentPage--; refresh(); } });
    next.addEventListener('click', () => { currentPage++; refresh(); });
    refresh();
  });
})();
</script>
<?php require __DIR__ . '/complaint_modal_component.php'; ?>
<script>
window.ComplaintModalConfig = {
  loggedIn: true,
  csrfToken: <?= json_encode(complaintCsrfToken(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
  endpoint: 'submit_complaint_incident.php',
  profileUrl: 'profile.php?section=complaints',
  loginUrl: '../?open_login=1'
};
</script>
<script src="../js/complaint-modal.js?v=<?= (int)@filemtime(__DIR__ . '/../js/complaint-modal.js') ?>"></script>
<script>
window.FavoritesConfig = {
  endpoint: 'favorites_api.php',
  csrfToken: <?= json_encode($favoritesCsrf) ?>,
  loginUrl: '../login.php'
};
</script>
<script src="../js/favorites.js"></script>
<script>
(function () {
  const grid = document.getElementById('favoritesGrid');
  const empty = document.getElementById('favoritesEmpty');
  const filters = Array.from(document.querySelectorAll('[data-favorite-filter]'));
  if (!grid || !empty) return;

  function activeFilter() {
    return document.querySelector('[data-favorite-filter].active')?.dataset.favoriteFilter || 'all';
  }

  function refreshFavorites() {
    const cards = Array.from(grid.querySelectorAll('[data-favorite-card]'));
    const selected = activeFilter();
    let visible = 0;
    const counts = {all: cards.length, hotel: 0, package: 0, guide: 0, boat: 0};

    cards.forEach(card => {
      const type = card.dataset.favoriteCategory;
      if (Object.prototype.hasOwnProperty.call(counts, type)) counts[type]++;
      const show = selected === 'all' || selected === type;
      card.hidden = !show;
      if (show) visible++;
    });

    filters.forEach(button => {
      const count = counts[button.dataset.favoriteFilter] || 0;
      const badge = button.querySelector('span');
      if (badge) badge.textContent = count;
    });
    const total = document.getElementById('favoritesTotalCount');
    const navCount = document.getElementById('favoritesNavCount');
    if (total) total.textContent = counts.all;
    if (navCount) navCount.textContent = counts.all;
    empty.hidden = visible !== 0;
  }

  filters.forEach(button => {
    button.addEventListener('click', () => {
      filters.forEach(item => item.classList.remove('active'));
      button.classList.add('active');
      refreshFavorites();
    });
  });

  document.addEventListener('favorite:changed', event => {
    if (event.detail?.favorited !== false) return;
    const source = event.detail.source;
    const card = source?.closest('[data-favorite-card]');
    if (!card) return;
    card.style.opacity = '0';
    card.style.transform = 'scale(.97)';
    setTimeout(() => {
      card.remove();
      refreshFavorites();
    }, 180);
  });
})();
</script>
<script src="../js/mobile-scroll.js"></script>
</body>
</html>

