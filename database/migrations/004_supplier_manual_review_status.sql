-- Add an explicit manual-review state for ambiguous supplier outcomes.
-- Apply after migration 003. Safe for databases created from the updated migration 002 as well.
ALTER TABLE supplier_transactions
  MODIFY COLUMN status ENUM('PENDING','PROCESSING','RETRYING','SUCCESS','FAILED','REVIEW')
  NOT NULL DEFAULT 'PENDING';

INSERT IGNORE INTO schema_migrations (migration_name)
VALUES ('004_supplier_manual_review_status');
