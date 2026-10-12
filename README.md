# PluQ-I

Shared-hosting edition of PluQ for PHP 8.2+ and MySQL/MariaDB. This repository is separate from the primary `sulistio95/PluQ` repository, which is intended for deployment to Cloudbaik.

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
- Public catalog: game, banner, product and dynamic game-input endpoints plus a responsive dark storefront with search and a 3-second banner rotation.
- Server-side price quotes validate the game, product, account fields, provider reserve and excluded services; the server recalculates selling price and admin fee.
- Admin transaction inspection is available at `/admin/` after an administrator account is provisioned. It filters by provider API, gateway, date/time range, payment status, order status, and the latest refund status (`PENDING`, `PROCESSING`, `SUCCESS`, `FAILED`). Refund filtering is based on the newest refund record per order; access is session-authenticated.
- Monthly settlement backup creates a formatted four-tab Google Sheets workbook in Google Drive after migrations 006–007 and OAuth/folder configuration. It is disabled by default and has not been tested against a real Google account from the hosting environment.
- Checkout has an idempotency key, persistent pending-order/payment records, and Duitku sandbox request support. Configure `DUITKU_PAYMENT_METHOD`, credentials, and HTTPS callback/return URLs in the private `.env`.
- Duitku callback validates HMAC-SHA256, checks the order and exact amount, and only queues a fulfillment job once for a successful pending payment.
- Supplier adapters and balance sync are committed but have not been verified against the user's real credentials.
- CI runs syntax checks and automated pricing, callback-signature, mapping, and database integration tests. Review the latest GitHub Actions result for the exact commit being evaluated.

**Not production-ready:** real payments and supplier execution must remain disabled until the payment flow and provider behavior are tested in a controlled staging environment. Supplier fulfillment, retries/refunds, username verification, live provider API behavior, game mapping setup, and admin-panel security still require implementation or verification. Do not enable production payments or supplier execution.

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
7. Configure Duitku sandbox credentials and set `DUITKU_PAYMENT_METHOD` to a method enabled for the sandbox merchant. Configure HTTPS callback URL `https://your-domain/duitku-callback.php` and return URL `https://your-domain/order-status.php`.
8. Point the document root at `public/` if supported. Otherwise deny direct access to `app/`, `config/`, `database/`, `bin/`, `tests/`, `.env`, and logs.
9. Run migrations and create the first admin from the server CLI; do not create an admin through a public one-off script.
10. Keep `SUPPLIER_ORDERS_ENABLED=false` and `SETTLEMENT_BACKUP_ENABLED=false` until their respective staging tests and recovery checks are complete.
11. Run `php bin/preflight.php` from the hosting CLI after configuring `.env` and applying migrations. Resolve every failed check; the script never prints secret values.
12. Do not enable live payments until the sandbox lifecycle, security review, backup/restore procedure, and provider reconciliation checks have passed.

## Scheduled work

The following is an example only. Adapt the PHP executable and absolute paths to the hosting control panel, and confirm that CLI cron is included in the selected plan:

```cron
* * * * * /usr/bin/php /absolute/path/PluQ-I/bin/worker-queue.php
15 2 1 * * /usr/bin/php /absolute/path/PluQ-I/bin/settle-monthly.php
```

Catalog sync is scheduled every 15 minutes in this example; Digiflazz's full price list is rate-limited, so do not run it every minute. Before the first sync, add active rows to `game_provider_mappings` so each provider's exact brand/game code points to the correct local `games.id`. For VIPayment, `VIPAYMENT_PRICE_TIER` selects `basic`, `premium`, or `special` (default `basic`). Balance sync runs every minute in this example and requires supplier credentials. Review cron output and provider records after a controlled test.\n\nMonthly settlement backup is disabled by default. It requires Google OAuth configuration, the intended destination folder, migrations 006–007, and a controlled upload/recovery test before enabling `SETTLEMENT_BACKUP_ENABLED=true`. See `docs/admin-transactions-and-settlement.md`.

`bin/worker.php` is a read-only queue diagnostic; it never changes job state. `bin/worker-queue.php` is the queue processor, but supplier execution remains disabled unless `SUPPLIER_ORDERS_ENABLED=true`. Do not enable it until provider sandbox behavior and manual reconciliation have been verified. Do not interpret the cron example as evidence that supplier fulfillment is ready.

### Queue capacity, monitoring, and recovery

- The queue is persistent in MySQL, not held in PHP memory. Callback requests only record the verified payment and enqueue a job; supplier API calls happen in the separate worker.
- The worker is bounded per invocation: default 5 jobs and 20 seconds, with environment limits of at most 20 jobs and 50 seconds. A busy queue should build a backlog rather than make one worker run without a bound. This protects memory/runtime, but it does **not** guarantee immediate fulfillment during a traffic spike.
- Start with one cron invocation per minute. Monitor it with `php bin/queue-health.php`; the read-only report shows ready backlog, oldest ready-job age, stale locks, and recent failures. A warning exit code means the report should be investigated, not that jobs were changed.
- Increase `SCHEDULER_BATCH_SIZE` gradually (for example 5 to 10, then at most 20) only after measuring PHP runtime, database load, and each supplier's rate limits. `SCHEDULER_MAX_RUNTIME_SECONDS` is capped at 50 seconds. Do not schedule overlapping workers or add parallel workers until concurrency and provider rate-limit behavior have been tested on the target MySQL/MariaDB version.
- If backlog repeatedly grows faster than the worker drains it, a once-per-minute shared-hosting cron is not enough. Use hosting-supported more frequent cron if permitted, or move the worker to a dedicated process/queue service on suitable hosting. Do not try to solve a sustained capacity problem by increasing PHP memory or retrying supplier requests blindly.
- Stale `PROCESSING` jobs older than 10 minutes are moved to manual review, not automatically resubmitted. This is intentionally conservative because a supplier may have accepted an order just before a PHP process crashed.
- For larger traffic, the next architecture step is a tested worker with concurrent job claiming (for example `SKIP LOCKED` after verifying server-version support), provider-specific rate limiting, and load tests. Those features are not yet claimed as implemented.


## Feature parity target

Dynamic game input forms, username/account validation where supported, Digiflazz/VIPayment catalog synchronization, lowest-price selection, Duitku payment creation/callbacks, idempotent order queue, bounded retries, separate payment/order statuses, provider balance reserves, admin panel, icon management, pricing rules, manual review/refunds, audit logs and health checks.

Each feature must be implemented and tested before being marked complete.
