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
$editing = null;

function banner_setting_url(string $url, bool $allowRelative = false): bool
{
    if ($allowRelative && str_starts_with($url, '/') && !str_starts_with($url, '//')) {
        return !str_contains($url, "\n") && !str_contains($url, "\r");
    }
    return filter_var($url, FILTER_VALIDATE_URL) !== false
        && strtolower((string) parse_url($url, PHP_URL_SCHEME)) === 'https';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!admin_csrf_valid($_POST['csrf'] ?? null)) {
        http_response_code(403);
        $error = 'Sesi formulir tidak valid. Muat ulang halaman dan coba lagi.';
    } elseif (!$canManage) {
        http_response_code(403);
        $error = 'Peran akun Anda tidak diizinkan mengubah banner.';
    } else {
        $action = (string) ($_POST['action'] ?? '');
        try {
            if ($action === 'save') {
                $id = filter_var($_POST['id'] ?? '0', FILTER_VALIDATE_INT);
                $title = trim((string) ($_POST['title'] ?? ''));
                $imageUrl = trim((string) ($_POST['image_url'] ?? ''));
                $targetUrl = trim((string) ($_POST['target_url'] ?? ''));
                $sortOrder = filter_var($_POST['sort_order'] ?? '0', FILTER_VALIDATE_INT);
                $status = (string) ($_POST['status'] ?? 'ACTIVE');
                if ($id === false || $id < 0 || $title === '' || strlen($title) > 160
                    || strlen($imageUrl) > 2000 || !banner_setting_url($imageUrl)
                    || strlen($targetUrl) > 2000 || ($targetUrl !== '' && !banner_setting_url($targetUrl, true))
                    || $sortOrder === false || $sortOrder < 0 || !in_array($status, ['ACTIVE', 'INACTIVE'], true)) {
                    throw new InvalidArgumentException('Periksa judul, URL gambar HTTPS, tautan tujuan, urutan, dan status banner.');
                }
                if ($id > 0) {
                    $stmt = $pdo->prepare('UPDATE banners SET title=:title,image_url=:image,target_url=:target,sort_order=:sort,status=:status WHERE id=:id');
                    $stmt->execute(['title'=>$title,'image'=>$imageUrl,'target'=>$targetUrl !== '' ? $targetUrl : null,'sort'=>$sortOrder,'status'=>$status,'id'=>$id]);
                    if ($stmt->rowCount() === 0) {
                        $check = $pdo->prepare('SELECT id FROM banners WHERE id=:id');
                        $check->execute(['id'=>$id]);
                        if (!$check->fetchColumn()) throw new RuntimeException('Banner yang diedit tidak ditemukan.');
                    }
                    $entityId = (string) $id;
                    $auditAction = 'BANNER_UPDATED';
                } else {
                    $stmt = $pdo->prepare('INSERT INTO banners (title,image_url,target_url,sort_order,status) VALUES (:title,:image,:target,:sort,:status)');
                    $stmt->execute(['title'=>$title,'image'=>$imageUrl,'target'=>$targetUrl !== '' ? $targetUrl : null,'sort'=>$sortOrder,'status'=>$status]);
                    $entityId = (string) $pdo->lastInsertId();
                    $auditAction = 'BANNER_CREATED';
                }
                $audit = $pdo->prepare("INSERT INTO audit_logs (actor_type,actor_id,action,entity_type,entity_id,details,ip_address) VALUES ('ADMIN',:actor,:action,'BANNER',:entity,:details,:ip)");
                $audit->execute([
                    'actor'=>$user['id'], 'action'=>$auditAction, 'entity'=>$entityId,
                    'details'=>json_encode(['title'=>$title,'status'=>$status,'sort_order'=>$sortOrder], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),
                    'ip'=>substr((string)($_SERVER['REMOTE_ADDR'] ?? ''),0,45),
                ]);
                header('Location: /admin/template-settings.php?saved=1');
                exit;
            }
            if ($action === 'deactivate') {
                $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT);
                if (!$id || $id < 1) throw new InvalidArgumentException('ID banner tidak valid.');
                $stmt = $pdo->prepare("UPDATE banners SET status='INACTIVE' WHERE id=:id");
                $stmt->execute(['id'=>$id]);
                $audit = $pdo->prepare("INSERT INTO audit_logs (actor_type,actor_id,action,entity_type,entity_id,ip_address) VALUES ('ADMIN',:actor,'BANNER_DEACTIVATED','BANNER',:entity,:ip)");
                $audit->execute(['actor'=>$user['id'],'entity'=>(string)$id,'ip'=>substr((string)($_SERVER['REMOTE_ADDR'] ?? ''),0,45)]);
                header('Location: /admin/template-settings.php?deactivated=1');
                exit;
            }
            throw new InvalidArgumentException('Aksi tidak dikenal.');
        } catch (InvalidArgumentException $e) {
            $error = $e->getMessage();
        } catch (Throwable $e) {
            $error = 'Perubahan banner gagal. Periksa log server dan pastikan migration database sudah diterapkan.';
        }
    }
}
if (($_GET['saved'] ?? '') === '1') $notice = 'Banner berhasil disimpan.';
if (($_GET['deactivated'] ?? '') === '1') $notice = 'Banner dinonaktifkan dan tidak akan ditampilkan di halaman depan.';
if (isset($_GET['edit'])) {
    $id = filter_var($_GET['edit'], FILTER_VALIDATE_INT);
    if ($id && $id > 0) {
        $stmt = $pdo->prepare('SELECT id,title,image_url,target_url,sort_order,status FROM banners WHERE id=:id LIMIT 1');
        $stmt->execute(['id'=>$id]);
        $editing = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
}
$banners = $pdo->query('SELECT id,title,image_url,target_url,sort_order,status FROM banners ORDER BY sort_order,id DESC')->fetchAll(PDO::FETCH_ASSOC);
$csrf = admin_csrf_token();
?>
<!doctype html>
<html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Pengaturan Template · PluQ-I</title>
<style>
:root{color-scheme:dark;--bg:#0c0d10;--panel:#15171d;--line:#292d36;--text:#f3f4f6;--muted:#9ca3af;--accent:#a7f3d0}
*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--text);font:14px/1.5 system-ui,sans-serif}main{max-width:1150px;margin:auto;padding:24px}h1,h2{margin:0 0 12px}.muted{color:var(--muted)}.panel{background:var(--panel);border:1px solid var(--line);border-radius:12px;padding:18px;margin:16px 0}.bar{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap}label{display:grid;gap:6px;color:var(--muted)}input,select,button{width:100%;border:1px solid var(--line);background:#0d0f13;color:var(--text);padding:10px;border-radius:7px;font:inherit}button{cursor:pointer;background:#d1fae5;color:#111827;font-weight:700}.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px}.actions{display:flex;gap:8px;flex-wrap:wrap;margin-top:12px}.actions button{width:auto}.notice,.error{padding:10px;border-radius:7px;margin:12px 0}.notice{background:#15352b}.error{background:#3b1d22}a{color:var(--accent)}.table-wrap{overflow:auto}table{width:100%;border-collapse:collapse}th,td{text-align:left;padding:10px;border-bottom:1px solid var(--line);vertical-align:top}th{color:var(--muted)}.thumb{width:140px;max-height:70px;object-fit:cover;border-radius:6px;background:#08090b}.small{font-size:12px;overflow-wrap:anywhere;max-width:260px}.inline{display:inline}.inline button{padding:6px 9px;width:auto}@media(max-width:600px){main{padding:12px}.panel{padding:12px}}
</style></head><body><main>
<header class="bar"><div><h1>Pengaturan Template</h1><div class="muted">Kelola banner slider di halaman depan PluQ-I.</div></div><a href="/admin/">Kembali ke panel admin</a></header>
<?php if ($error !== ''): ?><div class="error"><?=admin_escape($error)?></div><?php endif; ?>
<?php if ($notice !== ''): ?><div class="notice"><?=admin_escape($notice)?></div><?php endif; ?>
<section class="panel"><h2><?= $editing ? 'Edit banner' : 'Tambah banner' ?></h2>
<p class="muted">Slider halaman depan berputar otomatis setiap 3 detik. Gunakan gambar yang Anda miliki atau berizin untuk digunakan. Saat ini gambar dimasukkan melalui URL HTTPS; unggah file langsung belum diaktifkan.</p>
<?php if (!$canManage): ?><p class="muted">Akun ini hanya dapat melihat pengaturan. ADMIN atau SUPER_ADMIN diperlukan untuk mengubah banner.</p><?php else: ?>
<form method="post"><input type="hidden" name="csrf" value="<?=admin_escape($csrf)?>"><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?=admin_escape($editing['id'] ?? 0)?>">
<div class="grid">
<label>Nama/judul banner<input name="title" maxlength="160" required value="<?=admin_escape($editing['title'] ?? '')?>" placeholder="Contoh: Promo Mobile Legends"></label>
<label>URL gambar (HTTPS)<input type="url" name="image_url" maxlength="2000" required value="<?=admin_escape($editing['image_url'] ?? '')?>" placeholder="https://.../banner.webp"></label>
<label>Tautan tujuan saat diklik (opsional)<input type="text" name="target_url" maxlength="2000" value="<?=admin_escape($editing['target_url'] ?? '')?>" placeholder="/game/mobile-legends atau https://..."></label>
<label>Urutan tampilan<input type="number" name="sort_order" min="0" max="1000000" required value="<?=admin_escape($editing['sort_order'] ?? 0)?>"></label>
<label>Status<select name="status"><option value="ACTIVE" <?=($editing['status'] ?? 'ACTIVE')==='ACTIVE'?'selected':''?>>Aktif</option><option value="INACTIVE" <?=($editing['status'] ?? '')==='INACTIVE'?'selected':''?>>Nonaktif</option></select></label>
</div><div class="actions"><button type="submit">Simpan banner</button><?php if ($editing): ?><a href="/admin/template-settings.php">Batal edit</a><?php endif; ?></div>
</form><?php endif; ?></section>
<section class="panel"><h2>Daftar banner</h2><p class="muted">Banner aktif akan dikirim melalui API katalog untuk slider halaman depan. Urutan lebih kecil tampil lebih dahulu.</p>
<div class="table-wrap"><table><thead><tr><th>Pratinjau</th><th>Judul dan tautan</th><th>Urutan</th><th>Status</th><th>Aksi</th></tr></thead><tbody>
<?php if (!$banners): ?><tr><td colspan="5" class="muted">Belum ada banner tersimpan.</td></tr><?php endif; ?>
<?php foreach ($banners as $banner): ?><tr><td><img class="thumb" src="<?=admin_escape($banner['image_url'])?>" alt="<?=admin_escape($banner['title'])?>" loading="lazy"></td><td><?=admin_escape($banner['title'])?><div class="muted small"><?=admin_escape($banner['image_url'])?></div><div class="muted small">Tujuan: <?=admin_escape($banner['target_url'] ?: 'Tidak ada')?></div></td><td><?=number_format((int)$banner['sort_order'])?></td><td><?=admin_escape($banner['status'])?></td><td><div class="actions"><a href="/admin/template-settings.php?edit=<?=(int)$banner['id']?>">Edit</a><?php if ($canManage && $banner['status']==='ACTIVE'): ?><form class="inline" method="post" onsubmit="return confirm('Nonaktifkan banner ini?')"><input type="hidden" name="csrf" value="<?=admin_escape($csrf)?>"><input type="hidden" name="action" value="deactivate"><input type="hidden" name="id" value="<?=(int)$banner['id']?>"><button type="submit">Nonaktifkan</button></form><?php endif; ?></div></td></tr><?php endforeach; ?>
</tbody></table></div></section>
<section class="panel"><h2>Catatan tampilan</h2><ul><li>Gunakan gambar landscape yang responsif untuk banner Mobile Legends, HOK, Free Fire, PUBG, atau promosi lain.</li><li>Slider publik harus menampilkan banner aktif, dengan tombol sebelumnya/berikutnya, indikator slide, perulangan, jeda saat kursor berada di atas slider, dan dukungan geser pada ponsel.</li><li>Pengaturan ini mengelola data banner; pengujian visual slider di browser masih perlu dilakukan setelah perubahan diterapkan.</li></ul></section>
</main></body></html>
