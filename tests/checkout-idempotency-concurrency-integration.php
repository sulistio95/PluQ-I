<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/app/services/order_checkout.php';

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
$firstResult = sys_get_temp_dir() . '/pluq-checkout-first-' . $token . '.json';
$secondResult = sys_get_temp_dir() . '/pluq-checkout-second-' . $token . '.json';
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
            $result = checkout_create_payment($childPdo, [
                'email' => 'same@example.test',
                'idempotency_key' => $key,
                'game_slug' => $slug,
                'product_id' => $productId,
                'account' => ['user_id' => '123456'],
            ], $creator);
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
        try {
            $childPdo = database();
            $creator = static function (array $config, string $orderId, int $amount, string $product, string $email) use ($callLog): array {
                $fh = fopen($callLog, 'ab');
                if ($fh !== false) { fwrite($fh, 'UNEXPECTED_SECOND_CALL' . PHP_EOL); fclose($fh); }
                return ['payment_url' => 'https://sandbox.duitku.com/mock/duplicate', 'reference' => 'MOCK-DUPLICATE'];
            };
            $result = checkout_create_payment($childPdo, [
                'email' => 'same@example.test',
                'idempotency_key' => $key,
                'game_slug' => $slug,
                'product_id' => $productId,
                'account' => ['user_id' => '123456'],
            ], $creator);
            file_put_contents($secondResult, json_encode($result, JSON_THROW_ON_ERROR));
            exit(0);
        } catch (Throwable $e) {
            file_put_contents($secondResult, json_encode(['error' => get_class($e) . ': ' . $e->getMessage()]));
            exit(1);
        }
    }

    pcntl_waitpid($pid1, $status1);
    pcntl_waitpid($pid2, $status2);
    checkout_integration_assert(pcntl_wifexited($status1) && pcntl_wexitstatus($status1) === 0, 'First checkout process failed: ' . (file_get_contents($firstResult) ?: 'no result'));
    checkout_integration_assert(pcntl_wifexited($status2) && pcntl_wexitstatus($status2) === 0, 'Second checkout process failed: ' . (file_get_contents($secondResult) ?: 'no result'));

    $first = json_decode((string)file_get_contents($firstResult), true, 512, JSON_THROW_ON_ERROR);
    $second = json_decode((string)file_get_contents($secondResult), true, 512, JSON_THROW_ON_ERROR);
    checkout_integration_assert(empty($first['error']) && empty($second['error']), 'Checkout process returned an error.');
    checkout_integration_assert(($first['reused'] ?? null) === false, 'First request must create the gateway payment.');
    checkout_integration_assert(($second['reused'] ?? null) === true, 'Second request must reuse the winning payment URL.');
    checkout_integration_assert(($first['order_id'] ?? null) === ($second['order_id'] ?? null), 'Both requests must return the same order.');
    checkout_integration_assert(($first['payment_url'] ?? null) === ($second['payment_url'] ?? null), 'Both requests must return the same persisted payment URL.');
    $calls = is_file($callLog) ? file($callLog, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [];
    checkout_integration_assert(count($calls) === 1, 'Mock Duitku must be called exactly once.');

    $pdo = database();
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

    echo "Concurrent MySQL checkout idempotency tests passed (mock Duitku; one order, one payment, one gateway call, winner URL reused, changed payload rejected).\n";
} finally {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
    if (!isset($pdo) || !$pdo instanceof PDO) {
        try { $pdo = database(); } catch (Throwable $ignored) { $pdo = null; }
    }
    if ($pdo instanceof PDO) {
        $pdo->prepare('DELETE pt FROM payment_transactions pt JOIN orders o ON o.id=pt.order_id WHERE o.checkout_key=:key')->execute(['key' => $checkoutHash]);
        $pdo->prepare('DELETE FROM orders WHERE checkout_key=:key')->execute(['key' => $checkoutHash]);
        if ($gameId > 0) {
            $pdo->prepare('DELETE FROM game_fields WHERE game_id=:id')->execute(['id' => $gameId]);
            $pdo->prepare('DELETE FROM products WHERE game_id=:id')->execute(['id' => $gameId]);
            $pdo->prepare('DELETE FROM games WHERE id=:id')->execute(['id' => $gameId]);
        }
    }
    foreach ([$callLog, $firstResult, $secondResult] as $path) if (is_file($path)) unlink($path);
}
