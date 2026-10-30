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
if (!admin_can_manage_settings($user)) { http_response_code(403); exit('Akses ditolak.'); }

$error = '';
$notice = '';
$items = [];
$settingKey = 'homepage_announcements';

function announcement_load(PDO $pdo, string $key): array
{
    $stmt = $pdo->prepare('SELECT setting_value FROM settings WHERE setting_key = :key LIMIT 1');
    $stmt->execute(['key' => $key]);
    $raw = $stmt->fetchColumn();
    if (!is_string($raw) || $raw === '') return [];
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) return [];
    return array_values(array_filter($decoded, static fn($item) => is_array($item)
        && isset($item['id'], $item['message'], $item['type'], $item['active'])
        && is_string($item['id']) && is_string($item['message'])
        && is_string($item['type']) && is_bool($item['active'])));
}
function announcement_save(PDO $pdo, string $key, array $items, int $adminId): void
{
    $json = json_encode(array_values($items), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    $stmt = $pdo->prepare('INSERT INTO settings (setting_key, setting_value) VALUES (:key, :value) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)');
    $stmt->execute(['key' => $key, 'value' => $json]);
    $audit = $pdo->prepare("INSERT INTO audit_logs (actor_type, actor_id, action, entity_type, entity_id, details, ip_address) VALUES ('ADMIN', :id, :action, 'HOMEPAGE_ANNOUNCEMENT', :entity, :details, :ip)");
    $audit->execute([
        'id' => $adminId,
        'action' => 'HOMEPAGE_ANNOUNCEMENTS_UPDATED',
        'entity' => $key,
        'details' => json_encode(['count' => count($items)], JSON_UNESCAPED_SLASHES),
        'ip' => substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45),
    ]);
}

