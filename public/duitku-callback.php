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
    require_once PLUQI_ROOT.'/app/services/provider_reservations.php';
    $config=duitku_config();
    if (!duitku_verify_callback($config,$_POST)) json_response(['error'=>'invalid_signature'],400);
    $orderId=(string)$_POST['merchantOrderId'];
    $amount=(int)$_POST['amount'];
    $resultCode=(string)($_POST['resultCode']??'');
    if (!preg_match('/^[a-f0-9]{26}$/D',$orderId)||!in_array($resultCode,['00','01'],true)) json_response(['error'=>'invalid_callback'],400);
    $pdo=database();
    $pdo->beginTransaction();
    $q=$pdo->prepare("SELECT pt.id,pt.order_id,pt.amount,pt.status FROM payment_transactions pt WHERE pt.merchant_order_id=:oid AND pt.gateway='DUITKU' LIMIT 1 FOR UPDATE");
    $q->execute(['oid'=>$orderId]); $tx=$q->fetch();
    if (!$tx || (int)$tx['amount']!==$amount) { $pdo->rollBack(); json_response(['error'=>'order_mismatch'],400); }
    if ($tx['status']!=='PENDING') {
        // A late success can arrive after a failure callback was already recorded.
        // Never silently discard evidence of received money, but do not auto-fulfill:
        // the original reservation may have been released and provider capacity changed.
        if ($tx['status']==='FAILED' && $resultCode==='00') {
            $latePayload=json_encode([
                'merchantOrderId'=>$orderId,
                'amount'=>(string)$amount,
                'resultCode'=>$resultCode,
                'reference'=>(string)($_POST['reference']??''),
                'reconciliation'=>'late_success_after_failure'
            ],JSON_THROW_ON_ERROR);
            $q=$pdo->prepare("UPDATE payment_transactions SET status='PAID',gateway_reference=COALESCE(NULLIF(:ref,''),gateway_reference),callback_payload=:payload,callback_received_at=NOW() WHERE id=:id AND status='FAILED'");
            $q->execute(['ref'=>(string)($_POST['reference']??''),'payload'=>$latePayload,'id'=>$tx['id']]);
            if ($q->rowCount()===1) {
                $q=$pdo->prepare("UPDATE orders SET payment_status='PAID',order_status='MANUAL_REVIEW',paid_at=COALESCE(paid_at,NOW()),last_error='Late successful Duitku callback received after failure; manual payment and fulfillment reconciliation required' WHERE id=:id");
                $q->execute(['id'=>$tx['order_id']]);
            }
            $pdo->commit();
            json_response(['status'=>'manual_review']);
        }
        $pdo->commit();
        json_response(['status'=>'ok']);
    }
    $status=$resultCode==='00'?'PAID':'FAILED';
    $safe=json_encode(['merchantOrderId'=>$orderId,'amount'=>(string)$amount,'resultCode'=>$resultCode,'reference'=>(string)($_POST['reference']??'')],JSON_THROW_ON_ERROR);
    $q=$pdo->prepare("UPDATE payment_transactions SET status=:status,gateway_reference=COALESCE(NULLIF(:ref,''),gateway_reference),callback_payload=:payload,callback_received_at=NOW() WHERE id=:id AND status='PENDING'");
    $q->execute(['status'=>$status,'ref'=>(string)($_POST['reference']??''),'payload'=>$safe,'id'=>$tx['id']]);
    if ($status==='PAID') {
        $q=$pdo->prepare("UPDATE orders SET payment_status='PAID',order_status='QUEUED',paid_at=NOW() WHERE id=:id AND payment_status='PENDING'");
        $q->execute(['id'=>$tx['order_id']]);
        if ($q->rowCount() === 1) {
            // A valid payment must also have provider capacity reserved before fulfillment
            // is queued. If the hold expired and capacity was consumed by other orders,
            // keep the payment PAID and route the order to manual review.
            if (provider_reservation_commit_paid($pdo, (int)$tx['order_id'])) {
                // Queue only on the first valid PENDING -> PAID transition.
                $q=$pdo->prepare("INSERT INTO job_queue (job_type,payload,status,available_at) VALUES ('FULFILL_ORDER',:payload,'PENDING',NOW())");
                $q->execute(['payload'=>json_encode(['order_id'=>(int)$tx['order_id']],JSON_THROW_ON_ERROR)]);
            } else {
                $q=$pdo->prepare("UPDATE orders SET order_status='MANUAL_REVIEW',last_error='Payment received but provider capacity could not be reserved; manual reconciliation required' WHERE id=:id AND payment_status='PAID'");
                $q->execute(['id'=>$tx['order_id']]);
            }
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
        $q=$pdo->prepare("UPDATE orders SET payment_status='FAILED' WHERE id=:id AND payment_status='PENDING'");
        $q->execute(['id'=>$tx['order_id']]);
        provider_reservation_release($pdo, (int)$tx['order_id']);
    }
    $pdo->commit();
    json_response(['status'=>'ok']);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
    json_response(['error'=>'callback_unavailable'],503);
}
