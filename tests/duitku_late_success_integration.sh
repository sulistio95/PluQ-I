#!/usr/bin/env bash
set -euo pipefail

: "${DB_HOST:?DB_HOST is required}"
: "${DB_PORT:?DB_PORT is required}"
: "${DB_DATABASE:?DB_DATABASE is required}"
: "${DB_USERNAME:?DB_USERNAME is required}"
: "${DB_PASSWORD:?DB_PASSWORD is required}"
: "${DUITKU_MERCHANT_CODE:?DUITKU_MERCHANT_CODE is required}"
: "${DUITKU_API_KEY:?DUITKU_API_KEY is required}"

order_id="fedcba9876543210fedcba9876"
amount="2500"
signature="$(php -r 'echo hash_hmac("sha256", getenv("DUITKU_MERCHANT_CODE") . "2500" . "fedcba9876543210fedcba9876", getenv("DUITKU_API_KEY"));')"

mysql -h "$DB_HOST" -P "$DB_PORT" -u"$DB_USERNAME" -p"$DB_PASSWORD" "$DB_DATABASE" <<'SQL'
INSERT INTO orders (public_id, game_id, product_id, provider_id, customer_game_data, base_price, selling_price, admin_fee, payment_status, order_status)
SELECT 'fedcba9876543210fedcba9876', g.id, p.id, p.provider_id, JSON_OBJECT('user_id','789'), 1000, 1500, 1000, 'PENDING', 'PENDING'
FROM games g JOIN products p ON p.game_id = g.id
WHERE g.slug = 'integration-game' AND p.provider_product_code = 'integration-sku';
INSERT INTO payment_transactions (order_id, gateway, merchant_order_id, amount, status)
SELECT id, 'DUITKU', public_id, 2500, 'PENDING'
FROM orders WHERE public_id = 'fedcba9876543210fedcba9876';
SQL

post_callback() {
  local result_code="$1"
  curl --fail --silent --show-error \
    --data-urlencode "merchantCode=${DUITKU_MERCHANT_CODE}" \
    --data-urlencode "amount=${amount}" \
    --data-urlencode "merchantOrderId=${order_id}" \
    --data-urlencode "resultCode=${result_code}" \
    --data-urlencode "reference=LATE-SUCCESS-REF" \
    --data-urlencode "signature=${signature}" \
    http://127.0.0.1:8080/duitku-callback.php
}

failed_response="$(post_callback 01)"
success_response="$(post_callback 00)"
if [[ "$success_response" != *'"status":"manual_review"'* ]]; then
  echo "Expected late success callback to require manual review; got: $success_response" >&2
  exit 1
fi

php -r '
$pdo = new PDO(
    sprintf("mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4", getenv("DB_HOST"), getenv("DB_PORT"), getenv("DB_DATABASE")),
    getenv("DB_USERNAME"), getenv("DB_PASSWORD"),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
$id = "fedcba9876543210fedcba9876";
$q = $pdo->prepare("SELECT status FROM payment_transactions WHERE merchant_order_id = ?");
$q->execute([$id]);
if ($q->fetchColumn() !== "PAID") { fwrite(STDERR, "Late successful callback must record payment as PAID.\\n"); exit(1); }
$q = $pdo->prepare("SELECT payment_status, order_status, last_error FROM orders WHERE public_id = ?");
$q->execute([$id]);
$order = $q->fetch(PDO::FETCH_ASSOC);
if (!$order || $order["payment_status"] !== "PAID" || $order["order_status"] !== "MANUAL_REVIEW"
    || strpos((string)$order["last_error"], "Late successful Duitku callback") === false) {
    fwrite(STDERR, "Late successful callback must route order to manual review.\\n"); exit(1);
}
$q = $pdo->query("SELECT COUNT(*) FROM job_queue WHERE job_type = " . $pdo->quote("FULFILL_ORDER"));
if ((int)$q->fetchColumn() !== 1) { fwrite(STDERR, "Late success must not enqueue another fulfillment job.\\n"); exit(1); }
echo "Late Duitku success reconciliation integration test passed.\\n";
'
