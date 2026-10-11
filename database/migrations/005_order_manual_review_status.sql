-- Add an explicit order state for ambiguous or abandoned fulfillment.
-- Apply after migration 004_supplier_manual_review_status.sql.
ALTER TABLE orders
  MODIFY COLUMN order_status ENUM('PENDING','QUEUED','PROCESSING','RETRYING','SUCCESS','FAILED','REFUND_PENDING','REFUNDED','MANUAL_REVIEW')
  NOT NULL DEFAULT 'PENDING';

INSERT IGNORE INTO schema_migrations (migration_name)
VALUES ('005_order_manual_review_status');
