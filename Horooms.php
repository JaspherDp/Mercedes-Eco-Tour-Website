<?php
require_once __DIR__ . '/Ho_common.php';
require_once __DIR__ . '/php/activity_logger.php';
require_once __DIR__ . '/php/firebase_config.php';
require_once __DIR__ . '/php/input_validation.php';
require_once __DIR__ . '/php/secure_upload_helper.php';

$hoAdmin = HoRequireHotelAdmin($pdo);
$hoHotelResortId = (int)$hoAdmin['hotel_resort_id'];
$hoPropertyName = trim((string)($hoAdmin['property_name'] ?? ''));
HoEnsureHotelRoomsTable($pdo);
HoEnsureHotelBookingsTable($pdo);

$hoActive = 'rooms';
$hoTitle = 'Room Management';
$hoOwnerName = $hoPropertyName !== '' ? $hoPropertyName . ' Admin' : (string)$hoAdmin['username'];
$hoUnreadBadge = HoGetUnreadCount($pdo, $hoHotelResortId);
$hoNotifItems = HoGetNotificationItems($pdo, 8, $hoHotelResortId);
$hoPendingBadge = HoGetPendingCount($pdo, $hoHotelResortId);
$hoFirebaseConfiguration = firebase_public_configuration();
$hoPayMongoCsrf = (string)($_SESSION['paymongo_hotel_admin_csrf'] ?? '');
if ($hoPayMongoCsrf === '') {
    $hoPayMongoCsrf = bin2hex(random_bytes(32));
    $_SESSION['paymongo_hotel_admin_csrf'] = $hoPayMongoCsrf;
}
$hotelRoomCsrf = AppCsrfToken('hotel_admin', 'room_management');
$hotelNotificationCsrf = AppCsrfToken('hotel_admin', 'notifications');
$roomStatusFilter = strtolower(trim((string)($_GET['status'] ?? 'active')));
if (!in_array($roomStatusFilter, ['active', 'archived'], true)) {
    $roomStatusFilter = 'active';
}
$roomSearch = trim((string)($_GET['q'] ?? ''));
$roomStateFilter = strtolower(trim((string)($_GET['room_state'] ?? 'all')));
if (!in_array($roomStateFilter, ['all', 'available', 'booked', 'occupied'], true)) {
    $roomStateFilter = 'all';
}
$roomTypeFilter = trim((string)($_GET['room_type'] ?? ''));
$roomView = strtolower(trim((string)($_GET['view'] ?? 'card')));
if (!in_array($roomView, ['card', 'list'], true)) {
    $roomView = 'card';
}
$selectedRoomId = max(0, (int)($_GET['selected_room'] ?? 0));
$requestedCheckinBookingId = max(0, (int)($_GET['checkin_booking'] ?? 0));

if (isset($_POST['ho_action']) && $_POST['ho_action'] === 'mark_notifications_read') {
    if (!AppVerifyCsrf('hotel_admin', 'notifications', $_POST['csrf_token'] ?? null)) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['ok' => false]);
        exit;
    }
    HoMarkNotificationsRead($pdo, $hoHotelResortId);
    header('Content-Type: application/json');
    echo json_encode(['ok' => true]);
    exit;
}

function HoEnsureRoomUploadDirectory(): array
{
    $relativeDir = 'uploads/hotel_rooms';
    $absoluteDir = ItourEnsureProjectDirectory($relativeDir);
    return [$absoluteDir, $relativeDir];
}

