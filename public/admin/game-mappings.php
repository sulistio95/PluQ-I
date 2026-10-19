<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once PLUQI_ROOT . '/config/database.php';
require_once PLUQI_ROOT . '/app/services/admin_auth.php';
require_once PLUQI_ROOT . '/app/services/digiflazz.php';
require_once PLUQI_ROOT . '/app/services/vipayment.php';

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
$providers = $pdo->query("SELECT id,code,name FROM providers WHERE code IN ('DIGI','VIPAY') ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$providerById = [];
foreach ($providers as $provider) {
    $providerById[(int) $provider['id']] = $provider;
}
$selectedProviderId = filter_var($_POST['provider_id'] ?? $_GET['provider_id'] ?? null, FILTER_VALIDATE_INT);
$selectedProviderId = $selectedProviderId && isset($providerById[(int) $selectedProviderId]) ? (int) $selectedProviderId : 0;
$apiGames = [];
$checkedCodes = [];
$apiWasLoaded = false;

function mapping_escape(mixed $value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Build a unique, readable slug for a provider-discovered game. */
function mapping_game_slug(PDO $pdo, string $name): string
{
    $slug = strtolower(trim($name));
    if (function_exists('iconv')) {
        $converted = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $slug);
        if (is_string($converted) && $converted !== '') $slug = $converted;
    }
    $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', $slug), '-');
    if ($slug === '') $slug = 'game';
    $base = substr($slug, 0, 130);
    $slug = $base;
    $suffix = 2;
    $check = $pdo->prepare('SELECT id FROM games WHERE slug=:slug LIMIT 1');
    while (true) {
        $check->execute(['slug' => $slug]);
        if (!$check->fetchColumn()) return $slug;
        $slug = substr($base, 0, 140 - strlen((string) $suffix)) . '-' . $suffix;
        $suffix++;
    }
}

/** Fetch distinct game names from the selected supplier without exposing its codes in the UI. */
function mapping_fetch_provider_games(string $providerCode): array
{
    if ($providerCode === 'DIGI') {
        $items = digiflazz_price_list();
        $games = [];
        foreach ($items as $item) {
            if (!is_array($item)) continue;
            $code = trim((string) ($item['brand'] ?? ''));
            if ($code === '' || strlen($code) > 190) continue;
            $lower = strtolower($code);
            if (str_contains($lower, 'joki') || str_contains($lower, 'vilog')) continue;
            $games[$code] = ['code' => $code, 'name' => $code];
        }
    } elseif ($providerCode === 'VIPAY') {
        $items = vipayment_game_services(null, null);
        $games = [];
        foreach ($items as $item) {
            if (!is_array($item)) continue;
            $code = trim((string) ($item['game'] ?? ''));
            if ($code === '' || strlen($code) > 190) continue;
            $lower = strtolower($code);
            if (str_contains($lower, 'joki') || str_contains($lower, 'vilog')) continue;
            $games[$code] = ['code' => $code, 'name' => $code];
        }
    } else {
        throw new InvalidArgumentException('Supplier tidak dikenal.');
    }

    uasort($games, static fn(array $a, array $b): int => strcasecmp($a['name'], $b['name']));
    if ($games === []) {
        throw new RuntimeException('API supplier tidak mengembalikan daftar game yang valid. Pemetaan lama tidak diubah.');
    }
    return array_values($games);
}

/** Find or create one canonical web game so same-named supplier games share one storefront entry. */
function mapping_canonical_game_id(PDO $pdo, string $name): int
{
    $find = $pdo->prepare('SELECT id FROM games WHERE LOWER(name)=LOWER(:name) ORDER BY id LIMIT 1');
    $find->execute(['name' => $name]);
    $id = $find->fetchColumn();
    if ($id !== false) return (int) $id;

    $slug = mapping_game_slug($pdo, $name);
    $insert = $pdo->prepare("INSERT INTO games (slug,name,status,sort_order) VALUES (:slug,:name,'INACTIVE',0)");
    $insert->execute(['slug' => $slug, 'name' => $name]);
    return (int) $pdo->lastInsertId();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!admin_csrf_valid($_POST['csrf'] ?? null)) {
        http_response_code(403);
        $error = 'Sesi formulir tidak valid. Muat ulang halaman dan coba lagi.';
    } elseif (!$canManage) {
        http_response_code(403);
        $error = 'Peran akun Anda tidak diizinkan mengubah daftar game supplier.';
    } elseif (!$selectedProviderId) {
        $error = 'Pilih supplier terlebih dahulu.';
    } else {
        $provider = $providerById[$selectedProviderId];
        try {
            // Fetch fresh data on both load and save. A provider/API failure never deactivates stored mappings.
            $apiGames = mapping_fetch_provider_games((string) $provider['code']);
            $apiWasLoaded = true;
            $knownCodes = array_fill_keys(array_column($apiGames, 'code'), true);

            if (isset($_POST['load_games'])) {
                $stmt = $pdo->prepare("SELECT provider_game_code FROM game_provider_mappings WHERE provider_id=:provider_id AND status='ACTIVE'");
                $stmt->execute(['provider_id' => $selectedProviderId]);
                $checkedCodes = array_fill_keys(array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN)), true);
                $notice = 'Daftar game berhasil diambil dari ' . $provider['name'] . '. Centang game yang ingin ditampilkan, lalu simpan.';
            } elseif (isset($_POST['save_selection'])) {
                $submittedCodes = $_POST['game_codes'] ?? [];
                if (!is_array($submittedCodes)) $submittedCodes = [];
                $selectedCodes = [];
                foreach ($submittedCodes as $submittedCode) {
                    if (!is_string($submittedCode) || !isset($knownCodes[$submittedCode])) {
                        throw new RuntimeException('Daftar game berubah atau tidak valid. Tarik ulang daftar game lalu coba lagi.');
                    }
                    $selectedCodes[$submittedCode] = true;
                }

                $gameByCode = [];
                foreach ($apiGames as $game) $gameByCode[$game['code']] = $game;

                $pdo->beginTransaction();
                try {
                    // Replace only this supplier's selection. The other supplier's active mappings are untouched.
                    $deactivate = $pdo->prepare("UPDATE game_provider_mappings SET status='INACTIVE' WHERE provider_id=:provider_id AND status='ACTIVE'");
                    $deactivate->execute(['provider_id' => $selectedProviderId]);

                    $upsert = $pdo->prepare("INSERT INTO game_provider_mappings
                        (game_id,provider_id,provider_game_code,provider_game_name,status)
                        VALUES (:game_id,:provider_id,:code,:name,'ACTIVE')
                        ON DUPLICATE KEY UPDATE game_id=VALUES(game_id),provider_game_name=VALUES(provider_game_name),status='ACTIVE'");
                    foreach (array_keys($selectedCodes) as $code) {
                        $game = $gameByCode[$code];
                        $gameId = mapping_canonical_game_id($pdo, $game['name']);
                        $upsert->execute([
                            'game_id' => $gameId,
                            'provider_id' => $selectedProviderId,
                            'code' => $game['code'],
                            'name' => $game['name'],
                        ]);
                    }

                    // Storefront visibility is derived from whether any supplier has selected the canonical game.
                    $pdo->exec("UPDATE games g SET g.status=CASE WHEN EXISTS (
                        SELECT 1 FROM game_provider_mappings m WHERE m.game_id=g.id AND m.status='ACTIVE'
                    ) THEN 'ACTIVE' ELSE 'INACTIVE' END");

                    $audit = $pdo->prepare("INSERT INTO audit_logs
                        (actor_type,actor_id,action,entity_type,entity_id,details,ip_address)
                        VALUES ('ADMIN',:actor,'SUPPLIER_GAME_SELECTION_SAVED','PROVIDER',:entity,:details,:ip)");
                    $audit->execute([
                        'actor' => $user['id'],
                        'entity' => (string) $selectedProviderId,
                        'details' => json_encode([
                            'provider_code' => $provider['code'],
                            'selected_count' => count($selectedCodes),
                            'api_game_count' => count($apiGames),
                        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                        'ip' => substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45),
                    ]);
                    $pdo->commit();
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    throw $e;
                }
                header('Location: /admin/game-mappings.php?saved=1&provider_id=' . $selectedProviderId);
                exit;
            }
        } catch (Throwable $e) {
            $error = 'Tidak dapat mengambil atau menyimpan daftar game dari supplier. Periksa pengaturan API dan log server. Daftar tersimpan tidak diubah jika proses penarikan API gagal.';
            if ($apiWasLoaded && isset($_POST['load_games'])) {
                $apiGames = [];
                $apiWasLoaded = false;
            }
        }
    }
}

if (($_GET['saved'] ?? '') === '1') {
    $notice = 'Pilihan game berhasil disimpan. Untuk melihat kembali centang yang tersimpan, tarik ulang daftar dari supplier ini.';
}
if ($selectedProviderId > 0 && $apiWasLoaded) {
    $stmt = $pdo->prepare("SELECT provider_game_code FROM game_provider_mappings WHERE provider_id=:provider_id AND status='ACTIVE'");
    $stmt->execute(['provider_id' => $selectedProviderId]);
    $checkedCodes = array_fill_keys(array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN)), true);
}

