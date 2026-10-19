<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once PLUQI_ROOT . '/config/database.php';
require_once PLUQI_ROOT . '/app/services/admin_auth.php';
require_once PLUQI_ROOT . '/app/services/api_secret_crypto.php';

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Cache-Control: no-store');
header('Content-Type: text/html; charset=utf-8');
admin_session_start();
$pdo = database();
$user = admin_current_user($pdo);
if (!$user) { header('Location: /admin/'); exit; }
if (!admin_can_manage_settings($user)) { http_response_code(403); exit('Akses ditolak. Peran admin tidak diizinkan mengelola kredensial API.'); }

$fields = [
 'Duitku' => [
  'DUITKU_MERCHANT_CODE' => 'Merchant Code',
  'DUITKU_API_KEY' => 'API Key',
  'DUITKU_PAYMENT_METHOD' => 'Kode metode pembayaran',
  'DUITKU_CALLBACK_URL' => 'URL callback HTTPS',
  'DUITKU_RETURN_URL' => 'URL kembali HTTPS',
 ],
 'Digiflazz' => [
  'DIGIFLAZZ_USERNAME' => 'Username',
  'DIGIFLAZZ_API_KEY' => 'API Key',
 ],
 'VIPayment' => [
  'VIPAYMENT_API_ID' => 'API ID',
  'VIPAYMENT_API_KEY' => 'API Key',
 ],
];
$optionalFields = ['DIGIFLAZZ_API_URL' => 'URL API Digiflazz (opsional)', 'VIPAYMENT_API_URL' => 'URL API VIPayment (opsional)'];
$message = '';
$error = '';
$csrf = admin_csrf_token();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
 if (!admin_csrf_valid($_POST['csrf'] ?? null)) {
  http_response_code(403); $error = 'Sesi formulir tidak valid. Muat ulang halaman.';
 } elseif (isset($_POST['save_api_settings'])) {
  try {
   if (!function_exists('sodium_crypto_secretbox')) throw new RuntimeException('Ekstensi PHP Sodium tidak tersedia. Kredensial tidak disimpan.');
   $key = (string) getenv('APP_KEY');
   if (strlen($key) < 32 || str_contains($key, 'replace-with')) throw new RuntimeException('Atur APP_KEY acak minimal 32 karakter di konfigurasi privat terlebih dahulu.');
   $saved = [];
   foreach (array_merge(...array_values($fields), $optionalFields) as $name => $label) {
    $value = trim((string) ($_POST[$name] ?? ''));
    if ($value === '') continue; // Empty means keep the current value.
    if (strlen($value) > 2000 || str_contains($value, "\0")) throw new RuntimeException('Nilai salah satu kolom tidak valid atau terlalu panjang.');
    if (str_ends_with($name, '_URL')) {
     $parts = parse_url($value);
     if (!is_array($parts) || strtolower((string)($parts['scheme'] ?? '')) !== 'https' || empty($parts['host'])) {
      throw new RuntimeException($label . ' harus berupa URL HTTPS yang valid.');
     }
    }
    $encrypted = pluqi_encrypt_api_secret($value);
    $stmt = $pdo->prepare("INSERT INTO app_settings (setting_key,setting_value,updated_by) VALUES (:setting_key,:setting_value,:admin) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),updated_by=VALUES(updated_by)");
    $stmt->execute(['setting_key' => 'api_secret_' . $name, 'setting_value' => $encrypted, 'admin' => $user['id']]);
    $saved[] = $name;
   }
   if (!$saved) throw new RuntimeException('Tidak ada nilai baru yang diisi. Kolom kosong tidak menghapus nilai lama.');
   $pdo->prepare("INSERT INTO audit_logs (actor_type,actor_id,action,entity_type,entity_id,details,ip_address) VALUES ('ADMIN',:id,'API_CREDENTIALS_UPDATED','APP_SETTING','provider_api',:details,:ip)")
    ->execute(['id' => $user['id'], 'details' => json_encode(['fields' => $saved], JSON_UNESCAPED_SLASHES), 'ip' => substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45)]);
   header('Location: /admin/api-settings.php?saved=1'); exit;
  } catch (Throwable $e) {
   $error = $e->getMessage();
  }
 } elseif (isset($_POST['check_duitku'])) {
  try {
   require_once PLUQI_ROOT . '/app/services/duitku.php';
   duitku_config(); $message = 'Konfigurasi Duitku lengkap dan valid secara lokal. Tidak ada permintaan jaringan atau transaksi yang dibuat.';
  } catch (Throwable $e) { $error = 'Validasi Duitku gagal: ' . $e->getMessage(); }
 } elseif (isset($_POST['check_digiflazz'])) {
  try {
   require_once PLUQI_ROOT . '/app/services/digiflazz.php';
   $result = digiflazz_balance();
   $message = 'Koneksi Digiflazz berhasil. Saldo yang dilaporkan API: Rp ' . number_format((float)$result['balance'], 0, ',', '.') . '. Tidak ada pesanan dibuat.';
  } catch (Throwable $e) { $error = 'Pemeriksaan Digiflazz gagal: ' . $e->getMessage(); }
 } elseif (isset($_POST['check_vipayment'])) {
  try {
   require_once PLUQI_ROOT . '/app/services/vipayment.php';
   $result = vipayment_profile();
   $message = 'Koneksi VIPayment berhasil. Saldo yang dilaporkan API: Rp ' . number_format((float)$result['balance'], 0, ',', '.') . '. Tidak ada pesanan dibuat.';
  } catch (Throwable $e) { $error = 'Pemeriksaan VIPayment gagal: ' . $e->getMessage(); }
 }
}
if (isset($_GET['saved']) && $_GET['saved'] === '1') $message = 'Pengaturan API terenkripsi berhasil disimpan.';

