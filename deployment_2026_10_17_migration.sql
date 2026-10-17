-- Deployment update: 17 October 2026
-- Back up the hosted database and select the website database before importing.
-- Requires the existing booking_expenses table and current booking/payment schema.
-- Additive only: no historical bookings, prices, accounts, or payments are updated.
-- Existing objects are skipped; incompatible existing definitions are not repaired.

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


-- Pending Hostinger update: run manually later, after a database backup.
-- This file has not been executed on Hostinger.
-- Prerequisite: booking_expenses already exists. Select the correct database first.
-- Adds only missing columns and preserves all existing rows.

SET @itour_addon_sql = IF(
    EXISTS(SELECT 1 FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'booking_expenses' AND COLUMN_NAME = 'expense_name'),
    'SELECT ''booking_expenses.expense_name already exists'' AS migration_status',
    'ALTER TABLE booking_expenses ADD COLUMN expense_name VARCHAR(160) NULL'
);
PREPARE itour_addon_stmt FROM @itour_addon_sql;
EXECUTE itour_addon_stmt;
DEALLOCATE PREPARE itour_addon_stmt;

SET @itour_addon_sql = IF(
    EXISTS(SELECT 1 FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'booking_expenses' AND COLUMN_NAME = 'quantity'),
    'SELECT ''booking_expenses.quantity already exists'' AS migration_status',
    'ALTER TABLE booking_expenses ADD COLUMN quantity INT UNSIGNED NULL'
);
PREPARE itour_addon_stmt FROM @itour_addon_sql;
EXECUTE itour_addon_stmt;
DEALLOCATE PREPARE itour_addon_stmt;

SET @itour_addon_sql = IF(
    EXISTS(SELECT 1 FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'booking_expenses' AND COLUMN_NAME = 'unit_price'),
    'SELECT ''booking_expenses.unit_price already exists'' AS migration_status',
    'ALTER TABLE booking_expenses ADD COLUMN unit_price DECIMAL(12,2) NULL'
);
PREPARE itour_addon_stmt FROM @itour_addon_sql;
EXECUTE itour_addon_stmt;
DEALLOCATE PREPARE itour_addon_stmt;


-- Apply manually before deploying multi-resource booking code. No historical rows are changed.
CREATE TABLE IF NOT EXISTS booking_resource_boats (
    booking_id INT NOT NULL, boat_id INT NOT NULL,
    PRIMARY KEY (booking_id, boat_id), INDEX idx_reserved_boat (boat_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS booking_resource_guides (
    booking_id INT NOT NULL, guide_id INT NOT NULL,
    PRIMARY KEY (booking_id, guide_id), INDEX idx_reserved_guide (guide_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- Storage used by the existing admin walk-in booking helper.
CREATE TABLE IF NOT EXISTS booking_walkin_details (
    booking_id INT NOT NULL PRIMARY KEY,
    details LONGTEXT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
