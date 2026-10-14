# PluQ-I staging and payment-test runbook

Last reviewed: 2026-10-14

This is an operational checklist, not a production approval. No live payment or supplier API request has been made from this review.

## Current hard blockers confirmed from source

- `app/services/duitku.php` explicitly accepts only `DUITKU_ENV=sandbox`. Setting it to `production` currently throws an exception. **Real Duitku payment mode is not implemented/enabled in this build.**
- `checkout_payments_enabled()` in `app/services/checkout_quote.php` returns true only when both `CHECKOUT_PAYMENTS_ENABLED=true` and `SUPPLIER_ORDERS_ENABLED=true`. Therefore, payment sandbox testing cannot currently be isolated from supplier fulfillment through the normal checkout flow.
- GitHub Actions runs are failing before runner allocation/step recording (`runner_id=0`, empty `steps`, no usable logs). This does not prove an application test failed, but it means the current CI status is not verified.
- Do not solve these blockers by turning on supplier ordering or changing Duitku to production blindly.

## Stage A — prepare hosting without taking payments

- [ ] Select a staging domain/subdomain and enable HTTPS.
- [ ] Confirm PHP 8.2+, `pdo_mysql`, `curl`, `openssl`, `mbstring`, `fileinfo`, and MySQL/MariaDB with InnoDB.
- [ ] Point the web document root to `public/`; otherwise deny direct web access to `app/`, `config/`, `database/`, `bin/`, `tests/`, `.env`, and logs.
- [ ] Create a separate staging database and restricted database user.
- [ ] Generate unique staging `APP_KEY` and `SCHEDULER_TOKEN`; never reuse production secrets.
- [ ] Upload private `.env` outside the public document root if possible. Keep `APP_DEBUG=false`.
- [ ] Keep `CHECKOUT_PAYMENTS_ENABLED=false`, `SUPPLIER_ORDERS_ENABLED=false`, and `SETTLEMENT_BACKUP_ENABLED=false`.
- [ ] Import `database/schema.mysql.sql`, then apply migrations 002 through 011 in order (migrations 006–007 are required if settlement backup is later enabled; migration 011 is required before the callback or fulfillment code that uses provider budget reservations).
- [ ] Run `php bin/preflight.php` from CLI. Save output without secrets and resolve every failed check.
- [ ] Verify backup creation and restore to a disposable database before any real transaction.

## Stage B — restore observable automated testing

- [ ] In GitHub, inspect **Repository Settings → Actions → General**, Actions permissions/policies, account billing/usage limits, and GitHub Status.
- [ ] Rerun the workflows and confirm GitHub assigns a runner and records step names/logs.
- [ ] Require all syntax, PHP unit, and MySQL 8.4 integration jobs to complete successfully. A green lint job alone is insufficient.
- [ ] Specifically verify checkout idempotency/deadlock recovery, valid and invalid Duitku signatures, wrong amounts, duplicate callbacks, contradictory terminal callbacks, queue concurrency, supplier ambiguity, and refund recovery.
- [ ] If Actions still fails before steps, run the suite in a controlled PHP 8.2 + MySQL 8.4 environment and retain reproducible logs; do not label tests passed without them.

## Stage C — enable an isolated Duitku sandbox test

**Blocked pending a code change and regression tests:** the current checkout gate requires supplier ordering enabled at the same time as checkout payments. Before testing the real Duitku sandbox through the public checkout, implement and test an explicit sandbox-only payment path that cannot call supplier order APIs. Do not use a production flag or a public bypass to work around this.

Required acceptance criteria for that change:
- Sandbox-only mode is explicit and rejects any non-sandbox Duitku endpoint.
- It can create and receive a sandbox payment without making supplier purchase/status calls.
- A successful sandbox callback records the payment and routes the order to a safe test/manual-review state, or to a clearly isolated test queue that cannot execute supplier calls.
- Invalid signature, wrong amount, repeated callback, late/conflicting callback, and timeout cases are covered by integration tests.
- No production credentials are required or logged in test output.

After that change and CI pass:
- [ ] Configure only Duitku sandbox credentials and HTTPS callback/return URLs on staging.
- [ ] Confirm callback reachability from outside the host and inspect redacted server logs.
- [ ] Complete a sandbox payment lifecycle: create payment → gateway callback → persisted status → customer status page → duplicate callback check.
- [ ] Confirm supplier API order endpoints were not called in this isolated test.

## Stage D — provider integration verification

