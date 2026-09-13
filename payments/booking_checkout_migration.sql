-- Pending booking details used by PayMongo booking-time checkout.
-- A draft is not shown to tourists, admins, operators, or hotel owners as a
-- booking. It is materialized only after a verified paid Checkout Session.
CREATE TABLE IF NOT EXISTS booking_checkout_drafts (
    booking_draft_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    tourist_id INT UNSIGNED NOT NULL,
    booking_domain VARCHAR(20) NOT NULL,
    payload JSON NOT NULL,
    total_minor INT UNSIGNED NOT NULL,
    amount_minor INT UNSIGNED NOT NULL,
    payment_type VARCHAR(20) NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'pending',
    booking_id INT NULL,
    booking_reference VARCHAR(20) NULL,
    expires_at DATETIME NOT NULL,
    submitted_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (booking_draft_id),
    KEY idx_booking_draft_tourist (tourist_id, created_at),
    KEY idx_booking_draft_status_expiry (status, expires_at),
    KEY idx_booking_draft_hotel_reservation (booking_domain, status, expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
