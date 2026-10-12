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

$error = '';
$notice = '';
$canManage = admin_can_manage_settings($user);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!admin_csrf_valid($_POST['csrf'] ?? null)) {
        http_response_code(403);
        $error = 'Sesi formulir tidak valid. Muat ulang halaman dan coba lagi.';
    } elseif (!$canManage) {
        http_response_code(403);
        $error = 'Peran akun Anda hanya dapat melihat pemetaan, bukan mengubahnya.';
    } elseif (isset($_POST['save_mapping'])) {
        $gameId = filter_var($_POST['game_id'] ?? null, FILTER_VALIDATE_INT);
        $providerId = filter_var($_POST['provider_id'] ?? null, FILTER_VALIDATE_INT);
        $providerCode = trim((string) ($_POST['provider_game_code'] ?? ''));
        $providerName = trim((string) ($_POST['provider_game_name'] ?? ''));

        if (!$gameId || !$providerId || $providerCode === '' || strlen($providerCode) > 190 || strlen($providerName) > 190) {
            $error = 'Pilih game dan supplier, lalu masukkan kode game supplier yang valid (maksimal 190 karakter).';
        } else {
            $gameCheck = $pdo->prepare("SELECT id FROM games WHERE id=:id LIMIT 1");
            $gameCheck->execute(['id' => $gameId]);
            $providerCheck = $pdo->prepare("SELECT id FROM providers WHERE id=:id LIMIT 1");
            $providerCheck->execute(['id' => $providerId]);
            if (!$gameCheck->fetchColumn() || !$providerCheck->fetchColumn()) {
                $error = 'Game atau supplier yang dipilih tidak ditemukan.';
            } else {
                $pdo->beginTransaction();
                try {
                    $stmt = $pdo->prepare("INSERT INTO game_provider_mappings
                        (game_id, provider_id, provider_game_code, provider_game_name, status)
                        VALUES (:game_id, :provider_id, :code, :name, 'ACTIVE')
                        ON DUPLICATE KEY UPDATE game_id=VALUES(game_id),
                        provider_game_name=VALUES(provider_game_name), status='ACTIVE'");
                    $stmt->execute([
                        'game_id' => $gameId,
                        'provider_id' => $providerId,
                        'code' => $providerCode,
                        'name' => $providerName !== '' ? $providerName : null,
                    ]);
                    $lookup = $pdo->prepare("SELECT id FROM game_provider_mappings WHERE provider_id=:provider_id AND provider_game_code=:code LIMIT 1");
                    $lookup->execute(['provider_id' => $providerId, 'code' => $providerCode]);
                    $mappingId = (int) $lookup->fetchColumn();
                    $audit = $pdo->prepare("INSERT INTO audit_logs
                        (actor_type,actor_id,action,entity_type,entity_id,details,ip_address)
                        VALUES ('ADMIN',:actor,'GAME_PROVIDER_MAPPING_SAVED','GAME_PROVIDER_MAPPING',:entity,:details,:ip)");
                    $audit->execute([
                        'actor' => $user['id'],
                        'entity' => (string) $mappingId,
                        'details' => json_encode(['game_id' => $gameId, 'provider_id' => $providerId, 'provider_game_code' => $providerCode], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                        'ip' => substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45),
                    ]);
                    $pdo->commit();
                    header('Location: /admin/game-mappings.php?saved=1');
                    exit;
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    $error = 'Pemetaan gagal disimpan. Periksa log server dan pastikan migration 002 sudah diterapkan.';
                }
            }
        }
    } elseif (isset($_POST['deactivate_mapping'])) {
        $mappingId = filter_var($_POST['mapping_id'] ?? null, FILTER_VALIDATE_INT);
        if (!$mappingId || $mappingId < 1) {
            $error = 'ID pemetaan tidak valid.';
        } else {
            $pdo->beginTransaction();
            try {
                $stmt = $pdo->prepare("UPDATE game_provider_mappings SET status='INACTIVE' WHERE id=:id AND status='ACTIVE'");
                $stmt->execute(['id' => $mappingId]);
                if ($stmt->rowCount() > 0) {
                    $audit = $pdo->prepare("INSERT INTO audit_logs
                        (actor_type,actor_id,action,entity_type,entity_id,ip_address)
                        VALUES ('ADMIN',:actor,'GAME_PROVIDER_MAPPING_DEACTIVATED','GAME_PROVIDER_MAPPING',:entity,:ip)");
                    $audit->execute([
                        'actor' => $user['id'],
                        'entity' => (string) $mappingId,
                        'ip' => substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45),
                    ]);
                }
                $pdo->commit();
                header('Location: /admin/game-mappings.php?deactivated=1');
                exit;
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $error = 'Pemetaan tidak dapat dinonaktifkan. Periksa log server.';
            }
        }
    }
}

if (($_GET['saved'] ?? '') === '1') $notice = 'Pemetaan berhasil disimpan dan diaktifkan.';
if (($_GET['deactivated'] ?? '') === '1') $notice = 'Pemetaan dinonaktifkan. Produk dari kode ini tidak akan dipetakan pada sinkronisasi berikutnya.';

$games = $pdo->query("SELECT id,name,slug FROM games ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$providers = $pdo->query("SELECT id,code,name FROM providers ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$mappings = $pdo->query("SELECT m.id,m.provider_game_code,m.provider_game_name,m.status,m.updated_at,
    g.name AS game_name,g.slug,p.code AS provider_code,p.name AS provider_name
    FROM game_provider_mappings m
    JOIN games g ON g.id=m.game_id JOIN providers p ON p.id=m.provider_id
    ORDER BY m.status='ACTIVE' DESC,p.name,g.name,m.provider_game_code")->fetchAll(PDO::FETCH_ASSOC);
$csrf = admin_csrf_token();

function mapping_escape(mixed $value): string {
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
?>
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Pemetaan Game Supplier · PluQ-I</title>
<style>
:root{color-scheme:dark;--bg:#0c0d10;--panel:#15171d;--line:#292d36;--text:#f3f4f6;--muted:#9ca3af;--accent:#a7f3d0}
*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--text);font:14px/1.5 system-ui,sans-serif}main{max-width:1100px;margin:auto;padding:24px}h1,h2{margin:0 0 12px}.muted{color:var(--muted)}.panel{background:var(--panel);border:1px solid var(--line);border-radius:12px;padding:18px;margin:16px 0}.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px}label{display:grid;gap:6px;color:var(--muted)}input,select,button{width:100%;border:1px solid var(--line);background:#0d0f13;color:var(--text);padding:10px;border-radius:7px;font:inherit}button{cursor:pointer;background:#d1fae5;color:#111827;font-weight:700}.danger{background:#3b1d22;color:#fecaca}.error,.notice{padding:10px;border-radius:7px;margin:12px 0}.error{background:#3b1d22}.notice{background:#15352b}.table-wrap{overflow:auto}table{width:100%;border-collapse:collapse;white-space:nowrap}th,td{text-align:left;border-bottom:1px solid var(--line);padding:10px}th{color:var(--muted)}.status{font-size:12px;border:1px solid var(--line);border-radius:5px;padding:3px 6px}.top{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap}a{color:var(--accent)}form.inline{margin:0}form.inline button{width:auto;padding:6px 10px}@media(max-width:600px){main{padding:12px}.panel{padding:12px}}
</style>
</head>
<body><main>
<header class="top"><div><h1>Pemetaan Game Supplier</h1><div class="muted">Admin: <?=mapping_escape($user['username'])?> · <?=mapping_escape($user['role'])?></div></div><a href="/admin/">Kembali ke panel transaksi</a></header>
<?php if ($error !== ''): ?><div class="error"><?=mapping_escape($error)?></div><?php endif; ?>
<?php if ($notice !== ''): ?><div class="notice"><?=mapping_escape($notice)?></div><?php endif; ?>
<section class="panel">
<h2>Tambah atau perbarui pemetaan</h2>
<p class="muted">Masukkan kode game/merek persis seperti yang dikirim supplier pada respons API. Jangan menebak kode dari nama yang mirip. Satu kode supplier hanya boleh dipasangkan ke satu game lokal untuk setiap supplier.</p>
<?php if (!$canManage): ?><p class="muted">Peran akun ini hanya dapat melihat data pemetaan.</p><?php else: ?>
<form method="post" class="grid">
<input type="hidden" name="csrf" value="<?=mapping_escape($csrf)?>">
<label>Game PluQ-I<select name="game_id" required><option value="">Pilih game lokal</option><?php foreach($games as $game): ?><option value="<?= (int) $game['id'] ?>"><?=mapping_escape($game['name'])?> (<?=mapping_escape($game['slug'])?>)</option><?php endforeach; ?></select></label>
<label>Supplier<select name="provider_id" required><option value="">Pilih supplier</option><?php foreach($providers as $provider): ?><option value="<?= (int) $provider['id'] ?>"><?=mapping_escape($provider['name'])?> (<?=mapping_escape($provider['code'])?>)</option><?php endforeach; ?></select></label>
<label>Kode game supplier<input name="provider_game_code" maxlength="190" required placeholder="Contoh: kode persis dari API"></label>
<label>Nama game supplier (opsional)<input name="provider_game_name" maxlength="190" placeholder="Nama dari respons API"></label>
<div><button type="submit" name="save_mapping" value="1">Simpan pemetaan</button></div>
</form>
<?php endif; ?>
</section>
<section class="panel"><h2>Pemetaan tersimpan</h2><div class="table-wrap"><table><thead><tr><th>Game PluQ-I</th><th>Supplier</th><th>Kode supplier</th><th>Nama supplier</th><th>Status</th><th>Diubah</th><th>Aksi</th></tr></thead><tbody>
<?php if (!$mappings): ?><tr><td colspan="7" class="muted">Belum ada pemetaan. Tambahkan game lokal terlebih dahulu, lalu buat pemetaan berdasarkan respons API supplier.</td></tr><?php endif; ?>
<?php foreach($mappings as $mapping): ?><tr><td><?=mapping_escape($mapping['game_name'])?><div class="muted"><?=mapping_escape($mapping['slug'])?></div></td><td><?=mapping_escape($mapping['provider_name'])?> (<?=mapping_escape($mapping['provider_code'])?>)</td><td><?=mapping_escape($mapping['provider_game_code'])?></td><td><?=mapping_escape($mapping['provider_game_name'] ?: '-')?></td><td><span class="status"><?=mapping_escape($mapping['status'])?></span></td><td><?=mapping_escape($mapping['updated_at'])?></td><td><?php if ($canManage && $mapping['status'] === 'ACTIVE'): ?><form class="inline" method="post" onsubmit="return confirm('Nonaktifkan pemetaan ini? Produk tidak akan dipetakan pada sinkronisasi berikutnya.');"><input type="hidden" name="csrf" value="<?=mapping_escape($csrf)?>"><input type="hidden" name="mapping_id" value="<?=(int)$mapping['id']?>"><button class="danger" type="submit" name="deactivate_mapping" value="1">Nonaktifkan</button></form><?php else: ?>-<?php endif; ?></td></tr><?php endforeach; ?>
</tbody></table></div></section>
</main></body></html>
