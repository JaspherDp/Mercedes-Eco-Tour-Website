<?php
declare(strict_types=1);

chdir(__DIR__ . '/..');
require_once __DIR__ . '/../php/session_security.php';
AppSessionStart();
require_once __DIR__ . '/../php/db_connection.php';
require_once __DIR__ . '/../php/admin_auth_helper.php';

AdminRequireLogin();

function tourismReportDate(string $value, DateTimeImmutable $fallback): DateTimeImmutable
{
    return DateTimeImmutable::createFromFormat('!Y-m-d', $value) ?: $fallback;
}

function tourismReportEmptyCounts(): array
{
    return array_fill(0, 15, 0);
}

function tourismReportAddPerson(array &$counts, string $residence, string $arrangement, string $gender): void
{
    $foreign = strtolower($residence) === 'foreign';
    $overnight = strtolower($arrangement) !== 'same-day';
    $female = strtolower($gender) === 'female';
    $index = ($foreign ? 6 : 0) + ($overnight ? 3 : 0) + ($female ? 1 : 0);
    $counts[$index]++;
}

function tourismReportFinalize(array $counts): array
{
    foreach ([0, 3, 6, 9] as $index) $counts[$index + 2] = $counts[$index] + $counts[$index + 1];
    $counts[12] = $counts[0] + $counts[3] + $counts[6] + $counts[9];
    $counts[13] = $counts[1] + $counts[4] + $counts[7] + $counts[10];
    $counts[14] = $counts[12] + $counts[13];
    return $counts;
}

function tourismReportIslandCatalog(): array
{
    return [
        'apuao pequena island' => 'Apuao Pequeña Island',
        'apuao grande island' => 'Apuao Grande Island',
        'quinapaguian island' => 'Quinapaguian Island',
        'canimog island' => 'Canimog Island',
        'caringo island' => 'Caringo Island',
        'malasugui island' => 'Malasugui Island',
        'canton island' => 'Canton Island',
    ];
}

function tourismReportIslandKey(string $name): ?string
{
    $normalized = mb_strtolower(trim(str_replace(['-', 'ñ', 'Ñ'], [' ', 'n', 'n'], $name)));
    $normalized = preg_replace('/\s+/', ' ', $normalized) ?: $normalized;
    foreach (tourismReportIslandCatalog() as $key => $_label) {
        $comparison = str_replace('ñ', 'n', $key);
        if ($normalized === $comparison || $normalized === str_replace(' island', '', $comparison)) return $key;
    }
    return null;
}

function tourismReportCapRows(array $rows, int $limit, string $overflowLabel): array
{
    $rows = array_values($rows);
    if (count($rows) <= $limit) return $rows;
    $visible = array_slice($rows, 0, $limit - 1);
    $overflow = tourismReportEmptyCounts();
    foreach (array_slice($rows, $limit - 1) as $row) {
        for ($i = 0; $i < 15; $i++) $overflow[$i] += (int)$row[$i];
    }
    $overflow['name'] = $overflowLabel;
    $visible[] = $overflow;
    return $visible;
}

