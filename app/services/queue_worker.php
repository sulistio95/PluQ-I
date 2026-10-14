<?php
declare(strict_types=1);

/**
 * Finalize a claimed queue job only while the same worker still owns its lease.
 * Lock order is queue -> order -> supplier, matching stale-lock recovery.
 */
function queue_finalize_job(PDO $pdo, array $job, string $token, string $outcome): string
{
    $pdo->beginTransaction();
    try {
        $leaseQuery = $pdo->prepare("SELECT status,lock_token FROM job_queue WHERE id=:id LIMIT 1 FOR UPDATE");
        $leaseQuery->execute(['id' => $job['id']]);
        $lease = $leaseQuery->fetch();
        if (!$lease || $lease['status'] !== 'PROCESSING'
            || !is_string($lease['lock_token']) || !hash_equals($token, $lease['lock_token'])) {
            $pdo->commit();
            return 'LOCK_LOST';
        }

        if (in_array($outcome, ['DONE', 'IGNORED'], true)) {
            $pdo->prepare("UPDATE job_queue SET status='DONE',locked_at=NULL,lock_token=NULL,last_error=NULL WHERE id=:id AND status='PROCESSING' AND lock_token=:token")
                ->execute(['id' => $job['id'], 'token' => $token]);
        } elseif ($outcome === 'RETRY' && ((int)$job['attempts'] + 1) < 3) {
            $pdo->prepare("UPDATE job_queue SET status='RETRY',available_at=DATE_ADD(NOW(),INTERVAL 60 SECOND),locked_at=NULL,lock_token=NULL,last_error='Supplier status pending; recheck scheduled' WHERE id=:id AND status='PROCESSING' AND lock_token=:token")
                ->execute(['id' => $job['id'], 'token' => $token]);
        } else {
            $message = $outcome === 'RETRY'
                ? 'Retry limit reached; manual reconciliation required; automatic retry blocked'
                : ($outcome === 'REVIEW'
                    ? 'Supplier outcome requires manual reconciliation; no automatic retry or refund'
                    : 'Worker failed unexpectedly; inspect supplier and order state before retry or refund');
            if ($outcome === 'RETRY' || $outcome === 'ERROR') {
                $jobPayload = json_decode((string)$job['payload'], true);
                $reviewOrderId = filter_var(is_array($jobPayload) ? ($jobPayload['order_id'] ?? null) : null, FILTER_VALIDATE_INT);
                if ($reviewOrderId && $reviewOrderId > 0) {
                    $orderLock = $pdo->prepare("SELECT id,payment_status,order_status FROM orders WHERE id=:id LIMIT 1 FOR UPDATE");
                    $orderLock->execute(['id' => $reviewOrderId]);
                    $reviewOrder = $orderLock->fetch();
                    $supplierLock = $pdo->prepare("SELECT status FROM supplier_transactions WHERE order_id=:id LIMIT 1 FOR UPDATE");
                    $supplierLock->execute(['id' => $reviewOrderId]);
                    $reviewSupplier = $supplierLock->fetch();
                    if ($reviewOrder && $reviewOrder['payment_status'] === 'PAID'
                        && in_array($reviewOrder['order_status'], ['QUEUED', 'PROCESSING', 'RETRYING'], true)) {
                        // Do not rewrite an order that another path has already moved to a
                        // terminal or explicitly reviewed state while this job was running.
                        $pdo->prepare("UPDATE orders SET order_status='MANUAL_REVIEW',last_error=:error WHERE id=:id AND payment_status='PAID' AND order_status IN ('QUEUED','PROCESSING','RETRYING')")
                            ->execute(['error' => $message, 'id' => $reviewOrderId]);
                        if ($reviewSupplier && in_array($reviewSupplier['status'], ['PENDING', 'PROCESSING', 'RETRYING'], true)) {
                            $pdo->prepare("UPDATE supplier_transactions SET status='REVIEW',last_error=:error WHERE order_id=:id AND status IN ('PENDING','PROCESSING','RETRYING')")
                                ->execute(['error' => $message, 'id' => $reviewOrderId]);
                        }
                    }
                }
            }
            $pdo->prepare("UPDATE job_queue SET status='FAILED',locked_at=NULL,lock_token=NULL,last_error=:error WHERE id=:id AND status='PROCESSING' AND lock_token=:token")
                ->execute(['error' => $message, 'id' => $job['id'], 'token' => $token]);
        }
        $pdo->commit();
        return 'FINALIZED';
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        return 'FINALIZE_ERROR';
    }
}
