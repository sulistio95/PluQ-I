<?php
declare(strict_types=1);

/** Pure capacity calculation, kept separate so boundary conditions can be tested without supplier credentials. */
function provider_budget_capacity_allows(float $balance, float $reservePercentage, float $committed, float $cost): bool
{
    if (!is_finite($balance) || !is_finite($reservePercentage)
        || !is_finite($committed) || !is_finite($cost)
        || $balance < 0 || $reservePercentage < 0 || $reservePercentage > 100
        || $committed < 0 || $cost <= 0) {
        return false;
    }
    $capacity = $balance * $reservePercentage / 100;
    return is_finite($capacity) && $committed + $cost <= $capacity;
}

/**
 * Reserve provider purchasing capacity for a paid order.
 * Caller must be inside a DB transaction. Locking the provider row serializes
 * all reservation decisions for that provider, preventing concurrent overspend.
 */
function provider_budget_reserve_paid_order(PDO $pdo, int $orderId): array
{
    if (!$pdo->inTransaction()) {
        throw new LogicException('Provider budget reservation requires an active transaction.');
    }

    $orderQuery = $pdo->prepare("SELECT id,provider_id,base_price,payment_status,order_status
        FROM orders WHERE id=:id LIMIT 1 FOR UPDATE");
    $orderQuery->execute(['id' => $orderId]);
    $order = $orderQuery->fetch(PDO::FETCH_ASSOC);
    if (!$order || $order['payment_status'] !== 'PAID') {
        throw new DomainException('Only a paid order can reserve provider budget.');
    }

    $existingQuery = $pdo->prepare("SELECT id,provider_id,amount,status
        FROM provider_budget_reservations WHERE order_id=:id LIMIT 1 FOR UPDATE");
    $existingQuery->execute(['id' => $orderId]);
    $existing = $existingQuery->fetch(PDO::FETCH_ASSOC);
    if ($existing) {
        if ((int)$existing['provider_id'] !== (int)$order['provider_id']
            || (float)$existing['amount'] !== (float)$order['base_price']) {
            throw new DomainException('Existing provider budget reservation does not match the order.');
        }
        return ['reserved' => in_array($existing['status'], ['ACTIVE','REVIEW','SETTLED'], true), 'status' => $existing['status'], 'existing' => true];
    }

    $providerQuery = $pdo->prepare("SELECT id,balance,reserve_percentage,status
        FROM providers WHERE id=:id LIMIT 1 FOR UPDATE");
    $providerQuery->execute(['id' => $order['provider_id']]);
    $provider = $providerQuery->fetch(PDO::FETCH_ASSOC);
    if (!$provider || !in_array($provider['status'], ['ACTIVE','LOW_BALANCE'], true)
        || $provider['balance'] === null || (float)$provider['balance'] < 0) {
        return ['reserved' => false, 'status' => 'UNAVAILABLE', 'existing' => false];
    }

    $usedQuery = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM provider_budget_reservations
        WHERE provider_id=:id AND status IN ('ACTIVE','REVIEW')");
    $usedQuery->execute(['id' => $provider['id']]);
    $committed = (float)$usedQuery->fetchColumn();
    $cost = (float)$order['base_price'];
    if (!provider_budget_capacity_allows((float)$provider['balance'], (float)$provider['reserve_percentage'], $committed, $cost)) {
        return ['reserved' => false, 'status' => 'CAPACITY_EXCEEDED', 'existing' => false];
    }

    $insert = $pdo->prepare("INSERT INTO provider_budget_reservations
        (order_id,provider_id,amount,status) VALUES (:order_id,:provider_id,:amount,'ACTIVE')");
    $insert->execute(['order_id' => $orderId, 'provider_id' => $provider['id'], 'amount' => $order['base_price']]);
    return ['reserved' => true, 'status' => 'ACTIVE', 'existing' => false];
}

/** Resolve reservation in the same transaction as the terminal supplier state. */
function provider_budget_resolve(PDO $pdo, int $orderId, string $resolution, string $note = ''): void
{
    if (!in_array($resolution, ['SETTLED','RELEASED','REVIEW'], true)) {
        throw new InvalidArgumentException('Invalid provider budget resolution.');
    }
    if (!$pdo->inTransaction()) {
        throw new LogicException('Provider budget resolution requires an active transaction.');
    }
    $stmt = $pdo->prepare("UPDATE provider_budget_reservations
        SET status=:status,resolution_note=:note
        WHERE order_id=:order_id AND status IN ('ACTIVE','REVIEW')");
    $stmt->execute([
        'status' => $resolution,
        'note' => $note === '' ? null : substr($note, 0, 500),
        'order_id' => $orderId,
    ]);
}
