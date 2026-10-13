<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/app/services/order_checkout.php';

putenv('APP_ENV=test');
putenv('CHECKOUT_PAYMENTS_ENABLED=true');
putenv('SUPPLIER_ORDERS_ENABLED=true');
putenv('DUITKU_ENV=sandbox');
putenv('DUITKU_MERCHANT_CODE=TESTMERCHANT');
putenv('DUITKU_API_KEY=integration-test-key');
putenv('DUITKU_PAYMENT_METHOD=VC');
putenv('DUITKU_CALLBACK_URL=https://example.test/duitku-callback.php');
putenv('DUITKU_RETURN_URL=https://example.test/order-status.php');

function checkout_integration_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

// Use a non-static setup connection and close it before fork. config/database.php caches
// its PDO in a function-static variable, which must not be inherited across pcntl_fork().
$pdo = new PDO(
    sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', getenv('DB_HOST') ?: '127.0.0.1', (int)(getenv('DB_PORT') ?: 3306), getenv('DB_DATABASE') ?: ''),
    getenv('DB_USERNAME') ?: '',
    getenv('DB_PASSWORD') ?: '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]
);
$token = bin2hex(random_bytes(5));
$slug = 'checkout-race-' . $token;
$key = sprintf('%08x-%04x-4%03x-a%03x-%012x', random_int(1, 0xffffffff), random_int(0, 0xffff), random_int(0, 0xfff), random_int(0, 0xfff), random_int(0, 0xffffffffffff));
$checkoutHash = hash('sha256', $key);
$callLog = sys_get_temp_dir() . '/pluq-duitku-mock-' . $token . '.log';
$startGate = sys_get_temp_dir() . '/pluq-checkout-start-' . $token . '.gate';
$firstResult = sys_get_temp_dir() . '/pluq-checkout-first-' . $token . '.json';
$secondResult = sys_get_temp_dir() . '/pluq-checkout-second-' . $token . '.json';
$firstLockReady = sys_get_temp_dir() . '/pluq-checkout-lock-a-' . $token . '.ready';
$secondLockReady = sys_get_temp_dir() . '/pluq-checkout-lock-b-' . $token . '.ready';
$firstDeadlockLog = sys_get_temp_dir() . '/pluq-checkout-deadlock-a-' . $token . '.json';
$secondDeadlockLog = sys_get_temp_dir() . '/pluq-checkout-deadlock-b-' . $token . '.json';
$gameId = 0;
$productId = 0;
$orderIds = [];

