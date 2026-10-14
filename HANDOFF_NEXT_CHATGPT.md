# PluQ-I — Handoff for the next ChatGPT session

Last updated: 2026-10-13 (Asia/Jakarta) — admin checkout hints and timeout CI verified; manual reconciliation is next
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
6. Continue with DB-backed concurrent-worker tests and full staging checks. The new test starts two independent PHP processes/PDO connections and asserts they claim distinct ready jobs with separate lease tokens. The first run exposed an inherited parent PDO socket; commit `94f806c` closed it before forking, and commit `5632ac5657` also releases the parent statement before the fork. The latest fulfillment workflow `38213945487` passed, including `Test concurrent queue claims against MySQL`; PHP quality `38213945851` and syntax `38213945489` also passed. Checkout UUID validation now has valid/malformed input tests (`6fa1d80`). Review of concurrent checkout handling found that a MySQL deadlock/serialization failure during simultaneous use of the same absent idempotency key would fail closed but not attempt to reuse the winning request. Commit `103cf16` classifies duplicate-key, deadlock, and lock-wait conflicts for a post-rollback winner lookup; it does not re-call Duitku. Commit `59c893b` adds unit coverage for SQLSTATE classification. The initial fixture failed because PHP `PDOException` requires an integer code; commit `c2568d9` fixed it. CI for `c2568d98b0` passed PHP quality (`38239039733`), syntax (`38239039554`), and fulfillment (`38239039583`). A PHP-quality failure on older documentation commit `5920d6f0a5` was confirmed to be the pre-fix fixture and is superseded by the passing corrected commit. The fulfillment integration workflow now applies migrations 009 and 010 after 008 (commit `ec6a6bc`); its workflows passed after documentation updates in runs `38237885006`, `38237884979`, and `38237885082`. Atomic supplier response persistence/lock ordering and worker lease revocation are covered by passing CI runs listed above.
7. Keep `README.md`, `docs/production-readiness-audit.md`, and this handoff updated after substantive changes.

## Required staging exit checklist

- Clean install and upgrade migrations 001–010 on the target MySQL/MariaDB version.
- Verify private `.env`, file permissions, HTTPS, PHP extensions, outbound HTTPS and cron capability on the selected shared-host plan.
- Test valid/invalid Duitku HMAC, wrong amount/order, duplicate callback, pending-then-paid, and conflicting status transitions.
- Run same idempotency key concurrently with same and different payloads; ensure only one order/payment exists and payload mismatch never reuses a payment URL. Unit coverage validates request fingerprint normalization; UUID shape regression cases were added in commit `6fa1d80` and corrected classifier unit cases passed PHP quality and syntax in runs `38242799852` and `38242799858`. The MySQL integration workflow applies migrations 009 and 010 after 008. Added `tests/checkout-idempotency-concurrency-integration.php` and wired it into `.github/workflows/fulfillment-tests.yml` (commits `e64061e` and `d6f848f`). It forks two PHP processes with independent PDO connections, delays the first mocked gateway response so the second request observes a committed order without a URL, and asserts one order/payment, exactly one mocked Duitku call, reuse of the winner URL, and rejection of changed account data for the same key. The checkout service now waits up to 22 seconds for the winning URL on the same-key path and after classified DB race errors (commit `623d0c6`). The concurrent checkout integration passed in fulfillment run `38240603363`; the real MySQL deadlock-classification integration passed in run `38242948715`. Latest follow-up documentation commit `e33a8140be` passed quality (`38242987438`), syntax (`38242987397`), and fulfillment (`38242987424`). Full-path recovery after an induced deadlock remains an explicit test gap.
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


## Latest work — checkout deadlock and admin HTTPS proxy trust (2026-10-12)

- The real MySQL deadlock classification test passed in fulfillment run `38242948715`; both `mysql-callback-integration` and `php-tests` completed successfully. Documentation follow-up workflows on commit `e33a8140be` passed quality (`38242987438`), syntax (`38242987397`), and fulfillment (`38242987424`). Full-path recovery from a deliberately induced deadlock inside `checkout_create_payment()` remains untested and is still a production-readiness gap.
- Admin session cookie setup previously trusted `HTTP_X_FORWARDED_PROTO=https` unconditionally. Commit `875866f` introduces `admin_request_is_https()`; forwarded proto is accepted only when `ADMIN_TRUST_PROXY_HTTPS=true`, while direct `HTTPS=on` remains accepted. `.env.example` documents the setting as false by default; only enable it when the trusted reverse proxy strips client-supplied forwarded headers. Regression tests in `tests/admin-authorization.php` cover untrusted forwarded headers, explicit proxy trust, multi-value headers, and direct HTTPS.
- For commit `022fe0052b`, PHP quality run `38244155564` and syntax run `38244155580` passed. Fulfillment run `38244155610` was still in progress at the last check; recheck it before claiming all workflows passed. Do not deploy or enable live payment/supplier flags.


## Duitku failed-callback state consistency (2026-10-12)

- Audit found the definitive `resultCode=01` callback marked `payment_transactions.status=FAILED` and `orders.payment_status=FAILED` but left a still-pending `orders.order_status=PENDING` behind. Commit `e9ffef5` now also transitions order status from `PENDING` to `FAILED`, without overwriting later/non-pending order states.
- Added a second disposable pending order fixture to `.github/workflows/fulfillment-tests.yml` and extended `tests/duitku_callback_integration.sh` (commit `dabd808`) to send a valid signed `01` callback and assert payment/order both become `FAILED` and no fulfillment job is added. This is test-only fixture data; no live API calls.
- CI for this callback-state change and integration assertion must be checked before marking it verified. Keep live payment/supplier flags disabled.


Integration-test fixture correction: commit `649d3e2` fixes shell variable expansion in the failed-callback test (`tests/duitku_callback_integration.sh`). The latest CI triggered by this correction is pending; verify the MySQL integration job before considering the regression covered.


## Latest verification — failed Duitku callback state consistency (2026-10-12)

- Code change: commit `e9ffef5` closes a still-pending order as `FAILED` when a valid Duitku server-to-server callback has `resultCode=01`; it does not overwrite non-pending order status.
- Integration fixture correction: commit `649d3e2cd1` fixes shell variable expansion in `tests/duitku_callback_integration.sh`. The MySQL integration job and PHP test job both passed in fulfillment run `38246227164`; the `mysql-callback-integration` job specifically completed successfully. Follow-up documentation workflows on commit `7e7723095e` also passed: PHP quality `38246259533`, PHP/JavaScript syntax `38246259565`, and fulfillment safety `38246259539`.
- The integration test verifies the signed failed callback marks payment and pending order failed, does not enqueue fulfillment, and that duplicate successful callbacks enqueue only one job. No live gateway/supplier calls were made.
- Next focused audit gap: build a controlled integration test for full `checkout_create_payment()` recovery after a real deadlock, not just classifier behavior. Keep checkout/payment, supplier order, and settlement backup flags disabled; do not deploy.


## Duitku callback rejection tests added (2026-10-12)

- Commit `fc7b8788890d6de2` extends `tests/duitku_callback_integration.sh` to verify that an invalid HMAC signature and a correctly signed callback with the wrong amount both receive HTTP 400 and leave payment/order pending with no fulfillment job. The existing valid duplicate-success and definitive-failure cases remain in the same MySQL integration script.
- Verified: PHP quality (`38251844581`), PHP/JavaScript syntax (`38251844521`), and fulfillment safety (`38251844517`) all passed for commit `fc7b8788890d6de2c62f231b9016d67a39c30765`; the fulfillment run's `mysql-callback-integration` job completed successfully, including both new negative cases. Follow-up documentation workflows were triggered separately. This is a local test fixture only; no live gateway or supplier requests were made. Production flags remain disabled.


Verification update: callback rejection coverage from commit `fc7b8788890d6de2c62f231b9016d67a39c30765` passed all three CI workflows (quality `38251844581`, syntax `38251844521`, fulfillment `38251844517`). The MySQL integration step confirms invalid HMAC and mismatched amount return HTTP 400 while leaving the payment/order pending and adding no fulfillment job; the duplicate-success and failed-payment callback assertions also pass. Full-path deadlock recovery in `checkout_create_payment()` remains an open production-readiness test gap.


## Checkout concurrency regression strengthened (2026-10-13)

- Commits `93a13db9ac` and `f7508904ed` remove the deliberate 300 ms stagger between the two forked checkout requests so they compete more directly for the same idempotency key. Commit `f7508904ed` adds a filesystem start barrier so both child processes begin the checkout path together. This increases coverage of the absent-key/gap-lock race while preserving the mock Duitku creator and assertions for one order, one payment, one gateway call, and winner URL reuse.
- CI triggered by these changes is pending; inspect the MySQL integration job before calling the new race setup verified. This still does not prove the DB actually emitted error 1213 from inside `checkout_create_payment()`; the existing direct deadlock integration only proves classification. If CI is flaky or no deterministic deadlock is observed, document that limitation rather than claiming full-path deadlock recovery proven. No live requests; production flags remain disabled.


