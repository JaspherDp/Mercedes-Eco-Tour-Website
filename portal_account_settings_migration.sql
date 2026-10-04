-- Account-scoped operator and hotel administrator preferences.
-- Safe to apply again; existing preferences and booking data are preserved.
CREATE TABLE IF NOT EXISTS portal_account_settings (
  role VARCHAR(20) NOT NULL,
  account_id INT UNSIGNED NOT NULL,
  settings_json LONGTEXT NOT NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (role, account_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
