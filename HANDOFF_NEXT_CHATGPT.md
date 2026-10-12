# PluQ-I — Handoff for the next ChatGPT session

Last updated: 2026-10-12 (Asia/Jakarta) — checkout idempotency deadlock recovery hardened; CI pending
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
- Worker finalization hardening (commit `957d310591c159af2357c2553db72d4f0187ef65`): after fulfillment returns, the worker locks its queue row and verifies `status='PROCESSING'` plus the same `lock_token` before changing queue/order/supplier state. Finalization is transactional and locks queue → order → supplier, matching stale-lock recovery. A worker whose lease was revoked records `LOCK_LOST` and does not finalize its stale result. The revoked-lease DB integration test passed in run `38209568044`.
- Follow-up commit `524006860eb1662f9bc415d830ab0a0dc1d775bf` extracted lease-guarded finalization to `app/services/queue_worker.php` so it can be exercised directly. Commit `3c99f1483dac492029640e3ac1c648b3df242b93` adds `tests/worker-lease-revocation-integration.php`, which simulates stale recovery revoking a queue token and setting order/supplier to review, then verifies the original worker's RETRY result returns `LOCK_LOST` without changing those states. Workflow step added in commit `5595378aea624f67bb4a42cb1e24bcd24169c89b`. MySQL integration and PHP test jobs passed in fulfillment run `38209568044`; the revoked-lease test passed. The expanded preflight schema-coverage change (`33a32ee`) passed all three workflows: quality `38209632272`, syntax `38209632254`, fulfillment `38209632265`. Follow-up documentation commits also passed all three workflows: `5985ec58` (`38209640961`, `38209640956`, `38209640963`), `10e24cad` (`38209648824`, `38209648887`, `38209648851`), `d143dd7f` (`38209663121`, `38209663128`, `38209663111`), and `e3f24a21` (`38209670216`, `38209670195`, `38209670175`). The expanded admin CSRF regression test in commit `b371a0cb` passed quality run `38210544538`, syntax run `38210544554`, and fulfillment run `38210544547`; the later documentation commits `7a227ed9` and `f632ce18` also passed all three workflows (runs `38210555764`, `38210555798`, `38210555794` and `38210566728`, `38210566658`, `38210566561`, respectively).
- Preflight schema coverage expanded in commit `33a32eefd5d2d14a031978fe58b46af083316247`: it checks the recorded foundation migration `001_shared_hosting_foundation`, additional core tables (`audit_logs`, `provider_products`, `product_variants`, `settings`, `banners`, `webhook_events`, `account_validations`), and—when settlement backup is enabled—both `monthly_settlement_exports` and `app_settings` as well as migrations 006–007. All three workflows passed for this change.
- Supplier response mapping tests cover account-field mapping and common status normalization.
- `bin/preflight.php` checks required runtime/schema conditions and ensures payment, supplier order and settlement-backup flags remain disabled.
- Google Drive settlement export scaffolding exists but is disabled by default and is not validated against a real Google account.
- Admin login uses password hashing, session ID regeneration, CSRF tokens, DB-backed login throttling and audit logs.
- Admin authorization fix: commit `76fc5ad5685dad8cb63b89e1d2b1bb41614a967e` denies OPERATOR-role POST attempts to change the Google Drive folder setting.
- Admin role checks were centralized in `admin_can_manage_settings()` in `app/services/admin_auth.php` (commit `a9b8c4c7321fab6924f4175d1419ce0acaa74c65`). Both `public/admin/index.php` (Drive setting) and `public/admin/game-mappings.php` (supplier mapping mutations) now call that helper (commits `da72aa030fe641a4a3ed7b11191e303b790fc78f` and `b13b7ade7f24af5cb742e67c001da28ffb916d89`).
- Added `tests/admin-authorization.php` for role allow/deny cases and wired it into `.github/workflows/php-quality.yml` (commits `6134f52527d8d0882b92e237847ba3ed3086b197` and `a6a15d6131b21d577611153075ff8b1106665ed6`).
- Audit row updated in `docs/production-readiness-audit.md` (commit `6f19570577ed02383dfb4a571e8d018b1f9b6c6c`).
- Queue race audit: found that a duplicate queue job could see a supplier transaction in fresh `PROCESSING` state while another worker was still making the supplier request, and incorrectly force manual review. `app/services/fulfillment.php` now treats `PROCESSING` rows updated within the last 10 minutes as active and returns `IGNORED` for the duplicate job; older ambiguous states still go to manual review (commit `43ba2b74f1dae5644cc0d0fdfe5574ad09a7d5c9`). This is source-level mitigation; DB-backed concurrency tests are still required.
- Updated audit notes with the queue finding and observed CI step results (commit `ef878ab554a96271401df9c468fed3c7c40e5eab`).
- Added `tests/duplicate-queue-processing-integration.php`, which creates a disposable paid order plus a fresh supplier `PROCESSING` row, invokes `fulfillment_process()`, and asserts the duplicate path returns `IGNORED` without changing order/supplier status or calling a supplier endpoint. Added the test step to `.github/workflows/fulfillment-tests.yml` (commits `a9d8a8a4fbf6f629038fb24fcd74efa7132ed0b6` and `25b03689aec41a4015ce8cc2cd74bcab085f7185`). Audit updated at `c7b682f695a68999217cc7bb5700ad422ceb2041`.
- MySQL integration run `38201807243` confirmed the new duplicate-queue test passed, as did duplicate callback and stale queue recovery tests. It then failed in `tests/uncertain_supplier_integration.sh` because that fixture created a fresh `PROCESSING` supplier transaction, while the new guard correctly treats records updated within 10 minutes as an active request. Corrected the fixture to set `updated_at` to 11 minutes ago so it models an abandoned request (commit `92a0ddb13644c353966dd520567ebf606e8e586b`). Audit records this finding in commit `205836d7482fe60a48991bfd22294ef6b3b5fbce`. Verify rerun before treating integration suite as passing.

