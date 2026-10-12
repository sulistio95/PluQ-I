-- Provider balance reservations serialize concurrent checkout capacity checks.
-- Apply after migration 008. Reservations are local safeguards against overselling
-- the configured share of the last known provider balance; they do not move provider funds.
CREATE TABLE IF NOT EXISTS provider_balance_reservations (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 order_id BIGINT UNSIGNED NOT NULL UNIQUE,
 provider_id BIGINT UNSIGNED NOT NULL,
 amount DECIMAL(18,2) NOT NULL,
 status ENUM('HOLD','COMMITTED','CONSUMED','RELEASED') NOT NULL DEFAULT 'HOLD',
 expires_at DATETIME NULL,
 committed_at DATETIME NULL,
 consumed_at DATETIME NULL,
 released_at DATETIME NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 INDEX idx_provider_reservation_capacity (provider_id,status,expires_at),
 INDEX idx_provider_reservation_consumed (provider_id,status,consumed_at),
 CONSTRAINT fk_reservation_order FOREIGN KEY (order_id) REFERENCES orders(id),
 CONSTRAINT fk_reservation_provider FOREIGN KEY (provider_id) REFERENCES providers(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO schema_migrations (migration_name) VALUES ('009_provider_balance_reservations');
