-- Database-backed login throttling for shared-hosting deployments.
CREATE TABLE IF NOT EXISTS admin_login_attempts (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 ip_address VARCHAR(45) NOT NULL,
 username_hash CHAR(64) NOT NULL,
 attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 INDEX idx_login_attempt_ip_time (ip_address, attempted_at),
 INDEX idx_login_attempt_user_time (username_hash, attempted_at),
 INDEX idx_login_attempt_time (attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO schema_migrations (migration_name)
VALUES ('008_admin_login_rate_limit');
