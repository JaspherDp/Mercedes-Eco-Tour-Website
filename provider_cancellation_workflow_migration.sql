ALTER TABLE booking_cancellation_requests
    ADD COLUMN IF NOT EXISTS initiated_by VARCHAR(30) NOT NULL DEFAULT 'tourist' AFTER booking_id,
    ADD COLUMN IF NOT EXISTS original_service_date DATE NULL AFTER service_date,
    ADD COLUMN IF NOT EXISTS rescheduled_service_date DATE NULL AFTER original_service_date,
    ADD COLUMN IF NOT EXISTS refund_request_created_at DATETIME NULL AFTER refund_status,
    ADD COLUMN IF NOT EXISTS reschedule_offered TINYINT(1) NOT NULL DEFAULT 0 AFTER cancellation_reason,
    ADD COLUMN IF NOT EXISTS reschedule_offered_at DATETIME NULL AFTER reschedule_offered,
    ADD COLUMN IF NOT EXISTS decision_deadline DATETIME NULL AFTER reschedule_offered_at,
    ADD COLUMN IF NOT EXISTS tourist_decision VARCHAR(30) NULL AFTER decision_deadline,
    ADD COLUMN IF NOT EXISTS tourist_responded_at DATETIME NULL AFTER tourist_decision,
    ADD COLUMN IF NOT EXISTS decision_expired_at DATETIME NULL AFTER tourist_responded_at;

CREATE INDEX IF NOT EXISTS idx_cancellation_deadline
    ON booking_cancellation_requests (request_status, decision_deadline);

-- Run every 5–15 minutes in production, for example:
-- php /path/to/project/scripts/process_reschedule_deadlines.php
