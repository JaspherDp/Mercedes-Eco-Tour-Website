<?php
declare(strict_types=1);

require_once __DIR__ . '/../php/session_security.php';
AppSessionStart();
require_once __DIR__ . '/../php/db_connection.php';
require_once __DIR__ . '/../php/tourist_auth_helper.php';
require_once __DIR__ . '/../php/hotel_rooms_helper.php';
require_once __DIR__ . '/../php/additional_fees_helper.php';
require_once __DIR__ . '/../php/package_checkout_authority_helper.php';
require_once __DIR__ . '/../php/tour_resource_availability_helper.php';
require_once __DIR__ . '/../php/request_rate_limiter.php';
require_once __DIR__ . '/../php/input_validation.php';
require_once __DIR__ . '/PayMongoService.php';
require_once __DIR__ . '/BookingCheckoutService.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function bookingCheckoutResponse(int $status, array $body): never
{
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function bookingCheckoutText(mixed $value, int $limit = 255): string
{
    $value = trim((string)$value);
    return mb_substr($value, 0, $limit);
}

function bookingCheckoutDate(mixed $value): string
{
    try {
        return ItourValidationDate($value, 'Booking date');
    } catch (InvalidArgumentException) {
        return '';
    }
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    bookingCheckoutResponse(405, ['success' => false, 'message' => 'Method not allowed.']);
}
$tourist = TouristRequireLogin($pdo, 'json');
$touristId = (int)$tourist['tourist_id'];
$csrf = (string)($_SERVER['HTTP_X_BOOKING_CSRF'] ?? '');
$sessionCsrf = (string)($_SESSION['paymongo_booking_csrf'] ?? '');
if ($csrf === '' || $sessionCsrf === '' || !hash_equals($sessionCsrf, $csrf)) {
    bookingCheckoutResponse(403, ['success' => false, 'message' => 'Your booking session expired. Refresh the page and try again.']);
}
if (!BookingCheckoutService::tableExists($pdo)) {
    bookingCheckoutResponse(503, ['success' => false, 'message' => 'Booking-time online payments are not ready.']);
}

$input = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($input)) {
    bookingCheckoutResponse(400, ['success' => false, 'message' => 'Invalid booking details.']);
}
$domain = strtolower(bookingCheckoutText($input['bookingType'] ?? $input['booking_domain'] ?? '', 20));
$paymentType = strtolower(bookingCheckoutText($input['paymentOption'] ?? $input['payment_type'] ?? 'full', 20));
if (!in_array($domain, ['hotel', 'package', 'boat', 'tourguide'], true)
    || !in_array($paymentType, ['full', 'partial'], true)) {
    bookingCheckoutResponse(400, ['success' => false, 'message' => 'Invalid booking or payment option.']);
}

