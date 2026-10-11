#!/usr/bin/env bash
set -euo pipefail

# Isolate this test from the callback test's newly queued fulfillment job.
mysql -h "$DB_HOST" -P "$DB_PORT" -u"$DB_USERNAME" -p"$DB_PASSWORD" "$DB_DATABASE" <<'SQL'
UPDATE job_queue SET status='DONE', locked_at=NULL, lock_token=NULL WHERE status IN ('PENDING','RETRY');
INSERT INTO orders
  (public_id,game_id,product_id,provider_id,customer_game_data,base_price,selling_price,admin_fee,payment_status,order_status,paid_at)
SELECT '1234567890abcdef1234567890', g.id, p.id, p.provider_id, JSON_OBJECT('user_id','456'), 1000, 1500, 1000, 'PAID', 'PROCESSING', NOW()
FROM games g JOIN products p ON p.game_id=g.id
WHERE g.slug='integration-game' AND p.provider_product_code='integration-sku';
INSERT INTO supplier_transactions (order_id,provider_id,reference_id,status,attempts)
SELECT o.id,o.provider_id,CONCAT('PQI-',o.public_id),'PROCESSING',1
FROM orders o WHERE o.public_id='1234567890abcdef1234567890';
INSERT INTO job_queue (job_type,payload,status,attempts,available_at,locked_at,lock_token,last_error)
SELECT 'FULFILL_ORDER',JSON_OBJECT('order_id',o.id),'PROCESSING',1,NOW(),DATE_SUB(NOW(),INTERVAL 11 MINUTE),REPEAT('a',32),NULL
FROM orders o WHERE o.public_id='1234567890abcdef1234567890';
SQL

SUPPLIER_ORDERS_ENABLED=true php bin/worker-queue.php

php -r '
$pdo = new PDO(
    sprintf("mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4", getenv("DB_HOST"), getenv("DB_PORT"), getenv("DB_DATABASE")),
    getenv("DB_USERNAME"),
    getenv("DB_PASSWORD"),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
$orderId = "1234567890abcdef1234567890";
$q = $pdo->prepare("SELECT order_status FROM orders WHERE public_id = ?");
$q->execute([$orderId]);
if ($q->fetchColumn() !== "MANUAL_REVIEW") { fwrite(STDERR, "Stale order was not moved to MANUAL_REVIEW.\\n"); exit(1); }
$q = $pdo->prepare("SELECT status FROM supplier_transactions WHERE reference_id = ?");
$q->execute(["PQI-" . $orderId]);
if ($q->fetchColumn() !== "REVIEW") { fwrite(STDERR, "Stale supplier transaction was not moved to REVIEW.\\n"); exit(1); }
$q = $pdo->prepare("SELECT status FROM job_queue WHERE job_type = ? AND JSON_UNQUOTE(JSON_EXTRACT(payload, "$.order_id")) = (SELECT CAST(id AS CHAR) FROM orders WHERE public_id = ?)");
$q->execute(["FULFILL_ORDER", $orderId]);
if ($q->fetchColumn() !== "FAILED") { fwrite(STDERR, "Stale queue job was not failed safely.\\n"); exit(1); }
echo "Stale queue recovery integration test passed.\\n";
'
