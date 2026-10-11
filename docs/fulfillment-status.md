# Supplier fulfillment rollout

Supplier execution is disabled by default. The example environment file sets `SUPPLIER_ORDERS_ENABLED=false`. Keep it disabled until supplier product codes, account-field mappings, API response states, and sandbox behavior have been verified.

## Current files

- `app/services/fulfillment.php`: draft provider adapters and order-state handling.
- `bin/worker-queue.php`: bounded queue runner with scheduler-token protection for HTTP use.
- `bin/worker.php`: original foundation-only runner; it does not dispatch supplier jobs.
- `database/migrations/004_supplier_manual_review_status.sql`: adds the `REVIEW` supplier-transaction state used when a provider outcome is ambiguous.
- `database/migrations/005_order_manual_review_status.sql`: adds the order-level `MANUAL_REVIEW` state for unresolved fulfillment.
- `tests/fulfillment_account_test.php`: basic account-field mapping tests.
- `.github/workflows/fulfillment-tests.yml`: PHP lint and test workflow.

The queue runner now moves locks older than 10 minutes to manual review rather than automatically resubmitting potentially accepted supplier requests. Retry exhaustion and unexpected worker errors also move paid orders to `MANUAL_REVIEW`; no automatic Duitku refund is issued. The new runner is not the production scheduler yet. Before using it, review provider API contracts and test the complete lifecycle with non-production credentials. Do not set `SUPPLIER_ORDERS_ENABLED=true` on a live store.

## Operational cautions

- Payment callback jobs are created only after a verified successful payment callback.
- Ambiguous `PROCESSING` supplier transactions are blocked from automatic resubmission and moved to manual review with a pending refund record.
- Digiflazz orders that remain `RETRYING` are also moved to manual review instead of resending `/transaction`; idempotency/status-query behavior has not been verified.
- VIPayment pending orders use the returned transaction ID for a status query, but this API contract still needs sandbox validation.
- Refund rows are queued for manual processing; this code does not automatically issue a Duitku refund.
- The account-field mapping is generic. Configure and test the exact field keys for every game before enabling fulfillment.
- Confirm the API's status-check endpoint and returned status values for each provider before live use.
- Keep API credentials in a private environment file, never in Git.