## Latest work log (2026-10-12)

- Reviewed `public/admin/index.php`, `public/admin/game-mappings.php`, and `app/services/admin_auth.php`.
- Centralized the allowed mutation roles (`SUPER_ADMIN`, `ADMIN`) in `admin_can_manage_settings()` and applied it to both Drive folder settings and game-provider mapping changes.
- Added role-policy regression tests for allowed roles, `OPERATOR`, missing/empty roles, and case sensitivity; wired the test into GitHub Actions.
- Updated `docs/production-readiness-audit.md` and this handoff after the code changes.
- Verification update: GitHub Actions runs `38201807339` (`PHP quality checks`) and `38201807369` (`PHP and JavaScript syntax checks`) completed successfully for code commit `c7b682f695a68999217cc7bb5700ad422ceb2041`. In `38201807243` (`Fulfillment safety tests`), the PHP unit-test job passed. MySQL integration confirmed duplicate queue processing, duplicate callback, and stale queue recovery, then failed at the ambiguous supplier test because its fixture was not aged beyond the new 10-minute guard. The fixture was corrected in commit `92a0ddb`; recheck the rerun before treating the suite as passing. No local PHP run is claimed.

## Tests and what is actually verified

GitHub Actions workflow: `.github/workflows/php-quality.yml`
- PHP 8.2 lint over PHP files in `app`, `bin`, `config`, `public`, and `tests`.
- `tests/duitku-callback-result-state.php`
- `tests/checkout-request-fingerprint.php`
- `tests/fulfillment-mapping.php`
- `tests/admin-authorization.php`
- `tests/duplicate-queue-processing-integration.php` (MySQL integration workflow only; pending run result)

Runs `38202791491` (PHP quality), `38202791510` (PHP/JavaScript syntax), and `38202791455` (fulfillment safety tests) all completed successfully for commit `455499aa42c3f99fdd31587db2e21d36613da408`. The refund crash-window recovery (`e221f01`) and integration test (`7908e11`) were verified by fulfillment run `38203732917`: both `php-tests` and `mysql-callback-integration` passed, including the explicit supplier rejection/refund integration step. After that, `app/services/fulfillment.php` was hardened in commit `30c0735` so a late supplier HTTP response cannot overwrite a `REVIEW` state written by stale-lock recovery. PHP quality, syntax, and fulfillment safety workflows for `30c0735` all passed (runs `38204088585`, `38204088548`, `38204088535`). A deeper follow-up in commits `3cf9eaf` and `b039424` serializes both normal and uncertain supplier response persistence in a DB transaction that locks the order before the supplier transaction, matching stale-lock recovery's lock order. CI for these newest commits is pending. A local clone previously failed because the execution environment could not resolve `github.com`. CI does not certify live provider/payment behavior.

## Production safety — hard stop

