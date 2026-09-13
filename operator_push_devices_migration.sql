CREATE TABLE IF NOT EXISTS operator_push_devices (
    device_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    operator_id INT NOT NULL,
    fcm_token TEXT NOT NULL,
    device_name VARCHAR(100) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_used_at DATETIME NULL,
    revoked_at DATETIME NULL,
    UNIQUE KEY unique_operator_fcm_token (fcm_token(191)),
    KEY idx_operator_push_owner (operator_id),
    KEY idx_operator_push_active (operator_id, revoked_at)
) ENGINE=InnoDB
DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_unicode_ci;
