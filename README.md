# PluQ-I

Shared-hosting edition of PluQ for PHP 8.2+ and MySQL/MariaDB. This repository is separate from the primary `sulistio95/PluQ` repository, which is intended for deployment to Cloudbaik.

## Continuing work in a new ChatGPT chat

Read [`HANDOFF_NEXT_CHATGPT.md`](HANDOFF_NEXT_CHATGPT.md) first, then [`docs/production-readiness-audit.md`](docs/production-readiness-audit.md). The handoff records project boundaries, latest verified changes, test limitations, production safety flags, and the prioritized next steps. Update it as work progresses; do not treat unverified tests as passing.

## Hosting split

- **PluQ** (`sulistio95/PluQ`): primary edition; Cloudbaik is the planned hosting target.
- **PluQ-I** (`sulistio95/PluQ-I`): low-cost shared-hosting edition; the target may be Rumahweb/Rumah Hosting or another provider that meets the compatibility checklist below.
- Keep deployments, environment files, databases, API credentials, and backup destinations separate. Do not copy PluQ-I changes into the primary PluQ repository unless that is explicitly intended.
- Neither project should be treated as production-ready just because its code is present in GitHub.

### Shared-hosting compatibility checklist

Before buying or selecting a plan for PluQ-I, confirm with the hosting provider that the exact plan supports:

- PHP 8.2 or a newer supported PHP release, with `pdo_mysql`, `curl`, `openssl`, `json`, `mbstring`, and `fileinfo`.
- MySQL/MariaDB with InnoDB, database creation/import, and a database user restricted to the project database.
- HTTPS and configurable callback/return URLs for Duitku.
- A way to keep `.env` and application files outside the public document root, or web-server rules that deny access to private files.
- Cron jobs running PHP CLI, including the required frequency and execution time. Confirm any process/time limits and whether cron is included in the chosen plan.
- Outbound HTTPS requests to Duitku, Digiflazz, VIPayment, and Google APIs.
- Enough storage and database capacity, and a usable database backup/restore facility.

Do not assume every shared-hosting plan supports cron, the needed PHP extensions, or unrestricted outbound API access. If cron is unavailable, we must design and protect a scheduler alternative before enabling scheduled jobs; never expose an unauthenticated job runner.

## Current implementation status

- Foundation: PHP bootstrap, environment configuration, PDO connection, MySQL schema and operational tables.
- Public catalog: game, banner, product and automatically generated game-input endpoints plus a responsive dark storefront with search and a 3-second banner rotation. Manual per-game account-field editing has been removed; see `docs/automatic-game-account-form.md` for current rules and limits.
- Server-side price quotes validate the game, product, account fields, provider reserve and excluded services; the server recalculates selling price and admin fee.
- Admin transaction inspection is available at `/admin/` after an administrator account is provisioned. It filters by provider API, gateway, date/time range, payment status, order status, and the latest refund status (`PENDING`, `PROCESSING`, `SUCCESS`, `FAILED`). Refund filtering is based on the newest refund record per order; access is session-authenticated.
- Monthly settlement backup creates a formatted four-tab Google Sheets workbook in Google Drive after migrations 006–007 and OAuth/folder configuration. It is disabled by default and has not been tested against a real Google account from the hosting environment.
- Checkout binds its idempotency key to a normalized request fingerprint (email, game, product, and account fields), with persistent pending-order/payment records and Duitku sandbox request support. Configure `DUITKU_PAYMENT_METHOD`, credentials, and HTTPS callback/return URLs in the private `.env`.
- Duitku callback validates HMAC-SHA256, checks the order and amount, treats result code `01` as failed, according to the server-to-server callback documentation, and only queues fulfillment after the first valid transition to paid.
- Supplier adapters, balance sync, and an admin page for mapping supplier game codes to local games are committed; mappings still need to be configured and verified against the actual provider catalogs.
- GitHub Actions now lints PHP files and runs isolated Duitku callback-state, checkout fingerprint, and supplier mapping tests. Database-backed integration tests and live provider behavior still require a configured test database and staging credentials; do not infer they passed from the lint workflow.
- The stage-by-stage source review and staging exit checklist are documented in `docs/production-readiness-audit.md`. The detailed hosting and payment-test sequence, including current sandbox-only blockers, is in `docs/staging-payment-test-runbook.md`.

