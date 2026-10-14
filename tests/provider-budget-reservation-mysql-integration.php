<?php
declare(strict_types=1);

require dirname(__DIR__) . '/config/database.php';
require dirname(__DIR__) . '/app/services/provider_budget.php';

function budget_integration_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$pdo = database();
$token = bin2hex(random_bytes(6));
$providerId = null;
$gameId = null;
$productId = null;
$orderIds = [];

try {
    $pdo->beginTransaction();
    $stmt = $pdo->prepare("INSERT INTO providers (code,name,status,balance,balance_threshold,reserve_percentage,last_balance_check_at)
        VALUES (:code,'Budget integration fixture','ACTIVE',1000000,0,50.00,NULL)");
    $stmt->execute(['code' => 'BUDGET_' . strtoupper($token)]);
    $providerId = (int)$pdo->lastInsertId();

    $stmt = $pdo->prepare("INSERT INTO games (slug,name,status) VALUES (:slug,'Budget integration fixture','ACTIVE')");
    $stmt->execute(['slug' => 'budget-test-' . $token]);
    $gameId = (int)$pdo->lastInsertId();

    $stmt = $pdo->prepare("INSERT INTO products (game_id,provider_id,provider_product_code,name,base_price,selling_price,status)
        VALUES (:game,:provider,:sku,'Budget integration fixture',300000,350000,'ACTIVE')");
    $stmt->execute(['game' => $gameId, 'provider' => $providerId, 'sku' => 'BUDGET_SKU_' . $token]);
    $productId = (int)$pdo->lastInsertId();

    foreach ([300000, 200001] as $index => $cost) {
        $publicId = bin2hex(random_bytes(13));
        $stmt = $pdo->prepare("INSERT INTO orders
            (public_id,game_id,product_id,provider_id,customer_game_data,base_price,selling_price,admin_fee,payment_status,order_status)
            VALUES (:public_id,:game,:product,:provider,JSON_OBJECT('user_id','budget-test'),:cost,:selling,0,'PAID','QUEUED')");
        $stmt->execute([
            'public_id' => $publicId,
            'game' => $gameId,
            'product' => $productId,
            'provider' => $providerId,
            'cost' => $cost,
            'selling' => $cost + 50000,
        ]);
        $orderIds[$index] = (int)$pdo->lastInsertId();
    }
    $pdo->commit();

    // The first reservation consumes Rp300,000 of the Rp500,000 usable capacity.
    $pdo->beginTransaction();
    $first = provider_budget_reserve_paid_order($pdo, $orderIds[0]);
    budget_integration_assert($first['reserved'] === true, 'First reservation should be admitted.');
    $pdo->commit();

    // A second order must see the first commitment and fail closed at Rp500,001 total.
    $pdo->beginTransaction();
    $second = provider_budget_reserve_paid_order($pdo, $orderIds[1]);
    budget_integration_assert($second['reserved'] === false && $second['status'] === 'CAPACITY_EXCEEDED',
        'Second reservation exceeded aggregate provider capacity but was not rejected.');
    $pdo->commit();

    // A definitive release frees capacity and allows the second order to reserve.
    $pdo->beginTransaction();
    provider_budget_resolve($pdo, $orderIds[0], 'RELEASED', 'Integration test release');
    $pdo->commit();

    $pdo->beginTransaction();
    $secondAfterRelease = provider_budget_reserve_paid_order($pdo, $orderIds[1]);
    budget_integration_assert($secondAfterRelease['reserved'] === true,
        'Capacity should become available after the first reservation is released.');
    $pdo->commit();

    // Repeating the same reservation must be idempotent and must not create a second row.
    $pdo->beginTransaction();
    $duplicate = provider_budget_reserve_paid_order($pdo, $orderIds[1]);
    $pdo->commit();
    budget_integration_assert($duplicate['reserved'] === true && $duplicate['existing'] === true,
        'Duplicate reservation call should return the existing reservation.');
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM provider_budget_reservations WHERE order_id=:id');
    $stmt->execute(['id' => $orderIds[1]]);
    budget_integration_assert((int)$stmt->fetchColumn() === 1, 'Duplicate reservation created more than one ledger row.');

    echo "MySQL provider budget reservation integration checks passed." . PHP_EOL;
} finally {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    // Clean only the disposable fixture created by this test.
    if ($orderIds) {
        $marks = implode(',', array_fill(0, count($orderIds), '?'));
        $pdo->prepare("DELETE FROM provider_budget_reservations WHERE order_id IN ($marks)")->execute(array_values($orderIds));
        $pdo->prepare("DELETE FROM orders WHERE id IN ($marks)")->execute(array_values($orderIds));
    }
    if ($productId !== null) $pdo->prepare('DELETE FROM products WHERE id=?')->execute([$productId]);
    if ($gameId !== null) $pdo->prepare('DELETE FROM games WHERE id=?')->execute([$gameId]);
    if ($providerId !== null) $pdo->prepare('DELETE FROM providers WHERE id=?')->execute([$providerId]);
}
