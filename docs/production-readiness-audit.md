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
| Schema and migrations | Foundation schema and migrations 002–010 cover catalog/operations, checkout fields, manual-review states, settlements, admin Drive setting, login throttling, latest-refund lookup, and checkout request fingerprints. | The fulfillment CI integration setup applies migrations 002–010 in order over `database/schema.mysql.sql` (the 009–010 extension is in commit `ec6a6bc`; CI pending). Still apply on a clean target MySQL/MariaDB database, test upgrade from an existing database, and verify compatibility with the exact host version. |
| Catalog and pricing | Catalog chooses an available cheapest offer by normalized product name; quote recalculates price, checks reserve balance and exclusions, and validates dynamic account fields. | Run pricing boundary tests and DB-backed catalog sync tests using real supplier catalogs; validate every game's account field names. |
| Supplier game mapping | Admin page allows authorized admins to activate/deactivate mappings between local games and provider game codes; changes are audited. | Configure exact Digiflazz brand/game codes and VIPayment game names; compare synced product counts and samples before enabling any order. |
| Checkout and idempotency | Idempotency key is bound to a normalized request fingerprint; duplicate-key races re-read the winning order; the Duitku payment URL is persisted even if a fast callback arrives first. UUID input is now validated against the 8-4-4-4-12 hexadecimal shape; unit tests cover valid UUIDs, case/whitespace normalization and malformed keys. These tests passed PHP quality run `38215219816` and syntax run `38215219946`; fulfillment run `38215219929` also passed. Source review confirmed the checkout key validator enforces the 8-4-4-4-12 hexadecimal shape. This audit found the existing test file did not actually assert valid/malformed UUID cases; commit `6fa1d80` adds valid lowercase, uppercase/whitespace-normalized, and malformed-key cases. CI for the UUID cases passed in runs `38237778503` and `38237778648`. Review of concurrent checkout handling found that MySQL deadlocks/serialization conflicts fail closed but did not re-read the winning checkout. Commit `103cf16` now classifies duplicate-key, deadlock, and lock-wait conflicts for a post-rollback lookup, reusing only an existing row with matching fingerprint and saved payment URL; it never re-calls Duitku on that path. Commit `59c893b` adds unit coverage for duplicate-key/deadlock SQLSTATEs and unrelated errors. The first quality run (`38238953683`) caught a test-fixture error because `PDOException` requires an integer code; commit `c2568d9` fixes the test fixture. Corrected commit `c2568d98b0` passed PHP quality (`38239039733`), syntax (`38239039554`), and fulfillment (`38239039583`). An older documentation commit (`5920d6f0a5`) still showed the pre-fix fixture and failed PHP quality; later corrected commits passed all workflows. Commit `623d0c6` adds a bounded wait (up to 22 seconds) for the winner's payment URL when a concurrent request finds a committed order before the mocked/real gateway response has been persisted. A MySQL integration test using a mocked Duitku creator has now been added in `tests/checkout-idempotency-concurrency-integration.php` and wired into the fulfillment workflow (commits `e64061e` and `d6f848f`); it tests concurrent same-key calls, exactly one order/payment/gateway call, URL reuse, and changed-payload rejection. The first integration run failed due to an inherited function-static PDO socket; commit `67fa47d` fixes fixture setup by closing a direct, non-static PDO before forking. The corrected integration passed in fulfillment run `38240603363`, with both MySQL integration and PHP tests successful. PHP quality (`38240603254`) and syntax (`38240603237`) also passed for commit `67fa47d`. A further audit found that SQLSTATE `23000` includes foreign-key violations, not just duplicate idempotency keys. Commit `bdf606b` narrows recovery to SQLSTATE `40001` or MySQL driver codes `1062`, `1205`, and `1213`; commit `24c0a59` adds tests for duplicate-key, deadlock, lock timeout, and foreign-key exclusion. The classifier unit-test commit `24c0a59` passed PHP quality (`38242799852`) and syntax (`38242799858`); fulfillment was still running at the last check. Added `tests/checkout-deadlock-classification-integration.php` and wired it into the MySQL workflow (commits `796d205` and `d0ef765`). It creates a real MySQL row-lock cycle and asserts one deadlock victim (driver code 1213) is classified for winner recovery while the other transaction commits. Fulfillment run `38242948715` passed both `mysql-callback-integration` and `php-tests`, including this real deadlock test; PHP quality, syntax, and fulfillment workflows also passed on follow-up documentation commit `e33a8140be` (runs `38242987438`, `38242987397`, `38242987424`). This verifies actual engine error classification, but does not yet simulate full `checkout_create_payment()` recovery with a deadlock victim. The fulfillment MySQL workflow applies migrations 009–010 after 008 (commit `ec6a6bc`); all three workflows passed for follow-up documentation (runs `38237885006`, `38237884979`, `38237885082`). The stale supplier freshness integration change (`5a168b8`) passed PHP quality `38236928788`, syntax `38236928777`, and fulfillment `38236928790`; documentation follow-ups also passed all workflows (latest runs `38236974932`, `38236974892`, `38236974895`). | Run concurrent same-key requests and same-key/different-payload requests against MySQL; verify one order/payment is created and a mismatched payload never reuses a payment URL. |
| Duitku callback | HMAC-SHA256 verification, order/amount matching, row locking, and first-transition queue insertion are present. Result code `01` maps to failed, matching the server-to-server callback documentation; amount parsing rejects invalid/overflowing integers. | Execute valid/invalid signature, wrong amount, duplicate callback, pending-then-paid, and conflicting-state cases against a test database. |
| Queue and worker | MySQL-backed bounded worker, lock tokens, retry cap, read-only health diagnostic, and conservative stale-lock manual review are present. `fulfillment_process()` now treats a fresh supplier `PROCESSING` row (updated within 10 minutes) as an active request and returns `IGNORED` for a duplicate queue job rather than prematurely forcing the order into manual review; older ambiguous processing still fails closed. Commit `43ba2b7`. | Added `tests/duplicate-queue-processing-integration.php` and wired it into `.github/workflows/fulfillment-tests.yml` to verify a fresh `PROCESSING` row is preserved when a duplicate job runs. The PHP unit-test job passed in run `38201807243`; the MySQL integration job ran the new duplicate-queue test successfully, but the existing ambiguous-supplier test failed because its fixture created a fresh `PROCESSING` record, which the new active-request guard correctly treats as in progress. The fixture was corrected in commit `92a0ddb` to age that record by 11 minutes. The integration rerun passed after fixing the bootstrap path: workflow run `38202791455` completed successfully, including duplicate queue processing, concurrent duplicate callbacks, stale queue recovery, ambiguous supplier outcome, explicit supplier rejection/refund state, and read-only checkout review. A further audit found a crash window: if supplier status had already been persisted as `FAILED` but the process stopped before creating the refund row, a retry returned `FAILED` without creating the refund. `app/services/fulfillment.php` now calls the idempotent explicit-failure recorder when it encounters an existing supplier `FAILED` state (commit `e221f01`); the integration test now simulates that crash window (commit `7908e11`). The crash-window recovery test passed in fulfillment workflow run `38203732917` (both `php-tests` and `mysql-callback-integration` successful, including explicit supplier rejection/refund). A subsequent race review found that an older worker's delayed supplier HTTP response could overwrite `REVIEW` after stale-lock recovery. Commit `30c0735` conditionally persists supplier responses only while the supplier transaction remains `PROCESSING`/`RETRYING`, and only updates order states from allowed in-flight states; PHP quality, syntax, and fulfillment workflows passed (runs `38204088585`, `38204088548`, `38204088535`). A further change in commits `3cf9eaf` and `b039424` wraps normal and uncertain supplier-response persistence in a transaction and locks the order before the supplier row, matching stale-lock recovery's lock order; all three workflows passed for the resulting latest documentation commit (runs `38204439650`, `38204439603`, `38204439553`). A subsequent audit found worker finalization could update order/supplier state before checking whether its queue lease had been revoked. Commit `957d310` introduced transactional finalization after locking the queue row and verifying the same `PROCESSING` status and `lock_token`; it uses queue → order → supplier lock order and reports `LOCK_LOST` without applying the stale outcome. The logic was extracted into `app/services/queue_worker.php` (`5240068`), and `tests/worker-lease-revocation-integration.php` (`3c99f14`) plus a MySQL CI step (`5595378`) now simulate recovery revoking the lease and assert queue/order/supplier review states remain unchanged when the old worker returns. The MySQL integration and PHP test jobs passed in fulfillment run `38209568044`, including the revoked-lease regression step. Expanded preflight schema coverage (`33a32ee`) passed all three workflows: quality `38209632272`, syntax `38209632254`, fulfillment `38209632265`. The subsequent documentation commits `5985ec58`, `10e24cad`, `d143dd7f`, and `e3f24a21` also passed all three workflows (latest: `38209670216`, `38209670195`, `38209670175`). Added `tests/queue-worker-concurrency-integration.php`, wired into the MySQL integration workflow before callback tests. It uses two forked PHP processes with independent PDO connections, holds the first claim lock briefly, and asserts each process claims a distinct ready job with its own lease token. The initial failure was caused by an inherited parent PDO socket across `pcntl_fork()`; commit `94f806c` closes the connection before forking, and `5632ac5657` releases the parent statement before the fork. The latest fulfillment workflow `38213945487` passed, including `Test concurrent queue claims against MySQL`; PHP quality `38213945851` and syntax `38213945489` also passed. Commit `5a168b8` extends `tests/duplicate-queue-processing-integration.php` to verify both sides of the freshness rule: fresh `PROCESSING` returns `IGNORED`; an 11-minute-old ambiguous supplier request becomes order `MANUAL_REVIEW` and supplier `REVIEW`, without another provider call. All three workflows passed for this change (quality `38236928788`, syntax `38236928777`, fulfillment `38236928790`), and all three passed again after documentation updates (quality `38236974932`, syntax `38236974892`, fulfillment `38236974895`). This does not yet test the exact 10-minute boundary against real HTTP timeout duration and cron overlap. Do not auto-resubmit stale supplier requests. |
| Supplier fulfillment | Supplier adapters use HTTPS, timeouts, no redirects, and conservative manual review for ambiguous outcomes. Definitive rejection preserves payment=PAID and creates a single pending refund record under the order lock. Added isolated tests for account mapping and provider response normalization. | Run the new mapping test in CI, then verify provider API payload/signature/status semantics with sandbox credentials and known test accounts. Never treat an HTTP timeout as a definitive rejection. |
| Refunds | Refund records and idempotent creation for supplier failure exist; admin can filter latest refund status. | **No automatic customer refund is certified by this audit.** Confirm gateway refund capability and implement/verify an authorized refund action or complete refunds manually through the approved gateway process. Reconcile the gateway reference and refund status before closing an order. |
| Admin security | Session regeneration after login, CSRF checks, password hash verification, DB-backed throttling, output escaping, and audit logs are present in inspected paths. Regression tests now cover valid CSRF tokens and reject missing, malformed, mismatched, or absent-session tokens; the new test is included in the existing `tests/admin-authorization.php` workflow step. A shared `admin_can_manage_settings()` role policy now guards both Drive-folder changes and game-provider mapping changes; regression tests cover `SUPER_ADMIN`, `ADMIN`, `OPERATOR`, missing/empty roles, and case sensitivity. Related commits: `76fc5ad`, `a9b8c4c`, `da72aa0`, `b13b7ad`, `6134f52`, `a6a15d6`. | Verify every admin route and mutation in a configured runtime; test secure cookie behavior behind the actual reverse proxy, CSRF rejection, login throttling and permissions for each role. Expanded CSRF regression cases passed quality, syntax, and fulfillment workflows in runs `38210544538`, `38210544554`, and `38210544547`. |
| Google Drive settlement | Export and folder configuration scaffolding exist and are disabled by default. | Configure OAuth on the server and test create/upload, duplicate prevention, interrupted upload recovery, permissions, and independent database restore. |
| CI and tests | GitHub Actions lints PHP files and runs callback-state, checkout-fingerprint, supplier-mapping, and admin-authorization tests. Run `38201655600` showed lint and all four test steps successful; overall workflow was still `in_progress` at the last check due to the final post-job step. | Recheck the final workflow conclusion. The separate `fulfillment-tests.yml` workflow includes MySQL 8.4 integration cases for duplicate callbacks, queue recovery, ambiguous supplier outcomes, explicit rejection/refund state, and duplicate queue processing. Run `38202791455` completed successfully with both the PHP tests and all MySQL integration steps passing after the bootstrap path fix. The follow-up code/test change (`e221f01`, `7908e11`) adds recovery for a crash after supplier failure is persisted but before the refund record is created; both jobs passed in run `38203732917`. Commit `30c0735` adds conditional writes to stop a late supplier response from overriding stale-lock recovery; all three workflows passed. Later atomic supplier-response persistence, worker lease revocation, preflight schema coverage, and CSRF regression changes also passed their documented workflows. Latest docs commits `7a227ed9` and `f632ce18` passed quality, syntax, and fulfillment runs (`38210555764`, `38210555798`, `38210555794`; `38210566728`, `38210566658`, `38210566561`). Live provider calls are not run by these workflows. |
| Deployment and production | Preflight and queue-health CLI diagnostics exist. | **Blocked for production** until all staging tests, restore rehearsal, API reconciliation, cron monitoring, and incident/manual-review procedures pass. |

