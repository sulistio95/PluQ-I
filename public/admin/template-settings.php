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

function banner_local_upload_path(string $url): ?string
{
    $path = parse_url($url, PHP_URL_PATH);
    if (!is_string($path) || !preg_match('~^/uploads/banners/([a-f0-9]{32})\\.(jpg|png|webp)$~D', $path, $matches)) {
        return null;
    }
    return PLUQI_ROOT . '/public/uploads/banners/' . $matches[1] . '.' . $matches[2];
}

function banner_store_upload(array $file): string
{
    if (!isset($file['error'], $file['tmp_name'], $file['size'])
        || is_array($file['error']) || (int) $file['error'] !== UPLOAD_ERR_OK) {
        throw new InvalidArgumentException('Unggahan gambar gagal. Pilih gambar kembali.');
    }
    if ((int) $file['size'] < 1 || (int) $file['size'] > 5 * 1024 * 1024
        || !is_uploaded_file((string) $file['tmp_name'])) {
        throw new InvalidArgumentException('Ukuran gambar maksimal 5 MB.');
    }
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file((string) $file['tmp_name']);
    $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    if (!is_string($mime) || !isset($extensions[$mime])) {
        throw new InvalidArgumentException('Format gambar harus JPG, PNG, atau WebP. SVG dan file lain tidak diizinkan.');
    }
    $imageInfo = @getimagesize((string) $file['tmp_name']);
    if (!is_array($imageInfo) || (int) $imageInfo[0] < 1 || (int) $imageInfo[1] < 1
        || (int) $imageInfo[0] > 8000 || (int) $imageInfo[1] > 8000
        || (int) $imageInfo[0] * (int) $imageInfo[1] > 25000000) {
        throw new InvalidArgumentException('Dimensi gambar tidak valid atau terlalu besar.');
    }
    $directory = PLUQI_ROOT . '/public/uploads/banners';
    if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
        throw new RuntimeException('Direktori unggahan tidak dapat dibuat.');
    }
    $filename = bin2hex(random_bytes(16)) . '.' . $extensions[$mime];
    if (!move_uploaded_file((string) $file['tmp_name'], $directory . '/' . $filename)) {
        throw new RuntimeException('Server tidak dapat menyimpan gambar. Periksa izin folder uploads.');
    }
    return '/uploads/banners/' . $filename;
}

