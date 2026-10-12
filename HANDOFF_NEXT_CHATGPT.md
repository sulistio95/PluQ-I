# PluQ-I — Handoff for the next ChatGPT session

Last updated: 2026-10-12 (Asia/Jakarta)
Repository: https://github.com/sulistio95/PluQ-I
Default branch: `main`

## Instructions for continuing

Read this file first, then read `README.md` and `docs/production-readiness-audit.md`. Continue work directly in **PluQ-I** using the GitHub integration when available. Do not make the user repeat the project background. Audit source before changing it, commit focused changes, update this handoff and the audit document as work progresses, and distinguish code review from tests actually executed.

## Scope and repository separation

- **Only work in `sulistio95/PluQ-I`** for the current shared-hosting edition.
- The separate `sulistio95/PluQ` repository is the primary edition planned for Cloudbaik. Do not edit it or copy changes there unless the user explicitly asks.
- PluQ-I targets PHP 8.2+ and MySQL/MariaDB on lower-cost shared hosting (possible targets include Rumahweb/Rumah Hosting).
- Architecture is intentionally PHP + PDO + MySQL queue processed in bounded cron runs. Do not introduce Redis, BullMQ, Node.js, or a permanently running worker into this edition.
- User asked to complete the stages and then audit them individually. Continue implementation and source review rather than only offering a plan.

## Product and business requirements

- Top-up storefront for games, dark/minimal responsive UI, search and rotating banners.
- Suppliers: Digiflazz and VIPayment. Gateway: Duitku only for now.
- Customer prices are calculated server-side from supplier costs, profit/pricing rules and the configured admin fee. Exclude catalog entries containing `joki` or `vilog`.
- Provider balance availability must respect reserve percentage (default design reserves 50%).
- Game account inputs vary by game; never guess field meanings or map IDs/zones by array order.
- Do not expose supplier choice to the customer if the selected offer should be automatically chosen by the backend.
- Keep payment status separate from fulfillment/order status.
- Supplier ambiguity/timeouts must go to manual review; do not blindly repeat a purchase that might already have been accepted by the supplier.
- Definitive supplier rejection currently creates a pending refund record, but **automatic customer refunds are not certified/implemented as a production-safe workflow**. Reconcile refunds through the approved gateway procedure.

## Current implementation at a glance

- PHP bootstrap/environment loading and PDO database configuration.
- MySQL schema plus migrations 002–010 for catalog/operations, checkout/payment, manual-review statuses, settlements/Drive settings, login throttling, refund lookup index, and checkout request fingerprint.
- Public catalog and quote validation; admin transaction view and game/provider mapping screen.
- Checkout idempotency binds the idempotency key to a normalized fingerprint of email, game, product and account fields; same key with a different payload fails closed.
- Duitku callback verifies HMAC, checks order/amount, locks the payment row and enqueues fulfillment only on the first valid transition to paid. Callback code `00` means success and `01` means failure according to Duitku server-to-server callback docs.
- MySQL-backed bounded queue with lock tokens and retry cap; stale worker locks are moved to manual review rather than automatically resubmitted.
- Supplier response mapping tests cover account-field mapping and common status normalization.
- `bin/preflight.php` checks required runtime/schema conditions and ensures payment, supplier order and settlement-backup flags remain disabled.
- Google Drive settlement export scaffolding exists but is disabled by default and is not validated against a real Google account.
- Admin login uses password hashing, session ID regeneration, CSRF tokens, DB-backed login throttling and audit logs.
- Recent admin security fix: commit `76fc5ad5685dad8cb63b89e1d2b1bb41614a967e` denies OPERATOR-role POST attempts to change the Google Drive folder setting. Audit update: `7106b77845b5c8e4ba4aeff74a86fcbf384a508a`.

## Tests and what is actually verified

