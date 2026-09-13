-- Foundation ledger for PayMongo and existing offline payment records.
-- This migration is intentionally not executed automatically.

CREATE TABLE IF NOT EXISTS payment_transactions (
    payment_transaction_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tourist_id INT UNSIGNED NOT NULL,
    booking_domain VARCHAR(20) NOT NULL COMMENT 'hotel, package, boat, or tourguide',
    booking_id INT NOT NULL,
    booking_reference VARCHAR(20) NULL,
    provider VARCHAR(30) NOT NULL DEFAULT 'paymongo',
    merchant_reference VARCHAR(100) NOT NULL,
    provider_checkout_session_id VARCHAR(100) NULL,
    provider_payment_intent_id VARCHAR(100) NULL,
    provider_payment_id VARCHAR(100) NULL,
    provider_event_id VARCHAR(100) NULL,
    idempotency_key VARCHAR(255) NOT NULL,
    return_token CHAR(64) NOT NULL,
    amount_minor INT UNSIGNED NOT NULL COMMENT 'Amount in centavos',
    currency CHAR(3) NOT NULL DEFAULT 'PHP',
    status VARCHAR(30) NOT NULL DEFAULT 'pending',
    payment_method_type VARCHAR(50) NULL,
    checkout_url TEXT NULL,
    failure_code VARCHAR(100) NULL,
    failure_message TEXT NULL,
    metadata JSON NULL,
    paid_at DATETIME NULL,
    failed_at DATETIME NULL,
    expires_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    active_booking_key VARCHAR(80) GENERATED ALWAYS AS (
        CASE
            WHEN status = 'pending'
            THEN CONCAT(booking_domain, ':', booking_id)
            ELSE NULL
        END
    ) STORED,
    PRIMARY KEY (payment_transaction_id),
    UNIQUE KEY uq_payment_merchant_reference (merchant_reference),
    UNIQUE KEY uq_payment_checkout_session (provider_checkout_session_id),
    UNIQUE KEY uq_payment_provider_payment (provider_payment_id),
    UNIQUE KEY uq_payment_provider_event (provider_event_id),
    UNIQUE KEY uq_payment_idempotency_key (idempotency_key),
    UNIQUE KEY uq_payment_return_token (return_token),
    UNIQUE KEY uq_payment_active_booking (active_booking_key),
    KEY idx_payment_tourist_created (tourist_id, created_at),
    KEY idx_payment_booking (booking_domain, booking_id),
    KEY idx_payment_booking_reference (booking_reference),
    KEY idx_payment_status_created (status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
