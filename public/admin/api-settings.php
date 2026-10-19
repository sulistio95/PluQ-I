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
  'DUITKU_EXPIRY_MINUTES' => 'Masa berlaku invoice (menit, 5–1440)',
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
$allFields = [];
foreach ($fields as $groupFields) { foreach ($groupFields as $fieldName => $fieldLabel) { $allFields[$fieldName] = $fieldLabel; } }
foreach ($optionalFields as $fieldName => $fieldLabel) { $allFields[$fieldName] = $fieldLabel; }
$message = '';
$error = '';
$catalogPreview = null;
$catalogSyncResult = null;
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
   foreach ($allFields as $name => $label) {
    $value = trim((string) ($_POST[$name] ?? ''));
    if ($value === '') continue; // Empty means keep the current value.
    if (strlen($value) > 2000 || str_contains($value, "\0")) throw new RuntimeException('Nilai salah satu kolom tidak valid atau terlalu panjang.');
    if ($name === 'DUITKU_EXPIRY_MINUTES' && (!ctype_digit($value) || (int)$value < 5 || (int)$value > 1440)) {
     throw new RuntimeException('Masa berlaku invoice harus berupa bilangan bulat antara 5 dan 1440 menit.');
    }
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
 } elseif (isset($_POST['delete_api_provider'])) {
  try {
   $selection = trim((string) ($_POST['delete_api_provider'] ?? ''));
   $deleteKeys = [];
   $label = '';
   if (in_array($selection, ['DUITKU', 'DIGIFLAZZ', 'VIPAYMENT'], true)) {
    $providerFields = match ($selection) {
     'DUITKU' => array_keys($fields['Duitku']),
     'DIGIFLAZZ' => array_merge(array_keys($fields['Digiflazz']), ['DIGIFLAZZ_API_URL']),
     'VIPAYMENT' => array_merge(array_keys($fields['VIPayment']), ['VIPAYMENT_API_URL']),
    };
    foreach ($providerFields as $fieldName) $deleteKeys[] = 'api_secret_' . $fieldName;
    $label = match ($selection) { 'DUITKU' => 'Duitku', 'DIGIFLAZZ' => 'Digiflazz', 'VIPAYMENT' => 'VIPayment' };
   } elseif (preg_match('/^CUSTOM_[a-f0-9]{32}$/D', $selection)) {
    $profileId = substr($selection, 7);
    $deleteKeys[] = 'api_custom_profile_' . $profileId;
    $label = 'API custom ' . $profileId;
   } else {
    throw new RuntimeException('Pilih satu gateway/supplier yang valid untuk dihapus.');
   }
   $pdo->beginTransaction();
   $delete = $pdo->prepare('DELETE FROM app_settings WHERE setting_key = :key');
   $deletedRows = 0;
   foreach (array_unique($deleteKeys) as $key) {
    $delete->execute(['key' => $key]);
    $deletedRows += $delete->rowCount();
   }
   $pdo->prepare("INSERT INTO audit_logs (actor_type,actor_id,action,entity_type,entity_id,details,ip_address) VALUES ('ADMIN',:id,'API_PROVIDER_DELETED','APP_SETTING','provider_api',:details,:ip)")
    ->execute(['id' => $user['id'], 'details' => json_encode(['selection' => $selection, 'deleted_setting_rows' => $deletedRows], JSON_UNESCAPED_SLASHES), 'ip' => substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45)]);
   $pdo->commit();
   $message = $label . ': ' . $deletedRows . ' pengaturan terkait dihapus dari database. Riwayat transaksi/pesanan tidak dihapus. Jika kredensial juga ada di .env/lingkungan hosting, hapus dari sana secara terpisah.';
  } catch (Throwable $e) {
   if ($pdo->inTransaction()) $pdo->rollBack();
   $error = 'Penghapusan API gagal: ' . $e->getMessage();
  }
 } elseif (isset($_POST['add_custom_api'])) {
  try {
   $name = trim((string) ($_POST['custom_api_name'] ?? ''));
   $type = trim((string) ($_POST['custom_api_type'] ?? ''));
   $baseUrl = trim((string) ($_POST['custom_api_base_url'] ?? ''));
   $authType = trim((string) ($_POST['custom_api_auth_type'] ?? ''));
   $apiKey = trim((string) ($_POST['custom_api_key'] ?? ''));
   $apiSecret = trim((string) ($_POST['custom_api_secret'] ?? ''));
   $apiToken = trim((string) ($_POST['custom_api_token'] ?? ''));
   if ($name === '' || strlen($name) > 100 || preg_match('/[<>\x00-\x1F]/', $name)) throw new RuntimeException('Nama API wajib diisi (maksimal 100 karakter).');
   if (!in_array($type, ['PAYMENT_GATEWAY', 'SUPPLIER'], true)) throw new RuntimeException('Jenis API harus Payment Gateway atau Supplier.');
   $urlParts = parse_url($baseUrl);
   if (!is_array($urlParts) || strtolower((string)($urlParts['scheme'] ?? '')) !== 'https' || empty($urlParts['host']) || strlen($baseUrl) > 2000) {
    throw new RuntimeException('Base URL API wajib berupa URL HTTPS yang valid.');
   }
   if (!in_array($authType, ['API_KEY', 'BEARER_TOKEN', 'KEY_SECRET', 'NONE'], true)) throw new RuntimeException('Metode autentikasi tidak valid.');
   foreach ([$apiKey, $apiSecret, $apiToken] as $secretValue) {
    if (strlen($secretValue) > 2000 || str_contains($secretValue, "\0")) throw new RuntimeException('Kredensial terlalu panjang atau tidak valid.');
   }
   if (!function_exists('sodium_crypto_secretbox')) throw new RuntimeException('Ekstensi PHP Sodium tidak tersedia.');
   $profileId = bin2hex(random_bytes(16));
   $profile = [
    'id' => $profileId, 'name' => $name, 'type' => $type, 'base_url' => $baseUrl,
    'auth_type' => $authType, 'api_key' => $apiKey, 'api_secret' => $apiSecret,
    'api_token' => $apiToken, 'created_at' => gmdate('c'),
   ];
   $encrypted = pluqi_encrypt_api_secret(json_encode($profile, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
   $pdo->beginTransaction();
   $pdo->prepare("INSERT INTO app_settings (setting_key,setting_value,updated_by) VALUES (:setting_key,:setting_value,:admin)")
    ->execute(['setting_key' => 'api_custom_profile_' . $profileId, 'setting_value' => $encrypted, 'admin' => $user['id']]);
   $pdo->prepare("INSERT INTO audit_logs (actor_type,actor_id,action,entity_type,entity_id,details,ip_address) VALUES ('ADMIN',:id,'CUSTOM_API_PROFILE_CREATED','APP_SETTING',:entity_id,:details,:ip)")
    ->execute(['id' => $user['id'], 'entity_id' => $profileId, 'details' => json_encode(['name' => $name, 'type' => $type, 'auth_type' => $authType, 'base_url_host' => (string)($urlParts['host'] ?? '')], JSON_UNESCAPED_SLASHES), 'ip' => substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45)]);
   $pdo->commit();
   header('Location: /admin/api-settings.php?custom_added=1'); exit;
  } catch (Throwable $e) {
   if ($pdo->inTransaction()) $pdo->rollBack();
   $error = 'Pendaftaran API custom gagal: ' . $e->getMessage();
  }
 } elseif (isset($_POST['sync_provider_catalog'])) {
  try {
   $providerCode = strtoupper(trim((string)($_POST['sync_provider_catalog'] ?? '')));
   if (!in_array($providerCode, ['DIGI', 'VIPAY'], true)) throw new RuntimeException('Pilihan supplier tidak valid.');
   require_once PLUQI_ROOT . '/app/services/catalog_sync.php';
   $catalogSyncResult = $providerCode === 'DIGI' ? sync_digiflazz_catalog($pdo) : sync_vipayment_catalog($pdo);
   if (!empty($catalogSyncResult['skipped'])) {
    $message = $providerCode . ' dilewati karena supplier dinonaktifkan.';
   } elseif ((int)($catalogSyncResult['mapped'] ?? 0) === 0) {
    $error = 'Sinkronisasi dibatalkan karena tidak ada layanan yang cocok dengan pemetaan game aktif. Periksa Game List dan simpan pemetaan sebelum mencoba lagi.';
    $catalogSyncResult = null;
   } else {
    $message = sprintf('Sinkronisasi %s selesai: %d layanan dibaca, %d layanan dipetakan ke game PluQ-I. Tidak ada pesanan dibuat.', $providerCode, (int)$catalogSyncResult['seen'], (int)$catalogSyncResult['mapped']);
   }
  } catch (Throwable $e) {
   $error = 'Sinkronisasi katalog gagal: ' . $e->getMessage();
  }
 } elseif (isset($_POST['preview_vipayment_catalog'])) {
  try {
   require_once PLUQI_ROOT . '/app/services/vipayment.php';
   $catalogPreview = vipayment_game_services(null, null);
   if (!is_array($catalogPreview)) throw new RuntimeException('Format katalog VIPayment tidak valid.');
   $message = 'Daftar layanan VIPayment berhasil diambil untuk pratinjau baca-saja. Database katalog tidak diubah dan tidak ada pesanan dibuat.';
  } catch (Throwable $e) {
   $error = 'Pengambilan katalog VIPayment gagal: ' . $e->getMessage();
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
if (isset($_GET['custom_added']) && $_GET['custom_added'] === '1') $message = 'Profil API custom berhasil disimpan secara terenkripsi. Profil ini belum otomatis terhubung ke alur pembayaran atau pesanan supplier.';

$customApiProfiles = [];
$customStmt = $pdo->query("SELECT setting_key, setting_value FROM app_settings WHERE setting_key LIKE 'api_custom_profile_%' ORDER BY setting_key");
foreach ($customStmt->fetchAll(PDO::FETCH_ASSOC) as $customRow) {
 try {
  $decoded = json_decode(pluqi_decrypt_api_secret((string)$customRow['setting_value']), true, 32, JSON_THROW_ON_ERROR);
  if (is_array($decoded) && preg_match('/^[a-f0-9]{32}$/D', (string)($decoded['id'] ?? ''))) {
   $customApiProfiles[] = [
    'id' => (string)$decoded['id'], 'name' => (string)($decoded['name'] ?? 'API custom'),
    'type' => (string)($decoded['type'] ?? 'SUPPLIER'), 'base_url' => (string)($decoded['base_url'] ?? ''),
    'auth_type' => (string)($decoded['auth_type'] ?? 'NONE'),
   ];
  }
 } catch (Throwable $e) {
  // Never expose decrypted credentials or secret-related exception details in the page.
 }
}

$statuses = [];
$storedStatuses = [];
foreach ($allFields as $name => $label) {
 $stmt = $pdo->prepare('SELECT setting_value FROM app_settings WHERE setting_key=:key LIMIT 1');
 $stmt->execute(['key' => 'api_secret_' . $name]);
 $stored = $stmt->fetchColumn();
 $storedStatuses[$name] = is_string($stored) && $stored !== '';
 $statuses[$name] = $storedStatuses[$name] || trim((string)getenv($name)) !== '';
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
<?php foreach($providerFields as $name=>$label): ?><label><?=api_settings_escape($label)?> <span class="muted">(<?=$statuses[$name]?'sudah ada — kosongkan untuk mempertahankan':'belum diatur'?>)</span><input type="<?=$name === 'DUITKU_EXPIRY_MINUTES' ? 'number' : 'password'?>" name="<?=api_settings_escape($name)?>" <?=$name === 'DUITKU_EXPIRY_MINUTES' ? 'min="5" max="1440" step="1" inputmode="numeric"' : 'autocomplete="new-password" maxlength="2000"'?> placeholder="<?=$name === 'DUITKU_EXPIRY_MINUTES' ? 'Kosongkan untuk mempertahankan; default 30 menit' : 'Isi nilai baru; tidak ditampilkan kembali'?>"></label><?php endforeach; ?>
</div></section><?php endforeach; ?>
<section class="panel"><h2>URL API opsional</h2><div class="fields"><?php foreach($optionalFields as $name=>$label): ?><label><?=api_settings_escape($label)?> <span class="muted">(<?=$statuses[$name]?'sudah ada — kosongkan untuk mempertahankan':'default bawaan akan digunakan'?>)</span><input type="url" name="<?=api_settings_escape($name)?>" maxlength="2000" placeholder="https://..."></label><?php endforeach; ?></div></section>
<section class="panel"><p class="note">Nilai disimpan terenkripsi di database menggunakan APP_KEY. Jangan mengubah APP_KEY setelah menyimpan kredensial karena data tidak dapat dibuka kembali. Kolom kosong mempertahankan nilai lama. Mode Duitku tetap Sandbox; pembayaran, pemesanan supplier, dan backup Drive tidak diaktifkan oleh halaman ini.</p><button type="submit" name="save_api_settings" value="1">Simpan kredensial API</button></section>
</form>
<section class="panel"><h2>Hapus API tersimpan</h2>
<p class="muted">Pilih satu gateway/supplier atau profil custom yang ingin dihapus. Semua kolom kredensial dan URL terkait yang tersimpan di database akan dihapus bersama dalam satu transaksi. Riwayat transaksi, pesanan, mapping game, dan katalog tidak dihapus agar relasi database serta pembukuan tetap utuh. Nilai .env/hosting harus dihapus terpisah.</p>
<form method="post" onsubmit="return confirm('Hapus seluruh konfigurasi API yang dipilih dari database? Tindakan ini tidak dapat dibatalkan.');">
<input type="hidden" name="csrf" value="<?=api_settings_escape($csrf)?>">
<label>API yang akan dihapus
<select name="delete_api_provider" required>
<option value="">— Pilih API —</option>
<?php foreach (['DUITKU'=>'Duitku (payment gateway)','DIGIFLAZZ'=>'Digiflazz (supplier)','VIPAYMENT'=>'VIPayment (supplier)'] as $providerCode=>$providerLabel):
 $providerNames = match ($providerCode) { 'DUITKU'=>array_keys($fields['Duitku']), 'DIGIFLAZZ'=>array_merge(array_keys($fields['Digiflazz']),['DIGIFLAZZ_API_URL']), 'VIPAYMENT'=>array_merge(array_keys($fields['VIPayment']),['VIPAYMENT_API_URL']) };
 $hasStored = false;
 foreach ($providerNames as $providerName) if (!empty($storedStatuses[$providerName]) || (is_string(getenv($providerName)) && trim((string)getenv($providerName)) !== '')) $hasStored = true;
 if ($hasStored): ?><option value="<?=api_settings_escape($providerCode)?>"><?=api_settings_escape($providerLabel)?></option><?php endif; endforeach; ?>
<?php foreach ($customApiProfiles as $profile): ?><option value="CUSTOM_<?=api_settings_escape($profile['id'])?>"><?=api_settings_escape(($profile['type']==='PAYMENT_GATEWAY'?'Payment Gateway':'Supplier').' — '.$profile['name'])?></option><?php endforeach; ?>
</select></label>
<div style="margin-top:12px"><button type="submit">Hapus API yang dipilih</button></div>
</form>
<?php if (!array_filter($storedStatuses) && !$customApiProfiles): ?><p class="muted">Belum ada konfigurasi API tersimpan di database.</p><?php endif; ?>
</section>
<section class="panel"><h2>Tambah API custom</h2>
<p class="muted">Daftarkan API gateway pembayaran atau supplier lain untuk memudahkan pergantian provider. Semua kredensial disimpan terenkripsi. Pendaftaran ini menyimpan profil konfigurasi saja; adapter khusus tetap harus dibuat dan diuji sebelum API dapat digunakan oleh checkout, pembayaran, katalog, atau pemesanan otomatis.</p>
<form method="post" autocomplete="off">
<input type="hidden" name="csrf" value="<?=api_settings_escape($csrf)?>">
<div class="fields">
<label>Nama API<input name="custom_api_name" maxlength="100" required placeholder="Contoh: Gateway Baru"></label>
<label>Jenis API<select name="custom_api_type" required><option value="PAYMENT_GATEWAY">Payment Gateway</option><option value="SUPPLIER">Supplier</option></select></label>
<label>Base URL HTTPS<input type="url" name="custom_api_base_url" maxlength="2000" required placeholder="https://api.example.com"></label>
<label>Metode autentikasi<select name="custom_api_auth_type" required><option value="API_KEY">API Key</option><option value="BEARER_TOKEN">Bearer Token</option><option value="KEY_SECRET">API Key + Secret</option><option value="NONE">Tanpa autentikasi</option></select></label>
<label>API Key (opsional)<input type="password" name="custom_api_key" maxlength="2000" autocomplete="new-password"></label>
<label>API Secret (opsional)<input type="password" name="custom_api_secret" maxlength="2000" autocomplete="new-password"></label>
<label>API Token (opsional)<input type="password" name="custom_api_token" maxlength="2000" autocomplete="new-password"></label>
</div>
<p class="note muted">Gunakan HTTPS. Jangan memasukkan kredensial ke catatan atau URL. Profil custom belum terintegrasi otomatis ke proses transaksi.</p>
<div style="margin-top:12px"><button type="submit" name="add_custom_api" value="1">Simpan API custom</button></div>
</form>
<?php if ($customApiProfiles): ?><h3>Profil API custom tersimpan</h3><div style="overflow:auto"><table style="width:100%;border-collapse:collapse"><thead><tr><th style="text-align:left;padding:8px">Nama</th><th style="text-align:left;padding:8px">Jenis</th><th style="text-align:left;padding:8px">Base URL</th><th style="text-align:left;padding:8px">Autentikasi</th></tr></thead><tbody>
<?php foreach ($customApiProfiles as $profile): ?><tr><td style="padding:8px;border-top:1px solid var(--line)"><?=api_settings_escape($profile['name'])?></td><td style="padding:8px;border-top:1px solid var(--line)"><?=api_settings_escape($profile['type']==='PAYMENT_GATEWAY'?'Payment Gateway':'Supplier')?></td><td style="padding:8px;border-top:1px solid var(--line)"><?=api_settings_escape($profile['base_url'])?></td><td style="padding:8px;border-top:1px solid var(--line)"><?=api_settings_escape($profile['auth_type'])?></td></tr><?php endforeach; ?>
</tbody></table></div><?php endif; ?>
</section>
<section class="panel"><h2>Pemeriksaan koneksi (manual)</h2><p class="muted">Duitku: validasi konfigurasi lokal saja. Tombol supplier mengirim permintaan baca-saldo ke API nyata; gunakan hanya setelah kredensial benar. Tidak ada tombol yang membuat pesanan top-up.</p>
<form method="post" class="actions"><input type="hidden" name="csrf" value="<?=api_settings_escape($csrf)?>"><button type="submit" name="check_duitku" value="1">Validasi konfigurasi Duitku</button><button type="submit" name="check_digiflazz" value="1">Periksa saldo Digiflazz</button><button type="submit" name="check_vipayment" value="1">Periksa saldo VIPayment</button></form>
</section>
<section class="panel"><h2>Sinkronisasi katalog ke database</h2>
<p class="muted">Langkah ini berbeda dari pratinjau: sistem mengambil daftar harga supplier dan menyimpan produk yang cocok dengan pemetaan aktif pada menu Game List. Simpan pemetaan game terlebih dahulu. Permintaan ini hanya membaca katalog supplier; tidak membuat pesanan atau pembayaran.</p>
<form method="post" class="actions"><input type="hidden" name="csrf" value="<?=api_settings_escape($csrf)?>"><button type="submit" name="sync_provider_catalog" value="VIPAY">Sinkronkan katalog VIPayment</button><button type="submit" name="sync_provider_catalog" value="DIGI">Sinkronkan katalog Digiflazz</button></form>
<?php if (is_array($catalogSyncResult) && empty($catalogSyncResult['skipped'])): ?>
<p><strong>Hasil:</strong> <?=api_settings_escape($catalogSyncResult['provider'] ?? '')?> — <?= (int)($catalogSyncResult['seen'] ?? 0) ?> layanan diproses, <?= (int)($catalogSyncResult['mapped'] ?? 0) ?> dipetakan ke game.</p>
<?php endif; ?>
</section>
<section class="panel"><h2>Pratinjau katalog VIPayment</h2>
<p class="muted">Mengambil daftar layanan API secara langsung. Fitur ini hanya menampilkan data; tidak menyimpan/mengubah katalog dan tidak mengirim pesanan.</p>
<form method="post" class="actions"><input type="hidden" name="csrf" value="<?=api_settings_escape($csrf)?>"><button type="submit" name="preview_vipayment_catalog" value="1">Ambil daftar game dan layanan</button></form>
<?php if (is_array($catalogPreview)): ?>
<?php
$catalogRows = array_values(array_filter($catalogPreview, 'is_array'));
$gameNames = [];
foreach ($catalogRows as $catalogRow) {
 $gameName = $catalogRow['game'] ?? $catalogRow['category'] ?? $catalogRow['game_name'] ?? null;
 if (is_scalar($gameName) && trim((string)$gameName) !== '') $gameNames[(string)$gameName] = true;
}
?>
<p><strong>Total layanan:</strong> <?=count($catalogRows)?> &nbsp; <strong>Game/kategori teridentifikasi:</strong> <?=count($gameNames)?></p>
<?php if (!$catalogRows): ?><p class="muted">API mengembalikan daftar kosong.</p><?php else: ?>
<div style="overflow:auto"><table style="width:100%;border-collapse:collapse"><thead><tr><th style="text-align:left;padding:8px">Game/kategori</th><th style="text-align:left;padding:8px">Layanan</th><th style="text-align:left;padding:8px">Kode</th><th style="text-align:left;padding:8px">Status</th><th style="text-align:left;padding:8px">Harga</th></tr></thead><tbody>
<?php foreach (array_slice($catalogRows, 0, 100) as $catalogRow):
 $gameName = $catalogRow['game'] ?? $catalogRow['category'] ?? $catalogRow['game_name'] ?? '';
 $serviceName = $catalogRow['name'] ?? $catalogRow['service'] ?? $catalogRow['layanan'] ?? $catalogRow['product_name'] ?? '';
 $serviceCode = $catalogRow['code'] ?? $catalogRow['service_code'] ?? $catalogRow['buyer_sku_code'] ?? $catalogRow['id'] ?? '';
 $serviceStatus = $catalogRow['status'] ?? $catalogRow['service_status'] ?? '';
 $servicePrice = $catalogRow['price'] ?? $catalogRow['harga'] ?? $catalogRow['cost'] ?? '';
?>
<tr><?php foreach ([$gameName,$serviceName,$serviceCode,$serviceStatus,$servicePrice] as $cell): ?><td style="padding:8px;border-top:1px solid var(--line);vertical-align:top"><?=api_settings_escape(is_scalar($cell) ? $cell : json_encode($cell))?></td><?php endforeach; ?></tr>
<?php endforeach; ?>
</tbody></table></div>
<?php if (count($catalogRows)>100): ?><p class="muted">Menampilkan 100 dari <?=count($catalogRows)?> layanan.</p><?php endif; ?>
<?php endif; ?>
<?php endif; ?>
</section>
</main></body></html>