function tourismReportCollect(PDO $pdo, string $from, string $to): array
{
    $destinationRows = [];
    foreach (tourismReportIslandCatalog() as $key => $name) {
        $destinationRows[$key] = tourismReportEmptyCounts();
        $destinationRows[$key]['name'] = $name;
    }
    $tourPeople = $pdo->prepare("SELECT b.location,b.tour_type,bt.gender,bt.residence FROM bookings b INNER JOIN booking_tourists bt ON bt.booking_id=b.booking_id AND LOWER(COALESCE(bt.booking_source,'tour'))='tour' WHERE b.booking_date BETWEEN ? AND ? AND LOWER(COALESCE(b.status,''))='accepted' AND LOWER(COALESCE(b.is_complete,''))='completed'");
    $tourPeople->execute([$from, $to]);
    foreach ($tourPeople->fetchAll(PDO::FETCH_ASSOC) as $person) {
        foreach (preg_split('/\s*,\s*/', trim((string)$person['location']), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $destination) {
            $key = tourismReportIslandKey($destination);
            if ($key === null || !isset($destinationRows[$key])) continue;
            tourismReportAddPerson($destinationRows[$key], (string)$person['residence'], (string)$person['tour_type'], (string)$person['gender']);
        }
    }

    $resortRows = [];
    $activeHotels = $pdo->query("SELECT name FROM hotel_resorts WHERE LOWER(COALESCE(status,'active'))='active' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($activeHotels as $hotelName) {
        $key = mb_strtolower(trim((string)$hotelName));
        $resortRows[$key] = tourismReportEmptyCounts();
        $resortRows[$key]['name'] = trim((string)$hotelName);
    }
    $hotelPeople = $pdo->prepare("SELECT TRIM(hr.name) name,bt.gender,bt.residence FROM hotel_room_bookings hb INNER JOIN hotel_resorts hr ON hr.hotel_resort_id=hb.hotel_resort_id AND LOWER(COALESCE(hr.status,'active'))='active' INNER JOIN booking_tourists bt ON bt.booking_id=hb.hotel_booking_id AND LOWER(COALESCE(bt.booking_source,''))='hotel' WHERE hb.checkin_date BETWEEN ? AND ? AND LOWER(COALESCE(hb.booking_status,''))='completed'");
    $hotelPeople->execute([$from, $to]);
    foreach ($hotelPeople->fetchAll(PDO::FETCH_ASSOC) as $person) {
        $key = mb_strtolower((string)$person['name']);
        if (!isset($resortRows[$key])) continue;
        tourismReportAddPerson($resortRows[$key], (string)$person['residence'], 'overnight', (string)$person['gender']);
    }

    foreach ($destinationRows as &$row) $row = tourismReportFinalize($row);
    unset($row);
    foreach ($resortRows as &$row) $row = tourismReportFinalize($row);
    unset($row);
    return [tourismReportCapRows($destinationRows, 8, 'OTHER DESTINATIONS'), tourismReportCapRows($resortRows, 23, 'OTHER RESORTS')];
}

function tourismReportTotals(array $destinations, array $resorts): array
{
    $totals = tourismReportEmptyCounts();
    foreach (array_merge($destinations, $resorts) as $row) for ($i = 0; $i < 15; $i++) $totals[$i] += (int)$row[$i];
    return $totals;
}

function tourismReportPeriodLabel(DateTimeImmutable $from, DateTimeImmutable $to): array
{
    if ($from->format('Y-m') === $to->format('Y-m')) return ['AS OF ' . strtoupper($to->format('F')), '(Month)'];
    return [strtoupper($from->format('M j') . ' - ' . $to->format('M j, Y')), '(Reporting Period)'];
}

function tourismReportSetCell(DOMDocument $document, DOMXPath $xpath, string $reference, string|int $value, bool $numeric = false): void
{
    $cell = $xpath->query('//x:c[@r="' . $reference . '"]')->item(0);
    if (!$cell instanceof DOMElement) return;
    while ($cell->firstChild) $cell->removeChild($cell->firstChild);
    if ($numeric) {
        $cell->removeAttribute('t');
        $cell->appendChild($document->createElementNS('http://schemas.openxmlformats.org/spreadsheetml/2006/main', 'v', (string)$value));
        return;
    }
    $cell->setAttribute('t', 'inlineStr');
    $inline = $document->createElementNS('http://schemas.openxmlformats.org/spreadsheetml/2006/main', 'is');
    $text = $document->createElementNS('http://schemas.openxmlformats.org/spreadsheetml/2006/main', 't');
    $text->appendChild($document->createTextNode((string)$value));
    $inline->appendChild($text);
    $cell->appendChild($inline);
}

function tourismReportPngAsJpeg(string $source): string
{
    $image = imagecreatefrompng($source);
    if (!$image) return '';
    $canvas = imagecreatetruecolor(imagesx($image), imagesy($image));
    $white = imagecolorallocate($canvas, 255, 255, 255);
    imagefill($canvas, 0, 0, $white);
    imagealphablending($canvas, true);
    imagecopy($canvas, $image, 0, 0, 0, 0, imagesx($image), imagesy($image));
    ob_start(); imagejpeg($canvas, null, 92); $bytes = (string)ob_get_clean();
    imagedestroy($image); imagedestroy($canvas);
    return $bytes;
}

function tourismReportFixExcelDrawing(string $drawingXml): string
{
    if ($drawingXml === '') return $drawingXml;
    $document = new DOMDocument();
    $document->preserveWhiteSpace = false;
    if (!$document->loadXML($drawingXml)) return $drawingXml;
    $xpath = new DOMXPath($document);
    $drawingNamespace = 'http://schemas.openxmlformats.org/drawingml/2006/spreadsheetDrawing';
    $drawingMainNamespace = 'http://schemas.openxmlformats.org/drawingml/2006/main';
    $xpath->registerNamespace('xdr', $drawingNamespace);
    $xpath->registerNamespace('a', $drawingMainNamespace);
    $anchors = [];
    foreach ($xpath->query('//xdr:twoCellAnchor') ?: [] as $anchor) $anchors[] = $anchor;
    if (count($anchors) < 2) return $drawingXml;
    $pictures = [
        $xpath->query('./xdr:pic', $anchors[0])->item(0),
        $xpath->query('./xdr:pic', $anchors[count($anchors) - 1])->item(0),
    ];
    $positions = [
        ['col' => 3, 'colOff' => 500000, 'row' => 0, 'rowOff' => 150000],
        ['col' => 8, 'colOff' => 0, 'row' => 0, 'rowOff' => 150000],
    ];
    $root = $document->documentElement;
    while ($root?->firstChild) $root->removeChild($root->firstChild);
    foreach ($pictures as $index => $picture) {
        if (!$picture instanceof DOMElement || !$root) continue;
        $anchor = $document->createElementNS($drawingNamespace, 'xdr:oneCellAnchor');
        $from = $document->createElementNS($drawingNamespace, 'xdr:from');
        foreach ($positions[$index] as $field => $value) {
            $node = $document->createElementNS($drawingNamespace, 'xdr:' . $field);
            $node->appendChild($document->createTextNode((string)$value));
            $from->appendChild($node);
        }
        $anchor->appendChild($from);
        $extent = $document->createElementNS($drawingNamespace, 'xdr:ext');
        $extent->setAttribute('cx', '620000');
        $extent->setAttribute('cy', '620000');
        $anchor->appendChild($extent);
        $picture = $picture->cloneNode(true);
        foreach ($xpath->query('.//a:srcRect', $picture) ?: [] as $crop) {
            while ($crop->attributes->length) $crop->removeAttributeNode($crop->attributes->item(0));
        }
        $offset = $xpath->query('.//a:xfrm/a:off', $picture)->item(0);
        if ($offset instanceof DOMElement) { $offset->setAttribute('x', '0'); $offset->setAttribute('y', '0'); }
        $pictureExtent = $xpath->query('.//a:xfrm/a:ext', $picture)->item(0);
        if ($pictureExtent instanceof DOMElement) { $pictureExtent->setAttribute('cx', '620000'); $pictureExtent->setAttribute('cy', '620000'); }
        $anchor->appendChild($picture);
        $anchor->appendChild($document->createElementNS($drawingNamespace, 'xdr:clientData'));
        $root->appendChild($anchor);
    }
    return $document->saveXML() ?: $drawingXml;
}

function tourismReportExcel(array $destinations, array $resorts, array $totals, DateTimeImmutable $from, DateTimeImmutable $to): never
{
    $template = __DIR__ . '/report_templates/tourist_arrivals_template.xlsx';
    if (!is_file($template)) { http_response_code(500); exit('Tourism report template is unavailable.'); }
    $temporary = tempnam(__DIR__ . '/report_templates', 'tourism-report-');
    if ($temporary === false || !copy($template, $temporary)) { http_response_code(500); exit('Unable to prepare the Excel report.'); }
    register_shutdown_function(static function () use ($temporary): void { if (is_file($temporary)) @unlink($temporary); });
    $zip = new ZipArchive();
    if ($zip->open($temporary) !== true) { http_response_code(500); exit('Unable to open the Excel report template.'); }
    $document = new DOMDocument();
    $document->preserveWhiteSpace = false;
    $document->loadXML((string)$zip->getFromName('xl/worksheets/sheet1.xml'));
    $xpath = new DOMXPath($document);
    $xpath->registerNamespace('x', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
    [$periodTitle, $periodCaption] = tourismReportPeriodLabel($from, $to);
    tourismReportSetCell($document, $xpath, 'A9', 'TOURIST ARRIVALS ' . $to->format('Y'));
    tourismReportSetCell($document, $xpath, 'A11', $periodTitle);
    tourismReportSetCell($document, $xpath, 'A12', $periodCaption);
    $columns = range('B', 'P');
    $writeRows = static function (array $rows, int $start, int $capacity) use ($document, $xpath, $columns): void {
        for ($offset = 0; $offset < $capacity; $offset++) {
            $rowNumber = $start + $offset;
            $row = $rows[$offset] ?? null;
            tourismReportSetCell($document, $xpath, 'A' . $rowNumber, $row ? strtoupper((string)$row['name']) : '');
            foreach ($columns as $index => $column) {
                if ($row) tourismReportSetCell($document, $xpath, $column . $rowNumber, (int)$row[$index], true);
                else tourismReportSetCell($document, $xpath, $column . $rowNumber, '');
            }
        }
    };
    $writeRows($destinations, 18, 8);
    $writeRows($resorts, 27, 23);
    foreach ($columns as $index => $column) tourismReportSetCell($document, $xpath, $column . '50', (int)$totals[$index], true);
    $zip->addFromString('xl/worksheets/sheet1.xml', $document->saveXML());
    $drawingXml = (string)$zip->getFromName('xl/drawings/drawing1.xml');
    if ($drawingXml !== '') $zip->addFromString('xl/drawings/drawing1.xml', tourismReportFixExcelDrawing($drawingXml));
    $leftLogo = tourismReportPngAsJpeg(__DIR__ . '/../img/mercedeslogo.png');
    if ($leftLogo !== '') $zip->addFromString('xl/media/image1.jpeg', $leftLogo);
    $zip->addFile(__DIR__ . '/../img/TourismLogo.png', 'xl/media/image2.png');
    $zip->close();
    $filename = 'Mercedes-Tourist-Arrivals-' . $from->format('Y-m-d') . '-to-' . $to->format('Y-m-d') . '.xlsx';
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . filesize($temporary));
    header('Cache-Control: private, no-store');
    readfile($temporary);
    exit;
}

function tourismReportPdf(array $destinations, array $resorts, array $totals, DateTimeImmutable $from, DateTimeImmutable $to, bool $inline): never
{
    require_once __DIR__ . '/../tcpdf/tcpdf.php';
    $temporaryLogos = [];
    foreach (['mercedeslogo.png', 'TourismLogo.png'] as $logoName) {
        $temporaryLogo = tempnam(__DIR__ . '/report_templates', 'tourism-logo-');
        if ($temporaryLogo !== false) {
            file_put_contents($temporaryLogo, tourismReportPngAsJpeg(__DIR__ . '/../img/' . $logoName));
            $temporaryLogos[] = $temporaryLogo;
        }
    }
    register_shutdown_function(static function () use (&$temporaryLogos): void { foreach ($temporaryLogos as $logo) if (is_file($logo)) @unlink($logo); });
    $pdf = new class('L', 'mm', 'LEGAL', true, 'UTF-8', false) extends TCPDF {
        public function Footer(): void
        {
            $margins = $this->getMargins();
            $pageWidth = $this->getPageWidth();
            $lineY = $this->getPageHeight() - 8.5;
            $this->SetDrawColor(90, 105, 99);
            $this->SetLineWidth(0.18);
            $this->Line((float)$margins['left'], $lineY, $pageWidth - (float)$margins['right'], $lineY);
            $this->SetY(-7.5);
            $this->SetX((float)$margins['left']);
            $this->SetTextColor(85, 96, 92);
            $this->SetFont('helvetica', '', 6.2);
            $availableWidth = $pageWidth - (float)$margins['left'] - (float)$margins['right'];
            $this->Cell($availableWidth - 25, 3.5, 'This is a system-generated report from iTour Mercedes. No signature is required.', 0, 0, 'L');
            $this->SetTextColor(30, 42, 38);
            $this->Cell(25, 3.5, $this->getAliasNumPage() . ' / ' . $this->getAliasNbPages(), 0, 0, 'R');
        }
    };
    $pdf->SetCreator('iTour Mercedes');
    $pdf->SetAuthor('Municipal Tourism Development Operation Center');
    $pdf->SetTitle('Tourist Arrivals ' . $to->format('Y'));
    $pdf->setPrintHeader(false); $pdf->setPrintFooter(true);
    $pdf->SetFooterMargin(7); $pdf->SetMargins(9, 7, 9); $pdf->SetAutoPageBreak(true, 11);
    $pdf->AddPage();
    if (isset($temporaryLogos[0])) $pdf->Image($temporaryLogos[0], 136, 7, 19, 19, 'JPG');
    if (isset($temporaryLogos[1])) $pdf->Image($temporaryLogos[1], 201, 7, 19, 19, 'JPG');
    $pdf->SetXY(157, 10);
    $pdf->writeHTMLCell(42, 14, 157, 10, '<div style="text-align:center;font-family:times;font-size:9pt"><b>Republic of the Philippines</b><br>Province of Camarines Norte<br>Municipality of Mercedes</div>', 0, 0, false, true, 'C', true);
    $pdf->SetFont('times', '', 9); $pdf->SetXY(90, 29); $pdf->MultiCell(176, 4, "MUNICIPAL TOURISM DEVELOPMENT OPERATION CENTER\n(MTDOC)", 0, 'C');
    $pdf->SetFont('times', 'B', 11); $pdf->SetXY(90, 39); $pdf->MultiCell(176, 5, 'TOURIST ARRIVALS ' . $to->format('Y'), 0, 'C');
    [$periodTitle, $periodCaption] = tourismReportPeriodLabel($from, $to);
    $pdf->SetFont('times', 'U', 9); $pdf->SetXY(90, 47); $pdf->MultiCell(176, 4, $periodTitle, 0, 'C');
    $pdf->SetFont('times', '', 8); $pdf->SetXY(90, 51); $pdf->MultiCell(176, 4, $periodCaption, 0, 'C');
    $cell = static fn(int $value): string => (string)$value;
    $rowHtml = static function (array $row) use ($cell): string {
        $html = '<tr><td class="name">' . htmlspecialchars(strtoupper((string)$row['name']), ENT_QUOTES, 'UTF-8') . '</td>';
        for ($i = 0; $i < 15; $i++) {
            $width = $i < 12 ? '5.3333%' : '4%';
            $class = in_array($i, [2,5,8,11,14], true) ? ' class="total"' : '';
            $html .= '<td' . $class . ' style="width:' . $width . '">' . $cell((int)$row[$i]) . '</td>';
        }
        return $html . '</tr>';
    };
    $rows = '';
    foreach ($destinations as $row) $rows .= $rowHtml($row);
    if (!$destinations) $rows .= '<tr><td class="name empty" colspan="16">NO RECORDED DESTINATION ARRIVALS</td></tr>';
    $rows .= '<tr><td class="section" colspan="16">RESORTS</td></tr>';
    foreach ($resorts as $row) $rows .= $rowHtml($row);
    if (!$resorts) $rows .= '<tr><td class="name empty" colspan="16">NO RECORDED RESORT ARRIVALS</td></tr>';
    $rows .= '<tr><td class="grand-label">TOTAL:</td>';
    for ($i = 0; $i < 15; $i++) {
        $width = $i < 12 ? '5.3333%' : '4%';
        $rows .= '<td style="width:' . $width . '" class="' . (in_array($i, [2,5,8,11,12,13,14], true) ? 'total' : '') . '">' . $cell((int)$totals[$i]) . '</td>';
    }
    $rows .= '</tr>';
    $residenceWidth = '5.3333%'; $grandWidth = '4%';
    $leafHeaders = '';
    foreach (['Male','Female','Total','Male','Female','Total','Male','Female','Total','Male','Female','Total'] as $label) $leafHeaders .= '<th style="width:' . $residenceWidth . '"' . ($label === 'Total' ? ' class="total"' : '') . '>' . $label . '</th>';
    foreach (['Male','Female','Total'] as $label) $leafHeaders .= '<th style="width:' . $grandWidth . '"' . ($label === 'Total' ? ' class="total"' : '') . '>' . $label . '</th>';
    $html = '<style>table{border-collapse:collapse;font-family:times;font-size:7pt}th,td{border:0.35mm solid #111;text-align:center;vertical-align:middle;padding:1.5px}.name{text-align:left;width:24%}.total{background-color:#c9c7c7;font-weight:bold}.section{font-size:10pt;font-weight:bold;text-align:left;background-color:#f1f1f1}.grand-label{text-align:right;width:24%;font-size:9pt;font-weight:bold;background-color:#c9c7c7}.empty{text-align:center;color:#666}thead th{font-weight:bold}</style><table cellpadding="1.5"><thead><tr><th style="width:24%">TOURIST DESTINATION</th><th colspan="12" style="width:64%">PLACE OF RESIDENCE</th><th rowspan="3" colspan="3" style="width:12%">Grand Total Number of Visitors</th></tr><tr><th rowspan="3" style="width:24%">Name</th><th colspan="6" style="width:32%">Philippines</th><th colspan="6" style="width:32%">Foreign Country</th></tr><tr><th colspan="3" style="width:16%">Same-Day</th><th colspan="3" style="width:16%">Overnight</th><th colspan="3" style="width:16%">Same-Day</th><th colspan="3" style="width:16%">Overnight</th></tr><tr>' . $leafHeaders . '</tr></thead><tbody>' . $rows . '</tbody></table>';
    $pdf->SetY(57); $pdf->writeHTML($html, true, false, true, false, '');
    $filename = 'Mercedes-Tourist-Arrivals-' . $from->format('Y-m-d') . '-to-' . $to->format('Y-m-d') . '.pdf';
    $pdf->Output($filename, $inline ? 'I' : 'D');
    exit;
}

$today = new DateTimeImmutable('today');
$fromDate = tourismReportDate((string)($_GET['from'] ?? ''), $today->modify('first day of this month'));
$toDate = tourismReportDate((string)($_GET['to'] ?? ''), $today);
if ($fromDate > $toDate) [$fromDate, $toDate] = [$toDate, $fromDate];
if ($toDate > $today) $toDate = $today;
if ($fromDate < $today->modify('-5 years')) $fromDate = $today->modify('-5 years');
[$destinations, $resorts] = tourismReportCollect($pdo, $fromDate->format('Y-m-d'), $toDate->format('Y-m-d'));
$totals = tourismReportTotals($destinations, $resorts);
$format = strtolower((string)($_GET['format'] ?? 'pdf'));
if ($format === 'xlsx') tourismReportExcel($destinations, $resorts, $totals, $fromDate, $toDate);
tourismReportPdf($destinations, $resorts, $totals, $fromDate, $toDate, strtolower((string)($_GET['disposition'] ?? 'inline')) !== 'download');