## Preflight schema coverage

`bin/preflight.php` now checks the recorded foundation migration `001_shared_hosting_foundation` and additional core tables (`audit_logs`, `provider_products`, `product_variants`, `settings`, `banners`, `webhook_events`, `account_validations`) in addition to the previously checked tables. If `SETTLEMENT_BACKUP_ENABLED=true`, it also checks `monthly_settlement_exports` and `app_settings` alongside migrations 006–007. This improves missing-schema detection; all three CI workflows passed for the code change (quality `38209632272`, syntax `38209632254`, fulfillment `38209632265`). It does not replace running preflight against the target database.

## Callback result-state rule

- `00`: payment reported successful; the callback may transition a pending payment and enqueue fulfillment once.
- `01`: payment failed; mark the payment/order failed only if the stored transaction is still pending.
- Any other code: reject the callback as unsupported. The separate customer redirect result codes are not used to update database payment state.

Duplicate callbacks for a transaction already in a non-pending state are acknowledged without repeating the payment/order transition.

## Staging exit checklist

- [ ] Apply all migrations 001–010 in order to a clean test database.
- [x] PHP quality checks and PHP/JavaScript syntax checks succeeded for commit `455499a` (runs `38202791491`, `38202791510`).
- [x] Fulfillment safety tests passed all PHP and MySQL integration steps for commit `455499a` (run `38202791455`).
- [x] Verify crash-window refund recovery (`e221f01`) and its integration fixture (`7908e11`): run `38203732917` passed both PHP and MySQL integration jobs.
- [x] Verify CI for the late supplier-response/stale-lock guard (`30c0735`): PHP quality, syntax, and fulfillment workflows all passed.
- [x] Verify CI for atomic supplier response persistence and consistent lock ordering (`3cf9eaf`, `b039424`): quality, syntax, and fulfillment workflows passed (runs `38204439650`, `38204439603`, `38204439553`).
- [x] Verify worker lease finalization guard and revoked-lease regression test (`957d310`, `5240068`, `3c99f14`, `5595378`): PHP tests and MySQL integration including the revoked-lease step passed in run `38209568044`.
- [x] Verify expanded preflight schema coverage (`33a32ee`): quality, syntax, and fulfillment workflows all passed (`38209632272`, `38209632254`, `38209632265`).
- [x] Verify expanded admin CSRF regression test in CI (`b371a0cb`; runs `38210544538`, `38210544554`, `38210544547`).
- [x] Add a MySQL-backed concurrent queue-claim regression test to CI; latest fulfillment run `38213945487` passed the concurrency test, alongside the PHP quality and syntax workflows (`38213945851`, `38213945489`). Commit `5a168b8` extends the duplicate queue test to assert that an 11-minute-old ambiguous supplier request enters manual review without resubmission; all three workflows passed (`38236928788`, `38236928777`, `38236928790`). Exact-boundary behavior against real HTTP timeout duration and cron overlap still needs staging verification.
- [ ] Test callback signature failures, wrong order/amount, duplicate callback, and pending-to-paid transition.
- [x] Verify `tests/checkout-idempotency-concurrency-integration.php` in MySQL CI (run `38240603363`): concurrent same-key requests create one order/payment, invoke the mocked gateway once, reuse the persisted URL, and reject a changed account payload.
- [x] Verify `tests/checkout-deadlock-classification-integration.php` against MySQL 8.4 (fulfillment run `38242948715`): it induces a real deadlock and asserts exactly one 1213 victim is classified for winner recovery while the other transaction commits. Unit coverage excludes SQLSTATE `23000` foreign-key violations (`24c0a59`). This does not yet prove full `checkout_create_payment()` recovery after that exact deadlock; add a controlled full-path test before production.
- [ ] Reuse one idempotency key with changed product/account data and verify the existing payment URL is not returned.
- [x] Verify the two-worker queue-claim integration test in CI (`38213945487`); test full worker execution and the exact 10-minute freshness boundary under controlled concurrent runs remains pending.
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


## Admin session cookie proxy trust review (2026-10-12)

The admin session helper no longer trusts `X-Forwarded-Proto: https` unless the deployment explicitly sets `ADMIN_TRUST_PROXY_HTTPS=true`. This reduces reliance on a client-spoofable forwarded header. Direct HTTPS detection through the server's `HTTPS` variable remains supported. The environment example defaults the option to false; enable it only when the reverse proxy is trusted and configured to overwrite/strip incoming forwarded-proto headers. Regression coverage was added to `tests/admin-authorization.php`. PHP quality (`38244155564`) and syntax (`38244155580`) passed for commit `022fe0052b`; fulfillment (`38244155610`) was pending at the last check. Verify the final CI conclusion and confirm cookie `Secure` behavior on the actual hosting/proxy before production.

The real MySQL deadlock classification integration passed in fulfillment run `38242948715`; it validates MySQL error classification, not the full `checkout_create_payment()` recovery path. A deterministic full-path regression test remains outstanding.


## Duitku failed-callback state consistency (2026-10-12)

- Audit found the definitive `resultCode=01` callback marked `payment_transactions.status=FAILED` and `orders.payment_status=FAILED` but left a still-pending `orders.order_status=PENDING` behind. Commit `e9ffef5` now also transitions order status from `PENDING` to `FAILED`, without overwriting later/non-pending order states.
- Added a second disposable pending order fixture to `.github/workflows/fulfillment-tests.yml` and extended `tests/duitku_callback_integration.sh` (commit `dabd808`) to send a valid signed `01` callback and assert payment/order both become `FAILED` and no fulfillment job is added. This is test-only fixture data; no live API calls.
- Verification complete: after correcting shell variable expansion in commit `649d3e2cd1`, fulfillment run `38246227164` passed both `mysql-callback-integration` and `php-tests`. The follow-up documentation commit `7e7723095e` passed PHP quality (`38246259533`), syntax (`38246259565`), and fulfillment (`38246259539`). The MySQL test confirms the signed `resultCode=01` callback sets payment and still-pending order to `FAILED`, adds no fulfillment job, and duplicate successful callbacks enqueue exactly once. No live gateway/supplier calls were made. Keep live payment/supplier flags disabled.


