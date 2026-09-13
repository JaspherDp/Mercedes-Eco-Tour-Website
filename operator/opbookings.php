<?php
chdir(__DIR__ . '/..');
require_once __DIR__ . '/../php/session_security.php';
AppSessionStart();
require 'php/db_connection.php';
require_once __DIR__ . '/../php/activity_logger.php';
require_once __DIR__ . '/../php/operator_auth_helper.php';
require_once __DIR__ . '/../php/booking_reference_helper.php';
require_once __DIR__ . '/../php/tour_resource_availability_helper.php';
require_once __DIR__ . '/../php/firebase_config.php';
require_once __DIR__ . '/../php/input_validation.php';
require_once __DIR__ . '/../payments/PayMongoService.php';
require_once __DIR__ . '/../payments/PaymentReconciler.php';

$operatorAccount = OperatorRequireLogin($pdo);

$operator_id = (int)$_SESSION['operator_id'];
$operatorName = $_SESSION['operator_name'] ?? 'Operator';
$opProfilePicFile = trim((string)($_SESSION['operator_profile'] ?? ''));
$opHeaderProfilePic = null;
if ($opProfilePicFile !== '' && strtolower($opProfilePicFile) !== 'img/profileicon.png' && file_exists($opProfilePicFile)) {
    $opHeaderProfilePic = $opProfilePicFile;
}
$opProfileInitial = strtoupper(substr(trim((string)$operatorName) !== '' ? trim((string)$operatorName) : 'O', 0, 1));
$operatorFirebasePublicConfiguration = firebase_public_configuration();
$opWalkinCsrf = (string)($_SESSION['op_walkin_booking_csrf'] ?? '');
if ($opWalkinCsrf === '') {
    $opWalkinCsrf = bin2hex(random_bytes(24));
    $_SESSION['op_walkin_booking_csrf'] = $opWalkinCsrf;
}
$opPayMongoCsrf = (string)($_SESSION['paymongo_operator_csrf'] ?? '');
if ($opPayMongoCsrf === '') {
    $opPayMongoCsrf = bin2hex(random_bytes(32));
    $_SESSION['paymongo_operator_csrf'] = $opPayMongoCsrf;
}
$operatorBookingCsrf = AppCsrfToken('operator', 'booking_management');
$operatorNotificationCsrf = AppCsrfToken('operator', 'notifications');

if (($_GET['action'] ?? '') === 'paymongo_payment_status') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    $token = strtolower(trim((string)($_GET['token'] ?? '')));
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid payment return token.']);
        exit;
    }
    try {
        $statusStmt = $pdo->prepare(
            "SELECT pt.payment_transaction_id, pt.booking_id, pt.booking_reference, pt.amount_minor,
                    pt.status, pt.provider_checkout_session_id, pt.metadata
             FROM payment_transactions pt
             INNER JOIN bookings b ON b.booking_id = pt.booking_id
             WHERE pt.return_token = ? AND pt.booking_domain = 'package' AND b.operator_id = ?
             LIMIT 1"
        );
        $statusStmt->execute([$token, $operator_id]);
        $transaction = $statusStmt->fetch(PDO::FETCH_ASSOC);
        $metadata = $transaction ? json_decode((string)($transaction['metadata'] ?? ''), true) : null;
        if (!$transaction || !is_array($metadata)
            || ($metadata['source'] ?? '') !== 'operator_booking_payment'
            || (int)($metadata['operator_id'] ?? 0) !== $operator_id) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Operator payment transaction not found.']);
            exit;
        }
        if (strtolower((string)$transaction['status']) === 'pending'
            && preg_match('/^cs_[A-Za-z0-9]+$/', (string)$transaction['provider_checkout_session_id'])) {
            try {
                $checkout = PayMongoService::fromEnvironment()->retrieveCheckoutSession((string)$transaction['provider_checkout_session_id']);
                PaymentReconciler::reconcilePaidCheckout($pdo, is_array($checkout['data'] ?? null) ? $checkout['data'] : []);
                $refresh = $pdo->prepare('SELECT status FROM payment_transactions WHERE payment_transaction_id = ?');
                $refresh->execute([(int)$transaction['payment_transaction_id']]);
                $transaction['status'] = (string)$refresh->fetchColumn();
            } catch (UnexpectedValueException|PayMongoException $ignored) {
            }
        }
        echo json_encode([
            'success' => true,
            'status' => strtolower((string)$transaction['status']),
            'booking_id' => (int)$transaction['booking_id'],
            'booking_reference' => (string)$transaction['booking_reference'],
            'amount' => ((int)$transaction['amount_minor']) / 100,
        ]);
    } catch (Throwable $error) {
        http_response_code(503);
        echo json_encode(['success' => false, 'message' => 'Payment status is temporarily unavailable.']);
    }
    exit;
}

if (isset($_POST['op_action']) && $_POST['op_action'] === 'mark_notifications_read') {
    if (!AppVerifyCsrf('operator', 'notifications', $_POST['csrf_token'] ?? null)) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'message' => 'Invalid security token.']);
        exit;
    }
    $_SESSION['op_notifications_seen_at'] = date('Y-m-d H:i:s');
    header('Content-Type: application/json');
    echo json_encode(['ok' => true]);
    exit;
}

if (($_GET['action'] ?? '') === 'fetch_billing') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $bookingId = (int)($_GET['id'] ?? 0);
        if ($bookingId <= 0) throw new RuntimeException('Invalid booking reference.');

        $billingStmt = $pdo->prepare("
            SELECT b.booking_id, b.booking_reference, b.package_name, b.grand_total,
                   b.payment_amount, b.remaining_balance, b.payment_method, b.is_paid,
                   b.status, b.is_complete,
                   COALESCE(NULLIF(TRIM(t.full_name), ''), CONCAT('Tourist #', b.tourist_id)) AS guest_name
            FROM bookings b
            LEFT JOIN tourist t ON t.tourist_id = b.tourist_id
            WHERE b.booking_id = ? AND b.operator_id = ? AND LOWER(b.booking_type) = 'package'
            LIMIT 1
        ");
        $billingStmt->execute([$bookingId, $operator_id]);
        $billingBooking = $billingStmt->fetch(PDO::FETCH_ASSOC);
        if (!$billingBooking) throw new RuntimeException('Booking not found or access was denied.');

        $expenseStmt = $pdo->prepare("
            SELECT expense_type, amount, note, created_at
            FROM booking_expenses
            WHERE booking_id = ?
            ORDER BY created_at ASC
        ");
        $expenseStmt->execute([$bookingId]);
        echo json_encode([
            'success' => true,
            'booking' => $billingBooking,
            'expenses' => $expenseStmt->fetchAll(PDO::FETCH_ASSOC) ?: []
        ]);
    } catch (Throwable $error) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => $error->getMessage()]);
    }
    exit;
}

