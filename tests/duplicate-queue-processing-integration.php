<?php
declare(strict_types=1);

// This integration test exercises the duplicate-job path only; a fresh PROCESSING
// supplier transaction must not trigger another supplier HTTP request.
putenv('SUPPLIER_ORDERS_ENABLED=true');

require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once PLUQI_ROOT . '/config/database.php';
require_once PLUQI_ROOT . '/app/services/fulfillment.php';

$pdo = database();
$game = $pdo->prepare("SELECT g.id AS game_id, p.id AS product_id, p.provider_id
    FROM games g
    JOIN products p ON p.game_id = g.id
    JOIN providers pr ON pr.id = p.provider_id
    WHERE g.slug = 'integration-game'
      AND p.provider_product_code = 'integration-sku'
      AND pr.code = 'DIGI'
    LIMIT 1");
$game->execute();
$fixture = $game->fetch();
if (!$fixture) {
    fwrite(STDERR, "Missing integration-game/integration-sku fixture.\n");
    exit(1);
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
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }
}
