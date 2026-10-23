<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/bootstrap.php';
require_once PLUQI_ROOT . '/config/database.php';
require_once PLUQI_ROOT . '/app/services/order_rating.php';
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');

// Dedicated public session for order-rating CSRF; separate from admin sessions.
if (session_status() !== PHP_SESSION_ACTIVE) {
    $https = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
        || (strtolower(trim((string) (getenv('ADMIN_TRUST_PROXY_HTTPS') ?: 'false'))) === 'true'
            && strtolower(trim((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''))) === 'https');
    session_name('PLUQI_RATING');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/order-status.php',
        'secure' => $https,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}
if (empty($_SESSION['order_rating_csrf']) || !is_string($_SESSION['order_rating_csrf'])) {
    $_SESSION['order_rating_csrf'] = bin2hex(random_bytes(32));
}

$orderId = trim((string)($_POST['merchantOrderId'] ?? $_GET['merchantOrderId'] ?? ''));
$message = 'Nomor pesanan tidak valid atau tidak ditemukan.';
$error = '';
$row = null;
$alreadyRated = false;
$ratingReasons = [
    'FAST_AND_SMOOTH' => 'Proses cepat dan lancar',
    'SLOW_PROCESS' => 'Proses terlalu lambat',
    'ITEM_NOT_RECEIVED' => 'Item belum diterima',
    'ORDER_FAILED' => 'Pesanan gagal',
    'SUPPORT_EXPERIENCE' => 'Pengalaman menghubungi dukungan',
    'OTHER' => 'Alasan lainnya',
];

