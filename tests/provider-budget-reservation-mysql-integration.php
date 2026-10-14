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

// Worker mode is launched by the parent test as a separate PHP process so each
// contender uses its own PDO connection and transaction.
if (($argv[1] ?? '') === '--worker') {
    $orderId = (int)($argv[2] ?? 0);
    $barrierPath = (string)($argv[3] ?? '');
    if ($orderId < 1 || $barrierPath === '') {
        fwrite(STDERR, "Worker requires order ID and barrier path.\\n");
        exit(2);
    }
    $readyPath = $barrierPath . '.' . $orderId . '.ready';
    file_put_contents($readyPath, 'ready', LOCK_EX);
    $deadline = microtime(true) + 15.0;
    while (!is_file($barrierPath) && microtime(true) < $deadline) {
        usleep(10000);
    }
    if (!is_file($barrierPath)) {
        fwrite(STDERR, "Timed out waiting for concurrency barrier.\\n");
        exit(3);
    }
    $workerPdo = database();
    $workerPdo->beginTransaction();
    try {
        $result = provider_budget_reserve_paid_order($workerPdo, $orderId);
        $workerPdo->commit();
        echo json_encode($result, JSON_THROW_ON_ERROR) . PHP_EOL;
        exit(0);
    } catch (Throwable $e) {
        if ($workerPdo->inTransaction()) $workerPdo->rollBack();
        fwrite(STDERR, $e->getMessage() . PHP_EOL);
        exit(4);
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

    foreach ([300000, 200001, 300000, 300000, 300000, 200001] as $index => $cost) {
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

    // Clear the sequential fixture's commitment, then race two independent PHP
    // processes for Rp300,000 each against the same Rp500,000 usable capacity.
    $pdo->beginTransaction();
    provider_budget_resolve($pdo, $orderIds[1], 'RELEASED', 'Prepare concurrent reservation test');
    $pdo->commit();

    $barrierPath = sys_get_temp_dir() . '/pluq-budget-barrier-' . $token;
    @unlink($barrierPath);
    $workers = [];
    foreach ([$orderIds[2], $orderIds[3]] as $concurrentOrderId) {
        $stdoutPath = tempnam(sys_get_temp_dir(), 'pluq-budget-out-');
        $stderrPath = tempnam(sys_get_temp_dir(), 'pluq-budget-err-');
        budget_integration_assert(is_string($stdoutPath) && is_string($stderrPath), 'Could not allocate worker output files.');
        $stdoutHandle = fopen($stdoutPath, 'wb');
        $stderrHandle = fopen($stderrPath, 'wb');
        $command = [PHP_BINARY, __FILE__, '--worker', (string)$concurrentOrderId, $barrierPath];
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => $stdoutHandle, 2 => $stderrHandle], $pipes);
        budget_integration_assert(is_resource($process), 'Could not start concurrent reservation worker.');
        fclose($pipes[0]);
        $workers[] = ['process' => $process, 'stdout_path' => $stdoutPath, 'stderr_path' => $stderrPath,
            'stdout_handle' => $stdoutHandle, 'stderr_handle' => $stderrHandle];
    }
    // Wait until both independent workers are actually at the barrier before releasing them.
    $readyDeadline = microtime(true) + 15.0;
    $readyCount = 0;
    do {
        $readyCount = 0;
        foreach ([$orderIds[2], $orderIds[3]] as $readyOrderId) {
            if (is_file($barrierPath . '.' . $readyOrderId . '.ready')) $readyCount++;
        }
        if ($readyCount === 2) break;
        usleep(10000);
    } while (microtime(true) < $readyDeadline);
    // Always release any started worker, even if the readiness timeout is reached.
    file_put_contents($barrierPath, 'go');
    foreach ($workers as &$worker) {
        $worker['exit'] = proc_close($worker['process']);
        fclose($worker['stdout_handle']);
        fclose($worker['stderr_handle']);
        $worker['out'] = (string)file_get_contents($worker['stdout_path']);
        $worker['err'] = (string)file_get_contents($worker['stderr_path']);
        @unlink($worker['stdout_path']);
        @unlink($worker['stderr_path']);
        budget_integration_assert($worker['exit'] === 0,
            'Concurrent worker failed: ' . trim($worker['err']));
        $decoded = json_decode(trim($worker['out']), true);
        budget_integration_assert(is_array($decoded) && array_key_exists('reserved', $decoded),
            'Concurrent worker returned invalid result: ' . trim($worker['out']));
        $worker['result'] = $decoded;
    }
    unset($worker);
    @unlink($barrierPath);
    @unlink($barrierPath . '.' . $orderIds[2] . '.ready');
    @unlink($barrierPath . '.' . $orderIds[3] . '.ready');
    budget_integration_assert($readyCount === 2,
        'Concurrent reservation workers did not both reach the barrier before release.');

    $admitted = count(array_filter($workers, static fn(array $w): bool => $w['result']['reserved'] === true));
    budget_integration_assert($admitted === 1,
        'Concurrent reservation race admitted ' . $admitted . ' orders; expected exactly one within Rp500,000 capacity.');
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM provider_budget_reservations WHERE provider_id=:provider AND status IN ('ACTIVE','REVIEW')");
    $stmt->execute(['provider' => $providerId]);
    budget_integration_assert((float)$stmt->fetchColumn() <= 500000,
        'Concurrent reservations exceeded configured usable provider capacity.');

    // A settled purchase must remain committed if the cached balance timestamp is
    // equal to its updated_at timestamp. MySQL TIMESTAMP commonly has second precision,
    // so equality cannot prove that the balance query began after the supplier purchase.
    $pdo->beginTransaction();
    provider_budget_resolve($pdo, $orderIds[2], 'RELEASED', 'Clear concurrency fixture');
    provider_budget_resolve($pdo, $orderIds[3], 'RELEASED', 'Clear concurrency fixture');
    $settled = provider_budget_reserve_paid_order($pdo, $orderIds[4]);
    budget_integration_assert($settled['reserved'] === true, 'Settled timestamp fixture should reserve capacity.');
    provider_budget_resolve($pdo, $orderIds[4], 'SETTLED', 'Timestamp-boundary test');
    $boundary = '2026-10-14 12:00:00';
    $pdo->prepare('UPDATE provider_budget_reservations SET updated_at=:boundary WHERE order_id=:id')
        ->execute(['boundary' => $boundary, 'id' => $orderIds[4]]);
    $pdo->prepare('UPDATE providers SET last_balance_check_at=:boundary WHERE id=:id')
        ->execute(['boundary' => $boundary, 'id' => $providerId]);
    $pdo->commit();

    $pdo->beginTransaction();
    $afterSettled = provider_budget_reserve_paid_order($pdo, $orderIds[5]);
    $pdo->commit();
    budget_integration_assert($afterSettled['reserved'] === false && $afterSettled['status'] === 'CAPACITY_EXCEEDED',
        'A settled purchase with a timestamp equal to the cached balance check was incorrectly excluded from commitments.');

    echo "MySQL provider budget reservation capacity, idempotency, release, concurrency, and stale-balance boundary checks passed." . PHP_EOL;
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
