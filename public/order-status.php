<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/bootstrap.php';
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
$orderId = (string)($_GET['merchantOrderId'] ?? '');
$title = 'Status pesanan PluQ';
$message = 'Nomor pesanan tidak valid atau tidak ditemukan.';
$paymentStatus = '';
$orderStatus = '';
if (preg_match('/^[a-f0-9]{26}$/D', $orderId)) {
    try {
        require_once PLUQI_ROOT . '/config/database.php';
        $stmt = database()->prepare("SELECT o.payment_status,o.order_status FROM orders o
          JOIN payment_transactions pt ON pt.order_id=o.id
          WHERE o.public_id=:id AND pt.merchant_order_id=:id LIMIT 1");
        $stmt->execute(['id' => $orderId]);
        $row = $stmt->fetch();
        if ($row) {
            $paymentStatus = (string)$row['payment_status'];
            $orderStatus = (string)$row['order_status'];
            $message = match ($paymentStatus) {
                'PAID' => 'Pembayaran telah diterima. Status pesanan: ' . $orderStatus . '.',
                'FAILED' => 'Pembayaran gagal. Jangan melakukan pembayaran ulang untuk nomor pesanan ini sebelum memeriksa statusnya.',
                'EXPIRED' => 'Waktu pembayaran telah berakhir.',
                default => 'Pembayaran masih menunggu konfirmasi Duitku. Muat ulang halaman ini beberapa saat lagi.',
            };
        }
    } catch (Throwable $e) {
        $message = 'Status pesanan belum dapat diperiksa. Coba lagi nanti.';
    }
}
$safeTitle = htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$safeMessage = htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$safeOrder = htmlspecialchars($orderId, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
echo '<!doctype html><html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>'.$safeTitle.'</title><link rel="stylesheet" href="/assets/catalog.css"></head><body><main><section class="account-panel"><h1>'.$safeTitle.'</h1><p>'.$safeMessage.'</p><p class="muted">Nomor pesanan: '.$safeOrder.'</p><button type="button" onclick="location.reload()">Periksa kembali</button><p><a href="/">Kembali ke katalog</a></p></section></main></body></html>';
