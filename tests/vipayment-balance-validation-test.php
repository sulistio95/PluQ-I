<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/services/vipayment.php';

function vipayment_balance_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

$valid = vipayment_map_profile_response(['data' => ['balance' => '125000.50']]);
vipayment_balance_assert($valid['balance'] === 125000.5, 'Numeric string balance should be parsed accurately.');

foreach ([
    [],
    ['data' => []],
    ['data' => ['balance' => 'not-a-number']],
    ['data' => ['balance' => -1]],
    ['data' => ['balance' => 'INF']],
    ['data' => ['balance' => '']],
    ['data' => ['balance' => '1e10000']],
] as $invalid) {
    $rejected = false;
    try {
        vipayment_map_profile_response($invalid);
    } catch (RuntimeException $e) {
        $rejected = true;
    }
    vipayment_balance_assert($rejected, 'Missing, malformed, negative, or non-finite VIPayment balance must be rejected.');
}

echo "VIPayment balance validation tests passed." . PHP_EOL;
