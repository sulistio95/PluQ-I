# PluQ-I

Shared-hosting edition of PluQ, designed for PHP 8.2+ and MySQL/MariaDB. This repository is separate from the primary PluQ repository for Cloudbaik.

## Status

**Stage 1: shared-hosting foundation.** PHP bootstrap, environment configuration, PDO connection, initial database schema, catalog/operations schema expansion, and a fail-closed scheduled-job entry point have been added. Full catalog, provider, Duitku, admin, refund, and order-processing parity is not complete yet.

## Target architecture

- PHP 8.2+; prefer a currently supported PHP version where available.
- MySQL/MariaDB via PDO.
- No Node.js, Redis, BullMQ, or permanently running worker.
- Database-backed queue processed in bounded batches through cron where available.
- If cron is unavailable, a protected scheduler endpoint or external scheduler may be used; never expose an unauthenticated job runner.
- Payment callbacks must be verified and idempotent before order fulfillment.
- Keep API credentials in server environment settings or a private .env file, never in Git.

## Requirements

PHP extensions: pdo_mysql, curl, openssl, json, mbstring, fileinfo. MySQL/MariaDB with InnoDB and HTTPS are required.

## Initial setup

1. Create a dedicated MySQL/MariaDB database and database user.
2. Copy .env.example to .env in a location that cannot be served by the web server.
3. Set database credentials, APP_KEY, and a separate random SCHEDULER_TOKEN.
4. Import database/schema.mysql.sql into the empty database.
5. Then import database/migrations/002_catalog_and_operations.sql.
6. Point the document root at public/ if supported. Otherwise configure web-server rules to deny direct access to app/, config/, database/, and storage/.
7. Do not enable real transactions until all provider and payment integrations have been implemented and tested.

## Scheduled work

Example only; adapt the PHP path and interval to the hosting control panel:

    * * * * * /usr/bin/php /absolute/path/PluQ-I/bin/worker.php

Cron is recommended but not yet confirmed on the target hosting plan. The current worker is intentionally fail-closed and does not execute supplier jobs. It is a scheduler scaffold, not a functioning fulfillment worker.

## Feature parity target

Catalog/search, banners, dynamic game input forms, account validation where supported, Digiflazz/VIPayment catalog sync, price selection, Duitku payment creation/callbacks, idempotent order queue, bounded retries, separate payment/order statuses, provider balance reserves, admin panel, game icons, pricing rules, manual review, refunds, audit logs, and health checks.

The foundation schema is not the complete production schema. Each feature must be implemented and tested before being marked complete. The PHP lint workflow is committed, but no successful GitHub Actions run has been observed yet.
