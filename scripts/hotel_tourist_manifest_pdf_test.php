<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../php/booking_tourists_helper.php';
define('K_PATH_CACHE', str_replace('\\','/',sys_get_temp_dir()) . '/');
require_once __DIR__ . '/../tcpdf/tcpdf.php';
$bookingId = 42;
$booking = hotelBookingTouristNormalize(['hotel_booking_id'=>42,'booking_status'=>'confirmed','checkin_date'=>'2026-11-01','adults'=>1,'children'=>1,'booking_reference'=>'HR-FIXTURE-42']);
$booking['location']='Fixture Hotel'; $booking['package_name']='Family Room';
$passenger=['full_name'=>'Fixture Guest','gender'=>'female','age'=>29,'address'=>'Main Street, Mercedes','country'=>'Philippines','region'=>'Bicol','province'=>'Camarines Norte','city'=>'Mercedes','barangay'=>'San Roque','postal_code'=>'4601','street'=>'Main Street','residence'=>'philippines','phone_number'=>'09123456789','boat_number'=>null];
$passengers=[$passenger,array_merge($passenger,['full_name'=>'Fixture Child','age'=>10])];
$source=file_get_contents(__DIR__ . '/../php/tourist_submission_pdf.php');
$start=strpos($source,'function profilePdfText(');
$end=strpos($source,'$filename =');
if ($start===false || $end===false) throw new RuntimeException('PDF renderer not found.');
// Run the same renderer with synthetic hotel guests, excluding authentication/database/output.
eval(substr($source,$start,$end-$start));
$renderedPages=$pdf->getNumPages();
$output=$pdf->Output('fixture.pdf','S');
if (!str_starts_with($output,'%PDF-') || $renderedPages!==1 || $serviceLabel!=='Hotel' || $pax!==2) throw new RuntimeException('Hotel guest PDF render failed.');
echo "PASS: shared PDF renderer accepts hotel booking metadata, adult/child guests, and single-list pagination. No application data changed.\n";
