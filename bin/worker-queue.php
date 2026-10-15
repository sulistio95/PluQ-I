<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';
require_once PLUQI_ROOT . '/config/database.php';
require_once PLUQI_ROOT . '/app/services/fulfillment.php';
require_once PLUQI_ROOT . '/app/services/queue_worker.php';

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

// Expired locks are never requeued automatically: the supplier may have accepted
// the request before the previous PHP process stopped. Move the job to manual
// review and require provider reconciliation before any retry or refund.
$pdo->beginTransaction();
try {
    $staleQuery = $pdo->query("SELECT id,job_type,payload FROM job_queue
        WHERE status='PROCESSING' AND locked_at IS NOT NULL
          AND locked_at < DATE_SUB(NOW(), INTERVAL 10 MINUTE)
        ORDER BY locked_at ASC LIMIT 50 FOR UPDATE");
    $staleJobs = $staleQuery->fetchAll();
    foreach ($staleJobs as $staleJob) {
        $orderId = null;
        if ($staleJob['job_type'] === 'FULFILL_ORDER') {
            try {
                $stalePayload = json_decode((string)$staleJob['payload'], true, 512, JSON_THROW_ON_ERROR);
                $candidate = filter_var(is_array($stalePayload) ? ($stalePayload['order_id'] ?? null) : null, FILTER_VALIDATE_INT);
                if ($candidate && $candidate > 0) $orderId = (int)$candidate;
            } catch (Throwable $ignored) {
                $orderId = null;
            }
        }

        $queueStatus = 'FAILED';
        $queueMessage = 'Worker lock expired; manual reconciliation required; automatic retry blocked';
        if ($orderId !== null) {
            $orderQuery = $pdo->prepare("SELECT id,payment_status,order_status FROM orders WHERE id=:id LIMIT 1 FOR UPDATE");
            $orderQuery->execute(['id'=>$orderId]);
            $staleOrder = $orderQuery->fetch();
            $supplierQuery = $pdo->prepare("SELECT status FROM supplier_transactions WHERE order_id=:id LIMIT 1 FOR UPDATE");
            $supplierQuery->execute(['id'=>$orderId]);
            $staleSupplier = $supplierQuery->fetch();

            if ($staleOrder && $staleOrder['payment_status'] === 'PAID'
                && $staleOrder['order_status'] === 'SUCCESS') {
                // An already successful order is terminal; reconcile its ledger idempotently.
                provider_budget_resolve($pdo, $orderId, 'SETTLED', 'Queue recovery confirmed persisted order success');
                $queueStatus = 'DONE';
                $queueMessage = null;
            } elseif ($staleOrder && $staleOrder['payment_status'] === 'PAID'
                && in_array($staleOrder['order_status'], ['QUEUED','PROCESSING','RETRYING'], true)
                && $staleSupplier && $staleSupplier['status'] === 'SUCCESS') {
                // Recover supplier success only from an automatic fulfillment state.
                // MANUAL_REVIEW can have unrelated causes (for example a payment callback
                // conflict) and must never be cleared automatically by stale-lock recovery.
                $pdo->prepare("UPDATE orders SET order_status='SUCCESS',fulfilled_at=COALESCE(fulfilled_at,NOW()),last_error=NULL WHERE id=:id AND payment_status='PAID' AND order_status IN ('QUEUED','PROCESSING','RETRYING')")
                    ->execute(['id'=>$orderId]);
                provider_budget_resolve($pdo, $orderId, 'SETTLED', 'Queue recovery confirmed supplier success');
                $queueStatus = 'DONE';
                $queueMessage = null;
            } elseif ($staleOrder && $staleOrder['payment_status'] === 'PAID'
                && !in_array($staleOrder['order_status'], ['SUCCESS','REFUNDED'], true)) {
                $pdo->prepare("UPDATE orders SET order_status='MANUAL_REVIEW',last_error=:error WHERE id=:id AND order_status<>'MANUAL_REVIEW'")
                    ->execute(['error'=>$queueMessage,'id'=>$orderId]);
                $pdo->prepare("UPDATE supplier_transactions SET status='REVIEW',last_error=:error WHERE order_id=:id AND status IN ('PENDING','PROCESSING','RETRYING')")
                    ->execute(['error'=>$queueMessage,'id'=>$orderId]);
                provider_budget_resolve($pdo, $orderId, 'REVIEW', $queueMessage);
            }
        }

        $pdo->prepare("UPDATE job_queue SET status=:status,locked_at=NULL,lock_token=NULL,last_error=:error
            WHERE id=:id AND status='PROCESSING'")
            ->execute(['status'=>$queueStatus,'error'=>$queueMessage,'id'=>$staleJob['id']]);
    }
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    // Fail closed: do not process new supplier jobs if stale-lock recovery failed.
    if (PHP_SAPI === 'cli') { fwrite(STDERR, "Queue lock recovery failed; no jobs processed.\\n"); exit(1); }
    json_response(['error'=>'queue_recovery_failed'], 503);
}

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
    $finalized = queue_finalize_job($pdo, $job, $token, $outcome);
    if ($finalized === 'LOCK_LOST') {
        $results[] = ['job_id'=>(int)$job['id'],'outcome'=>'LOCK_LOST'];
        $processed++;
        continue;
    }
    if ($finalized === 'FINALIZE_ERROR') {
        $results[] = ['job_id'=>(int)$job['id'],'outcome'=>'FINALIZE_ERROR'];
        $processed++;
        continue;
    }
    $results[] = ['job_id'=>(int)$job['id'],'outcome'=>$outcome];
    $processed++;
}
$result = ['status'=>'processed','processed'=>$processed,'results'=>$results];
if (PHP_SAPI === 'cli') { fwrite(STDOUT, json_encode($result, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) . PHP_EOL); exit(0); }
json_response($result);