function banner_remove_local_file(string $url): bool
{
    $path = banner_local_upload_path($url);
    if ($path === null || !is_file($path)) {
        return true;
    }
    return @unlink($path);
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
                    || strlen($imageUrl) > 2000 || ($imageUrl !== '' && !banner_setting_url($imageUrl))
                    || strlen($targetUrl) > 2000 || ($targetUrl !== '' && !banner_setting_url($targetUrl, true))
                    || $sortOrder === false || $sortOrder < 0 || !in_array($status, ['ACTIVE', 'INACTIVE'], true)) {
                    throw new InvalidArgumentException('Periksa judul, URL gambar HTTPS, tautan tujuan, urutan, dan status banner.');
                }

                $existing = null;
                if ($id > 0) {
                    $findExisting = $pdo->prepare('SELECT id,image_url FROM banners WHERE id=:id LIMIT 1');
                    $findExisting->execute(['id'=>$id]);
                    $existing = $findExisting->fetch(PDO::FETCH_ASSOC) ?: null;
                    if (!$existing) throw new InvalidArgumentException('Banner yang diedit tidak ditemukan.');
                }
                $uploadError = (int) ($_FILES['image_file']['error'] ?? UPLOAD_ERR_NO_FILE);
                $hasUpload = $uploadError !== UPLOAD_ERR_NO_FILE;
                $newUploadUrl = null;
                if ($hasUpload) {
                    $newUploadUrl = banner_store_upload($_FILES['image_file']);
                    $imageUrl = $newUploadUrl;
                } elseif ($imageUrl === '' && $existing) {
                    $imageUrl = (string) $existing['image_url'];
                } elseif ($imageUrl === '' && !$existing) {
                    throw new InvalidArgumentException('Pilih gambar dari laptop atau masukkan URL gambar HTTPS.');
                }

                try {
                    if ($id > 0) {
                        $stmt = $pdo->prepare('UPDATE banners SET title=:title,image_url=:image,target_url=:target,sort_order=:sort,status=:status WHERE id=:id');
                        $stmt->execute(['title'=>$title,'image'=>$imageUrl,'target'=>$targetUrl !== '' ? $targetUrl : null,'sort'=>$sortOrder,'status'=>$status,'id'=>$id]);
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
                        'details'=>json_encode(['title'=>$title,'status'=>$status,'sort_order'=>$sortOrder,'image_source'=>str_starts_with($imageUrl, '/uploads/banners/') ? 'LOCAL_UPLOAD' : 'HTTPS_URL'], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),
                        'ip'=>substr((string)($_SERVER['REMOTE_ADDR'] ?? ''),0,45),
                    ]);
                } catch (Throwable $saveError) {
                    if ($newUploadUrl !== null) banner_remove_local_file($newUploadUrl);
                    throw $saveError;
                }
                if ($existing && (string) $existing['image_url'] !== $imageUrl) {
                    banner_remove_local_file((string) $existing['image_url']);
                }
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
            if ($action === 'delete') {
                $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT);
                if (!$id || $id < 1) throw new InvalidArgumentException('ID banner tidak valid.');
                $pdo->beginTransaction();
                $stmt = $pdo->prepare('SELECT id,title,image_url FROM banners WHERE id=:id FOR UPDATE');
                $stmt->execute(['id'=>$id]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$row) throw new InvalidArgumentException('Banner tidak ditemukan.');
                $audit = $pdo->prepare("INSERT INTO audit_logs (actor_type,actor_id,action,entity_type,entity_id,details,ip_address) VALUES ('ADMIN',:actor,'BANNER_DELETED','BANNER',:entity,:details,:ip)");
                $audit->execute([
                    'actor'=>$user['id'], 'entity'=>(string)$id,
                    'details'=>json_encode(['title'=>$row['title'],'local_image'=>banner_local_upload_path((string)$row['image_url']) !== null], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),
                    'ip'=>substr((string)($_SERVER['REMOTE_ADDR'] ?? ''),0,45),
                ]);
                $remove = $pdo->prepare('DELETE FROM banners WHERE id=:id');
                $remove->execute(['id'=>$id]);
                $pdo->commit();
                if (!banner_remove_local_file((string)$row['image_url'])) {
                    header('Location: /admin/template-settings.php?deleted=1&file_cleanup=failed');
                } else {
                    header('Location: /admin/template-settings.php?deleted=1');
                }
                exit;
            }
            throw new InvalidArgumentException('Aksi tidak dikenal.');
        } catch (InvalidArgumentException $e) {
            $error = $e->getMessage();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $error = 'Perubahan banner gagal. Periksa log server dan pastikan folder uploads dapat ditulis.';
        }
    }
}
if (($_GET['saved'] ?? '') === '1') $notice = 'Banner berhasil disimpan.';
if (($_GET['deactivated'] ?? '') === '1') $notice = 'Banner dinonaktifkan dan tidak akan ditampilkan di halaman depan.';
if (($_GET['deleted'] ?? '') === '1') $notice = (($_GET['file_cleanup'] ?? '') === 'failed') ? 'Banner dihapus dari database, tetapi file gambar lokal gagal dihapus. Periksa folder public/uploads/banners.' : 'Banner dihapus. Jika menggunakan gambar lokal, file gambarnya juga dihapus.';
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
<p class="muted">Slider halaman depan berputar otomatis setiap 3 detik. Gunakan gambar yang Anda miliki atau berizin untuk digunakan. Pilih gambar dari laptop (JPG/PNG/WebP, maksimal 5 MB) atau masukkan URL HTTPS. Jika mengunggah gambar baru saat edit, gambar lokal lama akan dihapus setelah penggantian berhasil.</p>
<?php if (!$canManage): ?><p class="muted">Akun ini hanya dapat melihat pengaturan. ADMIN atau SUPER_ADMIN diperlukan untuk mengubah banner.</p><?php else: ?>
<form method="post" enctype="multipart/form-data"><input type="hidden" name="csrf" value="<?=admin_escape($csrf)?>"><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?=admin_escape($editing['id'] ?? 0)?>">
<div class="grid">
<label>Nama/judul banner<input name="title" maxlength="160" required value="<?=admin_escape($editing['title'] ?? '')?>" placeholder="Contoh: Promo Mobile Legends"></label>
<label>Unggah gambar dari laptop (JPG/PNG/WebP, maks. 5 MB)<input type="file" name="image_file" accept="image/jpeg,image/png,image/webp"></label>
<label>Atau URL gambar (HTTPS)<input type="url" name="image_url" maxlength="2000" value="<?=admin_escape(isset($editing['image_url']) && !str_starts_with((string)$editing['image_url'], '/uploads/banners/') ? $editing['image_url'] : '')?>" placeholder="https://.../banner.webp"><span class="muted">Kosongkan saat edit untuk mempertahankan gambar saat ini, atau unggah gambar baru.</span></label>
<label>Tautan tujuan saat diklik (opsional)<input type="text" name="target_url" maxlength="2000" value="<?=admin_escape($editing['target_url'] ?? '')?>" placeholder="/game/mobile-legends atau https://..."></label>
<label>Urutan tampilan<input type="number" name="sort_order" min="0" max="1000000" required value="<?=admin_escape($editing['sort_order'] ?? 0)?>"></label>
<label>Status<select name="status"><option value="ACTIVE" <?=($editing['status'] ?? 'ACTIVE')==='ACTIVE'?'selected':''?>>Aktif</option><option value="INACTIVE" <?=($editing['status'] ?? '')==='INACTIVE'?'selected':''?>>Nonaktif</option></select></label>
</div><div class="actions"><button type="submit">Simpan banner</button><?php if ($editing): ?><a href="/admin/template-settings.php">Batal edit</a><?php endif; ?></div>
</form><?php endif; ?></section>
<section class="panel"><h2>Daftar banner</h2><p class="muted">Banner aktif akan dikirim melalui API katalog untuk slider halaman depan. Urutan lebih kecil tampil lebih dahulu.</p>
<div class="table-wrap"><table><thead><tr><th>Pratinjau</th><th>Judul dan tautan</th><th>Urutan</th><th>Status</th><th>Aksi</th></tr></thead><tbody>
<?php if (!$banners): ?><tr><td colspan="5" class="muted">Belum ada banner tersimpan.</td></tr><?php endif; ?>
<?php foreach ($banners as $banner): ?><tr><td><img class="thumb" src="<?=admin_escape($banner['image_url'])?>" alt="<?=admin_escape($banner['title'])?>" loading="lazy"></td><td><?=admin_escape($banner['title'])?><div class="muted small"><?=admin_escape($banner['image_url'])?></div><div class="muted small">Tujuan: <?=admin_escape($banner['target_url'] ?: 'Tidak ada')?></div></td><td><?=number_format((int)$banner['sort_order'])?></td><td><?=admin_escape($banner['status'])?></td><td><div class="actions"><a href="/admin/template-settings.php?edit=<?=(int)$banner['id']?>">Edit</a><?php if ($canManage): ?><?php if ($banner['status']==='ACTIVE'): ?><form class="inline" method="post" onsubmit="return confirm('Nonaktifkan banner ini?')"><input type="hidden" name="csrf" value="<?=admin_escape($csrf)?>"><input type="hidden" name="action" value="deactivate"><input type="hidden" name="id" value="<?=(int)$banner['id']?>"><button type="submit">Nonaktifkan</button></form><?php endif; ?><form class="inline" method="post" onsubmit="return confirm('Hapus banner ini secara permanen? File lokal yang terkait juga akan dihapus.')"><input type="hidden" name="csrf" value="<?=admin_escape($csrf)?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?=(int)$banner['id']?>"><button type="submit">Hapus</button></form><?php endif; ?></div></td></tr><?php endforeach; ?>
</tbody></table></div></section>
<section class="panel"><h2>Catatan tampilan</h2><ul><li>Gunakan gambar landscape yang responsif untuk banner Mobile Legends, HOK, Free Fire, PUBG, atau promosi lain.</li><li>Slider publik harus menampilkan banner aktif, dengan tombol sebelumnya/berikutnya, indikator slide, perulangan, jeda saat kursor berada di atas slider, dan dukungan geser pada ponsel.</li><li>Unggahan dibatasi ke JPG/PNG/WebP, maksimal 5 MB dan pemeriksaan dimensi; file diberi nama acak dan disimpan di public/uploads/banners.</li><li>Nonaktifkan hanya menyembunyikan banner. Hapus menghapus baris banner dan file lokal yang cocok; gambar pada URL eksternal tidak dihapus dari situs asalnya.</li><li>Pengujian visual slider di browser masih perlu dilakukan setelah perubahan diterapkan.</li></ul></section>
</main></body></html>
