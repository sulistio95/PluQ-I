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
    $user = '';
    $zone = '';
    foreach ($data as $key => $value) {
        if (!is_scalar($value)) continue;
        $key = strtolower((string)$key);
        if (in_array($key, ['user_id','userid','user','id','target','data_no'], true)) $user = trim((string)$value);
        if (in_array($key, ['zone_id','zone','server','data_zone'], true)) $zone = trim((string)$value);
    }
    if ($user === '') {
        $values = [];
        foreach ($data as $key => $value) {
            if (!is_scalar($value)) continue;
            $normalizedKey = strtolower((string)$key);
            if (in_array($normalizedKey, ['zone_id','zone','server','data_zone'], true)) continue;
            $value = trim((string)$value);
            if ($value !== '') $values[] = $value;
        }
        if (count($values) === 1) $user = $values[0];
        if (count($values) === 2) { $user = $values[0]; $zone = $values[1]; }
    }
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
    $data = $response['data'] ?? null;
    if (!is_array($data) || !isset($data['status'])) throw new RuntimeException('Digiflazz response has no status.');
    $status = strtolower((string)$data['status']);
    $state = $status === 'sukses' ? 'SUCCESS' : ($status === 'pending' ? 'PENDING' : ($status === 'gagal' ? 'FAILED' : 'REVIEW'));
    return ['state'=>$state,'reference'=>(string)($data['sn'] ?? $reference),'response'=>$response];
}

function fulfillment_vipayment(array $order, array $account, ?string $trxid): array
{
    if ($trxid !== null && $trxid !== '') {
        $response = vipayment_post('/game-feature', ['type'=>'status','trxid'=>$trxid]);
        $rows = $response['data'] ?? null;
        if (!is_array($rows) || !isset($rows[0]) || !is_array($rows[0])) return ['state'=>'REVIEW','reference'=>$trxid,'response'=>$response];
        $row = $rows[0];
    } else {
        $response = vipayment_post('/game-feature', [
            'type'=>'order',
            'service'=>(string)$order['provider_product_code'],
            'data_no'=>$account['user'],
            'data_zone'=>$account['zone'],
        ]);
        $row = $response['data'] ?? null;
        if (!is_array($row) || empty($row['trxid']) || !isset($row['status'])) return ['state'=>'REVIEW','reference'=>'','response'=>$response];
    }
    $status = strtolower((string)($row['status'] ?? ''));
    $state = in_array($status,['success','sukses','berhasil'],true) ? 'SUCCESS'
        : (in_array($status,['error','failed','gagal'],true) ? 'FAILED'
        : (in_array($status,['waiting','processing','pending'],true) ? 'PENDING' : 'REVIEW'));
    return ['state'=>$state,'reference'=>(string)($row['trxid'] ?? ''),'response'=>$response];
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
        $q=$pdo->prepare('SELECT * FROM supplier_transactions WHERE order_id=:id LIMIT 1 FOR UPDATE');
        $q->execute(['id'=>$orderId]); $supplier=$q->fetch() ?: null;
        if (!$supplier) {
            $q=$pdo->prepare("INSERT INTO supplier_transactions (order_id,provider_id,reference_id,status,attempts) VALUES (:oid,:pid,:ref,'PROCESSING',1)");
            $q->execute(['oid'=>$orderId,'pid'=>$order['provider_id'],'ref'=>$reference]);
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
        } elseif ($supplier['status']==='FAILED') { $pdo->commit(); return 'FAILED'; }
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
        // Preserve the paid payment state and require reconciliation; do not create a refund task automatically.
        $pdo->prepare("UPDATE supplier_transactions SET status='REVIEW',last_error='Supplier outcome uncertain; manual review required' WHERE order_id=:id")->execute(['id'=>$orderId]);
        $pdo->prepare("UPDATE orders SET order_status='MANUAL_REVIEW',last_error='Supplier outcome uncertain; manual review required' WHERE id=:id AND payment_status='PAID'")->execute(['id'=>$orderId]);
        return 'REVIEW';
    }

    $safe=json_encode($result['response'],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
    $state=$result['state'];
    $txStatus=$state==='SUCCESS'?'SUCCESS':($state==='PENDING'?'RETRYING':($state==='FAILED'?'FAILED':'REVIEW'));
    $pdo->prepare("UPDATE supplier_transactions SET provider_reference=NULLIF(:ref,''),status=:status,attempts=attempts+1,response_payload=:response,last_error=:error WHERE order_id=:id")
      ->execute(['ref'=>$result['reference'],'status'=>$txStatus,'response'=>$safe,'error'=>$state==='REVIEW'?'Supplier response requires manual review':null,'id'=>$orderId]);
    if ($state==='SUCCESS') {
        $pdo->prepare("UPDATE orders SET order_status='SUCCESS',supplier_reference=:ref,fulfilled_at=NOW(),last_error=NULL WHERE id=:id")->execute(['ref'=>$result['reference'],'id'=>$orderId]);
        return 'DONE';
    }
    if ($state==='PENDING') {
        $pdo->prepare("UPDATE orders SET order_status='RETRYING',last_error='Supplier processing; status will be checked again' WHERE id=:id")->execute(['id'=>$orderId]);
        return 'RETRY';
    }
    if ($state==='REVIEW') {
        $reason='Supplier response is ambiguous or unrecognized; manual reconciliation required';
        $pdo->prepare("UPDATE supplier_transactions SET status='REVIEW',last_error=:reason WHERE order_id=:id")->execute(['reason'=>$reason,'id'=>$orderId]);
        $pdo->prepare("UPDATE orders SET order_status='MANUAL_REVIEW',last_error=:reason WHERE id=:id AND payment_status='PAID'")->execute(['reason'=>$reason,'id'=>$orderId]);
        return 'REVIEW';
    }
    $reason='Supplier explicitly rejected the order';
    $pdo->prepare("UPDATE orders SET order_status='FAILED',payment_status='REFUND_PENDING',last_error=:reason WHERE id=:id")->execute(['reason'=>$reason,'id'=>$orderId]);
    $pdo->prepare("INSERT INTO refunds (order_id,amount,status,reason) SELECT id,selling_price+admin_fee,'PENDING',:reason FROM orders WHERE id=:id AND NOT EXISTS (SELECT 1 FROM refunds WHERE order_id=:id2 AND status IN ('PENDING','PROCESSING','SUCCESS'))")->execute(['reason'=>$reason,'id'=>$orderId,'id2'=>$orderId]);
    return 'FAILED';
}
