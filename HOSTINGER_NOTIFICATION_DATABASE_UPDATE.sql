-- Manual deployment only. Back up first; select the existing iTour database.
-- Additive and repeatable on MySQL 5.7+/8 and MariaDB 10.2+.
-- IF NOT EXISTS does not repair an incompatible table; compare SHOW CREATE TABLE.
-- No existing business data, columns, indexes, or foreign keys are changed.
CREATE TABLE IF NOT EXISTS app_notifications (
  notification_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  recipient_role VARCHAR(20) NOT NULL,
  recipient_id INT UNSIGNED NOT NULL,
  event_key CHAR(64) NOT NULL,
  event_type VARCHAR(50) NOT NULL,
  booking_domain VARCHAR(10) NOT NULL,
  booking_id INT UNSIGNED NOT NULL,
  title VARCHAR(100) NOT NULL,
  message VARCHAR(255) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  read_at DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (notification_id),
  UNIQUE KEY uq_notification_event (recipient_role, recipient_id, event_key),
  KEY idx_notification_inbox (recipient_role, recipient_id, read_at, notification_id),
  KEY idx_notification_history (recipient_role, recipient_id, created_at, notification_id),
  KEY idx_notification_record (booking_domain, booking_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Preserve the existing main-admin booking feed and its shared read flag.
-- Main administrators have central tour/cancellation-review responsibility.
-- Re-running never resets read states or creates a second submission event.
INSERT INTO app_notifications
  (recipient_role,recipient_id,event_key,event_type,booking_domain,booking_id,title,message,created_at,read_at)
SELECT 'admin',a.admin_id,SHA2(CONCAT('booking_submitted|tour|',b.booking_id,'|created'),256),
  CASE WHEN cr.tourist_decision='reschedule' THEN 'booking_rescheduled'
       WHEN cr.tourist_decision IN ('full_refund','tourist_full_refund') THEN 'booking_cancelled'
       ELSE 'booking_submitted' END,
  'tour',b.booking_id,
  CASE WHEN cr.tourist_decision='reschedule' THEN 'Booking Schedule Updated'
       WHEN cr.tourist_decision IN ('full_refund','tourist_full_refund') THEN 'Booking Cancelled'
       ELSE 'Booking Submitted' END,
  CASE WHEN cr.tourist_decision='reschedule' THEN 'The tourist selected a new booking schedule.'
       WHEN cr.tourist_decision IN ('full_refund','tourist_full_refund') THEN 'The tourist selected cancellation and a full refund.'
       ELSE 'A booking has been submitted. Review its current details.' END,
  COALESCE(cr.tourist_responded_at,b.created_at,CURRENT_TIMESTAMP),
  CASE WHEN COALESCE(b.is_notif_viewed,0)=0 THEN NULL ELSE CURRENT_TIMESTAMP END
FROM bookings b CROSS JOIN admin_users a
LEFT JOIN booking_cancellation_requests cr ON cr.booking_domain='tour' AND cr.booking_id=b.booking_id
  AND cr.tourist_responded_at IS NOT NULL
WHERE NOT (LOWER(COALESCE(b.preferred_resource,'')) LIKE '%walk-in booking%'
  AND LOWER(COALESCE(b.status,''))='pending' AND LOWER(COALESCE(b.is_complete,''))='uncomplete'
  AND NOT (COALESCE(b.is_paid,0)=1 AND COALESCE(b.grand_total,0)>0
    AND COALESCE(b.remaining_balance,1)<=0 AND COALESCE(b.payment_amount,0)>=COALESCE(b.grand_total,0)))
ON DUPLICATE KEY UPDATE notification_id=notification_id;

-- Preserve provider booking history. Their old read markers exist only in PHP
-- sessions, so historical provider entries start read to avoid replaying old alerts.
-- Future successful events have independent persistent unread state.
INSERT INTO app_notifications
  (recipient_role,recipient_id,event_key,event_type,booking_domain,booking_id,title,message,created_at,read_at)
SELECT 'operator',b.operator_id,SHA2(CONCAT('booking_submitted|tour|',b.booking_id,'|created'),256),
  'booking_submitted','tour',b.booking_id,'Booking Submitted',
  'A booking is available. Review its current details.',COALESCE(b.created_at,CURRENT_TIMESTAMP),CURRENT_TIMESTAMP
FROM bookings b INNER JOIN operators o ON o.operator_id=b.operator_id AND LOWER(o.status)='active'
WHERE NOT (LOWER(COALESCE(b.preferred_resource,'')) LIKE '%walk-in booking%'
  AND LOWER(COALESCE(b.status,''))='pending' AND LOWER(COALESCE(b.is_complete,''))='uncomplete'
  AND NOT (COALESCE(b.is_paid,0)=1 AND COALESCE(b.grand_total,0)>0
    AND COALESCE(b.remaining_balance,1)<=0 AND COALESCE(b.payment_amount,0)>=COALESCE(b.grand_total,0)))
ON DUPLICATE KEY UPDATE notification_id=notification_id;

INSERT INTO app_notifications
  (recipient_role,recipient_id,event_key,event_type,booking_domain,booking_id,title,message,created_at,read_at)
SELECT 'hotel_admin',a.hotel_admin_id,SHA2(CONCAT('booking_submitted|hotel|',h.hotel_booking_id,'|created'),256),
  'booking_submitted','hotel',h.hotel_booking_id,'Booking Submitted',
  'A hotel booking is available. Review its current details.',COALESCE(h.created_at,CURRENT_TIMESTAMP),CURRENT_TIMESTAMP
FROM hotel_room_bookings h INNER JOIN hotel_admin_accounts a ON a.hotel_resort_id=h.hotel_resort_id AND LOWER(a.status)='active'
ON DUPLICATE KEY UPDATE notification_id=notification_id;

-- Read-only verification. Existing bookings may populate history immediately.
SHOW CREATE TABLE app_notifications;
SHOW INDEX FROM app_notifications;
SELECT COUNT(*) AS notification_count FROM app_notifications;
