# PluQ-I

Shared-hosting edition of PluQ for PHP 8.2+ and MySQL/MariaDB. This repository is separate from the primary PluQ repository intended for Cloudbaik.

## Current implementation status

- Foundation: PHP bootstrap, environment configuration, PDO connection, MySQL schema and operational tables.
- Public catalog: game, banner, product and dynamic game-input endpoints plus a responsive dark storefront with search and a 3-second banner rotation.
- Server-side price quotes validate the game, product, account fields, provider reserve and excluded services; the server recalculates selling price and admin fee.
- Checkout now has an idempotency key, persistent pending-order/payment records, and Duitku sandbox request support. Set `DUITKU_PAYMENT_METHOD`, credentials, and HTTPS callback/return URLs in the private `.env`.
- Duitku callback validates HMAC-SHA256, checks the order and exact amount, and only queues a fulfillment job once for a successful pending payment.
- Supplier adapters and balance sync are committed but have not been tested against live credentials.
- CI is configured for PHP lint, JavaScript syntax and pricing tests. Verify the actual workflow run before considering checks passed.

**Not production-ready:** real payments are deliberately blocked; sandbox payment flow has not been tested with merchant credentials. Supplier fulfillment worker, order retries/refunds, account username verification, admin authentication, rate limiting and automated catalog synchronization remain incomplete. Do not enable production payments.

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
7. Then import database/migrations/003_checkout_payment.sql.
8. Then import database/migrations/004_supplier_manual_review_status.sql. This migration is required on existing databases because supplier transactions may enter `REVIEW` when an outcome is ambiguous.
9. Configure Duitku sandbox credentials and set `DUITKU_PAYMENT_METHOD` to a method enabled for your sandbox merchant; set callback URL to `https://your-domain/duitku-callback.php` and return URL to `https://your-domain/order-status.php`.
10. Point the document root at public/ if supported. Otherwise configure web-server rules to deny direct access to app/, config/, database/ and storage files.
11. Do not enable real transactions until provider and payment integrations have been implemented and tested.

## Scheduled work

Example only; adapt the PHP path and interval to the hosting control panel:

    * * * * * /usr/bin/php /absolute/path/PluQ-I/bin/worker.php

Cron availability depends on the selected hosting plan. The current worker remains a fail-closed scaffold and does not execute supplier jobs.

## Feature parity target

Dynamic game input forms, username/account validation where supported, Digiflazz/VIPayment catalog synchronization, lowest-price selection, Duitku payment creation/callbacks, idempotent order queue, bounded retries, separate payment/order statuses, provider balance reserves, admin panel, icon management, pricing rules, manual review/refunds, audit logs and health checks.

Each feature must be implemented and tested before being marked complete.
