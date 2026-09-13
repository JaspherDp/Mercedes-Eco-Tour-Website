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
    // Administrators may view any travel registration form.
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
    SELECT b.booking_id, b.booking_reference, b.booking_date, b.created_at,
           b.booking_type, b.package_name, b.location, b.payment_amount,
           bo.name AS boat_name, tg.fullname AS guide_name,
           t.full_name AS leader_name
    FROM bookings b
    LEFT JOIN boats bo ON bo.boat_id = b.boat_id
    LEFT JOIN tour_guides tg ON tg.guide_id = b.guide_id
    LEFT JOIN tourist t ON t.tourist_id = b.tourist_id
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
    SELECT full_name, address, country, region, province, city, barangay,
           postal_code, street, residence, phone_number
    FROM booking_tourists
    WHERE booking_id = ?
    ORDER BY id ASC
");
$touristStmt->execute([$bookingId]);
$tourists = array_values(array_filter(
    $touristStmt->fetchAll(PDO::FETCH_ASSOC) ?: [],
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

function travelFormText($value): string
{
    return trim((string)$value);
}

function travelFormDate($value): string
{
    $timestamp = strtotime((string)$value);
    return $timestamp ? date('F j, Y', $timestamp) : '';
}

function travelFormDisplayFont(): string
{
    static $fontName = null;
    if ($fontName !== null) {
        return $fontName;
    }
    $fontName = 'dejavusanscondensed';
    $windowsDirectory = trim((string)getenv('WINDIR'));
    $impactPath = ($windowsDirectory !== '' ? $windowsDirectory : 'C:/Windows') . '/Fonts/impact.ttf';
    if (is_file($impactPath)) {
        try {
            $registeredFont = TCPDF_FONTS::addTTFfont($impactPath, 'TrueTypeUnicode', '', 32);
            if (is_string($registeredFont) && $registeredFont !== '') {
                $fontName = $registeredFont;
            }
        } catch (Throwable $error) {
            // The bundled condensed font below remains a portable fallback.
        }
    }
    return $fontName;
}

function travelFormLine(TCPDF $pdf, float $x, float $y, string $label, string $value, float $labelWidth, float $lineWidth): void
{
    $pdf->SetXY($x, $y);
    $pdf->SetFont('times', 'B', 9.2);
    $pdf->Cell($labelWidth, 6, $label, 0, 0, 'L');
    $pdf->SetFont('times', '', 9.2);
    $pdf->Cell($lineWidth, 6, $value, 'B', 0, 'L');
}

function travelFormHeader(TCPDF $pdf): void
{
    $tourismLogo = __DIR__ . '/../img/departmenttourism.png';
    $mercedesLogo = __DIR__ . '/../img/mercedeslogo.png';
    if (is_file($tourismLogo)) {
        $pdf->Image($tourismLogo, 30, 12, 27, 27, 'PNG');
    }
    if (is_file($mercedesLogo)) {
        $pdf->Image($mercedesLogo, 153, 12, 27, 27, 'PNG');
    }

    $pdf->SetTextColor(20, 20, 20);
    $pdf->SetXY(60, 13);
    $pdf->SetFont('helvetica', '', 9.5);
    foreach ([
        'Republic of the Philippines',
        'Department of Tourism',
        'Region V - Bicol',
        'Local Government Unit of Mercedes',
    ] as $line) {
        $pdf->Cell(90, 5.2, $line, 0, 1, 'C');
        $pdf->SetX(60);
    }

    $pdf->SetXY(10, 43);
    $pdf->SetTextColor(121, 50, 50);
    $pdf->SetFont('helvetica', 'B', 11.2);
    $pdf->Cell(190, 7, 'MERCEDES TOURISM DEVELOPMENT OPERATION CENTER', 0, 1, 'C');
    $pdf->SetTextColor(0, 0, 0);
    $titleFont = travelFormDisplayFont();
    $pdf->SetFont($titleFont, '', 19.5);
    if ($titleFont === 'dejavusanscondensed') {
        $pdf->SetFontStretching(78);
    }
    $pdf->Cell(190, 12, 'TRAVEL REGISTRATION FORM', 0, 1, 'C');
    $pdf->SetFontStretching(100);
}

function travelFormOfficialSection(TCPDF $pdf, string $leaderName, string $boatName, string $guideName): void
{
    $pdf->SetTextColor(0, 0, 0);
    $pdf->SetFont('times', '', 9.2);
    $pdf->SetXY(18, 207);
    $pdf->Cell(25, 6, 'Noted:', 0, 1, 'L');
    $pdf->SetXY(20, 220);
    $pdf->Cell(65, 6, $leaderName, 'B', 1, 'C');
    $pdf->SetX(20);
    $pdf->SetFont('times', 'B', 8.8);
    $pdf->Cell(65, 5, 'Leader of the Group', 0, 1, 'C');

    $pdf->SetFont('times', '', 9.2);
    $pdf->SetXY(18, 241);
    $pdf->Cell(25, 6, 'Approved:', 0, 1, 'L');
    $pdf->SetXY(20, 252);
    $pdf->SetFont('times', 'BU', 9.6);
    $pdf->Cell(65, 6, 'RUSTOM A. MARIANO', 0, 1, 'C');
    $pdf->SetX(20);
    $pdf->SetFont('times', 'B', 8.8);
    $pdf->Cell(65, 5, 'OIC, MTDOC', 0, 1, 'C');

    travelFormLine($pdf, 105, 206, 'Name of Motorboat:', $boatName, 36, 49);
    travelFormLine($pdf, 105, 216, 'Name of Boat Captain:', '', 40, 45);
    travelFormLine($pdf, 105, 226, 'Name of Tour Guide:', $guideName, 37, 48);
    travelFormLine($pdf, 105, 236, 'ETDF O.R number:', '', 33, 52);
    travelFormLine($pdf, 105, 246, 'Date:', '', 16, 69);
    travelFormLine($pdf, 105, 256, 'Amount:', '', 20, 65);
    travelFormLine($pdf, 105, 266, 'Assisted by:', '', 24, 61);

    $pdf->SetXY(10, 284);
    $pdf->SetFont('helvetica', 'B', 7.1);
    $pdf->Cell(190, 5, 'Mercedes Fish Port, Barangay 5, Mercedes, Camarines Norte, Bicol, Philippines - tourismoffice@mercedes.gov.ph - 09353064328', 0, 1, 'C');
}

class TravelRegistrationPdf extends TCPDF
{
    public function Header() {}
    public function Footer() {}
}

ob_start();
$pdf = new TravelRegistrationPdf('P', 'mm', 'A4', true, 'UTF-8', false);
$pdf->SetCreator('iTour Mercedes');
$pdf->SetAuthor('iTour Mercedes');
$pdf->SetTitle('Travel Registration Form - Booking ' . $bookingId);
$pdf->SetPrintHeader(false);
$pdf->SetPrintFooter(false);
$pdf->SetMargins(10, 10, 10);
$pdf->SetAutoPageBreak(false);

$destination = travelFormText($booking['location'] ?? '');
if ($destination === '') {
    $destination = travelFormText($booking['package_name'] ?? '');
}
$destination = preg_replace('/\s*,\s*/u', ', ', $destination) ?? $destination;
$leaderName = travelFormText($booking['leader_name'] ?? '');
if ($leaderName === '' && $tourists) {
    $leaderName = travelFormText($tourists[0]['full_name'] ?? '');
}
$boatName = travelFormText($booking['boat_name'] ?? '');
$guideName = travelFormText($booking['guide_name'] ?? '');
$formDate = travelFormDate($booking['created_at'] ?? '');
$travelDate = travelFormDate($booking['booking_date'] ?? '');

$pages = array_chunk($tourists, 8);
if (!$pages) {
    $pages = [[]];
}

foreach ($pages as $pageIndex => $passengers) {
    $pdf->AddPage();
    travelFormHeader($pdf);

    travelFormLine($pdf, 13, 70, 'Destination:', $destination, 25, 70);
    travelFormLine($pdf, 13, 79, 'Purpose:', 'ISLAND HOPPING', 25, 70);
    travelFormLine($pdf, 13, 88, 'Travel Date:', $travelDate, 25, 70);
    travelFormLine($pdf, 132, 64, 'Date:', $formDate, 14, 50);

    $pdf->SetXY(10, 101);
    $pdf->SetFont('times', 'B', 13.2);
    $pdf->Cell(190, 8, 'List of Tourists/Guests', 0, 1, 'C');

    $columns = [
        ['label' => '#', 'width' => 10, 'align' => 'C'],
        ['label' => 'NAME', 'width' => 65, 'align' => 'L'],
        ['label' => 'ADDRESS', 'width' => 75, 'align' => 'L'],
        ['label' => 'CONTACT NUMBER', 'width' => 40, 'align' => 'C'],
    ];
    $left = 10.0;
    $y = 110.0;
    $x = $left;
    $pdf->SetFillColor(247, 247, 247);
    $pdf->SetDrawColor(35, 35, 35);
    $pdf->SetLineWidth(0.3);
    $pdf->SetFont('times', 'B', 8.2);
    foreach ($columns as $column) {
        $pdf->MultiCell($column['width'], 9, $column['label'], 1, 'C', true, 0, $x, $y, true, 0, false, true, 9, 'M');
        $x += $column['width'];
    }
    $y += 9;

    foreach ($passengers as $rowIndex => $passenger) {
        $values = [
            (string)($pageIndex * 8 + $rowIndex + 1),
            travelFormText($passenger['full_name'] ?? ''),
            travelFormText($passenger['address'] ?? ''),
            travelFormText($passenger['phone_number'] ?? ''),
        ];
        $pdf->SetFont('times', '', 7.3);
        $rowHeight = 9.0;
        foreach ($columns as $columnIndex => $column) {
            $lines = $pdf->getNumLines($values[$columnIndex], $column['width'] - 2);
            $rowHeight = max($rowHeight, min(15.0, $lines * 3.5 + 2));
        }
        $x = $left;
        foreach ($columns as $columnIndex => $column) {
            $pdf->MultiCell($column['width'], $rowHeight, $values[$columnIndex], 1, $column['align'], false, 0, $x, $y, true, 0, false, true, $rowHeight, 'M');
            $x += $column['width'];
        }
        $y += $rowHeight;
    }

    if (count($pages) > 1) {
        $pdf->SetXY(10, 195);
        $pdf->SetFont('times', '', 8);
        $pdf->Cell(190, 5, 'Page ' . ($pageIndex + 1) . ' of ' . count($pages), 0, 1, 'C');
    }
    travelFormOfficialSection($pdf, $leaderName, $boatName, $guideName);
}

$reference = (string)($booking['booking_reference'] ?: $bookingId);
$filename = 'travel_registration_' . preg_replace('/[^A-Za-z0-9_-]/', '_', $reference) . '.pdf';
$pdf->Output($filename, 'I');
ob_end_flush();
