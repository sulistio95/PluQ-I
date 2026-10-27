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

$publicId = trim((string) ($_GET['id'] ?? ''));
$order = null;
$payments = [];
$suppliers = [];
$refunds = [];
$error = '';
if ($publicId === '' || strlen($publicId) > 150) {
    $error = 'ID pesanan tidak valid.';
} else {
    try {
        $stmt = $pdo->prepare("SELECT o.id,o.public_id,o.created_at,o.paid_at,o.fulfilled_at,o.payment_status,o.order_status,
                o.base_price,o.selling_price,o.admin_fee,o.last_error,g.name AS game_name,p.name AS product_name,
                pr.code AS provider_code,pr.name AS provider_name
            FROM orders o JOIN games g ON g.id=o.game_id JOIN products p ON p.id=o.product_id
            JOIN providers pr ON pr.id=o.provider_id WHERE o.public_id=:public_id LIMIT 1");
        $stmt->execute(['public_id'=>$publicId]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$order) {
            $error = 'Transaksi tidak ditemukan.';
        } else {
            $stmt = $pdo->prepare("SELECT gateway,status,amount,merchant_order_id,gateway_reference,gateway_payment_url,gateway_expiry_at
                FROM payment_transactions WHERE order_id=:id ORDER BY id DESC");
            $stmt->execute(['id'=>$order['id']]);
            $payments = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $stmt = $pdo->prepare("SELECT reference_id,provider_reference,status,created_at FROM supplier_transactions WHERE order_id=:id ORDER BY created_at ASC,id ASC");
            $stmt->execute(['id'=>$order['id']]);
            $suppliers = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $stmt = $pdo->prepare("SELECT status FROM refunds WHERE order_id=:id ORDER BY id ASC");
            $stmt->execute(['id'=>$order['id']]);
            $refunds = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (Throwable $e) {
        $error = 'Detail transaksi belum dapat dimuat. Periksa migration database dan log server.';
    }
}
function tx_detail_money(mixed $amount): string { return 'Rp ' . number_format((float) $amount, 0, ',', '.'); }
$timeline = [];
if ($order) {
    $timeline[] = ['time'=>$order['created_at'],'title'=>'Pesanan dibuat','detail'=>'Pesanan tercatat di PluQ-I.'];
    if (!empty($order['paid_at'])) $timeline[] = ['time'=>$order['paid_at'],'title'=>'Pembayaran diterima','detail'=>'Waktu pembayaran yang tersimpan pada pesanan.'];
    foreach ($payments as $p) {
        $timeline[] = ['time'=>null,'title'=>'Status gateway: '.(string)$p['status'],'detail'=>'Gateway: '.(string)$p['gateway'].' · Merchant: '.(string)($p['merchant_order_id'] ?? '-').' · Referensi: '.(string)($p['gateway_reference'] ?? '-')];
    }
    foreach ($suppliers as $s) {
        $timeline[] = ['time'=>$s['created_at'],'title'=>'Transaksi supplier: '.(string)$s['status'],'detail'=>'Referensi: '.(string)($s['reference_id'] ?? '-').' · Referensi provider: '.(string)($s['provider_reference'] ?? '-')];
    }
    if (!empty($order['fulfilled_at'])) $timeline[] = ['time'=>$order['fulfilled_at'],'title'=>'Pemenuhan selesai','detail'=>'Waktu penyelesaian yang tersimpan pada pesanan.'];
    foreach ($refunds as $r) $timeline[] = ['time'=>null,'title'=>'Status refund: '.(string)$r['status'],'detail'=>'Status refund tersimpan; waktu perubahan belum disediakan oleh tampilan ini.'];
    usort($timeline, static function(array $a, array $b): int {
        if ($a['time'] === null) return $b['time'] === null ? 0 : 1;
        if ($b['time'] === null) return -1;
        return strcmp((string)$a['time'], (string)$b['time']);
    });
}
?>
<!doctype html><html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Detail Transaksi — PluQ-I</title><link rel="stylesheet" href="/assets/admin-sidebar.css">
<style>
:root{color-scheme:dark;--bg:#0c0d10;--panel:#15171d;--line:#292d36;--text:#f3f4f6;--muted:#9ca3af;--accent:#a7f3d0}
*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--text);font:14px/1.5 system-ui,sans-serif}main{max-width:1200px;margin:auto;padding:24px}h1,h2{margin:0 0 14px}.muted{color:var(--muted)}.panel{background:var(--panel);border:1px solid var(--line);border-radius:12px;padding:18px;margin:16px 0}.error{padding:10px;background:#3b1d22;border-radius:7px;margin:12px 0}.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px}.item{padding:12px;background:#0d0f13;border:1px solid var(--line);border-radius:8px;min-width:0}.label{color:var(--muted);font-size:12px}.value{font-weight:650;overflow-wrap:anywhere}.status{display:inline-block;font-size:12px;border:1px solid var(--line);border-radius:5px;padding:3px 6px}.timeline{display:grid;gap:0}.event{display:grid;grid-template-columns:minmax(145px,190px) 1fr;gap:16px;padding:14px 0;border-bottom:1px solid var(--line)}.event:last-child{border-bottom:0}.event-title{font-weight:650}.event-detail{color:var(--muted);overflow-wrap:anywhere}a{color:var(--accent)}table{width:100%;border-collapse:collapse}td,th{text-align:left;padding:10px;border-bottom:1px solid var(--line);vertical-align:top}th{color:var(--muted)}.table-wrap{overflow:auto}.warning{border-left:3px solid #d6a84f;padding:10px 12px;color:var(--muted);background:#211e17;border-radius:4px}@media(max-width:600px){main{padding:12px}.panel{padding:12px}.event{grid-template-columns:1fr;gap:4px}}
</style></head><body class="admin-layout">
<?php require PLUQI_ROOT . '/app/views/admin_sidebar.php'; ?>
<main><p><a href="/admin/transaction-search.php">← Kembali ke pencarian transaksi</a></p><h1>Detail Transaksi</h1>
<?php if ($error !== ''): ?><div class="error"><?=admin_escape($error)?></div><?php endif; ?>
<?php if ($order): ?>
<section class="panel"><h2>Ringkasan pesanan</h2><div class="grid">
<div class="item"><div class="label">ID pesanan PluQ-I</div><div class="value"><?=admin_escape($order['public_id'])?></div></div>
<div class="item"><div class="label">Dibuat</div><div class="value"><?=admin_escape($order['created_at'])?></div></div>
<div class="item"><div class="label">Status pembayaran</div><div class="value"><span class="status"><?=admin_escape($order['payment_status'])?></span></div></div>
<div class="item"><div class="label">Status pesanan</div><div class="value"><span class="status"><?=admin_escape($order['order_status'])?></span></div></div>
<div class="item"><div class="label">Game / produk</div><div class="value"><?=admin_escape($order['game_name'].' / '.$order['product_name'])?></div></div>
<div class="item"><div class="label">Supplier terpilih</div><div class="value"><?=admin_escape($order['provider_name'].' ('.$order['provider_code'].')')?></div></div>
<div class="item"><div class="label">Modal supplier</div><div class="value"><?=tx_detail_money($order['base_price'])?></div></div>
<div class="item"><div class="label">Harga jual + biaya admin</div><div class="value"><?=tx_detail_money((float)$order['selling_price']+(float)$order['admin_fee'])?></div></div>
<div class="item"><div class="label">Pembayaran diterima</div><div class="value"><?=admin_escape($order['paid_at'] ?? '-')?></div></div>
<div class="item"><div class="label">Top-up selesai</div><div class="value"><?=admin_escape($order['fulfilled_at'] ?? '-')?></div></div>
</div>
<?php if (!empty($order['last_error'])): ?><h2 style="margin-top:18px">Error terakhir</h2><div class="warning"><?=nl2br(admin_escape($order['last_error']))?></div><?php endif; ?>
</section>
<section class="panel"><h2>Linimasa transaksi</h2><p class="muted">Linimasa disusun dari cap waktu dan status yang saat ini tersimpan. Status tanpa cap waktu khusus ditampilkan tanpa waktu; ini bukan log mentah seluruh request/callback.</p><div class="timeline">
<?php foreach ($timeline as $event): ?><div class="event"><div class="muted"><?=admin_escape($event['time'] ?? 'Waktu kejadian tidak tercatat')?></div><div><div class="event-title"><?=admin_escape($event['title'])?></div><div class="event-detail"><?=admin_escape($event['detail'])?></div></div></div><?php endforeach; ?>
<?php if (!$timeline): ?><p class="muted">Belum ada kejadian tercatat.</p><?php endif; ?></div></section>
<section class="panel"><h2>Riwayat gateway pembayaran</h2><div class="table-wrap"><table><thead><tr><th>Gateway</th><th>Status</th><th>Nominal</th><th>Nomor merchant</th><th>Referensi gateway</th><th>Kedaluwarsa</th><th>Invoice</th></tr></thead><tbody>
<?php if (!$payments): ?><tr><td colspan="7" class="muted">Tidak ada catatan gateway.</td></tr><?php endif; ?>
<?php foreach ($payments as $p): ?><tr><td><?=admin_escape($p['gateway'])?></td><td><?=admin_escape($p['status'])?></td><td><?=tx_detail_money($p['amount'])?></td><td><?=admin_escape($p['merchant_order_id'] ?? '-')?></td><td><?=admin_escape($p['gateway_reference'] ?? '-')?></td><td><?=admin_escape($p['gateway_expiry_at'] ?? '-')?></td><td><?php if (!empty($p['gateway_payment_url'])): ?><a href="<?=admin_escape($p['gateway_payment_url'])?>" target="_blank" rel="noopener noreferrer">Buka invoice</a><?php else: ?>-<?php endif; ?></td></tr><?php endforeach; ?>
</tbody></table></div></section>
<section class="panel"><h2>Riwayat supplier</h2><div class="table-wrap"><table><thead><tr><th>Waktu dibuat</th><th>Status</th><th>Referensi PluQ-I</th><th>Referensi provider</th></tr></thead><tbody>
<?php if (!$suppliers): ?><tr><td colspan="4" class="muted">Belum ada catatan transaksi supplier.</td></tr><?php endif; ?>
<?php foreach ($suppliers as $s): ?><tr><td><?=admin_escape($s['created_at'])?></td><td><?=admin_escape($s['status'])?></td><td><?=admin_escape($s['reference_id'] ?? '-')?></td><td><?=admin_escape($s['provider_reference'] ?? '-')?></td></tr><?php endforeach; ?>
</tbody></table></div></section>
<section class="panel"><h2>Riwayat refund</h2><div class="table-wrap"><table><thead><tr><th>Status</th></tr></thead><tbody>
<?php if (!$refunds): ?><tr><td class="muted">Belum ada catatan refund.</td></tr><?php endif; ?>
<?php foreach ($refunds as $r): ?><tr><td><?=admin_escape($r['status'])?></td></tr><?php endforeach; ?>
</tbody></table></div></section>
<?php endif; ?>
</main></body></html>
