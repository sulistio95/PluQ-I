-- Shared-hosting-safe cache and atomic rate limits for public game nickname checks.
-- Stores only successful nickname results and hashes of request identifiers/IPs.
CREATE TABLE IF NOT EXISTS game_username_check_cache (
    fingerprint CHAR(64) NOT NULL PRIMARY KEY,
    game_slug VARCHAR(100) NOT NULL,
    username VARCHAR(190) NOT NULL,
    expires_at DATETIME NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_username_cache_expiry (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS game_username_check_limits (
    limit_key CHAR(64) NOT NULL PRIMARY KEY,
    window_start BIGINT UNSIGNED NOT NULL,
    request_count INT UNSIGNED NOT NULL DEFAULT 0,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_username_limits_updated (updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO schema_migrations (migration_name) VALUES ('013_game_username_check_protection');
