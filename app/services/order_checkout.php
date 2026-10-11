<?php
declare(strict_types=1);

require_once __DIR__ . '/checkout_quote.php';
require_once __DIR__ . '/duitku.php';

function checkout_create_payment(PDO $pdo, array $input): array
{
    $email = trim((string)($input['email'] ?? ''));
    $key = strtolower(trim((string)($input['idempotency_key'] ?? '')));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 255) {
        throw new InvalidArgumentException('Email tidak valid.');
    }
    if (!preg_match('/^[a-f0-9-]{36}$/D', $key)) {
        throw new InvalidArgumentException('Kunci permintaan tidak valid.');
    }
    $config = duitku_config();
    $checkoutKey = hash('sha256', $key);
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("SELECT o.public_id,pt.gateway_payment_url FROM orders o
          LEFT JOIN payment_transactions pt ON pt.order_id=o.id
          WHERE o.checkout_key=:key LIMIT 1 FOR UPDATE");
        $stmt->execute(['key' => $checkoutKey]);
        $old = $stmt->fetch();
        if ($old) {
            $pdo->commit();
            if (!empty($old['gateway_payment_url'])) {
                return ['order_id' => $old['public_id'], 'payment_url' => $old['gateway_payment_url'], 'reused' => true];
            }
            throw new DomainException('Permintaan sudah tercatat dan perlu diperiksa admin.');
        }

        $productId = filter_var($input['product_id'] ?? null, FILTER_VALIDATE_INT);
        if (!$productId || $productId < 1) throw new InvalidArgumentException('Produk tidak valid.');
        $lock = $pdo->prepare('SELECT p.id FROM products p JOIN providers pr ON pr.id=p.provider_id WHERE p.id=:id FOR UPDATE');
        $lock->execute(['id' => $productId]);
        if (!$lock->fetch()) throw new DomainException('Produk tidak tersedia.');

        $quote = checkout_quote($pdo, $input);
        $stmt = $pdo->prepare('SELECT p.provider_id,g.id AS game_id FROM products p JOIN games g ON g.id=p.game_id WHERE p.id=:id AND g.slug=:slug AND g.status=\'ACTIVE\'');
        $stmt->execute(['id' => $productId, 'slug' => $quote['game']['slug']]);
        $refs = $stmt->fetch();
        if (!$refs) throw new DomainException('Game atau produk tidak tersedia.');

        $publicId = bin2hex(random_bytes(13));
        $gameData = json_encode($quote['account'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $stmt = $pdo->prepare("INSERT INTO orders
          (public_id,checkout_key,game_id,product_id,provider_id,customer_email,customer_game_data,base_price,selling_price,admin_fee,payment_status,order_status)
          VALUES (:public_id,:checkout_key,:game_id,:product_id,:provider_id,:email,:game_data,:base,:selling,:fee,'PENDING','PENDING')");
        $stmt->execute([
          'public_id'=>$publicId,'checkout_key'=>$checkoutKey,'game_id'=>$refs['game_id'],
          'product_id'=>$productId,'provider_id'=>$refs['provider_id'],'email'=>$email,'game_data'=>$gameData,
          'base'=>$quote['price']['base_price'],'selling'=>$quote['price']['selling_price'],'fee'=>$quote['price']['admin_fee']
        ]);
        $orderDbId = (int)$pdo->lastInsertId();
        $amount = (int)$quote['price']['total'];
        $stmt = $pdo->prepare("INSERT INTO payment_transactions (order_id,gateway,merchant_order_id,amount,status)
          VALUES (:order_id,'DUITKU',:merchant_order_id,:amount,'PENDING')");
        $stmt->execute(['order_id'=>$orderDbId,'merchant_order_id'=>$publicId,'amount'=>$amount]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }

    try {
        $payment = duitku_create_payment($config,$publicId,$amount,$quote['game']['name'].' - '.$quote['product']['name'],$email);
        $stmt = $pdo->prepare("UPDATE payment_transactions SET gateway_reference=:reference,gateway_payment_url=:url,gateway_expiry_at=DATE_ADD(NOW(),INTERVAL 30 MINUTE) WHERE merchant_order_id=:id AND status='PENDING'");
        $stmt->execute(['reference'=>$payment['reference'],'url'=>$payment['payment_url'],'id'=>$publicId]);
        return ['order_id'=>$publicId,'payment_url'=>$payment['payment_url'],'reused'=>false];
    } catch (Throwable $e) {
        $stmt = $pdo->prepare("UPDATE orders SET last_error='Duitku sandbox request needs manual review' WHERE public_id=:id AND payment_status='PENDING'");
        $stmt->execute(['id'=>$publicId]);
        throw new RuntimeException('Pesanan tercatat, tetapi tautan pembayaran belum tersedia. Hubungi admin untuk pemeriksaan.');
    }
}
