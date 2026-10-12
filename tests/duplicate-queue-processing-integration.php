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

    echo "Duplicate queue job preserves fresh PROCESSING state; stale ambiguous requests enter manual review without resubmission.\\n";
} finally {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    if ($orderId !== null) {
        $pdo->beginTransaction();
        try {
            $pdo->prepare('DELETE FROM supplier_transactions WHERE order_id=:id')->execute(['id' => $orderId]);
            $pdo->prepare('DELETE FROM orders WHERE id=:id')->execute(['id' => $orderId]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }
}
