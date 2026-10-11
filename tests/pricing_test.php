<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/services/pricing.php';

function assert_same(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

assert_same(1000, pricing_admin_fee(0), 'Fee at zero');
assert_same(1000, pricing_admin_fee(100000), 'Fee at Rp100,000 boundary');
assert_same(2000, pricing_admin_fee(100001), 'Fee above Rp100,000 boundary');
assert_same(101000, pricing_invoice_total(100000), 'Invoice total at lower fee tier');
assert_same(102001, pricing_invoice_total(100001), 'Invoice total at upper fee tier');

$thrown = false;
try {
    pricing_admin_fee(-1);
} catch (InvalidArgumentException) {
    $thrown = true;
}
assert_same(true, $thrown, 'Negative invoice price must be rejected');

echo "Pricing fee tests passed.\n";
