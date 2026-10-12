<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/bootstrap.php';
header('Cache-Control: no-store');
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    json_response(['error'=>'method_not_allowed'],405);
}
try {
    require_once PLUQI_ROOT.'/config/database.php';
    require_once PLUQI_ROOT.'/app/services/duitku.php';
    $config=duitku_config();
    if (!duitku_verify_callback($config,$_POST)) json_response(['error'=>'invalid_signature'],400);
    $orderId=(string)$_POST['merchantOrderId'];
    $amountRaw=(string)$_POST['amount'];
    $amount=filter_var($amountRaw,FILTER_VALIDATE_INT);
    $resultCode=(string)($_POST['resultCode']??'');
    if (!preg_match('/^[a-f0-9]{26}$/D',$orderId)||$amount===false||$amount<1||!in_array($resultCode,['00','01'],true)) json_response(['error'=>'invalid_callback'],400);
    $pdo=database();
    $pdo->beginTransaction();
    $q=$pdo->prepare("SELECT pt.id,pt.order_id,pt.amount,pt.status FROM payment_transactions pt WHERE pt.merchant_order_id=:oid AND pt.gateway='DUITKU' LIMIT 1 FOR UPDATE");
    $q->execute(['oid'=>$orderId]); $tx=$q->fetch();
    if (!$tx || (int)$tx['amount']!==$amount) { $pdo->rollBack(); json_response(['error'=>'order_mismatch'],400); }
    if ($tx['status']!=='PENDING') { $pdo->commit(); json_response(['status'=>'ok']); }
    $safe=json_encode(['merchantOrderId'=>$orderId,'amount'=>(string)$amount,'resultCode'=>$resultCode,'reference'=>(string)($_POST['reference']??'')],JSON_THROW_ON_ERROR);
    $status=duitku_callback_result_state($resultCode);
    // Duitku's server-to-server callback uses 00 for success and 01 for failed payment.
    $q=$pdo->prepare("UPDATE payment_transactions SET status=:status,gateway_reference=COALESCE(NULLIF(:ref,''),gateway_reference),callback_payload=:payload,callback_received_at=NOW() WHERE id=:id AND status='PENDING'");
    $q->execute(['status'=>$status,'ref'=>(string)($_POST['reference']??''),'payload'=>$safe,'id'=>$tx['id']]);
    if ($status==='PAID') {
        $q=$pdo->prepare("UPDATE orders SET payment_status='PAID',order_status='QUEUED',paid_at=NOW() WHERE id=:id AND payment_status='PENDING'");
        $q->execute(['id'=>$tx['order_id']]);
        if ($q->rowCount() === 1) {
            // Queue only when this callback performed the first valid PENDING -> PAID transition.
            // This prevents a stale/inconsistent payment row from enqueuing duplicate fulfillment.
            $q=$pdo->prepare("INSERT INTO job_queue (job_type,payload,status,available_at) VALUES ('FULFILL_ORDER',:payload,'PENDING',NOW())");
            $q->execute(['payload'=>json_encode(['order_id'=>(int)$tx['order_id']],JSON_THROW_ON_ERROR)]);
        } else {
            $q=$pdo->prepare("SELECT payment_status FROM orders WHERE id=:id LIMIT 1 FOR UPDATE");
            $q->execute(['id'=>$tx['order_id']]);
            $orderPaymentStatus=(string)$q->fetchColumn();
            if ($orderPaymentStatus !== 'PAID') {
                // Money was reported as paid, but the order could not make the normal transition.
                // Keep the payment recorded and require reconciliation rather than silently dropping it.
                $q=$pdo->prepare("UPDATE orders SET payment_status='PAID',order_status='MANUAL_REVIEW',paid_at=COALESCE(paid_at,NOW()),last_error='Paid callback received after an incompatible order state; manual reconciliation required' WHERE id=:id");
                $q->execute(['id'=>$tx['order_id']]);
            }
        }
    } else {
        // A definitive failed-payment callback closes a still-pending order too.
        // Do not overwrite any later order state if data is already inconsistent.
        $q=$pdo->prepare("UPDATE orders
            SET payment_status='FAILED',
                order_status=CASE WHEN order_status='PENDING' THEN 'FAILED' ELSE order_status END
            WHERE id=:id AND payment_status='PENDING'");
        $q->execute(['id'=>$tx['order_id']]);
    }
    $pdo->commit();
    json_response(['status'=>'ok']);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
    json_response(['error'=>'callback_unavailable'],503);
}
