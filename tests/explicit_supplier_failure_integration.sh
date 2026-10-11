#!/usr/bin/env bash
set -euo pipefail

# A definitive supplier rejection means fulfillment failed, not that the buyer payment failed.
mysql -h "$DB_HOST" -P "$DB_PORT" -u"$DB_USERNAME" -p"$DB_PASSWORD" "$DB_DATABASE" <<'SQL'
INSERT INTO orders
  (public_id,game_id,product_id,provider_id,customer_game_data,base_price,selling_price,admin_fee,payment_status,order_status,paid_at)
SELECT 'failuretest0123456789012345', g.id, p.id, p.provider_id, JSON_OBJECT('user_id','987'), 1000, 1500, 1000, 'PAID', 'PROCESSING', NOW()
FROM games g JOIN products p ON p.game_id=g.id
WHERE g.slug='integration-game' AND p.provider_product_code='integration-sku';
INSERT INTO payment_transactions (order_id,gateway,merchant_order_id,amount,status)
SELECT id,'DUITKU',public_id,2500,'PAID'
FROM orders WHERE public_id='failuretest0123456789012345';
SQL

php <<'PHP'
<?php
require __DIR__ . '/../app/bootstrap.php';
require_once PLUQI_ROOT . '/config/database.php';
require_once PLUQI_ROOT . '/app/services/fulfillment.php';

$pdo = database();
$q = $pdo->prepare("SELECT id FROM orders WHERE public_id = ?");
$q->execute(['failuretest0123456789012345']);
$orderId = (int)$q->fetchColumn();
if ($orderId < 1) { fwrite(STDERR, "Failure fixture was not created.\n"); exit(1); }

// Repeat the transition to verify refund-task idempotency.
fulfillment_record_explicit_failure($pdo, $orderId);
fulfillment_record_explicit_failure($pdo, $orderId);

$q = $pdo->prepare("SELECT payment_status,order_status FROM orders WHERE id = ?");
$q->execute([$orderId]);
$order = $q->fetch(PDO::FETCH_ASSOC);
if (!$order || $order['payment_status'] !== 'PAID' || $order['order_status'] !== 'FAILED') {
    fwrite(STDERR, "Supplier failure must preserve PAID and set order FAILED.\n"); exit(1);
}
$q = $pdo->prepare("SELECT status,amount FROM refunds WHERE order_id = ?");
$q->execute([$orderId]);
$refunds = $q->fetchAll(PDO::FETCH_ASSOC);
if (count($refunds) !== 1 || $refunds[0]['status'] !== 'PENDING' || (float)$refunds[0]['amount'] !== 2500.0) {
    fwrite(STDERR, "Exactly one pending refund for the paid invoice total must be created.\n"); exit(1);
}
$q = $pdo->prepare("SELECT status FROM payment_transactions WHERE merchant_order_id = ?");
$q->execute(['failuretest0123456789012345']);
if ($q->fetchColumn() !== 'PAID') {
    fwrite(STDERR, "Supplier failure must not rewrite the gateway payment status.\n"); exit(1);
}
echo "Explicit supplier failure integration test passed.\n";
PHP
