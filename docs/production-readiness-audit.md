# PluQ-I implementation and readiness audit

Audit baseline: GitHub default branch at the time this file was added. This is a source-code review, not a live-hosting or payment certification.

## Status legend

- **Implemented in repository**: corresponding code or documentation exists.
- **Source-reviewed**: relevant paths were inspected for the behavior described.
- **Runtime verification pending**: behavior has not been executed against a configured MySQL/MariaDB instance, provider sandbox, or hosting account.
- **Blocked for production**: do not enable live payments or supplier execution.

## Stage-by-stage audit

| Stage | Source review | Remaining verification / blocker |
|---|---|---|
| PHP bootstrap and environment | PDO uses exceptions, associative fetches, and native prepared statements; environment loading skips already-defined variables. | Verify document root/private `.env`, file permissions, HTTPS, PHP extensions, and production error handling on the selected host. |
| Schema and migrations | Foundation schema and migrations 002–010 cover catalog/operations, checkout fields, manual-review states, settlements, admin Drive setting, login throttling, latest-refund lookup, and checkout request fingerprints. | Apply in order on a clean MySQL/MariaDB database; test upgrade from an existing database; verify SQL compatibility with the exact host version. |
| Catalog and pricing | Catalog chooses an available cheapest offer by normalized product name; quote recalculates price, checks reserve balance and exclusions, and validates dynamic account fields. | Run pricing boundary tests and DB-backed catalog sync tests using real supplier catalogs; validate every game's account field names. |
| Supplier game mapping | Admin page allows authorized admins to activate/deactivate mappings between local games and provider game codes; changes are audited. | Configure exact Digiflazz brand/game codes and VIPayment game names; compare synced product counts and samples before enabling any order. |
| Checkout and idempotency | Idempotency key is bound to a normalized request fingerprint; duplicate-key races re-read the winning order; the Duitku payment URL is persisted even if a fast callback arrives first. | Run concurrent same-key requests and same-key/different-payload requests against MySQL; verify one order/payment is created and a mismatched payload never reuses a payment URL. |
| Duitku callback | HMAC-SHA256 verification, order/amount matching, row locking, and first-transition queue insertion are present. Result code `01` maps to failed, matching the server-to-server callback documentation; amount parsing rejects invalid/overflowing integers. | Execute valid/invalid signature, wrong amount, duplicate callback, pending-then-paid, and conflicting-state cases against a test database. |
| Queue and worker | MySQL-backed bounded worker, lock tokens, retry cap, read-only health diagnostic, and conservative stale-lock manual review are present. | Run simultaneous-worker tests on the host's MySQL version; confirm cron does not overlap and measure backlog under load. Do not auto-resubmit stale supplier requests. |
| Supplier fulfillment | Supplier adapters use HTTPS, timeouts, no redirects, and conservative manual review for ambiguous outcomes. Definitive rejection preserves payment=PAID and creates a single pending refund record under the order lock. | Verify provider API payload/signature/status semantics with sandbox credentials and known test accounts. Never treat an HTTP timeout as a definitive rejection. |
| Refunds | Refund records and idempotent creation for supplier failure exist; admin can filter latest refund status. | **No automatic customer refund is certified by this audit.** Confirm gateway refund capability and implement/verify an authorized refund action or complete refunds manually through the approved gateway process. Reconcile the gateway reference and refund status before closing an order. |
| Admin security | Session regeneration after login, CSRF checks, password hash verification, DB-backed throttling, output escaping, and audit logs are present in inspected paths. | Verify all admin routes enforce roles and CSRF; test secure cookie behavior behind the actual reverse proxy and check permissions for each role. |
| Google Drive settlement | Export and folder configuration scaffolding exist and are disabled by default. | Configure OAuth on the server and test create/upload, duplicate prevention, interrupted upload recovery, permissions, and independent database restore. |
| CI and tests | Added GitHub Actions workflow lints PHP files and runs `tests/duitku-callback-result-state.php` plus `tests/checkout-request-fingerprint.php`. | The workflow status has not been confirmed as passing. Database-backed integration tests and live provider calls are not run by this workflow. |
| Deployment and production | Preflight and queue-health CLI diagnostics exist. | **Blocked for production** until all staging tests, restore rehearsal, API reconciliation, cron monitoring, and incident/manual-review procedures pass. |

## Callback result-state rule

- `00`: payment reported successful; the callback may transition a pending payment and enqueue fulfillment once.
- `01`: payment failed; mark the payment/order failed only if the stored transaction is still pending.
- Any other code: reject the callback as unsupported. The separate customer redirect result codes are not used to update database payment state.

Duplicate callbacks for a transaction already in a non-pending state are acknowledged without repeating the payment/order transition.

## Staging exit checklist

- [ ] Apply all migrations 001–010 in order to a clean test database.
- [ ] Run PHP lint, the callback-state test, and the checkout-fingerprint test in GitHub Actions.
- [ ] Test callback signature failures, wrong order/amount, duplicate callback, and pending-to-paid transition.
- [ ] Test checkout idempotency under concurrent requests and verify only one payment/order is created.
- [ ] Reuse one idempotency key with changed product/account data and verify the existing payment URL is not returned.
- [ ] Test two workers claiming jobs concurrently; confirm each order is claimed once.
- [ ] Test provider success, explicit rejection, pending status, timeout, malformed response, and stale worker lock.
- [ ] Verify uncertain supplier outcomes go to manual review and are never blindly resubmitted.
- [ ] Verify a supplier rejection creates only one refund record and that the actual customer refund is independently reconciled.
- [ ] Verify product availability at reserve-balance boundaries and lowest-price selection across both providers.
- [ ] Test backup upload, duplicate-month handling, interrupted recovery, and database restore.
- [ ] Verify admin login throttling, roles, CSRF, secure cookies, and audit logs.
- [ ] Confirm cron schedule, non-overlap, queue health alerts, log rotation, and a manual recovery runbook.
- [ ] Keep live Duitku payments, supplier orders, and Drive backup disabled until the applicable checks pass.

## Safety defaults

Keep `CHECKOUT_PAYMENTS_ENABLED=false`, `SUPPLIER_ORDERS_ENABLED=false`, and `SETTLEMENT_BACKUP_ENABLED=false` until staging evidence is recorded. A successful syntax check is not proof that external APIs, database transactions, or production hosting work correctly.
