-- PluQ-I checkout and payment fields. Apply after migration 002.
ALTER TABLE orders
  ADD COLUMN checkout_key CHAR(64) NULL UNIQUE AFTER public_id;

ALTER TABLE payment_transactions
  ADD COLUMN gateway_payment_url VARCHAR(500) NULL AFTER gateway_reference,
  ADD COLUMN gateway_expiry_at DATETIME NULL AFTER gateway_payment_url;

INSERT IGNORE INTO schema_migrations (migration_name) VALUES ('003_checkout_payment');
