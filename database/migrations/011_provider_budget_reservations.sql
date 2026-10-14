-- Reserve provider purchasing capacity for paid orders before queueing supplier fulfillment.
-- Active and REVIEW reservations count against the usable balance until resolved.
CREATE TABLE IF NOT EXISTS provider_budget_reservations (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 order_id BIGINT UNSIGNED NOT NULL,
 provider_id BIGINT UNSIGNED NOT NULL,
 amount DECIMAL(18,2) NOT NULL,
 status ENUM('ACTIVE','REVIEW','SETTLED','RELEASED') NOT NULL DEFAULT 'ACTIVE',
 resolution_note VARCHAR(500) NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE KEY uq_provider_budget_order (order_id),
 INDEX idx_provider_budget_capacity (provider_id, status),
 CONSTRAINT fk_budget_reservation_order FOREIGN KEY (order_id) REFERENCES orders(id),
 CONSTRAINT fk_budget_reservation_provider FOREIGN KEY (provider_id) REFERENCES providers(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO schema_migrations (migration_name)
VALUES ('011_provider_budget_reservations');