$csrf = admin_csrf_token();
?>
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Daftar Game Supplier · PluQ-I</title>
<style>
:root{color-scheme:dark;--bg:#0c0d10;--panel:#15171d;--line:#292d36;--text:#f3f4f6;--muted:#9ca3af;--accent:#a7f3d0}
*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--text);font:14px/1.5 system-ui,sans-serif}main{max-width:1000px;margin:auto;padding:24px}h1,h2{margin:0 0 12px}.muted{color:var(--muted)}.panel{background:var(--panel);border:1px solid var(--line);border-radius:12px;padding:18px;margin:16px 0}.top{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap}label{display:block;color:var(--muted);margin-bottom:8px}select,button{width:100%;border:1px solid var(--line);background:#0d0f13;color:var(--text);padding:10px;border-radius:7px;font:inherit}button{cursor:pointer;background:#d1fae5;color:#111827;font-weight:700}.actions{display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:10px;margin-top:12px}.error,.notice{padding:10px;border-radius:7px;margin:12px 0}.error{background:#3b1d22}.notice{background:#15352b}.game-list{display:grid;grid-template-columns:repeat(auto-fit,minmax(230px,1fr));gap:8px}.game{display:flex;align-items:center;gap:10px;background:#0d0f13;border:1px solid var(--line);border-radius:8px;padding:10px;color:var(--text);margin:0}.game input{width:18px;height:18px;accent-color:#a7f3d0;flex-shrink:0}.counter{color:var(--muted);margin:8px 0 14px}a{color:var(--accent)}@media(max-width:600px){main{padding:12px}.panel{padding:12px}}
</style>
</head>
<body><main>
<header class="top"><div><h1>Daftar Game Supplier</h1><div class="muted">Admin: <?=mapping_escape($user['username'])?> · <?=mapping_escape($user['role'])?></div></div><a href="/admin/">Kembali ke panel transaksi</a></header>
<?php if ($error !== ''): ?><div class="error"><?=mapping_escape($error)?></div><?php endif; ?>
<?php if ($notice !== ''): ?><div class="notice"><?=mapping_escape($notice)?></div><?php endif; ?>
<section class="panel">
<h2>1. Pilih supplier dan tarik game</h2>
<p class="muted">Daftar ditarik langsung dari API supplier yang dipilih. Pilihan tersimpan untuk supplier lain tidak dihapus saat Anda berpindah supplier. Kegagalan API tidak akan mengubah pemetaan tersimpan.</p>
<?php if (!$canManage): ?><p class="muted">Peran akun Anda hanya dapat melihat panel transaksi. Minta ADMIN atau SUPER_ADMIN mengelola daftar game.</p><?php else: ?>
<form method="post">
<input type="hidden" name="csrf" value="<?=mapping_escape($csrf)?>">
<label for="provider_id">Supplier</label>
<select id="provider_id" name="provider_id" required>
<option value="">Pilih supplier</option>
<?php foreach ($providers as $provider): ?><option value="<?=(int)$provider['id']?>" <?=$selectedProviderId === (int)$provider['id'] ? 'selected' : ''?>><?=mapping_escape($provider['name'])?></option><?php endforeach; ?>
</select>
<div class="actions"><button type="submit" name="load_games" value="1">Tarik daftar game dari API</button></div>
</form>
<?php endif; ?>
</section>
<?php if ($apiWasLoaded): ?>
<section class="panel">
<h2>2. Pilih game yang ditampilkan di web</h2>
<p class="muted"><?=count($apiGames)?> nama game diterima dari API. Hanya nama game ditampilkan di sini; kode supplier digunakan secara internal untuk mencocokkan katalog dan pesanan.</p>
<?php if (!$canManage): ?><p class="muted">Akun ini tidak dapat menyimpan perubahan.</p><?php else: ?>
<form method="post">
<input type="hidden" name="csrf" value="<?=mapping_escape($csrf)?>">
<input type="hidden" name="provider_id" value="<?=$selectedProviderId?>">
<div class="game-list">
<?php foreach ($apiGames as $game): ?>
<label class="game"><input type="checkbox" name="game_codes[]" value="<?=mapping_escape($game['code'])?>" <?=isset($checkedCodes[$game['code']]) ? 'checked' : ''?>><span><?=mapping_escape($game['name'])?></span></label>
<?php endforeach; ?>
</div>
<div class="actions"><button type="submit" name="save_selection" value="1">Simpan pilihan game</button></div>
</form>
<?php endif; ?>
<?php endif; ?>
</section>
<section class="panel">
<h2>Aturan tampilan web</h2>
<ul>
<li>Game yang dicentang dari setidaknya satu supplier tampil satu kali di halaman game utama.</li>
<li>Jika kedua supplier menyediakan nama game yang sama, keduanya dipetakan ke satu game web. Produk dan pilihan server/region tetap berasal dari penawaran supplier yang cocok.</li>
<li>Game yang tidak dicentang dari semua supplier tidak ditampilkan.</li>
<li>Kode game supplier tetap disimpan di database untuk sinkronisasi katalog, tetapi tidak ditampilkan sebagai kolom atau input admin.</li>
</ul>
</section>
</main></body></html>
