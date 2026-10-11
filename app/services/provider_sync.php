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

    if ($providerCode === 'DIGI') {
        $result = digiflazz_balance();
    } else {
        $result = vipayment_profile();
    }
    $balance = (float) $result['balance'];
    if (!is_finite($balance) || $balance < 0) {
        throw new RuntimeException('Provider returned an invalid balance.');
    }

    $stmt = $pdo->prepare('SELECT id, balance_threshold FROM providers WHERE code = :code LIMIT 1');
    $stmt->execute(['code' => $providerCode]);
    $provider = $stmt->fetch();
    if (!is_array($provider)) {
        throw new RuntimeException('Provider is not seeded in the database.');
    }
    $status = $balance < (float) $provider['balance_threshold'] ? 'LOW_BALANCE' : 'ACTIVE';
    $update = $pdo->prepare('UPDATE providers
        SET balance = :balance, status = :status, last_balance_check_at = NOW(), last_error = NULL
        WHERE id = :id');
    $update->execute(['balance' => $balance, 'status' => $status, 'id' => $provider['id']]);
    return ['provider' => $providerCode, 'balance' => $balance, 'status' => $status];
}

function record_provider_balance_error(PDO $pdo, string $providerCode, Throwable $error): void
{
    $stmt = $pdo->prepare("UPDATE providers SET status = 'ERROR', last_balance_check_at = NOW(), last_error = :error WHERE code = :code");
    $stmt->execute([
        'error' => substr($error->getMessage(), 0, 1000),
        'code' => strtoupper($providerCode),
    ]);
}
