<?php
declare(strict_types=1);

require_once __DIR__ . '/digiflazz.php';
require_once __DIR__ . '/vipayment.php';

function sync_provider_balance(PDO $pdo, string $providerCode): array
{
    $providerCode = strtoupper(trim($providerCode));
    if (!in_array($providerCode, ['DIGI', 'VIPAY'], true)) {
        throw new InvalidArgumentException('Unknown provider code.');
    }

    $stmt = $pdo->prepare('SELECT id, balance_threshold, status FROM providers WHERE code = :code LIMIT 1');
    $stmt->execute(['code' => $providerCode]);
    $provider = $stmt->fetch();
    if (!is_array($provider)) {
        throw new RuntimeException('Provider is not seeded in the database.');
    }
    if ($provider['status'] === 'DISABLED') {
        return ['provider' => $providerCode, 'balance' => null, 'status' => 'DISABLED', 'skipped' => true];
    }

    // Capture the balance-query start time before the network request. A settled purchase
    // after this instant must still count against the cached balance, even if the API response
    // arrives later and is written to the database after that purchase.
    $checkTime = (string)$pdo->query('SELECT NOW()')->fetchColumn();
    $result = $providerCode === 'DIGI' ? digiflazz_balance() : vipayment_profile();
    $balance = (float) ($result['balance'] ?? -1);
    if (!is_finite($balance) || $balance < 0) {
        throw new RuntimeException('Provider returned an invalid balance.');
    }

    $status = $balance < (float) $provider['balance_threshold'] ? 'LOW_BALANCE' : 'ACTIVE';
    $update = $pdo->prepare('UPDATE providers
        SET balance = :balance, status = :status, last_balance_check_at = :checked_at, last_error = NULL
        WHERE id = :id AND status <> "DISABLED"');
    $update->execute([
        'balance' => $balance,
        'status' => $status,
        'checked_at' => $checkTime,
        'id' => $provider['id'],
    ]);
    return ['provider' => $providerCode, 'balance' => $balance, 'status' => $status, 'skipped' => false];
}

function record_provider_balance_error(PDO $pdo, string $providerCode, Throwable $error): void
{
    $stmt = $pdo->prepare("UPDATE providers SET status = 'ERROR', last_balance_check_at = NOW(), last_error = :error WHERE code = :code AND status <> 'DISABLED'");
    $stmt->execute([
        'error' => substr($error->getMessage(), 0, 1000),
        'code' => strtoupper($providerCode),
    ]);
}