$items = announcement_load($pdo, $settingKey);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!admin_csrf_valid($_POST['csrf'] ?? null)) {
        http_response_code(403);
        $error = 'Sesi formulir tidak valid. Muat ulang halaman.';
    } else {
        $action = (string)($_POST['action'] ?? '');
        $id = trim((string)($_POST['id'] ?? ''));
        if ($action === 'save') {
            $message = trim((string)($_POST['message'] ?? ''));
            $type = (string)($_POST['type'] ?? 'info');
            if ($message === '' || mb_strlen($message, 'UTF-8') > 300) {
                $error = 'Isi pengumuman wajib diisi dan maksimal 300 karakter.';
            } elseif ($id === '' && count($items) >= 20) {
                $error = 'Maksimal 20 pengumuman dapat disimpan. Hapus pengumuman lama terlebih dahulu.';
            } elseif (!in_array($type, ['info', 'maintenance', 'warning', 'promo'], true)) {
                $error = 'Jenis pengumuman tidak valid.';
            } else {
                $active = isset($_POST['active']);
                $found = false;
                foreach ($items as &$item) {
                    if ($id !== '' && hash_equals((string)$item['id'], $id)) {
                        $item['message'] = $message; $item['type'] = $type; $item['active'] = $active; $found = true; break;
                    }
                }
                unset($item);
                if (!$found) {
                    $items[] = ['id' => bin2hex(random_bytes(8)), 'message' => $message, 'type' => $type, 'active' => $active];
                }
                announcement_save($pdo, $settingKey, $items, (int)$user['id']);
                header('Location: /admin/announcements.php?saved=1'); exit;
            }
        } elseif ($action === 'toggle' || $action === 'delete') {
            foreach ($items as $index => &$item) {
                if ($id !== '' && hash_equals((string)$item['id'], $id)) {
                    if ($action === 'delete') array_splice($items, $index, 1);
                    else $item['active'] = !$item['active'];
                    break;
                }
            }
            unset($item);
            announcement_save($pdo, $settingKey, $items, (int)$user['id']);
            header('Location: /admin/announcements.php?saved=1'); exit;
        }
    }
}
if (isset($_GET['saved'])) $notice = 'Pengumuman berhasil diperbarui.';
$editId = (string)($_GET['edit'] ?? '');
$editing = null;
foreach ($items as $item) if ($editId !== '' && hash_equals((string)$item['id'], $editId)) { $editing = $item; break; }
$csrf = admin_csrf_token();
function announcement_h(mixed $value): string { return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
?>
<!doctype html>
<html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Pengumuman Halaman Awal - Admin PluQ-I</title><link rel="stylesheet" href="/assets/admin-sidebar.css">
<style>
:root{color-scheme:dark;--bg:#0c0d10;--panel:#15171d;--line:#292d36;--text:#f3f4f6;--muted:#9ca3af;--accent:#a7f3d0}
*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--text);font:14px/1.5 system-ui,sans-serif}
main{max-width:1200px;margin:auto;padding:24px}h1{font-size:24px;margin:0 0 8px}.muted{color:var(--muted)}
.panel{background:var(--panel);border:1px solid var(--line);border-radius:12px;padding:18px;margin:16px 0}
label{display:grid;gap:6px;color:var(--muted);margin:10px 0}
input,select,textarea,button{width:100%;border:1px solid var(--line);background:#0d0f13;color:var(--text);padding:10px;border-radius:7px;font:inherit}
textarea{min-height:90px;resize:vertical}button{cursor:pointer;background:#d1fae5;color:#111827;font-weight:700}
.inline{display:flex;gap:8px;align-items:center;flex-wrap:wrap}.inline button,.inline a{width:auto}.inline a{color:var(--accent)}
.notice{padding:10px;border-radius:8px;background:#163326;color:#bbf7d0}.error{background:#3b1d22;color:#fecaca}
.item{display:flex;gap:12px;justify-content:space-between;align-items:center;border-bottom:1px solid var(--line);padding:14px 0}.item:last-child{border:0}
.item p{margin:0;overflow-wrap:anywhere}.tag{font-size:11px;color:#a7f3d0;text-transform:uppercase}
@media(max-width:760px){main{padding:14px}.item{align-items:flex-start;flex-direction:column}}
</style></head>
<body class="admin-layout">
<?php require PLUQI_ROOT . '/app/views/admin_sidebar.php'; ?>
<main>
<h1>Pengumuman Halaman Awal</h1><p class="muted">Kelola teks berjalan yang tampil di halaman utama toko.</p>
<?php if ($error !== ''): ?><p class="notice error"><?=announcement_h($error)?></p><?php endif; ?>
<?php if ($notice !== ''): ?><p class="notice"><?=announcement_h($notice)?></p><?php endif; ?>
<section class="panel">
<h2><?= $editing ? 'Edit pengumuman' : 'Tambah pengumuman' ?></h2>
<form method="post">
<input type="hidden" name="csrf" value="<?=announcement_h($csrf)?>">
<input type="hidden" name="action" value="save">
<input type="hidden" name="id" value="<?=announcement_h($editing['id'] ?? '')?>">
<label>Isi pengumuman<textarea name="message" maxlength="300" required placeholder="Contoh: Pemeliharaan sistem pada pukul 23.00–01.00 WIB."><?=announcement_h($editing['message'] ?? '')?></textarea></label>
<label>Jenis pengumuman<select name="type">
<?php foreach (['info'=>'Informasi','maintenance'=>'Maintenance','warning'=>'Peringatan','promo'=>'Promo'] as $value=>$label): ?>
<option value="<?=announcement_h($value)?>"<?=($editing['type'] ?? 'info') === $value ? ' selected' : ''?>><?=announcement_h($label)?></option>
<?php endforeach; ?></select></label>
<label><span><input style="width:auto" type="checkbox" name="active" value="1"<?=(!$editing || ($editing['active'] ?? false)) ? ' checked' : ''?>> Aktifkan dan tampilkan di halaman awal</span></label>
<div class="inline"><button type="submit">Simpan pengumuman</button><?php if ($editing): ?><a href="/admin/announcements.php">Batal edit</a><?php endif; ?></div>
</form></section>
<section class="panel"><h2>Daftar pengumuman</h2>
<?php if (!$items): ?><p class="muted">Belum ada pengumuman.</p><?php endif; ?>
<?php foreach ($items as $item): ?><div class="item"><div><span class="tag"><?=announcement_h($item['type'])?> · <?=!empty($item['active']) ? 'Aktif' : 'Nonaktif'?></span><p><?=announcement_h($item['message'])?></p></div>
<div class="inline"><a href="/admin/announcements.php?edit=<?=rawurlencode($item['id'])?>">Edit</a>
<form method="post"><input type="hidden" name="csrf" value="<?=announcement_h($csrf)?>"><input type="hidden" name="id" value="<?=announcement_h($item['id'])?>"><input type="hidden" name="action" value="toggle"><button type="submit"><?=!empty($item['active']) ? 'Nonaktifkan' : 'Aktifkan'?></button></form>
<form method="post" onsubmit="return confirm('Hapus pengumuman ini?')"><input type="hidden" name="csrf" value="<?=announcement_h($csrf)?>"><input type="hidden" name="id" value="<?=announcement_h($item['id'])?>"><input type="hidden" name="action" value="delete"><button type="submit">Hapus</button></form></div></div><?php endforeach; ?>
</section></main></body></html>