## Latest MySQL integration work (2026-10-13)

Workflow commit `bb8549257b9b35bf5214152c50905192e6ab05a4` changes the concurrent checkout idempotency test to run three independent iterations against MySQL with mocked Duitku. Existing checks still assert one order/payment, exactly one gateway mock call, identical payment URL reuse, and rejection of a changed account payload. Await CI for this workflow commit. This increases stress coverage but does not prove full-path deadlock recovery inside `checkout_create_payment()`; keep that gap open. Do not enable live checkout/supplier flags or deploy. No live APIs were called.


CI note: initial 3-iteration MySQL stress run found the test incorrectly assumed child 1 always wins; two iterations passed and the third failed that assertion. Commit `d518075b5d8b3b8bfa5c4b8f284eb4d072a24813` makes the assertions scheduling-independent (exactly one creator and one reuser). Verify the new CI before considering it green. This was a test assertion flaw, not evidence of checkout code failure. Full-path deadlock recovery remains an open test gap.


## Checkout full-path deadlock recovery test (2026-10-13)

Commit `4e33bf5dcd08` passed quality (`38283808180`), syntax (`38283808125`), and fulfillment (`38283808131`); MySQL integration passed three checkout idempotency iterations and the separate deadlock classifier test. Commit `570d6daf401375feb4fd39f822d63d8e0d7a6284` adds a stronger assertion around the three-iteration checkout stress test: the MySQL `Innodb_deadlocks` counter must increase during that window. The integration already asserts both callers succeed, one order/payment, exactly one mock Duitku call, and URL reuse. Await CI for `570d6daf` before claiming this proves full-path recovery. A failed counter assertion means the run did not observe a real deadlock; it must not be described as a code failure or a pass. No live APIs; do not deploy or enable checkout/supplier flags.


CI result for commit `570d6daf401375feb4fd39f822d63d8e0d7a6284` (2026-10-13): PHP tests passed, PHP/JS syntax and PHP quality passed, and all three concurrent checkout iterations passed their idempotency assertions. However, MySQL reported `Innodb_deadlocks` before=0 and after=0. Therefore the stress run did **not** exercise a real deadlock inside `checkout_create_payment()`; it does not prove full-path deadlock recovery. The workflow was adjusted in commit `7113cdae9c22007fcf5b90db523b03354aef2960` to report this limitation without failing otherwise-valid stress tests. Next task: design a deterministic integration schedule that provokes a real 1213 during checkout, or retain the gap explicitly. The separate real-deadlock classification test remains useful but is not equivalent. No live APIs or deployment; checkout/supplier flags remain disabled.


Workflow verification for commit `7113cdae9c22007fcf5b90db523b03354aef2960`: PHP/JS syntax `38315071913`, PHP quality `38315071804`, and fulfillment safety `38315071794` all passed. The MySQL integration job passed, including three concurrent checkout idempotency runs; it reported no natural deadlock rather than failing. Documentation follow-up commit `3fe14f4ca3a04f5a686b61fd5efaab6708c9e34a` also has syntax `38315106885` and quality `38315106999` passed, and fulfillment `38315106585` passed both PHP and MySQL jobs. Next: build a deterministic deadlock choreography if feasible; otherwise keep full-path recovery explicitly unverified. No live APIs/deployment; production flags remain disabled.


## Deterministic checkout deadlock choreography added (2026-10-13; CI pending)

Commit `58ee5b048800c490538ad04cb754b58befc680a0` updates `tests/checkout-idempotency-concurrency-integration.php`: after both child processes are forked, the parent acquires a lock on the test product row, releases the child start gate, and polls `performance_schema.data_lock_waits` until both checkout workers are blocked by that parent lock. The parent then releases the product row. This is intended to make both children retain their missing-checkout-key gap locks while competing for the same product row, provoking an InnoDB deadlock in the real `checkout_create_payment()` transaction. The test asserts both callers succeed, exactly one order/payment and mock gateway call exist, both callers share the winning URL, and the global InnoDB deadlock counter increases during that test. This is not verified until CI passes; if the lock choreography does not trigger a deadlock or the recovery fails, inspect MySQL job logs and revise rather than claiming success. No live APIs or deployment; production flags remain disabled.


### Deterministic checkout deadlock attempt — negative result (2026-10-13)

- CI for test commit `58ee5b048800c490538ad04cb754b58befc680a0` failed at the new assertion: the MySQL global `Innodb_deadlocks` counter remained `before=0 after=0`. The test had observed both workers waiting on the parent-held product row, but releasing that lock did not produce a deadlock. Therefore the choreography did **not** prove full-path deadlock recovery; do not interpret the failed assertion as a confirmed product-code regression.
- Reverted only `tests/checkout-idempotency-concurrency-integration.php` to the previously passing synchronized stress test in commit `065a10ca522f9d24ed213dd7c9ac583a7e49d4ac`. It continues to verify same-key concurrency, one order/payment, one mocked Duitku call, URL reuse, and payload-mismatch rejection. CI for the revert is pending.
- The separate MySQL deadlock integration still proves the SQLSTATE/driver error classifier handles a real 1213 victim, but it does not prove that the complete checkout function recovers after a real deadlock. Keep this gap explicit. Next work should design a deterministic full-path test without relying on the current product-row lock choreography, or leave the gap open rather than claim success.
- No live gateway/supplier APIs or deployment were used; production flags remain disabled.


### Verification after reverting the unproven deadlock choreography (2026-10-13)

Commit `065a10ca522f9d24ed213dd7c9ac583a7e49d4ac` passed all three workflows: PHP quality `38319787900`, PHP/JavaScript syntax `38319788215`, and fulfillment safety `38319788002`. The fulfillment run's PHP tests and MySQL integration job both completed successfully, including the restored concurrent checkout idempotency stress test. This verifies the restored stress coverage only; full-path recovery after an actual deadlock inside `checkout_create_payment()` remains unproven. Documentation-only follow-up commit `ef5e72894d4ffa7bcc51ca2c577a356102f4511e` passed quality `38319827522` and syntax `38319827634`; its fulfillment run `38319827341` was still in progress at last check. No live APIs or deployment.


### New deterministic checkout deadlock integration attempt — awaiting CI (2026-10-13)

- Commit `69b4cd0e3105d5fa061c34e16e42d08f36a0b01c` adds an optional pre-product-lock callback to `checkout_create_payment()`, guarded so it is accepted only when `APP_ENV=test`. Production callers omit the callback.
- Commit `d9ad0feb8d0fa98a18a4f0f64bf967d038bbf524` changes the MySQL checkout integration test so two independent same-key checkout transactions lock seeded provider rows in opposite order inside the checkout transaction. Both workers synchronize at a filesystem barrier before requesting the second row, so InnoDB should choose one real deadlock victim (1213). The test then requires both checkout calls to succeed, exactly one mock gateway call/order/payment, the same payment URL for both callers, rejection of a mismatched account payload, and an increased global `Innodb_deadlocks` counter.
- This is a new test strategy and is **not yet verified**. Await MySQL integration CI and inspect exact logs. If it fails, record the observed error and do not claim recovery. The hook is guarded by `APP_ENV=test`; no live APIs or deployment were used, and production transaction flags remain disabled.


### Deadlock integration test harness correction (2026-10-13)

The first CI attempt for commit `d9ad0feb8d0fa98a18a4f0f64bf967d038bbf524` failed before checking the deadlock counter because the test had deliberately unset the parent PDO before forking and then tried to query the counter through that unset variable. This was a test-harness connection-lifecycle bug, not a deadlock-recovery result; no claim about the application path can be drawn from that run. Commit `5b9452144202d8e97aa5c5d8259db5e36643ad04` reconnects through `database()` before reading the post-run counter and is awaiting CI. Continue by inspecting the MySQL integration logs for all three attempts and the counter assertion. Production flags remain disabled; mock gateway only.


### Direct deadlock evidence replaces status-counter inference (2026-10-13)

The corrected test run for commit `5b9452144202d8e97aa5c5d8259db5e36643ad04` reached the checkout assertion but reported `before=0 after=0`. The integration test did not produce a recorded exception, and the global status counter path is not a reliable proof source in this run. Commit `80eeec3f6ef66798b684a4eb66424719e51cc4c1` now captures the actual `PDOException` from each worker's second provider-row lock and asserts exactly one driver error `1213` classified by `checkout_is_idempotency_race()`; both checkout calls must still return the same order/URL with one mock gateway call. Workflow commit `eb30fe8539b6a7cdb9d8f5417ddcfd3dde744c70` removes the misleading global status counter and runs the deterministic test three times. CI is pending. Do not claim full-path recovery until the MySQL integration job passes all three iterations.


### Full-path checkout deadlock recovery verified in MySQL CI (2026-10-13)