// Payment and expense updates use the same bookings / booking_expenses records
// as the admin page, so changes are immediately visible in both dashboards.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'confirm_payment') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        if (!hash_equals($opPayMongoCsrf, (string)($_POST['csrf_token'] ?? ''))) {
            throw new Exception('Your payment session expired. Refresh the page and try again.');
        }
        $bookingId = ItourValidationInt($_POST['booking_id'] ?? null, 'Booking ID', 1, PHP_INT_MAX);
        $amount = ItourValidationMoney($_POST['amount'] ?? null, 'Payment amount', 10000000.00, false);
        $paymentMethod = trim((string)($_POST['payment_method'] ?? ''));
        $completeAfterPayment = !empty($_POST['complete_after_payment']);
        if ($bookingId <= 0 || $amount <= 0 || $paymentMethod !== 'cash') {
            throw new Exception('Please provide a valid payment method and amount.');
        }

        $pdo->beginTransaction();
        $stmt = $pdo->prepare("SELECT tourist_id, booking_reference, payment_amount, remaining_balance, status, is_complete FROM bookings WHERE booking_id = ? AND operator_id = ? AND LOWER(booking_type) = 'package' FOR UPDATE");
        $stmt->execute([$bookingId, $operator_id]);
        $booking = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$booking) {
            throw new Exception('Booking not found or you do not have access to it.');
        }
        if (strtolower((string)$booking['is_complete']) !== 'uncomplete') {
            throw new Exception('Payments can no longer be added to this booking.');
        }
        $balance = max((float)$booking['remaining_balance'], 0);
        if ($completeAfterPayment && (strtolower((string)$booking['status']) !== 'accepted'
            || strtolower((string)$booking['is_complete']) !== 'uncomplete')) {
            throw new Exception('Only accepted bookings can be completed.');
        }
        if ($amount > $balance) {
            throw new Exception('Payment amount cannot exceed the remaining balance.');
        }
        if ($completeAfterPayment && abs($amount - $balance) > 0.009) {
            throw new Exception('The full remaining balance is required before completing this booking.');
        }

        $remaining = $balance - $amount;
        $newPayment = (float)$booking['payment_amount'] + $amount;
        $paid = $remaining <= 0 ? 1 : 0;
        $update = $pdo->prepare('UPDATE bookings SET payment_amount = ?, remaining_balance = ?, payment_method = ?, is_paid = ?, updated_at = NOW() WHERE booking_id = ? AND operator_id = ?');
        $update->execute([$newPayment, $remaining, $paymentMethod, $paid, $bookingId, $operator_id]);
        $completed = false;
        if ($paid && $completeAfterPayment && strtolower((string)$booking['status']) === 'accepted'
            && strtolower((string)$booking['is_complete']) === 'uncomplete') {
            $complete = $pdo->prepare("UPDATE bookings SET is_complete = 'completed', updated_at = NOW()
                WHERE booking_id = ? AND operator_id = ? AND LOWER(status) = 'accepted' AND LOWER(is_complete) = 'uncomplete'");
            $complete->execute([$bookingId, $operator_id]);
            if ($complete->rowCount() !== 1) throw new RuntimeException('The booking changed before completion. Refresh and try again.');
            $completed = true;
        }

        $cashUnique = bin2hex(random_bytes(16));
        $cashReference = 'CASH-' . date('YmdHis') . '-' . strtoupper(substr($cashUnique, 0, 8));
        $cashMetadata = json_encode([
            'source' => 'operator_booking_payment',
            'operator_id' => $operator_id,
            'operator_name' => (string)$operatorName,
            'staff_type' => 'operator',
            'display_reference' => 'Cash',
        ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        $cashLedger = $pdo->prepare("
            INSERT INTO payment_transactions
              (tourist_id, booking_domain, booking_id, booking_reference, provider,
               merchant_reference, idempotency_key, return_token, amount_minor,
               currency, status, payment_method_type, metadata, paid_at)
            VALUES (?, 'package', ?, ?, 'cash', ?, ?, ?, ?, 'PHP', 'paid', 'cash', ?, NOW())
        ");
        $cashLedger->execute([
            (int)$booking['tourist_id'],
            $bookingId,
            (string)($booking['booking_reference'] ?: $bookingId),
            $cashReference,
            'operator-cash:' . $cashUnique,
            hash('sha256', $cashUnique . random_bytes(8)),
            (int)round($amount * 100),
            $cashMetadata,
        ]);
        $pdo->commit();
        logActivity(
            $pdo, 'Tour Operator', $operator_id, (string)$operatorName,
            'Booking Payment Updated',
            'Recorded a payment for booking #' . $bookingId . '.',
            'Bookings', $bookingId
        );
        echo json_encode(['success' => true, 'remaining' => $remaining, 'is_paid' => $paid, 'completed' => $completed]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'mark_completed') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        if (!AppVerifyCsrf('operator', 'booking_management', $_POST['csrf_token'] ?? null)) {
            http_response_code(403);
            throw new Exception('Invalid security token. Refresh the page and try again.');
        }
        $bookingId = ItourValidationInt($_POST['booking_id'] ?? null, 'Booking ID', 1, PHP_INT_MAX);
        $stmt = $pdo->prepare("SELECT remaining_balance, status, is_complete FROM bookings WHERE booking_id = ? AND operator_id = ? AND LOWER(booking_type) = 'package'");
        $stmt->execute([$bookingId, $operator_id]);
        $booking = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$booking || strtolower((string)$booking['status']) !== 'accepted'
            || strtolower((string)$booking['is_complete']) !== 'uncomplete') {
            throw new Exception('Only accepted package bookings can be completed.');
        }
        if ((float)$booking['remaining_balance'] > 0) {
            throw new Exception('Record the remaining payment before completing this booking.');
        }
        $update = $pdo->prepare("UPDATE bookings SET is_complete = 'completed', updated_at = NOW()
            WHERE booking_id = ? AND operator_id = ? AND LOWER(status) = 'accepted' AND LOWER(is_complete) = 'uncomplete'");
        $update->execute([$bookingId, $operator_id]);
        if ($update->rowCount() !== 1) throw new RuntimeException('The booking changed before completion. Refresh and try again.');
        logActivity(
            $pdo, 'Tour Operator', $operator_id, (string)$operatorName,
            'Booking Completed', 'Marked booking #' . $bookingId . ' as completed.',
            'Bookings', $bookingId
        );
        echo json_encode(['success' => true]);
    } catch (Throwable $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_expense') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        if (!AppVerifyCsrf('operator', 'booking_management', $_POST['csrf_token'] ?? null)) {
            http_response_code(403);
            throw new Exception('Invalid security token. Refresh the page and try again.');
        }
        $bookingId = ItourValidationInt($_POST['booking_id'] ?? null, 'Booking ID', 1, PHP_INT_MAX);
        $type = ItourValidationText($_POST['expense_type'] ?? null, 'Expense type', 40, true);
        $amount = ItourValidationMoney($_POST['amount'] ?? null, 'Expense amount', 10000000.00, false);
        $note = ItourValidationText($_POST['note'] ?? '', 'Expense note', 500);
        $allowedTypes = ['additional_boat', 'additional_tourguide', 'food', 'others'];
        if ($bookingId <= 0 || $amount <= 0 || !in_array($type, $allowedTypes, true)) {
            throw new Exception('Please provide valid expense details.');
        }

        $pdo->beginTransaction();
        $stmt = $pdo->prepare("SELECT grand_total, payment_amount, remaining_balance, status, is_complete FROM bookings WHERE booking_id = ? AND operator_id = ? AND LOWER(booking_type) = 'package' FOR UPDATE");
        $stmt->execute([$bookingId, $operator_id]);
        $booking = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$booking) {
            throw new Exception('Booking not found or you do not have access to it.');
        }
        if (!in_array(strtolower((string)$booking['status']), ['pending', 'accepted'], true)
            || strtolower((string)$booking['is_complete']) !== 'uncomplete') {
            throw new Exception('Expenses can only be added to pending or accepted bookings.');
        }
        $newGrandTotal = round(max(0, (float)$booking['grand_total']) + $amount, 2);
        $amountPaid = round(max(0, (float)$booking['payment_amount']), 2);
        $newRemaining = round(max(0, $newGrandTotal - $amountPaid), 2);
        $newIsPaid = $newGrandTotal > 0 && $newRemaining <= 0 ? 1 : 0;

        $insert = $pdo->prepare('INSERT INTO booking_expenses (booking_id, expense_type, amount, note) VALUES (?, ?, ?, ?)');
        $insert->execute([$bookingId, $type, $amount, $note]);
        $update = $pdo->prepare("UPDATE bookings SET grand_total = ?, remaining_balance = ?, is_paid = ?, updated_at = NOW()
            WHERE booking_id = ? AND operator_id = ? AND LOWER(is_complete) = 'uncomplete'");
        $update->execute([$newGrandTotal, $newRemaining, $newIsPaid, $bookingId, $operator_id]);
        if ($update->rowCount() !== 1) throw new RuntimeException('The booking changed before the expense was saved. Refresh and try again.');
        $balanceStmt = $pdo->prepare('SELECT remaining_balance FROM bookings WHERE booking_id = ? AND operator_id = ?');
        $balanceStmt->execute([$bookingId, $operator_id]);
        $remaining = (float)$balanceStmt->fetchColumn();
        $pdo->commit();
        logActivity(
            $pdo, 'Tour Operator', $operator_id, (string)$operatorName,
            'Booking Expense Added', 'Added an expense to booking #' . $bookingId . '.',
            'Bookings', $bookingId
        );
        echo json_encode(['success' => true, 'remaining' => $remaining]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

$seenAt = trim((string)($_SESSION['op_notifications_seen_at'] ?? ''));
if ($seenAt !== '' && preg_match('/^\d{4}-\d{2}-\d{2}\s\d{2}:\d{2}:\d{2}$/', $seenAt)) {
    $notifStmt = $pdo->prepare("SELECT COUNT(*) FROM bookings WHERE operator_id=? AND status='pending' AND created_at > ?");
    $notifStmt->execute([$operator_id, $seenAt]);
} else {
    $notifStmt = $pdo->prepare("SELECT COUNT(*) FROM bookings WHERE operator_id=? AND status='pending'");
    $notifStmt->execute([$operator_id]);
}
$notificationCount = (int)$notifStmt->fetchColumn();

$notifItemsStmt = $pdo->prepare("
    SELECT booking_id, booking_reference, package_name, booking_date, status, created_at
    FROM bookings
    WHERE operator_id=?
    ORDER BY created_at DESC
    LIMIT 8
");
$notifItemsStmt->execute([$operator_id]);
$notificationItems = $notifItemsStmt->fetchAll(PDO::FETCH_ASSOC);

// --- UPDATED POST ACTION: HANDLE ACCEPT/DECLINE & SEND EMAIL ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'], $_POST['booking_id'])) {
    if (!AppVerifyCsrf('operator', 'booking_management', $_POST['csrf_token'] ?? null)) {
        http_response_code(403);
        exit('Invalid security token. Refresh the page and try again.');
    }
    try {
        $bookingId = ItourValidationInt($_POST['booking_id'], 'Booking ID', 1, PHP_INT_MAX);
    } catch (InvalidArgumentException $exception) {
        http_response_code(422);
        exit($exception->getMessage());
    }
    $action = (string)$_POST['action'];

    if ($bookingId > 0 && in_array($action, ['confirm', 'cancel'], true)) {
        $newStatus = $action === 'confirm' ? 'accepted' : 'cancelled';

        // 1. Fetch booking & tourist details first so we know who to email
        $fetchStmt = $pdo->prepare("
            SELECT b.package_name, b.booking_date, b.status, b.is_complete, t.email, t.full_name
            FROM bookings b
            LEFT JOIN tourist t ON b.tourist_id = t.tourist_id
            WHERE b.booking_id = ? AND b.operator_id = ?
        ");
        $fetchStmt->execute([$bookingId, $operator_id]);
        $bookingData = $fetchStmt->fetch(PDO::FETCH_ASSOC);

        if ($bookingData
            && strtolower((string)$bookingData['status']) === 'pending'
            && strtolower((string)$bookingData['is_complete']) === 'uncomplete') {
            // 2. Update the booking status in the shared database
            $stmt = $pdo->prepare("
                UPDATE bookings
                SET status = ?, updated_at = NOW()
                WHERE booking_id = ? AND operator_id = ?
                  AND LOWER(status) = 'pending' AND LOWER(is_complete) = 'uncomplete'
            ");
            $updateSuccess = $stmt->execute([$newStatus, $bookingId, $operator_id]);
            $updateSuccess = $updateSuccess && $stmt->rowCount() === 1;
            if ($updateSuccess) {
                logActivity(
                    $pdo, 'Tour Operator', $operator_id, (string)$operatorName,
                    $newStatus === 'accepted' ? 'Booking Accepted' : 'Booking Rejected',
                    ($newStatus === 'accepted' ? 'Accepted' : 'Rejected') . ' booking #' . $bookingId . '.',
                    'Bookings', $bookingId
                );
            }

            // 3. Send Email Notification if update was successful and email exists
            if ($updateSuccess && !empty($bookingData['email'])) {
                $guestEmail = trim($bookingData['email']);
                $guestName = !empty($bookingData['full_name']) ? trim($bookingData['full_name']) : 'Valued Guest';
                $packageName = $bookingData['package_name'];
                $bookingDate = date('F j, Y', strtotime($bookingData['booking_date']));

                $subject = "Update on your Tour Package Booking - iTour Mercedes";

                // HTML Email styling
                $message = "
                <html>
                <head>
                  <title>Booking Update</title>
                </head>
                <body style='font-family: Arial, sans-serif; color: #333;'>
                  <div style='max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #eee; border-radius: 10px;'>
                    <h2 style='color: #1d5d4a;'>Booking Status: " . ucfirst($newStatus) . "</h2>
                    <p>Hello <strong>{$guestName}</strong>,</p>";

                if ($newStatus === 'accepted') {
                    $message .= "
                    <p>Great news! Your booking for the <strong>{$packageName}</strong> package on <strong>{$bookingDate}</strong> has been <strong>accepted</strong> by the operator.</p>
                    <p>Please log in to your tourist dashboard to view your payment details, settle any remaining balances, and finalize your travel preparations.</p>";
                } else {
                    $message .= "
                    <p>We regret to inform you that your booking for the <strong>{$packageName}</strong> package on <strong>{$bookingDate}</strong> has been <strong>cancelled</strong> by the operator.</p>
                    <p>If you have any questions or would like to look for alternative dates, please feel free to reach out to our support team.</p>";
                }

                $message .= "
                    <br>
                    <p>Thank you,<br><strong>iTour Mercedes Team</strong></p>
                  </div>
                </body>
                </html>
                ";

                // Standard headers for HTML emails
                $headers = "MIME-Version: 1.0" . "\r\n";
                $headers .= "Content-type:text/html;charset=UTF-8" . "\r\n";
                $headers .= "From: no-reply@itourmercedes.com" . "\r\n";

                // Note: If your admin panel uses PHPMailer, you can include and use your PHPMailer script here instead of mail()
                @mail($guestEmail, $subject, $message, $headers);
            }
        }
    }

    $redirectQuery = http_build_query([
        'status' => $_GET['status'] ?? 'all',
        'q' => $_GET['q'] ?? '',
        'range' => $_GET['range'] ?? 'all',
        'year' => $_GET['year'] ?? date('Y'),
        'month' => $_GET['month'] ?? date('n'),
        'date' => $_GET['date'] ?? date('Y-m-d'),
        'sort' => $_GET['sort'] ?? 'time',
        'rows' => $_GET['rows'] ?? '25',
    ]);
    header('Location: opbookings.php' . ($redirectQuery ? '?' . $redirectQuery : ''));
    exit;
}
// --- END OF POST ACTION ---

$statusFilter = strtolower(trim((string)($_GET['status'] ?? 'all')));
$search = trim((string)($_GET['q'] ?? ''));
$rangeFilter = strtolower(trim((string)($_GET['range'] ?? 'all')));
$sortBy = strtolower(trim((string)($_GET['sort'] ?? 'time')));
$rowsRaw = trim((string)($_GET['rows'] ?? '25'));
$rowsPerPage = (int)$rowsRaw;
if ($rowsPerPage < 1) {
    $rowsPerPage = 25;
}
if ($rowsPerPage > 300) {
    $rowsPerPage = 300;
}

$validStatus = ['all', 'pending', 'accepted', 'cancelled'];
if (!in_array($statusFilter, $validStatus, true)) {
    $statusFilter = 'all';
}
$validRange = ['all', 'yearly', 'monthly', 'weekly', 'daily'];
if (!in_array($rangeFilter, $validRange, true)) {
    $rangeFilter = 'all';
}
$validSort = ['time', 'name'];
if (!in_array($sortBy, $validSort, true)) {
    $sortBy = 'time';
}

$currentYear = (int)date('Y');
$currentMonth = (int)date('n');
$selectedYear = (int)($_GET['year'] ?? $currentYear);
if ($selectedYear < 2000 || $selectedYear > ($currentYear + 2)) {
    $selectedYear = $currentYear;
}
$selectedMonth = (int)($_GET['month'] ?? $currentMonth);
if ($selectedMonth < 1 || $selectedMonth > 12) {
    $selectedMonth = $currentMonth;
}
$selectedDate = trim((string)($_GET['date'] ?? date('Y-m-d')));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $selectedDate)) {
    $selectedDate = date('Y-m-d');
}

$availableYears = $pdo->prepare("
    SELECT DISTINCT YEAR(created_at) AS yr
    FROM bookings
    WHERE created_at IS NOT NULL AND operator_id = ?
    ORDER BY yr DESC
");
$availableYears->execute([$operator_id]);
$availableYears = array_values(array_filter(array_map('intval', $availableYears->fetchAll(PDO::FETCH_COLUMN))));
if (empty($availableYears)) {
    $availableYears = [$currentYear];
}
if (!in_array($selectedYear, $availableYears, true)) {
    $selectedYear = $availableYears[0];
}

function OpResolveBookerProfileImage(?string $profilePicture): string
{
    $profilePicture = trim((string)$profilePicture);
    if ($profilePicture === '' || preg_match('~(?:^|/)(?:profileicon|profileicon2)\.png(?:$|[?#])~i', str_replace('\\', '/', $profilePicture))) {
        return '';
    }

    if (preg_match('#^https?://#i', $profilePicture)) {
        if (stripos($profilePicture, 'profiles.google.com') !== false && preg_match('~profiles\\.google\\.com/(?:s2/photos/profile/)?([^/?#]+)(?:/picture)?~i', $profilePicture, $m)) {
            return 'https://profiles.google.com/' . rawurlencode($m[1]) . '/picture?sz=256';
        }
        if (stripos($profilePicture, 'googleusercontent.com') !== false) {
            $profilePicture = preg_replace('/([?&])sz=\\d+/i', '$1sz=256', $profilePicture);
            $profilePicture = preg_replace('/=s\\d+-c(?=$|[?&#])/i', '=s256-c', $profilePicture);
            $profilePicture = preg_replace('/=s\\d+(?=$|[?&#])/i', '=s256', $profilePicture);
        }
        return $profilePicture;
    }

    $clean = ltrim($profilePicture, '/');
    $paths = [
        'uploads/profile_pictures/' . basename($clean),
        'uploads/profile_picture/' . basename($clean),
        $clean,
    ];

    foreach ($paths as $p) {
        $local = __DIR__ . '/../' . str_replace('/', DIRECTORY_SEPARATOR, $p);
        if (file_exists($local)) {
            return $p;
        }
    }

    return '';
}

function OpBuildGoogleAvatarUrl(?string $googleId): string
{
    if (!$googleId) {
        return '';
    }
    return 'https://profiles.google.com/' . urlencode($googleId) . '/picture?sz=256';
}

if (isset($_GET['op_action']) && $_GET['op_action'] === 'search_tourists') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    $touristSearch = trim((string)($_GET['q'] ?? ''));
    if (mb_strlen($touristSearch) < 2) {
        echo json_encode(['ok' => true, 'tourists' => []]);
        exit;
    }

    $searchTerm = '%' . $touristSearch . '%';
    $touristStmt = $pdo->prepare("
        SELECT tourist_id, full_name, email, phone_number, profile_picture, google_id
        FROM tourist
        WHERE status = 'active'
          AND (full_name LIKE ? OR email LIKE ? OR phone_number LIKE ?)
        ORDER BY full_name ASC
        LIMIT 15
    ");
    $touristStmt->execute([$searchTerm, $searchTerm, $searchTerm]);
    $tourists = $touristStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($tourists as &$tourist) {
        $profileImage = OpResolveBookerProfileImage($tourist['profile_picture'] ?? null);
        if ($profileImage === '') {
            $profileImage = OpBuildGoogleAvatarUrl($tourist['google_id'] ?? null);
        }
        $tourist['profile_image'] = $profileImage;
        unset($tourist['profile_picture'], $tourist['google_id']);
    }
    unset($tourist);
    echo json_encode(['ok' => true, 'tourists' => $tourists], JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_operator_walkin_booking') {
    $walkinPackageLock = '';
    try {
        if (!hash_equals($opWalkinCsrf, (string)($_POST['csrf_token'] ?? ''))) {
            throw new RuntimeException('The booking form session expired. Please reopen it and try again.');
        }

        $guestMode = ($_POST['guest_mode'] ?? 'existing') === 'new' ? 'new' : 'existing';
        $packageId = ItourValidationInt($_POST['package_id'] ?? null, 'Package ID', 1, PHP_INT_MAX);
        $bookingDate = trim((string)($_POST['booking_date'] ?? ''));
        $bookingEndDate = trim((string)($_POST['booking_end_date'] ?? ''));
        $jumpOffPort = trim((string)($_POST['jump_off_port'] ?? ''));
        $adults = ItourValidationInt($_POST['num_adults'] ?? null, 'Adults', 1, 100);
        $children = ItourValidationInt($_POST['num_children'] ?? 0, 'Children', 0, 100);
        $pax = $adults + $children;
        $phoneNumber = trim((string)($_POST['phone_number'] ?? ''));
        $paymentOption = strtolower(trim((string)($_POST['payment_option'] ?? 'partial')));
        $paymentMethod = strtolower(trim((string)($_POST['payment_method'] ?? '')));
        $partialAmount = ItourValidationMoney($_POST['payment_amount'] ?? 0, 'Payment amount');
        $allowedPorts = ['Mercedes Port', 'Cayucyucan'];
        $allowedPaymentMethods = ['', 'cash', 'gcash', 'bank_transfer'];

        $bookingDate = ItourValidationDate($bookingDate, 'Booking date');
        if ($bookingDate < date('Y-m-d')) {
            throw new RuntimeException('Select a valid tour date that is not in the past.');
        }
        if (!in_array($jumpOffPort, $allowedPorts, true)) {
            throw new RuntimeException('Select a valid jump-off port.');
        }
        if ($adults < 1 || $pax < 1 || $pax > 100) {
            throw new RuntimeException('The booking requires at least one adult and no more than 100 guests.');
        }
        if (!in_array($paymentOption, ['full', 'partial', 'unpaid'], true)) {
            throw new RuntimeException('Select a valid payment option.');
        }
        if (!in_array($paymentMethod, $allowedPaymentMethods, true)) {
            throw new RuntimeException('Select a valid payment method.');
        }

        $packageStmt = $pdo->prepare("
            SELECT package_id, package_title, package_type, package_range, price
            FROM tour_packages
            WHERE package_id = ? AND operator_id = ?
            LIMIT 1
        ");
        $packageStmt->execute([$packageId, $operator_id]);
        $package = $packageStmt->fetch(PDO::FETCH_ASSOC);
        if (!$package) {
            throw new RuntimeException('Select one of your own tour packages.');
        }

        $packageType = strtolower(trim((string)($package['package_type'] ?? '')));
        $tourType = str_contains($packageType, 'night') ? 'overnight' : 'same-day';
        if ($tourType === 'overnight') {
            $bookingEndDate = ItourValidationDate($bookingEndDate, 'Booking end date');
            if ($bookingEndDate <= $bookingDate) {
                throw new RuntimeException('Select an end date after the start date for this overnight package.');
            }
            $tourRange = $bookingDate . ' to ' . $bookingEndDate;
        } else {
            $bookingEndDate = '';
            $tourRange = $bookingDate;
        }

        $expenseAmounts = [
            'Environmental Fee' => ItourValidationMoney($_POST['expense_environmental'] ?? 0, 'Environmental fee'),
            'Entrance Fee' => ItourValidationMoney($_POST['expense_entrance'] ?? 0, 'Entrance fee'),
            'Docking / Landing Fee' => ItourValidationMoney($_POST['expense_docking'] ?? 0, 'Docking fee'),
            'Other Fee' => ItourValidationMoney($_POST['expense_other'] ?? 0, 'Other fee'),
        ];
        $serviceAmount = round(max(0, (float)$package['price']) * $pax, 2);
        $grandTotal = round($serviceAmount + array_sum($expenseAmounts), 2);
        $paymentAmount = match ($paymentOption) {
            'full' => $grandTotal,
            'partial' => $partialAmount,
            default => 0.0,
        };
        if ($paymentOption === 'partial' && ($paymentAmount <= 0 || $paymentAmount >= $grandTotal)) {
            throw new RuntimeException('Enter a partial payment greater than zero and lower than the grand total.');
        }
        if ($paymentAmount > 0 && $paymentMethod === '') {
            throw new RuntimeException('Select a payment method for the amount received.');
        }

        $walkinPackageLock = tourPackageLock($pdo, (string)$package['package_title']);
        if (!tourPackageIsAvailable($pdo, (string)$package['package_title'], $bookingDate, $bookingEndDate ?: $bookingDate, $pax, $operator_id, (int)$package['package_id'])) {
            throw new RuntimeException('This package does not have enough open guest slots for the selected date.');
        }

        $pdo->beginTransaction();
        if ($guestMode === 'existing') {
            $touristId = max(0, (int)($_POST['tourist_id'] ?? 0));
            $touristStmt = $pdo->prepare("
                SELECT tourist_id, phone_number
                FROM tourist
                WHERE tourist_id = ? AND status = 'active'
                LIMIT 1
                FOR UPDATE
            ");
            $touristStmt->execute([$touristId]);
            $tourist = $touristStmt->fetch(PDO::FETCH_ASSOC);
            if (!$tourist) {
                throw new RuntimeException('Search for and select an active tourist account.');
            }
            if ($phoneNumber === '') {
                $phoneNumber = trim((string)($tourist['phone_number'] ?? ''));
            }
        } else {
            $guestName = trim((string)($_POST['guest_name'] ?? ''));
            $guestEmail = strtolower(trim((string)($_POST['guest_email'] ?? '')));
            $guestAddress = trim((string)($_POST['guest_address'] ?? ''));
            if ($guestName === '' || !filter_var($guestEmail, FILTER_VALIDATE_EMAIL) || $phoneNumber === '') {
                throw new RuntimeException('Enter the walk-in guest’s name, valid email, and contact number.');
            }
            $existingStmt = $pdo->prepare("SELECT tourist_id, status FROM tourist WHERE LOWER(email) = ? LIMIT 1 FOR UPDATE");
            $existingStmt->execute([$guestEmail]);
            $existingTourist = $existingStmt->fetch(PDO::FETCH_ASSOC);
            if ($existingTourist) {
                if (strtolower((string)$existingTourist['status']) !== 'active') {
                    throw new RuntimeException('This email belongs to an inactive tourist account.');
                }
                $touristId = (int)$existingTourist['tourist_id'];
            } else {
                $temporaryPassword = password_hash(bin2hex(random_bytes(18)), PASSWORD_DEFAULT);
                $insertTourist = $pdo->prepare("
                    INSERT INTO tourist (full_name, email, phone_number, address, password_hash, email_verified, status)
                    VALUES (?, ?, ?, ?, ?, 1, 'active')
                ");
                $insertTourist->execute([$guestName, $guestEmail, $phoneNumber, $guestAddress, $temporaryPassword]);
                $touristId = (int)$pdo->lastInsertId();
            }
        }

        if ($phoneNumber === '') {
            throw new RuntimeException('A booking contact number is required.');
        }

        $remainingBalance = max(0, round($grandTotal - $paymentAmount, 2));
        $isPaid = $grandTotal > 0 && $remainingBalance <= 0 ? 1 : 0;
        $paymentStatus = $isPaid ? 'Paid' : ($paymentAmount > 0 ? 'Partial' : 'Unpaid');
        $packageName = (string)$package['package_title'];
        $preferredResource = $packageName
            . ' | Operator-assisted booking'
            . ' | Payment Option: ' . ucfirst($paymentOption)
            . ' | Payment Status: ' . $paymentStatus;
        $bookingReference = BookingReferenceGenerate($pdo, 'package');

        $insertBooking = $pdo->prepare("
            INSERT INTO bookings (
                booking_reference, tourist_id, operator_id, booking_date, location, package_name, pax,
                phone_number, booking_type, jump_off_port, tour_type, tour_range,
                status, is_notif_viewed, num_adults, num_children, is_complete,
                preferred_resource, grand_total, remaining_balance, payment_amount,
                is_paid, payment_method, guide_id, boat_id, created_at, updated_at
            ) VALUES (
                ?, ?, ?, ?, '', ?, ?,
                ?, 'package', ?, ?, ?,
                'accepted', 0, ?, ?, 'uncomplete',
                ?, ?, ?, ?,
                ?, ?, NULL, NULL, NOW(), NOW()
            )
        ");
        $insertBooking->execute([
            $bookingReference, $touristId, $operator_id, $bookingDate, $packageName, $pax,
            $phoneNumber, $jumpOffPort, $tourType, substr($tourRange, 0, 50),
            $adults, $children, substr($preferredResource, 0, 255),
            $grandTotal, $remainingBalance, $paymentAmount, $isPaid,
            $paymentMethod !== '' ? $paymentMethod : null
        ]);
        $bookingId = (int)$pdo->lastInsertId();

        $insertExpense = $pdo->prepare("
            INSERT INTO booking_expenses (booking_id, expense_type, amount, note)
            VALUES (?, ?, ?, ?)
        ");
        foreach ($expenseAmounts as $expenseType => $expenseAmount) {
            if ($expenseAmount <= 0) continue;
            $insertExpense->execute([
                (string)$bookingId,
                $expenseType,
                $expenseAmount,
                'Recorded during operator-assisted booking creation'
            ]);
        }

        logActivity(
            $pdo,
            'Tour Operator',
            $operator_id,
            (string)$operatorName,
            'Walk-in Booking Added',
            'Created operator-assisted booking ' . $bookingReference . ' for ' . $packageName . '.',
            'Bookings',
            $bookingId
        );
        $pdo->commit();
        tourResourceUnlock($pdo, $walkinPackageLock);
        $_SESSION['op_walkin_booking_csrf'] = bin2hex(random_bytes(24));
        header('Location: opbookings.php?booking_notice=created&booking_ref=' . rawurlencode($bookingReference));
        exit;
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        tourResourceUnlock($pdo, $walkinPackageLock);
        $_SESSION['op_walkin_error'] = $error->getMessage();
        $_SESSION['op_walkin_form'] = $_POST;
        header('Location: opbookings.php?add_booking=1');
        exit;
    }
}

$walkInPackagesStmt = $pdo->prepare("
    SELECT package_id, package_title, package_type, package_range, price
    FROM tour_packages
    WHERE operator_id = ?
    ORDER BY package_title ASC
");
$walkInPackagesStmt->execute([$operator_id]);
$walkInPackages = $walkInPackagesStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
$walkinError = (string)($_SESSION['op_walkin_error'] ?? '');
$walkinForm = is_array($_SESSION['op_walkin_form'] ?? null) ? $_SESSION['op_walkin_form'] : [];
unset($_SESSION['op_walkin_error'], $_SESSION['op_walkin_form']);

$where = ["b.operator_id = :operator_id", "LOWER(b.booking_type) = 'package'"];
$params = [':operator_id' => $operator_id];

if ($statusFilter !== 'all') {
    $where[] = 'LOWER(b.status) = :status';
    $params[':status'] = $statusFilter;
}

if ($search !== '') {
    $where[] = "(COALESCE(t.full_name, '') LIKE :search OR COALESCE(b.booking_reference, '') LIKE :search OR CAST(b.booking_id AS CHAR) LIKE :search OR COALESCE(b.package_name, '') LIKE :search)";
    $params[':search'] = '%' . $search . '%';
}
if ($rangeFilter === 'yearly') {
    $where[] = 'YEAR(b.created_at) = :selected_year';
    $params[':selected_year'] = $selectedYear;
} elseif ($rangeFilter === 'monthly') {
    $where[] = 'YEAR(b.created_at) = :selected_year AND MONTH(b.created_at) = :selected_month';
    $params[':selected_year'] = $selectedYear;
    $params[':selected_month'] = $selectedMonth;
} elseif ($rangeFilter === 'daily') {
    $where[] = 'DATE(b.created_at) = :selected_date';
    $params[':selected_date'] = $selectedDate;
} elseif ($rangeFilter === 'weekly') {
    $weekDate = DateTime::createFromFormat('Y-m-d', $selectedDate) ?: new DateTime();
    $weekDate->setTime(0, 0, 0);
    $weekStart = (clone $weekDate)->modify('monday this week');
    $weekEnd = (clone $weekStart)->modify('+6 days');
    $where[] = 'DATE(b.created_at) BETWEEN :week_start AND :week_end';
    $params[':week_start'] = $weekStart->format('Y-m-d');
    $params[':week_end'] = $weekEnd->format('Y-m-d');
}

$whereSql = 'WHERE ' . implode(' AND ', $where);
$orderBySql = $sortBy === 'name'
    ? 'COALESCE(t.full_name, \'\') ASC, b.created_at DESC'
    : 'b.created_at DESC';

$countSql = "
SELECT COUNT(*)
FROM bookings b
LEFT JOIN tourist t ON t.tourist_id = b.tourist_id
$whereSql
";
$countStmt = $pdo->prepare($countSql);
$countStmt->execute($params);
$totalFilteredBookings = (int)$countStmt->fetchColumn();

$sql = "
SELECT
  b.booking_id,
  b.booking_reference,
  b.package_name,
  b.booking_date,
  b.status,
  b.phone_number,
  b.location,
  b.pax,
  b.num_adults,
  b.num_children,
  b.booking_type,
  b.jump_off_port,
  b.is_complete,
  b.grand_total,
  b.payment_amount,
  b.remaining_balance,
  b.payment_method,
  b.is_paid,
  b.created_at,
  b.tourist_id,
  COALESCE(NULLIF(TRIM(t.full_name), ''), CONCAT('Tourist #', b.tourist_id)) AS guest_name,
  COALESCE(NULLIF(TRIM(t.email), ''), '-') AS guest_email,
  t.profile_picture AS tourist_profile_picture,
  t.google_id AS tourist_google_id,
  t.address AS tourist_address,
  t.email_verified AS tourist_email_verified,
  t.status AS tourist_account_status,
  t.created_at AS tourist_created_at,
  t.updated_at AS tourist_updated_at,
  (SELECT COUNT(*) FROM bookings tb WHERE tb.tourist_id = t.tourist_id) AS tourist_total_bookings,
  (SELECT COUNT(*) FROM bookings cb WHERE cb.tourist_id = t.tourist_id AND cb.is_complete = 'completed') AS tourist_completed_bookings
FROM bookings b
LEFT JOIN tourist t ON t.tourist_id = b.tourist_id
$whereSql
ORDER BY $orderBySql
LIMIT " . (int)$rowsPerPage;

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$bookings = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>iTour Mercedes - Operator Bookings</title>
  <link rel="icon" type="image/png" href="img/newlogo.png" />
  <link rel="stylesheet" href="styles/Ho_panel.css" />
  <link rel="stylesheet" href="styles/operator_booking_drawer.css?v=2" />
  <link rel="stylesheet" href="styles/required-fields.css" />
  <script src="js/required-fields.js" defer></script>
  <style>
    .op-layout {
      width: 100%;
      max-width: 100vw;
      display: flex;
      min-height: 100vh;
      overflow-x: clip;
    }
    body.ho-body { overflow-x: hidden; }
    .op-main {
      margin-left: 250px;
      min-width: 0;
      padding: 80px 12px 24px;
      flex: 1;
      min-height: 100vh;
      overflow-x: hidden;
    }
    .operator-header {
      background: #fff;
      border-radius: 0 0 14px 14px;
      padding: 14px 18px;
      position: fixed;
      top: 0;
      left: 250px;
      width: calc(100vw - 250px);
      min-height: 78px;
      z-index: 90;
      border-bottom: 1px solid rgba(188, 220, 206, 0.6);
      display: flex;
      justify-content: space-between;
      align-items: center;
      gap: 16px;
    }
    .operator-header-left h2 {
      margin: 0;
      font-size: 23px;
      font-weight: 700;
      color: #1d5d4a;
      letter-spacing: 0.01em;
    }
    .operator-header-left p {
      margin: 3px 0 0;
      color: #60707a;
      font-size: 13px;
    }
    .operator-header-right {
      display: inline-flex;
      align-items: center;
      gap: 10px;
      margin-right: 8px;
      flex-wrap: wrap;
      justify-content: flex-end;
    }
    .op-global-filter-form {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      flex-wrap: wrap;
      justify-content: flex-end;
    }
    .op-global-filter-label {
      font-size: 13px;
      color: #4f636c;
      font-weight: 600;
    }
    .op-global-filter-select {
      border: 1px solid #d8e6e0;
      background: #fff;
      border-radius: 10px;
      padding: 8px 11px;
      font: inherit;
      font-size: 13px;
      color: #24434d;
      min-width: 96px;
    }
    .op-global-filter-date {
      min-width: 148px;
    }
    .op-global-filter-apply {
      border: 1px solid #236552;
      background: linear-gradient(135deg, #2b7a66 0%, #236552 100%);
      border-radius: 11px;
      padding: 8px 13px;
      font: inherit;
      font-size: 13px;
      font-weight: 700;
      color: #fff;
      cursor: pointer;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 8px;
      box-shadow: 0 7px 15px rgba(29, 93, 74, 0.17);
    }
    .op-global-filter-apply::before {
      content: "";
      width: 16px;
      height: 16px;
      flex: 0 0 16px;
      background-color: currentColor;
      -webkit-mask: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24'%3E%3Cpath fill='black' d='M3.5 5.2A1.2 1.2 0 0 1 4.6 4.5h14.8a1.2 1.2 0 0 1 .9 2L14.5 13v5.2a1.2 1.2 0 0 1-.7 1.1l-3 1.4A1.2 1.2 0 0 1 9 19.6V13L3.7 6.5a1.2 1.2 0 0 1-.2-1.3Z'/%3E%3C/svg%3E") center / contain no-repeat;
      mask: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24'%3E%3Cpath fill='black' d='M3.5 5.2A1.2 1.2 0 0 1 4.6 4.5h14.8a1.2 1.2 0 0 1 .9 2L14.5 13v5.2a1.2 1.2 0 0 1-.7 1.1l-3 1.4A1.2 1.2 0 0 1 9 19.6V13L3.7 6.5a1.2 1.2 0 0 1-.2-1.3Z'/%3E%3C/svg%3E") center / contain no-repeat;
    }
    .op-global-filter-apply:hover {
      background: linear-gradient(135deg, #236d59 0%, #194f40 100%);
      box-shadow: 0 9px 18px rgba(29, 93, 74, 0.22);
      transform: translateY(-1px);
    }
    .op-topbar-profile {
      width: 38px;
      height: 38px;
      border-radius: 50%;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      background: linear-gradient(135deg, #2b7a66 0%, #1f614e 100%);
      color: #fff;
      font-size: 14px;
      font-weight: 800;
      text-transform: uppercase;
      border: 1px solid rgba(43, 122, 102, 0.18);
      box-shadow: 0 4px 10px rgba(28, 74, 62, 0.14);
      overflow: hidden;
      flex-shrink: 0;
    }
    .op-topbar-profile img {
      width: 100%;
      height: 100%;
      object-fit: cover;
      display: block;
    }
    .op-notif-wrap { position: relative; }
    .op-notif-btn {
      border: 1px solid #d8e6e0;
      background: #fff;
      border-radius: 10px;
      padding: 8px 11px;
      display: inline-flex;
      align-items: center;
      gap: 8px;
      color: #26404a;
      font-weight: 600;
      cursor: pointer;
      font-size: 13px;
    }
    .op-notif-badge {
      min-width: 20px;
      height: 20px;
      border-radius: 50%;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      background: #bf3545;
      color: #fff;
      font-size: 12px;
      font-weight: 700;
    }
    .op-notif-panel {
      position: absolute;
      right: 0;
      top: calc(100% + 8px);
      width: min(420px, 88vw);
      background: #fff;
      border: 1px solid #d8e6e0;
      border-radius: 12px;
      box-shadow: 0 12px 30px rgba(17, 67, 53, 0.08);
      padding: 10px;
      display: none;
      z-index: 120;
    }
    .op-notif-panel.open { display: block; }
    .op-notif-panel h4 { margin: 0 0 8px; font-size: 14px; }
    .op-notif-list {
      margin: 0;
      padding: 0;
      list-style: none;
      display: grid;
      gap: 8px;
      max-height: 320px;
      overflow: auto;
    }
    .op-notif-list li {
      border: 1px solid #e8f0ed;
      background: #fbfefd;
      border-radius: 10px;
      padding: 8px;
      display: grid;
      gap: 2px;
    }
    .op-notif-list li strong { font-size: 12px; color: #1d343e; }
    .op-notif-list li span,
    .op-notif-list li small { font-size: 12px; color: #63747d; }
    .op-notif-empty { margin: 0; color: #60707a; font-size: 13px; padding: 8px 4px; }
    .ho-content {
      min-width: 0;
      margin-top: 3px;
    }
    .ho-status.accepted {
      background: #e6f8ef;
      color: #1f6e4a;
    }
    .op-payment { display: grid; gap: 6px; justify-items: center; min-width: 112px; }
    .op-payment-badge {
      display: inline-flex; align-items: center; gap: 6px; padding: 6px 10px;
      border: 1px solid transparent; border-radius: 999px; font-size: 11px;
      line-height: 1; font-weight: 800; letter-spacing: .02em;
    }
    .op-payment-badge::before { content: ''; width: 6px; height: 6px; border-radius: 50%; background: currentColor; }
    .op-payment-badge.paid { color: #17724d; background: #eaf8f0; border-color: #bfe8d0; }
    .op-payment-badge.partial { color: #9a6100; background: #fff6de; border-color: #f2d796; }
    .op-payment-badge.unpaid { color: #b13442; background: #fff0f2; border-color: #f2c8ce; }
    .op-payment small { color: #aa3742; font-size: 11px; font-weight: 700; white-space: nowrap; }
    .op-payment.paid small { color: #287253; }
    .ho-table-card { min-width: 0; }
    .op-main .ho-table-wrap { overflow-x: hidden; }
    .op-bookings-table {
      width: 100%;
      min-width: 0;
      table-layout: fixed;
    }
    .op-bookings-table th:first-child,
    .op-bookings-table td:first-child {
      width: 25%;
      min-width: 0;
      padding-left: 17px;
      white-space: normal;
    }
    .op-bookings-table th:nth-child(2) { width: 10%; }
    .op-bookings-table th:nth-child(3) { width: 16%; }
    .op-bookings-table th:nth-child(4) { width: 12%; }
    .op-bookings-table th:nth-child(5) { width: 10%; }
    .op-bookings-table th:nth-child(6) { width: 10%; }
    .op-bookings-table th:nth-child(7) { width: 8%; }
    .op-bookings-table th:nth-child(8) { width: 9%; }
    .op-bookings-table tbody td {
      height: 76px;
      padding-inline: 12px;
    }
    .op-bookings-table .ho-pax-cell,
    .op-bookings-table .ho-pax-summary,
    .op-bookings-table .op-payment { min-width: 0; }
    .op-bookings-table .ho-row-actions-trigger {
      width: 100%;
      min-width: 0;
      padding-inline: 7px;
    }
    .op-booker-copy { min-width: 0; }
    .op-booker-name-row {
      display: flex;
      align-items: center;
      flex-wrap: wrap;
      gap: 7px;
      margin-bottom: 4px;
      white-space: nowrap;
    }
    .op-booker-name {
      min-width: 0;
      overflow: hidden;
      color: #163d32;
      font-size: 14px;
      font-weight: 800;
      line-height: 1.25;
      text-overflow: ellipsis;
    }
    .op-booker-month {
      flex: 0 0 auto;
      padding: 3px 7px;
      border: 1px solid #e1ebe7;
      border-radius: 999px;
      background: #f1f6f4;
      color: #547068;
      font-size: 9px;
      font-weight: 750;
    }
    .op-booker-email {
      display: block;
      overflow: hidden;
      color: #667c75;
      font-size: 11px;
      line-height: 1.25;
      text-overflow: ellipsis;
      white-space: nowrap;
    }
    .op-package-chip {
      display: inline-flex;
      max-width: 100%;
      padding: 5px 8px;
      border: 1px solid #dce9e4;
      border-radius: 8px;
      background: #f7faf9;
      color: #405e55;
      font-size: 11px;
      font-weight: 700;
      line-height: 1.25;
      overflow-wrap: anywhere;
      text-align: left;
      white-space: normal;
    }
    .op-booking-date {
      color: #294b41;
      font-size: 12px;
      font-weight: 750;
      white-space: nowrap;
    }
    .op-bookings-table .ho-row-actions-menu .op-action-payment::before,
    .op-bookings-table .ho-row-actions-menu .op-action-expense::before,
    .op-bookings-table .ho-row-actions-menu .op-action-billing::before,
    .op-bookings-table .ho-row-actions-menu .op-action-complete::before {
      content: "";
      width: 15px;
      height: 15px;
      flex: 0 0 15px;
      background: currentColor;
      -webkit-mask-position: center;
      -webkit-mask-size: contain;
      -webkit-mask-repeat: no-repeat;
      mask-position: center;
      mask-size: contain;
      mask-repeat: no-repeat;
    }
    .op-bookings-table .ho-row-actions-menu .op-action-payment::before {
      -webkit-mask-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24'%3E%3Crect x='3' y='5' width='18' height='14' rx='2' fill='none' stroke='black' stroke-width='2'/%3E%3Cpath d='M3 10h18M7 15h4' stroke='black' stroke-width='2'/%3E%3C/svg%3E");
      mask-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24'%3E%3Crect x='3' y='5' width='18' height='14' rx='2' fill='none' stroke='black' stroke-width='2'/%3E%3Cpath d='M3 10h18M7 15h4' stroke='black' stroke-width='2'/%3E%3C/svg%3E");
    }
    .op-bookings-table .ho-row-actions-menu .op-action-expense::before {
      -webkit-mask-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24'%3E%3Cpath d='M12 3v18M17 7.5c0-2-2-3-5-3s-5 1.3-5 3 1.5 2.5 5 3.5 5 1.8 5 3.8-2 3.2-5 3.2-5-1.2-5-3.2' fill='none' stroke='black' stroke-width='2' stroke-linecap='round'/%3E%3C/svg%3E");
      mask-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24'%3E%3Cpath d='M12 3v18M17 7.5c0-2-2-3-5-3s-5 1.3-5 3 1.5 2.5 5 3.5 5 1.8 5 3.8-2 3.2-5 3.2-5-1.2-5-3.2' fill='none' stroke='black' stroke-width='2' stroke-linecap='round'/%3E%3C/svg%3E");
    }
    .op-bookings-table .ho-row-actions-menu .op-action-billing::before {
      -webkit-mask-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24'%3E%3Crect x='3' y='4' width='18' height='16' rx='2' fill='none' stroke='black' stroke-width='2'/%3E%3Cpath d='M7 9h10M7 13h6M7 17h4' stroke='black' stroke-width='2' stroke-linecap='round'/%3E%3C/svg%3E");
      mask-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24'%3E%3Crect x='3' y='4' width='18' height='16' rx='2' fill='none' stroke='black' stroke-width='2'/%3E%3Cpath d='M7 9h10M7 13h6M7 17h4' stroke='black' stroke-width='2' stroke-linecap='round'/%3E%3C/svg%3E");
    }
    .op-bookings-table .ho-row-actions-menu .op-action-complete::before {
      -webkit-mask-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24'%3E%3Crect x='4' y='3' width='16' height='18' rx='2' fill='none' stroke='black' stroke-width='2'/%3E%3Cpath d='m8 13 2.5 2.5L16 10' fill='none' stroke='black' stroke-width='2' stroke-linecap='round'/%3E%3C/svg%3E");
      mask-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24'%3E%3Crect x='4' y='3' width='16' height='18' rx='2' fill='none' stroke='black' stroke-width='2'/%3E%3Cpath d='m8 13 2.5 2.5L16 10' fill='none' stroke='black' stroke-width='2' stroke-linecap='round'/%3E%3C/svg%3E");
    }
    .op-bookings-table .ho-row-actions-menu .op-action-complete { color: #2563c7; }
    .op-bookings-table .ho-row-actions-menu .op-action-complete:hover { color: #174ea6; background: #edf5ff; }
    .op-billing-overlay {
      position: fixed;
      inset: 0;
      z-index: 10080;
      display: none;
      align-items: center;
      justify-content: center;
      padding: 22px;
      background: rgba(12, 31, 35, .62);
      backdrop-filter: blur(4px);
    }
    .op-billing-overlay.show { display: flex; }
    body.op-billing-open { overflow: hidden; }
    .op-billing-modal {
      width: min(620px, 100%);
      max-height: min(820px, calc(100vh - 44px));
      display: flex;
      flex-direction: column;
      overflow: hidden;
      color: #203d35;
      border: 1px solid #d7e4df;
      border-radius: 16px;
      background: #fff;
      box-shadow: 0 28px 80px rgba(7, 31, 32, .28);
    }
    .op-billing-header {
      display: grid;
      grid-template-columns: 44px 1fr 34px;
      gap: 13px;
      align-items: center;
      padding: 20px 22px;
      border-bottom: 1px solid #dce8e3;
      background: linear-gradient(135deg, #f7fbf9, #edf6f2);
    }
    .op-billing-icon {
      width: 44px;
      height: 44px;
      display: grid;
      place-items: center;
      color: #fff;
      border-radius: 11px;
      background: #1e6a57;
      box-shadow: 0 7px 16px rgba(30, 106, 87, .2);
    }
    .op-billing-icon svg { width: 23px; fill: none; stroke: currentColor; stroke-width: 1.7; }
    .op-billing-header > div > span { display: block; color: #638078; font-size: 9px; font-weight: 800; letter-spacing: .11em; }
    .op-billing-header h3 { margin: 2px 0 3px; color: #173b32; font-size: 20px; line-height: 1.15; }
    .op-billing-header p { margin: 0; color: #657c75; font-size: 11px; }
    .op-billing-close-icon {
      width: 32px;
      height: 32px;
      border: 0;
      border-radius: 8px;
      color: #55726a;
      background: transparent;
      font-size: 24px;
      cursor: pointer;
    }
    .op-billing-close-icon:hover { color: #164f40; background: #dfede8; }
    .op-billing-body {
      overflow-y: auto;
      padding: 20px 22px;
      background: #fbfcfc;
      scrollbar-width: thin;
      scrollbar-color: #4f9b82 #e5efeb;
    }
    .op-billing-loading { min-height: 280px; display: grid; place-content: center; justify-items: center; gap: 12px; color: #6c817b; font-size: 12px; }
    .op-billing-loading i { width: 30px; height: 30px; border: 3px solid #d8e7e1; border-top-color: #26735f; border-radius: 50%; animation: opBillingSpin .7s linear infinite; }
    @keyframes opBillingSpin { to { transform: rotate(360deg); } }
    .op-billing-summary { display: flex; justify-content: space-between; align-items: center; gap: 14px; margin-bottom: 15px; }
    .op-billing-reference small, .op-billing-party small, .op-billing-stat small { display: block; margin-bottom: 4px; color: #758781; font-size: 9px; font-weight: 800; letter-spacing: .055em; }
    .op-billing-reference strong { color: #1b4237; font-size: 14px; }
    .op-billing-status { padding: 7px 11px; border: 1px solid; border-radius: 999px; font-size: 10px; font-weight: 800; }
    .op-billing-status.paid { color: #17694a; background: #e8f6ee; border-color: #bfe1ce; }
    .op-billing-status.partial { color: #936000; background: #fff7e2; border-color: #eed69b; }
    .op-billing-status.unpaid { color: #a53b45; background: #fff0f1; border-color: #ecc6ca; }
    .op-billing-party { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; padding: 14px; margin-bottom: 16px; border: 1px solid #dde9e5; border-radius: 11px; background: #fff; }
    .op-billing-party strong { display: block; overflow-wrap: anywhere; color: #27483f; font-size: 12px; }
    .op-billing-section-title { margin: 0 0 9px; color: #264b40; font-size: 11px; font-weight: 800; text-transform: uppercase; letter-spacing: .055em; }
    .op-billing-lines { overflow: hidden; margin-bottom: 16px; border: 1px solid #dbe7e2; border-radius: 11px; background: #fff; }
    .op-billing-line { display: flex; justify-content: space-between; gap: 20px; padding: 10px 13px; border-bottom: 1px solid #edf2f0; color: #526b64; font-size: 11px; }
    .op-billing-line:last-child { border-bottom: 0; }
    .op-billing-line strong { color: #294a40; white-space: nowrap; }
    .op-billing-line small { display: block; margin-top: 3px; color: #899a95; font-size: 9px; }
    .op-billing-line.subtotal { background: #f4f8f6; font-weight: 700; }
    .op-billing-line.total { padding-block: 13px; color: #174f40; background: #e4f1ec; font-size: 13px; font-weight: 800; }
    .op-billing-line.total strong { color: #124c3c; font-size: 16px; }
    .op-billing-stats { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
    .op-billing-stat { padding: 13px; border: 1px solid #dce7e3; border-radius: 10px; background: #fff; }
    .op-billing-stat strong { display: block; overflow-wrap: anywhere; color: #21483d; font-size: 13px; }
    .op-billing-stat.balance strong { color: #9b3d45; }
    .op-billing-stat.balance.paid strong { color: #176b4c; }
    .op-billing-footer { display: flex; justify-content: flex-end; gap: 9px; padding: 15px 22px; border-top: 1px solid #dce7e3; background: #fff; }
    .op-billing-footer button { min-height: 38px; padding: 9px 14px; border: 1px solid #cfdcd7; border-radius: 8px; color: #405b54; background: #fff; font: inherit; font-size: 11px; font-weight: 800; cursor: pointer; }
    .op-billing-footer .secondary { color: #1d654f; border-color: #cce1d9; background: #edf6f2; }
    .op-billing-footer .primary { color: #fff; border-color: #1f705a; background: #1f705a; }
    .op-billing-footer button:disabled { color: #899b95; border-color: transparent; background: #e4eae8; cursor: not-allowed; }
    .op-billing-error { padding: 55px 20px; color: #8a3e46; text-align: center; }
    .op-expense-card {
      width: min(520px, calc(100vw - 30px));
      overflow: hidden;
      padding: 0;
      border: 1px solid #d5e4de;
      border-radius: 16px;
      box-shadow: 0 24px 65px rgba(8, 42, 32, .24);
    }
    .op-expense-header {
      display: grid;
      grid-template-columns: 42px 1fr 32px;
      gap: 12px;
      align-items: center;
      padding: 18px 20px;
      border-bottom: 1px solid #dce8e3;
      background: linear-gradient(135deg, #f6fbf9, #eaf5f1);
    }
    .op-expense-header-icon {
      width: 42px;
      height: 42px;
      display: grid;
      place-items: center;
      color: #fff;
      border-radius: 11px;
      background: linear-gradient(135deg, #2b7a66, #1b604d);
      box-shadow: 0 7px 15px rgba(27, 96, 77, .2);
    }
    .op-expense-header-icon svg { width: 21px; height: 21px; fill: none; stroke: currentColor; stroke-width: 1.8; stroke-linecap: round; }
    .op-expense-header-copy small { display: block; margin-bottom: 2px; color: #618078; font-size: 8px; font-weight: 800; letter-spacing: .11em; }
    .op-expense-header-copy h3 { margin: 0; color: #173d32; font-size: 18px; }
    .op-expense-header-copy p { margin: 3px 0 0; color: #70827c; font-size: 10px; }
    .op-expense-close {
      width: 32px;
      height: 32px;
      display: grid;
      place-items: center;
      padding: 0;
      border: 0;
      border-radius: 8px;
      color: #55726a;
      background: transparent;
      font-size: 23px;
      cursor: pointer;
    }
    .op-expense-close:hover { color: #164f40; background: #dfeee8; }
    .op-expense-form { gap: 15px; padding: 20px; background: #fff; }
    .op-expense-form label { gap: 7px; color: #294d42; font-size: 11px; font-weight: 800; }
    .op-expense-form label > small { color: #879790; font-size: 9px; font-weight: 500; }
    .op-expense-form select,
    .op-expense-form input,
    .op-expense-form textarea {
      width: 100%;
      min-height: 42px;
      border: 1px solid #cfdfd9;
      border-radius: 10px;
      color: #26463d;
      background: #fbfdfc;
      transition: border-color .16s ease, box-shadow .16s ease, background .16s ease;
    }
    .op-expense-form select:focus,
    .op-expense-form input:focus,
    .op-expense-form textarea:focus {
      outline: 0;
      border-color: #438e78;
      background: #fff;
      box-shadow: 0 0 0 3px rgba(43, 122, 102, .12);
    }
    .op-expense-form textarea { min-height: 82px; }
    .op-expense-money { position: relative; display: block; }
    .op-expense-money > span {
      position: absolute;
      z-index: 1;
      left: 12px;
      top: 50%;
      color: #2b6f5c;
      font-size: 13px;
      font-weight: 800;
      transform: translateY(-50%);
    }
    .op-expense-form .op-expense-money input {
      padding-left: 36px;
      padding-right: 34px;
    }
    .op-expense-footer {
      margin: 2px -20px -20px;
      padding: 14px 20px;
      border-top: 1px solid #e2ebe7;
      background: #f9fbfa;
    }
    .op-expense-footer .ho-btn { min-height: 38px; padding: 9px 14px; border-radius: 9px; font-size: 11px; font-weight: 800; }
    .op-expense-footer .confirm { box-shadow: 0 5px 12px rgba(31, 112, 90, .16); }
    .op-payment-card {
      width: min(500px, calc(100vw - 30px));
      overflow: hidden;
      padding: 0;
      border: 1px solid #d5e4de;
      border-radius: 16px;
      box-shadow: 0 24px 65px rgba(8, 42, 32, .24);
    }
    .op-payment-header {
      display: grid;
      grid-template-columns: 42px 1fr 32px;
      gap: 12px;
      align-items: center;
      padding: 18px 20px;
      border-bottom: 1px solid #dce8e3;
      background: linear-gradient(135deg, #f6fbf9, #eaf5f1);
    }
    .op-payment-header-icon {
      width: 42px;
      height: 42px;
      display: grid;
      place-items: center;
      color: #fff;
      border-radius: 11px;
      background: linear-gradient(135deg, #2b7a66, #1b604d);
      box-shadow: 0 7px 15px rgba(27, 96, 77, .2);
    }
    .op-payment-header-icon svg { width: 21px; height: 21px; fill: none; stroke: currentColor; stroke-width: 1.8; }
    .op-payment-header-copy small { display: block; margin-bottom: 2px; color: #618078; font-size: 8px; font-weight: 800; letter-spacing: .11em; }
    .op-payment-header-copy h3 { margin: 0; color: #173d32; font-size: 19px; }
    .op-payment-header-copy p { margin: 3px 0 0; color: #70827c; font-size: 10px; }
    .op-payment-close { width: 32px; height: 32px; display: grid; place-items: center; padding: 0; border: 0; border-radius: 8px; color: #55726a; background: transparent; font-size: 23px; cursor: pointer; }
    .op-payment-close:hover { color: #164f40; background: #dfeee8; }
    .op-payment-form { gap: 15px; padding: 20px; }
    .op-payment-balance-callout { padding: 13px 14px; color: #244a40; border: 1px solid #dae6e2; border-radius: 10px; background: #f1f5f3; font-size: 12px; }
    .op-payment-balance-callout strong { margin-left: 4px; color: #124d3d; font-size: 15px; }
    .op-payment-method-note { margin: -5px 0 0; color: #698078; font-size: 10px; line-height: 1.5; }
    .op-qr-device { display: grid; grid-template-columns: 42px minmax(0,1fr) auto; align-items: center; gap: 12px; margin-top: -3px; padding: 12px; border: 1px solid #cfe2db; border-radius: 11px; background: linear-gradient(135deg,#f0f8f5,#fff); }
    .op-qr-device[hidden] { display: none !important; }
    .op-qr-device-icon { width: 42px; height: 42px; display: grid; place-items: center; border-radius: 10px; color: #fff; background: linear-gradient(135deg,#2b7a66,#1c5e4b); }
    .op-qr-device-icon svg,.op-phone-modal-icon svg { width: 20px; height: 20px; fill: none; stroke: currentColor; stroke-width: 1.8; stroke-linecap: round; stroke-linejoin: round; }
    .op-qr-device-copy { min-width: 0; }
    .op-qr-device-copy small { display: block; margin-bottom: 3px; color: #668078; font-size: 8px; font-weight: 850; letter-spacing: .09em; }
    .op-qr-device-copy strong { display: block; overflow: hidden; color: #214c40; font-size: 12px; text-overflow: ellipsis; white-space: nowrap; }
    .op-qr-device-copy span { display: block; margin-top: 3px; color: #71847e; font-size: 9px; line-height: 1.35; }
    .op-qr-device-action { min-height: 35px; padding: 7px 11px; border: 1px solid #bcd7cd; border-radius: 8px; color: #1f654f; background: #fff; font: inherit; font-size: 9px; font-weight: 850; cursor: pointer; white-space: nowrap; }
    .op-qr-device-action:disabled { cursor: wait; opacity: .6; }
    .op-phone-toolbar { display: inline-flex; align-items: center; gap: 7px; min-height: 40px; padding: 9px 13px; border: 1px solid #17604c; border-radius: 999px; color: #fff; background: linear-gradient(135deg,#278066,#17604c); font: inherit; font-size: 11px; font-weight: 800; cursor: pointer; box-shadow: 0 6px 14px rgba(23,96,76,.2); transition: transform .18s ease, box-shadow .18s ease, background .18s ease; }
    .op-phone-toolbar span { color: #fff; }
    .op-phone-toolbar svg { width: 16px; height: 16px; fill: none; stroke: currentColor; stroke-width: 1.8; }
    .op-phone-toolbar:hover { transform: translateY(-1px); background: linear-gradient(135deg,#226d59,#174e3f); box-shadow: 0 9px 19px rgba(27,91,71,.25); }
    .op-phone-toolbar:focus-visible { outline: 3px solid rgba(43,122,102,.25); outline-offset: 2px; }
    .op-phone-overlay { position: fixed; inset: 0; z-index: 100300; display: none; align-items: center; justify-content: center; padding: 22px; background: rgba(7,31,25,.68); backdrop-filter: blur(4px); }
    .op-phone-overlay.show { display: flex; }
    body > .swal2-container { z-index: 100500 !important; }
    .op-phone-modal { width: min(580px,100%); max-height: calc(100vh - 44px); display: flex; flex-direction: column; overflow: hidden; border: 1px solid #d3e3dd; border-radius: 17px; background: #fff; box-shadow: 0 28px 80px rgba(5,31,25,.3); }
    .op-phone-modal-head { display: grid; grid-template-columns: 48px 1fr 36px; align-items: center; gap: 14px; padding: 21px 23px; border-bottom: 1px solid #dbe7e2; background: linear-gradient(135deg,#f8fbfa,#eaf5f0); }
    .op-phone-modal-icon { width: 48px; height: 48px; display: grid; place-items: center; border-radius: 11px; color: #fff; background: #1f705a; }
    .op-phone-modal-head small { color: #668078; font-size: 9px; font-weight: 850; letter-spacing: .11em; }
    .op-phone-modal-head h3 { margin: 3px 0 4px; color: #173b32; font-size: 20px; }
    .op-phone-modal-head p { margin: 0; color: #687d76; font-size: 11px; line-height: 1.45; }
    .op-phone-modal-x { width: 34px; height: 34px; border: 0; border-radius: 8px; color: #59736b; background: transparent; font-size: 23px; cursor: pointer; }
    .op-phone-modal-body { overflow-y: auto; padding: 22px 24px; }
    .op-phone-current { display: grid; grid-template-columns: 44px minmax(0,1fr) auto; align-items: center; gap: 12px; margin-bottom: 19px; padding: 13px 14px; border: 1px solid #e0d7b8; border-radius: 12px; background: #fffaf0; }
    .op-phone-current.is-registered { border-color: #b9ddcf; background: linear-gradient(135deg,#edf8f3,#fff); }
    .op-phone-current .op-qr-device-icon { width: 44px; height: 44px; }
    .op-phone-current-state { padding: 6px 9px; border-radius: 999px; color: #826315; background: #f5e8bb; font-size: 9px; font-weight: 850; white-space: nowrap; }
    .op-phone-current.is-registered .op-phone-current-state { color: #176047; background: #dcefe7; }
    .op-phone-steps { display: grid; gap: 12px; margin: 0 0 20px; padding: 0; list-style: none; counter-reset: op-phone-step; }
    .op-phone-steps li { position: relative; min-height: 45px; padding: 6px 8px 6px 49px; counter-increment: op-phone-step; }
    .op-phone-steps li::before { content: counter(op-phone-step); position: absolute; left: 4px; top: 3px; width: 31px; height: 31px; display: grid; place-items: center; border-radius: 9px; color: #1d654f; background: #e4f2ed; font-size: 11px; font-weight: 900; }
    .op-phone-steps b { display: block; color: #2b4d43; font-size: 12px; }
    .op-phone-steps span { display: block; margin-top: 4px; color: #71847e; font-size: 10px; line-height: 1.45; }
    .op-phone-link > span { display: block; margin-bottom: 7px; color: #668078; font-size: 9px; font-weight: 850; letter-spacing: .08em; }
    .op-phone-link > div { display: flex; overflow: hidden; border: 1px solid #cdded8; border-radius: 9px; background: #f8faf9; }
    .op-phone-link input { min-width: 0; flex: 1; min-height: 44px; padding: 11px 12px; border: 0; outline: 0; color: #47645b; background: transparent; font-size: 11px; }
    .op-phone-link button { padding: 9px 13px; border: 0; border-left: 1px solid #cdded8; color: #1f654f; background: #eaf4f0; font-size: 10px; font-weight: 850; cursor: pointer; }
    .op-phone-status { display: flex; align-items: center; gap: 12px; margin-top: 17px; padding: 13px 14px; border: 1px solid #d7e5e0; border-radius: 10px; background: #f7faf9; }
    .op-phone-status > i { width: 12px; height: 12px; flex: 0 0 12px; border: 2px solid #72a895; border-top-color: transparent; border-radius: 50%; animation: opPaymentSpin .8s linear infinite; }
    .op-phone-status.success { border-color: #b9ddcf; background: #eaf7f1; }.op-phone-status.success > i { border: 0; background: #2b8a67; animation: none; }
    .op-phone-status.error { border-color: #ecc8cc; background: #fff2f3; }.op-phone-status.error > i { border: 0; background: #bd4c58; animation: none; }
    .op-phone-status strong { display: block; color: #315349; font-size: 11px; }.op-phone-status small { display: block; margin-top: 3px; color: #7a8d86; font-size: 10px; }
    .op-phone-modal-foot { display: flex; justify-content: flex-end; gap: 9px; padding: 15px 23px; border-top: 1px solid #dce7e3; }
    .op-phone-modal-foot button { min-height: 40px; padding: 9px 13px; border: 1px solid #cfdcd7; border-radius: 8px; color: #405b54; background: #fff; font: inherit; font-size: 11px; font-weight: 850; cursor: pointer; }
    .op-phone-modal-foot .primary { border-color: #1f705a; color: #fff; background: #1f705a; }
    .op-phone-overview { width: min(560px,100%); }
    .op-phone-overview .op-phone-modal-body { padding: 22px 24px; }
    .op-phone-overview .op-phone-current { margin: 0; }
    .op-phone-overview-change { min-height: 37px; padding: 8px 13px; border: 1px solid #bcd7cd; border-radius: 9px; color: #1f654f; background: #fff; font: inherit; font-size: 10px; font-weight: 850; cursor: pointer; white-space: nowrap; }
    .op-phone-overview-change:hover { border-color: #76ad99; background: #f1f8f5; }
    .op-payment-form label { gap: 7px; color: #294d42; font-size: 11px; font-weight: 800; }
    .op-payment-form select,
    .op-payment-form input { width: 100%; min-height: 44px; border: 1px solid #cfdfd9; border-radius: 10px; color: #26463d; background: #fff; }
    .op-payment-form select:focus,
    .op-payment-form input:focus { outline: 0; border-color: #438e78; box-shadow: 0 0 0 3px rgba(43, 122, 102, .12); }
    .op-payment-money { position: relative; display: block; }
    .op-payment-money > span { position: absolute; z-index: 1; left: 12px; top: 50%; color: #2b6f5c; font-size: 13px; font-weight: 800; transform: translateY(-50%); }
    .op-payment-form .op-payment-money input { padding-left: 36px; padding-right: 34px; }
    .op-payment-footer { margin: 2px -20px -20px; padding: 14px 20px; border-top: 1px solid #e2ebe7; background: #f9fbfa; }
    .op-payment-footer .ho-btn { min-height: 39px; padding: 9px 15px; border-radius: 9px; font-size: 11px; font-weight: 800; }
    .op-payment-footer .op-payment-submit { min-width: 142px; display: inline-flex; align-items: center; justify-content: center; gap: 8px; border-color: #176b55; color: #fff; background: linear-gradient(135deg,#278066,#17604c); box-shadow: 0 5px 12px rgba(31,112,90,.22); }
    .op-payment-footer .op-payment-submit:disabled { color: #fff; background: linear-gradient(135deg,#278066,#17604c); opacity: .9; }
    .op-payment-submit.is-loading { cursor: wait; }
    .op-payment-spinner { width: 15px; height: 15px; flex: 0 0 15px; display: inline-block; border: 2px solid rgba(255,255,255,.38); border-top-color: #fff; border-radius: 50%; animation: opPaymentSpin .65s linear infinite; }
    @keyframes opPaymentSpin { to { transform: rotate(360deg); } }
    .op-modal-form { display: grid; gap: 12px; }
    .op-modal-form label { display: grid; gap: 5px; color: #36525b; font-size: 13px; font-weight: 700; }
    .op-modal-form input, .op-modal-form select, .op-modal-form textarea { border: 1px solid #d8e6e0; border-radius: 8px; padding: 9px; font: inherit; }
    .op-modal-form textarea { min-height: 76px; resize: vertical; }
    .op-modal-actions { display: flex; justify-content: flex-end; gap: 8px; }
    #opWalkinBookingModal .ho-checkin-fields select,
    #opWalkinBookingModal .ho-walkin-partial-field select {
      width: 100%;
      min-height: 40px;
      border: 1px solid #d7e3df;
      border-radius: 10px;
      padding: 9px 11px;
      color: #183239;
      background: #fff;
      font: inherit;
      font-size: 12px;
    }
    #opWalkinBookingModal .ho-checkin-fields select:focus,
    #opWalkinBookingModal .ho-walkin-partial-field select:focus {
      outline: 0;
      border-color: #4b9983;
      box-shadow: 0 0 0 3px rgba(50, 139, 113, 0.11);
    }
    .walkin-pax-control {
      min-height: 42px;
      display: grid;
      grid-template-columns: 40px minmax(0, 1fr) 40px;
      overflow: hidden;
      border: 1px solid #d7e3df;
      border-radius: 10px;
      background: #fff;
    }
    .walkin-pax-control button {
      border: 0;
      background: #edf6f2;
      color: #1d6853;
      font: inherit;
      font-size: 18px;
      font-weight: 800;
      cursor: pointer;
    }
    .walkin-pax-control button:hover:not(:disabled) { background: #dceee7; }
    .walkin-pax-control button:disabled { color: #a6b5b0; cursor: not-allowed; }
    .ho-checkin-fields .walkin-pax-control input {
      width: 100%;
      border: 0;
      border-right: 1px solid #d7e3df;
      border-left: 1px solid #d7e3df;
      border-radius: 0;
      padding: 0;
      text-align: center;
      box-shadow: none;
    }
    #opWalkinEndDateField[hidden] { display: none; }
    @media (max-width: 900px) {
      .operator-header {
        position: static;
        width: auto;
        left: auto;
        border-radius: 14px;
      }
      .op-main {
        margin-left: 250px;
        padding-top: 18px;
      }
      .ho-toolbar {
        flex-wrap: wrap;
      }
      .ho-toolbar input[name="q"] {
        min-width: 100%;
      }
    }
    @media (max-width: 620px) { .op-qr-device { grid-template-columns: 42px minmax(0,1fr); }.op-qr-device-action { grid-column: 1/-1; width: 100%; }.op-phone-overlay { padding: 0; }.op-phone-modal { max-height: 100vh; border-radius: 0; }.op-phone-modal-foot { display: grid; grid-template-columns: 1fr 1fr; }.op-phone-modal-foot .primary { grid-column: 1/-1; } }
  </style>
  <link rel="stylesheet" href="styles/operator_header.css?v=5">
  <style>
    @media (min-width: 901px) {
      .op-main { padding-top: 80px; }
    }
  </style>
</head>
<body class="ho-body">
  <div class="op-layout">
    <?php include 'operator_sidebar.php'; ?>

    <main class="op-main">
      <header class="operator-header">
        <div class="operator-header-left">
          <span class="operator-header-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 10h18M8 14h.01M12 14h.01M16 14h.01"/></svg></span>
          <div class="operator-header-copy"><h2>Booking Management</h2><p>Welcome, <?= htmlspecialchars((string)$operatorName) ?></p></div>
        </div>
        <div class="operator-header-right">
          <form method="get" class="op-global-filter-form">
            <input type="hidden" name="status" value="<?= htmlspecialchars($statusFilter) ?>" />
            <input type="hidden" name="sort" value="<?= htmlspecialchars($sortBy) ?>" />
            <input type="hidden" name="q" value="<?= htmlspecialchars($search) ?>" />
            <input type="hidden" name="rows" value="<?= (int)$rowsPerPage ?>" />
            <label for="opRangeFilter" class="op-global-filter-label">Overview Filter</label>
            <select id="opRangeFilter" name="range" class="op-global-filter-select">
              <option value="all" <?= $rangeFilter === 'all' ? 'selected' : '' ?>>All</option>
              <option value="yearly" <?= $rangeFilter === 'yearly' ? 'selected' : '' ?>>Yearly</option>
              <option value="monthly" <?= $rangeFilter === 'monthly' ? 'selected' : '' ?>>Monthly</option>
              <option value="weekly" <?= $rangeFilter === 'weekly' ? 'selected' : '' ?>>Weekly</option>
              <option value="daily" <?= $rangeFilter === 'daily' ? 'selected' : '' ?>>Daily</option>
            </select>

            <select id="opRangeYear" name="year" class="op-global-filter-select">
              <?php foreach ($availableYears as $year): ?>
                <option value="<?= (int)$year ?>" <?= $selectedYear === (int)$year ? 'selected' : '' ?>><?= (int)$year ?></option>
              <?php endforeach; ?>
            </select>

            <select id="opRangeMonth" name="month" class="op-global-filter-select">
              <?php for ($m = 1; $m <= 12; $m++): ?>
                <option value="<?= $m ?>" <?= $selectedMonth === $m ? 'selected' : '' ?>><?= date('F', mktime(0, 0, 0, $m, 1)) ?></option>
              <?php endfor; ?>
            </select>

            <input id="opRangeDate" type="date" name="date" class="op-global-filter-select op-global-filter-date" value="<?= htmlspecialchars($selectedDate) ?>" />
            <button type="submit" class="op-global-filter-apply">Apply</button>
          </form>
          <div class="op-notif-wrap">
            <button type="button" class="op-notif-btn" id="opNotifToggle" aria-label="Notifications" aria-expanded="false">
              <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9M10 21h4"/></svg><span class="op-header-sr">Notifications</span>
              <?php if ($notificationCount > 0): ?>
                <span class="op-notif-badge"><?= $notificationCount ?></span>
              <?php endif; ?>
            </button>
            <div class="op-notif-panel" id="opNotifPanel">
              <h4>Recent Bookings</h4>
              <?php if (!empty($notificationItems)): ?>
                <ul class="op-notif-list">
                  <?php foreach ($notificationItems as $item): ?>
                    <li>
                      <strong><?= htmlspecialchars((string)($item['booking_reference'] ?: $item['booking_id'])) ?> - <?= htmlspecialchars((string)$item['package_name']) ?></strong>
                      <span><?= htmlspecialchars((string)$item['status']) ?> • <?= htmlspecialchars((string)$item['booking_date']) ?></span>
                      <small><?= htmlspecialchars((string)$item['created_at']) ?></small>
                    </li>
                  <?php endforeach; ?>
                </ul>
              <?php else: ?>
                <p class="op-notif-empty">No notifications yet.</p>
              <?php endif; ?>
            </div>
          </div>
          <div class="op-topbar-profile" title="<?= htmlspecialchars((string)$operatorName) ?>" data-operator-header-profile role="button" tabindex="0" aria-label="Open operator profile">
            <?php if ($opHeaderProfilePic): ?>
              <img src="<?= htmlspecialchars($opHeaderProfilePic) ?>" alt="<?= htmlspecialchars((string)$operatorName) ?>">
            <?php else: ?>
              <?= htmlspecialchars($opProfileInitial) ?>
            <?php endif; ?>
          </div>
        </div>
      </header>

      <section class="ho-content">
        <article class="ho-card ho-table-card">
          <div class="ho-table-head">
            <h2 class="ho-section-title">All Bookings</h2>
            <div class="ho-booking-head-actions">
              <div class="ho-booking-tabs" role="tablist" aria-label="Booking status quick tabs">
                <a href="opbookings.php?<?= htmlspecialchars(http_build_query(['status' => 'all', 'q' => $search, 'range' => $rangeFilter, 'year' => $selectedYear, 'month' => $selectedMonth, 'date' => $selectedDate, 'sort' => $sortBy, 'rows' => $rowsPerPage])) ?>" class="<?= $statusFilter === 'all' ? 'active' : '' ?>">All</a>
                <a href="opbookings.php?<?= htmlspecialchars(http_build_query(['status' => 'pending', 'q' => $search, 'range' => $rangeFilter, 'year' => $selectedYear, 'month' => $selectedMonth, 'date' => $selectedDate, 'sort' => $sortBy, 'rows' => $rowsPerPage])) ?>" class="<?= $statusFilter === 'pending' ? 'active' : '' ?>">Pending</a>
                <a href="opbookings.php?<?= htmlspecialchars(http_build_query(['status' => 'accepted', 'q' => $search, 'range' => $rangeFilter, 'year' => $selectedYear, 'month' => $selectedMonth, 'date' => $selectedDate, 'sort' => $sortBy, 'rows' => $rowsPerPage])) ?>" class="<?= $statusFilter === 'accepted' ? 'active' : '' ?>">Accepted</a>
                <a href="opbookings.php?<?= htmlspecialchars(http_build_query(['status' => 'cancelled', 'q' => $search, 'range' => $rangeFilter, 'year' => $selectedYear, 'month' => $selectedMonth, 'date' => $selectedDate, 'sort' => $sortBy, 'rows' => $rowsPerPage])) ?>" class="<?= $statusFilter === 'cancelled' ? 'active' : '' ?>">Cancelled</a>
              </div>
              <button type="button" class="op-phone-toolbar" id="opPaymentPhoneButton"><svg viewBox="0 0 24 24" aria-hidden="true"><rect x="6" y="2" width="12" height="20" rx="2"/><path d="M10 18h4"/></svg><span>Payment Phone</span></button>
              <button type="button" class="ho-add-booking-btn" id="opOpenWalkinBooking">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>
                <span>Add Booking</span>
              </button>
            </div>
          </div>

          <?php if (($_GET['booking_notice'] ?? '') === 'created'): ?>
            <div class="ho-banner success">Booking <?= htmlspecialchars((string)($_GET['booking_ref'] ?? '')) ?> was created successfully.</div>
          <?php endif; ?>

          <form method="get" class="ho-toolbar">
            <input type="hidden" name="range" value="<?= htmlspecialchars($rangeFilter) ?>" />
            <input type="hidden" name="year" value="<?= (int)$selectedYear ?>" />
            <input type="hidden" name="month" value="<?= (int)$selectedMonth ?>" />
            <input type="hidden" name="date" value="<?= htmlspecialchars($selectedDate) ?>" />
            <span class="ho-rows-label">Rows</span>
            <input type="number" name="rows" min="1" max="300" step="1" value="<?= (int)$rowsPerPage ?>" list="hoRowsOptions" placeholder="25" title="Rows count" />
            <datalist id="hoRowsOptions">
              <option value="25"></option>
              <option value="50"></option>
              <option value="100"></option>
              <option value="150"></option>
              <option value="200"></option>
              <option value="250"></option>
              <option value="300"></option>
            </datalist>
            <select name="status">
              <option value="all" <?= $statusFilter === 'all' ? 'selected' : '' ?>>All Statuses</option>
              <option value="pending" <?= $statusFilter === 'pending' ? 'selected' : '' ?>>Pending</option>
              <option value="accepted" <?= $statusFilter === 'accepted' ? 'selected' : '' ?>>Accepted</option>
              <option value="cancelled" <?= $statusFilter === 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
            </select>
            <select name="sort">
              <option value="time" <?= $sortBy === 'time' ? 'selected' : '' ?>>Sort: Latest (Default)</option>
              <option value="name" <?= $sortBy === 'name' ? 'selected' : '' ?>>Sort: Name (A-Z)</option>
            </select>
            <button type="submit" class="ho-btn ho-filter-apply">Apply Filter</button>
            <input type="text" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="Search by guest name, package, or booking ID" />
          </form>

          <?php if ($bookings): ?>
            <div class="ho-table-wrap">
              <table class="ho-table op-bookings-table">
                <thead>
                  <tr>
                    <th>Tourist</th>
                    <th>ID</th>
                    <th>Package</th>
                    <th>Pax</th>
                    <th>Date</th>
                    <th>Payment</th>
                    <th>Status</th>
                    <th>Actions</th>
                  </tr>
                </thead>
                <tbody>
                    <?php foreach ($bookings as $row): ?>
                    <?php $status = strtolower((string)$row['status']); ?>
                    <?php
                      $remainingBalance = max((float)($row['remaining_balance'] ?? 0), 0);
                      $isPaid = (int)($row['is_paid'] ?? 0) === 1 || $remainingBalance <= 0;
                      $paymentLabel = $isPaid ? 'Paid' : ((float)($row['payment_amount'] ?? 0) > 0 ? 'Partial' : 'Unpaid');
                      $paymentClass = $isPaid ? 'paid' : ($paymentLabel === 'Partial' ? 'partial' : 'unpaid');
                    ?>
                    <?php
                      $profilePath = OpResolveBookerProfileImage($row['tourist_profile_picture'] ?? null);
                      if ($profilePath === '') {
                        $profilePath = OpBuildGoogleAvatarUrl($row['tourist_google_id'] ?? null);
                      }
                      $hasAvatar = $profilePath !== '';
                      $guestName = (string)$row['guest_name'];
                      $guestInitial = strtoupper(substr(trim($guestName) !== '' ? trim($guestName) : 'G', 0, 1));
                      $guestNameEsc = htmlspecialchars($guestName);
                      $guestEmailEsc = htmlspecialchars((string)$row['guest_email']);
                      $guestPhoneEsc = htmlspecialchars((string)($row['phone_number'] ?: '-'));
                      $guestAddressEsc = htmlspecialchars((string)($row['tourist_address'] ?: '-'));
                      $createdTimestamp = strtotime((string)($row['created_at'] ?? ''));
                      $createdMonth = $createdTimestamp ? date('F Y', $createdTimestamp) : '';
                      $adultCount = max(0, (int)($row['num_adults'] ?? 0));
                      $childCount = max(0, (int)($row['num_children'] ?? 0));
                      $guestCount = max(0, (int)$row['pax']);
                      if ($adultCount + $childCount === 0 && $guestCount > 0) $adultCount = $guestCount;
                      $guestLabel = $guestCount === 1 ? 'guest' : 'guests';
                      $adultLabel = $adultCount === 1 ? 'adult' : 'adults';
                      $childLabel = $childCount === 1 ? 'child' : 'children';
                    ?>
                    <tr class="ho-booking-row op-booking-row">
                      <td>
                        <div class="ho-booker-cell">
                          <div class="ho-booker-avatar-wrap">
                            <?php if ($hasAvatar): ?>
                              <img
                                src="<?= htmlspecialchars($profilePath) ?>"
                                alt="<?= $guestNameEsc ?> profile"
                                class="ho-booker-avatar ho-profile-trigger"
                                loading="lazy"
                                decoding="async"
                                referrerpolicy="no-referrer"
                                onerror="this.onerror=null;this.src='img/profileicon2.png';"
                                role="button"
                                tabindex="0"
                                aria-label="View full profile for <?= $guestNameEsc ?>"
                                data-profile-src="<?= htmlspecialchars($profilePath) ?>"
                                data-tourist-id="<?= htmlspecialchars((string)($row['tourist_id'] ?? '')) ?>"
                                data-fullname="<?= $guestNameEsc ?>"
                                data-email="<?= $guestEmailEsc ?>"
                                data-phone="<?= $guestPhoneEsc ?>"
                                data-address="<?= $guestAddressEsc ?>"
                                data-total-bookings="<?= (int)($row['tourist_total_bookings'] ?? 0) ?>"
                                data-completed-bookings="<?= (int)($row['tourist_completed_bookings'] ?? 0) ?>"
                                data-account-status="<?= htmlspecialchars((string)($row['tourist_account_status'] ?? 'Guest')) ?>"
                                data-email-verified="<?= (int)($row['tourist_email_verified'] ?? 0) ?>"
                                data-google-connected="<?= !empty($row['tourist_google_id']) ? '1' : '0' ?>"
                                data-created-at="<?= htmlspecialchars((string)($row['tourist_created_at'] ?? '')) ?>"
                                data-updated-at="<?= htmlspecialchars((string)($row['tourist_updated_at'] ?? '')) ?>"
                              />
                            <?php else: ?>
                              <div
                                class="ho-booker-avatar ho-booker-avatar-initial ho-profile-trigger"
                                role="button"
                                tabindex="0"
                                aria-label="View full profile for <?= $guestNameEsc ?>"
                                data-profile-src="img/profileicon2.png"
                                data-tourist-id="<?= htmlspecialchars((string)($row['tourist_id'] ?? '')) ?>"
                                data-fullname="<?= $guestNameEsc ?>"
                                data-email="<?= $guestEmailEsc ?>"
                                data-phone="<?= $guestPhoneEsc ?>"
                                data-address="<?= $guestAddressEsc ?>"
                                data-total-bookings="<?= (int)($row['tourist_total_bookings'] ?? 0) ?>"
                                data-completed-bookings="<?= (int)($row['tourist_completed_bookings'] ?? 0) ?>"
                                data-account-status="<?= htmlspecialchars((string)($row['tourist_account_status'] ?? 'Guest')) ?>"
                                data-email-verified="<?= (int)($row['tourist_email_verified'] ?? 0) ?>"
                                data-google-connected="<?= !empty($row['tourist_google_id']) ? '1' : '0' ?>"
                                data-created-at="<?= htmlspecialchars((string)($row['tourist_created_at'] ?? '')) ?>"
                                data-updated-at="<?= htmlspecialchars((string)($row['tourist_updated_at'] ?? '')) ?>"
                              ><?= htmlspecialchars($guestInitial) ?></div>
                            <?php endif; ?>
                          </div>
                          <div class="op-booker-copy">
                            <div class="op-booker-name-row">
                              <span class="op-booker-name"><?= $guestNameEsc ?></span>
                              <?php if ($createdMonth !== ''): ?><span class="op-booker-month"><?= htmlspecialchars($createdMonth) ?></span><?php endif; ?>
                            </div>
                            <small class="op-booker-email"><?= $guestEmailEsc ?></small>
                          </div>
                        </div>
                      </td>
                      <td class="ho-cell-center"><span class="ho-booking-id"><?= htmlspecialchars((string)($row['booking_reference'] ?: $row['booking_id'])) ?></span></td>
                      <td class="ho-cell-center"><span class="op-package-chip" title="<?= htmlspecialchars((string)$row['package_name']) ?>"><?= htmlspecialchars((string)$row['package_name']) ?></span></td>
                      <td class="ho-cell-center ho-pax-cell">
                        <div class="ho-pax-summary" aria-label="<?= $guestCount ?> <?= $guestLabel ?>: <?= $adultCount ?> <?= $adultLabel ?> and <?= $childCount ?> <?= $childLabel ?>">
                          <span class="ho-pax-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M16 20v-1.5a4.5 4.5 0 0 0-4.5-4.5h-3A4.5 4.5 0 0 0 4 18.5V20"/><circle cx="10" cy="7" r="3.5"/><path d="M16 4.4a3.5 3.5 0 0 1 0 6.7M18 14.2a4.5 4.5 0 0 1 2 3.8v2"/></svg></span>
                          <span class="ho-pax-copy">
                            <span class="ho-pax-total"><strong><?= $guestCount ?></strong> <?= $guestLabel ?></span>
                            <span class="ho-pax-breakdown"><b><?= $adultCount ?></b> <?= $adultLabel ?><i></i><b><?= $childCount ?></b> <?= $childLabel ?></span>
                          </span>
                        </div>
                      </td>
                      <td class="ho-cell-center"><span class="op-booking-date"><?= htmlspecialchars((string)$row['booking_date']) ?></span></td>
                      <td class="ho-cell-center">
                        <div class="op-payment <?= $isPaid ? 'paid' : '' ?>">
                          <span class="op-payment-badge <?= htmlspecialchars($paymentClass) ?>"><?= htmlspecialchars($paymentLabel) ?></span>
                          <small><?= $isPaid ? 'Fully settled' : 'Balance: ₱' . number_format($remainingBalance, 2) ?></small>
                        </div>
                      </td>
                      <td class="ho-cell-center"><span class="ho-status <?= htmlspecialchars($status) ?>"><?= htmlspecialchars(ucfirst($status)) ?></span></td>
                      <td>
                        <div class="ho-row-actions" data-row-actions>
                          <button type="button" class="ho-btn ho-row-actions-trigger" data-row-actions-trigger aria-haspopup="menu" aria-expanded="false">Actions</button>
                          <div class="ho-actions ho-actions-center ho-row-actions-menu" data-row-actions-menu role="menu">
                          <button
                            type="button"
                            class="ho-btn"
                            data-view
                            data-booking='<?= htmlspecialchars(json_encode([
                              'id' => (string)($row['booking_reference'] ?: $row['booking_id']),
                              'guest' => (string)$row['guest_name'],
                              'email' => (string)$row['guest_email'],
                              'profile_image' => $profilePath,
                              'package' => (string)$row['package_name'],
                              'date' => (string)$row['booking_date'],
                              'phone' => (string)$row['phone_number'],
                              'location' => (string)$row['location'],
                              'pax' => (int)$row['pax'],
                              'booking_type' => (string)$row['booking_type'],
                              'jump_off_port' => (string)$row['jump_off_port'],
                              'status' => (string)$row['status'],
                              'completion' => (string)$row['is_complete'],
                              'grand_total' => (float)$row['grand_total'],
                              'payment_amount' => (float)$row['payment_amount'],
                              'remaining_balance' => $remainingBalance,
                              'payment_method' => (string)$row['payment_method'],
                              'created_at' => (string)$row['created_at'],
                            ]), ENT_QUOTES) ?>'
                          >View Details</button>

                          <?php if ($status !== 'accepted'): ?>
                            <form method="post">
                              <input type="hidden" name="action" value="confirm" />
                              <input type="hidden" name="booking_id" value="<?= (int)$row['booking_id'] ?>" />
                              <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($operatorBookingCsrf, ENT_QUOTES, 'UTF-8') ?>" />
                              <button type="submit" class="ho-btn confirm">Accept</button>
                            </form>
                          <?php endif; ?>

                          <?php if ($status !== 'cancelled'): ?>
                            <form method="post" data-cancel-form>
                              <input type="hidden" name="action" value="cancel" />
                              <input type="hidden" name="booking_id" value="<?= (int)$row['booking_id'] ?>" />
                              <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($operatorBookingCsrf, ENT_QUOTES, 'UTF-8') ?>" />
                              <button type="submit" class="ho-btn cancel">Cancel</button>
                            </form>
                          <?php endif; ?>

                          <?php if ($status === 'accepted' && $remainingBalance > 0): ?>
                            <button type="button" class="ho-btn op-action-payment" data-payment data-id="<?= (int)$row['booking_id'] ?>" data-balance="<?= htmlspecialchars((string)$remainingBalance) ?>">Record Payment</button>
                          <?php endif; ?>

                          <button type="button" class="ho-btn op-action-billing" data-billing data-id="<?= (int)$row['booking_id'] ?>">Billing</button>

                          <?php if ($status === 'accepted' && strtolower((string)$row['is_complete']) !== 'completed'): ?>
                            <button type="button" class="ho-btn op-action-complete" data-complete data-id="<?= (int)$row['booking_id'] ?>" data-balance="<?= htmlspecialchars((string)$remainingBalance) ?>">Mark Completed</button>
                          <?php endif; ?>
                          </div>
                        </div>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php else: ?>
            <div class="ho-empty">No bookings found for the selected filter/search.</div>
          <?php endif; ?>

          <p class="ho-footnote">
            Showing <?= number_format(count($bookings)) ?> of <?= number_format($totalFilteredBookings) ?> bookings.
          </p>
        </article>
      </section>
    </main>
  </div>

  <div class="ho-modal ho-checkin-flow-modal" id="opWalkinBookingModal" aria-hidden="true">
    <div class="ho-modal-card ho-checkin-flow-card ho-walkin-booking-card" role="dialog" aria-modal="true" aria-labelledby="opWalkinBookingTitle">
      <header class="ho-checkin-flow-header">
        <span class="ho-walkin-title-icon" aria-hidden="true">
          <svg viewBox="0 0 24 24"><path d="M8 2v3M16 2v3M3.5 9h17M5 4h14a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2Z"></path><path d="M12 12v6M9 15h6"></path></svg>
        </span>
        <div>
          <span class="ho-checkin-eyebrow">Operator-assisted reservation</span>
          <h3 id="opWalkinBookingTitle">Add a tour package booking</h3>
          <p>Create an accepted booking using one of your own packages.</p>
        </div>
        <button type="button" class="ho-checkin-close" data-close-op-walkin aria-label="Close add booking">&times;</button>
      </header>

      <div class="ho-checkin-stepper" aria-label="New booking progress">
        <div class="ho-checkin-step active" data-op-walkin-step-indicator="1" data-checkin-step-indicator="1">
          <span class="ho-checkin-step-circle">1</span>
          <div><strong>Guest</strong><small>Account and contact</small></div>
        </div>
        <div class="ho-checkin-step" data-op-walkin-step-indicator="2" data-checkin-step-indicator="2">
          <span class="ho-checkin-step-circle">2</span>
          <div><strong>Package</strong><small>Tour and guests</small></div>
        </div>
        <div class="ho-checkin-step" data-op-walkin-step-indicator="3" data-checkin-step-indicator="3">
          <span class="ho-checkin-step-circle">3</span>
          <div><strong>Payment</strong><small>Review and create</small></div>
        </div>
      </div>

      <form method="post" class="ho-checkin-flow-form" id="opWalkinBookingForm" data-step="1" data-required-fields>
        <input type="hidden" name="action" value="create_operator_walkin_booking" />
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($opWalkinCsrf) ?>" />

        <section class="ho-checkin-panel active" data-op-walkin-panel="1">
          <div class="ho-checkin-section-head">
            <div>
              <span class="ho-checkin-section-kicker">Step 1 of 3</span>
              <h4>Guest information</h4>
              <p>Select a registered tourist or create an account for a new walk-in guest.</p>
            </div>
            <span class="ho-checkin-secure">Staff assisted</span>
          </div>

          <?php if ($walkinError !== ''): ?>
            <div class="ho-walkin-error" role="alert">
              <span>!</span>
              <p><strong>Booking not created</strong><?= htmlspecialchars($walkinError) ?></p>
            </div>
          <?php endif; ?>

          <p class="required-choice-title" data-required-label>Guest account type</p>
          <div class="ho-walkin-guest-mode" role="radiogroup" aria-label="Guest account type">
            <label>
              <input type="radio" name="guest_mode" value="existing" <?= (($walkinForm['guest_mode'] ?? 'existing') === 'existing') ? 'checked' : '' ?> />
              <span><strong>Existing tourist</strong><small>Attach to their account</small></span>
            </label>
            <label>
              <input type="radio" name="guest_mode" value="new" <?= (($walkinForm['guest_mode'] ?? '') === 'new') ? 'checked' : '' ?> />
              <span><strong>New walk-in guest</strong><small>Create a tourist account</small></span>
            </label>
          </div>

          <div class="ho-walkin-existing-guest" id="opWalkinExistingGuest">
            <label class="ho-walkin-tourist-search-label" for="opWalkinTouristSearch" data-required-label>Search tourist account</label>
            <div class="ho-walkin-tourist-search">
              <svg aria-hidden="true" viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"></circle><path d="m16 16 4 4"></path></svg>
              <input type="search" id="opWalkinTouristSearch" placeholder="Search by name, email, or phone number" autocomplete="off" />
              <span class="ho-walkin-search-spinner" id="opWalkinSearchSpinner" hidden></span>
            </div>
            <input type="hidden" name="tourist_id" id="opWalkinTouristId" />
            <div class="ho-walkin-tourist-results" id="opWalkinTouristResults" hidden></div>
            <div class="ho-walkin-selected-tourist" id="opWalkinSelectedTourist" hidden>
              <span class="ho-walkin-tourist-avatar" id="opWalkinTouristAvatar"><b>T</b></span>
              <div>
                <small>Selected tourist account</small>
                <strong id="opWalkinTouristName">Tourist</strong>
                <span id="opWalkinTouristEmail"></span>
              </div>
              <button type="button" id="opWalkinChangeTourist">Change</button>
            </div>
            <p class="ho-walkin-search-help" id="opWalkinSearchHelp">Type at least two characters to find an active tourist account.</p>
          </div>

          <div class="ho-walkin-new-guest" id="opWalkinNewGuest" hidden>
            <div class="ho-checkin-fields two">
              <label>Full name
                <input type="text" name="guest_name" maxlength="150" value="<?= htmlspecialchars((string)($walkinForm['guest_name'] ?? '')) ?>" placeholder="Guest's complete name" />
              </label>
              <label>Email address
                <input type="email" name="guest_email" maxlength="190" value="<?= htmlspecialchars((string)($walkinForm['guest_email'] ?? '')) ?>" placeholder="guest@example.com" />
              </label>
            </div>
            <div class="ho-checkin-fields one">
              <label>Home address <span>(optional)</span>
                <input type="text" name="guest_address" maxlength="255" value="<?= htmlspecialchars((string)($walkinForm['guest_address'] ?? '')) ?>" placeholder="Street, barangay, municipality" />
              </label>
            </div>
          </div>

          <div class="ho-checkin-fields one">
            <label>Booking contact number
              <input type="tel" name="phone_number" id="opWalkinPhone" maxlength="20" value="<?= htmlspecialchars((string)($walkinForm['phone_number'] ?? '')) ?>" placeholder="09XX XXX XXXX" required />
            </label>
          </div>

          <footer class="ho-checkin-footer">
            <p class="required-step-note"><span class="required-mark" aria-hidden="true">*</span><span>indicates a required field.</span></p>
            <div class="required-footer-actions">
              <button type="button" class="ho-btn cancel" data-close-op-walkin>Cancel</button>
              <button type="button" class="ho-btn confirm" data-op-walkin-next>Continue to package</button>
            </div>
          </footer>
        </section>

        <section class="ho-checkin-panel" data-op-walkin-panel="2" hidden>
          <div class="ho-checkin-section-head">
            <div>
              <span class="ho-checkin-section-kicker">Step 2 of 3</span>
              <h4>Package and trip details</h4>
              <p>Only packages assigned to your operator account are available.</p>
            </div>
          </div>

          <?php if ($walkInPackages): ?>
            <div class="ho-walkin-room-field">
              <label>Tour package
                <select name="package_id" id="opWalkinPackage" required>
                  <option value="">Select one of your packages</option>
                  <?php foreach ($walkInPackages as $package): ?>
                    <option
                      value="<?= (int)$package['package_id'] ?>"
                      data-name="<?= htmlspecialchars((string)$package['package_title']) ?>"
                      data-price="<?= htmlspecialchars(number_format((float)$package['price'], 2, '.', '')) ?>"
                      data-tour-type="<?= htmlspecialchars((string)$package['package_type']) ?>"
                      data-range="<?= htmlspecialchars((string)$package['package_range']) ?>"
                      <?= (int)($walkinForm['package_id'] ?? 0) === (int)$package['package_id'] ? 'selected' : '' ?>
                    ><?= htmlspecialchars((string)$package['package_title']) ?> — ₱<?= number_format((float)$package['price'], 2) ?>/pax</option>
                  <?php endforeach; ?>
                </select>
              </label>
              <div class="ho-walkin-room-price"><small>Rate per guest</small><strong id="opWalkinPackageRate">₱0.00</strong></div>
            </div>

            <div class="ho-checkin-fields two">
              <label>Tour date
                <input type="date" name="booking_date" id="opWalkinBookingDate" min="<?= htmlspecialchars(date('Y-m-d')) ?>" value="<?= htmlspecialchars((string)($walkinForm['booking_date'] ?? '')) ?>" required />
              </label>
              <label id="opWalkinEndDateField" hidden>Tour end date
                <input type="date" name="booking_end_date" id="opWalkinBookingEndDate" min="<?= htmlspecialchars(date('Y-m-d', strtotime('+1 day'))) ?>" value="<?= htmlspecialchars((string)($walkinForm['booking_end_date'] ?? '')) ?>" />
              </label>
              <label>Jump-off port
                <select name="jump_off_port" required>
                  <option value="">Select jump-off port</option>
                  <option value="Mercedes Port" <?= ($walkinForm['jump_off_port'] ?? '') === 'Mercedes Port' ? 'selected' : '' ?>>Mercedes Port</option>
                  <option value="Cayucyucan" <?= ($walkinForm['jump_off_port'] ?? '') === 'Cayucyucan' ? 'selected' : '' ?>>Cayucyucan</option>
                </select>
              </label>
            </div>
            <div class="ho-checkin-fields two">
              <label>Adults
                <div class="walkin-pax-control">
                  <button type="button" data-op-pax-action="decrease" data-op-pax-target="opWalkinAdults" aria-label="Remove one adult">−</button>
                  <input type="number" name="num_adults" id="opWalkinAdults" min="1" max="100" value="<?= max(1, (int)($walkinForm['num_adults'] ?? 1)) ?>" required readonly />
                  <button type="button" data-op-pax-action="increase" data-op-pax-target="opWalkinAdults" aria-label="Add one adult">+</button>
                </div>
              </label>
              <label>Children
                <div class="walkin-pax-control">
                  <button type="button" data-op-pax-action="decrease" data-op-pax-target="opWalkinChildren" aria-label="Remove one child">−</button>
                  <input type="number" name="num_children" id="opWalkinChildren" min="0" max="99" value="<?= max(0, (int)($walkinForm['num_children'] ?? 0)) ?>" readonly />
                  <button type="button" data-op-pax-action="increase" data-op-pax-target="opWalkinChildren" aria-label="Add one child">+</button>
                </div>
              </label>
            </div>
            <div class="ho-walkin-info-note"><span aria-hidden="true">i</span><p id="opWalkinPackageNote">Select a package to see its schedule type and price.</p></div>
          <?php else: ?>
            <div class="ho-walkin-empty">You do not have any tour packages available. Add a package before creating a booking.</div>
          <?php endif; ?>

          <footer class="ho-checkin-footer">
            <p class="required-step-note"><span class="required-mark" aria-hidden="true">*</span><span>indicates a required field.</span></p>
            <div class="required-footer-actions">
              <button type="button" class="ho-btn" data-op-walkin-prev>Back</button>
              <button type="button" class="ho-btn confirm" data-op-walkin-next <?= $walkInPackages ? '' : 'disabled' ?>>Review payment</button>
            </div>
          </footer>
        </section>

        <section class="ho-checkin-panel" data-op-walkin-panel="3" hidden>
          <div class="ho-checkin-section-head">
            <div>
              <span class="ho-checkin-section-kicker">Step 3 of 3</span>
              <h4>Payment and confirmation</h4>
              <p>Review the package total and record any payment collected now.</p>
            </div>
            <span class="ho-checkin-secure">Accepted booking</span>
          </div>

          <div class="ho-checkin-finance-grid">
            <div><small>Package subtotal</small><strong id="opWalkinServiceTotal">₱0.00</strong></div>
            <div><small>Additional fees</small><strong id="opWalkinFeesTotal">₱0.00</strong></div>
            <div class="balance"><small>Grand total</small><strong id="opWalkinGrandTotal">₱0.00</strong></div>
          </div>

          <div class="ho-checkin-fields two">
            <label>Environmental fee
              <div class="ho-checkin-money-input"><span>₱</span><input type="number" name="expense_environmental" class="op-walkin-expense" min="0" step="0.01" value="<?= htmlspecialchars((string)($walkinForm['expense_environmental'] ?? '0.00')) ?>" /></div>
            </label>
            <label>Entrance fee
              <div class="ho-checkin-money-input"><span>₱</span><input type="number" name="expense_entrance" class="op-walkin-expense" min="0" step="0.01" value="<?= htmlspecialchars((string)($walkinForm['expense_entrance'] ?? '0.00')) ?>" /></div>
            </label>
            <label>Docking / landing fee
              <div class="ho-checkin-money-input"><span>₱</span><input type="number" name="expense_docking" class="op-walkin-expense" min="0" step="0.01" value="<?= htmlspecialchars((string)($walkinForm['expense_docking'] ?? '0.00')) ?>" /></div>
            </label>
            <label>Other fee
              <div class="ho-checkin-money-input"><span>₱</span><input type="number" name="expense_other" class="op-walkin-expense" min="0" step="0.01" value="<?= htmlspecialchars((string)($walkinForm['expense_other'] ?? '0.00')) ?>" /></div>
            </label>
          </div>

          <div class="ho-walkin-payment-layout">
            <div>
              <h5 data-required-label>Payment received</h5>
              <div class="ho-walkin-payment-options">
                <label><input type="radio" name="payment_option" value="full" <?= ($walkinForm['payment_option'] ?? '') === 'full' ? 'checked' : '' ?> /><span><strong>Paid in full</strong><small>Collect the complete total</small></span></label>
                <label><input type="radio" name="payment_option" value="partial" <?= !isset($walkinForm['payment_option']) || ($walkinForm['payment_option'] ?? '') === 'partial' ? 'checked' : '' ?> /><span><strong>Partial payment</strong><small>Record a deposit collected now</small></span></label>
                <label><input type="radio" name="payment_option" value="unpaid" <?= ($walkinForm['payment_option'] ?? '') === 'unpaid' ? 'checked' : '' ?> /><span><strong>No payment yet</strong><small>Keep the full amount as balance</small></span></label>
              </div>
              <label class="ho-walkin-partial-field" id="opWalkinPartialField">Amount received
                <div class="ho-checkin-money-input"><span>₱</span><input type="number" name="payment_amount" id="opWalkinPaymentAmount" min="0.01" step="0.01" value="<?= htmlspecialchars((string)($walkinForm['payment_amount'] ?? '')) ?>" /></div>
              </label>
              <label class="ho-walkin-partial-field">Payment method
                <select name="payment_method" id="opWalkinPaymentMethod">
                  <option value="">Select payment method</option>
                  <option value="cash" <?= ($walkinForm['payment_method'] ?? '') === 'cash' ? 'selected' : '' ?>>Cash</option>
                  <option value="gcash" <?= ($walkinForm['payment_method'] ?? '') === 'gcash' ? 'selected' : '' ?>>GCash</option>
                  <option value="bank_transfer" <?= ($walkinForm['payment_method'] ?? '') === 'bank_transfer' ? 'selected' : '' ?>>Bank transfer</option>
                </select>
              </label>
            </div>
            <div class="ho-checkin-summary-box ho-walkin-summary">
              <h5>Booking summary</h5>
              <dl>
                <div><dt>Guest</dt><dd id="opWalkinSummaryGuest">—</dd></div>
                <div><dt>Package</dt><dd id="opWalkinSummaryPackage">—</dd></div>
                <div><dt>Schedule</dt><dd id="opWalkinSummaryDates">—</dd></div>
                <div><dt>Guests</dt><dd id="opWalkinSummaryGuests">—</dd></div>
                <div><dt>Paid now</dt><dd id="opWalkinSummaryPaid">₱0.00</dd></div>
                <div><dt>Balance</dt><dd id="opWalkinSummaryBalance">₱0.00</dd></div>
              </dl>
            </div>
          </div>

          <div class="ho-checkin-confirm-note">
            <span aria-hidden="true">i</span>
            <p><strong>Ready to create</strong> The booking will be saved as accepted and attached to this operator account.</p>
          </div>

          <footer class="ho-checkin-footer">
            <p class="required-step-note"><span class="required-mark" aria-hidden="true">*</span><span>indicates a required field.</span></p>
            <div class="required-footer-actions">
              <button type="button" class="ho-btn" data-op-walkin-prev>Back</button>
              <button type="submit" class="ho-btn confirm">Create accepted booking</button>
            </div>
          </footer>
        </section>
      </form>
    </div>
  </div>

  <div class="ho-booking-details-overlay" id="hoDetailsModal" aria-hidden="true">
    <aside class="ho-booking-details-drawer" role="dialog" aria-modal="true" aria-labelledby="opBookingDetailsTitle">
      <header class="ho-booking-details-header">
        <div class="ho-booking-drawer-brand">
          <img src="img/newlogo.png" alt="" />
          <div>
            <span>ITOUR MERCEDES</span>
            <h3 id="opBookingDetailsTitle">Booking Details</h3>
          </div>
        </div>
        <button type="button" class="ho-booking-details-close" id="hoCloseModal" aria-label="Close booking details">
          <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m6 6 12 12M18 6 6 18"/></svg>
        </button>
      </header>
      <div class="ho-booking-details-body" id="hoDetailGrid"></div>
      <footer class="ho-booking-details-footer">
        <button type="button" class="ho-booking-details-close-btn" id="opCloseDetailsFooter">Close Details</button>
      </footer>
    </aside>
  </div>

  <div id="opTouristProfileDrawer" class="ho-tourist-profile-overlay" aria-hidden="true">
    <aside class="ho-tourist-profile-drawer" role="dialog" aria-modal="true" aria-labelledby="opTouristProfileTitle">
      <header class="ho-tourist-profile-header">
        <div class="ho-tourist-profile-brand">
          <img src="img/newlogo.png" alt="" />
          <div>
            <span>ITOUR MERCEDES</span>
            <h3 id="opTouristProfileTitle">Tourist Profile</h3>
          </div>
        </div>
        <button type="button" class="ho-tourist-profile-close" aria-label="Close tourist profile">
          <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m6 6 12 12M18 6 6 18"/></svg>
        </button>
      </header>
      <div class="ho-tourist-profile-body" id="opTouristProfileContent"></div>
      <footer class="ho-tourist-profile-footer">
        <button type="button" class="ho-tourist-profile-close-btn">Close Profile</button>
      </footer>
    </aside>
  </div>

  <div class="op-billing-overlay" id="opBillingModal" aria-hidden="true">
    <section class="op-billing-modal" role="dialog" aria-modal="true" aria-labelledby="opBillingTitle">
      <header class="op-billing-header">
        <span class="op-billing-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="16" rx="2"/><path d="M7 9h10M7 13h6M7 17h4"/></svg></span>
        <div>
          <span>BOOKING ACCOUNT</span>
          <h3 id="opBillingTitle">Billing Details</h3>
          <p id="opBillingSubtitle">Current charges and payment information</p>
        </div>
        <button type="button" class="op-billing-close-icon" data-close-billing aria-label="Close billing details">&times;</button>
      </header>
      <div class="op-billing-body" id="opBillingBody">
        <div class="op-billing-loading"><i aria-hidden="true"></i><span>Preparing billing statement…</span></div>
      </div>
      <footer class="op-billing-footer">
        <button type="button" data-close-billing>Close</button>
        <button type="button" class="secondary" id="opBillingAddExpense">Add Expense</button>
        <button type="button" class="primary" id="opBillingPayBalance">Pay Balance</button>
      </footer>
    </section>
  </div>

  <div class="ho-modal" id="opPaymentModal" aria-hidden="true">
    <div class="ho-modal-card op-payment-card" role="dialog" aria-modal="true" aria-labelledby="opPaymentTitle">
      <header class="op-payment-header">
        <span class="op-payment-header-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 10h18M7 15h4"/></svg></span>
        <div class="op-payment-header-copy"><small>PAYMENT COLLECTION</small><h3 id="opPaymentTitle">Pay Balance</h3><p>Record cash or create a secure PayMongo QR.</p></div>
        <button type="button" class="op-payment-close" data-close-modal="opPaymentModal" aria-label="Close pay balance">&times;</button>
      </header>
      <form id="opPaymentForm" class="op-modal-form op-payment-form">
        <input type="hidden" name="booking_id" id="opPaymentBookingId">
        <input type="hidden" name="complete_after_payment" id="opCompleteAfterPayment" value="">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($opPayMongoCsrf, ENT_QUOTES, 'UTF-8') ?>">
        <div class="op-payment-balance-callout">Remaining balance:<strong id="opPaymentInfo">₱0.00</strong></div>
        <label>Payment Method<select name="payment_method" id="opPaymentMethod" required><option value="" selected disabled>Select payment method</option><option value="cash">Cash</option><option value="qr_code">QR Code (PayMongo)</option></select></label>
        <p class="op-payment-method-note" id="opPaymentMethodNote">Choose how the tourist will settle this balance.</p>
        <section class="op-qr-device" id="opQrDevicePanel" hidden aria-live="polite">
          <span class="op-qr-device-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><rect x="6" y="2" width="12" height="20" rx="2"/><path d="M10 18h4"/></svg></span>
          <div class="op-qr-device-copy"><small>REGISTERED OPERATOR PHONE</small><strong id="opQrDeviceName">Checking registered phone...</strong><span id="opQrDeviceMeta">Please wait.</span></div>
          <button type="button" class="op-qr-device-action" id="opManagePhoneButton">Register a Phone</button>
        </section>
        <label>Amount Paid<span class="op-payment-money"><span>₱</span><input type="number" name="amount" min="0.01" step="0.01" inputmode="decimal" required></span></label>
        <div class="op-modal-actions op-payment-footer"><button type="button" class="ho-btn" data-close-modal="opPaymentModal">Cancel</button><button type="submit" class="ho-btn confirm op-payment-submit" id="opPaymentSubmit">Confirm Payment</button></div>
      </form>
    </div>
  </div>

  <div class="op-phone-overlay" id="opPhoneRegistrationModal" aria-hidden="true" inert>
    <section class="op-phone-modal" role="dialog" aria-modal="true" aria-labelledby="opPhoneModalTitle">
      <header class="op-phone-modal-head">
        <span class="op-phone-modal-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><rect x="6" y="2" width="12" height="20" rx="2"/><path d="M10 18h4"/></svg></span>
        <div><small>TOUR OPERATOR DEVICE</small><h3 id="opPhoneModalTitle">Register an Operator Phone</h3><p>Use the phone that should receive and display PayMongo QR notifications.</p></div>
        <button type="button" class="op-phone-modal-x" data-close-operator-phone aria-label="Close phone registration">&times;</button>
      </header>
      <div class="op-phone-modal-body">
        <section class="op-phone-current" id="opPhoneCurrent">
          <span class="op-qr-device-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><rect x="6" y="2" width="12" height="20" rx="2"/><path d="M10 18h4"/></svg></span>
          <div class="op-qr-device-copy"><small>CURRENT PAYMENT PHONE</small><strong id="opPhoneCurrentName">Checking registered phone...</strong><span id="opPhoneCurrentMeta">Please wait.</span></div>
          <span class="op-phone-current-state" id="opPhoneCurrentState">Checking</span>
        </section>
        <ol class="op-phone-steps">
          <li><b>Open the setup address on the operator phone.</b><span>Log in with this same Tour Operator account when asked.</span></li>
          <li><b>Name and register the phone.</b><span>Tap Register This Phone and allow browser notifications.</span></li>
          <li><b>Return to this computer.</b><span>This window detects the newly registered phone automatically.</span></li>
        </ol>
        <label class="op-phone-link"><span>PHONE SETUP ADDRESS</span><div><input type="text" id="opPhoneSetupUrl" readonly><button type="button" id="opCopyPhoneSetupUrl">Copy Link</button></div></label>
        <div class="op-phone-status" id="opPhoneRegistrationStatus"><i aria-hidden="true"></i><div><strong>Waiting for phone registration</strong><small>Keep this window open while registering the phone.</small></div></div>
      </div>
      <footer class="op-phone-modal-foot"><button type="button" data-close-operator-phone>Close</button><button type="button" id="opCheckPhoneRegistration">Check Again</button><button type="button" class="primary" id="opOpenPhoneSetup">Open Setup Page</button></footer>
    </section>
  </div>

  <div class="op-phone-overlay" id="opPhoneOverviewModal" aria-hidden="true" inert>
    <section class="op-phone-modal op-phone-overview" role="dialog" aria-modal="true" aria-labelledby="opPhoneOverviewTitle">
      <header class="op-phone-modal-head">
        <span class="op-phone-modal-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><rect x="6" y="2" width="12" height="20" rx="2"/><path d="M10 18h4"/></svg></span>
        <div><small>PAYMENT NOTIFICATIONS</small><h3 id="opPhoneOverviewTitle">Payment Phone</h3><p>The registered operator phone receives secure PayMongo QR notifications.</p></div>
        <button type="button" class="op-phone-modal-x" data-close-operator-phone-overview aria-label="Close payment phone">&times;</button>
      </header>
      <div class="op-phone-modal-body">
        <section class="op-phone-current" id="opPhoneOverviewCurrent">
          <span class="op-qr-device-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><rect x="6" y="2" width="12" height="20" rx="2"/><path d="M10 18h4"/></svg></span>
          <div class="op-qr-device-copy"><small>REGISTERED PAYMENT PHONE</small><strong id="opPhoneOverviewName">Checking registered phone...</strong><span id="opPhoneOverviewMeta">Please wait.</span></div>
          <button type="button" class="op-phone-overview-change" id="opPhoneOverviewAction">Change</button>
        </section>
      </div>
      <footer class="op-phone-modal-foot"><button type="button" data-close-operator-phone-overview>Close</button></footer>
    </section>
  </div>

  <div class="ho-modal" id="opExpenseModal" aria-hidden="true">
    <div class="ho-modal-card op-expense-card" role="dialog" aria-modal="true" aria-labelledby="opExpenseTitle">
      <header class="op-expense-header">
        <span class="op-expense-header-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M12 3v18M17 7.5c0-2-2-3-5-3s-5 1.3-5 3 1.5 2.5 5 3.5 5 1.8 5 3.8-2 3.2-5 3.2-5-1.2-5-3.2"/></svg></span>
        <div class="op-expense-header-copy">
          <small>BOOKING CHARGE</small>
          <h3 id="opExpenseTitle">Add Expense</h3>
          <p>Add an itemized charge to this booking account.</p>
        </div>
        <button type="button" class="op-expense-close" data-close-modal="opExpenseModal" aria-label="Close add expense">&times;</button>
      </header>
      <form id="opExpenseForm" class="op-modal-form op-expense-form">
        <input type="hidden" name="booking_id" id="opExpenseBookingId">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($operatorBookingCsrf, ENT_QUOTES, 'UTF-8') ?>">
        <label>Expense Type
          <select name="expense_type" required><option value="additional_boat">Additional Boat</option><option value="additional_tourguide">Additional Tour Guide</option><option value="food">Food</option><option value="others">Other Expense</option></select>
          <small>Select the category that best describes this additional charge.</small>
        </label>
        <label>Amount
          <span class="op-expense-money"><span>₱</span><input type="number" name="amount" min="0.01" step="0.01" inputmode="decimal" placeholder="0.00" required></span>
        </label>
        <label>Note <small>Optional description shown in the billing breakdown.</small><textarea name="note" maxlength="255" placeholder="Add a short explanation for this expense"></textarea></label>
        <div class="op-modal-actions op-expense-footer"><button type="button" class="ho-btn" data-close-modal="opExpenseModal">Cancel</button><button type="submit" class="ho-btn confirm">Save Expense</button></div>
      </form>
    </div>
  </div>

  <script>
    (function () {
      const notifToggle = document.getElementById('opNotifToggle');
      const notifPanel = document.getElementById('opNotifPanel');
      const notifBadge = document.querySelector('.op-notif-badge');
      const range = document.getElementById('opRangeFilter');
      const year = document.getElementById('opRangeYear');
      const month = document.getElementById('opRangeMonth');
      const date = document.getElementById('opRangeDate');
      let notifMarked = false;
      const updateRangeVisibility = () => {
        const r = range ? range.value : 'all';
        if (year) year.style.display = (r === 'yearly' || r === 'monthly' || r === 'daily') ? '' : 'none';
        if (month) month.style.display = (r === 'monthly') ? '' : 'none';
        if (date) date.style.display = (r === 'daily') ? '' : 'none';
      };
      if (range) range.addEventListener('change', updateRangeVisibility);
      updateRangeVisibility();

      const markNotificationsRead = async () => {
        if (notifMarked) return;
        notifMarked = true;
        if (notifBadge) notifBadge.remove();
        const body = new URLSearchParams();
        body.set('op_action', 'mark_notifications_read');
        body.set('csrf_token', <?= json_encode($operatorNotificationCsrf) ?>);
        try {
          await fetch('opbookings.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body.toString()
          });
        } catch (e) {
          console.error('Failed to mark notifications as read', e);
        }
      };
      if (notifToggle && notifPanel) {
        notifToggle.addEventListener('click', () => {
          const open = notifPanel.classList.toggle('open');
          notifToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
          if (open) markNotificationsRead();
        });
        document.addEventListener('click', (event) => {
          if (!notifPanel.contains(event.target) && !notifToggle.contains(event.target)) {
            notifPanel.classList.remove('open');
            notifToggle.setAttribute('aria-expanded', 'false');
          }
        });
      }

      const opWalkinModal = document.getElementById('opWalkinBookingModal');
      const opWalkinForm = document.getElementById('opWalkinBookingForm');
      const opWalkinOpenButton = document.getElementById('opOpenWalkinBooking');
      const shouldOpenWalkin = <?= isset($_GET['add_booking']) ? 'true' : 'false' ?>;
      if (opWalkinModal && opWalkinForm && opWalkinOpenButton) {
        const panels = [...opWalkinForm.querySelectorAll('[data-op-walkin-panel]')];
        const indicators = [...opWalkinModal.querySelectorAll('[data-op-walkin-step-indicator]')];
        const existingGuest = document.getElementById('opWalkinExistingGuest');
        const newGuest = document.getElementById('opWalkinNewGuest');
        const touristSearch = document.getElementById('opWalkinTouristSearch');
        const touristId = document.getElementById('opWalkinTouristId');
        const touristResults = document.getElementById('opWalkinTouristResults');
        const touristSpinner = document.getElementById('opWalkinSearchSpinner');
        const selectedTourist = document.getElementById('opWalkinSelectedTourist');
        const touristAvatar = document.getElementById('opWalkinTouristAvatar');
        const touristName = document.getElementById('opWalkinTouristName');
        const touristEmail = document.getElementById('opWalkinTouristEmail');
        const touristHelp = document.getElementById('opWalkinSearchHelp');
        const phoneInput = document.getElementById('opWalkinPhone');
        const packageSelect = document.getElementById('opWalkinPackage');
        const packageRate = document.getElementById('opWalkinPackageRate');
        const packageNote = document.getElementById('opWalkinPackageNote');
        const bookingDate = document.getElementById('opWalkinBookingDate');
        const bookingEndDate = document.getElementById('opWalkinBookingEndDate');
        const endDateField = document.getElementById('opWalkinEndDateField');
        const adultsInput = document.getElementById('opWalkinAdults');
        const childrenInput = document.getElementById('opWalkinChildren');
        const expenseInputs = [...opWalkinForm.querySelectorAll('.op-walkin-expense')];
        const paymentAmount = document.getElementById('opWalkinPaymentAmount');
        const paymentMethod = document.getElementById('opWalkinPaymentMethod');
        const partialField = document.getElementById('opWalkinPartialField');
        let touristTimer = null;
        let touristRequest = 0;
        let paymentManuallyEdited = Boolean(paymentAmount?.value);

        const money = value => `\u20B1${Number(value || 0).toLocaleString('en-PH', {
          minimumFractionDigits: 2,
          maximumFractionDigits: 2
        })}`;
        const currentMode = () => opWalkinForm.elements.guest_mode.value;
        const selectedPackage = () => packageSelect?.options[packageSelect.selectedIndex] || null;
        const guestCount = () => Number(adultsInput?.value || 0) + Number(childrenInput?.value || 0);
        const packageSubtotal = () => Number(selectedPackage()?.dataset.price || 0) * guestCount();
        const feesTotal = () => expenseInputs.reduce((total, input) => total + Math.max(0, Number(input.value || 0)), 0);
        const grandTotal = () => packageSubtotal() + feesTotal();
        const paymentMode = () => opWalkinForm.elements.payment_option.value;
        const paidNow = () => {
          if (paymentMode() === 'full') return grandTotal();
          if (paymentMode() === 'unpaid') return 0;
          return Math.max(0, Number(paymentAmount?.value || 0));
        };

        const setStep = step => {
          const nextStep = Math.min(3, Math.max(1, Number(step) || 1));
          opWalkinForm.dataset.step = String(nextStep);
          panels.forEach(panel => {
            const active = Number(panel.dataset.opWalkinPanel) === nextStep;
            panel.hidden = !active;
            panel.classList.toggle('active', active);
          });
          indicators.forEach(indicator => {
            const index = Number(indicator.dataset.opWalkinStepIndicator);
            indicator.classList.toggle('active', index === nextStep);
            indicator.classList.toggle('complete', index < nextStep);
          });
          opWalkinModal.querySelector('.ho-checkin-flow-card')?.scrollTo({ top: 0, behavior: 'smooth' });
          if (nextStep === 3) syncFinancials();
        };

        const openWalkin = () => {
          opWalkinModal.classList.add('open');
          opWalkinModal.setAttribute('aria-hidden', 'false');
          document.body.style.overflow = 'hidden';
          setStep(Number(opWalkinForm.dataset.step || 1));
          window.setTimeout(() => opWalkinModal.querySelector('input:not([type="hidden"]), select')?.focus(), 60);
        };
        const closeWalkin = () => {
          opWalkinModal.classList.remove('open');
          opWalkinModal.setAttribute('aria-hidden', 'true');
          document.body.style.overflow = '';
          opWalkinOpenButton.focus();
        };

        const setGuestRequirements = (resetPhone = false) => {
          const isNew = currentMode() === 'new';
          existingGuest.hidden = isNew;
          newGuest.hidden = !isNew;
          newGuest.querySelectorAll('input').forEach(input => {
            input.disabled = !isNew;
            input.required = isNew && input.name !== 'guest_address';
          });
          touristSearch.disabled = isNew;
          touristId.disabled = isNew;
          if (resetPhone && isNew) {
            phoneInput.value = '';
          } else if (resetPhone && selectedTourist.dataset.phone) {
            phoneInput.value = selectedTourist.dataset.phone;
          }
        };

        const renderTouristAvatar = (container, tourist) => {
          const initial = String(tourist.full_name || 'T').trim().charAt(0).toUpperCase() || 'T';
          const fallback = document.createElement('b');
          fallback.textContent = initial;
          container.replaceChildren(fallback);
          if (tourist.profile_image) {
            const image = document.createElement('img');
            image.src = tourist.profile_image;
            image.alt = '';
            image.referrerPolicy = 'no-referrer';
            image.addEventListener('error', () => image.remove());
            container.appendChild(image);
          }
        };
        const chooseTourist = tourist => {
          touristId.value = String(tourist.tourist_id || '');
          selectedTourist.dataset.name = tourist.full_name || '';
          selectedTourist.dataset.phone = tourist.phone_number || '';
          touristName.textContent = tourist.full_name || 'Tourist';
          touristEmail.textContent = [tourist.email, tourist.phone_number].filter(Boolean).join(' · ') || 'No contact details';
          renderTouristAvatar(touristAvatar, tourist);
          phoneInput.value = tourist.phone_number || '';
          selectedTourist.hidden = false;
          touristSearch.closest('.ho-walkin-tourist-search').hidden = true;
          touristResults.hidden = true;
          touristHelp.textContent = 'This booking will be saved in the selected tourist’s account.';
          touristSearch.setCustomValidity('');
        };
        const clearTourist = () => {
          touristId.value = '';
          selectedTourist.dataset.name = '';
          selectedTourist.dataset.phone = '';
          selectedTourist.hidden = true;
          touristSearch.closest('.ho-walkin-tourist-search').hidden = false;
          touristSearch.value = '';
          touristResults.hidden = true;
          phoneInput.value = '';
          touristHelp.textContent = 'Type at least two characters to find an active tourist account.';
          touristSearch.focus();
        };
        const renderTourists = tourists => {
          touristResults.replaceChildren();
          if (!tourists.length) {
            const empty = document.createElement('p');
            empty.className = 'ho-walkin-tourist-empty';
            empty.textContent = 'No matching active tourist accounts found.';
            touristResults.appendChild(empty);
          } else {
            tourists.forEach(tourist => {
              const button = document.createElement('button');
              button.type = 'button';
              button.className = 'ho-walkin-tourist-result';
              const avatar = document.createElement('span');
              avatar.className = 'ho-walkin-tourist-avatar';
              renderTouristAvatar(avatar, tourist);
              const copy = document.createElement('span');
              const name = document.createElement('strong');
              const detail = document.createElement('small');
              name.textContent = tourist.full_name || 'Tourist';
              detail.textContent = [tourist.email, tourist.phone_number].filter(Boolean).join(' · ') || 'No contact details';
              copy.append(name, detail);
              const action = document.createElement('b');
              action.textContent = 'Select';
              button.append(avatar, copy, action);
              button.addEventListener('click', () => chooseTourist(tourist));
              touristResults.appendChild(button);
            });
          }
          touristResults.hidden = false;
        };
        const searchTourists = async query => {
          const requestId = ++touristRequest;
          touristSpinner.hidden = false;
          try {
            const response = await fetch(`opbookings.php?op_action=search_tourists&q=${encodeURIComponent(query)}`, {
              headers: { Accept: 'application/json' }
            });
            const result = await response.json();
            if (!response.ok || !result.ok) throw new Error('Tourist search failed.');
            if (requestId === touristRequest) renderTourists(Array.isArray(result.tourists) ? result.tourists : []);
          } catch (error) {
            if (requestId === touristRequest) renderTourists([]);
            console.error(error);
          } finally {
            if (requestId === touristRequest) touristSpinner.hidden = true;
          }
        };

        const syncPackage = () => {
          if (!packageSelect || !packageRate || !packageNote || !endDateField || !bookingEndDate) {
            syncFinancials();
            return;
          }
          const option = selectedPackage();
          const hasPackage = Boolean(option?.value);
          const type = String(option?.dataset.tourType || '').toLowerCase();
          const overnight = hasPackage && type.includes('night');
          const rate = Number(option?.dataset.price || 0);
          packageRate.textContent = money(rate);
          endDateField.hidden = !overnight;
          bookingEndDate.disabled = !overnight;
          bookingEndDate.required = overnight;
          if (!overnight) bookingEndDate.value = '';
          packageNote.textContent = hasPackage
            ? `${overnight ? 'Overnight' : 'Day tour'} · ${option.dataset.range || 'Schedule set by package'} · ${money(rate)} per guest`
            : 'Select a package to see its schedule type and price.';
          syncFinancials();
        };
        const syncDateLimits = () => {
          if (!bookingDate?.value || !bookingEndDate) return;
          const nextDay = new Date(`${bookingDate.value}T00:00:00`);
          nextDay.setDate(nextDay.getDate() + 1);
          const minimum = [
            nextDay.getFullYear(),
            String(nextDay.getMonth() + 1).padStart(2, '0'),
            String(nextDay.getDate()).padStart(2, '0')
          ].join('-');
          bookingEndDate.min = minimum;
          if (bookingEndDate.value && bookingEndDate.value < minimum) bookingEndDate.value = '';
        };
        const syncFinancials = () => {
          const subtotal = packageSubtotal();
          const fees = feesTotal();
          const total = subtotal + fees;
          const mode = paymentMode();
          partialField.hidden = mode !== 'partial';
          paymentAmount.disabled = mode !== 'partial';
          paymentAmount.required = mode === 'partial';
          if (mode === 'full') paymentAmount.value = total.toFixed(2);
          if (mode === 'unpaid') paymentAmount.value = '0.00';
          if (mode === 'partial') {
            paymentAmount.max = Math.max(0, total - 0.01).toFixed(2);
            if (!paymentManuallyEdited) paymentAmount.value = (total * 0.2).toFixed(2);
          }
          const paid = Math.min(total, paidNow());
          paymentMethod.required = paid > 0;
          paymentMethod.disabled = paid <= 0;
          if (paid <= 0) paymentMethod.value = '';

          document.getElementById('opWalkinServiceTotal').textContent = money(subtotal);
          document.getElementById('opWalkinFeesTotal').textContent = money(fees);
          document.getElementById('opWalkinGrandTotal').textContent = money(total);
          document.getElementById('opWalkinSummaryGuest').textContent = currentMode() === 'new'
            ? (opWalkinForm.elements.guest_name.value.trim() || 'New walk-in guest')
            : (selectedTourist.dataset.name || 'Tourist not selected');
          document.getElementById('opWalkinSummaryPackage').textContent = selectedPackage()?.dataset.name || 'Package not selected';
          const startDateValue = bookingDate?.value || '';
          const endDateValue = bookingEndDate?.value || '';
          document.getElementById('opWalkinSummaryDates').textContent = startDateValue
            ? (!bookingEndDate || bookingEndDate.disabled || !endDateValue ? startDateValue : `${startDateValue} to ${endDateValue}`)
            : 'Date not selected';
          document.getElementById('opWalkinSummaryGuests').textContent = `${guestCount()} guest${guestCount() === 1 ? '' : 's'}`;
          document.getElementById('opWalkinSummaryPaid').textContent = money(paid);
          document.getElementById('opWalkinSummaryBalance').textContent = money(Math.max(0, total - paid));
        };

        const validateStep = step => {
          if (step === 1 && currentMode() === 'existing' && !touristId.value) {
            touristSearch.setCustomValidity('Search for and select an active tourist account.');
            touristSearch.reportValidity();
            touristSearch.focus();
            return false;
          }
          if (step === 3 && paymentMode() === 'partial') {
            const amount = Number(paymentAmount.value || 0);
            if (amount <= 0 || amount >= grandTotal()) {
              paymentAmount.setCustomValidity('Enter an amount greater than zero and lower than the grand total.');
              paymentAmount.reportValidity();
              return false;
            }
            paymentAmount.setCustomValidity('');
          }
          const panel = panels.find(item => Number(item.dataset.opWalkinPanel) === step);
          const invalid = [...(panel?.querySelectorAll('input, select, textarea') || [])]
            .find(field => !field.disabled && !field.checkValidity());
          if (invalid) {
            invalid.reportValidity();
            invalid.focus();
            return false;
          }
          return true;
        };

        opWalkinOpenButton.addEventListener('click', openWalkin);
        opWalkinModal.querySelectorAll('[data-close-op-walkin]').forEach(button => button.addEventListener('click', closeWalkin));
        opWalkinModal.addEventListener('mousedown', event => {
          if (event.target === opWalkinModal) closeWalkin();
        });
        document.addEventListener('keydown', event => {
          if (event.key === 'Escape' && opWalkinModal.classList.contains('open')) closeWalkin();
        });
        opWalkinForm.querySelectorAll('[name="guest_mode"]').forEach(radio => radio.addEventListener('change', () => setGuestRequirements(true)));
        document.getElementById('opWalkinChangeTourist')?.addEventListener('click', clearTourist);
        touristSearch.addEventListener('input', () => {
          touristSearch.setCustomValidity('');
          window.clearTimeout(touristTimer);
          const query = touristSearch.value.trim();
          if (query.length < 2) {
            touristResults.hidden = true;
            touristSpinner.hidden = true;
            return;
          }
          touristTimer = window.setTimeout(() => searchTourists(query), 260);
        });
        packageSelect?.addEventListener('change', syncPackage);
        bookingDate?.addEventListener('change', () => {
          syncDateLimits();
          syncFinancials();
        });
        bookingEndDate?.addEventListener('change', syncFinancials);
        opWalkinForm.querySelectorAll('[data-op-pax-action]').forEach(button => {
          button.addEventListener('click', () => {
            const input = document.getElementById(button.dataset.opPaxTarget);
            if (!input) return;
            const direction = button.dataset.opPaxAction === 'increase' ? 1 : -1;
            const minimum = Number(input.min || 0);
            const maximum = Number(input.max || 100);
            input.value = String(Math.min(maximum, Math.max(minimum, Number(input.value || 0) + direction)));
            syncFinancials();
          });
        });
        expenseInputs.forEach(input => input.addEventListener('input', syncFinancials));
        opWalkinForm.querySelectorAll('[name="payment_option"]').forEach(radio => radio.addEventListener('change', () => {
          paymentManuallyEdited = false;
          syncFinancials();
        }));
        paymentAmount?.addEventListener('input', () => {
          paymentManuallyEdited = true;
          paymentAmount.setCustomValidity('');
          syncFinancials();
        });
        opWalkinForm.querySelectorAll('[data-op-walkin-next]').forEach(button => {
          button.addEventListener('click', () => {
            const step = Number(opWalkinForm.dataset.step || 1);
            if (validateStep(step)) setStep(step + 1);
          });
        });
        opWalkinForm.querySelectorAll('[data-op-walkin-prev]').forEach(button => {
          button.addEventListener('click', () => setStep(Number(opWalkinForm.dataset.step || 1) - 1));
        });
        opWalkinForm.addEventListener('submit', event => {
          if (!validateStep(3)) {
            event.preventDefault();
            return;
          }
          const submit = opWalkinForm.querySelector('button[type="submit"]');
          if (submit) {
            submit.disabled = true;
            submit.textContent = 'Creating booking…';
          }
        });

        setGuestRequirements();
        syncPackage();
        syncDateLimits();
        syncFinancials();
        if (shouldOpenWalkin) openWalkin();
      }

      const modal = document.getElementById('hoDetailsModal');
      const closeBtn = document.getElementById('hoCloseModal');
      const closeDetailsFooterBtn = document.getElementById('opCloseDetailsFooter');
      const grid = document.getElementById('hoDetailGrid');
      let lastDetailsTrigger = null;
      const escapeDetailText = value => String(value ?? '').replace(/[&<>"']/g, character => ({
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#039;'
      })[character]);

      const profileTooltip = document.createElement('div');
      profileTooltip.className = 'ho-profile-tooltip';
      profileTooltip.setAttribute('role', 'dialog');
      document.body.appendChild(profileTooltip);

      const profileDrawer = document.getElementById('opTouristProfileDrawer');
      const profileDrawerContent = document.getElementById('opTouristProfileContent');
      let profileHideTimer = null;
      let activeProfileTrigger = null;

      const formatProfileDate = value => {
        if (!value) return 'Not available';
        const parsed = new Date(String(value).replace(' ', 'T'));
        return Number.isNaN(parsed.getTime()) ? String(value) : parsed.toLocaleDateString('en-PH', {
          year: 'numeric',
          month: 'long',
          day: 'numeric'
        });
      };

      const positionProfileTooltip = trigger => {
        const triggerRect = trigger.getBoundingClientRect();
        const tooltipRect = profileTooltip.getBoundingClientRect();
        const spacing = 9;
        const pageTop = window.scrollY;
        const pageLeft = window.scrollX;
        let top = triggerRect.bottom + pageTop + spacing;
        let left = triggerRect.left + pageLeft;

        if (left + tooltipRect.width > pageLeft + window.innerWidth - 12) {
          left = pageLeft + window.innerWidth - tooltipRect.width - 12;
        }
        left = Math.max(pageLeft + 12, left);
        if (top + tooltipRect.height > pageTop + window.innerHeight - 12) {
          top = triggerRect.top + pageTop - tooltipRect.height - spacing;
        }
        top = Math.max(pageTop + 12, top);
        profileTooltip.style.top = `${Math.round(top)}px`;
        profileTooltip.style.left = `${Math.round(left)}px`;
      };

      const hideProfileTooltip = (immediate = false) => {
        window.clearTimeout(profileHideTimer);
        const hide = () => {
          profileTooltip.classList.remove('show');
          profileTooltip.style.display = 'none';
        };
        if (immediate) hide();
        else profileHideTimer = window.setTimeout(hide, 150);
      };

      const closeProfileDrawer = () => {
        if (!profileDrawer) return;
        profileDrawer.classList.remove('show');
        profileDrawer.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('ho-tourist-profile-open');
        activeProfileTrigger?.focus();
      };

      const profileIcons = {
        mail: '<svg viewBox="0 0 24 24"><rect x="3" y="5" width="18" height="14" rx="2"></rect><path d="m4 7 8 6 8-6"></path></svg>',
        phone: '<svg viewBox="0 0 24 24"><path d="M7 3H4a1 1 0 0 0-1 1c0 9.4 7.6 17 17 17a1 1 0 0 0 1-1v-3l-4-2-2 3a15 15 0 0 1-9-9l3-2-2-4Z"></path></svg>',
        map: '<svg viewBox="0 0 24 24"><path d="M20 10c0 5-8 12-8 12S4 15 4 10a8 8 0 1 1 16 0Z"></path><circle cx="12" cy="10" r="2.5"></circle></svg>',
        shield: '<svg viewBox="0 0 24 24"><path d="M12 3 4.5 6v5.5c0 4.8 3.2 8 7.5 9.5 4.3-1.5 7.5-4.7 7.5-9.5V6L12 3Z"></path><path d="m9 12 2 2 4-4"></path></svg>'
      };

      const openProfileDrawer = trigger => {
        if (!profileDrawer || !profileDrawerContent || !trigger) return;
        hideProfileTooltip(true);
        activeProfileTrigger = trigger;

        const data = trigger.dataset;
        const name = escapeDetailText(data.fullname || 'Guest');
        const email = escapeDetailText(data.email || '-');
        const phone = escapeDetailText(data.phone || '-');
        const address = escapeDetailText(data.address || '-');
        const profileSrc = escapeDetailText(data.profileSrc || 'img/profileicon2.png');
        const touristId = escapeDetailText(data.touristId || '-');
        const totalBookings = Number(data.totalBookings || 0);
        const completedBookings = Number(data.completedBookings || 0);
        const rawStatus = data.accountStatus || 'Guest';
        const accountStatus = escapeDetailText(rawStatus.charAt(0).toUpperCase() + rawStatus.slice(1));
        const verified = data.emailVerified === '1';
        const googleConnected = data.googleConnected === '1';
        const joinedDate = escapeDetailText(formatProfileDate(data.createdAt));
        const updatedDate = escapeDetailText(formatProfileDate(data.updatedAt));
        const emailHref = data.email && data.email !== '-' ? `mailto:${encodeURIComponent(data.email)}` : '#';

        profileDrawerContent.innerHTML = `
          <section class="ho-tp-hero">
            <img src="${profileSrc}" alt="">
            <div class="ho-tp-hero-copy">
              <span class="ho-tp-eyebrow">REGISTERED TOURIST</span>
              <h2>${name}</h2>
              <p>Tourist ID: #${touristId}</p>
              <div class="ho-tp-hero-pills">
                <span>${accountStatus}</span>
                <span class="${verified ? 'is-positive' : 'is-warning'}">${verified ? 'Email verified' : 'Email unverified'}</span>
              </div>
            </div>
          </section>
          <section class="ho-tp-stats" aria-label="Tourist booking summary">
            <div><strong>${totalBookings}</strong><span>Total bookings</span></div>
            <div><strong>${completedBookings}</strong><span>Completed tours</span></div>
            <div><strong>${joinedDate}</strong><span>Member since</span></div>
          </section>
          <section class="ho-tp-section">
            <div class="ho-tp-section-heading"><span>${profileIcons.mail}</span><div><h3>Contact information</h3><p>Primary contact details for this tourist</p></div></div>
            <div class="ho-tp-info-list">
              <div class="ho-tp-info-row"><span>${profileIcons.mail}</span><div><small>Email address</small><strong>${email}</strong></div></div>
              <div class="ho-tp-info-row"><span>${profileIcons.phone}</span><div><small>Phone number</small><strong>${phone}</strong></div></div>
              <div class="ho-tp-info-row"><span>${profileIcons.map}</span><div><small>Home address</small><strong>${address}</strong></div></div>
            </div>
            <a class="ho-tp-email-action${emailHref === '#' ? ' is-disabled' : ''}" href="${emailHref}" ${emailHref === '#' ? 'aria-disabled="true"' : ''}>
              ${profileIcons.mail}<span>Send email to tourist</span><b aria-hidden="true">&rarr;</b>
            </a>
          </section>
          <section class="ho-tp-section">
            <div class="ho-tp-section-heading"><span>${profileIcons.shield}</span><div><h3>Account information</h3><p>Registration, verification, and access status</p></div></div>
            <div class="ho-tp-account-grid">
              <div><small>Account status</small><strong>${accountStatus}</strong></div>
              <div><small>Email verification</small><strong>${verified ? 'Verified' : 'Not verified'}</strong></div>
              <div><small>Sign-in connection</small><strong>${googleConnected ? 'Google connected' : 'Email and password'}</strong></div>
              <div><small>Last profile update</small><strong>${updatedDate}</strong></div>
            </div>
          </section>
        `;

        profileDrawer.classList.add('show');
        profileDrawer.setAttribute('aria-hidden', 'false');
        document.body.classList.add('ho-tourist-profile-open');
        profileDrawer.querySelector('.ho-tourist-profile-close')?.focus();
      };

      const showProfileTooltip = trigger => {
        window.clearTimeout(profileHideTimer);
        activeProfileTrigger = trigger;
        const data = trigger.dataset;
        const name = escapeDetailText(data.fullname || 'Guest');
        const email = escapeDetailText(data.email || '-');
        const phone = escapeDetailText(data.phone || '-');
        const address = escapeDetailText(data.address || '-');
        const profileSrc = escapeDetailText(data.profileSrc || 'img/profileicon2.png');
        const completed = Number(data.completedBookings || 0);

        profileTooltip.innerHTML = `
          <div class="ho-tooltip-accent" aria-hidden="true"></div>
          <div class="ho-tooltip-header">
            <img class="ho-tooltip-avatar" src="${profileSrc}" alt="">
            <div class="ho-tooltip-identity">
              <span class="ho-tooltip-eyebrow">Tourist profile</span>
              <div class="ho-tooltip-name">${name}</div>
              <span class="ho-tooltip-booking-count">${completed} completed booking${completed === 1 ? '' : 's'}</span>
            </div>
          </div>
          <div class="ho-tooltip-details">
            <div class="ho-tooltip-detail"><span class="ho-tooltip-detail-icon" aria-hidden="true">&#9993;</span><span><small>Email address</small><strong>${email}</strong></span></div>
            <div class="ho-tooltip-detail"><span class="ho-tooltip-detail-icon" aria-hidden="true">&#9742;</span><span><small>Phone number</small><strong>${phone}</strong></span></div>
            <div class="ho-tooltip-detail"><span class="ho-tooltip-detail-icon" aria-hidden="true">&#9673;</span><span><small>Home address</small><strong>${address}</strong></span></div>
          </div>
          <button type="button" class="ho-tooltip-profile-btn"><span>View full profile</span><span aria-hidden="true">&rarr;</span></button>
        `;
        profileTooltip.style.display = 'block';
        profileTooltip.style.visibility = 'hidden';
        requestAnimationFrame(() => {
          positionProfileTooltip(trigger);
          profileTooltip.style.visibility = 'visible';
          profileTooltip.classList.add('show');
        });
      };

      document.querySelectorAll('.ho-profile-trigger').forEach(trigger => {
        trigger.addEventListener('mouseenter', () => showProfileTooltip(trigger));
        trigger.addEventListener('mouseleave', () => hideProfileTooltip());
        trigger.addEventListener('click', () => openProfileDrawer(trigger));
        trigger.addEventListener('keydown', event => {
          if (event.key === 'Enter' || event.key === ' ') {
            event.preventDefault();
            openProfileDrawer(trigger);
          }
        });
      });
      profileTooltip.addEventListener('mouseenter', () => window.clearTimeout(profileHideTimer));
      profileTooltip.addEventListener('mouseleave', () => hideProfileTooltip());
      profileTooltip.addEventListener('click', event => {
        if (event.target.closest('.ho-tooltip-profile-btn') && activeProfileTrigger) {
          openProfileDrawer(activeProfileTrigger);
        }
      });
      profileDrawer?.querySelectorAll('.ho-tourist-profile-close, .ho-tourist-profile-close-btn').forEach(button => {
        button.addEventListener('click', closeProfileDrawer);
      });
      profileDrawer?.addEventListener('mousedown', event => {
        if (event.target === profileDrawer) closeProfileDrawer();
      });
      document.addEventListener('click', event => {
        if (!profileTooltip.contains(event.target) && !event.target.closest('.ho-profile-trigger')) {
          hideProfileTooltip(true);
        }
      });
      document.addEventListener('keydown', event => {
        if (event.key === 'Escape' && profileDrawer?.classList.contains('show')) {
          closeProfileDrawer();
        }
      });

      const bookingDetailIcons = {
        guest: '<svg viewBox="0 0 24 24"><path d="M20 21a8 8 0 0 0-16 0"/><circle cx="12" cy="7" r="4"/></svg>',
        calendar: '<svg viewBox="0 0 24 24"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 10h18"/></svg>',
        service: '<svg viewBox="0 0 24 24"><path d="M4 19h16M6 16l2-9h8l2 9M9 11h6"/><path d="M12 3v4"/></svg>',
        map: '<svg viewBox="0 0 24 24"><path d="m3 6 6-3 6 3 6-3v15l-6 3-6-3-6 3Z"/><path d="M9 3v15M15 6v15"/></svg>',
        port: '<svg viewBox="0 0 24 24"><path d="M3 19h18M5 19l2-8h10l2 8M9 11V6h6v5M12 6V3"/></svg>',
        users: '<svg viewBox="0 0 24 24"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/></svg>',
        payment: '<svg viewBox="0 0 24 24"><rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20M6 15h2"/></svg>'
      };
      const formatDetailMoney = value => `\u20B1${Number(value || 0).toLocaleString('en-PH', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
      })}`;
      const formatDetailDate = (value, includeTime = false) => {
        if (!value) return '-';
        const normalized = includeTime ? String(value).replace(' ', 'T') : `${value}T00:00:00`;
        const parsed = new Date(normalized);
        if (Number.isNaN(parsed.getTime())) return String(value);
        return parsed.toLocaleDateString('en-PH', {
          year: 'numeric',
          month: 'long',
          day: 'numeric',
          ...(includeTime ? { hour: 'numeric', minute: '2-digit' } : {})
        });
      };
      const bookingDetailRow = (icon, label, value) => `
        <div class="ho-bd-detail-row">
          <span class="ho-bd-row-icon">${bookingDetailIcons[icon] || ''}</span>
          <div><small>${escapeDetailText(label)}</small><strong>${escapeDetailText(value || '-')}</strong></div>
        </div>
      `;
      const closeBookingDetails = () => {
        modal?.classList.remove('show');
        modal?.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('ho-booking-details-open');
        lastDetailsTrigger?.focus();
      };

      const rowActions = document.querySelectorAll('[data-row-actions]');
      const positionRowActionsMenu = (wrapper) => {
        const trigger = wrapper.querySelector('[data-row-actions-trigger]');
        const menu = wrapper.querySelector('[data-row-actions-menu]');
        if (!trigger || !menu) return;

        const spacing = 7;
        const viewportPadding = 12;
        const triggerRect = trigger.getBoundingClientRect();
        const menuRect = menu.getBoundingClientRect();
        const availableBelow = window.innerHeight - triggerRect.bottom - viewportPadding;
        const openUp = availableBelow < menuRect.height + spacing
          && triggerRect.top > menuRect.height + spacing + viewportPadding;
        const top = openUp
          ? Math.max(viewportPadding, triggerRect.top - menuRect.height - spacing)
          : Math.min(triggerRect.bottom + spacing, window.innerHeight - menuRect.height - viewportPadding);
        const maxLeft = window.innerWidth - menuRect.width - viewportPadding;
        const left = Math.max(viewportPadding, Math.min(triggerRect.right - menuRect.width, maxLeft));

        menu.style.top = `${Math.round(top)}px`;
        menu.style.left = `${Math.round(left)}px`;
        wrapper.classList.toggle('drop-up', openUp);
      };
      const closeRowActions = (except = null) => {
        rowActions.forEach(wrapper => {
          if (wrapper === except) return;
          wrapper.classList.remove('open', 'drop-up');
          wrapper.querySelector('[data-row-actions-trigger]')?.setAttribute('aria-expanded', 'false');
          const menu = wrapper.querySelector('[data-row-actions-menu]');
          if (menu) {
            menu.style.top = '-9999px';
            menu.style.left = '-9999px';
          }
        });
      };

      document.querySelectorAll('[data-row-actions-trigger]').forEach(trigger => {
        trigger.addEventListener('click', event => {
          event.preventDefault();
          event.stopPropagation();
          const wrapper = trigger.closest('[data-row-actions]');
          if (!wrapper) return;
          const willOpen = !wrapper.classList.contains('open');
          closeRowActions(wrapper);
          wrapper.classList.toggle('open', willOpen);
          trigger.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
          if (willOpen) requestAnimationFrame(() => positionRowActionsMenu(wrapper));
        });
      });
      document.querySelectorAll('[data-row-actions-menu]').forEach(menu => {
        menu.addEventListener('click', event => {
          if (event.target.closest('button, a')) window.setTimeout(() => closeRowActions(), 0);
        });
      });
      document.addEventListener('click', event => {
        if (!(event.target instanceof Element) || !event.target.closest('[data-row-actions]')) closeRowActions();
      });
      document.addEventListener('keydown', event => {
        if (event.key === 'Escape') closeRowActions();
      });
      window.addEventListener('resize', () => closeRowActions());
      document.addEventListener('scroll', () => closeRowActions(), true);

      document.querySelectorAll('[data-view]').forEach(btn => {
        btn.addEventListener('click', () => {
          const raw = btn.getAttribute('data-booking');
          if (!raw || !modal || !grid) return;
          const data = JSON.parse(raw);
          const isCompleted = String(data.completion || '').toLowerCase() === 'completed';
          const status = isCompleted ? 'Completed' : String(data.status || 'Pending');
          const statusClass = status.toLowerCase().replace(/[^a-z0-9-]/g, '');
          const guestName = String(data.guest || 'Guest');
          const initials = guestName.split(/\s+/).filter(Boolean).slice(0, 2).map(part => part[0]).join('').toUpperCase() || 'G';
          const profileImage = String(data.profile_image || '').trim();
          const guestAvatar = `${escapeDetailText(initials)}${profileImage ? `<img src="${escapeDetailText(profileImage)}" alt="${escapeDetailText(guestName)} profile picture" loading="lazy" decoding="async" referrerpolicy="no-referrer" onerror="this.remove()">` : ''}`;
          const grandTotal = Number(data.grand_total || 0);
          const paidAmount = Number(data.payment_amount || 0);
          const remainingBalance = Number(data.remaining_balance || 0);
          const paymentStatus = remainingBalance <= 0 ? 'Paid' : paidAmount > 0 ? 'Partial' : 'Unpaid';
          const paymentClass = paymentStatus.toLowerCase();

          grid.innerHTML = `
            <div class="ho-bd-drawer-hero">
              <div class="ho-bd-reference"><span>BOOKING REFERENCE</span><strong>${escapeDetailText(data.id)}</strong></div>
              <span class="ho-bd-status-pill ho-bd-status-${statusClass}">${escapeDetailText(status)}</span>
            </div>

            <section class="ho-bd-drawer-section ho-bd-guest-card">
              <div class="ho-bd-guest-avatar">${guestAvatar}</div>
              <div class="ho-bd-guest-primary">
                <small>PRIMARY GUEST</small>
                <h4>${escapeDetailText(guestName)}</h4>
                <p>${escapeDetailText(data.email || '-')}</p>
              </div>
            </section>

            <section class="ho-bd-drawer-section">
              <div class="ho-bd-section-title"><span>${bookingDetailIcons.guest}</span><div><h4>Guest information</h4><p>Contact details used for this reservation</p></div></div>
              <div class="ho-bd-detail-grid">
                ${bookingDetailRow('guest', 'Contact number', data.phone)}
                ${bookingDetailRow('users', 'Guest count', `${Number(data.pax || 0)} passenger(s)`)}
              </div>
            </section>

            <section class="ho-bd-drawer-section">
              <div class="ho-bd-section-title"><span>${bookingDetailIcons.calendar}</span><div><h4>Trip information</h4><p>Package, schedule, and meeting details</p></div></div>
              <div class="ho-bd-detail-grid">
                ${bookingDetailRow('service', 'Selected package', data.package)}
                ${bookingDetailRow('service', 'Booking type', data.booking_type)}
                ${bookingDetailRow('map', 'Destination', data.location)}
                ${bookingDetailRow('calendar', 'Tour date', formatDetailDate(data.date))}
                ${bookingDetailRow('port', 'Jump-off port', data.jump_off_port)}
                ${bookingDetailRow('calendar', 'Booked on', formatDetailDate(data.created_at, true))}
              </div>
            </section>

            <section class="ho-bd-drawer-section ho-bd-payment-card">
              <div class="ho-bd-section-title"><span>${bookingDetailIcons.payment}</span><div><h4>Payment summary</h4><p>Current financial status of this booking</p></div></div>
              <div class="ho-bd-payment-lines">
                <div><span>Grand total</span><strong>${formatDetailMoney(grandTotal)}</strong></div>
                <div><span>Amount received</span><strong>${formatDetailMoney(paidAmount)}</strong></div>
                <div class="ho-bd-grand-total"><span>Remaining balance</span><strong>${formatDetailMoney(remainingBalance)}</strong></div>
              </div>
              <div class="ho-bd-payment-stats">
                <div><small>PAYMENT METHOD</small><strong>${escapeDetailText(data.payment_method ? String(data.payment_method).replace(/_/g, ' ').toUpperCase() : 'Not selected')}</strong></div>
                <div><small>PAYMENT STATUS</small><strong class="ho-bd-payment-${paymentClass}">${escapeDetailText(paymentStatus)}</strong></div>
              </div>
            </section>

            <div class="ho-bd-confidence-note">
              <span>${bookingDetailIcons.service}</span>
              <p><strong>Booking record verified</strong>Details shown here are loaded directly from the operator's current booking record.</p>
            </div>
          `;
          lastDetailsTrigger = btn;
          closeRowActions();
          modal.classList.add('show');
          modal.setAttribute('aria-hidden', 'false');
          document.body.classList.add('ho-booking-details-open');
          closeBtn?.focus();
        });
      });

      closeBtn?.addEventListener('click', closeBookingDetails);
      closeDetailsFooterBtn?.addEventListener('click', closeBookingDetails);
      modal?.addEventListener('mousedown', (e) => {
        if (e.target === modal) closeBookingDetails();
      });
      document.addEventListener('keydown', event => {
        if (event.key === 'Escape' && modal?.classList.contains('show')) {
          closeBookingDetails();
        }
      });

      document.querySelectorAll('[data-cancel-form]').forEach(form => {
        form.addEventListener('submit', (e) => {
          const ok = window.confirm('Are you sure you want to cancel this booking?');
          if (!ok) e.preventDefault();
        });
      });

      const paymentModal = document.getElementById('opPaymentModal');
      const expenseModal = document.getElementById('opExpenseModal');
      const openModal = (modal) => modal?.classList.add('open');
      const closeModal = (modal) => modal?.classList.remove('open');
      const billingModal = document.getElementById('opBillingModal');
      const billingBody = document.getElementById('opBillingBody');
      const billingSubtitle = document.getElementById('opBillingSubtitle');
      const billingAddExpense = document.getElementById('opBillingAddExpense');
      const billingPayBalance = document.getElementById('opBillingPayBalance');
      const billingState = { bookingId: 0, balance: 0, canAddExpense: false };
      const opPayMongoCsrf = <?= json_encode($opPayMongoCsrf, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
      const operatorBookingCsrf = <?= json_encode($operatorBookingCsrf, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
      const opPayMongoEndpoint = <?= json_encode(
        (str_contains(str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? '')), '/operator/') ? '../' : '')
        . 'payments/create-balance-checkout.php',
        JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
      ) ?>;
      const opPhoneStatusEndpoint = <?= json_encode(
        (str_contains(str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? '')), '/operator/') ? '../' : '')
        . 'operator-push-device-status.php',
        JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
      ) ?>;
      const opPhoneSetupPage = <?= json_encode(
        (str_contains(str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? '')), '/operator/') ? '../' : '')
        . 'operator-phone-setup.php',
        JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
      ) ?>;
      const opPublicAppUrl = <?= json_encode(
        (string)($operatorFirebasePublicConfiguration['app_url'] ?? ''),
        JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
      ) ?>;
      const opPhoneState = { device: null, baseline: null, pollTimer: null, paymentPollTimer: null };
      const paymentMethod = document.getElementById('opPaymentMethod');
      const paymentMethodNote = document.getElementById('opPaymentMethodNote');
      const paymentSubmit = document.getElementById('opPaymentSubmit');
      const billingMoney = value => `\u20B1${Number(value || 0).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
      const escapeBilling = value => String(value ?? '').replace(/[&<>'"]/g, character => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#39;', '"': '&quot;'
      })[character]);
      const readPaymentJson = async response => {
        const contentType = response.headers.get('content-type') || '';
        if (!contentType.includes('application/json')) throw new Error('The payment service returned an unexpected response.');
        return response.json();
      };
      const updatePaymentMethod = () => {
        const qr = paymentMethod?.value === 'qr_code';
        const devicePanel = document.getElementById('opQrDevicePanel');
        if (devicePanel) devicePanel.hidden = !qr;
        if (paymentMethodNote) paymentMethodNote.textContent = qr
          ? 'PayMongo will send a secure QR notification to the registered operator phone. The balance changes only after verification.'
          : paymentMethod?.value === 'cash'
            ? 'Confirm only after receiving the cash payment from the tourist.'
            : 'Choose how the tourist will settle this balance.';
        if (paymentSubmit) paymentSubmit.textContent = qr ? 'Send PayMongo QR' : 'Confirm Payment';
        if (qr) startOperatorPaymentPhonePolling();
        else stopOperatorPaymentPhonePolling();
      };
      const preparePaymentModal = (bookingId, balance, completeAfterPayment = false) => {
        const normalizedBalance = Math.max(0, Number(balance || 0));
        document.getElementById('opPaymentBookingId').value = String(bookingId || '');
        document.getElementById('opCompleteAfterPayment').value = completeAfterPayment ? '1' : '';
        if (paymentMethod) paymentMethod.value = '';
        document.getElementById('opPaymentInfo').textContent = billingMoney(normalizedBalance);
        const amount = document.querySelector('#opPaymentForm [name="amount"]');
        if (amount) {
          amount.value = normalizedBalance.toFixed(2);
          amount.max = normalizedBalance.toFixed(2);
        }
        updatePaymentMethod();
        openModal(paymentModal);
      };
      function operatorPhoneSetupUrl() {
        if (opPublicAppUrl) {
          const base = new URL(opPublicAppUrl);
          base.pathname = base.pathname.endsWith('/') ? base.pathname : `${base.pathname}/`;
          base.search = '';
          base.hash = '';
          return new URL('operator-phone-setup.php', base).href;
        }
        return new URL(opPhoneSetupPage, window.location.href).href;
      }
      async function fetchOperatorPhone() {
        const response = await fetch(opPhoneStatusEndpoint, { credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'application/json' } });
        const payload = await readPaymentJson(response);
        if (!response.ok || !payload.success) throw new Error(payload.message || 'The registered operator phone could not be checked.');
        return payload;
      }
      function renderOperatorPhone(payload) {
        const registered = Boolean(payload?.registered && payload.device);
        const device = registered ? payload.device : null;
        opPhoneState.device = device;
        const lastUsed = device?.last_used_at ? new Date(String(device.last_used_at).replace(' ', 'T')) : null;
        const name = device?.device_name || (registered ? 'Operator phone' : 'No operator phone registered');
        const meta = registered
          ? (lastUsed && !Number.isNaN(lastUsed.getTime()) ? `Notifications active · Registered ${lastUsed.toLocaleString()}` : 'Ready to receive PayMongo QR notifications.')
          : 'Register a phone before sending PayMongo QR notifications.';
        ['opQrDeviceName', 'opPhoneCurrentName', 'opPhoneOverviewName'].forEach(id => { const element = document.getElementById(id); if (element) element.textContent = name; });
        ['opQrDeviceMeta', 'opPhoneCurrentMeta', 'opPhoneOverviewMeta'].forEach(id => { const element = document.getElementById(id); if (element) element.textContent = meta; });
        const action = document.getElementById('opManagePhoneButton');
        if (action) { action.disabled = false; action.textContent = registered ? 'Change' : 'Register a Phone'; }
        const current = document.getElementById('opPhoneCurrent');
        current?.classList.toggle('is-registered', registered);
        document.getElementById('opPhoneOverviewCurrent')?.classList.toggle('is-registered', registered);
        const overviewAction = document.getElementById('opPhoneOverviewAction');
        if (overviewAction) overviewAction.textContent = registered ? 'Change' : 'Register Phone';
        const state = document.getElementById('opPhoneCurrentState');
        if (state) state.textContent = registered ? 'Registered' : 'Not registered';
      }
      async function refreshOperatorPhone(silent = false) {
        const action = document.getElementById('opManagePhoneButton');
        if (!silent && action) action.disabled = true;
        try {
          const payload = await fetchOperatorPhone();
          renderOperatorPhone(payload);
          return payload;
        } catch (error) {
          if (!silent) {
            const name = document.getElementById('opQrDeviceName');
            const meta = document.getElementById('opQrDeviceMeta');
            if (name) name.textContent = 'Unable to check registered phone';
            if (meta) meta.textContent = error.message;
            if (action) action.disabled = false;
          }
          return null;
        }
      }
      function stopOperatorPaymentPhonePolling() {
        if (opPhoneState.paymentPollTimer) window.clearInterval(opPhoneState.paymentPollTimer);
        opPhoneState.paymentPollTimer = null;
      }
      function startOperatorPaymentPhonePolling() {
        stopOperatorPaymentPhonePolling();
        refreshOperatorPhone();
        opPhoneState.paymentPollTimer = window.setInterval(() => {
          if (!paymentModal?.classList.contains('open') || paymentMethod?.value !== 'qr_code') {
            stopOperatorPaymentPhonePolling();
            return;
          }
          refreshOperatorPhone(true);
        }, 2500);
      }
      function setOperatorPhoneStatus(type, title, detail) {
        const status = document.getElementById('opPhoneRegistrationStatus');
        if (!status) return;
        status.classList.toggle('success', type === 'success');
        status.classList.toggle('error', type === 'error');
        status.querySelector('strong').textContent = title;
        status.querySelector('small').textContent = detail;
      }
      function stopOperatorPhoneRegistrationPolling() {
        if (opPhoneState.pollTimer) window.clearInterval(opPhoneState.pollTimer);
        opPhoneState.pollTimer = null;
      }
      async function checkOperatorPhoneRegistration(manual = false) {
        if (manual) setOperatorPhoneStatus('', 'Checking registration...', 'Looking for a newly registered operator phone.');
        try {
          const payload = await fetchOperatorPhone();
          const device = payload.registered ? payload.device : null;
          const baseline = opPhoneState.baseline;
          const changed = device && (!baseline || Number(device.device_id) !== Number(baseline.device_id) || String(device.last_used_at) !== String(baseline.last_used_at));
          renderOperatorPhone(payload);
          if (changed) {
            stopOperatorPhoneRegistrationPolling();
            setOperatorPhoneStatus('success', 'Operator phone registered', `${device.device_name || 'Operator phone'} is ready for payment QR notifications.`);
            Swal.fire({ toast: true, position: 'top-end', icon: 'success', title: 'Operator phone registration detected.', showConfirmButton: false, timer: 2200 });
          } else if (manual) {
            setOperatorPhoneStatus('', 'No new phone detected', `Checked ${new Date().toLocaleTimeString()}. Finish registration on the phone, then check again.`);
          }
        } catch (error) {
          setOperatorPhoneStatus('error', 'Could not check registration', error.message);
        }
      }
      function openOperatorPhoneRegistration() {
        const modal = document.getElementById('opPhoneRegistrationModal');
        if (!modal) return;
        opPhoneState.baseline = opPhoneState.device ? { ...opPhoneState.device } : null;
        document.getElementById('opPhoneSetupUrl').value = operatorPhoneSetupUrl();
        document.getElementById('opPhoneModalTitle').textContent = opPhoneState.baseline ? 'Change Registered Operator Phone' : 'Register an Operator Phone';
        setOperatorPhoneStatus('', 'Waiting for phone registration', 'Keep this window open while registering the phone.');
        modal.inert = false;
        modal.setAttribute('aria-hidden', 'false');
        modal.classList.add('show');
        refreshOperatorPhone(true);
        stopOperatorPhoneRegistrationPolling();
        opPhoneState.pollTimer = window.setInterval(checkOperatorPhoneRegistration, 2500);
      }
      function closeOperatorPhoneRegistration() {
        const modal = document.getElementById('opPhoneRegistrationModal');
        if (!modal) return;
        stopOperatorPhoneRegistrationPolling();
        modal.classList.remove('show');
        modal.setAttribute('aria-hidden', 'true');
        modal.inert = true;
        if (paymentMethod?.value === 'qr_code') refreshOperatorPhone(true);
      }
      function openOperatorPhoneOverview() {
        const modal = document.getElementById('opPhoneOverviewModal');
        if (!modal) return;
        modal.inert = false;
        modal.setAttribute('aria-hidden', 'false');
        modal.classList.add('show');
      }
      function closeOperatorPhoneOverview() {
        const modal = document.getElementById('opPhoneOverviewModal');
        if (!modal) return;
        modal.classList.remove('show');
        modal.setAttribute('aria-hidden', 'true');
        modal.inert = true;
      }
      async function requireOperatorPaymentPhone() {
        try {
          const payload = await fetchOperatorPhone();
          renderOperatorPhone(payload);
          if (payload.registered && payload.device) return true;
          const choice = await Swal.fire({ icon: 'error', title: 'Payment Phone Required', text: 'Register an operator phone before using QR Code (PayMongo).', showCancelButton: true, confirmButtonText: 'Register a Phone', cancelButtonText: 'Cancel', confirmButtonColor: '#176b55' });
          if (choice.isConfirmed) openOperatorPhoneRegistration();
          return false;
        } catch (error) {
          await Swal.fire('Phone Check Failed', error.message || 'The registered payment phone could not be checked.', 'error');
          return false;
        }
      }
      async function cancelOperatorQrPayment(bookingId, returnToken) {
        const form = new FormData();
        form.append('action', 'cancel_pending'); form.append('type', 'tour'); form.append('id', String(bookingId)); form.append('return_token', String(returnToken)); form.append('csrf_token', opPayMongoCsrf);
        const response = await fetch(opPayMongoEndpoint, { method: 'POST', body: form, headers: { Accept: 'application/json' } });
        const result = await readPaymentJson(response);
        if (!response.ok || !result.success) throw new Error(result.message || 'The pending QR payment could not be cancelled.');
        return Boolean(result.cancelled);
      }
      async function monitorOperatorPhoneQrPayment(token, bookingId) {
        let cancelRequested = false;
        Swal.fire({ title: 'QR Sent to Operator Phone', text: 'Waiting for the tourist payment. The booking updates automatically after PayMongo verifies it.', allowOutsideClick: false, allowEscapeKey: false, showCancelButton: true, showConfirmButton: false, cancelButtonText: 'Cancel Payment', cancelButtonColor: '#b5444f', didOpen: () => Swal.showLoading() }).then(result => {
          if (result.dismiss === Swal.DismissReason.cancel) cancelRequested = true;
        });
        for (let attempt = 0; attempt < 120; attempt += 1) {
          if (cancelRequested) {
            sessionStorage.removeItem('itour_operator_paymongo_pending');
            try {
              const cancelled = await cancelOperatorQrPayment(bookingId, token);
              await Swal.fire(cancelled ? 'Payment Cancelled' : 'Nothing to Cancel', cancelled ? 'No amount was applied to the booking.' : 'No active pending payment was found.', cancelled ? 'info' : 'warning');
              if (cancelled) window.location.reload();
            } catch (error) { await Swal.fire('Cancellation Not Confirmed', error.message, 'warning'); }
            return false;
          }
          try {
            const statusUrl = new URL(window.location.href); statusUrl.search = ''; statusUrl.searchParams.set('action', 'paymongo_payment_status'); statusUrl.searchParams.set('token', token);
            const response = await fetch(statusUrl.href, { headers: { Accept: 'application/json' }, cache: 'no-store' });
            const result = await readPaymentJson(response);
            if (!response.ok || !result.success) throw new Error(result.message || 'Payment status could not be checked.');
            if (result.status === 'paid') {
              sessionStorage.removeItem('itour_operator_paymongo_pending');
              await Swal.fire({ icon: 'success', title: 'QR Payment Verified', text: `${billingMoney(result.amount)} was applied to Booking ${result.booking_reference || result.booking_id}.`, confirmButtonColor: '#176b55' });
              window.location.reload(); return true;
            }
            if (['cancelled', 'failed'].includes(result.status)) {
              sessionStorage.removeItem('itour_operator_paymongo_pending');
              await Swal.fire('Payment Not Completed', 'The phone QR payment was not completed.', 'warning');
              if (result.status === 'cancelled') window.location.reload();
              return false;
            }
          } catch (_) {}
          await new Promise(resolve => setTimeout(resolve, 2500));
        }
        await Swal.fire('Confirmation Pending', 'The QR remains active, but verification is taking longer than expected.', 'info');
        return false;
      }
      const expenseLabels = {
        additional_boat: 'Additional Boat',
        additional_tourguide: 'Additional Tour Guide',
        food: 'Food',
        others: 'Other Expense',
        'Environmental Fee': 'Environmental Fee',
        'Entrance Fee': 'Entrance Fee',
        'Docking / Landing Fee': 'Docking / Landing Fee',
        'Other Fee': 'Other Fee'
      };
      const closeBilling = () => {
        billingModal?.classList.remove('show');
        billingModal?.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('op-billing-open');
      };
      const openBilling = async bookingId => {
        if (!billingModal || !billingBody || !bookingId) return;
        billingState.bookingId = Number(bookingId);
        billingState.balance = 0;
        billingModal.classList.add('show');
        billingModal.setAttribute('aria-hidden', 'false');
        document.body.classList.add('op-billing-open');
        billingSubtitle.textContent = 'Loading current charges and payment information…';
        billingBody.innerHTML = '<div class="op-billing-loading"><i aria-hidden="true"></i><span>Preparing billing statement…</span></div>';
        billingAddExpense.disabled = true;
        billingPayBalance.disabled = true;

        try {
          const response = await fetch(`opbookings.php?action=fetch_billing&id=${encodeURIComponent(bookingId)}`, {
            headers: { Accept: 'application/json' }, cache: 'no-store'
          });
          const payload = await response.json();
          if (!response.ok || !payload.success || !payload.booking) throw new Error(payload.message || 'Billing details could not be loaded.');

          const booking = payload.booking;
          const expenses = Array.isArray(payload.expenses) ? payload.expenses : [];
          const expenseTotal = expenses.reduce((sum, expense) => sum + Number(expense.amount || 0), 0);
          const amountPaid = Number(booking.payment_amount || 0);
          const balance = Math.max(0, Number(booking.remaining_balance || 0));
          const billingTotal = Math.max(Number(booking.grand_total || 0), amountPaid + balance);
          const serviceAmount = Math.max(0, billingTotal - expenseTotal);
          const isPaid = Number(booking.is_paid || 0) === 1 || balance <= 0;
          const paymentStatus = isPaid ? 'Paid' : amountPaid > 0 ? 'Partial' : 'Unpaid';
          const normalizedStatus = String(booking.status || '').toLowerCase();
          const expenseRows = expenses.length ? expenses.map(expense => {
            const rawType = String(expense.expense_type || 'Expense');
            const label = expenseLabels[rawType] || rawType.replace(/_/g, ' ').replace(/\b\w/g, letter => letter.toUpperCase());
            const note = String(expense.note || '').trim();
            return `<div class="op-billing-line"><span>${escapeBilling(label)}${note ? `<small>${escapeBilling(note)}</small>` : ''}</span><strong>${billingMoney(expense.amount)}</strong></div>`;
          }).join('') : '<div class="op-billing-line"><span>No additional expenses</span><strong>\u20B10.00</strong></div>';

          billingState.balance = balance;
          billingState.canAddExpense = ['pending', 'accepted'].includes(normalizedStatus);
          billingSubtitle.textContent = `${booking.guest_name || 'Guest'} · ${booking.package_name || 'Tour package'}`;
          billingBody.innerHTML = `
            <div class="op-billing-summary">
              <div class="op-billing-reference"><small>BOOKING REFERENCE</small><strong>${escapeBilling(booking.booking_reference || booking.booking_id)}</strong></div>
              <span class="op-billing-status ${paymentStatus.toLowerCase()}">${paymentStatus}</span>
            </div>
            <div class="op-billing-party">
              <div><small>BILLED TO</small><strong>${escapeBilling(booking.guest_name || 'Guest')}</strong></div>
              <div><small>TOUR / SERVICE</small><strong>${escapeBilling(booking.package_name || 'Tour package')}</strong></div>
            </div>
            <h4 class="op-billing-section-title">Detailed Charges</h4>
            <div class="op-billing-lines">
              <div class="op-billing-line"><span>Tour service amount</span><strong>${billingMoney(serviceAmount)}</strong></div>
              ${expenseRows}
              <div class="op-billing-line subtotal"><span>Additional expenses</span><strong>${billingMoney(expenseTotal)}</strong></div>
              <div class="op-billing-line total"><span>Total amount due</span><strong>${billingMoney(billingTotal)}</strong></div>
            </div>
            <h4 class="op-billing-section-title">Payment Summary</h4>
            <div class="op-billing-stats">
              <div class="op-billing-stat"><small>AMOUNT PAID</small><strong>${billingMoney(amountPaid)}</strong></div>
              <div class="op-billing-stat balance ${isPaid ? 'paid' : ''}"><small>CURRENT BALANCE</small><strong>${billingMoney(balance)}</strong></div>
              <div class="op-billing-stat"><small>PAYMENT STATUS</small><strong>${paymentStatus}</strong></div>
              <div class="op-billing-stat"><small>LAST PAYMENT METHOD</small><strong>${escapeBilling(booking.payment_method ? String(booking.payment_method).replace(/_/g, ' ').toUpperCase() : 'Not recorded')}</strong></div>
            </div>`;
          billingAddExpense.disabled = !billingState.canAddExpense;
          billingPayBalance.disabled = isPaid || normalizedStatus !== 'accepted';
        } catch (error) {
          billingSubtitle.textContent = 'Billing statement unavailable';
          billingBody.innerHTML = `<div class="op-billing-error"><strong>Unable to load billing details</strong><p>${escapeBilling(error.message)}</p></div>`;
        }
      };

      document.querySelectorAll('[data-billing]').forEach(button => {
        button.addEventListener('click', () => openBilling(button.dataset.id));
      });
      document.querySelectorAll('[data-close-billing]').forEach(button => button.addEventListener('click', closeBilling));
      billingModal?.addEventListener('mousedown', event => { if (event.target === billingModal) closeBilling(); });
      document.addEventListener('keydown', event => { if (event.key === 'Escape') closeBilling(); });
      billingAddExpense?.addEventListener('click', () => {
        if (!billingState.bookingId || !billingState.canAddExpense) return;
        document.getElementById('opExpenseBookingId').value = String(billingState.bookingId);
        closeBilling();
        openModal(expenseModal);
      });
      billingPayBalance?.addEventListener('click', () => {
        if (!billingState.bookingId || billingState.balance <= 0) return;
        closeBilling();
        preparePaymentModal(billingState.bookingId, billingState.balance);
      });
      document.querySelectorAll('[data-close-modal]').forEach(button => {
        button.addEventListener('click', () => {
          const modal = document.getElementById(button.dataset.closeModal);
          closeModal(modal);
          if (modal === paymentModal) stopOperatorPaymentPhonePolling();
        });
      });
      [paymentModal, expenseModal].forEach(modal => modal?.addEventListener('click', (event) => {
        if (event.target === modal) { closeModal(modal); if (modal === paymentModal) stopOperatorPaymentPhonePolling(); }
      }));

      document.querySelectorAll('[data-payment]').forEach(button => {
        button.addEventListener('click', () => {
          preparePaymentModal(button.dataset.id, Number(button.dataset.balance || 0));
        });
      });
      document.querySelectorAll('[data-expense]').forEach(button => {
        button.addEventListener('click', () => {
          document.getElementById('opExpenseBookingId').value = button.dataset.id || '';
          openModal(expenseModal);
        });
      });

      document.querySelectorAll('[data-complete]').forEach(button => {
        button.addEventListener('click', async () => {
          const bookingId = button.dataset.id || '';
          const balance = Number(button.dataset.balance || 0);
          if (balance > 0) {
            preparePaymentModal(bookingId, balance, true);
            return;
          }
          if (!window.confirm('Mark this paid booking as completed?')) return;
          try {
            const body = new URLSearchParams({ action: 'mark_completed', booking_id: bookingId, csrf_token: operatorBookingCsrf });
            const response = await fetch('opbookings.php', {
              method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body.toString()
            });
            const result = await response.json();
            if (!result.success) throw new Error(result.message || 'Unable to complete the booking.');
            window.location.reload();
          } catch (error) { window.alert(error.message); }
        });
      });

      const submitSharedUpdate = async (form, action) => {
        const body = new URLSearchParams(new FormData(form));
        body.set('action', action);
        body.set('csrf_token', operatorBookingCsrf);
        const response = await fetch('opbookings.php', {
          method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body.toString()
        });
        const result = await response.json();
        if (!result.success) throw new Error(result.message || 'Unable to save the update.');
        return result;
      };
      paymentMethod?.addEventListener('change', updatePaymentMethod);
      document.getElementById('opManagePhoneButton')?.addEventListener('click', openOperatorPhoneRegistration);
      document.getElementById('opPaymentPhoneButton')?.addEventListener('click', async () => {
        await refreshOperatorPhone(true);
        openOperatorPhoneOverview();
      });
      document.getElementById('opPhoneOverviewAction')?.addEventListener('click', () => {
        closeOperatorPhoneOverview();
        openOperatorPhoneRegistration();
      });
      document.querySelectorAll('[data-close-operator-phone-overview]').forEach(button => button.addEventListener('click', closeOperatorPhoneOverview));
      document.getElementById('opPhoneOverviewModal')?.addEventListener('mousedown', event => { if (event.target.id === 'opPhoneOverviewModal') closeOperatorPhoneOverview(); });
      document.querySelectorAll('[data-close-operator-phone]').forEach(button => button.addEventListener('click', closeOperatorPhoneRegistration));
      document.getElementById('opPhoneRegistrationModal')?.addEventListener('mousedown', event => { if (event.target.id === 'opPhoneRegistrationModal') closeOperatorPhoneRegistration(); });
      document.getElementById('opCheckPhoneRegistration')?.addEventListener('click', () => checkOperatorPhoneRegistration(true));
      document.getElementById('opOpenPhoneSetup')?.addEventListener('click', () => window.open(operatorPhoneSetupUrl(), '_blank', 'noopener'));
      document.getElementById('opCopyPhoneSetupUrl')?.addEventListener('click', async () => {
        const value = document.getElementById('opPhoneSetupUrl')?.value || operatorPhoneSetupUrl();
        try { await navigator.clipboard.writeText(value); Swal.fire({ toast: true, position: 'top-end', icon: 'success', title: 'Setup link copied.', showConfirmButton: false, timer: 1800 }); }
        catch (_) { document.getElementById('opPhoneSetupUrl')?.select(); Swal.fire('Copy the Link', 'Copy the selected setup address and open it on the operator phone.', 'info'); }
      });
      document.getElementById('opPaymentForm')?.addEventListener('submit', async (event) => {
        event.preventDefault();
        const form = event.currentTarget;
        const bookingId = String(form.elements.booking_id?.value || '');
        const method = String(form.elements.payment_method?.value || '');
        const amountInput = form.elements.amount;
        const amount = Number(amountInput?.value || 0);
        const maximum = Number(amountInput?.max || 0);
        const completeAfterPayment = form.elements.complete_after_payment?.value === '1';
        if (!method) {
          await Swal.fire('Payment Method Required', 'Select Cash or QR Code before continuing.', 'warning');
          return;
        }
        if (!bookingId || !Number.isFinite(amount) || amount <= 0) {
          await Swal.fire('Payment Required', 'Enter an amount greater than zero.', 'warning');
          return;
        }
        if (maximum > 0 && amount > maximum + 0.009) {
          await Swal.fire('Invalid Amount', 'Payment cannot exceed the current balance.', 'warning');
          return;
        }
        if (completeAfterPayment && Math.abs(amount - maximum) > 0.009) {
          await Swal.fire('Full Payment Required', 'Collect the entire balance before completing this booking.', 'warning');
          return;
        }
        if (method === 'qr_code' && !(await requireOperatorPaymentPhone())) return;

        const idleLabel = method === 'qr_code' ? 'Send PayMongo QR' : 'Confirm Payment';
        if (paymentSubmit) {
          paymentSubmit.disabled = true;
          paymentSubmit.classList.add('is-loading');
          paymentSubmit.setAttribute('aria-busy', 'true');
          paymentSubmit.innerHTML = `<span class="op-payment-spinner" aria-hidden="true"></span><span>${method === 'qr_code' ? 'Sending QR…' : 'Recording…'}</span>`;
        }
        await new Promise(resolve => window.requestAnimationFrame(resolve));
        try {
          if (method === 'qr_code') {
            const checkoutData = new FormData();
            checkoutData.append('type', 'tour');
            checkoutData.append('id', bookingId);
            checkoutData.append('amount', amount.toFixed(2));
            checkoutData.append('csrf_token', opPayMongoCsrf);
            if (completeAfterPayment) checkoutData.append('complete_after_payment', '1');
            const response = await fetch(opPayMongoEndpoint, {
              method: 'POST', body: checkoutData, headers: { Accept: 'application/json' }
            });
            const data = await readPaymentJson(response);
            if (!response.ok || !data.success) throw new Error(data.message || 'The PayMongo QR payment page could not be opened.');
            const checkoutUrl = new URL(String(data.checkout_url || ''));
            if (checkoutUrl.protocol !== 'https:' || (checkoutUrl.hostname !== 'checkout.paymongo.com' && !checkoutUrl.hostname.endsWith('.paymongo.com'))) {
              throw new Error('PayMongo returned an invalid checkout URL.');
            }
            sessionStorage.setItem('itour_operator_paymongo_pending', JSON.stringify({
              id: Number(bookingId), token: String(data.return_token || ''), startedAt: Date.now()
            }));
            if (data.phone_notification?.sent && /^[a-f0-9]{64}$/.test(String(data.return_token || ''))) {
              closeModal(paymentModal);
              stopOperatorPaymentPhonePolling();
              await monitorOperatorPhoneQrPayment(String(data.return_token), Number(bookingId));
              return;
            }
            const fallback = await Swal.fire({
              icon: 'warning', title: 'Phone Notification Not Sent',
              text: data.phone_notification?.message || 'The QR was created, but it could not be delivered to the registered operator phone.',
              showCancelButton: true, confirmButtonText: 'Open QR on This Computer', cancelButtonText: 'Close', confirmButtonColor: '#176b55'
            });
            if (fallback.isConfirmed) window.location.assign(checkoutUrl.href);
            return;
          }

          const result = await submitSharedUpdate(form, 'confirm_payment');
          closeModal(paymentModal);
          await Swal.fire({
            icon: 'success',
            title: result.completed ? 'Booking Completed' : 'Payment Recorded',
            text: result.completed
              ? 'The full cash balance was recorded and the booking was marked completed.'
              : (result.is_paid ? 'The booking is now fully paid.' : 'The cash payment was recorded successfully.'),
            confirmButtonColor: '#176b55'
          });
          window.location.reload();
        } catch (error) {
          await Swal.fire(method === 'qr_code' ? 'QR Payment Failed' : 'Payment Failed', error.message, 'error');
        } finally {
          if (paymentSubmit) {
            paymentSubmit.disabled = false;
            paymentSubmit.classList.remove('is-loading');
            paymentSubmit.removeAttribute('aria-busy');
            paymentSubmit.textContent = idleLabel;
          }
        }
      });

      const handleOperatorPayMongoReturn = async () => {
        const params = new URLSearchParams(window.location.search);
        const returnType = params.get('payment_return');
        const token = params.get('payment_return_token') || '';
        if (!['paymongo', 'cancelled'].includes(returnType) || !/^[a-f0-9]{64}$/.test(token)) return;
        sessionStorage.removeItem('itour_operator_paymongo_pending');
        const cleanReturnUrl = () => {
          const url = new URL(window.location.href);
          url.searchParams.delete('payment_return');
          url.searchParams.delete('payment_return_token');
          history.replaceState({}, '', url.href);
        };
        if (returnType === 'cancelled') {
          cleanReturnUrl();
          await Swal.fire('QR Payment Cancelled', 'No payment was recorded and the booking balance was not changed.', 'info');
          return;
        }

        Swal.fire({
          title: 'Verifying QR Payment',
          text: 'Waiting for PayMongo confirmation. Do not record this payment manually.',
          allowOutsideClick: false,
          allowEscapeKey: false,
          didOpen: () => Swal.showLoading()
        });
        for (let attempt = 0; attempt < 15; attempt += 1) {
          try {
            const statusUrl = new URL(window.location.href);
            statusUrl.search = '';
            statusUrl.searchParams.set('action', 'paymongo_payment_status');
            statusUrl.searchParams.set('token', token);
            const response = await fetch(statusUrl.href, { headers: { Accept: 'application/json' }, cache: 'no-store' });
            const result = await readPaymentJson(response);
            if (!response.ok || !result.success) throw new Error(result.message || 'Payment status could not be checked.');
            if (result.status === 'paid') {
              cleanReturnUrl();
              await Swal.fire({
                icon: 'success',
                title: 'QR Payment Verified',
                text: `${billingMoney(result.amount)} was applied to Booking ${result.booking_reference || result.booking_id}.`,
                confirmButtonColor: '#176b55'
              });
              window.location.reload();
              return;
            }
            if (['failed', 'cancelled'].includes(result.status)) {
              cleanReturnUrl();
              await Swal.fire('Payment Not Completed', 'PayMongo did not verify a payment. The booking balance was not changed.', 'warning');
              return;
            }
          } catch (error) {
            if (attempt === 14) break;
          }
          await new Promise(resolve => setTimeout(resolve, 2000));
        }
        cleanReturnUrl();
        await Swal.fire('Confirmation Pending', 'PayMongo confirmation is still pending. Refresh shortly; the booking updates only after verification.', 'info');
      };

      handleOperatorPayMongoReturn();

      window.addEventListener('pageshow', async event => {
        const navigation = performance.getEntriesByType('navigation')[0];
        const returnedByHistory = event.persisted || navigation?.type === 'back_forward';
        const hasReturn = new URLSearchParams(window.location.search).has('payment_return');
        const pendingRaw = sessionStorage.getItem('itour_operator_paymongo_pending');
        if (!returnedByHistory || hasReturn || !pendingRaw) return;
        sessionStorage.removeItem('itour_operator_paymongo_pending');
        try {
          const pending = JSON.parse(pendingRaw);
          if (!pending?.id) return;
          const cancellation = new FormData();
          cancellation.append('action', 'cancel_pending');
          cancellation.append('type', 'tour');
          cancellation.append('id', String(pending.id));
          cancellation.append('return_token', String(pending.token || ''));
          cancellation.append('csrf_token', opPayMongoCsrf);
          const response = await fetch(opPayMongoEndpoint, { method: 'POST', body: cancellation, headers: { Accept: 'application/json' } });
          const result = await readPaymentJson(response);
          if (!response.ok || !result.success) throw new Error(result.message || 'The pending QR payment could not be closed.');
          await Swal.fire('QR Payment Cancelled', 'No payment was recorded. A new QR payment can be started when needed.', 'info');
        } catch (error) {
          await Swal.fire('Payment Status Notice', 'Refresh the page before starting another QR payment.', 'info');
        }
      });

      document.getElementById('opExpenseForm')?.addEventListener('submit', async (event) => {
        event.preventDefault();
        const form = event.currentTarget;
        const submitButton = form.querySelector('button[type="submit"]');
        const originalLabel = submitButton?.textContent || 'Save Expense';
        if (submitButton) {
          submitButton.disabled = true;
          submitButton.textContent = 'Saving…';
        }
        try {
          await submitSharedUpdate(form, 'add_expense');
          closeModal(expenseModal);
          if (window.Swal) {
            await Swal.fire({
              icon: 'success',
              title: 'Expense Added',
              text: 'The additional charge was added to the booking billing details.',
              confirmButtonText: 'Done',
              confirmButtonColor: '#176b55'
            });
          } else {
            window.alert('Expense added successfully.');
          }
          window.location.reload();
        } catch (error) {
          if (window.Swal) {
            await Swal.fire({ icon: 'error', title: 'Expense Not Added', text: error.message, confirmButtonColor: '#176b55' });
          } else {
            window.alert(error.message);
          }
          if (submitButton) {
            submitButton.disabled = false;
            submitButton.textContent = originalLabel;
          }
        }
      });
    })();
  </script>
  <script src="js/operator_header.js?v=4"></script>
</body>
</html>
