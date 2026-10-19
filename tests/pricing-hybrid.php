<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/services/pricing.php';

$cases = [
    [10000, 'FIXED', 500.0, 500],
    [10000, 'PERCENTAGE', 10.0, 1000],
    [9999, 'PERCENTAGE', 10.0, 1000],
    [105, 'PERCENTAGE', 10.0, 11],
    [10000, 'PERCENTAGE', 0.0, 0],
    [10000, 'PERCENTAGE', 100.0, 10000],
];
foreach ($cases as [$base, $type, $value, $expected]) {
    $actual = pricing_calculate_profit($base, $type, $value);
    if ($actual !== $expected) {
        fwrite(STDERR, "FAIL: {$base} {$type} {$value}; expected {$expected}, got {$actual}\n");
        exit(1);
    }
}
foreach ([
    [-1, 'FIXED', 1.0],
    [100, 'UNKNOWN', 1.0],
    [100, 'PERCENTAGE', 100.01],
    [100, 'FIXED', -1.0],
] as [$base, $type, $value]) {
    try {
        pricing_calculate_profit($base, $type, $value);
        fwrite(STDERR, "FAIL: invalid rule was accepted\n");
        exit(1);
    } catch (InvalidArgumentException) {
        // Expected validation failure.
    }
}
echo "Hybrid pricing calculation checks passed.\n";
