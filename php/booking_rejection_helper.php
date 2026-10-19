<?php
declare(strict_types=1);
require_once __DIR__.'/booking_cancellations_helper.php';
require_once __DIR__.'/input_validation.php';

function BookingRejectionReason(array $input): string
{
    $reason = $input['rejection_reason'] ?? null;
    if ($reason === null) {
        $category = $input['decision_category'] ?? '';
        $note = $input['decision_note'] ?? '';
        if (!is_string($category) || !is_string($note)) throw new InvalidArgumentException('Enter a valid rejection reason.');
        $category = trim($category); $note = trim($note);
        if ($category === 'Other' && $note === '') throw new InvalidArgumentException('Enter the reason when selecting Other.');
        $reason = $category === 'Other' ? $note : ($category . ($note !== '' ? ' - '.$note : ''));
    }
    if (!is_string($reason) || trim($reason) === '' || mb_strlen(trim($reason)) > 1500) {
        throw new InvalidArgumentException('A rejection reason of 1 to 1,500 characters is required.');
    }
    return trim($reason);
}

/** Caller supplies authenticated role/owner IDs, never request-supplied owner IDs. */
function BookingRejectAndQueue(PDO $pdo, string $role, int $actorId, int $bookingId, string $reason, int $propertyId = 0): array
{
    if (!in_array($role, ['admin','operator','hotel_admin'], true) || $actorId < 1 || $bookingId < 1) throw new DomainException('Invalid booking access.');
    $reason = BookingRejectionReason(['rejection_reason'=>$reason]);
    if ($pdo->inTransaction()) throw new LogicException('Rejection requires its own transaction.');
    $hotel = $role === 'hotel_admin';
    $domain = $hotel ? 'hotel' : 'tour';
    $lock = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
    try {
        $pdo->beginTransaction();
        if ($hotel) {
            $q = $pdo->prepare('SELECT * FROM hotel_room_bookings WHERE hotel_booking_id=? AND hotel_resort_id=?'.$lock);
            $q->execute([$bookingId,$propertyId]);
        } else {
            $q = $pdo->prepare('SELECT * FROM bookings WHERE booking_id=?'.($role==='operator'?' AND operator_id=?':'').$lock);
            $q->execute($role==='operator'?[$bookingId,$actorId]:[$bookingId]);
        }
        $booking = $q->fetch(PDO::FETCH_ASSOC);
        if (!$booking) throw new DomainException('Booking not found or not assigned to this account.');
        $query = $pdo->prepare('SELECT * FROM booking_cancellation_requests WHERE booking_domain=? AND booking_id=?'.$lock);
        $query->execute([$domain,$bookingId]); $existing = $query->fetch(PDO::FETCH_ASSOC);
        $cancelled = $hotel ? strtolower((string)$booking['booking_status'])==='cancelled' : strtolower((string)$booking['is_complete'])==='cancelled';
        if ($existing && ($existing['tourist_decision']??'')==='booking_rejected' && $cancelled) {
            $pdo->commit(); return ['request'=>$existing,'booking'=>$booking,'created'=>false];
        }
        if ($hotel) {
            if (!in_array(strtolower((string)$booking['booking_status']),['pending','confirmed'],true) || !empty($booking['checked_in_at']) || !empty($booking['checked_out_at'])) throw new DomainException('Only pending or confirmed, not checked-in hotel bookings can be rejected.');
        } elseif (strtolower((string)$booking['status'])!=='pending' || strtolower((string)$booking['is_complete'])!=='uncomplete') {
            throw new DomainException('Only pending bookings can be rejected. Use the provider cancellation workflow for accepted bookings.');
        }
        $paymentDomain = $hotel ? 'hotel' : strtolower((string)$booking['booking_type']);
        if (!in_array($paymentDomain,['hotel','package','boat','tourguide'],true)) throw new DomainException('Unsupported booking type.');
        $active = $pdo->prepare("SELECT COUNT(*) FROM payment_transactions WHERE booking_domain=? AND booking_id=? AND status='pending'");
        $active->execute([$paymentDomain,$bookingId]);
        if ((int)$active->fetchColumn()>0) throw new DomainException('Finish or cancel the active online payment before rejecting this booking. No booking or refund was changed.');
        if ($existing && in_array(strtolower((string)$existing['request_status']),['approved','completed','rescheduled'],true)) throw new DomainException('This booking already has a processed cancellation request. Review that request first.');
        if ($existing && !in_array(strtolower((string)$existing['refund_status']),['not_started','not_applicable','pending'],true)) throw new DomainException('An existing refund must be reviewed before changing this cancellation.');
        if ($existing) {
            $attempts=$pdo->prepare('SELECT COUNT(*) FROM booking_refunds WHERE cancellation_request_id=?');$attempts->execute([(int)$existing['cancellation_request_id']]);
            if ((int)$attempts->fetchColumn()>0) throw new DomainException('Refund attempts already exist. Review the existing cancellation instead of replacing it.');
        }
        $paid = max(0,round((float)($hotel?$booking['amount_paid']:$booking['payment_amount']),2));
        $total = max($paid,round((float)($hotel?$booking['total_amount']:$booking['grand_total']),2),$paid+max(0,(float)$booking['remaining_balance']));
        $date = (string)($hotel?$booking['checkin_date']:$booking['booking_date']);
        $policy = bookingProviderFullRefund($paid);
        $days = bookingCancellationRefundPolicy($date,$total,$paid)['days_before_service'];
        $reference = trim((string)$booking['booking_reference']) ?: ($hotel?'HR-':'TOUR-').$bookingId;
        $service = $hotel ? (string)($booking['room_type']??'Hotel room') : (string)($booking['package_name']?:($booking['preferred_resource']?:$booking['location']));
        $record = ['tourist_id'=>(int)($booking['tourist_id']??0),'booking_domain'=>$domain,'booking_id'=>$bookingId,'initiated_by'=>'admin_provider','booking_reference'=>$reference,'booking_type'=>$hotel?'Hotel/Resort':$booking['booking_type'],'service_name'=>$service?:'Booking service','service_date'=>$date,'original_service_date'=>$date,'total_amount'=>$total,'amount_paid'=>$paid,'days_before_service'=>$days,'refund_policy'=>$policy['refund_policy'],'refundable_amount'=>$paid,'non_refundable_amount'=>0,'request_status'=>'approved','refund_status'=>$policy['refund_status'],'cancellation_reason'=>$reason,'admin_note'=>'Rejected by '.$role.' #'.$actorId,'reschedule_offered'=>0,'tourist_decision'=>'booking_rejected','reviewed_by_admin_id'=>$role==='admin'?$actorId:null];
        if ($existing) {
            $parts=[]; foreach(array_keys($record) as $column)$parts[]=$column.'=?';
            $save=$pdo->prepare('UPDATE booking_cancellation_requests SET '.implode(',',$parts).', reviewed_at=CURRENT_TIMESTAMP, refund_updated_at=CURRENT_TIMESTAMP, refund_request_created_at=CURRENT_TIMESTAMP, decision_deadline=NULL, rescheduled_service_date=NULL WHERE cancellation_request_id=?');
            $save->execute([...array_values($record),(int)$existing['cancellation_request_id']]);$requestId=(int)$existing['cancellation_request_id'];
        } else {
            $save=$pdo->prepare('INSERT INTO booking_cancellation_requests ('.implode(',',array_keys($record)).',reviewed_at,refund_updated_at,refund_request_created_at) VALUES ('.implode(',',array_fill(0,count($record),'?')).',CURRENT_TIMESTAMP,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP)');
            $save->execute(array_values($record)); $requestId=(int)$pdo->lastInsertId();
        }
        if ($hotel) {
            $update=$pdo->prepare("UPDATE hotel_room_bookings SET booking_status='cancelled',updated_at=CURRENT_TIMESTAMP WHERE hotel_booking_id=? AND hotel_resort_id=?");$update->execute([$bookingId,$propertyId]);
        } else {
            // Match the existing provider-cancellation state used by profile/refund views.
            $update=$pdo->prepare("UPDATE bookings SET status='accepted',is_complete='cancelled',dec_can_note=?,updated_at=CURRENT_TIMESTAMP WHERE booking_id=?");$update->execute(['Booking rejected - '.$reason,$bookingId]);
        }
        $query=$pdo->prepare('SELECT * FROM booking_cancellation_requests WHERE cancellation_request_id=?');$query->execute([$requestId]);$request=$query->fetch(PDO::FETCH_ASSOC);
        $pdo->commit(); return ['request'=>$request,'booking'=>$booking,'created'=>true];
    } catch (Throwable $e) { if($pdo->inTransaction())$pdo->rollBack();throw $e; }
}

