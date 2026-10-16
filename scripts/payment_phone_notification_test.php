<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__.'/../php/firebase_admin_messaging.php';
$source=file_get_contents(__DIR__.'/../php/firebase_admin_messaging.php');
$start=strpos($source,"        \$notificationTitle = 'Payment QR Ready';");
$end=strpos($source,"        \$fcmUrl =",$start);
if ($start===false || $end===false) throw new RuntimeException('Missing notification payload.');
$code=substr($source,$start,$end-$start);
$bookingReference='TB26-TEST'; $reference=$bookingReference; $amountMinor=300000; $transactionId=102;
$appUrl='https://example.test/'; $handoffUrl='https://example.test/admin-payment-handoff.php?token=test';
$device=['fcm_token'=>'synthetic-token'];
eval($code);
$first=$payload;
foreach (['image','icon','badge'] as $resource) {
    if (isset($first['message']['notification'][$resource]) || isset($first['message']['webpush']['notification'][$resource])) {
        throw new RuntimeException('Urgent QR alerts must not depend on downloading notification artwork.');
    }
}
eval($code);
if ($first['message']['webpush']['notification']['tag']===$payload['message']['webpush']['notification']['tag']) throw new RuntimeException('Resending the same payment must create a distinct phone notification.');
if ($first['message']['token']!==$payload['message']['token'] || $first['message']['data']['url']!==$payload['message']['data']['url']) throw new RuntimeException('Retries must target the same phone and payment handoff.');
if ($payload['message']['webpush']['fcm_options']['link']!==$handoffUrl || $payload['message']['webpush']['headers']['Urgency']!=='high') throw new RuntimeException('Native background notification and handoff must be preserved.');
echo "PASS: same registered-phone sender and handoff, fresh notification tags on resend, and native high-priority notification payload. No notification was sent.\n";
