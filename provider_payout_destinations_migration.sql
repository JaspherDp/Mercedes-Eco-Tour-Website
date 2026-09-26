-- Manual provider payout destinations and immutable settlement snapshots.
-- The Earnings & Disbursements page also applies these additions defensively
-- for existing installations.

CREATE TABLE IF NOT EXISTS provider_payout_destinations (
  destination_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  provider_type VARCHAR(30) NOT NULL,
  provider_id INT NOT NULL,
  method VARCHAR(40) NOT NULL,
  institution VARCHAR(150) NOT NULL,
  account_name VARCHAR(190) NOT NULL,
  account_identifier_cipher TEXT NOT NULL,
  account_identifier_last4 VARCHAR(4) NOT NULL,
  account_identifier_hash CHAR(64) NOT NULL,
  is_default TINYINT(1) NOT NULL DEFAULT 1,
  created_by_admin_id INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (destination_id),
  UNIQUE KEY uq_provider_payout_destination
    (provider_type, provider_id, method, institution, account_identifier_hash),
  KEY idx_provider_payout_destination_default
    (provider_type, provider_id, is_default, updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE provider_payouts
  ADD COLUMN IF NOT EXISTS settlement_type VARCHAR(20) NULL AFTER settlement_method,
  ADD COLUMN IF NOT EXISTS settlement_destination_id BIGINT UNSIGNED NULL AFTER settlement_type,
  ADD COLUMN IF NOT EXISTS settlement_institution VARCHAR(150) NULL AFTER settlement_destination_id,
  ADD COLUMN IF NOT EXISTS settlement_account_name VARCHAR(190) NULL AFTER settlement_institution,
  ADD COLUMN IF NOT EXISTS settlement_destination_cipher TEXT NULL AFTER settlement_account_name,
  ADD COLUMN IF NOT EXISTS settlement_destination_last4 VARCHAR(4) NULL AFTER settlement_destination_cipher,
  ADD COLUMN IF NOT EXISTS settlement_destination_label VARCHAR(190) NULL AFTER settlement_destination_last4;
