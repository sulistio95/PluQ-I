<?php
declare(strict_types=1);

/**
 * Atomically reserve one operator-triggered status lookup.
 *
 * The payment row serializes competing requests. A committed STARTED audit row
 * makes the cooldown visible before the gateway network request begins.
 * The transaction is always released before the caller performs network I/O.
 */
function duitku_reconcile_admit_lookup(
    PDO $pdo,
    int $paymentId,
    ?int $actorId,
    string $merchantOrderId,
    int $amount,
    string $paymentStatus,
    string $orderPaymentStatus,
    string $orderStatus,
    string $ipAddress
): void {
    if ($paymentId < 1 || $merchantOrderId === '' || $amount < 1) {
        throw new InvalidArgumentException('Invalid local payment data for status lookup.');
    }

    $pdo->beginTransaction();
    try {
        $lock = $pdo->prepare('SELECT id FROM payment_transactions WHERE id=:id FOR UPDATE');
        $lock->execute(['id' => $paymentId]);
        if (!$lock->fetchColumn()) {
            throw new DomainException('Transaksi pembayaran tidak lagi tersedia.');
        }

        $recent = $pdo->prepare("SELECT id FROM audit_logs
            WHERE action='DUITKU_STATUS_LOOKUP' AND entity_type='PAYMENT_TRANSACTION'
              AND entity_id=:entity_id AND created_at >= DATE_SUB(NOW(), INTERVAL 60 SECOND)
            ORDER BY id DESC LIMIT 1");
        $recent->execute(['entity_id' => (string)$paymentId]);
        if ($recent->fetchColumn()) {
            throw new DomainException('Pemeriksaan untuk transaksi ini baru saja dilakukan. Tunggu minimal 60 detik sebelum meminta status lagi.');
        }

        $startAudit = $pdo->prepare("INSERT INTO audit_logs
            (actor_type,actor_id,action,entity_type,entity_id,details,ip_address)
            VALUES ('ADMIN',:actor,'DUITKU_STATUS_LOOKUP','PAYMENT_TRANSACTION',:entity,:details,:ip)");
        $startAudit->execute([
            'actor' => $actorId,
            'entity' => (string)$paymentId,
            'details' => json_encode([
                'outcome' => 'STARTED',
                'merchant_order_id' => $merchantOrderId,
                'amount' => $amount,
                'payment_status_before' => $paymentStatus,
                'order_payment_status_before' => $orderPaymentStatus,
                'order_status_before' => $orderStatus,
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'ip' => substr($ipAddress, 0, 45),
        ]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}
