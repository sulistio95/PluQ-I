<?php
declare(strict_types=1);

// This integration test exercises the duplicate-job guard against an isolated
// MySQL fixture. If a regression reaches the supplier client, its URL is loopback
// port 1, never a real supplier endpoint.
putenv('SUPPLIER_ORDERS_ENABLED=true');
putenv('DIGIFLAZZ_USERNAME=pluqi-test');
putenv('DIGIFLAZZ_API_KEY=not-a-real-key');
putenv('DIGIFLAZZ_API_URL=http://127.0.0.1:1');
putenv('VIPAYMENT_API_ID=pluqi-test');
putenv('VIPAYMENT_API_KEY=not-a-real-key');
putenv('VIPAYMENT_API_URL=https://127.0.0.1:1');

require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once PLUQI_ROOT . '/config/database.php';
require_once PLUQI_ROOT . '/app/services/fulfillment.php';

$pdo = database();
$providerQuery = $pdo->prepare("SELECT id FROM providers WHERE code='DIGI' LIMIT 1");
$providerQuery->execute();
$providerId = (int)$providerQuery->fetchColumn();
if ($providerId <= 0) {
    fwrite(STDERR, "Missing DIGI provider row; apply the schema and migrations first.\n");
    exit(1);
}

$token = bin2hex(random_bytes(8));
$gameId = null;
$productId = null;
$fixture = null;
$pdo->beginTransaction();
try {
    $q = $pdo->prepare("INSERT INTO games (slug,name,status) VALUES (:slug,'Duplicate queue integration','ACTIVE')");
    $q->execute(['slug' => 'dup-queue-' . $token]);
    $gameId = (int)$pdo->lastInsertId();

    $q = $pdo->prepare("INSERT INTO products
        (game_id,provider_id,provider_product_code,name,base_price,selling_price,status)
        VALUES (:game_id,:provider_id,:sku,'Duplicate queue integration product',1000,1500,'ACTIVE')");
    $q->execute([
        'game_id' => $gameId,
        'provider_id' => $providerId,
        'sku' => 'dup-queue-' . $token,
    ]);
    $productId = (int)$pdo->lastInsertId();
    $fixture = ['game_id' => $gameId, 'product_id' => $productId, 'provider_id' => $providerId];
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $e;
}

$publicId = 'dupjob' . bin2hex(random_bytes(10));
$reference = 'PQI-' . $publicId;
$orderId = null;
try {
    $pdo->beginTransaction();
    $insertOrder = $pdo->prepare("INSERT INTO orders
        (public_id, game_id, product_id, provider_id, customer_game_data,
         base_price, selling_price, admin_fee, payment_status, order_status)
        VALUES (:public_id, :game_id, :product_id, :provider_id, :account,
         1000, 1500, 1000, 'PAID', 'PROCESSING')");
    $insertOrder->execute([
        'public_id' => $publicId,
        'game_id' => $fixture['game_id'],
        'product_id' => $fixture['product_id'],
        'provider_id' => $fixture['provider_id'],
        'account' => json_encode(['user_id' => '123'], JSON_THROW_ON_ERROR),
    ]);
    $orderId = (int) $pdo->lastInsertId();

    $insertBudget = $pdo->prepare("INSERT INTO provider_budget_reservations
        (order_id, provider_id, amount, status) VALUES (:order_id, :provider_id, 1000, 'ACTIVE')");
    $insertBudget->execute(['order_id' => $orderId, 'provider_id' => $fixture['provider_id']]);

    $insertSupplier = $pdo->prepare("INSERT INTO supplier_transactions
        (order_id, provider_id, reference_id, status, attempts)
        VALUES (:order_id, :provider_id, :reference, 'PROCESSING', 1)");
    $insertSupplier->execute([
        'order_id' => $orderId,
        'provider_id' => $fixture['provider_id'],
        'reference' => $reference,
    ]);
    $pdo->commit();

    $outcome = fulfillment_process($pdo, $orderId);
    if ($outcome !== 'IGNORED') {
        throw new RuntimeException("Expected duplicate job to be IGNORED, got " . $outcome);
    }

    $checkOrder = $pdo->prepare('SELECT order_status FROM orders WHERE id=:id');
    $checkOrder->execute(['id' => $orderId]);
    $orderStatus = $checkOrder->fetchColumn();
    $checkSupplier = $pdo->prepare('SELECT status FROM supplier_transactions WHERE order_id=:id');
    $checkSupplier->execute(['id' => $orderId]);
    $supplierStatus = $checkSupplier->fetchColumn();

    if ($orderStatus !== 'PROCESSING' || $supplierStatus !== 'PROCESSING') {
        throw new RuntimeException("Fresh supplier PROCESSING state changed unexpectedly: order="
            . var_export($orderStatus, true) . ", supplier=" . var_export($supplierStatus, true));
    }

    // Older ambiguous requests must fail closed into review, never be resubmitted.
    $pdo->prepare("UPDATE supplier_transactions SET updated_at=DATE_SUB(NOW(), INTERVAL 11 MINUTE) WHERE order_id=:id")
        ->execute(['id' => $orderId]);
    $staleOutcome = fulfillment_process($pdo, $orderId);
    if ($staleOutcome !== 'REVIEW') {
        throw new RuntimeException("Expected stale ambiguous supplier request to enter REVIEW, got " . $staleOutcome);
    }
    $checkOrder->execute(['id' => $orderId]);
    $staleOrderStatus = $checkOrder->fetchColumn();
    $checkSupplier->execute(['id' => $orderId]);
    $staleSupplierStatus = $checkSupplier->fetchColumn();
    if ($staleOrderStatus !== 'MANUAL_REVIEW' || $staleSupplierStatus !== 'REVIEW') {
        throw new RuntimeException("Stale supplier PROCESSING was not moved to manual review: order="
            . var_export($staleOrderStatus, true) . ", supplier=" . var_export($staleSupplierStatus, true));
    }
    $checkBudget = $pdo->prepare('SELECT status FROM provider_budget_reservations WHERE order_id=:id');
    $checkBudget->execute(['id' => $orderId]);
    if ($checkBudget->fetchColumn() !== 'REVIEW') {
        throw new RuntimeException("Stale ambiguous supplier request must move provider reservation to REVIEW.");
    }

    // Even if an active budget reservation remains, a stale duplicate job cannot
    // revive an order that has already entered MANUAL_REVIEW.
    $pdo->prepare("UPDATE provider_budget_reservations SET status='ACTIVE' WHERE order_id=:id")
        ->execute(['id' => $orderId]);
    $reviewOutcome = fulfillment_process($pdo, $orderId);
    if ($reviewOutcome !== 'REVIEW') {
        throw new RuntimeException("Expected MANUAL_REVIEW order to remain blocked, got " . $reviewOutcome);
    }
    $checkOrder->execute(['id' => $orderId]);
    if ($checkOrder->fetchColumn() !== 'MANUAL_REVIEW') {
        throw new RuntimeException("Duplicate job revived a MANUAL_REVIEW order.");
    }
    $checkSupplier->execute(['id' => $orderId]);
    if ($checkSupplier->fetchColumn() !== 'REVIEW') {
        throw new RuntimeException("Duplicate job changed the reviewed supplier transaction.");
    }

    // Terminal/reconciliation states must never be revived by stale queue entries.
    // Keep the supplier row in REVIEW and the reservation ACTIVE to exercise the guard
    // independently of those later-stage safety checks.
    foreach (['FAILED', 'REFUND_PENDING', 'REFUNDED'] as $blockedStatus) {
        $pdo->prepare("UPDATE orders SET order_status=:status WHERE id=:id")
            ->execute(['status' => $blockedStatus, 'id' => $orderId]);

        $blockedOutcome = fulfillment_process($pdo, $orderId);
        if ($blockedOutcome !== 'IGNORED') {
            throw new RuntimeException("Expected {$blockedStatus} order to be ignored, got " . $blockedOutcome);
        }

        $checkOrder->execute(['id' => $orderId]);
        if ($checkOrder->fetchColumn() !== $blockedStatus) {
            throw new RuntimeException("Duplicate job changed terminal order status {$blockedStatus}.");
        }
        $checkSupplier->execute(['id' => $orderId]);
        if ($checkSupplier->fetchColumn() !== 'REVIEW') {
            throw new RuntimeException("Duplicate job changed supplier review state for {$blockedStatus}.");
        }
    }

    echo "Duplicate queue job preserves fresh PROCESSING state; stale ambiguous requests enter manual review; reviewed and terminal orders cannot be revived.\\n";
} finally {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    if ($orderId !== null) {
        $pdo->beginTransaction();
        try {
            $pdo->prepare('DELETE FROM supplier_transactions WHERE order_id=:id')->execute(['id' => $orderId]);
            $pdo->prepare('DELETE FROM provider_budget_reservations WHERE order_id=:id')->execute(['id' => $orderId]);
            $pdo->prepare('DELETE FROM orders WHERE id=:id')->execute(['id' => $orderId]);
            if ($productId !== null) $pdo->prepare('DELETE FROM products WHERE id=:id')->execute(['id' => $productId]);
            if ($gameId !== null) $pdo->prepare('DELETE FROM games WHERE id=:id')->execute(['id' => $gameId]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }
}
