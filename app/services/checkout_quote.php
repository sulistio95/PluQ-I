<?php
declare(strict_types=1);
require_once __DIR__ . '/pricing.php';
require_once __DIR__ . '/fulfillment.php';

/**
 * Isolated gateway testing is permitted only in a non-production environment,
 * with Duitku sandbox explicitly selected and supplier ordering disabled.
 */
function checkout_sandbox_only_enabled(): bool
{
    $environment = strtolower(trim((string)(getenv('APP_ENV') ?: 'production')));
    $duitkuEnvironment = strtolower(trim((string)(getenv('DUITKU_ENV') ?: 'sandbox')));
    $payments = filter_var(getenv('CHECKOUT_PAYMENTS_ENABLED') ?: 'false', FILTER_VALIDATE_BOOLEAN);
    $suppliers = filter_var(getenv('SUPPLIER_ORDERS_ENABLED') ?: 'false', FILTER_VALIDATE_BOOLEAN);

    return in_array($environment, ['staging', 'test'], true)
        && $duitkuEnvironment === 'sandbox'
        && $payments
        && !$suppliers;
}

/** Payments stay disabled unless normal fulfillment is enabled or isolated sandbox mode is explicitly configured. */
function checkout_payments_enabled(): bool
{
    $payments = filter_var(getenv('CHECKOUT_PAYMENTS_ENABLED') ?: 'false', FILTER_VALIDATE_BOOLEAN);
    $suppliers = filter_var(getenv('SUPPLIER_ORDERS_ENABLED') ?: 'false', FILTER_VALIDATE_BOOLEAN);

    return $payments && ($suppliers || checkout_sandbox_only_enabled());
}

