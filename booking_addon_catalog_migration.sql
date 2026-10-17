CREATE TABLE IF NOT EXISTS booking_addon_catalog (
    addon_id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    owner_role VARCHAR(20) NOT NULL,
    owner_id INT UNSIGNED NOT NULL,
    hotel_resort_id INT UNSIGNED NULL,
    name VARCHAR(160) NOT NULL,
    price DECIMAL(10,2) NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX addon_owner (owner_role, owner_id),
    INDEX addon_availability (is_active, owner_role, hotel_resort_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
