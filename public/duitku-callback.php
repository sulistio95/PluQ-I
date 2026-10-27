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
    require_once PLUQI_ROOT.'/app/services/checkout_quote.php';
    require_once PLUQI_ROOT.'/app/services/provider_budget.php';
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
    $status=duitku_callback_result_state($resultCode);
    if ($tx['status']!=='PENDING') {
        if ($tx['status'] !== $status) {
            // Record the contradiction for human reconciliation. A late success after a failed
            // callback is recorded as PAID, but the order is never queued automatically.
            $details=json_encode([
                'gateway'=>'DUITKU',
                'merchant_order_id'=>$orderId,
                'stored_payment_status'=>(string)$tx['status'],
                'incoming_payment_status'=>$status,
                'incoming_result_code'=>$resultCode,
                'amount'=>(string)$amount,
                'reference'=>(string)($_POST['reference']??''),
                'resolution'=>($tx['status']==='FAILED' && $status==='PAID') ? 'late success recorded as paid; order requires manual reconciliation' : 'payment state preserved; order requires manual reconciliation',
            ],JSON_THROW_ON_ERROR);
            if ($tx['status']==='FAILED' && $status==='PAID') {
                // Duitku confirms money was received after an earlier failure callback.
                // Correct the payment record, but keep fulfillment blocked until an admin reconciles it.
                $latePayload=json_encode([
                    'merchantOrderId'=>$orderId,
                    'amount'=>(string)$amount,
                    'resultCode'=>$resultCode,
                    'reference'=>(string)($_POST['reference']??''),
                    'late_success_manual_review'=>true,
                ],JSON_THROW_ON_ERROR);
                $q=$pdo->prepare("UPDATE payment_transactions
                    SET status='PAID',
                        gateway_reference=COALESCE(NULLIF(:ref,''),gateway_reference),
                        callback_payload=:payload,
                        callback_received_at=NOW()
                    WHERE id=:id AND status='FAILED'");
                $q->execute(['ref'=>(string)($_POST['reference']??''),'payload'=>$latePayload,'id'=>$tx['id']]);
                $q=$pdo->prepare("UPDATE orders
                    SET payment_status='PAID',
                        order_status='MANUAL_REVIEW',
                        paid_at=COALESCE(paid_at,NOW()),
                        last_error='Late successful Duitku callback after a recorded failure; manual payment and fulfillment reconciliation required'
                    WHERE id=:id");
                $q->execute(['id'=>$tx['order_id']]);
            }
            $q=$pdo->prepare("UPDATE orders SET order_status='MANUAL_REVIEW',last_error='Conflicting terminal Duitku callback; payment state preserved for manual reconciliation' WHERE id=:id AND order_status<>'MANUAL_REVIEW'");
            $q->execute(['id'=>$tx['order_id']]);
            // A contradictory callback moves the order out of automatic fulfillment.
            // Keep any active provider capacity reserved for manual reconciliation.
            provider_budget_resolve(
                $pdo,
                (int)$tx['order_id'],
                'REVIEW',
                'Conflicting terminal Duitku callback; reservation retained for manual reconciliation'
            );
            // Gateway retries are expected. Record each distinct stored/incoming status conflict
            // once per order so repeated identical callbacks cannot flood the audit log.
            $q=$pdo->prepare("SELECT id FROM audit_logs
                WHERE action='DUITKU_TERMINAL_CALLBACK_CONFLICT'
                  AND entity_type='ORDER'
                  AND entity_id=:entity_id
                  AND JSON_UNQUOTE(JSON_EXTRACT(details, '$.stored_payment_status'))=:stored_status
                  AND JSON_UNQUOTE(JSON_EXTRACT(details, '$.incoming_payment_status'))=:incoming_status
                LIMIT 1 FOR UPDATE");
            $q->execute([
                'entity_id'=>(string)$tx['order_id'],
                'stored_status'=>(string)$tx['status'],
                'incoming_status'=>$status,
            ]);
            if ($q->fetchColumn() === false) {
                $q=$pdo->prepare("INSERT INTO audit_logs (actor_type,action,entity_type,entity_id,details) VALUES ('GATEWAY','DUITKU_TERMINAL_CALLBACK_CONFLICT','ORDER',:entity_id,:details)");
                $q->execute(['entity_id'=>(string)$tx['order_id'],'details'=>$details]);
            }
        }
        $pdo->commit();
        json_response(['status'=>'ok']);
    }
    $safe=json_encode(['merchantOrderId'=>$orderId,'amount'=>(string)$amount,'resultCode'=>$resultCode,'reference'=>(string)($_POST['reference']??'')],JSON_THROW_ON_ERROR);
    // Duitku's server-to-server callback uses 00 for success and 01 for failed payment.
    $q=$pdo->prepare("UPDATE payment_transactions SET status=:status,gateway_reference=COALESCE(NULLIF(:ref,''),gateway_reference),callback_payload=:payload,callback_received_at=NOW() WHERE id=:id AND status='PENDING'");
    $q->execute(['status'=>$status,'ref'=>(string)($_POST['reference']??''),'payload'=>$safe,'id'=>$tx['id']]);
    if ($status==='PAID' && checkout_sandbox_only_enabled()) {
        // Sandbox-only orders are payment-flow tests, never real fulfillment requests.
        // Keep the successful gateway result visible, but force manual review and do not enqueue a supplier job.
        $q=$pdo->prepare("UPDATE orders
            SET payment_status='PAID',
                order_status='MANUAL_REVIEW',
                paid_at=COALESCE(paid_at,NOW()),
                last_error='Duitku sandbox payment test recorded; supplier fulfillment intentionally disabled'
            WHERE id=:id");
        $q->execute(['id'=>$tx['order_id']]);
    } elseif ($status==='PAID') {
        $q=$pdo->prepare("UPDATE orders SET payment_status='PAID',order_status='PENDING',paid_at=COALESCE(paid_at,NOW()) WHERE id=:id AND payment_status='PENDING'");
        $q->execute(['id'=>$tx['order_id']]);
        if ($q->rowCount() === 1) {
            // Reserve provider capacity in the same transaction before a supplier job can be queued.
            // The provider-row lock serializes concurrent paid callbacks against one usable-balance budget.
            $reservation=provider_budget_reserve_paid_order($pdo,(int)$tx['order_id']);
            if (!empty($reservation['reserved'])) {
                $q=$pdo->prepare("UPDATE orders SET order_status='QUEUED',last_error=NULL WHERE id=:id AND payment_status='PAID' AND order_status='PENDING'");
                $q->execute(['id'=>$tx['order_id']]);
                if ($q->rowCount() === 1) {
                    $q=$pdo->prepare("INSERT INTO job_queue (job_type,payload,status,available_at) VALUES ('FULFILL_ORDER',:payload,'PENDING',NOW())");
                    $q->execute(['payload'=>json_encode(['order_id'=>(int)$tx['order_id']],JSON_THROW_ON_ERROR)]);
                } else {
                    throw new RuntimeException('Order state changed while queueing a reserved order.');
                }
            } else {
                // Payment is real, but capacity is unavailable. Never discard the paid state or send
                // an unreserved order to the supplier; surface it for human reconciliation.
                $q=$pdo->prepare("UPDATE orders SET order_status='MANUAL_REVIEW',last_error=:reason WHERE id=:id AND payment_status='PAID'");
                $q->execute([
                    'reason'=>'Payment received but provider budget reservation failed (' . (string)$reservation['status'] . '); supplier order not queued',
                    'id'=>$tx['order_id']
                ]);
            }
        } else {
            $q=$pdo->prepare("SELECT payment_status,order_status FROM orders WHERE id=:id LIMIT 1 FOR UPDATE");
            $q->execute(['id'=>$tx['order_id']]);
            $existingOrder=$q->fetch(PDO::FETCH_ASSOC);
            if (!$existingOrder || $existingOrder['payment_status'] !== 'PAID') {
                // Money was reported as paid, but the order could not make the normal transition.
                $q=$pdo->prepare("UPDATE orders SET payment_status='PAID',order_status='MANUAL_REVIEW',paid_at=COALESCE(paid_at,NOW()),last_error='Paid callback received after an incompatible order state; manual reconciliation required' WHERE id=:id");
                $q->execute(['id'=>$tx['order_id']]);
            } elseif ($existingOrder['order_status'] === 'QUEUED') {
                // Repeated callbacks must not enqueue a second job. A previously queued order must
                // already have its unique budget reservation; missing reservation requires review.
                $check=$pdo->prepare("SELECT status FROM provider_budget_reservations WHERE order_id=:id LIMIT 1 FOR UPDATE");
                $check->execute(['id'=>$tx['order_id']]);
                if ($check->fetchColumn() === false) {
                    $q=$pdo->prepare("UPDATE orders SET order_status='MANUAL_REVIEW',last_error='Queued paid order has no provider budget reservation; manual reconciliation required' WHERE id=:id");
                    $q->execute(['id'=>$tx['order_id']]);
                }
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