- Commit `80eeec3f6ef66798b684a4eb66424719e51cc4c1` passed PHP quality `38321635015`, PHP/JavaScript syntax `38321635052`, and fulfillment safety `38321635067`. The MySQL integration job completed successfully, including all three iterations of `tests/checkout-idempotency-concurrency-integration.php`.
- In each iteration, the integration test's opposing provider-row locks occur inside `checkout_create_payment()`; it captures the actual exception from the second lock attempt and asserts exactly one driver error 1213 classified by `checkout_is_idempotency_race()`. Both callers must still succeed with one order/payment, one mock Duitku call, identical payment URL, and changed-payload rejection. Passing all three iterations verifies the full-path deadlock recovery scenario using the mocked gateway.
- The previous `Innodb_deadlocks` global-status read remained `0 -> 0`; the workflow step was subsequently changed in commit `eb30fe8539b6a7cdb9d8f5417ddcfd3dde744c70` to rely on the direct 1213 exception evidence instead. That combined workflow's MySQL job was still in progress at last poll. Commit `c58719a03166cedf4537df373b18c2501282e1d9` updates the test success message to make the verified assertion explicit; its CI is pending.
- No live Duitku or supplier APIs were called and no deployment occurred. Keep production flags disabled until separate staging exit checks pass.


### Checkout gateway timeout retry guard verified (2026-10-13)

- Commit `808f17e33d0baa2d3c19a60b9deea8586b9ba2d4` updates `checkout_reuse_winning_request()`: when an existing checkout has no persisted gateway URL but has a recorded `orders.last_error`, same-key retry now fails promptly with a manual-review message instead of polling for up to 22 seconds. It does not repeat the Duitku request, because an HTTP timeout is ambiguous: the gateway may have created a payment even when the response was lost.
- Commit `a94f8449b333466a33aea585f558a145fd49eb4b` extends the MySQL checkout integration test with a mocked gateway timeout after the order/payment transaction commits. It asserts the first call records the issue, a retry returns promptly, the mock gateway is called only once, one order/payment remains, no URL is fabricated, and `last_error` is set for review.
- Verified CI for `808f17e3`: PHP quality `38326174963`, syntax `38326174976`, fulfillment `38326175007` all passed. Verified CI for `a94f8449`: PHP quality `38326198802`, syntax `38326198808`, fulfillment `38326198783` all passed; the MySQL integration job and PHP tests completed successfully.
- This is still mock-gateway integration evidence, not proof of Duitku's live timeout behavior or reconciliation APIs. Keep live payment/supplier flags disabled. Next: inspect whether admin tooling has a safe way to reconcile an order left pending after gateway timeout, and document an operational resolution path without retrying an ambiguous request automatically.


## Latest work — read-only checkout reconciliation hints (2026-10-13)

- Commit `2e6efab01a524af252bc27c78bed61ab59fe6cd5` updates `public/admin/index.php` to show a read-only “Catatan checkout” column: whether the Duitku payment URL was saved, gateway expiry timestamp, and escaped `orders.last_error` marked for review. It does not expose the actual payment URL or add payment/order mutation controls. Transaction table empty-state colspan was updated from 10 to 11.
- Verification for code commit `2e6efab0`: PHP quality run `38329325146` passed; PHP/JS syntax run `38329325150` passed; fulfillment safety run `38329325168` passed. The fulfillment run's `php-tests` and `mysql-callback-integration` jobs both completed successfully.
- Documentation follow-ups for the ambiguous gateway timeout protection passed all checks: handoff commit `813ec60ad4d8c93086b9e915b226a95f709599eb`, run `38329275031` (PHP and MySQL jobs success); audit commit `c2e38d90a58cb6748bec23b7ddd02463f22b3950`, run `38329284829` (PHP and MySQL jobs success).
- The admin hints improve operator visibility only. They do not query Duitku, prove whether a payment was created, or authorize a retry/refund. Next work should define a safe, auditable manual reconciliation procedure and review official Duitku status-query capabilities before implementing any live gateway integration. Do not make live API calls during this audit.
- Production checkout and supplier-order flags remain disabled. No deployment, live payment, or supplier order was performed.


## Duitku timeout reconciliation research (2026-10-13)

Official Duitku API reference: https://docs.duitku.com/api/id/ (section “Cek Transaksi”).
- Status lookup uses POST to `https://sandbox.duitku.com/webapi/api/merchant/transactionStatus` for development and `https://passport.duitku.com/webapi/api/merchant/transactionStatus` for production.
- Request fields: `merchantCode`, `merchantOrderId`, `signature`; current documentation specifies `HMAC_SHA256(merchantCode + merchantOrderId, apiKey)`. Older documentation pages show MD5, so do not copy legacy snippets.
- Status lookup response codes are **not the same semantics as callback result codes**: the current status-query documentation lists `00=Success`, `01=Process`, `02=Failed/Expired`. The existing callback mapper's `01=FAILED` applies to callback payloads only; do not reuse it for status-query responses.
- Duitku explicitly warns against repeated automatic status-query requests because rate limiting may block calls for about an hour. This supports an operator-initiated, audited lookup for ambiguous checkout cases—not a polling cron.
- Safe manual process: search by exact `merchantOrderId`; compare response order ID and amount to local `payment_transactions.amount`; store gateway reference and response evidence in an audit record; if status is `01` (Process), leave it pending and do not create a replacement; only consider replacement after an authoritative failed/expired response is matched and local payment/order state is checked under a transaction. A `00` success must follow the normal verified callback/state-transition path, not an ad-hoc admin status edit. Network errors, malformed response, unknown codes, amount/order mismatch, or uncertainty stay in manual review.
- No status-query API call was made and no live credentials were used. Before coding this integration, add unit tests for signature/response validation and status-code mapping, then mock-only integration tests for mismatch, process, success, failed/expired, rate/error handling, and concurrent callback interactions. Do not add a repeated cron poller.


### Duitku transaction-status validation helpers — CI verified (2026-10-13)

- Added `duitku_transaction_status_signature()`, using HMAC-SHA256 over merchant code + merchant order ID; it is separate from callback signature validation.
- Added `duitku_transaction_status_state()` with the status-query meanings `00=SUCCESS`, `01=PROCESS`, `02=FAILED_OR_EXPIRED`.
- Added `duitku_validate_transaction_status_response()` to require exact merchant-order ID and integer amount matches, reject unknown codes/malformed fields, and return normalized evidence without changing database state.
- Added `tests/duitku-transaction-status.php` and wired it into `.github/workflows/php-quality.yml`.
- Verified on workflow commit `8e6e46497107`: PHP quality run `38331891216` passed (including the new unit test); syntax run `38331891186` passed; fulfillment safety run `38331891209` passed both `php-tests` and `mysql-callback-integration`. The preceding service and test commits also passed their workflows.
- **Not implemented yet:** network transport to Duitku status endpoint, admin action, audit event persistence, or any payment/order mutation. These helpers are validation only. Before adding transport, resolve official docs' conflicting content-type wording (the page labels the request x-www-form-urlencoded while its examples show JSON) and verify only against sandbox. Keep lookup operator-triggered and rate-conscious; do not add cron polling. No live calls/deployment; production flags remain disabled.


### Admin-triggered Duitku status reconciliation — mock-tested (2026-10-13)

