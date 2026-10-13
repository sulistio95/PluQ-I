<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/services/digiflazz.php';

function digiflazz_balance_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

$valid = digiflazz_map_balance_response(['data' => ['deposit' => '125000.50']]);
digiflazz_balance_assert($valid['balance'] === 125000.5, 'Numeric string balance should be parsed accurately.');

foreach ([
    ['data' => []],
    ['data' => ['deposit' => 'not-a-number']],
    ['data' => ['deposit' => -1]],
    ['data' => ['deposit' => 'INF']],
    ['data' => ['deposit' => '']],
] as $invalid) {
    $rejected = false;
    try {
        digiflazz_map_balance_response($invalid);
    } catch (RuntimeException $e) {
        $rejected = true;
    }
    digiflazz_balance_assert($rejected, 'Malformed or negative supplier balance must be rejected.');
}

echo "Digiflazz balance validation tests passed." . PHP_EOL;