function BookingRejectionRecipient(PDO $pdo, array $request, array $booking = []): array
{
    if (!$booking) {
        $hotel=($request['booking_domain']??'')==='hotel';
        $q=$pdo->prepare('SELECT * FROM '.($hotel?'hotel_room_bookings WHERE hotel_booking_id':'bookings WHERE booking_id').'=?');$q->execute([(int)$request['booking_id']]);$booking=$q->fetch(PDO::FETCH_ASSOC)?:[];
    }
    $q=$pdo->prepare('SELECT full_name,email FROM tourist WHERE tourist_id=?');$q->execute([(int)($request['tourist_id']??0)]);$tourist=$q->fetch(PDO::FETCH_ASSOC)?:[];
    return ['full_name'=>trim((string)($tourist['full_name']??'')) ?: (trim((string)($booking['walkin_guest_name']??'')) ?: trim(($booking['first_name']??'').' '.($booking['last_name']??''))), 'email'=>trim((string)($tourist['email']??'')) ?: trim((string)($booking['walkin_guest_email']??$booking['email']??''))];
}

function BookingRejectionNotify(PDO $pdo, array $result, ?callable $sender = null): bool
{
    if (!$result['created']) return (int)($result['request']['provider_email_sent_count']??0)>0;
    $request=$result['request'];
    try { require_once __DIR__.'/provider_cancellation_email.php'; $recipient=BookingRejectionRecipient($pdo,$request,$result['booking']); $sent=$sender ? $sender($request,$recipient) : sendBookingRejectedEmail($request,$recipient); }
    catch(Throwable $e){error_log('Booking rejection email failed: '.get_class($e));$sent=false;}
    try { if($sent){$q=$pdo->prepare('UPDATE booking_cancellation_requests SET provider_email_sent_count=provider_email_sent_count+1,provider_email_last_sent_at=CURRENT_TIMESTAMP WHERE cancellation_request_id=?');$q->execute([(int)$request['cancellation_request_id']]);} } catch(Throwable $e) { error_log('Rejection email delivery counter could not be saved.'); }
    return (bool)$sent;
}

