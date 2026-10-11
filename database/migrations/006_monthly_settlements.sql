-- Track monthly transaction settlement exports to prevent duplicate backups.
CREATE TABLE IF NOT EXISTS monthly_settlement_exports (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 period_start DATE NOT NULL,
 period_end DATE NOT NULL,
 status ENUM('PROCESSING','UPLOADED','FAILED') NOT NULL DEFAULT 'PROCESSING',
 file_name VARCHAR(255) NOT NULL,
 drive_file_id VARCHAR(255) NULL,
 row_count INT UNSIGNED NOT NULL DEFAULT 0,
 total_amount DECIMAL(18,2) NOT NULL DEFAULT 0,
 uploaded_at DATETIME NULL,
 last_error TEXT NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE KEY uq_settlement_period (period_start, period_end),
 INDEX idx_settlement_status (status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO schema_migrations (migration_name)
VALUES ('006_monthly_settlements');