**Not production-ready:** real payments and supplier execution must remain disabled until the payment flow and provider behavior are tested in a controlled staging environment. Supplier API credentials, account/zone mapping for each game, retry/refund recovery, username verification, live provider behavior, and deployment hardening still require staging verification. Do not enable production payments or supplier execution.

## Target architecture

- PHP 8.2+; prefer a currently supported PHP version if offered by the host.
- MySQL/MariaDB through PDO.
- No Node.js, Redis, BullMQ or permanently running worker.
- Database-backed jobs processed in bounded batches through cron if available.
- If cron is unavailable, use only a protected and authenticated scheduler design after it is implemented and tested.
- Payment callbacks must be authenticated and idempotent before fulfillment.
- Keep API credentials in environment settings or a private `.env` file, never in Git.

## Requirements

PHP extensions: `pdo_mysql`, `curl`, `openssl`, `json`, `mbstring`, and `fileinfo`. MySQL/MariaDB with InnoDB and HTTPS are required.

## Initial setup

1. Create a dedicated MySQL/MariaDB database and database user.
2. Put `.env` outside the public document root where possible; otherwise configure server rules to deny web access to it and all private project directories.
3. Set database credentials, a long random `APP_KEY`, and a separate random `SCHEDULER_TOKEN`.
4. Configure Digiflazz and VIPayment credentials only in the private environment. Verify the VIPayment API ID/key belong to the intended VIP-Reseller account before making calls.
5. Import `database/schema.mysql.sql` into an empty database.
6. Apply migrations in order:
   - `database/migrations/002_catalog_and_operations.sql`
   - `database/migrations/003_checkout_payment.sql`
   - `database/migrations/004_supplier_manual_review_status.sql`
   - `database/migrations/005_order_manual_review_status.sql`
   - `database/migrations/006_monthly_settlements.sql` (when enabling settlement records)
   - `database/migrations/007_admin_drive_folder_setting.sql` (when enabling the admin-managed Drive folder setting)
   - `database/migrations/008_admin_login_rate_limit.sql` (required for database-backed admin login throttling)
   - `database/migrations/009_refund_order_latest_index.sql` (recommended for efficient latest-refund lookups in admin/status queries)
   - `database/migrations/010_checkout_payload_fingerprint.sql` (required to prevent an idempotency key from being reused with different checkout details)
   - `database/migrations/011_provider_budget_reservations.sql` (required before enabling paid supplier fulfillment; reserves aggregate provider purchasing capacity)
7. Configure Duitku sandbox credentials and set `DUITKU_PAYMENT_METHOD` to a method enabled for the sandbox merchant. Configure HTTPS callback URL `https://your-domain/duitku-callback.php` and return URL `https://your-domain/order-status.php`.
8. Point the document root at `public/` if supported. Otherwise deny direct access to `app/`, `config/`, `database/`, `bin/`, `tests/`, `.env`, and logs.
9. Run migrations and create the first admin from the server CLI; do not create an admin through a public one-off script.
10. Keep `SUPPLIER_ORDERS_ENABLED=false` and `SETTLEMENT_BACKUP_ENABLED=false` until their respective staging tests and recovery checks are complete.
11. Run `php bin/preflight.php` from the hosting CLI after configuring `.env` and applying migrations. Resolve every failed check; the script never prints secret values.
12. Do not enable live payments until the sandbox lifecycle, security review, backup/restore procedure, and provider reconciliation checks have passed.

## Scheduled work

**The cron schedule is configured in the hosting control panel, not in GitHub.** The repository does not currently have a live cron job because no hosting account has been deployed yet. The examples below must be adapted to the PHP CLI path and absolute project path supplied by the host.

For **DomaiNesia Nimbus (minimum interval 6 minutes)**, use this as the initial queue-worker schedule:

```cron
*/6 * * * * /absolute/path/to/php /absolute/path/to/PluQ-I/bin/worker-queue.php
```

Replace both paths with the actual PHP CLI executable and project path from the hosting provider. Do not copy a guessed path. If using a provider that permits cron only every 15 minutes, change the schedule to `*/15 * * * *` and account for the longer queue delay.

