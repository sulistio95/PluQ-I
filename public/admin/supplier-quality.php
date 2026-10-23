<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once PLUQI_ROOT . '/config/database.php';
require_once PLUQI_ROOT . '/app/services/admin_auth.php';
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
admin_session_start();
$pdo = database();
$user = admin_current_user($pdo);
if (!$user) { header('Location: /admin/'); exit; }

$providers = [];
$reasons = [];
$error = '';
try {
    $providers = $pdo->query("SELECT pr.id,pr.code,pr.name,
        (SELECT COUNT(*) FROM orders o WHERE o.provider_id=pr.id AND o.payment_status='PAID' AND o.order_status='SUCCESS') AS successful_orders,
        (SELECT COUNT(*) FROM orders o WHERE o.provider_id=pr.id AND o.payment_status='PAID' AND o.order_status IN ('FAILED','REFUNDED')) AS failed_orders,
        (SELECT ROUND(AVG(TIMESTAMPDIFF(SECOND,o.created_at,o.fulfilled_at))) FROM orders o WHERE o.provider_id=pr.id AND o.payment_status='PAID' AND o.order_status='SUCCESS' AND o.fulfilled_at IS NOT NULL AND o.fulfilled_at>=o.created_at) AS avg_success_seconds,
        (SELECT COUNT(*) FROM order_ratings r WHERE r.provider_id=pr.id) AS rating_count,
        (SELECT ROUND(AVG(r.rating),2) FROM order_ratings r WHERE r.provider_id=pr.id) AS avg_customer_rating
        FROM providers pr ORDER BY pr.name")->fetchAll(PDO::FETCH_ASSOC);
    $reasons = $pdo->query("SELECT r.reason,COUNT(*) AS total,ROUND(AVG(r.rating),2) AS avg_rating
        FROM order_ratings r GROUP BY r.reason ORDER BY total DESC, r.reason")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $error = 'Data belum dapat dimuat. Terapkan migration 014_order_ratings_and_supplier_quality.sql, lalu muat ulang halaman.';
}
$esc = static fn(mixed $v): string => htmlspecialchars((string)($v ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
function quality_duration(mixed $seconds): string {
    if ($seconds === null || $seconds === '') return 'Belum cukup data';
    $seconds = max(0, (int)$seconds);
    if ($seconds < 60) return $seconds . ' detik';
    if ($seconds < 3600) return intdiv($seconds,60) . ' menit';
    return intdiv($seconds,3600) . ' jam ' . intdiv($seconds%3600,60) . ' menit';
}
?><!doctype html><html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Kualitas Supplier — PluQ-I</title><link rel="stylesheet" href="/assets/admin-sidebar.css">
<style>
:root{color-scheme:dark;--bg:#0c0d10;--panel:#15171d;--line:#292d36;--text:#f3f4f6;--muted:#9ca3af;--accent:#a7f3d0}
*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--text);font:14px/1.5 system-ui,sans-serif}main{max-width:1400px;margin:auto;padding:24px}h1,h2{margin:0 0 12px}.muted{color:var(--muted)}.panel{background:var(--panel);border:1px solid var(--line);border-radius:12px;padding:18px;margin:16px 0}.table-wrap{overflow:auto}table{width:100%;border-collapse:collapse;white-space:nowrap}td,th{text-align:left;padding:11px;border-bottom:1px solid var(--line)}th{color:var(--muted)}.warning{padding:12px;border-left:3px solid #d6a84f;background:#211e17;color:#e5d5b1;border-radius:5px;margin:14px 0}.metric{font-weight:700}.pill{border:1px solid var(--line);padding:3px 7px;border-radius:6px;color:var(--muted)}@media(max-width:600px){main{padding:12px}.panel{padding:12px}}
</style></head><body class="admin-layout"><?php require PLUQI_ROOT . '/app/views/admin_sidebar.php'; ?>
<main><h1>Kualitas Supplier</h1><p class="muted">Statistik dihitung dari transaksi PluQ-I. Rating pelanggan ditampilkan terpisah dan bukan bukti otomatis bahwa supplier penyebab kegagalan.</p>
<div class="warning">Data awal dapat kosong atau belum mewakili kualitas sebenarnya. Jangan gunakan angka ini untuk mengganti pemilihan supplier otomatis sebelum diuji dan jumlah sampelnya memadai.</div>
<?php if ($error !== ''): ?><div class="panel"><?= $esc($error) ?></div><?php else: ?>
<section class="panel"><h2>Kinerja per supplier</h2><div class="table-wrap"><table><thead><tr><th>Supplier</th><th>Berhasil</th><th>Gagal / refund</th><th>Tingkat berhasil</th><th>Rata-rata waktu berhasil</th><th>Rating pelanggan</th><th>Jumlah rating</th></tr></thead><tbody>
<?php foreach ($providers as $p): $success=(int)$p['successful_orders']; $failed=(int)$p['failed_orders']; $total=$success+$failed; ?>
<tr><td><?= $esc($p['name']) ?> <span class="pill"><?= $esc($p['code']) ?></span></td><td><?= $success ?></td><td><?= $failed ?></td><td><?= $total > 0 ? number_format($success/$total*100,1,',','.') . '%' : 'Belum cukup data' ?></td><td><?= $esc(quality_duration($p['avg_success_seconds'])) ?></td><td><?= $p['avg_customer_rating'] !== null ? $esc(number_format((float)$p['avg_customer_rating'],2,',','.')) . ' / 5' : 'Belum ada rating' ?></td><td><?= (int)$p['rating_count'] ?></td></tr>
<?php endforeach; ?>
<?php if (!$providers): ?><tr><td colspan="7">Belum ada data supplier.</td></tr><?php endif; ?>
</tbody></table></div></section>
<section class="panel"><h2>Alasan rating pelanggan</h2><div class="table-wrap"><table><thead><tr><th>Alasan</th><th>Jumlah</th><th>Rata-rata rating</th></tr></thead><tbody>
<?php foreach ($reasons as $r): ?><tr><td><?= $esc(str_replace('_',' ',(string)$r['reason'])) ?></td><td><?= (int)$r['total'] ?></td><td><?= $esc(number_format((float)$r['avg_rating'],2,',','.')) ?> / 5</td></tr><?php endforeach; ?>
<?php if (!$reasons): ?><tr><td colspan="3">Belum ada rating pelanggan.</td></tr><?php endif; ?>
</tbody></table></div></section>
<?php endif; ?><p class="muted">Catatan: tingkat berhasil dihitung dari pesanan berbayar yang berstatus SUCCESS dibandingkan pesanan berbayar berstatus FAILED atau REFUNDED. Pesanan yang masih berjalan tidak masuk penyebut.</p></main></body></html>