/** Handle only the rejection/cancel decision, leaving other panel actions intact. */
function BookingRejectionPost(PDO $pdo, string $role, int $actorId, int $propertyId, string $actorName, string $redirect): never
{
    try {
        if (!AppVerifyCsrf($role, 'booking_management', $_POST['csrf_token']??null)) throw new RuntimeException('Your session expired. Refresh the page and try again.');
        $id=ItourValidationInt($_POST[$role==='admin'?'id':'booking_id']??null,'Booking ID',1,PHP_INT_MAX);
        $reason=BookingRejectionReason($_POST);
        $result=BookingRejectAndQueue($pdo,$role,$actorId,$id,$reason,$propertyId);
        $sent=BookingRejectionNotify($pdo,$result);
        if($result['created'])logActivity($pdo,$role==='admin'?'Admin':($role==='operator'?'Tour Operator':'Hotel Owner'),$actorId,$actorName,$role==='hotel_admin'?'Hotel Booking Rejected':'Booking Rejected','Rejected booking #'.$id.'. Reason: '.$reason.'. Approved cancellation request #'.$result['request']['cancellation_request_id'].'.',$role==='hotel_admin'?'Hotel Bookings':'Bookings',$id);
        $message='Booking cancelled. Cancellation request approved. '.((float)$result['request']['refundable_amount']>0?'The full amount paid is queued for refund processing.':'No payment to refund.');
        $message.=$sent?' The rejection email was sent with the reason.':' The rejection email was not sent. Use Resend Tourist Email in the main admin cancellation requests.';
        $_SESSION['booking_rejection_flash']=['icon'=>$sent?'success':'warning','message'=>$message];
    } catch(Throwable $e) {
        $_SESSION['booking_rejection_flash']=['icon'=>'error','message'=>!($e instanceof PDOException) && ($e instanceof DomainException||$e instanceof InvalidArgumentException||$e instanceof RuntimeException) ? $e->getMessage() : 'The booking could not be rejected. No cancellation was saved.'];
    }
    header('Location: '.$redirect);exit;
}
