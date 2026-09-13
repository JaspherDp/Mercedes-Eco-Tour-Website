CREATE TABLE IF NOT EXISTS request_rate_limits (
    request_scope VARCHAR(60) NOT NULL,
    identifier_hash CHAR(64) NOT NULL,
    request_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    window_started_at DATETIME NOT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (request_scope, identifier_hash),
    INDEX idx_request_rate_limits_updated (updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
