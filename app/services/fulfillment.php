<?php
declare(strict_types=1);

require_once __DIR__ . '/digiflazz.php';
require_once __DIR__ . '/vipayment.php';

function fulfillment_enabled(): bool
{
    return filter_var(getenv('SUPPLIER_ORDERS_ENABLED') ?: 'false', FILTER_VALIDATE_BOOLEAN);
}

function fulfillment_account(array $data): array
{
    // Supplier account payloads must be mapped by semantic field names, never by array order.
    // A wrong ID/zone pairing can top up the wrong account, so unknown or conflicting keys fail closed.
    $userAliases = ['user_id','userid','user','id','target','data_no','player','player_id','account_id'];
    $zoneAliases = ['zone_id','zone','server','data_zone'];
    $users = [];
    $zones = [];

    foreach ($data as $key => $value) {
        if (!is_string($key) || !is_scalar($value)) {
            throw new DomainException('Account fields cannot be mapped safely.');
        }
        $key = strtolower(trim($key));
        $value = trim((string) $value);
        if ($value === '') continue;
        if (in_array($key, $userAliases, true)) {
            $users[$value] = true;
        } elseif (in_array($key, $zoneAliases, true)) {
            $zones[$value] = true;
        } else {
            throw new DomainException('Unknown account field cannot be mapped safely.');
        }
    }

    if (count($users) !== 1 || count($zones) > 1) {
        throw new DomainException('Account fields are missing or ambiguous.');
    }
    $user = (string) array_key_first($users);
    $zone = $zones ? (string) array_key_first($zones) : '';
    if ($user === '' || strlen($user) > 190 || strlen($zone) > 190) {
        throw new DomainException('Account fields cannot be mapped safely.');
    }
    return ['user'=>$user,'zone'=>$zone];
}

function fulfillment_digiflazz(array $order, array $account, string $reference): array
{
    $cfg = digiflazz_config();
    $payload = [
        'username'=>$cfg['username'],
        'buyer_sku_code'=>(string)$order['provider_product_code'],
        'customer_no'=>$account['zone'] !== '' ? $account['user'] . $account['zone'] : $account['user'],
        'ref_id'=>$reference,
        'sign'=>md5($cfg['username'] . $cfg['api_key'] . $reference),
    ];
    if (filter_var(getenv('DIGIFLAZZ_TESTING') ?: 'false', FILTER_VALIDATE_BOOLEAN)) $payload['testing'] = true;
    $response = provider_http_json($cfg['base_url'] . '/transaction', $payload);
    return fulfillment_map_digiflazz_response($response, $reference);
}

function fulfillment_map_digiflazz_response(array $response, string $reference): array
{
    $data = $response['data'] ?? null;
    if (!is_array($data) || !isset($data['status']) || !is_scalar($data['status'])) {
        throw new RuntimeException('Digiflazz response has no status.');
    }
    $status = strtolower(trim((string)$data['status']));
    $state = $status === 'sukses' ? 'SUCCESS' : ($status === 'pending' ? 'PENDING' : ($status === 'gagal' ? 'FAILED' : 'REVIEW'));
    return ['state'=>$state,'reference'=>(string)($data['sn'] ?? $reference),'response'=>$response];
}

function fulfillment_vipayment(array $order, array $account, ?string $trxid): array
{
    if ($trxid !== null && $trxid !== '') {
        $response = vipayment_post('/game-feature', ['type'=>'status','trxid'=>$trxid]);
    } else {
        $response = vipayment_post('/game-feature', [
            'type'=>'order',
            'service'=>(string)$order['provider_product_code'],
            'data_no'=>$account['user'],
            'data_zone'=>$account['zone'],
        ]);
    }
    return fulfillment_map_vipayment_response($response, $trxid);
}

