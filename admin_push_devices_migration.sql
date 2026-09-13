CREATE TABLE admin_push_devices (
    device_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    admin_id INT NOT NULL,
    fcm_token TEXT NOT NULL,
    device_name VARCHAR(100) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_used_at DATETIME NULL,
    revoked_at DATETIME NULL,
    UNIQUE KEY unique_fcm_token (fcm_token(191)),
    KEY idx_admin_push_admin (admin_id),
    KEY idx_admin_push_active (admin_id, revoked_at)
) ENGINE=InnoDB
DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_unicode_ci;

