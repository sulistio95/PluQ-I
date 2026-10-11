<?php
declare(strict_types=1);

require_once __DIR__ . '/pricing.php';

function checkout_quote(PDO $pdo, array $input): array
{
    $slug = trim((string) ($input['game_slug'] ?? ''));
    $productId = filter_var($input['product_id'] ?? null, FILTER_VALIDATE_INT);
    $account = $input['account'] ?? null;

    if (!preg_match('/^[a-zA-Z0-9_-]{1,150}$/D', $slug)) {
        throw new InvalidArgumentException('Game tidak valid.');
    }
    if ($productId === false || $productId === null || $productId < 1) {
        throw new InvalidArgumentException('Produk tidak valid.');
    }
    if (!is_array($account) || array_is_list($account)) {
        throw new InvalidArgumentException('Data akun tidak valid.');
    }
    if (count($account) > 20) {
        throw new InvalidArgumentException('Terlalu banyak kolom data akun.');
    }

    $gameStmt = $pdo->prepare("SELECT id,name FROM games WHERE slug=:slug AND status='ACTIVE' LIMIT 1");
    $gameStmt->execute(['slug' => $slug]);
    $game = $gameStmt->fetch();
    if (!$game) {
        throw new DomainException('Game tidak tersedia.');
    }

    $productStmt = $pdo->prepare("SELECT p.id,p.game_id,p.provider_id,p.name,p.base_price,p.status,
            pr.status AS provider_status,pr.balance,pr.reserve_percentage
        FROM products p
        JOIN providers pr ON pr.id=p.provider_id
        WHERE p.id=:product_id AND p.game_id=:game_id
        LIMIT 1");
    $productStmt->execute(['product_id' => $productId, 'game_id' => $game['id']]);
    $product = $productStmt->fetch();
    if (!$product || $product['status'] !== 'ACTIVE') {
        throw new DomainException('Produk tidak tersedia.');
    }
    if (!in_array($product['provider_status'], ['ACTIVE','LOW_BALANCE'], true)
        || $product['balance'] === null
        || (float) $product['base_price'] > ((float) $product['balance'] * (float) $product['reserve_percentage'] / 100)) {
        throw new DomainException('Saldo provider tidak mencukupi untuk produk ini.');
    }

    $fieldStmt = $pdo->prepare("SELECT id,field_key,label,field_type,is_required,validation_regex
        FROM game_fields WHERE game_id=:game_id AND status='ACTIVE'");
    $fieldStmt->execute(['game_id' => $game['id']]);
    $fields = $fieldStmt->fetchAll();
    if (!$fields) {
        throw new DomainException('Formulir data akun game belum dikonfigurasi.');
    }

    $allowedKeys = [];
    $normalized = [];
    $optionStmt = $pdo->prepare("SELECT option_value FROM game_field_options WHERE game_field_id=:id AND status='ACTIVE'");
    foreach ($fields as $field) {
        $key = (string) $field['field_key'];
        $allowedKeys[] = $key;
        $value = $account[$key] ?? '';
        if (!is_scalar($value)) {
            throw new InvalidArgumentException('Format data akun tidak valid.');
        }
        $value = trim((string) $value);
        if (strlen($value) > 190) {
            throw new InvalidArgumentException('Data akun terlalu panjang.');
        }
        if ((bool) $field['is_required'] && $value === '') {
            throw new InvalidArgumentException('Kolom ' . $field['label'] . ' wajib diisi.');
        }
        if ($value !== '' && $field['field_type'] === 'number' && !preg_match('/^[0-9]+$/D', $value)) {
            throw new InvalidArgumentException('Kolom ' . $field['label'] . ' harus berupa angka.');
        }
        if ($value !== '' && $field['validation_regex'] !== null && $field['validation_regex'] !== '') {
            $pattern = str_replace('~', '\\~', (string) $field['validation_regex']);
            if (@preg_match('~^(?:' . $pattern . ')$~uD', $value) !== 1) {
                throw new InvalidArgumentException('Format kolom ' . $field['label'] . ' tidak sesuai.');
            }
        }
        if ($value !== '' && $field['field_type'] === 'select') {
            $optionStmt->execute(['id' => $field['id']]);
            $options = array_column($optionStmt->fetchAll(), 'option_value');
            if (!in_array($value, $options, true)) {
                throw new InvalidArgumentException('Pilihan untuk kolom ' . $field['label'] . ' tidak valid.');
            }
        }
        $normalized[$key] = $value;
    }
    foreach (array_keys($account) as $key) {
        if (!is_string($key) || !in_array($key, $allowedKeys, true)) {
            throw new InvalidArgumentException('Terdapat kolom data akun yang tidak dikenal.');
        }
    }

    $basePrice = (int) round((float) $product['base_price']);
    $sellingPrice = pricing_selling_price($pdo, $basePrice);
    $adminFee = pricing_admin_fee($sellingPrice);

    return [
        'game' => ['slug' => $slug, 'name' => $game['name']],
        'product' => ['id' => (int) $product['id'], 'name' => $product['name']],
        'account' => $normalized,
        'price' => [
            'base_price' => $basePrice,
            'selling_price' => $sellingPrice,
            'admin_fee' => $adminFee,
            'total' => $sellingPrice + $adminFee,
            'currency' => 'IDR',
        ],
        'payment_enabled' => false,
        'message' => 'Kutipan harga saja. Belum ada pesanan atau pembayaran yang dibuat.',
    ];
}