$payload = [];
$total = 0.0;
$serviceName = '';
$lineDescription = '';
try {
    if ($domain === 'hotel') {
        HoEnsureHotelBookingsTable($pdo);
        $hotelId = ItourValidationInt($input['hotel_id'] ?? null, 'Hotel ID', 1, PHP_INT_MAX);
        $roomId = ItourValidationInt($input['room_id'] ?? null, 'Room ID', 1, PHP_INT_MAX);
        $checkin = bookingCheckoutDate($input['checkin'] ?? '');
        $checkout = bookingCheckoutDate($input['checkout'] ?? '');
        $adults = ItourValidationInt($input['adults'] ?? 1, 'Adults', 1, 100);
        $children = ItourValidationInt($input['children'] ?? 0, 'Children', 0, 100);
        $firstName = bookingCheckoutText($input['first_name'] ?? '', 120);
        $lastName = bookingCheckoutText($input['last_name'] ?? '', 120);
        $email = bookingCheckoutText($input['email'] ?? '', 190);
        $phone = bookingCheckoutText($input['phone_number'] ?? '', 40);
        if ($hotelId < 1 || $roomId < 1 || $checkin === '' || $checkout === ''
            || $checkin < date('Y-m-d') || $checkout <= $checkin
            || $firstName === '' || $lastName === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || $phone === '') {
            throw new DomainException('Please review the hotel booking details and dates.');
        }
        $hotelStmt = $pdo->prepare("SELECT name FROM hotel_resorts WHERE hotel_resort_id = ? AND status = 'active' LIMIT 1");
        $hotelStmt->execute([$hotelId]);
        $hotelName = bookingCheckoutText($hotelStmt->fetchColumn(), 120);
        $room = HoGetHotelRoomById($pdo, $hotelId, $roomId, true);
        if ($hotelName === '' || !$room) {
            throw new DomainException('The selected hotel room is no longer available.');
        }
        if ($adults + $children > HoRoomCapacityTotal($room)) {
            throw new DomainException('The selected room cannot accommodate this number of guests.');
        }
        if (!HoIsHotelRoomAvailable($pdo, $hotelId, $roomId, (string)$room['room_name'], $checkin, $checkout)) {
            throw new DomainException('This room is no longer available for the selected dates.');
        }
        $draftConflict = $pdo->prepare(
            "SELECT COUNT(*) FROM booking_checkout_drafts
             WHERE booking_domain = 'hotel' AND status = 'pending' AND expires_at > NOW()
               AND JSON_UNQUOTE(JSON_EXTRACT(payload, '$.hotel_resort_id')) = ?
               AND JSON_UNQUOTE(JSON_EXTRACT(payload, '$.hotel_room_id')) = ?
               AND ? <= JSON_UNQUOTE(JSON_EXTRACT(payload, '$.checkout_date'))
               AND ? >= JSON_UNQUOTE(JSON_EXTRACT(payload, '$.checkin_date'))"
        );
        $draftConflict->execute([(string)$hotelId, (string)$roomId, $checkin, $checkout]);
        if ((int)$draftConflict->fetchColumn() > 0) {
            throw new DomainException('Another customer is currently paying for this room. Please try again shortly.');
        }
        $nights = (int)(new DateTimeImmutable($checkin))->diff(new DateTimeImmutable($checkout))->days;
        $total = round((float)$room['price'] * max(1, $nights), 2);
        $payload = [
            'hotel_resort_id' => $hotelId, 'hotel_room_id' => $roomId,
            'room_type' => (string)$room['room_name'], 'checkin_date' => $checkin,
            'checkout_date' => $checkout, 'nights' => max(1, $nights),
            'adults' => $adults, 'children' => $children, 'first_name' => $firstName,
            'last_name' => $lastName, 'email' => $email, 'phone_number' => $phone,
            'special_request' => bookingCheckoutText($input['special_request'] ?? '', 2000),
            'unit_price' => (float)$room['price'], 'hotel_name' => $hotelName,
        ];
        $serviceName = $hotelName . ' - ' . (string)$room['room_name'];
        $lineDescription = $checkin . ' to ' . $checkout . ' | ' . $nights . ' night(s)';
    } else {
        $bookingDate = bookingCheckoutDate($input['bookingDate'] ?? '');
        $bookingEndDate = bookingCheckoutDate($input['bookingEndDate'] ?? '');
        $tourType = bookingCheckoutText($input['tourType'] ?? '', 20);
        $phone = bookingCheckoutText($input['contactNumber'] ?? '', 20);
        $adults = ItourValidationInt($input['numAdults'] ?? 0, 'Adults', 0, 100);
        $children = ItourValidationInt($input['numChildren'] ?? 0, 'Children', 0, 100);
        $guestCount = $adults + $children;
        if ($guestCount < 1 || $guestCount > 100) throw new DomainException('A booking must have between 1 and 100 guests.');
        if (!is_array($input['selectedLocations'] ?? null)) {
            throw new DomainException('Tour locations must be submitted as a list.');
        }
        $allowedLocations = [
            'Malasugui Island', 'Caringo Island', 'Apuao Grande Island',
            'Apuao Pequeña Island', 'Canimog Island', 'Quinapaguian Island',
        ];
        $locations = ItourValidationList($input['selectedLocations'], 'Tour locations', 2, 80);
        if (count($locations) !== count(array_unique($locations))) {
            throw new DomainException('Tour locations must not contain duplicates.');
        }
        foreach ($locations as $location) {
            if (!in_array($location, $allowedLocations, true)) {
                throw new DomainException('Select only available tour locations.');
            }
        }
        if ($bookingDate === '' || $bookingDate < date('Y-m-d') || $phone === '' || $guestCount < 1
            || !in_array($tourType, ['same-day', 'overnight'], true)) {
            throw new DomainException('Please review the tour date, guests, and contact details.');
        }
        if ($domain !== 'package' && $tourType === 'overnight' && ($bookingEndDate === '' || $bookingEndDate <= $bookingDate)) {
            throw new DomainException('Select a valid end date for the overnight tour.');
        }
        if ($domain !== 'package' && ($locations === [] || count($locations) > 2)) {
            throw new DomainException('Select one or two tour locations.');
        }
        $rateKey = $tourType === 'overnight' ? 'overnight' : 'day';
        $rates = ['boat' => 0.0, 'tourguide' => 0.0];
        $rateStmt = $pdo->query("SELECT service_type, day_tour_price, overnight_price FROM service_prices WHERE is_active = 1");
        foreach ($rateStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $key = strtolower((string)$row['service_type']);
            if (isset($rates[$key])) $rates[$key] = (float)$row[$rateKey === 'day' ? 'day_tour_price' : 'overnight_price'];
        }
        $requiredBoats = max(1, (int)ceil($guestCount / 8));
        $addOn = bookingCheckoutText($input['addOnService'] ?? '', 20);
        $operatorId = 0;
        $packageName = '';
        $packageId = 0;
        $authoritativePackageRange = '';
        if ($domain === 'package') {
            $packageId = ItourValidationInt($input['package_id'] ?? null, 'Package ID', 1, PHP_INT_MAX);
            $packageStmt = $pdo->prepare("SELECT p.package_id, p.operator_id, p.package_title, p.price, p.package_type, p.package_range FROM tour_packages p JOIN operators o ON o.operator_id=p.operator_id WHERE p.package_id=? AND o.status='active' LIMIT 1");
            $packageStmt->execute([$packageId]);
            $package = $packageStmt->fetch(PDO::FETCH_ASSOC);
            if (!$package) throw new DomainException('The selected package is no longer available.');

            $packageId = (int)$package['package_id'];
            $operatorId = (int)$package['operator_id'];
            $packageName = bookingCheckoutText($package['package_title'] ?? '', 255);
            $submittedPackageName = bookingCheckoutText($input['packageName'] ?? '', 255);
            if ($submittedPackageName !== '' && $submittedPackageName !== $packageName) {
                throw new DomainException('The selected package details do not match. Refresh the page and try again.');
            }

            $packageDuration = PackageCheckoutDuration(
                (string)($package['package_type'] ?? ''),
                (string)($package['package_range'] ?? '')
            );
            if ($tourType !== '' && $tourType !== $packageDuration['type']) {
                throw new DomainException('The submitted tour type does not match the selected package.');
            }
            $tourType = $packageDuration['type'];
            $authoritativePackageRange = $packageDuration['range'];
            $rateKey = $tourType === 'overnight' ? 'overnight' : 'day';
            if ($tourType === 'same-day') {
                if ($bookingEndDate !== '' && $bookingEndDate !== $bookingDate) {
                    throw new DomainException('This package is valid for one same-day booking only.');
                }
                $bookingEndDate = $bookingDate;
            } else {
                $expectedEndDate = (new DateTimeImmutable($bookingDate))
                    ->modify('+' . $packageDuration['nights'] . ' days')
                    ->format('Y-m-d');
                if ($bookingEndDate === '' || $bookingEndDate !== $expectedEndDate) {
                    throw new DomainException(
                        'This package requires exactly ' . $packageDuration['days'] . ' days and '
                        . $packageDuration['nights'] . ' night(s).'
                    );
                }
                $bookingEndDate = $expectedEndDate;
            }
            $serviceTotal = (float)$package['price'] * $guestCount;
        } elseif ($domain === 'boat') {
            $serviceTotal = $rates['boat'] * $requiredBoats;
            if ($addOn === 'tourguide') $serviceTotal += $rates['tourguide'];
        } else {
            $serviceTotal = $rates['tourguide'];
            if ($addOn === 'boat') $serviceTotal += $rates['boat'] * $requiredBoats;
        }
        $configuredFees = additionalFeesForBooking(getAdditionalFees($pdo));
        $ecoRates = $configuredFees['environmental'];
        $eco = strtolower(bookingCheckoutText($input['ecoCategory'] ?? '', 20));
        if (!array_key_exists($eco, $ecoRates)) {
            throw new DomainException('Select a valid environmental-fee category.');
        }
        $environmental = $ecoRates[$eco] * $adults;
        $childAgesInput = $input['childAges'] ?? [];
        if (!is_array($childAgesInput) || count($childAgesInput) !== $children) {
            throw new DomainException('Provide one valid age for every child.');
        }
        $childAges = [];
        foreach ($childAgesInput as $index => $age) {
            $childAges[] = ItourValidationInt($age, 'Child age ' . ($index + 1), 0, 17);
        }
        $freeChildren = count(array_filter($childAges, static fn(int $age): bool => $age <= 7));
        $entranceGuests = max(0, $guestCount - $freeChildren);
        $entrance = $configuredFees['entrance'];
        $docking = $configuredFees['docking'];
        $usesBoat = $domain === 'boat' || $addOn === 'boat';
        $fees = $environmental;
        foreach ($locations as $location) {
            $entranceRate = $location === 'Canimog Island'
                ? (float)$entrance['Canimog Island'][$rateKey]
                : ($entrance[$location] ?? 0);
            $fees += $entranceRate * $entranceGuests;
            if ($usesBoat) $fees += ($docking[$location] ?? 0) * $requiredBoats;
        }
        $total = round($serviceTotal + $fees, 2);
        $rawResourceId = $domain === 'boat' ? ($input['boat_id'] ?? 0)
            : ($domain === 'tourguide' ? ($input['guide_id'] ?? 0) : 0);
        $resourceId = ($rawResourceId === 0 || $rawResourceId === '0' || $rawResourceId === '')
            ? 0 : ItourValidationInt($rawResourceId, 'Preferred service ID', 1, PHP_INT_MAX);
        $resourceName = '';
        if ($resourceId > 0) {
            $resourceTable = $domain === 'boat' ? 'boats' : 'tour_guides';
            $resourceColumn = $domain === 'boat' ? 'boat_id' : 'guide_id';
            $resourceNameColumn = $domain === 'boat' ? 'name' : 'fullname';
            $resourceStmt = $pdo->prepare("SELECT {$resourceNameColumn} FROM {$resourceTable} WHERE {$resourceColumn} = ? LIMIT 1");
            $resourceStmt->execute([$resourceId]);
            $resourceName = bookingCheckoutText($resourceStmt->fetchColumn(), 160);
            if ($resourceName === '') {
                throw new DomainException('The selected boat or tour guide is no longer available.');
            }
        }
        $allowedAddOns = $domain === 'boat' ? ['', 'tourguide']
            : ($domain === 'tourguide' ? ['', 'boat'] : ['']);
        if (!in_array($addOn, $allowedAddOns, true)) {
            throw new DomainException('The selected add-on does not match this booking type.');
        }
        $validatedBoatId = $domain === 'boat' ? $resourceId : 0;
        $validatedGuideId = $domain === 'tourguide' ? $resourceId : 0;
        $resourceBookingEnd = $tourType === 'overnight' ? $bookingEndDate : $bookingDate;
        $preferredParts = [];
        if ($domain === 'package') {
            $preferredParts[] = 'Package: ' . $packageName;
        } elseif ($domain === 'boat') {
            $preferredParts[] = 'Preferred Boat: ' . ($resourceName !== '' ? $resourceName : 'No specific preference');
        } else {
            $preferredParts[] = 'Preferred Tour Guide: ' . ($resourceName !== '' ? $resourceName : 'No specific preference');
        }
        if ($addOn === 'boat') $preferredParts[] = 'Add-on: Tour Boat';
        if ($addOn === 'tourguide') $preferredParts[] = 'Add-on: Tour Guide';
        $preferredParts[] = 'Payment Option: ' . ($paymentType === 'full' ? 'Full' : '20% Partial');
        $preferred = bookingCheckoutText(implode(' | ', $preferredParts), 255);
        $payload = [
            'booking_date' => $bookingDate, 'location' => implode(',', $locations),
            'package_id' => $packageId, 'package_name' => $packageName,
            'package_range' => $authoritativePackageRange, 'phone_number' => $phone,
            'operator_id' => $operatorId, 'tour_type' => $tourType,
            'booking_end_date' => $resourceBookingEnd,
            'tour_range' => $tourType === 'overnight' ? ($bookingDate . ' to ' . $bookingEndDate) : $bookingDate,
            'jump_off_port' => bookingCheckoutText($input['jumpOffPort'] ?? '', 50),
            'preferred_resource' => $preferred, 'boat_id' => $validatedBoatId,
            'guide_id' => $validatedGuideId, 'num_adults' => $adults,
            'num_children' => $children,
        ];
        $serviceName = $packageName !== '' ? $packageName : ucfirst($domain) . ' booking';
        $lineDescription = $bookingDate . ' | ' . $guestCount . ' guest(s)';
    }

    if ($total <= 0) throw new DomainException('This booking has no payable amount.');

    $bookingLimit = requestRateLimitConsume($pdo, 'booking_submission', 'tourist:' . $touristId, 5, 3600);
    if (!$bookingLimit['allowed']) {
        requestRateLimitReject($bookingLimit);
    }

    $amount = $paymentType === 'partial' ? round($total * 0.20, 2) : $total;
    $totalMinor = PaymentHelper::amountToCentavos($total);
    $amountMinor = PaymentHelper::amountToCentavos($amount);

    $merchantReference = 'PM-BK-' . strtoupper(bin2hex(random_bytes(10)));
    $idempotencyKey = 'booking_' . bin2hex(random_bytes(24));
    $returnToken = bin2hex(random_bytes(32));

    $resourceLock = '';
    if ($domain === 'package' && isset($packageName, $resourceBookingEnd)) {
        $resourceLock = tourPackageLock($pdo, $packageName);
        if (!tourPackageIsAvailable($pdo, $packageName, $bookingDate, $resourceBookingEnd, max(1, (int)$guestCount), (int)$operatorId, (int)$packageId)) {
            throw new DomainException('This tour package does not have enough open guest slots for the selected date.');
        }
    } elseif (isset($resourceId, $resourceBookingEnd) && $resourceId > 0 && in_array($domain, ['boat', 'tourguide'], true)) {
        $resourceLock = tourResourceLock($pdo, $domain, $resourceId);
        if (!tourResourceIsAvailable($pdo, $domain, $resourceId, $bookingDate, $resourceBookingEnd)) {
            throw new DomainException('This boat or tour guide is unavailable for the selected date.');
        }
    }

    $pdo->beginTransaction();
    $draftInsert = $pdo->prepare(
        "INSERT INTO booking_checkout_drafts
         (tourist_id, booking_domain, payload, total_minor, amount_minor, payment_type, status, expires_at)
         VALUES (?, ?, ?, ?, ?, ?, 'pending', DATE_ADD(NOW(), INTERVAL 30 MINUTE))"
    );
    $draftInsert->execute([$touristId, $domain, json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), $totalMinor, $amountMinor, $paymentType]);
    $draftId = (int)$pdo->lastInsertId();
    $returnPath = $domain === 'hotel'
        ? ('hotel_details.php?id=' . (int)$payload['hotel_resort_id'])
        : bookingCheckoutText($input['returnUrl'] ?? '', 500);
    $metadata = json_encode([
        'source' => 'booking_checkout', 'booking_draft_id' => (string)$draftId,
        'booking_domain' => $domain, 'return_path' => $returnPath,
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    $txInsert = $pdo->prepare(
        "INSERT INTO payment_transactions
         (tourist_id, booking_domain, booking_id, booking_reference, provider, merchant_reference,
          idempotency_key, return_token, amount_minor, currency, status, metadata, expires_at)
         VALUES (?, ?, ?, NULL, 'paymongo', ?, ?, ?, ?, 'PHP', 'pending', ?, DATE_ADD(NOW(), INTERVAL 30 MINUTE))"
    );
    $txInsert->execute([$touristId, $domain, -$draftId, $merchantReference, $idempotencyKey, $returnToken, $amountMinor, $metadata]);
    $transactionId = (int)$pdo->lastInsertId();
    $pdo->commit();
    tourResourceUnlock($pdo, $resourceLock ?? '');

    session_write_close();
    $publicAppUrl = PaymentHelper::env('PUBLIC_APP_URL');
    $attributes = [
        'line_items' => [[
            'name' => mb_substr($serviceName, 0, 120), 'description' => mb_substr($lineDescription, 0, 255),
            'amount' => $amountMinor, 'currency' => 'PHP', 'quantity' => 1,
        ]],
        'payment_method_types' => ['card', 'gcash', 'qrph'],
        'merchant' => 'iTour Mercedes',
        'description' => ($paymentType === 'partial' ? '20% booking downpayment' : 'Full booking payment') . '. Booking is submitted after verified payment.',
        'success_url' => PaymentHelper::publicHttpsUrl($publicAppUrl, '/payments/payment-success.php?token=' . rawurlencode($returnToken)),
        'cancel_url' => PaymentHelper::publicHttpsUrl($publicAppUrl, '/payments/payment-cancel.php?token=' . rawurlencode($returnToken)),
        'reference_number' => $merchantReference, 'send_email_receipt' => false,
        'show_description' => true, 'show_line_items' => true,
        'billing' => array_filter(['name' => (string)$tourist['full_name'], 'email' => (string)$tourist['email'], 'phone' => (string)$tourist['phone_number']]),
        'metadata' => [
            'payment_transaction_id' => (string)$transactionId, 'booking_draft_id' => (string)$draftId,
            'booking_domain' => $domain, 'tourist_id' => (string)$touristId,
        ],
    ];
    $result = PayMongoService::fromEnvironment()->createCheckoutSession($attributes, $idempotencyKey);
    $resource = is_array($result['data'] ?? null) ? $result['data'] : [];
    $resourceAttributes = is_array($resource['attributes'] ?? null) ? $resource['attributes'] : [];
    $sessionId = (string)($resource['id'] ?? '');
    $checkoutUrl = (string)($resourceAttributes['checkout_url'] ?? '');
    $host = strtolower((string)(parse_url($checkoutUrl, PHP_URL_HOST) ?: ''));
    if (!str_starts_with($sessionId, 'cs_') || ($host !== 'checkout.paymongo.com' && !str_ends_with($host, '.paymongo.com'))) {
        throw new RuntimeException('PayMongo returned an invalid Checkout Session.');
    }
    $save = $pdo->prepare('UPDATE payment_transactions SET provider_checkout_session_id=?, checkout_url=? WHERE payment_transaction_id=?');
    $save->execute([$sessionId, $checkoutUrl, $transactionId]);
    bookingCheckoutResponse(200, ['success' => true, 'checkout_url' => $checkoutUrl]);
} catch (DomainException|InvalidArgumentException $exception) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    tourResourceUnlock($pdo, $resourceLock ?? '');
    bookingCheckoutResponse(400, ['success' => false, 'message' => $exception->getMessage()]);
} catch (PayMongoException $exception) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    tourResourceUnlock($pdo, $resourceLock ?? '');
    if (isset($transactionId)) {
        $pdo->prepare("UPDATE payment_transactions SET status='failed', failed_at=NOW(), failure_message=? WHERE payment_transaction_id=?")->execute([mb_substr($exception->getMessage(), 0, 1000), $transactionId]);
        $pdo->prepare("UPDATE booking_checkout_drafts SET status='failed' WHERE booking_draft_id=?")->execute([$draftId]);
    }
    error_log('Booking checkout PayMongo error: ' . $exception->getMessage());
    bookingCheckoutResponse(502, ['success' => false, 'message' => 'PayMongo could not prepare the payment page. No booking was submitted.']);
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    tourResourceUnlock($pdo, $resourceLock ?? '');
    if (isset($transactionId, $draftId)) {
        try {
            $pdo->prepare("UPDATE payment_transactions SET status='failed', failed_at=NOW(), failure_message=? WHERE payment_transaction_id=? AND status='pending'")
                ->execute([mb_substr($exception->getMessage(), 0, 1000), $transactionId]);
            $pdo->prepare("UPDATE booking_checkout_drafts SET status='failed' WHERE booking_draft_id=? AND status='pending'")
                ->execute([$draftId]);
        } catch (Throwable $ignored) {
        }
    }
    error_log('Booking checkout error: ' . $exception->getMessage());
    bookingCheckoutResponse(500, ['success' => false, 'message' => 'The secure payment page could not be opened. No booking was submitted.']);
}
