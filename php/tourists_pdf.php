<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    require_once __DIR__ . '/session_security.php';
    AppSessionStart();
}
require_once __DIR__ . '/db_connection.php';
require_once __DIR__ . '/admin_auth_helper.php';
require_once __DIR__ . '/operator_auth_helper.php';
require_once __DIR__ . '/tourist_auth_helper.php';
require_once __DIR__ . '/booking_tourists_helper.php';
require_once __DIR__ . '/../tcpdf/tcpdf.php';

$bookingScope = '';
$bookingScopeParams = [];
$adminAuthorized = ($_SESSION['admin_logged_in'] ?? false) === true && AdminValidateSession($pdo);
$operatorAuthorized = !$adminAuthorized && ($_SESSION['operator_logged_in'] ?? false) === true && OperatorValidateSession($pdo);
$touristAuthorized = !$adminAuthorized && !$operatorAuthorized && (int)($_SESSION['tourist_id'] ?? 0) > 0 && TouristValidateSession($pdo);
if ($adminAuthorized) {
    // Administrators may view any booking manifest.
} elseif ($operatorAuthorized) {
    $bookingScope = ' AND b.operator_id = ?';
    $bookingScopeParams[] = (int)$_SESSION['operator_id'];
} elseif ($touristAuthorized) {
    $bookingScope = ' AND b.tourist_id = ?';
    $bookingScopeParams[] = (int)$_SESSION['tourist_id'];
} else {
    http_response_code(401);
    exit('Authentication required.');
}

$bookingId = (int)($_GET['booking_id'] ?? 0);
if ($bookingId <= 0) {
    http_response_code(400);
    exit('Invalid booking.');
}

