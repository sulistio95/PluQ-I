<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/app/services/duitku_reconcile.php';

function reconcile_concurrency_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

if (!function_exists('pcntl_fork')) {
    fwrite(STDERR, "pcntl is required for the concurrent MySQL reconciliation test.\n");
    exit(1);
}

$dsn = sprintf(
    'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
    getenv('DB_HOST') ?: '127.0.0.1',
    (int)(getenv('DB_PORT') ?: 3306),
    getenv('DB_DATABASE') ?: ''
);
$connect = static fn(): PDO => new PDO($dsn, getenv('DB_USERNAME') ?: '', getenv('DB_PASSWORD') ?: '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
]);
$pdo = $connect();
$merchantOrderId = 'abcdef0123456789abcdef0123';
$stmt = $pdo->prepare("SELECT pt.id,pt.amount,pt.status AS payment_status,o.payment_status AS order_payment_status,o.order_status
    FROM payment_transactions pt JOIN orders o ON o.id=pt.order_id
    WHERE pt.merchant_order_id=:merchant_order_id AND pt.gateway='DUITKU' LIMIT 1");
$stmt->execute(['merchant_order_id' => $merchantOrderId]);
$payment = $stmt->fetch();
reconcile_concurrency_assert(is_array($payment), 'Expected disposable Duitku payment fixture was not found.');

$paymentId = (int)$payment['id'];
$entityId = (string)$paymentId;
$pdo->prepare("DELETE FROM audit_logs WHERE action='DUITKU_STATUS_LOOKUP' AND entity_type='PAYMENT_TRANSACTION' AND entity_id=:entity")
    ->execute(['entity' => $entityId]);

$token = bin2hex(random_bytes(6));
$gate = sys_get_temp_dir() . '/pluq-reconcile-gate-' . $token;
$resultPaths = [
    sys_get_temp_dir() . '/pluq-reconcile-a-' . $token . '.json',
    sys_get_temp_dir() . '/pluq-reconcile-b-' . $token . '.json',
];
$pids = [];

// Do not leave a live MySQL socket open across fork: children must establish
// their own PDO connections, and the parent reconnects after all children exit.
$pdo = null;

try {
    foreach ($resultPaths as $resultPath) {
        $pid = pcntl_fork();
        if ($pid === -1) {
            throw new RuntimeException('Could not fork reconciliation test worker.');
        }
        if ($pid === 0) {
            $deadline = microtime(true) + 10;
            while (!is_file($gate) && microtime(true) < $deadline) {
                usleep(1000);
            }
            try {
                if (!is_file($gate)) {
                    throw new RuntimeException('Timed out waiting for concurrent start gate.');
                }
                $childPdo = $connect();
                duitku_reconcile_admit_lookup(
                    $childPdo,
                    $paymentId,
                    null,
                    $merchantOrderId,
                    (int)$payment['amount'],
                    (string)$payment['payment_status'],
                    (string)$payment['order_payment_status'],
                    (string)$payment['order_status'],
                    '127.0.0.1'
                );
                $outcome = ['outcome' => 'ADMITTED'];
            } catch (DuitkuReconcileCooldownException $e) {
                $outcome = ['outcome' => 'COOLDOWN'];
            } catch (Throwable $e) {
                $outcome = ['outcome' => 'ERROR', 'error' => get_class($e) . ': ' . $e->getMessage()];
            }
            file_put_contents($resultPath, json_encode($outcome, JSON_THROW_ON_ERROR));
            exit(($outcome['outcome'] === 'ERROR') ? 1 : 0);
        }
        $pids[] = $pid;
    }

    touch($gate);
    $exitCodes = [];
    foreach ($pids as $pid) {
        pcntl_waitpid($pid, $status);
        $exitCodes[] = pcntl_wifexited($status) ? pcntl_wexitstatus($status) : 255;
    }

    $outcomes = [];
    foreach ($resultPaths as $resultPath) {
        reconcile_concurrency_assert(is_file($resultPath), 'Concurrent worker did not write its result.');
        $decoded = json_decode((string)file_get_contents($resultPath), true, 512, JSON_THROW_ON_ERROR);
        $outcomes[] = $decoded['outcome'] ?? 'MISSING';
        if (($decoded['outcome'] ?? '') === 'ERROR') {
            fwrite(STDERR, (string)($decoded['error'] ?? 'Unknown child error') . PHP_EOL);
        }
    }
    reconcile_concurrency_assert($exitCodes === [0, 0], 'A reconciliation worker exited unexpectedly.');
    sort($outcomes);
    reconcile_concurrency_assert($outcomes === ['ADMITTED', 'COOLDOWN'], 'Expected exactly one concurrent admission and one cooldown rejection; got: ' . implode(',', $outcomes));

    $pdo = $connect();
    $count = $pdo->prepare("SELECT COUNT(*) FROM audit_logs WHERE action='DUITKU_STATUS_LOOKUP' AND entity_type='PAYMENT_TRANSACTION' AND entity_id=:entity AND created_at >= DATE_SUB(NOW(), INTERVAL 60 SECOND)");
    $count->execute(['entity' => $entityId]);
    reconcile_concurrency_assert((int)$count->fetchColumn() === 1, 'Expected exactly one STARTED audit row for concurrent attempts.');

    echo "Concurrent MySQL Duitku reconciliation admission passed: one admitted, one cooldown-rejected, one audit marker; no gateway request was made.\n";
} finally {
    @unlink($gate);
    foreach ($resultPaths as $resultPath) {
        @unlink($resultPath);
    }
}
