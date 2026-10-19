<?php
declare(strict_types=1);
require __DIR__.'/booking_rejection_test.php';
require_once __DIR__.'/../php/notification_helper.php';
foreach (['pending'=>'booking_rejected','confirmed'=>'hotel_booking_cancelled'] as $status=>$marker) {
    $p=fixture();
    $p->exec("UPDATE hotel_room_bookings SET booking_status='$status'");
    $r=BookingRejectAndQueue($p,'hotel_admin',1,2,'Property unavailable <reason>',9);
    check($r['request']['tourist_decision']===$marker,'Decision follows locked hotel status');
    check($r['request']['request_status']==='approved','Existing refund queue preserved');
    check((float)$r['request']['refundable_amount']===(float)$r['booking']['amount_paid'],'Full paid refund preserved');
    $content=BookingRejectionEmailContent($r['request'],['full_name'=>'Guest']);
    $cancel=$status==='confirmed';
    check(str_contains($content['subject'],$cancel?'Booking Cancelled':'Booking Rejected'),'Correct email subject');
    check(str_contains($content['html'],$cancel?'previously accepted hotel booking':'pending booking'),'Clear acceptance state in email');
    check(str_contains($content['html'],$cancel?'Cancellation reason':'Rejection reason'),'Correct reason heading');
    check(str_contains($content['html'],'Property unavailable &lt;reason&gt;'),'Escaped guest reason');
    if($cancel)check(!str_contains(strtolower($content['html']),'reject'),'Accepted cancellation email does not say rejection');
    $repeat=BookingRejectAndQueue($p,'hotel_admin',1,2,'Repeated',9);
    check(!$repeat['created'],'Both decisions remain idempotent');
}
$events=NotificationEvents();
check($events['Hotel Booking Rejected'][0]==='booking_rejected','Pending rejection notification event');
check($events['Hotel Booking Cancelled'][0]==='booking_cancelled','Accepted cancellation notification event');
check($events['Hotel Booking Accepted'][1]==='Hotel Booking Accepted','Acceptance notification title');
echo "PASS: hotel pending rejection versus accepted cancellation, refund preservation, repeat protection, email and notification wording. No real emails or database writes.\n";
