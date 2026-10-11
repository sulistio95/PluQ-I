# PluQ-I

Shared-hosting edition of PluQ for PHP 8.2+ and MySQL/MariaDB. This repository is separate from the primary PluQ repository intended for Cloudbaik.

## Current implementation status

- Foundation: PHP bootstrap, environment configuration, PDO connection, MySQL schema and operational tables.
- Public catalog: read-only game, banner and product endpoints plus a responsive dark storefront with game search and a 3-second banner rotation.
- Product filtering: hides names/codes containing "joki" or "vilog"; same-named offers prefer an available offer and then the lowest price.
- Pricing utilities: profit is read from active database pricing rules; invoice admin fee is Rp1,000 for selling prices up to Rp100,000 and Rp2,000 above that.
- Provider adapters: HTTPS JSON transport, Digiflazz balance/price-list helpers, and VIPayment profile/game-service helpers are committed. They have not been tested against live credentials.
- CI: PHP lint, JavaScript syntax check and pricing fee boundary tests are configured. Do not assume the workflow passed until its run is inspected.

**Not production-ready:** checkout, Duitku payment creation/callback verification, supplier order execution, retry/fulfillment, admin authentication and automated catalog synchronization are not complete. Do not enable real payments yet.

## Target architecture

- PHP 8.2+; prefer a currently supported PHP version if offered by the host.
- MySQL/MariaDB through PDO.
- No Node.js, Redis, BullMQ or permanently running worker.
- Database-backed jobs processed in bounded batches through cron if available.
- If cron is unavailable, a protected scheduler endpoint or external scheduler may be used; never expose an unauthenticated job runner.
- Payment callbacks must be authenticated and idempotent before fulfillment.
- Keep API credentials in environment settings or a private .env file, never in Git.

## Requirements

PHP extensions: pdo_mysql, curl, openssl, json, mbstring, fileinfo. MySQL/MariaDB with InnoDB and HTTPS are required.

## Initial setup

1. Create a dedicated MySQL/MariaDB database and database user.
2. Copy .env.example to .env outside the public document root where possible.
3. Set database credentials, APP_KEY, and a separate random SCHEDULER_TOKEN.
4. Configure Digiflazz credentials. VIPayment uses the VIP-Reseller API base URL by default; verify the API ID/key belong to that provider before enabling calls.
5. Import database/schema.mysql.sql into an empty database.
6. Then import database/migrations/002_catalog_and_operations.sql.
7. Point the document root at public/ if supported. Otherwise configure web-server rules to deny direct access to app/, config/, database/ and storage files.
8. Do not enable real transactions until provider and payment integrations have been implemented and tested.

## Scheduled work

Example only; adapt the PHP path and interval to the hosting control panel:

    * * * * * /usr/bin/php /absolute/path/PluQ-I/bin/worker.php

Cron availability depends on the selected hosting plan. The current worker remains a fail-closed scaffold and does not execute supplier jobs.

## Feature parity target

Dynamic game input forms, username/account validation where supported, Digiflazz/VIPayment catalog synchronization, lowest-price selection, Duitku payment creation/callbacks, idempotent order queue, bounded retries, separate payment/order statuses, provider balance reserves, admin panel, icon management, pricing rules, manual review/refunds, audit logs and health checks.

Each feature must be implemented and tested before being marked complete.
