#!/usr/bin/env bash
set -euo pipefail

: "${DB_HOST:?DB_HOST is required}"
: "${DB_PORT:?DB_PORT is required}"
: "${DB_DATABASE:?DB_DATABASE is required}"
: "${DB_USERNAME:?DB_USERNAME is required}"
: "${DUITKU_MERCHANT_CODE:?DUITKU_MERCHANT_CODE is required}"
: "${DUITKU_API_KEY:?DUITKU_API_KEY is required}"

order_id="abcdef0123456789abcdef0123"
merchant="${DUITKU_MERCHANT_CODE}"
amount="2500"
signature="$(php -r 'echo hash_hmac("sha256", getenv("DUITKU_MERCHANT_CODE") . "2500" . "abcdef0123456789abcdef0123", getenv("DUITKU_API_KEY"));')"

# The callback route must only enqueue once, even when two valid callbacks race.
post_callback() {
  curl --fail --silent --show-error \
    --data-urlencode "merchantCode=${merchant}" \
    --data-urlencode "amount=${amount}" \
    --data-urlencode "merchantOrderId=${order_id}" \
    --data-urlencode "resultCode=00" \
    --data-urlencode "reference=INTEGRATION-REF" \
    --data-urlencode "signature=${signature}" \
    http://127.0.0.1:8080/duitku-callback.php
}

post_callback > /tmp/pluq-callback-1.json &
pid1=$!
post_callback > /tmp/pluq-callback-2.json &
pid2=$!
wait "$pid1"
wait "$pid2"

php -r '
$pdo = new PDO(
    sprintf("mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4", getenv("DB_HOST"), getenv("DB_PORT"), getenv("DB_DATABASE")),
    getenv("DB_USERNAME"),
    getenv("DB_PASSWORD"),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
$orderId = "abcdef0123456789abcdef0123";
$q = $pdo->prepare("SELECT status FROM payment_transactions WHERE merchant_order_id = ?");
$q->execute([$orderId]);
if ($q->fetchColumn() !== "PAID") { fwrite(STDERR, "Expected payment status PAID.\\n"); exit(1); }
$q = $pdo->prepare("SELECT order_status FROM orders WHERE public_id = ?");
$q->execute([$orderId]);
if ($q->fetchColumn() !== "QUEUED") { fwrite(STDERR, "Expected order status QUEUED.\\n"); exit(1); }
$q = $pdo->query("SELECT COUNT(*) FROM job_queue WHERE job_type = " . $pdo->quote("FULFILL_ORDER"));
if ((int)$q->fetchColumn() !== 1) { fwrite(STDERR, "Expected exactly one fulfillment job after duplicate callbacks.\\n"); exit(1); }
echo "Duitku MySQL callback idempotency integration test passed.\\n";
'
