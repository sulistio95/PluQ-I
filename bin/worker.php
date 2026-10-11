<?php
declare(strict_types=1);

/**
 * Read-only scheduler diagnostic for shared hosting.
 *
 * This legacy entry point must never reset expired locks or change job states.
 * The queue worker with explicit stale-lock reconciliation is bin/worker-queue.php.
 * Supplier execution remains disabled unless SUPPLIER_ORDERS_ENABLED=true.
 */
require dirname(__DIR__) . '/app/bootstrap.php';
require_once PLUQI_ROOT . '/config/database.php';

if (PHP_SAPI !== 'cli') {
    $provided = request_header('X-Scheduler-Token');
    $expected = $config['scheduler_token'];
    if ($expected === '' || $provided === '' || !hash_equals($expected, $provided)) {
        json_response(['error' => 'unauthorized'], 401);
    }
}

$pdo = database();
$statement = $pdo->prepare(
    "SELECT status, COUNT(*) AS total
     FROM job_queue
     GROUP BY status
     ORDER BY status"
);
$statement->execute();
$counts = [];
foreach ($statement->fetchAll() as $row) {
    $counts[(string) $row['status']] = (int) $row['total'];
}

$pending = $pdo->query(
    "SELECT id, job_type, status, attempts, available_at
     FROM job_queue
     WHERE status IN ('PENDING','RETRY','PROCESSING','FAILED')
     ORDER BY id DESC
     LIMIT 50"
)->fetchAll();

$result = [
    'status' => 'read_only_diagnostic',
    'queue_counts' => $counts,
    'recent_jobs' => $pending,
    'message' => 'No queue state was changed. Use bin/worker-queue.php only after staging verification.',
];

if (PHP_SAPI === 'cli') {
    fwrite(STDOUT, json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL);
    exit(0);
}
json_response($result, 503);
