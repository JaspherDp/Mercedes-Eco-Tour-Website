ALTER TABLE feedback
  ADD COLUMN IF NOT EXISTS moderation_status ENUM('published','hidden','flagged') NOT NULL DEFAULT 'published' AFTER comment,
  ADD COLUMN IF NOT EXISTS admin_note TEXT NULL AFTER moderation_status,
  ADD COLUMN IF NOT EXISTS admin_reply TEXT NULL AFTER admin_note,
  ADD COLUMN IF NOT EXISTS moderated_by INT NULL AFTER admin_reply,
  ADD COLUMN IF NOT EXISTS moderated_at DATETIME NULL AFTER moderated_by,
  ADD COLUMN IF NOT EXISTS updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at;
