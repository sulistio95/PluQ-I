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
    $amount=(int)$_POST['amount'];
    $resultCode=(string)($_POST['resultCode']??'');
    if (!preg_match('/^[a-f0-9]{26}$/D',$orderId)||!in_array($resultCode,['00','01'],true)) json_response(['error'=>'invalid_callback'],400);
    $pdo=database();
    $pdo->beginTransaction();
    $q=$pdo->prepare("SELECT pt.id,pt.order_id,pt.amount,pt.status FROM payment_transactions pt WHERE pt.merchant_order_id=:oid AND pt.gateway='DUITKU' LIMIT 1 FOR UPDATE");
    $q->execute(['oid'=>$orderId]); $tx=$q->fetch();
    if (!$tx || (int)$tx['amount']!==$amount) { $pdo->rollBack(); json_response(['error'=>'order_mismatch'],400); }
    if ($tx['status']!=='PENDING') { $pdo->commit(); json_response(['status'=>'ok']); }
    $status=$resultCode==='00'?'PAID':'FAILED';
    $safe=json_encode(['merchantOrderId'=>$orderId,'amount'=>(string)$amount,'resultCode'=>$resultCode,'reference'=>(string)($_POST['reference']??'')],JSON_THROW_ON_ERROR);
    $q=$pdo->prepare("UPDATE payment_transactions SET status=:status,gateway_reference=COALESCE(NULLIF(:ref,''),gateway_reference),callback_payload=:payload,callback_received_at=NOW() WHERE id=:id AND status='PENDING'");
    $q->execute(['status'=>$status,'ref'=>(string)($_POST['reference']??''),'payload'=>$safe,'id'=>$tx['id']]);
    if ($status==='PAID') {
        $q=$pdo->prepare("UPDATE orders SET payment_status='PAID',order_status='QUEUED',paid_at=NOW() WHERE id=:id AND payment_status='PENDING'");
        $q->execute(['id'=>$tx['order_id']]);
        $q=$pdo->prepare("INSERT INTO job_queue (job_type,payload,status,available_at) VALUES ('FULFILL_ORDER',:payload,'PENDING',NOW())");
        $q->execute(['payload'=>json_encode(['order_id'=>(int)$tx['order_id']],JSON_THROW_ON_ERROR)]);
    } else {
        $q=$pdo->prepare("UPDATE orders SET payment_status='FAILED' WHERE id=:id AND payment_status='PENDING'");
        $q->execute(['id'=>$tx['order_id']]);
    }
    $pdo->commit();
    json_response(['status'=>'ok']);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
    json_response(['error'=>'callback_unavailable'],503);
}
