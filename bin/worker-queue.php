<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';
require_once PLUQI_ROOT . '/config/database.php';
require_once PLUQI_ROOT . '/app/services/fulfillment.php';

if (PHP_SAPI !== 'cli') {
    $provided = request_header('X-Scheduler-Token');
    $expected = $config['scheduler_token'];
    if ($expected === '' || $provided === '' || !hash_equals($expected, $provided)) {
        json_response(['error' => 'unauthorized'], 401);
    }
}
if (!fulfillment_enabled()) {
    $result = ['status' => 'disabled', 'processed' => 0];
    if (PHP_SAPI === 'cli') { fwrite(STDOUT, json_encode($result) . PHP_EOL); exit(0); }
    json_response($result, 503);
}

$pdo = database();
$processed = 0;
$results = [];
$start = microtime(true);
while ($processed < $config['scheduler_batch_size'] && microtime(true) - $start < $config['scheduler_max_runtime_seconds']) {
    $token = bin2hex(random_bytes(16));
    $pdo->beginTransaction();
    try {
        $q = $pdo->query("SELECT id,job_type,payload,attempts FROM job_queue WHERE status IN ('PENDING','RETRY') AND available_at<=NOW() ORDER BY id LIMIT 1 FOR UPDATE");
        $job = $q->fetch();
        if (!$job) { $pdo->commit(); break; }
        $pdo->prepare("UPDATE job_queue SET status='PROCESSING',attempts=attempts+1,locked_at=NOW(),lock_token=:token WHERE id=:id")
            ->execute(['token'=>$token,'id'=>$job['id']]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        break;
    }
    $outcome = 'FAILED';
    try {
        $payload = json_decode((string)$job['payload'], true, 512, JSON_THROW_ON_ERROR);
        if ($job['job_type'] !== 'FULFILL_ORDER' || !is_array($payload)) throw new DomainException('Unsupported job.');
        $orderId = filter_var($payload['order_id'] ?? null, FILTER_VALIDATE_INT);
        if (!$orderId || $orderId < 1) throw new DomainException('Invalid order ID.');
        $outcome = fulfillment_process($pdo, (int)$orderId);
    } catch (Throwable $e) {
        $outcome = 'ERROR';
    }
    if (in_array($outcome, ['DONE','IGNORED'], true)) {
        $pdo->prepare("UPDATE job_queue SET status='DONE',locked_at=NULL,lock_token=NULL,last_error=NULL WHERE id=:id AND lock_token=:token")
            ->execute(['id'=>$job['id'],'token'=>$token]);
    } elseif ($outcome === 'RETRY' && ((int)$job['attempts'] + 1) < 3) {
        $pdo->prepare("UPDATE job_queue SET status='RETRY',available_at=DATE_ADD(NOW(),INTERVAL 60 SECOND),locked_at=NULL,lock_token=NULL,last_error='Supplier status pending; recheck scheduled' WHERE id=:id AND lock_token=:token")
            ->execute(['id'=>$job['id'],'token'=>$token]);
    } else {
        $pdo->prepare("UPDATE job_queue SET status='FAILED',locked_at=NULL,lock_token=NULL,last_error='Manual review required; inspect the order and supplier transaction' WHERE id=:id AND lock_token=:token")
            ->execute(['id'=>$job['id'],'token'=>$token]);
    }
    $results[] = ['job_id'=>(int)$job['id'],'outcome'=>$outcome];
    $processed++;
}
$result = ['status'=>'processed','processed'=>$processed,'results'=>$results];
if (PHP_SAPI === 'cli') { fwrite(STDOUT, json_encode($result, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) . PHP_EOL); exit(0); }
json_response($result);
