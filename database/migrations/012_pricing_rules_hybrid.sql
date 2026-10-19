-- Hybrid pricing rules: fixed rupiah or percentage profit.
-- Existing fixed profit_amount values are copied without changing current pricing.
ALTER TABLE pricing_rules
  ADD COLUMN profit_type ENUM('FIXED','PERCENTAGE') NOT NULL DEFAULT 'FIXED' AFTER profit_amount,
  ADD COLUMN profit_value DECIMAL(18,2) NOT NULL DEFAULT 0 AFTER profit_type;

UPDATE pricing_rules SET profit_value = profit_amount, profit_type = 'FIXED';

INSERT IGNORE INTO schema_migrations (migration_name) VALUES ('012_pricing_rules_hybrid');