Integration-test fixture correction: commit `649d3e2` fixes shell variable expansion in the failed-callback test (`tests/duitku_callback_integration.sh`). The latest CI triggered by this correction is pending; verify the MySQL integration job before considering the regression covered.


## Verification update — 2026-10-12

The failed Duitku callback state-consistency regression is now verified. Commit `649d3e2cd1` corrected variable expansion in the shell integration fixture. Fulfillment run `38246227164` passed both MySQL callback integration and PHP tests. Follow-up documentation commit `7e7723095e` passed all three workflows: quality `38246259533`, syntax `38246259565`, and fulfillment `38246259539`. Full `checkout_create_payment()` recovery after an induced deadlock remains a separate test gap; do not mistake the existing deadlock-classification test for full-path proof. Production remains blocked pending staging checks, and all live transaction flags stay disabled.


## Additional Duitku callback rejection coverage (2026-10-12)

Commit `fc7b8788890d6de2` extends the MySQL callback integration script with two negative cases: invalid HMAC signature and validly signed callback with an amount that does not match the payment row. Each must return HTTP 400 and preserve pending payment/order state without enqueuing fulfillment. The cases are verified in CI for commit `fc7b8788890d6de2c62f231b9016d67a39c30765`: PHP quality run `38251844581`, syntax run `38251844521`, and fulfillment run `38251844517` all passed. The fulfillment run's MySQL integration job completed successfully. No live API calls were made.


Verification update: callback rejection coverage from commit `fc7b8788890d6de2c62f231b9016d67a39c30765` passed all three CI workflows (quality `38251844581`, syntax `38251844521`, fulfillment `38251844517`). The MySQL integration step confirms invalid HMAC and mismatched amount return HTTP 400 while leaving the payment/order pending and adding no fulfillment job; the duplicate-success and failed-payment callback assertions also pass. Full-path deadlock recovery in `checkout_create_payment()` remains an open production-readiness test gap.


## Checkout concurrency regression strengthened (2026-10-13)

Commits `93a13db9ac` and `f7508904ed` modify `tests/checkout-idempotency-concurrency-integration.php` to start both same-key checkout workers without the previous 300 ms stagger, using a shared filesystem gate to release them together. Assertions remain: exactly one order, one payment, one mocked Duitku request, both callers receive the same order/payment URL, and mismatched payload reuse is rejected. CI is pending; verify the MySQL integration job before considering this test change green. This stress test increases race coverage but does not alone prove that error 1213 was triggered within `checkout_create_payment()`; full-path deadlock recovery remains unproven until the deadlock is observed deterministically. No live gateway/supplier calls; live transaction flags remain disabled.


## MySQL checkout race stress run (2026-10-13)

- CI now executes `tests/checkout-idempotency-concurrency-integration.php` three times with independent random fixtures on each run, using the synchronized start gate and mocked Duitku only (workflow commit `bb8549257b9b35bf5214152c50905192e6ab05a4`). This raises the chance of catching intermittent concurrency regressions while asserting one order/payment, one gateway mock call, the same persisted payment URL for both callers, and rejection of changed payload reuse.
- This is stress coverage, not deterministic proof that `checkout_create_payment()` itself receives MySQL error 1213 and completes winner recovery. The separate deadlock test proves real MySQL classification only. Keep the full-path deadlock-recovery gap open until a deterministic integration test observes that exact sequence. Await CI for the new workflow change; do not claim it passed yet. No live gateway or supplier API calls were made.


CI finding for the three-iteration stress test: the first run passed twice and then exposed an order-dependent assertion: the test assumed child process 1 always wins, which is not guaranteed by OS scheduling. Commit `d518075b5d8b3b8bfa5c4b8f284eb4d072a24813` changes assertions to require exactly one creator and one reuser regardless of which child wins. This is a test-harness correction, not evidence of a checkout application failure. Re-run CI before marking the stress test green. Full-path deadlock recovery remains unproven.


## MySQL checkout race verification update (2026-10-13)

- The scheduling-independent checkout race test passed in all three workflows for commit `4e33bf5dcd08`: PHP quality `38283808180`, syntax `38283808125`, and fulfillment safety `38283808131`. The fulfillment run's MySQL integration job passed, including all three checkout iterations and the existing real-deadlock classification integration.
- Follow-up commit `570d6daf401375feb4fd39f822d63d8e0d7a6284` strengthens the checkout stress step: it snapshots MySQL's global `Innodb_deadlocks` counter, runs the three concurrent checkout iterations (mock Duitku only), then requires the counter to increase. Because the checkout integration assertions also require both calls to succeed, exactly one gateway call, one order/payment, and the same persisted URL, a passing run will provide direct evidence that at least one real InnoDB deadlock occurred during the checkout stress window and the checkout flow recovered. The separate classifier test still verifies the 1213 classification independently.
- This new assertion is not yet verified; wait for CI. If no deadlock occurs in the three runs, the step intentionally fails rather than falsely claiming full-path recovery coverage. No live API calls or deployment; production flags remain disabled.


## MySQL checkout race verification update (2026-10-13)

- The scheduling-independent checkout race test passed in all three workflows for commit `4e33bf5dcd08`: PHP quality `38283808180`, syntax `38283808125`, and fulfillment safety `38283808131`. The fulfillment run's MySQL integration job passed, including all three checkout iterations and the existing real-deadlock classification integration.
- Follow-up commit `570d6daf401375feb4fd39f822d63d8e0d7a6284` strengthens the checkout stress step: it snapshots MySQL's global `Innodb_deadlocks` counter, runs the three concurrent checkout iterations (mock Duitku only), then requires the counter to increase. Because the checkout integration assertions also require both calls to succeed, exactly one gateway call, one order/payment, and the same persisted URL, a passing run will provide direct evidence that at least one real InnoDB deadlock occurred during the checkout stress window and the checkout flow recovered. The separate classifier test still verifies the 1213 classification independently.
- This new assertion is not yet verified; wait for CI. If no deadlock occurs in the three runs, the step intentionally fails rather than falsely claiming full-path recovery coverage. No live API calls or deployment; production flags remain disabled.


### Checkout deadlock stress result (2026-10-13)

The checkout stress CI for commit `570d6daf401375feb4fd39f822d63d8e0d7a6284` passed all three concurrent idempotency iterations and the PHP test job, but MySQL's global `Innodb_deadlocks` counter stayed at 0 (before=0, after=0). This means the stress did not observe a real deadlock during `checkout_create_payment()`, so full-path deadlock recovery is still **not verified**. Commit `7113cdae9c22007fcf5b90db523b03354aef2960` changes the workflow to report the absence of a deadlock rather than failing otherwise-passing stress coverage. The independent deadlock integration test only verifies error classification and must not be counted as proof of the complete checkout recovery path. Next: attempt deterministic deadlock choreography in the checkout integration or leave this gap clearly documented. No live gateway/supplier calls; production flags remain disabled.


### CI after making deadlock observation non-fatal (2026-10-13)

Commit `7113cdae9c22007fcf5b90db523b03354aef2960` passed PHP quality (`38315071804`), PHP/JS syntax (`38315071913`), and fulfillment safety (`38315071794`). Both the PHP tests and MySQL integration job passed, including the three concurrent checkout idempotency runs. The stress window did not observe a real deadlock, so the workflow now reports that limitation without falsely failing the unrelated stress checks. Follow-up documentation commit `3fe14f4ca3a04f5a686b61fd5efaab6708c9e34a` also passed syntax (`38315106885`), quality (`38315106999`), and fulfillment (`38315106585`). Full-path recovery from a real deadlock inside `checkout_create_payment()` remains an open verification gap; the independent deadlock-classification test does not close it. No live API calls or deployment; production flags remain disabled.


### Deterministic checkout deadlock test added — awaiting CI (2026-10-13)

Commit `58ee5b048800c490538ad04cb754b58befc680a0` strengthens `tests/checkout-idempotency-concurrency-integration.php` with a parent transaction that locks the test product row before releasing both checkout workers. It waits until `performance_schema.data_lock_waits` shows both workers blocked by that lock, then releases it to encourage the absent-key gap-lock/insert-intention deadlock inside `checkout_create_payment()`. The test requires both calls to complete successfully, one order/payment, one mock Duitku call, shared winner URL, and an increased `Innodb_deadlocks` counter. This is a test strategy, not yet evidence of success; wait for the MySQL integration job. If it fails to provoke a deadlock, inspect logs and adjust. No live gateway/supplier calls or deployment; production flags remain disabled.


## Checkout deadlock choreography result (2026-10-13)

