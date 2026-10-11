# Provider API validation notes

Reviewed against the public provider documentation on 2026-10-11. This is a documentation review only; no live API calls were made.

## Duitku payment callback

Official documentation:
- Indonesian API reference: https://docs.duitku.com/api/id/

The callback is an HTTP POST using `application/x-www-form-urlencoded`. Documented fields include `merchantCode`, `amount`, `merchantOrderId`, `resultCode`, `reference`, and `signature`. Callback result codes are `00` for success and `01` for failed. The signature must be verified before changing payment state. The customer-facing redirect is not authoritative for payment status.

Current code checks the signature, amount, merchant order ID format, and result code before updating the payment. It uses the verified callback to enqueue fulfillment. Still required: exercise duplicate callbacks and concurrent callback requests against a sandbox database and verify the exact signature with sandbox callback fixtures.

## VIPayment / VIP-Reseller game-feature

Official public documentation:
- https://vip-reseller.co.id/page/api/game-feature

Documented endpoint: `POST https://vip-reseller.co.id/api/game-feature`.

Order fields include `key`, `sign` (MD5 of API ID + API KEY), `type=order`, `service`, `data_no`, optional `data_zone`, and optional `post_additional_data`. The documented order response places a transaction ID (`trxid`) and status in an object under `data`; example initial status is `waiting`.

Status checks use the same endpoint with `type=status` and a `trxid`; the documentation describes a list under `data`. Status examples include `waiting`, `processing`, `success`, and `error`. The documentation also describes a `get-nickname` operation for checking a game username, but it needs a per-game code and target mapping.

Current code structure is broadly consistent with the documented order/status shapes, but the configured base URL, request signature, exact response handling, and error cases have not been verified against sandbox/test credentials.

## Digiflazz

Digiflazz request semantics and response reconciliation have not been confirmed in this review. In particular, do not assume that resending a transaction with the same `ref_id` is safe until the official API behavior has been verified.

## Release gate

- Keep `SUPPLIER_ORDERS_ENABLED=false`.
- Do not use production supplier credentials for integration tests.
- Verify product codes and account-field mappings for each game.
- Verify provider response statuses and ambiguous network timeout behavior.
- Verify queue recovery and manual reconciliation before scheduling the worker.
- Treat refund records as manual tasks; this code does not automatically issue a Duitku refund.
