ALTER TABLE hotel_resort_reviews
  ADD COLUMN IF NOT EXISTS moderation_status ENUM('published','hidden','flagged') NOT NULL DEFAULT 'published' AFTER review_message,
  ADD COLUMN IF NOT EXISTS owner_reply TEXT NULL AFTER moderation_status,
  ADD COLUMN IF NOT EXISTS internal_note TEXT NULL AFTER owner_reply,
  ADD COLUMN IF NOT EXISTS responded_by INT NULL AFTER internal_note,
  ADD COLUMN IF NOT EXISTS responded_at DATETIME NULL AFTER responded_by,
  ADD COLUMN IF NOT EXISTS updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at;