The deterministic test attempt in commit `58ee5b048800c490538ad04cb754b58befc680a0` failed its intended deadlock assertion: MySQL's global `Innodb_deadlocks` counter remained `0 -> 0`. The test did observe both checkout workers waiting on the parent-held product row, but releasing it did not trigger a deadlock. This is a failed test strategy, not proof that the checkout implementation is defective; equally, it is not proof of full-path recovery. Commit `065a10ca522f9d24ed213dd7c9ac583a7e49d4ac` reverts that test file to the previously passing synchronized checkout idempotency stress test. CI for the revert is pending. The independent deadlock integration still verifies only error classification and remains insufficient to certify complete `checkout_create_payment()` recovery after error 1213. Keep production checkout/supplier flags disabled until this gap is either verified with a deterministic test or explicitly accepted as an outstanding risk. No live APIs or deployment.


### Verification after reverting deadlock choreography (2026-10-13)

The restored checkout idempotency stress test in commit `065a10ca522f9d24ed213dd7c9ac583a7e49d4ac` passed PHP quality (`38319787900`), syntax (`38319788215`), and fulfillment safety (`38319788002`). Both PHP tests and the MySQL integration job passed. This confirms the prior same-key concurrency/idempotency assertions, not full-path recovery after a real deadlock inside `checkout_create_payment()`; keep that item open. The docs-only follow-up commit `ef5e72894d4ffa7bcc51ca2c577a356102f4511e` passed quality (`38319827522`) and syntax (`38319827634`); fulfillment run `38319827341` was still in progress at last check. No live payment/supplier API calls or deployment.


## Deterministic checkout deadlock integration attempt (2026-10-13)

Commits `69b4cd0e3105d5fa061c34e16e42d08f36a0b01c` and `d9ad0feb8d0fa98a18a4f0f64bf967d038bbf524` introduce a test-only callback seam guarded by `APP_ENV=test` and use it to make two same-key checkout transactions lock the seeded DIGI and VIPAY rows in opposite order. Both workers synchronize after taking their first row lock, then request the second row inside `checkout_create_payment()`; the MySQL test asserts a real increase in `Innodb_deadlocks`, successful recovery for both requests, one order/payment, one mock Duitku call, URL reuse, and changed-payload rejection. CI is pending. This is only a test strategy until the MySQL job passes and logs confirm the intended deadlock counter increase. No live API calls/deployment; production payment and supplier flags remain disabled.


### Deadlock test harness fix (2026-10-13)

The first MySQL CI attempt for `d9ad0feb8d0fa98a18a4f0f64bf967d038bbf524` failed at the test's post-run counter query because the parent PDO was intentionally unset before forking. The deadlock counter was never checked, so this run provides no evidence for or against full-path recovery. Commit `5b9452144202d8e97aa5c5d8259db5e36643ad04` reconnects before querying the counter; CI is pending. The deterministic deadlock and recovery assertions remain unverified until the corrected MySQL job passes.


### Direct 1213 evidence for checkout deadlock recovery (2026-10-13)

The MySQL attempt for commit `5b9452144202d8e97aa5c5d8259db5e36643ad04` reached the deadlock-counter assertion but showed `before=0 after=0`; this counter query did not provide usable proof of a real deadlock. Commit `80eeec3f6ef66798b684a4eb66424719e51cc4c1` changes the test to capture the actual exception at each worker's second opposing provider-row lock and require exactly one driver code 1213 classified for idempotency recovery. It also continues to require both callers to succeed, one order/payment, one mock Duitku call, shared URL and mismatched-payload rejection. Workflow commit `eb30fe8539b6a7cdb9d8f5417ddcfd3dde744c70` removes the global-counter inference and runs the integration three times. This is pending CI; full-path recovery remains unverified until the MySQL job passes.


## Full-path checkout deadlock recovery verified (2026-10-13)

Commit `80eeec3f6ef66798b684a4eb66424719e51cc4c1` passed PHP quality (`38321635015`), syntax (`38321635052`), and fulfillment safety (`38321635067`). The MySQL integration job passed all three concurrent checkout iterations. The test records the actual exception from the opposing provider-row lock attempts inside `checkout_create_payment()` and asserts exactly one driver code 1213 victim is classified by `checkout_is_idempotency_race()`; both checkout calls then have to succeed with exactly one order/payment, one mock gateway call, the same persisted URL, and changed-payload rejection. This verifies the full-path recovery branch in the MySQL integration environment.

The older global `Innodb_deadlocks` status read stayed `0 -> 0` and was not a reliable indicator here; workflow commit `eb30fe8539b6a7cdb9d8f5417ddcfd3dde744c70` removes that inference and relies on direct error evidence. Its combined MySQL job was still in progress at the last poll. Commit `c58719a03166cedf4537df373b18c2501282e1d9` makes the successful assertion explicit in test output and is awaiting CI. This remains mock-only integration evidence, not live gateway/provider certification. Production payment/supplier flags stay disabled pending staging exit checks.


## Checkout gateway timeout retry safety — verified in mock MySQL integration (2026-10-13)

Commit `808f17e33d0baa2d3c19a60b9deea8586b9ba2d4` makes same-key checkout retries fail promptly for admin review when the existing order has `last_error` set but no saved gateway URL. This avoids the previous 22-second polling delay and, more importantly, prevents a second external payment-create request after an ambiguous timeout. It deliberately does not assume the gateway failed to create a payment.

Commit `a94f8449b333466a33aea585f558a145fd49eb4b` adds an integration scenario using a mock gateway that throws after the database transaction commits. Assertions cover a single mock call, immediate retry rejection, one order/payment, no fabricated URL, and a persisted error marker. All workflows passed: PHP quality `38326198802`, PHP/JavaScript syntax `38326198808`, and fulfillment safety `38326198783`; the fulfillment workflow's MySQL integration and PHP test jobs both completed successfully.

**Remaining operational gap:** the error marker intentionally routes an ambiguous payment attempt to manual review. Before production, admin procedures/tooling must reconcile the merchant order ID with Duitku and determine whether a payment exists before any replacement attempt. Do not blindly retry, mark the payment failed, refund, or create a second payment based only on a client timeout. No live API calls or deployment were performed; production checkout and supplier flags remain disabled.


## Admin checkout reconciliation hints — verified (2026-10-13)

Commit `2e6efab01a524af252bc27c78bed61ab59fe6cd5` adds a read-only “Catatan checkout” column to the admin transaction list. It displays whether a Duitku payment URL is saved, the recorded expiry timestamp, and the escaped `orders.last_error` text marked as needing review. The actual payment URL is not exposed and no state-changing reconciliation controls were added.

CI for the code commit passed: PHP quality `38329325146`, PHP/JS syntax `38329325150`, and fulfillment safety `38329325168`; both jobs in the fulfillment workflow (`php-tests` and `mysql-callback-integration`) completed successfully. The documentation follow-ups also passed both fulfillment jobs: handoff run `38329275031` and audit run `38329284829`.

**Remaining gap:** this UI is evidence display, not reconciliation. A missing URL and a timeout marker do not establish whether Duitku created a payment. Before production, document a controlled procedure to query/confirm gateway status using the merchant order ID, record the operator and evidence, and only then decide whether a new payment attempt is safe. Do not automatically retry, mark failed, or refund an ambiguous payment. Verify current official Duitku API documentation before implementing status queries; no live API calls or deployment were performed. Production checkout and supplier flags remain disabled.


## Duitku manual reconciliation research (2026-10-13)

