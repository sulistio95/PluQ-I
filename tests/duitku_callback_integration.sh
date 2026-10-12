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


# Invalid signatures and signed callbacks with the wrong amount must not mutate state.
invalid_signature_code="$(curl --silent --output /tmp/pluq-callback-invalid-signature.json --write-out '%{http_code}' \
  --data-urlencode "merchantCode=${merchant}" \
  --data-urlencode "amount=${amount}" \
  --data-urlencode "merchantOrderId=${order_id}" \
  --data-urlencode "resultCode=00" \
  --data-urlencode "reference=INTEGRATION-INVALID-SIGNATURE" \
  --data-urlencode "signature=not-a-valid-signature" \
  http://127.0.0.1:8080/duitku-callback.php)"
if [ "${invalid_signature_code}" != "400" ]; then
  echo "Expected invalid callback signature to return HTTP 400, got ${invalid_signature_code}" >&2
  exit 1
fi

wrong_amount="2501"
wrong_amount_signature="$(php -r 'echo hash_hmac("sha256", getenv("DUITKU_MERCHANT_CODE") . "2501" . "abcdef0123456789abcdef0123", getenv("DUITKU_API_KEY"));')"
wrong_amount_code="$(curl --silent --output /tmp/pluq-callback-wrong-amount.json --write-out '%{http_code}' \
  --data-urlencode "merchantCode=${merchant}" \
  --data-urlencode "amount=${wrong_amount}" \
  --data-urlencode "merchantOrderId=${order_id}" \
  --data-urlencode "resultCode=00" \
  --data-urlencode "reference=INTEGRATION-WRONG-AMOUNT" \
  --data-urlencode "signature=${wrong_amount_signature}" \
  http://127.0.0.1:8080/duitku-callback.php)"
if [ "${wrong_amount_code}" != "400" ]; then
  echo "Expected signed callback with wrong amount to return HTTP 400, got ${wrong_amount_code}" >&2
  exit 1
fi

php -r '
$pdo = new PDO(
    sprintf("mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4", getenv("DB_HOST"), getenv("DB_PORT"), getenv("DB_DATABASE")),
    getenv("DB_USERNAME"), getenv("DB_PASSWORD"),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
$orderId = "abcdef0123456789abcdef0123";
$q = $pdo->prepare("SELECT status FROM payment_transactions WHERE merchant_order_id = ?");
$q->execute([$orderId]);
if ($q->fetchColumn() !== "PENDING") { fwrite(STDERR, "Invalid callback must not change payment state.\\n"); exit(1); }
$q = $pdo->prepare("SELECT payment_status, order_status FROM orders WHERE public_id = ?");
$q->execute([$orderId]);
$row = $q->fetch(PDO::FETCH_ASSOC);
if (!$row || $row["payment_status"] !== "PENDING" || $row["order_status"] !== "PENDING") {
    fwrite(STDERR, "Invalid callback must not change order state.\\n"); exit(1);
}
$q = $pdo->query("SELECT COUNT(*) FROM job_queue WHERE job_type = " . $pdo->quote("FULFILL_ORDER"));
if ((int)$q->fetchColumn() !== 0) { fwrite(STDERR, "Invalid callback must not enqueue fulfillment.\\n"); exit(1); }
echo "Duitku invalid-signature and wrong-amount integration tests passed.\\n";
'


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


# A definitive failure must close both payment and still-pending order states.
failed_order_id="fedcba9876543210fedcba9876"
failed_signature="$(php -r 'echo hash_hmac("sha256", getenv("DUITKU_MERCHANT_CODE") . "2500" . "fedcba9876543210fedcba9876", getenv("DUITKU_API_KEY"));')"
curl --fail --silent --show-error \
  --data-urlencode "merchantCode=${merchant}" \
  --data-urlencode "amount=${amount}" \
  --data-urlencode "merchantOrderId=${failed_order_id}" \
  --data-urlencode "resultCode=01" \
  --data-urlencode "reference=INTEGRATION-FAILED-REF" \
  --data-urlencode "signature=${failed_signature}" \
  http://127.0.0.1:8080/duitku-callback.php >/tmp/pluq-callback-failed.json

php -r '
$pdo = new PDO(
    sprintf("mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4", getenv("DB_HOST"), getenv("DB_PORT"), getenv("DB_DATABASE")),
    getenv("DB_USERNAME"), getenv("DB_PASSWORD"),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
$orderId = "fedcba9876543210fedcba9876";
$q = $pdo->prepare("SELECT status FROM payment_transactions WHERE merchant_order_id = ?");
$q->execute([$orderId]);
if ($q->fetchColumn() !== "FAILED") { fwrite(STDERR, "Expected failed payment status FAILED.\\n"); exit(1); }
$q = $pdo->prepare("SELECT payment_status, order_status FROM orders WHERE public_id = ?");
$q->execute([$orderId]);
$row = $q->fetch(PDO::FETCH_ASSOC);
if (!$row || $row["payment_status"] !== "FAILED" || $row["order_status"] !== "FAILED") {
    fwrite(STDERR, "Definitive failed callback must close pending payment and order.\\n"); exit(1);
}
$q = $pdo->query("SELECT COUNT(*) FROM job_queue WHERE job_type = " . $pdo->quote("FULFILL_ORDER"));
if ((int)$q->fetchColumn() !== 1) { fwrite(STDERR, "Failed payment must not enqueue fulfillment.\\n"); exit(1); }
echo "Duitku failed-payment state integration test passed.\\n";
'
