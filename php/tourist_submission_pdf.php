<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    require_once __DIR__ . '/session_security.php';
    AppSessionStart();
}
require_once __DIR__ . '/db_connection.php';
require_once __DIR__ . '/tourist_auth_helper.php';
require_once __DIR__ . '/booking_tourists_helper.php';
require_once __DIR__ . '/../tcpdf/tcpdf.php';

$touristAccount = TouristRequireLogin($pdo, 'text');

ensureBookingTouristManifestColumns($pdo);
$touristId = (int)$_SESSION['tourist_id'];
$bookingId = (int)($_GET['booking_id'] ?? 0);
if ($bookingId <= 0) {
    http_response_code(400);
    exit('Invalid booking.');
}

$bookingStmt = $pdo->prepare("
    SELECT b.booking_id, b.booking_reference, b.booking_date, b.created_at,
           b.booking_type, b.package_name, b.location, b.tour_type, b.pax,
           b.num_adults, b.num_children, b.status, t.full_name AS account_name
    FROM bookings b
    INNER JOIN tourist t ON t.tourist_id = b.tourist_id
    WHERE b.booking_id = ? AND b.tourist_id = ?
    LIMIT 1
");
$bookingStmt->execute([$bookingId, $touristId]);
$booking = $bookingStmt->fetch(PDO::FETCH_ASSOC);
if (!$booking) {
    http_response_code(404);
    exit('Booking not found.');
}

$passengerStmt = $pdo->prepare("
    SELECT full_name, gender, age, address, country, region, province, city,
           barangay, postal_code, street, residence, phone_number
    FROM booking_tourists
    WHERE booking_id = ?
    ORDER BY id ASC
");
$passengerStmt->execute([$bookingId]);
$passengers = array_values(array_filter(
    $passengerStmt->fetchAll(PDO::FETCH_ASSOC) ?: [],
    static fn(array $passenger): bool => trim((string)($passenger['full_name'] ?? '')) !== ''
));

if (!$passengers) {
    http_response_code(404);
    exit('No submitted tourist information was found.');
}

foreach ($passengers as &$passenger) {
    $addressParts = [
        $passenger['street'] ?? '',
        $passenger['barangay'] ?? '',
        $passenger['city'] ?? '',
        $passenger['province'] ?? '',
        $passenger['region'] ?? '',
        $passenger['postal_code'] ?? '',
        $passenger['country'] ?? '',
    ];
    $addressParts = array_values(array_filter(array_map(
        static fn($value): string => trim((string)$value),
        $addressParts
    )));
    if ($addressParts) {
        $passenger['address'] = implode(', ', $addressParts);
    }
}
unset($passenger);

function profilePdfText($value, string $fallback = '-'): string
{
    $text = trim((string)$value);
    return $text === '' ? $fallback : $text;
}

function profilePdfDate($value): string
{
    $timestamp = strtotime((string)$value);
    return $timestamp ? date('F j, Y', $timestamp) : '-';
}

function profilePdfLabel(TCPDF $pdf, float $x, float $y, float $width, string $label, string $value): void
{
    $pdf->SetXY($x, $y);
    $pdf->SetTextColor(91, 112, 104);
    $pdf->SetFont('helvetica', 'B', 7.3);
    $pdf->Cell($width, 4, strtoupper($label), 0, 1, 'L');
    $pdf->SetX($x);
    $pdf->SetTextColor(28, 58, 49);
    $valueFontSize = mb_strlen($value) > 34 ? 8.6 : 9.6;
    $pdf->SetFont('helvetica', 'B', $valueFontSize);
    $pdf->MultiCell($width, 10, $value, 0, 'L', false, 1, $x, $y + 4.2, true, 0, false, true, 10, 'T');
}

function profilePdfHeader(TCPDF $pdf, array $booking, int $page, int $pageCount): void
{
    $green = [29, 107, 83];
    $dark = [21, 61, 49];
    $pdf->SetFillColor(...$green);
    $pdf->Rect(0, 0, 210, 8, 'F');

    // Use the current iTour logo through a PDF-optimized copy. TCPDF expands
    // transparent PNG alpha channels, so the 6250px website master is unsafe.
    $logo = __DIR__ . '/../img/newlogo-pdf.png';
    $textLogo = __DIR__ . '/../img/textlogo2.png';
    if (is_file($logo)) {
        $pdf->Image($logo, 15, 15, 24, 24, 'PNG');
    }
    if (is_file($textLogo)) {
        $pdf->Image($textLogo, 44, 18, 57, 14, 'PNG');
    }

    $pdf->SetTextColor(...$dark);
    $pdf->SetXY(44, 32.5);
    $pdf->SetFont('helvetica', '', 7.8);
    $pdf->Cell(85, 4, 'Official booking and passenger information copy', 0, 0, 'L');

    $reference = profilePdfText($booking['booking_reference'] ?? ('Booking #' . $booking['booking_id']));
    $pdf->SetXY(137, 16);
    $pdf->SetTextColor(92, 112, 105);
    $pdf->SetFont('helvetica', 'B', 7);
    $pdf->Cell(58, 4, 'BOOKING REFERENCE', 0, 1, 'R');
    $pdf->SetX(137);
    $pdf->SetTextColor(...$dark);
    $pdf->SetFont('helvetica', 'B', 12);
    $pdf->Cell(58, 6, $reference, 0, 1, 'R');
    if ($pageCount > 1) {
        $pdf->SetX(137);
        $pdf->SetTextColor(92, 112, 105);
        $pdf->SetFont('helvetica', '', 7);
        $pdf->Cell(58, 5, "Page {$page} of {$pageCount}", 0, 1, 'R');
    }

    $pdf->SetDrawColor(207, 222, 216);
    $pdf->Line(15, 43, 195, 43);
    $pdf->SetXY(15, 49);
    $pdf->SetTextColor(...$dark);
    $pdf->SetFont('helvetica', 'B', 17);
    $pdf->Cell(180, 8, 'Passenger Information Record', 0, 1, 'L');
    $pdf->SetX(15);
    $pdf->SetTextColor(95, 116, 108);
    $pdf->SetFont('helvetica', '', 8.5);
    $pdf->Cell(180, 5, 'Submitted traveler details associated with your confirmed booking.', 0, 1, 'L');
}

function profilePdfFooter(TCPDF $pdf, string $reference): void
{
    $pdf->SetDrawColor(208, 221, 216);
    $pdf->Line(15, 269, 195, 269);
    $pdf->SetXY(15, 273);
    $pdf->SetTextColor(82, 105, 97);
    $pdf->SetFont('helvetica', '', 7.2);
    $note = 'This is a system-generated passenger information record from iTour Mercedes. No signature is required. Keep this copy for your booking reference.';
    $pdf->MultiCell(145, 8, $note, 0, 'L', false, 0, 15, 273);
    $pdf->SetXY(164, 273);
    $pdf->SetTextColor(29, 107, 83);
    $pdf->SetFont('helvetica', 'B', 7.2);
    $pdf->Cell(31, 8, $reference, 0, 0, 'R');
}

class TouristProfilePdf extends TCPDF
{
    public function Header() {}
    public function Footer() {}
}

$destination = trim((string)($booking['location'] ?? ''));
if ($destination === '') {
    $destination = trim((string)($booking['package_name'] ?? ''));
}
$destination = preg_replace('/\s*,\s*/u', ', ', $destination) ?? $destination;
$bookingType = strtolower(trim((string)($booking['booking_type'] ?? '')));
$serviceLabel = match ($bookingType) {
    'package' => 'Tour Package',
    'tourguide' => 'Tour Guide',
    'boat' => 'Tour Boat',
    default => ucwords($bookingType ?: 'Tour Booking'),
};
$reference = profilePdfText($booking['booking_reference'] ?? ('Booking #' . $bookingId));
$pax = (int)($booking['pax'] ?: ((int)$booking['num_adults'] + (int)$booking['num_children']));

$passengersPerPage = 6;
$pages = array_chunk($passengers, $passengersPerPage);
$pageCount = count($pages);
$pdf = new TouristProfilePdf('P', 'mm', 'A4', true, 'UTF-8', false);
$pdf->SetCreator('iTour Mercedes');
$pdf->SetAuthor('iTour Mercedes');
$pdf->SetTitle('Passenger Information - ' . $reference);
$pdf->SetPrintHeader(false);
$pdf->SetPrintFooter(false);
$pdf->SetMargins(15, 12, 15);
$pdf->SetAutoPageBreak(false);

foreach ($pages as $pageIndex => $pagePassengers) {
    $pdf->AddPage();
    profilePdfHeader($pdf, $booking, $pageIndex + 1, $pageCount);

    $pdf->SetFillColor(245, 249, 247);
    $pdf->SetDrawColor(211, 225, 219);
    $pdf->RoundedRect(15, 67, 180, 20, 3, '1111', 'DF');
    profilePdfLabel($pdf, 21, 70, 38, 'Travel date', profilePdfDate($booking['booking_date'] ?? ''));
    profilePdfLabel($pdf, 63, 70, 39, 'Service', $serviceLabel);
    profilePdfLabel($pdf, 106, 70, 62, 'Destination / Package', profilePdfText($destination));
    profilePdfLabel($pdf, 173, 70, 16, 'Pax', (string)$pax);

    $pdf->SetXY(15, 94);
    $pdf->SetTextColor(24, 66, 53);
    $pdf->SetFont('helvetica', 'B', 11.5);
    $pdf->Cell(120, 7, 'Submitted Passengers', 0, 0, 'L');
    $pdf->SetTextColor(94, 116, 108);
    $pdf->SetFont('helvetica', '', 7.5);
    $pdf->Cell(60, 7, count($passengers) . ' passenger' . (count($passengers) === 1 ? '' : 's'), 0, 1, 'R');

    $columns = [
        ['label' => '#', 'width' => 9, 'align' => 'C'],
        ['label' => 'PASSENGER NAME', 'width' => 43, 'align' => 'L'],
        ['label' => 'AGE / GENDER', 'width' => 25, 'align' => 'C'],
        ['label' => 'COMPLETE ADDRESS', 'width' => 70, 'align' => 'L'],
        ['label' => 'CONTACT', 'width' => 33, 'align' => 'C'],
    ];
    $left = 15.0;
    $y = 103.0;
    $x = $left;
    $pdf->SetFillColor(29, 107, 83);
    $pdf->SetDrawColor(29, 107, 83);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->SetFont('helvetica', 'B', 7.2);
    foreach ($columns as $column) {
        $pdf->MultiCell($column['width'], 9, $column['label'], 1, 'C', true, 0, $x, $y, true, 0, false, true, 9, 'M');
        $x += $column['width'];
    }
    $y += 9;

    foreach ($pagePassengers as $rowIndex => $passenger) {
        $gender = ucfirst(strtolower(profilePdfText($passenger['gender'] ?? '')));
        $ageGender = profilePdfText($passenger['age'] ?? '') . ' / ' . $gender;
        $values = [
            (string)($pageIndex * $passengersPerPage + $rowIndex + 1),
            profilePdfText($passenger['full_name'] ?? ''),
            $ageGender,
            profilePdfText($passenger['address'] ?? ''),
            profilePdfText($passenger['phone_number'] ?? ''),
        ];
        $pdf->SetFont('helvetica', '', 7.4);
        $rowHeight = 11.0;
        foreach ($columns as $columnIndex => $column) {
            $rowHeight = max($rowHeight, min(18.0, $pdf->getNumLines($values[$columnIndex], $column['width'] - 3) * 3.8 + 3));
        }
        $x = $left;
        $fill = $rowIndex % 2 === 1;
        $pdf->SetFillColor(248, 251, 250);
        $pdf->SetDrawColor(207, 221, 216);
        $pdf->SetTextColor(34, 59, 51);
        foreach ($columns as $columnIndex => $column) {
            $pdf->MultiCell($column['width'], $rowHeight, $values[$columnIndex], 1, $column['align'], $fill, 0, $x, $y, true, 0, false, true, $rowHeight, 'M');
            $x += $column['width'];
        }
        $y += $rowHeight;
    }

    $pdf->SetFillColor(239, 247, 244);
    $pdf->SetDrawColor(198, 220, 211);
    $summaryY = min(245.0, $y + 9);
    $pdf->RoundedRect(15, $summaryY, 180, 20, 3, '1111', 'DF');
    $pdf->SetXY(21, $summaryY + 4);
    $pdf->SetTextColor(29, 107, 83);
    $pdf->SetFont('helvetica', 'B', 7.2);
    $pdf->Cell(38, 4, 'SUBMISSION STATUS', 0, 1, 'L');
    $pdf->SetX(21);
    $pdf->SetTextColor(26, 68, 54);
    $pdf->SetFont('helvetica', 'B', 9.5);
    $pdf->Cell(60, 6, 'Passenger details submitted', 0, 0, 'L');
    $pdf->SetXY(111, $summaryY + 4);
    $pdf->SetTextColor(88, 111, 102);
    $pdf->SetFont('helvetica', '', 7.2);
    $pdf->MultiCell(78, 12, 'Present this booking reference when coordinating changes with iTour Mercedes.', 0, 'R', false, 0, 111, $summaryY + 4, true, 0, false, true, 12, 'M');

    profilePdfFooter($pdf, $reference);
}

$filename = 'itour_passenger_information_' . preg_replace('/[^A-Za-z0-9_-]/', '_', $reference) . '.pdf';
$pdf->Output($filename, 'I');
