-- Backward-compatible expansion of the existing activity log.
-- Existing admin logs and the legacy admin_id column are preserved.
ALTER TABLE admin_activity_logs
    ADD COLUMN actor_type VARCHAR(30) NULL AFTER admin_id,
    ADD COLUMN actor_id INT NULL AFTER actor_type,
    ADD COLUMN actor_name VARCHAR(190) NULL AFTER actor_id,
    ADD INDEX idx_activity_actor (actor_type, actor_id),
    ADD INDEX idx_activity_action (action),
    ADD INDEX idx_activity_created (created_at);

UPDATE admin_activity_logs l
LEFT JOIN admin_users a ON a.admin_id = l.admin_id
SET
    l.actor_type = 'Admin',
    l.actor_id = l.admin_id,
    l.actor_name = COALESCE(a.full_name, a.username, 'Administrator')
WHERE l.actor_type IS NULL;

ALTER TABLE admin_activity_logs
    MODIFY actor_type VARCHAR(30) NOT NULL,
    MODIFY actor_name VARCHAR(190) NOT NULL;