function HoSaveUploadedRoomImage(?array $file, string $absoluteDir, string $relativeDir): ?string
{
    if (!$file || (int)($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return null;
    $validated = ItourSecureValidateUploadedImage($file, 40 * 1024 * 1024, 40000000, 12000, 12000);
    $filename = 'room_' . date('YmdHis') . '_' . bin2hex(random_bytes(6)) . '.webp';
    $targetAbs = $absoluteDir . DIRECTORY_SEPARATOR . $filename;
    try {
        ItourSecureOptimizeUploadedImage($validated, $targetAbs, 1600, 'image/webp');
        ItourAssertPublicMediaFile($targetAbs);
    } catch (Throwable $exception) {
        if (is_file($targetAbs)) @unlink($targetAbs);
        throw $exception;
    }
    return $relativeDir . '/' . $filename;
}

function HoSaveUploadedRoomImages(?array $files, string $absoluteDir, string $relativeDir): array
{
    if (
        !$files ||
        !isset($files['name'], $files['tmp_name'], $files['error']) ||
        !is_array($files['name']) ||
        !is_array($files['tmp_name']) ||
        !is_array($files['error'])
    ) {
        return [];
    }

    $saved = [];
    $count = count($files['name']);
    for ($i = 0; $i < $count; $i++) {
        $entry = [
            'name' => $files['name'][$i] ?? '',
            'type' => $files['type'][$i] ?? '',
            'tmp_name' => $files['tmp_name'][$i] ?? '',
            'error' => $files['error'][$i] ?? UPLOAD_ERR_NO_FILE,
            'size' => $files['size'][$i] ?? 0,
        ];
        $path = HoSaveUploadedRoomImage($entry, $absoluteDir, $relativeDir);
        if ($path) {
            $saved[] = $path;
        }
    }
    return $saved;
}

function HoSaveUploadedRoomImagesIndexed(?array $files, string $absoluteDir, string $relativeDir): array
{
    if (
        !$files ||
        !isset($files['name'], $files['tmp_name'], $files['error']) ||
        !is_array($files['name']) ||
        !is_array($files['tmp_name']) ||
        !is_array($files['error'])
    ) {
        return [];
    }

    $saved = [];
    $count = count($files['name']);
    try {
        for ($i = 0; $i < $count; $i++) {
            $entry = [
                'name' => $files['name'][$i] ?? '',
                'type' => $files['type'][$i] ?? '',
                'tmp_name' => $files['tmp_name'][$i] ?? '',
                'error' => $files['error'][$i] ?? UPLOAD_ERR_NO_FILE,
                'size' => $files['size'][$i] ?? 0,
            ];
            $path = HoSaveUploadedRoomImage($entry, $absoluteDir, $relativeDir);
            if ($path) $saved[$i] = $path;
        }
    } catch (Throwable $exception) {
        foreach ($saved as $newPath) {
            $basename = basename((string)$newPath);
            $absolute = $absoluteDir . DIRECTORY_SEPARATOR . $basename;
            if ($basename !== '' && is_file($absolute)) @unlink($absolute);
        }
        throw $exception;
    }
    return $saved;
}

function HoParseLocalDateTime(mixed $value): ?string
{
    if ($value === null || $value === '') return null;
    try {
        return ItourValidationDateTime($value, 'Operational date and time');
    } catch (InvalidArgumentException) {
        return null;
    }
}

function HoMergeRoomBookingItems(array $byId, array $byName, int $roomId, string $roomName): array
{
    $items = [];
    $nameKey = mb_strtolower(trim($roomName));
    if ($roomId > 0 && isset($byId[$roomId]) && is_array($byId[$roomId])) {
        $items = array_merge($items, $byId[$roomId]);
    }
    if ($nameKey !== '' && isset($byName[$nameKey]) && is_array($byName[$nameKey])) {
        $items = array_merge($items, $byName[$nameKey]);
    }

    if (!$items) {
        return [];
    }

    $unique = [];
    foreach ($items as $item) {
        $bookingId = (int)($item['hotel_booking_id'] ?? 0);
        if ($bookingId < 1 || isset($unique[$bookingId])) {
            continue;
        }
        $unique[$bookingId] = $item;
    }

    $merged = array_values($unique);
    usort($merged, static function (array $a, array $b): int {
        $aTs = strtotime((string)($a['created_at'] ?? '')) ?: 0;
        $bTs = strtotime((string)($b['created_at'] ?? '')) ?: 0;
        if ($aTs !== $bTs) {
            return $bTs <=> $aTs;
        }
        return ((int)($b['hotel_booking_id'] ?? 0)) <=> ((int)($a['hotel_booking_id'] ?? 0));
    });

    return $merged;
}

function HoUpcomingBookingDisplay(array $booking, string $today): array
{
    $checkin = (string)($booking['checkin_date'] ?? '');
    $checkout = (string)($booking['checkout_date'] ?? '');
    $status = strtolower((string)($booking['booking_status'] ?? 'pending'));
    $prefix = $status === 'confirmed' ? 'Reserved' : 'Pending request';

    $todayDate = DateTimeImmutable::createFromFormat('!Y-m-d', $today);
    $checkinDate = DateTimeImmutable::createFromFormat('!Y-m-d', $checkin);
    $checkoutDate = DateTimeImmutable::createFromFormat('!Y-m-d', $checkout);
    $daysUntil = ($todayDate && $checkinDate) ? (int)$todayDate->diff($checkinDate)->format('%r%a') : 0;
    $arrival = $daysUntil === 1 ? 'tomorrow' : ($daysUntil > 1 ? "in {$daysUntil} days" : 'soon');
    $stay = $checkinDate ? $checkinDate->format('M j') : $checkin;
    if ($checkoutDate) {
        $stay .= '–' . $checkoutDate->format('M j');
    }

    return [
        'title' => $prefix . ' ' . $arrival,
        'stay' => $stay,
        'guest' => (string)($booking['guest_name'] ?? 'Guest'),
    ];
}

$flash = '';
$flashTitle = 'Success';
$error = '';
$validCheckinIdTypes = [
    'philsys' => 'PhilSys National ID',
    'passport' => 'Passport',
    'drivers_license' => "Driver's License",
    'umid' => 'UMID',
    'prc' => 'PRC ID',
    'postal' => 'Postal ID',
    'voters' => "Voter's ID",
    'senior_citizen' => 'Senior Citizen ID',
    'pwd' => 'PWD ID',
    'student' => 'Student ID',
    'other_government' => 'Other government-issued ID',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['room_action'])) {
    if (!AppVerifyCsrf('hotel_admin', 'room_management', $_POST['csrf_token'] ?? null)) {
        http_response_code(403);
        exit('Invalid security token. Refresh the page and try again.');
    }
    [$roomUploadAbsDir, $roomUploadRelDir] = HoEnsureRoomUploadDirectory();
    $action = trim((string)$_POST['room_action']);
    $roomId = (int)($_POST['room_id'] ?? 0);
    $roomName = trim((string)($_POST['room_name'] ?? ''));
    $roomDescription = trim((string)($_POST['description'] ?? ''));
    $price = 0.0;
    $capacityAdults = 1;
    $capacityChildren = 0;
    $availableUnits = 1;
    $breakfastFor = 0;
    $bookingId = 0;
    $checkinGuestName = trim((string)($_POST['checkin_guest_name'] ?? ''));
    $checkinRepresentativeName = trim((string)($_POST['checkin_representative_name'] ?? ''));
    $checkinGuestCount = 0;
    $checkinDateTime = HoParseLocalDateTime($_POST['checkin_date_time'] ?? null);
    $checkinIdType = strtolower(trim((string)($_POST['checkin_id_type'] ?? '')));
    $checkinIdReference = trim((string)($_POST['checkin_id_reference'] ?? ''));
    $checkinRemainingPayment = 0.0;
    $checkinPaymentMethod = strtolower(trim((string)($_POST['checkin_payment_method'] ?? '')));
    $checkinPayMongoToken = strtolower(trim((string)($_POST['checkin_paymongo_token'] ?? '')));
    $checkoutGuestName = trim((string)($_POST['checkout_guest_name'] ?? ''));
    $checkoutRepresentativeName = trim((string)($_POST['checkout_representative_name'] ?? ''));
    $checkoutDateTime = HoParseLocalDateTime($_POST['checkout_date_time'] ?? null);
    $checkoutAdditionalCharges = 0.0;
    $checkoutFinalPayment = 0.0;
    $checkoutPaymentMethod = strtolower(trim((string)($_POST['checkout_payment_method'] ?? '')));
    $checkoutPayMongoToken = strtolower(trim((string)($_POST['checkout_paymongo_token'] ?? '')));
    $metaJsonRaw = trim((string)($_POST['meta_json'] ?? ''));
    $inclusionsRaw = trim((string)($_POST['inclusions_text'] ?? ''));
    $mainImagePath = trim((string)($_POST['main_image_path'] ?? ''));
    $galleryRaw = trim((string)($_POST['gallery_images_text'] ?? ''));
    $galleryPathsPosted = isset($_POST['gallery_paths']) && is_array($_POST['gallery_paths']) ? $_POST['gallery_paths'] : [];
    $inputValidationError = '';
    try {
        if (in_array($action, ['checkin', 'checkout'], true)) {
            $roomId = ItourValidationInt($_POST['room_id'] ?? null, 'Room ID', 1, PHP_INT_MAX);
            $bookingId = ItourValidationInt($_POST['booking_id'] ?? null, 'Booking ID', 1, PHP_INT_MAX);
        }
        if ($action === 'checkin') {
            $checkinGuestName = ItourValidationText($_POST['checkin_guest_name'] ?? null, 'Check-in guest name', 160, true);
            $checkinRepresentativeName = ItourValidationText($_POST['checkin_representative_name'] ?? '', 'Representative name', 160);
            $checkinGuestCount = ItourValidationInt($_POST['checkin_guest_count'] ?? null, 'Guest count', 1, 100);
            if ($checkinDateTime === null) throw new InvalidArgumentException('Enter a valid check-in date and time.');
            $checkinIdReference = ItourValidationText($_POST['checkin_id_reference'] ?? null, 'ID reference', 100, true);
            $checkinRemainingPayment = ItourValidationMoney($_POST['checkin_remaining_payment'] ?? 0, 'Check-in payment');
        } elseif ($action === 'checkout') {
            $checkoutGuestName = ItourValidationText($_POST['checkout_guest_name'] ?? null, 'Check-out guest name', 160, true);
            $checkoutRepresentativeName = ItourValidationText($_POST['checkout_representative_name'] ?? '', 'Representative name', 160);
            if ($checkoutDateTime === null) throw new InvalidArgumentException('Enter a valid check-out date and time.');
            $checkoutAdditionalCharges = ItourValidationMoney($_POST['checkout_additional_charges'] ?? 0, 'Additional charges');
            $checkoutFinalPayment = ItourValidationMoney($_POST['checkout_final_payment'] ?? 0, 'Final payment');
        } elseif (in_array($action, ['create', 'update'], true)) {
            $roomName = ItourValidationText($_POST['room_name'] ?? null, 'Room name', 160, true);
            $roomDescription = ItourValidationText($_POST['description'] ?? '', 'Room description', 5000);
            $price = ItourValidationMoney($_POST['price'] ?? null, 'Room price');
            $capacityAdults = ItourValidationInt($_POST['capacity_adults'] ?? null, 'Adult capacity', 1, 100);
            $capacityChildren = ItourValidationInt($_POST['capacity_children'] ?? 0, 'Child capacity', 0, 100);
            $breakfastFor = ItourValidationInt($_POST['breakfast_for'] ?? 0, 'Breakfast capacity', 0, 100);
            $galleryUploadCount = is_array($_FILES['gallery_row_files']['name'] ?? null)
                ? count($_FILES['gallery_row_files']['name']) : 0;
            $galleryTextCount = 0;
            if ($galleryRaw !== '') {
                foreach (preg_split('/\r\n|\r|\n/', $galleryRaw) ?: [] as $galleryLine) {
                    if (trim((string)$galleryLine) !== '') $galleryTextCount++;
                }
            }
            if ($galleryUploadCount > 20 || (count($galleryPathsPosted) + $galleryTextCount) > 20) {
                throw new InvalidArgumentException('A room gallery may contain at most 20 uploaded images.');
            }
        }
    } catch (InvalidArgumentException $exception) {
        $inputValidationError = $exception->getMessage();
    }
    $existingRoom = null;
    if ($action === 'update' && $roomId > 0) {
        $existingRoom = HoGetHotelRoomById($pdo, $hoHotelResortId, $roomId, false);
    }

    $metaParsed = json_decode($metaJsonRaw !== '' ? $metaJsonRaw : '{}', true);
    if (!is_array($metaParsed)) {
        $metaParsed = [];
    }

    $inclusions = [];
    if ($inclusionsRaw !== '') {
        $lines = preg_split('/\r\n|\r|\n/', $inclusionsRaw);
        foreach ($lines as $line) {
            $line = trim((string)$line);
            if ($line !== '') {
                $inclusions[] = $line;
            }
        }
    }

    $canUploadRoomMedia = $inputValidationError === '' && in_array($action, ['create', 'update'], true);
    $uploadedGalleryRows = [];
    if ($canUploadRoomMedia) {
        try {
            $uploadedGalleryRows = HoSaveUploadedRoomImagesIndexed($_FILES['gallery_row_files'] ?? null, $roomUploadAbsDir, $roomUploadRelDir);
        } catch (Throwable $exception) {
            $inputValidationError = $exception->getMessage();
            $canUploadRoomMedia = false;
        }
    }
    $galleryImages = [];
    $rowCount = max(count($galleryPathsPosted), !empty($uploadedGalleryRows) ? (max(array_keys($uploadedGalleryRows)) + 1) : 0);
    for ($idx = 0; $idx < $rowCount; $idx++) {
        $path = trim((string)($galleryPathsPosted[$idx] ?? ''));
        if (isset($uploadedGalleryRows[$idx]) && trim((string)$uploadedGalleryRows[$idx]) !== '') {
            $path = trim((string)$uploadedGalleryRows[$idx]);
        }
        if ($path !== '') {
            $galleryImages[] = $path;
        }
    }

    if ($galleryRaw !== '') {
        $lines = preg_split('/\r\n|\r|\n/', $galleryRaw);
        foreach ($lines as $line) {
            $line = trim((string)$line);
            if ($line !== '') {
                $galleryImages[] = $line;
            }
        }
    }
    $galleryImages = array_values(array_unique($galleryImages));

    $uploadedMainImage = null;
    if ($canUploadRoomMedia) {
        try {
            $uploadedMainImage = HoSaveUploadedRoomImage($_FILES['main_image_file'] ?? null, $roomUploadAbsDir, $roomUploadRelDir);
        } catch (Throwable $exception) {
            $inputValidationError = $exception->getMessage();
            foreach ($uploadedGalleryRows as $newGalleryPath) {
                if (is_string($newGalleryPath) && preg_match('#^uploads/hotel_rooms/[A-Za-z0-9._-]+$#D', $newGalleryPath)) {
                    $newGalleryAbsolute = ItourProjectPath($newGalleryPath);
                    if (is_file($newGalleryAbsolute)) @unlink($newGalleryAbsolute);
                }
            }
            $uploadedGalleryRows = [];
        }
    }
    if ($uploadedMainImage) {
        $mainImagePath = $uploadedMainImage;
    }

    if ($mainImagePath === '') {
        if ($existingRoom && trim((string)($existingRoom['main_image_path'] ?? '')) !== '') {
            $mainImagePath = trim((string)$existingRoom['main_image_path']);
        } else {
            $mainImagePath = 'img/sampleimage.png';
        }
    }

    if ($inputValidationError !== '') {
        $error = $inputValidationError;
    } elseif ($roomName === '' && !in_array($action, ['archive', 'checkin', 'checkout'], true)) {
        $error = 'Room name is required.';
    } elseif ($price < 0 && !in_array($action, ['archive', 'checkin', 'checkout'], true)) {
        $error = 'Price cannot be negative.';
    } else {
        if ($action === 'checkin' && $roomId > 0 && $bookingId > 0) {
            $room = HoGetHotelRoomById($pdo, $hoHotelResortId, $roomId, false);
            if (!$room) {
                $error = 'Room not found.';
            } else {
                $bookingStmt = $pdo->prepare("
                    SELECT *
                    FROM hotel_room_bookings
                    WHERE hotel_booking_id = ? AND hotel_resort_id = ?
                    LIMIT 1
                ");
                $bookingStmt->execute([$bookingId, $hoHotelResortId]);
                $booking = $bookingStmt->fetch(PDO::FETCH_ASSOC);

                if (!$booking) {
                    $error = 'Booking not found.';
                } else {
                    $bookingStatus = strtolower(trim((string)($booking['booking_status'] ?? 'pending')));
                    $bookingRoomId = (int)($booking['hotel_room_id'] ?? 0);
                    $bookingRoomType = trim((string)($booking['room_type'] ?? ''));
                    $roomMatch = $bookingRoomId > 0
                        ? $bookingRoomId === $roomId
                        : strcasecmp($bookingRoomType, (string)$room['room_name']) === 0;
                    if (!$roomMatch) {
                        $error = 'Selected booking does not belong to this room.';
                    } elseif ($bookingStatus !== 'confirmed') {
                        $error = 'Only confirmed bookings can be checked in.';
                    } elseif (!empty($booking['checked_in_at']) && empty($booking['checked_out_at'])) {
                        $error = 'Guest is already checked in for this booking.';
                    } elseif ($checkinGuestName === '') {
                        $error = 'Guest name is required for check-in.';
                    } elseif ($checkinGuestCount < 1) {
                        $error = 'Number of guests is required for check-in.';
                    } elseif ($checkinGuestCount > min(
                        max(1, (int)($booking['adults'] ?? 0) + (int)($booking['children'] ?? 0)),
                        max(1, (int)($room['capacity_adults'] ?? 0) + (int)($room['capacity_children'] ?? 0))
                    )) {
                        $error = 'Guest count exceeds the booking or room capacity.';
                    } elseif ($checkinDateTime === null) {
                        $error = 'Check-in date and time is required.';
                    } elseif (substr($checkinDateTime, 0, 10) < (string)$booking['checkin_date']
                        || substr($checkinDateTime, 0, 10) > (string)$booking['checkout_date']) {
                        $error = 'Check-in date and time must fall within the reserved stay.';
                    } elseif (!isset($validCheckinIdTypes[$checkinIdType])) {
                        $error = 'Select the valid ID presented by the guest.';
                    } elseif (mb_strlen($checkinIdReference) < 3 || mb_strlen($checkinIdReference) > 100) {
                        $error = 'Enter a valid ID reference number between 3 and 100 characters.';
                    } else {
                        $remaining = round(max(0, (float)($booking['remaining_balance'] ?? 0)), 2);
                        $bookingPaymentIncrement = 0.0;
                        $checkinPaymentRecordedAmount = 0.0;
                        $checkinPaymentRecordedMethod = null;

                        if ($checkinPaymentMethod === 'qr_code') {
                            if (!preg_match('/^[a-f0-9]{64}$/', $checkinPayMongoToken)) {
                                $error = 'Complete and verify the PayMongo QR payment before check-in.';
                            } else {
                                $verifiedPaymentStmt = $pdo->prepare(
                                    "SELECT amount_minor, metadata
                                     FROM payment_transactions
                                     WHERE return_token = ?
                                       AND booking_domain = 'hotel'
                                       AND booking_id = ?
                                       AND provider = 'paymongo'
                                       AND status = 'paid'
                                     LIMIT 1"
                                );
                                $verifiedPaymentStmt->execute([$checkinPayMongoToken, $bookingId]);
                                $verifiedPayment = $verifiedPaymentStmt->fetch(PDO::FETCH_ASSOC);
                                $verifiedMetadata = $verifiedPayment
                                    ? json_decode((string)($verifiedPayment['metadata'] ?? ''), true)
                                    : null;
                                if (!$verifiedPayment || !is_array($verifiedMetadata)
                                    || ($verifiedMetadata['source'] ?? '') !== 'hotel_checkin_payment'
                                    || (int)($verifiedMetadata['hotel_admin_id'] ?? 0) !== (int)$hoAdmin['hotel_admin_id']
                                    || (int)($verifiedMetadata['hotel_resort_id'] ?? 0) !== $hoHotelResortId) {
                                    $error = 'The PayMongo payment could not be verified for this check-in.';
                                } elseif ($remaining > 0.009) {
                                    $error = 'PayMongo verification did not settle the full booking balance.';
                                } else {
                                    $checkinPaymentRecordedAmount = ((int)$verifiedPayment['amount_minor']) / 100;
                                    $checkinPaymentRecordedMethod = 'qr_code';
                                }
                            }
                        } elseif ($remaining > 0) {
                            if ($checkinPaymentMethod !== 'cash') {
                                $error = 'Select Cash or QR Code (PayMongo) for the check-in payment.';
                            } elseif (abs(round($checkinRemainingPayment, 2) - $remaining) > 0.009) {
                                $error = 'The cash payment must match the current booking balance.';
                            } else {
                                $bookingPaymentIncrement = $remaining;
                                $checkinPaymentRecordedAmount = $remaining;
                                $checkinPaymentRecordedMethod = 'cash';
                            }
                        }
                    }

                    if ($error === '') {
                        $updateCheckin = $pdo->prepare("
                            UPDATE hotel_room_bookings
                            SET
                              hotel_room_id = ?,
                              room_type = ?,
                              checked_in_at = ?,
                              checkin_guest_name = ?,
                              checkin_representative_name = ?,
                              checkin_guest_count = ?,
                              checkin_id_type = ?,
                              checkin_id_reference = ?,
                              amount_paid = amount_paid + ?,
                              remaining_balance = ROUND(GREATEST(remaining_balance - ?, 0), 2),
                              payment_status = CASE
                                WHEN GREATEST(remaining_balance - ?, 0) <= 0 THEN 'paid'
                                WHEN amount_paid + ? > 0 THEN 'partial'
                                ELSE 'unpaid'
                              END,
                              checkin_payment_amount = ?,
                              checkin_payment_method = ?,
                              checkin_payment_recorded_at = CASE WHEN ? > 0 THEN ? ELSE checkin_payment_recorded_at END,
                              updated_at = NOW()
                            WHERE hotel_booking_id = ?
                              AND hotel_resort_id = ?
                              AND LOWER(TRIM(booking_status)) = 'confirmed'
                              AND checked_in_at IS NULL
                              AND checked_out_at IS NULL
                        ");
                        $updateCheckin->execute([
                            $roomId,
                            (string)$room['room_name'],
                            $checkinDateTime,
                            $checkinGuestName,
                            $checkinRepresentativeName !== '' ? $checkinRepresentativeName : null,
                            $checkinGuestCount,
                            $checkinIdType,
                            $checkinIdReference,
                            $bookingPaymentIncrement,
                            $bookingPaymentIncrement,
                            $bookingPaymentIncrement,
                            $bookingPaymentIncrement,
                            $checkinPaymentRecordedAmount,
                            $checkinPaymentRecordedMethod,
                            $checkinPaymentRecordedAmount,
                            $checkinDateTime,
                            $bookingId,
                            $hoHotelResortId,
                        ]);
                        if ($updateCheckin->rowCount() < 1) {
                            $error = 'Check-in could not be completed. Confirm that the booking is still confirmed and has not already been checked in.';
                        } else {
                            if ($checkinPaymentRecordedMethod === 'qr_code') {
                                $flashTitle = 'Payment confirmed';
                                $flash = 'The payment was verified and the guest is now checked in.';
                            } else {
                                $flashTitle = 'Check-in complete';
                                $flash = 'The guest is now checked in.';
                            }
                            logActivity(
                                $pdo, 'Hotel Owner', (int)$hoAdmin['hotel_admin_id'], (string)$hoOwnerName,
                                'Guest Checked In', 'Checked in hotel booking #' . $bookingId . '.',
                                'Hotel Rooms', $bookingId
                            );
                        }
                    }
                }
            }
        } elseif ($action === 'checkout' && $roomId > 0 && $bookingId > 0) {
            $room = HoGetHotelRoomById($pdo, $hoHotelResortId, $roomId, false);
            if (!$room) {
                $error = 'Room not found.';
            } else {
                $bookingStmt = $pdo->prepare("
                    SELECT *
                    FROM hotel_room_bookings
                    WHERE hotel_booking_id = ? AND hotel_resort_id = ?
                    LIMIT 1
                ");
                $bookingStmt->execute([$bookingId, $hoHotelResortId]);
                $booking = $bookingStmt->fetch(PDO::FETCH_ASSOC);

                if (!$booking) {
                    $error = 'Booking not found.';
                } else {
                    $bookingRoomId = (int)($booking['hotel_room_id'] ?? 0);
                    $bookingRoomType = trim((string)($booking['room_type'] ?? ''));
                    $roomMatch = $bookingRoomId > 0
                        ? $bookingRoomId === $roomId
                        : strcasecmp($bookingRoomType, (string)$room['room_name']) === 0;
                    $checkedInAt = trim((string)($booking['checked_in_at'] ?? ''));
                    $checkedOutAt = trim((string)($booking['checked_out_at'] ?? ''));

                    if (!$roomMatch) {
                        $error = 'Selected booking does not belong to this room.';
                    } elseif ($checkedInAt === '' || $checkedOutAt !== '') {
                        $error = 'This booking is not currently checked in.';
                    } elseif ($checkoutGuestName === '') {
                        $error = 'Guest name is required for check-out.';
                    } elseif ($checkoutRepresentativeName === '') {
                        $error = 'Representative name is required for check-out.';
                    } elseif ($checkoutDateTime === null) {
                        $error = 'Check-out date and time is required.';
                    } elseif (substr($checkoutDateTime, 0, 10) < (string)$booking['checkin_date']) {
                        $error = 'Check-out date and time cannot be before the reserved stay.';
                    } else {
                        try {
                            $checkedInNormalized = ItourValidationDateTime($checkedInAt, 'Stored check-in date and time', ['Y-m-d H:i:s']);
                            $checkedInObject = new DateTimeImmutable($checkedInNormalized);
                            $checkoutObject = new DateTimeImmutable($checkoutDateTime);
                        } catch (Throwable) {
                            $checkedInObject = null;
                            $checkoutObject = null;
                        }
                        if (!$checkedInObject || !$checkoutObject || $checkoutObject <= $checkedInObject) {
                            $error = 'Check-out date/time must be after check-in date/time.';
                        } else {
                            $durationNights = max(1, (int)ceil(($checkoutObject->getTimestamp() - $checkedInObject->getTimestamp()) / 86400));
                            $remaining = round(max(0, (float)($booking['remaining_balance'] ?? 0)), 2);
                            $dueAtCheckout = round($remaining + $checkoutAdditionalCharges, 2);
                            $bookingTotalIncrement = $checkoutAdditionalCharges;
                            $bookingPaymentIncrement = $dueAtCheckout;
                            $balanceChargeIncrement = $checkoutAdditionalCharges;
                            $balancePaymentIncrement = $dueAtCheckout;
                            $appliedFinalPayment = $dueAtCheckout;

                            $previousCheckoutMethod = strtolower(trim((string)($booking['checkout_payment_method'] ?? '')));
                            $previousCheckoutPayment = round(max(0, (float)($booking['checkout_final_payment_amount'] ?? 0)), 2);
                            $previousCheckoutCharges = round(max(0, (float)($booking['checkout_additional_charges'] ?? 0)), 2);
                            $recoverVerifiedCheckout = false;
                            if ($dueAtCheckout <= 0.009
                                && $checkoutPaymentMethod === ''
                                && $previousCheckoutMethod === 'qr_code'
                                && $previousCheckoutPayment > 0) {
                                // A verified checkout payment may have reconciled just before a lost
                                // browser submission. Preserve it and finish departure without charging again.
                                $checkoutPaymentMethod = 'qr_code';
                                $checkoutAdditionalCharges = $previousCheckoutCharges;
                                $appliedFinalPayment = $previousCheckoutPayment;
                                $bookingTotalIncrement = 0.0;
                                $bookingPaymentIncrement = 0.0;
                                $balanceChargeIncrement = 0.0;
                                $balancePaymentIncrement = 0.0;
                                $recoverVerifiedCheckout = true;
                            }

                            if ($checkoutPaymentMethod === 'qr_code') {
                                if ($recoverVerifiedCheckout) {
                                    // Already verified by PaymentReconciler; recovery path above.
                                } elseif (!preg_match('/^[a-f0-9]{64}$/', $checkoutPayMongoToken)) {
                                    $error = 'Complete and verify the PayMongo QR payment before check-out.';
                                } else {
                                    $verifiedPaymentStmt = $pdo->prepare(
                                        "SELECT amount_minor, metadata
                                         FROM payment_transactions
                                         WHERE return_token = ?
                                           AND booking_domain = 'hotel'
                                           AND booking_id = ?
                                           AND provider = 'paymongo'
                                           AND status = 'paid'
                                         LIMIT 1"
                                    );
                                    $verifiedPaymentStmt->execute([$checkoutPayMongoToken, $bookingId]);
                                    $verifiedPayment = $verifiedPaymentStmt->fetch(PDO::FETCH_ASSOC);
                                    $verifiedMetadata = $verifiedPayment
                                        ? json_decode((string)($verifiedPayment['metadata'] ?? ''), true)
                                        : null;
                                    $verifiedAdditional = is_array($verifiedMetadata)
                                        ? round((float)($verifiedMetadata['checkout_additional_charges'] ?? -1), 2)
                                        : -1;
                                    $verifiedDue = is_array($verifiedMetadata)
                                        ? round((float)($verifiedMetadata['checkout_amount_due'] ?? -1), 2)
                                        : -1;
                                    if (!$verifiedPayment || !is_array($verifiedMetadata)
                                        || ($verifiedMetadata['source'] ?? '') !== 'hotel_checkout_payment'
                                        || (int)($verifiedMetadata['hotel_admin_id'] ?? 0) !== (int)$hoAdmin['hotel_admin_id']
                                        || (int)($verifiedMetadata['hotel_resort_id'] ?? 0) !== $hoHotelResortId
                                        || abs($verifiedAdditional - round($checkoutAdditionalCharges, 2)) > 0.009
                                        || abs($verifiedDue - (((int)$verifiedPayment['amount_minor']) / 100)) > 0.009
                                        || abs($checkoutFinalPayment - $verifiedDue) > 0.009) {
                                        $error = 'The PayMongo payment could not be verified for this check-out.';
                                    } elseif ($remaining > 0.009) {
                                        $error = 'PayMongo verification did not settle the complete check-out balance.';
                                    } else {
                                        $dueAtCheckout = $verifiedDue;
                                        $appliedFinalPayment = $verifiedDue;
                                        // Reconciliation already applied the paid amount and incidental charges.
                                        $bookingTotalIncrement = 0.0;
                                        $bookingPaymentIncrement = 0.0;
                                        $balanceChargeIncrement = 0.0;
                                        $balancePaymentIncrement = 0.0;
                                    }
                                }
                            } elseif ($dueAtCheckout > 0) {
                                if ($checkoutPaymentMethod !== 'cash') {
                                    $error = 'Select Cash or QR Code (PayMongo) for the check-out payment.';
                                } elseif (abs(round($checkoutFinalPayment, 2) - $dueAtCheckout) > 0.009) {
                                    $error = 'Final payment must cover the remaining balance and additional charges.';
                                }
                            }

                            if ($error === '') {
                                $updateCheckout = $pdo->prepare("
                                    UPDATE hotel_room_bookings
                                    SET
                                      hotel_room_id = ?,
                                      room_type = ?,
                                      booking_status = 'completed',
                                      checked_out_at = ?,
                                      checkout_guest_name = ?,
                                      checkout_representative_name = ?,
                                      checkout_total_nights = ?,
                                      checkout_additional_charges = ?,
                                      checkout_final_payment_amount = ?,
                                      checkout_payment_method = ?,
                                      total_amount = total_amount + ?,
                                      amount_paid = amount_paid + ?,
                                      remaining_balance = ROUND(GREATEST((remaining_balance + ?) - ?, 0), 2),
                                      payment_status = CASE
                                        WHEN GREATEST((remaining_balance + ?) - ?, 0) <= 0 THEN 'paid'
                                        WHEN amount_paid + ? > 0 THEN 'partial'
                                        ELSE 'unpaid'
                                      END,
                                      updated_at = NOW()
                                    WHERE hotel_booking_id = ? AND hotel_resort_id = ?
                                      AND checked_in_at IS NOT NULL
                                      AND checked_out_at IS NULL
                                ");
                                $updateCheckout->execute([
                                    $roomId,
                                    (string)$room['room_name'],
                                    $checkoutDateTime,
                                    $checkoutGuestName,
                                    $checkoutRepresentativeName,
                                    $durationNights,
                                    $checkoutAdditionalCharges,
                                    $appliedFinalPayment,
                                    $appliedFinalPayment > 0 ? $checkoutPaymentMethod : null,
                                    $bookingTotalIncrement,
                                    $bookingPaymentIncrement,
                                    $balanceChargeIncrement,
                                    $balancePaymentIncrement,
                                    $balanceChargeIncrement,
                                    $balancePaymentIncrement,
                                    $bookingPaymentIncrement,
                                    $bookingId,
                                    $hoHotelResortId,
                                ]);
                                $flashTitle = $checkoutPaymentMethod === 'qr_code' ? 'Payment confirmed' : 'Check-out complete';
                                $flash = $checkoutPaymentMethod === 'qr_code'
                                    ? 'PayMongo verified the payment and the guest is now checked out.'
                                    : 'Guest checked out successfully. Room is now available.';
                                logActivity(
                                    $pdo, 'Hotel Owner', (int)$hoAdmin['hotel_admin_id'], (string)$hoOwnerName,
                                    'Guest Checked Out', 'Checked out hotel booking #' . $bookingId . '.',
                                    'Hotel Rooms', $bookingId
                                );
                            }
                        }
                    }
                }
            }
        } elseif ($action === 'create') {
            $dupStmt = $pdo->prepare("
                SELECT hotel_room_id
                FROM hotel_rooms
                WHERE hotel_resort_id = ? AND LOWER(room_name) = LOWER(?)
                LIMIT 1
            ");
            $dupStmt->execute([$hoHotelResortId, $roomName]);
            if ($dupStmt->fetch(PDO::FETCH_ASSOC)) {
                $error = 'A room with this name already exists.';
            }

            if ($error !== '') {
                // no-op, error already set
            } else {
            $insert = $pdo->prepare("
                INSERT INTO hotel_rooms
                (hotel_resort_id, room_name, description, price, capacity_adults, capacity_children, available_units, breakfast_for, room_meta_json, inclusions_json, main_image_path, gallery_images_json, status)
                VALUES
                (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active')
            ");
            try {
                $insert->execute([
                    $hoHotelResortId,
                    $roomName,
                    $roomDescription,
                    $price,
                    $capacityAdults,
                    $capacityChildren,
                    $availableUnits,
                    $breakfastFor,
                    json_encode($metaParsed, JSON_UNESCAPED_UNICODE),
                    json_encode($inclusions, JSON_UNESCAPED_UNICODE),
                    $mainImagePath,
                    json_encode($galleryImages, JSON_UNESCAPED_UNICODE),
                ]);
                $flash = 'Room added successfully.';
                logActivity(
                    $pdo, 'Hotel Owner', (int)$hoAdmin['hotel_admin_id'], (string)$hoOwnerName,
                    'Hotel Room Added', 'Added hotel room "' . $roomName . '".',
                    'Hotel Rooms', (int)$pdo->lastInsertId()
                );
            } catch (PDOException $e) {
                $error = 'Failed to add room. Please try again.';
            }
            }
        } elseif ($action === 'update' && $roomId > 0) {
            $dupStmt = $pdo->prepare("
                SELECT hotel_room_id
                FROM hotel_rooms
                WHERE hotel_resort_id = ? AND LOWER(room_name) = LOWER(?) AND hotel_room_id <> ?
                LIMIT 1
            ");
            $dupStmt->execute([$hoHotelResortId, $roomName, $roomId]);
            if ($dupStmt->fetch(PDO::FETCH_ASSOC)) {
                $error = 'A room with this name already exists.';
            }

            if ($error !== '') {
                // no-op, error already set
            } else {
            $update = $pdo->prepare("
                UPDATE hotel_rooms
                SET room_name = ?, description = ?, price = ?, capacity_adults = ?, capacity_children = ?, available_units = ?, breakfast_for = ?, room_meta_json = ?, inclusions_json = ?, main_image_path = ?, gallery_images_json = ?, updated_at = NOW()
                WHERE hotel_room_id = ? AND hotel_resort_id = ?
            ");
            try {
                $update->execute([
                    $roomName,
                    $roomDescription,
                    $price,
                    $capacityAdults,
                    $capacityChildren,
                    $availableUnits,
                    $breakfastFor,
                    json_encode($metaParsed, JSON_UNESCAPED_UNICODE),
                    json_encode($inclusions, JSON_UNESCAPED_UNICODE),
                    $mainImagePath,
                    json_encode($galleryImages, JSON_UNESCAPED_UNICODE),
                    $roomId,
                    $hoHotelResortId,
                ]);
                $flash = 'Room updated successfully.';
                logActivity(
                    $pdo, 'Hotel Owner', (int)$hoAdmin['hotel_admin_id'], (string)$hoOwnerName,
                    'Hotel Room Updated', 'Updated hotel room "' . $roomName . '".',
                    'Hotel Rooms', $roomId
                );
            } catch (PDOException $e) {
                $error = 'Failed to update room. Please try again.';
            }
            }
        } elseif ($action === 'archive' && $roomId > 0) {
            $archive = $pdo->prepare("
                UPDATE hotel_rooms
                SET status = 'inactive', updated_at = NOW()
                WHERE hotel_room_id = ? AND hotel_resort_id = ?
            ");
            $archive->execute([$roomId, $hoHotelResortId]);
            $flash = 'Room archived successfully.';
            logActivity(
                $pdo, 'Hotel Owner', (int)$hoAdmin['hotel_admin_id'], (string)$hoOwnerName,
                'Hotel Room Archived', 'Archived hotel room #' . $roomId . '.',
                'Hotel Rooms', $roomId
            );
        }
    }

    if ($error !== '' && in_array($action, ['create', 'update'], true)) {
        $newRoomMedia = array_values($uploadedGalleryRows);
        if (is_string($uploadedMainImage) && $uploadedMainImage !== '') {
            $newRoomMedia[] = $uploadedMainImage;
        }
        foreach ($newRoomMedia as $newRoomPath) {
            if (is_string($newRoomPath) && preg_match('#^uploads/hotel_rooms/[A-Za-z0-9._-]+$#D', $newRoomPath)) {
                $newRoomAbsolute = ItourProjectPath($newRoomPath);
                if (is_file($newRoomAbsolute)) @unlink($newRoomAbsolute);
            }
        }
    }
}

$allRooms = HoGetHotelRooms($pdo, $hoHotelResortId, false);
$roomStatusSnapshot = HoGetHotelRoomStatusSnapshot($pdo, $hoHotelResortId);

$bookingStmt = $pdo->prepare("
    SELECT
      hotel_booking_id,
      booking_reference,
      hotel_room_id,
      room_type,
      booking_status,
      checkin_date,
      checkout_date,
      checked_in_at,
      checked_out_at,
      first_name,
      last_name,
      email,
      phone_number,
      adults,
      children,
      rooms_booked,
      checkin_guest_name,
      checkin_representative_name,
      total_amount,
      amount_paid,
      remaining_balance,
      payment_status,
      created_at
    FROM hotel_room_bookings
    WHERE hotel_resort_id = ?
    ORDER BY created_at DESC, hotel_booking_id DESC
");
$bookingStmt->execute([$hoHotelResortId]);
$bookingRows = $bookingStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$allBookingsByRoomId = [];
$allBookingsByRoomName = [];
$currentBookingsByRoomId = [];
$currentBookingsByRoomName = [];
$checkedInBookingsByRoomId = [];
$checkedInBookingsByRoomName = [];
$today = (new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))->format('Y-m-d');

foreach ($bookingRows as $row) {
    $bookingStatus = strtolower(trim((string)($row['booking_status'] ?? '')));
    $roomId = (int)($row['hotel_room_id'] ?? 0);
    $roomNameKey = mb_strtolower(trim((string)($row['room_type'] ?? '')));
    $checkedIn = !empty($row['checked_in_at']) && empty($row['checked_out_at']);
    $checkoutDate = (string)($row['checkout_date'] ?? '');
    $currentReserved = in_array($bookingStatus, ['pending', 'confirmed'], true)
        && !$checkedIn
        && empty($row['checked_out_at'])
        && $checkoutDate > $today;

    $guestName = trim((string)($row['checkin_guest_name'] ?? ''));
    if ($guestName === '') {
        $guestName = trim((string)($row['first_name'] ?? '') . ' ' . (string)($row['last_name'] ?? ''));
    }
    if ($guestName === '') {
        $guestName = 'Guest';
    }

    $normalized = [
        'hotel_booking_id' => (int)($row['hotel_booking_id'] ?? 0),
        'booking_reference' => trim((string)($row['booking_reference'] ?? '')) ?: (string)($row['hotel_booking_id'] ?? ''),
        'booking_status' => $bookingStatus,
        'guest_name' => $guestName,
        'email' => trim((string)($row['email'] ?? '')),
        'phone_number' => trim((string)($row['phone_number'] ?? '')),
        'adults' => max(0, (int)($row['adults'] ?? 0)),
        'children' => max(0, (int)($row['children'] ?? 0)),
        'guest_count' => max(1, (int)($row['adults'] ?? 0) + (int)($row['children'] ?? 0)),
        'rooms_booked' => max(1, (int)($row['rooms_booked'] ?? 1)),
        'representative_name' => trim((string)($row['checkin_representative_name'] ?? '')),
        'checkin_date' => (string)($row['checkin_date'] ?? ''),
        'checkout_date' => (string)($row['checkout_date'] ?? ''),
        'checked_in_at' => (string)($row['checked_in_at'] ?? ''),
        'checked_out_at' => (string)($row['checked_out_at'] ?? ''),
        'remaining_balance' => (float)($row['remaining_balance'] ?? 0),
        'amount_paid' => (float)($row['amount_paid'] ?? 0),
        'total_amount' => (float)($row['total_amount'] ?? 0),
        'payment_status' => strtolower((string)($row['payment_status'] ?? 'unpaid')),
        'created_at' => (string)($row['created_at'] ?? ''),
    ];

    if ($roomId > 0) {
        $allBookingsByRoomId[$roomId][] = $normalized;
        if ($currentReserved) {
            $currentBookingsByRoomId[$roomId][] = $normalized;
        }
        if ($checkedIn) {
            $checkedInBookingsByRoomId[$roomId][] = $normalized;
        }
    }

    if ($roomNameKey !== '') {
        $allBookingsByRoomName[$roomNameKey][] = $normalized;
        if ($currentReserved) {
            $currentBookingsByRoomName[$roomNameKey][] = $normalized;
        }
        if ($checkedIn) {
            $checkedInBookingsByRoomName[$roomNameKey][] = $normalized;
        }
    }
}

$roomsForTab = array_values(array_filter($allRooms, static function (array $room) use ($roomStatusFilter): bool {
    $status = strtolower((string)($room['status'] ?? 'active'));
    return $roomStatusFilter === 'active' ? $status === 'active' : $status === 'inactive';
}));

$roomTypeLookup = [];
foreach ($roomsForTab as $room) {
    $name = trim((string)($room['room_name'] ?? ''));
    if ($name === '') {
        continue;
    }
    $roomTypeLookup[$name] = true;
}
$roomTypeOptions = array_keys($roomTypeLookup);
sort($roomTypeOptions, SORT_NATURAL | SORT_FLAG_CASE);
if ($roomTypeFilter !== '' && !isset($roomTypeLookup[$roomTypeFilter])) {
    $roomTypeFilter = '';
}

$summaryTotalRooms = count($roomsForTab);
$summaryAvailableRooms = 0;
$summaryBookedRooms = 0;
$summaryOccupiedRooms = 0;
$summaryTotalBookings = 0;

$roomCards = [];
foreach ($roomsForTab as $room) {
    $roomId = (int)($room['id'] ?? 0);
    $roomName = (string)($room['room_name'] ?? '');
    $roomStatus = HoResolveRoomStatus($roomStatusSnapshot, $roomId, $roomName);
    $resolvedState = strtolower((string)($roomStatus['status'] ?? 'available'));

    if ($resolvedState === 'occupied') {
        $summaryOccupiedRooms++;
    } elseif ($resolvedState === 'booked') {
        $summaryBookedRooms++;
    } else {
        $summaryAvailableRooms++;
    }

    $allBookings = HoMergeRoomBookingItems($allBookingsByRoomId, $allBookingsByRoomName, $roomId, $roomName);
    $currentBookings = HoMergeRoomBookingItems($currentBookingsByRoomId, $currentBookingsByRoomName, $roomId, $roomName);
    $checkedInBookings = HoMergeRoomBookingItems($checkedInBookingsByRoomId, $checkedInBookingsByRoomName, $roomId, $roomName);
    $upcomingBookings = array_values(array_filter($currentBookings, static function (array $booking) use ($today): bool {
        return (string)($booking['checkin_date'] ?? '') > $today;
    }));
    usort($upcomingBookings, static function (array $a, array $b): int {
        $dateCompare = strcmp((string)($a['checkin_date'] ?? ''), (string)($b['checkin_date'] ?? ''));
        return $dateCompare !== 0
            ? $dateCompare
            : ((int)($a['hotel_booking_id'] ?? 0) <=> (int)($b['hotel_booking_id'] ?? 0));
    });
    $nextUpcomingBooking = $upcomingBookings[0] ?? null;
    if ($resolvedState === 'available' && $nextUpcomingBooking) {
        $roomStatus['label'] = 'Available Today';
    }

    $checkinBookings = array_values(array_filter($currentBookings, static function (array $booking) use ($today): bool {
        return strtolower(trim((string)($booking['booking_status'] ?? ''))) === 'confirmed'
            && (string)($booking['checkin_date'] ?? '') <= $today
            && (string)($booking['checkout_date'] ?? '') > $today;
    }));
    usort($checkinBookings, static function (array $a, array $b): int {
        $dateCompare = strcmp((string)($a['checkin_date'] ?? ''), (string)($b['checkin_date'] ?? ''));
        return $dateCompare !== 0
            ? $dateCompare
            : ((int)($a['hotel_booking_id'] ?? 0) <=> (int)($b['hotel_booking_id'] ?? 0));
    });
    $eligibleCheckinCount = count($checkinBookings);
    $bookingCount = count($allBookings);
    $summaryTotalBookings += $bookingCount;

    if ($roomStateFilter !== 'all' && $resolvedState !== $roomStateFilter) {
        continue;
    }
    if ($roomTypeFilter !== '' && strcasecmp($roomName, $roomTypeFilter) !== 0) {
        continue;
    }
    if ($roomSearch !== '') {
        $needle = mb_strtolower($roomSearch);
        $name = mb_strtolower($roomName);
        $desc = mb_strtolower((string)($room['description'] ?? ''));
        if (!str_contains($name, $needle) && !str_contains($desc, $needle)) {
            continue;
        }
    }

    $roomCards[] = [
        'room' => $room,
        'room_status' => $roomStatus,
        'capacity_total' => HoRoomCapacityTotal($room),
        'booking_count' => $bookingCount,
        'all_bookings' => $allBookings,
        'current_bookings' => $currentBookings,
        'upcoming_booking' => $nextUpcomingBooking,
        'checkin_bookings' => $checkinBookings,
        'eligible_checkin_count' => $eligibleCheckinCount,
        'checkedin_bookings' => $checkedInBookings,
    ];
}

$selectedRoomCard = null;
if ($roomCards) {
    foreach ($roomCards as $card) {
        if ((int)($card['room']['id'] ?? 0) === $selectedRoomId) {
            $selectedRoomCard = $card;
            break;
        }
    }
    if (!$selectedRoomCard) {
        $selectedRoomCard = $roomCards[0];
        $selectedRoomId = (int)($selectedRoomCard['room']['id'] ?? 0);
    }
}

$roomQueryBase = [
    'status' => $roomStatusFilter,
    'q' => $roomSearch,
    'room_state' => $roomStateFilter,
    'room_type' => $roomTypeFilter,
    'view' => $roomView,
];
if ($selectedRoomId > 0) {
    $roomQueryBase['selected_room'] = $selectedRoomId;
}

$tabActiveQuery = $roomQueryBase;
$tabActiveQuery['status'] = 'active';
$tabArchivedQuery = $roomQueryBase;
$tabArchivedQuery['status'] = 'archived';
$cardViewQuery = $roomQueryBase;
$cardViewQuery['view'] = 'card';
$listViewQuery = $roomQueryBase;
$listViewQuery['view'] = 'list';
$hoTopbarViewToggle = [
    'card_url' => 'Horooms.php?' . http_build_query($cardViewQuery),
    'list_url' => 'Horooms.php?' . http_build_query($listViewQuery),
    'active' => $roomView,
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Horooms | Hotel Owner Rooms</title>
  <link rel="icon" type="image/png" href="img/newlogo.png" />
  <link rel="stylesheet" href="styles/Ho_panel.css?v=<?= (int)@filemtime(__DIR__ . '/styles/Ho_panel.css') ?>" />
</head>
<body class="ho-body">
  <div class="ho-layout">
    <?php include __DIR__ . '/Ho_sidebar.php'; ?>

    <main class="ho-main">
      <?php include __DIR__ . '/Ho_header.php'; ?>

      <section class="ho-content">
        <div class="ho-room-summary-grid">
          <article class="ho-room-summary-card">
            <div class="ho-room-summary-copy">
              <span>Total Rooms</span>
              <strong><?= (int)$summaryTotalRooms ?></strong>
            </div>
            <div class="ho-room-summary-icon" aria-hidden="true">
              <svg viewBox="0 0 24 24"><path d="M4 20V7a2 2 0 0 1 2-2h12a2 2 0 0 1 2 2v13M8 5V3h8v2M3 20h18"></path><path d="M8 10h3v3H8zm5 0h3v3h-3zM8 16h3v4H8zm5 0h3v4h-3z"></path></svg>
            </div>
          </article>
          <article class="ho-room-summary-card available">
            <div class="ho-room-summary-copy">
              <span>Total Available</span>
              <strong><?= (int)$summaryAvailableRooms ?></strong>
            </div>
            <div class="ho-room-summary-icon" aria-hidden="true">
              <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"></circle><path d="m8 12 2.6 2.6L16.5 9"></path></svg>
            </div>
          </article>
          <article class="ho-room-summary-card occupied">
            <div class="ho-room-summary-copy">
              <span>Total Occupied</span>
              <strong><?= (int)$summaryOccupiedRooms ?></strong>
            </div>
            <div class="ho-room-summary-icon" aria-hidden="true">
              <svg viewBox="0 0 24 24"><path d="M5 20V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2v15M3 20h18"></path><circle cx="15" cy="12" r="1"></circle><path d="M9 8h3"></path></svg>
            </div>
          </article>
          <article class="ho-room-summary-card bookings">
            <div class="ho-room-summary-copy">
              <span>Total Room Bookings</span>
              <strong><?= (int)$summaryTotalBookings ?></strong>
            </div>
            <div class="ho-room-summary-icon" aria-hidden="true">
              <svg viewBox="0 0 24 24"><path d="M7 3v3M17 3v3M4 9h16"></path><rect x="4" y="5" width="16" height="15" rx="2"></rect><path d="m8 14 2 2 5-5"></path></svg>
            </div>
          </article>
        </div>

        <article class="ho-card ho-table-card">
          <form method="get" class="ho-toolbar ho-room-toolbar ho-room-toolbar-single">
            <input type="hidden" name="status" value="<?= htmlspecialchars($roomStatusFilter) ?>" />
            <input type="hidden" name="view" value="<?= htmlspecialchars($roomView) ?>" />
            <?php if ($selectedRoomId > 0): ?>
              <input type="hidden" name="selected_room" value="<?= (int)$selectedRoomId ?>" />
            <?php endif; ?>
            <select name="room_state">
              <option value="all" <?= $roomStateFilter === 'all' ? 'selected' : '' ?>>All Room States</option>
              <option value="available" <?= $roomStateFilter === 'available' ? 'selected' : '' ?>>Available</option>
              <option value="booked" <?= $roomStateFilter === 'booked' ? 'selected' : '' ?>>Booked</option>
              <option value="occupied" <?= $roomStateFilter === 'occupied' ? 'selected' : '' ?>>Occupied</option>
            </select>
            <select name="room_type">
              <option value="">All Room Types</option>
              <?php foreach ($roomTypeOptions as $typeOption): ?>
                <option value="<?= htmlspecialchars((string)$typeOption) ?>" <?= $roomTypeFilter === (string)$typeOption ? 'selected' : '' ?>><?= htmlspecialchars((string)$typeOption) ?></option>
              <?php endforeach; ?>
            </select>
            <input type="text" name="q" value="<?= htmlspecialchars($roomSearch) ?>" placeholder="Search room name or description" />
            <button type="submit" class="ho-btn ho-search-submit">Search</button>
            <div class="ho-room-toolbar-actions">
              <div class="ho-booking-tabs" role="tablist" aria-label="Room status quick tabs">
                <a href="Horooms.php?<?= htmlspecialchars(http_build_query($tabActiveQuery)) ?>" class="<?= $roomStatusFilter === 'active' ? 'active' : '' ?>">Active</a>
                <a href="Horooms.php?<?= htmlspecialchars(http_build_query($tabArchivedQuery)) ?>" class="<?= $roomStatusFilter === 'archived' ? 'active' : '' ?>">Archived</a>
              </div>
              <button type="button" class="ho-btn confirm ho-add-room-btn" data-open-add-room>
                <span class="ho-add-room-plus" aria-hidden="true">+</span>
                <span>Add New Room</span>
              </button>
            </div>
          </form>

          <?php if ($roomCards): ?>
            <?php if ($roomView === 'list' && $selectedRoomCard): ?>
              <?php
                $selectedRoom = $selectedRoomCard['room'];
                $selectedRoomStatus = $selectedRoomCard['room_status'];
                $selectedStatusBooking = $selectedRoomStatus['booking'] ?? null;
                $selectedCapacityTotal = (int)$selectedRoomCard['capacity_total'];
                $selectedBookingCount = (int)$selectedRoomCard['booking_count'];
                $selectedCurrentBookings = $selectedRoomCard['current_bookings'];
                $selectedUpcomingBooking = $selectedRoomCard['upcoming_booking'];
                $selectedUpcomingDisplay = $selectedUpcomingBooking ? HoUpcomingBookingDisplay($selectedUpcomingBooking, $today) : null;
                $selectedCheckinBookings = $selectedRoomCard['checkin_bookings'];
                $selectedEligibleCheckinCount = (int)$selectedRoomCard['eligible_checkin_count'];
                $selectedCheckedInBookings = $selectedRoomCard['checkedin_bookings'];
              ?>
              <div class="ho-room-list-layout">
                <aside class="ho-room-list-pane">
                  <div class="ho-room-list-scroll">
                    <?php foreach ($roomCards as $card): ?>
                      <?php
                        $room = $card['room'];
                        $roomStatus = $card['room_status'];
                        $capacityTotal = (int)$card['capacity_total'];
                        $bookingCount = (int)$card['booking_count'];
                        $upcomingBooking = $card['upcoming_booking'];
                        $upcomingDisplay = $upcomingBooking ? HoUpcomingBookingDisplay($upcomingBooking, $today) : null;
                        $isSelectedRoom = (int)$room['id'] === $selectedRoomId;
                        $roomSelectQuery = $roomQueryBase;
                        $roomSelectQuery['view'] = 'list';
                        $roomSelectQuery['selected_room'] = (int)$room['id'];
                      ?>
                      <a href="Horooms.php?<?= htmlspecialchars(http_build_query($roomSelectQuery)) ?>" class="ho-room-list-item <?= $isSelectedRoom ? 'active' : '' ?>">
                        <img src="<?= htmlspecialchars((string)$room['main_image_path']) ?>" alt="<?= htmlspecialchars((string)$room['room_name']) ?>" class="ho-room-list-cover" />
                        <div class="ho-room-list-main">
                          <div class="ho-room-list-title-row">
                            <h3><?= htmlspecialchars((string)$room['room_name']) ?></h3>
                            <span class="ho-room-state-badge <?= htmlspecialchars((string)$roomStatus['badge_class']) ?>"><?= htmlspecialchars((string)$roomStatus['label']) ?></span>
                          </div>
                          <?php if ($upcomingDisplay): ?>
                            <div class="ho-room-upcoming-notice compact">
                              <span class="ho-room-upcoming-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M8 3v4M16 3v4M3 10h18"/></svg></span>
                              <span><strong><?= htmlspecialchars($upcomingDisplay['title']) ?></strong><small><?= htmlspecialchars($upcomingDisplay['stay']) ?> &middot; <?= htmlspecialchars($upcomingDisplay['guest']) ?></small></span>
                            </div>
                          <?php endif; ?>
                          <p class="ho-room-list-desc"><?= htmlspecialchars((string)$room['description']) ?></p>
                          <div class="ho-room-list-meta">
                            <span>Capacity <?= $capacityTotal ?> guest(s)</span>
                            <span><?= (int)$room['capacity_adults'] ?>A / <?= (int)$room['capacity_children'] ?>C</span>
                          </div>
                          <div class="ho-room-list-foot">
                            <small>Total bookings: <?= $bookingCount ?></small>
                            <strong>₱<?= number_format((float)$room['price'], 2) ?>/night</strong>
                          </div>
                        </div>
                      </a>
                    <?php endforeach; ?>
                  </div>
                </aside>
                <section class="ho-room-focus-pane">
                  <div class="ho-room-focus-head">
                    <div>
                      <span class="ho-room-focus-kicker">Room Detail</span>
                      <h3><?= htmlspecialchars((string)$selectedRoom['room_name']) ?></h3>
                    </div>
                    <div class="ho-room-focus-actions">
                      <button type="button" class="ho-btn" data-open-room-modal="roomModal<?= (int)$selectedRoom['id'] ?>">Edit Room</button>
                      <button type="button" class="ho-btn" data-open-lifecycle-modal="roomDetailsModal<?= (int)$selectedRoom['id'] ?>">View Details</button>
                      <?php if ($selectedRoomStatus['status'] === 'booked' && $selectedEligibleCheckinCount > 0): ?>
                        <button type="button" class="ho-btn confirm" data-open-lifecycle-modal="checkinFlowRoom<?= (int)$selectedRoom['id'] ?>">Check-in</button>
                      <?php endif; ?>
                      <?php if ($selectedRoomStatus['status'] === 'occupied' && $selectedStatusBooking): ?>
                        <button type="button" class="ho-btn cancel" data-open-lifecycle-modal="checkoutModal<?= (int)$selectedStatusBooking['hotel_booking_id'] ?>">Check-out</button>
                      <?php endif; ?>
                    </div>
                  </div>

                  <img src="<?= htmlspecialchars((string)$selectedRoom['main_image_path']) ?>" alt="<?= htmlspecialchars((string)$selectedRoom['room_name']) ?>" class="ho-room-focus-image" />

                  <div class="ho-room-meta-inline">
                    <span>₱<?= number_format((float)$selectedRoom['price'], 2) ?>/night</span>
                    <span>Capacity: <?= $selectedCapacityTotal ?> guest(s)</span>
                    <span><?= (int)$selectedRoom['capacity_adults'] ?>A / <?= (int)$selectedRoom['capacity_children'] ?>C</span>
                    <span>Status: <?= htmlspecialchars((string)$selectedRoomStatus['label']) ?></span>
                  </div>

                  <?php if ($selectedUpcomingDisplay): ?>
                    <div class="ho-room-upcoming-notice">
                      <span class="ho-room-upcoming-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M8 3v4M16 3v4M3 10h18"/></svg></span>
                      <span><strong><?= htmlspecialchars($selectedUpcomingDisplay['title']) ?></strong><small><?= htmlspecialchars($selectedUpcomingDisplay['stay']) ?> &middot; <?= htmlspecialchars($selectedUpcomingDisplay['guest']) ?>. The room remains available until this stay begins.</small></span>
                    </div>
                  <?php endif; ?>

                  <p class="ho-room-focus-desc"><?= htmlspecialchars((string)$selectedRoom['description']) ?></p>

                  <button type="button" class="ho-room-booking-count" data-open-lifecycle-modal="roomBookingsModal<?= (int)$selectedRoom['id'] ?>" aria-label="View all bookings for <?= htmlspecialchars((string)$selectedRoom['room_name']) ?>">
                    <strong>Total Bookings</strong>
                    <span><?= (int)$selectedBookingCount ?> <small aria-hidden="true">&rsaquo;</small></span>
                  </button>

                  <div class="ho-room-focus-grid">
                    <div class="ho-room-details-block">
                      <h4>Current Bookings</h4>
                      <?php if (!empty($selectedCurrentBookings)): ?>
                        <ul class="ho-room-details-booking-list">
                          <?php foreach (array_slice($selectedCurrentBookings, 0, 2) as $bookingItem): ?>
                            <li>
                              <strong><?= htmlspecialchars((string)$bookingItem['booking_reference']) ?> - <?= htmlspecialchars((string)$bookingItem['guest_name']) ?></strong>
                              <span><?= htmlspecialchars((string)$bookingItem['checkin_date']) ?> to <?= htmlspecialchars((string)$bookingItem['checkout_date']) ?></span>
                              <small>Status: <?= htmlspecialchars(ucfirst((string)$bookingItem['booking_status'])) ?> • Payment: <?= htmlspecialchars(ucfirst((string)$bookingItem['payment_status'])) ?></small>
                            </li>
                          <?php endforeach; ?>
                        </ul>
                      <?php else: ?>
                        <p class="ho-empty-inline">No current bookings for this room.</p>
                      <?php endif; ?>
                    </div>
                    <div class="ho-room-details-block">
                      <h4>Checked-in Guests</h4>
                      <?php if (!empty($selectedCheckedInBookings)): ?>
                        <ul class="ho-room-details-booking-list">
                          <?php foreach (array_slice($selectedCheckedInBookings, 0, 2) as $bookingItem): ?>
                            <li>
                              <strong><?= htmlspecialchars((string)$bookingItem['booking_reference']) ?> - <?= htmlspecialchars((string)$bookingItem['guest_name']) ?></strong>
                              <span>Checked in: <?= htmlspecialchars((string)$bookingItem['checked_in_at']) ?></span>
                              <small>Remaining balance: ₱<?= number_format((float)$bookingItem['remaining_balance'], 2) ?></small>
                            </li>
                          <?php endforeach; ?>
                        </ul>
                      <?php else: ?>
                        <p class="ho-empty-inline">No checked-in guests in this room.</p>
                      <?php endif; ?>
                    </div>
                  </div>
                </section>
              </div>
            <?php else: ?>
              <div class="ho-room-grid">
                <?php foreach ($roomCards as $card): ?>
                  <?php
                    $room = $card['room'];
                    $roomStatus = $card['room_status'];
                    $statusBooking = $roomStatus['booking'] ?? null;
                    $capacityTotal = (int)$card['capacity_total'];
                    $bookingCount = (int)$card['booking_count'];
                    $upcomingBooking = $card['upcoming_booking'];
                    $upcomingDisplay = $upcomingBooking ? HoUpcomingBookingDisplay($upcomingBooking, $today) : null;
                    $eligibleCheckinCount = (int)$card['eligible_checkin_count'];
                  ?>
                  <article class="ho-room-card">
                    <img src="<?= htmlspecialchars((string)$room['main_image_path']) ?>" alt="<?= htmlspecialchars((string)$room['room_name']) ?>" class="ho-room-cover" />
                    <div class="ho-room-content">
                      <h3><?= htmlspecialchars((string)$room['room_name']) ?></h3>
                      <span class="ho-room-state-badge <?= htmlspecialchars((string)$roomStatus['badge_class']) ?>"><?= htmlspecialchars((string)$roomStatus['label']) ?></span>
                      <?php if ($roomStatus['status'] === 'occupied' && $statusBooking): ?>
                        <div class="ho-room-upcoming-notice occupied-now">
                          <span class="ho-room-upcoming-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M5 3h14v18H5zM9 12h.01"/></svg></span>
                          <span><strong>Currently occupied</strong><small>Until <?= htmlspecialchars((string)$statusBooking['checkout_date']) ?> &middot; <?= htmlspecialchars((string)$statusBooking['guest_name']) ?></small></span>
                        </div>
                      <?php elseif ($roomStatus['status'] === 'booked' && $statusBooking): ?>
                        <div class="ho-room-upcoming-notice">
                          <span class="ho-room-upcoming-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M8 3v4M16 3v4M3 10h18"/></svg></span>
                          <span><strong>Reserved today</strong><small>Until <?= htmlspecialchars((string)$statusBooking['checkout_date']) ?> &middot; <?= htmlspecialchars((string)$statusBooking['guest_name']) ?></small></span>
                        </div>
                      <?php elseif ($upcomingDisplay): ?>
                        <div class="ho-room-upcoming-notice">
                          <span class="ho-room-upcoming-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M8 3v4M16 3v4M3 10h18"/></svg></span>
                          <span><strong><?= htmlspecialchars($upcomingDisplay['title']) ?></strong><small><?= htmlspecialchars($upcomingDisplay['stay']) ?> &middot; <?= htmlspecialchars($upcomingDisplay['guest']) ?></small></span>
                        </div>
                      <?php else: ?>
                        <div class="ho-room-upcoming-notice open-schedule">
                          <span class="ho-room-upcoming-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="m8 12 2.6 2.6L16.5 9"/></svg></span>
                          <span><strong>Schedule open</strong><small>No upcoming reservations</small></span>
                        </div>
                      <?php endif; ?>
                      <p><?= htmlspecialchars((string)$room['description']) ?></p>
                      <div class="ho-room-meta-inline">
                        <span>₱<?= number_format((float)$room['price'], 2) ?>/night</span>
                        <span>Capacity: <?= $capacityTotal ?> guest(s)</span>
                        <span><?= (int)$room['capacity_adults'] ?>A / <?= (int)$room['capacity_children'] ?>C</span>
                      </div>
                      <button type="button" class="ho-room-booking-count" data-open-lifecycle-modal="roomBookingsModal<?= (int)$room['id'] ?>" aria-label="View all bookings for <?= htmlspecialchars((string)$room['room_name']) ?>">
                        <strong>Total Bookings</strong>
                        <span><?= (int)$bookingCount ?> <small aria-hidden="true">&rsaquo;</small></span>
                      </button>
                      <div class="ho-room-cta-row">
                        <button type="button" class="ho-btn" data-open-room-modal="roomModal<?= (int)$room['id'] ?>">Edit Room</button>
                        <button type="button" class="ho-btn" data-open-lifecycle-modal="roomDetailsModal<?= (int)$room['id'] ?>">View Details</button>
                        <?php if ($roomStatus['status'] === 'booked' && $eligibleCheckinCount > 0): ?>
                          <button type="button" class="ho-btn confirm" data-open-lifecycle-modal="checkinFlowRoom<?= (int)$room['id'] ?>">Check-in</button>
                        <?php endif; ?>
                        <?php if ($roomStatus['status'] === 'occupied' && $statusBooking): ?>
                          <button type="button" class="ho-btn cancel" data-open-lifecycle-modal="checkoutModal<?= (int)$statusBooking['hotel_booking_id'] ?>">Check-out</button>
                        <?php endif; ?>
                      </div>
                    </div>
                  </article>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          <?php else: ?>
            <div class="ho-empty">No rooms found for this filter/search.</div>
          <?php endif; ?>
        </article>
      </section>

      <?php include __DIR__ . '/Ho_footer.php'; ?>
    </main>
  </div>

  <?php foreach ($roomCards as $card): ?>
    <?php
      $room = $card['room'];
      $roomStatus = $card['room_status'];
      $statusBooking = $roomStatus['booking'] ?? null;
      $capacityTotal = (int)$card['capacity_total'];
      $bookingCount = (int)$card['booking_count'];
      $currentBookings = $card['current_bookings'];
      $checkinBookings = $card['checkin_bookings'];
      $eligibleCheckinCount = (int)$card['eligible_checkin_count'];
      $checkedInBookings = $card['checkedin_bookings'];
    ?>
    <div class="ho-modal" id="roomDetailsModal<?= (int)$room['id'] ?>" aria-hidden="true">
      <div class="ho-modal-card ho-room-details-modal-card">
        <div class="ho-modal-head">
          <h3>Room Details - <?= htmlspecialchars((string)$room['room_name']) ?></h3>
          <button type="button" class="ho-close" data-close-modal>&times;</button>
        </div>
        <div class="ho-room-details-layout">
          <section class="ho-room-details-left">
            <img src="<?= htmlspecialchars((string)$room['main_image_path']) ?>" alt="<?= htmlspecialchars((string)$room['room_name']) ?>" class="ho-room-details-image" />
            <div class="ho-room-details-meta">
              <h4><?= htmlspecialchars((string)$room['room_name']) ?></h4>
              <p><?= htmlspecialchars((string)$room['description']) ?></p>
              <div class="ho-room-meta-inline">
                <span>₱<?= number_format((float)$room['price'], 2) ?>/night</span>
                <span>Capacity: <?= $capacityTotal ?></span>
                <span>Status: <?= htmlspecialchars((string)$roomStatus['label']) ?></span>
              </div>
              <div class="ho-room-details-totals">
                <strong>Total Bookings:</strong> <span><?= (int)$bookingCount ?></span>
              </div>
            </div>
          </section>
          <section class="ho-room-details-right">
            <div class="ho-room-details-block">
              <h4>Current Bookings</h4>
              <?php if (!empty($currentBookings)): ?>
                <ul class="ho-room-details-booking-list">
                  <?php foreach ($currentBookings as $bookingItem): ?>
                    <li>
                      <strong><?= htmlspecialchars((string)$bookingItem['booking_reference']) ?> - <?= htmlspecialchars((string)$bookingItem['guest_name']) ?></strong>
                      <span><?= htmlspecialchars((string)$bookingItem['checkin_date']) ?> to <?= htmlspecialchars((string)$bookingItem['checkout_date']) ?></span>
                      <small>Status: <?= htmlspecialchars(ucfirst((string)$bookingItem['booking_status'])) ?> • Payment: <?= htmlspecialchars(ucfirst((string)$bookingItem['payment_status'])) ?></small>
                    </li>
                  <?php endforeach; ?>
                </ul>
              <?php else: ?>
                <p class="ho-empty-inline">No current bookings for this room.</p>
              <?php endif; ?>
            </div>
            <div class="ho-room-details-block">
              <h4>Checked-in Guests</h4>
              <?php if (!empty($checkedInBookings)): ?>
                <ul class="ho-room-details-booking-list">
                  <?php foreach ($checkedInBookings as $bookingItem): ?>
                    <li>
                      <strong><?= htmlspecialchars((string)$bookingItem['booking_reference']) ?> - <?= htmlspecialchars((string)$bookingItem['guest_name']) ?></strong>
                      <span>Checked in: <?= htmlspecialchars((string)$bookingItem['checked_in_at']) ?></span>
                      <small>Remaining balance: ₱<?= number_format((float)$bookingItem['remaining_balance'], 2) ?></small>
                    </li>
                  <?php endforeach; ?>
                </ul>
              <?php else: ?>
                <p class="ho-empty-inline">No checked-in guests in this room.</p>
              <?php endif; ?>
            </div>
          </section>
        </div>
      </div>
    </div>

    <?php
      $roomBookingGroups = [
          'all' => $card['all_bookings'],
          'current' => array_values(array_filter($card['all_bookings'], static function (array $booking): bool {
              return in_array(strtolower((string)($booking['booking_status'] ?? '')), ['pending', 'confirmed'], true);
          })),
          'completed' => array_values(array_filter($card['all_bookings'], static function (array $booking): bool {
              return strtolower((string)($booking['booking_status'] ?? '')) === 'completed';
          })),
          'cancelled' => array_values(array_filter($card['all_bookings'], static function (array $booking): bool {
              return in_array(strtolower((string)($booking['booking_status'] ?? '')), ['cancelled', 'canceled', 'no-show', 'no_show'], true);
          })),
      ];
      $roomBookingTabs = [
          'all' => 'All',
          'current' => 'Current',
          'completed' => 'Completed',
          'cancelled' => 'Cancelled / No-show',
      ];
    ?>
    <div class="ho-modal ho-room-bookings-modal" id="roomBookingsModal<?= (int)$room['id'] ?>" aria-hidden="true">
      <div class="ho-modal-card ho-room-bookings-modal-card" role="dialog" aria-modal="true" aria-labelledby="roomBookingsTitle<?= (int)$room['id'] ?>">
        <header class="ho-room-bookings-header">
          <span class="ho-room-bookings-header-icon" aria-hidden="true">
            <svg viewBox="0 0 24 24"><path d="M7 3v3M17 3v3M4 9h16"></path><rect x="4" y="5" width="16" height="15" rx="2"></rect><path d="M8 13h3v3H8z"></path></svg>
          </span>
          <div>
            <span>Room booking history</span>
            <h3 id="roomBookingsTitle<?= (int)$room['id'] ?>"><?= htmlspecialchars((string)$room['room_name']) ?> bookings</h3>
            <p><?= $bookingCount ?> total &middot; <?= count($roomBookingGroups['current']) ?> current &middot; <?= count($roomBookingGroups['completed']) ?> completed</p>
          </div>
          <button type="button" class="ho-room-bookings-close" data-close-modal aria-label="Close room bookings">&times;</button>
        </header>

        <nav class="ho-room-bookings-tabs" role="tablist" aria-label="<?= htmlspecialchars((string)$room['room_name']) ?> booking statuses">
          <?php foreach ($roomBookingTabs as $tabKey => $tabLabel): ?>
            <button
              type="button"
              role="tab"
              class="<?= $tabKey === 'all' ? 'active' : '' ?>"
              data-room-booking-tab="<?= htmlspecialchars($tabKey) ?>"
              aria-selected="<?= $tabKey === 'all' ? 'true' : 'false' ?>"
            >
              <?= htmlspecialchars($tabLabel) ?>
              <span><?= count($roomBookingGroups[$tabKey]) ?></span>
            </button>
          <?php endforeach; ?>
        </nav>

        <div class="ho-room-bookings-body">
          <?php foreach ($roomBookingTabs as $tabKey => $tabLabel): ?>
            <section class="ho-room-bookings-panel <?= $tabKey === 'all' ? 'active' : '' ?>" data-room-booking-panel="<?= htmlspecialchars($tabKey) ?>" <?= $tabKey === 'all' ? '' : 'hidden' ?>>
              <?php if (!empty($roomBookingGroups[$tabKey])): ?>
                <div class="ho-room-bookings-list">
                  <?php foreach ($roomBookingGroups[$tabKey] as $bookingItem): ?>
                    <?php
                      $historyStatus = strtolower((string)($bookingItem['booking_status'] ?? 'pending'));
                      $historyStatusClass = in_array($historyStatus, ['pending', 'confirmed', 'completed', 'cancelled', 'canceled', 'no-show', 'no_show'], true)
                          ? str_replace('_', '-', $historyStatus)
                          : 'pending';
                      $historyStatusLabel = ucwords(str_replace(['-', '_'], ' ', $historyStatus));
                      $historyGuestCount = max(1, (int)($bookingItem['guest_count'] ?? 1));
                    ?>
                    <article class="ho-room-booking-history-item">
                      <div class="ho-room-booking-history-primary">
                        <span class="ho-room-booking-history-ref"><?= htmlspecialchars((string)$bookingItem['booking_reference']) ?></span>
                        <div>
                          <strong><?= htmlspecialchars((string)$bookingItem['guest_name']) ?></strong>
                          <small><?= htmlspecialchars((string)($bookingItem['email'] ?? '')) ?: 'No email provided' ?></small>
                        </div>
                      </div>
                      <div class="ho-room-booking-history-stay">
                        <small>Reserved stay</small>
                        <strong><?= htmlspecialchars((string)$bookingItem['checkin_date']) ?> &rarr; <?= htmlspecialchars((string)$bookingItem['checkout_date']) ?></strong>
                        <span><?= $historyGuestCount ?> guest<?= $historyGuestCount === 1 ? '' : 's' ?> &middot; <?= (int)($bookingItem['adults'] ?? 0) ?>A / <?= (int)($bookingItem['children'] ?? 0) ?>C</span>
                      </div>
                      <div class="ho-room-booking-history-payment">
                        <small>Payment</small>
                        <strong>₱<?= number_format((float)($bookingItem['amount_paid'] ?? 0), 2) ?> paid</strong>
                        <span>₱<?= number_format((float)($bookingItem['remaining_balance'] ?? 0), 2) ?> balance</span>
                      </div>
                      <div class="ho-room-booking-history-state">
                        <span class="ho-room-history-status <?= htmlspecialchars($historyStatusClass) ?>"><?= htmlspecialchars($historyStatusLabel) ?></span>
                        <?php if (!empty($bookingItem['checked_in_at']) && empty($bookingItem['checked_out_at'])): ?>
                          <small class="checked-in">Currently checked in</small>
                        <?php elseif (!empty($bookingItem['checked_out_at'])): ?>
                          <small>Checked out <?= htmlspecialchars((string)$bookingItem['checked_out_at']) ?></small>
                        <?php else: ?>
                          <small><?= htmlspecialchars(ucfirst((string)($bookingItem['payment_status'] ?? 'unpaid'))) ?> payment</small>
                        <?php endif; ?>
                      </div>
                    </article>
                  <?php endforeach; ?>
                </div>
              <?php else: ?>
                <div class="ho-room-bookings-empty">
                  <span aria-hidden="true">0</span>
                  <strong>No <?= strtolower(htmlspecialchars($tabLabel)) ?> bookings</strong>
                  <p>This room has no bookings in this category yet.</p>
                </div>
              <?php endif; ?>
            </section>
          <?php endforeach; ?>
        </div>

        <footer class="ho-room-bookings-footer">
          <p>Select a status tab to review this room’s booking history.</p>
          <button type="button" class="ho-btn confirm" data-close-modal>Done</button>
        </footer>
      </div>
    </div>

    <?php if ($roomStatus['status'] === 'booked' && $eligibleCheckinCount > 0): ?>
      <div class="ho-modal ho-checkin-flow-modal" id="checkinFlowRoom<?= (int)$room['id'] ?>" aria-hidden="true">
        <div class="ho-modal-card ho-checkin-flow-card" role="dialog" aria-modal="true" aria-labelledby="checkinTitleRoom<?= (int)$room['id'] ?>">
          <header class="ho-checkin-flow-header">
            <div>
              <span class="ho-checkin-eyebrow">Guest arrival</span>
              <h3 id="checkinTitleRoom<?= (int)$room['id'] ?>">Check in to <?= htmlspecialchars((string)$room['room_name']) ?></h3>
              <p>Select the correct booking, verify the guest, then review payment.</p>
            </div>
            <button type="button" class="ho-checkin-close" data-close-modal aria-label="Close check-in">&times;</button>
          </header>

          <div class="ho-checkin-stepper" aria-label="Check-in progress">
            <div class="ho-checkin-step active" data-checkin-step-indicator="1">
              <span class="ho-checkin-step-circle">1</span>
              <div><strong>Select booking</strong><small>Choose the arriving guest</small></div>
            </div>
            <div class="ho-checkin-step" data-checkin-step-indicator="2">
              <span class="ho-checkin-step-circle">2</span>
              <div><strong>Guest details</strong><small>Verify arrival information</small></div>
            </div>
            <div class="ho-checkin-step" data-checkin-step-indicator="3">
              <span class="ho-checkin-step-circle">3</span>
              <div><strong>Payment</strong><small>Review and confirm</small></div>
            </div>
          </div>

          <form method="post" class="ho-checkin-flow-form" data-checkin-form data-checkin-flow-form>
            <input type="hidden" name="room_action" value="checkin" />
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($hotelRoomCsrf, ENT_QUOTES, 'UTF-8') ?>" />
            <input type="hidden" name="room_id" value="<?= (int)$room['id'] ?>" />
            <input type="hidden" name="booking_id" value="" data-checkin-booking-id />
            <input type="hidden" name="checkin_paymongo_token" value="" data-checkin-paymongo-token />

            <section class="ho-checkin-panel active" data-checkin-panel="1">
              <div class="ho-checkin-panel-body">
              <div class="ho-checkin-section-head">
                <div>
                  <span class="ho-checkin-section-kicker">Step 1 of 3</span>
                  <h4>Who is checking in?</h4>
                  <p>Bookings are ordered by arrival date. Select the guest at the front desk.</p>
                </div>
                <span class="ho-checkin-count"><?= count($checkinBookings) ?> booking<?= count($checkinBookings) === 1 ? '' : 's' ?></span>
              </div>

              <div class="ho-checkin-booking-list">
                <?php foreach ($checkinBookings as $bookingItem): ?>
                  <?php
                    $bookingItemStatus = strtolower(trim((string)$bookingItem['booking_status']));
                    $isEligible = $bookingItemStatus === 'confirmed';
                    $bookingItemId = (int)$bookingItem['hotel_booking_id'];
                    $bookingGuestCount = max(1, (int)($bookingItem['guest_count'] ?? 1));
                    $bookingBalance = max(0, (float)($bookingItem['remaining_balance'] ?? 0));
                    $bookingPaid = max(0, (float)($bookingItem['amount_paid'] ?? 0));
                    $bookingTotal = max(0, (float)($bookingItem['total_amount'] ?? 0));
                  ?>
                  <label class="ho-checkin-booking-option <?= $isEligible ? '' : 'disabled' ?>">
                    <input
                      type="radio"
                      name="checkin_booking_choice_room_<?= (int)$room['id'] ?>"
                      value="<?= $bookingItemId ?>"
                      data-checkin-booking-choice
                      data-booking-id="<?= $bookingItemId ?>"
                      data-reference="<?= htmlspecialchars((string)$bookingItem['booking_reference']) ?>"
                      data-guest="<?= htmlspecialchars((string)$bookingItem['guest_name']) ?>"
                      data-email="<?= htmlspecialchars((string)($bookingItem['email'] ?? '')) ?>"
                      data-phone="<?= htmlspecialchars((string)($bookingItem['phone_number'] ?? '')) ?>"
                      data-guests="<?= $bookingGuestCount ?>"
                      data-adults="<?= max(0, (int)($bookingItem['adults'] ?? 0)) ?>"
                      data-children="<?= max(0, (int)($bookingItem['children'] ?? 0)) ?>"
                      data-checkin="<?= htmlspecialchars((string)$bookingItem['checkin_date']) ?>"
                      data-checkout="<?= htmlspecialchars((string)$bookingItem['checkout_date']) ?>"
                      data-total="<?= htmlspecialchars(number_format($bookingTotal, 2, '.', '')) ?>"
                      data-paid="<?= htmlspecialchars(number_format($bookingPaid, 2, '.', '')) ?>"
                      data-balance="<?= htmlspecialchars(number_format($bookingBalance, 2, '.', '')) ?>"
                      data-payment-status="<?= htmlspecialchars((string)$bookingItem['payment_status']) ?>"
                      <?= $isEligible ? '' : 'disabled' ?>
                    />
                    <span class="ho-checkin-radio-mark" aria-hidden="true"></span>
                    <span class="ho-checkin-booking-main">
                      <span class="ho-checkin-booking-topline">
                        <strong><?= htmlspecialchars((string)$bookingItem['guest_name']) ?></strong>
                        <span class="ho-checkin-status confirmed">Ready for check-in</span>
                      </span>
                      <span class="ho-checkin-booking-ref"><?= htmlspecialchars((string)$bookingItem['booking_reference']) ?> &middot; <?= $bookingGuestCount ?> guest<?= $bookingGuestCount === 1 ? '' : 's' ?></span>
                      <span class="ho-checkin-booking-dates"><?= htmlspecialchars((string)$bookingItem['checkin_date']) ?> &rarr; <?= htmlspecialchars((string)$bookingItem['checkout_date']) ?></span>
                    </span>
                    <span class="ho-checkin-booking-payment">
                      <small>Balance</small>
                      <strong>₱<?= number_format($bookingBalance, 2) ?></strong>
                      <span>₱<?= number_format($bookingPaid, 2) ?> paid</span>
                    </span>
                  </label>
                <?php endforeach; ?>
              </div>

              <p class="ho-checkin-helper">Only confirmed bookings are eligible for check-in.</p>
              </div>

              <footer class="ho-checkin-footer">
                <button type="button" class="ho-btn cancel" data-close-modal>Cancel</button>
                <button type="button" class="ho-btn confirm" data-checkin-next disabled>Continue to guest details</button>
              </footer>
            </section>

            <section class="ho-checkin-panel" data-checkin-panel="2" hidden>
              <div class="ho-checkin-panel-body">
              <div class="ho-checkin-section-head">
                <div>
                  <span class="ho-checkin-section-kicker">Step 2 of 3</span>
                  <h4>Verify guest details</h4>
                  <p>Confirm who is present and record their arrival information.</p>
                </div>
              </div>

              <div class="ho-checkin-selected">
                <span class="ho-checkin-selected-icon" aria-hidden="true">✓</span>
                <div><small>Selected booking</small><strong data-checkin-bind="guest">—</strong><span data-checkin-bind="reference">—</span></div>
                <button type="button" data-checkin-go-step="1">Change</button>
              </div>

              <div class="ho-checkin-fields two">
                <label>Guest name
                  <input type="text" name="checkin_guest_name" required data-checkin-guest-name />
                </label>
                <label>Representative name <span>(optional)</span>
                  <input type="text" name="checkin_representative_name" placeholder="If checking in on behalf of guest" />
                </label>
              </div>
              <div class="ho-checkin-fields two">
                <label>Number of guests
                  <input type="number" name="checkin_guest_count" min="1" required data-checkin-guest-count />
                </label>
                <label>Check-in date &amp; time
                  <input type="datetime-local" name="checkin_date_time" value="<?= htmlspecialchars(date('Y-m-d\TH:i')) ?>" required />
                </label>
              </div>
              <div class="ho-checkin-fields two">
                <label>Valid ID type
                  <select name="checkin_id_type" required>
                    <option value="" selected disabled>Select the presented ID</option>
                    <?php foreach ($validCheckinIdTypes as $idTypeValue => $idTypeLabel): ?>
                      <option value="<?= htmlspecialchars($idTypeValue) ?>"><?= htmlspecialchars($idTypeLabel) ?></option>
                    <?php endforeach; ?>
                  </select>
                </label>
                <label>Valid ID reference number
                  <input type="text" name="checkin_id_reference" minlength="3" maxlength="100" autocomplete="off" placeholder="Enter the ID number" required />
                </label>
              </div>
              <div class="ho-checkin-contact-strip">
                <span><small>Email</small><strong data-checkin-bind="email">Not provided</strong></span>
                <span><small>Phone</small><strong data-checkin-bind="phone">Not provided</strong></span>
                <span><small>Reserved stay</small><strong><span data-checkin-bind="checkin">—</span> to <span data-checkin-bind="checkout">—</span></strong></span>
              </div>
              </div>

              <footer class="ho-checkin-footer">
                <button type="button" class="ho-btn" data-checkin-prev>Back</button>
                <button type="button" class="ho-btn confirm" data-checkin-next>Review payment</button>
              </footer>
            </section>

            <section class="ho-checkin-panel" data-checkin-panel="3" hidden>
              <div class="ho-checkin-panel-body">
              <div class="ho-checkin-section-head">
                <div>
                  <span class="ho-checkin-section-kicker">Step 3 of 3</span>
                  <h4>Review payment and confirm</h4>
                  <p>Check the booking amounts before completing the arrival.</p>
                </div>
                <span class="ho-checkin-secure">Final review</span>
              </div>

              <div class="ho-checkin-finance-grid">
                <div><small>Total booking</small><strong data-checkin-money="total">₱0.00</strong></div>
                <div><small>Already paid</small><strong class="paid" data-checkin-money="paid">₱0.00</strong></div>
                <div class="balance"><small>Balance due now</small><strong data-checkin-money="balance">₱0.00</strong></div>
              </div>

              <div class="ho-checkin-review-grid">
                <div class="ho-checkin-payment-box">
                  <label>Payment method
                    <select name="checkin_payment_method" required disabled data-checkin-payment-method>
                      <option value="" selected disabled>Select payment method</option>
                      <option value="cash">Cash</option>
                      <option value="qr_code">QR Code (PayMongo)</option>
                    </select>
                  </label>
                  <label>Amount to collect at check-in
                    <div class="ho-checkin-money-input"><span>₱</span><input type="number" name="checkin_remaining_payment" min="0" step="0.01" value="0.00" readonly required data-checkin-payment /></div>
                  </label>
                  <p data-checkin-payment-note>The remaining balance must be settled to complete check-in.</p>
                </div>
                <div class="ho-checkin-summary-box">
                  <h5>Arrival summary</h5>
                  <dl>
                    <div><dt>Guest</dt><dd data-checkin-bind="guest">—</dd></div>
                    <div><dt>Booking ID</dt><dd data-checkin-bind="reference">—</dd></div>
                    <div><dt>Room</dt><dd><?= htmlspecialchars((string)$room['room_name']) ?></dd></div>
                    <div><dt>Guests</dt><dd><span data-checkin-bind="guests">—</span></dd></div>
                    <div><dt>Valid ID</dt><dd><span data-checkin-id-summary>—</span></dd></div>
                  </dl>
                </div>
              </div>

              <div class="ho-checkin-qr-row" data-checkin-qr-notice hidden>
                <div class="ho-checkin-qr-message">
                  <strong>QR delivery to registered phone</strong>
                  <span>PayMongo sends the secure QR to the hotel-admin phone. Check-in continues only after provider verification.</span>
                </div>
                <section class="ho-checkin-phone-card" data-checkin-phone-card aria-live="polite">
                  <span class="ho-checkin-phone-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><rect x="6" y="2" width="12" height="20" rx="2"/><path d="M10 18h4"/></svg></span>
                  <div><small>REGISTERED HOTEL PHONE</small><strong data-checkin-phone-name>Checking registered phone…</strong><span data-checkin-phone-meta>Please wait.</span></div>
                  <button type="button" data-change-checkin-phone>Change</button>
                </section>
              </div>

              <div class="ho-checkin-confirm-note">
                <span aria-hidden="true">i</span>
                <p><strong>Before you confirm</strong> This records the guest as checked in and marks the room as occupied.</p>
              </div>
              </div>

              <footer class="ho-checkin-footer">
                <button type="button" class="ho-btn" data-checkin-prev>Back</button>
                <button type="submit" class="ho-btn confirm">Confirm check-in</button>
              </footer>
            </section>
          </form>
        </div>
      </div>
    <?php endif; ?>

    <?php if ($roomStatus['status'] === 'occupied' && $statusBooking): ?>
      <?php
        $bookingId = (int)$statusBooking['hotel_booking_id'];
        $checkoutDefaultGuest = trim((string)($statusBooking['checkout_guest_name'] ?? '')) !== ''
            ? (string)$statusBooking['checkout_guest_name']
            : (trim((string)($statusBooking['checkin_guest_name'] ?? '')) !== '' ? (string)$statusBooking['checkin_guest_name'] : (string)($statusBooking['guest_name'] ?? ''));
        $checkoutDefaultRep = trim((string)($statusBooking['checkout_representative_name'] ?? '')) !== ''
            ? (string)$statusBooking['checkout_representative_name']
            : (string)($statusBooking['checkin_representative_name'] ?? '');
        $checkedInAt = (string)($statusBooking['checked_in_at'] ?? '');
        $remainingDue = max(0, (float)($statusBooking['remaining_balance'] ?? 0));
        $checkedInDisplay = $checkedInAt !== '' ? date('Y-m-d\TH:i', strtotime($checkedInAt)) : date('Y-m-d\TH:i');
      ?>
      <div class="ho-modal ho-checkout-modal" id="checkoutModal<?= $bookingId ?>" aria-hidden="true">
        <div class="ho-modal-card ho-lifecycle-modal-card ho-checkout-card" role="dialog" aria-modal="true" aria-labelledby="checkoutTitle<?= $bookingId ?>">
          <header class="ho-checkout-header">
            <div class="ho-checkout-heading">
              <span class="ho-checkout-heading-icon" aria-hidden="true">
                <svg viewBox="0 0 24 24"><path d="M9 5H5a2 2 0 0 0-2 2v10a2 2 0 0 0 2 2h4"></path><path d="m16 17 5-5-5-5"></path><path d="M21 12H9"></path></svg>
              </span>
              <div>
                <span class="ho-checkout-eyebrow">Guest departure</span>
                <h3 id="checkoutTitle<?= $bookingId ?>">Complete guest check-out</h3>
                <p>Review the stay and settle any outstanding charges before releasing the room.</p>
              </div>
            </div>
            <button type="button" class="ho-checkout-close" data-close-modal aria-label="Close check-out dialog">&times;</button>
          </header>
          <form method="post" class="ho-lifecycle-form" data-checkout-form data-checked-in-at="<?= htmlspecialchars($checkedInDisplay) ?>" data-base-remaining="<?= htmlspecialchars(number_format($remainingDue, 2, '.', '')) ?>" data-booking-reference="<?= htmlspecialchars((string)$statusBooking['booking_reference']) ?>">
            <input type="hidden" name="room_action" value="checkout" />
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($hotelRoomCsrf, ENT_QUOTES, 'UTF-8') ?>" />
            <input type="hidden" name="room_id" value="<?= (int)$room['id'] ?>" />
            <input type="hidden" name="booking_id" value="<?= $bookingId ?>" />
            <input type="hidden" name="checkout_paymongo_token" value="" data-checkout-paymongo-token />
            <div class="ho-checkout-scroll-region">
              <div class="ho-checkout-meta" aria-label="Booking summary">
                <span><small>Booking reference</small><strong><?= htmlspecialchars((string)$statusBooking['booking_reference']) ?></strong></span>
                <span><small>Room</small><strong><?= htmlspecialchars((string)$room['room_name']) ?></strong></span>
                <span><small>Reservation total</small><strong>₱<?= number_format((float)($statusBooking['total_amount'] ?? 0), 2) ?></strong></span>
                <span><small>Payment recorded</small><strong class="paid">₱<?= number_format((float)($statusBooking['amount_paid'] ?? 0), 2) ?></strong></span>
              </div>

              <div class="ho-checkout-body">
              <section class="ho-checkout-section" aria-labelledby="checkoutGuestHeading<?= $bookingId ?>">
                <div class="ho-checkout-section-head">
                  <span class="ho-checkout-section-number">01</span>
                  <div><h4 id="checkoutGuestHeading<?= $bookingId ?>">Guest verification</h4><p>Confirm the departing guest and the staff representative processing this record.</p></div>
                </div>
                <div class="ho-checkout-fields two">
                  <label>Guest name
                    <input type="text" name="checkout_guest_name" value="<?= htmlspecialchars($checkoutDefaultGuest) ?>" autocomplete="name" required />
                  </label>
                  <label>Representative name
                    <input type="text" name="checkout_representative_name" value="<?= htmlspecialchars($checkoutDefaultRep) ?>" placeholder="Enter staff representative" required />
                  </label>
                </div>
              </section>

              <section class="ho-checkout-section" aria-labelledby="checkoutStayHeading<?= $bookingId ?>">
                <div class="ho-checkout-section-head">
                  <span class="ho-checkout-section-number">02</span>
                  <div><h4 id="checkoutStayHeading<?= $bookingId ?>">Stay details</h4><p>Verify the departure time. The stay duration updates automatically.</p></div>
                </div>
                <div class="ho-checkout-fields three">
                  <label>Checked in
                    <input type="datetime-local" value="<?= htmlspecialchars($checkedInDisplay) ?>" readonly />
                  </label>
                  <label>Check-out date &amp; time
                    <input type="datetime-local" name="checkout_date_time" value="<?= htmlspecialchars(date('Y-m-d\TH:i')) ?>" required />
                  </label>
                  <label>Total stay
                    <span class="ho-checkout-suffixed-input"><input type="number" name="checkout_total_nights_display" min="1" step="1" value="1" readonly /><span>nights</span></span>
                  </label>
                </div>
              </section>

              <section class="ho-checkout-section ho-checkout-settlement" aria-labelledby="checkoutPaymentHeading<?= $bookingId ?>">
                <div class="ho-checkout-section-head">
                  <span class="ho-checkout-section-number">03</span>
                  <div><h4 id="checkoutPaymentHeading<?= $bookingId ?>">Final settlement</h4><p>Record incidental charges and confirm the amount collected at departure.</p></div>
                  <span class="ho-checkout-secure">Final review</span>
                </div>
                <div class="ho-checkout-breakdown" aria-live="polite">
                  <div><small>Outstanding balance</small><strong>₱<?= number_format($remainingDue, 2) ?></strong></div>
                  <span class="ho-checkout-operator" aria-hidden="true">+</span>
                  <div><small>Additional charges</small><strong data-checkout-additional-value>₱0.00</strong></div>
                  <span class="ho-checkout-operator" aria-hidden="true">=</span>
                  <div class="total" data-checkout-due-note><small>Amount due</small><strong data-checkout-due-value>₱<?= number_format($remainingDue, 2) ?></strong></div>
                </div>
                <div class="ho-checkout-payment-grid">
                  <label>Additional charges
                    <span class="ho-checkout-money-input"><span>₱</span><input type="number" name="checkout_additional_charges" min="0" step="0.01" value="0.00" required /></span>
                    <small>Enter 0.00 if there are no incidental charges.</small>
                  </label>
                  <label>Payment method
                    <select name="checkout_payment_method" required <?= $remainingDue > 0 ? '' : 'disabled' ?> data-checkout-payment-method>
                      <option value="" selected disabled>Select payment method</option>
                      <option value="cash">Cash</option>
                      <option value="qr_code">QR Code (PayMongo)</option>
                    </select>
                    <small>Required only when an amount is due.</small>
                  </label>
                  <label>Amount collected
                    <span class="ho-checkout-money-input emphasis"><span>₱</span><input type="number" name="checkout_final_payment" min="<?= htmlspecialchars(number_format($remainingDue, 2, '.', '')) ?>" step="0.01" value="<?= htmlspecialchars(number_format($remainingDue, 2, '.', '')) ?>" required /></span>
                    <small>Must cover the complete amount due.</small>
                  </label>
                </div>
                <div class="ho-checkin-qr-row ho-checkout-qr-row" data-checkout-qr-notice hidden>
                  <div class="ho-checkin-qr-message">
                    <strong>QR delivery to registered phone</strong>
                    <span>PayMongo sends the secure QR to the hotel-admin phone. Check-out continues only after provider verification.</span>
                  </div>
                  <section class="ho-checkin-phone-card" data-checkin-phone-card aria-live="polite">
                    <span class="ho-checkin-phone-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><rect x="6" y="2" width="12" height="20" rx="2"/><path d="M10 18h4"/></svg></span>
                    <div><small>REGISTERED HOTEL PHONE</small><strong data-checkin-phone-name>Checking registered phone…</strong><span data-checkin-phone-meta>Please wait.</span></div>
                    <button type="button" data-change-checkin-phone>Change</button>
                  </section>
                </div>
              </section>
              </div>
            </div>

            <footer class="ho-checkout-footer">
              <p><span aria-hidden="true">i</span><span><strong>Completion notice</strong>This will mark the booking as completed and return the room to Available.</span></p>
              <div>
                <button type="button" class="ho-btn ho-checkout-cancel" data-close-modal>Cancel</button>
                <button type="submit" class="ho-btn ho-checkout-submit"><span>Complete check-out</span><span aria-hidden="true">&rarr;</span></button>
              </div>
            </footer>
          </form>
        </div>
      </div>
    <?php endif; ?>
  <?php endforeach; ?>

  <?php foreach ($roomCards as $card): ?>
    <?php $room = $card['room']; ?>
    <div class="ho-modal" id="roomModal<?= (int)$room['id'] ?>" aria-hidden="true">
      <div class="ho-modal-card ho-room-modal-card">
        <div class="ho-modal-head">
          <div><h3>Edit Room</h3><p>Update room details, capacity, pricing, and photos.</p></div>
          <button type="button" class="ho-close" data-close-modal>&times;</button>
        </div>
        <form method="post" class="ho-room-form" enctype="multipart/form-data">
          <input type="hidden" name="room_action" value="update" />
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($hotelRoomCsrf, ENT_QUOTES, 'UTF-8') ?>" />
          <input type="hidden" name="room_id" value="<?= (int)$room['id'] ?>" />

          <label>Room Name<input type="text" name="room_name" value="<?= htmlspecialchars((string)$room['room_name']) ?>" required /></label>
          <label>Description<textarea name="description" rows="3"><?= htmlspecialchars((string)$room['description']) ?></textarea></label>

            <div class="ho-room-row">
              <label>Price<input type="number" name="price" min="0" step="0.01" value="<?= htmlspecialchars((string)$room['price']) ?>" required /></label>
              <label>Adults<input type="number" name="capacity_adults" min="1" value="<?= (int)$room['capacity_adults'] ?>" required /></label>
              <label>Children<input type="number" name="capacity_children" min="0" value="<?= (int)$room['capacity_children'] ?>" required /></label>
              <label>Breakfast For<input type="number" name="breakfast_for" min="0" value="<?= (int)$room['breakfast_for'] ?>" required /></label>
            </div>

          <div class="ho-image-editor-section">
            <h4>Main Image</h4>
            <div class="ho-main-image-row">
              <div class="ho-main-image-preview">
                <img src="<?= htmlspecialchars((string)$room['main_image_path']) ?>" alt="Main image preview" />
              </div>
              <div class="ho-main-image-fields">
                <label>Main Image Path<input type="text" name="main_image_path" value="<?= htmlspecialchars((string)$room['main_image_path']) ?>" /></label>
                <div class="ho-room-image-control">
                  <input type="file" name="main_image_file" accept="image/*" data-room-image-input hidden />
                  <button type="button" class="ho-room-image-trigger" data-room-image-trigger>Update main image</button>
                  <small>JPG, PNG, or WebP up to 40 MB</small>
                </div>
              </div>
            </div>
          </div>

          <div class="ho-image-editor-section">
            <h4>Gallery Images</h4>
            <div class="ho-gallery-list" data-gallery-list>
              <?php foreach ((array)$room['gallery_images'] as $galleryPath): ?>
                <div class="ho-gallery-row">
                  <img src="<?= htmlspecialchars((string)$galleryPath) ?>" alt="Gallery image preview" class="ho-gallery-thumb" />
                  <div class="ho-gallery-input-stack">
                    <input type="text" name="gallery_paths[]" value="<?= htmlspecialchars((string)$galleryPath) ?>" />
                    <button type="button" class="ho-gallery-upload-inline" data-room-image-trigger>Update image</button>
                    <input type="file" name="gallery_row_files[]" accept="image/*" data-room-image-input hidden />
                  </div>
                  <button type="button" class="ho-gallery-remove" data-remove-gallery-row aria-label="Remove image">&times;</button>
                </div>
              <?php endforeach; ?>
            </div>
            <div class="ho-gallery-actions">
              <button type="button" class="ho-btn" data-add-gallery-row>+ Add Gallery Row</button>
            </div>
            <label class="ho-alt-input-label">Optional quick paste (one path/URL per line)
              <textarea name="gallery_images_text" rows="2" placeholder="Paste only new gallery paths you want to add"></textarea>
            </label>
          </div>

          <div class="ho-rich-forms-grid">
            <label>Room Meta (JSON object)
              <textarea name="meta_json" rows="7"><?= htmlspecialchars(json_encode((array)$room['meta'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) ?></textarea>
            </label>
            <label>Inclusions (one item per line)
              <textarea name="inclusions_text" rows="7"><?= htmlspecialchars(implode("\n", (array)$room['inclusions'])) ?></textarea>
            </label>
          </div>

          <div class="ho-room-actions">
            <button type="button" class="ho-btn cancel" data-close-modal>Cancel</button>
            <button type="submit" class="ho-btn confirm">Save Changes</button>
          </div>
        </form>

        <?php if ((string)$room['status'] === 'active'): ?>
          <form method="post" class="ho-room-archive-form" data-archive-form>
            <input type="hidden" name="room_action" value="archive" />
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($hotelRoomCsrf, ENT_QUOTES, 'UTF-8') ?>" />
            <input type="hidden" name="room_id" value="<?= (int)$room['id'] ?>" />
            <button type="submit" class="ho-btn cancel">Archive Room</button>
          </form>
        <?php endif; ?>
      </div>
    </div>
  <?php endforeach; ?>

  <div class="ho-modal ho-checkin-phone-modal" id="hoCheckinPhoneSetupModal" aria-hidden="true">
    <div class="ho-modal-card ho-checkin-phone-modal-card" role="dialog" aria-modal="true" aria-labelledby="hoCheckinPhoneSetupTitle">
      <header>
        <div><span>PAYMONGO NOTIFICATIONS</span><h3 id="hoCheckinPhoneSetupTitle">Change Registered Payment Phone</h3></div>
        <button type="button" data-close-checkin-phone aria-label="Close phone setup">&times;</button>
      </header>
      <div class="ho-checkin-phone-modal-body">
        <ol class="ho-checkin-phone-steps">
          <li><b>Open the setup address on the hotel phone.</b><span>Log in with the same hotel administrator account when asked.</span></li>
          <li><b>Name and register the phone.</b><span>Allow browser notifications on the setup page.</span></li>
          <li><b>Return to this computer.</b><span>This window detects the registered phone automatically.</span></li>
        </ol>
        <label class="ho-checkin-phone-link"><span>PHONE SETUP ADDRESS</span><div><input type="text" id="hoCheckinPhoneSetupUrl" readonly /><button type="button" id="hoCopyCheckinPhoneUrl">Copy Link</button></div></label>
        <div class="ho-checkin-phone-registration" id="hoCheckinPhoneRegistration"><i></i><div><strong>Waiting for phone registration</strong><small>Keep this window open while registering the phone.</small></div></div>
      </div>
      <footer><button type="button" class="ho-btn" data-close-checkin-phone>Close</button><button type="button" class="ho-btn" id="hoCheckinPhoneCheckAgain">Check Again</button><button type="button" class="ho-btn confirm" id="hoOpenCheckinPhoneSetup">Open Setup Page</button></footer>
    </div>
  </div>

  <div class="ho-modal" id="hoAddRoomModal" aria-hidden="true">
    <div class="ho-modal-card ho-room-modal-card">
      <div class="ho-modal-head">
        <div><h3>Add New Room</h3><p>Create the room listing and add its photos.</p></div>
        <button type="button" class="ho-close" data-close-modal>&times;</button>
      </div>
      <form method="post" class="ho-room-form" enctype="multipart/form-data">
        <input type="hidden" name="room_action" value="create" />
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($hotelRoomCsrf, ENT_QUOTES, 'UTF-8') ?>" />
        <label>Room Name<input type="text" name="room_name" required /></label>
        <label>Description<textarea name="description" rows="3"></textarea></label>
        <div class="ho-room-row">
          <label>Price<input type="number" name="price" min="0" step="0.01" required /></label>
          <label>Adults<input type="number" name="capacity_adults" min="1" value="2" required /></label>
          <label>Children<input type="number" name="capacity_children" min="0" value="0" required /></label>
          <label>Breakfast For<input type="number" name="breakfast_for" min="0" value="2" required /></label>
        </div>
        <div class="ho-image-editor-section">
          <h4>Main Image</h4>
          <div class="ho-main-image-row">
            <div class="ho-main-image-preview is-empty">No image yet</div>
            <div class="ho-main-image-fields">
              <label>Main Image Path<input type="text" name="main_image_path" placeholder="img/sampleimage.png or image URL" /></label>
              <div class="ho-room-image-control">
                <input type="file" name="main_image_file" accept="image/*" data-room-image-input hidden />
                <button type="button" class="ho-room-image-trigger" data-room-image-trigger>Choose main image</button>
                <small>JPG, PNG, or WebP up to 40 MB</small>
              </div>
            </div>
          </div>
        </div>

        <div class="ho-image-editor-section">
          <h4>Gallery Images</h4>
          <div class="ho-gallery-list" data-gallery-list></div>
          <div class="ho-gallery-actions">
            <button type="button" class="ho-btn" data-add-gallery-row>+ Add Gallery Row</button>
          </div>
          <label class="ho-alt-input-label">Optional quick paste (one path/URL per line)<textarea name="gallery_images_text" rows="2"></textarea></label>
        </div>

        <div class="ho-rich-forms-grid">
          <label>Room Meta (JSON object)<textarea name="meta_json" rows="7" placeholder='{"bed":"1 Queen bed","view":"Sea View","size":"30 sqm"}'></textarea></label>
          <label>Inclusions (one item per line)<textarea name="inclusions_text" rows="7" placeholder="Air conditioning&#10;Private CR&#10;Free WiFi"></textarea></label>
        </div>
        <div class="ho-room-actions">
          <button type="button" class="ho-btn cancel" data-close-modal>Cancel</button>
          <button type="submit" class="ho-btn confirm">Add Room</button>
        </div>
      </form>
    </div>
  </div>

  <div class="ho-modal" id="hoRoomImageUploadModal" aria-hidden="true">
    <div class="ho-modal-card ho-room-image-upload-card" role="dialog" aria-modal="true" aria-labelledby="hoRoomImageUploadTitle">
      <div class="ho-modal-head">
        <div><h3 id="hoRoomImageUploadTitle">Update room image</h3><p>Choose a high-quality image before applying it to the room.</p></div>
        <button type="button" class="ho-close" id="hoRoomImageUploadClose" aria-label="Close image upload">&times;</button>
      </div>
      <div class="ho-room-image-upload-body">
        <label class="ho-room-image-dropzone" for="hoRoomImagePicker" data-room-image-dropzone>
          <span class="ho-room-image-upload-icon" aria-hidden="true">
            <svg viewBox="0 0 24 24"><path d="M10 1a1 1 0 0 0-.71.29l-6 6A1 1 0 0 0 3 8v12a3 3 0 0 0 3 3h1a1 1 0 1 0 0-2H6a1 1 0 0 1-1-1V9h5a1 1 0 0 0 1-1V3h7a1 1 0 0 1 1 1v5a1 1 0 1 0 2 0V4a3 3 0 0 0-3-3h-8ZM9 7H6.41L9 4.41V7Zm7.5 4a4.5 4.5 0 0 0-4.48 4.12A4 4 0 0 0 13 23h7a4 4 0 0 0 .98-7.88A4.5 4.5 0 0 0 16.5 11Zm0 2a2.5 2.5 0 0 1 2.5 2.5V17h1a2 2 0 1 1 0 4h-7a2 2 0 1 1 0-4h1v-1.5a2.5 2.5 0 0 1 2.5-2.5Z"/></svg>
          </span>
          <strong>Click or drag an image here</strong>
          <small>JPG, PNG, or WebP · maximum 40 MB</small>
          <input type="file" id="hoRoomImagePicker" accept="image/jpeg,image/png,image/webp" hidden />
        </label>
        <div class="ho-room-image-selected" id="hoRoomImageSelected" hidden>
          <img id="hoRoomImageSelectedPreview" alt="Selected room image preview" />
          <div><strong id="hoRoomImageSelectedName"></strong><span>Ready to apply</span></div>
          <button type="button" id="hoRoomImageChooseAgain">Choose another</button>
        </div>
      </div>
      <div class="ho-room-image-upload-actions">
        <button type="button" class="ho-btn" id="hoRoomImageUploadCancel">Cancel</button>
        <button type="button" class="ho-btn confirm" id="hoRoomImageUploadApply" disabled>Apply image</button>
      </div>
    </div>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
  <script src="js/image-upload-optimizer-v2.js?v=<?= (int)@filemtime(__DIR__ . '/js/image-upload-optimizer-v2.js') ?>"></script>
  <script>
    (function () {
      const flashMessage = <?= json_encode($flash, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
      const flashTitle = <?= json_encode($flashTitle, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
      const errorMessage = <?= json_encode($error, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
      const toggle = document.getElementById('hoNotifToggle');
      const panel = document.getElementById('hoNotifPanel');
      const markBtn = document.getElementById('hoNotifMarkRead');
      const badge = document.getElementById('hoNotifBadge');
      const unreadSelector = '.ho-notif-item.is-unread';
      let notifMarked = false;
      const hideBadge = () => {
        if (badge) badge.style.display = 'none';
      };
      const hasUnreadItems = () => panel ? panel.querySelector(unreadSelector) !== null : false;
      const clearUnreadState = () => {
        if (!panel) return;
        panel.querySelectorAll(unreadSelector).forEach((item) => item.classList.remove('is-unread'));
        panel.querySelectorAll('.ho-notif-unread-pill').forEach((pill) => pill.remove());
      };

      const markNotificationsRead = async () => {
        if (notifMarked || !hasUnreadItems()) return;
        notifMarked = true;
        const body = new URLSearchParams();
        body.set('ho_action', 'mark_notifications_read');
        body.set('csrf_token', <?= json_encode($hotelNotificationCsrf) ?>);
        try {
          const response = await fetch('Horooms.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body.toString()
          });
          if (!response.ok) {
            throw new Error(`Failed to mark notifications as read (${response.status})`);
          }
          hideBadge();
          clearUnreadState();
        } catch (error) {
          notifMarked = false;
          console.error(error);
        }
      };

      const closePanelAndMarkRead = () => {
        if (!panel || !toggle) return;
        const wasOpen = panel.classList.contains('open');
        panel.classList.remove('open');
        toggle.setAttribute('aria-expanded', 'false');
        if (wasOpen) markNotificationsRead();
      };

      if (toggle && panel) {
        toggle.addEventListener('click', () => {
          const willOpen = !panel.classList.contains('open');
          if (!willOpen) {
            closePanelAndMarkRead();
            return;
          }
          panel.classList.add('open');
          toggle.setAttribute('aria-expanded', 'true');
          hideBadge();
        });

        document.addEventListener('click', (e) => {
          if (!panel.contains(e.target) && !toggle.contains(e.target)) {
            closePanelAndMarkRead();
          }
        });
      }

      if (markBtn) markBtn.addEventListener('click', markNotificationsRead);

      const syncRoomListViewport = () => {
        const listLayout = document.querySelector('.ho-room-list-layout');
        if (!listLayout) return;
        const topOffset = listLayout.getBoundingClientRect().top;
        const available = Math.floor(window.innerHeight - topOffset - 12);
        const targetHeight = Math.max(460, available);
        listLayout.style.setProperty('--ho-room-list-height', `${targetHeight}px`);
      };
      syncRoomListViewport();
      window.addEventListener('resize', syncRoomListViewport);

      const addModal = document.getElementById('hoAddRoomModal');
      const openAddBtn = document.querySelector('[data-open-add-room]');
      if (openAddBtn && addModal) {
        openAddBtn.addEventListener('click', () => addModal.classList.add('open'));
      }

      document.querySelectorAll('[data-open-room-modal]').forEach(btn => {
        btn.addEventListener('click', () => {
          const targetId = btn.getAttribute('data-open-room-modal');
          const modal = targetId ? document.getElementById(targetId) : null;
          if (modal) modal.classList.add('open');
        });
      });
      document.querySelectorAll('[data-open-lifecycle-modal]').forEach(btn => {
        btn.addEventListener('click', () => {
          const targetId = btn.getAttribute('data-open-lifecycle-modal');
          const modal = targetId ? document.getElementById(targetId) : null;
          if (modal) {
            if (modal.matches('.ho-checkin-flow-modal')) {
              resetCheckinFlow(modal);
            }
            if (modal.matches('.ho-room-bookings-modal')) {
              setRoomBookingTab(modal, 'all');
            }
            modal.classList.add('open');
            modal.setAttribute('aria-hidden', 'false');
          }
        });
      });

      function setRoomBookingTab(modal, tabName) {
        modal.querySelectorAll('[data-room-booking-tab]').forEach(button => {
          const active = button.getAttribute('data-room-booking-tab') === tabName;
          button.classList.toggle('active', active);
          button.setAttribute('aria-selected', active ? 'true' : 'false');
        });
        modal.querySelectorAll('[data-room-booking-panel]').forEach(panelItem => {
          const active = panelItem.getAttribute('data-room-booking-panel') === tabName;
          panelItem.classList.toggle('active', active);
          panelItem.hidden = !active;
        });
        const body = modal.querySelector('.ho-room-bookings-body');
        if (body) body.scrollTop = 0;
      }

      document.querySelectorAll('.ho-room-bookings-modal').forEach(modal => {
        modal.querySelectorAll('[data-room-booking-tab]').forEach(button => {
          button.addEventListener('click', () => {
            setRoomBookingTab(modal, button.getAttribute('data-room-booking-tab') || 'all');
          });
        });
      });

      document.querySelectorAll('[data-close-modal]').forEach(btn => {
        btn.addEventListener('click', () => {
          const modal = btn.closest('.ho-modal');
          if (modal) {
            modal.classList.remove('open');
            modal.setAttribute('aria-hidden', 'true');
          }
        });
      });

      document.querySelectorAll('.ho-modal').forEach(modal => {
        modal.addEventListener('click', (e) => {
          if (e.target === modal) {
            modal.classList.remove('open');
            modal.setAttribute('aria-hidden', 'true');
          }
        });
      });

      const buildGalleryRow = (value = '') => {
        const wrapper = document.createElement('div');
        wrapper.className = 'ho-gallery-row';
        wrapper.innerHTML = `
          <img src="${value || 'img/sampleimage.png'}" alt="Gallery image preview" class="ho-gallery-thumb" />
          <div class="ho-gallery-input-stack">
            <input type="text" name="gallery_paths[]" value="${value}" />
            <button type="button" class="ho-gallery-upload-inline" data-room-image-trigger>Choose image</button>
            <input type="file" name="gallery_row_files[]" accept="image/*" data-room-image-input hidden />
          </div>
          <button type="button" class="ho-gallery-remove" data-remove-gallery-row aria-label="Remove image">&times;</button>
        `;
        const input = wrapper.querySelector('input[name="gallery_paths[]"]');
        const img = wrapper.querySelector('img');
        const upload = wrapper.querySelector('input[type="file"]');
        if (input && img) {
          input.addEventListener('input', () => {
            img.src = input.value.trim() || 'img/sampleimage.png';
          });
        }
        if (upload && img) {
          upload.addEventListener('change', () => {
            const file = upload.files && upload.files[0];
            if (!file) return;
            const tempUrl = URL.createObjectURL(file);
            img.src = tempUrl;
          });
        }
        return wrapper;
      };

      document.querySelectorAll('[data-add-gallery-row]').forEach(btn => {
        btn.addEventListener('click', () => {
          const modal = btn.closest('.ho-modal-card');
          const list = modal ? modal.querySelector('[data-gallery-list]') : null;
          if (!list) return;
          list.appendChild(buildGalleryRow(''));
        });
      });

      document.querySelectorAll('[data-gallery-list]').forEach(list => {
        list.querySelectorAll('.ho-gallery-row').forEach(row => {
          const input = row.querySelector('input[name="gallery_paths[]"]');
          const img = row.querySelector('.ho-gallery-thumb');
          const upload = row.querySelector('input[type="file"]');
          if (input && img) {
            input.addEventListener('input', () => {
              img.src = input.value.trim() || 'img/sampleimage.png';
            });
          }
          if (upload && img) {
            upload.addEventListener('change', () => {
              const file = upload.files && upload.files[0];
              if (!file) return;
              const tempUrl = URL.createObjectURL(file);
              img.src = tempUrl;
            });
          }
        });
      });

      const roomImageModal = document.getElementById('hoRoomImageUploadModal');
      const roomImagePicker = document.getElementById('hoRoomImagePicker');
      const roomImageDropzone = roomImageModal?.querySelector('[data-room-image-dropzone]');
      const roomImageSelected = document.getElementById('hoRoomImageSelected');
      const roomImagePreview = document.getElementById('hoRoomImageSelectedPreview');
      const roomImageName = document.getElementById('hoRoomImageSelectedName');
      const roomImageApply = document.getElementById('hoRoomImageUploadApply');
      const roomImageCancel = document.getElementById('hoRoomImageUploadCancel');
      const roomImageClose = document.getElementById('hoRoomImageUploadClose');
      const roomImageChooseAgain = document.getElementById('hoRoomImageChooseAgain');
      let roomImageTargetInput = null;
      let roomImagePendingFile = null;
      let roomImagePreviewUrl = '';

      const resetRoomImageUpload = () => {
        roomImagePendingFile = null;
        if (roomImagePicker) roomImagePicker.value = '';
        if (roomImagePreviewUrl) URL.revokeObjectURL(roomImagePreviewUrl);
        roomImagePreviewUrl = '';
        if (roomImagePreview) roomImagePreview.removeAttribute('src');
        if (roomImageName) roomImageName.textContent = '';
        if (roomImageSelected) roomImageSelected.hidden = true;
        if (roomImageDropzone) roomImageDropzone.hidden = false;
        ItourImageOptimizer.setButtonBusy(roomImageApply, false, '', true);
      };

      const closeRoomImageUpload = () => {
        roomImageModal?.classList.remove('open');
        roomImageModal?.setAttribute('aria-hidden', 'true');
        roomImageTargetInput = null;
        resetRoomImageUpload();
      };

      const openRoomImageUpload = (targetInput) => {
        if (!roomImageModal || !targetInput) return;
        resetRoomImageUpload();
        roomImageTargetInput = targetInput;
        roomImageModal.classList.add('open');
        roomImageModal.setAttribute('aria-hidden', 'false');
      };

      const selectRoomImage = async (file) => {
        if (!file) return;
        ItourImageOptimizer.setButtonBusy(roomImageApply, true, 'Optimizing image...');
        if (roomImageName) roomImageName.textContent = 'Optimizing image...';
        try {
          const optimizedFile = await ItourImageOptimizer.optimizeSource(file, 2400);
          if (roomImagePreviewUrl) URL.revokeObjectURL(roomImagePreviewUrl);
          roomImagePendingFile = optimizedFile;
          roomImagePreviewUrl = URL.createObjectURL(optimizedFile);
          if (roomImagePreview) roomImagePreview.src = roomImagePreviewUrl;
          if (roomImageName) roomImageName.textContent = optimizedFile.name;
          if (roomImageDropzone) roomImageDropzone.hidden = true;
          if (roomImageSelected) roomImageSelected.hidden = false;
          ItourImageOptimizer.setButtonBusy(roomImageApply, false);
        } catch (error) {
          if (roomImageName) roomImageName.textContent = '';
          ItourImageOptimizer.setButtonBusy(roomImageApply, false, '', true);
          Swal.fire({ icon: 'error', title: 'Image could not be processed', text: error.message || 'Please try another photo.' });
        }
      };

      document.addEventListener('click', (event) => {
        const trigger = event.target instanceof Element ? event.target.closest('[data-room-image-trigger]') : null;
        if (!trigger) return;
        const scope = trigger.closest('.ho-main-image-fields, .ho-gallery-input-stack');
        openRoomImageUpload(scope?.querySelector('[data-room-image-input]'));
      });

      roomImagePicker?.addEventListener('change', () => selectRoomImage(roomImagePicker.files?.[0]));
      roomImageChooseAgain?.addEventListener('click', () => roomImagePicker?.click());
      [roomImageCancel, roomImageClose].forEach(button => button?.addEventListener('click', closeRoomImageUpload));
      roomImageModal?.addEventListener('click', event => {
        if (event.target === roomImageModal) closeRoomImageUpload();
      });
      ['dragenter', 'dragover'].forEach(type => roomImageDropzone?.addEventListener(type, event => {
        event.preventDefault();
        roomImageDropzone.classList.add('is-dragging');
      }));
      ['dragleave', 'drop'].forEach(type => roomImageDropzone?.addEventListener(type, event => {
        event.preventDefault();
        roomImageDropzone.classList.remove('is-dragging');
      }));
      roomImageDropzone?.addEventListener('drop', event => selectRoomImage(event.dataTransfer?.files?.[0]));
      roomImageApply?.addEventListener('click', () => {
        if (!roomImageTargetInput || !roomImagePendingFile) return;
        const transfer = new DataTransfer();
        transfer.items.add(roomImagePendingFile);
        roomImageTargetInput.files = transfer.files;
        roomImageTargetInput.dispatchEvent(new Event('change', { bubbles: true }));

        const mainFields = roomImageTargetInput.closest('.ho-main-image-fields');
        const mainPreview = mainFields?.closest('.ho-main-image-row')?.querySelector('.ho-main-image-preview');
        if (mainPreview) {
          let image = mainPreview.querySelector('img');
          if (!image) {
            mainPreview.textContent = '';
            image = document.createElement('img');
            image.alt = 'Main image preview';
            mainPreview.appendChild(image);
          }
          image.src = URL.createObjectURL(roomImagePendingFile);
          mainPreview.classList.remove('is-empty');
        }
        closeRoomImageUpload();
      });

      document.querySelectorAll('form.ho-room-form[enctype="multipart/form-data"]').forEach(form => {
        form.addEventListener('submit', event => {
          const hasImageUpload = Array.from(form.querySelectorAll('input[type="file"]')).some(input => input.files?.length);
          if (!hasImageUpload || form.dataset.imageSubmitting === 'true') return;
          event.preventDefault();
          form.dataset.imageSubmitting = 'true';
          const submitButton = form.querySelector('button[type="submit"]');
          ItourImageOptimizer.setButtonBusy(submitButton, true, 'Uploading images...');
          requestAnimationFrame(() => form.submit());
        });
      });

      document.addEventListener('click', (e) => {
        const target = e.target;
        if (!(target instanceof HTMLElement)) return;
        if (!target.matches('[data-remove-gallery-row]')) return;
        const row = target.closest('.ho-gallery-row');
        const list = row ? row.parentElement : null;
        if (!row || !list) return;
        row.remove();
      });

      const formatMoney = (value) => `₱${Number(value || 0).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
      const checkinPayMongoCsrf = <?= json_encode($hoPayMongoCsrf, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
      const checkinPayMongoEndpoint = new URL('payments/create-balance-checkout.php', window.location.href).href;
      const checkinPayMongoStatusEndpoint = 'Hobookings.php?ho_action=paymongo_payment_status';
      const checkinPhoneStatusEndpoint = 'hotel-push-device-status.php';
      const checkinPhoneSetupPage = 'hotel-admin-phone-setup.php';
      const checkinPublicAppUrl = <?= json_encode((string)($hoFirebaseConfiguration['app_url'] ?? ''), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
      const checkinPhoneState = { device: null, baseline: null, displayTimer: null, registrationTimer: null };
      const readCheckinPaymentJson = async response => {
        if (!(response.headers.get('content-type') || '').includes('application/json')) {
          throw new Error('The payment service returned an unexpected response.');
        }
        return response.json();
      };
      const fetchCheckinPhone = async () => {
        const response = await fetch(checkinPhoneStatusEndpoint, {
          credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'application/json' }
        });
        const data = await readCheckinPaymentJson(response);
        if (!response.ok || !data.success) throw new Error(data.message || 'The registered hotel phone could not be checked.');
        return data;
      };
      const renderCheckinPhone = data => {
        const registered = Boolean(data?.registered && data.device);
        checkinPhoneState.device = registered ? data.device : null;
        const registeredAt = registered
          ? new Date(String(data.device.last_used_at || data.device.created_at || '').replace(' ', 'T'))
          : null;
        document.querySelectorAll('[data-checkin-phone-card]').forEach(card => {
          card.classList.toggle('is-registered', registered);
          const name = card.querySelector('[data-checkin-phone-name]');
          const meta = card.querySelector('[data-checkin-phone-meta]');
          const action = card.querySelector('[data-change-checkin-phone]');
          if (name) name.textContent = registered ? (data.device.device_name || 'Hotel administrator phone') : 'No hotel phone registered';
          if (meta) meta.textContent = registered
            ? (Number.isNaN(registeredAt.getTime()) ? 'Notifications active on this phone.' : `Notifications active · Registered ${registeredAt.toLocaleString()}`)
            : 'Register a phone to receive PayMongo QR notifications.';
          if (action) action.textContent = registered ? 'Change' : 'Register Phone';
        });
      };
      const refreshCheckinPhone = async (silent = false) => {
        if (!silent) {
          document.querySelectorAll('[data-checkin-phone-name]').forEach(node => { node.textContent = 'Checking registered phone…'; });
          document.querySelectorAll('[data-checkin-phone-meta]').forEach(node => { node.textContent = 'Please wait.'; });
        }
        try {
          const data = await fetchCheckinPhone();
          renderCheckinPhone(data);
          return data;
        } catch (error) {
          if (!silent) {
            document.querySelectorAll('[data-checkin-phone-name]').forEach(node => { node.textContent = 'Unable to check registered phone'; });
            document.querySelectorAll('[data-checkin-phone-meta]').forEach(node => { node.textContent = error.message; });
          }
          return null;
        }
      };
      const hasVisibleCheckinQr = () => [...document.querySelectorAll('[data-checkin-flow-form]')].some(form =>
        form.closest('.ho-checkin-flow-modal')?.classList.contains('open')
        && form.querySelector('[data-checkin-payment-method]')?.value === 'qr_code'
      ) || [...document.querySelectorAll('[data-checkout-form]')].some(form =>
        form.closest('.ho-checkout-modal')?.classList.contains('open')
        && form.querySelector('[data-checkout-payment-method]')?.value === 'qr_code'
      );
      const stopCheckinPhoneDisplayPolling = () => {
        if (checkinPhoneState.displayTimer) window.clearInterval(checkinPhoneState.displayTimer);
        checkinPhoneState.displayTimer = null;
      };
      const startCheckinPhoneDisplayPolling = () => {
        stopCheckinPhoneDisplayPolling();
        refreshCheckinPhone();
        checkinPhoneState.displayTimer = window.setInterval(() => {
          if (!hasVisibleCheckinQr()) return stopCheckinPhoneDisplayPolling();
          refreshCheckinPhone(true);
        }, 2500);
      };
      const buildCheckinPhoneSetupUrl = () => {
        if (checkinPublicAppUrl) {
          const base = new URL(checkinPublicAppUrl);
          base.pathname = base.pathname.endsWith('/') ? base.pathname : `${base.pathname}/`;
          base.search = '';
          base.hash = '';
          return new URL(checkinPhoneSetupPage, base).href;
        }
        return new URL(checkinPhoneSetupPage, window.location.href).href;
      };
      const setCheckinPhoneRegistrationStatus = (type, title, detail) => {
        const status = document.getElementById('hoCheckinPhoneRegistration');
        status?.classList.toggle('is-success', type === 'success');
        status?.classList.toggle('is-error', type === 'error');
        if (status) {
          status.querySelector('strong').textContent = title;
          status.querySelector('small').textContent = detail;
        }
      };
      const checkCheckinPhoneRegistration = async (manual = false) => {
        try {
          const data = await fetchCheckinPhone();
          const device = data.registered ? data.device : null;
          const baseline = checkinPhoneState.baseline;
          const changed = device && (!baseline
            || Number(device.device_id) !== Number(baseline.device_id)
            || String(device.last_used_at || '') !== String(baseline.last_used_at || ''));
          renderCheckinPhone(data);
          if (changed) {
            if (checkinPhoneState.registrationTimer) window.clearInterval(checkinPhoneState.registrationTimer);
            checkinPhoneState.registrationTimer = null;
            setCheckinPhoneRegistrationStatus('success', 'Hotel phone registered', `${device.device_name || 'Hotel phone'} is ready for PayMongo notifications.`);
          } else if (manual) {
            setCheckinPhoneRegistrationStatus('', 'No new phone detected', `Checked ${new Date().toLocaleTimeString()}. Finish registration on the phone, then check again.`);
          }
        } catch (error) {
          setCheckinPhoneRegistrationStatus('error', 'Could not check registration', error.message);
        }
      };
      const checkinPhoneSetupModal = document.getElementById('hoCheckinPhoneSetupModal');
      const openCheckinPhoneSetup = async () => {
        const latest = await refreshCheckinPhone(true);
        checkinPhoneState.baseline = latest?.registered && latest.device ? { ...latest.device } : null;
        const urlInput = document.getElementById('hoCheckinPhoneSetupUrl');
        if (urlInput) urlInput.value = buildCheckinPhoneSetupUrl();
        const title = document.getElementById('hoCheckinPhoneSetupTitle');
        if (title) title.textContent = checkinPhoneState.baseline ? 'Change Registered Payment Phone' : 'Register Payment Phone';
        setCheckinPhoneRegistrationStatus('', 'Waiting for phone registration', 'Keep this window open while registering the phone.');
        checkinPhoneSetupModal?.classList.add('open');
        checkinPhoneSetupModal?.setAttribute('aria-hidden', 'false');
        if (checkinPhoneState.registrationTimer) window.clearInterval(checkinPhoneState.registrationTimer);
        checkinPhoneState.registrationTimer = window.setInterval(checkCheckinPhoneRegistration, 2500);
      };
      const closeCheckinPhoneSetup = () => {
        if (checkinPhoneState.registrationTimer) window.clearInterval(checkinPhoneState.registrationTimer);
        checkinPhoneState.registrationTimer = null;
        checkinPhoneSetupModal?.classList.remove('open');
        checkinPhoneSetupModal?.setAttribute('aria-hidden', 'true');
        refreshCheckinPhone(true);
      };
      document.querySelectorAll('[data-change-checkin-phone]').forEach(button => button.addEventListener('click', openCheckinPhoneSetup));
      document.querySelectorAll('[data-close-checkin-phone]').forEach(button => button.addEventListener('click', closeCheckinPhoneSetup));
      document.getElementById('hoCheckinPhoneCheckAgain')?.addEventListener('click', () => checkCheckinPhoneRegistration(true));
      document.getElementById('hoOpenCheckinPhoneSetup')?.addEventListener('click', () => window.open(document.getElementById('hoCheckinPhoneSetupUrl')?.value || buildCheckinPhoneSetupUrl(), '_blank', 'noopener'));
      document.getElementById('hoCopyCheckinPhoneUrl')?.addEventListener('click', async event => {
        const input = document.getElementById('hoCheckinPhoneSetupUrl');
        try {
          await navigator.clipboard.writeText(input.value);
          event.currentTarget.textContent = 'Copied';
          window.setTimeout(() => { event.currentTarget.textContent = 'Copy Link'; }, 1500);
        } catch (_) {
          input.select();
          document.execCommand('copy');
        }
      });
      checkinPhoneSetupModal?.addEventListener('mousedown', event => { if (event.target === checkinPhoneSetupModal) closeCheckinPhoneSetup(); });
      window.addEventListener('focus', () => { if (hasVisibleCheckinQr()) refreshCheckinPhone(true); });
      document.addEventListener('visibilitychange', () => { if (!document.hidden && hasVisibleCheckinQr()) refreshCheckinPhone(true); });
      const cancelCheckinQrPayment = async (bookingId, returnToken) => {
        const body = new FormData();
        body.append('action', 'cancel_pending');
        body.append('type', 'hotel');
        body.append('context', 'hotel_checkin');
        body.append('id', String(bookingId));
        body.append('return_token', String(returnToken));
        body.append('csrf_token', checkinPayMongoCsrf);
        const response = await fetch(checkinPayMongoEndpoint, { method: 'POST', body, headers: { Accept: 'application/json' } });
        const data = await readCheckinPaymentJson(response);
        if (!response.ok || !data.success) throw new Error(data.message || 'The pending QR payment could not be cancelled.');
        return Boolean(data.cancelled);
      };
      const waitForCheckinQrPayment = async (token, bookingId) => {
        let cancelRequested = false;
        Swal.fire({
          title: 'QR Sent to Registered Phone',
          text: 'Waiting for the tourist to pay. Check-in will continue only after PayMongo verifies the QR payment.',
          allowOutsideClick: false,
          allowEscapeKey: false,
          showCancelButton: true,
          showConfirmButton: false,
          cancelButtonText: 'Cancel Payment',
          cancelButtonColor: '#b5444f',
          didOpen: () => Swal.showLoading()
        }).then(result => {
          if (result.dismiss === Swal.DismissReason.cancel) cancelRequested = true;
        });
        for (let attempt = 0; attempt < 120; attempt += 1) {
          if (cancelRequested) {
            const cancelled = await cancelCheckinQrPayment(bookingId, token);
            await Swal.fire(
              cancelled ? 'Payment Cancelled' : 'Nothing to Cancel',
              cancelled ? 'No amount was applied. The guest has not been checked in.' : 'The payment may already be processing. Check its latest status before trying again.',
              cancelled ? 'info' : 'warning'
            );
            return false;
          }
          try {
            const response = await fetch(`${checkinPayMongoStatusEndpoint}&token=${encodeURIComponent(token)}`, {
              cache: 'no-store', headers: { Accept: 'application/json' }
            });
            const data = await readCheckinPaymentJson(response);
            if (response.ok && data.success && data.status === 'paid') {
              Swal.fire({
                title: 'Payment Verified',
                text: 'PayMongo verified the QR payment. Completing guest check-in…',
                allowOutsideClick: false,
                allowEscapeKey: false,
                showConfirmButton: false,
                didOpen: () => Swal.showLoading()
              });
              return true;
            }
            if (response.ok && data.success && data.status === 'cancelled') {
              await Swal.fire('Payment Cancelled', 'No amount was applied and the guest was not checked in. You can try again from Step 3.', 'info');
              return false;
            }
            if (response.ok && data.success && ['failed', 'expired'].includes(data.status)) {
              await Swal.fire('Payment Failed', 'PayMongo did not confirm the payment. The guest was not checked in. You can try again from Step 3.', 'error');
              return false;
            }
          } catch (_) {}
          await new Promise(resolve => window.setTimeout(resolve, 2500));
        }
        await Swal.fire('Verification Pending', 'PayMongo confirmation is taking longer than expected. The guest has not been checked in yet.', 'info');
        return false;
      };
      const startCheckinQrPayment = async form => {
        const bookingId = Number(form.querySelector('[data-checkin-booking-id]')?.value || 0);
        const amount = Number(form.querySelector('[data-checkin-payment]')?.value || 0);
        if (!bookingId || amount <= 0) throw new Error('The booking balance is not valid for QR payment.');
        Swal.fire({
          title: 'Sending QR to Registered Phone',
          text: 'Preparing the secure PayMongo QR. Please wait…',
          allowOutsideClick: false,
          allowEscapeKey: false,
          showConfirmButton: false,
          didOpen: () => Swal.showLoading()
        });
        const body = new FormData();
        body.append('type', 'hotel');
        body.append('context', 'hotel_checkin');
        body.append('id', String(bookingId));
        body.append('amount', amount.toFixed(2));
        body.append('csrf_token', checkinPayMongoCsrf);
        const response = await fetch(checkinPayMongoEndpoint, { method: 'POST', body, headers: { Accept: 'application/json' } });
        const data = await readCheckinPaymentJson(response);
        if (!response.ok || !data.success) throw new Error(data.message || 'The PayMongo QR could not be prepared.');
        if (!data.phone_notification?.sent) throw new Error(data.phone_notification?.message || 'The QR could not be sent to the registered phone.');
        const token = String(data.return_token || '');
        if (!/^[a-f0-9]{64}$/.test(token)) throw new Error('PayMongo returned an invalid payment token.');
        return (await waitForCheckinQrPayment(token, bookingId)) ? token : '';
      };
      const cancelCheckoutQrPayment = async (bookingId, returnToken) => {
        const body = new FormData();
        body.append('action', 'cancel_pending');
        body.append('type', 'hotel');
        body.append('context', 'hotel_checkout');
        body.append('id', String(bookingId));
        body.append('return_token', String(returnToken));
        body.append('csrf_token', checkinPayMongoCsrf);
        const response = await fetch(checkinPayMongoEndpoint, { method: 'POST', body, headers: { Accept: 'application/json' } });
        const data = await readCheckinPaymentJson(response);
        if (!response.ok || !data.success) throw new Error(data.message || 'The pending checkout payment could not be cancelled.');
        return Boolean(data.cancelled);
      };
      const waitForCheckoutQrPayment = async (token, bookingId) => {
        let cancelRequested = false;
        Swal.fire({
          title: 'QR Sent to Registered Phone',
          text: 'Waiting for the tourist to pay. Check-out will continue only after PayMongo verifies the payment.',
          allowOutsideClick: false,
          allowEscapeKey: false,
          showCancelButton: true,
          showConfirmButton: false,
          cancelButtonText: 'Cancel Payment',
          cancelButtonColor: '#b5444f',
          didOpen: () => Swal.showLoading()
        }).then(result => {
          if (result.dismiss === Swal.DismissReason.cancel) cancelRequested = true;
        });
        for (let attempt = 0; attempt < 120; attempt += 1) {
          if (cancelRequested) {
            const cancelled = await cancelCheckoutQrPayment(bookingId, token);
            await Swal.fire(
              cancelled ? 'Payment Cancelled' : 'Nothing to Cancel',
              cancelled ? 'No checkout payment was applied. The guest remains checked in.' : 'The payment may already be processing. Check its latest status before trying again.',
              cancelled ? 'info' : 'warning'
            );
            return false;
          }
          try {
            const response = await fetch(`${checkinPayMongoStatusEndpoint}&token=${encodeURIComponent(token)}`, {
              cache: 'no-store', headers: { Accept: 'application/json' }
            });
            const data = await readCheckinPaymentJson(response);
            if (response.ok && data.success && data.status === 'paid') {
              Swal.fire({
                title: 'Payment Verified',
                text: 'PayMongo verified the checkout payment. Completing guest check-out…',
                allowOutsideClick: false,
                allowEscapeKey: false,
                showConfirmButton: false,
                didOpen: () => Swal.showLoading()
              });
              return true;
            }
            if (response.ok && data.success && data.status === 'cancelled') {
              await Swal.fire('Payment Cancelled', 'No amount was applied and the guest remains checked in. You can try again in this modal.', 'info');
              return false;
            }
            if (response.ok && data.success && ['failed', 'expired'].includes(data.status)) {
              await Swal.fire('Payment Failed', 'PayMongo did not confirm the payment. The guest remains checked in and you can try again.', 'error');
              return false;
            }
          } catch (_) {}
          await new Promise(resolve => window.setTimeout(resolve, 2500));
        }
        await Swal.fire('Verification Pending', 'PayMongo confirmation is taking longer than expected. The guest remains checked in.', 'info');
        return false;
      };
      const startCheckoutQrPayment = async form => {
        const bookingId = Number(form.querySelector('input[name="booking_id"]')?.value || 0);
        const amount = Number(form.querySelector('input[name="checkout_final_payment"]')?.value || 0);
        const additionalCharges = Number(form.querySelector('input[name="checkout_additional_charges"]')?.value || 0);
        if (!bookingId || amount <= 0) throw new Error('The checkout balance is not valid for QR payment.');
        Swal.fire({
          title: 'Sending QR to Registered Phone',
          text: 'Preparing the secure PayMongo checkout QR. Please wait…',
          allowOutsideClick: false,
          allowEscapeKey: false,
          showConfirmButton: false,
          didOpen: () => Swal.showLoading()
        });
        const body = new FormData();
        body.append('type', 'hotel');
        body.append('context', 'hotel_checkout');
        body.append('id', String(bookingId));
        body.append('amount', amount.toFixed(2));
        body.append('additional_charges', Math.max(0, additionalCharges).toFixed(2));
        body.append('csrf_token', checkinPayMongoCsrf);
        const response = await fetch(checkinPayMongoEndpoint, { method: 'POST', body, headers: { Accept: 'application/json' } });
        const data = await readCheckinPaymentJson(response);
        if (!response.ok || !data.success) throw new Error(data.message || 'The checkout QR could not be prepared.');
        if (!data.phone_notification?.sent) throw new Error(data.phone_notification?.message || 'The QR could not be sent to the registered phone.');
        const token = String(data.return_token || '');
        if (!/^[a-f0-9]{64}$/.test(token)) throw new Error('PayMongo returned an invalid payment token.');
        return (await waitForCheckoutQrPayment(token, bookingId)) ? token : '';
      };
      const setCheckinStep = (form, step) => {
        const nextStep = Math.min(3, Math.max(1, Number(step) || 1));
        form.dataset.step = String(nextStep);
        form.querySelectorAll('[data-checkin-panel]').forEach(panel => {
          const isActive = Number(panel.getAttribute('data-checkin-panel')) === nextStep;
          panel.classList.toggle('active', isActive);
          panel.hidden = !isActive;
        });
        const modal = form.closest('.ho-checkin-flow-modal');
        modal?.querySelectorAll('[data-checkin-step-indicator]').forEach(indicator => {
          const indicatorStep = Number(indicator.getAttribute('data-checkin-step-indicator'));
          indicator.classList.toggle('active', indicatorStep === nextStep);
          indicator.classList.toggle('complete', indicatorStep < nextStep);
        });
        form.querySelector(`[data-checkin-panel="${nextStep}"]`)?.scrollTo({ top: 0, behavior: 'smooth' });
      };

      const populateCheckinBooking = (form, choice) => {
        const data = choice.dataset;
        const bookingIdInput = form.querySelector('[data-checkin-booking-id]');
        const payMongoTokenInput = form.querySelector('[data-checkin-paymongo-token]');
        const isDifferentBooking = bookingIdInput?.value && bookingIdInput.value !== (data.bookingId || '');
        const guestNameInput = form.querySelector('[data-checkin-guest-name]');
        const guestCountInput = form.querySelector('[data-checkin-guest-count]');
        const paymentInput = form.querySelector('[data-checkin-payment]');
        const paymentMethod = form.querySelector('[data-checkin-payment-method]');
        const firstNext = form.querySelector('[data-checkin-panel="1"] [data-checkin-next]');
        if (isDifferentBooking) {
          const representativeInput = form.querySelector('input[name="checkin_representative_name"]');
          const idTypeInput = form.querySelector('select[name="checkin_id_type"]');
          const idReferenceInput = form.querySelector('input[name="checkin_id_reference"]');
          if (representativeInput) representativeInput.value = '';
          if (idTypeInput) idTypeInput.value = '';
          if (idReferenceInput) idReferenceInput.value = '';
          if (payMongoTokenInput) payMongoTokenInput.value = '';
        }
        if (bookingIdInput) bookingIdInput.value = data.bookingId || '';
        if (guestNameInput) guestNameInput.value = data.guest || '';
        if (guestCountInput) guestCountInput.value = data.guests || '1';
        if (paymentInput) paymentInput.value = Number(data.balance || 0).toFixed(2);
        if (paymentMethod) {
          const hasBalance = Number(data.balance || 0) > 0;
          paymentMethod.disabled = !hasBalance;
          paymentMethod.value = '';
        }
        form.querySelectorAll('[data-checkin-qr-notice]').forEach(notice => { notice.hidden = true; });
        if (firstNext) firstNext.disabled = false;

        const values = {
          guest: data.guest || '—',
          reference: data.reference || '—',
          email: data.email || 'Not provided',
          phone: data.phone || 'Not provided',
          checkin: data.checkin || '—',
          checkout: data.checkout || '—',
          guests: `${data.guests || 1} (${data.adults || 0}A / ${data.children || 0}C)`
        };
        Object.entries(values).forEach(([key, value]) => {
          form.querySelectorAll(`[data-checkin-bind="${key}"]`).forEach(node => {
            node.textContent = value;
          });
        });
        ['total', 'paid', 'balance'].forEach(key => {
          form.querySelectorAll(`[data-checkin-money="${key}"]`).forEach(node => {
            node.textContent = formatMoney(data[key] || 0);
          });
        });

        const note = form.querySelector('[data-checkin-payment-note]');
        if (note) {
          const hasBalance = Number(data.balance || 0) > 0;
          note.textContent = hasBalance
            ? 'Collect the full remaining balance shown above to complete check-in.'
            : 'This booking is fully paid. No payment is required at check-in.';
          note.classList.toggle('paid', !hasBalance);
        }
      };

      function resetCheckinFlow(modal) {
        const form = modal?.querySelector('[data-checkin-flow-form]');
        if (!form) return;
        form.reset();
        form.dataset.confirmed = '0';
        const bookingIdInput = form.querySelector('[data-checkin-booking-id]');
        const payMongoTokenInput = form.querySelector('[data-checkin-paymongo-token]');
        const firstNext = form.querySelector('[data-checkin-panel="1"] [data-checkin-next]');
        if (bookingIdInput) bookingIdInput.value = '';
        if (payMongoTokenInput) payMongoTokenInput.value = '';
        if (firstNext) firstNext.disabled = true;
        form.querySelectorAll('[data-checkin-bind]').forEach(node => {
          const key = node.getAttribute('data-checkin-bind');
          node.textContent = key === 'email' || key === 'phone' ? 'Not provided' : '—';
        });
        form.querySelectorAll('[data-checkin-money]').forEach(node => {
          node.textContent = formatMoney(0);
        });
        form.querySelectorAll('[data-checkin-id-summary]').forEach(node => {
          node.textContent = '—';
        });
        form.querySelectorAll('[data-checkin-payment-method]').forEach(select => {
          select.value = '';
          select.disabled = true;
        });
        form.querySelectorAll('[data-checkin-qr-notice]').forEach(notice => { notice.hidden = true; });
        const submit = form.querySelector('[data-checkin-panel="3"] button[type="submit"]');
        if (submit) submit.textContent = 'Confirm check-in';
        setCheckinStep(form, 1);
      }

      document.querySelectorAll('[data-checkin-flow-form]').forEach(form => {
        const paymentMethod = form.querySelector('[data-checkin-payment-method]');
        paymentMethod?.addEventListener('change', () => {
          const isQr = paymentMethod.value === 'qr_code';
          form.querySelectorAll('[data-checkin-qr-notice]').forEach(notice => { notice.hidden = !isQr; });
          const submit = form.querySelector('[data-checkin-panel="3"] button[type="submit"]');
          if (submit) submit.textContent = isQr ? 'Send QR & confirm check-in' : 'Confirm check-in';
          if (isQr) startCheckinPhoneDisplayPolling();
          else if (!hasVisibleCheckinQr()) stopCheckinPhoneDisplayPolling();
        });
        form.querySelectorAll('[data-checkin-booking-choice]').forEach(choice => {
          choice.addEventListener('change', () => {
            if (choice.checked) populateCheckinBooking(form, choice);
          });
        });

        form.querySelectorAll('[data-checkin-next]').forEach(button => {
          button.addEventListener('click', () => {
            const step = Number(form.dataset.step || 1);
            if (step === 1) {
              const selected = form.querySelector('[data-checkin-booking-choice]:checked');
              if (!selected) return;
              populateCheckinBooking(form, selected);
            }
            if (step === 2) {
              const requiredFields = [...form.querySelectorAll('[data-checkin-panel="2"] input[required], [data-checkin-panel="2"] select[required]')];
              const invalid = requiredFields.find(field => !field.checkValidity());
              if (invalid) {
                invalid.reportValidity();
                return;
              }
              const idType = form.querySelector('select[name="checkin_id_type"]');
              const idReference = form.querySelector('input[name="checkin_id_reference"]');
              const idSummary = form.querySelector('[data-checkin-id-summary]');
              if (idSummary) {
                const idTypeLabel = idType?.selectedOptions?.[0]?.textContent?.trim() || 'ID';
                idSummary.textContent = `${idTypeLabel} · ${idReference?.value.trim() || '—'}`;
              }
            }
            setCheckinStep(form, step + 1);
          });
        });

        form.querySelectorAll('[data-checkin-prev]').forEach(button => {
          button.addEventListener('click', () => setCheckinStep(form, Number(form.dataset.step || 1) - 1));
        });
        form.querySelectorAll('[data-checkin-go-step]').forEach(button => {
          button.addEventListener('click', () => setCheckinStep(form, Number(button.getAttribute('data-checkin-go-step')) || 1));
        });
      });

      <?php if ($requestedCheckinBookingId > 0): ?>
      const requestedCheckinChoice = [...document.querySelectorAll('[data-checkin-booking-choice]')]
        .find(choice => Number(choice.dataset.bookingId || 0) === <?= (int)$requestedCheckinBookingId ?> && !choice.disabled);
      if (requestedCheckinChoice) {
        const requestedCheckinModal = requestedCheckinChoice.closest('.ho-checkin-flow-modal');
        const requestedCheckinForm = requestedCheckinChoice.closest('[data-checkin-flow-form]');
        if (requestedCheckinModal && requestedCheckinForm) {
          resetCheckinFlow(requestedCheckinModal);
          requestedCheckinChoice.checked = true;
          populateCheckinBooking(requestedCheckinForm, requestedCheckinChoice);
          requestedCheckinModal.classList.add('open');
          requestedCheckinModal.setAttribute('aria-hidden', 'false');
        }
      }
      <?php endif; ?>

      const parseLocalDateTime = (value) => {
        if (!value) return null;
        const normalized = value.includes('T') ? value : value.replace(' ', 'T');
        const dt = new Date(normalized);
        return Number.isNaN(dt.getTime()) ? null : dt;
      };
      const recalcCheckoutForm = (form) => {
        const checkedInRaw = form.getAttribute('data-checked-in-at') || '';
        const checkedInDate = parseLocalDateTime(checkedInRaw);
        const checkoutInput = form.querySelector('input[name="checkout_date_time"]');
        const nightsInput = form.querySelector('input[name="checkout_total_nights_display"]');
        const additionalInput = form.querySelector('input[name="checkout_additional_charges"]');
        const finalPaymentInput = form.querySelector('input[name="checkout_final_payment"]');
        const paymentMethod = form.querySelector('[data-checkout-payment-method]');
        const payMongoTokenInput = form.querySelector('[data-checkout-paymongo-token]');
        const dueLabel = form.querySelector('[data-checkout-due-value]');
        const additionalLabel = form.querySelector('[data-checkout-additional-value]');
        const baseRemaining = Number(form.getAttribute('data-base-remaining') || 0);
        const checkoutDate = checkoutInput ? parseLocalDateTime(checkoutInput.value) : null;

        let nights = 1;
        if (checkedInDate && checkoutDate) {
          const diff = checkoutDate.getTime() - checkedInDate.getTime();
          nights = diff > 0 ? Math.max(1, Math.ceil(diff / 86400000)) : 1;
        }
        if (nightsInput) {
          nightsInput.value = String(nights);
        }

        const additional = Math.max(0, Number(additionalInput?.value || 0));
        const due = Math.max(0, baseRemaining + additional);
        const isQr = paymentMethod?.value === 'qr_code' && due > 0;
        if (additionalLabel) {
          additionalLabel.textContent = formatMoney(additional);
        }
        if (dueLabel) {
          dueLabel.textContent = formatMoney(due);
        }
        if (finalPaymentInput) {
          finalPaymentInput.min = due.toFixed(2);
          finalPaymentInput.readOnly = isQr;
          if (isQr || !finalPaymentInput.value || Number(finalPaymentInput.value) < due) {
            finalPaymentInput.value = due.toFixed(2);
          }
        }
        if (paymentMethod) {
          paymentMethod.disabled = due <= 0;
          if (due <= 0) paymentMethod.value = '';
        }
        if (payMongoTokenInput) payMongoTokenInput.value = '';
        form.querySelectorAll('[data-checkout-qr-notice]').forEach(notice => { notice.hidden = !isQr; });
        const submit = form.querySelector('.ho-checkout-submit span:first-child');
        if (submit) submit.textContent = isQr ? 'Send QR & complete check-out' : 'Complete check-out';
      };

      document.querySelectorAll('[data-checkout-form]').forEach(form => {
        const checkoutInput = form.querySelector('input[name="checkout_date_time"]');
        const additionalInput = form.querySelector('input[name="checkout_additional_charges"]');
        const paymentMethod = form.querySelector('[data-checkout-payment-method]');
        if (checkoutInput) {
          checkoutInput.addEventListener('change', () => recalcCheckoutForm(form));
          checkoutInput.addEventListener('input', () => recalcCheckoutForm(form));
        }
        if (additionalInput) {
          additionalInput.addEventListener('change', () => recalcCheckoutForm(form));
          additionalInput.addEventListener('input', () => recalcCheckoutForm(form));
        }
        paymentMethod?.addEventListener('change', () => {
          recalcCheckoutForm(form);
          if (paymentMethod.value === 'qr_code') startCheckinPhoneDisplayPolling();
          else if (!hasVisibleCheckinQr()) stopCheckinPhoneDisplayPolling();
        });
        recalcCheckoutForm(form);
      });

      document.querySelectorAll('[data-checkin-form]').forEach(form => {
        form.addEventListener('submit', async (e) => {
          if (form.dataset.confirmed === '1') {
            return;
          }
          if (form.matches('[data-checkin-flow-form]') && Number(form.dataset.step || 1) < 3) {
            e.preventDefault();
            form.querySelector(`[data-checkin-panel="${form.dataset.step || 1}"] [data-checkin-next]`)?.click();
            return;
          }
          e.preventDefault();
          const submitConfirmedCheckin = async () => {
            const paymentMethod = form.querySelector('[data-checkin-payment-method]')?.value || '';
            const paymentAmount = Number(form.querySelector('[data-checkin-payment]')?.value || 0);
            const tokenInput = form.querySelector('[data-checkin-paymongo-token]');
            if (paymentMethod === 'qr_code' && paymentAmount > 0 && !tokenInput?.value) {
              if (!window.Swal) {
                window.alert('PayMongo QR payment requires the payment dialog. Refresh the page and try again.');
                return;
              }
              try {
                const token = await startCheckinQrPayment(form);
                if (!token) return;
                if (tokenInput) tokenInput.value = token;
                const paymentInput = form.querySelector('[data-checkin-payment]');
                if (paymentInput) paymentInput.value = '0.00';
              } catch (error) {
                await Swal.fire('QR Payment Failed', error.message || 'The PayMongo QR payment could not be completed.', 'error');
                return;
              }
            }
            form.dataset.confirmed = '1';
            form.submit();
          };
          await submitConfirmedCheckin();
        });
      });
      document.querySelectorAll('[data-checkout-form]').forEach(form => {
        form.addEventListener('submit', async (e) => {
          if (form.dataset.confirmed === '1') {
            return;
          }
          e.preventDefault();
          const guest = form.querySelector('input[name="checkout_guest_name"]')?.value.trim() || 'Guest';
          const reference = form.dataset.bookingReference || 'Selected booking';
          const amountDue = form.querySelector('[data-checkout-due-value]')?.textContent.trim() || formatMoney(0);
          const paymentMethod = form.querySelector('[data-checkout-payment-method]')?.value || '';
          const paymentAmount = Number(form.querySelector('input[name="checkout_final_payment"]')?.value || 0);
          const tokenInput = form.querySelector('[data-checkout-paymongo-token]');
          if (paymentMethod === 'qr_code' && paymentAmount > 0 && !tokenInput?.value) {
            if (!window.Swal) {
              window.alert('PayMongo QR payment requires the payment dialog. Refresh the page and try again.');
              return;
            }
            try {
              const token = await startCheckoutQrPayment(form);
              if (!token) return;
              if (tokenInput) tokenInput.value = token;
              form.dataset.confirmed = '1';
              form.submit();
            } catch (error) {
              await Swal.fire('QR Payment Failed', error.message || 'The PayMongo checkout payment could not be completed.', 'error');
            }
            return;
          }
          if (!window.Swal) {
            const ok = window.confirm(`Complete check-out for ${guest} (${reference}) with ${amountDue} due?`);
            if (ok) {
              form.dataset.confirmed = '1';
              form.submit();
            }
            return;
          }
          const result = await Swal.fire({
            icon: 'question',
            title: 'Complete guest check-out?',
            text: `${guest} - ${reference} - ${amountDue} due. The room will return to Available.`,
            showCancelButton: true,
            confirmButtonText: 'Complete check-out',
            cancelButtonText: 'Return to review',
            confirmButtonColor: '#2b7a66',
            cancelButtonColor: '#6c757d'
          });
          if (result.isConfirmed) {
            form.dataset.confirmed = '1';
            form.submit();
          }
        });
      });
      document.querySelectorAll('[data-archive-form]').forEach(form => {
        form.addEventListener('submit', async (e) => {
          if (form.dataset.confirmed === '1') {
            return;
          }
          e.preventDefault();
          if (!window.Swal) {
            const ok = window.confirm('Archive this room?');
            if (ok) {
              form.dataset.confirmed = '1';
              form.submit();
            }
            return;
          }
          const result = await Swal.fire({
            icon: 'warning',
            title: 'Archive this room?',
            text: 'You can still view it under Archived rooms.',
            showCancelButton: true,
            confirmButtonText: 'Yes, archive',
            cancelButtonText: 'Cancel',
            confirmButtonColor: '#2b7a66',
            cancelButtonColor: '#6c757d'
          });
          if (result.isConfirmed) {
            form.dataset.confirmed = '1';
            form.submit();
          }
        });
      });

      if (errorMessage) {
        if (window.Swal) {
          Swal.fire({
            icon: 'error',
            title: 'Action failed',
            text: errorMessage,
            confirmButtonColor: '#2b7a66'
          });
        } else {
          alert(errorMessage);
        }
      } else if (flashMessage) {
        if (window.Swal) {
          Swal.fire({
            icon: 'success',
            title: flashTitle,
            text: flashMessage,
            confirmButtonColor: '#2b7a66'
          });
        } else {
          alert(flashMessage);
        }
      }
    })();
  </script>
</body>
</html>