GitHub Actions workflow: `.github/workflows/php-quality.yml`
- PHP 8.2 lint over PHP files in `app`, `bin`, `config`, `public`, and `tests`.
- `tests/duitku-callback-result-state.php`
- `tests/checkout-request-fingerprint.php`
- `tests/fulfillment-mapping.php`

Important limitation: at the last check, the GitHub combined commit status returned no status entries, and a local clone failed because the execution environment could not resolve `github.com`. Therefore **do not claim CI passed or PHP/tests were run locally** unless a new check confirms it. These are source files and intended checks, not proof of a successful runtime run.

## Production safety — hard stop

Keep all of these disabled until controlled staging tests pass:
- `CHECKOUT_PAYMENTS_ENABLED=false`
- `SUPPLIER_ORDERS_ENABLED=false`
- settlement/Google Drive backup flag disabled.

Do not deploy or initiate live transactions, real supplier orders, or Google Drive writes while auditing. Never put API keys, Duitku secrets, supplier credentials, OAuth tokens, or production `.env` contents in Git or chat.

## Highest-priority next steps

1. **Continue admin authorization audit**: inspect every `public/admin/*.php` route, including all POST mutations, for authentication, role checks, CSRF validation, session/cookie handling, output escaping, and audit logging. Compare with `public/admin/game-mappings.php`, which already gates mutations to `SUPER_ADMIN`/ `ADMIN`. Fix any bypass and add tests where practical.
2. Inspect the rest of `public/admin/index.php` and each admin page for privilege-sensitive actions. Confirm the new Drive-folder role check works for OPERATOR and allowed roles.
3. Review the queue and fulfillment transaction boundaries: concurrent worker claims, lock-token conditional updates, pending supplier responses, retry limits, stale-lock recovery, duplicate queue jobs, and reconciliation path. Add DB integration tests where feasible; do not alter safe manual-review behavior without evidence.
4. Review all migrations 001–010 and `bin/preflight.php` against a clean MySQL/MariaDB install and an upgrade path. Execute only when a real test DB/runtime is available.
5. Expand tests for pricing/reserve boundaries, callback duplicate/conflicting transitions, checkout idempotency concurrency, refund idempotency, admin roles/CSRF, and queue concurrency.
6. Verify the workflow actually runs and inspect its result. If the GitHub integration exposes workflow-run actions, use them; a missing combined status is not a pass.
7. Keep `README.md`, `docs/production-readiness-audit.md`, and this handoff updated after substantive changes.

## Required staging exit checklist

- Clean install and upgrade migrations 001–010 on the target MySQL/MariaDB version.
- Verify private `.env`, file permissions, HTTPS, PHP extensions, outbound HTTPS and cron capability on the selected shared-host plan.
- Test valid/invalid Duitku HMAC, wrong amount/order, duplicate callback, pending-then-paid, and conflicting status transitions.
- Run same idempotency key concurrently with same and different payloads; ensure only one order/payment exists and payload mismatch never reuses a payment URL.
- Run multiple queue workers; test success, pending, definitive failure, timeout, process crash, stale lock and recovery. Reconcile provider status before any manual retry.
- Verify supplier API request signing, exact product codes, ID/zone mapping, status meanings and provider transaction references with sandbox credentials and known test accounts.
- Reconcile refund amount/status and the actual Duitku refund capability/process; never treat a local refund row as proof money was returned.
- Verify admin roles, CSRF, login throttling, cookie security behind the actual proxy, audit trail and unauthorized POST behavior.
- Test Google Drive export only in staging after migrations 006–007 and OAuth/folder setup; test interruption recovery, duplicate prevention, access permissions and independent DB restore.
- Test backups and restore, cron monitoring, queue backlog alerts, and incident/manual-review procedures.

## Working style requested by the user

- Continue directly with concrete repository work where tools permit; avoid repetitive setup questions.
- Keep explanations in Indonesian.
- Do not claim completion merely because files exist. State what was changed, which checks were actually performed, and what remains blocked.
- If a tool/network restriction prevents a test or edit, record that limitation here and in the audit notes.
