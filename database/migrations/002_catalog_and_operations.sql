-- PluQ-I catalog and operations schema expansion.
-- Apply after database/schema.mysql.sql. Use an empty database for a fresh install.

CREATE TABLE IF NOT EXISTS game_provider_mappings (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 game_id BIGINT UNSIGNED NOT NULL,
 provider_id BIGINT UNSIGNED NOT NULL,
 provider_game_code VARCHAR(190) NOT NULL,
 provider_game_name VARCHAR(190) NULL,
 status ENUM('ACTIVE','INACTIVE') NOT NULL DEFAULT 'ACTIVE',
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE KEY uq_game_provider_code (provider_id, provider_game_code),
 INDEX idx_mapping_game_status (game_id, status),
 CONSTRAINT fk_mapping_game FOREIGN KEY (game_id) REFERENCES games(id),
 CONSTRAINT fk_mapping_provider FOREIGN KEY (provider_id) REFERENCES providers(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS game_fields (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 game_id BIGINT UNSIGNED NOT NULL,
 field_key VARCHAR(80) NOT NULL,
 label VARCHAR(120) NOT NULL,
 field_type ENUM('text','select','number') NOT NULL DEFAULT 'text',
 placeholder VARCHAR(190) NULL,
 is_required TINYINT(1) NOT NULL DEFAULT 1,
 validation_regex VARCHAR(500) NULL,
 sort_order INT NOT NULL DEFAULT 0,
 status ENUM('ACTIVE','INACTIVE') NOT NULL DEFAULT 'ACTIVE',
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE KEY uq_game_field_key (game_id, field_key),
 INDEX idx_game_fields_order (game_id, status, sort_order),
 CONSTRAINT fk_game_fields_game FOREIGN KEY (game_id) REFERENCES games(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS game_field_options (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 game_field_id BIGINT UNSIGNED NOT NULL,
 option_value VARCHAR(190) NOT NULL,
 option_label VARCHAR(190) NOT NULL,
 sort_order INT NOT NULL DEFAULT 0,
 status ENUM('ACTIVE','INACTIVE') NOT NULL DEFAULT 'ACTIVE',
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_field_option (game_field_id, option_value),
 CONSTRAINT fk_field_option_field FOREIGN KEY (game_field_id) REFERENCES game_fields(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS provider_products (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 provider_id BIGINT UNSIGNED NOT NULL,
 provider_game_code VARCHAR(190) NULL,
 provider_product_code VARCHAR(190) NOT NULL,
 product_name VARCHAR(190) NOT NULL,
 category VARCHAR(120) NULL,
 base_price DECIMAL(18,2) NOT NULL,
 is_available TINYINT(1) NOT NULL DEFAULT 1,
 raw_data JSON NULL,
 synced_at DATETIME NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE KEY uq_provider_products (provider_id, provider_product_code),
 INDEX idx_provider_products_game (provider_id, provider_game_code, is_available),
 CONSTRAINT fk_provider_products_provider FOREIGN KEY (provider_id) REFERENCES providers(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS product_variants (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 product_id BIGINT UNSIGNED NOT NULL,
 provider_product_id BIGINT UNSIGNED NULL,
 variant_code VARCHAR(190) NOT NULL,
 variant_name VARCHAR(190) NOT NULL,
 base_price DECIMAL(18,2) NOT NULL,
 selling_price DECIMAL(18,2) NOT NULL,
 status ENUM('ACTIVE','INACTIVE','OUT_OF_STOCK') NOT NULL DEFAULT 'ACTIVE',
 metadata JSON NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE KEY uq_product_variant (product_id, variant_code),
 INDEX idx_variant_price (product_id, status, selling_price),
 CONSTRAINT fk_variant_product FOREIGN KEY (product_id) REFERENCES products(id),
 CONSTRAINT fk_variant_provider_product FOREIGN KEY (provider_product_id) REFERENCES provider_products(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pricing_rules (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 min_amount DECIMAL(18,2) NOT NULL,
 max_amount DECIMAL(18,2) NULL,
 profit_amount DECIMAL(18,2) NOT NULL,
 status ENUM('ACTIVE','INACTIVE') NOT NULL DEFAULT 'ACTIVE',
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 INDEX idx_pricing_range (status, min_amount, max_amount)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS settings (
 setting_key VARCHAR(120) PRIMARY KEY,
 setting_value TEXT NULL,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS banners (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 title VARCHAR(190) NOT NULL,
 image_url VARCHAR(500) NOT NULL,
 target_url VARCHAR(500) NULL,
 sort_order INT NOT NULL DEFAULT 0,
 status ENUM('ACTIVE','INACTIVE') NOT NULL DEFAULT 'ACTIVE',
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 INDEX idx_banners_public (status, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS excluded_services (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 provider_id BIGINT UNSIGNED NOT NULL,
 service_code VARCHAR(190) NOT NULL,
 reason VARCHAR(255) NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_excluded_service (provider_id, service_code),
 CONSTRAINT fk_excluded_provider FOREIGN KEY (provider_id) REFERENCES providers(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS supplier_transactions (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 order_id BIGINT UNSIGNED NOT NULL,
 provider_id BIGINT UNSIGNED NOT NULL,
 reference_id VARCHAR(190) NOT NULL UNIQUE,
 provider_reference VARCHAR(190) NULL,
 status ENUM('PENDING','PROCESSING','RETRYING','SUCCESS','FAILED','REVIEW') NOT NULL DEFAULT 'PENDING',
 attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
 request_payload JSON NULL,
 response_payload JSON NULL,
 last_error TEXT NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 INDEX idx_supplier_status (status, created_at),
 CONSTRAINT fk_supplier_tx_order FOREIGN KEY (order_id) REFERENCES orders(id),
 CONSTRAINT fk_supplier_tx_provider FOREIGN KEY (provider_id) REFERENCES providers(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS refunds (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 order_id BIGINT UNSIGNED NOT NULL,
 amount DECIMAL(18,2) NOT NULL,
 status ENUM('PENDING','PROCESSING','SUCCESS','FAILED') NOT NULL DEFAULT 'PENDING',
 reason TEXT NULL,
 gateway_reference VARCHAR(190) NULL,
 processed_at DATETIME NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 INDEX idx_refund_status (status, created_at),
 CONSTRAINT fk_refund_order FOREIGN KEY (order_id) REFERENCES orders(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS webhook_events (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 provider_code VARCHAR(32) NOT NULL,
 event_key VARCHAR(190) NOT NULL,
 payload_hash CHAR(64) NOT NULL,
 processing_status ENUM('PENDING','PROCESSING','PROCESSED','FAILED') NOT NULL DEFAULT 'PENDING',
 received_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 processed_at DATETIME NULL,
 last_error TEXT NULL,
 UNIQUE KEY uq_webhook_event (provider_code, event_key),
 INDEX idx_webhook_status (processing_status, received_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS account_validations (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 game_id BIGINT UNSIGNED NOT NULL,
 input_hash CHAR(64) NOT NULL,
 result_name VARCHAR(190) NULL,
 status ENUM('ACTIVE','INACTIVE') NOT NULL DEFAULT 'ACTIVE',
 checked_at DATETIME NOT NULL,
 expires_at DATETIME NOT NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 INDEX idx_validation_lookup (game_id, input_hash, expires_at),
 CONSTRAINT fk_validation_game FOREIGN KEY (game_id) REFERENCES games(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO settings (setting_key, setting_value) VALUES
 ('site_name', 'PluQ'),
 ('default_currency', 'IDR'),
 ('maintenance_mode', 'false'),
 ('provider_balance_check_interval', '60'),
 ('queue_retry_limit', '3'),
 ('catalog_sync_interval_seconds', '900');

INSERT IGNORE INTO pricing_rules (min_amount, max_amount, profit_amount, status) VALUES
 (0, 10000, 500, 'ACTIVE'),
 (10000.01, 100000, 1000, 'ACTIVE'),
 (100000.01, 500000, 2000, 'ACTIVE'),
 (500000.01, NULL, 3500, 'ACTIVE');

INSERT IGNORE INTO schema_migrations (migration_name) VALUES ('002_catalog_and_operations');
