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
$error = '';
$user = admin_current_user($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!admin_csrf_valid($_POST['csrf'] ?? null)) {
        http_response_code(403);
        $error = 'Sesi formulir tidak valid. Muat ulang halaman dan coba lagi.';
    } elseif (isset($_POST['logout'])) {
        if ($user) {
            $pdo->prepare("INSERT INTO audit_logs (actor_type,actor_id,action,entity_type,entity_id,ip_address) VALUES ('ADMIN',:id,'ADMIN_LOGOUT','ADMIN_USER',:entity,:ip)")
                ->execute(['id' => $user['id'], 'entity' => (string) $user['id'], 'ip' => substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45)]);
        }
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', ['expires' => time() - 42000, 'path' => $params['path'], 'secure' => $params['secure'], 'httponly' => $params['httponly'], 'samesite' => $params['samesite'] ?? 'Strict']);
        }
        session_destroy();
        header('Location: /admin/');
        exit;
    } elseif ($user && isset($_POST['save_drive_folder']) && !admin_can_manage_settings($user)) {
        http_response_code(403);
        $error = 'Peran akun Anda tidak diizinkan mengubah pengaturan folder Google Drive.';
    } elseif ($user && isset($_POST['save_drive_folder'])) {
        $sharedUrl = trim((string) ($_POST['google_drive_folder_url'] ?? ''));
        $folderId = '';
        if ($sharedUrl === '') {
            $error = 'Masukkan URL folder Google Drive yang dibagikan.';
        } else {
            $parts = parse_url($sharedUrl);
            $host = strtolower((string) ($parts['host'] ?? ''));
            $path = (string) ($parts['path'] ?? '');
            $query = [];
            parse_str((string) ($parts['query'] ?? ''), $query);
            if (!in_array($host, ['drive.google.com', 'www.drive.google.com'], true) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https') {
                $error = 'Gunakan tautan HTTPS folder Google Drive, bukan tautan file atau situs lain.';
            } elseif (preg_match('~^/drive/folders/([A-Za-z0-9_-]{10,})/?$~', $path, $matches)) {
                $folderId = $matches[1];
            } elseif (preg_match('~^/folder/([A-Za-z0-9_-]{10,})/?$~', $path, $matches)) {
                $folderId = $matches[1];
            } else {
                $error = 'URL belum dikenali sebagai tautan folder. Salin tautan folder dari Google Drive, bukan tautan file.';
            }
            if ($error === '' && $folderId !== '') {
                $stmt = $pdo->prepare("INSERT INTO app_settings (setting_key,setting_value,updated_by) VALUES ('google_drive_folder_id',:value,:admin) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),updated_by=VALUES(updated_by)");
                $stmt->execute(['value' => $folderId, 'admin' => $user['id']]);
                $pdo->prepare("INSERT INTO audit_logs (actor_type,actor_id,action,entity_type,entity_id,details,ip_address) VALUES ('ADMIN',:id,'DRIVE_BACKUP_FOLDER_UPDATED','APP_SETTING','google_drive_folder_id',:details,:ip)")
                    ->execute(['id' => $user['id'], 'details' => json_encode(['folder_id_suffix' => substr($folderId, -6)], JSON_UNESCAPED_SLASHES), 'ip' => substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45)]);
                header('Location: /admin/?drive_folder_saved=1');
                exit;
            }
        }
    } elseif (!$user) {
        $username = trim((string) ($_POST['username'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        if ($username === '' || $password === '' || strlen($username) > 100 || strlen($password) > 4096) {
            $error = 'Username atau kata sandi tidak valid.';
        } else {
            $loginIp = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
            if (admin_login_is_limited($pdo, $loginIp, $username)) {
                usleep(300000);
                $error = 'Terlalu banyak percobaan login. Tunggu 15 menit sebelum mencoba lagi.';
            } else {
                $stmt = $pdo->prepare("SELECT id,username,password_hash,role FROM admin_users WHERE username=:username AND status='ACTIVE' LIMIT 1");
                $stmt->execute(['username' => $username]);
                $candidate = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$candidate || !password_verify($password, (string) $candidate['password_hash'])) {
                    admin_login_record_failure($pdo, $loginIp, $username);
                    usleep(300000);
                    $error = 'Login gagal. Periksa username dan kata sandi.';
                } else {
                    admin_login_clear_failures($pdo, $loginIp, $username);
                    session_regenerate_id(true);
                    $_SESSION['admin_user_id'] = (int) $candidate['id'];
                    $_SESSION['admin_csrf'] = bin2hex(random_bytes(32));
                    $pdo->prepare('UPDATE admin_users SET last_login_at=NOW() WHERE id=:id')->execute(['id' => $candidate['id']]);
                    $pdo->prepare("INSERT INTO audit_logs (actor_type,actor_id,action,entity_type,entity_id,ip_address) VALUES ('ADMIN',:id,'ADMIN_LOGIN','ADMIN_USER',:entity,:ip)")
                        ->execute(['id' => $candidate['id'], 'entity' => (string) $candidate['id'], 'ip' => substr($loginIp, 0, 45)]);
                    header('Location: /admin/');
                    exit;
                }
            }
        }
    }
}

$user = admin_current_user($pdo);
$csrf = admin_csrf_token();
$rows = [];
$settlements = [];
$driveFolderId = '';
$providers = [];
$summary = ['count' => 0, 'paid_total' => '0.00'];
$filters = [
    'provider' => trim((string) ($_GET['provider'] ?? '')),
    'gateway' => trim((string) ($_GET['gateway'] ?? '')),
    'date_field' => trim((string) ($_GET['date_field'] ?? 'created_at')),
    'from' => trim((string) ($_GET['from'] ?? '')),
    'to' => trim((string) ($_GET['to'] ?? '')),
    'payment_status' => trim((string) ($_GET['payment_status'] ?? '')),
    'order_status' => trim((string) ($_GET['order_status'] ?? '')),
    'refund_status' => trim((string) ($_GET['refund_status'] ?? '')),
];

if ($user) {
    if (isset($_GET['drive_folder_saved']) && $_GET['drive_folder_saved'] === '1') $error = 'URL folder Google Drive berhasil disimpan.';
    try {
        $folderStmt = $pdo->query("SELECT setting_value FROM app_settings WHERE setting_key='google_drive_folder_id' LIMIT 1");
        $driveFolderId = trim((string) ($folderStmt->fetchColumn() ?: ''));
    } catch (Throwable $e) {
        $error = 'Tabel pengaturan belum tersedia. Terapkan migration 007_admin_drive_folder_setting.sql.';
    }
    $providers = $pdo->query('SELECT code,name FROM providers ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);
    $allowedPayment = ['PENDING','PAID','FAILED','EXPIRED','REFUND_PENDING','REFUNDED'];
    $allowedOrder = ['PENDING','QUEUED','PROCESSING','RETRYING','SUCCESS','FAILED','REFUND_PENDING','REFUNDED','MANUAL_REVIEW'];
    $where = [];
    $params = [];
    $providerCodes = array_column($providers, 'code');
    if ($filters['provider'] !== '' && in_array($filters['provider'], $providerCodes, true)) {
        $where[] = 'pr.code = :provider'; $params['provider'] = $filters['provider'];
    } elseif ($filters['provider'] !== '') {
        $error = 'Filter API tidak dikenal.';
        $filters['provider'] = '';
    }
    if ($filters['gateway'] !== '' && in_array($filters['gateway'], ['DUITKU'], true)) {
        $where[] = 'pt.gateway = :gateway'; $params['gateway'] = $filters['gateway'];
    } elseif ($filters['gateway'] !== '') {
        $error = 'Filter payment gateway tidak dikenal.';
        $filters['gateway'] = '';
    }
    $dateColumns = ['created_at' => 'o.created_at', 'paid_at' => 'o.paid_at', 'fulfilled_at' => 'o.fulfilled_at', 'supplier_created_at' => 'st.created_at'];
    if (!isset($dateColumns[$filters['date_field']])) { $filters['date_field'] = 'created_at'; $error = 'Dasar waktu tidak valid.'; }
    $dateColumn = $dateColumns[$filters['date_field']];
    foreach (['from' => '>=', 'to' => '<='] as $key => $operator) {
        if ($filters[$key] === '') continue;
        $dt = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $filters[$key], new DateTimeZone($config['timezone']));
        $errors = DateTimeImmutable::getLastErrors();
        if (!$dt || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) || $dt->format('Y-m-d\TH:i') !== $filters[$key]) {
            $error = 'Format tanggal/waktu tidak valid.';
            $filters[$key] = '';
            continue;
        }
        $where[] = $dateColumn . ' ' . $operator . ' :' . $key;
        $params[$key] = $dt->format('Y-m-d H:i:00');
    }
    if ($filters['from'] !== '' && $filters['to'] !== '' && $filters['from'] > $filters['to']) {
        $error = 'Waktu awal tidak boleh melewati waktu akhir.';
        $where = array_values(array_filter($where, static fn($clause) => !str_contains($clause, ':from') && !str_contains($clause, ':to')));
        unset($params['from'], $params['to']);
    }
    if (in_array($filters['payment_status'], $allowedPayment, true)) {
        $where[] = 'o.payment_status = :payment_status'; $params['payment_status'] = $filters['payment_status'];
    } elseif ($filters['payment_status'] !== '') { $error = 'Status pembayaran tidak valid.'; $filters['payment_status'] = ''; }
    if (in_array($filters['order_status'], $allowedOrder, true)) {
        $where[] = 'o.order_status = :order_status'; $params['order_status'] = $filters['order_status'];
    } elseif ($filters['order_status'] !== '') { $error = 'Status pesanan tidak valid.'; $filters['order_status'] = ''; }
    $allowedRefund = ['PENDING','PROCESSING','SUCCESS','FAILED'];
    if (in_array($filters['refund_status'], $allowedRefund, true)) {
        $where[] = "(SELECT rf.status FROM refunds rf WHERE rf.order_id=o.id ORDER BY rf.id DESC LIMIT 1) = :refund_status";
        $params['refund_status'] = $filters['refund_status'];
    } elseif ($filters['refund_status'] !== '') { $error = 'Status refund tidak valid.'; $filters['refund_status'] = ''; }

    $sql = "SELECT o.public_id,o.created_at,o.paid_at,o.fulfilled_at,o.payment_status,o.order_status,
              g.name AS game_name,p.name AS product_name,pr.code AS provider_code,
              o.base_price,o.selling_price,o.admin_fee,o.last_error,pt.gateway,pt.amount AS payment_amount,
              pt.status AS gateway_status,pt.merchant_order_id,pt.gateway_reference,
              pt.gateway_payment_url,pt.gateway_expiry_at,
              st.reference_id AS supplier_reference,st.provider_reference,st.status AS supplier_status,
              (SELECT r.status FROM refunds r WHERE r.order_id=o.id ORDER BY r.id DESC LIMIT 1) AS refund_status
            FROM orders o JOIN games g ON g.id=o.game_id JOIN products p ON p.id=o.product_id
            JOIN providers pr ON pr.id=o.provider_id
            LEFT JOIN payment_transactions pt ON pt.order_id=o.id
            LEFT JOIN supplier_transactions st ON st.order_id=o.id" .
            ($where ? ' WHERE ' . implode(' AND ', $where) : '') .
            ' ORDER BY o.created_at DESC,o.id DESC LIMIT 200';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $summary['count'] = count($rows);
    $paidTotal = 0.0;
    foreach ($rows as $row) if ($row['payment_status'] === 'PAID') $paidTotal += (float) ($row['payment_amount'] ?? ((float) $row['selling_price'] + (float) $row['admin_fee']));
    $summary['paid_total'] = number_format($paidTotal, 2, '.', '');

    try {
        $settlements = $pdo->query("SELECT period_start,period_end,status,file_name,drive_file_id,row_count,total_amount,uploaded_at,last_error FROM monthly_settlement_exports ORDER BY period_start DESC LIMIT 12")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $settlements = [];
        $error = 'Tabel settlement belum tersedia. Terapkan migration 006_monthly_settlements.sql.';
    }
}