The official [Duitku API reference](https://docs.duitku.com/api/id/) documents the merchant transaction status endpoint at `/webapi/api/merchant/transactionStatus`. Development uses `https://sandbox.duitku.com/webapi/api/merchant/transactionStatus`; production uses `https://passport.duitku.com/webapi/api/merchant/transactionStatus`. The current “Cek Transaksi” section specifies POST fields `merchantCode`, `merchantOrderId`, and an HMAC-SHA256 signature over `merchantCode + merchantOrderId` using the API key. Some older Duitku pages show MD5; implement only against the current API reference and verify with sandbox before production.

**Critical status-code distinction:** current status-query response codes mean `00=Success`, `01=Process`, `02=Failed/Expired`. The existing callback mapping where `01=FAILED` is for callback payloads, not status-query responses. Do not share the callback mapper with a status-query parser. Duitku also warns that repeated automatic status lookups can trigger rate limiting and block requests for roughly an hour; do not build a cron poller.

Proposed safe operator flow for an ambiguous checkout timeout:
1. Use the exact local `merchant_order_id`; never use the customer redirect query as authoritative status.
2. Query status only through a deliberate operator action, using server-side credentials and no credential exposure in browser output or logs.
3. Require exact response `merchantOrderId` match and numeric amount equality with local `payment_transactions.amount`; validate response shape and allowed status code.
4. Record the operator, timestamp, query outcome, reference, status and mismatch/error evidence in an audit log with secrets redacted.
5. `01=Process`, network errors, invalid/malformed responses, unknown status, or any ID/amount mismatch stay in manual review; no replacement payment and no order-state mutation.
6. `00=Success` should be reconciled through the same verified, idempotent payment transition/fulfillment-enqueue logic as a trusted callback—not by directly editing the order row.
7. `02=Failed/Expired` can only inform a controlled resolution after matching response evidence and rechecking current DB state inside a transaction; never override a later PAID/QUEUED/PROCESSING/SUCCESS state. Replacement attempts must be separately audited and only allowed after proving no payable transaction remains active.

Implementation has not yet started. Next code change should add a small status-query service with strict response validation and unit tests, then a CSRF-protected admin action with role enforcement, rate-conscious manual invocation, audit logging, and no direct status mutation. Add mock-only integration tests for duplicate callbacks/races. No live Duitku calls or deployment occurred; checkout and supplier flags remain disabled.


### Duitku transaction-status validation helpers — verified (2026-10-13)

The code now contains three pure helpers in `app/services/duitku.php`: `duitku_transaction_status_signature()` (current HMAC-SHA256 formula), `duitku_transaction_status_state()` (separate status-query mapping), and `duitku_validate_transaction_status_response()` (exact order ID/amount matching, allowed-code validation, and response-shape checks). They do not perform network requests or mutate payment/order state. Unit tests in `tests/duitku-transaction-status.php` cover valid signature/mappings and reject unknown codes, order/amount mismatches, non-integer amounts, invalid references, and missing API keys.

CI passed on commit `8e6e46497107`: PHP quality `38331891216`, PHP/JS syntax `38331891186`, and fulfillment safety `38331891209`; both fulfillment jobs passed, including MySQL integration. No live gateway calls were made.

**Next:** before adding a transport method, reconcile the current Duitku docs' content-type inconsistency: the “Cek Transaksi” section labels the request x-www-form-urlencoded while its examples show a JSON body. Then implement a single-request, server-side status lookup, sandbox-only verification, CSRF-protected privileged admin action, and durable audit record. Do not implement automatic polling. A validated response alone does not authorize direct state mutation; paid status must use the existing idempotent callback transition path. Production flags remain disabled.


## Admin-triggered Duitku status reconciliation — verified (2026-10-13)

Added `public/admin/reconcile.php` and a privileged-admin link from `public/admin/index.php`. The action requires a valid admin session, a CSRF token, and the existing `ADMIN`/`SUPER_ADMIN` role gate. It performs one operator-triggered lookup for a locally stored Duitku merchant order ID, refuses non-integer rupiah amounts, and throttles repeated lookup attempts for the same payment transaction to at least 60 seconds using audit-log history.

The status lookup is sandbox-only. `duitku_status_config()` requires merchant code/API key and a sandbox `DUITKU_ENV`; `duitku_query_transaction_status()` is pinned to the exact sandbox transaction-status URL. A test-only transport seam is permitted only when `APP_ENV=test`. The route validates exact response order ID and amount, records a `DUITKU_STATUS_LOOKUP` audit event with normalized status/reference or a sanitized error, and **never changes payment/order status, enqueues fulfillment, or creates a replacement payment**.

CI passed on test commit `05d9f1ee497b`: PHP quality `38334462332`, syntax `38334462340`, and fulfillment `38334462306`; the fulfillment run passed both PHP and MySQL jobs. The preceding endpoint refactor's fulfillment run `38334449650` also passed.

**Duitku documentation caveat:** the current [API reference](https://docs.duitku.com/api/id/) labels “Cek Transaksi” as x-www-form-urlencoded but its included PHP and cURL examples serialize JSON and set `Content-Type: application/json`. This implementation follows the concrete examples, not the conflicting label. It has not been tested against Duitku sandbox yet; perform that verification only after sandbox credentials are configured and before considering production. The current documented signature is HMAC-SHA256 over `merchantCode + merchantOrderId`; MD5 is obsolete. Status-query codes remain separate from callback codes: `00=SUCCESS`, `01=PROCESS`, `02=FAILED_OR_EXPIRED`.

**Open production gap:** a validated `SUCCESS` result is displayed and audited but does not automatically reconcile the payment through the existing callback's idempotent state-transition path. This page is read-only evidence gathering. Do not manually edit statuses, trigger supplier fulfillment, or refund based only on its result. No live API calls or deployment; production checkout and supplier-order flags remain disabled.


### Open issue: status lookup cooldown is not atomic

The current 60-second throttle in `public/admin/reconcile.php` checks recent audit history before the HTTP request, but does not atomically reserve the lookup. Two simultaneous admin POST requests could both observe no recent row and each call the sandbox endpoint. This is a known limitation, not a concurrency-safe rate limiter. Before production or live Duitku use, serialize admission with a short DB transaction that locks the `payment_transactions` row and commits a `STARTED` audit/lease record before network I/O, or create a dedicated unique lease row. Do not hold the transaction during the network request. Add a concurrent-request integration test asserting one outbound call. Current implementation is sandbox-only and no live calls have been made.


### Atomic status-lookup admission — implementation added (2026-10-13)

The reconciliation route now serializes admission attempts by locking the matching `payment_transactions` row with `SELECT ... FOR UPDATE`, checking the last 60 seconds of lookup audit records while holding that lock, and committing a `STARTED` audit event before network I/O. The transaction is released before calling Duitku. A concurrent request for the same payment should therefore observe the committed marker and be rejected without making a second status-query request. No payment/order state is changed.

This closes the race in the prior check-then-call implementation at the code level. However, no dedicated concurrent HTTP/MySQL integration test for this route exists yet; current passing workflows do not independently prove simultaneous status lookup admission. Add a focused two-process test before treating the throttle as production-certified. Sandbox only, no live requests/deployment.


### Cooldown rejection audit fix (2026-10-13)

Commit `b8d7fa77e1d2363a561775ce7bbe0b7675e2db9c` fixes a throttle edge case in `public/admin/reconcile.php`: a request rejected because a lookup already occurred within 60 seconds no longer writes another `DUITKU_STATUS_LOOKUP` error event. Previously, repeated rejected clicks could refresh the audit timestamp and extend the cooldown indefinitely. Genuine lookup attempts that fail after admission still record an error. The atomic row-lock/STARTED-marker approach remains in place, and no payment/order state is changed. This change has been committed to GitHub; wait for CI and add a dedicated concurrent MySQL/web-route regression test before calling the throttle production-verified. No live Duitku request or deployment was performed.


### Cooldown regression guard added to CI (2026-10-13)

Added `tests/reconcile-cooldown-regression.php` and wired it into `.github/workflows/php-quality.yml`. The structural regression test checks that the route retains the payment-row lock, cooldown audit query, committed `STARTED` marker before network I/O, and the guard that suppresses duplicate audit rows for cooldown-denied clicks. This protects the discovered bug against accidental reintroduction. It is explicitly a structural check, not proof of simultaneous-request behavior; the dedicated two-process MySQL/web-route integration test remains required. Commits: test `cce70d749ba6f809a3a2e582f7deeb99dde184e1`; CI workflow `61ad26239ba960b59e5c379c2de254a226c56b83`. No live Duitku call or deployment.


### CI result — cooldown regression guard (2026-10-13)

PHP quality workflow run [38340379209](https://github.com/sulistio95/PluQ-I/actions/runs/38340379209) completed successfully on commit `78e58e8f67ea133d39f36acfc6d245f8956adc2a`. It passed PHP lint, Duitku callback-state mapping, transaction-status validation, the new admin cooldown structural regression guard, checkout request fingerprint, supplier response mapping, and admin authorization tests. This confirms the new structural test is wired correctly and passes in CI; it does not replace the outstanding concurrent MySQL/web-route integration test.


### Shared atomic reconciliation admission and concurrent MySQL test added (2026-10-13)

- Extracted the row-lock + 60-second cooldown + committed `STARTED` audit-marker transaction into `app/services/duitku_reconcile.php`. `public/admin/reconcile.php` calls this helper before any gateway request; a dedicated `DuitkuReconcileCooldownException` marks rejected clicks so they do not create a second lookup audit row.
- Added `tests/reconcile-cooldown-concurrency-integration.php`: two independent PHP processes race for the same disposable MySQL payment fixture. The intended assertion is exactly one admitted attempt, one cooldown rejection, and one recent audit marker. It does not call Duitku or any external API.
- Wired the integration test to run three times in the MySQL 8.4 fulfillment workflow and adjusted the structural regression test to assert the route uses the shared helper.
- Relevant commits: helper `ad24834b92ff8fb6887e7e8450b43b026c709b64`; route integration `1efda2505449740b070f7054ab9c472cd83184f8`, explicit exception `746fb1496499abb7b0d5acfd1ef564351187fc2b`, route exception handling `bcc94ea9d08e8bd98c74ca6322f35b562c54c030`; concurrency test `fa8ab44e4390033513db60fc2747e09a8bcfe143`; structural test `804aa234151e808611c59501b0baca63b97183ec` and ordering correction `8c7d050ca2e68e33f0068b952e7affe1ee6872a9`; workflow `bb740f156bcabda93ac315d60939351eb4ae9f99`.
- **Verification pending:** this test has been committed to CI but its runtime result has not yet been inspected. Do not mark concurrency behavior verified until the MySQL workflow passes all three attempts. It tests the shared admission helper, not two authenticated HTTP requests end-to-end. No live Duitku request, supplier order, production payment, or deployment was performed.


### Reconciliation concurrency CI failure investigated (2026-10-13)

- First run of the new MySQL concurrency test failed after the two workers completed: the parent process reused its PDO/MySQL socket after `pcntl_fork()`, and MySQL reported `server has gone away` when the test queried the final audit count. This is a test harness connection-lifecycle problem, not yet evidence of a cooldown logic failure.
- Fixed `tests/reconcile-cooldown-concurrency-integration.php` to close the parent PDO before forking and establish a fresh parent connection after both children exit. Commit: `8844027fd6cf3290163a3a6cffa21672e4389d62`.
- Syntax workflow passed on the previous commit; the fulfillment workflow failed specifically at the new concurrency integration step. The corrected run is pending; concurrency behavior remains unverified until it passes.
### Concurrent Duitku reconciliation admission — MySQL CI verified (2026-10-13)

The corrected concurrent admission integration test now passes. Fulfillment run [38347691175](https://github.com/sulistio95/PluQ-I/actions/runs/38347691175) completed successfully on commit `2ca838957423caa407fb49524ff23e16eff41723`; its `mysql-callback-integration` and `php-tests` jobs both passed, including all three concurrent reconciliation admission attempts. PHP quality [38347691176](https://github.com/sulistio95/PluQ-I/actions/runs/38347691176) and PHP/JavaScript syntax [38347691179](https://github.com/sulistio95/PluQ-I/actions/runs/38347691179) also passed. The follow-up runs on documentation commit `ddea8e3a5e3ea6080f8716c1a7b389bc0f4e0cf8) also passed: fulfillment `38347687156`, quality `38347687161`, syntax `38347687166`.

This is evidence that two independent PHP processes competing against the same MySQL payment row yield one admitted lookup, one cooldown rejection, and one audit marker in the integration harness. It does not yet test two authenticated HTTP requests through the admin page. No external Duitku request, production transaction, supplier order, or deployment occurred; production checkout/supplier flags remain disabled.

**Next audit focus:** determine whether a test-only HTTP-level admin reconciliation test can safely exercise authentication, CSRF, role checks, and duplicate POST admission without network access. Keep external transport mocked and ensure the route itself cannot be tricked into contacting a live endpoint.
### Reconciliation route security regression guard expanded (2026-10-13)

Commit `5877fa6e1fbd75bea0fe812c8e8a56ad4fecd738` extends `tests/reconcile-cooldown-regression.php` to guard the admin route's CSRF validation, privileged-role check, and read-only boundary (no payment/order UPDATE, checkout creation, or fulfillment call). This is a structural regression guard only; it does not simulate a browser session or replace an authenticated HTTP integration test. CI was triggered by the code change and must be checked before treating the guard as verified. No live gateway calls or deployment.
### Admin reconciliation HTTP boundary integration test added (2026-10-13)

Commits `77a1e0ed67460c84cc14eeea6252937fe5d9f4a8`, `b9e59514989c200e7f73b22c3e2d9fc365518655`, and `136f11118aa181a44a533fae93017dcb746e6ddf` add `tests/reconcile-admin-http-integration.php` and wire it into the MySQL CI workflow after the local PHP server starts. The test creates disposable admin/operator accounts and tests unauthenticated redirect, OPERATOR 403, invalid-CSRF POST 403, unknown POST action 400, and authorized ADMIN form render 200. It never submits a valid status-lookup action and therefore makes no Duitku request. Test-created admin rows are removed in a `finally` block. This expands coverage from source-structure checks to actual HTTP/session/role/CSRF boundaries, but still does not test duplicate valid HTTP status lookup requests or the external transport. CI is pending; do not call the new test verified until the workflow passes.
### CI runner anomaly after adding HTTP boundary test (2026-10-13)

The first workflow attempts after adding `tests/reconcile-admin-http-integration.php` reported failures across syntax, PHP quality, and fulfillment workflows within seconds, but the GitHub Actions job API returned no step summaries (`steps: null`, no log URL) and the job-log endpoint returned 404. This does not identify a test assertion or code failure and is being treated as an infrastructure/runner anomaly until logs or a rerun provide evidence. Failed jobs were requested for rerun on runs [38394970823](https://github.com/sulistio95/PluQ-I/actions/runs/38394970823), [38394970881](https://github.com/sulistio95/PluQ-I/actions/runs/38394970881), and [38394970906](https://github.com/sulistio95/PluQ-I/actions/runs/38394970906). Do not mark the new HTTP test verified until rerun results are available. The test script's first 50 lines were also syntax-checked locally with PHP 8.4; the full CI PHP 8.2 run remains authoritative.
### Follow-up CI attempts still fail before exposing job steps (2026-10-13)

The reruns of the three failed workflow runs also failed within seconds, and new runs triggered by commit `24d6bce9215add73efbfa6a13703c2f661011bd8` likewise failed immediately: fulfillment [38395199538](https://github.com/sulistio95/PluQ-I/actions/runs/38395199538), PHP quality [38395199540](https://github.com/sulistio95/PluQ-I/actions/runs/38395199540), syntax [38395199525](https://github.com/sulistio95/PluQ-I/actions/runs/38395199525). Their job payloads have no step summaries and no log URLs, so the failure happens before any identifiable test step and cannot currently be attributed to the new test code. Prior known-green CI remains the last usable verification of the existing code; the newly added HTTP integration test remains **unverified**. Investigate GitHub Actions runner/repository execution availability or retry when logs are available. Do not claim a green build.


### CI runner failure remains outside visible test steps (2026-10-13, latest check)

Checked the newest workflow runs on commit `fd167feb7018cecd25365275654acdb973f6066b`: fulfillment run `38395277059` and PHP quality run `38395277049` both completed as failures within seconds. The job API reports completed/failure but `steps: null` and `logs_url: null`; earlier syntax run `38395199525` has the same pre-step pattern. Since unrelated workflows fail before any step is exposed, this is not evidence that the new HTTP test itself failed. The root cause remains unconfirmed; inspect repository Actions availability, account billing/minute limits, and runner provisioning before changing application logic. Do not mark CI green or production-ready.

The HTTP boundary test source was reviewed against `admin_auth.php` and the schema: it creates disposable ADMIN/OPERATOR users, exercises anonymous/role/CSRF/unknown-action/read-only-form HTTP paths, and cleans up fixtures in `finally`. It intentionally never submits the valid Duitku lookup action, so it must not call the gateway. Runtime execution remains unverified until the CI runner issue is resolved.


Follow-up after recording this note: the note-only commits also triggered all three workflows, and all failed immediately without visible steps: commit `309c6b8b060ad4c10a20af77b0609162cbc06f6a` (runs `38395658550`, `38395658689`, `38395658589`) and commit `26aa6b64ee5f71acd1c34de9bbe9adc007e311e6` (runs `38395668065`, `38395668156`, `38395668061`). This further supports investigating Actions execution/billing/runner provisioning rather than attributing the failure to a particular test step; root cause is still not established.


### External comparison: failures match the known pre-runner symptom pattern (2026-10-13)

A current GitHub Community discussion dated 2026-09-28 describes the same broad symptom pattern in another repository: Actions jobs fail before the first step, no runner is assigned, and no job logs exist. This supports treating the PluQ-I failures as likely occurring in runner provisioning/orchestration rather than assuming a PHP/YAML test failure; it does **not** prove the same root cause here. Reference: https://github.com/orgs/community/discussions/209001. GitHub's billing documentation also confirms Actions usage/budget can be relevant for private repositories: https://docs.github.com/en/billing/concepts/product-billing/github-actions.

Next checks that require repository-owner UI access: (1) repository Settings → Actions → General, ensure Actions and required actions are allowed; (2) account Settings → Billing & licensing / Budgets and alerts, check payment status, included minutes and spending limit; (3) check https://www.githubstatus.com/ for an active incident; (4) if settings and billing are normal, contact GitHub Support with the failed run URLs and job IDs. No workflow source changes were made in this investigation because the jobs have not reached a visible step. Do not mark any new test as passed.


After this investigation note was committed, the three workflows again failed immediately on commit `851fddb169713d605360c2e49b71cdb87571e16b`: fulfillment `38397679614`, PHP quality `38397679661`, and syntax `38397679602` (2026-10-13 12:29 UTC). This repeats the same pre-step symptom; no root cause is confirmed and no test is marked green.


### Latest continuation check — workflow source and run metadata (2026-10-13)

The current head commit `2d559672eb132c4211eff93d529b749097fc54c8` triggered another set of immediate failures: fulfillment [38397710451](https://github.com/sulistio95/PluQ-I/actions/runs/38397710451), PHP quality [38397710416](https://github.com/sulistio95/PluQ-I/actions/runs/38397710416), and PHP/JavaScript syntax [38397710358](https://github.com/sulistio95/PluQ-I/actions/runs/38397710358). The newest fulfillment job records still have `steps: null` and `logs_url: null`; retrieving logs is unavailable (404). Reviewed the three checked-in workflow YAML files: each uses the ordinary `runs-on: ubuntu-latest` job pattern and normal setup/test steps; no obvious malformed runner declaration was found in the inspected content. The HTTP test source also remains a conventional PHP/cURL/MySQL integration test, but cannot be certified without runtime execution. This reinforces that diagnosis is blocked before visible steps, without proving a GitHub platform cause. No application changes were made; next action still requires owner-side Actions/billing/status checks or GitHub Support. Keep the new HTTP test unverified and do not enable live payment/supplier processing.


### Controlled retry of newest syntax workflow (2026-10-13)

Requested one rerun of syntax workflow [38399998833](https://github.com/sulistio95/PluQ-I/actions/runs/38399998833) to determine whether the failure was transient. Attempt 2 also ended in failure (updated 2026-10-13 12:52:43Z); the same commit's PHP quality run [38399998813](https://github.com/sulistio95/PluQ-I/actions/runs/38399998813) and fulfillment run [38399998667](https://github.com/sulistio95/PluQ-I/actions/runs/38399998667) failed immediately on attempt 1. The retry did not restore CI. No code or workflow changes were made. Root cause remains unknown; test execution is not certified and production remains blocked pending owner-side Actions/billing/platform investigation.


### Latest continuation — Actions metadata rechecked (2026-10-13)

- Rechecked the newest workflow runs triggered by handoff commit `a202647f6295ed4292637dc56a5961f7a0246bda`: PHP quality [38400685887](https://github.com/sulistio95/PluQ-I/actions/runs/38400685887) and fulfillment [38400685767](https://github.com/sulistio95/PluQ-I/actions/runs/38400685767) both failed within about four seconds.
- Job metadata remains opaque: quality job `115307218283`, fulfillment jobs `115307218758` and `115307219224` report `steps: null` and `logs_url: null`. This still does not show any application test executing and does not identify a root cause.
- This result repeats on a note-only commit, so do not attribute it to the new HTTP integration test without evidence. No further rerun or speculative workflow edit was made.
- Required owner-side checks remain: repository Settings → Actions → General; account Billing & licensing / Budgets and alerts; GitHub Status; then GitHub Support if all are normal. Runtime verification of the HTTP integration test remains pending. No live gateway/provider calls or deployment.


### Continuation review — latest Actions runs and admin route (2026-10-14)

- Rechecked the newest workflows after the prior handoff update. PHP quality [38401404982](https://github.com/sulistio95/PluQ-I/actions/runs/38401404982), fulfillment [38401405037](https://github.com/sulistio95/PluQ-I/actions/runs/38401405037), and syntax [38401405068](https://github.com/sulistio95/PluQ-I/actions/runs/38401405068) all failed immediately. Job steps/logs remain unavailable, so the test code is not shown to have executed.
- Re-read `tests/reconcile-admin-http-integration.php`, `public/admin/reconcile.php`, `app/services/duitku_reconcile.php`, `app/services/admin_auth.php`, and the fulfillment workflow. The HTTP test's session cookie name/path matches the admin session setup; it tests role/CSRF/request-boundary behavior and deliberately does not submit a real lookup. The admission helper commits its STARTED audit marker before any outbound request and releases the transaction before network I/O.
- No clear source-level defect was established in this review; no speculative code change was made. The HTTP test still requires runtime execution in CI/staging before it can be marked passed.
- Next blocker remains owner-side GitHub Actions settings/billing/status investigation or GitHub Support. No live gateway/provider calls, supplier fulfillment, or deployment.


### CI check after latest documentation commits (2026-10-14)

- Audit-only commit `5faf902970f9aaff42b75d22ea7448ea6c638e15` triggered PHP quality run [38468671002](https://github.com/sulistio95/PluQ-I/actions/runs/38468671002), which failed with job `steps: null` and `logs_url: null`.
- Handoff commit `f33f062705e451820aa5828266d62ff32f13d8dc` triggered syntax [38468686551](https://github.com/sulistio95/PluQ-I/actions/runs/38468686551), fulfillment [38468686528](https://github.com/sulistio95/PluQ-I/actions/runs/38468686528), and PHP quality [38468686565](https://github.com/sulistio95/PluQ-I/actions/runs/38468686565); all three ended in failure, with no visible steps/logs. This is consistent with the existing pre-step failure pattern, not evidence of a failing test assertion.
- No code change was made. The admin reconciliation HTTP integration test remains runtime-unverified; owner-side Actions settings, billing/budget and GitHub Status checks remain necessary. Live payment/provider calls and deployment remain disabled.


### Checkout idempotency source review — 2026-10-14

- Re-read `app/services/order_checkout.php`, `app/services/checkout_quote.php`, `public/create-payment.php`, migration 003, migration 010, and the checkout fingerprint/concurrency integration tests.
- Confirmed in source: `orders.checkout_key` is uniquely indexed; the key is stored as a SHA-256 digest; request fingerprints bind normalized email, game slug, product ID, and normalized account fields; a key reused with changed payload is rejected; recovery after duplicate-key/deadlock/lock-timeout rolls back and re-reads the winner without calling Duitku again; ambiguous gateway errors are marked for manual review and retries do not dispatch a second gateway request.
- The quote revalidates game/product eligibility, provider reserve balance, excluded services, cheapest matching offer, and configured account fields before inserting the order.
- No definite source-level defect was established in this review. The existing MySQL integration test covers same-key concurrent requests, one mocked gateway call, URL reuse, changed-payload rejection, real deadlock classification, and ambiguous gateway timeout. These checks are **not newly runtime-verified in this session** because GitHub Actions still fails before exposing steps/logs.
- Latest Actions runs for commit `213cccdd7e5db035ee0d3985884d0a64d12330f8`: PHP quality [38468745359](https://github.com/sulistio95/PluQ-I/actions/runs/38468745359) and fulfillment [38468745412](https://github.com/sulistio95/PluQ-I/actions/runs/38468745412) failed within seconds; job steps/logs remain unavailable. Root cause is still unknown.
- No source edits, live Duitku/provider calls, or deployment were performed. Keep production payment/provider flags disabled until CI is restored and the integration tests run successfully.


### Callback and queue lease source review — 2026-10-14

- Re-read `public/duitku-callback.php`, `app/services/duitku.php`, `app/services/queue_worker.php`, `app/services/fulfillment.php`, and callback signature tests.
- Callback source verifies the Duitku signature, validates the 26-character order identifier and integer amount, locks the matching Duitku payment row, and treats non-`PENDING` callbacks as idempotent acknowledgements. Fulfillment is queued only when the callback successfully transitions the order from pending to paid.
- Queue finalization verifies `PROCESSING` plus the same lock token while holding the queue row lock; a stale worker returns `LOCK_LOST` without finalizing. Supplier ambiguity routes to manual review instead of blind resubmission.
- No definite source-level defect was established in this targeted review. Callback/queue integration behavior is not newly runtime-verified because current GitHub Actions runs expose no steps/logs.
- The checkout review was recorded in audit commit `f620f2f49f44f9ce6e3726dc2f87d0dfb59fef56`; current CI blocker remains unresolved. Keep live payment/supplier processing and deployment disabled until workflows can execute and staging verification passes.


## Provider HTTP transport and admin reconciliation review — 2026-10-14

- Reviewed `app/services/provider_http.php`: outbound JSON requests require HTTPS, verify TLS peer/host, disable redirects, bound connect/overall timeouts, reject non-2xx responses and malformed/non-object JSON, and avoid logging request bodies or credential-bearing headers. No live network request was made.
- Reviewed `app/services/duitku.php` and `app/services/duitku_reconcile.php`: status lookup is sandbox-only, uses a separate status-query signature, validates the returned merchant order ID and amount, and does not mutate payment/order state. The admission helper locks the local payment row and commits a STARTED audit marker before network I/O; repeated lookups are limited by a 60-second audit-backed cooldown.
- Reviewed `public/admin/reconcile.php`, `public/admin/game-mappings.php`, `public/admin/index.php`, and `app/services/admin_auth.php`: reconciliation and mapping mutations require an authenticated privileged role, POST requests require CSRF validation, mapping writes and audit records share a transaction, and user-controlled display values are escaped. No definite source-level vulnerability was established in this pass.
- These are source-review findings only; no PHP/MySQL test was executed during this pass. Current CI execution status must be checked separately; existing failures without visible steps/logs cannot be treated as application-test failures or as passing verification.
- No code was changed, no live Duitku/provider call was made, and no deployment occurred. Keep checkout, supplier-order execution, and settlement backup disabled until CI and controlled staging checks are available.


### GitHub Actions follow-up — 2026-10-14

- The documentation-only handoff update triggered PHP/JavaScript syntax workflow run [38472583538](https://github.com/sulistio95/PluQ-I/actions/runs/38472583538), which failed in seconds. GitHub's job metadata reports `steps: null` and `logs_url: null`; no test/lint step output is available, so this does not establish that application checks failed.
- Root cause remains undetermined. Do not change application code or workflow definitions speculatively based on this result. Repository owner should check Actions availability/settings, billing/budgets, GitHub Status, and contact GitHub Support if those are normal.


## Admin login throttle review — 2026-10-14

Reviewed admin login and database-backed rate limiting. A successful login currently clears failed-attempt rows more broadly than the single IP-and-username pair involved, which can erase rate-limit history for unrelated attempts. Proposed a narrow correction and attempted to update the source, but the repository write was blocked; no code change has been committed. This finding remains open. No runtime tests were run, and CI still needs visible job steps/logs before test results can be trusted.


## Admin login throttle cleanup correction — 2026-10-14

- Corrected `admin_login_clear_failures()` in commit `6d0e2f24b4397d0f48148ee4e7bf3e1e0e9a8a77`: successful login now deletes failed attempts only when both the IP address and normalized username hash match. It no longer clears unrelated rows merely because either field matches.
- Added `tests/admin-login-throttle-clear.php` in commit `8e624e68e1c97572aec71268a15ac7e548d9d3d9`, checking the conjunction and bound values; wired it into `.github/workflows/php-quality.yml` in commit `664e9883ce7241c519dc0affb91949503f0054f2`.
- The quality workflow triggered for the test-wiring commit failed before exposing any steps/logs (run [38474440334](https://github.com/sulistio95/PluQ-I/actions/runs/38474440334)); this does not establish whether the new regression test passes. A local PHP 8.4 harness using the committed predicate and regression assertions passed; because this environment lacks mbstring and does not contain a full checkout, a compatibility shim was used for the ASCII test username. This is not a run of the repository test file itself, and CI/runtime verification remains pending.
- No live gateway/provider calls or deployment were performed. Keep live processing disabled until CI is observable and staging verification passes.


## Queue worker and fulfillment follow-up — 2026-10-14

- Re-reviewed `bin/worker-queue.php`, `app/services/queue_worker.php`, `app/services/fulfillment.php`, and queue/fulfillment integration tests. The worker claims jobs transactionally with a per-claim random token; expired leases are not blindly requeued; recovery locks queue → order → supplier; finalization checks the current token; uncertain supplier outcomes enter manual review; definitive failures are persisted separately from payment status and refund creation is idempotent by order lookup. No additional definite source defect was established in this pass.
- No database-backed runtime tests were run here; this is source review only.
- GitHub Actions continues to fail immediately without visible steps/logs across all three workflows for the current documentation commits: syntax [38474672764](https://github.com/sulistio95/PluQ-I/actions/runs/38474672764), PHP quality [38474672798](https://github.com/sulistio95/PluQ-I/actions/runs/38474672798), and fulfillment [38474672805](https://github.com/sulistio95/PluQ-I/actions/runs/38474672805). This still does not show application test failures; root cause remains external/undetermined.
- Do not enable live supplier orders, live payments, or deployment until CI produces inspectable logs and staging checks pass.


## Supplier retry duplicate-order guard — 2026-10-14

- Found a fail-closed gap in `fulfillment_process()`: a VIPayment supplier transaction marked `RETRYING` without a saved provider `trxid` could fall through to the order-creation API path. If the earlier request had reached VIPayment but its reference was not persisted, this could create a duplicate top-up.
- Added `fulfillment_supplier_state_requires_review()` and routed ambiguous supplier states through manual review. VIPayment `RETRYING` is allowed to continue only when a non-empty provider reference exists for status lookup; Digiflazz `RETRYING` remains blocked from automatic resubmission. Source commit: [9930da7](https://github.com/sulistio95/PluQ-I/commit/9930da74ac4d1d8f49e86add191811f4745064dd).
- Added regression cases in `tests/fulfillment-retry-safety-test.php` (commit [99cfd7e](https://github.com/sulistio95/PluQ-I/commit/99cfd7ebaa150051ef6676cd2ed2b2a42ee0542e)) and wired the test into the fulfillment workflow (commit [4af58c0](https://github.com/sulistio95/PluQ-I/commit/4af58c07616cf8b64989ce5d6f9b01872a46e72f)).
- Runtime/CI verification is pending: GitHub Actions has recently failed before exposing steps/logs, so the new test is committed but not yet verified by CI. No live supplier request or deployment was performed. Keep live supplier/payment flags disabled until workflow output and staging checks are available.


### CI status after supplier retry guard — 2026-10-14

- Latest runs on handoff commit `942ca0ef7e83662cc57f72bcf4cee90e72f2cdc6`: syntax [38480851988](https://github.com/sulistio95/PluQ-I/actions/runs/38480851988) and PHP quality [38480851789](https://github.com/sulistio95/PluQ-I/actions/runs/38480851789) failed without exposed step/log metadata; fulfillment [38480851797](https://github.com/sulistio95/PluQ-I/actions/runs/38480851797) also ended with both jobs failed and `steps: null`, `logs_url: null`. These results still do not identify an application test failure or establish why Actions exits early.
- The newly added retry-safety test is therefore committed but not CI-verified. Investigate repository/account Actions availability before making speculative workflow changes. No live requests or deployment.


## Digiflazz balance response validation — 2026-10-14

- Found that `digiflazz_balance()` only checked whether `data.deposit` existed, then cast it to float. Malformed strings such as `not-a-number` can cast to zero and be recorded as a real low balance, obscuring a provider response problem.
- Added `digiflazz_map_balance_response()` to require a numeric, finite, non-negative balance before returning it. Source commit: [9040192](https://github.com/sulistio95/PluQ-I/commit/9040192a403aa3afffb43eb6c448fc1774806c04).
- Added regression coverage for numeric strings, missing/malformed values, negative values, and non-finite strings in [5dcad4e](https://github.com/sulistio95/PluQ-I/commit/5dcad4ed2e83dbb631b6f0facd0841c54d6cc1b7); wired it into the fulfillment workflow in [c7b34f7](https://github.com/sulistio95/PluQ-I/commit/c7b34f7832be06c44be38fa425c9b8b809e1d1ce).
- Verification remains pending until GitHub Actions exposes runnable steps/logs. No live supplier API call or deployment performed.


### CI check after Digiflazz balance guard — 2026-10-14

- Runs on the latest note commit `421e31d0fbfcf79f193b12d24d2bc80f3af0ef94`: fulfillment [38482250710](https://github.com/sulistio95/PluQ-I/actions/runs/38482250710), PHP quality [38482250685](https://github.com/sulistio95/PluQ-I/actions/runs/38482250685), and syntax [38482250681](https://github.com/sulistio95/PluQ-I/actions/runs/38482250681) all reported job failures with `steps: null` and `logs_url: null`. No actionable test failure or root cause is exposed.
- Treat both newly added regression tests as unverified until Actions provides step-level results or the suite is run in a controlled local/staging environment.


## VIPayment balance response validation — 2026-10-14

- Follow-up review found `vipayment_profile()` accepted any `is_numeric()` value and returned its float conversion without checking finiteness or non-negativity. The balance-sync caller also rejects invalid floats, so this was defense-in-depth at the adapter boundary rather than evidence of an incorrect persisted balance.
- Added `vipayment_map_profile_response()` to reject missing, malformed, negative, and non-finite balances before returning profile data. Source commit: [2893932](https://github.com/sulistio95/PluQ-I/commit/2893932819fa8cafa3a1bf5905103df2bddd9675).
- Added `tests/vipayment-balance-validation-test.php` for numeric-string acceptance and missing/malformed/negative/overflow/non-finite rejection (commit [955fd57](https://github.com/sulistio95/PluQ-I/commit/955fd57aeb228e4f0d1b6213049e61cebdd0cdfa)); wired it into `.github/workflows/fulfillment-tests.yml` (commit [40e8bf3](https://github.com/sulistio95/PluQ-I/commit/40e8bf341e12d19a4307ec340826328ec7416c23)).
- Tests are committed but not yet verified by execution. Recent GitHub Actions runs still return failed jobs without step summaries/log URLs; root cause remains unknown. No live supplier/payment request or deployment was performed. Keep production payment and supplier-order flags disabled until tests are observable and staging checks pass.


### GitHub Actions runner allocation evidence — 2026-10-14

- Inspected the GitHub Actions REST job records for the latest syntax, PHP quality, and fulfillment runs on commit `d690c7828117183ca43d1b6cff9c08052c4a0340`. The jobs were created and marked failed within roughly 1–7 seconds, with `steps: []`, `runner_id: 0`, and no `started_at`/`completed_at` step records. This narrows the observable failure to before normal runner-step execution; it does not identify the cause.
- The repository Actions permissions endpoint was not accessible through the current GitHub connector, so account/repository Actions settings, billing/usage limits, policy restrictions, and GitHub service status remain unverified. Do not rewrite workflow definitions without evidence. Owner should inspect repository **Settings → Actions → General**, account billing/Actions usage, and GitHub Status; then rerun workflows and verify step logs.
- No source change or runtime test is claimed from this diagnostic pass. Keep payment/supplier processing disabled and deployment paused until actual test steps execute and pass.


## Duitku status amount integer-overflow hardening — 2026-10-14

- Source review found that transaction-status amount validation cast an arbitrary digit string to PHP `int` before comparing. Oversized decimal strings can saturate at `PHP_INT_MAX`; if the expected amount were also `PHP_INT_MAX`, a larger amount could be mistaken for an exact match.
- Updated `duitku_validate_transaction_status_response()` to compare normalized decimal strings instead of casting untrusted amount text: [source commit b6bba9d](https://github.com/sulistio95/PluQ-I/commit/b6bba9d5e3c5e2f9c708ef5904b52d06cb0b9662).
- Extended `tests/duitku-transaction-status.php` with oversized amounts, leading-zero mismatch, and a direct regression where an amount larger than `PHP_INT_MAX` must not match `PHP_INT_MAX`: [test commit 12042e3](https://github.com/sulistio95/PluQ-I/commit/12042e37603638481ce23cddeed87c4b8ac3601a).
- This is a source/test change only; the regression test has **not** been executed in this session. GitHub Actions still needs inspectable step logs before CI can be considered verified. No live Duitku/provider call or deployment; keep live processing disabled.


### CI result after Duitku overflow regression — 2026-10-14

- The note commit triggered syntax run [38485787043](https://github.com/sulistio95/PluQ-I/actions/runs/38485787043) and fulfillment run [38485787071](https://github.com/sulistio95/PluQ-I/actions/runs/38485787071); both failed immediately. Job records show `steps: null` and `logs_url: null` (jobs `115598975273`, `115598975592`, and `115598975726`). This still does not establish whether lint/tests ran or failed; root cause remains unknown.
- The integer-overflow fix and regression case are committed, but **not runtime-verified**. Restore inspectable Actions execution or run the suite in a controlled PHP 8.2+/MySQL environment before treating checks as green. Keep production payment/supplier execution disabled and do not deploy.


### Further CI log retrieval attempt — 2026-10-14

- Queried step summaries for failed jobs `115598975273`, `115598975592`, and `115598975726`; GitHub returned empty step arrays for all three. Attempted to retrieve their logs, but the log artifacts returned `BlobNotFound`. Thus there is still no evidence that lint or tests executed, and no actionable assertion/runner error is exposed by the available job records.
- Continued read-only review of Duitku callback, checkout idempotency, order status, and supplier recovery paths; no additional source change was made because the observed terminal-callback/order-state edge cases require a database-backed integration test and explicit reconciliation policy before safely changing payment states. Do not silently change a terminal payment to PAID based only on a conflicting callback.
- Next safe step is to restore actionable CI logs or execute the existing PHP/MySQL integration tests in a controlled environment. No live API calls or deployment.
