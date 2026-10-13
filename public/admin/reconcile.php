<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once PLUQI_ROOT . '/config/database.php';
require_once PLUQI_ROOT . '/app/services/admin_auth.php';
require_once PLUQI_ROOT . '/app/services/duitku.php';
require_once PLUQI_ROOT . '/app/services/duitku_reconcile.php';

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
if (!admin_can_manage_settings($user)) {
    http_response_code(403);
    exit('Akun Anda tidak diizinkan menjalankan pemeriksaan status Duitku.');
}

$csrf = admin_csrf_token();
$error = '';
$result = null;
$orderId = trim((string)($_POST['merchant_order_id'] ?? ''));
$lookupPerformed = false;
$cooldownRejected = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!admin_csrf_valid($_POST['csrf'] ?? null)) {
        http_response_code(403);
        $error = 'Sesi formulir tidak valid. Muat ulang halaman dan coba lagi.';
    } elseif (!isset($_POST['check_status'])) {
        http_response_code(400);
        $error = 'Permintaan tidak dikenal.';
    } elseif ($orderId === '' || strlen($orderId) > 190 || preg_match('/[\x00-\x1F\x7F]/', $orderId)) {
        $error = 'Merchant order ID tidak valid.';
    } else {
        $payment = null;
        try {
            $stmt = $pdo->prepare("SELECT pt.id AS payment_id,pt.order_id,pt.merchant_order_id,pt.amount,pt.status AS payment_status,
                o.public_id,o.payment_status AS order_payment_status,o.order_status,o.last_error
                FROM payment_transactions pt JOIN orders o ON o.id=pt.order_id
                WHERE pt.merchant_order_id=:merchant_order_id AND pt.gateway='DUITKU' LIMIT 1");
            $stmt->execute(['merchant_order_id' => $orderId]);
            $payment = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
            if (!$payment) {
                throw new DomainException('Transaksi Duitku dengan merchant order ID tersebut tidak ditemukan di database lokal.');
            }

            $paymentId = (int)$payment['payment_id'];
            $amountText = (string)$payment['amount'];
            if (!preg_match('/^\d+\.00$/', $amountText) || (int)$amountText < 1) {
                throw new DomainException('Nominal lokal bukan bilangan rupiah bulat; pemeriksaan otomatis dibatalkan dan perlu pemeriksaan manual.');
            }

            // Atomically admit one lookup and commit its STARTED audit marker
            // before any gateway network I/O. Rejected clicks are not re-audited.
            try {
                duitku_reconcile_admit_lookup(
                    $pdo,
                    $paymentId,
                    isset($user['id']) ? (int)$user['id'] : null,
                    (string)$payment['merchant_order_id'],
                    (int)$amountText,
                    (string)$payment['payment_status'],
                    (string)$payment['order_payment_status'],
                    (string)$payment['order_status'],
                    (string)($_SERVER['REMOTE_ADDR'] ?? '')
                );
            } catch (DuitkuReconcileCooldownException $e) {
                $cooldownRejected = true;
                throw $e;
            }

            $config = duitku_status_config();
            $raw = duitku_query_transaction_status($config, (string)$payment['merchant_order_id']);
            $result = duitku_validate_transaction_status_response($raw, (string)$payment['merchant_order_id'], (int)$amountText);
            $lookupPerformed = true;

            $details = [
                'outcome' => 'VALIDATED',
                'status' => $result['state'],
                'amount' => $result['amount'],
                'reference' => $result['reference'],
                'status_message' => mb_substr($result['status_message'], 0, 160),
                'payment_status_before' => $payment['payment_status'],
                'order_payment_status_before' => $payment['order_payment_status'],
                'order_status_before' => $payment['order_status'],
            ];
            $audit = $pdo->prepare("INSERT INTO audit_logs
                (actor_type,actor_id,action,entity_type,entity_id,details,ip_address)
                VALUES ('ADMIN',:actor,'DUITKU_STATUS_LOOKUP','PAYMENT_TRANSACTION',:entity,:details,:ip)");
            $audit->execute([
                'actor' => $user['id'],
                'entity' => (string)$paymentId,
                'details' => json_encode($details, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'ip' => substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45),
            ]);
        } catch (Throwable $e) {
            $error = $e instanceof DomainException || $e instanceof InvalidArgumentException
                ? $e->getMessage()
                : 'Status Duitku tidak dapat diverifikasi. Jangan membuat pembayaran pengganti atau mengubah status; periksa log server dan dashboard Duitku.';
            if ($payment && !$cooldownRejected) {
                try {
                    $audit = $pdo->prepare("INSERT INTO audit_logs
                        (actor_type,actor_id,action,entity_type,entity_id,details,ip_address)
                        VALUES ('ADMIN',:actor,'DUITKU_STATUS_LOOKUP','PAYMENT_TRANSACTION',:entity,:details,:ip)");
                    $audit->execute([
                        'actor' => $user['id'],
                        'entity' => (string)$payment['payment_id'],
                        'details' => json_encode([
                            'outcome' => 'ERROR',
                            'error_class' => get_class($e),
                            'error' => mb_substr($error, 0, 240),
                            'payment_status_before' => $payment['payment_status'],
                            'order_payment_status_before' => $payment['order_payment_status'],
                            'order_status_before' => $payment['order_status'],
                        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                        'ip' => substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45),
                    ]);
                } catch (Throwable $ignored) {
                    error_log('PluQ-I could not persist Duitku status lookup audit event.');
                }
            }
        }
    }
}
function reconcile_escape(mixed $value): string {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
?>
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Rekonsiliasi Duitku · PluQ-I</title>
<style>
:root{color-scheme:dark;--bg:#0c0d10;--panel:#15171d;--line:#292d36;--text:#f3f4f6;--muted:#9ca3af;--accent:#a7f3d0}
*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--text);font:14px/1.5 system-ui,sans-serif}main{max-width:850px;margin:auto;padding:24px}h1,h2{margin:0 0 12px}.muted{color:var(--muted)}.panel{background:var(--panel);border:1px solid var(--line);border-radius:12px;padding:18px;margin:16px 0}label{display:grid;gap:6px;color:var(--muted)}input,button{width:100%;border:1px solid var(--line);background:#0d0f13;color:var(--text);padding:10px;border-radius:7px;font:inherit}button{cursor:pointer;background:#d1fae5;color:#111827;font-weight:700}.error{padding:10px;background:#3b1d22;border-radius:7px;margin:12px 0}.notice{padding:10px;background:#15352b;border-radius:7px;margin:12px 0}a{color:var(--accent)}.form{display:grid;gap:12px;max-width:600px}.row{display:grid;grid-template-columns:190px 1fr;gap:12px;padding:8px 0;border-bottom:1px solid var(--line)}code{overflow-wrap:anywhere}@media(max-width:600px){main{padding:12px}.panel{padding:12px}.row{grid-template-columns:1fr;gap:3px}}
</style>
</head>
<body><main>
<header><h1>Rekonsiliasi transaksi Duitku</h1><p class="muted">Admin: <?=reconcile_escape($user['username'])?> · <?=reconcile_escape($user['role'])?> · <a href="/admin/">Kembali ke panel</a></p></header>
<section class="panel">
<h2>Cek satu transaksi</h2>
<p class="muted">Pemeriksaan ini mengirim satu permintaan status ke endpoint sandbox Duitku, mencocokkan ID pesanan dan nominal, lalu mencatat hasilnya di audit log. Tidak ada status pembayaran atau pesanan yang diubah.</p>
<p><strong>Jangan klik berulang:</strong> permintaan untuk transaksi yang sama dibatasi minimal 60 detik. Status “masih diproses”, kesalahan jaringan, atau data yang tidak cocok tidak membenarkan pembuatan pembayaran pengganti.</p>
<?php if ($error !== ''): ?><div class="error"><?=reconcile_escape($error)?></div><?php endif; ?>
<form method="post" class="form">
<input type="hidden" name="csrf" value="<?=reconcile_escape($csrf)?>">
<label>Merchant order ID dari panel transaksi
<input name="merchant_order_id" maxlength="190" required value="<?=reconcile_escape($orderId)?>" autocomplete="off"></label>
<button type="submit" name="check_status" value="1">Periksa status Duitku (sandbox)</button>
</form>
</section>
<?php if ($result !== null && $payment !== null): ?>
<section class="panel"><h2>Hasil validasi</h2><div class="notice">Respons cocok dengan merchant order ID dan nominal lokal. Ini hanya bukti hasil cek status; status database belum diubah.</div>
<div class="row"><strong>No. pesanan lokal</strong><span><?=reconcile_escape($payment['public_id'])?></span></div>
<div class="row"><strong>Merchant order ID</strong><code><?=reconcile_escape($result['merchant_order_id'])?></code></div>
<div class="row"><strong>Nominal</strong><span>Rp<?=number_format($result['amount'], 0, ',', '.')?></span></div>
<div class="row"><strong>Status Duitku</strong><strong><?=reconcile_escape($result['state'])?></strong></div>
<div class="row"><strong>Referensi Duitku</strong><span><?=reconcile_escape($result['reference'] ?? '-')?></span></div>
<div class="row"><strong>Pesan status</strong><span><?=reconcile_escape($result['status_message'] ?: '-')?></span></div>
<div class="row"><strong>Status lokal saat diperiksa</strong><span>Payment: <?=reconcile_escape($payment['payment_status'])?> / <?=reconcile_escape($payment['order_payment_status'])?> · Order: <?=reconcile_escape($payment['order_status'])?></span></div>
<?php if ($result['state'] === 'SUCCESS'): ?><p>Jangan mengedit status secara manual. Tinjau callback dan jalur transisi pembayaran terverifikasi sebelum tindakan lanjutan.</p>
<?php elseif ($result['state'] === 'PROCESS'): ?><p>Biarkan transaksi dalam pemeriksaan. Jangan membuat pembayaran pengganti.</p>
<?php else: ?><p>Status gagal/kedaluwarsa perlu ditinjau bersama status lokal terkini sebelum mempertimbangkan tindakan lanjutan.</p><?php endif; ?>
</section>
<?php endif; ?>
</main></body></html>
