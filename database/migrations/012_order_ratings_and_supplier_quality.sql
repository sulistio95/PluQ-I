-- Customer feedback for completed orders and supplier-quality aggregates.
-- Customer ratings are separate from objective provider performance metrics.
CREATE TABLE IF NOT EXISTS order_ratings (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 order_id BIGINT UNSIGNED NOT NULL,
 provider_id BIGINT UNSIGNED NOT NULL,
 game_id BIGINT UNSIGNED NOT NULL,
 order_status_at_rating ENUM('SUCCESS','FAILED','REFUNDED') NOT NULL,
 rating TINYINT UNSIGNED NOT NULL,
 reason ENUM('FAST_AND_SMOOTH','SLOW_PROCESS','ITEM_NOT_RECEIVED','ORDER_FAILED','SUPPORT_EXPERIENCE','OTHER') NOT NULL,
 comment VARCHAR(1000) NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_order_ratings_order (order_id),
 INDEX idx_order_ratings_provider_created (provider_id, created_at),
 INDEX idx_order_ratings_rating_created (rating, created_at),
 CONSTRAINT chk_order_ratings_rating CHECK (rating BETWEEN 1 AND 5),
 CONSTRAINT fk_order_ratings_order FOREIGN KEY (order_id) REFERENCES orders(id),
 CONSTRAINT fk_order_ratings_provider FOREIGN KEY (provider_id) REFERENCES providers(id),
 CONSTRAINT fk_order_ratings_game FOREIGN KEY (game_id) REFERENCES games(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO schema_migrations (migration_name)
VALUES ('012_order_ratings_and_supplier_quality');
