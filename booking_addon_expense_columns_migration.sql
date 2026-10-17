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
