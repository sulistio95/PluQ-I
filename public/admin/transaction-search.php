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

$q = trim((string) ($_GET['q'] ?? ''));
$rows = [];
$error = '';
if (strlen($q) > 150) {
    $q = substr($q, 0, 150);
    $error = 'Pencarian dipotong hingga 150 karakter.';
}
if ($q !== '') {
    try {
        $sql = "SELECT o.id,o.public_id,o.created_at,o.paid_at,o.fulfilled_at,o.payment_status,o.order_status,
                    o.selling_price,o.admin_fee,o.last_error,g.name AS game_name,p.name AS product_name,pr.code AS provider_code,
                    (SELECT pt.merchant_order_id FROM payment_transactions pt WHERE pt.order_id=o.id ORDER BY pt.id DESC LIMIT 1) AS merchant_order_id,
                    (SELECT pt.gateway_reference FROM payment_transactions pt WHERE pt.order_id=o.id ORDER BY pt.id DESC LIMIT 1) AS gateway_reference,
                    (SELECT st.reference_id FROM supplier_transactions st WHERE st.order_id=o.id ORDER BY st.id DESC LIMIT 1) AS supplier_reference,
                    (SELECT st.provider_reference FROM supplier_transactions st WHERE st.order_id=o.id ORDER BY st.id DESC LIMIT 1) AS provider_reference,
                    (SELECT st.status FROM supplier_transactions st WHERE st.order_id=o.id ORDER BY st.id DESC LIMIT 1) AS supplier_status
                FROM orders o
                JOIN games g ON g.id=o.game_id
                JOIN products p ON p.id=o.product_id
                JOIN providers pr ON pr.id=o.provider_id
                WHERE o.public_id LIKE :public_id
                   OR EXISTS (SELECT 1 FROM payment_transactions pt WHERE pt.order_id=o.id AND (pt.merchant_order_id LIKE :merchant_id OR pt.gateway_reference LIKE :gateway_ref))
                   OR EXISTS (SELECT 1 FROM supplier_transactions st WHERE st.order_id=o.id AND (st.reference_id LIKE :supplier_ref OR st.provider_reference LIKE :provider_ref))
                ORDER BY o.created_at DESC,o.id DESC LIMIT 200";
        $stmt = $pdo->prepare($sql);
        $needle = '%' . $q . '%';
        $stmt->execute(['public_id'=>$needle,'merchant_id'=>$needle,'gateway_ref'=>$needle,'supplier_ref'=>$needle,'provider_ref'=>$needle]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $error = 'Pencarian belum dapat dijalankan. Periksa penerapan migration dan log server.';
    }
}
function tx_search_money(mixed $amount): string { return 'Rp ' . number_format((float) $amount, 0, ',', '.'); }
?>
<!doctype html>
<html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Cari Transaksi — PluQ-I</title>
<link rel="stylesheet" href="/assets/admin-sidebar.css">
<style>
:root{color-scheme:dark;--bg:#0c0d10;--panel:#15171d;--line:#292d36;--text:#f3f4f6;--muted:#9ca3af;--accent:#a7f3d0}
*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--text);font:14px/1.5 system-ui,sans-serif}main{max-width:1500px;margin:auto;padding:24px}h1,h2{margin:0 0 12px}.muted{color:var(--muted)}.panel{background:var(--panel);border:1px solid var(--line);border-radius:12px;padding:18px;margin:16px 0}input,button{border:1px solid var(--line);background:#0d0f13;color:var(--text);padding:11px;border-radius:7px;font:inherit}input{width:100%;min-width:0}button{cursor:pointer;background:#d1fae5;color:#111827;font-weight:700}.search{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:10px}.table-wrap{overflow:auto}table{width:100%;border-collapse:collapse;white-space:nowrap}th,td{text-align:left;border-bottom:1px solid var(--line);padding:10px}th{color:var(--muted)}a{color:var(--accent)}.status{font-size:12px;border:1px solid var(--line);border-radius:5px;padding:3px 6px}.error{padding:10px;background:#3b1d22;border-radius:7px;margin:12px 0}.small{font-size:12px;max-width:220px;white-space:normal;overflow-wrap:anywhere}@media(max-width:600px){main{padding:12px}.panel{padding:12px}.search{grid-template-columns:1fr}}
</style></head><body class="admin-layout">
<?php require PLUQI_ROOT . '/app/views/admin_sidebar.php'; ?>
<main><header><h1>Pencarian Transaksi</h1><p class="muted">Cari ID pesanan PluQ-I, nomor merchant Duitku, referensi gateway, atau referensi supplier.</p></header>
<?php if ($error !== ''): ?><div class="error"><?=admin_escape($error)?></div><?php endif; ?>
<section class="panel"><form method="get" class="search"><input name="q" value="<?=admin_escape($q)?>" maxlength="150" placeholder="Contoh: ID pesanan atau nomor referensi" autofocus><button type="submit">Cari transaksi</button></form>
<p class="muted">Hasil dibatasi maksimal 200 transaksi. Pencarian kosong tidak menjalankan query.</p></section>
<?php if ($q !== ''): ?><section class="panel"><h2>Hasil pencarian (<?=number_format(count($rows))?>)</h2><div class="table-wrap"><table><thead><tr><th>Waktu dibuat</th><th>ID pesanan</th><th>Game / produk</th><th>Pembayaran</th><th>Pesanan</th><th>Supplier</th><th>Nominal</th><th>Detail</th></tr></thead><tbody>
<?php if (!$rows): ?><tr><td colspan="8" class="muted">Tidak ada transaksi yang cocok.</td></tr><?php endif; ?>
<?php foreach ($rows as $r): ?><tr><td><?=admin_escape($r['created_at'])?></td><td><strong><?=admin_escape($r['public_id'])?></strong><div class="muted small">Merchant: <?=admin_escape($r['merchant_order_id'] ?? '-')?></div><div class="muted small">Gateway: <?=admin_escape($r['gateway_reference'] ?? '-')?></div></td><td><?=admin_escape($r['game_name'])?><div class="muted small"><?=admin_escape($r['product_name'])?></div></td><td><span class="status"><?=admin_escape($r['payment_status'])?></span></td><td><span class="status"><?=admin_escape($r['order_status'])?></span><?php if (!empty($r['last_error'])): ?><div class="muted small"><?=admin_escape($r['last_error'])?></div><?php endif; ?></td><td><?=admin_escape($r['provider_code'])?><div class="muted small"><?=admin_escape($r['supplier_status'] ?? '-')?></div><div class="muted small"><?=admin_escape($r['supplier_reference'] ?? $r['provider_reference'] ?? '-')?></div></td><td><?=tx_search_money((float)$r['selling_price']+(float)$r['admin_fee'])?></td><td><a href="/admin/transaction-detail.php?id=<?=rawurlencode((string)$r['public_id'])?>">Lihat detail &amp; linimasa</a></td></tr><?php endforeach; ?>
</tbody></table></div></section><?php endif; ?>
</main></body></html>