function fulfillment_map_vipayment_response(array $response, ?string $requestedTrxid = null): array
{
    if ($requestedTrxid !== null && $requestedTrxid !== '') {
        $rows = $response['data'] ?? null;
        if (!is_array($rows) || !isset($rows[0]) || !is_array($rows[0])) {
            return ['state'=>'REVIEW','reference'=>$requestedTrxid,'response'=>$response];
        }
        $row = $rows[0];
        if (!is_array($row)) {
            return ['state'=>'REVIEW','reference'=>$requestedTrxid,'response'=>$response];
        }
        $returnedTrxid = trim((string)($row['trxid'] ?? ''));
        if ($returnedTrxid === '' || !hash_equals($requestedTrxid, $returnedTrxid)) {
            return ['state'=>'REVIEW','reference'=>$requestedTrxid,'response'=>$response];
        }
    } else {
        $row = $response['data'] ?? null;
        if (!is_array($row) || empty($row['trxid']) || !isset($row['status'])) {
            return ['state'=>'REVIEW','reference'=>'','response'=>$response];
        }
    }
    if (!isset($row['status']) || !is_scalar($row['status'])) {
        return ['state'=>'REVIEW','reference'=>(string)($row['trxid'] ?? $requestedTrxid ?? ''),'response'=>$response];
    }
    $status = strtolower(trim((string)$row['status']));
    $state = in_array($status,['success','sukses','berhasil'],true) ? 'SUCCESS'
        : (in_array($status,['error','failed','gagal'],true) ? 'FAILED'
        : (in_array($status,['waiting','processing','pending'],true) ? 'PENDING' : 'REVIEW'));
    return ['state'=>$state,'reference'=>(string)($row['trxid'] ?? $requestedTrxid ?? ''),'response'=>$response];
}

/**
 * Record a definitive supplier rejection without rewriting the payment outcome.
 * Payment remains PAID; refund processing is represented only by the refunds table.
 */
