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
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = (int)($_GET['per_page'] ?? 50);
if (!in_array($perPage, [25, 50, 100, 200], true)) { $perPage = 50; }
$error = ''; $rows = []; $providers = []; $games = []; $total = 0; $totalPages = 1; $offset = 0;
function supplier_prices_money(mixed $value): string { return 'Rp '.number_format((float)$value,0,',','.'); }
try {
 $providers=$pdo->query('SELECT id,code,name FROM providers ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);
 $games=$pdo->query("SELECT g.id,g.name FROM games g WHERE g.status='ACTIVE' AND EXISTS (SELECT 1 FROM game_provider_mappings gm WHERE gm.game_id=g.id AND gm.status='ACTIVE') ORDER BY g.name")->fetchAll(PDO::FETCH_ASSOC);
 $where=[]; $params=[]; $codes=array_column($providers,'code');
 if ($filters['provider']!=='') {
  if (!in_array($filters['provider'],$codes,true)) { $filters['provider']=''; $error='Filter supplier tidak valid.'; }
  else { $where[]='pr.code=:provider'; $params['provider']=$filters['provider']; }
 }
 if ($filters['game_id']!=='') {
  $gid=filter_var($filters['game_id'],FILTER_VALIDATE_INT);
  if ($gid===false || $gid<1) { $filters['game_id']=''; $error='Filter game tidak valid.'; }
  else { $where[]="gm.game_id=:game_id AND g.status='ACTIVE'"; $params['game_id']=$gid; }
 }
 if ($filters['q']!=='') {
  $where[]='(pp.product_name LIKE :q1 OR pp.provider_product_code LIKE :q2 OR pp.provider_game_code LIKE :q3)';
  $needle='%'.$filters['q'].'%'; $params['q1']=$needle; $params['q2']=$needle; $params['q3']=$needle;
 }
 $whereSql=$where?' WHERE '.implode(' AND ',$where):'';
 $from=" FROM provider_products pp JOIN providers pr ON pr.id=pp.provider_id
 JOIN game_provider_mappings gm ON gm.provider_id=pp.provider_id AND gm.provider_game_code=pp.provider_game_code AND gm.status='ACTIVE'
 JOIN games g ON g.id=gm.game_id AND g.status='ACTIVE'
 LEFT JOIN products p ON p.provider_id=pp.provider_id AND p.provider_product_code=pp.provider_product_code";
 $stmt=$pdo->prepare('SELECT COUNT(*)'.$from.$whereSql); $stmt->execute($params); $total=(int)$stmt->fetchColumn();
 $totalPages=max(1,(int)ceil($total/$perPage));
 if ($page>$totalPages) { $page=$totalPages; }
 $offset=($page-1)*$perPage;
 $sql="SELECT pp.product_name,pp.provider_product_code,pp.provider_game_code,pp.base_price,pp.is_available,pp.synced_at,
 pr.code AS provider_code,pr.name AS provider_name,COALESCE(g.name,'Belum dipetakan') AS game_name,p.status AS store_status,
 COALESCE(p.base_price+(SELECT CASE WHEN rr.profit_type='PERCENTAGE' THEN ROUND(p.base_price*rr.profit_value/100,0) ELSE rr.profit_value END
 FROM pricing_rules rr WHERE rr.status='ACTIVE' AND rr.min_amount<=p.base_price AND (rr.max_amount IS NULL OR rr.max_amount>=p.base_price)
 ORDER BY rr.min_amount DESC,rr.id DESC LIMIT 1),p.selling_price) AS selling_price".$from.$whereSql.
 " ORDER BY pp.base_price " . ($sort === 'price_desc' ? 'DESC' : 'ASC') . ", pr.name,game_name,pp.product_name,pp.provider_product_code LIMIT " . $perPage . " OFFSET " . $offset;
 $stmt=$pdo->prepare($sql); $stmt->execute($params); $rows=$stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { $error='Data harga belum dapat dibaca. Pastikan migrasi katalog sudah diterapkan dan periksa log server.'; }
?>
<!doctype html><html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Harga Modal Supplier · PluQ-I</title><style>
:root{color-scheme:dark;--bg:#0c0d10;--panel:#15171d;--line:#292d36;--text:#f3f4f6;--muted:#9ca3af;--accent:#a7f3d0}*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--text);font:14px/1.5 system-ui,sans-serif}main{max-width:1500px;margin:auto;padding:24px}h1,h2{margin:0 0 12px}.muted{color:var(--muted)}.panel{background:var(--panel);border:1px solid var(--line);border-radius:12px;padding:18px;margin:16px 0}.bar{display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap}.filters{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px}label{display:grid;gap:6px;color:var(--muted)}input,select,button{width:100%;border:1px solid var(--line);background:#0d0f13;color:var(--text);padding:10px;border-radius:7px;font:inherit}button{cursor:pointer;background:#d1fae5;color:#111827;font-weight:700}a{color:var(--accent)}.table-scroll-top{position:sticky;top:8px;z-index:5;overflow-x:auto;overflow-y:hidden;height:20px;margin:8px 0;background:var(--panel);border:1px solid var(--line);border-radius:6px;overscroll-behavior-x:contain}.table-scroll-top-inner{height:1px}.table-wrap{overflow:auto;overscroll-behavior-x:contain}table{width:100%;min-width:max-content;border-collapse:collapse;white-space:nowrap}th,td{text-align:left;padding:10px;border-bottom:1px solid var(--line);vertical-align:top}th{color:var(--muted)}.error{padding:10px;background:#3b1d22;border-radius:7px;margin:12px 0}.small{font-size:12px}.pagination{display:flex;align-items:center;justify-content:center;gap:6px;flex-wrap:wrap;margin-top:16px}.pagination a,.pagination span{display:inline-block;padding:8px 12px;border:1px solid var(--line);border-radius:7px;text-decoration:none}.pagination .current{background:#d1fae5;color:#111827;font-weight:700}.pagination .disabled{color:#6b7280;pointer-events:none}.page-size{max-width:160px}@media(max-width:650px){main{padding:12px}.panel{padding:12px}}
</style>
<link rel="stylesheet" href="/assets/admin-sidebar.css"></head><body class="admin-layout">
<?php require PLUQI_ROOT . '/app/views/admin_sidebar.php'; ?><main>
<header class="bar"><div><h1>Harga Modal Supplier</h1><div class="muted">Bandingkan harga asli supplier dengan harga jual setelah keuntungan.</div></div></header>
<?php if($error!==''): ?><div class="error"><?=admin_escape($error)?></div><?php endif; ?>
<section class="panel"><p class="muted">Harga modal adalah harga dari sinkronisasi katalog terakhir. Harga jual dihitung dengan aturan keuntungan aktif. Hanya produk dengan pemetaan supplier aktif dan game aktif yang ditampilkan. Halaman ini hanya-baca: tidak mengubah harga dan tidak membuat pesanan.</p>
<form method="get" class="filters"><input type="hidden" name="page" value="1"><label>Jumlah per halaman<select name="per_page" class="page-size"><?php foreach([25,50,100,200] as $size): ?><option value="<?=$size?>" <?=$perPage===$size?'selected':''?>><?=$size?> item</option><?php endforeach; ?></select></label><label>Supplier<select name="provider"><option value="">Semua supplier</option><?php foreach($providers as $p): ?><option value="<?=admin_escape($p['code'])?>" <?=$filters['provider']===$p['code']?'selected':''?>><?=admin_escape($p['name'])?> (<?=admin_escape($p['code'])?>)</option><?php endforeach; ?></select></label>
<label>Game<select name="game_id"><option value="">Semua game</option><?php foreach($games as $g): ?><option value="<?=admin_escape($g['id'])?>" <?=$filters['game_id']===(string)$g['id']?'selected':''?>><?=admin_escape($g['name'])?></option><?php endforeach; ?></select></label>
<label>Cari nama/kode<input type="search" name="q" maxlength="150" value="<?=admin_escape($filters['q'])?>" placeholder="Nama produk atau kode supplier"></label>
<label>Urutan harga modal<select name="sort"><option value="price_asc" <?=$sort==='price_asc'?'selected':''?>>Terendah ke tertinggi</option><option value="price_desc" <?=$sort==='price_desc'?'selected':''?>>Tertinggi ke terendah</option></select></label><button type="submit">Terapkan filter</button></form></section>
<section class="panel"><div class="bar"><h2>Daftar harga</h2><span class="muted"><?=number_format($total===0?0:$offset+1)?>–<?=number_format(min($offset+count($rows),$total))?> dari <?=number_format($total)?> item</span></div><div class="table-scroll-top" id="supplier-prices-scroll-top" role="region" aria-label="Penggeser tabel harga secara horizontal" tabindex="0"><div class="table-scroll-top-inner" id="supplier-prices-scroll-inner"></div></div><div class="table-wrap" id="supplier-prices-table-wrap"><table><thead><tr><th>Supplier</th><th>Game</th><th>Produk</th><th>Kode supplier</th><th>Harga modal</th><th>Harga jual</th><th>Keuntungan</th><th>Status katalog</th><th>Sinkron terakhir</th></tr></thead><tbody>
<?php if(!$rows): ?><tr><td colspan="9" class="muted">Belum ada data. Simpan pemetaan game lalu jalankan sinkronisasi katalog di Pengaturan API.</td></tr><?php endif; ?>
<?php foreach($rows as $r): $base=(int)$r['base_price']; $sell=$r['selling_price']===null?null:(int)$r['selling_price']; ?><tr><td><?=admin_escape($r['provider_name'])?> <span class="muted small">(<?=admin_escape($r['provider_code'])?>)</span></td><td><?=admin_escape($r['game_name'])?><div class="muted small"><?=admin_escape($r['provider_game_code'])?></div></td><td><?=admin_escape($r['product_name'])?></td><td><?=admin_escape($r['provider_product_code'])?></td><td><?=supplier_prices_money($base)?></td><td><?=$sell===null?'—':supplier_prices_money($sell)?></td><td><?=$sell===null?'Belum dipetakan':supplier_prices_money($sell-$base)?></td><td><?=((int)$r['is_available']===1?'Tersedia':'Tidak tersedia')?><?=!empty($r['store_status'])?'<div class="muted small">Produk toko: '.admin_escape($r['store_status']).'</div>':''?></td><td><?=admin_escape($r['synced_at']??'—')?></td></tr><?php endforeach; ?>
</tbody></table></div>
<?php if($totalPages>1): ?><nav class="pagination" aria-label="Halaman daftar harga">
<?php $queryBase=$filters; $queryBase['sort']=$sort; $queryBase['per_page']=$perPage; $queryBase['page']=max(1,$page-1); ?><a class="<?=$page<=1?'disabled':''?>" href="?<?=admin_escape(http_build_query($queryBase))?>" aria-label="Halaman sebelumnya">‹ Sebelumnya</a>
<?php $start=max(1,$page-2); $end=min($totalPages,$page+2); if($start>1): $queryBase['page']=1; ?><a href="?<?=admin_escape(http_build_query($queryBase))?>">1</a><?php if($start>2): ?><span>…</span><?php endif; ?><?php endif; ?>
<?php for($n=$start;$n<=$end;$n++): $queryBase['page']=$n; if($n===$page): ?><span class="current" aria-current="page"><?=$n?></span><?php else: ?><a href="?<?=admin_escape(http_build_query($queryBase))?>"><?=$n?></a><?php endif; endfor; ?>
<?php if($end<$totalPages): if($end<$totalPages-1): ?><span>…</span><?php endif; $queryBase['page']=$totalPages; ?><a href="?<?=admin_escape(http_build_query($queryBase))?>"><?=$totalPages?></a><?php endif; ?>
<?php $queryBase['page']=min($totalPages,$page+1); ?><a class="<?=$page>=$totalPages?'disabled':''?>" href="?<?=admin_escape(http_build_query($queryBase))?>" aria-label="Halaman berikutnya">Berikutnya ›</a></nav><?php endif; ?>
</section></main><script>
(function(){
 const top=document.getElementById("supplier-prices-scroll-top");
 const inner=document.getElementById("supplier-prices-scroll-inner");
 const wrap=document.getElementById("supplier-prices-table-wrap");
 const table=wrap&&wrap.querySelector("table");
 if(!top||!inner||!wrap||!table)return;
 let syncing=false;
 function sizeBar(){inner.style.width=table.scrollWidth+"px";top.hidden=wrap.scrollWidth<=wrap.clientWidth;top.scrollLeft=wrap.scrollLeft;}
 top.addEventListener("scroll",function(){if(syncing)return;syncing=true;wrap.scrollLeft=top.scrollLeft;syncing=false;});
 wrap.addEventListener("scroll",function(){if(syncing)return;syncing=true;top.scrollLeft=wrap.scrollLeft;syncing=false;});
 window.addEventListener("resize",sizeBar);
 if(typeof ResizeObserver!=="undefined"){new ResizeObserver(sizeBar).observe(table);}
 sizeBar();
})();
</script></body></html>
