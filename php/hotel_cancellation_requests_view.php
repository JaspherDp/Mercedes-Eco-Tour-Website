<?php
// Included only after hotel authentication; all reads are scoped to the property.
$hcFilter=is_string($_GET['cancellation_status']??null)?$_GET['cancellation_status']:'';
$hcSearch=is_string($_GET['cancellation_search']??null)?mb_substr(trim($_GET['cancellation_search']),0,150):'';
$hcSize=max(1,min(100,(int)($_GET['cancellation_rows']??25)));
$hcData=HotelCancellationList($pdo,$hoHotelResortId,$hcFilter,$hcSearch,max(1,(int)($_GET['cancellation_page']??1)),$hcSize);
$hcSummary=HotelCancellationSummary($pdo,$hoHotelResortId);
$hcEscape=static fn($value):string=>htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8');
?>
<div class="hc-requests">
  <form method="get" class="hc-filters">
    <input type="hidden" name="tab" value="cancellations">
    <label class="hc-rows">Rows<input type="number" name="cancellation_rows" min="1" max="100" value="<?= $hcSize ?>"></label>
    <label><span class="hc-sr-only">Request status</span><select name="cancellation_status"><option value="">All requests</option><?php foreach(['pending'=>'Pending review','approved'=>'Approved','rejected'=>'Not approved','completed'=>'Completed']as$key=>$label):?><option value="<?= $key ?>" <?= $hcFilter===$key?'selected':'' ?>><?= $label ?></option><?php endforeach;?></select></label>
    <label class="hc-search"><span class="hc-sr-only">Search</span><input name="cancellation_search" value="<?= $hcEscape($hcSearch) ?>" placeholder="Guest, booking reference or reason"></label>
    <button type="submit" class="ho-btn confirm hc-filter-button">Apply Filter</button>
    <a class="ho-btn" href="Hobookings.php?tab=cancellations">Refresh</a>
  </form>
  <div class="hc-metrics" aria-label="Property cancellation summary">
    <?php foreach(['total'=>'All requests','pending'=>'Awaiting approval','approved'=>'Approved','refund_required'=>'Refund required','completed'=>'Completed']as$hcKey=>$hcLabel):?>
      <div><span><?= $hcLabel ?></span><strong><?= $hcSummary[$hcKey] ?></strong></div>
    <?php endforeach;?>
  </div>
  <div class="hc-table-scroll"><table class="ho-table hc-table"><thead><tr><th>Guest</th><th>Booking / room</th><th>Stay dates</th><th>Requested</th><th>Refund eligibility</th><th>Request status</th><th>Refund status</th><th>Actions</th></tr></thead><tbody>
  <?php foreach($hcData['rows']as$hcRequest):
    $hcStatus=strtolower((string)$hcRequest['request_status']);
    $hcClass=['pending'=>'pending','approved'=>'accepted','completed'=>'completed','rejected'=>'cancelled'][$hcStatus]??'pending';
    $hcRefund=(string)$hcRequest['refund_status'];
    $hcRefundClass=(float)$hcRequest['refundable_amount']<=0?'neutral':($hcStatus==='rejected'?'cancelled':(in_array($hcRefund,['completed','refunded'],true)?'completed':(in_array($hcRefund,['failed','declined'],true)?'cancelled':'pending')));
    $hcGuest=trim((string)($hcRequest['full_name']??''))?:trim($hcRequest['first_name'].' '.$hcRequest['last_name']);
    $hcCanReview=$hcStatus==='pending'&&($hcRequest['initiated_by']??'tourist')==='tourist';
  ?>
    <tr id="hotel-cancellation-<?= (int)$hcRequest['cancellation_request_id'] ?>" <?= (int)($_GET['focus_request']??0)===(int)$hcRequest['cancellation_request_id']?'class="hc-focused"':'' ?>>
      <td><strong><?= $hcEscape($hcGuest?:'Guest') ?></strong><small><?= $hcEscape($hcRequest['email']?:$hcRequest['booking_email']) ?></small></td>
      <td><strong><?= $hcEscape($hcRequest['booking_reference']) ?></strong><small><?= $hcEscape($hcRequest['room_type']) ?></small></td>
      <td><?= $hcEscape($hcRequest['checkin_date']) ?><small>to <?= $hcEscape($hcRequest['checkout_date']) ?></small></td>
      <td><?= $hcEscape(date('M j, Y',strtotime($hcRequest['requested_at']))) ?><small><?= $hcEscape(date('g:i A',strtotime($hcRequest['requested_at']))) ?></small></td>
      <td><strong>PHP <?= number_format((float)$hcRequest['refundable_amount'],2) ?></strong><small>of PHP <?= number_format((float)$hcRequest['amount_paid'],2) ?> paid</small><small><?= $hcEscape(bookingCancellationPolicyLabel($hcRequest['refund_policy'])) ?></small></td>
      <td><span class="ho-status <?= $hcClass ?>"><?= $hcEscape($hcStatus==='completed'?'Completed':bookingCancellationRequestStatusLabel($hcStatus)) ?></span></td>
      <td><span class="ho-status <?= $hcRefundClass ?>"><?= $hcEscape($hcStatus==='rejected'?'Cancellation not approved':bookingCancellationRefundStatusLabel($hcRefund,(float)$hcRequest['refundable_amount'])) ?></span></td>
      <td class="hc-action-cell"><div class="hc-actions">
        <button type="button" class="hc-action-toggle" data-hc-actions aria-haspopup="menu" aria-expanded="false" aria-controls="hc-actions-<?= (int)$hcRequest['cancellation_request_id'] ?>">Actions <svg class="hc-action-chevron" aria-hidden="true" viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg></button>
        <div class="hc-action-menu" id="hc-actions-<?= (int)$hcRequest['cancellation_request_id'] ?>" role="menu" hidden>
          <button type="button" role="menuitem" data-hc-reason="<?= $hcEscape(json_encode(['reference'=>$hcRequest['booking_reference'],'reason'=>$hcRequest['cancellation_reason'],'review_note'=>$hcRequest['admin_note']??'','non_refundable'=>(float)$hcRequest['non_refundable_amount']],JSON_UNESCAPED_UNICODE)) ?>">View reason</button>
          <?php if($hcCanReview): foreach(['approve'=>'Approve cancellation','reject'=>'Reject request']as$hcDecision=>$hcLabel):?>
          <form method="post" data-hotel-cancellation-decision="<?= $hcDecision ?>">
            <input type="hidden" name="action" value="hotel_cancellation_review"><input type="hidden" name="csrf_token" value="<?= $hcEscape($hotelBookingCsrf) ?>"><input type="hidden" name="cancellation_request_id" value="<?= (int)$hcRequest['cancellation_request_id'] ?>"><input type="hidden" name="decision" value="<?= $hcDecision ?>"><input type="hidden" name="review_note" value="">
            <button type="submit" role="menuitem" class="hc-menu-<?= $hcDecision ?>"><?= $hcLabel ?></button>
          </form>
          <?php endforeach; endif;?>
        </div>
      </div></td>
    </tr>
  <?php endforeach;?>
  <?php if(!$hcData['rows']):?><tr><td colspan="8" class="ho-empty">No cancellation requests match these filters.</td></tr><?php endif;?>
  </tbody></table></div>
  <footer class="hc-footer"><span><?= (int)$hcData['total'] ?> request(s) &middot; Page <?= $hcData['page'] ?> of <?= $hcData['pages'] ?></span><nav aria-label="Cancellation request pages"><?php foreach(['Previous'=>max(1,$hcData['page']-1),'Next'=>min($hcData['pages'],$hcData['page']+1)]as$hcLabel=>$hcPage):?><a class="ho-btn" <?= $hcPage===$hcData['page']?'aria-disabled="true" tabindex="-1"':'' ?> href="Hobookings.php?<?= $hcEscape(http_build_query(['tab'=>'cancellations','cancellation_status'=>$hcFilter,'cancellation_search'=>$hcSearch,'cancellation_rows'=>$hcSize,'cancellation_page'=>$hcPage])) ?>"><?= $hcLabel ?></a><?php endforeach;?></nav></footer>
</div>
