-- Bind an idempotency key to the normalized checkout request to prevent key reuse
-- from silently returning a payment link for a different game/product/account.
ALTER TABLE orders
  ADD COLUMN checkout_payload_hash CHAR(64) NULL AFTER checkout_key;

INSERT IGNORE INTO schema_migrations (migration_name)
VALUES ('010_checkout_payload_fingerprint');