Only after payment sandbox checks pass:
- [ ] Configure Digiflazz and VIPayment credentials in private staging environment variables.
- [ ] Verify account identity and balance using the provider's documented read-only/profile or balance endpoints first.
- [ ] Configure and verify exact game/provider mappings and compare product counts and sample prices.
- [ ] Test supplier purchase, status polling, timeout/ambiguous outcomes, retry caps, manual review, and refund reconciliation using provider-approved test products/low-risk controlled procedures.
- [ ] Confirm the supplier supports a test/sandbox mode before attempting any purchase. If not, obtain explicit approval for a small controlled live test and define rollback/refund ownership first.
- [ ] Keep supplier ordering disabled until the above checks and operational recovery review pass.

## Stage E — production gate (not currently approved)

Production payment mode must be implemented deliberately and separately from sandbox mode, with tests for the production endpoint/signature configuration and an explicit operator-controlled release. Before activation, verify HTTPS, callback IP/signature rules as applicable, secrets/permissions, monitoring, backup/restore, order reconciliation, refund procedures, and alert ownership. Do not simply change `DUITKU_ENV` to `production`: current code intentionally rejects it.

## Evidence log

- 2026-10-14: source review confirmed the sandbox-only Duitku guard and combined checkout/supplier feature gate.
- 2026-10-14: recent GitHub Actions runs failed before runner allocation or step recording; cause remains unknown and application tests are not claimed as passed.
- 2026-10-14: no live payment, supplier purchase, or deployment was performed.

## Additional gate — provider balance concurrency

- Source review on 2026-10-14 found that checkout compares each order to a stored provider balance/reserve percentage but does not reserve aggregate capacity for paid orders waiting in the queue. Concurrent orders can all pass against the same snapshot and collectively exceed the configured usable balance.
- Before supplier ordering is enabled, add an InnoDB-backed provider budget reservation/commitment ledger, serialize capacity decisions by locking the provider row, and release reservations idempotently only on definitive terminal outcomes. Keep reservations for ambiguous/manual-review orders until reconciliation.
- Add MySQL concurrency tests proving multiple simultaneous orders cannot reserve more than the configured usable balance. This is a source-level finding, not evidence of a live overspend. Do not enable supplier ordering until fixed and tested.

## Provider budget reservation implementation — 2026-10-14

- New migration: `database/migrations/011_provider_budget_reservations.sql`; new service: `app/services/provider_budget.php`. Apply migration 011 before releasing code that requires the reservation table.
- Normal successful-payment callback must reserve supplier cost under a provider-row lock before enqueueing fulfillment. If capacity cannot be reserved, expect payment `PAID`, order `MANUAL_REVIEW`, and zero new `FULFILL_ORDER` jobs.
- `ACTIVE` and `REVIEW` reservations consume capacity. Supplier-confirmed success changes the reservation to `SETTLED`; definitive supplier failure changes it to `RELEASED` while refund review remains pending; ambiguous outcomes stay in `REVIEW` and continue consuming capacity.
- Unit boundary test added at `tests/provider-budget-capacity.php` and included in the PHP quality workflow. This does not replace a MySQL concurrency test: before enabling supplier orders, verify simultaneous callbacks cannot reserve more than the configured usable balance and test duplicate callback/idempotency behavior.
- This implementation has not yet passed a successful CI run or live MySQL integration test. Keep supplier orders and production deployment disabled until verified.

- Additional fail-closed guard: queue processing must refuse to call suppliers unless the order has an `ACTIVE` provider budget reservation. Test old/unreserved queue rows, `REVIEW` reservations, and duplicate callbacks; all must avoid a supplier API call and route inconsistent paid orders to manual review.
- Migration 011 has been added to the MySQL CI migration list and README install order. CI has not yet shown executed steps; do not count the test as passed.

- Verify stale-balance protection: after a supplier success, the `SETTLED` reservation must continue counting until a balance sync that started after the settlement updates `last_balance_check_at`. Simulate a slow balance request overlapping a supplier success and ensure the older request's late response does not release that commitment.


### Reservation recovery consistency fix — 2026-10-14