function admin_money(mixed $amount): string {
    return 'Rp ' . number_format((float) $amount, 0, ',', '.');
}
?>
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Admin PluQ-I</title>
<style>
:root{color-scheme:dark;--bg:#0c0d10;--panel:#15171d;--line:#292d36;--text:#f3f4f6;--muted:#9ca3af;--accent:#a7f3d0}
*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--text);font:14px/1.5 system-ui,sans-serif}main{max-width:1500px;margin:auto;padding:24px}h1,h2{margin:0 0 14px}h1{font-size:24px}h2{font-size:18px}.muted{color:var(--muted)}.panel{background:var(--panel);border:1px solid var(--line);border-radius:12px;padding:18px;margin:16px 0}.bar{display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap}.filters{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px}label{display:grid;gap:6px;color:var(--muted)}input,select,button{width:100%;border:1px solid var(--line);background:#0d0f13;color:var(--text);padding:10px;border-radius:7px;font:inherit}button{cursor:pointer;background:#d1fae5;color:#111827;font-weight:700}.actions{display:flex;gap:8px;align-items:end}.actions a{color:var(--accent)}.table-wrap{overflow:auto}table{width:100%;border-collapse:collapse;white-space:nowrap}th,td{text-align:left;border-bottom:1px solid var(--line);padding:10px}th{color:var(--muted);font-weight:600}.status{font-size:12px;border:1px solid var(--line);border-radius:5px;padding:3px 6px}.error{padding:10px;background:#3b1d22;border-radius:7px;margin:12px 0}.login{max-width:420px;margin:10vh auto}.stat{font-size:24px;font-weight:700}.form-grid{display:grid;gap:12px}.small{font-size:12px;max-width:240px;overflow-wrap:anywhere}@media(max-width:600px){main{padding:12px}.panel{padding:12px}}
</style>
</head><body><main>
<?php if (!$user): ?>
<section class="panel login"><h1>Admin PluQ-I</h1><p class="muted">Masuk untuk memeriksa transaksi dan settlement.</p>
<?php if ($error !== ''): ?><div class="error"><?=admin_escape($error)?></div><?php endif; ?>
<form method="post" class="form-grid" autocomplete="on">
<input type="hidden" name="csrf" value="<?=admin_escape($csrf)?>">
<label>Username<input name="username" maxlength="100" autocomplete="username" required></label>
<label>Kata sandi<input type="password" name="password" autocomplete="current-password" required></label>
<button type="submit">Masuk</button>
</form></section>
<?php else: ?>
<header class="bar"><div><h1>Panel Admin PluQ-I</h1><div class="muted">Masuk sebagai <?=admin_escape($user['username'])?> · <?=admin_escape($user['role'])?></div></div>
<nav><a href="/admin/game-mappings.php" style="color:#a7f3d0;margin-right:14px">Pemetaan game supplier</a><?php if (admin_can_manage_settings($user)): ?><a href="/admin/reconcile.php" style="color:#a7f3d0">Rekonsiliasi Duitku</a><?php endif; ?></nav>
<form method="post"><input type="hidden" name="csrf" value="<?=admin_escape($csrf)?>"><button name="logout" value="1" type="submit">Keluar</button></form></header>
<?php if ($error !== ''): ?><div class="error"><?=admin_escape($error)?></div><?php endif; ?>
<section class="panel"><div class="bar"><h2>Filter transaksi</h2><span class="muted">Maksimal 200 baris per pencarian</span></div>
<form method="get" class="filters">
<label>API/provider<select name="provider"><option value="">Semua API provider</option><?php foreach($providers as $provider): ?><option value="<?=admin_escape($provider['code'])?>" <?=$filters['provider']===$provider['code']?'selected':''?>><?=admin_escape($provider['name'])?> (<?=admin_escape($provider['code'])?>)</option><?php endforeach; ?></select></label>
<label>Payment gateway<select name="gateway"><option value="">Semua gateway</option><option value="DUITKU" <?=$filters['gateway']==='DUITKU'?'selected':''?>>Duitku</option></select></label>
<label>Dasar tanggal/waktu<select name="date_field"><option value="created_at" <?=$filters['date_field']==='created_at'?'selected':''?>>Pesanan dibuat</option><option value="paid_at" <?=$filters['date_field']==='paid_at'?'selected':''?>>Pembayaran diterima</option><option value="fulfilled_at" <?=$filters['date_field']==='fulfilled_at'?'selected':''?>>Top-up selesai</option><option value="supplier_created_at" <?=$filters['date_field']==='supplier_created_at'?'selected':''?>>Transaksi API supplier dibuat</option></select></label>
<label>Dari tanggal & waktu<input type="datetime-local" name="from" value="<?=admin_escape($filters['from'])?>"></label>
<label>Sampai tanggal & waktu<input type="datetime-local" name="to" value="<?=admin_escape($filters['to'])?>"></label>
<label>Status pembayaran<select name="payment_status"><option value="">Semua status pembayaran</option><?php foreach(['PENDING','PAID','FAILED','EXPIRED','REFUND_PENDING','REFUNDED'] as $s): ?><option <?=$filters['payment_status']===$s?'selected':''?>><?=$s?></option><?php endforeach; ?></select></label>
<label>Status pesanan<select name="order_status"><option value="">Semua status pesanan</option><?php foreach(['PENDING','QUEUED','PROCESSING','RETRYING','SUCCESS','FAILED','REFUND_PENDING','REFUNDED','MANUAL_REVIEW'] as $s): ?><option <?=$filters['order_status']===$s?'selected':''?>><?=$s?></option><?php endforeach; ?></select></label>
<label>Status refund<select name="refund_status"><option value="">Semua status refund</option><?php foreach(['PENDING','PROCESSING','SUCCESS','FAILED'] as $s): ?><option <?=$filters['refund_status']===$s?'selected':''?>><?=$s?></option><?php endforeach; ?></select></label>
<div class="actions"><button type="submit">Terapkan filter</button><a href="/admin/">Reset</a></div>
</form></section>
<section class="panel"><div class="bar"><div><h2>Hasil transaksi</h2><div class="muted">Total hasil yang ditampilkan, bukan seluruh hasil jika batas 200 tercapai.</div></div><div><div class="stat"><?=number_format($summary['count'])?></div><div class="muted">Baris</div></div><div><div class="stat"><?=admin_money($summary['paid_total'])?></div><div class="muted">Jumlah payment PAID pada hasil</div></div></div>
<div class="table-wrap"><table><thead><tr><th>Waktu</th><th>No. pesanan</th><th>Game / produk</th><th>API provider</th><th>Gateway</th><th>Total</th><th>Status bayar</th><th>Status pesanan</th><th>Status supplier</th><th>Referensi</th><th>Catatan checkout</th></tr></thead><tbody>
<?php if (!$rows): ?><tr><td colspan="11" class="muted">Tidak ada transaksi untuk filter ini.</td></tr><?php endif; ?>
<?php foreach($rows as $r): ?><tr><td><?=admin_escape($r['created_at'])?></td><td><?=admin_escape($r['public_id'])?><div class="muted small"><?=admin_escape($r['merchant_order_id'])?></div></td><td><?=admin_escape($r['game_name'])?><div class="muted"><?=admin_escape($r['product_name'])?></div></td><td><?=admin_escape($r['provider_code'])?></td><td><?=admin_escape($r['gateway'] ?? '-')?></td><td><?=admin_money($r['payment_amount'] ?? ((float)$r['selling_price']+(float)$r['admin_fee']))?><div class="muted small">Modal <?=admin_money($r['base_price'])?> · Admin <?=admin_money($r['admin_fee'])?></div></td><td><span class="status"><?=admin_escape($r['payment_status'])?></span><div class="muted small">Gateway: <?=admin_escape($r['gateway_status'] ?? '-')?></div></td><td><span class="status"><?=admin_escape($r['order_status'])?></span></td><td><?=admin_escape($r['supplier_status'] ?? '-')?></td><td class="small"><?=admin_escape($r['supplier_reference'] ?? '-')?><br><?=admin_escape($r['provider_reference'] ?? '')?><br>Refund: <?=admin_escape($r['refund_status'] ?? '-')?></td><td class="small"><?php if (!empty($r['gateway_payment_url'])): ?>URL tersimpan<?php else: ?>URL belum tersimpan<?php endif; ?><br>Kedaluwarsa: <?=admin_escape($r['gateway_expiry_at'] ?? '-')?><br><?php if (!empty($r['last_error'])): ?><strong>Perlu pemeriksaan:</strong><br><?=admin_escape($r['last_error'])?><?php endif; ?></td></tr><?php endforeach; ?>
</tbody></table></div></section>
<section class="panel"><h2>Settlement bulanan & backup Google Drive</h2><p class="muted">Backup membuat Google Sheets dengan empat tab: Ringkasan Bulanan, Rincian Transaksi, Rekonsiliasi, dan Refund. Ringkasan memisahkan refund sukses yang diproses pada bulan laporan dan penerimaan bersih indikatif. Laporan membantu pembukuan dan pemeriksaan transaksi; bukan perhitungan pajak final. Data ID/zone akun game tidak disertakan, dan data transaksi di database tidak dihapus.</p>
<form method="post" class="form-grid" style="max-width:760px;margin:16px 0">
<input type="hidden" name="csrf" value="<?=admin_escape($csrf)?>">
<label>URL folder Google Drive tujuan backup
<input type="url" name="google_drive_folder_url" placeholder="https://drive.google.com/drive/folders/..." maxlength="2000" required>
</label>
<div class="muted">Tempel tautan folder yang dibagikan dari Google Drive. Akun Google yang dipakai oleh OAuth di konfigurasi server harus memiliki izin untuk membuat file di folder tersebut.</div>
<label>ID folder tersimpan
<input value="<?=admin_escape($driveFolderId !== '' ? '••••••' . substr($driveFolderId, -6) : 'Belum diatur')?>" readonly>
</label>
<button type="submit" name="save_drive_folder" value="1">Simpan folder tujuan</button>
</form>
<p class="muted">Menyimpan URL hanya mengatur tujuan. Kredensial Google OAuth tetap harus diatur di konfigurasi server dan backup tetap nonaktif sampai diuji.</p>
<div class="table-wrap"><table><thead><tr><th>Periode</th><th>Status</th><th>Baris</th><th>Total pembayaran PAID</th><th>Google Sheets</th><th>Unggah</th><th>Catatan</th></tr></thead><tbody>
<?php if (!$settlements): ?><tr><td colspan="7" class="muted">Belum ada catatan settlement atau migration 006 belum diterapkan.</td></tr><?php endif; ?>
<?php foreach($settlements as $s): ?><tr><td><?=admin_escape($s['period_start'])?> – <?=admin_escape($s['period_end'])?></td><td><span class="status"><?=admin_escape($s['status'])?></span></td><td><?=number_format((int)$s['row_count'])?></td><td><?=admin_money($s['total_amount'])?></td><td><?php if (!empty($s['drive_file_id']) && $s['status'] === 'UPLOADED'): ?><a href="https://docs.google.com/spreadsheets/d/<?=admin_escape(rawurlencode((string)$s['drive_file_id']))?>/edit" target="_blank" rel="noopener noreferrer"><?=admin_escape($s['file_name'])?></a><?php else: ?><?=admin_escape($s['file_name'])?><?php endif; ?><div class="muted small"><?=admin_escape($s['drive_file_id'] ?? '')?></div></td><td><?=admin_escape($s['uploaded_at'] ?? '-')?></td><td class="small"><?=admin_escape($s['last_error'] ?? '')?></td></tr><?php endforeach; ?>
</tbody></table></div></section>
<?php endif; ?>
</main></body></html>
