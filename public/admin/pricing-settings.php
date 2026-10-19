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
if (!$user) {
    header('Location: /admin/');
    exit;
}
$canManage = admin_can_manage_settings($user);
$error = '';
$notice = '';
$rows = [];

function pricing_admin_amount(mixed $value, string $label, bool $nullable = false): ?int
{
    if ($nullable && ($value === null || trim((string)$value) === '')) return null;
    if (!is_scalar($value) || !preg_match('/^[0-9]{1,15}$/D', (string)$value)) {
        throw new InvalidArgumentException($label . ' harus berupa angka rupiah bulat yang valid.');
    }
    $number = (int)$value;
    if ($number < 0 || $number > 999999999999999) {
        throw new InvalidArgumentException($label . ' berada di luar batas yang diizinkan.');
    }
    return $number;
}

function pricing_admin_validate_no_overlap(PDO $pdo, int $ignoreId, int $min, ?int $max, string $status): void
{
    if ($status !== 'ACTIVE') return;
    $stmt = $pdo->prepare("SELECT id,min_amount,max_amount FROM pricing_rules WHERE status='ACTIVE' AND id<>:id");
    $stmt->execute(['id' => $ignoreId]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $other) {
        $otherMin = (float)$other['min_amount'];
        $otherMax = $other['max_amount'] === null ? null : (float)$other['max_amount'];
        $thisMax = $max === null ? INF : (float)$max;
        $existingMax = $otherMax === null ? INF : $otherMax;
        if ((float)$min <= $existingMax && $otherMin <= $thisMax) {
            throw new InvalidArgumentException('Rentang harga aktif bertumpang tindih dengan aturan #' . (int)$other['id'] . '. Ubah batas agar setiap harga hanya cocok dengan satu aturan.');
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!admin_csrf_valid($_POST['csrf'] ?? null)) {
        http_response_code(403);
        $error = 'Sesi formulir tidak valid. Muat ulang halaman dan coba lagi.';
    } elseif (!$canManage) {
        http_response_code(403);
        $error = 'Peran akun Anda tidak diizinkan mengubah aturan keuntungan.';
    } else {
        try {
            $action = (string)($_POST['action'] ?? '');
            $idRaw = filter_var($_POST['id'] ?? '0', FILTER_VALIDATE_INT);
            if ($idRaw === false || $idRaw < 0) throw new InvalidArgumentException('ID aturan tidak valid.');
            $id = (int)$idRaw;
            $min = pricing_admin_amount($_POST['min_amount'] ?? null, 'Harga modal minimum');
            $max = pricing_admin_amount($_POST['max_amount'] ?? null, 'Harga modal maksimum', true);
            $type = (string)($_POST['profit_type'] ?? 'FIXED');
            $value = $_POST['profit_value'] ?? null;
            $status = (string)($_POST['status'] ?? 'ACTIVE');
            if ($min === null || ($max !== null && $max < $min)) throw new InvalidArgumentException('Harga maksimum harus kosong (tanpa batas atas) atau sama dengan/lebih besar dari harga minimum.');
            if (!in_array($type, ['FIXED','PERCENTAGE'], true)) throw new InvalidArgumentException('Pilih tipe keuntungan nominal atau persentase.');
            if (!in_array($status, ['ACTIVE','INACTIVE'], true)) throw new InvalidArgumentException('Status aturan tidak valid.');
            if (!is_scalar($value) || !is_numeric((string)$value) || !is_finite((float)$value) || (float)$value < 0) throw new InvalidArgumentException('Nilai keuntungan harus nol atau lebih.');
            if ($type === 'PERCENTAGE' && (float)$value > 100) throw new InvalidArgumentException('Persentase keuntungan tidak boleh lebih dari 100%.');
            if ($type === 'FIXED' && (float)$value > 999999999999999) throw new InvalidArgumentException('Nominal keuntungan terlalu besar.');
            if ($type === 'FIXED' && floor((float)$value) !== (float)$value) throw new InvalidArgumentException('Keuntungan nominal harus berupa rupiah bulat.');
            if ($action !== 'save') throw new InvalidArgumentException('Aksi tidak dikenal.');

            $pdo->beginTransaction();
            try {
                pricing_admin_validate_no_overlap($pdo, $id, $min, $max, $status);
                if ($id > 0) {
                    $check = $pdo->prepare('SELECT id FROM pricing_rules WHERE id=:id FOR UPDATE');
                    $check->execute(['id'=>$id]);
                    if (!$check->fetchColumn()) throw new InvalidArgumentException('Aturan keuntungan tidak ditemukan.');
                    $stmt = $pdo->prepare('UPDATE pricing_rules SET min_amount=:min,max_amount=:max,profit_type=:type,profit_value=:value,profit_amount=:legacy,status=:status WHERE id=:id');
                    $stmt->execute(['min'=>$min,'max'=>$max,'type'=>$type,'value'=>$value,'legacy'=>$type==='FIXED'?(int)$value:0,'status'=>$status,'id'=>$id]);
                    $auditAction = 'PRICING_RULE_UPDATED';
                    $entityId = (string)$id;
                } else {
                    $stmt = $pdo->prepare('INSERT INTO pricing_rules (min_amount,max_amount,profit_amount,profit_type,profit_value,status) VALUES (:min,:max,:legacy,:type,:value,:status)');
                    $stmt->execute(['min'=>$min,'max'=>$max,'legacy'=>$type==='FIXED'?(int)$value:0,'type'=>$type,'value'=>$value,'status'=>$status]);
                    $entityId = (string)$pdo->lastInsertId();
                    $auditAction = 'PRICING_RULE_CREATED';
                }
                $activeCount = (int)$pdo->query("SELECT COUNT(*) FROM pricing_rules WHERE status='ACTIVE'")->fetchColumn();
                if ($activeCount < 1) throw new InvalidArgumentException('Setidaknya satu aturan keuntungan harus tetap aktif.');
                $details = json_encode(['min_amount'=>$min,'max_amount'=>$max,'profit_type'=>$type,'profit_value'=>(float)$value,'status'=>$status], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
                $audit = $pdo->prepare("INSERT INTO audit_logs (actor_type,actor_id,action,entity_type,entity_id,details,ip_address) VALUES ('ADMIN',:actor,:action,'PRICING_RULE',:entity,:details,:ip)");
                $audit->execute(['actor'=>$user['id'],'action'=>$auditAction,'entity'=>$entityId,'details'=>$details,'ip'=>substr((string)($_SERVER['REMOTE_ADDR']??''),0,45)]);
                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                throw $e;
            }
            header('Location: /admin/pricing-settings.php?saved=1');
            exit;
        } catch (InvalidArgumentException $e) {
            $error = $e->getMessage();
        } catch (Throwable $e) {
            $error = 'Aturan keuntungan gagal disimpan. Pastikan migration 012 sudah diterapkan dan periksa log server.';
        }
    }
}
if (($_GET['saved'] ?? '') === '1') $notice = 'Aturan keuntungan berhasil disimpan.';
try {
    $rows = $pdo->query('SELECT id,min_amount,max_amount,profit_amount,profit_type,profit_value,status,updated_at FROM pricing_rules ORDER BY min_amount,id')->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $error = 'Kolom aturan keuntungan belum tersedia. Terapkan database/migrations/012_pricing_rules_hybrid.sql terlebih dahulu.';
}
$csrf = admin_csrf_token();
function pricing_admin_money(mixed $value): string { return 'Rp ' . number_format((float)$value, 0, ',', '.'); }
?>
<!doctype html>
<html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Pengaturan Keuntungan · PluQ-I</title>
<style>
:root{color-scheme:dark;--bg:#0c0d10;--panel:#15171d;--line:#292d36;--text:#f3f4f6;--muted:#9ca3af;--accent:#a7f3d0}
*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--text);font:14px/1.5 system-ui,sans-serif}main{max-width:1200px;margin:auto;padding:24px}h1,h2{margin:0 0 12px}.muted{color:var(--muted)}.panel{background:var(--panel);border:1px solid var(--line);border-radius:12px;padding:18px;margin:16px 0}.bar{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap}.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:10px}label{display:grid;gap:6px;color:var(--muted)}input,select,button{width:100%;border:1px solid var(--line);background:#0d0f13;color:var(--text);padding:10px;border-radius:7px;font:inherit}button{cursor:pointer;background:#d1fae5;color:#111827;font-weight:700}.notice,.error{padding:10px;border-radius:7px;margin:12px 0}.notice{background:#15352b}.error{background:#3b1d22}a{color:var(--accent)}.table-wrap{overflow:auto}table{width:100%;border-collapse:collapse}th,td{text-align:left;padding:10px;border-bottom:1px solid var(--line);vertical-align:top}th{color:var(--muted)}.rule-form{min-width:790px}.rule-form .grid{grid-template-columns:1fr 1fr 1fr 1fr .8fr .8fr auto;align-items:end}.small{font-size:12px}.actions{display:flex;gap:10px;align-items:center;flex-wrap:wrap}.actions button{width:auto}@media(max-width:650px){main{padding:12px}.panel{padding:12px}}
</style></head><body><main>
<header class="bar"><div><h1>Pengaturan Keuntungan</h1><div class="muted">Atur keuntungan berdasarkan rentang harga modal: nominal rupiah atau persentase.</div></div><a href="/admin/">Kembali ke panel admin</a></header>
<?php if($error!==''):?><div class="error"><?=admin_escape($error)?></div><?php endif;?>
<?php if($notice!==''):?><div class="notice"><?=admin_escape($notice)?></div><?php endif;?>
<section class="panel"><h2>Cara kerja</h2><p class="muted">Harga jual = harga modal supplier + keuntungan sesuai aturan aktif. Persentase dihitung dari harga modal dan dibulatkan ke rupiah terdekat. Biaya admin invoice tetap dihitung terpisah: Rp1.000 untuk harga jual sampai Rp100.000, dan Rp2.000 di atasnya. Perubahan berlaku pada katalog dan kutipan checkout berikutnya; transaksi yang sudah dibuat tidak diubah.</p><p class="muted">Rentang aktif tidak boleh tumpang tindih. Kosongkan harga maksimum untuk batas tanpa akhir. Nonaktifkan aturan hanya jika aturan aktif lain tetap mencakup harga produk yang ingin dijual.</p></section>
<section class="panel"><h2>Tambah aturan keuntungan</h2>
<?php if(!$canManage):?><p class="muted">Akun ini hanya dapat melihat. Peran ADMIN atau SUPER_ADMIN diperlukan untuk mengubah aturan.</p><?php else:?>
<form method="post" class="rule-form"><input type="hidden" name="csrf" value="<?=admin_escape($csrf)?>"><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="0">
<div class="grid">
<label>Modal minimum (Rp)<input type="number" name="min_amount" min="0" step="1" required value="0"></label>
<label>Modal maksimum (Rp)<input type="number" name="max_amount" min="0" step="1" placeholder="Kosong = tanpa batas"></label>
<label>Jenis keuntungan<select name="profit_type"><option value="FIXED">Nominal tetap (Rp)</option><option value="PERCENTAGE">Persentase (%)</option></select></label>
<label>Nilai keuntungan<input type="number" name="profit_value" min="0" max="999999999999999" step="1" required value="500"></label>
<label>Status<select name="status"><option value="ACTIVE">Aktif</option><option value="INACTIVE">Nonaktif</option></select></label>
<div></div><button type="submit">Tambah aturan</button>
</div></form><?php endif;?></section>
<section class="panel"><h2>Aturan tersimpan</h2><p class="muted">Setiap baris dapat diedit. Gunakan angka batas yang tidak saling bertabrakan.</p><div class="table-wrap"><table><thead><tr><th>ID</th><th>Rentang modal dan keuntungan</th><th>Status / pembaruan</th></tr></thead><tbody>
<?php if(!$rows):?><tr><td colspan="3" class="muted">Belum ada aturan atau migration belum diterapkan.</td></tr><?php endif;?>
<?php foreach($rows as $row):?><tr><td>#<?=number_format((int)$row['id'])?></td><td>
<?php if($canManage):?><form method="post" class="rule-form"><input type="hidden" name="csrf" value="<?=admin_escape($csrf)?>"><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?=admin_escape($row['id'])?>"><div class="grid">
<label>Modal minimum<input type="number" name="min_amount" min="0" step="1" required value="<?=admin_escape((int)$row['min_amount'])?>"></label>
<label>Modal maksimum<input type="number" name="max_amount" min="0" step="1" placeholder="Tanpa batas" value="<?= $row['max_amount']===null?'':admin_escape((int)$row['max_amount'])?>"></label>
<label>Jenis<select name="profit_type"><option value="FIXED" <?=$row['profit_type']==='FIXED'?'selected':''?>>Nominal (Rp)</option><option value="PERCENTAGE" <?=$row['profit_type']==='PERCENTAGE'?'selected':''?>>Persentase (%)</option></select></label>
<label>Nilai<input type="number" name="profit_value" min="0" max="999999999999999" step="<?=$row['profit_type']==='PERCENTAGE'?'0.01':'1'?>" required value="<?=admin_escape((float)$row['profit_value'])?>"></label>
<label>Status<select name="status"><option value="ACTIVE" <?=$row['status']==='ACTIVE'?'selected':''?>>Aktif</option><option value="INACTIVE" <?=$row['status']==='INACTIVE'?'selected':''?>>Nonaktif</option></select></label>
<div></div><button type="submit">Simpan</button>
</div></form><?php else:?><?=pricing_admin_money($row['min_amount'])?> – <?= $row['max_amount']===null?'tanpa batas':pricing_admin_money($row['max_amount'])?> · <?= $row['profit_type']==='PERCENTAGE'?admin_escape($row['profit_value']).'%':pricing_admin_money($row['profit_value'])?><?php endif;?>
</td><td><span><?=admin_escape($row['status'])?></span><div class="muted small"><?=admin_escape($row['updated_at'])?></div></td></tr><?php endforeach;?>
</tbody></table></div></section>
</main></body></html>