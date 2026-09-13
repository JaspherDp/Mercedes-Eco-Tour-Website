-- Provider payout audit ledger for the single-account PayMongo collection model.
-- The application creates this table automatically; this script is provided for managed deployments.
CREATE TABLE IF NOT EXISTS provider_payouts (
  payout_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  booking_domain VARCHAR(20) NOT NULL,
  booking_id INT NOT NULL,
  booking_reference VARCHAR(20) NOT NULL,
  provider_type VARCHAR(30) NOT NULL,
  provider_id INT NULL,
  provider_key VARCHAR(80) NOT NULL,
  provider_name VARCHAR(190) NOT NULL,
  gross_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
  status VARCHAR(20) NOT NULL DEFAULT 'pending',
  approved_by_admin_id INT NULL,
  approved_at DATETIME NULL,
  settlement_method VARCHAR(40) NULL,
  settlement_reference VARCHAR(120) NULL,
  settlement_note VARCHAR(500) NULL,
  settled_by_admin_id INT NULL,
  settled_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (payout_id),
  UNIQUE KEY uq_provider_booking (booking_domain, booking_id, provider_key),
  KEY idx_payout_status (status),
  KEY idx_payout_provider (provider_type, provider_id),
  KEY idx_payout_settled (settled_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