Optional scheduled tasks must be added separately, only after their feature and credentials have been tested:

- Catalog synchronization: `bin/sync-catalog.php`. A 15-minute interval is an initial example only; confirm supplier rate limits and mapping configuration first.
- Supplier balance synchronization: `bin/sync-balances.php`. Do not enable an aggressive interval until supplier API limits and expected request volume are confirmed.
- Monthly settlement/Google Drive export: `15 2 1 * * /absolute/path/to/php /absolute/path/to/PluQ-I/bin/settle-monthly.php`. Add this only after Google OAuth, destination folder, migrations 006–007, and backup/recovery tests are complete. Settlement backup is disabled by default.

The queue worker processes a bounded batch (default `SCHEDULER_BATCH_SIZE=5`) and stops after a bounded runtime (default `SCHEDULER_MAX_RUNTIME_SECONDS=20`; hard maximum 50 seconds). These settings are in the private environment configuration, not the cron schedule. Keep the defaults initially; only tune after measuring queue backlog, PHP runtime, database load, and provider rate limits.

`bin/worker.php` is a read-only queue diagnostic; it never changes job state. `bin/worker-queue.php` is the actual queue processor. It remains disabled while supplier ordering is disabled, and supplier orders must stay disabled during the initial Duitku Sandbox payment test. Use `php bin/queue-health.php` through CLI to inspect ready backlog, oldest ready-job age, stale locks, and recent failures.

Do not schedule overlapping worker invocations or add parallel workers before concurrency and provider rate-limit behavior have been tested on the target MySQL/MariaDB version. A six-minute cron does not guarantee a job completes within six minutes; if the backlog repeatedly grows faster than it drains, the plan may be too limited for the actual load. Never solve an ambiguous supplier result by blindly retrying a purchase.

## Feature parity target

Dynamic game input forms, username/account validation where supported, Digiflazz/VIPayment catalog synchronization, lowest-price selection, Duitku payment creation/callbacks, idempotent order queue, bounded retries, separate payment/order statuses, provider balance reserves, admin panel, icon management, pricing rules, manual review/refunds, audit logs and health checks.

Each feature must be implemented and tested before being marked complete.

## Invoice expiry reconciliation

- Default Duitku invoice expiry is 30 minutes and can be set by an admin in **Pengaturan API → Duitku → Masa berlaku invoice** (5–1440 minutes). The same setting drives the gateway request and local deadline.
- After the deadline, use the CLI script `php bin/reconcile-expired-payments.php` from cron. It queries Duitku sandbox status and only marks a pending invoice expired when the gateway confirms failed/expired; it never triggers supplier fulfillment. Keep it out of the public web root/URL and use a protected CLI cron job.
- Runtime verification is pending. Keep checkout, supplier ordering, and Drive backup disabled while testing.


## Account form automation (2026-10-19)

- Work is on branch `feature/automatic-game-account-form`; it has not been merged to `main`.
- Removed the manual **Konfigurasi Form Akun** admin link and deleted `public/admin/game-fields.php`; old database tables are left untouched for backwards compatibility and audit history.
- Account inputs are generated from synchronized game name/slug rules: all games receive User ID; Mobile Legends receives Zone ID; names explicitly identifying server/region receive a Server field when no Zone ID rule applies. Checkout is blocked for games outside the reviewed automatic mapping list or with an unmapped Server field, rather than risking an incorrectly targeted top-up.
- The VIPayment services list does not publish a formal per-game form schema. The current mapping is therefore a conservative fallback, not proof that every game/provider uses these exact fields. Unknown games receive User ID only. Review and expand the automated rules using provider documentation before enabling production checkout.
- The storefront attempts VIPayment `get-nickname` automatically after required inputs are complete, but only when an active VIPAY product code exists for the game. A missing code, unsupported game, API error, or invalid ID is shown as a status; the website does not invent a username. When checking is unavailable, a warning is shown and local input validation may continue.
- Checkout quote validation now uses the generated schema instead of `game_fields`. No supplier orders, real payments, or Google Drive backups were run. PHP/browser/runtime tests and the post-change GitHub Actions run are pending.
