<?php
session_start();

require_once __DIR__ . '/db_connection.php';
require_once __DIR__ . '/../tcpdf/tcpdf.php';

$booking_id = (int)($_GET['booking_id'] ?? 0);
if ($booking_id <= 0) exit('Invalid booking');

// FETCH BOOKING DETAILS
$stmt = $pdo->prepare("
  SELECT booking_id, booking_date, booking_type, package_name, location,
         pax, num_adults, num_children, tour_type, tour_range, jump_off_port
  FROM bookings
  WHERE booking_id = ?
");
$stmt->execute([$booking_id]);
$booking = $stmt->fetch(PDO::FETCH_ASSOC);

// FETCH TOURISTS
$stmt = $pdo->prepare("
  SELECT full_name, gender, residence, phone_number
  FROM booking_tourists
  WHERE booking_id = ?
");
$stmt->execute([$booking_id]);
$tourists = $stmt->fetchAll(PDO::FETCH_ASSOC);

$pax = $booking['pax'] ?? (($booking['num_adults'] ?? 0) + ($booking['num_children'] ?? 0));

// === Tourist statistics ===
$totalMale = 0;
$totalFemale = 0;
$totalLocal = 0;     // residence = Philippines
$totalForeigner = 0; // residence = Foreign

// === START OUTPUT BUFFERING ===
ob_start();
error_reporting(E_ALL & ~E_WARNING & ~E_NOTICE);

// TCPDF setup
$pdf = new TCPDF('P','mm','A4');
$pdf->SetCreator('iTour Mercedes');
$pdf->SetAuthor('iTour Mercedes');
$pdf->SetTitle('Tourist Manifest');
$pdf->SetMargins(15, 18, 15);
$pdf->SetAutoPageBreak(TRUE, 18);
$pdf->AddPage();

// Page width and margins
$pageWidth = $pdf->getPageWidth();
$leftMargin = $pdf->getMargins()['left'];
$rightMargin = $pdf->getMargins()['right'];
$usableWidth = $pageWidth - $leftMargin - $rightMargin;

// Logos
$logoWidth = 25;
$logoHeight = 25;
$logoTextGap = 5; // small gap between logos and text

$mercedesLogo = realpath(__DIR__ . '/../img/mercedeslogo.png');
$tourismLogo  = realpath(__DIR__ . '/../img/TourismLogo.png');

// Header text
$headerLines = [
    ['text' => 'Republic of the Philippines', 'font' => 'B', 'size' => 12],
    ['text' => 'Province of Camarines Norte', 'font' => '', 'size' => 12],
    ['text' => 'Municipality of Mercedes', 'font' => '', 'size' => 12]
];

// Calculate total width: logos + gaps + text
$pdf->SetFont('times','',12);
$textWidths = [];
foreach ($headerLines as $line) {
    $pdf->SetFont('times', $line['font'], $line['size']);
    $textWidths[] = $pdf->GetStringWidth($line['text']);
}
$maxTextWidth = max($textWidths);
$totalBlockWidth = $logoWidth + $logoTextGap + $maxTextWidth + $logoTextGap + $logoWidth;

// Starting X to center entire block
$startX = ($pageWidth - $totalBlockWidth)/2;
$headerY = 15;

// LEFT LOGO
if ($mercedesLogo && file_exists($mercedesLogo)) {
    @$pdf->Image($mercedesLogo, $startX, $headerY, $logoWidth, $logoHeight);
}

// HEADER TEXT
$textX = $startX + $logoWidth + $logoTextGap;
$textY = $headerY + 2;
foreach ($headerLines as $line) {
    $pdf->SetFont('times', $line['font'], $line['size']);
    $pdf->SetXY($textX, $textY);
    $pdf->Cell($maxTextWidth, 6, $line['text'], 0, 1, 'C');
    $textY += 6;
}

// RIGHT LOGO
$rightLogoX = $textX + $maxTextWidth + $logoTextGap;
if ($tourismLogo && file_exists($tourismLogo)) {
    @$pdf->Image($tourismLogo, $rightLogoX, $headerY, $logoWidth, $logoHeight);
}

// Divider below header
$pdf->Ln(1 + ($logoHeight - 18));
$pdf->SetLineWidth(0.5);
$pdf->Line($leftMargin, $pdf->GetY(), $pageWidth - $rightMargin, $pdf->GetY());
$pdf->Ln(5);

// Booking Details
$pdf->SetFont('times', 'B', 13);
$pdf->SetTextColor(46, 125, 102);
$pdf->Cell(0, 6, 'Booking Details', 0, 1, 'L');
$pdf->Ln(2);

$pdf->SetFont('times', '', 11);
$pdf->SetTextColor(0,0,0);

$pdf->SetFillColor(230, 230, 230);
$pdf->SetLineWidth(0.3);

$details = [
    ['Booking ID:', $booking['booking_id'], 'Booking Date:', $booking['booking_date']],
    ['Booking Type:', $booking['booking_type'], 'Jump-off Port:', $booking['jump_off_port']],
    ['Package / Location:', $booking['package_name'].' '.$booking['location'], '', ''],
    ['Tour Type:', $booking['tour_type'], 'Tour Range:', $booking['tour_range']],
    ['Total Pax:', $pax, '', '']
];

foreach ($details as $row) {
    $pdf->SetFont('times','B',11);
    $pdf->Cell(50,6,$row[0],0,0);
    $pdf->SetFont('times','',11);
    $pdf->Cell(50,6,$row[1],0,0);
    if($row[2]) {
        $pdf->SetFont('times','B',11);
        $pdf->Cell(50,6,$row[2],0,0);
        $pdf->SetFont('times','',11);
        $pdf->Cell(50,6,$row[3],0,1);
    } else {
        $pdf->Ln(6);
    }
}

$pdf->Ln(4);

// Tourist List
$pdf->SetFont('times','B',13);
$pdf->SetTextColor(46,125,102);
$pdf->Cell(0,6,'Tourist List',0,1,'L');
$pdf->Ln(2);

// Set column widths proportionally
$colWidths = [
    0.06 * $usableWidth, // # 
    0.34 * $usableWidth, // Full Name
    0.15 * $usableWidth, // Gender
    0.20 * $usableWidth, // Residence
    0.25 * $usableWidth  // Phone
];

// Table header
$pdf->SetFont('times','B',11);
$pdf->SetFillColor(46, 125, 102);
$pdf->SetTextColor(255,255,255);

$pdf->Cell($colWidths[0],7,'#',1,0,'C',true);
$pdf->Cell($colWidths[1],7,'Full Name',1,0,'C',true);
$pdf->Cell($colWidths[2],7,'Gender',1,0,'C',true);
$pdf->Cell($colWidths[3],7,'Residence',1,0,'C',true);
$pdf->Cell($colWidths[4],7,'Phone',1,1,'C',true);

$pdf->SetFont('times','',11);
$pdf->SetTextColor(0,0,0);

// Tourist rows + counting
foreach ($tourists as $i => $t) {
    // Count gender
    if (strtolower($t['gender']) === 'male') $totalMale++;
    elseif (strtolower($t['gender']) === 'female') $totalFemale++;

    // Count residence
    if (strtolower(trim($t['residence'])) === 'philippines') $totalLocal++;
    else $totalForeigner++;

    // Table row
    $pdf->Cell($colWidths[0],6,$i+1,1,0,'C');
    $pdf->Cell($colWidths[1],6,$t['full_name'],1,0);
    $pdf->Cell($colWidths[2],6,$t['gender'],1,0,'C');
    $pdf->Cell($colWidths[3],6,$t['residence'],1,0);
    $pdf->Cell($colWidths[4],6,$t['phone_number'],1,1);
}

// Tourist Summary (Left side below table)
$pdf->Ln(4);
$summaryX = $leftMargin; // left side
$pdf->SetXY($summaryX, $pdf->GetY());

$pdf->SetFont('times','B',12);
$pdf->SetTextColor(46,125,102);
$pdf->Cell(0,6,'Tourist Summary',0,1,'L');
$pdf->Ln(2);

$pdf->SetFont('times','',11);
$pdf->SetTextColor(0,0,0);
$cellLabelWidth = 50;
$cellValueWidth = 30;
$cellHeight = 6;
$pdf->SetFillColor(245,245,245);
$pdf->SetLineWidth(0.3);

$pdf->Cell($cellLabelWidth, $cellHeight,'Total Tourists',1,0,'L',true);
$pdf->Cell($cellValueWidth, $cellHeight,count($tourists),1,1);

$pdf->Cell($cellLabelWidth, $cellHeight,'Local (Philippines)',1,0,'L',true);
$pdf->Cell($cellValueWidth, $cellHeight,$totalLocal,1,1);

$pdf->Cell($cellLabelWidth, $cellHeight,'Foreigner',1,0,'L',true);
$pdf->Cell($cellValueWidth, $cellHeight,$totalForeigner,1,1);

$pdf->Cell($cellLabelWidth, $cellHeight,'Male',1,0,'L',true);
$pdf->Cell($cellValueWidth, $cellHeight,$totalMale,1,1);

$pdf->Cell($cellLabelWidth, $cellHeight,'Female',1,0,'L',true);
$pdf->Cell($cellValueWidth, $cellHeight,$totalFemale,1,1);

// Footer note
$pdf->Ln(5);
$pdf->SetFont('times','I',9);
$pdf->SetTextColor(100,100,100);
$pdf->Cell(0,5,'This document is system-generated and is valid without signature.',0,1,'C');

// Output PDF
$pdf->Output("tourist_manifest_{$booking_id}.pdf", "I");

// End output buffering
ob_end_flush();
