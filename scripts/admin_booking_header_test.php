<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
// Render only the trusted header fragment: no database, account or network calls.
$source = file_get_contents(__DIR__ . '/../admin/adbookings.php');
$start = strpos($source, '<header class="admin-header admin-page-header admin-booking-header">');
$end = strpos($source, '</header>', $start);
if ($start === false || $end === false) throw new RuntimeException('Booking header not found.');
$template = substr($source, $start, $end - $start + strlen('</header>'));
$checks = 0;
function checkBookingHeader(bool $condition, string $message): void {
    global $checks;
    if (!$condition) throw new RuntimeException($message);
    $checks++;
}
foreach (['all','yearly','monthly','daily'] as $rangeFilter) {
    $activeTab='all'; $sortBy='recent'; $rowsPerPage=10; $statusFilter='all'; $search_q='';
    $years=[2026]; $selectedYear=2026; $selectedMonth=10; $selectedDate='2026-10-19';
    $admin_profile_picture=''; $admin_initial='W';
    ob_start(); eval('?>' . $template); $html=ob_get_clean();
    $document = new DOMDocument();
    @$document->loadHTML('<!doctype html><meta charset="utf-8">' . $html);
    $xpath = new DOMXPath($document);
    foreach (['adRangeYear'=>in_array($rangeFilter,['yearly','monthly','daily'],true), 'adRangeMonth'=>$rangeFilter==='monthly', 'adRangeDate'=>$rangeFilter==='daily'] as $id=>$visible) {
        $field=$document->getElementById($id);
        checkBookingHeader($field !== null && $field->hasAttribute('hidden') === !$visible, "$rangeFilter: $id must be correct before JavaScript runs.");
    }
    checkBookingHeader($xpath->query('//*[contains(concat(" ",normalize-space(@class)," ")," ap-header-notif-wrap ")]')->length===1, "$rangeFilter: one server-rendered notification control.");
    checkBookingHeader($xpath->query('//*[contains(concat(" ",normalize-space(@class)," ")," admin-header-profile ")]')->length===1, "$rangeFilter: profile space exists before DOMContentLoaded.");
    checkBookingHeader($document->getElementById('openReportModal') !== null, "$rangeFilter: report action preserved.");
    checkBookingHeader($document->getElementById('adRangeDate')->getAttribute('value')==='2026-10-19', "$rangeFilter: selected date preserved.");
}
echo "PASS: $checks first-render admin booking header assertions; no database or network access.\n";