function fulfillment_record_explicit_failure(PDO $pdo, int $orderId, string $reason = 'Supplier explicitly rejected the order'): void
{
    $pdo->beginTransaction();
    try {
        $q = $pdo->prepare("SELECT id, payment_status FROM orders WHERE id=:id LIMIT 1 FOR UPDATE");
        $q->execute(['id' => $orderId]);
        $order = $q->fetch();
        if (!$order || $order['payment_status'] !== 'PAID') {
            throw new DomainException('Only a paid order can enter supplier-failure refund review.');
        }

        $pdo->prepare("UPDATE orders SET order_status='FAILED',last_error=:reason WHERE id=:id AND payment_status='PAID'")
            ->execute(['reason' => $reason, 'id' => $orderId]);

        $q = $pdo->prepare("SELECT id FROM refunds WHERE order_id=:id LIMIT 1 FOR UPDATE");
        $q->execute(['id' => $orderId]);
        if (!$q->fetch()) {
            $pdo->prepare("INSERT INTO refunds (order_id,amount,status,reason)
                SELECT id,selling_price+admin_fee,'PENDING',:reason FROM orders WHERE id=:id AND payment_status='PAID'")
                ->execute(['reason' => $reason, 'id' => $orderId]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

/** Processes a paid order. Network uncertainty is never blindly retried. */
function fulfillment_process(PDO $pdo, int $orderId): string
{
    if (!fulfillment_enabled()) throw new DomainException('Supplier ordering is disabled.');
    $pdo->beginTransaction();
    try {
        $q=$pdo->prepare("SELECT o.*,p.provider_product_code,pr.code AS provider_code
          FROM orders o JOIN products p ON p.id=o.product_id JOIN providers pr ON pr.id=o.provider_id
          WHERE o.id=:id LIMIT 1 FOR UPDATE");
        $q->execute(['id'=>$orderId]); $order=$q->fetch();
        if (!$order || $order['payment_status']!=='PAID') { $pdo->commit(); return 'IGNORED'; }
        if ($order['order_status']==='SUCCESS') { $pdo->commit(); return 'DONE'; }
        $reference='PQI-'.$order['public_id'];
        $q=$pdo->prepare("SELECT *, (updated_at >= DATE_SUB(NOW(), INTERVAL 10 MINUTE)) AS is_recent FROM supplier_transactions WHERE order_id=:id LIMIT 1 FOR UPDATE");
        $q->execute(['id'=>$orderId]); $supplier=$q->fetch() ?: null;
        if (!$supplier) {
            $q=$pdo->prepare("INSERT INTO supplier_transactions (order_id,provider_id,reference_id,status,attempts) VALUES (:oid,:pid,:ref,'PROCESSING',1)");
            $q->execute(['oid'=>$orderId,'pid'=>$order['provider_id'],'ref'=>$reference]);
        } elseif ($supplier['status']==='PROCESSING' && (int)($supplier['is_recent'] ?? 0) === 1) {
            // A second queue entry may encounter the first worker while its supplier HTTP call is still active.
            // Treat a fresh PROCESSING record as an active lease, not as proof of a crash; the duplicate job can finish safely.
            $pdo->commit();
            return 'IGNORED';
        } elseif ($supplier['status']==='PROCESSING' ||
            ($supplier['status']==='RETRYING' && strtoupper((string)$order['provider_code'])==='DIGI')) {
            // A prior request may have been accepted before a crash or timeout. Digiflazz retry semantics
            // are not verified here, so neither ambiguous state is safe for automatic resubmission.
            $pdo->prepare("UPDATE orders SET order_status='MANUAL_REVIEW',last_error='Supplier request outcome is ambiguous; manual reconciliation required' WHERE id=:id AND payment_status='PAID'")->execute(['id'=>$orderId]);
            $pdo->prepare("UPDATE supplier_transactions SET status='REVIEW',last_error='Ambiguous prior request; automatic resubmission blocked' WHERE order_id=:id")->execute(['id'=>$orderId]);
            $pdo->commit();
            return 'REVIEW';
        } elseif ($supplier['status']==='SUCCESS') {
            $pdo->prepare("UPDATE orders SET order_status='SUCCESS',fulfilled_at=COALESCE(fulfilled_at,NOW()) WHERE id=:id")->execute(['id'=>$orderId]);
            $pdo->commit(); return 'DONE';
        } elseif ($supplier['status']==='FAILED') {
            // Recover the crash window where the definitive supplier failure was saved,
            // but the refund row was not yet created. The helper is idempotent and keeps payment=PAID.
            $pdo->commit();
            fulfillment_record_explicit_failure($pdo, $orderId, 'Supplier explicitly rejected the order');
            return 'FAILED';
        }
        $account=json_decode((string)$order['customer_game_data'],true,512,JSON_THROW_ON_ERROR);
        if (!is_array($account)) throw new DomainException('Saved account data is invalid.');
        $pdo->prepare("UPDATE orders SET order_status='PROCESSING',retry_count=retry_count+1 WHERE id=:id")->execute(['id'=>$orderId]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }

    try {
        $provider=strtoupper((string)$order['provider_code']);
        $account=fulfillment_account($account);
        if ($provider==='DIGI') $result=fulfillment_digiflazz($order,$account,$reference);
        elseif ($provider==='VIPAY') $result=fulfillment_vipayment($order,$account, !empty($supplier['provider_reference']) ? (string)$supplier['provider_reference'] : null);
        else throw new DomainException('Unsupported supplier code.');
    } catch (Throwable $e) {
        // A timeout, transport error, or unexpected API response does not prove the supplier rejected the order.
        // Lock order first, matching stale-lock recovery, and never overwrite a newer REVIEW decision.
        $pdo->beginTransaction();
        try {
            $orderLock=$pdo->prepare("SELECT payment_status,order_status FROM orders WHERE id=:id LIMIT 1 FOR UPDATE");
            $orderLock->execute(['id'=>$orderId]);
            $lockedOrder=$orderLock->fetch(PDO::FETCH_ASSOC);
            $supplierLock=$pdo->prepare("SELECT status FROM supplier_transactions WHERE order_id=:id LIMIT 1 FOR UPDATE");
            $supplierLock->execute(['id'=>$orderId]);
            $lockedSupplier=$supplierLock->fetch(PDO::FETCH_ASSOC);
            if ($lockedOrder && $lockedOrder['payment_status']==='PAID'
                && in_array($lockedOrder['order_status'],['PROCESSING','RETRYING'],true)
                && $lockedSupplier && in_array($lockedSupplier['status'],['PROCESSING','RETRYING'],true)) {
                $pdo->prepare("UPDATE supplier_transactions SET status='REVIEW',last_error='Supplier outcome uncertain; manual review required' WHERE order_id=:id AND status IN ('PROCESSING','RETRYING')")
                    ->execute(['id'=>$orderId]);
                $pdo->prepare("UPDATE orders SET order_status='MANUAL_REVIEW',last_error='Supplier outcome uncertain; manual review required' WHERE id=:id AND payment_status='PAID' AND order_status IN ('PROCESSING','RETRYING')")
                    ->execute(['id'=>$orderId]);
            }
            $pdo->commit();
        } catch (Throwable $persistError) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $persistError;
        }
        return 'REVIEW';
    }

    $safe=json_encode($result['response'],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
    $state=$result['state'];
    $txStatus=$state==='SUCCESS'?'SUCCESS':($state==='PENDING'?'RETRYING':($state==='FAILED'?'FAILED':'REVIEW'));
    $reason=$state==='REVIEW'
        ? 'Supplier response is ambiguous or unrecognized; manual reconciliation required'
        : ($state==='FAILED' ? 'Supplier explicitly rejected the order' : null);

    // Serialize supplier response persistence with stale-lock recovery using the same lock order:
    // order first, supplier transaction second. This prevents a late response from reviving an
    // order already moved to MANUAL_REVIEW, and avoids supplier->order lock inversion.
    $pdo->beginTransaction();
    try {
        $orderLock=$pdo->prepare("SELECT payment_status,order_status FROM orders WHERE id=:id LIMIT 1 FOR UPDATE");
        $orderLock->execute(['id'=>$orderId]);
        $lockedOrder=$orderLock->fetch(PDO::FETCH_ASSOC);
        $supplierLock=$pdo->prepare("SELECT status FROM supplier_transactions WHERE order_id=:id LIMIT 1 FOR UPDATE");
        $supplierLock->execute(['id'=>$orderId]);
        $lockedSupplier=$supplierLock->fetch(PDO::FETCH_ASSOC);

        if (!$lockedOrder || $lockedOrder['payment_status']!=='PAID'
            || !in_array($lockedOrder['order_status'],['PROCESSING','RETRYING'],true)
            || !$lockedSupplier || !in_array($lockedSupplier['status'],['PROCESSING','RETRYING'],true)) {
            $pdo->rollBack();
            return 'REVIEW';
        }

        $updateSupplier=$pdo->prepare("UPDATE supplier_transactions
            SET provider_reference=NULLIF(:ref,''),status=:status,attempts=attempts+1,
                response_payload=:response,last_error=:error
            WHERE order_id=:id AND status IN ('PROCESSING','RETRYING')");
        $updateSupplier->execute([
            'ref'=>$result['reference'],'status'=>$txStatus,'response'=>$safe,
            'error'=>$reason,'id'=>$orderId
        ]);
        if ($updateSupplier->rowCount() !== 1) {
            $pdo->rollBack();
            return 'REVIEW';
        }

        if ($state==='SUCCESS') {
            $pdo->prepare("UPDATE orders SET order_status='SUCCESS',supplier_reference=:ref,
                fulfilled_at=NOW(),last_error=NULL WHERE id=:id AND payment_status='PAID'
                AND order_status IN ('PROCESSING','RETRYING')")
                ->execute(['ref'=>$result['reference'],'id'=>$orderId]);
        } elseif ($state==='PENDING') {
            $pdo->prepare("UPDATE orders SET order_status='RETRYING',
                last_error='Supplier processing; status will be checked again'
                WHERE id=:id AND payment_status='PAID' AND order_status IN ('PROCESSING','RETRYING')")
                ->execute(['id'=>$orderId]);
        } elseif ($state==='REVIEW') {
            $pdo->prepare("UPDATE orders SET order_status='MANUAL_REVIEW',last_error=:reason
                WHERE id=:id AND payment_status='PAID' AND order_status IN ('PROCESSING','RETRYING')")
                ->execute(['reason'=>$reason,'id'=>$orderId]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }

    if ($state==='SUCCESS') return 'DONE';
    if ($state==='PENDING') return 'RETRY';
    if ($state==='REVIEW') return 'REVIEW';
    fulfillment_record_explicit_failure($pdo, $orderId, 'Supplier explicitly rejected the order');
    return 'FAILED';
}
