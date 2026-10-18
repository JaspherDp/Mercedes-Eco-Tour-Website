<?php
$feeScopeRows=array_filter($allRows,static fn($row)=>($stakeholderFilter==='all'||strtolower((string)$row['provider_type'])===$stakeholderFilter)&&($providerFilter==='all'||$row['provider_key']===$providerFilter));
$feeBookKeys=array_map(static fn($row)=>$row['booking_domain'].':'.$row['booking_id'],$feeScopeRows);
$feeCardTotals=array_fill_keys(['docking','entrance','environmental'],['collected'=>0,'settled'=>0,'available'=>0]);
foreach($thirdPartyPools as $pool){$balance=ThirdPartyFeeLedger::balance($pool,$feeBookKeys);foreach($balance as $name=>$amount)$feeCardTotals[$pool['category']][$name]+=$amount;}
?>
<section class="fee-collection-section" aria-label="Fee collections summary">
<div class="fee-collection-grid">
<?php foreach(['docking'=>['Docking / landing fees','M12 3v14M8 7h8M5 12H3a9 9 0 0 0 18 0h-2M8 17l4 4 4-4'],'entrance'=>['Entrance fees','M4 5h16v5a2 2 0 0 0 0 4v5H4v-5a2 2 0 0 0 0-4V5ZM14 5v3m0 3v2m0 3v3'],'environmental'=>['Environmental fees','M20 4c-9-2-16 2-16 8a7 7 0 0 0 7 7c6 0 10-7 9-15ZM4 21 16 9']] as $category=>[$title,$icon]):?>
<article class="payout-panel fee-collection-card <?= $category ?>">
<header><div class="fee-card-title"><span class="fee-card-icon"><svg viewBox="0 0 24 24"><path d="<?= $icon ?>"/></svg></span><div><small>FEE COLLECTIONS</small><h4><?= $title ?></h4></div></div></header>
<div class="fee-card-balances"><div class="fee-card-collected"><span>Collected</span><strong data-fee-card="<?= $category ?>:collected"><?= PayoutAccounting::money($feeCardTotals[$category]['collected']) ?></strong></div><div class="fee-card-split"><div><span>Settled</span><strong data-fee-card="<?= $category ?>:settled"><?= PayoutAccounting::money($feeCardTotals[$category]['settled']) ?></strong></div><div class="fee-card-available"><span>To settle</span><strong data-fee-card="<?= $category ?>:available"><?= PayoutAccounting::money($feeCardTotals[$category]['available']) ?></strong></div></div></div>
<footer><button type="button" class="fee-expand" data-fee-expand="<?= $category ?>">Expand <svg viewBox="0 0 24 24"><path d="m9 5 7 7-7 7"/></svg></button></footer>
</article><?php endforeach;?>
</div></section>
<div class="fee-modal" id="feeCollectionModal" hidden>
<div class="fee-modal-backdrop" data-fee-close></div>
<section role="dialog" aria-modal="true" aria-labelledby="feeModalTitle" tabindex="-1">
<header class="fee-modal-header"><div class="fee-header-title"><button type="button" class="fee-header-back" id="feeHeaderBack" data-fee-back aria-label="Back to collections" hidden><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m14 6-6 6 6 6M8 12h12"/></svg><span>Back</span></button><div><small>FEE COLLECTIONS</small><h3 id="feeModalTitle">Collection details</h3><p id="feeModalSubtitle">Current prices and recorded balances</p></div></div><nav id="feeCollectionTabs" class="fee-modal-tabs fee-header-tabs" aria-label="Fee categories"><button type="button" data-fee-tab="docking">Docking / landing</button><button type="button" data-fee-tab="entrance">Entrance</button><button type="button" data-fee-tab="environmental">Environmental</button></nav><button type="button" class="fee-modal-close" data-fee-close aria-label="Close fee collections">×</button></header>
<div class="fee-modal-content">
<div id="feeCollectionTableView">
<div class="fee-modal-summary" id="feeModalSummary"></div>
<?php if(!$thirdPartyReady):?><p class="fee-modal-notice">Payout recording will be available after the third-party payout table is installed.</p><?php endif;?>
<div class="fee-modal-table-wrap"><table class="fee-modal-table"><thead><tr><th>Location / collection</th><th>Current price</th><th class="right">Collected</th><th class="right">Settled</th><th class="right">To settle</th><th class="right">Actions</th></tr></thead><tbody id="feeCollectionRows"></tbody></table></div>
</div>
<div id="feeCollectionDetailView" hidden><div id="feeCollectionDetails"></div></div>
<div id="feeCollectionSettleView" hidden>
<form method="post" id="thirdPartySettlementForm" autocomplete="off">
<input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>"><input type="hidden" name="action" value="settle_third_party"><input type="hidden" name="fee_pool_key" id="feePoolKey"><input type="hidden" name="fee_booking_keys" id="feeBookingKeys"><input type="hidden" name="fee_request_token" value="<?= bin2hex(random_bytes(32)) ?>">
<div class="fee-settlement-overview"><div><span>Recipient location</span><strong id="feeSettlementLocation"></strong></div><div><span>Available to settle</span><strong id="feeSettlementAvailable"></strong></div></div>
<div class="fee-form-grid"><label><span>Amount to record <b>*</b></span><input type="number" name="fee_amount" id="feeSettlementAmount" min="0.01" step="0.01" required></label><label><span>Recipient / owner name <b>*</b></span><input type="text" name="fee_recipient" maxlength="190" placeholder="Who received the payment?" required></label></div>
<fieldset class="fee-method-options"><legend>How did you settle the payment?</legend><label><input type="radio" name="fee_method" value="cash" checked><span><strong>Cash</strong><small>Handed to the recipient</small></span></label><label><input type="radio" name="fee_method" value="online"><span><strong>Send money online</strong><small>Bank or e-wallet transfer</small></span></label></fieldset>
<div id="feeOnlineFields" class="fee-form-grid" hidden><label><span>Channel used <b>*</b></span><select name="fee_channel" id="feeChannel"><option value="">Select channel</option><option>GCash</option><option>Maya</option><option>Bank transfer</option><option>Other online channel</option></select></label><label id="feeCustomChannelLabel" hidden><span>Bank / channel name <b>*</b></span><input type="text" id="feeCustomChannel" maxlength="100" placeholder="e.g. BDO, BPI, UnionBank"></label></div>
<label><span id="feeReferenceLabel">Receipt / acknowledgment reference <em>Optional</em></span><input type="text" name="fee_reference" id="feeReference" maxlength="120" placeholder="Receipt or acknowledgment number"></label>
<label><span>Internal note <em>Optional</em></span><textarea name="fee_note" maxlength="500" rows="2" placeholder="Add any useful settlement details"></textarea></label>
<label class="fee-payment-confirm"><input type="checkbox" id="feePaymentConfirmed" name="fee_confirmed" value="1" required><span>I have already paid the recipient. This records the payout and does not send money.</span></label>
<p id="feeFormError" class="fee-form-error" role="alert" hidden></p>
<footer class="fee-form-footer"><button type="button" class="fee-secondary" data-fee-back>Cancel</button><button type="submit" class="fee-primary">Record as settled</button></footer>
</form></div>
</div><footer class="fee-modal-footer" id="feeCollectionModalFooter"><span>Manual payouts only · Amounts follow the workspace filters</span><button type="button" class="fee-secondary" data-fee-close>Close</button></footer>
</section></div>
