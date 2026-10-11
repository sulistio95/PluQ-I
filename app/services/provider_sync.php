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

    // Record when the remote snapshot request starts, not when it finishes. A supplier
    // transaction completed during the API call must remain reserved for the next snapshot.
    $snapshotStartedAt = (string)$pdo->query('SELECT NOW()')->fetchColumn();
    $result = $providerCode === 'DIGI' ? digiflazz_balance() : vipayment_profile();
    $balance = (float) ($result['balance'] ?? -1);
    if (!is_finite($balance) || $balance < 0) {
        throw new RuntimeException('Provider returned an invalid balance.');
    }

    $pdo->beginTransaction();
    try {
        $lockedQuery = $pdo->prepare('SELECT id,balance_threshold,status FROM providers WHERE id=:id LIMIT 1 FOR UPDATE');
        $lockedQuery->execute(['id'=>$provider['id']]);
        $lockedProvider = $lockedQuery->fetch();
        if (!$lockedProvider || $lockedProvider['status'] === 'DISABLED') {
            $pdo->commit();
            return ['provider'=>$providerCode,'balance'=>null,'status'=>'DISABLED','skipped'=>true];
        }
        $status = $balance < (float)$lockedProvider['balance_threshold'] ? 'LOW_BALANCE' : 'ACTIVE';
        $update = $pdo->prepare('UPDATE providers
            SET balance=:balance,status=:status,last_balance_check_at=:checked_at,last_error=NULL
            WHERE id=:id AND status <> "DISABLED"');
        $update->execute(['balance'=>$balance,'status'=>$status,'checked_at'=>$snapshotStartedAt,'id'=>$provider['id']]);

        // Successful supplier debits remain counted until a snapshot that began after
        // their completion has been obtained. This avoids freeing capacity immediately
        // after fulfillment while the stored balance may still be stale.
        $release = $pdo->prepare("UPDATE provider_balance_reservations
            SET status='RELEASED',released_at=NOW()
            WHERE provider_id=:provider_id AND status='CONSUMED' AND consumed_at<=:checked_at");
        $release->execute(['provider_id'=>$provider['id'],'checked_at'=>$snapshotStartedAt]);
        $pdo->commit();
        return ['provider'=>$providerCode,'balance'=>$balance,'status'=>$status,'skipped'=>false];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function record_provider_balance_error(PDO $pdo, string $providerCode, Throwable $error): void
{
    $stmt = $pdo->prepare("UPDATE providers SET status = 'ERROR', last_balance_check_at = NOW(), last_error = :error WHERE code = :code AND status <> 'DISABLED'");
    $stmt->execute([
        'error' => substr($error->getMessage(), 0, 1000),
        'code' => strtoupper($providerCode),
    ]);
}
