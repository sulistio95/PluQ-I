#!/usr/bin/env bash
set -euo pipefail

# Simulate a prior supplier request that may have been accepted before a crash.
# The worker must not call the provider again or mark the payment as refundable.
mysql -h "$DB_HOST" -P "$DB_PORT" -u"$DB_USERNAME" -p"$DB_PASSWORD" "$DB_DATABASE" <<'SQL'
INSERT INTO orders
  (public_id,game_id,product_id,provider_id,customer_game_data,base_price,selling_price,admin_fee,payment_status,order_status,paid_at)
SELECT '9876543210abcdef9876543210', g.id, p.id, p.provider_id, JSON_OBJECT('user_id','789'), 1000, 1500, 1000, 'PAID', 'PROCESSING', NOW()
FROM games g JOIN products p ON p.game_id=g.id
WHERE g.slug='integration-game' AND p.provider_product_code='integration-sku';
INSERT INTO supplier_transactions (order_id,provider_id,reference_id,status,attempts)
SELECT o.id,o.provider_id,CONCAT('PQI-',o.public_id),'PROCESSING',1
FROM orders o WHERE o.public_id='9876543210abcdef9876543210';
-- Model a crashed/abandoned request, not an active request made seconds ago.
UPDATE supplier_transactions st
JOIN orders o ON o.id = st.order_id
SET st.updated_at = DATE_SUB(NOW(), INTERVAL 11 MINUTE)
WHERE o.public_id = '9876543210abcdef9876543210';
INSERT INTO job_queue (job_type,payload,status,attempts,available_at)
SELECT 'FULFILL_ORDER',JSON_OBJECT('order_id',o.id),'PENDING',0,NOW()
FROM orders o WHERE o.public_id='9876543210abcdef9876543210';
SQL

SUPPLIER_ORDERS_ENABLED=true php bin/worker-queue.php

php -r '
$pdo = new PDO(
    sprintf("mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4", getenv("DB_HOST"), getenv("DB_PORT"), getenv("DB_DATABASE")),
    getenv("DB_USERNAME"),
    getenv("DB_PASSWORD"),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
$orderId = "9876543210abcdef9876543210";
$q = $pdo->prepare("SELECT payment_status,order_status FROM orders WHERE public_id = ?");
$q->execute([$orderId]);
$order = $q->fetch(PDO::FETCH_ASSOC);
if (!$order || $order["payment_status"] !== "PAID" || $order["order_status"] !== "MANUAL_REVIEW") {
    fwrite(STDERR, "Ambiguous supplier order must remain paid and enter manual review.\\n"); exit(1);
}
$q = $pdo->prepare("SELECT status FROM supplier_transactions WHERE reference_id = ?");
$q->execute(["PQI-" . $orderId]);
if ($q->fetchColumn() !== "REVIEW") { fwrite(STDERR, "Supplier transaction must enter REVIEW.\\n"); exit(1); }
$q = $pdo->prepare("SELECT COUNT(*) FROM refunds r JOIN orders o ON o.id=r.order_id WHERE o.public_id = ?");
$q->execute([$orderId]);
if ((int)$q->fetchColumn() !== 0) { fwrite(STDERR, "Ambiguous supplier result must not create an automatic refund task.\\n"); exit(1); }
$q = $pdo->query("SELECT status FROM job_queue WHERE id = (SELECT MAX(id) FROM job_queue)");
if ($q->fetchColumn() !== "FAILED") { fwrite(STDERR, "Manual-review queue job should be closed without retry.\\n"); exit(1); }
echo "Ambiguous supplier outcome integration test passed.\\n";
'