function checkout_quote(PDO $pdo, array $input): array
{
    $slug = trim((string)($input['game_slug'] ?? ''));
    $productId = filter_var($input['product_id'] ?? null, FILTER_VALIDATE_INT);
    $account = $input['account'] ?? null;
    if (!preg_match('/^[a-zA-Z0-9_-]{1,150}$/D', $slug)) throw new InvalidArgumentException('Game tidak valid.');
    if ($productId === false || $productId === null || $productId < 1) throw new InvalidArgumentException('Produk tidak valid.');
    if (!is_array($account) || array_is_list($account)) throw new InvalidArgumentException('Data akun tidak valid.');
    if (count($account) > 20) throw new InvalidArgumentException('Terlalu banyak kolom data akun.');

    $stmt=$pdo->prepare("SELECT id,name FROM games WHERE slug=:slug AND status='ACTIVE' LIMIT 1");
    $stmt->execute(['slug'=>$slug]); $game=$stmt->fetch();
    if (!$game) throw new DomainException('Game tidak tersedia.');

    $stmt=$pdo->prepare("SELECT p.id,p.game_id,p.provider_id,p.provider_product_code,p.name,p.base_price,p.status,
        pr.status AS provider_status,pr.balance,pr.reserve_percentage,
        COALESCE((
          SELECT SUM(r.amount) FROM provider_budget_reservations r
          WHERE r.provider_id=pr.id
            AND (
              r.status IN ('ACTIVE','REVIEW')
              OR (r.status='SETTLED' AND
                (pr.last_balance_check_at IS NULL OR r.updated_at >= pr.last_balance_check_at))
            )
        ),0) AS committed_budget
      FROM products p JOIN providers pr ON pr.id=p.provider_id
      LEFT JOIN excluded_services ex ON ex.provider_id=p.provider_id AND ex.service_code=p.provider_product_code
      WHERE p.id=:pid AND p.game_id=:gid AND ex.id IS NULL
        AND LOWER(CONCAT(p.name,' ',p.provider_product_code)) NOT LIKE '%joki%'
        AND LOWER(CONCAT(p.name,' ',p.provider_product_code)) NOT LIKE '%vilog%'
      LIMIT 1");
    $stmt->execute(['pid'=>$productId,'gid'=>$game['id']]); $product=$stmt->fetch();
    if (!$product || $product['status']!=='ACTIVE') throw new DomainException('Produk tidak tersedia.');
    if (!in_array($product['provider_status'],['ACTIVE','LOW_BALANCE'],true) || $product['balance']===null
      || (float)$product['base_price'] + (float)$product['committed_budget']
        > (float)$product['balance']*(float)$product['reserve_percentage']/100) {
      throw new DomainException('Saldo provider tidak mencukupi.');
    }

    // A crafted product_id must not let the customer bypass the catalog's cheapest-provider choice.
    // Match the same normalized product name used by catalog_products(), but only among currently eligible offers.
    $cheapestStmt = $pdo->prepare("SELECT p.id,
        p.base_price + (
          CASE WHEN rr.profit_type='PERCENTAGE'
            THEN ROUND(p.base_price * rr.profit_value / 100, 0)
            ELSE rr.profit_value END
          FROM pricing_rules rr
          WHERE rr.status='ACTIVE' AND rr.min_amount <= p.base_price
            AND (rr.max_amount IS NULL OR rr.max_amount >= p.base_price)
          ORDER BY rr.min_amount DESC, rr.id DESC LIMIT 1
        ) AS selling_price
      FROM products p
      JOIN providers pr ON pr.id=p.provider_id
      LEFT JOIN excluded_services ex ON ex.provider_id=p.provider_id AND ex.service_code=p.provider_product_code
      WHERE p.game_id=:gid AND LOWER(TRIM(p.name))=LOWER(TRIM(:name))
        AND p.status='ACTIVE' AND pr.status IN ('ACTIVE','LOW_BALANCE')
        AND pr.balance IS NOT NULL
        AND p.base_price + COALESCE((
          SELECT SUM(r.amount) FROM provider_budget_reservations r
          WHERE r.provider_id=pr.id
            AND (
              r.status IN ('ACTIVE','REVIEW')
              OR (r.status='SETTLED' AND
                (pr.last_balance_check_at IS NULL OR r.updated_at >= pr.last_balance_check_at))
            )
        ),0) <= pr.balance*(pr.reserve_percentage/100)
        AND ex.id IS NULL
        AND LOWER(CONCAT(p.name,' ',p.provider_product_code)) NOT LIKE '%joki%'
        AND LOWER(CONCAT(p.name,' ',p.provider_product_code)) NOT LIKE '%vilog%'
        AND EXISTS (SELECT 1 FROM pricing_rules rr WHERE rr.status='ACTIVE'
          AND rr.min_amount <= p.base_price AND (rr.max_amount IS NULL OR rr.max_amount >= p.base_price))
      ORDER BY selling_price ASC,p.id ASC LIMIT 1");
    $cheapestStmt->execute(['gid'=>$game['id'],'name'=>$product['name']]);
    $cheapest = $cheapestStmt->fetch();
    if (!$cheapest || (int)$cheapest['id'] !== (int)$product['id']) {
      throw new DomainException('Pilihan produk berubah. Muat ulang katalog dan pilih harga termurah yang tersedia.');
    }

    $stmt=$pdo->prepare("SELECT id,field_key,label,field_type,is_required,validation_regex FROM game_fields WHERE game_id=:gid AND status='ACTIVE'");
    $stmt->execute(['gid'=>$game['id']]); $fields=$stmt->fetchAll();
    if (!$fields) throw new DomainException('Formulir game belum dikonfigurasi.');
    $keys=[]; $normalized=[];
    $optionStmt=$pdo->prepare("SELECT option_value FROM game_field_options WHERE game_field_id=:fid AND status='ACTIVE'");
    foreach ($fields as $field) {
      $key=(string)$field['field_key']; $keys[]=$key; $value=$account[$key]??'';
      if (!is_scalar($value)) throw new InvalidArgumentException('Format data akun tidak valid.');
      $value=trim((string)$value);
      if (strlen($value)>190) throw new InvalidArgumentException('Data akun terlalu panjang.');
      if ((bool)$field['is_required'] && $value==='') throw new InvalidArgumentException('Kolom '.$field['label'].' wajib diisi.');
      if ($value!=='' && $field['field_type']==='number' && !preg_match('/^[0-9]+$/D',$value)) throw new InvalidArgumentException('Kolom '.$field['label'].' harus berupa angka.');
      if ($value!=='' && $field['validation_regex']!==null && $field['validation_regex']!=='') {
        $pattern=str_replace('~','\\~',(string)$field['validation_regex']);
        if (@preg_match('~^(?:'.$pattern.')$~uD',$value)!==1) throw new InvalidArgumentException('Format kolom '.$field['label'].' tidak sesuai.');
      }
      if ($value!=='' && $field['field_type']==='select') {
        $optionStmt->execute(['fid'=>$field['id']]); $options=array_column($optionStmt->fetchAll(),'option_value');
        if (!in_array($value,$options,true)) throw new InvalidArgumentException('Pilihan '.$field['label'].' tidak valid.');
      }
      $normalized[$key]=$value;
    }
    foreach (array_keys($account) as $key) if (!is_string($key)||!in_array($key,$keys,true)) throw new InvalidArgumentException('Ada kolom akun yang tidak dikenal.');

    // Reject unsupported or ambiguous field mappings before any order/payment can be created.
    try {
      fulfillment_account($normalized);
    } catch (DomainException $e) {
      throw new DomainException('Formulir akun game belum memiliki pemetaan supplier yang aman. Admin harus memperbaiki field ID/zone/server sebelum checkout diaktifkan.');
    }

    $base=(int)round((float)$product['base_price']);
    $selling=pricing_selling_price($pdo,$base); $fee=pricing_admin_fee($selling);
    return [
      'game'=>['slug'=>$slug,'name'=>$game['name']],
      'product'=>['id'=>(int)$product['id'],'name'=>$product['name']],
      'account'=>$normalized,
      'price'=>['base_price'=>$base,'selling_price'=>$selling,'admin_fee'=>$fee,'total'=>$selling+$fee,'currency'=>'IDR'],
      'payment_enabled'=>checkout_payments_enabled(),
      'message'=>'Kutipan harga saja. Belum ada pesanan atau pembayaran yang dibuat.'
    ];
}
