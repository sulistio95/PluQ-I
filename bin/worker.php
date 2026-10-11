<?php
declare(strict_types=1);

/**
 * Scheduler entry point for shared hosting.
 * No supplier request is executed until a verified and tested handler exists.
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

$startedAt = microtime(true);
$pdo = database();
$pdo->prepare(
    "UPDATE job_queue SET status='RETRY', locked_at=NULL, lock_token=NULL,
     available_at=NOW(), last_error='Recovered expired worker lock'
     WHERE status='PROCESSING' AND locked_at < DATE_SUB(NOW(), INTERVAL 5 MINUTE)"
)->execute();

$statement = $pdo->prepare(
    "SELECT id, job_type FROM job_queue
     WHERE status IN ('PENDING','RETRY') AND available_at <= NOW()
     ORDER BY id ASC LIMIT :batch"
);
$statement->bindValue(':batch', $config['scheduler_batch_size'], PDO::PARAM_INT);
$statement->execute();
$jobs = $statement->fetchAll();

$unhandled = [];
foreach ($jobs as $job) {
    $unhandled[] = ['id' => (int) $job['id'], 'type' => (string) $job['job_type']];
    if (microtime(true) - $startedAt >= $config['scheduler_max_runtime_seconds']) {
        break;
    }
}

$result = [
    'status' => 'foundation_only',
    'jobs_visible' => count($jobs),
    'handlers_pending' => $unhandled,
    'message' => 'No jobs were executed: handler registration is not implemented yet.',
];

if (PHP_SAPI === 'cli') {
    fwrite(STDOUT, json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL);
    exit(0);
}
json_response($result, 503);
