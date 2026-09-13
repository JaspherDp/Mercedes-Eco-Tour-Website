-- Allow a new Checkout Session after the previous attempt was cancelled,
-- failed, or expired. Only a pending transaction reserves a booking.
ALTER TABLE payment_transactions
    MODIFY COLUMN active_booking_key VARCHAR(80) GENERATED ALWAYS AS (
        CASE
            WHEN status = 'pending' THEN CONCAT(booking_domain, ':', booking_id)
            ELSE NULL
        END
    ) STORED;