- Added `public/admin/reconcile.php` and a link from the admin transaction page for `ADMIN`/`SUPER_ADMIN` roles only. It requires a valid admin session and CSRF token.
- Lookup is operator-triggered for one exact local `merchant_order_id`; no cron/polling. Same payment transaction is throttled for at least 60 seconds based on audit log history.
- The route queries the local payment/order, requires a whole-rupiah amount, calls the sandbox-only `duitku_status_config()` / `duitku_query_transaction_status()`, validates exact merchant order ID and amount, and writes a `DUITKU_STATUS_LOOKUP` audit event with the normalized status/reference or a sanitized error. It never changes payment/order states, enqueues fulfillment, or creates replacement payments.
- Added test-only transport injection guarded by `APP_ENV=test`; tests verify fixed sandbox endpoint, HMAC-SHA256 signature, one transport call, and rejection of injected transport outside tests. No live request was made.
- The official [Duitku API reference](https://docs.duitku.com/api/id/) labels transaction-status requests x-www-form-urlencoded but its PHP/cURL examples send JSON with `Content-Type: application/json`. The implementation follows the executable examples (JSON) but remains sandbox-only; verify actual behavior with sandbox credentials before any production work. Signature uses current HMAC-SHA256, not obsolete MD5. Status meanings are `00=SUCCESS`, `01=PROCESS`, `02=FAILED_OR_EXPIRED`.
- Verified current test commit `05d9f1ee497b`: PHP quality `38334462332` passed; syntax `38334462340` passed; fulfillment `38334462306` passed both PHP tests and MySQL integration. The earlier endpoint refactor's fulfillment run `38334449650` also passed.
- Remaining gap: a verified `SUCCESS` is displayed only. It does not yet feed a controlled reconciliation event through the callback's idempotent transition logic. Do not manually edit statuses or refund based on this read-only page. No live API calls or deployment; production checkout and supplier-order flags remain disabled.


### Follow-up security note — concurrent admin clicks (2026-10-13)

The 60-second cooldown currently checks recent audit rows before calling Duitku, but the check-and-call sequence is not atomic. Two simultaneous POST requests can both pass the check before either records its result. This is a known limitation; do not describe the current cooldown as concurrency-safe. Before production or any real Duitku use, serialize lookup admission using a short DB transaction that locks the payment row and commits a `STARTED` audit/lease record before network I/O, or use a dedicated unique lease row. Do not hold a DB transaction open during the HTTP request. Verify that parallel requests result in at most one outbound call. Current page remains sandbox-only; no live API calls/deployment.


### Atomic admin status-lookup cooldown added (2026-10-13)

Commit `public/admin/reconcile.php` now acquires a `SELECT ... FOR UPDATE` lock on the local `payment_transactions` row, checks recent lookup audit entries while holding the lock, and commits a `STARTED` audit entry before any HTTP request. A concurrent POST for the same payment waits for the lock and then sees the committed `STARTED` record, so it should be rejected by the 60-second cooldown rather than sending a second request. The database transaction is not held open during network I/O. No payment/order state is mutated.

This closes the previously documented check-then-call race by design, but a dedicated two-process MySQL integration test for simultaneous admin status POSTs has not yet been added. Existing CI checks lint/unit/fulfillment flows, not the full authenticated web route's concurrent admission path. Verify the route against the MySQL test environment and add a focused concurrency test before relying on this for production. Sandbox only; no live API call or deployment.


## Latest work — admin status lookup cooldown edge case (2026-10-13)

- Reviewed the current `public/admin/reconcile.php` against the latest atomic cooldown notes.
- Found that a rejected repeat click entered the generic error-audit path and wrote another `DUITKU_STATUS_LOOKUP` row. Because cooldown admission checks this same action, repeated rejected clicks could keep refreshing the 60-second window indefinitely.
- Commit `b8d7fa77e1d2363a561775ce7bbe0b7675e2db9c` adds a `$cooldownRejected` guard: cooldown-denied requests do not create a new lookup audit event. Real admitted attempts still record success/error outcomes. The row lock and committed STARTED marker remain unchanged; the transaction is still released before HTTP I/O.
- CI has not yet been rechecked for this new code commit at the time of this note. A dedicated concurrent MySQL/web-route regression test remains open. No local PHP run, live Duitku call, production payment, supplier order, deployment, or Google Drive write is claimed.


## Follow-up — cooldown regression check wired into CI (2026-10-13)

- Added `tests/reconcile-cooldown-regression.php` to structurally assert the admin route's row-lock/cooldown/STARTED/commit/network ordering and the cooldown-rejection audit guard.
- Added the test to the PHP quality GitHub Actions workflow.
- Test commit: `cce70d749ba6f809a3a2e582f7deeb99dde184e1`.
- Workflow commit: `61ad26239ba960b59e5c379c2de254a226c56b83`.
- This is a structural regression guard only. It does not simulate concurrent authenticated HTTP requests or prove MySQL row-lock behavior; that integration test remains open. No live gateway call, deployment, supplier order, or production payment was performed.


## CI verification update — cooldown regression guard (2026-10-13)

- PHP quality workflow [38340379209](https://github.com/sulistio95/PluQ-I/actions/runs/38340379209) passed on commit `78e58e8f67ea133d39f36acfc6d245f8956adc2a`.
- Confirmed the new `Test admin Duitku cooldown regression guard` step passed along with PHP lint and the existing callback, status-validation, checkout fingerprint, supplier mapping, and admin authorization tests.
- Limitation remains: this is a source-structure regression guard, not a concurrent two-process MySQL/HTTP test. Keep that test as an explicit pre-production requirement.


## Latest progress — concurrent Duitku cooldown integration test (2026-10-13)

- Extracted the atomic MySQL admission transaction into `app/services/duitku_reconcile.php` and wired `public/admin/reconcile.php` to use it. The route sets its no-duplicate-audit guard only for the explicit cooldown exception.
- Added `tests/reconcile-cooldown-concurrency-integration.php` that races two independent PHP processes against the same seeded MySQL payment. Expected: one admission, one cooldown rejection, one audit marker. It makes no gateway request. Added it to the MySQL 8.4 fulfillment CI and updated the structural guard to follow the helper.
- Commits: `ad24834b92ff8fb6887e7e8450b43b026c709b64`, `1efda2505449740b070f7054ab9c472cd83184f8`, `746fb1496499abb7b0d5acfd1ef564351187fc2b`, `bcc94ea9d08e8bd98c74ca6322f35b562c54c030`, `fa8ab44e4390033513db60fc2747e09a8bcfe143`, `804aa234151e808611c59501b0baca63b97183ec`, `bb740f156bcabda93ac315d60939351eb4ae9f99`, `8c7d050ca2e68e33f0068b952e7affe1ee6872a9`.
- **Next:** inspect GitHub Actions for the latest head; if the MySQL integration fails, fix the test/code and update both notes again. Until passing, label the test pending. This is concurrent helper/database admission coverage, not authenticated concurrent HTTP end-to-end coverage. No live APIs or deployment.


### CI follow-up — concurrent reconciliation test harness (2026-10-13)

- Inspected failed run [38343145833](https://github.com/sulistio95/PluQ-I/actions/runs/38343145833): the PHP test job passed; MySQL integration failed in the new test's final count query with `MySQL server has gone away`. Root cause: parent PDO socket remained open across `pcntl_fork()` and was reused afterward.
- Fixed the test to close the parent connection before forking and reconnect after both workers finish. Commit: `8844027fd6cf3290163a3a6cffa21672e4389d62`.
- Await the new CI run before claiming success. If it fails again, inspect the exact failing step/log and update both notes. No live payment/supplier requests.
## Latest work log — 2026-10-13 reconciliation concurrency verification

- Fixed the MySQL test harness so the parent PDO connection is closed before `pcntl_fork()` and a fresh connection is created after both children exit (commit `8844027fd6cf3290163a3a6cffa21672e4389d62`).
- Corrected CI passed on code commit `2ca838957423caa407fb49524ff23e16eff41723`: fulfillment [38347691175](https://github.com/sulistio95/PluQ-I/actions/runs/38347691175) passed both PHP and MySQL jobs, including the three concurrent reconciliation admission attempts; PHP quality [38347691176](https://github.com/sulistio95/PluQ-I/actions/runs/38347691176) and syntax [38347691179](https://github.com/sulistio95/PluQ-I/actions/runs/38347691179) passed. Documentation-only follow-up commit `ddea8e3a5e3ea6080f8716c1a7b389bc0f4e0cf8` also passed fulfillment `38347687156`, quality `38347687161`, and syntax `38347687166`.
- Verified scope is limited to the shared admission helper under two concurrent PHP processes and MySQL: one request is admitted, one is rejected by cooldown, and one marker remains. It is **not** a full authenticated HTTP route test and does not exercise actual outbound calls. No live Duitku calls, supplier orders, or deployment.
- Next: inspect feasibility of a sandbox-only, test-transport-injected HTTP-level test for `public/admin/reconcile.php`, covering authentication, CSRF, privileged role, and duplicate requests. Do not make live API calls. Continue to update this handoff and `docs/production-readiness-audit.md` after each substantive change/test.
### Reconciliation route security regression guard expanded (2026-10-13)

Commit `5877fa6e1fbd75bea0fe812c8e8a56ad4fecd738` extends `tests/reconcile-cooldown-regression.php` to check that the admin reconciliation route retains CSRF validation, privileged-role enforcement, and no direct payment/order mutation or checkout/fulfillment calls. This is a source-structure test, not a full authenticated HTTP test. CI is pending and must be inspected before claiming success. No live Duitku requests or deployment.
### Admin reconciliation HTTP boundary integration test added (2026-10-13)

- Added `tests/reconcile-admin-http-integration.php` and workflow step after local PHP server startup. Commits: test `77a1e0ed67460c84cc14eeea6252937fe5d9f4a8`, cleanup fix `b9e59514989c200e7f73b22c3e2d9fc365518655`, workflow wiring `136f11118aa181a44a533fae93017dcb746e6ddf`.
- Exercises real HTTP/session boundaries with disposable MySQL users: anonymous redirect (302), OPERATOR forbidden (403), invalid CSRF (403), missing explicit action (400), ADMIN form render (200). No valid status-lookup submission, no Duitku network call. Fixtures are deleted in `finally`.
- CI is pending; inspect job logs and fix failures before marking verified. Coverage still does not include duplicate valid HTTP lookup requests or real external transport. Continue updating this handoff and `docs/production-readiness-audit.md` for every substantive change/result.
### CI runner anomaly after adding HTTP boundary test (2026-10-13)

- New HTTP integration test and workflow wiring are in commits `77a1e0ed67460c84cc14eeea6252937fe5d9f4a8`, `b9e59514989c200e7f73b22c3e2d9fc365518655`, and `136f11118aa181a44a533fae93017dcb746e6ddf`; notes committed at `4927d490bbaf89542026332c1a5239d4cb47bd0f` and `ab23e93e316be34e7dd6bc7db7b14f6b9094f356`.
- Initial workflows `38394970823` (fulfillment), `38394970881` (syntax), and `38394970906` (PHP quality) failed within seconds with no job step summaries/log URL. Job-log endpoint returned 404, so root cause is not known and must not be labeled a test-code failure without evidence.
- Requested reruns for all three failed workflows; check their latest attempt/results next. A PHP 8.4 local syntax check of the script's function/HTTP client portion passed, but full PHP 8.2 CI and runtime integration remain unverified.
- Do not claim new HTTP test green until reruns finish. Keep production and external Duitku calls untouched.
### Follow-up CI attempts still fail before exposing job steps (2026-10-13)

- Reruns of the previous failed workflow attempts failed immediately too. Fresh runs triggered by commit `24d6bce9215add73efbfa6a13703c2f661011bd8` failed immediately: fulfillment [38395199538](https://github.com/sulistio95/PluQ-I/actions/runs/38395199538), PHP quality [38395199540](https://github.com/sulistio95/PluQ-I/actions/runs/38395199540), syntax [38395199525](https://github.com/sulistio95/PluQ-I/actions/runs/38395199525).
- Job payloads have `steps: null`, no log URL, and log endpoint returns 404. This is currently an opaque pre-step failure, not evidence that a particular PHP test failed. The new HTTP integration test remains unverified; do not claim CI is green.
- Next: investigate Actions runner/repository execution availability or rerun when logs are accessible. Keep production/payment/supplier API interactions disabled. Both this file and `docs/production-readiness-audit.md` were updated to capture this status.


## Latest continuation note — CI runner diagnosis (2026-10-13)

- Rechecked current Actions runs: fulfillment `38395277059` and PHP quality `38395277049` on `fd167feb7018cecd25365275654acdb973f6066b` fail within seconds; the job payloads expose `steps: null` and `logs_url: null`. Syntax run `38395199525` shows the same pattern. This affects unrelated workflows before their first visible step, so it does not establish a failure in the HTTP integration test.
- Root cause is still unknown. Next: check repository Actions settings, account billing/minute limits, and runner provisioning; avoid speculative source changes and do not label CI successful.
- Reviewed `tests/reconcile-admin-http-integration.php` against `app/services/admin_auth.php` and `database/schema.mysql.sql`. The test covers anonymous redirect, OPERATOR denial, invalid CSRF denial, unknown POST action rejection, and authorized admin form rendering. It never submits the real status-lookup action, so it must not call Duitku. Fixture cleanup uses `finally`. Full runtime/CI verification remains pending.
- Keep payment/provider live execution and deployment disabled. Update this handoff and `docs/production-readiness-audit.md` after every material code or test change.


- Additional confirmation: documentation-only commits `309c6b8b060ad4c10a20af77b0609162cbc06f6a` and `26aa6b64ee5f71acd1c34de9bbe9adc007e311e6` also triggered all three workflows and all failed immediately (runs `38395658550`, `38395658689`, `38395658589`, `38395668065`, `38395668156`, `38395668061`). Continue treating the cause as unknown and investigate Actions settings/billing/runner provisioning first.


## Follow-up — Actions runner provisioning investigation (2026-10-13)

- Compared the repeated PluQ-I symptom (all three workflows fail within seconds; jobs have `steps: null` and `logs_url: null`) with a GitHub Community discussion dated 2026-09-28 describing similar failures before runner assignment: https://github.com/orgs/community/discussions/209001. This makes runner provisioning/orchestration a plausible area to investigate, but it does not establish the cause for this repository.
- Billing reference: https://docs.github.com/en/billing/concepts/product-billing/github-actions. Owner-side checks needed in GitHub UI: repository Settings → Actions → General; account Settings → Billing & licensing / Budgets and alerts; check current GitHub Status at https://www.githubstatus.com/.
- If settings and billing are clean, contact GitHub Support with the failed run URLs and job IDs. Repeated retries already showed the same pre-step symptom, so avoid adding more application/workflow changes until a job exposes logs or steps.
- No tests are certified green by this investigation. The HTTP test's runtime execution remains pending; payment/provider APIs and deployment remain disabled.


- Latest confirmation after the investigation note commit: all three workflows again failed immediately on `851fddb169713d605360c2e49b71cdb87571e16b`: fulfillment `38397679614`, PHP quality `38397679661`, syntax `38397679602`. This remains a pre-step failure; no test is green and no root cause is established.


## Latest continuation — workflow source and run metadata (2026-10-13)

- Checked the latest head `2d559672eb132c4211eff93d529b749097fc54c8`: fulfillment [38397710451](https://github.com/sulistio95/PluQ-I/actions/runs/38397710451), PHP quality [38397710416](https://github.com/sulistio95/PluQ-I/actions/runs/38397710416), and syntax [38397710358](https://github.com/sulistio95/PluQ-I/actions/runs/38397710358) all failed immediately.
- Latest job API results still return `steps: null` and `logs_url: null`; fetching job logs returns 404. Reviewed `.github/workflows/fulfillment-tests.yml`, `.github/workflows/php-quality.yml`, and `.github/workflows/php-lint.yml`; inspected runner declarations and steps look conventional, with no obvious malformed `runs-on` setup. This cannot explain or prove the external failure.
- Reviewed the HTTP boundary test source: it uses a local PHP server, cURL, and disposable MySQL admin fixtures; no valid Duitku status lookup is submitted. The test remains runtime-unverified.
- No speculative application/workflow edits were made. Next step is owner-side repository Settings → Actions → General, account Billing & licensing / Budgets and alerts, GitHub Status, then Support if those look normal. Keep production deployment and live gateway/provider calls disabled. Update both notes after any subsequent material change/result.


## Controlled Actions retry result (2026-10-13)

- Requested a targeted rerun of syntax workflow [38399998833](https://github.com/sulistio95/PluQ-I/actions/runs/38399998833). Attempt 2 also failed (updated at 12:52:43 UTC). The same commit's PHP quality [38399998813](https://github.com/sulistio95/PluQ-I/actions/runs/38399998813) and fulfillment [38399998667](https://github.com/sulistio95/PluQ-I/actions/runs/38399998667) failed on their first attempts within seconds.
- The retry did not resolve the CI issue. No source/workflow edits were made; root cause remains unconfirmed and no new tests are certified green. Continue with owner-side Actions settings/billing/status checks or GitHub Support; do not deploy or enable live payment/provider calls. Audit updated in commit `0efb7848c15a3a70f20268ddea7130ccde40a4c6`.


## Latest continuation — Actions metadata rechecked (2026-10-13)

- Rechecked workflows triggered by handoff commit `a202647f6295ed4292637dc56a5961f7a0246bda`: PHP quality [38400685887](https://github.com/sulistio95/PluQ-I/actions/runs/38400685887) and fulfillment [38400685767](https://github.com/sulistio95/PluQ-I/actions/runs/38400685767) failed within about four seconds.
- Job metadata is still opaque: quality job `115307218283`, fulfillment jobs `115307218758` and `115307219224` show `steps: null` and `logs_url: null`. No application test step is visible; root cause remains unknown.
- Because this happened again on a note-only commit, do not blame the HTTP test or change application/workflow files speculatively. No additional retry was requested.
- Next action requires owner-side checks in repository Settings → Actions → General, account Billing & licensing / Budgets and alerts, and GitHub Status. If all are normal, contact GitHub Support with these run URLs and job IDs.
- The HTTP boundary integration test remains runtime-unverified. Keep live Duitku/provider requests and deployment disabled. The production-readiness audit was updated in commit `cb9ec0f6dcc9c2baff50c47aca9a34d4324ad1ad`.


## Continuation review — 2026-10-14

- Latest Actions run set on commit `15d163d8e27202c7946318bf237a3824720c1fb1`: PHP quality [38401404982](https://github.com/sulistio95/PluQ-I/actions/runs/38401404982), fulfillment [38401405037](https://github.com/sulistio95/PluQ-I/actions/runs/38401405037), and syntax [38401405068](https://github.com/sulistio95/PluQ-I/actions/runs/38401405068) all failed within seconds with no visible steps or logs.
- Re-reviewed the admin reconciliation route, admission helper, admin session/auth functions, HTTP integration test, and MySQL workflow. The test's session cookie name matches the application; the test exercises anonymous access, OPERATOR denial, CSRF rejection, unknown action rejection, and authorized form rendering. It does not submit the actual lookup action or contact Duitku.
- The admission helper commits the cooldown marker before network I/O and does not hold the DB transaction open during the request. No definite code defect was identified in this review, so no speculative source edit was made.
- Runtime execution of the HTTP integration test remains unverified. The blocker is still the opaque Actions pre-step failure; owner-side Actions settings/billing/status checks or GitHub Support are required. Do not enable live payments, supplier execution, or deployment until tests can run and staging checks pass.
- The production-readiness audit was updated in commit `5faf902970f9aaff42b75d22ea7448ea6c638e15`.


## CI check after latest documentation commits (2026-10-14)

- Audit commit `5faf902970f9aaff42b75d22ea7448ea6c638e15` triggered PHP quality [38468671002](https://github.com/sulistio95/PluQ-I/actions/runs/38468671002), which failed with no visible steps/logs.
- Handoff commit `f33f062705e451820aa5828266d62ff32f13d8dc` triggered syntax [38468686551](https://github.com/sulistio95/PluQ-I/actions/runs/38468686551), fulfillment [38468686528](https://github.com/sulistio95/PluQ-I/actions/runs/38468686528), and PHP quality [38468686565](https://github.com/sulistio95/PluQ-I/actions/runs/38468686565). All failed before any step or log was exposed. No test assertion is shown to have run.
- No source change was made because no specific code defect was established. The admin reconciliation HTTP integration test remains runtime-unverified. Next required step is owner-side GitHub Actions settings/billing/status investigation or GitHub Support; live payment/provider calls and deployment remain disabled.
- Audit note updated in commit `8fff19d3d64eed7a3182a1c8f08632fe9b43f86a`.


## Checkout idempotency review — 2026-10-14

- Re-read `app/services/order_checkout.php`, `app/services/checkout_quote.php`, `public/create-payment.php`, migrations 003/010, and checkout fingerprint/concurrency tests.
- Source review confirms unique checkout-key constraint, SHA-256 storage, request fingerprint binding email/game/product/account, rejection of same-key changed payload, no repeat Duitku dispatch on duplicate/deadlock recovery, and manual-review handling for ambiguous gateway timeout. Quote logic rechecks reserve balance, cheapest eligible offer, exclusions, and account-field validation.
- No definite source defect was established; no speculative source edit was made. Existing DB integration coverage is present but **not newly runtime-verified** because current GitHub Actions runs fail before showing any steps/logs.
- Latest runs on commit `213cccdd7e5db035ee0d3985884d0a64d12330f8`: PHP quality [38468745359](https://github.com/sulistio95/PluQ-I/actions/runs/38468745359) and fulfillment [38468745412](https://github.com/sulistio95/PluQ-I/actions/runs/38468745412) failed within seconds with job logs/steps unavailable; root cause remains unknown.
- Audit update: commit `f620f2f49f44f9ce6e3726dc2f87d0dfb59fef56` in [production-readiness-audit.md](https://github.com/sulistio95/PluQ-I/blob/main/docs/production-readiness-audit.md).
- Next: continue source review on a separate subsystem without enabling live gateway/supplier calls; owner-side GitHub Actions settings, billing/budget, GitHub Status, or GitHub Support remain necessary to restore test execution. Do not deploy or enable production payment flags before successful CI and staging verification.


## Duitku callback and queue lease review — 2026-10-14

- Reviewed `public/duitku-callback.php`, `app/services/duitku.php`, `app/services/queue_worker.php`, `app/services/fulfillment.php`, and callback signature tests.
- Callback signature, order ID shape, and amount are validated before payment-row locking. Repeated callbacks are acknowledged without repeating transitions. A fulfillment job is inserted only when the order transitions to PAID for the first time.
- Worker finalization requires the current PROCESSING status and matching lease token. Supplier ambiguity is routed to manual review instead of blind resubmission.
- No definite defect established; this is source review, not a newly executed runtime test. CI remains blocked by failures with no visible steps/logs.
- Audit updated in commit `ad1fd8f812a9f2416f7846b43c047b07d129450f`: [production-readiness-audit.md](https://github.com/sulistio95/PluQ-I/blob/main/docs/production-readiness-audit.md).
- Continue reviewing code/tests without speculative changes; do not enable live payment/provider processing or deploy while CI execution and staging checks are unresolved.


## Provider transport and admin reconciliation review — 2026-10-14

- Reviewed `app/services/provider_http.php`: HTTPS-only, TLS verification, redirects disabled, bounded timeouts, non-2xx and invalid JSON rejected, no request-body/credential logging. No live API call was made.
- Reviewed `app/services/duitku.php`, `app/services/duitku_reconcile.php`, and `public/admin/reconcile.php`: status query is sandbox-only, uses a dedicated signature, validates returned order ID and amount, does not mutate local payment/order state, and applies a row-lock plus 60-second audit-backed cooldown before network I/O.
- Reviewed admin mapping and transaction pages plus `app/services/admin_auth.php`: privileged-role checks, CSRF validation, transactionally coupled mapping/audit writes, and HTML escaping are present. No definite source-level defect was established in this pass.
- Review only; no PHP/MySQL tests were executed in this pass. No code change, live provider/payment call, or deployment. Keep production flags disabled until CI can run visibly and staging checks pass.
- Audit record: commit `967870a6926912cdcdc59cf8839453b5970ffb73` in [production-readiness-audit.md](https://github.com/sulistio95/PluQ-I/blob/main/docs/production-readiness-audit.md).


### GitHub Actions follow-up — 2026-10-14

- The documentation-only handoff update triggered PHP/JavaScript syntax workflow run [38472583538](https://github.com/sulistio95/PluQ-I/actions/runs/38472583538), which failed in seconds. GitHub's job metadata reports `steps: null` and `logs_url: null`; no test/lint step output is available, so this does not establish that application checks failed.
- Root cause remains undetermined. Do not change application code or workflow definitions speculatively based on this result. Repository owner should check Actions availability/settings, billing/budgets, GitHub Status, and contact GitHub Support if those are normal.


## Admin login review — 2026-10-14

Login page and rate-limit helper review found that a successful login clears failed-attempt history too broadly, rather than only the matching IP and username pair. A source correction was attempted but repository writes to the source were blocked; no code fix is committed. This finding remains open, with no new runtime tests. The audit entry is recorded in commit `6c67f8367480baca7932cc70ee9e5ae5a254bf28`.


## Admin login throttle cleanup correction — 2026-10-14

- Fixed `admin_login_clear_failures()` in commit `6d0e2f24b4397d0f48148ee4e7bf3e1e0e9a8a77`: cleanup now matches both the current IP and normalized username hash, so a successful login does not erase unrelated throttle history.
- Added `tests/admin-login-throttle-clear.php` (commit `8e624e68e1c97572aec71268a15ac7e548d9d3d9`) and wired it into the PHP quality workflow (commit `664e9883ce7241c519dc0affb91949503f0054f2`).
- CI run [38474440334](https://github.com/sulistio95/PluQ-I/actions/runs/38474440334) failed before job steps/logs were available, so the new test is **not verified by CI**. A local PHP 8.4 harness using the committed SQL predicate and assertions passed; a small `mb_strtolower` compatibility shim was needed because local PHP lacks mbstring, and the repository test file itself was not run from a full checkout. Next, restore observable Actions execution and run the committed regression test on PHP 8.2 with mbstring.
- No live provider/payment call or deployment. Keep production flags disabled.
- Audit detail: [production-readiness-audit.md](https://github.com/sulistio95/PluQ-I/blob/main/docs/production-readiness-audit.md), commit `d2e7f0e954956c0f0ebc6bcffbf40500122bb901`.


## Queue worker and fulfillment follow-up — 2026-10-14

- Reviewed `bin/worker-queue.php`, `app/services/queue_worker.php`, `app/services/fulfillment.php`, and related integration tests. Source review found transactional job claiming with random lease tokens, fail-closed stale-lock recovery, queue → order → supplier lock ordering, lease-token verification at finalization, manual review for ambiguous supplier outcomes, and idempotent pending-refund creation for definitive supplier failure. No additional definite defect was established. No DB-backed runtime tests were run in this pass.
- All three workflows still fail immediately without exposed steps/logs on current notes commits: syntax [38474672764](https://github.com/sulistio95/PluQ-I/actions/runs/38474672764), PHP quality [38474672798](https://github.com/sulistio95/PluQ-I/actions/runs/38474672798), fulfillment [38474672805](https://github.com/sulistio95/PluQ-I/actions/runs/38474672805). Root cause remains unknown; do not infer that application tests themselves failed.
- Keep live supplier/payment processing and deployment paused until Actions execution is inspectable and staging verification passes.
- Audit detail: [production-readiness-audit.md](https://github.com/sulistio95/PluQ-I/blob/main/docs/production-readiness-audit.md), commit `0a21f099fa60dab90d58a02502c33a4fd9683d80`.


## Supplier retry duplicate-order guard — 2026-10-14

- Source review found that a VIPayment `RETRYING` record with no saved provider `trxid` could reach the new-order request path. That is unsafe because the earlier request may already have been accepted by the supplier.
- Fixed in [9930da7](https://github.com/sulistio95/PluQ-I/commit/9930da74ac4d1d8f49e86add191811f4745064dd): ambiguous `PROCESSING` requests and VIPayment `RETRYING` records without a transaction reference now require manual review; VIPayment status polling is retained when a reference exists; Digiflazz retry remains fail-closed.
- Added `tests/fulfillment-retry-safety-test.php` in [99cfd7e](https://github.com/sulistio95/PluQ-I/commit/99cfd7ebaa150051ef6676cd2ed2b2a42ee0542e) and included it in `.github/workflows/fulfillment-tests.yml` in [4af58c0](https://github.com/sulistio95/PluQ-I/commit/4af58c07616cf8b64989ce5d6f9b01872a46e72f).
- CI/runtime result is not yet verified because Actions has been failing before steps/logs are exposed. No live API calls or deployment. Keep live processing disabled. Audit details: [production-readiness-audit.md](https://github.com/sulistio95/PluQ-I/blob/main/docs/production-readiness-audit.md).


### CI follow-up after retry-safety fix — 2026-10-14

- Latest syntax [38480851988](https://github.com/sulistio95/PluQ-I/actions/runs/38480851988), PHP quality [38480851789](https://github.com/sulistio95/PluQ-I/actions/runs/38480851789), and fulfillment [38480851797](https://github.com/sulistio95/PluQ-I/actions/runs/38480851797) runs failed without visible step/log metadata (`steps: null`, `logs_url: null`). This does not tell us whether the newly added regression test or application checks themselves failed.
- Next: restore inspectable GitHub Actions execution, then run the new `tests/fulfillment-retry-safety-test.php` and the existing PHP/MySQL integration suite. Keep live supplier/payment processing and deployment disabled. Audit details: [production-readiness-audit.md](https://github.com/sulistio95/PluQ-I/blob/main/docs/production-readiness-audit.md).


## Digiflazz balance validation — 2026-10-14

- Added strict validation for the Digiflazz `deposit` balance: it must be numeric, finite, and non-negative. Previously a malformed string could silently cast to zero. Commit: [9040192](https://github.com/sulistio95/PluQ-I/commit/9040192a403aa3afffb43eb6c448fc1774806c04).
- Added `tests/digiflazz-balance-validation-test.php` in [5dcad4e](https://github.com/sulistio95/PluQ-I/commit/5dcad4ed2e83dbb631b6f0facd0841c54d6cc1b7) and wired it into fulfillment CI in [c7b34f7](https://github.com/sulistio95/PluQ-I/commit/c7b34f7832be06c44be38fa425c9b8b809e1d1ce).
- CI execution is still not verified because recent Actions runs failed without visible step/log metadata. No live provider requests/deployment. Keep payment and supplier processing disabled until verification passes. Full audit: [production-readiness-audit.md](https://github.com/sulistio95/PluQ-I/blob/main/docs/production-readiness-audit.md).


### CI status after latest balance-validation change — 2026-10-14

- Runs [38482250710](https://github.com/sulistio95/PluQ-I/actions/runs/38482250710), [38482250685](https://github.com/sulistio95/PluQ-I/actions/runs/38482250685), and [38482250681](https://github.com/sulistio95/PluQ-I/actions/runs/38482250681) failed with no step/log metadata (`steps: null`, `logs_url: null`). This still does not identify the root cause or prove a specific application test failed.
- Continue with source-level review and regression additions only where a concrete defect is found; do not keep modifying workflows blindly. Arrange inspectable CI or controlled PHP/MySQL test execution before enabling any live payment or supplier flow.


## VIPayment balance response validation — 2026-10-14

- Source commit [2893932](https://github.com/sulistio95/PluQ-I/commit/2893932819fa8cafa3a1bf5905103df2bddd9675) adds `vipayment_map_profile_response()`, validating the profile balance as numeric, finite, and non-negative before returning it. The sync caller already had a second validation check; this hardening makes the adapter's own contract safe for other callers too.
- Added regression test [tests/vipayment-balance-validation-test.php](https://github.com/sulistio95/PluQ-I/blob/main/tests/vipayment-balance-validation-test.php) (commit [955fd57](https://github.com/sulistio95/PluQ-I/commit/955fd57aeb228e4f0d1b6213049e61cebdd0cdfa)) and wired it into the fulfillment workflow (commit [40e8bf3](https://github.com/sulistio95/PluQ-I/commit/40e8bf341e12d19a4307ec340826328ec7416c23)).
- Regression test remains unverified until CI steps/logs are visible or the suite is run in controlled PHP 8.2+ environment. GitHub Actions failures still expose no steps/logs; root cause unknown. Do not enable live Duitku/supplier processing or deploy. No live API call made.
- Audit details: [docs/production-readiness-audit.md](https://github.com/sulistio95/PluQ-I/blob/main/docs/production-readiness-audit.md), commit [004e5ce](https://github.com/sulistio95/PluQ-I/commit/004e5ceea2655bddfeaf2c318b4416aac60737a3).


### GitHub Actions runner allocation follow-up — 2026-10-14

- Checked job records for syntax, PHP quality, and fulfillment runs on `d690c7828117183ca43d1b6cff9c08052c4a0340`. Each job failed before normal steps were recorded (`steps: []`, `runner_id: 0`, no usable logs), which confirms the visible problem is pre-step execution but does not establish the cause.
- Actions permissions could not be inspected with the current connector. Next diagnostic is owner-side review of **Settings → Actions → General**, account billing/usage limits, repository policy, and GitHub Status; then rerun and inspect step logs. Do not blindly alter workflows or mark tests as passed.
- No source edits, test execution, supplier/payment calls, or deployment occurred in this diagnostic pass. Production processing stays disabled. Audit details: [docs/production-readiness-audit.md](https://github.com/sulistio95/PluQ-I/blob/main/docs/production-readiness-audit.md).


## Latest work — Duitku status amount overflow guard (2026-10-14)

- Fixed a numeric validation edge case in `app/services/duitku.php`: arbitrary-length numeric response amounts are now compared as normalized decimal strings, avoiding PHP integer saturation.
- Source commit: [b6bba9d](https://github.com/sulistio95/PluQ-I/commit/b6bba9d5e3c5e2f9c708ef5904b52d06cb0b9662). Regression test commit: [12042e3](https://github.com/sulistio95/PluQ-I/commit/12042e37603638481ce23cddeed87c4b8ac3601a).
- Added coverage for oversized values and the exact `PHP_INT_MAX` saturation case. Test execution is still unverified because GitHub Actions failures have not exposed normal steps/logs; do not report this test as passing.
- No live gateway/provider calls or deployment. Keep live payment and supplier-order flags disabled. Next: verify CI execution and run the PHP/MySQL integration suite before considering staging.


### CI status for latest regression (2026-10-14)

- Syntax workflow [38485787043](https://github.com/sulistio95/PluQ-I/actions/runs/38485787043) and fulfillment workflow [38485787071](https://github.com/sulistio95/PluQ-I/actions/runs/38485787071) failed immediately on handoff commit `2a465e53a1ccf66bba3ce03dd558df50b55f0705`. Jobs `115598975273`, `115598975592`, and `115598975726` expose no step summaries or logs. Do not interpret this as a test assertion failure; cause remains undetermined.
- The Duitku status amount overflow regression remains unverified. Next priority: resolve Actions pre-step execution or run tests in a controlled PHP 8.2+/MySQL environment. Live payment/provider calls and deployment remain disabled.


### Additional CI/log diagnosis — 2026-10-14

- Retried step-summary retrieval for jobs `115598975273`, `115598975592`, and `115598975726`; all return empty step arrays. Log retrieval returns `BlobNotFound`, so the runner/test failure cause remains unknown and no tests can be claimed to have run.
- Read-only review covered Duitku callback terminal-state handling, checkout idempotency, order-status display, and supplier recovery. No extra code change was made: contradictory terminal callbacks need DB integration coverage and a defined manual-reconciliation policy; do not auto-flip a terminal failed payment to paid without reconciliation.
- Next: restore CI logs or run PHP/MySQL integration tests in controlled staging. Keep live payments and supplier orders disabled; do not deploy.


## Contradictory terminal Duitku callback — 2026-10-14

- Added fail-safe reconciliation behavior in commit [9008996](https://github.com/sulistio95/PluQ-I/commit/9008996d5000da74b1fdd0dc0ed0006586582b26). If a signed callback contradicts a payment transaction's already-terminal state, the transaction's payment status is preserved, the order is flagged \`MANUAL_REVIEW\`, and \`audit_logs\` captures both states plus callback metadata. No new fulfillment job is enqueued.
- Added regression assertions in \`tests/duitku_callback_integration.sh\` (commit [8454a51](https://github.com/sulistio95/PluQ-I/commit/8454a51937c35ca4616403ae51b8b187d174f5cc)).
- **Not runtime-verified yet**: current GitHub Actions jobs still fail before exposing step summaries/logs. After the runner issue is resolved, execute the full MySQL integration workflow and verify the new conflict case. No live gateway/provider calls or deployment; keep live payment/supplier execution disabled.


## Repeat-callback audit deduplication — 2026-10-14

- Follow-up hardening: repeated identical signed callbacks that conflict with a terminal payment state now create one audit event per order + stored/incoming state pair, rather than one row per gateway retry. Source: [363d985](https://github.com/sulistio95/PluQ-I/commit/363d98545e872207cf68f95259e4915bfdff47ec).
- Integration script now sends the conflicting callback twice and asserts one audit row and no extra fulfillment job: [44dd349](https://github.com/sulistio95/PluQ-I/commit/44dd34915ab3679c8a73acce6ccd43a10205e7dc).
- Not runtime-verified yet. The Actions runs on the prior note commit failed before exposing runner steps/logs. Check fresh runs after this change; keep live payments, supplier orders, and deployment disabled until actual tests pass.


### Latest CI check — 2026-10-14

- After repeat-callback audit deduplication, the new runs (fulfillment 38493159152, PHP quality 38493159289, syntax 38493159116) also failed with no usable step logs. This is still an execution visibility blocker, not a proven source-level failure.
- New callback conflict idempotency coverage is committed but not verified by execution. Inspect repository/account Actions settings or run in a controlled PHP 8.2 + MySQL 8.4 environment before claiming success.


## Hosting and real-payment readiness update — 2026-10-14

- Created [docs/staging-payment-test-runbook.md](docs/staging-payment-test-runbook.md) in commit [4b418ad](https://github.com/sulistio95/PluQ-I/commit/4b418ada3fc5b7c9a0d9423e2942c25d1dda5ac0). It lists the staging setup, CI restoration, isolated Duitku sandbox test, supplier API verification, and production exit gates.
- New blocking source findings: `app/services/duitku.php` intentionally accepts only `DUITKU_ENV=sandbox`, so production Duitku support does not yet exist. `checkout_payments_enabled()` requires both `CHECKOUT_PAYMENTS_ENABLED=true` and `SUPPLIER_ORDERS_ENABLED=true`, so public checkout cannot currently be sandbox-tested independently of supplier-order enablement. Do not work around this by enabling supplier calls blindly; next code task is a sandbox-only payment path with no supplier purchase calls and DB integration tests.
- Fresh Actions job metadata for runs [38493246965](https://github.com/sulistio95/PluQ-I/actions/runs/38493246965) and [38493246898](https://github.com/sulistio95/PluQ-I/actions/runs/38493246898) shows runner ID 0 and empty step arrays. Root cause is still unknown; do not claim test execution or success.
- No live payment/provider call, supplier purchase, or deployment. Keep `CHECKOUT_PAYMENTS_ENABLED=false` and `SUPPLIER_ORDERS_ENABLED=false` until CI is observable and the sandbox-only path is implemented and verified.


## Isolated Duitku sandbox checkout — 2026-10-14

- Added `checkout_sandbox_only_enabled()` and updated the checkout gate in [6dbfa21](https://github.com/sulistio95/PluQ-I/commit/6dbfa21b79eb6b08c77652b72f0276df01461b32). This mode requires `APP_ENV=staging` or `test`, `DUITKU_ENV=sandbox`, `CHECKOUT_PAYMENTS_ENABLED=true`, and `SUPPLIER_ORDERS_ENABLED=false`. Production cannot use the isolated mode.
- Updated the Duitku callback in [7e6dbd7](https://github.com/sulistio95/PluQ-I/commit/7e6dbd7480e305d9e48f6385c2b0333aa065e8b9): a successful payment callback in sandbox-only mode records payment as `PAID`, sets the order to `MANUAL_REVIEW`, records that fulfillment is intentionally disabled, and does not enqueue `FULFILL_ORDER`.
- Expanded checkout gate tests in [e7bef5c](https://github.com/sulistio95/PluQ-I/commit/e7bef5cb8e8555b2579e6c0efb98d199027c69df) and added a MySQL-backed callback integration script in [b878eb3](https://github.com/sulistio95/PluQ-I/commit/b878eb318bf754cd39dccf2c499447334074e8ec). Wired a separate staging-mode callback server and fixture into the fulfillment workflow in [eb3063d](https://github.com/sulistio95/PluQ-I/commit/eb3063d377f3d5d7259f85b50828af79d8466a3a).
- **Not yet runtime-verified.** Runs [38497557866](https://github.com/sulistio95/PluQ-I/actions/runs/38497557866), [38497557852](https://github.com/sulistio95/PluQ-I/actions/runs/38497557852), and [38497557727](https://github.com/sulistio95/PluQ-I/actions/runs/38497557727) are queued at the time of this note. The preceding runs failed before step metadata/logs were exposed; root cause remains unknown.
- No live Duitku payment, supplier purchase, or deployment was performed. Keep production checkout and supplier ordering disabled until workflow execution is visible, all tests pass, and staging is verified. This change does not implement production Duitku mode.


### CI follow-up after sandbox-only change — 2026-10-14

- The new workflow runs [38497627559](https://github.com/sulistio95/PluQ-I/actions/runs/38497627559), [38497627399](https://github.com/sulistio95/PluQ-I/actions/runs/38497627399), and [38497627390](https://github.com/sulistio95/PluQ-I/actions/runs/38497627390) have failed before step summaries were exposed (`steps: null`). This remains the existing GitHub Actions execution blocker; it does not prove the new PHP assertions or MySQL callback integration failed.
- The sandbox-only integration is committed and wired into CI, but no test pass is claimed. Next action remains restoring observable runner execution, then reviewing the actual test output and fixing any confirmed source/test defects.


### GitHub Actions rerun diagnosis — 2026-10-14

- Manually re-ran failed jobs for workflow run [38497627559](https://github.com/sulistio95/PluQ-I/actions/runs/38497627559) (attempt 2). It failed again within about three seconds before any steps began.
- GitHub job metadata again reports `runner_id=0`, blank `runner_name`, and an empty `steps` list; log download returns `BlobNotFound`. This is strong evidence the failure occurs before a runner executes the repository workflow, but the exact account/repository-level cause is not exposed by the available API.
- No code change can responsibly fix an unobserved account-level runner allocation problem. Repository owner should inspect **Settings → Actions → General** and the account's Actions usage/billing/spending limit, and check whether hosted Actions runners are allowed for this private repository. Do not change workflow permissions or enable supplier processing as a workaround.
- The sandbox gate and callback safety changes remain committed; test pass is still unconfirmed until a runner executes the steps.


### Local gate-logic smoke test — 2026-10-14

- Ran a standalone PHP CLI smoke test against the exact sandbox gate conditions for five scenarios: default-off, staging sandbox allowed, production sandbox-only rejected, non-sandbox Duitku rejected in isolated mode, and normal supplier-enabled path preserved. All five assertions passed.
- Scope limitation: this executed a standalone copy of the gate logic because the private repository cannot be cloned into this runtime and GitHub Actions has not started a runner. It is **not** a substitute for `php tests/checkout_gate_test.php`, PHP lint of the committed tree, or the MySQL callback integration workflow.


### Latest test review — sandbox callback assertion — 2026-10-14

- Reviewed the sandbox-only callback integration script and found its queue assertion counted all `FULFILL_ORDER` jobs in the shared MySQL test database. Since earlier integration tests can create unrelated queue jobs, this could fail even when the sandbox order correctly created no supplier job.
- Fixed in [fb3623c](https://github.com/sulistio95/PluQ-I/commit/fb3623cfa920afec7e5dc76978b2455064f569b9): assert zero fulfillment jobs for the specific sandbox fixture's `order_id` in the JSON payload.
- Test has not yet run on MySQL. Recheck GitHub Actions runner allocation/log visibility, then execute the full fulfillment workflow and confirm the sandbox callback case. No live payment/provider call or deployment; keep production flags disabled.


### CI result after sandbox assertion correction — 2026-10-14

- Latest fulfillment run [38505439662](https://github.com/sulistio95/PluQ-I/actions/runs/38505439662) and PHP quality run [38505439765](https://github.com/sulistio95/PluQ-I/actions/runs/38505439765) failed with job `steps: null`; no actual test logs are available. PHP quality run [38505408114](https://github.com/sulistio95/PluQ-I/actions/runs/38505408114) has the same symptom.
- Do not treat this as a source test failure or success. Continue by resolving owner-side Actions runner/account availability, then inspect and run the actual test suite. The test-only assertion correction and notes are committed; live payments, supplier calls, and deployment remain disabled.



### Sandbox integration script quoting fix — 2026-10-14

- Source audit found shell quoting risk in `tests/duitku_sandbox_no_fulfillment_integration.sh`: a `php -r '…'` argument contained SQL single-quoted literals and JSON path `'$.order_id'`, which the shell could remove before PHP executed it.
- Fixed by using a single-quoted PHP heredoc, commit [acac0af](https://github.com/sulistio95/PluQ-I/commit/acac0afad5b2c735272e0150b9261817e82db4c6). This preserves SQL quoting as authored. The test still needs a real MySQL run; do not claim it passed until Actions steps/logs are available.
- Continue by checking new CI run results and testing the actual script. Production payments, supplier calls and deployment remain disabled.



### CI status after heredoc test fix — 2026-10-14

- Workflow runs for the heredoc correction: [PHP quality 38508784612](https://github.com/sulistio95/PluQ-I/actions/runs/38508784612), [fulfillment safety 38508784598](https://github.com/sulistio95/PluQ-I/actions/runs/38508784598), and [syntax checks 38508784571](https://github.com/sulistio95/PluQ-I/actions/runs/38508784571). Completed-failure jobs expose `steps: null`; no actual step output is accessible. The fulfillment PHP unit job was still queued at check time. Treat tests as unverified, not passed.
