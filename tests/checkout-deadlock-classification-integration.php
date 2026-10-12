<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/app/services/order_checkout.php';

function deadlock_test_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

$dsn = sprintf(
    'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
    getenv('DB_HOST') ?: '127.0.0.1',
    (int)(getenv('DB_PORT') ?: 3306),
    getenv('DB_DATABASE') ?: ''
);
$parent = new PDO($dsn, getenv('DB_USERNAME') ?: '', getenv('DB_PASSWORD') ?: '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
]);
$check = $parent->query("SELECT id FROM providers WHERE code IN ('DIGI','VIPAY') ORDER BY code")->fetchAll(PDO::FETCH_COLUMN);
deadlock_test_assert(count($check) === 2, 'Deadlock test requires the seeded DIGI and VIPAY provider rows.');
unset($parent);

$base = sys_get_temp_dir() . '/pluq-checkout-deadlock-' . bin2hex(random_bytes(5));
$results = [$base . '-a.json', $base . '-b.json'];
$pids = [];
$specs = [
    ['first' => 'DIGI', 'second' => 'VIPAY', 'result' => $results[0]],
    ['first' => 'VIPAY', 'second' => 'DIGI', 'result' => $results[1]],
];

try {
    foreach ($specs as $spec) {
        $pid = pcntl_fork();
        if ($pid === -1) throw new RuntimeException('Could not fork deadlock test worker.');
        if ($pid === 0) {
            $pdo = null;
            try {
                $pdo = database();
                $pdo->beginTransaction();
                $stmt = $pdo->prepare('SELECT id FROM providers WHERE code=:code FOR UPDATE');
                $stmt->execute(['code' => $spec['first']]);
                if (!$stmt->fetch()) throw new RuntimeException('First provider row missing.');
                // Give the other process time to lock the opposite row.
                usleep(800000);
                $stmt->execute(['code' => $spec['second']]);
                $stmt->fetch();
                $pdo->commit();
                file_put_contents($spec['result'], json_encode(['outcome' => 'committed'], JSON_THROW_ON_ERROR));
                exit(0);
            } catch (PDOException $e) {
                if ($pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
                file_put_contents($spec['result'], json_encode([
                    'outcome' => 'database_error',
                    'sqlstate' => (string)$e->getCode(),
                    'driver_code' => (int)($e->errorInfo[1] ?? 0),
                    'classified_as_race' => checkout_is_idempotency_race($e),
                ], JSON_THROW_ON_ERROR));
                exit(0);
            } catch (Throwable $e) {
                if ($pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
                file_put_contents($spec['result'], json_encode(['outcome' => 'unexpected_error', 'message' => $e->getMessage()]));
                exit(1);
            }
        }
        $pids[] = $pid;
    }

    foreach ($pids as $pid) {
        pcntl_waitpid($pid, $status);
        deadlock_test_assert(pcntl_wifexited($status) && pcntl_wexitstatus($status) === 0, 'Deadlock worker exited unexpectedly.');
    }

    $observed = [];
    foreach ($results as $path) {
        deadlock_test_assert(is_file($path), 'Deadlock worker did not write a result.');
        $observed[] = json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    }
    $deadlocks = array_values(array_filter($observed, static fn(array $r): bool =>
        ($r['outcome'] ?? '') === 'database_error'
        && in_array((int)($r['driver_code'] ?? 0), [1213], true)
        && ($r['classified_as_race'] ?? false) === true
    ));
    deadlock_test_assert(count($deadlocks) === 1, 'Expected exactly one real MySQL deadlock (driver error 1213) classified for winner recovery; observed: ' . json_encode($observed));
    deadlock_test_assert(count(array_filter($observed, static fn(array $r): bool => ($r['outcome'] ?? '') === 'committed')) === 1, 'Expected the other transaction to commit.');
    echo "Real MySQL deadlock classification test passed (one victim classified for idempotency winner recovery).\n";
} finally {
    foreach ($results as $path) if (is_file($path)) unlink($path);
}