try {
    $pdo->beginTransaction();
    $pdo->prepare("UPDATE providers SET balance=1000000,reserve_percentage=50,status='ACTIVE' WHERE code='DIGI'")->execute();
    $pdo->prepare("INSERT INTO games (slug,name,status) VALUES (:slug,'Checkout Race Integration','ACTIVE')")
        ->execute(['slug' => $slug]);
    $gameId = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO products (game_id,provider_id,provider_product_code,name,base_price,selling_price,status)
        SELECT :game_id,id,:sku,'Checkout Race Product',1000,1500,'ACTIVE' FROM providers WHERE code='DIGI'")
        ->execute(['game_id' => $gameId, 'sku' => 'race-' . $token]);
    $productId = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO game_fields (game_id,field_key,label,field_type,is_required,status)
        VALUES (:game_id,'user_id','User ID','number',1,'ACTIVE')")
        ->execute(['game_id' => $gameId]);
    $pdo->commit();

    // Avoid inheriting a live PDO socket across fork; each process creates its own connection.
    unset($pdo);
    $pid1 = pcntl_fork();
    if ($pid1 === -1) throw new RuntimeException('Could not fork first checkout process.');
    if ($pid1 === 0) {
        while (!is_file($startGate)) usleep(1000);
        try {
            $childPdo = database();
            $creator = static function (array $config, string $orderId, int $amount, string $product, string $email) use ($callLog): array {
                $fh = fopen($callLog, 'ab');
                if ($fh === false) throw new RuntimeException('Cannot write mock call log.');
                flock($fh, LOCK_EX);
                fwrite($fh, $orderId . PHP_EOL);
                fflush($fh);
                flock($fh, LOCK_UN);
                fclose($fh);
                // Hold the request open so the second process sees the committed order before its URL exists.
                usleep(2200000);
                return ['payment_url' => 'https://sandbox.duitku.com/mock/' . $orderId, 'reference' => 'MOCK-' . $orderId];
            };
            $hook = static function (PDO $connection) use ($firstLockReady, $secondLockReady, $firstDeadlockLog): void {
                $stmt = $connection->prepare('SELECT id FROM providers WHERE code=:code FOR UPDATE');
                $stmt->execute(['code' => 'DIGI']);
                if (!$stmt->fetch()) throw new RuntimeException('DIGI provider row missing for deadlock test.');
                file_put_contents($firstLockReady, 'ready');
                $deadline = microtime(true) + 10;
                while (!is_file($secondLockReady)) {
                    if (microtime(true) >= $deadline) throw new RuntimeException('Second checkout did not reach the lock barrier.');
                    usleep(1000);
                }
                try {
                    $stmt->execute(['code' => 'VIPAY']);
                    $stmt->fetch();
                } catch (PDOException $e) {
                    file_put_contents($firstDeadlockLog, json_encode(['sqlstate' => (string)$e->getCode(), 'driver_code' => (int)($e->errorInfo[1] ?? 0), 'classified_as_race' => checkout_is_idempotency_race($e)], JSON_THROW_ON_ERROR));
                    throw $e;
                }
            };
            $result = checkout_create_payment($childPdo, [
                'email' => 'same@example.test',
                'idempotency_key' => $key,
                'game_slug' => $slug,
                'product_id' => $productId,
                'account' => ['user_id' => '123456'],
            ], $creator, $hook);
            file_put_contents($firstResult, json_encode($result, JSON_THROW_ON_ERROR));
            exit(0);
        } catch (Throwable $e) {
            file_put_contents($firstResult, json_encode(['error' => get_class($e) . ': ' . $e->getMessage()]));
            exit(1);
        }
    }

    $pid2 = pcntl_fork();
    if ($pid2 === -1) throw new RuntimeException('Could not fork second checkout process.');
    if ($pid2 === 0) {
        while (!is_file($startGate)) usleep(1000);
        try {
            $childPdo = database();
            $creator = static function (array $config, string $orderId, int $amount, string $product, string $email) use ($callLog): array {
                $fh = fopen($callLog, 'ab');
                if ($fh === false) throw new RuntimeException('Cannot write mock call log.');
                flock($fh, LOCK_EX);
                fwrite($fh, $orderId . PHP_EOL);
                fflush($fh);
                flock($fh, LOCK_UN);
                fclose($fh);
                usleep(2200000);
                return ['payment_url' => 'https://sandbox.duitku.com/mock/' . $orderId, 'reference' => 'MOCK-' . $orderId];
            };
            $hook = static function (PDO $connection) use ($firstLockReady, $secondLockReady, $secondDeadlockLog): void {
                $stmt = $connection->prepare('SELECT id FROM providers WHERE code=:code FOR UPDATE');
                $stmt->execute(['code' => 'VIPAY']);
                if (!$stmt->fetch()) throw new RuntimeException('VIPAY provider row missing for deadlock test.');
                file_put_contents($secondLockReady, 'ready');
                $deadline = microtime(true) + 10;
                while (!is_file($firstLockReady)) {
                    if (microtime(true) >= $deadline) throw new RuntimeException('First checkout did not reach the lock barrier.');
                    usleep(1000);
                }
                try {
                    $stmt->execute(['code' => 'DIGI']);
                    $stmt->fetch();
                } catch (PDOException $e) {
                    file_put_contents($secondDeadlockLog, json_encode(['sqlstate' => (string)$e->getCode(), 'driver_code' => (int)($e->errorInfo[1] ?? 0), 'classified_as_race' => checkout_is_idempotency_race($e)], JSON_THROW_ON_ERROR));
                    throw $e;
                }
            };
            $result = checkout_create_payment($childPdo, [
                'email' => 'same@example.test',
                'idempotency_key' => $key,
                'game_slug' => $slug,
                'product_id' => $productId,
                'account' => ['user_id' => '123456'],
            ], $creator, $hook);
            file_put_contents($secondResult, json_encode($result, JSON_THROW_ON_ERROR));
            exit(0);
        } catch (Throwable $e) {
            file_put_contents($secondResult, json_encode(['error' => get_class($e) . ': ' . $e->getMessage()]));
            exit(1);
        }
    }

    if (file_put_contents($startGate, 'go') === false) throw new RuntimeException('Could not release checkout race barrier.');
    pcntl_waitpid($pid1, $status1);
    pcntl_waitpid($pid2, $status2);
    checkout_integration_assert(pcntl_wifexited($status1) && pcntl_wexitstatus($status1) === 0, 'First checkout process failed: ' . (file_get_contents($firstResult) ?: 'no result'));
    checkout_integration_assert(pcntl_wifexited($status2) && pcntl_wexitstatus($status2) === 0, 'Second checkout process failed: ' . (file_get_contents($secondResult) ?: 'no result'));

    $first = json_decode((string)file_get_contents($firstResult), true, 512, JSON_THROW_ON_ERROR);
    $second = json_decode((string)file_get_contents($secondResult), true, 512, JSON_THROW_ON_ERROR);
    checkout_integration_assert(empty($first['error']) && empty($second['error']), 'Checkout process returned an error.');
    // Process scheduling is nondeterministic: either child may win the insert race.
    checkout_integration_assert(in_array(($first['reused'] ?? null), [true, false], true), 'First result must report whether it reused the payment.');
    checkout_integration_assert(in_array(($second['reused'] ?? null), [true, false], true), 'Second result must report whether it reused the payment.');
    checkout_integration_assert((int)(($first['reused'] ?? null) === false) + (int)(($second['reused'] ?? null) === false) === 1, 'Exactly one concurrent checkout request must create the gateway payment.');
    checkout_integration_assert((int)(($first['reused'] ?? null) === true) + (int)(($second['reused'] ?? null) === true) === 1, 'Exactly one concurrent checkout request must reuse the winning payment.');
    checkout_integration_assert(($first['order_id'] ?? null) === ($second['order_id'] ?? null), 'Both requests must return the same order.');
    checkout_integration_assert(($first['payment_url'] ?? null) === ($second['payment_url'] ?? null), 'Both requests must return the same persisted payment URL.');
    $calls = is_file($callLog) ? file($callLog, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [];
    checkout_integration_assert(count($calls) === 1, 'Mock Duitku must be called exactly once.');

    $pdo = database();
    $deadlockEvidence = [];
    foreach ([$firstDeadlockLog, $secondDeadlockLog] as $path) {
        if (is_file($path)) $deadlockEvidence[] = json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    }
    $classifiedVictims = array_values(array_filter($deadlockEvidence, static fn(array $row): bool =>
        (int)($row['driver_code'] ?? 0) === 1213 && ($row['classified_as_race'] ?? false) === true
    ));
    checkout_integration_assert(count($classifiedVictims) === 1, 'Expected exactly one real MySQL 1213 deadlock victim classified for winner recovery; observed: ' . json_encode($deadlockEvidence));

    $stmt = $pdo->prepare('SELECT id FROM orders WHERE checkout_key=:key');
    $stmt->execute(['key' => $checkoutHash]);
    $rows = $stmt->fetchAll();
    checkout_integration_assert(count($rows) === 1, 'Exactly one order must exist for the idempotency key.');
    $orderIds = array_map(static fn(array $row): int => (int)$row['id'], $rows);
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM payment_transactions WHERE order_id=:id');
    $stmt->execute(['id' => $orderIds[0]]);
    checkout_integration_assert((int)$stmt->fetchColumn() === 1, 'Exactly one payment transaction must exist.');

    $mismatchRejected = false;
    try {
        checkout_create_payment($pdo, [
            'email' => 'same@example.test',
            'idempotency_key' => $key,
            'game_slug' => $slug,
            'product_id' => $productId,
            'account' => ['user_id' => '999999'],
        ], static function (): array {
            throw new RuntimeException('Mock Duitku must not be called for a mismatched payload.');
        });
    } catch (DomainException $e) {
        $mismatchRejected = true;
    }
    checkout_integration_assert($mismatchRejected, 'Same key with a different account payload must be rejected.');

    // Gateway timeout/failure after the DB commit must not trigger a second gateway request.
    $failureKey = sprintf('%08x-%04x-4%03x-a%03x-%012x', random_int(1, 0xffffffff), random_int(0, 0xffff), random_int(0, 0xfff), random_int(0, 0xfff), random_int(0, 0xffffffffffff));
    $failureCheckoutHash = hash('sha256', $failureKey);
    $failureCalls = 0;
    $gatewayFailureObserved = false;
    try {
        checkout_create_payment($pdo, [
            'email' => 'timeout@example.test',
            'idempotency_key' => $failureKey,
            'game_slug' => $slug,
            'product_id' => $productId,
            'account' => ['user_id' => '654321'],
        ], static function () use (&$failureCalls): array {
            $failureCalls++;
            throw new RuntimeException('Mock gateway timeout after request dispatch.');
        });
    } catch (RuntimeException $e) {
        $gatewayFailureObserved = str_contains($e->getMessage(), 'tautan pembayaran belum tersedia');
    }
    checkout_integration_assert($gatewayFailureObserved, 'A gateway timeout must report that the order needs review.');
    checkout_integration_assert($failureCalls === 1, 'The first checkout must call the mock gateway exactly once.');

    $retryStarted = microtime(true);
    $retryRejectedForReview = false;
    try {
        checkout_create_payment($pdo, [
            'email' => 'timeout@example.test',
            'idempotency_key' => $failureKey,
            'game_slug' => $slug,
            'product_id' => $productId,
            'account' => ['user_id' => '654321'],
        ], static function () use (&$failureCalls): array {
            $failureCalls++;
            return ['payment_url' => 'https://sandbox.duitku.com/mock/should-not-be-called', 'reference' => 'SHOULD-NOT-BE-CALLED'];
        });
    } catch (DomainException $e) {
        $retryRejectedForReview = str_contains($e->getMessage(), 'pemeriksaan admin');
    }
    $retryDuration = microtime(true) - $retryStarted;
    checkout_integration_assert($retryRejectedForReview, 'A retry after recorded gateway failure must request manual review.');
    checkout_integration_assert($retryDuration < 2.0, 'A known failed gateway attempt must not incur the 22-second URL wait.');
    checkout_integration_assert($failureCalls === 1, 'Retry after ambiguous gateway failure must not make another gateway request.');

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM orders WHERE checkout_key=:key');
    $stmt->execute(['key' => $failureCheckoutHash]);
    checkout_integration_assert((int)$stmt->fetchColumn() === 1, 'Gateway failure must leave exactly one checkout order.');
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM payment_transactions pt JOIN orders o ON o.id=pt.order_id WHERE o.checkout_key=:key AND pt.gateway_payment_url IS NULL");
    $stmt->execute(['key' => $failureCheckoutHash]);
    checkout_integration_assert((int)$stmt->fetchColumn() === 1, 'Ambiguous gateway failure must not fabricate a payment URL.');
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM orders WHERE checkout_key=:key AND last_error IS NOT NULL AND last_error<>''");
    $stmt->execute(['key' => $failureCheckoutHash]);
    checkout_integration_assert((int)$stmt->fetchColumn() === 1, 'Gateway failure must be recorded for manual review.');

    echo "Checkout gateway-failure idempotency passed (retry returns immediately for review; exactly one mock gateway call; no duplicate order/payment).\n";
    echo "Concurrent MySQL checkout deadlock-recovery tests passed (one real 1213 victim classified and recovered; mock Duitku called once; one order/payment; URL reused; changed payload rejected).\n";
} finally {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
    if (!isset($pdo) || !$pdo instanceof PDO) {
        try { $pdo = database(); } catch (Throwable $ignored) { $pdo = null; }
    }
    if ($pdo instanceof PDO) {
        $pdo->prepare('DELETE pt FROM payment_transactions pt JOIN orders o ON o.id=pt.order_id WHERE o.checkout_key=:key OR o.checkout_key=:failure_key')->execute(['key' => $checkoutHash, 'failure_key' => isset($failureCheckoutHash) ? $failureCheckoutHash : '']);
        $pdo->prepare('DELETE FROM orders WHERE checkout_key=:key OR checkout_key=:failure_key')->execute(['key' => $checkoutHash, 'failure_key' => isset($failureCheckoutHash) ? $failureCheckoutHash : '']);
        if ($gameId > 0) {
            $pdo->prepare('DELETE FROM game_fields WHERE game_id=:id')->execute(['id' => $gameId]);
            $pdo->prepare('DELETE FROM products WHERE game_id=:id')->execute(['id' => $gameId]);
            $pdo->prepare('DELETE FROM games WHERE id=:id')->execute(['id' => $gameId]);
        }
    }
    foreach ([$callLog, $firstResult, $secondResult, $startGate, $firstLockReady, $secondLockReady, $firstDeadlockLog, $secondDeadlockLog] as $path) if (is_file($path)) unlink($path);
}