if (preg_match('/^[a-f0-9]{26}$/D', $orderId)) {
    try {
        $pdo = database();
        $stmt = $pdo->prepare("SELECT o.id,o.public_id,o.payment_status,o.order_status,o.created_at,o.fulfilled_at,
            (SELECT r.status FROM refunds r WHERE r.order_id=o.id ORDER BY r.id DESC LIMIT 1) AS refund_status
            FROM orders o JOIN payment_transactions pt ON pt.order_id=o.id
            WHERE o.public_id=:public_id AND pt.merchant_order_id=:merchant_id LIMIT 1");
        $stmt->execute(['public_id'=>$orderId,'merchant_id'=>$orderId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

        if ($row) {
            $finalStatus = (string)$row['order_status'];
            $eligible = order_rating_is_eligible((string)$row['payment_status'], $finalStatus);
            $stmt = $pdo->prepare('SELECT rating,reason,comment,created_at FROM order_ratings WHERE order_id=:order_id LIMIT 1');
            $stmt->execute(['order_id'=>$row['id']]);
            $existingRating = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
            $alreadyRated = $existingRating !== null;

            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                $submittedCsrf = $_POST['csrf'] ?? null;
                if (!is_string($submittedCsrf)
                    || !hash_equals($_SESSION['order_rating_csrf'], $submittedCsrf)) {
                    $error = 'Sesi formulir tidak valid atau sudah kedaluwarsa. Muat ulang halaman.';
                } elseif (!$eligible) {
                    $error = 'Rating hanya tersedia setelah pesanan berstatus berhasil atau gagal.';
                } elseif ($alreadyRated) {
                    $error = 'Pesanan ini sudah pernah diberi rating.';
                } else {
                    $validated = order_rating_validate_submission($_POST['rating'] ?? null, $_POST['reason'] ?? null, $_POST['comment'] ?? null);
                    if ($validated['error'] !== null) {
                        $error = $validated['error'];
                    } else {
                        $rating = (int)$validated['rating'];
                        $reason = (string)$validated['reason'];
                        $comment = (string)($validated['comment'] ?? '');
                        try {
                            $stmt = $pdo->prepare("INSERT INTO order_ratings
                                (order_id,provider_id,game_id,order_status_at_rating,rating,reason,comment)
                                SELECT id,provider_id,game_id,:status,:rating,:reason,:comment
                                FROM orders WHERE id=:order_id AND payment_status='PAID'
                                  AND order_status IN ('SUCCESS','FAILED','REFUNDED')");
                            $stmt->execute([
                                'status'=>$finalStatus,'rating'=>$rating,'reason'=>$reason,
                                'comment'=>$comment !== '' ? $comment : null,'order_id'=>$row['id'],
                            ]);
                            if ($stmt->rowCount() === 1) {
                                header('Location: /order-status.php?merchantOrderId=' . rawurlencode($orderId) . '&rated=1', true, 303);
                                exit;
                            }
                            $error = 'Status pesanan berubah atau rating tidak dapat disimpan. Muat ulang halaman.';
                        } catch (PDOException $e) {
                            if ((string)$e->getCode() === '23000') {
                                $alreadyRated = true;
                                $error = 'Pesanan ini sudah pernah diberi rating.';
                            } else {
                                throw $e;
                            }
                        }
                    }
                }
            }

            $message = match ($finalStatus) {
                'SUCCESS' => 'Top-up berhasil.',
                'FAILED' => 'Top-up gagal. Silakan simpan nomor pesanan untuk pemeriksaan admin.',
                'REFUNDED' => 'Top-up gagal dan pengembalian dana telah selesai.',
                'QUEUED' => 'Pembayaran diterima. Pesanan masuk antrean.',
                'PROCESSING' => 'Pembayaran diterima. Top-up sedang diproses.',
                'RETRYING' => 'Top-up masih diproses supplier.',
                'MANUAL_REVIEW' => 'Pesanan sedang diperiksa admin.',
                default => (string)$row['payment_status'] === 'PAID'
                    ? 'Pembayaran diterima. Status pesanan sedang diperiksa.'
                    : 'Pembayaran masih menunggu konfirmasi Duitku.',
            };
            if (isset($_GET['rated']) && $_GET['rated'] === '1') $message .= ' Terima kasih, rating Anda telah disimpan.';
        }
    } catch (Throwable $e) {
        $error = 'Status pesanan atau rating belum dapat diperiksa. Pastikan migration 014 sudah diterapkan dan periksa log server.';
    }
}
$esc = static fn(mixed $v): string => htmlspecialchars((string)($v ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$eligible = $row && order_rating_is_eligible((string)$row['payment_status'], (string)$row['order_status']);
?><!doctype html>
<html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Status Pesanan — PluQ-I</title><link rel="stylesheet" href="/assets/catalog.css">
<style>
.rating-panel{margin-top:24px;padding:18px;border:1px solid #343943;border-radius:12px;background:#15171d;color:#f3f4f6}
.rating-panel label{display:block;margin:12px 0 6px}.rating-panel select,.rating-panel textarea,.rating-panel button{width:100%;padding:11px;border:1px solid #343943;border-radius:8px;background:#0c0d10;color:#f3f4f6;font:inherit}
.rating-panel textarea{min-height:90px;resize:vertical}.rating-panel button{margin-top:14px;background:#d1fae5;color:#111827;font-weight:700;cursor:pointer}
.rating-error{padding:10px;border-radius:8px;background:#3b1d22;color:#fecaca}.rating-muted{color:#9ca3af;font-size:13px}
</style></head><body><main><section class="account-panel">
<h1>Status Pesanan PluQ-I</h1><p><?= $esc($message) ?></p>
<?php if ($error !== ''): ?><p class="rating-error"><?= $esc($error) ?></p><?php endif; ?>
<?php if ($orderId !== '' && preg_match('/^[a-f0-9]{26}$/D',$orderId)): ?><p class="muted">Nomor pesanan: <?= $esc($orderId) ?></p><?php endif; ?>
<?php if ($row && $eligible && !$alreadyRated): ?>
<section class="rating-panel"><h2>Bagaimana pengalaman top-up Anda?</h2>
<p class="rating-muted">Rating hanya dapat dikirim satu kali untuk pesanan ini. Penilaian Anda tidak otomatis menentukan kesalahan supplier.</p>
<form method="post" action="/order-status.php">
<input type="hidden" name="merchantOrderId" value="<?= $esc($orderId) ?>">
<input type="hidden" name="csrf" value="<?= $esc($_SESSION['order_rating_csrf']) ?>">
<label for="rating">Rating</label><select id="rating" name="rating" required>
<option value="">Pilih bintang</option><option value="5">5 — Sangat baik</option><option value="4">4 — Baik</option><option value="3">3 — Cukup</option><option value="2">2 — Kurang</option><option value="1">1 — Sangat buruk</option>
</select>
<label for="reason">Alasan penilaian</label><select id="reason" name="reason" required><option value="">Pilih alasan</option>
<?php foreach ($ratingReasons as $key=>$label): ?><option value="<?= $esc($key) ?>"><?= $esc($label) ?></option><?php endforeach; ?>
</select>
<label for="comment">Komentar tambahan (opsional)</label><textarea id="comment" name="comment" maxlength="1000" placeholder="Ceritakan pengalaman Anda"></textarea>
<button type="submit">Kirim rating</button></form></section>
<?php elseif ($alreadyRated): ?><section class="rating-panel"><h2>Rating sudah diterima</h2><p>Terima kasih. Pesanan ini sudah memiliki rating dan tidak dapat dinilai ulang.</p></section>
<?php endif; ?>
<p><a href="/">Kembali ke katalog</a></p></section></main></body></html>
