<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/services/provider_budget.php';

function provider_budget_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

// 50% of Rp1,000,000 is usable; exact capacity boundary is permitted.
provider_budget_assert(
    provider_budget_capacity_allows(1000000, 50, 0, 500000),
    'A reservation exactly at the configured usable-balance limit should be allowed.'
);
provider_budget_assert(
    !provider_budget_capacity_allows(1000000, 50, 100000, 400001),
    'Concurrent reservations must account for existing active commitments.'
);
provider_budget_assert(
    provider_budget_capacity_allows(1000000, 50, 100000, 400000),
    'A reservation that reaches the limit after existing commitments should be allowed.'
);

foreach ([
    [-1, 50, 0, 1],
    [1000, -1, 0, 1],
    [1000, 101, 0, 1],
    [1000, 50, -1, 1],
    [1000, 50, 0, 0],
    [1000, 50, INF, 1],
    [1000, 50, 0, NAN],
] as [$balance, $percentage, $committed, $cost]) {
    provider_budget_assert(
        !provider_budget_capacity_allows((float)$balance, (float)$percentage, (float)$committed, (float)$cost),
        'Invalid numeric capacity inputs must fail closed.'
    );
}

echo "Provider budget capacity boundary tests passed." . PHP_EOL;
