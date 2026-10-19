<?php
declare(strict_types=1);
require_once __DIR__.'/booking_cancellations_helper.php';
require_once __DIR__.'/input_validation.php';

function HotelCancellationMainAdminCanDecide(array $request): bool
{
    return strtolower((string)($request['booking_domain']??'')) !== 'hotel';
}

function HotelCancellationReview(PDO $pdo, int $propertyId, int $hotelAdminId, int $requestId, string $decision, string $note = ''): array
{
    if ($propertyId<1 || $hotelAdminId<1 || $requestId<1 || !in_array($decision,['approve','reject'],true)) throw new DomainException('Invalid cancellation review.');
    $note=ItourValidationText($note,'Review reason',1500,$decision==='reject');
    if($pdo->inTransaction())throw new LogicException('Cancellation review requires its own transaction.');
    $lock=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':'';
    try {
        $pdo->beginTransaction();
        // Find only this property's request, then acquire locks in booking/request order.
        $lookup=$pdo->prepare("SELECT cr.booking_id FROM booking_cancellation_requests cr JOIN hotel_room_bookings b ON b.hotel_booking_id=cr.booking_id WHERE cr.cancellation_request_id=? AND cr.booking_domain='hotel' AND b.hotel_resort_id=?");
        $lookup->execute([$requestId,$propertyId]);$bookingId=(int)$lookup->fetchColumn();
        if(!$bookingId)throw new DomainException('Cancellation request not found for this property.');
        $q=$pdo->prepare('SELECT * FROM hotel_room_bookings WHERE hotel_booking_id=? AND hotel_resort_id=?'.$lock);$q->execute([$bookingId,$propertyId]);$booking=$q->fetch(PDO::FETCH_ASSOC);
        $q=$pdo->prepare("SELECT * FROM booking_cancellation_requests WHERE cancellation_request_id=? AND booking_domain='hotel' AND booking_id=?".$lock);$q->execute([$requestId,$bookingId]);$request=$q->fetch(PDO::FETCH_ASSOC);
        if(!$booking||!$request||(int)$request['tourist_id']!==(int)$booking['tourist_id'])throw new DomainException('Cancellation request does not match the booking.');
        if(($request['initiated_by']??'tourist')!=='tourist')throw new DomainException('Provider cancellation requests do not require this review.');
        $target=$decision==='approve'?'approved':'rejected';
        if($request['request_status']===$target){$pdo->commit();return ['request'=>$request,'booking'=>$booking,'changed'=>false];}
        if($request['request_status']!=='pending')throw new DomainException('This cancellation request has already been reviewed.');
        if(!in_array(strtolower((string)$booking['booking_status']),['pending','confirmed'],true)||!empty($booking['checked_in_at'])||!empty($booking['checked_out_at']))throw new DomainException('This booking can no longer be cancelled before arrival.');
        if($decision==='approve'){
            $active=$pdo->prepare("SELECT COUNT(*) FROM payment_transactions WHERE booking_domain='hotel' AND booking_id=? AND status='pending'");$active->execute([$bookingId]);
            if((int)$active->fetchColumn()>0)throw new DomainException('Finish or cancel the active online payment before approving this cancellation.');
            $paid=max(0,round((float)$booking['amount_paid'],2));$total=max((float)$booking['total_amount'],$paid+max(0,(float)$booking['remaining_balance']));
            $requested=new DateTimeImmutable((string)$request['requested_at'],new DateTimeZone('Asia/Manila'));
            $policy=bookingCancellationRefundPolicy((string)$booking['checkin_date'],$total,$paid,$requested);
            $u=$pdo->prepare("UPDATE booking_cancellation_requests SET request_status='approved',refund_status=?,amount_paid=?,total_amount=?,days_before_service=?,refund_policy=?,refundable_amount=?,non_refundable_amount=?,admin_note=?,reviewed_by_admin_id=NULL,reviewed_at=CURRENT_TIMESTAMP,refund_updated_at=CURRENT_TIMESTAMP,refund_request_created_at=CURRENT_TIMESTAMP WHERE cancellation_request_id=? AND request_status='pending'");
            $u->execute([$policy['refundable_amount']>0.009?'pending':'not_applicable',$paid,$total,$policy['days_before_service'],$policy['refund_policy'],$policy['refundable_amount'],$policy['non_refundable_amount'],$note?:null,$requestId]);
            $u=$pdo->prepare("UPDATE hotel_room_bookings SET booking_status='cancelled',updated_at=CURRENT_TIMESTAMP WHERE hotel_booking_id=? AND hotel_resort_id=?");$u->execute([$bookingId,$propertyId]);
        }else{
            $u=$pdo->prepare("UPDATE booking_cancellation_requests SET request_status='rejected',refund_status='not_started',admin_note=?,reviewed_by_admin_id=NULL,reviewed_at=CURRENT_TIMESTAMP WHERE cancellation_request_id=? AND request_status='pending'");$u->execute([$note,$requestId]);
        }
        $q->execute([$requestId,$bookingId]);$request=$q->fetch(PDO::FETCH_ASSOC);$pdo->commit();
        return ['request'=>$request,'booking'=>$booking,'changed'=>true];
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}

function HotelCancellationList(PDO $pdo, int $propertyId, string $filter = '', string $search = '', int $page = 1, int $size = 25): array
{
    if($propertyId<1)throw new DomainException('Invalid property.');
    $where="cr.booking_domain='hotel' AND b.hotel_resort_id=?";$params=[$propertyId];
    if(in_array($filter,['pending','approved','rejected','completed'],true)){$where.=' AND cr.request_status=?';$params[]=$filter;}
    if($search!==''){$where.=' AND (cr.booking_reference LIKE ? OR cr.cancellation_reason LIKE ? OR t.full_name LIKE ? OR b.first_name LIKE ? OR b.last_name LIKE ?)';for($i=0;$i<5;$i++)$params[]='%'.$search.'%';}
    $from=' FROM booking_cancellation_requests cr JOIN hotel_room_bookings b ON b.hotel_booking_id=cr.booking_id LEFT JOIN tourist t ON t.tourist_id=cr.tourist_id WHERE '.$where;
    $q=$pdo->prepare('SELECT COUNT(*)'.$from);$q->execute($params);$total=(int)$q->fetchColumn();$size=max(1,min(100,$size));$pages=max(1,(int)ceil($total/$size));$page=max(1,min($pages,$page));
    $q=$pdo->prepare('SELECT cr.*,b.room_type,b.checkin_date,b.checkout_date,b.first_name,b.last_name,b.email AS booking_email,t.full_name,t.email'.$from.' ORDER BY cr.requested_at DESC,cr.cancellation_request_id DESC LIMIT '.$size.' OFFSET '.(($page-1)*$size));$q->execute($params);
    return ['rows'=>$q->fetchAll(PDO::FETCH_ASSOC),'total'=>$total,'page'=>$page,'pages'=>$pages];
}

function HotelCancellationDecisionContent(array $request, string $name): array
{
    require_once __DIR__.'/provider_cancellation_email.php';
    $approved=$request['request_status']==='approved';$reference=(string)$request['booking_reference'];
    $message=$approved?'Your hotel has approved your cancellation request and the reservation is cancelled.':'Your hotel has not approved your cancellation request. The reservation remains active.';
    $rows=['Booking reference'=>$reference,'Hotel / service'=>$request['service_name'],'Check-in'=>$request['service_date'],'Cancellation request'=>$approved?'Approved':'Not approved','Your cancellation reason'=>$request['cancellation_reason']];
    if($approved){$rows['Amount paid']='PHP '.number_format((float)$request['amount_paid'],2);$rows['Eligible refund']='PHP '.number_format((float)$request['refundable_amount'],2);$rows['Refund policy']=bookingCancellationPolicyLabel((string)$request['refund_policy']);$rows['Refund status']=(float)$request['refundable_amount']>0?'Pending processing by main admin':'No refund due';}
    if(!empty($request['admin_note']))$rows['Hotel review reason']=$request['admin_note'];
    $notice=$approved?'The main administrator handles any eligible refund. Hotel approval does not mean that the refund transfer is complete. If bank or e-wallet details are needed, follow the secure refund link or instructions sent by email during refund processing.':'Contact the hotel if you need clarification. Your booking has not been cancelled.';
    return ['subject'=>'iTour Mercedes | Hotel cancellation '.($approved?'approved':'not approved').' ('.$reference.')','html'=>bookingWorkflowEmailShell('HOTEL CANCELLATION',$approved?'Cancellation approved':'Cancellation not approved','Dear <strong>'.htmlspecialchars($name,ENT_QUOTES,'UTF-8').'</strong>,<br>'.$message,$rows,$notice),'plain'=>$message.' Booking '.$reference.'. Hotel review reason: '.($request['admin_note']??'').'. '.$notice];
}

function HotelCancellationNotify(PDO $pdo, array $result, ?callable $sender = null): bool
{
    if(!$result['changed'])return false;
    try{
        require_once __DIR__.'/booking_rejection_helper.php';require_once __DIR__.'/provider_cancellation_email.php';
        $guest=BookingRejectionRecipient($pdo,$result['request'],$result['booking']);$name=$guest['full_name']?:'Guest';$content=HotelCancellationDecisionContent($result['request'],$name);
        return $sender?(bool)$sender($guest,$content):sendBookingWorkflowEmail($guest['email'],$name,$content['subject'],$content['html'],$content['plain']);
    }catch(Throwable $e){error_log('Hotel cancellation decision email failed: '.get_class($e));return false;}
}

function HotelCancellationSummary(PDO $pdo, int $propertyId): array
{
    if($propertyId<1)throw new DomainException('Invalid property.');
    $q=$pdo->prepare("SELECT COUNT(*) total, COALESCE(SUM(CASE WHEN cr.request_status='pending' THEN 1 ELSE 0 END),0) pending, COALESCE(SUM(CASE WHEN cr.request_status='approved' THEN 1 ELSE 0 END),0) approved, COALESCE(SUM(CASE WHEN cr.request_status='approved' AND cr.refundable_amount>0 AND cr.refund_status NOT IN ('completed','refunded') THEN 1 ELSE 0 END),0) refund_required, COALESCE(SUM(CASE WHEN cr.request_status='completed' THEN 1 ELSE 0 END),0) completed FROM booking_cancellation_requests cr JOIN hotel_room_bookings b ON b.hotel_booking_id=cr.booking_id WHERE cr.booking_domain='hotel' AND b.hotel_resort_id=?");
    $q->execute([$propertyId]);return array_map('intval',$q->fetch(PDO::FETCH_ASSOC));
}