$statuses = [];
foreach (array_merge(...array_values($fields), $optionalFields) as $name => $label) {
 $stmt = $pdo->prepare('SELECT setting_value FROM app_settings WHERE setting_key=:key LIMIT 1');
 $stmt->execute(['key' => 'api_secret_' . $name]);
 $stored = $stmt->fetchColumn();
 $statuses[$name] = (is_string($stored) && $stored !== '') || trim((string)getenv($name)) !== '';
}
function api_settings_escape(mixed $value): string { return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
?><!doctype html>
<html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Pengaturan API — PluQ-I</title>
<style>
:root{color-scheme:dark;--bg:#0c0d10;--panel:#15171d;--line:#292d36;--text:#f3f4f6;--muted:#9ca3af;--accent:#a7f3d0}
*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--text);font:14px/1.5 system-ui,sans-serif}main{max-width:1000px;margin:auto;padding:24px}h1,h2{margin:0 0 12px}.muted{color:var(--muted)}.panel{background:var(--panel);border:1px solid var(--line);border-radius:12px;padding:18px;margin:16px 0}.bar{display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap}a{color:var(--accent)}.fields{display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:12px}label{display:grid;gap:6px;color:var(--muted)}input,button{width:100%;border:1px solid var(--line);background:#0d0f13;color:var(--text);padding:10px;border-radius:7px;font:inherit}button{cursor:pointer;background:#d1fae5;color:#111827;font-weight:700}.notice{padding:10px;border-radius:7px;margin:12px 0;background:#183329}.error{padding:10px;border-radius:7px;margin:12px 0;background:#3b1d22}.status{font-size:12px;padding:3px 7px;border:1px solid var(--line);border-radius:5px}.actions{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:10px;margin-top:14px}.note{font-size:13px}@media(max-width:600px){main{padding:12px}.panel{padding:12px}}
</style></head><body><main>
<header class="bar"><div><h1>Pengaturan API</h1><div class="muted">Kredensial gateway dan supplier — akun <?=api_settings_escape($user['username'])?></div></div><a href="/admin/">Kembali ke panel admin</a></header>
<?php if ($message !== ''): ?><div class="notice"><?=api_settings_escape($message)?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="error"><?=api_settings_escape($error)?></div><?php endif; ?>
<section class="panel"><h2>Status konfigurasi</h2><p class="muted">Status hanya menunjukkan apakah nilai tersedia, bukan apakah kredensial diterima oleh layanan.</p>
<?php foreach($fields as $provider => $providerFields): ?><p><strong><?=api_settings_escape($provider)?></strong><br><?php foreach($providerFields as $name=>$label): ?><span class="status"><?=api_settings_escape($label)?>: <?=$statuses[$name]?'Tersedia':'Belum diatur'?></span> <?php endforeach; ?></p><?php endforeach; ?>
</section>
<form method="post" autocomplete="off"><input type="hidden" name="csrf" value="<?=api_settings_escape($csrf)?>">
<?php foreach($fields as $provider=>$providerFields): ?><section class="panel"><h2><?=api_settings_escape($provider)?></h2><div class="fields">
<?php foreach($providerFields as $name=>$label): ?><label><?=api_settings_escape($label)?> <span class="muted">(<?=$statuses[$name]?'sudah ada — kosongkan untuk mempertahankan':'belum diatur'?>)</span><input type="password" name="<?=api_settings_escape($name)?>" autocomplete="new-password" maxlength="2000" placeholder="Isi nilai baru; tidak ditampilkan kembali"></label><?php endforeach; ?>
</div></section><?php endforeach; ?>
<section class="panel"><h2>URL API opsional</h2><div class="fields"><?php foreach($optionalFields as $name=>$label): ?><label><?=api_settings_escape($label)?> <span class="muted">(<?=$statuses[$name]?'sudah ada — kosongkan untuk mempertahankan':'default bawaan akan digunakan'?>)</span><input type="url" name="<?=api_settings_escape($name)?>" maxlength="2000" placeholder="https://..."></label><?php endforeach; ?></div></section>
<section class="panel"><p class="note">Nilai disimpan terenkripsi di database menggunakan APP_KEY. Jangan mengubah APP_KEY setelah menyimpan kredensial karena data tidak dapat dibuka kembali. Kolom kosong mempertahankan nilai lama. Mode Duitku tetap Sandbox; pembayaran, pemesanan supplier, dan backup Drive tidak diaktifkan oleh halaman ini.</p><button type="submit" name="save_api_settings" value="1">Simpan kredensial API</button></section>
</form>
<section class="panel"><h2>Pemeriksaan koneksi (manual)</h2><p class="muted">Duitku: validasi konfigurasi lokal saja. Tombol supplier mengirim permintaan baca-saldo ke API nyata; gunakan hanya setelah kredensial benar. Tidak ada tombol yang membuat pesanan top-up.</p>
<form method="post" class="actions"><input type="hidden" name="csrf" value="<?=api_settings_escape($csrf)?>"><button type="submit" name="check_duitku" value="1">Validasi konfigurasi Duitku</button><button type="submit" name="check_digiflazz" value="1">Periksa saldo Digiflazz</button><button type="submit" name="check_vipayment" value="1">Periksa saldo VIPayment</button></form>
</section>
</main></body></html>
