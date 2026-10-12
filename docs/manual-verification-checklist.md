# PluQ-I manual verification checklist

Use this checklist in a disposable local/staging database. Do not use real customer orders or enable live supplier execution during these checks.

## 1. Configuration and safety gates

- [ ] Confirm PHP 8.2+, required extensions, database migrations, and environment configuration.
- [ ] Confirm Duitku environment is `sandbox`; callback and return URLs use HTTPS.
- [ ] Confirm `SUPPLIER_ORDERS_ENABLED=false`.
- [ ] Confirm API credentials are in the private environment configuration and are not committed to Git.

## 2. Checkout and payment-link creation

- [ ] Submit checkout with a valid product, game account fields, email, and idempotency key.
- [ ] Confirm the order and one pending Duitku payment row are created.
- [ ] Repeat the same checkout with the same idempotency key; confirm it reuses the existing payment URL rather than creating a second order.
- [ ] Simulate a Duitku sandbox timeout or invalid response. Confirm the order remains pending, no payment URL is presented, and the customer-facing response instructs them to contact admin.
- [ ] Run `php bin/inspect-review.php`; confirm the unresolved Duitku setup appears in the report.
- [ ] Before any retry, inspect the Duitku sandbox merchant dashboard for an existing request/reference. Do not create another payment request until the first outcome is understood.

## 3. Payment callback

- [ ] Send a callback with an invalid signature; confirm it is rejected and does not enqueue fulfillment.
- [ ] Send a valid successful sandbox callback with the correct order ID and amount; confirm payment becomes PAID and order becomes QUEUED.
- [ ] Send the same valid callback again, including concurrent duplicates; confirm only one fulfillment job is created.
- [ ] Send a callback with the wrong amount or unknown order; confirm it is rejected.
- [ ] In a disposable staging database, record a valid failure callback and then a valid success callback for the same order; confirm payment is recorded as PAID, the order moves to MANUAL_REVIEW, and no fulfillment job is added automatically.
- [ ] Send a failure callback after a successful callback; confirm the already-recorded PAID state is not downgraded.

## 4. Supplier fulfillment safety

- [ ] Keep real supplier execution disabled while testing adapters.
- [ ] Verify success, pending, explicit failure, malformed response, timeout, and mismatched transaction-ID handling using fixtures or approved sandbox credentials.
- [ ] Confirm ambiguous outcomes enter MANUAL_REVIEW and are not automatically resubmitted or refunded.
- [ ] Confirm explicit supplier failure follows the documented refund-pending/manual-refund path.
- [ ] Confirm stale queue locks and retry exhaustion enter manual review instead of resending an uncertain top-up.
- [ ] Run `php bin/inspect-queue.php` and `php bin/inspect-review.php`; verify the reports do not mutate data.

## 5. Before deployment

- [ ] All GitHub Actions workflows pass on the exact commit intended for deployment.
- [ ] Test the full checkout/callback lifecycle in staging with Duitku sandbox credentials.
- [ ] Verify each provider's API fields, signatures, transaction status query, and game account mapping with provider-approved test cases.
- [ ] Confirm backup and restore procedure for the database.
- [ ] Confirm admin access controls, HTTPS, environment secrets, logging, and error handling.
- [ ] Enable supplier execution only after reconciliation procedures and provider sandbox outcomes are verified.
- [ ] Do not switch Duitku to live mode until separate live-payment validation and operational approval are complete.

## Test evidence

Record the commit SHA, date, environment, test case, result, and any provider reference (never API keys or signatures). A passing CI workflow does not prove external API credentials or live provider behavior are valid.
