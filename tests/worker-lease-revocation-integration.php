<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once PLUQI_ROOT . '/config/database.php';
require_once PLUQI_ROOT . '/app/services/queue_worker.php';

$pdo = database();
$providerQuery = $pdo->query("SELECT id FROM providers WHERE code='DIGI' LIMIT 1");
$providerId = $providerQuery->fetchColumn();
if (!$providerId) {
    fwrite(STDERR, "Missing DIGI provider row; apply the base schema and migrations first.\n");
    exit(1);
}

$token = bin2hex(random_bytes(8));
$gameId = null;
$productId = null;
$publicId = 'leaseguard' . bin2hex(random_bytes(8));
$reference = 'PQI-' . $publicId;
$orderId = null;
$jobId = null;
$oldToken = bin2hex(random_bytes(16));

try {
    // Create an isolated fixture so this integration test does not depend on a
    // pre-seeded catalog or mutate shared catalog data.
    $pdo->beginTransaction();
    $q = $pdo->prepare("INSERT INTO games (slug,name,status) VALUES (:slug,'Lease revocation integration','ACTIVE')");
    $q->execute(['slug' => 'lease-guard-' . $token]);
    $gameId = (int)$pdo->lastInsertId();

    $q = $pdo->prepare("INSERT INTO products
        (game_id,provider_id,provider_product_code,name,base_price,selling_price,status)
        VALUES (:game_id,:provider_id,:sku,'Lease revocation integration product',1000,1500,'ACTIVE')");
    $q->execute(['game_id' => $gameId, 'provider_id' => (int)$providerId, 'sku' => 'lease-guard-' . $token]);
    $productId = (int)$pdo->lastInsertId();
    $q = $pdo->prepare("INSERT INTO orders
        (public_id,game_id,product_id,provider_id,customer_game_data,base_price,selling_price,admin_fee,payment_status,order_status)
        VALUES (:public_id,:game_id,:product_id,:provider_id,:account,1000,1500,1000,'PAID','PROCESSING')");
    $q->execute([
        'public_id'=>$publicId,'game_id'=>$gameId,'product_id'=>$productId,
        'provider_id'=>(int)$providerId,'account'=>json_encode(['user_id'=>'123'],JSON_THROW_ON_ERROR),
    ]);
    $orderId = (int)$pdo->lastInsertId();

    $q = $pdo->prepare("INSERT INTO supplier_transactions (order_id,provider_id,reference_id,status,attempts)
        VALUES (:order_id,:provider_id,:reference,'PROCESSING',1)");
    $q->execute(['order_id'=>$orderId,'provider_id'=>(int)$providerId,'reference'=>$reference]);

    $q = $pdo->prepare("INSERT INTO job_queue (job_type,payload,status,attempts,available_at,locked_at,lock_token)
        VALUES ('FULFILL_ORDER',:payload,'PROCESSING',1,NOW(),NOW(),:token)");
    $q->execute(['payload'=>json_encode(['order_id'=>$orderId],JSON_THROW_ON_ERROR),'token'=>$oldToken]);
    $jobId = (int)$pdo->lastInsertId();
    $pdo->commit();

    // Model stale-lock recovery winning the race: it revokes the lease and
    // moves the order/supplier to review before the original worker returns.
    $pdo->beginTransaction();
    $pdo->prepare("UPDATE job_queue SET status='FAILED',locked_at=NULL,lock_token=NULL,last_error='expired lease' WHERE id=:id")
        ->execute(['id'=>$jobId]);
    $pdo->prepare("UPDATE orders SET order_status='MANUAL_REVIEW',last_error='expired lease' WHERE id=:id")
        ->execute(['id'=>$orderId]);
    $pdo->prepare("UPDATE supplier_transactions SET status='REVIEW',last_error='expired lease' WHERE order_id=:id")
        ->execute(['id'=>$orderId]);
    $pdo->commit();

    $job = ['id'=>$jobId,'attempts'=>1,'payload'=>json_encode(['order_id'=>$orderId],JSON_THROW_ON_ERROR)];
    $result = queue_finalize_job($pdo,$job,$oldToken,'RETRY');
    if ($result !== 'LOCK_LOST') {
        throw new RuntimeException("Expected LOCK_LOST, got {$result}");
    }

    $q=$pdo->prepare("SELECT status,lock_token FROM job_queue WHERE id=:id");
    $q->execute(['id'=>$jobId]); $queue=$q->fetch();
    $q=$pdo->prepare("SELECT order_status FROM orders WHERE id=:id");
    $q->execute(['id'=>$orderId]); $orderStatus=$q->fetchColumn();
    $q=$pdo->prepare("SELECT status FROM supplier_transactions WHERE order_id=:id");
    $q->execute(['id'=>$orderId]); $supplierStatus=$q->fetchColumn();

    if (!$queue || $queue['status']!=='FAILED' || $queue['lock_token']!==null
        || $orderStatus!=='MANUAL_REVIEW' || $supplierStatus!=='REVIEW') {
        throw new RuntimeException("Revoked lease changed protected state: queue="
            .var_export($queue,true).", order=".var_export($orderStatus,true)
            .", supplier=".var_export($supplierStatus,true));
    }

    // A worker that still owns its queue lease may nevertheless race with an
    // independent order-state transition. Error finalization must not rewrite
    // terminal/refund/review states back into MANUAL_REVIEW.
    foreach (['FAILED', 'REFUND_PENDING', 'REFUNDED', 'MANUAL_REVIEW'] as $protectedStatus) {
        $freshToken = bin2hex(random_bytes(16));
        $pdo->prepare("UPDATE orders SET order_status=:status,last_error='pre-existing protected state' WHERE id=:id")
            ->execute(['status' => $protectedStatus, 'id' => $orderId]);
        $pdo->prepare("UPDATE job_queue SET status='PROCESSING',locked_at=NOW(),lock_token=:token,last_error=NULL WHERE id=:id")
            ->execute(['token' => $freshToken, 'id' => $jobId]);

        $finalized = queue_finalize_job($pdo, $job, $freshToken, 'ERROR');
        if ($finalized !== 'FINALIZED') {
            throw new RuntimeException("Expected error finalization for protected state to complete, got {$finalized}");
        }
        $q = $pdo->prepare("SELECT order_status FROM orders WHERE id=:id");
        $q->execute(['id' => $orderId]);
        $actualStatus = $q->fetchColumn();
        if ($actualStatus !== $protectedStatus) {
            throw new RuntimeException("Queue error overwrote protected order status {$protectedStatus} with {$actualStatus}");
        }
    }

    echo "Revoked worker lease cannot finalize protected state; queue errors preserve terminal, refund, and review order states.\\n";
} finally {
    if ($pdo->inTransaction()) $pdo->rollBack();
    if ($jobId !== null || $orderId !== null) {
        $pdo->beginTransaction();
        try {
            if ($jobId !== null) $pdo->prepare("DELETE FROM job_queue WHERE id=:id")->execute(['id'=>$jobId]);
            if ($orderId !== null) {
                $pdo->prepare("DELETE FROM supplier_transactions WHERE order_id=:id")->execute(['id'=>$orderId]);
                $pdo->prepare("DELETE FROM refunds WHERE order_id=:id")->execute(['id'=>$orderId]);
                $pdo->prepare("DELETE FROM orders WHERE id=:id")->execute(['id'=>$orderId]);
            }
            if ($productId !== null) $pdo->prepare("DELETE FROM products WHERE id=:id")->execute(['id'=>$productId]);
            if ($gameId !== null) $pdo->prepare("DELETE FROM games WHERE id=:id")->execute(['id'=>$gameId]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }
}
