<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once PLUQI_ROOT . '/config/database.php';
require_once PLUQI_ROOT . '/app/services/admin_auth.php';
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Cache-Control: no-store');
header('Content-Type: text/html; charset=utf-8');
admin_session_start();
$pdo = database();
$user = admin_current_user($pdo);
if (!$user) { header('Location: /admin/'); exit; }
$filters = ['provider'=>trim((string)($_GET['provider']??'')), 'game_id'=>trim((string)($_GET['game_id']??'')), 'q'=>trim((string)($_GET['q']??''))];
$sort = (string)($_GET['sort'] ?? 'price_asc');
if (!in_array($sort, ['price_asc','price_desc'], true)) { $sort = 'price_asc'; }
$error = ''; $rows = []; $providers = []; $games = []; $total = 0;
function supplier_prices_money(mixed $value): string { return 'Rp '.number_format((float)$value,0,',','.'); }
try {
 $providers=$pdo->query('SELECT id,code,name FROM providers ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);
 $games=$pdo->query('SELECT id,name FROM games ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);
 $where=[]; $params=[]; $codes=array_column($providers,'code');
 if ($filters['provider']!=='') {
  if (!in_array($filters['provider'],$codes,true)) { $filters['provider']=''; $error='Filter supplier tidak valid.'; }
  else { $where[]='pr.code=:provider'; $params['provider']=$filters['provider']; }
 }
 if ($filters['game_id']!=='') {
  $gid=filter_var($filters['game_id'],FILTER_VALIDATE_INT);
  if ($gid===false || $gid<1) { $filters['game_id']=''; $error='Filter game tidak valid.'; }
  else { $where[]='gm.game_id=:game_id'; $params['game_id']=$gid; }
 }
 if ($filters['q']!=='') {
  $where[]='(pp.product_name LIKE :q1 OR pp.provider_product_code LIKE :q2 OR pp.provider_game_code LIKE :q3)';
  $needle='%'.$filters['q'].'%'; $params['q1']=$needle; $params['q2']=$needle; $params['q3']=$needle;
 }
 $whereSql=$where?' WHERE '.implode(' AND ',$where):'';
 $from=" FROM provider_products pp JOIN providers pr ON pr.id=pp.provider_id
 LEFT JOIN game_provider_mappings gm ON gm.provider_id=pp.provider_id AND gm.provider_game_code=pp.provider_game_code AND gm.status='ACTIVE'
 LEFT JOIN games g ON g.id=gm.game_id
 LEFT JOIN products p ON p.provider_id=pp.provider_id AND p.provider_product_code=pp.provider_product_code";
 $stmt=$pdo->prepare('SELECT COUNT(*)'.$from.$whereSql); $stmt->execute($params); $total=(int)$stmt->fetchColumn();
 $sql="SELECT pp.product_name,pp.provider_product_code,pp.provider_game_code,pp.base_price,pp.is_available,pp.synced_at,
 pr.code AS provider_code,pr.name AS provider_name,COALESCE(g.name,'Belum dipetakan') AS game_name,p.status AS store_status,
 COALESCE(p.base_price+(SELECT CASE WHEN rr.profit_type='PERCENTAGE' THEN ROUND(p.base_price*rr.profit_value/100,0) ELSE rr.profit_value END
 FROM pricing_rules rr WHERE rr.status='ACTIVE' AND rr.min_amount<=p.base_price AND (rr.max_amount IS NULL OR rr.max_amount>=p.base_price)
 ORDER BY rr.min_amount DESC,rr.id DESC LIMIT 1),p.selling_price) AS selling_price".$from.$whereSql.
 " ORDER BY pp.base_price " . ($sort === 'price_desc' ? 'DESC' : 'ASC') . ", pr.name,game_name,pp.product_name,pp.provider_product_code LIMIT 200";
 $stmt=$pdo->prepare($sql); $stmt->execute($params); $rows=$stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { $error='Data harga belum dapat dibaca. Pastikan migrasi katalog sudah diterapkan dan periksa log server.'; }
?>
<!doctype html><html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Harga Modal Supplier · PluQ-I</title><style>
:root{color-scheme:dark;--bg:#0c0d10;--panel:#15171d;--line:#292d36;--text:#f3f4f6;--muted:#9ca3af;--accent:#a7f3d0}*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--text);font:14px/1.5 system-ui,sans-serif}main{max-width:1500px;margin:auto;padding:24px}h1,h2{margin:0 0 12px}.muted{color:var(--muted)}.panel{background:var(--panel);border:1px solid var(--line);border-radius:12px;padding:18px;margin:16px 0}.bar{display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap}.filters{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px}label{display:grid;gap:6px;color:var(--muted)}input,select,button{width:100%;border:1px solid var(--line);background:#0d0f13;color:var(--text);padding:10px;border-radius:7px;font:inherit}button{cursor:pointer;background:#d1fae5;color:#111827;font-weight:700}a{color:var(--accent)}.table-wrap{overflow:auto}table{width:100%;border-collapse:collapse;white-space:nowrap}th,td{text-align:left;padding:10px;border-bottom:1px solid var(--line);vertical-align:top}th{color:var(--muted)}.error{padding:10px;background:#3b1d22;border-radius:7px;margin:12px 0}.small{font-size:12px}@media(max-width:650px){main{padding:12px}.panel{padding:12px}}
</style></head><body><main>
<header class="bar"><div><h1>Harga Modal Supplier</h1><div class="muted">Bandingkan harga asli supplier dengan harga jual setelah keuntungan.</div></div><a href="/admin/">Kembali ke panel admin</a></header>
<?php if($error!==''): ?><div class="error"><?=admin_escape($error)?></div><?php endif; ?>
<section class="panel"><p class="muted">Harga modal adalah harga dari sinkronisasi katalog terakhir. Harga jual dihitung dengan aturan keuntungan aktif. Produk yang belum dipetakan tetap dapat dilihat. Halaman ini hanya-baca: tidak mengubah harga dan tidak membuat pesanan.</p>
<form method="get" class="filters"><label>Supplier<select name="provider"><option value="">Semua supplier</option><?php foreach($providers as $p): ?><option value="<?=admin_escape($p['code'])?>" <?=$filters['provider']===$p['code']?'selected':''?>><?=admin_escape($p['name'])?> (<?=admin_escape($p['code'])?>)</option><?php endforeach; ?></select></label>
<label>Game<select name="game_id"><option value="">Semua game</option><?php foreach($games as $g): ?><option value="<?=admin_escape($g['id'])?>" <?=$filters['game_id']===(string)$g['id']?'selected':''?>><?=admin_escape($g['name'])?></option><?php endforeach; ?></select></label>
<label>Cari nama/kode<input type="search" name="q" maxlength="150" value="<?=admin_escape($filters['q'])?>" placeholder="Nama produk atau kode supplier"></label>
<label>Urutan harga modal<select name="sort"><option value="price_asc" <?=$sort==='price_asc'?'selected':''?>>Terendah ke tertinggi</option><option value="price_desc" <?=$sort==='price_desc'?'selected':''?>>Tertinggi ke terendah</option></select></label><button type="submit">Terapkan filter</button></form></section>
<section class="panel"><div class="bar"><h2>Daftar harga</h2><span class="muted"><?=number_format(count($rows))?> dari <?=number_format($total)?> baris · maksimal 200</span></div><div class="table-wrap"><table><thead><tr><th>Supplier</th><th>Game</th><th>Produk</th><th>Kode supplier</th><th>Harga modal</th><th>Harga jual</th><th>Keuntungan</th><th>Status katalog</th><th>Sinkron terakhir</th></tr></thead><tbody>
<?php if(!$rows): ?><tr><td colspan="9" class="muted">Belum ada data. Simpan pemetaan game lalu jalankan sinkronisasi katalog di Pengaturan API.</td></tr><?php endif; ?>
<?php foreach($rows as $r): $base=(int)$r['base_price']; $sell=$r['selling_price']===null?null:(int)$r['selling_price']; ?><tr><td><?=admin_escape($r['provider_name'])?> <span class="muted small">(<?=admin_escape($r['provider_code'])?>)</span></td><td><?=admin_escape($r['game_name'])?><div class="muted small"><?=admin_escape($r['provider_game_code'])?></div></td><td><?=admin_escape($r['product_name'])?></td><td><?=admin_escape($r['provider_product_code'])?></td><td><?=supplier_prices_money($base)?></td><td><?=$sell===null?'—':supplier_prices_money($sell)?></td><td><?=$sell===null?'Belum dipetakan':supplier_prices_money($sell-$base)?></td><td><?=((int)$r['is_available']===1?'Tersedia':'Tidak tersedia')?><?=!empty($r['store_status'])?'<div class="muted small">Produk toko: '.admin_escape($r['store_status']).'</div>':''?></td><td><?=admin_escape($r['synced_at']??'—')?></td></tr><?php endforeach; ?>
</tbody></table></div></section></main></body></html>
