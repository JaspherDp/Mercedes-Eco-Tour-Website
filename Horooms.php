<?php
require_once __DIR__ . '/Ho_common.php';

$hoAdmin = HoRequireHotelAdmin($pdo);
$hoHotelResortId = (int)$hoAdmin['hotel_resort_id'];
$hoPropertyName = trim((string)($hoAdmin['property_name'] ?? ''));
HoEnsureHotelRoomsTable($pdo);
HoEnsureHotelDefaultRooms($pdo, $hoHotelResortId);
HoEnsureHotelBookingsTable($pdo);

$hoActive = 'rooms';
$hoTitle = 'Room Management';
$hoOwnerName = $hoPropertyName !== '' ? $hoPropertyName . ' Admin' : (string)$hoAdmin['username'];
$hoUnreadBadge = HoGetUnreadCount($pdo, $hoHotelResortId);
$hoNotifItems = HoGetNotificationItems($pdo, 8, $hoHotelResortId);
$hoPendingBadge = HoGetPendingCount($pdo, $hoHotelResortId);
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

if (isset($_POST['ho_action']) && $_POST['ho_action'] === 'mark_notifications_read') {
    HoMarkNotificationsRead($pdo, $hoHotelResortId);
    header('Content-Type: application/json');
    echo json_encode(['ok' => true]);
    exit;
}

function HoEnsureRoomUploadDirectory(): array
{
    $relativeDir = 'uploads/hotel_rooms';
    $absoluteDir = __DIR__ . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'hotel_rooms';
    if (!is_dir($absoluteDir)) {
        mkdir($absoluteDir, 0777, true);
    }
    return [$absoluteDir, $relativeDir];
}

function HoSaveUploadedRoomImage(?array $file, string $absoluteDir, string $relativeDir): ?string
{
    if (!$file || !isset($file['error']) || (int)$file['error'] !== UPLOAD_ERR_OK) {
        return null;
    }
    if (empty($file['tmp_name']) || !is_uploaded_file((string)$file['tmp_name'])) {
        return null;
    }

    $mime = (string)(mime_content_type((string)$file['tmp_name']) ?: '');
    $allowed = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
    ];
    if (!isset($allowed[$mime])) {
        return null;
    }

    $ext = $allowed[$mime];
    $filename = 'room_' . date('YmdHis') . '_' . bin2hex(random_bytes(5)) . '.' . $ext;
    $targetAbs = $absoluteDir . DIRECTORY_SEPARATOR . $filename;
    if (!move_uploaded_file((string)$file['tmp_name'], $targetAbs)) {
        return null;
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
            $saved[$i] = $path;
        }
    }
    return $saved;
}

