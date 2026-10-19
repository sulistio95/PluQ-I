<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/app/bootstrap.php';
require_once PLUQI_ROOT . '/config/database.php';
require_once PLUQI_ROOT . '/app/services/duitku.php';

$pdo = database();
$config = duitku_status_config();
$limit = 25;
$stmt = $pdo->prepare("SELECT pt.id,pt.merchant_order_id,pt.amount,pt.gateway_expiry_at
    FROM payment_transactions pt
    JOIN orders o ON o.id=pt.order_id
    WHERE pt.gateway='DUITKU'
      AND pt.status='PENDING'
      AND o.payment_status='PENDING'
      AND pt.gateway_expiry_at IS NOT NULL
      AND pt.gateway_expiry_at <= NOW()
    ORDER BY pt.gateway_expiry_at ASC,pt.id ASC
    LIMIT {$limit}");
$stmt->execute();
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
$expired = 0;
$stillPending = 0;
$needsReview = 0;

foreach ($rows as $row) {
    try {
        $amount = (int)$row['amount'];
        $response = duitku_query_transaction_status($config, (string)$row['merchant_order_id']);
        $validated = duitku_validate_transaction_status_response(
            $response,
            (string)$row['merchant_order_id'],
            $amount
        );

        if ($validated['state'] !== 'FAILED_OR_EXPIRED') {
            // PROCESS stays pending; SUCCESS is deliberately not auto-fulfilled by this reconciler.
            if ($validated['state'] === 'SUCCESS') $needsReview++;
            else $stillPending++;
            continue;
        }

        $pdo->beginTransaction();
        try {
            $lock = $pdo->prepare("SELECT pt.id,pt.status,o.id AS order_id,o.payment_status,o.order_status,
                    pt.gateway_expiry_at
                FROM payment_transactions pt
                JOIN orders o ON o.id=pt.order_id
                WHERE pt.id=:id AND pt.gateway='DUITKU'
                FOR UPDATE");
            $lock->execute(['id' => (int)$row['id']]);
            $current = $lock->fetch(PDO::FETCH_ASSOC);
            if ($current
                && $current['status'] === 'PENDING'
                && $current['payment_status'] === 'PENDING'
                && $current['gateway_expiry_at'] !== null
                && strtotime((string)$current['gateway_expiry_at']) <= time()) {
                $updatePayment = $pdo->prepare("UPDATE payment_transactions
                    SET status='EXPIRED'
                    WHERE id=:id AND status='PENDING'");
                $updatePayment->execute(['id' => (int)$current['id']]);
                if ($updatePayment->rowCount() === 1) {
                    $updateOrder = $pdo->prepare("UPDATE orders
                        SET payment_status='EXPIRED',
                            order_status='FAILED',
                            last_error='Invoice expired; Duitku status confirmed failed/expired'
                        WHERE id=:id AND payment_status='PENDING'");
                    $updateOrder->execute(['id' => (int)$current['order_id']]);
                    if ($updateOrder->rowCount() !== 1) {
                        throw new RuntimeException('Order state changed while expiring payment.');
                    }
                    $expired++;
                }
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    } catch (Throwable $e) {
        // Never print request payloads, credentials, or gateway response bodies.
        fwrite(STDERR, 'Payment row ' . (int)$row['id'] . ': status reconciliation failed; left unchanged.\n');
        $needsReview++;
    }
}

echo 'Checked ' . count($rows) . ' expired-deadline candidate(s); marked expired: ' . $expired
    . '; still pending: ' . $stillPending . '; require review: ' . $needsReview . ".\n";
