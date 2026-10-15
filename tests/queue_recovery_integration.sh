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
INSERT INTO provider_budget_reservations (order_id,provider_id,amount,status)
SELECT o.id,o.provider_id,o.base_price,'ACTIVE'
FROM orders o WHERE o.public_id='1234567890abcdef1234567890';
INSERT INTO job_queue (job_type,payload,status,attempts,available_at,locked_at,lock_token,last_error)
SELECT 'FULFILL_ORDER',JSON_OBJECT('order_id',o.id),'PROCESSING',1,NOW(),DATE_SUB(NOW(),INTERVAL 11 MINUTE),REPEAT('a',32),NULL
FROM orders o WHERE o.public_id='1234567890abcdef1234567890';
-- Recover durable supplier success only for an order still in the automatic flow.
INSERT INTO orders
  (public_id,game_id,product_id,provider_id,customer_game_data,base_price,selling_price,admin_fee,payment_status,order_status,paid_at)
SELECT '2234567890abcdef1234567890', g.id, p.id, p.provider_id, JSON_OBJECT('user_id','457'), 1000, 1500, 1000, 'PAID', 'PROCESSING', NOW()
FROM games g JOIN products p ON p.game_id=g.id
WHERE g.slug='integration-game' AND p.provider_product_code='integration-sku';
INSERT INTO supplier_transactions (order_id,provider_id,reference_id,status,attempts)
SELECT o.id,o.provider_id,CONCAT('PQI-',o.public_id),'SUCCESS',1
FROM orders o WHERE o.public_id='2234567890abcdef1234567890';
INSERT INTO provider_budget_reservations (order_id,provider_id,amount,status)
SELECT o.id,o.provider_id,o.base_price,'ACTIVE'
FROM orders o WHERE o.public_id='2234567890abcdef1234567890';
INSERT INTO job_queue (job_type,payload,status,attempts,available_at,locked_at,lock_token,last_error)
SELECT 'FULFILL_ORDER',JSON_OBJECT('order_id',o.id),'PROCESSING',1,NOW(),DATE_SUB(NOW(),INTERVAL 11 MINUTE),REPEAT('b',32),NULL
FROM orders o WHERE o.public_id='2234567890abcdef1234567890';

-- Supplier success must not clear MANUAL_REVIEW caused by a conflicting payment callback.
INSERT INTO orders
  (public_id,game_id,product_id,provider_id,customer_game_data,base_price,selling_price,admin_fee,payment_status,order_status,paid_at,last_error)
SELECT '3234567890abcdef1234567890', g.id, p.id, p.provider_id, JSON_OBJECT('user_id','458'), 1000, 1500, 1000, 'PAID', 'MANUAL_REVIEW', NOW(), 'Conflicting terminal Duitku callback'
FROM games g JOIN products p ON p.game_id=g.id
WHERE g.slug='integration-game' AND p.provider_product_code='integration-sku';
INSERT INTO supplier_transactions (order_id,provider_id,reference_id,status,attempts)
SELECT o.id,o.provider_id,CONCAT('PQI-',o.public_id),'SUCCESS',1
FROM orders o WHERE o.public_id='3234567890abcdef1234567890';
INSERT INTO provider_budget_reservations (order_id,provider_id,amount,status)
SELECT o.id,o.provider_id,o.base_price,'ACTIVE'
FROM orders o WHERE o.public_id='3234567890abcdef1234567890';
INSERT INTO job_queue (job_type,payload,status,attempts,available_at,locked_at,lock_token,last_error)
SELECT 'FULFILL_ORDER',JSON_OBJECT('order_id',o.id),'PROCESSING',1,NOW(),DATE_SUB(NOW(),INTERVAL 11 MINUTE),REPEAT('c',32),NULL
FROM orders o WHERE o.public_id='3234567890abcdef1234567890';
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
$q = $pdo->prepare("SELECT status FROM provider_budget_reservations WHERE order_id = (SELECT id FROM orders WHERE public_id = ?)");
$q->execute([$orderId]);
if ($q->fetchColumn() !== "REVIEW") { fwrite(STDERR, "Stale provider budget reservation was not retained in REVIEW.\\n"); exit(1); }
$q = $pdo->prepare("SELECT status FROM job_queue WHERE JSON_UNQUOTE(JSON_EXTRACT(payload, '$.order_id')) = (SELECT id FROM orders WHERE public_id = ?)");
$q->execute([$orderId]);
if ($q->fetchColumn() !== "FAILED") { fwrite(STDERR, "Ambiguous stale queue job was not failed safely.\\n"); exit(1); }

$successId = "2234567890abcdef1234567890";
$q = $pdo->prepare("SELECT order_status FROM orders WHERE public_id = ?");
$q->execute([$successId]);
if ($q->fetchColumn() !== "SUCCESS") { fwrite(STDERR, "Durable supplier success was not recovered for an in-flight order.\\n"); exit(1); }
$q = $pdo->prepare("SELECT status FROM provider_budget_reservations WHERE order_id = (SELECT id FROM orders WHERE public_id = ?)");
$q->execute([$successId]);
if ($q->fetchColumn() !== "SETTLED") { fwrite(STDERR, "Recovered supplier success did not settle provider budget.\\n"); exit(1); }
$q = $pdo->prepare("SELECT status FROM job_queue WHERE JSON_UNQUOTE(JSON_EXTRACT(payload, '$.order_id')) = (SELECT id FROM orders WHERE public_id = ?)");
$q->execute([$successId]);
if ($q->fetchColumn() !== "DONE") { fwrite(STDERR, "Recovered supplier success job was not marked DONE.\\n"); exit(1); }

$reviewId = "3234567890abcdef1234567890";
$q = $pdo->prepare("SELECT order_status, last_error FROM orders WHERE public_id = ?");
$q->execute([$reviewId]);
$row = $q->fetch(PDO::FETCH_ASSOC);
if (!$row || $row["order_status"] !== "MANUAL_REVIEW"
    || !str_contains((string)$row["last_error"], "Conflicting terminal Duitku callback")) {
    fwrite(STDERR, "Stale queue recovery must not clear payment-conflict manual review.\\n"); exit(1);
}
$q = $pdo->prepare("SELECT status FROM provider_budget_reservations WHERE order_id = (SELECT id FROM orders WHERE public_id = ?)");
$q->execute([$reviewId]);
if ($q->fetchColumn() !== "REVIEW") { fwrite(STDERR, "Manual-review order capacity was not retained in REVIEW.\\n"); exit(1); }
$q = $pdo->prepare("SELECT status FROM job_queue WHERE JSON_UNQUOTE(JSON_EXTRACT(payload, '$.order_id')) = (SELECT id FROM orders WHERE public_id = ?)");
$q->execute([$reviewId]);
if ($q->fetchColumn() !== "FAILED") { fwrite(STDERR, "Manual-review stale queue job should remain failed for reconciliation.\\n"); exit(1); }
echo "Stale queue recovery integration test passed.\\n";
'
