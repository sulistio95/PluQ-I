-- Optimize latest-refund lookups used by admin transaction filters and order status.
-- Safe to apply after migration 002, which creates the refunds table.
ALTER TABLE refunds
  ADD INDEX idx_refunds_order_latest (order_id, id);

INSERT IGNORE INTO schema_migrations (migration_name)
VALUES ('009_refund_order_latest_index');