$bookingStmt = $pdo->prepare("
    SELECT b.booking_id, b.booking_reference, b.booking_date, b.booking_type,
           b.package_name, b.location, b.jump_off_port, b.preferred_resource,
           b.pax, b.num_adults, b.num_children, bo.name AS boat_name
    FROM bookings b
    LEFT JOIN boats bo ON bo.boat_id = b.boat_id
    WHERE b.booking_id = ?{$bookingScope}
    LIMIT 1
");
$bookingStmt->execute(array_merge([$bookingId], $bookingScopeParams));
$booking = $bookingStmt->fetch(PDO::FETCH_ASSOC);
if (!$booking) {
    http_response_code(404);
    exit('Booking not found.');
}

ensureBookingTouristManifestColumns($pdo);

$touristStmt = $pdo->prepare("
    SELECT full_name, gender, age, address, country, region, province, city, barangay,
           postal_code, street, residence, phone_number
    FROM booking_tourists
    WHERE booking_id = ?
    ORDER BY id ASC
");
$touristStmt->execute([$bookingId]);
$tourists = $touristStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
$tourists = array_values(array_filter(
    $tourists,
    static fn(array $tourist): bool => trim((string)($tourist['full_name'] ?? '')) !== ''
));

foreach ($tourists as &$tourist) {
    $parts = [
        $tourist['street'] ?? '',
        $tourist['barangay'] ?? '',
        $tourist['city'] ?? '',
        $tourist['province'] ?? '',
        $tourist['region'] ?? '',
        $tourist['postal_code'] ?? '',
        $tourist['country'] ?? '',
    ];
    $parts = array_values(array_filter(array_map(static fn($value) => trim((string)$value), $parts)));
    if ($parts) {
        $tourist['address'] = implode(', ', $parts);
    } elseif (trim((string)($tourist['address'] ?? '')) === '') {
        $tourist['address'] = ucfirst((string)($tourist['residence'] ?? ''));
    }
}
unset($tourist);

function manifestText($value): string
{
    $text = trim((string)$value);
    return $text === '' ? '-' : $text;
}

function manifestHeader(TCPDF $pdf): void
{
    $pdf->SetTextColor(0, 0, 0);
    $pdf->SetFont('times', 'B', 12);
    $pdf->SetY(15);
    foreach ([
        'Department of Transportation',
        'Philippine Coast Guard',
        'Coast Guard District Bicol',
        'Coast Guard Station Cam Norte',
        'Coast Guard Sub-Station Mercedes',
        'Delos Reyes Blvd. Mercedes Camarines Norte',
    ] as $line) {
        $pdf->Cell(0, 5.1, $line, 0, 1, 'C');
    }
}

function manifestFooter(TCPDF $pdf, float $tableBottom): void
{
    $signatureY = max(230, $tableBottom + 16);
    if ($signatureY > 253) {
        $signatureY = 253;
    }
    $pdf->SetLineWidth(0.35);
    $pdf->Line(132, $signatureY, 190, $signatureY);
    $pdf->SetXY(132, $signatureY + 1.5);
    $pdf->SetFont('times', 'B', 10.5);
    $pdf->Cell(58, 5, 'MASTER / SIGNATURE', 0, 1, 'C');

    $pdf->SetY(275);
    $pdf->SetFont('times', 'B', 9.2);
    $pdf->Cell(0, 5, "Distribution:  Original – PCG          Duplicate: Master's File (Ship's File)", 0, 1, 'C');
}

class PassengerManifestPdf extends TCPDF
{
    public function Header() {}
    public function Footer() {}
}

ob_start();
$pdf = new PassengerManifestPdf('P', 'mm', 'A4', true, 'UTF-8', false);
$pdf->SetCreator('iTour Mercedes');
$pdf->SetAuthor('iTour Mercedes');
$pdf->SetTitle('Passenger Manifest - Booking ' . $bookingId);
$pdf->SetPrintHeader(false);
$pdf->SetPrintFooter(false);
$pdf->SetMargins(10, 12, 10);
$pdf->SetAutoPageBreak(false);

$bookingType = strtolower(trim((string)($booking['booking_type'] ?? '')));
if ($bookingType === 'package') {
    $vesselName = trim((string)($booking['package_name'] ?? ''));
    $resourceNote = 'Tour Package';
} elseif ($bookingType === 'tourguide') {
    $vesselName = trim((string)($booking['preferred_resource'] ?? ''));
    $resourceNote = 'Tour Guide';
} else {
    $vesselName = trim((string)($booking['boat_name'] ?? ''));
    if ($vesselName === '') {
        $vesselName = trim((string)($booking['preferred_resource'] ?? ''));
    }
    $resourceNote = 'Tour Boat';
}
$vesselNameWithType = $vesselName === '' ? '' : $vesselName . ' (' . $resourceNote . ')';
$destination = trim((string)($booking['location'] ?? ''));
if ($destination === '') {
    $destination = trim((string)($booking['package_name'] ?? ''));
}
$destination = preg_replace('/\s*,\s*/u', ', ', $destination) ?? $destination;

$pages = array_chunk($tourists, 12);
if (!$pages) {
    $pages = [[]];
}

foreach ($pages as $pageIndex => $passengers) {
    $pdf->AddPage();
    manifestHeader($pdf);

    $pdf->SetXY(11, 57);
    $pdf->SetFont('times', 'B', 10.5);
    $pdf->Cell(40, 6, 'Name of FBCA/MBCA:', 0, 0, 'L');
    $pdf->SetFont('times', '', 10.5);
    $pdf->Cell(80, 6, manifestText($vesselNameWithType), 'B', 1, 'L');
    $pdf->SetX(11);
    $pdf->SetFont('times', 'B', 10.5);
    $pdf->Cell(40, 6, 'Destination:', 0, 0, 'L');
    $pdf->SetFont('times', '', 10.5);
    $pdf->Cell(80, 6, manifestText($destination), 'B', 1, 'L');

    $pdf->SetY(77);
    $pdf->SetFont('times', 'B', 15);
    $pdf->Cell(0, 8, 'PASSENGER MANIFEST', 0, 1, 'C');
    if (count($pages) > 1) {
        $pdf->SetFont('times', '', 8);
        $pdf->Cell(0, 4, 'Page ' . ($pageIndex + 1) . ' of ' . count($pages), 0, 1, 'C');
    }

    $columns = [
        ['label' => '#', 'width' => 9, 'align' => 'C'],
        ['label' => 'GUEST NAME', 'width' => 48, 'align' => 'L'],
        ['label' => 'ADDRESS', 'width' => 65, 'align' => 'L'],
        ['label' => 'GENDER', 'width' => 23, 'align' => 'C'],
        ['label' => 'AGE', 'width' => 14, 'align' => 'C'],
        ['label' => 'CONTACT #', 'width' => 31, 'align' => 'C'],
    ];
    $left = 10.0;
    $y = $pdf->GetY() + 2;
    $x = $left;
    $pdf->SetFillColor(245, 245, 245);
    $pdf->SetDrawColor(35, 35, 35);
    $pdf->SetLineWidth(0.3);
    $pdf->SetFont('times', 'B', 9);
    foreach ($columns as $column) {
        $pdf->MultiCell($column['width'], 8, $column['label'], 1, 'C', true, 0, $x, $y, true, 0, false, true, 8, 'M');
        $x += $column['width'];
    }
    $y += 8;

    $rowsToDraw = count($passengers);
    for ($rowIndex = 0; $rowIndex < $rowsToDraw; $rowIndex++) {
        $passenger = $passengers[$rowIndex] ?? null;
        $globalNumber = $pageIndex * 12 + $rowIndex + 1;
        $values = $passenger ? [
            (string)$globalNumber,
            manifestText($passenger['full_name'] ?? ''),
            manifestText($passenger['address'] ?? ''),
            ucfirst(manifestText($passenger['gender'] ?? '')),
            ($passenger['age'] === null || $passenger['age'] === '') ? '-' : (string)(int)$passenger['age'],
            manifestText($passenger['phone_number'] ?? ''),
        ] : [(string)$globalNumber, '', '', '', '', ''];

        $pdf->SetFont('times', '', 8);
        $rowHeight = 8.0;
        if ($passenger) {
            foreach ($columns as $columnIndex => $column) {
                $lines = $pdf->getNumLines($values[$columnIndex], $column['width'] - 2);
                $rowHeight = max($rowHeight, min(14.0, $lines * 3.4 + 2));
            }
        }
        if ($y + $rowHeight > 222) {
            break;
        }

        $x = $left;
        foreach ($columns as $columnIndex => $column) {
            $pdf->MultiCell($column['width'], $rowHeight, $values[$columnIndex], 1, $column['align'], false, 0, $x, $y, true, 0, false, true, $rowHeight, 'M');
            $x += $column['width'];
        }
        $y += $rowHeight;
    }

    manifestFooter($pdf, $y);
}

$filename = 'passenger_manifest_' . preg_replace('/[^A-Za-z0-9_-]/', '_', (string)($booking['booking_reference'] ?: $bookingId)) . '.pdf';
$pdf->Output($filename, 'I');
ob_end_flush();
