<?php
declare(strict_types=1);
require_once __DIR__ . '/pricing.php';

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
        pr.status AS provider_status,pr.balance,pr.reserve_percentage
      FROM products p JOIN providers pr ON pr.id=p.provider_id
      LEFT JOIN excluded_services ex ON ex.provider_id=p.provider_id AND ex.service_code=p.provider_product_code
      WHERE p.id=:pid AND p.game_id=:gid AND ex.id IS NULL
        AND LOWER(CONCAT(p.name,' ',p.provider_product_code)) NOT LIKE '%joki%'
        AND LOWER(CONCAT(p.name,' ',p.provider_product_code)) NOT LIKE '%vilog%'
      LIMIT 1");
    $stmt->execute(['pid'=>$productId,'gid'=>$game['id']]); $product=$stmt->fetch();
    if (!$product || $product['status']!=='ACTIVE') throw new DomainException('Produk tidak tersedia.');
    if (!in_array($product['provider_status'],['ACTIVE','LOW_BALANCE'],true) || $product['balance']===null
      || (float)$product['base_price'] > (float)$product['balance']*(float)$product['reserve_percentage']/100) {
      throw new DomainException('Saldo provider tidak mencukupi.');
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

    $base=(int)round((float)$product['base_price']);
    $selling=pricing_selling_price($pdo,$base); $fee=pricing_admin_fee($selling);
    return [
      'game'=>['slug'=>$slug,'name'=>$game['name']],
      'product'=>['id'=>(int)$product['id'],'name'=>$product['name']],
      'account'=>$normalized,
      'price'=>['base_price'=>$base,'selling_price'=>$selling,'admin_fee'=>$fee,'total'=>$selling+$fee,'currency'=>'IDR'],
      'payment_enabled'=>false,
      'message'=>'Kutipan harga saja. Belum ada pesanan atau pembayaran yang dibuat.'
    ];
}
