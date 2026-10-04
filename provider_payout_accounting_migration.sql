-- REVIEW AND APPLY SEPARATELY. No historical amounts are updated.
-- Run once after checking SHOW COLUMNS FROM provider_payouts.
-- NULL net_amount identifies a legacy settlement whose recorded amount is gross_amount.
ALTER TABLE provider_payouts
  ADD COLUMN paymongo_fee_amount DECIMAL(12,2) NULL AFTER gross_amount,
  ADD COLUMN refunded_amount DECIMAL(12,2) NULL AFTER paymongo_fee_amount,
  ADD COLUMN net_amount DECIMAL(12,2) NULL AFTER refunded_amount;
-- Per-payment fee provenance uses the existing payment_transactions.metadata JSON.
