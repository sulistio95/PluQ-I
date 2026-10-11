<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';
require_once PLUQI_ROOT . '/config/database.php';
require_once PLUQI_ROOT . '/app/services/provider_reservations.php';

$orderId = filter_var($argv[1] ?? null, FILTER_VALIDATE_INT);
if (!$orderId || $orderId < 1) {
    fwrite(STDERR, "Usage: php provider_reservation_concurrency.php ORDER_ID\n");
    exit(2);
}
$pdo = database();
$q = $pdo->prepare('SELECT provider_id FROM orders WHERE id=:id LIMIT 1');
$q->execute(['id'=>$orderId]);
$providerId = (int)$q->fetchColumn();
if ($providerId < 1) {
    fwrite(STDERR, "Reservation fixture order not found.\n");
    exit(2);
}
try {
    $pdo->beginTransaction();
    provider_reservation_hold($pdo, $orderId, $providerId, 300000.00);
    // Deliberately keep the provider-row lock briefly to make concurrent attempts overlap.
    usleep(250000);
    $pdo->commit();
    echo "RESERVED\n";
} catch (DomainException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo "REJECTED\n";
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
