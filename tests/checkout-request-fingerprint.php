<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/services/order_checkout.php';

$first = checkout_request_fingerprint('Player@example.com', 'mobile-legend', 42, [
    'zone_id' => ' 1234 ',
    'user_id' => ' 987654 ',
]);
$reordered = checkout_request_fingerprint('player@example.com', ' mobile-legend ', 42, [
    'user_id' => '987654',
    'zone_id' => '1234',
]);
if (!hash_equals($first, $reordered)) {
    fwrite(STDERR, "Equivalent normalized checkout requests should have the same fingerprint.\n");
    exit(1);
}

$changedProduct = checkout_request_fingerprint('player@example.com', 'mobile-legend', 43, [
    'user_id' => '987654',
    'zone_id' => '1234',
]);
$changedAccount = checkout_request_fingerprint('player@example.com', 'mobile-legend', 42, [
    'user_id' => '987655',
    'zone_id' => '1234',
]);
if (hash_equals($first, $changedProduct) || hash_equals($first, $changedAccount)) {
    fwrite(STDERR, "A different product or account must produce a different fingerprint.\n");
    exit(1);
}

$validKeys = [
    '123e4567-e89b-12d3-a456-426614174000',
    '123E4567-E89B-12D3-A456-426614174000',
    ' 123e4567-e89b-12d3-a456-426614174000 ',
];
foreach ($validKeys as $key) {
    if (!checkout_idempotency_key_is_valid($key)) {
        fwrite(STDERR, "A valid UUID key should be accepted, including normalized case/whitespace.\n");
        exit(1);
    }
}
foreach ([
    '',
    '123e4567-e89b-12d3-a456-42661417400',
    '123e4567-e89b-12d3-a456-426614174000x',
    '123e4567-e89b-12d3-a456-42661417400z',
    '123e4567e89b12d3a456426614174000',
    'xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx',
] as $key) {
    if (checkout_idempotency_key_is_valid($key)) {
        fwrite(STDERR, "Malformed UUID idempotency key should be rejected.\n");
        exit(1);
    }
}

$duplicateKey = new PDOException('duplicate key', 23000);
$duplicateKey->errorInfo = ['23000', 1062, 'Duplicate entry'];
$deadlockState = new PDOException('deadlock serialization failure', 40001);
$deadlockDriver = new PDOException('deadlock', 0);
$deadlockDriver->errorInfo = ['HY000', 1213, 'Deadlock found'];
$lockTimeout = new PDOException('lock timeout', 0);
$lockTimeout->errorInfo = ['HY000', 1205, 'Lock wait timeout'];
foreach ([$duplicateKey, $deadlockState, $deadlockDriver, $lockTimeout] as $exception) {
    if (!checkout_is_idempotency_race($exception)) {
        fwrite(STDERR, "Duplicate-key, deadlock, and lock-timeout errors should enter idempotency winner recovery.\n");
        exit(1);
    }
}
$foreignKeyViolation = new PDOException('foreign key violation', 23000);
$foreignKeyViolation->errorInfo = ['23000', 1452, 'Cannot add or update a child row'];
if (checkout_is_idempotency_race($foreignKeyViolation)) {
    fwrite(STDERR, "Foreign-key violations must not be classified as idempotency races.\n");
    exit(1);
}
if (checkout_is_idempotency_race(new PDOException('unrelated database failure', 0))) {
    fwrite(STDERR, "Unrelated database failures must not be classified as idempotency races.\n");
    exit(1);
}

echo "Checkout request fingerprint tests passed.\n";