- Source audit found a crash-recovery path in `app/services/fulfillment.php`: if the supplier transaction was already persisted as `SUCCESS` but the process crashed before resolving the budget reservation, the next worker marked the order successful without changing the reservation from `ACTIVE` to `SETTLED`.
- Fixed in commit [defd9d5](https://github.com/sulistio95/PluQ-I/commit/defd9d599f8c71b795ac7525addfa44f46a70178). The recovery branch now resolves the reservation to `SETTLED` inside the same DB transaction. This is an idempotent state-recovery fix and keeps the balance ledger consistent.
- Updated staging instructions to include migration 011 in the ordered migration list. Runtime PHP/MySQL tests remain unverified because GitHub Actions has not exposed executed steps/logs. Next priority: diagnose runner availability and add/execute a MySQL contention test proving two concurrent reservations cannot exceed the provider's usable balance. Keep payment/supplier production operations disabled.


### MySQL provider reservation integration coverage added — 2026-10-14

- Added `tests/provider-budget-reservation-mysql-integration.php` in commit [ed2ae8a](https://github.com/sulistio95/PluQ-I/commit/ed2ae8ab9c290408eda293ed64dde1db3fca15db). Against the configured MySQL schema, it creates disposable fixtures and checks that a Rp300,000 commitment blocks a further Rp200,001 against Rp500,000 usable capacity; releasing the first reservation frees capacity; and duplicate reservation calls reuse the single ledger row.
- Wired the test into `.github/workflows/fulfillment-tests.yml` after migration 011 in commit [81aea76](https://github.com/sulistio95/PluQ-I/commit/81aea76c0ff25d0132721fae1680750a9cab0573).
- This is a real-MySQL integration test for aggregate capacity/idempotency, but **not yet a simultaneous multi-connection contention test**. It has not run successfully because current GitHub Actions runs fail before step logs are available. Do not represent this test as passed. Continue investigating CI runner failure and add a genuine concurrent reservation test before enabling supplier orders.


### Concurrent provider-budget integration test — 2026-10-14

- `tests/provider-budget-reservation-mysql-integration.php` now covers aggregate capacity, release, duplicate idempotency, and a simultaneous two-process/two-PDO contention attempt. Two orders each cost Rp300,000 and compete for Rp500,000 usable capacity; only one should reserve successfully.
- Test is wired into the existing MySQL 8.4 job after migration 011; no workflow change was needed for the expanded script.
- **Status: not yet executed successfully.** Run [38529583687](https://github.com/sulistio95/PluQ-I/actions/runs/38529583687) failed before steps/logs were recorded. A local PHP syntax check of a process-I/O snippet is not proof of this integration test. Diagnose runner failure and rerun the full workflow before treating provider budget locking as verified. Keep production payments, supplier orders, and deployment disabled.

- CI retry evidence — 2026-10-14: manually reran the syntax-check job in [workflow run 38529853413](https://github.com/sulistio95/PluQ-I/actions/runs/38529853413); retry still failed before step recording. New PHP quality and fulfillment runs show the same symptom. This remains an infrastructure/runner diagnostic blocker of unknown cause, not evidence that the integration assertions passed or failed.


### Stale cached balance timestamp regression — 2026-10-14

- `provider_budget_reserve_paid_order()` conservatively counts SETTLED reservations when `reservation.updated_at >= providers.last_balance_check_at`; equality is ambiguous because timestamps may have only second precision.
- The MySQL integration test now checks that a settled Rp300,000 purchase at the exact same timestamp as the cached balance check blocks a further Rp200,001 commitment against Rp500,000 capacity. It also covers two concurrent Rp300,000 reservations, expecting only one to be admitted.
- Code commits: [d61a7c8](https://github.com/sulistio95/PluQ-I/commit/d61a7c89be9ca8c21a78495339746af62efd07de), [8fb1105](https://github.com/sulistio95/PluQ-I/commit/8fb110597c22ecf6bbd5e4919bb2cc3dfa676961). Neither test is verified as passing until the MySQL workflow has actual step logs and success.
- Runner diagnosis: run [38529853413](https://github.com/sulistio95/PluQ-I/actions/runs/38529853413) reports no runner name or steps and log download returns `BlobNotFound`. Current public GitHub status is operational; cause of this repo's failure remains unknown. Local PHP lint could not run because this environment cannot resolve `raw.githubusercontent.com`.


### Reservation contention synchronization — 2026-10-14

- Both child PHP processes now announce readiness through per-order marker files and wait on a common barrier. The parent releases them only after both markers exist (or fails after timeout), making the concurrency assertion meaningful rather than merely starting workers in sequence.
- MySQL workflow step label now explicitly covers capacity, idempotency, concurrency, and stale-balance timestamp boundary. Commits [6025cd6](https://github.com/sulistio95/PluQ-I/commit/6025cd6e7be77d3f6d0dbe2e601297a4b5b1009a), [870daf7](https://github.com/sulistio95/PluQ-I/commit/870daf71c105589a627dd63d8da299c9cd62b899).
- Staging gate remains closed until the workflow executes with actual runner logs and the MySQL integration assertions pass.

- Latest verification attempt after the concurrency and timestamp-boundary changes: syntax runs [38533061161](https://github.com/sulistio95/PluQ-I/actions/runs/38533061161) and [38533044011](https://github.com/sulistio95/PluQ-I/actions/runs/38533044011) failed before steps/logs (`steps: null`). PHP-quality and fulfillment runs were still queued. No PHP lint, MySQL assertion, or application test can be considered executed/passed from these runs.