function HoParseLocalDateTime(?string $value): ?string
{
    $value = trim((string)$value);
    if ($value === '') {
        return null;
    }

    foreach (['Y-m-d\TH:i', 'Y-m-d H:i:s', 'Y-m-d H:i'] as $format) {
        $dt = DateTime::createFromFormat($format, $value);
        if ($dt instanceof DateTime) {
            return $dt->format('Y-m-d H:i:s');
        }
    }

    $ts = strtotime($value);
    if ($ts === false) {
        return null;
    }
    return date('Y-m-d H:i:s', $ts);
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

$flash = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['room_action'])) {
    [$roomUploadAbsDir, $roomUploadRelDir] = HoEnsureRoomUploadDirectory();
    $action = trim((string)$_POST['room_action']);
    $roomId = (int)($_POST['room_id'] ?? 0);
    $roomName = trim((string)($_POST['room_name'] ?? ''));
    $roomDescription = trim((string)($_POST['description'] ?? ''));
    $price = (float)($_POST['price'] ?? 0);
    $capacityAdults = max(1, (int)($_POST['capacity_adults'] ?? 1));
    $capacityChildren = max(0, (int)($_POST['capacity_children'] ?? 0));
    $availableUnits = 1;
    $breakfastFor = max(0, (int)($_POST['breakfast_for'] ?? 0));
    $bookingId = (int)($_POST['booking_id'] ?? 0);
    $checkinGuestName = trim((string)($_POST['checkin_guest_name'] ?? ''));
    $checkinRepresentativeName = trim((string)($_POST['checkin_representative_name'] ?? ''));
    $checkinGuestCount = max(0, (int)($_POST['checkin_guest_count'] ?? 0));
    $checkinDateTime = HoParseLocalDateTime($_POST['checkin_date_time'] ?? null);
    $checkinIdReference = trim((string)($_POST['checkin_id_reference'] ?? ''));
    $checkinRemainingPayment = max(0, (float)($_POST['checkin_remaining_payment'] ?? 0));
    $checkoutGuestName = trim((string)($_POST['checkout_guest_name'] ?? ''));
    $checkoutRepresentativeName = trim((string)($_POST['checkout_representative_name'] ?? ''));
    $checkoutDateTime = HoParseLocalDateTime($_POST['checkout_date_time'] ?? null);
    $checkoutAdditionalCharges = max(0, (float)($_POST['checkout_additional_charges'] ?? 0));
    $checkoutFinalPayment = max(0, (float)($_POST['checkout_final_payment'] ?? 0));
    $metaJsonRaw = trim((string)($_POST['meta_json'] ?? ''));
    $inclusionsRaw = trim((string)($_POST['inclusions_text'] ?? ''));
    $mainImagePath = trim((string)($_POST['main_image_path'] ?? ''));
    $galleryRaw = trim((string)($_POST['gallery_images_text'] ?? ''));
    $galleryPathsPosted = isset($_POST['gallery_paths']) && is_array($_POST['gallery_paths']) ? $_POST['gallery_paths'] : [];
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

    $uploadedGalleryRows = HoSaveUploadedRoomImagesIndexed($_FILES['gallery_row_files'] ?? null, $roomUploadAbsDir, $roomUploadRelDir);
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

    $uploadedMainImage = HoSaveUploadedRoomImage($_FILES['main_image_file'] ?? null, $roomUploadAbsDir, $roomUploadRelDir);
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

    if ($roomName === '' && !in_array($action, ['archive', 'checkin', 'checkout'], true)) {
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
                    $bookingStatus = strtolower((string)($booking['booking_status'] ?? 'pending'));
                    $bookingRoomId = (int)($booking['hotel_room_id'] ?? 0);
                    $bookingRoomType = trim((string)($booking['room_type'] ?? ''));
                    $roomMatch = $bookingRoomId > 0
                        ? $bookingRoomId === $roomId
                        : strcasecmp($bookingRoomType, (string)$room['room_name']) === 0;
                    if (!$roomMatch) {
                        $error = 'Selected booking does not belong to this room.';
                    } elseif (!in_array($bookingStatus, ['pending', 'confirmed'], true)) {
                        $error = 'Booking cannot be checked in from its current status.';
                    } elseif (!empty($booking['checked_in_at']) && empty($booking['checked_out_at'])) {
                        $error = 'Guest is already checked in for this booking.';
                    } elseif ($checkinGuestName === '') {
                        $error = 'Guest name is required for check-in.';
                    } elseif ($checkinGuestCount < 1) {
                        $error = 'Number of guests is required for check-in.';
                    } elseif ($checkinDateTime === null) {
                        $error = 'Check-in date and time is required.';
                    } else {
                        $remaining = max(0, (float)($booking['remaining_balance'] ?? 0));
                        if ($checkinRemainingPayment + 0.0001 < $remaining) {
                            $error = 'Remaining balance must be fully paid during check-in.';
                        }
                        $checkinPayment = $remaining;
                    }

                    if ($error === '') {
                        $updateCheckin = $pdo->prepare("
                            UPDATE hotel_room_bookings
                            SET
                              hotel_room_id = ?,
                              room_type = ?,
                              booking_status = 'confirmed',
                              checked_in_at = ?,
                              checkin_guest_name = ?,
                              checkin_representative_name = ?,
                              checkin_guest_count = ?,
                              checkin_id_reference = ?,
                              amount_paid = amount_paid + ?,
                              remaining_balance = GREATEST(remaining_balance - ?, 0),
                              payment_status = CASE
                                WHEN GREATEST(remaining_balance - ?, 0) <= 0 THEN 'paid'
                                WHEN amount_paid + ? > 0 THEN 'partial'
                                ELSE 'unpaid'
                              END,
                              checkin_payment_amount = ?,
                              checkin_payment_recorded_at = CASE WHEN ? > 0 THEN ? ELSE checkin_payment_recorded_at END,
                              updated_at = NOW()
                            WHERE hotel_booking_id = ? AND hotel_resort_id = ?
                        ");
                        $updateCheckin->execute([
                            $roomId,
                            (string)$room['room_name'],
                            $checkinDateTime,
                            $checkinGuestName,
                            $checkinRepresentativeName !== '' ? $checkinRepresentativeName : null,
                            $checkinGuestCount,
                            $checkinIdReference !== '' ? $checkinIdReference : null,
                            $checkinPayment,
                            $checkinPayment,
                            $checkinPayment,
                            $checkinPayment,
                            $checkinPayment,
                            $checkinPayment,
                            $checkinDateTime,
                            $bookingId,
                            $hoHotelResortId,
                        ]);
                        $flash = 'Guest checked in successfully.';
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
                    } else {
                        $checkedInTs = strtotime($checkedInAt);
                        $checkoutTs = strtotime($checkoutDateTime);
                        if ($checkedInTs === false || $checkoutTs === false || $checkoutTs <= $checkedInTs) {
                            $error = 'Check-out date/time must be after check-in date/time.';
                        } else {
                            $durationNights = max(1, (int)ceil(($checkoutTs - $checkedInTs) / 86400));
                            $remaining = max(0, (float)($booking['remaining_balance'] ?? 0));
                            $dueAtCheckout = $remaining + $checkoutAdditionalCharges;
                            if ($checkoutFinalPayment + 0.0001 < $dueAtCheckout) {
                                $error = 'Final payment must cover the remaining balance and additional charges.';
                            }

                            if ($error === '') {
                                $appliedFinalPayment = $dueAtCheckout;
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
                                      total_amount = total_amount + ?,
                                      amount_paid = amount_paid + ?,
                                      remaining_balance = GREATEST((remaining_balance + ?) - ?, 0),
                                      payment_status = CASE
                                        WHEN GREATEST((remaining_balance + ?) - ?, 0) <= 0 THEN 'paid'
                                        WHEN amount_paid + ? > 0 THEN 'partial'
                                        ELSE 'unpaid'
                                      END,
                                      updated_at = NOW()
                                    WHERE hotel_booking_id = ? AND hotel_resort_id = ?
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
                                    $checkoutAdditionalCharges,
                                    $appliedFinalPayment,
                                    $checkoutAdditionalCharges,
                                    $appliedFinalPayment,
                                    $checkoutAdditionalCharges,
                                    $appliedFinalPayment,
                                    $appliedFinalPayment,
                                    $bookingId,
                                    $hoHotelResortId,
                                ]);
                                $flash = 'Guest checked out successfully. Room is now available.';
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
        }
    }
}

$allRooms = HoGetHotelRooms($pdo, $hoHotelResortId, false);
$roomStatusSnapshot = HoGetHotelRoomStatusSnapshot($pdo, $hoHotelResortId);

$bookingStmt = $pdo->prepare("
    SELECT
      hotel_booking_id,
      hotel_room_id,
      room_type,
      booking_status,
      checkin_date,
      checkout_date,
      checked_in_at,
      checked_out_at,
      first_name,
      last_name,
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

foreach ($bookingRows as $row) {
    $bookingStatus = strtolower(trim((string)($row['booking_status'] ?? '')));
    $roomId = (int)($row['hotel_room_id'] ?? 0);
    $roomNameKey = mb_strtolower(trim((string)($row['room_type'] ?? '')));
    $checkedIn = !empty($row['checked_in_at']) && empty($row['checked_out_at']);
    $currentReserved = in_array($bookingStatus, ['pending', 'confirmed'], true) && !$checkedIn && empty($row['checked_out_at']);

    $guestName = trim((string)($row['checkin_guest_name'] ?? ''));
    if ($guestName === '') {
        $guestName = trim((string)($row['first_name'] ?? '') . ' ' . (string)($row['last_name'] ?? ''));
    }
    if ($guestName === '') {
        $guestName = 'Guest';
    }

    $normalized = [
        'hotel_booking_id' => (int)($row['hotel_booking_id'] ?? 0),
        'booking_status' => $bookingStatus,
        'guest_name' => $guestName,
        'representative_name' => trim((string)($row['checkin_representative_name'] ?? '')),
        'checkin_date' => (string)($row['checkin_date'] ?? ''),
        'checkout_date' => (string)($row['checkout_date'] ?? ''),
        'checked_in_at' => (string)($row['checked_in_at'] ?? ''),
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
  <link rel="stylesheet" href="styles/Ho_panel.css" />
</head>
<body class="ho-body">
  <div class="ho-layout">
    <?php include __DIR__ . '/Ho_sidebar.php'; ?>

    <main class="ho-main">
      <?php include __DIR__ . '/Ho_header.php'; ?>

      <section class="ho-content">
        <div class="ho-room-summary-grid">
          <article class="ho-room-summary-card">
            <span>Total Rooms</span>
            <strong><?= (int)$summaryTotalRooms ?></strong>
          </article>
          <article class="ho-room-summary-card available">
            <span>Total Available</span>
            <strong><?= (int)$summaryAvailableRooms ?></strong>
          </article>
          <article class="ho-room-summary-card occupied">
            <span>Total Occupied</span>
            <strong><?= (int)$summaryOccupiedRooms ?></strong>
          </article>
          <article class="ho-room-summary-card bookings">
            <span>Total Room Bookings</span>
            <strong><?= (int)$summaryTotalBookings ?></strong>
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
            <button type="submit" class="ho-btn">Search</button>
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
                      <?php if ($selectedRoomStatus['status'] === 'booked' && $selectedStatusBooking): ?>
                        <button type="button" class="ho-btn confirm" data-open-lifecycle-modal="checkinModal<?= (int)$selectedStatusBooking['hotel_booking_id'] ?>" <?= $selectedRoomStatus['can_checkin'] ? '' : 'disabled' ?>>Check-in</button>
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

                  <p class="ho-room-focus-desc"><?= htmlspecialchars((string)$selectedRoom['description']) ?></p>

                  <div class="ho-room-booking-count">
                    <strong>Total Bookings</strong>
                    <span><?= (int)$selectedBookingCount ?></span>
                  </div>

                  <div class="ho-room-focus-grid">
                    <div class="ho-room-details-block">
                      <h4>Current Bookings</h4>
                      <?php if (!empty($selectedCurrentBookings)): ?>
                        <ul class="ho-room-details-booking-list">
                          <?php foreach (array_slice($selectedCurrentBookings, 0, 2) as $bookingItem): ?>
                            <li>
                              <strong>#<?= (int)$bookingItem['hotel_booking_id'] ?> - <?= htmlspecialchars((string)$bookingItem['guest_name']) ?></strong>
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
                              <strong>#<?= (int)$bookingItem['hotel_booking_id'] ?> - <?= htmlspecialchars((string)$bookingItem['guest_name']) ?></strong>
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
                  ?>
                  <article class="ho-room-card">
                    <img src="<?= htmlspecialchars((string)$room['main_image_path']) ?>" alt="<?= htmlspecialchars((string)$room['room_name']) ?>" class="ho-room-cover" />
                    <div class="ho-room-content">
                      <h3><?= htmlspecialchars((string)$room['room_name']) ?></h3>
                      <span class="ho-room-state-badge <?= htmlspecialchars((string)$roomStatus['badge_class']) ?>"><?= htmlspecialchars((string)$roomStatus['label']) ?></span>
                      <p><?= htmlspecialchars((string)$room['description']) ?></p>
                      <div class="ho-room-meta-inline">
                        <span>₱<?= number_format((float)$room['price'], 2) ?>/night</span>
                        <span>Capacity: <?= $capacityTotal ?> guest(s)</span>
                        <span><?= (int)$room['capacity_adults'] ?>A / <?= (int)$room['capacity_children'] ?>C</span>
                      </div>
                      <div class="ho-room-booking-count">
                        <strong>Total Bookings</strong>
                        <span><?= (int)$bookingCount ?></span>
                      </div>
                      <div class="ho-room-cta-row">
                        <button type="button" class="ho-btn" data-open-room-modal="roomModal<?= (int)$room['id'] ?>">Edit Room</button>
                        <button type="button" class="ho-btn" data-open-lifecycle-modal="roomDetailsModal<?= (int)$room['id'] ?>">View Details</button>
                        <?php if ($roomStatus['status'] === 'booked' && $statusBooking): ?>
                          <button type="button" class="ho-btn confirm" data-open-lifecycle-modal="checkinModal<?= (int)$statusBooking['hotel_booking_id'] ?>" <?= $roomStatus['can_checkin'] ? '' : 'disabled' ?>>Check-in</button>
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
                      <strong>#<?= (int)$bookingItem['hotel_booking_id'] ?> - <?= htmlspecialchars((string)$bookingItem['guest_name']) ?></strong>
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
                      <strong>#<?= (int)$bookingItem['hotel_booking_id'] ?> - <?= htmlspecialchars((string)$bookingItem['guest_name']) ?></strong>
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

    <?php if ($roomStatus['status'] === 'booked' && $statusBooking): ?>
      <?php
        $bookingId = (int)$statusBooking['hotel_booking_id'];
        $checkinDefaultGuest = trim((string)($statusBooking['checkin_guest_name'] ?? '')) !== ''
            ? (string)$statusBooking['checkin_guest_name']
            : (string)($statusBooking['guest_name'] ?? '');
        $checkinDefaultRep = (string)($statusBooking['checkin_representative_name'] ?? '');
        $checkinDefaultCount = (int)($statusBooking['checkin_guest_count'] ?? 0);
        if ($checkinDefaultCount < 1) {
            $checkinDefaultCount = max(1, (int)($statusBooking['guest_count'] ?? 1));
        }
        $checkinDefaultId = (string)($statusBooking['checkin_id_reference'] ?? '');
        $checkinDefaultPayment = max(0, (float)($statusBooking['remaining_balance'] ?? 0));
      ?>
      <div class="ho-modal" id="checkinModal<?= $bookingId ?>" aria-hidden="true">
        <div class="ho-modal-card ho-lifecycle-modal-card">
          <div class="ho-modal-head">
            <h3>Check-in Guest</h3>
            <button type="button" class="ho-close" data-close-modal>&times;</button>
          </div>
          <form method="post" class="ho-lifecycle-form" data-checkin-form>
            <input type="hidden" name="room_action" value="checkin" />
            <input type="hidden" name="room_id" value="<?= (int)$room['id'] ?>" />
            <input type="hidden" name="booking_id" value="<?= $bookingId ?>" />
            <div class="ho-lifecycle-grid two">
              <label>Guest Name
                <input type="text" name="checkin_guest_name" value="<?= htmlspecialchars($checkinDefaultGuest) ?>" required />
              </label>
              <label>Representative Name (if applicable)
                <input type="text" name="checkin_representative_name" value="<?= htmlspecialchars($checkinDefaultRep) ?>" />
              </label>
            </div>
            <div class="ho-lifecycle-grid three">
              <label>Number of Guests
                <input type="number" name="checkin_guest_count" min="1" value="<?= $checkinDefaultCount ?>" required />
              </label>
              <label>Check-in Date &amp; Time
                <input type="datetime-local" name="checkin_date_time" value="<?= htmlspecialchars(date('Y-m-d\TH:i')) ?>" required />
              </label>
              <label>Valid ID Reference (optional)
                <input type="text" name="checkin_id_reference" value="<?= htmlspecialchars($checkinDefaultId) ?>" placeholder="ID number or notes" />
              </label>
            </div>
            <div class="ho-lifecycle-grid two">
              <label>Remaining Balance Payment
                <input type="number" name="checkin_remaining_payment" min="0" step="0.01" value="<?= htmlspecialchars(number_format($checkinDefaultPayment, 2, '.', '')) ?>" required />
              </label>
              <div class="ho-lifecycle-note">
                <strong>Booking #<?= $bookingId ?></strong>
                <span>Check-in requires settling the remaining balance.</span>
              </div>
            </div>
            <div class="ho-room-actions">
              <button type="button" class="ho-btn cancel" data-close-modal>Cancel</button>
              <button type="submit" class="ho-btn confirm">Submit Check-in</button>
            </div>
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
      <div class="ho-modal" id="checkoutModal<?= $bookingId ?>" aria-hidden="true">
        <div class="ho-modal-card ho-lifecycle-modal-card">
          <div class="ho-modal-head">
            <h3>Check-out Guest</h3>
            <button type="button" class="ho-close" data-close-modal>&times;</button>
          </div>
          <form method="post" class="ho-lifecycle-form" data-checkout-form data-checked-in-at="<?= htmlspecialchars($checkedInDisplay) ?>" data-base-remaining="<?= htmlspecialchars(number_format($remainingDue, 2, '.', '')) ?>">
            <input type="hidden" name="room_action" value="checkout" />
            <input type="hidden" name="room_id" value="<?= (int)$room['id'] ?>" />
            <input type="hidden" name="booking_id" value="<?= $bookingId ?>" />
            <div class="ho-lifecycle-grid two">
              <label>Guest Name
                <input type="text" name="checkout_guest_name" value="<?= htmlspecialchars($checkoutDefaultGuest) ?>" required />
              </label>
              <label>Representative Name
                <input type="text" name="checkout_representative_name" value="<?= htmlspecialchars($checkoutDefaultRep) ?>" required />
              </label>
            </div>
            <div class="ho-lifecycle-grid three">
              <label>Check-in Date &amp; Time
                <input type="datetime-local" value="<?= htmlspecialchars($checkedInDisplay) ?>" readonly />
              </label>
              <label>Check-out Date &amp; Time
                <input type="datetime-local" name="checkout_date_time" value="<?= htmlspecialchars(date('Y-m-d\TH:i')) ?>" required />
              </label>
              <label>Total Stay Duration (nights)
                <input type="number" name="checkout_total_nights_display" min="1" step="1" value="1" readonly />
              </label>
            </div>
            <div class="ho-lifecycle-grid three">
              <label>Additional Charges
                <input type="number" name="checkout_additional_charges" min="0" step="0.01" value="0.00" required />
              </label>
              <label>Final Payment Confirmation
                <input type="number" name="checkout_final_payment" min="<?= htmlspecialchars(number_format($remainingDue, 2, '.', '')) ?>" step="0.01" value="<?= htmlspecialchars(number_format($remainingDue, 2, '.', '')) ?>" required />
              </label>
              <div class="ho-lifecycle-note" data-checkout-due-note>
                <strong>Amount due at check-out</strong>
                <span data-checkout-due-value>₱<?= number_format($remainingDue, 2) ?></span>
              </div>
            </div>
            <div class="ho-room-actions">
              <button type="button" class="ho-btn cancel" data-close-modal>Cancel</button>
              <button type="submit" class="ho-btn confirm">Submit Check-out</button>
            </div>
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
          <h3>Edit Room</h3>
          <button type="button" class="ho-close" data-close-modal>&times;</button>
        </div>
        <form method="post" class="ho-room-form" enctype="multipart/form-data">
          <input type="hidden" name="room_action" value="update" />
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
                <label>Upload New Main Image
                  <input type="file" name="main_image_file" accept="image/*" />
                </label>
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
                    <label class="ho-gallery-upload-inline">
                      <span>Upload image</span>
                      <input type="file" name="gallery_row_files[]" accept="image/*" />
                    </label>
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
            <input type="hidden" name="room_id" value="<?= (int)$room['id'] ?>" />
            <button type="submit" class="ho-btn cancel">Archive Room</button>
          </form>
        <?php endif; ?>
      </div>
    </div>
  <?php endforeach; ?>

  <div class="ho-modal" id="hoAddRoomModal" aria-hidden="true">
    <div class="ho-modal-card ho-room-modal-card">
      <div class="ho-modal-head">
        <h3>Add New Room</h3>
        <button type="button" class="ho-close" data-close-modal>&times;</button>
      </div>
      <form method="post" class="ho-room-form" enctype="multipart/form-data">
        <input type="hidden" name="room_action" value="create" />
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
              <label>Upload New Main Image
                <input type="file" name="main_image_file" accept="image/*" />
              </label>
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

  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
  <script>
    (function () {
      const flashMessage = <?= json_encode($flash, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
      const errorMessage = <?= json_encode($error, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
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
          if (modal) modal.classList.add('open');
        });
      });

      document.querySelectorAll('[data-close-modal]').forEach(btn => {
        btn.addEventListener('click', () => {
          const modal = btn.closest('.ho-modal');
          if (modal) modal.classList.remove('open');
        });
      });

      document.querySelectorAll('.ho-modal').forEach(modal => {
        modal.addEventListener('click', (e) => {
          if (e.target === modal) {
            modal.classList.remove('open');
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
            <label class="ho-gallery-upload-inline">
              <span>Upload image</span>
              <input type="file" name="gallery_row_files[]" accept="image/*" />
            </label>
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
        const dueLabel = form.querySelector('[data-checkout-due-value]');
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
        if (dueLabel) {
          dueLabel.textContent = formatMoney(due);
        }
        if (finalPaymentInput) {
          finalPaymentInput.min = due.toFixed(2);
          if (!finalPaymentInput.value || Number(finalPaymentInput.value) < due) {
            finalPaymentInput.value = due.toFixed(2);
          }
        }
      };

      document.querySelectorAll('[data-checkout-form]').forEach(form => {
        const checkoutInput = form.querySelector('input[name="checkout_date_time"]');
        const additionalInput = form.querySelector('input[name="checkout_additional_charges"]');
        if (checkoutInput) {
          checkoutInput.addEventListener('change', () => recalcCheckoutForm(form));
          checkoutInput.addEventListener('input', () => recalcCheckoutForm(form));
        }
        if (additionalInput) {
          additionalInput.addEventListener('change', () => recalcCheckoutForm(form));
          additionalInput.addEventListener('input', () => recalcCheckoutForm(form));
        }
        recalcCheckoutForm(form);
      });

      document.querySelectorAll('[data-checkin-form]').forEach(form => {
        form.addEventListener('submit', async (e) => {
          if (form.dataset.confirmed === '1') {
            return;
          }
          e.preventDefault();
          if (!window.Swal) {
            const ok = window.confirm('Proceed with check-in for this booking?');
            if (ok) {
              form.dataset.confirmed = '1';
              form.submit();
            }
            return;
          }
          const result = await Swal.fire({
            icon: 'question',
            title: 'Confirm check-in?',
            text: 'Proceed with check-in for this booking?',
            showCancelButton: true,
            confirmButtonText: 'Yes, check-in',
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
      document.querySelectorAll('[data-checkout-form]').forEach(form => {
        form.addEventListener('submit', async (e) => {
          if (form.dataset.confirmed === '1') {
            return;
          }
          e.preventDefault();
          if (!window.Swal) {
            const ok = window.confirm('Proceed with check-out and mark this booking as completed?');
            if (ok) {
              form.dataset.confirmed = '1';
              form.submit();
            }
            return;
          }
          const result = await Swal.fire({
            icon: 'warning',
            title: 'Confirm check-out?',
            text: 'This will complete the booking and set the room back to Available.',
            showCancelButton: true,
            confirmButtonText: 'Yes, check-out',
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
            title: 'Success',
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