Keep all of these disabled until controlled staging tests pass:
- `CHECKOUT_PAYMENTS_ENABLED=false`
- `SUPPLIER_ORDERS_ENABLED=false`
- settlement/Google Drive backup flag disabled.

Do not deploy or initiate live transactions, real supplier orders, or Google Drive writes while auditing. Never put API keys, Duitku secrets, supplier credentials, OAuth tokens, or production `.env` contents in Git or chat.

## Highest-priority next steps

1. **Continue admin security audit**: `public/admin/` currently contains `index.php` and `game-mappings.php`; both POST routes use CSRF validation, and privileged mutations use `admin_can_manage_settings()`. A regression test covers valid and invalid/missing CSRF tokens alongside role checks; it passed quality, syntax, and fulfillment workflows in runs `38210544538`, `38210544554`, and `38210544547`.
2. Inspect the rest of `public/admin/index.php` and each admin page for privilege-sensitive actions. Confirm the new Drive-folder role check works for OPERATOR and allowed roles.
3. Review the queue and fulfillment transaction boundaries: concurrent worker claims, lock-token conditional updates, pending supplier responses, retry limits, stale-lock recovery, duplicate queue jobs, and reconciliation path. A MySQL concurrency test was added in `tests/queue-worker-concurrency-integration.php` and wired into the fulfillment workflow before callback tests. First CI attempt failed because a live parent PDO connection was inherited across `pcntl_fork()`, causing `MySQL server has gone away` during parent verification/cleanup. Commit `94f806c` changes the fixture to use a direct fresh PDO connection, close the parent connection before forking, reconnect after child exit, and use a fresh cleanup connection. Its CI result is pending; do not count concurrency coverage as passing yet. Do not alter safe manual-review behavior without evidence.
4. Review all migrations 001–010 and `bin/preflight.php` against a clean MySQL/MariaDB install and an upgrade path. Execute only when a real test DB/runtime is available.
5. Expand tests for pricing/reserve boundaries, callback duplicate/conflicting transitions, checkout idempotency concurrency, refund idempotency, admin roles/CSRF, and queue concurrency.
6. Continue with DB-backed concurrent-worker tests and full staging checks. The new test starts two independent PHP processes/PDO connections and asserts they claim distinct ready jobs with separate lease tokens. The first run exposed an inherited parent PDO socket; commit `94f806c` closed it before forking, and commit `5632ac5657` also releases the parent statement before the fork. The latest fulfillment workflow `38213945487` passed, including `Test concurrent queue claims against MySQL`; PHP quality `38213945851` and syntax `38213945489` also passed. Checkout UUID validation now has valid/malformed input tests (`6fa1d80`). Review of concurrent checkout handling found that a MySQL deadlock/serialization failure during simultaneous use of the same absent idempotency key would fail closed but not attempt to reuse the winning request. Commit `103cf16` classifies duplicate-key, deadlock, and lock-wait conflicts for a post-rollback winner lookup; it does not re-call Duitku. Commit `59c893b` adds unit coverage for SQLSTATE classification. The first quality run (`38238953683`) caught a test-fixture error: PHP `PDOException` requires an integer code. Commit `c2568d9` fixes the test fixture; CI rerun is pending. The fulfillment integration workflow now applies migrations 009 and 010 after 008 (commit `ec6a6bc`); its workflows passed after documentation updates in runs `38237885006`, `38237884979`, and `38237885082`. Atomic supplier response persistence/lock ordering and worker lease revocation are covered by passing CI runs listed above.
7. Keep `README.md`, `docs/production-readiness-audit.md`, and this handoff updated after substantive changes.

## Required staging exit checklist

- Clean install and upgrade migrations 001–010 on the target MySQL/MariaDB version.
- Verify private `.env`, file permissions, HTTPS, PHP extensions, outbound HTTPS and cron capability on the selected shared-host plan.
- Test valid/invalid Duitku HMAC, wrong amount/order, duplicate callback, pending-then-paid, and conflicting status transitions.
- Run same idempotency key concurrently with same and different payloads; ensure only one order/payment exists and payload mismatch never reuses a payment URL. Unit coverage validates request fingerprint normalization; UUID shape regression cases were added in commit `6fa1d80` and await CI. The MySQL integration workflow applies migrations 009 and 010 after 008. A DB-backed concurrent checkout test with a mocked Duitku API remains pending; the current race-classification unit test does not substitute for that integration test.
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
