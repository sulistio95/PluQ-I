# Supplier fulfillment rollout

Supplier execution is disabled by default. Do not set `SUPPLIER_ORDERS_ENABLED=true` until the service has been reviewed and tested against provider sandbox APIs. The current queue worker must be connected to the fulfillment handler and tested before scheduled execution.

Payment success callbacks enqueue a `FULFILL_ORDER` job. Live supplier credentials must remain private and must not be committed to the repository.
