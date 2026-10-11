<?php
declare(strict_types=1);

/**
 * Local reservations protect the configured share of a provider's last known balance.
 * The provider row is the serialization point: all new holds for one provider lock it
 * before reading the reservation total. Call hold() inside the checkout transaction.
 */
function provider_reservation_hold(PDO $pdo, int $orderId, int $providerId, float $amount): void
{
    if (!$pdo->inTransaction()) {
        throw new LogicException('A provider reservation must share the checkout transaction.');
    }
    if ($orderId < 1 || $providerId < 1 || !is_finite($amount) || $amount <= 0) {
        throw new InvalidArgumentException('Invalid provider reservation.');
    }

    $providerQuery = $pdo->prepare("SELECT id,balance,status,reserve_percentage FROM providers WHERE id=:id LIMIT 1 FOR UPDATE");
    $providerQuery->execute(['id'=>$providerId]);
    $provider = $providerQuery->fetch();
    if (!$provider || !in_array($provider['status'], ['ACTIVE','LOW_BALANCE'], true) || $provider['balance'] === null) {
        throw new DomainException('Provider balance is unavailable; checkout is temporarily blocked.');
    }

    $usedQuery = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM provider_balance_reservations
        WHERE provider_id=:provider_id
          AND (status IN ('COMMITTED','CONSUMED') OR (status='HOLD' AND expires_at>NOW()))");
    $usedQuery->execute(['provider_id'=>$providerId]);
    $reserved = (float)$usedQuery->fetchColumn();
    $capacity = (float)$provider['balance'] * (float)$provider['reserve_percentage'] / 100;
    if ($amount > max(0.0, $capacity - $reserved)) {
        throw new DomainException('Provider capacity is reserved by other orders; refresh the catalog and try again.');
    }

    $insert = $pdo->prepare("INSERT INTO provider_balance_reservations
        (order_id,provider_id,amount,status,expires_at)
        VALUES (:order_id,:provider_id,:amount,'HOLD',DATE_ADD(NOW(), INTERVAL 30 MINUTE))");
    $insert->execute(['order_id'=>$orderId,'provider_id'=>$providerId,'amount'=>number_format($amount, 2, '.', '')]);
}

/**
 * Commit a hold when a valid payment callback arrives. Legacy/missing/expired holds
 * are re-reserved atomically; if capacity is no longer available, return false so the
 * paid order is kept for manual review instead of being sent to a supplier without cover.
 */
function provider_reservation_commit_paid(PDO $pdo, int $orderId): bool
{
    $orderQuery = $pdo->prepare('SELECT provider_id,base_price FROM orders WHERE id=:id LIMIT 1');
    $orderQuery->execute(['id'=>$orderId]);
    $order = $orderQuery->fetch();
    if (!$order) return false;

    $providerQuery = $pdo->prepare("SELECT id,balance,status,reserve_percentage FROM providers WHERE id=:id LIMIT 1 FOR UPDATE");
    $providerQuery->execute(['id'=>$order['provider_id']]);
    $provider = $providerQuery->fetch();
    if (!$provider || !in_array($provider['status'], ['ACTIVE','LOW_BALANCE'], true) || $provider['balance'] === null) {
        return false;
    }

    $reservationQuery = $pdo->prepare('SELECT id,provider_id,amount,status,expires_at FROM provider_balance_reservations WHERE order_id=:id LIMIT 1 FOR UPDATE');
    $reservationQuery->execute(['id'=>$orderId]);
    $reservation = $reservationQuery->fetch();
    if ($reservation && in_array($reservation['status'], ['COMMITTED','CONSUMED'], true)) return true;

    $amount = $reservation ? (float)$reservation['amount'] : (float)$order['base_price'];
    if (!is_finite($amount) || $amount <= 0) return false;
    $usedQuery = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM provider_balance_reservations
        WHERE provider_id=:provider_id AND order_id<>:order_id
          AND (status IN ('COMMITTED','CONSUMED') OR (status='HOLD' AND expires_at>NOW()))");
    $usedQuery->execute(['provider_id'=>$provider['id'],'order_id'=>$orderId]);
    $reserved = (float)$usedQuery->fetchColumn();
    $capacity = (float)$provider['balance'] * (float)$provider['reserve_percentage'] / 100;
    if ($amount > max(0.0, $capacity - $reserved)) return false;

    if ($reservation) {
        $update = $pdo->prepare("UPDATE provider_balance_reservations
            SET provider_id=:provider_id,amount=:amount,status='COMMITTED',expires_at=NULL,committed_at=NOW(),released_at=NULL
            WHERE id=:id");
        $update->execute(['provider_id'=>$provider['id'],'amount'=>number_format($amount,2,'.',''),'id'=>$reservation['id']]);
    } else {
        $insert = $pdo->prepare("INSERT INTO provider_balance_reservations
            (order_id,provider_id,amount,status,expires_at,committed_at)
            VALUES (:order_id,:provider_id,:amount,'COMMITTED',NULL,NOW())");
        $insert->execute(['order_id'=>$orderId,'provider_id'=>$provider['id'],'amount'=>number_format($amount,2,'.','')]);
    }
    return true;
}

/** Release unpaid holds, or committed holds only after a definitive supplier rejection. */
function provider_reservation_release(PDO $pdo, int $orderId, bool $allowCommitted = false): void
{
    $statuses = $allowCommitted ? "('HOLD','COMMITTED')" : "('HOLD')";
    $pdo->prepare("UPDATE provider_balance_reservations
        SET status='RELEASED',expires_at=NULL,released_at=NOW()
        WHERE order_id=:order_id AND status IN $statuses")->execute(['order_id'=>$orderId]);
}

/**
 * A successful supplier transaction consumes the reserved amount. Keep it counted
 * against the last balance snapshot until a subsequent successful balance sync sees it.
 */
function provider_reservation_consume(PDO $pdo, int $orderId): void
{
    $pdo->prepare("UPDATE provider_balance_reservations
        SET status='CONSUMED',expires_at=NULL,consumed_at=NOW()
        WHERE order_id=:order_id AND status='COMMITTED'")->execute(['order_id'=>$orderId]);
}
