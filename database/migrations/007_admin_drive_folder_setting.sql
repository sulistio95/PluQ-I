CREATE TABLE IF NOT EXISTS app_settings (
 setting_key VARCHAR(120) NOT NULL PRIMARY KEY,
 setting_value TEXT NULL,
 updated_by BIGINT UNSIGNED NULL,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 CONSTRAINT fk_app_settings_admin FOREIGN KEY (updated_by) REFERENCES admin_users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO app_settings (setting_key, setting_value)
VALUES ('google_drive_folder_id', NULL);

INSERT IGNORE INTO schema_migrations (migration_name)
VALUES ('007_admin_drive_folder_setting');
