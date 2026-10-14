#!/usr/bin/env bash
set -euo pipefail

: "${DB_HOST:?DB_HOST is required}"
: "${DB_PORT:?DB_PORT is required}"
: "${DB_DATABASE:?DB_DATABASE is required}"
: "${DB_USERNAME:?DB_USERNAME is required}"
: "${DUITKU_MERCHANT_CODE:?DUITKU_MERCHANT_CODE is required}"
: "${DUITKU_API_KEY:?DUITKU_API_KEY is required}"

order_id="0123456789abcdef0123456789"
merchant="${DUITKU_MERCHANT_CODE}"
amount="2500"
signature="$(php -r 'echo hash_hmac("sha256", getenv("DUITKU_MERCHANT_CODE") . "2500" . "0123456789abcdef0123456789", getenv("DUITKU_API_KEY"));')"

# This script must run against a PHP server started with:
# APP_ENV=staging CHECKOUT_PAYMENTS_ENABLED=true SUPPLIER_ORDERS_ENABLED=false DUITKU_ENV=sandbox
if [ "${APP_ENV:-}" != "staging" ] || [ "${CHECKOUT_PAYMENTS_ENABLED:-}" != "true" ] \
  || [ "${SUPPLIER_ORDERS_ENABLED:-}" != "false" ] || [ "${DUITKU_ENV:-}" != "sandbox" ]; then
  echo "Sandbox-only integration requires staging + Duitku sandbox + payments=true + supplier orders=false." >&2
  exit 1
fi

response_code="$(curl --silent --output /tmp/pluq-sandbox-callback.json --write-out '%{http_code}' \
  --data-urlencode "merchantCode=${merchant}" \
  --data-urlencode "amount=${amount}" \
  --data-urlencode "merchantOrderId=${order_id}" \
  --data-urlencode "resultCode=00" \
  --data-urlencode "reference=SANDBOX-ONLY-INTEGRATION" \
  --data-urlencode "signature=${signature}" \
  http://127.0.0.1:8080/duitku-callback.php)"
if [ "${response_code}" != "200" ]; then
  cat /tmp/pluq-sandbox-callback.json >&2
  echo "Expected sandbox callback HTTP 200, got ${response_code}" >&2
  exit 1
fi

php <<'PHP'
<?php
declare(strict_types=1);

$pdo = new PDO(
    sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
        getenv('DB_HOST'),
        getenv('DB_PORT'),
        getenv('DB_DATABASE')
    ),
    getenv('DB_USERNAME'),
    getenv('DB_PASSWORD'),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$orderId = '0123456789abcdef0123456789';
$q = $pdo->prepare('SELECT status FROM payment_transactions WHERE merchant_order_id = ?');
$q->execute([$orderId]);
if ($q->fetchColumn() !== 'PAID') {
    fwrite(STDERR, "Sandbox callback must record payment as PAID.\\n");
    exit(1);
}

$q = $pdo->prepare('SELECT payment_status, order_status, last_error FROM orders WHERE public_id = ?');
$q->execute([$orderId]);
$row = $q->fetch(PDO::FETCH_ASSOC);
if (
    !$row
    || $row['payment_status'] !== 'PAID'
    || $row['order_status'] !== 'MANUAL_REVIEW'
    || !str_contains((string)$row['last_error'], 'supplier fulfillment intentionally disabled')
) {
    fwrite(STDERR, "Sandbox callback must mark the order for manual review without fulfillment.\\n");
    exit(1);
}

$q = $pdo->prepare(
    "SELECT COUNT(*) FROM job_queue
     WHERE job_type = 'FULFILL_ORDER'
       AND JSON_UNQUOTE(JSON_EXTRACT(payload, '$.order_id')) = (
           SELECT CAST(id AS CHAR) FROM orders WHERE public_id = ?
       )"
);
$q->execute([$orderId]);
if ((int)$q->fetchColumn() !== 0) {
    fwrite(STDERR, "Sandbox callback must not enqueue fulfillment for this order.\\n");
    exit(1);
}

echo "Duitku sandbox-only callback did not enqueue supplier fulfillment.\\n";
PHP
