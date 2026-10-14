#!/usr/bin/env bash
set -euo pipefail

# A definitive supplier rejection means fulfillment failed, not that the buyer payment failed.
mysql -h "$DB_HOST" -P "$DB_PORT" -u"$DB_USERNAME" -p"$DB_PASSWORD" "$DB_DATABASE" <<'SQL'
INSERT INTO orders
  (public_id,game_id,product_id,provider_id,customer_game_data,base_price,selling_price,admin_fee,payment_status,order_status,paid_at)
SELECT 'failuretest012345678901234', g.id, p.id, p.provider_id, JSON_OBJECT('user_id','987'), 1000, 1500, 1000, 'PAID', 'PROCESSING', NOW()
FROM games g JOIN products p ON p.game_id=g.id
WHERE g.slug='integration-game' AND p.provider_product_code='integration-sku';
INSERT INTO payment_transactions (order_id,gateway,merchant_order_id,amount,status)
SELECT id,'DUITKU',public_id,2500,'PAID'
FROM orders WHERE public_id='failuretest012345678901234';
SQL

php <<'PHP'
<?php
if (!defined('STDERR')) define('STDERR', fopen('php://stderr', 'wb'));
require getcwd() . '/app/bootstrap.php';
require_once PLUQI_ROOT . '/config/database.php';
require_once PLUQI_ROOT . '/app/services/fulfillment.php';

$pdo = database();
$q = $pdo->prepare("SELECT id FROM orders WHERE public_id = ?");
$q->execute(['failuretest012345678901234']);
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
$q->execute(['failuretest012345678901234']);
if ($q->fetchColumn() !== 'PAID') {
    fwrite(STDERR, "Supplier failure must not rewrite the gateway payment status.\n"); exit(1);
}

// A failed refund must remain visible for manual recovery; rechecking supplier
// failure must not silently create another refund attempt.
$pdo->prepare("UPDATE refunds SET status='FAILED' WHERE order_id=?")->execute([$orderId]);
fulfillment_record_explicit_failure($pdo, $orderId);
$q = $pdo->prepare("SELECT status FROM refunds WHERE order_id=?");
$q->execute([$orderId]);
$refundsAfterFailure = $q->fetchAll(PDO::FETCH_COLUMN);
if (count($refundsAfterFailure) !== 1 || $refundsAfterFailure[0] !== 'FAILED') {
    fwrite(STDERR, "A failed refund must not be duplicated automatically.\n"); exit(1);
}
// Simulate a worker crash after supplier failure was persisted but before
// the refund row was inserted.
$pdo->prepare("INSERT INTO orders
    (public_id,game_id,product_id,provider_id,customer_game_data,base_price,selling_price,admin_fee,payment_status,order_status,paid_at)
    SELECT 'crashrefund012345678901234', g.id, p.id, p.provider_id, JSON_OBJECT('user_id','321'),
           1000, 1500, 1000, 'PAID', 'PROCESSING', NOW()
    FROM games g JOIN products p ON p.game_id=g.id
    WHERE g.slug='integration-game' AND p.provider_product_code='integration-sku'")->execute();
$q = $pdo->prepare("SELECT id,provider_id FROM orders WHERE public_id=?");
$q->execute(['crashrefund012345678901234']);
$recovery = $q->fetch(PDO::FETCH_ASSOC);
if (!$recovery) { fwrite(STDERR, "Crash-window fixture was not created.\\n"); exit(1); }
$pdo->prepare("INSERT INTO supplier_transactions (order_id,provider_id,reference_id,status,attempts)
    VALUES (?,?,?,'FAILED',1)")->execute([
        $recovery['id'], $recovery['provider_id'], 'PQI-crashrefund012345678901234'
    ]);
// Migration 011 requires a reservation before the worker can inspect a
// persisted supplier failure. Seed it so this fixture reaches that recovery path.
$pdo->prepare("INSERT INTO provider_budget_reservations (order_id,provider_id,amount,status)
    VALUES (?,?,?,'ACTIVE')")->execute([
        $recovery['id'], $recovery['provider_id'], 1000
    ]);
putenv('SUPPLIER_ORDERS_ENABLED=true');
$outcome = fulfillment_process($pdo, (int)$recovery['id']);
if ($outcome !== 'FAILED') {
    fwrite(STDERR, "Persisted supplier failure should remain FAILED during recovery.\\n"); exit(1);
}
$q = $pdo->prepare("SELECT payment_status,order_status FROM orders WHERE id=?");
$q->execute([$recovery['id']]);
$recoveredOrder = $q->fetch(PDO::FETCH_ASSOC);
$q = $pdo->prepare("SELECT status,amount FROM refunds WHERE order_id=?");
$q->execute([$recovery['id']]);
$recoveredRefunds = $q->fetchAll(PDO::FETCH_ASSOC);
if (!$recoveredOrder || $recoveredOrder['payment_status'] !== 'PAID'
    || $recoveredOrder['order_status'] !== 'FAILED'
    || count($recoveredRefunds) !== 1 || $recoveredRefunds[0]['status'] !== 'PENDING'
    || (float)$recoveredRefunds[0]['amount'] !== 2500.0) {
    fwrite(STDERR, "Recovery did not create exactly one pending refund after persisted supplier failure.\\n");
    exit(1);
}

// A stale explicit-failure signal must not overwrite an order already sent to manual review.
$pdo->prepare("UPDATE orders SET order_status='MANUAL_REVIEW' WHERE id=?")->execute([$recovery['id']]);
$blocked = false;
try {
    fulfillment_record_explicit_failure($pdo, (int)$recovery['id']);
} catch (DomainException $e) {
    $blocked = true;
}
$q = $pdo->prepare("SELECT order_status FROM orders WHERE id=?");
$q->execute([$recovery['id']]);
if (!$blocked || $q->fetchColumn() !== 'MANUAL_REVIEW') {
    fwrite(STDERR, "Explicit supplier failure must not overwrite MANUAL_REVIEW.\\n");
    exit(1);
}

echo "Explicit supplier failure and crash-window recovery integration test passed.\\n";
PHP
