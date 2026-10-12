<?php
declare(strict_types=1);

require_once __DIR__ . '/checkout_quote.php';
require_once __DIR__ . '/duitku.php';

function checkout_request_fingerprint(string $email, string $gameSlug, int $productId, array $account): string
{
    if ($productId < 1 || array_is_list($account) || count($account) > 20) {
        throw new InvalidArgumentException('Data checkout tidak valid.');
    }
    $normalizedAccount = [];
    foreach ($account as $field => $value) {
        if (!is_string($field) || !is_scalar($value)) {
            throw new InvalidArgumentException('Data akun tidak valid.');
        }
        $normalizedAccount[$field] = trim((string)$value);
    }
    ksort($normalizedAccount, SORT_STRING);
    return hash('sha256', json_encode([
        'email' => strtolower(trim($email)),
        'game_slug' => trim($gameSlug),
        'product_id' => $productId,
        'account' => $normalizedAccount,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
}

function checkout_is_idempotency_race(PDOException $e): bool
{
    $sqlState = (string)$e->getCode();
    $driverCode = (int)($e->errorInfo[1] ?? 0);
    return $sqlState === '23000'
        || $sqlState === '40001'
        || in_array($driverCode, [1062, 1205, 1213], true);
}

function checkout_idempotency_key_is_valid(string $key): bool
{
    return preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/D', strtolower(trim($key))) === 1;
}

/**
 * Wait briefly for the winning request to persist its gateway URL. Order/payment rows
 * are committed before the gateway call, so a concurrent request may see the winner
 * before the external response has been saved.
 */
function checkout_reuse_winning_request(PDO $pdo, string $checkoutKey, string $fingerprint, int $waitMilliseconds = 22000): array
{
    $deadline = microtime(true) + max(0, $waitMilliseconds) / 1000;
    do {
        $stmt = $pdo->prepare("SELECT o.public_id,o.checkout_payload_hash,pt.gateway_payment_url
            FROM orders o LEFT JOIN payment_transactions pt ON pt.order_id=o.id
            WHERE o.checkout_key=:key LIMIT 1");
        $stmt->execute(['key' => $checkoutKey]);
        $existing = $stmt->fetch();
        if ($existing) {
            if (!is_string($existing['checkout_payload_hash'])
                || !hash_equals($existing['checkout_payload_hash'], $fingerprint)) {
                throw new DomainException('Kunci idempotensi sudah dipakai untuk data checkout berbeda atau pesanan lama perlu diperiksa admin.');
            }
            if (!empty($existing['gateway_payment_url'])) {
                return ['order_id' => $existing['public_id'], 'payment_url' => $existing['gateway_payment_url'], 'reused' => true];
            }
        }
        if (microtime(true) >= $deadline) break;
        usleep(200000);
    } while (true);

    throw new DomainException('Permintaan sudah tercatat tetapi tautan pembayaran belum tersedia; perlu pemeriksaan admin.');
}

function checkout_create_payment(PDO $pdo, array $input, ?callable $paymentCreator = null): array
{
    if (!checkout_payments_enabled()) {
        throw new DomainException('Checkout pembayaran belum diaktifkan. Pesanan belum dibuat dan tidak ada pembayaran yang diminta.');
    }
    $email = trim((string)($input['email'] ?? ''));
    $key = strtolower(trim((string)($input['idempotency_key'] ?? '')));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 255) {
        throw new InvalidArgumentException('Email tidak valid.');
    }
    if (!checkout_idempotency_key_is_valid($key)) {
        throw new InvalidArgumentException('Kunci permintaan tidak valid.');
    }
    $productId = filter_var($input['product_id'] ?? null, FILTER_VALIDATE_INT);
    if (!$productId || $productId < 1) throw new InvalidArgumentException('Produk tidak valid.');
    $gameSlug = trim((string)($input['game_slug'] ?? ''));
    $account = $input['account'] ?? null;
    if (!is_array($account) || array_is_list($account) || count($account) > 20) {
        throw new InvalidArgumentException('Data akun tidak valid.');
    }
    $fingerprint = checkout_request_fingerprint($email, $gameSlug, (int)$productId, $account);

    $config = duitku_config();
    $checkoutKey = hash('sha256', $key);
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("SELECT o.public_id,o.checkout_payload_hash,pt.gateway_payment_url FROM orders o
          LEFT JOIN payment_transactions pt ON pt.order_id=o.id
          WHERE o.checkout_key=:key LIMIT 1 FOR UPDATE");
        $stmt->execute(['key' => $checkoutKey]);
        $old = $stmt->fetch();
        if ($old) {
            if (!is_string($old['checkout_payload_hash']) || !hash_equals($old['checkout_payload_hash'], $fingerprint)) {
                throw new DomainException('Kunci idempotensi sudah dipakai untuk data checkout berbeda atau pesanan lama perlu diperiksa admin.');
            }
            $pdo->commit();
            return checkout_reuse_winning_request($pdo, $checkoutKey, $fingerprint);
        }

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
          (public_id,checkout_key,checkout_payload_hash,game_id,product_id,provider_id,customer_email,customer_game_data,base_price,selling_price,admin_fee,payment_status,order_status)
          VALUES (:public_id,:checkout_key,:fingerprint,:game_id,:product_id,:provider_id,:email,:game_data,:base,:selling,:fee,'PENDING','PENDING')");
        $stmt->execute([
          'public_id'=>$publicId,'checkout_key'=>$checkoutKey,'fingerprint'=>$fingerprint,'game_id'=>$refs['game_id'],
          'product_id'=>$productId,'provider_id'=>$refs['provider_id'],'email'=>$email,'game_data'=>$gameData,
          'base'=>$quote['price']['base_price'],'selling'=>$quote['price']['selling_price'],'fee'=>$quote['price']['admin_fee']
        ]);
        $orderDbId = (int)$pdo->lastInsertId();
        $amount = (int)$quote['price']['total'];
        $stmt = $pdo->prepare("INSERT INTO payment_transactions (order_id,gateway,merchant_order_id,amount,status)
          VALUES (:order_id,'DUITKU',:merchant_order_id,:amount,'PENDING')");
        $stmt->execute(['order_id'=>$orderDbId,'merchant_order_id'=>$publicId,'amount'=>$amount]);
        $pdo->commit();
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if (checkout_is_idempotency_race($e)) {
            // A concurrent request may win the unique-key insert or make this transaction
            // deadlock while locking the same absent key. Re-read after rollback; never
            // repeat the external Duitku request from this recovery path.
            return checkout_reuse_winning_request($pdo, $checkoutKey, $fingerprint);
        }
        throw $e;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }

    try {
        $creator = $paymentCreator ?? 'duitku_create_payment';
        $payment = $creator($config,$publicId,$amount,$quote['game']['name'].' - '.$quote['product']['name'],$email);
        // A fast callback may mark the transaction paid before this response is persisted.
        // Store the payment URL regardless of current status, without overwriting a callback reference.
        $stmt = $pdo->prepare("UPDATE payment_transactions
          SET gateway_reference=COALESCE(gateway_reference,:reference),
              gateway_payment_url=:url,
              gateway_expiry_at=COALESCE(gateway_expiry_at,DATE_ADD(NOW(),INTERVAL 30 MINUTE))
          WHERE merchant_order_id=:id");
        $stmt->execute(['reference'=>$payment['reference'],'url'=>$payment['payment_url'],'id'=>$publicId]);
        return ['order_id'=>$publicId,'payment_url'=>$payment['payment_url'],'reused'=>false];
    } catch (Throwable $e) {
        $stmt = $pdo->prepare("UPDATE orders SET last_error='Duitku sandbox request needs manual review' WHERE public_id=:id AND payment_status='PENDING'");
        $stmt->execute(['id'=>$publicId]);
        throw new RuntimeException('Pesanan tercatat, tetapi tautan pembayaran belum tersedia. Hubungi admin untuk pemeriksaan.');
    }
}
