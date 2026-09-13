CREATE TABLE IF NOT EXISTS package_daily_slots (
    slot_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    operator_id INT NOT NULL,
    package_id INT NOT NULL,
    slot_date DATE NOT NULL,
    capacity INT UNSIGNED NOT NULL DEFAULT 0,
    is_open TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (slot_id),
    UNIQUE KEY uq_package_daily_slot (package_id, slot_date),
    KEY idx_operator_slot_date (operator_id, slot_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
